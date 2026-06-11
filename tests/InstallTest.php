<?php
use PHPUnit\Framework\TestCase;

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
