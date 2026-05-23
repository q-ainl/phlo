# CMS-diff: /srv/control/cms/ vs /srv/sronline/cms/

## Doel

Voorbereiding voor centralisatie naar `/srv/cms/` als canonical Phlo-CMS.

## Bestanden in beide (gelijk)

`CMS.change.phlo`, `CMS.create.phlo`, `CMS.list.phlo`, `CMS.loading.phlo`, `CMS.phlo`, `CMS.script.phlo`, `CMS.settings.phlo`, `CMS.style.phlo`, byte-identiek.

## Bestanden die verschillen

| File | control/ | sronline/ | Aanbeveling voor /srv/cms/ |
|---|---|---|---|
| `CMS.API.phlo` | CSRF-verify op alle 5 mutating routes, veilige referer-bounds-check | geen CSRF, ruwe referer-substring | **control** (security) |
| `CMS.dashboard.bi.phlo` | met `pruneCache($max = 200)` voor zoek-historie | zonder pruning (lekt geheugen) | **control** |
| `CMS.dashboard.phlo` | `view widget($widget, $title)` met expliciet title-arg | `view widget($widget)` met implied title | **control** (expliciter) |
| `CMS.layout.phlo` | minimaal: `view => nav.toggle` | rijk: `prop crumbTitle`, `userBadge`, `userSwitcher`, `notifyBadge`, plus `$this->top` | **sronline** (functioneler), maar `userSwitcher` is sronline-specifiek (admin/beheerder rollen) |
| `CMS.record.phlo` | korte styling | uitgebreide styling met WordPress-achtige headers | hybrid: control's logica + sronline's styling als opt-in theme |

## Alleen in een van beide

- `control/cms/FieldStyles/`, control-specifieke field-presentaties (per veld-type een eigen styling-snippet)
- `sronline/cms/styles/`, sronline-specifieke styling per pagina-context

Beide zijn project-specifieke aanvullingen. Voor `/srv/cms/`: levert *geen* default mee, apps voegen lokaal toe.

## Voorgestelde centralisatie

```
/srv/cms/
├── CMS.phlo                  ← base, identiek beide
├── CMS.API.phlo              ← control-versie (CSRF + safe referer)
├── CMS.change.phlo           ← identiek
├── CMS.create.phlo           ← identiek
├── CMS.dashboard.phlo        ← control-versie (expliciete args)
├── CMS.dashboard.bi.phlo     ← control-versie (met pruneCache)
├── CMS.layout.phlo           ← MINIMALE versie, hooks voor app-prop overrides
├── CMS.list.phlo             ← identiek
├── CMS.loading.phlo          ← identiek
├── CMS.record.phlo           ← control's logica, styling-extract naar themes/
├── CMS.script.phlo           ← identiek
├── CMS.settings.phlo         ← identiek
├── CMS.style.phlo            ← identiek
├── docs/
│   ├── README.md             ← CMS user-guide
│   └── agent-guide.md        ← hoe een coding-agent een nieuwe CMS-pagina toevoegt
├── themes/
│   └── wp-like/              ← optionele WordPress-achtige styling (uit sronline/cms/styles/)
└── icons/                    ← gemeenschappelijke icons
```

## Migratie-pad

1. `/srv/cms/` aanmaken als nieuwe git-repo (q-ainl org).
2. Bestanden samenvoegen volgens bovenstaande tabel.
3. `control/cms/` en `sronline/cms/` blijven staan, maar worden nieuwere include vervangen door git-submodule of klassieke require.
4. Per app `data/app.json`: voeg `cms/CMS` toe, verwijder lokale references. Test individueel.
5. Wanneer beide apps lopen op `/srv/cms/`, verwijder `control/cms/` en `sronline/cms/`.

## Aandachtspunten

- **`fields/file` + `fields/image`-koppeling**: oude CMS-versies hadden file/image-assets ingebakken in de CMS-engine. In `/srv/cms/` moet dit een opt-in zijn, een CMS zonder file-uploads laadt geen file-resources.
- **`prop userSwitcher` in sronline**: app-specifieke admin-feature. Niet meegeven naar `/srv/cms/`; apps definiëren eigen `prop userSwitcher` als override.
- **Styling-loskoppeling**: `CMS.style.phlo` is identiek, maar `CMS.record.phlo`-styling verschilt. Extract layout-styling uit `CMS.record` naar `themes/`.

## Werk dat hier NIET in zit

- Documentatie schrijven (volgt in fase 2 na centralisatie).
- `cms-pattern.md` in `/srv/phlo/docs/` (zoals genoemd in `prompts/_designs/control.groei.cms-laag-naar-phlo-resources.md`).
- CMS-architectuur-vragen rondom virtuele velden, validatie, en de relatie met `resources/fields/`.
