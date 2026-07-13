<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $settings = array_merge( ( new Mcpwp_Settings() )->get_defaults(), (array) get_option( 'mcpwp_settings', array() ) ); $limits = array_merge( ( new Mcpwp_Settings() )->get_rate_limit_defaults(), (array) get_option( 'mcpwp_rate_limit_settings', array() ) ); ?>
<div class="wrap mcpwp-wrap"><h1><?php esc_html_e( 'Mumega MCP Settings', 'mumega-mcp' ); ?></h1>
<form method="post" action="options.php"><?php settings_fields( 'mcpwp_settings_group' ); ?>
<h2><?php esc_html_e( 'Local Activity', 'mumega-mcp' ); ?></h2>
<label><input type="checkbox" name="mcpwp_settings[enable_logging]" value="1" <?php checked( ! empty( $settings['enable_logging'] ) ); ?>> <?php esc_html_e( 'Enable local request logging', 'mumega-mcp' ); ?></label>
<p><label><?php esc_html_e( 'Retention days', 'mumega-mcp' ); ?> <input type="number" min="1" max="365" name="mcpwp_settings[log_retention_days]" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>"></label></p>
<label><input type="checkbox" name="mcpwp_settings[log_store_response_data]" value="1" <?php checked( ! empty( $settings['log_store_response_data'] ) ); ?>> <?php esc_html_e( 'Store redacted response data', 'mumega-mcp' ); ?></label>
<?php submit_button(); ?></form>
<form method="post" action="options.php"><?php settings_fields( 'mcpwp_rate_limit_group' ); ?><h2><?php esc_html_e( 'Rate Limits', 'mumega-mcp' ); ?></h2>
<label><input type="checkbox" name="mcpwp_rate_limit_settings[enabled]" value="1" <?php checked( ! empty( $limits['enabled'] ) ); ?>> <?php esc_html_e( 'Enable rate limiting', 'mumega-mcp' ); ?></label>
<p><label><?php esc_html_e( 'Requests per minute', 'mumega-mcp' ); ?> <input type="number" min="1" name="mcpwp_rate_limit_settings[requests_per_minute]" value="<?php echo esc_attr( $limits['requests_per_minute'] ); ?>"></label></p>
<?php submit_button(); ?></form></div>
