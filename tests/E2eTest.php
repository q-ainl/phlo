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

	public function testCliPhloEval():void {
		// A single expression auto-returns, exactly like a => arrow body.
		[$code, $out, $err] = self::cli('phlo_eval', '1 + 2 * 3');
		$this->assertSame(0, $code, $err);
		$this->assertSame('7', trim($out));

		// Resource objects resolve: %app becomes phlo('app').
		[, $out] = self::cli('phlo_eval', '%app->title');
		$this->assertSame('"E2E"', trim($out));

		// An explicit return is honoured and never doubled.
		[, $out] = self::cli('phlo_eval', 'return 2 + 2');
		$this->assertSame('4', trim($out));

		// A multiline block requires its own return.
		[, $out] = self::cli('phlo_eval', "\$x = 21\nreturn \$x * 2");
		$this->assertSame('42', trim($out));

		// echo controls its own output, so it is not given a return.
		[, $out] = self::cli('phlo_eval', "echo 'hi'");
		$this->assertSame('hi', trim($out));
	}

	public function testCliReflectLoadStatus():void {
		// Regression: functionIndex/objectIndex joined load state on function/object name
		// but checked by resource path, so a dotted/nested resource like phlo.async
		// (function phlo_async) read as unloaded. A listed resource must read loaded, an
		// unlisted one not.
		[$code, $out, $err] = self::cli('reflect::functionIndex');
		$this->assertSame(0, $code, $err);
		$index = json_decode($out, true);
		$this->assertIsArray($index, "functionIndex JSON: $out");
		$this->assertArrayHasKey('phlo_async', $index, 'phlo.async resource should surface its function');
		$this->assertTrue($index['phlo_async']['loaded'] ?? null, 'phlo.async is listed in app.json, so phlo_async must read as loaded');
		$this->assertArrayHasKey('slug', $index, 'unlisted function-resources still appear in the index');
		$this->assertFalse($index['slug']['loaded'] ?? null, 'slug is not listed, so it must read as not loaded');
	}

	public function testCliResolvesAmbiguousBasenameDeps():void {
		// Regression: a basename shared by two resources (files/file vs fields/file,
		// security/token vs fields/token) nulled the alias, so a dependency declared by
		// short name (@file, @token) did not resolve. Class- and function-name resolution
		// must recover them.
		[$code, $out, $err] = self::cli('reflect::resourceDependencies', 'payload');
		$this->assertSame(0, $code, $err);
		$this->assertContains('files/file', json_decode($out, true) ?? [], "payload @file should resolve to files/file: $out");

		[, $out] = self::cli('reflect::resourceDependencies', 'security/CSRF');
		$this->assertContains('security/token', json_decode($out, true) ?? [], "CSRF @token should resolve to security/token: $out");
	}

	public function testCliSocialClaimVerification():void {
		// Social login verifies the id_token's issuer, audience (== client_id) and expiry
		// before trusting any claim (signature is skipped: the token is fetched
		// server-to-server over TLS). Good claims pass; expired, wrong-audience and
		// wrong-issuer tokens are rejected.
		$src = <<<'PHLO'
		$base = ["exp" => time() + 99, "aud" => "CID", "iss" => "https://accounts.google.com"]
		return [
			"ok"       => social::verifyClaims("google", ["client_id" => "CID"], $base),
			"expired"  => social::verifyClaims("google", ["client_id" => "CID"], ["exp" => time() - 1] + $base),
			"wrongAud" => social::verifyClaims("google", ["client_id" => "CID"], ["aud" => "OTHER"] + $base),
			"wrongIss" => social::verifyClaims("google", ["client_id" => "CID"], ["iss" => "https://evil.example"] + $base),
		]
		PHLO;
		[$code, $out, $err] = self::cli('phlo_eval', $src);
		$this->assertSame(0, $code, $err);
		$r = json_decode($out, true);
		$this->assertIsArray($r, "verifyClaims JSON: $out");
		$this->assertTrue($r['ok'] ?? null, 'valid claims must pass');
		$this->assertFalse($r['expired'] ?? null, 'expired token rejected');
		$this->assertFalse($r['wrongAud'] ?? null, 'wrong audience rejected');
		$this->assertFalse($r['wrongIss'] ?? null, 'wrong issuer rejected');
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

			// phlo_eval is CLI-only: even wired into a route it must refuse over HTTP and never emit its value.
			$evalBody = self::get("http://127.0.0.1:$port/eval");
			$this->assertStringNotContainsString('31337', $evalBody, 'phlo_eval executed over HTTP but must be CLI-only');
		}
		finally {
			proc_terminate($server);
			proc_close($server);
		}
	}
}
