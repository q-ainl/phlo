<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

// Compiles each fixture app under tests/fixtures/apps/<case>/src/ with the real
// build pipeline and compares the generated PHP/CSS/JS against the committed
// snapshots in <case>/expected/. Regenerate after an intended compiler change:
//   PHLO_UPDATE_GOLDENS=1 vendor/bin/phpunit --filter CompilerGolden
final class CompilerGoldenTest extends TestCase {

	public static function apps():array {
		$cases = [];
		foreach (glob(__DIR__.'/fixtures/apps/*', GLOB_ONLYDIR) ?: [] as $dir) $cases[basename($dir)] = [$dir];
		return $cases;
	}

	#[DataProvider('apps')]
	public function testFixtureCompilesToGolden(string $dir):void {
		phlo_test_wipe(php);
		phlo_test_wipe(www);
		$sources = glob($dir.'/src/*.phlo') ?: [];
		$this->assertNotEmpty($sources, 'Fixture has no .phlo sources: '.$dir);
		$meta      = is_file($dir.'/fixture.json') ? json_decode((string)file_get_contents($dir.'/fixture.json'), true) : [];
		$resources = glob($dir.'/rsrc/*.phlo') ?: [];
		foreach ($meta['engineResources'] ?? [] as $name) $resources[] = engine.'resources/'.$name.'.phlo';
		$builder = new build_builder([
			'build'      => self::config(),
			'sources'    => ['app' => $sources, 'resources' => $resources],
			'app_source' => $dir.'/src/app.phlo',
		], true);
		$this->assertNotEmpty($builder->written, 'Build wrote no files');
		$generated = [];
		foreach ([...(glob(php.'*.php') ?: []), ...(glob(www.'*.css') ?: []), ...(glob(www.'*.js') ?: [])] as $file){
			$generated[basename($file)] = self::normalize((string)file_get_contents($file), $dir);
		}
		ksort($generated);
		$expectedDir = $dir.'/expected/';
		if (getenv('PHLO_UPDATE_GOLDENS')){
			if (!is_dir($expectedDir)) mkdir($expectedDir, 0775, true);
			foreach (glob($expectedDir.'*') ?: [] as $old) unlink($old);
			foreach ($generated as $name => $content) file_put_contents($expectedDir.$name, $content);
			$this->assertNotEmpty($generated);
			return;
		}
		$expected = [];
		foreach (glob($expectedDir.'*') ?: [] as $file) $expected[basename($file)] = (string)file_get_contents($file);
		ksort($expected);
		$this->assertSame(array_keys($expected), array_keys($generated), 'Generated file set differs from expected/');
		foreach ($expected as $name => $content){
			$this->assertSame($content, $generated[$name], "Golden mismatch for $name: if the compiler change is intended, regenerate with PHLO_UPDATE_GOLDENS=1");
		}
	}

	private static function config():array {
		return [
			'routes'          => true,
			'buildCSS'        => true,
			'buildJS'         => true,
			'minifyCSS'       => false,
			'minifyJS'        => false,
			'minifyPHP'       => false,
			'_minifyExplicit' => ['minifyCSS' => true, 'minifyJS' => true, 'minifyPHP' => true],
			'phloJS'          => false,
			'phloNS'          => 'engine-off',
			'defaultNS'       => 'app',
			'iconNS'          => 'app',
			'comments'        => true,
			'extends'         => 'obj',
			'exclude'         => [],
			'trace'           => false,
		];
	}

	// Absolute fixture paths and the engine version would make goldens machine- and
	// version-dependent; replace both with stable placeholders.
	private static function normalize(string $content, string $dir):string {
		$content = str_replace([$dir.'/src/', $dir.'/rsrc/', php, engine], ['%SRC%/', '%RSRC%/', '%PHP%/', '%PHLO%/'], $content);
		return (string)preg_replace('/^(\/\/ phlo:\s*).+$/m', '$1%VERSION%', $content);
	}
}
