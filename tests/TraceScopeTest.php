<?php
use PHPUnit\Framework\TestCase;

// trace::boot() must leave tracing off for internal Control Center requests
// (a path under the `control` prefix), so the trace graph only shows real app
// requests. Normal app requests and CLI runs stay traced.
final class TraceScopeTest extends TestCase {

	public static function setUpBeforeClass():void {
		require_once engine.'classes/trace.php';
		if (!defined('control')) define('control', 'phlo');
	}

	protected function setUp():void {
		trace::$on     = false;
		trace::$events = [];
	}

	protected function tearDown():void {
		trace::$on = false;
	}

	public function testControlRequestIsNotTraced():void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = slash.control.'/graph';
		trace::boot(app);
		$this->assertFalse(trace::$on);
	}

	public function testAppRequestIsTraced():void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/orders';
		trace::boot(app);
		$this->assertTrue(trace::$on);
	}

	public function testCliRunIsTraced():void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset($_SERVER['REQUEST_URI']);
		trace::boot(app);
		$this->assertTrue(trace::$on);
	}
}
