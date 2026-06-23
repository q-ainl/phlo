<?php
// source:   %PHLO%/resources/connectors/shops/Shopify.phlo
// phlo:     %VERSION%
// version:  1.0
// creator:  q-ai.nl
// summary:  Shopify Admin API connector: read customers, orders and products; create draft orders and products; update inventory
// extends:  Connector
// package:  connectors
// frontend: false
// backend:  true
// requires: @Connector creds:Shopify
// tags:     shopify webshop ecommerce orders products connector
class Shopify extends Connector {
	public const section = 'Shopify';
	protected function base(){
		return 'https://'.($this->config['shop_domain'] ?? void).'/admin/api/'.($this->config['api_version'] ?? '2024-01');
	}
	protected function headers(){
		return ['X-Shopify-Access-Token: '.($this->config['access_token'] ?? void)];
	}
	public static function fields(){
		return arr(
			section: 'Shopify',
			config: arr(
				shop_domain: 'Shop domain, e.g. your-store.myshopify.com',
				api_version: 'Admin API version (optional, default 2024-01)',
			),
			secret: arr(access_token: 'Admin API access token (shpat_...)'),
			scopes: 'read_customers, read_orders, read_products, write_draft_orders, write_inventory',
		);
	}
	protected function customers(array $query = []):obj {
		if ($m = $this->missing('shop_domain', 'access_token')) return $m;
		return $this->get('customers.json', $query);
	}
	protected function searchCustomers($query, int $limit = 10):obj {
		if ($m = $this->missing('shop_domain', 'access_token')) return $m;
		return $this->get('customers/search.json', ['query' => $query, 'limit' => $limit]);
	}
	protected function customer($id):obj {
		if ($m = $this->missing('shop_domain', 'access_token')) return $m;
		return $this->get('customers/'.$id.'.json');
	}
	protected function orders(array $query = []):obj {
		if ($m = $this->missing('shop_domain', 'access_token')) return $m;
		return $this->get('orders.json', $query);
	}
	protected function products(array $query = []):obj {
		if ($m = $this->missing('shop_domain', 'access_token')) return $m;
		return $this->get('products.json', $query);
	}
	protected function createDraftOrder(array $order):obj {
		if ($m = $this->missing('shop_domain', 'access_token')) return $m;
		return $this->post('draft_orders.json', ['draft_order' => $order]);
	}
	protected function createProduct(array $product):obj {
		if ($m = $this->missing('shop_domain', 'access_token')) return $m;
		return $this->post('products.json', ['product' => $product]);
	}
	protected function setInventory($inventoryItemId, $locationId, int $available):obj {
		if ($m = $this->missing('shop_domain', 'access_token')) return $m;
		return $this->post('inventory_levels/set.json', ['inventory_item_id' => $inventoryItemId, 'location_id' => $locationId, 'available' => $available]);
	}
}
