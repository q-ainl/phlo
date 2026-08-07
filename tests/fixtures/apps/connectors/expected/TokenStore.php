<?php
// source:   %PHLO%/resources/connectors/TokenStore.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Persisted OAuth2 token store with automatic refresh via the OAuth2 resource
// advice:   Tokens are kept as one file per key under data/tokens with 0600 rights, so a refresh survives a restart and every worker shares it. A refresh takes an exclusive lock, which matters with providers that rotate the refresh token: without it the losing request is left holding a dead one.
// package:  connectors
// frontend: false
// backend:  true
// requires: OAuth2
// tags:     oauth oauth2 token refresh store credentials
class TokenStore extends obj {
	public static function path($key):string {
		return data.'tokens/'.preg_replace('/[^a-z0-9_.-]+/i', us, (string)$key).'.json';
	}
	public static function read($key):array {
		$file = static::path($key);
		if (!is_file($file)) return [];
		@chmod(data.'tokens', 0700);
		@chmod($file, 0600);
		return (array)json_read($file, true);
	}
	public static function write($key, array $token):void {
		$dir = data.'tokens';
		is_dir($dir) || mkdir($dir, 0700, true);
		@chmod($dir, 0700);
		$file = static::path($key);
		json_write($file, $token);
		@chmod($file, 0600);
	}
	public static function valid(array $token):bool {
		return ($token['access_token'] ?? void) !== void && (int)($token['expires_at'] ?? 0) > time() + 30;
	}
	public static function store($res, $refresh):array {
		$token = [
			'access_token' => $res['access_token'],
			'refresh_token' => $res['refresh_token'] ?? $refresh,
			'expires_at' => time() + (int)($res['expires_in'] ?? 3600),
		];
		return $token;
	}
	// Takes an exclusive lock around one key's refresh cycle.
	// Without it concurrent callers each fire a refresh, and since that rotates the
	// refresh_token every loser is left holding a dead one.
	public static function lock($key){
		$dir = data.'tokens';
		is_dir($dir) || mkdir($dir, 0700, true);
		$lock = @fopen(static::path($key).'.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX)){
			$lock && fclose($lock);
			return null;
		}
		return $lock;
	}
	public static function access($key, $tokenUrl, $clientId, $clientSecret, array $seed = []):?string {
		$token = static::read($key);
		if (static::valid($token)) return $token['access_token'];
		$lock = static::lock($key);
		if (!$lock) return null;
		try {
			$token = static::read($key);
			if (!($token['refresh_token'] ?? null) && ($seed['refresh_token'] ?? null)){
				$token = ['refresh_token' => $seed['refresh_token']];
				static::write($key, $token);
			}
			if (static::valid($token)) return $token['access_token'];
			$refresh = $token['refresh_token'] ?? null;
			if (!$refresh || !$tokenUrl || !$clientId) return null;
			$res = OAuth2::refresh($tokenUrl, $clientId, $clientSecret, $refresh);
			if (!($res['access_token'] ?? null)) return null;
			$token = static::store($res, $refresh);
			static::write($key, $token);
			return $token['access_token'];
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
}
