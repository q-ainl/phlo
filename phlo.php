<?php
namespace phlo\tech;
const version = '1.0γ';

require_once __DIR__.'/constants.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/error.php';
require_once __DIR__.'/classes/obj.php';
require_once __DIR__.'/classes/req.php';
require_once __DIR__.'/classes/res.php';

function app(...$args){
	$args['debug'] ??= false;
	$args['build'] ??= false;
	$args['host'] ?? \phlo\error('No "host" defined');
	$args['app'] ?? \phlo\error('No "app" path defined');
	$args['data'] ??= "$args[app]data/";
	$args['php'] ??= "$args[app]php/";
	$args['www'] ??= "$args[app]www/";
	foreach ($args AS $key => $value) \define("phlo\\$key", $value);
	\set_error_handler('phlo\\tech\\handle_error');
	\set_exception_handler('phlo\\tech\\handle_exception');
	\phlo\debug && require_once __DIR__.'/debug.php';
	\phlo\build && require_once __DIR__.'/build.php';
	\spl_autoload_register(static function($class){
		static $map;
		$map ??= \is_file($file = \phlo\php.'classmap.php') ? require($file) : [];
		if (isset($map[$class])) return require_once \phlo\php.$map[$class];
		$short = ($pos = \strrpos($class, '\\')) !== false ? \substr($class, $pos + 1) : $class;
		$filename = \phlo\php.\strtr($short, [\phlo\us => \phlo\dot]).'.php';
		if (\is_file($filename)) require_once $filename;
	});
	\phlo\build && changed() && build();
	\is_file($file = \phlo\php.'functions.php') && require_once $file;
	require_once \phlo\php.'app.php';
	if (false ===	$args['thread'] ??= false) return thread();
	\ignore_user_abort(true);
	$thread = static function() use (&$info):void { [\phlo\debug($info), thread()]; };
	for ($requests = 1; !$args['thread'] || $requests <= $args['thread']; ++$requests){
		$info = "Thread $requests/$args[thread]";
		$keepRunning = frankenphp_handle_request($thread);
		\phlo\phlo('tech/reset');
		\gc_collect_cycles();
		if (!$keepRunning) break;
	}
}

function thread(){
	try {
		\phlo\phlo('app');
		$req = \phlo\phlo('req');
		if ($req->cli) return cli($req->args);
		\phlo\phlo('res')->render();
	}
	catch (\RuntimeException $e){
		if ($e->getMessage() === 'PhloDump') return;
		handle_exception($e);
	}
	catch (\Throwable $e){
		handle_exception($e);
	}
}

function cli(array $args):void {
	if (!$args) return;
	$target = \array_shift($args);
	if (\str_contains($target, '.')){
		[$obj, $method] = \explode('.', $target, 2);
		$handle = \phlo\phlo($obj);
		$result = $args ? $handle->$method(...$args) : ($handle->hasMethod($method) ? $handle->$method() : $handle->$method);
	}
	elseif (\str_contains($target, '::')){
		[$class, $method] = \explode('::', $target, 2);
		$class = \str_contains($class, \phlo\bs) ? $class : 'phlo\\'.$class;
		$result = $class::$method(...$args);
	}
	else {
		$fn = \function_exists('phlo\\'.$target) ? 'phlo\\'.$target : $target;
		$result = $fn(...$args);
	}
	$res = \phlo\phlo('res');
	if ($res->outputted) return;
	if (\is_array($result) || $result instanceof \phlo\obj) \phlo\apply(...(array)$result);
	else \phlo\apply(data: $result);
}
