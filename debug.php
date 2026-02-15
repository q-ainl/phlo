<?php
namespace phlo;

function d(...$data):void {
	$res = phlo('res');
	foreach ($data AS $item) $res->dump[] = dump_resolve($item);
}

function dump_resolve($v, int $d = 0) {
	if ($d > 10) return '...';
	if ($v instanceof obj) $v = $v->objInfo();
	if (\is_array($v)){ foreach ($v AS $k => $i) $v[$k] = dump_resolve($i, $d + 1); }
	return $v;
}

function dx(...$data):void {
	$req = phlo('req');
	$res = phlo('res');
	if ($req->async || $req->cli || $res->streaming){
		$error = 'PhloDump';
		if (debug){
			$trace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
			$frame = $trace[0] ?? null;
			$source = $frame ? tech\sourcemap_lookup($frame['file'], $frame['line']) : null;
			$error .= lf.lf.'File:'.lf.tech\shortpath($source['file'] ?? $frame['file']).':'.($source['line'] ?? $frame['line']).lf.lf.'Line:'.lf.'dx('.\implode(', ', \array_map(fn($v) => \get_debug_type($v), $data)).')';
		}
		$cmds = ['error' => $error, 'dump' => \array_merge($res->dump, \array_map('phlo\\dump_resolve', $data))];
		apply(...$cmds);
		if (!$res->streaming) throw new \RuntimeException('PhloDump', 0);
		return;
	}
	$trace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
	$frame = $trace[0] ?? null;
	$source = $frame ? tech\sourcemap_lookup($frame['file'], $frame['line']) : null;
	$file = $source['file'] ?? $frame['file'] ?? 'unknown';
	$line = $source['line'] ?? $frame['line'] ?? 0;
	$html = render_dump_page($file, $line, $trace, \array_merge($res->dump, $data));
	$res->type = 'text/html';
	$res->body = $html;
	$res->render();
	throw new \RuntimeException('PhloDump', 0);
}

function dump_value($value, int $depth = 0):array {
	if ($depth > 10) return ['type' => 'truncated', 'value' => '...'];
	if (\is_null($value)) return ['type' => 'null', 'value' => null];
	if (\is_bool($value)) return ['type' => 'bool', 'value' => $value];
	if (\is_int($value)) return ['type' => 'int', 'value' => $value];
	if (\is_float($value)) return ['type' => 'float', 'value' => $value];
	if (\is_string($value)) return ['type' => 'string', 'value' => $value, 'length' => \strlen($value)];
	if (\is_array($value)){
		$items = [];
		$count = 0;
		foreach ($value AS $k => $v){
			if ($count++ > 100){ $items['...'] = ['type' => 'truncated', 'value' => '...']; break; }
			$items[$k] = dump_value($v, $depth + 1);
		}
		return ['type' => 'array', 'count' => \count($value), 'items' => $items];
	}
	if (\is_object($value)){
		$class = \get_class($value);
		if ($value instanceof obj) return ['type' => 'object', 'class' => $class, 'items' => dump_value($value->objInfo(), $depth + 1)['items'] ?? []];
		$items = [];
		foreach (\get_object_vars($value) AS $k => $v) $items[$k] = dump_value($v, $depth + 1);
		return ['type' => 'object', 'class' => $class, 'items' => $items];
	}
	if (\is_resource($value)) return ['type' => 'resource', 'value' => \get_resource_type($value)];
	return ['type' => 'unknown', 'value' => \gettype($value)];
}

function render_dump_page(string $file, int $line, array $trace, array $data):string {
	$css = tech\error_css();
	$memory = size_human(\memory_get_peak_usage());
	$time = duration(4);
	$version = tech\version;
	$shortFile = tech\shortpath($file);
	$dumps = render_dumps($data);
	$sourceContext = tech\get_source_context($file, $line);
	$sourceHtml = void;
	foreach ($sourceContext AS $ctx){
		$num = (int)$ctx['num'];
		$code = esc($ctx['code']);
		$active = $ctx['active'] ? ' active' : void;
		$sourceHtml .= "<tr class=\"trace-row$active\"><td class=\"trace-loc".($ctx['active'] ? ' active-num' : void)."\" style=\"text-align:right;\">$num</td><td class=\"trace-code".($ctx['active'] ? ' active-code' : void)."\">$code</td></tr>";
	}
	$frames = tech\format_trace($trace, $file, $line);
	$traceHtml = void;
	foreach ($frames AS $frame){
		$loc = esc($frame['file'].':'.$frame['line']);
		$call = esc($frame['call'] ?? void);
		$context = esc($frame['context'] ?? void);
		$active = ($frame['file'] === $file && (int)$frame['line'] === $line) ? ' active' : void;
		$traceHtml .= "<tr class=\"trace-row$active\"><td class=\"trace-loc\">$loc</td><td class=\"trace-call\">$call</td><td class=\"trace-code\">$context</td></tr>";
	}
	$fileEsc = esc($shortFile);
	$head = "<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>Phlo Debug</title>\n<style>$css</style>\n";
	$body = "<div class=\"viewport-center\">\n<main class=\"island\">\n"
		."<header class=\"error-header\">\n<h1 class=\"error-title\">Execution Halted</h1>\n<p class=\"error-subtitle\">Phlo runtime encountered a debug interrupt.</p>\n</header>\n"
		."<div class=\"debug-grid\">\n"
		."<section class=\"section-vardump full-width\">\n<div class=\"section-header\"><h2>Variable Stack</h2><span class=\"line\"></span></div>\n<div class=\"dump-container\">$dumps</div>\n</section>\n"
		."<section class=\"section-source\">\n<div class=\"section-header\"><h2>Origin</h2></div>\n"
		."<div class=\"trace-table-wrapper\"><table class=\"trace-table\">\n<thead><tr><th style=\"width:40px;text-align:right;\">#</th><th>$fileEsc:<span class=\"highlight-red\">$line</span></th></tr></thead>\n<tbody>$sourceHtml</tbody>\n</table></div>\n</section>\n"
		."<section class=\"section-backtrace\">\n<div class=\"section-header\"><h2>Backtrace</h2></div>\n"
		."<div class=\"trace-table-wrapper\"><table class=\"trace-table\">\n<thead><tr><th>File:Line</th><th>Call</th><th>Context</th></tr></thead>\n<tbody>$traceHtml</tbody>\n</table></div>\n</section>\n"
		."</div>\n"
		."<footer class=\"island-footer\"><span>Phlo $version</span><span>Memory: $memory</span><span>Time: $time</span></footer>\n"
		."</main>\n</div>";
	return DOM($body, $head);
}

function render_dumps(array $data):string {
	$html = void;
	foreach ($data AS $i => $item){
		$formatted = dump_value($item);
		$type = $formatted['type'];
		$badge = $type;
		if ($type === 'array') $badge = "array[{$formatted['count']}]";
		elseif ($type === 'object') $badge = $formatted['class'];
		elseif ($type === 'string') $badge = "string({$formatted['length']})";
		$body = render_dump_body($formatted);
		$html .= "<details class=\"dump-item\" open>\n<summary class=\"dump-head\"><span class=\"var-name\">dump</span><span class=\"type-badge\">$badge</span><span class=\"arrow\">▼</span></summary>\n<div class=\"dump-body\">$body</div>\n</details>\n";
	}
	return $html;
}

function render_dump_body(array $formatted, int $depth = 0):string {
	$type = $formatted['type'];
	if ($type === 'null') return '<span class="val-null">null</span>';
	if ($type === 'bool') return '<span class="val-bool">'.($formatted['value'] ? 'true' : 'false').'</span>';
	if ($type === 'int' || $type === 'float') return '<span class="val-num">'.$formatted['value'].'</span>';
	if ($type === 'string') return '<span class="val-string">"'.esc($formatted['value']).'"</span>';
	if ($type === 'truncated') return '<span class="val-null">...</span>';
	if ($type === 'array' || $type === 'object'){
		$items = $formatted['items'] ?? [];
		if (!$items) return '<span class="val-null">empty</span>';
		$html = void;
		foreach ($items AS $key => $val){
			$keyEsc = esc($key);
			$valHtml = render_dump_body($val, $depth + 1);
			$html .= "<div class=\"kv-pair\"><span class=\"key\">$keyEsc</span> <span class=\"arrow\">=></span> $valHtml</div>";
		}
		return $html;
	}
	if ($type === 'resource') return '<span class="val-obj">resource('.esc($formatted['value']).')</span>';
	return '<span class="val-null">'.esc($formatted['value'] ?? 'unknown').'</span>';
}

function debug_render($contentLength = null):string {
	$res = phlo('res');
	$out = "console.log('%c[".host.space.(phlo('app')->version ?? '.1')."] [Phlo ".tech\version."] [".size_human(\memory_get_peak_usage()).']'.($contentLength ? ' [DOM: '.size_human($contentLength).']' : void).' ['.\ltrim(duration(), 0)."]','color:lime')";
	$els = debug_elements();
	if ($c = \count($els)) $out .= ";console.log('%cphlo ($c)','font-weight:bold','\\n".\strtr(\implode(space, $els), [sq => bs.sq])."')";
	if ($dc = \count($res->debug)) $out .= ";console.log('%cdebug ($dc)','font-weight:bold','\\n".\strtr(\implode(lf, $res->debug), [lf => '\n', sq => bs.sq])."')";
	if ($res->dump){
		foreach ($res->dump AS $dump){
			$json = \json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$out .= ";console.log('%cdump','font-weight:bold',$json)";
		}
	}
	$out .= ";document.getElementById('debugScript').remove()";
	return '<script id="debugScript"'.(($nonce = phlo('app')->nonce) ? " nonce=\"$nonce\"" : void).'>'.$out.'</script>';
}

function debug_elements():array {
	$elements = phlo();
	\natcasesort($elements);
	$classes = [];
	foreach ($elements AS $el){
		if (\strpos($el, slash)){
			[$class, $handle] = \explode(slash, $el, 2);
			$classes[$class] = [...($classes[$class] ?? []), $handle];
		}
		else $classes[$el] = [];
	}
	return \array_values(loop($classes, fn($handles, $el) => $el.($handles ? '['.\implode(comma, $handles).']' : void)));
}
