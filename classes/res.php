<?php

class res extends obj {
	public int $status = 200;
	public array $headers = [];
	public array $debug = [];
	public array $dump = [];
	public bool $outputted = false;
	public bool $done = false;
	public ?string $type = null;
	public ?string $body = null;
	public ?array $json = null;
	public bool $streaming = false;
	public array $titles = [];

	public function debug($msg):void {
		$this->debug[] = $msg;
	}

	public function header(string $key, $value):void {
		if (phlo('req')->cli || $this->outputted) return;
		$this->headers[$key] = $value;
	}

	public function json(mixed ...$data):static {
		$this->type = 'application/json';
		$this->body = json_encode($data ?: new \stdClass(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
		return $this;
	}

	public function text(string $body):static {
		$this->body = $body;
		return $this;
	}

	public function xml(string $body):static {
		$this->type = 'text/xml';
		$this->body = $body;
		return $this;
	}

	public function render(?int $code = null):void {
		if ($this->outputted) return;
		$this->outputted = true;
		$this->done = true;
		$req = phlo('req');
		$cli = $req->cli;
		$head = !$cli && $req->method === 'HEAD';
		if (!$cli && !headers_sent()){
			$httpCode = $code ?? ($this->status !== 200 ? $this->status : null);
			if ($httpCode) http_response_code($httpCode);
			if ($this->type) header('Content-Type: '.$this->type);
			if (!$this->streaming && !is_null($this->body)) header('Content-Length: '.strlen($this->body));
			foreach ($this->headers as $key => $value) header("$key: $value");
		}
		if (!is_null($this->body) && !$head) print($this->body);
	}
}
