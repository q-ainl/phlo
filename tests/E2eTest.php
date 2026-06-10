<?php
use PHPUnit\Framework\TestCase;

// End-to-end smoke: builds the fixture app in tests/fixtures/e2e/ through its
// own CLI entry point (separate process, real constants), then serves it with
// PHP's built-in server and checks one sync (HTML) and one async (apply JSON)
// request.
final class E2eTest extends TestCase {

	private static string $appDir = __DIR__.'/fixtures/e2e/';
	private static string $entry  = __DIR__.'/fixtures/e2e/www/app.php';

	private static function cli(string ...$args):array {
		$proc = proc_open([PHP_BINARY, self::$entry, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	private static function get(string $url, array $headers = []):string {
		$context = stream_context_create(['http' => [
			'header'        => implode("\r\n", $headers),
			'timeout'       => 5,
			'ignore_errors' => true,
		]]);
		return (string)file_get_contents($url, false, $context);
	}

	public function testCliBuildAndLint():void {
		[$code, $out, $err] = self::cli('build::run');
		$this->assertSame(0, $code, "build::run failed:\n$out$err");
		[$code, $out, $err] = self::cli('build::lint');
		$this->assertSame(0, $code, "build::lint failed:\n$out$err");
		$this->assertSame('[]', trim($out), 'Lint errors: '.$out);
		$this->assertFileExists(self::$appDir.'php/app.php');
		$this->assertFileExists(self::$appDir.'www/app.js');
	}

	public function testCliObjectAccess():void {
		[$code, $out, $err] = self::cli('app.title');
		$this->assertSame(0, $code, $err);
		$this->assertSame('"E2E"', trim($out));
	}

	public function testHttpSyncAndAsync():void {
		$port = 8920 + (getmypid() % 1000);
		$server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:'.$port, self::$entry],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
			self::$appDir.'www'
		);
		$this->assertIsResource($server);
		try {
			$up = false;
			for ($i = 0; $i < 50 && !$up; ++$i){
				usleep(100_000);
				$sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
				if ($sock){ fclose($sock); $up = true; }
			}
			$this->assertTrue($up, 'php -S did not come up on port '.$port);

			$html = self::get("http://127.0.0.1:$port/");
			$this->assertStringContainsString('<title>Home - E2E</title>', $html);
			$this->assertStringContainsString('<h1>E2E</h1>', $html);
			$this->assertStringContainsString('<p id="status">up</p>', $html);

			$json = self::get("http://127.0.0.1:$port/ping", ['X-Requested-With: phlo']);
			$data = json_decode($json, true);
			$this->assertIsArray($data, 'No JSON from async ping: '.$json);
			$this->assertTrue($data['pong'] ?? null);
		}
		finally {
			proc_terminate($server);
			proc_close($server);
		}
	}
}
