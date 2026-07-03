<?php
use PHPUnit\Framework\TestCase;

// og:site_name/type/locale and the twitter card are emitted only when the app injects the
// matching %seo.* prop; this fixture injects all four to lock the opt-in path.
final class SeoOptInTest extends TestCase {

	private static function cli(string ...$args):array {
		$proc = proc_open([PHP_BINARY, __DIR__.'/fixtures/seorich/www/app.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	public static function setUpBeforeClass():void {
		[$code, $out, $err] = self::cli('build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	public function testInjectedOptInTags():void {
		[$code, $out, $err] = self::cli('seo.head');
		$this->assertSame(0, $code, $err);
		$h = json_decode(trim($out), true);
		$this->assertIsString($h, 'head output not a string: '.$out);
		$this->assertStringContainsString('og:site_name', $h);
		$this->assertStringContainsString('Rich Site', $h);
		$this->assertStringContainsString('og:type', $h);
		$this->assertStringContainsString('website', $h);
		$this->assertStringContainsString('og:locale', $h);
		$this->assertStringContainsString('en_US', $h);
		$this->assertStringContainsString('twitter:card', $h);
		$this->assertStringContainsString('summary_large_image', $h);
		$this->assertStringContainsString('og:title', $h);
		$this->assertStringContainsString('canonical', $h);
	}
}
