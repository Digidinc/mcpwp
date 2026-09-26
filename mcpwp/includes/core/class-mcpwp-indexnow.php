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
 * Coexistence: Rank Math ships its own IndexNow feature as its
 * "Instant Indexing" module (module id `instant-indexing`, active by
 * default since Rank Math 1.0.56 — verified against Rank Math's own
 * source, see defer_to_rankmath()). When that module's auto-submit is
 * configured, MCPWP defers to it entirely rather than double-submitting
 * the same URL to the same shared endpoint.
 *
 * OPT-IN (#937 adversarial review WARN-1): automatic submission is OFF by
 * default for every install, new or existing, and never runs at all when
 * the site's own "Discourage search engines" setting (blog_public=0) is
 * on, regardless of the IndexNow setting. The 32-hex-char key is never
 * generated, and its file is never served, while the feature is off — no
 * option write happens on an unauthenticated front-end request just
 * because IndexNow exists in the codebase.
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

	const ENDPOINT              = 'https://api.indexnow.org/indexnow';
	const KEY_OPTION            = 'mcpwp_indexnow_key';
	const SETTINGS_OPTION       = 'mcpwp_indexnow_settings';
	const PENDING_OPTION        = 'mcpwp_indexnow_pending';
	const LAST_RESULT_OPTION    = 'mcpwp_indexnow_last_result';
	const RETRY_STATE_OPTION    = 'mcpwp_indexnow_retry_state';
	const SUBMIT_THROTTLE_KEY   = 'mcpwp_indexnow_submit_throttle';
	const FLUSH_HOOK            = 'mcpwp_indexnow_flush';
	const DEBOUNCE_SECONDS      = 60;
	const MAX_URLS_PER_REQUEST  = 10000;
	const MAX_EXPLICIT_PER_CALL = 100;
	const SUBMIT_THROTTLE_SECONDS = 60;
	const MAX_BACKOFF_SECONDS   = 3600;
	const MAX_QUEUE_CAS_ATTEMPTS = 5;

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
	 * The stored key, or '' if none has been generated yet. Never writes.
	 *
	 * @return string
	 */
	public function get_stored_key() {
		$key = get_option( self::KEY_OPTION, '' );
		return ( is_string( $key ) && preg_match( '/^[a-f0-9]{32}$/', $key ) ) ? $key : '';
	}

	/**
	 * Generate and store the key if it does not exist yet. Unconditional —
	 * callers that must not generate while the feature is off use
	 * get_key() instead, which gates on the "enabled" setting.
	 *
	 * @return string
	 */
	public function ensure_key_generated() {
		$existing = $this->get_stored_key();
		if ( '' !== $existing ) {
			return $existing;
		}
		$key = bin2hex( random_bytes( 16 ) );
		update_option( self::KEY_OPTION, $key );
		return $key;
	}

	/**
	 * Get the site's IndexNow key, generating it only while the feature is
	 * enabled (#937 WARN-1: "generate on enable", never lazily on an
	 * unauthenticated front-end request while the feature is off).
	 *
	 * @return string '' when disabled and no key has ever been generated.
	 */
	public function get_key() {
		if ( $this->get_settings()['enabled'] ) {
			return $this->ensure_key_generated();
		}
		return $this->get_stored_key();
	}

	/**
	 * The URL IndexNow key verification fetches, or '' when disabled and
	 * no key exists yet.
	 *
	 * @return string
	 */
	public function key_url() {
		$key = $this->get_key();
		return '' !== $key ? home_url( '/' . $key . '.txt' ) : '';
	}

	/**
	 * Serve the key file at /{key}.txt as text/plain. Never served while
	 * disabled (#937 WARN-1) — a disabled site must not leak evidence a
	 * key was ever generated, and must not generate one on this request.
	 *
	 * @param WP $wp WordPress environment.
	 * @return void
	 */
	public function maybe_serve_key_file( $wp ) {
		if ( ! isset( $wp->request ) ) {
			return;
		}
		if ( ! $this->get_settings()['enabled'] ) {
			return;
		}

		$key = $this->get_stored_key();
		if ( '' === $key ) {
			return;
		}

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
	 * Merged settings with defaults. `enabled` defaults to FALSE (#937
	 * WARN-1 — opt-in for new AND existing installs alike; there is no
	 * migration to write because no site had this option before this
	 * feature shipped).
	 *
	 * @return array{enabled: bool, force_non_production: bool}
	 */
	public function get_settings() {
		$stored = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'enabled'              => isset( $stored['enabled'] ) && (bool) $stored['enabled'],
			'force_non_production' => ! empty( $stored['force_non_production'] ),
		);
	}

	/**
	 * Persist settings. Generates the key immediately when transitioning
	 * to enabled, so the key file is ready without depending on a later
	 * front-end request to create it.
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

		if ( $current['enabled'] ) {
			$this->ensure_key_generated();
		}

		return $current;
	}

	/**
	 * The site's own "Discourage search engines from indexing this site"
	 * setting. A hard stop regardless of the IndexNow setting (#937
	 * WARN-1) — pinging a third-party indexing relay while the admin has
	 * explicitly asked search engines to stay away is exactly backwards.
	 *
	 * @return bool
	 */
	public function blog_public_allows_indexing() {
		return '0' !== (string) get_option( 'blog_public', '1' );
	}

	/**
	 * Whether IndexNow should run at all right now: the off switch, the
	 * site-wide "discourage search engines" setting, and the environment
	 * gate (production by default, any other environment only when
	 * explicitly forced) — in that order.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = $this->get_settings();
		if ( ! $settings['enabled'] ) {
			return false;
		}
		if ( ! $this->blog_public_allows_indexing() ) {
			return false;
		}

		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( 'production' !== $env && ! $settings['force_non_production'] ) {
			return false;
		}

		return true;
	}

	/**
	 * True when Rank Math's own "Instant Indexing" module is configured to
	 * auto-submit, and this site should defer to it rather than
	 * double-submit.
	 *
	 * Verified against Rank Math 1.0.279 source (2026-09-26):
	 *   - the module id is `instant-indexing`, active by default since
	 *     Rank Math 1.0.56 (includes/class-installer.php's default
	 *     `rank_math_modules` list).
	 *   - it only auto-submits on `save_post_{$post_type}` for post types
	 *     configured in `Instant_Indexing::get_auto_submit_post_types()`,
	 *     which reads `bing_post_types` from the
	 *     `rank-math-options-instant-indexing` option (Rank Math's
	 *     Settings::get_options() maps the `instant_indexing` settings
	 *     group to that exact option name).
	 * A module active with no post types configured does not auto-submit
	 * anything, so both conditions are checked. Exposed as a filter so a
	 * site (or a test) can correct this without an MCPWP release if Rank
	 * Math changes the shape.
	 *
	 * @return bool
	 */
	public function defer_to_rankmath() {
		// Rank Math does not clean up its options on deactivation/uninstall
		// (WordPress core behavior for most plugins), so `rank_math_modules`
		// can keep saying "instant-indexing is active" long after the
		// plugin itself is gone. Gating on Mcpwp_SEO_Plugin_Detect first
		// stops that stale state from permanently blocking IndexNow — a
		// site that removed Rank Math must not be stuck deferring to a
		// feature that no longer exists (live-verified 2026-09-26: without
		// this gate, deactivating Rank Math left blocked_reason() reporting
		// rankmath_indexnow_active forever).
		$plugin_active  = Mcpwp_SEO_Plugin_Detect::is_rankmath_active();
		$active_modules = (array) get_option( 'rank_math_modules', array() );
		$module_active  = $plugin_active && in_array( 'instant-indexing', $active_modules, true );

		$settings           = get_option( 'rank-math-options-instant-indexing', array() );
		$auto_submit_types  = ( is_array( $settings ) && ! empty( $settings['bing_post_types'] ) )
			? (array) $settings['bing_post_types']
			: array();
		$module_enabled = $module_active && ! empty( $auto_submit_types );

		return (bool) apply_filters( 'mcpwp_rankmath_indexnow_active', $module_enabled, $module_active, $auto_submit_types );
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
		if ( ! $this->blog_public_allows_indexing() ) {
			return 'site_discourages_search_engines';
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
	 * whichever SEO plugin (or MCPWP itself) is active — checking BOTH a
	 * per-post override and, where the post has none, that plugin's
	 * site-wide per-post-type default (#937 WARN-2). Also re-checks the
	 * site-wide "discourage search engines" setting directly, so a
	 * flush-time re-validation catches a mid-flight change even though
	 * is_enabled() already gates the caller.
	 *
	 * @param object $post Post-like object (ID, post_type, post_status, post_password).
	 * @return bool
	 */
	public function is_url_indexable( $post ) {
		if ( ! $this->blog_public_allows_indexing() ) {
			return false;
		}
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

		if ( $this->has_noindex_meta( (int) $post->ID, (string) $post->post_type ) ) {
			return false;
		}

		return (bool) apply_filters( 'mcpwp_indexnow_should_submit', true, $post );
	}

	/**
	 * Check the noindex meta key used by whichever SEO plugin is active,
	 * falling back to that plugin's site-wide per-post-type default when
	 * the post carries no explicit override (#937 WARN-2).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function has_noindex_meta( $post_id, $post_type ) {
		if ( Mcpwp_SEO_Plugin_Detect::is_yoast_active() ) {
			// Yoast per-post meta: '1' = noindex, '2' = explicit index
			// (overrides the site default), '' / '0' = use the site default.
			$meta = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
			if ( '1' === $meta ) {
				return true;
			}
			if ( '2' === $meta ) {
				return false;
			}
			$titles = get_option( 'wpseo_titles', array() );
			return is_array( $titles ) && ! empty( $titles[ 'noindex-' . $post_type ] );
		}
		if ( Mcpwp_SEO_Plugin_Detect::is_rankmath_active() ) {
			$robots = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $robots ) && ! empty( $robots ) ) {
				return in_array( 'noindex', $robots, true );
			}
			// No per-post override: Rank Math's per-post-type default lives
			// under `pt_{$post_type}_default_robots` in the titles settings
			// group (option `rank-math-options-titles`).
			$titles  = get_option( 'rank-math-options-titles', array() );
			$default = is_array( $titles ) ? ( $titles[ 'pt_' . $post_type . '_default_robots' ] ?? array() ) : array();
			return is_array( $default ) && in_array( 'noindex', $default, true );
		}
		if ( Mcpwp_SEO_Plugin_Detect::is_aioseo_active() ) {
			$meta = get_post_meta( $post_id, '_aioseo_noindex', true );
			if ( '' !== $meta ) {
				return ! empty( $meta ) && '-1' !== (string) $meta;
			}
			// Best-effort site default: AIOSEO's own structure is a large,
			// version-dependent nested JSON blob; not probed here. A site
			// that needs this covered can use the mcpwp_indexnow_should_submit
			// filter below.
			return false;
		}
		if ( Mcpwp_SEO_Plugin_Detect::is_seopress_active() ) {
			$meta = get_post_meta( $post_id, '_seopress_robots_noindex', true );
			if ( '' !== $meta ) {
				return 'yes' === $meta;
			}
			$titles = get_option( 'seopress_titles_option_name', array() );
			return is_array( $titles ) && ! empty( $titles[ 'seopress_titles_noindex_' . $post_type ] );
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
	 * Queueing here (rather than only on `save_post`) means the noindex
	 * meta an SEO plugin's own meta-box save writes on THIS SAME request
	 * may not exist yet — `save_post` runs after `post_updated`. That is
	 * why flush() re-validates every URL with is_url_indexable() at send
	 * time rather than trusting the state captured at queue time (#937
	 * WARN-2's "or re-evaluate at flush" option): a same-save noindex is
	 * caught 60+ seconds later, before anything is ever sent.
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
	 * Atomically merge URLs into the pending option via read/compare-and-
	 * swap (#937 NOTE-2 — "an atomic append… or a lock"), reusing the same
	 * row-level CAS primitive Mcpwp_Encryption already uses for its own
	 * concurrent-write races, rather than a plain get_option()/
	 * update_option() pair that a second, concurrent publish could stomp
	 * on. Falls back to a best-effort non-atomic write only when the CAS
	 * primitive itself is unavailable (no $wpdb) or exhausts its retries —
	 * losing a URL is worse than the small residual race in that fallback.
	 *
	 * @param array $urls URLs to merge in.
	 * @return void
	 */
	private function atomic_merge_pending( array $urls ) {
		if ( empty( $urls ) ) {
			return;
		}

		if ( ! class_exists( 'Mcpwp_Encryption' ) ) {
			$this->non_atomic_merge_pending( $urls );
			return;
		}

		for ( $attempt = 0; $attempt < self::MAX_QUEUE_CAS_ATTEMPTS; $attempt++ ) {
			$row = Mcpwp_Encryption::read_option_row( self::PENDING_OPTION );

			if ( Mcpwp_Encryption::ROW_ERROR === $row['state'] ) {
				break; // DB read failed; fall through to the non-atomic path.
			}

			if ( Mcpwp_Encryption::ROW_ABSENT === $row['state'] ) {
				$merged = array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_URLS_PER_REQUEST );
				if ( Mcpwp_Encryption::insert_option_row_if_absent( self::PENDING_OPTION, maybe_serialize( $merged ), 'no' ) ) {
					Mcpwp_Encryption::clean_option_cache( self::PENDING_OPTION );
					return;
				}
				continue; // Someone else inserted first between our read and insert — retry.
			}

			$current = maybe_unserialize( (string) $row['value'] );
			$current = is_array( $current ) ? $current : array();
			$merged  = array_slice( array_values( array_unique( array_merge( $current, $urls ) ) ), 0, self::MAX_URLS_PER_REQUEST );

			if ( Mcpwp_Encryption::compare_and_swap_option_row( self::PENDING_OPTION, $row['value'], maybe_serialize( $merged ) ) ) {
				Mcpwp_Encryption::clean_option_cache( self::PENDING_OPTION );
				return;
			}
			// Someone else wrote in between — retry with a fresh read.
		}

		$this->non_atomic_merge_pending( $urls );
	}

	/**
	 * Best-effort fallback merge (get_option/update_option pair) — used
	 * only when the CAS path is unavailable or exhausted, so a URL is
	 * still queued rather than silently dropped.
	 *
	 * @param array $urls URLs to merge in.
	 * @return void
	 */
	private function non_atomic_merge_pending( array $urls ) {
		$pending = get_option( self::PENDING_OPTION, array() );
		$pending = is_array( $pending ) ? $pending : array();
		$pending = array_slice( array_values( array_unique( array_merge( $pending, $urls ) ) ), 0, self::MAX_URLS_PER_REQUEST );
		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * Atomically take and empty the entire pending queue in one step, so
	 * a flush() in progress and a concurrent queue_url() never see (or
	 * lose) the same entry twice.
	 *
	 * @return array URLs that were pending.
	 */
	private function claim_and_clear_pending() {
		if ( ! class_exists( 'Mcpwp_Encryption' ) ) {
			$current = get_option( self::PENDING_OPTION, array() );
			update_option( self::PENDING_OPTION, array(), false );
			return is_array( $current ) ? $current : array();
		}

		for ( $attempt = 0; $attempt < self::MAX_QUEUE_CAS_ATTEMPTS; $attempt++ ) {
			$row = Mcpwp_Encryption::read_option_row( self::PENDING_OPTION );

			if ( Mcpwp_Encryption::ROW_ABSENT === $row['state'] || Mcpwp_Encryption::ROW_ERROR === $row['state'] ) {
				return array();
			}

			$current = maybe_unserialize( (string) $row['value'] );
			$current = is_array( $current ) ? $current : array();
			if ( empty( $current ) ) {
				return array();
			}

			if ( Mcpwp_Encryption::compare_and_swap_option_row( self::PENDING_OPTION, $row['value'], maybe_serialize( array() ) ) ) {
				Mcpwp_Encryption::clean_option_cache( self::PENDING_OPTION );
				return $current;
			}
			// Someone else changed it since our read — retry.
		}

		$current = get_option( self::PENDING_OPTION, array() );
		update_option( self::PENDING_OPTION, array(), false );
		return is_array( $current ) ? $current : array();
	}

	/**
	 * Add a URL to the pending batch and schedule the debounced flush.
	 *
	 * @param string $url URL to queue.
	 * @return void
	 */
	public function queue_url( $url ) {
		$this->atomic_merge_pending( array( (string) $url ) );

		if ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
			wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::FLUSH_HOOK );
		}
	}

	/**
	 * Cron callback: re-validate and submit whatever accumulated during
	 * the debounce window.
	 *
	 * URLs stay queued (rather than being cleared unconditionally) until
	 * the endpoint answers 2xx; a failed submission is re-queued and
	 * retried with exponential backoff, and any URL that no longer
	 * validates at send time is dropped and counted, never silently
	 * resubmitted forever (#937 WARN-2, NOTE-2).
	 *
	 * @return array|null Submission result (with dropped/backoff bookkeeping), or null when nothing ran.
	 */
	public function flush() {
		$claimed = $this->claim_and_clear_pending();
		if ( empty( $claimed ) ) {
			return null;
		}

		if ( ! $this->is_enabled() || $this->defer_to_rankmath() ) {
			// The feature is off (or Rank Math owns this now) — the claimed
			// batch is intentionally discarded, not requeued: there is
			// nothing to retry toward.
			return null;
		}

		$survivors = array();
		$dropped   = 0;
		foreach ( $claimed as $url ) {
			$post_id = function_exists( 'url_to_postid' ) ? url_to_postid( $url ) : 0;
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( $post && $this->is_url_indexable( $post ) ) {
				$survivors[] = $url;
			} else {
				$dropped++;
			}
		}

		if ( empty( $survivors ) ) {
			delete_option( self::RETRY_STATE_OPTION );
			return array(
				'success'                => true,
				'url_count'              => 0,
				'dropped_not_indexable'  => $dropped,
			);
		}

		$over_cap = array();
		if ( count( $survivors ) > self::MAX_URLS_PER_REQUEST ) {
			$over_cap  = array_slice( $survivors, self::MAX_URLS_PER_REQUEST );
			$survivors = array_slice( $survivors, 0, self::MAX_URLS_PER_REQUEST );
		}

		$result = $this->submit( $survivors );
		$succeeded = is_array( $result ) && ! empty( $result['success'] );

		if ( ! empty( $over_cap ) ) {
			// Deferred to the next flush rather than lost — reported so an
			// operator can see the queue is growing faster than it drains.
			$this->atomic_merge_pending( $over_cap );
		}

		if ( $succeeded ) {
			delete_option( self::RETRY_STATE_OPTION );
		} else {
			// Keep the survivors queued and retry with exponential backoff
			// instead of losing them on a transient failure (#937 NOTE-2).
			$this->atomic_merge_pending( $survivors );

			$state   = get_option( self::RETRY_STATE_OPTION, array( 'attempt' => 0 ) );
			$attempt = ( is_array( $state ) && isset( $state['attempt'] ) ? (int) $state['attempt'] : 0 ) + 1;
			$delay   = min( self::DEBOUNCE_SECONDS * ( 2 ** $attempt ), self::MAX_BACKOFF_SECONDS );
			update_option(
				self::RETRY_STATE_OPTION,
				array(
					'attempt'    => $attempt,
					'last_error' => is_array( $result ) ? ( $result['error'] ?? '' ) : '',
				),
				false
			);

			$next = wp_next_scheduled( self::FLUSH_HOOK );
			if ( $next ) {
				wp_unschedule_event( $next, self::FLUSH_HOOK );
			}
			wp_schedule_single_event( time() + $delay, self::FLUSH_HOOK );

			if ( is_array( $result ) ) {
				$result['retry_attempt']    = $attempt;
				$result['retry_in_seconds'] = $delay;
			}
		}

		if ( is_array( $result ) ) {
			$result['dropped_not_indexable'] = $dropped;
			$result['over_cap_requeued']     = count( $over_cap );
		}

		return $result;
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
	 * Validate URLs submitted through the EXPLICIT tool/REST call — a
	 * stricter gate than the automatic publish-hook path, because these
	 * come from an untrusted-shaped caller argument rather than this
	 * site's own permalink generator (#937 WARN-3):
	 *   - refuses the whole call outright if it carries more than
	 *     self::MAX_EXPLICIT_PER_CALL URLs (never silently truncates —
	 *     the caller would not know which ones were dropped);
	 *   - rejects any URL carrying a query string (?s=, ?p=, etc.) even
	 *     if it would otherwise resolve, since a querystring URL is not a
	 *     stable canonical page identity;
	 *   - requires url_to_postid() to resolve it to a real post;
	 *   - requires that post to currently pass is_url_indexable().
	 *
	 * @param array $urls Candidate URLs.
	 * @return array{valid: string[], rejected: array<string,string>}|WP_Error WP_Error only for the over-the-cap refusal.
	 */
	public function validate_explicit_urls( array $urls ) {
		if ( count( $urls ) > self::MAX_EXPLICIT_PER_CALL ) {
			return new WP_Error(
				'indexnow_too_many_urls',
				sprintf( 'A single explicit submission is capped at %d URLs; %d were sent. Split the call — nothing was submitted.', self::MAX_EXPLICIT_PER_CALL, count( $urls ) )
			);
		}

		$own = $this->filter_own_urls( $urls );

		$valid    = array();
		$rejected = array();

		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				$rejected[ (string) $url ] = 'not a string URL';
				continue;
			}
			if ( ! in_array( $url, $own, true ) ) {
				$rejected[ $url ] = 'does not belong to this site';
				continue;
			}
			if ( '' !== (string) wp_parse_url( $url, PHP_URL_QUERY ) ) {
				$rejected[ $url ] = 'query strings are not accepted (not a stable canonical page identity)';
				continue;
			}
			$post_id = function_exists( 'url_to_postid' ) ? url_to_postid( $url ) : 0;
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( ! $post ) {
				$rejected[ $url ] = 'does not resolve to a post on this site';
				continue;
			}
			if ( ! $this->is_url_indexable( $post ) ) {
				$rejected[ $url ] = 'not currently indexable (unpublished, password-protected, non-public type, or noindex)';
				continue;
			}
			$valid[] = $url;
		}

		return array(
			'valid'    => $valid,
			'rejected' => $rejected,
		);
	}

	/**
	 * Consume the explicit-submit throttle (#937 WARN-3: 1 call/min).
	 *
	 * @return bool True when this call may proceed and the throttle window has been (re)started; false when still within a prior window.
	 */
	public function check_and_consume_submit_throttle() {
		if ( false !== get_transient( self::SUBMIT_THROTTLE_KEY ) ) {
			return false;
		}
		set_transient( self::SUBMIT_THROTTLE_KEY, time(), self::SUBMIT_THROTTLE_SECONDS );
		return true;
	}

	/**
	 * Low-level submission: POST the URL list to the shared IndexNow
	 * endpoint and record the result. Does not apply the enabled/blog_public/
	 * Rank Math gates or the URL validation above — callers decide whether
	 * to call this at all so the gate reason is visible to them.
	 *
	 * @param array $urls URLs to submit — must already be this site's own.
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
			'key'         => $this->ensure_key_generated(),
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
			// IndexNow's own spec treats 200 and 202 as success ("202
			// Accepted" is the documented response for a valid submission).
			$code                    = (int) wp_remote_retrieve_response_code( $response );
			$result['response_code'] = $code;
			$result['success']       = 200 === $code || 202 === $code;
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
			'enabled'                  => $this->is_enabled(),
			'blocked_reason'           => $this->blocked_reason(),
			'blog_public'              => $this->blog_public_allows_indexing(),
			'environment'              => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'key_url'                  => $this->key_url(),
			'rankmath_active'          => $this->defer_to_rankmath(),
			'pending_count'            => is_array( $pending ) ? count( $pending ) : 0,
			'retry_state'              => get_option( self::RETRY_STATE_OPTION, null ),
			'last_result'              => get_option( self::LAST_RESULT_OPTION, null ),
		);
	}
}
