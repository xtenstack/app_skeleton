---
name: check-maama-register
description: Check the MAAMA Handover Register for anything relevant to incorporate into this session. Use at the very start of a session, before starting real work — regardless of which agent is running (Claude Code, Claude Cowork, GitHub Copilot, Gemini, etc.), since the register is a cross-agent index, not project-specific.
---

# Checking the MAAMA Handover Register

MAAMA (the shared cross-agent handover layer across the XTen ecosystem)
exists to solve one problem: a handover created by one agent/session
getting lost because the next agent/session never knew to look for it.
This skill is the "look for it" half — see `add-new-maama-handovers`
for the "create it" half.

Full mechanics: `OneDrive(XTen)/Entities/xten.au/MAAMA/MAAMA-Handover-Register-Specification.md`.

## What to do

1. Read `OneDrive(XTen)/Entities/xten.au/MAAMA/MAAMA Handover Register.md`
   in full — it's meant to be short (Section 4 of the spec: "short,
   manually readable"), so this should be quick.
2. Filter for rows relevant to *this* session: `Target` matching this
   agent/project (or generic enough to apply — e.g. "Claude", "Claude
   Code", or unaddressed), and `Status` of `Open` or `In Progress`.
   `Done`/`Closed`/`Superseded` rows are read-only history, not
   actionable.
3. For each relevant row, read its linked `Detail File` (the register
   row itself is intentionally too short to act on — Section 4's "linked
   to detailed handover files rather than duplicating them").
4. Surface what you found to the user plainly before starting the
   session's own work — don't silently fold it in. Something like:
   "The MAAMA register has an open handover from \<source\> about
   \<subject\> (\<Handover ID\>) — want me to fold this into this
   session, or leave it for its actual target?" Not every open row
   belongs in *this* session even if it's technically relevant; the
   user decides whether to pull it in now or leave it queued.
5. If the user agrees to work on it this session, treat it like any
   other agreed-scope item — and update its status/Update Trail via
   `add-new-maama-handovers` at session end, same as a newly-created
   one.

## Don't

- Don't skip this because the register looks empty or stale — an empty
  check is still useful information (confirms nothing's waiting), not
  wasted effort.
- Don't silently action an open handover without telling the user —
  Section 8 of the spec makes "Open" mean "not yet accepted," and
  accepting it is a decision, not an assumption.
- Don't edit the register or a detail file from this skill — that's
  `add-new-maama-handovers`'s job, kept separate so "check" stays
  read-only and safe to run reflexively at the top of every session.
