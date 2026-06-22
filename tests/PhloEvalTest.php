<?php
use PHPUnit\Framework\TestCase;

final class PhloEvalTest extends TestCase {

	public function testTranspileResolvesResourceObjects():void {
		$this->assertSame("phlo('store')->all();", build_node::transpile('%store->all()'));
	}

	public function testTranspileTerminatesStatements():void {
		$php = build_node::transpile("\$x = 21\nreturn \$x * 2");
		$this->assertSame("\$x = 21;\nreturn \$x * 2;", $php);
	}

	public function testEvalRunsExpression():void {
		$this->assertSame(3, eval(build_node::transpile('return 1 + 2')));
	}

	public function testEvalRunsMultilineBlock():void {
		$this->assertSame(42, eval(build_node::transpile("\$x = 21\nreturn \$x * 2")));
	}
}
