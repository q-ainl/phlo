<?php
use PHPUnit\Framework\TestCase;

// Builds a fixture with one unique marker token a few lines into every node type, then checks
// that phlo_error_sourcemap() maps each marker's COMPILED line back to its exact SOURCE line.
// This catches per-node-type off-by-one errors in the build's sourcemap (classes/builder.php) -
// e.g. a view body opens with a synthetic `$_ = [];` prelude that must not shift the mapping.
final class SourcemapTest extends TestCase {

	private static array $markers = [];

	public static function setUpBeforeClass():void {
		phlo_test_wipe(php);
		phlo_test_wipe(www);
		$src = __DIR__.'/fixtures/sourcemap/app.phlo';
		new build_builder([
			'build' => [
				'routes' => true, 'buildCSS' => false, 'buildJS' => false,
				'minifyCSS' => false, 'minifyJS' => false, 'minifyPHP' => false,
				'phloJS' => false, 'phloNS' => 'engine-off', 'defaultNS' => 'app',
				'iconNS' => 'app', 'comments' => true, 'extends' => 'obj',
				'exclude' => [], 'trace' => false,
			],
			'sources'    => ['app' => [$src], 'resources' => []],
			'app_source' => $src,
		], true);

		// Where each marker lives in the source...
		foreach (file($src) as $i => $line){
			if (preg_match('/MARK_\w+/', $line, $m)) self::$markers[$m[0]]['src'] = $i + 1;
		}
		// ...and where the compiler put it.
		foreach (glob(php.'*.php') ?: [] as $file){
			foreach (file($file) as $i => $line){
				if (preg_match('/MARK_\w+/', $line, $m) && isset(self::$markers[$m[0]])){
					self::$markers[$m[0]]['file'] = $file;
					self::$markers[$m[0]]['line'] = $i + 1;
				}
			}
		}
	}

	public function testEveryNodeTypeMapsToItsSourceLine():void {
		$this->assertNotEmpty(self::$markers, 'no markers found; did the fixture build?');
		foreach (self::$markers as $marker => $info){
			$this->assertArrayHasKey('file', $info, "$marker was not found in the compiled output");
			$mapped = phlo_error_sourcemap($info['file'], $info['line']);
			$this->assertNotNull($mapped, "$marker (".basename($info['file']).':'.$info['line'].") has no sourcemap entry");
			$this->assertSame($info['src'], $mapped['line'], "$marker: compiled ".basename($info['file']).':'.$info['line']." must map to source line {$info['src']}, got {$mapped['line']}");
		}
	}
}
