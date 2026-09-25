"""Exercise compatibility validation through the real release-check CLI."""

import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
CHECK = ROOT / "scripts" / "verify_release_compatibility.py"


class ReleaseCompatibilityTest(unittest.TestCase):
    def check_metadata(self, requires="6.1", requires_php="7.4", plugin_wp="6.1", plugin_php="7.4", readme_wp=None, readme_php="7.4"):
        with tempfile.TemporaryDirectory(prefix="mcpwp-compat-test-") as directory:
            root = Path(directory)
            manifest = root / "version.json"
            manifest.write_text(json.dumps({"version": "3.13.0", "requires": requires, "requires_php": requires_php}), encoding="utf-8")
            plugin = root / "mcpwp.php"
            plugin.write_text(f"<?php\n/**\n * Plugin Name: MCPWP\n * Version: 3.13.0\n * Requires at least: {plugin_wp}\n * Requires PHP: {plugin_php}\n */\n", encoding="utf-8")
            command = [sys.executable, str(CHECK), "--manifest", str(manifest), "--plugin", str(plugin)]
            if readme_wp is not None:
                readme = root / "readme.txt"
                readme.write_text(f"=== MCPWP ===\nRequires at least: {readme_wp}\nRequires PHP: {readme_php}\n", encoding="utf-8")
                command.extend(["--readme", str(readme)])
            return subprocess.run(command, text=True, capture_output=True, check=False)

    def test_matching_minimums_pass(self):
        result = self.check_metadata(readme_wp="6.1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("WordPress >= 6.1", result.stdout)

    def test_manifest_cannot_offer_package_to_older_wordpress(self):
        result = self.check_metadata(requires="5.0")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("manifest requires=5.0", result.stderr)
        self.assertIn("plugin Requires at least=6.1", result.stderr)

    def test_php_drift_and_readme_drift_fail(self):
        self.assertNotEqual(0, self.check_metadata(requires_php="7.0").returncode)
        self.assertNotEqual(0, self.check_metadata(readme_wp="5.0").returncode)
        self.assertNotEqual(0, self.check_metadata(readme_wp="6.1", readme_php="7.0").returncode)

    def test_missing_or_malformed_requirements_fail(self):
        for value in (None, "", "latest", "6.1<script>", 6.1):
            with self.subTest(value=value):
                result = self.check_metadata(requires=value)
                self.assertNotEqual(0, result.returncode)
                self.assertIn("requires", result.stderr)
        self.assertNotEqual(0, self.check_metadata(plugin_wp="").returncode)
        self.assertNotEqual(0, self.check_metadata(plugin_php="").returncode)

    def test_semantically_equal_minimum_versions_pass(self):
        result = self.check_metadata(requires="6.1.0", requires_php="7.4.0")
        self.assertEqual(0, result.returncode, result.stderr)

    def test_repository_release_requirements_agree(self):
        result = subprocess.run([sys.executable, str(CHECK), "--manifest", str(ROOT / "version.json"), "--plugin", str(ROOT / "mcpwp/mcpwp.php"), "--readme", str(ROOT / "mcpwp/readme.txt")], text=True, capture_output=True, check=False)
        self.assertEqual(0, result.returncode, result.stderr)

    def test_zip_headers_are_checked_without_extraction_or_php_execution(self):
        with tempfile.TemporaryDirectory(prefix="mcpwp-compat-zip-") as directory:
            root = Path(directory)
            manifest = root / "version.json"
            manifest.write_text(json.dumps({"version": "3.13.0", "requires": "5.0", "requires_php": "7.4"}), encoding="utf-8")
            package = root / "package.zip"
            with zipfile.ZipFile(package, "w") as archive:
                archive.writestr("mcpwp/mcpwp.php", "<?php\n/*\nVersion: 3.13.0\nRequires at least: 6.1\nRequires PHP: 7.4\n*/\nthrow new Exception('must never run');")
                archive.writestr("mcpwp/readme.txt", "Requires at least: 6.1\nRequires PHP: 7.4\n")
            result = subprocess.run([sys.executable, str(CHECK), "--manifest", str(manifest), "--zip", str(package)], text=True, capture_output=True, check=False)
            self.assertNotEqual(0, result.returncode)
            self.assertIn("plugin Requires at least=6.1", result.stderr)
            self.assertFalse((root / "mcpwp").exists())

    def test_stale_agency_zip_fails_even_with_matching_minimum_requirements(self):
        with tempfile.TemporaryDirectory(prefix="mcpwp-agency-compat-") as directory:
            root = Path(directory)
            manifest = root / "version.json"
            manifest.write_text(json.dumps({"version": "3.13.0", "requires": "6.1", "requires_php": "7.4"}), encoding="utf-8")
            package = root / "mcpwp-agency-3.13.0.zip"
            with zipfile.ZipFile(package, "w") as archive:
                archive.writestr("mcpwp/mcpwp.php", "<?php\n/*\nVersion: 3.12.0\nRequires at least: 6.1\nRequires PHP: 7.4\n*/\n")
                archive.writestr("mcpwp/readme.txt", "Requires at least: 6.1\nRequires PHP: 7.4\n")
            result = subprocess.run([sys.executable, str(CHECK), "--manifest", str(manifest), "--zip", str(package)], text=True, capture_output=True, check=False)
            self.assertNotEqual(0, result.returncode)
            self.assertIn("Version mismatch", result.stderr)

    def test_channel_verifier_refuses_compatible_version_with_incompatible_minimum(self):
        with tempfile.TemporaryDirectory(prefix="mcpwp-channel-test-") as directory:
            root = Path(directory)
            manifest = root / "version.json"
            manifest.write_text(json.dumps({"version": "3.13.0", "download_url": "https://updates.mcpwp.net/mcpwp-latest.zip", "requires": "5.0", "requires_php": "7.4"}), encoding="utf-8")
            package = root / "package.zip"
            with zipfile.ZipFile(package, "w") as archive:
                archive.writestr("mcpwp/mcpwp.php", "<?php\n/*\nVersion: 3.13.0\nRequires at least: 6.1\nRequires PHP: 7.4\n*/\n")
                archive.writestr("mcpwp/readme.txt", "Requires at least: 6.1\nRequires PHP: 7.4\n")
                archive.writestr("mcpwp/includes/class-mcpwp-updater.php", "<?php\n")
            binary = root / "bin"
            binary.mkdir()
            curl = binary / "curl"
            curl.write_text('''#!/bin/sh
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o) target="$2"; shift 2 ;;
    https://*) url="$1"; shift ;;
    *) shift ;;
  esac
done
case "$url" in
  */version.json) cp "$TEST_MANIFEST" "$target" ;;
  */mcpwp-latest.zip) cp "$TEST_PACKAGE" "$target" ;;
  *) exit 1 ;;
esac
printf 200
''', encoding="utf-8")
            curl.chmod(0o700)
            environment = dict(os.environ, PATH=str(binary) + os.pathsep + os.environ["PATH"], TEST_MANIFEST=str(manifest), TEST_PACKAGE=str(package))
            result = subprocess.run(["bash", str(ROOT / "scripts/verify_update_channel.sh"), "--manifest-url", "https://updates.mcpwp.net/version.json"], env=environment, text=True, capture_output=True, check=False)
            self.assertNotEqual(0, result.returncode, result.stdout)
            self.assertIn("Compatibility mismatch", result.stderr)
            manifest.write_text(json.dumps({"version": "3.13.0", "download_url": "https://updates.mcpwp.net/mcpwp-latest.zip", "requires": "6.1", "requires_php": "7.4"}), encoding="utf-8")
            corrected = subprocess.run(["bash", str(ROOT / "scripts/verify_update_channel.sh"), "--manifest-url", "https://updates.mcpwp.net/version.json"], env=environment, text=True, capture_output=True, check=False)
            self.assertEqual(0, corrected.returncode, corrected.stderr)
            self.assertIn("OK: channel can deliver 3.13.0", corrected.stdout)


if __name__ == "__main__":
    unittest.main()
