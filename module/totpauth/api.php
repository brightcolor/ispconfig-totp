<?php
/*
 * api.php — JSON for the profile modal and the admin button.
 *
 * Every answer is JSON, errors included, and carries a fresh one-time CSRF
 * token for the next call. The account is always the one of the session;
 * only the admin_* actions take a user id, and only from an admin.
 *
 *   GET  ?action=status
 *   POST action=begin | confirm (code) | disable (code)
 *   POST action=admin_status | admin_reset (userid)     admins only
 */

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once __DIR__ . '/lib/common.inc.php';

const TOTPAUTH_SETUP_TIMEOUT = 900; // seconds from QR code to confirming code

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function totpauth_reply(array $data, int $status = 200): void
{
	global $app;
	http_response_code($status);
	if (($_SESSION['s']['user']['active'] ?? 0) == 1) {
		$token = $app->auth->csrf_token_get('totpauth_api');
		$data['csrf'] = array('id' => $token['csrf_id'], 'key' => $token['csrf_key']);
	}
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function totpauth_fail(string $key, int $status = 400): void
{
	totpauth_reply(array('ok' => false, 'error' => totpauth_lng($key)), $status);
}

/*
 * Same token store as ISPConfig's csrf_token_check(), which answers a bad
 * token with an HTML error page; the modal needs JSON.
 */
function totpauth_csrf_ok(): bool
{
	$id = trim((string) ($_POST['_csrf_id'] ?? ''));
	$key = trim((string) ($_POST['_csrf_key'] ?? ''));
	$ok = $id !== ''
		&& isset($_SESSION['_csrf'][$id], $_SESSION['_csrf_timeout'][$id])
		&& hash_equals((string) $_SESSION['_csrf'][$id], $key)
		&& $_SESSION['_csrf_timeout'][$id] >= time();
	unset($_SESSION['_csrf'][$id], $_SESSION['_csrf_timeout'][$id]);
	return $ok;
}

/* App code or recovery code of $uid; counts failures, honours the lock. */
function totpauth_check_code(totpauth_store $store, int $uid, string $given): bool
{
	$row = $store->get($uid);
	if ($row === null) {
		return false;
	}
	$digits = preg_replace('/\s+/', '', $given);
	if (preg_match('/^\d{' . TOTPAUTH_DIGITS . '}$/', $digits)) {
		if ((int) $row['failed'] < TOTPAUTH_MAX_TRIES) {
			$step = totpauth_match($store->secret($row), $digits, (int) $row['last_step']);
			if ($step !== null && $store->accept_step($uid, $step)) {
				return true;
			}
		}
	} elseif ($store->use_recovery($uid, $given)) {
		return true;
	}
	$store->failed($uid);
	return false;
}

if (($_SESSION['s']['user']['active'] ?? 0) != 1) {
	totpauth_fail('totpauth_err_session_txt', 401);
}
$user = $_SESSION['s']['user'];
if (!totpauth_eligible($user)) {
	totpauth_fail('totpauth_err_forbidden_txt', 403);
}
$uid = (int) $user['userid'];
$isAdmin = ($user['typ'] ?? '') === 'admin';
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($action !== 'status' && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !totpauth_csrf_ok())) {
	totpauth_fail('totpauth_err_request_txt', 400);
}

try {
	switch ($action) {
		case 'status':
			$store = totpauth_app_store();
			$row = $store->get($uid);
			totpauth_reply(array(
				'ok' => true,
				'enabled' => $row !== null,
				'recovery_left' => $row !== null ? $store->recovery_left($row) : 0,
				'locked' => $row !== null && (int) $row['failed'] >= TOTPAUTH_MAX_TRIES,
				'force_change' => $isAdmin && totpauth_force_change_active(),
				'is_admin' => $isAdmin,
				'texts' => totpauth_texts(),
			));

		case 'begin':
			if (totpauth_app_store()->enabled($uid)) {
				totpauth_fail('totpauth_err_request_txt');
			}
			$secret = totpauth_new_secret();
			$_SESSION['totpauth_setup'] = array('secret' => base64_encode($secret), 'started' => time());
			$b32 = totpauth_base32_encode($secret);
			totpauth_reply(array(
				'ok' => true,
				'uri' => totpauth_uri(totpauth_issuer(), (string) $user['username'], $secret),
				'secret' => trim(chunk_split($b32, 4, ' ')),
			));

		case 'confirm':
			$setup = $_SESSION['totpauth_setup'] ?? null;
			if (!is_array($setup) || time() - (int) $setup['started'] > TOTPAUTH_SETUP_TIMEOUT) {
				unset($_SESSION['totpauth_setup']);
				totpauth_fail('totpauth_err_setup_expired_txt');
			}
			$secret = base64_decode($setup['secret']);
			$step = totpauth_match($secret, (string) ($_POST['code'] ?? ''), 0);
			if ($step === null) {
				totpauth_fail('totpauth_err_code_txt');
			}
			$store = totpauth_app_store(true);
			$codes = $store->enable($uid, $secret);
			$store->accept_step($uid, $step); // this code must not open a login as well
			$app->db->query("UPDATE sys_user SET otp_type = 'none' WHERE userid = ?", $uid);
			unset($_SESSION['totpauth_setup']);
			$app->auth_log("Authenticator app set up for user '" . $user['username'] . "' from " . ($_SERVER['REMOTE_ADDR'] ?? '') . ' at ' . date('Y-m-d H:i:s'));
			totpauth_reply(array('ok' => true, 'codes' => $codes));

		case 'disable':
			$store = totpauth_app_store(true);
			if (!$store->enabled($uid)) {
				totpauth_fail('totpauth_err_not_enabled_txt');
			}
			if (!totpauth_check_code($store, $uid, (string) ($_POST['code'] ?? ''))) {
				$row = $store->get($uid);
				totpauth_fail($row !== null && (int) $row['failed'] >= TOTPAUTH_MAX_TRIES ? 'totpauth_err_locked_txt' : 'totpauth_err_code_txt');
			}
			$store->delete($uid);
			$app->auth_log("Authenticator app turned off by user '" . $user['username'] . "' from " . ($_SERVER['REMOTE_ADDR'] ?? '') . ' at ' . date('Y-m-d H:i:s'));
			totpauth_reply(array('ok' => true, 'message' => totpauth_lng('totpauth_disabled_txt')));

		case 'admin_status':
		case 'admin_reset':
			if (!$isAdmin) {
				totpauth_fail('totpauth_err_forbidden_txt', 403);
			}
			$target = (int) ($_POST['userid'] ?? 0);
			if ($target <= 0) {
				totpauth_fail('totpauth_err_request_txt');
			}
			$store = totpauth_app_store();
			if ($action === 'admin_status') {
				totpauth_reply(array('ok' => true, 'enabled' => $store->enabled($target)));
			}
			if (!$store->enabled($target)) {
				totpauth_fail('totpauth_err_not_enabled_txt');
			}
			$store->delete($target);
			$app->auth_log("Authenticator app of user id " . $target . " reset by admin '" . $user['username'] . "' from " . ($_SERVER['REMOTE_ADDR'] ?? '') . ' at ' . date('Y-m-d H:i:s'));
			totpauth_reply(array('ok' => true, 'message' => totpauth_lng('totpauth_admin_done_txt')));
	}
	totpauth_fail('totpauth_err_request_txt');
} catch (RuntimeException $e) {
	$app->log('totpauth: ' . $e->getMessage(), LOGLEVEL_ERROR);
	totpauth_fail('totpauth_err_server_txt', 500);
}
