---
description: Quick health check — prod/dev droplets, open tickets, open requirements
---

Give me a fast status snapshot across the live infrastructure and open
work. Real checks, not assumptions from memory:

1. **Prod**: `curl -sS -o /dev/null -w "%{http_code}\n" https://stack-internal.xten.au/`
   — expect `200`.
2. **Dev**: `curl -sS -o /dev/null -w "%{http_code}\n" https://stack-dev.xten.au/`
   — expect `200`.
3. **Open tickets**: source `~/.config/xten/api-keys.env` and hit
   `https://stack-internal.xten.au/api/tickets?status=open` with the
   `PROD_TICKETS_API_KEY` header (see the `review-tickets` skill for
   the exact pattern) — list title/severity/type for each.
4. **Open requirements**: query prod's `requirements` table directly
   (`docker compose exec -T db psql` over SSH, or the backend UI at
   `/requirements/requirements?status=open`) for a count and the most
   recent few by id.

Report a compact summary — droplet health, ticket count with the most
severe one called out, requirement count. This is a status check, not
a todo list to start working through — report and wait for direction.
