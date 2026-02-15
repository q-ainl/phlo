<?php
namespace phlo\tech;

function changed():bool {
	$ref = \phlo\php.'functions.php';
	if (!\is_file($ref)) return true;
	$refTime = \filemtime($ref);
	$data = sources();
	foreach (['app', 'libs', 'functions'] AS $group){
		foreach ($data['sources'][$group] AS $file){
			if (\filemtime($file) > $refTime) return true;
		}
	}
	return false;
}

function builder(bool $build = false, bool $release = false):builder {
	require_once __DIR__.'/classes/builder.php';
	require_once __DIR__.'/classes/file.php';
	require_once __DIR__.'/classes/node.php';
	return new builder(sources($release), $build, $release);
}

function build(bool $release = false):array {
	return builder(true, $release)->changed;
}

function release():array {
	return build(true);
}

function flush():array {
	$deleted = [];
	$path = \phlo\php;
	foreach (\glob($path.'*.php') AS $file){
		\unlink($file);
		$deleted[] = \phlo\dash.\basename($file);
	}
	return $deleted;
}

function sources(bool $release = false, ?string $appPath = null, ?string $dataPath = null):array {
	static $cache = [];
	static $mtime = [];
	$key = $release ? 'release' : 'build';
	$appPath ??= \phlo\app;
	$dataPath ??= \phlo\data;
	\is_file($file = \rtrim($dataPath, \phlo\slash).\phlo\slash.'build.json') || \phlo\error('No data/build.json file');
	$time = \filemtime($file);
	if (isset($cache[$key]) && ($mtime[$key] ?? 0) === $time) return $cache[$key];
	$build = \json_decode(\file_get_contents($file), true);
	\is_array($build) || \phlo\error('Build Error: Invalid data/build.json');
	$raw = $build;
	$defaults = [
		'auto' => true,
		'routes' => true,
		'buildCSS' => true,
		'buildJS' => true,
		'minifyCSS' => \array_key_exists('minifyCSS', $raw) ? (bool)$raw['minifyCSS'] : false,
		'minifyJS' => \array_key_exists('minifyJS', $raw) ? (bool)$raw['minifyJS'] : false,
		'minifyPHP' => \array_key_exists('minifyPHP', $raw) ? (bool)$raw['minifyPHP'] : false,
		'phloJS' => false,
		'phloNS' => 'app',
		'defaultNS' => 'app',
		'iconNS' => 'app',
		'comments' => true,
		'extends' => 'obj',
		'exclude' => [],
	];
	$build = \array_replace($defaults, $build);
	$build['_minifyExplicit'] = [
		'minifyCSS' => \array_key_exists('minifyCSS', $raw),
		'minifyJS' => \array_key_exists('minifyJS', $raw),
		'minifyPHP' => \array_key_exists('minifyPHP', $raw),
	];
	$build['paths'] ??= [];
	$paths = $build['paths'];
	$exclude = $build['exclude'] ?? [];
	$normalize = function($path) use ($appPath){
		$path = (string)$path;
		if ($path === \phlo\void) return $appPath;
		return $path[0] === \phlo\slash ? $path : $appPath.$path;
	};
	$appPaths = \array_key_exists('app', $paths) ? $paths['app'] : [$appPath];
	\is_array($appPaths) || $appPaths = [$appPaths];
	$appFiles = [];
	foreach ($appPaths AS $path){
		$dir = \rtrim($normalize($path), \phlo\slash).\phlo\slash;
		if (!\is_dir($dir)) continue;
		foreach (\glob($dir.'*.phlo') AS $appFile) $appFiles[] = $appFile;
	}
	$functionPaths = $paths['functions'] ?? [];
	\is_array($functionPaths) || $functionPaths = [$functionPaths];
	$functionFiles = [];
	foreach ($build['functions'] ?? [] AS $function){
		$found = null;
		foreach ($functionPaths AS $path){
			$functionFile = \rtrim($normalize($path), \phlo\slash).\phlo\slash.$function.'.phlo';
			if (\is_file($functionFile)){ $found = $functionFile; break; }
		}
		$found || \phlo\error('Build Error: Function not found '.$function);
		$functionFiles[] = $found;
	}
	$libPaths = $paths['libs'] ?? [];
	\is_array($libPaths) || $libPaths = [$libPaths];
	$libFiles = [];
	foreach ($build['libs'] ?? [] AS $lib){
		if ($exclude && \in_array(\basename($lib), $exclude, true)) continue;
		$found = null;
		foreach ($libPaths AS $path){
			$libFile = \rtrim($normalize($path), \phlo\slash).\phlo\slash.$lib.'.phlo';
			if (\is_file($libFile)){ $found = $libFile; break; }
		}
		$found || \phlo\error('Build Error: Library not found '.$lib);
		$libFiles[] = $found;
	}
	$release = $build['release'] ?? null;
	if ($release){
		$build['release'] = \array_replace(['php' => $appPath.'release/', 'www' => $appPath.'release/www/'], \array_map($normalize, $release));
	}
	$result = [
		'build' => $build,
		'sources' => [
			'app' => $appFiles,
			'functions' => $functionFiles,
			'libs' => $libFiles,
		],
	];
	$cache[$key] = $result;
	$mtime[$key] = $time;
	return $result;
}

function build_add(string $type, string $name, ?string $file = null):bool {
	$filePath = \phlo\data.'build.json';
	$json = \json_decode(\file_get_contents($filePath), true);
	if (!\is_array($json)) \phlo\error('Build Error: Invalid data/build.json');
	$key = $type === 'function' ? 'functions' : 'libs';
	$json[$key] ??= [];
	if ($type === 'class') $type = 'lib';
	if ($type === 'lib'){
		$appPath = \phlo\app;
		$paths = $json['paths']['libs'] ?? [];
		\is_array($paths) || $paths = [$paths];
		foreach ($paths AS $path){
			$path = (string)$path;
			if ($path === \phlo\void) $base = $appPath;
			elseif ($path[0] === \phlo\slash) $base = $path;
			else $base = $appPath.$path;
			$base = \rtrim($base, \phlo\slash).\phlo\slash;
			if ($file && \str_starts_with($file, $base)){
				$rel = \substr($file, \strlen($base));
				$name = \strtr(\substr($rel, 0, -5), [\phlo\bs => \phlo\slash]);
				break;
			}
		}
	}
	if (\in_array($name, $json[$key], true)) return false;
	$json[$key][] = $name;
	\natcasesort($json[$key]);
	$json[$key] = \array_values($json[$key]);
	\file_put_contents($filePath, \json_encode($json, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	return true;
}

function find_symbol(string $type, string $name):?array {
	$data = sources();
	$paths = $data['build']['paths'][$type === 'function' ? 'functions' : 'libs'] ?? [];
	\is_array($paths) || $paths = [$paths];
	$appPath = \phlo\app;
	foreach ($paths AS $base){
		$base = (string)$base;
		if ($base === \phlo\void) $base = $appPath;
		elseif ($base[0] !== \phlo\slash) $base = $appPath.$base;
		$base = \rtrim($base, \phlo\slash).\phlo\slash;
		if (!\is_dir($base)) continue;
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
		foreach ($it AS $file){
			if ($file->getExtension() !== 'phlo') continue;
			if ($file->getBasename('.phlo') !== $name) continue;
			return ['type' => $type, 'name' => $name, 'file' => (string)$file, 'base' => $base];
		}
	}
	return null;
}

function var_export($expression, int $indent = 0):string {
	if (\is_null($expression)) return 'null';
	if (\is_bool($expression)) return $expression ? 'true' : 'false';
	if (\is_int($expression) || \is_float($expression)) return (string)$expression;
	if (\is_string($expression)) return "'".addcslashes($expression, "'\\")."'";
	if (!\is_array($expression)) return \var_export($expression, true);
	if (!$expression) return '[]';
	$isSequential = \array_keys($expression) === \range(0, \count($expression) - 1);
	$tab = \str_repeat(\phlo\tab, $indent);
	$inner = \str_repeat(\phlo\tab, $indent + 1);
	$items = [];
	foreach ($expression AS $key => $value){
		$val = var_export($value, $indent + 1);
		$items[] = $isSequential ? $val : var_export($key).' => '.$val;
	}
	if (\count($items) <= 3 && !\str_contains(\implode(\phlo\void, $items), \phlo\lf)){
		$inline = \implode(', ', $items);
		if (\strlen($inline) < 80) return '['.$inline.']';
	}
	return "[\n$inner".\implode(",\n$inner", $items).",\n$tab]";
}
