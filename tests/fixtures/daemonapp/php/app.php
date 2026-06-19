<?php
// source:  /srv/control/phlo/tests/fixtures/daemonapp/app.phlo
// phlo:    1.0Δ
// summary: daemon engine test fixture: gate + one-shot fallback
class app extends obj {
	public static function route():bool {
		return false;
	}
	protected function ping($x = ''){
		return 'pong:'.$x;
	}
	protected function daemonLoaded(){
		return class_exists('daemon') ? 'yes' : 'no';
	}
}
