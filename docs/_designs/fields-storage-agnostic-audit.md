# Audit: resources/fields/*.phlo storage-engine-agnostiek

## Conclusie

De 18 field-types in `resources/fields/*.phlo` zijn **bijna volledig storage-agnostisch**, maar lekken via `method sql` een MariaDB/MySQL-dialect naar het schema-pad. Voor true engine-agnostiek (zodat dezelfde model met fields op MySQL, PostgreSQL, SQLite of JSONDB werkt) moet de DDL-generator van field naar DB-driver verhuizen.

## Wat is wel agnostisch (de runtime-laag)

| Field | Runtime-koppeling | Status |
|---|---|---|
| bool, date, datetime, email, number, password, price, select, text, token, virtual, wysiwyg | Alleen `input()`, `label()`, `objValidate()`. Geen `%MySQL`, geen `->query`, geen `fetch*`. | Schoon |
| parent, child, many | Roepen `model::records()` / `$record->getMany()` op via Phlo identity-map. Geen directe SQL. | Schoon (engine-agnostisch via model.phlo) |
| file, image | `method write` schrijft naar filesystem (`mkdir`, `file_put_contents`). Geen DB-koppeling. | Schoon, maar filesystem is impliciete tweede storage-engine (zie eigen design) |

## Wat lekt MariaDB-syntax (de DDL-laag)

Elke field heeft `method sql => "..."` die ruwe MySQL-DDL retourneert:

- `bool.phlo:19` → `` `name` tinyint(1) unsigned ``
- `date.phlo:13` → `` `name` DATE unsigned `` (bug: DATE heeft geen `unsigned`)
- `datetime.phlo:24` → `` `name` int(10) unsigned `` (unix-ts, geen native TIMESTAMP)
- `number.phlo:18` → `` `name` decimal(X,Y) unsigned `` of `smallint(X) unsigned`
- `password.phlo:20` → `` `name` char(60) ``
- `parent.phlo:16` → `` `name` varchar(10) ``
- `select.phlo:13` → `` `name` enum('...') `` (MySQL-specifiek, PostgreSQL kent geen ENUM zo)
- `text.phlo:19` → `` `name` varchar($length) ``
- `token.phlo:20` → `` `name` char($length) ``
- `wysiwyg.phlo:19` → `` `name` TEXT ``
- `file.phlo:55` + `image.phlo:30` → 2-kolom-tupel met backticks

Backticks (`` ` ``) zijn MySQL-only; PostgreSQL gebruikt `"`, SQLite tolereert beide, JSONDB heeft geen schema. Plus `unsigned`, `tinyint(1)`, `int(10)` zijn MySQL-dialect.

## Voorgestelde refactor (niet uitgevoerd; ter beoordeling)

Verplaats DDL-genese van field naar DB-driver. Drie patroon-opties:

### A. `field` retourneert type-descriptor, DB-driver vertaalt
```phlo
method sql => obj(type: 'int', size: 1, unsigned: true)
```
DB-driver (`MySQL.phlo`, `PostgreSQL.phlo`, `SQLite.phlo`) krijgt `objSqlColumn($name, $descriptor)` method die per engine de juiste DDL bouwt.

**Voor:** explicit, simpel uit te breiden, JSONDB kan `sql => null` doen.
**Tegen:** elke field-type extra prop-set, plus elke driver moet alle types ondersteunen.

### B. Field-type → logische type-naam, mapping in DB-driver
```phlo
prop dbType = 'bool'
prop dbLength = null
```
DB-driver heeft een static map: `bool` → MySQL `tinyint(1) unsigned`, PostgreSQL `boolean`.

**Voor:** mapping centraal in 1 file per engine.
**Tegen:** field-types die meta nodig hebben (decimal(X,Y), enum-opties) vereisen extra wegen.

### C. Status quo: declareer veld als MySQL-only, document expliciet
Voeg in field.phlo header een note toe: *"DDL via `method sql` is MariaDB-syntax. Voor andere engines: override per field."* Geen code-wijziging, alleen verwachtingsmanagement.

## Aanbeveling

**B** met **A-fallback voor edge-cases** (decimal/enum). Voer pas uit zodra er een tweede DB-driver actief in productie is. Op dit moment draaien alle 8 Phlo-apps op MariaDB; de refactor is voorbereidend werk zonder huidige consumer.

**Nu te doen:** fix `date.phlo:13` die ten onrechte `unsigned` na `DATE` zet (DATE heeft geen unsigned-attribuut; MariaDB negeert het stil, maar PostgreSQL/SQLite zouden klagen).

## Niet-fields, wel storage-impact

- `model.phlo` zelf gebruikt `static::$table.'.*'` en backtick-quoting via `DB.phlo::$fieldQuotes` (correct geabstraheerd).
- `audit.phlo` + `rate.phlo` doen rauwe `INSERT/UPDATE/DELETE` strings (MariaDB-syntax, niet portable, maar acceptabel als security-resources met expliciete `@ requires: @MySQL`).
- `visitors.phlo` gebruikt `model::create()` (engine-agnostisch via driver).
