<?php
/*
 * verify.php — the code page between password and panel.
 *
 * The plugin parked the session in s_pending and set $_SESSION['totpauth'].
 * A correct app code (or an unused recovery code) moves the session back
 * and opens the panel; wrong codes are counted per login and per account.
 */

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';
require_once __DIR__ . '/lib/common.inc.php';

// Already signed in: nothing to confirm.
if (($_SESSION['s']['user']['active'] ?? 0) == 1) {
	header('Location: ../index.php');
	die();
}

// No login waiting for a code: back to the start.
if (!isset($_SESSION['totpauth'], $_SESSION['s_pending']['user']['userid'])
	|| (int) $_SESSION['totpauth']['userid'] !== (int) $_SESSION['s_pending']['user']['userid']) {
	unset($_SESSION['totpauth'], $_SESSION['s_pending']);
	header('Location: ../login/index.php');
	die();
}

$uid = (int) $_SESSION['totpauth']['userid'];
$username = (string) $_SESSION['s_pending']['user']['username'];
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

function totpauth_end_login(): void
{
	unset($_SESSION['totpauth'], $_SESSION['s_pending']);
}

function totpauth_finish(string $how): void
{
	global $app, $username, $ip;
	$_SESSION['s'] = $_SESSION['s_pending'];
	totpauth_end_login();
	$app->auth_log("Successful login for user '" . $username . "' with authenticator app (" . $how . ") from " . $ip . ' at ' . date('Y-m-d H:i:s') . ' with session ID ' . session_id());
	session_write_close();
	header('Location: ../index.php');
	die();
}

if (isset($_GET['cancel'])) {
	totpauth_end_login();
	header('Location: ../login/index.php');
	die();
}

$error = '';
$ended = false;

if (time() - (int) $_SESSION['totpauth']['started'] > TOTPAUTH_LOGIN_TIMEOUT) {
	totpauth_end_login();
	$error = totpauth_lng('totpauth_err_expired_txt');
	$ended = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$app->auth->csrf_token_check();

	$given = trim((string) ($_POST['code'] ?? ''));
	$digits = preg_replace('/\s+/', '', $given);
	$isAppCode = preg_match('/^\d{' . TOTPAUTH_DIGITS . '}$/', $digits) === 1;
	$isRecovery = !$isAppCode && strlen(totpauth_recovery_normalize($given)) === 12;

	try {
		$store = totpauth_app_store(true);
		$row = $store->get($uid);
		if ($row === null) {
			// An admin reset the app while this login waited: the account has
			// no second factor any more, so the password was enough.
			totpauth_finish('app reset meanwhile');
		}
		$locked = (int) $row['failed'] >= TOTPAUTH_MAX_TRIES;

		if ($isAppCode && !$locked) {
			$step = totpauth_match($store->secret($row), $digits, (int) $row['last_step']);
			if ($step !== null && $store->accept_step($uid, $step)) {
				totpauth_finish('app code');
			}
		} elseif ($isRecovery) {
			if ($store->use_recovery($uid, $given)) {
				totpauth_finish('recovery code');
			}
		}

		// Everything below is a failed attempt.
		$total = $store->failed($uid);
		$_SESSION['totpauth']['attempts']++;
		$app->auth_log("Failed authenticator app code for user '" . $username . "' from " . $ip . ' at ' . date('Y-m-d H:i:s') . " ($total since last good login)");

		if ($_SESSION['totpauth']['attempts'] >= TOTPAUTH_MAX_SESSION_TRIES) {
			totpauth_end_login();
			$error = totpauth_lng('totpauth_err_session_tries_txt');
			$ended = true;
		} elseif ($total >= TOTPAUTH_MAX_TRIES && !$isRecovery) {
			$error = totpauth_lng('totpauth_err_locked_txt');
		} elseif ($isRecovery) {
			$error = totpauth_lng('totpauth_err_recovery_txt');
		} elseif ($isAppCode) {
			$error = totpauth_lng('totpauth_err_code_txt');
		} else {
			$error = totpauth_lng('totpauth_err_format_txt');
		}
	} catch (RuntimeException $e) {
		$app->log('totpauth: ' . $e->getMessage(), LOGLEVEL_ERROR);
		$error = totpauth_lng('totpauth_err_server_txt');
	}
}

$app->uses('tpl');
$app->tpl->newTemplate('main_login.tpl.htm');
$app->tpl->setInclude('content_tpl', __DIR__ . '/templates/verify.htm');

$logo = $app->db->queryOneRecord('SELECT * FROM sys_ini WHERE sysini_id = 1');
$app->tpl->setVar('base64_logo_txt', !empty($logo['custom_logo']) ? $logo['custom_logo'] : ($logo['default_logo'] ?? ''));
$app->tpl->setVar('current_theme', $_SESSION['s']['theme'] ?? 'default', true);
$app->tpl->setVar(totpauth_texts());
$app->tpl->setVar('error', $error, true);
$app->tpl->setVar('ended', $ended ? 1 : 0);
if (!$ended) {
	$csrf = $app->auth->csrf_token_get('totpauth_verify');
	$app->tpl->setVar('_csrf_id', $csrf['csrf_id']);
	$app->tpl->setVar('_csrf_key', $csrf['csrf_key']);
}
$app->tpl_defaults();
$app->tpl->pparse();
