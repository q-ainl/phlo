<?php
// source:   %PHLO%/resources/connectors/chat/Slack.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Slack connector: post messages, read channel history and list channels
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector creds:Slack
// tags:     slack messaging chat connector
class Slack extends Connector {
	public const section = 'Slack';
	public const api = 'https://slack.com/api';
	protected function headers(){
		return [static::bearer($this->config['bot_token'] ?? void)];
	}
	public static function fields(){
		return arr(
			section: 'Slack',
			secret: arr(
				bot_token: 'Bot user OAuth token (xoxb-...)',
				signing_secret: 'Signing secret for inbound webhook verification (optional)',
			),
			scopes: 'chat:write, channels:history, channels:read',
		);
	}
	protected function result(obj $res):obj {
		if (!$res->ok) return $res;
		$data = $res->data;
		if (is_object($data) && ($data->ok ?? null) === false) return static::fail($data->error ?? 'Slack API error', $res->status);
		return $res;
	}
	protected function send($channel, $text, array $extra = []):obj {
		if (!$this->configured('bot_token')) return static::fail('Slack bot_token not configured');
		return $this->result($this->post('chat.postMessage', ['channel' => $channel, 'text' => $text] + $extra));
	}
	protected function history($channel, int $limit = 20):obj {
		if (!$this->configured('bot_token')) return static::fail('Slack bot_token not configured');
		return $this->result($this->get('conversations.history', ['channel' => $channel, 'limit' => $limit]));
	}
	protected function channels(int $limit = 100, string $types = 'public_channel'):obj {
		if (!$this->configured('bot_token')) return static::fail('Slack bot_token not configured');
		return $this->result($this->get('conversations.list', ['limit' => $limit, 'types' => $types]));
	}
}
