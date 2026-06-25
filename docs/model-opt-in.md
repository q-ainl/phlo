# Phlo model: opt-in features

`@ extends: model` gives you CRUD + identity map + relations + soft-delete by
default. There are three additional opt-in features.

## 1. Audit log

Add `static objAudit = true` to a model. From then on every mutation is recorded in the `audit_log` table:
- `Class::create(...)`: on success, the new record is logged as a `create`.
- `Class::change($where, ...)` (and its alias `Class::objLogChange($where, ...)`): each affected record is re-fetched and logged as an `update`, so the diff is exact even on a bulk change.
- `Class::delete($where, ...)`: each affected record is logged as a `delete`.
- `$record->objSave()`: a *new* record is logged as a `create`; for an audited update use `change`/`objLogChange`.

The mutation and its audit row run in **one transaction** (nested via a savepoint when you are already in one), so a failed audit insert rolls the mutation back: a row is never left changed-but-unaudited.

Requirements:
- Add `security/audit` to the `data/app.json` resources.
- Import the table once: `mysql <database> < <engine>/resources/security/audit.sql`.

### What is recorded

| column | meaning |
|---|---|
| `ts` | unix timestamp of the mutation |
| `user` | acting user id (`%session->user`), or `null` when there is no session |
| `model` | model class name |
| `record_id` | the record's primary-key value (stored as a string, so non-int PKs fit) |
| `action` | `create` / `update` / `delete` |
| `changes` | JSON (see below) |
| `ip` | request `REMOTE_ADDR`, or `null` |

The `changes` JSON depends on the action:
- **create**: the full new row, `{"column": value, ...}`.
- **update**: only what changed, `{"column": {"from": old, "to": new}, ...}` (computed by `audit::diff`).
- **delete**: the full row as it was, `{"column": value, ...}`.

### Querying the trail

The query API lives on the `audit` resource; pass the model (class or instance) so the lookup runs on that model's own database:

```phlo
// every audit row for one record, newest first (limit defaults to 50)
$rows = %audit->history(invoice::class, $id)
foreach ($rows AS $row){
    $action  = $row->action                       // 'create' | 'update' | 'delete'
    $when    = $row->ts                            // unix timestamp
    $who     = $row->user                          // acting user id, or null
    $changes = json_decode($row->changes, true)    // the JSON above, decoded
}

// everything a user did in this model's table since a timestamp
$rows = %audit->byUser(invoice::class, $userId, fromTs: 0, limit: 100)

// retention: drop rows older than N seconds (default one year)
%audit->purge(invoice::class, olderThanSeconds: 60 * 60 * 24 * 90)
```

`history`/`byUser` return objects (`PDO::FETCH_OBJ`); `changes` is a JSON string, so `json_decode` it.

Exclude sensitive fields from the recorded `changes`:
```phlo
method afterCreate => %audit->log($this, 'create', [], (array)$this, exclude: ['password_hash'])
```

Gate auditing by environment with the `debug` runtime constant (set by `phlo_app(debug: true|false)`) - dev/build only:
```phlo
static objAudit => debug
```
or release/production only:
```phlo
static objAudit => !debug
```

## 2. Validation

Add `static objValidate = true` to a model. From then on:
- `Class::create($args)`: pre-flight `objRunValidation($args)`. On errors, `Class::$objLastErrors` is populated and `create()` returns `null`.
- Each field in `static schema()` has its `field->objValidate($value)` called.

Field rules in `field()`:
- `required: true`
- `length: 100` (max length)
- `pattern: '^[a-z]+$'` (regex)
- `enum: ['draft', 'sent', 'paid']` (whitelist)

Custom field validation: override `method objValidate($value)` in a field
subclass (`fields/email.phlo`, etc.) for specific rules.

Retrieve errors:
```phlo
if (!user::create($args)){
    $errors = user::objErrors()
    return apply(errors: $errors, ...)
}
```

## 3. Non-int / non-'id' primary key

Add to a model:
```phlo
static idColumn = 'sku'
static idType = 'string'
```

From then on:
- The identity map uses the `sku` value as key.
- `Class::record(sku: 'ABC')` works (not `id:`).
- `Class::recordCount()`, `Class::createTable()`, `Class::objSchemaDiff()`, `Class::objRestore()` work with `sku`.
- `getParent`/`getChildren`/`getMany`/`getLast` lookups use `sku` for the target.

Caller side:
- On `create()`: pass the PK value yourself (no auto-increment):
  ```phlo
  giftcard::create(sku: 'XYZ-123', ...other)
  ```
- `$record->id` does NOT work for a non-`id` PK; use `$record->sku` (the real column name).

## Combining

All three together is fine:
```phlo
static objAudit = true
static objValidate = true
static idColumn = 'sku'
static idType = 'string'
```

No interaction effects; each flag is independent.

## Defaults

Every feature is off by default (`false`, `'id'`, `'int'`). A model without opt-ins stays a plain model.
