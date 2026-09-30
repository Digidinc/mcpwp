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

}
