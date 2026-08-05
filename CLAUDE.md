# CLAUDE.md

Read [docs/CODING-STANDARDS.md](docs/CODING-STANDARDS.md) first — this
file is the short version plus things specific to working here as
Claude/Claude Code.

## What this is

A Phalcon 5.8 multi-module PHP skeleton (see [README.md](README.md)):
server-rendered AdminLTE `backend` + JSON `api`, sharing one codebase,
plus a `cli` module (`./run <task> <action>`, `./run` with no args lists
everything). Postgres via the ORM. `frontend` (guest landing + member
dashboard) ships alongside `backend`/`api`/`cli` as base modules — see
`docs/user-guide.md` for the full module-type breakdown once you need
it.

## Module boundaries

- `backend`, `api`, and `cli` are separate Phalcon modules with their
  own controllers/models/autoloaders (`Module.php::registerAutoloaders()`).
  No cross-module reach-ins — a backend controller doesn't call an api
  controller's methods directly, etc.
- Backend (HTML) and API (JSON) controllers stay separate classes even
  for the same resource. Don't merge them behind a content-negotiation
  branch.
- Optional feature modules are Composer packages with a `module.json`
  manifest, discovered by `ModuleManager` and toggleable from the admin
  Configuration page (`./run modules sync|list|enable|disable`).
  `backend`/`api`/`cli`/`frontend` are core — always on, never listed
  there, disabling them would break the admin UI itself.

## Forbidden patterns

- Raw SQL string concatenation — bound parameters or the query builder,
  always.
- A POST endpoint without the CSRF token check (`ControllerBase::enforceCsrf()`
  handles this centrally — don't bypass it per-action).
- A new endpoint with no explicit `$allowedRoles` decision. "Temporarily
  public" is not a state this project ships.
- A bespoke `is_deleted`/`deleted_at` column instead of the `SoftDeletes`
  trait.
- Committing a secret, or writing one into `app/config/config.php`
  (secrets go in gitignored `config.local.php` / env vars only).
- Skipping real verification (browser click-through or `curl`/`./run`)
  before calling a change done — see docs/CODING-STANDARDS.md's Testing
  section.

## Where things live

- `Requirements-List.md` (in the private session-docs folder, not this
  repo) is the single running requirement log — `REQ-NNN` ids, never
  reused. Branch names reference them: `feature/REQ-020-short-name`.
- Process runbooks (branching/release strategy, dev-environment sync,
  list-view conventions) and one-off design/plan docs for bigger
  features (e.g. the ticketing and Requirements-module plans) live
  privately in `stack.xten.au/docs/`, not in this repo — not published
  with the OSS release. `docs/user-guide.md` is the one exception: it's
  user-facing product documentation, meant to ship with the product, so
  it stays in-repo.

## Sessions

Each working session against this repo produces a Session Summary and
Handover in the private `stack.xten.au/claude/sessions/` folder, and
logs anything requirement-shaped to `Requirements-List.md` as it comes
up rather than only at the end.
