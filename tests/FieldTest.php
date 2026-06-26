<?php
use PHPUnit\Framework\TestCase;

// Covers the ORM field types without a database: a fixture instantiates fields directly and
// returns the result of validating a value (the shared required/length/pattern/enum rules live
// on the base field) and of rendering a value per type (checkbox, select, mailto, number input,
// masked password, formatted date).
final class FieldTest extends TestCase {

	private static string $entry = __DIR__.'/fixtures/fields/www/app.php';

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

	public function testBaseValidationRules():void {
		$r = self::fetch('fieldprobe::validation');
		$this->assertStringContainsString('required', (string)$r['reqEmpty'], 'a missing required value is rejected');
		$this->assertNull($r['reqOk'], 'a present required value passes');
		$this->assertStringContainsString('too long', (string)$r['tooLong'], 'a value over max length is rejected');
		$this->assertNull($r['lenOk']);
		$this->assertStringContainsString('format', (string)$r['patBad'], 'a value that fails the pattern is rejected');
		$this->assertNull($r['patOk']);
		$this->assertStringContainsString('must be one of', (string)$r['enumBad'], 'a value outside the enum is rejected');
		$this->assertNull($r['enumOk']);
		$this->assertNull($r['optionalNull'], 'an absent optional value is fine');
	}

	public function testPerTypeRendering():void {
		$r = self::fetch('fieldprobe::rendering');
		$this->assertSame('✅', $r['boolOn'], 'bool renders its true glyph');
		$this->assertSame('❌', $r['boolOff'], 'bool renders its false glyph');
		$this->assertStringContainsString('checkbox', (string)$r['boolInput'], 'bool input is a checkbox');
		$this->assertStringContainsString('selected', (string)$r['selectInput'], 'select marks the current option');
		$this->assertStringContainsString('mailto:a@b.com', (string)$r['emailLabel'], 'email label is a mailto link');
		$this->assertStringContainsString('type="number"', (string)$r['numberInput'], 'number input is a number field');
		$this->assertStringContainsString('••••••••', (string)$r['passwordLabel'], 'password label is masked');
		$this->assertStringContainsString('2025', (string)$r['dateLabel'], 'date label is formatted and keeps the year');
	}
}
