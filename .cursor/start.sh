#!/usr/bin/env bash
# Cloud Agent start phase for MCPWP.
# Brings up the Docker daemon so the optional WordPress test-rig can run.
# Idempotent and tolerant of restarts. There is no systemd in the VM, so
# dockerd is launched directly.
set -euo pipefail

command -v docker >/dev/null 2>&1 || { echo "docker not installed; skipping"; exit 0; }

# The nested VM ships both nftables and iptables-legacy rulesets. Docker writes
# its rules via nft, but the legacy FORWARD chain defaults to DROP and silently
# blocks container<->container traffic on custom bridges. Open it so the rig's
# WordPress container can reach its MariaDB container.
sudo iptables-legacy -P FORWARD ACCEPT 2>/dev/null || true

if sudo docker info >/dev/null 2>&1; then
  echo "==> dockerd already running"
  exit 0
fi

echo "==> starting dockerd (vfs storage driver)"
sudo rm -f /var/run/docker.pid /var/run/docker.sock 2>/dev/null || true
sudo bash -c 'nohup dockerd >/tmp/dockerd.log 2>&1 &'

for i in $(seq 1 30); do
  if sudo docker info >/dev/null 2>&1; then
    echo "==> dockerd ready"
    exit 0
  fi
  sleep 1
done

echo "WARNING: dockerd did not become ready in time; see /tmp/dockerd.log" >&2
exit 0
