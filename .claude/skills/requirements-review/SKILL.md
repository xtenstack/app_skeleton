---
name: requirements-review
description: Step through open requirements in the production requirements module, highest priority first, and triage each one with the user. Use when the user asks to review/triage/step through open requirements, or invokes /requirements-review.
---

# Reviewing open production requirements

The production `/requirements` module (see `CLAUDE.md`) is the sole system of record for REQ-NNN entries. This skill is a periodic triage pass — not a bug hunt like `review-tickets`, and not the same thing as `session-wrapup`'s per-session logging. It exists so requirements that have gone stale, drifted, or quietly become irrelevant get caught, and so `hold`/priority/target_version stay meaningful instead of write-once fields nobody revisits.

## Use the API, not the backend UI, for edits made during this skill

The requirements module has its own JSON API (Session 16) at `/requirements/api/...` — `index`/`view`/`create`/`update`, same fields/validation as the backend UI, reached with `PROD_REQUIREMENTS_API_KEY`-style auth exactly like Tickets. Use it for every status/priority/notes/etc. change made during a review pass: an API-key-authenticated write records a null `actor_user_id` in the audit log (see `App_skeleton\Audit` — it only reads session state, never the API-key principal), distinguishable from the user's own manual edits, which always carry their real user id. Going through the backend UI as the logged-in human defeats this — don't do that for edits this skill makes, even though it's technically still possible.

## What "open" means for this step-through

`hold` and `complete` are both deliberately excluded — `hold` because the user parked it on purpose (that's the whole point of the status), `complete` because it's done. Step through in this order, one priority tier fully before the next:

1. `?status=open&priority=high` , then `?status=in_progress&priority=high`, then `?status=done_pending_changelog_decision&priority=high`
2. Same three statuses at `priority=normal`
3. Same three statuses at `priority=low`

(Priority is a plain VARCHAR column — don't rely on the list view's alphabetical sort to order tiers correctly; filter by `priority=` explicitly per tier instead, per the loop above.)

**Default scope (Session 18):** unless the user asks for a full pass, step through the top 5 only — priority order as above, within each tier rank requirements matching the current in-development target_version above those targeting future versions, then oldest-first (`created_at` ascending) as the final tiebreaker. Say up front which 5 you're covering and offer to continue if the user wants more.

## The workflow, per requirement

1. **Read title/description/notes/project/branch/target_version** — build a real picture of what this requirement actually is before judging it, not just its title.
2. **Ask: is this still accurate?** Check against current repo state (`git log`, does the branch still exist/get merged, does the described thing already exist in code) rather than trusting the requirement's own text as ground truth — requirements can go stale the same way tickets' reported symptoms can be wrong.
3. **Propose a disposition, don't just apply one**: still open and correct as-is / needs a status change / needs a priority change / needs `target_version` or `branch` filled in or corrected / should move to `hold` / looks actually complete and just never got marked. Say what you found and what you'd change — let the user confirm, same as `review-tickets`' "don't assume closing authority."
4. **The intent of this pass is to action reviewed requirements, not just re-confirm them (clarified Session 19).** "Still open and correct as-is" describes the finding, not the disposition to stop at — a requirement that checks out as accurate and buildable should actually get built (time/scope permitting, same real-code/real-verification standard as any other work in this repo), not left open with a note saying it's still valid. If something in the top N genuinely isn't ready to build (needs its own design pass first, too risky/large to rush, blocked on something else), say so explicitly and why, rather than silently deferring by treating "confirmed accurate" as if it were the finish line.
5. **Apply the confirmed change** via the edit form, then move to the next one.
5. **Report a running tally**, not just a final summary — per-tier counts (reviewed/changed/left as-is) as you go, so a long session can be checked on mid-way.

## Don't

- Don't silently reclassify a requirement's status/priority without confirming — these are the project's own record of what happened and why; a wrong silent edit corrupts history the same way a bad `git commit --amend` would.
- Don't touch `complete` requirements in this pass — they're out of scope by definition (see "What 'open' means" above). A requirement that looks wrongly marked complete is worth flagging to the user directly, not silently reopening.
- Don't skip low-priority tiers just because they're less interesting — work through all three tiers per status unless the user explicitly says to stop after high.
