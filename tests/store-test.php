<?php
/*
 * store-test.php — encryption, recovery codes, counters of totpauth_store.
 *
 *   php tests/store-test.php
 */

require __DIR__ . '/../module/totpauth/lib/totp.inc.php';
require __DIR__ . '/../module/totpauth/lib/store.inc.php';
require __DIR__ . '/fakedb.php';

$failures = 0;
function check(string $name, bool $ok): void
{
	global $failures;
	printf("  %s  %s\n", $ok ? 'ok  ' : 'FAIL', $name);
	if (!$ok) {
		$failures++;
	}
}
function throws(callable $fn): bool
{
	try {
		$fn();
	} catch (RuntimeException $e) {
		return true;
	}
	return false;
}

$db = new totpauth_fakedb();
$key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
$store = new totpauth_store($db, $key);
$secret = totpauth_new_secret();

echo "Key file\n";
$dir = sys_get_temp_dir() . '/totpauth-test-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents("$dir/ok.key", $key);
file_put_contents("$dir/short.key", 'too short');
check('32-byte key file loads', totpauth_key("$dir/ok.key") === $key);
check('short key file refused', throws(function () use ($dir) { totpauth_key("$dir/short.key"); }));
check('missing key file refused', throws(function () use ($dir) { totpauth_key("$dir/none.key"); }));
array_map('unlink', glob("$dir/*"));
rmdir($dir);

echo "Enable and read back\n";
check('nothing stored yet', $store->get(7) === null && !$store->enabled(7));
$codes = $store->enable(7, $secret);
check('eight recovery codes', count($codes) === 8);
check('codes look like xxxx-xxxx-xxxx', count(preg_grep('/^[a-z2-7]{4}-[a-z2-7]{4}-[a-z2-7]{4}$/', $codes)) === 8);
check('codes are distinct', count(array_unique($codes)) === 8);
$row = $store->get(7);
check('enabled after enable()', $store->enabled(7));
check('secret decrypts to the original', $store->secret($row) === $secret);
check('stored secret is not the plain secret', strpos($row['secret'], totpauth_base32_encode($secret)) === false && strpos(base64_decode($row['secret']), $secret) === false);
check('recovery codes stored as hashes only', strpos($row['recovery'], $codes[0]) === false);
$first = $row['secret'];
$store->enable(7, $secret);
check('a fresh nonce on every save', $store->get(7)['secret'] !== $first);

echo "Tampering\n";
$other = new totpauth_store($db, random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
check('another key cannot decrypt', throws(function () use ($other, $store) { $other->secret($store->get(7)); }));
$store->enable(8, totpauth_new_secret());
$moved = $store->get(7);
$moved['userid'] = 8;
check('a record moved to another user does not decrypt', throws(function () use ($store, $moved) { $store->secret($moved); }));

echo "Steps\n";
check('first step accepted', $store->accept_step(7, 100) === true);
check('same step again refused', $store->accept_step(7, 100) === false);
check('older step refused', $store->accept_step(7, 99) === false);
check('newer step accepted', $store->accept_step(7, 101) === true);
check('last_step stored', (int) $store->get(7)['last_step'] === 101);

echo "Recovery codes\n";
$codes = $store->enable(9, totpauth_new_secret());
check('unknown code refused', $store->use_recovery(9, 'aaaa-aaaa-aaaa') === false);
check('code accepted with other case and no dashes', $store->use_recovery(9, strtoupper(str_replace('-', '', $codes[2]))) === true);
check('same code refused the second time', $store->use_recovery(9, $codes[2]) === false);
check('seven left', $store->recovery_left($store->get(9)) === 7);
check('another code still works', $store->use_recovery(9, $codes[5]) === true);
check('user without record refused', $store->use_recovery(99, $codes[0]) === false);

echo "Failed attempts\n";
check('counts up', $store->failed(9) === 1 && $store->failed(9) === 2);
$store->reset_failed(9);
check('reset to 0', (int) $store->get(9)['failed'] === 0);
$store->failed(9);
$store->accept_step(9, 5);
check('accepted step resets the counter', (int) $store->get(9)['failed'] === 0);

echo "Delete\n";
$store->delete(9);
check('gone after delete()', !$store->enabled(9));

echo "\n";
if ($failures > 0) {
	fwrite(STDERR, "$failures check(s) failed\n");
	exit(1);
}
echo "All store checks passed.\n";
