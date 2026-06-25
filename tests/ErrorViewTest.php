<?php
use PHPUnit\Framework\TestCase;

// The error view layer: the optional app::errorPage hook, its fail-safe fallback, and the
// short reference id shown on the production and dependency-free pages. The hook runs in a
// worker process so its stub `class app` never leaks into the in-process suite.
final class ErrorViewTest extends TestCase {

	private static ?array $r = null;

	private static function fragments():array {
		if (self::$r === null){
			$proc = proc_open([PHP_BINARY, __DIR__.'/workers/errorview.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			$out  = (string)stream_get_contents($pipes[1]);
			$err  = (string)stream_get_contents($pipes[2]);
			proc_close($proc);
			$r = json_decode(trim($out), true);
			self::assertIsArray($r, "errorview worker did not return JSON:\n$out$err");
			self::$r = $r;
		}
		return self::$r;
	}

	public function testAppErrorPageHookRendersCustomPage():void {
		$r = self::fragments();
		$this->assertSame(8, $r['idLen'], 'the reference stays a short 8-char id');
		$this->assertStringContainsString('class="custom"', (string)$r['hookOk'], 'a declared app::errorPage renders the response body');
		$this->assertStringContainsString($r['id'], (string)$r['hookOk'], 'the hook receives and can show the error id');
	}

	public function testThrowingOrEmptyHookFallsBackToEngine():void {
		$r = self::fragments();
		$this->assertNull($r['hookThrow'], 'a hook that throws must not surface; the engine page takes over');
		$this->assertNull($r['hookEmpty'], 'a hook that returns nothing falls back to the engine page');
	}

	public function testEngineAndBarePagesShowTheReference():void {
		$r = self::fragments();
		$this->assertStringContainsString('Reference', (string)$r['minimal'], 'the production page shows a reference line');
		$this->assertStringContainsString($r['id'], (string)$r['minimal'], 'the production page shows the id to quote');
		$this->assertStringContainsString($r['id'], (string)$r['bare'], 'even the dependency-free fallback shows the id');
	}
}
