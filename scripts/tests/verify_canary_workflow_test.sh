#!/usr/bin/env bash
# Contract test for .github/workflows/update-channel-canary.yml.
#
# The canary pipes each verifier into `tee`. A `run:` block without an
# explicit `shell: bash` runs as `bash -e {0}` — no pipefail — so the step took
# tee's status, always 0: a broken channel left the step green, the
# file-an-issue step (if: failure()) never ran, and the close-on-green step
# closed open outage issues while the channel was still down (review r2 N2).
#
# Checked statically, no secrets and no network:
#   - every step that runs a scripts/verify_*_channel.sh verifier through a
#     pipe does so under pipefail, and has an id;
#   - every step that closes an outage issue requires that verify step's
#     observed success (steps.<id>.outcome == 'success');
#   - the contract job runs with read-only permissions.
# Then, behaviourally: the shell `shell: bash` selects keeps a piped failure.
#
# Usage: bash scripts/tests/verify_canary_workflow_test.sh
#        CANARY_WORKFLOW=path/to/other.yml bash scripts/tests/verify_canary_workflow_test.sh
# Exit:  0 contract holds · 1 it does not · 2 could not check

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${CANARY_WORKFLOW:-$ROOT/.github/workflows/update-channel-canary.yml}"

[[ -f "$WORKFLOW" ]] || {
	echo "FAIL: $WORKFLOW not found — nothing was checked." >&2
	exit 2
}
command -v python3 >/dev/null 2>&1 || {
	echo "FAIL: python3 is required to check the workflow." >&2
	exit 2
}

python3 - "$WORKFLOW" <<'PY'
import re
import sys

try:
    import yaml
except ImportError:
    print("FAIL: PyYAML is required to check the workflow; nothing was checked.")
    sys.exit(2)

doc = yaml.safe_load(open(sys.argv[1]))
problems = []
workflow_shell = ((doc.get("defaults") or {}).get("run") or {}).get("shell")
verify_steps = 0
close_steps = 0

for job_name, job in (doc.get("jobs") or {}).items():
    job_shell = ((job.get("defaults") or {}).get("run") or {}).get("shell")
    steps = job.get("steps") or []
    verify_ids = []

    # A verifier step invokes a channel verifier as a command. Issue-filing
    # steps quote the same command inside their issue body; they are not one.
    invokes = re.compile(r"^\s*(bash\s+)?scripts/verify_[a-z_]+_channel\.sh\b", re.M)
    for step in steps:
        run = step.get("run") or ""
        if "gh issue" in run or not invokes.search(run):
            continue
        verify_steps += 1
        shell = step.get("shell") or job_shell or workflow_shell
        piped = "|" in run.replace("||", "")
        pipefail = shell == "bash" or "pipefail" in run
        if piped and not pipefail:
            problems.append(f"{job_name}: '{step.get('name')}' pipes the verifier without pipefail, so its exit code is lost")
        if step.get("id"):
            verify_ids.append(step["id"])
        else:
            problems.append(f"{job_name}: verify step '{step.get('name')}' has no id, so later steps cannot require its success")

    for step in steps:
        if "gh issue close" not in (step.get("run") or ""):
            continue
        close_steps += 1
        condition = str(step.get("if") or "")
        if not any(f"steps.{i}.outcome == 'success'" in condition for i in verify_ids):
            problems.append(f"{job_name}: '{step.get('name')}' closes the outage issue without requiring the verify step's observed success")

    if job_name == "freemius-verifier-contract" and job.get("permissions") != {"contents": "read"}:
        problems.append(f"{job_name}: must run with permissions {{contents: read}}, has {job.get('permissions')!r}")

if verify_steps == 0:
    problems.append("no verifier step found — nothing was checked")

for problem in problems:
    print("FAIL: " + problem)
if problems:
    sys.exit(1)
print(f"OK: {verify_steps} verifier step(s) keep their exit code; {close_steps} close step(s) require observed success.")
PY
code=$?
[[ "$code" -eq 0 ]] || exit "$code"

# `shell: bash` is `bash --noprofile --norc -eo pipefail {0}` on GitHub runners.
if bash --noprofile --norc -eo pipefail -c '( exit 3 ) 2>&1 | tee /dev/null >/dev/null'; then
	echo "FAIL: bash -eo pipefail did not keep a piped failure." >&2
	exit 1
fi
echo "OK: under pipefail a failing verifier fails the step."
