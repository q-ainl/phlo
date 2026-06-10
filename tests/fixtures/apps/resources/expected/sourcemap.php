<?php return [
	'%PHP%/functions.php' => [
		'source' => '',
		'map' => [
			[
				'php' => 3,
				'phlo' => 7,
				'name' => 'camel',
				'source' => '%PHLO%/resources/camel.phlo',
			],
			[
				'php' => 7,
				'phlo' => 7,
				'name' => 'slug',
				'source' => '%PHLO%/resources/slug.phlo',
			],
		],
	],
	'%PHP%/app.php' => [
		'source' => '%SRC%/app.phlo',
		'map' => [
			['php' => 6, 'phlo' => 3, 'name' => 'title'],
			['php' => 8, 'phlo' => 7, 'name' => 'GETW'],
			['php' => 11, 'phlo' => 9, 'name' => 'slugTitle'],
			['php' => 14, 'phlo' => 12, 'name' => 'shell'],
		],
	],
	'%PHP%/widget.php' => [
		'source' => '%RSRC%/widget.phlo',
		'map' => [
			['php' => 6, 'phlo' => 5, 'name' => 'theme'],
			['php' => 8, 'phlo' => 5, 'name' => 'render'],
		],
	],
];
