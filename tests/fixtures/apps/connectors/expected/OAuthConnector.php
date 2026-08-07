<?php
// source:   %PHLO%/resources/connectors/OAuthConnector.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  %TEXT%
// advice:   %TEXT%
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector TokenStore
// tags:     oauth oauth2 connector base token refresh
class OAuthConnector extends Connector {
	public const tokenUrl = void;
	protected function oauthKey():string {
		return static::section;
	}
	protected function _token():?string {
		return TokenStore::access($this->oauthKey, static::tokenUrl, $this->config['client_id'] ?? void, $this->config['client_secret'] ?? void, ['refresh_token' => $this->config['refresh_token'] ?? null]);
	}
	protected function headers():array {
		return [static::bearer((string)$this->token)];
	}
	protected function authed():bool {
		return (string)$this->token !== void;
	}
}
