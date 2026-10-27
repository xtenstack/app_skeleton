# Provisioning API

Admin-only JSON endpoints for creating users with role sets, issuing and rotating API keys, and deactivating users. Before this, users and API keys could only be managed through the backend screens, so role assignment could not be scripted (MAA-20261027-008).

Authenticate with `X-Api-Key: <key>` or `Authorization: Bearer <key>`. Every endpoint except rotate and confirm needs the **admin** role; a key with no admin role gets 403, no key gets 401.

| Method and path | Who | What |
|---|---|---|
| `GET /api/provision/roles` | admin | Role ids and names; `assignable: false` for roles the API will not grant. |
| `POST /api/provision/users` | admin | Create or update a user by email (idempotent). |
| `POST /api/provision/users/{id}/api-key` | admin | Issue a key for a user. The token is returned once. |
| `POST /api/provision/users/{id}/deactivate` | admin | Disable the user and revoke all their live keys. |
| `POST /api/provision/users/{id}/rotate-keys` | **only** the designated users (default `claude.code@xten.au`, `travissaron@xten.au`; override with `PROVISION_ROTATE_ANY_EMAILS`) | Rotate **any** active user's keys: new key (token once), every other live key expires after `grace_seconds` (default 600). |
| `POST /api/provision/api-keys/rotate` | the key's own user (admins included) | New key for the same user; the old one lives on for a grace window. |
| `POST /api/provision/api-keys/confirm` | the new key's user (admins included) | Kill the old key now. |

## Users

```json
POST /api/provision/users
{"email": "tim@xten.au", "first_name": "Tim", "last_name": "Agent",
 "kind": "agent", "role_names": ["agent", "operator"], "active": true}
```

`kind` is `agent` or `person`. The first name in `role_names` becomes the primary role (`users.role_id`); the whole list is the user's role set (`user_roles`). `201` with `created: true` for a new user, `200` with `created: false` when the email already exists (name, active flag and roles are updated). New users get a password nobody can verify, so an agent cannot log in interactively; a person signs in by the normal reset flow or by the self-registration they already did. Response:

```json
{"created": true, "kind": "agent",
 "user": {"id": 42, "email": "tim@xten.au", "first_name": "Tim", "last_name": "Agent",
          "is_active": true, "role_names": ["agent", "operator"]}}
```

Refused with `422`: a missing or invalid field, an unknown role (`unknown_roles` lists them), or the role `admin` (a new admin needs a human at the backend). An existing user who holds `admin` cannot be changed here (`403`).

## API keys

```json
POST /api/provision/users/42/api-key
{"label": "tim prod", "expires_in_days": 90}
```

`label` is required (100 characters at most); `expires_in_days` is optional (1 to 3650). `201`:

```json
{"api_key": {"id": 7, "label": "tim prod", "prefix": "sk_1a2b3c4d", "expires_at": null, "created_at": "..."},
 "token": "sk_...", "note": "The token is shown once and cannot be retrieved again."}
```

Only a SHA-256 hash is stored, exactly as for keys made in the backend, and the response carries `Cache-Control: no-store`. A key past `expires_at` stops authenticating (`api_keys.expires_at`, migration 029). An inactive user cannot be given a key.

## Rotation

An agent rotates its **own** key without a human:

1. `POST /api/provision/api-keys/rotate` with the current key (optional `{"grace_seconds": 60..3600}`, default 600). Returns `201` with the new `token`, and sets the old key to expire at `old_key.expires_at`. Both work until then.
2. The agent stores the new token (for example in its Infisical project).
3. `POST /api/provision/api-keys/confirm` **with the new key** revokes the old key at once. If the agent never confirms, the old key still dies at its expiry.

Admins may rotate their own key this way (decision 27 Oct 2026); nobody can rotate another user's key with these two, which are self-only. Neither endpoint accepts a browser session. Only Code and Travis rotate anyone's keys, with `rotate-keys`; every other admin can rotate only its own.

## Deactivate

`POST /api/provision/users/{id}/deactivate` sets `is_active = 0` and revokes every live key, returning `keys_revoked`. You cannot deactivate yourself (`422`) or a user who holds `admin` (`403`).

## Audit and secrets

Every write goes through a model with snapshots on, so the audit log attributes it to the calling key's user. The only secret returned is a new key's token, once; nothing is logged.
