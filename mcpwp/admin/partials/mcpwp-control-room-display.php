<?php
/**
 * Human control room template.
 *
 * Variables available from render_control_room_page():
 *   $control_room - array summarized approval, SEO, and activity data.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$control_room    = is_array( $control_room ) ? $control_room : array();
$approval_counts = isset( $control_room['approval_counts'] ) && is_array( $control_room['approval_counts'] ) ? $control_room['approval_counts'] : array();
$seo_summary     = isset( $control_room['seo_summary'] ) && is_array( $control_room['seo_summary'] ) ? $control_room['seo_summary'] : array();
$pending_items   = isset( $control_room['pending_approvals'] ) && is_array( $control_room['pending_approvals'] ) ? $control_room['pending_approvals'] : array();
$approved_items  = isset( $control_room['approved_approvals'] ) && is_array( $control_room['approved_approvals'] ) ? $control_room['approved_approvals'] : array();
$rollback_items  = isset( $control_room['rollback_ready'] ) && is_array( $control_room['rollback_ready'] ) ? $control_room['rollback_ready'] : array();
$seo_issues      = isset( $control_room['open_seo_issues'] ) && is_array( $control_room['open_seo_issues'] ) ? $control_room['open_seo_issues'] : array();
$seo_filters     = isset( $control_room['seo_filters'] ) && is_array( $control_room['seo_filters'] ) ? $control_room['seo_filters'] : array();
$event_inbox     = isset( $control_room['event_inbox'] ) && is_array( $control_room['event_inbox'] ) ? $control_room['event_inbox'] : array();
$event_filters   = isset( $control_room['event_filters'] ) && is_array( $control_room['event_filters'] ) ? $control_room['event_filters'] : array();
$activity_rows   = isset( $control_room['recent_activity'] ) && is_array( $control_room['recent_activity'] ) ? $control_room['recent_activity'] : array();
$recommendations = isset( $control_room['recommendations'] ) && is_array( $control_room['recommendations'] ) ? $control_room['recommendations'] : array();
$event_summary   = isset( $event_inbox['summary'] ) && is_array( $event_inbox['summary'] ) ? $event_inbox['summary'] : array();
$event_items     = isset( $event_inbox['events'] ) && is_array( $event_inbox['events'] ) ? $event_inbox['events'] : array();
$signals         = isset( $control_room['signals'] ) && is_array( $control_room['signals'] ) ? $control_room['signals'] : array();
$memory_count    = (int) ( $control_room['memory_count'] ?? 0 );

$pending_count  = (int) ( $approval_counts['pending'] ?? 0 );
$approved_count = (int) ( $approval_counts['approved'] ?? 0 );
$applied_count  = (int) ( $approval_counts['applied'] ?? 0 );
$seo_open_count = (int) ( $seo_summary['open'] ?? 0 );
$seo_error_count = (int) ( $seo_summary['error'] ?? 0 );
$event_escalated_count = (int) ( $event_summary['escalated'] ?? 0 );
?>

<div class="wrap mcpwp-admin mcpwp-control-room">
	<h1 class="mcpwp-header">
		<span class="mcpwp-logo">
			<span class="dashicons dashicons-shield"></span>
		</span>
		<?php esc_html_e( 'Control Room', 'mumcp' ); ?>
		<span class="mcpwp-version">v<?php echo esc_html( MCPWP_VERSION ); ?></span>
	</h1>

	<p class="description">
		<?php esc_html_e( 'Review agent work, SEO findings, recent activity, and rollback-ready changes from one supervised WordPress screen.', 'mumcp' ); ?>
	</p>

	<?php settings_errors( 'mcpwp_messages' ); ?>

	<div class="mcpwp-control-summary">
		<div class="mcpwp-library-stat mcpwp-control-stat <?php echo esc_attr( $pending_count > 0 ? 'is-warning' : 'is-good' ); ?>">
			<span class="mcpwp-control-stat__icon dashicons <?php echo esc_attr( $pending_count > 0 ? 'dashicons-clock' : 'dashicons-yes-alt' ); ?>"></span>
			<span class="mcpwp-library-stat__value"><?php echo esc_html( (string) $pending_count ); ?></span>
			<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Pending approvals', 'mumcp' ); ?></span>
		</div>
		<div class="mcpwp-library-stat mcpwp-control-stat <?php echo esc_attr( $applied_count > 0 ? 'is-info' : 'is-good' ); ?>">
			<span class="mcpwp-control-stat__icon dashicons <?php echo esc_attr( $applied_count > 0 ? 'dashicons-backup' : 'dashicons-shield' ); ?>"></span>
			<span class="mcpwp-library-stat__value"><?php echo esc_html( (string) $applied_count ); ?></span>
			<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Rollback-ready changes', 'mumcp' ); ?></span>
		</div>
		<div class="mcpwp-library-stat mcpwp-control-stat <?php echo esc_attr( $seo_open_count > 0 ? 'is-warning' : 'is-good' ); ?>">
			<span class="mcpwp-control-stat__icon dashicons <?php echo esc_attr( $seo_open_count > 0 ? 'dashicons-search' : 'dashicons-chart-line' ); ?>"></span>
			<span class="mcpwp-library-stat__value"><?php echo esc_html( (string) $seo_open_count ); ?></span>
			<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Open SEO issues', 'mumcp' ); ?></span>
		</div>
		<div class="mcpwp-library-stat mcpwp-control-stat <?php echo esc_attr( $seo_error_count > 0 ? 'is-critical' : 'is-good' ); ?>">
			<span class="mcpwp-control-stat__icon dashicons <?php echo esc_attr( $seo_error_count > 0 ? 'dashicons-warning' : 'dashicons-yes' ); ?>"></span>
			<span class="mcpwp-library-stat__value"><?php echo esc_html( (string) $seo_error_count ); ?></span>
			<span class="mcpwp-library-stat__label"><?php esc_html_e( 'SEO errors', 'mumcp' ); ?></span>
		</div>
		<div class="mcpwp-library-stat mcpwp-control-stat <?php echo esc_attr( $event_escalated_count > 0 ? 'is-critical' : 'is-good' ); ?>">
			<span class="mcpwp-control-stat__icon dashicons <?php echo esc_attr( $event_escalated_count > 0 ? 'dashicons-bell' : 'dashicons-yes-alt' ); ?>"></span>
			<span class="mcpwp-library-stat__value"><?php echo esc_html( (string) $event_escalated_count ); ?></span>
			<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Escalated events', 'mumcp' ); ?></span>
		</div>
	</div>

	<!-- F-07: in-page anchor nav strip -->
	<nav class="mcpwp-anchor-nav nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Jump to section', 'mumcp' ); ?>">
		<a class="nav-tab" href="#mcpwp-section-approvals"><?php esc_html_e( 'Approvals', 'mumcp' ); ?></a>
		<a class="nav-tab" href="#mcpwp-section-seo"><?php esc_html_e( 'SEO', 'mumcp' ); ?></a>
		<a class="nav-tab" href="#mcpwp-section-events"><?php esc_html_e( 'Events', 'mumcp' ); ?></a>
		<a class="nav-tab" href="#mcpwp-section-activity"><?php esc_html_e( 'Activity', 'mumcp' ); ?></a>
		<a class="nav-tab" href="#mcpwp-section-signals"><?php esc_html_e( 'Signals', 'mumcp' ); ?></a>
	</nav>

	<div class="mcpwp-control-grid">
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Recommended Next Actions', 'mumcp' ); ?>
			</h2>
			<?php if ( empty( $recommendations ) ) : ?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-yes-alt"></span>
					<p><?php esc_html_e( 'No actions needed right now — your site is in good shape.', 'mumcp' ); ?></p>
				</div>
			<?php else : ?>
			<?php foreach ( $recommendations as $recommendation ) : ?>
				<div class="mcpwp-control-action mcpwp-control-action--<?php echo esc_attr( sanitize_html_class( $recommendation['priority'] ?? 'low' ) ); ?>">
					<span class="mcpwp-control-priority mcpwp-control-priority--<?php echo esc_attr( sanitize_html_class( $recommendation['priority'] ?? 'low' ) ); ?>">
						<?php echo esc_html( $recommendation['priority'] ?? 'low' ); ?>
					</span>
					<div>
						<strong><?php echo esc_html( $recommendation['title'] ?? '' ); ?></strong>
						<p><?php echo esc_html( $recommendation['detail'] ?? '' ); ?></p>
					</div>
				</div>
			<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<div class="mcpwp-card" id="mcpwp-section-approvals">
			<h2>
				<span class="dashicons dashicons-shield"></span>
				<?php esc_html_e( 'Pending Approvals', 'mumcp' ); ?>
			</h2>
			<?php if ( empty( $pending_items ) ) : ?>
				<div class="mcpwp-control-empty is-good">
					<span class="dashicons dashicons-yes-alt"></span>
					<p><?php esc_html_e( 'No pending approval requests.', 'mumcp' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="mcpwp-control-list">
					<?php foreach ( $pending_items as $item ) : ?>
						<?php
						$resource     = isset( $item['resource'] ) && is_array( $item['resource'] ) ? $item['resource'] : array();
						$post_id      = isset( $resource['id'] ) ? absint( $resource['id'] ) : 0;
						$edit_url     = $post_id ? get_edit_post_link( $post_id, 'raw' ) : '';
						// F-08: one-line change summary — resource type + id + short description.
						$res_type     = ! empty( $resource['type'] ) ? $resource['type'] : '';
						$res_id       = ! empty( $resource['id'] ) ? (string) $resource['id'] : '';
						$res_label    = trim( $res_type . ( $res_id ? ' #' . $res_id : '' ) );
						$short_desc   = ! empty( $item['description'] ) ? $item['description'] : ( ! empty( $item['detail'] ) ? $item['detail'] : '' );
						$short_desc   = $short_desc ? wp_html_excerpt( $short_desc, 120, '…' ) : '';
						?>
						<li class="mcpwp-control-list__item is-warning">
							<span class="mcpwp-control-list__icon dashicons dashicons-clock"></span>
							<strong><?php echo esc_html( $item['title'] ?? $item['id'] ?? '' ); ?></strong>
							<span><?php echo esc_html( $item['tool'] ?? $item['action'] ?? '' ); ?></span>
							<?php if ( $res_label || $short_desc ) : ?>
								<span class="mcpwp-approval-summary">
									<?php if ( $res_label ) : ?>
										<code><?php echo esc_html( $res_label ); ?></code>
									<?php endif; ?>
									<?php if ( $short_desc ) : ?>
										<?php echo esc_html( $short_desc ); ?>
									<?php endif; ?>
								</span>
							<?php endif; ?>
							<div class="mcpwp-control-actions">
								<form method="post">
									<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
									<input type="hidden" name="mcpwp_control_room_action" value="approve">
									<input type="hidden" name="mcpwp_approval_id" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
									<button type="submit" class="button button-primary"
										onclick="return confirm('<?php esc_attr_e( 'Apply this change to the live site? You can roll it back from the Rollback Ready section.', 'mumcp' ); ?>')"
									><?php esc_html_e( 'Approve', 'mumcp' ); ?></button>
								</form>
								<form method="post">
									<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
									<input type="hidden" name="mcpwp_control_room_action" value="reject">
									<input type="hidden" name="mcpwp_approval_id" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Reject', 'mumcp' ); ?></button>
								</form>
								<?php if ( $edit_url ) : ?>
									<a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Preview in WordPress', 'mumcp' ); ?></a>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>

	<div class="mcpwp-control-grid">
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-saved"></span>
				<?php esc_html_e( 'Approved Changes', 'mumcp' ); ?>
			</h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of approved approvals */
					esc_html( _n( '%d approved change is ready to apply.', '%d approved changes are ready to apply.', $approved_count, 'mumcp' ) ),
					esc_html( (string) $approved_count )
				);
				?>
			</p>
			<?php if ( empty( $approved_items ) ) : ?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-shield"></span>
					<p><?php esc_html_e( 'No approved approval requests are waiting to apply.', 'mumcp' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="mcpwp-control-list">
					<?php foreach ( $approved_items as $item ) : ?>
						<li class="mcpwp-control-list__item is-info">
							<span class="mcpwp-control-list__icon dashicons dashicons-saved"></span>
							<strong><?php echo esc_html( $item['title'] ?? $item['id'] ?? '' ); ?></strong>
							<span><?php echo esc_html( $item['approved_at'] ?? '' ); ?></span>
							<div class="mcpwp-control-actions">
								<form method="post">
									<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
									<input type="hidden" name="mcpwp_control_room_action" value="apply">
									<input type="hidden" name="mcpwp_approval_id" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
									<button type="submit" class="button button-primary"
										onclick="return confirm('<?php esc_attr_e( 'Apply this change to the live site? You can roll it back from the Rollback Ready section.', 'mumcp' ); ?>')"
									><?php esc_html_e( 'Apply', 'mumcp' ); ?></button>
								</form>
								<form method="post">
									<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
									<input type="hidden" name="mcpwp_control_room_action" value="reject">
									<input type="hidden" name="mcpwp_approval_id" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Reject', 'mumcp' ); ?></button>
								</form>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="mcpwp-card" id="mcpwp-section-seo">
			<div class="mcpwp-control-card-header">
				<h2>
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Stored SEO Issues', 'mumcp' ); ?>
				</h2>
				<form method="post">
					<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
					<input type="hidden" name="mcpwp_control_room_action" value="run_seo_audit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Run SEO Audit', 'mumcp' ); ?></button>
				</form>
			</div>
			<form method="get" class="mcpwp-control-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Mcpwp_Admin::CONTROL_ROOM_PAGE_SLUG ); ?>">
				<label>
					<span><?php esc_html_e( 'Status', 'mumcp' ); ?></span>
					<select name="mcpwp_seo_status">
						<option value="open" <?php selected( $seo_filters['status'] ?? 'open', 'open' ); ?>><?php esc_html_e( 'Open', 'mumcp' ); ?></option>
						<option value="resolved" <?php selected( $seo_filters['status'] ?? '', 'resolved' ); ?>><?php esc_html_e( 'Resolved', 'mumcp' ); ?></option>
						<option value="" <?php selected( $seo_filters['status'] ?? '', '' ); ?>><?php esc_html_e( 'Any', 'mumcp' ); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Severity', 'mumcp' ); ?></span>
					<select name="mcpwp_seo_severity">
						<option value="" <?php selected( $seo_filters['severity'] ?? '', '' ); ?>><?php esc_html_e( 'Any', 'mumcp' ); ?></option>
						<option value="error" <?php selected( $seo_filters['severity'] ?? '', 'error' ); ?>><?php esc_html_e( 'Error', 'mumcp' ); ?></option>
						<option value="warning" <?php selected( $seo_filters['severity'] ?? '', 'warning' ); ?>><?php esc_html_e( 'Warning', 'mumcp' ); ?></option>
						<option value="info" <?php selected( $seo_filters['severity'] ?? '', 'info' ); ?>><?php esc_html_e( 'Info', 'mumcp' ); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Category', 'mumcp' ); ?></span>
					<select name="mcpwp_seo_category">
						<option value="" <?php selected( $seo_filters['category'] ?? '', '' ); ?>><?php esc_html_e( 'Any', 'mumcp' ); ?></option>
						<option value="readiness" <?php selected( $seo_filters['category'] ?? '', 'readiness' ); ?>><?php esc_html_e( 'Readiness', 'mumcp' ); ?></option>
						<option value="structured_data" <?php selected( $seo_filters['category'] ?? '', 'structured_data' ); ?>><?php esc_html_e( 'Structured data', 'mumcp' ); ?></option>
						<option value="media" <?php selected( $seo_filters['category'] ?? '', 'media' ); ?>><?php esc_html_e( 'Media', 'mumcp' ); ?></option>
						<option value="content_quality" <?php selected( $seo_filters['category'] ?? '', 'content_quality' ); ?>><?php esc_html_e( 'Content quality', 'mumcp' ); ?></option>
					</select>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'mumcp' ); ?></button>
			</form>
			<?php if ( empty( $seo_issues ) ) : ?>
				<div class="mcpwp-control-empty is-good">
					<span class="dashicons dashicons-chart-line"></span>
					<p><?php esc_html_e( 'No stored open SEO issues. Run a site SEO audit with store=true to populate this panel.', 'mumcp' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="mcpwp-control-list">
					<?php foreach ( $seo_issues as $issue ) : ?>
						<?php
						$post_id     = isset( $issue['post_id'] ) ? absint( $issue['post_id'] ) : 0;
						$edit_url    = $post_id ? get_edit_post_link( $post_id, 'raw' ) : '';
						$severity    = isset( $issue['severity'] ) ? sanitize_key( (string) $issue['severity'] ) : 'info';
						$icon_class  = 'error' === $severity ? 'dashicons-warning' : ( 'warning' === $severity ? 'dashicons-info' : 'dashicons-lightbulb' );
						$state_class = 'error' === $severity ? 'is-critical' : ( 'warning' === $severity ? 'is-warning' : 'is-info' );
						?>
						<li class="mcpwp-control-list__item <?php echo esc_attr( $state_class ); ?>">
							<span class="mcpwp-control-list__icon dashicons <?php echo esc_attr( $icon_class ); ?>"></span>
							<strong><?php echo esc_html( $issue['message'] ?? $issue['code'] ?? '' ); ?></strong>
							<span>
								<?php echo esc_html( strtoupper( $severity ) ); ?>
								<?php echo esc_html( ' · ' . (string) ( $issue['category'] ?? '' ) ); ?>
							</span>
							<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $issue['title'] ?? __( 'Open post', 'mumcp' ) ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-backup"></span>
				<?php esc_html_e( 'Rollback Ready', 'mumcp' ); ?>
			</h2>
			<?php if ( empty( $rollback_items ) ) : ?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-shield"></span>
					<p><?php esc_html_e( 'No applied approval requests are currently listed for rollback.', 'mumcp' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="mcpwp-control-list">
					<?php foreach ( $rollback_items as $item ) : ?>
						<li class="mcpwp-control-list__item is-info">
							<span class="mcpwp-control-list__icon dashicons dashicons-backup"></span>
							<strong><?php echo esc_html( $item['title'] ?? $item['id'] ?? '' ); ?></strong>
							<span><?php echo esc_html( $item['applied_at'] ?? '' ); ?></span>
							<div class="mcpwp-control-actions">
								<form method="post">
									<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
									<input type="hidden" name="mcpwp_control_room_action" value="rollback">
									<input type="hidden" name="mcpwp_approval_id" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Rollback', 'mumcp' ); ?></button>
								</form>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>

	<div class="mcpwp-card" id="mcpwp-section-events">
		<div class="mcpwp-control-card-header">
			<h2>
				<span class="dashicons dashicons-bell"></span>
				<?php esc_html_e( 'Event Inbox', 'mumcp' ); ?>
			</h2>
			<span class="mcpwp-control-count">
				<?php
				printf(
					/* translators: %d: number of visible events */
					esc_html( _n( '%d event', '%d events', (int) ( $event_summary['total'] ?? 0 ), 'mumcp' ) ),
					esc_html( (string) ( $event_summary['total'] ?? 0 ) )
				);
				?>
			</span>
		</div>
		<form method="get" class="mcpwp-control-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Mcpwp_Admin::CONTROL_ROOM_PAGE_SLUG ); ?>">
			<label>
				<span><?php esc_html_e( 'Event type', 'mumcp' ); ?></span>
				<input type="text" name="mcpwp_event_type" value="<?php echo esc_attr( $event_filters['type'] ?? '' ); ?>" placeholder="approval.created">
			</label>
			<label>
				<span><?php esc_html_e( 'Risk', 'mumcp' ); ?></span>
				<select name="mcpwp_event_risk">
					<option value="" <?php selected( $event_filters['risk_level'] ?? '', '' ); ?>><?php esc_html_e( 'Any', 'mumcp' ); ?></option>
					<option value="high" <?php selected( $event_filters['risk_level'] ?? '', 'high' ); ?>><?php esc_html_e( 'High', 'mumcp' ); ?></option>
					<option value="medium" <?php selected( $event_filters['risk_level'] ?? '', 'medium' ); ?>><?php esc_html_e( 'Medium', 'mumcp' ); ?></option>
					<option value="low" <?php selected( $event_filters['risk_level'] ?? '', 'low' ); ?>><?php esc_html_e( 'Low', 'mumcp' ); ?></option>
				</select>
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'mumcp' ); ?></button>
		</form>
		<?php if ( empty( $event_items ) ) : ?>
			<div class="mcpwp-control-empty is-muted">
				<span class="dashicons dashicons-bell"></span>
				<p><?php esc_html_e( 'No normalized events match the current filters yet.', 'mumcp' ); ?></p>
			</div>
		<?php else : ?>
			<ul class="mcpwp-control-list mcpwp-control-event-list">
				<?php foreach ( $event_items as $event ) : ?>
					<?php
					$event_risk       = isset( $event['risk_level'] ) ? sanitize_key( (string) $event['risk_level'] ) : 'low';
					$event_escalation = isset( $event['escalation'] ) && is_array( $event['escalation'] ) ? $event['escalation'] : array();
					$event_class      = 'high' === $event_risk ? 'is-critical' : ( 'medium' === $event_risk ? 'is-warning' : 'is-info' );
					$event_icon       = ! empty( $event_escalation['escalated'] ) ? 'dashicons-warning' : 'dashicons-info';
					$event_resource   = isset( $event['resource'] ) && is_array( $event['resource'] ) ? $event['resource'] : array();
					?>
					<li class="mcpwp-control-list__item <?php echo esc_attr( $event_class ); ?>">
						<span class="mcpwp-control-list__icon dashicons <?php echo esc_attr( $event_icon ); ?>"></span>
						<strong><?php echo esc_html( $event['type'] ?? '' ); ?></strong>
						<span>
							<?php echo esc_html( strtoupper( $event_risk ) ); ?>
							<?php echo esc_html( ' · ' . (string) ( $event['timestamp'] ?? '' ) ); ?>
						</span>
						<?php if ( ! empty( $event_escalation['label'] ) ) : ?>
							<span class="mcpwp-control-event-escalation"><?php echo esc_html( $event_escalation['label'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $event['recommended_action'] ) ) : ?>
							<span><?php echo esc_html( $event['recommended_action'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $event_resource['id'] ) || ! empty( $event_resource['type'] ) ) : ?>
							<code><?php echo esc_html( trim( (string) ( $event_resource['type'] ?? '' ) . ' #' . (string) ( $event_resource['id'] ?? '' ) ) ); ?></code>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>

	<div class="mcpwp-card" id="mcpwp-section-activity">
		<h2>
			<span class="dashicons dashicons-list-view"></span>
			<?php esc_html_e( 'Recent Agent Activity', 'mumcp' ); ?>
		</h2>
		<?php if ( empty( $activity_rows ) ) : ?>
			<div class="mcpwp-control-empty is-muted">
				<span class="dashicons dashicons-list-view"></span>
				<p><?php esc_html_e( 'No activity recorded yet.', 'mumcp' ); ?></p>
			</div>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'mumcp' ); ?></th>
						<th><?php esc_html_e( 'Action', 'mumcp' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'mumcp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mumcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $activity_rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['created_at'] ?? '' ); ?></td>
							<td><?php echo esc_html( $row['action'] ?? '' ); ?></td>
							<td><code><?php echo esc_html( $row['endpoint'] ?? '' ); ?></code></td>
							<td><?php echo esc_html( $row['status_code'] ?? '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php
	// ---- AI Action Log ----
	$action_log_data    = isset( $control_room['action_log'] ) && is_array( $control_room['action_log'] ) ? $control_room['action_log'] : array( 'entries' => array(), 'total' => 0 );
	$action_log_entries = is_array( isset( $action_log_data['entries'] ) ? $action_log_data['entries'] : null ) ? $action_log_data['entries'] : array();
	$action_log_total   = (int) ( isset( $action_log_data['total'] ) ? $action_log_data['total'] : 0 );
	?>
	<div class="mcpwp-card mcpwp-action-log">
		<div class="mcpwp-action-log__header">
			<h2>
				<span class="dashicons dashicons-backup"></span>
				<?php esc_html_e( 'AI Action Log', 'mumcp' ); ?>
			</h2>
			<a href="<?php echo esc_url( rest_url( 'mcpwp/v1/action-log/export' ) . '?_wpnonce=' . wp_create_nonce( 'wp_rest' ) ); ?>"
			   class="button button-small" target="_blank">
				<?php esc_html_e( 'Export CSV', 'mumcp' ); ?>
			</a>
		</div>

		<?php if ( empty( $action_log_entries ) ) : ?>
			<div class="mcpwp-control-empty is-muted mcpwp-action-log__empty">
				<span class="dashicons dashicons-backup"></span>
				<p><?php esc_html_e( 'No write-tool actions logged yet. Actions appear here the first time an AI agent calls a write tool (update, create, delete, set, etc.).', 'mumcp' ); ?></p>
			</div>
		<?php else : ?>
			<p class="description mcpwp-action-log__count">
				<?php
				printf(
					/* translators: 1: shown count, 2: total */
					esc_html__( 'Showing %1$d of %2$d logged actions (most recent first).', 'mumcp' ),
					(int) count( $action_log_entries ),
					(int) $action_log_total
				);
				?>
			</p>
			<table class="widefat striped mcpwp-action-log__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tool', 'mumcp' ); ?></th>
						<th><?php esc_html_e( 'Resource', 'mumcp' ); ?></th>
						<th><?php esc_html_e( 'Time', 'mumcp' ); ?></th>
						<th><?php esc_html_e( 'ms', 'mumcp' ); ?></th>
						<th><?php esc_html_e( 'Result', 'mumcp' ); ?></th>
						<th><?php esc_html_e( 'Action', 'mumcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $action_log_entries as $log_entry ) : ?>
						<?php
						$le_success   = ! empty( $log_entry['success'] );
						$le_rollback  = ! empty( $log_entry['rollback_supported'] );
						$le_rolled    = ! empty( $log_entry['rolled_back'] );
						$le_log_id    = esc_attr( (string) ( isset( $log_entry['log_id'] ) ? $log_entry['log_id'] : '' ) );
						$le_resource  = trim(
							( isset( $log_entry['resource_type'] ) ? $log_entry['resource_type'] : '' )
							. ':' .
							( isset( $log_entry['resource_id'] ) ? $log_entry['resource_id'] : '' ),
							':'
						);
						$le_ts        = isset( $log_entry['timestamp'] ) ? $log_entry['timestamp'] : '';
						?>
						<tr>
							<td class="mcpwp-action-log__td-code"><code><?php echo esc_html( (string) ( isset( $log_entry['tool_name'] ) ? $log_entry['tool_name'] : '' ) ); ?></code></td>
							<td class="mcpwp-action-log__td-sm"><?php echo esc_html( $le_resource ? $le_resource : '—' ); ?></td>
							<td class="mcpwp-action-log__td-sm">
								<?php echo esc_html( $le_ts ? human_time_diff( (int) strtotime( $le_ts ) ) . ' ago' : '—' ); ?>
							</td>
							<td class="mcpwp-action-log__td-sm"><?php echo esc_html( isset( $log_entry['duration_ms'] ) ? (string) $log_entry['duration_ms'] : '—' ); ?></td>
							<td>
								<?php if ( $le_success ) : ?>
									<span class="mcpwp-badge is-good"><?php esc_html_e( 'OK', 'mumcp' ); ?></span>
								<?php else : ?>
									<span class="mcpwp-badge is-critical"><?php echo esc_html( isset( $log_entry['error_code'] ) && $log_entry['error_code'] ? (string) $log_entry['error_code'] : 'error' ); ?></span>
								<?php endif; ?>
								<?php if ( $le_rolled ) : ?>
									<span class="mcpwp-badge is-info"><?php esc_html_e( 'Rolled back', 'mumcp' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $le_rollback && ! $le_rolled && $le_success ) : ?>
									<form method="post" class="mcpwp-inline-action">
										<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
										<input type="hidden" name="mcpwp_control_room_action" value="rollback_action_log" />
										<input type="hidden" name="action_log_id" value="<?php echo esc_attr( $le_log_id ); ?>" />
										<button type="submit" class="button button-small"
											onclick="return confirm('<?php esc_attr_e( 'Roll back this action? The before-state will be restored.', 'mumcp' ); ?>')">
											<?php esc_html_e( 'Rollback', 'mumcp' ); ?>
										</button>
									</form>
								<?php else : ?>
									<span class="mcpwp-action-log__muted">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

<?php // ── Site Signals (#363) ────────────────────────────────────────────────── ?>
<div class="mcpwp-card" id="mcpwp-section-signals" style="margin-top:1.5rem">
	<div class="mcpwp-control-card-header">
		<h2 class="mcpwp-control-card-title"><?php esc_html_e( 'Site Signals', 'mumcp' ); ?></h2>
		<div class="mcpwp-control-card-actions">
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
				<input type="hidden" name="mcpwp_control_room_action" value="refresh_signals" />
				<button type="submit" class="button button-small"><?php esc_html_e( 'Refresh Signals', 'mumcp' ); ?></button>
			</form>
		</div>
	</div>
	<?php if ( empty( $signals ) ) : ?>
		<p style="padding:1rem;color:#666"><?php esc_html_e( 'No signals found. Signals are computed daily via WP Cron — or click Refresh Signals to compute now.', 'mumcp' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="margin-top:.5rem">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Severity', 'mumcp' ); ?></th>
					<th><?php esc_html_e( 'Type', 'mumcp' ); ?></th>
					<th><?php esc_html_e( 'Entity', 'mumcp' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'mumcp' ); ?></th>
					<th><?php esc_html_e( 'Action Hint', 'mumcp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $signals as $signal ) :
					$sev        = esc_html( $signal['severity'] ?? 'low' );
					$sev_colors = array( 'high' => '#c0392b', 'medium' => '#e67e22', 'low' => '#7f8c8d' );
					$sev_color  = $sev_colors[ $signal['severity'] ?? 'low' ] ?? '#7f8c8d';
				?>
				<tr>
					<td><span style="background:<?php echo esc_attr( $sev_color ); ?>;color:#fff;padding:2px 7px;border-radius:3px;font-size:11px;text-transform:uppercase;display:inline-block"><?php echo $sev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $sev is pre-escaped with esc_html() at assignment ?></span></td>
					<td><code><?php echo esc_html( $signal['type'] ?? '' ); ?></code></td>
					<td>
						<?php if ( ! empty( $signal['entity_id'] ) ) : ?>
							<a href="<?php echo esc_url( get_edit_post_link( (int) $signal['entity_id'] ) ?? '#' ); ?>" target="_blank"><?php echo esc_html( $signal['entity_title'] ?? (string) $signal['entity_id'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $signal['entity_title'] ?? '—' ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $signal['detail'] ?? '' ); ?></td>
					<td style="color:#555;font-style:italic"><?php echo esc_html( $signal['action_hint'] ?? '' ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php if ( $memory_count > 0 ) : ?>
	<p style="margin:.75rem 0 0;color:#555;font-size:12px">
		<?php
		printf(
			/* translators: %1$d: entry count, %2$s: y or ies */
			esc_html__( 'Site memory: %1$d entr%2$s stored across AI sessions.', 'mumcp' ),
			(int) $memory_count,
			1 === $memory_count ? 'y' : 'ies'
		);
		?>
		&nbsp;<a href="<?php echo esc_url( rest_url( 'mcpwp/v1/memory' ) . '?_wpnonce=' . wp_create_nonce( 'wp_rest' ) ); ?>" target="_blank"><?php esc_html_e( 'View JSON', 'mumcp' ); ?></a>
	</p>
	<?php endif; ?>
</div>
