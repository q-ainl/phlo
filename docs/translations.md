# Phlo translations (i18n)

Multilingual text is **optional** and provided by the `lang` resource
(`@ requires: @cookies @AI @INI phlo.async`). It translates on demand with an
AI model, caches per language in `langs/<lang>.ini`, and falls back to the
source text when no translation exists yet. Nothing else depends on it.

## View shorthand

In views, write static translatable text with the language shorthand:

```phlo
view:
<p>{nl: Hallo wereld}</p>
```

The two-letter code before the colon is the **source** language of that text.
The compiler rewrites `{nl: Hallo wereld}` to `{{ nl('Hallo wereld') }}`.
Everything between the colon and the closing `}` becomes a single string
argument, so the shorthand has **no placeholder or argument syntax**. For
dynamic values use the function form instead (see Helpers).

If `%app->lang` equals the source language the text is shown verbatim; if it
differs, `lang` returns the cached translation and schedules any missing lines
for async translation.

## Helpers

```phlo
function nl($text, ...$args) => %lang->translation('nl', $text, ...$args)
function en($text, ...$args) => %lang->translation('en', $text, ...$args)
```

Use these in methods, props and controller code, and in views when you need
arguments. Extra arguments are applied with `sprintf` **after** translation, so
keep the placeholder in the source string (its cache key stays stable):

```phlo
<p>{{ nl('Hallo %s', $name) }}</p>
```

## Active language

`%app->lang` holds the active language. A route typically sets it before
rendering. The resource also exposes `%lang` (view value = current lang),
`cookie()`, `browser()` (from `Accept-Language`) and `detect($text)` (asks the
model for an ISO 639-1 code).

## Cache

| Aspect | Behaviour |
|---|---|
| Store | `langs/<lang>.ini`, one `hash = "value"` per line, sorted |
| Key | `hash($from, $line)`, a readable prefix plus an md5 tail |
| Lookup | binary search on the sorted file, with a per-request file cache |
| Miss | line is returned as-is and queued; `lang::asyncBatch` translates and writes back |
| Writes | atomic (`tmp` + `rename`), per-process tmp name to avoid races |

Translation is line-by-line: each non-empty line is hashed and cached
independently, so editing one line only re-translates that line.

## Methods

| Method | Use |
|---|---|
| `translation($from, $text, ...$args)` | normal rendering: cache + async backfill (this is what `nl()`/`en()` call) |
| `translate($from, $to, $text)` | one direct, uncached translation |
| `translateBatch($from, $to, $texts)` | numbered multi-line translation (used by the async backfill) |

## Steering the translator

Two overridable props shape the AI calls:

- `prop model` (default `gpt-4o-mini`) selects the translation model.
- `prop instructions` (default `void`) is extra context sent with every
  translation: domain, terminology, and rules about what stays verbatim.

An app injects instructions with a build mod:

```phlo
prop %lang.instructions = 'Documentation for the Phlo language. Keep keywords (route, view, prop) in English. Keep common English technical terms (best practices, deployment, release) untranslated where natural, and prefer natural phrasing over forced, over-literal translation.'
```

Instructions are appended to the translator's system prompt by `transContext()`
and apply both to view text and to markdown documentation translated through an
app's own `docs` machinery (which reads `%lang->instructions`). Without
instructions the translator works from the text alone.
