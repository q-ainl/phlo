# Versioning, compatibility and upgrading

Phlo follows [Semantic Versioning](https://semver.org/) from `1.0.0` onward. The
engine version is the `const phlo` in `phlo.php`.

Given a version `MAJOR.MINOR.PATCH`:

- **PATCH** (`1.0.x`) - bug fixes and security fixes only. No new features and no
  behaviour changes to documented APIs.
- **MINOR** (`1.x.0`) - backwards-compatible additions: new resources, new
  optional arguments, new CLI subcommands. Existing apps keep building and
  running unchanged.
- **MAJOR** (`x.0.0`) - changes that can require app changes. Only a major
  release may remove a deprecated API or change documented behaviour.

## What "compatible" covers

The compatibility promise applies to the **public surface** an app builds on:

- the `.phlo` language syntax and the build pipeline (`build::run`,
  `build::lint`);
- the documented resources and their documented method/argument signatures
  (`docs/SKILL.md`, `docs/model-opt-in.md`, `docs/connectors.md`, and the other
  files under `docs/`);
- the `phlo_app()` entry options;
- the CLI surface (`build::`, `reflect::`);
- the output contract (`view` / `apply` / `chunk` and the `apply()` DOM
  protocol).

It does **not** cover, and these may change in any release:

- the exact shape of the generated `php/`, `app.css` and `app.js` output - an
  implementation detail. Rebuild after upgrading; never hand-edit the output or
  depend on its shape.
- internal or undocumented classes, methods and constants;
- behaviour explicitly documented as undefined or "may change".

## Deprecations

A feature that will be removed is first **deprecated in a MINOR release**: it
keeps working, and the deprecation is recorded in `CHANGELOG.md` (and surfaced at
build time where that helps). It is only **removed in the next MAJOR**.

So an app that builds clean on the latest `1.x` keeps building across every later
`1.x`, and the changelog tells you what to change before `2.0`.

## Supported PHP versions

Phlo `1.x` supports **PHP 8.3, 8.4 and 8.5**, on both NTS and ZTS (FrankenPHP
worker mode). CI runs the test suite on all three.

## Upgrading

Phlo is the engine your app builds against, so upgrading is replacing the engine
and rebuilding:

1. Update the engine to the new tag - `git pull`, `composer update phlo/tech`, or
   pull the Docker image, depending on how you vendor it.
2. Run `build::run`, then `build::lint`, against your app.
3. Read the `CHANGELOG.md` section for the new version. Within a major series a
   clean build means you are done; a major bump lists what to change.

The generated `php/` output is derived - always rebuild rather than carrying it
across an upgrade.

### From a release candidate to 1.0.0

`1.0.0` is `1.0.0-RC3` plus the fixes listed under that version in the changelog;
there are no breaking changes from RC3. Update the engine and rebuild.
