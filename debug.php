<?php

require_once __DIR__.'/error.php';

function d(...$data):void {
	$res = phlo('res');
	foreach ($data as $item) $res->dump[] = $item;
}

function dump_resolve($v, int $d = 0) {
	if ($d > 10) return '...';
	if ($v instanceof obj) $v = $v->objInfo();
	if (is_array($v)) foreach ($v as $k => $i) $v[$k] = dump_resolve($i, $d + 1);
	return $v;
}

function dx(...$data):never {
	$req = phlo('req');
	$res = phlo('res');
	$res->done = true;
	if ($req->async || $req->cli || $res->streaming){
		$error = 'PhloDump';
		if (debug){
			$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
			$frame = $trace[0] ?? null;
			$source = $frame ? phlo_error_sourcemap($frame['file'], $frame['line']) : null;
			$srcFile = shortpath($source['file'] ?? $frame['file'] ?? 'unknown');
			$srcLine = $source['line'] ?? $frame['line'] ?? 0;
			$error .= lf.lf.'File:'.lf.$srcFile.':'.$srcLine.lf.lf.'Line:'.lf.'dx('.implode(', ', array_map('get_debug_type', $data)).')';
		}
		$cmds = ['error' => $error, 'dump' => array_map('dump_resolve', array_merge($res->dump, $data))];
		apply(...$cmds);
		throw new RuntimeException('PhloDump', 0);
	}
	$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
	$frame = $trace[0] ?? null;
	$source = $frame ? phlo_error_sourcemap($frame['file'], $frame['line']) : null;
	$file = $source['file'] ?? $frame['file'] ?? 'unknown';
	$line = $source['line'] ?? $frame['line'] ?? 0;
	$html = debug_page($file, $line, $trace, array_merge($res->dump, $data));
	$res->type = 'text/html';
	$res->body = $html;
	$res->render();
	throw new RuntimeException('PhloDump', 0);
}

function dump_value($value, int $depth = 0):array {
	if ($depth > 10) return ['type' => 'truncated', 'value' => '...'];
	if (is_null($value))   return ['type' => 'null',  'value' => null];
	if (is_bool($value))   return ['type' => 'bool',  'value' => $value];
	if (is_int($value))    return ['type' => 'int',   'value' => $value];
	if (is_float($value))  return ['type' => 'float', 'value' => $value];
	if (is_string($value)) return ['type' => 'string','value' => $value, 'length' => strlen($value)];
	if (is_array($value)){
		$items = [];
		$count = 0;
		foreach ($value as $key => $item){
			if ($count++ > 100){
				$items['...'] = ['type' => 'truncated', 'value' => '...'];
				break;
			}
			$items[$key] = dump_value($item, $depth + 1);
		}
		return ['type' => 'array', 'count' => count($value), 'items' => $items];
	}
	if (is_object($value)){
		$class = get_class($value);
		if ($value instanceof obj) return ['type' => 'object', 'class' => $class, 'items' => dump_value($value->objInfo(), $depth + 1)['items'] ?? []];
		$items = [];
		foreach (get_object_vars($value) as $key => $item) $items[$key] = dump_value($item, $depth + 1);
		return ['type' => 'object', 'class' => $class, 'items' => $items];
	}
	if (is_resource($value)) return ['type' => 'resource', 'value' => get_resource_type($value)];
	return ['type' => 'unknown', 'value' => gettype($value)];
}

function debug_page(string $file, int $line, array $trace, array $data):string {
	$fileLink  = phlo_error_location_html($file, $line);
	$dumps     = dump_html($data);
	$srcHtml   = void;
	foreach (phlo_error_source_context($file, $line) as $ctx){
		$active   = $ctx['active'] ? ' active' : void;
		$srcHtml .= '<tr class="row'.$active.'"><td class="num">'.$ctx['num'].'</td><td class="code">'.esc($ctx['code']).'</td></tr>';
	}
	$traceHtml = void;
	foreach (phlo_error_format_trace($trace, $file, $line) as $frame){
		$loc       = phlo_error_location_html($frame['file'], $frame['line']);
		$call      = esc($frame['call'] ?? void);
		$active    = ($frame['file'] === $file && $frame['line'] === $line) ? ' active' : void;
		$traceHtml .= '<tr class="row'.$active.'"><td class="loc">'.$loc.'</td><td class="call">'.$call.'</td></tr>';
	}
	$body = "<main class=\"wrap\">"
		."<header class=\"hero\"><div class=\"badge\">Debug</div><h1>Execution Halted</h1><p>$fileLink</p></header>"
		."<section class=\"grid\">"
		."<article class=\"panel full\"><h2>Dump</h2><div class=\"dump\">$dumps</div></article>"
		."<article class=\"panel\"><h2>Trace</h2><table><tbody>$traceHtml</tbody></table></article>"
		."<article class=\"panel\"><h2>Origin</h2><table><tbody>$srcHtml</tbody></table></article>"
		."</section>"
		.phlo_error_foot()
		."</main>";
	return DOM($body, phlo_error_head('Phlo Debug'));
}

function dump_html(array $data):string {
	$html = void;
	foreach ($data as $item){
		$formatted = dump_value($item);
		$type = $formatted['type'];
		$badge = $type;
		if ($type === 'array')  $badge = "array[{$formatted['count']}]";
		elseif ($type === 'object') $badge = $formatted['class'];
		elseif ($type === 'string') $badge = "string({$formatted['length']})";
		$body  = dump_body($formatted);
		$html .= "<details class=\"dump-item\" open><summary><span class=\"type\">$badge</span></summary><div class=\"dump-body\">$body</div></details>";
	}
	return $html;
}

function dump_body(array $formatted, int $depth = 0):string {
	$type = $formatted['type'];
	if ($type === 'null')      return '<span class="null">null</span>';
	if ($type === 'bool')      return '<span class="bool">'.($formatted['value'] ? 'true' : 'false').'</span>';
	if ($type === 'int' || $type === 'float') return '<span class="num">'.$formatted['value'].'</span>';
	if ($type === 'string')    return '<span class="str">"'.esc($formatted['value']).'"</span>';
	if ($type === 'truncated') return '<span class="null">...</span>';
	if ($type === 'resource')  return '<span class="obj">resource('.esc($formatted['value']).')</span>';
	if ($type === 'array' || $type === 'object'){
		$items = $formatted['items'] ?? [];
		if (!$items) return '<span class="null">empty</span>';
		$html = void;
		foreach ($items as $key => $value){
			$html .= '<div class="kv"><span class="key">'.esc($key).'</span> <span class="arrow">=></span> '.dump_body($value, $depth + 1).'</div>';
		}
		return $html;
	}
	return '<span class="null">'.esc($formatted['value'] ?? 'unknown').'</span>';
}

function debug_collect():array {
	$res = phlo('res');
	return [
		'dump'  => array_map('dump_resolve', $res->dump),
		'phlo'  => debug_elements(),
		'debug' => (array)($res->debug ?: []),
		'mem'   => size_human(memory_get_peak_usage()),
		'dur'   => duration(),
	];
}

function debug_render(?int $contentLength = null):string {
	$d   = debug_collect();
	$app = phlo('app');
	$ver = $app->version ?? '.1';
	$dom = $contentLength ? ' [DOM: '.size_human($contentLength).']' : void;
	$out = "console.log('%c[".id." $ver] [Phlo ".phlo."] [{$d['mem']}]$dom [{$d['dur']}]','color:lime')";
	if (class_exists('trace', false) && trace::$on && trace::$events){
		$count   = count(trace::$events);
		$traceMs = round((microtime(true) - trace::$t0) * 1000, 1);
		$dashUrl = defined('dashboard') && dashboard ? '/'.ltrim(dashboard, '/').'/graph?trace='.trace::$id : void;
		$out .= ";console.log('%c[trace ".trace::$id."] $count events, {$traceMs}ms".($dashUrl ? "  '+location.origin+'$dashUrl" : void)."','color:#c88a40')";
	}
	if ($c = count($d['phlo'])) $out .= ";console.log('%cphlo ($c)','font-weight:bold','\\n".strtr(implode(space, $d['phlo']), [sq => bs.sq])."')";
	if ($dc = count($d['debug'])) $out .= ";console.log('%cdebug ($dc)','font-weight:bold','\\n".strtr(implode(lf, $d['debug']), [lf => '\n', sq => bs.sq])."')";
	foreach ($d['dump'] as $dump){
		$json = json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$out .= ";console.log('%cdump','font-weight:bold',$json)";
	}
	$out .= ";document.getElementById('debugScript').remove()";
	$nonce = ($app->nonce ?? null) ? ' nonce="'.esc($app->nonce).'"' : void;
	return '<script id="debugScript"'.$nonce.'>'.$out.'</script>';
}

function debug_elements():array {
	$elements = phlo();
	natcasesort($elements);
	$classes = [];
	foreach ($elements as $el){
		if (strpos($el, slash)){
			[$class, $handle] = explode(slash, $el, 2);
			$classes[$class] = [...($classes[$class] ?? []), $handle];
		}
		else $classes[$el] = [];
	}
	return array_values(loop($classes, fn($handles, $el) => $el.($handles ? '['.implode(comma, $handles).']' : void)));
}
