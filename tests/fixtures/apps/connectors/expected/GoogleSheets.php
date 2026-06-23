<?php
// source:   %PHLO%/resources/connectors/cloud/GoogleSheets.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Google Sheets connector (OAuth2): read ranges and append rows
// extends:  OAuthConnector
// package:  connectors
// frontend: false
// backend:  true
// requires: @OAuthConnector creds:Google
// tags:     google sheets spreadsheet oauth connector
class GoogleSheets extends OAuthConnector {
	public const section = 'Google';
	public const tokenUrl = 'https://oauth2.googleapis.com/token';
	protected function base(){
		return 'https://sheets.googleapis.com/v4/spreadsheets';
	}
	public static function fields(){
		return arr(
			section: 'Google',
			secret: arr(
				client_id: 'OAuth client ID',
				client_secret: 'OAuth client secret',
				refresh_token: 'OAuth refresh token (scopes: calendar, spreadsheets)',
			),
			scopes: 'https://www.googleapis.com/auth/spreadsheets',
		);
	}
	protected function guard(){
		return $this->missing('client_id', 'client_secret', 'refresh_token');
	}
	protected function values($spreadsheetId, string $range):obj {
		if ($m = $this->guard) return $m;
		return $this->get($spreadsheetId.'/values/'.rawurlencode($range));
	}
	protected function append($spreadsheetId, string $range, array $rows, string $valueInputOption = 'USER_ENTERED'):obj {
		if ($m = $this->guard) return $m;
		return $this->post($spreadsheetId.'/values/'.rawurlencode($range).':append?valueInputOption='.$valueInputOption, ['values' => $rows]);
	}
}
