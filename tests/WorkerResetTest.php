<?php
use PHPUnit\Framework\TestCase;

// Worker-safety covers two distinct caches that tech/reset must reset between requests
// in a persistent FrankenPHP worker:
//  (1) computed statics reached via the `_x()` underscore fallback cache in
//      obj::$classProps (process-global) -> tech/reset clears that cache;
//  (2) the model's request-local state, `static state => %req->model ??= obj(...)`,
//      compiles to a PLAIN method (not a cached `_x()`) that caches on the request
//      resource %req, which tech/reset drops by dropping non-persistent resources.
// The fixture mirrors both forms exactly so the tests exercise the real mechanisms.
final class WorkerResetTest extends TestCase {

	public function testTechResetRecomputesComputedStatics():void {
		WorkerResetFixture::$ticks = 0;
		$this->assertSame(1, WorkerResetFixture::counter(), 'first call computes');
		$this->assertSame(1, WorkerResetFixture::counter(), 'cached within a request');
		phlo('tech/reset');
		$this->assertSame(2, WorkerResetFixture::counter(), 'recomputed after reset');
	}

	public function testModelStateIsRequestLocal():void {
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
	// Mirrors what `static state => %req->model ??= obj(...)` compiles to: a plain
	// method caching on the request resource, NOT a cached `_state()` in classProps.
	public static function state():obj { return phlo('req')->model ??= new obj(records: []); }
}
