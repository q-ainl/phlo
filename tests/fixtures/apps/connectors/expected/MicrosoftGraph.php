<?php
// source:   %PHLO%/resources/connectors/cloud/MicrosoftGraph.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Microsoft Graph connector (app-only client credentials): read users and calendars, send mail, create events
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector creds:Microsoft
// tags:     microsoft graph office365 calendar mail connector
class MicrosoftGraph extends Connector {
	public const section = 'Microsoft';
	protected function base(){
		return 'https://graph.microsoft.com/v1.0';
	}
	protected function headers(){
		return [static::bearer((string)$this->token)];
	}
	public static function fields(){
		return arr(
			section: 'Microsoft',
			config: arr(
				tenant_id: 'Azure AD tenant ID',
				client_id: 'App registration client ID',
				mailbox: 'Default mailbox / user UPN for calendar and mail (optional)',
			),
			secret: arr(client_secret: 'App registration client secret'),
			scopes: 'Application permissions: User.Read.All, Calendars.ReadWrite, Mail.Send',
		);
	}
	protected function _token(){
		return $this->fetchToken();
	}
	protected function fetchToken(){
		$tenant = $this->config['tenant_id'] ?? void;
		$id = $this->config['client_id'] ?? void;
		$secret = $this->config['client_secret'] ?? void;
		if ($tenant === void || $id === void || $secret === void) return void;
		$key = 'phlo:graph:'.$tenant.colon.$id;
		if (function_exists('apcu_fetch')){
			$cached = apcu_fetch($key);
			if ($cached) return $cached;
		}
		$res = $this->dispatch(static::build('POST', 'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token', null, [], null, ['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret, 'scope' => 'https://graph.microsoft.com/.default']));
		if (!$res->ok) return void;
		$token = $res->data->access_token ?? void;
		$expires = (int)($res->data->expires_in ?? 3600);
		if ($token !== void && function_exists('apcu_store')) apcu_store($key, $token, max(60, $expires - 60));
		return $token;
	}
	protected function mailbox($user = null){
		return $user ?? ($this->config['mailbox'] ?? void);
	}
	protected function users(array $query = []):obj {
		if ($m = $this->missing('tenant_id', 'client_id', 'client_secret')) return $m;
		return $this->get('users', $query);
	}
	protected function user($id):obj {
		if ($m = $this->missing('tenant_id', 'client_id', 'client_secret')) return $m;
		return $this->get('users/'.rawurlencode((string)$id));
	}
	protected function events($user = null, array $query = []):obj {
		if ($m = $this->missing('tenant_id', 'client_id', 'client_secret')) return $m;
		$mailbox = $this->mailbox($user);
		if ($mailbox === void) return static::fail('Microsoft mailbox required');
		return $this->get('users/'.rawurlencode((string)$mailbox).'/events', $query);
	}
	protected function sendMail($message, $user = null, bool $save = true):obj {
		if ($m = $this->missing('tenant_id', 'client_id', 'client_secret')) return $m;
		$mailbox = $this->mailbox($user);
		if ($mailbox === void) return static::fail('Microsoft mailbox required');
		return $this->post('users/'.rawurlencode((string)$mailbox).'/sendMail', ['message' => $message, 'saveToSentItems' => $save]);
	}
	protected function createEvent(array $event, $user = null):obj {
		if ($m = $this->missing('tenant_id', 'client_id', 'client_secret')) return $m;
		$mailbox = $this->mailbox($user);
		if ($mailbox === void) return static::fail('Microsoft mailbox required');
		return $this->post('users/'.rawurlencode((string)$mailbox).'/events', $event);
	}
}
