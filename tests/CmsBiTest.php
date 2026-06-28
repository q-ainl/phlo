<?php
use PHPUnit\Framework\TestCase;

// Regression tests for the CMS BI feature, the highest-risk part of the dashboard. A fixture
// subclasses CMS_dashboard_bi to reach its protected buildQuery and drives it against a SQLite
// model: the query builder must bind every value as a parameter (defeating UNION/' OR '1'='1
// injection that the structured rewrite replaced raw SQL with) and reject any column or operator
// outside the model. Also checks that the model authz hooks (canView/canChange/canDelete), which
// the CMS list/record/BI routes gate on, can deny per model.
final class CmsBiTest extends TestCase {

	private static string $entry = __DIR__.'/fixtures/cms/www/app.php';

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
		// The fixture mounts the real CMS (a separate repo) from /srv/control/CMS; skip where it is
		// absent (e.g. a CI runner that only checked out the engine) instead of failing the build.
		if (!is_dir('/srv/control/CMS')) self::markTestSkipped('this CMS integration test needs the phlo-cms checkout at /srv/control/CMS');
		[$code, $out, $err] = self::cli('build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	public function testBiQueryResistsSqlInjection():void {
		$r = self::fetch('biprobe::sqliCases');
		$this->assertSame(1, $r['validRows'], 'a valid structured filter matches its row');
		$this->assertSame(0, $r['injectRows'], "a classic ' OR '1'='1 value is bound as data, matching nothing");
		$this->assertSame(0, $r['unionRows'], 'a UNION SELECT payload is bound as a value, never reaching the SQL string');
		$this->assertSame(2, $r['allRows'], 'no filters returns every row');
		$this->assertTrue($r['unknownColumn'], 'a column outside the model is rejected');
		$this->assertTrue($r['unknownOperator'], 'an operator outside the allowlist is rejected');
		$this->assertSame(2, $r['badOrderIgnored'], 'a malicious ORDER BY column is ignored, not injected');
		$this->assertSame([1, 2], $r['keys'], 'rows are keyed by their real primary key (FETCH_UNIQUE), not 0,1,2 - the CMS uses the array key as the record data-id');
		$this->assertSame(1, $r['firstId'], 'the primary key is selected as a throwaway _ so FETCH_UNIQUE keeps the real id on the record object, not consumed off table.*');
	}

	public function testAutoGeneratingParsersStillRun():void {
		$r = self::fetch('biprobe::parseCases');
		$this->assertTrue($r['tokenHandle'], 'a token field is handle (auto-managed)');
		$this->assertTrue($r['tokenGenerated'], 'a non-writable token still auto-generates via parse()');
		$this->assertTrue($r['datetimeHandle'], 'a datetime field is handle');
		$this->assertTrue($r['createdGenerated'], "a non-writable 'created' timestamp is still set by parse()");
	}

	public function testModelAuthzHooksGate():void {
		$r = self::fetch('biprobe::authzHooks');
		$this->assertTrue($r['thingView'], 'an open model is viewable');
		$this->assertFalse($r['lockedView'], 'a model can deny view; the CMS list/record/BI routes return early on a failed canView');
		$this->assertTrue($r['thingChange']);
		$this->assertFalse($r['lockedChange'], 'a model can deny change');
		$this->assertTrue($r['thingDelete']);
		$this->assertFalse($r['lockedDelete'], 'a model can deny delete');
	}

	public function testChildFieldOwnershipIsFieldAgnostic():void {
		$r = self::fetch('biprobe::childOwnsCases');
		$this->assertSame('thing', $r['key'], 'the child field resolves its own foreign-key column (default: the parent model name)');
		$this->assertTrue($r['ownsCorrect'], 'a child whose foreign key matches the parent is owned');
		$this->assertFalse($r['ownsWrong'], 'a child of another parent is rejected - the routes delegate this to the field, not inline it');
	}

	public function testManyFieldOwnershipViaPivot():void {
		$r = self::fetch('biprobe::manyOwnsCases');
		$this->assertTrue($r['ownsCorrect'], 'a record linked to the parent in the pivot table is owned');
		$this->assertFalse($r['ownsWrong'], 'a record not linked to that parent is rejected');
	}

	public function testInvalidBiStructureIsEvictedFromCache():void {
		$r = self::fetch('biprobe::cacheCases');
		$this->assertTrue($r['cachedBefore'], 'the structure is in the cache before the query runs');
		$this->assertTrue($r['evictedAfter'], 'a valid-model-but-bad-structure entry is dropped from the cache when buildQuery fails, so the deterministic token does not fail forever');
	}
}
