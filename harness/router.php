<?php
/*
 * Harness for the profile modal and the API:
 *
 *   php -S 127.0.0.1:8142 -t harness harness/router.php
 *
 * /                     profile page (Tools -> Settings markup)
 * /?page=admin          System -> Users form of user id 2
 * /?as=user             signed in as a customer instead of the admin
 * /?force=30            pretend force_password_change_days = 30
 * /totpauth/api.php     the module's real api.php
 *
 * jQuery, Bootstrap and the theme come from the theme repository next door
 * (ispconfig-brightcolor/harness), see BC_HARNESS below.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$here = __DIR__;
$repo = dirname($here);
$bc = getenv('BC_HARNESS') ?: dirname($repo) . '/ispconfig-brightcolor/harness';

function serve(string $file): bool
{
	$types = array('js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'gif' => 'image/gif',
		'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject');
	if (!is_file($file)) {
		http_response_code(404);
		echo 'Nachbau: Datei fehlt: ' . basename($file);
		return true;
	}
	header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
	header('Cache-Control: no-store');
	readfile($file);
	return true;
}

if (preg_match('#^/js/js\.d/(totpauth[a-z-]*\.js)$#', $uri, $m)) {
	return serve("$repo/jsd/{$m[1]}");
}
if (preg_match('#^/themes/default/(.+)$#', $uri, $m)) {
	return serve("$bc/vendor/web/themes/default/{$m[1]}");
}
if (preg_match('#^/themes/brightcolor/(.+)$#', $uri, $m)) {
	return serve("$bc/build/themes/brightcolor/{$m[1]}");
}
if ($uri === '/js/jquery.min.js') {
	return serve("$bc/vendor/web/js/jquery.min.js");
}

if ($uri === '/totpauth/api.php') {
	@mkdir("$here/web/totpauth", 0777, true);
	chdir("$here/web/totpauth");
	require "$repo/module/totpauth/api.php";
	return true;
}

// ---- pages ---------------------------------------------------------------

require "$here/lib/config.inc.php";
require "$here/lib/app.inc.php";

if (!is_file("$here/totpauth/secret.key")) {
	@mkdir("$here/totpauth", 0777, true);
	file_put_contents("$here/totpauth/secret.key", random_bytes(32));
}
if (isset($_GET['force'])) {
	file_put_contents("$here/state/force_days", (string) (int) $_GET['force']);
}
$as = $_GET['as'] ?? ($_SESSION['harness_as'] ?? 'admin');
$_SESSION['harness_as'] = $as;
$_SESSION['s'] = array(
	'user' => $as === 'user'
		? array('userid' => 2, 'username' => 'musterfirma', 'typ' => 'user', 'active' => 1)
		: array('userid' => 1, 'username' => 'admin', 'typ' => 'admin', 'active' => 1),
	'language' => 'de',
	'theme' => 'brightcolor',
);
$app->db->query("INSERT OR IGNORE INTO sys_user (userid, otp_type) VALUES (1, 'email'), (2, 'none')");
$otp = $app->db->queryOneRecord('SELECT otp_type FROM sys_user WHERE userid = ?', $_SESSION['s']['user']['userid'])['otp_type'];
$theme = ($_GET['theme'] ?? 'brightcolor') === 'default' ? 'default' : 'brightcolor';
$mode = in_array($_GET['mode'] ?? '', array('light', 'dark'), true) ? $_GET['mode'] : 'light';
$page = $_GET['page'] ?? 'profile';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="de" data-theme="<?= $mode ?>">
<head>
<meta charset="utf-8">
<title>Nachbau totpauth</title>
<link rel="stylesheet" href="/themes/default/assets/stylesheets/bootstrap.min.css">
<link rel="stylesheet" href="/themes/default/assets/stylesheets/ispconfig.css">
<?php if ($theme === 'brightcolor'): ?>
<link rel="stylesheet" href="/themes/brightcolor/assets/stylesheets/brightcolor.css">
<?php else: ?>
<link rel="stylesheet" href="/themes/default/assets/stylesheets/themes/default/theme.min.css">
<?php endif; ?>
<style>body{padding:32px}</style>
</head>
<body class="<?= $theme === 'brightcolor' ? 'bc bc-panel' : '' ?>">
<div id="content"><form id="pageForm" class="form-horizontal" onsubmit="return false"><div id="pageContent"></div></form></div>

<template id="page-profile">
<div class="page-header" data-eyebrow="Werkzeuge"><h1>Einstellungen</h1></div>
<div class="content-tab-wrapper"><div class="tab-content"><div class="tab-pane active">
<div class="form-group">
  <label for="2fa" class="col-sm-3 control-label">Zwei-Faktor-Authentifizierung</label>
  <div class="col-sm-9">
    <select name="otp_type" id="otp_type" class="form-control">
      <option value="none"<?= $otp === 'none' ? ' selected' : '' ?>>none</option>
      <option value="email"<?= $otp === 'email' ? ' selected' : '' ?>>email</option>
    </select>
  </div>
</div>
<div class="form-group">
  <label for="language" class="col-sm-3 control-label">Sprache</label>
  <div class="col-sm-9"><select name="language" id="language" class="form-control"><option>de</option></select></div>
</div>
<input type="hidden" name="id" value="<?= (int) $_SESSION['s']['user']['userid'] ?>">
<div class="clear"><div class="right">
  <button class="btn btn-default formbutton-success" type="button" data-submit-form="pageForm" data-form-action="tools/user_settings.php">Speichern</button>
  <button class="btn btn-default formbutton-default" type="button" data-load-content="tools/index.php">Abbrechen</button>
</div></div>
</div></div></div>
</template>

<template id="page-admin">
<div class="page-header" data-eyebrow="System"><h1>Benutzer</h1></div>
<div class="content-tab-wrapper"><div class="tab-content"><div class="tab-pane active">
<div class="form-group"><label class="col-sm-3 control-label">Benutzername</label><div class="col-sm-9"><input class="form-control" value="musterfirma"></div></div>
<input type="hidden" name="id" value="2">
<div class="clear"><div class="right">
  <button class="btn btn-default formbutton-success" type="button" data-submit-form="pageForm" data-form-action="admin/users_edit.php">Speichern</button>
  <button class="btn btn-default formbutton-default" type="button" data-load-content="admin/users_list.php">Abbrechen</button>
</div></div>
</div></div></div>
</template>

<script src="/js/jquery.min.js"></script>
<script src="/themes/default/assets/javascripts/bootstrap.min.js"></script>
<script src="/js/js.d/totpauth.js"></script>
<script src="/js/js.d/totpauth-qr.js"></script>
<script>
// Like ISPConfig: the page content arrives after the frame, by AJAX.
setTimeout(function () {
	document.getElementById('pageContent').innerHTML = document.getElementById('page-<?= $page === 'admin' ? 'admin' : 'profile' ?>').innerHTML;
}, 50);
</script>
</body>
</html>
<?php
return true;
