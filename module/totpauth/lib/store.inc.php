<?php
/*
 * store.inc.php — the table totpauth_user: encrypted secrets, hashed recovery
 * codes, the last accepted step and a failure counter per panel account.
 *
 * Talks to the database only through query() / queryOneRecord() /
 * affectedRows() of ISPConfig's db class. Every UPDATE that decides whether
 * a login goes through carries its own condition, so two requests racing
 * with the same code cannot both win.
 */

const TOTPAUTH_RECOVERY_CODES = 8;

/* The 32-byte key that encrypts the stored secrets. */
function totpauth_key(string $file): string
{
	if (!is_file($file) || !is_readable($file)) {
		throw new RuntimeException("key file $file is missing or not readable");
	}
	$key = (string) file_get_contents($file);
	if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
		throw new RuntimeException("key file $file does not hold " . SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES . ' bytes');
	}
	return $key;
}

/* Lower case, base32 letters only: what a person types may differ in case and dashes. */
function totpauth_recovery_normalize(string $code): string
{
	return preg_replace('/[^a-z2-7]/', '', strtolower($code));
}

class totpauth_store
{
	private $db;
	private $key;

	public function __construct($db, string $key)
	{
		$this->db = $db;
		$this->key = $key;
	}

	public function get(int $uid): ?array
	{
		$row = $this->db->queryOneRecord('SELECT * FROM totpauth_user WHERE userid = ?', $uid);
		return is_array($row) && !empty($row) ? $row : null;
	}

	public function enabled(int $uid): bool
	{
		return $this->get($uid) !== null;
	}

	/* Stores a confirmed secret and returns the recovery codes in plain text, once. */
	public function enable(int $uid, string $secretBin): array
	{
		$codes = array();
		$hashes = array();
		while (count($codes) < TOTPAUTH_RECOVERY_CODES) {
			$raw = strtolower(totpauth_base32_encode(random_bytes(8)));
			$code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
			if (in_array($code, $codes, true)) {
				continue;
			}
			$codes[] = $code;
			$hashes[] = password_hash(totpauth_recovery_normalize($code), PASSWORD_DEFAULT);
		}

		$this->db->query('DELETE FROM totpauth_user WHERE userid = ?', $uid);
		$this->db->query(
			'INSERT INTO totpauth_user (userid, secret, recovery, last_step, failed, created) VALUES (?, ?, ?, 0, 0, ?)',
			$uid, $this->encrypt($uid, $secretBin), json_encode($hashes), date('Y-m-d H:i:s')
		);
		return $codes;
	}

	public function secret(array $row): string
	{
		$blob = base64_decode((string) $row['secret'], true);
		$nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ($blob === false || strlen($blob) <= $nonceLen) {
			throw new RuntimeException('stored secret of user ' . (int) $row['userid'] . ' is malformed');
		}
		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			substr($blob, $nonceLen), $this->aad((int) $row['userid']), substr($blob, 0, $nonceLen), $this->key
		);
		if ($plain === false) {
			throw new RuntimeException('stored secret of user ' . (int) $row['userid'] . ' does not decrypt with this key');
		}
		return $plain;
	}

	/* True when $step is newer than the last accepted one and is now the last one. */
	public function accept_step(int $uid, int $step): bool
	{
		$this->db->query('UPDATE totpauth_user SET last_step = ?, failed = 0 WHERE userid = ? AND last_step < ?', $step, $uid, $step);
		return $this->db->affectedRows() === 1;
	}

	public function use_recovery(int $uid, string $code): bool
	{
		$row = $this->get($uid);
		$given = totpauth_recovery_normalize($code);
		if ($row === null || $given === '') {
			return false;
		}
		$hashes = json_decode((string) $row['recovery'], true);
		if (!is_array($hashes)) {
			return false;
		}
		foreach ($hashes as $i => $hash) {
			if (password_verify($given, $hash)) {
				unset($hashes[$i]);
				// Compare-and-swap on the old list: of two requests using the
				// same code, only the first changes the row.
				$this->db->query(
					'UPDATE totpauth_user SET recovery = ?, failed = 0 WHERE userid = ? AND recovery = ?',
					json_encode(array_values($hashes)), $uid, $row['recovery']
				);
				return $this->db->affectedRows() === 1;
			}
		}
		return false;
	}

	public function recovery_left(array $row): int
	{
		$hashes = json_decode((string) $row['recovery'], true);
		return is_array($hashes) ? count($hashes) : 0;
	}

	/* Counts a failed attempt and returns the new total. */
	public function failed(int $uid): int
	{
		$this->db->query('UPDATE totpauth_user SET failed = failed + 1 WHERE userid = ?', $uid);
		$row = $this->get($uid);
		return $row === null ? 0 : (int) $row['failed'];
	}

	public function reset_failed(int $uid): void
	{
		$this->db->query('UPDATE totpauth_user SET failed = 0 WHERE userid = ?', $uid);
	}

	public function delete(int $uid): void
	{
		$this->db->query('DELETE FROM totpauth_user WHERE userid = ?', $uid);
	}

	private function encrypt(int $uid, string $plain): string
	{
		$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $this->aad($uid), $nonce, $this->key));
	}

	/* Binds the ciphertext to its account: moved to another row it fails. */
	private function aad(int $uid): string
	{
		return 'totpauth:user:' . $uid;
	}
}
