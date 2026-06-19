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
	foreach ($jobs AS $i => $job){
		[$cb, $args] = is_array($job) ? [$job[0], array_slice($job, 1)] : [$job, []];
		$cmd = cli.space.escapeshellarg($_SERVER['SCRIPT_FILENAME']).space.escapeshellarg($cb).loop($args, fn($a) => space.escapeshellarg((string)$a), void);
		$desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
		$proc = proc_open($cmd, $desc, $pipes);
		fclose($pipes[0]);
		$children[$i] = obj(proc: $proc, out: $pipes[1], err: $pipes[2]);
	}
	$results = [];
	foreach ($children AS $i => $child){
		$out = stream_get_contents($child->out);
		$err = stream_get_contents($child->err);
		fclose($child->out);
		fclose($child->err);
		$code = proc_close($child->proc);
		$err = trim($err);
		if ($err !== void){
			$ej = json_decode($err, true);
			$results[$i] = json_last_error() === JSON_ERROR_NONE ? $ej : $err;
			continue;
		}
		if ($code !== 0){
			$results[$i] = obj(error: 'CLI process failed', code: $code);
			continue;
		}
		$json = json_decode($out, true);
		$results[$i] = json_last_error() === JSON_ERROR_NONE ? $json : $out;
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

function HTTP(string $url, array $headers = [], bool $JSON = false, $POST = null, $PUT = null, $PATCH = null, bool $DELETE = false, ?string $agent = null){
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
	curl_setopt_array($curl, [CURLOPT_COOKIEFILE => data.'cookies.txt', CURLOPT_COOKIEJAR => data.'cookies.txt', CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_ENCODING => void]);
	$res = curl_exec($curl);
	if ($res === false) error('HTTP error: '.curl_error($curl));
	return $res;
}

