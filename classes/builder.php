<?php
namespace phlo\tech;

class builder {
	public array $files = [];
	public array $functionFiles = [];
	public array $appFiles = [];
	public array $libFiles = [];
	public array $sourcemap = [];
	public array $build;
	public array $sources;
	public array $written = [];
	public array $changed = [];
	public array $errors = [];
	public array $lintTargets = [];
	public bool $release;
	public string $appSource;

	public function __construct(array $buildData, bool $build = false, bool $release = false){
		$this->build = $buildData['build'] ?? [];
		$this->sources = $buildData['sources'] ?? [];
		$this->release = $release;
		$this->appSource = $this->resolveAppSource();
		$this->read_sources();
		$this->apply_mods();
		if ($this->errors) \phlo\error(\implode(\phlo\lf, $this->errors));
		$build && $this->build_all();
	}

	private function setting(string $name, $default = null){
		if ($this->release && isset($this->build['release'][$name])) return $this->build['release'][$name];
		if (\in_array($name, ['minifyCSS', 'minifyJS', 'minifyPHP'], true)){
			$explicit = $this->build['_minifyExplicit'][$name] ?? false;
			if (!$explicit) return $this->release ? true : false;
			return (bool)$this->build[$name];
		}
		return $this->build[$name] ?? $default;
	}

	private function resolveAppSource():string {
		$appFile = \rtrim(\phlo\app, \phlo\slash).\phlo\slash.'app.phlo';
		if (\is_file($appFile)) return $appFile;
		return $this->sources['app'][0] ?? $appFile;
	}

	private function read_sources():void {
		foreach ($this->sources['app'] ?? [] AS $file){
			try { $source = new file($file); }
			catch (\RuntimeException $e){ $this->errors[] = $e->getMessage(); continue; }
			if (isset($this->files[$source->class])){
				$this->errors[] = "Build Error: Duplicate class \"$source->class\" in $file";
				continue;
			}
			$this->files[$source->class] = $source;
			$this->appFiles[] = $source;
		}
		foreach ($this->sources['libs'] ?? [] AS $file){
			try { $source = new file($file); }
			catch (\RuntimeException $e){ $this->errors[] = $e->getMessage(); continue; }
			if (isset($this->files[$source->class])){
				$this->errors[] = "Build Error: Duplicate class \"$source->class\" in $file";
				continue;
			}
			$this->files[$source->class] = $source;
			$this->libFiles[] = $source;
		}
		foreach ($this->sources['functions'] ?? [] AS $file){
			try { $this->functionFiles[] = new file($file); }
			catch (\RuntimeException $e){ $this->errors[] = $e->getMessage(); }
		}
		node::set_classes(\array_keys($this->files));
	}

	private function apply_mods():void {
		foreach ($this->files AS $file){
			foreach ($file->nodes AS $name => $node){
				if (!\str_starts_with($name, '%') || !str_contains($name, \phlo\dot)) continue;
				[$class, $nodeName] = \explode(\phlo\dot, substr($name, 1), 2);
				$node->name = $nodeName;
				if (isset($this->files[$class])){
					$target = $this->files[$class];
					if (isset($target->nodes[$nodeName])){
						$this->errors[] = "Build Error: node \"$nodeName\" exists in $target->file";
						continue;
					}
					$target->nodes[$nodeName] = $node;
				}
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
		$functions = \phlo\void;
		$minifyPHP = $this->setting('minifyPHP', $this->release);
		$comments = !$minifyPHP && $this->setting('comments', !$this->release);
		foreach ([$this->appFiles, $this->libFiles, $this->functionFiles] AS $group){
			foreach ($group AS $file){
				foreach ($file->functions AS $node){
					$comments && $node->comments && $functions .= '// '.\strtr($node->comments, [\phlo\lf => \phlo\lf.'// ']).\phlo\lf;
					$functions .= $node->renderFunction().($minifyPHP ? \phlo\void : \phlo\lf);
				}
			}
		}
		$functions = "<?php\nnamespace phlo;\n\n".$functions;
		$this->write($this->php_path().'functions.php', $functions, true);
		return $functions;
	}

	private function build_routes():string {
		$static = [];
		$dynamic = [];
		$exclude = $this->setting('exclude', []);
		foreach ($this->files AS $class => $file){
			if (\in_array($class, $exclude)) continue;
			foreach ($file->nodes AS $node){
				if ($node->node !== 'route') continue;
				$method = $node->method ?: 'GET';
				$mode = $node->mode;
				$async = $mode === 'async' ? true : ($mode === 'both' ? null : false);
				$data = $node->data ? \explode(\phlo\comma, $node->data) : null;
				$cb = $node->shortRoute() ? $node->body : "phlo\\$class::$node->name";
				$path = $node->path ? \trim($node->path) : \phlo\void;
				$parts = $path ? \preg_split('/\s+/', $path) : [];
				$parts = \array_values(\array_filter($parts, fn($part) => $part !== \phlo\void));
				$isDynamic = false;
				foreach ($parts AS $part){
					if (\str_starts_with($part, '$')){
						$isDynamic = true;
						break;
					}
				}
				if (!$isDynamic){
					$pathKey = $parts ? \implode(\phlo\slash, $parts) : \phlo\void;
					$static[$method][$pathKey][] = ['a' => $async, 'd' => $data, 'c' => $cb];
					continue;
				}
				$segments = [];
				foreach ($parts AS $part){
					if (!\str_starts_with($part, '$')){
						$segments[] = $part;
						continue;
					}
					$name = \substr($part, 1);
					$splat = false;
					if (\str_ends_with($name, '=*')){
						$splat = true;
						$name = \substr($name, 0, -2);
					}
					$optional = false;
					if (!$splat && \str_ends_with($name, \phlo\qm)){
						$optional = true;
						$name = \substr($name, 0, -1);
					}
					$default = null;
					$hasDefault = false;
					if (\str_contains($name, \phlo\eq)){
						[$name, $default] = \explode(\phlo\eq, $name, 2);
						$default = $default ?: null;
						$hasDefault = true;
					}
					$length = null;
					if (\str_contains($name, \phlo\dot)){
						[$name, $length] = \explode(\phlo\dot, $name, 2);
						$length = $length !== \phlo\void ? (int)$length : null;
					}
					$list = null;
					if (\str_contains($name, \phlo\colon)){
						[$name, $list] = \explode(\phlo\colon, $name, 2);
						$list = $list !== \phlo\void ? \explode(\phlo\comma, $list) : [];
					}
					$seg = ['n' => $name];
					$splat && $seg['s'] = true;
					$optional && $seg['o'] = true;
					$hasDefault && $seg['d'] = $default;
					!is_null($length) && $seg['l'] = $length;
					!is_null($list) && $seg['v'] = $list;
					$segments[] = $seg;
				}
				$dynamic[$method][] = ['s' => $segments, 'a' => $async, 'd' => $data, 'c' => $cb];
			}
		}
		$routes = [
			'static' => $static ?: [],
			'dynamic' => $dynamic ?: [],
		];
		$routesExport = \phlo\tech\var_export($routes, 2);
		return "\tpublic static function route(){\n\t\treturn $routesExport;\n\t}\n";
	}

	private function build_classes(?string $routes):void {
		$path = $this->php_path();
		$classes = \phlo\void;
		$minifyPHP = $this->setting('minifyPHP', $this->release);
		$comments = !$minifyPHP && $this->setting('comments', !$this->release);
		$exclude = $this->setting('exclude', []);
		foreach ($this->files AS $class => $file){
			if (\in_array($class, $exclude)) continue;
			$isApp = $file->file === $this->appSource;
			$built = $this->build_class_php($file, $comments, $isApp ? $routes : null);
			if (!$built) continue;
			$php = $built['php'];
			$map = $built['map'] ?? [];
			if ($minifyPHP) $php = \preg_replace('/\n{2,}/', \phlo\lf, $php);
			$filename = \strtr($class, [\phlo\us => \phlo\dot]).'.php';
			$namespace = $file->meta['namespace'] ?? 'phlo';
			$classKey = $namespace ? $namespace.'\\'.$class : $class;
			$classes .= "\t'$classKey' => '$filename',\n";
			$this->write($path.$filename, $php, true);
			if (!$this->release) $this->sourcemap[$path.$filename] = ['source' => $file->file, 'map' => $map];
		}
		if ($classes !== \phlo\void){
			$classmap = "<?php return [\n$classes];\n";
			$this->write($path.'classmap.php', $classmap);
		}
		if (!$this->release) $this->write_sourcemap($path.'sourcemap.php');
	}

	private function build_class_php(file $file, bool $comments, ?string $routes):?array {
		$PHP = "<?php\n";
		if ($comments){
			$meta = \array_merge(['source' => $file->file, 'phlo' => version], $file->meta);
			$metaFind = $meta;
			\uksort($metaFind, fn($a, $b) => strlen($b) <=> strlen($a));
			$maxLength = strlen(\array_keys($metaFind)[0]);
			foreach ($meta AS $key => $value) $PHP .= '// '.$key.\phlo\colon.str_repeat(\phlo\space, $maxLength - strlen($key))." $value\n";
		}
		$type = $file->meta['type'] ?? 'class';
		$namespace = $file->meta['namespace'] ?? 'phlo';
		if ($namespace) $PHP .= 'namespace '.$namespace.";\n";
		$extends = $file->meta['extends'] ?? $this->build['extends'] ?? 'obj';
		$extends = $extends ? " extends $extends" : \phlo\void;
		$PHP .= "$type $file->class$extends {\n";
		$body = \phlo\void;
		$map = [];
		$constructorArgs = null;
		if (isset($file->nodes['__construct'])) $constructorArgs = $this->strip_visibility($file->nodes['__construct']->args ?? \phlo\void) ?: null;
		foreach ($file->nodes AS $key => $node){
			if (\str_starts_with($key, '%')) continue;
			if (\in_array($node->node, ['script', 'style'])) continue;
			$comments && $node->comments && $body .= "\t// ".\strtr($node->comments, [\phlo\lf => \phlo\lf.\phlo\tab.'// ']).\phlo\lf;
			$isValue = $node->node !== 'method' && (!$node->operator || $node->operator === 'value');
			$isShortRoute = $node->node === 'route' && $node->shortRoute();
			$isMethod = !$isValue && !$isShortRoute;
			if (isset($node->line)){
				$line = \substr_count($PHP.$body, \phlo\lf);
				$hasBody = $isMethod && \str_contains($node->body ?? \phlo\void, \phlo\lf);
				$map[] = ['php' => $line + 1 + ($isMethod ? 1 : 0), 'phlo' => $node->line + ($hasBody ? 1 : 0), 'name' => $node->name];
			}
			if ($isValue) $body .= $node->renderValue();
			elseif ($isShortRoute) \phlo\void;
			else $body .= $node->renderMethod($file->class, $constructorArgs);
			if ($routes && $key === 'controller') [$body .= $routes, $routes = null];
		}
		if (!$body) return null;
		$PHP = $PHP.$routes.\rtrim($body)."\n}\n";
		return ['php' => $PHP, 'map' => $map];
	}

	private function build_assets():void {
		$style = [];
		$script = ['app' => []];
		$minJS = $this->setting('minifyJS', $this->release);
		$exclude = $this->setting('exclude', []);
		$files = $this->files;
		$libsDir = \rtrim(\realpath(__DIR__.\phlo\slash.'..'.\phlo\slash.'libs'), \phlo\slash).\phlo\slash;
		uasort($files, fn($a, $b) => \str_starts_with($b->file, $libsDir) <=> \str_starts_with($a->file, $libsDir));
		foreach ($files AS $class => $file){
			if (\in_array($class, $exclude)) continue;
			foreach ($file->assets AS $asset){
				if ($asset->node === 'script' && !$minJS) $asset->body = "/* $file->file */\n$asset->body";
				$namespaces = \explode(\phlo\comma, $asset->ns ?? $this->build['defaultNS'] ?? 'app');
				foreach ($namespaces AS $ns) ${$asset->node}[\trim($ns)][] = $asset->body;
			}
		}
		$path = $this->www_path();
		if ($this->setting('buildCSS', true)){
			$icons = null;
			if (isset($this->build['icons'])){
				require_once __DIR__.\phlo\slash.'icons.php';
				$version = $this->app_version();
				$icons = icons::build($this->build['icons'], $path, $version);
			}
			$minCSS = $this->setting('minifyCSS', $this->release);
			$iconNS = $this->build['iconNS'] ?? 'app';
			foreach ($style AS $ns => $items){
				if ($icons && (!$iconNS || \in_array($ns, \explode(\phlo\comma, $iconNS)))) $items[] = $icons;
				$CSS = \phlo\css_decode(\implode(\phlo\lf, $items), $minCSS);
				$this->write("$path$ns.css", $CSS);
			}
		}
		if ($this->setting('buildJS', true)){
			$phloJS = $this->build['phloJS'] ?? false;
			$phloNS = (array)($this->build['phloNS'] ?? 'app');
			foreach ($script AS $ns => $JS){
				$inNS = \in_array($ns, $phloNS);
				if (($phloJS && !$inNS) || (!$phloJS && $inNS)){
					$engine = \rtrim(\file_get_contents(__DIR__.\phlo\slash.'..'.\phlo\slash.'phlo.js'));
					$JS = [$engine, ...$JS, "'https://',phlo.tech,'/'"];
				}
				$JS = \implode(\phlo\lf.\phlo\lf, $JS).\phlo\lf;
				if ($minJS) $JS = $this->minify_js($JS);
				$this->write("$path$ns.js", $JS);
			}
		}
	}

	private function lint_written():void {
		if (!$this->lintTargets) return;
		$files = \implode(\phlo\space, array_map('escapeshellarg', $this->lintTargets));
		\exec("php -l $files", $out, $code);
		if ($code !== 0) \phlo\error('Build Error: PHP lint failed'.\phlo\lf.\implode(\phlo\lf, $out));
	}

	private function cleanup_classes():void {
		$path = $this->php_path();
		$expected = [];
		foreach ($this->files AS $class => $file){
			$expected[$path.\strtr($class, [\phlo\us => \phlo\dot]).'.php'] = true;
		}
		foreach (\glob($path.'*.php') AS $file){
			if (\basename($file) === 'classmap.php' || \basename($file) === 'functions.php' || \basename($file) === 'sourcemap.php') continue;
			if (!isset($expected[$file])){
				\unlink($file);
				$this->changed[] = \phlo\dash.\basename($file);
			}
		}
	}

	private function php_path():string {
		if ($this->release && isset($this->build['release']['php'])) return \rtrim($this->build['release']['php'], \phlo\slash).\phlo\slash;
		return \phlo\php;
	}

	private function www_path():string {
		if ($this->release && isset($this->build['release']['www'])) return \rtrim($this->build['release']['www'], \phlo\slash).\phlo\slash;
		return \phlo\www;
	}

	private function app_version():string {
		foreach ($this->files AS $file){
			if ($file->file !== $this->appSource) continue;
			$node = $file->nodes['version'] ?? null;
			$version = $node?->body ? \trim($node->body, '\'"') : '1.0';
			return $version ?: '1.0';
		}
		return '1.0';
	}

	private function app_class():string {
		foreach ($this->files AS $file){
			if ($file->file === $this->appSource) return $file->class;
		}
		return 'app';
	}

	private function write(string $file, string $content, bool $touch = false):bool {
		$exists = \file_exists($file);
		if ($exists && \filesize($file) === \strlen($content) && \md5_file($file) === \md5($content)){
			if ($touch) @touch($file);
			return false;
		}
		$this->ensure_dir(dirname($file));
		if (\file_put_contents($file, $content) !== false){
			$this->written[] = $file;
			$this->changed[] = ($exists ? '*' : '+').\basename($file);
			if (\str_ends_with($file, '.php')) $this->lintTargets[] = $file;
			\phlo\debug('Written: '.\basename($file));
		}
		else \phlo\error("Build Error: Couldn't write $file");
		return true;
	}

	private function ensure_dir(string $dir):void {
		if (\is_dir($dir)) return;
		if (!\mkdir($dir, 0775, true) && !\is_dir($dir)) \phlo\error("Build Error: Couldn't create $dir");
	}

	private function write_sourcemap(string $file):void {
		if (!$this->sourcemap) return;
		$content = "<?php return ".\phlo\tech\var_export($this->sourcemap).";\n";
		$this->write($file, $content);
	}

	private function indent(string $value, int $depth = 1):string {
		$pad = str_repeat(\phlo\tab, $depth);
		return $pad.str_replace(\phlo\lf, \phlo\lf.$pad, \rtrim($value));
	}

	private function strip_visibility(?string $args):?string {
		if (!$args) return null;
		return str_replace(['public ', 'protected ', 'private ', 'readonly '], \phlo\void, $args);
	}

	private function minify_js(string $js):string {
		$out = \phlo\void;
		$len = \strlen($js);
		$i = 0;
		$prev = \phlo\void;
		while ($i < $len){
			$c = $js[$i];
			if ($c === \phlo\slash && $i + 1 < $len){
				$n = $js[$i + 1];
				if ($n === \phlo\slash && ($prev === \phlo\void || $prev === ')' || $prev === ']' || \ctype_alnum($prev) || $prev === '_' || $prev === '$' || $prev === '}' || $prev === \phlo\lf)){
					$end = \strpos($js, \phlo\lf, $i);
					$i = $end === false ? $len : $end;
					continue;
				}
				if ($n === '*'){
					$end = \strpos($js, '*/', $i + 2);
					$i = $end === false ? $len : $end + 2;
					continue;
				}
				if ($prev !== ')' && $prev !== ']' && !\ctype_alnum($prev) && $prev !== '_' && $prev !== '$'){
					$out .= $c;
					$i++;
					while ($i < $len){
						$ch = $js[$i];
						$out .= $ch;
						if ($ch === \phlo\bs && $i + 1 < $len){ $out .= $js[++$i]; $i++; continue; }
						if ($ch === '['){
							$i++;
							while ($i < $len && $js[$i] !== ']'){ $out .= $js[$i]; if ($js[$i] === \phlo\bs && $i + 1 < $len){ $out .= $js[++$i]; } $i++; }
							continue;
						}
						if ($ch === \phlo\slash){ $i++; break; }
						$i++;
					}
					while ($i < $len && \ctype_alpha($js[$i])){ $out .= $js[$i]; $i++; }
					$prev = \phlo\slash;
					continue;
				}
			}
			if ($c === \phlo\sq || $c === \phlo\dq || $c === \phlo\bt){
				$out .= $c;
				$i++;
				while ($i < $len){
					$ch = $js[$i];
					$out .= $ch;
					if ($ch === \phlo\bs && $i + 1 < $len){ $out .= $js[++$i]; $i++; continue; }
					if ($ch === $c){ $i++; break; }
					$i++;
				}
				$prev = $c;
				continue;
			}
			if ($c === \phlo\lf){
				$i++;
				while ($i < $len && ($js[$i] === \phlo\space || $js[$i] === \phlo\tab || $js[$i] === \phlo\lf)) $i++;
				if ($i < $len && $js[$i] === '}'){
					$out .= '}';
					$prev = '}';
					$i++;
				}
				else $out .= \phlo\lf;
				continue;
			}
			$out .= $c;
			if ($c !== \phlo\space && $c !== \phlo\tab) $prev = $c;
			$i++;
		}
		return $out;
	}
}
