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

	// Control tags compile to block statements that wrap whole lines, so each must be on
	// its own line. An inline <if>/<foreach>/<else> (mid-line, or open and close on one
	// line) is rejected here rather than emitted as literal markup or mis-parsed.
	private function guardInlineControl(string $trim, int $ln):void {
		$block = $trim === '</if>' || $trim === '</foreach>' || $trim === '<else>'
			|| (str_ends_with($trim, '>') && (
				(str_starts_with($trim, '<if ') && !str_contains($trim, '</if>'))
				|| (str_starts_with($trim, '<foreach ') && !str_contains($trim, '</foreach>'))
				|| (str_starts_with($trim, '<elseif ') && !str_contains($trim, '</if>'))));
		if (!$block && preg_match('#<(?:if |foreach |elseif |else>)|</(?:if|foreach)>#', $trim))
			error('Build error: inline control tag on line '.(((int)$this->line) + 1 + $ln).': <if>/<foreach>/<else> must each be on their own line; got '.$trim);
	}

	// Pop the open block a close tag belongs to, failing on a stray close (no open) or a
	// mismatched one (e.g. </foreach> closing an <if>). Each stack entry is [type, line].
	private function popBlock(array &$stack, string $tag, int $ln):void {
		$open = array_pop($stack);
		$open !== null || error('Build error: stray </'.$tag.'> on line '.(((int)$this->line) + 1 + $ln).' has no matching <'.$tag.'>');
		$open[0] === $tag || error('Build error: </'.$tag.'> on line '.(((int)$this->line) + 1 + $ln).' closes a <'.$open[0].'> opened on line '.(((int)$this->line) + 1 + $open[1]));
	}

	// <else>/<elseif> are only valid directly inside an <if>.
	private function requireIf(array $stack, string $tag, int $ln):void {
		$open = $stack ? $stack[array_key_last($stack)] : null;
		($open && $open[0] === 'if') || error('Build error: <'.$tag.'> on line '.(((int)$this->line) + 1 + $ln).' must be inside an <if>');
	}

	private function buildView():string {
		$blockDepth = 0;
		$blockStack = [];
		$view  = [];
		$lines = [];
		$body  = preg_replace('/{\\(\\s*(.*?)\\s*\\)}/s', '{{ ($1) }}', $this->body ?? void);
		foreach (explode(lf, $body) as $ln => $line){
			preg_match('/^\s*/', $line, $padMatch);
			$pad    = $padMatch[0] ?? void;
			$tabs   = substr_count($pad, "\t");
			$spaces = strlen(str_replace("\t", void, $pad));
			$depth  = $tabs + intdiv($spaces, 2);
			$trim   = ltrim($line);
			$this->guardInlineControl($trim, $ln);
			if (str_starts_with($trim, '<foreach ')) [$blockStack[] = ['foreach', $ln], $blockDepth++, $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'foreach ('.$this->parseObjects(trim(substr($trim, 9, -1))).'){'];
			elseif ($trim === '</foreach>') [$this->popBlock($blockStack, 'foreach', $ln), $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}', $blockDepth--];
			elseif (str_starts_with($trim, '<if ')) [$blockStack[] = ['if', $ln], $blockDepth++, $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'if ('.$this->parseObjects(trim(substr($trim, 4, -1))).'){'];
			elseif (str_starts_with($trim, '<elseif ')) [$this->requireIf($blockStack, 'elseif', $ln), $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}'.lf.str_repeat(tab, $blockDepth - 1).'elseif ('.$this->parseObjects(trim(substr($trim, 8, -1))).'){'];
			elseif ($trim === '<else>') [$this->requireIf($blockStack, 'else', $ln), $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}'.lf.str_repeat(tab, $blockDepth - 1).'else {'];
			elseif ($trim === '</if>') [$this->popBlock($blockStack, 'if', $ln), $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}', $blockDepth--];
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
					$trim = $this->scanTags($trim);
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
		if ($blockStack){
			[$type, $openLn] = $blockStack[0];
			error('Build error: unclosed <'.$type.'> opened on line '.(((int)$this->line) + 1 + $openLn));
		}
		if (count($view) === 1 && is_array($view[0]) && count($view[0]) === 1) return 'return "'.$view[0][0][1].'";';
		$output = '$_ = [];'.lf;
		foreach ($view as $chunk){
			if (is_array($chunk)) foreach ($chunk as $viewLine) $output .= str_repeat(tab, $viewLine[0]).'$_[] = "'.$viewLine[1].'";'.lf;
			else $output .= $chunk.lf;
		}
		$output = preg_replace('/(\\n\\t*)""\\./', '$1', $output);
		return $output.'return implode(lf, $_);';
	}

	// Quote- and interpolation-aware tag normaliser. Finds each opening tag's real
	// closing '>' (skipping quoted values and {{ }} interpolations, so a '>' inside a
	// value or an arrow operator no longer ends the tag), then merges shorthand
	// #id/.class with explicit attributes and expands self-closing tags.
	private function scanTags(string $line):string {
		$out = void;
		$len = strlen($line);
		$i   = 0;
		while ($i < $len){
			$next = $line[$i + 1] ?? void;
			if ($line[$i] !== '<' || !ctype_alpha($next) || $next !== strtolower($next)){
				$out .= $line[$i];
				$i++;
				continue;
			}
			$end = $this->tagEnd($line, $i);
			if ($end === -1){
				$out .= $line[$i];
				$i++;
				continue;
			}
			$out .= $this->normalizeTag(substr($line, $i + 1, $end - $i - 1));
			$i = $end + 1;
		}
		return $out;
	}

	private function tagEnd(string $line, int $start):int {
		$len = strlen($line);
		$i   = $start + 1;
		while ($i < $len){
			$c = $line[$i];
			if ($c === dq || $c === sq){
				$close = strpos($line, $c, $i + 1);
				if ($close === false) return -1;
				$i = $close + 1;
			}
			elseif ($c === '{' && ($line[$i + 1] ?? void) === '{'){
				$close = strpos($line, '}}', $i + 2);
				if ($close === false) return -1;
				$i = $close + 2;
			}
			elseif ($c === '>') return $i;
			elseif ($c === '<') return -1;
			else $i++;
		}
		return -1;
	}

	private function normalizeTag(string $inner):string {
		$self = str_ends_with(rtrim($inner), slash);
		if ($self) $inner = rtrim(rtrim($inner), slash);
		if (!preg_match('/^([a-z][\w:-]*)((?:#[A-Za-z][\w-]*|\.[A-Za-z][\w-]*)*)/', $inner, $m)) return '<'.$inner.'>';
		$attrs   = trim(substr($inner, strlen($m[0])));
		$id      = null;
		$classes = [];
		if ($m[2] !== void){
			preg_match_all('/#([A-Za-z][\w-]*)|\.([A-Za-z][\w-]*)/', $m[2], $sm, PREG_SET_ORDER);
			foreach ($sm as $s){
				if (($s[1] ?? void) !== void && $s[1] !== '') $id = $s[1];
				elseif (($s[2] ?? void) !== void) $classes[] = $s[2];
			}
		}
		$attrs = $this->mergeClassAndId($attrs, $id, $classes ? implode(space, $classes) : null);
		return '<'.$m[1].($attrs !== void ? space.$attrs : void).($self ? '></'.$m[1].'>' : '>');
	}

	// End offset (exclusive) of the attribute value starting at $i, treating {{ }} as
	// opaque so a quote inside an interpolation does not end a quoted value early
	// (e.g. class="{( $x === "yes" ? .. )}" must not stop at the first inner ").
	private function attrValueEnd(string $s, int $i):int {
		$len = strlen($s);
		if ($i >= $len) return $i;
		$q = $s[$i];
		if ($q === dq || $q === sq){
			$j = $i + 1;
			while ($j < $len){
				if ($s[$j] === '{' && ($s[$j + 1] ?? void) === '{'){
					$close = strpos($s, '}}', $j + 2);
					$j = $close === false ? $len : $close + 2;
				}
				elseif ($s[$j] === $q) return $j + 1;
				else $j++;
			}
			return $len;
		}
		$j = $i;
		while ($j < $len && !ctype_space($s[$j]) && strpos('"\'=<>`', $s[$j]) === false) $j++;
		return $j;
	}

	private function mergeClassAndId(string $attrs, ?string $id, ?string $shortClass):string {
		$attrs = trim($attrs);
		if ($shortClass !== null){
			if (($pos = $this->topLevelAttrPos($attrs, 'class')) !== null){
				$vs = strpos($attrs, '=', $pos) + 1;
				while ($vs < strlen($attrs) && ctype_space($attrs[$vs])) $vs++;
				$ve = $this->attrValueEnd($attrs, $vs);
				$shortClass = trim($shortClass.space.trim(substr($attrs, $vs, $ve - $vs), '"\''));
				$attrs      = trim(substr($attrs, 0, $pos).substr($attrs, $ve));
			}
			$attrs = trim('class="'.trim($shortClass).'"'.space.$attrs);
		}
		if ($id !== null && $this->topLevelAttrPos($attrs, 'id') === null) $attrs = trim('id="'.$id.'"'.space.$attrs);
		return trim($attrs);
	}

	// Offset of a top-level attribute named $name (skipping quoted values and {{ }}
	// interpolations, so a class=/id= inside one is not mistaken for the attribute), or
	// null. The name must sit at an attribute boundary, not inside another name.
	private function topLevelAttrPos(string $attrs, string $name):?int {
		$len = strlen($attrs);
		$i   = 0;
		while ($i < $len){
			$c = $attrs[$i];
			if ($c === dq || $c === sq){
				$close = strpos($attrs, $c, $i + 1);
				if ($close === false) return null;
				$i = $close + 1;
			}
			elseif ($c === '{' && ($attrs[$i + 1] ?? void) === '{'){
				$close = strpos($attrs, '}}', $i + 2);
				if ($close === false) return null;
				$i = $close + 2;
			}
			elseif (($i === 0 || ctype_space($attrs[$i - 1])) && preg_match('/\G'.$name.'\s*=/', $attrs, $m, 0, $i)) return $i;
			else $i++;
		}
		return null;
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
