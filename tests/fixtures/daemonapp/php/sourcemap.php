<?php return [
	'/srv/control/phlo/tests/fixtures/daemonapp/php/functions.php' => [
		'source' => '',
		'map' => [
			[
				'php' => 4,
				'phlo' => 10,
				'name' => 'phlo_sync',
				'source' => '/srv/control/phlo/resources/phlo.sync.phlo',
			],
			[
				'php' => 16,
				'phlo' => 11,
				'name' => 'await',
				'source' => '/srv/control/phlo/resources/await.phlo',
			],
			[
				'php' => 76,
				'phlo' => 10,
				'name' => 'wsCast',
				'source' => '/srv/control/phlo/resources/wsCast.phlo',
			],
			[
				'php' => 88,
				'phlo' => 10,
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
			['php' => 13, 'phlo' => 9, 'name' => 'dir'],
			['php' => 16, 'phlo' => 12, 'name' => 'run'],
			['php' => 30, 'phlo' => 26, 'name' => 'saveRun'],
			['php' => 33, 'phlo' => 29, 'name' => 'due'],
			['php' => 50, 'phlo' => 47, 'name' => 'fire'],
			['php' => 59, 'phlo' => 57, 'name' => 'lastRun'],
			['php' => 63, 'phlo' => 61, 'name' => 'markRun'],
			['php' => 66, 'phlo' => 64, 'name' => 'lock'],
			['php' => 72, 'phlo' => 70, 'name' => 'unlock'],
		],
	],
];
