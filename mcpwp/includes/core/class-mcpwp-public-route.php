<?php
/**
 * Does a real visitor see what the render tools just returned?
 *
 * The render tools answer from inside WordPress. On a staging site behind a
 * host-level gate — HostGator's "Coming Soon" lock, a maintenance-mode plugin,
 * an HTTP-auth wall — the public route answers something else entirely. A
 * reported case: `wp_get_rendered_html` returned an Elementor form and a 200
 * from the internal render, while the same page's public URL returned 404 and
 * a "Coming Soon" page. CTA and form QA passed while real visitors saw no form
 * (#693).
 *
 * The plugin already knew. `get_rendered_html()` renders in-process
 * *specifically because* those gates intercept a loopback fetch, with a
 * comment saying so — and then reported the result as though it were the page.
 * Working around a gate and not mentioning it leaves the caller with a
 * confident answer to a question they did not ask.
 *
 * This states the fact instead. Three outcomes, kept apart on purpose:
 *
 *   gated = true   the public route answers with something a visitor cannot
 *                  use — a non-200, or a page that identifies itself as
 *                  coming-soon / maintenance
 *   gated = false  the public route answers 200 with the page's own title
 *   gated = null   the check did not happen or could not complete
 *
 * The third is why this class exists rather than a boolean. "The public route
 * is fine" and "nobody looked" must never be the same answer, which is the
 * failure this plugin keeps rediscovering.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-route parity checking.
 */
class Mcpwp_Public_Route {

	/**
	 * Transient prefix for a cached result.
	 */
	const CACHE_PREFIX = 'mcpwp_public_route_';

	/**
	 * How long a result stands.
	 *
	 * A gate is a site-level condition that changes rarely, and the render
	 * tools are called repeatedly while building. Five minutes keeps this from
	 * adding an outbound request to every render without letting an answer go
	 * stale across a working session.
	 */
	const CACHE_TTL = 300;

	/**
	 * Title fragments that identify a gate rather than a page.
	 *
	 * Matched case-insensitively against the public response's <title>.
	 *
	 * @var array
	 */
	private static $gate_markers = array(
		'coming soon',
		'under construction',
		'maintenance mode',
		'briefly unavailable',
		'site is under maintenance',
		'launching soon',
		'opening soon',
	);

	/**
	 * Check whether a published post's public URL shows the post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Skip the cache.
	 * @return array Report; see the class docblock for the three outcomes.
	 */
	public static function check( $post_id, $force = false ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::unknown( 'no_such_post', 'The post does not exist, so its public route was not checked.' );
		}

		// A draft's public URL is *supposed* to 404. Reporting that as a gate
		// would cry wolf on the ordinary case of building before publishing.
		if ( 'publish' !== $post->post_status ) {
			return self::unknown(
				'not_published',
				sprintf(
					'This post is %s, so it has no public route yet — the render above is the internal one, and there is nothing to compare it against.',
					$post->post_status
				)
			);
		}

		$cache_key = self::CACHE_PREFIX . $post_id;

		if ( ! $force ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$permalink = get_permalink( $post_id );

		if ( ! $permalink ) {
			return self::unknown( 'no_permalink', 'The post has no permalink, so its public route could not be checked.' );
		}

		$response = wp_remote_get(
			$permalink,
			array(
				'timeout'     => 8,
				'sslverify'   => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				// Follow: a gate usually redirects, and the state a visitor
				// lands in is the thing under test.
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Could not look. Not "the route is fine", and not "the route is
			// gated" either — an unreachable check is its own outcome and the
			// caller is told which one this is.
			return self::unknown(
				'fetch_failed',
				'The public URL could not be fetched (' . $response->get_error_message() . '), so whether a visitor sees this page is unknown.'
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$title  = self::extract_title( $body );

		$report = array(
			'checked'      => true,
			'url'          => $permalink,
			'status'       => $status,
			'public_title' => $title,
			'cached'       => false,
		);

		if ( $status < 200 || $status >= 300 ) {
			$report['gated'] = true;
			$report['reason'] = 'http_status';
			$report['warning'] = sprintf(
				'The render above came from inside WordPress, but this page\'s public URL answers HTTP %d. A visitor does not see what was returned here.',
				$status
			);

			set_transient( $cache_key, $report, self::CACHE_TTL );

			return $report;
		}

		$marker = self::matched_gate_marker( $title );

		if ( null !== $marker ) {
			$report['gated']   = true;
			$report['reason']  = 'gate_page';
			$report['warning'] = sprintf(
				'The public URL answers 200 but serves "%s", which is a coming-soon or maintenance gate rather than this page. The render above is the internal one; a visitor sees the gate.',
				$title
			);

			set_transient( $cache_key, $report, self::CACHE_TTL );

			return $report;
		}

		$report['gated']  = false;
		$report['reason'] = 'public';

		set_transient( $cache_key, $report, self::CACHE_TTL );

		return $report;
	}

	/**
	 * An outcome where nothing was established.
	 *
	 * @param string $reason  Machine-readable reason.
	 * @param string $message Human-readable note.
	 * @return array
	 */
	private static function unknown( $reason, $message ) {
		return array(
			'checked' => false,
			'gated'   => null,
			'reason'  => $reason,
			'note'    => $message,
		);
	}

	/**
	 * The document title from an HTML response.
	 *
	 * @param string $html Response body.
	 * @return string Title, or '' when there is none.
	 */
	public static function extract_title( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return '';
		}

		if ( ! preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $matches ) ) {
			return '';
		}

		return trim( html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Which gate marker a title matches, if any.
	 *
	 * @param string $title Public page title.
	 * @return string|null The marker matched, or null.
	 */
	public static function matched_gate_marker( $title ) {
		$title = strtolower( (string) $title );

		if ( '' === $title ) {
			return null;
		}

		foreach ( self::$gate_markers as $marker ) {
			if ( false !== strpos( $title, $marker ) ) {
				return $marker;
			}
		}

		return null;
	}

	/**
	 * Forget a cached result — after a publish, or a settings change.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function forget( $post_id ) {
		delete_transient( self::CACHE_PREFIX . absint( $post_id ) );
	}
}
