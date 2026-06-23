<?php
// source:   %PHLO%/resources/connectors/chat/Twilio.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Twilio connector: send SMS and read message status
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector creds:Twilio
// tags:     twilio sms messaging connector
class Twilio extends Connector {
	public const section = 'Twilio';
	protected function base(){
		return 'https://api.twilio.com/2010-04-01/Accounts/'.($this->config['account_sid'] ?? void);
	}
	protected function headers(){
		return [static::basic($this->config['account_sid'] ?? void, $this->config['auth_token'] ?? void)];
	}
	public static function fields(){
		return arr(
			section: 'Twilio',
			config: arr(
				account_sid: 'Account SID (ACxxxx)',
				from_number: 'Sender number in E.164, e.g. +31600000000 (use this or messaging_service_sid)',
				messaging_service_sid: 'Messaging Service SID (optional alternative to from_number)',
			),
			secret: arr(auth_token: 'Auth token'),
		);
	}
	protected function sms($to, $body, array $extra = []):obj {
		if ($m = $this->missing('account_sid', 'auth_token')) return $m;
		$from = $this->config['from_number'] ?? void;
		$service = $this->config['messaging_service_sid'] ?? void;
		if ($from === void && $service === void) return static::fail('Twilio from_number or messaging_service_sid required');
		$fields = ['To' => $to, 'Body' => $body] + $extra;
		if ($service !== void) $fields['MessagingServiceSid'] = $service;
		else $fields['From'] = $from;
		return $this->form('Messages.json', $fields);
	}
	protected function message($sid):obj {
		if ($m = $this->missing('account_sid', 'auth_token')) return $m;
		return $this->get('Messages/'.$sid.'.json');
	}
}
