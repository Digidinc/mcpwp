<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wrap mcpwp-wrap">
	<h1><?php esc_html_e( 'Mumega MCP Setup & Keys', 'mumega-mcp' ); ?></h1>
	<p><?php esc_html_e( 'Connect an MCP client to the authenticated local WordPress endpoint.', 'mumega-mcp' ); ?></p>
	<code><?php echo esc_html( rest_url( 'mcpwp/v1/mcp' ) ); ?></code>
	<p><code>X-API-Key: YOUR_API_KEY</code></p>
	<?php if ( $new_key ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Copy this key now; it is shown once:', 'mumega-mcp' ); ?> <code><?php echo esc_html( $new_key ); ?></code></p></div><?php endif; ?>
	<form method="post"><?php wp_nonce_field( 'mcpwp_create_key' ); ?><label><?php esc_html_e( 'Key label', 'mumega-mcp' ); ?> <input name="label" type="text" required></label> <button class="button button-primary" name="mcpwp_create_key" value="1"><?php esc_html_e( 'Create API Key', 'mumega-mcp' ); ?></button></form>
	<h2><?php esc_html_e( 'Active API Keys', 'mumega-mcp' ); ?></h2>
	<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Label', 'mumega-mcp' ); ?></th><th><?php esc_html_e( 'Created', 'mumega-mcp' ); ?></th></tr></thead><tbody><?php foreach ( $keys as $key ) : ?><tr><td><?php echo esc_html( $key['label'] ?: $key['id'] ); ?></td><td><?php echo esc_html( $key['created_at'] ); ?></td></tr><?php endforeach; ?></tbody></table>
</div>
