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

## Releasing updates to production

A release is a deliberate, content-aware act, not a single push-button script.
What moves differs per app, so decide each release on its merits rather than
forcing every app through one generic tool (and never shrink an app's config
just to fit such a tool). Automate the repetitive, safe mechanics; keep the
judgment with a person.

A complete release has more parts than the compiled code:

- **The build** (`release/`): run `build::lint` (expect `[]`) then
  `build::release` as the user the app runs as, and ship that tree. It is the
  compiled code plus the `www/` assets.
- **Runtime content** the app reads from disk: markdown, translations and data
  files. These live *outside* `release/`, so they are a separate copy step, not
  part of the build. Find every content directory by grepping the source for
  *all* runtime reads (`file_get_contents`, `glob`, a `*Dir` prop, a hardcoded
  `/path`), not only the `phlo_app()` path constants: content is sometimes read
  through a hardcoded path in code, which an entrypoint-only inventory misses.
- **The entrypoint** (`phlo_app()` constants): environment-specific (host,
  `build: false` in production, production paths). Keep a per-environment
  entrypoint and *reconcile* it when you add new path constants; never copy a
  dev or stage entrypoint over production. A new feature whose constant is
  missing on prod silently has nowhere to read from.
- **The database**: new or changed tables, the grants the app's DB user needs
  on them, and any data the app reads at runtime (seeded or generated content).
  Production runs `build: false`, so tables are *not* auto-created on first use
  the way they are in a `build: true` dev environment; create and grant them as
  an explicit migration step, and copy or regenerate their content (copying from
  a working environment avoids re-running expensive generation). A table the app
  `SELECT`s from is a 500 until it exists and the app's user is granted on it.

Equally important is what must *not* move:

- **Prod-owned files**: a production `robots.txt`, the secrets and runtime
  state under `data/`, and static assets the node owns (favicons, fonts,
  uploads). Do not `rsync --delete` over those trees or you wipe what only
  exists on the node.
- **Never ship** `.git/` directories, databases or dev caches. Exclude them
  explicitly.
- **`robots.txt` — never copy the dev/stage one to production.** The repo's
  `www/robots.txt` is `Disallow: /` so `dev.*` and `stage.*` stay out of search;
  the build does *not* copy it into `release/`, so it never reaches prod on its
  own. Production serves its *own* public `robots.txt` (an `Allow` file the node
  owns, or a `route GET robots.txt`). Always rsync the `www/` tree with
  `--exclude='robots.txt'` and remove any stale `release/www/robots.txt`, or you
  deindex the live site by overwriting its robots with the dev `Disallow`.

Mechanics worth standardizing:

- **Dry-run first** (`rsync -ni`): review both the additions and the deletions
  before any write.
- **Ownership**: if you deploy as a different user than the app runs as (for
  example rsync over SSH as `root` while the app runs as a dedicated user), set
  ownership back to the app user on the target after the transfer.
- **Restart** the worker so it reloads the new code and entrypoint.
- **Smoke-test**: the home page and every new or changed route should return
  `200` with no error page.
- **Rollback**: keep the previous entrypoint and rely on the regenerable build;
  a version tag makes code rollback trivial.

## Multiple nodes and load balancing

A Phlo app is a stateless PHP application tier, so it scales horizontally the
way PHP has for 25+ years: run the identical release on several nodes behind a
load balancer, with a shared data tier (database, cache) between them.

1. **Same build on every node.** Deploy the same `release/` tree to each node
   (rsync it, or bake an image and roll it out) and run FrankenPHP in worker
   mode on each.
2. **Load balancer in front.** Put Caddy, nginx, HAProxy or a cloud load
   balancer ahead of the nodes (round-robin or least-connections over their
   `:80`/`:443`).
3. **Share session state.** Phlo's `session` resource is plain
   `session_start()`, so set PHP's `session.save_handler` to a shared backend
   (Redis, Memcached, or a database) in `php.ini`, or enable sticky sessions on
   the load balancer so a visitor keeps hitting the same node.
4. **Keep state shareable.** Follow the worker-safe rules: per-request data in
   `%req`/`%session`, never request- or user-state in statics. DB
   connections are transient by default; opt one into worker reuse with
   `prop %MySQL.objPers = true` (safe: `DB::query` reconnects and retries
   once on a dropped connection). Note that `apcu` is per-node memory, not shared, so
   anything that must be visible across nodes belongs in the database.
5. **The database is the real work.** The application tier is cheap to add to;
   the data tier is where scaling effort goes (read replicas, connection
   pooling, partitioning).

Phlo ships no orchestrator: you manage the nodes and the load balancer yourself
(the Phlo Dashboard gives a fleet overview). There is no autoscaler.

## Cron tasks (optional)

Apps using the `tasks` resource need one cron entry:

```cron
* * * * * php /path/to/my-app/www/app.php tasks::run
```

On ZTS installations the binary may be called `php-zts`. See `docs/tasks.md`.

## WebSockets (optional)

WebSocket support is provided by Phlo Realtime, the WebSocket layer built into
the Phlo Daemon, and is fully optional; nothing else in the framework depends
on it. See `docs/websocket-contract.md`.
