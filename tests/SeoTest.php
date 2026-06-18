<?php
use PHPUnit\Framework\TestCase;

// seo resource: the robots.txt route is gated on the optional site-wide `indexable`
// constant (Disallow without it, Allow+Sitemap with it). The head() view is gated on a
// per-page noindex/noLink flag (decoupled from indexable) and owns the full SEO head:
// meta description (moved out of view()), og:site_name/title/description/type/url/image/
// locale + canonical as uniform defaults, with the twitter card kept opt-in.
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

	public function testHeadShape():void {
		[$code, $out, $err] = self::cli('app.php', 'seo.head');
		$this->assertSame(0, $code, $err);
		$h = json_decode(trim($out), true);
		$this->assertIsString($h, 'head output not a string: '.$out);
		// seo owns the meta description (moved out of view())
		$this->assertStringContainsString('name="description"', $h);
		$this->assertStringContainsString('A test description', $h);
		// uniform defaults: og:site_name (= id), og:type=website, og:locale (derived from lang)
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
		// twitter card stays opt-in (default off)
		$this->assertStringNotContainsString('twitter', $h);
		// fixture has no noLink/noindex, so no robots meta
		$this->assertStringNotContainsString('noindex', $h);
	}
}
