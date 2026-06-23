<?php
// source:   %PHLO%/resources/connectors/shops/Lightspeed.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Lightspeed Retail (V3) connector: read customers and sales, create customers
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector creds:Lightspeed
// tags:     lightspeed webshop retail pos customers connector
class Lightspeed extends Connector {
	public const section = 'Lightspeed';
	protected function base(){
		return 'https://api.lightspeedapp.com/API/V3/Account/'.($this->config['cluster_id'] ?? void);
	}
	protected function headers(){
		return [static::basic($this->config['api_key'] ?? void, $this->config['api_secret'] ?? void)];
	}
	public static function fields(){
		return arr(
			section: 'Lightspeed',
			config: arr(
				cluster_id: 'Account / cluster ID',
				language: 'Language (optional, default nl)',
			),
			secret: arr(
				api_key: 'API key',
				api_secret: 'API secret',
			),
			scopes: 'Customer read/write, Sale read',
		);
	}
	protected function customers(array $query = []):obj {
		if ($m = $this->missing('cluster_id', 'api_key', 'api_secret')) return $m;
		return $this->get('Customer.json', $query);
	}
	protected function findCustomer($participant):obj {
		if ($m = $this->missing('cluster_id', 'api_key', 'api_secret')) return $m;
		$field = str_contains((string)$participant, '@') ? 'Email' : 'Phone';
		return $this->get('Customer.json', [$field => $participant, 'limit' => 1]);
	}
	protected function customer($id):obj {
		if ($m = $this->missing('cluster_id', 'api_key', 'api_secret')) return $m;
		return $this->get('Customer/'.$id.'.json');
	}
	protected function sales(array $query = []):obj {
		if ($m = $this->missing('cluster_id', 'api_key', 'api_secret')) return $m;
		return $this->get('Sale.json', $query);
	}
	protected function createCustomer(array $customer):obj {
		if ($m = $this->missing('cluster_id', 'api_key', 'api_secret')) return $m;
		return $this->post('Customer.json', $customer);
	}
}
