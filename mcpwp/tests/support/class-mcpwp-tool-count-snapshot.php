<?php
/**
 * Live tool-count snapshot for docs/marketing parity (#650).
 *
 * Counts match ToolListBudgetTest's worst-case catalog: every optional site
 * plugin reported active, integration registries loaded, Pro toggled via the
 * Freemius test stub — the largest tools/list a release can advertise.
 *
 * @package MCPWP
 */

require_once dirname( __DIR__, 2 ) . '/includes/core/class-mcpwp-figma.php';
require_once dirname( __DIR__, 2 ) . '/includes/mcp/class-mcpwp-mcp-ai-integration.php';
require_once dirname( __DIR__, 2 ) . '/includes/mcp/class-mcpwp-mcp-figma-integration.php';

final class Mcpwp_Tool_Count_Snapshot {

	/** MCP protocol for tools/list emission tests. */
	const PROTOCOL = '2025-11-25';

	/** GitHub About description (≤350 chars) — keep in sync with docs/launch/REPO-METADATA.md. */
	const GITHUB_ABOUT_SUGGESTED = 'Turn any WordPress site into an MCP server — let Claude, Cursor, and any MCP client build pages, edit Elementor, manage WooCommerce with Pro, and handle media/SEO through natural language. 190+ tools free, 300+ with Pro. Governed, approval-gated, auditable.';

	/**
	 * Test-only override for agency marker path (never write includes/agency-license.php in tests).
	 *
	 * @var string|null
	 */
	public static $agency_marker_path_override = null;

	/**
	 * Largest capability map used for public tool-count claims.
	 *
	 * @return array<string,bool|string>
	 */
	public static function max_capabilities(): array {
		return array(
			'gutenberg'             => true,
			'elementor'             => true,
			'elementor_pro'         => true,
			'elementor_layout_mode' => 'container',
			'woocommerce'           => true,
			'yoast'                 => true,
			'seo'                   => true,
			'forms'                 => true,
			'cf7'                   => true,
			'learnpress'            => true,
			'wpml'                  => true,
			'polylang'              => true,
		);
	}

	/**
	 * Round down to a marketing "N+" label (floor to tens, never above the real count).
	 */
	public static function marketing_plus_label( int $count ): string {
		if ( $count <= 0 ) {
			return '0';
		}
		$floor = (int) ( floor( $count / 10 ) * 10 );
		if ( $floor >= $count ) {
			$floor = max( 0, $floor - 10 );
		}
		return $floor . '+';
	}

	/**
	 * Refuse to generate counts when the local tree would skew Free tier.
	 *
	 * @throws RuntimeException When agency marker or MCPWP_TESTS would force Pro.
	 */
	public static function agency_marker_path(): string {
		if ( is_string( self::$agency_marker_path_override ) && '' !== self::$agency_marker_path_override ) {
			return self::$agency_marker_path_override;
		}
		return dirname( __DIR__, 2 ) . '/includes/agency-license.php';
	}

	public static function assert_clean_generation_context(): void {
		$marker = self::agency_marker_path();
		if ( is_file( $marker ) ) {
			throw new RuntimeException(
				'includes/agency-license.php is present — remove it before generating tool counts (it forces Pro and skews Free counts).'
			);
		}
		if ( defined( 'MCPWP_TESTS' ) && MCPWP_TESTS ) {
			throw new RuntimeException(
				'MCPWP_TESTS grants Pro under CLI — do not define it when generating tool counts.'
			);
		}
	}

	/**
	 * @param bool $pro When false, counts the Free install catalog (staging discovery tools included).
	 * @return array{
	 *   free_registry:int,
	 *   pro_registry:int,
	 *   pro_staging_discovery:int,
	 *   tools_list_full:int,
	 *   tools_list_core:int,
	 *   pro_only_tools:int
	 * }
	 */
	public static function compute( bool $pro = true ): array {
		self::assert_clean_generation_context();
		self::prime_integrations();
		self::set_tier( $pro );
		$controller = self::controller();

		$free_reg  = new Mcpwp_Mcp_Free_Tools();
		$pro_reg   = new Mcpwp_Mcp_Pro_Tools();
		$free_only = count( $free_reg->get_tools() );
		$pro_only  = count( $pro_reg->get_tools() );
		$staging   = count( $pro_reg->get_staging_pro_tools() );

		$full_names = self::tools_list_names( $controller, 'full' );
		$core_names = self::tools_list_names( $controller, 'core' );

		self::set_tier( false );
		$free_full = self::tools_list_names( self::controller(), 'full' );
		self::set_tier( $pro );

		return array(
			'free_registry'           => $free_only,
			'pro_registry'            => $pro_only,
			'pro_staging_discovery'   => $staging,
			'tools_list_full'         => count( $full_names ),
			'tools_list_full_free'    => count( $free_full ),
			'tools_list_core'         => count( $core_names ),
			'pro_only_tools'          => max( 0, count( $full_names ) - count( $free_full ) ),
		);
	}

	/**
	 * Build the checked-in docs/tool-counts.json payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function build_manifest(): array {
		self::assert_clean_generation_context();
		$pro_counts  = self::compute( true );
		$free_counts = self::compute( false );

		$marketing_free = self::marketing_plus_label( (int) $free_counts['tools_list_full'] );
		$marketing_pro  = self::marketing_plus_label( (int) $pro_counts['tools_list_full'] );
		$phrase_short   = sprintf( '%s tools free, %s with Pro', $marketing_free, $marketing_pro );

		return array(
			'schema'      => 1,
			'methodology' => 'tools/list profile=full. Registries: Free 180 + Pro 118 tool definitions in code. Default-capability tools/list: Free ≈160, Pro ≈183 (before optional plugins). Worst-case catalog tools/list (every optional plugin + integration reported active — same ceiling as ToolListBudgetTest): Free ≈190–194 (includes four staging discovery prompts on Free tier); Pro up to 309. Counts use the Free Freemius stub (explicit non-paying) vs Pro stub — not merely nulling the stub.',
			'generated_at' => gmdate( 'c' ),
			'counts'      => array(
				'tools_list_full_free'  => (int) $free_counts['tools_list_full'],
				'tools_list_full_pro'   => (int) $pro_counts['tools_list_full'],
				'tools_list_core'       => (int) $pro_counts['tools_list_core'],
				'free_registry'         => (int) $free_counts['free_registry'],
				'pro_registry'          => (int) $pro_counts['pro_registry'],
				'pro_staging_discovery' => (int) $free_counts['pro_staging_discovery'],
				'pro_only_tools'        => (int) $pro_counts['pro_only_tools'],
			),
			'marketing'    => array(
				'free_tools'       => $marketing_free,
				'pro_tools_total'  => $marketing_pro,
				'phrase_short'     => $phrase_short,
				'phrase_free_only' => sprintf( '%s tools free', $marketing_free ),
				'phrase_pro_total' => sprintf( '%s with Pro', $marketing_pro ),
				'core_profile'     => (string) (int) $pro_counts['tools_list_core'],
			),
			'github_about' => array(
				'suggested' => self::GITHUB_ABOUT_SUGGESTED,
				'note'      => 'Set manually in GitHub repo Settings → About (#498).',
			),
		);
	}

	/**
	 * Compare two docs/tool-counts.json blobs ignoring regenerated_at.
	 */
	public static function normalize_manifest_json( string $json ): string {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return $json;
		}
		unset( $data['generated_at'] );
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	private static function prime_integrations(): void {
		Mcpwp_Integration::clear_cache();
		Mcpwp_REST_MCP::reset_verdict_memo();
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		add_filter(
			'mcpwp_integrations',
			static function ( $integrations ) {
				$integrations[] = new Mcpwp_MCP_AI_Integration();
				$integrations[] = new Mcpwp_MCP_Figma_Integration();
				return $integrations;
			}
		);
	}

	private static function reset_license(): void {
		$ref = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * @return bool Previous pro state (unused; for call symmetry).
	 */
	private static function set_tier( bool $pro ): bool {
		$GLOBALS['mcpwp_test_fs'] = $pro
			? new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' )
			: new Mcpwp_Test_Fs_Stub( false, false, false, '' );
		self::reset_license();
		return $pro;
	}

	private static function controller(): Mcpwp_REST_MCP {
		$controller = new Mcpwp_REST_MCP();
		$ref        = new ReflectionProperty( Mcpwp_REST_MCP::class, 'active_capabilities' );
		$ref->setAccessible( true );
		$ref->setValue( $controller, self::max_capabilities() );
		return $controller;
	}

	/**
	 * @return string[]
	 */
	private static function tools_list_names( Mcpwp_REST_MCP $controller, string $profile ): array {
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'MCP-Protocol-Version' => self::PROTOCOL ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);
		$request->set_query_params( array( 'profile' => $profile ) );
		$data = $controller->handle_mcp( $request )->get_data();
		if ( empty( $data['result']['tools'] ) || ! is_array( $data['result']['tools'] ) ) {
			return array();
		}
		return array_column( $data['result']['tools'], 'name' );
	}
}