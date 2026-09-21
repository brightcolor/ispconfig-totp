<?php
/*
 * setup.php — database part of the installer.
 *
 *   php install/setup.php <interface-path> check     prints force_days=<n>
 *   php install/setup.php <interface-path> create    creates the table if missing
 *   php install/setup.php <interface-path> drop      removes the table (uninstall --purge)
 *
 * Uses the credentials of the panel itself from lib/config.inc.php, so no
 * password is typed or stored anywhere else.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}
$iface = rtrim((string) ($argv[1] ?? ''), '/');
$mode = (string) ($argv[2] ?? '');
if ($iface === '' || !is_file("$iface/lib/config.inc.php") || !in_array($mode, array('check', 'create', 'drop'), true)) {
	fwrite(STDERR, "usage: php install/setup.php <interface-path> check|create|drop\n");
	exit(2);
}

$conf = array();
// config.inc.php sends headers and defines constants; harmless on the CLI.
require "$iface/lib/config.inc.php";

mysqli_report(MYSQLI_REPORT_OFF);

/*
 * The panel's own database user may only read and write (SELECT, INSERT,
 * UPDATE, DELETE on the panel database), which covers the new table once it
 * exists. Creating and dropping it needs MySQL's root, reached through the
 * local socket the way the mysql client does for the system's root user.
 */
if ($mode === 'check') {
	$db = @new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_database'], (int) ($conf['db_port'] ?? 3306));
	$who = 'the panel credentials from lib/config.inc.php';
} else {
	$db = @new mysqli('localhost', 'root', '', $conf['db_database']);
	$who = "MySQL's root over the local socket (run this as the system's root)";
}
if ($db->connect_errno) {
	fwrite(STDERR, "error: cannot reach the panel database as $who: " . $db->connect_error . "\n");
	exit(1);
}
$db->set_charset('utf8mb4');

if ($mode === 'check') {
	$res = $db->query("SELECT config FROM sys_ini WHERE sysini_id = 1");
	$row = $res ? $res->fetch_assoc() : null;
	if (!$row) {
		fwrite(STDERR, "error: sys_ini has no row 1, this does not look like an ISPConfig database\n");
		exit(1);
	}
	$days = 0;
	if (preg_match('/^force_password_change_days=(\d+)/m', (string) $row['config'], $m)) {
		$days = (int) $m[1];
	}
	echo "force_days=$days\n";
	exit(0);
}

if ($mode === 'drop') {
	if (!$db->query('DROP TABLE IF EXISTS totpauth_user')) {
		fwrite(STDERR, 'error: DROP TABLE failed: ' . $db->error . "\n");
		exit(1);
	}
	echo "table totpauth_user removed\n";
	exit(0);
}

$sql = "CREATE TABLE IF NOT EXISTS totpauth_user (
	userid INT UNSIGNED NOT NULL PRIMARY KEY,
	secret VARCHAR(255) NOT NULL,
	recovery TEXT NOT NULL,
	last_step BIGINT NOT NULL DEFAULT 0,
	failed INT UNSIGNED NOT NULL DEFAULT 0,
	created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$db->query($sql)) {
	fwrite(STDERR, 'error: CREATE TABLE failed: ' . $db->error . "\n");
	exit(1);
}
$res = $db->query("SHOW COLUMNS FROM totpauth_user");
$cols = array();
while ($res && ($r = $res->fetch_assoc())) {
	$cols[] = $r['Field'];
}
$want = array('userid', 'secret', 'recovery', 'last_step', 'failed', 'created');
if (array_diff($want, $cols)) {
	fwrite(STDERR, 'error: table totpauth_user exists but lacks columns: ' . implode(', ', array_diff($want, $cols)) . "\n");
	exit(1);
}
echo "table totpauth_user ready\n";
