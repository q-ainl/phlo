<?php
// source:   %PHLO%/resources/connectors/cloud/GoogleCalendar.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Google Calendar connector (OAuth2): read events and create events
// extends:  OAuthConnector
// package:  connectors
// frontend: false
// backend:  true
// requires: @OAuthConnector creds:Google
// tags:     google calendar events oauth connector
class GoogleCalendar extends OAuthConnector {
	public const section = 'Google';
	public const tokenUrl = 'https://oauth2.googleapis.com/token';
	protected function base(){
		return 'https://www.googleapis.com/calendar/v3';
	}
	public static function fields(){
		return arr(
			section: 'Google',
			secret: arr(
				client_id: 'OAuth client ID',
				client_secret: 'OAuth client secret',
				refresh_token: 'OAuth refresh token (scopes: calendar, spreadsheets)',
			),
			scopes: 'https://www.googleapis.com/auth/calendar, https://www.googleapis.com/auth/spreadsheets',
		);
	}
	protected function guard(){
		return $this->missing('client_id', 'client_secret', 'refresh_token');
	}
	protected function events(string $calendarId = 'primary', array $query = []):obj {
		if ($m = $this->guard) return $m;
		return $this->get('calendars/'.rawurlencode($calendarId).'/events', $query);
	}
	protected function createEvent(array $event, string $calendarId = 'primary'):obj {
		if ($m = $this->guard) return $m;
		return $this->post('calendars/'.rawurlencode($calendarId).'/events', $event);
	}
}
