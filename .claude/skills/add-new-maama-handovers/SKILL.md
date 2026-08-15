---
name: add-new-maama-handovers
description: Create structured MAAMA handovers for anything that surfaced this session but belongs to a different agent, project, or session to actually act on — and update the MAAMA Handover Register. Use at the end of a session (alongside session-wrapup) whenever something requirement-shaped surfaced that's genuinely outside this session's own remit.
---

# Creating MAAMA handovers

The companion skill to `check-maama-register` — that one reads the
register at session start, this one writes to it at session end. Full
mechanics: `OneDrive(XTen)/Entities/xten.au/MAAMA/MAAMA-Handover-Register-Specification.md`
(Sections 7 and 9 especially — the detail file template and the update
rules this skill follows).

## When something needs a MAAMA handover (and when it doesn't)

Not everything deferred is a MAAMA handover. It's a MAAMA handover
specifically when the work belongs to a **different agent, project, or
session** to actually act on — not just "not done this session." A
concrete test: would logging it as a `REQ-NNN` in *this* project's own
requirements module be wrong, because the actual owner isn't this
project at all? If yes, it's MAAMA-shaped. If the work is still this
project's own responsibility, just deferred, it's a normal
`session-wrapup` carry-forward item instead — don't create a MAAMA
handover for it.

Example from Session 16/18: an AI-disclosure clause needed adding to
`Support-Operations-Pack.md`'s voice-channel signature policy — but
that document lives under `deploy.xten.au`, a different business
document outside `app_skeleton`'s own remit entirely. Not a `REQ-NNN`
here; a MAAMA handover to whichever agent/session actually owns that
document.

## What to do, per handover

1. **Pick the next Handover ID**: `MAA-YYYYMMDD-NNN` — `NNN` increments
   per day, check the register for the highest existing number on that
   date (or `001` if none yet today).
2. **Create the detail file** at
   `OneDrive(XTen)/Entities/xten.au/MAAMA/Handovers/MAA-YYYYMMDD-NNN-short-subject.md`,
   following the spec's Section 7 template exactly: Metadata table,
   `## Context` (why this exists — which session, what triggered it),
   `## Content` (the actual actionable detail — specific file/system
   named, not just the general topic), `## Update Trail` (first entry:
   creation, today's date, this session as Actor).
3. **Add a register row** to
   `OneDrive(XTen)/Entities/xten.au/MAAMA/MAAMA Handover Register.md`
   — same columns as the existing rows, `Status` = `Open` unless the
   handover is purely informational and already complete (Section 9's
   rule).
4. **Tell the user** what was created — Handover ID, one-line subject,
   target — as part of the session's own wrap-up, not buried.

## Updating an existing handover's status

Same two-file discipline, smaller scope: update the detail file's
Update Trail with a new row (date, actor, what changed), update the
register row's `Status`/`Last Updated`/`Notes` to match, and add a
short register `Notes` update only if the change affects
discoverability (Section 9).

## Don't

- Don't create a MAAMA handover for work that's still this project's
  own responsibility — that's a `REQ-NNN` via `session-wrapup`, not
  this skill. MAAMA is for genuine cross-agent/cross-project handoffs.
- Don't duplicate the detail file's content into the register row — the
  register stays a short index (Section 4); link to the detail file,
  don't repeat it.
- Don't set a handover's status to `Closed` unilaterally on the target's
  behalf — that's the target's call once they've actually acted on it,
  same spirit as this project's own rule that ticket-closing authority
  stays with the human, not the agent that fixed something.
