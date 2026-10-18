<?php
/**
 * Route → (scope, capability) map — #1100 Pass A.
 *
 * ONE row per registered mcpwp/v1 route and method, two columns:
 *  - scope: the API-key scope the route needs.  Bot-run principals (X-API-Key
 *    and ?api_key keys, broker bearer tokens, client_credentials OAuth
 *    tokens) are gated by this column alone.  It is a pinned mirror of
 *    Mcpwp_Api_Auth::get_required_scope_for_request() for the route's base
 *    request (no status/body params) — RouteCapabilityMapTest fails when the
 *    two disagree, so the column cannot drift from the enforcement.
 *  - capability: the WordPress capability a principal that ACTS AS A REAL
 *    USER (Mcpwp_Api_Auth::requester_acts_as_user()) must also hold.  Values:
 *      'none'          no WordPress capability; the row's reason says why
 *      'TODO'          not yet decided — admin-only until decided: a user-run
 *                      principal needs UNDECIDED_ADMIN_ONLY_CAPABILITY
 *                      (manage_options); never a pass-through
 *      'forms:forms' / 'forms:entries'  the forms plugin's own capability,
 *                      resolved from the {plugin} route segment
 *      any other string, a WordPress capability for current_user_can()
 *
 * The scope table stays the outer gate for everyone; the capability column
 * only ever narrows what a user-run principal may do.
 *
 * GENERATED from the #1100 inventory (handoff/2026-10-18-1100-capability-
 * inventory, regenerated at f7901dec): edit a row by hand only together with
 * its reason, and keep RouteCapabilityMapTest green — it asserts both
 * directions (every registered route/method has a row; every row is
 * registered).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pinned route capability map and its resolver.
 */
class Mcpwp_Route_Capability_Map {

	/** Undecided ('TODO') rows and unknown routes are admin-only until decided. */
	const UNDECIDED_ADMIN_ONLY_CAPABILITY = 'manage_options';

	/**
	 * Per-plugin capabilities for the forms routes (Kasra: the plugin's own
	 * capability, manage_options when unknown).
	 *
	 * @var array<string,array{forms:string,entries:string}>
	 */
	const FORM_CAPABILITIES = array(
		// Gravity Forms: its documented role capabilities.
		'gravityforms' => array( 'forms' => 'gravityforms_edit_forms', 'entries' => 'gravityforms_view_entries' ),
		// Contact Form 7 maps wpcf7_read_contact_forms to edit_posts itself.
		// Its entries live in Flamingo, whose message caps are not a stable
		// public contract — fallback until verified.
		'cf7'          => array( 'forms' => 'wpcf7_read_contact_forms', 'entries' => 'manage_options' ),
		// WPForms Lite gates everything on manage_options; the granular caps
		// exist only with Pro access controls — fallback until verified.
		'wpforms'      => array( 'forms' => 'manage_options', 'entries' => 'manage_options' ),
		// Ninja Forms admin screens use manage_options.
		'ninjaforms'   => array( 'forms' => 'manage_options', 'entries' => 'manage_options' ),
	);

	/**
	 * Every row: 'METHOD /route-pattern' => array( scope, capability, reason ).
	 * PATCH resolves through the PUT row and HEAD through GET (WordPress
	 * registers them together via EDITABLE/READABLE).
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function rows() {
		return array(
			'GET /openapi.json' => array( 'read', 'none', 'public pre-authentication endpoint (no credential involved)' ),
			'GET /site-info' => array( 'read', 'read', 'first-connection discovery every client calls; wp-admin dashboard access is read (decided in Pass A, Kasra to confirm)' ),
			'GET /introspect' => array( 'read', 'read', 'first-connection discovery every client calls; wp-admin dashboard access is read (decided in Pass A, Kasra to confirm)' ),
			'GET /onboard' => array( 'read', 'read', 'first-connection discovery every client calls; wp-admin dashboard access is read (decided in Pass A, Kasra to confirm)' ),
			'GET /site-context' => array( 'read', 'read', 'Kasra: the site brief every connector reads first (wp_get_site_context)' ),
			'POST /site-context' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /site-state' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /content-coherence' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /agent-playbooks' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /theme-info' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /site-health' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /flush-permalinks' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /plugins' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /analytics' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /plugin-settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /plugin-settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /plugin-settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /options' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /options' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /options' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /option' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /option' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /favicon' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /favicon' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /favicon' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /favicon' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /search' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'GET /fetch' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'GET /post-meta/(?P<id>\\d+)' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /post-meta/(?P<id>\\d+)' => array( 'write', 'edit_posts', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'GET /rendered-html' => array( 'read', 'read', 'Kasra: rendered output of a post; the per-post read_post check applies through the declared id (wp_get_rendered_html)' ),
			'GET /rendered-copy' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /categories' => array( 'read', 'none', 'public terms' ),
			'GET /tags' => array( 'read', 'none', 'public terms' ),
			'POST /terms' => array( 'write', 'manage_categories', 'Kasra: term writes need manage_categories' ),
			'POST /terms/(?P<id>\\d+)' => array( 'write', 'manage_categories', 'Kasra: term writes need manage_categories' ),
			'PUT /terms/(?P<id>\\d+)' => array( 'write', 'manage_categories', 'Kasra: term writes need manage_categories' ),
			'DELETE /terms/(?P<id>\\d+)' => array( 'write', 'manage_categories', 'Kasra: term writes need manage_categories' ),
			'GET /custom-css' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /custom-css' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /custom-css' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /custom-css/length' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /update' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /update' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /rate-limit' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /rate-limit' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /rate-limit' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /rate-limit/reset' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /api-keys' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /api-keys' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /api-keys/(?P<id>[a-z0-9\\-]+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /design-references' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /design-references' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /design-references/(?P<id>[A-Za-z0-9_-]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /design-references/(?P<id>[A-Za-z0-9_-]+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'PUT /design-references/(?P<id>[A-Za-z0-9_-]+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /guides' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /guides/(?P<topic>[a-z_]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /workflows/(?P<name>[a-z_]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /network/sites' => array( 'read', 'TODO', 'multisite network surface not yet decided (likely manage_sites): admin-only until decided' ),
			'POST /network/switch' => array( 'write', 'TODO', 'multisite network surface not yet decided (likely manage_sites): admin-only until decided' ),
			'GET /network/stats' => array( 'read', 'TODO', 'multisite network surface not yet decided (likely manage_sites): admin-only until decided' ),
			'POST /cache-flush' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /content-graph' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'GET /content-graph/suggestions' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /content-graph/apply-link' => array( 'write', 'edit_posts', 'create: wp-admin needs edit_posts' ),
			'GET /content-graph/validate-links' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'GET /graph' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'GET /graph/node' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'GET /graph/neighbors' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'GET /graph/query' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /graph/rebuild' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /seo/readiness/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/audit' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/issues' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/autofix-plan' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /seo/search-performance/import' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/search-performance' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/flags' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/woocommerce' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/structured-data/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/media/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /keyword-research' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/visibility' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /seo/visibility/refresh' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/opportunities' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /ai-crawlers/policy' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /ai-crawlers/policy' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /ai-crawlers/policy' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /indexnow/status' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /indexnow/submit' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /seo/content-quality/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /posts' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /posts' => array( 'write', 'edit_posts', 'create: wp-admin needs edit_posts' ),
			'GET /posts/(?P<id>\\d+)' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /posts/(?P<id>\\d+)' => array( 'write', 'edit_posts', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'PUT /posts/(?P<id>\\d+)' => array( 'write', 'edit_posts', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'DELETE /posts/(?P<id>\\d+)' => array( 'write', 'delete_posts', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'POST /posts/bulk' => array( 'write', 'edit_posts', 'create: wp-admin needs edit_posts' ),
			'PUT /posts/bulk' => array( 'write', 'edit_posts', 'create: wp-admin needs edit_posts' ),
			'POST /posts/(?P<id>\\d+)/featured-image' => array( 'write', 'edit_posts', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'GET /drafts' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'DELETE /drafts/delete-all' => array( 'write', 'edit_posts', 'create: wp-admin needs edit_posts' ),
			'GET /pages' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /pages' => array( 'write', 'edit_pages', 'Kasra: page creation needs edit_pages' ),
			'GET /pages/(?P<id>\\d+)' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /pages/(?P<id>\\d+)' => array( 'write', 'edit_pages', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'PUT /pages/(?P<id>\\d+)' => array( 'write', 'edit_pages', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'DELETE /pages/(?P<id>\\d+)' => array( 'write', 'delete_pages', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'POST /pages/bulk' => array( 'write', 'edit_pages', 'create: wp-admin needs edit_pages' ),
			'PUT /pages/bulk' => array( 'write', 'edit_pages', 'create: wp-admin needs edit_pages' ),
			'POST /pages/(?P<id>\\d+)/clone' => array( 'write', 'edit_pages', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'GET /pages/by-slug/(?P<slug>[a-z0-9-]+)' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /pages/(?P<id>\\d+)/template' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /templates/page' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /media' => array( 'read', 'none', 'attachment metadata of public media' ),
			'POST /media' => array( 'write', 'upload_files', 'Media library writes need upload_files' ),
			'POST /media/from-url' => array( 'write', 'upload_files', 'Media library writes need upload_files' ),
			'POST /media/bulk' => array( 'write', 'upload_files', 'Media library writes need upload_files' ),
			'POST /media/from-base64' => array( 'write', 'upload_files', 'Media library writes need upload_files' ),
			'POST /media/(?P<id>\\d+)' => array( 'write', 'upload_files', 'Media library writes need upload_files' ),
			'PUT /media/(?P<id>\\d+)' => array( 'write', 'upload_files', 'Media library writes need upload_files' ),
			'DELETE /media/(?P<id>\\d+)' => array( 'write', 'upload_files', 'Media library writes need upload_files' ),
			'GET /menus' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /menus/(?P<menu_id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /menus/assign-location' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /menus/locations' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /menus/setup' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /menus/(?P<menu_id>\\d+)/items' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /menus/(?P<menu_id>\\d+)/items' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /menus/(?P<menu_id>\\d+)/items/(?P<item_id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /menus/(?P<menu_id>\\d+)/items/(?P<item_id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /menus/(?P<menu_id>\\d+)/items/(?P<item_id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /menus/items/(?P<item_id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /menus/items/(?P<item_id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /menus/items/(?P<item_id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /menus/(?P<menu_id>\\d+)/items/reorder' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /content' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /content/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /elementor/status' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /elementor/bulk' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /elementor/(?P<id>\\d+)' => array( 'read', 'read', 'Kasra: a post\'s Elementor data; the per-post read_post check applies through the declared id (wp_get_elementor)' ),
			'POST /elementor/(?P<id>\\d+)' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'GET /elementor/(?P<id>\\d+)/summary' => array( 'read', 'read', 'Kasra: a post\'s Elementor summary; the per-post read_post check applies through the declared id (wp_get_elementor_summary)' ),
			'GET /elementor/(?P<id>\\d+)/preview' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/(?P<id>\\d+)/edit-section' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'POST /elementor/(?P<id>\\d+)/add-section' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'POST /elementor/(?P<id>\\d+)/remove-section' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'POST /elementor/(?P<id>\\d+)/replace-section' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'POST /elementor/(?P<id>\\d+)/patch' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'POST /elementor/(?P<id>\\d+)/edit-widget' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'POST /elementor/page' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'POST /elementor/(?P<id>\\d+)/find-replace' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'POST /elementor/find-replace' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'GET /elementor/widgets' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /elementor/widgets/(?P<type>[\\w-]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /elementor/widget-help/(?P<widget_type>[\\w-]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /elementor/kit-css' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/kit-css' => array( 'write', 'edit_theme_options', 'Kasra: global colours, fonts and kit CSS are site-wide styling' ),
			'GET /elementor/kit-css/blocks' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/kit-css/blocks' => array( 'write', 'edit_theme_options', 'Kasra: global colours, fonts and kit CSS are site-wide styling' ),
			'GET /elementor/kit-css/blocks/(?P<name>[\\w-]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'DELETE /elementor/kit-css/blocks/(?P<name>[\\w-]+)' => array( 'write', 'edit_theme_options', 'Kasra: global colours, fonts and kit CSS are site-wide styling' ),
			'POST /elementor/regenerate-css' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'GET /webhooks/events' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /events/schema' => array( 'read', 'manage_options', 'webhooks are site configuration' ),
			'GET /events' => array( 'read', 'manage_options', 'webhooks are site configuration' ),
			'GET /webhooks' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /webhooks' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /webhooks/(?P<id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /webhooks/(?P<id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /webhooks/(?P<id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /webhooks/(?P<id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /webhooks/(?P<id>\\d+)/test' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /webhooks/(?P<id>\\d+)/logs' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /screenshot' => array( 'write', 'upload_files', 'Media library writes need upload_files' ),
			'GET /cloudflare/status' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /cloudflare/fix-mcp-access' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /cloudflare/purge-cache' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /cloudflare/speed-settings' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /cloudflare/speed-settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /feedback' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /feedback' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /feedback/relay' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /blocks/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /blocks/(?P<id>\\d+)' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'GET /block-types' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /block-patterns' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /blocks/parse' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /blocks/serialize' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /blocks/validate' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /blocks/(?P<id>\\d+)/section' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'GET /blocks/design-system' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /approvals' => array( 'read', 'edit_posts', 'Kasra: the approval queue an editor works from (wp_list_approvals)' ),
			'POST /approvals/purge-closed' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /approvals/(?P<id>[A-Za-z0-9_-]+)/resolve' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /approvals/(?P<id>[A-Za-z0-9_-]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /approvals/(?P<id>[A-Za-z0-9_-]+)/approve' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /approvals/(?P<id>[A-Za-z0-9_-]+)/reject' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /approvals/(?P<id>[A-Za-z0-9_-]+)/apply' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /approvals/(?P<id>[A-Za-z0-9_-]+)/rollback' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /action-log' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /action-log/export' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /action-log/(?P<log_id>[a-z0-9_]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /action-log/(?P<log_id>[a-z0-9_]+)/rollback' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /memory' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /memory' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /memory/(?P<namespace>[a-z0-9_-]+)/(?P<key>[a-z0-9_-]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'DELETE /memory/(?P<namespace>[a-z0-9_-]+)/(?P<key>[a-z0-9_-]+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /signals' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /signals/refresh' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /signals/settings' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /signals/settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /signals/settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /site-blueprints' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /site-blueprints' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /site-blueprints/(?P<id>[a-z0-9_-]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'DELETE /site-blueprints/(?P<id>[a-z0-9_-]+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /site-blueprints/(?P<id>[a-z0-9_-]+)/deploy' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /site-blueprints/extract' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /mcp' => array( 'read', 'none', 'JSON-RPC envelope: every tools/call is re-checked as its own sub-request' ),
			'GET /mcp' => array( 'read', 'none', 'JSON-RPC envelope: every tools/call is re-checked as its own sub-request' ),
			'OPTIONS /mcp' => array( 'read', 'none', 'JSON-RPC envelope: every tools/call is re-checked as its own sub-request' ),
			'GET /oauth/authorize' => array( 'read', 'none', 'public pre-authentication endpoint (no credential involved)' ),
			'POST /oauth/authorize' => array( 'write', 'none', 'public pre-authentication endpoint (no credential involved)' ),
			'POST /oauth/register' => array( 'write', 'none', 'public pre-authentication endpoint (no credential involved)' ),
			'POST /oauth/token' => array( 'write', 'none', 'public pre-authentication endpoint (no credential involved)' ),
			'GET /broker/enroll-callback' => array( 'read', 'none', 'public pre-authentication endpoint (no credential involved)' ),
			'POST /broker/enroll-callback' => array( 'write', 'none', 'public pre-authentication endpoint (no credential involved)' ),
			'POST /broker/enroll-confirm' => array( 'write', 'none', 'public pre-authentication endpoint (no credential involved)' ),
			'POST /broker/enroll-store' => array( 'write', 'none', 'public pre-authentication endpoint (no credential involved)' ),
			'POST /batch' => array( 'write', 'edit_posts', 'create: wp-admin needs edit_posts' ),
			'GET /integrations/providers' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /integrations/providers/(?P<provider>[a-z]+)/key' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /integrations/providers/(?P<provider>[a-z]+)/key' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /integrations/providers/(?P<provider>[a-z]+)/test' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /integrations/status' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /integrations/configure' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /integrations/test' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /integrations/remove' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /integrations/stock-photos' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /integrations/stock-photos/download' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /integrations/generate-image' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /integrations/generate-featured-image' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /integrations/generate-alt-text' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /integrations/describe-image' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /integrations/generate-excerpt' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /integrations/text-to-speech' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /figma/status' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /figma/file' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /figma/node' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /figma/design-tokens' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /figma/design-tokens/sync' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /webmcp/call' => array( 'write', 'none', 'cookie+nonce endpoint; the tool it dispatches is checked as a sub-request' ),
			'GET /elementor/templates' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/templates' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /elementor/templates/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/templates/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'PUT /elementor/templates/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'DELETE /elementor/templates/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/templates/(?P<id>\\d+)/apply' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /elementor/archetypes' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/archetypes' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /elementor/archetypes/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/archetypes/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'PUT /elementor/archetypes/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/archetypes/(?P<id>\\d+)/apply' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /elementor/parts' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/parts' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /elementor/parts/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/parts/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'PUT /elementor/parts/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/parts/(?P<id>\\d+)/apply' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/parts/from-section' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/clone' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'POST /elementor/landing-page' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'GET /elementor/globals' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/globals' => array( 'write', 'edit_theme_options', 'Kasra: global colours, fonts and kit CSS are site-wide styling' ),
			'GET /elementor/custom-code' => array( 'admin', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'POST /elementor/custom-code' => array( 'admin', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'GET /elementor/custom-code/(?P<id>\\d+)' => array( 'admin', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'POST /elementor/custom-code/(?P<id>\\d+)' => array( 'admin', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'PUT /elementor/custom-code/(?P<id>\\d+)' => array( 'admin', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'POST /elementor/custom-code/(?P<id>\\d+)/disable' => array( 'admin', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'POST /elementor/custom-code/(?P<id>\\d+)/enable' => array( 'admin', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'POST /elementor/custom-code/(?P<id>\\d+)/sanitize' => array( 'admin', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'POST /elementor/build-page' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'GET /elementor/blueprints' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /elementor/blueprints/build' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'POST /elementor/save-section-as-template' => array( 'write', 'edit_posts', 'editing a layout = editing the post' ),
			'GET /seo/status' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/(?P<id>\\d+)' => array( 'read', 'edit_posts', 'Kasra: post SEO fields are editing data; the per-post read_post check applies (wp_get_seo)' ),
			'POST /seo/(?P<id>\\d+)' => array( 'write', 'edit_posts', 'Kasra: writing post SEO fields; the per-post edit_post check also applies (wp_set_seo)' ),
			'PUT /seo/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /seo/bulk' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/(?P<id>\\d+)/analyze' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/yoast/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /seo/yoast/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'PUT /seo/yoast/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/rankmath/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /seo/rankmath/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'PUT /seo/rankmath/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/aioseo/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /seo/aioseo/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'PUT /seo/aioseo/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /seo/(?P<id>\\d+)/noindex' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/seopress/(?P<id>\\d+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /seo/seopress/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'PUT /seo/seopress/(?P<id>\\d+)' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/scan' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /seo/report' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /forms/status' => array( 'read', 'forms:forms', 'Kasra: the forms plugin\'s own form capability; manage_options when the plugin is unknown' ),
			'GET /forms' => array( 'read', 'forms:forms', 'Kasra: the forms plugin\'s own form capability; manage_options when the plugin is unknown' ),
			'GET /forms/(?P<plugin>cf7|wpforms|gravityforms|ninjaforms)' => array( 'read', 'forms:forms', 'Kasra: the forms plugin\'s own form capability; manage_options when the plugin is unknown' ),
			'GET /forms/(?P<plugin>cf7|wpforms|gravityforms|ninjaforms)/(?P<id>\\d+)' => array( 'read', 'forms:forms', 'Kasra: the forms plugin\'s own form capability; manage_options when the plugin is unknown' ),
			'GET /forms/(?P<plugin>cf7|wpforms|gravityforms|ninjaforms)/(?P<id>\\d+)/entries' => array( 'admin', 'forms:entries', 'Kasra: the forms plugin\'s own entries capability; manage_options when the plugin is unknown' ),
			'POST /menus' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /menus/(?P<id>[\\d]+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /menus/(?P<id>[\\d]+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /menus/(?P<id>[\\d]+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /menus/(?P<id>[\\d]+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /menus/(?P<menu_id>[\\d]+)/items' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /menus/(?P<menu_id>[\\d]+)/items/(?P<item_id>[\\d]+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /menus/(?P<menu_id>[\\d]+)/items/(?P<item_id>[\\d]+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /menus/(?P<menu_id>[\\d]+)/items/(?P<item_id>[\\d]+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /theme/mods' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /theme/mods' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /theme/mods' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /theme/global-styles' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /theme/custom-css' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /theme/custom-css' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /theme/custom-css' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /pages/(?P<id>[\\d]+)/template' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /pages/(?P<id>[\\d]+)/template' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'PUT /pages/(?P<id>[\\d]+)/template' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /theme-builder/status' => array( 'read', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'GET /theme-builder/locations' => array( 'read', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'GET /theme-builder/conditions' => array( 'read', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'GET /theme-builder/templates' => array( 'read', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'POST /theme-builder/templates' => array( 'write', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'GET /theme-builder/templates/(?P<id>\\d+)' => array( 'read', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'GET /theme-builder/templates/(?P<id>\\d+)/conditions' => array( 'read', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'POST /theme-builder/templates/(?P<id>\\d+)/conditions' => array( 'write', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'PUT /theme-builder/templates/(?P<id>\\d+)/conditions' => array( 'write', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'DELETE /theme-builder/templates/(?P<id>\\d+)/conditions' => array( 'write', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'POST /theme-builder/templates/(?P<id>\\d+)/assign' => array( 'write', 'edit_theme_options', 'Kasra: Elementor theme-builder parts render site-wide' ),
			'GET /users' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /users' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /users/stats' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /users/roles' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /users/(?P<id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /users/(?P<id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /users/(?P<id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'DELETE /users/(?P<id>\\d+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /users/(?P<id>\\d+)/capabilities' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /sidebars' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /widgets/types' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /sidebars/(?P<id>[a-zA-Z0-9_-]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /sidebars/(?P<id>[a-zA-Z0-9_-]+)/widgets' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /sidebars/(?P<id>[a-zA-Z0-9_-]+)/widgets' => array( 'admin', 'edit_theme_options', 'wp-admin Widgets screen needs edit_theme_options' ),
			'POST /sidebars/(?P<id>[a-zA-Z0-9_-]+)/reorder' => array( 'admin', 'edit_theme_options', 'wp-admin Widgets screen needs edit_theme_options' ),
			'GET /widgets/(?P<id>[a-zA-Z0-9_-]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /widgets/(?P<id>[a-zA-Z0-9_-]+)' => array( 'admin', 'edit_theme_options', 'wp-admin Widgets screen needs edit_theme_options' ),
			'PUT /widgets/(?P<id>[a-zA-Z0-9_-]+)' => array( 'admin', 'edit_theme_options', 'wp-admin Widgets screen needs edit_theme_options' ),
			'DELETE /widgets/(?P<id>[a-zA-Z0-9_-]+)' => array( 'admin', 'edit_theme_options', 'wp-admin Widgets screen needs edit_theme_options' ),
			'POST /widgets/(?P<id>[a-zA-Z0-9_-]+)/move' => array( 'admin', 'edit_theme_options', 'wp-admin Widgets screen needs edit_theme_options' ),
			'GET /themes/detect' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /themes/supported' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /themes/settings' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /themes/settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /themes/settings' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /themes/settings/(?P<category>[a-z_]+)' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /themes/astra/colors' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /themes/astra/colors' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /themes/astra/colors' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /themes/astra/typography' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'POST /themes/astra/typography' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'PUT /themes/astra/typography' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /themes/astra/header' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /themes/astra/footer' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /themes/astra/layout' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /woocommerce/status' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /woocommerce/products' => array( 'read', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'POST /woocommerce/products' => array( 'admin', 'edit_products', 'Kasra: product creation (publish stays admin by scope)' ),
			'GET /woocommerce/products/(?P<id>\\d+)' => array( 'read', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'POST /woocommerce/products/(?P<id>\\d+)' => array( 'write', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'PUT /woocommerce/products/(?P<id>\\d+)' => array( 'write', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'DELETE /woocommerce/products/(?P<id>\\d+)' => array( 'write', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'GET /woocommerce/products/categories' => array( 'read', 'none', 'public product terms' ),
			'POST /woocommerce/products/categories' => array( 'write', 'manage_product_terms', 'WooCommerce term cap' ),
			'POST /woocommerce/products/categories/(?P<id>\\d+)' => array( 'write', 'manage_product_terms', 'WooCommerce term cap' ),
			'PUT /woocommerce/products/categories/(?P<id>\\d+)' => array( 'write', 'manage_product_terms', 'WooCommerce term cap' ),
			'GET /woocommerce/products/tags' => array( 'read', 'none', 'public product terms' ),
			'GET /woocommerce/archetypes' => array( 'read', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'POST /woocommerce/archetypes' => array( 'write', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'GET /woocommerce/archetypes/(?P<id>\\d+)' => array( 'read', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'POST /woocommerce/archetypes/(?P<id>\\d+)' => array( 'write', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'PUT /woocommerce/archetypes/(?P<id>\\d+)' => array( 'write', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'POST /woocommerce/archetypes/(?P<id>\\d+)/apply' => array( 'write', 'edit_products', 'Kasra: edit_products (listing includes drafts)' ),
			'GET /woocommerce/orders' => array( 'admin', 'manage_woocommerce', 'Kasra: orders and customers' ),
			'GET /woocommerce/orders/(?P<id>\\d+)' => array( 'admin', 'manage_woocommerce', 'Kasra: orders and customers' ),
			'POST /woocommerce/orders/(?P<id>\\d+)' => array( 'admin', 'manage_woocommerce', 'Kasra: orders and customers' ),
			'PUT /woocommerce/orders/(?P<id>\\d+)' => array( 'admin', 'manage_woocommerce', 'Kasra: orders and customers' ),
			'GET /woocommerce/orders/statuses' => array( 'admin', 'manage_woocommerce', 'Kasra: orders and customers' ),
			'GET /woocommerce/customers' => array( 'admin', 'manage_woocommerce', 'Kasra: orders and customers' ),
			'GET /woocommerce/customers/(?P<id>\\d+)' => array( 'admin', 'manage_woocommerce', 'Kasra: orders and customers' ),
			'GET /woocommerce/analytics' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /languages' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /languages/current' => array( 'write', 'edit_posts', 'create: wp-admin needs edit_posts' ),
			'PUT /languages/current' => array( 'write', 'edit_posts', 'create: wp-admin needs edit_posts' ),
			'GET /posts/(?P<id>\\d+)/translations' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /posts/(?P<id>\\d+)/translations' => array( 'write', 'edit_posts', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'GET /pages/(?P<id>\\d+)/translations' => array( 'read', 'none', 'public content; non-public is per-post read_post (#1099 chokepoint)' ),
			'POST /pages/(?P<id>\\d+)/translations' => array( 'write', 'edit_pages', 'content write by id; the per-post edit_post/delete_post check also applies' ),
			'POST /google-indexing/submit' => array( 'write', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /google-indexing/status' => array( 'read', 'TODO', 'not yet decided: admin-only until decided' ),
			'GET /learnpress/courses' => array( 'read', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'POST /learnpress/courses' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'GET /learnpress/courses/(?P<id>\\d+)' => array( 'read', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'POST /learnpress/courses/(?P<id>\\d+)' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'PUT /learnpress/courses/(?P<id>\\d+)' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'GET /learnpress/courses/(?P<id>\\d+)/curriculum' => array( 'read', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'POST /learnpress/courses/(?P<id>\\d+)/curriculum' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'PUT /learnpress/courses/(?P<id>\\d+)/curriculum' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'GET /learnpress/lessons' => array( 'read', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'POST /learnpress/lessons' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'POST /learnpress/lessons/(?P<id>\\d+)' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'PUT /learnpress/lessons/(?P<id>\\d+)' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'GET /learnpress/quizzes' => array( 'read', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'POST /learnpress/quizzes' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'POST /learnpress/quizzes/(?P<id>\\d+)' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'PUT /learnpress/quizzes/(?P<id>\\d+)' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'GET /learnpress/quizzes/(?P<id>\\d+)/questions' => array( 'read', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'GET /learnpress/categories' => array( 'read', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'POST /learnpress/categories' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'POST /learnpress/categories/(?P<id>\\d+)' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'PUT /learnpress/categories/(?P<id>\\d+)' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'DELETE /learnpress/categories/(?P<id>\\d+)' => array( 'write', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'GET /learnpress/stats' => array( 'read', 'manage_options', 'Kasra: LearnPress is admin-only until its course capabilities are verified' ),
			'POST /events' => array( 'write', 'manage_options', 'webhooks are site configuration' ),
			'GET /events/(?P<id>\\d+)' => array( 'read', 'manage_options', 'webhooks are site configuration' ),
			'POST /events/(?P<id>\\d+)' => array( 'write', 'manage_options', 'webhooks are site configuration' ),
			'PUT /events/(?P<id>\\d+)' => array( 'write', 'manage_options', 'webhooks are site configuration' ),
			'GET /staging' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'POST /staging/push' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
			'GET /staging/status/(?P<id>[a-zA-Z0-9_\\-]+)' => array( 'admin', 'manage_options', 'admin scope: only an administrator principal reaches it as a user' ),
		);
	}

	/**
	 * MCP tools whose declared route is not literally a registered pattern
	 * (a {type} placeholder, or a multisite-only route), each pointing at the
	 * rows it dispatches to.  Every other tool resolves through its route.
	 *
	 * @return array<string,string[]>
	 */
	public static function tool_rows() {
		return array(
			'wp_get_translations' => array( 'GET /posts/(?P<id>\\d+)/translations', 'GET /pages/(?P<id>\\d+)/translations' ),
			'wp_create_translation' => array( 'POST /posts/(?P<id>\\d+)/translations', 'POST /pages/(?P<id>\\d+)/translations' ),
			'wp_network_sites' => array( 'GET /network/sites' ),
			'wp_network_switch' => array( 'POST /network/switch' ),
			'wp_network_stats' => array( 'GET /network/stats' ),
		);
	}

	/**
	 * Resolve a request to its row.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Request route, with or without the /mcpwp/v1 prefix.
	 * @return array{key:string,scope:string,capability:string,reason:string}|null
	 */
	public static function resolve( $method, $route ) {
		static $cache = array();

		$method = strtoupper( (string) $method );
		if ( 'HEAD' === $method ) {
			$method = 'GET';
		} elseif ( 'PATCH' === $method ) {
			$method = 'PUT';
		}

		$path = '/' . ltrim( (string) $route, '/' );
		if ( 0 === stripos( $path, '/mcpwp/v1/' ) || 0 === strcasecmp( $path, '/mcpwp/v1' ) ) {
			$path = (string) substr( $path, strlen( '/mcpwp/v1' ) );
		}
		$path = '' === $path ? '/' : untrailingslashit( $path );
		if ( '' === $path ) {
			$path = '/';
		}

		$cache_key = $method . ' ' . $path;
		if ( array_key_exists( $cache_key, $cache ) ) {
			return $cache[ $cache_key ];
		}

		$found = null;
		foreach ( self::rows() as $key => $row ) {
			list( $row_method, $pattern ) = explode( ' ', $key, 2 );
			if ( $row_method !== $method ) {
				continue;
			}
			if ( preg_match( '@^' . $pattern . '$@i', $path ) ) {
				$found = array(
					'key'        => $key,
					'scope'      => $row[0],
					'capability' => $row[1],
					'reason'     => $row[2],
				);
				break;
			}
		}

		if ( count( $cache ) > 256 ) {
			$cache = array();
		}
		$cache[ $cache_key ] = $found;

		return $found;
	}

	/**
	 * The WordPress capability a user-run principal needs for a request, or
	 * '' when none is needed.  Unknown routes and TODO rows are admin-only
	 * until decided (closed to everyone else).
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Request route.
	 * @return string
	 */
	public static function required_capability( $method, $route ) {
		$row = self::resolve( $method, $route );
		if ( null === $row || 'TODO' === $row['capability'] ) {
			return self::UNDECIDED_ADMIN_ONLY_CAPABILITY;
		}
		if ( 'none' === $row['capability'] ) {
			return '';
		}
		if ( 0 === strpos( $row['capability'], 'forms:' ) ) {
			$kind   = substr( $row['capability'], 6 );
			$plugin = preg_match( '#/forms/([a-z0-9_-]+)(/|$)#i', (string) $route, $m ) ? strtolower( $m[1] ) : '';
			if ( isset( self::FORM_CAPABILITIES[ $plugin ][ $kind ] ) ) {
				return self::FORM_CAPABILITIES[ $plugin ][ $kind ];
			}
			return 'manage_options';
		}
		return $row['capability'];
	}
}
