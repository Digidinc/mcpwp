#!/usr/bin/env bash
# Fail-closed contract for the WordPress.org artifact.

set -euo pipefail

if [[ $# -ne 1 ]]; then
	echo "[FAIL] usage: $0 <zip>" >&2
	exit 1
fi

ZIP_PATH="$1"
if [[ ! -f "$ZIP_PATH" ]]; then
	echo "[FAIL] candidate ZIP does not exist: $ZIP_PATH" >&2
	exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
	echo "[FAIL] python3 is required to verify the artifact" >&2
	exit 1
fi

python3 - "$ZIP_PATH" <<'PY'
import os
import re
import sys
import zipfile
from pathlib import PurePosixPath

zip_path = sys.argv[1]
slug = "mumega-mcp"
main_name = f"{slug}/{slug}.php"
readme_name = f"{slug}/readme.txt"


def fail(rule, detail):
    print(f"[FAIL] {rule}: {detail}", file=sys.stderr)
    raise SystemExit(1)


GETTEXT_FUNCTIONS = {
    "__",
    "_e",
    "_ex",
    "_n",
    "_n_noop",
    "_nx",
    "_nx_noop",
    "_x",
    "esc_attr__",
    "esc_attr_e",
    "esc_attr_x",
    "esc_html__",
    "esc_html_e",
    "esc_html_x",
    "translate",
    "translate_with_gettext_context",
}
LEGACY_GETTEXT_DOMAINS = {"mumcp", "mcpwp"}


def skip_quoted(source, start):
    quote = source[start]
    index = start + 1
    while index < len(source):
        if source[index] == "\\":
            index += 2
            continue
        if source[index] == quote:
            return index + 1
        index += 1
    return len(source) + 1


def skip_trivia(source, start):
    index = start
    while index < len(source):
        if source[index].isspace():
            index += 1
            continue
        if source.startswith("//", index) or (source[index] == "#" and not source.startswith("#[", index)):
            newline = source.find("\n", index + 1)
            index = len(source) if newline < 0 else newline + 1
            continue
        if source.startswith("/*", index):
            closing = source.find("*/", index + 2)
            index = len(source) if closing < 0 else closing + 2
            continue
        break
    return index


def parse_call_arguments(source, opening):
    pairs = {"(": ")", "[": "]", "{": "}"}
    stack = [")"]
    arguments = []
    argument_start = opening + 1
    index = argument_start
    while index < len(source):
        char = source[index]
        if char in "'\"":
            index = skip_quoted(source, index)
            continue
        if source.startswith("//", index) or (char == "#" and not source.startswith("#[", index)):
            newline = source.find("\n", index + 1)
            index = len(source) if newline < 0 else newline + 1
            continue
        if source.startswith("/*", index):
            closing = source.find("*/", index + 2)
            index = len(source) if closing < 0 else closing + 2
            continue
        if char in pairs:
            stack.append(pairs[char])
            index += 1
            continue
        if char in ")]}":
            if not stack or char != stack[-1]:
                return None
            stack.pop()
            if not stack:
                arguments.append(source[argument_start:index])
                return arguments
            index += 1
            continue
        if char == "," and len(stack) == 1:
            arguments.append(source[argument_start:index])
            argument_start = index + 1
        index += 1
    return None


def literal_string_value(argument):
    start = skip_trivia(argument, 0)
    if start >= len(argument) or argument[start] not in "'\"":
        return None
    closing = skip_quoted(argument, start)
    if closing > len(argument) or skip_trivia(argument, closing) != len(argument):
        return None
    return argument[start + 1:closing - 1]


def has_legacy_gettext_domain(contents):
    source = contents.decode("utf-8", errors="surrogateescape")
    index = 0
    while index < len(source):
        char = source[index]
        if char in "'\"":
            index = skip_quoted(source, index)
            continue
        if source.startswith("//", index) or (char == "#" and not source.startswith("#[", index)):
            newline = source.find("\n", index + 1)
            index = len(source) if newline < 0 else newline + 1
            continue
        if source.startswith("/*", index):
            closing = source.find("*/", index + 2)
            index = len(source) if closing < 0 else closing + 2
            continue
        if char.isalpha() or char == "_":
            end = index + 1
            while end < len(source) and (source[end].isalnum() or source[end] == "_"):
                end += 1
            function_name = source[index:end]
            opening = skip_trivia(source, end)
            if function_name in GETTEXT_FUNCTIONS and opening < len(source) and source[opening] == "(":
                arguments = parse_call_arguments(source, opening)
                while arguments and skip_trivia(arguments[-1], 0) == len(arguments[-1]):
                    arguments.pop()
                if arguments and literal_string_value(arguments[-1]) in LEGACY_GETTEXT_DOMAINS:
                    return True
            index = end
            continue
        index += 1
    return False


try:
    archive = zipfile.ZipFile(zip_path)
except (OSError, zipfile.BadZipFile) as error:
    fail("valid ZIP", str(error))

with archive:
    infos = archive.infolist()
    names = [info.filename for info in infos]
    if not names:
        fail("manifest", "artifact is empty")
    if len(names) != len(set(names)):
        duplicates = sorted({name for name in names if names.count(name) > 1})
        fail("manifest uniqueness", ", ".join(duplicates))
    if names != sorted(names, key=lambda name: name.encode("utf-8")):
        fail("sorted manifest", "ZIP entries are not in bytewise lexical order")

    for name in names:
        path = PurePosixPath(name)
        if name.startswith("/") or "\\" in name or ".." in path.parts:
            fail("safe manifest path", name)

    roots = sorted({name.split("/", 1)[0] for name in names})
    if roots != [slug]:
        fail("single top-level root", f"expected {slug}/, found {roots}")
    if any(not name.startswith(f"{slug}/") for name in names):
        fail("rooted manifest", "every entry must be below the top-level plugin folder")

    required = {main_name, readme_name, f"{slug}/languages/{slug}.pot"}
    missing = sorted(required.difference(names))
    if missing:
        fail("required identity files", ", ".join(missing))

    allowed_top_files = {"LICENSE", "VERSION", "index.php", f"{slug}.php", "readme.txt"}
    allowed_top_dirs = {"admin", "assets", "includes", "languages"}
    forbidden_components = {
        ".git",
        ".github",
        "docs",
        "freemius",
        "node_modules",
        "scripts",
        "tests",
        "vendor",
        "pro",
    }
    forbidden_files = {
        ".distignore",
        ".editorconfig",
        ".gitignore",
        ".phpcs.xml",
        "changelog.md",
        "class-mcpwp-updater.php",
        "claude.md",
        "composer.json",
        "composer.lock",
        "freemius-init.php",
        "mcp_implementation.md",
        "package-lock.json",
        "package.json",
        "phpunit.xml",
        "submission.md",
    }

    for info in infos:
        name = info.filename
        parts = PurePosixPath(name.rstrip("/")).parts
        relative = parts[1:]
        lowered = tuple(part.lower() for part in relative)
        if forbidden_components.intersection(lowered):
            fail("private path exclusion", name)
        if relative:
            basename = relative[-1].lower()
            if basename in forbidden_files or basename.endswith((".md", ".zip", ".sh")):
                fail("private/build file exclusion", name)
            top = relative[0]
            if len(relative) == 1:
                if info.is_dir() and top not in allowed_top_dirs:
                    fail("unexpected top-level directory", name)
                if not info.is_dir() and top not in allowed_top_files:
                    fail("unexpected top-level file", name)
            if len(relative) > 1 and top not in allowed_top_dirs:
                fail("unexpected top-level directory", name)

    pot_files = sorted(name for name in names if name.endswith(".pot"))
    expected_pot = [f"{slug}/languages/{slug}.pot"]
    if pot_files != expected_pot:
        fail("translation catalog identity", f"expected {expected_pot}, found {pot_files}")

    try:
        main = archive.read(main_name).decode("utf-8", errors="strict")
        readme = archive.read(readme_name).decode("utf-8", errors="strict")
    except UnicodeDecodeError as error:
        fail("UTF-8 identity files", str(error))

    def header_value(header):
        match = re.search(rf"^\s*\*\s*{re.escape(header)}:\s*(.*?)\s*$", main, re.MULTILINE)
        return match.group(1) if match else ""

    if header_value("Plugin Name") != "Mumega MCP":
        fail("Plugin Name header", repr(header_value("Plugin Name")))
    if header_value("Text Domain") != slug:
        fail("Text Domain header", repr(header_value("Text Domain")))
    if not readme.startswith("=== Mumega MCP ===\n"):
        fail("readme header", repr(readme.splitlines()[0] if readme else ""))

    header_version = header_value("Version")
    constant = re.search(r"define\(\s*'MCPWP_VERSION'\s*,\s*'([^']+)'\s*\)", main)
    stable_tag = re.search(r"^Stable tag:\s*(\S+)\s*$", readme, re.MULTILINE | re.IGNORECASE)
    if not header_version or constant is None or stable_tag is None:
        fail("version declarations", "header, constant, and stable tag must all exist")
    versions = (header_version, constant.group(1), stable_tag.group(1))
    if len(set(versions)) != 1:
        fail("version equality", f"header/constant/stable-tag={versions}")

    expected_filename = f"{slug}-{header_version}.zip"
    actual_filename = os.path.basename(zip_path)
    if actual_filename != expected_filename:
        fail("artifact filename version", f"expected {expected_filename}, found {actual_filename}")

    legacy_domains = []
    for name in names:
        if name.endswith(".php") and has_legacy_gettext_domain(archive.read(name)):
            legacy_domains.append(name)
    if legacy_domains:
        fail("legacy gettext domain", ", ".join(legacy_domains))

    for name in names:
        if not name.endswith(".php"):
            continue
        contents = archive.read(name).lower()
        if b"fs_dynamic_init" in contents or b"freemius::" in contents:
            fail("Freemius implementation exclusion", name)

print(f"[OK] WordPress.org artifact verified: {zip_path}")
PY
