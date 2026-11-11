<?php
/**
 * Classification coverage for forgiving parameter names (#994).
 *
 * This inventory records the existing remapping policy; it does not grant
 * permissions. New schema parameters need an explicit classification here,
 * including parameters registered by third-party plugins at runtime.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-figma.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-ai-integration.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-figma-integration.php';

final class ForgivingParamsClassificationTest extends TestCase {

	/**
	 * Expected classifications, independent of PRIVILEGED_PARAM_MARKERS:
	 * true means privileged; false means eligible for near-miss mapping.
	 * Keep names explicit so a new parameter cannot inherit an unchecked default.
	 */
	private const PARAM_CLASSIFICATIONS = array(
		'action'                              => false,
		'address'                             => false,
		'after'                               => false,
		'alerts_5xx_threshold'                => false,
		'alerts_auth_threshold'               => true,
		'alerts_cooldown_minutes'             => false,
		'alerts_enabled'                      => true,
		'alerts_window_minutes'               => false,
		'allow_compounding'                   => true,
		'allow_destructive'                   => true,
		'allow_restricted_blocks'             => true,
		'allow_secrets'                       => true,
		'allowed_origins'                     => true,
		'alt'                                 => false,
		'analysis_summary'                    => false,
		'anchor'                              => false,
		'approval_note'                       => false,
		'approval_required'                   => false,
		'archetype_class'                     => false,
		'archetype_scope'                     => true,
		'archetype_style'                     => false,
		'arguments'                           => false,
		'attachment_id'                       => false,
		'auto_save'                           => false,
		'avoid'                               => false,
		'background'                          => false,
		'before'                              => false,
		'blocks'                              => false,
		'blog_public'                         => false,
		'bots'                                => false,
		'brand_terms'                         => false,
		'burst_limit'                         => false,
		'button_text'                         => false,
		'button_url'                          => false,
		'canonical'                           => false,
		'canonical_url'                       => false,
		'caption'                             => false,
		'categories'                          => false,
		'category'                            => false,
		'classes'                             => false,
		'code'                                => false,
		'columns'                             => false,
		'conditions'                          => false,
		'config'                              => false,
		'container_width'                     => false,
		'content'                             => false,
		'context'                             => false,
		'convert'                             => false,
		'course_id'                           => false,
		'css'                                 => false,
		'cta_text'                            => false,
		'cta_url'                             => false,
		'cursor'                              => false,
		'custom_colors'                       => false,
		'custom_css'                          => false,
		'custom_typography'                   => false,
		'customer'                            => false,
		'data'                                => false,
		'date_end'                            => false,
		'date_max'                            => false,
		'date_min'                            => false,
		'date_start'                          => false,
		'days'                                => false,
		'default_generic_fonts'               => false,
		'delete_settings'                     => true,
		'depth'                               => false,
		'description'                         => false,
		'destination'                         => false,
		'detail'                              => false,
		'direction'                           => false,
		'downloadable'                        => false,
		'dry_run'                             => false,
		'due_date'                            => false,
		'duration'                            => false,
		'element'                             => false,
		'element_id'                          => false,
		'elementor_data'                      => false,
		'elementor_data_base64'               => false,
		'enable_logging'                      => false,
		'enabled'                             => true,
		'enrich'                              => false,
		'event_limit'                         => false,
		'events'                              => false,
		'excerpt'                             => false,
		'expected_hash'                       => false,
		'faqs'                                => false,
		'featured_review'                     => false,
		'fields'                              => false,
		'file'                                => false,
		'file_key'                            => false,
		'filename'                            => false,
		'find'                                => false,
		'focus_keyword'                       => false,
		'force'                               => true,
		'force_type_change'                   => true,
		'form_id'                             => false,
		'format'                              => false,
		'full'                                => false,
		'full_page'                           => false,
		'gallery_image_ids'                   => false,
		'github_repo'                         => false,
		'github_token'                        => true,
		'gl'                                  => false,
		'graph_limit'                         => false,
		'has_metric'                          => false,
		'heading'                             => false,
		'height'                              => false,
		'hl'                                  => false,
		'id'                                  => false,
		'identifier'                          => false,
		'ids'                                 => false,
		'image_base64'                        => false,
		'image_id'                            => false,
		'image_url'                           => false,
		'images'                              => false,
		'include_chrome'                      => false,
		'include_content'                     => false,
		'include_db'                          => false,
		'include_drafts'                      => false,
		'include_files'                       => false,
		'include_patterns_content'            => false,
		'include_plugins'                     => false,
		'include_raw'                         => false,
		'include_revoked'                     => false,
		'include_starters'                    => false,
		'include_summary'                     => false,
		'inline'                              => false,
		'instant_check'                       => false,
		'instruction'                         => false,
		'issue_id'                            => false,
		'item_id'                             => false,
		'items'                               => false,
		'key'                                 => false,
		'key_features'                        => false,
		'kind'                                => false,
		'label'                               => false,
		'language'                            => false,
		'length'                              => false,
		'level'                               => false,
		'limit'                               => false,
		'linked_archetype_ids'                => false,
		'linked_part_ids'                     => false,
		'location'                            => false,
		'log_retention_days'                  => false,
		'log_store_response_data'             => false,
		'manage_stock'                        => false,
		'max'                                 => false,
		'max_bytes'                           => false,
		'max_inbound'                         => false,
		'max_length'                          => false,
		'max_suggestions'                     => false,
		'mcp_tool_profile'                    => false,
		'media_id'                            => false,
		'menu_id'                             => false,
		'menu_order'                          => false,
		'menus'                               => false,
		'meta'                                => false,
		'metric'                              => false,
		'mime_type'                           => false,
		'min_impressions'                     => false,
		'min_inbound'                         => false,
		'mode'                                => false,
		'modified_before'                     => false,
		'must_keep'                           => false,
		'name'                                => false,
		'name_prefix'                         => false,
		'namespace'                           => false,
		'no_edge_type'                        => false,
		'node_id'                             => false,
		'node_key'                            => false,
		'nofollow'                            => false,
		'noindex'                             => true,
		'note'                                => false,
		'note_customer'                       => false,
		'notes'                               => false,
		'oauth_client_id'                     => true,
		'oauth_client_secret'                 => true,
		'oauth_enabled'                       => true,
		'oauth_token_ttl'                     => true,
		'object'                              => false,
		'object_id'                           => false,
		'offset'                              => false,
		'og_description'                      => false,
		'og_image'                            => false,
		'og_title'                            => false,
		'operation_id'                        => false,
		'operations'                          => false,
		'order'                               => false,
		'order_by'                            => false,
		'order_dir'                           => false,
		'orderby'                             => false,
		'orphan'                              => false,
		'overwrite'                           => true,
		'page'                                => false,
		'page_for_posts'                      => false,
		'page_id'                             => false,
		'page_ids'                            => false,
		'page_intent'                         => false,
		'page_on_front'                       => false,
		'page_settings'                       => false,
		'page_settings_mode'                  => false,
		'page_title_selector'                 => false,
		'pages'                               => false,
		'parent'                              => false,
		'parent_id'                           => false,
		'part_kind'                           => false,
		'part_style'                          => false,
		'part_tags'                           => false,
		'passing_grade'                       => false,
		'per_page'                            => false,
		'period'                              => false,
		'photo_id'                            => false,
		'plans'                               => false,
		'plugin'                              => false,
		'position'                            => false,
		'post_id'                             => false,
		'post_ids'                            => false,
		'post_status'                         => true,
		'post_type'                           => false,
		'post_types'                          => false,
		'posts'                               => false,
		'preview'                             => false,
		'price'                               => false,
		'priority'                            => false,
		'product_id'                          => false,
		'product_type'                        => false,
		'prompt'                              => false,
		'provider'                            => false,
		'purge_everything'                    => true,
		'qty'                                 => false,
		'quality'                             => false,
		'query'                               => false,
		'reason'                              => false,
		'refresh'                             => false,
		'registration_end_date'               => false,
		'registration_end_time'               => false,
		'regular_price'                       => false,
		'repair_ids'                          => false,
		'replace'                             => false,
		'requests_per_hour'                   => false,
		'requests_per_minute'                 => false,
		'requirements'                        => false,
		'retake_count'                        => false,
		'review'                              => false,
		'role'                                => true,
		'rows'                                => false,
		'run_id'                              => false,
		'sale_price'                          => false,
		'save'                                => false,
		'save_to_media'                       => false,
		'scope'                               => true,
		'scopes'                              => true,
		'search'                              => false,
		'secret'                              => true,
		'section_index'                       => false,
		'section_outline'                     => false,
		'sections'                            => false,
		'seed'                                => false,
		'selector'                            => false,
		'seo_description'                     => false,
		'seo_title'                           => false,
		'setting'                             => false,
		'settings'                            => false,
		'severity'                            => false,
		'short_description'                   => false,
		'show_on_front'                       => false,
		'sidebar'                             => false,
		'since'                               => false,
		'site_context'                        => false,
		'size'                                => false,
		'sku'                                 => false,
		'slug'                                => false,
		'source'                              => false,
		'source_id'                           => false,
		'source_type'                         => false,
		'space_between_widgets'               => false,
		'status'                              => true,
		'stock_quantity'                      => false,
		'stock_status'                        => true,
		'store'                               => false,
		'stretched_section_container'         => false,
		'strip_defaults'                      => false,
		'style'                               => false,
		'subheading'                          => false,
		'system_colors'                       => false,
		'system_typography'                   => false,
		'tab'                                 => false,
		'tag'                                 => false,
		'tags'                                => false,
		'target'                              => false,
		'target_audiences'                    => false,
		'target_id'                           => false,
		'taxonomy'                            => false,
		'template'                            => false,
		'template_id'                         => false,
		'terms'                               => false,
		'text'                                => false,
		'threshold'                           => false,
		'time_end'                            => false,
		'time_start'                          => false,
		'title'                               => false,
		'tool'                                => false,
		'tool_categories'                     => false,
		'topic'                               => false,
		'ttl_days'                            => false,
		'twitter_description'                 => false,
		'twitter_image'                       => false,
		'twitter_title'                       => false,
		'type'                                => false,
		'types'                               => false,
		'updates'                             => false,
		'url'                                 => false,
		'urls'                                => false,
		'value'                               => false,
		'viewport_lg'                         => false,
		'viewport_md'                         => false,
		'virtual'                             => false,
		'voice_id'                            => false,
		'webhook_url'                         => false,
		'weight'                              => false,
		'whitelist'                           => false,
		'widget_id'                           => false,
		'widget_type'                         => false,
		'widgets'                             => false,
		'width'                               => false,
		'widths'                              => false,
		'word_count_lt'                       => false,
	);

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
		$GLOBALS['mcpwp_test_fs']         = new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' );
		$this->reset_license();
		Mcpwp_Integration::clear_cache();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
		$GLOBALS['mcpwp_test_fs']      = null;
		$this->reset_license();
		Mcpwp_Integration::clear_cache();
	}

	private function reset_license(): void {
		$property = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * Read registries before tools/list filters inactive plugin capabilities.
	 * Include integration definitions and the real runtime custom-tool registry.
	 */
	private function all_tools( Mcpwp_REST_MCP $controller ): array {
		$tools = array();
		foreach ( array( 'free_registry', 'pro_registry', 'meta_registry', 'custom_registry' ) as $name ) {
			$property = new ReflectionProperty( Mcpwp_REST_MCP::class, $name );
			$property->setAccessible( true );
			$tools = array_merge( $tools, $property->getValue( $controller )->get_tools() );
		}
		$integrations = array_merge(
			array( new Mcpwp_MCP_AI_Integration(), new Mcpwp_MCP_Figma_Integration() ),
			Mcpwp_Integration::resolve_all()
		);
		foreach ( $integrations as $integration ) {
			$tools = array_merge( $tools, $integration->get_tools() );
		}
		return $tools;
	}

	public function test_every_schema_parameter_has_an_explicit_remapping_classification(): void {
		add_filter(
			'mcpwp_register_tools',
			static function ( $tools ) {
				$tools[] = array(
					'name'        => 'classification_fixture',
					'description' => 'Parameter classification fixture.',
					'rest_path'   => '/classification/v1/item',
					'input_props' => array(
						'title' => array( 'type' => 'string' ),
						'force' => array( 'type' => 'boolean' ),
					),
				);
				return $tools;
			}
		);

		$controller = new Mcpwp_REST_MCP();
		$classify   = new ReflectionMethod( Mcpwp_REST_MCP::class, 'is_privileged_param_name' );
		$classify->setAccessible( true );
		$tools = $this->all_tools( $controller );
		$this->assertContains( 'classification_fixture', array_column( $tools, 'name' ) );

		foreach ( $tools as $tool ) {
			foreach ( (array) ( $tool['inputSchema']['properties'] ?? array() ) as $name => $schema ) {
				$context = $tool['name'] . '.' . $name;
				$this->assertArrayHasKey( $name, self::PARAM_CLASSIFICATIONS, $context . ': classify this parameter as privileged or eligible for near-miss mapping.' );
				$this->assertSame( self::PARAM_CLASSIFICATIONS[ $name ], $classify->invoke( $controller, $name ), $context . ': remapping classification changed.' );
			}
		}
	}
}
