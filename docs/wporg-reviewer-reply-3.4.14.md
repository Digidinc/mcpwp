# Reviewer reply draft — mumega-mcp 3.4.14 (round 5)

Review ID: `R mumega-mcp/mumega/24Feb26/T11`. Post this with the 3.4.14 upload.
Per the reviewer checklist we do **not** enumerate every internal change — the
changelog covers those; this reply speaks only to the two items they flagged.

---

Hi, thanks for the detailed review. Both points are addressed in 3.4.14.

**1. `require_once ABSPATH . 'wp-admin/includes/media.php'` flagged as a dead
include.** You were right that no `media.php` function was called on the
image-upload path, and we've fixed the include accordingly:

- For image uploads (the overwhelming majority) the `media.php` include is now
  **removed entirely** — it was never needed there.
- It is loaded **only** for audio/video uploads, immediately before
  `wp_generate_attachment_metadata()`. For those attachment types
  `wp_generate_attachment_metadata()` calls `wp_read_video_metadata()` /
  `wp_read_audio_metadata()`, which are defined only in
  `wp-admin/includes/media.php`. The include is guarded by the exact MIME check
  (`audio/*`, `video/*`) under which those functions are reached, so it is loaded
  only when — and immediately before — one of its functions is used.

**2. Missing `permission_callback` on the REST routes.** Every
`register_rest_route()` call site now carries a literal
`'permission_callback' => array( $this, 'check_…_permission' )` inline, so the
authorization callback is visible to static analysis and Plugin Check at each
route. (Previously the callback was supplied through a shared route-builder
helper, which is why it didn't appear at the individual call sites.)

For context on the auth model: every route is authenticated via a hashed
API-key check with per-key scopes; there are no intentionally public routes.

Before uploading we ran Plugin Check (0 errors) and a clean-install pass with
`WP_DEBUG` / `WP_DEBUG_LOG` enabled (no notices, warnings, or errors on
activation or on the media and REST paths).

Thanks again for the review.

---

## Verification artifacts to attach / cite (internal — not all for the reply)
- Media conditional include: `includes/core/class-mcpwp-media.php` `finalize_attachment()` (audio/video-gated require) + image path require removed.
- permission_callback literals: `includes/api/class-mcpwp-rest-api.php` — 19–21 call sites, each an inline literal.
- Plugin Check: run + paste "0 errors" summary.
- WP_DEBUG smoke: `scripts/test-wporg-clean-install.sh` output.
