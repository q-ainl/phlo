<?php
// source:   %PHLO%/resources/connectors/chat/Resend.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Resend connector: send transactional email via the HTTP API
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector creds:Resend
// tags:     resend email transactional messaging connector
class Resend extends Connector {
	public const section = 'Resend';
	protected function base(){
		return 'https://api.resend.com';
	}
	protected function headers(){
		return [static::bearer($this->config['api_key'] ?? void)];
	}
	public static function fields(){
		return arr(
			section: 'Resend',
			config: arr(from_email: 'Default sender, e.g. "App <noreply@yourdomain.com>"'),
			secret: arr(api_key: 'API key (re_...)'),
		);
	}
	protected function send($to, $subject, $html = void, array $extra = []):obj {
		if ($m = $this->missing('api_key', 'from_email')) return $m;
		$email = ['from' => $this->config['from_email'], 'to' => is_array($to) ? $to : [$to], 'subject' => $subject];
		if ($html !== void) $email['html'] = $html;
		return $this->post('emails', $email + $extra);
	}
}
