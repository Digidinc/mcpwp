<?php
/**
 * IndexNow: ping the shared IndexNow endpoint (relays to Bing, Yandex,
 * Naver, Seznam, Yep, Amazon — not Google) when a public post/page is
 * published or updated.
 *
 * Bing indexing matters beyond Bing itself: ChatGPT search leans heavily
 * on Bing's index, so IndexNow is the fastest freshness lever available
 * for ChatGPT/Copilot citations (see docs/scratchpad research, 2026-09-26).
 *
 * Coexistence: Rank Math ships its own IndexNow module. When that module
 * is active on this site, MCPWP defers to it entirely rather than
 * double-submitting the same URL to the same shared endpoint.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IndexNow key management, submission, and publish-hook wiring.
 */
class Mcpwp_IndexNow {

	const ENDPOINT             = 'https://api.indexnow.org/indexnow';
	const KEY_OPTION           = 'mcpwp_indexnow_key';
	const SETTINGS_OPTION      = 'mcpwp_indexnow_settings';
	const PENDING_OPTION       = 'mcpwp_indexnow_pending';
	const LAST_RESULT_OPTION   = 'mcpwp_indexnow_last_result';
	const FLUSH_HOOK           = 'mcpwp_indexnow_flush';
	const DEBOUNCE_SECONDS     = 60;
	const MAX_URLS_PER_REQUEST = 10000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'maybe_serve_key_file' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( self::FLUSH_HOOK, array( $this, 'flush' ) );
	}

	/**
	 * Get (or lazily generate) the site's IndexNow key: 32 lowercase hex
	 * characters, generated once and stored.
	 *
	 * @return string
	 */
	public function get_key() {
		$key = get_option( self::KEY_OPTION, '' );
		if ( is_string( $key ) && preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return $key;
		}

		$key = bin2hex( random_bytes( 16 ) );
		update_option( self::KEY_OPTION, $key );
		return $key;
	}

	/**
	 * The URL IndexNow key verification fetches.
	 *
	 * @return string
	 */
	public function key_url() {
		return home_url( '/' . $this->get_key() . '.txt' );
	}

	/**
	 * Serve the key file at /{key}.txt as text/plain.
	 *
	 * @param WP $wp WordPress environment.
	 * @return void
	 */
	public function maybe_serve_key_file( $wp ) {
		if ( ! isset( $wp->request ) ) {
			return;
		}

		$key     = $this->get_key();
		$request = trim( (string) $wp->request, '/' );
		if ( $key . '.txt' !== $request ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $key; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed 32-hex-char value, nothing to escape.
		exit;
	}

	/**
	 * Merged settings with defaults.
	 *
	 * @return array{enabled: bool, force_non_production: bool}
	 */
	public function get_settings() {
		$stored = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'enabled'              => ! isset( $stored['enabled'] ) || (bool) $stored['enabled'],
			'force_non_production' => ! empty( $stored['force_non_production'] ),
		);
	}

	/**
	 * Persist settings.
	 *
	 * @param array $updates Setting overrides.
	 * @return array Merged, persisted settings.
	 */
	public function update_settings( $updates ) {
		$current = $this->get_settings();
		if ( array_key_exists( 'enabled', $updates ) ) {
			$current['enabled'] = (bool) $updates['enabled'];
		}
		if ( array_key_exists( 'force_non_production', $updates ) ) {
			$current['force_non_production'] = (bool) $updates['force_non_production'];
		}
		update_option( self::SETTINGS_OPTION, $current );
		return $current;
	}

	/**
	 * Whether IndexNow should run at all right now: the off switch is
	 * respected first, then environment — production by default, any
	 * other environment only when explicitly forced.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = $this->get_settings();
		if ( ! $settings['enabled'] ) {
			return false;
		}

		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( 'production' !== $env && ! $settings['force_non_production'] ) {
			return false;
		}

		return true;
	}

	/**
	 * True when Rank Math's own IndexNow module is active and this site
	 * should defer to it rather than double-submit.
	 *
	 * Rank Math's module option key is a best-effort guess
	 * (`rank_math_general['IndexNow']`) rather than something confirmed
	 * against Rank Math's own source, so it is exposed as a filter a site
	 * (or a test) can correct without waiting on an MCPWP release.
	 *
	 * @return bool
	 */
	public function defer_to_rankmath() {
		$active  = Mcpwp_SEO_Plugin_Detect::is_rankmath_active();
		$general = get_option( 'rank_math_general', array() );
		// The filter runs unconditionally (not only when $active) so a test —
		// or a site whose Rank Math version stores this differently — can
		// assert the deferral behavior without needing RANK_MATH_VERSION
		// defined, which, being a PHP constant, could never be un-defined
		// again for the rest of the process once set.
		$module_enabled = $active && is_array( $general ) && ! empty( $general['IndexNow'] );

		return (bool) apply_filters( 'mcpwp_rankmath_indexnow_active', $module_enabled );
	}

	/**
	 * The reason automatic submission is currently not happening, or ''
	 * when it would proceed. Used by the status tool/REST route so an
	 * agent can see WHY nothing was sent instead of guessing.
	 *
	 * @return string
	 */
	public function blocked_reason() {
		if ( ! $this->get_settings()['enabled'] ) {
			return 'disabled_by_setting';
		}
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( 'production' !== $env && ! $this->get_settings()['force_non_production'] ) {
			return 'non_production_environment';
		}
		if ( $this->defer_to_rankmath() ) {
			return 'rankmath_indexnow_active';
		}
		return '';
	}

	/**
	 * Whether a post is a candidate for submission: public post type,
	 * published, not password-protected, and not marked noindex by
	 * whichever SEO plugin (or MCPWP itself) is active.
	 *
	 * @param object $post Post-like object (ID, post_type, post_status, post_password).
	 * @return bool
	 */
	public function is_url_indexable( $post ) {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( ! empty( $post->post_password ) ) {
			return false;
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || empty( $post_type->public ) ) {
			return false;
		}

		if ( $this->has_noindex_meta( (int) $post->ID ) ) {
			return false;
		}

		return (bool) apply_filters( 'mcpwp_indexnow_should_submit', true, $post );
	}

	/**
	 * Check the noindex meta key used by whichever SEO plugin is active.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function has_noindex_meta( $post_id ) {
		if ( Mcpwp_SEO_Plugin_Detect::is_yoast_active() ) {
			return '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		}
		if ( Mcpwp_SEO_Plugin_Detect::is_rankmath_active() ) {
			$robots = get_post_meta( $post_id, 'rank_math_robots', true );
			return is_array( $robots ) && in_array( 'noindex', $robots, true );
		}
		if ( Mcpwp_SEO_Plugin_Detect::is_aioseo_active() ) {
			return ! empty( get_post_meta( $post_id, '_aioseo_noindex', true ) );
		}
		if ( Mcpwp_SEO_Plugin_Detect::is_seopress_active() ) {
			return 'yes' === get_post_meta( $post_id, '_seopress_robots_noindex', true );
		}
		return ! empty( get_post_meta( $post_id, '_mcpwp_noindex', true ) );
	}

	/**
	 * `transition_post_status` handler — covers a post's first publish.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param object $post       Post object.
	 * @return void
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status ) {
			return;
		}
		$this->maybe_queue( $post );
	}

	/**
	 * `post_updated` handler — covers edits to a post that was already
	 * published (transition_post_status does not fire when the status
	 * does not change).
	 *
	 * @param int    $post_id    Post ID.
	 * @param object $post_after Post object after the update.
	 * @return void
	 */
	public function on_post_updated( $post_id, $post_after ) {
		if ( ! is_object( $post_after ) || 'publish' !== $post_after->post_status ) {
			return;
		}
		$this->maybe_queue( $post_after );
	}

	/**
	 * Queue a post's URL for submission if it passes every gate.
	 *
	 * @param object $post Post object.
	 * @return void
	 */
	private function maybe_queue( $post ) {
		if ( ! $this->is_enabled() || $this->defer_to_rankmath() ) {
			return;
		}
		if ( ! $this->is_url_indexable( $post ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( $url ) {
			$this->queue_url( $url );
		}
	}

	/**
	 * Add a URL to the pending batch and schedule the debounced flush.
	 *
	 * @param string $url URL to queue.
	 * @return void
	 */
	public function queue_url( $url ) {
		$pending = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		$pending[] = (string) $url;
		$pending   = array_slice( array_values( array_unique( $pending ) ), 0, self::MAX_URLS_PER_REQUEST );
		update_option( self::PENDING_OPTION, $pending );

		if ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
			wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::FLUSH_HOOK );
		}
	}

	/**
	 * Cron callback: submit and clear whatever accumulated during the
	 * debounce window.
	 *
	 * @return array|WP_Error|null Submission result, or null when nothing was pending.
	 */
	public function flush() {
		$pending = get_option( self::PENDING_OPTION, array() );
		if ( empty( $pending ) || ! is_array( $pending ) ) {
			return null;
		}
		delete_option( self::PENDING_OPTION );

		if ( ! $this->is_enabled() || $this->defer_to_rankmath() ) {
			return null;
		}

		return $this->submit( $pending );
	}

	/**
	 * Only ever submit URLs this site actually owns — an explicit tool
	 * call must not become an open relay for pinging arbitrary third-party
	 * URLs to IndexNow.
	 *
	 * @param array $urls Candidate URLs.
	 * @return array
	 */
	private function filter_own_urls( array $urls ) {
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return array_values(
			array_filter(
				$urls,
				static function ( $url ) use ( $home_host ) {
					if ( ! is_string( $url ) || '' === $url ) {
						return false;
					}
					$host = wp_parse_url( $url, PHP_URL_HOST );
					return is_string( $host ) && strtolower( $host ) === strtolower( (string) $home_host );
				}
			)
		);
	}

	/**
	 * Low-level submission: POST the URL list to the shared IndexNow
	 * endpoint and record the result. Does not apply the enabled/Rank
	 * Math gates — callers (the cron flush, the MCP tool) decide whether
	 * to call this at all so the gate reason is visible to them.
	 *
	 * @param array $urls URLs to submit.
	 * @return array|WP_Error
	 */
	public function submit( array $urls ) {
		$urls = $this->filter_own_urls( $urls );
		if ( empty( $urls ) ) {
			return new WP_Error( 'indexnow_no_urls', 'No submittable URLs: every URL must belong to this site.' );
		}
		$urls = array_slice( $urls, 0, self::MAX_URLS_PER_REQUEST );

		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$body = array(
			'host'        => $host,
			'key'         => $this->get_key(),
			'keyLocation' => $this->key_url(),
			'urlList'     => $urls,
		);

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		$result = array(
			'timestamp' => time(),
			'url_count' => count( $urls ),
			'urls'      => $urls,
		);

		if ( is_wp_error( $response ) ) {
			$result['success'] = false;
			$result['error']   = $response->get_error_message();
		} else {
			$code                     = (int) wp_remote_retrieve_response_code( $response );
			$result['response_code']  = $code;
			$result['success']        = $code >= 200 && $code < 300;
			if ( ! $result['success'] ) {
				$result['error'] = wp_remote_retrieve_body( $response );
			}
		}

		update_option( self::LAST_RESULT_OPTION, $result );

		return $result;
	}

	/**
	 * Status snapshot for the REST/MCP status surface.
	 *
	 * @return array
	 */
	public function get_status() {
		$pending = get_option( self::PENDING_OPTION, array() );
		return array(
			'enabled'         => $this->is_enabled(),
			'blocked_reason'  => $this->blocked_reason(),
			'environment'     => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'key_url'         => $this->key_url(),
			'rankmath_active' => $this->defer_to_rankmath(),
			'pending_count'   => is_array( $pending ) ? count( $pending ) : 0,
			'last_result'     => get_option( self::LAST_RESULT_OPTION, null ),
		);
	}
}
