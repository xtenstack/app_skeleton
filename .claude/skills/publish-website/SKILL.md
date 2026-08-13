---
name: publish-website
description: Diff the local website/ and website_deploy/ directories against what's actually live via FTP, and upload any changed files after explicit confirmation. Use at session end if website files were touched, or whenever the user asks to publish/deploy/upload the website.
---

# Publishing the marketing website (stack.xten.au / deploy.xten.au)

Two static sites, two local directories, one shared FTP account
credential-gated to exactly these two remote paths — see
`~/.config/xten/api-keys.env`'s `WEBSITE_FTP_*` vars. **Never touch any
other directory on this FTP account** — the user has explicitly named
other directories on it off-limits without discussing first.

| Local directory              | Remote path (env var)              | Site            |
| ----------------------------- | ----------------------------------- | --------------- |
| `website/`                    | `WEBSITE_STACK_DIR` (`/stack.xten.au/`)   | stack.xten.au   |
| `website_deploy/`             | `WEBSITE_DEPLOY_DIR` (`/deploy.xten.au/`) | deploy.xten.au  |

## Known environment quirks

- **TLS is broken server-side** on this FTP account — explicit FTPS
  (`--ssl-reqd`/`--ftp-ssl`) gets a 504 from the actual Pure-FTPd server
  despite being advertised in the cPanel FTP Accounts screen. Use
  **plain FTP** (`ftp://`, no TLS flags) — the user's own explicit call
  ("Just use plain for now"), not a shortcut taken unilaterally. If the
  user says the host has fixed it, switch back to `--ssl-reqd` and
  re-verify before trusting it.
- No `lftp`/`ncftp` on this Mac — plain `curl` only.
- Never print the FTP password. Source `~/.config/xten/api-keys.env`
  into the shell and reference the variable, never the literal value.

## Running it

1. **Scope the check to what's plausibly changed.** `git status`/
   `git log` against `website/` and `website_deploy/` for anything
   touched this session (these two directories aren't necessarily
   committed on the same rhythm as the app code, so also just recall
   what you edited this session — don't rely on git alone).

2. **For each candidate file, fetch the live copy and diff** before
   assuming it needs uploading:
   ```bash
   bash -c '
   set -a; source ~/.config/xten/api-keys.env; set +a
   curl -sS --user "${WEBSITE_FTP_USERNAME}:${WEBSITE_FTP_PASS}" \
     "ftp://${WEBSITE_FTP_SERVER}:${WEBSITE_EXPLICIT_FTPS_PORT}${WEBSITE_STACK_DIR}index.html" \
     -o /tmp/live-index.html 2>&1
   diff /opt/local/www/apache2/html/website/index.html /tmp/live-index.html
   '
   ```
   (swap in `WEBSITE_DEPLOY_DIR` and the matching local path for
   `website_deploy/` files.) Skip binary assets (images, the `.zip`)
   from a byte diff unless you have a specific reason to think they
   changed — a directory listing (`--list-only`) is enough to confirm
   they're still present.

3. **Report the diff plainly** — which files differ and a short
   description of the change, not a raw diff dump unless the user asks
   to see it. Publishing is a **modify-public-content action**: always
   ask for explicit confirmation before the actual upload, every
   session, even though the user has pre-approved the *mechanism* —
   approval of the skill isn't standing approval of any given upload.

4. **On confirmation, upload only the files that actually differ**:
   ```bash
   bash -c '
   set -a; source ~/.config/xten/api-keys.env; set +a
   curl -sS -T "/opt/local/www/apache2/html/website/index.html" \
     --user "${WEBSITE_FTP_USERNAME}:${WEBSITE_FTP_PASS}" \
     "ftp://${WEBSITE_FTP_SERVER}:${WEBSITE_EXPLICIT_FTPS_PORT}${WEBSITE_STACK_DIR}index.html" \
     -w "result: %{http_code}\n"
   '
   ```

5. **Verify live** after uploading — `curl -sk -o /dev/null -w "%{http_code}\n" https://<site>/<path>`
   for each uploaded file, and spot-check the actual content changed
   (grep for the new text, not just a 200).

## When to run this

- At the end of a session, **only if** `website/` or `website_deploy/`
  were actually touched that session — don't run as a matter of
  routine, and say so plainly ("no website changes this session,
  skipping publish") rather than silently doing nothing.
- Whenever the user explicitly asks to publish/deploy/upload the
  website.

## Don't

- Don't touch any FTP directory other than the two named above.
- Don't upload unconditionally — always diff first, always get explicit
  confirmation before the actual `-T` upload, every time.
- Don't switch to `--ssl-reqd`/`--ftp-ssl` without the user confirming
  the host-side TLS issue is actually fixed.
