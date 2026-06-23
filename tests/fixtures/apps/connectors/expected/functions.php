<?php

function HTTP(string $url, array $headers = [], bool $JSON = false, $POST = null, $PUT = null, $PATCH = null, bool $DELETE = false, ?string $agent = null, string|bool $cookies = false, int $timeout = 15, &$response = null){
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

