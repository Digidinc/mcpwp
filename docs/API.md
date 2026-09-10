# MCPWP - API Documentation

> Control WordPress with AI through a powerful REST API

**Base URL:** `https://your-site.com/wp-json/mcpwp/v1`
**Version:** 3.3.8

## Table of Contents

- [Native MCP Endpoint](#native-mcp-endpoint)
- [Authentication](#authentication)
- [Error Handling](#error-handling)
- [Rate Limiting](#rate-limiting)
- [Destructive Operations](#destructive-operations)
- [Endpoints](#endpoints)
  - [Site Info](#site-info)
  - [Posts](#posts)
  - [Pages](#pages)
  - [Post Meta](#post-meta)
  - [Media](#media)
  - [Elementor (Core)](#elementor-core)
  - [Elementor Pro](#elementor-pro)
  - [Gutenberg Blocks](#gutenberg-blocks)
  - [SEO](#seo)
  - [Forms](#forms)
  - [Users](#users)
  - [Menus](#menus)
  - [Settings](#settings)
  - [Options](#options)
  - [Site Context](#site-context)
  - [Favicon](#favicon)
  - [Widgets](#widgets)
  - [Themes](#themes)
  - [Theme Builder](#theme-builder)
  - [WooCommerce (Pro)](#woocommerce-pro)
  - [Multilanguage (Pro)](#multilanguage-pro)
  - [Webhooks](#webhooks)
  - [AI Integrations](#ai-integrations)
- [Rate Limiting](#rate-limiting)
- [Auto-Updates](#auto-updates)
- [MCP Server Configuration](#mcp-server-configuration)
- [AI Integration Examples](#ai-integration-examples)

---

## Native MCP Endpoint

**POST** `/wp-json/mcpwp/v1/mcp`
**GET** `/wp-json/mcpwp/v1/mcp` *(v1.1.8+ — server info / health check)*

Direct JSON-RPC 2.0 MCP endpoint. Supports `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, and batch requests.

**Authentication:** `X-API-Key` header, `Authorization: Bearer` header, or `?api_key=` query parameter
**Batch limit:** 10 requests per call

### Recommended Model Startup Sequence

When a new model connects to the MCP endpoint, it should follow this order before making changes:

1. `wp_introspect`
2. `wp_get_site_context`
3. `wp_get_guide(topic="workflows")`
4. `wp_get_guide(topic="elementor")` when working on pages/templates
5. `wp_get_guide(topic="woocommerce")` when working on products

Operational rules:

- Prefer structured reuse over reinvention.
- Use page archetypes for repeatable page classes like blog posts, service pages, landing pages, and case studies.
- Use product archetypes for repeatable WooCommerce product classes.
- Reuse existing Elementor parts before creating new sections.
- If a new page or product flow creates a reusable section, save it back into the Elementor parts library before finishing.
- Default new content to `draft` unless the user explicitly asks to publish.

**Example Request:**

```bash
curl -X POST "https://your-site.com/wp-json/mcpwp/v1/mcp" \
  -H "X-API-Key: mcpwp_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/list",
    "params": {}
  }'
```

**Supported Methods:**
- `initialize` - Initialize MCP session (declares `tools`, `resources`, `prompts` capabilities)
- `tools/list` - List all available tools (varies by license tier)
- `tools/call` - Execute a tool
- `resources/list` - List 6 site resources (site-info, context, pages, posts, design-references, elementor-status)
- `resources/read` - Proxy-read any `mcpwp/v1` resource URI with API key forwarding
- `prompts/list` - List 5 named prompt templates (audit-seo, build-page, refresh-content, onboard, design-match)
- `prompts/get` - Get filled prompt with interpolated arguments, returns `messages[]`
- `ping` - Health check
- Batch requests - Execute up to 10 requests in parallel

### Tool Categories

Every tool includes an `annotations.category` field. Tools are organized into 12 categories:

| Category | Tier | Description | Example Tools |
|----------|------|-------------|---------------|
| `content` | Free | Posts, pages, drafts, search, clone | `wp_list_posts`, `wp_create_page`, `wp_search` |
| `media` | Free | Upload, list, manage media files | `wp_upload_media`, `wp_list_media` |
| `elementor` | Free | Elementor page builder data | `wp_get_elementor`, `wp_set_elementor` |
| `seo` | Pro | SEO meta, audit, autofix | `wp_seo_audit_site`, `wp_validate_seo_readiness` |
| `forms` | Pro | Form plugins integration | `wp_list_forms`, `wp_get_form_submissions` |
| `gutenberg` | Free | Block editor content | `wp_get_blocks`, `wp_set_blocks` |
| `taxonomy` | Free | Categories, tags, terms | `wp_create_term`, `wp_list_categories` |
| `site` | Free | Site info, plugins, themes, health | `wp_site_info`, `wp_list_plugins` |
| `webhooks` | Free | Webhook management | `wp_list_webhooks`, `wp_create_webhook` |
| `admin` | Free | API keys, rate limits, feedback | `wp_get_rate_limit_status`, `wp_submit_feedback` |
| `ai` | Pro | AI-powered tools (stock photos, TTS, image gen) | `wp_search_stock_photos`, `wp_generate_image` |
| `staging` | Pro | Push/pull between staging and live environments | `wp_get_staging_info`, `wp_push_to_staging`, `wp_push_to_live` |

### Category Filtering

Filter `tools/list` by category to reduce context noise:

```bash
curl -X POST "https://your-site.com/wp-json/mcpwp/v1/mcp" \
  -H "X-API-Key: mcpwp_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/list",
    "params": {"category": "elementor"}
  }'
```

### Disabling Tool Categories (Admin)

Site administrators can disable entire tool categories from **WordPress Admin → Tools → MCP Tools**. Disabled categories are excluded from `tools/list` responses, reducing token usage for AI assistants.

Disabled categories are stored in the `mcpwp_disabled_tool_categories` WordPress option.

**Direct Claude Desktop/Code Connection:**

This endpoint enables native MCP integration without an external MCP server. Configure Claude Desktop or Claude Code to connect directly to your WordPress site.

---

## Authentication

All API requests require authentication via API key.

### Getting Your API Key

1. Go to **WordPress Admin → MCPWP → Setup**
2. Copy your API key or generate a new one

### Authentication Methods

#### Header Authentication (Recommended)

```bash
curl -H "X-API-Key: mcpwp_your_api_key_here" \
  https://your-site.com/wp-json/mcpwp/v1/site-info
```

#### Bearer Token

```bash
curl -H "Authorization: Bearer mcpwp_your_api_key_here" \
  https://your-site.com/wp-json/mcpwp/v1/site-info
```

#### Query Parameter

For MCP clients that don't support custom headers (e.g., Claude Desktop custom connectors):

```bash
curl "https://your-site.com/wp-json/mcpwp/v1/mcp?api_key=mcpwp_your_api_key_here"
```

> **Note:** Prefer header authentication when possible. Query parameters may appear in server access logs.

---

## Error Handling

### Error Response Format

```json
{
  "code": "error_code",
  "message": "Human-readable error message",
  "data": {
    "status": 400
  }
}
```

### Common Error Codes

| Code | Status | Description |
|------|--------|-------------|
| `missing_api_key` | 401 | API key not provided |
| `invalid_api_key` | 401 | API key is incorrect |
| `api_not_configured` | 500 | API key not set up in WordPress |
| `not_found` | 404 | Resource not found |
| `invalid_param` | 400 | Invalid parameter value |
| `missing_required` | 400 | Required parameter missing |
| `permission_denied` | 403 | Insufficient permissions |
| `rate_limit_exceeded` | 429 | Over the rate-limit budget; nothing ran. See [Rate Limiting](#rate-limiting) |
| `batch_exceeds_limit` | 413 | The call is heavier than the whole window; split it |
| `forbidden_option` | 403 | The option policy refused the key; `data.rule` names the rule |
| `invalid_option_name` | 400 | Option names are 1–191 characters of letters, digits, `_ - . :` |
| `secret_write_refused` | 409 | The write would set or overwrite a secret; resend with `allow_secrets: true` if intended |
| `active_markup_requires_admin` | 403 | The value carries script, an event handler or a `javascript:` URL; needs an admin-scoped key |
| `mask_in_write` | 409 | The value contains `***` from a read; send the real value or omit the field |
| `option_type_mismatch` | 409 | A plain string would replace a stored array or object; send JSON or pass `force_type_change` |
| `read_failed` | 500 | A plugin hook threw while reading; nothing is reported as empty |
| `forbidden_meta_key` | 403 | The meta key is internal or credential-shaped and cannot be read |
| `invalid_meta_key` | 400 | The meta key has characters outside `[A-Za-z0-9_-.:]`; nothing was written |
| `use_elementor_endpoint` | 400 | Elementor meta is written through the Elementor tools |
| `invalid_target` | 400 | A destructive operation named a target it cannot act on; nothing was queued |
| `destructive_id_mismatch` | 409 | The route id and the body id disagree; nothing was queued |

---

## Rate Limiting

Rate limiting protects your WordPress site from API abuse. Limits are configurable in settings.

### Default Limits

| Window | Requests |
|--------|----------|
| Per Minute | 60 |
| Per Hour | 1,000 |

### Rate Limit Headers

All API responses include rate limit headers:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 42          # seconds until the window resets (not an epoch)
X-RateLimit-Bucket: write      # or "diagnostic" for initialize / wp_rate_limit_status / wp_site_info
```

### Check Rate Limit Status

```http
GET /rate-limit
```

**Response:**

```json
{
  "enabled": true,
  "limits": {
    "per_minute": 60,
    "per_hour": 1000
  },
  "usage": {
    "identifier": "192.168.1.1",
    "minute": {
      "used": 15,
      "limit": 60,
      "remaining": 45,
      "reset": 1699574400
    },
    "hour": {
      "used": 150,
      "limit": 1000,
      "remaining": 850,
      "reset": 1699577400
    }
  },
  "relay_admission": "atomic",
  "relay_admission_basis": "redis_object_cache",
  "relay_limits": {
    "per_ip": { "limit": 10, "window_seconds": 60 },
    "global_per_minute": { "limit": 20, "window_seconds": 60 },
    "global_per_hour": { "limit": 200, "window_seconds": 3600 }
  }
}
```

`relay_admission`, `relay_admission_basis` and `relay_limits` describe the public feedback relay (`POST /feedback/relay`), not the API limits above. The relay is unauthenticated and spends the site's GitHub token, so it has three ceilings of its own: per source address, site-wide per minute, and site-wide per hour.

`GET /rate-limit` needs **read** scope (only `PUT`/`POST` need admin), so any read-scope API key or OAuth token for this site can see these fields. They reveal the relay's ceilings, which are constants in the plugin's published source, and how this site's object cache enforces them. None of it appears in the public relay's own responses.

| `relay_admission` | Meaning |
|---|---|
| `atomic` | Admission is decided on an increment known to be atomic: a recognised object-cache drop-in (Redis Object Cache without igbinary, or Automattic memcached) with its backend reachable and the `mcpwp_relay_admission` group persistent. Concurrent requests cannot take a slot past a ceiling. |
| `unverified` | An object-cache drop-in the plugin does not recognise. Admission is decided on its increment, but whether that increment is atomic, or whether the drop-in is currently persisting anything, cannot be determined. Treat it as no stronger than `approximate`. |
| `approximate` | A burst of concurrent requests can pass a ceiling by roughly the size of the burst. Sequential traffic is still bounded. |
| `fail_closed` | A recognised drop-in reports its backend unreachable, so no counter (transients included) can outlast a request. The relay refuses every request with `503 relay_unavailable` until the backend is back. |
| `unavailable` | The relay controller is not loaded, so nothing can be determined. `relay_limits` is omitted. |

| `relay_admission_basis` | Mode |
|---|---|
| `no_persistent_object_cache` | `approximate`: no object-cache drop-in, so the transient read-then-write decides |
| `object_cache_api_incomplete` | `approximate`: `wp_cache_add` / `wp_cache_incr` / `wp_cache_decr` missing |
| `object_cache_backend_unreachable` | `fail_closed`: Redis Object Cache `redis_status()` is false, or memcached has recorded connection errors |
| `cache_addition_suspended` | `approximate`: `wp_suspend_cache_addition()` is on for the reporting request |
| `admission_group_not_persistent` | `approximate`: `mcpwp_relay_admission` is in Redis `WP_REDIS_IGNORED_GROUPS` / non-persistent groups, or in memcached `no_mc_groups` |
| `object_cache_incr_not_atomic` | `approximate`: Redis Object Cache with `WP_REDIS_IGBINARY` increments by read-then-write |
| `object_cache_max_ttl_shorter_than_window` | `approximate`: `WP_REDIS_MAXTTL` is under an hour, so a counter can expire inside its own window |
| `redis_object_cache` / `memcached_object_cache` | `atomic` |
| `unrecognised_object_cache` | `unverified` |
| `relay_controller_not_loaded` | `unavailable` |

What the relay guarantees, precisely:

- **429 refusals.** A request refused at a ceiling writes nothing to the transient record and hands back any increment it took. Until it hands that increment back, a concurrent request can be refused one slot early. If the hand-back itself fails, the slot stays counted until the window ends. Neither can admit a request past a ceiling.
- **503 `relay_unavailable`.** Returned when a counter write fails, when an increment does not return a count, and in `fail_closed`. An unreadable transient counter is different: WordPress cannot tell "absent" from "unreadable", so it reads as zero. On an `approximate` site, that means an unreadable record admits.

### Rate Limit Exceeded Response

When rate limited, the API returns `429 Too Many Requests`:

```json
{
  "code": "rate_limit_exceeded",
  "message": "Rate limit exceeded. 60 requests per minute allowed. Try again in 45 seconds.",
  "data": {
    "status": 429,
    "retry_after": 45,
    "limit": 60,
    "remaining": 0,
    "reset": 1699574400
  }
}
```

### Batches and weight

A call that carries a list of operations is a batch by another name and is charged one slot per operation: a JSON-RPC batch, `POST /batch`, and any tool whose arguments carry `operations`, `updates`, `pages`, `posts`, `items`, `urls`, `files`, `media`, `page_ids`, `post_ids`, `rows`, `widgets` or `sections` (the largest list is the price; two spellings of one list are not summed). A call that does not fit the remaining budget is refused whole before anything runs (`429`, or `-32029` inside MCP). A call heavier than the whole window (`batch_exceeds_limit`, `413`) is refused with the ceiling: waiting will not help, split it.

The reachable ceiling is the tightest window: `min(burst, per-minute, per-hour)`. At the defaults (burst 30, 60/min, 1000/h) that is **30 operations per call**, whatever the per-endpoint maximum says; raise `burst_limit` to use larger batches.

### Idempotent tool calls (`params._meta.request_id`)

Any `tools/call` with side effects (every non-GET tool) may carry `params._meta.request_id` (1–128 chars of `[A-Za-z0-9_-:.]`, unique per intended write and per agent). The first completed result is stored for 24 hours under the principal + id; a re-send with the same id and arguments returns the stored result with `result._meta.replayed: true` and `replayed_at`, and the write does not run again. Two concurrent identical sends: exactly one runs (`-32009 in_progress` for the other, with `retry_after`).

| `result._meta.idempotency` | Meaning |
|---|---|
| `stored` | pinned; a re-send replays |
| `not_applicable` | read tool, unknown tool, or a tool that opts out (`reason` says which) |
| `not_stored` | the call ran but is NOT protected — partial failure, result too large, no per-user principal, or the claim store could not arbitrate (`reason` says which) |

Errors: `-32602` (invalid id, or the id was used with different arguments), `-32009` (in progress), `-32010` (stored record unreadable — not re-run), `-32029` (over budget, nothing ran). A partial failure (a batch with any failed operation) is never pinned: a retry re-runs the operations that succeeded, so retry only the failed ones, each under its own `request_id`. Ids are scoped to the principal; two clients acting as the same user or sharing one key share the namespace — make ids unique per agent.

### IP Whitelisting

Configure trusted IPs in WordPress settings to bypass rate limiting

---

## Destructive Operations

Since 3.11.0, an operation with no undo does not run directly. It becomes an approval request, and the call returns `202 Accepted` with nothing changed:

```json
{
  "approval_required": true,
  "approval_id": "<id>",
  "status": "pending",
  "operation": "delete_post",
  "message": "delete_post is a destructive operation and this site requires approval for it. Nothing was changed. ...",
  "hint": "Approval is the default for operations with no undo ..."
}
```

**Gated operations:** `delete_page`, `delete_post`, `delete_content`, `delete_media`, `delete_product`, `delete_elementor_template`, `delete_widget`, `delete_course_category`, `delete_all_drafts`, and `delete_user` (REST only).

**When a post-type delete counts as permanent**, and is therefore gated:

| Case | Gated |
|------|-------|
| `force=true` | Yes |
| The post is already in the trash | Yes |
| Media, unless the site defines `MEDIA_TRASH` | Yes |
| Any type other than post, page or product (templates, blocks, custom types) | Yes |
| Post, page or product when `EMPTY_TRASH_DAYS` is `0` | Yes |
| Post, page or product moved to the trash | No, runs immediately |

Widgets, course categories and users have no trash, so deleting them is always gated. Purging all drafts follows the post rule: gated with `force=true` or when `EMPTY_TRASH_DAYS` is `0`, otherwise the drafts are trashed immediately.

**Approving.** A reviewer other than the requester approves (`wp_approve_request`, `POST /approvals/{id}/approve`) and applies (`wp_apply_approval`, `POST /approvals/{id}/apply`). A WordPress administrator can do both from the Control Room. The target is fingerprinted when the request is made. If it changed before apply — title, status or modified date of a post, the set of drafts, a term, widget or user — apply is refused with `409` and the response shows what was reviewed next to what is there now.

**Refused before queueing:**

- A caller that could not perform the delete itself is refused, not queued.
- `destructive_id_mismatch` (`409`) when the route id and the body id disagree.
- `invalid_target` (`400`) when the target cannot be identified.
- A requester may hold 20 pending requests; API keys together may hold 100.

**Exemptions** are set by an administrator in wp-admin only: the site-wide switch in MCPWP Settings, or `allow_destructive` on a single API key when it is created there. An `allow_destructive` sent through the API is ignored and reported under `ignored`.

---

## Endpoints

### Site Info

#### Get Site Information

```http
GET /site-info
```

Returns WordPress site details and detected capabilities.

**Response:**

```json
{
  "name": "My Website",
  "description": "Just another WordPress site",
  "url": "https://example.com",
  "admin_url": "https://example.com/wp-admin/",
  "wp_version": "6.4.2",
  "php_version": "8.2.0",
  "theme": {
    "name": "Flavor flavor flavor flavor flavore flavor",
    "version": "1.0"
  },
  "timezone": "America/New_York",
  "language": "en_US",
  "capabilities": {
    "elementor": true,
    "elementor_pro": true,
    "woocommerce": false,
    "yoast": true,
    "rankmath": false,
    "cf7": true,
    "wpforms": false,
    "gutenberg": true,
    "ai_integrations": true,
    "ai_configured_providers": ["openai", "pexels"]
  },
  "plugin": {
    "name": "MCPWP",
    "version": "1.1.0"
  }
}
```

#### Get Plugin Detection

```http
GET /plugins
```

Returns detailed plugin detection information.

---

### Posts

#### List Posts

```http
GET /posts
```

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `per_page` | integer | 10 | Posts per page (max: 100) |
| `page` | integer | 1 | Page number |
| `status` | string | publish | Post status filter |
| `category` | integer | - | Category ID filter |
| `search` | string | - | Search term |

**Example:**

```bash
curl -H "X-API-Key: mcpwp_xxx" \
  "https://example.com/wp-json/mcpwp/v1/posts?per_page=5&status=publish"
```

**Response:**

```json
{
  "posts": [
    {
      "id": 123,
      "title": "Hello World",
      "slug": "hello-world",
      "status": "publish",
      "content": "<p>Welcome to WordPress...</p>",
      "excerpt": "Welcome to WordPress...",
      "author": 1,
      "date": "2024-01-15T10:30:00",
      "modified": "2024-01-15T10:30:00",
      "link": "https://example.com/hello-world/",
      "featured_image": null,
      "categories": [1],
      "tags": []
    }
  ],
  "total": 42,
  "pages": 9,
  "page": 1
}
```

#### Create Post

```http
POST /posts
```

**Body:**

```json
{
  "title": "My New Post",
  "content": "<p>This is the post content.</p>",
  "status": "publish",
  "excerpt": "A brief summary",
  "terms": { "genre": ["fiction", 12] },
  "meta": { "subtitle": "A short line under the title" }
}
```

`terms` and `meta` are accepted on create and update of posts, pages and custom post types.

- **`terms`** is `{taxonomy: [ids, slugs or names]}` and replaces that taxonomy's current set. An integer is an id; a string is a slug or a name, even when it is all digits. An unknown taxonomy or term is refused before anything is written. Use `categories` and `tags` for the built-in taxonomies; naming one twice is refused.
- **`meta`** is `{key: value}`. Registered meta runs through its registered sanitize callback; unregistered strings are reduced to post-safe HTML. Keys with a leading underscore, `_elementor_*`, `mcpwp_*` and secret-shaped keys (password, token, secret, credential, auth) are refused here. Write underscore keys through [Set Post Meta](#set-post-meta).

**Response:** Returns the created post object with `201 Created` status.

#### Get Single Post

```http
GET /posts/{id}
```

#### Update Post

```http
PUT /posts/{id}
```

**Body:**

```json
{
  "title": "Updated Title",
  "content": "Updated content"
}
```

#### Delete Post

```http
DELETE /posts/{id}
```

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `force` | boolean | false | Permanently delete (bypass trash) |

> A delete with no undo is queued for approval by default. See [Destructive Operations](#destructive-operations).

---

### Pages

#### List Pages

```http
GET /pages
```

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `per_page` | integer | 10 | Pages per page |
| `page` | integer | 1 | Page number |
| `status` | string | any | Page status |
| `parent` | integer | - | Parent page ID |

#### Create Page

```http
POST /pages
```

**Body:**

```json
{
  "title": "About Us",
  "content": "<p>About our company...</p>",
  "status": "publish",
  "parent": 0,
  "template": "templates/full-width.php"
}
```

#### Get/Update Page

```http
GET /pages/{id}
PUT /pages/{id}
```

#### Delete Page

```http
DELETE /pages/{id}
```

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `force` | boolean | false | Permanently delete (bypass trash) |

> A delete with no undo is queued for approval by default. See [Destructive Operations](#destructive-operations).

#### List Page Templates

```http
GET /templates/page
```

**Response:**

```json
{
  "templates": [
    {"slug": "default", "name": "Default Template"},
    {"slug": "templates/full-width.php", "name": "Full Width"},
    {"slug": "elementor_header_footer", "name": "Elementor Canvas"}
  ],
  "total": 3
}
```

---

### Post Meta

#### Get Post Meta

```http
GET /post-meta/{id}
GET /post-meta/{id}?key=subtitle
```

Returns a post's meta, or one key. Meta on a draft, pending or private post needs write scope; anyone else gets `404`.

Some keys are never returned: `_wp_*`, `_edit_lock`, `_edit_last`, `_thumbnail_id`, `_elementor_*`, transients, menu-item internals, and keys named like a credential — password, secret, token, credential, auth, webhook URL, API signature, serial, PIN or license. Asking for one by name returns `403 forbidden_meta_key`. A value shaped like a credential is masked whatever its key is called.

**Response:**

```json
{ "id": 123, "meta": { "subtitle": "value", "rating": "4" } }
```

With `key`:

```json
{ "id": 123, "key": "subtitle", "value": "value" }
```

#### Set Post Meta

```http
POST /post-meta/{id}
```

Writes one key. A JSON object or array sent as a string is stored as a structure. A `null` value deletes the key.

**Body:**

```json
{ "key": "subtitle", "value": "A short line under the title" }
```

**Response:** the value reported is the one WordPress stored, read back after the write.

```json
{ "id": 123, "key": "subtitle", "value": "A short line under the title", "updated": true, "deleted": false }
```

**Rules:**

- A key with characters outside `[A-Za-z0-9_-.:]` is refused with `400 invalid_meta_key`, never rewritten onto another key.
- Leading-underscore keys are accepted on this route. `_thumbnail_id` must be a media attachment id.
- `_elementor_*` keys are refused with `400 use_elementor_endpoint`; use the Elementor tools.
- Secret-shaped keys are refused before anything is written.
- Registered meta runs through its registered sanitize callback; unregistered strings are reduced to post-safe HTML.

---

### Media

#### List Media

```http
GET /media
```

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `per_page` | integer | 20 | Items per page |
| `page` | integer | 1 | Page number |
| `mime_type` | string | - | Filter by MIME type (image, video, etc.) |

#### Upload Media

```http
POST /media
Content-Type: multipart/form-data
```

**Form Data:**

- `file` - The file to upload
- `title` - Optional title
- `alt` - Optional alt text

**Example with curl:**

```bash
curl -H "X-API-Key: mcpwp_xxx" \
  -F "file=@/path/to/image.jpg" \
  -F "title=My Image" \
  -F "alt=Description of image" \
  https://example.com/wp-json/mcpwp/v1/media
```

#### Upload from URL

```http
POST /media/from-url
```

**Body:**

```json
{
  "url": "https://example.com/image.jpg",
  "title": "Downloaded Image",
  "alt": "Image description",
  "filename": "custom-name.jpg"
}
```

#### Bulk Upload from URLs

```http
POST /media/bulk
```

Upload multiple images in a single request (max 20).

**Body (simple):**

```json
{
  "urls": [
    "https://example.com/image1.jpg",
    "https://example.com/image2.jpg",
    "https://example.com/image3.jpg"
  ]
}
```

**Body (with metadata):**

```json
{
  "items": [
    {"url": "https://example.com/hero.jpg", "title": "Hero Image", "alt": "Main banner"},
    {"url": "https://example.com/logo.png", "title": "Logo", "alt": "Company logo"}
  ]
}
```

**Response:**

```json
{
  "uploaded": 2,
  "failed": 0,
  "media": [
    {"id": 123, "url": "https://site.com/wp-content/uploads/hero.jpg", "title": "Hero Image"},
    {"id": 124, "url": "https://site.com/wp-content/uploads/logo.png", "title": "Logo"}
  ],
  "errors": []
}
```

#### Delete Media

```http
DELETE /media/{id}
```

Delete a media attachment. WordPress trashes media only when the site defines `MEDIA_TRASH`; otherwise every media delete is permanent and is queued for approval by default. See [Destructive Operations](#destructive-operations).

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `id` | integer | *(required)* | Attachment ID |
| `force` | boolean | `false` | `true` = permanent delete. `false` = move to trash, only when `MEDIA_TRASH` is defined |

**Example:**

```bash
curl -X DELETE -H "X-API-Key: mcpwp_xxx" \
  "https://example.com/wp-json/mcpwp/v1/media/456?force=true"
```

**Response:**

```json
{
  "success": true,
  "deleted": {
    "id": 456,
    "title": "My Image",
    "url": "https://example.com/wp-content/uploads/2026/02/my-image.jpg"
  },
  "force": true
}
```

---

### Elementor (Core)

#### Get Elementor Status

```http
GET /elementor/status
```

**Response:**

```json
{
  "active": true,
  "version": "3.18.0",
  "pro": true,
  "pro_version": "3.18.0"
}
```

#### Get Page Elementor Data

```http
GET /elementor/{id}
```

Returns the Elementor JSON structure for a page.

#### Update Page Elementor Data

```http
POST /elementor/{id}
```

**Body:**

```json
{
  "elementor_data": [
    {
      "id": "abc123",
      "elType": "section",
      "settings": {},
      "elements": [
        {
          "id": "def456",
          "elType": "column",
          "settings": {"_column_size": 100},
          "elements": [
            {
              "id": "ghi789",
              "elType": "widget",
              "widgetType": "heading",
              "settings": {
                "title": "Hello World",
                "header_size": "h1"
              }
            }
          ]
        }
      ]
    }
  ]
}
```

#### Create Elementor Page

```http
POST /elementor/page
```

**Body:**

```json
{
  "title": "New Landing Page",
  "status": "draft",
  "elementor_data": []
}
```

#### Get Elementor Summary

```http
GET /elementor/{id}/summary
```

*New in v1.1.3.* Returns a lightweight structural summary of a page's Elementor data — typically <1K tokens instead of 64K+ for the full data. Ideal for AI inspection before editing.

**Response:**

```json
{
  "page_id": 95,
  "title": "Home",
  "has_elementor": true,
  "section_count": 4,
  "widget_count": 12,
  "sections": [
    {
      "type": "section",
      "children": [
        {
          "type": "column",
          "children": [
            {
              "widget": "heading",
              "settings": {"title": "Welcome to Our Site", "header_size": "h1"}
            },
            {
              "widget": "text-editor",
              "settings": {"editor": "We build amazing things..."}
            }
          ]
        }
      ]
    }
  ]
}
```

Key display settings are extracted per widget type (e.g., `title` for headings, `url` for images, `text` for buttons). Full settings are not included — use `GET /elementor/{id}` when you need the complete data.

---

### Elementor Pro

*Requires Pro license*

#### List Templates

```http
GET /elementor/templates
```

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `per_page` | integer | 50 | Templates per page |
| `type` | string | - | Template type filter |

#### Create Template

```http
POST /elementor/templates
```

**Body:**

```json
{
  "title": "Hero Section Template",
  "type": "section",
  "elementor_data": []
}
```

#### Apply Template to Page

```http
POST /elementor/templates/{id}/apply
```

**Body:**

```json
{
  "page_id": 123
}
```

#### Clone Page

```http
POST /elementor/clone
```

**Body:**

```json
{
  "source_id": 123,
  "title": "Page Copy",
  "status": "draft"
}
```

#### Create Landing Page

```http
POST /elementor/landing-page
```

**Body:**

```json
{
  "title": "Product Launch",
  "status": "draft",
  "sections": ["hero", "features", "testimonials", "cta"]
}
```

#### Build Page from Section Blueprints

```http
POST /elementor/build-page
```

*New in v1.1.3. Requires Pro license.*

Build a complete Elementor page from semantic section definitions. No raw Elementor JSON required — the plugin generates valid element trees with auto-generated IDs, correct column structures, and proper widget settings. Detects whether the site uses classic sections or flexbox containers.

**Body:**

```json
{
  "title": "My Landing Page",
  "status": "draft",
  "sections": [
    {
      "type": "hero",
      "heading": "Build Faster with AI",
      "subheading": "Ship landing pages in seconds, not hours.",
      "button_text": "Get Started",
      "button_url": "/get-started",
      "background_color": "#0B1220",
      "text_color": "#FFFFFF"
    },
    {
      "type": "features",
      "heading": "Why Choose Us",
      "items": [
        {"icon": "fas fa-rocket", "title": "Fast", "description": "Deploy in seconds"},
        {"icon": "fas fa-shield-alt", "title": "Secure", "description": "Enterprise-grade security"},
        {"icon": "fas fa-code", "title": "Developer-Friendly", "description": "Full API access"}
      ]
    },
    {
      "type": "pricing",
      "heading": "Simple Pricing",
      "items": [
        {
          "title": "Free",
          "price": "$0",
          "period": "/month",
          "features": ["5 pages", "Basic support", "Community access"],
          "button_text": "Start Free",
          "button_url": "/signup"
        },
        {
          "title": "Pro",
          "price": "$29",
          "period": "/month",
          "features": ["Unlimited pages", "Priority support", "AI tools", "Theme builder"],
          "button_text": "Go Pro",
          "button_url": "/upgrade",
          "featured": true
        }
      ]
    },
    {
      "type": "faq",
      "heading": "Frequently Asked Questions",
      "items": [
        {"question": "How does it work?", "answer": "Install the plugin, connect your AI assistant, and start building."},
        {"question": "Is there a trial?", "answer": "Paid plans and trials are managed through Freemius."}
      ]
    },
    {
      "type": "cta",
      "heading": "Ready to Get Started?",
      "subheading": "Join thousands of sites powered by AI.",
      "button_text": "Try It Now",
      "button_url": "/get-started"
    }
  ]
}
```

**Available Section Types:**

| Type | Key Params | Description |
|------|-----------|-------------|
| `hero` | `heading`, `subheading`, `button_text`, `button_url`, `background_color`, `text_color`, `background_image` | Full-width hero banner with CTA |
| `features` | `heading`, `items[]` (`icon`, `title`, `description`) | Multi-column icon-box grid |
| `cta` | `heading`, `subheading`, `button_text`, `button_url` | Centered call-to-action strip |
| `pricing` | `heading`, `items[]` (`title`, `price`, `period`, `features[]`, `button_text`, `button_url`, `featured`) | Pricing columns |
| `faq` | `heading`, `items[]` (`question`, `answer`) | Accordion FAQ section |
| `testimonials` | `heading`, `items[]` (`name`, `text`, `image`, `title`) | Testimonial cards |
| `text` | `heading`, `body` | Simple heading + text block |
| `gallery` | `heading`, `images[]` (attachment IDs) | Image gallery |

**Response:**

```json
{
  "success": true,
  "page_id": 234,
  "title": "My Landing Page",
  "status": "draft",
  "url": "https://example.com/?page_id=234",
  "edit_url": "https://example.com/wp-admin/post.php?post=234&action=elementor",
  "sections_built": 5
}
```

#### Get Available Widgets

```http
GET /elementor/widgets
```

#### Get Global Settings

```http
GET /elementor/globals
```

#### Set Global Settings

```http
POST /elementor/globals
```

*Requires Pro license*

Set Elementor global colors, fonts, and typography settings.

**Body:**

```json
{
  "colors": {
    "primary": "#0B1220",
    "secondary": "#1B4DFF",
    "text": "#4A5568",
    "accent": "#F6F8FF"
  },
  "typography": {
    "primary": {
      "font_family": "Poppins",
      "font_weight": "600"
    },
    "secondary": {
      "font_family": "Poppins",
      "font_weight": "400"
    }
  }
}
```

**Response:**

```json
{
  "success": true,
  "updated": ["colors", "typography"]
}
```

---

### Gutenberg Blocks

Manage content using the WordPress block editor (Gutenberg). Available when the `gutenberg` capability is `true` in site-info (requires WordPress 5.0+ and Classic Editor not forcing classic mode).

#### Get Blocks

```http
GET /blocks/{id}
```

Returns parsed Gutenberg blocks for a post or page.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Post or page ID |

**Response:**

```json
{
  "post_id": 123,
  "blocks": [
    {
      "blockName": "core/heading",
      "attrs": {
        "level": 2
      },
      "innerBlocks": [],
      "innerHTML": "<h2 class=\"wp-block-heading\">Hello World</h2>"
    },
    {
      "blockName": "core/paragraph",
      "attrs": {},
      "innerBlocks": [],
      "innerHTML": "<p>Welcome to my site.</p>"
    }
  ],
  "raw_content": "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Hello World</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Welcome to my site.</p>\n<!-- /wp:paragraph -->"
}
```

#### Set Blocks

```http
POST /blocks/{id}
```

Set Gutenberg blocks for a post or page. Accepts either a structured blocks array or a raw content string.

**Body (blocks array):**

```json
{
  "blocks": [
    {
      "blockName": "core/heading",
      "attrs": {"level": 2},
      "innerHTML": "<h2 class=\"wp-block-heading\">Hello World</h2>"
    },
    {
      "blockName": "core/paragraph",
      "attrs": {},
      "innerHTML": "<p>This is a paragraph.</p>"
    }
  ]
}
```

**Body (raw content string):**

```json
{
  "content": "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Hello World</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>This is a paragraph.</p>\n<!-- /wp:paragraph -->"
}
```

**Response:**

```json
{
  "success": true,
  "post_id": 123
}
```

#### List Block Types

```http
GET /block-types
```

Returns all registered block types available on the site.

**Response:**

```json
{
  "block_types": [
    {
      "name": "core/heading",
      "title": "Heading",
      "category": "text",
      "description": "Introduce new sections and organize content to help visitors find what they're looking for.",
      "keywords": ["title", "subtitle"],
      "supports": {
        "align": ["wide", "full"],
        "color": true,
        "typography": true
      }
    },
    {
      "name": "core/paragraph",
      "title": "Paragraph",
      "category": "text",
      "description": "Start with the basic building block of all narrative.",
      "keywords": ["text"],
      "supports": {
        "color": true,
        "typography": true
      }
    }
  ],
  "total": 150
}
```

#### List Block Patterns

```http
GET /block-patterns
```

Returns all registered block patterns available on the site.

**Response:**

```json
{
  "block_patterns": [
    {
      "name": "core/text-two-columns",
      "title": "Two columns of text",
      "categories": ["text"],
      "description": "Two columns of regular text.",
      "keywords": ["columns", "text"],
      "content": "<!-- wp:columns --><div class=\"wp-block-columns\">..."
    }
  ],
  "total": 45
}
```

---

### SEO

*Requires Pro license. Supports Yoast, RankMath, AIOSEO, SEOPress*

#### Get SEO Status

```http
GET /seo/status
```

**Response:**

```json
{
  "active_plugin": "yoast",
  "plugins": {
    "yoast": true,
    "rankmath": false,
    "aioseo": false,
    "seopress": false
  }
}
```

#### Get Post SEO Data

```http
GET /seo/{id}
```

**Response:**

```json
{
  "post_id": 123,
  "title": "Custom SEO Title",
  "description": "Meta description for search engines",
  "focus_keyword": "wordpress seo",
  "canonical": "https://example.com/page/",
  "og_title": "Social Share Title",
  "og_description": "Social share description",
  "og_image": "https://example.com/image.jpg",
  "robots_noindex": false,
  "robots_nofollow": false,
  "score": 85
}
```

#### Update Post SEO

```http
PUT /seo/{id}
```

**Body:**

```json
{
  "title": "Optimized SEO Title | Brand",
  "description": "Compelling meta description under 160 characters.",
  "focus_keyword": "target keyword",
  "og_title": "Share on Social",
  "og_description": "Description for social media",
  "robots_noindex": false
}
```

#### Bulk Update SEO

```http
POST /seo/bulk
```

**Body:**

```json
{
  "updates": [
    {"id": 123, "title": "Page 1 SEO Title"},
    {"id": 124, "title": "Page 2 SEO Title"},
    {"id": 125, "title": "Page 3 SEO Title"}
  ]
}
```

#### Analyze SEO

```http
GET /seo/{id}/analyze
```

Returns SEO analysis and recommendations.

---

### Forms

*Requires Pro license. Supports CF7, WPForms, Gravity Forms, Ninja Forms*

#### Get Forms Status

```http
GET /forms/status
```

**Response:**

```json
{
  "cf7": true,
  "wpforms": false,
  "gravityforms": true,
  "ninjaforms": false
}
```

#### List All Forms

```http
GET /forms
```

#### List Forms by Plugin

```http
GET /forms/{plugin}
```

Where `{plugin}` is: `cf7`, `wpforms`, `gravityforms`, or `ninjaforms`

#### Get Form Details

```http
GET /forms/{plugin}/{id}
```

#### Get Form Entries

```http
GET /forms/{plugin}/{id}/entries
```

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `per_page` | integer | 50 | Entries per page |
| `offset` | integer | 0 | Offset for pagination |

---

### Users

*Requires Pro license*

#### List Users

```http
GET /users
```

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `per_page` | integer | 50 | Users per page |
| `page` | integer | 1 | Page number |
| `role` | string | - | Filter by role |
| `search` | string | - | Search term |

#### Create User

```http
POST /users
```

**Body:**

```json
{
  "username": "newuser",
  "email": "user@example.com",
  "password": "SecurePass123!",
  "display_name": "New User",
  "first_name": "New",
  "last_name": "User",
  "role": "editor",
  "send_notification": true
}
```

#### Get User

```http
GET /users/{id}
```

#### Update User

```http
PUT /users/{id}
```

**Body:**

```json
{
  "display_name": "Updated Name",
  "role": "author"
}
```

#### Delete User

```http
DELETE /users/{id}
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `reassign` | integer | User ID to reassign posts to |

> A delete with no undo is queued for approval by default. See [Destructive Operations](#destructive-operations).

#### Get User Stats

```http
GET /users/stats
```

#### Get All Roles

```http
GET /users/roles
```

#### Get User Capabilities

```http
GET /users/{id}/capabilities
```

---

### Menus

Basic menu CRUD is available in the core tool set. Paid plans add `GET /menus/{id}`, `POST /menus`, and `PUT /menus/{id}`.

#### List Menus

```http
GET /menus
```

#### Setup Menu (create + assign + add pages)

```http
POST /menus/setup
```

**Body:**

```json
{
  "name": "Main Navigation",
  "location": "primary",
  "page_ids": [95, 32, 33],
  "overwrite": false
}
```

#### Menu Locations

```http
GET /menus/locations
POST /menus/assign-location   // body: {"menu_id": 5, "location": "primary"}
```

#### Get/Create/Update/Delete Menu *(Pro)*

```http
GET /menus/{id}
POST /menus            // body: {"name": "Footer", "location": "footer", "items": [...]}
PUT /menus/{id}        // body: {"name": "New Name", "location": "primary"}
DELETE /menus/{id}
```

#### List Menu Items

```http
GET /menus/{menu_id}/items
```

#### Add Menu Item

```http
POST /menus/{menu_id}/items
```

**Body:**

```json
{
  "title": "About Us",
  "url": "/about/",
  "type": "custom",
  "parent_id": 0,
  "position": 1,
  "classes": ["highlight", "btn"],
  "target": "_blank",
  "description": "Learn more about us"
}
```

| Param | Type | Notes |
|-------|------|-------|
| `title` | string | **Required**. Menu item label |
| `type` | string | `custom`, `post_type`, or `taxonomy` (default: `custom`) |
| `url` | string | Required for custom links |
| `object` | string | Object type for post_type/taxonomy (`page`, `category`, etc.) |
| `object_id` | number | Object ID for post_type/taxonomy items |
| `parent_id` | number | Parent menu item ID for sub-menus (0 = top level) |
| `position` | number | Menu order position |
| `classes` | array | CSS classes for styling |
| `target` | string | `_blank` (new tab) or `_self` (same tab) |
| `description` | string | Item description/tooltip (theme-dependent) |

#### Update/Delete Menu Item

```http
PUT /menus/{menu_id}/items/{item_id}
DELETE /menus/{menu_id}/items/{item_id}
```

Update accepts same params as add (all optional except `menu_id` and `item_id`).

#### Reorder Menu Items

```http
POST /menus/{menu_id}/items/reorder
```

**Body:**

```json
{
  "items": [
    {"id": 10, "position": 1, "parent_id": 0},
    {"id": 11, "position": 2, "parent_id": 0},
    {"id": 12, "position": 1, "parent_id": 10}
  ]
}
```

---

### Settings

*Requires Pro license*

#### Get All Settings

```http
GET /settings
```

**Response:**

```json
{
  "title": "My Website",
  "tagline": "Just another WordPress site",
  "admin_email": "admin@example.com",
  "timezone": "America/New_York",
  "date_format": "F j, Y",
  "time_format": "g:i a",
  "posts_per_page": 10,
  "show_on_front": "page",
  "page_on_front": 2,
  "page_for_posts": 10
}
```

#### Update Settings

```http
PUT /settings
```

**Body:**

```json
{
  "title": "New Site Title",
  "tagline": "A better tagline",
  "posts_per_page": 12
}
```

---

### Options

*Requires Pro license*

#### Get Site Options

```http
GET /options
```

Returns reading and front page settings.

**Response:**

```json
{
  "show_on_front": "page",
  "page_on_front": 2,
  "page_for_posts": 10,
  "posts_per_page": 10,
  "posts_per_rss": 10,
  "blog_public": "1"
}
```

#### Update Site Options

```http
PUT /options
```

**Body:**

```json
{
  "show_on_front": "page",
  "page_on_front": 123,
  "page_for_posts": 456,
  "posts_per_page": 12
}
```

**Allowed Options:**

| Option | Description |
|--------|-------------|
| `show_on_front` | `posts` or `page` |
| `page_on_front` | Homepage page ID |
| `page_for_posts` | Blog page ID |
| `posts_per_page` | Posts per page (1-100) |

#### Get Single Option

```http
GET /option?key=blogname
```

One policy decides which options an API caller may read or write, and what is masked on the way out.

- **Reachable:** WordPress core settings, the settings of recognised plugins, and these MCPWP keys: `mcpwp_site_context`, `mcpwp_site_context_updated`, `mcpwp_design_references`, `mcpwp_site_profile`, `mcpwp_wc_product_archetypes`, `mcpwp_action_log_retention_days`.
- **Never reachable**, for any scope: salts and keys, user roles, active plugins, cron, transients, sessions, private and encryption keys, and MCPWP's own state and credentials.
- **Masked:** a value under a secret-shaped name at any depth, or shaped like a credential, reads back as `***`. `redacted` lists the paths. An option stored as a JSON or serialised string is decoded, masked and reported with `decoded_from`.

**Response:**

```json
{ "key": "blogname", "value": "My Website", "redacted": [], "rule": "core_key" }
```

A refusal is `403 forbidden_option` with `data.rule` and `data.hint`. A malformed name is `400 invalid_option_name`.

#### Update Single Option

```http
POST /option
```

**Body:**

```json
{ "key": "blogname", "value": "New Site Title" }
```

| Name | Type | Description |
|------|------|-------------|
| `key` | string | Option name |
| `value` | any | New value |
| `allow_secrets` | boolean | Required to set or overwrite a secret-shaped field |
| `force_type_change` | boolean | Required to overwrite a stored array or object with a plain string that is not JSON |

**Response:** reports what WordPress stored, not what was sent.

```json
{
  "key": "blogname",
  "value": "New Site Title",
  "old_value": "My Website",
  "redacted": [],
  "preserved": [],
  "warnings": [],
  "updated": true,
  "rule": "core_key"
}
```

**Rules:**

- Omit a secret field to keep what is stored; `preserved` lists the fields kept. A value containing `***` anywhere is refused with `409 mask_in_write`, because it is a redaction marker from a read, not data.
- Setting or overwriting a secret-shaped field without `allow_secrets: true` is refused with `409 secret_write_refused` and `data.secret_paths`. So is `force_type_change` over a stored value that holds secrets.
- A plain string over a stored array or object is decoded when it is JSON, and otherwise refused with `409 option_type_mismatch` unless `force_type_change` is set.
- `widget_*`, `sidebars_*` and `auto_update_*` options are not writable here. Use the widget tools.
- A key without admin scope cannot store script, event handlers, `javascript:` URLs or embedded frames: `403 active_markup_requires_admin`.
- `warnings` says when WordPress altered the value on store, or when a plugin hook threw after the write landed.

---

### Site Context

A master prompt / style guide stored in the plugin. AI assistants read this on connect via `wp_introspect` and follow the design rules automatically. Stores design rules, header/footer structure, color palette, predefined sections, and page templates.

#### Get Site Context

```http
GET /site-context
```

Returns the site context (AI brief / style guide).

**Response (configured):**

```json
{
  "context": "# My Site Style Guide\n\n## Colors\n- Primary: #0B1220\n- Accent: #1B4DFF\n\n## Typography\n- Headings: Poppins 600\n- Body: Poppins 400\n\n## Header\n- Logo left, menu right\n- Sticky on scroll\n\n## Sections\n- Hero: full-width background image with overlay...",
  "updated_at": "2026-02-19T10:30:00+00:00"
}
```

**Response (not configured):**

```json
{
  "context": "",
  "updated_at": null,
  "hint": "No site context configured. Set one via POST /site-context or the Settings tab in wp-admin."
}
```

#### Set Site Context

```http
POST /site-context
```

**Body:**

```json
{
  "context": "# My Site Style Guide\n\n## Colors\n- Primary: #0B1220\n- Accent: #1B4DFF\n..."
}
```

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `context` | string | Yes | Markdown text, max 50 KB |

**Response:**

```json
{
  "success": true,
  "length": 1234,
  "updated_at": "2026-02-19T10:30:00+00:00"
}
```

#### Site Context in Introspect

When a site context is configured, the `GET /introspect` response (and `wp_introspect` MCP tool) includes a `site_context` field containing the full style guide text. AI assistants should read this first when building or editing pages.

---

### Favicon

*Requires Pro license*

Manage site icon (favicon) displayed in browser tabs.

#### Get Favicon

```http
GET /favicon
```

**Response:**

```json
{
  "has_icon": true,
  "attachment_id": 123,
  "sizes": {
    "32": "https://example.com/wp-content/uploads/cropped-icon-32x32.png",
    "180": "https://example.com/wp-content/uploads/cropped-icon-180x180.png",
    "192": "https://example.com/wp-content/uploads/cropped-icon-192x192.png",
    "270": "https://example.com/wp-content/uploads/cropped-icon-270x270.png",
    "512": "https://example.com/wp-content/uploads/icon.png"
  }
}
```

#### Set Favicon

```http
PUT /favicon
```

**Body (by Media ID):**

```json
{
  "attachment_id": 123
}
```

**Body (by URL):**

```json
{
  "url": "https://example.com/favicon.png"
}
```

The URL method will automatically download and import the image.

#### Remove Favicon

```http
DELETE /favicon
```

Removes the site icon. Returns `{"success": true}`.

---

### Widgets

*Requires Pro license*

Manage WordPress widgets and sidebars.

#### List Sidebars

```http
GET /widgets/sidebars
```

**Response:**

```json
{
  "sidebars": [
    {
      "id": "sidebar-1",
      "name": "Main Sidebar",
      "description": "Add widgets here to appear in your sidebar",
      "widgets": ["text-2", "recent-posts-3"]
    },
    {
      "id": "footer-1",
      "name": "Footer Widget Area",
      "description": "Footer column 1",
      "widgets": []
    }
  ],
  "total": 4
}
```

#### List Widget Types

```http
GET /widgets/types
```

Returns all registered widget types.

#### List Widgets

```http
GET /widgets
```

Returns all active widget instances.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `sidebar` | string | Filter by sidebar ID |

#### Get Widget

```http
GET /widgets/{id}
```

#### Create Widget

```http
POST /widgets
```

**Body:**

```json
{
  "type": "text",
  "sidebar": "sidebar-1",
  "settings": {
    "title": "Welcome",
    "text": "<p>Welcome to our site!</p>"
  }
}
```

#### Update Widget

```http
PUT /widgets/{id}
```

#### Delete Widget

```http
DELETE /widgets/{id}
```

#### Move Widget to Sidebar

```http
POST /widgets/{id}/move
```

**Body:**

```json
{
  "sidebar": "footer-1",
  "position": 0
}
```

---

### Themes

*Requires Pro license*

Unified theme settings management for popular WordPress themes.

#### Detect Active Theme

```http
GET /themes/detect
```

**Response:**

```json
{
  "active_theme": "astra",
  "theme_name": "Astra",
  "theme_version": "4.5.0",
  "is_supported": true,
  "supported_features": ["colors", "typography", "header", "footer"]
}
```

#### List Supported Themes

```http
GET /themes/supported
```

**Response:**

```json
{
  "themes": [
    {"slug": "astra", "name": "Astra", "features": ["colors", "typography", "header", "footer", "sidebar", "buttons"]},
    {"slug": "flavor flavor flavor flavor flavore flavor", "name": "GeneratePress", "features": ["colors", "typography", "layout"]},
    {"slug": "kadence", "name": "Flavor flavor flavor flavor flavore flavor", "features": ["colors", "typography", "header", "footer"]}
  ]
}
```

#### Get Theme Settings

```http
GET /themes/settings
```

Returns settings for the currently active theme in a normalized format.

**Response (Astra example):**

```json
{
  "theme": "astra",
  "colors": {
    "primary": "#0274be",
    "secondary": "#557799",
    "text": "#3a3a3a",
    "heading": "#3a3a3a",
    "background": "#ffffff",
    "link": "#0274be",
    "link_hover": "#3a3a3a"
  },
  "typography": {
    "body_font_family": "system-ui",
    "body_font_size": "16px",
    "heading_font_family": "inherit",
    "heading_font_weight": "600"
  },
  "header": {
    "type": "header-main-layout-1",
    "width": "content",
    "sticky": false
  },
  "footer": {
    "widgets_enabled": true,
    "copyright": "Copyright © 2024"
  }
}
```

#### Update Theme Settings

```http
PUT /themes/settings
```

**Body:**

```json
{
  "colors": {
    "primary": "#ff6b35"
  },
  "typography": {
    "body_font_size": "18px"
  }
}
```

#### Astra-Specific Endpoints

```http
GET /themes/astra/colors
PUT /themes/astra/colors

GET /themes/astra/typography
PUT /themes/astra/typography

GET /themes/astra/header
PUT /themes/astra/header

GET /themes/astra/footer
PUT /themes/astra/footer
```

---

### Theme Builder

*Requires Pro license and Elementor Pro*

#### Get Status

```http
GET /theme-builder/status
```

#### Get Theme Locations

```http
GET /theme-builder/locations
```

**Response:**

```json
{
  "locations": [
    {"id": "header", "label": "Header", "templates": []},
    {"id": "footer", "label": "Footer", "templates": []},
    {"id": "single", "label": "Single Post", "templates": []},
    {"id": "archive", "label": "Archive", "templates": []}
  ]
}
```

#### Get Available Conditions

```http
GET /theme-builder/conditions
```

#### List Theme Builder Templates

```http
GET /theme-builder/templates
```

#### Get/Set Template Conditions

```http
GET /theme-builder/templates/{id}/conditions
PUT /theme-builder/templates/{id}/conditions
DELETE /theme-builder/templates/{id}/conditions
```

**Set Conditions Body:**

```json
{
  "conditions": [
    {"type": "include", "name": "general"},
    {"type": "exclude", "name": "singular", "sub_name": "page"}
  ]
}
```

#### Assign Template to Location

```http
POST /theme-builder/templates/{id}/assign
```

**Body:**

```json
{
  "scope": "entire_site"
}
```

Scope options: `entire_site`, `singular`, `archive`, `specific`, `front_page`, `404`

#### Create Theme Template

```http
POST /theme-builder/templates
```

*New in v1.1.3.* Create a Theme Builder template, set its type, optionally populate with Elementor data, and assign display conditions — all in one call.

**Body:**

```json
{
  "title": "Site Header",
  "type": "header",
  "elementor_data": [],
  "scope": "entire_site"
}
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `title` | string | *(required)* | Template name |
| `type` | string | *(required)* | `header`, `footer`, `single`, or `archive` |
| `elementor_data` | array | - | Optional Elementor JSON to populate the template |
| `scope` | string | `entire_site` | Display condition scope |

**Response:**

```json
{
  "id": 567,
  "title": "Site Header",
  "type": "header",
  "scope": "entire_site",
  "edit_url": "https://example.com/wp-admin/post.php?post=567&action=elementor"
}
```

After creation, open the `edit_url` in Elementor to design the template visually, or use `POST /elementor/{id}` to set Elementor data programmatically.

Shared-host note:
If a host WAF such as HostGator `ModSecurity` rejects nested JSON bodies on Elementor routes, send Elementor payloads as `elementor_data_base64` or use `application/x-www-form-urlencoded` requests instead of raw JSON.

---

### WooCommerce (Pro)

Full WooCommerce integration for AI-powered e-commerce management.

> **Paid Feature:** Requires MCPWP with a valid Freemius license or trial.

#### WooCommerce Status

```http
GET /woocommerce/status
```

**Response:**

```json
{
  "active": true,
  "version": "8.5.1",
  "currency": "USD",
  "currency_symbol": "$",
  "weight_unit": "lbs",
  "dimension_unit": "in",
  "tax_enabled": true,
  "coupons_enabled": true,
  "products_count": 156,
  "orders_count": 1243
}
```

#### Products

##### List Products

```http
GET /woocommerce/products
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | integer | 50 | Items per page (1-100) |
| `page` | integer | 1 | Page number |
| `status` | string | publish | Product status (publish, draft, pending, private, any) |
| `type` | string | - | Product type (simple, variable, grouped, external) |
| `category` | string | - | Category slug |
| `tag` | string | - | Tag slug |
| `search` | string | - | Search term |
| `sku` | string | - | Exact SKU match |
| `stock_status` | string | - | Stock status (instock, outofstock, onbackorder) |
| `orderby` | string | date | Order by (date, title, price, popularity, rating) |
| `order` | string | DESC | Sort order (ASC, DESC) |

**Response:**

```json
{
  "products": [
    {
      "id": 42,
      "name": "Premium Widget",
      "slug": "premium-widget",
      "type": "simple",
      "status": "publish",
      "sku": "WIDGET-001",
      "price": "29.99",
      "regular_price": "39.99",
      "sale_price": "29.99",
      "on_sale": true,
      "stock_status": "instock",
      "stock_quantity": 150,
      "manage_stock": true,
      "categories": ["Electronics", "Widgets"],
      "tags": ["bestseller", "featured"],
      "permalink": "https://example.com/product/premium-widget",
      "date_created": "2024-01-15T10:30:00+00:00",
      "date_modified": "2024-02-01T14:22:00+00:00"
    }
  ],
  "total": 156,
  "page": 1,
  "per_page": 50,
  "total_pages": 4
}
```

##### Get Single Product

```http
GET /woocommerce/products/{id}
```

Returns detailed product information including description, dimensions, images, and attributes.

##### Create Product

```http
POST /woocommerce/products
```

**Body:**

```json
{
  "name": "New Product",
  "type": "simple",
  "status": "publish",
  "description": "Full product description with HTML support",
  "short_description": "Brief product summary",
  "sku": "NEWPROD-001",
  "regular_price": "49.99",
  "sale_price": "39.99",
  "manage_stock": true,
  "stock_quantity": 100,
  "stock_status": "instock",
  "categories": ["Electronics"],
  "tags": ["new", "featured"],
  "image_id": 123,
  "gallery_image_ids": [124, 125, 126],
  "virtual": false,
  "downloadable": false
}
```

##### Update Product

```http
PUT /woocommerce/products/{id}
```

Send only the fields you want to update.

##### Delete Product

```http
DELETE /woocommerce/products/{id}
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `force` | boolean | false | Permanently delete (bypass trash) |

##### Get Product Categories

```http
GET /woocommerce/products/categories
```

**Response:**

```json
[
  {
    "id": 15,
    "name": "Electronics",
    "slug": "electronics",
    "parent": 0,
    "count": 45
  }
]
```

##### Get Product Tags

```http
GET /woocommerce/products/tags
```

#### Orders

##### List Orders

```http
GET /woocommerce/orders
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | integer | 50 | Items per page (1-100) |
| `page` | integer | 1 | Page number |
| `status` | string | any | Order status (pending, processing, completed, etc.) |
| `customer` | integer | - | Customer ID |
| `after` | string | - | Orders after date (ISO 8601) |
| `before` | string | - | Orders before date (ISO 8601) |

**Response:**

```json
{
  "orders": [
    {
      "id": 1001,
      "number": "1001",
      "status": "processing",
      "currency": "USD",
      "total": "129.97",
      "subtotal": "119.97",
      "tax_total": "10.00",
      "shipping_total": "0.00",
      "discount_total": "0.00",
      "payment_method": "Credit Card (Stripe)",
      "customer_id": 42,
      "date_created": "2024-02-01T09:15:00+00:00",
      "date_completed": null,
      "items_count": 3
    }
  ],
  "total": 1243,
  "page": 1,
  "per_page": 50,
  "total_pages": 25
}
```

##### Get Single Order

```http
GET /woocommerce/orders/{id}
```

Returns full order details including billing/shipping addresses, line items, and order notes.

##### Update Order

```http
PUT /woocommerce/orders/{id}
```

**Body:**

```json
{
  "status": "completed",
  "note": "Order shipped via FedEx, tracking: 123456789",
  "note_customer": true
}
```

##### Get Order Statuses

```http
GET /woocommerce/orders/statuses
```

**Response:**

```json
[
  {"slug": "pending", "name": "Pending payment"},
  {"slug": "processing", "name": "Processing"},
  {"slug": "on-hold", "name": "On hold"},
  {"slug": "completed", "name": "Completed"},
  {"slug": "cancelled", "name": "Cancelled"},
  {"slug": "refunded", "name": "Refunded"},
  {"slug": "failed", "name": "Failed"}
]
```

#### Customers

##### List Customers

```http
GET /woocommerce/customers
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | integer | 50 | Items per page (1-100) |
| `page` | integer | 1 | Page number |
| `search` | string | - | Search term |
| `orderby` | string | registered | Order by (registered, display_name, user_login, user_email) |
| `order` | string | DESC | Sort order (ASC, DESC) |

**Response:**

```json
{
  "customers": [
    {
      "id": 42,
      "email": "customer@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "display_name": "John Doe",
      "date_created": "2023-06-15T10:30:00+00:00",
      "orders_count": 12,
      "total_spent": "1,245.67"
    }
  ],
  "total": 523,
  "page": 1,
  "per_page": 50,
  "total_pages": 11
}
```

##### Get Single Customer

```http
GET /woocommerce/customers/{id}
```

Returns full customer details including billing and shipping addresses.

#### Analytics

```http
GET /woocommerce/analytics
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | month | Time period (day, week, month, year) |
| `date_min` | string | - | Start date (ISO 8601) |
| `date_max` | string | - | End date (ISO 8601) |

**Response:**

```json
{
  "period": "month",
  "date_range": {
    "start": "2024-01-01T00:00:00",
    "end": "2024-01-31T23:59:59"
  },
  "sales": {
    "total": "15,678.90",
    "count": 156,
    "average": "100.51"
  },
  "products": {
    "total": 156,
    "in_stock": 142,
    "out_of_stock": 14
  },
  "top_products": [
    {
      "id": 42,
      "name": "Premium Widget",
      "sku": "WIDGET-001",
      "quantity": 89,
      "price": "29.99"
    }
  ],
  "customers": {
    "total": 523,
    "new": 45
  },
  "orders_by_status": {
    "pending": 12,
    "processing": 34,
    "on-hold": 5,
    "completed": 1156,
    "cancelled": 23,
    "refunded": 8,
    "failed": 5
  }
}
```

---

### Multilanguage (Pro)

Full multilingual site support for WPML, Polylang, and TranslatePress.

> **Paid Feature:** Requires MCPWP with a valid Freemius license or trial.

#### Supported Plugins

| Plugin | Detection | Translations |
|--------|-----------|--------------|
| WPML | Full support | Separate posts per language |
| Polylang | Full support | Separate posts per language |
| TranslatePress | Detection only | Inline translations (same post) |

#### Get Languages

```http
GET /languages
```

**Response (WPML example):**

```json
{
  "plugin": "wpml",
  "plugin_version": "4.6.5",
  "default_language": "en",
  "current_language": "en",
  "languages": [
    {
      "code": "en",
      "name": "English",
      "native_name": "English",
      "flag": "https://example.com/flags/en.png",
      "is_default": true,
      "active": true
    },
    {
      "code": "fr",
      "name": "French",
      "native_name": "Français",
      "flag": "https://example.com/flags/fr.png",
      "is_default": false,
      "active": true
    },
    {
      "code": "es",
      "name": "Spanish",
      "native_name": "Español",
      "flag": "https://example.com/flags/es.png",
      "is_default": false,
      "active": true
    }
  ]
}
```

**Response (no plugin):**

```json
{
  "active": false,
  "plugin": null,
  "languages": [],
  "message": "No multilingual plugin detected."
}
```

#### Set Current Language

```http
PUT /languages/current
```

**Body:**

```json
{
  "language": "fr"
}
```

Sets the language context for subsequent API calls in the same session.

#### Get Post Translations

```http
GET /posts/{id}/translations
GET /pages/{id}/translations
```

**Response:**

```json
{
  "post_id": 42,
  "post_type": "post",
  "post_language": "en",
  "plugin": "wpml",
  "translations": {
    "en": {
      "post_id": 42,
      "status": "original",
      "title": "Hello World",
      "post_status": "publish",
      "permalink": "https://example.com/hello-world/",
      "modified": "2024-02-01T10:30:00"
    },
    "fr": {
      "post_id": 156,
      "status": "translation",
      "title": "Bonjour le Monde",
      "post_status": "publish",
      "permalink": "https://example.com/fr/bonjour-le-monde/",
      "modified": "2024-02-01T11:45:00"
    }
  },
  "missing": [
    {"code": "es", "name": "Spanish"}
  ]
}
```

#### Create Post Translation

```http
POST /posts/{id}/translations
POST /pages/{id}/translations
```

**Body:**

```json
{
  "language": "es",
  "title": "Hola Mundo",
  "content": "<p>Este es el contenido traducido...</p>",
  "excerpt": "Resumen del artículo",
  "status": "draft"
}
```

**Response:**

```json
{
  "success": true,
  "original_post_id": 42,
  "translation_id": 189,
  "language": "es",
  "title": "Hola Mundo",
  "status": "draft",
  "permalink": "https://example.com/es/hola-mundo/",
  "edit_link": "https://example.com/wp-admin/post.php?post=189&action=edit"
}
```

#### Filter Content by Language

All list endpoints support the `lang` parameter:

```http
GET /posts?lang=fr
GET /pages?lang=es
```

This filters results to only return content in the specified language.

---

### Webhooks

Webhooks allow your external systems to receive real-time notifications when events occur on your WordPress site.

#### List Available Events

```http
GET /webhooks/events
```

**Response:**

```json
{
  "events": [
    "post.created", "post.updated", "post.deleted", "post.published",
    "page.created", "page.updated", "page.deleted", "page.published",
    "media.uploaded", "media.deleted",
    "user.created", "user.updated", "user.deleted",
    "comment.created", "comment.approved", "comment.deleted"
  ],
  "grouped": {
    "post": ["post.created", "post.updated", "post.deleted", "post.published"],
    "page": ["page.created", "page.updated", "page.deleted", "page.published"],
    "media": ["media.uploaded", "media.deleted"],
    "user": ["user.created", "user.updated", "user.deleted"],
    "comment": ["comment.created", "comment.approved", "comment.deleted"]
  },
  "total": 16
}
```

#### List Webhooks

```http
GET /webhooks
```

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `status` | string | all | Filter: `active`, `disabled`, `all` |
| `per_page` | integer | 50 | Items per page |
| `page` | integer | 1 | Page number |

#### Create Webhook

```http
POST /webhooks
```

**Body:**

```json
{
  "name": "My Webhook",
  "url": "https://example.com/webhook-receiver",
  "events": ["post.published", "page.published"],
  "secret": "optional-custom-secret"
}
```

**Response:**

```json
{
  "id": 1,
  "webhook": {
    "id": 1,
    "name": "My Webhook",
    "url": "https://example.com/webhook-receiver",
    "events": ["post.published", "page.published"],
    "status": "active",
    "secret": "abc123..."
  },
  "message": "Webhook created successfully."
}
```

#### Get/Update/Delete Webhook

```http
GET /webhooks/{id}
PUT /webhooks/{id}
DELETE /webhooks/{id}
```

#### Test Webhook

```http
POST /webhooks/{id}/test
```

Sends a test payload to verify the webhook URL is reachable.

**Response:**

```json
{
  "success": true,
  "response_code": 200,
  "response_body": "OK",
  "duration": 0.245
}
```

#### View Delivery Logs

```http
GET /webhooks/{id}/logs
```

**Response:**

```json
{
  "logs": [
    {
      "id": 1,
      "webhook_id": 1,
      "event": "post.published",
      "response_code": 200,
      "duration": 0.312,
      "created_at": "2024-01-15 10:30:00"
    }
  ],
  "total": 15,
  "pages": 1,
  "page": 1
}
```

#### Webhook Payload Format

When an event triggers, MCPWP sends a POST request with:

**Headers:**

```
Content-Type: application/json
X-MCPWP-Event: post.published
X-MCPWP-Signature: sha256-hmac-of-body
X-MCPWP-Webhook-ID: 1
X-MCPWP-Delivery-ID: uuid
```

**Body:**

```json
{
  "event": "post.published",
  "timestamp": "2024-01-15T10:30:00+00:00",
  "site_url": "https://example.com",
  "id": 123,
  "title": "New Blog Post",
  "type": "post",
  "permalink": "https://example.com/new-blog-post/"
}
```

#### Verifying Webhook Signatures

Verify the `X-MCPWP-Signature` header using HMAC-SHA256:

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_MCPWP_SIGNATURE'];
$expected = hash_hmac('sha256', $payload, $your_webhook_secret);

if (hash_equals($expected, $signature)) {
    // Valid webhook
}
```

---

### AI Integrations

*New in v1.1.0.* Third-party AI services and design sources (OpenAI, Gemini, ElevenLabs, Pexels, Figma) integrate directly into MCPWP. Configure API keys via **WP Admin → MCPWP → Integrations**. Generated assets are auto-uploaded to the WordPress media library, while Figma is used as approved design context for archetypes and reusable parts.

**Tier gating:** Stock photo search is available as a core integration. AI generation tools require a paid plan or trial.

#### Provider Management (Admin)

##### List Providers

```http
GET /integrations/providers
```

Returns all supported providers and their configuration status.

**Response:**

```json
{
  "openai": {
    "name": "OpenAI",
    "configured": true,
    "tier": "pro",
    "last_tested": "2026-02-19T10:30:00",
    "test_status": "ok"
  },
  "gemini": {
    "name": "Google Gemini",
    "configured": false,
    "tier": "pro",
    "last_tested": null,
    "test_status": null
  },
  "elevenlabs": {
    "name": "ElevenLabs",
    "configured": false,
    "tier": "pro",
    "last_tested": null,
    "test_status": null
  },
  "pexels": {
    "name": "Pexels",
    "configured": true,
    "tier": "free",
    "last_tested": "2026-02-19T10:25:00",
    "test_status": "ok"
  },
  "figma": {
    "name": "Figma",
    "configured": true,
    "tier": "pro",
    "last_tested": "2026-04-01T00:00:00",
    "test_status": "ok"
  }
}
```

##### Store Provider Key

```http
POST /integrations/providers/{provider}/key
```

**Body:**

```json
{
  "key": "sk-your-api-key-here"
}
```

Keys are encrypted at rest using Sodium (libsodium).

##### Remove Provider Key

```http
DELETE /integrations/providers/{provider}/key
```

##### Test Provider Connection

```http
POST /integrations/providers/{provider}/test
```

**Response:**

```json
{
  "success": true,
  "provider": "openai",
  "message": "Connection successful."
}
```

#### Stock Photos (Core)

##### Search Stock Photos

```http
GET /integrations/stock-photos
```

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `query` | string | *(required)* | Search query (e.g., "sunset beach") |
| `per_page` | integer | 10 | Results per page (1-80) |
| `page` | integer | 1 | Page number |

**Response:**

```json
{
  "total_results": 5000,
  "page": 1,
  "per_page": 10,
  "photos": [
    {
      "id": 1234567,
      "width": 4000,
      "height": 2667,
      "url": "https://www.pexels.com/photo/...",
      "photographer": "John Doe",
      "photographer_url": "https://www.pexels.com/@johndoe",
      "src": {
        "original": "https://images.pexels.com/photos/1234567/...",
        "large2x": "...",
        "large": "...",
        "medium": "...",
        "small": "..."
      },
      "alt": "Sunset over the ocean"
    }
  ]
}
```

##### Download Stock Photo

```http
POST /integrations/stock-photos/download
```

Downloads a Pexels photo to the WordPress media library.

**Body:**

```json
{
  "photo_id": 1234567,
  "size": "large",
  "alt": "Sunset beach photo",
  "title": "Beach Sunset"
}
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `photo_id` | integer | *(required)* | Pexels photo ID from search results |
| `size` | string | `large` | Image size: `original`, `large2x`, `large`, `medium`, `small` |
| `alt` | string | - | Alt text for the image |
| `title` | string | - | Title for the media library item |

**Response:**

```json
{
  "id": 456,
  "url": "https://example.com/wp-content/uploads/2026/02/pexels-1234567.jpg",
  "title": "Beach Sunset",
  "alt": "Sunset beach photo",
  "photographer": "John Doe",
  "pexels_url": "https://www.pexels.com/photo/1234567/"
}
```

#### AI Image Generation (Pro)

##### Generate Image

```http
POST /integrations/generate-image
```

Generate an AI image using GPT-Image-1-Mini (OpenAI) or Imagen 3 (Gemini) and upload to media library.

**Body:**

```json
{
  "prompt": "A modern minimalist office workspace with plants",
  "provider": "openai",
  "size": "1024x1024",
  "quality": "medium",
  "alt": "Modern office workspace",
  "title": "Office Workspace"
}
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `prompt` | string | *(required)* | Image generation prompt |
| `provider` | string | auto | `openai` or `gemini` (auto-selects first configured) |
| `size` | string | `1024x1024` | `1024x1024`, `1536x1024` (landscape), `1024x1536` (portrait) |
| `quality` | string | `medium` | OpenAI only: `low`, `medium`, or `high` |
| `alt` | string | - | Alt text for the uploaded image |
| `title` | string | - | Title for the media library item |

**Response:**

```json
{
  "id": 789,
  "url": "https://example.com/wp-content/uploads/2026/02/ai-generated-abc123.png",
  "title": "Office Workspace",
  "alt": "Modern office workspace",
  "provider": "openai"
}
```

##### Generate Featured Image

```http
POST /integrations/generate-featured-image
```

Generate an AI image and set it as the featured image for a post/page.

**Body:**

```json
{
  "post_id": 123,
  "prompt": "Abstract technology background with blue gradient",
  "provider": "openai",
  "size": "1536x1024",
  "quality": "medium"
}
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `post_id` | integer | *(required)* | Post or page ID |
| `prompt` | string | *(required)* | Image generation prompt |
| `provider` | string | auto | `openai` or `gemini` |
| `size` | string | `1536x1024` | Image size (landscape default for featured images) |
| `quality` | string | `medium` | OpenAI only: `low`, `medium`, or `high` |

**Response:**

```json
{
  "id": 790,
  "url": "https://example.com/wp-content/uploads/2026/02/ai-generated-def456.png",
  "title": "Featured Image - My Blog Post",
  "set_as_featured": true,
  "post_id": 123,
  "provider": "openai"
}
```

#### AI Vision (Pro)

##### Generate Alt Text

```http
POST /integrations/generate-alt-text
```

Use AI vision to generate alt text for an existing media library image.

**Body:**

```json
{
  "attachment_id": 456,
  "provider": "openai",
  "auto_save": true
}
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `attachment_id` | integer | *(required)* | WordPress attachment ID |
| `provider` | string | auto | `openai` or `gemini` |
| `auto_save` | boolean | `false` | Save generated alt text to the attachment |

**Response:**

```json
{
  "attachment_id": 456,
  "alt_text": "A golden retriever playing fetch on a sandy beach at sunset",
  "saved": true,
  "provider": "openai"
}
```

##### Describe Image

```http
POST /integrations/describe-image
```

Get a detailed AI-powered description of an image.

**Body:**

```json
{
  "attachment_id": 456,
  "provider": "openai",
  "instruction": "Describe this image focusing on colors and composition."
}
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `attachment_id` | integer | *(required)* | WordPress attachment ID |
| `provider` | string | auto | `openai` or `gemini` |
| `instruction` | string | `Describe this image in detail.` | Custom instruction for the vision model |

**Response:**

```json
{
  "attachment_id": 456,
  "description": "The image shows a golden retriever mid-leap catching a red frisbee...",
  "provider": "openai"
}
```

#### AI Content (Pro)

##### Generate Excerpt

```http
POST /integrations/generate-excerpt
```

Use AI to generate a compelling excerpt/summary for a post.

**Body:**

```json
{
  "post_id": 123,
  "provider": "openai",
  "max_length": 160,
  "auto_save": true
}
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `post_id` | integer | *(required)* | Post or page ID |
| `provider` | string | auto | `openai` or `gemini` |
| `max_length` | integer | `160` | Maximum excerpt length in characters (50-500) |
| `auto_save` | boolean | `false` | Save generated excerpt to the post |

**Response:**

```json
{
  "post_id": 123,
  "excerpt": "Discover the top AI trends reshaping industries in 2026, from autonomous agents to multimodal models.",
  "saved": true,
  "provider": "openai"
}
```

#### Text-to-Speech (Pro)

##### Convert Text to Speech

```http
POST /integrations/text-to-speech
```

Convert text to speech using ElevenLabs and upload the MP3 to media library.

**Body:**

```json
{
  "text": "Welcome to our website. We're glad you're here.",
  "voice_id": "21m00Tcm4TlvDq8ikWAM",
  "title": "Welcome Audio"
}
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `text` | string | *(required)* | Text to convert to speech |
| `voice_id` | string | Rachel | ElevenLabs voice ID (default: Rachel) |
| `title` | string | - | Title for the audio file in media library |

**Response:**

```json
{
  "id": 801,
  "url": "https://example.com/wp-content/uploads/2026/02/tts-welcome-audio.mp3",
  "title": "Welcome Audio",
  "mime_type": "audio/mpeg",
  "provider": "elevenlabs"
}
```

#### Provider Auto-Selection

When `provider` is omitted, the plugin auto-selects the first configured provider for the capability:

| Capability | Priority | Models Used |
|-----------|----------|-------------|
| Image generation | OpenAI > Gemini | GPT-Image-1-Mini / Imagen 3 |
| Vision (alt text, describe) | OpenAI > Gemini | GPT-4o / Gemini 2.5 Flash |
| Text (excerpts) | OpenAI > Gemini | GPT-4o / Gemini 2.5 Flash |
| Text-to-speech | ElevenLabs only | ElevenLabs v1 |
| Stock photos | Pexels only | Pexels API |

---

### Staging (Pro)

Push and pull between staging and live environments. Requires Pro. Configure provider API keys under **WP Admin → MCPWP → Integrations**.

**Supported hosts:**

| Host | Method | Integration key |
|------|--------|-----------------|
| WP Engine | REST API (`/v1/installs/{id}/copy`) | `wpengine_staging` (fields: `install_id`, `api_token`) |
| Kinsta | REST API (`/v2/sites/{id}/environments` PUT) | `kinsta_staging` (fields: `site_id`, `api_key`) |
| Pressable | REST API (`/v1/sites/{id}/clone`) | `pressable_staging` (fields: `site_id`, `api_token`) |
| Any host | WP-CLI over SSH (fallback) | No config needed — requires `shell_exec` + WP-CLI |

**REST Endpoints:**

| Endpoint | Method | Scope | Purpose |
|----------|--------|-------|---------|
| `/staging` | GET | admin | Detect host, list environments |
| `/staging/push` | POST | admin | Push between envs — destination=live creates approval request |
| `/staging/status/{id}` | GET | admin | Check async operation status |

**MCP Tools:**

| Tool | Destructive | Purpose |
|------|-------------|---------|
| `wp_get_staging_info` | No | Detect host, list environments with IDs and types |
| `wp_push_to_staging` | No | Copy live → staging immediately |
| `wp_push_to_live` | Yes | Create approval request for staging → live (never pushes directly) |
| `wp_get_staging_status` | No | Poll async push status by operation ID |

**Security model:**
- All staging endpoints require `admin` scope — write-scoped keys are rejected
- `wp_push_to_live` always creates an approval request; the push only executes after `wp_apply_approval`
- The API key that created the push request cannot approve its own request (403 self-approval block)
- Env IDs are validated against the host's live environment list before and at apply time

**Example — push to staging:**

```bash
curl -s -X POST https://your-site.com/wp-json/mcpwp/v1/staging/push \
  -H "X-API-Key: mcpwp_your_admin_key" \
  -H "Content-Type: application/json" \
  -d '{"source":"production","destination":"staging","include_db":true,"include_files":true}'
```

**Example — push to live (returns approval request):**

```bash
curl -s -X POST https://your-site.com/wp-json/mcpwp/v1/staging/push \
  -H "X-API-Key: mcpwp_your_admin_key" \
  -H "Content-Type: application/json" \
  -d '{"source":"staging","destination":"production","include_db":true,"include_files":true,"reason":"Homepage redesign ready"}'
# Returns: {"status":"pending_approval","approval_id":"apr_stg_..."}
# Then approve via a DIFFERENT admin key:
# curl -X POST .../approvals/apr_stg_.../approve -H "X-API-Key: mcpwp_other_admin_key"
# curl -X POST .../approvals/apr_stg_.../apply  -H "X-API-Key: mcpwp_other_admin_key"
```

---

## Auto-Updates

MCPWP can use a self-hosted updater for non-Freemius builds.

### Canonical Sources

- Version manifest: `https://mumega.com/mcp-updates/version.json`
- ZIP download: `https://mumega.com/mcp-updates/mcpwp-latest.zip`

### How It Works

1. The plugin checks the `mcpwp_update_info` option first
2. If that option is empty, it falls back to `mcpwp_version_url`
3. If `mcpwp_version_url` is empty, it falls back to the built-in static `mumega.com` manifest URL
4. If a newer version is found, WordPress displays an update notice and installs from `download_url`

### Important Behavior

`mcpwp_update_info` is a site-level override. If it contains stale release data, it can block newer updates from the worker manifest.

Recommended practice:

- leave `mcpwp_update_info` empty unless you explicitly need an override
- if you do write `mcpwp_update_info`, keep it identical to the static manifest

### Manual Update Check

To force an update check:

1. Go to **Dashboard → Updates**
2. Click **Check Again**

Or use the plugin REST route, which clears the update caches before checking:

```bash
curl -fsSL "https://SITE/wp-json/mcpwp/v1/update" -H "X-API-Key: ..."
```

You can also inspect the update-related options:

```bash
curl -fsSL "https://SITE/wp-json/mcpwp/v1/option?key=mcpwp_version_url" -H "X-API-Key: ..."
curl -fsSL "https://SITE/wp-json/mcpwp/v1/option?key=mcpwp_update_info" -H "X-API-Key: ..."
```

### Version Numbering

We follow semantic versioning: `MAJOR.MINOR.PATCH`

- **MAJOR:** Breaking API changes
- **MINOR:** New features (backwards compatible)
- **PATCH:** Bug fixes

### Release Assets

The current release artifacts are:

| Asset | Description |
|-------|-------------|
| `version.json` | Worker-served update manifest |
| `mcpwp-latest.zip` | Canonical install/update ZIP |

## Elementor 4 Compatibility

The current Elementor save path is hardened for Elementor 4 behavior:

- MCPWP no longer assumes `Document::save()` means the data was persisted
- after calling `Document::save()`, it verifies the stored `_elementor_data`
- if Elementor reports success but stores zero sections, MCPWP automatically falls back to direct meta write plus CSS regeneration

Local validation completed on:

- WordPress `6.9.1`
- Elementor `4.0.0`

Landing page generation was also verified on the local Elementor 4 test stack.

---

## MCP Server Configuration

MCPWP includes a built-in MCP (Model Context Protocol) server. The MCP endpoint is at `/wp-json/mcpwp/v1/mcp` — no external server needed.

### Server Info

On `initialize`, the server returns:

```json
{
  "serverInfo": {
    "name": "mcpwp:Your Site Name",
    "version": "1.1.8"
  }
}
```

The server name includes the WordPress site title so you can distinguish multiple sites.

### Claude Desktop — Custom Connector (Recommended)

In Claude Desktop, go to **Settings → Connectors → Add custom connector**:

- **Name:** `mcpwp-mysite`
- **Remote MCP server URL:** `https://your-site.com/wp-json/mcpwp/v1/mcp?api_key=mcpwp_your_api_key`
- Leave OAuth fields empty

This connects Claude Desktop directly to your WordPress site — no npm package or proxy needed.

### Claude Desktop — npm Package

Alternatively, use the `mcpwp` npm package (stdio proxy):

```json
{
  "mcpServers": {
    "mcpwp-mysite": {
      "command": "npx",
      "args": ["-y", "mcpwp"],
      "env": {
        "WP_URL": "https://your-site.com",
        "WP_API_KEY": "mcpwp_your_api_key",
        "WP_SITE_NAME": "mysite"
      }
    }
  }
}
```

The server registers as `mcpwp-<sitename>` for unique identification with multiple sites.

### Claude Code

Claude Code connects directly via the Streamable HTTP transport — no proxy or npm package needed. Add the MCP endpoint URL with an `X-API-Key` header or `?api_key=` query parameter.

### Available MCP Tools

#### Core Tools

| Tool | Description |
|------|-------------|
| **Site & Analytics** | |
| `wp_site_info` | Get site info, version, theme, plugins, content counts |
| `wp_introspect` | Machine-readable plugin description for AI self-configuration |
| `wp_analytics` | Site analytics (post/page/comment/user counts) |
| `wp_detect_plugins` | Detect active plugins and capabilities |
| `wp_get_options` | Get WordPress reading options |
| `wp_update_options` | Update reading options (front page, posts page, visibility) |
| `wp_get_site_context` | Get the site context — a master prompt / style guide that defines design rules, header/footer structure, color palette, typography, predefined sections, and page layout guidelines. Always read this first when building or editing pages |
| `wp_set_site_context` | Set the site context (AI brief). A markdown document that tells AI assistants how to build pages for this site. Included automatically in `wp_introspect` |
| `wp_get_custom_css` | Get Additional CSS from Customizer |
| `wp_set_custom_css` | Set/append CSS (mode: replace or append) |
| **Content** | |
| `wp_list_posts` | List posts with filters |
| `wp_create_post` | Create a post |
| `wp_update_post` | Update a post |
| `wp_delete_post` | Delete a post |
| `wp_list_pages` | List pages |
| `wp_create_page` | Create a page |
| `wp_update_page` | Update a page |
| `wp_delete_page` | Delete a page |
| `wp_clone_page` | Duplicate a page (content + Elementor + template) |
| `wp_get_page_by_slug` | Fetch page by URL slug |
| `wp_search` | Search posts/pages |
| `wp_fetch` | Fetch single post/page by ID or URL (flags Elementor pages) |
| `wp_list_content` | List any post type (products, courses, etc.) |
| `wp_delete_content` | Delete any post type by ID |
| `wp_set_featured_image` | Set/remove featured image |
| `wp_list_categories` | List post categories |
| `wp_list_tags` | List post tags |
| `wp_list_drafts` | List all drafts |
| `wp_delete_all_drafts` | Bulk delete drafts |
| `wp_get_post_meta` | Get all public meta fields for a post/page |
| `wp_set_post_meta` | Set meta fields on a post/page (blocked-key safety) |
| `wp_get_option` | Get a single WordPress option. Supports core WP options and prefix-based matching: `elementor_*`, `wpseo_*`, `rank_math_*`, `astra_*`, `theme_mods_*`, `woocommerce_*`, `mcpwp_*`. Sensitive keys (passwords, tokens, secrets) are always blocked |
| `wp_update_option` | Update a single WordPress option (same allowlist as `wp_get_option`) |
| `wp_batch_update` | Execute up to 25 REST operations in one call (v1.0.69: fixed error handling for mixed success/failure batches) |
| **Menus** | |
| `wp_list_menus` | List all navigation menus |
| `wp_list_menu_locations` | List theme locations and assigned menus |
| `wp_setup_menu` | Create menu + add pages + assign location |
| `wp_list_menu_items` | List items in a menu |
| `wp_add_menu_item` | Add item (custom/post_type/taxonomy, classes/target/description) |
| `wp_update_menu_item` | Update item (title, url, parent, position, classes, target, description) |
| `wp_delete_menu_item` | Remove a menu item |
| `wp_reorder_menu_items` | Bulk reorder and reparent items |
| `wp_delete_menu` | Delete an entire menu |
| `wp_assign_menu_location` | Assign menu to theme location |
| **Elementor** | |
| `wp_get_elementor` | Get full Elementor page data (JSON tree) |
| `wp_get_elementor_summary` | Get lightweight structural summary (<1K tokens vs 64K+ for full data) |
| `wp_edit_section` | Surgically edit a single element by ID, index, or search criteria — no full JSON round-trip needed |
| `wp_set_elementor` | Set Elementor page data (with validation, auto-fix, CSS regen) |
| `wp_elementor_status` | Check Elementor status |
| `wp_regenerate_elementor_css` | Regenerate CSS after API edits |
| `wp_bulk_find_replace` | Search/replace in Elementor JSON |
| **Gutenberg Blocks** | |
| `wp_get_blocks` | Get parsed Gutenberg blocks for a post or page |
| `wp_set_blocks` | Set Gutenberg blocks (blocks array or raw content string) |
| `wp_list_block_types` | List all registered block types |
| `wp_list_block_patterns` | List all registered block patterns |
| **Media** | |
| `wp_list_media` | List media library items |
| `wp_upload_media` | Upload media (base64 or URL) |
| `wp_upload_media_from_url` | Upload from URL |
| `wp_upload_media_b64` | Upload from base64 (bypasses ModSecurity) |
| `wp_delete_media` | Delete media attachment (trash or permanent) |
| **Templates** | |
| `wp_update_page_template` | Change page template |
| `wp_list_page_templates` | List available templates |
| **Other** | |
| `wp_screenshot_url` | Screenshot a URL (Cloudflare or mshots) |
| `wp_list_api_keys` | List scoped API keys |
| `wp_create_api_key` | Create scoped API key |
| `wp_revoke_api_key` | Revoke an API key |
| `wp_rate_limit_status` | Get rate limit settings |
| `wp_update_rate_limit` | Update rate limit settings |
| `wp_reset_rate_limit` | Reset rate limit counters |
| `wp_list_webhooks` | List webhooks |
| `wp_create_webhook` | Create webhook subscription |
| `wp_update_webhook` | Update webhook |
| `wp_delete_webhook` | Delete webhook |
| `wp_test_webhook` | Test webhook delivery |
| `wp_submit_feedback` | Submit bug report or feature request |
| `wp_list_feedback` | List feedback entries |
| **AI Integrations (Core)** | |
| `wp_search_stock_photos` | Search Pexels for free stock photos (returns IDs, URLs, dimensions, photographer) |
| `wp_download_stock_photo` | Download a Pexels photo to the WordPress media library |

#### Pro Tier (additional tools)

| Tool | Description |
|------|-------------|
| **Menu Management** | |
| `wp_get_menu` | Get single menu with all items and metadata |
| `wp_create_menu` | Create menu with items and optional location |
| `wp_update_menu` | Rename menu or change location |
| **SEO** *(requires SEO plugin)* | |
| `wp_get_seo` | Get SEO metadata (Yoast, RankMath, AIOSEO, SEOPress) |
| `wp_set_seo` | Set SEO title, description, keywords, OG data |
| `wp_analyze_seo` | Analyze SEO quality |
| `wp_bulk_seo` | Bulk update SEO for multiple posts |
| `wp_seo_status` | Get SEO plugin status |
| **Forms** *(requires forms plugin)* | |
| `wp_list_forms` | List forms (CF7, WPForms, Gravity Forms) |
| `wp_get_form` | Get form details |
| `wp_get_form_entries` | Get form submissions |
| **Elementor Pro** | |
| `wp_list_elementor_templates` | List templates |
| `wp_get_elementor_template` | Get template data |
| `wp_create_elementor_template` | Create template |
| `wp_update_elementor_template` | Update template |
| `wp_delete_elementor_template` | Delete template |
| `wp_apply_elementor_template` | Apply template to page |
| `wp_create_landing_page` | Create landing page from template |
| `wp_clone_elementor_page` | Clone Elementor page |
| `wp_get_elementor_globals` | Get global colors/fonts |
| `wp_set_elementor_globals` | Set global colors, fonts, and typography |
| `wp_get_elementor_widgets` | List available widgets |
| **Theme Builder** | |
| `wp_theme_builder_status` | Theme Builder availability |
| `wp_list_theme_templates` | List header/footer/single/archive templates |
| `wp_get_theme_template` | Get template with conditions |
| `wp_set_template_conditions` | Set display conditions |
| `wp_assign_template` | Assign template to scope |
| **Widgets & Sidebars** | |
| `wp_list_sidebars` | List widget areas |
| `wp_get_sidebar` | Get sidebar with widgets |
| `wp_add_widget` | Add widget to sidebar |
| `wp_update_widget` | Update widget settings |
| `wp_delete_widget` | Delete widget |
| `wp_move_widget` | Move widget between sidebars |
| **Multilingual** | |
| `wp_languages` | Get languages and plugin status |
| `wp_get_translations` | Get translations for a post |
| `wp_create_translation` | Create translation |
| **AI Integrations (Pro)** | |
| `wp_generate_image` | Generate AI image (DALL-E 3 / Imagen 3) → media library |
| `wp_generate_featured_image` | Generate AI image and set as featured image for a post/page |
| `wp_generate_alt_text` | AI vision → alt text for an existing image (optional auto-save) |
| `wp_describe_image` | AI vision → detailed image description |
| `wp_generate_excerpt` | AI → compelling post excerpt/summary (optional auto-save) |
| `wp_text_to_speech` | ElevenLabs TTS → MP3 in media library |

---

## AI Integration Examples

### Claude

```
Human: Create a new blog post about AI trends in 2024

Claude: I'll create that blog post for you using the MCPWP API.

[Uses wp_create_post tool with title "AI Trends Shaping 2024" and content...]

Done! I've created the post. You can view it at https://example.com/ai-trends-2024/
```

### Python Example

```python
import requests

class MCPWP:
    def __init__(self, url, api_key):
        self.base_url = f"{url}/wp-json/mcpwp/v1"
        self.headers = {"X-API-Key": api_key}

    def create_post(self, title, content, status="draft"):
        response = requests.post(
            f"{self.base_url}/posts",
            headers=self.headers,
            json={"title": title, "content": content, "status": status}
        )
        return response.json()

    def update_seo(self, post_id, title, description):
        response = requests.put(
            f"{self.base_url}/seo/{post_id}",
            headers=self.headers,
            json={"title": title, "description": description}
        )
        return response.json()

# Usage
wp = MCPWP("https://example.com", "mcpwp_your_key")
post = wp.create_post("My Post", "<p>Content here</p>", "publish")
wp.update_seo(post["id"], "SEO Title", "Meta description")
```

### JavaScript/Node.js Example

```javascript
const axios = require('axios');

class MCPWP {
  constructor(url, apiKey) {
    this.client = axios.create({
      baseURL: `${url}/wp-json/mcpwp/v1`,
      headers: { 'X-API-Key': apiKey }
    });
  }

  async createPost(title, content, status = 'draft') {
    const { data } = await this.client.post('/posts', {
      title, content, status
    });
    return data;
  }

  async uploadFromUrl(imageUrl, title) {
    const { data } = await this.client.post('/media/from-url', {
      url: imageUrl,
      title
    });
    return data;
  }
}

// Usage
const wp = new MCPWP('https://example.com', 'mcpwp_your_key');

(async () => {
  const post = await wp.createPost('Hello World', '<p>Content</p>', 'publish');
  console.log(`Created post: ${post.link}`);
})();
```

### cURL Examples

```bash
# Get site info
curl -H "X-API-Key: mcpwp_xxx" https://example.com/wp-json/mcpwp/v1/site-info

# Create a post
curl -X POST -H "X-API-Key: mcpwp_xxx" -H "Content-Type: application/json" \
  -d '{"title":"New Post","content":"<p>Hello</p>","status":"publish"}' \
  https://example.com/wp-json/mcpwp/v1/posts

# Upload image from URL
curl -X POST -H "X-API-Key: mcpwp_xxx" -H "Content-Type: application/json" \
  -d '{"url":"https://example.com/image.jpg","title":"My Image"}' \
  https://example.com/wp-json/mcpwp/v1/media/from-url

# Update SEO
curl -X PUT -H "X-API-Key: mcpwp_xxx" -H "Content-Type: application/json" \
  -d '{"title":"SEO Title","description":"Meta description"}' \
  https://example.com/wp-json/mcpwp/v1/seo/123
```

---

## Support

- **Documentation:** https://mcpwp.net/docs
- **GitHub Issues:** https://github.com/Mumega-com/mcp-for-wp/issues
- **Email:** support@mumega.com

---

*MCPWP is developed by [Mumega](https://mumega.com)*
