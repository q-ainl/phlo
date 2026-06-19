<?php
require dirname(__DIR__, 4).'/phlo.php';

phlo_app(
	id:    'DaemonApp',
	host:  'daemon.test',
	build: true,
	debug: false,
	app:   dirname(__DIR__).'/',
);
