# Phlo

Phlo is a compiled language that extends PHP with declarative syntax. `.phlo` files are compiled to native PHP classes — combining properties, methods, views, routes, styles and scripts in a single file per component. Phlo compiles at request time during development and ahead-of-time for production.

## Threaded runtime

Phlo runs on FrankenPHP as a persistent worker process. The application boots once — autoloading, configuration, route registration — and then serves each HTTP request in a thread. There are no cold starts.

Request-scoped state is accessed through the `%` operator, which resolves to thread-local singletons:

```phlo
%req->path        // current request path
%req->async       // is this an async (XHR) request?
%payload->name    // POST data
%session->user    // session data
%app->theme       // application config
```

After each request, `phlo('tech/reset')` clears all non-persistent singletons while shared state (classmap, configuration, routes) stays in memory.

## Language

A `.phlo` file compiles to a single PHP class. The filename determines the class name (dots become underscores: `CMS.list.phlo` → `CMS_list`). Metadata headers configure the class:

```phlo
@ extends: model
@ class:   cat
@ requires: breed
```

### Constructs

**Properties** — evaluated lazily and cached per instance:
```phlo
prop title = 'Default'              // static default
prop fullName => $this->first.' '.$this->last  // computed, cached after first access
prop items {                        // multi-line computed property
    $result = []
    foreach ($this->data AS $item) $result[] = transform($item)
    return $result
}
```

**Methods:**
```phlo
method greet($name) => "Hello $name"
method process($data){
    $validated = $this->validate($data)
    return $this->store($validated)
}
```

**Static properties and methods:**
```phlo
static table = 'cats'
static schema => arr(field('text', name: 'name'), field('bool', name: 'active'))
static find($id) => static::record(id: $id)
```

**Views** — inline HTML templates with embedded expressions:
```phlo
view: <div.card>{{ $this->title }}</div>

view header:
<header>
    <h1>$this->title</h1>
    <foreach $this->items AS $item>
        <p>$item->name</p>
    </foreach>
</header>
```

Views support shorthand CSS class syntax (`div.card.active`), template loops (`<foreach>`), conditionals (`<if>...<else>`), expression output (`{{ expr }}`), and inline expression shorthands (`{( $x ? 'y' : 'z' )}`).

**Routes** — declared inline, with typed parameters:
```phlo
route GET $id => static::show($id)
route POST $section $id => static::update($section, $id)
route both GET $list $options=* => static::handle($list, $options)
```

Route parameters support fixed-length constraints (`$token.20`), value lists (`$type:a,b,c`), splat parameters (`$rest=*`), and optional defaults.

**Inline assets** — `<script>` and `<style>` blocks are extracted at compile time and bundled into the application's CSS/JS output:
```phlo
<script>
on('click', '.btn', el => app.get(el.href))
</script>

<style>
.card {
    padding: 1rem
    background: $surface
    \:hover: background: $surface-alt
}
</style>
```

The CSS dialect uses indentation-based nesting and `$variable` references for theming.

### Controller pattern

Code at the top of a `.phlo` file (before any `prop`, `method`, `view`, or `route` declaration) becomes the `controller()` method — called automatically when the singleton is instantiated:

```phlo
@ extends: page

// This becomes controller():
$this->data = loadData()
$this->prepare()

prop title => $this->data->title
view: <main>{{ $this->content }}</main>
```

### The `%` operator

`%name` compiles to `phlo('name')` — a singleton registry scoped to the current thread. Each singleton is instantiated once per request and cleared between requests (unless marked persistent with `objPers`).

```phlo
%app          // application instance
%req          // current request
%res          // current response
%session      // session
%MySQL        // database connection (persistent)
%payload      // parsed request body
```

### Compilation

The compiler (`classes/builder.php`, `classes/file.php`, `classes/node.php`) parses `.phlo` source files and emits standard PHP:

- Each line gets a `;` appended (unless it ends with `{`, `}`, `:`, or is a control structure)
- `prop name => expr` becomes a `_name()` method with memoization via `obj`'s `__get`
- `view:` blocks are compiled to string-building methods with HTML escaping
- `<script>` and `<style>` blocks are extracted and concatenated into app-level asset files
- `@ extends` sets up PHP class inheritance
- Routes are collected and registered as a static route table

Source maps are preserved — compiled PHP files include `// source:` comments pointing back to the original `.phlo` file and line numbers.

During development with `build: true`, the compiler runs automatically when source files change. For production, `build: false` skips compilation and uses pre-compiled PHP.

## Singletons and lifecycle

`phlo('name')` is the core instantiation mechanism. It:

1. Checks if a singleton with that handle already exists → returns it
2. Instantiates `phlo\{name}` with any provided arguments
3. Calls `__handle()` if defined (to customize the singleton key)
4. Stores the instance in the singleton registry
5. Calls `controller()` if defined
6. Returns the instance

The `__handle` static method controls identity. Returning a string uses that as the cache key. Returning `true` means "reuse existing, update with new args". Returning `null` means "always create new".

## Application structure

A Phlo application needs:

```
www/app.php        Entry point — boots the runtime
app.phlo           Application class (config, models, routes)
data/build.json    Compiler configuration (paths, functions, libs)
php/               Compiled output (generated)
www/               Public web root
```

The entry point:
```php
phlo\tech\app(
    host:   'myapp.com',
    app:    '/srv/myapp/',
    debug:  true,
    build:  true,
    thread: 500,     // requests per worker before restart
);
```

`build.json` tells the compiler where to find sources:
```json
{
    "paths": {
        "app": ["", "/srv/phlo/cms/"],
        "libs": ["/srv/phlo/libs/"],
        "functions": ["/srv/phlo/functions/"]
    },
    "functions": ["arr", "create", "obj", "field"]
}
```

Functions listed in `build.json` are compiled into a shared `functions.php` and autoloaded. Libraries are compiled into individual class files. App sources are compiled with route registration.

## Routing

Routes declared in `.phlo` files are collected at compile time into a static route table on the `app` class. At runtime, `route()` matches the current request against this table — first checking static paths (exact matches), then dynamic paths (parameterized).

A route callback returning `false` means "not matched, try next". Any other return value (including `null`) means "matched, stop routing". This allows fallthrough:

```phlo
route GET dashboard => main(view(...), 'Dashboard')
route both GET $list $options=* {
    if (!$model = findModel($list)) return false
    // handle listing...
}
```

Async requests (XHR) return JSON patches instead of full HTML — the same route handles both, switching on `%req->async`.

## Syntax reference

| Syntax | Meaning |
|--------|---------|
| `@ key: value` | Class metadata (extends, class, requires, version) |
| `prop name = value` | Property with default |
| `prop name => expr` | Lazy-computed property |
| `prop name { ... }` | Multi-line computed property |
| `method name => expr` | Arrow method |
| `method name { ... }` | Block method |
| `static name = value` | Static property |
| `static name => expr` | Static lazy property |
| `view:` | Default view (`__toString`) |
| `view name:` | Named view method |
| `route METHOD path => expr` | Route declaration |
| `%name` | Thread-local singleton |
| `{{ expr }}` | Expression output in views |
| `{( expr )}` | Inline expression shorthand (e.g. `{( $x ? 'y' : 'z' )}`) |
| `<foreach>` | Template loop |
| `<if>...<else>` | Template conditional |
| `<tag.class#id attr=val>` | Shorthand HTML with CSS selectors |

## License

Copyright q-ai.nl
