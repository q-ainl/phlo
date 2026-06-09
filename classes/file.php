<?php

class build_file {
	public string $file;
	public string $class;
	public array $meta = [];
	public array $nodes = [];
	public array $functions = [];
	public array $assets = [];

	public function __construct(string $file){
		is_file($file) || error('Phlo file does not exist: '.esc($file));
		$this->file  = $file;
		$this->class = strtr(basename($file, '.phlo'), [dot => us]);
		$this->parse();
	}

	private function parse():void {
		$fp = fopen($this->file, 'r');
		$lineIndex          = 0;
		$controllerLines    = [];
		$controllerLine     = null;
		$controllerClosed   = false;
		$controllerComments = [];
		$comments           = [];
		while (($line = fgets($fp)) !== false){
			++$lineIndex;
			$line = rtrim($line, "\r\n");
			$trim = ltrim($line);
			if (str_starts_with($trim, '@')){
				$meta = trim(substr($trim, 1));
				if (str_contains($meta, colon)){
					[$key, $value] = explode(colon, $meta, 2);
					$key   = trim($key);
					$value = ltrim($value);
					$this->meta[$key] = $value;
					if ($key === 'class') $this->class = $value;
				}
				continue;
			}
			if ($trim === void || str_starts_with($trim, '//') || str_starts_with($trim, '#')){
				($comment = ltrim($trim, '/# 	')) && $comments[] = $comment;
				continue;
			}
			$nodeData = $this->parse_node_header($trim);
			if (!$nodeData){
				if ($controllerClosed) error('Build error: Controller must be in one place '.$this->file);
				if ($comments && !$controllerLines){
					$controllerComments = $comments;
					$comments = [];
				}
				$controllerLines[] = $line;
				$controllerLine ??= $lineIndex;
				continue;
			}
			if ($controllerLines && !$controllerClosed){
				$controller = new build_node([
					'node'     => 'method',
					'name'     => 'controller',
					'operator' => 'method',
					'body'     => implode(lf, $controllerLines),
					'line'     => $controllerLine,
				]);
				if ($controllerComments) $controller->comments = implode(lf, $controllerComments);
				$this->add_node($controller);
				$controllerClosed   = true;
				$controllerLines    = [];
				$controllerLine     = null;
				$controllerComments = [];
			}
			$nodeData['line'] = $lineIndex;
			$node = new build_node($nodeData);
			if ($comments){
				$node->comments = implode(lf, $comments);
				$comments = [];
			}
			if (in_array($node->node, ['script', 'style'], true)){
				$node->body = $this->collect_block($fp, $lineIndex, "</$node->node>");
				$this->assets[] = $node;
				continue;
			}
			if ($node->operator === 'arrow')        $node->body = $this->collect_inline_block($fp, $lineIndex, $node->body ?? void);
			elseif ($node->operator === 'method')   $node->body = ($node->body ?? void) === void ? $this->collect_block($fp, $lineIndex, '}') : $this->collect_inline_block($fp, $lineIndex, $node->body);
			elseif ($node->operator === 'value')    $node->body = $this->collect_inline_block($fp, $lineIndex, $node->body ?? void);
			elseif ($node->operator === 'view')     $node->body = ($node->body ?? void) === void ? $this->collect_block($fp, $lineIndex, void) : ltrim($node->body);
			if ($node->node === 'function'){
				if ($node->name) $this->functions[$node->name] = $node;
				continue;
			}
			$this->add_node($node);
		}
		fclose($fp);
		if ($controllerLines){
			$controller = new build_node([
				'node'     => 'method',
				'name'     => 'controller',
				'operator' => 'method',
				'body'     => implode(lf, $controllerLines),
				'line'     => $controllerLine,
			]);
			if ($controllerComments) $controller->comments = implode(lf, $controllerComments);
			$this->add_node($controller);
		}
	}

	private function add_node(build_node $node):void {
		if (!$node->name && $node->node === 'view')  $node->name = 'view';
		if (!$node->name && $node->node === 'route') $node->name = $this->route_name($node);
		$key = $node->name ?: $node->node.'@'.$node->line;
		if (isset($this->nodes[$key])) error('Build error: Duplicate node "'.$key.'" in '.$this->file);
		$this->nodes[$key] = $node;
	}

	private function parse_node_header(string $trim):?array {
		if (preg_match('/^<(script|style)(?:\s+ns=([^>]+))?>$/i', $trim, $match)) return ['node' => strtolower($match[1]), 'ns' => isset($match[2]) ? trim($match[2]) : null];
		if (str_starts_with($trim, 'route')){
			if (!preg_match('/^route\s*(sync|async|both)?\s*(GET|POST|PUT|PATCH|DELETE)(?:\s+([^@{>]*?[^@\s{>]))?(?:\s*@([A-Za-z,]+))?\s*(\{|\=\>)\s*(.*)$/', $trim, $node)) return null;
			return [
				'node'     => 'route',
				'mode'     => $node[1] ?: null,
				'method'   => $node[2] ?: null,
				'path'     => $node[3] ?: null,
				'data'     => $node[4] ?: null,
				'operator' => $node[5] === '{' ? 'method' : 'arrow',
				'body'     => $node[6] ?: null,
			];
		}
		if (str_starts_with($trim, 'function')){
			[$signature, $operator, $body] = $this->split_operator($trim, ['=>', '{']);
			[$name, $args, $type] = $this->parse_name_args_type(trim(substr($signature, strlen('function'))), false);
			return ['node' => 'function', 'name' => $name, 'args' => $args, 'type' => $type, 'operator' => $this->map_operator($operator), 'body' => $body];
		}
		if (!preg_match('/^(?:(public|protected|private)\s+)?(static|method|prop|view|const|readonly)\b(.*)$/', $trim, $match)) return null;
		$nodeType  = $match[2];
		$operators = $nodeType === 'view' ? [':', '=>', '{'] : ['=>', '{', '='];
		[$signature, $operator, $body] = $this->split_operator($nodeType.space.trim($match[3]), $operators);
		// A keyword immediately followed by a call, view('x', scroll: 0) or static::foo(), with no node
		// operator is a controller statement, not a node declaration. (Bare decls like `prop class` keep a name.)
		if ($operator === null){
			$rest = ltrim($match[3]);
			if ($rest !== void && ($rest[0] === '(' || str_starts_with($rest, '::'))) return null;
		}
		[$name, $args, $type] = $this->parse_name_args_type(trim(substr($signature, strlen($nodeType))), $nodeType === 'view');
		return [
			'node'       => $nodeType,
			'visibility' => $match[1] ?: null,
			'name'       => $name,
			'args'       => $args,
			'type'       => $type,
			'operator'   => $this->map_operator($operator, $nodeType),
			'body'       => $body,
		];
	}

	private function map_operator(?string $operator, ?string $nodeType = null):?string {
		return match ($operator) {
			'=>'    => 'arrow',
			'{'     => 'method',
			':'     => $nodeType === 'view' ? 'view' : null,
			'='     => 'value',
			default => null,
		};
	}

	private function split_operator(string $line, array $operators):array {
		$bestPos = null;
		$bestOp  = null;
		foreach ($operators as $operator){
			$pos = $operator === ':' ? $this->find_top_level_colon_operator($line) : $this->find_top_level_token($line, $operator, false);
			if ($pos === null) continue;
			if ($bestPos === null || $pos < $bestPos){
				$bestPos = $pos;
				$bestOp  = $operator;
			}
		}
		if ($bestOp !== null){
			$signature = trim(substr($line, 0, $bestPos));
			$body      = trim(substr($line, $bestPos + strlen($bestOp)));
			return [$signature, $bestOp, $body !== void ? $body : null];
		}
		return [trim($line), null, null];
	}

	private function parse_name_args_type(string $signature, bool $nameOptional):array {
		$signature = trim($signature);
		if ($signature === void) return [null, null, null];
		if (($open = $this->find_top_level_token($signature, '(')) !== null){
			$close = $this->find_matching_paren($signature, $open);
			$name  = trim(substr($signature, 0, $open));
			$args  = substr($signature, $open + 1, $close - $open - 1);
			$after = trim(substr($signature, $close + 1));
			$type  = null;
			if ($after && $after[0] === colon) $type = trim(substr($after, 1));
			if ($name === void && !$nameOptional) error('Build error: Missing name in '.$this->file);
			return [$name ?: null, $args ?: null, $type ?: null];
		}
		$type = null;
		$name = $signature;
		if (($pos = $this->find_top_level_token($signature, colon, true)) !== null){
			$name = trim(substr($signature, 0, $pos));
			$type = trim(substr($signature, $pos + 1)) ?: null;
		}
		if ($name === void && !$nameOptional) error('Build error: Missing name in '.$this->file);
		return [$name ?: null, null, $type];
	}

	private function route_name(build_node $node):string {
		$parts = [];
		if ($node->mode)   $parts[] = $node->mode;
		if ($node->method) $parts[] = $node->method;
		if ($node->path){
			preg_match_all('/(?:^| |\$)([A-Za-z0-9_]+)/', $node->path, $matches);
			$pathParts = $matches[1] ?? [];
			$parts = array_merge($parts, $pathParts ?: ['home']);
		}
		else $parts[] = 'home';
		if ($node->data) $parts[] = strtr($node->data, [comma => space]);
		$name = ucfirst($this->camelize(implode(space, $parts)));
		return $name ?: 'route';
	}

	private function camelize(string $value):string {
		$value = preg_replace('/[^A-Za-z0-9]+/', space, $value);
		$value = ucwords(trim($value));
		$value = str_replace(space, void, $value);
		return lcfirst($value);
	}

	private function collect_block($fp, int &$lineIndex, string $find):string {
		$body = void;
		while (($line = fgets($fp)) !== false){
			++$lineIndex;
			$line = rtrim($line, "\r\n");
			if ($find !== void && $line === $find) break;
			if ($find === void && $line === void)  break;
			$body .= $line.lf;
		}
		return rtrim($body);
	}

	private function collect_inline_block($fp, int &$lineIndex, string $body):string {
		$translate = ['[' => ']', '(' => ')', '{' => '}'];
		if ($body === void) return $body;
		if (!isset($translate[substr($body, -1)])) return $body;
		$found = array_filter(str_split($body), static fn($c) => isset($translate[$c]));
		if (!$found) return $body;
		$end   = strtr(implode($found), $translate);
		$body .= lf;
		while (($line = fgets($fp)) !== false){
			++$lineIndex;
			$line = rtrim($line, "\r\n");
			if ($line === $end) break;
			$body .= $line.lf;
		}
		return rtrim($body.$end);
	}

	private function find_top_level_token(string $line, string $token, bool $last = false):?int {
		$len     = strlen($line);
		$depth   = 0;
		$inSingle = false;
		$inDouble = false;
		$pos     = null;
		for ($i = 0; $i < $len; ++$i){
			$char = $line[$i];
			if ($char === sq && !$inDouble && ($i === 0 || $line[$i - 1] !== bs)) $inSingle = !$inSingle;
			elseif ($char === dq && !$inSingle && ($i === 0 || $line[$i - 1] !== bs)) $inDouble = !$inDouble;
			if ($inSingle || $inDouble) continue;
			if ($depth === 0 && substr($line, $i, strlen($token)) === $token){
				$pos = $i;
				if (!$last) return $i;
			}
			if ($char === '(' || $char === '[' || $char === '{') ++$depth;
			elseif (($char === ')' || $char === ']' || $char === '}') && $depth > 0) --$depth;
		}
		return $pos;
	}

	private function find_top_level_colon_operator(string $line):?int {
		$len     = strlen($line);
		$depth   = 0;
		$inSingle = false;
		$inDouble = false;
		for ($i = 0; $i < $len; ++$i){
			$char = $line[$i];
			if ($char === sq && !$inDouble && ($i === 0 || $line[$i - 1] !== bs)) $inSingle = !$inSingle;
			elseif ($char === dq && !$inSingle && ($i === 0 || $line[$i - 1] !== bs)) $inDouble = !$inDouble;
			if ($inSingle || $inDouble) continue;
			if ($char === '(') ++$depth;
			elseif ($char === ')' && $depth > 0) --$depth;
			if ($depth || $char !== colon) continue;
			$next = $i + 1 < $len ? $line[$i + 1] : null;
			if ($next === null || $next === "\t" || $next === space) return $i;
		}
		return null;
	}

	private function find_matching_paren(string $line, int $open):int {
		$len   = strlen($line);
		$depth = 0;
		for ($i = $open; $i < $len; ++$i){
			if ($line[$i] === '(') ++$depth;
			elseif ($line[$i] === ')' && --$depth === 0) return $i;
		}
		return $len - 1;
	}
}
