# RB-03 List-view convention: row actions, New, and bulk operations

> **Category:** Process & workflow · **Status:** DRAFT · **Last executed:** 2026-08-03 — built and verified against Tickets (curl + browser), Session 12

## Purpose

Every backend list view (Tickets today; Users, the eventual Requirements
module, and anything future) should look and behave the same way. This
is the confirmed shape, with `app/modules/backend/views/tickets/index.phtml`
+ `TicketsController::bulkAction()` as the reference implementation —
copy that pattern rather than re-deriving it per controller.

## Trigger

Building or reviewing any backend list (index) view.

## The convention

### Per-record row (where access permits)

Right-aligned button group, in this order: **View**, **Edit**, **Delete**.
Delete always goes through `SoftDeletes` (see CODING-STANDARDS.md's
Models section) — never a hard `DELETE`, and always a confirm dialog
(`onclick="return confirm(...)"`).

### Top-right: New

A single primary-styled button, top-right of the page header, linking to
the resource's `new` action. Matches Tickets' "New Ticket" button.

### Top-left: bulk operations

A `With selected` dropdown, positioned top-left above the table (below
any status-filter buttons, if the list has them):

- A checkbox column: header checkbox toggles every row (`bulk-select-all`
  id pattern), each row a `name="ticket_ids[]"`-style checkbox (name the
  array after the resource, e.g. `user_ids[]`).
- A live "N selected" counter next to the dropdown toggle, updated by a
  small inline `<script>` (see the reference implementation — no
  framework needed for this).
- Inside the dropdown: one `— No change —`-defaulted `<select>` per
  bulk-editable field (only fields that make sense applied identically
  across a batch — skip anything requiring a per-record target, like
  Tickets' consolidation merge-target), plus two submit buttons: **Apply
  to selected** (`bulk_action=update`) and **Delete selected**
  (`bulk_action=delete`, with its own confirm dialog).
- Fields left at `— No change —` (empty string) are not touched on the
  selected records — the controller only applies fields that were
  actually set, exactly like a partial PATCH.

### Implementation: one `<form>`, not nested forms

The whole table lives inside a single `<form method="post"
action=".../bulk">` so the checkboxes submit together with the dropdown's
fields. HTML forbids nesting `<form>` elements, so per-row single-record
actions (Delete) **cannot** be their own nested `<form>` anymore — use a
submit `<button>` with `formaction` pointing at the individual action
(e.g. `backend/tickets/delete/42`) instead. It still POSTs through the
same outer form (same CSRF field, already auto-injected — see below),
just to a different URL; the extra `ticket_ids[]`/dropdown fields that
tag along are harmless since the single-record action doesn't read
them.

View/Edit stay plain `<a href>` — they're GET navigations, unaffected by
being inside a `<form>`.

### CSRF

Nothing extra to do — `app/modules/backend/views/index.phtml`'s layout
JS already auto-injects the session's CSRF field into every `<form
method="post">` on the page, bulk form included. Don't hand-roll a
token field.

### Controller shape

```php
public function bulkAction()
{
    // 1. require POST, read ids array, 404/flash if empty
    // 2. bulk_action === 'delete' → softDelete() each, flash count, redirect
    // 3. otherwise: read each optional field, skip if still '' (no change),
    //    apply + save() each matched record, flash count, redirect
}
```

Status fields specifically: don't expose a raw status `<select>` if any
status value has side effects (Tickets' `closed_at`/`close_reason` on
close, cleared on reopen) — replicate the same side-effect logic the
single-record action already uses, and only offer the status values
that have a well-defined, batch-safe transition (Tickets: `closed` and
`open` only, not `consolidated`, which needs a single merge target).

## Verification

Confirmed working end-to-end against Tickets, Session 12: bulk field
update (type + severity across 3 records), bulk status close (with
`closed_at`/`close_reason` side effects applied correctly, and correctly
*not* applied to an unselected record), and bulk delete (soft-delete
across a batch) — all verified via direct POST requests and a real
browser click-through of the checkbox/dropdown UI.

## Changelog

| Date | Author | Change |
|---|---|---|
| 2026-08-03 | Travis Saron / Claude | Written and verified against Tickets, Session 12 |
