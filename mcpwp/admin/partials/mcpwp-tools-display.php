<?php
/**
 * MCP Tools admin page template.
 *
 * @package MCPWP
 * @since   1.1.2
 *
 * @var array $disabled_categories Currently disabled category slugs.
 * @var array $category_meta       Category display metadata.
 * @var array $tool_counts         Category slug => tool count.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap mcpwp-admin mcpwp-tools-page">
	<h1 class="mcpwp-header">
		<span class="mcpwp-logo">
			<span class="dashicons dashicons-admin-tools"></span>
		</span>
		<?php esc_html_e( 'Tools', 'mumcp' ); ?>
	</h1>
	<p class="description mcpwp-page-intro">
		<?php esc_html_e( 'Enable or disable tool categories exposed to AI assistants via MCP. Disabled categories are hidden from tools/list to reduce context noise.', 'mumcp' ); ?>
	</p>

	<div class="mcpwp-tools-grid">
		<?php foreach ( $category_meta as $slug => $meta ) :
			$is_disabled = in_array( $slug, $disabled_categories, true );
			$count       = isset( $tool_counts[ $slug ] ) ? (int) $tool_counts[ $slug ] : 0;
		?>
			<div class="mcpwp-tool-card <?php echo $is_disabled ? 'is-disabled' : 'is-enabled'; ?>" data-category="<?php echo esc_attr( $slug ); ?>">

				<div class="mcpwp-tool-card__header">
					<div class="mcpwp-tool-card__title">
						<span class="mcpwp-tool-card__icon dashicons <?php echo esc_attr( $meta['icon'] ); ?>"></span>
						<h3><?php echo esc_html( $meta['name'] ); ?></h3>
					</div>
					<label class="mcpwp-toggle">
						<?php
						/* translators: %s: tool category name */
						$toggle_label = sprintf( __( 'Toggle %s tools', 'mumcp' ), $meta['name'] );
						?>
						<input type="checkbox" class="mcpwp-category-toggle"
							data-category="<?php echo esc_attr( $slug ); ?>"
							<?php checked( ! $is_disabled ); ?>
							aria-label="<?php echo esc_attr( $toggle_label ); ?>" />
						<span class="mcpwp-toggle-track"></span>
						<span class="mcpwp-toggle-knob"></span>
					</label>
				</div>

				<p class="mcpwp-tool-card__description">
					<?php echo esc_html( $meta['description'] ); ?>
				</p>

				<span class="mcpwp-tool-card__count">
					<?php
						printf(
							/* translators: %d: number of tools */
							esc_html( _n( '%d tool', '%d tools', $count, 'mumcp' ) ),
							absint( $count )
						);
					?>
				</span>

				<span class="mcpwp-tool-status" hidden></span>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<!-- Tools toggle interactions handled by the enqueued mcpwp-admin.js (initToolsPage). -->
