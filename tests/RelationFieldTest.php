<?php
use PHPUnit\Framework\TestCase;

// Pure-engine tests for the relation fields and model hooks (no CMS): foreign-key/idColumn
// resolution and ownership (child + many/pivot, including a string PK), the auto-generating
// parsers (token / created timestamp), the sync filter (keeps a '0' PK, drops empty), and the
// per-model permission hooks the CMS routes gate on.
final class RelationFieldTest extends TestCase {

	private static string $entry = __DIR__.'/fixtures/relations/www/app.php';

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
		[$code, $out, $err] = self::cli('build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	public function testAutoGeneratingParsersRun():void {
		$r = self::fetch('relprobe::parseCases');
		$this->assertTrue($r['tokenHandle'], 'a token field is handle (auto-managed)');
		$this->assertTrue($r['tokenGenerated'], 'a non-writable token still auto-generates via parse()');
		$this->assertTrue($r['datetimeHandle'], 'a datetime field is handle');
		$this->assertTrue($r['createdGenerated'], "a non-writable 'created' timestamp is still set by parse()");
	}

	public function testModelAuthzHooksGate():void {
		$r = self::fetch('relprobe::authzHooks');
		$this->assertTrue($r['thingView']);
		$this->assertFalse($r['lockedView'], 'a model can deny view');
		$this->assertTrue($r['thingChange']);
		$this->assertFalse($r['lockedChange'], 'a model can deny change');
		$this->assertTrue($r['thingDelete']);
		$this->assertFalse($r['lockedDelete'], 'a model can deny delete');
	}

	public function testChildOwnership():void {
		$r = self::fetch('relprobe::childOwnsCases');
		$this->assertSame('thing', $r['key'], 'the child field resolves its foreign-key column');
		$this->assertTrue($r['ownsCorrect'], 'a child of the parent is owned');
		$this->assertFalse($r['ownsWrong'], 'a child of another parent is rejected');
	}

	public function testManyOwnershipViaPivot():void {
		$r = self::fetch('relprobe::manyOwnsCases');
		$this->assertTrue($r['ownsCorrect'], 'a record linked in the pivot is owned (string PK)');
		$this->assertFalse($r['ownsWrong'], 'a record not linked to that parent is rejected');
		$this->assertTrue($r['linkHasPk'], 'the relation link href uses the string primary key');
	}

	public function testSyncKeepsZeroStringPrimaryKey():void {
		$r = self::fetch('relprobe::syncCases');
		$this->assertTrue($r['keptZero'], "a string primary key '0' survives the sync filter");
		$this->assertTrue($r['droppedEmpty'], 'an empty submitted value is dropped');
	}
}
