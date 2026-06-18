<?php
use PHPUnit\Framework\TestCase;

// Verifies the per-resource asset-NS override (app.json `resourceNS`, path-keyed). The
// cobalt theme declares `<style ns=theme.cobalt>`, so by default its CSS would bundle to
// www/theme.cobalt.css. With `resourceNS: {"themes/cobalt": "app"}` it must instead land
// in www/app.css and no theme.cobalt.css may be written.
final class NsTest extends TestCase {

	private static string $appDir = __DIR__.'/fixtures/ns/';
	private static string $entry  = __DIR__.'/fixtures/ns/www/app.php';

	private static function cli(string ...$args):array {
		$proc = proc_open([PHP_BINARY, self::$entry, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	public static function setUpBeforeClass():void {
		foreach (glob(self::$appDir.'www/*.css') ?: [] as $f) @unlink($f);
	}

	public function testResourceNsOverride():void {
		[$code, $out, $err] = self::cli('build::run');
		$this->assertSame(0, $code, "build::run failed:\n$out$err");
		$appCss = self::$appDir.'www/app.css';
		$this->assertFileExists($appCss, 'www/app.css was not written');
		$this->assertStringContainsString('#0f172a', (string)file_get_contents($appCss), 'cobalt CSS did not land in app.css');
		$this->assertFileDoesNotExist(self::$appDir.'www/theme.cobalt.css', 'theme.cobalt.css must not exist when overridden to the app namespace');
	}
}
