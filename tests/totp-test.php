<?php
/*
 * totp-test.php — the TOTP core against RFC 6238 and the acceptance rules.
 *
 *   php tests/totp-test.php
 */

require __DIR__ . '/../module/totpauth/lib/totp.inc.php';

$failures = 0;
function check(string $name, bool $ok): void
{
	global $failures;
	printf("  %s  %s\n", $ok ? 'ok  ' : 'FAIL', $name);
	if (!$ok) {
		$failures++;
	}
}

echo "RFC 6238 appendix B, SHA-1, 8 digits\n";
$seed = '12345678901234567890';
$vectors = array(
	59 => '94287082',
	1111111109 => '07081804',
	1111111111 => '14050471',
	1234567890 => '89005924',
	2000000000 => '69279037',
	20000000000 => '65353130',
);
foreach ($vectors as $time => $expected) {
	check("T=$time -> $expected", totpauth_code($seed, totpauth_step($time), 8) === $expected);
}
check('6 digits are the last six of the 8-digit value', totpauth_code($seed, totpauth_step(59)) === '287082');

echo "Base32\n";
check('RFC 4648 "foobar" -> MZXW6YTBOI', totpauth_base32_encode('foobar') === 'MZXW6YTBOI');
check('decode ignores case, spaces and padding', totpauth_base32_decode('mzxw 6ytb oi==') === 'foobar');
check('decode rejects characters outside the alphabet', totpauth_base32_decode('MZXW1') === null);
$secret = totpauth_new_secret();
check('new secret has 20 bytes', strlen($secret) === 20);
check('round trip of a random secret', totpauth_base32_decode(totpauth_base32_encode($secret)) === $secret);
check('two new secrets differ', totpauth_new_secret() !== $secret);

echo "Acceptance\n";
$now = 1790000000;
$step = totpauth_step($now);
$code = totpauth_code($secret, $step);
check('current code accepted, returns its step', totpauth_match($secret, $code, 0, $now) === $step);
check('code of the previous step accepted', totpauth_match($secret, totpauth_code($secret, $step - 1), 0, $now) === $step - 1);
check('code of the next step accepted', totpauth_match($secret, totpauth_code($secret, $step + 1), 0, $now) === $step + 1);
check('code two steps back refused', totpauth_match($secret, totpauth_code($secret, $step - 2), 0, $now) === null);
check('code two steps ahead refused', totpauth_match($secret, totpauth_code($secret, $step + 2), 0, $now) === null);
check('step equal to last accepted refused (replay)', totpauth_match($secret, $code, $step, $now) === null);
check('step older than last accepted refused', totpauth_match($secret, totpauth_code($secret, $step - 1), $step, $now) === null);
check('spaces inside the code are ignored', totpauth_match($secret, substr($code, 0, 3) . ' ' . substr($code, 3), 0, $now) === $step);
check('five digits refused', totpauth_match($secret, substr($code, 0, 5), 0, $now) === null);
check('letters refused', totpauth_match($secret, 'abcdef', 0, $now) === null);
check('wrong code refused', totpauth_match($secret, $code === '000000' ? '111111' : '000000', 0, $now) === null);

echo "otpauth URI\n";
$uri = totpauth_uri('cp.example.test', 'max@example.test', 'foobar');
check('scheme, label and secret', strpos($uri, 'otpauth://totp/cp.example.test:max%40example.test?secret=MZXW6YTBOI') === 0);
check('issuer, algorithm, digits, period', strpos($uri, '&issuer=cp.example.test&algorithm=SHA1&digits=6&period=30') !== false);

echo "\n";
if ($failures > 0) {
	fwrite(STDERR, "$failures check(s) failed\n");
	exit(1);
}
echo "All TOTP checks passed.\n";
