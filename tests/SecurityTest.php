<?php
use PHPUnit\Framework\TestCase;

// Covers the security primitives without external services: JWT sign/verify (HS256), sodium
// encryption round-trip + tamper detection, and CSRF token verification + rotation. A fixture
// drives each one and reports booleans the test asserts. (The rate limiter is left to the live
// fleet: its db backend needs MySQL and its apcu backend needs a web SAPI, neither available in
// a CLI unit test.)
final class SecurityTest extends TestCase {

	private static string $entry = __DIR__.'/fixtures/security/www/app.php';

	private static function cli(string ...$args):array {
		$proc = proc_open([PHP_BINARY, self::$entry, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	private static function fetch(string $target):array {
		[$code, $out, $err] = self::cli($target);
		self::assertSame(0, $code, "$target failed:\n$out$err");
		$r = json_decode(trim($out), true);
		self::assertIsArray($r, "no JSON from $target: $out");
		return $r;
	}

	public static function setUpBeforeClass():void {
		[$code, $out, $err] = self::cli('build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	public function testJwtSignAndVerify():void {
		$r = self::fetch('secprobe::jwtCases');
		$this->assertTrue($r['roundtrip'], 'a signed token verifies back to its claims');
		$this->assertTrue($r['hasExp'], 'sign adds an exp claim');
		$this->assertTrue($r['tampered'], 'a tampered token is rejected');
		$this->assertTrue($r['expired'], 'an expired token is rejected');
		$this->assertTrue($r['wrongSecret'], 'a token signed with another secret is rejected');
	}

	public function testEncryptionRoundTrip():void {
		$r = self::fetch('secprobe::encryptionCases');
		$this->assertTrue($r['roundtrip'], 'decrypt recovers the plaintext with the right key');
		$this->assertTrue($r['opaque'], 'the ciphertext is not the plaintext');
		$this->assertTrue($r['wrongKey'], 'the wrong key fails to decrypt');
		$this->assertTrue($r['tampered'], 'a tampered ciphertext fails authentication');
	}

	public function testCsrfTokenVerification():void {
		$r = self::fetch('secprobe::csrfCases');
		$this->assertSame(32, $r['len'], 'the session token is 32 chars');
		$this->assertTrue($r['valid'], 'a matching X-CSRF-Token header passes');
		$this->assertFalse($r['invalid'], 'a wrong header fails');
		$this->assertFalse($r['missing'], 'a missing header fails');
		$this->assertTrue($r['rotated'], 'update rotates to a new token');
	}
}
