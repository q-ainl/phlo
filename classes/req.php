<?php

class req extends obj {
	protected function _cli():bool {
		return !isset($_SERVER['REQUEST_METHOD']);
	}

	protected function _async():bool {
		return 'phlo' === ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? null);
	}

	protected function _method():string {
		return $this->cli ? 'CLI' : ($_SERVER['REQUEST_METHOD'] ?? 'GET');
	}

	protected function _path():string {
		if ($this->cli){
			$args = $this->args;
			return $args ? array_shift($args) : void;
		}
		$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? slash, PHP_URL_PATH);
		return rawurldecode(ltrim($path, slash));
	}

	protected function _args():array {
		return $this->cli ? array_slice($_SERVER['argv'] ?? [], 1) : [];
	}

	protected function _qs():string {
		return $this->cli ? void : ($_SERVER['QUERY_STRING'] ?? void);
	}

	protected function _query():array {
		if ($this->cli) return [];
		parse_str($this->qs, $query);
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

	public function part(int $index):?string {
		$parts = explode(slash, $this->path);
		return $parts[$index] ?? null;
	}

	protected function _host():string {
		return host;
	}

	protected function _secure():bool {
		if ($this->cli) return true;
		$https = strtolower($_SERVER['HTTPS'] ?? '');
		if ($https && $https !== 'off') return true;
		if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
		if (($_SERVER['SERVER_PORT'] ?? null) === '443') return true;
		return false;
	}

	protected function _scheme():string {
		return $this->secure ? 'https' : 'http';
	}

	protected function _base():string {
		return "$this->scheme://$this->host";
	}

	protected function _url():string {
		return "$this->base/$this->path";
	}
}
