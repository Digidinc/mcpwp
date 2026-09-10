# Batch Endpoint

The batch endpoint allows executing multiple REST API operations in a single HTTP request, significantly reducing round-trip time for AI agents performing bulk operations.

Over MCP, the same controller is the `wp_batch` tool (and its older name `wp_batch_update`): `wp_batch(operations=[...])` takes the same `operations` array and returns this endpoint's response as its result text. Everything below applies to it unchanged. See [API.md → wp_batch](API.md#wp_batch).

## Endpoint

```
POST /wp-json/mcpwp/v1/batch
```

## Authentication

Uses the same `X-API-Key` header as other endpoints. The API key is forwarded to each operation in the batch.

## Request Format

```json
{
  "operations": [
    {
      "method": "GET|POST|PUT|DELETE",
      "path": "/relative-path",
      "body": { /* optional, for POST/PUT */ }
    }
  ]
}
```

### Parameters

- **operations** (required): Array of operation objects
  - **method** (required): HTTP method (GET, POST, PUT, DELETE)
  - **path** (required): Relative path (e.g., `/posts`, `/pages/123`)
  - **body** (optional): Request body for POST/PUT operations

### Limits

- Maximum **100 operations** per batch; each operation costs one rate-limit slot, and a batch that does not fit the remaining budget is refused whole (429) before anything runs
- The **reachable** ceiling is the tightest rate-limit window, `min(burst, per-minute, per-hour)` — **30 at the default settings** (burst 30). A batch above that is refused with `batch_exceeds_limit` (413): waiting will not help; split it or raise `burst_limit`
- Operations execute sequentially (not in parallel)
- Each operation inherits authentication from the batch request
- If one operation fails, remaining operations still execute
- An operation may **not** target `/batch` or `/mcp`. Those endpoints dispatch internally, and allowing one inside the other would let a single counted request fan out into an unbounded tree of uncounted ones. Such an operation returns `forbidden_path` with status `400`; the rest of the batch still runs.
- Operations draw on the request's **spend budget** — 1000 units by default, shared with everything else the same HTTP request dispatches (when the batch runs as `wp_batch` inside a JSON-RPC batch, every tool call in that batch shares it). An ordinary operation costs 1, a bulk route the items it processes, `wp_bulk_find_replace` / `wp_delete_all_drafts` routes 10, and any operation that spends a site credential or fetches a caller-chosen URL 25 each — AI generation, text-to-speech, image description, alt text, excerpts, `/media/from-url` (25 per URL in `/media/bulk`), stock photos, screenshots, Cloudflare, Figma, Google indexing, staging, SEO data providers, webhook tests, feedback, favicon and update downloads, design-reference image imports. See [API.md → Spend budget](API.md#spend-budget-per-request).

## Response Format

```json
{
  "results": [
    {
      "index": 0,
      "status": 200,
      "data": { /* operation response */ }
    },
    {
      "index": 1,
      "status": 201,
      "data": { /* operation response */ }
    }
  ],
  "total": 2,
  "succeeded": 2,
  "failed": 0,
  "spend_budget": {
    "limit": 1000,
    "spent": 2,
    "remaining": 998,
    "exhausted": false,
    "refused": []
  }
}
```

Each result contains:
- **index**: Operation position in the batch (0-based)
- **status**: HTTP status code
- **data**: Response data from the operation

The batch adds:
- **total**, **succeeded**, **failed**: counts; an operation with status 400 or above, including one refused by the spend budget, is failed
- **spend_budget**: the request's spend budget after the last operation — `limit`, `spent`, `remaining`, `exhausted`, and `refused` (indexes of operations the budget refused)

## Examples

### Example 1: List posts and pages

```bash
curl -X POST "https://example.com/wp-json/mcpwp/v1/batch" \
  -H "X-API-Key: your-key" \
  -H "Content-Type: application/json" \
  -d '{
    "operations": [
      {
        "method": "GET",
        "path": "/posts",
        "body": { "per_page": 5 }
      },
      {
        "method": "GET",
        "path": "/pages",
        "body": { "per_page": 5 }
      }
    ]
  }'
```

### Example 2: Create multiple posts

```bash
curl -X POST "https://example.com/wp-json/mcpwp/v1/batch" \
  -H "X-API-Key: your-key" \
  -H "Content-Type: application/json" \
  -d '{
    "operations": [
      {
        "method": "POST",
        "path": "/posts",
        "body": {
          "title": "First Post",
          "content": "<p>Content here</p>",
          "status": "draft"
        }
      },
      {
        "method": "POST",
        "path": "/posts",
        "body": {
          "title": "Second Post",
          "content": "<p>More content</p>",
          "status": "draft"
        }
      }
    ]
  }'
```

### Example 3: Update multiple posts

```bash
curl -X POST "https://example.com/wp-json/mcpwp/v1/batch" \
  -H "X-API-Key: your-key" \
  -H "Content-Type: application/json" \
  -d '{
    "operations": [
      {
        "method": "POST",
        "path": "/posts/123",
        "body": { "status": "publish" }
      },
      {
        "method": "POST",
        "path": "/posts/124",
        "body": { "status": "publish" }
      },
      {
        "method": "POST",
        "path": "/posts/125",
        "body": { "status": "publish" }
      }
    ]
  }'
```

### Example 4: Mixed operations

```bash
curl -X POST "https://example.com/wp-json/mcpwp/v1/batch" \
  -H "X-API-Key: your-key" \
  -H "Content-Type: application/json" \
  -d '{
    "operations": [
      {
        "method": "GET",
        "path": "/site-info"
      },
      {
        "method": "POST",
        "path": "/posts",
        "body": {
          "title": "New Post",
          "status": "draft"
        }
      },
      {
        "method": "DELETE",
        "path": "/posts/999",
        "body": { "force": true }
      }
    ]
  }'
```

## Error Handling

### Batch-level errors

If the batch request itself is invalid:

```json
{
  "code": "batch_too_large",
  "message": "Batch contains too many operations. Maximum 100 allowed.",
  "data": { "status": 400 }
}
```

### Operation-level errors

Individual operation failures are included in the results:

```json
{
  "results": [
    {
      "index": 0,
      "status": 200,
      "data": { "success": true }
    },
    {
      "index": 1,
      "status": 404,
      "data": {
        "code": "rest_post_invalid_id",
        "message": "Invalid post ID."
      }
    }
  ],
  "total": 2,
  "succeeded": 1,
  "failed": 1
}
```

### Refused whole by the spend budget

A batch whose operations, priced as sent, cost more than is left of the request's spend budget is refused **whole, before any operation runs**:

```json
{
  "code": "spend_budget_exceeded",
  "message": "Spend budget exceeded: this call costs 1250 units and 1000 of this request's 1000 remain. Nothing ran.",
  "data": { "status": 413, "weight": 1250, "remaining": 1000, "limit": 1000, "hint": "…" }
}
```

Nothing ran, so nothing needs undoing: split the operations into batches that each fit. The request's rate-limit weight (one slot per operation) was already charged when it was authenticated, so an over-budget batch still costs its slots.

### Refused one by one by the spend budget

An operation's real price can show only at dispatch (from the route pattern WordPress matched, or its sanitised parameters). An operation that then does not fit is refused **before it runs**, with status `413` and code `spend_budget_exhausted`. From that operation on, every later operation in the request is refused the same way without being dispatched — even a cheap one, even one that would have failed for another reason — so `spend_budget.refused` is exactly the tail of the batch to resend. Operations that already ran are not undone. Waiting does not help: send the refused operations in a new request. Called as `wp_batch` with `params._meta.request_id`, a batch whose only failures are these refusals (status `413` and code `spend_budget_exhausted` — a `200` that happens to carry that code is not one) is pinned: a replay returns this result, with its `idempotency_reason`, without running anything.

```json
{
  "index": 40,
  "status": 413,
  "data": {
    "code": "spend_budget_exhausted",
    "message": "Spend budget exhausted: this operation costs 25 units and 0 of this request's 1000 remain.",
    "data": { "status": 413, "weight": 25, "remaining": 0, "limit": 1000, "hint": "…" }
  }
}
```

A site can change the budget with the `mcpwp_request_spend_budget` filter and an operation's price with `mcpwp_spend_weight`; nothing in the request itself can.

## Use Cases

### For AI Agents

- Create 5 pages for a new website in one request
- Update SEO metadata for multiple posts
- Fetch site info, posts, and pages for analysis
- Clean up drafts (list + delete in one batch)

### Performance Benefits

- Single HTTP request instead of N requests
- Single authentication check
- Reduced network latency
- Lower server overhead

## Limitations

- **Sequential execution**: Operations run one after another, not in parallel
- **No transactions**: If operation 3 fails, operations 1-2 are not rolled back
- **100 operation limit**: Prevents abuse and excessive resource usage
- **Spend budget**: 1000 units per HTTP request by default; paid and egress operations cost 25
- **Same authentication**: All operations use the batch request's API key

## Troubleshooting

### "batch_too_large" error

Reduce the number of operations to 100 or fewer.

### `spend_budget_exhausted` on some operations

The request's spend budget ran out. The refused operations did not run; resend them (and only them) in a new request. Forty image generations or URL imports fill a default budget.

### Individual operations failing

Check the `status` and `data` for each result. Each operation's error is returned separately.

### Path validation

Paths must be valid MCPWP endpoints. The batch endpoint does not validate paths before execution - invalid paths will return 404 in the results.

## Testing

Use the included test script:

```bash
./test-batch-endpoint.sh https://example.com your-api-key
```
