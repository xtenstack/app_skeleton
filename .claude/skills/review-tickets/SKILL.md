---
name: review-tickets
description: Review and work through open tickets in the production Tickets system via its API. Use when the user asks to review, triage, or close open (production) tickets.
---

# Reviewing open production tickets

The production Tickets system (`backend`'s Ticketing feature, staff-facing triage at `/backend/tickets`) is how the user reports real bugs/requests found while using their own deployed instance. This skill is the workflow for going through the open queue via the API, not the backend UI — faster for a session doing several at once, and scriptable.

## Getting access

The prod Tickets API key lives at `~/.config/xten/api-keys.env` on the user's Mac (mode 600) — **never** print its value, `cat` it directly, or put it in a memory file. Source it into a shell variable and use it within the same command, e.g.:

```bash
bash -c 'set -a; source ~/.config/xten/api-keys.env; set +a; curl -s -H "X-Api-Key: $PROD_TICKETS_API_KEY" "https://stack-internal.xten.au/api/tickets?status=open"'
```

If that file doesn't exist or the key doesn't work, ask the user rather than guessing at a different location — this is exactly the kind of secret that shouldn't be regenerated speculatively.

## The workflow, per ticket

This mirrors the pattern already proven across two sessions (REQ-048/049, REQ-054/056 in Requirements-List.md) — root-cause it for real, don't just patch the reported symptom:

1. **Read the ticket's title/description/severity** from the API response — don't assume the reported symptom is the actual bug.
2. **Reproduce or root-cause it directly against the code**, not by guessing. A CSS bug gets traced to the actual conflicting rule; a backend error gets traced with a real request against a real stack (see this project's own testing philosophy — CODING-STANDARDS.md's Testing section — real HTTP/real DB, not assumed-safe reasoning).
3. **Fix it, then verify the fix against the real running stack** — same standard as any other change in this repo, not lowered because it's "just a ticket."
4. **Deploy and confirm live** if the fix is code (commit → push → prod deploy, per the standing deploy authorization if one is active — check current project memory/CLAUDE.md for whether that authorization still holds).
5. **Report back per-ticket**, not just "all done" — what the root cause actually was, distinct from what was reported, if they differ. The user has consistently closed tickets themselves once they've verified live, rather than Claude closing them — don't assume closing authority; report what was fixed and let them confirm and close.
6. **If a ticket can't be confidently root-caused** (intermittent, no reliable repro), say so plainly rather than guessing at a fix. Add diagnostic logging if that's genuinely the right next step, and say clearly that the ticket is staying open pending more data — don't force a resolution that isn't real. The user has closed tickets on their own judgment as "not a design flaw, a usage note" in exactly this situation before; that's their call to make, not something to preempt.

## Don't

- Don't close a ticket via the API (there's no such endpoint exposed anyway — `api\TicketsController` deliberately has no status-changing action; ticket triage authority stays with humans, only `backend\TicketsController` can close/reopen).
- Don't treat "ticket says X is broken" as ground truth for *why* — several past tickets' real root cause was different from what the reporter assumed (a CSS specificity bug reported as a "dark mode is broken" ticket, a Safari Photos-picker quirk reported as a "session expired" ticket).
