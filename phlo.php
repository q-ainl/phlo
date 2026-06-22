<?php
const phlo = '1.0.0-RC3';

const br    = '<br>';
const bs    = '\\';
const bt    = '`';
const colon = ':';
const comma = ',';
const cr    = "\r";
const dash  = '-';
const dot   = '.';
const dq    = '"';
const eq    = '=';
const lf    = "\n";
const nl    = "\r\n";
const perc  = '%';
const pipe  = '|';
const qm    = '?';
const semi  = ';';
const slash = '/';
const space = ' ';
const sq    = '\'';
const tab   = "\t";
const us    = '_';
const void  = '';
const jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

class PhloException extends RuntimeException {
	public function __construct(string $message, int $code = 0, public array $data = []){ parent::__construct($message, $code); }
	public function payload():array { return ['error' => $this->getMessage(), 'code' => $this->getCode(), 'type' => static::class, 'data' => $this->data ?: null]; }
}

function phlo_exception(Throwable $e):void {
	require_once engine.'error.php';
	phlo_error_handle($e);
}

function phlo_app(...$args):void {
	if ($args['trace'] ??= false) require_once __DIR__.'/classes/trace.php';
	require_once __DIR__.'/functions'.($args['trace'] ? '.trace.php' : '.php');
	require_once __DIR__.'/classes/obj.php';
	require_once __DIR__.'/classes/req.php';
	require_once __DIR__.'/classes/res.php';
	$args['app']       ??  error('No "app" path defined');
	$args['debug']     ??= false;
	$args['build']     ??= false;
	$args['host']      ??= null;
	$args['control']   ??= ($args['build'] && $args['debug']) ? 'phlo' : false;
	$args['auth']      ??= false;
	$args['data']      ??= $args['app'].'data/';
	$args['php']       ??= $args['app'].'php/';
	$args['www']       ??= $args['app'].'www/';
	$args['cli']       ??= ZEND_THREAD_SAFE ? 'php-zts' : 'php';
	$args['thread']    ??= false;
	$args['daemon']    ??= false;
	$args['build'] && $args['thread'] && error('Phlo build and thread mode cannot be combined');
	$args['build'] && !is_file($args['data'].'app.json') && error('Phlo build mode requires data/app.json');
	$args['auth'] && !$args['build'] && error('Auth requires build mode');
	foreach ($args as $key => $value) define($key, $value);
	define('engine', __DIR__.slash);
	if ($args['debug']) require_once __DIR__.'/debug.php';
	if ($args['build']) require_once __DIR__.'/classes/changed.php';
	if ($args['daemon']) require_once __DIR__.'/classes/daemon.php';
	if ($args['trace']) trace::boot($args['app']);
	set_error_handler(static function(int $level, string $msg, string $file = '', int $line = 0):bool {
		if (!(error_reporting() & $level)) return false;
		throw new ErrorException($msg, 0, $level, $file, $line);
	});
	set_exception_handler('phlo_exception');
	spl_autoload_register(static function(string $class):void {
		static $map = null, $mtime = null;
		$file = php.'classmap.php';
		if ($map === null || $mtime !== (is_file($file) ? filemtime($file) : null)){
			$map   = is_file($file) ? require $file : [];
			$mtime = is_file($file) ? filemtime($file) : null;
		}
		if (isset($map[$class])){ require_once php.$map[$class]; return; }
	});
	if ($args['build']){
		$engineMap = ['build' => 'build', 'reflect' => 'reflect', 'build_file' => 'file', 'build_node' => 'node', 'build_builder' => 'builder', 'build_css' => 'css', 'build_icons' => 'icons'];
		spl_autoload_register(static function(string $class) use ($engineMap):void {
			$name = $engineMap[strtolower($class)] ?? null;
			if ($name !== null) require_once engine.'classes/'.$name.'.php';
		});
	}
	defined('composer') && spl_autoload_register(static function(string $class):void {
		static $loaded = false;
		if ($loaded) return;
		$loaded = true;
		require_once composer.'vendor/autoload.php';
		foreach (spl_autoload_functions() as $fn){
			if (is_array($fn) && ($fn[0] ?? null) instanceof \Composer\Autoload\ClassLoader){
				spl_autoload_unregister($fn);
				spl_autoload_register($fn);
				$fn[0]->loadClass($class);
				return;
			}
		}
	});
	if ($args['thread'] !== false && PHP_SAPI !== 'cli'){
		ignore_user_abort(true);
		$handle = static function():void { phlo_thread(); };
		for ($i = 1; !$args['thread'] || $i <= $args['thread']; ++$i){
			$keepRunning = frankenphp_handle_request($handle);
			phlo('tech/reset');
			if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
			gc_collect_cycles();
			if (!$keepRunning) break;
		}
		return;
	}
	phlo_thread();
}

function phlo_thread():void {
	try {
		$req = phlo('req');
		if ($req->cli){
			$target = $req->args[0] ?? void;
			if (str_starts_with($target, 'build::') || str_starts_with($target, 'reflect::')){
				phlo_cli($req->args);
				return;
			}
			phlo_load(false);
			phlo('app');
			phlo_cli($req->args);
			return;
		}
		$isDashboard = build && debug && control && str_starts_with($req->path.slash, control.slash);
		if (auth && !$isDashboard){
			phlo_auth('site', 'Phlo App - '.host);
			if (phlo('res')->done) return;
		}
		if ($isDashboard){
			require_once engine.'control.php';
			phlo_dashboard::handle(substr($req->path, strlen(control) + 1));
			phlo('res')->render();
			return;
		}
		phlo_load(true);
		phlo('app');
		phlo('res')->render();
	}
	catch (RuntimeException $e){
		if ($e->getMessage() === 'PhloDump') return;
		phlo_exception($e);
	}
	catch (Throwable $e){
		phlo_exception($e);
	}
}

function phlo_load(bool $http):void {
	static $loaded = false, $loadedApp = null;
	if ($loaded && $loadedApp === app){
		if ($http && !phlo('res')->type) phlo('res')->type = 'text/html; charset=UTF-8';
		return;
	}
	if (build && (!is_file(php.'functions.php') || !is_file(php.'app.php') || build_base::changed())){
		debug('Builder started');
		$changed = build::run();
		$changed && debug('Built '.implode(', ', array_map('basename', $changed)).' ('.count($changed).')');
	}
	if (!is_file(php.'functions.php') || !is_file(php.'app.php')) error('Compiled runtime not available');
	if (!$loaded){
		require_once php.'functions.php';
		$loaded = true;
	}
	if ($loadedApp !== app){
		require_once php.'app.php';
		$loadedApp = app;
	}
	if ($http && !phlo('res')->type) phlo('res')->type = 'text/html; charset=UTF-8';
}

// `object.method` with no args and no such method is a bare property read, not a call.
function phlo_dispatch(string $target, array $args = []):mixed {
	if (str_contains($target, dot)){
		[$object, $method] = explode(dot, $target, 2);
		$handle = phlo($object);
		return $args ? $handle->$method(...$args) : ($handle->hasMethod($method) ? $handle->$method() : $handle->$method);
	}
	if (str_contains($target, '::')){
		[$class, $method] = explode('::', $target, 2);
		return $class::$method(...$args);
	}
	return $target(...$args);
}

function phlo_cli(array $args):void {
	if (!$args) return;
	$target = array_shift($args);
	$result = phlo_dispatch($target, $args);
	if (isset($result)) print(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).lf);
}

function phlo_serve():void {
	ini_set('display_errors', 'stderr');
	stream_set_blocking(STDIN, true);
	fwrite(STDOUT, json_encode(['t' => 'ready']).lf);
	while (($line = fgets(STDIN)) !== false){
		$line = trim($line);
		if ($line === void) continue;
		$msg    = json_decode($line, true) ?: [];
		$id     = $msg['id']     ?? null;
		$target = (string)($msg['target'] ?? void);
		$args   = (array)($msg['args'] ?? []);
		$stream = (bool)($msg['stream'] ?? false);
		$lineBuf = void;
		$emit = static function(string $chunk) use (&$lineBuf, $id):string {
			$lineBuf .= $chunk;
			while (($pos = strpos($lineBuf, lf)) !== false){
				$out = substr($lineBuf, 0, $pos);
				$lineBuf = substr($lineBuf, $pos + 1);
				fwrite(STDOUT, json_encode(['id' => $id, 't' => 'line', 'data' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).lf);
			}
			return void;
		};
		try {
			if ($target === void) error('No target');
			if ($stream){
				ob_start($emit, 1);
				$result = phlo_dispatch($target, $args);
				while (ob_get_level()) ob_end_flush();
				if ($lineBuf !== void) fwrite(STDOUT, json_encode(['id' => $id, 't' => 'line', 'data' => $lineBuf], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).lf);
			}
			else $result = phlo_dispatch($target, $args);
			fwrite(STDOUT, json_encode(['id' => $id, 't' => 'done', 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).lf);
		}
		catch (Throwable $e){
			while (ob_get_level()) ob_end_clean();
			fwrite(STDOUT, json_encode(['id' => $id, 't' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).lf);
		}
		phlo('tech/reset');
		if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
		gc_collect_cycles();
	}
}

function phlo(?string $phloName = null, ...$args):mixed {
	static $list = [];
	if ($phloName === 'tech/reset'){
		obj::$classProps = [];
		return array_keys($list = array_filter($list, static fn($obj) => $obj->objPers));
	}
	if ($phloName === null) return array_keys($list);
	$class = strtr($phloName, [slash => us]);
	$handle = method_exists($class, '__handle') ? $class::__handle(...$args) : ($args ? null : $phloName);
	if ($handle === true){
		if (isset($list[$phloName])) return $list[$phloName]->objImport(...$args);
		$handle = $phloName;
	}
	elseif ($handle && isset($list[$handle])) return $list[$handle];
	$object = new $class(...$args);
	if ($handle) $list[$handle] = $object;
	if ($object->hasMethod('controller') && (!phlo('req')->cli || $phloName !== 'app')) $object->controller();
	return $object;
}
