<?php
use PHPUnit\Framework\TestCase;

// The translation layer collects missing phrases and hands them over in batches. Before this, every
// nl() call with a missing phrase asked for its own background job, so one page could queue hundreds
// of them, each an AI call for a single line; a daemon whose queue is full refuses the rest and the
// phrase goes missing with them. These tests cover the collecting, both caps, the tail that leaves
// when the instance dies, and the promise that a refused job never breaks the page it was rendering.
// No network, no daemon and no process: the one method that reaches the background is replaced.
final class LangBatchTest extends TestCase {

	public static function setUpBeforeClass():void {
		if (!defined('langs')) define('langs', PHLO_TEST_TMP.'build/langs/');
		if (!is_dir(langs)) mkdir(langs, 0775, true);
		phlo_test_wipe(php);
		phlo_test_wipe(www);
		// The app class must be called app, because translation() reads %app->lang.
		$appSrc = PHLO_TEST_TMP.'work/app.phlo';
		file_put_contents($appSrc, "@ summary: lang test app\n\nprop title = 'Lang'\nprop lang = 'nl'\n");
		new build_builder([
			'build' => [
				'routes' => true, 'buildCSS' => true, 'buildJS' => true,
				'minifyCSS' => false, 'minifyJS' => false, 'minifyPHP' => false,
				'_minifyExplicit' => ['minifyCSS' => true, 'minifyJS' => true, 'minifyPHP' => true],
				'phloJS' => false, 'phloNS' => 'engine-off', 'defaultNS' => 'app',
				'iconNS' => 'app', 'comments' => false, 'extends' => 'obj',
				'exclude' => [], 'trace' => false,
			],
			'sources'    => ['app' => [$appSrc], 'resources' => array_map(
					fn($naam) => engine.'resources/'.$naam.'.phlo',
					// lang leans on these at runtime; without them in the build the compiler leaves
					// %AI and %cookies standing and the generated PHP does not parse.
					['AI/AI', 'cookies', 'files/INI', 'phlo.async', 'lang']
				)],
			'app_source' => $appSrc,
		], true);
		require_once php.'app.php';
		require_once php.'lang.php';
		require_once __DIR__.'/support/langspy.php';
	}

	protected function setUp():void {
		foreach (glob(langs.'*.ini') ?: [] as $file) unlink($file);
	}

	private function spy():langspy {
		return new langspy();
	}

	// --- Collecting ----------------------------------------------------------

	public function testPhrasesUnderBothCapsWaitForTheTail():void {
		$spy = $this->spy();
		$spy->take('nl', 'de', ['h1' => 'Een korte zin', 'h2' => 'Nog een korte zin']);

		$this->assertSame([], $spy->jobs, 'a batch left before it was full');
		$this->assertSame(2, $spy->waiting('nl|de'));
	}

	public function testTheLineCapSendsExactlyOneJobWithThoseLines():void {
		$spy = $this->spy();
		$spy->batchLines = 5;
		$spy->take('nl', 'de', ['h1' => 'een', 'h2' => 'twee', 'h3' => 'drie', 'h4' => 'vier']);
		$this->assertSame([], $spy->jobs, 'the cap is five lines and four already left');

		$spy->take('nl', 'de', ['h5' => 'vijf']);
		$this->assertCount(1, $spy->jobs);
		$this->assertSame(['nl', 'de'], [$spy->jobs[0]['from'], $spy->jobs[0]['to']]);
		$this->assertSame(
			['h1' => 'een', 'h2' => 'twee', 'h3' => 'drie', 'h4' => 'vier', 'h5' => 'vijf'],
			json_decode($spy->jobs[0]['json'], true)
		);
		$this->assertSame(0, $spy->waiting('nl|de'), 'the batch stayed behind after it was sent');
	}

	public function testTheCharacterCapSendsBeforeTheLineCapIsReached():void {
		$spy = $this->spy();
		$spy->batchLines = 50;
		$spy->batchChars = 300;
		$spy->take('nl', 'fr', ['h1' => str_repeat('a', 200), 'h2' => str_repeat('b', 150)]);

		$this->assertCount(1, $spy->jobs, 'two long lines are one AI request and should not wait for fifty');
		$this->assertCount(2, json_decode($spy->jobs[0]['json'], true));
	}

	public function testTheSamePhraseTwiceIsOneLine():void {
		$spy = $this->spy();
		$spy->take('nl', 'de', ['h1' => 'Doe de intake']);
		$spy->take('nl', 'de', ['h1' => 'Doe de intake']);

		$this->assertSame(1, $spy->waiting('nl|de'), 'a phrase that appears twice on a page is translated twice');
	}

	public function testTwoLanguagesInOneRequestKeepTheirOwnBatch():void {
		$spy = $this->spy();
		$spy->batchLines = 2;
		$spy->take('nl', 'de', ['h1' => 'een']);
		$spy->take('nl', 'fr', ['h1' => 'een']);
		$this->assertSame([], $spy->jobs, 'the two languages were counted as one batch');

		$spy->take('nl', 'de', ['h2' => 'twee']);
		$this->assertCount(1, $spy->jobs);
		$this->assertSame('de', $spy->jobs[0]['to']);
		$this->assertSame(1, $spy->waiting('nl|fr'), 'the other language left with it');
	}

	// --- The tail ------------------------------------------------------------

	public function testTheTailLeavesWhenTheInstanceDies():void {
		$spy = $this->spy();
		$sent = [];
		$spy->onDispatch = function (array $job) use (&$sent) { $sent[] = $job; return true; };
		$spy->take('nl', 'es', ['h1' => 'Bekijk de pakketten']);
		$this->assertSame([], $sent, 'it left before the instance died');

		unset($spy);
		$this->assertCount(1, $sent, 'the tail of the batch never left');
		$this->assertSame(['h1' => 'Bekijk de pakketten'], json_decode($sent[0]['json'], true));
	}

	public function testDyingWithNothingWaitingSendsNothing():void {
		$spy = $this->spy();
		$sent = [];
		$spy->onDispatch = function (array $job) use (&$sent) { $sent[] = $job; return true; };
		unset($spy);

		$this->assertSame([], $sent, 'an empty batch still asked for a job');
	}

	// --- Failure -------------------------------------------------------------

	public function testARefusedJobDropsTheBatchInsteadOfBlockingThePage():void {
		$spy = $this->spy();
		$spy->batchLines = 1;
		$spy->refuse = true;
		$spy->take('nl', 'de', ['h1' => 'een']);

		$this->assertSame(0, $spy->waiting('nl|de'), 'a refused batch stays behind and is sent again on every call');
		$this->assertSame(1, $spy->tries, 'the batch was never offered');
	}

	public function testAThrowingDispatchNeverEscapesTheDestructor():void {
		$spy = $this->spy();
		$spy->throwOnDispatch = true;
		$spy->take('nl', 'de', ['h1' => 'een']);

		unset($spy);
		$this->assertTrue(true, 'the destructor let an exception out during shutdown');
	}

	// --- Through translation() ----------------------------------------------

	public function testTranslationCollectsInsteadOfAskingPerPhrase():void {
		$spy = $this->spy();
		$spy->batchLines = 3;
		phlo('app')->lang = 'de';

		foreach (['Eerste zin', 'Tweede zin'] as $zin) {
			$this->assertSame($zin, $spy->translation('nl', $zin), 'the source text is what the first visitor reads');
		}
		$this->assertSame([], $spy->jobs, 'translation() still asks for a job per phrase');

		$spy->translation('nl', 'Derde zin');
		$this->assertCount(1, $spy->jobs, 'the third phrase should have filled the batch');
		$this->assertSame(
			['Eerste zin', 'Tweede zin', 'Derde zin'],
			array_values(json_decode($spy->jobs[0]['json'], true))
		);
	}

    public function testAKnownPhraseAsksForNothing():void
    {
		$spy = $this->spy();
		phlo('app')->lang = 'de';
		$hash = $spy->hashOf('nl', 'Doe de intake');
		file_put_contents(langs.'de.ini', $hash.' = "Mach die Aufnahme"'."\n");

		$this->assertSame('Mach die Aufnahme', $spy->translation('nl', 'Doe de intake'));
		$this->assertSame(0, $spy->waiting('nl|de'), 'a phrase that is already translated went into a batch');
	}

	public function testATextOfSeveralLinesCountsPerLine():void {
		$spy = $this->spy();
		phlo('app')->lang = 'de';
		$spy->translation('nl', "Eerste regel\nTweede regel");

		$this->assertSame(2, $spy->waiting('nl|de'), 'a text of two lines should give two phrases');
	}
}
