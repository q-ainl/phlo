<?php
use PHPUnit\Framework\TestCase;

// Worker-mode (phlo_serve) soak. The daemon runs each app as a long-lived worker (php-zts <app> phlo_serve)
// that boots once and answers newline-JSON requests, resetting state between them (phlo('tech/reset') +
// session_write_close + gc_collect_cycles). This drives that loop directly over many requests and asserts the
// three properties the worker must hold for its whole lifetime, on ZTS (the production SAPI) as much as on
// NTS: correct dispatch under load, state isolation between requests (no leaked objects - the class of bug
// the FrankenPHP session leak was), and survival (no stderr noise, clean exit). The ZTS CI job runs this
// suite inside the FrankenPHP image so the worker mode is exercised on the SAPI production actually uses.
final class ServeTest extends TestCase {

	private const REQUESTS = 500;
	private static string $entry = __DIR__.'/fixtures/serve/www/app.php';

	public static function setUpBeforeClass():void {
		$proc = proc_open([PHP_BINARY, self::$entry, 'build::run'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		self::assertSame(0, proc_close($proc), "build::run failed:\n$out$err");
	}

	public function testWorkerSoak():void {
		$proc = proc_open([PHP_BINARY, self::$entry, 'phlo_serve'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($proc, 'phlo_serve started');
		[$stdin, $stdout, $stderr] = $pipes;

		$ready = json_decode(trim((string)fgets($stdout)), true);
		$this->assertSame('ready', $ready['t'] ?? null, 'the worker announces itself ready before serving');

		for ($i = 0; $i < self::REQUESTS; $i++){
			fwrite($stdin, json_encode(['id' => "m$i", 'target' => 'app::mirror', 'args' => [$i]]).PHP_EOL);
			$mirror = json_decode(trim((string)fgets($stdout)), true);
			$this->assertSame("m$i", $mirror['id'] ?? null, "response $i lines up with its request");
			$this->assertSame('done', $mirror['t'] ?? null, "mirror $i dispatched without error");
			$this->assertSame([$i], $mirror['result'] ?? null, "mirror $i echoes its own args - correct dispatch under load");

			fwrite($stdin, json_encode(['id' => "p$i", 'target' => 'app::probe']).PHP_EOL);
			$probe = json_decode(trim((string)fgets($stdout)), true);
			$this->assertSame(['fresh', 'visited'], $probe['result'] ?? null, "request $i gets a clean resource object ('fresh'), not one leaked from the previous request - the worker reset isolates state between requests");
		}

		fclose($stdin);
		$this->assertSame('', stream_get_contents($stderr), 'the worker logs nothing to stderr across the whole soak');
		$this->assertSame(0, proc_close($proc), 'the worker exits cleanly when its input closes');
	}
}
