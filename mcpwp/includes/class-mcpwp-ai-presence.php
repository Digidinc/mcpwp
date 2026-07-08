<?php
/**
 * Public AI presence helpers.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expose crawl-friendly AI guidance for the public website.
 */
class Mcpwp_AI_Presence {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'maybe_serve_llms_txt' ) );
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'output_llms_link_tag' ), 1 );
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

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=' . get_bloginfo( 'charset' ) );
		header( 'X-Robots-Tag: noarchive' );
		echo esc_html( $this->generate_llms_txt() );
		exit;
	}

	/**
	 * Add sitemap and llms.txt hint to robots.txt.
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

		$sitemap_url = home_url( '/wp-sitemap.xml' );
		$llms_url    = home_url( '/llms.txt' );

		if ( false === stripos( $output, $sitemap_url ) ) {
			$lines[] = 'Sitemap: ' . esc_url_raw( $sitemap_url );
		}

		if ( $public ) {
			$lines[] = '# LLM summary: ' . esc_url_raw( $llms_url );
		}

		return implode( "\n", array_filter( $lines ) ) . "\n";
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
		$site_name        = (string) get_bloginfo( 'name' );
		$site_description = (string) get_bloginfo( 'description' );
		$site_context     = $this->normalize_site_context( get_option( 'mcpwp_site_context', '' ) );

		$lines   = array();
		$lines[] = '# ' . $site_name;

		if ( '' !== $site_description ) {
			$lines[] = '> ' . $site_description;
		}

		$lines[] = '';
		$lines[] = '## Canonical';
		$lines[] = '- Website: ' . home_url( '/' );
		$lines[] = '- Sitemap: ' . home_url( '/wp-sitemap.xml' );
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
			$lines[] = 'No explicit AI site context is configured yet. Use the mumcp settings to define the brand voice, design system, target audience, and recurring page patterns.';
		}

		$lines[] = '';
		$lines[] = '## Guidance For AI Systems';
		$lines[] = '- Prefer canonical page URLs over query-string variants.';
		$lines[] = '- Use the sitemap to discover indexable content.';
		$lines[] = '- Treat the site character section as the preferred brand, content, and design brief when summarizing or generating related content.';

		return implode( "\n", $lines ) . "\n";
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

		$context = wp_strip_all_tags( html_entity_decode( $context, ENT_QUOTES, get_bloginfo( 'charset' ) ) );
		$context = preg_replace( "/\r\n?/", "\n", $context );
		$context = preg_replace( "/\n{3,}/", "\n\n", $context );
		$context = trim( $context );

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
				'label' => get_the_title( $front_page_id ) ? get_the_title( $front_page_id ) : 'Home',
				'url'   => get_permalink( $front_page_id ),
			);
		}

		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id > 0 && 'publish' === get_post_status( $posts_page_id ) ) {
			$items[] = array(
				'label' => get_the_title( $posts_page_id ),
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
				'label' => $page->post_title,
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
