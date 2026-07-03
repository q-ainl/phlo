<?php
// source:  /srv/control/phlo/tests/fixtures/daemonapp/app.phlo
// phlo:    1.0.0-RC4
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
	// 2026-07-06 is a Monday: a 'monday 09:00' task must be due at 09:00, not midnight (the strtotime 'today' bug).
	protected function dueWeekly(){
		$monday = strtotime('2026-07-06');
		$task = obj(weekly: 'monday 09:00');
		return arr(at09: tasks::due('dueprobe', $task, $monday + 32400), at00: tasks::due('dueprobe', $task, $monday));
	}
}
