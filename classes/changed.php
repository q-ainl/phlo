<?php

function phlo_build_config(string $file, ?string $appPath = null):array {
	$appPath ??= app;
	$json = strtr((string)file_get_contents($file), [
		'%app/'  => $appPath,
		'%phlo/' => dirname(__DIR__).slash,
	]);
	$config = json_decode($json, true);
	is_array($config) || error('Build error: Invalid data/app.json');
	return $config;
}

class build_base {

	public static function changed():array {
		static $nextCheckAt = 0.0, $last = ['init'];
		$now = microtime(true);
		if ($now < $nextCheckAt) return $last;
		$ref = php.'functions.php';
		if (!is_file($ref)) return $last = ['php/functions.php'];
		$refTime   = filemtime($ref);
		$buildFile = data.'app.json';
		$changed   = [];
		if (is_file($buildFile) && filemtime($buildFile) > $refTime) $changed[] = $buildFile;
		$data = static::sources();
		foreach (['app', 'resources'] as $group){
			foreach ((array)$data['sources'][$group] as $file){
				if (filemtime($file) > $refTime) $changed[] = $file;
			}
		}
		if ($changed){
			$changed     = array_values(array_unique($changed));
			natcasesort($changed);
			$nextCheckAt = $now + 0.05;
			return $last = array_values($changed);
		}
		$last        = [];
		$nextCheckAt = $now + 0.20;
		return [];
	}

	public static function config():array {
		$config = static::sources()['build'];
		unset($config['_minifyExplicit']);
		return $config;
	}

	protected static function sources(bool $release = false, ?string $appPath = null, ?string $dataPath = null):array {
		static $cache = [], $mtime = [];
		$appPath  ??= app;
		$dataPath ??= data;
		$key = ($release ? 'release' : 'build').'|'.$appPath.'|'.$dataPath;
		is_file($file = rtrim($dataPath, slash).slash.'app.json') || error('No data/app.json file');
		$time = filemtime($file);
		if (isset($cache[$key]) && ($mtime[$key] ?? 0) === $time) return $cache[$key];
		$raw   = phlo_build_config($file, $appPath);
		is_array($raw) || error('Build error: Invalid data/app.json');
		if (array_key_exists('libs', $raw) || array_key_exists('functions', $raw)) error('Build error: data/app.json uses obsolete keys. Use "resources" only.');
		if (isset($raw['paths']) && (array_key_exists('libs', (array)$raw['paths']) || array_key_exists('functions', (array)$raw['paths']))) error('Build error: data/app.json paths use obsolete keys. Use paths.resources only.');
		$build = array_replace(static::defaults($raw, $release), $raw);
		$build['resources'] = array_values(array_unique($build['resources'] ?? []));
		$build['_minifyExplicit'] = [
			'minifyCSS' => array_key_exists('minifyCSS', $raw),
			'minifyJS'  => array_key_exists('minifyJS',  $raw),
			'minifyPHP' => array_key_exists('minifyPHP', $raw),
		];
		$build['paths'] ??= [];
		$paths   = $build['paths'];
		$exclude = $release ? ($build['release']['exclude'] ?? []) : ($build['exclude'] ?? []);
		$normalize = static fn(string $path) => $path === void ? $appPath : $path;
		$appPaths = array_values(array_unique(array_filter(
			array_merge([$appPath], $paths['app'] ?? []),
			'strlen'
		)));
		$appFiles = [];
		foreach ($appPaths as $path){
			$dir = rtrim($normalize($path), slash).slash;
			if (!is_dir($dir)) continue;
			foreach (glob($dir.'*.phlo') ?: [] as $appFile){
				if ($exclude && in_array(strtr(basename($appFile, '.phlo'), [dot => us]), $exclude, true)) continue;
				$appFiles[] = $appFile;
			}
		}
		$resourcePaths = array_values(array_unique(array_filter(
			array_merge([dirname(__DIR__).slash.'resources'.slash], $paths['resources'] ?? []),
			'strlen'
		)));
		$resourceFiles = [];
		foreach ($build['resources'] ?? [] as $resource){
			if ($exclude && in_array(basename($resource), $exclude, true)) continue;
			$found = null;
			foreach ($resourcePaths as $path){
				$resourceFile = rtrim($normalize($path), slash).slash.$resource.'.phlo';
				if (is_file($resourceFile)){ $found = $resourceFile; break; }
			}
			$found || error('Build error: Resource not found '.$resource);
			$resourceFiles[] = $found;
		}
		$releaseCfg = $build['release'] ?? null;
		if ($releaseCfg){
			$releaseCfg = is_string($releaseCfg) ? ['php' => $releaseCfg] : $releaseCfg;
			$build['release'] = array_replace(['php' => $appPath.'release/'], $releaseCfg);
			$build['release']['php'] = $normalize($build['release']['php']);
			$build['release']['www'] ??= rtrim($build['release']['php'], slash).slash.'www/';
			$build['release']['www'] = $normalize($build['release']['www']);
		}
		if (isset($build['icons'])) $build['icons'] = $normalize($build['icons']);
		$result = [
			'build'      => $build,
			'app_path'   => $appPath,
			'data_path'  => $dataPath,
			'app_source' => is_file(rtrim($appPath, slash).slash.'app.phlo')
				? rtrim($appPath, slash).slash.'app.phlo'
				: ($appFiles[0] ?? rtrim($appPath, slash).slash.'app.phlo'),
			'sources'    => [
				'app'       => $appFiles,
				'resources' => $resourceFiles,
			],
		];
		$cache[$key] = $result;
		$mtime[$key] = $time;
		return $result;
	}

	protected static function defaults(array $raw = [], bool $release = false):array {
		return [
			'routes'    => true,
			'buildCSS'  => true,
			'buildJS'   => true,
			'minifyCSS' => $raw['minifyCSS'] ?? $release,
			'minifyJS'  => $raw['minifyJS']  ?? $release,
			'minifyPHP' => $raw['minifyPHP'] ?? $release,
			'phloJS'    => false,
			'phloNS'    => 'app',
			'defaultNS' => 'app',
			'resourceNS' => [],
			'iconNS'    => 'app',
			'comments'  => true,
			'extends'   => 'obj',
			'exclude'   => [],
			'trace'     => false,
		];
	}
}

function phlo_auth(string $name, ?string $realm = null):bool {
	static $cache = null, $mtime = null;
	$file = defined('data') ? data.'auth.ini' : null;
	if ($file && is_file($file)){
		$time = filemtime($file);
		if ($cache === null || $mtime !== $time){
			$ini   = parse_ini_file($file, true, INI_SCANNER_RAW);
			$cache = is_array($ini) ? $ini : [];
			$mtime = $time;
		}
	}
	else $cache ??= [];
	$section = is_array($cache[$name] ?? null) ? $cache[$name] : [];
	$user    = $section['user']     ?? void;
	$pass    = $section['password'] ?? void;
	$ok = $user !== void && $pass !== void && isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) && $_SERVER['PHP_AUTH_USER'] === $user && $_SERVER['PHP_AUTH_PW'] === $pass;
	if ($ok || $realm === null) return $ok;
	$res = phlo('res');
	if (!$section){
		$res->type = 'text/plain; charset=UTF-8';
		$res->body = 'Missing auth config section ['.$name.'] in data/auth.ini';
		$res->render(500);
		return false;
	}
	$res->header('WWW-Authenticate', 'Basic realm="'.strtr($realm, ['"' => '']).'"');
	$res->type = 'text/plain; charset=UTF-8';
	$res->body = '401 Unauthorized';
	$res->render(401);
	return false;
}

function phlo_help_reflect(string $class):array {
	$ref = new ReflectionClass($class);
	$out = [];
	foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method){
		if (!$method->isStatic()) continue;
		$name = $method->getName();
		if ($name === 'help') continue;
		if ($method->getDeclaringClass()->getName() !== $class) continue;
		$doc    = $method->getDocComment();
		$text   = $doc ? trim(preg_replace('/^[ \t]*\*[ \t]?/m', '', preg_replace('/^\/\*\*\s*|\s*\*\/$/s', '', (string)$doc))) : null;
		$params = [];
		foreach ($method->getParameters() as $p){
			$entry = ['name' => '$'.$p->getName()];
			if ($p->hasType()) $entry['type'] = (string)$p->getType();
			if ($p->isOptional() && $p->isDefaultValueAvailable()){
				$default = $p->getDefaultValue();
				$entry['default'] = is_string($default) ? '"'.$default.'"' : json_encode($default);
			}
			$params[] = $entry;
		}
		$item = [];
		$text   && $item['summary'] = $text;
		$params && $item['params']  = $params;
		$method->hasReturnType() && $item['return'] = (string)$method->getReturnType();
		$out[$name] = $item;
	}
	ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
	return $out;
}
