# JSONDB als echte model-backend

## Status

`/srv/phlo/resources/DB/JSONDB.phlo` (was `JSON.phlo` tot 2026-05-24) is **een proof-of-concept**, niet productieklaar. Wel: extends `DB`, heeft `objRead/objWrite/objNextId/objFilter`. Niet: gebruikt als model-backend door enige app.

## Waar het nu staat (kan, kan niet)

Wat werkt:
- File-per-tabel (`<dir>/<table>.json`)
- Simpele WHERE met `col = ?` AND-joins
- `objNextId` voor int-PK auto-increment
- `LOCK_EX` ontbreekt op `objWrite` (race-conditie bij concurrent write, zie LOCK_EX-design)

Wat niet werkt (overgenomen uit DB-interface, niet geïmplementeerd):
- `lastInsertId()`, model.phlo gebruikt dit na `create()` om de nieuwe PK terug te krijgen
- `fieldQuotes` (waarschijnlijk void), model.phlo gebruikt dit voor kolom-quoting in SELECT
- Joins en complexe WHERE (alleen AND `col=?` patroon)
- Transactions
- Schema-introspectie (`columns()`, schema-diff)
- Sorteren, LIMIT-offset (model.phlo gebruikt `order` en `limit` via DB::query)

## Drie wegen om JSONDB als echte model-backend te gebruiken

### Weg 1: alleen `static $DB = ...` op specifieke models

```phlo
@ extends: model
static table = 'cache'
static DB => %JSONDB(data.'JSONDB/')
```

(`data` is de Phlo-constant voor de app data-dir, gezet door `phlo_app(data: ...)`. `%JSONDB(args)` resolvet als `phlo('JSONDB', args)`.)

**Reikwijdte:** model.phlo's `static::DB()` resolvet per model. JSONDB hoeft alleen het deel van DB-interface te dekken dat door dat model wordt gebruikt.

**Werk:** in JSONDB.phlo de DB-interface aanvullen: `lastInsertId`, fieldQuotes (`''`), `create/change/delete/query`-equivalent dat array-data manipuleert i.p.v. SQL.

**Geschikt voor:** read-heavy lookup-tabellen (translations, categories, config, settings). NIET voor transactioneel/relationeel werk.

### Weg 2: per-resource hybride (JSON-read, MySQL-write)

Voor frequent-read seldom-write tabellen: JSONDB als read-cache, MySQL als source-of-truth. Voorbeelden: country-list, currency-tabellen.

**Werk:** dunne wrapper-resource. Geen wijziging aan model.phlo nodig. Cache-invalidation bij MySQL-write.

**Geschikt voor:** static reference-data.

### Weg 3: JSONDB als volwaardige driver (full DB-interface)

Implementeer alles wat MySQL.phlo levert. Zware investering, twijfelachtige ROI:
- SQL-parser of equivalent expression-builder voor WHERE/ORDER/LIMIT
- LOCK_EX + file-level transactions (zwak, niet ACID)
- Index-emulatie of full-scan (slow boven 10k rows)
- Geen joins, of joins via N+1 in PHP

**Niet aanbevolen.** Voor non-MySQL backend liever PostgreSQL.phlo of SQLite.phlo afmaken, die zijn 80% klaar en hebben echte query-engines.

## Aanbeveling

**Weg 1** voor 1 of 2 specifieke read-heavy models per app. Bouw uit zodra er een eerste echte use-case is (bv. `settings`-tabel in `app/` die je liever git-trackt dan in MySQL). Investeer minimaal: voeg in JSONDB.phlo de 5-6 ontbrekende DB-interface-methods toe als no-op of array-equivalent, en test met 1 model.

**Niet doen:** weg 3 (full driver). Niet de moeite waard.

## Naam-collision (opgelost)

`db/JSON.phlo` (driver) en `files/JSON.phlo` (file-resource) hadden beide classnaam `JSON`. Sinds 2026-05-24 hernoemd tot `DB/JSONDB.phlo` met classnaam `JSONDB`. Alle bestaande gebruikers van `%JSON` / `phlo('JSON')` verwijzen naar de file-resource (geverifieerd via grep), geen migratie nodig.
