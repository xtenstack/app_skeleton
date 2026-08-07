# Adding private/internal modules

The base `app_skeleton` repo ships with no internal-only modules —
anything that shouldn't be part of the public release (a customer's
proprietary feature, an operator-only admin tool, etc.) lives in a
**separate, private repo** and gets pulled in locally, per-instance,
without the public repo ever hard-requiring it.

This is how XTen's own `requirements-module` is distributed: it lives in
a private `InternalModules` repo, one top-level folder per module name,
and never appears in this repo's git history or `composer.json`.

## Mechanism

1. A gitignored `composer.local.json` at the repo root, merged into the
   real `composer.json` at install time by
   [`wikimedia/composer-merge-plugin`](https://github.com/wikimedia/composer-merge-plugin)
   (already a base dependency — it's a no-op if `composer.local.json`
   doesn't exist, so a fresh clone with no private modules works exactly
   as before).
2. `composer.local.json.example` in this repo shows the shape: a `path`
   repository glob pointing at a sibling checkout of your private
   modules repo, plus a `require` entry per module you actually want
   installed on this instance.
3. `ModuleManager` (`app/common/library/ModuleManager.php`) discovers
   any installed Composer package with a `module.json` manifest,
   regardless of where Composer physically put it — a private module
   installed this way is indistinguishable from a normal dependency once
   `composer install` has run. Enable/disable it from the admin
   Configuration page like any other optional module.

`composer.local.json.example`'s path repository uses `"symlink": false`
deliberately — Composer copies the module's files into `vendor/` instead
of symlinking them. The Docker build's runtime stage only copies
`vendor/` forward from the vendor-build stage, not the sibling
`InternalModules` checkout itself, so a symlink would dangle in the
final image. The tradeoff: local edits to a module in your sibling
checkout need `composer install` re-run to show up, rather than being
picked up live.

## Setup

```bash
# Sibling checkout, next to this repo (not inside it)
git clone git@github.com:<you>/InternalModules.git ../InternalModules

cp composer.local.json.example composer.local.json
# edit composer.local.json: list only the modules this instance actually needs

composer install
./run modules sync
```

`composer.local.json` is never committed — each instance (dev droplet,
prod droplet, a teammate's laptop) maintains its own copy, listing only
the private modules it actually needs. A fresh clone of this repo with
no `composer.local.json` behaves identically to the public release.

## Why not a git submodule / vendored copy in this repo

A submodule or an in-tree private module folder both mean the private
code's presence (even empty/disabled) is visible in this repo's own git
history, and both make "give someone a copy of just `app_skeleton`"
harder to do cleanly. Keeping private modules in a wholly separate repo,
pulled in only via each instance's own untracked `composer.local.json`,
keeps the public repo genuinely public — no private code, no private
repo names, nothing to scrub before sharing it.
