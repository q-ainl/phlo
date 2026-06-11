# Security Policy

## Supported versions

Only the latest tagged release and the `main` branch receive security fixes.

## Reporting a vulnerability

Please do **not** open a public issue for security problems.

Report vulnerabilities privately to **j@rdi.nu** (or via GitHub's
"Report a vulnerability" if enabled on the repository). Include:

- A description of the issue and where it lives (engine file, resource,
  dashboard section).
- Reproduction steps or a proof of concept.
- The impact as you understand it.

You can expect an acknowledgement within a few days. Fixes are released as
soon as practical; credit is given in the changelog unless you prefer to stay
anonymous.

## Scope notes

- The dashboard and the `build::`/`reflect::` CLI are **development tools**:
  they are only active when `build: true` (and the dashboard additionally
  requires `debug: true`). Running a production app with `build: true` is
  itself a misconfiguration, but reports about the engine failing to enforce
  these gates are very much in scope.
- Resources under `resources/security/` (CSRF, JWT, encryption, rate, audit,
  creds, token) are part of the trusted surface and in scope.
