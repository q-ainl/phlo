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
		$this->assertTrue($r['cacheFresh'] ?? false, 'record cache reflects the update, not a stale copy');
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

	public function testCatalogHasNoUnresolvedRequires():void {
		[$code, $out, $err] = self::cli('reflect::catalogGaps');
		$this->assertSame(0, $code, $err);
		$this->assertSame([], json_decode(trim($out), true), 'every @requires must resolve to a resource, function, constant or class; gaps: '.$out);
	}

	public function testAuditedChangeBindsWherePlaceholders():void {
		[$code, $out, $err] = self::cli('audited::runTests');
		$this->assertSame(0, $code, "an audited change must bind its WHERE placeholders for the pre-update SELECT:\n$out$err");
		$r = json_decode(trim($out), true);
		$this->assertSame(1, $r['logged'] ?? null, 'the bound WHERE must match the row so the update is audited; '.$out);
		$this->assertTrue($r['amountChanged'] ?? false, 'the audit must capture the amount change; '.$out);
	}

	public function testAuditFailureRollsBackTheChange():void {
		// Under objAudit the mutation and the audit insert run in one transaction, so an audit
		// failure (here: no audit_log table) rolls the change back instead of leaving the row
		// changed but unaudited.
		[$code, $out, $err] = self::cli('audited::runRollback');
		$this->assertSame(0, $code, $err);
		$r = json_decode(trim($out), true);
		$this->assertTrue($r['threw'] ?? false, 'the audit failure must propagate; '.$out);
		$this->assertSame(1, $r['amount'] ?? null, 'the change must roll back when its audit insert fails; '.$out);
	}

	public function testNestedAuditFailureRollsBackWithinOuterTransaction():void {
		// Inside an outer transaction the audited change runs on a savepoint, so a failing
		// audit undoes only its own update even though the caller commits the outer transaction.
		[$code, $out, $err] = self::cli('audited::runNestedRollback');
		$this->assertSame(0, $code, $err);
		$r = json_decode(trim($out), true);
		$this->assertTrue($r['threw'] ?? false, $out);
		$this->assertSame(1, $r['amount'] ?? null, 'a nested audit failure must roll back its update via savepoint; '.$out);
	}

	public function testObjSaveCreateIsAudited():void {
		// objSave() on a new record must audit the create like static::create() does; updates
		// via objSave() already audit because they route through change().
		[$code, $out, $err] = self::cli('audited::runSaveCreate');
		$this->assertSame(0, $code, $err);
		$this->assertSame(1, json_decode(trim($out), true)['logged'] ?? null, 'objSave() of a new audited record must log a create; '.$out);
	}

	public function testAwaitCliFallbackCollectsResults():void {
		// The non-daemon await drains each child's stdout and stderr concurrently; spawn two
		// sibling targets and confirm both JSON results come back, in order.
		[$code, $out, $err] = self::cli('awaiter::run');
		$this->assertSame(0, $code, $err);
		$this->assertSame([['echo' => 'a'], ['echo' => 'b']], json_decode(trim($out), true), $out);
	}

	public function testHeldReferenceGetsRelationAfterRefetch():void {
		// A reference held across a re-fetch of the same PK is orphaned from the record cache;
		// relation loading mirrors the relation onto the held object too (objMirror), so it
		// still sees its children instead of an empty list.
		[$code, $out, $err] = self::cli('lst::runHeldRef');
		$this->assertSame(0, $code, $err);
		$this->assertSame(1, json_decode(trim($out), true)['heldChildren'] ?? null, 'a held reference should still see its relation after a re-fetch');
	}

	public function testObjInQuotesIdsThroughTheExecutingDriver():void {
		// JSONDB has no PDO, so objIn must quote IN-lists through the driver's quoteList,
		// not PDO::quote directly (or every JSONDB relation load fatals). And a relation
		// query runs on the related model's DB, so objIn quotes through the DB passed to it
		// (here SQLite) rather than the source model's own driver.
		[$code, $out, $err] = self::cli('jdoc::runTests');
		$this->assertSame(0, $code, "objIn must not assume a PDO driver:\n$out$err");
		$r = json_decode(trim($out), true);
		$this->assertSame('"1","2"', $r['objIn'] ?? null, 'JSONDB quoteList is literal-quoted, no PDO: '.$out);
		$this->assertSame("'1','2'", $r['objInDb'] ?? null, 'objIn quotes through the executing DB passed in (SQLite), not the model own driver: '.$out);
	}
}
