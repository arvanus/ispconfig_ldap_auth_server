<?php

namespace ISPLDAP\lib;

class Util {
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
	public static function writeLog($app, $message, $level) {
		//$context = ['app' => $app];
		//\OC::$server->getLogger()->log($level, $message, $context);
		//echo "SYSLOG: $app: $message\n";
        syslog($level, "$app: $message");
        // syslog($level, "$app: $message | {$_SERVER['REMOTE_ADDR']} ({$_SERVER['HTTP_USER_AGENT']})");
	}
}

?>