<?php

if (!build) error('Reflection requires build mode');

require_once __DIR__.slash.'file.php';
require_once __DIR__.slash.'node.php';
require_once __DIR__.slash.'build.php';

class reflect {

	/** Returns all .phlo source file paths for the current app. */
	public static function sourceFiles():array {
		$files = [];
		foreach (static::appPaths() as $path){
			foreach (glob($path.'*.phlo') ?: [] as $file) $files[] = $file;
		}
		natcasesort($files);
		return array_values(array_unique($files));
	}

	/** Returns a parsed node structure for every source file, keyed by file path. Pass true to include node bodies. */
	public static function sourceNodes(bool $withBody = false):array {
		$data = [];
		foreach (static::sourceFiles() as $path) $data[$path] = static::sourcePruneNulls(static::sourceDumpFile(new build_file($path), $withBody));
		return $data;
	}

	/** Returns all available resource file paths. */
	public static function resourceFiles():array {
		$catalog   = static::resourceIndex();
		$resources = array_values(array_map(static fn($item) => $item['file'], $catalog['resources']));
		natcasesort($resources);
		return array_values($resources);
	}

	/** Returns detailed route information for all routes including file, line, method, and dependency usage. */
	public static function routes():array {
		$deps = static::dependencyCatalog();
		$routes = [];
		foreach (static::routeFiles() as $entry){
			$parsed = new build_file((string)$entry['file']);
			$methodBodies = static::appMethodBodies($parsed);
			foreach (($parsed->nodes ?? []) as $node){
				if (($node->node ?? null) !== 'route') continue;
				$routes[] = static::routeNodeEntry((string)$entry['origin'], (string)$entry['name'], $node, $methodBodies, $deps);
			}
		}
		return $routes;
	}

	/** Returns all view nodes across source files with file, name, and line. Pass true to include view bodies. */
	public static function views(bool $withBody = false):array {
		$out = [];
		foreach (static::sourceNodes($withBody) as $path => $data){
			foreach (($data['nodes'] ?? []) as $node){
				if (($node['node'] ?? null) !== 'view') continue;
				$entry = [
					'file'    => static::displayPath($path),
					'name'    => ($node['name'] ?? void) ?: null,
					'line'    => ($node['line'] ?? 0),
					'summary' => ($node['comments'] ?? void) ?: null,
				];
				if ($withBody) $entry['body'] = ($node['body'] ?? void);
				$out[] = static::sourcePruneNulls($entry);
			}
		}
		return $out;
	}

	/** Returns the body of a named node by type. Searches source files first, then resources. Type defaults to 'method'. */
	public static function nodeBody(string $name, string $type = 'method'):?string {
		$name = trim($name);
		$type = trim($type);
		if ($name === void) return null;
		$group = $type === 'function' ? 'functions' : 'nodes';
		$location = null;
		foreach (static::sourceNodes(false) as $path => $data){
			foreach (($data[$group] ?? []) as $node){
				if (($node['node'] ?? null) !== $type) continue;
				if (trim(($node['name'] ?? void)) !== $name) continue;
				$location = $path;
				break 2;
			}
		}
		if ($location !== null){
			$data = static::sourcePruneNulls(static::sourceDumpFile(new build_file($location), true));
			foreach (($data[$group] ?? []) as $node){
				if (($node['node'] ?? null) !== $type) continue;
				if (trim(($node['name'] ?? void)) !== $name) continue;
				return ($node['body'] ?? void) ?: null;
			}
		}
		foreach (static::resourceNodes(false) as $resData){
			foreach (($resData[$group] ?? []) as $node){
				if (($node['node'] ?? null) !== $type) continue;
				if (trim(($node['name'] ?? void)) !== $name) continue;
				$filePath = ($resData['file'] ?? void);
				if ($filePath === void) continue;
				$full = static::sourcePruneNulls(static::sourceDumpFile(new build_file($filePath), true));
				foreach (($full[$group] ?? []) as $fn){
					if (($fn['node'] ?? null) !== $type) continue;
					if (trim(($fn['name'] ?? void)) !== $name) continue;
					return ($fn['body'] ?? void) ?: null;
				}
			}
		}
		return null;
	}

	/** Searches .phlo files for lines matching the query string. Scope: 'app' (source files, default), 'resources', 'all' (both). Pass contextLines > 0 to include surrounding lines. Returns up to maxHits results. */
	public static function search(string $query, string $scope = 'app', int $maxHits = 20, int $contextLines = 0):array {
		$query = trim($query);
		if ($query === void) return [];
		$appFiles = $scope !== 'resources' ? static::sourceFiles() : [];
		$resFiles = $scope !== 'app' ? static::resourceFiles() : [];
		$files    = array_values(array_unique(array_merge($appFiles, $resFiles)));
		$hits = [];
		foreach ($files as $file){
			if (!is_file($file)) continue;
			$lines = explode(lf, (string)file_get_contents($file));
			foreach ($lines as $i => $line){
				if (stripos($line, $query) === false) continue;
				$hit = [
					'file'    => static::displayPath($file),
					'line'    => $i + 1,
					'snippet' => trim($line),
				];
				if ($contextLines > 0){
					$from = max(0, $i - $contextLines);
					$to   = min(count($lines) - 1, $i + $contextLines);
					$ctx  = [];
					for ($l = $from; $l <= $to; $l++) $ctx[$l + 1] = $lines[$l];
					$hit['context'] = $ctx;
				}
				$hits[] = $hit;
				if (count($hits) >= $maxHits) return $hits;
			}
		}
		return $hits;
	}

	/** Finds all nodes of a given type, optionally filtered by name. Scope: 'app' (default), 'resources', 'all'. Pass true to include bodies. */
	public static function find(string $type, ?string $name = null, bool $withBody = false, string $scope = 'app'):array {
		$type  = trim($type);
		$name  = $name !== null ? trim($name) : null;
		$group = $type === 'function' ? 'functions' : 'nodes';
		$out   = [];
		$sources = [];
		if ($scope !== 'resources') foreach (static::sourceNodes($withBody) as $path => $data) $sources[static::displayPath($path)] = $data;
		if ($scope !== 'app') foreach (static::resourceNodes($withBody) as $rname => $data) $sources[$rname] = $data;
		foreach ($sources as $label => $data){
			foreach (($data[$group] ?? []) as $node){
				if (($node['node'] ?? null) !== $type) continue;
				if ($name !== null && trim(($node['name'] ?? void)) !== $name) continue;
				$entry = ['file' => $label, 'line' => ($node['line'] ?? 0)];
				($node['name']     ?? null) !== null && $entry['name']    = $node['name'];
				($node['args']     ?? null) !== null && $entry['args']    = $node['args'];
				($node['comments'] ?? null) !== null && $entry['summary'] = static::firstLineStr((string)$node['comments']);
				if ($withBody) $entry['body'] = $node['body'] ?? null;
				$out[] = $entry;
			}
		}
		return $out;
	}

	/** Returns a typed backend overview graph with semantic edges for files, entrypoints, resources, functions, and declared relationships. */
	public static function graph():array {
		$nodes = [];
		$edges = [];
		$resNodes = static::resourceNodes(true);
		$sourceData = static::sourceNodes(true);
		$resIndex = static::graphResourceIndex($resNodes);
		$fnIndex  = static::graphFunctionIndex($resIndex);
		$appMethods = static::graphAppMethodIndex($sourceData);
		$classIndex = static::graphClassIndex($sourceData, $resIndex, $appMethods);

		foreach ($sourceData as $path => $data){
			$fileKey = static::displayPath($path);
			$fileId  = 'file:'.$fileKey;
			static::graphNode($nodes, $fileId, ['label' => static::displayName($fileKey), 'type' => 'file', 'categories' => ['app'], 'file' => $fileKey, 'mode' => 'app']);
		}

		foreach ($resIndex['loaded'] as $name => $_) static::graphAddResource($nodes, $resIndex, $name);

		foreach ($sourceData as $path => $data){
			$fileKey = static::displayPath($path);
			$fileId  = 'file:'.$fileKey;
			$class   = trim(($data['class'] ?? void));
			$extends = static::metaValue(($data['meta'] ?? []), 'extends');
			if ($extends !== null && strtolower($extends) !== 'obj'){
				$target = static::graphResourceByAlias($extends, $resIndex);
				if ($target){
					static::graphAddResource($nodes, $resIndex, $target);
					static::graphEdge($edges, $fileId, 'res:'.$target, 'extends', 'declared');
				}
			}
			foreach (($data['functions'] ?? []) as $fn){
				$name = trim(($fn['name'] ?? void));
				if ($name === void) continue;
				$body = ($fn['body'] ?? void);
				if ($body !== void) static::graphScanCalls($nodes, $edges, $fileId, $body, $fileKey, $class, $extends, [], $fnIndex, $resIndex, $classIndex, ($fn['line'] ?? 0));
			}
			foreach (($data['nodes'] ?? []) as $node){
				$type = ($node['node'] ?? 'node');
				$line = ($node['line'] ?? 0);
				$body = ($node['body'] ?? void);
				if ($body !== void) static::graphScanCalls($nodes, $edges, $fileId, $body, $fileKey, $class, $extends, $appMethods[$fileKey] ?? [], $fnIndex, $resIndex, $classIndex, $line);
			}
		}

		foreach ($resIndex['items'] as $name => $info){
			if (!isset($resIndex['loaded'][$name])) continue;
			foreach (($info['requires'] ?? []) as $req){
				$target = static::graphResourceByAlias($req, $resIndex);
				if ($target){
					static::graphAddResource($nodes, $resIndex, $target);
					static::graphEdge($edges, 'res:'.$name, 'res:'.$target, 'requires', 'declared');
				}
			}
			$extends = ($info['extends'] ?? void);
			if ($extends !== void && strtolower($extends) !== 'obj'){
				$target = static::graphResourceByAlias($extends, $resIndex);
				if ($target){
					static::graphAddResource($nodes, $resIndex, $target);
					static::graphEdge($edges, 'res:'.$name, 'res:'.$target, 'extends', 'declared');
				}
			}
		}

		foreach ($sourceData as $path => $data){
			$fileKey = static::displayPath($path);
			$fileId  = 'file:'.$fileKey;
			$content = (string)file_get_contents($path);
			if (preg_match_all('/\bphlo\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*[,\)]/i', $content, $m)){
				foreach ($m[1] as $name){
					$entry = $classIndex[strtolower($name)] ?? null;
					if ($entry && ($entry['kind'] ?? null) === 'app' && $entry['file'] !== $fileKey)
						static::graphEdge($edges, $fileId, 'file:'.$entry['file'], 'depends', 'exact');
				}
			}
			if (preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::/', $content, $m)){
				foreach ($m[1] as $name){
					$entry = $classIndex[strtolower($name)] ?? null;
					if ($entry && ($entry['kind'] ?? null) === 'app' && $entry['file'] !== $fileKey)
						static::graphEdge($edges, $fileId, 'file:'.$entry['file'], 'depends', 'exact');
				}
			}
		}

		$resCallers = [];
		foreach ($edges as $e){
			if (str_starts_with((string)($e['from'] ?? void), 'file:') && str_starts_with((string)($e['to'] ?? void), 'res:')){
				$resCallers[$e['to']][$e['from']] = true;
			}
		}
		foreach ($resCallers as $callers){
			$callers = array_keys($callers);
			if (count($callers) < 2 || count($callers) > 8) continue;
			for ($i = 0; $i < count($callers); $i++){
				for ($j = $i + 1; $j < count($callers); $j++) static::graphEdge($edges, $callers[$i], $callers[$j], 'shared', 'inferred');
			}
		}
		$connected = [];
		foreach ($edges as $e){
			$connected[$e['from']] = true;
			$connected[$e['to']]   = true;
		}
		foreach (array_keys($nodes) as $id){
			if ((str_starts_with($id, 'file:') || str_starts_with($id, 'res:')) && !isset($connected[$id])) unset($nodes[$id]);
		}
		return ['mode' => 'backend', 'nodes' => array_values($nodes), 'edges' => array_values($edges)];
	}

	/** Returns a typed frontend graph with script/style, selector, and declared frontend API relationships. */
	public static function selectorGraph():array {
		$nodes = [];
		$edges = [];
		$resRootPrefix  = rtrim(str_replace(bs, slash, engine), slash).'/resources/';
		$selSources     = [];
		$selEdges       = [];
		$hasAppStyle    = false;
		$hasAppScript   = false;
		foreach (static::sourceFiles() as $path){
			$file    = new build_file($path);
			$fileKey = static::displayPath($path);
			$fileId  = 'file:'.$fileKey;
			$hasCss  = false;
			$hasJs   = false;
			foreach ($file->assets as $asset){
				$body = ($asset->body ?? void);
				if ($body === void) continue;
				if ($asset->node === 'style'){
					$hasAppStyle = $hasCss = true;
					foreach (static::extractCssSelectors($body) as $sel){
						$selSources[$sel][$fileId] = true;
						$selEdges[]   = [$fileId, $sel, 'selector-def'];
					}
				}
				if ($asset->node === 'script'){
					$hasAppScript = $hasJs = true;
					foreach (static::extractJsSelectors($body) as $sel){
						$selSources[$sel][$fileId] = true;
						$selEdges[]   = [$fileId, $sel, 'selector-use'];
					}
				}
			}
			foreach ($file->nodes as $node){
				if ((string)($node->node ?? void) !== 'view') continue;
				$body = trim(($node->body ?? void));
				if ($body === void) continue;
				$hasCss = true;
				foreach (static::extractViewSelectors($body) as $sel){
					$selSources[$sel][$fileId] = true;
					$selEdges[] = [$fileId, $sel, 'selector-def'];
				}
			}
			if ($hasCss || $hasJs) static::graphNode($nodes, $fileId, ['label' => static::displayName($fileKey), 'type' => 'file', 'categories' => ['app'], 'file' => $fileKey, 'mode' => 'app']);
			if ($hasCss) static::graphEdge($edges, $fileId, 'asset:app:style',  'style',  'exact');
			if ($hasJs)  static::graphEdge($edges, $fileId, 'asset:app:script', 'script', 'exact');
		}
		if ($hasAppStyle)  static::graphNode($nodes, 'asset:app:style',  ['label' => 'app style',  'type' => 'style',  'categories' => ['style',  'app']]);
		if ($hasAppScript) static::graphNode($nodes, 'asset:app:script', ['label' => 'app script', 'type' => 'script', 'categories' => ['script', 'app']]);
		$hasResStyle  = false;
		$hasResScript = false;
		$loadedRes = array_fill_keys(static::loadedConfigNames(), true);
		foreach (static::resourceNodes(true) as $name => $node){
			if (!isset($loadedRes[$name])) continue;
			$filePath = str_replace(bs, slash, ($node['file'] ?? void));
			if ($filePath === void) continue;
			$fileKey = str_starts_with($filePath, $resRootPrefix) ? substr($filePath, strlen($resRootPrefix)) : basename($filePath);
			$fileId  = 'res:'.$name;
			$meta    = ($node['meta'] ?? []);
			$f = new build_file($filePath);
			foreach ($f->assets as $asset){
				$body = ($asset->body ?? void);
				if ($body === void) continue;
				if ($asset->node === 'style'){
					$hasResStyle = true;
					static::graphNode($nodes, $fileId, ['label' => $name, 'type' => 'resource', 'categories' => ['resource'], 'file' => $fileKey, 'mode' => 'resources']);
					static::graphEdge($edges, $fileId, 'asset:resource:style', 'style', 'exact', $asset->line);
					foreach (static::extractCssSelectors($body) as $sel){
						$selSources[$sel][$fileId] = true;
						$selEdges[] = [$fileId, $sel, 'selector-def'];
					}
				}
				if ($asset->node === 'script'){
					$hasResScript = true;
					static::graphNode($nodes, $fileId, ['label' => $name, 'type' => 'resource', 'categories' => ['resource'], 'file' => $fileKey, 'mode' => 'resources']);
					static::graphEdge($edges, $fileId, 'asset:resource:script', 'script', 'exact', $asset->line);
					foreach (static::extractJsSelectors($body) as $sel){
						$selSources[$sel][$fileId] = true;
						$selEdges[] = [$fileId, $sel, 'selector-use'];
					}
				}
			}
			foreach (static::metaList($meta, 'provides') as $provided){
				static::graphNode($nodes, $fileId, ['label' => $name, 'type' => 'resource', 'categories' => ['resource'], 'file' => $fileKey, 'mode' => 'resources']);
				static::graphNode($nodes, 'api:'.$provided, ['label' => $provided, 'type' => 'frontend-api', 'categories' => ['api']]);
				static::graphEdge($edges, $fileId, 'api:'.$provided, 'provides', 'declared');
			}
			foreach (static::metaList($meta, 'binds') as $bind){
				static::graphNode($nodes, $fileId, ['label' => $name, 'type' => 'resource', 'categories' => ['resource'], 'file' => $fileKey, 'mode' => 'resources']);
				static::graphNode($nodes, 'bind:'.$bind, ['label' => $bind, 'type' => 'binding', 'categories' => ['binds']]);
				static::graphEdge($edges, $fileId, 'bind:'.$bind, 'binds', 'declared');
			}
		}
		if ($hasResStyle)  static::graphNode($nodes, 'asset:resource:style',  ['label' => 'resource style',  'type' => 'style',  'categories' => ['style',  'resource']]);
		if ($hasResScript) static::graphNode($nodes, 'asset:resource:script', ['label' => 'resource script', 'type' => 'script', 'categories' => ['script', 'resource']]);
		foreach ($selEdges as [$from, $sel, $kind]){
			if (count($selSources[$sel] ?? []) < 2) continue;
			$sid = 'sel:'.$sel;
			static::graphNode($nodes, $sid, ['label' => $sel, 'type' => 'selector', 'categories' => ['selector', ($sel[0] ?? void) === '#' ? 'id' : 'class']]);
			static::graphEdge($edges, $from, $sid, $kind, 'exact');
		}
		return ['mode' => 'frontend', 'nodes' => array_values($nodes), 'edges' => array_values($edges)];
	}

	private static function extractCssSelectors(string $css):array {
		$out = [];
		$css = preg_replace('|/\*.*?\*/|s', '', $css);
		preg_match_all('/([^{}]+)\{/', $css, $m);
		foreach ($m[1] as $block){
			$block = trim($block);
			if (str_starts_with($block, '@')) continue;
			foreach (explode(',', $block) as $sel){
				preg_match_all('/[.#][a-zA-Z_][a-zA-Z0-9_-]*|\[data-[a-zA-Z0-9_-]+\]/', $sel, $tokens);
				foreach ($tokens[0] as $t) $out[] = $t;
			}
		}
		return array_values(array_unique($out));
	}

	private static function extractJsSelectors(string $js):array {
		$out = [];
		$selectorPatterns = [
			'/\bon\s*\(\s*[\'"][^\'"]*[\'"],\s*[\'"]([^\'"]+)[\'"]/',
			'/\bobj(?:ects)?\s*\(\s*[\'"]([^\'"]+)[\'"]/',
			'/\bquerySelector(?:All)?\s*\(\s*[\'"]([^\'"]+)[\'"]/',
		];
		foreach ($selectorPatterns as $re){
			preg_match_all($re, $js, $m);
			foreach ($m[1] as $sel){
				preg_match_all('/[.#][a-zA-Z_][a-zA-Z0-9_-]*|\[data-[a-zA-Z0-9_-]+\]/', $sel, $tokens);
				foreach ($tokens[0] as $t) $out[] = $t;
			}
		}
		preg_match_all('/\.classList\.(?:add|remove|toggle|contains|replace)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $js, $m1);
		foreach ($m1[1] as $name){ $name = trim($name); if ($name !== void) $out[] = '.'.$name; }
		preg_match_all('/\.classList\.replace\s*\(\s*[\'"][^\'"]+[\'"],\s*[\'"]([^\'"]+)[\'"]/', $js, $m2);
		foreach ($m2[1] as $name){ $name = trim($name); if ($name !== void) $out[] = '.'.$name; }
		return array_values(array_unique($out));
	}

	private static function extractViewSelectors(string $body):array {
		$out = [];
		preg_match_all('/<[a-zA-Z][a-zA-Z0-9]*(?:\.[a-zA-Z_][a-zA-Z0-9._-]*)/', $body, $m);
		foreach ($m[0] as $match){
			preg_match_all('/\.[a-zA-Z_][a-zA-Z0-9_-]*/', $match, $tokens);
			foreach ($tokens[0] as $t) $out[] = $t;
		}
		return array_values(array_unique($out));
	}

	private static function subtypeFor(string $rtype, ?string $ext):string {
		if (in_array($rtype, ['script', 'style', 'both'], true)) return $rtype;
		return 'other';
	}

	private static function graphNode(array &$nodes, string $id, array $data):void {
		if (isset($nodes[$id])){
			$nodes[$id] = array_replace($nodes[$id], array_filter($data, static fn($v) => $v !== null));
			return;
		}
		$nodes[$id] = ['id' => $id] + array_filter($data, static fn($v) => $v !== null);
	}

	private static function graphEdge(array &$edges, string $from, string $to, string $kind, string $source = 'inferred', int $line = 0):void {
		if ($from === void || $to === void || $from === $to) return;
		$key = $from.'|'.$to.'|'.$kind.'|'.$source;
		if (isset($edges[$key])){
			$edges[$key]['count'] = (int)($edges[$key]['count'] ?? 1) + 1;
			return;
		}
		$edges[$key] = array_filter(['from' => $from, 'to' => $to, 'kind' => $kind, 'source' => $source, 'line' => $line ?: null], static fn($v) => $v !== null);
	}

	private static function graphResourceIndex(array $resNodes):array {
		$loaded = array_fill_keys(static::loadedConfigNames(), true);
		$items  = [];
		$alias  = [];
		foreach ($resNodes as $name => $node){
			$name = $name;
			$meta = ($node['meta'] ?? []);
			$file = str_replace(bs, slash, ($node['file'] ?? void));
			$fileKey = static::resourceFileKey($file);
			$class = trim(($node['class'] ?? static::metaValue($meta, 'class') ?? void));
			$methods = [];
			$statics = [];
			foreach (($node['nodes'] ?? []) as $item){
				$n = trim(($item['name'] ?? void));
				if ($n === void) continue;
				if (($item['node'] ?? null) === 'static') $statics[strtolower($n)] = $item;
				elseif (in_array(($item['node'] ?? null), ['method', 'route', 'view'], true)) $methods[strtolower($n)] = $item;
			}
			$items[$name] = [
				'name'     => $name,
				'file'     => $fileKey,
				'mode'     => isset($loaded[$name]) ? 'resources' : 'available',
				'class'    => $class !== void ? $class : null,
				'line'     => static::firstLineNode($node),
				'extends'  => static::metaValue($meta, 'extends'),
				'requires' => static::metaList($meta, 'requires'),
				'methods'  => $methods,
				'statics'  => $statics,
			];
			foreach (array_unique(array_filter([$name, basename($name), $class])) as $a){
				$k = strtolower((string)$a);
				if (!isset($alias[$k])) $alias[$k] = $name;
				elseif ($alias[$k] !== $name) $alias[$k] = null;
			}
		}
		return ['items' => $items, 'alias' => $alias, 'loaded' => $loaded];
	}

	private static function graphFunctionIndex(array $resIndex):array {
		$out = [];
		foreach (static::availableFunctions() as $item){
			$name = ($item['name'] ?? void);
			if ($name === void) continue;
			$resName = ($item['resource'] ?? void);
			$mode = ($item['source'] ?? void) === 'native' ? 'native' : ($resIndex['items'][$resName]['mode'] ?? 'available');
			$out[strtolower($name)] = [
				'name' => $name,
				'id'   => 'fn:'.$name,
				'type' => 'function',
				'mode' => $mode,
				'resource' => $resName !== void ? $resName : null,
				'file' => $resName !== void ? ($resIndex['items'][$resName]['file'] ?? null) : null,
				'line' => ($item['line'] ?? 0),
			];
		}
		return $out;
	}

	private static function graphClassIndex(array $sourceData, array $resIndex, array $appMethods):array {
		$out = [];
		foreach ($sourceData as $path => $data){
			$class = trim(($data['class'] ?? void));
			$fileKey = static::displayPath($path);
			if ($class !== void) $out[strtolower($class)] = ['kind' => 'app', 'file' => $fileKey, 'methods' => $appMethods[$fileKey] ?? [], 'extends' => static::metaValue(($data['meta'] ?? []), 'extends')];
		}
		foreach ($resIndex['items'] as $name => $item){
			foreach (array_unique(array_filter([$name, basename($name), $item['class'] ?? null])) as $alias){
				$out[strtolower((string)$alias)] ??= ['kind' => 'resource', 'name' => $name];
			}
		}
		return $out;
	}

	private static function graphAppMethodIndex(array $sourceData):array {
		$out = [];
		foreach ($sourceData as $path => $data){
			$fileKey = static::displayPath($path);
			$fileId = 'file:'.$fileKey;
			foreach (($data['nodes'] ?? []) as $node){
				$name = trim(($node['name'] ?? void));
				if ($name === void) continue;
				$out[$fileKey][strtolower($name)] = $fileId;
			}
		}
		return $out;
	}

	private static function graphAddResource(array &$nodes, array $resIndex, string $name):void {
		$item = $resIndex['items'][$name] ?? null;
		if (!$item) return;
		static::graphNode($nodes, 'res:'.$name, [
			'label' => $name,
			'type' => 'resource',
			'categories' => ['resource'],
			'file' => $item['file'],
			'mode' => $item['mode'],
			'line' => $item['line'],
			'extends' => $item['extends'],
		]);
	}

	private static function graphResourceByAlias(string $name, array $resIndex):?string {
		$name = trim(ltrim($name, '@'));
		if ($name === void || str_ends_with($name, '?')) return null;
		if (str_starts_with($name, 'php-ext:') || str_starts_with($name, 'creds:')) return null;
		return $resIndex['alias'][strtolower($name)] ?? null;
	}

	private static function graphResourceMethodTarget(array &$nodes, array $resIndex, string $resName, string $method):?string {
		$item = $resIndex['items'][$resName] ?? null;
		if (!$item) return null;
		static::graphAddResource($nodes, $resIndex, $resName);
		return 'res:'.$resName;
	}

	private static function graphFunctionTarget(array &$nodes, array $fnIndex, string $name):?string {
		$key = strtolower($name);
		$info = $fnIndex[$key] ?? null;
		if (!$info) return null;
		if ($info['mode'] === 'native') return null;
		$resName = ($info['resource'] ?? void);
		if ($resName === void) return null;
		static::graphNode($nodes, 'res:'.$resName, [
			'label' => $resName,
			'type' => 'resource',
			'categories' => ['resource'],
			'file' => $info['file'],
			'mode' => $info['mode'],
			'line' => $info['line'],
		]);
		return 'res:'.$resName;
	}

	private static function graphScanCalls(array &$nodes, array &$edges, string $from, string $source, string $fileKey, string $class, ?string $extends, array $methods, array $fnIndex, array $resIndex, array $classIndex, int $baseLine, bool $externalOnly = false):void {
		if ($source === void) return;
		if (preg_match_all('/%([A-Za-z_][A-Za-z0-9_]*)(?:->([A-Za-z_][A-Za-z0-9_]*))?/', $source, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)){
			foreach ($m as $match){
				$resName = static::graphResourceByAlias($match[1][0], $resIndex);
				if (!$resName) continue;
				static::graphAddResource($nodes, $resIndex, $resName);
				$line = $baseLine + substr_count(substr($source, 0, $match[0][1]), lf);
				$method = ($match[2][0] ?? void);
				$target = $method !== void ? static::graphResourceMethodTarget($nodes, $resIndex, $resName, $method) : 'res:'.$resName;
				static::graphEdge($edges, $from, $target ?: 'res:'.$resName, $method !== void ? 'calls' : 'uses', 'exact', $line);
			}
		}
		if (preg_match_all('/\bphlo\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)){
			foreach ($m as $match){
				$resName = static::graphResourceByAlias($match[1][0], $resIndex);
				if (!$resName) continue;
				static::graphAddResource($nodes, $resIndex, $resName);
				$line = $baseLine + substr_count(substr($source, 0, $match[0][1]), lf);
				static::graphEdge($edges, $from, 'res:'.$resName, 'uses', 'exact', $line);
			}
		}
		if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*|static|self)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)){
			foreach ($m as $match){
				$owner = $match[1][0];
				$method = $match[2][0];
				$line = $baseLine + substr_count(substr($source, 0, $match[0][1]), lf);
				$target = null;
				if (in_array(strtolower($owner), ['static', 'self', strtolower($class)], true)){
					$target = $externalOnly ? null : ($methods[strtolower($method)] ?? null);
					if (!$target && $extends !== null && ($resName = static::graphResourceByAlias($extends, $resIndex))) $target = static::graphResourceMethodTarget($nodes, $resIndex, $resName, $method);
				} else {
					$cls = $classIndex[strtolower($owner)] ?? null;
					if (!$externalOnly && ($cls['kind'] ?? null) === 'app'){
						$target = (($cls['methods'] ?? []))[strtolower($method)] ?? null;
						if (!$target && ($cls['extends'] ?? null) !== null && ($resName = static::graphResourceByAlias((string)$cls['extends'], $resIndex))) $target = static::graphResourceMethodTarget($nodes, $resIndex, $resName, $method);
						$target ??= 'file:'.(string)$cls['file'];
					}
					elseif (($cls['kind'] ?? null) === 'resource') $target = static::graphResourceMethodTarget($nodes, $resIndex, (string)$cls['name'], $method);
				}
				if ($target) static::graphEdge($edges, $from, $target, 'calls', 'exact', $line);
			}
		}
		if (preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)){
			foreach ($m as $match){
				if ($externalOnly) continue;
				$target = $methods[strtolower($match[1][0])] ?? null;
				if (!$target) continue;
				$line = $baseLine + substr_count(substr($source, 0, $match[0][1]), lf);
				static::graphEdge($edges, $from, $target, 'calls', 'exact', $line);
			}
		}
		if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)){
			foreach ($m as $match){
				$name = $match[1][0];
				$offset = (int)$match[1][1];
				$before = substr($source, max(0, $offset - 8), min(8, $offset));
				if (preg_match('/(::|->|new\s+)$/', $before)) continue;
				if (in_array(strtolower($name), ['if','elseif','foreach','for','while','switch','catch','isset','empty','array','fn','function','return'], true)) continue;
				$target = static::graphFunctionTarget($nodes, $fnIndex, $name);
				if (!$target) continue;
				$line = $baseLine + substr_count(substr($source, 0, $match[0][1]), lf);
				static::graphEdge($edges, $from, $target, 'calls', 'exact', $line);
			}
		}
	}

	private static function resourceFileKey(string $file):string {
		$resRootPrefix = rtrim(str_replace(bs, slash, engine), slash).'/resources/';
		return str_starts_with($file, $resRootPrefix) ? substr($file, strlen($resRootPrefix)) : basename($file);
	}

	private static function displayName(string $file):string {
		$file = str_replace(bs, slash, $file);
		return str_ends_with($file, '.phlo') ? substr($file, 0, -5) : $file;
	}

	/** Returns all loaded functions with their signatures, types, and metadata. */
	public static function functionIndex():array {
		$out = [];
		foreach (static::availableFunctions() as $item){
			$name = ($item['name'] ?? void);
			if ($name === void) continue;
			$out[$name] = [
				'args'    => $item['args'] ?? void,
				'return'  => $item['return'] ?? 'mixed',
				'file'    => static::displayPath(($item['file'] ?? void)),
				'line'    => ($item['line'] ?? 0),
				'summary' => $item['summary'] ?? null,
				'package' => $item['package'] ?? null,
				'source'  => $item['source'] ?? null,
				'group'   => $item['group'] ?? null,
				'loaded'  => (bool)($item['loaded'] ?? false),
			];
		}
		uksort($out, 'strnatcasecmp');
		return $out;
	}

	/** Returns all available resource objects with their methods, props, constructor, and metadata. */
	public static function objectIndex():array {
		$out = [];
		foreach (static::availableObjects() as $item){
			$name = ($item['class'] ?? $item['name'] ?? void);
			if ($name === void) continue;
			$out[$name] = [
				'file'          => static::displayPath(($item['file'] ?? void)),
				'class'         => $item['class'] ?? null,
				'summary'       => $item['summary'] ?? null,
				'package'       => $item['package'] ?? null,
				'loaded'        => (bool)($item['loaded'] ?? false),
				'frontend'      => $item['frontend'] ?? null,
				'backend'       => $item['backend'] ?? null,
				'ctor'          => static::constructorArgs($item['methods'] ?? []),
				'methods'       => static::entryMap($item['methods'] ?? []),
				'staticMethods' => static::entryMap($item['statics'] ?? []),
				'props'         => static::entryMap($item['props'] ?? []),
			];
		}
		uksort($out, 'strnatcasecmp');
		return $out;
	}

	/** Returns a combined index of functions, objects, routes, and views, useful for editor tooling. */
	public static function editorIndex():array {
		return [
			'functions' => static::functionIndex(),
			'objects'   => static::objectIndex(),
			'routes'    => static::routes(),
			'views'     => static::views(false),
		];
	}

	/** Returns a summary of loaded packages, resource counts, and external requirements. */
	public static function resourceSummary():array {
		$functions = static::loadedFunctions();
		$objects   = static::loadedObjects();
		$packages  = [];
		$requires  = [];
		foreach ([...$functions, ...$objects] as $item){
			$package = ($item['package'] ?? void);
			$package !== void || $package = 'misc';
			$packages[$package] ??= ['functions' => 0, 'objects' => 0];
			isset($item['class']) ? $packages[$package]['objects']++ : $packages[$package]['functions']++;
			foreach (($item['requires'] ?? []) as $require) $requires[$require] = true;
		}
		ksort($packages, SORT_NATURAL | SORT_FLAG_CASE);
		ksort($requires, SORT_NATURAL | SORT_FLAG_CASE);
		return [
			'functions' => count($functions),
			'objects'   => count($objects),
			'packages'  => $packages,
			'requires'  => array_keys($requires),
		];
	}

	/** Returns required resource names for a given resource, resolved from @ requires metadata. Pass full: true to also include phpExt and creds lists. */
	public static function resourceDependencies(string $name, bool $transitive = true, bool $full = false):array {
		$resIndex = static::graphResourceIndex(static::resourceNodes(false));
		$root = static::graphResourceByAlias($name, $resIndex);
		if (!$root) return [];
		$intern = [];
		$phpExt = [];
		$creds  = [];
		$seen   = [$root => true];
		$queue  = [$root];
		while ($queue){
			$current = array_shift($queue);
			foreach (($resIndex['items'][$current]['requires'] ?? []) as $req){
				$req = (string)$req;
				if (str_starts_with($req, 'php-ext:')){
					$full && $phpExt[] = substr($req, 8);
					continue;
				}
				if (str_starts_with($req, 'creds:')){
					$full && $creds[] = substr($req, 6);
					continue;
				}
				$dep = static::graphResourceByAlias($req, $resIndex);
				if (!$dep || isset($seen[$dep])) continue;
				$seen[$dep] = true;
				$intern[] = $dep;
				if ($transitive) $queue[] = $dep;
			}
		}
		natcasesort($intern);
		if (!$full) return array_values($intern);
		$out = ['intern' => array_values($intern)];
		$phpExt && $out['phpExt'] = array_values(array_unique($phpExt));
		$creds  && $out['creds']  = array_values(array_unique($creds));
		return $out;
	}

	/** Returns per-source-file dependencies on other app files, detected from phlo() and ClassName:: calls. Keys and values are display paths. */
	public static function fileDependencies():array {
		$sourceData = static::sourceNodes(false);
		$classToFile = [];
		foreach ($sourceData as $path => $data){
			$class = trim(($data['class'] ?? void));
			if ($class !== void) $classToFile[strtolower($class)] = static::displayPath($path);
		}
		$out = [];
		foreach ($sourceData as $path => $data){
			$fileKey = static::displayPath($path);
			$content = (string)file_get_contents($path);
			$deps    = [];
			if (preg_match_all('/\bphlo\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*[,\)]/i', $content, $m)){
				foreach ($m[1] as $name){
					$t = $classToFile[strtolower($name)] ?? null;
					if ($t && $t !== $fileKey) $deps[$t] = true;
				}
			}
			if (preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::/', $content, $m)){
				foreach ($m[1] as $name){
					$t = $classToFile[strtolower($name)] ?? null;
					if ($t && $t !== $fileKey) $deps[$t] = true;
				}
			}
			if ($deps) $out[$fileKey] = array_values(array_keys($deps));
		}
		uksort($out, 'strnatcasecmp');
		return $out;
	}

	/** Returns a compact route list with route path, file, line, and dependency summary. */
	public static function compactRoutes():array {
		$out = [];
		foreach (static::routes() as $route){
			$method = ($route['method'] ?? 'ANY');
			$path = trim(($route['path'] ?? void));
			$item = [
				'route' => $path === void ? $method : $method.space.$path,
				'file'  => $route['file'] ?? null,
				'line'  => $route['line'] ?? null,
			];
			$functions = array_values(($route['uses']['functions'] ?? []));
			$resources = array_values(($route['uses']['resources'] ?? []));
			$methods   = array_values(($route['uses']['appMethods'] ?? []));
			$functions && $item['functions'] = $functions;
			$resources && $item['resources'] = $resources;
			$methods   && $item['methods']   = $methods;
			$comments = array_values(array_filter(($route['comments'] ?? []), 'strlen'));
			$comments && $item['summary'] = static::firstLineStr($comments[0]);
			$out[] = $item;
		}
		return $out;
	}

	/** Returns a compact view list with name, file, and line. */
	public static function compactViews():array {
		$out = [];
		foreach (static::views(false) as $view){
			$item = [
				'name' => $view['name'] ?? null,
				'file' => $view['file'] ?? null,
				'line' => $view['line'] ?? null,
			];
			if (!empty($view['summary'])) $item['summary'] = static::firstLineStr((string)$view['summary']);
			$out[] = $item;
		}
		return $out;
	}

	/** Returns the contents of data/app.md, or null if the file does not exist. */
	public static function appInfo():?string {
		$file = data.'app.md';
		if (!is_file($file)) return null;
		return trim((string)file_get_contents($file)) ?: null;
	}

	/** Returns a parsed node structure for every resource file, keyed by name. */
	private static function resourceNodes(bool $withBody = false):array {
		static $cache = [];
		$key = ($withBody ? '1' : '0').':'.static::resourceStamp();
		if (isset($cache[$key])) return $cache[$key];
		$catalog = static::resourceIndex();
		$data = [];
		foreach ($catalog['resources'] as $item){
			$file = new build_file($item['file']);
			$data[$item['name']] = static::sourcePruneNulls(static::sourceDumpFile($file, $withBody));
		}
		uksort($data, 'strnatcasecmp');
		return $cache[$key] = $data;
	}

	/** Returns all discoverable resources with load status, type flags, and metadata. */
	public static function availableResources():array {
		$nodes = static::resourceNodes(false);
		$loaded = array_fill_keys(array_map('strtolower', static::loadedConfigNames()), true);
		$out = [];
		foreach ($nodes as $name => $node){
			$meta = ($node['meta'] ?? []);
			$hasFunctions = !empty($node['functions']);
			$hasClass = static::resourceHasClass($node);
			$hasAssets = !empty($node['assets']);
			$kind = $hasClass ? 'object' : ($hasFunctions ? 'function' : ($hasAssets ? 'frontend' : 'resource'));
			$out[$name] = [
				'name'      => $name,
				'file'      => ($node['file'] ?? void),
				'class'     => $hasClass ? ($node['class'] ?? null) : null,
				'kind'      => $kind,
				'loaded'    => isset($loaded[strtolower($name)]),
				'functions' => count(($node['functions'] ?? [])),
				'nodes'     => count(($node['nodes'] ?? [])),
				'assets'    => count(($node['assets'] ?? [])),
				'summary'   => static::metaSummary($meta),
				'package'   => static::metaValue($meta, 'package'),
				'frontend'  => static::metaBool($meta, 'frontend'),
				'backend'   => static::metaBool($meta, 'backend'),
			];
		}
		uksort($out, 'strnatcasecmp');
		return array_values($out);
	}

	/** Returns all currently loaded functions with their full metadata. */
	private static function loadedFunctions():array {
		$resources = static::resourceNodes();
		$loaded = [];
		foreach (static::coreFunctions() as $item) $loaded[$item['name']] = $item + ['loaded' => true];
		foreach (static::loadedConfigNames() as $key){
			$node = $resources[$key] ?? null;
			if (!$node) continue;
			if (empty($node['functions'])) continue;
			$item = static::functionEntryFromResource($key, $node, true);
			$loaded[$item['resource']] = $item;
		}
		uksort($loaded, 'strnatcasecmp');
		return array_values($loaded);
	}

	/** Returns all available functions (loaded and unloaded) with their full metadata. */
	private static function availableFunctions():array {
		$resources = static::resourceNodes();
		$loaded = [];
		foreach (static::loadedFunctions() as $item) $loaded[strtolower((string)$item['name'])] = true;
		$available = [];
		foreach (static::coreFunctions() as $item) $available[$item['name']] = $item + ['loaded' => true];
		foreach ($resources as $key => $node){
			if (empty($node['functions'])) continue;
			$item = static::functionEntryFromResource($key, $node, isset($loaded[$key]));
			$available[$item['name']] = $item;
		}
		uksort($available, 'strnatcasecmp');
		return array_values($available);
	}

	/** Returns all currently loaded resource objects with their full metadata. */
	private static function loadedObjects():array {
		$resources = static::resourceNodes();
		$loaded = [];
		foreach (static::loadedConfigNames() as $key){
			$node = $resources[$key] ?? null;
			if (!$node) continue;
			if (!static::resourceHasClass($node)) continue;
			$item = static::objectEntryFromResource($key, $node, true);
			$loaded[$item['name']] = $item;
		}
		uksort($loaded, 'strnatcasecmp');
		return array_values($loaded);
	}

	/** Returns all available resource objects (loaded and unloaded) with their full metadata. */
	private static function availableObjects():array {
		$resources = static::resourceNodes();
		$loaded = [];
		foreach (static::loadedObjects() as $item) $loaded[strtolower((string)$item['name'])] = true;
		$available = [];
		foreach ($resources as $key => $node){
			if (!static::resourceHasClass($node)) continue;
			$item = static::objectEntryFromResource($key, $node, isset($loaded[strtolower($key)]));
			$available[$item['name']] = $item;
		}
		uksort($available, 'strnatcasecmp');
		return array_values($available);
	}

	/** Finds a function resource entry by name. Returns null if not found. */
	public static function findFunction(string $name):?array {
		$name = strtolower(trim($name));
		if ($name === void) return null;
		$index = static::resourceIndex();
		foreach ($index['resources'] as $item){
			if (strtolower(static::functionKey((string)$item['name'])) === $name) return $item;
		}
		return null;
	}

	/** Finds a resource class entry by name or class alias. Returns null if not found. */
	public static function findClass(string $name):?array {
		$name = strtolower(trim($name));
		if ($name === void) return null;
		$index = static::resourceIndex();
		return $index['classes'][$name] ?? null;
	}

	/** Returns recent runtime errors logged by the app, keyed by error ID. Limit defaults to 10; pass 0 for all. Returns an empty array if no errors exist. */
	public static function errors(int $limit = 10):array {
		$file = data.'errors.json';
		if (!is_file($file)) return [];
		$all = json_decode((string)file_get_contents($file), true) ?: [];
		return $limit > 0 ? array_slice($all, 0, $limit, true) : $all;
	}

	/** Returns the runtime constants defined by phlo_app() for this app: host, paths, feature flags, and any custom parameters. */
	public static function runtime():array {
		$known = ['app', 'host', 'debug', 'build', 'auth', 'thread', 'data', 'php', 'www', 'cli', 'langs', 'composer', 'websocket'];
		$out   = [];
		foreach ($known as $key){
			if (defined($key)) $out[$key] = constant($key);
		}
		return $out;
	}

	/** Returns all available CLI methods with their signatures and descriptions. */
	public static function help():array {
		return phlo_help_reflect(static::class);
	}

	/** Returns the contents of a source file by its display path (as returned by routes, views, find, etc.). Returns null if not found. */
	public static function fileContent(string $relPath):?string {
		$relPath = trim($relPath);
		if ($relPath === void) return null;
		foreach (static::sourceFiles() as $path){
			if (static::displayPath($path) === $relPath) return (string)file_get_contents($path) ?: null;
		}
		return null;
	}

	/** Returns a high-level snapshot of the app: identity, route and view counts, resource summary, and recent errors. Useful as a first call to orient an AI agent. */
	public static function context():array {
		$routes  = static::compactRoutes();
		$views   = static::compactViews();
		$summary = static::resourceSummary();
		$errors  = static::errors(5);
		$out = [
			'app'      => static::appInfo(),
			'runtime'  => static::runtime(),
			'routes'   => count($routes),
			'views'    => count($views),
			'packages' => $summary['packages'],
			'errors'   => count($errors),
		];
		if ($errors) $out['recent_errors'] = $errors;
		return array_filter($out, static fn($v) => $v !== null && $v !== [] && $v !== 0);
	}

	private static function resourceIndex(bool $reload = false):array {
		static $cache = null, $stamp = null;
		$current = static::resourceStamp();
		if (!$reload && $cache !== null && $stamp === $current) return $cache;
		$cache = [
			'resources' => static::discoverResources(),
			'classes'   => static::discoverClasses(),
		];
		$stamp = $current;
		return $cache;
	}

	private static function loadedConfigNames():array {
		$items = [];
		foreach ((array)(static::buildJson()['resources'] ?? []) as $name){
			$name = trim($name);
			if ($name === void) continue;
			$items[static::resourceName($name)] = true;
		}
		uksort($items, 'strnatcasecmp');
		return array_keys($items);
	}

	private static function resourceName(string $name):string {
		return str_replace(bs, slash, trim($name));
	}

	private static function functionKey(string $name):string {
		return strtr(basename($name), [dot => us, '-' => us, slash => us]);
	}

	private static function resourceStamp():string {
		$parts = [];
		foreach (static::resourcePaths() as $path) $parts[] = $path.':'.(@filemtime($path) ?: 0);
		$file = defined('data') ? data.'app.json' : void;
		if ($file !== void && is_file($file)) $parts[] = $file.':'.filemtime($file);
		return md5(implode('|', $parts));
	}

	private static function buildJson():array {
		static $cache = null, $stamp = null;
		$file = defined('data') ? data.'app.json' : void;
		$current = ($file !== void && is_file($file)) ? (string)filemtime($file) : '-';
		if ($cache !== null && $stamp === $current) return $cache;
		if ($file === void || !is_file($file)) return $cache = [];
		$json = phlo_build_config($file, app);
		$stamp = $current;
		return $cache = is_array($json) ? $json : [];
	}

	private static function appPaths():array {
		$paths = [rtrim(app, slash).slash];
		foreach ((array)(static::buildJson()['paths']['app'] ?? []) as $path){
			$path    = $path;
			$paths[] = $path === void ? rtrim(app, slash).slash : rtrim($path, slash).slash;
		}
		return array_values(array_unique(array_filter($paths, 'is_dir')));
	}

	private static function resourcePaths():array {
		$paths = [dirname(__DIR__).slash.'resources'.slash];
		foreach ((array)(static::buildJson()['paths']['resources'] ?? []) as $path){
			$path    = $path;
			$paths[] = $path === void ? rtrim(app, slash).slash : rtrim($path, slash).slash;
		}
		return array_values(array_unique(array_filter($paths, 'is_dir')));
	}

	private static function discoverResources():array {
		$items = [];
		foreach (static::resourcePaths() as $base){
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $file){
				if ($file->getExtension() !== 'phlo') continue;
				$full = (string)$file;
				$name = substr($full, strlen($base));
				$name = str_replace(bs, slash, substr($name, 0, -5));
				$key  = strtolower(static::resourceName($name));
				$items[$key] ??= ['name' => $name, 'file' => $full, 'group' => 'resources'];
			}
		}
		uksort($items, 'strnatcasecmp');
		return $items;
	}

	private static function discoverClasses():array {
		$items = [];
		foreach (static::discoverResources() as $info){
			$file  = new build_file($info['file']);
			if (!static::buildFileHasClass($file)) continue;
			$name  = (string)$info['name'];
			$item  = ['name' => $name, 'file' => (string)$info['file'], 'group' => 'resources'];
			$class = trim(($file->class ?? void));
			$aliases = [strtolower($name), strtolower(basename($name))];
			if ($class !== void){
				$item['class'] = $class;
				$aliases[] = strtolower($class);
			}
			foreach (array_unique($aliases) as $alias) $items[$alias] ??= $item;
		}
		uksort($items, 'strnatcasecmp');
		return $items;
	}

	private static function sourceDumpFile(build_file $file, bool $withBody = false):array {
		$meta = [];
		foreach ($file->meta as $key => $value) $meta[$key] = static::sourceInline($value);
		ksort($meta, SORT_NATURAL | SORT_FLAG_CASE);
		$nodes = [];
		foreach ($file->nodes as $node) $nodes[] = static::sourceNodeItem($node, $withBody);
		$functions = [];
		foreach ($file->functions as $node) $functions[] = static::sourceNodeItem($node, $withBody);
		$assets = [];
		foreach ($file->assets as $asset){
			$assets[] = [
				'node'     => $asset->node,
				'ns'       => $asset->ns ?: null,
				'line'     => $asset->line,
				'bytes'    => $asset->body ? strlen((string)$asset->body) : 0,
				'comments' => $asset->comments ? static::sourceInline((string)$asset->comments) : null,
			];
		}
		return [
			'file'      => $file->file,
			'class'     => $file->class,
			'meta'      => $meta,
			'nodes'     => $nodes,
			'functions' => $functions,
			'assets'    => $assets,
		];
	}

	private static function buildFileHasClass(build_file $file):bool {
		foreach ($file->nodes as $key => $node){
			if (str_starts_with($key, '%')) continue;
			if (in_array($node->node, ['script', 'style'], true)) continue;
			return true;
		}
		return false;
	}

	private static function resourceHasClass(array $node):bool {
		foreach (($node['nodes'] ?? []) as $item){
			if (str_starts_with((string)($item['name'] ?? void), '%')) continue;
			if (in_array(($item['node'] ?? null), ['script', 'style'], true)) continue;
			return true;
		}
		return false;
	}

	private static function sourceNodeItem(object $node, bool $withBody = false):array {
		$out = [
			'node'       => $node->node,
			'name'       => $node->name ?: null,
			'method'     => $node->method ?: null,
			'mode'       => $node->mode ?: null,
			'path'       => $node->path ? static::sourceInline($node->path) : null,
			'visibility' => $node->visibility ?: null,
			'type'       => $node->type ?: null,
			'args'       => $node->args ? static::sourceInline($node->args) : null,
			'operator'   => $node->operator ?: null,
			'line'       => (int)$node->line,
			'comments'   => $node->comments ? static::sourceInline($node->comments) : null,
		];
		if ($withBody){
			$body = trim(($node->body ?? void));
			$out['body'] = $body === void ? null : $body;
		}
		return $out;
	}

	private static function sourceInline(string $value):string {
		$value = trim($value);
		return $value === void ? $value : preg_replace('/\s+/', space, $value);
	}

	private static function sourcePruneNulls(mixed $value):mixed {
		if (!is_array($value)) return $value;
		if (array_is_list($value)){
			$out = [];
			foreach ($value as $item) $out[] = static::sourcePruneNulls($item);
			return $out;
		}
		$out = [];
		foreach ($value as $key => $item){
			if ($item === null) continue;
			$out[$key] = static::sourcePruneNulls($item);
		}
		return $out;
	}

	private static function routeFiles():array {
		$files = [];
		foreach (static::sourceFiles() as $file) $files[$file] = ['file' => $file, 'name' => static::displayPath($file), 'origin' => 'app'];
		foreach (static::loadedObjects() as $resource){
			$file = ($resource['file'] ?? void);
			$file === void || $files[$file] ??= ['file' => $file, 'name' => static::displayPath($file), 'origin' => 'resource'];
		}
		return array_values($files);
	}

	private static function appMethodBodies(build_file $parsed):array {
		$out = [];
		foreach (($parsed->nodes ?? []) as $node){
			$name = trim(($node->name ?? void));
			if ($name === void) continue;
			$body = trim(($node->body ?? void));
			if ($body !== void) $out[$name] = $body;
		}
		return $out;
	}

	private static function routeNodeEntry(string $origin, string $file, object $node, array $methodBodies, array $deps):array {
		$scan = static::routeScanSource(($node->body ?? void), $methodBodies);
		return static::sourcePruneNulls([
			'origin'   => $origin,
			'file'     => $file,
			'line'     => ($node->line ?? 0),
			'method'   => ($node->method ?? void) ?: null,
			'mode'     => ($node->mode ?? void) ?: null,
			'path'     => ($node->path ?? void) ?: null,
			'operator' => ($node->operator ?? void) ?: null,
			'comments' => ($node->comments ?? void) ?: null,
			'uses'     => [
				'functions'  => static::detectFunctions($scan, (array)$deps['functions']),
				'resources'  => static::detectResources($scan, (array)$deps['resources']),
				'appMethods' => static::detectAppMethods($scan, $methodBodies),
			],
		]);
	}

	private static function routeScanSource(string $source, array $methodBodies):string {
		$scan  = $source;
		$queue = static::detectAppMethods($scan, $methodBodies);
		$seen  = [];
		$depth = 0;
		while ($queue && $depth < 4){
			++$depth;
			$next = [];
			foreach ($queue as $method){
				if (isset($seen[$method])) continue;
				$seen[$method] = true;
				$body = trim(($methodBodies[$method] ?? void));
				if ($body === void) continue;
				$scan .= lf.$body;
				foreach (static::detectAppMethods($body, $methodBodies) as $m){
					if (!isset($seen[$m])) $next[] = $m;
				}
			}
			$queue = array_values(array_unique($next));
		}
		return $scan;
	}

	private static function detectAppMethods(string $source, array $methodBodies):array {
		if ($source === void) return [];
		$hits = [];
		if (preg_match_all('/(?:\$this->|::)([A-Za-z_][A-Za-z0-9_]*)/', $source, $m)){
			foreach ($m[1] as $name){
				$name = $name;
				if (!isset($methodBodies[$name])) continue;
				$hits[$name] = true;
			}
		}
		$out = array_keys($hits);
		natcasesort($out);
		return array_values($out);
	}

	private static function detectFunctions(string $source, array $map):array {
		if ($source === void) return [];
		$hits = [];
		if (preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\(/i', $source, $m)){
			foreach ($m[1] as $name){
				$key = strtolower($name);
				if (!isset($map[$key])) continue;
				$hits[$map[$key]] = true;
			}
		}
		$out = array_keys($hits);
		natcasesort($out);
		return array_values($out);
	}

	private static function detectResources(string $source, array $map):array {
		if ($source === void) return [];
		$hits = [];
		if (preg_match_all('/%([A-Za-z_][A-Za-z0-9_]*)/', $source, $m1)){
			foreach ($m1[1] as $name){
				$key = strtolower($name);
				if (!isset($map[$key])) continue;
				$hits[$map[$key]] = true;
			}
		}
		if (preg_match_all('/\bphlo\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*[,\)]/i', $source, $m2)){
			foreach ($m2[1] as $name){
				$key = strtolower($name);
				if (!isset($map[$key])) continue;
				$hits[$map[$key]] = true;
			}
		}
		if (preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::/', $source, $m3)){
			foreach ((array)$m3[1] as $name){
				$key = strtolower($name);
				if (!isset($map[$key])) continue;
				$hits[$map[$key]] = true;
			}
		}
		$out = array_keys($hits);
		natcasesort($out);
		return array_values($out);
	}

	private static function coreFunctions():array {
		static $cache = null;
		if ($cache !== null) return $cache;
		$out = [];
		$files = ['phlo.php' => 'engine'];
		if (defined('debug') && debug) $files['debug.php'] = 'debug';
		foreach ($files as $name => $group){
			$file = dirname(__DIR__).slash.$name;
			if (!is_file($file)) continue;
			foreach (static::phpFunctionsIn($file) as $item){
				$item['source'] = 'native';
				$item['group']  = $group;
				$item['loaded'] = true;
				$out[$item['name']] = $item;
			}
		}
		uksort($out, 'strnatcasecmp');
		return $cache = array_values($out);
	}

	private static function dependencyCatalog():array {
		static $cache = null, $cacheStamp = null;
		$stamp = static::resourceStamp().'.'.(defined('data') && is_file(data.'app.json') ? (string)filemtime(data.'app.json') : '-');
		if ($cache !== null && $cacheStamp === $stamp) return $cache;
		$functions = [];
		foreach (static::availableFunctions() as $item){
			$name = strtolower(($item['name'] ?? void));
			$name === void || $functions[$name] = (string)$item['name'];
		}
		$resources = [];
		foreach (static::availableObjects() as $item){
			$name  = ($item['name'] ?? void);
			$class = trim(($item['class'] ?? void));
			foreach (array_unique(array_filter([
				strtolower($name),
				strtolower(basename($name)),
				$class !== void ? strtolower($class) : null,
			])) as $alias) $resources[$alias] = $name;
		}
		$cacheStamp = $stamp;
		return $cache = ['functions' => $functions, 'resources' => $resources];
	}

	private static function phpFunctionsIn(string $file):array {
		$lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
		$out = [];
		foreach ($lines as $i => $line){
			if (!preg_match('/^\s*function\s+([a-zA-Z0-9_]+)\s*\((.*?)\)\s*(?::\s*([^{]+))?/', (string)$line, $m)) continue;
			$name    = (string)$m[1];
			$args    = trim((string)$m[2]);
			$return  = trim(($m[3] ?? void));
			$comment = static::phpFunctionComment($lines, $i);
			$out[]   = [
				'name'      => $name,
				'signature' => $name.'('.$args.')'.($return !== void ? ': '.$return : void),
				'args'      => $args ?: null,
				'return'    => $return ?: null,
				'summary'   => $comment,
				'comments'  => $comment,
				'file'      => $file,
				'line'      => $i + 1,
				'package'   => 'runtime',
				'frontend'  => null,
				'backend'   => true,
				'requires'  => [],
				'tags'      => [],
			];
		}
		return $out;
	}

	private static function phpFunctionComment(array $lines, int $lineIndex):?string {
		for ($i = $lineIndex - 1; $i >= 0; --$i){
			$line = trim(($lines[$i] ?? void));
			if ($line === void) continue;
			if (str_starts_with($line, '//') || str_starts_with($line, '/*') || str_starts_with($line, '*')){
				$line = trim($line, "/* \t");
				return $line !== void ? $line : null;
			}
			break;
		}
		return null;
	}

	private static function functionEntryFromResource(string $name, array $node, bool $loaded):array {
		$fn     = static::primaryFunctionNode($node);
		$meta   = ($node['meta'] ?? []);
		$args   = trim(($fn['args'] ?? void));
		$return = trim(($fn['type'] ?? void));
		$display = (string)($fn['name'] ?? $name);
		return [
			'name'      => $display,
			'resource'  => $name,
			'signature' => $display.($args !== void ? '('.$args.')' : '()').($return !== void ? ': '.$return : void),
			'args'      => $args ?: null,
			'return'    => $return ?: null,
			'summary'   => static::metaSummary($meta),
			'file'      => ($node['file'] ?? void),
			'line'      => ($fn['line'] ?? 0),
			'source'    => 'function',
			'loaded'    => $loaded,
			'package'   => static::metaValue($meta, 'package'),
			'frontend'  => static::metaBool($meta, 'frontend'),
			'backend'   => static::metaBool($meta, 'backend'),
			'requires'  => static::metaList($meta, 'requires'),
			'tags'      => static::metaList($meta, 'tags'),
			'comments'  => $fn['comments'] ?? null,
			'metadata'  => $meta,
		];
	}

	private static function objectEntryFromResource(string $name, array $node, bool $loaded):array {
		$meta    = ($node['meta'] ?? []);
		$class   = trim(($node['class'] ?? static::metaValue($meta, 'class') ?? void));
		$methods = [];
		$statics = [];
		$props   = [];
		foreach (($node['nodes'] ?? []) as $item){
			$n = trim(($item['name'] ?? void));
			if ($n === void) continue;
			$entry = [
				'name'     => $n,
				'args'     => $item['args'] ?? null,
				'return'   => $item['type'] ?? null,
				'line'     => ($item['line'] ?? 0),
				'comments' => $item['comments'] ?? null,
			];
			$nodeType = ($item['node'] ?? null);
			if ($nodeType === 'static')                                                        $statics[$n] = $entry;
			elseif ($nodeType === 'prop' || $nodeType === 'readonly' || $nodeType === 'const') $props[$n]   = $entry;
			else                                                                               $methods[$n] = $entry;
		}
		uksort($methods, 'strnatcasecmp');
		uksort($statics, 'strnatcasecmp');
		uksort($props,   'strnatcasecmp');
		return [
			'name'     => $name,
			'class'    => $class !== void ? $class : null,
			'type'     => static::metaValue($meta, 'type') ?? 'class',
			'extends'  => static::metaValue($meta, 'extends'),
			'summary'  => static::metaSummary($meta),
			'file'     => ($node['file'] ?? void),
			'line'     => static::firstLineNode($node),
			'source'   => 'resource',
			'loaded'   => $loaded,
			'package'  => static::metaValue($meta, 'package'),
			'frontend' => static::metaBool($meta, 'frontend'),
			'backend'  => static::metaBool($meta, 'backend'),
			'requires' => static::metaList($meta, 'requires'),
			'tags'     => static::metaList($meta, 'tags'),
			'advice'   => static::metaValue($meta, 'advice'),
			'methods'  => array_values($methods),
			'statics'  => array_values($statics),
			'props'    => array_values($props),
			'metadata' => $meta,
		];
	}

	private static function primaryFunctionNode(array $node):array {
		foreach (($node['functions'] ?? []) as $fn){
			if (($fn['node'] ?? null) === 'function') return (array)$fn;
		}
		return ['name' => null, 'args' => null, 'type' => null, 'line' => 0, 'comments' => null];
	}

	private static function firstLineNode(array $node):int {
		foreach (['nodes', 'functions', 'assets'] as $group){
			foreach (($node[$group] ?? []) as $item){
				$line = ($item['line'] ?? 0);
				if ($line) return $line;
			}
		}
		return 0;
	}

	private static function firstLineStr(string $text):string {
		$lines = explode(lf, $text);
		return trim(($lines[0] ?? void));
	}

	private static function metaSummary(array $meta):?string { return static::metaValue($meta, 'summary'); }

	private static function metaValue(array $meta, string $key):?string {
		$value = trim(($meta[$key] ?? void));
		return $value !== void ? $value : null;
	}

	private static function metaBool(array $meta, string $key):?bool {
		$value = strtolower(trim(($meta[$key] ?? void)));
		if ($value === void) return null;
		if (in_array($value, ['1', 'true', 'yes'], true))  return true;
		if (in_array($value, ['0', 'false', 'no'], true))  return false;
		return null;
	}

	private static function metaList(array $meta, string $key):array {
		$value = trim(($meta[$key] ?? void));
		if ($value === void) return [];
		$parts = preg_split('/[\s,]+/', $value) ?: [];
		$parts = array_values(array_filter(array_map('trim', $parts), 'strlen'));
		natcasesort($parts);
		return array_values($parts);
	}

	private static function displayPath(string $file):string {
		$file = str_replace(bs, slash, $file);
		$app  = rtrim(str_replace(bs, slash, app), slash).slash;
		$phlo = rtrim(str_replace(bs, slash, dirname(__DIR__)), slash).slash;
		if (str_starts_with($file, $app))  return substr($file, strlen($app));
		if (str_starts_with($file, $phlo)) return 'phlo/'.substr($file, strlen($phlo));
		return basename($file);
	}

	private static function entryMap(array $entries):array {
		$out = [];
		foreach ($entries as $entry){
			$name = trim(($entry['name'] ?? void));
			if ($name === void) continue;
			$out[$name] = [
				'args'    => $entry['args'] ?? void,
				'ret'     => $entry['return'] ?? 'mixed',
				'line'    => ($entry['line'] ?? 0),
				'summary' => $entry['comments'] ?? null,
			];
		}
		uksort($out, 'strnatcasecmp');
		return $out;
	}

	private static function constructorArgs(array $methods):string {
		foreach ($methods as $entry){
			if (($entry['name'] ?? void) !== '__construct') continue;
			return ($entry['args'] ?? void);
		}
		return void;
	}
}
