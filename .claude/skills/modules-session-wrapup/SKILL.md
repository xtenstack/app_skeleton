---
name: modules-session-wrapup
description: Log requirements directly to the production requirements module as they surface during a Modules-thread session, and write the end-of-session Modules-Session-N-Summary/Handover pair into stack.xten.au/claude/sessions/modules/. Use at the end of a module design/scoping/build-review session (the separate "Modules Session #N" thread), not the main App Skeleton session count.
---

# Modules session wrap-up

This is the Modules-thread variant of `session-wrapup` — same
production-requirements-logging discipline, same Session
Summary/Handover shape, different home folder, different numbering.

**Home folder (settled 2026-08-12, Modules Session #1):**
`stack.xten.au/claude/sessions/modules/`. An earlier `claude/modules/`
folder briefly existed with its own README describing a similar thread
at a different path — it predated this convention and has been
retired (its rationale is folded into this skill); don't write there.

## What's shared with the main App Skeleton thread

- **The same production `/requirements` module, same `REQ-NNN` id
  space.** There is no separate requirements log for the Modules
  thread — anything agreed as a real requirement during a Modules
  session logs into the exact same system App Skeleton sessions use,
  continuing the same never-reused id sequence regardless of which
  thread originated it.
- **Claude's own memory** (auto-memory, not this repo) — cross-thread
  by design, keyed to the user/project, not the session.

## What's independent

- **Session numbering.** This thread counts its own sessions ("Modules
  Session #1", #2, ...) rather than continuing the main App Skeleton
  count. Every Handover here notes which `REQ-NNN` ids it touched; any
  future App Skeleton session picking up reviewed module work should
  note which Modules Session produced it.
- **File naming** — prefixed `Modules-` to distinguish from the main
  thread's own `Session-NN-Summary.md`/`Handover-*.md` living one
  folder up.

## During the session: log requirements as they surface

Don't batch this to the end. Whenever the user agrees to something
requirement-shaped, create/update the row directly in prod's
`/requirements` module, continuing the next `REQ-NNN` id (check the
highest existing `display_id` first, including soft-deleted rows —
same rule as the main thread, since it's the same id space). Use the
backend UI or a curl+CSRF flow for one or two; a reviewed batch script
against prod's `requirements` table for several at once — see
`session-wrapup`'s own "During the session" section for the mechanics,
identical here.

## At the end: two files, in `claude/sessions/modules/`

1. **`Modules-Session-N-Summary.md`** — one per Modules-thread session,
   numbered sequentially from this thread's own count (this skill's
   first use is Modules Session #1). Same structure as the main
   thread's `Session-NN-Summary.md`:
   - Start/end time line near the top.
   - Opening paragraph: agenda vs. what actually landed, honest framing
     if something was deferred rather than padded to look done.
   - `## Built and verified` — one bullet per major piece of work, REQ
     id(s), the actual root cause/decision, how it was verified.
   - `## Explicitly not done this session` — deferred items and why.
   - `## Reference notes` — anything a future Modules session would
     otherwise have to rediscover.

2. **`Modules-Handover-YYYY-MM-DD.md`** — dated to the day written.
   Shorter, action-oriented:
   - `## First thing, next session` — next agenda item plus any
     guidance already given about how to approach it.
   - `## Needed from the user to unblock other things`.
   - `## Resolved this session, no longer open` — compact, pointing at
     `REQ-NNN` ids in prod rather than repeating detail.
   - `## Reference notes`.

If a session ends mid-stream rather than at a natural stopping point,
say so explicitly at the top of the handover, same as the main thread's
convention.

## Don't

- Don't write these into the public repo's `docs/` folder — private,
  session-narrative documents, same rule as `session-wrapup`.
- Don't write to the retired `claude/modules/` path.
- Don't create a separate requirements log for this thread — there is
  only one, shared with App Skeleton.
