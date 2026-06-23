<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

// Exercises install.php in both invocation modes: from the engine with a
// target argument (original stays), and as a copy inside the app directory
// (deletes itself after success).
final class InstallTest extends TestCase {

	private static function runInstaller(string $script, array $answers, array $args = [], ?string $cwd = null):array {
		// PHLO_ENGINE pins the engine location so the run is deterministic; when the
		// installer is a copy outside the engine its dirname can't locate it, and in CI
		// none of the /srv fallbacks exist. A real user types the path at the prompt.
		$env  = ['PHLO_ENGINE' => rtrim(engine, slash)] + getenv();
		$proc = proc_open(
			[PHP_BINARY, $script, ...$args],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			$cwd,
			$env
		);
		fwrite($pipes[0], implode("\n", $answers)."\n");
		fclose($pipes[0]);
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	private static function wipe(string $dir):void {
		if (!is_dir($dir)) return;
		$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($rii as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		rmdir($dir);
	}

	public function testScaffoldBuildsCleanAppWithResolvedResources():void {
		$target = PHLO_TEST_TMP.'install-app';
		self::wipe($target);
		[$code, $out, $err] = self::runInstaller(engine.'install.php', ['Demo', 'demo.test', 'Install test app', 'notify', 'y'], [$target]);
		$this->assertSame(0, $code, $out.$err);
		$this->assertStringContainsString('Build clean.', $out);
		foreach (['app.phlo', 'www/app.php', 'data/app.json', 'data/app.md', '.gitignore', 'php/app.php', 'www/app.css', 'www/app.js'] as $file){
			$this->assertFileExists("$target/$file");
		}
		$config = json_decode((string)file_get_contents("$target/data/app.json"), true);
		$this->assertContains('notify', $config['resources']);
		$this->assertContains('HTTP', $config['resources'], 'The @ requires of notify must be resolved');
		$this->assertFileExists(engine.'install.php', 'Engine original must never delete itself');
		self::wipe($target);
	}

	public function testScaffoldResolvesSpaceSeparatedRequires():void {
		$target = PHLO_TEST_TMP.'install-multi';
		self::wipe($target);
		// lang declares `@ requires: @cookies @AI @INI phlo.async`: space-separated and
		// @-prefixed. Every dependency must resolve, not survive as one unsplit token.
		[$code, $out, $err] = self::runInstaller(engine.'install.php', ['Demo', 'demo.test', 'Multi-dep app', 'lang', 'y'], [$target]);
		$this->assertSame(0, $code, $out.$err);
		$this->assertStringContainsString('Build clean.', $out);
		$config = json_decode((string)file_get_contents("$target/data/app.json"), true);
		foreach (['lang', 'cookies', 'AI/AI', 'files/INI', 'phlo.async'] as $res) {
			$this->assertContains($res, $config['resources'], "lang dependency $res must resolve");
		}
		self::wipe($target);
	}

	public function testScaffoldDisambiguatesCollidingBasenameRequires():void {
		$target = PHLO_TEST_TMP.'install-collide';
		self::wipe($target);
		// CSRF requires `token`, a basename shared by security/token (function) and
		// fields/token (class). It must resolve to the function-providing security/token,
		// not be dropped as ambiguous.
		[$code, $out, $err] = self::runInstaller(engine.'install.php', ['Demo', 'demo.test', 'CSRF app', 'security/CSRF', 'y'], [$target]);
		$this->assertSame(0, $code, $out.$err);
		$config = json_decode((string)file_get_contents("$target/data/app.json"), true);
		$this->assertContains('security/token', $config['resources'], 'ambiguous `token` must resolve to security/token');
		self::wipe($target);
	}

	public function testScaffoldResolvesDottedNameRequires():void {
		$target = PHLO_TEST_TMP.'install-dotted';
		self::wipe($target);
		// age.human requires `time_human`, the compiled name of the dotted resource
		// time.human; the resolver must map the underscore name back to the dotted file.
		[$code, $out, $err] = self::runInstaller(engine.'install.php', ['Demo', 'demo.test', 'Dotted dep app', 'age.human', 'y'], [$target]);
		$this->assertSame(0, $code, $out.$err);
		$config = json_decode((string)file_get_contents("$target/data/app.json"), true);
		$this->assertContains('time.human', $config['resources'], 'age.human @time_human must resolve to time.human');
		self::wipe($target);
	}

	public static function parityResources():array {
		// Each exercises a different resolver path the installer and reflect must agree on:
		// a basename collision (security/token), space-separated @requires, a dotted
		// compiled name (time_human -> time.human), and pure transitive resolution.
		return [
			'basename collision'       => ['security/CSRF'],
			'space-separated requires' => ['lang'],
			'dotted compiled name'     => ['age.human'],
			'transitive only'          => ['payload'],
			'deep dependency chain'    => ['security/social'],
			'no dependencies'          => ['files/UBL'],
		];
	}

	#[DataProvider('parityResources')]
	public function testReflectAndInstallerAgreeOnDependencies(string $resource):void {
		$target = PHLO_TEST_TMP.'install-parity';
		self::wipe($target);
		// The installer and reflect resolve resource names with separate code; they must
		// agree. Scaffold the resource, then ask reflect for the same resource's deps and
		// require the same set.
		[$code, $out, $err] = self::runInstaller(engine.'install.php', ['Demo', 'demo.test', 'Parity app', $resource, 'y'], [$target]);
		$this->assertSame(0, $code, $out.$err);
		$config    = json_decode((string)file_get_contents("$target/data/app.json"), true);
		$installer = array_values(array_diff($config['resources'], [$resource]));
		sort($installer);

		$proc    = proc_open([PHP_BINARY, "$target/www/app.php", 'reflect::resourceDependencies', $resource], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$reflect = json_decode(trim((string)stream_get_contents($pipes[1])), true) ?? [];
		proc_close($proc);
		sort($reflect);

		$this->assertSame($installer, $reflect, "installer and reflect must agree for $resource");
		self::wipe($target);
	}

	public function testCopiedInstallerRemovesItselfAfterSuccess():void {
		$target = PHLO_TEST_TMP.'install-copy';
		self::wipe($target);
		mkdir($target, 0775, true);
		copy(engine.'install.php', "$target/install.php");
		[$code, $out, $err] = self::runInstaller("$target/install.php", ['', '', '', '', 'y'], [], $target);
		$this->assertSame(0, $code, $out.$err);
		$this->assertStringContainsString('removed itself', $out);
		$this->assertFileDoesNotExist("$target/install.php");
		$this->assertFileExists("$target/app.phlo");
		self::wipe($target);
	}

	public function testRefusesToOverwriteExistingApp():void {
		$target = PHLO_TEST_TMP.'install-existing';
		self::wipe($target);
		mkdir($target, 0775, true);
		file_put_contents("$target/app.phlo", "prop title = 'X'\n");
		[$code, , $err] = self::runInstaller(engine.'install.php', [], [$target]);
		$this->assertNotSame(0, $code);
		$this->assertStringContainsString('refusing', $err);
		self::wipe($target);
	}
}
