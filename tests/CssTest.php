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

	// decode(encode(decode($phlo))) must equal decode($phlo): CSS is a fixpoint of the round trip.
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

	// Functional pseudo-classes (:is/:not/:where) carry commas inside their parens; the nesting
	// combiner must not split on those commas (it would prepend the parent to the post-comma part).
	public function testFunctionalPseudoCommaSurvivesNesting():void {
		$this->assertSame('.nav a:is(.x, .y){color:red}', build_css::decode(".nav {\n\ta:is(.x, .y): color: red\n}"));
		$this->assertSame('.box:where(.x, .y){color:red}', build_css::decode(".box {\n\t:where(.x, .y): color: red\n}"));
		$this->assertSame('.a .b c:not(.x, .y){color:red}', build_css::decode(".a {\n\t.b {\n\t\tc:not(.x, .y): color: red\n\t}\n}"));
	}

	public function testFunctionalPseudoCommaSurvivesFlat():void {
		$this->assertSame(':is(.a, .b){color:red}', build_css::decode(":is(.a, .b) {\n\tcolor: red\n}"));
		$this->assertSame('.card:is(.a, .b){color:red}', build_css::decode(".card:is(.a, .b) {\n\tcolor: red\n}"));
		$this->assertSame(':not(h1, h2){margin:0}', build_css::decode(":not(h1, h2) {\n\tmargin: 0\n}"));
	}

	public function testGroupedSelectorWithFunctionalPseudo():void {
		$this->assertSame('.card, :is(.a, .b){color:red}', build_css::decode(".card, :is(.a, .b) {\n\tcolor: red\n}"));
	}

	// A comma inside a quoted attribute value must not split the selector either.
	public function testCommaInQuotedAttributePreserved():void {
		$this->assertSame('.a b[data-x="p, q"]{color:red}', build_css::decode(".a {\n\tb[data-x=\"p, q\"]: color: red\n}"));
	}

	public function testTrailingSemicolonIsNotPartOfTheValue():void {
		$this->assertSame('body{color:red}', build_css::decode("body {\n\tcolor: red;\n}"));
		$this->assertSame('body{color:red;margin:0}', build_css::decode("body {\n\tcolor: red;\n\tmargin: 0;;\n}"));
		$this->assertSame('html{height:100dvh}', build_css::decode('html: height: 100dvh;'));
	}

	public function testSemicolonInsideAQuotedValueSurvives():void {
		$this->assertSame('.a::after{content:"x;y"}', build_css::decode(".a::after {\n\tcontent: \"x;y\"\n}"));
		$this->assertSame('.a::after{content:"x;y"}', build_css::decode(".a::after {\n\tcontent: \"x;y\";\n}"));
		$this->assertSame(".b{background:url('a;b.png')}", build_css::decode(".b {\n\tbackground: url('a;b.png')\n}"));
	}

	public function testAtRuleWithDeclarationBodyRendersItsOwnBlock():void {
		$this->assertSame('@page{size:A4 landscape;margin:10mm}', build_css::decode("@page {\n\tsize: A4 landscape\n\tmargin: 10mm\n}"));
		$this->assertSame('@font-face{font-family:Inter}', build_css::decode("@font-face {\n\tfont-family: Inter\n}"));
	}

	public function testAtRuleWithDeclarationBodyKeepsItsPrelude():void {
		$this->assertSame('@page :first{margin-top:30mm}', build_css::decode("@page :first {\n\tmargin-top: 30mm\n}"));
		$this->assertSame("@property --tint{syntax:'<color>';inherits:false}", build_css::decode("@property --tint {\n\tsyntax: '<color>'\n\tinherits: false\n}"));
	}

	public function testUnknownAtRuleIsClassifiedByItsBody():void {
		$this->assertSame('@brandnew{foo:bar}', build_css::decode("@brandnew {\n\tfoo: bar\n}"));
		$this->assertSame("@brandnew (x){\n.a{color:red}}", build_css::decode("@brandnew (x) {\n\t.a {\n\t\tcolor: red\n\t}\n}"));
	}

	public function testConditionalGroupsKeepWrappingSelectors():void {
		$this->assertSame("@media (min-width: 40em){\n.x{color:red}}", build_css::decode("@media (min-width: 40em) {\n\t.x {\n\t\tcolor: red\n\t}\n}"));
		$this->assertSame("@supports (display: grid){\n.x{display:grid}}", build_css::decode("@supports (display: grid) {\n\t.x {\n\t\tdisplay: grid\n\t}\n}"));
		$this->assertSame("@keyframes fade{\n0%{opacity:0}}", build_css::decode("@keyframes fade {\n\t0% {\n\t\topacity: 0\n\t}\n}"));
	}

	public function testInlineDeclarationAtRuleBecomesItsOwnBlock():void {
		$this->assertSame('@view-transition{navigation:auto}', build_css::decode('@view-transition: navigation: auto'));
		$this->assertSame("@page{size:A4 landscape}", build_css::decode(".kaart {\n\t@page: size: A4 landscape\n}"));
	}

	public function testInlineConditionalGroupStillWrapsTheSelector():void {
		$this->assertSame("@media (max-width: 40em){\n.kaart{color:red}}", build_css::decode(".kaart {\n\t@media (max-width: 40em): color: red\n}"));
	}

	public function testIdSelectorSurvivesTheAtRuleKeying():void {
		$this->assertSame('#header{color:red}', build_css::decode("#header {\n\tcolor: red\n}"));
		$this->assertSame('.a #b{color:red}', build_css::decode(".a {\n\t#b: color: red\n}"));
	}

	public function testRepeatedDeclarationBodyBlocksStaySeparate():void {
		$phlo = "@font-face {\n\tfont-family: A\n}\n@font-face {\n\tfont-family: B\n}\n@page {\n\tmargin: 1mm\n}\n@page {\n\tmargin: 2mm\n}";
		$this->assertSame('@font-face{font-family:A}@font-face{font-family:B}@page{margin:1mm}@page{margin:2mm}', str_replace(lf, void, build_css::decode($phlo)));
	}

	public function testDeclarationThatBelongsToNothingIsABuildError():void {
		$this->expectException(PhloException::class);
		$this->expectExceptionMessageMatches('/belongs to no selector/');
		build_css::decode('color: red');
	}

	public function testDeclarationInsideAConditionalGroupIsABuildError():void {
		$this->expectException(PhloException::class);
		$this->expectExceptionMessageMatches('/belongs to no selector/');
		build_css::decode("@media (min-width: 40em) {\n\tcolor: red\n}");
	}

	public function testEncodeKeepsAtRulesWithADeclarationBody():void {
		$this->assertSame("@font-face {\n\tfont-family: Inter\n\tsrc: url(/f.woff2)\n}", build_css::encode('@font-face{font-family:Inter;src:url(/f.woff2)}'));
		$this->assertSame('@page: margin-bottom: 30mm', build_css::encode('@page{margin-bottom:30mm}'));
		$this->assertSame('@view-transition: navigation: auto', build_css::encode('@view-transition{navigation:auto}'));
	}

	public function testEncodeLeavesConditionalGroupsAlone():void {
		$this->assertSame('@media (min-width: 40em): .x: color: red', build_css::encode('@media (min-width: 40em){.x{color:red}}'));
	}

	public function testAtRuleRoundTripFixpoint():void {
		$samples = [
			"@font-face {\n\tfont-family: Inter\n\tsrc: url(/f.woff2)\n}",
			"@page {\n\tmargin-bottom: 30mm\n}",
			"@page :first {\n\tmargin-top: 30mm\n}",
			"@view-transition: navigation: auto",
			"@property --tint {\n\tsyntax: '<color>'\n\tinherits: false\n}",
		];
		foreach ($samples as $phlo){
			$css = build_css::decode($phlo);
			$this->assertNotSame(void, $css, 'Nothing came out for: '.$phlo);
			$this->assertSame($css, build_css::decode(build_css::encode($css)), 'Round trip drifted for: '.$phlo);
		}
	}

	public function testStylesheetsThatSilentlyLostTheirAtRule():void {
		$pdf = build_css::decode("@page {\n\tmargin-bottom: 30mm\n}\nbody {\n\tcolor: #111\n}");
		$this->assertStringContainsString('@page{margin-bottom:30mm}', $pdf);
		$this->assertStringContainsString('body{color:#111}', $pdf);
		$trans = build_css::decode("@view-transition: navigation: auto\n@media (prefers-reduced-motion: reduce) {\n\t@view-transition: navigation: none\n}");
		$this->assertStringContainsString('@view-transition{navigation:auto}', $trans);
		$this->assertStringContainsString('navigation:none', $trans);
	}
}
