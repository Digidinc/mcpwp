<?php
/**
 * Admin mupot Control Room data assembly.
 *
 * Small dedicated trait (not folded into trait-mcpwp-admin-control-room.php)
 * so it stays independently testable without the full Control Room dependency
 * graph (Approvals, SEO Audit Store, Signals, etc.) — see MupotControlRoomTest.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Admin_Mupot_Trait {

	/**
	 * Assemble the "My mupot" Control Room section data for the current user.
	 *
	 * @return array{enabled: bool, connected: bool, has_squad: bool, status: array|null, task_board: array|null, error: string|null}
	 */
	public function get_control_room_mupot_data() {
		$settings = get_option( 'mcpwp_settings', array() );
		$enabled  = ! empty( $settings['mupot_enabled'] );

		$data = array(
			'enabled'    => $enabled,
			'connected'  => false,
			'has_squad'  => false,
			'status'     => null,
			'task_board' => null,
			'error'      => null,
		);

		if ( ! $enabled ) {
			return $data;
		}

		$connector  = new Mcpwp_Mupot_Connector();
		$user_id    = get_current_user_id();
		$data['connected'] = $connector->has_token( $user_id );
		$data['has_squad'] = $connector->has_squad( $user_id );

		if ( ! $data['connected'] ) {
			return $data;
		}

		$status = $connector->get_status( $user_id );
		if ( is_wp_error( $status ) ) {
			$data['error'] = $status->get_error_message();
			return $data;
		}
		$data['status'] = $status;

		if ( $data['has_squad'] ) {
			$board = $connector->get_task_board( $user_id );
			if ( is_wp_error( $board ) ) {
				$data['error'] = $board->get_error_message();
			} else {
				$data['task_board'] = $board;
			}
		}

		return $data;
	}
}
