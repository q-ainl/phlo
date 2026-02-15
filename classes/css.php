<?php
namespace phlo\tech;

class css {
	public static function decode(string $input, bool $compact = true):string {
		$input = \str_replace(["\r\n", "\r"], "\n", $input);
		$lines = \explode("\n", $input);
		$rules = [];
		$selectorStack = [];
		$atStack = [];
		$contextStack = [];
		$fontFaceIndex = 0;
		foreach ($lines AS $line){
			$trim = \trim($line);
			if ($trim === \phlo\void) continue;
			if (\str_starts_with($trim, '//')) continue;
			if (\str_starts_with($trim, '#') && isset($trim[1]) && ($trim[1] === ' ' || $trim[1] === "\t")) continue;
			if ($trim === '}'){
				$ctx = array_pop($contextStack);
				if ($ctx === 'selector') array_pop($selectorStack);
				elseif ($ctx === 'at') array_pop($atStack);
				continue;
			}
			if (\str_ends_with($trim, '{')){
				$head = \trim(\substr($trim, 0, -1));
				if ($head !== \phlo\void){
					if ($head[0] === '@'){
						$headLower = \strtolower($head);
						if (\str_starts_with($headLower, '@font-face')){
							$selectorStack[] = ['@font-face#'.(++$fontFaceIndex)];
							$contextStack[] = 'selector';
						}
						else {
							$atStack[] = $head;
							$contextStack[] = 'at';
						}
					}
					else {
						$selectorStack[] = self::split_selector($head);
						$contextStack[] = 'selector';
					}
				}
				continue;
			}
			$token = \phlo\colon.\phlo\space;
			[$left, $right] = self::split_first($trim, $token);
			$usedToken = $token;
			if ($right === null){
				[$left, $right] = self::split_first($trim, \phlo\colon);
				$usedToken = \phlo\colon;
			}
			if ($right === null) continue;
			$right = \trim($right);
			if (\str_contains($right, $token)){
				$selector = \trim($left);
				if ($selector !== \phlo\void && $selector[0] === '@'){
					[$selector, $rest] = self::split_first($right, $token);
					if (!\str_contains($rest ?? \phlo\void, $token)) continue;
					[$prop, $value] = self::split_first($rest, $token);
					self::add_rule($rules, array_merge($atStack, [\trim($left)]), $selectorStack, $selector, $prop, $value);
				}
				else {
					[$maybeSelector, $rest] = self::split_first($right, $token);
					if (\str_contains($rest ?? \phlo\void, $token)){
						[$prop, $value] = self::split_first($rest, $token);
						$parentStack = $selectorStack;
						$parentStack[] = self::split_selector($selector);
						self::add_rule($rules, $atStack, $parentStack, $maybeSelector, $prop, $value);
					}
					else {
						[$prop, $value] = self::split_first($right, $token);
						self::add_rule($rules, $atStack, $selectorStack, $selector, $prop, $value);
					}
				}
				continue;
			}
			self::add_rule($rules, $atStack, $selectorStack, null, $left, $right);
		}
		return self::render_rules($rules, $compact);
	}

	public static function encode(string $input):string {
		$rules = self::parse_css_rules($input);
		$output = [];
		foreach ($rules AS $atKey => $selectors){
			if ($atKey !== \phlo\void){
				$single = self::single_rule($selectors);
				if ($single){
					[$selector, $prop, $value] = $single;
					$output[] = $atKey.\phlo\colon.\phlo\space.$selector.\phlo\colon.\phlo\space.$prop.\phlo\colon.\phlo\space.$value;
					continue;
				}
				$output[] = $atKey.\phlo\space.'{';
			}
			foreach ($selectors AS $selector => $props){
				$props = self::reverse_props($props);
				if (\count($props) === 1 && !\str_contains($selector, \phlo\comma)){
					$line = $selector.\phlo\colon.\phlo\space.$props[0];
					$output[] = $atKey !== \phlo\void ? \phlo\tab.$line : $line;
					continue;
				}
				$selLines = self::selector_lines($selector, $atKey !== \phlo\void);
				$output = array_merge($output, $selLines);
				foreach ($props AS $propLine){
					$output[] = ($atKey !== \phlo\void ? \phlo\tab.\phlo\tab : \phlo\tab).$propLine;
				}
				$output[] = ($atKey !== \phlo\void ? \phlo\tab : \phlo\void).'}';
			}
			if ($atKey !== \phlo\void) $output[] = '}';
		}
		return \rtrim(\implode(\phlo\lf, $output));
	}

	private static function add_rule(array &$rules, array $atStack, array $selectorStack, ?string $selector, string $prop, string $value):void {
		$atKey = $atStack ? \implode(\phlo\space, $atStack) : \phlo\void;
		$prop = \trim($prop);
		$value = \trim($value);
		$selector = $selector !== null ? \trim($selector) : null;
		if ($selector && $selectorStack){
			$selector = \preg_replace('/(^|\\s|,)\\\\\\./', '$1&.', $selector);
			$selector = \preg_replace('/(^|\\s|,)\\\\#/', '$1&#', $selector);
		}
		$selector = $selector ? self::unescape_selector($selector) : null;
		$fullSelector = self::build_selector($selectorStack, $selector);
		if (!$fullSelector) return;
		if (\str_starts_with($prop, '$')) $prop = '--'.\substr($prop, 1);
		$value = \preg_replace('/\\$([A-Za-z_][A-Za-z0-9_-]*)/', 'var(--$1)', $value);
		$rules[$atKey][$fullSelector][] = [$prop, $value];
	}

	private static function render_rules(array $rules, bool $compact):string {
		$out = [];
		foreach ($rules AS $atKey => $selectors){
			$inAt = $atKey !== \phlo\void;
			if ($compact){
				if ($inAt) $out[] = $atKey.'{';
				foreach ($selectors AS $selector => $props){
					$selectorOut = \str_starts_with($selector, '@font-face#') ? '@font-face' : $selector;
					$rule = $selectorOut.'{';
					$last = \count($props) - 1;
					foreach ($props AS $i => $prop) $rule .= $prop[0].\phlo\colon.$prop[1].($i < $last ? \phlo\semi : \phlo\void);
					$out[] = $rule.'}';
				}
				if ($inAt) $out[\count($out) - 1] .= '}';
			}
			else {
				if ($inAt) $out[] = $atKey.'{';
				foreach ($selectors AS $selector => $props){
					$selectorOut = \str_starts_with($selector, '@font-face#') ? '@font-face' : $selector;
					$out = \array_merge($out, self::selector_lines($selectorOut, $inAt));
					$pad = $inAt ? \phlo\tab.\phlo\tab : \phlo\tab;
					foreach ($props AS $prop) $out[] = $pad.$prop[0].\phlo\colon.\phlo\space.$prop[1].\phlo\semi;
					$out[] = ($inAt ? \phlo\tab : \phlo\void).'}';
				}
				if ($inAt) $out[] = '}';
			}
		}
		return \rtrim(\implode(\phlo\lf, $out));
	}

	private static function build_selector(array $stack, ?string $child):?string {
		$selectors = [];
		if ($stack){
			$selectors = $stack[0];
			for ($i = 1; $i < \count($stack); $i++){
				$selectors = self::combine_selectors($selectors, $stack[$i]);
			}
		}
		if ($child !== null){
			$childParts = self::split_selector($child, $stack !== []);
			$selectors = $selectors ? self::combine_selectors($selectors, $childParts) : $childParts;
		}
		if (!$selectors) return null;
		return \implode(\phlo\comma.\phlo\space, $selectors);
	}

	private static function combine_selectors(array $parents, array $children):array {
		$out = [];
		foreach ($parents AS $parent){
			foreach ($children AS $child){
				if ($child === \phlo\void) continue;
				if ($child[0] === ':') $out[] = $parent.$child;
				elseif ($child[0] === '&') $out[] = \str_replace('&', $parent, $child);
				else $out[] = $parent.\phlo\space.$child;
			}
		}
		return $out;
	}

	private static function split_selector(string $selector, bool $hasParent = false):array {
		if ($hasParent){
			$selector = \preg_replace('/(^|\\s|,)\\\\\\./', '$1&.', $selector);
			$selector = \preg_replace('/(^|\\s|,)\\\\#/', '$1&#', $selector);
		}
		$selector = self::unescape_selector($selector);
		$parts = \array_map('trim', \explode(\phlo\comma, $selector));
		return \array_values(\array_filter($parts, fn($p) => $p !== \phlo\void));
	}

	private static function unescape_selector(string $selector):string {
		return \str_replace(['\\:', '\\.', '\\#'], [':', '.', '#'], $selector);
	}

	private static function split_first(string $line, string $token):array {
		$len = \strlen($line);
		$tlen = \strlen($token);
		$depth = 0;
		for ($i = 0; $i < $len; $i++){
			$c = $line[$i];
			if ($c === '(') $depth++;
			elseif ($c === ')' && $depth > 0) $depth--;
			if ($depth === 0 && \substr($line, $i, $tlen) === $token){
				return [\substr($line, 0, $i), \substr($line, $i + $tlen)];
			}
		}
		return [$line, null];
	}

	private static function parse_css_rules(string $input):array {
		$input = \preg_replace('#/\*.*?\*/#s', \phlo\void, $input);
		$rules = [];
		$atStack = [];
		$selectorStack = [];
		$contextStack = [];
		$buffer = \phlo\void;
		$lastPos = 0;
		$len = strlen($input);
		for ($i = 0; $i < $len; $i++){
			$char = $input[$i];
			if ($char === '{'){
				$selector = \trim(\substr($input, $lastPos, $i - $lastPos));
				if ($selector !== \phlo\void){
					if ($selector[0] === '@'){
						$atStack[] = $selector;
						$contextStack[] = 'at';
					}
					else {
						$selectorStack[] = $selector;
						$contextStack[] = 'selector';
					}
				}
				$lastPos = $i + 1;
			}
			elseif ($char === '}'){
				$block = \trim(\substr($input, $lastPos, $i - $lastPos));
				$ctx = array_pop($contextStack);
				if ($ctx === 'selector'){
					$selector = array_pop($selectorStack);
					$atKey = $atStack ? \implode(\phlo\space, $atStack) : \phlo\void;
					$props = self::parse_css_props($block);
					$rules[$atKey][$selector] = $props;
				}
				elseif ($ctx === 'at') array_pop($atStack);
				$lastPos = $i + 1;
			}
		}
		return $rules;
	}

	private static function parse_css_props(string $block):array {
		$props = [];
		foreach (\explode(\phlo\semi, $block) AS $chunk){
			$chunk = \trim($chunk);
			if ($chunk === \phlo\void) continue;
			[$prop, $value] = self::split_first($chunk, \phlo\colon);
			$prop = \trim($prop);
			$value = \trim($value);
			$props[] = [$prop, $value];
		}
		return $props;
	}

	private static function reverse_props(array $props):array {
		$out = [];
		foreach ($props AS $prop){
			$name = $prop[0];
			$value = $prop[1];
			if (\str_starts_with($name, '--')) $name = '$'.\substr($name, 2);
			$value = \preg_replace('/var\\(--([A-Za-z_][A-Za-z0-9_-]*)\\)/', '\\$$1', $value);
			$out[] = $name.\phlo\colon.\phlo\space.$value;
		}
		return $out;
	}

	private static function selector_lines(string $selector, bool $indent = false):array {
		$parts = \array_map('trim', \explode(\phlo\comma, $selector));
		if (\count($parts) === 1) return [($indent ? \phlo\tab : \phlo\void).$selector.\phlo\space.'{'];
		$lines = [];
		foreach ($parts AS $i => $part){
			$commaChar = $i < \count($parts) - 1 ? \phlo\comma : \phlo\void;
			$lines[] = ($indent ? \phlo\tab : \phlo\void).$part.$commaChar;
		}
		$lines[] = ($indent ? \phlo\tab : \phlo\void).'{';
		return $lines;
	}

	private static function single_rule(array $selectors):?array {
		if (\count($selectors) !== 1) return null;
		$selector = \array_key_first($selectors);
		if ($selector === null || \str_contains($selector, \phlo\comma)) return null;
		$props = $selectors[$selector];
		if (\count($props) !== 1) return null;
		$prop = $props[0][0];
		$value = $props[0][1];
		$prop = \str_starts_with($prop, '--') ? '$'.\substr($prop, 2) : $prop;
		$value = \preg_replace('/var\\(--([A-Za-z_][A-Za-z0-9_-]*)\\)/', '\\$$1', $value);
		return [$selector, $prop, $value];
	}
}
