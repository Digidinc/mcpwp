=== Mumega MCP ===
Contributors: mumega
Tags: mcp, ai, automation, content, media
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.4.14
License: GPLv2 or later
License URI: https://wordpress.org/about/license/

A focused Model Context Protocol server for authenticated WordPress content operations.

== Description ==

Mumega MCP gives a site owner's configured MCP client a small authenticated interface to WordPress. The retained tools cover site information, posts, pages, individual drafts, local media, categories, and tags. Media can be uploaded from multipart form data or base64 content supplied by the client.

Security and authority:

* API keys are stored as password hashes, bound to an explicit existing WordPress user, and shown in plaintext only once.
* All usable API keys have non-empty scopes. A key may allow read, write, or admin authority according to its stored scope list.
* New post and page content is created as a draft by default.
* Publishing, private-content mutation, and draft deletion require the stronger admin scope and the corresponding WordPress user permissions.
* Request limits are enforced locally. Optional metadata-only activity logging stores tool, status, duration, key ID, and time, but not request arguments or response content. Retention is configurable.

Setup & Keys requires a non-empty label, selected non-empty scopes, and selected non-empty tool categories. A key created there is bound to the current explicit WordPress user. Direct REST child-key creation inherits the authenticated parent key's explicit existing WordPress-user owner. REST child-key scopes are required; tool categories are optional, and empty tool categories mean all retained categories.

Privacy and data flow:

There are no plugin-initiated outbound requests, no telemetry, and no contact with Mumega. Authenticated responses travel from WordPress to the MCP client configured by the site owner. That client may send the response to an AI or other provider chosen and configured by the site owner; the plugin does not select or contact that provider.

The WordPress.org directory edition cannot write PHP, JavaScript, or CSS; edit plugin or theme files; install or update software; or self-update.

== Installation ==

1. Install Mumega MCP from WordPress.org and activate it.
2. Open **Mumega MCP > Setup & Keys**.
3. Enter a label, select the minimum scopes and tool categories needed, and create a key.
4. Copy the key when it is displayed. It cannot be recovered later.
5. Configure the site owner's MCP client with `SITE_URL/wp-json/mcpwp/v1/mcp` and send the key in the `X-API-Key` header.

== Frequently Asked Questions ==

= Where are API keys stored? =

Each key is stored in WordPress as a password hash and is bound to the existing WordPress user who created it. The plaintext is displayed only once. A user with the Mumega MCP settings capability can revoke an active key from Setup & Keys.

= What do the scopes mean? =

Read permits non-mutating operations. Write permits retained content, media, and taxonomy mutations that the bound WordPress user is allowed to perform. Admin is the stronger scope required for sensitive operations such as publishing, private-content mutation, and delete operations on individual drafts. Tool-category selection narrows which groups of tools the key can call.

= Does the plugin send data to an external service? =

No plugin-initiated outbound request is made. The plugin sends authenticated results only to the MCP client chosen by the site owner. Any provider processing performed by that client is controlled by the owner's client configuration, outside this plugin.

= What is stored in the activity log? =

When local logging is enabled, the plugin stores tool name, status code, duration, API-key ID, and time. It does not store request arguments or response content. The site owner controls retention in Settings.

== Changelog ==

= 3.4.14 =

* Security and stability hardening pass.
* Load wp-admin/includes/media.php only for audio and video uploads, immediately before the metadata function that needs it; image uploads no longer load it at all.
* Add an explicit permission_callback literal at every REST route registration so authorization is visible to static analysis.
* Authenticate before any resource existence check in REST permission callbacks.
* Remove the mcpwp_manage_settings capability from the API agent role.
* Load only the current-namespace API keys; the legacy cross-prefix key path has been removed from this edition.

= 3.4.13 =

* Default Setup & Keys selections include read and write access for draft-first content testing.

= 3.4.3 =

* Reduce the directory edition to 17 authenticated MCP tools and 19 REST route patterns for content, local media, taxonomy, and local administration.
* Keep four minimal administration pages: Setup & Keys, Tool Inventory, Settings, and Activity.
* Remove remote, commercial, code-editing, and software-management surfaces.
