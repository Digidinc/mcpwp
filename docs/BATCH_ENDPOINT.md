# Batch Endpoint

The batch endpoint allows executing multiple REST API operations in a single HTTP request, significantly reducing round-trip time for AI agents performing bulk operations.

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

- Maximum **25 operations** per batch
- Operations execute sequentially (not in parallel)
- Each operation inherits authentication from the batch request
- If one operation fails, remaining operations still execute
- An operation may **not** target `/batch` or `/mcp`. Those endpoints dispatch internally, and allowing one inside the other would let a single counted request fan out into an unbounded tree of uncounted ones. Such an operation returns `forbidden_path` with status `400`; the rest of the batch still runs.

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
  "total": 2
}
```

Each result contains:
- **index**: Operation position in the batch (0-based)
- **status**: HTTP status code
- **data**: Response data from the operation

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
  "message": "Batch contains too many operations. Maximum 25 allowed.",
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
  "total": 2
}
```

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
- **25 operation limit**: Prevents abuse and excessive resource usage
- **Same authentication**: All operations use the batch request's API key

## Troubleshooting

### "batch_too_large" error

Reduce the number of operations to 25 or fewer.

### Individual operations failing

Check the `status` and `data` for each result. Each operation's error is returned separately.

### Path validation

Paths must be valid MCPWP endpoints. The batch endpoint does not validate paths before execution - invalid paths will return 404 in the results.

## Testing

Use the included test script:

```bash
./test-batch-endpoint.sh https://example.com your-api-key
```
