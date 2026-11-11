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
write. Removal deletes the record; explicit reconfiguration creates a fresh
incarnation, so a restarted counter cannot match an earlier snapshot.

OAuth reads configuration, generation and incarnation together through
`get_provider_snapshot()` before HTTP. Both refresh and code exchange submit only
their returned token fields and that snapshot to the shared writer. The rule is
strict: **any successful write to the same provider invalidates the completion**,
including a settings-only update or test-status write. The writer checks that the
record still exists and both version fields still match on every CAS attempt.
A conflict returns `figma_config_changed` (409), without applying late tokens.
Configure/remove therefore win; the first of overlapping OAuth completions to
commit wins; unrelated-provider changes can retry without invalidating OAuth.

Snapshots are internal and contain decrypted configuration. They must never be
returned by REST/MCP endpoints or written to logs. Existing provider encryption
and encryption-migration failure-clearing semantics are preserved.

The shared log walker masks secret-named keys to the fixed `[redacted]` string
before inspecting or decoding their values. This includes strings, empty
containers, serialized values, native arrays/objects, booleans and null. There is
no option-name-shaped exception for a field named `key`. Encoded non-secret
containers can still be traversed to find secret-named children; existing
credential-shape detection remains additional protection for unlabelled values.
