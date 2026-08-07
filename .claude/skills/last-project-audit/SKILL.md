---
name: last-project-audit
description: Review the most recent Project Audit Report against current repo state and write its wrap-up doc, if one doesn't already exist. Use when the user asks to review/check/follow up on the last project audit, or invokes /last-project-audit.
---

# Reviewing the last Project Audit Report

Project Audit Reports (`project-audit` skill's output) live at
`stack.xten.au/docs/Project-Audit-Report-YYYY-MM-DD.md` — private, not
in this repo. Each one's Fix Kit is a punch list, not something that
gets auto-applied; this skill is the "did we actually do any of that"
follow-up pass, not a build step.

## Steps

1. **Find the latest report.** List
   `stack.xten.au/docs/Project-Audit-Report-*.md`, take the newest date.
2. **Check for an existing wrap-up.** If
   `stack.xten.au/docs/wrap-up-pa-<that-date>.md` already exists, this
   audit has already been reviewed — say so and stop, don't redo it.
   (This is the condition the user's own trigger names: "provided there
   is no `wrap-up-pa-YYYY-MM-DD.md`.")
3. **If none exists, review the Fix Kit against real current state** —
   grep/read the actual codebase for each Tier 1/2/3 item, don't assume
   from memory of what a prior session did. Cross-reference
   `Requirements-List.md` for anything that turns out to already be
   covered by a REQ logged since the audit ran (this has happened
   before — an audit's Tier 3 item turned out to already be resolved by
   unrelated same-session work).
4. **Write `wrap-up-pa-<date>.md`** in `stack.xten.au/docs/`, same shape
   as any prior one (see `wrap-up-pa-2026-08-04.md` for the reference
   format): one section per tier, each finding marked done/not
   done/deferred with enough detail to not require re-reading the
   original report, a "Found along the way" section for anything
   discovered outside the original report's own findings, honest about
   what's genuinely still open rather than padded to look more complete
   than it is.
5. **Report back to the user** — what's done, what's still open, and
   let *them* decide whether to action any remaining Fix Kit items now
   or defer them. Don't start building Tier 1/2 items unprompted just
   because they're listed — that's a scope decision for the user, not
   an automatic follow-on from reviewing the report.

## Don't

- Don't write the wrap-up doc into this repo's public `docs/` folder —
  it's a private session-narrative document, same rule as
  `session-wrapup`'s Requirements-List/Summary/Handover trio.
- Don't claim a Fix Kit item is done without checking the real repo
  state for it (grep, read the actual file) — several past audit
  findings turned out to be false positives/negatives from the
  original audit's own checks; don't compound that by trusting a
  claim without re-verifying.
