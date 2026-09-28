<?php
/**
 * Admin control_room page methods.
 *
 * Carved verbatim from Mcpwp_Admin (G3 split). Mixed back via trait — same class, same $this.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Admin_Control_Room_Trait {

	/**
	 * Render the human control room page.
	 */
	public function render_control_room_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcpwp' ) );
		}

		$this->handle_control_room_actions();

		$control_room = $this->get_control_room_data();

		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-control-room-display.php';
	}

	/**
	 * Handle control room form actions.
	 *
	 * @return void
	 */
	private function handle_control_room_actions() {
		if ( empty( $_POST['mcpwp_control_room_action'] ) ) {
			return;
		}

		check_admin_referer( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' );

		$action = sanitize_key( wp_unslash( $_POST['mcpwp_control_room_action'] ) );
		$result = null;

		if ( 'run_seo_audit' === $action ) {
			$result = $this->run_control_room_seo_audit();
		} elseif ( 'refresh_signals' === $action ) {
			if ( class_exists( 'Mcpwp_Signals' ) ) {
				$computed = Mcpwp_Signals::compute( array(), Mcpwp_Signals::request_time_budget() );
				/* translators: %d: number of signals found */
				$result   = sprintf( __( 'Signals refreshed: %d signal(s) found.', 'mcpwp' ), count( $computed ) );
			}
		} elseif ( 'purge_closed_approvals' === $action ) {
			// #981 review round 4: drop every closed approval record now.
			$removed = class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::purge_closed() : 0;
			$result  = is_wp_error( $removed )
				? $removed
				/* translators: %d: number of records removed */
				: sprintf( __( 'Removed %d closed approval request(s).', 'mcpwp' ), (int) $removed );
		} elseif ( 'rollback_action_log' === $action ) {
			$log_id = isset( $_POST['action_log_id'] ) ? sanitize_text_field( wp_unslash( $_POST['action_log_id'] ) ) : '';
			if ( class_exists( 'Mcpwp_Action_Log' ) && $log_id ) {
				$rollback = Mcpwp_Action_Log::rollback( $log_id );
				$result   = is_wp_error( $rollback )
					? $rollback
					: __( 'Action rolled back successfully.', 'mcpwp' );
			} else {
				$result = new WP_Error( 'mcpwp_action_log_unavailable', __( 'Action log unavailable or missing log ID.', 'mcpwp' ) );
			}
		} else {
			$approval_id = isset( $_POST['mcpwp_approval_id'] ) ? sanitize_key( wp_unslash( $_POST['mcpwp_approval_id'] ) ) : '';
			$result      = $this->handle_control_room_approval_action( $action, $approval_id );
		}

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_control_room_error',
				$result->get_error_message(),
				'error'
			);
			return;
		}

		if ( is_string( $result ) && '' !== $result ) {
			add_settings_error(
				'mcpwp_messages',
				'mcpwp_control_room_updated',
				$result,
				'updated'
			);
		}
	}

	/**
	 * Handle an approval transition from the control room.
	 *
	 * @param string $action      Action slug.
	 * @param string $approval_id Approval request ID.
	 * @return string|WP_Error Message on success.
	 */
	private function handle_control_room_approval_action( $action, $approval_id ) {
		if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
			return new WP_Error( 'mcpwp_approvals_unavailable', __( 'Approval storage is unavailable.', 'mcpwp' ) );
		}

		if ( '' === $approval_id ) {
			return new WP_Error( 'mcpwp_missing_approval_id', __( 'Approval request ID is required.', 'mcpwp' ) );
		}

		switch ( $action ) {
			case 'approve':
				$result = Mcpwp_Approvals::approve_request( $approval_id, '', array( 'admin_user' => get_current_user_id(), 'actor' => 'wp-user:' . get_current_user_id() ) );
				$message = __( 'Approval request approved.', 'mcpwp' );
				break;
			case 'reject':
				$result = Mcpwp_Approvals::reject_request( $approval_id, '', array( 'actor' => 'wp-user:' . get_current_user_id() ) );
				$message = __( 'Approval request rejected.', 'mcpwp' );
				break;
			case 'apply':
				$result = Mcpwp_Approvals::apply_request( $approval_id, array( 'admin_user' => get_current_user_id(), 'actor' => 'wp-user:' . get_current_user_id() ) );
				$message = __( 'Approved change applied.', 'mcpwp' );
				break;
			case 'rollback':
				$result = Mcpwp_Approvals::rollback_request( $approval_id, array( 'actor' => 'wp-user:' . get_current_user_id() ) );
				$message = __( 'Applied change rolled back.', 'mcpwp' );
				break;
			case 'resolve_completed':
			case 'resolve_not_completed':
				$result  = Mcpwp_Approvals::resolve_stale( $approval_id, 'resolve_completed' === $action ? 'completed' : 'not_completed', array( 'actor' => 'wp-user:' . get_current_user_id() ) );
				$message = 'resolve_completed' === $action
					? __( 'Marked as completed.', 'mcpwp' )
					: __( 'Marked as not completed. Check the post content before applying again.', 'mcpwp' );
				break;
			default:
				return new WP_Error( 'mcpwp_unknown_control_action', __( 'Unknown control room action.', 'mcpwp' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $message;
	}

	/**
	 * Run and store a compact SEO audit from the control room.
	 *
	 * @return string|WP_Error Message on success.
	 */
	private function run_control_room_seo_audit() {
		if ( ! class_exists( 'Mcpwp_REST_SEO_Audit' ) || ! class_exists( 'WP_REST_Request' ) ) {
			return new WP_Error( 'mcpwp_seo_audit_unavailable', __( 'SEO audit tools are unavailable.', 'mcpwp' ) );
		}

		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/audit-site' );
		$request->set_param( 'post_types', 'post,page' );
		$request->set_param( 'limit', 20 );
		$request->set_param( 'include_drafts', false );
		$request->set_param( 'store', true );

		$response = ( new Mcpwp_REST_SEO_Audit() )->audit_seo_site( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response instanceof WP_REST_Response ? $response->get_data() : array();
		if ( isset( $data['success'] ) && empty( $data['success'] ) ) {
			$message = isset( $data['message'] ) ? (string) $data['message'] : __( 'SEO audit failed.', 'mcpwp' );
			return new WP_Error( 'mcpwp_seo_audit_failed', $message );
		}

		$payload = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
		$summary = isset( $payload['summary'] ) && is_array( $payload['summary'] ) ? $payload['summary'] : array();
		$count   = isset( $summary['audited_count'] ) ? absint( $summary['audited_count'] ) : 0;

		return sprintf(
			/* translators: %d: number of audited URLs */
			_n( 'Stored SEO audit completed for %d URL.', 'Stored SEO audit completed for %d URLs.', $count, 'mcpwp' ),
			$count
		);
	}

	/**
	 * Get summarized control room data.
	 *
	 * @return array
	 */
	public function get_control_room_data() {
		// Control Room renders under render_control_room_page(), which requires
		// current_user_can( 'activate_plugins' ) — a genuine admin, not a read API
		// key — so it legitimately reveals full non-public approval/issue data.
		$approvals = class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::list_requests( '', 100, true ) : array();
		$seo_filters   = $this->get_control_room_seo_filters();
		$event_filters = $this->get_control_room_event_filters();
		$approval_counts = array(
			'pending'     => 0,
			'approved'    => 0,
			'applied'     => 0,
			'rejected'    => 0,
			'rolled_back' => 0,
		);

		foreach ( $approvals as $approval ) {
			$status = isset( $approval['status'] ) ? sanitize_key( (string) $approval['status'] ) : '';
			if ( isset( $approval_counts[ $status ] ) ) {
				$approval_counts[ $status ]++;
			}
		}

		$seo_open = class_exists( 'Mcpwp_SEO_Audit_Store' )
			? Mcpwp_SEO_Audit_Store::list_issues(
				array_merge(
					$seo_filters,
					array( 'limit' => 8, 'reveal_non_public' => true )
				)
			)
			: array(
				'summary' => array( 'total' => 0, 'open' => 0, 'resolved' => 0, 'error' => 0, 'warning' => 0, 'info' => 0 ),
				'issues'  => array(),
			);

		$seo_all = class_exists( 'Mcpwp_SEO_Audit_Store' )
			? Mcpwp_SEO_Audit_Store::list_issues( array( 'limit' => 1, 'reveal_non_public' => true ) )
			: $seo_open;

		$recent_activity = $this->get_recent_activity_rows( 8 );
		$event_inbox     = $this->get_control_room_event_inbox( $event_filters );

		$seo_visibility_latest = class_exists( 'Mcpwp_SEO_Visibility' )
			? Mcpwp_SEO_Visibility::get_latest()
			: array( 'rows' => array(), 'reason' => 'unavailable' );

		$data = array(
			'approval_counts'  => $approval_counts,
			// Priority order when any pending request carries a validated Jev
			// triage; falls back to date order on its own when none do.
			'pending_approvals' => class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::list_requests( 'pending', 5, true, 'priority' ) : array(),
			'approved_approvals' => class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::list_requests( 'approved', 5, true ) : array(),
			// #981 review round 3: applies/rollbacks that ran but could not
			// be recorded, stalled, or only partially applied.
			'attention_approvals' => array_values(
				array_filter(
					$approvals,
					function ( $approval ) {
						return ! empty( $approval['needs_attention'] );
					}
				)
			),
			'rollback_ready'   => array_slice(
				array_values(
					array_filter(
						$approvals,
						function ( $approval ) {
							// #981 review round 5: only what can still be rolled
							// back from what the record keeps.
							return isset( $approval['status'] ) && in_array( $approval['status'], array( 'applied', 'apply_failed' ), true ) && ! empty( $approval['rollback_available'] );
						}
					)
				),
				0,
				5
			),
			'seo_summary'      => isset( $seo_all['summary'] ) && is_array( $seo_all['summary'] ) ? $seo_all['summary'] : array(),
			'open_seo_issues'  => isset( $seo_open['issues'] ) && is_array( $seo_open['issues'] ) ? $seo_open['issues'] : array(),
			'seo_visibility'    => $seo_visibility_latest,
			'seo_opportunities' => class_exists( 'Mcpwp_SEO_Opportunities' ) && class_exists( 'Mcpwp_SEO_Visibility' )
				? Mcpwp_SEO_Opportunities::rank(
					$seo_visibility_latest['rows'],
					array(),
					isset( $seo_open['issues'] ) && is_array( $seo_open['issues'] ) ? $seo_open['issues'] : array(),
					isset( $seo_visibility_latest['reason'] ) ? $seo_visibility_latest['reason'] : null
				)
				: array( 'mode' => 'unknown', 'opportunities' => array(), 'reason' => 'unavailable' ),
			'seo_filters'      => $seo_filters,
			'event_inbox'      => $event_inbox,
			'event_filters'    => $event_filters,
			'recent_activity'  => $recent_activity,
			'action_log'       => class_exists( 'Mcpwp_Action_Log' )
				? Mcpwp_Action_Log::list_entries( array( 'limit' => 20 ) )
				: array( 'entries' => array(), 'total' => 0 ),
		);

		$data['recommendations'] = $this->get_control_room_recommendations( $data );

		// Site Signals (#363).
		$data['signals'] = class_exists( 'Mcpwp_Signals' )
			? Mcpwp_Signals::get_signals( array(), '', 20 )
			: array();

		// Site Memory summary (#362).
		$data['memory_count'] = 0;
		if ( class_exists( 'Mcpwp_Site_Memory' ) ) {
			Mcpwp_Site_Memory::maybe_migrate_site_context();
			$mem_grouped = Mcpwp_Site_Memory::list_all();
			foreach ( $mem_grouped as $entries ) {
				$data['memory_count'] += count( $entries );
			}
		}

		// mupot connector (#689 read-first slice).
		$data['mupot'] = $this->get_control_room_mupot_data();

		return $data;
	}

	/**
	 * Read and sanitize control room SEO filters.
	 *
	 * @return array
	 */
	private function get_control_room_seo_filters() {
		$status   = isset( $_GET['mcpwp_seo_status'] ) ? sanitize_key( wp_unslash( $_GET['mcpwp_seo_status'] ) ) : 'open';
		$severity = isset( $_GET['mcpwp_seo_severity'] ) ? sanitize_key( wp_unslash( $_GET['mcpwp_seo_severity'] ) ) : '';
		$category = isset( $_GET['mcpwp_seo_category'] ) ? sanitize_key( wp_unslash( $_GET['mcpwp_seo_category'] ) ) : '';

		if ( ! in_array( $status, array( 'open', 'resolved', '' ), true ) ) {
			$status = 'open';
		}

		if ( ! in_array( $severity, array( 'error', 'warning', 'info', '' ), true ) ) {
			$severity = '';
		}

		if ( ! in_array( $category, array( 'readiness', 'structured_data', 'media', 'content_quality', '' ), true ) ) {
			$category = '';
		}

		return array(
			'status'   => $status,
			'severity' => $severity,
			'category' => $category,
		);
	}

	/**
	 * Read and sanitize control room event filters.
	 *
	 * @return array
	 */
	private function get_control_room_event_filters() {
		$type       = isset( $_GET['mcpwp_event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['mcpwp_event_type'] ) ) : '';
		$risk_level = isset( $_GET['mcpwp_event_risk'] ) ? sanitize_key( wp_unslash( $_GET['mcpwp_event_risk'] ) ) : '';

		$type = preg_replace( '/[^a-z0-9_.-]/', '', strtolower( $type ) );

		if ( ! in_array( $risk_level, array( 'high', 'medium', 'low', '' ), true ) ) {
			$risk_level = '';
		}

		return array(
			'type'       => $type,
			'risk_level' => $risk_level,
		);
	}

	/**
	 * Build Control Room event inbox data.
	 *
	 * @param array $filters Event filters.
	 * @return array
	 */
	private function get_control_room_event_inbox( $filters ) {
		if ( ! class_exists( 'Mcpwp_Event_Store' ) ) {
			return array(
				'summary' => array( 'total' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'escalated' => 0 ),
				'events'  => array(),
			);
		}

		$result = Mcpwp_Event_Store::list_events(
			array(
				'type'  => isset( $filters['type'] ) ? $filters['type'] : '',
				'limit' => 50,
			)
		);
		$events = isset( $result['events'] ) && is_array( $result['events'] ) ? $result['events'] : array();

		if ( ! empty( $filters['risk_level'] ) ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $filters ) {
						return isset( $event['risk_level'] ) && $filters['risk_level'] === $event['risk_level'];
					}
				)
			);
		}

		$summary = array(
			'total'     => count( $events ),
			'high'      => 0,
			'medium'    => 0,
			'low'       => 0,
			'escalated' => 0,
		);

		foreach ( $events as $index => $event ) {
			$risk = isset( $event['risk_level'] ) ? sanitize_key( (string) $event['risk_level'] ) : 'low';
			if ( isset( $summary[ $risk ] ) ) {
				$summary[ $risk ]++;
			}

			$events[ $index ]['escalation'] = $this->classify_control_room_event_escalation( $event );
			if ( ! empty( $events[ $index ]['escalation']['escalated'] ) ) {
				$summary['escalated']++;
			}
		}

		return array(
			'summary' => $summary,
			'events'  => array_slice( $events, 0, 10 ),
		);
	}

	/**
	 * Classify event urgency for the Control Room inbox.
	 *
	 * @param array $event Event record.
	 * @return array
	 */
	private function classify_control_room_event_escalation( $event ) {
		$risk           = isset( $event['risk_level'] ) ? sanitize_key( (string) $event['risk_level'] ) : 'low';
		$approval_state = isset( $event['approval_state'] ) ? sanitize_key( (string) $event['approval_state'] ) : '';
		$seo_state      = isset( $event['seo_state'] ) ? sanitize_key( (string) $event['seo_state'] ) : '';
		$type           = isset( $event['type'] ) ? sanitize_text_field( (string) $event['type'] ) : '';

		if ( 'high' === $risk || 'fail' === $seo_state ) {
			return array(
				'escalated' => true,
				'level'     => 'high',
				'label'     => __( 'Needs attention', 'mcpwp' ),
				'reason'    => __( 'High-risk event or failing SEO state.', 'mcpwp' ),
			);
		}

		if ( 'pending' === $approval_state || false !== strpos( $type, 'approval.' ) ) {
			return array(
				'escalated' => true,
				'level'     => 'medium',
				'label'     => __( 'Human decision', 'mcpwp' ),
				'reason'    => __( 'Approval lifecycle event requires human awareness.', 'mcpwp' ),
			);
		}

		return array(
			'escalated' => false,
			'level'     => 'low',
			'label'     => __( 'Informational', 'mcpwp' ),
			'reason'    => __( 'No escalation rule matched.', 'mcpwp' ),
		);
	}

	/**
	 * Build short human recommendations for the control room.
	 *
	 * @param array $data Control room data.
	 * @return array
	 */
	private function get_control_room_recommendations( $data ) {
		$recommendations = array();
		$approval_counts = isset( $data['approval_counts'] ) && is_array( $data['approval_counts'] ) ? $data['approval_counts'] : array();
		$seo_summary     = isset( $data['seo_summary'] ) && is_array( $data['seo_summary'] ) ? $data['seo_summary'] : array();

		if ( ! empty( $approval_counts['pending'] ) ) {
			$recommendations[] = array(
				'priority' => 'high',
				'title'    => __( 'Review pending approvals', 'mcpwp' ),
				'detail'   => sprintf(
					/* translators: %d: number of pending approvals */
					_n( '%d agent change is waiting for human review.', '%d agent changes are waiting for human review.', (int) $approval_counts['pending'], 'mcpwp' ),
					(int) $approval_counts['pending']
				),
			);
		}

		if ( ! empty( $approval_counts['applied'] ) ) {
			$recommendations[] = array(
				'priority' => 'medium',
				'title'    => __( 'Keep rollback handles visible', 'mcpwp' ),
				'detail'   => __( 'Applied approval requests can still be rolled back if production content needs to revert.', 'mcpwp' ),
			);
		}

		if ( ! empty( $seo_summary['error'] ) ) {
			$recommendations[] = array(
				'priority' => 'high',
				'title'    => __( 'Prioritize SEO errors', 'mcpwp' ),
				'detail'   => sprintf(
					/* translators: %d: number of SEO errors */
					_n( '%d stored SEO error needs attention before lower-priority warnings.', '%d stored SEO errors need attention before lower-priority warnings.', (int) $seo_summary['error'], 'mcpwp' ),
					(int) $seo_summary['error']
				),
			);
		}

		if ( ! empty( $data['event_inbox']['summary']['escalated'] ) ) {
			$recommendations[] = array(
				'priority' => 'high',
				'title'    => __( 'Review escalated events', 'mcpwp' ),
				'detail'   => sprintf(
					/* translators: %d: number of escalated events */
					_n( '%d recent event needs human attention.', '%d recent events need human attention.', (int) $data['event_inbox']['summary']['escalated'], 'mcpwp' ),
					(int) $data['event_inbox']['summary']['escalated']
				),
			);
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = array(
				'priority' => 'low',
				'title'    => __( 'Run the next supervised workflow', 'mcpwp' ),
				'detail'   => __( 'No urgent approval or stored SEO issue is visible. Run a stored SEO audit or create an approval-required draft change to populate the control room.', 'mcpwp' ),
			);
		}

		return $recommendations;
	}
}
