<?php return [
	'/srv/control/phlo/tests/fixtures/daemonapp/php/functions.php' => [
		'source' => '',
		'map' => [
			[
				'php' => 4,
				'phlo' => 11,
				'name' => 'phlo_sync',
				'source' => '/srv/control/phlo/resources/phlo.sync.phlo',
			],
			[
				'php' => 16,
				'phlo' => 12,
				'name' => 'await',
				'source' => '/srv/control/phlo/resources/await.phlo',
			],
			[
				'php' => 76,
				'phlo' => 11,
				'name' => 'wsCast',
				'source' => '/srv/control/phlo/resources/wsCast.phlo',
			],
			[
				'php' => 94,
				'phlo' => 16,
				'name' => 'HTTP',
				'source' => '/srv/control/phlo/resources/HTTP.phlo',
			],
		],
	],
	'/srv/control/phlo/tests/fixtures/daemonapp/php/app.php' => [
		'source' => '/srv/control/phlo/tests/fixtures/daemonapp/app.phlo',
		'map' => [
			['php' => 7, 'phlo' => 3, 'name' => 'ping'],
			['php' => 10, 'phlo' => 4, 'name' => 'daemonLoaded'],
			['php' => 14, 'phlo' => 8, 'name' => 'dueWeekly'],
		],
	],
	'/srv/control/phlo/tests/fixtures/daemonapp/php/tasks.php' => [
		'source' => '/srv/control/phlo/resources/tasks.phlo',
		'map' => [
			['php' => 14, 'phlo' => 10, 'name' => 'dir'],
			['php' => 17, 'phlo' => 13, 'name' => 'run'],
			['php' => 31, 'phlo' => 27, 'name' => 'saveRun'],
			['php' => 38, 'phlo' => 34, 'name' => 'due'],
			['php' => 55, 'phlo' => 52, 'name' => 'fire'],
			['php' => 64, 'phlo' => 62, 'name' => 'lastRun'],
			['php' => 68, 'phlo' => 66, 'name' => 'markRun'],
			['php' => 71, 'phlo' => 69, 'name' => 'lock'],
			['php' => 77, 'phlo' => 75, 'name' => 'unlock'],
		],
	],
];
