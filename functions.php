<?php
function error(string $msg, int $code = 500):never { throw new PhloException($msg, $code); }
function esc($string):string { return htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function arr(...$array):array { return $array; }
function obj(...$data):obj { return new obj(...$data); }
function first(...$args):mixed { return current($args); }
function last(...$args):mixed { return end($args); }

function loop(iterable $data, Closure|array $cb, ?string $implode = null):mixed {
	$return = [];
	$isArray = is_array($cb);
	foreach ($data as $key => $value) $return[$key] = $isArray ? $cb[0]->{$cb[1]}($value, $key) : $cb($value, $key);
	return is_null($implode) ? $return : implode($implode, $return);
}

function files(string|array $paths, string $ext = '*.*'):array { return array_merge(...loop((array)$paths, fn($path) => glob("$path$ext") ?: [])); }
function dirs(string $path):array { return glob("$path*", GLOB_MARK | GLOB_ONLYDIR) ?: []; }
function json_write(string $file, $data, $flags = null):int|false { return file_put_contents($file, json_encode($data, $flags ?? jsonFlags), LOCK_EX); }
function json_read(string $file, ?bool $assoc = null):mixed { return json_decode((string)file_get_contents($file), $assoc) ?? error('Error reading '.esc($file)); }
function regex(string $pattern, string $subject, int $flags = 0, int $offset = 0):array { return preg_match($pattern, $subject, $match, $flags, $offset) ? $match : []; }
function regex_all(string $pattern, string $subject, int $flags = 0, int $offset = 0):array { return preg_match_all($pattern, $subject, $matches, $flags, $offset) ? $matches : []; }
function is_absolute_url(string $url):bool { return str_starts_with($url, 'http://') || str_starts_with($url, 'https://'); }
function shortpath(?string $file):string {
	if (!$file) return 'unknown';
	$clean = str_replace(bs, slash, $file);
	if (defined('php') && str_starts_with($clean, str_replace(bs, slash, php))) return 'php/'.basename($file);
	$appPath = defined('app') ? rtrim(str_replace(bs, slash, app), slash).slash : void;
	if ($appPath && str_starts_with($clean, $appPath)) $clean = ltrim(substr($clean, strlen($appPath)), slash);
	$parts = array_values(array_filter(explode(slash, trim($clean, slash)), 'strlen'));
	$n = count($parts);
	if ($n >= 2) return $parts[$n - 2].slash.$parts[$n - 1];
	return end($parts) ?: 'unknown';
}
function debug(?string $msg = null):mixed {
	if (!debug) return $msg;
	if (is_null($msg)) return phlo('res')->debug;
	phlo('res')->debug($msg);
	return $msg;
}
function duration(int $decimals = 5, bool $float = false):string|float {
	$d = microtime(true) - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
	if ($float) return round($d, $decimals);
	$label = rtrim(rtrim(sprintf("%.{$decimals}f", $d), '0'), dot).'s';
	if ($d > 0 && $d < .5) $label .= ' ('.round(1 / $d).'/s)';
	return $label;
}
function size_human(int $size, int $precision = 0):string {
	foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit){ if ($size / 1024 < 1) break; $size /= 1024; }
	return round($size, $precision).$unit;
}

function route(?string $method = null, string $path = void, ?bool $async = null, ?string $data = null, ?callable $cb = null):bool|obj {
	if (func_num_args() === 0) error('route() requires arguments; use app::route() for route chaining');
	$req = phlo('req');
	if ($method && $method !== $req->method && !($method === 'GET' && $req->method === 'HEAD')) return false;
	if (!is_null($async) && $async !== $req->async) return false;
	if ($data && phlo('payload')->objKeys !== explode(comma, $data)) return false;
	$parts  = array_values(array_filter(explode(slash, $req->path)));
	$cbArgs = [];
	$index  = -1;
	foreach (array_filter(explode(space, $path)) as $index => $item){
		$reqItem = $parts[$index] ?? null;
		if (str_starts_with($item, '$')){
			$item = substr($item, 1);
			if (str_ends_with($item, '=*')){
				$cbArgs[substr($item, 0, -2)] = implode(slash, array_slice($parts, $index));
				$index = count($parts) - 1;
				break;
			}
			elseif (str_ends_with($item, qm)){
				$item = substr($item, 0, -1);
				if ($reqItem && $item !== $reqItem) return false;
				$reqItem = $item === $reqItem;
			}
			elseif (str_contains($item, eq)){
				[$item, $default] = explode(eq, $item, 2);
				$default = $default ?: null;
			}
			elseif (is_null($reqItem)) return false;
			if (str_contains($item, dot) && ([$item, $length] = explode(dot, $item, 2)) && strlen($reqItem) != (int)$length) return false;
			if (str_contains($item, colon)){
				[$item, $list] = explode(colon, $item, 2);
				$list = explode(comma, $list);
				if ($reqItem && !in_array($reqItem, $list)) return false;
				$cbArgs[$item] = $reqItem ?: ($default ?? null);
			}
			else $cbArgs[$item] = $reqItem ?? ($default ?? null);
		}
		elseif ($item !== $reqItem) return false;
	}
	if (isset($parts[$index + 1])) return false;
	if (!$cb) return obj(...$cbArgs);
	if ($cb(...$cbArgs) === false) return false;
	return true;
}

function indent(string $string, int $depth = 1):string { return ($tab = str_repeat(tab, $depth)).rtrim(strtr($string, [lf => lf.$tab]), tab); }
function indentView(string $string, int $depth = 1):string { return last($tab = str_repeat(tab, $depth), rtrim(preg_replace('/\n(\t*)</', "\n$1$tab<", $string), tab)); }

function DOM(string $body = void, string $head = void, string $lang = 'en', string $bodyAttrs = void, string $htmlAttrs = void):string { return "<!DOCTYPE html>\n<html lang=\"$lang\"$htmlAttrs>\n<head>\n$head</head>\n<body$bodyAttrs>\n$body\n</body>\n</html>"; }

function title(?string $title = null, string $implode = ' - '):string {
	$res = phlo('res');
	if ($title) { $res->titles[] = $title; return $title; }
	return implode($implode, [...$res->titles, phlo('app')->title ?: 'Phlo '.phlo]);
}

function mime(string $filename):string { return ['html' => 'text/html', 'css' => 'text/css', 'gif' => 'image/gif', 'ico' => 'image/x-icon', 'ini' => 'text/plain', 'js' => 'application/javascript', 'json' => 'application/json', 'jpg' => 'image/jpeg', 'jpe' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jfif' => 'image/jpeg', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'pdf' => 'application/pdf', 'phlo' => 'application/phlo', 'php' => 'application/x-httpd-php', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'txt' => 'text/plain', 'webp' => 'image/webp', 'xml' => 'text/xml'][pathinfo($filename, PATHINFO_EXTENSION)] ?? (is_file($filename) ? mime_content_type($filename) : 'application/octet-stream'); }

function phlo_css(string $input, bool $compact = true):string {
	require_once __DIR__.'/classes/css.php';
	return build_css::decode($input, $compact);
}

function css_phlo(string $input):string {
	require_once __DIR__.'/classes/css.php';
	return build_css::encode($input);
}

function view(?string $body = null, ?string $title = null, array|string $css = [], array|string $js = [], array|string $defer = [], array|string $options = [], array $settings = [], ?string $ns = null, bool|string|null $path = null, bool $inline = false, string $bodyAttrs = void, string $htmlAttrs = void, ?int $code = null, ...$cmds):string {
	$req = phlo('req');
	$res = phlo('res');
	$prefix = trim($req->extra ?? void, slash);
	$async = $req->async || $res->streaming;
	if (!$req->method) return $body ?? void;
	$res->done && error('Output already started, invalid view()');
	is_null($path) && $path = $req->path;
	$asset = fn($item) => str_starts_with($item, slash) && $prefix && !str_starts_with($item, slash.$prefix.slash) && $item !== slash.$prefix ? slash.$prefix.$item : $item;
	!$async && !is_bool($path) && $path !== $req->path && location($asset(slash.trim($path, slash)));
	$app = phlo('app');
	$viewTitle = $title;
	$title ??= $app->title;
	$viewTitle && title($viewTitle);
	$css = array_merge((array)$css, (array)$app->css);
	$js = array_merge((array)$js, (array)$app->js);
	$defer = array_merge((array)$defer, (array)$app->defer);
	$options = implode(space, array_merge((array)$options, (array)$app->options, debug ? ['debug'] : []));
	$settings = array_merge($settings, (array)$app->settings);
	if ($async){
		$path !== false && $cmds['path'] = $path;
		$cmds['trans'] ??= $app->trans ?? true;
		$title && $cmds['title'] = title();
		$css && $cmds['css'] = $css;
		$js && $cmds['js'] = $js;
		$defer && $cmds['defer'] = $defer;
		$cmds['options'] = $options;
		$cmds['settings'] = $settings;
		!is_null($body) && $cmds['inner']['body'] = $body;
		return apply(...$cmds);
	}
	$body ??= $cmds['main'] ?? void;
	$version = $app->version ?? '.1';
	$ns ??= $app->ns ?? 'app';
	$link = $app->link ?: [];
	$nonce = $app->nonce ? ' nonce="'.$app->nonce.'"' : void;
	$head = '<title>'.esc(title()).'</title>'.lf;
	$head .= '<meta name="viewport" content="'.($cmds['viewport'] ?? $app->viewport ?? 'width=device-width').'">'.lf;
	$app->themeColor && $head .= "<meta name=\"theme-color\" content=\"$app->themeColor\">\n";
	$app->nonce && $head .= "<meta name=\"nonce\" content=\"$app->nonce\">\n";
	is_file(www.($filename = 'icons.png')) && $link[] = "</$filename?$version>; rel=preload; as=image";
	is_file(www.($filename = "$ns.css")) && $css = [$asset("/$filename"), ...$css];
	is_file(www.($filename = "$ns.js")) && $defer[] = $asset("/$filename");
	$app->head && $head .= $app->head.lf;
	is_file(www.($filename = 'favicon.ico')) && $head .= '<link rel="icon" href="'.esc($asset("/$filename")).qm.$version.'">'.lf;
	is_file(www.($filename = 'manifest.json')) && $head .= '<link rel="manifest" href="'.esc($asset("/$filename")).qm.$version.'">'.lf;
	foreach ($css as $item){
		$url = $asset($item);
		if ($inline && !is_absolute_url($item)) $head .= '<style'.$nonce.'>'.lf.file_get_contents(www.substr($item, 1)).lf.'</style>'.lf;
		else $head .= '<link rel="stylesheet" href="'.esc($url).qm.$version.'">'.lf;
		if (!$inline && !is_absolute_url($item)) $link[] = "<$url?$version>; rel=preload; as=style";
	}
	foreach ($js as $item){
		$url = $asset($item);
		if ($inline && !is_absolute_url($item)) $head .= '<script'.$nonce.'>'.lf.file_get_contents(www.substr($item, 1)).'</script>'.lf;
		else $head .= '<script src="'.esc($url).qm.$version.'"'.$nonce.'></script>'.lf;
		if (!$inline && !is_absolute_url($item)) $link[] = "<$url?$version>; rel=preload; as=script";
	}
	foreach ($defer as $item){
		$url = $asset($item);
		if ($inline && !is_absolute_url($item)) $body .= lf.'<script'.$nonce.'>'.lf.file_get_contents(www.substr($item, 1)).'</script>';
		else $head .= '<script src="'.esc($url).qm.$version.'" defer'.$nonce.'></script>'.lf;
		if (!$inline && !is_absolute_url($item)) $link[] = "<$url?$version>; rel=preload; as=script";
	}
	!build && $link && $res->header('Link', implode(comma, $link));
	debug && $body .= lf.debug_render(strlen($body));
	$options && $bodyAttrs .= " class=\"$options\"";
	$settings && $bodyAttrs .= loop($settings, fn($value, $key) => ' data-'.$key.'="'.esc($value).'"', void);
	$dom = DOM($body, $head, $cmds['lang'] ?? $app->lang ?? 'en', $bodyAttrs, $htmlAttrs);
	$code && $res->status = $code;
	$res->type = 'text/html';
	$res->body = $dom;
	return $dom;
}

function apply(...$cmds):string {
	$req = phlo('req');
	$res = phlo('res');
	$res->done && error('Output already started, invalid apply()');
	if (debug){
		$d = debug_collect();
		$d['dump'] && !isset($cmds['dump'])  && $cmds['dump']  = $d['dump'];
		$d['phlo'] && !isset($cmds['phlo'])  && $cmds['phlo']  = $d['phlo'];
		$dbg   = $d['debug'];
		$dbg[] = '['.$d['mem'].'] ['.$d['dur'].']';
		$cmds['debug'] = isset($cmds['debug']) ? [...(array)$cmds['debug'], ...$dbg] : $dbg;
	}
	$body = (string)json_encode($cmds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($req->cli){
		$res->outputted = true;
		$res->done = true;
		print($body.lf);
		return $body;
	}
	if ($res->streaming){
		print($body.lf);
		@ob_flush();
		flush();
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

function output(mixed $content = null, ?string $filename = null, ?bool $attachment = null, ?string $file = null, ?int $code = null, ?string $type = null):void {
	$req  = phlo('req');
	$res  = phlo('res');
	# Arrays are unambiguously JSON; objects only when application/json is explicitly requested (obj is both Stringable and JsonSerializable, so the type cannot be inferred safely).
	if (is_array($content) || ($type === 'application/json' && !is_string($content) && !is_null($content))){
		$content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$type ??= 'application/json';
	}
	$name = $filename ?? basename($file ?? $req->path);
	$body = $file ? (string)file_get_contents($file) : (string)$content;
	$res->type = $type ?? mime($name);
	$res->header('Content-Length', (string)($file ? filesize($file) : strlen($body)));
	if (is_bool($attachment) || $filename) $res->header('Content-Disposition', ($attachment ? 'attachment' : 'inline').';filename='.rawurlencode($name));
	$res->body = $body;
	$res->render($code);
}

function location(?string $url = null, ?int $code = null):string|bool {
	if (phlo('req')->async) return apply(location: $url ?? true);
	$res = phlo('res');
	$res->header('Location', $url ?? (phlo('req')->referer ?: slash));
	$res->render($code ?? 302);
	return true;
}
