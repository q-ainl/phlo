<?php
// Same app, but with the daemon constant set: the phlo_app gate must lazily load
// classes/daemon.php (so class_exists('daemon') becomes true).
require dirname(__DIR__, 4).'/phlo.php';

phlo_app(
	id:     'DaemonApp',
	host:   'daemon.test',
	build:  true,
	debug:  false,
	daemon: 3099,
	app:    dirname(__DIR__).'/',
);
