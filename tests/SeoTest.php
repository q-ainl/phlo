<?php
use PHPUnit\Framework\TestCase;

// The site-wide `indexable` constant gates robots.txt and the head alike: without it a host is
// noindex and carries no canonical. head() owns the rest of the SEO head (description, og:*,
// canonical), dropped per page by a noIndex flag or by an error status, with the twitter card opt-in.
final class SeoTest extends TestCase {

	private static function cli(string $entry, string ...$args):array {
		$proc = proc_open([PHP_BINARY, __DIR__.'/fixtures/seo/www/'.$entry, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	private static function http(string $url, array $headers = []):array {
		$context = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 5, 'ignore_errors' => true]]);
		$body    = (string)file_get_contents($url, false, $context);
		$status  = 0;
		foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)){ $status = (int)$m[1]; break; }
		return [$status, $body];
	}

	public static function setUpBeforeClass():void {
		[$code, $out, $err] = self::cli('app.php', 'build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	public function testRobotsDisallowWithoutIndexable():void {
		[$code, $out, $err] = self::cli('app.php', 'seo.robots');
		$this->assertSame(0, $code, $err);
		$r = json_decode(trim($out), true);
		$this->assertIsString($r, 'robots output not a string: '.$out);
		$this->assertStringContainsString('Disallow: /', $r);
		$this->assertStringNotContainsString('Allow: /', $r);
	}

	public function testRobotsAllowWithIndexable():void {
		[$code, $out, $err] = self::cli('app-indexed.php', 'seo.robots');
		$this->assertSame(0, $code, $err);
		$r = (string)json_decode(trim($out), true);
		$this->assertStringContainsString('Allow: /', $r);
		$this->assertStringContainsString('Sitemap:', $r);
	}

	public function testSitemapWithoutPagesOrLangs():void {
		// A single-page site declares neither: the sitemap must still list its root and skip the
		// hreflang alternates, instead of raising on a null foreach behind the URL robots advertises.
		$src = 'phlo(\'app\')->pages = null'."\n".'phlo(\'app\')->langs = null'."\n".'return [phlo(\'seo\')->sitemapPages, phlo(\'seo\')->sitemapLangs]';
		[$code, $out, $err] = self::cli('app-indexed.php', 'phlo_eval', $src);
		$this->assertSame(0, $code, $err);
		$r = json_decode(trim($out), true);
		$this->assertSame([''], $r[0] ?? null, "an app without pages still lists its root: $out");
		$this->assertSame([], $r[1] ?? null, "an app without langs gets no hreflang alternates: $out");
	}

	public function testHeadShape():void {
		[$code, $out, $err] = self::cli('app-indexed.php', 'seo.head');
		$this->assertSame(0, $code, $err);
		$h = json_decode(trim($out), true);
		$this->assertIsString($h, 'head output not a string: '.$out);
		$this->assertStringContainsString('name="description"', $h);
		$this->assertStringContainsString('A test description', $h);
		$this->assertStringContainsString('og:site_name', $h);
		$this->assertStringContainsString('SEO', $h);
		$this->assertStringContainsString('og:title', $h);
		$this->assertStringContainsString('og:description', $h);
		$this->assertStringContainsString('og:type', $h);
		$this->assertStringContainsString('website', $h);
		$this->assertStringContainsString('og:url', $h);
		$this->assertStringContainsString('og:image', $h);
		$this->assertStringContainsString('og:locale', $h);
		$this->assertStringContainsString('en_US', $h);
		$this->assertStringContainsString('canonical', $h);
		$this->assertStringNotContainsString('twitter', $h);
		$this->assertStringNotContainsString('noindex', $h);
	}

	public function testHeadWithoutIndexable():void {
		[$code, $out, $err] = self::cli('app.php', 'seo.head');
		$this->assertSame(0, $code, $err);
		$h = json_decode(trim($out), true);
		$this->assertIsString($h, 'head output not a string: '.$out);
		$this->assertStringContainsString('noindex', $h);
		$this->assertStringNotContainsString('canonical', $h);
	}

	// Over real HTTP on purpose: view() used to set the response status after the head had already
	// been assembled, so the resource could not see it. Setting the status by hand in a fixture would
	// pass either way, which is the regression this has to catch.
	public function testErrorStatusDropsTheIndexOverHttp():void {
		$port   = 8930 + (getmypid() % 1000);
		$server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/fixtures/seo/www/app-indexed.php'],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
			__DIR__.'/fixtures/seo/www'
		);
		$this->assertIsResource($server);
		try {
			$up = false;
			for ($i = 0; $i < 50 && !$up; ++$i){
				usleep(100_000);
				$sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
				if ($sock){ fclose($sock); $up = true; }
			}
			$this->assertTrue($up, 'php -S did not come up on port '.$port);

			[$ok, $okBody] = self::http("http://127.0.0.1:$port/");
			$this->assertSame(200, $ok);
			$this->assertStringContainsString('canonical', $okBody, 'an ordinary page on an indexable host keeps its canonical');
			$this->assertStringNotContainsString('noindex', $okBody);

			[$gone, $goneBody] = self::http("http://127.0.0.1:$port/gone");
			$this->assertSame(404, $gone);
			$this->assertStringContainsString('noindex', $goneBody, 'view(code: 404) is noindex without the app saying so');
			$this->assertStringNotContainsString('canonical', $goneBody);

			// The apply transport stays 200. A status on an async reply makes the request look
			// failed to everything between the browser and the app, while the client reads the
			// body either way, so view() hands the code to the page and not to the transport.
			[$asyncCode, $asyncBody] = self::http("http://127.0.0.1:$port/gone", ['X-Requested-With: phlo']);
			$this->assertSame(200, $asyncCode, 'an async reply carries no error status');
			$this->assertNotSame('', trim($asyncBody), 'and it carries a body');
		}
		finally {
			proc_terminate($server);
			proc_close($server);
		}
	}
}
