# Changelog

All notable changes to Phlo are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and Phlo adheres to [Semantic Versioning](https://semver.org/) from the first
tagged release onward. The engine version constant lives in `phlo.php`
(`const phlo`).

## [Unreleased]

### Added
- MIT `LICENSE`, `composer.json`, `CHANGELOG.md`, `CONTRIBUTING.md` and
  `SECURITY.md` in preparation for the public open-source release.
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
- SKILL.md: full-line `//` comments are documented as officially supported
  (the parser always accepted and forwarded them); `<script>`/`<style>`
  block termination is documented as the literal closing tag, matching
  actual parser behaviour.
- `docs/websocket-contract.md` rewritten in English against the actual
  PhloWS implementation: cookie-based auth at the upgrade, the
  `websocket::<hook>` statics mapping to `wsAuth`/`wsConnect`/`wsReceive`/
  `wsClose` app functions, real cast targets (`all`, `token:`,
  `token:not:`) and the `/health` endpoint. Server-specific port numbers
  removed from SKILL.md; WebSocket support is documented as optional.

### Security
- Removed the dashboard `inspect` section. It read any file resolvable on
  disk (including `data/auth.ini` / `data/creds.ini`) for an authenticated
  dashboard user. Nothing linked to it; the Source, Build and Release
  views already cover every legitimate target and only serve files from
  known maps. A regression test guards against reintroducing a raw file
  reader.

### Fixed
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

## 1.0Δ (Delta), baseline

The state of the framework at the start of the open-source preparation
(June 2026). Highlights of what Delta already contains:

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
