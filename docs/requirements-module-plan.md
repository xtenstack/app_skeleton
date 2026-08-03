# Requirements Module — Implementation Plan

## Context

Every session against this repo (and any other XTen.Stack project)
currently tracks requirements in a single flat `Requirements-List.md`
file living outside the repo, in the private session-docs folder. That
works, but it's manual, has no queryable status, and the "does this
belong in the changelog" decision the user described (Session 12) has
no structured home:

> We generate them during our sessions, then as they are implemented or
> done, each [is] reviewed and a decision made if to include in the
> ChangeLog for the release. Either way, at this stage the entry would
> be updated to Complete — meaning it has been added to the change log
> for the release, or a deliberate decision has been made to finalise it
> without adding it to the changelog.

This plan turns that workflow into a real feature: a `requirements`
module tracking `REQ-NNN` entries with status, a changelog-inclusion
decision, and (eventually) a generated `CHANGELOG.md` per release.

## Design decisions

**1. A real optional module, not another built-in engine feature.**
Ticketing (see `ticketing-module-plan.md`) was deliberately built
built-in rather than through `ModuleManager`/`module.json`, because at
the time the module system's own design (routes, event bus,
nav-switching) was still unsettled and shouldn't be guessed at from
nothing. That's no longer true — `ModuleManager` v1 is done, proven with
a real Composer-package test module, and in production use
(`./run modules sync|list|enable|disable`, the Configuration page
toggle). Requirement-tracking is also a better fit for "optional" than
Ticketing ever was: not every XTen.Stack deployment needs a requirements
log, whereas every business fields support requests. This module is
this project's first real validation of the module system end-to-end —
also a good worked example for `docs/guide-to-xten-stack-modules.md`.

**2. ID format: `REQ-NNN`, unpadded past 3 digits.** Matches the
existing convention (`Requirements-List.md`'s own numbering spec) with
the "just keep going — 1000, 1001" extension the user asked for, without
a format change at the boundary: generate with `sprintf('REQ-%03d', $n)`
— zero-pads up to 3 digits (`REQ-001`...`REQ-999`) and naturally stops
padding once the number itself is wider (`REQ-1000`, `REQ-1547`). IDs
are never reused, even if a requirement is later dropped — matches the
existing file's own rule.

**3. Status lifecycle**, mapped directly from the user's description:

```
open → in_progress → done_pending_changelog_decision → complete
                                                        ↳ (either included in
                                                           a changelog entry,
                                                           or explicitly
                                                           finalized without one)
```

- `open` — logged, not started.
- `in_progress` — actively being worked.
- `done_pending_changelog_decision` — implemented/resolved, awaiting the
  human review-and-decide step.
- `complete` — the terminal state either way; `changelog_decision`
  (enum: `included` / `excluded_deliberately`) records which, and
  `changelog_id` links to the generated changelog entry when
  `included`. A requirement can also move straight to `complete` with
  `changelog_decision = excluded_deliberately` for things closed without
  ever being "built" (decided against, superseded, duplicate).

**4. Changelog is generated, not hand-written.** A `changelogs` table
represents one release's changelog; `requirements.changelog_id` links
included requirements to it. A backend action renders/exports the
changelog (Markdown, matching this project's own `CHANGELOG.md`-style
output) from whichever requirements are linked to it — no separate
free-text changelog editor, the requirement entries' own summaries are
the source text (with an optional per-link `changelog_note` override for
release-facing wording that differs from the internal requirement
description).

**5. Not a replacement for session docs.** `Session-NN-Summary.md` and
`Handover-YYYY-MM-DD.md` stay exactly as they are (private,
session-narrative documents) — this module only replaces the
`Requirements-List.md` *table*, not the narrative write-ups. A session's
Claude Code workflow becomes: log/update REQ entries in this module
during the session (via its API or the backend UI) instead of editing
the markdown table by hand; the Summary/Handover docs still get written
the same way they do today.

## 1. Module layout

```
requirements-module/                  (separate Composer package, path-repo during dev)
  module.json
  src/
    controllers/
      RequirementsController.php      # backend UI
      ChangelogsController.php        # backend UI
    models/
      Requirement.php
      Changelog.php
    Module.php
  migrations/
    postgresql/
      001_requirements.sql
  views/
    requirements/{index,view,new,edit}.phtml
    changelogs/{index,view}.phtml
```

Matches the existing Composer-package module shape (`module.json`
manifest, own `migrations/<adapter>/` applied by `./run migrate run` per
the module-aware migration runner, own autoloaded controllers/models via
`Module.php::registerAutoloaders()`), same pattern the module-system
design brief and the throwaway test module already validated.

## 2. Database migration — `migrations/postgresql/001_requirements.sql`

```sql
-- One row per REQ-NNN entry. display_id is the generated "REQ-042"
-- string (see design decision 2) — stored, not computed on read, so
-- historical ids never shift even if generation logic changes later.
-- origin_session is free text (e.g. "Session 12") matching the existing
-- Requirements-List.md "Originated" column.
CREATE TABLE requirements (
    id                  SERIAL PRIMARY KEY,
    display_id          VARCHAR(20) NOT NULL UNIQUE,
    title               VARCHAR(200) NOT NULL,
    description         TEXT,
    status              VARCHAR(40) NOT NULL DEFAULT 'open',
    changelog_decision  VARCHAR(30),
    changelog_id        INTEGER,
    changelog_note      TEXT,
    origin_session       VARCHAR(100),
    deleted_at          TIMESTAMP,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (changelog_id) REFERENCES changelogs(id)
);

-- One row per release's changelog. Requirements link into this via
-- requirements.changelog_id once their changelog_decision is 'included'.
CREATE TABLE changelogs (
    id           SERIAL PRIMARY KEY,
    version       VARCHAR(50) NOT NULL UNIQUE,
    released_at   TIMESTAMP,
    deleted_at    TIMESTAMP,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

(`changelogs` created first in the actual migration file — order above
is for readability; `requirements.changelog_id`'s FK needs `changelogs`
to exist already.)

## 3. Models

`Requirement.php` / `Changelog.php` — plain properties matching the
schema, `use SoftDeletes;`, `keepSnapshots(true)` (status/changelog
changes belong in the audit log, same as Tickets), `beforeSave()` bumps
`updated_at`, `Requirement::belongsTo('changelog_id', Changelog::class,
'id', ['alias' => 'Changelog'])`.

## 4. Backend UI — `RequirementsController.php`

```php
indexAction()    // list, ?status= filter, follows the standard list-view
                 // convention (docs/runbooks/RB-03-list-view-conventions.md):
                 // View/Edit/Delete per row, New button top-right, bulk
                 // "with selected" status-update top-left (see Session 12's
                 // Tickets bulk-ops work, same pattern reused here)
newAction() / createAction()
editAction($id) / updateAction($id)   // status transitions happen here —
                                       // a status dropdown, not separate
                                       // per-transition actions (this
                                       // entity doesn't need Tickets'
                                       // richer assign/consolidate/QA flow)
viewAction($id)
deleteAction($id)                     // soft delete
```

`ChangelogsController.php`:

```php
indexAction()
newAction() / createAction()          // version + released_at
viewAction($id)                       // shows every linked requirement,
                                       // an "Add requirements" picker
                                       // (only status=done_pending_changelog_decision
                                       // ones), and a rendered Markdown
                                       // preview
exportAction($id)                     // downloads the rendered Markdown
```

Markdown rendering: one line per linked requirement, `changelog_note` if
set else `title`, grouped by nothing fancy — a flat list matches this
project's own `CHANGELOG.md`-style conventions closely enough not to
need a taxonomy (feature/fix/docs) in v1.

## 5. Menu entry

```php
['label' => 'Requirements', 'icon' => 'fas fa-list-check', 'controller' => 'requirements', 'url' => 'requirements', 'roles' => /* admin + operator */],
```

Module-provided menu entries merge via `ModuleManager::mergedMenu()`
(existing mechanism, no new work needed here).

## 6. What this plan explicitly does not do

- Does not migrate `Requirements-List.md`'s existing ~40 entries
  automatically — a one-off import script is a reasonable follow-up once
  this is built, not part of the plan itself.
- Does not touch how `Session-NN-Summary.md`/`Handover-*.md` are
  written — narrative session docs stay markdown, by hand.
- Does not build a taxonomy (feature/fix/breaking) on changelog entries
  — flat list only, v1.
- Does not attempt automatic REQ-id extraction from commit messages —
  `feature/<REQ-id>-short-name` branch naming (RB-01) is a human/PR-time
  cross-reference, not a mined one, in this plan.

## Verification

1. `composer require` the module locally (path repository during dev),
   `./run modules sync`, confirm it appears in the Configuration page
   and its migration applies (`./run migrate run`).
2. Create a requirement via the backend UI, move it through
   `open → in_progress → done_pending_changelog_decision → complete`,
   confirm each transition is captured in the audit log.
3. Create a changelog, link two `done_pending_changelog_decision`
   requirements to it, confirm `exportAction` produces a Markdown file
   matching this project's own `CHANGELOG.md` formatting conventions.
4. Confirm a requirement can also go straight to `complete` with
   `changelog_decision = excluded_deliberately` and never appears in any
   changelog's picker.
5. Disable the module (`./run modules disable requirements`), confirm
   the admin UI (including the Configuration page itself) is unaffected
   — proves this really is an optional module, not something core
   quietly depends on.
