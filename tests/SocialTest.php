<?php

use PHPUnit\Framework\TestCase;

// social::config() must read ini-backed credentials through the creds resource's
// SensitiveParameterValue wrapping (the toArray unwrap): a plain (array) cast on the
// section object yields obj internals (objData, ...) instead of the ini keys, which
// left configured() false for every ini-configured provider.
final class SocialTest extends TestCase {

	private const RESOURCES = ['security/creds', 'HTTP', 'security/OAuth2', 'security/social'];

	public static function setUpBeforeClass():void {
		phlo_test_wipe(php);
		phlo_test_wipe(www);
		$resources = array_map(fn($n) => engine.'resources/'.$n.'.phlo', self::RESOURCES);
		$appSrc = PHLO_TEST_TMP.'work/socialtestapp.phlo';
		file_put_contents($appSrc, "@ summary: social test app\n\nprop title = 'Social'\n");
		new build_builder([
			'build' => [
				'routes' => true, 'buildCSS' => true, 'buildJS' => true,
				'minifyCSS' => false, 'minifyJS' => false, 'minifyPHP' => false,
				'_minifyExplicit' => ['minifyCSS' => true, 'minifyJS' => true, 'minifyPHP' => true],
				'phloJS' => false, 'phloNS' => 'engine-off', 'defaultNS' => 'app',
				'iconNS' => 'app', 'comments' => false, 'extends' => 'obj',
				'exclude' => [], 'trace' => false,
			],
			'sources'    => ['app' => [$appSrc], 'resources' => $resources],
			'app_source' => $appSrc,
		], true);
		foreach (['creds', 'OAuth2', 'social'] as $class){
			require_once php.$class.'.php';
		}
		file_put_contents(data.'creds.ini', implode("\n", [
			'[google]',
			'client_id = test-client.apps.googleusercontent.com',
			'client_secret = test-secret',
			'',
			'[microsoft]',
			'client_id = ms-client',
			'client_secret = ms-secret',
			'redirect_uri = https://example.test/custom/callback',
			'',
		]));
		phlo('tech/reset');
	}

	public static function tearDownAfterClass():void {
		@unlink(data.'creds.ini');
		phlo('tech/reset');
	}

	public function testConfigUnwrapsIniSectionCreds():void {
		$c = social::config('google');
		$this->assertSame('test-client.apps.googleusercontent.com', $c['client_id']);
		$this->assertSame('test-secret', $c['client_secret']);
		$this->assertTrue(social::configured('google'));
	}

	public function testRedirectUriDefaultsToHostCallbackAndHonoursOverride():void {
		$this->assertSame('https://phlo.test/auth/google/callback', social::config('google')['redirect_uri']);
		$this->assertSame('https://example.test/custom/callback', social::config('microsoft')['redirect_uri']);
	}

	public function testUnconfiguredAndUnknownProviders():void {
		$this->assertFalse(social::configured('apple'));
		$this->assertNull(social::config('github'));
		$this->assertFalse(social::configured('github'));
	}

	public function testAuthUrlCarriesClientIdStateAndScope():void {
		$url = social::authUrl('google', 'state-token-123');
		$this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
		$this->assertSame('test-client.apps.googleusercontent.com', $q['client_id']);
		$this->assertSame('state-token-123', $q['state']);
		$this->assertSame('code', $q['response_type']);
		$this->assertSame('openid email profile', $q['scope']);
	}
}
