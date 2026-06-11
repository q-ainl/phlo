<?php
use PHPUnit\Framework\TestCase;

// Guards the security decision to remove the dashboard's arbitrary-file viewer.
// The "inspect" section read any path resolvable on disk (e.g. data/auth.ini);
// it was removed in favour of the Source/Build/Release views, which only serve
// files from known maps. If someone reintroduces a raw file reader, these tests
// should force a deliberate, confined design.
final class DashboardTest extends TestCase {

	public static function setUpBeforeClass():void {
		require_once engine.'control.php';
	}

	public function testInspectMethodIsGone():void {
		$this->assertFalse(method_exists('phlo_dashboard', 'inspect'), 'The arbitrary-file inspect viewer must not return without path confinement');
	}

	public function testHandlerSourceDoesNotRegisterInspect():void {
		$source = (string)file_get_contents(engine.'control.php');
		$this->assertStringNotContainsString("'inspect'", $source);
		$this->assertStringNotContainsString('"inspect"', $source);
	}

	public function testFileResolutionStillExistsForLinkBuilding():void {
		$ref = new ReflectionMethod('phlo_dashboard', 'resolveFilePath');
		$this->assertTrue($ref->isPrivate(), 'resolveFilePath stays for dashboardFileTarget link building only');
	}
}
