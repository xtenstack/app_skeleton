---
name: session-request-wrapup
description: Summarize a session's initial Request doc plus every Q&A/instruction/decision that came up during the session into a wrap-up-request(...).md, stored alongside the Request doc. Use at the end of a session that started from a "Request (...)" PDF/pages doc, or when the user asks for a request wrap-up.
---

# Wrapping up a session's Request doc

Each session against this repo starts from a `Request (App Skeleton
Session N).pdf`/`.pages` doc in `stack.xten.au/claude/sessions/` — the
user's own agenda/questions for the session, sometimes with follow-up
instructions given mid-session that never made it into the original
doc. This skill produces the "what actually got asked and decided"
companion doc, since the original PDF only captures what was asked at
the *start*.

## Why this exists

The user has been inconsistently hand-copying mid-session asks into the
Request doc themselves. This skill replaces that manual step, and
covers what a plain re-read of the original PDF can't: follow-up
instructions, clarifications, and decisions that only happened in the
conversation itself.

## Steps

1. **Identify the session's Request doc** — `Request (<Project> Session
   N).pdf` in `stack.xten.au/claude/sessions/` (or the equivalent
   folder for a non-App-Skeleton thread, e.g. `claude/modules/`).
2. **Walk the full session transcript**, not just the opening message —
   every distinct ask, clarifying question asked back to the user (and
   their answer), scope decision, and mid-session instruction that
   shaped what got built. Group by topic, not strict chronological
   order, if the session covered many independent items (this project's
   sessions often do).
3. **Write `wrap-up-request(<Project> Session N).md`** in the same
   folder as the Request doc, structured as:
   - One entry per distinct ask/topic from the original doc, each
     noting what was actually decided or asked back, and the user's
     answer if there was a back-and-forth.
   - A separate section for anything raised *mid-session* that wasn't
     in the original doc at all (new asks, follow-up instructions).
   - Cross-reference `REQ-NNN` ids where applicable rather than
     repeating detail that already lives in `Requirements-List.md` — this
     doc is about *what was asked and decided*, not the technical
     how — that's the Requirements List's and Session Summary's job.
4. Keep it a request/decision log, not a technical summary — don't
   duplicate `Session-NN-Summary.md`'s "Built and verified" detail here.

## Don't

- Don't write this into the public repo — private session-narrative
  document, same rule as the other session-wrapup docs.
- Don't skip questions that were asked and answered inline (not via a
  formal AskUserQuestion) — a plain "should I do X or Y?" / "X" exchange
  in the conversation is exactly the kind of thing the original PDF
  never captures and this doc exists to.
