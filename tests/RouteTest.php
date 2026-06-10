<?php
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase {

	private function request(string $method, string $uri, bool $async = false):void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI']    = $uri;
		if ($async) $_SERVER['HTTP_X_REQUESTED_WITH'] = 'phlo';
		else unset($_SERVER['HTTP_X_REQUESTED_WITH']);
		phlo('tech/reset');
	}

	public function testRootRouteMatches():void {
		$this->request('GET', '/');
		$this->assertInstanceOf(obj::class, route('GET', ''));
	}

	public function testLiteralMatch():void {
		$this->request('GET', '/home');
		$this->assertInstanceOf(obj::class, route('GET', 'home'));
		$this->assertFalse(route('GET', 'about'));
	}

	public function testMethodMismatch():void {
		$this->request('POST', '/home');
		$this->assertFalse(route('GET', 'home'));
		$this->assertInstanceOf(obj::class, route('POST', 'home'));
	}

	public function testHeadMatchesGet():void {
		$this->request('HEAD', '/home');
		$this->assertInstanceOf(obj::class, route('GET', 'home'));
	}

	public function testVariableBinding():void {
		$this->request('GET', '/profile/42');
		$result = route('GET', 'profile $id');
		$this->assertInstanceOf(obj::class, $result);
		$this->assertSame('42', $result->id);
	}

	public function testRequiredVariableMissing():void {
		$this->request('GET', '/profile');
		$this->assertFalse(route('GET', 'profile $id'));
	}

	public function testOptionalVariableIsBoolean():void {
		$this->request('GET', '/list/full');
		$result = route('GET', 'list $full?');
		$this->assertTrue($result->full);
		$this->request('GET', '/list');
		$result = route('GET', 'list $full?');
		$this->assertFalse($result->full);
	}

	public function testDefaultValue():void {
		$this->request('GET', '/page');
		$result = route('GET', 'page $slug=home');
		$this->assertSame('home', $result->slug);
		$this->request('GET', '/page/about');
		$result = route('GET', 'page $slug=home');
		$this->assertSame('about', $result->slug);
	}

	public function testSlurpCapturesRest():void {
		$this->request('GET', '/file/a/b/c.txt');
		$result = route('GET', 'file $path=*');
		$this->assertSame('a/b/c.txt', $result->path);
	}

	public function testLengthConstraint():void {
		$this->request('GET', '/code/abcd');
		$this->assertInstanceOf(obj::class, route('GET', 'code $id.4'));
		$this->assertFalse(route('GET', 'code $id.6'));
	}

	public function testEnumConstraint():void {
		$this->request('GET', '/report/daily');
		$result = route('GET', 'report $range:daily,weekly');
		$this->assertSame('daily', $result->range);
		$this->request('GET', '/report/yearly');
		$this->assertFalse(route('GET', 'report $range:daily,weekly'));
	}

	public function testExtraSegmentFails():void {
		$this->request('GET', '/home/extra');
		$this->assertFalse(route('GET', 'home'));
	}

	public function testAsyncFilter():void {
		$this->request('GET', '/items', async: true);
		$this->assertInstanceOf(obj::class, route('GET', 'items', async: true));
		$this->assertFalse(route('GET', 'items', async: false));
		$this->request('GET', '/items', async: false);
		$this->assertFalse(route('GET', 'items', async: true));
	}

	public function testCallbackReceivesArgsAndFalseMeansMiss():void {
		$this->request('GET', '/profile/7');
		$seen = null;
		$hit  = route('GET', 'profile $id', cb: function($id) use (&$seen){ $seen = $id; });
		$this->assertTrue($hit);
		$this->assertSame('7', $seen);
		$this->assertFalse(route('GET', 'profile $id', cb: fn($id) => false));
	}

	public function testPathIsUrlDecoded():void {
		$this->request('GET', '/tag/caf%C3%A9');
		$result = route('GET', 'tag $name');
		$this->assertSame('café', $result->name);
	}
}
