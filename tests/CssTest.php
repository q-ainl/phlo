<?php
use PHPUnit\Framework\TestCase;

final class CssTest extends TestCase {

	public function testSingleLineDeclaration():void {
		$this->assertSame('html{height:100dvh}', build_css::decode('html: height: 100dvh'));
	}

	public function testBlockDeclarations():void {
		$phlo = "body {\n\tbackground: #0d0d0d\n\tcolor: #fff\n}";
		$this->assertSame('body{background:#0d0d0d;color:#fff}', build_css::decode($phlo));
	}

	public function testNestedSelector():void {
		$phlo = "body {\n\tp: line-height: 1.6\n}";
		$this->assertSame('body p{line-height:1.6}', build_css::decode($phlo));
	}

	public function testPseudoSelectorGluesToParent():void {
		$phlo = "a {\n\ttext-decoration: none\n\t\\:hover: color: blue\n}";
		$this->assertSame("a{text-decoration:none}\na:hover{color:blue}", build_css::decode($phlo));
	}

	public function testMediaQueryIsHoisted():void {
		$phlo = "h1 {\n\tfont-size: 2em\n\t@media (max-width: 768px): font-size: 1.2em\n}";
		$css  = build_css::decode($phlo);
		$this->assertStringContainsString('h1{font-size:2em}', $css);
		$this->assertStringContainsString("@media (max-width: 768px){\nh1{font-size:1.2em}}", $css);
	}

	public function testVariablesBecomeCustomProperties():void {
		$phlo = ":root {\n\t\$primary: #f00\n}\nbody {\n\tcolor: \$primary\n}";
		$css  = build_css::decode($phlo);
		$this->assertStringContainsString(':root{--primary:#f00}', $css);
		$this->assertStringContainsString('body{color:var(--primary)}', $css);
	}

	public function testCommentsAndBlankLinesIgnored():void {
		$phlo = "// comment\n\nbody {\n\tcolor: red\n}";
		$this->assertSame('body{color:red}', build_css::decode($phlo));
	}

	public function testPrettyOutput():void {
		$phlo = "body {\n\tcolor: red\n}";
		$this->assertSame("body {\n\tcolor: red;\n}", build_css::decode($phlo, false));
	}

	public function testEncodeCssToPhlo():void {
		$css = 'body{background:#000;color:#fff}';
		$phlo = build_css::encode($css);
		$this->assertSame("body {\n\tbackground: #000\n\tcolor: #fff\n}", $phlo);
	}

	public function testEncodeSingleDeclarationCollapses():void {
		$this->assertSame('html: height: 100dvh', build_css::encode('html{height:100dvh}'));
	}

	public function testDanglingColonValueWrapMerges():void {
		$phlo = "body {\n\tbackground:\n\t\tradial-gradient(a),\n\t\tlinear-gradient(b)\n}";
		$this->assertSame('body{background:radial-gradient(a), linear-gradient(b)}', build_css::decode($phlo));
	}

	public function testDanglingColonBeforeCloserDoesNotSwallowBrace():void {
		$css = build_css::decode("body {\n\tcolor:\n}\np {\n\tmargin: 0\n}");
		$this->assertStringContainsString('p{margin:0}', $css);
	}

	public function testWrappedValueLineThrows():void {
		$this->expectException(PhloException::class);
		$this->expectExceptionMessageMatches('/CSS line is not a declaration/');
		build_css::decode("body {\n\tbackground: linear-gradient(\n\tto bottom, #000, #fff)\n}");
	}

	public function testFullLineBlockCommentIsIgnored():void {
		$this->assertSame('body{color:red}', build_css::decode("/* note */\nbody {\n\tcolor: red\n}"));
	}

	/** decode(encode(decode($phlo))) must equal decode($phlo): CSS is a fixpoint of the round trip. */
	public function testRoundTripFixpoint():void {
		$samples = [
			"body {\n\tbackground: #0d0d0d\n\tcolor: #fff\n}",
			"html: height: 100dvh",
			":root {\n\t\$bg: #111\n}\nmain {\n\tbackground: \$bg\n\tp: margin: 0\n}",
			"h1 {\n\tfont-size: 2em\n\t@media (max-width: 768px): font-size: 1.2em\n}",
		];
		foreach ($samples as $phlo){
			$css = build_css::decode($phlo);
			$this->assertSame($css, build_css::decode(build_css::encode($css)), 'Round trip drifted for: '.$phlo);
		}
	}
}
