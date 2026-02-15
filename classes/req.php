<?php
namespace phlo;

class req extends obj {
	protected function _cli():bool {
		return !isset($_SERVER['REQUEST_METHOD']);
	}

	protected function _async():bool {
		return 'phlo' === ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? null);
	}

	protected function _method():string {
		return $this->cli ? 'CLI' : $_SERVER['REQUEST_METHOD'];
	}

	protected function _path():string {
		if ($this->cli){
			$args = $this->args;
			return $args ? \array_shift($args) : void;
		}
		return \rawurldecode(\substr(\parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 1));
	}

	protected function _args():array {
		return $this->cli ? \array_slice($_SERVER['argv'] ?? [], 1) : [];
	}

	protected function _query():array {
		if ($this->cli) return [];
		\parse_str($_SERVER['QUERY_STRING'] ?? void, $query);
		return $query;
	}

	protected function _referer():string {
		return $this->cli ? void : ($_SERVER['HTTP_REFERER'] ?? void);
	}

	protected function _ip():string {
		return $this->cli ? '127.0.0.1' : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
	}

	protected function _contentType():string {
		return $_SERVER['CONTENT_TYPE'] ?? void;
	}

	protected function _userAgent():string {
		return $this->cli ? void : ($_SERVER['HTTP_USER_AGENT'] ?? void);
	}

	protected function _acceptLanguage():string {
		return $this->cli ? void : ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? void);
	}

	protected function _host():string {
		return $this->cli ? (\defined('phlo\\host') ? host : 'cli') : ($_SERVER['HTTP_HOST'] ?? 'localhost');
	}
}
