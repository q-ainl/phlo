<?php
// Worker for ErrorLogTest concurrency check: logs one unique error, then exits.
require __DIR__.'/../bootstrap.php';
$n = (int)($argv[1] ?? 0);
$path = "worker.$n:1";
$msg  = "Error $n at /tmp/worker$n.php:1";
phlo_error_log(phlo_error_id((string)phlo('req')->host, $path, $msg), $path, $msg);
