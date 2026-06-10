<?php return [
	'%PHP%/functions.php' => [
		'source' => '',
		'map' => [
			[
				'php' => 3,
				'phlo' => 18,
				'name' => 'slugify',
				'source' => '%SRC%/page.items.phlo',
			],
		],
	],
	'%PHP%/app.php' => [
		'source' => '%SRC%/app.phlo',
		'map' => [
			['php' => 6, 'phlo' => 3, 'name' => 'title'],
			['php' => 7, 'phlo' => 4, 'name' => 'version'],
			['php' => 9, 'phlo' => 5, 'name' => 'now'],
			['php' => 12, 'phlo' => 8, 'name' => 'controller'],
			['php' => 21, 'phlo' => 11, 'name' => 'GETHome'],
			['php' => 24, 'phlo' => 12, 'name' => 'AsyncPOSTItemsSave'],
			['php' => 27, 'phlo' => 14, 'name' => 'home'],
			['php' => 30, 'phlo' => 17, 'name' => 'main'],
		],
	],
	'%PHP%/page.items.php' => [
		'source' => '%SRC%/page.items.phlo',
		'map' => [
			['php' => 6, 'phlo' => 3, 'name' => 'items'],
			['php' => 8, 'phlo' => 5, 'name' => 'label'],
			['php' => 11, 'phlo' => 7, 'name' => 'save'],
			['php' => 14, 'phlo' => 12, 'name' => 'list'],
		],
	],
];
