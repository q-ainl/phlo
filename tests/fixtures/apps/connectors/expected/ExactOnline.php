<?php
// source:   %PHLO%/resources/connectors/finance/ExactOnline.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  %TEXT%
// advice:   %TEXT%
// extends:  OAuthConnector
// package:  connectors
// frontend: false
// backend:  true
// requires: @OAuthConnector creds:ExactOnline
// tags:     exact exactonline accounting invoices oauth connector
class ExactOnline extends OAuthConnector {
	public const section = 'ExactOnline';
	public const tokenUrl = 'https://start.exactonline.nl/api/oauth2/token';
	protected function base():string {
		return 'https://start.exactonline.nl/api/v1/'.($this->config['division'] ?? void);
	}
	public static function fields():array {
		return arr(
			section: 'ExactOnline',
			config: arr(division: 'Division (administration) number'),
			secret: arr(
				client_id: 'OAuth client ID',
				client_secret: 'OAuth client secret',
				refresh_token: 'OAuth refresh token (managed and rotated after first authorization)',
			),
			scopes: 'OAuth2 authorization code flow; token endpoint refreshes automatically',
		);
	}
	protected function guard():?obj {
		return $this->missing('division', 'client_id', 'client_secret', 'refresh_token');
	}
	protected function invoices(array $query = []):obj {
		if ($m = $this->guard) return $m;
		return $this->get('salesinvoice/SalesInvoices', $query);
	}
	protected function accounts(array $query = []):obj {
		if ($m = $this->guard) return $m;
		return $this->get('crm/Accounts', $query);
	}
	protected function createInvoice(array $invoice):obj {
		if ($m = $this->guard) return $m;
		return $this->post('salesinvoice/SalesInvoices', $invoice);
	}
}
