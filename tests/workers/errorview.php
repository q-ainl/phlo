<?php
// Worker for ErrorViewTest: defines a stub `app` class with a custom errorPage so the
// app error-view hook can be exercised in isolation, without leaking a global `class app`
// into the in-process suite. Outputs the rendered fragments as JSON.
require __DIR__.'/../bootstrap.php';

class app {
	public static string $mode = 'ok';
	public static function errorPage(int $code, string $id):string {
		if (self::$mode === 'throw') throw new \Exception('errorPage itself broke');
		if (self::$mode === 'empty') return '';
		return "<main class=\"custom\">Sorry, error $code (ref $id)</main>";
	}
}

$id = phlo_error_id('host', 'a.phlo:1', 'boom');

app::$mode = 'ok';    $hookOk    = phlo_error_app_html(503, $id);
app::$mode = 'throw'; $hookThrow = phlo_error_app_html(503, $id);
app::$mode = 'empty'; $hookEmpty = phlo_error_app_html(503, $id);

echo json_encode([
	'id'        => $id,
	'idLen'     => strlen($id),
	'hookOk'    => $hookOk,
	'hookThrow' => $hookThrow,
	'hookEmpty' => $hookEmpty,
	'minimal'   => phlo_error_render_minimal(503, $id),
	'bare'      => phlo_error_bare_html(503, $id),
]);
