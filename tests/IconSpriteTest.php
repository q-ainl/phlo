<?php
use PHPUnit\Framework\TestCase;

final class IconSpriteTest extends TestCase {

	private static string $icons = PHLO_TEST_TMP.'work/icons/';

	public static function setUpBeforeClass():void {
		if (!extension_loaded('gd')) return;
		require_once engine.'classes/icons.php';
		if (!is_dir(self::$icons)) mkdir(self::$icons, 0775, true);
		foreach (['save' => [24, 24], 'trash' => [16, 24], 'save.dark' => [24, 24]] as $name => [$w, $h]){
			$img = imagecreatetruecolor($w, $h);
			imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
			imagepng($img, self::$icons.$name.'.png');
		}
	}

	protected function setUp():void {
		if (!extension_loaded('gd')) $this->markTestSkipped('gd is not available');
	}

	private function css():string {
		phlo_test_wipe(www);
		$sprite = build_icons::build(self::$icons, www, '1.0');
		$this->assertNotNull($sprite, 'the sprite builder returned nothing');
		return $sprite;
	}

	public function testSpriteCssIsWrittenInTheDialect():void {
		$sprite = $this->css();
		$this->assertStringNotContainsString(semi, $sprite, 'the sprite feeds the .phlo parser, which takes one declaration per line without a terminator');
		$this->assertStringContainsString('background-image: url(/icons.png?1.0)', $sprite);
		$this->assertStringContainsString('.icon.trash', $sprite);
		$this->assertStringContainsString('body.dark .icon.save', $sprite);
	}

	public function testSpriteSurvivesTheParserWithoutDoubledTerminators():void {
		$css = phlo_css($this->css());
		$this->assertStringNotContainsString(';;', $css);
		$this->assertStringContainsString('background-image:url(/icons.png?1.0)', $css);
		$this->assertStringContainsString('.icon{', $css);
	}

	public function testSpriteImageLandsNextToTheCss():void {
		$this->css();
		$this->assertFileExists(www.'icons.png');
		[$width, $height] = getimagesize(www.'icons.png');
		$this->assertSame(64, $width);
		$this->assertSame(24, $height);
	}
}
