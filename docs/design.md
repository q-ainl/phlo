# Phlo design notes

Why Phlo is built the way it is. These are the non-obvious decisions, the
reasons behind them, and the trade-offs they accept. Read this before
changing the engine: most of the surprising choices are deliberate.

## 1. A line-based parser, no AST

`build_file::parse()` (`classes/file.php`) reads a `.phlo` file line by line.
A statement ends at a line ending; a node header (`route`, `method`, `prop`,
`view`, `static`, `const`, `function`, `<style>`, `<script>`) opens a block;
a blank line closes a view. There is no tokenizer producing an abstract
syntax tree.

**Why.** The whole parser is a few hundred lines and one pass. Anyone can
read it in a sitting, and the generated PHP maps to source lines trivially
(line N in becomes a known line out), which is what makes the sourcemap and
the error pages possible.

**The trade-off.** The language is strict by construction: no multiline
quoted strings, a blank line ends a view, CSS declarations stay on one line.
These aren't bugs, they're the cost of not building a full grammar. The
chosen response to that strictness is **better diagnostics, not a more
forgiving parser**: when something violates a rule, the build stops with the
`.phlo` file and line (HTML outside a view, a missing trailing comma, a CSS
line that is not a declaration). Adding a real grammar to relax the rules
would trade the parser's legibility for convenience, and that is not a trade
Phlo makes.

## 2. Compile to readable PHP, with a sourcemap

`.phlo` compiles to plain PHP classes under `php/` (`build_builder`,
`build_node`). The output is meant to be read: one class per file, a header
comment naming the source, optional inline comments. A per-class sourcemap
(`php/sourcemap.php`) records PHP-line to `.phlo`-line.

**Why.** Two payoffs. First, you can always drop to the generated PHP to
understand exactly what runs; there is no hidden runtime interpreting a
template language. Second, a runtime error is translated back to the `.phlo`
line that produced it (`phlo_error_sourcemap()` in `error.php`), so the error
page and the Phlo Control Center links point at source you wrote, not at generated
code.

**The trade-off.** A build step exists. In development that is hidden by
rebuild-on-request (see 5); in production you build once.

## 3. The `obj` magic base class

Every compiled class extends `obj` (`classes/obj.php`) unless told otherwise.
`obj` is a magic container: arbitrary data via `__get`/`__set`, bound
closures, and **computed properties** written as `_name()` methods that are
called without parentheses and cached on first access (`$this->fullName`
calls `_fullName()` once). Computed props with arguments cache per argument
set.

**Why.** It collapses what would otherwise be boilerplate (getters, lazy
init, value objects) into one consistent access model that the `.phlo`
property syntax compiles onto directly: `prop now => time()` becomes a cached
`_now()`. The same object is the view model, the record, and the config bag.

**The trade-off.** Magic access is less statically analysable than explicit
properties. The mitigation is discipline about caches: the static structure
caches in `obj` (`$objMC`, `$objPC`) only ever hold class/method shape, never
request- or user-dependent values, so the model stays safe under worker mode
(see 5). Do not add static caches keyed on anything request-scoped.

## 4. `phlo()` as a tiny service registry

`phlo('MySQL')` returns a shared instance; in `.phlo` source `%MySQL` compiles
to exactly that call (`build_node::parseObjects`). Each class can implement
`__handle()` to decide its own identity: singleton, multiton by argument, or
always-new. Instances opt into surviving between worker requests with
`$objPers = true`.

**Why.** It gives dependency lookup without a container framework, config, or
annotations. The `%name` shorthand keeps call sites short and greppable, and
`__handle()` puts the lifetime decision in the resource that knows it, not in
a central wiring file.

**The trade-off.** It is service location, not injection, so dependencies are
implicit at the call site. In exchange there is zero wiring and the registry
is a dozen lines (`phlo()` in `phlo.php`).

## 5. Rebuild on request in development

With `build: true`, `phlo_load()` checks whether any source changed since the
last build and recompiles before handling the request (`build_base::changed()`
compares mtimes against `php/functions.php`, throttled to a 50/200ms window so
it is cheap in a hot loop). With `build: false` the app runs the compiled
output as-is.

**Why.** The edit-refresh loop feels interpreted while the runtime stays
compiled. No watcher process, no manual build during development.

**The trade-off.** `build: true` writes files during a request, which is
unsafe in a long-running worker, so **`build` and `thread` are mutually
exclusive** (enforced in `phlo_app()`). Development uses on-request builds;
production uses worker mode on a release build. This split is intentional,
not a limitation to engineer around.

## 6. No runtime dependencies

The engine ships its own CSS transpiler (`classes/css.php`), JS minifier
(`build_builder::minify_js`), icon-sprite builder (`classes/icons.php`) and
SPA runtime (`assets/phlo.js`). Composer is supported but lazy and optional
(`phlo_app()` registers a Composer autoloader only if `composer` is defined).

**Why.** The engine has nothing to audit but itself, upgrades on its own
schedule, and stays small enough to hold in your head. For a framework whose
selling point is legibility, a vendor tree would undercut the premise.

**The trade-off.** Phlo reimplements things that mature libraries already
solve, so those implementations must be tested (see `tests/`) and can have
edge cases a big library would not. The bet is that a small, owned surface is
worth more here than breadth.

## 7. Agent-first is a feature, not a side effect

`docs/SKILL.md` is a complete language and build reference written so an AI
agent can work without prior knowledge, and the `reflect::` CLI exposes
routes, views, the parsed AST, search and dependency graphs as JSON. Apps
keep a `data/app.md` scratchpad that an agent reads first and updates after.

**Why.** The same properties that make Phlo legible to a person (one closed
loop, source-mapped errors, JSON introspection, a single skill document)
make it tractable for an agent. Treating that as a first-class goal is how a
one-person framework competes: an agent can build, introspect and fix a Phlo
app reliably, which multiplies the author.

**The trade-off.** SKILL.md and `reflect::` are part of the contract. A
change to the language, the build, or the CLI is not done until SKILL.md
reflects it. That maintenance cost is accepted on purpose.

## Where contributions fit

The engine (`phlo.php`, `functions.php`, `error.php`, `debug.php`,
`control.php`, `classes/`) is held small and changed conservatively; the
safe, encouraged surface for contributions is `resources/`, which are
isolated, metadata-described `.phlo` files. See `CONTRIBUTING.md`.
