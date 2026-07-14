# Mumega MCP — WordPress.org reviewer notes

Candidate identity: **Mumega MCP** / `mumega-mcp`, version 3.4.13. Internal `mcpwp_*` option and hook prefixes and the `mcpwp/v1` namespace remain for compatibility with installations of the earlier off-directory build.

## Exact directory surface

- The 17 MCP tools cover authenticated site information, posts, pages, drafts, local media, categories, and tags.
- The separate REST surface has exactly 19 route patterns. It includes the content/media/taxonomy operations plus scoped-key, local-settings, and local-rate-limit administration.
- Administration is limited to Setup & Keys, read-only Tool Inventory, Settings, and read-only Activity. All four use `mcpwp_manage_settings`.
- All usable API keys have non-empty scopes. Plaintext is shown once; stored records contain a password hash. Keys can be revoked by ID.
- Setup & Keys requires a non-empty label, selected non-empty scopes, and selected non-empty tool categories; it binds the key to the current explicit WordPress user.
- Direct REST child-key creation inherits the authenticated parent key's explicit existing WordPress-user owner. REST scopes are required, while tool categories are optional; empty tool categories mean all retained categories.
- There are no outbound calls initiated by the plugin, no telemetry, no provider integration, no paid or upgrade code, and no self-update mechanism.
- The edition cannot edit PHP, JavaScript, CSS, plugins, or themes, and cannot install or update software.
- Local activity logging stores metadata only and has configurable retention. Request arguments and response content are not logged.

Authenticated responses are returned to the site owner's configured MCP client. A provider chosen in that client may process those responses; the plugin does not choose or contact it.

## Legacy cleanup only

Some option, table, and scheduled-hook names from pre-directory versions remain only in uninstall or deactivation delete/clear/drop statements. They have no active implementation, network call, scheduler, UI, or commercial behavior in this artifact.

## Validation status

The source policy, exact ZIP, compatibility matrices, Plugin Check, and deterministic-build results are not claimed final here. They must all be rerun and recorded in Task 5 before upload.
