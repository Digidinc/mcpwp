<?php

/**
 * PHPUnit bootstrap with lightweight WordPress stubs.
 */

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (! defined('MCPWP_VERSION')) {
    define('MCPWP_VERSION', 'test');
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

$GLOBALS['mcpwp_test_options'] = array();
$GLOBALS['mcpwp_test_transients'] = array();
// Persistent object cache double (#752 F6). Off by default, so every test that
// does not opt in sees WordPress without an object-cache drop-in — the state
// the suite was written against. See wp_using_ext_object_cache() below.
$GLOBALS['mcpwp_test_ext_object_cache'] = false;
$GLOBALS['mcpwp_test_object_cache'] = array();
// Redis semantics for the double below: incr/decr on a missing key create it
// (from zero, no expiry) and values may go negative. Off = core/memcached.
$GLOBALS['mcpwp_test_object_cache_incr_creates'] = false;
$GLOBALS['mcpwp_test_cache_addition_suspended'] = false;
$GLOBALS['mcpwp_test_current_user'] = 0;
// Dispatch spy for rest_do_request() below — records the route of every
// internal REST dispatch so tests can assert fan-out actually happened
// (or did not), not merely infer it from a stubbed response shape.
$GLOBALS['mcpwp_test_dispatched_routes'] = array();
// Routes a rest_dispatch_request filter refused before their callback ran.
$GLOBALS['mcpwp_test_refused_routes'] = array();
// Route-matching emulation for rest_do_request() (see there).
$GLOBALS['mcpwp_test_route_patterns'] = array();
$GLOBALS['mcpwp_test_route_permission_denied'] = array();
$GLOBALS['mcpwp_test_denied_routes'] = array();
// Per-user capability overrides for OAuth scope-clamping tests.
// Key: user_id (int), Value: array of capability => bool.
// When empty (or user_id not present), current_user_can() grants all caps.
$GLOBALS['mcpwp_test_user_caps'] = array();
$GLOBALS['mcpwp_test_users'] = array(
    (object) array( 'ID' => 2, 'roles' => array( 'mcpwp_api_agent' ) ),
);
// Role registry double for add_role()/get_role() — keyed by role slug, value
// a WP_Role instance. Lets #861b's capability-grant migration be exercised
// against something that behaves like WordPress's real role storage (a
// pre-existing role is left alone by add_role(), only add_cap()/remove_cap()
// mutate it) without pulling in all of wp-admin/includes/capabilities.php.
$GLOBALS['mcpwp_test_roles'] = array();

/**
 * Minimal stand-in for WP_User. Production code identifies the user a
 * capability check is running for by `$user instanceof WP_User`, so the class
 * has to exist for a user_has_cap filter to be testable at all.
 */
class WP_User
{
    public $ID;

    public function __construct($id = 0)
    {
        $this->ID = (int) $id;
    }
}

/**
 * Minimal stand-in for WP_Role — just enough for #861b's capability-grant
 * migration (has_cap/add_cap/remove_cap against a plain associative array),
 * mirroring core's own WP_Role shape closely enough that production code
 * written against the real class needs no changes to run against this double.
 *
 * Deliberately NOT a model of WordPress's real role storage: in core, every
 * role lives inside ONE option (`wp_user_roles`), and add_cap()/remove_cap()
 * each read-modify-write that whole option — every role, every capability —
 * not just the single capability named. This double instead keeps each
 * role as its own independent object in $GLOBALS['mcpwp_test_roles'], so
 * add_cap()/remove_cap() here mutate only that one role's array in memory.
 * That means tests built on this double (e.g.
 * AgentRoleManageSettingsCapabilityTest) can verify the CAPABILITY-level
 * behaviour of the activator migrations, but they cannot exercise or refute
 * anything about the single-option round-trip mechanism — do not read a
 * green result here as evidence about concurrent-write/race behaviour
 * against the real wp_user_roles option (see the docblock in mcpwp.php
 * above strip_agent_manage_settings_capability()'s registration for what is
 * and is not actually established there).
 */
class WP_Role
{
    public $name;
    public $capabilities;

    public function __construct($role, $capabilities = array())
    {
        $this->name         = $role;
        $this->capabilities = $capabilities;
    }

    public function has_cap($cap)
    {
        return ! empty($this->capabilities[ $cap ]);
    }

    public function add_cap($cap, $grant = true)
    {
        $this->capabilities[ $cap ] = (bool) $grant;
        $GLOBALS['mcpwp_test_roles'][ $this->name ] = $this;
    }

    public function remove_cap($cap)
    {
        unset($this->capabilities[ $cap ]);
        $GLOBALS['mcpwp_test_roles'][ $this->name ] = $this;
    }
}

/**
 * add_role() no-ops when the role already exists — real WordPress behaviour,
 * and the exact reason #861b needs a separate one-time migration for
 * already-installed sites rather than relying on this to backfill new caps.
 */
function add_role($role, $display_name, $capabilities = array())
{
    if (isset($GLOBALS['mcpwp_test_roles'][ $role ])) {
        return null;
    }

    $GLOBALS['mcpwp_test_roles'][ $role ] = new WP_Role($role, $capabilities);
    return $GLOBALS['mcpwp_test_roles'][ $role ];
}

function get_role($role)
{
    return $GLOBALS['mcpwp_test_roles'][ $role ] ?? null;
}

function remove_role($role)
{
    unset($GLOBALS['mcpwp_test_roles'][ $role ]);
}

class WP_Error
{
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = null)
    {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }

    public function get_error_data()
    {
        return $this->data;
    }

    /**
     * Real WP_Error::remove() drops every message and the data recorded
     * under $code. This double only ever holds one code, so "remove the
     * current code" is "go back to empty" — good enough for the only
     * caller in this codebase (Mcpwp_Error_Hints::enhance_error(), which
     * always removes the code it is about to re-add).
     */
    public function remove($code)
    {
        if ($this->code === $code) {
            $this->code    = '';
            $this->message = '';
            $this->data    = null;
        }
    }

    /**
     * Real WP_Error::add() appends a message under $code and, when $data
     * is not an empty string, replaces the data recorded for it. Matches
     * real behaviour closely enough for enhance_error()'s remove-then-add
     * round trip (it never accumulates multiple messages on one code).
     */
    public function add($code, $message, $data = '')
    {
        $this->code    = $code;
        $this->message = $message;
        if ('' !== $data) {
            $this->data = $data;
        }
    }
}

class WP_REST_Response
{
    private $data;
    private $status;
    private $headers = array();

    public function __construct($data = null, $status = 200)
    {
        $this->data   = $data;
        $this->status = $status;
    }

    public function get_data()
    {
        return $this->data;
    }

    public function set_data($data)
    {
        $this->data = $data;
    }

    public function get_status()
    {
        return $this->status;
    }

    public function header($name, $value)
    {
        $this->headers[ $name ] = $value;
    }

    public function get_headers()
    {
        return $this->headers;
    }
}

class WP_REST_Request
{
    private $method;
    private $route;
    private $params;
    private $headers;
    private $json_params;
    private $query_params = array();
    private $body_params = array();

    public function __construct(
        $method = 'GET',
        $route = '/',
        $params = array(),
        $headers = array(),
        $json_params = null
    ) {
        $this->method     = strtoupper($method);
        $this->route      = $route;
        $this->params     = is_array($params) ? $params : array();
        // Constructor params model the request body (what a POST/PUT carries);
        // query params are added with set_query_params().
        $this->body_params = $this->params;
        $this->headers    = is_array($headers) ? $headers : array();
        $this->json_params = $json_params;
    }

    public function get_method()
    {
        return $this->method;
    }

    public function get_route()
    {
        return $this->route;
    }

    public function get_param($key)
    {
        return isset($this->params[ $key ]) ? $this->params[ $key ] : null;
    }

    public function has_param($key)
    {
        // Real WP_REST_Request::has_param() uses array_key_exists() (not
        // isset()), so a param explicitly sent as null still counts as
        // "present" — the distinction production code relies on to tell
        // "the caller sent this field, even empty" from "the caller never
        // mentioned it" (e.g. Mcpwp_REST_Elementor_Pro::
        // has_elementor_payload_in_request(), RC review finding #1).
        return array_key_exists($key, $this->params);
    }

    public function set_param($key, $value)
    {
        $this->params[ $key ] = $value;
    }

    public function get_params()
    {
        return $this->params;
    }

    public function get_header($name)
    {
        $target = strtolower($name);
        foreach ($this->headers as $header => $value) {
            if (strtolower($header) === $target) {
                return $value;
            }
        }
        return null;
    }

    public function set_header($name, $value)
    {
        $this->headers[ $name ] = $value;
    }

    public function set_body_params($params)
    {
        if (is_array($params)) {
            $this->body_params = array_merge($this->body_params, $params);
            $this->params      = array_merge($this->params, $params);
        }
    }

    public function set_query_params($params)
    {
        if (is_array($params)) {
            $this->query_params = array_merge($this->query_params, $params);
            $this->params       = array_merge($this->params, $params);
        }
    }

    public function get_query_params()
    {
        return $this->query_params;
    }

    public function get_body_params()
    {
        return $this->body_params;
    }

    public function get_json_params()
    {
        return $this->json_params;
    }

    // Real WP_REST_Request parses a JSON body into get_json_params() when
    // the content type is JSON; internal dispatchers (admin chat, WebMCP
    // #981) build their /mcp request this way.
    private $body = '';

    public function set_body($body)
    {
        $this->body = (string) $body;
        $decoded    = json_decode($this->body, true);
        if (is_array($decoded)) {
            $this->json_params = $decoded;
        }
    }

    public function get_body()
    {
        return $this->body;
    }

}

class WP_REST_Server
{
    public const READABLE  = 'GET';
    public const CREATABLE = 'POST';
    public const EDITABLE  = 'POST,PUT,PATCH';
    public const DELETABLE = 'DELETE';
}

function __($text, $domain = 'default')
{
    // Mirror WP 6.7+'s "_load_textdomain_just_in_time" doing-it-wrong: real
    // WordPress lazy-loads a non-default text domain's translations on first
    // use, and logs a notice if that first use happens before the `init`
    // action has fired. Record every call that would have tripped it, keyed
    // by domain, so a test can assert "no early translation call for domain
    // X" without needing a real WP_Textdomain_Registry.
    if ('default' !== $domain && 0 === did_action('init')) {
        $GLOBALS['mcpwp_test_early_textdomain_calls'][] = array(
            'text'   => $text,
            'domain' => $domain,
        );
    }
    return $text;
}

function _n($single, $plural, $number)
{
    return 1 === (int) $number ? $single : $plural;
}

if (! function_exists('wp_kses_post')) {
    function wp_kses_post($text)
    {
        // Enough of core's behaviour for tests to tell "post-safe HTML" from
        // "left as sent": script/style blocks and their bodies are removed.
        if (! is_string($text)) {
            return $text;
        }
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
        // Core normalises a bare ampersand to an entity, which is how a
        // plain-text value run through kses comes back changed.
        return preg_replace('/&(?!#?[a-z0-9]+;)/i', '&amp;', $text);
    }
}

if (! function_exists('maybe_serialize')) {
    function maybe_serialize($data)
    {
        return is_array($data) || is_object($data) ? serialize($data) : $data;
    }
}

if (! function_exists('maybe_unserialize')) {
    function maybe_unserialize($data)
    {
        if (is_string($data)) {
            $trimmed = trim($data);
            if (preg_match('/^[aOs]:[0-9]+:/', $trimmed) || 'b:0;' === $trimmed || 'b:1;' === $trimmed || 'N;' === $trimmed) {
                $out = @unserialize($trimmed);
                return (false === $out && 'b:0;' !== $trimmed) ? $data : $out;
            }
        }
        return $data;
    }
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

function sanitize_text_field($value)
{
        // Mirror core: tags stripped, control characters and surrounding
        // whitespace removed. A passthrough here hid a stored-XSS class.
        $value = is_scalar($value) ? (string) $value : '';
        $value = strip_tags($value);
        $value = preg_replace('/[\r\n\t ]+/', ' ', $value);
        return trim($value);
}

function sanitize_textarea_field($value)
{
    return trim((string) $value);
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'test-nonce';
    }
}

if (! function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1)
    {
        return 'test-nonce' === $nonce ? 1 : false;
    }
}

if (! function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true)
    {
        $field = '<input type="hidden" name="' . $name . '" value="test-nonce" />';
        if ($echo) {
            echo $field;
        }
        return $field;
    }
}

function sanitize_key($value)
{
    $value = strtolower((string) $value);
    return preg_replace('/[^a-z0-9_\\-]/', '', $value);
}

function sanitize_title($value)
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim($value, '-');
}

function sanitize_email($value)
{
    return filter_var($value, FILTER_SANITIZE_EMAIL);
}

function sanitize_user($value)
{
    return preg_replace('/[^a-zA-Z0-9_\\-.@]/', '', (string) $value);
}

function absint($value)
{
    return abs((int) $value);
}

function esc_url_raw($value)
{
    return filter_var((string) $value, FILTER_SANITIZE_URL);
}

if (! function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default')
    {
        echo esc_html($text);
    }
}

function is_email($value)
{
    return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function wp_set_current_user($user_id)
{
    $GLOBALS['mcpwp_test_current_user'] = (int) $user_id;
}

function user_can($user, $capability)
{
    // $GLOBALS['mcpwp_test_user_caps'][$id] is either a cap => bool map (the
    // OAuth tests' convention) or a list of cap names. Unset means no caps.
    $id   = is_object($user) ? (int) $user->ID : (int) $user;
    $caps = $GLOBALS['mcpwp_test_user_caps'][ $id ] ?? array();
    if ($id < 1 || ! is_array($caps)) {
        return false;
    }
    if (array_key_exists($capability, $caps)) {
        return (bool) $caps[ $capability ];
    }
    return in_array($capability, $caps, true);
}

function get_current_user_id()
{
    return (int) $GLOBALS['mcpwp_test_current_user'];
}

function get_userdata($user_id)
{
    foreach ($GLOBALS['mcpwp_test_users'] as $user) {
        if ((int) $user->ID === (int) $user_id) {
            if (empty($user->user_login)) {
                $user->user_login = 'mcpwp_test_user';
            }
            return $user;
        }
    }
    return false;
}

function get_users($args = array())
{
    $users = isset($GLOBALS['mcpwp_test_users']) ? $GLOBALS['mcpwp_test_users'] : array();
    if (empty($args['role'])) {
        return $users;
    }

    $filtered = array();
    foreach ($users as $user) {
        if (! empty($user->roles) && in_array($args['role'], $user->roles, true)) {
            $filtered[] = $user;
        }
    }

    return $filtered;
}

function current_user_can($capability)
{
    $user_id = (int) $GLOBALS['mcpwp_test_current_user'];
    if (0 === $user_id) {
        return false;
    }

    // If per-user caps are configured for this user_id, check them.
    // This lets OAuth scope-clamping tests drive subscriber/editor/admin behavior.
    if (isset($GLOBALS['mcpwp_test_user_caps'][ $user_id ])) {
        $caps = $GLOBALS['mcpwp_test_user_caps'][ $user_id ];
        // Explicit true/false per capability key.
        if (array_key_exists($capability, $caps)) {
            if ($caps[ $capability ]) {
                return true;
            }
            return mcpwp_test_cap_filters_grant($capability, $user_id);
        }
        // If the caps array exists but the key is absent, deny the cap unless a
        // scoped filter grants it.
        return mcpwp_test_cap_filters_grant($capability, $user_id);
    }

    // Default: grant all capabilities to any logged-in user (original behaviour).
    return true;
}

/**
 * Run the map_meta_cap / user_has_cap filters the way WordPress does, for a
 * capability the static cap map denied.
 *
 * Deliberately additive: it can only turn a denial into a grant, never the
 * reverse, so adding it cannot change the verdict of any test that passed
 * before these filters existed. It is what lets a test observe a *scoped*
 * capability grant actually taking effect rather than merely being registered.
 */
function mcpwp_test_cap_filters_grant($capability, $user_id)
{
    $caps = apply_filters('map_meta_cap', array( $capability ), $capability, $user_id, array());

    if (! is_array($caps) || in_array('do_not_allow', $caps, true)) {
        return false;
    }

    $allcaps = apply_filters('user_has_cap', array(), $caps, array( $capability ), new WP_User($user_id));

    if (! is_array($allcaps)) {
        return false;
    }

    foreach ($caps as $cap) {
        if (empty($allcaps[ $cap ])) {
            return false;
        }
    }

    return true;
}

function get_option($name, $default = false)
{
    if (array_key_exists($name, $GLOBALS['mcpwp_test_options'])) {
        return $GLOBALS['mcpwp_test_options'][ $name ];
    }
    // One options table in real WordPress: a row created behind the options
    // API (a first-writer-wins INSERT, #845) is what get_option() returns for
    // a name the options API has not stored. The options-API value still wins
    // when both exist, so every test that seeds through it reads what it seeded.
    if (array_key_exists($name, $GLOBALS['mcpwp_test_wpdb_options'] ?? array())) {
        return maybe_unserialize($GLOBALS['mcpwp_test_wpdb_options'][ $name ]);
    }
    return $default;
}

if (interface_exists('PHPUnit\Runner\AfterTestHook')) {
    /**
     * The provider records can now be created as a raw row (#845). Without
     * this, a row created by one test would shadow the records a later test
     * seeds through the options API — the row probe prefers the raw row — and
     * the suite would depend on its order (#757's family).
     */
    final class Mcpwp_Test_Raw_Option_Row_Reset implements \PHPUnit\Runner\AfterTestHook
    {
        public function executeAfterTest(string $test, float $time): void
        {
            unset($GLOBALS['mcpwp_test_wpdb_options']['mcpwp_integrations']);
        }
    }
}

function update_option($name, $value, $autoload = null)
{
    // Test-only: mimic WordPress's sanitize_option() — a hook may alter the
    // value before storage, or refuse it (null = refused, nothing stored).
    if (isset($GLOBALS['mcpwp_test_option_store_hook']) && is_callable($GLOBALS['mcpwp_test_option_store_hook'])) {
        $value = call_user_func($GLOBALS['mcpwp_test_option_store_hook'], $name, $value);
        if (null === $value) {
            return false;
        }
    }
    // Allow tests to simulate DB write failures for specific option names.
    if (! empty($GLOBALS['mcpwp_test_update_option_fail'][ $name ])) {
        return false;
    }
    $GLOBALS['mcpwp_test_options'][ $name ] = $value;
    // Recorded so tests can assert a caller passed autoload=false explicitly
    // (e.g. a large/rarely-read option that must not load on every request).
    $GLOBALS['mcpwp_test_options_autoload'][ $name ] = $autoload;
    if (isset($GLOBALS['mcpwp_test_option_store_after_hook']) && is_callable($GLOBALS['mcpwp_test_option_store_after_hook'])) {
        call_user_func($GLOBALS['mcpwp_test_option_store_after_hook'], $name, $value);
    }
    return true;
}

function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
{
    // NOT an atomic primitive, and not one in WordPress either: core's
    // add_option() is a cached read-then-write over INSERT ... ON DUPLICATE
    // KEY UPDATE. Claims go through Mcpwp_Idempotency_Claim / $wpdb->insert.
    if (array_key_exists($name, $GLOBALS['mcpwp_test_options'] ?? array())) {
        return false;
    }
    if (! empty($GLOBALS['mcpwp_test_update_option_fail'][ $name ])) {
        return false;
    }
    $GLOBALS['mcpwp_test_options'][ $name ] = $value;
    return true;
}

function is_serialized($data, $strict = true)
{
    // Core: a:/O: end in '}', scalars in ';', 'N;' is null.
    if (! is_string($data)) {
        return false;
    }
    $data = trim($data);
    if ('N;' === $data) {
        return true;
    }
    if (! preg_match('/^([adObis]):/', $data, $m)) {
        return false;
    }
    $last = substr($data, -1);
    return in_array($m[1], array('a', 'O'), true) ? '}' === $last : ';' === $last;
}

function wp_delete_post($post_id, $force_delete = false)
{
    $post_id = (int) $post_id;
    if (empty($GLOBALS['_mcpwp_test_posts'][ $post_id ])) {
        return false;
    }
    $post = $GLOBALS['_mcpwp_test_posts'][ $post_id ];
    // Core trashes on force=false ONLY for post and page (and only while
    // EMPTY_TRASH_DAYS is set); every other type is deleted outright. A
    // double that trashed everything hid an ungated permanent delete
    // (#536 round 5).
    $trashes = ! $force_delete && in_array($post->post_type, array('post', 'page'), true) && (! defined('EMPTY_TRASH_DAYS') || EMPTY_TRASH_DAYS);
    if ($trashes && 'trash' !== $post->post_status) {
        $post->post_status = 'trash';
        return $post;
    }
    // Real WordPress fires this immediately before the actual delete, with
    // the post object as of WP 5.5 (#952: Mcpwp_Theme_Builder listens here
    // to keep elementor_pro_theme_builder_conditions in sync with every
    // elementor_library delete, not just the ones MCPWP's own routes know
    // about). Never fired on the trash branch above — matches core.
    do_action('before_delete_post', $post_id, $post);
    unset($GLOBALS['_mcpwp_test_posts'][ $post_id ], $GLOBALS['_mcpwp_test_meta'][ $post_id ]);
    return $post;
}

if (! function_exists('wp_delete_attachment')) {
    function wp_delete_attachment($post_id, $force_delete = false)
    {
        // Core trashes an attachment only when MEDIA_TRASH is on.
        $force = $force_delete || ! (defined('MEDIA_TRASH') && MEDIA_TRASH);
        return wp_delete_post($post_id, $force);
    }
}

function wp_trash_post($post_id)
{
    // #1023: wp_trash_post() is a REAL, generic core function distinct from
    // wp_delete_post() — it soft-deletes ANY post type (not just post/page),
    // which is exactly why Mcpwp_Posts::delete_post()/Mcpwp_REST_Content::
    // delete_content() now call it directly for a non-forced delete of a
    // type wp_delete_post()'s own narrower built-in branch would otherwise
    // delete outright. Aliasing this to wp_delete_post($id, false) (as this
    // double used to) made every such call silently permanent in tests too,
    // masking the exact bug #1023 fixed.
    $post_id = (int) $post_id;
    if (empty($GLOBALS['_mcpwp_test_posts'][ $post_id ])) {
        return false;
    }
    if (defined('EMPTY_TRASH_DAYS') && ! EMPTY_TRASH_DAYS) {
        return wp_delete_post($post_id, true);
    }
    $post = $GLOBALS['_mcpwp_test_posts'][ $post_id ];
    if ('trash' === $post->post_status) {
        return false;
    }
    $post->post_status = 'trash';
    return $post;
}

function delete_option($name)
{
    unset($GLOBALS['mcpwp_test_options'][ $name ]);
    return true;
}

function set_transient($name, $value, $expiration)
{
    // Test-only escape hatch: lets a test simulate a transient backend that
    // cannot persist a write (full/read-only options table, unreachable
    // external object cache) without needing a real broken backend.
    // May be set to `true` (every write fails) or a callable `fn($name): bool`
    // (only writes it approves fail), so a test can fail one specific
    // transient key without affecting the other.
    $write_fails = $GLOBALS['mcpwp_test_transient_write_fails'] ?? false;
    $should_fail = is_callable($write_fails) ? (bool) $write_fails($name) : (bool) $write_fails;
    if ($should_fail) {
        return false;
    }

    $GLOBALS['mcpwp_test_transients'][ $name ] = array(
        'value'   => $value,
        'expires' => time() + (int) $expiration,
        // Requested TTL, kept verbatim. 'expires' cannot be compared reliably
        // when two writes land in the same second.
        'ttl'     => (int) $expiration,
    );
    return true;
}

function get_transient($name)
{
    // Test-only: a hook that can answer a read differently from the store —
    // an object cache that flaps between consecutive reads (#802 round 4).
    if (isset($GLOBALS['mcpwp_test_transient_hook']) && is_callable($GLOBALS['mcpwp_test_transient_hook'])) {
        $answer = call_user_func($GLOBALS['mcpwp_test_transient_hook'], $name);
        if (null !== $answer) {
            return $answer;
        }
    }

    if (! isset($GLOBALS['mcpwp_test_transients'][ $name ])) {
        return false;
    }

    $item = $GLOBALS['mcpwp_test_transients'][ $name ];
    if ($item['expires'] <= time()) {
        unset($GLOBALS['mcpwp_test_transients'][ $name ]);
        return false;
    }

    return $item['value'];
}

function delete_transient($name)
{
    unset($GLOBALS['mcpwp_test_transients'][ $name ]);
    return true;
}

function get_user_meta($user_id, $key = '', $single = false)
{
    $user_id = (int) $user_id;
    if ('' === $key) {
        return isset($GLOBALS['mcpwp_test_user_meta'][ $user_id ]) ? $GLOBALS['mcpwp_test_user_meta'][ $user_id ] : array();
    }
    $value = isset($GLOBALS['mcpwp_test_user_meta'][ $user_id ][ $key ]) ? $GLOBALS['mcpwp_test_user_meta'][ $user_id ][ $key ] : '';
    return $single ? $value : array( $value );
}

function update_user_meta($user_id, $key, $value)
{
    $GLOBALS['mcpwp_test_user_meta'][ (int) $user_id ][ $key ] = $value;
    return true;
}

function delete_user_meta($user_id, $key)
{
    unset($GLOBALS['mcpwp_test_user_meta'][ (int) $user_id ][ $key ]);
    return true;
}

// -------------------------------------------------------------------------
// Minimal hooks system (filters/actions) for unit tests.
// -------------------------------------------------------------------------

$GLOBALS['mcpwp_test_filters'] = array();
$GLOBALS['mcpwp_test_actions'] = array();

function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['mcpwp_test_filters'][ $tag ][ (int) $priority ][] = array(
        'callback'      => $callback,
        'accepted_args' => (int) $accepted_args,
    );
    return true;
}

function apply_filters($tag, $value)
{
    $args = func_get_args();
    array_shift($args); // $tag
    $value = array_shift($args); // $value

    if (empty($GLOBALS['mcpwp_test_filters'][ $tag ])) {
        return $value;
    }

    $by_priority = $GLOBALS['mcpwp_test_filters'][ $tag ];
    ksort($by_priority);
    foreach ($by_priority as $callbacks) {
        foreach ($callbacks as $item) {
            $cb_args = array_merge(array( $value ), array_slice($args, 0, $item['accepted_args'] - 1));
            $value   = call_user_func_array($item['callback'], $cb_args);
        }
    }

    return $value;
}

function remove_filter($tag, $callback, $priority = 10)
{
    $priority = (int) $priority;
    if (empty($GLOBALS['mcpwp_test_filters'][ $tag ][ $priority ])) {
        return false;
    }

    $removed = false;
    foreach ($GLOBALS['mcpwp_test_filters'][ $tag ][ $priority ] as $index => $item) {
        if ($item['callback'] === $callback) {
            unset($GLOBALS['mcpwp_test_filters'][ $tag ][ $priority ][ $index ]);
            $removed = true;
        }
    }

    if (empty($GLOBALS['mcpwp_test_filters'][ $tag ][ $priority ])) {
        unset($GLOBALS['mcpwp_test_filters'][ $tag ][ $priority ]);
    }

    return $removed;
}

function has_filter($tag, $callback = false)
{
    if (false === $callback) {
        return ! empty($GLOBALS['mcpwp_test_filters'][ $tag ]);
    }

    foreach ($GLOBALS['mcpwp_test_filters'][ $tag ] ?? array() as $priority => $callbacks) {
        foreach ($callbacks as $item) {
            if ($item['callback'] === $callback) {
                return (int) $priority;
            }
        }
    }

    return false;
}

/**
 * Count the callbacks registered on a filter — lets a test assert that a
 * scoped filter was actually removed again, not merely added.
 */
function mcpwp_test_filter_count($tag)
{
    $count = 0;
    foreach ($GLOBALS['mcpwp_test_filters'][ $tag ] ?? array() as $callbacks) {
        $count += count($callbacks);
    }
    return $count;
}

/**
 * Stand-in for wp_kses_allowed_html(). $GLOBALS['mcpwp_test_allowed_html'] is
 * the "core" allow-list; the filter runs over it exactly as in WordPress.
 */
function wp_kses_allowed_html($context = '')
{
    $tags = $GLOBALS['mcpwp_test_allowed_html'] ?? array();

    return apply_filters('wp_kses_allowed_html', $tags, $context);
}

function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['mcpwp_test_actions'][ $tag ][ (int) $priority ][] = array(
        'callback'      => $callback,
        'accepted_args' => (int) $accepted_args,
    );
    return true;
}

function do_action($tag)
{
    $args = func_get_args();
    array_shift($args);

    if (empty($GLOBALS['mcpwp_test_actions'][ $tag ])) {
        return;
    }

    $by_priority = $GLOBALS['mcpwp_test_actions'][ $tag ];
    ksort($by_priority);
    foreach ($by_priority as $callbacks) {
        foreach ($callbacks as $item) {
            $cb_args = array_slice($args, 0, $item['accepted_args']);
            call_user_func_array($item['callback'], $cb_args);
        }
    }
}

function current_time($type)
{
    if ('mysql' === $type) {
        return gmdate('Y-m-d H:i:s');
    }
    if ('c' === $type) {
        return gmdate('c');
    }
    return time();
}

function get_site_url()
{
    return isset($GLOBALS['mcpwp_test_site_url']) && '' !== $GLOBALS['mcpwp_test_site_url']
        ? $GLOBALS['mcpwp_test_site_url']
        : 'https://example.com';
}

if (! function_exists('wp_get_environment_type')) {
    function wp_get_environment_type()
    {
        $env = '';

        if (defined('WP_ENVIRONMENT_TYPE') && '' !== (string) constant('WP_ENVIRONMENT_TYPE')) {
            $env = (string) constant('WP_ENVIRONMENT_TYPE');
        } elseif (false !== getenv('WP_ENVIRONMENT_TYPE')) {
            $env = (string) getenv('WP_ENVIRONMENT_TYPE');
        }

        // Mirrors core: anything outside the known set falls back to production.
        return in_array($env, array('local', 'development', 'staging', 'production'), true)
            ? $env
            : 'production';
    }
}

if (! function_exists('home_url')) {
    function home_url($path = '', $scheme = null)
    {
        return 'https://example.com' . ('' !== (string) $path ? '/' . ltrim((string) $path, '/') : '');
    }
}

if (! function_exists('wp_sitemaps_get_server')) {
    // Real WP (5.5+) always defines this once core sitemaps are loaded; the
    // stub just needs to exist so feature-detection code takes the same
    // branch it would on a real site, with `wp_sitemaps_enabled` (a plain
    // filter, already supported) doing the actual on/off switching in tests.
    function wp_sitemaps_get_server()
    {
        return true;
    }
}

if (! function_exists('get_nav_menu_locations')) {
    function get_nav_menu_locations()
    {
        return array();
    }
}

if (! function_exists('get_the_terms')) {
    // Terms attached to an object, seeded per test in
    // $GLOBALS['_mcpwp_test_object_terms'][ $post_id ][ $taxonomy ]. Core
    // answers false when nothing is attached, and so does this.
    function get_the_terms($post, $taxonomy)
    {
        $id    = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
        $terms = $GLOBALS['_mcpwp_test_object_terms'][ $id ][ $taxonomy ] ?? array();
        return empty($terms) ? false : $terms;
    }
}

if (! function_exists('wp_list_pluck')) {
    function wp_list_pluck($list, $field, $index_key = null)
    {
        $out = array();
        foreach ((array) $list as $key => $item) {
            $value = is_object($item) ? ($item->{$field} ?? null) : ($item[$field] ?? null);
            if (null === $index_key) {
                $out[] = $value;
            } else {
                $idx       = is_object($item) ? ($item->{$index_key} ?? null) : ($item[$index_key] ?? null);
                $out[$idx] = $value;
            }
        }
        return $out;
    }
}

function rest_url($path = '')
{
    return 'https://example.com/wp-json/' . ltrim($path, '/');
}

function get_bloginfo($show = '')
{
    if ('version' === $show) {
        return '6.9.4';
    }
    if ('name' === $show) {
        // Overridable so tests can exercise #903 (entity-encoded blogname,
        // e.g. "Crumb &amp; Co") without a real WordPress option store.
        return $GLOBALS['mcpwp_test_bloginfo_name'] ?? 'Example Site';
    }
    if ('description' === $show) {
        return $GLOBALS['mcpwp_test_bloginfo_description'] ?? '';
    }
    return '';
}

function is_multisite()
{
    return ! empty($GLOBALS['mcpwp_test_is_multisite']);
}

// Site graph (#943 S1 review round 2, W2/W3): minimal multisite double —
// switch_to_blog()/restore_current_blog() actually move $wpdb->prefix (main
// site 'wp_', blog N 'wp_N_', matching real WordPress), so a test can assert
// on the generated SQL which table a write actually targeted.
if (! function_exists('get_current_blog_id')) {
    function get_current_blog_id()
    {
        return (int) ($GLOBALS['mcpwp_test_current_blog_id'] ?? 1);
    }
}

if (! function_exists('mcpwp_test_prefix_for_blog')) {
    function mcpwp_test_prefix_for_blog($blog_id)
    {
        $blog_id = (int) $blog_id;
        return 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
    }
}

if (! function_exists('switch_to_blog')) {
    function switch_to_blog($blog_id)
    {
        if (! isset($GLOBALS['mcpwp_test_blog_stack'])) {
            $GLOBALS['mcpwp_test_blog_stack'] = array();
        }
        $GLOBALS['mcpwp_test_blog_stack'][] = get_current_blog_id();
        $GLOBALS['mcpwp_test_current_blog_id'] = (int) $blog_id;
        $GLOBALS['wpdb']->prefix = mcpwp_test_prefix_for_blog($blog_id);
        return true;
    }
}

if (! function_exists('restore_current_blog')) {
    function restore_current_blog()
    {
        if (! isset($GLOBALS['mcpwp_test_blog_stack'])) {
            $GLOBALS['mcpwp_test_blog_stack'] = array();
        }
        $prev = array_pop($GLOBALS['mcpwp_test_blog_stack']);
        $GLOBALS['mcpwp_test_current_blog_id'] = null === $prev ? 1 : (int) $prev;
        $GLOBALS['wpdb']->prefix = mcpwp_test_prefix_for_blog($GLOBALS['mcpwp_test_current_blog_id']);
        return true;
    }
}

if (! function_exists('get_sites')) {
    function get_sites($args = array())
    {
        $ids = $GLOBALS['mcpwp_test_sites'] ?? array(1);
        if (isset($args['fields']) && 'ids' === $args['fields']) {
            return array_values(array_map('intval', $ids));
        }
        return array_map(function ($id) {
            return (object) array('blog_id' => $id);
        }, $ids);
    }
}

if (! function_exists('is_super_admin')) {
    // Core grants unfiltered_html to super admins only on multisite, so the
    // Custom Code refusal in #873 turns on this answer. Default false: the
    // interesting case is the ordinary site admin who does NOT have it.
    function is_super_admin($user_id = null)
    {
        return ! empty($GLOBALS['mcpwp_test_is_super_admin']);
    }
}

if (! function_exists('get_site_option')) {
    function get_site_option($name, $default = false)
    {
        return $GLOBALS['mcpwp_test_site_options'][ $name ] ?? $default;
    }
}

function wp_generate_uuid4()
{
    return '00000000-0000-4000-8000-' . str_pad((string) mt_rand(1, 999999999999), 12, '0', STR_PAD_LEFT);
}

function wp_hash_password($password)
{
    return password_hash((string) $password, PASSWORD_BCRYPT);
}

function wp_check_password($password, $hash)
{
    $hash = (string) $hash;
    if (0 === strpos($hash, '$2') || 0 === strpos($hash, '$argon')) {
        return password_verify((string) $password, $hash);
    }
    return hash_equals($hash, (string) $password);
}

function wp_check_invalid_utf8($text, $strip = false)
{
    $text = (string) $text;
    if ('' === $text) {
        return '';
    }
    return mb_check_encoding($text, 'UTF-8') ? $text : '';
}

function wp_json_encode($value, $flags = 0)
{
    // Core: encode; on failure sanitise every string and retry once.
    $encoded = json_encode($value, $flags);
    if (false !== $encoded) {
        return $encoded;
    }
    $sanitise = function ($v) use (&$sanitise) {
        if (is_string($v)) {
            return wp_check_invalid_utf8($v);
        }
        if (is_array($v)) {
            foreach ($v as $k => $item) {
                $v[ $k ] = $sanitise($item);
            }
        }
        return $v;
    };
    return json_encode($sanitise($value), $flags);
}

function wp_parse_args($args, $defaults = array())
{
    $args = is_array($args) ? $args : array();
    return array_merge($defaults, $args);
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function register_rest_route($namespace = '', $route = '', $args = array())
{
    if (! isset($GLOBALS['_mcpwp_test_registered_routes'])) {
        $GLOBALS['_mcpwp_test_registered_routes'] = array();
    }

    $GLOBALS['_mcpwp_test_registered_routes'][] = array(
        'namespace' => $namespace,
        'route'     => $route,
        'args'      => $args,
    );

    return true;
}

function rest_do_request($request = null)
{
    // Core's order in WP_REST_Server::respond_to_request(): match the route,
    // run the permission callback, then the rest_dispatch_request filter, then
    // the handler. A test can emulate the first two:
    //  - mcpwp_test_route_permission_denied: regexes of routes whose permission
    //    callback refuses (403) — nothing after it runs;
    //  - mcpwp_test_route_patterns: regex => the registered pattern core matched,
    //    handed to the filter as $route (default: the requested route).
    if (is_object($request) && method_exists($request, 'get_route')) {
        foreach ($GLOBALS['mcpwp_test_route_permission_denied'] ?? array() as $denied) {
            if (preg_match($denied, $request->get_route())) {
                $GLOBALS['mcpwp_test_denied_routes'][] = $request->get_route();
                return new WP_REST_Response(
                    array( 'code' => 'rest_forbidden', 'message' => 'Sorry, you are not allowed to do that.', 'data' => array( 'status' => 403 ) ),
                    403
                );
            }
        }
    }
    // A non-null filter value replaces the handler's answer, and a WP_Error
    // becomes the response core builds from it (error_to_response). A refused
    // route is recorded separately — its callback never ran, so it does not
    // count as dispatched.
    if (is_object($request) && method_exists($request, 'get_route') && ! empty($GLOBALS['mcpwp_test_filters']['rest_dispatch_request'])) {
        $matched = $request->get_route();
        foreach ($GLOBALS['mcpwp_test_route_patterns'] ?? array() as $regex => $pattern) {
            if (preg_match($regex, $request->get_route())) {
                $matched = $pattern;
                break;
            }
        }
        $pre = apply_filters('rest_dispatch_request', null, $request, $matched, array());
        if ($pre instanceof WP_Error) {
            $GLOBALS['mcpwp_test_refused_routes'][] = $request->get_route();
            $error_data = $pre->get_error_data();
            $status     = is_array($error_data) && isset($error_data['status']) ? (int) $error_data['status'] : 500;
            return new WP_REST_Response(
                array(
                    'code'    => $pre->get_error_code(),
                    'message' => $pre->get_error_message(),
                    'data'    => $error_data,
                ),
                $status
            );
        }
        if (null !== $pre) {
            $GLOBALS['mcpwp_test_dispatched_routes'][]   = $request->get_route();
            $GLOBALS['mcpwp_test_dispatched_requests'][] = $request;
            return $pre instanceof WP_REST_Response ? $pre : new WP_REST_Response($pre, 200);
        }
    }
    if (is_object($request) && method_exists($request, 'get_route')) {
        $GLOBALS['mcpwp_test_dispatched_routes'][]   = $request->get_route();
        $GLOBALS['mcpwp_test_dispatched_requests'][] = $request;
    }
    // A test may script the body the dispatched route answers with, e.g. a
    // 200 that carries its own errors[] (a partial success).
    if (isset($GLOBALS['mcpwp_test_rest_response_override'])) {
        return new WP_REST_Response($GLOBALS['mcpwp_test_rest_response_override'], 200);
    }
    return new WP_REST_Response(array( 'ok' => true ), 200);
}

function rest_sanitize_boolean($value)
{
    if (is_string($value)) {
        $value = strtolower($value);
        if (in_array($value, array( 'false', '0', '' ), true)) {
            return false;
        }
    }
    return (bool) $value;
}

function map_deep($value, $callback)
{
    if (is_array($value)) {
        foreach ($value as $index => $item) {
            $value[ $index ] = map_deep($item, $callback);
        }
    } elseif (is_object($value)) {
        $object_vars = get_object_vars($value);
        foreach ($object_vars as $property_name => $property_value) {
            $value->$property_name = map_deep($property_value, $callback);
        }
    } else {
        $value = call_user_func($callback, $value);
    }
    return $value;
}

// ── Minimal $wpdb double ─────────────────────────────────────────────────
//
// Most tests never touch $wpdb (the code paths that would are guarded), so it
// was previously left null. Once ELEMENTOR_VERSION is defined in-process (any
// Elementor test), Mcpwp_Signals::compute_broken_elementor() stops short-
// circuiting and runs a real query — so a benign $wpdb that returns no rows
// keeps that path (and SignalsTest) working regardless of load order.
// Site graph (#943 S1): parse a $wpdb->prepare()-produced VALUES(...) tuple
// into raw tokens, respecting quoted strings (this double's own prepare()
// escapes an embedded quote as \', matching the query() handlers above) so a
// comma inside a JSON props value never splits a token.
if (! function_exists('mcpwp_test_split_sql_tuple')) {
    function mcpwp_test_split_sql_tuple($tuple)
    {
        $tokens   = array();
        $current  = '';
        $in_quote = false;
        $len      = strlen($tuple);

        for ($i = 0; $i < $len; $i++) {
            $ch = $tuple[ $i ];

            if ($in_quote) {
                if ('\\' === $ch && $i + 1 < $len) {
                    $current .= $tuple[ ++$i ];
                    continue;
                }
                if ("'" === $ch) {
                    $in_quote = false;
                    continue;
                }
                $current .= $ch;
                continue;
            }

            if ("'" === $ch) {
                $in_quote = true;
                continue;
            }
            if (',' === $ch) {
                $tokens[] = trim($current);
                $current  = '';
                continue;
            }
            $current .= $ch;
        }
        $tokens[] = trim($current);

        return $tokens;
    }
}

// Site graph (#943 S1 review, BLOCK-4): split a multi-row `VALUES
// (t1),(t2),(t3)` blob into each tuple's inner content (still comma-joined —
// pass each result through mcpwp_test_split_sql_tuple() for the field
// values), respecting quoted strings so a comma or paren inside a JSON props
// value never miscounts a tuple boundary.
if (! function_exists('mcpwp_test_split_value_tuples')) {
    function mcpwp_test_split_value_tuples($blob)
    {
        $tuples   = array();
        $depth    = 0;
        $current  = '';
        $in_quote = false;
        $len      = strlen($blob);

        for ($i = 0; $i < $len; $i++) {
            $ch = $blob[ $i ];

            if ($in_quote) {
                $current .= $ch;
                if ('\\' === $ch && $i + 1 < $len) {
                    $current .= $blob[ ++$i ];
                    continue;
                }
                if ("'" === $ch) {
                    $in_quote = false;
                }
                continue;
            }

            if ("'" === $ch) {
                $in_quote = true;
                $current .= $ch;
                continue;
            }
            if ('(' === $ch) {
                $depth++;
                if (1 === $depth) {
                    $current = '';
                    continue;
                }
                $current .= $ch;
                continue;
            }
            if (')' === $ch) {
                $depth--;
                if (0 === $depth) {
                    $tuples[] = $current;
                    $current  = '';
                    continue;
                }
                $current .= $ch;
                continue;
            }
            if (0 === $depth) {
                continue; // comma (or whitespace) between tuples
            }
            $current .= $ch;
        }

        return $tuples;
    }
}

if (! defined('MCPWP_TEST_WPDB_PASS')) {
    define('MCPWP_TEST_WPDB_PASS', '__mcpwp_test_wpdb_pass__');
}

if (! isset($GLOBALS['wpdb'])) {
    class Mcpwp_Test_WPDB
    {
        public $prefix   = 'wp_';
        public $posts    = 'wp_posts';
        public $postmeta = 'wp_postmeta';
        public $options  = 'wp_options';
        public $users    = 'wp_users';
        public $usermeta = 'wp_usermeta';
        public $last_error = '';
        public $insert_id  = 0;
        /** Set while query() evaluates a byte-exact HEX(option_value) compare-and-swap. */
        public $exact_option_value_compare = false;

        public function prepare($query, ...$args)
        {
            // Substitute, like the real one. Returning the template verbatim
            // meant every query built through prepare() still carried %d/%s
            // when it reached get_var() below, so a double that wanted to
            // answer a query could not recognise one — and, more importantly,
            // any test asserting on generated SQL was asserting on a string
            // the search term never entered.
            if (empty($args)) {
                return $query;
            }

            if (isset($args[0]) && is_array($args[0]) && 1 === count($args)) {
                $args = $args[0];
            }

            return preg_replace_callback(
                '/%[dfs]/',
                function ($m) use (&$args) {
                    if (! $args) {
                        return $m[0];
                    }
                    $value = array_shift($args);
                    if ('%d' === $m[0]) {
                        return (string) (int) $value;
                    }
                    if ('%f' === $m[0]) {
                        return (string) (float) $value;
                    }
                    return "'" . str_replace("'", "\\'", (string) $value) . "'";
                },
                $query
            );
        }
        public function get_results($query = '', $output = ARRAY_A)
        {
            // Test-scoped interceptor (#943 S2 graph read surface): a test may
            // install a callable that sees every query and either answers it
            // (any value other than MCPWP_TEST_WPDB_PASS) or lets it fall
            // through to this double's own handling.
            if (isset($GLOBALS['mcpwp_test_wpdb_intercept']) && is_callable($GLOBALS['mcpwp_test_wpdb_intercept'])) {
                $answer = call_user_func($GLOBALS['mcpwp_test_wpdb_intercept'], 'get_results', (string) $query, $output);
                if (MCPWP_TEST_WPDB_PASS !== $answer) {
                    return $answer;
                }
            }
            // #1030 round-5: Mcpwp_Api_Auth::fetch_post_ref_rows() — the
            // declared post-reference guard's one direct lookup. Answered
            // from the same in-memory post table get_post() reads, with no
            // type or status filtering (that is the point of the query).
            if (preg_match(
                '/^SELECT ID, post_type, post_status FROM \\S*posts WHERE ID IN \\(([0-9,\\s]+)\\)$/',
                trim((string) $query),
                $m
            )) {
                $GLOBALS['mcpwp_test_post_ref_queries'][] = trim((string) $query);
                $rows = array();
                foreach (array_map('intval', explode(',', $m[1])) as $ref_id) {
                    $ref_post = $GLOBALS['_mcpwp_test_posts'][ $ref_id ] ?? null;
                    if (! is_object($ref_post)) {
                        continue;
                    }
                    $rows[] = array(
                        'ID'          => (string) $ref_id,
                        'post_type'   => (string) ($ref_post->post_type ?? 'post'),
                        'post_status' => (string) ($ref_post->post_status ?? 'publish'),
                    );
                }
                return $rows;
            }
            // Site graph batched link resolver (#943 S1 review, BLOCK-4
            // perf / BLOCK-2 correctness): resolve_hrefs_for_post()'s ONE
            // query for every ?p=/?page_id= id and every same-host path's
            // last-segment slug on a post, instead of one query per link.
            // post_parent is fetched too (BLOCK-2 round 2) even though this
            // double does not itself use it — get_page_by_path() (a separate
            // stub) does the actual parent-chain verification.
            if (preg_match(
                "/^SELECT ID, post_name, post_type, post_parent FROM \\S*posts WHERE \\((.*)\\) AND post_status != 'auto-draft'$/s",
                trim((string) $query),
                $m
            )) {
                $cond = $m[1];
                $ids  = array();
                $slugs = array();
                if (preg_match('/ID IN \(([^)]*)\)/', $cond, $mi)) {
                    $ids = array_map('intval', array_filter(array_map('trim', explode(',', $mi[1]))));
                }
                if (preg_match("/post_name IN \\(((?:'(?:[^'\\\\]|\\\\.)*'(?:,)?)*)\\)/", $cond, $ms)) {
                    $slugs = array_map(function ($s) {
                        return str_replace("\\'", "'", trim($s, "'"));
                    }, array_filter(mcpwp_test_split_sql_tuple($ms[1])));
                }

                $rows = array();
                foreach ($GLOBALS['_mcpwp_test_posts'] as $post) {
                    if ('auto-draft' === $post->post_status) {
                        continue;
                    }
                    if (in_array((int) $post->ID, $ids, true) || in_array($post->post_name, $slugs, true)) {
                        $row = array(
                            'ID'          => (string) $post->ID,
                            'post_name'   => $post->post_name,
                            'post_type'   => $post->post_type,
                            'post_parent' => (string) $post->post_parent,
                        );
                        $rows[] = ARRAY_A === $output ? $row : (object) $row;
                    }
                }
                return $rows;
            }

            // Site graph queue drain (#943 S1 review round 2, N4a):
            // pop_queue_batch()'s SELECT now also reads `reason`, so a
            // second failure can be told from a fresh one.
            if (preg_match('/^SELECT node_key, reason FROM \S*mcpwp_graph_queue ORDER BY queued_at ASC LIMIT (\d+)$/', trim((string) $query), $m)) {
                $rows = $GLOBALS['mcpwp_test_graph_queue'] ?? array();
                uasort($rows, function ($a, $b) {
                    return strcmp((string) $a['queued_at'], (string) $b['queued_at']);
                });
                $rows = array_slice($rows, 0, (int) $m[1], true);
                $out  = array();
                foreach ($rows as $node_key => $row) {
                    $one = array('node_key' => $node_key, 'reason' => $row['reason']);
                    $out[] = ARRAY_A === $output ? $one : (object) $one;
                }
                return $out;
            }

            return array();
        }
        public function get_row($query = '', $output = OBJECT, $y = 0)
        {
            // Test-scoped interceptor (#943 S2 graph read surface): a test may
            // install a callable that sees every query and either answers it
            // (any value other than MCPWP_TEST_WPDB_PASS) or lets it fall
            // through to this double's own handling.
            if (isset($GLOBALS['mcpwp_test_wpdb_intercept']) && is_callable($GLOBALS['mcpwp_test_wpdb_intercept'])) {
                $answer = call_user_func($GLOBALS['mcpwp_test_wpdb_intercept'], 'get_row', (string) $query, $output);
                if (MCPWP_TEST_WPDB_PASS !== $answer) {
                    return $answer;
                }
            }
            // Site graph (#943 S1): the hash-skip check that decides whether
            // rebuild_post_node() has anything to do.
            if (preg_match("/^SELECT content_hash, props FROM \\S*mcpwp_graph_nodes WHERE node_key = '((?:[^'\\\\]|\\\\.)*)'$/", trim((string) $query), $m)) {
                $key = str_replace("\\'", "'", $m[1]);
                if (! isset($GLOBALS['mcpwp_test_graph_nodes'][ $key ])) {
                    return null;
                }
                $row = array(
                    'content_hash' => $GLOBALS['mcpwp_test_graph_nodes'][ $key ]['content_hash'],
                    'props'        => $GLOBALS['mcpwp_test_graph_nodes'][ $key ]['props'],
                );
                return ARRAY_A === $output ? $row : (object) $row;
            }

            // The direct option-row probe (Mcpwp_Encryption::read_option_row,
            // #564). A select with no FROM always yields exactly one row when
            // the query ran, so the real wpdb answers null here only when the
            // read failed — never for "no such option". The double keeps that
            // contract and lets a test fail the read two ways:
            //   true     — null plus last_error, like a lost connection mid-query;
            //   'silent' — null with an empty last_error, which is what the real
            //              wpdb returns when a reconnect fails after
            //              template_redirect (check_connection() returns false
            //              without recording an error).
            // A callable receives the option name and answers true/'silent'/false.
            if (preg_match("/^SELECT 1 AS mcpwp_probe, \\(SELECT option_value FROM \\S*options WHERE option_name = '([^']+)'(?: ORDER BY option_id ASC)? LIMIT 1\\) AS option_value$/", trim((string) $query), $m)) {
                $this->last_error = '';
                $fails            = $GLOBALS['mcpwp_test_wpdb_read_fails'] ?? false;
                if (is_callable($fails)) {
                    $fails = $fails($m[1]);
                }
                if ('silent' === $fails) {
                    return null;
                }
                if (true === $fails) {
                    $this->last_error = 'WordPress database error: Lost connection to MySQL server during query';
                    return null;
                }
                // One table in real WordPress: a row written through the options
                // API (update_option() double) is visible to the probe too,
                // serialized as WordPress stores it. A raw row wins when both exist.
                if (array_key_exists($m[1], $GLOBALS['mcpwp_test_wpdb_options'] ?? array())) {
                    $value = $GLOBALS['mcpwp_test_wpdb_options'][ $m[1] ];
                } elseif (array_key_exists($m[1], $GLOBALS['mcpwp_test_options'] ?? array())) {
                    $value = maybe_serialize($GLOBALS['mcpwp_test_options'][ $m[1] ]);
                } else {
                    $value = null;
                }
                $row = array(
                    'mcpwp_probe'  => '1',
                    'option_value' => $value,
                );
                return ARRAY_A === $output ? $row : (object) $row;
            }
            return null;
        }
        public function get_var($query = '', $x = 0, $y = 0)
        {
            // Test-scoped interceptor (#943 S2 graph read surface): a test may
            // install a callable that sees every query and either answers it
            // (any value other than MCPWP_TEST_WPDB_PASS) or lets it fall
            // through to this double's own handling.
            if (isset($GLOBALS['mcpwp_test_wpdb_intercept']) && is_callable($GLOBALS['mcpwp_test_wpdb_intercept'])) {
                $answer = call_user_func($GLOBALS['mcpwp_test_wpdb_intercept'], 'get_var', (string) $query, null);
                if (MCPWP_TEST_WPDB_PASS !== $answer) {
                    return $answer;
                }
            }
            // MySQL named locks (#981 review round 2, Mcpwp_Approvals::mutate()).
            // GET_LOCK answers $GLOBALS['mcpwp_test_get_lock_result'] (default
            // '1'); a test may run a one-shot hook the moment the lock is
            // granted — i.e. a writer that committed just before we got it.
            if (preg_match('/^SELECT GET_LOCK\(/', trim((string) $query))) {
                $GLOBALS['mcpwp_test_named_lock_calls'][] = 'get';
                $result = $GLOBALS['mcpwp_test_get_lock_result'] ?? '1';
                // A per-call sequence wins when set: e.g. array('1','0','0','0')
                // grants the first lock and times out the next three.
                if (! empty($GLOBALS['mcpwp_test_get_lock_results']) && is_array($GLOBALS['mcpwp_test_get_lock_results'])) {
                    $result = array_shift($GLOBALS['mcpwp_test_get_lock_results']);
                }
                if ('1' === (string) $result && isset($GLOBALS['mcpwp_test_on_lock_granted']) && is_callable($GLOBALS['mcpwp_test_on_lock_granted'])) {
                    $hook = $GLOBALS['mcpwp_test_on_lock_granted'];
                    $GLOBALS['mcpwp_test_on_lock_granted'] = null;
                    $hook();
                }
                return $result;
            }
            if ('SELECT @@max_allowed_packet' === trim((string) $query)) {
                return $GLOBALS['mcpwp_test_max_allowed_packet'] ?? null;
            }
            if (preg_match('/^SELECT RELEASE_LOCK\(/', trim((string) $query))) {
                $GLOBALS['mcpwp_test_named_lock_calls'][] = 'release';
                return '1';
            }
            // Site graph (#943 S1 review): read_node_hash() — the single
            // change-hash comparison BLOCK-1 collapsed the old two-field
            // check into.
            if (preg_match("/^SELECT content_hash FROM \\S*mcpwp_graph_nodes WHERE node_key = '((?:[^'\\\\]|\\\\.)*)'$/", trim((string) $query), $m)) {
                $key = str_replace("\\'", "'", $m[1]);
                return isset($GLOBALS['mcpwp_test_graph_nodes'][ $key ]) ? $GLOBALS['mcpwp_test_graph_nodes'][ $key ]['content_hash'] : null;
            }

            // queue_has_rows() — plain, unprepared (no placeholders to substitute).
            if (preg_match('/^SELECT COUNT\(\*\) FROM \S*mcpwp_graph_queue LIMIT 1$/', trim((string) $query))) {
                return (string) count($GLOBALS['mcpwp_test_graph_queue'] ?? array());
            }

            if (preg_match("/SELECT option_value FROM \\S*options WHERE option_name = '([^']+)'/", (string) $query, $m)) {
                return $GLOBALS['mcpwp_test_wpdb_options'][ $m[1] ] ?? null;
            }
            // Answer the one query the Elementor writer depends on.
            //
            // set_elementor_data() verifies its own write by reading the raw
            // postmeta row through $wpdb rather than get_post_meta(), on
            // purpose (#612: a persistent object cache can report a value the
            // DB never committed). A double that answers null for that read
            // makes the writer conclude 0 sections were stored and return
            // meta_write_truncated, so the whole method was untestable and
            // therefore untested — which is how a builder-flag defect reached
            // the busiest write tool in the plugin (#785).
            //
            // Deliberately narrow: only this exact shape is served from the
            // meta store, and everything else still answers null as before.
            if (preg_match(
                "/SELECT meta_value FROM \\S*postmeta WHERE post_id = (\\d+) AND meta_key = '([^']+)'/",
                (string) $query,
                $m
            )) {
                $value = $GLOBALS['_mcpwp_test_meta'][ (int) $m[1] ][ $m[2] ] ?? null;

                return is_scalar($value) ? (string) $value : $value;
            }

            return null;
        }
        public function get_col($query = '', $x = 0)
        {
            // Test-scoped interceptor (#943 S2 graph read surface): a test may
            // install a callable that sees every query and either answers it
            // (any value other than MCPWP_TEST_WPDB_PASS) or lets it fall
            // through to this double's own handling.
            if (isset($GLOBALS['mcpwp_test_wpdb_intercept']) && is_callable($GLOBALS['mcpwp_test_wpdb_intercept'])) {
                $answer = call_user_func($GLOBALS['mcpwp_test_wpdb_intercept'], 'get_col', (string) $query, null);
                if (MCPWP_TEST_WPDB_PASS !== $answer) {
                    return $answer;
                }
            }

            // requeue_dependents_before_delete() (BLOCK-2): every source of
            // an inbound links_to edge, and every parent_of child.
            if (preg_match("/^SELECT DISTINCT from_key FROM \\S*mcpwp_graph_edges WHERE to_key = '((?:[^'\\\\]|\\\\.)*)' AND type = 'links_to'$/", trim((string) $query), $m)) {
                $key = str_replace("\\'", "'", $m[1]);
                $out = array();
                foreach ($GLOBALS['mcpwp_test_graph_edges'] ?? array() as $e) {
                    if ($e['to_key'] === $key && 'links_to' === $e['type']) {
                        $out[ $e['from_key'] ] = true;
                    }
                }
                return array_keys($out);
            }
            if (preg_match("/^SELECT DISTINCT to_key FROM \\S*mcpwp_graph_edges WHERE from_key = '((?:[^'\\\\]|\\\\.)*)' AND type = 'parent_of'$/", trim((string) $query), $m)) {
                $key = str_replace("\\'", "'", $m[1]);
                $out = array();
                foreach ($GLOBALS['mcpwp_test_graph_edges'] ?? array() as $e) {
                    if ($e['from_key'] === $key && 'parent_of' === $e['type']) {
                        $out[ $e['to_key'] ] = true;
                    }
                }
                return array_keys($out);
            }

            // Reconcile (W8): every covered post modified after built_at.
            if (preg_match(
                "/^SELECT ID FROM \\S*posts WHERE post_type IN \\(((?:'[^']*'(?:,'[^']*')*)?)\\) AND post_status != 'auto-draft' AND post_modified_gmt > '([^']*)'$/",
                trim((string) $query),
                $m
            )) {
                $types = array_map(function ($t) {
                    return trim($t, "'");
                }, array_filter(explode(',', $m[1])));
                $since = $m[2];

                $ids = array();
                foreach ($GLOBALS['_mcpwp_test_posts'] as $post) {
                    if (in_array($post->post_type, $types, true) && 'auto-draft' !== $post->post_status && (string) ($post->post_modified_gmt ?? '') > $since) {
                        $ids[] = (int) $post->ID;
                    }
                }
                sort($ids);
                return $ids;
            }

            // Site graph backfill batch (#943 S1): Mcpwp_Graph_Builder::
            // fetch_backfill_batch_ids() — same keyset-cursor idea as the
            // single-post-type scan below, extended to an IN() list of types.
            if (preg_match(
                "/^SELECT ID FROM \\S*posts WHERE post_type IN \\(((?:'[^']*'(?:,'[^']*')*)?)\\) AND post_status != 'auto-draft' AND ID > (\\d+) ORDER BY ID ASC LIMIT (\\d+)$/",
                trim((string) $query),
                $m
            )) {
                $types  = array_map(function ($t) {
                    return trim($t, "'");
                }, array_filter(explode(',', $m[1])));
                $cursor = (int) $m[2];
                $limit  = (int) $m[3];

                $ids = array();
                foreach ($GLOBALS['_mcpwp_test_posts'] as $post) {
                    if (in_array($post->post_type, $types, true) && 'auto-draft' !== $post->post_status && (int) $post->ID > $cursor) {
                        $ids[] = (int) $post->ID;
                    }
                }
                sort($ids);

                return array_slice($ids, 0, $limit);
            }

            // Keyset-cursor scan used by
            // Mcpwp_Activator::repair_custom_code_locations() (#875 W3) —
            // get_posts()/WP_Query has no "ID > cursor" primitive, so that
            // method queries $wpdb directly. Matches the exact shape its
            // $wpdb->prepare() call produces; anything else still answers no
            // rows, same as before this was added.
            if (preg_match(
                "/^SELECT ID FROM \\S*posts WHERE post_type = '([^']*)' AND ID > (\\d+) ORDER BY ID ASC LIMIT (\\d+)$/",
                trim((string) $query),
                $m
            )) {
                $type   = $m[1];
                $cursor = (int) $m[2];
                $limit  = (int) $m[3];

                $ids = array();
                foreach ($GLOBALS['_mcpwp_test_posts'] as $post) {
                    if ($post->post_type === $type && (int) $post->ID > $cursor) {
                        $ids[] = (int) $post->ID;
                    }
                }
                sort($ids);

                return array_slice($ids, 0, $limit);
            }

            return array();
        }
        public $suppressed = false;
        public $queries    = array();
        public function suppress_errors($suppress = true)
        {
            $prev = $this->suppressed;
            $this->suppressed = (bool) $suppress;
            return $prev;
        }
        public function query($query)
        {
            $this->queries[] = (string) $query;
            $trimmed         = trim((string) $query);

            // ---------------------------------------------------------------
            // Site graph (#943 S1): Mcpwp_Graph_Builder / Mcpwp_Graph_Signals
            // write through raw $wpdb->query() (INSERT ... ON DUPLICATE KEY
            // UPDATE / DELETE), never $wpdb->insert()/update(), because the
            // upsert needs ON DUPLICATE KEY UPDATE. Three tiny in-memory
            // tables back these patterns; mcpwp_test_split_sql_tuple() below
            // parses each VALUES(...) tuple positionally (the column order is
            // exactly what each INSERT names, so no column-aware mapping is
            // needed here).
            // ---------------------------------------------------------------

            if (preg_match('/^INSERT INTO (\S*mcpwp_graph_nodes) \(node_key, kind, ref_id, label, url, status, content_hash, word_count, props, updated_at\)\s*VALUES\s*\((.*)\)\s*ON DUPLICATE KEY UPDATE/s', $trimmed, $m)) {
                $vals = mcpwp_test_split_sql_tuple($m[2]);
                $GLOBALS['mcpwp_test_graph_nodes'][ $vals[0] ] = array(
                    'node_key'     => $vals[0],
                    'kind'         => $vals[1],
                    'ref_id'       => (int) $vals[2],
                    'label'        => $vals[3],
                    'url'          => $vals[4],
                    'status'       => $vals[5],
                    'content_hash' => $vals[6],
                    'word_count'   => (int) $vals[7],
                    'props'        => $vals[8],
                    'updated_at'   => $vals[9],
                );
                return 1;
            }

            // Multi-row: Mcpwp_Graph_Builder::insert_edges_batch() (#943 S1
            // review, BLOCK-4 perf) issues ONE statement with N value
            // tuples, not one query per edge. Each tuple is split the same
            // quote-aware way as a single-row VALUES(...).
            if (preg_match('/^INSERT INTO (\S*mcpwp_graph_edges) \(from_key, to_key, type, weight, source, props, updated_at\)\s*VALUES\s*(\(.*\))\s*ON DUPLICATE KEY UPDATE/s', $trimmed, $m)) {
                if (! isset($GLOBALS['mcpwp_test_graph_edges'])) {
                    $GLOBALS['mcpwp_test_graph_edges'] = array();
                }
                foreach (mcpwp_test_split_value_tuples($m[2]) as $tuple) {
                    $vals = mcpwp_test_split_sql_tuple($tuple);
                    $row  = array(
                        'from_key'   => $vals[0],
                        'to_key'     => $vals[1],
                        'type'       => $vals[2],
                        'weight'     => (float) $vals[3],
                        'source'     => $vals[4],
                        'props'      => $vals[5],
                        'updated_at' => $vals[6],
                    );
                    $matched = false;
                    foreach ($GLOBALS['mcpwp_test_graph_edges'] as $i => $existing) {
                        if ($existing['from_key'] === $row['from_key'] && $existing['to_key'] === $row['to_key'] && $existing['type'] === $row['type']) {
                            $GLOBALS['mcpwp_test_graph_edges'][ $i ] = $row;
                            $matched                                 = true;
                            break;
                        }
                    }
                    if (! $matched) {
                        $GLOBALS['mcpwp_test_graph_edges'][] = $row;
                    }
                }
                return 1;
            }

            // Site graph queue table (#943 S1 review, BLOCK-4/W2/W5):
            // mark() -> flush_marks_to_queue() -> ONE multi-row INSERT
            // IGNORE for every mark() buffered during the request (#943 S1
            // review, BLOCK-4 perf: 400 individual single-row INSERTs
            // measured ~10ms each on the rig — batching this the same way
            // insert_edges_batch() already does was the actual fix). Real
            // MySQL semantics: each row is skipped silently when its primary
            // key (node_key) already exists — other rows in the same
            // statement are unaffected.
            if (preg_match("/^INSERT IGNORE INTO (\\S*mcpwp_graph_queue) \\(node_key, reason, queued_at\\) VALUES (\\(.*\\))$/s", $trimmed, $m)) {
                if (! isset($GLOBALS['mcpwp_test_graph_queue'])) {
                    $GLOBALS['mcpwp_test_graph_queue'] = array();
                }
                $inserted = 0;
                foreach (mcpwp_test_split_value_tuples($m[2]) as $tuple) {
                    $vals = mcpwp_test_split_sql_tuple($tuple);
                    $key  = $vals[0];
                    if (! isset($GLOBALS['mcpwp_test_graph_queue'][ $key ])) {
                        $GLOBALS['mcpwp_test_graph_queue'][ $key ] = array(
                            'reason'    => $vals[1],
                            'queued_at' => $vals[2],
                        );
                        $inserted++;
                    }
                }
                return $inserted;
            }

            if (preg_match("/^DELETE FROM (\\S*mcpwp_graph_queue) WHERE node_key IN \\((.*)\\)$/s", $trimmed, $m)) {
                $keys = mcpwp_test_split_sql_tuple($m[2]);
                foreach ($keys as $key) {
                    unset($GLOBALS['mcpwp_test_graph_queue'][ $key ]);
                }
                return count($keys);
            }

            // Trash status-only update (BLOCK-2): mark_node_trashed() — never
            // touches content_hash/edges, only the status column.
            if (preg_match("/^UPDATE (\\S*mcpwp_graph_nodes) SET status = 'trash', updated_at = '((?:[^'\\\\]|\\\\.)*)' WHERE node_key = '((?:[^'\\\\]|\\\\.)*)'$/", $trimmed, $m)) {
                $updated_at = str_replace("\\'", "'", $m[2]);
                $key        = str_replace("\\'", "'", $m[3]);
                if (! isset($GLOBALS['mcpwp_test_graph_nodes'][ $key ])) {
                    return 0;
                }
                $GLOBALS['mcpwp_test_graph_nodes'][ $key ]['status']     = 'trash';
                $GLOBALS['mcpwp_test_graph_nodes'][ $key ]['updated_at'] = $updated_at;
                return 1;
            }

            if (preg_match('/^INSERT INTO (\S*mcpwp_graph_signals) \(node_key, metric, value, period_start, period_end, source, fetched_at\)\s*VALUES\s*\((.*)\)\s*ON DUPLICATE KEY UPDATE/s', $trimmed, $m)) {
                $vals = mcpwp_test_split_sql_tuple($m[2]);
                $row  = array(
                    'node_key'     => $vals[0],
                    'metric'       => $vals[1],
                    'value'        => (float) $vals[2],
                    'period_start' => $vals[3],
                    'period_end'   => $vals[4],
                    'source'       => $vals[5],
                    'fetched_at'   => $vals[6],
                );
                if (! isset($GLOBALS['mcpwp_test_graph_signals'])) {
                    $GLOBALS['mcpwp_test_graph_signals'] = array();
                }
                $matched = false;
                foreach ($GLOBALS['mcpwp_test_graph_signals'] as $i => $existing) {
                    if ($existing['node_key'] === $row['node_key'] && $existing['metric'] === $row['metric'] && $existing['source'] === $row['source'] && $existing['period_start'] === $row['period_start'] && $existing['period_end'] === $row['period_end']) {
                        $GLOBALS['mcpwp_test_graph_signals'][ $i ] = $row;
                        $matched                                   = true;
                        break;
                    }
                }
                if (! $matched) {
                    $GLOBALS['mcpwp_test_graph_signals'][] = $row;
                }
                return 1;
            }

            if (preg_match("/^DELETE FROM \\S*mcpwp_graph_nodes WHERE node_key = '((?:[^'\\\\]|\\\\.)*)'$/", $trimmed, $m)) {
                unset($GLOBALS['mcpwp_test_graph_nodes'][ str_replace("\\'", "'", $m[1]) ]);
                return 1;
            }

            if (preg_match("/^DELETE FROM \\S*mcpwp_graph_edges WHERE from_key = '((?:[^'\\\\]|\\\\.)*)' AND source = 'builder' AND type IN \\('links_to','in_term','uses_media'\\)$/", $trimmed, $m)) {
                $key = str_replace("\\'", "'", $m[1]);
                $GLOBALS['mcpwp_test_graph_edges'] = array_values(array_filter($GLOBALS['mcpwp_test_graph_edges'] ?? array(), function ($e) use ($key) {
                    return ! ($e['from_key'] === $key && 'builder' === $e['source'] && in_array($e['type'], array( 'links_to', 'in_term', 'uses_media' ), true));
                }));
                return 1;
            }

            if (preg_match("/^DELETE FROM \\S*mcpwp_graph_edges WHERE to_key = '((?:[^'\\\\]|\\\\.)*)' AND source = 'builder' AND type = 'parent_of'$/", $trimmed, $m)) {
                $key = str_replace("\\'", "'", $m[1]);
                $GLOBALS['mcpwp_test_graph_edges'] = array_values(array_filter($GLOBALS['mcpwp_test_graph_edges'] ?? array(), function ($e) use ($key) {
                    return ! ($e['to_key'] === $key && 'builder' === $e['source'] && 'parent_of' === $e['type']);
                }));
                return 1;
            }

            if (preg_match("/^DELETE FROM \\S*mcpwp_graph_edges WHERE from_key = '((?:[^'\\\\]|\\\\.)*)' OR to_key = '((?:[^'\\\\]|\\\\.)*)'$/", $trimmed, $m)) {
                $a = str_replace("\\'", "'", $m[1]);
                $b = str_replace("\\'", "'", $m[2]);
                $GLOBALS['mcpwp_test_graph_edges'] = array_values(array_filter($GLOBALS['mcpwp_test_graph_edges'] ?? array(), function ($e) use ($a, $b) {
                    return ! ($e['from_key'] === $a || $e['to_key'] === $b);
                }));
                return 1;
            }

            if (preg_match("/^DELETE FROM \\S*mcpwp_graph_signals WHERE period_end < '([0-9\\-]+)'$/", $trimmed, $m)) {
                $cutoff = $m[1];
                $before = count($GLOBALS['mcpwp_test_graph_signals'] ?? array());
                $GLOBALS['mcpwp_test_graph_signals'] = array_values(array_filter($GLOBALS['mcpwp_test_graph_signals'] ?? array(), function ($s) use ($cutoff) {
                    return ! ($s['period_end'] < $cutoff);
                }));
                return $before - count($GLOBALS['mcpwp_test_graph_signals']);
            }

            // The one statement the claim store issues: the lease sweep. It
            // is evaluated against the options rows so a test can assert
            // WHICH rows survive, not only what the SQL looked like.
            if (preg_match("/^DELETE FROM \\S*options WHERE option_name LIKE '([^']*)' AND option_value < '([^']*)'$/", trim((string) $query), $m)) {
                // LIKE → regex, as MySQL reads it with the default ESCAPE '\':
                // an escaped \% or \_ is literal; a bare % is any run and a
                // bare _ is any one character.
                $like    = $m[1];
                $pattern = '';
                for ($i = 0, $n = strlen($like); $i < $n; $i++) {
                    $ch = $like[ $i ];
                    if ('\\' === $ch && $i + 1 < $n) {
                        $pattern .= preg_quote($like[ ++$i ], '/');
                    } elseif ('%' === $ch) {
                        $pattern .= '.*';
                    } elseif ('_' === $ch) {
                        $pattern .= '.';
                    } else {
                        $pattern .= preg_quote($ch, '/');
                    }
                }
                $pattern = '/^' . $pattern . '$/';
                $deleted = 0;
                foreach (array_keys($GLOBALS['mcpwp_test_wpdb_options'] ?? array()) as $name) {
                    if (preg_match($pattern, $name) && strcmp((string) $GLOBALS['mcpwp_test_wpdb_options'][ $name ], $m[2]) < 0) {
                        unset($GLOBALS['mcpwp_test_wpdb_options'][ $name ]);
                        $deleted++;
                    }
                }
                return $deleted;
            }

            // The byte-exact compare-and-swap on an options row (#845 review):
            // HEX(option_value) is immune to the column's case-insensitive,
            // pad-space collation. Evaluated through update(), so every
            // concurrency hook a test arms still applies, with an exact compare.
            if (preg_match("/^UPDATE (\\S*options) SET option_value = '((?:[^'\\\\]|\\\\.)*)' WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' AND HEX\\(option_value\\) = '([0-9A-Fa-f]*)'$/s", trim((string) $query), $m)) {
                $this->exact_option_value_compare = true;
                try {
                    return $this->update(
                        $m[1],
                        array('option_value' => str_replace("\\'", "'", $m[2])),
                        array('option_name' => str_replace("\\'", "'", $m[3]), 'option_value' => (string) hex2bin($m[4]))
                    );
                } finally {
                    $this->exact_option_value_compare = false;
                }
            }

            return 0;
        }
        public function insert($table, $data, $format = null)
        {
            // The options table has a UNIQUE index on option_name. A plain
            // INSERT of an existing name fails with a duplicate-key error —
            // that failure is the atomic-claim primitive (#802), so the
            // double reproduces it exactly. add_option() does NOT behave
            // like this (it uses ON DUPLICATE KEY UPDATE); the earlier
            // add_option() double claiming otherwise is why a race shipped.
            if ($table === $this->options && isset($data['option_name'])) {
                // One-shot hook that runs between a caller's read and its
                // insert, so a test can land a concurrent writer's row first
                // (the first-creation race in #564).
                if (isset($GLOBALS['mcpwp_test_wpdb_before_insert']) && is_callable($GLOBALS['mcpwp_test_wpdb_before_insert'])) {
                    $hook = $GLOBALS['mcpwp_test_wpdb_before_insert'];
                    $GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
                    $hook((string) $data['option_name']);
                }
                if (! empty($GLOBALS['mcpwp_test_wpdb_insert_fails'])) {
                    $this->last_error = 'WordPress database error: connection lost';
                    return false;
                }
                $name = (string) $data['option_name'];
                if (array_key_exists($name, $GLOBALS['mcpwp_test_wpdb_options'] ?? array())) {
                    // A localized server or the SQLite driver word it differently.
                    $this->last_error = $GLOBALS['mcpwp_test_wpdb_duplicate_message'] ?? ("Duplicate entry '" . $name . "' for key 'option_name'");
                    return false;
                }
                $GLOBALS['mcpwp_test_wpdb_options'][ $name ] = (string) $data['option_value'];
                $this->last_error = '';
                return 1;
            }
            // A test may make inserts into one table fail (e.g. the action
            // log, #981 review round 5: no audit row → no removal).
            if (! empty($GLOBALS['mcpwp_test_wpdb_insert_fail'][ (string) $table ])) {
                return false;
            }
            // Captured so a test can assert what was — and was not — persisted
            // (activity log rows, for one).
            $GLOBALS['mcpwp_test_wpdb_rows'][ (string) $table ][] = $data;
            return 1;
        }
        public function update($table, $data, $where, $f = null, $wf = null)
        {
            if ($table === $this->options && isset($where['option_name'])) {
                $name = (string) $where['option_name'];
                // One-shot hook between a caller's read and its compare-and-swap,
                // so a test can land a concurrent writer's value first.
                if (isset($GLOBALS['mcpwp_test_wpdb_before_update']) && is_callable($GLOBALS['mcpwp_test_wpdb_before_update'])) {
                    $hook = $GLOBALS['mcpwp_test_wpdb_before_update'];
                    $GLOBALS['mcpwp_test_wpdb_before_update'] = null;
                    $hook($name);
                }
                $fails = $GLOBALS['mcpwp_test_wpdb_update_fails'] ?? false;
                if (is_callable($fails) ? (bool) $fails($name) : (bool) $fails) {
                    $this->last_error = 'WordPress database error: Lock wait timeout exceeded';
                    return false;
                }
                // Same one-table view as the probe: a raw row, else the options-API value.
                if (array_key_exists($name, $GLOBALS['mcpwp_test_wpdb_options'] ?? array())) {
                    $current = (string) $GLOBALS['mcpwp_test_wpdb_options'][ $name ];
                    $in_rows = true;
                } elseif (array_key_exists($name, $GLOBALS['mcpwp_test_options'] ?? array())) {
                    $current = (string) maybe_serialize($GLOBALS['mcpwp_test_options'][ $name ]);
                    $in_rows = false;
                } else {
                    return 0;
                }
                // WHERE option_value = %s compares with the column collation,
                // utf8mb4_*_ci on WordPress tables: case-insensitive, trailing
                // spaces ignored (#845 review). A byte-exact compare is what
                // the HEX(option_value) guard in query() asks for.
                if (isset($where['option_value'])) {
                    $expected = (string) $where['option_value'];
                    $matches  = $this->exact_option_value_compare
                        ? $expected === $current
                        : 0 === strcasecmp(rtrim($expected, ' '), rtrim($current, ' '));
                    if (! $matches) {
                        return 0; // compare-and-swap miss
                    }
                }
                if ($in_rows) {
                    $GLOBALS['mcpwp_test_wpdb_options'][ $name ] = (string) $data['option_value'];
                } else {
                    $GLOBALS['mcpwp_test_options'][ $name ] = maybe_unserialize((string) $data['option_value']);
                }
                // One-shot hook after a successful write, so a test can alter
                // what was stored before the caller reads it back.
                if (isset($GLOBALS['mcpwp_test_wpdb_after_update']) && is_callable($GLOBALS['mcpwp_test_wpdb_after_update'])) {
                    $hook = $GLOBALS['mcpwp_test_wpdb_after_update'];
                    $GLOBALS['mcpwp_test_wpdb_after_update'] = null;
                    $hook($name);
                }
                return 1;
            }
            return 1;
        }
        public function delete($table, $where, $where_format = null)
        {
            if ($table === $this->options && isset($where['option_name'])) {
                $name = (string) $where['option_name'];
                if (array_key_exists($name, $GLOBALS['mcpwp_test_wpdb_options'] ?? array())) {
                    // Like the real DELETE ... WHERE option_value = %s: a
                    // compare-and-delete that misses removes nothing.
                    if (isset($where['option_value']) && (string) $where['option_value'] !== (string) $GLOBALS['mcpwp_test_wpdb_options'][ $name ]) {
                        return 0;
                    }
                    unset($GLOBALS['mcpwp_test_wpdb_options'][ $name ]);
                    return 1;
                }
                return 0;
            }
            return 1;
        }
        public function replace($table, $data, $format = null)
        {
            // REPLACE overwrites an existing row, as in MySQL. A no-op here would
            // let a key-creation path that overwrites look as safe as INSERT.
            if ($table === $this->options && isset($data['option_name'])) {
                $GLOBALS['mcpwp_test_wpdb_options'][ (string) $data['option_name'] ] = (string) $data['option_value'];
            }
            return 1;
        }
        public function esc_like($text)
        {
            // Core: backslash-escape the LIKE metacharacters.
            return addcslashes((string) $text, '_%\\');
        }
    }

    if (! defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    $GLOBALS['wpdb'] = new Mcpwp_Test_WPDB();
}

// ── Post / meta stubs (controllable via globals) ─────────────────────────

// Minimal WP_Post double. The non-public content gate (guard_non_public_post)
// keys on `instanceof WP_Post`, which is how production posts arrive, so tests
// exercising that gate must seed WP_Post instances (not bare stdClass). The
// array constructor matches the shape RestSiteContentTest already relied on.
if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public $ID                = 0;
        public $post_type         = 'post';
        public $post_title        = '';
        public $post_name         = '';
        public $post_status       = 'publish';
        public $post_content      = '';
        public $post_excerpt      = '';
        public $post_date         = '';
        public $post_date_gmt     = '';
        public $post_modified     = '';
        public $post_modified_gmt = '';
        public $post_parent       = 0;
        public $menu_order        = 0;
        public $post_author       = 0;
        public $post_password     = '';

        public function __construct(array $data = array())
        {
            foreach ($data as $key => $value) {
                $this->{$key} = $value;
            }
        }
    }
}

$GLOBALS['_mcpwp_test_posts'] = array();
$GLOBALS['_mcpwp_test_meta']  = array();
$GLOBALS['_mcpwp_test_menu_page_ids'] = array();

function get_post($id)
{
    $id = (int) $id;
    // Site graph (#943 S1 review, BLOCK-3): lets a test simulate "a filter
    // this plugin does not control throws while rebuilding one specific
    // post" — off unless a test explicitly sets it.
    if (isset($GLOBALS['mcpwp_test_throw_for_post_id']) && (int) $GLOBALS['mcpwp_test_throw_for_post_id'] === $id) {
        throw new \RuntimeException('seeded test throw for post ' . $id);
    }
    return isset($GLOBALS['_mcpwp_test_posts'][ $id ]) ? $GLOBALS['_mcpwp_test_posts'][ $id ] : null;
}

if (! function_exists('get_post_status')) {
    function get_post_status($post = null)
    {
        $post = is_object($post) ? $post : get_post($post);
        return $post && isset($post->post_status) ? $post->post_status : false;
    }
}

if (! function_exists('clean_post_cache')) {
    function clean_post_cache($post)
    {
        // No object cache in tests; nothing to bust.
    }
}

function did_action($hook)
{
    // Real WP counts how many times an action has fired. Tests that need a
    // hook to look "already fired" set the count; everything else reads 0,
    // which is what an unbooted plugin genuinely looks like.
    return isset($GLOBALS['mcpwp_test_did_action'][ $hook ])
        ? (int) $GLOBALS['mcpwp_test_did_action'][ $hook ]
        : 0;
}

function get_posts($args = array())
{
    // Call-count spy — lets a test assert the duplicate-check QUERY itself
    // never ran (e.g. a title-only edit doing zero duplicate work), not
    // just that the outcome happened to be unflagged.
    $GLOBALS['mcpwp_test_get_posts_call_count'] = ($GLOBALS['mcpwp_test_get_posts_call_count'] ?? 0) + 1;

    $posts = array_values($GLOBALS['_mcpwp_test_posts']);
    $types = isset($args['post_type']) ? (array) $args['post_type'] : array();
    $statuses = isset($args['post_status']) ? (array) $args['post_status'] : array();
    $not_in = isset($args['post__not_in']) ? array_map('intval', (array) $args['post__not_in']) : array();

    $posts = array_values(array_filter($posts, function ($post) use ($types, $statuses, $not_in) {
        if (! empty($types) && ! in_array($post->post_type, $types, true)) {
            return false;
        }
        if (! empty($statuses) && ! in_array($post->post_status, $statuses, true)) {
            return false;
        }
        if (! empty($not_in) && in_array((int) $post->ID, $not_in, true)) {
            return false;
        }
        return true;
    }));

    $limit = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : -1;
    if ($limit > -1) {
        $posts = array_slice($posts, 0, $limit);
    }

    if (isset($args['fields']) && 'ids' === $args['fields']) {
        return array_map(function ($post) {
            return (int) $post->ID;
        }, $posts);
    }

    return $posts;
}

function post_type_exists($post_type)
{
    $known = array( 'post', 'page', 'wp_block', 'elementor_library', 'elementor_snippet' );
    // Tests may register public custom types: $GLOBALS['mcpwp_test_public_post_types'] = array( 'sm_team' ).
    // #1020: a test may also register a REGISTERED-BUT-PRIVATE type — exists,
    // but neither public nor show_in_rest (e.g. a plugin's internal working
    // storage) — via $GLOBALS['mcpwp_test_private_post_types'].
    return in_array((string) $post_type, $known, true)
        || in_array((string) $post_type, (array) ($GLOBALS['mcpwp_test_public_post_types'] ?? array()), true)
        || in_array((string) $post_type, (array) ($GLOBALS['mcpwp_test_private_post_types'] ?? array()), true);
}

function post_type_supports($post_type, $feature)
{
    if (isset($GLOBALS['mcpwp_test_post_type_supports'][ (string) $post_type ])) {
        return in_array($feature, (array) $GLOBALS['mcpwp_test_post_type_supports'][ (string) $post_type ], true);
    }
    return in_array((string) $post_type, array( 'post', 'page' ), true);
}

if (! defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (! function_exists('get_post_status_object')) {
    function get_post_status_object($status)
    {
        // Stock WP: only `publish` is public.
        $public = array( 'publish' => true );
        $known  = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
        if (! in_array((string) $status, $known, true)) {
            return null; // Unknown/unregistered status.
        }
        return (object) array(
            'name'   => (string) $status,
            'public' => ! empty($public[ (string) $status ]),
        );
    }
}

if (! function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
    {
        return substr(bin2hex(random_bytes(max(1, (int) $length))), 0, (int) $length);
    }
}

// ── WP-Cron doubles ──────────────────────────────────────────────────────
// One timestamp per hook in $GLOBALS['mcpwp_test_cron'], enough to assert
// whether an event is scheduled.
if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = array())
    {
        return $GLOBALS['mcpwp_test_cron'][ $hook ] ?? false;
    }
}
if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = array(), $wp_error = false)
    {
        $GLOBALS['mcpwp_test_cron'][ $hook ] = (int) $timestamp;
        return true;
    }
}
if (! function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($timestamp, $hook, $args = array(), $wp_error = false)
    {
        if (isset($GLOBALS['mcpwp_test_cron'][ $hook ]) && (int) $timestamp === $GLOBALS['mcpwp_test_cron'][ $hook ]) {
            unset($GLOBALS['mcpwp_test_cron'][ $hook ]);
        }
        return true;
    }
}
if (! function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = array(), $wp_error = false)
    {
        $had = isset($GLOBALS['mcpwp_test_cron'][ $hook ]) ? 1 : 0;
        unset($GLOBALS['mcpwp_test_cron'][ $hook ]);
        return $had;
    }
}
// wp_schedule_single_event() has no real WP counterpart here — it also records
// into $GLOBALS['mcpwp_test_single_cron'] (a plain list, since a single hook
// can legitimately be scheduled more than once with different $args) so a
// test can assert not just "was this hook scheduled" but "with which args".
// Cron itself never actually runs in this harness; a test simulates a cron
// tick by calling do_action($hook, ...$args) directly against whatever
// add_action() already registered.
if (! function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = array(), $wp_error = false)
    {
        $GLOBALS['mcpwp_test_cron'][ $hook ] = (int) $timestamp;
        $GLOBALS['mcpwp_test_single_cron'][] = array(
            'timestamp' => (int) $timestamp,
            'hook'      => $hook,
            'args'      => $args,
        );
        return true;
    }
}

if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '')
    {
        // Deletes from the object cache double below, as core does. A no-op
        // delete made every cache invalidation unobservable: removing one
        // could not fail a test (#845 review).
        unset($GLOBALS['mcpwp_test_object_cache'][ (string) $group . ':' . (string) $key ]);
        return true;
    }
}

/*
 * Persistent object cache double with atomic add/incr/decr (#752 F6).
 *
 * Models what a Redis or Memcached drop-in guarantees across requests: one
 * shared store, `add` that succeeds only when the key is absent, and
 * `incr`/`decr` that apply in a single step, so the value `incr` returns
 * already includes every other caller's increment. Semantics follow core's
 * WP_Object_Cache: `incr`/`decr` on a missing key return false, a result
 * below zero is floored at zero, and an expiry of 0 means "never expires".
 * `mcpwp_test_object_cache_incr_creates` switches incr/decr to Redis
 * semantics instead: a missing key is created with no expiry, and values may
 * go negative. `add` honours wp_suspend_cache_addition(), as core does.
 *
 * `mcpwp_test_object_cache_hook` lets a test answer one operation
 * differently from the store — a backend failure, an eviction between
 * `add` and `incr`, a drop-in returning a non-count — without a real broken
 * backend: fn($op, $key, $group) returning null passes through; any other
 * value is returned instead and the store is left untouched.
 */
if (! function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache($using = null)
    {
        $current = ! empty($GLOBALS['mcpwp_test_ext_object_cache']);
        if (null !== $using) {
            $GLOBALS['mcpwp_test_ext_object_cache'] = (bool) $using;
        }
        return $current;
    }
}

if (! function_exists('mcpwp_test_object_cache_hook')) {
    function mcpwp_test_object_cache_hook($op, $key, $group)
    {
        $hook = $GLOBALS['mcpwp_test_object_cache_hook'] ?? null;
        return is_callable($hook) ? call_user_func($hook, $op, $key, $group) : null;
    }
}

if (! function_exists('mcpwp_test_object_cache_live')) {
    /** The live entry for group:key, dropping it first if it has expired. */
    function mcpwp_test_object_cache_live($id)
    {
        if (! isset($GLOBALS['mcpwp_test_object_cache'][ $id ])) {
            return null;
        }
        $item = $GLOBALS['mcpwp_test_object_cache'][ $id ];
        if (0 !== $item['expires'] && $item['expires'] <= time()) {
            unset($GLOBALS['mcpwp_test_object_cache'][ $id ]);
            return null;
        }
        return $item;
    }
}

if (! function_exists('wp_suspend_cache_addition')) {
    function wp_suspend_cache_addition($suspend = null)
    {
        if (is_bool($suspend)) {
            $GLOBALS['mcpwp_test_cache_addition_suspended'] = $suspend;
        }
        return ! empty($GLOBALS['mcpwp_test_cache_addition_suspended']);
    }
}

if (! function_exists('wp_cache_add')) {
    function wp_cache_add($key, $data, $group = '', $expire = 0)
    {
        $answer = mcpwp_test_object_cache_hook('add', $key, $group);
        if (null !== $answer) {
            return $answer;
        }
        // Core and Redis Object Cache: add is a no-op while addition is suspended.
        if (wp_suspend_cache_addition()) {
            return false;
        }
        $id = (string) $group . ':' . (string) $key;
        if (null !== mcpwp_test_object_cache_live($id)) {
            return false;
        }
        $GLOBALS['mcpwp_test_object_cache'][ $id ] = array(
            'value'   => $data,
            'expires' => (int) $expire > 0 ? time() + (int) $expire : 0,
            'ttl'     => (int) $expire,
        );
        return true;
    }
}

if (! function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '', $force = false, &$found = null)
    {
        $answer = mcpwp_test_object_cache_hook('get', $key, $group);
        if (null !== $answer) {
            $found = false !== $answer;
            return $answer;
        }
        $item  = mcpwp_test_object_cache_live((string) $group . ':' . (string) $key);
        $found = null !== $item;
        return $found ? $item['value'] : false;
    }
}

if (! function_exists('mcpwp_test_object_cache_step')) {
    function mcpwp_test_object_cache_step($op, $key, $offset, $group)
    {
        $answer = mcpwp_test_object_cache_hook($op, $key, $group);
        if (null !== $answer) {
            return $answer;
        }
        $id         = (string) $group . ':' . (string) $key;
        $item       = mcpwp_test_object_cache_live($id);
        $redis_like = ! empty($GLOBALS['mcpwp_test_object_cache_incr_creates']);
        if (null === $item) {
            if (! $redis_like) {
                return false;
            }
            // Redis INCRBY/DECRBY: a missing key is created from zero, with no expiry.
            $item = array('value' => 0, 'expires' => 0, 'ttl' => 0);
            $GLOBALS['mcpwp_test_object_cache'][ $id ] = $item;
        }
        $value = is_numeric($item['value']) ? (int) $item['value'] : 0;
        $value = 'incr' === $op ? $value + (int) $offset : $value - (int) $offset;
        if ($value < 0 && ! $redis_like) {
            $value = 0;
        }
        $GLOBALS['mcpwp_test_object_cache'][ $id ]['value'] = $value;
        return $value;
    }
}

if (! function_exists('wp_cache_incr')) {
    function wp_cache_incr($key, $offset = 1, $group = '')
    {
        return mcpwp_test_object_cache_step('incr', $key, $offset, $group);
    }
}

if (! function_exists('wp_cache_decr')) {
    function wp_cache_decr($key, $offset = 1, $group = '')
    {
        return mcpwp_test_object_cache_step('decr', $key, $offset, $group);
    }
}

if (! function_exists('is_taxonomy_viewable')) {
    /** Double: viewable unless a test registers the taxonomy with 'viewable' => false. */
    function is_taxonomy_viewable($taxonomy)
    {
        $tax = $GLOBALS['_mcpwp_test_taxonomies'][ (string) $taxonomy ] ?? array();
        return ! array_key_exists('viewable', $tax) || (bool) $tax['viewable'];
    }
}

if (! function_exists('get_post_type')) {
    function get_post_type($post = null)
    {
        if (is_object($post) && isset($post->post_type)) {
            return $post->post_type;
        }
        $id = is_object($post) ? (int) $post->ID : (int) $post;
        if (isset($GLOBALS['_mcpwp_test_posts'][$id]->post_type)) {
            return $GLOBALS['_mcpwp_test_posts'][$id]->post_type;
        }
        return false;
    }
}

if (! function_exists('wp_http_validate_url')) {
    // Mirror the parts of WP's wp_http_validate_url() that change a caller's
    // answer — including the ones that make it return false for reasons
    // unrelated to the URL being absolute.
    //
    // An earlier stub validated any absolute http(s) URL and stopped there.
    // That made a test assert the opposite of production: real WP runs
    // gethostbyname() on a foreign host and `return false`s when it does not
    // resolve, so `https://another-site.test/other/` — a reserved TLD that
    // never resolves — validates here and fails there. A caller that treats
    // false as "relative" then resolved it against home_url() and decided an
    // external link was internal. The test was green and wrong.
    //
    // No DNS is performed here. Hosts under the reserved .test/.invalid TLDs
    // (RFC 6761) are treated as unresolvable, which is what they are, so a
    // fixture using them exercises the real false branch.
    function wp_http_validate_url($url)
    {
        $url = trim((string) $url);
        if ('' === $url || 0 !== strpos($url, 'http')) {
            return false;
        }
        $parsed = parse_url($url);
        if (empty($parsed['host']) || empty($parsed['scheme'])) {
            return false;
        }
        if (! in_array(strtolower($parsed['scheme']), array( 'http', 'https' ), true)) {
            return false;
        }
        // WP rejects credentials in the URL.
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return false;
        }
        $home = parse_url(home_url());
        $same = ! empty($home['host']) && strtolower($home['host']) === strtolower($parsed['host']);
        if (! $same && preg_match('/\.(test|invalid)$/i', $parsed['host'])) {
            return false; // gethostbyname() error condition in real WP.
        }
        return $url;
    }
}

if (! function_exists('url_to_postid')) {
    // Mirror WP: resolve the plain-permalink query vars (?p=ID / ?page_id=ID)
    // regardless of post status; pretty draft slugs fall through to path lookup.
    // A test can also register an exact pretty-permalink URL -> post ID via
    // $GLOBALS['mcpwp_test_url_to_postid'][$url] = $id, since get_permalink()
    // here always returns the plain-permalink shape and this stub has no
    // rewrite-rule engine to derive a pretty path from a post ID itself.
    function url_to_postid($url)
    {
        if (isset($GLOBALS['mcpwp_test_url_to_postid'][ (string) $url ])) {
            return (int) $GLOBALS['mcpwp_test_url_to_postid'][ (string) $url ];
        }

        $query = (string) parse_url((string) $url, PHP_URL_QUERY);
        if ('' !== $query) {
            parse_str($query, $args);
            foreach (array( 'p', 'page_id' ) as $var) {
                if (! empty($args[ $var ])) {
                    return (int) $args[ $var ];
                }
            }
        }
        return 0;
    }
}

if (! function_exists('get_page_by_path')) {
    // Site graph resolver fix (#943 S1 review round 2, BLOCK-2) depends on
    // this WALKING THE PARENT CHAIN like the real core function does, not
    // just matching the last segment — a stub that only checked basename()
    // would make BLOCK-2's own regression test pass without the underlying
    // bug actually being fixed.
    function get_page_by_path($path, $output = OBJECT, $post_type = 'page')
    {
        $types    = (array) $post_type;
        $segments = array_values(array_filter(explode('/', trim((string) $path, '/')), 'strlen'));
        if (empty($segments)) {
            return null;
        }

        $parent_id = 0;
        $found     = null;
        foreach ($segments as $i => $slug) {
            $slug     = sanitize_title($slug);
            $is_last  = ($i === count($segments) - 1);
            $found    = null;
            foreach ($GLOBALS['_mcpwp_test_posts'] as $post) {
                if ('publish' !== $post->post_status) {
                    continue;
                }
                if ($post->post_name !== $slug) {
                    continue;
                }
                if ((int) $post->post_parent !== $parent_id) {
                    continue;
                }
                if ($is_last && ! in_array($post->post_type, $types, true)) {
                    continue;
                }
                $found = $post;
                break;
            }
            if (! $found) {
                return null;
            }
            $parent_id = (int) $found->ID;
        }

        return $found;
    }
}

if (! function_exists('parse_blocks')) {
    // Minimal: wrap non-empty content as one freeform block; empty → no blocks.
    function parse_blocks($content)
    {
        if ('' === trim((string) $content)) {
            return array();
        }
        return array(
            array(
                'blockName'    => null,
                'attrs'        => array(),
                'innerHTML'    => (string) $content,
                'innerBlocks'  => array(),
                'innerContent' => array( (string) $content ),
            ),
        );
    }
}

if (! function_exists('get_preview_post_link')) {
    function get_preview_post_link($post = 0)
    {
        $id = is_object($post) ? (int) $post->ID : (int) $post;
        return 'https://example.com/?p=' . $id . '&preview=true';
    }
}

if (! function_exists('has_excerpt')) {
    function has_excerpt($post = 0)
    {
        $post = is_object($post) ? $post : get_post($post);
        return $post && '' !== trim((string) ($post->post_excerpt ?? ''));
    }
}

if (! function_exists('get_object_taxonomies')) {
    // Was an always-empty stub. A second, taxonomy-aware definition further
    // down this file (guarded the same way) was consequently dead code —
    // function_exists() means only the FIRST definition PHP evaluates in a
    // single bootstrap ever wins — so nothing that seeded
    // $GLOBALS['_mcpwp_test_taxonomies'] and called get_object_taxonomies()
    // (rather than hardcoding a taxonomy name) could ever see it. Harmless
    // until Mcpwp_Graph_Builder::extract_term_refs() (#943 S1) became the
    // first caller to actually depend on the enumeration. Moved here, and
    // the dead duplicate below removed, rather than leaving two copies.
    function get_object_taxonomies($object, $output = 'names')
    {
        $object_type = is_object($object) ? ($object->post_type ?? '') : (string) $object;
        $out         = array();
        foreach ($GLOBALS['_mcpwp_test_taxonomies'] ?? array() as $name => $tax) {
            if (in_array($object_type, (array) ($tax['object_type'] ?? array()), true)) {
                $out[] = $name;
            }
        }
        return $out;
    }
}

if (! function_exists('wp_get_post_terms')) {
    function wp_get_post_terms($post_id = 0, $taxonomy = 'post_tag', $args = array())
    {
        return array();
    }
}

if (! function_exists('wp_trim_words')) {
    function wp_trim_words($text, $num_words = 55, $more = null)
    {
        $words = preg_split('/\s+/', trim((string) $text));
        if (count($words) <= $num_words) {
            return (string) $text;
        }
        return implode(' ', array_slice($words, 0, $num_words)) . ($more ?? '…');
    }
}

// ── Cron / plugin-update stubs (drive via globals) ───────────────────────

$GLOBALS['mcpwp_test_doing_cron']            = false;
$GLOBALS['mcpwp_test_plugin_updates']        = array();
$GLOBALS['mcpwp_test_update_plugins_called'] = false;

function wp_doing_cron()
{
    return (bool) $GLOBALS['mcpwp_test_doing_cron'];
}

function wp_update_plugins()
{
    $GLOBALS['mcpwp_test_update_plugins_called'] = true;
}

function get_plugin_updates()
{
    return $GLOBALS['mcpwp_test_plugin_updates'];
}

function get_post_type_object($post_type)
{
    if (! post_type_exists($post_type)) {
        return null;
    }
    return (object) array(
        'name'         => $post_type,
        'public'       => in_array((string) $post_type, array( 'post', 'page' ), true) || in_array((string) $post_type, (array) ($GLOBALS['mcpwp_test_public_post_types'] ?? array()), true),
        // #1020: honoured by category_for_post_type()'s public-OR-show_in_rest
        // fallback. A test-registered private type (mcpwp_test_private_post_types)
        // defaults to false here, same as a real non-REST-exposed CPT.
        'show_in_rest' => in_array((string) $post_type, (array) ($GLOBALS['mcpwp_test_show_in_rest_post_types'] ?? array()), true),
        // Core's default capability_type mapping for the two built-ins; a
        // test-registered type maps like 'post'. Read by WebMCP (#981).
        // A test may give a type its own capability_type via
        // $GLOBALS['mcpwp_test_post_type_caps'][ $type ] = array( 'edit_posts' => …, 'create_posts' => … ).
        'cap'          => (object) array_merge(
            array(
                'create_posts' => 'page' === (string) $post_type ? 'edit_pages' : 'edit_posts',
                'edit_posts'   => 'page' === (string) $post_type ? 'edit_pages' : 'edit_posts',
            ),
            (array) ($GLOBALS['mcpwp_test_post_type_caps'][ (string) $post_type ] ?? array())
        ),
    );
}

if (! function_exists('get_post_types')) {
    // Mirrors post_type_exists()'s known set + any test-registered public
    // custom types. Only the `public` arg is honoured (the one the graph
    // backfill batch query — #943 S1 — actually passes).
    function get_post_types($args = array())
    {
        $all = array_values(array_unique(array_merge(
            array( 'post', 'page', 'wp_block', 'elementor_library', 'elementor_snippet' ),
            (array) ($GLOBALS['mcpwp_test_public_post_types'] ?? array())
        )));

        if (! empty($args['public'])) {
            $all = array_values(array_filter($all, function ($post_type) {
                $obj = get_post_type_object($post_type);
                return $obj && ! empty($obj->public);
            }));
        }

        // BLOCK-2: hierarchical_covered_post_types() needs this filter.
        // 'page' is hierarchical by default; a test can register more via
        // $GLOBALS['mcpwp_test_hierarchical_post_types'].
        if (array_key_exists('hierarchical', $args)) {
            $hierarchical = array_unique(array_merge(array('page'), (array) ($GLOBALS['mcpwp_test_hierarchical_post_types'] ?? array())));
            $all = array_values(array_filter($all, function ($post_type) use ($args, $hierarchical) {
                $is_hierarchical = in_array($post_type, $hierarchical, true);
                return $args['hierarchical'] ? $is_hierarchical : ! $is_hierarchical;
            }));
        }

        return $all;
    }
}

// The page templates the active theme/plugins register, mirroring
// wp_get_theme()->get_page_templates(). Tests can override via
// $GLOBALS['mcpwp_test_page_templates']; unset means the same three
// Elementor-registered templates a real rig has.
function mcpwp_test_page_templates()
{
    return isset($GLOBALS['mcpwp_test_page_templates']) ? $GLOBALS['mcpwp_test_page_templates'] : array(
        'elementor_canvas'        => 'Elementor Canvas',
        'elementor_header_footer' => 'Elementor Full Width',
        'elementor_theme'         => 'Theme',
    );
}

// Mirrors WP core's wp_insert_post(): if a non-empty 'page_template' key is
// present in $postarr, it is validated against the active theme's registered
// templates. 'default' always short-circuits as valid. An invalid slug
// returns a WP_Error (when $wp_error) WITHOUT undoing whatever else this
// call already wrote — matching core, where the row is already persisted by
// the time this check runs. See #965.
function mcpwp_test_apply_page_template($post_id, $postarr, $wp_error)
{
    if (empty($postarr['page_template'])) {
        return null;
    }

    $templates = mcpwp_test_page_templates();
    if ('default' !== $postarr['page_template'] && ! isset($templates[ $postarr['page_template'] ])) {
        if ($wp_error) {
            return new WP_Error('invalid_page_template', 'Invalid page template.');
        }
        update_post_meta($post_id, '_wp_page_template', 'default');
        return null;
    }

    update_post_meta($post_id, '_wp_page_template', $postarr['page_template']);
    return null;
}

function wp_insert_post($postarr, $wp_error = false)
{
    $is_update = ! empty($postarr['ID']) && ! empty($GLOBALS['_mcpwp_test_posts'][ (int) $postarr['ID'] ]);
    $old_status = $is_update ? ($GLOBALS['_mcpwp_test_posts'][ (int) $postarr['ID'] ]->post_status ?? '') : 'new';
    $post_before = $is_update ? clone $GLOBALS['_mcpwp_test_posts'][ (int) $postarr['ID'] ] : null;

    // wp_insert_post_data fires here in real WP core, before the row is
    // written, for BOTH a create and an update (wp_update_post() below
    // calls this same function) — it's the one filter #957's content
    // guardrails hook to gate a caller-controlled post_status regardless
    // of which of the many write paths reached it.
    $filtered = apply_filters('wp_insert_post_data', $postarr, $postarr, $postarr, $is_update);
    if (is_array($filtered)) {
        $postarr = $filtered;
    }

    $next_id = empty($GLOBALS['_mcpwp_test_posts']) ? 1 : max(array_map('intval', array_keys($GLOBALS['_mcpwp_test_posts']))) + 1;
    $post_id = isset($postarr['ID']) ? (int) $postarr['ID'] : $next_id;

    $GLOBALS['_mcpwp_test_posts'][ $post_id ] = (object) array(
        'ID'                => $post_id,
        'post_type'         => isset($postarr['post_type']) ? $postarr['post_type'] : 'post',
        'post_title'        => isset($postarr['post_title']) ? $postarr['post_title'] : '',
        'post_name'         => isset($postarr['post_name']) ? $postarr['post_name'] : sanitize_title(isset($postarr['post_title']) ? $postarr['post_title'] : ''),
        'post_status'       => isset($postarr['post_status']) ? $postarr['post_status'] : 'draft',
        'post_date'         => '2026-06-06 00:00:00',
        'post_date_gmt'     => '2026-06-06 00:00:00',
        'post_modified'     => '2026-06-06 00:00:00',
        'post_modified_gmt' => '2026-06-06 00:00:00',
        'post_parent'       => 0,
        'menu_order'        => 0,
        'post_author'       => 1,
        'post_content'      => isset($postarr['post_content']) ? $postarr['post_content'] : '',
        'post_excerpt'      => isset($postarr['post_excerpt']) ? $postarr['post_excerpt'] : '',
    );

    // meta_input: a real wp_insert_post() feature — writes postmeta
    // atomically alongside the post. clone_page() relies on this (#957) to
    // make _elementor_data visible to the wp_insert_post_data filter above
    // even for a brand-new post that has no ID until this point.
    if (! empty($postarr['meta_input']) && is_array($postarr['meta_input'])) {
        foreach ($postarr['meta_input'] as $meta_key => $meta_value) {
            update_post_meta($post_id, $meta_key, $meta_value);
        }
    }

    $template_error = mcpwp_test_apply_page_template($post_id, $postarr, $wp_error);
    if ($template_error instanceof WP_Error) {
        return $template_error;
    }

    $post = $GLOBALS['_mcpwp_test_posts'][ $post_id ];
    do_action('transition_post_status', $post->post_status ?? '', $old_status, $post);
    if ($is_update) {
        do_action('post_updated', $post_id, $post, $post_before);
    }
    do_action('wp_insert_post', $post_id, $post, $is_update);

    return $post_id;
}

function wp_update_post($postarr, $wp_error = false)
{
    if (empty($postarr['ID']) || empty($GLOBALS['_mcpwp_test_posts'][ (int) $postarr['ID'] ])) {
        return $wp_error ? new WP_Error('not_found', 'Post not found.', array( 'status' => 404 )) : 0;
    }

    $post_id = (int) $postarr['ID'];
    $old_status = $GLOBALS['_mcpwp_test_posts'][ $post_id ]->post_status ?? '';
    // Snapshot BEFORE any field mutation below, for the post_updated action
    // (WordPress core passes the pre-update post object as its 3rd arg).
    $post_before = clone $GLOBALS['_mcpwp_test_posts'][ $post_id ];

    // Mirror WP core's wp_update_post(): it fetches the FULL existing row
    // (get_post($ID, ARRAY_A)) and merges it under the caller's array before
    // ever reaching wp_insert_post()'s validation. WP_Post::to_array() folds
    // in a virtual 'page_template' key sourced from the _wp_page_template
    // postmeta, so a caller who never mentions page_template still carries
    // forward whatever stale slug is stored in meta. Reproduces #965: a
    // title-only update on a post with a stale/invalid _wp_page_template can
    // be rejected below even though nothing here asked for a template.
    if (! array_key_exists('page_template', $postarr)) {
        $existing_template = get_post_meta($post_id, '_wp_page_template', true);
        if ('' !== $existing_template) {
            $postarr['page_template'] = $existing_template;
        }
    }

    // wp_insert_post_data fires here too — WP core's wp_update_post() calls
    // wp_insert_post() internally, hitting the exact same filter (#957).
    $filtered = apply_filters('wp_insert_post_data', $postarr, $postarr, $postarr, true);
    if (is_array($filtered)) {
        $postarr = $filtered;
    }

    $post = $GLOBALS['_mcpwp_test_posts'][ $post_id ];
    foreach ($postarr as $key => $value) {
        if ('ID' === $key || 'page_template' === $key) {
            continue;
        }
        $post->{$key} = $value;
    }
    $post->post_modified = '2026-06-06 01:00:00';
    $post->post_modified_gmt = '2026-06-06 01:00:00';
    $GLOBALS['_mcpwp_test_posts'][ $post_id ] = $post;

    // Mirror WP core: the page-template check runs AFTER the row is written,
    // so an invalid template surfaces as an error return without undoing the
    // field changes already applied above.
    $template_error = mcpwp_test_apply_page_template($post_id, $postarr, $wp_error);
    if ($template_error instanceof WP_Error) {
        return $template_error;
    }

    do_action('transition_post_status', $post->post_status ?? '', $old_status, $post);
    // WordPress core fires this ONLY for a genuine update (never a
    // create), after the row is actually written — content guardrails'
    // post_content advisory (#957 round 4 NIT-2) defers its recording to
    // exactly this hook.
    do_action('post_updated', $post_id, $post, $post_before);
    do_action('wp_insert_post', $post_id, $post, true);

    return $post_id;
}

class WP_Query
{
    public $posts = array();
    public $found_posts = 0;
    public $max_num_pages = 0;

    public function __construct($args = array())
    {
        $posts = array_values($GLOBALS['_mcpwp_test_posts']);
        $types = isset($args['post_type']) ? (array) $args['post_type'] : array();
        $statuses = isset($args['post_status']) ? (array) $args['post_status'] : array();

        $posts = array_values(array_filter($posts, function ($post) use ($types, $statuses, $args) {
            if (! empty($types) && ! in_array('any', $types, true) && ! in_array($post->post_type, $types, true)) {
                return false;
            }
            if (! empty($statuses) && ! in_array('any', $statuses, true) && ! in_array($post->post_status, $statuses, true)) {
                return false;
            }
            if (isset($args['post_parent']) && (int) $post->post_parent !== (int) $args['post_parent']) {
                return false;
            }
            if (! empty($args['post__in']) && ! in_array((int) $post->ID, $args['post__in'], true)) {
                return false;
            }
            if (! empty($args['s']) && false === stripos($post->post_title . ' ' . $post->post_content, (string) $args['s'])) {
                return false;
            }
            // meta_key alone is an EXISTS test in WP_Query: any row under that
            // key, empty values included. A double that ignored it handed
            // find/replace a candidate set production cannot produce.
            if (! empty($args['meta_key'])) {
                $meta = isset($GLOBALS['_mcpwp_test_meta'][ (int) $post->ID ]) ? $GLOBALS['_mcpwp_test_meta'][ (int) $post->ID ] : array();
                if (! is_array($meta) || ! array_key_exists($args['meta_key'], $meta)) {
                    return false;
                }
            }
            return true;
        }));

        // orderby ID is the only ordering the offset/resume contract relies on;
        // insertion order is not a guarantee production gives.
        if (isset($args['orderby']) && 'ID' === $args['orderby']) {
            usort($posts, function ($a, $b) {
                return (int) $a->ID <=> (int) $b->ID;
            });
            if (isset($args['order']) && 'DESC' === strtoupper((string) $args['order'])) {
                $posts = array_reverse($posts);
            }
        }

        $this->found_posts = count($posts);

        if (! empty($args['offset'])) {
            $posts = array_slice($posts, (int) $args['offset']);
        }
        $per_page = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : count($posts);

        // -1 means unlimited in real WP_Query, and clamping it to 1 made this
        // double disagree with WordPress on the one value internal callers use
        // to mean "everything" — a test could pass against the double and the
        // code still truncate a library in production, or vice versa.
        if ($per_page < 0) {
            $this->max_num_pages = $this->found_posts > 0 ? 1 : 0;
            $this->posts         = $posts;
            return;
        }

        $per_page = max(1, $per_page);
        $paged = isset($args['paged']) ? max(1, (int) $args['paged']) : 1;
        $this->max_num_pages = $per_page > 0 ? (int) ceil($this->found_posts / $per_page) : 0;
        $this->posts = array_slice($posts, ($paged - 1) * $per_page, $per_page);
    }
}

function wp_count_posts($type)
{
    $counts = array(
        'publish' => 0,
        'draft'   => 0,
        'private' => 0,
        'trash'   => 0,
    );

    foreach ($GLOBALS['_mcpwp_test_posts'] as $post) {
        if ($post->post_type === $type && isset($counts[ $post->post_status ])) {
            $counts[ $post->post_status ]++;
        }
    }

    return (object) $counts;
}

function wp_strip_all_tags($value)
{
    return strip_tags((string) $value);
}

function get_post_modified_time($format, $gmt, $post)
{
    $timestamp = isset($post->modified_ts) ? (int) $post->modified_ts : time();
    if ('U' === $format) {
        return $timestamp;
    }
    return gmdate('c', $timestamp);
}

function get_the_title($post)
{
    if (is_object($post)) {
        return isset($post->post_title) ? $post->post_title : '';
    }
    $post = get_post($post);
    return $post ? $post->post_title : '';
}

function get_permalink($post)
{
    $post_id = is_object($post) ? (int) $post->ID : (int) $post;

    // BLOCK-1 (round 3): the resolver's non-hierarchical nested-path branch
    // compares a candidate's REAL permalink against the href it is being
    // matched to — a plain `?p=ID` stub can never differ from a nested path
    // like /product-category/shoes/, which would make that comparison
    // vacuously always-fail (or, if the comparison were reversed by
    // mistake, always-pass) and hide either direction of the bug. A test
    // that needs a post type with its own rewrite structure (a CPT with an
    // archive base, a taxonomy-prefixed permalink) declares it explicitly.
    if (isset($GLOBALS['mcpwp_test_permalinks'][$post_id])) {
        return $GLOBALS['mcpwp_test_permalinks'][$post_id];
    }

    return 'https://example.com/?p=' . $post_id;
}

function get_edit_post_link($post_id, $context = 'display')
{
    return 'https://example.com/wp-admin/post.php?post=' . (int) $post_id . '&action=edit';
}

function get_the_author_meta($field, $user_id)
{
    return 'Author ' . (int) $user_id;
}

function get_post_thumbnail_id($post_id)
{
    $post_id = is_object($post_id) ? (int) ($post_id->ID ?? 0) : (int) $post_id;
    return (int) ($GLOBALS['_mcpwp_test_thumbnail_ids'][ $post_id ] ?? 0);
}

function wp_get_attachment_image_src($attachment_id, $size = 'thumbnail')
{
    return false;
}

if (! function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($attachment_id)
    {
        return 'https://example.com/wp-content/uploads/' . (int) $attachment_id . '.jpg';
    }
}

if (! function_exists('has_blocks')) {
    function has_blocks($post = null)
    {
        $post = is_object($post) ? $post : get_post($post);
        return (bool) ($post && false !== strpos((string) $post->post_content, '<!-- wp:'));
    }
}

if (! function_exists('get_term_link')) {
    function get_term_link($term, $taxonomy = '')
    {
        $term_id = is_object($term) ? (int) $term->term_id : (int) $term;
        return 'https://example.com/?term_id=' . $term_id;
    }
}

if (! function_exists('wp_is_post_revision')) {
    function wp_is_post_revision($post)
    {
        $post = is_object($post) ? $post : get_post($post);
        return ( $post && isset($post->post_type) && 'revision' === $post->post_type ) ? (int) ($post->post_parent ?? 0) : false;
    }
}

if (! function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave($post)
    {
        $post = is_object($post) ? $post : get_post($post);
        return (bool) ( $post && isset($post->post_name) && false !== strpos((string) $post->post_name, '-autosave-') );
    }
}

if (! function_exists('esc_sql')) {
    function esc_sql($value)
    {
        if (is_array($value)) {
            return array_map('esc_sql', $value);
        }
        return addslashes((string) $value);
    }
}

// -------------------------------------------------------------------------
// Minimal HTTP API stubs. Mirrors WordPress's own short-circuit contract:
// wp_remote_request() calls apply_filters('pre_http_request', false, $args, $url)
// first; tests hook that same filter (as they would against real WP) to
// mock responses instead of hitting the network.
// -------------------------------------------------------------------------

function wp_remote_request($url, $args = array())
{
    $args = wp_parse_args($args, array( 'method' => 'GET' ));
    $pre  = apply_filters('pre_http_request', false, $args, $url);
    if (false !== $pre) {
        return $pre;
    }

    return new WP_Error('http_request_failed', 'No network access in test environment; hook pre_http_request to mock a response.');
}

function wp_remote_get($url, $args = array())
{
    $args['method'] = 'GET';
    return wp_remote_request($url, $args);
}

if (! function_exists('wp_safe_remote_get')) {
    function wp_safe_remote_get($url, $args = array())
    {
        // Mirror core: same transport, with reject_unsafe_urls on. Tests mock
        // the response via the pre_http_request filter regardless.
        $args['method']             = 'GET';
        $args['reject_unsafe_urls'] = true;
        return wp_remote_request($url, $args);
    }
}

if (! function_exists('wp_safe_remote_post')) {
    function wp_safe_remote_post($url, $args = array())
    {
        // Same shape as wp_safe_remote_get() above, for POST callers (e.g.
        // Mcpwp_Jev) that must reject internal/private hosts even though
        // nothing in this double actually enforces it — tests mock the
        // response via pre_http_request regardless.
        $args['method']             = 'POST';
        $args['reject_unsafe_urls'] = true;
        return wp_remote_request($url, $args);
    }
}

function wp_remote_post($url, $args = array())
{
    $args['method'] = 'POST';
    return wp_remote_request($url, $args);
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) && isset($response['body']) ? $response['body'] : '';
}

if (! function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, $header)
    {
        if (! is_array($response) || ! isset($response['headers'])) {
            return '';
        }
        $headers = array_change_key_case((array) $response['headers'], CASE_LOWER);
        $header  = strtolower((string) $header);
        return isset($headers[ $header ]) ? $headers[ $header ] : '';
    }
}

if (! function_exists('wp_tempnam')) {
    function wp_tempnam($filename = '')
    {
        return tempnam(sys_get_temp_dir(), 'mcpwp_test_');
    }
}

if (! function_exists('wp_delete_file')) {
    function wp_delete_file($file)
    {
        if (is_string($file) && file_exists($file)) {
            unlink($file);
        }
    }
}

if (! function_exists('verify_file_md5')) {
    // Mirror WP core: hex (32 chars) or base64 expected digest; true on match,
    // WP_Error('md5_mismatch') otherwise. Unknown format is a no-op (true).
    function verify_file_md5($filename, $expected_md5)
    {
        if (32 === strlen($expected_md5)) {
            $expected_raw = pack('H*', $expected_md5);
        } elseif (24 === strlen($expected_md5)) {
            $expected_raw = base64_decode($expected_md5);
        } else {
            return true;
        }
        $file_md5 = md5_file($filename, true);
        if ($file_md5 === $expected_raw) {
            return true;
        }
        return new WP_Error('md5_mismatch', 'The checksum of the file does not match the expected checksum value.');
    }
}

if (! class_exists('WP_Http')) {
    class WP_Http
    {
        // Minimal make_absolute_url: return absolute URLs as-is, resolve a
        // root-relative path against the base origin. Sufficient for tests.
        public static function make_absolute_url($maybe_relative_url, $url)
        {
            if (preg_match('#^https?://#i', (string) $maybe_relative_url)) {
                return (string) $maybe_relative_url;
            }
            $parts = parse_url((string) $url);
            if (empty($parts['scheme']) || empty($parts['host'])) {
                return (string) $maybe_relative_url;
            }
            $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if ('' !== (string) $maybe_relative_url && '/' === $maybe_relative_url[0]) {
                return $origin . $maybe_relative_url;
            }
            return $origin . '/' . ltrim((string) $maybe_relative_url, '/');
        }
    }
}

function wp_get_nav_menus()
{
    return empty($GLOBALS['_mcpwp_test_menu_page_ids']) ? array() : array((object) array( 'term_id' => 1 ));
}

function wp_get_nav_menu_items($term_id)
{
    return array_map(function ($post_id) {
        return (object) array(
            'object'    => 'page',
            'object_id' => (int) $post_id,
        );
    }, $GLOBALS['_mcpwp_test_menu_page_ids']);
}

function get_nav_menu_locations()
{
    return isset($GLOBALS['_mcpwp_test_nav_menu_locations']) ? $GLOBALS['_mcpwp_test_nav_menu_locations'] : array();
}

function wp_create_nav_menu($name)
{
    $id = empty($GLOBALS['_mcpwp_test_nav_menus']) ? 1 : (max(array_keys($GLOBALS['_mcpwp_test_nav_menus'])) + 1);
    $GLOBALS['_mcpwp_test_nav_menus'][ $id ] = (object) array(
        'term_id'     => $id,
        'name'        => (string) $name,
        'slug'        => sanitize_title((string) $name),
        'description' => '',
        'count'       => 0,
    );
    return $id;
}

// A nav menu exists only when registered in $GLOBALS['_mcpwp_test_nav_menus'].
function wp_get_nav_menu_object($menu)
{
    $id = is_object($menu) ? (int) $menu->term_id : (int) $menu;
    return isset($GLOBALS['_mcpwp_test_nav_menus'][ $id ]) ? $GLOBALS['_mcpwp_test_nav_menus'][ $id ] : false;
}

// Capture the item data WordPress would persist so tests can assert the
// menu-item-type / object / object-id contract without a real DB.
function wp_update_nav_menu_item($menu_id, $item_id, $item_data)
{
    if (! isset($GLOBALS['_mcpwp_test_nav_menu_item_writes'])) {
        $GLOBALS['_mcpwp_test_nav_menu_item_writes'] = array();
    }
    $GLOBALS['_mcpwp_test_nav_menu_item_writes'][] = array(
        'menu_id' => (int) $menu_id,
        'item_id' => (int) $item_id,
        'data'    => $item_data,
    );
    return 1000 + count($GLOBALS['_mcpwp_test_nav_menu_item_writes']);
}

function get_post_meta($post_id, $key = '', $single = false)
{
    $post_id = (int) $post_id;
    if ('' === $key) {
        // Core returns every key as a LIST of values (get_post_custom shape),
        // not key => value; a double that flattened it hid a count() on a
        // string in the meta listing (#797 round 4).
        $all = isset($GLOBALS['_mcpwp_test_meta'][ $post_id ]) ? $GLOBALS['_mcpwp_test_meta'][ $post_id ] : array();
        $out = array();
        foreach ($all as $k => $v) {
            $out[ $k ] = array($v);
        }
        return $out;
    }
    if (isset($GLOBALS['_mcpwp_test_meta'][ $post_id ][ $key ])) {
        return $GLOBALS['_mcpwp_test_meta'][ $post_id ][ $key ];
    }
    return $single ? '' : array();
}

function update_post_meta($post_id, $key, $value)
{
    // A seeded failure, so #873 can prove its `finally` releases the scoped
    // unfiltered_html grant when the write throws rather than returns. Off
    // unless a test asks for it.
    if (! empty($GLOBALS['mcpwp_test_update_post_meta_throws'])) {
        throw new \RuntimeException('seeded meta failure');
    }

    // Mirror WP core, which unslashes on write (update_metadata() calls
    // wp_unslash on the value) so get_post_meta() returns clean data.
    //
    // Storing the slashed value verbatim meant every _elementor_data write in
    // tests read back as un-decodable JSON, so set_elementor_data()'s
    // readback verification always failed with meta_write_truncated. Nothing
    // caught it because the one test file covering that path was itself never
    // registered in phpunit.xml.
    // A host that HTML-escapes meta on write can be simulated for tests that
    // need it (see KitCssChildCombinatorTest). Off by default so no existing
    // test changes behaviour.
    // Core's update_metadata() runs sanitize_meta(), which applies a
    // registered key's sanitize_callback. Without this the harness could not
    // tell whether a callback ran.
    if (function_exists('sanitize_meta')) {
        $post = get_post((int) $post_id);
        $value = sanitize_meta($key, $value, 'post', ($post && isset($post->post_type)) ? $post->post_type : '');
    }
    if (! empty($GLOBALS['mcpwp_test_meta_escapes_html'])) {
        $value = mcpwp_test_escape_meta_deep($value);
    }

    // A meta write that silently does nothing — a host with a broken object
    // cache, a filter that vetoes the write. Code that trusts its own write
    // without reading it back cannot tell this apart from success (#785).
    if (! empty($GLOBALS['mcpwp_test_meta_write_fails'])
        && in_array($key, (array) $GLOBALS['mcpwp_test_meta_write_fails'], true)) {
        return false;
    }

    // Mirror WP core's update_metadata(): a key that has no existing row
    // yet is delegated to add_metadata() internally, which fires
    // add_post_meta/added_post_meta instead of update_post_meta/
    // updated_post_meta. Content guardrails (#957 round 3) hooks all three
    // of these real WordPress core meta actions to advisory-check an
    // already-published post's _elementor_data edit — a double this class
    // never fired at all made that path untestable. The same
    // added_post_meta/updated_post_meta pair is also what
    // Mcpwp_Graph_Builder::on_post_meta_changed() (#943 S1 review, W3)
    // listens for, so both consumers are exercised by firing all four real
    // core actions rather than a synthetic subset of either one's needs.
    $post_id_int = (int) $post_id;
    $existed     = isset($GLOBALS['_mcpwp_test_meta'][ $post_id_int ][ $key ]);
    $meta_id     = 1;

    do_action($existed ? 'update_post_meta' : 'add_post_meta', $meta_id, $post_id_int, $key, $value);

    $GLOBALS['_mcpwp_test_meta'][ $post_id_int ][ $key ] = wp_unslash($value);

    do_action($existed ? 'updated_post_meta' : 'added_post_meta', $meta_id, $post_id_int, $key, $value);

    return true;
}

function mcpwp_test_escape_meta_deep($value)
{
    if (is_string($value)) {
        return str_replace(array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $value);
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[ $k ] = mcpwp_test_escape_meta_deep($v);
        }
    }
    return $value;
}

if (! function_exists('wp_rand')) {
    function wp_rand($min = 0, $max = 0)
    {
        if (0 === $min && 0 === $max) {
            $max = PHP_INT_MAX;
        }
        return random_int((int) $min, (int) $max);
    }
}

if (! function_exists('wp_upload_dir')) {
    // Minimal shape: the writer trait reads ['basedir'] to report generated
    // CSS file size after a save. A temp dir keeps that read harmless.
    function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false)
    {
        $base = sys_get_temp_dir() . '/mcpwp-test-uploads';
        return array(
            'path'    => $base,
            'url'     => 'http://example.test/wp-content/uploads',
            'subdir'  => '',
            'basedir' => $base,
            'baseurl' => 'http://example.test/wp-content/uploads',
            'error'   => false,
        );
    }
}

function delete_post_meta($post_id, $key, $value = '')
{
    if (! isset($GLOBALS['_mcpwp_test_meta'][ (int) $post_id ][ $key ])) {
        return false;
    }
    unset($GLOBALS['_mcpwp_test_meta'][ (int) $post_id ][ $key ]);
    do_action('deleted_post_meta', array(0), (int) $post_id, $key, $value);
    return true;
}

if (! function_exists('get_page_template_slug')) {
    // Mirrors WP core: '' when unset/'default', else the stored template slug.
    function get_page_template_slug($post = null)
    {
        $post = is_object($post) ? $post : get_post($post);
        if (! $post) {
            return false;
        }
        $template = get_post_meta($post->ID, '_wp_page_template', true);
        if (! $template || 'default' === $template) {
            return '';
        }
        return $template;
    }
}

function sanitize_html_class($class)
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $class);
}

function admin_url($path = '')
{
    return 'https://example.com/wp-admin/' . ltrim($path, '/');
}

function trailingslashit($string)
{
    return rtrim((string) $string, '/\\') . '/';
}

if (! function_exists('get_home_path')) {
    // Real WP: derives the filesystem path to the site's home URL, which can
    // differ from ABSPATH when WordPress is installed in a subdirectory.
    // Defaults to ABSPATH (this double has no such split by default) but a
    // test can set $GLOBALS['mcpwp_test_home_path'] to a DIFFERENT directory
    // to prove a code path genuinely calls get_home_path() rather than
    // reading ABSPATH directly (#937 NOTE-3). Defining this here also means
    // llms_txt_physical_file_exists()'s `function_exists('get_home_path')`
    // guard never tries to require a real wp-admin/includes/file.php that
    // doesn't exist in this harness.
    function get_home_path()
    {
        return $GLOBALS['mcpwp_test_home_path'] ?? ABSPATH;
    }
}

function untrailingslashit($string)
{
    return rtrim((string) $string, '/\\');
}

if (! function_exists('add_query_arg')) {
    /**
     * Minimal stand-in for WP's add_query_arg(). Supports both call forms:
     * add_query_arg($key, $value, $url) and add_query_arg($array, $url).
     * Mirrors real WP behavior of NOT urlencoding query values (WP's
     * build_query() intentionally skips encoding for backcompat), so callers
     * that need encoded values must pre-encode them (as production code does).
     */
    function add_query_arg(...$args)
    {
        if (count($args) >= 3) {
            $params = array( $args[0] => $args[1] );
            $url    = (string) $args[2];
        } else {
            $params = is_array($args[0]) ? $args[0] : array();
            $url    = isset($args[1]) ? (string) $args[1] : '';
        }

        $frag = '';
        $hash_pos = strpos($url, '#');
        if (false !== $hash_pos) {
            $frag = substr($url, $hash_pos);
            $url  = substr($url, 0, $hash_pos);
        }

        $query_pos = strpos($url, '?');
        if (false !== $query_pos) {
            $base         = substr($url, 0, $query_pos);
            $query_string = substr($url, $query_pos + 1);
        } else {
            $base         = $url;
            $query_string = '';
        }

        parse_str($query_string, $query);
        foreach ($params as $key => $value) {
            if (null === $value || false === $value) {
                unset($query[ $key ]);
            } else {
                $query[ $key ] = $value;
            }
        }

        $pairs = array();
        foreach ($query as $key => $value) {
            $pairs[] = '' === $value ? $key : $key . '=' . $value;
        }

        return $base . ( empty($pairs) ? '' : '?' . implode('&', $pairs) ) . $frag;
    }
}

function wp_slash($value)
{
    return is_string($value) ? addslashes($value) : $value;
}

// -------------------------------------------------------------------------
// Freemius double. Mcpwp_License treats Freemius as the single source of
// truth via mcpwp_get_fs_instance(). Tests drive entitlement state by setting
// $GLOBALS['mcpwp_test_fs'] to a Mcpwp_Test_Fs_Stub (or null for "no Freemius").
// -------------------------------------------------------------------------

$GLOBALS['mcpwp_test_fs'] = null;

function mcpwp_get_fs_instance()
{
    return $GLOBALS['mcpwp_test_fs'];
}

class Mcpwp_Test_Fs_Stub
{
    private $is_paying;
    private $is_trial;
    private $can_use_premium_code;
    private $plan;

    public function __construct(
        bool $can_use_premium_code = false,
        bool $is_paying = false,
        bool $is_trial = false,
        $plan = ''
    ) {
        $this->can_use_premium_code = $can_use_premium_code;
        $this->is_paying            = $is_paying;
        $this->is_trial             = $is_trial;
        $this->plan                 = $plan;
    }

    public function can_use_premium_code()
    {
        return $this->can_use_premium_code;
    }

    public function is_paying()
    {
        return $this->is_paying;
    }

    public function is_trial()
    {
        return $this->is_trial;
    }

    public function get_plan()
    {
        return $this->plan;
    }
}

// ── WebMCP (#981) wp-admin stubs ─────────────────────────────────────────

// Revisions (#981 review round 3: large rollback sources are referenced by
// revision id). $GLOBALS['mcpwp_test_revisions_disabled'] makes
// wp_save_post_revision() save nothing, like revisions being off.
if (! function_exists('wp_save_post_revision')) {
    function wp_save_post_revision($post_id)
    {
        if (! empty($GLOBALS['mcpwp_test_revisions_disabled'])) {
            return null;
        }
        $post = $GLOBALS['_mcpwp_test_posts'][ (int) $post_id ] ?? null;
        if (! $post) {
            return null;
        }
        $id = 900000 + count($GLOBALS['_mcpwp_test_posts']);
        while (isset($GLOBALS['_mcpwp_test_posts'][ $id ])) {
            $id++;
        }
        $GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
            array( 'ID' => $id, 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => (int) $post_id, 'post_content' => $post->post_content )
        );
        return $id;
    }
}
if (! function_exists('wp_revisions_to_keep')) {
    function wp_revisions_to_keep($post)
    {
        return (int) ($GLOBALS['mcpwp_test_revisions_to_keep'] ?? -1);
    }
}
if (! function_exists('wp_get_post_revisions')) {
    function wp_get_post_revisions($post_id = 0, $args = null)
    {
        $out = array();
        foreach ($GLOBALS['_mcpwp_test_posts'] as $id => $post) {
            if ('revision' === $post->post_type && (int) $post->post_parent === (int) $post_id) {
                $out[ $id ] = $post;
            }
        }
        krsort($out);
        return $out;
    }
}

if (! function_exists('site_url')) {
    function site_url($path = '', $scheme = null)
    {
        return 'https://example.com' . ('' !== (string) $path ? '/' . ltrim((string) $path, '/') : '');
    }
}

if (! function_exists('is_network_admin')) {
    function is_network_admin()
    {
        return false;
    }
}

$GLOBALS['mcpwp_test_enqueued_scripts'] = array();
$GLOBALS['mcpwp_test_inline_scripts']   = array();

if (! function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $args = array())
    {
        $GLOBALS['mcpwp_test_enqueued_scripts'][ $handle ] = $src;
    }
}

if (! function_exists('wp_add_inline_script')) {
    function wp_add_inline_script($handle, $data, $position = 'after')
    {
        $GLOBALS['mcpwp_test_inline_scripts'][] = array( 'handle' => $handle, 'data' => $data, 'position' => $position );
        return true;
    }
}

// ── Admin / AJAX stubs for auth trait bypass tests ───────────────────────

$GLOBALS['mcpwp_test_is_admin']             = false;
$GLOBALS['mcpwp_test_doing_ajax']           = false;
$GLOBALS['mcpwp_test_ajax_referer_valid']   = false;
$GLOBALS['mcpwp_test_post_action']          = '';

if (! function_exists('is_admin')) {
    function is_admin()
    {
        return (bool) $GLOBALS['mcpwp_test_is_admin'];
    }
}

if (! function_exists('wp_doing_ajax')) {
    // Deliberately NOT "defined('DOING_AJAX') && DOING_AJAX" — DOING_AJAX
    // is a raw, permanent PHP constant once any test defines it (PHP
    // cannot undefine a constant), and this suite runs many hundreds of
    // "requests" in one shared process. A test file that legitimately
    // needs DOING_AJAX true (see AdminChatToolExecutionAuthTest.php,
    // which reads the constant directly, not through this function) would
    // otherwise leak that into every OTHER test in every OTHER file that
    // runs afterward in the same process, including ones asserting the
    // opposite. This function is used ONLY by content guardrails'
    // is_genuine_admin_human_apply() (#957 round 4 WARN-2) — nothing else
    // in this codebase calls wp_doing_ajax() — so it is free to use a
    // per-test-resettable global instead, isolated from that constant.
    function wp_doing_ajax()
    {
        return (bool) ($GLOBALS['mcpwp_test_doing_ajax'] ?? false);
    }
}

// Note: DOING_AJAX is a PHP constant. Tests that need the bypass path to be
// reached must define DOING_AJAX = true before the first call. Tests that run
// WITHOUT the bypass active (the common case) rely on DOING_AJAX being undefined
// (the condition `defined('DOING_AJAX') && DOING_AJAX` evaluates to false).

if (! function_exists('check_ajax_referer')) {
    /**
     * Stub: returns truthy/falsy based on $GLOBALS['mcpwp_test_ajax_referer_valid'].
     * Never calls wp_die() in tests.
     *
     * Does not itself validate $action against anything (there is no real
     * nonce here to bind an action string to) — but it records the action
     * string it was called with in mcpwp_test_last_ajax_referer_action so a
     * test can assert production code checked the RIGHT action string
     * (e.g. #607: is_trusted_admin_ajax_request() once checked a nonce
     * action ('mcpwp_admin_chat') that was never the one actually minted
     * ('mcpwp_admin_nonce'), which this stub's boolean-only return would not
     * have caught on its own).
     */
    function check_ajax_referer($action, $query_arg = false, $die = true)
    {
        $GLOBALS['mcpwp_test_last_ajax_referer_action'] = $action;
        return (bool) $GLOBALS['mcpwp_test_ajax_referer_valid'];
    }
}

if (! function_exists('sodium_memzero') && ! extension_loaded('sodium')) {
    function sodium_memzero(&$var)
    {
        $var = str_repeat("\0", strlen($var));
    }
}

require_once dirname(__DIR__) . '/includes/traits/trait-mcpwp-api-auth.php';
require_once dirname(__DIR__) . '/includes/traits/trait-mcpwp-sanitization.php';
require_once dirname(__DIR__) . '/includes/traits/trait-mcpwp-logging.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-agent-text.php';
require_once dirname(__DIR__) . '/includes/class-mcpwp-rate-limiter.php';
require_once dirname(__DIR__) . '/includes/class-mcpwp-operation-weights.php';
require_once dirname(__DIR__) . '/includes/class-mcpwp-spend-budget.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-event-store.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-site-state.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-agent-playbooks.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-content-coherence.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-audit-store.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-autofix.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-search-performance.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-flags.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-woocommerce-seo.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-pages.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-posts.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-drafts.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-tool-annotations.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-tool-registry.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-meta-tools.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-output-schema.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-next-step-hints.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-custom-tool-registry.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-site.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-content.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-media.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-elementor.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-blocks.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-ops.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-ai-presence.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-graph.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-free-tools.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-pro-tools-seo.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-pro-tools-forms.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-pro-tools-elementor.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-pro-tools-menus.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-pro-tools-commerce.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-pro-tools-network.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-pro-tools-staging.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-pro-tools.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-integration.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-mcp.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-webmcp.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-webmcp-integration.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-webmcp.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-pages.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-site-updates.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-media.php';
require_once dirname(__DIR__) . '/includes/class-mcpwp-duplicate-copy.php';
require_once dirname(__DIR__) . '/includes/class-mcpwp-loader.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-menus.php';
// The single definition of which Elementor settings keys are wrong. Loaded
// before the validator and before every _elementor_data write path (#737).
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-public-route.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-elementor-page-settings.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-slug-search.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-idempotency-claim.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-post-fields.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-option-access.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-destructive-gate.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-elementor-registry.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-elementor-element-keys.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-elementor-element-ids.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-elementor-stored-tree.php';
// Site graph (#943 S1): class-mcpwp-rest-api.php's content-graph extraction
// helpers below delegate to these — loaded before that require so every test
// exercising them (build_content_graph_data() and friends) has a real target.
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-graph-builder.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-graph-signals.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-graph-reader.php';
// The one reader of an Elementor experiment's active/inactive state (#883) —
// loaded before the writer trait and class-mcpwp-core.php, both of which
// call it.
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-elementor-experiments.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-elementor-sanitizer-guard.php';
require_once dirname(__DIR__) . '/includes/core/traits/trait-mcpwp-elementor-reader.php';
require_once dirname(__DIR__) . '/includes/core/traits/trait-mcpwp-elementor-writer.php';
require_once dirname(__DIR__) . '/includes/core/traits/trait-mcpwp-elementor-validator.php';
require_once dirname(__DIR__) . '/includes/core/traits/trait-mcpwp-elementor-css.php';
// The validator resolves valid control keys through the widget registry, so a
// save that reaches widget-level validation fatals without it.
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-elementor-widgets.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-elementor-basic.php';
require_once dirname(__DIR__) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
// Theme Builder: display-condition parsing is pure and unit-testable even
// though the save path needs Elementor Pro (#785).
require_once dirname(__DIR__) . '/includes/pro/core/class-mcpwp-theme-builder.php';
require_once dirname(__DIR__) . '/includes/class-mcpwp-license.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-core.php';
require_once dirname(__DIR__) . '/includes/pro/class-mcpwp-pro-bootstrap.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-signals.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-signals.php';
// OAuth 2.1 server (#531).
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-site-settings.php';
require_once dirname(__DIR__) . '/includes/class-mcpwp-oauth-well-known.php';
// Encryption + migration classes.
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-integration-manager.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption-migration.php';

// Escaping helpers — no-ops for tests, but required by any class that renders
// output (Site Health results, admin notices).
if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        return esc_html($text);
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return esc_html($text);
    }
}

// Update channel health (#716 follow-up) — depends on the updater above.
require_once dirname(__DIR__) . '/includes/class-mcpwp-security.php';
require_once dirname(__DIR__) . '/includes/class-mcpwp-updater.php';
require_once dirname(__DIR__) . '/includes/class-mcpwp-update-channel-health.php';

// DataForSEO market data provider.
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-provider-dataforseo.php';

// Keyword research (autocomplete + DataForSEO enrichment).
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-keyword-research.php';

// Search visibility snapshot store — market position, separate from
// first-party Search Console performance (Mcpwp_Search_Performance above).
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-visibility.php';

// Maturity-aware opportunity ranker — fuses visibility, keyword metrics and
// audit issues into a ranked list of SEO work worth doing.
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-opportunities.php';

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

// Cache for paid SEO lookups — bounds agent-loop spend on DataForSEO credit.
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-cache.php';

// AI presence: llms.txt/robots.txt coexistence, AI crawler policy, IndexNow.
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-plugin-detect.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-ai-crawler-policy.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-indexnow.php';
require_once dirname(__DIR__) . '/includes/class-mcpwp-ai-presence.php';

// ---------------------------------------------------------------------------
// Taxonomy, term, registered-meta and thumbnail doubles (#797).
//
// Registry: $GLOBALS['_mcpwp_test_taxonomies'][ $tax ] = array(
//     'object_type' => array( 'post', 'sm_team' ),
//     'terms'       => array( 1522 => array( 'slug' => 'k12', 'name' => 'K-12' ) ),
// );
// Assignments: $GLOBALS['_mcpwp_test_object_terms'][ $post_id ][ $tax ] = array( ids ).
// Registered meta: $GLOBALS['_mcpwp_test_registered_meta'][ $key ] = array(
//     'object_subtype' => 'sm_team', 'sanitize_callback' => callable|null );
// ---------------------------------------------------------------------------
if (! function_exists('get_post_type')) {
    function get_post_type($post = null)
    {
        $post = is_object($post) ? $post : get_post((int) $post);
        return $post ? $post->post_type : false;
    }
}
if (! function_exists('get_taxonomy')) {
    // Registered via $GLOBALS['_mcpwp_test_taxonomies'][ $tax ]; 'cap' in
    // that entry overrides core's defaults (assign_terms edit_posts,
    // edit_terms manage_categories). Read by WebMCP (#981).
    function get_taxonomy($taxonomy)
    {
        $tax = $GLOBALS['_mcpwp_test_taxonomies'][ $taxonomy ] ?? null;
        if (null === $tax) {
            return false;
        }
        return (object) array(
            'name' => $taxonomy,
            'cap'  => (object) array_merge(
                array( 'assign_terms' => 'edit_posts', 'edit_terms' => 'manage_categories' ),
                (array) ($tax['cap'] ?? array())
            ),
        );
    }
}
if (! function_exists('term_exists')) {
    function term_exists($term, $taxonomy = '')
    {
        return in_array((string) $term, (array) ($GLOBALS['_mcpwp_test_existing_terms'][ $taxonomy ] ?? array()), true) ? array( 'term_id' => 1 ) : null;
    }
}
if (! function_exists('taxonomy_exists')) {
    function taxonomy_exists($taxonomy)
    {
        return isset($GLOBALS['_mcpwp_test_taxonomies'][ $taxonomy ]);
    }
}
if (! function_exists('is_object_in_taxonomy')) {
    function is_object_in_taxonomy($object_type, $taxonomy)
    {
        $tax = $GLOBALS['_mcpwp_test_taxonomies'][ $taxonomy ] ?? null;
        return $tax && in_array($object_type, (array) ($tax['object_type'] ?? array()), true);
    }
}
if (! function_exists('get_term')) {
    function get_term($term_id, $taxonomy = '')
    {
        foreach ($GLOBALS['_mcpwp_test_taxonomies'] ?? array() as $name => $tax) {
            if ('' !== $taxonomy && $name !== $taxonomy) {
                continue;
            }
            if (isset($tax['terms'][ (int) $term_id ])) {
                $t = $tax['terms'][ (int) $term_id ];
                return (object) array( 'term_id' => (int) $term_id, 'slug' => $t['slug'], 'name' => $t['name'], 'taxonomy' => $name );
            }
        }
        return null;
    }
}
if (! function_exists('get_term_by')) {
    function get_term_by($field, $value, $taxonomy = '')
    {
        $tax = $GLOBALS['_mcpwp_test_taxonomies'][ $taxonomy ] ?? null;
        if (! $tax) {
            return false;
        }
        foreach ($tax['terms'] ?? array() as $id => $t) {
            if (('id' === $field && (int) $value === (int) $id) || ('slug' === $field && $t['slug'] === $value) || ('name' === $field && $t['name'] === $value)) {
                return (object) array( 'term_id' => (int) $id, 'slug' => $t['slug'], 'name' => $t['name'], 'taxonomy' => $taxonomy );
            }
        }
        return false;
    }
}
if (! function_exists('wp_set_object_terms')) {
    function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false)
    {
        $ids = array_map('intval', (array) $terms);
        if ($append) {
            $ids = array_values(array_unique(array_merge($GLOBALS['_mcpwp_test_object_terms'][ $object_id ][ $taxonomy ] ?? array(), $ids)));
        }
        $GLOBALS['_mcpwp_test_object_terms'][ $object_id ][ $taxonomy ] = $ids;
        return $ids;
    }
}
if (! function_exists('wp_get_object_terms')) {
    function wp_get_object_terms($object_ids, $taxonomies, $args = array())
    {
        $out = array();
        foreach ((array) $object_ids as $oid) {
            foreach ((array) $taxonomies as $tax) {
                foreach ($GLOBALS['_mcpwp_test_object_terms'][ $oid ][ $tax ] ?? array() as $id) {
                    $term = get_term($id, $tax);
                    if ($term) {
                        $out[] = $term;
                    }
                }
            }
        }
        return $out;
    }
}
if (! function_exists('wp_set_post_categories')) {
    function wp_set_post_categories($post_id, $categories = array())
    {
        return wp_set_object_terms($post_id, $categories, 'category');
    }
}
if (! function_exists('wp_set_post_tags')) {
    function wp_set_post_tags($post_id, $tags = '', $append = false)
    {
        $GLOBALS['_mcpwp_test_object_terms'][ $post_id ]['post_tag'] = (array) $tags;
        return (array) $tags;
    }
}
if (! function_exists('wp_get_post_categories')) {
    function wp_get_post_categories($post_id, $args = array())
    {
        return $GLOBALS['_mcpwp_test_object_terms'][ $post_id ]['category'] ?? array();
    }
}
if (! function_exists('wp_get_post_tags')) {
    function wp_get_post_tags($post_id, $args = array())
    {
        return array();
    }
}
if (! function_exists('registered_meta_key_exists')) {
    function registered_meta_key_exists($object_type, $meta_key, $object_subtype = '')
    {
        $reg = $GLOBALS['_mcpwp_test_registered_meta'][ $meta_key ] ?? null;
        if (! $reg) {
            return false;
        }
        return '' === ($reg['object_subtype'] ?? '') || $reg['object_subtype'] === $object_subtype;
    }
}
if (! function_exists('get_registered_meta_keys')) {
    function get_registered_meta_keys($object_type, $object_subtype = '')
    {
        $out = array();
        foreach ($GLOBALS['_mcpwp_test_registered_meta'] ?? array() as $key => $reg) {
            $sub = $reg['object_subtype'] ?? '';
            if ('' === $sub || $sub === $object_subtype) {
                $out[ $key ] = array( 'sanitize_callback' => $reg['sanitize_callback'] ?? null );
            }
        }
        return $out;
    }
}
if (! function_exists('is_protected_meta')) {
    function is_protected_meta($meta_key, $meta_type = '')
    {
        return '_' === substr((string) $meta_key, 0, 1);
    }
}
if (! function_exists('sanitize_meta')) {
    function sanitize_meta($meta_key, $meta_value, $object_type, $object_subtype = '')
    {
        $reg = $GLOBALS['_mcpwp_test_registered_meta'][ $meta_key ] ?? null;
        if ($reg && ! empty($reg['sanitize_callback']) && is_callable($reg['sanitize_callback'])) {
            return call_user_func($reg['sanitize_callback'], $meta_value);
        }
        return $meta_value;
    }
}
if (! function_exists('set_post_thumbnail')) {
    function set_post_thumbnail($post, $thumbnail_id)
    {
        $id = is_object($post) ? $post->ID : (int) $post;
        update_post_meta($id, '_thumbnail_id', (int) $thumbnail_id);
        return true;
    }
}
if (! function_exists('delete_post_thumbnail')) {
    function delete_post_thumbnail($post)
    {
        $id = is_object($post) ? $post->ID : (int) $post;
        delete_post_meta($id, '_thumbnail_id');
        return true;
    }
}
if (! function_exists('wp_get_attachment_image_src')) {
    function wp_get_attachment_image_src($attachment_id, $size = 'thumbnail')
    {
        return array( 'https://example.test/media/' . (int) $attachment_id . '.jpg', 100, 100, false );
    }
}

// Site-info surface (#906): lets Mcpwp_Core::get_site_info() — the real
// wp_site_info result — run in-process so its declared outputSchema is
// validated against what the code actually produces.
if (! function_exists('wp_get_theme')) {
    function wp_get_theme()
    {
        return new class {
            public function get($header)
            {
                // Like WP_Theme::get(): false for a header it cannot read. A
                // test simulates a deleted/broken active theme by setting
                // mcpwp_test_theme_headers to array().
                $headers = isset($GLOBALS['mcpwp_test_theme_headers'])
                    ? $GLOBALS['mcpwp_test_theme_headers']
                    : array( 'Name' => 'Twenty Twenty-Five', 'Version' => '1.2' );
                return isset($headers[ $header ]) ? $headers[ $header ] : false;
            }
        };
    }
}

if (! function_exists('wp_timezone_string')) {
    function wp_timezone_string()
    {
        return 'UTC';
    }
}

if (! function_exists('get_locale')) {
    function get_locale()
    {
        return 'en_US';
    }
}
