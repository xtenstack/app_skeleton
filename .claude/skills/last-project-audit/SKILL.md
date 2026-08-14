---
name: last-project-audit
description: Review the most recent Project Audit Report against current repo state and write its wrap-up doc, if one doesn't already exist. Use when the user asks to review/check/follow up on the last project audit, or invokes /last-project-audit.
---

# Reviewing the last Project Audit Report

Project Audit Reports (`project-audit` skill's output) live at
`stack.xten.au/docs/Project-Audit-Report-YYYY-MM-DD-HHMM.md` — private,
not in this repo. The `HHMM` suffix (added after this project got
audited twice in one day) means there can be more than one report on
the same calendar date — always match a wrap-up to a report by its
*full* stamp, not just the date. Each report's Fix Kit is a punch list,
not something that gets auto-applied; this skill is the "did we
actually do any of that" follow-up pass, not a build step.

**Note (Session 17):** the session-wrapup skills' `Handover-*` filenames
picked up a trailing timezone abbreviation (e.g. `-AWST`) alongside
`HHMM`, for the same reason `HHMM` itself was added — sessions now run
more than once a day. `project-audit` (the global skill that generates
these reports, `~/.claude/skills/project-audit`, not in this repo)
hasn't been updated to match yet — if a report shows up with a `-TZ`
suffix, that's expected and the same matching rule applies; if it still
uses the old `HHMM`-only stamp, that's not a bug, just not yet
reconciled.

## Steps

1. **Find the latest report.** List
   `stack.xten.au/docs/Project-Audit-Report-*.md`, take the one that
   sorts last (the `YYYY-MM-DD-HHMM` stamp sorts chronologically as a
   plain string, so the last filename alphabetically is the most
   recent report — no date parsing needed).
2. **Check for an existing wrap-up.** If
   `stack.xten.au/docs/wrap-up-pa-<that report's full stamp>.md`
   already exists, this exact report has already been reviewed — say so
   and stop, don't redo it. (This is the condition the user's own
   trigger names: "provided there is no `wrap-up-pa-<stamp>.md`" — a
   same-day *earlier* report having a wrap-up doesn't count, since it's
   a different report than the latest one found in step 1.)
3. **If none exists, review the Fix Kit against real current state** —
   grep/read the actual codebase for each Tier 1/2/3 item, don't assume
   from memory of what a prior session did. Cross-reference the
   production `/requirements` module (the system of record since
   Session 15 — `Requirements-List.md` is archived, don't consult it)
   for anything that turns out to already be covered by a REQ logged
   since the audit ran (this has happened before — an audit's Tier 3
   item turned out to already be resolved by unrelated same-session
   work).
4. **Write `wrap-up-pa-<report's full stamp>.md`** in
   `stack.xten.au/docs/`, same shape as any prior one (see
   `wrap-up-pa-2026-08-04.md` for the reference format — pre-dates the
   `HHMM` convention, still a valid shape reference): one section per
   tier, each finding marked done/not
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
  `session-wrapup`'s Summary/Handover pair.
- Don't claim a Fix Kit item is done without checking the real repo
  state for it (grep, read the actual file) — several past audit
  findings turned out to be false positives/negatives from the
  original audit's own checks; don't compound that by trusting a
  claim without re-verifying.
