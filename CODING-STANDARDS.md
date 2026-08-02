# Coding Standards

This document describes the conventions already in use throughout the
codebase — it's a description of the existing style, not a new set of
rules being imposed. When in doubt, look at a neighboring file in the same
directory before asking here.

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

## Comments

Default to none. A comment earns its place only when it explains a
non-obvious **why** — a hidden constraint, a workaround for a specific
bug, a decision that would otherwise look arbitrary. If removing a
comment wouldn't leave a future reader confused, it shouldn't be there.
Don't restate what well-named code already says, and don't reference the
current ticket/PR/task in a comment — that belongs in the commit message
or PR description, not in code that outlives it.

## Models

- Soft deletes via the `SoftDeletes` trait (`app/common/library/... ` →
  see `App_skeleton\Models\SoftDeletes`), not a bespoke `is_deleted`
  column per table. `find()`/`findFirst()` exclude trashed rows
  automatically; use the `*WithTrashed()` variants when you deliberately
  need everything.
- `keepSnapshots(true)` on any model that should show up in the audit
  log — the audit listener is attached globally in
  `app/config/services.php`'s `modelsManager` factory, so this is the
  only per-model opt-in needed.

## Controllers

- `ControllerBase::$allowedRoles` for role gating — `null` means "any
  authenticated user," an array of role ids restricts further. Resolve
  role ids at runtime via `Roles::idsByNames([...])`, never hardcode an
  id.
- Backend (HTML) and API (JSON) controllers are separate classes even
  when they cover the same resource (e.g. `Tickets`) — triage/admin
  actions live backend-only, machine-facing actions live api-only. Don't
  merge them into one controller with a content-negotiation branch.
- Every POST across the backend must carry the CSRF token the layout
  embeds — this is enforced centrally in `ControllerBase::enforceCsrf()`,
  not per-action.

## Migrations

Plain SQL, one file per change, under `db/migrations/<adapter>/`, applied
in filename order (`./run migrate run`). Each migration runs in its own
transaction. Write a short comment at the top of the file explaining the
*why* behind non-obvious column choices (nullable FKs, default values) —
see any existing migration for the expected level of detail.

## Testing changes before calling them done

For anything the browser can exercise, actually drive it (start the dev
server, click through the real flow) rather than asserting it works from
reading the diff. For anything that can't be driven through the UI
(background jobs, file downloads, API-only endpoints), exercise it with
a real request (`curl`, `./run`) against a running instance.
