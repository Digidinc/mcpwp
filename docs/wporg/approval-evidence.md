# WordPress.org approval evidence

Evidence date: 2026-07-14 (America/Toronto)

This record applies only to the immutable WordPress.org candidate described below. Repository-only evidence, tests, development dependencies, and CI files are excluded from the ZIP. A changed ZIP SHA invalidates this record and requires the complete gate to be rerun.

## Release status

The exact-ZIP verifier, reproducible build, PHP compatibility lint, artifact policy/identity suite, both clean-install lifecycle lanes, official Plugin Check, WPCS review, and anonymous readme-link check pass for the recorded SHA. Plugin Check and PHPCS have reviewed warnings; neither result is represented as zero warnings.

This evidence does **not** by itself authorize upload. The SHA-pinned approval workflow and a final fresh human review of this exact manifest/package remain release go/no-go requirements. No WordPress.org upload, SVN write, GitHub push, or release is evidenced here.

## Immutable candidate

| Field | Recorded value |
| --- | --- |
| File | `mcpwp/scripts/mumega-mcp-3.4.3.zip` |
| Installed directory/main file | `mumega-mcp/mumega-mcp.php` |
| Plugin version | `3.4.3` |
| Candidate SHA-256 | `e9c84443c7c496e1b4ea4f06a75e3db4908273fb8d82e2ff8338a2203b29c7c0` |
| Size | 85,364 bytes |
| ZIP entries | 62 sorted regular files under one `mumega-mcp/` root |
| Manifest | `docs/wporg/mumega-mcp-manifest.txt` |
| Manifest SHA-256 | `13dd5d0abea3be56512432abb588de2db5d727a928280a608e2df2762c2cfd94` |
| Last shipped-file evidence commit | `b06a3f5b590432d5b7435a855d811cc1d491de48` |

The build was run twice from the same tracked shipped source. Both independent outputs had the candidate SHA above. The builder normalizes shipped-file timestamps to 1980-01-01, sorts the input list with `LC_ALL=C`, and invokes Info-ZIP with metadata stripping (`zip -X`).

Reproduction commands:

```bash
bash mcpwp/scripts/build-wporg.sh
first=$(shasum -a 256 mcpwp/scripts/mumega-mcp-3.4.3.zip | awk '{print $1}')
bash mcpwp/scripts/build-wporg.sh
second=$(shasum -a 256 mcpwp/scripts/mumega-mcp-3.4.3.zip | awk '{print $1}')
test "$first" = "$second"
bash mcpwp/scripts/verify-wporg-artifact.sh mcpwp/scripts/mumega-mcp-3.4.3.zip
shasum -a 256 mcpwp/scripts/mumega-mcp-3.4.3.zip
wc -c < mcpwp/scripts/mumega-mcp-3.4.3.zip
unzip -Z1 mcpwp/scripts/mumega-mcp-3.4.3.zip | LC_ALL=C sort > docs/wporg/mumega-mcp-manifest.txt
test "$(unzip -Z1 mcpwp/scripts/mumega-mcp-3.4.3.zip | LC_ALL=C sort | wc -l | tr -d ' ')" = 62
shasum -a 256 docs/wporg/mumega-mcp-manifest.txt
```

Recorded result: both builds produced `e9c84443…`; the verifier returned `[OK]`; size, entry count, and manifest hash matched the table.

## Pinned tools and environments

| Component | Version or immutable reference |
| --- | --- |
| WordPress/PHP floor | `wordpress:6.1.1-php7.4-apache@sha256:7e46cf3373751b6d62b7a0fc3a7d6686f641a34a2a0eb18947da5375c55fd009` |
| Current lane | `wordpress:7.0.1-php8.3-apache@sha256:e36cd332ae0de78f3c3691e468c54c5540c0a74286b51eb9b5964fa1e9c916cd` |
| Database | `mariadb:10.6@sha256:114d40be36852d0a019afd8458f7e1cc63fd300403b115b1f32e31130d5f671b` |
| PHPUnit | 9.6.34 |
| PHP_CodeSniffer | 3.13.5 |
| WordPress Coding Standards | 3.3.0 |
| PHPCompatibilityWP | 2.1.8 (`PHPCompatibility` 9.3.5) |
| Composer platform | PHP 7.4.33 |
| Plugin Check | 2.0.0; official ZIP SHA-256 `d744ee1f93866527aedf7d0a73df40bd87018f02cd5465fa39230bf4c2b3a3fa` |
| WP-CLI used for Plugin Check | 2.12.0; verified signing fingerprint `63AF7AA15067C05616FDDD88A3A2E8F226F0BC06` |
| POT builder | `wordpress:cli@sha256:dac35b8f34c6f4f1fafa0b32342642fa9eecd077bb0a8909b486c108874bd7f1` |
| Local build utilities | Python 3.14.5, Info-ZIP 3.0/UnZip 6.00, Docker 29.4.0, Compose 5.1.2 |

Development package versions are locked in `mcpwp/composer.lock`; Docker image digests are locked in the clean-install harness.

## Automated results

### Exact artifact and static suites

| Gate | Result |
| --- | --- |
| Exact archive verifier | PASS |
| Artifact policy + identity (PHP 7.4) | 91 tests, 467 assertions, PASS |
| Product suite excluding artifact-only release contracts | 225 tests, 2,682 assertions, 7 skipped, PASS |
| Focused lifecycle suite | 12 tests, 1,428 assertions, PASS |
| Exact candidate PHP 7.4 syntax | 57/57 shipped PHP files, PASS |
| Exact candidate PHP 8.3 syntax | 57/57 shipped PHP files, PASS |
| Deterministic POT rebuild/source coverage | PASS; only reviewed source-reference changes occurred before the final candidate freeze |
| Anonymous readme URLs | 1/1, HTTP 200 after a validated public redirect, PASS |

The skipped product tests are environment-dependent fixtures and are not counted as passes. The final repository-wide PHPUnit run remains part of the approval workflow gate; the counts above are the completed focused/full product gates established before the repository-only workflow/evidence files land.

Key reproducible commands:

```bash
mcpwp/vendor/bin/phpunit --configuration mcpwp/tests/phpunit.xml
bash mcpwp/scripts/build-pot.sh
bash mcpwp/scripts/check-readme-links.sh
```

For syntax checks, extract the frozen ZIP and run `php -l` over each of its 57 PHP files inside each pinned WordPress/PHP image. For artifact policy/identity, run PHPUnit under PHP 7.4 with `zip`, `unzip`, and `python3` available because the mutation suite builds and inspects archives.

### Clean install, no outbound HTTP, and lifecycle

Command:

```bash
bash mcpwp/scripts/test-wporg-clean-install.sh \
  "$PWD/mcpwp/scripts/mumega-mcp-3.4.3.zip" all
```

| Lane | Result |
| --- | --- |
| WordPress 6.1.1 / PHP 7.4 | 43/43 TAP checks, PASS |
| WordPress 7.0.1 / PHP 8.3 | 43/43 TAP checks, PASS |

Each lane installed the exact 85,364-byte ZIP (not a source mount), rechecked its SHA before and after the run, enabled `WP_DEBUG`/`WP_DEBUG_LOG`, and used an internal-only Docker network. The pre-activation `pre_http_request` spy first proved it could observe and block a probe request, then recorded zero plugin outbound attempts. `debug.log` and the outbound-attempt log were absent or exactly zero bytes at the end.

Both lanes verified:

- final basename, version, activation table/role/capability, one owned schedule, exactly four admin pages, 19 REST route patterns, and 17 advertised MCP tools;
- setup, tools, settings, and activity screens; API-key create/list/revoke; verifier-only key storage; site-info REST success;
- successful MCP execution for all 17 retained tools: `wp_site_info`, post/page create/read/update/list, draft list/delete, media upload/read/list, category/tag list, and term creation;
- a read-only key cannot mutate and a user without the custom capability cannot perform the admin mutation;
- deactivation preserves state/content while clearing owned schedules; reactivation restores the schedule without schema duplication or state loss;
- uninstall removes current and legacy plugin options/tables/rate transients/schedules, the API role, and administrator capability while preserving unrelated and similar-prefix state/content.

The exact spy context set was activation; the four admin screens; key create/list/revoke; site-info; all 17 tool calls; denied scope/capability; deactivation; reactivation; and uninstall.

## Official Plugin Check 2.0.0

Plugin Check ran against the ZIP-installed candidate on WordPress 7.0.1/PHP 8.3 in `mode=new`, strict JSON format, with error and warning severity thresholds set to 1 and AI disabled:

```bash
wp --path=/var/www/html --allow-root \
  --require=/var/www/html/wp-content/plugins/plugin-check/cli.php \
  plugin check mumega-mcp --mode=new --format=strict-json \
  --error-severity=1 --warning-severity=1
```

Result: **0 errors, 36 warnings**. The candidate SHA remained `e9c84443…`; outbound attempts and `debug.log` remained zero; the disposable lane was torn down with no Docker residue. Raw strict-JSON report SHA-256: `97ecd09f9fce06c8ae82d763d2d392638af5f55c12eb3e0bb021c9f996d71df5`.

All 36 warnings were reviewed:

| Count | Plugin Check code | Locations | Disposition |
| ---: | --- | --- | --- |
| 2 | `WordPress.Security.NonceVerification.Recommended` | `includes/admin/class-mcpwp-admin.php:42` | Scanner false positive. This reads, unslashes, sanitizes, and allowlists the `page` query variable only to decide whether to enqueue CSS; it performs no mutation, so a nonce is not applicable. |
| 3 | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | `includes/traits/trait-mcpwp-logging.php:50,63`; `includes/class-mcpwp-loader.php:95` | Scanner false positive. Each identifier is exclusively `$wpdb->prefix . 'mcpwp_activity_log'`; WordPress 6.1 does not support the later `%i` identifier placeholder. All values retain typed placeholders. |
| 7 | `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` | three settings-partial variables, one setup loop variable, three tools-partial loop variables | Scanner false positive. These templates are included from render methods, so the reported names are method-local variables, not globals. |
| 10 | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | activity log read/write/retention and uninstall cleanup | Intentional. The plugin owns a small local activity table and must directly remove owned transient rows/tables on uninstall. Inputs are fixed or prepared; lifecycle tests cover cleanup and preservation boundaries. |
| 10 | `WordPress.DB.DirectDatabaseQuery.NoCaching` | same local activity/uninstall operations | Intentional. Caching current activity rows would create stale administration output, and uninstall/retention operations must affect persistent rows immediately. |
| 4 | `WordPress.DB.DirectDatabaseQuery.SchemaChange` | `uninstall.php:54,56-58` | Intentional. Uninstall drops only the current owned activity table and three explicitly named legacy plugin tables. Multisite and preservation tests cover the boundaries. |

The counts sum to 36. No warning was hidden, converted to an error, or described as fixed when it remains in the report.

## PHPCS/WPCS review

Rules: `WordPress` plus `PHPCompatibilityWP`, minimum WordPress 6.1, `testVersion=7.4-`, PHP files only. The scan ran against the extracted frozen ZIP, not the broader source tree.

Representative gate command (the runtime option preserves warning output while making reviewed warnings non-fatal):

```bash
vendor/bin/phpcs \
  --standard=mcpwp/phpcs.xml.dist \
  --runtime-set ignore_warnings_on_exit 1 \
  -s /path/to/extracted/mumega-mcp
```

Result: **0 errors, 40 warnings in 6 files; 2 warnings mechanically fixable**. Without `ignore_warnings_on_exit`, PHPCS exits 2 because warnings are present; the report contents are identical.

All 40 warnings were reviewed and are itemized by complete category below:

| Count | PHPCS source | Exact locations | Disposition |
| ---: | --- | --- | --- |
| 10 | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | `uninstall.php:25,54,56-58`; `includes/admin/class-mcpwp-activity-log.php:27`; `includes/class-mcpwp-loader.php:95`; `includes/traits/trait-mcpwp-logging.php:26,50,63` | Intentional owned-table/transient activity and lifecycle operations. Identifiers are fixed plugin table names; values use typed `$wpdb->prepare()` placeholders. |
| 10 | `WordPress.DB.DirectDatabaseQuery.NoCaching` | `uninstall.php:25,54,56-58`; activity log `:26-27`; loader `:95`; logging trait `:50,63` | Intentional current activity/retention and destructive cleanup. Caching would be stale or incorrect for these operations. |
| 4 | `WordPress.DB.DirectDatabaseQuery.SchemaChange` | `uninstall.php:54,56-58` | Required uninstall removal of one current and three fixed legacy plugin-owned tables; no dynamic or unrelated table is targeted. |
| 10 | `Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed` | `includes/class-mcpwp-loader.php:125,139` (five parameters at each line) | False positive: every named parameter is consumed by `compact()` and later used to register the queued hook/filter. |
| 1 | `Generic.CodeAnalysis.UnusedFunctionParameter.Found` | `includes/admin/class-mcpwp-admin.php:51` | WordPress callback-signature parameter `$hook`; retained for hook compatibility even though page detection uses the sanitized page slug. |
| 2 | `Generic.Formatting.MultipleStatementAlignment.NotSameWarning` | `includes/admin/class-mcpwp-admin.php:20-21` | Cosmetic only. The unaligned constant declarations preserve exact reviewed/snapshotted slug declarations and have no runtime or security effect. |
| 2 | `WordPress.Security.NonceVerification.Recommended` | `includes/admin/class-mcpwp-admin.php:42` | Read-only, sanitized, allowlisted `page` routing used solely for asset enqueueing; no state change occurs and no nonce is applicable. |
| 1 | `WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode` | `includes/core/class-mcpwp-media.php:216` | Benign strict media decode (`base64_decode(..., true)`), not code execution. Input grammar/size/filename/extension are checked; the temporary file is then validated by WordPress against its content and declared MIME; executable extensions and SVG are rejected. |

The counts sum to 40. Security-error false positives for fixed table identifiers and nonce-protected delegated admin mutations use narrow line-level explanations in source; no broad ruleset suppression was added.

## Manual and CI go/no-go

Automated evidence covers fresh admin rendering, key lifecycle, denial paths, exact inventory, activity metadata behavior, readme disclosures, lifecycle cleanup, compatibility, and network silence. Before upload, the release owner still must:

- land and pass the dedicated SHA-pinned `wporg-approval.yml` workflow on the target branch, rebuilding once and fanning out the same candidate to every job;
- confirm the workflow's final full PHPUnit, Plugin Check, WPCS, manifest, link, clean-install, and artifact jobs all consumed this exact SHA;
- have a fresh reviewer inspect the 62-entry manifest and extracted ZIP, review both warning tables, and confirm that no WordPress.org email finding remains unresolved;
- manually confirm the final WordPress.org readme rendering and retained admin UX under the upload candidate.

Any shipped-file edit, candidate filename/size/SHA change, or unexplained difference from the committed manifest is an automatic no-go and restarts the complete gate.
