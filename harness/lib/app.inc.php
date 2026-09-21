<?php
/*
 * Stand-in for interface/lib/app.inc.php: a session, a SQLite database with
 * ISPConfig's db calls, CSRF tokens exactly as auth.inc.php makes them, and
 * the logs. Enough to run the module's real api.php.
 */

require_once HARNESS . '/../tests/fakedb.php';

if (!defined('LOGLEVEL_DEBUG')) {
	define('LOGLEVEL_DEBUG', 0);
	define('LOGLEVEL_WARN', 1);
	define('LOGLEVEL_ERROR', 2);
}

@mkdir(HARNESS . '/state', 0777, true);
session_save_path(HARNESS . '/state');
session_name('HARNESSSESS');
session_start();

class harness_auth
{
	/* Copied behaviour of auth.inc.php::csrf_token_get(). */
	public function csrf_token_get($form_name)
	{
		$id = $form_name . '_' . bin2hex(random_bytes(12));
		$key = sha1(random_bytes(20));
		$_SESSION['_csrf'][$id] = $key;
		$_SESSION['_csrf_timeout'][$id] = time() + 3600;
		return array('csrf_id' => $id, 'csrf_key' => $key);
	}
}

class harness_getconf
{
	public function get_global_config($section = '')
	{
		$days = (int) (@file_get_contents(HARNESS . '/state/force_days') ?: 0);
		return array('force_password_change_days' => $days);
	}
}

class harness_app
{
	public $db, $auth, $getconf;

	public function __construct()
	{
		$this->db = new totpauth_fakedb(HARNESS . '/state/harness.sqlite');
		$this->auth = new harness_auth();
		$this->getconf = new harness_getconf();
	}

	public function uses($classes) {}

	public function log($msg, $level = 0)
	{
		file_put_contents(HARNESS . '/state/ispconfig.log', date('c') . " [$level] $msg\n", FILE_APPEND);
	}

	public function auth_log($msg)
	{
		file_put_contents(HARNESS . '/state/auth.log', $msg . "\n", FILE_APPEND);
	}
}

$app = new harness_app();
