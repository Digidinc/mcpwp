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
#     pipe does so under pipefail — the step's shell, or a real
#     `set ... pipefail` command, never the word in a comment — has an id, and
#     does not set continue-on-error (which keeps the job green, so failure()
#     never fires);
#   - every step that closes an outage issue has an `if:` made only of
#     &&-joined clauses, one of them exactly steps.<id>.outcome == 'success',
#     with no ||, always(), failure(), cancelled() or negation that could let
#     it run without an observed success;
#   - the contract job runs with read-only permissions.
# Then, behaviourally: the shell `shell: bash` selects keeps a piped failure.
#
# --self-test builds mutated copies of the real workflow (each one a way to
# silence the canary) in its own temp directory and requires every one to be
# rejected, and the unmutated copy to pass (review r3 R3-4).
#
# Usage: bash scripts/tests/verify_canary_workflow_test.sh [--self-test]
#        CANARY_WORKFLOW=path/to/other.yml bash scripts/tests/verify_canary_workflow_test.sh
# Exit:  0 contract holds · 1 it does not · 2 could not check

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SELF_PATH="$ROOT/scripts/tests/$(basename "${BASH_SOURCE[0]}")"

command -v python3 >/dev/null 2>&1 || {
	echo "FAIL: python3 is required to check the workflow." >&2
	exit 2
}

if [[ "${1:-}" == "--self-test" ]]; then
	CHECKER="${CANARY_CHECKER:-$SELF_PATH}"
	SOURCE="$ROOT/.github/workflows/update-channel-canary.yml"
	WORK="$(mktemp -d "${TMPDIR:-/tmp}/canary-contract-selftest.XXXXXX")" || {
		echo "FAIL: mktemp -d failed." >&2
		exit 2
	}
	# Only the directory this run created.
	trap 'rm -rf "$WORK"' EXIT

	python3 - "$SOURCE" "$WORK" <<'PY' || exit 2
import copy
import os
import sys

try:
    import yaml
except ImportError:
    print("FAIL: PyYAML is required for the self-test; nothing was checked.")
    sys.exit(2)

source, out = sys.argv[1], sys.argv[2]
base = yaml.safe_load(open(source))

def verifier_steps(doc):
    for job in doc["jobs"].values():
        for step in job.get("steps") or []:
            run = step.get("run") or ""
            if "gh issue" not in run and "scripts/verify_" in run and "_channel.sh" in run:
                yield step

def close_steps(doc):
    for job in doc["jobs"].values():
        for step in job.get("steps") or []:
            if "gh issue close" in (step.get("run") or ""):
                yield step

mutations = {}

def mutation(name):
    def register(fn):
        doc = copy.deepcopy(base)
        fn(doc)
        mutations[name] = doc
        return fn
    return register

@mutation("no-workflow-shell")
def _(doc):
    doc.pop("defaults", None)

@mutation("verify-step-shell-sh")
def _(doc):
    for step in verifier_steps(doc):
        step["shell"] = "sh -e {0}"

@mutation("continue-on-error")
def _(doc):
    for step in verifier_steps(doc):
        step["continue-on-error"] = True

@mutation("pipefail-only-in-a-comment")
def _(doc):
    doc.pop("defaults", None)
    for step in verifier_steps(doc):
        step["run"] = "# no pipefail here\n" + step["run"]

@mutation("close-condition-with-or-always")
def _(doc):
    for step in close_steps(doc):
        step["if"] = "always() && github.event_name == 'schedule' || steps.verify.outcome == 'success'"

@mutation("close-condition-negated")
def _(doc):
    for step in close_steps(doc):
        step["if"] = "!cancelled() && steps.verify.outcome == 'success'"

@mutation("close-condition-without-outcome")
def _(doc):
    for step in close_steps(doc):
        step["if"] = "success() && github.event_name == 'schedule'"

@mutation("dot-slash-invocation-without-pipefail")
def _(doc):
    doc.pop("defaults", None)
    for step in verifier_steps(doc):
        step["run"] = step["run"].replace("bash scripts/", "./scripts/")

@mutation("verify-step-without-id")
def _(doc):
    for step in verifier_steps(doc):
        step.pop("id", None)

@mutation("contract-job-default-permissions")
def _(doc):
    doc["jobs"]["freemius-verifier-contract"].pop("permissions", None)

yaml.safe_dump(base, open(os.path.join(out, "baseline.yml"), "w"), sort_keys=False)
for name, doc in mutations.items():
    yaml.safe_dump(doc, open(os.path.join(out, "mutant-" + name + ".yml"), "w"), sort_keys=False)
PY

	failed=0
	if CANARY_WORKFLOW="$WORK/baseline.yml" bash "$CHECKER" >/dev/null 2>&1; then
		echo "PASS  baseline workflow accepted"
	else
		echo "FAIL  baseline workflow rejected"
		failed=1
	fi
	for mutant in "$WORK"/mutant-*.yml; do
		name="$(basename "$mutant" .yml)"
		CANARY_WORKFLOW="$mutant" bash "$CHECKER" >/dev/null 2>&1
		code=$?
		if [[ "$code" -eq 1 ]]; then
			echo "PASS  ${name#mutant-} rejected"
		else
			echo "FAIL  ${name#mutant-}: checker exit $code, want 1"
			failed=1
		fi
	done
	exit "$failed"
fi

WORKFLOW="${CANARY_WORKFLOW:-$ROOT/.github/workflows/update-channel-canary.yml}"
[[ -f "$WORKFLOW" ]] || {
	echo "FAIL: $WORKFLOW not found — nothing was checked." >&2
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

# A verifier invoked as a command: bash scripts/…, ./scripts/…, "$GITHUB_WORKSPACE"/scripts/…
invokes = re.compile(r"(^|\s)(bash\s+)?(\./|\S*/)?scripts/verify_[a-z_]+_channel\.sh\b", re.M)
# pipefail set by a command, not mentioned in a comment.
set_pipefail = re.compile(r"(^|[;&|]\s*)set\s+(-[A-Za-z]*o\s*pipefail|-[A-Za-z]+\s+-o\s+pipefail|-o\s+pipefail)\b")
shell_pipefail = re.compile(r"-[A-Za-z]*o\s*pipefail|-o\s+pipefail")

def code_of(run):
    """The run block without comment lines or trailing comments."""
    lines = []
    for line in run.splitlines():
        if line.strip().startswith("#"):
            continue
        lines.append(re.sub(r"\s+#.*$", "", line))
    return "\n".join(lines)

def pipefail_shell(shell):
    return shell == "bash" or bool(shell and "bash" in shell and shell_pipefail.search(shell))

workflow_shell = ((doc.get("defaults") or {}).get("run") or {}).get("shell")
verify_steps = 0
close_steps = 0

for job_name, job in (doc.get("jobs") or {}).items():
    job_shell = ((job.get("defaults") or {}).get("run") or {}).get("shell")
    steps = job.get("steps") or []
    verify_ids = []

    for step in steps:
        run = step.get("run") or ""
        if "gh issue" in run:
            continue  # issue bodies quote the command; they do not run it
        code = code_of(run)
        if not invokes.search(code):
            continue
        verify_steps += 1
        label = f"{job_name}: '{step.get('name')}'"

        if step.get("continue-on-error") not in (None, False):
            problems.append(f"{label} sets continue-on-error, so a failing verifier leaves the job green and no issue is filed")

        shell = step.get("shell") or job_shell or workflow_shell
        piped = re.search(r"(?<!\|)\|(?!\|)", code) is not None
        if piped and not (pipefail_shell(shell) or any(set_pipefail.search(line) for line in code.splitlines())):
            problems.append(f"{label} pipes the verifier without pipefail, so its exit code is lost")

        if step.get("id"):
            verify_ids.append(step["id"])
        else:
            problems.append(f"{label} is a verify step without an id, so later steps cannot require its success")

    for step in steps:
        if "gh issue close" not in (step.get("run") or ""):
            continue
        close_steps += 1
        label = f"{job_name}: '{step.get('name')}'"
        condition = str(step.get("if") or "").strip()
        condition = re.sub(r"^\$\{\{\s*(.*?)\s*\}\}$", r"\1", condition)

        if "||" in condition or re.search(r"\b(always|failure|cancelled)\s*\(", condition) or re.search(r"!(?!=)", condition):
            problems.append(f"{label} closes the outage issue on a condition with ||, always(), failure(), cancelled() or a negation — it can run without an observed success")
            continue

        clauses = [c.strip() for c in condition.split("&&")]
        clauses = [re.sub(r"^\((.*)\)$", r"\1", c).strip() for c in clauses]
        if not any(re.fullmatch(rf"steps\.{re.escape(i)}\.outcome\s*==\s*'success'", c) for i in verify_ids for c in clauses):
            problems.append(f"{label} closes the outage issue without requiring the verify step's observed success")

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
