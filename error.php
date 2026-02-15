<?php
namespace phlo\tech;

function handle_exception(\Throwable $e):void {
	if ($e->getMessage() === 'PhloDump') return;
	$req = \phlo\phlo('req');
	$res = \phlo\phlo('res');
	$code = (int)$e->getCode() ?: 500;
	$code < 100 && $code = 500;
	$file = $e->getFile();
	$line = $e->getLine();
	$message = $e->getMessage();
	$class = \get_class($e);
	$trace = $e->getTrace();
	$source = sourcemap_lookup($file, $line);
	$sourceFile = $source['file'] ?? $file;
	$sourceLine = $source['line'] ?? $line;
	if ($req->async || $req->cli || $res->streaming){
		$error = $e instanceof \Exception ? 'PhloException' : 'PhloError';
		if (\phlo\debug){
			$error = "$class:\n$message\n\nFile:\n".shortpath($sourceFile).":$sourceLine";
			$codeLine = @\file($sourceFile)[$sourceLine - 1] ?? null;
			if ($codeLine) $error .= "\n\nLine:\n".\trim($codeLine);
		}
		$cmds = ['error' => $error];
		$res->dump && $cmds['dump'] = $res->dump;
		$res->debug && $cmds['debug'] = $res->debug;
		\phlo\apply(...$cmds);
		return;
	}
	if (\phlo\debug) $html = render_error_page($class, $message, $code, $sourceFile, $sourceLine, $trace);
	else $html = render_error_minimal($code);
	$res->type = 'text/html';
	$res->body = $html;
	$res->render($code);
}

function handle_error(int $level, string $message, string $file, int $line):bool {
	if (!(\error_reporting() & $level)) return false;
	throw new \ErrorException($message, 0, $level, $file, $line);
}

function sourcemap_lookup(string $phpFile, int $phpLine):?array {
	if (!\is_file($mapFile = \phlo\php.'sourcemap.php')) return null;
	static $map;
	$map ??= require $mapFile;
	if (!isset($map[$phpFile])) return null;
	$entry = $map[$phpFile];
	$source = $entry['source'] ?? null;
	if (!$source) return null;
	$lineMap = $entry['map'] ?? [];
	$phloLine = $phpLine;
	$bestMatch = null;
	foreach ($lineMap AS $mapping){
		if (($mapping['php'] ?? 0) <= $phpLine){
			if (!$bestMatch || $mapping['php'] > $bestMatch['php']) $bestMatch = $mapping;
		}
	}
	if ($bestMatch) $phloLine = $bestMatch['phlo'] + ($phpLine - $bestMatch['php']);
	return ['file' => $source, 'line' => $phloLine, 'name' => $bestMatch['name'] ?? null];
}

function render_error_page(string $type, string $message, int $code, string $file, int $line, array $trace):string {
	$frames = format_trace($trace, $file, $line);
	$sourceContext = get_source_context($file, $line);
	$css = error_css();
	$memory = \phlo\size_human(\memory_get_peak_usage());
	$time = \phlo\duration(4);
	$version = version;
	$shortFile = shortpath($file);
	$traceHtml = \phlo\void;
	foreach ($frames AS $frame){
		$loc = \phlo\esc($frame['file'].':'.$frame['line']);
		$call = \phlo\esc($frame['call'] ?? \phlo\void);
		$context = \phlo\esc($frame['context'] ?? \phlo\void);
		$active = ($frame['file'] === $file && (int)$frame['line'] === $line) ? ' active' : \phlo\void;
		$traceHtml .= "<tr class=\"trace-row$active\"><td class=\"trace-loc\">$loc</td><td class=\"trace-call\">$call</td><td class=\"trace-code\">$context</td></tr>";
	}
	$sourceHtml = \phlo\void;
	foreach ($sourceContext AS $ctx){
		$num = (int)$ctx['num'];
		$code_line = \phlo\esc($ctx['code']);
		$active = $ctx['active'] ? ' active' : \phlo\void;
		$sourceHtml .= "<tr class=\"trace-row$active\"><td class=\"trace-loc".($ctx['active'] ? ' active-num' : \phlo\void)."\" style=\"text-align:right;\">$num</td><td class=\"trace-code".($ctx['active'] ? ' active-code' : \phlo\void)."\">$code_line</td></tr>";
	}
	$typeEsc = \phlo\esc($type);
	$msgEsc = \phlo\esc($message);
	$fileEsc = \phlo\esc($shortFile);
	$title = "Phlo $code Error";
	$head = "<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>$title</title>\n<style>$css</style>\n";
	$body = "<div class=\"viewport-center\">\n<main class=\"island\">\n"
		."<header class=\"error-header\">\n<span class=\"error-badge\">$typeEsc</span>\n<h1 class=\"error-title\">$msgEsc</h1>\n<p class=\"error-subtitle\">$fileEsc:<span class=\"highlight-red\">$line</span></p>\n</header>\n"
		."<div class=\"debug-grid\">\n"
		."<section class=\"section-source\">\n<div class=\"section-header\"><h2>Origin</h2></div>\n"
		."<div class=\"trace-table-wrapper\"><table class=\"trace-table\">\n<thead><tr><th style=\"width:40px;text-align:right;\">#</th><th>$fileEsc:<span class=\"highlight-red\">$line</span></th></tr></thead>\n<tbody>$sourceHtml</tbody>\n</table></div>\n</section>\n"
		."<section class=\"section-backtrace\">\n<div class=\"section-header\"><h2>Backtrace</h2></div>\n"
		."<div class=\"trace-table-wrapper\"><table class=\"trace-table\">\n<thead><tr><th>File:Line</th><th>Call</th><th>Context</th></tr></thead>\n<tbody>$traceHtml</tbody>\n</table></div>\n</section>\n"
		."</div>\n"
		."<footer class=\"island-footer\"><span>Phlo $version</span><span>Memory: $memory</span><span>Time: $time</span></footer>\n"
		."</main>\n</div>";
	return \phlo\DOM($body, $head);
}

function render_error_minimal(int $code):string {
	$css = error_css();
	$head = "<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>Error $code</title>\n<style>$css</style>\n";
	$body = "<div class=\"viewport-center\">\n<main class=\"island\">\n"
		."<header class=\"error-header\">\n<h1 class=\"error-title\">Error $code</h1>\n<p class=\"error-subtitle\">An unexpected error occurred.</p>\n</header>\n"
		."</main>\n</div>";
	return \phlo\DOM($body, $head);
}

function format_trace(array $trace, string $errorFile, int $errorLine):array {
	$frames = [['file' => $errorFile, 'line' => $errorLine, 'call' => 'error', 'context' => \phlo\void]];
	foreach ($trace AS $frame){
		$file = $frame['file'] ?? null;
		$line = $frame['line'] ?? null;
		if (!$file || !$line) continue;
		$source = sourcemap_lookup($file, $line);
		$call = $frame['function'] ?? null;
		if (isset($frame['class'])) $call = $frame['class'].($frame['type'] ?? '::').$call;
		$frames[] = [
			'file' => $source['file'] ?? $file,
			'line' => $source['line'] ?? $line,
			'call' => $source['name'] ?? $call,
			'context' => $call ? "$call()" : \phlo\void,
		];
	}
	return $frames;
}

function get_source_context(string $file, int $line, int $context = 3):array {
	if (!\is_file($file)) return [];
	$lines = @\file($file) ?: [];
	$start = \max(0, $line - $context - 1);
	$end = \min(\count($lines), $line + $context);
	$result = [];
	for ($i = $start; $i < $end; $i++){
		$result[] = ['num' => $i + 1, 'code' => \rtrim($lines[$i] ?? \phlo\void, "\r\n"), 'active' => ($i + 1) === $line];
	}
	return $result;
}

function shortpath(string $path):string {
	if (\str_starts_with($path, \phlo\app)) return \substr($path, \strlen(\phlo\app));
	if (\str_starts_with($path, \phlo\php)) return 'php/'.\basename($path);
	return \basename($path);
}

function error_css():string {
	static $css;
	return $css ??= \file_get_contents(__DIR__.'/assets/debug.css');
}
