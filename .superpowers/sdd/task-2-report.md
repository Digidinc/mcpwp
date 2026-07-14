# Task 2 report — 3.4.13 minimal WordPress.org candidate

Date: 2026-07-14 (America/Toronto)

## Scope

Updated only the minimal candidate's release metadata and exact artifact references from `3.4.3` to `3.4.13`. The release notes describe only the already-landed default read/write API-key behavior for draft-first content testing. No excluded full-product surface was merged or enabled; `version.json` was intentionally not changed because it belongs to the excluded self-updater surface.

## Test-driven change

Added `WporgArtifactPolicyTest::test_release_metadata_and_generated_zip_name_are_pinned_to_3_4_13`.

- Red: PHP 7.4 container run against the frozen `mumega-mcp-3.4.3.zip` failed on the source header, which was still `3.4.3`.
- Green: the focused test passed with 1 test and 6 assertions after the metadata/reference update and 3.4.13 build.

## Immutable candidate

- File: `mcpwp/scripts/mumega-mcp-3.4.13.zip`
- SHA-256: `4ff90b406b0a6343a4d511b374b00ecb281c5f7211d1a348bf0bbf3d116c4fb1`
- Size: 85,422 bytes
- Entries: 62
- Manifest SHA-256: `13dd5d0abea3be56512432abb588de2db5d727a928280a608e2df2762c2cfd94`
- Determinism: two clean builds produced the same candidate SHA.

## Verification results

- `bash mcpwp/scripts/build-wporg.sh`: PASS, including exact ZIP verifier.
- Focused 3.4.13 identity test in PHP 7.4: PASS, 1 test / 6 assertions.
- Full PHPUnit against the frozen ZIP in PHP 7.4: PASS, 352 tests / 4,168 assertions.
- Full PHPUnit against the frozen ZIP in PHP 8.3: PASS, 352 tests / 4,168 assertions. Existing `ReflectionProperty::setValue()` deprecation notices were emitted, but the command exited successfully.
- Extracted exact ZIP syntax lint: PASS for 57 shipped PHP files in PHP 7.4 and PHP 8.3.
- WPCS/PHPCompatibilityWP on the exact ZIP: PASS, 0 errors and 40 reviewed warnings in 6 files.
- Clean-install lifecycle/no-outbound: PASS in both pinned lanes, WordPress 6.1/PHP 7.4 and WordPress 7.0/PHP 8.3, with 43/43 TAP checks each.
- Official Plugin Check 2.0.0 on the ZIP-installed candidate: PASS, 0 errors and 36 reviewed warnings; strict-JSON SHA-256 `97ecd09f9fce06c8ae82d763d2d392638af5f55c12eb3e0bb021c9f996d71df5`.

## Commits

- `69650f6f49f81437ef052ba82039bad6b586d87e` — version and exact-artifact source/reference update.
- The documentation/evidence commit is recorded after this report is staged.

## Constraints observed

- No upload, email, merge, push, PR status change, or WordPress.org submission was performed.
- The pre-existing untracked plan `docs/superpowers/plans/2026-07-14-wporg-3413-write-key-release.md` was left untouched and is excluded from commits.
