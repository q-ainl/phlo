# Phlo apply() protocol

`apply()` is de Phlo-server-naar-client communicatie voor async responses. Server bouwt een object met opdrachten, frontend `phlo.js` voert ze uit op de DOM.

## Algemeen

```php
apply(cmd1: value1, cmd2: value2, ...)
```

Elke opdracht heeft een eigen handler in `app.mod.<opdracht>` (zie `phlo.js`). Een waarde kan zijn:
- Een string/getal/object (1 doel)
- Een associatieve array `{'#sel': 'value', '.cls': 'value'}` (meerdere doelen in 1 keer)
- Een array van waarden (sequentieel)

## DOM-mutaties

| Cmd | Argument | Effect |
|---|---|---|
| `inner` | `{selector: html}` | `el.innerHTML = html` |
| `outer` | `{selector: html}` | `el.outerHTML = html` |
| `main` | `html` | Vervang `<main>` (of body als geen main) |
| `before` | `{selector: html}` | Insert HTML voor element |
| `after` | `{selector: html}` | Insert HTML na element |
| `prepend` | `{selector: html}` | Insert HTML als eerste child |
| `append` | `{selector: html}` | Insert HTML als laatste child |
| `remove` | `selector` of array | Verwijder elementen |
| `attr` | `{selector: {attr: value}}` | Zet/verwijder attribuut (null = verwijder) |
| `value` | `{selector: value}` | Zet `el.value` (forms) |
| `data` | `{selector: {key: value}}` | Zet `el.dataset[key]` |
| `class` | `{selector: 'a b -c !d'}` | Add/remove (-prefix)/toggle (!prefix) |

## App-state

| Cmd | Argument | Effect |
|---|---|---|
| `title` | `string` | `document.title` |
| `lang` | `string` | `html.lang` |
| `options` | `string` | Vervang `body.className` |
| `settings` | `{key: value}` | Zet `body.dataset[key]` |
| `path` | `string` | `history.pushState` (URL wijzigt) |
| `trans` | `string` | Transition-classes voor view-animatie |
| `scroll` | `int` of `#anchor` | Scroll-positie |

## Assets

| Cmd | Argument | Effect |
|---|---|---|
| `css` | `href` of array | Voeg `<link rel=stylesheet>` toe (eenmalig per href) |
| `js` | `src` of array | Voeg `<script src>` toe (eenmalig) |
| `defer` | `src` of array | Idem maar met `defer` attr |

## Navigatie

| Cmd | Argument | Effect |
|---|---|---|
| `location` | `path` of `true` | Volgende navigatie. `true` = huidige path opnieuw. Externe URL `http(s)://`: `location.assign()` |
| `call` | `callback-name` | Roep `app[cb]()` aan |

## Notificaties

| Cmd | Argument | Effect |
|---|---|---|
| `error` | `string` | Render error-toast (server-fout, validatie, etc.) |
| `notice` | `string` | Render notice-toast (succes, info) |
| `log` | `string` | `console.log` op client |

## Speciale

| Cmd | Argument | Effect |
|---|---|---|
| `phlo` | array van debug-strings | Server-side trace; logt naar browser-console in debug-mode |
| `head` | `html` | Append naar `<head>` |
| `redirect` | URL | Sync redirect (zelfde als location voor extern) |
| `refresh` | bool | Forceer pagina-reload |

## Stream-semantiek

- 1 HTTP response = 1 of meer JSON-regels (newline-gescheiden).
- Per regel een complete `apply({...})` object.
- Frontend parsed regel-voor-regel, voert direct uit.
- Geen rollback bij fout in 1 cmd: andere cmds in zelfde batch voeren wel uit.

## Error-handling

- Bij `apply(error: ...)`: toast verschijnt, verdere processing in dezelfde batch GAAT DOOR. Server kan na error nog DOM-updates sturen (bv. form-velden markeren met `class: '... error'`).
- Bij niet-bestaande target (`inner: ['#niet-bestaande': '...']`): faalt stil (geen toast, geen warning). Bewuste keuze; voorkomt ruis bij optionele targets.

## Voorbeelden

```php
// Form-error met veld-markering
apply(
    error: 'Vul alle verplichte velden in',
    class: ['[name=email]' => 'error', '[name=name]' => 'error'],
)

// List-refresh + scroll
apply(
    outer: ['#list' => $this->newListHtml()],
    scroll: '#list',
    trans: 'forward',
)

// Modal openen
apply(
    append: ['body' => '<dialog open>...</dialog>'],
    class: ['html' => 'modal-open'],
)

// Volledige page-update (vergelijkbaar met view())
apply(
    title: 'Nieuwe titel',
    inner: ['main' => $html],
    path: '/nieuwe-route',
    trans: 'forward',
    scroll: 0,
)
```

## Niet documenteren via lint

Phlo doet GEEN build-time checks op apply-keys. Een typo (`inner` -> `innr`) wordt stil genegeerd door phlo.js (`app.mod[mod]` is undefined). De ontwikkelaar bewaakt dit zelf via deze doc + agent-memory. Zie SKILL.md voor de bewuste keuze om Phlo's build slank te houden.
