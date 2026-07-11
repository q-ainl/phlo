<?php
use PHPUnit\Framework\TestCase;

// The stream resource over real HTTP: raw body and headers, the async-route contract
// (an `route async` target only matches when X-Requested-With: phlo is sent, which
// app.stream() adds via its third argument), and SSE passthrough with CRLF framing.
final class StreamHttpTest extends TestCase {

	private static $server;
	private static int $port = 0;
	private static string $entry = __DIR__.'/fixtures/output/www/app.php';

	private static function fetch(string $path, array $headers = []):array {
		$context = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'ignore_errors' => true]]);
		$body = (string)file_get_contents('http://127.0.0.1:'.self::$port.$path, false, $context);
		return [$body, $http_response_header ?? []];
	}

	private static function header(array $headers, string $name):?string {
		foreach ($headers as $h) if (stripos($h, $name.':') === 0) return trim(substr($h, strlen($name) + 1));
		return null;
	}

	public static function setUpBeforeClass():void {
		$proc = proc_open([PHP_BINARY, self::$entry, 'build::run'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		self::assertSame(0, proc_close($proc), "build::run failed:\n$out$err");
		self::$port = 8890 + (getmypid() % 900);
		self::$server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:'.self::$port, self::$entry],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes
		);
		for ($i = 0; $i < 50; $i++){
			$s = @fsockopen('127.0.0.1', self::$port, $e, $m, 0.2);
			if ($s){ fclose($s); break; }
			usleep(100_000);
		}
	}

	public static function tearDownAfterClass():void {
		if (is_resource(self::$server)) proc_terminate(self::$server);
	}

	public function testStreamBodyAndHeaders():void {
		[$body, $headers] = self::fetch('/streamed');
		$this->assertSame('hello world', $body);
		$this->assertStringStartsWith('text/plain', (string)self::header($headers, 'Content-Type'));
		$this->assertSame('no-store', self::header($headers, 'Cache-Control'));
		$this->assertSame('nosniff', self::header($headers, 'X-Content-Type-Options'));
		$this->assertSame('no', self::header($headers, 'X-Accel-Buffering'));
	}

	public function testAsyncRouteNeedsTheAsyncHeader():void {
		[$body] = self::fetch('/streamedasync');
		$this->assertStringNotContainsString('hello world', $body, 'an async route must not match a plain request');
		[$body] = self::fetch('/streamedasync', ['X-Requested-With: phlo']);
		$this->assertSame('hello world', $body, 'the async header makes the async route reachable');
	}

	public function testSsePassesCrlfFramesVerbatim():void {
		[$body, $headers] = self::fetch('/streamsse');
		$this->assertStringStartsWith('text/event-stream', (string)self::header($headers, 'Content-Type'));
		$this->assertSame("data: alpha\r\n\r\nevent: tick\r\ndata: beta\r\n\r\n", $body, 'the server never rewrites the byte stream');
	}
}
