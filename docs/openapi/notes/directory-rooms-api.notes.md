# Notes: Directory and Agent Rooms OpenAPI

## Could not fully determine (x-unverified)
- dir_search: the Prominent "boost" rows prepended on page 1 (findProminentBoosts) were not read; their field set may differ from DirSearchResult. Text-search internals (textSearchAbns) only skimmed.
- room_uploadAttachment: camelCase action in the URL (/agent_rooms/api/uploadAttachment/...) assumed to dispatch; the module itself generates downloadAttachment URLs the same way, and the web views contain no api/ calls to confirm.

## Not in code (requested but absent)
- Agent Rooms has no room key / room token, no "participants" listing endpoint, and no "watches" feature. Access is only user identity (session, or API key via X-Api-Key / Bearer; session wins if logged in). Participants are only touched via join, kick, leave. The HTML RoomsController is out of scope.
- No agent-rooms route registration: routes come from the generic /agent_rooms/:controller/:action/:params route in app_skeleton/app/config/routes.php, which accepts any HTTP verb. Verb checks are in code (405 for non-POST on create, join, message, uploadAttachment, kick, lock, leave). read, status, transcript, downloadAttachment accept any method.
- Directory routes accept GET or POST plus OPTIONS only; OPTIONS returns 204 from onConstruct. The OPTIONS checks inside videoAction and unspscAssignAction are dead code.

## Inconsistencies / possible bugs
- dir_video and dir_unspscAssign are "staff only" in comments, but the code only requires any authenticated principal (any role, including member). No role check.
- dir_video, when it creates a claim, stores tier = package key (e.g. bundle_prominent_video) rather than free/featured/prominent; such a claim will not match the tier IN ('prominent','featured') checks and its UNSPSC limit falls to 1.
- dir_claimVerify is a state-changing GET.
- dir_optOut has no ownership proof: anyone with an ABN and any email can suppress it. dir_enquiry does not check suppression. dir_verifyAbn ignores suppression.
- dir_person returns contact_via as a placeholder string "(not yet built - directory-V3.md)".
- dir_claim: UNSPSC tier limit is derived from the package name (contains "prominent" else featured), so video_showcase/video_standalone count as featured (3) though stored tier is the package key; the free path validates the count but never persists the codes; the paid path silently skips unknown codes.
- dir_claim: a failed second (production) Dolibarr order is only logged; the caller gets 200 with null production_checkout_url.
- Rooms: room_leave on a kicked participant sets status to left, and room_join then allows rejoin, bypassing the kick. read does not reject kicked/left participants. uploadAttachment does not check room lock or participant status. message validates content before room existence (422 before 404). content limit uses strlen (bytes) though the error says characters.
- Rooms: allowedRoles is null in ApiController, so every active user of any role can create/join/read; roles matter only for canView/canManage (admin, operator).
- inviteInstructions and the endpoints object omit kick, lock and attachments.
