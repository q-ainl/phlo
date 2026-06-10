<?php
// Test bootstrap: loads the engine without running phlo_app(), and defines the
// runtime constants the engine normally derives from phlo_app() arguments.
$root = dirname(__DIR__);

require_once $root.'/vendor/autoload.php';
require_once $root.'/phlo.php';
require_once $root.'/functions.php';
require_once $root.'/classes/obj.php';
require_once $root.'/classes/req.php';
require_once $root.'/classes/res.php';
require_once $root.'/classes/file.php';
require_once $root.'/classes/node.php';
require_once $root.'/classes/builder.php';
require_once $root.'/classes/css.php';

const PHLO_TEST_TMP = __DIR__.'/.tmp/';

foreach ([PHLO_TEST_TMP, PHLO_TEST_TMP.'build/php/', PHLO_TEST_TMP.'build/www/', PHLO_TEST_TMP.'work/'] as $dir){
	if (!is_dir($dir)) mkdir($dir, 0775, true);
}

define('engine', $root.'/');
define('app',    PHLO_TEST_TMP);
define('data',   PHLO_TEST_TMP.'build/data/');
define('php',    PHLO_TEST_TMP.'build/php/');
define('www',    PHLO_TEST_TMP.'build/www/');
define('cli',    PHP_BINARY);
define('host',   'phlo.test');
define('debug',  false);
define('build',  false);

// Wipes a directory's files (not recursive); used between golden builds.
function phlo_test_wipe(string $dir):void {
	foreach (glob($dir.'*') ?: [] as $file) if (is_file($file)) unlink($file);
}
