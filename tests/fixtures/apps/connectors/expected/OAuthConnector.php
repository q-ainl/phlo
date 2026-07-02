<?php
// source:   %PHLO%/resources/connectors/OAuthConnector.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Base class for OAuth2 connectors: stored, auto-refreshed bearer access tokens via TokenStore, on the OAuth2 primitive
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector TokenStore
// tags:     oauth oauth2 connector base token refresh
class OAuthConnector extends Connector {
	public const tokenUrl = void;
	protected function oauthKey(){
		return static::section;
	}
	protected function _token(){
		return TokenStore::access($this->oauthKey, static::tokenUrl, $this->config['client_id'] ?? void, $this->config['client_secret'] ?? void, ['refresh_token' => $this->config['refresh_token'] ?? null]);
	}
	protected function headers(){
		return [static::bearer((string)$this->token)];
	}
	protected function authed(){
		return (string)$this->token !== void;
	}
}
