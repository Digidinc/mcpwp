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
// Per-user capability overrides for OAuth scope-clamping tests.
// Key: user_id (int), Value: array of capability => bool.
// When empty (or user_id not present), current_user_can() grants all caps.
$GLOBALS['mcpwp_test_user_caps'] = array();
$GLOBALS['mcpwp_test_users'] = array(
    (object) array( 'ID' => 2, 'roles' => array( 'mcpwp_api_agent' ) ),
);

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
        return $text;
    }
}

if (! function_exists('maybe_serialize')) {
    function maybe_serialize($data)
    {
        return is_array($data) || is_object($data) ? serialize($data) : $data;
    }
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

function sanitize_text_field($value)
{
    return trim((string) $value);
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
            return (bool) $caps[ $capability ];
        }
        // If the caps array exists but the key is absent, deny the cap.
        return false;
    }

    // Default: grant all capabilities to any logged-in user (original behaviour).
    return true;
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['mcpwp_test_options']) ? $GLOBALS['mcpwp_test_options'][ $name ] : $default;
}

function update_option($name, $value, $autoload = null)
{
    // Allow tests to simulate DB write failures for specific option names.
    if (! empty($GLOBALS['mcpwp_test_update_option_fail'][ $name ])) {
        return false;
    }
    $GLOBALS['mcpwp_test_options'][ $name ] = $value;
    return true;
}

function delete_option($name)
{
    unset($GLOBALS['mcpwp_test_options'][ $name ]);
    return true;
}

function set_transient($name, $value, $expiration)
{
    $GLOBALS['mcpwp_test_transients'][ $name ] = array(
        'value'   => $value,
        'expires' => time() + (int) $expiration,
    );
    return true;
}

function get_transient($name)
{
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
    return 'https://example.com';
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
    return false;
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

function wp_json_encode($value, $flags = 0)
{
    return json_encode($value, $flags);
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

function rest_do_request()
{
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
            return $query;
        }
        public function get_results($query = '', $output = ARRAY_A)
        {
            return array();
        }
        public function get_row($query = '', $output = OBJECT, $y = 0)
        {
            return null;
        }
        public function get_var($query = '', $x = 0, $y = 0)
        {
            return null;
        }
        public function get_col($query = '', $x = 0)
        {
            return array();
        }
        public function query($query)
        {
            return 0;
        }
        public function insert($table, $data, $format = null)
        {
            return 1;
        }
        public function update($table, $data, $where, $f = null, $wf = null)
        {
            return 1;
        }
        public function delete($table, $where, $where_format = null)
        {
            return 1;
        }
        public function replace($table, $data, $format = null)
        {
            return 1;
        }
        public function esc_like($text)
        {
            return (string) $text;
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
    return in_array((string) $post_type, $known, true);
}

function post_type_supports($post_type, $feature)
{
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
        'public' => in_array((string) $post_type, array( 'post', 'page' ), true),
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
            if (! empty($types) && ! in_array($post->post_type, $types, true)) {
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
            return true;
        }));

        $this->found_posts = count($posts);
        $per_page = isset($args['posts_per_page']) ? max(1, (int) $args['posts_per_page']) : count($posts);
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

function get_post_meta($post_id, $key = '', $single = false)
{
    $post_id = (int) $post_id;
    if ('' === $key) {
        return isset($GLOBALS['_mcpwp_test_meta'][ $post_id ]) ? $GLOBALS['_mcpwp_test_meta'][ $post_id ] : array();
    }
    if (isset($GLOBALS['_mcpwp_test_meta'][ $post_id ][ $key ])) {
        return $GLOBALS['_mcpwp_test_meta'][ $post_id ][ $key ];
    }
    return $single ? '' : array();
}

function update_post_meta($post_id, $key, $value)
{
    $GLOBALS['_mcpwp_test_meta'][ (int) $post_id ][ $key ] = $value;
    return true;
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
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-menus.php';
require_once dirname(__DIR__) . '/includes/core/traits/trait-mcpwp-elementor-reader.php';
require_once dirname(__DIR__) . '/includes/core/traits/trait-mcpwp-elementor-writer.php';
require_once dirname(__DIR__) . '/includes/core/traits/trait-mcpwp-elementor-validator.php';
require_once dirname(__DIR__) . '/includes/core/traits/trait-mcpwp-elementor-css.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-elementor-basic.php';
require_once dirname(__DIR__) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
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
