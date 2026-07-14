<?php
/**
 * Settings-page markup.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} $settings_controller = isset( $settings_controller ) ? $settings_controller : new Mcpwp_Settings();
$settings              = array_merge( $settings_controller->get_defaults(), (array) get_option( 'mcpwp_settings', array() ) );
$limits                = array_merge( $settings_controller->get_rate_limit_defaults(), (array) get_option( 'mcpwp_rate_limit_settings', array() ) ); ?>
<div class="wrap mcpwp-wrap"><h1><?php esc_html_e( 'Mumega MCP Settings', 'mumega-mcp' ); ?></h1>
<?php
if ( isset( $save_result ) && is_wp_error( $save_result ) ) :
	?>
	<div class="notice notice-error"><p><?php echo esc_html( $save_result->get_error_message() ); ?></p></div>
	<?php
elseif ( ! empty( $save_result ) ) :
	?>
	<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'mumega-mcp' ); ?></p></div><?php endif; ?>
<form method="post"><?php wp_nonce_field( 'mcpwp_save_plugin_settings' ); ?><input type="hidden" name="mcpwp_save_settings" value="plugin">
<h2><?php esc_html_e( 'Local Activity', 'mumega-mcp' ); ?></h2>
<label><input type="checkbox" name="mcpwp_settings[enable_logging]" value="1" <?php checked( ! empty( $settings['enable_logging'] ) ); ?>> <?php esc_html_e( 'Enable local request logging', 'mumega-mcp' ); ?></label>
<p><label><?php esc_html_e( 'Retention days', 'mumega-mcp' ); ?> <input type="number" min="1" max="365" name="mcpwp_settings[log_retention_days]" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>"></label></p>
<?php submit_button(); ?></form>
<form method="post"><?php wp_nonce_field( 'mcpwp_save_rate_settings' ); ?><input type="hidden" name="mcpwp_save_settings" value="rate"><h2><?php esc_html_e( 'Rate Limits', 'mumega-mcp' ); ?></h2>
<label><input type="checkbox" name="mcpwp_rate_limit_settings[enabled]" value="1" <?php checked( ! empty( $limits['enabled'] ) ); ?>> <?php esc_html_e( 'Enable rate limiting', 'mumega-mcp' ); ?></label>
<p><label><?php esc_html_e( 'Requests per minute', 'mumega-mcp' ); ?> <input type="number" min="1" name="mcpwp_rate_limit_settings[requests_per_minute]" value="<?php echo esc_attr( $limits['requests_per_minute'] ); ?>"></label></p>
<p><label><?php esc_html_e( 'Requests per hour', 'mumega-mcp' ); ?> <input type="number" min="1" max="100000" name="mcpwp_rate_limit_settings[requests_per_hour]" value="<?php echo esc_attr( $limits['requests_per_hour'] ); ?>"></label></p>
<p><label><?php esc_html_e( 'Burst limit', 'mumega-mcp' ); ?> <input type="number" min="1" max="100000" name="mcpwp_rate_limit_settings[burst_limit]" value="<?php echo esc_attr( $limits['burst_limit'] ); ?>"></label></p>
<p><label><?php esc_html_e( 'Whitelisted identifiers', 'mumega-mcp' ); ?><br><textarea name="mcpwp_rate_limit_settings[whitelist]" rows="5" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $limits['whitelist'] ) ); ?></textarea></label></p>
<?php submit_button(); ?></form></div>
