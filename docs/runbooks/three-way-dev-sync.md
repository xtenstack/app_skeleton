# Runbook: Keeping local / dev droplet / GitHub in sync

**Status: stub, raised Session 11.** This project currently has three
places code can exist in a different state at once — the user's local
machine, the Internal Dev droplet (`168.144.166.113`, runs AutoClaudeDev
unattended, see `REQ-007`), and GitHub (`main`, source of truth). This
runbook exists so all three are known-current before starting
unattended work, reducing stale-context runs. Not yet automated — this
is the manual sequence until it is.

## Before launching an AutoClaudeDev run on the dev droplet

1. **Local → GitHub**: make sure anything you want the dev droplet to
   see is actually pushed to `main` first. AutoClaudeDev works from
   whatever's on the droplet's checkout, not your local working copy.
2. **Dev droplet → GitHub**: SSH in and refresh the droplet's checkout
   before kicking off a run:
   ```bash
   ssh -i ~/.ssh/<dev-droplet-key> root@168.144.166.113
   cd /opt/dev-sandbox/workspace
   git fetch origin
   git status   # confirm no uncommitted local changes on the droplet first
   git checkout main
   git pull origin main
   ```
   If the droplet has uncommitted changes from a prior run that never
   got pushed, resolve that first (push them, or stash/discard
   deliberately) — don't silently overwrite in-progress work.
3. **Launch the run** against this now-current `main`.

## After an AutoClaudeDev run finishes

1. Review the run's changes on the droplet (or its feature branch) before
   merging — same standard as any other PR, unattended or not.
2. Merge/push to `main` once reviewed.
3. **GitHub → local**: pull `main` locally before starting your own next
   session, so you're not reviewing against stale context either.

## Known gap

This is still a fully manual checklist — REQ-007's remaining scope
(an actual task-queue/notification loop) would be the natural place to
automate step 1–2 above (refresh-before-run) rather than relying on
remembering to do it by hand every time.
