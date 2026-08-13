# Accessibility

app_skeleton aims for **WCAG 2.1 AA where feasible** — stated plainly
here rather than left implicit, per [Open Source Guides' accessibility
best practices](https://opensource.guide/accessibility-best-practices-for-your-project/).
This is a goal to work toward, not a certification already held: parts
of the UI (server-rendered AdminLTE, a third-party Bootstrap 4 admin
theme) predate this document, and the real state is closer to "no known
blockers found in a pass through the auth flows and shared components"
than "fully audited."

## What's already true, checked directly (not assumed)

- Every `<html>` document declares `lang="en"`.
- No focus outlines are removed anywhere in the codebase (`outline:
  none`/`outline: 0` — grepped, zero matches).
- No `<img>` tag ships without an `alt` attribute.
- Status/severity indicators (ticket severity, requirement priority,
  audit-log actions, etc.) pair color with a real text label — never
  color alone.
- Form controls generally use real `<label for="...">` elements
  associated by `id`, not placeholder-only text — the one real gap
  found (see below) has been fixed.

## Found and fixed

The four AdminLTE auth screens (`backend/session` login,
`backend/signup`, `backend/password/forgot`,
`backend/password/reset`) used placeholder-only inputs with no
associated `<label>`, plus decorative Font Awesome icons with no
`aria-hidden` — a real gap: placeholder text isn't a reliable label
substitute (it disappears on input and isn't consistently announced by
assistive tech), and unhidden decorative icons add screen-reader noise.
Fixed: each input now has a `sr-only` (visually-hidden, matches this
codebase's Bootstrap 4) label matching its placeholder text, and the
icon `<span>`s are `aria-hidden="true"`. Verified live via the real
accessibility tree, not just by reading the diff.

## Reporting an accessibility issue

Open a GitHub issue tagged `accessibility` (or email `stack@xten.au` if
you'd rather not file publicly). Include what you were trying to do,
what assistive technology/browser/OS you were using, and what happened
instead of what you expected. Treated with the same severity taxonomy
as any other bug — see `CONTRIBUTING.md`.

## For contributors

- Every new/changed form control needs a real `<label>`, not a
  placeholder standing in for one.
- Every new interactive element needs to be keyboard-reachable and
  operable — tab to it, activate it with Enter/Space, no mouse-only
  affordances.
- Don't remove a focus outline without providing an equally visible
  replacement.
- Don't convey meaning through color alone — pair it with text, an
  icon with an accessible name, or both.
- Decorative icons/images get `aria-hidden="true"` (icon fonts) or
  `alt=""` (`<img>`); meaningful ones get a real, specific `alt`.
- See the PR template's own Accessibility checklist — it's the same
  list, applied per-change.

This document itself is a starting point, not a finished audit — a
full pass (keyboard-only walkthrough, screen reader spot-check, 200%
zoom/narrow-viewport check) is worth doing deliberately at some point,
not assumed complete because this file exists.
