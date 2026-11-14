<?php
/** Strict, state-free equality checks for the Elementor write policy. */

use PHPUnit\Framework\TestCase;

final class ElementorMetaWriteEqualityTest extends TestCase {
	public static function equality_cases(): array {
		return array(
			'1 vs 1.0'             => array( 1, 1.0, false ),
			'"1" vs 1'             => array( '1', 1, false ),
			'true vs 1'            => array( true, 1, false ),
			'null vs ""'           => array( null, '', false ),
			'false vs 0'           => array( false, 0, false ),
			'-0.0 vs 0.0'          => array( -0.0, 0.0, false ),
			'0.1 vs 0.1'           => array( 0.1, 0.1, true ),
			'INF vs INF'           => array( INF, INF, false ),
			'-INF vs -INF'         => array( -INF, -INF, false ),
			'NAN vs NAN'           => array( NAN, NAN, false ),
			'huge-int string'      => array( '12345678901234567890123', '12345678901234567890123', false ),
			'in-range digits'      => array( '1234567890', '1234567890', true ),
			'{} vs [] (as Elementor sees them)' => array( array(), array(), true ),
			'map key order'        => array( array( 'a' => 1, 'b' => 2 ), array( 'b' => 2, 'a' => 1 ), true ),
			'list order'           => array( array( 'x', 'y' ), array( 'y', 'x' ), false ),
			'list vs map'          => array( array( 'x' ), array( 1 => 'x' ), false ),
			'nested empties'       => array( array( 'a' => array() ), array( 'a' => '' ), false ),
			'NFC vs NFD'           => array( "caf\u{00e9}", "cafe\u{0301}", false ),
			'emoji equal'          => array( 'ok 🎉', 'ok 🎉', true ),
			'object vs array'      => array( (object) array( 'a' => 1 ), array( 'a' => 1 ), false ),
			'same stdClass'        => array( (object) array( 'a' => 1 ), (object) array( 'a' => 1 ), true ),
			'object numeric prop'  => array( (object) array( '0' => 'x' ), array( 'x' ), false ),
		);
	}

	/**
	 * @dataProvider equality_cases
	 */
	public function test_equality_is_strict_and_fails_closed( $a, $b, bool $equal ): void {
		// Round 4, design question: anything not provably the same value
		// Elementor would see is different. Pure function, no WordPress state.
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-meta-write.php';
		$this->assertSame( $equal, Mcpwp_Elementor_Meta_Write::deep_equal( $a, $b ) );
		$this->assertSame( $equal, Mcpwp_Elementor_Meta_Write::deep_equal( $b, $a ), 'symmetric' );
	}


	public static function hash_cases(): array {
		return self::equality_cases() + array(
			'1 vs 1 (int)'          => array( 1, 1, true ),
			'A vs a'                => array( 'A', 'a', false ),
			'list swap'             => array( array( 1, 2 ), array( 2, 1 ), false ),
			'delimiter forgery'     => array( array( 'a' => 's1:x;' ), array( 'a' => 'x', 's1:x' => '' ), false ),
			'forged structure'      => array( array( 'a' => 'b;s1:c=s1:d' ), array( 'a' => 'b', 'c' => 'd' ), false ),
			'string with length'    => array( array( 'k' => "5:abc" ), array( 'k' => 'abc' ), false ),
			'string vs int'         => array( array( 'k' => '1' ), array( 'k' => 1 ), false ),
			'float vs int nested'   => array( array( 'k' => 1.0 ), array( 'k' => 1 ), false ),
			'bool vs int nested'    => array( array( 'k' => true ), array( 'k' => 1 ), false ),
			'null vs "" nested'     => array( array( 'k' => null ), array( 'k' => '' ), false ),
			'neg zero nested'       => array( array( 'k' => -0.0 ), array( 'k' => 0.0 ), false ),
			'float precision'       => array( array( 'k' => 0.1 ), array( 'k' => 0.10000000000000002 ), false ),
		);
	}

	/**
	 * vouch() relies on fields_hash() alone: it must agree with deep_equal()
	 * exactly, in both directions.
	 *
	 * @dataProvider hash_cases
	 */
	public function test_the_vouching_hash_agrees_with_deep_equal( $a, $b, bool $equal ): void {
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-meta-write.php';
		$hash = new ReflectionMethod( Mcpwp_Elementor_Meta_Write::class, 'fields_hash' );
		$hash->setAccessible( true );
		$wrap = static function ( $v ) {
			return array( 'settings' => array( 'v' => $v ) );
		};
		$ha = $hash->invoke( null, $wrap( $a ) );
		$hb = $hash->invoke( null, $wrap( $b ) );

		$this->assertSame( $equal, Mcpwp_Elementor_Meta_Write::deep_equal( $a, $b ) );
		$this->assertSame( $equal, null !== $ha && $ha === $hb, 'hash equality is deep_equal equality' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deep_non_list_elements_chain_is_not_hashed_per_level(): void {
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-meta-write.php';
		$big   = str_repeat( 'x', 1000000 );
		$chain = array( 'id' => 'bot8291', 'elType' => 'widget', 'settings' => array( 'html' => $big . '<script>1</script>' ) );
		for ( $i = 0; $i < 200; $i++ ) {
			$chain = array( 'id' => 'l' . $i . '8291', 'elType' => 'container', 'settings' => array(), 'elements' => array( 'k' => $chain ) );
		}
		$tree = array( $chain );
		$GLOBALS['_mcpwp_test_posts'][99] = new WP_Post( array( 'ID' => 99, 'post_type' => 'page', 'post_status' => 'publish' ) );
		$GLOBALS['_mcpwp_test_meta'][99]['_elementor_data'] = wp_json_encode( $tree );
		if ( false === has_filter( 'update_post_metadata', array( 'Mcpwp_Elementor_Meta_Write', 'on_update_post_metadata' ) ) ) {
			Mcpwp_Elementor_Meta_Write::boot();
		}

		$start  = microtime( true );
		$policy = Mcpwp_Elementor_Meta_Write::apply_policy( 99, '_elementor_data', wp_json_encode( $tree ) );
		$this->assertLessThan( 3, microtime( true ) - $start, 'Linear in the payload, not depth squared.' );

		// Never vouched: the stored raw script is sanitised.
		$this->assertStringNotContainsString( '<script', (string) $policy );
	}
}
