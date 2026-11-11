# Provider record writes

`Mcpwp_Integration_Manager::write_provider_record()` is the single mutation
chokepoint for configuration, single-key saves, removal, OAuth completion and
connection-test status. It runs the mutation against the current record inside
`write_records()`'s byte-exact options-row compare-and-swap. That CAS is the
optimistic lock: if any writer changes the row, the entire read/check/mutation
is retried against the new row. No process-local or expiring mutex is used.

Every stored record carries an integer `generation` and a random `record_id`
identifying its incarnation. Each successful mutation increments the generation.
Legacy records read as generation zero and acquire an incarnation on their first
write. Removal deletes the provider record but retains its counter high-water
mark under the reserved `_mcpwp_generations` key in the same CAS-protected option.
Reconfiguration advances that counter and creates a fresh incarnation.

OAuth reads configuration, generation and incarnation together through
`get_provider_snapshot()` before HTTP. Both refresh and code exchange submit only
their returned token fields and that snapshot to the shared writer. The rule is
strict: **any successful write to the same provider invalidates the completion**,
including a settings-only update or test-status write. The writer checks that the
record still exists and both version fields still match on every CAS attempt.
A conflict returns `figma_config_changed` (409), without applying late tokens.
Configure/remove therefore win; the first of overlapping OAuth completions to
commit wins; unrelated-provider changes can retry without invalidating OAuth.

Managed restores use `restore_provider_records()`, an atomic batch through the
same `write_records()` row CAS and shared `advance_record_version()` rule. They
ignore the backup's counter map, keep live high-water marks (including removed
providers), advance beyond both the live counter and restored record generation,
and mint a fresh random incarnation for every restored provider. No incarnation
is taken from the backup. The action-log option-restore fallback also dispatches
provider snapshots here, although normal integration actions do not generate
such rollback snapshots.

Raw WordPress `update_option()`/`add_option()` replacement of this option is
intentionally unsupported: the bootstrap registers a sanitizer that throws a
static `RuntimeException` directing trusted restore code to the CAS restore API.
This includes WP-CLI option writes when the plugin is loaded. It is an explicit
refusal, not a graceful REST response or a successful restore.

Direct SQL/database imports, filesystem/process snapshots, `--skip-plugins`,
and whole-option deletion bypass PHP guards. They are outside the managed restore
protocol and must drain/terminate in-flight PHP requests before restoring and
restarting the plugin. A generation stored in the same restored database cannot
prove that the database was not rolled back; the plugin does not claim otherwise.
No plugin-owned full-database restore operation exists.

Snapshots are internal and contain decrypted configuration. They must never be
returned by REST/MCP endpoints or written to logs. Existing provider encryption
and encryption-migration failure-clearing semantics are preserved.

The shared log walker masks secret-named keys to the fixed `[redacted]` string
before inspecting or decoding their values. This includes strings, empty
containers, serialized values, native arrays/objects, booleans and null. There is
no option-name-shaped exception for a field named `key`. Encoded non-secret
containers can still be traversed to find secret-named children; existing
credential-shape detection remains additional protection for unlabelled values.


## Writer inventory

| Writer or surface | Storage route or reason |
|---|---|
| Manager configure, key saves, remove and test-status updates | `write_provider_record()` -> shared version stamping -> row CAS; deletion retains counter high-water marks. |
| REST/MCP/WebMCP/batch integration calls; admin save/remove; Figma OAuth callback/refresh | Existing dispatch -> manager; conditional OAuth cannot create or replace a mismatched version. |
| Legacy bridge import (`class-mcpwp-migrate.php`) | Plain insert-if-absent CAS, never `update_option()` for integrations. A concurrent configure wins. Opaque legacy payloads remain unchanged for compatibility; legacy provider records acquire version metadata on their first mutation. |
| Encryption migration (`class-mcpwp-encryption-migration.php`) | Retains its existing migration lock and byte-exact batch CAS for all-or-retry ciphertext migration/read-back verification. Each changed provider uses `advance_record_version()` and updates the live counter map before that CAS; current metadata and unrelated records are preserved. |
| Managed backup restore and action-log option-restore fallback | `restore_provider_records()` -> shared version stamping -> row CAS. Backup incarnations and counter maps cannot be replayed. Generic option APIs already deny plugin-owned state and ordinary credential snapshots are withheld. |
| Activation | Calls the guarded bridge; no separate provider-option writer. |
| Uninstall/Freemius cleanup | No provider-option/ciphertext writer or deletion in the cleanup lists. |
| Blueprint/import | Blueprint store and application write blueprint/context options only, never provider records. |
| Pro staging | Delegates host operations; status snapshots only; no local provider-record/database restore implementation. Host database replacement requires the drain described above. |
| WP-CLI | No plugin-defined provider-writing command. Core option add/update goes through the raw-write guard when the plugin is loaded; SQL import, option deletion or skipping plugin loading is outside the protocol. |
| Encryption SQL primitives | Low-level insertion/CAS used by the manager and the two documented migration paths; no other provider caller found. |
| `mcpwp_integrations` integration-discovery filter | Registration/read seam with the same name, not an option writer. |

The audit searched the whole plugin and repository scripts for the option names,
provider ciphertext assignments, manager option constants, generic option writes,
SQL writes, activation/uninstall, import/blueprints, admin posts, CLI and Pro paths.
