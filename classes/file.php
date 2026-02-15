<?php
namespace phlo\tech;

class file {
	public string $file;
	public string $class;
	public array $meta = [];
	public array $nodes = [];
	public array $functions = [];
	public array $assets = [];

	public function __construct(string $file){
		\is_file($file) || \phlo\error('Phlo file does not exist: '.\phlo\esc($file));
		$this->file = $file;
		$this->class = \strtr(\basename($file, '.phlo'), [\phlo\dot => \phlo\us]);
		$this->parse();
	}

	private function parse():void {
		$fp = \fopen($this->file, 'r');
		$lineIndex = 0;
		$controllerLines = [];
		$controllerLine = null;
		$controllerClosed = false;
		$controllerComments = [];
		$comments = [];
		while (($line = \fgets($fp)) !== false){
			$lineIndex++;
			$line = \rtrim($line, "\r\n");
			$trim = \ltrim($line);
			if (\str_starts_with($trim, '@')){
				$meta = \trim(substr($trim, 1));
				if (\str_contains($meta, \phlo\colon)){
					[$key, $value] = \explode(\phlo\colon, $meta, 2);
					$key = \trim($key);
					$value = \ltrim($value);
					$this->meta[$key] = $value;
					if ($key === 'class') $this->class = $value;
				}
				continue;
			}
			if ($trim === \phlo\void || \str_starts_with($trim, '//') || \str_starts_with($trim, '#')){
				($comment = \ltrim($trim, '/# 	')) && $comments[] = $comment;
				continue;
			}
			$nodeData = $this->parse_node_header($trim);
			if (!$nodeData){
				if ($controllerClosed) \phlo\error('Build Error: Controller must be in one place '.$this->file);
				if ($comments && !$controllerLines){
					$controllerComments = $comments;
					$comments = [];
				}
				$controllerLines[] = $line;
				$controllerLine ??= $lineIndex;
				continue;
			}
			if ($controllerLines && !$controllerClosed){
				$controller = new node([
					'node' => 'method',
					'name' => 'controller',
					'operator' => 'method',
					'body' => implode(\phlo\lf, $controllerLines),
					'line' => $controllerLine,
				]);
				if ($controllerComments) $controller->comments = implode(\phlo\lf, $controllerComments);
				$this->add_node($controller);
				$controllerClosed = true;
				$controllerLines = [];
				$controllerLine = null;
				$controllerComments = [];
			}
			$nodeData['line'] = $lineIndex;
			$node = new node($nodeData);
			if ($comments){
				$node->comments = implode(\phlo\lf, $comments);
				$comments = [];
			}
			if (\in_array($node->node, ['script', 'style'])){
				$node->body = $this->collect_block($fp, $lineIndex, "</$node->node>");
				$this->assets[] = $node;
				continue;
			}
			if ($node->operator === 'arrow'){
				$node->body = $this->collect_inline_block($fp, $lineIndex, $node->body ?? \phlo\void);
			}
			elseif ($node->operator === 'method'){
				if (($node->body ?? \phlo\void) === \phlo\void) $node->body = $this->collect_block($fp, $lineIndex, '}');
				else $node->body = $this->collect_inline_block($fp, $lineIndex, $node->body);
			}
			elseif ($node->operator === 'view'){
				if (($node->body ?? \phlo\void) === \phlo\void) $node->body = $this->collect_block($fp, $lineIndex, \phlo\void);
				else $node->body = \ltrim($node->body);
			}
			if ($node->node === 'function'){
				$node->name && $this->functions[$node->name] = $node;
				continue;
			}
			$this->add_node($node);
		}
		\fclose($fp);
		if ($controllerLines){
			$controller = new node([
				'node' => 'method',
				'name' => 'controller',
				'operator' => 'method',
				'body' => implode(\phlo\lf, $controllerLines),
				'line' => $controllerLine,
			]);
			if ($controllerComments) $controller->comments = implode(\phlo\lf, $controllerComments);
			$this->add_node($controller);
		}
	}

	private function add_node(node $node):void {
		if (!$node->name && $node->node === 'view') $node->name = 'view';
		if (!$node->name && $node->node === 'route') $node->name = $this->route_name($node);
		$key = $node->name ?: $node->node.'@'.$node->line;
		if (isset($this->nodes[$key])) \phlo\error("Build Error: Duplicate node \"$key\" in $this->file");
		$this->nodes[$key] = $node;
	}

	private function parse_node_header(string $trim):?array {
		if (\preg_match('/^<(script|style)(?:\s+ns=([^>]+))?>$/i', $trim, $match)){
			return ['node' => strtolower($match[1]), 'ns' => isset($match[2]) ? \trim($match[2]) : null];
		}
		if (\preg_match('/^route\b/', $trim)){
			if (!\preg_match('/^route\s*(sync|async|both)?\s*(GET|POST|PUT|PATCH|DELETE)(?:\s+([^@{>]*?[^@\s{>]))?(?:\s*@([A-Za-z,]+))?\s*(\{|\=\>)\s*(.*)$/', $trim, $node)) return null;
			$operator = $node[5] === '{' ? 'method' : 'arrow';
			return [
				'node' => 'route',
				'mode' => $node[1] ?: null,
				'method' => $node[2] ?: null,
				'path' => $node[3] ?: null,
				'data' => $node[4] ?: null,
				'operator' => $operator,
				'body' => $node[6] ?: null,
			];
		}
		if (\preg_match('/^function\b/', $trim)){
			[$signature, $operator, $body] = $this->split_operator($trim, ['=>', '{']);
			$sig = \trim(substr($signature, strlen('function')));
			[$name, $args, $type] = $this->parse_name_args_type($sig, false);
			return [
				'node' => 'function',
				'name' => $name,
				'args' => $args,
				'type' => $type,
				'operator' => $this->map_operator($operator),
				'body' => $body,
			];
		}
		if (!\preg_match('/^(?:(public|protected|private)\s+)?(static|method|prop|view|const|readonly)\b(.*)$/', $trim, $match)) return null;
		$visibility = $match[1] ?: null;
		$nodeType = $match[2];
		$rest = \trim($match[3]);
		$operators = $nodeType === 'view' ? [':', '=>', '{'] : ['=>', '{', '='];
		[$signature, $operator, $body] = $this->split_operator("$nodeType $rest", $operators);
		$sig = \trim(substr($signature, strlen($nodeType)));
		[$name, $args, $type] = $this->parse_name_args_type($sig, $nodeType === 'view');
		return [
			'node' => $nodeType,
			'visibility' => $visibility,
			'name' => $name,
			'args' => $args,
			'type' => $type,
			'operator' => $this->map_operator($operator, $nodeType),
			'body' => $body,
		];
	}

	private function map_operator(?string $operator, ?string $nodeType = null):?string {
		return match ($operator) {
			'=>' => 'arrow',
			'{' => 'method',
			':' => $nodeType === 'view' ? 'view' : null,
			'=' => 'value',
			default => null,
		};
	}

	private function split_operator(string $line, array $operators):array {
		$bestPos = null;
		$bestOp = null;
		foreach ($operators AS $operator){
			$pos = $operator === ':' ? $this->find_top_level_colon_operator($line) : $this->find_top_level_token($line, $operator, false);
			if (is_null($pos)) continue;
			if ($bestPos === null || $pos < $bestPos){
				$bestPos = $pos;
				$bestOp = $operator;
			}
		}
		if ($bestOp !== null){
			$signature = \trim(substr($line, 0, $bestPos));
			$body = \trim(substr($line, $bestPos + strlen($bestOp)));
			return [$signature, $bestOp, $body !== \phlo\void ? $body : null];
		}
		return [\trim($line), null, null];
	}

	private function parse_name_args_type(string $signature, bool $nameOptional):array {
		$signature = \trim($signature);
		if ($signature === \phlo\void) return [null, null, null];
		if (($open = $this->find_top_level_token($signature, '(')) !== null){
			$close = $this->find_matching_paren($signature, $open);
			$name = \trim(substr($signature, 0, $open));
			$args = substr($signature, $open + 1, $close - $open - 1);
			$after = \trim(substr($signature, $close + 1));
			$type = null;
			if ($after && $after[0] === \phlo\colon) $type = \trim(substr($after, 1));
			if ($name === \phlo\void && !$nameOptional) \phlo\error("Build Error: Missing name in $this->file");
			return [$name ?: null, $args ?: null, $type ?: null];
		}
		$type = null;
		$name = $signature;
		if (($pos = $this->find_top_level_token($signature, \phlo\colon, true)) !== null){
			$name = \trim(substr($signature, 0, $pos));
			$type = \trim(substr($signature, $pos + 1)) ?: null;
		}
		if ($name === \phlo\void && !$nameOptional) \phlo\error("Build Error: Missing name in $this->file");
		return [$name ?: null, null, $type];
	}

	private function route_name(node $node):string {
		$parts = [];
		$node->mode && $parts[] = $node->mode;
		$node->method && $parts[] = $node->method;
		if ($node->path){
			preg_match_all('/(?:^| |\$)([A-Za-z0-9_]+)/', $node->path, $matches);
			$parts = array_merge($parts, $matches[1] ?: ['home']);
		}
		else $parts[] = 'home';
		if ($node->data) $parts[] = \strtr($node->data, [\phlo\comma => \phlo\space]);
		$name = ucfirst($this->camelize(implode(\phlo\space, $parts)));
		return $name ?: 'route';
	}

	private function camelize(string $value):string {
		$value = preg_replace('/[^A-Za-z0-9]+/', \phlo\space, $value);
		$value = ucwords(\trim($value));
		$value = str_replace(\phlo\space, \phlo\void, $value);
		return lcfirst($value);
	}

	private function collect_block($fp, int &$lineIndex, string $find):string {
		$body = \phlo\void;
		while (($line = \fgets($fp)) !== false){
			$lineIndex++;
			$line = \rtrim($line, "\r\n");
			if ($find !== \phlo\void && $line === $find) break;
			if ($find === \phlo\void && $line === \phlo\void) break;
			$body .= $line.\phlo\lf;
		}
		return \rtrim($body);
	}

	private function collect_inline_block($fp, int &$lineIndex, string $body):string {
		$translate = ['[' => ']', '(' => ')', '{' => '}'];
		if ($body === \phlo\void) return $body;
		if (!isset($translate[substr($body, -1)])) return $body;
		$found = array_filter(str_split($body), fn($c) => isset($translate[$c]));
		if (!$found) return $body;
		$end = \strtr(implode($found), $translate);
		$body .= \phlo\lf;
		while (($line = \fgets($fp)) !== false){
			$lineIndex++;
			$line = \rtrim($line, "\r\n");
			if ($line === $end) break;
			$body .= $line.\phlo\lf;
		}
		return \rtrim($body.$end);
	}

	private function find_top_level_token(string $line, string $token, bool $last = false):?int {
		$len = strlen($line);
		$depth = 0;
		$inSingle = false;
		$inDouble = false;
		$pos = null;
		for ($i = 0; $i < $len; $i++){
			$char = $line[$i];
			if ($char === \phlo\sq && !$inDouble && ($i === 0 || $line[$i - 1] !== \phlo\bs)) $inSingle = !$inSingle;
			elseif ($char === \phlo\dq && !$inSingle && ($i === 0 || $line[$i - 1] !== \phlo\bs)) $inDouble = !$inDouble;
			if ($inSingle || $inDouble) continue;
			if ($depth === 0 && substr($line, $i, strlen($token)) === $token){
				$pos = $i;
				if (!$last) return $i;
			}
			if ($char === '(' || $char === '[' || $char === '{') $depth++;
			elseif (($char === ')' || $char === ']' || $char === '}') && $depth > 0) $depth--;
		}
		return $pos;
	}

	private function find_top_level_colon_operator(string $line):?int {
		$len = strlen($line);
		$depth = 0;
		$inSingle = false;
		$inDouble = false;
		for ($i = 0; $i < $len; $i++){
			$char = $line[$i];
			if ($char === \phlo\sq && !$inDouble && ($i === 0 || $line[$i - 1] !== \phlo\bs)) $inSingle = !$inSingle;
			elseif ($char === \phlo\dq && !$inSingle && ($i === 0 || $line[$i - 1] !== \phlo\bs)) $inDouble = !$inDouble;
			if ($inSingle || $inDouble) continue;
			if ($char === '(') $depth++;
			elseif ($char === ')' && $depth > 0) $depth--;
			if ($depth) continue;
			if ($char !== \phlo\colon) continue;
			$next = $i + 1 < $len ? $line[$i + 1] : null;
			if ($next === null || $next === "\t" || $next === \phlo\space) return $i;
		}
		return null;
	}

	private function find_matching_paren(string $line, int $open):int {
		$len = strlen($line);
		$depth = 0;
		for ($i = $open; $i < $len; $i++){
			if ($line[$i] === '(') $depth++;
			elseif ($line[$i] === ')' && --$depth === 0) return $i;
		}
		return $len - 1;
	}
}
