<?php
// source:   %PHLO%/resources/security/OAuth2.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Stateless OAuth2 client: build the authorize URL and exchange/refresh tokens. Token storage and config are the caller's responsibility.
// package:  security
// frontend: false
// backend:  true
// requires: HTTP
// tags:     oauth oauth2 token authorization refresh authentication
class OAuth2 extends obj {
	public static function authorizeUrl($endpoint, array $params){
		return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query($params);
	}
	public static function token($tokenUrl, $clientId, $clientSecret, $grantType, array $extra = []){
		$body = ['grant_type' => $grantType, 'client_id' => (string)$clientId, 'client_secret' => (string)$clientSecret];
		foreach ($extra AS $key => $value){
			if ($value !== null && $value !== void) $body[$key] = $value;
		}
		try {
			$res = HTTP($tokenUrl, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], POST: http_build_query($body));
		}
		catch (\Throwable $e){
			return ['error' => $e->getMessage()];
		}
		return json_decode((string)$res, true) ?: ['error' => 'Invalid token response'];
	}
	public static function exchangeCode($tokenUrl, $clientId, $clientSecret, $code, $redirectUri = null, array $extra = []){
		return static::token($tokenUrl, $clientId, $clientSecret, 'authorization_code', ['code' => $code, 'redirect_uri' => $redirectUri] + $extra);
	}
	public static function refresh($tokenUrl, $clientId, $clientSecret, $refreshToken, array $extra = []){
		return static::token($tokenUrl, $clientId, $clientSecret, 'refresh_token', ['refresh_token' => $refreshToken] + $extra);
	}
}
