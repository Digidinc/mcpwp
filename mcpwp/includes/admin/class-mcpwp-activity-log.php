<?php
/**
 * Local activity-log administration.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Render the local activity-log administration page.
 */
class Mcpwp_Activity_Log_Page {
	/**
	 * Render a paginated table of local activity metadata.
	 *
	 * @return void
	 */
	public function render() {
		global $wpdb;
		$table    = $wpdb->prefix . 'mcpwp_activity_log';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 50;
		$offset   = ( $paged - 1 ) * $per_page;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows     = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, action, status_code, duration_ms, api_key_id, created_at FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Mumega MCP Activity', 'mumega-mcp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Local MCP tool activity metadata only; arguments and content are never stored.', 'mumega-mcp' ) . '</p>';
		echo '<table class="widefat fixed striped"><thead><tr>';
		foreach ( array( __( 'When', 'mumega-mcp' ), __( 'Tool', 'mumega-mcp' ), __( 'Status', 'mumega-mcp' ), __( 'Duration (ms)', 'mumega-mcp' ), __( 'API key ID', 'mumega-mcp' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>'; }
		echo '</tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No activity found.', 'mumega-mcp' ) . '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				echo '<tr><td>' . esc_html( (string) $row['created_at'] ) . '</td><td><code>' . esc_html( (string) $row['action'] ) . '</code></td><td>' . esc_html( (string) $row['status_code'] ) . '</td><td>' . esc_html( (string) $row['duration_ms'] ) . '</td><td><code>' . esc_html( (string) $row['api_key_id'] ) . '</code></td></tr>';
			}
		}
		echo '</tbody></table>';
		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) {
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => esc_url_raw(
							add_query_arg(
								array(
									'page'  => Mcpwp_Admin::ACTIVITY_LOG_PAGE_SLUG,
									'paged' => '%#%',
								),
								admin_url( 'admin.php' )
							)
						),
						'total'   => $total_pages,
						'current' => $paged,
					)
				)
			);
		}
		echo '</div>';
	}
}
