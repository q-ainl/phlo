<?php
use PHPUnit\Framework\TestCase;

// Worker-safety: computed statics (`static x => ...`) are cached process-globally in
// obj::$classProps. In a persistent FrankenPHP worker that cache would otherwise bleed
// one request's result into the next, so tech/reset drops it between requests. The
// model's request-local state (records/loaded/errors) rides on exactly this mechanism.
final class WorkerResetTest extends TestCase {

	public function testTechResetRecomputesComputedStatics():void {
		WorkerResetFixture::$ticks = 0;
		$this->assertSame(1, WorkerResetFixture::counter(), 'first call computes');
		$this->assertSame(1, WorkerResetFixture::counter(), 'cached within a request');
		phlo('tech/reset');
		$this->assertSame(2, WorkerResetFixture::counter(), 'recomputed after reset');
	}

	public function testComputedStaticStateIsRequestLocal():void {
		$first = WorkerResetFixture::state();
		$first->records['x'] = 1;
		$this->assertSame($first, WorkerResetFixture::state(), 'same state object within a request');
		phlo('tech/reset');
		$fresh = WorkerResetFixture::state();
		$this->assertNotSame($first, $fresh, 'fresh state object after reset');
		$this->assertSame([], (array)$fresh->records, 'previous request state must not bleed');
	}
}

class WorkerResetFixture extends obj {
	public static int $ticks = 0;
	protected static function _counter():int { return ++self::$ticks; }
	protected static function _state():obj { return new obj(records: []); }
}
