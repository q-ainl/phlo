<?php
// source:   %PHLO%/resources/connectors/chat/MessageBird.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  MessageBird connector: send SMS
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector creds:MessageBird
// tags:     messagebird sms messaging connector
class MessageBird extends Connector {
	public const section = 'MessageBird';
	protected function base(){
		return 'https://rest.messagebird.com';
	}
	protected function headers(){
		return ['Authorization: AccessKey '.($this->config['access_key'] ?? void)];
	}
	public static function fields(){
		return arr(
			section: 'MessageBird',
			config: arr(originator: 'Originator (sender number or name)'),
			secret: arr(access_key: 'Access key'),
		);
	}
	public static function errorMessage($data, string $raw, int $status):string {
		if (is_object($data) && isset($data->errors[0]->description)) return (string)$data->errors[0]->description;
		return parent::errorMessage($data, $raw, $status);
	}
	protected function sms($to, $body, array $extra = []):obj {
		if ($m = $this->missing('access_key', 'originator')) return $m;
		$recipients = is_array($to) ? $to : [$to];
		return $this->post('messages', ['originator' => $this->config['originator'], 'recipients' => $recipients, 'body' => $body] + $extra);
	}
}
