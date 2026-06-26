<?php
require dirname(__DIR__, 4).'/phlo.php';

phlo_app(
	id:    'AITEST',
	host:  'localhost',
	build: true,
	debug: false,
	app:   dirname(__DIR__).'/',
);
