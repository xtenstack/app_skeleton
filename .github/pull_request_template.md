## What this changes

A short description of the change and why (not just what).

## Related issue

Closes #

## How this was tested

- [ ] Ran it locally and exercised the actual change (not just `php -l`
      or reading the diff)
- [ ] Existing behavior around this area still works (no regressions)

Describe what you actually did to verify it:

## Checklist

- [ ] Follows [CODING-STANDARDS.md](../docs/CODING-STANDARDS.md)
- [ ] No unrelated changes bundled in
- [ ] Migrations (if any) are additive/reversible and documented

## Accessibility (skip if this PR touches no UI)

- [ ] New/changed form controls have a real `<label>` (not placeholder-only)
- [ ] New interactive elements are reachable and operable by keyboard alone
- [ ] No focus outline removed without a visible replacement
- [ ] Meaning isn't conveyed by color alone (status badges etc. also carry text)
- [ ] New images/icons conveying meaning have alt text; purely decorative ones are `aria-hidden="true"`
