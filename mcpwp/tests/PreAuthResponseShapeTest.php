<?php
/**
 * Standing guard for #670: a permission callback must not tell an anonymous
 * caller anything about the resource it names.
 *
 * The finding was that permission callbacks ran `get_post()` existence, type and
 * status checks BEFORE verifying the API key. `verify_api_key()` answers 401 for
 * a missing key, so an anonymous caller got 404 for an id that does not exist
 * and 401 for one that does — an oracle for enumerating valid ids, telling a
 * post from a page from an attachment, and detecting drafts, including private
 * and unpublished ones. Metadata only, never content, but more than WordPress
 * core exposes and squarely against what this plugin advertises.
 *
 * The ordering is correct on main today. This is not a test of that fix — it is
 * the standing check the issue asked for, so the class cannot come back in a
 * route nobody thought about. It is therefore written as a STRUCTURAL invariant
 * over every route the loaded controllers register, not as a list of the three
 * callbacks that were originally wrong. A new route with an id parameter is
 * covered the day it is added, without anyone remembering to extend this file.
 *
 * The assertion is byte-equality of the whole response, not just the status
 * code: a 401 whose message or hint differs by target is the same oracle in a
 * quieter voice.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class PreAuthResponseShapeTest extends TestCase {

	private const PUBLISHED_PAGE = 8300;
	private const DRAFT_PAGE     = 8301;
	private const PUBLISHED_POST = 8302;
	private const ATTACHMENT     = 8303;
	private const MISSING        = 999999;

	/**
	 * Put the request-scoped state back to a clean slate.
	 *
	 * Rate-limit settings and counters both live here. Another test class in
	 * this suite lowers the burst limit and does not restore it (#757's family),
	 * so this cannot assume the defaults are in force — it has to establish
	 * them.
	 */
	private function reset_request_state(): void {
		$GLOBALS['mcpwp_test_options']    = array( 'mcpwp_api_key' => 'hashed-key-value' );
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	protected function setUp(): void {
		$this->reset_request_state();

		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_meta']              = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PUBLISHED_PAGE => $this->post( self::PUBLISHED_PAGE, 'page', 'publish' ),
			self::DRAFT_PAGE     => $this->post( self::DRAFT_PAGE, 'page', 'draft' ),
			self::PUBLISHED_POST => $this->post( self::PUBLISHED_POST, 'post', 'publish' ),
			self::ATTACHMENT     => $this->post( self::ATTACHMENT, 'attachment', 'inherit' ),
		);

		// reset_request_state() seeds mcpwp_api_key, so the plugin is in its
		// normal configured state and the request fails for the one reason
		// under test: the caller presented no key.
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['mcpwp_test_filters']            = array();
	}

	private function post( int $id, string $type, string $status ): WP_Post {
		return new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_title'  => 'Target ' . $id,
				'post_name'   => 'target-' . $id,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Every id an anonymous caller might probe with. The response must not
	 * distinguish any of them from any other.
	 *
	 * @return array<string,int>
	 */
	private function probe_ids(): array {
		return array(
			'published page' => self::PUBLISHED_PAGE,
			'draft page'     => self::DRAFT_PAGE,
			'published post' => self::PUBLISHED_POST,
			'attachment'     => self::ATTACHMENT,
			'nonexistent'    => self::MISSING,
		);
	}

	/**
	 * Registered routes that take an id, as {route, method, permission callback}.
	 *
	 * @return array<int,array{route:string,method:string,callback:callable}>
	 */
	private function id_routes(): array {
		// Every controller under includes/api/ that registers a route with an
		// id-shaped parameter (grepped for `(?P<id>` at the time this list was
		// last widened — #670 follow-up audit, 2026-09-19). A controller added
		// later that never appears here would silently narrow this invariant
		// back down to the two classes it started with, so
		// test_the_harness_actually_found_routes_to_check() stays the backstop:
		// it fails loudly if this list (or a future one) stops finding routes.
		$controllers = array(
			Mcpwp_REST_Pages::class,
			Mcpwp_REST_Menus::class,
			Mcpwp_REST_Posts::class,
			Mcpwp_REST_Media::class,
			Mcpwp_REST_Elementor::class,
			Mcpwp_REST_Blocks::class,
			Mcpwp_REST_Content::class,
			Mcpwp_REST_Approvals::class,
			Mcpwp_REST_Webhooks::class,
			Mcpwp_REST_SEO_Audit::class,
			Mcpwp_REST_Content_Quality::class,
			Mcpwp_REST_Site_Library::class,
			Mcpwp_REST_Site_Taxonomy::class,
			Mcpwp_REST_Site_Blueprints::class,
			Mcpwp_REST_Site_Content::class,
			Mcpwp_REST_Site_Updates::class,
		);

		foreach ( $controllers as $class ) {
			if ( class_exists( $class ) ) {
				( new $class() )->register_routes();
			}
		}

		$found = array();

		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registered ) {
			$route = (string) $registered['route'];

			if ( false === strpos( $route, '(?P<id>' ) ) {
				continue;
			}

			// register_rest_route() accepts one endpoint or a list of them.
			$endpoints = isset( $registered['args'][0] ) ? $registered['args'] : array( $registered['args'] );

			foreach ( $endpoints as $endpoint ) {
				if ( ! is_array( $endpoint ) || ! isset( $endpoint['permission_callback'] ) ) {
					continue;
				}
				if ( ! is_callable( $endpoint['permission_callback'] ) ) {
					continue;
				}

				$found[] = array(
					'route'    => $route,
					'method'   => isset( $endpoint['methods'] ) ? (string) $endpoint['methods'] : 'GET',
					'callback' => $endpoint['permission_callback'],
				);
			}
		}

		return $found;
	}

	/**
	 * Reduce whatever a permission callback returns to a comparable shape.
	 *
	 * Deliberately includes the message and the error data, not just the code:
	 * an oracle that answers 401 both times but varies the hint is still an
	 * oracle.
	 */
	private function describe( $result ): string {
		if ( $result instanceof WP_Error ) {
			return wp_json_encode(
				array(
					'type'    => 'WP_Error',
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				)
			);
		}

		return wp_json_encode(
			array(
				'type'  => gettype( $result ),
				'value' => $result,
			)
		);
	}

	public function test_the_harness_actually_found_routes_to_check(): void {
		// Without this, a refactor that renames the id parameter turns every
		// assertion below into a vacuous pass over an empty list — the exact
		// "test passes for the wrong reason" shape this suite has been bitten
		// by before.
		$this->assertNotEmpty( $this->id_routes(), 'No id-bearing route was discovered; the invariant below would assert nothing.' );
	}

	public function test_an_anonymous_caller_cannot_tell_targets_apart(): void {
		$routes = $this->id_routes();

		foreach ( $routes as $entry ) {
			$responses = array();

			foreach ( $this->probe_ids() as $label => $id ) {
				// Every probe starts from the same state, so the only thing that
				// varies between them is the target. Without this the rate
				// limiter — which counts requests and answers identically
				// whatever the target is — trips partway through the loop and
				// the test "finds" a difference that is its own probing, not the
				// code under test.
				$this->reset_request_state();

				$request = new WP_REST_Request(
					'GET',
					'/mcpwp/v1' . str_replace( '(?P<id>\d+)', (string) $id, $entry['route'] ),
					array( 'id' => $id )
				);

				$responses[ $label ] = $this->describe( call_user_func( $entry['callback'], $request ) );
			}

			$distinct = array_unique( array_values( $responses ) );

			$this->assertCount(
				1,
				$distinct,
				sprintf(
					"%s %s answers differently depending on the target:\n%s",
					$entry['method'],
					$entry['route'],
					wp_json_encode( $responses )
				)
			);
		}
	}

	public function test_the_shared_permission_callback_refuses_before_looking_at_the_target(): void {
		// The base callback is what every route above resolves to today. Pinned
		// separately so the reason the invariant holds is itself asserted, not
		// just its consequence.
		$controller = new Mcpwp_REST_Pages();

		$existing = $controller->check_permission(
			new WP_REST_Request( 'GET', '/mcpwp/v1/pages/' . self::DRAFT_PAGE, array( 'id' => self::DRAFT_PAGE ) )
		);
		$missing = $controller->check_permission(
			new WP_REST_Request( 'GET', '/mcpwp/v1/pages/' . self::MISSING, array( 'id' => self::MISSING ) )
		);

		$this->assertInstanceOf( WP_Error::class, $existing );
		$this->assertSame( 'missing_api_key', $existing->get_error_code(), 'The refusal must be about the credential, never about the target.' );
		$this->assertSame( $this->describe( $existing ), $this->describe( $missing ) );
	}
}
