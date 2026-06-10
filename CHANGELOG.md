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

### Fixed
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
