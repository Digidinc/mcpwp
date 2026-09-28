<?php
/**
 * #1024 / PR #1030, round-4 WARN-1: the stored-issue readers carry each
 * issue's post title, id and url, so a caller must only receive issues about
 * posts its category may read — GET /seo/issues and GET /seo/autofix-plan
 * drop issues about a product for a key without woocommerce (and recompute
 * their counts), and GET /seo/woocommerce — a report made of product titles
 * and urls — needs woocommerce on top of the route's own 'seo'.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-seo-audit.php';

final class StoredSeoIssuesCategoryClampTest extends TestCase {

	private const PAGE_ID    = 7701;
	private const PRODUCT_ID = 7702;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['mcpwp_test_public_post_types']  = array( 'product' );
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID    => new WP_Post( array( 'ID' => self::PAGE_ID, 'post_type' => 'page', 'post_title' => 'About', 'post_status' => 'publish' ) ),
			self::PRODUCT_ID => new WP_Post( array( 'ID' => self::PRODUCT_ID, 'post_type' => 'product', 'post_title' => 'Secret Hoodie', 'post_status' => 'publish' ) ),
		);

		update_option(
			Mcpwp_SEO_Audit_Store::ISSUES_OPTION,
			array(
				$this->issue( 'a1', self::PAGE_ID, 'About' ),
				$this->issue( 'a2', self::PRODUCT_ID, 'Secret Hoodie' ),
			)
		);
	}

	private function issue( string $id, int $post_id, string $title ): array {
		return array(
			'id'             => $id,
			'post_id'        => $post_id,
			'title'          => $title,
			'url'            => 'https://example.com/' . $post_id,
			'code'           => 'missing_meta_description',
			'category'       => 'metadata',
			'severity'       => 'warning',
			'status'         => 'open',
			'message'        => 'Missing meta description.',
			'priority_score' => 10,
			'last_seen_at'   => '2026-09-01 00:00:00',
		);
	}

	/** @return array{0:Mcpwp_REST_SEO_Audit,1:true|WP_Error,2:WP_REST_Request} */
	private function request( string $route, array $params, array $categories ): array {
		$controller = new Mcpwp_REST_SEO_Audit();
		$created    = $controller->create_scoped_api_key( 'k', array( 'read', 'write' ), 'custom', $categories );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1' . $route, $params, array( 'X-API-Key' => $created['key'] ) );
		return array( $controller, $controller->check_permission( $request ), $request );
	}

	public function test_seo_issues_drop_issues_about_a_product_for_a_key_without_woocommerce(): void {
		[ $c, $auth, $r ] = $this->request( '/seo/issues', array(), array( 'seo' ) );
		$this->assertTrue( $auth );
		$data = $c->get_seo_issues( $r )->get_data();

		$this->assertSame( array( self::PAGE_ID ), array_column( $data['issues'], 'post_id' ) );
		$this->assertSame( 1, $data['summary']['total'], 'Counts describe only what the caller receives.' );
		$this->assertStringNotContainsString( 'Secret Hoodie', wp_json_encode( $data ) );

		[ $c, $auth, $r ] = $this->request( '/seo/issues', array(), array( 'seo', 'woocommerce' ) );
		$data             = $c->get_seo_issues( $r )->get_data();
		$this->assertSame( 2, $data['summary']['total'], 'A key holding woocommerce still sees both.' );
	}

	public function test_seo_issues_post_id_filter_is_a_declared_post_ref(): void {
		[ , $gated ]   = $this->request( '/seo/issues', array( 'post_id' => self::PRODUCT_ID ), array( 'seo' ) );
		[ , $missing ] = $this->request( '/seo/issues', array( 'post_id' => 7799 ), array( 'seo' ) );

		$this->assertInstanceOf( WP_Error::class, $gated );
		$this->assertSame( wp_json_encode( $missing->get_error_data() ), wp_json_encode( $gated->get_error_data() ) );
		$this->assertSame( $missing->get_error_message(), $gated->get_error_message() );
	}

	public function test_autofix_plan_drops_actions_for_a_product_for_a_key_without_woocommerce(): void {
		[ $c, $auth, $r ] = $this->request( '/seo/autofix-plan', array(), array( 'seo' ) );
		$this->assertTrue( $auth );
		$data = $c->get_seo_autofix_plan( $r )->get_data();

		$this->assertSame( array( self::PAGE_ID ), array_column( $data['actions'], 'post_id' ) );
		$this->assertSame( 1, $data['summary']['issues_inspected'] );
		$this->assertStringNotContainsString( 'Secret Hoodie', wp_json_encode( $data ) );
	}

	public function test_woocommerce_seo_report_needs_the_woocommerce_category(): void {
		[ $c, $auth, $r ] = $this->request( '/seo/woocommerce', array(), array( 'seo' ) );
		$this->assertTrue( $auth );
		$result = $c->get_woocommerce_seo_report( $r );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden_category', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		[ $c, , $r ] = $this->request( '/seo/woocommerce', array(), array( 'seo', 'woocommerce' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $c->get_woocommerce_seo_report( $r ) );
	}
}
