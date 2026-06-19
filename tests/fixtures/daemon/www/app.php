<?php
require dirname(__DIR__, 4).'/phlo.php';

phlo_app(
	id:     'DaemonFixture',
	host:   'daemon.test',
	daemon: 3010,
	build:  true,
	debug:  false,
	app:    dirname(__DIR__).'/',
);
