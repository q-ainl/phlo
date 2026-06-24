<?php

function phlo_sync(string $cb, ...$args){
	if (daemon) return daemon::run($cb, $args);
	$cmd = cli.space.escapeshellarg($_SERVER['SCRIPT_FILENAME']).space.escapeshellarg($cb).loop($args, fn($a) => space.escapeshellarg((string)$a), void);
	exec($cmd.' 2>&1', $r, $code);
	$out = implode(lf, $r);
	if ($code !== 0) error('Could not execute "'.esc($cb).'" via CLI');
	$j = json_decode($out, true);
	if (json_last_error() !== JSON_ERROR_NONE) return $out;
	if (is_array($j) && isset($j['error'])) error($j['error']);
	return $j;
}

function await(...$jobs){
	if (daemon) return daemon::await($jobs);
	$children = [];
	$open = [];
	foreach ($jobs AS $i => $job){
		[$cb, $args] = is_array($job) ? [$job[0], array_slice($job, 1)] : [$job, []];
		$cmd = cli.space.escapeshellarg($_SERVER['SCRIPT_FILENAME']).space.escapeshellarg($cb).loop($args, fn($a) => space.escapeshellarg((string)$a), void);
		$desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
		$proc = proc_open($cmd, $desc, $pipes);
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$children[$i] = obj(proc: $proc, out: $pipes[1], err: $pipes[2], stdout: void, stderr: void);
		$open['o'.$i] = $pipes[1];
		$open['e'.$i] = $pipes[2];
	}
	// Drain every child's stdout AND stderr together: reading one stream to EOF before the;
	// other deadlocks a child that fills the unread pipe. Bound the whole wait so a hung;
	// child cannot block the caller forever.
	$deadline = time() + 30;
	while ($open){
		$read = $open;
		$write = $except = [];
		if (@stream_select($read, $write, $except, 1) === false) break;
		foreach ($read AS $key => $stream){
			$chunk = fread($stream, 65536);
			if ($chunk === void || $chunk === false){
				feof($stream) && $open = array_diff_key($open, [$key => 1]);
				continue;
			}
			$i = (int)substr($key, 1);
			if ($key[0] === 'o') $children[$i]->stdout .= $chunk;
			else $children[$i]->stderr .= $chunk;
		}
		if (time() >= $deadline){
			foreach ($children AS $child) (proc_get_status($child->proc)['running'] ?? false) && proc_terminate($child->proc);
			break;
		}
	}
	$results = [];
	foreach ($children AS $i => $child){
		fclose($child->out);
		fclose($child->err);
		$code = proc_close($child->proc);
		$err = trim($child->stderr);
		if ($err !== void){
			$ej = json_decode($err, true);
			$results[$i] = json_last_error() === JSON_ERROR_NONE ? $ej : $err;
			continue;
		}
		if ($code !== 0){
			$results[$i] = obj(error: 'CLI process failed', code: $code);
			continue;
		}
		$json = json_decode($child->stdout, true);
		$results[$i] = json_last_error() === JSON_ERROR_NONE ? $json : $child->stdout;
	}
	return $results;
}

function wsCast($wsTarget = 'all', $wsHost = host, $wsPort = daemon, ...$data){
	return HTTP (
		'http://127.0.0.1:'.$wsPort.'/message',
		JSON: true,
		POST: arr (
			host: $wsHost,
			target: $wsTarget,
			data: $data,
		),
	);
}

function HTTP(string $url, array $headers = [], bool $JSON = false, $POST = null, $PUT = null, $PATCH = null, bool $DELETE = false, string|bool|null $agent = null, string|bool $cookies = false, int $timeout = 15, &$response = null){
	$curl = curl_init($url);
	if ($POST !== null || $PUT !== null || $PATCH !== null){
		if (!is_null($POST)) [$method = 'POST', $content = $POST];
		elseif (!is_null($PUT)) [$method = 'PUT', $content = $PUT];
		elseif (!is_null($PATCH)) [$method = 'PATCH', $content = $PATCH];
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		if ($JSON) [!is_string($content) && $content = json_encode($content), array_push($headers, 'Content-Type: application/json', 'Content-Length: '.strlen($content))];
		curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
	}
	elseif ($DELETE) curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
	$agent && curl_setopt($curl, CURLOPT_USERAGENT, $agent === true ? phlo('req')->userAgent : $agent);
	if ($cookies !== false) [$jar = $cookies === true ? data.'cookies.txt' : $cookies, curl_setopt($curl, CURLOPT_COOKIEFILE, $jar), curl_setopt($curl, CURLOPT_COOKIEJAR, $jar)];
	$resHeaders = [];
	curl_setopt_array($curl, [CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_ENCODING => void, CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$resHeaders){
		$parts = explode(colon, $line, 2);
		count($parts) === 2 && $resHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
		return strlen($line);
	}]);
	$res = curl_exec($curl);
	$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
	$response = obj(ok: $res !== false && $status >= 200 && $status < 300, status: $status, headers: $resHeaders, error: $res === false ? curl_error($curl) : null);
	if ($res === false) error('HTTP error: '.curl_error($curl));
	return $res;
}

