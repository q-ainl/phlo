# Phlo apply() protocol

`apply()` is Phlo's server-to-client channel for async responses. The server
builds an object of commands; the frontend (`phlo.js`) executes them against
the DOM.

## General

```php
apply(cmd1: value1, cmd2: value2, ...)
```

Each command has a handler in `app.mod.<command>` (see `phlo.js`). A value
can be:
- A string/number/object (one target).
- An associative array `{'#sel': 'value', '.cls': 'value'}` (several targets
  at once).
- An array of values (applied in sequence).

## DOM mutations

| Cmd | Argument | Effect |
|---|---|---|
| `inner` | `{selector: html}` | `el.innerHTML = html` |
| `outer` | `{selector: html}` | `el.outerHTML = html` |
| `main` | `html` | Replace `<main>` (or body if there is no main) |
| `before` | `{selector: html}` | Insert HTML before the element |
| `after` | `{selector: html}` | Insert HTML after the element |
| `prepend` | `{selector: html}` | Insert HTML as first child |
| `append` | `{selector: html}` | Insert HTML as last child |
| `remove` | `selector` or array | Remove elements |
| `attr` | `{selector: {attr: value}}` | Set/remove attribute (null removes) |
| `value` | `{selector: value}` | Set `el.value` (forms) |
| `data` | `{selector: {key: value}}` | Set `el.dataset[key]` |
| `class` | `{selector: 'a b -c !d'}` | Add / remove (`-` prefix) / toggle (`!` prefix) |

## App state

| Cmd | Argument | Effect |
|---|---|---|
| `title` | `string` | `document.title` |
| `lang` | `string` | `html.lang` |
| `options` | `string` | Replace `body.className` |
| `settings` | `{key: value}` | Set `body.dataset[key]` |
| `path` | `string` | `history.pushState` (URL changes) |
| `trans` | `string` | Transition classes for the view animation |
| `scroll` | `int` or `#anchor` | Scroll position |

## Assets

| Cmd | Argument | Effect |
|---|---|---|
| `css` | `href` or array | Add `<link rel=stylesheet>` (once per href) |
| `js` | `src` or array | Add `<script src>` (once) |
| `defer` | `src` or array | Same, with the `defer` attribute |

## Navigation

| Cmd | Argument | Effect |
|---|---|---|
| `location` | `path` or `true` | Next navigation. `true` re-requests the current path. External `http(s)://` URLs use `location.assign()` |
| `call` | `callback-name` | Calls `app[cb]()` |

## Feedback and debug

| Cmd | Argument | Effect |
|---|---|---|
| `error` | `string` | Client error handler (`phlo.error`) |
| `log` | `string` | `console.log` on the client (debug mode) |
| `phlo` | array of strings | Server-side trace, logged to the browser console in debug mode |
| `debug` | array of strings | Debug lines, logged to the browser console |
| `dump` | array | `d()`/`dx()` dumps, logged to the browser console |

## Custom responders (`app.res`)

`apply()` only ships the commands above. To add app-specific behaviour,
register a responder on `app.res`; for every key present in the response that
matches a responder name, `app.res[key](cmds)` runs. This is the supported
extension point, so the engine's command set stays small. A toast/notice
system, for example, is a resource that registers its own responder, not a
core command.

## Stream semantics

- One HTTP response is one or more newline-separated JSON lines.
- Each line is a complete `apply({...})` object.
- The frontend parses line by line and executes immediately.
- No rollback if one command fails: the other commands in the same batch
  still run.

## Error handling

- After `apply(error: ...)` the rest of the batch still runs, so the server
  can send DOM updates alongside the error (e.g. mark form fields with
  `class: '... error'`).
- A missing target (`inner: ['#does-not-exist' => '...']`) fails silently:
  no toast, no warning. This is deliberate; it avoids noise for optional
  targets.

## Examples

```php
// Form error with field marking
apply(
    error: 'Please fill in all required fields',
    class: ['[name=email]' => 'error', '[name=name]' => 'error'],
)

// List refresh + scroll
apply(
    outer: ['#list' => $this->newListHtml()],
    scroll: '#list',
    trans: 'forward',
)

// Open a modal
apply(
    append: ['body' => '<dialog open>...</dialog>'],
    class: ['html' => 'modal-open'],
)

// Full page update (comparable to view())
apply(
    title: 'New title',
    inner: ['main' => $html],
    path: '/new-route',
    trans: 'forward',
    scroll: 0,
)
```

## No lint for apply keys

Phlo does NOT check apply keys at build time. A typo (`inner` -> `innr`) is
silently ignored by phlo.js (`app.mod[key]` is undefined). The developer
guards this via this doc. See SKILL.md for the deliberate choice to keep
Phlo's build lean.
