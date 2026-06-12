# Phlo model: opt-in features

`@ extends: model` gives you CRUD + identity map + relations + soft-delete by
default. There are three additional opt-in features.

## 1. Audit log

Add `static objAudit = true` to a model. From then on:
- `Class::create(...)`: on success, `%audit->log($record, 'create', [], (array)$record)`.
- `Class::delete($where, ...)`: per affected record, `%audit->log($record, 'delete', (array)$record, [])`.
- `$record->objSave()`: on update, `%audit->log($saved, 'update', (array)$old, (array)$saved)`.
- `Class::objLogChange($where, ...)`: an alternative update that pre-fetches and diffs for `%audit->log`. Use it instead of `Class::change()` when you want per-record auditing on a bulk update.

Requirements:
- Add `security/audit` to the `data/app.json` resources.
- Import `resources/security/audit.sql` (in the engine directory) into the app database once: `mysql <database> < <engine>/resources/security/audit.sql`.

Exclude sensitive fields:
```phlo
method afterCreate => %audit->log($this, 'create', [], (array)$this, exclude: ['password_hash'])
```

Dev/build mode only (off in release):
```phlo
static objAudit => debug
```

Release/production only (off in dev):
```phlo
static objAudit => !debug
```

(`debug` is a Phlo runtime constant set by `phlo_app(debug: true|false)`.)

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
