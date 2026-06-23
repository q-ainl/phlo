<?php
use PHPUnit\Framework\TestCase;

// Proves the ORM is engine-agnostic by running a full CRUD suite (including the
// driver-specific INSERT-OR-IGNORE path) on SQLite, which has zero external
// dependency (pdo_sqlite ships with PHP). The fixture app picks its default engine
// with `static %model.DB => %SQLite(...)`, the same injection idiom real apps use.
// MySQL parity is verified through the live fleet (factuur/logbook run on MySQL),
// not here, to keep the suite dependency-free.
final class DbTest extends TestCase {

	private static string $appDir = __DIR__.'/fixtures/db/';
	private static string $entry  = __DIR__.'/fixtures/db/www/app.php';

	private static function cli(string ...$args):array {
		$proc = proc_open([PHP_BINARY, self::$entry, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	public static function setUpBeforeClass():void {
		@unlink(self::$appDir.'data/test.db');
		[$code, $out, $err] = self::cli('build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	public function testOrmOnSqlite():void {
		[$code, $out, $err] = self::cli('note::runTests');
		$this->assertSame(0, $code, "note::runTests failed:\n$out$err");
		$r = json_decode(trim($out), true);
		$this->assertIsArray($r, 'No JSON from runTests: '.$out);
		$this->assertTrue($r['create'] ?? false, 'create');
		$this->assertSame([1, 2], $r['ids'] ?? null, 'autoincrement ids');
		$this->assertTrue($r['fetch'] ?? false, 'record by id + typed columns');
		$this->assertSame(2, $r['count'] ?? null, 'recordCount');
		$this->assertSame(2, $r['list'] ?? null, 'records');
		$this->assertTrue($r['ignoreNoNewRow'] ?? false, 'INSERT OR IGNORE adds no row on conflict');
		$this->assertTrue($r['ignoreNoOverwrite'] ?? false, 'INSERT OR IGNORE does not overwrite');
		$this->assertTrue($r['update'] ?? false, 'change');
		$this->assertTrue($r['cacheFresh'] ?? false, 'identity-map cache reflects the update, not a stale copy');
		$this->assertTrue($r['deleteGone'] ?? false, 'delete removes the row');
		$this->assertSame(1, $r['countAfterDelete'] ?? null, 'count after delete');
	}

	public function testCreateReloadsByCustomIdColumn():void {
		[$code, $out, $err] = self::cli('code::runTests');
		$this->assertSame(0, $code, "code::runTests failed:\n$out$err");
		$r = json_decode(trim($out), true);
		$this->assertIsArray($r, 'No JSON from code::runTests: '.$out);
		$this->assertTrue($r['create'] ?? false, 'create returns the row reloaded by its custom PK');
		$this->assertTrue($r['reload'] ?? false, 'record() finds the row by the custom PK');
		$this->assertTrue($r['columns'] ?? false, 'objData exposes the bare record columns, which is what audit logs');
		$this->assertTrue($r['saveNew'] ?? false, 'objSave inserts and reloads the new record by its PK');
		$this->assertTrue($r['saveUpdate'] ?? false, 'objSave updates and reloads an existing record');
	}

	public function testRelationLoaderHandlesIncrementalParents():void {
		[$code, $out, $err] = self::cli('lst::runTests');
		$this->assertSame(0, $code, "lst::runTests failed:\n$out$err");
		$r = json_decode(trim($out), true);
		$this->assertIsArray($r, 'No JSON from lst::runTests: '.$out);
		$this->assertSame(1, $r['childrenA'] ?? null, 'the first parent loads its children');
		$this->assertSame(1, $r['childrenB'] ?? null, 'a parent loaded after the first still loads its children');
	}
}
