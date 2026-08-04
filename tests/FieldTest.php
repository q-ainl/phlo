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

	// A nullable amount has no number to format. Text and datetime answer that with a dash, and
	// the number field now does the same, so a column that is simply empty can be listed instead
	// of ending the request. Zero is a number and keeps its own rendering.
	public function testNumberLabelRendersAnEmptyValueAsADash():void {
		$r = self::fetch('fieldprobe::rendering');
		$this->assertSame('-', $r['numberLabelEmpty'], 'an empty number is a dash, not a formatting error');
		$this->assertSame('-', $r['priceLabelEmpty'], 'price inherits that from number');
		$this->assertSame('0', $r['numberLabelZero'], 'zero is a value and still renders as one');
		$this->assertSame('1.234,50', $r['numberLabel'], 'a number keeps its decimals and separators');
	}

	public function testPasswordParseSkipsAnAbsentPayloadKey():void {
		$r = self::fetch('fieldprobe::parsing');
		$this->assertSame('kept', $r['absentLeavesRecord'], 'saving a record without a password value leaves the stored hash alone');
	}

	// An emptied number input submits an empty string, which a numeric column refuses. The
	// absence of a number is the default, or zero; anything actually typed is left alone.
	public function testNumberParseTurnsAnEmptyInputIntoANumber():void {
		$r = self::fetch('fieldprobe::parsing');
		$this->assertSame(0, $r['emptyBecomesZero'], 'an emptied number field stores zero, not an empty string');
		$this->assertSame(0, $r['blankBecomesZero'], 'whitespace is empty too');
		$this->assertSame(2, $r['emptyTakesDefault'], 'a declared default is what empty means for that field');
		$this->assertSame('4.50', $r['priceKeepsValue'], 'a price the user typed is stored as typed');
		$this->assertSame('9', $r['numberKeepsValue'], 'so is a plain number');
		$this->assertSame(3, $r['absentIsUntouched'], 'a column the payload never mentioned is not ours to rewrite');
	}
}
