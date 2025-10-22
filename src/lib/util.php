<?php

namespace ISPLDAP\lib;

class Util
{
	public const DEBUG = LOG_DEBUG;
	public const INFO = LOG_INFO;
	public const WARN = LOG_WARNING;
	public const ERROR = LOG_ERR;
	public const FATAL = LOG_CRIT;
	/**
	 * write a message in the log
	 * @param string $app
	 * @param string $message
	 * @param int $level
	 */
	public static function writeLog($app, $message, $level)
	{
		//$context = ['app' => $app];
		//\OC::$server->getLogger()->log($level, $message, $context);
		//echo "SYSLOG: $app: $message\n";
		syslog($level, "$app: $message");
		// syslog($level, "$app: $message | {$_SERVER['REMOTE_ADDR']} ({$_SERVER['HTTP_USER_AGENT']})");
	}

	public static function getUsernameFromMail($mail): string
	{
		$parts = explode('@', $mail);
		return $parts[0] ?? '';
	}

	public static function getDomainFromMail($mail): string
	{
		$parts = explode('@', $mail);
		return $parts[1] ?? '';
	}
	public static function getDNfromMail($mail): string
	{
		$arr_dn = [];
		$arr_mail = explode('@', $mail);
		$arr_dn[] = 'CN=' . ($arr_mail[0] ?? '');
		if (isset($arr_mail[1])) {
			foreach (explode('.', $arr_mail[1]) as $value) {
				$arr_dn[] = 'DC=' . $value;
			}
		}
		return implode(',', $arr_dn);
	}
	public static function getMailFromDN($dn): string
	{
		$tmp = Util::parseLdapDn($dn);
		$mail = implode('.',$tmp['CN']) . '@' . implode('.', $tmp['DC']);
		return $mail;
	}
	/**
	 * https://www.php.net/manual/en/function.ldap-explode-dn.php 
	 * Parse, and format a DN string to Array
	 *
	 * Read a LDAP DN, and return an array keys
	 * listing all similar attributes.
	 *
	 * Also takes care of the character escape and unescape
	 *
	 * Example:
	 * CN=username,OU=UNITNAME,OU=Region,OU=Country,DC=subdomain,DC=domain,DC=com
	 *
	 * Would normally return:
	 * Array (
	 *     [count] => 9
	 *     [0] => CN=username
	 *     [1] => OU=UNITNAME
	 *     [2] => OU=Region
	 *     [5] => OU=Country
	 *     [6] => DC=subdomain
	 *     [7] => DC=domain
	 *     [8] => DC=com
	 * )
	 *
	 * Returns instead a manageable array:
	 * array (
	 *     [CN] => array( username )
	 *     [OU] => array( UNITNAME, Region, Country )
	 *     [DC] => array ( subdomain, domain, com )
	 * )
	 *
	 *
	 * @author gabriel at hrz dot uni-marburg dot de 05-Aug-2003 02:27 (part of the character replacement)
	 * @author Renoir Boulanger
	 *
	 * @param  string $dn          The DN
	 * @return array
	 */
	static function parseLdapDn($dn)
	{
		$parsr = ldap_explode_dn($dn, 0);
		//$parsr[] = 'EE=Sôme Krazï string';
		//$parsr[] = 'AndBogusOne';
		$out = array();
		foreach ($parsr as $key => $value) {
			if (FALSE !== strstr($value, '=')) {
				list($prefix, $data) = explode("=", $value);
				$data = preg_replace_callback('/\\\([0-9A-Fa-f]{2})/', function ($matches) { return chr(hexdec($matches[1])); }, $data);
				if (isset($current_prefix) && $prefix == $current_prefix) {
					$out[$prefix][] = $data;
				} else {
					$current_prefix = $prefix;
					$out[$prefix][] = $data;
				}
			}
		}
		return $out;
	}
	
	/**
	 * Splits the full name into First name and Last name
	 * Example Aaa Bbb Ccc Ddd will return 
	 * [0]=Aaa Bbb Ccc
	 * [1]=Ddd
	 * @param string $fullname
	 */
	static function splitName($fullname):array {

		$parts = explode(" ", $fullname);
		if(count($parts) > 1) {
			$lastname = array_pop($parts);
			$firstname = implode(" ", $parts);
		}
		else
		{
			return [$fullname,''];
		}
		return [$firstname, $lastname];
	}
}
