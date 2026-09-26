<?php
/**
 * Public AI presence helpers.
 *
 * Coexistence rule for this whole file: MCPWP never shadows or duplicates
 * output an active SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress) already
 * produces for the same purpose. Where that plugin already ships an
 * llms.txt or a sitemap, MCPWP defers to it by default.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expose crawl-friendly AI guidance for the public website.
 */
class Mcpwp_AI_Presence {

	/**
	 * Settings option. Currently holds only `force_llms_txt`, which makes
	 * MCPWP serve its own /llms.txt even while an SEO plugin that ships one
	 * is active — an explicit admin choice, never the default.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION = 'mcpwp_ai_presence_settings';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'maybe_serve_llms_txt' ) );
		// Very late priority (#937 adversarial review NOTE-1): an SEO
		// plugin's own robots_txt filter commonly runs late too (Yoast:
		// 99999) to have the last word over core's defaults. Running after
		// it means our dedupe check (existing "sitemap:"/"user-agent: X"
		// text) sees what that plugin ACTUALLY produced, not just whatever
		// ran before us — and our own appended content lands last, after
		// every other plugin's block, which is also the clearer read for a
		// human or agent looking at the final file.
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), PHP_INT_MAX - 10, 2 );
		add_action( 'wp_head', array( $this, 'output_llms_link_tag' ), 1 );
	}

	/**
	 * Merged settings with defaults.
	 *
	 * @return array{force_llms_txt: bool}
	 */
	public function get_settings() {
		$stored = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'force_llms_txt' => ! empty( $stored['force_llms_txt'] ),
		);
	}

	/**
	 * Whether MCPWP should serve its own /llms.txt right now.
	 *
	 * #937 adversarial review WARN-4: deferring merely because Yoast/Rank
	 * Math is ACTIVE was wrong — both ship llms.txt as an opt-in feature
	 * that defaults OFF, so a site running Yoast for meta/schema only
	 * would get neither plugin's llms.txt if MCPWP deferred on presence
	 * alone. Deference now requires POSITIVE evidence the other plugin's
	 * llms.txt is actually configured to serve, checked in order:
	 *
	 *   1. A physical llms.txt file at the site root. Yoast (verified
	 *      against Yoast 28.5 source, 2026-09-26) writes a REAL file via
	 *      WP_Filesystem to get_home_path() . 'llms.txt' — the same
	 *      "static file wins before WordPress even routes the request"
	 *      situation as a physical robots.txt (see
	 *      Mcpwp_AI_Crawler_Policy::physical_robots_file_exists()). If
	 *      that file exists, it will answer the request regardless of
	 *      what MCPWP does, so there is nothing to gain by also serving
	 *      ours and a real risk of the two disagreeing.
	 *   2. Yoast's own feature flag: option `wpseo`, key `enable_llms_txt`
	 *      (Yoast\WP\SEO\...\Enable_Llms_Txt_Option_Watcher reads this
	 *      exact option/key; default false).
	 *   3. Rank Math's own module flag: option `rank_math_modules`
	 *      contains `llms-txt` (Rank Math's module id for its dynamically-
	 *      served, template_redirect-based llms.txt — verified against
	 *      Rank Math 1.0.279 source; NOT in the default-active module
	 *      list, so inactive on a fresh install).
	 *
	 * AIOSEO/SEOPress are not known to ship an llms.txt feature at all as
	 * of this writing, so no flag is probed for them; being active alone
	 * is no longer treated as evidence for any of the four. `force_llms_txt`
	 * remains the explicit escape hatch for a site that wants MCPWP's
	 * version regardless of what else is configured.
	 *
	 * @return bool
	 */
	public function should_serve_own_llms_txt() {
		if ( $this->get_settings()['force_llms_txt'] ) {
			return true;
		}

		if ( $this->llms_txt_physical_file_exists() ) {
			return false;
		}

		if ( $this->yoast_llms_txt_enabled() ) {
			return false;
		}

		if ( $this->rankmath_llms_txt_enabled() ) {
			return false;
		}

		return true;
	}

	/**
	 * A physical llms.txt file at the site root — see should_serve_own_llms_txt().
	 *
	 * @return bool
	 */
	private function llms_txt_physical_file_exists() {
		return file_exists( trailingslashit( ABSPATH ) . 'llms.txt' );
	}

	/**
	 * Yoast's llms.txt feature flag — see should_serve_own_llms_txt().
	 *
	 * @return bool
	 */
	private function yoast_llms_txt_enabled() {
		if ( ! Mcpwp_SEO_Plugin_Detect::is_yoast_active() ) {
			return false;
		}
		$wpseo = get_option( 'wpseo', array() );
		return is_array( $wpseo ) && ! empty( $wpseo['enable_llms_txt'] );
	}

	/**
	 * Rank Math's llms.txt module flag — see should_serve_own_llms_txt().
	 *
	 * @return bool
	 */
	private function rankmath_llms_txt_enabled() {
		if ( ! Mcpwp_SEO_Plugin_Detect::is_rankmath_active() ) {
			return false;
		}
		$modules = (array) get_option( 'rank_math_modules', array() );
		return in_array( 'llms-txt', $modules, true );
	}

	/**
	 * Whether WordPress's core XML sitemaps are actually reachable: the
	 * feature exists on this WP version, the `wp_sitemaps_enabled` filter
	 * has not turned it off, and no coexistence-partner SEO plugin is
	 * active (every one of the four disables the core sitemap and serves
	 * its own — Yoast and Rank Math's is at a different URL entirely, so
	 * advertising /wp-sitemap.xml in that case is a Sitemap line that
	 * 404s or redirects, #bug 2).
	 *
	 * @return bool
	 */
	public function core_sitemap_active() {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return false;
		}
		if ( Mcpwp_SEO_Plugin_Detect::any_active() ) {
			return false;
		}
		return (bool) apply_filters( 'wp_sitemaps_enabled', true );
	}

	/**
	 * Serve /llms.txt without custom rewrites.
	 *
	 * @param WP $wp WordPress environment.
	 * @return void
	 */
	public function maybe_serve_llms_txt( $wp ) {
		if ( ! isset( $wp->request ) ) {
			return;
		}

		$request = trim( (string) $wp->request, '/' );
		if ( 'llms.txt' !== $request ) {
			return;
		}

		if ( ! $this->should_serve_own_llms_txt() ) {
			return;
		}

		status_header( 200 );
		// text/plain markdown, not HTML: no entity-encoding pass over the
		// body (#bug 3 — esc_html() turned every "&" in a URL or title into
		// "&amp;", corrupting the markdown source of truth for a client
		// that reads this file verbatim). Every dynamic value that reaches
		// generate_llms_txt() is already plain-text-sanitized at the point
		// it is composed (see sanitize_plain_text_line() / normalize_site_context()).
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noarchive' );
		echo $this->generate_llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text body, sanitized per-field before assembly; see docblock above.
		exit;
	}

	/**
	 * Add sitemap and llms.txt hints to robots.txt, and the AI crawler
	 * policy block.
	 *
	 * @param string $output Existing robots content.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function filter_robots_txt( $output, $public ) {
		$lines = array();
		if ( is_string( $output ) && '' !== trim( $output ) ) {
			$lines[] = trim( $output );
		}

		// Never advertise a second Sitemap: line, and never advertise ours
		// when the core sitemap it points at is not actually serving
		// (#bug 2). An SEO plugin that supplies its own sitemap is expected
		// to have added its own Sitemap: line already; this only fills the
		// gap when nothing else has.
		if ( $this->core_sitemap_active() && false === stripos( (string) $output, 'sitemap:' ) ) {
			$lines[] = 'Sitemap: ' . esc_url_raw( home_url( '/wp-sitemap.xml' ) );
		}

		if ( $public && $this->should_serve_own_llms_txt() ) {
			$lines[] = '# LLM summary: ' . esc_url_raw( home_url( '/llms.txt' ) );
		}

		$assembled = implode( "\n", array_filter( $lines ) ) . "\n";

		if ( class_exists( 'Mcpwp_AI_Crawler_Policy' ) ) {
			$assembled = Mcpwp_AI_Crawler_Policy::append_to_robots( $assembled );
		}

		return $assembled;
	}

	/**
	 * Advertise the llms.txt file in the document head.
	 *
	 * @return void
	 */
	public function output_llms_link_tag() {
		if ( is_admin() ) {
			return;
		}

		if ( ! $this->should_serve_own_llms_txt() ) {
			return;
		}

		printf(
			"<link rel=\"alternate\" type=\"text/markdown\" title=\"LLMs\" href=\"%s\" />\n",
			esc_url( home_url( '/llms.txt' ) )
		);
	}

	/**
	 * Generate llms.txt content.
	 *
	 * @return string
	 */
	public function generate_llms_txt() {
		$site_name        = $this->sanitize_plain_text_line( (string) get_bloginfo( 'name' ) );
		$site_description = $this->sanitize_plain_text_line( (string) get_bloginfo( 'description' ) );
		$site_context     = $this->normalize_site_context( get_option( 'mcpwp_site_context', '' ) );

		$lines   = array();
		$lines[] = '# ' . $site_name;

		if ( '' !== $site_description ) {
			$lines[] = '> ' . $site_description;
		}

		$lines[] = '';
		$lines[] = '## Canonical';
		$lines[] = '- Website: ' . home_url( '/' );
		if ( $this->core_sitemap_active() ) {
			$lines[] = '- Sitemap: ' . home_url( '/wp-sitemap.xml' );
		}
		$lines[] = '- LLM summary: ' . home_url( '/llms.txt' );

		$important_urls = $this->get_important_urls();
		if ( ! empty( $important_urls ) ) {
			$lines[] = '';
			$lines[] = '## Important URLs';
			foreach ( $important_urls as $item ) {
				$lines[] = '- [' . $item['label'] . '](' . $item['url'] . ')';
			}
		}

		if ( '' !== $site_context ) {
			$lines[] = '';
			$lines[] = '## Site Character';
			$lines[] = $site_context;
		} else {
			$lines[] = '';
			$lines[] = '## Site Character';
			$lines[] = 'No explicit AI site context is configured yet. Use the MCPWP settings to define the brand voice, design system, target audience, and recurring page patterns.';
		}

		$lines[] = '';
		$lines[] = '## Guidance For AI Systems';
		$lines[] = '- Prefer canonical page URLs over query-string variants.';
		if ( $this->core_sitemap_active() ) {
			$lines[] = '- Use the sitemap to discover indexable content.';
		}
		$lines[] = '- Treat the site character section as the preferred brand, content, and design brief when summarizing or generating related content.';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Sanitize a single-line, plain-text value that will be interpolated
	 * into llms.txt: strip markup, decode entities (wptexturize can leave
	 * numeric character references like &#8217; in titles/bloginfo — a
	 * true plain-text file should carry the character, not the entity),
	 * and strip control characters (including CR/LF) so a value that
	 * contains an embedded newline cannot inject a fake extra line/section
	 * into the file (#bug 3 hardening — content-spoofing via a crafted
	 * site name, tagline, or page title).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_plain_text_line( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$value = wp_strip_all_tags( $value );
		$value = html_entity_decode( $value, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		// Strip all C0/C1 control characters (CR, LF, tab, etc.) — a
		// single-line field never legitimately carries one.
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', (string) $value );

		return trim( (string) $value );
	}

	/**
	 * Normalize stored site context for plain-text output.
	 *
	 * @param string $context Raw saved context.
	 * @return string
	 */
	private function normalize_site_context( $context ) {
		if ( ! is_string( $context ) || '' === $context ) {
			return '';
		}

		$context = wp_strip_all_tags( html_entity_decode( $context, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
		$context = preg_replace( "/\r\n?/", "\n", $context );
		// Strip control characters other than the newlines this field is
		// intentionally multi-line on (#bug 3 hardening).
		$context = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $context );
		$context = preg_replace( "/\n{3,}/", "\n\n", (string) $context );
		$context = trim( (string) $context );

		if ( strlen( $context ) > 6000 ) {
			$context = substr( $context, 0, 6000 ) . "\n\n[truncated]";
		}

		return $context;
	}

	/**
	 * Build a short list of important public URLs.
	 *
	 * @return array
	 */
	private function get_important_urls() {
		$items = array();

		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id > 0 && 'publish' === get_post_status( $front_page_id ) ) {
			$items[] = array(
				'label' => $this->sanitize_plain_text_line( get_the_title( $front_page_id ) ? get_the_title( $front_page_id ) : 'Home' ),
				'url'   => get_permalink( $front_page_id ),
			);
		}

		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id > 0 && 'publish' === get_post_status( $posts_page_id ) ) {
			$items[] = array(
				'label' => $this->sanitize_plain_text_line( get_the_title( $posts_page_id ) ),
				'url'   => get_permalink( $posts_page_id ),
			);
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 6,
				'post__not_in'   => array_filter( array( $front_page_id, $posts_page_id ) ),
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		foreach ( $pages as $page ) {
			$items[] = array(
				'label' => $this->sanitize_plain_text_line( $page->post_title ),
				'url'   => get_permalink( $page->ID ),
			);
		}

		$items = array_filter(
			$items,
			function ( $item ) {
				return ! empty( $item['label'] ) && ! empty( $item['url'] );
			}
		);

		return array_values( array_slice( $items, 0, 8 ) );
	}
}
