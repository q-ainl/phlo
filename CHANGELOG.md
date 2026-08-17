# Changelog

All notable changes to Phlo are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and Phlo adheres to [Semantic Versioning](https://semver.org/) from the first
tagged release onward. The engine version constant lives in `phlo.php`
(`const phlo`).

## [Unreleased]

### Changed
- **Breaking:** the `jsonFlags` constant is now `jsonPretty`, and `jsonFlat` joins it for
  the set without `JSON_PRETTY_PRINT`. `jsonFlags` is gone with no alias, so rename it
  where you used it. The pair exists because both sets were already in use and only one
  had a name: 36 places in the engine spelled the flags out by hand, and which of the two
  they meant was invisible. Use `jsonPretty` for anything a person opens and `jsonFlat`
  for payloads.
- **Breaking:** `view()` no longer links a manifest when `www/manifest.json`
  happens to exist. The manifest resource owns that output now: it serves
  `/manifest.json` from `%manifest->body` and puts the link on the page with
  `%manifest->head`. An app that relied on the file being picked up must
  either add that head view or write the `<link rel="manifest">` itself. The
  `favicon.ico` link stays automatic, because no resource owns favicons and
  the file is what a browser asks for either way.
- Every resource now carries `@ advice` next to `@ summary`, and return types
  are declared wherever the body proves one (18 to 80 percent of nodes).
  Adding a return type to an overridable method is a compatibility rule: an
  app that overrides one without repeating the type gets a fatal. No
  configuration property was typed, because a typed property must keep its
  type in every subclass and that would make a migration out of every
  `static table` and `static objCache`.
- The CSS parser classifies an at-rule by what its body holds instead of by its
  name. Only the conditional group rules (`@media`, `@supports`, `@container`,
  `@layer`, `@scope`, `@document`, `@keyframes`) are known by name and keep
  wrapping the selectors inside them; every other at-rule whose body holds
  declarations is emitted as its own block. `@page`, `@property`,
  `@counter-style`, `@font-palette-values`, `@view-transition` and anything CSS
  adds later therefore survive, where before only `@font-face` did and the rest
  was dropped without a word. `@page :first` and `@property --tint` keep their
  prelude; `.card { @page: size: A4 }` no longer wraps the card in an `@page`.
- `css_phlo()` keeps at-rules that hold declarations. The encoder classified
  them the same way the decoder did, so pasting a stylesheet with `@font-face`
  or `@page` into the Control Center CSS editor dropped those blocks. It follows
  the body now too, and the round trip through both directions is covered.
- **Breaking:** a declaration that belongs to no selector is a build error
  instead of silence. It names the declaration, the at-rule it sat in and, for
  the common cause, that a rule was written on one line while the dialect wants
  one declaration per line. This surfaces stylesheets that were already losing
  those lines, so a build that was green can now fail on CSS that never
  rendered.
- A failing build lint now names the `.phlo` line instead of the generated PHP
  file. `php -l` reports what it was handed, and the builder still holds the
  sourcemap of that same build, so the location is rewritten before the error
  is raised: `security.phlo:16` rather than `php/security.php on line 19`. One
  source line becomes several lines of PHP, so the mapped line is capped by the
  next node and by the end of the file. A release build carries no sourcemap
  and keeps the original wording.
- `phlo_auth()` compares both the user and the password with `hash_equals()`
  and evaluates both before combining them, so neither the length of a correct
  prefix nor a wrong username is readable from how long the answer takes.
- `session` takes an overridable `options` array and passes it to
  `session_start()`, so an app can steer the session cookie without working
  around the resource. A flow that returns with a cross-site POST, such as an
  OIDC provider posting its callback, needs `cookie_samesite: 'None'` with
  `cookie_secure: true`; PHP 8.6 makes `Lax` the default, and a Lax cookie is
  not sent on that request, so the state stored before the redirect would read
  back empty.
- `phlo_auth($section, $realm = null)` moved from `classes/changed.php` into
  `functions.php`, so an app can gate anything with its own section of
  `data/auth.ini` on a release node too. It used to load only in build mode,
  which made a gate of your own fatal with an undefined function in
  production. Without a realm it returns a bool and renders nothing; with one
  it renders the 401 challenge, or a 500 naming the section that is missing.
- The installer writes `data/auth.ini` with a `[control]` section and a
  generated password (mode 600), prints it once at the end, and adds
  `data/auth.ini` and `data/creds.ini` to the generated `.gitignore`. A fresh
  app used to answer 500 on its own Control Center until someone wrote that
  file by hand, and nothing kept credentials out of the first commit.
- **Breaking:** the Control Center reads its credentials from the `[control]`
  section of `data/auth.ini`; the old `[dashboard]` section is no longer read
  and there is no fallback. Rename the section in every app that mounts the
  Control Center, otherwise it answers with `Missing auth config section
  [control] in data/auth.ini`. The site-wide gate keeps using `[site]`. The
  handler class is renamed from `phlo_dashboard` to `phlo_control` in the same
  pass, so the last of the old naming is gone: the feature is the Control
  Center, and Phlo Dashboard stays the name of the separate fleet app.

### Added
- A `manual` resource: `GET /manual` describes the app it runs in, from three
  sources that follow the code by themselves: `data/app.md`, the live source
  through `reflect` and `git log`. A layer under `paths.resources` becomes its
  own section once it carries a `layer.json` in its repo root and a heading in
  its README; a layer that does not announce itself stays out of sight, which
  is how the framework stays out of the manuals of the apps built on it. The
  markdown of the description is rendered server-side and the page inlines its
  own css, so it carries no javascript and listing the resource is all
  `data/app.json` needs: no namespace keys, no client-side parser. The AI
  summary is optional and keyed on the description, so it costs nothing per
  visit and the page works without a key or without the `AI` resource. Every
  render whose content differs from what is stored is written to
  `data/manual.html` without its session token, so the last state survives
  without the app running. Put the route behind your auth gate and add
  `manual` to `release.exclude`, since a manual carrying the source does not
  belong on a customer server.
- Reflection hands over what an author wrote for a reader: `objectIndex()`,
  `availableResources()` and `functionIndex()` carry `advice` and `tags` now,
  `find()` reports a node's declared type next to its args, and every summary
  a reflection call returns is the first line of the comment, so two callers
  asking about the same node get the same sentence. The Control Center shows
  the advice and the tags on its resource cards, which also lets the filter
  match on a tag. Before this, the 132 advice lines were parsed and then
  dropped on the private side of reflect, reachable by nobody.

### Fixed
- `view()` no longer sends a `Link: rel=preload; as=script` header when the app
  runs a nonce-based policy. A preload header cannot carry a nonce, so under
  `%security->strict` the browser refused the preloaded bundle and fetched it
  again from the tag: one blocked request, one console error and one wasted
  round trip on every page. Styles keep their preload, because a nonce policy
  still allows `'self'` for stylesheets.
- Seven of the fourteen node comments added for what a type cannot say opened
  with a line that ran on into the next, so reflection served half a thought
  as their summary. Each first line ends where its sentence does now.
- `app.stream()` could throw while turning a blob into a `File` when a plain
  download filename contained a literal percent sign, and cut a quoted name at
  its first semicolon. It now parses the plain and RFC 5987 forms separately,
  keeps compatibility with the encoded names from `output(filename:)`, and
  prefers `filename*` when a response carries both forms.
- `payload` turned a POST into a 500 whenever a text field preceded a numeric
  field name (`a=1&0=x`): the import unpacked `$_POST` into named arguments and
  PHP forbids a positional argument after a named one, so survival depended on
  field order and any bot could provoke it. Numeric field names are legal HTTP
  and are now assigned the same way `objImport` does it internally. The parsed
  urlencoded body and `$_FILES` were unpacked the same way and follow suit.
- `seo` raised a 500 on `sitemap.xml` for an app that declared neither `pages` nor
  `langs`, which is the normal shape of a single-page or placeholder site. Both
  are optional now: without `pages` the sitemap lists the site root, without
  `langs` it leaves the hreflang alternates out. `robots` already guarded the
  same way, so the resource is consistent with itself again.
- `seo` advertised `og:image` and `twitter:image` even when the app had no
  image: the `icon.webp` fallback is a convention, not a promise, so every
  crawler and link preview was pointed at a 404. The fallback now applies only
  when the file is really there, and without an image the tags stay out.
- `DOM/link` intercepted a link that carried a `target`, so `class=async` and
  `target=_blank` on one element resolved differently on a plain click than on
  a ctrl or cmd click. Browser semantics win whenever a target is set.

## [1.0.1] - 2026-08-01

### Added
- A `manifest` resource: declare the web-app-manifest body once
  (`prop %manifest.body => arr(...)`) and the resource serves
  `GET /manifest.json` as `application/manifest+json` (pretty-printed, short
  cache), plus a `%manifest->head` view for the `<link rel="manifest">` tag.
  Apps with several manifest variants keep their own routes and serve each
  body through `manifest::output()`; the default route steps aside (`return
  false`) when no body is declared, so it never shadows an app-defined
  `manifest.json` route.
- `{[ expr ]}` view interpolation for auto-escaped output: it HTML-escapes its
  value (equivalent to `{{ esc(expr) }}`), while `{{ }}` and `{( )}` stay raw.
  Quote-aware, so a `]}` inside a string literal does not close it early.
  Covered by the views golden fixture and `ParserTest`.
- A `settings` resource: `setting()` lists, reads and writes persistent app
  settings as a flat key/value map in `data/settings.json`. Reads are fresh per
  request (worker-safe); a write flushes to disk immediately. Covered by
  `FileFormatTest`.
- A `stream` resource: `stream()` emits raw text or binary chunks under any
  content type beside the JSON command channel, and the frontend gains
  `app.stream(path, onData, async, type, data)`: a fetch consumer that
  dispatches on the response Content-Type (ndjson, sse, text, json, blob, raw),
  normalizes SSE line endings, and reaches `route async` targets when the
  `async` argument is true. Covered by `OutputTest` and `StreamHttpTest`.
- HTTP `QUERY` method support (RFC 10008): a safe, idempotent read that carries
  a request body. `route QUERY <path>` now parses in the router; `%payload`
  decodes the QUERY body (JSON, form-urlencoded, multipart); the `HTTP()` helper
  takes a `QUERY:` argument; the `connectors/Connector` base gains a `query()`
  method and treats QUERY as retryable; and the frontend `app.query()` helper
  issues QUERY requests.
- A `connectors/finance/EBoekhouden` connector: e-Boekhouden.nl relations and
  sales invoices behind the standard connector contract. The first call
  exchanges the configured `api_token` for a session token (cached per
  instance); pass `source` to label the e-Boekhouden audit trail.
- Keyboard control on the presentation player: a document keymap steers the
  fullscreen, focused or only player on the page. Space and `k` toggle
  playback, the horizontal arrows seek five seconds, the vertical arrows step
  the volume, `m` mutes, `c` toggles subtitles, `f` fullscreen and Home/End
  jump to the edges. The player root is focusable, so an embedded player takes
  the keys after a click or a tab stop, while keys typed into a form field
  elsewhere on the page never steer a presentation. Covered by the frontend
  `presentation.test.js`.

### Changed
- Trace no longer records internal Control Center requests (paths under the
  `control` prefix), so a trace session shows only real app requests. It runs
  only when `trace` is on (dev-only). Covered by `TraceScopeTest`.

### Security
- `security/social` now verifies the OIDC `id_token` signature against the
  provider's JWKS (RS256 only, key looked up by `kid`) before a single claim is
  read. It previously decoded the payload and trusted it, on the grounds that the
  token arrives server-to-server over TLS; that holds for the transport but
  leaves nothing checking the token itself, and an `alg: none` or symmetric
  header was accepted as readily as a real one. The audience, expiry and, when
  the caller passes one to `authUrl()`/`profile()`, the `nonce` are checked too.
- The Microsoft issuer is now bound to the tenant that signed the token
  (`iss` must equal `https://login.microsoftonline.com/{tid}/v2.0`) instead of
  matching the `login.microsoftonline.com` prefix, which accepted a token from
  any tenant in the world. An unknown issuer now fails closed rather than
  passing when the provider declares none.
- A Microsoft email is no longer reported as verified just because it is present.
  Microsoft omits `email_verified`, and any tenant administrator can put someone
  else's address in the claim (nOAuth), so a caller that trusted the flag could
  be walked onto an existing local account. `verified` now reflects the optional
  `xms_edov` claim, which states that the tenant owns the address. Callers must
  key identity on provider + `sub` and treat an unverified address as a claim,
  never as an identity. Covered by `SocialTest`.

### Fixed
- `payload` raised "Error reading php://input" on any request that declared
  `Content-Type: application/json` but sent an empty or malformed body (common
  from bots and scanners), turning a bad request into a logged 500. The JSON
  branch now decodes the body directly and treats a null result as an empty
  payload, the same as the form and multipart branches.
- `security/social` never saw ini-backed credentials: `config()` and the Apple
  client-secret path cast the creds section object straight to an array, which
  exposes obj internals (`objData`, ...) instead of the ini keys, so
  `configured()` stayed false for every ini-configured provider. Both paths now
  unwrap the section through the creds resource's `toArray`. Covered by
  `SocialTest` (config unwrap, redirect-uri default and override, authUrl).

## [1.0] - 2026-07-03

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
- `DOM/cookiewall` serves both English-only and multilingual sites from one
  resource: English by default, auto-translating when the lang system (`en()`) is
  loaded, with overridable `labels` and `translate` props. `DOM/cookiewall.translated`
  is removed (merged in).
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
- Raw view output (`{{ }}` / `{( )}`) is documented as intentionally unescaped: the
  app owns output escaping, with the strict CSP as a backstop.
- Removed the dashboard `inspect` section. It read any file resolvable on
  disk (including `data/auth.ini` / `data/creds.ini`) for an authenticated
  dashboard user. Nothing linked to it; the Source, Build and Release
  views already cover every legitimate target and only serve files from
  known maps. A regression test guards against reintroducing a raw file
  reader.

### Fixed
- Visitor tracking keys each record per browser window instead of per persistent
  cookie, so `active_seconds` and the dashboard's session duration reflect a single
  window's visible time, not the visitor's whole history.
- `field_child::objOwns()` resolves an object-valued back-reference to its id before
  comparing, so a nested child record (a comment under its article) matches its parent
  instead of returning a 404, while non-owners are still rejected.
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
- CSS transpiler: a comma inside a functional pseudo-class (`:is()`, `:not()`,
  `:where()`) on a nested selector was treated as a selector-group separator, so
  the parent was prepended to the part after the comma (`.nav a:is(.x, .y)`
  became `.nav a:is(.x, .nav .y)`). Commas are now split only at the top level,
  respecting parentheses and quoted strings.

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
