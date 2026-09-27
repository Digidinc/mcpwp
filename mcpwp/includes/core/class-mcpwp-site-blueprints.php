<?php
/**
 * Site Blueprint Library — multi-page site structure definitions.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages site blueprints: structured multi-page site definitions that
 * can be deployed in one instruction to create pages, menus, and site context.
 */
class Mcpwp_Site_Blueprints {

	const OPTION_KEY = 'mcpwp_site_blueprints';

	// -------------------------------------------------------------------------
	// CRUD

	/**
	 * List all blueprints (starters + custom).
	 *
	 * @param bool $include_starters Include built-in starter blueprints.
	 * @return array
	 */
	public static function list_all( bool $include_starters = true ): array {
		$stored  = self::load_stored();
		$starters = $include_starters ? self::starter_blueprints() : array();

		// Stored blueprints override starters with same ID.
		$merged = array_merge( $starters, $stored );
		return array_values( $merged );
	}

	/**
	 * Get one blueprint by ID.
	 *
	 * @param string $id
	 * @return array|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::list_all() as $bp ) {
			if ( ( $bp['id'] ?? '' ) === $id ) {
				return $bp;
			}
		}
		return null;
	}

	/**
	 * Save a new or updated custom blueprint.
	 *
	 * @param array $data
	 * @return array Saved blueprint (with generated ID).
	 */
	public static function save( array $data ): array {
		$stored = self::load_stored();
		$now    = gmdate( 'c' );

		$id = ! empty( $data['id'] ) ? sanitize_key( $data['id'] ) : 'bp-' . substr( md5( uniqid( '', true ) ), 0, 8 );

		$blueprint = array(
			'id'           => $id,
			'name'         => sanitize_text_field( $data['name'] ?? 'Untitled Blueprint' ),
			'description'  => sanitize_textarea_field( $data['description'] ?? '' ),
			'category'     => sanitize_key( $data['category'] ?? 'custom' ),
			'pages'        => self::sanitize_pages( $data['pages'] ?? array() ),
			'menus'        => self::sanitize_menus( $data['menus'] ?? array() ),
			'site_context' => sanitize_textarea_field( $data['site_context'] ?? '' ),
			'is_starter'   => false,
			'created_at'   => $stored[ $id ]['created_at'] ?? $now,
			'updated_at'   => $now,
		);

		$stored[ $id ] = $blueprint;
		update_option( self::OPTION_KEY, $stored, false );

		return $blueprint;
	}

	/**
	 * Delete a custom blueprint by ID.
	 *
	 * @param string $id
	 * @return bool False if not found or is a starter.
	 */
	public static function delete( string $id ): bool {
		$stored = self::load_stored();
		if ( ! isset( $stored[ $id ] ) ) {
			return false;
		}
		unset( $stored[ $id ] );
		update_option( self::OPTION_KEY, $stored, false );
		return true;
	}

	// -------------------------------------------------------------------------
	// Deployment

	/**
	 * Deploy a blueprint: create all pages, menus, and set site context.
	 *
	 * @param string $id       Blueprint ID.
	 * @param array  $overrides Overrides: name_prefix, post_status, site_context.
	 * @return array Deployment result with created page IDs and menu IDs.
	 */
	public static function deploy( string $id, array $overrides = array() ): array {
		$blueprint = self::get( $id );
		if ( ! $blueprint ) {
			return array( 'success' => false, 'message' => "Blueprint '{$id}' not found." );
		}

		$post_status = sanitize_key( $overrides['post_status'] ?? 'draft' );
		$name_prefix = sanitize_text_field( $overrides['name_prefix'] ?? '' );
		$created_pages = array();
		$slug_to_id    = array();

		// Content guardrails (#957 rule 2): deploying a blueprint publishes
		// every one of its pages in a single call — treat it the same as a
		// bulk_create_pages call for the bulk-approval threshold. Over the
		// threshold, every page is force-created as a draft and one
		// approval request covers publishing the whole set.
		$page_count = is_array( $blueprint['pages'] ) ? count( $blueprint['pages'] ) : 0;
		$bulk_plan  = ( class_exists( 'Mcpwp_Content_Guardrails' ) && 'publish' === $post_status )
			? Mcpwp_Content_Guardrails::plan_bulk_publish( array_fill( 0, $page_count, array( 'status' => 'publish' ) ) )
			: array( 'requires_approval' => false, 'threshold' => 0 );

		$forced_draft_ids = array();

		// Create pages. Per-page content guardrails (ramp cap, duplicate
		// detection) are enforced centrally by
		// Mcpwp_Content_Guardrails::on_insert_post_data() inside the
		// wp_insert_post() call below — not here; only the bulk-approval
		// threshold decision above (which needs the whole batch's size
		// before any single insert happens) stays call-site logic.
		foreach ( $blueprint['pages'] as $page_def ) {
			$title = $name_prefix
				? $name_prefix . ': ' . ( $page_def['title'] ?? 'Page' )
				: ( $page_def['title'] ?? 'Page' );

			$slug = ! empty( $page_def['slug'] ) ? sanitize_title( $page_def['slug'] ) : sanitize_title( $title );

			$content = self::build_page_placeholder_content( $page_def );

			$page_status = $bulk_plan['requires_approval'] ? 'draft' : $post_status;

			$page_id = wp_insert_post( array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => $page_status,
				'post_type'    => 'page',
				'post_content' => $content,
				'meta_input'   => array(
					'_mcpwp_blueprint_id'       => $id,
					'_mcpwp_blueprint_sections' => wp_json_encode( $page_def['sections'] ?? array() ),
				),
			) );

			if ( is_wp_error( $page_id ) ) {
				continue;
			}

			// Apply page template if specified.
			if ( ! empty( $page_def['template'] ) ) {
				update_post_meta( $page_id, '_wp_page_template', sanitize_text_field( $page_def['template'] ) );
			}

			if ( $bulk_plan['requires_approval'] ) {
				$forced_draft_ids[] = $page_id;
			}

			$slug_to_id[ $page_def['slug'] ?? $slug ] = $page_id;
			$created_pages[] = array(
				'id'       => $page_id,
				'page_id'  => $page_id,
				'title'    => $title,
				'slug'     => $slug,
				'url'      => get_permalink( $page_id ),
				'sections' => $page_def['sections'] ?? array(),
				'next_step' => 'Use wp_get_blueprint to generate Elementor sections for each section type listed, then wp_add_section to insert them.',
			);
		}

		// Create menus.
		$created_menus = array();
		foreach ( $blueprint['menus'] ?? array() as $menu_def ) {
			$menu_name = sanitize_text_field( $menu_def['name'] ?? 'Menu' );
			$menu_id   = wp_create_nav_menu( $menu_name );

			if ( is_wp_error( $menu_id ) ) {
				// Menu already exists — get ID.
				$existing = wp_get_nav_menu_object( $menu_name );
				$menu_id  = $existing ? $existing->term_id : null;
			}

			if ( $menu_id ) {
				foreach ( $menu_def['items'] ?? array() as $item_slug ) {
					$pid = $slug_to_id[ $item_slug ] ?? 0;
					if ( $pid ) {
						wp_update_nav_menu_item( $menu_id, 0, array(
							'menu-item-title'     => get_the_title( $pid ),
							'menu-item-object'    => 'page',
							'menu-item-object-id' => $pid,
							'menu-item-type'      => 'post_type',
							'menu-item-status'    => 'publish',
						) );
					}
				}
				$created_menus[] = array( 'menu_id' => $menu_id, 'name' => $menu_name );
			}
		}

		// Set site context if provided and none already exists.
		if ( ! empty( $blueprint['site_context'] ) && empty( get_option( 'mcpwp_site_context', '' ) ) ) {
			update_option( 'mcpwp_site_context', wp_kses_post( $blueprint['site_context'] ), false );
		}

		$result = array(
			'success'      => true,
			'blueprint_id' => $id,
			'blueprint'    => $blueprint['name'],
			'pages'        => $created_pages,
			'menus'        => $created_menus,
			'pages_count'  => count( $created_pages ),
			'note'         => 'All pages created as ' . $post_status . '. Use wp_set_elementor or wp_get_blueprint + wp_add_section to build page layouts.',
		);

		// The bulk-publish approval request itself is queued by the REST
		// controller (deploy_blueprint()), not here — creating it needs
		// guard_stable_identity_for_approvals()/current_requester_key_id(),
		// which only exist on a REST controller instance, and this is a
		// static method with no such context (#957 adversarial review
		// WARN-7). Hand back what it needs to do that.
		if ( $bulk_plan['requires_approval'] && ! empty( $forced_draft_ids ) ) {
			$result['note']                 = 'This blueprint publishes ' . count( $forced_draft_ids ) . ' pages at once (bulk threshold: ' . $bulk_plan['threshold'] . '), so every page was created as a draft instead.';
			$result['_bulk_approval_ids']   = $forced_draft_ids;
			$result['_bulk_approval_threshold'] = $bulk_plan['threshold'];
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Extraction

	/**
	 * Analyze the current site and generate a blueprint definition.
	 *
	 * @return array Blueprint schema (not saved — caller decides whether to persist).
	 */
	public static function extract(): array {
		$pages_query = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		) );

		$pages = array();
		foreach ( $pages_query as $post ) {
			$entry = array(
				'slug'     => $post->post_name,
				'title'    => $post->post_title,
				'template' => get_post_meta( $post->ID, '_wp_page_template', true ) ?: '',
				'sections' => array(),
			);

			// Detect Elementor section types if present, in whatever form the
			// tree is stored (#845). Data that cannot be read is flagged: an
			// empty section list would claim the page has none.
			$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
			if ( $elementor_data ) {
				$stored  = Mcpwp_Elementor_Stored_Tree::read( $elementor_data );
				$decoded = $stored['elements'];
				if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $stored ) ) {
					$entry['elementor_unreadable'] = true;
				}
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $section ) {
						if ( isset( $section['elements'] ) ) {
							foreach ( $section['elements'] as $col ) {
								if ( isset( $col['elements'] ) ) {
									foreach ( $col['elements'] as $widget ) {
										$type = $widget['widgetType'] ?? $widget['elType'] ?? '';
										if ( $type ) {
											$entry['sections'][] = $type;
										}
									}
								}
							}
						}
					}
					$entry['sections'] = array_values( array_unique( array_slice( $entry['sections'], 0, 6 ) ) );
				}
			}

			$pages[] = $entry;
		}

		// Extract menus.
		$nav_menus    = wp_get_nav_menus();
		$menu_defs    = array();
		$page_slugs   = wp_list_pluck( $pages, 'slug' );

		foreach ( $nav_menus as $menu ) {
			$items   = wp_get_nav_menu_items( $menu->term_id );
			$items   = is_array( $items ) ? $items : array();
			$slugs   = array();

			foreach ( $items as $item ) {
				if ( 'post_type' === $item->type ) {
					$slug = get_post_field( 'post_name', (int) $item->object_id );
					if ( in_array( $slug, $page_slugs, true ) ) {
						$slugs[] = $slug;
					}
				}
			}

			$menu_defs[] = array(
				'name'  => $menu->name,
				'items' => $slugs,
			);
		}

		return array(
			'id'           => 'extracted-' . gmdate( 'Ymd' ),
			'name'         => get_bloginfo( 'name' ) . ' — Extracted Blueprint',
			'description'  => 'Auto-extracted from ' . home_url() . ' on ' . gmdate( 'Y-m-d' ),
			'category'     => 'custom',
			'pages'        => $pages,
			'menus'        => $menu_defs,
			'site_context' => get_option( 'mcpwp_site_context', '' ),
			'is_starter'   => false,
			'extracted_at' => gmdate( 'c' ),
		);
	}

	// -------------------------------------------------------------------------
	// Starters

	/**
	 * @return array<string, array> Keyed by ID.
	 */
	public static function starter_blueprints(): array {
		return array(
			'law-firm' => array(
				'id'          => 'law-firm',
				'name'        => 'Law Firm',
				'description' => 'Professional law firm site: homepage, practice areas, attorney profiles, contact.',
				'category'    => 'professional',
				'is_starter'  => true,
				'pages'       => array(
					array( 'slug' => 'home',            'title' => 'Home',            'template' => '', 'sections' => array( 'hero', 'features', 'testimonials', 'cta' ) ),
					array( 'slug' => 'practice-areas',  'title' => 'Practice Areas',  'template' => '', 'sections' => array( 'text', 'features' ) ),
					array( 'slug' => 'our-team',        'title' => 'Our Team',        'template' => '', 'sections' => array( 'text', 'team' ) ),
					array( 'slug' => 'contact',         'title' => 'Contact',         'template' => '', 'sections' => array( 'contact_form', 'map' ) ),
				),
				'menus'       => array(
					array( 'name' => 'Primary', 'items' => array( 'home', 'practice-areas', 'our-team', 'contact' ) ),
				),
				'site_context' => 'Professional law firm. Conservative, authoritative tone. Trust signals and client outcomes prominent. No jargon. Call to action for free consultation.',
				'created_at'  => '',
				'updated_at'  => '',
			),
			'restaurant' => array(
				'id'          => 'restaurant',
				'name'        => 'Restaurant',
				'description' => 'Restaurant or café site: homepage, menu, reservations, about, contact.',
				'category'    => 'hospitality',
				'is_starter'  => true,
				'pages'       => array(
					array( 'slug' => 'home',         'title' => 'Home',         'template' => '', 'sections' => array( 'hero', 'features', 'gallery', 'cta' ) ),
					array( 'slug' => 'menu',         'title' => 'Menu',         'template' => '', 'sections' => array( 'text', 'pricing' ) ),
					array( 'slug' => 'reservations', 'title' => 'Reservations', 'template' => '', 'sections' => array( 'contact_form' ) ),
					array( 'slug' => 'about',        'title' => 'About',        'template' => '', 'sections' => array( 'text', 'stats' ) ),
					array( 'slug' => 'contact',      'title' => 'Contact',      'template' => '', 'sections' => array( 'contact_form', 'map' ) ),
				),
				'menus'       => array(
					array( 'name' => 'Primary', 'items' => array( 'home', 'menu', 'reservations', 'about', 'contact' ) ),
				),
				'site_context' => 'Restaurant/café. Warm, inviting tone. Food photography prominent. Hours and reservations easy to find. Local character emphasized.',
				'created_at'  => '',
				'updated_at'  => '',
			),
			'saas' => array(
				'id'          => 'saas',
				'name'        => 'SaaS Product',
				'description' => 'Software product site: landing page, features, pricing, blog, contact.',
				'category'    => 'tech',
				'is_starter'  => true,
				'pages'       => array(
					array( 'slug' => 'home',     'title' => 'Home',     'template' => '', 'sections' => array( 'hero', 'social_proof', 'features', 'pricing', 'faq', 'cta' ) ),
					array( 'slug' => 'features', 'title' => 'Features', 'template' => '', 'sections' => array( 'hero', 'features', 'stats' ) ),
					array( 'slug' => 'pricing',  'title' => 'Pricing',  'template' => '', 'sections' => array( 'pricing', 'faq', 'cta' ) ),
					array( 'slug' => 'about',    'title' => 'About',    'template' => '', 'sections' => array( 'text', 'team', 'stats' ) ),
					array( 'slug' => 'contact',  'title' => 'Contact',  'template' => '', 'sections' => array( 'contact_form' ) ),
				),
				'menus'       => array(
					array( 'name' => 'Primary', 'items' => array( 'home', 'features', 'pricing', 'about', 'contact' ) ),
				),
				'site_context' => 'SaaS product. Clear value proposition above the fold. Feature comparison easy to scan. Pricing transparent. Social proof (logos, testimonials) prominent. Free trial CTA.',
				'created_at'  => '',
				'updated_at'  => '',
			),
			'real-estate' => array(
				'id'          => 'real-estate',
				'name'        => 'Real Estate Agency',
				'description' => 'Real estate agency: listings, agents, about, contact.',
				'category'    => 'professional',
				'is_starter'  => true,
				'pages'       => array(
					array( 'slug' => 'home',     'title' => 'Home',     'template' => '', 'sections' => array( 'hero', 'features', 'testimonials', 'cta' ) ),
					array( 'slug' => 'listings', 'title' => 'Listings', 'template' => '', 'sections' => array( 'text', 'gallery' ) ),
					array( 'slug' => 'agents',   'title' => 'Our Agents', 'template' => '', 'sections' => array( 'text', 'team' ) ),
					array( 'slug' => 'about',    'title' => 'About',    'template' => '', 'sections' => array( 'text', 'stats', 'testimonials' ) ),
					array( 'slug' => 'contact',  'title' => 'Contact',  'template' => '', 'sections' => array( 'contact_form', 'map' ) ),
				),
				'menus'       => array(
					array( 'name' => 'Primary', 'items' => array( 'home', 'listings', 'agents', 'about', 'contact' ) ),
				),
				'site_context' => 'Real estate agency. Local market expertise emphasized. Property search/listings prominent. Agent profiles build trust. Area photos and market stats.',
				'created_at'  => '',
				'updated_at'  => '',
			),
			'portfolio' => array(
				'id'          => 'portfolio',
				'name'        => 'Creative Portfolio',
				'description' => 'Designer, photographer, or freelancer portfolio: work, about, services, contact.',
				'category'    => 'creative',
				'is_starter'  => true,
				'pages'       => array(
					array( 'slug' => 'home',     'title' => 'Home',     'template' => '', 'sections' => array( 'hero', 'portfolio', 'stats' ) ),
					array( 'slug' => 'work',     'title' => 'Work',     'template' => '', 'sections' => array( 'gallery' ) ),
					array( 'slug' => 'about',    'title' => 'About',    'template' => '', 'sections' => array( 'text', 'stats' ) ),
					array( 'slug' => 'services', 'title' => 'Services', 'template' => '', 'sections' => array( 'features', 'pricing' ) ),
					array( 'slug' => 'contact',  'title' => 'Contact',  'template' => '', 'sections' => array( 'contact_form' ) ),
				),
				'menus'       => array(
					array( 'name' => 'Primary', 'items' => array( 'home', 'work', 'about', 'services', 'contact' ) ),
				),
				'site_context' => 'Creative portfolio. Work speaks first — large visuals. Personal voice throughout. Services and pricing clear. Easy to get in touch.',
				'created_at'  => '',
				'updated_at'  => '',
			),
		);
	}

	// -------------------------------------------------------------------------
	// Helpers

	private static function build_page_placeholder_content( array $page_def ): string {
		$sections = $page_def['sections'] ?? array();
		if ( empty( $sections ) ) {
			return '';
		}
		$lines = array( '<!-- Blueprint sections: ' . implode( ', ', $sections ) . ' -->' );
		$lines[] = '<p>This page was created from a site blueprint. Use wp_get_blueprint to generate Elementor sections: ' . implode( ', ', $sections ) . '</p>';
		return implode( "\n", $lines );
	}

	private static function sanitize_pages( array $pages ): array {
		$clean = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$clean[] = array(
				'slug'     => sanitize_title( $page['slug'] ?? '' ),
				'title'    => sanitize_text_field( $page['title'] ?? '' ),
				'template' => sanitize_text_field( $page['template'] ?? '' ),
				'sections' => array_map( 'sanitize_key', (array) ( $page['sections'] ?? array() ) ),
			);
		}
		return $clean;
	}

	private static function sanitize_menus( array $menus ): array {
		$clean = array();
		foreach ( $menus as $menu ) {
			if ( ! is_array( $menu ) ) {
				continue;
			}
			$clean[] = array(
				'name'  => sanitize_text_field( $menu['name'] ?? '' ),
				'items' => array_map( 'sanitize_title', (array) ( $menu['items'] ?? array() ) ),
			);
		}
		return $clean;
	}

	/**
	 * @return array<string, array> Keyed by ID.
	 */
	private static function load_stored(): array {
		$data = get_option( self::OPTION_KEY, array() );
		return is_array( $data ) ? $data : array();
	}
}
