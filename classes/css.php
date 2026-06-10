<?php

class build_css {
	public static function decode(string $input, bool $compact = true):string {
		$input = str_replace(["\r\n", "\r"], "\n", $input);
		$lines = self::merge_continuations(explode("\n", $input));
		$rules = [];
		$statements = [];
		$selectorStack = [];
		$atStack       = [];
		$contextStack  = [];
		$fontFaceIndex = 0;
		foreach ($lines as $line){
			$trim = trim($line);
			if ($trim === void) continue;
			if (str_starts_with($trim, '//')) continue;
			if (str_starts_with($trim, '#') && isset($trim[1]) && ($trim[1] === space || $trim[1] === "\t")) continue;
			if ($trim === '}'){
				$ctx = array_pop($contextStack);
				if ($ctx === 'selector') array_pop($selectorStack);
				elseif ($ctx === 'at')   array_pop($atStack);
				continue;
			}
			if (str_ends_with($trim, '{')){
				$head = trim(substr($trim, 0, -1));
				if ($head !== void){
					if ($head[0] === '@'){
						$headLower = strtolower($head);
						if (str_starts_with($headLower, '@font-face')){
							$selectorStack[] = ['@font-face#'.(++$fontFaceIndex)];
							$contextStack[]  = 'selector';
						}
						else {
							$atStack[]      = $head;
							$contextStack[] = 'at';
						}
					}
					else {
						$selectorStack[] = self::split_selector($head);
						$contextStack[]  = 'selector';
					}
				}
				continue;
			}
			$token = colon.space;
			[$left, $right] = self::split_first($trim, $token);
			if ($right === null) [$left, $right] = self::split_first($trim, colon);
			if ($right === null){
				if ($trim[0] === '@'){ $statements[] = rtrim($trim, semi).semi; continue; }
				if (str_starts_with($trim, '/*') && str_ends_with($trim, '*/')) continue;
				error('Build error: CSS line is not a declaration: "'.$trim.'" (values cannot wrap across lines)');
			}
			$right = trim($right);
			if (self::has_token($right, $token)){
				$selector = trim($left);
				if ($selector !== void && $selector[0] === '@'){
					[$selector, $rest] = self::split_first($right, $token);
					if (self::has_token($rest ?? void, $token)){
						[$prop, $value] = self::split_first($rest, $token);
						self::add_rule($rules, array_merge($atStack, [trim($left)]), $selectorStack, $selector, $prop, $value);
					}
					else {
						[$prop, $value] = self::split_first($right, $token);
						self::add_rule($rules, array_merge($atStack, [trim($left)]), $selectorStack, null, $prop, $value);
					}
				}
				else {
					[$maybeSelector, $rest] = self::split_first($right, $token);
					if (self::has_token($rest ?? void, $token)){
						[$prop, $value] = self::split_first($rest, $token);
						$parentStack   = $selectorStack;
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
		$css = self::render_rules($rules, $compact);
		if ($statements) $css = implode(lf, $statements).($css !== void ? lf.$css : void);
		return $css;
	}

	public static function encode(string $input):string {
		$rules  = self::parse_css_rules($input);
		$output = [];
		foreach ($rules as $atKey => $selectors){
			if ($atKey !== void){
				$single = self::single_rule($selectors);
				if ($single){
					[$selector, $prop, $value] = $single;
					$output[] = $atKey.colon.space.$selector.colon.space.$prop.colon.space.$value;
					continue;
				}
				$output[] = $atKey.space.'{';
			}
			foreach ($selectors as $selector => $props){
				$props = self::reverse_props($props);
				if (count($props) === 1 && !str_contains($selector, comma)){
					$line     = $selector.colon.space.$props[0];
					$output[] = $atKey !== void ? tab.$line : $line;
					continue;
				}
				$output = array_merge($output, self::selector_lines($selector, $atKey !== void));
				foreach ($props as $propLine) $output[] = ($atKey !== void ? tab.tab : tab).$propLine;
				$output[] = ($atKey !== void ? tab : void).'}';
			}
			if ($atKey !== void) $output[] = '}';
		}
		return rtrim(implode(lf, $output));
	}

	private static function add_rule(array &$rules, array $atStack, array $selectorStack, ?string $selector, string $prop, string $value):void {
		$atKey    = $atStack ? implode(space, $atStack) : void;
		$prop     = trim($prop);
		$value    = trim($value);
		$selector = $selector !== null ? trim($selector) : null;
		if ($selector && $selectorStack){
			$selector = preg_replace('/(^|\\s|,)\\\\\\./', '$1&.', $selector);
			$selector = preg_replace('/(^|\\s|,)\\\\#/', '$1&#', $selector);
		}
		$selector     = $selector ? self::unescape_selector($selector) : null;
		$fullSelector = self::build_selector($selectorStack, $selector);
		if (!$fullSelector) return;
		if (str_starts_with($prop, '$')) $prop = '--'.substr($prop, 1);
		$value = preg_replace('/\\$([A-Za-z_][A-Za-z0-9_-]*)/', 'var(--$1)', $value);
		$rules[$atKey][$fullSelector][] = [$prop, $value];
	}

	private static function render_rules(array $rules, bool $compact):string {
		$out = [];
		foreach ($rules as $atKey => $selectors){
			$inAt = $atKey !== void;
			if ($compact){
				if ($inAt) $out[] = $atKey.'{';
				foreach ($selectors as $selector => $props){
					$selectorOut = str_starts_with($selector, '@font-face#') ? '@font-face' : $selector;
					$rule = $selectorOut.'{';
					$last = count($props) - 1;
					foreach ($props as $i => $prop) $rule .= $prop[0].colon.$prop[1].($i < $last ? semi : void);
					$out[] = $rule.'}';
				}
				if ($inAt) $out[count($out) - 1] .= '}';
			}
			else {
				if ($inAt) $out[] = $atKey.'{';
				foreach ($selectors as $selector => $props){
					$selectorOut = str_starts_with($selector, '@font-face#') ? '@font-face' : $selector;
					$out = array_merge($out, self::selector_lines($selectorOut, $inAt));
					$pad = $inAt ? tab.tab : tab;
					foreach ($props as $prop) $out[] = $pad.$prop[0].colon.space.$prop[1].semi;
					$out[] = ($inAt ? tab : void).'}';
				}
				if ($inAt) $out[] = '}';
			}
		}
		return rtrim(implode(lf, $out));
	}

	private static function build_selector(array $stack, ?string $child):?string {
		$selectors = [];
		if ($stack){
			$selectors = $stack[0];
			for ($i = 1; $i < count($stack); ++$i) $selectors = self::combine_selectors($selectors, $stack[$i]);
		}
		if ($child !== null){
			$childParts = self::split_selector($child, $stack !== []);
			$selectors  = $selectors ? self::combine_selectors($selectors, $childParts) : $childParts;
		}
		if (!$selectors) return null;
		return implode(comma.space, $selectors);
	}

	private static function combine_selectors(array $parents, array $children):array {
		$out = [];
		foreach ($parents as $parent){
			foreach ($children as $child){
				if ($child === void) continue;
				if ($child[0] === ':')  $out[] = $parent.$child;
				elseif ($child[0] === '&') $out[] = str_replace('&', $parent, $child);
				else                    $out[] = $parent.space.$child;
			}
		}
		return $out;
	}

	private static function split_selector(string $selector, bool $hasParent = false):array {
		if ($hasParent){
			$selector = preg_replace('/(^|\\s|,)\\\\\\./', '$1&.', $selector);
			$selector = preg_replace('/(^|\\s|,)\\\\#/', '$1&#', $selector);
		}
		$selector = self::unescape_selector($selector);
		$parts    = array_map('trim', explode(comma, $selector));
		return array_values(array_filter($parts, static fn($p) => $p !== void));
	}

	private static function unescape_selector(string $selector):string {
		return str_replace(['\\:', '\\.', '\\#'], [':', '.', '#'], $selector);
	}

	private static function merge_continuations(array $lines):array {
		$out   = [];
		$carry = void;
		foreach ($lines as $line){
			if ($carry !== void){
				$next = ltrim($line);
				if (str_ends_with($carry, colon) && ($next === '}' || str_ends_with(rtrim($next), '{'))) $out[] = $carry;
				else $line = $carry.space.$next;
				$carry = void;
			}
			$body  = ltrim($line);
			$isComment = str_starts_with($body, '//') || ($body !== void && $body[0] === '#' && isset($body[1]) && ($body[1] === space || $body[1] === tab));
			$trimmed = rtrim($line);
			if (!$isComment && (str_ends_with($trimmed, comma) || str_ends_with($trimmed, colon))){ $carry = $trimmed; continue; }
			$out[] = $line;
		}
		if ($carry !== void) $out[] = $carry;
		return $out;
	}

	private static function has_token(string $line, string $token):bool {
		return self::split_first($line, $token)[1] !== null;
	}

	private static function split_first(string $line, string $token):array {
		$len   = strlen($line);
		$tlen  = strlen($token);
		$depth = 0;
		$quote = void;
		for ($i = 0; $i < $len; ++$i){
			$c = $line[$i];
			if ($quote !== void){
				if ($c === bs){ ++$i; continue; }
				if ($c === $quote) $quote = void;
				continue;
			}
			if ($c === dq || $c === sq){ $quote = $c; continue; }
			if ($c === '(') ++$depth;
			elseif ($c === ')' && $depth > 0) --$depth;
			if ($depth === 0 && substr($line, $i, $tlen) === $token) return [substr($line, 0, $i), substr($line, $i + $tlen)];
		}
		return [$line, null];
	}

	private static function parse_css_rules(string $input):array {
		$input         = preg_replace('#/\*.*?\*/#s', void, $input);
		$rules         = [];
		$atStack       = [];
		$selectorStack = [];
		$contextStack  = [];
		$lastPos       = 0;
		$len           = strlen($input);
		for ($i = 0; $i < $len; ++$i){
			$char = $input[$i];
			if ($char === '{'){
				$selector = trim(substr($input, $lastPos, $i - $lastPos));
				if ($selector !== void){
					if ($selector[0] === '@'){ $atStack[] = $selector; $contextStack[] = 'at'; }
					else { $selectorStack[] = $selector; $contextStack[] = 'selector'; }
				}
				$lastPos = $i + 1;
			}
			elseif ($char === '}'){
				$block = trim(substr($input, $lastPos, $i - $lastPos));
				$ctx   = array_pop($contextStack);
				if ($ctx === 'selector'){
					$selector = array_pop($selectorStack);
					$atKey    = $atStack ? implode(space, $atStack) : void;
					$rules[$atKey][$selector] = self::parse_css_props($block);
				}
				elseif ($ctx === 'at') array_pop($atStack);
				$lastPos = $i + 1;
			}
		}
		return $rules;
	}

	private static function parse_css_props(string $block):array {
		$props = [];
		foreach (explode(semi, $block) as $chunk){
			$chunk = trim($chunk);
			if ($chunk === void) continue;
			[$prop, $value] = self::split_first($chunk, colon);
			$props[] = [trim($prop), trim($value)];
		}
		return $props;
	}

	private static function reverse_props(array $props):array {
		$out = [];
		foreach ($props as $prop){
			$name  = $prop[0];
			$value = $prop[1];
			if (str_starts_with($name, '--')) $name = '$'.substr($name, 2);
			$value = preg_replace('/var\\(--([A-Za-z_][A-Za-z0-9_-]*)\\)/', '\\$$1', $value);
			$out[] = $name.colon.space.$value;
		}
		return $out;
	}

	private static function selector_lines(string $selector, bool $indent = false):array {
		$parts = array_map('trim', explode(comma, $selector));
		if (count($parts) === 1) return [($indent ? tab : void).$selector.space.'{'];
		$lines = [];
		foreach ($parts as $i => $part){
			$last = $i === count($parts) - 1;
			$lines[] = ($indent ? tab : void).$part.($last ? space.'{' : comma);
		}
		return $lines;
	}

	private static function single_rule(array $selectors):?array {
		if (count($selectors) !== 1) return null;
		$selector = array_key_first($selectors);
		if ($selector === null || str_contains($selector, comma)) return null;
		$props = $selectors[$selector];
		if (count($props) !== 1) return null;
		[$prop, $value] = $props[0];
		$prop  = str_starts_with($prop, '--') ? '$'.substr($prop, 2) : $prop;
		$value = preg_replace('/var\\(--([A-Za-z_][A-Za-z0-9_-]*)\\)/', '\\$$1', $value);
		return [$selector, $prop, $value];
	}
}
