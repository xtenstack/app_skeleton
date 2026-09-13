#!/bin/bash
# Standard deploy for app_skeleton on stack-prod (Internal Prod).
#
# MUST use both compose files together -- docker-compose.prod.yml is
# what keeps this container's `app`/`caddy` services on the external
# `edge_shared` network (PR #11, app_skeleton#11, merged 2026-09-11).
# A bare `docker compose up`/`build` without -f docker-compose.prod.yml
# silently drops that network membership and takes xtmk.xten.au down
# (502, Caddy can't resolve xten-marketing-app-1) -- this has now
# happened at least 3 times, including twice from Claude sessions that
# forgot the second -f flag. This script exists so "deploy" always means
# running this, not remembering a flag.
set -e
cd /opt/app_skeleton
git pull origin main
docker compose -f docker-compose.yml -f docker-compose.prod.yml build app
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm app php run migrate run
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
sleep 3
echo "--- edge_shared membership ---"
docker network inspect edge_shared --format '{{range .Containers}}{{.Name}} {{end}}'
echo
echo "--- health ---"
curl -s -o /dev/null -w "stack-internal.xten.au: %{http_code}\n" https://stack-internal.xten.au/
curl -s -o /dev/null -w "xtmk.xten.au: %{http_code}\n" https://xtmk.xten.au/
