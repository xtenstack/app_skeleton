---
description: Sanity checks before deploying to prod — clean tree, CI green, migrations reviewed
---

Run a pre-deploy sanity pass against this repo before pushing/deploying
to production. Check each of the following and report a clear go/no-go,
not just raw output:

1. **Working tree clean**: `git status --short` — flag anything
   uncommitted.
2. **`main` is up to date**: `git fetch origin && git log HEAD..origin/main --oneline`
   — flag if local is behind.
3. **CI is actually green**, not just present: `gh run list --repo xtenstack/app_skeleton --workflow=build.yml --limit 1 --json status,conclusion,headSha` for the current
   `HEAD`'s commit (or the latest run if it hasn't run for this exact
   commit yet) — a workflow file existing isn't the same claim as its
   last run passing (see `wrap-up-pa-2026-08-11.md` for why this
   distinction matters — it's exactly the gap that let a real CI break
   go unnoticed for 4 days).
4. **Pending DB migrations**: check `db/migrations/postgresql/` (and
   `xtenstack/internal`'s own modules' migrations, if relevant) for any
   file newer than what's likely already applied on the target —
   `docker compose exec -T app ./run migrate status` on the actual
   target droplet is the authoritative check if SSH access is
   available.
5. **Standing deploy authorization**: confirm from project memory/
   `CLAUDE.md` whether commit→push→deploy still doesn't need a
   per-instance ask (see the `feedback_standing_deploy_authorization`
   memory) — don't assume it's still active without checking, it's
   explicitly scoped to expire at first stable release.

Report a plain go/no-go with the specific blocker(s) if it's a no-go —
don't proceed with an actual deploy as part of running this command,
that's a separate step.
