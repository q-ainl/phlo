<?php
use PHPUnit\Framework\TestCase;

// Covers the server-side markdown of the manual resource: the block parser, the inline
// formatter and the views that turn blocks into HTML. The page renders data/app.md, which
// an app writes for itself, so the parser has to hold up against ordinary prose as well as
// the identifiers and code fragments those files are full of.
final class ManualMarkdownTest extends TestCase {

	public static function setUpBeforeClass():void {
		phlo_test_wipe(php);
		phlo_test_wipe(www);
		$appSrc = PHLO_TEST_TMP.'work/manualapp.phlo';
		file_put_contents($appSrc, "@ summary: manual test app\n\nprop title = 'Manual'\n");
		new build_builder([
			'build' => [
				'routes' => true, 'buildCSS' => true, 'buildJS' => true,
				'minifyCSS' => false, 'minifyJS' => false, 'minifyPHP' => false,
				'phloJS' => false, 'phloNS' => 'engine-off', 'defaultNS' => 'app',
				'iconNS' => 'app', 'comments' => false, 'extends' => 'obj',
				'exclude' => [], 'trace' => false,
			],
			'sources'    => ['app' => [$appSrc], 'resources' => [engine.'resources/manual.phlo']],
			'app_source' => $appSrc,
		], true);
		require_once php.'functions.php';
		require_once php.'manual.php';
	}

	// The resource names %AI and %creds, which are not in this build. It must still compile
	// and load, otherwise an app pays for the manual with resources it does not want.
	public function testCompilesWithoutTheOptionalResources():void {
		$this->assertTrue(class_exists('manual'));
		$this->assertFalse(class_exists('AI'));
		$this->assertFalse(manual::configured());
	}

	public function testHeadingsClampToTheThreeLevelsTheStylesheetKnows():void {
		$blocks = manual::mdBlocks("# One\n\n## Two\n\n### Three\n\n#### Four\n\n##### Five");
		$this->assertSame(['heading', 'heading', 'heading', 'heading', 'heading'], array_column($blocks, 'type'));
		$this->assertSame([2, 2, 3, 4, 4], array_column($blocks, 'depth'));
		$this->assertSame('One', $blocks[0]->text);
	}

	public function testParagraphKeepsItsLinesAndSurvivesTrailingHashes():void {
		$blocks = manual::mdBlocks("## Kop ##\nfirst line\nsecond line\n\ntail");
		$this->assertSame('Kop', $blocks[0]->text);
		$this->assertSame("first line\nsecond line", $blocks[1]->text);
		$this->assertSame('tail', $blocks[2]->text);
	}

	public function testBulletsTasksAndOrderedListsStaySeparateLists():void {
		$blocks = manual::mdBlocks("- one\n- two\n\n1. first\n2. second\n\n- [ ] open\n- [x] done");
		$this->assertSame(['list', 'list', 'list'], array_column($blocks, 'type'));
		$this->assertFalse($blocks[0]->ordered);
		$this->assertTrue($blocks[1]->ordered);
		$checked = static fn($list) => array_map(static fn($item) => $item->checked, $list);
		$this->assertSame([null, null], $checked($blocks[0]->items));
		$this->assertSame([false, true], $checked($blocks[2]->items));
		$this->assertSame('done', $blocks[2]->items[1]->text);
	}

	public function testNestedListBecomesABlockUnderItsItem():void {
		$blocks = manual::mdBlocks("- parent\n  - child one\n  - child two\n- sibling");
		$items  = $blocks[0]->items;
		$this->assertCount(2, $items);
		$this->assertSame('parent', $items[0]->text);
		$this->assertSame('list', $items[0]->blocks[0]->type);
		$this->assertSame(['child one', 'child two'], array_column($items[0]->blocks[0]->items, 'text'));
		$this->assertSame([], $items[1]->blocks);
	}

	public function testFencedCodeKeepsItsBodyVerbatim():void {
		$blocks = manual::mdBlocks("text\n\n```sh\nphp app.php build::run\n# a comment\n```\n\nafter");
		$this->assertSame(['para', 'code', 'para'], array_column($blocks, 'type'));
		$this->assertSame("php app.php build::run\n# a comment", $blocks[1]->text);
		$this->assertSame('after', $blocks[2]->text);
	}

	public function testTableKeepsItsHeaderAndRows():void {
		$blocks = manual::mdBlocks("| Discipline | key |\n|---|---:|\n| skydive | dropzone |\n| dive | location |");
		$this->assertSame('table', $blocks[0]->type);
		$this->assertSame(['Discipline', 'key'], $blocks[0]->head);
		$this->assertSame([['skydive', 'dropzone'], ['dive', 'location']], $blocks[0]->rows);
	}

	public function testRuleAndQuote():void {
		$blocks = manual::mdBlocks("---\n\n> a quote\n> over two lines");
		$this->assertSame('hr', $blocks[0]->type);
		$this->assertSame("a quote\nover two lines", $blocks[1]->text);
	}

	public function testInlineEscapesBeforeItAddsMarkup():void {
		$html = manual::mdInline('<script>alert("x")</script> & co');
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
		$this->assertStringContainsString('&amp;', $html);
	}

	public function testInlineCodeBoldAndItalic():void {
		$this->assertSame('a <code>b_c</code> d', manual::mdInline('a `b_c` d'));
		$this->assertSame('a <code>b</code> d', manual::mdInline('a ``b`` d'));
		$this->assertSame('a <strong>b</strong> c', manual::mdInline('a **b** c'));
		$this->assertSame('a <em>b</em> c', manual::mdInline('a *b* c'));
	}

	// These files are full of identifiers like tenant_users and of asterisks used as
	// multiplication; emphasis on underscores would italicise half of them.
	public function testUnderscoresAndLooseAsterisksAreLeftAlone():void {
		$this->assertSame('tenant_users and invoice_lines', manual::mdInline('tenant_users and invoice_lines'));
		$this->assertSame('5 * 3 = 15', manual::mdInline('5 * 3 = 15'));
	}

	public function testOnlySafeLinkTargetsBecomeLinks():void {
		$this->assertSame('<a href="https://phlo.tech">docs</a>', manual::mdInline('[docs](https://phlo.tech)'));
		$this->assertSame('<a href="/manual">here</a>', manual::mdInline('[here](/manual)'));
		$this->assertStringNotContainsString('<a', manual::mdInline('[x](javascript:alert(1))'));
	}

	public function testViewsRenderTheBlocksIntoTheMarkupTheStylesheetTargets():void {
		$md   = "## Status\n\n- [x] done\n- [ ] open\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n```\nraw <b>\n```";
		$html = phlo('manual')->infoText(manual::mdBlocks($md));
		$this->assertStringContainsString('<h2>Status</h2>', $html);
		$this->assertStringContainsString('<li class="docs__task docs__task--done">done</li>', $html);
		$this->assertStringContainsString('<li class="docs__task">open</li>', $html);
		$this->assertStringContainsString('<table class="docs__table">', $html);
		$this->assertStringContainsString('<th>a</th>', $html);
		$this->assertStringContainsString('<pre class="docs__pre">raw &lt;b&gt;</pre>', $html);
		$this->assertStringNotContainsString('<script', $html);
	}

	public function testEmptyDescriptionRendersNothingInsteadOfBreaking():void {
		$this->assertSame([], manual::mdBlocks(''));
		$this->assertSame([], manual::mdBlocks("\n\n \n"));
	}
}
