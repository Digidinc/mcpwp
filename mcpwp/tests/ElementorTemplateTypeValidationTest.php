<?php
/**
 * Regression coverage for #827 — wp_create_elementor_template accepted an
 * unknown template type and silently rewrote it to "page".
 *
 * Reported from a live build: wp_create_elementor_template{type:
 * "single-post"} answered success and created a PAGE-type template. A
 * page-type template cannot be bound with Theme Builder conditions, so it can
 * never render as the single-post template the caller asked for — and
 * because the call reported success, the mistake was repeated in a later
 * session and left an unusable "canary" template on the live site.
 *
 * The cause was a hardcoded eight-type allowlist (page, section, header,
 * footer, single, archive, popup, loop-item) frozen at the time the create
 * path was written. Reading Elementor Pro's own source on the local rig
 * (`modules/theme-builder/classes/templates-types-manager.php` and
 * `includes/admin-templates/new-template.php`) shows `single-post` is not a
 * spelling of `single` — Documents\Single_Post is its own registered
 * document type, distinct from Documents\Single, and Elementor's own "Add New
 * Template" screen lists every registered type whose show_in_library
 * property is true. The hardcoded list simply predated it.
 *
 * The fix asks Elementor's live Documents_Manager the same question its own
 * admin screen asks, and refuses whatever is not in that answer — no default
 * type is safe to guess into a caller's place, because "page" and
 * "single-post" are both real, differently-behaving templates.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// Not required by tests/bootstrap.php — no existing test instantiates
// Mcpwp_Elementor_Pro directly, so nothing else pulls this file in. Guarded
// because @runInSeparateProcess tests below re-run this file from a fresh
// bootstrap and this require alongside it.
if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
	require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';
}

final class ElementorTemplateTypeValidationTest extends TestCase {

	// ---------------------------------------------------------------
	// filter_library_types(): the filtering rule in isolation. No
	// \Elementor\Plugin involved, so this runs in the main process.
	// ---------------------------------------------------------------

	/**
	 * @param array $document_types Type slug => class name map.
	 * @return array
	 */
	private function filter( array $document_types ): array {
		$method = new ReflectionMethod( Mcpwp_Elementor_Pro::class, 'filter_library_types' );
		$method->setAccessible( true );

		return $method->invoke( null, $document_types );
	}

	public function test_show_in_library_true_types_are_kept(): void {
		$this->assertSame(
			array( 'page', 'single', 'single-post' ),
			$this->filter(
				array(
					'page'        => Mcpwp_Test_Doc_Shown::class,
					'single'      => Mcpwp_Test_Doc_Shown::class,
					'single-post' => Mcpwp_Test_Doc_Shown::class,
				)
			)
		);
	}

	public function test_show_in_library_false_is_excluded(): void {
		$this->assertSame(
			array( 'page' ),
			$this->filter(
				array(
					'page'   => Mcpwp_Test_Doc_Shown::class,
					'widget' => Mcpwp_Test_Doc_Hidden::class,
				)
			),
			'Elementor itself does not offer "widget" on its New Template screen; neither should this list.'
		);
	}

	public function test_show_in_library_null_is_excluded(): void {
		// "post" and "wp-page" register show_in_library as null, not false —
		// this pins that null is treated the same as false, not as true.
		$this->assertSame(
			array( 'page' ),
			$this->filter(
				array(
					'page' => Mcpwp_Test_Doc_Shown::class,
					'post' => Mcpwp_Test_Doc_Null::class,
				)
			)
		);
	}

	public function test_an_entry_that_is_not_a_usable_class_is_skipped_not_fatal(): void {
		$this->assertSame(
			array( 'page' ),
			$this->filter(
				array(
					'page'    => Mcpwp_Test_Doc_Shown::class,
					'garbled' => 'Not\\A\\Real\\Class',
					'weird'   => 123,
				)
			)
		);
	}

	public function test_the_result_is_sorted(): void {
		$this->assertSame(
			array( 'archive', 'page', 'single' ),
			$this->filter(
				array(
					'single'  => Mcpwp_Test_Doc_Shown::class,
					'page'    => Mcpwp_Test_Doc_Shown::class,
					'archive' => Mcpwp_Test_Doc_Shown::class,
				)
			)
		);
	}

	// ---------------------------------------------------------------
	// Without a live \Elementor\Plugin: refuse, don't guess.
	// ---------------------------------------------------------------

	protected function tearDown(): void {
		unset(
			$GLOBALS['mcpwp_test_did_action']['elementor/loaded'],
			$GLOBALS['_mcpwp_test_posts'],
			$GLOBALS['_mcpwp_test_meta']
		);
	}

	public function test_without_a_readable_registry_the_call_is_refused_not_defaulted(): void {
		$this->assertFalse(
			class_exists( '\Elementor\Plugin', false ),
			'This assertion is the premise of the rest of the test; if Elementor leaked into this process the result below means nothing.'
		);

		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
		$GLOBALS['_mcpwp_test_posts']                         = array();
		$GLOBALS['_mcpwp_test_meta']                           = array();

		$pro    = new Mcpwp_Elementor_Pro();
		$result = $pro->create_template( array( 'title' => 'Whatever', 'type' => 'single-post' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_types_unavailable', $result->get_error_code() );
		$this->assertEmpty(
			$GLOBALS['_mcpwp_test_posts'],
			'An unreadable type registry must refuse before wp_insert_post, not create something and then complain.'
		);
	}

	// ---------------------------------------------------------------
	// With Elementor's document registry live. Separate processes:
	// declaring \Elementor\Plugin in the main run changes every
	// Elementor-sensitive test that follows it (#757).
	// ---------------------------------------------------------------

	/**
	 * @return array Type slug => fake document class, matching what this
	 *               Elementor Pro install actually registers (read live from
	 *               the rig): page, single, single-post are distinct,
	 *               show_in_library types; post is registered but excluded
	 *               from the library the same way Elementor excludes it.
	 */
	private function live_like_registry(): array {
		return array(
			'page'        => \Elementor\Mcpwp_Fake_Doc_Page::class,
			'section'     => \Elementor\Mcpwp_Fake_Doc_Section::class,
			'single'      => \Elementor\Mcpwp_Fake_Doc_Single::class,
			'single-post' => \Elementor\Mcpwp_Fake_Doc_Single_Post::class,
			'single-page' => \Elementor\Mcpwp_Fake_Doc_Single_Page::class,
			'post'        => \Elementor\Mcpwp_Fake_Doc_Post::class,
			'widget'      => \Elementor\Mcpwp_Fake_Doc_Widget::class,
		);
	}

	private function install_live_like_registry(): void {
		$GLOBALS['mcpwp_test_document_types'] = $this->live_like_registry();
		$GLOBALS['mcpwp_test_document_properties'] = array(
			\Elementor\Mcpwp_Fake_Doc_Page::class        => array( 'show_in_library' => true ),
			\Elementor\Mcpwp_Fake_Doc_Section::class     => array( 'show_in_library' => true ),
			\Elementor\Mcpwp_Fake_Doc_Single::class      => array( 'show_in_library' => true ),
			\Elementor\Mcpwp_Fake_Doc_Single_Post::class => array( 'show_in_library' => true ),
			\Elementor\Mcpwp_Fake_Doc_Single_Page::class => array( 'show_in_library' => true ),
			// Registered, but not offered on Elementor's own New Template
			// screen — the live rig confirms `post` answers null here, not
			// false, and it must still be excluded.
			\Elementor\Mcpwp_Fake_Doc_Post::class        => array( 'show_in_library' => null ),
			\Elementor\Mcpwp_Fake_Doc_Widget::class      => array( 'show_in_library' => false ),
		);

		require_once __DIR__ . '/fixtures/elementor-template-type-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
		$GLOBALS['_mcpwp_test_posts']                         = array();
		$GLOBALS['_mcpwp_test_meta']                           = array();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_single_post_is_accepted_as_its_own_distinct_type(): void {
		$this->install_live_like_registry();

		$pro    = new Mcpwp_Elementor_Pro();
		$result = $pro->create_template( array( 'title' => 'Blog Post Template', 'type' => 'single-post' ) );

		$this->assertIsArray( $result, 'single-post is a real, registered Elementor document type and must be created as asked.' );
		$this->assertSame( 'single-post', $result['type'] );

		// The exact defect reported: the stored meta silently became "page".
		$this->assertNotSame( 'page', $result['type'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_failed_template_data_write_leaves_no_published_template(): void {
		$this->install_live_like_registry();
		$status_during_write = null;
		add_action( 'wp_insert_post', static function ( $post_id, $post ) use ( &$status_during_write ) {
			if ( 'Failed template' === $post->post_title ) {
				$status_during_write = $post->post_status;
			}
		}, 10, 2 );
		add_filter( 'add_post_metadata', static function ( $check, $post_id, $key ) use ( &$status_during_write ) {
			if ( '_elementor_data' === $key ) {
				return false;
			}
			return $check;
		}, 1, 3 );
		$elements = array( array( 'id' => 'sec1083', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) );

		$result = ( new Mcpwp_Elementor_Pro() )->create_template( array( 'title' => 'Failed template', 'type' => 'page', 'elementor_data' => $elements ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'draft', $status_during_write, 'The template must not be published while its data is still unverified.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'], 'A template with no saved data must not survive.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_settings_only_template_stays_draft_until_settings_are_stored(): void {
		$this->install_live_like_registry();
		$status_during_write = null;
		add_filter( 'add_post_metadata', static function ( $check, $post_id, $key ) use ( &$status_during_write ) {
			if ( '_elementor_page_settings' === $key ) {
				$status_during_write = get_post_status( $post_id );
				return false;
			}
			return $check;
		}, 1, 3 );

		$result = ( new Mcpwp_Elementor_Pro() )->create_template( array( 'title' => 'Settings only', 'type' => 'page', 'page_settings' => array( 'hide_title' => 'yes' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'draft', $status_during_write );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unknown_type_is_refused_and_nothing_is_created(): void {
		$this->install_live_like_registry();

		$pro    = new Mcpwp_Elementor_Pro();
		$result = $pro->create_template( array( 'title' => 'Bogus', 'type' => 'single-postt' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_template_type', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertContains( 'single-post', $data['valid_types'], 'The error must name the types this site actually supports.' );
		$this->assertNotContains( 'single-postt', $data['valid_types'] );

		$this->assertEmpty(
			$GLOBALS['_mcpwp_test_posts'],
			'A rejected type must not leave a post behind — that is the "page-type canary" this issue reported.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_type_registered_but_not_shown_in_library_is_refused(): void {
		$this->install_live_like_registry();

		// "post" is a real Elementor document type (it is what WordPress
		// posts themselves are), but it is not one of the types Elementor
		// itself offers for an elementor_library template, and never was.
		$pro    = new Mcpwp_Elementor_Pro();
		$result = $pro->create_template( array( 'title' => 'Not a library type', 'type' => 'post' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_template_type', $result->get_error_code() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_omitting_type_still_defaults_to_page(): void {
		$this->install_live_like_registry();

		$pro    = new Mcpwp_Elementor_Pro();
		$result = $pro->create_template( array( 'title' => 'No type given' ) );

		$this->assertIsArray( $result, 'The pre-existing default for an omitted type must keep working for callers that already rely on it (create_archetype/create_part).' );
		$this->assertSame( 'page', $result['type'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_archetype_and_create_part_defaults_still_validate(): void {
		$this->install_live_like_registry();

		$pro = new Mcpwp_Elementor_Pro();

		$archetype = $pro->create_archetype( array( 'title' => 'Hero Archetype' ) );
		$this->assertIsArray( $archetype, 'create_archetype() defaults type to "page", which must still be accepted.' );
		$this->assertSame( 'page', $archetype['type'] );

		$part = $pro->create_part( array( 'title' => 'Hero Part' ) );
		$this->assertIsArray( $part, 'create_part() defaults type to "section", which must still be accepted.' );
		$this->assertSame( 'section', $part['type'] );
	}
}

/**
 * Plain (non-Elementor-namespaced) document doubles for the pure
 * filter_library_types() tests above — these never touch \Elementor\Plugin,
 * so they are safe in the main test process.
 */
class Mcpwp_Test_Doc_Shown {
	public static function get_property( $key ) {
		return 'show_in_library' === $key ? true : null;
	}
}

class Mcpwp_Test_Doc_Hidden {
	public static function get_property( $key ) {
		return 'show_in_library' === $key ? false : null;
	}
}

class Mcpwp_Test_Doc_Null {
	public static function get_property( $key ) {
		return null;
	}
}
