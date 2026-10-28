# Stack API description (OpenAPI)

- `base.yaml`: info, authentication, error and convention text, shared schemas.
- `parts/*.yaml`: one file per area (core api, marketing, directory and agent rooms, plugin and module APIs).
- `notes/*.notes.md`: what each author could not confirm from the code, and inconsistencies found while documenting (28 Oct 2026). Work through them; delete an item when it is fixed or decided.
- Build: `npm install && node bin/build-openapi.mjs` writes `app/modules/api/spec/openapi.json` (commit it); `--check` fails when it is stale or invalid.
- Served at `GET /api/docs/openapi` (any authenticated caller) and browsed at Backend > API Docs.
- Rule (docs/MODULE-SPEC.md, "API documentation"): a route change updates its part file in the same pull request.
