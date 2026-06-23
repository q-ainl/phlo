<?php
use PHPUnit\Framework\TestCase;

// Exercises the real HTTP() transport and the connector dispatch built on it
// against a loopback `php -S` server: status capture and the by-ref $response,
// response-header capture, 4xx/5xx returning the body without throwing, the
// cookie-jar toggle, and connector-layer retry. Skips gracefully if the
// loopback server cannot be started.
final class HttpTest extends TestCase {

	private static int $port = 0;
	private static $proc = null;
	private static bool $up = false;

	public static function setUpBeforeClass():void {
		phlo_test_wipe(php);
		phlo_test_wipe(www);
		$appSrc = PHLO_TEST_TMP.'work/httpapp.phlo';
		file_put_contents($appSrc, "@ summary: http test app\n\nprop title = 'Http'\n");
		new build_builder([
			'build' => [
				'routes' => true, 'buildCSS' => true, 'buildJS' => true,
				'minifyCSS' => false, 'minifyJS' => false, 'minifyPHP' => false,
				'_minifyExplicit' => ['minifyCSS' => true, 'minifyJS' => true, 'minifyPHP' => true],
				'phloJS' => false, 'phloNS' => 'engine-off', 'defaultNS' => 'app',
				'iconNS' => 'app', 'comments' => false, 'extends' => 'obj',
				'exclude' => [], 'trace' => false,
			],
			'sources'    => ['app' => [$appSrc], 'resources' => [engine.'resources/HTTP.phlo', engine.'resources/security/creds.phlo', engine.'resources/connectors/Connector.phlo']],
			'app_source' => $appSrc,
		], true);
		require_once php.'functions.php';
		require_once php.'creds.php';
		require_once php.'Connector.php';

		$router = PHLO_TEST_TMP.'work/http-router.php';
		file_put_contents($router, self::router());
		self::$port = self::freePort();
		self::$proc = @proc_open([PHP_BINARY, '-S', '127.0.0.1:'.self::$port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
		if (is_resource(self::$proc)){
			for ($i = 0; $i < 50; $i++){
				$conn = @fsockopen('127.0.0.1', self::$port, $e, $s, 0.1);
				if ($conn){ fclose($conn); self::$up = true; break; }
				usleep(100000);
			}
		}
	}

	public static function tearDownAfterClass():void {
		if (is_resource(self::$proc)){ proc_terminate(self::$proc); proc_close(self::$proc); }
	}

	protected function setUp():void {
		if (!self::$up) $this->markTestSkipped('loopback php -S server unavailable');
	}

	private function url(string $path):string { return 'http://127.0.0.1:'.self::$port.$path; }

	// Connector subclass declared at runtime (Connector is only loaded in setUpBeforeClass).
	private function conn():Connector {
		return new class([]) extends Connector {
			public function probe(string $method, string $url, mixed $json = null):obj { return $this->request($method, $url, json: $json); }
		};
	}

	public function testReturnsBodyWithStatusAndHeaders():void {
		$response = null;
		$body = HTTP($this->url('/json'), response: $response);
		$this->assertSame(1, json_decode($body)->id);
		$this->assertSame(200, $response->status);
		$this->assertTrue($response->ok);
		$this->assertSame('hi', $response->headers['x-test'] ?? null);
	}

	public function testErrorStatusReturnsBodyWithoutThrowing():void {
		$response = null;
		$body = HTTP($this->url('/err'), response: $response);
		$this->assertSame('boom', json_decode($body)->error);
		$this->assertSame(503, $response->status);
		$this->assertFalse($response->ok);
	}

	public function testCookiesOffByDefault():void {
		HTTP($this->url('/setcookie'));
		$body = HTTP($this->url('/readcookie'));
		$this->assertNull(json_decode($body)->sid);
	}

	public function testCookiesOnPersistsAcrossCalls():void {
		@unlink(data.'cookies.txt');
		HTTP($this->url('/setcookie'), cookies: true);
		$body = HTTP($this->url('/readcookie'), cookies: true);
		$this->assertSame('abc', json_decode($body)->sid);
	}

	public function testConnectorDispatchesThroughHttp():void {
		$res = $this->conn()->probe('GET', $this->url('/json'));
		$this->assertTrue($res->ok);
		$this->assertSame(200, $res->status);
		$this->assertSame(1, $res->data->id);
		$this->assertSame('hi', $res->headers['x-test'] ?? null);
	}

	public function testConnectorRetriesIdempotentUntilSuccess():void {
		HTTP($this->url('/reset'));
		$conn = $this->conn();
		$conn->retries = 5;
		$res = $conn->probe('GET', $this->url('/flaky'));
		$this->assertTrue($res->ok);
		$this->assertSame(3, $res->data->n);
	}

	public function testConnectorDoesNotRetryPost():void {
		HTTP($this->url('/reset'));
		$conn = $this->conn();
		$conn->retries = 5;
		$res = $conn->probe('POST', $this->url('/flaky'), ['x' => 1]);
		$this->assertFalse($res->ok);
		$this->assertSame(503, $res->status);
	}

	private static function freePort():int {
		$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		$name = stream_socket_get_name($sock, false);
		fclose($sock);
		return (int)substr($name, strrpos($name, ':') + 1);
	}

	private static function router():string {
		return <<<'PHP'
		<?php
		$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		$cf = sys_get_temp_dir().'/phlo-httptest-flaky.cnt';
		header('Content-Type: application/json');
		if ($path === '/json'){ header('X-Test: hi'); echo json_encode(['ok' => true, 'id' => 1]); exit; }
		if ($path === '/err'){ http_response_code(503); echo json_encode(['error' => 'boom']); exit; }
		if ($path === '/reset'){ @unlink($cf); echo json_encode(['reset' => true]); exit; }
		if ($path === '/setcookie'){ setcookie('sid', 'abc'); echo json_encode(['set' => true]); exit; }
		if ($path === '/readcookie'){ echo json_encode(['sid' => $_COOKIE['sid'] ?? null]); exit; }
		if ($path === '/flaky'){
			$n = (int)@file_get_contents($cf) + 1;
			file_put_contents($cf, (string)$n);
			if ($n < 3){ http_response_code(503); header('Retry-After: 0'); echo json_encode(['error' => 'again', 'n' => $n]); exit; }
			echo json_encode(['ok' => true, 'n' => $n]); exit;
		}
		http_response_code(404); echo json_encode(['error' => 'nf']);
		PHP;
	}
}
