# Copilot instructions for this repo

## Branch/editor policy — read this first

**Claude Code Desktop (Sonnet 5) is the sole authorized editor of `main`.**
GitHub Copilot / VS Code sessions in this repo exist purely as an
independent validation and safety-net lane against the Azure Test stack —
not as a second author of application code on `main`.

Concretely:

- Do not commit or push directly to `main`.
- Do not hand-edit the `azure-test` branch. It is a machine-managed mirror
  — `.github/workflows/mirror-azure-test.yml` force-pushes `main`'s exact
  content onto it on every push to `main`. Anything committed there
  directly is silently overwritten the next time `main` changes.
- If you find a real bug, a missed edge case, or something the Azure
  validation pass caught, write it up (what broke, how to reproduce, a
  suggested fix if you have one) rather than pushing a fix yourself. Save
  it under `stack.xten.au/GitHub Copilot/sessions/` (outside this repo) so
  the next Claude Code Desktop session can pick it up. Opening a PR
  against `main` for review is fine; merging it is not — that's the
  user's or Claude's call.
- Test artifacts, scratch scripts, and anything produced only to validate
  a deploy belong in your own session notes or a throwaway location, never
  committed to `main` or `azure-test`.

## What this repo's Azure Test stack is for

A second, independently-driven deployment target (Azure Container Apps,
`cptest-app`), used to catch regressions and functional gaps that the
primary Claude Code Desktop + `main` workflow might miss — a different
model, different environment, different reviewer. It is also a candidate
future deployment target if a customer requests Azure specifically. It is
not a second place to develop features.

## Model policy

Use a single, explicitly-selected model for this repo's sessions (not
automatic model selection) so the validation lane stays genuinely
independent in state/environment from the primary Claude Code Desktop
sessions on `main`.

## Known stale state (as of Session 14, 2026-08-07)

`.github/workflows/azure-test_cptest.yml` (filename/content owned by
Azure's Deployment Center, not hand-edited here) still deploys to an
Azure **Web App**. The project has since moved to **Azure Container
Apps** (`cptest-app`, `cptest-ca-smoke`) and the legacy Web App
(`cptest`) was deleted. This workflow is likely dead — flag it rather
than assume it still does anything, and don't treat its presence as
evidence the Web App still exists.
