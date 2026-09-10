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
$GLOBALS['mcpwp_test_current_user'] = 0;
// Dispatch spy for rest_do_request() below — records the route of every
// internal REST dispatch so tests can assert fan-out actually happened
// (or did not), not merely infer it from a stubbed response shape.
$GLOBALS['mcpwp_test_dispatched_routes'] = array();
// Per-user capability overrides for OAuth scope-clamping tests.
// Key: user_id (int), Value: array of capability => bool.
// When empty (or user_id not present), current_user_can() grants all caps.
$GLOBALS['mcpwp_test_user_caps'] = array();
$GLOBALS['mcpwp_test_users'] = array(
    (object) array( 'ID' => 2, 'roles' => array( 'mcpwp_api_agent' ) ),
);

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

}

class WP_REST_Server
{
    public const READABLE  = 'GET';
    public const CREATABLE = 'POST';
    public const EDITABLE  = 'POST,PUT,PATCH';
    public const DELETABLE = 'DELETE';
}

function __($text)
{
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
    return array_key_exists($name, $GLOBALS['mcpwp_test_options']) ? $GLOBALS['mcpwp_test_options'][ $name ] : $default;
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
    return wp_delete_post($post_id, false);
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

if (! function_exists('get_nav_menu_locations')) {
    function get_nav_menu_locations()
    {
        return array();
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
        return 'Example Site';
    }
    return '';
}

function is_multisite()
{
    return ! empty($GLOBALS['mcpwp_test_is_multisite']);
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
            return array();
        }
        public function get_row($query = '', $output = OBJECT, $y = 0)
        {
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
                $row = array(
                    'mcpwp_probe'  => '1',
                    'option_value' => $GLOBALS['mcpwp_test_wpdb_options'][ $m[1] ] ?? null,
                );
                return ARRAY_A === $output ? $row : (object) $row;
            }
            return null;
        }
        public function get_var($query = '', $x = 0, $y = 0)
        {
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
                $current = $GLOBALS['mcpwp_test_wpdb_options'][ $name ] ?? null;
                if (null === $current) {
                    return 0;
                }
                if (isset($where['option_value']) && (string) $where['option_value'] !== $current) {
                    return 0; // compare-and-swap miss
                }
                $GLOBALS['mcpwp_test_wpdb_options'][ $name ] = (string) $data['option_value'];
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
    return isset($GLOBALS['_mcpwp_test_posts'][ $id ]) ? $GLOBALS['_mcpwp_test_posts'][ $id ] : null;
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
    $posts = array_values($GLOBALS['_mcpwp_test_posts']);
    $types = isset($args['post_type']) ? (array) $args['post_type'] : array();
    $statuses = isset($args['post_status']) ? (array) $args['post_status'] : array();

    $posts = array_values(array_filter($posts, function ($post) use ($types, $statuses) {
        if (! empty($types) && ! in_array($post->post_type, $types, true)) {
            return false;
        }
        if (! empty($statuses) && ! in_array($post->post_status, $statuses, true)) {
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
    return in_array((string) $post_type, $known, true) || in_array((string) $post_type, (array) ($GLOBALS['mcpwp_test_public_post_types'] ?? array()), true);
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

if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '')
    {
        return true;
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
    function url_to_postid($url)
    {
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
    function get_page_by_path($path, $output = OBJECT, $post_type = 'page')
    {
        $types = (array) $post_type;
        $name  = basename(trim((string) $path, '/'));
        foreach ($GLOBALS['_mcpwp_test_posts'] as $post) {
            if ('publish' === $post->post_status
                && $post->post_name === $name
                && in_array($post->post_type, $types, true)) {
                return $post;
            }
        }
        return null;
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
    function get_object_taxonomies($object, $output = 'names')
    {
        return array();
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
        'name'   => $post_type,
        'public' => in_array((string) $post_type, array( 'post', 'page' ), true) || in_array((string) $post_type, (array) ($GLOBALS['mcpwp_test_public_post_types'] ?? array()), true),
    );
}

function wp_insert_post($postarr, $wp_error = false)
{
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

    return $post_id;
}

function wp_update_post($postarr, $wp_error = false)
{
    if (empty($postarr['ID']) || empty($GLOBALS['_mcpwp_test_posts'][ (int) $postarr['ID'] ])) {
        return $wp_error ? new WP_Error('not_found', 'Post not found.', array( 'status' => 404 )) : 0;
    }

    $post = $GLOBALS['_mcpwp_test_posts'][ (int) $postarr['ID'] ];
    foreach ($postarr as $key => $value) {
        if ('ID' === $key) {
            continue;
        }
        $post->{$key} = $value;
    }
    $post->post_modified = '2026-06-06 01:00:00';
    $post->post_modified_gmt = '2026-06-06 01:00:00';
    $GLOBALS['_mcpwp_test_posts'][ (int) $postarr['ID'] ] = $post;

    return (int) $postarr['ID'];
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
    return 0;
}

function wp_get_attachment_image_src($attachment_id, $size = 'thumbnail')
{
    return false;
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

    $GLOBALS['_mcpwp_test_meta'][ (int) $post_id ][ $key ] = wp_unslash($value);
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

// Note: DOING_AJAX is a PHP constant. Tests that need the bypass path to be
// reached must define DOING_AJAX = true before the first call. Tests that run
// WITHOUT the bypass active (the common case) rely on DOING_AJAX being undefined
// (the condition `defined('DOING_AJAX') && DOING_AJAX` evaluates to false).

if (! function_exists('check_ajax_referer')) {
    /**
     * Stub: returns truthy/falsy based on $GLOBALS['mcpwp_test_ajax_referer_valid'].
     * Never calls wp_die() in tests.
     */
    function check_ajax_referer($action, $query_arg = false, $die = true)
    {
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
require_once dirname(__DIR__) . '/includes/class-mcpwp-rate-limiter.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-event-store.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-site-state.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-agent-playbooks.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-content-coherence.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-audit-store.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-autofix.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-search-performance.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-woocommerce-seo.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-pages.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-tool-registry.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-custom-tool-registry.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-site.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-content.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-media.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-elementor.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-blocks.php';
require_once dirname(__DIR__) . '/includes/mcp/traits/trait-mcpwp-free-tools-ops.php';
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
if (! function_exists('get_object_taxonomies')) {
    function get_object_taxonomies($object_type, $output = 'names')
    {
        $out = array();
        foreach ($GLOBALS['_mcpwp_test_taxonomies'] ?? array() as $name => $tax) {
            if (in_array($object_type, (array) ($tax['object_type'] ?? array()), true)) {
                $out[] = $name;
            }
        }
        return $out;
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
