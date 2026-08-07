<?php
// source:   %PHLO%/resources/connectors/chat/Resend.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Resend connector: send transactional email via the HTTP API
// advice:   Needs api_key and a from_email on a domain you verified with Resend, otherwise the send is refused. send() takes HTML; leave it out for a plain text mail and add anything else Resend accepts through extra.
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector creds:Resend
// tags:     resend email transactional messaging connector
class Resend extends Connector {
	public const section = 'Resend';
	protected function base():string {
		return 'https://api.resend.com';
	}
	protected function headers():array {
		return [static::bearer($this->config['api_key'] ?? void)];
	}
	public static function fields():array {
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
