<?php
// source:  %SRC%/app.phlo
// phlo:    %VERSION%
// summary: Golden fixture app
class app extends obj {
	public $title = 'Fixture';
	public $version = '.7';
	protected function _now(){
		return time();
	}
	protected function controller(){
		$this->ready = true;
		$first = phlo('page_items')->items[0];
		app::route();
	}
	public static function route():bool {
		return route('GET', cb: 'app::GETHome') ||
		route('POST', 'items save', true, cb: 'app::AsyncPOSTItemsSave');
	}
	public static function GETHome(){
		return phlo('app')->home;
	}
	public static function AsyncPOSTItemsSave(){
		return page_items::save();
	}
	protected function home(){
		return view($this->main, 'Home');
	}
	protected function main():string {
		$_ = [];
		$_[] = "<h1 id=\"top\" class=\"hero\">$this->title</h1>";
		$_[] = "<p>".ucfirst($this->title)."</p>";
		$_[] = "<p>".($this->ready ? 'ready' : 'not ready')."</p>";
		return implode(lf, $_);
	}
}
