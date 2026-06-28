<?php
// The CMS is a separate repo; its location is configurable via PHLO_CMS_PATH (default
// /srv/control/CMS) so this BI fixture is not tied to a fixed /srv layout. Generate the
// build's app.json from the template, pointing paths.resources at the resolved CMS path.
$cms  = rtrim(getenv('PHLO_CMS_PATH') ?: '/srv/control/CMS', '/').'/';
$data = dirname(__DIR__).'/data/';
file_put_contents($data.'app.json', str_replace('__CMS_PATH__', $cms, file_get_contents($data.'app.json.dist')));

require dirname(__DIR__, 4).'/phlo.php';

phlo_app(
	id:    'CMSBITEST',
	host:  'localhost',
	build: true,
	debug: false,
	app:   dirname(__DIR__).'/',
);
