# Governance

This document describes how Phlo is governed: who decides what, how those
decisions are made, and where the line runs between the project's core and its
wider ecosystem. It applies to all repositories under the Phlo umbrella (the
engine, the daemon, the dashboard, the CMS, and the official demos).

## In short

- Phlo is a **single-maintainer project** with a deliberately small, opinionated
  core. The maintainer has final say on direction.
- The **core is closed by default**: changes to the language, the engine and the
  build pipeline start as a discussion, not a pull request.
- The **ecosystem is open**: resources, integrations, themes and tools are meant
  to be built and shipped by the community, in their own repositories,
  discoverable through a curated registry, not merged into the core.
- A clear **"no"** is a feature. It is what keeps Phlo coherent.

## Who decides

Phlo is maintained by **Jordi (`j@rdi.nu`)** as the project lead. For a language
this is intentional: a single, consistent vision is what keeps the syntax, the
semantics and the diagnostics coherent across every layer. The maintainer:

- sets the direction and the scope of the core;
- reviews and merges, or declines, contributions;
- cuts releases and owns the version numbers;
- can grant triage and review rights to trusted contributors over time.

This is not a democracy, and that is by design. It is meant to be *benevolent*:
decisions are explained, disagreement is welcome in the open, and a "no" always
comes with a reason.

## The three surfaces

Phlo is contributed to at three levels, each with its own bar.

### 1. The core, highest bar

The `.phlo` language (syntax, semantics, build config keys), the engine
(`phlo.php`, `functions.php`, `error.php`, `classes/`), and the build, lint and
sourcemap loop.

- **Always discuss first.** Open an issue or a discussion describing the
  *problem*, not the solution. Do not open a PR for a non-trivial core change
  before there is agreement on the approach.
- Bug fixes and diagnostics improvements are welcome as PRs.
- New language features and config keys have the highest bar and must clear the
  philosophy in `CONTRIBUTING.md`: small, dependency-free, the closed loop,
  convention over configuration.

### 2. Resources, the preferred contribution surface

The `resources/` tree: integrations, fields, DOM components, security helpers,
themes, transitions. These are isolated `.phlo` files with `@ summary` /
`@ requires` metadata.

- This is where most contributions belong and where they are most welcome.
- A resource that is broadly useful, dependency-clean and follows the metadata
  conventions can ship in core.
- A resource that is niche, opinionated, or pulls in a heavy dependency belongs
  in the **ecosystem** (your own repo), not in core.

### 3. The ecosystem, open and in your own repositories

Anything that does not need to live in core: app-specific resources,
experimental integrations, project templates, tooling around Phlo.

- Build and ship these in **your own repository**, under your own name, on your
  own release schedule.
- Phlo's resource system is designed for exactly this: a resource is a
  self-contained `.phlo` file an app can drop in. You do not need to be merged
  into core to extend Phlo.
- We keep a **curated registry** (see below) so people can find your work. This
  is how Phlo grows *wide* without the core growing *heavy*, and how the project
  avoids fragmenting into a hundred half-merged forks.

## How a contribution flows

1. **Idea to issue or discussion.** For anything beyond a small, obvious fix,
   start a conversation. Describe the problem you hit and why the current
   features do not cover it. This is not bureaucracy: it saves you from writing
   code that will not be merged, and it lets the design be agreed before the
   effort is spent.
2. **Agreement to pull request.** Once the approach is agreed, open a PR that
   does *one* thing, passes CI, and follows the repository's `CONTRIBUTING.md`.
3. **Review to merge or revise.** The maintainer reviews. You may be asked for
   changes; be specific and patient, and so will the review be.

Small, obvious fixes (a typo, a clear bug with a failing test) can skip straight
to a PR.

## How a "no" works

Not every good idea belongs in Phlo's core, and that is not a judgement on the
idea. A decline names its reason, usually one of:

- **Out of core scope.** "This is a great fit for a resource in your own repo;
  here is how to publish it to the registry."
- **Adds a dependency.** The engine is zero-dependency on purpose.
- **Breaks the closed loop.** Output that cannot be source-mapped, errors that
  cannot point back to a `.phlo` line.
- **More configuration than convention.** A setting where a convention would do.

A "no" to core is very often a "yes" to the ecosystem. The goal is to keep the
thing one person can hold in their head, while letting the surface around it grow
without limit.

## The ecosystem registry

Community resources, themes and tools live in their own repositories and are
listed in the curated registry at
**[phlo.tech/ecosystem](https://phlo.tech/ecosystem)**. To get listed, open an
issue on the [engine repository](https://github.com/q-ainl/phlo/issues) with your
repository, a one-line description, and the resource metadata. A listing means
"this exists and installs cleanly", not an endorsement or a support commitment.
Anything malicious, abandoned or misleading is removed.

## Releases and versioning

Phlo follows [Semantic Versioning](https://semver.org): `MAJOR.MINOR.PATCH`.

- **PATCH** (`1.0.x`): backward-compatible bug fixes.
- **MINOR** (`1.x.0`): backward-compatible additions.
- **MAJOR** (`x.0.0`): breaking changes, used sparingly.

Releases are cut from `main` after a release-candidate soak. The stack (engine,
daemon, dashboard, CMS) moves to a new major or minor together so the versions
stay legible. The maintainer owns the version numbers and the changelog.

## Security

Security issues are never reported in public. See `SECURITY.md`.

## Code of conduct

Participation is governed by our Code of Conduct. Be respectful and
constructive; the maintainer has final say on conduct as on code.

## Changing this document

This document is maintained by the project lead. Suggestions are welcome as
discussions; changes are made by the maintainer.
