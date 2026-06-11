# Deploying Phlo

Phlo needs PHP >= 8.3 and a web server that routes requests to `www/app.php`.
Everything else is optional and per-resource: `pdo_mysql`/`pdo_pgsql`/`sqlite3`
for the DB resources, `gd` for icon sprites, `sodium` (bundled with PHP) for
the encryption resource.

Scaffold an app first (see `install.php` in the engine root):

```bash
php /path/to/phlo/install.php /path/to/my-app/
```

## Option A: FrankenPHP on a bare server (recommended)

[FrankenPHP](https://frankenphp.dev) is a single binary that bundles PHP and
Caddy. This is the setup Phlo is developed against.

```bash
curl https://frankenphp.dev/install.sh | sh
mv frankenphp /usr/local/bin/
```

Caddyfile (automatic HTTPS included):

```
{
	frankenphp
}

example.com {
	root * /path/to/my-app/www
	encode zstd gzip
	php_server
}
```

Run `frankenphp run --config Caddyfile`, or install it as a systemd service
(`frankenphp` ships a service template; see the FrankenPHP docs).

**Worker mode** (`thread: true` in `phlo_app()`) keeps the app resident
between requests. It requires the worker directive in the Caddyfile:

```
example.com {
	root * /path/to/my-app/www
	php_server {
		worker /path/to/my-app/www/app.php
	}
}
```

Worker mode and `build: true` are mutually exclusive; use workers in
production (release builds), on-request builds in development.

## Option B: Docker

The engine repo ships a `Dockerfile` (FrankenPHP base, engine baked at
`/phlo`, app mounted at `/app`):

```bash
docker build -t phlo /path/to/phlo/

# scaffold interactively into ./my-app
docker run -it --rm -v ./my-app:/app phlo php /phlo/install.php /app

# serve on http://localhost
docker run -v ./my-app:/app -p 80:80 phlo

# automatic HTTPS for a real domain
docker run -v ./my-app:/app -p 80:80 -p 443:443 -p 443:443/udp \
	-e SERVER_NAME=example.com phlo
```

`docker/compose.yml` contains a Compose example. Note: with `build: true`
the container writes compiled files into the mounted app directory; on Linux
those files are owned by the container user (root by default). Use release
builds or align the user (`--user $(id -u)`) if that matters to you.

## Option C: classic PHP-FPM with nginx

Phlo does not require FrankenPHP; any SAPI works (worker mode excepted).

```nginx
server {
	server_name example.com;
	root /path/to/my-app/www;
	location / {
		try_files $uri /app.php$is_args$args;
	}
	location ~ \.php$ {
		include fastcgi_params;
		fastcgi_param SCRIPT_FILENAME $document_root/app.php;
		fastcgi_pass unix:/run/php/php8.3-fpm.sock;
	}
}
```

For a quick look without any server:

```bash
php -S 127.0.0.1:8000 /path/to/my-app/www/app.php
```

## Cron tasks (optional)

Apps using the `tasks` resource need one cron entry:

```cron
* * * * * php /path/to/my-app/www/app.php tasks::run
```

On ZTS installations the binary may be called `php-zts`. See `docs/tasks.md`.

## WebSockets (optional)

WebSocket support requires the separate PhloWS Node.js server and is fully
optional; nothing else in the framework depends on it. See
`docs/websocket-contract.md`.
