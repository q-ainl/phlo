<?php
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase {

	private function parse(string $source, string $name = 'page.test.phlo'):build_file {
		$file = PHLO_TEST_TMP.'work/'.$name;
		file_put_contents($file, $source);
		return new build_file($file);
	}

	public function testClassNameFromFilename():void {
		$file = $this->parse("prop title = 'X'\n");
		$this->assertSame('page_test', $file->class);
	}

	public function testClassNameOverrideViaMeta():void {
		$file = $this->parse("@ class: custom\nprop title = 'X'\n");
		$this->assertSame('custom', $file->class);
		$this->assertSame('custom', $file->meta['class']);
	}

	public function testNodeTypesAndOperators():void {
		$src = <<<'PHLO'
		prop title = 'App'
		prop now => time()
		method hello($who) => "Hi $who"
		method classify($x) {
			if ($x > 5) return 'large'
			return 'small'
		}
		static count = 0
		view main:
		<p>$title</p>

		route GET home => $this->hello('home')
		PHLO;
		$file = $this->parse($src);
		$nodes = $file->nodes;
		$this->assertSame('value',  $nodes['title']->operator);
		$this->assertSame('arrow',  $nodes['now']->operator);
		$this->assertSame('arrow',  $nodes['hello']->operator);
		$this->assertSame('$who',   $nodes['hello']->args);
		$this->assertSame('method', $nodes['classify']->operator);
		$this->assertSame('value',  $nodes['count']->operator);
		$this->assertSame('view',   $nodes['main']->operator);
		$this->assertSame('route',  $nodes['GETHome']->node);
	}

	public function testBlankLineClosesView():void {
		$src = "view main:\n<h1>Title</h1>\n<p>Body</p>\n\nprop after = 1\n";
		$file = $this->parse($src);
		$this->assertSame("<h1>Title</h1>\n<p>Body</p>", $file->nodes['main']->body);
		$this->assertArrayHasKey('after', $file->nodes);
	}

	public function testControllerCodeIsCollected():void {
		$src = "prop ready = false\n\$this->ready = true\n\$this->boot()\n";
		$file = $this->parse($src);
		$this->assertArrayHasKey('controller', $file->nodes);
		$this->assertSame("\$this->ready = true\n\$this->boot()", $file->nodes['controller']->body);
	}

	public function testSplitControllerIsBuildError():void {
		$src = "\$this->a = 1\nprop x = 1\n\$this->b = 2\nprop y = 1\n\$this->c = 3\n";
		$this->expectException(PhloException::class);
		$this->expectExceptionMessageMatches('/Controller must be in one place/');
		$this->parse($src);
	}

	public function testDuplicateNodeIsBuildError():void {
		$this->expectException(PhloException::class);
		$this->expectExceptionMessageMatches('/Duplicate node/');
		$this->parse("prop a = 1\nprop a = 2\n");
	}

	public function testCommentsAttachToNextNode():void {
		$src = "// Greets the user\nmethod hello => 'hi'\n";
		$file = $this->parse($src);
		$this->assertSame('Greets the user', $file->nodes['hello']->comments);
	}

	public function testFunctionsAndAssetsAreSeparated():void {
		$src = <<<'PHLO'
		function slugify($value):string => strtolower(trim($value))
		<style>
		body {
			color: red
		}
		</style>
		<script>
		on('click', '#x', () => app.get('y'))
		</script>
		PHLO;
		$file = $this->parse($src);
		$this->assertArrayHasKey('slugify', $file->functions);
		$this->assertSame('string', $file->functions['slugify']->type);
		$this->assertCount(2, $file->assets);
		$this->assertSame('style',  $file->assets[0]->node);
		$this->assertSame('script', $file->assets[1]->node);
		$this->assertArrayNotHasKey('slugify', $file->nodes);
	}

	public function testRouteHeaderParsing():void {
		$src = "route async POST items save @name => \$this->saveItem\n";
		$file = $this->parse($src);
		$node = current($file->nodes);
		$this->assertSame('route',      $node->node);
		$this->assertSame('async',      $node->mode);
		$this->assertSame('POST',       $node->method);
		$this->assertSame('items save', $node->path);
		$this->assertSame('name',       $node->data);
		$this->assertSame('arrow',      $node->operator);
	}

	public function testAnonymousViewGetsDefaultName():void {
		$file = $this->parse("view:\n<p>anon</p>\n");
		$this->assertArrayHasKey('view', $file->nodes);
	}

	public function testViewKeywordAsStatementIsControllerCode():void {
		$file = $this->parse("prop a = 1\nview(\$this->main, 'Home')\n");
		$this->assertArrayHasKey('controller', $file->nodes);
		$this->assertStringContainsString("view(\$this->main, 'Home')", $file->nodes['controller']->body);
		$this->assertArrayNotHasKey('view', $file->nodes);
	}
}
