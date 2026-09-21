<?php
/*
 * fakedb.php — the two calls of ISPConfig's db class the store uses, backed
 * by an in-memory SQLite database. It proves the store's logic, not its SQL:
 * the statements are run against MariaDB in the end-to-end test.
 */

class totpauth_fakedb
{
	public $pdo;
	private $affected = 0;

	public function __construct()
	{
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE totpauth_user (
			userid INTEGER PRIMARY KEY,
			secret TEXT NOT NULL,
			recovery TEXT NOT NULL,
			last_step INTEGER NOT NULL DEFAULT 0,
			failed INTEGER NOT NULL DEFAULT 0,
			created TEXT NOT NULL
		)');
		$this->pdo->exec("CREATE TABLE sys_user (userid INTEGER PRIMARY KEY, otp_type TEXT NOT NULL DEFAULT 'none')");
	}

	public function queryOneRecord($sql, ...$params)
	{
		$st = $this->pdo->prepare($sql);
		$st->execute($params);
		$row = $st->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	public function query($sql, ...$params)
	{
		$st = $this->pdo->prepare($sql);
		$st->execute($params);
		$this->affected = $st->rowCount();
		return true;
	}

	public function affectedRows()
	{
		return $this->affected;
	}
}
