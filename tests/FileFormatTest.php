<?php
use PHPUnit\Framework\TestCase;

// Covers the file-format resources without fixtures on disk: a probe writes temp files, reads
// them back through CSV/INI/JSON, and (for INI/JSON) writes and re-reads. Checks the CSV header
// mapping + delimiter auto-detection, the INI typed scanner + write-back, and the JSON read/write.
final class FileFormatTest extends TestCase {

	private static string $entry = __DIR__.'/fixtures/files/www/app.php';

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

	public function testCsvReadAndDelimiter():void {
		$r = self::fetch('fileprobe::csvCases');
		$this->assertSame(2, $r['count'], 'two data rows are parsed');
		$this->assertSame('Ann', $r['firstName'], 'the header row becomes the keys');
		$this->assertSame('25', $r['secondAge'], 'cells are read as strings');
		$this->assertSame('Cleo', $r['semiName'], 'the semicolon delimiter is auto-detected');
	}

	public function testIniReadAndWrite():void {
		$r = self::fetch('fileprobe::iniCases');
		$this->assertSame('Ann', $r['name']);
		$this->assertSame(30, $r['age'], 'the typed scanner returns an int');
		$this->assertTrue($r['active'], 'the typed scanner returns a bool');
		$this->assertTrue($r['wroteCity'], 'objWrite persists a newly set key');
	}

	public function testJsonReadAndWrite():void {
		$r = self::fetch('fileprobe::jsonCases');
		$this->assertSame('Ann', $r['name']);
		$this->assertSame(2, $r['tagCount'], 'a nested array is read');
		$this->assertTrue($r['wroteBob'], 'objWrite serializes the new data');
	}
}
