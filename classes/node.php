<?php
namespace phlo\tech;

class node extends \stdClass {
	public static array $classes = [];

	public function __construct(array $data = []){ foreach ($data AS $key => $value) $this->$key = $value; }
	public function __get($key){ return null; }

	public static function set_classes(array $classes):void {
		self::$classes = array_fill_keys($classes, true);
	}

	public function renderFunction(?string $class = null):string {
		$type = $this->type ? \phlo\colon.$this->type.\phlo\space : \phlo\void;
		if ($this->operator === 'view'){
			$body = $this->indent($this->buildView());
			$type = \phlo\colon.($this->type ?: 'string').\phlo\space;
		}
		elseif ($this->operator === 'arrow') $body = $this->parsePHP($this->buildArrow());
		else {
			$body = $this->body ? $this->parsePHP($this->parseObjects($class ? \strtr($this->body, ['$this' => "%$class"]) : $this->body)) : \phlo\void;
		}
		$body && $body = \phlo\lf.$body.\phlo\lf;
		return "function $this->name($this->args)$type{".$body."}\n";
	}

	public function renderRoute(string $class):string {
		$args = [];
		$pathArg = ($last = $this->method) ? \phlo\void : 'path: ';
		$asyncArg = ($last = $last && $this->path) ? \phlo\void : 'async: ';
		$dataArg = ($last = $last && $this->mode) ? \phlo\void : 'data: ';
		$cbArg = (($last = $last && $this->args) ? \phlo\void : 'cb: ').\phlo\sq.($this->shortRoute() ? $this->body : "phlo\app\\$class::$this->name").\phlo\sq;
		$args[] = \phlo\sq.$this->method.\phlo\sq;
		if ($this->path) $args[] = $pathArg.\phlo\sq.\strtr(\trim($this->path), [\phlo\sq => \phlo\bs.\phlo\sq]).\phlo\sq;
		if ($this->mode === 'async') $args[] = $asyncArg.'true';
		elseif (\in_array($this->mode, [\phlo\void, 'sync'])) $args[] = $asyncArg.'false';
		if ($this->data) $args[] = "$dataArg'$this->data'";
		$args[] = $cbArg;
		return 'route('.\implode(', ', $args).')';
	}

	public function shortRoute():bool {
		return $this->node === 'route' && \preg_match('/^[A-Za-z0-9_]+(?:::[A-Za-z0-9_]+)?$/', $this->body ?? \phlo\void);
	}

	public function renderMethod(string $class, ?string $constructorArgs = null):string {
		$static = $this->node === 'static' || $this->node === 'route' ? ' static' : \phlo\void;
		$name = ($this->node === 'prop' ? \phlo\us : \phlo\void).($this->name ?: 'view');
		$visibility = $this->visibility ?: ($static || \str_starts_with($name, '__') || \str_starts_with($name, 'obj') ? 'public' : 'protected');
		$type = $this->type ? \phlo\colon.$this->type.\phlo\space : \phlo\void;
		$args = $this->node === 'route' ? $this->route_args() : $this->args;
		if ($this->operator === 'view'){
			$body = $this->indent($this->buildView());
			$type = \phlo\colon.($this->type ?: 'string').\phlo\space;
		}
		elseif ($this->operator === 'arrow') $body = $this->parsePHP($this->buildArrow());
		else $body = \rtrim($this->body ?? \phlo\void) ? $this->parsePHP($this->parseObjects($this->body)) : \phlo\void;
		($this->node === 'route' || $this->node === 'static') && $body && $body = \strtr($body, ['$this' => "phlo('$class')"]);
		$this->name === 'controller' && $body && $body = $this->indent($body);
		$body && $body = \phlo\lf.$this->indent($body).\phlo\lf.\phlo\tab;
		$method = "\t$visibility$static function $name($args)$type{".$body."}\n";
		if ($this->name === '__handle'){
			$handleArgs = $constructorArgs ?: '...$data';
			$method = \strtr($method, ['static function __handle(){' => 'static function __handle('.$handleArgs.'){']);
		}
		return $method;
	}

	public function renderValue():string {
		$vis = $this->visibility ?: 'public';
		$const = $this->node === 'const' ? ' const' : \phlo\void;
		$static = $this->node === 'static' ? ' static' : \phlo\void;
		$readonly = $this->node === 'readonly' ? ' readonly' : \phlo\void;
		$name = ($const ? \phlo\void : '$').$this->name;
		$type = $this->type ? \phlo\space.$this->type : \phlo\void;
		if (isset($this->body)){
			$body = $this->body;
			if (\strpos($body, \phlo\lf)) $body = \ltrim($this->indent($body));
			$body = " = $body";
		}
		else $body = \phlo\void;
		return "\t$vis$const$static$readonly$type $name$body;\n";
	}

	private function route_args():string {
		if (!$this->path) return \phlo\void;
		\preg_match_all('/\\$[A-Za-z0-9_]+/', $this->path, $matches);
		return \implode(', ', $matches[0] ?? []);
	}

	private function parseObjects(string $code, bool $createBlock = false):string {
		$replace = [];
		\preg_match_all('/%([A-Za-z0-9_]+)(?:\\((.*)\\))?/', $code, $matches, PREG_SET_ORDER);
		foreach ($matches AS $match){
			$name = $match[1];
			if (\strlen($name) <= 2 && !isset(self::$classes[$name])) continue;
			$replace[$match[0]] = "phlo('$name'".(isset($match[2]) ? ', '.$this->parseObjects($match[2]) : \phlo\void).')';
		}
		$code = \strtr($code, $replace);
		if ($createBlock) $code = "{{ $code }}";
		return $code;
	}

	private function parsePHP(string $php):string {
		return \str_replace([\phlo\lf, '\\;'.\phlo\lf, '(;', '[;', '{;', '};', ',;', '.;', ';;', \phlo\lf.';'.\phlo\lf], [';'.\phlo\lf, \phlo\lf, '(', '[', '{', '}', ',', '.', ';', \phlo\lf.\phlo\lf], $php.';');
	}

	private function buildArrow():string {
		$body = $this->parseObjects($this->body ?? \phlo\void);
		$body = \strpos($body, \phlo\lf) ? \ltrim($this->indent($body)) : $body;
		if (!\str_starts_with($body, 'apply') && !\str_starts_with($body, 'echo ') && !\str_starts_with($body, 'unset(') && !\str_starts_with($body, 'yield ')) $body = "return $body";
		return "\t$body";
	}

	private function buildView():string {
		$blockDepth = 0;
		$view = [];
		$lines = [];
		$body = \preg_replace('/{\\(\\s*(.*?)\\s*\\)}/s', '{{ ($1) }}', $this->body ?? \phlo\void);
		foreach (\explode(\phlo\lf, $body) AS $line){
			\preg_match('/^\\s*/', $line, $padMatch);
			$pad = $padMatch[0] ?? \phlo\void;
			$tabs = \substr_count($pad, "\t");
			$spaces = \strlen(\str_replace("\t", '', $pad));
			$tabWidth = 2;
			$depth = $tabs + \intdiv($spaces, $tabWidth);
			$trim = \ltrim($line);
			if (\str_starts_with($trim, '<foreach ')) [$blockDepth++, $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(\phlo\tab, $blockDepth - 1).'foreach ('.$this->parseObjects(\trim(\substr($trim, 9, -1))).'){'];
			elseif ($trim === '</foreach>') [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(\phlo\tab, $blockDepth - 1).'}', $blockDepth--];
			elseif (\str_starts_with($trim, '<if ')) [$blockDepth++, $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(\phlo\tab, $blockDepth - 1).'if ('.$this->parseObjects(\trim(\substr($trim, 4, -1))).'){'];
			elseif (\str_starts_with($trim, '<elseif ')) [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(\phlo\tab, $blockDepth - 1).'}'.\phlo\lf.str_repeat(\phlo\tab, $blockDepth - 1).'elseif ('.$this->parseObjects(\trim(\substr($trim, 8, -1))).'){'];
			elseif ($trim === '<else>') [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(\phlo\tab, $blockDepth - 1).'}'.\phlo\lf.str_repeat(\phlo\tab, $blockDepth - 1).'else {'];
			elseif ($trim === '</if>') [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(\phlo\tab, $blockDepth - 1).'}', $blockDepth--];
			else {
				if (\preg_match_all('/{\\s*([a-z]{2}):\\s*(.*?)\\s*(?:\\(\\s*(?=[^)]*[$%\'"\\d])(.+?)\\s*\\))?\\s*}/is', $trim, $matches, PREG_SET_ORDER)){
					foreach ($matches AS $match){
						$call = "{{ $match[1]('".\strtr(\rtrim($match[2]), [\phlo\sq => \phlo\bs.\phlo\sq])."'".(isset($match[3]) ? ", $match[3]" : \phlo\void).') }}';
						$trim = \str_replace($match[0], $call, $trim);
					}
				}
				if (\preg_match_all('/(\\h*)\\{\\{\\h*(?>(?:[^{}"\\\']+|"[^"]*"|\\\'[^\\\']*\\\'|\\{(?-1)\\})*)\\h*\\}\\}/u', $line, $matches, PREG_SET_ORDER)){
					foreach ($matches AS $match){
						$indentDepth = ($depth - $blockDepth);
						\preg_match('/^\\h*\\{\\{\\h*(.*)\\h*\\}\\}\\h*$/s', $match[0], $innerMatch);
						$inner = $innerMatch[1] ?? \phlo\void;
						if ($indentDepth && $match[1] !== \phlo\void && \trim($match[0]) === $trim) $trim = '{{ indentView('.$this->parseObjects($inner).($indentDepth === 1 ? \phlo\void : ", $indentDepth").') }}';
						else $trim = \str_replace(\ltrim($match[0]), $this->parseObjects($inner, true), $trim);
					}
				}
				if (\strpos($trim, '<') !== false){
					$trim = $this->normalizeViewTags($trim);
					$trim = \preg_replace('/<([a-z][\\w-]*)([^<>]*?)\\/>/', "<$1$2></$1>", $trim);
					if (\preg_match_all('/\\s([A-Za-z_:][\\w:.-]*)=([^\\s"\\\'=<>`]+)(?=[\\s>])/', $trim, $matches, PREG_SET_ORDER)){
						foreach ($matches AS $match) $trim = \str_replace($match[0], \phlo\space.$match[1].'="'.\strtr($match[2], ['+' => \phlo\space]).'"', $trim);
					}
				}
				if (\preg_match_all('/%([A-Za-z_]\\w*(?:->\\w+)*)/', $trim, $matches, PREG_SET_ORDER)){
					foreach ($matches AS $match){
						$full = $match[1];
						$base = \explode('->', $full)[0];
						if ($base === 's') continue;
						$chain = strstr($full, '->') ?: \phlo\void;
						$trim = \str_replace($match[0], "{{ phlo('$base')".$chain.' }}', $trim);
					}
				}
				$segments = preg_split('/{{\s*(.*?)\s*}}/s', $trim, -1, PREG_SPLIT_DELIM_CAPTURE);
				$out = \phlo\void;
				foreach ($segments AS $i => $segment){
					if ($i % 2 === 0){
						$out .= \strtr($segment, [\phlo\bs.\phlo\dq => \phlo\bs.\phlo\bs.\phlo\bs.\phlo\dq, \phlo\dq => \phlo\bs.\phlo\dq]);
						continue;
					}
					$expr = \trim($segment);
					$expr = $this->parseObjects($expr);
					$out .= \phlo\dq.\phlo\dot.$expr.\phlo\dot.\phlo\dq;
				}
				$trim = $out;
				$indent = \max(0, $depth - $blockDepth);
				$lines[] = [$blockDepth, str_repeat(\phlo\tab, $indent).$trim];
			}
		}
		$lines && $view[] = $lines;
		if (\count($view) === 1 && is_array($view[0]) && \count($view[0]) === 1) return 'return "'.$view[0][0][1].'";';
		$output = '$phloView = [];'.\phlo\lf;
		foreach ($view AS $chunk){
			if (is_array($chunk)) foreach ($chunk AS $line) $output .= str_repeat(\phlo\tab, $line[0]).'$phloView[] = "'.$line[1].'";'.\phlo\lf;
			else $output .= $chunk.\phlo\lf;
		}
		$output = \preg_replace('/(\\n\\t*)""\\./', '$1', $output);
		return $output.'return \implode(lf, $phloView);';
	}

	private function normalizeViewTags(string $line):string {
		return \preg_replace_callback('/<([a-z][\\w-]*)(#[A-Za-z][\\w-]*)?((?:\\.[A-Za-z][\\w-]*)+)?([^<>]*?)(\\/?)>/', function($m){
			$tag = $m[1];
			$id = $m[2] ? \substr($m[2], 1) : null;
			$shortClass = $m[3] ? \strtr(\substr($m[3], 1), [\phlo\dot => \phlo\space]) : null;
			$attrs = $m[4];
			$closing = $m[5] ? \phlo\slash : \phlo\void;

			$attrs = $this->mergeClassAndId($attrs, $id, $shortClass);
			$attrs = \trim($attrs);
			$attrs = $attrs ? \phlo\space.$attrs : \phlo\void;

			return '<'.$tag.$attrs.$closing.'>';
		}, $line);
	}

	private function mergeClassAndId(string $attrs, ?string $id, ?string $shortClass):string {
		$attrs = \trim($attrs);
		if ($shortClass){
			if (\preg_match('/\\bclass\\s*=\\s*(\"([^\"]*)\"|\\\'([^\\\']*)\\\'|([^\\s\"\\\'=<>`]+))/', $attrs, $match)){
				$current = $match[2] ?? $match[3] ?? $match[4] ?? \phlo\void;
				$attrs = \preg_replace('/\\bclass\\s*=\\s*(\"[^\"]*\"|\\\'[^\\\']*\\\'|[^\\s\"\\\'=<>`]+)/', \phlo\void, $attrs, 1);
				$shortClass = \trim($shortClass.\phlo\space.$current);
			}
			$attrs = \trim('class="'.\trim($shortClass).'"'.\phlo\space.$attrs);
		}
		if ($id && !\preg_match('/\\bid\\s*=\\s*/', $attrs)) $attrs = \trim('id="'.\trim($id).'"'.\phlo\space.$attrs);
		$attrs = \preg_replace('/\\s+/', \phlo\space, $attrs);
		return \trim($attrs);
	}

	private function indent(string $value, int $depth = 1):string {
		$pad = str_repeat(\phlo\tab, $depth);
		return $pad.\str_replace(\phlo\lf, \phlo\lf.$pad, \rtrim($value));
	}
}
