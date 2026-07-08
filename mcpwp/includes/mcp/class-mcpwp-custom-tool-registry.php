<?php
/**
 * Custom Tool Registry — mcpwp_register_tools hook API (the gateway contract)
 *
 * Lets any plugin, theme, or owner register MCP tools without extending a PHP
 * class. Tools are collected via the `mcpwp_register_tools` WordPress filter and
 * validated/normalized against a formal contract before they enter dispatch.
 *
 * This is the seam third-party + owner-authored addons register through. A tool
 * is a pointer to a handler (`rest_path`, local or remote), gated at the single
 * dispatch choke point by tier, category/scope, capability, and rate — the same
 * gates first-party tools pass. See docs/adr/0001-extension-architecture.md.
 *
 * Usage in any plugin or theme:
 *
 *     add_filter( 'mcpwp_register_tools', function( $tools ) {
 *         $tools[] = [
 *             'name'        => 'digid_list_listings',
 *             'description' => 'List active real estate listings.',
 *             'category'    => 'listings',
 *             'rest_path'   => '/digid/v1/listings',
 *             'method'      => 'GET',
 *             'tier'        => 'free',          // free | pro
 *             'capability'  => '',              // required site capability, e.g. 'woocommerce'
 *             'input_props' => [
 *                 'per_page' => [ 'type' => 'integer', 'description' => 'Items per page.' ],
 *                 'status'   => [ 'type' => 'string',  'description' => 'Filter by status.' ],
 *             ],
 *         ];
 *         return $tools;
 *     } );
 *
 * Tool definition keys (the contract):
 *   name        (string, required) — unique tool name, plugin_prefix_action format
 *   description (string, required) — one sentence, plain English
 *   rest_path   (string, required) — full WP REST route '/digid/v1/listings'
 *                                    or MCPWP-relative '/my-endpoint' (gets /mcpwp/v1 prepended)
 *   method      (string)          — HTTP method GET|POST|PUT|PATCH|DELETE (default GET)
 *   category    (string)          — category slug for scope/toggle (default 'custom')
 *   tier        (string)          — 'free' | 'pro' (default 'free'). Pro tools are hidden
 *                                    and undispatched on sites without an active pro license.
 *   capability  (string)          — required site capability (e.g. 'woocommerce', 'elementor').
 *                                    Tool is gated off when the capability is inactive.
 *   version     (string)          — addon-defined version string (informational)
 *   input_props (array)           — tool parameters, same format as define_tool()
 *   destructive (bool)            — hint: deletes / irreversibly modifies data (default false)
 *   open_world  (bool)            — hint: calls external services (default false)
 *   param_remap (array)           — map MCP param names to REST param names if they differ
 *
 * @package mumcp
 * @since   2.8.49
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects, validates, and exposes tools registered via mcpwp_register_tools.
 */
class Mcpwp_Custom_Tool_Registry extends Mcpwp_MCP_Tool_Registry {

	/**
	 * Resolved + normalized tool registrations, keyed by tool name.
	 *
	 * @var array[]|null
	 */
	private $registrations = null;

	/**
	 * Count of registrations rejected by the contract validator in the last resolve.
	 *
	 * @var int
	 */
	private $rejected_count = 0;

	/**
	 * Reserved tool names a registration may NOT claim (first-party tool names).
	 * Prevents a registered tool from shadowing a built-in's route.
	 *
	 * @var string[]
	 */
	private $reserved_names = array();

	/**
	 * Declare names that registrations may not use (built-in tool names).
	 * Invalidates the resolve cache.
	 *
	 * @param string[] $names Reserved tool names.
	 * @return void
	 */
	public function set_reserved_names( array $names ) {
		$this->reserved_names = array_flip( array_map( 'sanitize_key', $names ) );
		$this->registrations  = null;
	}

	/**
	 * Whether a rest_path is a safe target for a registered tool.
	 *
	 * Registered tools may only point at their own / namespaced REST routes —
	 * never at WordPress core routes or mumcp's own privileged surfaces, and
	 * never at an absolute URL. This is the gate that stops a registration from
	 * proxying a privileged endpoint as the API-agent user (SSRF / escalation).
	 *
	 * @param string $path Candidate rest_path.
	 * @return bool
	 */
	private function is_safe_rest_path( $path ) {
		if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] ) {
			return false;
		}
		// No scheme/host (absolute URL), no protocol-relative, no traversal,
		// no query/fragment smuggling.
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $path ) || 0 === strpos( $path, '//' ) ) {
			return false;
		}
		if ( false !== strpos( $path, '..' ) || false !== strpos( $path, '?' ) || false !== strpos( $path, '#' ) ) {
			return false;
		}
		// Allowed chars only (path segments + {placeholders}).
		if ( ! preg_match( '#^/[A-Za-z0-9._~/{}\-]+$#', $path ) ) {
			return false;
		}
		// Deny WordPress core / oEmbed namespaces.
		foreach ( array( '/wp/', '/oembed/' ) as $core ) {
			if ( 0 === stripos( $path, $core ) ) {
				return false;
			}
		}
		// Deny mumcp privileged surfaces (key/license/update/settings management).
		if ( preg_match( '#^/mcpwp/v[0-9]+/(api-keys|oauth|update|settings|options|option|integrations|rate-limit|favicon)\b#i', $path ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Validate + normalize one raw registration against the contract.
	 *
	 * @param mixed $entry Raw filter entry.
	 * @return array|null Normalized registration, or null if it fails the contract.
	 */
	private function normalize_registration( $entry ) {
		if ( ! is_array( $entry )
			|| empty( $entry['name'] )
			|| empty( $entry['description'] )
			|| empty( $entry['rest_path'] )
			|| ! $this->is_safe_rest_path( $entry['rest_path'] ) ) {
			return null;
		}

		$name = sanitize_key( $entry['name'] );
		if ( '' === $name ) {
			return null;
		}

		$method = isset( $entry['method'] ) ? strtoupper( (string) $entry['method'] ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			$method = 'GET';
		}

		$tier = isset( $entry['tier'] ) ? strtolower( (string) $entry['tier'] ) : 'free';
		if ( 'free' !== $tier && 'pro' !== $tier ) {
			$tier = 'free';
		}

		return array(
			'name'        => $name,
			'description' => sanitize_text_field( $entry['description'] ),
			'rest_path'   => $entry['rest_path'],
			'method'      => $method,
			'category'    => isset( $entry['category'] ) && is_string( $entry['category'] )
				? sanitize_key( $entry['category'] )
				: 'custom',
			'tier'        => $tier,
			'capability'  => isset( $entry['capability'] ) && is_string( $entry['capability'] )
				? sanitize_key( $entry['capability'] )
				: '',
			'version'     => isset( $entry['version'] ) ? sanitize_text_field( (string) $entry['version'] ) : '',
			'input_props' => isset( $entry['input_props'] ) && is_array( $entry['input_props'] )
				? $entry['input_props']
				: array(),
			'destructive' => ! empty( $entry['destructive'] ),
			'open_world'  => ! empty( $entry['open_world'] ),
			'param_remap' => $this->sanitize_param_remap( isset( $entry['param_remap'] ) ? $entry['param_remap'] : null ),
		);
	}

	/**
	 * Sanitize a param_remap map to a clean array of key => key.
	 *
	 * @param mixed $remap Raw remap.
	 * @return array<string, string>
	 */
	private function sanitize_param_remap( $remap ) {
		if ( ! is_array( $remap ) ) {
			return array();
		}
		$clean = array();
		foreach ( $remap as $from => $to ) {
			if ( is_string( $from ) && is_string( $to ) ) {
				$from_key = sanitize_key( $from );
				$to_key   = sanitize_key( $to );
				if ( '' !== $from_key && '' !== $to_key ) {
					$clean[ $from_key ] = $to_key;
				}
			}
		}
		return $clean;
	}

	/**
	 * Whether the site has an active pro license (mirrors the dispatcher's gate).
	 *
	 * @return bool
	 */
	private function is_pro() {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return false;
		}
		return class_exists( 'Mcpwp_License' ) && Mcpwp_License::get_instance()->is_pro();
	}

	/**
	 * Resolve, validate, dedupe, and tier-gate registrations from the filter.
	 *
	 * Rejected (contract-invalid) entries are dropped and counted. Duplicate
	 * names keep the first registration. Pro-tier tools are excluded entirely on
	 * sites without an active pro license, so they never appear in tools/list and
	 * cannot be dispatched.
	 *
	 * @return array[] Normalized registrations keyed by tool name.
	 */
	private function get_registrations() {
		if ( null !== $this->registrations ) {
			return $this->registrations;
		}

		/**
		 * Register custom MCP tools.
		 *
		 * Third-party plugins append tool definition arrays to this filter. Each
		 * definition must include 'name', 'description', and 'rest_path'.
		 *
		 * @param array[] $tools Existing tool registrations (starts empty).
		 * @return array[] Modified tool registrations.
		 */
		$raw = apply_filters( 'mcpwp_register_tools', array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$resolved             = array();
		$this->rejected_count = 0;
		$is_pro               = $this->is_pro();

		foreach ( $raw as $entry ) {
			$norm = $this->normalize_registration( $entry );
			if ( null === $norm ) {
				$this->rejected_count++;
				continue;
			}
			// A registration may not claim a built-in tool name (anti-shadowing).
			if ( isset( $this->reserved_names[ $norm['name'] ] ) ) {
				$this->rejected_count++;
				continue;
			}
			// First registration of a name wins; later collisions are rejected.
			if ( isset( $resolved[ $norm['name'] ] ) ) {
				$this->rejected_count++;
				continue;
			}
			// Pro-tier tools are invisible + undispatchable without a pro license.
			if ( 'pro' === $norm['tier'] && ! $is_pro ) {
				continue;
			}
			$resolved[ $norm['name'] ] = $norm;
		}

		$this->registrations = $resolved;
		return $this->registrations;
	}

	/**
	 * Number of registrations rejected by the contract validator on last resolve.
	 *
	 * @return int
	 */
	public function get_rejected_count() {
		$this->get_registrations();
		return $this->rejected_count;
	}

	/**
	 * Build MCP tool definitions from validated registrations.
	 *
	 * @return array Tool definitions.
	 */
	public function get_tools() {
		$tools = array();
		foreach ( $this->get_registrations() as $reg ) {
			$tools[] = $this->define_tool( $reg['name'], $reg['description'], $reg['input_props'] );
		}
		return $tools;
	}

	/**
	 * Build tool → REST route map from validated registrations.
	 *
	 * Each entry includes 'rest_path' so the dispatcher knows the full route,
	 * bypassing the default '/mcpwp/v1' namespace prepend.
	 *
	 * @return array Tool name → mapping array.
	 */
	public function get_tool_map() {
		$map = array();
		foreach ( $this->get_registrations() as $reg ) {
			$map[ $reg['name'] ] = array(
				'rest_path'   => $reg['rest_path'],
				'route'       => $reg['rest_path'],
				'method'      => $reg['method'],
				'param_remap' => $reg['param_remap'],
			);
		}
		return $map;
	}

	/**
	 * Build tool → category map from validated registrations.
	 *
	 * @return array Tool name → category slug.
	 */
	public function get_tool_categories() {
		$cats = array();
		foreach ( $this->get_registrations() as $reg ) {
			$cats[ $reg['name'] ] = $reg['category'];
		}
		return $cats;
	}

	/**
	 * Build tool → required-capability map from validated registrations.
	 *
	 * Lets a registered tool declare a site capability it needs (e.g.
	 * 'woocommerce'); the dispatcher gates the tool off when that capability is
	 * inactive — the same gate first-party tools pass.
	 *
	 * @return array Tool name → capability key.
	 */
	public function get_required_capabilities() {
		$reqs = array();
		foreach ( $this->get_registrations() as $reg ) {
			if ( '' !== $reg['capability'] ) {
				$reqs[ $reg['name'] ] = $reg['capability'];
			}
		}
		return $reqs;
	}

	/**
	 * Collect destructive tool names from validated registrations.
	 *
	 * @return string[] Tool names flagged as destructive.
	 */
	protected function get_destructive_tools() {
		$destructive = array();
		foreach ( $this->get_registrations() as $reg ) {
			if ( $reg['destructive'] ) {
				$destructive[] = $reg['name'];
			}
		}
		return $destructive;
	}

	/**
	 * Collect open-world tool names from validated registrations.
	 *
	 * @return string[] Tool names flagged as open_world.
	 */
	protected function get_open_world_tools() {
		$open_world = array();
		foreach ( $this->get_registrations() as $reg ) {
			if ( $reg['open_world'] ) {
				$open_world[] = $reg['name'];
			}
		}
		return $open_world;
	}
}
