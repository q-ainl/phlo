---
name: phlo
description: "Work with Phlo projects: write and edit source files, trigger builds, introspect routes, views, functions and resources via CLI, diagnose build or parse errors, and understand app structure. Use for any task involving Phlo source files, the build system, or CLI introspection."
---

# Phlo - Complete Language and Build Reference

## Overview

Phlo is a compile-to-PHP framework. Source files have the `.phlo` extension and compile to PHP, CSS, and JS. **Never edit generated files** in `php/`, `www/app.js`, `www/app.css`, or release output such as `release/php/`, `release/www/app.js`, and `release/www/app.css`.

Each `.phlo` file compiles to exactly one PHP class. The class name is derived from the file name: dots become underscores (`page.home.phlo` -> class `page_home`). File names use dots as separators - **never underscores**.

---

## CRITICAL SYNTAX RULES

These rules are absolute. Violations produce parse errors or silently broken output. Read before writing a single line.

### 1. Comments on their own line only, no semicolons

Statements are terminated by **line endings**, never by semicolons.
Comments are allowed only as **full lines** starting with `//` or `#`. A comment line is attached to the node below it and emitted into the compiled output. Never place a comment after code on the same line, and never put comments inside view HTML (they would render as visible text).

```
OK  $x = 1
OK  // explains the node below this line
NO  $x = 1;            <- no semicolons
NO  $x = 1 // inline   <- no inline comments
NO  /* comment */      <- no block comments in .phlo code
```

### 2. The line parser: every line ends a statement, and how to continue one

The compiler appends a `;` to **every** line, then removes it again where the line clearly continues. The complete rule set:

- A `;` is appended at every line ending.
- That `;` is removed when the line ends with `(`, `[`, `{`, `}`, `,` or `.`. These are **implicit continuations**: open delimiters, a trailing comma in an argument list, or a string concatenation broken after the `.`.
- A line ending in a single backslash `\` is an **explicit continuation**: the parser removes both the backslash and the `;`, joining the line with the next. Use it for everything the implicit set does not cover, such as multi-line ternaries or conditions split across lines:

```
$html .= $prev === null \
	? tag('span', void) \
	: tag('a', $prev->title, href: "/guide/$prev->slug")
```

- Blank lines never produce a `;`.

**The one exception - a statement ending in `}`.** The `}` trigger above assumes `}` closes a block. When `}` instead closes a property-name interpolation (`$obj->{$expr}`, `%payload->{$key}`) or an inline closure assigned to a variable (`$fn = function(){ ... }`), the line is a complete *statement*, not a continuation - yet the auto-`;` is still stripped, so the line merges into the next one and PHP reports `unexpected token` one line down. Add an explicit `;`; this is the single place in `.phlo` where a trailing `;` is correct:

```
OK  $file = %payload->{$this->name};
OK  method label($record) => $record->{$this->name};
OK  $assign = function($v) use ($keys){ ... };
NO  $file = %payload->{$this->name}      <- ; stripped, next line breaks
```

The mental model: never think about semicolons. Only ask "is my statement complete on this line?" If it is not, end the line with one of `( [ { , .` (natural in most multiline code) or an explicit `\`.

**Lesson:** the FILE-level node parser tracks multiline node bodies by counting parentheses only, not square brackets. A multiline `prop x => [ ... ]` therefore ends the node at the first line and the rest becomes stray controller code (`Controller must be in one place`). Open multiline node bodies with a parenthesis: `prop x => arr(...)` or `prop x => array_merge(...)`.

Without an implicit or explicit continuation, every line becomes its own statement. This is why multiline arguments require a trailing comma on **every** line:

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

A **blank line immediately closes the current view**. HTML after a blank line would become controller code; the build stops with `HTML outside a view at <file>:<line>`, naming the blank line and the view it closed. This is the most common source of errors.

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

`<script>` and `<style>` blocks close only at their literal `</script>` / `</style>` line; blank lines inside them do not close them. A new `view`, `<script>`, or `<style>` block must still start after the previous view has been closed (a blank line after the view, then the block).

### 4. CSS: one declaration per line

The CSS parser treats each line as one complete declaration. A long value may only be wrapped in two ways: end the property line with a bare `:`, or end each continuation line with a `,`. Any other wrap is a build error (`CSS line is not a declaration`).

```
OK  entire value on one line:
background: linear-gradient(to bottom, #000, #fff)

OK  dangling colon; continuation lines are joined automatically:
background:
  radial-gradient(800px 500px at 80% 20%, rgba(255,199,107,.18), transparent 60%),
  linear-gradient(180deg, #000 0%, #050714 100%)

NO  wrapping inside a value without a trailing comma or dangling colon:
background: linear-gradient(
  to bottom, #000, #fff
)
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

Attribute values interpolate `$var`, `$this->prop` and `%instance->prop` **directly**, including with a literal suffix. Never wrap plain property access in `{{ }}` inside an attribute; reserve `{{ }}` for function/method calls and `{( )}` for expressions:

```
OK  <a href="%base->view/install">
OK  <a href="$this->url">
NO  <a href="{{ %base->view }}/install">   <- redundant, ugly
OK  <a class="card {( $active ? 'is-active' : void )}">
OK  <button data-target="{{ $this->target() }}">
```

### 8. Never combine class/id shorthand with an explicit class or id attribute

A tag uses EITHER the `.class`/`#id` shorthand OR an explicit attribute for that same property, never both. Combining them can emit a duplicate attribute (`class="a" class="b"`) and the browser keeps only the first, silently dropping the dynamic one. When part of the class is dynamic, write the whole thing as one attribute; keep shorthands for fully static tags, and prefer `#id` shorthand for static ids:

```
OK  <a.site-logo href="/">                          (fully static: shorthand)
OK  <nav#index-nav.sidebar-nav>                     (static id + class shorthand)
OK  <a class="site-logo {{ $this->extra }}" href="/">  (dynamic: one class attribute)
NO  <a.site-logo class="{{ $this->extra }}">        <- duplicate class attribute
NO  <div.panel id="panel-$key">                     -> write class in the attribute too
```

---

## Source file structure

```
www/app.php          <- entry point and runtime config (PHP, not .phlo)
data/app.json        <- build config (resources, paths, flags)
data/app.md          <- app documentation: structure, TODO items, agent notes (read/write)
data/errors.json     <- runtime error log
data/auth.ini        <- auth credentials (Control Center / site)
*.phlo               <- source files (app root)
resources/           <- reusable resources: objects, functions, frontend assets, or hybrids
php/                 <- generated PHP - never edit
www/app.js           <- generated JS - never edit
www/app.css          <- generated CSS - never edit
```

Core resource folders are intentionally limited to larger domains: `AI/`, `CSS/`, `DB/`, `DOM/`, `Fields/`, `Files/`, and `Security/`. General-purpose resources such as `active`, `age`, `HTTP`, `session`, `slug`, `tag`, `phlo.sync`, and `websocket` live directly in `resources/`.

Generated classes are loaded through `php/classmap.php`. Do not rely on classname-to-filename autoload fallback behavior; if a class is not in the classmap, rebuild or fix the source/config.

### Resource dependencies and graph metadata

Resource `@ requires` metadata is used by reflection and the Phlo Control Center. When a resource is enabled from the Control Center, required resources are added to `data/app.json` as well. Disabling a resource removes only that resource; dependencies remain enabled because they may be shared or intentionally loaded.

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

### app.md: agent scratchpad and app documentation

`data/app.md` is a plain-text Markdown file. It is optional but valuable:
- Describes the app's purpose, main routes, and key data structures
- Lists open TODOs, known issues, and ongoing work
- Persists context between agent sessions: read it before starting, update it after finishing

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
- The `visitor` class tracks page views per session and sends notifications to the Phlo Dashboard (the separate fleet app) on its WebSocket port by default
```

The Phlo Control Center shows `app.md` on the Home page and provides an editor under Config.

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

Prefer the constant over the raw literal in backend/DSL code: `implode(comma, $parts)` not `implode(',', ...)`, `$out = void` not `$out = ''`, `rtrim($p, slash)` not `rtrim($p, '/')`. Constants are PHP/DSL only - never inside `<script>` JavaScript or `<style>` CSS, and never to replace a character *inside* a composite string, regex, URL or path (only standalone separator/value literals).

### Instance shorthand

```
%MySQL           -> phlo('MySQL')
%payload         -> phlo('payload')
%session         -> phlo('session')
%JSON('file')    -> phlo('JSON', 'file')
```

**Lesson:** the compiler rewrites `%name` EVERYWHERE in a `.phlo` file, including inside string literals. A docs page that tried to print the literal text `%session` in example code shipped `phlo('session')` to visitors instead. Phlo example code that must stay verbatim belongs in external files (`.txt`, `.md`) loaded at runtime, never in `.phlo` string literals.

### Metadata annotations

Placed at the top of a `.phlo` file, before any nodes. Any `@ key: value` line is stored as file metadata; these keys have engine or tooling meaning:

```
@ class: myName       <- override the PHP class name
@ extends: model      <- PHP inheritance (default: obj)
@ implements: A,B     <- PHP interfaces
@ use: Full\Name as X <- PHP use statement
@ namespace: My\Ns    <- PHP namespace
@ type: class         <- node type: class (default), interface, trait, abstract class
@ summary: ...        <- description for tooling (reflection, Control Center, manual)
@ package: ...        <- package group for tooling
@ frontend: true      <- marks lib as frontend-only
@ backend: true       <- marks lib as backend-only
@ requires: a, b?     <- dependencies (see Resource dependencies section)
@ provides: app.mod.x <- frontend APIs this resource provides (selector graph)
@ binds: form.async   <- frontend selectors/events this resource hooks (selector graph)
@ tags: experimental  <- free-form tags, shown in reflection indexes
@ advice: use X for Y <- developer guidance, shown in reflect::objectIndex
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

Usage: `$this->repeat(5)`. Results are cached per argument set; the no-argument form caches on first access.

Props and methods **without arguments** are called without `()`. Static methods **always require `()`**.

**Lesson:** a concrete prop in a parent class SHADOWS a computed prop in a child. `prop dir = void` in an abstract parent compiles to a real PHP property, so a child's `prop dir => guide` getter is never consulted: `$this->dir` reads the parent's `void`. When children must override a prop with a computed one, declare it computed in the parent too (`prop dir => void`).

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
Call computed statics with `ClassName::cashCoupures()`. Primitive scalar `static name = value` works, but complex literal `static name = [...]` can produce misleading parser errors in current builds.

### The obj base class

Every compiled class extends `obj` (classes/obj.php). Its powers go well beyond `__get`/`__set`:

**Interception hooks.** Implement any of these to trap the access chain; returning `null` falls through to the default behaviour, anything non-null short-circuits:

```phlo
method objCall($method, ...$args) => str_starts_with($method, 'find') ? $this->finder($method, $args) : null
method objGet($key) => $this->lazy[$key] ?? null
method objSet($key, $value) => $key === 'readonly' ? true : null
```

- `objCall($method, ...$args)` runs before closure/method/prop lookup on unknown calls.
- `objGet($key)` runs before data/closure/method/prop lookup on reads.
- `objSet($key, $value)` runs before assignment on writes; a non-null return swallows the write.

**Bound closures.** Assigning a closure binds it to the instance: `$obj->greet = fn() => "Hi $this->name"` and `$obj->greet()` later runs with `$this` bound. Stored separately in `objClosures`, never serialized.

**Data API.** `objImport(...$data)` bulk-assigns named values and returns `$this` (chainable; `new obj(name: 'x')` uses it). `objData` is the raw storage array; `objKeys()`/`objValues()`/`objLength()` inspect it; `objClear()` wipes it; iteration (`foreach $obj`) and `json_encode($obj)` expose exactly `objData`. Every write or unset flips `objChanged = true`, which is what the ORM uses as its dirty flag.

**Computed prop caching.** `prop x => ...` compiles to `_x()`; results cache in `objProps`, keyed per serialized argument set. Statics differ: a bare `static x => ...` compiles to a plain method and is recomputed on every call; only the underscore form `_x()` (reached as `x()` via `__callStatic`) caches per class in `obj::$classProps`. For per-request static state cache it on `%req` (`static state => %req->model ??= ...`), which resets each request.

**Worker persistence.** Set `$this->objPers = true` (or `prop objPers = true`) and the instance survives between worker-mode requests: `phlo()`'s internal registry only keeps `objPers` instances on the per-request reset. Use it for DB connections and parsed config; never for request- or user-scoped state.

### Cross-resource node modifiers

Any `.phlo` file can inject or override a node in **another** resource's class at build time by naming the node `%<targetClass>.<nodeName>`:
```phlo
static %visitors.table = 'control.visitors'
prop %visitors.db = 'control'
method %model.greet => 'hi'
```
The first line overrides the `visitors` model's `static $table`; the second adds or overrides a `db` prop on `visitors`; the third adds a `greet` method to the `model` class. At build the compiler strips the `%<class>.` prefix and writes the node into `<class>`, **overwriting** an existing node of that name (or adding a new one). The target class must be part of the current build (its resource loaded), otherwise the modifier is silently ignored. Match the node *type* you replace (override a `static` with `static`, a `prop` with `prop`); the whole node is swapped.

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

**Lesson:** any other return value is DISCARDED. `route GET hello => 'Hello'` matches and returns a 200 with an empty body; the dispatcher only inspects the value for `=== false`. A route produces output exclusively through `view()`, `apply()`, `output()`, `location()` or the `%res` API.

The `false` fall-through is also a tool: a `route GET guide $slug` catch-all that returns `false` for unknown slugs lets a later literal route (such as `GET guide index.json` in another file) still match.

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

**view() parameter glossary** (all optional, named): `title` (page title, combined via `title()`), `css`/`js`/`defer` (extra assets next to the ns bundles), `options` (body class list), `settings` (body `data-*` attributes), `ns` (bundle namespace, default `app`), `path` (browser URL; `false` keeps current), `inline` (embed local css/js into the HTML instead of linking), `bodyAttrs`/`htmlAttrs` (extra attributes), `lang`, `code` (HTTP status for a sync page, for example `view(body, code: 404)`; dropped on async since the apply transport must stay 200), plus any apply command (`scroll: 0`, `trans: 'fade'`) as trailing named args. App-level defaults come from `%app` props with the same names; the `<head>` is further fed by `%app->description`, `%app->viewport`, `%app->themeColor`, `%app->nonce`, `%app->head`, `%app->link` and `%app->version` (asset cache-buster).

**`output()` - files, blobs and JSON with a status.** `output($content, $filename = null, $attachment = null, $file = null, $code = null, $type = null)` sends one response body and renders. Use it for anything that is not an HTML page or an `apply()` batch:
- File/blob: `output($bytes, 'export.csv', true)` (attachment) or `output(file: $path)` (serve a file, mime by name).
- JSON with a status: an `array` (or an `object` when `type: 'application/json'` is passed) is JSON-encoded automatically; `code` sets the HTTP status. `return output(['id' => $id], code: 201)`, `return output(['error' => 'not found'], code: 404)`.
- `type` overrides the content-type for a pre-encoded string body: `output($json, type: 'application/json')`.

Call `output()` directly; do not wrap it in a per-app `jsonOut()`/`respond()` helper.

**`error()` - abort with a status, content-negotiated.** `error('message', $code = 500)` throws and ends the request; the engine renders it to fit the context:
- async/SPA request -> `apply(error: 'message')` (the SPA shows it).
- JSON context (the route called `%security->api`, or the client sent `Accept: application/json`) -> a JSON body `{"error": "message"}` with `$code`.
- otherwise -> the HTML error page (full debug page when `debug`, minimal otherwise).

Client errors (`$code < 500`) keep their message in the JSON/async output; server errors (`>= 500`) stay generic (`"Error"`) unless `debug` is on, so uncaught-exception internals are not exposed by default. `error()` throws, so no `return` is needed (a leading `return` is harmless).

**Which to use.** HTML page route: `view(body, code)` for a custom page, or `error('msg', code)` to abort to the standard error page. Async/SPA route: `apply(error: 'msg')` for an inline message. JSON API / webhook: `output($data, code)` for success and `error('msg', code)` for errors. For a deliberate non-standard JSON error shape (such as an `{ok, error}` webhook contract) use `output(['ok' => false, 'error' => '...'], code: 400)` directly. A redirect is `location($url)`, which defaults to 302; pass a code for a permanent redirect, `location($url, 301)`.

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

Pseudo-selectors - backslash glues to parent (works for classes, ids and attribute selectors too):
```phlo
a {
  text-decoration: none
  \:hover: color: blue
  \.is-active: color: lime
}
```
Output: `a:hover { ... }` and `a.is-active { ... }`; without the backslash, `.is-active` would nest as the descendant `a .is-active`.

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

`app.path` is the current path; `app.uri` does not exist.

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
| `inner` | Replace innerHTML |
| `outer` | Replace outerHTML |
| `main` | Replace `<main>` (or body if there is no main) |
| `before` / `after` | Insert HTML adjacent to element |
| `prepend` / `append` | Insert HTML as first / last child |
| `remove` | Remove element(s) |
| `attr` | Set/remove attributes (null removes) |
| `class` | Add / remove (`-`) / toggle (`!`) classes |
| `value` | Set form value |
| `data` | Set `dataset` key(s) |
| `title` | Set page title |
| `lang` | Set `html` lang |
| `options` | Replace body class list |
| `settings` | Set body data attributes |
| `path` | Update browser URL |
| `trans` | Page transition |
| `scroll` | Scroll position (int or `#anchor`) |
| `css` / `js` / `defer` | Add stylesheet / script / deferred script (once) |
| `location` | Navigate (path, `true` reloads current; external URL via `assign`) |
| `call` | Call `app[name]()` |
| `log` / `error` | Console log / error handler |
| `phlo` / `debug` / `dump` | Debug output to the browser console (debug mode) |

**Streaming.** `chunk(...)` opens a streaming response and flushes each package immediately as one newline-delimited JSON line (`application/x-ndjson`) instead of buffering, so progressive updates reach the frontend over a single HTTP response with no WebSocket. Call it repeatedly for long-running work (AI token streams, batch progress); the frontend keeps applying the commands as they arrive. `apply(...)` and `view(...)` are single, finalizing responses, but once `chunk()` has opened the stream they compose into it (flushing instead of finalizing), so a stream can end with one closing `apply(...)`. (`chunk()` ships as the `chunk` resource; `apply()`/`view()` are built in.)

```phlo
route async POST report::generate {
	foreach ($this->steps AS $i => $step){
		$step->run
		chunk(inner: arr('#progress' => $i + 1 .'/'. count($this->steps)))
	}
	apply(toast: 'Done')
}
```

For the full semantics (targeting forms, streaming, error handling and the `app.res` extension point) see [apply-protocol.md](apply-protocol.md).

---

## Build and introspection workflow

Every Phlo app exposes a CLI layer via `build::` and `reflect::` commands. **Use these to understand an app before editing anything.**

> **Never run CLI commands against a live production server.** The CLI is only available when `build: true`. Always use a dev environment.

On servers where `php` is not in PATH, use `/usr/bin/php-zts` for CLI checks and linting.

### Recommended agent workflow

1. **Orient** - run `reflect::context` for a single snapshot: app identity, route/view counts, loaded packages, and recent errors. Follow with `reflect::compactRoutes` for route detail.
2. **Explore** - use `reflect::sourceFiles`, `reflect::find <type>`, `reflect::search <query>`, `reflect::nodeBody <name>`, `reflect::fileContent <relPath>`
3. **Execute** - use `phlo_eval '<phlo>'` to run an expression or block against the live app: read records, call a method, render a view, reproduce an expression. The runtime complement to reflection's static view (see below)
4. **Read** - read the actual `.phlo` source files directly before editing
5. **Edit** - only `.phlo` source files
6. **Build** - `php www/app.php build::run`
7. **Lint** - `php www/app.php build::lint` - empty array = clean; if errors, fix the `.phlo` source (not the generated PHP), then repeat from step 6
8. **Update app.md** - after completing a task, update `data/app.md` to reflect any structural changes, new routes, resolved TODOs, or remaining work

### build:: commands

| Command | Returns |
|---------|---------|
| `build::run` | Triggers a build; returns changed file paths |
| `build::lint` | PHP parse errors in compiled files - empty array = clean |
| `build::config` | Full build config from `data/app.json` |
| `build::changed` | Source files changed since last build |
| `build::buildFiles` | All compiled output file paths (`php/` + `www/`) |
| `build::release` | Compiles a release build with release hooks; returns changed file paths |
| `build::releaseFiles` | Compiled PHP and web output paths for the release build |
| `build::flush` | Deletes all compiled files |
| `build::traceShadow` | Regenerates the engine's `functions.trace.php` from `functions.php` |
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
| `reflect::findFunction <name>` | Resource entry for a function by name, or null |
| `reflect::findClass <name>` | Resource class entry by name or class alias, or null |
| `reflect::help` | All available methods with signatures and descriptions |

**CLI dispatch rules:**
- `build::method` and `reflect::method` call static methods on the engine classes (these run outside the app: no app boot, source-only)
- `object.method` calls a method on a Phlo runtime object (e.g. `app.someMethod`)
- `function args` calls a global function (e.g. `phlo_eval`, `answer`); these boot the app first
- All output is JSON on stdout; errors go to stderr with a non-zero exit code
- Only available when `build: true`

### phlo_eval

`phlo_eval '<phlo source>'` transpiles a string of Phlo statements and runs them in the live app. It is the runtime complement to `reflect::`: where reflection reads the static source, `phlo_eval` executes against real data, resources and views.

```bash
php www/app.php phlo_eval "user::recordCount()"            # 38, no return keyword
php www/app.php phlo_eval "%app->title"                    # "App"
php www/app.php phlo_eval "echo %app->error('boom')"       # <div class="app-error">boom</div>
```

- A single line auto-returns, exactly like a `=>` arrow body, so you write just the expression, no `return`. The exceptions are lines starting with `return`/`apply`/`echo`/`unset`/`yield`. Only a multiline block needs its own `return`.
- `return <expr>` prints the value as JSON: type-safe, non-scalar-capable, and errors come back as `{"error": ...}`. `echo <expr>` prints raw to stdout, for viewing rendered HTML/markup as-is.
- `%resource` refs resolve as normal. The app is constructed but its app-controller (router/init) is skipped, like any CLI callback; resource controllers do run, so the database and resources are live.
- **Power and danger:** it runs arbitrary code with full app privileges against the live app. It can read secrets and PII and mutate the database (`%db->exec(...)`). It is **CLI-only and build-only** by design and never exists in a release/prod app. Treat the source as developer-authored only; never pass it untrusted input.

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
    app:       '/path/to/app/',
    daemon:    3001,
);
```

- `auth` - `true` enables site-wide HTTP Basic Authentication (credentials in `data/auth.ini`).
- `build: true` and `thread: true` are mutually exclusive - build writes files to disk between requests which is unsafe in a long-running worker.
- `control` - URL prefix for the built-in **Phlo Control Center**. Auto-defaults to `'phlo'` (so `/phlo`) whenever `build: true` and `debug: true`; set `control: 'admin'` to move it or `control: false` to disable. This is NOT the Phlo Dashboard, which is the separate fleet-management app.
- `daemon` - the port the Phlo Daemon listens on. Phlo Realtime, the WebSocket layer built into the daemon, handles both `/websocket` upgrades and `/message` casts on that one port.

WebSocket support is optional and provided by Phlo Realtime, the WebSocket layer built into the Phlo Daemon (one process, no separate repository). Each runtime points at the daemon's port via `daemon:`; Phlo Realtime serves multiple hosts on one port and routes by `Host` header. See `docs/websocket-contract.md`.

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

### Phlo Control Center

**Terminology, keep these apart:**
- **Phlo Control Center**: the per-app dev panel built into the engine. Auto-mounts at `/phlo` when `build: true` and `debug: true`; override the path with the `control:` key. Requires build + debug.
- **Phlo Dashboard**: a separate fleet-management application (its own repository, `phlo-dashboard`) for managing many apps and servers: fleet overview, hosts, domains, databases, notifications, visitors.

The `control:` key sets where it mounts; what it mounts is the Control Center, not the Phlo Dashboard.

Current Control Center sections:

| Section | Purpose |
|---------|---------|
| `home` | Compact app status, build status, source/build counts, recent errors |
| `config` | Edit `data/app.json`; search and toggle available resources |
| `source` | Browse app `.phlo` sources, loaded resource sources, or all available unloaded resources with client-side Phlo highlighting and search |
| `build` | Run build, flush compiled files, inspect generated files, and search generated output |
| `release` | Run release build and inspect/search release output when release config exists |
| `errors` | Inspect and clear `data/errors.json` |

Control Center file links resolve to app `.phlo` sources, resource `.phlo` files, and compiled `php/` and `www/` output, shown through the Source, Build and Release views. Links to files outside those known sets render as plain text rather than opening an arbitrary-file viewer.

Control Center POST actions should use the Phlo SPA response protocol where practical. Avoid redirect-only mutations for toggles, builds, release actions, and config edits unless the whole page state truly needs to reset.

There are no separate `nodes`, `api`, or `reflection` Control Center sections. Use CLI `reflect::` methods for callable surface area, routes, views, resources, and raw introspection.

Debug error pages may link file locations back to Control Center source/build views when `dashboard` is enabled and the file can be mapped to an app `.phlo` source file or compiled `php/`/`www/` output.

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
| `defaultNS` | `"app"` | Namespace(s) for assets without an explicit `ns=` (comma-separated) |
| `phloNS` | `["app"]` | Namespaces whose JS bundle embeds the phlo.js runtime |
| `phloJS` | `false` | Inverts `phloNS`: embed the runtime in every namespace NOT listed there |
| `iconNS` | `"app"` | Namespace that receives the generated icon-sprite CSS |
| `resourceNS` | `{}` | Per-resource namespace override, keyed by resource path (e.g. `{"DOM/markdown": "app", "themes/cobalt": "app"}`); forces that resource's `<style>`/`<script>` into the given ns, overriding the block's own `ns=` |
| `exclude` | `[]` | Source files to skip |

### Namespaces and bundles

Every `<style>`/`<script>` block compiles into a per-namespace bundle: `ns=docs` goes into `www/docs.css`/`www/docs.js`, `ns=app,docs` into both, and blocks without `ns=` into `defaultNS`. A page selects its bundle with `view(..., ns: 'docs')`.

Two rules keep multi-namespace apps working:

1. **Every namespace whose pages load standalone needs the runtime**: list it in `phloNS` (e.g. `"phloNS": ["app", "docs"]`). Resource assets (helpers like `onExist`, cookiewall styles) carry no `ns=`, so widen `defaultNS` accordingly (e.g. `"defaultNS": "app,docs"`). Never work around a missing runtime by passing `defer: '/app.js'` to `view()`: on async navigation that re-injects the bundle and crashes with duplicate declarations.
2. **Two runtimes must never meet in one page**: links that cross namespaces (an `app` page linking to a `docs` page) must be plain links (full page load), only same-namespace links get `class=async` (SPA navigation).

A typical minimal config:

```json
{
  "resources": ["DB/MySQL", "session", "slug"],
  "release": "%app/release/"
}
```

`icons` is only needed when using the Phlo sprite engine - omit it otherwise. When set, it points to one or more folders of PNG files; the build composes them into a single `www/icons.png` sprite plus the CSS to use them. Naming convention: `name.png` becomes class `.icon.name`; `name.context.png` becomes `.icon.name` scoped to `body.context` (the same icon name can have per-context variants, e.g. per theme). Usage in views: `<i.icon.save/>`. The generated CSS lands in the `iconNS` bundle (default `app`), and the sprite is preloaded automatically by `view()`.

Path placeholders: `%app/` (app root) and `%phlo/` (engine root). Never use plain relative paths.

Custom resource search paths (only needed when adding app-local resources alongside engine defaults):

```json
{
  "paths": {
    "resources": ["%phlo/resources/", "%app/cms/"]
  }
}
```

`libs`, `functions`, `paths.libs`, and `paths.functions` are not config keys. The build fails hard when they are present.

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

### Debug helpers (debug: true only)

Three levels, one lifecycle: collect during the request, render into the browser console at the end.

- `d(...$data)` - dump values into the response. Inert without `debug: true`; safe to remove before release but harmless if forgotten.
- `dx(...$data)` - dump and STOP. Sync requests get a full debug page with the source-mapped `.phlo` file and line; async/CLI/streaming requests get an `apply(error, dump)` so the dump lands in the browser console. Worker-safe: it throws `RuntimeException('PhloDump')` instead of calling `die()`.
- `debug($msg)` - append a line to the debug log shown in the browser console; `debug()` without arguments returns everything collected so far.

In debug mode every sync page ends with an inline script (`debug_render`) that logs dumps, debug lines, memory, duration and trace metadata to the console; async responses carry the same data in the apply payload. Objects are unwrapped via `objInfo()` to a depth of 10.

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

### Translations (optional)

The `lang` resource adds multilingual text: the `{nl: ...}` / `{en: ...}` view shorthand for static text (compiles to `{{ nl('...') }}`, so it has no argument syntax), the `nl()` / `en()` functions for text with `sprintf` arguments, an AI-backed per-language cache in `langs/<lang>.ini`, and a `%lang.instructions` prop to steer terminology and avoid forced translations. See [translations.md](translations.md).

---

## Diagnosing parse errors

### `HTML outside a view at <file>:<line>`

A blank line inside a view closed the block prematurely, so the HTML below it would leak into controller code. The message names the blank line and the view that was closed.

**Fix checklist:**
1. Remove the blank line(s) inside the view body
2. Ensure `<style>` and `<script>` blocks are preceded by a blank line (to close the preceding view before starting an asset block)
3. Re-run `build::run` and `build::lint`

### `Missing trailing comma at <file>:<line>`

A line of a multiline argument list does not end with a comma. Add a comma to the end of every argument line, including the last.

### `CSS line is not a declaration`

A CSS value was wrapped across lines without a legal continuation. Merge the value onto one line, or use the dangling-colon form (see syntax rule 4).

### Multiline strings break into multiple statements

Phlo terminates statements by line ending and does not currently treat multiline quoted strings as one statement. Keep string literals on one line, or build long SQL/text with `implode(' ', [...])` over an array of one-line strings.

### `unexpected token` right after a line ending in `->{...}` or a closure

The statement ended in the `}` of a property interpolation (`$obj->{$expr}`) or an inline closure (`$fn = function(){...}`), so syntax rule 2 stripped the auto-`;` and merged the following line into it. Add an explicit `;` to the end of that statement (see syntax rule 2).

### `build::lint` reports an error in a generated file

Never edit the generated PHP. Find the corresponding `.phlo` source, fix the syntax there, and rebuild.

---

## What not to do - quick reference

| Never | Instead |
|-------|---------|
| Inline comments or comments inside views | Full-line `//` comments above code only |
| Semicolons in `.phlo` | Line endings terminate statements |
| Blank lines inside a view | Keep all view HTML contiguous |
| Wrapping a CSS value without `,` or dangling `:` continuation | One line, or the rule-4 continuation forms |
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
| Literal `%name` in `.phlo` string literals | External `.txt`/`.md` files; the compiler rewrites `%name` even inside strings |
| `.class`/`#id` shorthand combined with a `class=`/`id=` attribute | One full attribute when any part is dynamic |
| `{{ %x->prop }}` in view attributes | Direct interpolation: `href="%x->prop/suffix"` |
| Multiline ternary without continuations | End each continued line with `\` |
| Multiline `prop x => [ ... ]` | Open with a parenthesis: `arr(...)` or `array_merge(...)` |
| `die($content)` to send a response | `output(...)` or `%res->type/text()->render()`; `die()` skips headers and breaks worker mode |
| `defer: '/app.js'` to give another ns the runtime | `phloNS`/`defaultNS` in `data/app.json` |
| `class=async` on links that cross ns bundles | Plain links; two runtimes must never meet in one page |
| Returning a bare value from a route | `view()`/`apply()`/`output()`/`location()`; bare returns are discarded |
| A hooks file named `websocket.phlo` | Another name (`app.ws.phlo`); it collides with the engine resource |

---

## Skill maintenance

Treat this file as the source of truth. If it is copied into an agent's local skill directory, refresh that installed copy after changing this file. Do not hardcode machine-specific filesystem paths in this skill; use placeholders, `%app/`, or `%phlo/`. Avoid `../` path examples.
