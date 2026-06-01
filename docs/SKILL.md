---
name: phlo
description: "Work with Phlo v4 (Delta) projects: write and edit source files, trigger builds, introspect routes, views, functions and resources via CLI, diagnose build or parse errors, and understand app structure. Use for any task involving Phlo source files, the build system, or CLI introspection."
---

# Phlo v4 (Delta) - Complete Language and Build Reference

## Overview

Phlo is a compile-to-PHP framework. Source files have the `.phlo` extension and compile to PHP, CSS, and JS. **Never edit generated files** in `php/`, `www/app.js`, `www/app.css`, or release output such as `release/php/`, `release/www/app.js`, and `release/www/app.css`.

Each `.phlo` file compiles to exactly one PHP class. The class name is derived from the file name: dots become underscores (`page.home.phlo` -> class `page_home`). File names use dots as separators - **never underscores**.

---

## CRITICAL SYNTAX RULES

These rules are absolute. Violations produce parse errors or silently broken output. Read before writing a single line.

### 1. No comments, no semicolons

Phlo source files contain **no comments of any kind** - no `//`, `/* */`, `#`, or HTML comments.
Statements are terminated by **line endings**, never by semicolons.

```
OK  $x = 1
NO  $x = 1;            <- no semicolons
NO  // comment         <- no comments
NO  /* comment */      <- no comments
NO  $x = 1 // inline  <- no inline comments either
```

### 2. Multiline arguments require a trailing comma on every line

The parser inserts a semicolon at each line ending. Without a trailing comma, each argument line becomes its own broken statement.

```
NO  WRONG:
apply(
  title: 'Home'
  main: $html
)

OK  CORRECT - comma on every line, including the last:
apply(
  title: 'Home',
  main: $html,
)
```

This applies to **every** multiline call: `apply()`, `view()`, `chunk()`, array literals, any function call.

### 3. No blank lines inside a view

A **blank line immediately closes the current view**. HTML after a blank line becomes controller code - producing a PHP parse error (`unexpected token "<"`). This is the most common source of catastrophic errors.

```
NO  WRONG - blank line inside view breaks it:
view home:
<h1>Welcome</h1>

<p>This is now controller code - parse error!</p>

OK  CORRECT - no blank lines inside a view:
view home:
<h1>Welcome</h1>
<p>This is still inside the view.</p>

view nextView:
<p>Next view starts after the blank line above.</p>
```

When adding or editing view content, **never insert a blank line for visual spacing**.

The same block-terminator rule applies to `<script>` and `<style>` blocks: a blank line inside the block closes it. A new `view`, `<script>`, or `<style>` block after another block must start after the previous block has been closed.

### 4. CSS values must stay on one line

The CSS parser treats each line as one complete declaration. Long values like gradients, font stacks, or `calc()` expressions must **never be wrapped** across multiple lines.

```
NO  WRONG - parser sees two broken declarations:
background: linear-gradient(
  to bottom, #000, #fff
)

OK  CORRECT - keep the entire value on one line:
background: linear-gradient(to bottom, #000, #fff)
```

### 5. Space around `=>` in single-line nodes

```
OK  method now => time()
NO  method now=>time()
```

### 6. Control flow tags must be on their own line

`<if>`, `<elseif>`, `<else>`, `<foreach>` cannot appear inline within HTML text or other tags.

```
OK  CORRECT:
<foreach $this->items AS $item>
  <p>$item->name</p>
</foreach>

NO  WRONG - inline control tag:
<p><if $x>More</if></p>
```

### 7. Attribute values with variables or slashes need quotes

```
OK  <a href="/">Home</a>
OK  <html lang="$this->lang">
NO  <a href=/>
NO  <html lang=$this->lang>
```

---

## Source file structure

```
www/app.php          <- entry point and runtime config (PHP, not .phlo)
data/app.json        <- build config (resources, paths, flags)
data/app.md          <- app documentation: structure, TODO items, agent notes (read/write)
data/errors.json     <- runtime error log
data/auth.ini        <- auth credentials (dashboard / site)
*.phlo               <- source files (app root)
resources/           <- reusable resources: objects, functions, frontend assets, or hybrids
php/                 <- generated PHP - never edit
www/app.js           <- generated JS - never edit
www/app.css          <- generated CSS - never edit
```

Core resource folders are intentionally limited to larger domains: `AI/`, `CSS/`, `DB/`, `DOM/`, `Fields/`, `Files/`, and `Security/`. General-purpose resources such as `active`, `age`, `HTTP`, `session`, `slug`, `tag`, `phlo.sync`, and `websocket` live directly in `resources/`.

Generated classes are loaded through `php/classmap.php`. Do not rely on classname-to-filename autoload fallback behavior; if a class is not in the classmap, rebuild or fix the source/config.

### Resource dependencies and graph metadata

Resource `@ requires` metadata is used by reflection and the dashboard. When a resource is enabled from the dashboard, required resources are added to `data/app.json` as well. Disabling a resource removes only that resource; dependencies remain enabled because they may be shared or intentionally loaded.

Only resource requirements are auto-added. Optional requirements ending in `?`, `php-ext:*`, and `creds:*` stay informational.

Resources may declare frontend graph metadata when static analysis cannot infer behavior:

```
@ provides: app.mod.csrf
@ binds:    form.async a.async [data-get]
```

`reflect::graph()` and `reflect::selectorGraph()` return typed `nodes` and `edges`, not the old file-to-dependency map.

Backend edge kinds (`graph`): `calls`, `uses`, `extends`, `requires`, `shared` (inferred file-to-file connection via shared resource dependency).

Frontend edge kinds (`selectorGraph`): `style`, `script`, `selector-def`, `selector-use` (cross-file only), `provides`, `binds`.

Disconnected nodes are excluded from both graphs. The backend graph only includes source files and resources that have at least one edge.

### WhatsApp Resource Contract

`resources/WhatsApp.phlo` is the official client for the current PhloWA whatsapp-web.js engine. Do not add Open-WA compatibility. Apps should configure one WhatsApp channel per engine instance and call the resource instead of making ad-hoc HTTP calls.

Expected channel shape:
- `config.instance`: human/server instance name such as `wa1`, `wa2`, or `wa3`
- `config.url`: local or external engine URL, for example `http://127.0.0.1:8081`
- `secrets.secret`: encrypted shared secret

The same `secret` is used both ways:
- App to engine: HTTP header `secret: ...`
- Engine to app webhook: HTTP header `secret: ...`

Webhook URLs use the instance, not a secret token:

```
https://example.test/receive/whatsapp/js/{instance}
```

The app must validate the instance, active plugin, and `secret` header before processing payloads.

### app.md, agent scratchpad and app documentation

`data/app.md` is a plain-text Markdown file. It is optional but valuable:
- Describes the app's purpose, main routes, and key data structures
- Lists open TODOs, known issues, and ongoing work
- Persists context between agent sessions, read it before starting, update it after finishing

**Read it first:** `reflect::appInfo` returns its contents as a string (or null if it does not exist).

**Keep it current:** after any non-trivial change, rewrite the relevant sections. Use a simple structure:

```markdown
# App name

Short description of what this app does.

## Routes
- GET /  → home page
- POST /items/save → saves an item

## TODO
- [ ] Add pagination to the items list
- [ ] Fix the CMS image upload on mobile

## Notes
- MySQL credentials are in data/auth.ini under [db]
- The `visitor` class tracks page views per session and sends dashboard notifications to the dashboard WebSocket port by default
```

The dashboard shows `app.md` on the Home page and provides an editor under Config.

**Naming conventions:**
- `app.phlo` - main controller and shared props
- `page.*.phlo` - page controllers with routes and views
- `layout.phlo` - shared layout views
- `style.*.phlo` - CSS nodes only (no backend, no routes)
- `script.*.phlo` - JS nodes only

Remember that each `.phlo` file is its own class. `pos.view.phlo` compiles to class `pos_view`, not extra methods on class `pos`. Call views in separate files through `%pos_view->method(...)` or combine model/routes/views in one file when the methods must live on the same class.

---

## Language reference

### Constants

| Constant | Value |
|----------|-------|
| `void` | `''` (empty string) |
| `lf` | `"\n"` |
| `slash` | `'/'` |
| `space` | `' '` |
| `comma` | `','` |
| `dot` | `'.'` |
| `dash` | `'-'` |
| `tab` | `"\t"` |
| `nl` | `"\r\n"` |
| `us` | `'_'` |
| `sq` | `"'"` |
| `dq` | `'"'` |
| `bs` | `'\\'` |
| `semi` | `';'` |
| `br` | `'<br>'` |

### Instance shorthand

```
%MySQL           -> phlo('MySQL')
%payload         -> phlo('payload')
%session         -> phlo('session')
%JSON('file')    -> phlo('JSON', 'file')
```

### Metadata annotations

Placed at the top of a `.phlo` file, before any nodes:

```
@ class: myName       <- override the PHP class name
@ extends: model      <- PHP inheritance (default: obj)
@ summary: ...        <- description for tooling
@ package: ...        <- package group for tooling
@ frontend: true      <- marks lib as frontend-only
@ backend: true       <- marks lib as backend-only
```

### Controller code

Top-level statements outside any node (`route`, `prop`, `method`, `view`, `<style>`, `<script>`) are **controller code** - executed after class instantiation, not in `__construct`.

```phlo
prop ready = false

$this->ready = true    <- controller code, runs on every request
```

### Props

**Static** (literal values only, no function calls):
```phlo
prop title = 'App'
prop defaults = ['theme' => 'dark']
```

**Computed** (lazy, cached on first access):
```phlo
prop now => time()
prop fullName => "$this->first $this->last"
```

**With arguments** (computed, called as method):
```phlo
prop repeat($n) => str_repeat('*', $n)
```

Usage: `$this->repeat(5)`.

Props and methods **without arguments** are called without `()`. Static methods **always require `()`**.

### Methods and statics

Single-line:
```phlo
method hello($who) => "Hi $who"
```

Multiline:
```phlo
method classify($x) {
  if ($x > 5) return 'large'
  return 'small'
}
```

Static value:
```phlo
static count = 0
```

Static computed:
```phlo
static timestamp => time()
```

For array or other complex static values, prefer computed statics:
```phlo
static cashCoupures => [100, 50, 25]
```
Call computed statics with `ClassName::cashCoupures()`. Primitive scalar `static name = value` works, but complex literal `static name = [...]` can produce misleading parser errors in current Delta builds.

### Cross-resource node modifiers

Any `.phlo` file can inject or override a node in **another** resource's class at build time by naming the node `%<targetClass>.<nodeName>`:
```phlo
static %visitors.table = 'control.visitors'
prop %visitors.db = 'control'
method %model.greet => 'hi'
```
The first line overrides the `visitors` model's `static $table`; the second adds or overrides a `db` prop on `visitors`; the third adds a `greet` method to the `model` class. At build the compiler strips the `%<class>.` prefix and writes the node into `<class>`, **overwriting** an existing node of that name (or adding a new one). The target class must be part of the current build (its resource loaded), otherwise the modifier is silently ignored. Match the node *type* you replace (override a `static` with `static`, a `prop` with `prop`), the whole node is swapped.

Use it to adapt an engine/shared resource per app without forking it, e.g. point the shared `visitors` model at a central analytics database while every other query stays on the app's own connection:
```phlo
static %visitors.table = 'control.visitors'
```

### Routing

```
route [async|both] VERB segment1 segment2 ... => target
route [async|both] VERB segment1 segment2 ... { ... }
```

Path segments use **spaces**, not slashes. Route modifier:
- (none) - sync requests only
- `async` - async (Phlo frontend) requests only
- `both` - both sync and async

| Pattern | Meaning |
|---------|---------|
| `segment` | Literal match |
| `$var` | Required variable segment |
| `$var?` | Optional - boolean (true if present) |
| `$var=default` | Default value if segment absent |
| `$var=*` | Rest/slurp - all remaining segments as one string |
| `$var.N` | Exactly N characters |
| `$var:a,b,c` | Must match one of the listed values |
| `@key1,key2` | Validate payload keys - does **not** bind parameters |

```phlo
route GET home => $this->home
route both GET profile $id => $this->showProfile($id)
route async POST items save @name => $this->saveItem
route GET file $path=* => $this->serve($path)
route GET page $slug=home => $this->page($slug)
route GET report $range:daily,weekly,monthly => $this->report($range)
```

Read payload via `%payload->key`, not as route parameters.

The root path has no segments:
```phlo
route GET => $this->home
```
Do not write `route GET /`; slashes are separators, not route segments.

In `app.phlo`, call `app::route()` to activate route discovery across all files.

### Views

A view block starts at `view name:` and **ends at the first blank line**. Never put blank lines inside a view - a blank line terminates the block immediately.

```phlo
view home:
<h1>$this->title</h1>
<p>Welcome.</p>

view detail($item):
<p>$item->name</p>

view:
<p>Anonymous view - named "view" by default.</p>
```

**Expression syntax:**

| Syntax | Use for |
|--------|---------|
| `$var` | Plain variable |
| `$this->prop` | Property or zero-argument method |
| `{{ expr }}` | Function calls, method calls with args, chained access |
| `{( expr )}` | Conditions, operators, null-coalesce |

```phlo
view card($item):
<p>$item->name</p>
<p>{{ $this->formatDate($item->date) }}</p>
<p>{( $item->active ? 'Active' : 'Inactive' )}</p>
<p>{( $item->note ?? dash )}</p>
```

**HTML shorthand:**
```
<p#id.class1.class2/>    -> <p id="id" class="class1 class2"></p>
<div.wrap/>              -> <div class="wrap"></div>
<a.async href=/path>     -> async-enabled link
<form.async method=post> -> async-enabled form
```

**Control flow tags** (always on their own line):
```phlo
<foreach $this->items AS $item>
  <p>$item->name</p>
  <if $item->active>
    <span>Active</span>
  <elseif $item->pending>
    <span>Pending</span>
  <else>
    <span>Inactive</span>
  </if>
</foreach>
```

**Rendering - `view()` and `apply()` are terminating.** They send output and end the request. Never call either more than once per request path.

Inside route guards, always return the terminating call:
```phlo
route async GET item $id {
  if (!$item = item::record(id: $id)) return apply(remove: '#item')
  apply(outer: ['#item' => $this->itemView($item)])
}
```

Routes may return exact `false` to signal a route miss and continue to the next matching routine. Do not use `return false` after a route has actually handled the request; use `return apply(...)`, `return view(...)`, `return location(...)` or a plain `return` after a final side-effect instead.

Correct:
```phlo
method home => view($this->home, 'Home')
```

Wrong:
```phlo
method dashboard {
  view($this->header)
  view($this->content)
}
```

### CSS

One line = one complete declaration. No semicolons. No multi-line values.

Basic declarations:
```phlo
<style>
html: height: 100dvh

body {
  background: #0d0d0d
  color: #fff
  font-family: Sans-serif
}
```

Nesting:
```phlo
body {
  p: line-height: 1.6em
}
```

Pseudo-selectors - backslash glues to parent:
```phlo
a {
  text-decoration: none
  \:hover: color: blue
}
```

Media queries are hoisted automatically:
```phlo
h1 {
  font-size: 2em
  @media (max-width: 768px): font-size: 1.2em
}
```

CSS variables: `$name` becomes `--name`, and variable values become `var(--name)`.
```phlo
:root {
  $primary: #ff4a00
  $bg: #0d0d0d
}
body {
  background: $bg
  color: $primary
}
</style>
```

### Frontend JavaScript

Frontend code lives in `<script>` blocks. API calls are **fire-and-forget** - never use `.then()` or `await`.

```phlo
<script>
on('click', '#btn', (btn, e) => {
  app.post('items/save', {name: 'test'})
})
</script>
```

**Frontend API:**
```js
app.get('path/to/resource')
app.post('path/to/resource', {key: 'value'})
app.put('path/to/resource', data)
app.patch('path/to/resource', data)
app.delete('path/to/resource')

app.path
app.options
app.settings
```

`app.path` is the current path. `app.uri` was v1 and should not be used in v4.

### Apply commands

`apply()` sends a JSON response with DOM update commands:

```phlo
apply(
  path: 'new/path',
  title: 'New Title',
  trans: true,
  inner: ['#target' => $html],
  options: 'dark-mode',
)
```

| Command | Effect |
|---------|--------|
| `path` | Update browser URL |
| `title` | Set page title |
| `trans` | Page transition |
| `scroll` | Scroll position |
| `inner` | Replace innerHTML |
| `outer` | Replace outerHTML |
| `before` / `after` | Insert adjacent to element |
| `prepend` / `append` | Insert inside element |
| `remove` | Remove element(s) |
| `attr` | Set attributes |
| `class` | Modify classes |
| `value` | Set form values |
| `options` | Body classes |
| `settings` | Body data attributes |
| `call` | Execute JS function |
| `state` | State data |
| `log` / `error` | Console output |

---

## Build and introspection workflow

Every Phlo v4 app exposes a CLI layer via `build::` and `reflect::` commands. **Use these to understand an app before editing anything.**

> **Never run CLI commands against a live production server.** The CLI is only available when `build: true`. Always use a dev environment.

On servers where `php` is not in PATH, use `/usr/bin/php-zts` for CLI checks and linting.

### Recommended agent workflow

1. **Orient** - run `reflect::context` for a single snapshot: app identity, route/view counts, loaded packages, and recent errors. Follow with `reflect::compactRoutes` for route detail.
2. **Explore** - use `reflect::sourceFiles`, `reflect::find <type>`, `reflect::search <query>`, `reflect::nodeBody <name>`, `reflect::fileContent <relPath>`
3. **Read** - read the actual `.phlo` source files directly before editing
4. **Edit** - only `.phlo` source files
5. **Build** - `php www/app.php build::run`
6. **Lint** - `php www/app.php build::lint` - empty array = clean; if errors, fix the `.phlo` source (not the generated PHP), then repeat from step 5
7. **Update app.md** - after completing a task, update `data/app.md` to reflect any structural changes, new routes, resolved TODOs, or remaining work

### build:: commands

| Command | Returns |
|---------|---------|
| `build::run` | Triggers a build; returns changed file paths |
| `build::lint` | PHP parse errors in compiled files - empty array = clean |
| `build::config` | Full build config from `data/app.json` |
| `build::changed` | Source files changed since last build |
| `build::buildFiles` | All compiled output file paths (`php/` + `www/`) |
| `build::flush` | Deletes all compiled files |
| `build::help` | All available methods with signatures and descriptions |

### reflect:: commands

| Command | Returns |
|---------|---------|
| `reflect::context` | Single-call snapshot: app identity, route/view counts, loaded packages, recent errors. Best first call for orientation. |
| `reflect::appInfo` | Contents of `data/app.md`, or null |
| `reflect::runtime` | Runtime constants: host, paths, feature flags, custom params |
| `reflect::errors [limit]` | Recent runtime errors (default: 10 most recent; 0 = all) |
| `reflect::compactRoutes` | Route map with file, line, method, and dependency usage |
| `reflect::compactViews` | View list with name, file, and line |
| `reflect::routes` | All route nodes with full detail including complete dependency and comments data |
| `reflect::views [withBody]` | All view nodes with file and line; pass `true` to include view bodies |
| `reflect::sourceFiles` | All `.phlo` source file paths |
| `reflect::sourceNodes [withBody]` | Full parsed AST of all source files |
| `reflect::fileContent <relPath>` | Source file contents by display path (as returned by routes, views, find, search) |
| `reflect::find <type> [name] [withBody] [scope]` | Nodes of a given type, optionally filtered by name. Scope: `app` (default), `resources`, `all` |
| `reflect::nodeBody <name> [type]` | Body of a named node (default type: `method`). Searches source files first, then resources |
| `reflect::search <query> [scope] [maxHits] [contextLines]` | Text search across `.phlo` files. Scope: `app` (default), `resources`, `all`. Pass `contextLines > 0` for surrounding lines. |
| `reflect::graph` | Typed backend graph: `nodes` (files, resources) and semantic `edges` (calls, uses, extends, requires, shared) |
| `reflect::selectorGraph` | Typed frontend graph: files/resources with style/script edges, cross-file selectors, provides, and binds |
| `reflect::resourceDependencies <name> [transitive] [full]` | Required resources for a resource, resolved transitively from `@ requires`. Pass `full: true` to also include `phpExt` and `creds` lists. |
| `reflect::fileDependencies` | Per-source-file map of which other app files it depends on, detected from `phlo()` and `ClassName::` calls. Useful for understanding class-level coupling between source files. |
| `reflect::functionIndex` | All functions with signatures, source (`native`/`function`), group, and load status |
| `reflect::objectIndex` | All resource objects with methods, props, constructor args, and metadata |
| `reflect::resourceSummary` | Package counts, function/object totals, external requirements |
| `reflect::resourceFiles` | All resource file paths |
| `reflect::availableResources` | All discoverable resources with type, kind, load status, and metadata |
| `reflect::editorIndex` | Combined index of functions, objects, routes, and views for editor tooling |
| `reflect::help` | All available methods with signatures and descriptions |

**CLI dispatch rules:**
- `build::method` and `reflect::method` call static methods on the engine classes
- `object.method` calls a method on a Phlo runtime object (e.g. `app.someMethod`)
- All output is JSON on stdout; errors go to stderr with a non-zero exit code
- Only available when `build: true`

---

## Runtime config

Runtime and host-specific settings live in `www/app.php`:

```php
phlo_app (
		id:        'Example'
    host:      'dev.example.nl',
    auth:      false,
    build:     true,
    debug:     true,
    dashboard: 'phlo',
    app:       '/path/to/app/',
    websocket: 3001,
);
```

- `auth` - `true` enables site-wide HTTP Basic Authentication (credentials in `data/auth.ini`).
- `build: true` and `thread: true` are mutually exclusive - build writes files to disk between requests which is unsafe in a long-running worker.
- `dashboard` - URL prefix for the built-in Phlo dashboard (omit to disable).
- `websocket` - App-specific WebSocket port. PhloWS runs one server per runtime and handles both `/websocket` upgrades and `/message` casts on the same port.

**WebSocket ports on this server:**
- `3001` - dashboard/control (`ws-dashboard`)
- `3002` - production app (`ws-app`)
- `3003` - development app (`ws-app-dev`)

**Worker mode:**
```php
phlo_app(
    host:   'example.nl',
    build:  false,
    app:    '/path/to/app/',
    thread: true,
);
```

`thread: true` means unlimited requests per worker. Use an integer to cap the number of requests per worker.

Worker-safe code rules: no `die()` or `exit()` in the HTTP path; no static properties holding per-request state; use `$this->objPers = true` on objects that should survive between requests (e.g. DB connections).

`obj` uses static structure lookup caches for `method_exists()` and computed prop method detection. These caches may store class/method structure only. Do not use static caches for request-, session-, user-, payload-, DB-, or time-dependent values unless the cache key and lifecycle are explicitly worker-safe.

`model` keeps ORM runtime state request-local in `%req->model`. `fields()` caches only `schema()`-derived fields there; legacy `static::$fields` and explicit `static::$columns` remain live overrides. `columns()` is cached per request with a DB-aware key because it depends on field quotes/table context. `objRecords`, `objLoaded`, relation metadata, and `objIncludeDeleted` must not be used as worker-persistent state; keep identity-map and relation preload state scoped to `%req`.

Current ORM model helpers assume an `id` column for identity-map and relation tracking. Tables with a non-`id` primary key, such as `barcode` or `sku`, should use direct `%MySQL->query()` calls for record lookup until the model layer explicitly supports an id-column override. If a model extends `model`, prefer an integer `id` primary key unless you have a documented workaround.

### Dashboard

The built-in dashboard is available when `dashboard` is set in `www/app.php` and `build: true`.

Current dashboard sections:

| Section | Purpose |
|---------|---------|
| `home` | Compact app status, build status, source/build counts, recent errors |
| `config` | Edit `data/app.json`; search and toggle available resources |
| `source` | Browse app `.phlo` sources, loaded resource sources, or all available unloaded resources with client-side Phlo highlighting and search |
| `build` | Run build, flush compiled files, inspect generated files, and search generated output |
| `release` | Run release build and inspect/search release output when release config exists |
| `errors` | Inspect and clear `data/errors.json` |

Dashboard file links resolve to app `.phlo` sources, resource `.phlo` files, compiled `php/` and `www/` output, or a hidden inspect view for other readable files. Hidden inspect views are not part of the nav and only exist to make error/home links usable.

Dashboard POST actions should use the Phlo SPA response protocol where practical. Avoid redirect-only mutations for toggles, builds, release actions, and config edits unless the whole page state truly needs to reset.

There are no separate `nodes`, `api`, or `reflection` dashboard sections in Delta. Use CLI `reflect::` methods for callable surface area, routes, views, resources, and raw introspection.

Debug error pages may link file locations back to dashboard source/build views when `dashboard` is enabled and the file can be mapped to an app `.phlo` source file or compiled `php/`/`www/` output.

### Custom path constants

**Every named argument passed to `phlo_app()` becomes a PHP constant** available throughout the app. Use this for app-wide paths:

```php
phlo_app(
    app:      '/path/to/app/',
    build:    true,
    host:     'dev.example.nl',
    langs:    '/path/to/app/langs/',
    files:    '/path/to/app/files/',
    composer: '/path/to/app/',
);
```

These constants are then usable directly in `.phlo` source code and resources:

```phlo
$lang = parse_ini_file(langs.'en.ini')
$path = files.'uploads/'
```

`reflect::runtime` lists all defined constants so agents can discover what is available without reading `www/app.php` directly.

---

## Build config (data/app.json)

Build-only settings. Do **not** put `debug`, `host`, `cli`, or `websocket` here.

**Keep `data/app.json` as minimal as possible.** The build system has sensible defaults - only add keys that differ from them:

| Key | Default | Notes |
|-----|---------|-------|
| `extends` | `"obj"` | Base class for all compiled files |
| `routes` | `true` | Route discovery enabled |
| `buildCSS` | `true` | CSS compilation enabled |
| `buildJS` | `true` | JS compilation enabled |
| `minifyCSS` | `false` (dev) / `true` (release) | Omit unless overriding |
| `minifyJS` | `false` (dev) / `true` (release) | Omit unless overriding |
| `minifyPHP` | `false` (dev) / `true` (release) | Omit unless overriding |
| `comments` | `true` | Source-map comments in output |
| `phloJS` | `false` | Phlo JS namespace |
| `exclude` | `[]` | Source files to skip |

A typical minimal config:

```json
{
  "resources": ["DB/MySQL", "session", "slug"],
  "release": "%app/release/"
}
```

`icons` is only needed when using the Phlo SVG sprite engine - omit it otherwise.

Path placeholders: `%app/` (app root) and `%phlo/` (engine root). Never use plain relative paths.

Custom resource search paths (only needed when adding app-local resources alongside engine defaults):

```json
{
  "paths": {
    "resources": ["%phlo/resources/", "%app/cms/"]
  }
}
```

`libs`, `functions`, `paths.libs`, and `paths.functions` are obsolete and must not be used. Delta build fails hard when these keys are present.

Use the object form for `release` only when `www` is not the default location or when overriding minify settings for release:

```json
{
  "release": {
    "php": "%app/release/",
    "minifyCSS": false,
    "minifyJS": false
  }
}
```

Plain string form when `www` sits in the default location (`release/www/`):
```json
{ "release": "%app/release/" }
```

---

## Native functions

Phlo kernel functions live in `phlo.php` and are always available. `reflect::functionIndex` marks them as `source: "native"` with:
- `group: "engine"` - core runtime functions
- `group: "debug"` - debug helpers (`debug: true` only)

Reusable function resources live under `resources/` and carry `source: "function"` in reflection. Some are in root, others are grouped by domain such as `AI/answer` or `Security/token`. Do not create a `.phlo` function whose PHP name matches a native function.

Notable natives that are **not** obvious from their names:
- `indent(string, depth)` / `indentView(string, depth)` - string indentation helpers; `indentView` is also emitted by the compiler for `{{ expr }}` on its own line inside `<foreach>`/`<if>` blocks, so it must stay native
- `regex(pattern, subject, flags, offset)` / `regex_all(...)` - thin wrappers around `preg_match`/`preg_match_all`, used by core resources
- `json_read(file, assoc)` / `json_write(file, data, flags)` - JSON file I/O, used by core resources
- `duration(decimals, float)` - time elapsed since request start; used by debug output
- `size_human(size, precision = 0)` - human-readable byte size (B/KB/MB/GB/TB); used by debug output
- `phlo_css(input, compact)` / `css_phlo(input)` - CSS transpiler bridge, lazy-loaded

### Phlo CSS runtime API

The CSS transpiler is available at runtime and in builds through native lazy-loaded functions:

| Function | Direction |
|----------|-----------|
| `phlo_css($input, $compact = true)` | Phlo CSS -> CSS |
| `css_phlo($input)` | CSS -> Phlo CSS |

Use these functions instead of calling the internal `build_css` class directly. The implementation is loaded only when one of these functions is used, so production apps do not pay a boot cost.

---

## Diagnosing parse errors

### `unexpected token "<"` in generated PHP

HTML has ended up in controller code because a blank line inside a view closed the block prematurely.

**Fix checklist:**
1. Open the `.phlo` file and find every `view name:` block
2. Remove any blank lines inside view bodies
3. Ensure `<style>` and `<script>` blocks are preceded by a blank line (to close the preceding view before starting an asset block)
4. Re-run `build::run` and `build::lint`

### `unexpected ";"` / broken logic

A multiline argument list is missing trailing commas. Add a comma to the end of every argument line, including the last.

### CSS not rendering correctly or truncated

A CSS property value was split across multiple lines. Merge the entire value onto one line.

### Multiline strings break into multiple statements

Phlo terminates statements by line ending and does not currently treat multiline quoted strings as one statement. Keep string literals on one line, or build long SQL/text with `implode(' ', [...])` over an array of one-line strings.

### `build::lint` reports an error in a generated file

Never edit the generated PHP. Find the corresponding `.phlo` source, fix the syntax there, and rebuild.

---

## Migration from v1 (Alpha) to v4 (Delta)

| Alpha (v1) | Delta (v4) |
|------------|------------|
| `uri` (everywhere) | `path` |
| `apply(uri: ...)` | `apply(path: ...)` |
| `app.uri` in JS | `app.path` |
| `req()` function | `phlo('req')->part($index)` |
| `define('req', ...)` | `phlo('req')->path`, `->method`, `->async` |
| `cli` constant = boolean | `cli` constant = PHP binary path (string) |
| `die()` / `exit()` in HTTP path | `return` - never `die()` in request path |
| `title('Page Name')` | pass title to `view($this->view, 'Page Name')` |
| `phlo_app_jsonfile()` | `phlo_app(build: true, ...)` |
| `php www/app.php debug` | `php www/app.php build::run` then `build::lint` |
| Thread via `function_exists` check | `thread: true` in `phlo_app()` |
| `dx($v)` calls `die()` | `dx($v)` throws exception - safe in worker mode |
| Basic auth credentials in `data/creds.ini [dashboard]` | credentials in `data/auth.ini` |

---

## What not to do - quick reference

| Never | Instead |
|-------|---------|
| Comments of any kind in `.phlo` | No comments |
| Semicolons in `.phlo` | Line endings terminate statements |
| Blank lines inside a view | Keep all view HTML contiguous |
| Splitting a CSS value across multiple lines | Entire value on one line |
| Multiline args without trailing comma on every line | Comma on every line including the last |
| Multiline quoted strings | `implode(' ', [...])` with one-line string parts |
| Edit generated PHP, CSS, JS, or release output | Edit only source files and rebuild |
| `apply(uri: ...)` | `apply(path: ...)` |
| `app.uri` in JS | `app.path` |
| `die()` or `exit()` in HTTP request path | `return` |
| `.then()` or `await` on frontend API calls | Fire-and-forget - no return value |
| Multiple `view()` or `apply()` calls per request | One terminating call per request path |
| Treating `cli` as a boolean | `cli` is the PHP binary path string |
| Inline `<if>` or `<foreach>` inside HTML | Control tags always on their own line |
| `route GET /` for the homepage | `route GET => $this->home` |
| Underscores in `.phlo` file names | Dots as separators (`page.home.phlo`) |
| Runtime values (`host`, `debug`, `cli`) in `data/app.json` | Put them in `www/app.php` |
| Relative paths in `data/app.json` | Use `%app/` or `%phlo/` prefixes |

---

## Skill maintenance

Treat this file as the source of truth. If it is copied into an agent's local skill directory, refresh that installed copy after changing this file. Do not hardcode machine-specific filesystem paths in this skill; use placeholders, `%app/`, or `%phlo/`. Avoid `../` path examples.
