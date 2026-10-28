# Marketing API notes

## Not fully determined
- api-campaigns/update: a non-existent mail_template_id is not checked in code; behaviour depends on the DB foreign key (probably an uncaught exception, 500). Marked x-unverified.
- Prospect `tech`, `reasons`, `band` and claims `portal_type`/`tier` column types are not visible in code (view/table definitions not fully read); documented as nullable strings.
- Exact JSON for Phalcon save() failures (422) is the model messages joined by ", "; the models define no validators, so in practice failures are DB constraint errors, which may surface as 500 instead.
- Webhook method is not checked by the action; documented as POST only.
- Router accepts any HTTP method for every generic URL; only write actions check isPost(). Read actions documented as GET.

## Possible bugs / inconsistencies
- Authorisation: all Api* controllers allow only roles admin and agent (Roles::idsByNames). operator, charter_agent, member get 403 (despite the brief listing them as general roles).
- ListView::paginate (addresses, prospects): per_page is uncapped and not guarded; per_page=0 divides by zero (500), negative gives odd pages.
- ApiProspectsController STATUSES lacks "unsubscribed" (added to the DB CHECK by migration 033): the filter silently ignores ?status=unsubscribed, and a prospect already unsubscribed cannot be updated with outreach_status "unsubscribed" (422), though serialize() can return it.
- api-leads/convert ignores save() results for contact, member and lead, so a 200 may hide failed writes. Lead abn is never validated on create/update/convert.
- api-leads/index silently ignores invalid status; api-prospects/index likewise.
- api-contacts/redirect always returns 201, even if old_email was already suppressed or matched no ABN (in which case nothing but the suppression happens). Response omits events_added.
- api-contacts/replied with reply_type "unsubscribe" does NOT suppress the address; callers must also call api-unsubscribes/create.
- api-unsubscribes/create: scope values other than "address" silently become "entity". Suppress for related addresses isn't wrapped in try/catch (a RuntimeException there gives 500, while the primary one gives 422).
- PublicLeadCaptureController docblock/comments claim an Origin allowlist; code only echoes Access-Control-Allow-Origin for deploy.xten.au and xten.au and never rejects other origins. The honeypot response (200) differs from the success status (201).
- PublicLeadCaptureController comment says it is "never reachable via the generic route", but the generic /marketing/public-lead-capture/<action> route would also dispatch to it (any method; healthCheckAction still requires POST). Likewise OPTIONS handling via the generic route has no CORS check differences.
- Stale/odd text: PublicLeadCaptureController logs "LeadsController:" and notification text mentions "Data Restore Audit" for every source/product.
- WebhookController: email.complained suppresses at default (entity) scope; bounce Permanent suppresses at address scope. `data.bounce_type` is a fallback for `data.bounce.type`.
- Leads.restored is accepted only by the public form and is not returned by api-leads serialisation.
- Api* session callers: a logged-in browser session also authenticates (no CSRF check).

## In code but not routable / not documented
- Not documented (outside assigned area): AddressesController, CampaignsController, LeadsController, LeadReservationsController, MailTemplatesController, ProspectsController, UnsubscribeController (HTML/backend or public unsubscribe flow).
- ApiControllerBase itself: abstract helper, not an endpoint.
- Generic routes also expose `/marketing/<controller>` (index action) and `/marketing/` (index controller); none of the Api controllers' indexAction needs params, so `/marketing/api-<x>` is equivalent to `/marketing/api-<x>/index`. Not listed separately.
