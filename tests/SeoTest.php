<?php
use PHPUnit\Framework\TestCase;

// robots.txt is gated on the site-wide `indexable` constant; head() owns the full SEO head
// (description, og:*, canonical) gated on a per-page noIndex flag, with the twitter card opt-in.
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

	public function testSitemapWithoutPagesOrLangs():void {
		// A single-page site declares neither: the sitemap must still list its root and skip the
		// hreflang alternates, instead of raising on a null foreach behind the URL robots advertises.
		$src = 'phlo(\'app\')->pages = null'."\n".'phlo(\'app\')->langs = null'."\n".'return [phlo(\'seo\')->sitemapPages, phlo(\'seo\')->sitemapLangs]';
		[$code, $out, $err] = self::cli('app-indexed.php', 'phlo_eval', $src);
		$this->assertSame(0, $code, $err);
		$r = json_decode(trim($out), true);
		$this->assertSame([''], $r[0] ?? null, "an app without pages still lists its root: $out");
		$this->assertSame([], $r[1] ?? null, "an app without langs gets no hreflang alternates: $out");
	}

	public function testHeadShape():void {
		[$code, $out, $err] = self::cli('app.php', 'seo.head');
		$this->assertSame(0, $code, $err);
		$h = json_decode(trim($out), true);
		$this->assertIsString($h, 'head output not a string: '.$out);
		$this->assertStringContainsString('name="description"', $h);
		$this->assertStringContainsString('A test description', $h);
		$this->assertStringContainsString('og:site_name', $h);
		$this->assertStringContainsString('SEO', $h);
		$this->assertStringContainsString('og:title', $h);
		$this->assertStringContainsString('og:description', $h);
		$this->assertStringContainsString('og:type', $h);
		$this->assertStringContainsString('website', $h);
		$this->assertStringContainsString('og:url', $h);
		$this->assertStringContainsString('og:image', $h);
		$this->assertStringContainsString('og:locale', $h);
		$this->assertStringContainsString('en_US', $h);
		$this->assertStringContainsString('canonical', $h);
		$this->assertStringNotContainsString('twitter', $h);
		$this->assertStringNotContainsString('noindex', $h);
	}
}
