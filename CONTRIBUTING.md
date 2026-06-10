# Contributing to Phlo

Thanks for your interest in Phlo. Before you open a PR, please read this -
Phlo is deliberately small and opinionated, and contributions are judged
against that philosophy.

## The philosophy (what PRs are measured against)

1. **Small and dependency-free.** The engine has zero runtime dependencies:
   its own CSS transpiler, its own JS minifier, lazy Composer integration.
   A PR that adds a runtime dependency to the engine will not be merged.
2. **The closed loop.** Source → build → lint → sourcemap → error page →
   dashboard → reflection. Every error must point back to the `.phlo` line.
   Changes that break this loop (e.g. output that can't be source-mapped)
   need a very good reason.
3. **Agent-first.** `docs/SKILL.md` and the `reflect::` CLI are part of the
   product. If your change affects the language, the build, or the CLI,
   update `docs/SKILL.md` in the same PR.
4. **Convention over configuration.** New config keys need to justify their
   existence; the default answer is a convention, not a setting.

## Where to contribute

- **Resources (`resources/`)**, the preferred surface for contributions.
  Resources are isolated `.phlo` files with `@ summary` / `@ requires`
  metadata. New integrations, fields, DOM components, themes and transitions
  belong here. Follow the metadata conventions of the existing resources.
- **The engine (`phlo.php`, `functions.php`, `error.php`, `debug.php`,
  `control.php`, `classes/`)**, held to a stricter standard. Bug fixes and
  diagnostics improvements are welcome; structural changes should start as
  an issue/discussion, not a PR.
- **Docs (`docs/`)**, always welcome. Documentation is in English.

## Requirements for every PR

- **Tests.** Run `composer install && composer test`. Engine and compiler
  changes need test coverage:
  - Compiler behaviour (parser, codegen, CSS, assets): add or update a
    golden fixture under `tests/fixtures/`.
  - Runtime behaviour (`route()`, `obj`, helpers): add a unit test under
    `tests/`.
  - Bug fixes: add the test that fails without your fix.
- **Style.** Match the surrounding code: tabs, compact expressions, no
  superfluous abstractions, no comments that restate the code.
- **Scope.** One change per PR. Refactors and behaviour changes don't mix.
- **Changelog.** Add a line to the `[Unreleased]` section of `CHANGELOG.md`.

## Language changes

Changes to the `.phlo` language itself (syntax, semantics, build config keys)
have the highest bar: open an issue first and describe the problem, not the
solution. The strictness of the language is intentional, the goal is better
*diagnostics* for sharp edges, not a more forgiving parser at the cost of
compiler simplicity.

## Security issues

Never report security problems through public issues, see
[SECURITY.md](SECURITY.md).
