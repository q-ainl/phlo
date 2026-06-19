<?php
use PHPUnit\Framework\TestCase;

// Engine-side coverage for the optional daemon. Network-free: it does not need a running daemon.
// It locks two invariants: (1) the daemon client is lazily loaded ONLY when the `daemon` constant
// is set (the phlo_app gate, mirroring changed.php), and (2) the runtime helpers fall back to a
// one-shot subprocess when no daemon is configured, so core Phlo works without it. The actual
// pool / register / websocket behaviour of the node daemon is covered by daemon/test/smoke.js.
final class DaemonTest extends TestCase {

	private static function cli(string $entry, string ...$args):array {
		$proc = proc_open([PHP_BINARY, __DIR__."/fixtures/daemonapp/www/$entry", ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	public static function setUpBeforeClass():void {
		[$code, $out, $err] = self::cli('app.php', 'build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	// The fixture loads phlo.sync, await and wsCast: this also proves they compile against the new
	// daemon-targeted wsCast and the bare `if (daemon)` gate in the helpers.
	public function testBuildLintClean():void {
		[$code, $out, $err] = self::cli('app.php', 'build::lint');
		$this->assertSame(0, $code, $err);
		$this->assertSame('[]', trim($out), 'lint not clean: '.$out);
	}

	public function testDaemonClientNotLoadedWithoutConstant():void {
		[$code, $out, $err] = self::cli('app.php', 'app.daemonLoaded');
		$this->assertSame(0, $code, $err);
		$this->assertStringContainsString('no', $out, 'daemon class must not load without the daemon constant: '.$out);
	}

	public function testDaemonClientLoadedWithConstant():void {
		[$code, $out, $err] = self::cli('withdaemon.php', 'app.daemonLoaded');
		$this->assertSame(0, $code, $err);
		$this->assertStringContainsString('yes', $out, 'daemon class must load when the daemon constant is set: '.$out);
	}

	public function testOneShotSyncWithoutDaemon():void {
		[$code, $out, $err] = self::cli('app.php', 'phlo_sync', 'app.ping', 'hi');
		$this->assertSame(0, $code, $err);
		$this->assertStringContainsString('pong:hi', $out, 'phlo_sync must fall back to a one-shot subprocess without a daemon: '.$out);
	}

	public function testOneShotParallelWithoutDaemon():void {
		[$code, $out, $err] = self::cli('app.php', 'await', 'app.ping');
		$this->assertSame(0, $code, $err);
		$this->assertStringContainsString('pong:', $out, 'await must fall back to one-shot subprocesses without a daemon: '.$out);
	}
}
