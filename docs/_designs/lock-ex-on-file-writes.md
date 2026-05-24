# LOCK_EX op file_put_contents in Phlo

## Status: DONE (2026-05-24)

Toegepast op alle runtime-writes in `resources/files/` + `DB/JSONDB.phlo`:
- `files/INI.phlo:19` `objWrite` → `LOCK_EX`
- `files/file.phlo:16` `append` → `FILE_APPEND | LOCK_EX`
- `files/file.phlo:61` `write` → `LOCK_EX`
- `DB/JSONDB.phlo:18` `objWrite` → `LOCK_EX`

Build-time writes (`classes/build.php`, `classes/builder.php`) ongewijzigd: single-writer per worker-startup.

## Vraag (origineel)

Moet `LOCK_EX` overal op `file_put_contents` in `/srv/phlo/`? Welke afwegingen.

## Huidige staat (2026-05-24)

`grep file_put_contents` in `/srv/phlo/resources/` + `/srv/phlo/classes/` + `/srv/phlo/functions.php`:

| Locatie | Heeft LOCK_EX? | Wat schrijft het |
|---|---|---|
| `functions.php:18` `json_write()` | **JA** | Wrapper. Wordt door `files/JSON.phlo` indirect gebruikt. |
| `classes/trace.php:46` | **JA** | Trace-output per request naar `data/trace/<id>.json`. |
| `classes/trace.php:116,126` | **JA** | Trace-index updates. |
| `classes/build.php:135` | nee | Shadow-source voor trace-mode. Build-tijd, single-writer impliciet. |
| `classes/builder.php:345` | nee | PHP-output van transpile (`<app>/php/<class>.php`). Build-tijd. |
| `files/INI.phlo:19` | nee | INI-files (config, langs). Hoog write-volume voor language-files. |
| `files/file.phlo:16` | n.v.t. | `FILE_APPEND`, atomisch op POSIX voor < PIPE_BUF. |
| `files/file.phlo:61` | nee | `method save`, generieke file-write. |
| `DB/JSONDB.phlo:18` | nee | JSON-DB driver `objWrite`. |

## Wat LOCK_EX wel/niet oplost

**LOCK_EX = advisory exclusive lock** op de file-descriptor tijdens write. Effecten:

- Twee gelijktijdige `file_put_contents($f, ..., LOCK_EX)` *serieus*: writer 2 wacht op writer 1.
- Twee gelijktijdige writes *zonder* LOCK_EX: PHP doet 1 write-syscall, kernel schrijft in willekeurige volgorde. Bij identieke content vrijwel altijd OK; bij verschillende content krijg je interleaved garbage of een verloren write.
- LOCK_EX beschermt **niet** tegen "read tijdens write", een reader die het bestand opent terwijl writer halverwege is, kan een half geschreven file zien. Voor strikte read-consistency: schrijf naar `tmp` + atomisch `rename()`.
- Onder FrankenPHP worker-mode: meerdere worker-processes op dezelfde host kunnen tegelijk writen, dus race-conditie is reëel.

## Afwegingen per use-case

### Hot path met multi-writer (LOCK_EX nodig)
- `files/INI.phlo:19`, language-files worden tijdens runtime aangevuld door auto-translate-flows. Memory `feedback_phlo_multiline_strings` is impliciete bevestiging: user heeft historisch corruptie ervaren. **Toevoegen.**
- `DB/JSONDB.phlo:18`, als JSONDB ooit als model-backend wordt gebruikt (zie eigen design): hoge concurrent-write-risk. **Toevoegen** als precaution, ook al is JSONDB nu inactief.

### Build-time, single-writer impliciet (LOCK_EX overbodig)
- `classes/build.php:135` + `classes/builder.php:345`, build-pass is altijd 1 process per worker-startup. Geen multi-writer. **Niet nodig.**

### Generieke writes (LOCK_EX als default)
- `files/file.phlo:61` `method save`, generiek. Gebruik onbekend. Goedkoop om altijd LOCK_EX toe te voegen (overhead = 0 als geen contention). **Toevoegen** als sane default.

### Append-only (LOCK_EX niet relevant)
- `files/file.phlo:16` `method append`, `FILE_APPEND` is atomisch op POSIX onder PIPE_BUF (typisch 4KB). Voor grotere writes is `FILE_APPEND` + `LOCK_EX` veiliger, maar dat raakt write-performance. **Document gedrag**, geen wijziging zolang appends klein zijn.

## Aanbeveling

**Minimum (alleen waar bewezen-pijn):**
- `files/INI.phlo:19` → `LOCK_EX` (user-bevestigde lang-file corruptie historie)
- `DB/JSONDB.phlo:18` → `LOCK_EX` (futureproof, geen kosten)

**Uitbreiding (als sane default):**
- `files/file.phlo:61` `method save` → `LOCK_EX` (generieke writes, geen meetbare overhead)

**Niet doen:**
- `classes/build.php` + `classes/builder.php`, niet nodig, single-writer.
- `files/file.phlo:16` `method append`, werkt al atomisch voor kleine writes; voor groot een aparte `method appendLocked` introduceren als use-case opduikt.

**Niet vergeten:**
- Voor read-consistency (niet alleen write-integrity): tmp+rename-patroon voor kritieke files (auth.ini etc.). LOCK_EX dekt dat niet.

## Argument om alles uniform LOCK_EX te maken
Pro: 1 regel, geen "waarom hier niet". Geen meetbare overhead bij geen contentie. Neutraliseert toekomstige bugs.
Con: maskeert dat sommige paden bewust single-writer zijn; nieuwe ontwikkelaars leren niet de onderscheid. LOCK_EX-deadlock-risico bij geneste lock-acquire (theoretisch; PHP file-locks geven dit niet, maar reflex zou kunnen migreren naar `flock()`).

**Kies hier op basis van smaak.** Mijn voorkeur: uniform LOCK_EX op alle runtime-writes (`files/*`, `DB/JSONDB`), niet op build-output.

## Concrete patch-voorstel (nog niet uitgevoerd, wacht op jouw beslissing)

```phlo
# files/INI.phlo:19
method objWrite => file_put_contents($this->objFile, ..., LOCK_EX)

# files/file.phlo:61
if ($written = file_put_contents($this->file, $data, LOCK_EX) !== false) debug(...)

# DB/JSONDB.phlo:18
method objWrite(string $table, array $data) => file_put_contents(..., json_encode(...), LOCK_EX)
```

Drie regels code. Reversibel. Geen API-impact.
