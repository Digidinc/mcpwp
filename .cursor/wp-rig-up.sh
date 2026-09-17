#!/usr/bin/env bash
# Bring up the local WordPress test rig, install core, activate the mcpwp
# plugin, and mint an admin-scoped API key. Idempotent. Prints the key and a
# ready-to-run curl command on success.
#
#   bash .cursor/wp-rig-up.sh
#
# Requires the Docker daemon (started by .cursor/start.sh).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE="$SCRIPT_DIR/wp-rig.compose.yml"
KEY_FILE="$SCRIPT_DIR/.mcpwp-dev-key"
URL="http://127.0.0.1:8086"
C=wp-rig-wp-dev
DC="sudo docker compose -f $COMPOSE"
WP="sudo docker exec $C wp --allow-root"

echo "==> Bringing up containers"
$DC up -d

echo "==> Waiting for WordPress HTTP"
for i in $(seq 1 60); do
  code=$(curl -s -o /dev/null -w "%{http_code}" "$URL/wp-login.php" 2>/dev/null || echo 000)
  case "$code" in 200|302) break;; esac
  sleep 3
done

echo "==> Ensuring WP-CLI in container"
if ! sudo docker exec "$C" wp --version --allow-root >/dev/null 2>&1; then
  # The container only resolves IPv6 for GitHub and has no v6 route, so fetch
  # WP-CLI on the host (IPv4) and copy it in.
  curl -4 -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /tmp/wp-cli.phar
  sudo docker cp /tmp/wp-cli.phar "$C:/usr/local/bin/wp"
  sudo docker exec "$C" chmod +x /usr/local/bin/wp
fi

echo "==> Installing WordPress core (idempotent)"
if ! $WP core is-installed >/dev/null 2>&1; then
  $WP core install --url="$URL" --title="MCPWP Dev Rig" \
    --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.com --skip-email
fi

echo "==> Activating mcpwp"
$WP plugin activate mcpwp >/dev/null 2>&1 || true

echo "==> Minting API key (idempotent)"
if [ -f "$KEY_FILE" ]; then
  KEY=$(cat "$KEY_FILE")
else
  KEY=$(sudo docker exec "$C" bash -c 'php -r "
require_once \"/var/www/html/wp-load.php\";
\$plain = \"mcpwp_\" . bin2hex(random_bytes(24));
\$hash  = wp_hash_password(\$plain);
\$record = array(
  \"id\"=>wp_generate_uuid4(),\"label\"=>\"Cloud Rig Key\",\"hash\"=>\$hash,
  \"scopes\"=>array(\"read\",\"write\",\"admin\"),\"role\"=>\"admin\",
  \"tool_categories\"=>array(),\"created_at\"=>current_time(\"mysql\"),
  \"last_used_at\"=>null,\"revoked_at\"=>null,
);
\$existing = get_option(\"mcpwp_api_keys\", array()); if(!is_array(\$existing))\$existing=array();
\$existing[]=\$record; update_option(\"mcpwp_api_keys\", \$existing); update_option(\"mcpwp_api_key\", \$hash);
echo \$plain;
" 2>/dev/null')
  echo "$KEY" > "$KEY_FILE"
fi

echo ""
echo "============================================"
echo "  MCPWP test rig ready"
echo "  WordPress:  $URL  (admin / admin)"
echo "  API key:    $KEY"
echo ""
echo "  Verify:"
echo "    curl -s '$URL/?rest_route=/mcpwp/v1/site-info' -H 'X-API-Key: $KEY' | jq"
echo "============================================"
