<?php
use PHPUnit\Framework\TestCase;

// functions.trace.php is a generated mirror of functions.php with a trace()
// call injected into every function (build::traceShadow). The two must stay in
// sync, but only functions.php is hand-edited, so the shadow drifts silently.
// This guard regenerates from the current functions.php via the real generator
// and fails if the committed shadow differs. It restores the file afterwards,
// so the working tree is never left modified.
final class TraceShadowTest extends TestCase {

	public static function setUpBeforeClass():void {
		require_once engine.'classes/changed.php';
		require_once engine.'classes/build.php';
	}

	public function testShadowMatchesCurrentFunctions():void {
		$shadow = engine.'functions.trace.php';
		$this->assertFileExists($shadow);
		$committed = (string)file_get_contents($shadow);
		try {
			build::traceShadow();
			$fresh = (string)file_get_contents($shadow);
		}
		finally {
			file_put_contents($shadow, $committed);
		}
		$this->assertSame($committed, $fresh, 'functions.trace.php is out of sync with functions.php; run: php www/app.php build::traceShadow');
	}
}
