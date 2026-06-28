<?php
use PHPUnit\Framework\TestCase;

// MySQL parity for the ORM. The same db fixture and probes that DbTest runs on SQLite run here
// against a real MySQL (PHLO_TEST_DB=mysql, creds via PHLO__mysql__* env). Skipped when no MySQL is
// configured, so the default suite stays dependency-free; CI runs it against a MySQL service. Covers
// the behaviours that differ between engines: CRUD + the driver-specific INSERT-IGNORE path, a
// custom string primary key, relation loading, and the audit feature's transaction + savepoint
// rollback.
final class MySqlTest extends TestCase {

	private static string $entry = __DIR__.'/fixtures/db/www/app.php';

	private static function cli(string ...$args):array {
		$proc = proc_open([PHP_BINARY, self::$entry, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	private static function fetch(string $target):array {
		[$code, $out, $err] = self::cli($target);
		self::assertSame(0, $code, "$target failed:\n$out$err");
		$r = json_decode(trim($out), true);
		self::assertIsArray($r, "no JSON from $target: $out");
		return $r;
	}

	public static function setUpBeforeClass():void {
		if (getenv('PHLO_TEST_DB') !== 'mysql') self::markTestSkipped('MySQL parity test: set PHLO_TEST_DB=mysql and PHLO__mysql__* to run (CI does; the default suite is SQLite-only)');
		[$code, $out, $err] = self::cli('build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	public function testOrmOnMysql():void {
		$r = self::fetch('note::runTests');
		$this->assertTrue($r['create'] ?? false, 'create');
		$this->assertSame([1, 2], $r['ids'] ?? null, 'auto-increment ids');
		$this->assertTrue($r['fetch'] ?? false, 'record by id + typed columns');
		$this->assertSame(2, $r['count'] ?? null, 'recordCount');
		$this->assertSame(2, $r['list'] ?? null, 'records');
		$this->assertTrue($r['ignoreNoNewRow'] ?? false, 'INSERT IGNORE adds no row on conflict (the MySQL syntax differs from SQLite INSERT OR IGNORE)');
		$this->assertTrue($r['ignoreNoOverwrite'] ?? false, 'INSERT IGNORE does not overwrite');
		$this->assertTrue($r['update'] ?? false, 'change');
		$this->assertTrue($r['cacheFresh'] ?? false, 'record cache reflects the update, not a stale copy');
		$this->assertTrue($r['deleteGone'] ?? false, 'delete removes the row');
		$this->assertSame(1, $r['countAfterDelete'] ?? null, 'count after delete');
	}

	public function testCustomStringPrimaryKeyOnMysql():void {
		$r = self::fetch('code::runTests');
		$this->assertTrue($r['create'] ?? false, 'create reloads the row by its custom VARCHAR primary key');
		$this->assertTrue($r['reload'] ?? false, 'record() finds the row by the custom PK');
		$this->assertTrue($r['columns'] ?? false, 'objData exposes the bare record columns');
		$this->assertTrue($r['saveNew'] ?? false, 'objSave inserts and reloads the new record by its PK');
		$this->assertTrue($r['saveUpdate'] ?? false, 'objSave updates and reloads an existing record');
	}

	public function testRelationLoadingOnMysql():void {
		$r = self::fetch('lst::runTests');
		$this->assertSame(1, $r['childrenA'] ?? null, 'the first parent loads its children');
		$this->assertSame(1, $r['childrenB'] ?? null, 'a parent loaded after the first still loads its children');
	}

	public function testAuditTransactionsAndSavepointsOnMysql():void {
		$logged = self::fetch('audited::runTests');
		$this->assertSame(1, $logged['logged'] ?? null, 'an audited change is logged');
		$this->assertTrue($logged['amountChanged'] ?? false, 'the audit captures the amount change');

		$rollback = self::fetch('audited::runRollback');
		$this->assertTrue($rollback['threw'] ?? false, 'the audit failure propagates');
		$this->assertSame(1, $rollback['amount'] ?? null, 'the change rolls back when its audit insert fails - the mutation and audit are one transaction');

		$nested = self::fetch('audited::runNestedRollback');
		$this->assertTrue($nested['threw'] ?? false, 'the nested audit failure propagates');
		$this->assertSame(1, $nested['amount'] ?? null, 'a nested audit failure rolls back via SAVEPOINT inside the caller transaction, which then commits the unchanged row');

		$save = self::fetch('audited::runSaveCreate');
		$this->assertSame(1, $save['logged'] ?? null, 'objSave() of a new audited record logs a create');
	}
}
