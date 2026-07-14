<?php if ( ! defined( 'ABSPATH' ) ) {
	exit; } ?>
<div class="wrap mcpwp-wrap">
	<h1><?php esc_html_e( 'Mumega MCP Setup & Keys', 'mumega-mcp' ); ?></h1>
	<p><?php esc_html_e( 'Connect an MCP client to the authenticated local WordPress endpoint.', 'mumega-mcp' ); ?></p>
	<code><?php echo esc_html( rest_url( 'mcpwp/v1/mcp' ) ); ?></code>
	<p><code>X-API-Key: YOUR_API_KEY</code></p>
	<?php
	if ( $notice ) :
		?>
		<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?>"><p><?php echo esc_html( $notice[1] ); ?></p></div><?php endif; ?>
	<?php
	if ( $new_key ) :
		?>
		<div class="notice notice-success"><p><?php esc_html_e( 'Copy this key now; it is shown once:', 'mumega-mcp' ); ?> <code><?php echo esc_html( $new_key ); ?></code></p></div><?php endif; ?>
	<form method="post">
		<?php wp_nonce_field( 'mcpwp_create_key' ); ?>
		<p><label><?php esc_html_e( 'Key label', 'mumega-mcp' ); ?> <input name="label" type="text" required maxlength="100"></label></p>
		<fieldset><legend><?php esc_html_e( 'Scopes', 'mumega-mcp' ); ?></legend>
			<label><input type="checkbox" name="scopes[]" value="read"> <?php esc_html_e( 'Read', 'mumega-mcp' ); ?></label>
			<label><input type="checkbox" name="scopes[]" value="write"> <?php esc_html_e( 'Write', 'mumega-mcp' ); ?></label>
			<label><input type="checkbox" name="scopes[]" value="admin"> <?php esc_html_e( 'Admin', 'mumega-mcp' ); ?></label>
		</fieldset>
		<fieldset><legend><?php esc_html_e( 'Tool categories', 'mumega-mcp' ); ?></legend>
			<label><input type="checkbox" name="tool_categories[]" value="site"> <?php esc_html_e( 'Site', 'mumega-mcp' ); ?></label>
			<label><input type="checkbox" name="tool_categories[]" value="content"> <?php esc_html_e( 'Content', 'mumega-mcp' ); ?></label>
			<label><input type="checkbox" name="tool_categories[]" value="media"> <?php esc_html_e( 'Media', 'mumega-mcp' ); ?></label>
			<label><input type="checkbox" name="tool_categories[]" value="taxonomy"> <?php esc_html_e( 'Taxonomy', 'mumega-mcp' ); ?></label>
		</fieldset>
		<p><button class="button button-primary" name="mcpwp_create_key" value="1"><?php esc_html_e( 'Create API Key', 'mumega-mcp' ); ?></button></p>
	</form>
	<h2><?php esc_html_e( 'Active API Keys', 'mumega-mcp' ); ?></h2>
	<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Label', 'mumega-mcp' ); ?></th><th><?php esc_html_e( 'WordPress user ID', 'mumega-mcp' ); ?></th><th><?php esc_html_e( 'Scopes', 'mumega-mcp' ); ?></th><th><?php esc_html_e( 'Tool categories', 'mumega-mcp' ); ?></th><th><?php esc_html_e( 'Created', 'mumega-mcp' ); ?></th><th><?php esc_html_e( 'Action', 'mumega-mcp' ); ?></th></tr></thead><tbody>
	<?php
	foreach ( $keys as $key ) :
		?>
		<tr><td><?php echo esc_html( $key['label'] ?: $key['id'] ); ?></td><td><?php echo esc_html( (string) $key['user_id'] ); ?></td><td><?php echo esc_html( implode( ', ', (array) $key['scopes'] ) ); ?></td><td><?php echo esc_html( implode( ', ', (array) $key['tool_categories'] ) ); ?></td><td><?php echo esc_html( $key['created_at'] ); ?></td><td><form method="post"><?php wp_nonce_field( 'mcpwp_revoke_key' ); ?><input type="hidden" name="key_id" value="<?php echo esc_attr( $key['id'] ); ?>"><button class="button button-link-delete" name="mcpwp_revoke_key" value="1"><?php esc_html_e( 'Revoke', 'mumega-mcp' ); ?></button></form></td></tr><?php endforeach; ?>
	</tbody></table>
</div>
