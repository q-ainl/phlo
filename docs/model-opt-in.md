# Phlo model: opt-in features

`@ extends: model` levert standaard CRUD + identity-map + relaties + soft-delete. Sinds 2026-05 zijn er drie extra opt-in features.

## 1. Audit-log

Voeg `static objAudit = true` toe op een model. Vanaf dan:
- `Class::create(...)`: na success, `%audit->log($record, 'create', [], (array)$record)`.
- `Class::delete($where, ...)`: per geraakt record, `%audit->log($record, 'delete', (array)$record, [])`.
- `$record->objSave()`: bij update, `%audit->log($saved, 'update', (array)$old, (array)$saved)`.
- `Class::objLogChange($where, ...)`: alternatieve update die pre-fetcht + diff voor `%audit->log`. Gebruik in plaats van `Class::change()` als je per-record-audit op bulk-update wilt.

Vereisten:
- Voeg `security/audit` toe aan `data/app.json` resources.
- Importeer eenmalig `resources/security/audit.sql` (in de engine-map) in de app-database: `mysql <database> < <engine>/resources/security/audit.sql`.

Sensitive fields uitsluiten:
```phlo
method afterCreate => %audit->log($this, 'create', [], (array)$this, exclude: ['password_hash'])
```

Alleen in dev/build-mode (uit in release):
```phlo
static objAudit => debug
```

Alleen in release/productie (uit in dev):
```phlo
static objAudit => !debug
```

(`debug` is een Phlo-runtime-constant gezet door `phlo_app(debug: true|false)`.)

## 2. Validation

Voeg `static objValidate = true` toe op een model. Vanaf dan:
- `Class::create($args)`: pre-flight `objRunValidation($args)`. Bij errors: `Class::$objLastErrors` populated, `create()` returnt `null`.
- Per field in `static schema()` wordt `field->objValidate($value)` aangeroepen.

Veld-rules in `field()`:
- `required: true`
- `length: 100` (max-length)
- `pattern: '^[a-z]+$'` (regex)
- `enum: ['draft', 'sent', 'paid']` (whitelist)

Custom field-validatie: override `method objValidate($value)` in een field-subclass (`fields/email.phlo`, etc.) voor specifieke regels.

Errors ophalen:
```phlo
if (!user::create($args)){
    $errors = user::objErrors()
    return apply(errors: $errors, ...)
}
```

## 3. Non-int / non-'id' primary key

Voeg toe op een model:
```phlo
static idColumn = 'sku'
static idType = 'string'
```

Vanaf dan:
- Identity-map gebruikt `sku`-waarde als key.
- `Class::record(sku: 'ABC')` werkt (niet `id:`).
- `Class::recordCount()`, `Class::createTable()`, `Class::objSchemaDiff()`, `Class::objRestore()` werken met `sku`.
- `getParent`/`getChildren`/`getMany`/`getLast` lookups gebruiken `sku` voor target.

Caller-side:
- Bij `create()`: meegeef PK-waarde zelf (geen auto-increment):
  ```phlo
  giftcard::create(sku: 'XYZ-123', ...other)
  ```
- `$record->id` werkt NIET voor non-`id` PK; gebruik `$record->sku` (de echte kolom-naam).

## Combineren

Alle drie samen mag:
```phlo
static objAudit = true
static objValidate = true
static idColumn = 'sku'
static idType = 'string'
```

Geen interactie-effecten; elke flag staat los.

## Backwards compat

Alle defaults: `false` of `'id'`/`'int'`. Bestaande models onveranderd.
