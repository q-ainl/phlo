<?php
namespace phlo;

function apply(...$cmds):string {
	$res = phlo('res');
	$req = phlo('req');
	if (debug){
		$res->dump && $cmds['dump'] ??= $res->dump;
		$res->debug && $cmds['debug'] ??= $res->debug;
	}
	$body = \json_encode($cmds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($req->cli){
		$res->outputted = true;
		print($body.lf);
		return $body;
	}
	if ($res->streaming){
		print($body.lf);
		@\ob_flush();
		\flush();
		$res->outputted = true;
		return $body;
	}
	$res->type = 'application/json';
	$res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
	$res->header('Pragma', 'no-cache');
	$res->header('X-Content-Type-Options', 'nosniff');
	$res->json = $cmds;
	$res->body = $body.lf;
	$res->render();
	return $body;
}
function chunk(...$cmds):void {
	$res = phlo('res');
	$cli = phlo('req')->cli;
	if (debug){
		$res->dump && [$cmds['dump'] = $res->dump, $res->dump = []];
		$res->debug && [$cmds['debug'] = $res->debug, $res->debug = []];
	}
	if (!$res->streaming){
		$res->streaming = true;
		$res->type = 'text/event-stream';
		$res->header('Cache-Control', 'no-store');
		$res->header('X-Content-Type-Options', 'nosniff');
		$res->render(206);
	}
	print(\json_encode($cmds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).lf);
	$cli || [@\ob_flush(), \flush()];
}

function css_decode(string $input, bool $compact = true):string {
	require_once __DIR__.'/classes/css.php';
	return tech\css::decode($input, $compact);
}
function css_encode(string $input):string {
	require_once __DIR__.'/classes/css.php';
	return tech\css::encode($input);
}
function debug($msg){ debug && $msg && phlo('res')->debug($msg); }
function dirs(string $path):array|false { return \glob("$path*", GLOB_MARK | GLOB_ONLYDIR); }
function DOM(string $body = void, string $head = void, string $lang = 'en', string $bodyAttrs = void, string $htmlAttrs = void):string { return "<!DOCTYPE html>\n<html lang=\"$lang\"$htmlAttrs>\n<head>\n$head</head>\n<body$bodyAttrs>\n$body\n</body>\n</html>"; }
function duration(int $decimals = 5, bool $float = false):string|float { return last($d = \microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? \microtime(true)), $float ? \round($d, $decimals) : \rtrim(\rtrim(\sprintf("%.{$decimals}f", $d), '0'), dot).'s'.($d > 0 && $d < .5 ? ' ('.\round(1 / $d).'/s)' : void)); }
function error(string $msg, int $code = 500):never { throw new \RuntimeException($msg, $code); }
function esc($string):string { return \htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function files(string|array $paths, string $ext = '*.*'):array { return \array_merge(...loop((array)$paths, fn($path) => \glob("$path$ext"))); }
function first(...$args):mixed { return \current($args); }
function indent(string $string, int $depth = 1):string { return ($tab = \str_repeat(tab, $depth)).\rtrim(\strtr($string, [lf => lf.$tab]), tab); }
function indentView(string $string, int $depth = 1):string { return last($tab = \str_repeat(tab, $depth), \rtrim(\preg_replace('/\n(\t*)</', "\n$1$tab<", $string), tab)); }
function is_absolute_url(string $url):bool { return \str_starts_with($url, 'http://') || \str_starts_with($url, 'https://'); }
function last(...$args):mixed { return \end($args); }
function location(?string $location = null):string|bool {
	if (phlo('req')->async) return apply(location: $location ?? true);
	$res = phlo('res');
	$res->header('Location', $location ?? (phlo('req')->referer ?: slash));
	$res->render(302);
	return true;
}
function loop(iterable $data, \Closure|array $cb, ?string $implode = null):mixed {
	$return = [];
	$isArray = \is_array($cb);
	foreach ($data AS $key => $value) $return[$key] = $isArray ? $cb[0]->{$cb[1]}($value, $key) : $cb($value, $key);
	return \is_null($implode) ? $return : \implode($implode, $return);
}
function mime(string $filename):string { return ['html' => 'text/html', 'css' => 'text/css', 'gif' => 'image/gif', 'ico' => 'image/x-icon', 'ini' => 'text/plain', 'js' => 'application/javascript', 'json' => 'application/json', 'jpg' => 'image/jpeg', 'jpe' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jfif' => 'image/jpeg', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'pdf' => 'application/pdf', 'phlo' => 'application/phlo', 'php' => 'application/x-httpd-php', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'txt' => 'text/plain', 'webp' => 'image/webp', 'xml' => 'text/xml'][\pathinfo($filename, PATHINFO_EXTENSION)] ?? (\is_file($filename) ? \mime_content_type($filename) : 'application/octet-stream'); }
function output(?string $content = null, ?string $filename = null, ?bool $attachment = null, ?string $file = null):void {
	$path = phlo('req')->path;
	$res = phlo('res');
	$res->type = mime($filename ?? \basename($file ?? $path));
	$res->header('Content-Length', $file ? \filesize($file) : \strlen($content));
	if (\is_bool($attachment) || $filename) $res->header('Content-Disposition', ($attachment ? 'attachment' : 'inline').';filename='.\rawurlencode($filename ?? \basename($file ?? $path)));
	$res->body = $file ? null : $content;
	$res->render();
	$file && \readfile($file);
}
function phlo(?string $phloName = null, ...$args):mixed {
	static $list = [];
	if ($phloName === 'tech/reset') return \array_keys($list = \array_filter($list, fn($obj) => $obj->objPers));
	if (\is_null($phloName)) return \array_keys($list);
	$class = "phlo\\$phloName";
	$handle = \method_exists($class, '__handle') ? $class::__handle(...$args) : ($args ? null : $phloName);
	if ($handle === true){
		if (isset($list[$phloName])) return $list[$phloName]->objImport(...$args);
		$handle = $phloName;
	}
	elseif ($handle && isset($list[$handle])) return $list[$handle];
	$phlo = new $class(...$args);
	if ($handle) $list[$handle] = $phlo;
	if ($phlo->hasMethod('controller') && (!phlo('req')->cli || $phloName !== 'app')) $phlo->controller();
	return $phlo;
}
function req(int $index):?string { return \explode(slash, phlo('req')->path)[$index] ?? null; }
function route():bool {
	$req = phlo('req');
	$method = $req->method;
	$path = $req->path;
	$async = $req->async;
	$routes = \phlo\app::route();
	$static = $routes['static'] ?? [];
	if (isset($static[$method][$path])){
		foreach ($static[$method][$path] AS $route){
			$routeAsync = $route['a'];
			if ($routeAsync !== null && $routeAsync !== $async) continue;
			$cb = $route['c'];
			$result = \is_callable($cb) ? $cb() : null;
			if ($result !== false) return true;
		}
	}
	if (isset($static['BOTH'][$path])){
		foreach ($static['BOTH'][$path] AS $route){
			$routeAsync = $route['a'];
			if ($routeAsync !== null && $routeAsync !== $async) continue;
			$cb = $route['c'];
			$result = \is_callable($cb) ? $cb() : null;
			if ($result !== false) return true;
		}
	}
	$dynamic = $routes['dynamic'] ?? [];
	$parts = $path !== void ? \explode(slash, $path) : [];
	$partsCount = \count($parts);
	$tryMethods = [$method];
	$method !== 'BOTH' && $tryMethods[] = 'BOTH';
	foreach ($tryMethods AS $tryMethod){
		if (!isset($dynamic[$tryMethod])) continue;
		foreach ($dynamic[$tryMethod] AS $route){
			$routeAsync = $route['a'];
			if ($routeAsync !== null && $routeAsync !== $async) continue;
			$segments = $route['s'];
			$params = [];
			$matched = true;
			$partIndex = 0;
			foreach ($segments AS $i => $seg){
				if (\is_string($seg)){
					if (!isset($parts[$partIndex]) || $parts[$partIndex] !== $seg){
						$matched = false;
						break;
					}
					$partIndex++;
					continue;
				}
				$name = $seg['n'];
				$splat = $seg['s'] ?? false;
				$optional = $seg['o'] ?? false;
				$hasDefault = \array_key_exists('d', $seg);
				$default = $seg['d'] ?? null;
				$length = $seg['l'] ?? null;
				$validValues = $seg['v'] ?? null;
				if ($splat){
					$params[$name] = \array_slice($parts, $partIndex);
					$partIndex = $partsCount;
					continue;
				}
				if (!isset($parts[$partIndex])){
					if ($optional || $hasDefault){
						$params[$name] = $default;
						continue;
					}
					$matched = false;
					break;
				}
				$value = $parts[$partIndex];
				if ($length !== null && \strlen($value) != $length){
					$matched = false;
					break;
				}
				if ($validValues !== null && !\in_array($value, $validValues, true)){
					$matched = false;
					break;
				}
				$params[$name] = $value;
				$partIndex++;
			}
			if (!$matched) continue;
			if ($partIndex < $partsCount) continue;
			$cb = $route['c'];
			$result = \is_callable($cb) ? $cb(...\array_values($params)) : null;
			if ($result !== false) return true;
		}
	}
	return false;
}
function size_human(int $size, int $precision = 0):string {
	foreach (['B', 'KB', 'MB', 'GB', 'TB'] AS $range){
		if ($size / 1024 < 1) break;
		$size /= 1024;
	}
	return \round($size, $precision).$range;
}
function view(?string $body = null, ?string $title = null, array|string $css = [], array|string $js = [], array|string $defer = [], array|string $options = [], array $settings = [], ?string $ns = null, bool|string|null $path = null, bool $inline = false, string $bodyAttrs = void, string $htmlAttrs = void, ...$cmds):string {
	$req = phlo('req');
	$res = phlo('res');
	$async = $req->async || $res->streaming;
	if (!$req->method) return $body ?? void;
	\is_null($path) && $path = $req->path;
	!$async && !\is_bool($path) && $path !== $req->path && location("/$path");
	static $inlineCache = [];
	static $fileCache = [];
	$app = phlo('app');
	$title ??= $app->title;
	$css = \array_merge((array)$css, (array)$app->css);
	$js = \array_merge((array)$js, (array)$app->js);
	$defer = \array_merge((array)$defer, (array)$app->defer);
	$options = \implode(space, \array_merge((array)$options, (array)$app->options, debug ? ['debug'] : []));
	$settings = \array_merge($settings, (array)$app->settings);
	if ($async){
		$path !== false && $cmds['path'] = $path;
		$cmds['trans'] ??= $app->trans ?? true;
		$title && $cmds['title'] = $title;
		$css && $cmds['css'] = $css;
		$js && $cmds['js'] = $js;
		$defer && $cmds['defer'] = $defer;
		$cmds['options'] = $options;
		$cmds['settings'] = $settings;
		!\is_null($body) && $cmds['inner']['body'] = $body;
		return apply(...$cmds);
	}
	$body ??= $cmds['main'] ?? void;
	$version = $app->version ?? '.1';
	$ns ??= $app->ns ?? 'app';
	$link = $app->link ?: [];
	$nonce = $app->nonce ? ' nonce="'.$app->nonce.'"' : void;
	$head = '<title>'.esc($title).'</title>'.lf;
	$head .= '<meta name="viewport" content="'.($cmds['viewport'] ?? $app->viewport ?? 'width=device-width').'">'.lf;
	$app->description && $head .= "<meta name=\"description\" content=\"$app->description\">\n";
	$app->themeColor && $head .= "<meta name=\"theme-color\" content=\"$app->themeColor\">\n";
	$app->nonce && $head .= "<meta name=\"nonce\" content=\"$app->nonce\">\n";
	($fileCache[$filename = 'icons.png'] ??= \is_file(www.$filename)) && $link[] = "</$filename?$version>; rel=preload; as=image";
	($fileCache[$filename = "$ns.css"] ??= \is_file(www.$filename)) && $css[] = "/$filename";
	($fileCache[$filename = "$ns.js"]  ??= \is_file(www.$filename)) && $defer[] = "/$filename";
	$app->head && $head .= $app->head.lf;
	($fileCache[$filename = 'favicon.ico'] ??= \is_file(www.$filename)) && $head .= "<link rel=\"icon\" href=\"/$filename?$version\">\n";
	($fileCache[$filename = 'manifest.json'] ??= \is_file(www.$filename)) && $head .= "<link rel=\"manifest\" href=\"/$filename?$version\">\n";
	foreach ($css AS $item){
		if ($inline && !is_absolute_url($item)){
			$file = www.\substr($item, 1);
			$inlineCache[$file] ??= \file_get_contents($file);
			$head .= '<style'.$nonce.'>'.lf.$inlineCache[$file].lf.'</style>'.lf;
		}
		else $head .= '<link rel="stylesheet" href="'.esc($item).qm.$version.'">'.lf;
		if (!$inline && !is_absolute_url($item)) $link[] = "<$item?$version>; rel=preload; as=style";
	}
	foreach ($js AS $item){
		if ($inline && !is_absolute_url($item)){
			$file = www.\substr($item, 1);
			$inlineCache[$file] ??= \file_get_contents($file);
			$head .= '<script'.$nonce.'>'.lf.$inlineCache[$file].'</script>'.lf;
		}
		else $head .= '<script src="'.esc($item).qm.$version.'"'.$nonce.'></script>'.lf;
		if (!$inline && !is_absolute_url($item)) $link[] = "<$item?$version>; rel=preload; as=script";
	}
	foreach ($defer AS $item){
		if ($inline && !is_absolute_url($item)){
			$file = www.\substr($item, 1);
			$inlineCache[$file] ??= \file_get_contents($file);
			$body .= lf.'<script'.$nonce.'>'.lf.$inlineCache[$file].'</script>';
		}
		else $head .= '<script src="'.esc($item).qm.$version.'" defer'.$nonce.'></script>'.lf;
		if (!$inline && !is_absolute_url($item)) $link[] = "<$item?$version>; rel=preload; as=script";
	}
	!build && $link && $res->header('Link', \implode(comma, $link));
	debug && $body .= lf.debug_render();
	$options && $bodyAttrs .= " class=\"$options\"";
	$settings && $bodyAttrs .= loop($settings, fn($value, $key) => ' data-'.$key.'="'.esc($value).'"', void);
	$dom = DOM($body, $head, $cmds['lang'] ?? $app->lang ?? 'en', $bodyAttrs, $htmlAttrs);
	$res->type = 'text/html';
	$res->body = $dom;
	return $dom;
}
