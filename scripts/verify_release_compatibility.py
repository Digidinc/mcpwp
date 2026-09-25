"""Refuse release metadata that disagrees with source or packaged headers."""

import argparse
import json
from pathlib import Path
import re
import sys
import zipfile


def version_parts(value, label):
    if not isinstance(value, str) or not re.fullmatch(r"\d+(?:\.\d+){1,3}", value):
        raise ValueError(f"{label} is missing or is not a numeric minimum version")
    parts = tuple(int(part) for part in value.split("."))
    while len(parts) > 1 and parts[-1] == 0:
        parts = parts[:-1]
    return parts


def header(text, label):
    # WordPress reads plugin metadata from the first 8 KiB. Do not inspect
    # executable code farther down the file as if it were a plugin header.
    match = re.search(r"^[ \t/*#@]*" + re.escape(label) + r":[ \t]*([^\n]*)", text.replace("\r", "\n"), re.MULTILINE | re.IGNORECASE)
    if not match:
        raise ValueError(f"Missing {label} header")
    return re.sub(r"\s*(?:\*/|\?>).*", "", match.group(1)).strip()


def read_header_file(path):
    with path.open("rb") as stream:
        return stream.read(8192).decode("utf-8", errors="replace")


def validate(manifest, plugin, readme=None):
    if not isinstance(manifest, dict):
        raise ValueError("Release manifest must be a JSON object")
    advertised_version = manifest.get("version")
    packaged_version = header(plugin, "Version")
    if not isinstance(advertised_version, str) or not advertised_version or advertised_version != packaged_version:
        raise ValueError(f"Version mismatch: manifest version={advertised_version}; plugin Version={packaged_version}")
    for key, label in (("requires", "Requires at least"), ("requires_php", "Requires PHP")):
        advertised = manifest.get(key)
        packaged = header(plugin, label)
        if version_parts(advertised, f"manifest {key}") != version_parts(packaged, f"plugin {label}"):
            raise ValueError(f"Compatibility mismatch: manifest {key}={advertised}; plugin {label}={packaged}")
        if readme is not None:
            documented = header(readme, label)
            if version_parts(documented, f"readme {label}") != version_parts(packaged, f"plugin {label}"):
                raise ValueError(f"Compatibility mismatch: readme {label}={documented}; plugin {label}={packaged}")
    return f"OK: WordPress >= {header(plugin, 'Requires at least')}; PHP >= {header(plugin, 'Requires PHP')}"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path, required=True)
    inputs = parser.add_mutually_exclusive_group(required=True)
    inputs.add_argument("--plugin", type=Path)
    inputs.add_argument("--zip", type=Path)
    parser.add_argument("--readme", type=Path)
    args = parser.parse_args()
    if args.zip and args.readme:
        parser.error("--zip reads its own readme; do not also pass --readme")
    try:
        manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
        if args.zip:
            with zipfile.ZipFile(args.zip) as archive:
                mains = [name for name in archive.namelist() if name.count("/") == 1 and name.endswith("/mcpwp.php")]
                if len(mains) != 1:
                    raise ValueError("Package must contain exactly one root/mcpwp.php")
                with archive.open(mains[0]) as stream:
                    plugin = stream.read(8192).decode("utf-8", errors="replace")
                with archive.open(mains[0].rsplit("/", 1)[0] + "/readme.txt") as stream:
                    readme = stream.read(8192).decode("utf-8", errors="replace")
        else:
            plugin = read_header_file(args.plugin)
            readme = read_header_file(args.readme) if args.readme else None
        print(validate(manifest, plugin, readme))
        return 0
    except (OSError, ValueError, KeyError, zipfile.BadZipFile, RuntimeError) as error:
        print(f"FAIL: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
