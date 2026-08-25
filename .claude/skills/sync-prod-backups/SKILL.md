---
name: sync-prod-backups
description: Copy new database backups from the production droplet down to this Mac. Use at the start of a session, or whenever the user asks to sync/pull/copy prod backups down.
---

# Syncing prod database backups to this Mac

Production (`stack-internal.xten.au`, `134.199.163.98`) writes daily
Postgres dumps to `/opt/app_skeleton/backups/` on the droplet itself —
see `BackupTask` (`app/modules/cli/tasks/BackupTask.php`) and
`docs/user-guide.md`'s Backups section for how they get there. That
directory is the droplet's own local disk — losing the droplet loses
every backup on it, so this Mac is the off-droplet copy until real
off-instance storage (DigitalOcean Spaces or similar) exists.

## Running it

```bash
rsync -avz stack-prod:/opt/app_skeleton/backups/ ~/Developer/xten/prod-backups/
```

This relies on the `stack-prod` host defined in `~/.ssh/config`, which
carries the user and key — see stack `RB-12` for that setup. On a
machine without it, the long form still works:

```bash
rsync -avz -e "ssh -i ~/.ssh/xten_stack_internal" \
    root@stack-internal.xten.au:/opt/app_skeleton/backups/ \
    ~/Developer/xten/prod-backups/
```

`rsync` only transfers files that are new or changed — safe to run
every session regardless of whether anything's actually new. If SSH
fails outright, see `RB-02`/prior session notes: access can depend on
which network you're on (eduroam has blocked outbound SSH before).

## After syncing

Report what came down — new filenames and total count is enough, no
need to inspect contents unless something looks wrong (an
unexpectedly-small file size is the one thing worth flagging: a prior
session found this exact signal pointing at a real backup failure).
