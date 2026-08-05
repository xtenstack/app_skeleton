#!/bin/bash
set -euo pipefail

CUSTOM_NGINX_CONFIG="/home/site/wwwroot/azure-nginx-default"
TARGET_NGINX_CONFIG="/etc/nginx/sites-available/default"

if [ ! -f "$CUSTOM_NGINX_CONFIG" ]; then
  echo "Custom nginx config not found: $CUSTOM_NGINX_CONFIG" >&2
  exit 1
fi

cp "$CUSTOM_NGINX_CONFIG" "$TARGET_NGINX_CONFIG"

if ! nginx -t; then
  echo "nginx configuration test failed" >&2
  exit 1
fi

service nginx reload || true

echo "Custom nginx config applied from $CUSTOM_NGINX_CONFIG"