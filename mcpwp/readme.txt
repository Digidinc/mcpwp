=== Mumega MCP ===
Contributors: mumega
Tags: mcp, ai, automation, content, media
Requires at least: 6.1
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 3.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A focused, local Model Context Protocol server for WordPress.

== Description ==

Mumega MCP exposes a small authenticated API for managing WordPress posts, pages, drafts, local media, categories, and tags.

The plugin includes:

* Scoped API keys stored as password hashes
* Local request rate limiting
* Local metadata-only activity logging with configurable retention and no request or response content
* MCP tools for the retained WordPress content operations
* Multipart and base64 uploads from your device

Mumega MCP does not send site data to a third-party service. All retained operations run inside your WordPress installation.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/` or install it from WordPress.org.
2. Activate Mumega MCP.
3. Open **Mumega MCP > Setup & Keys** and create an API key.
4. Configure your MCP client with `https://yoursite.com/wp-json/mcpwp/v1/mcp` and send the key in the `X-API-Key` header.

== Frequently Asked Questions ==

= Where are API keys stored? =

Keys are stored in WordPress as password hashes. The plaintext value is displayed only when a key is created.

= Does the plugin call an external service? =

No. The directory edition performs only local WordPress operations.

= What can MCP clients manage? =

The MCP inventory covers site information, posts, pages, individual drafts, local media, categories, and tags. Administrative key, rate-limit, and plugin settings remain available only through authenticated REST and WordPress administration.

== Changelog ==

= 3.4.3 =

* Reduce the directory edition to a focused local WordPress and MCP connector.
* Retain scoped API keys, rate limiting, local activity logs, content, local media, and taxonomy.
