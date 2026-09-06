---
name: modules-session-list
description: List every module currently being worked on, mooted, or on hold, with a one-line status each, sourced from docs/module-plans/, the MAAMA register, composer.local.json, and the module-templates repo. Use at the start (or on request) of a Modules-thread session to get an at-a-glance picture before deciding what to work on, without re-deriving it from scratch each time.
---

# Modules session list

A Modules-thread session (see `modules-session-wrapup`) usually starts
with "what's actually in flight?" — this skill answers that in one pass
instead of re-reading every plan doc and MAAMA row from scratch each
session.

## Sources, in order

1. **`stack.xten.au/docs/module-plans/*.md`** — one file per named
   module/package, whether built, in progress, or still an outline. A
   plan existing here doesn't mean it's built; check its own status
   language (see below).
2. **`app_skeleton/composer.local.json`** (this instance's own, untracked
   — see `docs/INTERNAL-MODULES.md`) — which modules are actually
   `require`d on *this* dev checkout right now is the strongest signal
   something is built and wired in, not just planned.
3. **`xtenstack/module-templates`** (public repo) — the blank
   application-tier/plugin-tier scaffolding modules; check via `gh` (branches,
   merged PRs) since it isn't required by any instance's
   `composer.local.json` under normal use (it's copied, not installed
   standing).
4. **MAAMA Handover Register** (`MAAMA/MAAMA Handover Register.md`) —
   filter rows whose Subject touches a module by name; a module can be
   blocked on an open MAAMA row even if its own plan doc looks finished.
5. **`AutoClaudeDev/Task Prompts/`** and
   `internal/queue/{pending,done,failed}/` — an ACD launch prompt
   existing here but no matching run log means "queued, not yet run."

## What counts as a status

Don't invent a fixed taxonomy per module — read each plan doc's own
language (a plan dated/titled "built and verified", a "Queued, not
started" line, an explicit "deferred"/"superseded" note) rather than
guessing from the plan's mere existence. Roughly:

- **Shipped** — built, deployed somewhere real (say where — Internal
  Prod, stack-prod, a specific instance), not just committed to a
  feature branch.
- **In progress** — actively being built or mid-review this thread.
- **Queued** — plan + ACD prompt (or equivalent) ready, waiting on
  something specific (another module finishing, a decision, a slot in
  the run queue) — name the specific blocker, not just "not started."
- **Mooted / planned** — a scope/outline doc exists, no build attempted.
- **On hold** — was in progress or planned, explicitly paused by Travis
  with a reason on record (don't mark something "on hold" just because
  it's been quiet — that's most likely just "mooted" or "queued").

## What to do

1. Read every file under `docs/module-plans/`, `composer.local.json`,
   and the MAAMA register (steps 1-4 above).
2. For each distinct module (not engine features like nav takeover or
   the dependency framework — those live in the requirements module as
   `REQ-NNN` items, not here), produce one line: **name — status —
   one-clause why**, plus the specific blocker for anything queued or on
   hold.
3. Present as a flat list grouped by status (Shipped / In progress /
   Queued / Mooted / On hold), not by discovery order.
4. Cross-reference open MAAMA rows or `REQ-NNN` ids inline rather than
   repeating their detail.

## Don't

- Don't treat a module-plan doc's existence as proof of a build — most
  of these are outlines. Read the doc's own status language.
- Don't duplicate MAAMA/requirements-module detail — link/cite the id,
  same discipline as the other Modules-thread skills.
- Don't list engine-level features (nav takeover, module dependency
  framework, license check-in) as modules — those are `REQ-NNN` items
  against the base engine, not entries in `module-plans/`.
