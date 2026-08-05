---
name: session-wrapup
description: Log requirements as they surface during a session, and write the end-of-session Requirements-List/Session-Summary/Handover trio. Use at the end of every session against this repo, and any time something requirement-shaped is agreed on mid-session — don't wait until the end to log it.
---

# Session wrap-up: Requirements-List, Session Summary, Handover

Per CLAUDE.md: *"Each working session against this repo produces a Session Summary and Handover in the private `stack.xten.au/claude/sessions/` folder, and logs anything requirement-shaped to `Requirements-List.md` as it comes up rather than only at the end."* This skill is the concrete how-to.

All three files live in `stack.xten.au/claude/sessions/` (private, not in this repo — see CLAUDE.md's "Where things live"). Never write session docs into the public `docs/` folder.

## During the session: log requirements as they surface

Don't batch this to the end. Whenever the user agrees to something requirement-shaped — a feature, a fix, a scope decision, an explicit "log this for later" — add a row to `Requirements-List.md`'s table immediately, continuing the next `REQ-NNN` id (never reused, even for a dropped requirement). Match the existing table's tone: lead with what was decided/built, a **Status** cell with enough detail that a future session doesn't have to re-derive it (root cause, what was verified, what's deliberately incomplete), and the originating session(s).

An "on hold" requirement (a named idea with no scope yet — this project has several: modules logged by name only) still gets a real row: `On hold — user asked for this to be logged for a future session, no further detail given yet.` Don't fabricate scope that wasn't given.

## At the end: three files

1. **`Requirements-List.md`** — already current if you logged as you went (above). Do a final pass: anything agreed late in the session that hasn't been logged yet.

2. **`Session-NN-Summary.md`** — one per session, numbered sequentially. Structure that's worked well across sessions:
   - Opening paragraph: what the agenda was, roughly how much of it landed, honest framing if something was explicitly deferred rather than padded to look done.
   - `## Built and verified` — one bullet per major piece of work, each naming its REQ id(s), what the actual root cause/decision was (not just "fixed X"), and how it was verified (against the real running stack, not "should work"). This is the part worth being thorough in — it's what a future session actually reads.
   - `## Explicitly not done this session` — anything deferred, and why, including the user's own stated reasoning if they gave one.
   - `## Reference notes` — anything a future session would otherwise have to rediscover (droplet IPs, credential locations, non-obvious infrastructure facts).

3. **`Handover-YYYY-MM-DD.md`** — dated to the day it's written, not the session number. Shorter and more action-oriented than the summary — it's the "read this first" doc for next time, not the full record:
   - `## First thing, next session` — the actual next agenda item if the user named one, plus any guidance they already gave about *how* to approach it (don't make the next session re-derive instructions that were already stated).
   - `## Needed from the user to unblock other things` — anything genuinely stuck pending their action.
   - `## Resolved this session, no longer open` — a compact list, pointing at Requirements-List.md for detail rather than repeating it.
   - `## Reference notes` — same purpose as the summary's, can duplicate the parts that matter most for picking work back up immediately (droplet access, deploy status, credential locations).

If a session ends mid-stream (a usage limit, not a natural stopping point — this has happened before), say so explicitly at the top of the handover, and make sure "first thing, next session" is unambiguous. That framing matters: a handover written at a natural stopping point can be terser than one written because the session got cut off mid-task.

## Don't

- Don't write these into the public repo's `docs/` folder — they're private, session-narrative documents (see CLAUDE.md's explicit exception for `docs/user-guide.md`, which is the *one* thing that's both user-facing product documentation and stays in-repo).
- Don't let the Requirements-List table's entries go stale-vague ("fixed the bug") — a future session (or a future *you*, months later) needs enough in the Status cell to not have to re-read the whole diff to understand what actually happened.
