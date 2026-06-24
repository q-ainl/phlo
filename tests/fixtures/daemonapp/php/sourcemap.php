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
		],
	],
];
