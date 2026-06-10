<?php

function phlo_error_handle(Throwable $e):void {
	$req  = phlo('req');
	$res  = phlo('res');
	$code = (int)$e->getCode() ?: 500;
	if ($code < 100 || $code > 599) $code = 500;
	[$file, $line] = phlo_error_origin($e);
	$message = $e->getMessage();
	$type    = get_class($e);
	$source  = phlo_error_sourcemap($file, $line);
	$srcFile = $source['file'] ?? $file;
	$srcLine = $source['line'] ?? $line;
	phlo_error_log(shortpath($srcFile).colon.$srcLine, $message);
	if ($req->cli || $req->async || $res->streaming){
		$error = (debug)
			? $type.":\n".$message."\n\nFile:\n".shortpath($srcFile).':'.$srcLine
			: 'Error';
		$cmds = ['error' => $error];
		if ($res->dump)  $cmds['dump']  = $res->dump;
		if ($res->debug) $cmds['debug'] = $res->debug;
		if ($req->cli){
			$payload = json_encode($cmds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			@fwrite(STDERR, ($payload ?: '{"error":"Error"}').lf);
			$res->outputted = true;
			exit($code > 0 && $code < 256 ? $code : 1);
		}
		apply(...$cmds);
		return;
	}
	$html = debug
		? phlo_error_render_debug($type, $message, $code, $srcFile, $srcLine, $e->getTrace())
		: phlo_error_render_minimal($code);
	$res->type = 'text/html; charset=UTF-8';
	$res->body = $html;
	$res->render($code);
}

function phlo_error_log(string $path, string $msg):int|false {
	$file = data.'errors.json';
	$now  = date('j-n-Y G:i:s');
	$id   = md5($path.preg_replace('/\s+/', void, trim(preg_replace('~(?:[A-Za-z]:)?[\\/](?:[^\s:/\\\\]+[\\/])*(?:([^\s:/\\\\]+\.[A-Za-z0-9]{1,8})|[^\s:/\\\\]+)(?::\d+)?~', '$1', $msg))));
	$map  = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
	$row  = $map[$id] ?? [];
	$row['file']        = $path;
	$row['path']        = phlo('req')->path;
	$row['msg']         = $msg;
	$row['count']       = ($map[$id]['count'] ?? 0) + 1;
	$row['lastOccurred'] = $now;
	$row['build']       = build ? 1 : 0;
	unset($row['lastOccured']);
	unset($map[$id]);
	$map = [...[$id => $row], ...$map];
	return json_write($file, $map);
}

function phlo_error_sourcemap(string $phpFile, int $phpLine):?array {
	if (!is_file($mapFile = php.'sourcemap.php')) return null;
	static $map = null, $mtime = null;
	$time = filemtime($mapFile);
	if ($map === null || $mtime !== $time){
		$map   = require $mapFile;
		$mtime = $time;
	}
	if (!isset($map[$phpFile])) return null;
	$entry = $map[$phpFile];
	$source = $entry['source'] ?? null;
	$best   = null;
	foreach ($entry['map'] ?? [] as $row){
		if (($row['php'] ?? 0) <= $phpLine && (!$best || $row['php'] > $best['php'])) $best = $row;
	}
	if ($best){
		$source  = $best['source'] ?? $source;
		$phpLine = $best['phlo'] + ($phpLine - $best['php']);
	}
	if (!$source) return null;
	return ['file' => $source, 'line' => $phpLine, 'name' => $best['name'] ?? null];
}

function phlo_error_head(string $title):string { return "<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>$title</title>\n<style>".phlo_error_css()."</style>\n"; }
function phlo_error_foot():string { return "<footer class=\"foot\"><span>Phlo ".phlo."</span><span>Memory: ".size_human(memory_get_peak_usage())."</span><span>Time: ".duration(4)."</span></footer>"; }

function phlo_error_render_minimal(int $code):string {
	return DOM("<main class=\"wrap\"><header class=\"hero\"><div class=\"badge\">Error</div><h1>Error $code</h1><p>An unexpected error occurred.</p></header></main>", phlo_error_head("Error $code"));
}

function phlo_error_render_debug(string $type, string $message, int $code, string $file, int $line, array $trace):string {
	$fileEsc   = phlo_error_location_html($file, $line);
	$srcHtml   = void;
	foreach (phlo_error_source_context($file, $line) as $ctx){
		$active   = $ctx['active'] ? ' active' : void;
		$srcHtml .= '<tr class="row'.$active.'"><td class="num">'.$ctx['num'].'</td><td class="code">'.phlo_error_highlight($ctx['code'], $file).'</td></tr>';
	}
	$traceHtml = void;
	foreach (phlo_error_format_trace($trace, $file, $line) as $frame){
		$loc       = phlo_error_location_html($frame['file'], $frame['line']);
		$call      = esc($frame['call'] ?? void);
		$traceHtml .= '<tr class="row"><td class="loc">'.$loc.'</td><td class="call">'.$call.'</td></tr>';
	}
	$body = "<main class=\"wrap\">"
		."<header class=\"hero\"><div class=\"badge\">".esc($type)."</div><h1>".esc($message)."</h1><p>$fileEsc</p></header>"
		."<section class=\"grid\">"
		."<article class=\"panel\"><h2>Trace</h2><table><tbody>$traceHtml</tbody></table></article>"
		."<article class=\"panel\"><h2>Origin</h2><table><tbody>$srcHtml</tbody></table></article>"
		."</section>"
		.phlo_error_foot()
		."</main>";
	return DOM($body, phlo_error_head('Phlo '.$code.' Error'));
}

function phlo_error_location_html(string $file, int $line):string {
	$label = esc(shortpath($file).':'.$line);
	$url = phlo_error_dashboard_url($file, $line);
	return $url ? '<a class="file-link" href="'.esc($url).'">'.$label.'</a>' : $label;
}

function phlo_error_dashboard_url(string $file, int $line):?string {
	if (!defined('control') || !is_string(control) || !control) return null;
	$base   = slash.control;
	$full   = phlo_error_resolve_file($file);
	if (!$full) return null;
	$clean  = str_replace(bs, slash, $full);
	$anchor = '#L'.$line;

	if (str_ends_with($clean, '.phlo')){
		$key = phlo_error_source_key($clean);
		if ($key === null) return null;
		$mode = phlo_error_source_mode($clean, $key);
		return $base.'/source/'.rawurlencode($key).($mode !== 'app' ? '?mode='.$mode : void).$anchor;
	}

	$target = phlo_error_build_target($clean);
	if ($target !== null) return $base.$target.$anchor;

	return null;
}

function phlo_error_source_key(string $file):?string {
	$clean = str_replace(bs, slash, $file);
	if (defined('app')){
		$appPath = rtrim(str_replace(bs, slash, app), slash).slash;
		if (str_starts_with($clean, $appPath) && str_ends_with($clean, '.phlo'))
			return ltrim(substr($clean, strlen($appPath)), slash);
	}
	if (defined('engine')){
		$resPath = rtrim(str_replace(bs, slash, engine), slash).'/resources/';
		if (str_starts_with($clean, $resPath) && str_ends_with($clean, '.phlo'))
			return ltrim(substr($clean, strlen($resPath)), slash);
	}
	return null;
}

function phlo_error_source_mode(string $file, string $key):string {
	$clean = str_replace(bs, slash, $file);
	if (defined('app') && str_starts_with($clean, rtrim(str_replace(bs, slash, app), slash).slash)) return 'app';
	try { $config = class_exists('build_base') ? build_base::config() : []; }
	catch (Throwable) { $config = []; }
	return in_array(substr($key, 0, -5), $config['resources'] ?? [], true) ? 'resources' : 'available';
}

function phlo_error_build_target(string $file):?string {
	if (!class_exists('build')) return null;
	try {
		$sections = ['build' => build::buildFiles(), 'release' => build::releaseFiles()];
		foreach ($sections as $section => $groups){
			foreach ($groups as $prefix => $files){
				foreach ($files as $candidate){
					$candidate = str_replace(bs, slash, $candidate);
					if ($candidate === $file) return '/'.$section.'/view/'.rawurlencode($prefix.'/'.basename($candidate));
				}
			}
		}
	}
	catch (Throwable) {}
	return null;
}

function phlo_error_resolve_file(string $file):?string {
	$file = str_replace(bs, slash, $file);
	if (is_file($file)) return $file;
	if (str_starts_with($file, 'phlo/resources/') && defined('engine')){
		$try = rtrim(str_replace(bs, slash, engine), slash).slash.substr($file, 5);
		if (is_file($try)) return $try;
	}
	if (str_starts_with($file, 'resources/') && defined('engine')){
		$try = rtrim(str_replace(bs, slash, engine), slash).slash.$file;
		if (is_file($try)) return $try;
	}
	foreach (['app', 'php', 'www', 'engine'] as $const){
		if (!defined($const)) continue;
		$base = rtrim(str_replace(bs, slash, constant($const)), slash).slash;
		foreach ([$file, basename($file)] as $rel){
			$try = $base.ltrim($rel, slash);
			if (is_file($try)) return $try;
		}
	}
	return null;
}

function phlo_error_highlight(string $code, string $file):string {
	$parts = preg_split('/("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|%[A-Za-z_]\\w*|\\$[A-Za-z_]\\w*|\\b\\d+(?:\\.\\d+)?\\b|=>|::|->|[()\\[\\]{};,.=:+*\\/|&<>?-]|\\b(?:route|view|method|prop|readonly|const|static|function|if|else|elseif|foreach|return|new|true|false|null)\\b)/u', $code, -1, PREG_SPLIT_DELIM_CAPTURE);
	$out = void;
	foreach ($parts ?: [] as $part){
		if ($part === void) continue;
		$class = null;
		if ($part[0] === '"' || $part[0] === "'") $class = 'hl-string';
		elseif ($part[0] === '$') $class = 'hl-var';
		elseif ($part[0] === '%') $class = 'hl-obj';
		elseif (preg_match('/^\\d/', $part)) $class = 'hl-number';
		elseif (preg_match('/^(route|view|method|prop|readonly|const|static|function)$/', $part)) $class = 'hl-node';
		elseif (preg_match('/^(if|else|elseif|foreach|return|new|true|false|null)$/', $part)) $class = 'hl-key';
		elseif (preg_match('/^(=>|::|->|[()\\[\\]{};,.=:+*\\/|&<>?-])$/', $part)) $class = 'hl-operator';
		$out .= $class ? '<span class="'.$class.'">'.esc($part).'</span>' : esc($part);
	}
	return $out;
}

// True when a path is inside the engine itself (bootstrap/helpers), as opposed to
// compiled app/resource code under php/. Used to skip engine frames in traces.
function phlo_error_is_engine(string $file):bool {
	static $eng = null;
	if ($eng === null) $eng = defined('engine') ? rtrim(str_replace(bs, slash, engine), slash).slash : false;
	return $eng !== false && str_starts_with(str_replace(bs, slash, $file), $eng);
}

// Real origin of an error: error() throws inside the engine (functions.php), so the
// exception's own file/line points at the helper. Walk to the first non-engine frame.
function phlo_error_origin(Throwable $e):array {
	if (!phlo_error_is_engine($e->getFile())) return [$e->getFile(), $e->getLine()];
	foreach ($e->getTrace() as $frame){
		if (isset($frame['file'], $frame['line']) && !phlo_error_is_engine($frame['file'])) return [$frame['file'], $frame['line']];
	}
	return [$e->getFile(), $e->getLine()];
}

function phlo_error_format_trace(array $trace, string $file, int $line):array {
	$frames = [];
	foreach ($trace as $frame){
		if (!isset($frame['file'], $frame['line'])) continue;
		if (phlo_error_is_engine($frame['file'])) continue;
		$source = phlo_error_sourcemap($frame['file'], $frame['line']);
		$call   = $frame['function'] ?? void;
		isset($frame['class']) && $call = $frame['class'].($frame['type'] ?? '::').$call;
		$frames[] = [
			'file' => $source['file'] ?? $frame['file'],
			'line' => $source['line'] ?? $frame['line'],
			'call' => $call,
		];
	}
	// Ensure the real origin heads the trace (prepend only if not already first).
	if (!$frames || $frames[0]['file'] !== $file || $frames[0]['line'] !== $line)
		array_unshift($frames, ['file' => $file, 'line' => $line, 'call' => null]);
	return $frames;
}

function phlo_error_source_context(string $file, int $line, int $context = 3):array {
	if (!is_file($file)) return [];
	$lines = @file($file) ?: [];
	$start = max(0, $line - $context - 1);
	$end   = min(count($lines), $line + $context);
	$out   = [];
	for ($i = $start; $i < $end; ++$i){
		$out[] = ['num' => $i + 1, 'code' => rtrim($lines[$i] ?? void, "\r\n"), 'active' => $i + 1 === $line];
	}
	return $out;
}

function phlo_error_css():string {
	static $css = null;
	if ($css !== null) return $css;
	$path = defined('engine') ? engine.'assets/error.css' : __DIR__.'/assets/error.css';
	$extra = 'a.file-link{color:#bef264;text-decoration:none;border-bottom:1px dotted #bef264}a.file-link:hover{opacity:.9}'
		.'.hl-string{color:#c5e388}.hl-var{color:#82ff9f}.hl-obj{color:#ffd100}.hl-number{color:#67e8f9}'
		.'.hl-node{color:#bef264}.hl-key{color:#c084fc}.hl-operator{color:#ff8bd6}';
	return $css = (is_file($path) ? (string)file_get_contents($path) : void).$extra;
}
