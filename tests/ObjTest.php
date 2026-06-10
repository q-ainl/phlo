<?php
use PHPUnit\Framework\TestCase;

class objFixture extends obj {
	public static int $computeCalls = 0;
	protected function _stamp():string { return 'S'.(++self::$computeCalls); }
	protected function _double($n):int { ++self::$computeCalls; return $n * 2; }
	protected static function _seven():int { return 7; }
}

final class ObjTest extends TestCase {

	protected function setUp():void {
		objFixture::$computeCalls = 0;
	}

	public function testDataSetGetUnset():void {
		$o = new obj();
		$this->assertFalse($o->objChanged);
		$o->name = 'phlo';
		$this->assertSame('phlo', $o->name);
		$this->assertTrue($o->objChanged);
		$this->assertTrue(isset($o->name));
		unset($o->name);
		$this->assertFalse(isset($o->name));
	}

	public function testConstructorImportDoesNotMarkChanged():void {
		$o = new obj(a: 1, b: 2);
		$this->assertSame(1, $o->a);
		$this->assertFalse($o->objChanged);
		$this->assertSame(['a', 'b'], $o->objKeys());
		$this->assertSame(2, $o->objLength());
	}

	public function testClosureBindsToInstance():void {
		$o = new obj(name: 'world');
		$o->greet = function($greeting){ return "$greeting $this->name"; };
		$this->assertTrue($o->hasClosure('greet'));
		$this->assertSame('hi world', $o->greet('hi'));
	}

	public function testComputedPropIsCached():void {
		$o = new objFixture();
		$first = $o->stamp;
		$this->assertSame('S1', $first);
		$this->assertSame($first, $o->stamp);
		$this->assertSame(1, objFixture::$computeCalls);
	}

	public function testComputedPropWithArgsCachesPerArgs():void {
		$o = new objFixture();
		$this->assertSame(10, $o->double(5));
		$this->assertSame(10, $o->double(5));
		$this->assertSame(1, objFixture::$computeCalls);
		$this->assertSame(14, $o->double(7));
		$this->assertSame(2, objFixture::$computeCalls);
	}

	public function testComputedStatic():void {
		$this->assertSame(7, objFixture::seven());
	}

	public function testUnknownCallThrows():void {
		$this->expectException(PhloException::class);
		(new obj())->nope();
	}

	public function testIterationAndJson():void {
		$o = new obj(a: 1, b: 2);
		$this->assertSame(['a' => 1, 'b' => 2], iterator_to_array($o));
		$this->assertSame('{"a":1,"b":2}', json_encode($o));
	}

	public function testSerializeRoundTrip():void {
		$o = new obj(a: 1);
		$copy = unserialize(serialize($o));
		$this->assertSame(1, $copy->a);
	}

	public function testToStringWithoutViewThrows():void {
		$this->expectException(PhloException::class);
		(string)(new obj());
	}
}
