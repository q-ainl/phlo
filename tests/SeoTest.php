<?php
use PHPUnit\Framework\TestCase;

// Verifies the seo resource's robots.txt + head() logic gated on the optional `indexable`
// constant. Two entries point at the same app: app.php (no indexable) must yield a
// Disallow robots + a noindex head; app-indexed.php (indexable: true) must yield an
// Allow+Sitemap robots + an indexable head. Run via the CLI dispatcher.
final class SeoTest extends TestCase {

	private static function cli(string $entry, string ...$args):array {
		$proc = proc_open([PHP_BINARY, __DIR__.'/fixtures/seo/www/'.$entry, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	public static function setUpBeforeClass():void {
		[$code, $out, $err] = self::cli('app.php', 'build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	public function testRobotsDisallowWithoutIndexable():void {
		[$code, $out, $err] = self::cli('app.php', 'seo.robots');
		$this->assertSame(0, $code, $err);
		$r = json_decode(trim($out), true);
		$this->assertIsString($r, 'robots output not a string: '.$out);
		$this->assertStringContainsString('Disallow: /', $r);
		$this->assertStringNotContainsString('Allow: /', $r);
	}

	public function testRobotsAllowWithIndexable():void {
		[$code, $out, $err] = self::cli('app-indexed.php', 'seo.robots');
		$this->assertSame(0, $code, $err);
		$r = (string)json_decode(trim($out), true);
		$this->assertStringContainsString('Allow: /', $r);
		$this->assertStringContainsString('Sitemap:', $r);
	}

	public function testHeadNoindexWithoutIndexable():void {
		[$code, $out, $err] = self::cli('app.php', 'seo.head');
		$this->assertSame(0, $code, $err);
		$h = json_decode(trim($out), true);
		$this->assertIsString($h, 'head output not a string: '.$out);
		$this->assertStringContainsString('noindex', $h);
		$this->assertStringContainsString('og:title', $h);
		$this->assertStringContainsString('SEO Fixture', $h);
		$this->assertStringContainsString('twitter:card', $h);
	}

	public function testHeadIndexedHasNoNoindex():void {
		[$code, $out, $err] = self::cli('app-indexed.php', 'seo.head');
		$this->assertSame(0, $code, $err);
		$h = (string)json_decode(trim($out), true);
		$this->assertStringNotContainsString('noindex', $h);
		$this->assertStringContainsString('og:title', $h);
	}
}
