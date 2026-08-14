---
name: session-wrapup
description: Log requirements directly to the production requirements module as they surface during a session, and write the end-of-session Session-Summary/Handover pair. Use at the end of every session against this repo, and any time something requirement-shaped is agreed on mid-session — don't wait until the end to log it.
---

# Session wrap-up: production requirements, Session Summary, Handover

Per CLAUDE.md: *"Each working session against this repo produces a Session Summary and Handover in the private `stack.xten.au/claude/sessions/` folder, and logs anything requirement-shaped to the requirements in production as it comes up rather than only at the end."* This skill is the concrete how-to.

Both files live in `stack.xten.au/claude/sessions/` (private, not in this repo — see CLAUDE.md's "Where things live"). Never write session docs into the public `docs/` folder.

## `Requirements-List.md` is archived (Session 15) — production is the sole system of record

The user moved `Requirements-List.md` to `stack.xten.au/claude/sessions/archive/` as of Session 15 — it's a frozen historical record (entries through roughly REQ-093), not a living document anymore. **Don't add to it, don't treat it as current.** The live `/requirements` module on prod (since Session 14's REQ-081 import) is now the *only* place new or status-changing requirements get logged — there is no longer a markdown file to keep in sync with it.

## During the session: log requirements as they surface

Don't batch this to the end. Whenever the user agrees to something requirement-shaped — a feature, a fix, a scope decision, an explicit "log this for later" — create/update the row directly in prod's `/requirements` module, continuing the next `REQ-NNN` id (never reused, even for a dropped requirement — check the highest existing `display_id` first, including soft-deleted rows). Two ways to do that, pick based on volume:

- **One or two, mid-session**: use the backend UI directly (`/requirements/requirements/new` or `/edit/<id>`), or drive it with a curl + CSRF-token flow like any other authenticated POST (see `docs/CODING-STANDARDS.md`'s Testing section for the general pattern — extract `csrf-key`/`csrf-token` from the page's `<meta>` tags per `public/assets/js/app.js`, since forms inject CSRF via JS, not a static hidden field). Create fields: `title`, `description`, `type` (functional/user/internal/note), `origin_session` — `status` is set to `open` automatically. Update adds `status`, `notes`, `changelog_note`.
- **A batch, at session end**: same shape as the REQ-081 import — a short script generating `INSERT`/`UPDATE` statements against prod's `requirements` table via `docker compose exec -T db psql`, reviewed before running. Faster than N round-trips through the UI when several requirements changed in one session.

An "on hold" requirement (a named idea with no scope yet — this project has several: modules logged by name only) still gets a real row: `notes` = `On hold — user asked for this to be logged for a future session, no further detail given yet.` Don't fabricate scope that wasn't given. Don't let `notes`/`description` go stale-vague ("fixed the bug") — a future session (or a future *you*, months later) needs enough there to not have to re-read the whole diff to understand what actually happened. When updating an already-populated field via curl (not the UI), fetch the current value first — a plain form POST replaces the field, it doesn't merge.

## At the end: two files

1. **`Session-NN-Summary.md`** — one per session, numbered sequentially. Structure that's worked well across sessions:
   - **Session start/end time** (clarified Session 15) — a line near the top, e.g. `Started HH:MM, ended HH:MM (timezone)`, so a future session/you can see roughly how long this one ran without reconstructing it from message timestamps.
   - Opening paragraph: what the agenda was, roughly how much of it landed, honest framing if something was explicitly deferred rather than padded to look done.
   - `## Built and verified` — one bullet per major piece of work, each naming its REQ id(s), what the actual root cause/decision was (not just "fixed X"), and how it was verified (against the real running stack, not "should work"). This is the part worth being thorough in — it's what a future session actually reads.
   - `## Explicitly not done this session` — anything deferred, and why, including the user's own stated reasoning if they gave one.
   - `## Reference notes` — anything a future session would otherwise have to rediscover (droplet IPs, credential locations, non-obvious infrastructure facts).

2. **`Handover-YYYY-MM-DD-HHMM-TZ.md`** (e.g. `Handover-2026-08-14-0930-AWST.md`, or the offset form `Handover-2026-08-14-0930+0800.md` — both acceptable, clarified Session 17) — stamped to the day *and time* it's written, plus the timezone. The `HHMM` suffix matters as of Session 17: with sessions now running more than once a day, a date-only name would collide or get silently overwritten by the next same-day session. The `TZ` suffix matters because AWST doesn't observe daylight saving but the user isn't always working from the same zone — a bare local time is ambiguous without it. Same spirit as `last-project-audit`'s `Project-Audit-Report-*.md` stamps (that one predates the `TZ` suffix) — when looking for "the most recent handover," sort by the full stamp, not just the date. Shorter and more action-oriented than the summary — it's the "read this first" doc for next time, not the full record:
   - `## First thing, next session` — the actual next agenda item if the user named one, plus any guidance they already gave about *how* to approach it (don't make the next session re-derive instructions that were already stated).
   - `## Needed from the user to unblock other things` — anything genuinely stuck pending their action.
   - `## Resolved this session, no longer open` — a compact list, pointing at the prod `/requirements` module (by `REQ-NNN` id) for detail rather than repeating it.
   - `## Reference notes` — same purpose as the summary's, can duplicate the parts that matter most for picking work back up immediately (droplet access, deploy status, credential locations).

If a session ends mid-stream (a usage limit, not a natural stopping point — this has happened before), say so explicitly at the top of the handover, and make sure "first thing, next session" is unambiguous. That framing matters: a handover written at a natural stopping point can be terser than one written because the session got cut off mid-task.

## Don't

- Don't write these into the public repo's `docs/` folder — they're private, session-narrative documents (see CLAUDE.md's explicit exception for `docs/user-guide.md`, which is the *one* thing that's both user-facing product documentation and stays in-repo).
- Don't add rows to `Requirements-List.md` — it's archived, not a living document (see above).
