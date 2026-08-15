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

## What "WCAG 2.1 AA certification" actually is (Session 17)

There's no single global certifying body or seal for WCAG — "AA
certified" in practice means one of two things, and they're not
equivalent:

- **A self-published conformance statement**, usually a **VPAT**
  (Voluntary Product Accessibility Template — the standard US
  government/enterprise-procurement format) or an informal "Accessibility
  Conformance Report." You write it yourself, based on your own testing
  against all 50 WCAG 2.1 AA success criteria. This is what most open
  source and small commercial products actually have, if they have
  anything.
- **A third-party audit**, done by an accessibility firm (deque,
  Level Access, TPGi, and similar) that tests against every AA success
  criterion with real assistive tech and issues a report under their
  name. This is what "certified" tends to imply when a client asks for
  it specifically, and it costs real money — not something to plan for
  until there's a client/deal that actually requires it.

Either path requires the full pass this document already says hasn't
happened yet (keyboard-only walkthrough, screen reader spot-check, all
50 criteria — not just the auth-flow/shared-component pass done so
far). Worth deciding which one (if either) is actually the goal before
investing in the full pass — a self-published VPAT is realistic for
this project pre-release; a paid third-party audit is a "when a real
client needs it" decision, not a base-product one.

## What a self-published VPAT actually takes (Session 18)

Concretely, not just conceptually — the steps to produce one:

1. **Get the template.** The ITI (Information Technology Industry
   Council) publishes the standard VPAT template, currently the
   VPAT 2.5 edition — the "WCAG 2.x" edition variant is the one that
   maps directly to WCAG 2.1 AA (there are also Section 508/EN 301 549
   editions covering overlapping but not identical criteria; not
   needed unless a client specifically asks for one). Free download,
   no cost to obtain the template itself.
2. **Run the full pass**, not the partial one this doc already
   describes: keyboard-only walkthrough of every screen (not just
   auth), a real screen reader spot-check (VoiceOver on macOS is the
   zero-install option), and a 200%-zoom/narrow-viewport check —
   exactly the three checks this document has flagged as outstanding
   since it was first written.
3. **Score every one of the 50 WCAG 2.1 A/AA success criteria**
   against what the pass actually found, using the VPAT's own
   four-way scale: *Supports*, *Partially Supports*, *Does Not
   Support*, *Not Applicable* — each with a one-line remark citing the
   real evidence (a screen name, a component, a specific gap), not a
   blanket "supports" claim.
4. **Fix or explicitly accept known gaps before publishing** — a VPAT
   that admits "Partially Supports, see remark" for a real known issue
   is normal and expected; a VPAT that quietly omits a known issue is
   not.
5. **Publish it** — `docs/VPAT.md` (or a linked PDF, the ITI template's
   native format) alongside this file, referenced from the README docs
   list the same way this file is.
6. **Re-run and re-date it** after any UI change substantial enough to
   plausibly affect conformance — a VPAT is a dated snapshot, not a
   one-time certificate; stale ones are worse than none since they
   misrepresent current state.

None of this is started yet — this section exists so "let's do a VPAT"
has a concrete checklist to work from when the time's actually spent,
not to imply it's already underway.
