<?php
// Worker for ErrorLogTest concurrency check: logs one unique error, then exits.
require __DIR__.'/../bootstrap.php';
$n = (int)($argv[1] ?? 0);
phlo_error_log("worker.$n:1", "Error $n at /tmp/worker$n.php:1");
