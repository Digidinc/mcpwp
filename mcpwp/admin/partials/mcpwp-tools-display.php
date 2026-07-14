<?php
/**
 * Retained MCP tool-inventory markup.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; } ?>
<div class="wrap mcpwp-wrap"><h1><?php esc_html_e( 'MCP Tool Inventory', 'mumega-mcp' ); ?></h1><p><?php esc_html_e( 'This inventory is generated from the active free registry.', 'mumega-mcp' ); ?></p>
<?php
foreach ( $categories as $slug => $category ) :
	?>
	<section><h2><?php echo esc_html( $category['name'] ); ?></h2><p><?php echo esc_html( $category['description'] ); ?></p><ul>
	<?php
	foreach ( $tools as $tool ) :
		if ( ( $tool['annotations']['category'] ?? '' ) !== $slug ) {
			continue; }
		?>
	<li><code><?php echo esc_html( $tool['name'] ); ?></code> — <?php echo esc_html( $tool['description'] ); ?></li><?php endforeach; ?></ul></section><?php endforeach; ?></div>
