<?php
namespace phlo;

class res extends obj {
	public array $headers = [];
	public array $debug = [];
	public array $dump = [];
	public bool $outputted = false;
	public ?string $type = null;
	public ?string $body = null;
	public ?array $json = null;
	public bool $streaming = false;

	public function debug($msg):void {
		$this->debug[] = $msg;
	}

	public function dump($data):void {
		$this->dump[] = $data;
	}

	public function header(string $key, $value):void {
		if (phlo('req')->cli || $this->outputted) return;
		$this->headers[$key] = $value;
	}

	public function render(?int $code = null):void {
		if ($this->outputted) return;
		$this->outputted = true;
		$cli = phlo('req')->cli;
		if (!$cli && !\headers_sent()){
			$code && \http_response_code($code);
			$this->type && \header('Content-Type: '.$this->type);
			foreach ($this->headers AS $key => $value) \header("$key: $value");
		}
		is_null($this->body) || print($this->body);
	}
}
