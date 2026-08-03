# RB-02 Keep local / dev droplet / GitHub in sync before unattended work

> **Category:** Environment · **Status:** DRAFT · **Last executed:** n/a — manual checklist, not yet automated

## Purpose

This project has three places code can exist in a different state at
once — the user's local machine, the Internal Dev droplet
(`168.144.166.113`, runs AutoClaudeDev unattended, see REQ-007), and
GitHub (`main`, source of truth). This runbook keeps all three
known-current before starting unattended work, reducing stale-context
runs.

## Trigger

About to launch an AutoClaudeDev run on the dev droplet.

## Prerequisites

SSH access to the dev droplet (`~/.ssh/<dev-droplet-key>`).

## Steps

### Before launching a run

1. **Local → GitHub**: push anything you want the dev droplet to see to
   `main` first. AutoClaudeDev works from whatever's on the droplet's
   checkout, not your local working copy.
2. **Dev droplet → GitHub**: SSH in and refresh the droplet's checkout:
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
3. Launch the run against this now-current `main`.

### After a run finishes

1. Review the run's changes on the droplet (or its feature branch)
   before merging — same standard as any other PR, unattended or not.
2. Merge/push to `main` once reviewed.
3. **GitHub → local**: pull `main` locally before starting your own next
   session, so you're not reviewing against stale context either.

## Verification

`git status` clean and `git log -1` matching on all three (local, dev
droplet, GitHub `main`) before a run starts.

## Known gap

Still a fully manual checklist — REQ-007's remaining scope (an actual
task-queue/notification loop) is the natural place to automate the
refresh-before-run step rather than relying on remembering to do it by
hand every time.

## Changelog

| Date | Author | Change |
|---|---|---|
| 2026-08-02 | Travis Saron / Claude | Stub written, Session 11 |
| 2026-08-03 | Travis Saron / Claude | Renumbered into the RB-NN scheme (was `three-way-dev-sync.md`) |
