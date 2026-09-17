#!/usr/bin/env bash
# Cloud Agent install phase for MCPWP.
# Idempotent: installs the PHP/Composer/Docker toolchain and project deps.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

echo "==> Ensuring PHP 8.2 (matches .devcontainer) + extensions"
if ! command -v php >/dev/null 2>&1 || ! php -v 2>/dev/null | grep -q 'PHP 8\.2'; then
  sudo add-apt-repository -y ppa:ondrej/php
  sudo apt-get update -qq
  sudo apt-get install -y -qq \
    php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-mysql php8.2-gd unzip
  sudo update-alternatives --install /usr/bin/php php /usr/bin/php8.2 82
fi
php -v | head -1

echo "==> Ensuring Composer"
if ! command -v composer >/dev/null 2>&1; then
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  sudo php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
composer --version | head -1

echo "==> Ensuring Docker engine (for the optional WordPress e2e test-rig)"
if ! command -v docker >/dev/null 2>&1; then
  sudo apt-get install -y -qq docker.io docker-compose-v2 || true
fi
# Nested-container VMs can't use overlayfs; vfs is slower but always works.
echo '{"storage-driver":"vfs","features":{"containerd-snapshotter":false}}' \
  | sudo tee /etc/docker/daemon.json >/dev/null

echo "==> Installing PHP dev dependencies (phpunit, phpcs)"
composer install --no-interaction --prefer-dist --working-dir "$REPO_ROOT/mcpwp"

echo "==> Installing agency-proxy Worker dependencies"
( cd "$REPO_ROOT/mcpwp-agency-proxy" && npm ci )

echo "==> install phase complete"
