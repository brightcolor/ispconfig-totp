<?php
/*
 * common.inc.php — shared by the plugin, the code page and the API.
 * Expects ISPConfig's $app to be loaded.
 */

require_once __DIR__ . '/totp.inc.php';
require_once __DIR__ . '/store.inc.php';

const TOTPAUTH_MAX_SESSION_TRIES = 5;   // wrong codes in one login before it is ended
const TOTPAUTH_MAX_TRIES = 10;          // wrong codes since the last good login before the app is locked
const TOTPAUTH_LOGIN_TIMEOUT = 600;     // seconds between password and code

/* Next to interface/, outside the web root: /usr/local/ispconfig/totpauth/secret.key */
function totpauth_key_file(): string
{
	return dirname(ISPC_ROOT_PATH) . '/totpauth/secret.key';
}

/* A store for lookups. Pass $withKey when secrets are read or written. */
function totpauth_app_store(bool $withKey = false): totpauth_store
{
	global $app;
	return new totpauth_store($app->db, $withKey ? totpauth_key(totpauth_key_file()) : '');
}

/* Texts in the panel language, English where a text is missing. */
function totpauth_lng(string $key): string
{
	static $texts = null;
	if ($texts === null) {
		global $conf;
		$lang = 'en';
		foreach (array($_SESSION['s']['language'] ?? null, $_SESSION['s_pending']['language'] ?? null, $conf['language'] ?? null) as $candidate) {
			if (is_string($candidate) && preg_match('/^[a-z]{2}$/', $candidate)) {
				$lang = $candidate;
				break;
			}
		}
		$wb = array();
		include __DIR__ . '/lang/en.lng';
		$texts = $wb;
		if ($lang !== 'en' && is_file(__DIR__ . '/lang/' . $lang . '.lng')) {
			$wb = array();
			include __DIR__ . '/lang/' . $lang . '.lng';
			$texts = array_merge($texts, $wb);
		}
	}
	return $texts[$key] ?? $key;
}

/* All texts of the language file, for templates and the profile script. */
function totpauth_texts(): array
{
	$wb = array();
	include __DIR__ . '/lang/en.lng';
	$keys = array_keys($wb);
	$out = array();
	foreach ($keys as $key) {
		$out[$key] = totpauth_lng($key);
	}
	return $out;
}

/*
 * Panel accounts from sys_user only. Mail users log in through a user built
 * from mail_user (build_fake_user in login/index.php); their id belongs to
 * another table and could equal a sys_user id.
 */
function totpauth_eligible($user): bool
{
	return is_array($user)
		&& !isset($user['mailuser_id'])
		&& in_array($user['typ'] ?? '', array('admin', 'user'), true)
		&& (int) ($user['userid'] ?? 0) > 0;
}

/*
 * With force_password_change_days above 0, ISPConfig sends users with an
 * old password to force_password_change.php before the login event, and
 * that page opens the session without raising it. The app would be skipped.
 */
function totpauth_force_change_active(): bool
{
	global $app;
	$app->uses('getconf');
	$misc = $app->getconf->get_global_config('misc');
	return (int) ($misc['force_password_change_days'] ?? 0) > 0;
}

function totpauth_issuer(): string
{
	$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
	return preg_match('/^[a-z0-9.-]{1,253}$/i', $host) ? $host : 'ISPConfig';
}
