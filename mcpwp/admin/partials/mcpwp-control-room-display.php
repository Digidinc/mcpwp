<?php
/**
 * Human control room template.
 *
 * Variables available from render_control_room_page():
 *   $control_room - array summarized approval, SEO, and activity data.
 *
 * @package MCPWP
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
$attention_items = isset( $control_room['attention_approvals'] ) && is_array( $control_room['attention_approvals'] ) ? $control_room['attention_approvals'] : array();
$seo_issues      = isset( $control_room['open_seo_issues'] ) && is_array( $control_room['open_seo_issues'] ) ? $control_room['open_seo_issues'] : array();
$seo_visibility    = isset( $control_room['seo_visibility'] ) && is_array( $control_room['seo_visibility'] ) ? $control_room['seo_visibility'] : array();
$seo_opportunities = isset( $control_room['seo_opportunities'] ) && is_array( $control_room['seo_opportunities'] ) ? $control_room['seo_opportunities'] : array();
$seo_filters     = isset( $control_room['seo_filters'] ) && is_array( $control_room['seo_filters'] ) ? $control_room['seo_filters'] : array();
$event_inbox     = isset( $control_room['event_inbox'] ) && is_array( $control_room['event_inbox'] ) ? $control_room['event_inbox'] : array();
$event_filters   = isset( $control_room['event_filters'] ) && is_array( $control_room['event_filters'] ) ? $control_room['event_filters'] : array();
$activity_rows   = isset( $control_room['recent_activity'] ) && is_array( $control_room['recent_activity'] ) ? $control_room['recent_activity'] : array();
$recommendations = isset( $control_room['recommendations'] ) && is_array( $control_room['recommendations'] ) ? $control_room['recommendations'] : array();
$event_summary   = isset( $event_inbox['summary'] ) && is_array( $event_inbox['summary'] ) ? $event_inbox['summary'] : array();
$event_items     = isset( $event_inbox['events'] ) && is_array( $event_inbox['events'] ) ? $event_inbox['events'] : array();
$signals         = isset( $control_room['signals'] ) && is_array( $control_room['signals'] ) ? $control_room['signals'] : array();
$memory_count    = (int) ( $control_room['memory_count'] ?? 0 );
$mupot = isset( $control_room['mupot'] ) && is_array( $control_room['mupot'] ) ? $control_room['mupot'] : array();

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
		<?php esc_html_e( 'Control Room', 'mcpwp' ); ?>
		<span class="mcpwp-version">v<?php echo esc_html( MCPWP_VERSION ); ?></span>
	</h1>

	<p class="description">
		<?php esc_html_e( 'Review agent work, SEO findings, recent activity, and rollback-ready changes from one supervised WordPress screen.', 'mcpwp' ); ?>
	</p>

	<?php settings_errors( 'mcpwp_messages' ); ?>

	<div class="mcpwp-control-summary">
		<div class="mcpwp-library-stat mcpwp-control-stat <?php echo esc_attr( $pending_count > 0 ? 'is-warning' : 'is-good' ); ?>">
			<span class="mcpwp-control-stat__icon dashicons <?php echo esc_attr( $pending_count > 0 ? 'dashicons-clock' : 'dashicons-yes-alt' ); ?>"></span>
			<span class="mcpwp-library-stat__value"><?php echo esc_html( (string) $pending_count ); ?></span>
			<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Pending approvals', 'mcpwp' ); ?></span>
		</div>
		<div class="mcpwp-library-stat mcpwp-control-stat <?php echo esc_attr( $applied_count > 0 ? 'is-info' : 'is-good' ); ?>">
			<span class="mcpwp-control-stat__icon dashicons <?php echo esc_attr( $applied_count > 0 ? 'dashicons-backup' : 'dashicons-shield' ); ?>"></span>
			<span class="mcpwp-library-stat__value"><?php echo esc_html( (string) $applied_count ); ?></span>
			<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Rollback-ready changes', 'mcpwp' ); ?></span>
		</div>
		<div class="mcpwp-library-stat mcpwp-control-stat <?php echo esc_attr( $seo_open_count > 0 ? 'is-warning' : 'is-good' ); ?>">
			<span class="mcpwp-control-stat__icon dashicons <?php echo esc_attr( $seo_open_count > 0 ? 'dashicons-search' : 'dashicons-chart-line' ); ?>"></span>
			<span class="mcpwp-library-stat__value"><?php echo esc_html( (string) $seo_open_count ); ?></span>
			<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Open SEO issues', 'mcpwp' ); ?></span>
		</div>
		<div class="mcpwp-library-stat mcpwp-control-stat <?php echo esc_attr( $seo_error_count > 0 ? 'is-critical' : 'is-good' ); ?>">
			<span class="mcpwp-control-stat__icon dashicons <?php echo esc_attr( $seo_error_count > 0 ? 'dashicons-warning' : 'dashicons-yes' ); ?>"></span>
			<span class="mcpwp-library-stat__value"><?php echo esc_html( (string) $seo_error_count ); ?></span>
			<span class="mcpwp-library-stat__label"><?php esc_html_e( 'SEO errors', 'mcpwp' ); ?></span>
		</div>
		<div class="mcpwp-library-stat mcpwp-control-stat <?php echo esc_attr( $event_escalated_count > 0 ? 'is-critical' : 'is-good' ); ?>">
			<span class="mcpwp-control-stat__icon dashicons <?php echo esc_attr( $event_escalated_count > 0 ? 'dashicons-bell' : 'dashicons-yes-alt' ); ?>"></span>
			<span class="mcpwp-library-stat__value"><?php echo esc_html( (string) $event_escalated_count ); ?></span>
			<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Escalated events', 'mcpwp' ); ?></span>
		</div>
	</div>

	<!-- F-07: in-page anchor nav strip -->
	<nav class="mcpwp-anchor-nav nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Jump to section', 'mcpwp' ); ?>">
		<a class="nav-tab" href="#mcpwp-section-approvals"><?php esc_html_e( 'Approvals', 'mcpwp' ); ?></a>
		<a class="nav-tab" href="#mcpwp-section-seo"><?php esc_html_e( 'SEO', 'mcpwp' ); ?></a>
		<a class="nav-tab" href="#mcpwp-section-events"><?php esc_html_e( 'Events', 'mcpwp' ); ?></a>
		<a class="nav-tab" href="#mcpwp-section-activity"><?php esc_html_e( 'Activity', 'mcpwp' ); ?></a>
		<a class="nav-tab" href="#mcpwp-section-signals"><?php esc_html_e( 'Signals', 'mcpwp' ); ?></a>
		<?php if ( ! empty( $mupot['enabled'] ) ) : ?>
		<a class="nav-tab" href="#mcpwp-section-mupot"><?php esc_html_e( 'My mupot', 'mcpwp' ); ?></a>
		<?php endif; ?>
	</nav>

	<div class="mcpwp-control-grid">
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Recommended Next Actions', 'mcpwp' ); ?>
			</h2>
			<?php if ( empty( $recommendations ) ) : ?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-yes-alt"></span>
					<p><?php esc_html_e( 'No actions needed right now — your site is in good shape.', 'mcpwp' ); ?></p>
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
				<?php esc_html_e( 'Pending Approvals', 'mcpwp' ); ?>
			</h2>
			<?php if ( empty( $pending_items ) ) : ?>
				<div class="mcpwp-control-empty is-good">
					<span class="dashicons dashicons-yes-alt"></span>
					<p><?php esc_html_e( 'No pending approval requests.', 'mcpwp' ); ?></p>
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
						$item_diff    = isset( $item['diff'] ) && is_array( $item['diff'] ) ? $item['diff'] : array();
						$diff_before  = isset( $item_diff['before_excerpt'] ) ? (string) $item_diff['before_excerpt'] : '';
						$diff_after   = isset( $item_diff['after_excerpt'] ) ? (string) $item_diff['after_excerpt'] : '';
						$short_desc   = ! empty( $item['description'] ) ? $item['description'] : ( ! empty( $item['detail'] ) ? $item['detail'] : '' );
						$short_desc   = $short_desc ? wp_html_excerpt( $short_desc, 120, '…' ) : '';
						// Independent re-validation before render: never trust a stored
						// `triage` value at face value, even though set_triage() already
						// validated it on write. Jev's answer summarized untrusted site
						// content, so an enum that doesn't match is dropped here too
						// rather than displayed — this is a display label, nothing more,
						// and it never changes what Approve/Reject/Apply do.
						$triage_raw      = isset( $item['triage'] ) && is_array( $item['triage'] ) ? $item['triage'] : array();
						$triage_priority = isset( $triage_raw['priority'] ) && in_array( $triage_raw['priority'], Mcpwp_Approvals::TRIAGE_PRIORITIES, true )
							? (string) $triage_raw['priority']
							: '';
						$triage_kind     = isset( $triage_raw['change_kind'] ) && in_array( $triage_raw['change_kind'], Mcpwp_Approvals::TRIAGE_CHANGE_KINDS, true )
							? (string) $triage_raw['change_kind']
							: '';
						?>
						<li class="mcpwp-control-list__item is-warning">
							<span class="mcpwp-control-list__icon dashicons dashicons-clock"></span>
							<?php if ( '' !== $triage_priority ) : ?>
								<span
									class="mcpwp-triage-chip mcpwp-triage-chip--<?php echo esc_attr( $triage_priority ); ?>"
									title="<?php echo esc_attr( sprintf(
										/* translators: 1: priority label, 2: change-kind label */
										__( 'Jev triage (display only): %1$s priority, %2$s', 'mcpwp' ),
										$triage_priority,
										'' !== $triage_kind ? $triage_kind : 'other'
									) ); ?>"
								><?php echo esc_html( ucfirst( $triage_priority ) ); ?></span>
							<?php endif; ?>
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
							<?php
							// A destructive record replays a stored call. The reviewer
							// consents to THAT call, so it is shown verbatim next to the
							// summary (#536 round 2, P0-2).
							$item_payload = isset( $item['payload'] ) && is_array( $item['payload'] ) ? $item['payload'] : array();
							if ( isset( $item['action'] ) && 'destructive_operation' === $item['action'] && ! empty( $item_payload['route'] ) ) :
								?>
								<details class="mcpwp-approval-diff" open>
									<summary><?php esc_html_e( 'The exact call this approval will run', 'mcpwp' ); ?></summary>
									<pre><?php echo esc_html( ( isset( $item_payload['method'] ) ? (string) $item_payload['method'] : 'DELETE' ) . ' ' . (string) $item_payload['route'] ); ?></pre>
									<pre><?php echo esc_html( (string) wp_json_encode( isset( $item_payload['params'] ) ? $item_payload['params'] : array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
									<p><?php echo esc_html( sprintf( /* translators: %s: operation */ __( 'Operation: %s. Only this operation\'s own route can be applied; a mismatch is refused at apply.', 'mcpwp' ), isset( $item['operation'] ) ? (string) $item['operation'] : '' ) ); ?></p>
								</details>
							<?php endif; ?>
							<?php if ( '' !== $diff_before || '' !== $diff_after ) : ?>
								<details class="mcpwp-approval-diff">
									<summary><?php esc_html_e( 'View before / after', 'mcpwp' ); ?></summary>
									<div class="mcpwp-approval-diff__grid">
										<div class="mcpwp-approval-diff__pane mcpwp-approval-diff__pane--before">
											<span class="mcpwp-approval-diff__label"><?php esc_html_e( 'Before', 'mcpwp' ); ?></span>
											<pre><?php echo esc_html( $diff_before ); ?></pre>
										</div>
										<div class="mcpwp-approval-diff__pane mcpwp-approval-diff__pane--after">
											<span class="mcpwp-approval-diff__label"><?php esc_html_e( 'After', 'mcpwp' ); ?></span>
											<pre><?php echo esc_html( $diff_after ); ?></pre>
										</div>
									</div>
								</details>
							<?php endif; ?>
							<div class="mcpwp-control-actions">
								<form method="post">
									<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
									<input type="hidden" name="mcpwp_control_room_action" value="approve">
									<input type="hidden" name="mcpwp_approval_id" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
									<button type="submit" class="button button-primary"
										onclick="return confirm('<?php esc_attr_e( 'Apply this change to the live site? You can roll it back from the Rollback Ready section.', 'mcpwp' ); ?>')"
									><?php esc_html_e( 'Approve', 'mcpwp' ); ?></button>
								</form>
								<form method="post">
									<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
									<input type="hidden" name="mcpwp_control_room_action" value="reject">
									<input type="hidden" name="mcpwp_approval_id" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Reject', 'mcpwp' ); ?></button>
								</form>
								<?php if ( $edit_url ) : ?>
									<a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Preview in WordPress', 'mcpwp' ); ?></a>
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
				<?php esc_html_e( 'Approved Changes', 'mcpwp' ); ?>
			</h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of approved approvals */
					esc_html( _n( '%d approved change is ready to apply.', '%d approved changes are ready to apply.', $approved_count, 'mcpwp' ) ),
					esc_html( (string) $approved_count )
				);
				?>
			</p>
			<?php if ( empty( $approved_items ) ) : ?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-shield"></span>
					<p><?php esc_html_e( 'No approved approval requests are waiting to apply.', 'mcpwp' ); ?></p>
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
										onclick="return confirm('<?php esc_attr_e( 'Apply this change to the live site? You can roll it back from the Rollback Ready section.', 'mcpwp' ); ?>')"
									><?php esc_html_e( 'Apply', 'mcpwp' ); ?></button>
								</form>
								<form method="post">
									<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
									<input type="hidden" name="mcpwp_control_room_action" value="reject">
									<input type="hidden" name="mcpwp_approval_id" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Reject', 'mcpwp' ); ?></button>
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
					<?php esc_html_e( 'Stored SEO Issues', 'mcpwp' ); ?>
				</h2>
				<form method="post">
					<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
					<input type="hidden" name="mcpwp_control_room_action" value="run_seo_audit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Run SEO Audit', 'mcpwp' ); ?></button>
				</form>
			</div>
			<form method="get" class="mcpwp-control-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Mcpwp_Admin::CONTROL_ROOM_PAGE_SLUG ); ?>">
				<label>
					<span><?php esc_html_e( 'Status', 'mcpwp' ); ?></span>
					<select name="mcpwp_seo_status">
						<option value="open" <?php selected( $seo_filters['status'] ?? 'open', 'open' ); ?>><?php esc_html_e( 'Open', 'mcpwp' ); ?></option>
						<option value="resolved" <?php selected( $seo_filters['status'] ?? '', 'resolved' ); ?>><?php esc_html_e( 'Resolved', 'mcpwp' ); ?></option>
						<option value="" <?php selected( $seo_filters['status'] ?? '', '' ); ?>><?php esc_html_e( 'Any', 'mcpwp' ); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Severity', 'mcpwp' ); ?></span>
					<select name="mcpwp_seo_severity">
						<option value="" <?php selected( $seo_filters['severity'] ?? '', '' ); ?>><?php esc_html_e( 'Any', 'mcpwp' ); ?></option>
						<option value="error" <?php selected( $seo_filters['severity'] ?? '', 'error' ); ?>><?php esc_html_e( 'Error', 'mcpwp' ); ?></option>
						<option value="warning" <?php selected( $seo_filters['severity'] ?? '', 'warning' ); ?>><?php esc_html_e( 'Warning', 'mcpwp' ); ?></option>
						<option value="info" <?php selected( $seo_filters['severity'] ?? '', 'info' ); ?>><?php esc_html_e( 'Info', 'mcpwp' ); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Category', 'mcpwp' ); ?></span>
					<select name="mcpwp_seo_category">
						<option value="" <?php selected( $seo_filters['category'] ?? '', '' ); ?>><?php esc_html_e( 'Any', 'mcpwp' ); ?></option>
						<option value="readiness" <?php selected( $seo_filters['category'] ?? '', 'readiness' ); ?>><?php esc_html_e( 'Readiness', 'mcpwp' ); ?></option>
						<option value="structured_data" <?php selected( $seo_filters['category'] ?? '', 'structured_data' ); ?>><?php esc_html_e( 'Structured data', 'mcpwp' ); ?></option>
						<option value="media" <?php selected( $seo_filters['category'] ?? '', 'media' ); ?>><?php esc_html_e( 'Media', 'mcpwp' ); ?></option>
						<option value="content_quality" <?php selected( $seo_filters['category'] ?? '', 'content_quality' ); ?>><?php esc_html_e( 'Content quality', 'mcpwp' ); ?></option>
					</select>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'mcpwp' ); ?></button>
			</form>
			<?php if ( empty( $seo_issues ) ) : ?>
				<div class="mcpwp-control-empty is-good">
					<span class="dashicons dashicons-chart-line"></span>
					<p><?php esc_html_e( 'No stored open SEO issues. Run a site SEO audit with store=true to populate this panel.', 'mcpwp' ); ?></p>
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
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $issue['title'] ?? __( 'Open post', 'mcpwp' ) ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Search opportunities', 'mcpwp' ); ?></h3>
			<?php if ( ! empty( $seo_visibility['captured_at'] ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: target domain the snapshot describes, 2: human-readable time since capture */
						esc_html__( 'Visibility snapshot for %1$s — captured %2$s ago.', 'mcpwp' ),
						esc_html( (string) ( $seo_visibility['target'] ?? '' ) ),
						esc_html( human_time_diff( (int) $seo_visibility['captured_at'] ) )
					);
					?>
				</p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No visibility snapshot has been fetched yet.', 'mcpwp' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $seo_opportunities['opportunities'] ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Keyword', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Position', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Volume', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Difficulty', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Why', 'mcpwp' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $seo_opportunities['opportunities'] as $opp ) : ?>
						<tr>
							<td><?php echo esc_html( $opp['keyword'] ?? '' ); ?></td>
							<td><?php echo isset( $opp['position'] ) && $opp['position'] > 0 ? esc_html( (string) $opp['position'] ) : '&mdash;'; ?></td>
							<td><?php echo esc_html( (string) ( $opp['search_volume'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $opp['keyword_difficulty'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( $opp['reason'] ?? '' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p>
					<?php
					// "Not configured" and "nothing to do" are different answers
					// and must not render as the same empty table.
					$seo_opportunities_reason = isset( $seo_opportunities['reason'] ) ? (string) $seo_opportunities['reason'] : '';
					if ( 'no_inputs' === $seo_opportunities_reason ) {
						esc_html_e( 'No search data yet. Connect DataForSEO under Integrations, then refresh visibility.', 'mcpwp' );
					} elseif ( 'no_rankings_needs_seed' === $seo_opportunities_reason ) {
						esc_html_e( 'This site does not rank for anything yet. Add a seed keyword to find terms worth targeting.', 'mcpwp' );
					} elseif ( 'seed_no_suggestions' === $seo_opportunities_reason ) {
						esc_html_e( 'No suggestions were found for that seed keyword. Try a different seed.', 'mcpwp' );
					} elseif ( 'lookup_budget_exhausted' === $seo_opportunities_reason ) {
						esc_html_e( 'The daily budget for paid keyword lookups on this site has been reached. Try again later.', 'mcpwp' );
					} elseif ( 'unavailable' === $seo_opportunities_reason ) {
						esc_html_e( 'Search opportunity analysis is not available in this build.', 'mcpwp' );
					} else {
						esc_html_e( 'No opportunities outstanding.', 'mcpwp' );
					}
					?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $attention_items ) ) : ?>
			<div class="mcpwp-card">
				<h2>
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Approvals Needing Attention', 'mcpwp' ); ?>
				</h2>
				<p class="description"><?php esc_html_e( 'These changes ran, or started to, but their approval record could not be completed. They are never re-applied automatically. For one stuck in progress, check the post and record what actually happened: "It completed" marks it applied (or rolled back), and rollback then works; "It did not complete" puts it back for review. A partially applied change can be rolled back from Rollback Ready.', 'mcpwp' ); ?></p>
				<ul class="mcpwp-control-list">
					<?php foreach ( $attention_items as $item ) : ?>
						<li class="mcpwp-control-list__item is-warning">
							<span class="mcpwp-control-list__icon dashicons dashicons-warning"></span>
							<strong><?php echo esc_html( $item['title'] ?? $item['id'] ?? '' ); ?></strong>
							<span><?php echo esc_html( ( $item['status'] ?? '' ) . ' — ' . ( $item['needs_attention'] ?? '' ) ); ?></span>
							<?php if ( in_array( $item['status'] ?? '', array( 'applying', 'rolling_back' ), true ) ) : ?>
								<div class="mcpwp-control-actions">
									<?php foreach ( array( 'resolve_completed' => __( 'It completed', 'mcpwp' ), 'resolve_not_completed' => __( 'It did not complete', 'mcpwp' ) ) as $resolve_action => $resolve_label ) : ?>
										<form method="post">
											<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
											<input type="hidden" name="mcpwp_control_room_action" value="<?php echo esc_attr( $resolve_action ); ?>">
											<input type="hidden" name="mcpwp_approval_id" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
											<button type="submit" class="button"><?php echo esc_html( $resolve_label ); ?></button>
										</form>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-backup"></span>
				<?php esc_html_e( 'Rollback Ready', 'mcpwp' ); ?>
			</h2>
			<form method="post" class="mcpwp-control-actions">
				<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
				<input type="hidden" name="mcpwp_control_room_action" value="purge_closed_approvals">
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Purge closed approval requests', 'mcpwp' ); ?></button>
			</form>
			<?php if ( empty( $rollback_items ) ) : ?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-shield"></span>
					<p><?php esc_html_e( 'No applied approval requests are currently listed for rollback.', 'mcpwp' ); ?></p>
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
									<button type="submit" class="button"><?php esc_html_e( 'Rollback', 'mcpwp' ); ?></button>
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
				<?php esc_html_e( 'Event Inbox', 'mcpwp' ); ?>
			</h2>
			<span class="mcpwp-control-count">
				<?php
				printf(
					/* translators: %d: number of visible events */
					esc_html( _n( '%d event', '%d events', (int) ( $event_summary['total'] ?? 0 ), 'mcpwp' ) ),
					esc_html( (string) ( $event_summary['total'] ?? 0 ) )
				);
				?>
			</span>
		</div>
		<form method="get" class="mcpwp-control-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Mcpwp_Admin::CONTROL_ROOM_PAGE_SLUG ); ?>">
			<label>
				<span><?php esc_html_e( 'Event type', 'mcpwp' ); ?></span>
				<input type="text" name="mcpwp_event_type" value="<?php echo esc_attr( $event_filters['type'] ?? '' ); ?>" placeholder="approval.created">
			</label>
			<label>
				<span><?php esc_html_e( 'Risk', 'mcpwp' ); ?></span>
				<select name="mcpwp_event_risk">
					<option value="" <?php selected( $event_filters['risk_level'] ?? '', '' ); ?>><?php esc_html_e( 'Any', 'mcpwp' ); ?></option>
					<option value="high" <?php selected( $event_filters['risk_level'] ?? '', 'high' ); ?>><?php esc_html_e( 'High', 'mcpwp' ); ?></option>
					<option value="medium" <?php selected( $event_filters['risk_level'] ?? '', 'medium' ); ?>><?php esc_html_e( 'Medium', 'mcpwp' ); ?></option>
					<option value="low" <?php selected( $event_filters['risk_level'] ?? '', 'low' ); ?>><?php esc_html_e( 'Low', 'mcpwp' ); ?></option>
				</select>
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'mcpwp' ); ?></button>
		</form>
		<?php if ( empty( $event_items ) ) : ?>
			<div class="mcpwp-control-empty is-muted">
				<span class="dashicons dashicons-bell"></span>
				<p><?php esc_html_e( 'No normalized events match the current filters yet.', 'mcpwp' ); ?></p>
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
			<?php esc_html_e( 'Recent Agent Activity', 'mcpwp' ); ?>
		</h2>
		<?php if ( empty( $activity_rows ) ) : ?>
			<div class="mcpwp-control-empty is-muted">
				<span class="dashicons dashicons-list-view"></span>
				<p><?php esc_html_e( 'No activity recorded yet.', 'mcpwp' ); ?></p>
			</div>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Action', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcpwp' ); ?></th>
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
				<?php esc_html_e( 'AI Action Log', 'mcpwp' ); ?>
			</h2>
			<a href="<?php echo esc_url( rest_url( 'mcpwp/v1/action-log/export' ) . '?_wpnonce=' . wp_create_nonce( 'wp_rest' ) ); ?>"
			   class="button button-small" target="_blank">
				<?php esc_html_e( 'Export CSV', 'mcpwp' ); ?>
			</a>
		</div>

		<?php if ( empty( $action_log_entries ) ) : ?>
			<div class="mcpwp-control-empty is-muted mcpwp-action-log__empty">
				<span class="dashicons dashicons-backup"></span>
				<p><?php esc_html_e( 'No write-tool actions logged yet. Actions appear here the first time an AI agent calls a write tool (update, create, delete, set, etc.).', 'mcpwp' ); ?></p>
			</div>
		<?php else : ?>
			<p class="description mcpwp-action-log__count">
				<?php
				printf(
					/* translators: 1: shown count, 2: total */
					esc_html__( 'Showing %1$d of %2$d logged actions (most recent first).', 'mcpwp' ),
					(int) count( $action_log_entries ),
					(int) $action_log_total
				);
				?>
			</p>
			<table class="widefat striped mcpwp-action-log__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tool', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Resource', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Time', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'ms', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Result', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Action', 'mcpwp' ); ?></th>
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
									<span class="mcpwp-badge is-good"><?php esc_html_e( 'OK', 'mcpwp' ); ?></span>
								<?php else : ?>
									<span class="mcpwp-badge is-critical"><?php echo esc_html( isset( $log_entry['error_code'] ) && $log_entry['error_code'] ? (string) $log_entry['error_code'] : 'error' ); ?></span>
								<?php endif; ?>
								<?php if ( $le_rolled ) : ?>
									<span class="mcpwp-badge is-info"><?php esc_html_e( 'Rolled back', 'mcpwp' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $le_rollback && ! $le_rolled && $le_success ) : ?>
									<form method="post" class="mcpwp-inline-action">
										<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
										<input type="hidden" name="mcpwp_control_room_action" value="rollback_action_log" />
										<input type="hidden" name="action_log_id" value="<?php echo esc_attr( $le_log_id ); ?>" />
										<button type="submit" class="button button-small"
											onclick="return confirm('<?php esc_attr_e( 'Roll back this action? The before-state will be restored.', 'mcpwp' ); ?>')">
											<?php esc_html_e( 'Rollback', 'mcpwp' ); ?>
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
		<h2 class="mcpwp-control-card-title"><?php esc_html_e( 'Site Signals', 'mcpwp' ); ?></h2>
		<div class="mcpwp-control-card-actions">
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'mcpwp_control_room_actions', 'mcpwp_control_room_nonce' ); ?>
				<input type="hidden" name="mcpwp_control_room_action" value="refresh_signals" />
				<button type="submit" class="button button-small"><?php esc_html_e( 'Refresh Signals', 'mcpwp' ); ?></button>
			</form>
		</div>
	</div>
	<?php if ( empty( $signals ) ) : ?>
		<p style="padding:1rem;color:#666"><?php esc_html_e( 'No signals found. Signals are computed daily via WP Cron — or click Refresh Signals to compute now.', 'mcpwp' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="margin-top:.5rem">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Severity', 'mcpwp' ); ?></th>
					<th><?php esc_html_e( 'Type', 'mcpwp' ); ?></th>
					<th><?php esc_html_e( 'Entity', 'mcpwp' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'mcpwp' ); ?></th>
					<th><?php esc_html_e( 'Action Hint', 'mcpwp' ); ?></th>
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
			esc_html__( 'Site memory: %1$d entr%2$s stored across AI sessions.', 'mcpwp' ),
			(int) $memory_count,
			1 === $memory_count ? 'y' : 'ies'
		);
		?>
		&nbsp;<a href="<?php echo esc_url( rest_url( 'mcpwp/v1/memory' ) . '?_wpnonce=' . wp_create_nonce( 'wp_rest' ) ); ?>" target="_blank"><?php esc_html_e( 'View JSON', 'mcpwp' ); ?></a>
	</p>
	<?php endif; ?>
</div>

<?php if ( ! empty( $mupot['enabled'] ) ) : ?>
<div class="mcpwp-card" id="mcpwp-section-mupot" style="margin-top:1.5rem">
	<div class="mcpwp-control-card-header">
		<h2 class="mcpwp-control-card-title"><?php esc_html_e( 'My mupot', 'mcpwp' ); ?></h2>
	</div>
	<?php if ( empty( $mupot['connected'] ) ) : ?>
		<p style="padding:1rem;color:#666">
			<?php
			printf(
				/* translators: %s: link to the user's own Profile screen */
				wp_kses(
					__( 'Not connected. <a href="%s">Add your mupot member token in your profile</a> to see your task board here.', 'mcpwp' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( admin_url( 'profile.php' ) )
			);
			?>
		</p>
	<?php elseif ( ! empty( $mupot['error'] ) ) : ?>
		<p style="padding:1rem;color:#c0392b"><?php echo esc_html( $mupot['error'] ); ?></p>
	<?php else : ?>
		<?php if ( is_array( $mupot['status'] ) ) : ?>
			<div style="padding:0 1rem 1rem">
				<strong><?php esc_html_e( 'Status', 'mcpwp' ); ?>:</strong>
				<code><?php echo esc_html( wp_json_encode( $mupot['status'] ) ); ?></code>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $mupot['board_error'] ) ) : ?>
			<p style="padding:0 1rem 1rem;color:#c0392b"><?php echo esc_html( $mupot['board_error'] ); ?></p>
		<?php elseif ( empty( $mupot['has_squad'] ) ) : ?>
			<p style="padding:0 1rem 1rem;color:#666">
				<?php
				printf(
					wp_kses(
						__( 'Add your mupot squad id in <a href="%s">your profile</a> to see your task board.', 'mcpwp' ),
						array( 'a' => array( 'href' => array() ) )
					),
					esc_url( admin_url( 'profile.php' ) )
				);
				?>
			</p>
		<?php elseif ( is_array( $mupot['task_board'] ) ) : ?>
			<div style="padding:0 1rem 1rem">
				<strong><?php esc_html_e( 'Task Board', 'mcpwp' ); ?>:</strong>
				<code><?php echo esc_html( wp_json_encode( $mupot['task_board'] ) ); ?></code>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php endif; ?>
