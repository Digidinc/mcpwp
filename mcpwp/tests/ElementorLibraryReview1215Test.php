<?php
/**
 * #1211 review fixes — update-path rollback, list visibility, publish sync, MCP gates.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';
require_once __DIR__ . '/ApiAuthTest.php';

if ( ! class_exists( 'Mcpwp_WebMCP' ) ) {
	require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-webmcp.php';
}

class Mcpwp_Elementor_Library_List_Harness extends Mcpwp_REST_Elementor_Pro {
	/** @var array|null */
	private $record_override;

	/** @var bool */
	private $record_override_set = false;

	public function use_record( $record ) {
		$this->record_override     = $record;
		$this->record_override_set = true;
	}

	public function get_current_api_key_record() {
		if ( $this->record_override_set ) {
			return $this->record_override;
		}

		return parent::get_current_api_key_record();
	}

	public function library_list_read_only(): bool {
		$method = new ReflectionMethod( self::class, 'caller_is_read_only_for_library_list' );
		$method->setAccessible( true );
		return (bool) $method->invoke( $this );
	}
}

class Mcpwp_Mcp_Publish_Gate_Harness {
	use Mcpwp_Api_Auth;

	public function rest_scope( string $method, string $route, array $params = array() ): string {
		return $this->get_required_scope_for_request( new WP_REST_Request( $method, $route, $params ) );
	}
}

final class ElementorLibraryReview1215Test extends TestCase {

	const EDITOR  = 7;
	const AUTHOR  = 11;
	const WEBMCP  = 21;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['_mcpwp_test_meta']        = array();
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_users'] = array(
			(object) array( 'ID' => self::EDITOR, 'roles' => array( 'editor' ), 'user_login' => 'editor' ),
			(object) array( 'ID' => self::AUTHOR, 'roles' => array( 'author' ), 'user_login' => 'author' ),
			(object) array( 'ID' => self::WEBMCP, 'roles' => array( 'editor' ), 'user_login' => 'webmcp' ),
		);
		$GLOBALS['mcpwp_test_user_caps']    = array(
			self::EDITOR => array(
				'read'                => true,
				'edit_posts'          => true,
				'edit_others_posts'   => true,
				'read_private_posts'  => true,
			),
			self::AUTHOR => array(
				'read'       => true,
				'edit_posts' => true,
			),
			self::WEBMCP => array(
				'read'       => true,
				'edit_posts' => true,
			),
		);
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		update_option( 'mcpwp_oauth_local_min_role', 'author' );
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_custom_code_cache_sync'], $GLOBALS['mcpwp_test_wp_update_post_status_fails'] );
		Mcpwp_REST_API::reset_verdict_memo();
	}

	private function seed_library_posts(): void {
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
		$GLOBALS['_mcpwp_test_posts'][101] = new WP_Post(
			(object) array(
				'ID'          => 101,
				'post_title'  => 'Published template',
				'post_type'   => 'elementor_library',
				'post_status' => 'publish',
			)
		);
		$GLOBALS['_mcpwp_test_posts'][102] = new WP_Post(
			(object) array(
				'ID'          => 102,
				'post_title'  => 'Draft template',
				'post_type'   => 'elementor_library',
				'post_status' => 'draft',
			)
		);
	}

	private function elementor_templates_controller(): Mcpwp_REST_Elementor_Pro {
		return new Mcpwp_REST_Elementor_Pro( new Mcpwp_Elementor_Pro() );
	}

	private function oauth_token( int $user_id, array $scopes = array( 'read', 'write' ) ): string {
		return ( new Mcpwp_Api_Auth_Test_Harness() )->issue_oauth_access_token(
			$scopes,
			3600,
			array( 'user_id' => $user_id, 'client_id' => 'phpunit' )
		)['access_token'];
	}

	private function list_ids_with_record( array $record ): array {
		$harness = new Mcpwp_Elementor_Library_List_Harness( new Mcpwp_Elementor_Pro() );
		$harness->use_record( $record );
		$data  = $harness->get_templates( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/templates' ) )->get_data();
		$items = $data['templates'] ?? $data['items'] ?? array();
		return array_map( 'intval', array_column( $items, 'id' ) );
	}

	public function test_update_publish_failure_leaves_live_template_and_elementor_data(): void {
		$GLOBALS['_mcpwp_test_posts'][55] = new WP_Post(
			(object) array(
				'ID'           => 55,
				'post_title'   => 'Live header',
				'post_type'    => 'elementor_library',
				'post_status'  => 'private',
			)
		);
		$GLOBALS['_mcpwp_test_meta'][55] = array(
			'_elementor_template_type' => array( 'header' ),
			'_elementor_data'          => array( '[{"id":"hdr0001","elType":"section"}]' ),
		);
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
		$GLOBALS['mcpwp_test_wp_update_post_status_fails']    = array( 55 );

		$result = ( new Mcpwp_Elementor_Pro() )->update_template(
			55,
			array(
				'title'          => 'Renamed header',
				'elementor_data' => array( array( 'id' => 'hdr0002', 'elType' => 'section' ) ),
				'status'         => 'publish',
			)
		);

		unset( $GLOBALS['mcpwp_test_wp_update_post_status_fails'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertTrue( isset( $GLOBALS['_mcpwp_test_posts'][55] ), 'A failed publish on update must not force-delete the template.' );
		$this->assertSame( 'private', get_post_status( 55 ) );
		$this->assertSame( 'Live header', $GLOBALS['_mcpwp_test_posts'][55]->post_title, 'Status is resolved before title/data writes.' );
		$this->assertSame( '[{"id":"hdr0001","elType":"section"}]', $GLOBALS['_mcpwp_test_meta'][55]['_elementor_data'][0] );
	}

	public function test_status_refusal_before_title_write_on_update(): void {
		$GLOBALS['_mcpwp_test_posts'][77] = new WP_Post(
			(object) array(
				'ID'          => 77,
				'post_title'  => 'Untouched',
				'post_type'   => 'elementor_library',
				'post_status' => 'draft',
			)
		);
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;

		$result = ( new Mcpwp_Elementor_Pro() )->update_template(
			77,
			array(
				'title'  => 'Should not land',
				'status' => 'pending',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
		$this->assertSame( 'Untouched', $GLOBALS['_mcpwp_test_posts'][77]->post_title );
	}

	public function test_empty_key_record_lists_publish_only_fail_closed(): void {
		$harness = new Mcpwp_Elementor_Library_List_Harness( new Mcpwp_Elementor_Pro() );
		$harness->use_record( null );
		$this->assertTrue( $harness->library_list_read_only(), 'No key record must not fail open to non-public library rows.' );
	}

	public function test_read_api_key_lists_publish_only_via_rest(): void {
		$this->seed_library_posts();
		$ids = $this->list_ids_with_record(
			array(
				'id'     => 'read-key',
				'scopes' => array( 'read' ),
				'role'   => 'admin',
			)
		);
		sort( $ids );
		$this->assertSame( array( 101 ), $ids );
	}

	public function test_write_api_key_lists_drafts_via_rest(): void {
		$this->seed_library_posts();
		$ids = $this->list_ids_with_record(
			array(
				'id'     => 'write-key',
				'scopes' => array( 'read', 'write' ),
				'role'   => 'admin',
			)
		);
		sort( $ids );
		$this->assertSame( array( 101, 102 ), $ids );
	}

	public function test_oauth_editor_lists_drafts_via_rest(): void {
		$this->seed_library_posts();
		$GLOBALS['mcpwp_test_current_user'] = self::EDITOR;
		$ids                                = $this->list_ids_with_record(
			array(
				'id'           => 'oauth-editor',
				'type'         => 'oauth',
				'acts_as_user' => self::EDITOR,
				'scopes'       => array( 'read', 'write' ),
				'role'         => 'custom',
			)
		);
		sort( $ids );
		$this->assertSame( array( 101, 102 ), $ids );
	}

	public function test_oauth_author_write_token_lists_publish_only_via_rest(): void {
		$this->seed_library_posts();
		$GLOBALS['mcpwp_test_current_user'] = self::AUTHOR;
		$ids                                = $this->list_ids_with_record(
			array(
				'id'           => 'oauth-author',
				'type'         => 'oauth',
				'acts_as_user' => self::AUTHOR,
				'scopes'       => array( 'read', 'write' ),
				'role'         => 'custom',
			)
		);
		$this->assertSame( array( 101 ), $ids );
	}

	public function test_webmcp_principal_lists_publish_only_via_rest(): void {
		$this->seed_library_posts();
		$harness = new Mcpwp_Elementor_Library_List_Harness( new Mcpwp_Elementor_Pro() );
		$harness->use_record( Mcpwp_WebMCP::principal_record( self::WEBMCP ) );
		$this->assertTrue( $harness->library_list_read_only(), 'WebMCP must not see non-public at list scope.' );
		$page = ( new Mcpwp_Elementor_Pro() )->get_templates_page( array( 'caller_read_only' => true ) );
		$ids  = array_map( 'intval', array_column( $page['items'], 'id' ) );
		$this->assertSame( array( 101 ), $ids );
	}

	public function test_status_mismatch_warning_when_stored_status_differs(): void {
		$GLOBALS['_mcpwp_test_posts'][88] = new WP_Post(
			(object) array(
				'ID'          => 88,
				'post_title'  => 'Draft stays',
				'post_type'   => 'elementor_library',
				'post_status' => 'draft',
			)
		);
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
		$GLOBALS['mcpwp_test_wp_update_post_status_fails']    = array( 88 );

		$result = ( new Mcpwp_Elementor_Pro() )->update_template( 88, array( 'status' => 'publish' ) );
		unset( $GLOBALS['mcpwp_test_wp_update_post_status_fails'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'draft', get_post_status( 88 ) );
	}

	public function test_transition_post_status_hook_resyncs_conditions_on_admin_publish(): void {
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.0.0' );
		}
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;

		$GLOBALS['_mcpwp_test_posts'][199] = new WP_Post(
			(object) array(
				'ID'          => 199,
				'post_title'  => 'Footer',
				'post_type'   => 'elementor_library',
				'post_status' => 'draft',
			)
		);
		$GLOBALS['_mcpwp_test_meta'][199] = array(
			'_elementor_template_type' => array( 'footer' ),
			'_elementor_conditions'    => array( 'include/general' ),
		);

		$syncs = 0;
		$GLOBALS['mcpwp_test_custom_code_cache_sync'] = static function () use ( &$syncs ) {
			++$syncs;
			return true;
		};

		add_action( 'transition_post_status', array( 'Mcpwp_Elementor_Pro', 'on_library_template_status_transition' ), 10, 3 );
		wp_update_post( array( 'ID' => 199, 'post_status' => 'publish' ) );

		$this->assertSame( 1, $syncs );
	}

	public function test_publish_sync_failure_sets_live_false_and_warning(): void {
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.0.0' );
		}
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;

		$GLOBALS['_mcpwp_test_posts'][301] = new WP_Post(
			(object) array(
				'ID'          => 301,
				'post_title'  => 'Sync fail',
				'post_type'   => 'elementor_library',
				'post_status' => 'draft',
			)
		);
		$GLOBALS['_mcpwp_test_meta'][301] = array(
			'_elementor_template_type' => array( 'header' ),
			'_elementor_conditions'    => array( 'include/general' ),
		);

		$GLOBALS['mcpwp_test_custom_code_cache_sync'] = static function () {
			return new WP_Error( 'sync_failed', 'Cache sync refused.' );
		};

		$result = ( new Mcpwp_Elementor_Pro() )->update_template( 301, array( 'status' => 'publish' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertFalse( $result['live'] );
		$this->assertNotEmpty( $result['warnings'] ?? array() );
	}

	public function test_mcp_dispatch_route_publish_on_update_requires_admin_scope(): void {
		$auth = new Mcpwp_Mcp_Publish_Gate_Harness();
		$this->assertSame(
			'admin',
			$auth->rest_scope(
				'PUT',
				'/mcpwp/v1/elementor/templates/12',
				array(
					'id'     => 12,
					'status' => 'publish',
				)
			),
			'tools/call dispatches template updates through this REST route.'
		);
	}

	public function test_write_key_refused_publish_on_template_update_via_rest_gate(): void {
		$controller = $this->elementor_templates_controller();
		$key        = $controller->create_scoped_api_key( 'write', array( 'read', 'write' ), 'admin' )['key'];
		$request    = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/templates/12',
			array( 'id' => 12, 'status' => 'publish' ),
			array( 'X-API-Key' => $key )
		);
		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_theme_builder_next_step_points_at_update_tool(): void {
		$builder = new Mcpwp_Theme_Builder();
		$method  = new ReflectionMethod( Mcpwp_Theme_Builder::class, 'annotate_theme_template_status_response' );
		$method->setAccessible( true );
		$out = $method->invoke( $builder, array( 'status' => 'draft' ), array() );
		$this->assertStringContainsString( 'wp_update_elementor_template', $out['next_step'] ?? '' );
		$this->assertStringNotContainsString( 'wp_create_theme_template', $out['next_step'] ?? '' );
	}
}