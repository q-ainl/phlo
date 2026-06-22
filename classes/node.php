<?php

class build_node extends stdClass {
	public static array $classes = [];
	public static bool $trace = false;

	public function __construct(array $data = []){ foreach ($data as $key => $value) $this->$key = $value; }
	public function __get($key){ return null; }

	public static function set_classes(array $classes):void {
		self::$classes = array_fill_keys($classes, true);
	}

	public static function set_trace(bool $on):void {
		self::$trace = $on;
	}

	public function renderFunction(?string $class = null):string {
		$type = $this->type ? colon.$this->type.space : void;
		if ($this->operator === 'view'){
			$body = $this->indent($this->buildView());
			$type = colon.($this->type ?: 'string').space;
		}
		elseif ($this->operator === 'arrow') $body = $this->parsePHP($this->buildArrow());
		else $body = $this->body ? $this->parsePHP($this->parseObjects($class ? strtr($this->body, ['$this' => "%$class"]) : $this->body)) : void;
		if (self::$trace) $body = $this->renderTrace($this->name, $this->args).($body ? lf.$body : void);
		if ($body) $body = lf.$body.lf;
		return "function $this->name($this->args)$type{".$body."}\n";
	}

	public function renderRoute(string $class):string {
		$args = [];
		$pathArg  = ($last = $this->method) ? void : 'path: ';
		$asyncArg = ($last = $last && $this->path) ? void : 'async: ';
		$dataArg  = ($last = $last && $this->mode) ? void : 'data: ';
		$cbArg    = (($last = $last && $this->args) ? void : 'cb: ').sq.($this->shortRoute() ? $this->body : "$class::$this->name").sq;
		$args[]   = sq.$this->method.sq;
		if ($this->path) $args[] = $pathArg.sq.strtr(trim($this->path), [sq => bs.sq]).sq;
		if ($this->mode === 'async')                            $args[] = $asyncArg.'true';
		elseif (in_array($this->mode, [void, 'sync'], true))   $args[] = $asyncArg.'false';
		if ($this->data) $args[] = "$dataArg'$this->data'";
		$args[] = $cbArg;
		return 'route('.implode(', ', $args).')';
	}

	public function shortRoute():bool {
		return $this->node === 'route' && preg_match('/^[A-Za-z0-9_]+(?:::[A-Za-z0-9_]+)?$/', $this->body ?? void);
	}

	public function renderMethod(string $class, ?string $constructorArgs = null):string {
		$static  = $this->node === 'static' || $this->node === 'route' ? ' static' : void;
		$name    = ($this->node === 'prop' ? us : void).($this->name ?: 'view');
		$vis     = $this->visibility ?: ($static || str_starts_with($name, '__') || str_starts_with($name, 'obj') ? 'public' : 'protected');
		$type    = $this->type ? colon.$this->type.space : void;
		$args    = $this->node === 'route' ? $this->route_args() : $this->args;
		if ($this->operator === 'view'){
			$body = $this->indent($this->buildView());
			$type = colon.($this->type ?: 'string').space;
		}
		elseif ($this->operator === 'arrow') $body = $this->parsePHP($this->buildArrow());
		else $body = rtrim($this->body ?? void) ? $this->parsePHP($this->parseObjects($this->body)) : void;
		if (($this->node === 'route' || $this->node === 'static') && $body) $body = strtr($body, ['$this' => "phlo('$class')"]);
		if ($this->name === 'controller' && $body) $body = $this->indent($body);
		if (self::$trace){
			$traceNode = $static ? "$class::$this->name" : "$class->$this->name".($this->node === 'prop' ? ' (get)' : void);
			$body = $this->renderTrace($traceNode, $args).($body ? lf.$body : void);
		}
		if ($body) $body = lf.$this->indent($body).lf.tab;
		$method = "\t$vis$static function $name($args)$type{".$body."}\n";
		if ($this->name === '__handle'){
			$handleArgs = $constructorArgs ?: '...$data';
			$method = strtr($method, ['static function __handle(){' => 'static function __handle('.$handleArgs.'){']);
		}
		return $method;
	}

	public function renderValue():string {
		$vis      = $this->visibility ?: 'public';
		$const    = $this->node === 'const' ? ' const' : void;
		$static   = $this->node === 'static' ? ' static' : void;
		$readonly = $this->node === 'readonly' ? ' readonly' : void;
		$name     = ($const ? void : '$').$this->name;
		$type     = $this->type ? space.$this->type : void;
		$body     = isset($this->body) ? ' = '.(strpos($this->body, lf) ? ltrim($this->indent($this->body)) : $this->body) : void;
		return "\t$vis$const$static$readonly$type $name$body;\n";
	}

	public static function transpile(string $body):string {
		$node = new self(['body' => $body]);
		return $node->parsePHP($node->parseObjects($body));
	}

	private function route_args():string {
		if (!$this->path) return void;
		preg_match_all('/\\$[A-Za-z0-9_]+/', $this->path, $matches);
		return implode(', ', $matches[0] ?? []);
	}

	private function parseObjects(string $code, bool $createBlock = false):string {
		$replace = [];
		preg_match_all('/%([A-Za-z0-9_]+)(?:\\((.*)\\))?/', $code, $matches, PREG_SET_ORDER);
		foreach ($matches as $match){
			$name = $match[1];
			if (strlen($name) <= 2 && !isset(self::$classes[$name])) continue;
			$replace[$match[0]] = "phlo('$name'".(isset($match[2]) ? ', '.$this->parseObjects($match[2]) : void).')';
		}
		$code = strtr($code, $replace);
		if ($createBlock) $code = "{{ $code }}";
		return $code;
	}

	private function parsePHP(string $php):string {
		return str_replace([lf, '\\;'.lf, '(;', '[;', '{;', '};', ',;', '.;', ';;', lf.';'.lf], [';'.lf, lf, '(', '[', '{', '}', ',', '.', ';', lf.lf], $php.';');
	}

	private function buildArrow():string {
		$body = $this->parseObjects($this->body ?? void);
		if (strpos($body, lf)) $body = ltrim($this->indent($body));
		if (!str_starts_with($body, 'apply') && !str_starts_with($body, 'echo ') && !str_starts_with($body, 'unset(') && !str_starts_with($body, 'yield ')) $body = 'return '.$body;
		return "\t$body";
	}

	private function buildView():string {
		$blockDepth = 0;
		$view  = [];
		$lines = [];
		$body  = preg_replace('/{\\(\\s*(.*?)\\s*\\)}/s', '{{ ($1) }}', $this->body ?? void);
		foreach (explode(lf, $body) as $line){
			preg_match('/^\s*/', $line, $padMatch);
			$pad    = $padMatch[0] ?? void;
			$tabs   = substr_count($pad, "\t");
			$spaces = strlen(str_replace("\t", void, $pad));
			$depth  = $tabs + intdiv($spaces, 2);
			$trim   = ltrim($line);
			if (str_starts_with($trim, '<foreach ')) [$blockDepth++, $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'foreach ('.$this->parseObjects(trim(substr($trim, 9, -1))).'){'];
			elseif ($trim === '</foreach>') [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}', $blockDepth--];
			elseif (str_starts_with($trim, '<if ')) [$blockDepth++, $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'if ('.$this->parseObjects(trim(substr($trim, 4, -1))).'){'];
			elseif (str_starts_with($trim, '<elseif ')) [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}'.lf.str_repeat(tab, $blockDepth - 1).'elseif ('.$this->parseObjects(trim(substr($trim, 8, -1))).'){'];
			elseif ($trim === '<else>') [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}'.lf.str_repeat(tab, $blockDepth - 1).'else {'];
			elseif ($trim === '</if>') [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}', $blockDepth--];
			else {
				if (preg_match_all('/{\\s*([a-z]{2}):\\s*(.*?)\\s*}/is', $trim, $matches, PREG_SET_ORDER)){
					foreach ($matches as $match){
						$call = "{{ $match[1]('".strtr(rtrim($match[2]), [sq => bs.sq])."') }}";
						$trim = str_replace($match[0], $call, $trim);
					}
				}
				if (preg_match_all('/(\\h*)\\{\\{\\h*(?>(?:[^{}"\\\']+|"[^"]*"|\\\'[^\\\']*\\\'|\\{(?-1)\\})*)\\h*\\}\\}/u', $line, $matches, PREG_SET_ORDER)){
					foreach ($matches as $match){
						$indentDepth = $depth - $blockDepth;
						preg_match('/^\\h*\\{\\{\\h*(.*)\\h*\\}\\}\\h*$/s', $match[0], $innerMatch);
						$inner = $innerMatch[1] ?? void;
						if ($indentDepth && $match[1] !== void && trim($match[0]) === $trim) $trim = '{{ indentView('.$this->parseObjects($inner).($indentDepth === 1 ? void : ", $indentDepth").') }}';
						else $trim = str_replace(ltrim($match[0]), $this->parseObjects($inner, true), $trim);
					}
				}
				if (strpos($trim, '<') !== false){
					$trim = $this->normalizeViewTags($trim);
					$trim = preg_replace('/<([a-z][\\w:-]*)([^<>]*?)\\/>/', "<$1$2></$1>", $trim);
					if (preg_match_all('/\\s([A-Za-z_:][\\w:.-]*)=([^\\s"\\\'=<>`]+)(?=[\\s>])/', $trim, $matches, PREG_SET_ORDER)){
						foreach ($matches as $match) $trim = str_replace($match[0], space.$match[1].'="'.strtr($match[2], ['+' => space]).'"', $trim);
					}
				}
				if (preg_match_all('/%([A-Za-z_]\\w*(?:->\\w+)*)/', $trim, $matches, PREG_SET_ORDER)){
					foreach ($matches as $match){
						$full = $match[1];
						$base = explode('->', $full)[0];
						if ($base === 's') continue;
						$chain = strstr($full, '->') ?: void;
						$trim = str_replace($match[0], "{{ phlo('$base')".$chain.' }}', $trim);
					}
				}
				$segments = preg_split('/{{\s*(.*?)\s*}}/s', $trim, -1, PREG_SPLIT_DELIM_CAPTURE);
				$out = void;
				foreach ($segments as $i => $segment){
					if ($i % 2 === 0){
						$out .= strtr($segment, [bs.dq => bs.bs.bs.dq, dq => bs.dq]);
						continue;
					}
					$out .= dq.dot.$this->parseObjects(trim($segment)).dot.dq;
				}
				$trim   = $out;
				$indent = max(0, $depth - $blockDepth);
				$lines[] = [$blockDepth, str_repeat(tab, $indent).$trim];
			}
		}
		if ($lines) $view[] = $lines;
		if (count($view) === 1 && is_array($view[0]) && count($view[0]) === 1) return 'return "'.$view[0][0][1].'";';
		$output = '$_ = [];'.lf;
		foreach ($view as $chunk){
			if (is_array($chunk)) foreach ($chunk as $viewLine) $output .= str_repeat(tab, $viewLine[0]).'$_[] = "'.$viewLine[1].'";'.lf;
			else $output .= $chunk.lf;
		}
		$output = preg_replace('/(\\n\\t*)""\\./', '$1', $output);
		return $output.'return implode(lf, $_);';
	}

	private function normalizeViewTags(string $line):string {
		return preg_replace_callback('/<([a-z][\\w:-]*)(#[A-Za-z][\\w-]*)?((?:\\.[A-Za-z][\\w-]*)+)?([^<>]*?)(\\/?)>/', function($m){
			$attrs = $this->mergeClassAndId($m[4], $m[2] ? substr($m[2], 1) : null, $m[3] ? strtr(substr($m[3], 1), [dot => space]) : null);
			$attrs = trim($attrs);
			$attrs = $attrs ? space.$attrs : void;
			return '<'.$m[1].$attrs.($m[5] ? slash : void).'>';
		}, $line);
	}

	private function mergeClassAndId(string $attrs, ?string $id, ?string $shortClass):string {
		$attrs = trim($attrs);
		if ($shortClass){
			if (preg_match('/\\bclass\\s*=\\s*(\"([^\"]*)\"|\\\'([^\\\']*)\\\'|([^\\s\"\\\'=<>`]+))/', $attrs, $match)){
				$current = $match[2] ?? $match[3] ?? $match[4] ?? void;
				$attrs   = preg_replace('/\\bclass\\s*=\\s*(\"[^\"]*\"|\\\'[^\\\']*\\\'|[^\\s\"\\\'=<>`]+)/', void, $attrs, 1);
				$shortClass = trim($shortClass.space.$current);
			}
			$attrs = trim('class="'.trim($shortClass).'"'.space.$attrs);
		}
		if ($id && !preg_match('/\\bid\\s*=\\s*/', $attrs)) $attrs = trim('id="'.trim($id).'"'.space.$attrs);
		$attrs = preg_replace('/\\s+/', space, $attrs);
		return trim($attrs);
	}

	private function indent(string $value, int $depth = 1):string {
		$pad = str_repeat(tab, $depth);
		return $pad.str_replace(lf, lf.$pad, rtrim($value));
	}

	private function renderTrace(string $node, ?string $args):string {
		preg_match_all('/\$([A-Za-z_]\w*)/', (string)$args, $m);
		$vars = array_values(array_unique($m[1] ?? []));
		if (!$vars) return tab."trace('$node');";
		$list = implode(', ', array_map(fn($v) => "'$v'", $vars));
		return tab."trace('$node', compact($list));";
	}
}
