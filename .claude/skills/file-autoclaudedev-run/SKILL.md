---
name: file-autoclaudedev-run
description: File an AutoClaudeDev run's task prompt and log into the versioned Task Prompts archive after it's been reviewed (accepted or rejected). Use once a session finishes reviewing an unattended AutoClaudeDev run's output.
---

# Filing an AutoClaudeDev run

`stack.xten.au/AutoClaudeDev/Task Prompts/` is the versioned archive of
every task prompt handed to an unattended AutoClaudeDev run, plus that
run's own log — kept even for failed/rejected runs, not just accepted
ones, since a failed run's log is exactly what explains *why* it
failed to whoever picks the module back up later.

## When to run this

Right after a session finishes reviewing an AutoClaudeDev run's
output — merged, rejected, or reworked — not before. The point is to
capture what actually happened, not the prompt in isolation.

## Steps

1. **File the prompt.** If this run used a fresh or reworked prompt not
   already in the archive, save it as
   `<module-or-feature>-V<N>.<ext>` — `V0` for the first version,
   incrementing each time the prompt itself had to be reworked and
   relaunched (not each time the *code* changed — that's normal
   iteration, not a prompt rework).
2. **File the log.** Copy (don't leave the only copy on the droplet)
   the run's own log to
   `<module-or-feature>-run-log-YYYY-MM-DD.log`, dated to when the run
   happened. If the log lived somewhere non-obvious (a droplet's `/root`,
   a CI artifact), remove it from there afterward only once the copy is
   confirmed — don't leave a stray copy behind indefinitely, but don't
   delete-then-verify either.
3. **Log the outcome in the production `/requirements` module** (the
   system of record since Session 15 — `Requirements-List.md` is
   archived, don't write to it) if this run corresponds to a real
   `REQ-NNN` — cross-reference the filed prompt/log path in `notes` so
   a future session doesn't have to search for them.
4. **If the run used `xtenstack/internal`'s `docs/autoclaudedev/`
   scratch folder** (see that repo's README) **and the run succeeded**,
   clear it now — delete everything under `docs/autoclaudedev/`,
   commit, push. Leave it alone for a failed/rejected run; only a
   successful, accepted run's temp docs get cleared.

## Don't

- Don't skip filing a rejected/failed run — its log is often the most
  useful artifact in the archive (see `requirements-module-run-log-*`'s
  own history: the *first* AutoClaudeDev attempt failed cleanly and its
  log was the only way to root-cause why).
- Don't overwrite an existing `-V<N>` file — always increment.
