<?php
// source:   %PHLO%/resources/connectors/chat/Telegram.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Telegram Bot API connector: send messages, photos and documents; poll updates
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector creds:Telegram
// tags:     telegram bot messaging chat connector
class Telegram extends Connector {
	public const section = 'Telegram';
	protected function base(){
		return 'https://api.telegram.org/bot'.($this->config['bot_token'] ?? void);
	}
	public static function fields(){
		return arr(
			section: 'Telegram',
			secret: arr(
				bot_token: 'Bot token from BotFather',
				webhook_secret: 'Webhook secret token for inbound verification (optional)',
			),
			help: 'Create a bot via BotFather and store its token. The chat_id is the recipient or chat to message.',
		);
	}
	protected function result(obj $res):obj {
		if (!$res->ok) return $res;
		$data = $res->data;
		if (is_object($data) && ($data->ok ?? null) === false) return static::fail($data->description ?? 'Telegram API error', $res->status);
		return $res;
	}
	protected function send($chatId, $text, array $extra = []):obj {
		if ($m = $this->missing('bot_token')) return $m;
		return $this->result($this->post('sendMessage', ['chat_id' => $chatId, 'text' => $text] + $extra));
	}
	protected function photo($chatId, $photo, $caption = void, array $extra = []):obj {
		if ($m = $this->missing('bot_token')) return $m;
		$payload = ['chat_id' => $chatId, 'photo' => $photo];
		if ($caption !== void) $payload['caption'] = $caption;
		return $this->result($this->post('sendPhoto', $payload + $extra));
	}
	protected function document($chatId, $document, $caption = void, array $extra = []):obj {
		if ($m = $this->missing('bot_token')) return $m;
		$payload = ['chat_id' => $chatId, 'document' => $document];
		if ($caption !== void) $payload['caption'] = $caption;
		return $this->result($this->post('sendDocument', $payload + $extra));
	}
	protected function updates(int $offset = 0, int $limit = 100):obj {
		if ($m = $this->missing('bot_token')) return $m;
		return $this->result($this->get('getUpdates', ['offset' => $offset, 'limit' => $limit]));
	}
}
