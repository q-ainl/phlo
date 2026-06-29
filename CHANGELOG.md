# Changelog

All notable changes to Phlo are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and Phlo adheres to [Semantic Versioning](https://semver.org/) from the first
tagged release onward. The engine version constant lives in `phlo.php`
(`const phlo`).

## [Unreleased]

### Added
- A `security/captcha` resource: a self-contained slider-puzzle captcha (GD-rendered,
  session-bound, single-use) with server-side human-behaviour checks (drag time, path and
  variation) and no external service. `%captcha->widget()` renders it; `captcha::verify()`
  then `captcha::consume()` gate a form submit such as sign-up.
- A custom production error page hook, `app::errorPage($code, $id)`, plus short
  8-character error reference ids: the id is shown on the error page and in the
  JSON/async payload and logged in `data/errors.json`, so a user can quote it and
  the developer can find the entry. The error pipeline is recursion-guarded - a
  throw from the renderer or a custom `errorPage` falls back to a dependency-free
  bare page instead of looping.
- Field-agnostic `objOwns()` on the relation fields (`child`, `many`): the CMS
  routes delegate record-ownership checks to the field instead of inlining
  relationship semantics, and relation links/counts resolve through the model's
  `idColumn`, so a non-`id` primary key works end to end.
- Expanded test coverage: the AI layer (no credentials), the ORM field types (no
  database), the security primitives, the file-format resources, the DOM
  tag-builders, a penetration-test round against the framework defenses, and the
  safe-DB-reconnect rules. The ORM suite also runs against a real MySQL in CI (a
  MySQL service job): CRUD and the INSERT-IGNORE path, a custom string primary
  key, relations, and the audit transaction/savepoint rollback, alongside the
  SQLite run.
- `docs/versioning.md`: the Semantic Versioning compatibility, deprecation and
  support policy, and the upgrade process.
- API connectors under `resources/connectors/`: a `Connector` base class
  (credential resolution from a `creds.ini` section, JSON request helpers built
  on `HTTP()`, opt-in idempotent retry, pagination and a normalized
  `obj(ok, status, data, error)` result), plus connectors for Shopify,
  Lightspeed, Slack, Telegram, Twilio, MessageBird, Resend, Moneybird,
  Exact Online, Microsoft Graph, Google Calendar and Google Sheets. OAuth2
  connectors share an `OAuthConnector` base with a refreshing `TokenStore`.
  Documented in `docs/connectors.md` with a `docs/creds.example.ini` template;
  covered by `tests/ConnectorTest.php`, `tests/HttpTest.php` and a `connectors`
  golden fixture.
- `HTTP()` gains optional arguments: `cookies` (off by default, `true` maps to
  `data/cookies.txt`, a string is treated as a jar path), `timeout` (default
  15s) and a by-reference `response` that receives an
  `obj(ok, status, headers, error)`. The body-string return and the
  throw-on-transport-error behaviour are unchanged; the only behavioural change
  is that the shared cookie jar is now opt-in. `AI`'s internal HTTP client is
  refactored onto `HTTP()`.
- MIT `LICENSE`, `composer.json`, `CHANGELOG.md`, `CONTRIBUTING.md` and
  `SECURITY.md` in preparation for the public open-source release.
- CI publishes the Docker image to `ghcr.io/<owner>/phlo` on version tags
  (`v*`), tagged with the semver version and `latest`; branch/PR builds
  still only smoke-build the image without pushing.
- PHPUnit test harness (`tests/`): unit tests for the CSS transpiler, the
  `route()` matcher, the `obj` base class and the `.phlo` parser, plus
  golden-file compiler tests that build fixture apps end-to-end and compare
  the generated PHP/CSS/JS against committed snapshots.
- GitHub Actions CI: build + test on PHP 8.3 / 8.4 / 8.5.
- Source-level build diagnostics: HTML that leaks out of a view (a blank
  line closed it) stops the build with the .phlo file, line and view name;
  a multiline argument list with a missing trailing comma is reported on
  its source line; a CSS line that is not a declaration is a build error
  instead of being silently dropped.
- CSS values may wrap across lines after a dangling colon on the property
  line; continuation lines ending with a comma were already merged. Both
  legal wrap forms are documented in SKILL.md (syntax rule 4).
- VS Code extension with a TextMate grammar for `.phlo` under
  `editor/vscode/`: nodes, routes, views with interpolation and control
  tags, Phlo CSS, embedded JavaScript, `%object` shorthands and metadata.
- `install.php`: interactive CLI scaffolder. Asks name, host, purpose and
  resources (catalog and `@ requires` resolution come from the engine's
  own resource metadata), writes a buildable app skeleton including
  `data/app.md`, runs `build::run` + `build::lint`, and removes itself
  when run as a copy inside the new app directory.
- Docker image (`Dockerfile`, FrankenPHP base): engine baked at `/phlo`,
  app mounted at `/app`, `SERVER_NAME` env for automatic HTTPS; Compose
  example under `docker/`. CI builds the image on every push.
- `docs/deploy.md`: deployment guide for FrankenPHP bare metal (incl.
  worker mode), Docker, classic PHP-FPM/nginx and `php -S`, plus the cron
  and WebSocket notes.

### Changed
- `%MySQL` connections are transient by default (the `objPers` marker is gone),
  so an idle FrankenPHP worker no longer reuses a connection MySQL has already
  closed ("server has gone away"). An app that wants a persistent connection opts
  in with `prop %MySQL.objPers = true`.
- SKILL.md: full-line `//` comments are documented as officially supported
  (the parser always accepted and forwarded them); `<script>`/`<style>`
  block termination is documented as the literal closing tag, matching
  actual parser behaviour.
- `docs/websocket-contract.md` rewritten in English against the actual
  phloWS implementation: cookie-based auth at the upgrade, the
  `websocket::<hook>` statics mapping to `wsAuth`/`wsConnect`/`wsReceive`/
  `wsClose` app functions, real cast targets (`all`, `token:`,
  `token:not:`) and the `/health` endpoint. Server-specific port numbers
  removed from SKILL.md; WebSocket support is documented as optional.

### Security
- The `JSON` file resource maps slashes in a filename to dots (like `CSV`/`INI`),
  so a `../` in a name can no longer escape the data directory; the
  penetration-test round asserts all three file resources are safe.
- `debug` mode no longer relaxes the script Content-Security-Policy - the strict
  nonce policy holds in debug too. Raw view output (`{{ }}` / `{( )}`) is
  documented as intentionally unescaped: the app owns output escaping, with the
  strict CSP as a backstop.
- Removed the dashboard `inspect` section. It read any file resolvable on
  disk (including `data/auth.ini` / `data/creds.ini`) for an authenticated
  dashboard user. Nothing linked to it; the Source, Build and Release
  views already cover every legitimate target and only serve files from
  known maps. A regression test guards against reintroducing a raw file
  reader.

### Fixed
- Safe DB reconnect: a "server has gone away" / lost-connection error on a read
  transparently reconnects and retries once; a mutation, a statement inside a
  transaction, and a data-modifying CTE (`WITH ... DELETE/UPDATE/INSERT`) are
  never auto-retried, so a write is never silently run twice. DB identifier
  quoting is hardened across the driver layer.
- View compilation of bare constants, and the source line reported for an error
  raised inside a view body.
- `phlo_error_log()` now wraps the whole read-modify-write of
  `data/errors.json` in a single `flock(LOCK_EX)`, so concurrent errors no
  longer overwrite each other's updates, and caps the log at the newest 200
  entries to stop unbounded growth. Dedup-by-origin, newest-first ordering
  and output formatting are unchanged.
- CSS transpiler: the inline media-query shorthand inside a selector block
  (`@media (max-width: 768px): font-size: 1.2em`) was silently dropped from
  the output; it now inherits the surrounding selector and hoists correctly,
  as documented in SKILL.md. This also restores the missing
  `@media(min-width: 600px): right: auto` rule from the cookiewall resource.

## Baseline

The state of the framework at the start of the open-source preparation
(June 2026). Highlights of what this baseline contains:

- Compile-to-PHP `.phlo` language: routes, props, methods, views, statics,
  `<style>`/`<script>` blocks, cross-resource node modifiers.
- Build system with self-linting output, per-class sourcemaps (PHP line →
  `.phlo` line), classmap autoloading and on-request rebuilds in dev.
- Bidirectional Phlo-CSS ↔ CSS transpiler, JS minifier, PNG icon sprites.
- SPA runtime (`assets/phlo.js`): `apply()` DOM-command protocol, streaming
  responses, history snapshots, View Transitions.
- Error pipeline: source-mapped error pages, `data/errors.json` log with
  deduplication, debug dumps to the browser console.
- Built-in dashboard (home / config / source / build / release / errors /
  graph / tasks) when `build + debug` are enabled.
- CLI introspection via `build::` and `reflect::` (routes, views, AST,
  search, typed backend/frontend dependency graphs).
- Opt-in function tracing (`trace: true`, `build::traceShadow`).
- ~150 resources: AI clients, DB layer + model ORM, form fields, file
  formats, security (CSRF, JWT, encryption, rate limiting, audit), DOM
  components, page transitions, themes, WebSocket, cron tasks.
- FrankenPHP worker mode (`thread: true`) with per-request object reset.
