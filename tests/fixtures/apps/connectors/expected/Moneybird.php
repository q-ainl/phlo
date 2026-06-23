<?php
// source:   %PHLO%/resources/connectors/finance/Moneybird.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Moneybird connector: read contacts and invoices, create sales invoices
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector creds:Moneybird
// tags:     moneybird accounting invoices contacts connector
class Moneybird extends Connector {
	public const section = 'Moneybird';
	protected function base(){
		return 'https://moneybird.com/api/v2/'.($this->config['administration_id'] ?? void);
	}
	protected function headers(){
		return [static::bearer($this->config['access_token'] ?? void)];
	}
	public static function fields(){
		return arr(
			section: 'Moneybird',
			config: arr(administration_id: 'Administration ID'),
			secret: arr(access_token: 'Personal access token with read/write for contacts and invoices'),
		);
	}
	protected function contacts(array $query = []):obj {
		if ($m = $this->missing('administration_id', 'access_token')) return $m;
		return $this->get('contacts.json', $query);
	}
	protected function findContact($query):obj {
		if ($m = $this->missing('administration_id', 'access_token')) return $m;
		return $this->get('contacts.json', ['query' => $query, 'per_page' => 1]);
	}
	protected function contact($id):obj {
		if ($m = $this->missing('administration_id', 'access_token')) return $m;
		return $this->get('contacts/'.$id.'.json');
	}
	protected function invoices(array $query = []):obj {
		if ($m = $this->missing('administration_id', 'access_token')) return $m;
		return $this->get('sales_invoices.json', $query);
	}
	protected function createContact(array $contact):obj {
		if ($m = $this->missing('administration_id', 'access_token')) return $m;
		return $this->post('contacts.json', ['contact' => $contact]);
	}
	protected function createInvoice(array $invoice):obj {
		if ($m = $this->missing('administration_id', 'access_token')) return $m;
		return $this->post('sales_invoices.json', ['sales_invoice' => $invoice]);
	}
}
