<?php
/**
 * REST endpoints for proactive site signals.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles /signals/* REST routes.
 */
class Mcpwp_REST_Signals extends Mcpwp_REST_API {

	public function register_routes() {
		// Get current signal feed.
		register_rest_route(
			$this->namespace,
			'/signals',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_signals' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'types' => array(
						'type'        => 'string',
						'default'     => '',
						'description' => 'Comma-separated signal types to filter.',
					),
					'since' => array(
						'type'        => 'string',
						'default'     => '',
						'description' => 'ISO 8601 timestamp — return signals detected after this time.',
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 200,
						'sanitize_callback' => 'absint',
					),
					'refresh' => array(
						'type'    => 'boolean',
						'default' => false,
						'description' => 'Recompute signals before returning.',
					),
				),
			)
		);

		// Trigger recompute.
		register_rest_route(
			$this->namespace,
			'/signals/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_signals' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'types' => array(
						'type'        => 'string',
						'default'     => '',
						'description' => 'Comma-separated signal types to recompute (empty = all).',
					),
				),
			)
		);

		// Get/update signal settings.
		register_rest_route(
			$this->namespace,
			'/signals/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	public function get_signals( WP_REST_Request $request ): WP_REST_Response {
		$meta = Mcpwp_Signals::get_meta();

		// Compute when explicitly asked, or lazily on first read — an empty
		// feed that has never been computed is indistinguishable from "no
		// issues" otherwise (cron is unreliable on low-traffic hosts). Both
		// paths run under the request time budget; types that don't fit are
		// skipped and reported as partial.
		if ( $request->get_param( 'refresh' ) || '' === $meta['last_computed'] ) {
			Mcpwp_Signals::compute( array(), Mcpwp_Signals::request_time_budget() );
			$meta = Mcpwp_Signals::get_meta();
		}

		$types = array_filter( array_map( 'trim', explode( ',', $request->get_param( 'types' ) ?? '' ) ) );
		$since = $request->get_param( 'since' ) ?? '';
		$limit = (int) $request->get_param( 'limit' );

		// Fail-closed at read: a read-only key never receives a signal's
		// draft/private source title or detail; only a privileged caller reveals.
		$signals = Mcpwp_Signals::get_signals( $types, $since, $limit, $this->requester_can_see_non_public() );

		$counts = array();
		foreach ( $signals as $s ) {
			$t = $s['type'] ?? 'unknown';
			$counts[ $t ] = ( $counts[ $t ] ?? 0 ) + 1;
		}

		$response = array(
			'signals'       => $signals,
			'count'         => count( $signals ),
			'by_type'       => $counts,
			'signal_types'  => Mcpwp_Signals::SIGNAL_TYPES,
			'last_computed' => '' !== $meta['last_computed'] ? $meta['last_computed'] : null,
			'partial'       => (bool) $meta['partial'],
		);
		if ( ! empty( $meta['skipped_types'] ) ) {
			$response['skipped_types'] = $meta['skipped_types'];
			$response['hint']          = 'Some signal types were skipped to stay within the request time budget. Call again with refresh=true and types=<skipped> to compute them.';
		}

		return new WP_REST_Response( $response, 200 );
	}

	public function refresh_signals( WP_REST_Request $request ): WP_REST_Response {
		$types = array_filter( array_map( 'trim', explode( ',', $request->get_param( 'types' ) ?? '' ) ) );
		$types = array_values( array_intersect( $types, Mcpwp_Signals::SIGNAL_TYPES ) );

		// compute() refreshes and persists the shared cache but returns the
		// freshly-computed signals RAW (unredacted) — returning that directly to
		// a read-only key would leak a draft/private post's title or detail
		// (e.g. seo_issue.entity_title). Never respond with compute()'s value.
		// Read the refreshed cache back through get_signals() — the SINGLE
		// redaction chokepoint every signal read passes — so refresh clears the
		// exact same fail-closed gate as GET /signals. $computed is used only for
		// its count (an integer, no source fields).
		$computed = Mcpwp_Signals::compute( $types, Mcpwp_Signals::request_time_budget() );
		$meta     = Mcpwp_Signals::get_meta();

		$signals = Mcpwp_Signals::get_signals( $types, '', 200, $this->requester_can_see_non_public() );

		return new WP_REST_Response(
			array(
				'success'       => true,
				'computed'      => count( $computed ),
				'signals'       => $signals,
				'last_computed' => '' !== $meta['last_computed'] ? $meta['last_computed'] : null,
				'partial'       => (bool) $meta['partial'],
				'skipped_types' => $meta['skipped_types'],
			),
			200
		);
	}

	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Mcpwp_Signals::get_settings(), 200 );
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid JSON body' ), 400 );
		}
		Mcpwp_Signals::save_settings( $body );
		return new WP_REST_Response( array( 'success' => true, 'settings' => Mcpwp_Signals::get_settings() ), 200 );
	}
}
