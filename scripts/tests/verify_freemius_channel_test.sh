#!/usr/bin/env bash
# Offline contract test for scripts/verify_freemius_channel.sh.
#
# The verifier must exit 0 only when paying customers can actually get the
# build. This drives it against a fake curl and fixture ZIPs and pins its
# exit-code matrix, plus three properties no exit code shows: the bearer never
# reaches storage, no credential or signed query string is printed, and the
# verifier's temp directory is gone afterwards.
#
# Usage: bash scripts/tests/verify_freemius_channel_test.sh
# Exit:  0 contract holds · 1 a case failed · 2 could not run

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/scripts/verify_freemius_channel.sh"

for tool in jq unzip python3; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "FAIL: $tool is required to run this test." >&2
		exit 2
	fi
done
[[ -f "$SCRIPT" ]] || {
	echo "FAIL: $SCRIPT not found." >&2
	exit 2
}

WORK="$(mktemp -d)" || {
	echo "FAIL: mktemp -d failed." >&2
	exit 2
}
# Only the directory this test created.
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/bin" "$WORK/fixtures"

cat >"$WORK/bin/curl" <<'SHIM'
#!/usr/bin/env bash
# Fake curl: the Freemius tag list, the premium redirect, and storage.
headers="" out="" url="" auth=""
while [[ $# -gt 0 ]]; do
	case "$1" in
	-D) headers="$2"; shift 2 ;;
	-o) out="$2"; shift 2 ;;
	-w | --max-time | --proto) shift 2 ;;
	-H) [[ "$2" == Authorization:* ]] && auth=1; shift 2 ;;
	-*) shift ;;
	*) url="$1"; shift ;;
	esac
done
emit() { if [[ -n "$out" && "$out" != /dev/null ]]; then cat >"$out"; else cat >/dev/null; fi; }
case "$url" in
*"/tags.json"*)
	cat "$SHIM_DIR/fixtures/tags.json"
	;;
*".zip?is_premium=true")
	[[ -n "$auth" ]] || echo "api-call-without-bearer" >>"$SHIM_DIR/misuse"
	id="${url##*/tags/}"
	id="${id%%.zip*}"
	printf 'HTTP/1.1 302 Found\r\nLocation: https://storage.test/%s/premium.zip?X-Amz-Credential=AKIASECRET&X-Amz-Signature=SIGSECRET\r\n\r\n' "$id" >"$headers"
	echo "<html>302</html>" | emit
	printf 302
	;;
https://storage.test/*)
	[[ -z "$auth" ]] || echo "bearer-sent-to-storage" >>"$SHIM_DIR/misuse"
	n=$(($(cat "$SHIM_DIR/count" 2>/dev/null || echo 0) + 1))
	echo "$n" >"$SHIM_DIR/count"
	mode="${SHIM_MODE:-ok}"
	if [[ "$mode" == flaky ]]; then
		if [[ "$n" -eq 1 ]]; then mode=403; else mode=ok; fi
	fi
	# Refuse the first three requests — a whole failed attempt — then serve.
	if [[ "$mode" == refuse-3 ]]; then
		if [[ "$n" -le 3 ]]; then mode=403; else mode=ok; fi
	fi
	if [[ "$mode" == 403 ]]; then
		echo '<Error><Code>SignatureDoesNotMatch</Code><StringToSign>SIGSECRET</StringToSign></Error>' | emit
		printf 403
	else
		id="${url#https://storage.test/}"
		id="${id%%/*}"
		var="SHIM_ZIP_$id"
		emit <"$SHIM_DIR/fixtures/${!var:-$SHIM_ZIP}"
		printf 200
	fi
	;;
*)
	echo "fake curl: unexpected URL" >&2
	exit 7
	;;
esac
SHIM
chmod +x "$WORK/bin/curl"

python3 - "$WORK/fixtures" <<'PY'
import os, sys, zipfile
d = sys.argv[1]
def build(name, header, constant, sdk=True):
    with zipfile.ZipFile(os.path.join(d, name), "w") as z:
        z.writestr("mcpwp-premium/mcpwp.php",
                   "<?php\n/**\n * Plugin Name: MCPWP\n * Version:           %s\n */\n\n"
                   "define( 'MCPWP_VERSION', '%s' ); // x-release-please-version\n" % (header, constant))
        if sdk:
            z.writestr("mcpwp-premium/freemius/start.php", "<?php\n")
build("3.11.0.zip", "3.11.0", "3.11.0")
build("3.10.8.zip", "3.10.8", "3.10.8")
build("older-build.zip", "3.10.8", "3.10.8")
build("constant-drift.zip", "3.11.0", "3.10.8")
build("no-sdk.zip", "3.11.0", "3.11.0", sdk=False)
with open(os.path.join(d, "not-a-zip.zip"), "w") as f:
    f.write("<html>not a zip</html>")
PY
cat >"$WORK/fixtures/tags.json" <<'JSON'
{"tags":[{"id":"151558","version":"3.11.0","release_mode":"released"},{"id":"150827","version":"3.10.8","release_mode":"released"},{"id":"151600","version":"3.11.1","release_mode":"pending"}]}
JSON

passed=0
failed=0

# run_case NAME WANT_EXIT [VAR=value ...] [-- script args ...]
run_case() {
	local name="$1" want="$2"
	shift 2
	local -a envs=()
	while [[ $# -gt 0 && "$1" != "--" ]]; do
		envs+=("$1")
		shift
	done
	[[ "${1:-}" == "--" ]] && shift

	local tmp="$WORK/case-$name"
	mkdir -p "$tmp"
	rm -f "$WORK/misuse" "$WORK/count"

	local output code
	output="$(env PATH="$WORK/bin:$PATH" SHIM_DIR="$WORK" TMPDIR="$tmp" SHIM_ZIP=3.11.0.zip \
		FREEMIUS_BEARER_TOKEN=tok-SECRET-value "${envs[@]}" bash "$SCRIPT" "$@" 2>&1)"  # secret-scan: allow fake fixture token for the contract test, never a real credential
	code=$?

	local problems=""
	[[ "$code" == "$want" ]] || problems+=" exit $code, want $want;"
	grep -q 'SECRET' <<<"$output" && problems+=" printed a credential or signed query;"
	[[ -s "$WORK/misuse" ]] && problems+=" $(sort -u "$WORK/misuse" | tr '\n' ' ');"
	[[ -z "$(ls -A "$tmp")" ]] || problems+=" left its temp dir behind;"

	if [[ -z "$problems" ]]; then
		echo "PASS  $name (exit $code)"
		passed=$((passed + 1))
	else
		echo "FAIL  $name:$problems"
		sed 's/^/        /' <<<"$output"
		failed=$((failed + 1))
	fi
}

run_case ok                   0 -- --expect-version 3.11.0
run_case no-expect            0
run_case ahead-both-good      0 SHIM_ZIP_150827=3.10.8.zip SHIM_ZIP_151558=3.11.0.zip -- --expect-version 3.10.8
run_case ahead-latest-broken  1 SHIM_ZIP_150827=3.10.8.zip SHIM_ZIP_151558=no-sdk.zip -- --expect-version 3.10.8
run_case behind               1 -- --expect-version 3.12.0
run_case older-build          1 SHIM_ZIP=older-build.zip -- --expect-version 3.11.0
run_case constant-drift       1 SHIM_ZIP=constant-drift.zip -- --expect-version 3.11.0
run_case no-sdk               1 SHIM_ZIP=no-sdk.zip -- --expect-version 3.11.0
run_case not-a-zip            1 SHIM_ZIP=not-a-zip.zip -- --expect-version 3.11.0
run_case storage-refuses      1 SHIM_MODE=403 -- --expect-version 3.11.0
run_case storage-flaky        0 SHIM_MODE=flaky -- --expect-version 3.11.0
run_case storage-flaky-strict 2 SHIM_MODE=flaky -- --expect-version 3.11.0 --strict-storage
run_case missing-token        1 FREEMIUS_BEARER_TOKEN= -- --expect-version 3.11.0
run_case retry-after-refusals 0 SHIM_MODE=refuse-3 -- --expect-version 3.11.0 --retries 2 --delay 0
run_case strict-across-retries 2 SHIM_MODE=refuse-3 -- --expect-version 3.11.0 --retries 2 --delay 0 --strict-storage

echo "$passed passed, $failed failed"
[[ "$failed" -eq 0 ]]
