<?php
use PHPUnit\Framework\TestCase;

// Compiles the connector resources with the real build pipeline, then exercises
// the base class's pure request builders, the normalized result contract, the
// per-connector auth/URL wiring and the OAuth2 token store. No network access:
// every check uses injected config and pre-seeded tokens, so the curl transport
// is never reached.
final class ConnectorTest extends TestCase {

	private const RESOURCES = [
		'security/creds', 'HTTP', 'security/OAuth2',
		'connectors/Connector', 'connectors/TokenStore', 'connectors/OAuthConnector',
		'connectors/shops/Shopify', 'connectors/shops/Lightspeed',
		'connectors/chat/Slack', 'connectors/chat/Telegram', 'connectors/chat/Twilio',
		'connectors/chat/MessageBird', 'connectors/chat/Resend',
		'connectors/finance/Moneybird', 'connectors/finance/ExactOnline',
		'connectors/cloud/MicrosoftGraph', 'connectors/cloud/GoogleCalendar', 'connectors/cloud/GoogleSheets',
	];

	public static function setUpBeforeClass():void {
		phlo_test_wipe(php);
		phlo_test_wipe(www);
		$resources = array_map(fn($n) => engine.'resources/'.$n.'.phlo', self::RESOURCES);
		$appSrc = PHLO_TEST_TMP.'work/conntestapp.phlo';
		file_put_contents($appSrc, "@ summary: connector test app\n\nprop title = 'Conn'\n");
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
		foreach (['creds', 'OAuth2', 'Connector', 'TokenStore', 'OAuthConnector', 'Shopify',
			'Lightspeed', 'Slack', 'Telegram', 'Twilio', 'MessageBird', 'Resend', 'Moneybird',
			'ExactOnline', 'MicrosoftGraph', 'GoogleCalendar', 'GoogleSheets'] as $class){
			require_once php.$class.'.php';
		}
	}

	// --- Pure request builder ------------------------------------------------

	public function testBuildGetAppendsQueryAndAccept():void {
		$r = Connector::build('get', 'https://api.test/users', ['q' => 'a b', 'limit' => 2], ['X-Test: 1']);
		$this->assertSame('GET', $r['method']);
		$this->assertSame('https://api.test/users?q=a+b&limit=2', $r['url']);
		$this->assertContains('X-Test: 1', $r['headers']);
		$this->assertContains('Accept: application/json', $r['headers']);
		$this->assertNull($r['body']);
	}

	public function testBuildJsonBodyAndContentType():void {
		$r = Connector::build('POST', 'https://api.test/x', null, [], ['a' => 1]);
		$this->assertSame('{"a":1}', $r['body']);
		$this->assertContains('Content-Type: application/json', $r['headers']);
	}

	public function testBuildFormBodyAndContentType():void {
		$r = Connector::build('POST', 'https://api.test/x', null, [], null, ['a' => 'b c']);
		$this->assertSame('a=b+c', $r['body']);
		$this->assertContains('Content-Type: application/x-www-form-urlencoded', $r['headers']);
	}

	public function testBuildQueryJoinsExistingQueryString():void {
		$r = Connector::build('GET', 'https://api.test/x?z=1', ['a' => 2]);
		$this->assertSame('https://api.test/x?z=1&a=2', $r['url']);
	}

	// --- Normalized result contract -----------------------------------------

	public function testParseSuccessDecodesJson():void {
		$res = Connector::parse('{"id":7,"name":"x"}', 200);
		$this->assertTrue($res->ok);
		$this->assertSame(200, $res->status);
		$this->assertSame(7, $res->data->id);
	}

	public function testParseEmptyBodyIsOk():void {
		$res = Connector::parse('', 204);
		$this->assertTrue($res->ok);
		$this->assertSame('', $res->data);
	}

	public function testParseErrorShapes():void {
		$this->assertSame('bad token', Connector::parse('{"error":{"message":"bad token"}}', 401)->error);
		$this->assertSame('nope', Connector::parse('{"error":"nope"}', 400)->error);
		$this->assertSame('gone', Connector::parse('{"message":"gone"}', 404)->error);
		$this->assertSame('boom', Connector::parse('{"errors":["boom"]}', 422)->error);
		$this->assertSame('Server Error', Connector::parse('Server Error', 500)->error);
		$this->assertFalse(Connector::parse('{"error":"x"}', 400)->ok);
	}

	public function testAuthHelpers():void {
		$this->assertSame('Authorization: Bearer abc', Connector::bearer('abc'));
		$this->assertSame('Authorization: Basic '.base64_encode('u:p'), Connector::basic('u', 'p'));
	}

	// --- Per-connector base URLs and auth headers ---------------------------

	public function testConnectorBaseUrls():void {
		$this->assertSame('https://acme.myshopify.com/admin/api/2024-01', (new Shopify(['shop_domain' => 'acme.myshopify.com', 'access_token' => 't']))->base);
		$this->assertSame('https://api.lightspeedapp.com/API/V3/Account/9', (new Lightspeed(['cluster_id' => '9', 'api_key' => 'k', 'api_secret' => 's']))->base);
		$this->assertSame('https://api.telegram.org/bot42:ABC', (new Telegram(['bot_token' => '42:ABC']))->base);
		$this->assertSame('https://api.twilio.com/2010-04-01/Accounts/AC1', (new Twilio(['account_sid' => 'AC1', 'auth_token' => 't']))->base);
		$this->assertSame('https://moneybird.com/api/v2/999', (new Moneybird(['administration_id' => '999', 'access_token' => 't']))->base);
		$this->assertSame('https://graph.microsoft.com/v1.0', (new MicrosoftGraph(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']))->base);
	}

	public function testConnectorAuthHeaders():void {
		$this->assertSame(['X-Shopify-Access-Token: shpat_x'], (new Shopify(['shop_domain' => 'a', 'access_token' => 'shpat_x']))->headers);
		$this->assertSame(['Authorization: Basic '.base64_encode('k:s')], (new Lightspeed(['cluster_id' => '9', 'api_key' => 'k', 'api_secret' => 's']))->headers);
		$this->assertSame(['Authorization: Basic '.base64_encode('AC1:tok')], (new Twilio(['account_sid' => 'AC1', 'auth_token' => 'tok']))->headers);
		$this->assertSame(['Authorization: AccessKey k'], (new MessageBird(['access_key' => 'k', 'originator' => 'P']))->headers);
		$this->assertSame(['Authorization: Bearer re_x'], (new Resend(['api_key' => 're_x', 'from_email' => 'a@b.c']))->headers);
		$this->assertSame(['Authorization: Bearer tk'], (new Moneybird(['administration_id' => '9', 'access_token' => 'tk']))->headers);
	}

	// --- Credential guards (no network) -------------------------------------

	public function testUnconfiguredConnectorsFailClosed():void {
		$this->assertFalse((new Shopify([]))->orders()->ok);
		$this->assertFalse((new Lightspeed([]))->customers()->ok);
		$this->assertFalse((new Slack([]))->send('C', 'hi')->ok);
		$this->assertFalse((new Telegram([]))->send('1', 'hi')->ok);
		$this->assertFalse((new MessageBird([]))->sms('1', 'hi')->ok);
		$this->assertFalse((new Resend([]))->send('a@b.c', 'hi')->ok);
		$this->assertFalse((new Moneybird([]))->invoices()->ok);
		$this->assertStringContainsString('not configured', (string)(new Shopify([]))->orders()->error);
	}

	public function testTwilioRequiresSenderOrService():void {
		$res = (new Twilio(['account_sid' => 'AC1', 'auth_token' => 't']))->sms('+31', 'hi');
		$this->assertFalse($res->ok);
		$this->assertStringContainsString('from_number', (string)$res->error);
	}

	public function testMessageBirdErrorMessageOverride():void {
		$data = (object)['errors' => [(object)['description' => 'bad number']]];
		$this->assertSame('bad number', MessageBird::errorMessage($data, '', 422));
	}

	// --- OAuth2 token store --------------------------------------------------

	public function testTokenStoreRoundtripAndValidity():void {
		TokenStore::write('unit', ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_at' => time() + 3600]);
		$token = TokenStore::read('unit');
		$this->assertSame('AT', $token['access_token']);
		$this->assertTrue(TokenStore::valid($token));
		$this->assertFalse(TokenStore::valid(['access_token' => 'x', 'expires_at' => time() - 10]));
		$this->assertFalse(TokenStore::valid([]));
	}

	public function testTokenStoreReturnsCachedAccessTokenWithoutRefresh():void {
		TokenStore::write('cached', ['access_token' => 'CACHED', 'refresh_token' => 'RT', 'expires_at' => time() + 3600]);
		$this->assertSame('CACHED', TokenStore::access('cached', 'https://token', 'cid', 'sec'));
	}

	public function testTokenStoreSeedsRefreshTokenOnFirstUse():void {
		@unlink(TokenStore::path('seed'));
		$result = TokenStore::access('seed', '', 'cid', 'sec', ['refresh_token' => 'SEED']);
		$this->assertNull($result);
		$this->assertSame('SEED', TokenStore::read('seed')['refresh_token']);
	}

	public function testOAuthConnectorsUseStoredToken():void {
		TokenStore::write('ExactOnline', ['access_token' => 'EX', 'refresh_token' => 'RT', 'expires_at' => time() + 3600]);
		$exact = new ExactOnline(['division' => '111', 'client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'RT']);
		$this->assertSame('https://start.exactonline.nl/api/v1/111', $exact->base);
		$this->assertSame(['Authorization: Bearer EX'], $exact->headers);
		$this->assertNull($exact->guard);

		TokenStore::write('Google', ['access_token' => 'G', 'refresh_token' => 'RT', 'expires_at' => time() + 3600]);
		$cal = new GoogleCalendar(['client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'RT']);
		$sheets = new GoogleSheets(['client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'RT']);
		$this->assertSame('Google', $cal->oauthKey);
		$this->assertSame(['Authorization: Bearer G'], $cal->headers);
		$this->assertSame(['Authorization: Bearer G'], $sheets->headers, 'Google connectors share one stored token');
	}

	public function testOAuthConnectorFailsClosedWhenUnconfigured():void {
		$this->assertFalse((new ExactOnline([]))->invoices()->ok);
		$this->assertFalse((new GoogleSheets([]))->values('id', 'A1')->ok);
	}

	// --- Retry policy (pure, no network) ------------------------------------

	public function testRetryableOnlyForIdempotentMethods():void {
		$this->assertTrue(Connector::retryable('GET', 503));
		$this->assertTrue(Connector::retryable('GET', 429));
		$this->assertTrue(Connector::retryable('HEAD', 502));
		$this->assertFalse(Connector::retryable('GET', 404), 'non-retryable status');
		$this->assertFalse(Connector::retryable('GET', 200));
		$this->assertFalse(Connector::retryable('POST', 503), 'POST must never be auto-retried');
		$this->assertFalse(Connector::retryable('PATCH', 500));
		$this->assertFalse(Connector::retryable('PUT', 429));
	}

	public function testBackoffHonorsRetryAfterHeaderCappedAtThirtySeconds():void {
		$this->assertSame(2000000, Connector::backoff(1, obj(headers: ['retry-after' => '2'])));
		$this->assertSame(400000, Connector::backoff(2, obj(headers: [])), 'falls back to linear 200ms * attempt');
		$this->assertSame(30000000, Connector::backoff(1, obj(headers: ['retry-after' => '999'])), 'Retry-After capped at 30s');
	}
}
