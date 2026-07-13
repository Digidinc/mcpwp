<?php
/**
 * WooCommerce SEO intelligence.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only WooCommerce SEO report for product content.
 */
class Mcpwp_WooCommerce_SEO {

	/**
	 * Build a read-only WooCommerce SEO report.
	 *
	 * @param array $args Report args.
	 * @return array
	 */
	public static function get_report( $args = array() ) {
		$limit    = isset( $args['limit'] ) ? max( 1, min( 100, absint( $args['limit'] ) ) ) : 25;
		$status   = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : 'publish';
		$statuses = 'any' === $status ? array( 'publish', 'draft', 'private' ) : array( $status );
		$products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => $statuses,
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items          = array();
		$error_count    = 0;
		$warning_count  = 0;
		$opportunities  = 0;
		$total_clicks   = 0;
		$total_impr     = 0;

		foreach ( $products as $product ) {
			$item = self::inspect_product( $product );
			foreach ( $item['issues'] as $issue ) {
				if ( 'error' === $issue['severity'] ) {
					$error_count++;
				} elseif ( 'warning' === $issue['severity'] ) {
					$warning_count++;
				}
			}
			$opportunities += count( $item['opportunities'] );
			$total_clicks  += (int) ( $item['search_performance']['clicks'] ?? 0 );
			$total_impr    += (int) ( $item['search_performance']['impressions'] ?? 0 );
			$items[]        = $item;
		}

		usort(
			$items,
			static function ( $a, $b ) {
				if ( (int) $a['score'] === (int) $b['score'] ) {
					return (int) $b['search_performance']['impressions'] <=> (int) $a['search_performance']['impressions'];
				}
				return (int) $b['score'] <=> (int) $a['score'];
			}
		);

		return array(
			'schema_version' => '2026-05-20',
			'summary'        => array(
				'woocommerce_detected' => self::woocommerce_detected(),
				'products_inspected'   => count( $items ),
				'error_count'          => $error_count,
				'warning_count'        => $warning_count,
				'opportunity_count'    => $opportunities,
				'search_clicks'        => $total_clicks,
				'search_impressions'   => $total_impr,
				'search_ctr'           => $total_impr > 0 ? round( $total_clicks / $total_impr, 4 ) : 0,
			),
			'filters'        => array(
				'status' => $status,
				'limit'  => $limit,
			),
			'products'       => $items,
			'workflow'       => array(
				'read'     => 'Use this report after wp_get_seo_trends to prioritize product SEO work with search evidence.',
				'prepare'  => 'Prepare product copy, image alt text, category mapping, or internal links through approval-safe workflows.',
				'guard'    => 'This endpoint is read-only. It never changes prices, stock, product descriptions, categories, images, schema, or metadata.',
				'approval' => 'Any commerce-facing change must be human approved because product pages affect revenue and customer expectations.',
			),
		);
	}

	/**
	 * Inspect one product.
	 *
	 * @param WP_Post|object $product Product post.
	 * @return array
	 */
	private static function inspect_product( $product ) {
		$product_id    = absint( $product->ID ?? 0 );
		$title         = get_the_title( $product_id );
		$content       = wp_strip_all_tags( (string) ( $product->post_content ?? '' ) );
		$excerpt       = wp_strip_all_tags( (string) ( $product->post_excerpt ?? '' ) );
		$content_words = str_word_count( $content );
		$excerpt_words = str_word_count( $excerpt );
		$url           = get_permalink( $product_id );
		$meta          = self::product_meta( $product_id );
		$issues        = array();
		$opportunities = array();

		if ( '' === trim( $title ) ) {
			$issues[] = self::issue( 'missing_product_title', 'error', __( 'Product title is missing.', 'mumega-mcp' ), __( 'Add a clear product name before indexing or promotion.', 'mumega-mcp' ) );
		}
		if ( $content_words < 120 ) {
			$issues[] = self::issue( 'thin_product_description', 'warning', __( 'Product description is thin.', 'mumega-mcp' ), __( 'Add useful product details, use cases, specs, materials, fit, compatibility, or comparison guidance.', 'mumega-mcp' ) );
		}
		if ( $excerpt_words < 12 ) {
			$issues[] = self::issue( 'missing_short_description', 'warning', __( 'Short product description is missing or too short.', 'mumega-mcp' ), __( 'Add a concise benefit-led summary for product listings and product-page scanning.', 'mumega-mcp' ) );
		}
		if ( '' === $meta['price'] ) {
			$issues[] = self::issue( 'missing_price_signal', 'warning', __( 'Product price signal is missing.', 'mumega-mcp' ), __( 'Confirm product price data before relying on Product schema or shopping search surfaces.', 'mumega-mcp' ) );
		}
		if ( '' === $meta['sku'] ) {
			$opportunities[] = self::opportunity( 'add_sku', __( 'Add SKU or product identifier where appropriate.', 'mumega-mcp' ), 'seo_audit_triage' );
		}
		if ( empty( $meta['categories'] ) ) {
			$issues[] = self::issue( 'missing_product_category', 'warning', __( 'Product has no category evidence.', 'mumega-mcp' ), __( 'Assign a relevant product category so shoppers, crawlers, and agents understand the catalog structure.', 'mumega-mcp' ) );
		}
		if ( ! $meta['has_featured_image'] ) {
			$issues[] = self::issue( 'missing_product_image', 'warning', __( 'Product has no featured image.', 'mumega-mcp' ), __( 'Add a descriptive product image before promotion.', 'mumega-mcp' ) );
		}
		if ( 'outofstock' === $meta['stock_status'] ) {
			$opportunities[] = self::opportunity( 'out_of_stock_seo_review', __( 'Review whether out-of-stock product pages need alternatives, internal links, or noindex strategy.', 'mumega-mcp' ), 'seo_audit_triage' );
		}

		$search = self::search_performance_for_url( $url );
		if ( empty( $search['clicks'] ) && ! empty( $search['impressions'] ) ) {
			$opportunities[] = self::opportunity( 'high_impression_low_click_product', __( 'Search impressions exist but clicks are weak; review title, description, price, image, and internal links.', 'mumega-mcp' ), 'seo_audit_triage' );
		}

		$score = ( count( $issues ) * 20 ) + ( count( $opportunities ) * 8 ) + min( 30, (int) floor( (int) ( $search['impressions'] ?? 0 ) / 25 ) );

		return array(
			'id'                 => $product_id,
			'title'              => sanitize_text_field( $title ),
			'status'             => sanitize_key( (string) ( $product->post_status ?? '' ) ),
			'url'                => esc_url_raw( $url ),
			'score'              => $score,
			'content'            => array(
				'description_words'       => $content_words,
				'short_description_words' => $excerpt_words,
			),
			'commerce_evidence'  => $meta,
			'search_performance' => $search,
			'issues'             => $issues,
			'opportunities'      => $opportunities,
			'next_steps'         => self::next_steps( $issues, $opportunities ),
		);
	}

	/**
	 * Product meta evidence.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private static function product_meta( $product_id ) {
		return array(
			'sku'                => sanitize_text_field( (string) get_post_meta( $product_id, '_sku', true ) ),
			'price'              => sanitize_text_field( (string) get_post_meta( $product_id, '_price', true ) ),
			'regular_price'      => sanitize_text_field( (string) get_post_meta( $product_id, '_regular_price', true ) ),
			'sale_price'         => sanitize_text_field( (string) get_post_meta( $product_id, '_sale_price', true ) ),
			'stock_status'       => sanitize_key( (string) get_post_meta( $product_id, '_stock_status', true ) ),
			'categories'         => self::term_names( $product_id, 'product_cat' ),
			'tags'               => self::term_names( $product_id, 'product_tag' ),
			'has_featured_image' => self::has_featured_image( $product_id ),
		);
	}

	/**
	 * Get search performance for URL.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	private static function search_performance_for_url( $url ) {
		if ( ! class_exists( 'Mcpwp_Search_Performance' ) ) {
			return array(
				'clicks'      => 0,
				'impressions' => 0,
				'ctr'         => 0,
				'position'    => 0,
				'top_queries' => array(),
			);
		}
		$report = Mcpwp_Search_Performance::get_report(
			array(
				'url'   => $url,
				'days'  => 180,
				'limit' => 5,
			)
		);
		return array(
			'clicks'      => (int) ( $report['summary']['clicks'] ?? 0 ),
			'impressions' => (int) ( $report['summary']['impressions'] ?? 0 ),
			'ctr'         => (float) ( $report['summary']['ctr'] ?? 0 ),
			'position'    => (float) ( $report['summary']['position'] ?? 0 ),
			'top_queries' => isset( $report['top_queries'] ) && is_array( $report['top_queries'] ) ? $report['top_queries'] : array(),
		);
	}

	/**
	 * Build issue record.
	 *
	 * @param string $code           Code.
	 * @param string $severity       Severity.
	 * @param string $message        Message.
	 * @param string $recommendation Recommendation.
	 * @return array
	 */
	private static function issue( $code, $severity, $message, $recommendation ) {
		return array(
			'code'              => sanitize_key( $code ),
			'severity'          => sanitize_key( $severity ),
			'message'           => sanitize_text_field( $message ),
			'recommendation'    => sanitize_text_field( $recommendation ),
			'approval_required' => true,
		);
	}

	/**
	 * Build opportunity record.
	 *
	 * @param string $code     Code.
	 * @param string $message  Message.
	 * @param string $playbook Playbook.
	 * @return array
	 */
	private static function opportunity( $code, $message, $playbook ) {
		return array(
			'code'              => sanitize_key( $code ),
			'message'           => sanitize_text_field( $message ),
			'playbook'          => sanitize_key( $playbook ),
			'approval_required' => true,
		);
	}

	/**
	 * Recommended next steps.
	 *
	 * @param array $issues        Issues.
	 * @param array $opportunities Opportunities.
	 * @return array
	 */
	private static function next_steps( $issues, $opportunities ) {
		$steps = array();
		if ( ! empty( $issues ) ) {
			$steps[] = array(
				'tool'     => 'wp_run_seo_autofix_plan',
				'playbook' => 'seo_audit_triage',
				'action'   => __( 'Resolve product SEO issues through approval-first content, media, metadata, or internal-link changes.', 'mumega-mcp' ),
			);
		}
		if ( ! empty( $opportunities ) ) {
			$steps[] = array(
				'tool'     => 'wp_get_seo_trends',
				'playbook' => 'seo_audit_triage',
				'action'   => __( 'Use search evidence to decide whether a product page needs copy refresh, internal links, or category cleanup.', 'mumega-mcp' ),
			);
		}
		return $steps;
	}

	/**
	 * Term names for a taxonomy.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	private static function term_names( $post_id, $taxonomy ) {
		if ( ! function_exists( 'get_the_terms' ) ) {
			return array();
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}
		return array_values(
			array_map(
				static function ( $term ) {
					return sanitize_text_field( (string) ( $term->name ?? '' ) );
				},
				$terms
			)
		);
	}

	/**
	 * Featured image presence.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function has_featured_image( $post_id ) {
		if ( function_exists( 'has_post_thumbnail' ) ) {
			return (bool) has_post_thumbnail( $post_id );
		}
		return '' !== (string) get_post_meta( $post_id, '_thumbnail_id', true );
	}

	/**
	 * WooCommerce detection.
	 *
	 * @return bool
	 */
	private static function woocommerce_detected() {
		return class_exists( 'WooCommerce' ) || class_exists( 'WC' ) || function_exists( 'wc_get_product' );
	}
}
