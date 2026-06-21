<?php
use PHPUnit\Framework\TestCase;

// Output composition with the streaming-aware done-guard: a stream opened by chunk()
// lets subsequent chunk()/apply() flush into it; a second BUFFERED output (no open
// stream) still throws "Output already started". Targets live in the output fixture.
final class OutputTest extends TestCase {

	private static string $entry = __DIR__.'/fixtures/output/www/app.php';

	private static function cli(string ...$args):array {
		$proc = proc_open([PHP_BINARY, self::$entry, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	private static function cliDebug(string ...$args):array {
		$proc = proc_open([PHP_BINARY, __DIR__.'/fixtures/output/www/app-debug.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	private static function lines(string $out):array {
		return array_values(array_filter(explode("\n", trim($out)), 'strlen'));
	}

	public static function setUpBeforeClass():void {
		[$code, $out, $err] = self::cli('build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	public function testChunkOnlyStreamsSingleLine():void {
		[$code, $out, $err] = self::cli('flow::chunkOnly');
		$this->assertSame(0, $code, $err);
		$this->assertSame(['{"a":1}'], self::lines($out));
	}

	public function testChunkThenApplyCloses():void {
		[$code, $out, $err] = self::cli('flow::chunkApply');
		$this->assertSame(0, $code, $err);
		$this->assertSame(['{"a":1}', '{"b":2}'], self::lines($out));
	}

	public function testChunkChunkApply():void {
		[$code, $out, $err] = self::cli('flow::chunkChunkApply');
		$this->assertSame(0, $code, $err);
		$this->assertSame(['{"a":1}', '{"b":2}', '{"c":3}'], self::lines($out));
	}

	public function testApplyAloneBuffers():void {
		[$code, $out, $err] = self::cli('flow::applyOnly');
		$this->assertSame(0, $code, $err);
		$this->assertSame(['{"a":1}'], self::lines($out));
	}

	public function testSecondBufferedApplyThrows():void {
		[$code, $out, $err] = self::cli('flow::applyApply');
		$this->assertNotSame(0, $code, "expected non-zero exit, out:\n$out");
		$this->assertStringContainsString('{"a":1}', $out, 'first output should be emitted');
		$this->assertStringNotContainsString('{"b":2}', $out, 'the blocked second output must not be emitted');
	}

	public function testChunkAfterBufferedApplyThrows():void {
		[$code, $out, $err] = self::cli('flow::applyChunk');
		$this->assertNotSame(0, $code, "expected non-zero exit, out:\n$out");
		$this->assertStringContainsString('{"a":1}', $out, 'first output should be emitted');
		$this->assertStringNotContainsString('{"b":2}', $out, 'the blocked second output must not be emitted');
	}

	public function testDoubleOutputMessageIsClearInDebug():void {
		// debug surfaces the guard message; non-debug generalises it to "Error" (no internal leak)
		[$code, $out, $err] = self::cliDebug('flow::applyChunk');
		$this->assertNotSame(0, $code);
		$this->assertStringContainsString('Output already started', $out.$err);
	}
}
