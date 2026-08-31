<?php

namespace ISPLDAP\lib;

/**
 * Short-lived authentication cache shared across forked worker processes.
 *
 * Why this exists: the server forks one process per LDAP connection, and every
 * bind opens a brand new SOAP connection to ISPConfig (connect + login + logout).
 * A single `git fetch` against a GitLab instance backed by this server was
 * measured issuing 8 binds in 5.7 seconds - six of them for the very same
 * service bind DN. Caching the outcome for a few seconds removes almost all of
 * that traffic.
 *
 * Because each bind runs in a separate forked process, an in-process cache would
 * die with the child. The cache is therefore file backed, living in a tmpfs
 * directory (/dev/shm by default) so nothing ever touches the disk.
 *
 * Security notes:
 *  - Cache keys are HMAC-SHA256 of the credentials using a 32 byte secret that
 *    is generated on every server start. Credentials are never written to disk,
 *    and the keys cannot be precomputed or brute forced without the secret.
 *  - Stored values contain only an expiry timestamp and a boolean result.
 *  - The directory is created 0700 and entries 0600.
 *  - A fresh secret per server start means a restart implicitly voids the cache.
 */
class AuthCache
{
    /** Name of the file holding the per-run HMAC secret. */
    private const SECRET_FILE = '.secret';

    /** Length of the HMAC secret, in bytes. */
    private const SECRET_LENGTH = 32;

    /** Roughly one in N writes triggers a sweep of expired entries. */
    private const PRUNE_CHANCE = 100;

    /** @var AuthCache|null */
    private static $instance = null;

    /** @var bool */
    private $enabled;

    /** @var string */
    private $dir;

    /** @var int TTL for successful authentications, in seconds. */
    private $ttl;

    /** @var int TTL for failed authentications, in seconds. */
    private $negativeTtl;

    /** @var string|null */
    private $secret = null;

    public function __construct(array $config)
    {
        $this->enabled     = (bool) ($config['auth_cache_enabled'] ?? true);
        $this->dir         = rtrim((string) ($config['auth_cache_dir'] ?? '/dev/shm/ispldap-authcache'), '/');
        $this->ttl         = max(0, (int) ($config['auth_cache_ttl'] ?? 60));
        $this->negativeTtl = max(0, (int) ($config['auth_cache_negative_ttl'] ?? 5));

        // A non-positive positive-TTL disables the whole cache: there would be
        // nothing worth keeping.
        if ($this->ttl <= 0 || $this->dir === '') {
            $this->enabled = false;
        }
    }

    /**
     * Shared instance. Children inherit it through fork, which is fine: the
     * state that matters (directory and secret) lives on the filesystem.
     */
    public static function instance(?array $config = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config ?? ($GLOBALS['config'] ?? []));
        }

        return self::$instance;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getDirectory(): string
    {
        return $this->dir;
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    public function getNegativeTtl(): int
    {
        return $this->negativeTtl;
    }

    /**
     * Prepare the cache directory and secret. Call this once in the parent
     * process, before any connection is accepted, so children only ever read
     * the secret instead of racing to create it.
     *
     * Failure is never fatal: the cache simply disables itself and every bind
     * goes to ISPConfig as before.
     */
    public function bootstrap(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            $this->enabled = false;

            return false;
        }

        @chmod($this->dir, 0700);

        // Entries from a previous run are unreadable anyway (new secret), so
        // drop them instead of leaving them around until they expire.
        $this->flush();

        if ($this->loadOrCreateSecret() === null) {
            $this->enabled = false;

            return false;
        }

        return true;
    }

    /**
     * Cached result of a bind, or null when there is no usable entry.
     *
     * Note the distinction: false means "we know this credential fails",
     * null means "we do not know, go ask ISPConfig".
     */
    public function getBind(string $username, string $password): ?bool
    {
        $value = $this->read('bind', $username . "\0" . $password);

        return $value === null ? null : (bool) $value;
    }

    /**
     * Remember the outcome of a bind. Failures are kept for a shorter time so a
     * user who just fixed their password is not locked out by the cache.
     */
    public function setBind(string $username, string $password, bool $result): void
    {
        $this->write(
            'bind',
            $username . "\0" . $password,
            $result,
            $result ? $this->ttl : $this->negativeTtl
        );
    }

    /**
     * Remove every cached entry. Used on bootstrap.
     */
    public function flush(): void
    {
        foreach ($this->entryFiles() as $file) {
            @unlink($file);
        }
    }

    /**
     * @return mixed|null Decoded value, or null on miss/expiry.
     */
    private function read(string $namespace, string $material)
    {
        if (!$this->enabled || $this->loadOrCreateSecret() === null) {
            return null;
        }

        $file = $this->path($namespace, $material);
        $raw  = @file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || !array_key_exists('exp', $data) || !array_key_exists('v', $data)) {
            @unlink($file);

            return null;
        }

        if ((int) $data['exp'] < time()) {
            @unlink($file);

            return null;
        }

        return $data['v'];
    }

    /**
     * @param mixed $value
     */
    private function write(string $namespace, string $material, $value, int $ttl): void
    {
        if (!$this->enabled || $ttl <= 0 || $this->loadOrCreateSecret() === null) {
            return;
        }

        $payload = json_encode(['exp' => time() + $ttl, 'v' => $value]);

        if ($payload === false) {
            return;
        }

        $file = $this->path($namespace, $material);
        $tmp  = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $payload) === false) {
            @unlink($tmp);

            return;
        }

        @chmod($tmp, 0600);

        // rename() is atomic within the same filesystem, so a concurrent reader
        // never observes a half written entry.
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }

        $this->maybePrune();
    }

    private function path(string $namespace, string $material): string
    {
        $hash = hash_hmac('sha256', $namespace . "\0" . $material, (string) $this->secret);

        return $this->dir . '/' . $hash . '.json';
    }

    /**
     * Load the per-run secret, creating it when absent.
     *
     * The create path is safe against concurrent children: each writes to its
     * own temporary file and renames it into place, and the value actually read
     * back from disk is the one used.
     */
    private function loadOrCreateSecret(): ?string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }

        $path = $this->dir . '/' . self::SECRET_FILE;
        $raw  = @file_get_contents($path);

        if ($raw !== false && strlen($raw) === self::SECRET_LENGTH) {
            return $this->secret = $raw;
        }

        try {
            $new = random_bytes(self::SECRET_LENGTH);
        } catch (\Throwable $e) {
            $this->enabled = false;

            return null;
        }

        $tmp = $path . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $new) === false) {
            @unlink($tmp);
            $this->enabled = false;

            return null;
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }

        $raw = @file_get_contents($path);

        if ($raw === false || strlen($raw) !== self::SECRET_LENGTH) {
            $this->enabled = false;

            return null;
        }

        return $this->secret = $raw;
    }

    /**
     * Occasionally drop expired entries so the directory does not grow without
     * bound on a long running server.
     */
    private function maybePrune(): void
    {
        if (mt_rand(1, self::PRUNE_CHANCE) !== 1) {
            return;
        }

        $now = time();

        foreach ($this->entryFiles() as $file) {
            $raw = @file_get_contents($file);

            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);

            if (!is_array($data) || !isset($data['exp']) || (int) $data['exp'] < $now) {
                @unlink($file);
            }
        }
    }

    /**
     * @return string[]
     */
    private function entryFiles(): array
    {
        $files = glob($this->dir . '/*.json');

        return $files === false ? [] : $files;
    }
}
