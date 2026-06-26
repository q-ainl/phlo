<?php
use PHPUnit\Framework\TestCase;

// Covers the backend HTML tag-builders (tag/input/select). A probe renders a value per case and
// the test asserts the attribute handling: exact output for simple tags, bare boolean attributes,
// omitted null attributes, escaped attribute values, and underscore->dash key conversion. (The
// markdown and form resources are client-side <script> and are exercised in the browser, not here.)
final class DomTest extends TestCase {

	private static string $entry = __DIR__.'/fixtures/dom/www/app.php';

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

	public function testTagRendering():void {
		$r = self::fetch('domprobe::tagCases');
		$this->assertSame('<p>Hello</p>', $r['simple'], 'inner content is wrapped');
		$this->assertSame('<br>', $r['selfClose'], 'no inner means no closing tag');
		$this->assertSame('<a href="https://x.test">link</a>', $r['attr']);
		$this->assertStringContainsString(' checked>', $r['boolAttr'], 'a true attribute renders bare');
		$this->assertStringNotContainsString('value', $r['nullOmitted'], 'a null attribute is omitted');
		$this->assertStringContainsString('&lt;b&gt;', $r['escaped'], 'attribute values are escaped');
		$this->assertStringContainsString('&quot;', $r['escaped']);
		$this->assertStringContainsString('data-id="5"', $r['usToDash'], 'underscores in keys become dashes');
		$this->assertSame('<input type="email" name="mail">', $r['input'], 'input wraps tag');
		$this->assertStringContainsString('<select name="pick"', $r['select'], 'select wraps tag');
	}
}
