<?php

class build extends build_base {

	/** Compiles all changed .phlo source files. Returns an array of changed file paths. */
	public static function run(bool $release = false, bool $runHooks = true):array {
		static::requireEnabled('run');
		$config = static::sources($release)['build'];
		$hooks  = $release ? ($config['release'] ?? []) : $config;
		if ($runHooks) static::runHooks($hooks, 'runBefore');
		require_once __DIR__.slash.'file.php';
		require_once __DIR__.slash.'node.php';
		require_once __DIR__.slash.'builder.php';
		require_once __DIR__.slash.'css.php';
		$builder = new build_builder(static::sources($release), true, $release);
		if ($builder->changed) putenv('PHLO_CHANGED='.implode(comma, $builder->changed));
		if ($runHooks) static::runHooks($hooks, 'runAfter');
		return $builder->changed;
	}

	/** Compiles a release build with release hooks. Returns an array of changed file paths. */
	public static function release(bool $runHooks = true):array {
		static::requireEnabled('release');
		return static::run(true, $runHooks);
	}

	/** Deletes compiled PHP from php/ and the build's own www/ namespace bundles (<ns>.css/.js); vendored assets (e.g. chart.js) are left untouched. Returns an array of deleted filenames. */
	public static function flush():array {
		static::requireEnabled('flush');
		$deleted = [];
		foreach (glob(php.'*.php') ?: [] as $file){
			unlink($file);
			$deleted[] = dash.basename($file);
		}
		require_once __DIR__.slash.'file.php';
		require_once __DIR__.slash.'node.php';
		require_once __DIR__.slash.'builder.php';
		$builder = new build_builder(static::sources(false), false);
		foreach ($builder->namespaces() as $ns){
			foreach ([www.$ns.'.css', www.$ns.'.js'] as $file){
				if (!is_file($file)) continue;
				unlink($file);
				$deleted[] = dash.basename($file);
			}
		}
		return $deleted;
	}

	/** Returns lists of compiled PHP and web output files for the current build. */
	public static function buildFiles():array {
		return static::outputFiles(php, www);
	}

	/** Returns lists of compiled PHP and web output files for the release build. */
	public static function releaseFiles():array {
		$config  = static::sources(true)['build'];
		$release = $config['release'] ?? [];
		$phpDir  = rtrim($release['php'] ?? (app.'release/'), slash).slash;
		$wwwDir  = rtrim($release['www'] ?? (app.'release/www/'), slash).slash;
		return static::outputFiles($phpDir, $wwwDir);
	}

	/** Lints all compiled PHP files in a single process. Returns an array of parse errors, empty if all clean. */
	public static function lint():array {
		$files = static::buildFiles()['php'];
		if (!$files) return [];
		exec(cli.' -l '.implode(space, array_map('escapeshellarg', $files)).' 2>&1', $out, $code);
		if ($code === 0) return [];
		$errors = [];
		foreach ($out as $line){
			if (!preg_match('/^(?:PHP\s+)?(Parse|Fatal) error:\s*(.+) in (.+) on line (\d+)/', $line, $m)) continue;
			$errors[] = ['file' => $m[3], 'line' => (int)$m[4], 'error' => trim($m[2])];
		}
		return $errors;
	}

	/** Regenerates the engine's functions.trace.php from functions.php by injecting trace() as first statement of every function. */
	public static function traceShadow():array {
		$sourceFile = engine.'functions.php';
		$shadowFile = engine.'functions.trace.php';
		if (!is_file($sourceFile)) error('functions.php not found at '.$sourceFile);
		$tokens = token_get_all((string)file_get_contents($sourceFile));
		$n      = count($tokens);
		$out    = void;
		$i      = 0;
		$count  = 0;
		while ($i < $n){
			$tok = $tokens[$i];
			if (is_array($tok) && $tok[0] === T_FUNCTION){
				$j = $i + 1;
				while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
				if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING){
					$fnName = $tokens[$j][1];
					$params = [];
					while ($i <= $j) $out .= self::tokStr($tokens[$i++]);
					$depth = 0;
					while ($i < $n){
						$t = $tokens[$i];
						$out .= self::tokStr($t);
						if ($t === '('){ $depth++; }
						elseif ($t === ')'){ $depth--; if ($depth === 0){ $i++; break; } }
						elseif ($depth === 1 && is_array($t) && $t[0] === T_VARIABLE) $params[] = substr($t[1], 1);
						$i++;
					}
					while ($i < $n){
						$t = $tokens[$i];
						$out .= self::tokStr($t);
						$i++;
						if ($t === '{') break;
					}
					$argsStr = $params ? ', compact('.implode(', ', array_map(fn($p) => "'$p'", $params)).')' : void;
					$out .= "\n\ttrace('$fnName'$argsStr);";
					while ($i < $n && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) $i++;
					if ($i < $n && $tokens[$i] !== '}') $out .= "\n\t";
					$depth = 1;
					while ($i < $n){
						$t = $tokens[$i];
						if ($t === '{') $depth++;
						elseif ($t === '}'){
							$depth--;
							if ($depth === 0){
								$out  = rtrim($out, " \t");
								$out .= (str_ends_with($out, lf) ? void : lf).'}';
								$i++;
								break;
							}
						}
						$out .= self::tokStr($t);
						$i++;
					}
					$count++;
					continue;
				}
			}
			$out .= self::tokStr($tok);
			$i++;
		}
		file_put_contents($shadowFile, $out);
		return ['file' => $shadowFile, 'functions' => $count, 'bytes' => strlen($out)];
	}

	/** Returns all available CLI methods with their signatures and descriptions. */
	public static function help():array {
		return phlo_help_reflect(static::class);
	}

	private static function tokStr(string|array $t):string {
		return is_array($t) ? $t[1] : $t;
	}

	private static function requireEnabled(string $fn):void {
		if (!build) error('Build error: "'.$fn.'" is unavailable when build=false');
	}

	private static function runHooks(array $config, string $event):void {
		foreach ($config[$event] ?? [] as $cmd){
			exec($cmd, $out, $code);
			if ($code) error('Hook "'.$event.'" failed: '.$cmd.lf.implode(lf, $out));
		}
	}

	private static function outputFiles(string $phpDir, string $wwwDir):array {
		$php = [];
		if (is_dir($phpDir)) foreach (glob(rtrim($phpDir, slash).slash.'*.php') ?: [] as $file) $php[] = $file;
		$w   = [];
		if (is_dir($wwwDir)){
			$dir = rtrim($wwwDir, slash).slash;
			foreach (glob($dir.'*.css') ?: [] as $file) $w[] = $file;
			foreach (glob($dir.'*.js') ?: [] as $file) $w[] = $file;
		}
		natcasesort($php);
		natcasesort($w);
		return ['php' => array_values($php), 'www' => array_values(array_unique($w))];
	}
}
