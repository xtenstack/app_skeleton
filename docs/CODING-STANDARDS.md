# Coding Standards

This document describes the conventions already in use throughout the
codebase — it's a description of the existing style, not a new set of
rules being imposed. When in doubt, look at a neighboring file in the same
directory before asking here.

This project follows the company-wide **XTen Coding Standard**
(deploy.xten.au, internal) wherever the two don't conflict; sections
below are this repo's concrete instantiation of that standard, plus a
short [Deviations](#deviations-from-the-xten-coding-standard) list where
they diverge on purpose.

## Base standard: PSR-12

All PHP code follows [PSR-12](https://www.php-fig.org/psr/psr-12/) —
deliberate, since Phalcon's own coding standard is a PSR-12 variant, so
this code, framework code, and the wider PHP ecosystem read the same
way. In practice: 4-space indentation, StudlyCaps classes, camelCase
methods, one class per file, `declare(strict_types=1)`, a 120-character
hard line limit (aim for 80).

## PHP

- `declare(strict_types=1);` at the top of every PHP file.
- PSR-4 autoloading for shared library code (`App_skeleton\` →
  `app/common/library/`). Module controllers/models are autoloaded per
  module (see each `Module.php`'s `registerAutoloaders()`), not under a
  namespace prefix — this matches Phalcon's own module convention and
  keeps `Tickets::find(...)` etc. usable unqualified inside controllers.
- No abstractions ahead of need. Three similar lines beat a premature
  helper; a one-off script doesn't need a class hierarchy. See
  `[[project_app_skeleton_goals]]` for why (deliberately chosen over
  Laravel's heavier conventions).
- Validate at system boundaries (controller input, external API
  responses) — not defensively everywhere. Trust internal code and model
  guarantees once past that boundary.
- Every function and method declares parameter and return types.

## Comments

Default to none. A comment earns its place only when it explains a
non-obvious **why** — a hidden constraint, a workaround for a specific
bug, a decision that would otherwise look arbitrary. If removing a
comment wouldn't leave a future reader confused, it shouldn't be there.
Don't restate what well-named code already says, and don't reference the
current ticket/PR/task in a comment — that belongs in the commit message
or PR description, not in code that outlives it. Public methods carry
docblocks only where the types themselves don't tell the story.

## Models

- Soft deletes via the `SoftDeletes` trait (`app/common/library/... ` →
  see `App_skeleton\Models\SoftDeletes`), not a bespoke `is_deleted`
  column per table. `find()`/`findFirst()` exclude trashed rows
  automatically; use the `*WithTrashed()` variants when you deliberately
  need everything. Every destructive action goes through this
  soft-delete pathway, not a hard `DELETE`.
- `keepSnapshots(true)` on any model that should show up in the audit
  log — the audit listener is attached globally in
  `app/config/services.php`'s `modelsManager` factory, so this is the
  only per-model opt-in needed.

## Controllers

- `ControllerBase::$allowedRoles` for role gating — `null` means "any
  authenticated user," an array of role ids restricts further. Resolve
  role ids at runtime via `Roles::idsByNames([...])`, never hardcode an
  id. New endpoints declare their RBAC requirement explicitly —
  "temporarily public" is not a state this project ships.
- Backend (HTML) and API (JSON) controllers are separate classes even
  when they cover the same resource (e.g. `Tickets`) — triage/admin
  actions live backend-only, machine-facing actions live api-only. Don't
  merge them into one controller with a content-negotiation branch.
- Every POST across the backend must carry the CSRF token the layout
  embeds — this is enforced centrally in `ControllerBase::enforceCsrf()`,
  not per-action.

## Security (non-negotiable)

- No raw SQL string concatenation — use the ORM/query builder or bound
  parameters, always.
- All output escaped by default — Volt's auto-escaping stays on.
- Every state-changing form/endpoint carries CSRF protection (see
  Controllers above).
- Input validated at the boundary against an allow-list, not sanitised
  after.
- Secrets live in environment/`config.local.php` outside the
  repository (gitignored) — a committed secret is an incident.
- Every destructive action goes through the audit/soft-delete pathway
  (see Models above).

## Database portability

New DB code must be PDO-portable / database-agnostic — no
Postgres-specific SQL constructs (e.g. `ILIKE`, `RETURNING`-style
idioms tied to one adapter) where a portable equivalent exists, and
prefer the ORM/query builder over raw SQL in the first place. This is
forward-looking: pgsql/mysql/sqlite demo installs are planned, so code
written against Postgres-only syntax today becomes a migration problem
later. Applies to new/changed code only — existing Postgres-specific
code (e.g. the search implementation) is a known, deliberately
deferred backlog item, not something to refactor incidentally while
touching nearby code.

## Migrations

Plain SQL, one file per change, under `db/migrations/<adapter>/`, applied
in filename order (`./run migrate run`). Each migration runs in its own
transaction. Write a short comment at the top of the file explaining the
*why* behind non-obvious column choices (nullable FKs, default values) —
see any existing migration for the expected level of detail.

## Git workflow

Trunk-based: `main` is the only ongoing branch, always releasable; a
short-lived `release` branch is cut only when actually preparing to
ship a tagged version, frozen except for bugfixes. Branch names:
`feature/<REQ-id>-short-name` or `fix/<issue>`. Commits follow
[Conventional Commits](https://www.conventionalcommits.org/)
(`feat:`/`fix:`/`docs:`/`refactor:`/`chore:`/`test:`). Every merge to
`main` goes through a reviewed PR.

## AI-augmented development

Claude/Claude Code use is expected and encouraged on this project. The
rules that keep it safe, per the XTen Coding Standard:

- **You own what you commit.** Every AI-generated line is reviewed and
  understood by the committing developer. "Claude wrote it" is never an
  explanation.
- **Tests before trust.** AI-written code meets the same review/testing
  gates as any other code (see [Testing](#testing-changes-before-calling-them-done)
  below) — the gates are the standard; the author is irrelevant.
- No client/production data goes into a prompt.
- This file plus `CLAUDE.md` (repo root) are what a Claude Code session
  is expected to have read before making changes here.

## Testing changes before calling them done

For anything the browser can exercise, actually drive it (start the dev
server, click through the real flow) rather than asserting it works from
reading the diff. For anything that can't be driven through the UI
(background jobs, file downloads, API-only endpoints), exercise it with
a real request (`curl`, `./run`) against a running instance.

`vendor/bin/phpunit` (`tests/Unit/`, `tests/Feature/`) covers CSRF
rejection, RBAC denial, and soft-delete exclusion — see
[CONTRIBUTING.md](CONTRIBUTING.md#testing) for how to run it and what
it does and doesn't cover. It's a baseline, not comprehensive coverage
— the manual-verification standard above still applies for anything
outside those three patterns. A `.github/workflows/` build/smoke check
plus this suite both run on every push/PR — see the README badge.

## Deviations from the XTen Coding Standard

Documented here per the master standard's own rule: "deviations need a
written reason in the PR — good reasons update this document."

- **PHP 8.3, not 8.5.** The master standard encourages PHP 8.5 features;
  this repo pins PHP 8.3 because Phalcon 5 only publishes prebuilt
  binaries up to PHP 8.3 (verified against packages.sury.org) — see the
  `Dockerfile`'s top comment for the full reasoning. Revisit once
  Phalcon ships 8.4/8.5 binaries.
- **No PHPStan/PHP_CodeSniffer CI gate yet.** The master standard expects
  both; this repo currently only runs a build/smoke-test workflow (real
  Phalcon install, migrate, seed, boot, curl real endpoints — no static
  analysis or a PSR-12 lint step yet). Tracked as a backlog item, not
  silently skipped.
