<?php

function export($expression, int $indent = 0):string {
	if (is_null($expression))  return 'null';
	if (is_bool($expression))  return $expression ? 'true' : 'false';
	if (is_int($expression) || is_float($expression)) return (string)$expression;
	if (is_string($expression)) return "'".addcslashes($expression, "'\\")."'";
	if (!is_array($expression)) return var_export($expression, true);
	if (!$expression) return '[]';
	$isSeq  = array_keys($expression) === range(0, count($expression) - 1);
	$tab    = str_repeat(tab, $indent);
	$inner  = str_repeat(tab, $indent + 1);
	$items  = [];
	foreach ($expression as $key => $value){
		$val     = export($value, $indent + 1);
		$items[] = $isSeq ? $val : export($key).' => '.$val;
	}
	if (count($items) <= 3 && !str_contains(implode(void, $items), lf)){
		$inline = implode(', ', $items);
		if (strlen($inline) < 80) return '['.$inline.']';
	}
	return "[\n$inner".implode(",\n$inner", $items).",\n$tab]";
}

class build_builder {
	public array $files        = [];
	public array $appFiles     = [];
	public array $resourceFiles = [];
	public array $sourcemap    = [];
	public array $build;
	public array $sources;
	public array $written      = [];
	public array $changed      = [];
	public array $errors       = [];
	public array $lintTargets  = [];
	public bool $release;
	public string $appSource;

	public function __construct(array $buildData, bool $doBuild = false, bool $release = false){
		$this->build     = $buildData['build'] ?? [];
		$this->sources   = $buildData['sources'] ?? [];
		$this->release   = $release;
		$this->appSource = $buildData['app_source'] ?? $this->resolveAppSource();
		$this->read_sources();
		$this->apply_mods();
		if ($this->errors) error(implode(lf, $this->errors));
		if ($doBuild) $this->build_all();
	}

	private function setting(string $name, $default = null){
		if ($this->release){
			if ($name === 'exclude') return $this->build['release']['exclude'] ?? [];
			if (isset($this->build['release'][$name])) return $this->build['release'][$name];
		}
		if (in_array($name, ['minifyCSS', 'minifyJS', 'minifyPHP'], true)){
			$explicit = $this->build['_minifyExplicit'][$name] ?? false;
			if (!$explicit) return $this->release ? true : false;
			return $this->build[$name];
		}
		return $this->build[$name] ?? $default;
	}

	private function resolveAppSource():string {
		$appFile = rtrim(app, slash).slash.'app.phlo';
		if (is_file($appFile)) return $appFile;
		return $this->sources['app'][0] ?? $appFile;
	}

	private function read_sources():void {
		foreach ($this->sources['app'] ?? [] as $file){
			try { $source = new build_file($file); }
			catch (RuntimeException $e){ $this->errors[] = $e->getMessage(); continue; }
			if (isset($this->files[$source->class])){
				$this->errors[] = 'Build error: Duplicate class "'.$source->class.'" in '.$file;
				continue;
			}
			$this->files[$source->class] = $source;
			$this->appFiles[] = $source;
		}
		foreach ($this->sources['resources'] ?? [] as $file){
			try { $source = new build_file($file); }
			catch (RuntimeException $e){ $this->errors[] = $e->getMessage(); continue; }
			$this->resourceFiles[] = $source;
			if (!$this->classBearing($source)) continue;
			if (isset($this->files[$source->class])){
				$this->errors[] = 'Build error: Duplicate class "'.$source->class.'" in '.$file;
				continue;
			}
			$this->files[$source->class] = $source;
		}
		build_node::set_classes(array_keys($this->files));
		build_node::set_trace(defined('trace') && trace && !$this->release);
	}

	private function classBearing(build_file $file):bool {
		foreach ($file->nodes as $key => $node){
			if (str_starts_with((string)$key, '%')) continue;
			if (in_array($node->node, ['script', 'style'], true)) continue;
			return true;
		}
		return false;
	}

	private function apply_mods():void {
		foreach ($this->files as $file){
			foreach ($file->nodes as $name => $node){
				if (!str_starts_with($name, '%') || !str_contains($name, dot)) continue;
				[$class, $nodeName] = explode(dot, substr($name, 1), 2);
				$node->name = $nodeName;
				if (!isset($this->files[$class])) continue;
				$target = $this->files[$class];
				if (isset($target->nodes[$nodeName])){
					$this->errors[] = 'Build error: Node "'.$nodeName.'" exists in '.$target->file;
					continue;
				}
				$target->nodes[$nodeName] = $node;
			}
		}
	}

	private function build_all():void {
		$this->build_functions_file();
		$routes = $this->setting('routes', true) ? $this->build_routes() : null;
		$this->build_classes($routes);
		$this->build_assets();
		$this->lint_written();
		$this->cleanup_classes();
	}

	private function build_functions_file():string {
		$functions  = void;
		$map        = [];
		$minifyPHP  = $this->setting('minifyPHP', $this->release);
		$comments   = !$minifyPHP && $this->setting('comments', !$this->release);
		$prefix     = "<?php\n\n";
		foreach ([$this->appFiles, $this->resourceFiles] as $group){
			foreach ($group as $file){
				foreach ($file->functions as $node){
					if ($comments && $node->comments) $functions .= '// '.strtr($node->comments, [lf => lf.'// ']).lf;
					if (isset($node->line)){
						$line    = substr_count($prefix.$functions, lf);
						$hasBody = str_contains($node->body ?? void, lf);
						$map[]   = ['php' => $line + 1, 'phlo' => $node->line + ($hasBody ? 1 : 0), 'name' => $node->name, 'source' => $file->file];
					}
					$functions .= $node->renderFunction().($minifyPHP ? void : lf);
				}
			}
		}
		$functions = $prefix.$functions;
		$target    = $this->php_path().'functions.php';
		$this->write($target, $functions, true);
		if (!$this->release) $this->sourcemap[$target] = ['source' => void, 'map' => $map];
		return $functions;
	}

	private function build_routes():string {
		$routes  = [];
		$exclude = $this->setting('exclude', []);
		foreach ($this->files as $class => $file){
			if (in_array($class, $exclude, true)) continue;
			foreach ($file->nodes as $node){
				if ($node->node !== 'route') continue;
				$routes[] = $node->renderRoute($class);
			}
		}
		$body = $routes ? 'return '.implode(' ||'.lf.tab.tab, $routes).';' : 'return false;';
		return "\tpublic static function route():bool {\n\t\t$body\n\t}\n";
	}

	private function build_classes(?string $routes):void {
		$path      = $this->php_path();
		$classes   = void;
		$minifyPHP = $this->setting('minifyPHP', $this->release);
		$comments  = !$minifyPHP && $this->setting('comments', !$this->release);
		$exclude   = $this->setting('exclude', []);
		foreach ($this->files as $class => $file){
			if (in_array($class, $exclude, true)) continue;
			$isApp = $file->file === $this->appSource;
			$built = $this->build_class_php($file, $comments, $isApp ? $routes : null);
			if (!$built) continue;
			$php = $built['php'];
			$map = $built['map'] ?? [];
			if ($minifyPHP) $php = preg_replace('/\n{2,}/', lf, $php);
			$filename  = strtr($class, [us => dot]).'.php';
			$namespace = $file->meta['namespace'] ?? null;
			$classKey  = $namespace ? $namespace.bs.$class : $class;
			$classes  .= "\t'$classKey' => '$filename',\n";
			$this->write($path.$filename, $php, true);
			if (!$this->release) $this->sourcemap[$path.$filename] = ['source' => $file->file, 'map' => $map];
		}
		if ($classes !== void) $this->write($path.'classmap.php', "<?php return [\n$classes];\n");
		if (!$this->release) $this->write_sourcemap($path.'sourcemap.php');
	}

	private function build_class_php(build_file $file, bool $comments, ?string $routes):?array {
		$PHP  = "<?php\n";
		if ($comments){
			$meta = array_merge(['source' => $file->file, 'phlo' => phlo], $file->meta);
			$metaFind = $meta;
			uksort($metaFind, static fn($a, $b) => strlen($b) <=> strlen($a));
			$maxLength = strlen(array_keys($metaFind)[0]);
			foreach ($meta as $key => $value) $PHP .= '// '.$key.colon.str_repeat(space, $maxLength - strlen($key)).space.$value."\n";
		}
		$type      = $file->meta['type'] ?? 'class';
		$namespace = $file->meta['namespace'] ?? null;
		if ($namespace) $PHP .= 'namespace '.$namespace.";\n";
		$useStatements = isset($file->meta['use'])
			? array_values(array_filter(array_map('trim', explode(comma, $file->meta['use'])), 'strlen'))
			: [];
		foreach ($useStatements as $useClass) $PHP .= 'use '.$useClass.";\n";
		$isInterface    = $type === 'interface';
		$isTrait        = $type === 'trait';
		$defaultExtends = ($isInterface || $isTrait) ? null : ($this->build['extends'] ?? 'obj');
		$extends        = isset($file->meta['extends']) ? trim($file->meta['extends']) : $defaultExtends;
		if ($extends === void) $extends = null;
		$implements = (isset($file->meta['implements']) && !$isInterface && !$isTrait) ? trim($file->meta['implements']) : null;
		$decl = $type.space.$file->class;
		if ($extends)    $decl .= ' extends '.$extends;
		if ($implements) $decl .= ' implements '.$implements;
		$PHP .= $decl." {\n";
		$body            = void;
		$map             = [];
		$constructorArgs = null;
		if (isset($file->nodes['__construct'])) $constructorArgs = $this->strip_visibility($file->nodes['__construct']->args ?? void) ?: null;
		foreach ($file->nodes as $key => $node){
			if (str_starts_with($key, '%')) continue;
			if (in_array($node->node, ['script', 'style'], true)) continue;
			if ($comments && $node->comments) $body .= "\t// ".strtr($node->comments, [lf => lf.tab.'// ']).lf;
			$isValue      = $node->node !== 'method' && (!$node->operator || $node->operator === 'value');
			$isShortRoute = $node->node === 'route' && $node->shortRoute();
			$isMethod     = !$isValue && !$isShortRoute;
			if (isset($node->line)){
				$line    = substr_count($PHP.$body, lf);
				$hasBody = $isMethod && str_contains($node->body ?? void, lf);
				$map[]   = ['php' => $line + 1 + ($isMethod ? 1 : 0), 'phlo' => $node->line + ($hasBody ? 1 : 0), 'name' => $node->name];
			}
			if ($isValue)        $body .= $node->renderValue();
			elseif ($isShortRoute) ;
			else                 $body .= $node->renderMethod($file->class, $constructorArgs);
			if ($routes && $key === 'controller'){
				$body  .= $routes;
				$routes = null;
			}
		}
		if (!$body) return null;
		return ['php' => $PHP.$routes.rtrim($body)."\n}\n", 'map' => $map];
	}

	private function build_assets():void {
		$style   = [];
		$script  = ['app' => []];
		$minJS   = $this->setting('minifyJS', $this->release);
		$exclude = $this->setting('exclude', []);
		$files   = array_merge($this->appFiles, $this->resourceFiles);
		$resourcesDir = rtrim(realpath(__DIR__.slash.'..'.slash.'resources'), slash).slash;
		uasort($files, static fn($a, $b) => str_starts_with($b->file, $resourcesDir) <=> str_starts_with($a->file, $resourcesDir));
		foreach ($files as $class => $file){
			if (in_array($class, $exclude, true)) continue;
			foreach ($file->assets as $asset){
				if ($asset->node === 'script' && !$minJS) $asset->body = '/* '.$file->file." */\n".$asset->body;
				$namespaces = explode(comma, $asset->ns ?? $this->build['defaultNS'] ?? 'app');
				foreach ($namespaces as $ns) ${$asset->node}[trim($ns)][] = $asset->body;
			}
		}
		$path = $this->www_path();
		if ($this->setting('buildCSS', true)){
			if (isset($this->build['icons'])){
				require_once __DIR__.slash.'icons.php';
				$icons = build_icons::build($this->build['icons'], $path, $this->app_version());
				if ($icons) $style[$this->build['iconNS'] ?? 'app'][] = $icons;
			}
			$minCSS = $this->setting('minifyCSS', $this->release);
			foreach ($style as $ns => $items){
				$CSS = phlo_css(implode(lf, array_values(array_filter($items, static fn($v) => trim((string)$v) !== void))), $minCSS);
				$CSS = preg_replace("/(?:\r?\n){2,}/", lf, str_replace("\r\n", lf, rtrim($CSS))).lf;
				$this->write($path.$ns.'.css', $CSS);
			}
		}
		if ($this->setting('buildJS', true)){
			$phloJS = $this->build['phloJS'] ?? false;
			$phloNS = (array)($this->build['phloNS'] ?? 'app');
			foreach ($script as $ns => $JS){
				$inNS = in_array($ns, $phloNS, true);
				if (($phloJS && !$inNS) || (!$phloJS && $inNS)){
					$engine = rtrim(file_get_contents(__DIR__.slash.'..'.slash.'assets'.slash.'phlo.js'));
					$JS = [$engine, ...$JS, "'https://',phlo.tech,'/'"];
				}
				$JS = implode(lf.lf, $JS).lf;
				if ($minJS) $JS = $this->minify_js($JS);
				$this->write($path.$ns.'.js', $JS);
			}
		}
	}

	private function lint_written():void {
		if (!$this->lintTargets) return;
		$files = implode(space, array_map('escapeshellarg', $this->lintTargets));
		exec(cli.' -l '.$files.' 2>&1', $out, $code);
		if ($code === 0) return;
		$errors = array_values(array_filter($out, fn($l) => preg_match('/^(?:PHP\s+)?(Parse|Fatal) error:/', $l)));
		error('Build error: PHP lint failed'.lf.implode(lf, $errors ?: $out));
	}

	private function cleanup_classes():void {
		$path     = $this->php_path();
		$expected = [];
		foreach ($this->files as $class => $file) $expected[$path.strtr($class, [us => dot]).'.php'] = true;
		foreach (glob($path.'*.php') ?: [] as $file){
			$base = basename($file);
			if ($base === 'classmap.php' || $base === 'functions.php' || $base === 'sourcemap.php') continue;
			if (!isset($expected[$file])){
				unlink($file);
				$this->changed[] = dash.$base;
			}
		}
	}

	private function php_path():string {
		if ($this->release && isset($this->build['release']['php'])) return rtrim($this->build['release']['php'], slash).slash;
		return php;
	}

	private function www_path():string {
		if ($this->release && isset($this->build['release']['www'])) return rtrim($this->build['release']['www'], slash).slash;
		return www;
	}

	private function app_version():string {
		foreach ($this->files as $file){
			if ($file->file !== $this->appSource) continue;
			$node    = $file->nodes['version'] ?? null;
			$version = $node?->body ? trim($node->body, '\'"') : '1.0';
			return $version ?: '1.0';
		}
		return '1.0';
	}

	private function write(string $file, string $content, bool $touch = false):bool {
		$exists = file_exists($file);
		if ($exists && filesize($file) === strlen($content) && md5_file($file) === md5($content)){
			if ($touch) @touch($file);
			return false;
		}
		$this->ensure_dir(dirname($file));
		if (file_put_contents($file, $content) !== false){
			$this->written[]  = $file;
			$this->changed[]  = ($exists ? '*' : '+').basename($file);
			if (str_ends_with($file, '.php')) $this->lintTargets[] = $file;
		}
		else error("Build error: Couldn't write $file");
		return true;
	}

	private function ensure_dir(string $dir):void {
		if (is_dir($dir)) return;
		if (!mkdir($dir, 0775, true) && !is_dir($dir)) error("Build error: Couldn't create $dir");
	}

	private function write_sourcemap(string $file):void {
		if (!$this->sourcemap) return;
		$this->write($file, '<?php return '.export($this->sourcemap).";\n");
	}

	private function strip_visibility(?string $args):?string {
		if (!$args) return null;
		return str_replace(['public ', 'protected ', 'private ', 'readonly '], void, $args);
	}

	private function minify_js(string $js):string {
		$out  = void;
		$len  = strlen($js);
		$i    = 0;
		$prev = void;
		while ($i < $len){
			$c = $js[$i];
			if ($c === slash && $i + 1 < $len){
				$n = $js[$i + 1];
				if ($n === slash && ($prev === void || $prev === ')' || $prev === ']' || ctype_alnum($prev) || $prev === '_' || $prev === '$' || $prev === '}' || $prev === lf)){
					$end = strpos($js, lf, $i);
					$i = $end === false ? $len : $end;
					continue;
				}
				if ($n === '*'){
					$end = strpos($js, '*/', $i + 2);
					$i = $end === false ? $len : $end + 2;
					continue;
				}
				if ($prev !== ')' && $prev !== ']' && !ctype_alnum($prev) && $prev !== '_' && $prev !== '$'){
					$out .= $c;
					++$i;
					while ($i < $len){
						$ch = $js[$i];
						$out .= $ch;
						if ($ch === bs && $i + 1 < $len){ $out .= $js[++$i]; ++$i; continue; }
						if ($ch === '['){
							++$i;
							while ($i < $len && $js[$i] !== ']'){
								$out .= $js[$i];
								if ($js[$i] === bs && $i + 1 < $len) $out .= $js[++$i];
								++$i;
							}
							continue;
						}
						if ($ch === slash){ ++$i; break; }
						++$i;
					}
					while ($i < $len && ctype_alpha($js[$i])){ $out .= $js[$i]; ++$i; }
					$prev = slash;
					continue;
				}
			}
			if ($c === sq || $c === dq || $c === bt){
				$out .= $c;
				++$i;
				while ($i < $len){
					$ch = $js[$i];
					$out .= $ch;
					if ($ch === bs && $i + 1 < $len){ $out .= $js[++$i]; ++$i; continue; }
					if ($ch === $c){ ++$i; break; }
					++$i;
				}
				$prev = $c;
				continue;
			}
			if ($c === lf){
				++$i;
				while ($i < $len && ($js[$i] === space || $js[$i] === tab || $js[$i] === lf)) ++$i;
				if ($i < $len && $js[$i] === '}'){
					$out .= '}';
					$prev = '}';
					++$i;
				}
				else $out .= lf;
				continue;
			}
			$out .= $c;
			if ($c !== space && $c !== tab) $prev = $c;
			++$i;
		}
		return $out;
	}
}
