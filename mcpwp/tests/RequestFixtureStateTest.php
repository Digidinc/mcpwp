<?php
/** Regression coverage for the declared request-fixture boundary (#1114). */
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/helpers/trait-mcpwp-request-fixture-state.php';

final class RequestFixtureStateTest extends TestCase {
	/** @dataProvider incoming_states */
	public function test_every_declared_global_round_trips( string $state ): void {
		$incoming = array();
		$expected = array();
		$fixture  = new class {
			use Mcpwp_Test_Request_Fixture_State;
			public function capture(): void { $this->capture_request_fixture_state(); }
			public function restore(): void { $this->restore_request_fixture_state(); }
		};

		foreach ( Mcpwp_Test_Request_Fixture_Globals::NAMES as $name ) {
			$incoming[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
			if ( 'absent' === $state ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = 'null' === $state ? null : array( 'sentinel' => $name );
			}
			$expected[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
		}

		try {
			$fixture->capture();
			foreach ( Mcpwp_Test_Request_Fixture_Globals::NAMES as $name ) {
				$GLOBALS[ $name ] = array( 'changed' => $name );
			}
			$fixture->restore();
			foreach ( Mcpwp_Test_Request_Fixture_Globals::NAMES as $name ) {
				$this->assertSame( $expected[ $name ], array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null ), $name );
			}
		} finally {
			$fixture->restore();
			foreach ( $incoming as $name => list( $exists, $value ) ) {
				if ( $exists ) {
					$GLOBALS[ $name ] = $value;
				} else {
					unset( $GLOBALS[ $name ] );
				}
			}
		}
	}

	public function incoming_states(): array {
		return array( 'existing value' => array( 'value' ), 'existing null' => array( 'null' ), 'absent' => array( 'absent' ) );
	}

	public function test_every_literal_global_used_by_the_fixtures_is_declared(): void {
		foreach ( array( 'McpProtocolVersionTest', 'McpToolResourceLinkTest', 'RestElementorDraftLeakTest' ) as $class ) {
			$source = file_get_contents( __DIR__ . '/' . $class . '.php' );
			preg_match_all( '/\$GLOBALS\s*\[\s*[\'\"]([^\'\"]+)[\'\"]\s*\]/', $source, $matches );
			$this->assertSame( array(), array_values( array_diff( array_unique( $matches[1] ), Mcpwp_Test_Request_Fixture_Globals::NAMES ) ), $class . ' uses an undeclared fixture global.' );
		}
	}
}
