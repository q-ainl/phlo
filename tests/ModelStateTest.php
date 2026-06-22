<?php
use PHPUnit\Framework\TestCase;

// Guards the worker-safety decision to keep per-request validation errors out of a
// static property. A static leaks validation state between requests in a persistent
// FrankenPHP worker; errors must live on the request-local model state (%req->model,
// reset every request), alongside records/loaded/meta.
final class ModelStateTest extends TestCase {

	public function testValidationErrorsAreRequestLocal():void {
		$src = (string)file_get_contents(engine.'resources/DB/model.phlo');
		$this->assertStringNotContainsString('static objLastErrors', $src, 'validation errors must not live in a worker-unsafe static');
		$this->assertStringContainsString('static::state()->errors', $src, 'errors must be stored on the request-local model state');
		$this->assertMatchesRegularExpression('/state\s*=>.*errors:/', $src, 'the request-local state object must initialise an errors bucket');
	}
}
