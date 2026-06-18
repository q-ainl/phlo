<?php
require dirname(__DIR__, 4).'/phlo.php';

phlo_app(
	id:     'DaemonFixture',
	host:   'daemon.test',
	daemon: 'http://127.0.0.1:3010',
	build:  true,
	debug:  false,
	app:    dirname(__DIR__).'/',
);
