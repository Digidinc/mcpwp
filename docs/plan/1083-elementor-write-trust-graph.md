# PR #1083 Elementor write and trust graph

Pinned review input: `d6e7272ae2f5431677f57aa8f7802884b0128167` (PR #1083, 2026-10-01). This map records source paths and proof still needed; it is not a release verdict.

## Boundaries and edges

| Edge | Entry or owner | Trust decision | Persistent effect and readback |
| --- | --- | --- | --- |
| API key or OAuth bearer → REST route | `Mcpwp_Api_Auth::check_permission`; Free and Pro Elementor controllers | Key scope, tool category, post ID, publish status, and template-reference gates | No write before authorization; inspect both REST and MCP dispatch. |
| REST/MCP payload → Elementor tree | `Mcpwp_Elementor_Basic::set_elementor_data`, `save_elements_to_page` | Tree shape, non-finite numbers, widget/template references, post type, content conversion | `Mcpwp_Elementor_Meta_Write::update`; fresh `_elementor_data` readback precedes success. |
| Existing page → edited tree | Basic writer: edit section/widget, add/remove/replace section, patch, find/replace | Existing element on same post may vouch for unchanged bytes; introduced or changed element is sanitized | Shared `save_elements_to_page`; Elementor `Document::save`, CSS and HTML cache regeneration are separate results. |
| New page or template → first tree | Basic create; Pro template, landing-page and page-builder creates; Theme Builder create | `wp_insert_post` returns a post ID even when its `meta_input` metadata write fails | Each creator needs its own fresh meta readback and cleanup on failure; remaining creator paths are under review. |
| Source page/template/part → target tree | `/pages/{id}/clone`; Pro clone, apply template/archetype/part, insert part, save section as template | Source readability, nested template references, target policy; content is new on a different post | Pro clone now checks its target; Free clone and apply routes still need path-specific verification. |
| Page settings → target settings | Basic set, `Mcpwp_Elementor_Page_Settings::apply`, Pro clone and Kit CSS | Same-post unchanged values survive; copied or new values are sanitized; Kit `custom_css` needs its own scope and style-breakout rule | Shared writer now checks DB readback; Kit CSS block repair has its own verification. Other settings responses still need review. |
| Tree/settings → derived output | Elementor document save, `_elementor_css`, `_elementor_element_cache`, frontend render | Rebuilding caches must not replace the verified tree or reuse a source cache after a sanitized copy | Fresh database readback, render result, and served frontend are separate evidence. |
| Custom Code → sitewide hooks | Pro Custom Code routes | Admin scope and explicit Custom Code sanitation | `_elementor_code`, conditions index, and frontend hook output need their own proof; ordinary Elementor clone refuses this post type. |
| Batch/MCP → REST re-dispatch | `Mcpwp_REST_Batch` and `Mcpwp_REST_MCP` | Each inner route must recheck its own scope and template-reference gate | A batch result must not upgrade a failed inner write to success. |
| Action-log rollback → old tree | `Mcpwp_Action_Log` | Trusted snapshot of the same post may vouch for unchanged raw bytes | Restore `_elementor_data`, invalidate CSS, and verify what the DB holds. |
| Elementor changes → site content graph | `Mcpwp_Graph_Builder` meta hooks, shutdown and worker queue | Graph nodes and extracted links are derived from persisted post state | Queue/rebuild evidence is separate from page-write success. |
| Template conditions → Elementor Pro cache | Theme Builder and Custom Code conditions managers | Published status, location, and conditions must agree | Verify Elementor Pro's location-first cache and frontend output. |
| Kit CSS blocks → Kit settings | Named block upsert/delete through Free REST | Block marker/hash, raw CSS, and `</style` refusal | Direct DB readback after repair; a delete must also refuse unverified persistence. |
| Activation repair → legacy snippets | `Mcpwp_Activator` migration | Trusted local migration context, existing snippet type | Migration writes `_elementor_code`, location and priority; verify separately from live request paths. |

## Source inventory

- Free REST routes: `mcpwp/includes/api/class-mcpwp-rest-elementor.php` covers set/page, section and widget edits, patch, find/replace, Kit CSS and regeneration. `mcpwp/includes/api/class-mcpwp-rest-pages.php` also clones a page with Elementor data.
- Basic writers: `mcpwp/includes/core/traits/trait-mcpwp-elementor-writer.php` funnels full and surgical tree writes through `set_elementor_data()` or `save_elements_to_page()`. `mcpwp/includes/core/class-mcpwp-elementor-meta-write.php` owns the metadata policy for `_elementor_data` and `_elementor_page_settings`.
- Pro REST routes: `mcpwp/includes/pro/api/class-mcpwp-rest-elementor-pro.php` exposes templates, archetypes, parts, clone, landing page, build page, globals, Kit CSS and Custom Code. Its permission callback installs the template-reference gate in `Mcpwp_Elementor_Pro`.
- Pro writers: `mcpwp/includes/pro/core/class-mcpwp-elementor-pro.php` creates and copies templates and pages; `class-mcpwp-page-builder.php` builds pages; `class-mcpwp-theme-builder.php` writes Theme Builder templates; `mcpwp/includes/core/class-mcpwp-elementor-page-settings.php` applies document settings.
- Indirect writers: `mcpwp/includes/api/class-mcpwp-rest-batch.php`, `mcpwp/includes/api/class-mcpwp-rest-mcp.php`, and `mcpwp/includes/core/class-mcpwp-action-log.php` re-dispatch or restore Elementor writes. `mcpwp/includes/core/class-mcpwp-graph-builder.php` rebuilds derived graph state; `mcpwp/includes/class-mcpwp-activator.php` repairs legacy Custom Code metadata during activation.
- Source coverage guard: `mcpwp/tests/ElementorMetaWriteChokepointScanTest.php` scans direct metadata writes, `meta_input`, and SQL postmeta writes. It is a sink scan, not a route, authority, persistence or render proof.

## Entry route matrix

All paths below are under `/mcpwp/v1`. MCP tool names route into these REST handlers; `/batch` can re-dispatch them. Authorization must be evaluated for each dispatched operation.

| Entry routes | Writer and state | Verification boundary |
| --- | --- | --- |
| `POST /elementor/{id}`; `POST /elementor/page` | Basic full-tree set and create, `_elementor_data`, builder flags and CSS | Basic writer compares approved JSON with raw DB postmeta before success; create deletes failed post. |
| `POST /elementor/{id}/edit-section`, `/add-section`, `/remove-section`, `/replace-section`, `/patch`, `/edit-widget` | Basic surgical edits through `save_elements_to_page()` | Same-post unchanged element may keep raw bytes; changed element is sanitized; raw DB tree comparison precedes cache rebuild. |
| `POST /elementor/{id}/find-replace`; bulk `/elementor/find-replace` | Direct metadata update, then document cache invalidation | Shared metadata writer compares the approved tree with raw DB postmeta for each write. |
| `POST /pages/{id}/clone` | Free page clone, copied tree and page settings | Draft staging, target-policy write and raw metadata readback before requested status transition. |
| `POST /elementor/templates`, `/archetypes`, `/parts`; updates at `/{id}`; `/parts/from-section`; `/save-section-as-template` | Pro library creates/updates and section copy | Tree uses Basic writer; settings use shared writer; creates remain draft and delete on failed persistence. Updates report partial settings failure. |
| `POST /elementor/templates/{id}/apply`, `/archetypes/{id}/apply`, `/parts/{id}/apply` | Pro template/part transfer to a target | Target tree is a new write under target policy; requested page settings failure returns a partial-write error. |
| `POST /elementor/clone`, `/landing-page`, `/build-page` | Pro clone, landing and page-builder posts; `meta_input` plus later tree save where applicable | Draft staging, explicit target readback and cleanup on failure; builder rechecks raw DB after publish. |
| `POST /elementor/kit-css`, `/kit-css/blocks`; delete `/kit-css/blocks/{name}`; `POST /elementor/globals` | Kit `_elementor_page_settings` and named CSS | Raw `custom_css` is guarded against style breakout; named-block repair has its own DB readback; shared writer verifies ordinary settings writes. |
| `POST /elementor/regenerate-css` | Elementor CSS file and derived meta | Render/cache outcome is separate from tree persistence. |
| `POST /theme-builder/templates`; condition and assign routes under `/theme-builder/templates/{id}` | Theme template tree, publish transition and Elementor Pro display conditions | Tree DB readback precedes publish; live conditions cache and frontend require separate checks. |
| `POST /elementor/custom-code`; update, enable, disable and sanitize routes under `/elementor/custom-code/{id}` | Snippet code/location/priority/conditions and Pro cache | Submitted meta and cache are checked against DB before success; create stages draft and update defers publish. |
| Action-log rollback and activation migration | Internal restore/repair entry points outside the ordinary REST write routes | Same-post trusted snapshot or migration authority; check restored meta, invalidated caches and frontend independently. |

## Findings under repair

1. Pro clone returned success after `wp_insert_post()` even if the initial `_elementor_data` metadata write failed. A fresh target-tree readback now checks the value approved by the copy policy and deletes an incomplete copy on mismatch.
2. Pro clone ignored a failed `_elementor_page_settings` copy. The copy now reads back the approved settings and deletes an incomplete copy on mismatch.
3. Pro landing-page creation returned an error after a failed `set_elementor_data()` write but left the new post. That error path now deletes the incomplete page.
4. Theme Builder could publish a template after a silent `_elementor_data` write failure. It now compares fresh stored data with the approved value before publishing and deletes the draft on mismatch.
5. The full and surgical Elementor writers accepted a stale tree when its top-level section count matched. Both now compare the database value to the approved tree before rebuilding caches or returning success.
6. The shared metadata writer now verifies persisted tree/settings values directly from postmeta. Kit CSS blocks retain a narrowly deferred repair path whose caller must verify the final CSS bytes.
7. Pro template, landing-page, page-builder, saved-section, Pro clone, Free clone, and Custom Code creation now stage as draft until requested data is saved. Failed creates remove the incomplete post; Custom Code updates defer status changes until after metadata verification.
8. Free page clones now carry page settings through the target policy and verify the target copy. Pro template apply/update refuse a failed requested settings write, reporting a partial tree write where applicable.
9. Custom Code checks persisted code, location, priority, edit mode, and conditions. When Elementor Pro is loaded, an unavailable document or failed conditions cache update is a write error, and the cache entry is read from the options table before success.
10. Named Kit CSS block writes now verify the whole `_elementor_page_settings` DB row after CSS repair. A host that changes an untouched sibling setting while preserving CSS bytes no longer receives a success response.

## Remaining proof

- Exercise every route family above with scoped credentials and adversarial tree/settings inputs, including late metadata filters, failed writes, and template references. Real authenticated Pro clone was checked via WP-CLI with the plugin's CLI-only test entitlement; live HTTP Pro behavior remains unproven.
- Verify Theme Builder and Custom Code against a normally entitled Pro deployment if customer-facing release proof is required. The isolated run below used MCPWP's CLI-only test entitlement and Elementor Pro's existing-snippet compatibility path, not a customer license.
- Obtain CI and independent gate evidence for the changed patch if it is submitted to a PR. Current remote checks apply to the unmodified PR head only.
- Recheck PR head and CI after any later push. Local test results do not prove CI or release readiness.

## Current local evidence

- Final declared-order and randomized PHPUnit (seed 5150) each passed 4,049 tests, 35,515 assertions, 2 skipped, 0 failures on the changed worktree. The source scan, test-discovery, distribution-reference, workflow-script, committed-secret and PHP syntax checks passed separately; 45 Python and 9 browser-side JavaScript tests also passed.
- The complete plugin PHP tree also passed syntax checks in local PHP 7.4, 8.0, 8.1 and 8.2 CLI containers, matching the repository's validation matrix. These local checks do not substitute for GitHub CI on a pushed patch.
- PHPUnit under the repository CI's PHP 8.1 version passed both declared and seed-5150 randomized order: 4,049 tests, 35,514 assertions, 2 skipped in each run. The container used a writable isolated worktree and a 512 MB memory limit because eight unrelated fixtures create temporary files under the test tree; the first read-only mount failed on those fixture writes, then the corrected run passed.
- An earlier intermediate randomized run had one failure in `McpNextStepHintsTest::test_existing_meta_keys_survive_alongside_the_hint`; that test also fails in isolation on untouched PR head. The final randomized run was clean.
- Three deliberate mutations each failed the focused test: sanitizing every unchanged element stripped its HTML widgets; granting raw storage to every writer exposed scripts in a clone; skipping metadata readback accepted a failed tree write. Each test passed after the mutation was restored.
- Candidate ZIP includes byte-identical changed PHP files. The final throwaway WordPress upgrade smoke passed 15/15, including MCP readback, frontend heading render and no MCPWP PHP errors. It exercised Free routes, not authenticated Pro routes.
- A separate throwaway WordPress site ran Elementor 4.3.3 and Elementor Pro 4.1.1 against the final candidate. Direct Pro handler smoke verified an unrelated edit preserved a stored HTML widget, sanitized cloned content, left the source untouched, dropped source element cache, copied page settings, and removed a failed clone. The published clone rendered an Elementor HTML widget without a live copy of the source `<script>`.
- With MCPWP's CLI-only test entitlement, the real WordPress REST Pro clone route authenticated a newly scoped local key and returned a sanitized copy with settings while preserving the source. The candidate ZIP itself has no Pro entitlement, and the CLI-only check is not an HTTP production route receipt.
- In a fresh throwaway WordPress site with Elementor 4.3.3 and Elementor Pro 4.1.1, Elementor Pro initially left `elementor_snippet` unregistered. Its installed module source permits Custom Code when a published legacy snippet exists; a local test fixture was seeded for that path. With MCPWP's CLI-only test entitlement, authenticated REST calls then created Custom Code (post 18) and a Theme Builder header (post 19). The database held the exact Custom Code script and header tree, the Elementor Pro conditions cache held both entries, and the HTTP frontend served the live script and header marker. Deleting posts 19, 18 and fixture 17 removed both markers from the frontend. No production site or license state was changed.
- A fresh read-only reviewer found no remaining concrete Critical/Important issue after the four findings in the prior pass were repaired, then separately reviewed the final Kit CSS change with no such finding. All review passes were source-only; runtime claims come from the separate commands above.
