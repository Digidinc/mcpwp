#!/usr/bin/env bash
# Check every public HTTP(S) URL advertised by the WordPress.org readme.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
README="${1:-$SCRIPT_DIR/../readme.txt}"
CURL_BIN="${MCPWP_LINK_CHECK_CURL:-curl}"

if [[ ! -f "$README" ]]; then
	echo "[FAIL] Readme not found: $README" >&2
	exit 1
fi
if ! command -v python3 >/dev/null 2>&1; then
	echo "[FAIL] python3 is required for DNS and IP safety validation." >&2
	exit 1
fi

url_host() {
	local authority
	authority="${1#*://}"
	authority="${authority%%/*}"
	authority="${authority##*@}"
	if [[ "$authority" == \[*\]* ]]; then
		authority="${authority#\[}"
		printf '%s\n' "${authority%%]*}"
	else
		printf '%s\n' "${authority%%:*}"
	fi
}

url_port() {
	local url authority remainder
	url="$1"
	authority="${url#*://}"
	authority="${authority%%/*}"
	authority="${authority##*@}"
	if [[ "$authority" == \[*\]:* ]]; then
		printf '%s\n' "${authority##*:}"
	elif [[ "$authority" != \[*\] && "$authority" == *:* ]]; then
		printf '%s\n' "${authority##*:}"
	elif [[ "$url" == https://* ]]; then
		printf '443\n'
	else
		printf '80\n'
	fi
}

url_has_userinfo() {
	local authority
	authority="${1#*://}"
	authority="${authority%%/*}"
	authority="${authority%%\?*}"
	authority="${authority%%\#*}"
	[[ "$authority" == *@* ]]
}

is_private_name() {
	local host
	host="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
	case "$host" in
		localhost|*.localhost|*.local|*.internal)
			return 0
			;;
	esac
	return 1
}

resolve_host() {
	local host
	host="$1"
	if [[ -n "${MCPWP_LINK_CHECK_RESOLVER:-}" ]]; then
		"$MCPWP_LINK_CHECK_RESOLVER" "$host"
	else
		python3 -c 'import socket, sys
host = sys.argv[1]
addresses = sorted({item[4][0] for item in socket.getaddrinfo(host, None, type=socket.SOCK_STREAM)})
print("\n".join(addresses))' "$host"
	fi
}

validate_public_host() {
	local host addresses classification classify_status
	host="$1"
	if is_private_name "$host"; then
		echo "[FAIL] Private or local hostname is not allowed: $host" >&2
		return 1
	fi
	if ! addresses="$(resolve_host "$host" 2>/dev/null)"; then
		echo "[FAIL] Could not resolve readme link host: $host" >&2
		return 1
	fi
	classify_status=0
	classification="$(printf '%s\n' "$addresses" | python3 -c 'import ipaddress, sys
valid = []
for line in sys.stdin:
    candidate = line.strip().split("%", 1)[0]
    if not candidate:
        continue
    try:
        valid.append(ipaddress.ip_address(candidate))
    except ValueError:
        continue
if not valid:
    raise SystemExit(2)
blocked = [str(address) for address in valid if not address.is_global]
if blocked:
    print(", ".join(blocked))
    raise SystemExit(3)
print("\n".join(str(address) for address in valid))' "$host")" || classify_status=$?
	if (( classify_status == 2 )); then
		echo "[FAIL] Host did not resolve to a usable IPv4 or IPv6 address: $host" >&2
		return 1
	fi
	if (( classify_status != 0 )); then
		echo "[FAIL] Host resolves to a private, loopback, link-local, or reserved address: $host -> $classification" >&2
		return 1
	fi
	MCPWP_VALIDATED_ADDRESSES="$classification"
	return 0
}

check_url() {
	local original current host port pinned resolve_entry result code redirect effective effective_host remote_ip hop write_out
	local -a destination_args
	original="$1"
	current="$original"
	hop=0
	while (( hop <= 10 )); do
		if [[ ! "$current" =~ ^https?:// ]]; then
			echo "[FAIL] Redirect uses a non-HTTP(S) scheme: $original -> $current" >&2
			return 1
		fi
		if url_has_userinfo "$current"; then
			echo "[FAIL] URL userinfo credentials are not allowed: $original -> $current" >&2
			return 1
		fi
		host="$(url_host "$current")"
		validate_public_host "$host" || return 1
		port="$(url_port "$current")"
		pinned="$(printf '%s\n' "$MCPWP_VALIDATED_ADDRESSES" | sed -n '1p')"
		if [[ "$pinned" == *:* ]]; then pinned="[$pinned]"; fi
		resolve_entry="$host:$port:$pinned"
		write_out=$'%{http_code}\n%{redirect_url}\n%{url_effective}'
		if [[ "${MCPWP_LINK_CHECK_HOSTNAME_TRANSPORT:-0}" == '1' ]]; then
			# GitHub-hosted runners may block direct connections to a pinned IP.
			# Preserve the DNS-rebinding guard by requiring curl's connected address
			# to be one of the public addresses resolved immediately before this hop.
			destination_args=()
			write_out+=$'\n%{remote_ip}'
		else
			destination_args=( --resolve "$resolve_entry" )
		fi

		if ! result="$("$CURL_BIN" --disable --silent --show-error --output /dev/null \
			--proxy '' --noproxy '*' "${destination_args[@]}" \
			--connect-timeout 10 --max-time 60 --retry 3 --retry-all-errors --retry-delay 2 --max-redirs 0 --proto '=http,https' --proto-redir '=http,https' \
			--user-agent 'Mumega-MCP-Readme-Link-Checker/1.0' \
			--write-out "$write_out" "$current")"; then
			echo "[FAIL] Request failed: $current" >&2
			return 1
		fi
		code="$(printf '%s\n' "$result" | sed -n '1p')"
		redirect="$(printf '%s\n' "$result" | sed -n '2p')"
		effective="$(printf '%s\n' "$result" | sed -n '3p')"
		effective_host="$(url_host "$effective")"
		validate_public_host "$effective_host" || return 1
		if [[ "${MCPWP_LINK_CHECK_HOSTNAME_TRANSPORT:-0}" == '1' ]]; then
			remote_ip="$(printf '%s\n' "$result" | sed -n '4p')"
			if [[ -z "$remote_ip" ]] || ! printf '%s\n' "$MCPWP_VALIDATED_ADDRESSES" | grep -F -x -- "$remote_ip" >/dev/null; then
				echo "[FAIL] Hostname transport connected outside the validated address set: $host -> ${remote_ip:-<empty>}" >&2
				return 1
			fi
		fi

		if [[ "$code" =~ ^2[0-9][0-9]$ ]]; then
			echo "[OK] HTTP $code: $original -> $effective"
			return 0
		fi
		if [[ "$code" =~ ^3[0-9][0-9]$ && -n "$redirect" ]]; then
			current="$redirect"
			hop=$(( hop + 1 ))
			continue
		fi
		echo "[FAIL] HTTP $code: $original -> $effective" >&2
		return 1
	done
	echo "[FAIL] Too many redirects: $original" >&2
	return 1
}

URLS_FILE="$(mktemp)"
trap 'rm -f "$URLS_FILE"' EXIT
{
	grep -Eo 'https?://[^[:space:]<>"`)]+' "$README" || true
} | sed -E 's/[.,;:]+$//' | LC_ALL=C sort -u > "$URLS_FILE"

failures=0
checked=0
while IFS= read -r url; do
	[[ -n "$url" ]] || continue
	checked=$(( checked + 1 ))
	if ! check_url "$url"; then failures=$(( failures + 1 )); fi
done < "$URLS_FILE"

if (( failures > 0 )); then
	echo "[FAIL] $failures of $checked readme link(s) failed." >&2
	exit 1
fi

echo "[OK] $checked readme link(s) passed."
