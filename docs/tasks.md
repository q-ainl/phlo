# Phlo tasks

Cross-app cron runner. One system cron entry per app triggers `tasks::run`
every minute; the resource matches declaratively against `%app->tasks`.

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

**3. One cron entry per app** (absolute path):
```cron
* * * * * php /path/to/app/www/app.php tasks::run
```

Place it in `/etc/cron.d/<app>-tasks` (system, 6 fields incl. user) or
`crontab -u <user>` (per user, 5 fields). On ZTS installations the binary may
be called `php-zts`.

## Schedule

Pick one per task:

| Key | Format | Example |
|---|---|---|
| `every:` | PHP-readable duration string | `'minute'`, `'5 minutes'`, `'2 hours'`, `'1 day'` |
| `daily:` | `'HH:MM'` | `'03:00'` |
| `weekly:` | `'<weekday> HH:MM'` | `'monday 09:00'` |

`every: 'minute'` (no leading number) becomes `'1 minute'` internally.
Parsing happens via `strtotime("+$every", 0)`.

## Callable (`do:`)

| Type | Example | Becomes |
|---|---|---|
| Closure | `fn() => external::pull()` | called directly |
| `'Class::method'` | `'account::cleanup'` | `account::cleanup()` |
| Resource name | `'backup'` | `phlo('backup')` |

## File conventions (`data/tasks/`)

| File | Content | When |
|---|---|---|
| `<name>.last` | raw unix ts | After each successful run, for the due check |
| `<name>.json` | `{schedule, return}` for the Control Center | After each successful run |
| `<name>.lock` | empty (mtime matters) | During a run, TTL 1h |

`data/tasks/` is created automatically by `tasks::run`.

## Error flow

No try/catch in `tasks::run`. A Throwable bubbles up to Phlo's framework
exception handler, which writes to `data/errors.json` (like build errors).
The lock stays until its TTL (1h); the failed task is parked and the other
tasks run on the next cron tick.

## Phlo Control Center integration

The Phlo Control Center detects `data/tasks/` automatically:
- A **Tasks tab** in the nav (visible only when the directory exists), right
  after Home.
- Per task: schedule (from JSON), last-run-ago, return value (type-aware:
  scalar/array/string), lock-status pill.

The Control Center is **fully agnostic** about the `tasks` resource and the app:
it reads purely from `data/tasks/`. Schedule info comes from `<name>.json`
(written by the runner), never via `phlo('app')`; that would trigger an app
route and disturb the HTTP status.

## Example (demo)

```phlo
prop tasks => arr(
    heartbeat: arr(do: 'app::heartbeat', every: 'minute'),
)

static heartbeat => file_put_contents(data.'heartbeat.log', date('Y-m-d H:i:s').' tasks::run fired'.lf, FILE_APPEND | LOCK_EX)
```

Cron: `* * * * * php /path/to/app/www/app.php tasks::run`
