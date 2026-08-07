<?php
use PHPUnit\Framework\TestCase;

// The build lints what it writes, and php -l reports the generated file. Nobody edits that file,
// so the location is mapped back through the sourcemap of the same build: a lint failure has to
// name the .phlo line that produced it, like every other error in the loop does.
final class LintSourcemapTest extends TestCase {

	private static function build(string $source):string {
		phlo_test_wipe(php);
		phlo_test_wipe(www);
		$file = PHLO_TEST_TMP.'work/lintapp.phlo';
		file_put_contents($file, $source);
		try {
			new build_builder([
				'build' => [
					'routes' => true, 'buildCSS' => false, 'buildJS' => false,
					'minifyCSS' => false, 'minifyJS' => false, 'minifyPHP' => false,
					'comments' => false, 'extends' => 'obj', 'exclude' => [], 'trace' => false,
				],
				'sources'    => ['app' => [$file], 'resources' => []],
				'app_source' => $file,
			], true);
		}
		catch (PhloException $e){ return $e->getMessage(); }
		return void;
	}

	// A void return type on an arrow node compiles to a return in a void method, which PHP rejects.
	// The class body lands in lintapp.php, so the message must point at the .phlo node instead.
	public function testFatalInAClassBodyPointsAtThePhloNode():void {
		$message = self::build("@ summary: lint test app\n\nprop title = 'Lint'\n\nmethod stamp:void => time()\n");
		$this->assertStringContainsString('PHP lint failed', $message);
		$this->assertStringContainsString('lintapp.phlo:5', $message, "unmapped message:\n$message");
		$this->assertStringNotContainsString('lintapp.php on line', $message);
	}

	// Functions live in the shared functions.php, whose map carries a source per row: a broken
	// function must still resolve to the file that declared it, not to the generated bundle.
	public function testFatalInAFunctionPointsAtThePhloFile():void {
		$message = self::build("@ summary: lint test app\n\nprop title = 'Lint'\n\nfunction stamped:void => time()\n");
		$this->assertStringContainsString('PHP lint failed', $message);
		$this->assertStringContainsString('lintapp.phlo:', $message, "unmapped message:\n$message");
	}

	public function testACleanBuildStillPasses():void {
		$this->assertSame(void, self::build("@ summary: lint test app\n\nprop title = 'Lint'\n\nmethod stamp:int => time()\n"));
	}
}
