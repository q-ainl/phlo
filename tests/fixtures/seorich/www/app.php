<?php
require dirname(__DIR__, 4).'/phlo.php';

phlo_app(
	id:    'SEORich',
	host:  'localhost',
	indexable: true,
	build: true,
	debug: false,
	app:   dirname(__DIR__).'/',
);
