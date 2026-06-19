<?php
function trace(string $node, array $args = []):void {
	if (!trace::$on) return;
	trace::$events[] = [microtime(true), $node, trace::snap($args)];
}

class trace {
	public static bool $on = false;
	public static array $events = [];
	public static string $id = void;
	public static float $t0 = 0.0;
	public static string $route = void;

	public static function boot(string $appPath):void {
		self::$on = true;
		self::$t0 = microtime(true);
		self::$id = date('Ymd-His').'-'.substr(uniqid(), -4);
		self::$route = ($_SERVER['REQUEST_METHOD'] ?? qm).space.($_SERVER['REQUEST_URI'] ?? qm);
		register_shutdown_function([self::class, 'flush']);
	}

	public static function snap(array $args):array {
		$out = [];
		foreach ($args as $k => $v) $out[$k] = self::snapValue($v);
		return $out;
	}

	private static function snapValue(mixed $v):mixed {
		if (is_null($v) || is_bool($v) || is_int($v) || is_float($v)) return $v;
		if (is_string($v)) return strlen($v) > 200 ? substr($v, 0, 200).'...' : $v;
		if (is_array($v)) return '['.count($v).' items]';
		if (is_object($v)){
			$class = get_class($v);
			if (isset($v->id) && is_scalar($v->id)) return ['class' => $class, 'id' => $v->id];
			return ['class' => $class];
		}
		return '['.gettype($v).']';
	}

	public static function flush():void {
		if (!self::$on || !self::$events) return;
		if (!defined('data')) return;
		$dir = data.'trace'.slash;
		if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return;
		$out = self::build();
		file_put_contents($dir.self::$id.'.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
		self::indexUpdate($dir, $out);
		self::prune($dir);
	}

	private static function build():array {
		$srcMap = self::sourceMap();
		$events = [];
		foreach (self::$events as $e){
			[$mt, $node, $args] = $e;
			[$kind, $class, $name] = self::parseNode($node);
			$entry = ['t' => round(($mt - self::$t0) * 1000, 3), 'k' => $kind, 'c' => $class, 'n' => $name, 'node' => $node];
			if ($args) $entry['args'] = $args;
			$src = $srcMap[strtolower($class)] ?? null;
			if ($src) $entry['f'] = $src;
			$events[] = $entry;
		}
		$active = [];
		$seq    = [];
		$prev   = null;
		foreach ($events as $e){
			$key = $e['f'] ?? ($e['c'] ?: $e['n']);
			$active[$key][$e['k']] = ($active[$key][$e['k']] ?? 0) + 1;
			if ($key !== $prev){ $seq[] = $key; $prev = $key; }
		}
		return [
			'id'       => self::$id,
			'path'     => $_SERVER['REQUEST_URI']    ?? qm,
			'method'   => $_SERVER['REQUEST_METHOD'] ?? qm,
			'route'    => self::$route,
			'ts'       => round(self::$t0, 4),
			'ms'       => round((microtime(true) - self::$t0) * 1000, 2),
			'count'    => count($events),
			'active'   => $active,
			'sequence' => $seq,
			'events'   => $events,
		];
	}

	private static function parseNode(string $node):array {
		if (str_contains($node, '::')){ [$c, $n] = explode('::', $node, 2); return ['static', $c, $n]; }
		if (str_contains($node, '->')){
			[$c, $n] = explode('->', $node, 2);
			if (str_ends_with($n, ' (get)')) return ['get', $c, substr($n, 0, -6)];
			if (str_ends_with($n, ' (set)')) return ['set', $c, substr($n, 0, -6)];
			return ['call', $c, $n];
		}
		return ['function', void, $node];
	}

	private static function sourceMap():array {
		if (!defined('php')) return [];
		$cm = php.'classmap.php';
		$sm = php.'sourcemap.php';
		if (!is_file($cm) || !is_file($sm)) return [];
		$classmap  = require $cm;
		$sourcemap = require $sm;
		$out = [];
		foreach ($classmap as $class => $phpFile){
			$src = $sourcemap[php.$phpFile]['source'] ?? null;
			if ($src) $out[strtolower($class)] = $src;
		}
		return $out;
	}

	private static function indexUpdate(string $dir, array $out):void {
		$file = $dir.'index.json';
		$index = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
		$index[] = ['id' => $out['id'], 'ts' => $out['ts'], 'route' => $out['route'], 'path' => $out['path'], 'method' => $out['method'], 'ms' => $out['ms'], 'count' => $out['count']];
		usort($index, fn($a, $b) => $b['ts'] <=> $a['ts']);
		file_put_contents($file, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
	}

	private static function prune(string $dir, int $keep = 100):void {
		$file = $dir.'index.json';
		if (!is_file($file)) return;
		$index = json_decode((string)file_get_contents($file), true) ?: [];
		if (count($index) <= $keep) return;
		foreach (array_slice($index, $keep) as $entry) @unlink($dir.$entry['id'].'.json');
		$index = array_slice($index, 0, $keep);
		file_put_contents($file, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
	}
}
