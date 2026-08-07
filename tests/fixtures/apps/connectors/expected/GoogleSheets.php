<?php
// source:   %PHLO%/resources/connectors/cloud/GoogleSheets.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Google Sheets connector (OAuth2): read ranges and append rows
// advice:   Shares the Google section with Calendar, so one refresh token has to carry both scopes. A range is A1 notation including the tab name, e.g. Sheet1!A:D. append() writes with USER_ENTERED, so the sheet parses what you send just as a typist would and a leading zero or a date-like string is reinterpreted; pass RAW when the value has to stay untouched.
// extends:  OAuthConnector
// package:  connectors
// frontend: false
// backend:  true
// requires: @OAuthConnector creds:Google
// tags:     google sheets spreadsheet oauth connector
class GoogleSheets extends OAuthConnector {
	public const section = 'Google';
	public const tokenUrl = 'https://oauth2.googleapis.com/token';
	protected function base():string {
		return 'https://sheets.googleapis.com/v4/spreadsheets';
	}
	public static function fields():array {
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
	protected function guard():?obj {
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
