<?php
/**
 * Make an unreadable API key store impossible to miss (#1096).
 *
 * When `mcpwp_api_keys` holds something that is not a list of keys, every API
 * key is refused (see Mcpwp_Key_State::store_is_unreadable()). That is the safe
 * answer, but from the outside it looks like every key stopped working for no
 * reason, so it is reported where an administrator will see it: a Site Health
 * test and an admin notice on every admin screen.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_Key_Store_Health {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_status_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Add the key-store test to Site Health.
	 *
	 * @param array $tests Registered tests.
	 * @return array
	 */
	public static function register_site_status_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$tests['direct']['mcpwp_key_store'] = array(
			'label' => __( 'MCPWP API keys can be read', 'mcpwp' ),
			'test'  => array( __CLASS__, 'run_site_status_test' ),
		);
		return $tests;
	}

	/**
	 * Site Health result.
	 *
	 * @return array
	 */
	public static function run_site_status_test() {
		require_once __DIR__ . '/core/class-mcpwp-key-state.php';
		$result = array(
			'label'       => __( 'MCPWP API keys can be read', 'mcpwp' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'mcpwp' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'The stored MCPWP API keys are readable.', 'mcpwp' ) . '</p>',
			'actions'     => '',
			'test'        => 'mcpwp_key_store',
		);

		if ( Mcpwp_Key_State::store_is_unreadable() ) {
			$result['status']         = 'critical';
			$result['label']          = __( 'MCPWP API keys cannot be read', 'mcpwp' );
			$result['badge']['color'] = 'red';
			$result['description']    = '<p>' . esc_html( Mcpwp_Key_State::unreadable_message() ) . '</p>';
		}

		return $result;
	}

	/**
	 * Admin notice on every admin screen while the store is unreadable.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require_once __DIR__ . '/core/class-mcpwp-key-state.php';
		if ( ! Mcpwp_Key_State::store_is_unreadable() ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'MCPWP is refusing every API key.', 'mcpwp' ),
			esc_html( Mcpwp_Key_State::unreadable_message() )
		);
	}
}
