<?php
if (PHP_SAPI !== 'cli' && !defined('PHLO_INSTALL_LIB')) exit("Run install.php from the command line.\n");

function ask(string $question, string $default = ''):string {
	$suffix = $default !== '' ? " [$default]" : '';
	fwrite(STDOUT, $question.$suffix.': ');
	$answer = trim((string)fgets(STDIN));
	return $answer !== '' ? $answer : $default;
}

function fail(string $msg):never {
	fwrite(STDERR, "ERROR: $msg\n");
	exit(1);
}

function engine_dir(string $self):string {
	$dir = dirname($self);
	if (is_file($dir.'/phlo.php') && is_dir($dir.'/classes')) return $dir;
	$env = (string)getenv('PHLO_ENGINE');
	foreach ([$env, '/phlo', '/srv/control/phlo', '/srv/phlo'] as $try){
		if ($try && is_file($try.'/phlo.php') && is_dir($try.'/classes')) return rtrim($try, '/');
	}
	$try = rtrim(ask('Path to the Phlo engine'), '/');
	if (!is_file($try.'/phlo.php')) fail("No Phlo engine found at $try");
	return $try;
}

function resource_catalog(string $engine):array {
	$catalog = [];
	$base    = $engine.'/resources/';
	$rii     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
	foreach ($rii as $file){
		$path = $file->getPathname();
		if (!str_ends_with($path, '.phlo')) continue;
		$key  = substr(str_replace('\\', '/', substr($path, strlen($base))), 0, -5);
		$meta = ['summary' => '', 'package' => str_contains($key, '/') ? dirname($key) : 'general', 'requires' => [], 'class' => null, 'functions' => []];
		foreach (file($path) as $line){
			$line = trim($line);
			if ($line === '' || $line[0] !== '@') break;
			if (preg_match('/^@\s*summary:\s*(.+)$/', $line, $m)) $meta['summary'] = trim($m[1]);
			if (preg_match('/^@\s*package:\s*(.+)$/', $line, $m)) $meta['package'] = trim($m[1]);
			if (preg_match('/^@\s*requires:\s*(.+)$/', $line, $m)){
				foreach (preg_split('/[\s,]+/', trim($m[1])) as $req){
					$req = ltrim($req, '@');
					if ($req === '' || str_ends_with($req, '?') || str_starts_with($req, 'php-ext:') || str_starts_with($req, 'creds:')) continue;
					$meta['requires'][] = $req;
				}
			}
		}
		$content = (string)file_get_contents($path);
		if (preg_match('/^@\s*class:\s*(.+)$/m', $content, $cm)) $meta['class'] = trim($cm[1]);
		elseif (preg_match('/^\s*(?:(?:public|protected|private)\s+)?(?:static|method|prop|view|const|readonly)\b/m', $content)) $meta['class'] = strtr(basename($key), '.', '_');
		preg_match_all('/^function\s+([A-Za-z_]\w*)/m', $content, $fm);
		$meta['functions'] = $fm[1] ?? [];
		$catalog[$key] = $meta;
	}
	ksort($catalog, SORT_NATURAL | SORT_FLAG_CASE);
	return $catalog;
}

function print_catalog(array $catalog):void {
	$groups = [];
	foreach ($catalog as $key => $meta){
		$dir = str_contains($key, '/') ? explode('/', $key)[0] : 'general';
		$groups[$dir][$key] = $meta;
	}
	ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
	fwrite(STDOUT, "\nAvailable resources:\n");
	foreach ($groups as $group => $items){
		if (in_array($group, ['themes', 'transitions', 'loaders'], true)){
			$names = array_map(fn($k) => basename($k), array_keys($items));
			fwrite(STDOUT, sprintf("  %-12s %s\n", $group.':', implode(', ', $names)));
			continue;
		}
		fwrite(STDOUT, "  $group:\n");
		foreach ($items as $key => $meta){
			fwrite(STDOUT, sprintf("    %-28s %s\n", $key, $meta['summary']));
		}
	}
	fwrite(STDOUT, "\n");
}

function resolve_resources(string $input, array $catalog):array {
	if (trim($input) === '') return [];
	// Alias index: resolve a name by exact path, then a unique class, function or
	// basename match (mirroring reflect). Class names use the compiled form, so dotted
	// resources resolve by their time_human / JSON_result names too.
	$byClass = $byFunc = $byBase = [];
	foreach ($catalog as $key => $meta){
		$byBase[strtolower(basename($key))][] = $key;
		if (!empty($meta['class'])) $byClass[strtolower($meta['class'])][] = $key;
		foreach ($meta['functions'] ?? [] as $fn) $byFunc[strtolower($fn)][] = $key;
	}
	$resolve = function(string $name, bool $strict) use ($catalog, $byClass, $byFunc, $byBase):?string {
		if (isset($catalog[$name])) return $name;
		$k = strtolower($name);
		foreach ([$byClass, $byFunc, $byBase] as $map){
			$hits = $map[$k] ?? [];
			if (count($hits) === 1) return $hits[0];
			if (count($hits) > 1){ if ($strict) fail("Ambiguous resource \"$name\": ".implode(', ', $hits)); return null; }
		}
		if ($strict) fail("Unknown resource \"$name\"");
		return null;
	};
	$picked = [];
	foreach (explode(',', $input) as $name){
		$name = trim($name);
		if ($name !== '' && ($key = $resolve($name, true))) $picked[] = $key;
	}
	$resolved = [];
	$queue    = $picked;
	while ($queue){
		$key = array_shift($queue);
		if (isset($resolved[$key])) continue;
		$resolved[$key] = true;
		foreach ($catalog[$key]['requires'] ?? [] as $req){
			if ($dep = $resolve($req, false)) $queue[] = $dep;
		}
	}
	return array_keys($resolved);
}

// Reuse the resolver/catalog functions above (e.g. from the parity test) without running
// the interactive installer below.
if (defined('PHLO_INSTALL_LIB')) return;

$self   = realpath(__FILE__);
$engine = engine_dir($self);
$target = isset($argv[1]) ? rtrim($argv[1], '/') : rtrim(getcwd(), '/');
if (realpath($target) === realpath($engine)) fail('Give a target directory: php install.php /path/to/new-app/');
foreach (['app.phlo', 'www/app.php', 'data/app.json'] as $existing){
	if (file_exists("$target/$existing")) fail("$target/$existing already exists; refusing to overwrite an app");
}

fwrite(STDOUT, "Phlo app installer (engine: $engine)\n\n");
$base    = basename($target);
$name    = ask('App name', ucfirst(str_contains($base, '.') ? explode('.', $base)[0] : $base));
$host    = ask('Host', str_contains($base, '.') ? $base : "$base.test");
$purpose = ask('Purpose (one line)', 'A Phlo app');

$catalog = resource_catalog($engine);
print_catalog($catalog);
$resources = resolve_resources(ask('Resources (comma-separated, empty for none)'), $catalog);
$resources && fwrite(STDOUT, 'Selected (with requirements): '.implode(', ', $resources)."\n");

fwrite(STDOUT, "\nCreate \"$name\" in $target for host $host? ");
if (!in_array(strtolower(trim((string)fgets(STDIN))), ['y', 'yes', 'j', 'ja'], true)) fail('Aborted');

foreach (["$target/www", "$target/data", "$target/php"] as $dir){
	if (!is_dir($dir) && !mkdir($dir, 0775, true)) fail("Could not create $dir");
}

$purposeHtml = htmlspecialchars(strtr($purpose, ["\n" => ' ', "\r" => '']), ENT_QUOTES);
$nameEsc     = strtr($name, ["'" => "\\'"]);

file_put_contents("$target/www/app.php", "<?php
require '$engine/phlo.php';

phlo_app(
	id:    '$nameEsc',
	host:  '$host',
	build: true,
	debug: true,
	app:   dirname(__DIR__).'/',
);
");

file_put_contents("$target/data/app.json", json_encode(['resources' => $resources], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

file_put_contents("$target/data/app.md", "# $name

$purpose

## Routes
- GET / -> home page

## TODO
- [ ] Replace the placeholder home view
");

file_put_contents("$target/app.phlo", "@ summary: $purpose

prop title = '$nameEsc'

app::route()

route GET => \$this->home

method home => view(\$this->main, 'Home')

view main:
<main.wrap>
<h1>\$this->title</h1>
<p>$purposeHtml</p>
</main>

<style>
:root {
	\$bg: #0d0d0d
	\$fg: #f2f2f2
}
body {
	background: \$bg
	color: \$fg
	font-family: system-ui, sans-serif
	margin: 0
}
main.wrap {
	max-width: 720px
	margin: 10vh auto
	padding: 0 24px
}
</style>
");

file_put_contents("$target/.gitignore", "php/
www/app.css
www/app.js
data/errors.json
data/trace/
");

fwrite(STDOUT, "\nBuilding...\n");
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg("$target/www/app.php").' build::run 2>&1', $out, $code);
if ($code !== 0) fail("Build failed:\n".implode("\n", $out));
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg("$target/www/app.php").' build::lint 2>&1', $lint, $code);
if ($code !== 0 || trim(implode('', $lint)) !== '[]') fail("Lint failed:\n".implode("\n", $lint));

fwrite(STDOUT, "Build clean.\n\nNext steps:\n");
fwrite(STDOUT, "  - Serve $target/www/ for host $host (FrankenPHP/Caddy), e.g.:\n");
fwrite(STDOUT, "      $host {\n          root * $target/www\n          @notStatic not file\n          rewrite @notStatic /app.php\n          php_server\n      }\n");
fwrite(STDOUT, "  - Quick check without a server: php $target/www/app.php reflect::context\n");
fwrite(STDOUT, "  - App notes for agents live in data/app.md\n");

if ($self !== "$engine/install.php" && @unlink($self)) fwrite(STDOUT, "\ninstall.php removed itself.\n");
fwrite(STDOUT, "\nDone.\n");
