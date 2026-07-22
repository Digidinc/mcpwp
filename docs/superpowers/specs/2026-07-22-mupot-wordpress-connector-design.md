# mupot WordPress connector — read-first slice

**Date:** 2026-07-22
**Status:** Approved design; implementation pending plan
**Repository:** `Mumega-com/mcpwp`
**Tracking:** `Mumega-com/mcpwp#689`
**Site:** `https://mcpwp.net` (first deployment target)

## Objective

Prove the two-plane governed-showcase thesis live on mcpwp.net: mcpwp.net is a WordPress site **operated by** the mumcp plugin (239+ MCP tools — the hands) and **governed by** mupot (identity, capabilities, gates — the authority). This is the keystone slice: the thinnest possible integration that makes mupot visibly, verifiably present inside wp-admin.

A logged-in WP staff member connects their own mupot identity once, and sees their own live mupot task board inside wp-admin — capability-gated, read-only, zero write risk.

## Doctrine constraint

mupot's `STATE-AND-STRATEGY.md`: *"the runtime governs; the tool stays a tool — don't build the OS into the tool."* WordPress must not become a second task/capability brain — that duplicates mupot's own in-flight `v0.24.0 Project Operations`. This design is a pure **client** of the existing `runtime-adapter/v1` contract (`mupot/docs/runtime-adapter-contract.md`). It requires **no changes to mupot** — mcpwp becomes another partner alongside Claude, Codex, and brain-node in `mupot/connectors/`, consuming the same seam:

- `POST https://<pot-instance>/mcp` `{tool, args}`, `Authorization: Bearer <member-token>`
- Tools used: `status { agent_id? }` and `task_board { squad_id?, limit? }` — both read-only, both `member` capability minimum, both squad-derived server-side from the bearer token (`auth.boundAgentId`). No `squad_id` is ever supplied by WordPress; mupot resolves it from the token alone.

Identity is server-derived. WordPress carries an opaque bearer token per user; it never asserts who that user is or what they may do. This is the same trust model every other mupot connector uses (see `mupot/connectors/README.md`, "Identity is server-derived").

## Scope

**In scope (this slice):**
- Site-level enable/disable toggle + configurable pot instance URL.
- Per-user personal mupot member token, entered once, encrypted at rest.
- A read-only "My mupot" tab inside the existing Control Room admin page, showing `status` + `task_board` for the current user's mupot identity.
- Graceful states for: feature disabled, user not connected, token invalid/revoked, pot unreachable.

**Explicitly out of scope:**
- Any write path back to mupot (task creation, updates, wakes) from WordPress.
- A new top-level admin page or menu item.
- Squad selection or any client-supplied scoping — the token alone determines what a user sees.
- Multi-pot / multi-tenant support beyond the one configured instance URL.
- Caching beyond a short-TTL freshness window (this is a live status view, not a synced dataset).

Widening after this slice (not now): member self-service task creation, Hermes-parity routines, gated write-back — each is its own future spec.

## Architecture

All new code lives in this repo (`Mumega-com/mcpwp`), inside the existing `mcpwp` plugin. No mupot-repo changes.

| Component | File | Responsibility |
|---|---|---|
| `Mcpwp_Mupot_Connector` | `includes/core/class-mcpwp-mupot-connector.php` (new) | Thin HTTP client. `get_status( $wp_user_id )`, `get_task_board( $wp_user_id )`. Resolves the calling WP user's stored token, calls `POST {instance_url}/mcp`, returns parsed result or `WP_Error`. Follows the existing `Mcpwp_Figma`-style external-client pattern (own base URL, no shared HTTP helper needed at this scale). |
| Settings extension | `includes/admin/class-mcpwp-settings.php` (modify) | Two new keys inside the existing `mcpwp_settings` option: `mupot_enabled` (bool, default `false`) and `mupot_instance_url` (string, default `https://mupot.mumega.com`). Rendered as a new fieldset on the existing Settings screen — no new admin page. |
| Profile field | `includes/admin/class-mcpwp-admin.php` or a new small trait (modify/new) | Hooks `show_user_profile` / `edit_user_profile` and `personal_options_update` / `edit_user_profile_update`. Renders only when `mupot_enabled` is true AND `current_user_can( 'activate_plugins' )`. Field: "Your mupot member token" (password-style input, never pre-filled with the real value — shows a masked placeholder if already set, matching the existing pattern for other secret fields in this plugin). Stored via `Mcpwp_Encryption::encrypt()` in user meta key `mcpwp_mupot_member_token`. |
| Control Room tab | `includes/admin/class-mcpwp-activity-log.php` or the Control Room render method (modify) | New "My mupot" tab, registered only when `mupot_enabled`. Renders one of three states (see Data flow). |

## Data model

| Key | Location | Shape |
|---|---|---|
| `mcpwp_settings['mupot_enabled']` | `wp_options` (existing option, extended) | `bool`, default `false` |
| `mcpwp_settings['mupot_instance_url']` | `wp_options` (existing option, extended) | `string`, default `https://mupot.mumega.com` |
| `mcpwp_mupot_member_token` | `wp_usermeta`, per user | Encrypted string (via `Mcpwp_Encryption`), never returned by any REST/MCP tool, never logged |
| Transient `mcpwp_mupot_board_{user_id}` | `wp_options` (transient API) | Cached JSON result of the last successful `status`+`task_board` call, TTL 60 seconds |

## Data flow

1. Admin enables mupot in Settings, sets instance URL (or accepts default).
2. A WP user with `activate_plugins` capability visits their own Profile screen, sees the mupot token field (empty/masked), pastes their personal mupot member token, saves. Token is encrypted before storage; the raw value is never held longer than the single request that sets it.
3. That user visits Control Room → "My mupot" tab.
   - **No token stored:** render a "Connect your mupot account" notice linking to their Profile screen. No API call is made.
   - **Token stored:** connector checks the 60s transient first. On miss, calls `POST {instance_url}/mcp` twice (`status`, `task_board`) with `Authorization: Bearer <decrypted token>`, a bounded timeout (5s), caches the combined result, and renders it.
4. mupot resolves identity, capability, and squad entirely server-side from the bearer token. WordPress never supplies or infers any of these.
5. Rendered output is read-only: agent status fields and the task list exactly as mupot returns them — no local reinterpretation of status, capability, or ownership.

## Error handling

| Condition | Behavior |
|---|---|
| `mupot_enabled` is false | Tab does not register at all. No mupot code path runs. |
| Current user has no stored token | "Connect your account" prompt. Not an error state. |
| mupot returns `401`/`403` | "Your mupot token is invalid or has been revoked — reconnect from your profile." No retry, no silent fallback to a shared/default identity. |
| Network timeout / pot unreachable | If a cached value exists and is still within TTL, show it with a "last updated Xs ago" note. Otherwise show "mupot is unavailable right now" — never a fatal PHP error, never a blank admin screen. |
| Malformed/unexpected response shape | Log via existing activity-log trait at `warning` level; render the unavailable state. Never attempt to guess-parse a differently-shaped payload. |

## Security

- Token stored encrypted (existing `Mcpwp_Encryption` utility, same as other provider secrets in this plugin).
- Token never appears in any REST/MCP response, error message, or activity log entry.
- Profile field and Control Room tab both gated on `current_user_can( 'activate_plugins' )` — same capability the rest of wp-admin's mcpwp surface already requires (Setup, Control Room itself).
- No token is ever passed as a request parameter to mcpwp's own REST/MCP surface — it lives only in user meta and is read server-side by the connector class.
- HTTPS-only calls to the configured instance URL; the plugin does not downgrade or offer an insecure fallback.

## Testing contract

PHPUnit, following this repo's existing test-double conventions (`tests/bootstrap.php` HTTP stubs):

- `Mcpwp_Mupot_Connector::get_status()`/`get_task_board()` return a `WP_Error` when no token is stored for the given user, without making an HTTP call.
- A stubbed `wp_remote_post` returning a valid payload is parsed correctly and cached in the transient.
- A stubbed `401`/`403` response surfaces the "reconnect" error path, not a generic failure.
- A stubbed timeout/`WP_Error` from `wp_remote_post` falls back to a valid cached transient when present, and to the "unavailable" state when absent.
- Settings: `mupot_enabled` defaults to `false`; the Control Room tab and the profile field both fail to register when it is `false`.
- Profile field: does not render for a user lacking `activate_plugins`; renders masked (never the decrypted value) when a token is already stored.
- Token round-trip: a token saved through the profile handler decrypts back to the original value via `Mcpwp_Encryption`.

Manual verification: enable the feature, connect a real mupot member token against a real pot instance, confirm the Control Room tab shows genuine live status/task-board data, confirm disabling `mupot_enabled` removes the tab and the profile field immediately.

## Acceptance criteria

- With the feature disabled, no mupot-related code path, admin UI, or network call exists anywhere in wp-admin.
- A connected WP user sees their own real mupot task board in Control Room, sourced from a live server-side call, not synthesized or hard-coded.
- No mupot-side contract or code changes are required to ship this.
- WordPress never asserts identity, capability, or squad — every one of those is read from what mupot's response contains.
- All four failure states (disabled, unconnected, invalid token, unreachable pot) render a clear, non-fatal message.
- Full PHPUnit suite remains green; new tests cover the connector class, settings gating, and profile field behavior.
