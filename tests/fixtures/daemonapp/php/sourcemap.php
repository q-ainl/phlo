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
				'php' => 89,
				'phlo' => 11,
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
			['php' => 34, 'phlo' => 30, 'name' => 'due'],
			['php' => 51, 'phlo' => 48, 'name' => 'fire'],
			['php' => 60, 'phlo' => 58, 'name' => 'lastRun'],
			['php' => 64, 'phlo' => 62, 'name' => 'markRun'],
			['php' => 67, 'phlo' => 65, 'name' => 'lock'],
			['php' => 73, 'phlo' => 71, 'name' => 'unlock'],
		],
	],
];
