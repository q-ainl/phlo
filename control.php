<?php

class phlo_dashboard {

	public static function handle(string $req):void {
		if ($req === 'logo.png'){
			if (is_file(engine.'assets/logo.png')) output(file: engine.'assets/logo.png');
			return;
		}
		if ($req === 'control.css'){
			if (is_file(engine.'assets/control.css')) output(file: engine.'assets/control.css');
			return;
		}
		if ($req === 'control.js'){
			$js = void;
			foreach (['phlo.js', 'highlight.js', 'control.js'] as $f){
				$path = engine.'assets/'.$f;
				if (is_file($path)) $js .= file_get_contents($path)."\n";
			}
			$res = phlo('res');
			$res->type = 'application/javascript; charset=UTF-8';
			$res->body = $js;
			return;
		}
		if (!phlo_auth('dashboard', 'Phlo Control - '.host)) return;

		if ($req === 'trace' || str_starts_with($req, 'trace/') || $req === 'traces'){
			$res     = phlo('res');
			$dir     = data.'trace'.slash;
			$index   = is_file($dir.'index.json') ? (json_decode((string)file_get_contents($dir.'index.json'), true) ?: []) : [];
			$res->type = 'application/json';
			if ($req === 'traces'){
				$res->body = json_encode(['index' => $index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				return;
			}
			$id = str_starts_with($req, 'trace/') ? substr($req, 6) : ($index[0]['id'] ?? null);
			$file = $id ? $dir.$id.'.json' : null;
			$data = ($file && is_file($file)) ? json_decode((string)file_get_contents($file)) : null;
			if (!$data && $req === 'trace' && is_file(data.'trace.json')) $data = json_decode((string)file_get_contents(data.'trace.json'));
			$res->body = json_encode(['trace' => $data, 'index' => $index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			return;
		}

		$section = $req ?: 'home';
		$arg     = null;
		if (str_contains($req, slash)) [$section, $arg] = explode(slash, $req, 2);

		$cfg      = build_base::config();
		$sections = ['home'];
		if (is_dir(data.'tasks/')) $sections[] = 'tasks';
		$sections = array_merge($sections, ['config', 'graph', 'source', 'build']);
		if (!empty($cfg['release'])) $sections[] = 'release';
		$sections[] = 'errors';
		$sections[] = 'theme';

		if (!in_array($section, $sections, true)){
			phlo('res')->render(404);
			return;
		}
		static::$section($arg);
	}

	private static function theme(?string $arg):void {
		$req   = phlo('req');
		$clean = $arg ? preg_replace('/[^a-z0-9]/', '', strtolower($arg)) : '';
		$valid = $clean !== '' && is_file(www.'theme.'.$clean.'.css');
		$value = $valid ? $clean : '';
		setcookie('phlo_ctl_theme', $value, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
		$_COOKIE['phlo_ctl_theme'] = $value;
		if ($req->async){
			apply(...array_filter([
				'remove' => 'link[href^="/theme."]',
				'css'    => $valid ? '/theme.'.$clean.'.css' : null,
				'class'  => ['.ctl-theme' => '-is-active', '.ctl-theme[data-theme="'.$value.'"]' => 'is-active'],
				'trans'  => true,
			]));
			return;
		}
		location('/'.ltrim(control, '/'));
	}

	private static function home(?string $arg):void {
		$req = phlo('req');
		if ($arg === 'readme' && $req->method === 'POST'){
			file_put_contents(data.'app.md', (string)($_POST['md'] ?? ''));
			if ($req->async){ apply(inner: ['#home-md-msg' => 'Saved']); return; }
			location('/'.control);
			return;
		}
		$phpFiles     = glob(php.'*.php') ?: [];
		$wwwFiles     = array_merge(glob(www.'*.js') ?: [], glob(www.'*.css') ?: []);
		$srcFiles     = reflect::sourceFiles();
		$changed      = build_base::changed();
		$cfg          = build_base::config();
		$errors       = reflect::errors(5);
		$summary      = reflect::resourceSummary();
		$routes       = reflect::compactRoutes();
		$views        = reflect::compactViews();
		$releaseFiles = !empty($cfg['release']) ? build::releaseFiles() : ['php' => [], 'www' => []];

		$buildCls  = $changed ? 'warn' : 'ok';
		$buildTxt  = $changed ? count($changed).' pending' : 'Up to date';
		$appUrl    = '//'.host;
		$appUrlE   = esc($appUrl);
		$appHost   = esc(host);
		$phpCount  = count($phpFiles);
		$wwwCount  = count($wwwFiles);
		$srcCount  = count($srcFiles);
		$relPhp    = count($releaseFiles['php']);
		$relWww    = count($releaseFiles['www']);

		$s = fn($k, $v) => "<tr class=\"row\"><td class=\"num\">$k</td><td class=\"code\">$v</td></tr>\n";
		$statRows = $s('phlo', esc(phlo))
			.$s('host', "<a href=\"$appUrlE\" target=\"_blank\" rel=\"noopener\">$appHost</a>")
			.$s('source', esc("$srcCount .phlo"))
			.$s('build', "<span class=\"$buildCls\">".esc($buildTxt)."</span>")
			.$s('php/', esc("$phpCount files"))
			.$s('www/', esc("$wwwCount assets"))
			.$s('release', !empty($cfg['release']) ? esc("$relPhp php / $relWww assets") : '<span class="muted">Not configured</span>');

		$errRows = void;
		foreach ($errors as $id => $err){
			$ref   = esc((string)$id);
			$file  = static::dashboardFileLink((string)($err['file'] ?? ''));
			$msg   = esc((string)($err['msg'] ?? ''));
			$count = (int)($err['count'] ?? 0);
			$last  = esc((string)($err['lastOccurred'] ?? ''));
			$errRows .= "<tr class=\"row\"><td class=\"num\"><code>$ref</code></td><td>$file</td><td>$msg</td><td class=\"num\">$count</td><td class=\"num\">$last</td></tr>\n";
		}
		if (!$errRows) $errRows = "<tr class=\"row\"><td colspan=\"5\" class=\"muted\">No errors logged</td></tr>\n";

		$routeCount = count($routes);
		$viewCount  = count($views);
		$fnCount    = (int)($summary['functions'] ?? 0);
		$objCount   = (int)($summary['objects'] ?? 0);
		$overview   = "<div class=\"dash-stat\"><strong>$routeCount</strong><span>Routes</span></div>\n"
			."<div class=\"dash-stat\"><strong>$viewCount</strong><span>Views</span></div>\n"
			."<div class=\"dash-stat\"><strong>$fnCount</strong><span>Functions</span></div>\n"
			."<div class=\"dash-stat\"><strong>$objCount</strong><span>Objects</span></div>\n";

		$routeRows = void;
		foreach ($routes as $route){
			$r    = esc(($route['route'] ?? ''));
			$link = static::dashboardFileLink(($route['file'] ?? ''));
			$routeRows .= "<tr class=\"row\"><td><code>$r</code></td><td class=\"muted\">$link</td></tr>\n";
		}
		if (!$routeRows) $routeRows = "<tr class=\"row\"><td colspan=\"2\" class=\"muted\">No routes</td></tr>\n";

		$viewRows = void;
		foreach ($views as $view){
			$vname = esc(($view['name'] ?? 'view'));
			$link  = static::dashboardFileLink(($view['file'] ?? ''));
			$viewRows .= "<tr class=\"row\"><td><code>$vname</code></td><td class=\"muted\">$link</td></tr>\n";
		}
		if (!$viewRows) $viewRows = "<tr class=\"row\"><td colspan=\"2\" class=\"muted\">No views</td></tr>\n";

		$mdFile    = data.'app.md';
		$mdContent = esc(is_file($mdFile) ? (string)file_get_contents($mdFile) : void);
		$mdUrl     = esc('/'.control.'/home/readme');
		$mdCard    = "<div class=\"dash-card dash-half\">\n"
			."<div class=\"dash-card-head\">app.md</div>\n"
			."<div class=\"dash-card-body\">\n"
			."<form method=\"post\" action=\"$mdUrl\">\n"
			."<textarea name=\"md\" class=\"code-textarea dash-md-home\" spellcheck=\"false\" placeholder=\"Document your app: structure, routes, TODO items, notes for AI agents...\">$mdContent</textarea>\n"
			."<div class=\"dash-config-actions\" style=\"margin-top:8px\">"
			."<button type=\"submit\" class=\"primary\">Save</button>"
			."<span id=\"home-md-msg\" class=\"muted\"></span>"
			."</div>\n"
			."</form>\n"
			."</div>\n"
			."</div>\n";

		$phloV = esc(phlo);
		$body  = "<main class=\"dash-main\">\n"
			."<header class=\"dash-hero\">\n"
			."<div class=\"dash-badge\">Phlo $phloV</div>\n"
			."<h1>$appHost</h1>\n"
			."<p><a href=\"$appUrlE\" target=\"_blank\" rel=\"noopener\">$appHost</a></p>\n"
			."</header>\n"
			."<div class=\"dash-grid\">\n"
			."<div class=\"dash-card dash-half\">\n"
			."<div class=\"dash-card-head\">Overview</div>\n"
			."<div class=\"dash-card-body dash-stats\">\n$overview</div>\n"
			."</div>\n"
			.$mdCard
			."<div class=\"dash-card dash-half\">\n"
			."<div class=\"dash-card-head\">Status</div>\n"
			."<div class=\"dash-card-body\">\n"
			."<table><tbody>\n$statRows</tbody></table>\n"
			."</div>\n"
			."</div>\n"
			."<div class=\"dash-card dash-half\">\n"
			."<div class=\"dash-card-head\">Recent Errors</div>\n"
			."<div class=\"dash-card-body\">\n"
			."<table>\n"
			."<thead><tr><th>Ref</th><th>File</th><th>Message</th><th>#</th><th>Last seen</th></tr></thead>\n"
			."<tbody>\n$errRows</tbody>\n"
			."</table>\n"
			."</div>\n"
			."</div>\n"
			."<div class=\"dash-card dash-half\">\n"
			."<div class=\"dash-card-head\">Routes ($routeCount)</div>\n"
			."<div class=\"dash-card-body\">\n"
			."<table>\n"
			."<thead><tr><th>Route</th><th>Location</th></tr></thead>\n"
			."<tbody>\n$routeRows</tbody>\n"
			."</table>\n"
			."</div>\n"
			."</div>\n"
			."<div class=\"dash-card dash-half\">\n"
			."<div class=\"dash-card-head\">Views ($viewCount)</div>\n"
			."<div class=\"dash-card-body\">\n"
			."<table>\n"
			."<thead><tr><th>View</th><th>Location</th></tr></thead>\n"
			."<tbody>\n$viewRows</tbody>\n"
			."</table>\n"
			."</div>\n"
			."</div>\n"
			."</div>\n"
			."</main>\n";

		static::render($body, 'home');
	}

	private static function config(?string $arg):void {
		$req  = phlo('req');
		$base = control;

		if ($arg === 'save' && $req->method === 'POST'){
			$json    = trim((string)($_POST['json'] ?? ''));
			$decoded = json_decode($json);
			if ($decoded === null){ apply(error: 'Invalid JSON: '.json_last_error_msg()); return; }
			file_put_contents(data.'app.json', $json);
			try { build::run(); } catch (\Throwable $e){
				apply(error: 'Saved but build failed: '.esc($e->getMessage())); return;
			}
			if ($req->async){ apply(inner: ['#config-msg' => 'Saved and built']); return; }
			location('/'.ltrim("$base/config", '/'));
			return;
		}
		if ($arg && str_starts_with($arg, 'resource/') && $req->method === 'POST'){
			static::toggleResource(rawurldecode(substr($arg, 9))); return;
		}

		$file    = data.'app.json';
		$json    = esc(is_file($file) ? (string)file_get_contents($file) : '{}');
		$saveUrl = esc('/'.ltrim("$base/config/save", '/'));

		$resourceGroups = [];
		foreach (reflect::availableResources() as $resource){
			$config = ($resource['name'] ?? '');
			$url    = '/'.ltrim("$base/config/resource/".rawurlencode($config), '/');
			$id     = 'resource-'.substr(md5($config), 0, 10);
			$group  = str_contains($config, slash) ? dirname($config) : 'root';
			$resourceGroups[$group][] = [
				'id'      => $id,
				'url'     => $url,
				'loaded'  => !empty($resource['loaded']),
				'name'    => $config,
				'meta'    => trim((string)($resource['kind'] ?? 'resource').space.(string)($resource['class'] ?? void)),
				'summary' => ($resource['summary'] ?? ''),
			];
		}
		ksort($resourceGroups, SORT_NATURAL | SORT_FLAG_CASE);
		if (isset($resourceGroups['root'])) $resourceGroups = ['root' => $resourceGroups['root']] + $resourceGroups;

		$resourceCards = static::resourceGroupsHtml($resourceGroups, 'No resources available');

		$body = "<main class=\"dash-main dash-config-page\">\n"
			."<div class=\"dash-config-layout\">\n"
			."<div class=\"dash-config-editor\">\n"
			."<div class=\"dash-card-head\">app.json</div>\n"
			."<form id=\"config-form\" method=\"post\" action=\"$saveUrl\">\n"
			."<textarea id=\"config-json\" name=\"json\" class=\"code-textarea\" spellcheck=\"false\">$json</textarea>\n"
			."<div class=\"dash-config-actions\">"
			."<button type=\"submit\" id=\"config-save\" class=\"primary\">Save</button>"
			."<span id=\"config-msg\" class=\"muted\"></span>"
			."</div>\n"
			."</form>\n"
			."<span id=\"config-json-pending\" style=\"display:none\" aria-hidden=\"true\"></span>\n"
			."</div>\n"
			."<div class=\"dash-config-resources-col\">\n"
			."<div class=\"dash-card-head\">Resources</div>\n"
			."<div class=\"dash-config-resources-body\">\n"
			."<input type=\"search\" id=\"resource-search\" class=\"resource-search-input\" placeholder=\"Filter resources...\">\n"
			."<div id=\"resource-list\">\n$resourceCards</div>\n"
			."</div>\n"
			."</div>\n"
			."</div>\n"
			."</main>\n";

		static::render($body, 'config');
	}

	private static function graph(?string $arg):void {
		$req        = phlo('req');
		$isFrontend = $arg === 'frontend';
		$data       = $isFrontend ? reflect::selectorGraph() : reflect::graph();
		$b64        = base64_encode((string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$b64E       = esc($b64);
		$mode       = $isFrontend ? 'frontend' : 'backend';
		$base       = '/'.ltrim(control, '/');
		$hrefBack   = esc("$base/graph");
		$hrefFront  = esc("$base/graph/frontend");
		$clsBack    = !$isFrontend ? ' active' : '';
		$clsFront   = $isFrontend  ? ' active' : '';
		$traceDir   = data.'trace'.slash;
		$index      = is_file($traceDir.'index.json') ? (json_decode((string)file_get_contents($traceDir.'index.json'), true) ?: []) : [];
		$selectedId = (string)($req->query['trace'] ?? ($index[0]['id'] ?? ''));
		$traceFile  = $selectedId ? $traceDir.$selectedId.'.json' : null;
		$legacyFile = data.'trace.json';
		$traceAttr  = '';
		$traceBtn   = '';
		$selector   = '';
		if ($traceFile && is_file($traceFile)){
			$traceAttr = ' data-trace="'.esc(base64_encode((string)file_get_contents($traceFile))).'"';
			$traceBtn  = '<button id="rg-trace-toggle" title="Trace replay">&#9654;</button>';
		}
		elseif (is_file($legacyFile)){
			$traceAttr = ' data-trace="'.esc(base64_encode((string)file_get_contents($legacyFile))).'"';
			$traceBtn  = '<button id="rg-trace-toggle" title="Trace replay">&#9654;</button>';
		}
		if ($index){
			$opts = '';
			foreach ($index as $entry){
				$id    = (string)($entry['id'] ?? '');
				$route = (string)($entry['route'] ?? ($entry['method'] ?? '').' '.($entry['path'] ?? ''));
				$ms    = (string)($entry['ms'] ?? '0');
				$count = (string)($entry['count'] ?? '0');
				$ts    = (int)($entry['ts'] ?? 0);
				$time  = $ts ? date('H:i:s', $ts) : '';
				$sel   = $id === $selectedId ? ' selected' : '';
				$label = esc("$time  $route  ($ms ms, $count events)");
				$opts .= "<option value=\"".esc($id)."\"$sel>$label</option>";
			}
			$selector = "<select id=\"rg-trace-select\" data-endpoint=\"".esc("$base/trace/")."\">$opts</select>";
		}
		$controls = ($traceAttr ? '<div class="rg-trace-controls">'
			.'<button id="rg-trace-prev" title="Previous step">&#9664;</button>'
			.'<input type="range" id="rg-trace-scrub" min="0" max="0" value="0">'
			.'<button id="rg-trace-next" title="Next step">&#9654;</button>'
			.'<button id="rg-trace-restart" title="Restart">&#10227;</button>'
			.'</div>' : '');
		$sidebar = ($traceAttr ? '<aside class="rg-calls">'
			.'<header class="rg-calls__head"><span>Calls</span><span id="rg-calls-count" class="muted"></span></header>'
			.'<ol class="rg-calls__list" id="rg-calls-list"></ol>'
			.'</aside>' : '');
		$body = "<main class=\"dash-main dash-graph-page\">\n"
			."<div class=\"dash-file-hero\">\n"
			."<div class=\"rg-graph-mode\">"
			."<a href=\"$hrefBack\" class=\"async$clsBack\">Backend</a>"
			."<a href=\"$hrefFront\" class=\"async$clsFront\">Frontend</a>"
			."<span class=\"rg-mode-sep\"></span>"
			."<button id=\"rg-dim-toggle\">3D</button>"
			."$traceBtn"
			."$selector"
			."</div>\n"
			."<div class=\"rg-legend\"></div>\n"
			."</div>\n"
			."<div class=\"dash-graph-layout\">\n"
			."<div class=\"dash-graph-wrap\">\n"
			."<canvas id=\"rg-canvas\" data-graph=\"$b64E\" data-dashboard=\"".esc($base)."\" data-mode=\"$mode\"$traceAttr></canvas>\n"
			."$controls"
			."<div id=\"rg-timeline\"></div>\n"
			."<div id=\"rg-hit-label\" class=\"rg-hit-label\" hidden></div>\n"
			."<div id=\"rg-args-tooltip\" class=\"rg-args-tooltip\" hidden></div>\n"
			."</div>\n"
			."$sidebar"
			."</div>\n"
			."</main>\n";
		static::render($body, 'graph');
	}

	private static function source(?string $arg):void {
		$req  = phlo('req');
		$base = control;

		$mode        = (string)($req->query['mode'] ?? 'app');
		$engineModes = ['native' => engine.'phlo.php', 'build' => engine.'classes/build.php', 'reflect' => engine.'classes/reflect.php'];
		if (!in_array($mode, ['app', 'resources', 'available', 'native', 'build', 'reflect'], true)) $mode = 'app';

		$modeBase  = '/'.ltrim("$base/source", '/');

		if (isset($engineModes[$mode])){
			$engineFile = $engineModes[$mode];
			$fns        = static::engineFunctionMap($engineFile);
			$content    = static::fileContent($engineFile, true);
			$tabs       = static::sourceTabs($mode, $modeBase);
			$body = "<main class=\"dash-main dash-file-page\">\n"
				."<div class=\"dash-file-hero\">\n$tabs</div>\n"
				."<div class=\"dash-file-shell\">\n"
				."<nav class=\"dash-file-nav\">\n".static::functionPicker($fns, "$modeBase?mode=$mode")."</nav>\n"
				."<div class=\"dash-file-body\"><div id=\"file-content\">\n$content</div></div>\n"
				."</div>\n"
				."</main>\n";
			static::render($body, 'source', basename($engineFile));
			return;
		}

		$files = static::sourceFileMap($mode);
		if ($arg && $arg !== 'search' && !isset($files[$arg])){
			foreach (['app', 'resources', 'available'] as $scope){
				$try = static::sourceFileMap($scope);
				if (isset($try[$arg])){ $mode = $scope; $files = $try; break; }
			}
		}
		$tabs = static::sourceTabs($mode, $modeBase);

		if ($arg === 'search'){
			$q    = trim((string)($req->query['q'] ?? ''));
			$hits = $q ? static::searchFiles($files, $q) : [];
			$html = static::searchResultsHtml($hits, $q, $modeBase, [], "$modeBase?mode=$mode");
			if ($req->async){ apply(inner: ['.dash-file-shell' => $html]); return; }
		}

		$requested = trim((string)($req->query['active'] ?? ''));
		$active    = ($arg && isset($files[$arg])) ? $arg
			: ($requested && isset($files[$requested]) ? $requested : (string)array_key_first($files));
		$content   = $files ? static::fileContent((string)($files[$active] ?? ''), false) : '<p class="muted">No source files found.</p>';
		$searchUrl = esc('/'.ltrim("$base/source/search", '/'));

		$body = "<main class=\"dash-main dash-file-page\">\n"
			."<div class=\"dash-file-hero\">\n$tabs"
			."<form class=\"dash-search-form\" action=\"$searchUrl?mode=$mode\" method=\"get\">\n"
			."<input type=\"search\" name=\"q\" placeholder=\"Search...\" class=\"dash-search-input\">"
			."<button type=\"submit\">Search</button>\n"
			."</form>\n"
			."</div>\n"
			."<div class=\"dash-file-shell\">\n"
			."<nav class=\"dash-file-nav\">\n".static::filePicker(array_keys($files), $active, "$modeBase?mode=$mode")."</nav>\n"
			."<div class=\"dash-file-body\"><div id=\"file-content\">\n$content</div></div>\n"
			."</div>\n"
			."</main>\n";

		static::render($body, 'source', $files ? static::displayFileName($active) : '');
	}

	private static function engineFunctionMap(string $file):array {
		if (!is_file($file)) return [];
		$fns = [];
		foreach (file($file, FILE_IGNORE_NEW_LINES) as $i => $line){
			if (preg_match('/^function\s+(\w+)\s*\(/', $line, $m))
				$fns[$m[1]] = $i + 1;
			elseif (preg_match('/^\t.*static\s+function\s+(\w+)\s*\(/', $line, $m))
				$fns[$m[1]] = $i + 1;
		}
		return $fns;
	}

	private static function sourceTabs(string $mode, string $modeBase):string {
		$modeBaseE = esc($modeBase);
		$appCls    = $mode === 'app'       ? ' active' : '';
		$resCls    = $mode === 'resources' ? ' active' : '';
		$availCls  = $mode === 'available' ? ' active' : '';
		$natCls    = $mode === 'native'    ? ' active' : '';
		$bldCls    = $mode === 'build'     ? ' active' : '';
		$refCls    = $mode === 'reflect'   ? ' active' : '';
		return "<div class=\"dash-mode-tabs\">"
			."<a href=\"$modeBaseE?mode=app\" class=\"mode-tab$appCls\" data-nav=\"file\">App</a>"
			."<a href=\"$modeBaseE?mode=resources\" class=\"mode-tab$resCls\" data-nav=\"file\">Resources</a>"
			."<a href=\"$modeBaseE?mode=available\" class=\"mode-tab$availCls\" data-nav=\"file\">Available</a>"
			."<a href=\"$modeBaseE?mode=native\" class=\"mode-tab$natCls\" data-nav=\"file\">Native</a>"
			."<a href=\"$modeBaseE?mode=build\" class=\"mode-tab$bldCls\" data-nav=\"file\">Build</a>"
			."<a href=\"$modeBaseE?mode=reflect\" class=\"mode-tab$refCls\" data-nav=\"file\">Reflect</a>"
			."</div>\n";
	}

	private static function functionPicker(array $fns, string $pageUrl):string {
		$html     = void;
		$hrefBase = esc($pageUrl);
		foreach ($fns as $name => $line){
			$nameE = esc($name);
			$html .= "<a href=\"$hrefBase#L$line\" data-nav=\"file\">$nameE</a>\n";
		}
		return $html;
	}

	private static function build(?string $arg):void {
		$req  = phlo('req');
		$base = control;

		if ($arg === 'run' && $req->method === 'POST'){
			try {
				$changed = build::run();
				$html    = static::actionResultHtml('Built', $changed, 'changed');
			} catch (\Throwable $e){ $html = '<p class="error">Build failed: '.esc($e->getMessage()).'</p>'; }
			if ($req->async){ static::fileActionApply('build', $html); return; }
		}
		if ($arg === 'flush' && $req->method === 'POST'){
			try {
				$deleted = static::safeFlush(php, www);
				$html    = static::actionResultHtml('Flushed', $deleted, 'deleted');
			} catch (\Throwable $e){ $html = '<p class="error">Flush failed: '.esc($e->getMessage()).'</p>'; }
			if ($req->async){ static::fileActionApply('build', $html); return; }
		}

		$allFiles = build::buildFiles();
		$fileMap  = static::buildFileMap($allFiles);

		if ($arg === 'search'){
			$q    = trim((string)($req->query['q'] ?? ''));
			$hits = $q ? static::searchFiles($fileMap, $q) : [];
			$html = static::searchResultsHtml($hits, $q, '/'.ltrim("$base/build/view", '/'), [], '/'.ltrim("$base/build", '/'));
			if ($req->async){ apply(inner: ['.dash-file-shell' => $html]); return; }
		}

		$name      = ($arg && str_starts_with($arg, 'view/')) ? rawurldecode(substr($arg, 5)) : null;
		$requested = trim((string)($req->query['active'] ?? ''));
		$active    = ($name && isset($fileMap[$name])) ? $name
			: ($requested && isset($fileMap[$requested]) ? $requested : (string)array_key_first($fileMap));
		$content   = $fileMap ? static::fileContent(($fileMap[$active] ?? ''), str_ends_with(($fileMap[$active] ?? ''), '.php')) : '<p class="muted">No built files.</p>';
		$runUrl    = esc('/'.ltrim("$base/build/run", '/'));
		$flushUrl  = esc('/'.ltrim("$base/build/flush", '/'));
		$searchUrl = esc('/'.ltrim("$base/build/search", '/'));
		$phpCount  = count($allFiles['php']);
		$wwwCount  = count($allFiles['www']);

		$body = "<main class=\"dash-main dash-file-page\">\n"
			."<div class=\"dash-file-hero\">\n"
			."<span class=\"dash-badge\">$phpCount php / $wwwCount assets</span>\n"
			."<form class=\"dash-search-form\" action=\"$searchUrl\" method=\"get\">\n"
			."<input type=\"search\" name=\"q\" placeholder=\"Search...\" class=\"dash-search-input\">"
			."<button type=\"submit\">Search</button>\n"
			."</form>\n"
			."<div class=\"dash-actions\">\n"
			."<form method=\"post\" action=\"$runUrl\"><button type=\"submit\" class=\"primary\">Build</button></form>\n"
			."<form method=\"post\" action=\"$flushUrl\"><button type=\"submit\">Flush</button></form>\n"
			."</div>\n"
			."</div>\n"
			."<div id=\"build-result\" class=\"dash-file-result\"></div>\n"
			."<div class=\"dash-file-shell\">\n"
			."<nav class=\"dash-file-nav\">\n".static::filePicker(array_keys($fileMap), $active, "/$base/build/view")."</nav>\n"
			."<div class=\"dash-file-body\"><div id=\"file-content\">\n$content</div></div>\n"
			."</div>\n"
			."</main>\n";

		static::render($body, 'build', $fileMap ? static::displayFileName($active) : '');
	}

	private static function release(?string $arg):void {
		$req  = phlo('req');
		$base = control;

		if ($arg === 'run' && $req->method === 'POST'){
			try {
				$changed = build::release();
				$html    = static::actionResultHtml('Released', $changed, 'written');
			} catch (\Throwable $e){ $html = '<p class="error">Release failed: '.esc($e->getMessage()).'</p>'; }
			if ($req->async){ static::fileActionApply('release', $html); return; }
		}
		if ($arg === 'flush' && $req->method === 'POST'){
			try {
				$deleted = static::flushReleaseFiles();
				$html    = static::actionResultHtml('Flushed', $deleted, 'deleted');
			} catch (\Throwable $e){ $html = '<p class="error">Flush failed: '.esc($e->getMessage()).'</p>'; }
			if ($req->async){ static::fileActionApply('release', $html); return; }
		}

		$allFiles = build::releaseFiles();
		$fileMap  = static::buildFileMap($allFiles);

		if ($arg === 'search'){
			$q    = trim((string)($req->query['q'] ?? ''));
			$hits = $q ? static::searchFiles($fileMap, $q) : [];
			$html = static::searchResultsHtml($hits, $q, '/'.ltrim("$base/release/view", '/'), [], '/'.ltrim("$base/release", '/'));
			if ($req->async){ apply(inner: ['.dash-file-shell' => $html]); return; }
		}

		$name      = ($arg && str_starts_with($arg, 'view/')) ? rawurldecode(substr($arg, 5)) : null;
		$requested = trim((string)($req->query['active'] ?? ''));
		$active    = ($name && isset($fileMap[$name])) ? $name
			: ($requested && isset($fileMap[$requested]) ? $requested : (string)array_key_first($fileMap));
		$content   = $fileMap ? static::fileContent(($fileMap[$active] ?? ''), str_ends_with(($fileMap[$active] ?? ''), '.php')) : '<p class="muted">No release files.</p>';
		$runUrl    = esc('/'.ltrim("$base/release/run", '/'));
		$flushUrl  = esc('/'.ltrim("$base/release/flush", '/'));
		$searchUrl = esc('/'.ltrim("$base/release/search", '/'));
		$phpCount  = count($allFiles['php']);
		$wwwCount  = count($allFiles['www']);

		$body = "<main class=\"dash-main dash-file-page\">\n"
			."<div class=\"dash-file-hero\">\n"
			."<span class=\"dash-badge\">$phpCount php / $wwwCount assets</span>\n"
			."<form class=\"dash-search-form\" action=\"$searchUrl\" method=\"get\">\n"
			."<input type=\"search\" name=\"q\" placeholder=\"Search...\" class=\"dash-search-input\">"
			."<button type=\"submit\">Search</button>\n"
			."</form>\n"
			."<div class=\"dash-actions\">\n"
			."<form method=\"post\" action=\"$runUrl\"><button type=\"submit\" class=\"primary\">Release</button></form>\n"
			."<form method=\"post\" action=\"$flushUrl\"><button type=\"submit\">Flush</button></form>\n"
			."</div>\n"
			."</div>\n"
			."<div id=\"release-result\" class=\"dash-file-result\"></div>\n"
			."<div class=\"dash-file-shell\">\n"
			."<nav class=\"dash-file-nav\">\n".static::filePicker(array_keys($fileMap), $active, "/$base/release/view")."</nav>\n"
			."<div class=\"dash-file-body\"><div id=\"file-content\">\n$content</div></div>\n"
			."</div>\n"
			."</main>\n";

		static::render($body, 'release', $fileMap ? static::displayFileName($active) : '');
	}

	private static function errors(?string $arg):void {
		$req  = phlo('req');
		$base = control;

		if ($arg === 'reset' && $req->method === 'POST'){
			$file = data.'errors.json';
			if (is_file($file)) unlink($file);
			if ($req->async){ apply(location: '/'.ltrim("$base/errors", '/')); return; }
			location('/'.ltrim("$base/errors", '/'));
			return;
		}

		$errors   = reflect::errors(0);
		$rows     = void;
		foreach ($errors as $id => $err){
			$filter = esc(json_encode(['id' => $id] + $err, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: void);
			$ref    = esc((string)$id);
			$file   = static::dashboardFileLink((string)($err['file'] ?? ''));
			$msg    = esc((string)($err['msg'] ?? ''));
			$count  = (int)($err['count'] ?? 0);
			$last   = esc((string)($err['lastOccurred'] ?? ''));
			$rows  .= "<tr class=\"row\"><td class=\"num\" data-filter=\"$filter\"><code>$ref</code></td><td>$file</td><td>$msg</td><td class=\"num\">$count</td><td class=\"num\">$last</td></tr>\n";
		}
		if (!$rows) $rows = "<tr class=\"row\"><td colspan=\"5\" class=\"muted\">No errors logged.</td></tr>\n";

		$errCount = count($errors);
		$resetUrl = esc('/'.ltrim("$base/errors/reset", '/'));

		$body = "<main class=\"dash-main dash-file-page dash-errors-page\">\n"
			."<div class=\"dash-file-hero\">\n"
			."<span class=\"dash-badge\">$errCount entries</span>\n"
			."<input type=\"search\" id=\"error-search\" placeholder=\"Filter errors...\" class=\"dash-search-input dash-error-search\">\n"
			."<div class=\"dash-actions\">\n"
			."<form method=\"post\" action=\"$resetUrl\"><button type=\"submit\">Clear log</button></form>\n"
			."</div>\n"
			."</div>\n"
			."<div class=\"dash-errors-body\">\n"
			."<table>\n"
			."<thead><tr><th>Ref</th><th>File</th><th>Message</th><th>#</th><th>Last seen</th></tr></thead>\n"
			."<tbody>\n$rows</tbody>\n"
			."</table>\n"
			."</div>\n"
			."</main>\n";

		static::render($body, 'errors');
	}

	private static function toggleResource(string $name):void {
		$file   = data.'app.json';
		$backup = is_file($file) ? (string)file_get_contents($file) : '{}';
		$cfg    = json_decode($backup, true) ?: [];
		$cur    = array_values(array_unique((array)($cfg['resources'] ?? [])));
		$affected = [$name];
		if (in_array($name, $cur, true)){
			$cfg['resources'] = array_values(array_diff($cur, [$name]));
		} else {
			$cur[] = $name;
			foreach (reflect::resourceDependencies($name) as $dep){
				$cur[] = $dep;
				$affected[] = $dep;
			}
			$cfg['resources'] = $cur;
		}
		natcasesort($cfg['resources']);
		$cfg['resources'] = array_values($cfg['resources']);
		$newJson = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		file_put_contents($file, $newJson);
		try { build::run(); } catch (\Throwable $e){
			file_put_contents($file, $backup);
			apply(error: 'Build failed: '.esc($e->getMessage()));
			return;
		}
		if (phlo('req')->async){
			$id = trim((string)($_POST['button_id'] ?? ''));
			if ($id){
				$inner = [
					'#config-msg'          => 'Updated and built',
					'#config-json-pending' => esc($newJson),
				];
				$class = [];
				foreach (array_unique($affected) as $res){
					$rowId = $res === $name ? $id : 'resource-'.substr(md5($res), 0, 10);
					$isLoaded = in_array($res, $cfg['resources'], true);
					$inner["#$rowId"] = $isLoaded ? 'on' : 'off';
					$class["#$rowId"] = $isLoaded ? 'on' : '-on';
					$class["#{$rowId}-row"] = $isLoaded ? 'loaded' : '-loaded';
				}
				apply(
					inner: $inner,
					class: $class,
				);
				return;
			}
		}
		$base = control;
		location('/'.ltrim("$base/config", '/'));
	}

	private static function actionResultHtml(string $label, array $files, string $kind):string {
		$count   = count($files);
		$names   = array_map(static fn($f) => basename($f), $files);
		$labelE  = esc($label);
		$details = $names
			? '<div class="dash-action-preview">'.esc(implode(', ', $names)).'</div>'
			: '<div class="dash-action-preview muted">No files</div>';
		return "<div class=\"dash-action-result\"><div class=\"dash-action-summary ok\">$labelE $count file(s).</div>$details</div>";
	}

	private static function fileActionApply(string $section, string $result):void {
		$base     = control;
		$allFiles = $section === 'release' ? build::releaseFiles() : build::buildFiles();
		$fileMap  = static::buildFileMap($allFiles);
		$viewBase = "/$base/$section/view";
		$active   = (string)array_key_first($fileMap);
		$noFiles  = $section === 'release' ? 'No release files.' : 'No built files.';
		$content  = $fileMap ? static::fileContent($fileMap[$active], str_ends_with($fileMap[$active], '.php')) : "<p class=\"muted\">$noFiles</p>";
		$shell    = "<nav class=\"dash-file-nav\">\n".static::filePicker(array_keys($fileMap), $active, $viewBase)."</nav>\n"
			."<div class=\"dash-file-body\"><div id=\"file-content\">\n$content</div></div>\n";
		apply(
			path: ltrim("$base/$section", '/'),
			pathReplace: true,
			title: ucfirst($section).' - Phlo Control',
			inner: ["#{$section}-result" => $result, '.dash-file-shell' => $shell],
			class: ["#{$section}-result" => 'show'],
		);
	}

	private static function flushReleaseFiles():array {
		$cfg     = build_base::config();
		$release = (array)($cfg['release'] ?? []);
		$phpDir  = rtrim((string)($release['php'] ?? (app.'release/')), slash).slash;
		$wwwDir  = rtrim((string)($release['www'] ?? (app.'release/www/')), slash).slash;
		return static::safeFlush($phpDir, $wwwDir);
	}

	private static function safeFlush(string $phpDir, string $wwwDir):array {
		$deleted = [];
		foreach (glob(rtrim($phpDir, slash).slash.'*.php') ?: [] as $file){
			unlink($file); $deleted[] = $file;
		}
		$cfg        = build_base::config();
		$namespaces = [(string)($cfg['defaultNS'] ?? 'app')];
		foreach (reflect::sourceFiles() as $file){
			if (!is_file($file)) continue;
			foreach (explode(lf, (string)file_get_contents($file)) as $line){
				if (preg_match('/^\s*ns:\s*([A-Za-z_]\w*)/', $line, $m)) $namespaces[] = $m[1];
			}
		}
		$wwwBase = rtrim($wwwDir, slash).slash;
		foreach (array_unique($namespaces) as $ns){
			foreach (["$ns.css", "$ns.js"] as $filename){
				$path = $wwwBase.$filename;
				if (is_file($path)){ unlink($path); $deleted[] = $path; }
			}
		}
		return $deleted;
	}

	private static function sourceFileMap(string $mode):array {
		$files = [];
		if ($mode === 'available'){
			foreach (reflect::availableResources() as $resource){
				if (!empty($resource['loaded'])) continue;
				$file = ($resource['file'] ?? '');
				if ($file && is_file($file)) $files[static::sourceFileKey($file)] = $file;
			}
		}
		elseif ($mode === 'resources'){
			foreach (reflect::availableResources() as $resource){
				if (empty($resource['loaded'])) continue;
				$file = ($resource['file'] ?? '');
				if ($file && is_file($file)) $files[static::sourceFileKey($file)] = $file;
			}
		}
		else {
			foreach (reflect::sourceFiles() as $file) $files[static::sourceFileKey($file)] = $file;
		}
			uksort($files, static fn($a, $b) => strnatcasecmp(basename($a), basename($b)) ?: strnatcasecmp($a, $b));
			return $files;
		}

	private static function sourceFileKey(string $file):string {
		$clean   = str_replace(bs, slash, $file);
		$appRoot = defined('app') ? rtrim(str_replace(bs, slash, app), slash).slash : '';
		$resRoot = defined('engine') ? rtrim(str_replace(bs, slash, engine), slash).'/resources/' : '';
		if ($appRoot && str_starts_with($clean, $appRoot)) return substr($clean, strlen($appRoot));
		if ($resRoot && str_starts_with($clean, $resRoot)) return substr($clean, strlen($resRoot));
		return basename($clean);
	}

	private static function buildFileMap(array $allFiles):array {
		$phpFiles   = [];
		$assetFiles = [];
		foreach (($allFiles['php'] ?? []) as $file){
			$phpFiles[basename($file)] = $file;
		}
		foreach (($allFiles['www'] ?? []) as $file){
			$assetFiles[basename($file)] = $file;
		}
		ksort($phpFiles, SORT_NATURAL | SORT_FLAG_CASE);
		ksort($assetFiles, SORT_NATURAL | SORT_FLAG_CASE);
		return $phpFiles + $assetFiles;
	}

	private static function dashboardFileLink(string $file):string {
		// $file may be "shortpath:line" as stored by phlo_error_log
		$line = 0;
		if (preg_match('/^(.+):(\d+)$/', $file, $m)){
			$file = $m[1];
			$line = (int)$m[2];
		}
		$rawLabel = $file.($line > 0 ? ":$line" : '');
		$label    = esc($rawLabel);
		$anchor   = $line > 0 ? "#L$line" : '';
		$base  = slash.control;
		$clean = str_replace(bs, slash, $file);

		$link = static::dashboardFileTarget($clean);
		if ($link) return "<a href=\"".esc("$base$link$anchor")."\" data-nav=\"file\">$label</a>";
		return $label;
	}

	private static function dashboardFileTarget(string $file):?string {
		$full = static::resolveFilePath($file);
		if (!$full) return null;
		$clean = str_replace(bs, slash, $full);

		if (str_ends_with($clean, '.phlo')){
			$key = static::sourceFileKey($clean);
			foreach (['app', 'resources', 'available'] as $scope){
				if (isset(static::sourceFileMap($scope)[$key])) return '/source/'.rawurlencode($key);
			}
			return null;
		}

		$buildMap = static::buildFileMap(build::buildFiles());
		$key = array_search($full, $buildMap, true);
		if ($key !== false) return '/build/view/'.rawurlencode($key);
		return null;
	}

	private static function resolveFilePath(string $file):?string {
		$file = str_replace(bs, slash, $file);
		if (is_file($file)) return $file;
		if (defined('app')){
			$try = rtrim(str_replace(bs, slash, app), slash).slash.basename($file);
			if (is_file($try)) return $try;
		}
		if (defined('php')){
			$try = rtrim(str_replace(bs, slash, php), slash).slash.basename($file);
			if (is_file($try)) return $try;
		}
		if (defined('www')){
			$try = rtrim(str_replace(bs, slash, www), slash).slash.basename($file);
			if (is_file($try)) return $try;
		}
		if (defined('engine')){
			$try = rtrim(str_replace(bs, slash, engine), slash).'/resources/'.$file;
			if (is_file($try)) return $try;
		}
		if (defined('engine')){
			$try = rtrim(str_replace(bs, slash, engine), slash).slash.$file;
			if (is_file($try)) return $try;
		}
		if (str_starts_with($file, 'resources/') && defined('engine')){
			$try = rtrim(str_replace(bs, slash, engine), slash).slash.$file;
			if (is_file($try)) return $try;
		}
		if (str_starts_with($file, 'phlo/resources/') && defined('engine')){
			$try = rtrim(str_replace(bs, slash, engine), slash).slash.substr($file, 5);
			if (is_file($try)) return $try;
		}
		if (str_starts_with($file, 'php/') && defined('php')){
			$try = rtrim(str_replace(bs, slash, php), slash).slash.substr($file, 4);
			if (is_file($try)) return $try;
		}
		if (str_starts_with($file, 'www/') && defined('www')){
			$try = rtrim(str_replace(bs, slash, www), slash).slash.substr($file, 4);
			if (is_file($try)) return $try;
		}
		$bases = [];
		if (defined('app'))    $bases[] = rtrim(str_replace(bs, slash, app), slash).slash;
		if (defined('php'))    $bases[] = rtrim(str_replace(bs, slash, php), slash).slash;
		if (defined('www'))    $bases[] = rtrim(str_replace(bs, slash, www), slash).slash;
		if (defined('engine')) $bases[] = rtrim(str_replace(bs, slash, engine), slash).slash;
		foreach ($bases as $b){
			if (is_file($b.$file)) return $b.$file;
		}
		return null;
	}

	private static function resourceGroupsHtml(array $groups, string $empty):string {
		if (!$groups) return '<p class="muted">'.esc($empty).'</p>';
		$html = void;
		foreach ($groups as $group => $items){
			$count       = count($items);
			$loadedCount = count(array_filter($items, fn($i) => !empty($i['loaded'])));
			$groupName   = esc($group);
			$html .= "<section class=\"resource-group collapsed\">\n"
				."<header><span class=\"rg-caret\"></span><strong>$groupName</strong><span class=\"rg-count\">$loadedCount / $count</span></header>\n"
				."<div class=\"resource-list\">\n";
			foreach ($items as $item){
				$id      = $item['id'];
				$loaded  = !empty($item['loaded']);
				$rowCls  = $loaded ? ' loaded' : ' unselected';
				$btnCls  = $loaded ? ' on' : '';
				$btnTxt  = $loaded ? 'on' : 'off';
				$iName   = esc($item['name']);
				$iMeta   = esc($item['meta']);
				$iUrl    = esc($item['url']);
				$summary = $item['summary'] !== void ? '<p>'.esc($item['summary']).'</p>' : void;
				$html .= "<article id=\"{$id}-row\" class=\"resource-item$rowCls\">\n"
					."<form method=\"post\" action=\"$iUrl\">"
					."<input type=\"hidden\" name=\"button_id\" value=\"$id\">"
					."<button id=\"$id\" type=\"submit\" class=\"toggle$btnCls\">$btnTxt</button>"
					."</form>\n"
					."<div><strong>$iName</strong><span>$iMeta</span>$summary</div>\n"
					."</article>\n";
			}
			$html .= "</div>\n</section>\n";
		}
		return $html;
	}

	private static function searchFiles(array $fileMap, string $q, int $max = 60):array {
		$hits = [];
		foreach ($fileMap as $name => $path){
			if (!is_file($path)) continue;
			$lines = explode(lf, (string)file_get_contents($path));
			foreach ($lines as $i => $line){
				if (stripos($line, $q) === false) continue;
				$hits[] = ['name' => $name, 'line' => $i + 1, 'snippet' => trim($line)];
				if (count($hits) >= $max) return $hits;
			}
		}
		return $hits;
	}

	private static function searchResultsHtml(array $hits, string $q, string $baseUrl, array $query = [], string $closeUrl = void):string {
		$rows = void;
		foreach ($hits as $hit){
			$name    = (string)($hit['name'] ?? '');
			$line    = (int)($hit['line'] ?? 0);
			$href    = rtrim($baseUrl, slash).slash.rawurlencode($name);
			if ($query) $href .= '?'.http_build_query($query);
			$href   .= "#L$line";
			$hrefE   = esc($href);
			$nameE   = esc($name);
			$snippet = esc(trim((string)($hit['snippet'] ?? '')));
			$rows   .= "<tr class=\"row\"><td><a href=\"$hrefE\" data-nav=\"file\">$nameE</a></td><td class=\"num\">$line</td><td class=\"code muted src-snippet\">$snippet</td></tr>\n";
		}
		if (!$rows) $rows = '<tr class="row"><td colspan="3" class="muted">'.($q ? 'No results for "'.esc($q).'"' : 'Enter a search term')."</td></tr>\n";
		$close = $closeUrl ? "<a href=\"".esc($closeUrl)."\" class=\"close-panel\" data-nav=\"file\">Close</a>" : '';
		return "<div class=\"dash-results-full\">\n"
			."<div class=\"dash-search-panel-head\"><span>Search results</span>$close</div>\n"
			."<div class=\"dash-search-table\">\n"
			."<table>\n"
			."<thead><tr><th>File</th><th>Line</th><th>Snippet</th></tr></thead>\n"
			."<tbody>\n$rows</tbody>\n"
			."</table>\n"
			."</div>\n"
			."</div>\n";
	}

	private static function fileApply(string $file, string $name, string $section, bool $php = false):void {
		$sectionTitle = ucfirst($section);
		$fname        = static::displayFileName($name);
		apply(
			path: ltrim(phlo('req')->path, slash),
			pathReplace: true,
			title: "$sectionTitle - $fname - Phlo Control",
			inner: ['#file-content' => static::fileContent($file, $php)],
			call: 'dashActiveFile',
		);
	}

	private static function displayFileName(string $name):string {
		return basename(str_replace(bs, slash, $name));
	}

	private static function filePicker(array $files, string $active, string $baseUrl):string {
		$html = void;
		[$urlBase, $urlQuery] = array_pad(explode('?', $baseUrl, 2), 2, null);
		$qs = $urlQuery ? "?$urlQuery" : '';
		foreach ($files as $name){
			$href    = rtrim($urlBase, '/').'/'.rawurlencode($name).$qs;
			$hrefE   = esc($href);
			$nameE   = esc($name);
			$display = esc(static::displayFileName($name));
			$cls     = $name === $active ? ' class="active"' : '';
			$html   .= "<a href=\"$hrefE\"$cls data-file-key=\"$nameE\" data-nav=\"file\">$display</a>\n";
		}
		return $html;
	}

	private static function fileContent(string $file, bool $php = false):string {
		if (!$file || !is_file($file)) return '<p class="muted">File not found.</p>';
		$src = (string)file_get_contents($file);
		if ($php){
			$save = [];
			foreach (['default', 'comment', 'keyword', 'string', 'html'] as $k)
				$save[$k] = ini_get('highlight.'.$k);
			ini_set('highlight.default', '#e6edf3');
			ini_set('highlight.comment', '#8b949e');
			ini_set('highlight.keyword', '#ff7b72');
			ini_set('highlight.string',  '#a5d6ff');
			ini_set('highlight.html',    '#e6edf3');
			$hi = highlight_string($src, true);
			foreach ($save as $k => $v) ini_set('highlight.'.$k, $v);
			$hi = preg_replace('/^<code[^>]*>(.*)<\/code>$/s', '$1', trim($hi));
			$hi = preg_replace('/<br\\s*\\/?>/i', "\n", $hi);
			$lines = explode("\n", $hi);
			$items = void;
			foreach ($lines as $i => $line)
				$items .= '<li id="L'.($i + 1).'">'.$line.'</li>';
			return '<ol class="code-lines php-src">'.$items.'</ol>';
		}
		$lines = explode("\n", rtrim($src));
		$items = void;
		foreach ($lines as $i => $line)
			$items .= '<li id="L'.($i + 1).'">'.esc(rtrim($line, "\r")).'</li>';
		return '<ol class="code-lines '.($php ? 'php-src' : 'phlo-src').'">'.$items.'</ol>';
	}

	private static function render(string $body, string $active, string $subtitle = void):void {
		$req  = phlo('req');
		$res  = phlo('res');
		$base = control;
		$cfg  = build_base::config();
		$title = $active === 'home' ? 'Phlo Control' : ($subtitle ? ucfirst($active)." - $subtitle - Phlo Control" : ucfirst($active).' - Phlo Control');

		$sections = ['home'];
		if (is_dir(data.'tasks/')) $sections[] = 'tasks';
		$sections = array_merge($sections, ['config', 'graph', 'source', 'build']);
		if (!empty($cfg['release'])) $sections[] = 'release';
		$sections[] = 'errors';

		$labels = [
			'home' => 'Home', 'config' => 'Config', 'graph' => 'Graph', 'source' => 'Source',
			'build' => 'Build', 'release' => 'Release', 'errors' => 'Errors', 'tasks' => 'Tasks',
		];

		$nav = void;
		foreach ($sections as $s){
			$href = $s === 'home' ? '/'.ltrim($base, '/') : '/'.ltrim("$base/$s", '/');
			$cls  = $s === $active ? ' class="active"' : '';
			$lbl  = esc($labels[$s] ?? ucfirst($s));
			$nav .= "<a href=\"".esc($href)."\"$cls>$lbl</a>\n";
		}

		$themes = [];
		foreach (glob(www.'theme.*.css') ?: [] as $tf){
			if (preg_match('/^theme\.(.+)\.css$/', basename($tf), $m)) $themes[] = $m[1];
		}
		sort($themes);
		$themeSel = (string)($_COOKIE['phlo_ctl_theme'] ?? '');
		if (!in_array($themeSel, $themes, true)) $themeSel = '';
		if ($themes){
			$tBase = '/'.ltrim($base.'/theme', '/');
			$links = '<a href="'.esc($tBase).'" class="async ctl-theme'.($themeSel === '' ? ' is-active' : '').'" data-theme="">Default</a>';
			foreach ($themes as $t){
				$links .= '<a href="'.esc($tBase.'/'.$t).'" class="async ctl-theme'.($t === $themeSel ? ' is-active' : '').'" data-theme="'.esc($t).'">'.esc(ucfirst($t)).'</a>';
			}
			$nav .= '<div class="ctl-theme-tool">'
				.'<button type="button" class="ctl-theme-btn" title="Theme" aria-label="Theme"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" stroke="none"/></svg><span>Theme</span></button>'
				.'<div class="ctl-theme-pop">'.$links.'</div>'
				."</div>\n";
		}

		if ($req->async){
			$renderPath = ltrim($req->path, slash);
			$renderQuery = array_filter((array)($req->query ?? []));
			if ($renderQuery) $renderPath .= '?'.http_build_query($renderQuery);
			apply(path: $renderPath, title: $title, inner: ['#dash-top-nav' => $nav], main: $body);
			return;
		}

		$jsBase = '/'.ltrim("$base/", '/');
		$titleE = esc($title);
		$themeLink = $themeSel !== '' ? "<link id=\"ctl-theme\" rel=\"stylesheet\" href=\"/theme.".rawurlencode($themeSel).".css\">\n" : '';

		$head = "<meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
			."<title>$titleE</title>\n"
			."<link rel=\"stylesheet\" href=\"{$jsBase}control.css\">\n"
			.$themeLink
			."<script defer src=\"{$jsBase}control.js\"></script>\n";

		$page = "<nav id=\"dash-top-nav\">\n{$nav}</nav>\n"
			.rtrim($body, "\n");
		$res->type = 'text/html; charset=UTF-8';
		$res->body = DOM($page, $head);
	}

	private static function tasks(?string $arg):void {
		$dir   = data.'tasks/';
		$tasks = is_dir($dir) ? array_diff(scandir($dir), ['.', '..']) : [];
		$names = [];
		foreach ($tasks as $f){
			if (preg_match('/^(.+)\.(last|json)$/', $f, $m)) $names[$m[1]] = true;
		}
		ksort($names);

		$rows = void;
		foreach (array_keys($names) as $name){
			$last     = is_file($dir.$name.'.last') ? (int)file_get_contents($dir.$name.'.last') : 0;
			$lastAgo  = $last ? static::ago($last) : 'never';
			$lastFull = $last ? date('Y-m-d H:i:s', $last) : 'never';
			$lock     = is_file($dir.$name.'.lock');
			$lockAge  = $lock ? (time() - filemtime($dir.$name.'.lock')) : 0;
			$run      = is_file($dir.$name.'.json') ? json_read($dir.$name.'.json', true) : null;
			$return   = is_array($run) && array_key_exists('return', $run) ? $run['return'] : null;
			$schedule = is_array($run) ? ($run['schedule'] ?? []) : [];
			$do       = is_array($run) && !empty($run['do']) ? $run['do'] : null;
			$schedLbl = static::scheduleLabel((array)$schedule);
			$doHtml   = $do ? " <code class=\"muted\">".esc($do)."</code>" : '';
			$lockHtml = $lock ? " <span class=\"dash-pill warn\">running · {$lockAge}s</span>" : '';
			$rows .= "<div class=\"dash-card\">\n"
				."<div class=\"dash-card-head\"><strong>".esc($name)."</strong>$doHtml <span class=\"muted\">".esc($schedLbl)."</span>$lockHtml</div>\n"
				."<div class=\"dash-card-body\">\n"
				."<div class=\"dash-stats\">\n"
				."<div class=\"dash-stat\"><strong>".esc($lastAgo)."</strong><span title=\"".esc($lastFull)."\">last run</span></div>\n"
				.static::returnStats($return)
				."</div>\n"
				.static::returnDetail($return)
				."</div>\n"
				."</div>\n";
		}
		if (!$rows) $rows = "<div class=\"dash-card\"><div class=\"dash-card-body muted\">No tasks have run yet.</div></div>";

		$body = "<main class=\"dash-main\">\n"
			."<header class=\"dash-hero\"><div class=\"dash-badge\">Tasks</div><h1>Scheduled tasks</h1><p>State from <code>data/tasks/</code></p></header>\n"
			."<div class=\"dash-grid\">\n$rows</div>\n"
			."</main>\n";
		static::render($body, 'tasks');
	}

	private static function scheduleLabel(array $schedule):string {
		foreach (['every', 'daily', 'weekly'] as $key){
			if (isset($schedule[$key])) return "$key {$schedule[$key]}";
		}
		return '(no schedule)';
	}

	private static function returnStats($return):string {
		if (is_bool($return)) return "<div class=\"dash-stat\"><strong>".($return ? 'true' : 'false')."</strong><span>return</span></div>\n";
		if (is_int($return) || is_float($return)) return "<div class=\"dash-stat\"><strong>".esc((string)$return)."</strong><span>return</span></div>\n";
		if (is_string($return) && strlen($return) <= 40) return "<div class=\"dash-stat\"><strong>".esc($return)."</strong><span>return</span></div>\n";
		if (is_null($return)) return "<div class=\"dash-stat\"><strong>null</strong><span>return</span></div>\n";
		if (is_array($return)) return "<div class=\"dash-stat\"><strong>".count($return)."</strong><span>items</span></div>\n";
		return '';
	}

	private static function returnDetail($return):string {
		if ($return === null || is_scalar($return) && (is_bool($return) || strlen((string)$return) <= 40)) return '';
		if (is_string($return)) return "<pre class=\"code\">".esc($return)."</pre>\n";
		return "<pre class=\"code\">".esc(json_encode($return, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."</pre>\n";
	}

	private static function ago(int $ts):string {
		$d = time() - $ts;
		if ($d < 60) return "{$d}s ago";
		if ($d < 3600) return floor($d/60)."m ago";
		if ($d < 86400) return floor($d/3600)."h ago";
		return floor($d/86400).'d ago';
	}
}
