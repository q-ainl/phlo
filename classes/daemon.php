<?php
// Optional daemon client. Loaded by phlo_app() only when the `daemon` constant (the daemon port) is
// set, so apps that do not use the daemon never load this. The runtime helpers (phlo_sync /
// phlo_async / await / phlo_stream) route through here when `daemon` is set, and fall back to a
// one-shot subprocess otherwise. Targets are dispatched by the app's own path, so no host map.

class daemon {
	static function url(){
		return 'http://127.0.0.1:'.daemon;
	}

	static function app(){
		return realpath($_SERVER['SCRIPT_FILENAME']);
	}

	// Best-effort: tell the daemon which app serves this host, so websockets for the host route here.
	// Persisted by the daemon, so it survives a reboot; throttled so it fires about once a minute.
	static function register(){
		if (!host) return;
		$key = 'daemon:registered:'.host;
		if (function_exists('apcu_fetch') && apcu_fetch($key)) return;
		$ctx = stream_context_create(['http' => [
			'method'        => 'POST',
			'header'        => 'Content-Type: application/json',
			'content'       => json_encode(['host' => host, 'app' => self::app(), 'build' => build]),
			'timeout'       => 2,
			'ignore_errors' => true,
		]]);
		$res = @file_get_contents(self::url().'/register', false, $ctx);
		if ($res !== false && function_exists('apcu_store')) apcu_store($key, 1, 60);
	}

	// POST a target to the pool and return the decoded {status, result} body.
	static function post(string $path, array $body):array {
		$ctx = stream_context_create(['http' => [
			'method'        => 'POST',
			'header'        => 'Content-Type: application/json',
			'content'       => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'timeout'       => 30,
			'ignore_errors' => true,
		]]);
		$raw = file_get_contents(self::url().$path, false, $ctx);
		if ($raw === false) error('Phlo daemon unreachable at '.self::url());
		$res = json_decode($raw, true);
		if (!is_array($res) || ($res['status'] ?? null) !== 'ok') error('Daemon dispatch failed: '.($res['message'] ?? 'unknown'));
		return $res;
	}

	// Run a target and return its result.
	static function run(string $target, array $args = []){
		return self::post('/dispatch', ['app' => self::app(), 'target' => $target, 'args' => $args])['result'] ?? null;
	}

	// Queue a target fire-and-forget; returns once accepted, not once run.
	static function fire(string $target, array $args = []):bool {
		self::post('/dispatch', ['app' => self::app(), 'target' => $target, 'args' => $args, 'async' => true]);
		return true;
	}

	// Run many jobs concurrently on the pool and collect their results (drives await()).
	static function await(array $jobs):array {
		$mh = curl_multi_init();
		$handles = [];
		foreach ($jobs as $i => $job){
			[$cb, $args] = is_array($job) ? [$job[0], array_slice($job, 1)] : [$job, []];
			$ch = curl_init(self::url().'/dispatch');
			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
				CURLOPT_POSTFIELDS => json_encode(['app' => self::app(), 'target' => $cb, 'args' => array_values($args)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			]);
			curl_multi_add_handle($mh, $ch);
			$handles[$i] = $ch;
		}
		$running = null;
		do {
			curl_multi_exec($mh, $running);
			if ($running) curl_multi_select($mh, 1);
		} while ($running > 0);
		$results = [];
		foreach ($handles as $i => $ch){
			$res = json_decode(curl_multi_getcontent($ch), true);
			curl_multi_remove_handle($mh, $ch);
			$results[$i] = is_array($res) && ($res['status'] ?? null) === 'ok' ? ($res['result'] ?? null) : obj(error: $res['message'] ?? 'await dispatch failed');
		}
		curl_multi_close($mh);
		return $results;
	}

	// Stream a target's output line by line from the pool (drives phlo_stream).
	static function stream(string $target, array $args = []){
		$ctx = stream_context_create(['http' => [
			'method'        => 'POST',
			'header'        => 'Content-Type: application/json',
			'content'       => json_encode(['app' => self::app(), 'target' => $target, 'args' => $args, 'stream' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'timeout'       => 300,
			'ignore_errors' => true,
		]]);
		$fp = fopen(self::url().'/dispatch', 'r', false, $ctx);
		if (!$fp) error('Phlo daemon unreachable at '.self::url());
		while (($line = fgets($fp)) !== false){
			$line = trim($line);
			if ($line === '') continue;
			$frame = json_decode($line, true);
			if (!is_array($frame)) continue;
			if (($frame['t'] ?? null) === 'line') yield obj(data: $frame['data'] ?? '');
			elseif (($frame['t'] ?? null) === 'error') yield obj(data: $frame['message'] ?? 'error', error: true);
		}
		fclose($fp);
	}
}
