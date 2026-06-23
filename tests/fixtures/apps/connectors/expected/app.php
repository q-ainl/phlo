<?php
// source:  %SRC%/app.phlo
// phlo:    %VERSION%
// summary: Connector compile coverage
class app extends obj {
	public static function route():bool {
		return route('GET', cb: 'app::GETHome');
	}
	public $title = 'Connectors';
	public static function GETHome(){
		return view(phlo('app')->main, 'Connectors');
	}
	protected function main():string {
		return "<h1>$this->title</h1>";
	}
}
