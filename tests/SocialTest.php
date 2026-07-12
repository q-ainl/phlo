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
		$this->assertArrayNotHasKey('nonce', $q);
		parse_str((string) parse_url(social::authUrl('google', 'state', 'nonce-abc'), PHP_URL_QUERY), $q);
		$this->assertSame('nonce-abc', $q['nonce']);
	}

	private static function b64u(string $raw):string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	// Publishes a throwaway RSA key as the provider's JWKS through the per-request cache, so the
	// signature path is exercised end to end (including the JWK to PEM conversion) without HTTP.
	private static function publishKey(string $provider, string $kid = 'k1'){
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$rsa = openssl_pkey_get_details($key)['rsa'];
		$cache = phlo('req')->socialJwks ?? [];
		$cache[$provider] = [['kty' => 'RSA', 'kid' => $kid, 'n' => self::b64u($rsa['n']), 'e' => self::b64u($rsa['e'])]];
		phlo('req')->socialJwks = $cache;
		return $key;
	}

	private static function sign(array $header, array $claims, $key):string {
		$body = self::b64u((string) json_encode($header)).'.'.self::b64u((string) json_encode($claims));
		openssl_sign($body, $sig, $key, OPENSSL_ALGO_SHA256);
		return $body.'.'.self::b64u((string) $sig);
	}

	public function testSignatureVerifiesAgainstTheProvidersJwks():void {
		$key = self::publishKey('google');
		$jwt = self::sign(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT'], ['sub' => '42'], $key);
		$this->assertTrue(social::verifySignature('google', $jwt));
	}

	public function testForgedAndUnsignedTokensAreRejected():void {
		$key = self::publishKey('google');
		$jwt = self::sign(['alg' => 'RS256', 'kid' => 'k1'], ['sub' => '42', 'email' => 'real@example.test'], $key);
		[$h, $p, $s] = explode('.', $jwt);

		$tampered = $h.'.'.self::b64u((string) json_encode(['sub' => '42', 'email' => 'victim@example.test'])).'.'.$s;
		$this->assertFalse(social::verifySignature('google', $tampered), 'a swapped payload must not verify');

		$none = self::b64u((string) json_encode(['alg' => 'none', 'kid' => 'k1'])).'.'.$p.'.';
		$this->assertFalse(social::verifySignature('google', $none), 'alg none must not verify');

		$hs = self::b64u((string) json_encode(['alg' => 'HS256', 'kid' => 'k1'])).'.'.$p.'.'.$s;
		$this->assertFalse(social::verifySignature('google', $hs), 'a symmetric alg must not verify');

		$unknownKid = self::sign(['alg' => 'RS256', 'kid' => 'other'], ['sub' => '42'], $key);
		$this->assertFalse(social::verifySignature('google', $unknownKid), 'an unknown kid must not verify');

		$this->assertFalse(social::verifySignature('google', 'not.a.jwt'));
	}

	// nOAuth: any tenant can put any address in the email claim, so only the tenant that actually
	// signed the token may be the issuer, and the address counts as verified only with xms_edov.
	public function testMicrosoftIssuerMustNameTheSigningTenant():void {
		$this->assertTrue(social::verifyIssuer('microsoft', [
			'tid' => 'aaaa-1111',
			'iss' => 'https://login.microsoftonline.com/aaaa-1111/v2.0',
		]));
		$this->assertFalse(social::verifyIssuer('microsoft', [
			'tid' => 'attacker-tenant',
			'iss' => 'https://login.microsoftonline.com/aaaa-1111/v2.0',
		]), 'issuer and tid must belong to the same tenant');
		$this->assertFalse(social::verifyIssuer('microsoft', ['iss' => 'https://login.microsoftonline.com/']), 'a bare prefix must not pass');
		$this->assertFalse(social::verifyIssuer('google', ['iss' => 'https://evil.test']));
		$this->assertTrue(social::verifyIssuer('google', ['iss' => 'https://accounts.google.com']));
	}

	public function testMicrosoftEmailCountsAsVerifiedOnlyWithXmsEdov():void {
		$claimed = social::normalize('microsoft', ['sub' => '1', 'email' => 'victim@example.test']);
		$this->assertFalse($claimed['verified'], 'a bare Microsoft email claim is not proof of ownership');

		$proven = social::normalize('microsoft', ['sub' => '1', 'email' => 'user@example.test', 'xms_edov' => true]);
		$this->assertTrue($proven['verified']);

		$this->assertTrue(social::normalize('google', ['sub' => '1', 'email' => 'u@example.test', 'email_verified' => true])['verified']);
		$this->assertFalse(social::normalize('google', ['sub' => '1', 'email' => 'u@example.test', 'email_verified' => false])['verified']);
	}

	public function testClaimsRejectWrongAudienceExpiryAndNonce():void {
		$cfg = social::config('google');
		$ok = ['exp' => time() + 300, 'aud' => $cfg['client_id'], 'iss' => 'https://accounts.google.com'];
		$this->assertTrue(social::verifyClaims('google', $cfg, $ok));

		$this->assertFalse(social::verifyClaims('google', $cfg, ['exp' => time() - 1] + $ok), 'expired');
		$this->assertFalse(social::verifyClaims('google', $cfg, ['aud' => 'someone-else'] + $ok), 'wrong audience');
		$this->assertFalse(social::verifyClaims('google', $cfg, $ok, 'expected-nonce'), 'missing nonce');
		$this->assertFalse(social::verifyClaims('google', $cfg, ['nonce' => 'wrong'] + $ok, 'expected-nonce'), 'nonce mismatch');
		$this->assertTrue(social::verifyClaims('google', $cfg, ['nonce' => 'expected-nonce'] + $ok, 'expected-nonce'));
	}
}
