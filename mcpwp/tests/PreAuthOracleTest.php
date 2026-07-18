<?php
/**
 * Regression coverage for GH #670 — pre-auth enumeration oracle.
 *
 * Before the fix, check_typed_resource_permission(), check_content_update_permission(),
 * and check_delete_draft_permission() ran get_post() existence/type/draft-status
 * checks BEFORE verifying the API key. An anonymous (no X-API-Key header) caller
 * could therefore distinguish "resource does not exist" (404) from "resource exists"
 * (401) — and, for the draft-delete route, even learn whether an existing resource
 * was currently a draft (404 not_a_draft) versus something else (401).
 *
 * These tests assert that every one of those three permission callbacks now returns
 * a byte-identical unauthenticated response (401 missing_api_key) regardless of
 * whether the target id is missing, exists as the expected type, exists as the
 * wrong type, or (for the draft-delete path) is or isn't currently a draft.
 */

use PHPUnit\Framework\TestCase;

final class PreAuthOracleTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']       = array(
			'mcpwp_rate_limit_settings' => array( 'enabled' => false ),
		);
		$GLOBALS['mcpwp_test_transients']    = array();
		$GLOBALS['mcpwp_test_current_user']  = 0;
		$GLOBALS['mcpwp_test_users']         = array();
		$GLOBALS['mcpwp_test_user_caps']     = array();
		$GLOBALS['_mcpwp_test_posts']        = array();
		$this->reset_limiter_singleton();
	}

	/**
	 * check_typed_resource_permission() backs check_get_post_permission,
	 * check_get_page_permission, and check_get_media_permission. All three
	 * must return the identical unauthenticated envelope whether the id is
	 * missing, correctly typed, or the wrong type.
	 */
	public function test_typed_resource_permission_is_uniform_pre_auth(): void {
		// id 10: correct-type resource for every case below.
		$GLOBALS['_mcpwp_test_posts'][10] = $this->post_fixture( 10, 'post', 'publish' );
		$GLOBALS['_mcpwp_test_posts'][11] = $this->post_fixture( 11, 'page', 'publish' );
		$GLOBALS['_mcpwp_test_posts'][12] = $this->attachment_fixture( 12 );

		$cases = array(
			'post: missing id'        => array( new Mcpwp_REST_Posts(), 'check_get_post_permission', 999 ),
			'post: correct type'      => array( new Mcpwp_REST_Posts(), 'check_get_post_permission', 10 ),
			'post: wrong type (page)' => array( new Mcpwp_REST_Posts(), 'check_get_post_permission', 11 ),
			'page: missing id'        => array( new Mcpwp_REST_Pages(), 'check_get_page_permission', 999 ),
			'page: correct type'      => array( new Mcpwp_REST_Pages(), 'check_get_page_permission', 11 ),
			'page: wrong type (post)' => array( new Mcpwp_REST_Pages(), 'check_get_page_permission', 10 ),
			'media: missing id'       => array( new Mcpwp_REST_Media(), 'check_get_media_permission', 999 ),
			'media: correct type'     => array( new Mcpwp_REST_Media(), 'check_get_media_permission', 12 ),
			'media: wrong type (post)' => array( new Mcpwp_REST_Media(), 'check_get_media_permission', 10 ),
		);

		$envelopes = array();
		foreach ( $cases as $label => $case ) {
			list( $controller, $callback, $id ) = $case;
			$request = new WP_REST_Request( 'GET', '/mcpwp/v1/resource/' . $id, array( 'id' => $id ), array() );
			$envelopes[ $label ] = $this->unauthenticated_envelope( $controller->$callback( $request ) );
		}

		$reference = reset( $envelopes );
		foreach ( $envelopes as $label => $envelope ) {
			$this->assertSame( $reference, $envelope, "Case '$label' leaked resource existence/type pre-auth." );
		}
	}

	/**
	 * check_content_update_permission() backs check_update_post_permission and
	 * check_update_page_permission. The scope required (write vs admin) is only
	 * decided AFTER get_post() runs, so this is the trickiest of the three —
	 * confirm the anonymous caller still gets a uniform 401 regardless.
	 */
	public function test_content_update_permission_is_uniform_pre_auth(): void {
		$GLOBALS['_mcpwp_test_posts'][20] = $this->post_fixture( 20, 'post', 'draft' );
		$GLOBALS['_mcpwp_test_posts'][21] = $this->post_fixture( 21, 'post', 'publish' );
		$GLOBALS['_mcpwp_test_posts'][22] = $this->post_fixture( 22, 'page', 'draft' );

		$cases = array(
			'post: missing id'         => array( new Mcpwp_REST_Posts(), 'check_update_post_permission', 999 ),
			'post: correct type draft' => array( new Mcpwp_REST_Posts(), 'check_update_post_permission', 20 ),
			'post: correct type publish' => array( new Mcpwp_REST_Posts(), 'check_update_post_permission', 21 ),
			'post: wrong type (page)'  => array( new Mcpwp_REST_Posts(), 'check_update_post_permission', 22 ),
			'page: missing id'         => array( new Mcpwp_REST_Pages(), 'check_update_page_permission', 999 ),
			'page: correct type'       => array( new Mcpwp_REST_Pages(), 'check_update_page_permission', 22 ),
			'page: wrong type (post)'  => array( new Mcpwp_REST_Pages(), 'check_update_page_permission', 20 ),
		);

		$envelopes = array();
		foreach ( $cases as $label => $case ) {
			list( $controller, $callback, $id ) = $case;
			$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/resource/' . $id, array( 'id' => $id ), array(), null, wp_json_encode( array() ) );
			$envelopes[ $label ] = $this->unauthenticated_envelope( $controller->$callback( $request ) );
		}

		$reference = reset( $envelopes );
		foreach ( $envelopes as $label => $envelope ) {
			$this->assertSame( $reference, $envelope, "Case '$label' leaked resource existence/type/status pre-auth." );
		}
	}

	/**
	 * check_delete_draft_permission() previously leaked draft status via a
	 * 404 'not_a_draft' response issued before authentication. Confirm the
	 * anonymous caller can no longer distinguish missing / non-draft / draft /
	 * wrong-type ids.
	 */
	public function test_delete_draft_permission_is_uniform_pre_auth(): void {
		$GLOBALS['_mcpwp_test_posts'][30] = $this->post_fixture( 30, 'post', 'draft' );
		$GLOBALS['_mcpwp_test_posts'][31] = $this->post_fixture( 31, 'post', 'publish' );
		$GLOBALS['_mcpwp_test_posts'][32] = $this->post_fixture( 32, 'page', 'draft' );
		$GLOBALS['_mcpwp_test_posts'][33] = $this->attachment_fixture( 33 );

		$cases = array(
			'missing id'             => 999,
			'draft post (draft)'     => 30,
			'published post (not draft)' => 31,
			'draft page (draft)'     => 32,
			'wrong type (attachment)' => 33,
		);

		$envelopes = array();
		foreach ( $cases as $label => $id ) {
			$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/drafts/' . $id, array( 'id' => $id ), array() );
			$envelopes[ $label ] = $this->unauthenticated_envelope( ( new Mcpwp_REST_Posts() )->check_delete_draft_permission( $request ) );
		}

		$reference = reset( $envelopes );
		foreach ( $envelopes as $label => $envelope ) {
			$this->assertSame( $reference, $envelope, "Case '$label' leaked draft status pre-auth." );
		}
	}

	/**
	 * Sanity check: the uniform pre-auth response is actually the expected
	 * 401 missing_api_key error, not some other accidental convergence.
	 */
	public function test_uniform_pre_auth_envelope_is_missing_api_key_401(): void {
		$GLOBALS['_mcpwp_test_posts'][40] = $this->post_fixture( 40, 'post', 'publish' );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/posts/40', array( 'id' => 40 ), array() );
		$result  = ( new Mcpwp_REST_Posts() )->check_get_post_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_api_key', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	private function unauthenticated_envelope( $result ): array {
		$this->assertInstanceOf( WP_Error::class, $result, 'Anonymous caller unexpectedly authorized.' );
		return array(
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
			'data'    => $result->get_error_data(),
		);
	}

	private function post_fixture( $id, $type, $status ) {
		return (object) array(
			'ID'                => $id,
			'post_type'         => $type,
			'post_title'        => 'Item ' . $id,
			'post_name'         => 'item-' . $id,
			'post_status'       => $status,
			'post_date'         => '2026-01-01',
			'post_date_gmt'     => '2026-01-01',
			'post_modified'     => '2026-01-02',
			'post_modified_gmt' => '2026-01-02',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 7,
			'post_content'      => '',
			'post_excerpt'      => '',
		);
	}

	private function attachment_fixture( $id ) {
		$post                 = $this->post_fixture( $id, 'attachment', 'inherit' );
		$post->post_mime_type = 'image/png';
		$post->attached_file  = $id . '.png';
		return $post;
	}

	private function reset_limiter_singleton(): void {
		$reflection = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null );
	}
}
