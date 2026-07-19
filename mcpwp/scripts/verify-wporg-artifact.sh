#!/usr/bin/env bash
# Fail-closed contract for the WordPress.org artifact.

set -euo pipefail

if [[ $# -ne 1 ]]; then
	echo "[FAIL] usage: $0 <zip>" >&2
	exit 1
fi

ZIP_PATH="$1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POLICY_PATH="$SCRIPT_DIR/wporg-artifact-policy.json"
if [[ ! -f "$ZIP_PATH" ]]; then
	echo "[FAIL] candidate ZIP does not exist: $ZIP_PATH" >&2
	exit 1
fi
if [[ ! -f "$POLICY_PATH" ]]; then
	echo "[FAIL] shared artifact policy does not exist: $POLICY_PATH" >&2
	exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
	echo "[FAIL] python3 is required to verify the artifact" >&2
	exit 1
fi

python3 - "$ZIP_PATH" "$POLICY_PATH" <<'PY'
import hashlib
import json
import os
import re
import stat
import sys
import unicodedata
import zipfile
from pathlib import PurePosixPath

zip_path = sys.argv[1]
policy_path = sys.argv[2]
slug = "mumega-mcp"
main_name = f"{slug}/{slug}.php"
readme_name = f"{slug}/readme.txt"

try:
    with open(policy_path, "r", encoding="utf-8") as policy_file:
        policy = json.load(policy_file)
except (OSError, ValueError) as error:
    print(f"[FAIL] shared artifact policy: {error}", file=sys.stderr)
    raise SystemExit(1)


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


def mask_allowed_cleanup(relative, contents):
    for quoted in policy.get("lifecycle_cleanup_exceptions", {}).get(relative, []):
        token = quoted.strip("'\"")
        functions = r"wp_clear_scheduled_hook"
        if relative == "uninstall.php":
            functions += r"|delete_option|delete_transient"
        contents = re.sub(
            rf"\b(?:{functions})\s*\(\s*(['\"]){re.escape(token)}\1\s*\)",
            "__allowed_cleanup__",
            contents,
            flags=re.IGNORECASE,
        )
        if relative == "uninstall.php":
            prefix = r"(?:\{\$wpdb->prefix\})?"
            contents = re.sub(
                rf"DROP\s+TABLE\s+IF\s+EXISTS\s+{prefix}{re.escape(token)}(?=\s*(?:[\"';)]|$))",
                "__allowed_drop__",
                contents,
                flags=re.IGNORECASE,
            )
    return contents


def mask_documentation_negative_disclosures(relative, contents):
    for disclosure in policy.get("documentation_negative_disclosures", {}).get(relative, []):
        contents = re.sub(re.escape(disclosure), "__allowed_negative_disclosure__", contents, flags=re.IGNORECASE)
    return contents


def normalize_static_string_expressions(contents):
    comment = r"(?:(?:/\*[^*]*(?:\*(?!/)[^*]*)*\*/|//[^\r\n]*(?:\r\n|\r|\n)|\#[^\r\n]*(?:\r\n|\r|\n))\s*)*"
    pattern = re.compile(r"(['\"])([^'\"\r\n]*)\1\s*" + comment + r"\.\s*" + comment + r"(['\"])([^'\"\r\n]*)\3")
    while True:
        normalized = pattern.sub(lambda match: "'" + match.group(2) + match.group(4) + "'", contents)
        if normalized == contents:
            return normalized
        contents = normalized


def strip_comments(contents, hash_comments=False, line_comments=True):
    stripped = []
    index = 0
    while index < len(contents):
        char = contents[index]
        if char in "'\"":
            closing = min(skip_quoted(contents, index), len(contents))
            stripped.append(contents[index:closing])
            index = closing
            continue
        if contents.startswith("/*", index):
            closing = contents.find("*/", index + 2)
            index = len(contents) if closing < 0 else closing + 2
            stripped.append(" ")
            continue
        if (line_comments and contents.startswith("//", index)) or (hash_comments and char == "#" and not contents.startswith("#[", index)):
            newline = contents.find("\n", index + 1)
            if newline < 0:
                stripped.append(" ")
                break
            stripped.append("\n")
            index = newline + 1
            continue
        stripped.append(char)
        index += 1
    return "".join(stripped)


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

    folded_names = {}
    for name in names:
        folded = unicodedata.normalize("NFKC", name).casefold()
        folded_names.setdefault(folded, []).append(name)
    collisions = [sorted(values) for values in folded_names.values() if len(values) > 1]
    if collisions:
        fail("case-colliding entry", repr(collisions))

    for name in names:
        if name != str(PurePosixPath(name)):
            fail("canonical manifest path", name)

    if names != sorted(names, key=lambda name: name.encode("utf-8")):
        fail("sorted manifest", "ZIP entries are not in bytewise lexical order")

    for info in infos:
        name = info.filename
        path = PurePosixPath(name)
        if name.startswith("/") or "\\" in name or ".." in path.parts:
            fail("unsafe manifest path", name)

        mode = info.external_attr >> 16
        if stat.S_ISLNK(mode):
            fail("symlink ZIP entry", name)
        if not stat.S_ISREG(mode):
            fail("non-regular ZIP entry", name)

    roots = sorted({name.split("/", 1)[0] for name in names})
    if roots != [slug]:
        fail("single top-level root", f"expected {slug}/, found {roots}")
    if any(not name.startswith(f"{slug}/") for name in names):
        fail("rooted manifest", "every entry must be below the top-level plugin folder")
    if main_name not in names:
        fail("main file identity", f"missing {main_name}")

    allowed_top_files = {"LICENSE", "VERSION", "index.php", f"{slug}.php", "readme.txt", "uninstall.php"}
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
        parts = PurePosixPath(name).parts
        relative = parts[1:]
        lowered = tuple(part.lower() for part in relative)
        if forbidden_components.intersection(lowered):
            fail("private path exclusion", name)
        if not relative:
            continue
        basename = relative[-1].lower()
        if basename in forbidden_files or basename.endswith((".md", ".zip", ".sh")):
            fail("private/build file exclusion", name)
        if "." not in basename and basename not in {"license", "version"}:
            fail("extensionless executable", name)
        top = relative[0]
        if len(relative) == 1 and top not in allowed_top_files:
            fail("unexpected top-level file", name)
        if len(relative) > 1 and top not in allowed_top_dirs:
            fail("unexpected top-level directory", name)

    expected_runtime = set(policy["allowed_runtime_files"])
    expected_runtime.remove("mcpwp.php")
    expected_runtime.add(f"{slug}.php")
    expected_manifest = sorted(
        (f"{slug}/{relative}" for relative in expected_runtime),
        key=lambda name: name.encode("utf-8"),
    )
    if names != expected_manifest:
        unexpected_runtime = sorted(set(names).difference(expected_manifest))
        missing_runtime = sorted(set(expected_manifest).difference(names))
        fail(
            "exclusive runtime inventory",
            f"unexpected={unexpected_runtime}; missing={missing_runtime}; count={len(names)}/{len(expected_manifest)}",
        )

    execution_pattern = re.compile(
        r"\b(?:eval|assert|shell_exec|exec|system|passthru|proc_open|popen)\s*\(",
        flags=re.IGNORECASE,
    )
    dynamic_include_pattern = re.compile(
        r"\b(?:include|include_once|require|require_once)\s*(?:\(\s*)?(?:\$|(?:download_url|wp_remote_retrieve_body|file_get_contents)\s*\()",
        flags=re.IGNORECASE,
    )
    package_pattern = re.compile(
        r"\b(?:Plugin_Upgrader|Theme_Upgrader|Core_Upgrader|Language_Pack_Upgrader|plugins_api|themes_api|wp_update_plugins|wp_update_themes|delete_plugins|delete_theme|activate_plugin|activate_plugins|deactivate_plugin|deactivate_plugins)\b",
        flags=re.IGNORECASE,
    )
    outbound_pattern = re.compile(
        r"\b(?:curl_init|curl_exec|fsockopen|pfsockopen|socket_create|socket_connect|stream_socket_client|wp_remote_get|wp_remote_post|wp_remote_request)\s*\(",
        flags=re.IGNORECASE,
    )
    url_host_pattern = re.compile(r"https?://([a-z0-9.-]+)", flags=re.IGNORECASE)
    fixed_host_pattern = re.compile(
        r"\b(?:[a-z0-9-]+\.)+(?:com|net|org|dev|io|ai|co|ca|app|cloud)\b",
        flags=re.IGNORECASE,
    )
    remote_authority_pattern = re.compile(
        r"(?:https?:)?//(?:[^\s/'\"]+@)?[a-z0-9.-]+\.[a-z]{2,}",
        flags=re.IGNORECASE,
    )
    remote_asset_authority_pattern = re.compile(
        r"(?:https?:)?//(?:\[[^\]\s]+\]|[^/\s'\"()]+)",
        flags=re.IGNORECASE,
    )
    allowed_local_include_statements = {
        "include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-tools-display.php';",
        "include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-settings-display.php';",
        "include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-setup-display.php';",
        "require_once ABSPATH . 'wp-admin/includes/upgrade.php';",
        "require_once ABSPATH . 'wp-admin/includes/file.php';",
        "require_once ABSPATH . 'wp-admin/includes/image.php';",
        "require_once ABSPATH . 'wp-admin/includes/media.php';",
        "require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-activator.php';",
        "require_once plugin_dir_path( __FILE__ ) . 'includes/traits/trait-mcpwp-api-auth.php';",
        "require_once plugin_dir_path( __FILE__ ) . 'includes/class-mcpwp-activator.php';",
        "require_once plugin_dir_path( __FILE__ ) . 'includes/class-mcpwp-deactivator.php';",
    }
    allowed_writer_count = 0

    for name in names:
        lowered_name = name.lower()
        if not lowered_name.endswith((".php", ".js", ".css")):
            continue
        contents = archive.read(name).decode("utf-8", errors="surrogateescape")
        executable_contents = strip_comments(
            contents,
            hash_comments=lowered_name.endswith(".php"),
            line_comments=not lowered_name.endswith(".css"),
        )
        normalized_contents = normalize_static_string_expressions(executable_contents)

        if lowered_name.endswith((".js", ".css")):
            if remote_asset_authority_pattern.search(normalized_contents):
                fail("remote runtime asset", name)
            continue

        static_bootstrap_inventory = False
        if name == main_name:
            bootstrap_match = re.search(
                r"\$files\s*=\s*array\((.*?)\)\s*;\s*foreach\s*\(\s*\$files\s+as\s+\$file\s*\)\s*\{\s*require_once\s+MCPWP_PLUGIN_DIR\s*\.\s*\$file\s*;\s*\}",
                executable_contents,
                flags=re.DOTALL,
            )
            if bootstrap_match is not None:
                bootstrap_body = bootstrap_match.group(1)
                bootstrap_paths = re.findall(r"'([^']+)'", bootstrap_body)
                bootstrap_residual = re.sub(r"'[^']*'", "", bootstrap_body)
                static_bootstrap_inventory = (
                    bool(bootstrap_paths)
                    and re.fullmatch(r"[\s,]*", bootstrap_residual) is not None
                    and len(bootstrap_paths) == len(set(bootstrap_paths))
                    and all(path in expected_runtime and path.endswith(".php") for path in bootstrap_paths)
                )

        for include_match in re.finditer(r"\b(?:include|include_once|require|require_once)\b", executable_contents, flags=re.IGNORECASE):
            statement_end = executable_contents.find(";", include_match.start())
            if statement_end < 0:
                fail("forbidden execution primitive", f"unterminated include in {name}")
            statement = re.sub(r"\s+", " ", executable_contents[include_match.start():statement_end + 1]).strip()
            dynamic_bootstrap = (
                name == main_name
                and static_bootstrap_inventory
                and statement == "require_once MCPWP_PLUGIN_DIR . $file;"
            )
            if statement not in allowed_local_include_statements and not dynamic_bootstrap:
                fail("forbidden execution primitive", f"computed include in {name}")

        for writer_match in re.finditer(
            r"\b(?:file_put_contents|fopen|fwrite|touch|rename|copy)\s*\(",
            executable_contents,
            flags=re.IGNORECASE,
        ):
            opening = executable_contents.find("(", writer_match.start())
            arguments = parse_call_arguments(executable_contents, opening)
            function_name = re.match(r"[a-z_]+", writer_match.group(0), flags=re.IGNORECASE).group(0).lower()
            normalized_arguments = [] if arguments is None else [re.sub(r"\s+", " ", argument).strip() for argument in arguments]
            allowed_writer = (
                name == f"{slug}/includes/core/class-mcpwp-media.php"
                and function_name == "file_put_contents"
                and normalized_arguments == ["$tmp_file", "$decoded"]
            )
            if not allowed_writer:
                fail("forbidden arbitrary code writer", name)
            allowed_writer_count += 1

        if execution_pattern.search(executable_contents) or dynamic_include_pattern.search(executable_contents):
            fail("forbidden execution primitive", name)
        if package_pattern.search(executable_contents):
            fail("forbidden package operation", name)

        if outbound_pattern.search(executable_contents):
            fail("forbidden outbound primitive", name)

        hosts = {match.lower().rstrip(".") for match in url_host_pattern.findall(normalized_contents)}
        hosts.update(match.lower().rstrip(".") for match in fixed_host_pattern.findall(normalized_contents))
        if hosts or remote_authority_pattern.search(normalized_contents):
            fail("fixed external host", f"{name}: {sorted(hosts)}")

    if allowed_writer_count != 1:
        fail("local media writer allowlist", f"expected exactly one retained temp write, found {allowed_writer_count}")

    forbidden_directories = {item.lower() for item in policy["forbidden_directory_components"]}
    forbidden_basenames = {item.lower() for item in policy["forbidden_basenames"]}
    forbidden_prefixes = tuple(item.lower() for item in policy["forbidden_basename_prefixes"])
    textual_suffixes = (".php", ".js", ".css", ".pot", ".txt")
    for info in infos:
        normalized = info.filename.replace("\\", "/")
        parts = tuple(part.lower() for part in PurePosixPath(normalized.rstrip("/")).parts)
        relative_parts = parts[1:]
        if forbidden_directories.intersection(relative_parts[:-1] if relative_parts else ()):
            fail("shared policy forbidden directory", info.filename)
        basename = relative_parts[-1] if relative_parts else ""
        if basename in forbidden_basenames or basename.startswith(forbidden_prefixes):
            fail("shared policy forbidden basename", info.filename)
        if info.is_dir() or not normalized.lower().endswith(textual_suffixes):
            continue
        relative = "/".join(PurePosixPath(normalized).parts[1:])
        contents = archive.read(info.filename).decode("utf-8", errors="surrogateescape")
        contents = mask_allowed_cleanup(relative, contents)
        contents = mask_documentation_negative_disclosures(relative, contents)
        contents += "\n" + normalize_static_string_expressions(contents)
        lowered_contents = contents.lower()
        for literal in policy["forbidden_literals"]:
            if literal.lower() in lowered_contents:
                fail("shared policy forbidden literal", f"{literal} in {info.filename}")
        for pattern in policy.get("forbidden_patterns", []):
            if re.search(pattern, contents, flags=re.IGNORECASE):
                fail("shared policy forbidden pattern", f"{pattern} in {info.filename}")

    required = {main_name, readme_name, f"{slug}/languages/{slug}.pot"}
    for relative in policy["required_source_files"]:
        if relative == "mcpwp.php":
            required.add(main_name)
        else:
            required.add(f"{slug}/{relative}")
    missing = sorted(required.difference(names))
    if missing:
        fail("required identity files", ", ".join(missing))

    pot_files = sorted(name for name in names if name.endswith(".pot"))
    expected_pot = [f"{slug}/languages/{slug}.pot"]
    if pot_files != expected_pot:
        fail("translation catalog identity", f"expected {expected_pot}, found {pot_files}")

    try:
        main = archive.read(main_name).decode("utf-8", errors="strict")
        readme = archive.read(readme_name).decode("utf-8", errors="strict")
        version_file = archive.read(f"{slug}/VERSION").decode("utf-8", errors="strict").strip()
    except KeyError as error:
        fail("version declarations", f"missing {error}")
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
    versions = (header_version, constant.group(1), stable_tag.group(1), version_file)
    if len(set(versions)) != 1:
        fail("version equality", f"header/constant/stable-tag/VERSION={versions}")
    expected_version = policy.get("expected_version")
    if expected_version != "3.4.14":
        fail("shared artifact policy", f"expected_version must be 3.4.14, found {expected_version!r}")
    if any(version != expected_version for version in versions):
        fail("expected release version", f"expected {expected_version}, found {versions}")

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

    tool_source_name = f"{slug}/includes/mcp/class-mcpwp-mcp-free-tools.php"
    tool_source = archive.read(tool_source_name).decode("utf-8", errors="strict")
    tool_matches = re.findall(
        r"'(wp_[a-z0-9_]+)'\s*=>\s*(?:array\s*\(|\[)\s*'method'\s*=>\s*'([A-Z]+)'\s*,\s*'route'\s*=>\s*'([^']+)'",
        tool_source,
    )
    actual_tools = {name: [method, route] for name, method, route in tool_matches}
    if actual_tools != policy["retained_tool_map"]:
        fail("exact MCP tool map", f"expected={policy['retained_tool_map']}; found={actual_tools}")

    expected_tool_names = set(policy["retained_tool_map"])
    description_match = re.search(
        r"\$descriptions\s*=\s*array\((.*?)\);\s*\$tools",
        tool_source,
        flags=re.DOTALL,
    )
    if description_match is None:
        fail("MCP advertised descriptions", "could not extract get_tools descriptions")
    description_names = set(re.findall(r"'(wp_[a-z0-9_]+)'\s*=>", description_match.group(1)))
    if description_names != expected_tool_names:
        fail("exact MCP advertised descriptions", f"expected={sorted(expected_tool_names)}; found={sorted(description_names)}")

    trait_names = set()
    trait_files = sorted(
        name
        for name in names
        if name.startswith(f"{slug}/includes/mcp/traits/trait-mcpwp-free-tools-") and name.endswith(".php")
    )
    if not trait_files:
        fail("MCP trait inventory", "no retained tool-name traits found")
    for trait_file in trait_files:
        trait_source = archive.read(trait_file).decode("utf-8", errors="strict")
        inventories = re.findall(
            r"function\s+mcpwp_[a-z0-9_]+_tool_names\s*\(\s*\)\s*\{\s*return\s+array\((.*?)\);\s*\}",
            trait_source,
            flags=re.DOTALL,
        )
        if not inventories:
            fail("MCP trait inventory", trait_file)
        for inventory in inventories:
            trait_names.update(re.findall(r"'(wp_[a-z0-9_]+)'", inventory))
    if trait_names != expected_tool_names:
        fail("exact MCP trait-advertised tools", f"expected={sorted(expected_tool_names)}; found={sorted(trait_names)}")

    method_constants = {
        "WP_REST_Server::READABLE": ["GET"],
        "WP_REST_Server::CREATABLE": ["POST"],
        "WP_REST_Server::EDITABLE": ["POST", "PUT", "PATCH"],
        "WP_REST_Server::DELETABLE": ["DELETE"],
    }
    actual_routes = {}
    for name in names:
        if not name.startswith(f"{slug}/") or not name.endswith(".php"):
            continue
        source = archive.read(name).decode("utf-8", errors="strict")
        if re.search(r"\bregister_rest_route\s*\(", source) and re.search(r"['\"]__return_true['\"]", source):
            fail("public REST permission callback", name)
        for match in re.finditer(r"\bregister_rest_route\s*\(", source):
            arguments = parse_call_arguments(source, source.find("(", match.start()))
            if not arguments or len(arguments) < 3:
                fail("REST route extraction", name)
            route = literal_string_value(arguments[1])
            if route is None:
                fail("REST route literal", name)
            values = re.findall(
                r"['\"]methods['\"]\s*=>\s*(WP_REST_Server::[A-Z]+|['\"][A-Z,]+['\"])",
                arguments[2],
            )
            values.extend(re.findall(
                r"\broute_definition\s*\(\s*(WP_REST_Server::[A-Z]+|['\"][A-Z,]+['\"])",
                arguments[2],
            ))
            if not values:
                fail("REST route methods", f"{name}:{route}")
            for value in values:
                methods = method_constants.get(value)
                if methods is None:
                    methods = value.strip("'\"").split(",")
                actual_routes.setdefault(route, set()).update(methods)
    actual_routes = {route: sorted(methods) for route, methods in actual_routes.items()}
    expected_routes = {route: sorted(methods) for route, methods in policy["retained_rest_routes"].items()}
    if actual_routes != expected_routes:
        fail("exact REST route map", f"expected={expected_routes}; found={actual_routes}")

    expected_trusted_sources = {
        "includes/api/class-mcpwp-rest-api.php",
        "includes/api/class-mcpwp-rest-api-keys.php",
        "includes/api/class-mcpwp-rest-mcp.php",
        "includes/api/class-mcpwp-rest-media.php",
        "includes/api/class-mcpwp-rest-pages.php",
        "includes/api/class-mcpwp-rest-posts.php",
        "includes/api/class-mcpwp-rest-rate-limit.php",
        "includes/api/class-mcpwp-rest-site-info.php",
        "includes/api/class-mcpwp-rest-site-settings.php",
        "includes/api/class-mcpwp-rest-site-taxonomy.php",
        "includes/traits/trait-mcpwp-api-auth.php",
        "includes/mcp/class-mcpwp-mcp-tool-registry.php",
        "includes/mcp/class-mcpwp-mcp-free-tools.php",
    }
    expected_trusted_sources.update(
        "/".join(PurePosixPath(name).parts[1:])
        for name in names
        if name.startswith(f"{slug}/includes/mcp/traits/trait-mcpwp-free-tools-") and name.endswith(".php")
    )
    trusted_sources = policy.get("trusted_source_sha256", {})
    if set(trusted_sources) != expected_trusted_sources:
        fail(
            "trusted source policy",
            f"expected={sorted(expected_trusted_sources)}; found={sorted(trusted_sources)}",
        )
    for relative, expected_hash in trusted_sources.items():
        trusted_name = f"{slug}/{relative}"
        if trusted_name not in names:
            fail("trusted source", f"missing {trusted_name}")
        actual_hash = hashlib.sha256(archive.read(trusted_name)).hexdigest()
        if actual_hash != expected_hash:
            fail("trusted source hash", f"{relative}: expected={expected_hash}; found={actual_hash}")

    for name in names:
        if not name.endswith(".php"):
            continue
        contents = archive.read(name).lower()
        if b"fs_dynamic_init" in contents or b"freemius::" in contents:
            fail("Freemius implementation exclusion", name)

print(f"[OK] WordPress.org artifact verified: {zip_path}")
PY
