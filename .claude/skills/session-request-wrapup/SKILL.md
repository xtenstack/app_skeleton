---
name: session-request-wrapup
description: Review the session's work against the initial Request doc's own items only, writing a wrap-up-request(...).md alongside it. Use at the end of a session that started from a "Request (...)" PDF/pages doc, or when the user asks for a request wrap-up.
---

# Wrapping up a session's Request doc

Each session against this repo starts from a `Request (App Skeleton
Session N).pdf`/`.pages` doc in `stack.xten.au/claude/sessions/` — the
user's own agenda/questions for the session. This skill produces the
"what actually got decided, per item in that original doc" companion
doc.

**Scope note (clarified Session 15):** this skill covers *only* the
items that were actually in the original Request doc. Anything raised
mid-session that wasn't in that doc at all — a new ask, a follow-up
instruction, an inline decision that came up along the way — belongs
in `Session-NN-Summary.md` instead (the `session-wrapup` skill), not
here. Before this clarification the two skills overlapped; don't
re-introduce that by adding a "mid-session asks" section back into this
doc.

## Why this exists

The user has been inconsistently hand-copying original-doc items'
outcomes back into their own records. This skill replaces that manual
step: walk the original doc's own items and confirm/record what
actually happened to each one.

## Steps

1. **Identify the session's Request doc** — `Request (<Project> Session
   N).pdf` in `stack.xten.au/claude/sessions/` (or the equivalent
   folder for a non-App-Skeleton thread, e.g. `claude/modules/`).
2. **Walk the session transcript against the original doc's own items
   only** — for each thing the doc actually asked, what was decided,
   any clarifying back-and-forth that shaped it, and the outcome. Group
   by topic, not strict chronological order, if the doc covered many
   independent items (this project's sessions often do).
3. **Write `wrap-up-request(<Project> Session N).md`** in the same
   folder as the Request doc: one entry per distinct ask/topic from the
   original doc, each noting what was actually decided or asked back,
   and the user's answer if there was a back-and-forth. Cross-reference
   `REQ-NNN` ids where applicable rather than repeating detail that
   already lives in the production requirements system — this doc is
   about *what was asked and decided*, not the technical how — that's
   the requirements system's and Session Summary's job.
4. Keep it a request/decision log, not a technical summary — don't
   duplicate `Session-NN-Summary.md`'s "Built and verified" detail here.

## Don't

- Don't write this into the public repo — private session-narrative
  document, same rule as the other session-wrapup docs.
- Don't skip questions that were asked and answered inline (not via a
  formal AskUserQuestion) — a plain "should I do X or Y?" / "X" exchange
  about an item that *was* in the original doc is exactly the kind of
  thing a plain re-read of the PDF misses and this doc exists to.
- Don't add a mid-session-asks section back in — that's
  `session-wrapup`'s job now (see Scope note above).
