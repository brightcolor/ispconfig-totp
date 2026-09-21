<?php
/*
 * totp.inc.php — time-based one-time passwords after RFC 6238 (SHA-1, six
 * digits, 30-second steps), the way authenticator apps compute them.
 *
 * No state and no dependencies beyond PHP itself, so it can be tested
 * outside ISPConfig.
 */

const TOTPAUTH_PERIOD = 30;
const TOTPAUTH_DIGITS = 6;
const TOTPAUTH_WINDOW = 1; // steps accepted either side of now, for clock drift
const TOTPAUTH_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totpauth_base32_encode(string $bin): string
{
	$bits = '';
	foreach (str_split($bin) as $char) {
		$bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
	}
	$out = '';
	foreach (str_split($bits, 5) as $chunk) {
		$out .= TOTPAUTH_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
	}
	return $bin === '' ? '' : $out;
}

/* Accepts what people type or paste: any case, spaces, trailing padding. */
function totpauth_base32_decode(string $b32): ?string
{
	$clean = strtoupper(preg_replace('/[\s=]+/', '', $b32));
	if ($clean === '' || strspn($clean, TOTPAUTH_ALPHABET) !== strlen($clean)) {
		return null;
	}
	$bits = '';
	foreach (str_split($clean) as $char) {
		$bits .= str_pad(decbin(strpos(TOTPAUTH_ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
	}
	$out = '';
	foreach (str_split($bits, 8) as $byte) {
		if (strlen($byte) === 8) {
			$out .= chr(bindec($byte));
		}
	}
	return $out;
}

function totpauth_step(?int $time = null): int
{
	return intdiv($time ?? time(), TOTPAUTH_PERIOD);
}

/* HOTP (RFC 4226) over the time step. */
function totpauth_code(string $secretBin, int $step, int $digits = TOTPAUTH_DIGITS): string
{
	$counter = pack('J', $step); // 64-bit big endian
	$hash = hash_hmac('sha1', $counter, $secretBin, true);
	$offset = ord($hash[19]) & 0x0f;
	$value = ((ord($hash[$offset]) & 0x7f) << 24)
		| (ord($hash[$offset + 1]) << 16)
		| (ord($hash[$offset + 2]) << 8)
		| ord($hash[$offset + 3]);
	return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/*
 * The step the code belongs to, or null. Only steps after $lastStep count,
 * so a code that already opened a session cannot open a second one.
 */
function totpauth_match(string $secretBin, string $code, int $lastStep, ?int $time = null): ?int
{
	$code = preg_replace('/\s+/', '', $code);
	if (!preg_match('/^\d{' . TOTPAUTH_DIGITS . '}$/', $code)) {
		return null;
	}
	$now = totpauth_step($time);
	$found = null;
	for ($step = $now - TOTPAUTH_WINDOW; $step <= $now + TOTPAUTH_WINDOW; $step++) {
		// Every candidate is compared, so the time taken says nothing about
		// which step matched.
		if (hash_equals(totpauth_code($secretBin, $step), $code) && $step > $lastStep && $found === null) {
			$found = $step;
		}
	}
	return $found;
}

function totpauth_new_secret(): string
{
	return random_bytes(20);
}

function totpauth_uri(string $issuer, string $account, string $secretBin): string
{
	return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
		. '?secret=' . totpauth_base32_encode($secretBin)
		. '&issuer=' . rawurlencode($issuer)
		. '&algorithm=SHA1&digits=' . TOTPAUTH_DIGITS . '&period=' . TOTPAUTH_PERIOD;
}
