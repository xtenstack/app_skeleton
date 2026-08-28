---
name: xtmk-session-wrapup
description: Log requirements directly to the production requirements module as they surface during an XTMK-thread session, tagged so they're distinctly identifiable from app_skeleton/modules requirements, and write the end-of-session Session-Summary/Handover pair into stack.xten.au/claude/sessions/XTMK/. Use at the end of an XTen Marketing System (XTMK) session (the separate "XTMK Session #N" thread against the xten-marketing/xtmk.xten.au product), not the main App Skeleton session count.
---

# XTMK session wrap-up

This is the XTMK-thread variant of `session-wrapup` — same production-
requirements-logging discipline, same Session Summary/Handover shape,
different home folder, different numbering, and (settled XTMK Session
#1, 2026-08-28) its own tagging convention so these rows don't blend
into app_skeleton's or the Modules thread's own requirements.

**Home folder:** `stack.xten.au/claude/sessions/XTMK/`.

## What's shared with the main App Skeleton thread

- **The same production `/requirements` module (on stack-internal,
  `app_skeleton-db-1`), same `REQ-NNN` id space.** There is no separate
  requirements log for the XTMK thread — anything agreed as a real
  requirement during an XTMK session logs into the exact same system
  App Skeleton and Modules sessions use, continuing the same
  never-reused id sequence regardless of which thread originated it.
  Check the highest existing `display_id` first (numeric, not string,
  sort — `id` and `display_id` can drift, confirmed XTMK Session 1),
  including soft-deleted rows.
- **Claude's own memory** (auto-memory, not this repo) — cross-thread
  by design, keyed to the user/project, not the session.

## What's independent

- **Session numbering.** This thread counts its own sessions ("XTMK
  Session #1", #2, ...) rather than continuing the main App Skeleton
  count.
- **File naming** — prefixed `XTMK-` where it doesn't already live in
  its own `XTMK/` folder (see below).
- **Tagging on every requirement row this thread creates (XTMK Session
  1 decision — this is the identifiability requirement the user asked
  for directly):**
  - `project = 'xtmk'` — matches the existing `project = 'modules'`
    convention the Modules thread already uses; makes XTMK's
    requirements a one-column filter (`WHERE project = 'xtmk'`) instead
    of something read off free text.
  - `origin_session = 'XTMK Session <N>'` — matches the existing
    `'Modules Session <N>'` string convention, distinct from bare
    `'Session <N>'` (App Skeleton) at a glance in any listing.

  Note the xten-marketing/xtmk.xten.au product runs on its own droplet
  container (`xten-marketing-app-1`/`xten-marketing-db-1`) — the
  requirements module itself is NOT installed there. Writes go to
  `stack-internal`'s `app_skeleton-db-1` (`/requirements` on
  `stack-internal.xten.au`), same box every other thread uses; only the
  *tag* on the row says which thread the requirement came from.

## During the session: log requirements as they surface

Don't batch this to the end. Whenever the user agrees to something
requirement-shaped, create/update the row directly in prod's
`/requirements` module, continuing the next `REQ-NNN` id. Two ways to
do that, pick based on volume (mechanics identical to `session-wrapup`):

- **One or two, mid-session**: the backend UI (`/requirements/requirements/new`
  on stack-internal), or a curl+CSRF flow.
- **A batch, at session end**: a reviewed script of `INSERT`s against
  `app_skeleton-db-1`'s `requirements` table via
  `ssh stack-prod "docker exec -i app_skeleton-db-1 psql -U app_skeleton -d app_skeleton"`
  — dollar-quote (`$tag$...$tag$`) title/description/notes rather than
  escaping single quotes by hand, it's less error-prone when text
  contains contractions or inline code. Always set `project` and
  `origin_session` per the tagging convention above on every row this
  thread creates.

An "on hold" requirement (a named idea with no scope yet) still gets a
real row — `status = 'hold'`, `notes` explaining why, same as
`session-wrapup`'s own rule.

## At the end: three files, in `claude/sessions/XTMK/`

1. **`Session-N-Summary.md`** — one per XTMK-thread session, numbered
   sequentially from this thread's own count (this skill's first use is
   XTMK Session #1). Same structure as the main thread's
   `Session-NN-Summary.md`:
   - Start/end time line near the top (start time comes from the top
     of the session's own Request doc).
   - Opening paragraph: agenda vs. what actually landed.
   - `## Built and verified` — one bullet per major piece of work, each
     naming its `REQ-NNN` id(s) and how it was verified against the
     real running stack (not "should work").
   - `## Explicitly not done this session` — deferred items and why.
   - A cost indicator — token usage or another measure the user can
     start tracking session-over-session (asked for explicitly, XTMK
     Session 1).
   - `## Reference notes` — anything a future XTMK session would
     otherwise have to rediscover (droplet/container names, credential
     locations, non-obvious infrastructure facts).

2. **`Handover-YYYY-MM-DD-HHMM-TZ.md`** — stamped to the day and time
   written, plus timezone (same reasoning as the main thread's
   `session-wrapup`: more-than-daily sessions make a date-only name
   collide). Shorter, action-oriented:
   - `## First thing, next session`.
   - `## Needed from the user to unblock other things`.
   - `## Resolved this session, no longer open` — point at `REQ-NNN`
     ids (`project = 'xtmk'`) rather than repeating detail.
   - `## Reference notes`.

3. **`wrap-up-request(XTMK Session N).md`** — matches the main thread's
   own convention (see e.g. `claude/sessions/App Skeleton/
   wrap-up-request(App Skeleton Session 18).md`), not previously
   documented in either that skill or this one until XTMK Session 2
   caught the gap. One heading per item from the original
   `Request (XTMK Session N).pdf`, **in the order given**, each answered
   directly and concisely — what was built/decided, `REQ-NNN`
   cross-references, a pointer to `Session-N-Summary.md` for full
   technical detail rather than repeating it. After the original items,
   add a heading per substantive follow-on thread the session surfaced
   even if it wasn't in the request doc (a mid-session question that led
   to real work, an unplanned incident found and fixed, a scope
   negotiation) — same spirit as the main thread's examples, which cover
   things like a found production issue or a raised-and-resolved open
   question alongside the literal original bullets. If a past XTMK
   session skipped this file, that's likely an oversight (per the main
   thread's own precedent of backfilling a missed one) worth raising
   with the user rather than silently leaving a gap — but don't backfill
   it unasked.

If a session ends mid-stream rather than at a natural stopping point,
say so explicitly at the top of the handover.

## Don't

- Don't write these into the public repo's `docs/` folder — private,
  session-narrative documents, same rule as `session-wrapup`.
- Don't create a separate requirements log for this thread — there is
  only one, shared with App Skeleton and Modules.
- Don't skip the `project`/`origin_session` tagging on a row this
  thread creates, even for a one-off logged via the backend UI — that
  tagging is the entire point of this skill existing separately from
  plain `session-wrapup`.
