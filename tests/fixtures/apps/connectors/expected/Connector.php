<?php
// source:   %PHLO%/resources/connectors/Connector.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Base class for API connectors: credentials, JSON requests, retries, pagination and a normalized result contract
// package:  connectors
// frontend: false
// backend:  true
// requires: creds HTTP
// tags:     api connector http rest base
class Connector extends obj {
	public const section = void;
	public const api = void;
	public function __construct(?array $config = null){
		if ($config === null){
			$section = static::section;
			$creds = $section ? phlo('creds')->{$section} : null;
			$config = $creds ? (array)$creds->toArray : [];
		}
		$this->config = $config;
		$this->timeout = 15;
		$this->retries = 0;
	}
	public static function make(?array $config = null):static {
		return new static($config);
	}
	protected function base(){
		return static::api;
	}
	protected function headers(){
		return [];
	}
	public static function fields(){
		return [];
	}
	protected function configured(...$keys):bool {
		foreach ($keys AS $key){
			if (($this->config[$key] ?? void) === void) return false;
		}
		return true;
	}
	protected function missing(...$keys):?obj {
		return $this->configured(...$keys) ? null : static::fail(static::section.' credentials not configured ('.implode(', ', $keys).')');
	}
	public static function bearer($token):string {
		return 'Authorization: Bearer '.$token;
	}
	public static function basic($user, $pass):string {
		return 'Authorization: Basic '.base64_encode($user.colon.$pass);
	}
	public static function build(string $method, string $url, ?array $query = null, array $headers = [], mixed $json = null, mixed $form = null):array {
		if ($query) $url .= (str_contains($url, qm) ? '&' : qm).http_build_query($query);
		$body = null;
		if ($json !== null){
			$body = is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$headers[] = 'Content-Type: application/json';
		}
		elseif ($form !== null){
			$body = is_string($form) ? $form : http_build_query($form);
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		}
		$headers[] = 'Accept: application/json';
		return ['method' => strtoupper($method), 'url' => $url, 'headers' => $headers, 'body' => $body];
	}
	public static function ok($data, int $status = 200):obj {
		return obj(ok: true, status: $status, data: $data);
	}
	public static function fail($error, int $status = 0):obj {
		return obj(ok: false, status: $status, error: $error);
	}
	public static function errorMessage($data, string $raw, int $status):string {
		if (is_object($data)){
			if (isset($data->error->message)) return (string)$data->error->message;
			if (isset($data->error) && is_string($data->error)) return $data->error;
			if (isset($data->message)) return (string)$data->message;
			if (isset($data->errors)){
				$errors = $data->errors;
				if (is_string($errors)) return $errors;
				if (is_array($errors)) return is_string($errors[0] ?? null) ? $errors[0] : json_encode($errors);
				if (is_object($errors)) return json_encode($errors);
			}
		}
		return $raw !== void ? $raw : 'HTTP '.$status;
	}
	public static function parse($raw, int $status = 200):obj {
		$raw = (string)$raw;
		$data = $raw === void ? null : json_decode($raw);
		if ($status < 200 || $status >= 300) return static::fail(static::errorMessage($data, $raw, $status), $status);
		return obj(ok: true, status: $status, data: $data ?? $raw);
	}
	public static function retryable($method, int $status):bool {
		return in_array($method, ['GET', 'HEAD']) && ($status === 429 || $status >= 500);
	}
	public static function backoff(int $attempt, $response):int {
		$after = (int)($response->headers['retry-after'] ?? 0);
		return $after > 0 ? min($after, 30) * 1000000 : 200000 * $attempt;
	}
	protected function dispatch(array $req):obj {
		$method = $req['method'];
		$body = $req['body'];
		$attempt = 0;
		$response = null;
		while (true){
			try {
				if ($method === 'GET') $raw = HTTP($req['url'], $req['headers'], cookies: false, timeout: $this->timeout, response: $response);
				elseif ($method === 'DELETE') $raw = HTTP($req['url'], $req['headers'], DELETE: true, cookies: false, timeout: $this->timeout, response: $response);
				elseif ($method === 'PUT') $raw = HTTP($req['url'], $req['headers'], PUT: $body ?? void, cookies: false, timeout: $this->timeout, response: $response);
				elseif ($method === 'PATCH') $raw = HTTP($req['url'], $req['headers'], PATCH: $body ?? void, cookies: false, timeout: $this->timeout, response: $response);
				else $raw = HTTP($req['url'], $req['headers'], POST: $body ?? void, cookies: false, timeout: $this->timeout, response: $response);
			}
			catch (\Throwable $e){
				return static::fail($e->getMessage(), 0);
			}
			$status = $response->status ?? 0;
			if (($status >= 200 && $status < 300) || !static::retryable($method, $status) || $attempt >= $this->retries) break;
			usleep(static::backoff(++$attempt, $response));
		}
		$result = static::parse($raw, $status);
		$result->headers = $response->headers ?? [];
		return $result;
	}
	protected function request(string $method, string $url, ?array $query = null, array $headers = [], mixed $json = null, mixed $form = null):obj {
		if (!str_starts_with($url, 'http')) $url = rtrim((string)$this->base, slash).slash.ltrim($url, slash);
		$headers = array_merge((array)$this->headers, $headers);
		return $this->dispatch(static::build($method, $url, $query, $headers, $json, $form));
	}
	protected function get(string $url, ?array $query = null, array $headers = []):obj {
		return $this->request('GET', $url, query: $query, headers: $headers);
	}
	protected function post(string $url, mixed $json = null, array $headers = []):obj {
		return $this->request('POST', $url, headers: $headers, json: $json);
	}
	protected function put(string $url, mixed $json = null, array $headers = []):obj {
		return $this->request('PUT', $url, headers: $headers, json: $json);
	}
	protected function patch(string $url, mixed $json = null, array $headers = []):obj {
		return $this->request('PATCH', $url, headers: $headers, json: $json);
	}
	protected function del(string $url, array $headers = []):obj {
		return $this->request('DELETE', $url, headers: $headers);
	}
	protected function form(string $url, array $fields, array $headers = []):obj {
		return $this->request('POST', $url, headers: $headers, form: $fields);
	}
	protected function paginate(string $url, callable $extract, ?array $query = null, string $param = 'page', int $start = 1, int $max = 0):array {
		$items = [];
		$page = $start;
		while (true){
			$res = $this->get($url, ($query ?? []) + [$param => $page]);
			if (!$res->ok) break;
			$batch = $extract($res->data);
			if (!$batch) break;
			foreach ($batch AS $item) $items[] = $item;
			if ($max && count($items) >= $max) break;
			$page++;
		}
		return $items;
	}
}
