# Phlo tasks

Cross-app cron-runner. Eén system-cron entry per app triggert `tasks::run` elke minuut; resource matched declaratief tegen `%app->tasks`.

## Setup

**1. Resource** in `data/app.json`:
```json
"resources": [..., "tasks"]
```

**2. `prop tasks`** in `app.phlo`:
```phlo
prop tasks => arr(
    cleanup: arr(do: 'account::cleanup', every: '5 minutes'),
    poll:    arr(do: fn() => external::pull(), every: 'minute'),
    backup:  arr(do: 'backup::run', daily: '03:00'),
    report:  arr(do: 'report::weekly', weekly: 'monday 09:00'),
)
```

**3. Eén cron-entry per app** (absoluut pad):
```cron
* * * * * php-zts /srv/<app>/www/app.php tasks::run
```

Plaats in `/etc/cron.d/<app>-tasks` (systeem, 6 velden incl user) of `crontab -u <user>` (per-user, 5 velden).

## Schedule

Kies één per task:

| Key | Format | Voorbeeld |
|---|---|---|
| `every:` | PHP-leesbare duur-string | `'minute'`, `'5 minutes'`, `'2 hours'`, `'1 day'` |
| `daily:` | `'HH:MM'` | `'03:00'` |
| `weekly:` | `'<weekday> HH:MM'` | `'monday 09:00'` |

`every: 'minute'` (geen leading number) wordt intern `'1 minute'`. Parsing via `strtotime("+$every", 0)`.

## Callable (`do:`)

| Type | Voorbeeld | Wordt |
|---|---|---|
| Closure | `fn() => external::pull()` | direct aangeroepen |
| `'Class::method'` | `'account::cleanup'` | `account::cleanup()` |
| Resource-naam | `'backup'` | `phlo('backup')` |

## File-conventies (`data/tasks/`)

| File | Inhoud | Wanneer |
|---|---|---|
| `<name>.last` | raw unix-ts | Per succesvolle run, voor due-check |
| `<name>.json` | `{schedule, return}` voor dashboard | Per succesvolle run |
| `<name>.lock` | leeg (mtime telt) | Tijdens run, TTL 1u |

`data/tasks/` wordt auto-aangemaakt door `tasks::run`.

## Error-flow

Geen try/catch in `tasks::run`. Een Throwable bubblet naar Phlo's framework-exception-handler die naar `data/errors.json` schrijft (zoals build-errors). Lock blijft hangen tot TTL (1u); gefaalde task is geparkeerd, andere tasks worden volgende cron-tick uitgevoerd.

## Dashboard-integratie

Phlo's dev-dashboard detecteert `data/tasks/` automatisch:
- **Tasks-tab** in nav (alleen zichtbaar als dir bestaat), direct na Home
- Per task: schedule (uit JSON), last-run-ago, return-value (type-aware: scalar/array/string), lock-status pill

Dashboard is **volledig agnostisch** over de `tasks`-resource en de app: leest puur uit `data/tasks/`. Schedule-info komt uit `<name>.json` (door runner geschreven), nooit via `phlo('app')`; dat zou een app-route trigger en HTTP-status verstoring veroorzaken.

## Voorbeeld (demo)

Zie `/srv/demo/app.phlo`:
```phlo
prop tasks => arr(
    heartbeat: arr(do: 'app::heartbeat', every: 'minute'),
)

static heartbeat => file_put_contents(data.'heartbeat.log', date('Y-m-d H:i:s').' tasks::run fired'.lf, FILE_APPEND | LOCK_EX)
```

Cron: `* * * * * php-zts /srv/demo/www/app.php tasks::run`
