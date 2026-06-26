<?php
use PHPUnit\Framework\TestCase;

// Proves the AI layer without any API credentials: a fixture replaces AI::http with a stub that
// records the outgoing request and returns a canned per-provider response. So we can check that
// each model builds the right endpoint + request body and parses its own response shape into the
// uniform obj(answer, model, finish, tokens_*), plus the facade's model -> engine resolver.
final class AiTest extends TestCase {

	private static string $entry = __DIR__.'/fixtures/ai/www/app.php';

	private static function cli(string ...$args):array {
		$proc = proc_open([PHP_BINARY, self::$entry, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out  = (string)stream_get_contents($pipes[1]);
		$err  = (string)stream_get_contents($pipes[2]);
		return [proc_close($proc), $out, $err];
	}

	private static function fetch(string $target):array {
		[$code, $out, $err] = self::cli($target);
		self::assertSame(0, $code, "$target failed:\n$out$err");
		$r = json_decode(trim($out), true);
		self::assertIsArray($r, "no JSON from $target: $out");
		return $r;
	}

	public static function setUpBeforeClass():void {
		[$code, $out, $err] = self::cli('build::run');
		self::assertSame(0, $code, "build::run failed:\n$out$err");
	}

	public function testResolverMapsModelPrefixToEngine():void {
		$this->assertSame(
			['claude' => 'Claude', 'gpt' => 'OpenAI', 'gemini' => 'Gemini', 'deepseek' => 'DeepSeek', 'grok' => 'Grok', 'fallback' => 'OpenAI'],
			self::fetch('aiprobe::resolveCases'),
			'the model name prefix must route to the right engine, defaulting to OpenAI'
		);
	}

	public function testClaudeBuildsRequestAndParsesResponse():void {
		$r = self::fetch('aiprobe::runClaude');
		$this->assertStringContainsString('api.anthropic.com/v1/messages', $r['url'], 'Claude messages endpoint');
		$this->assertStringContainsString('Hello Claude', $r['post'], 'the user message is in the request body');
		$this->assertSame('Claude reply', $r['answer'], 'the text content block is parsed into answer');
		$this->assertSame('claude-test', $r['model']);
		$this->assertSame('end_turn', $r['finish'], 'stop_reason maps to finish');
		$this->assertSame(11, $r['in']);
		$this->assertSame(7, $r['out']);
	}

	public function testOpenAiBuildsRequestAndParsesResponse():void {
		$r = self::fetch('aiprobe::runOpenAI');
		$this->assertStringContainsString('api.openai.com/v1/chat/completions', $r['url'], 'OpenAI chat endpoint');
		$this->assertStringContainsString('Hello OpenAI', $r['post']);
		$this->assertSame('OpenAI reply', $r['answer'], 'choices[0].message.content is parsed into answer');
		$this->assertSame('gpt-test', $r['model']);
		$this->assertSame('stop', $r['finish']);
		$this->assertSame(13, $r['in']);
		$this->assertSame(9, $r['out']);
	}

	public function testGeminiBuildsRequestAndParsesResponse():void {
		$r = self::fetch('aiprobe::runGemini');
		$this->assertStringContainsString('generativelanguage.googleapis.com', $r['url'], 'Gemini endpoint');
		$this->assertStringContainsString('generateContent', $r['url']);
		$this->assertStringContainsString('Hello Gemini', $r['post']);
		$this->assertSame('Gemini reply', $r['answer'], 'candidates[0].content.parts[].text is parsed into answer');
		$this->assertSame('STOP', $r['finish']);
		$this->assertSame(13, $r['in']);
		$this->assertSame(9, $r['out']);
	}
}
