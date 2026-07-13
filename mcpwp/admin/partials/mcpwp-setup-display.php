<?php
/**
 * Setup page template — first thing users see after installing mumcp.
 *
 * Variables available from render_setup_page():
 *   $new_key        — string|null  plaintext key just generated (or from first-activation transient)
 *   $new_scoped_key — array|null   newly created scoped key payload
 *   $scoped_keys    — array        list of all scoped API keys
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stored_key_hash = get_option( 'mcpwp_api_key', '' );
$admin           = new Mcpwp_Admin();
$is_first        = get_option( 'mcpwp_first_activation', false );
$rest_base       = rest_url( 'mcpwp/v1/' );
$mcp_url         = rest_url( 'mcpwp/v1/mcp' );
$site_name       = get_bloginfo( 'name' );
$site_slug       = sanitize_title( $site_name );
// In the WP.org build no update-channel data is needed — the method early-returns a neutral stub.
$update_channel  = $admin->get_update_channel_status();
$license         = class_exists( 'Mcpwp_License' ) ? Mcpwp_License::get_instance() : null;
$license_plan    = $license ? $license->get_plan() : 'unlicensed';
$license_label   = ucwords( str_replace( '_', ' ', $license_plan ) );

// Non-Latin site names (Persian, Arabic, CJK) produce URL-encoded slugs — fall back to hostname.
if ( empty( $site_slug ) || false !== strpos( $site_slug, '%' ) ) {
	$site_slug = preg_replace( '/^www\./', '', wp_parse_url( home_url(), PHP_URL_HOST ) );
	$site_slug = str_replace( '.', '-', $site_slug );
}

// Determine key display state.
if ( isset( $new_key ) && $new_key ) {
	$display_key = $new_key;
	$is_hidden   = false;
} elseif ( ! empty( $stored_key_hash ) ) {
	$display_key = 'mcpwp_******************** (Hidden)';
	$is_hidden   = true;
} else {
	$display_key = '';
	$is_hidden   = false;
}

$role_definitions = Mcpwp_Admin::get_role_definitions();
$all_cat_labels   = Mcpwp_Admin::get_all_tool_category_labels();

// Onboarding checklist state.
$onboard_key_done  = ! empty( $stored_key_hash );
$onboard_conn_done = ! empty( $last_activity_time );
$onboard_tool_done = class_exists( 'Mcpwp_Action_Log' )
	&& ( Mcpwp_Action_Log::list_entries( array( 'limit' => 1 ) )['total'] ?? 0 ) > 0;
$onboard_all_done  = $onboard_key_done && $onboard_conn_done && $onboard_tool_done;

// Role badge colours.
$role_colors = array(
	'admin'    => '#d63638',
	'author'   => '#2271b1',
	'designer' => '#8c5fc7',
	'editor'   => '#00a32a',
	'custom'   => '#996800',
);

// Recent API activity for connection status section.
$recent_activity    = $admin->get_recent_activity_rows( 5 );
$last_activity_time = ! empty( $recent_activity[0]['created_at'] ) ? $recent_activity[0]['created_at'] : null;
?>

<div class="wrap mcpwp-admin">
	<h1 class="mcpwp-header">
		<span class="mcpwp-logo">
			<span class="dashicons dashicons-shield"></span>
		</span>
		<?php esc_html_e( 'mumcp', 'mumega-mcp' ); ?>
		<span class="mcpwp-version">v<?php echo esc_html( MCPWP_VERSION ); ?></span>
	</h1>

	<?php if ( $is_first && isset( $new_key ) && $new_key ) : ?>
	<!-- First-time welcome banner -->
	<div class="mcpwp-welcome-banner" id="mcpwp-welcome">
		<div class="mcpwp-welcome-icon">
			<span class="dashicons dashicons-yes-alt"></span>
		</div>
		<div class="mcpwp-welcome-content">
			<h2><?php esc_html_e( 'mumcp is ready!', 'mumega-mcp' ); ?></h2>
			<p><?php esc_html_e( 'Your API key has been generated. Copy it now and use it to connect Claude Desktop, Claude Code, or ChatGPT to your WordPress site.', 'mumega-mcp' ); ?></p>
			<div class="mcpwp-api-key-wrapper mcpwp-api-key-wrapper--highlight">
				<input
					type="text"
					id="mcpwp-welcome-key"
					class="mcpwp-api-key-input"
					value="<?php echo esc_attr( $new_key ); ?>"
					readonly
				/>
				<button type="button" class="button button-primary mcpwp-copy-btn" data-copy="<?php echo esc_attr( $new_key ); ?>">
					<span class="dashicons dashicons-clipboard"></span>
					<?php esc_html_e( 'Copy Key', 'mumega-mcp' ); ?>
				</button>
			</div>
			<p class="mcpwp-welcome-warning">
				<strong><?php esc_html_e( 'Save this key now!', 'mumega-mcp' ); ?></strong>
				<?php esc_html_e( 'It will not be shown again after you leave this page. You can always regenerate a new key.', 'mumega-mcp' ); ?>
			</p>
			<button type="button" class="button mcpwp-dismiss-welcome" id="mcpwp-dismiss-welcome">
				<?php esc_html_e( 'Got it, I\'ve saved my key', 'mumega-mcp' ); ?>
			</button>
		</div>
	</div>
	<?php endif; ?>

	<div class="mcpwp-license-banner mcpwp-license-active">
		<div class="mcpwp-license-content">
			<span class="dashicons dashicons-yes-alt"></span>
			<strong>
				<?php
				printf(
					/* translators: %s: active plan name */
					esc_html__( 'Plan: %s', 'mumega-mcp' ),
					esc_html( $license_label )
				);
				?>
			</strong>
			&mdash;
			<a href="https://mumcp.com/pricing/" target="_blank"><?php esc_html_e( 'Manage pricing and license', 'mumega-mcp' ); ?></a>
		</div>
	</div>

	<?php settings_errors( 'mcpwp_messages' ); ?>

	<div class="mcpwp-setup-page">

		<?php if ( ! $onboard_all_done ) : ?>
		<!-- ============================= ONBOARDING CHECKLIST ============================= -->
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Getting Started', 'mumega-mcp' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Complete these three steps to connect your first AI client.', 'mumega-mcp' ); ?>
			</p>
			<div class="mcpwp-onboarding-checklist">
				<div class="mcpwp-onboarding-step">
					<span class="mcpwp-onboarding-step__status <?php echo $onboard_key_done ? 'is-done' : 'is-open'; ?>">
						<?php echo $onboard_key_done ? esc_html__( 'Done', 'mumega-mcp' ) : esc_html__( 'To do', 'mumega-mcp' ); ?>
					</span>
					<div class="mcpwp-onboarding-step__body">
						<strong><?php esc_html_e( 'Generate an API key', 'mumega-mcp' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Create your first key in the "Your API Key" section below.', 'mumega-mcp' ); ?></p>
					</div>
				</div>
				<div class="mcpwp-onboarding-step">
					<span class="mcpwp-onboarding-step__status <?php echo $onboard_conn_done ? 'is-done' : 'is-open'; ?>">
						<?php echo $onboard_conn_done ? esc_html__( 'Done', 'mumega-mcp' ) : esc_html__( 'To do', 'mumega-mcp' ); ?>
					</span>
					<div class="mcpwp-onboarding-step__body">
						<strong><?php esc_html_e( 'Make your first connection', 'mumega-mcp' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Paste the config snippet into your AI client and send a request. The "Test Connection" button below can verify connectivity.', 'mumega-mcp' ); ?></p>
					</div>
				</div>
				<div class="mcpwp-onboarding-step">
					<span class="mcpwp-onboarding-step__status <?php echo $onboard_tool_done ? 'is-done' : 'is-open'; ?>">
						<?php echo $onboard_tool_done ? esc_html__( 'Done', 'mumega-mcp' ) : esc_html__( 'To do', 'mumega-mcp' ); ?>
					</span>
					<div class="mcpwp-onboarding-step__body">
						<strong><?php esc_html_e( 'Run your first AI tool call', 'mumega-mcp' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Ask your AI client to list pages or read site info. The first write-tool action will appear in the AI Action Log.', 'mumega-mcp' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- ============================= SECTION 1: YOUR API KEY ============================= -->
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-admin-network"></span>
				<?php esc_html_e( 'Your API Key', 'mumega-mcp' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'This key authenticates AI assistants when they connect to your site. Generate one key per AI client so you can revoke access individually.', 'mumega-mcp' ); ?>
			</p>

			<?php if ( empty( $stored_key_hash ) && ! ( isset( $new_key ) && $new_key ) ) : ?>
			<!-- No keys yet — prominent Generate button -->
			<div class="mcpwp-no-key-prompt">
				<p class="description"><strong><?php esc_html_e( 'No API key configured yet.', 'mumega-mcp' ); ?></strong> <?php esc_html_e( 'Generate one to start connecting AI tools to this site.', 'mumega-mcp' ); ?></p>
				<form method="post" class="mcpwp-regenerate-form">
					<?php wp_nonce_field( 'mcpwp_regenerate_key', 'mcpwp_nonce' ); ?>
					<button type="submit" name="mcpwp_regenerate_key" class="button button-primary button-hero">
						<span class="dashicons dashicons-plus-alt"></span>
						<?php esc_html_e( 'Generate API Key', 'mumega-mcp' ); ?>
					</button>
				</form>
			</div>
			<?php else : ?>

			<!-- Key exists (or was just generated) -->
			<div class="mcpwp-api-key-wrapper">
				<input
					type="text"
					id="mcpwp-api-key"
					class="mcpwp-api-key-input"
					value="<?php echo esc_attr( $display_key ); ?>"
					readonly
				/>
				<?php if ( ! $is_hidden ) : ?>
				<button type="button" class="button mcpwp-copy-btn" data-copy="<?php echo esc_attr( $display_key ); ?>">
					<span class="dashicons dashicons-clipboard"></span>
					<?php esc_html_e( 'Copy', 'mumega-mcp' ); ?>
				</button>
				<?php endif; ?>
			</div>

			<?php if ( $is_hidden ) : ?>
			<p class="description">
				<?php esc_html_e( 'Your API key is stored securely (hashed). To see it again, regenerate a new one below.', 'mumega-mcp' ); ?>
			</p>
			<?php endif; ?>

			<form method="post" class="mcpwp-regenerate-form" style="margin-top:10px;">
				<?php wp_nonce_field( 'mcpwp_regenerate_key', 'mcpwp_nonce' ); ?>
				<button type="submit" name="mcpwp_regenerate_key" class="button mcpwp-regenerate-btn">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Regenerate Key', 'mumega-mcp' ); ?>
				</button>
				<span class="description"><?php esc_html_e( 'The old key will stop working immediately.', 'mumega-mcp' ); ?></span>
			</form>
			<?php endif; ?>

			<!-- Role-based API keys -->
			<hr style="margin:24px 0;" />
			<h3><?php esc_html_e( 'Create Role-Based Key', 'mumega-mcp' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Each role limits which MCP tools the AI can access. Use Designer for Elementor-only access, Editor for content, Author for drafts.', 'mumega-mcp' ); ?>
			</p>

			<?php if ( ! empty( $new_scoped_key['key'] ) ) : ?>
			<div class="mcpwp-api-key-wrapper mcpwp-api-key-wrapper--highlight" style="margin-bottom:12px;">
				<input
					type="text"
					class="mcpwp-api-key-input"
					value="<?php echo esc_attr( $new_scoped_key['key'] ); ?>"
					readonly
				/>
				<button type="button" class="button button-primary mcpwp-copy-btn" data-copy="<?php echo esc_attr( $new_scoped_key['key'] ); ?>">
					<span class="dashicons dashicons-clipboard"></span>
					<?php esc_html_e( 'Copy Key', 'mumega-mcp' ); ?>
				</button>
			</div>
			<?php endif; ?>

			<form method="post" class="mcpwp-regenerate-form">
				<?php wp_nonce_field( 'mcpwp_manage_scoped_keys', 'mcpwp_scoped_keys_nonce' ); ?>
				<p>
					<label for="mcpwp_scoped_key_label"><strong><?php esc_html_e( 'Label', 'mumega-mcp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_scoped_key_label" name="mcpwp_scoped_key_label" class="regular-text" placeholder="<?php esc_attr_e( 'Example: Content Writer Bot', 'mumega-mcp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_scoped_key_role"><strong><?php esc_html_e( 'Role', 'mumega-mcp' ); ?></strong></label><br />
					<select id="mcpwp_scoped_key_role" name="mcpwp_scoped_key_role" style="min-width:200px;">
						<?php foreach ( $role_definitions as $role_slug => $role_def ) : ?>
						<option value="<?php echo esc_attr( $role_slug ); ?>"
							data-categories="<?php echo esc_attr( wp_json_encode( $role_def['categories'] ) ); ?>">
							<?php echo esc_html( $role_def['label'] ); ?> &mdash; <?php echo esc_html( $role_def['description'] ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</p>
				<div id="mcpwp-custom-categories" style="display:none;margin-bottom:12px;">
					<strong><?php esc_html_e( 'Tool Categories', 'mumega-mcp' ); ?></strong><br />
					<p class="description" style="margin-bottom:6px;">
						<?php esc_html_e( 'Select which tool categories this key can access.', 'mumega-mcp' ); ?>
					</p>
					<?php foreach ( $all_cat_labels as $cat_slug => $cat_label ) : ?>
					<label style="display:inline-block;min-width:120px;margin:2px 0;">
						<input type="checkbox" name="mcpwp_scoped_key_categories[]" value="<?php echo esc_attr( $cat_slug ); ?>" class="mcpwp-category-checkbox" />
						<?php echo esc_html( $cat_label ); ?>
					</label>
					<?php endforeach; ?>
				</div>
				<div id="mcpwp-role-preview" style="margin-bottom:12px;padding:8px 12px;background:#f0f0f1;border-radius:4px;display:none;">
					<strong><?php esc_html_e( 'Access:', 'mumega-mcp' ); ?></strong>
					<span id="mcpwp-role-preview-categories"></span>
				</div>
				<button type="submit" name="mcpwp_create_scoped_key" class="button button-primary">
					<?php esc_html_e( 'Create API Key', 'mumega-mcp' ); ?>
				</button>
			</form>

			<!-- Active keys table -->
			<?php if ( ! empty( $scoped_keys ) ) : ?>
			<h3 style="margin-top:24px;"><?php esc_html_e( 'Active Keys', 'mumega-mcp' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'mumega-mcp' ); ?></th>
						<th><?php esc_html_e( 'Role', 'mumega-mcp' ); ?></th>
						<th><?php esc_html_e( 'Categories', 'mumega-mcp' ); ?></th>
						<th><?php esc_html_e( 'Created', 'mumega-mcp' ); ?></th>
						<th><?php esc_html_e( 'Last Used', 'mumega-mcp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mumega-mcp' ); ?></th>
						<th><?php esc_html_e( 'Action', 'mumega-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $scoped_keys as $key ) :
						$key_role    = isset( $key['role'] ) ? $key['role'] : 'admin';
						$role_def    = isset( $role_definitions[ $key_role ] ) ? $role_definitions[ $key_role ] : $role_definitions['admin'];
						$badge_bg    = isset( $role_colors[ $key_role ] ) ? $role_colors[ $key_role ] : '#50575e';
						$display_cats = array();
						if ( 'admin' === $key_role ) {
							$display_cats = array( __( 'All', 'mumega-mcp' ) );
						} elseif ( 'custom' === $key_role && ! empty( $key['tool_categories'] ) ) {
							foreach ( $key['tool_categories'] as $cat ) {
								$display_cats[] = isset( $all_cat_labels[ $cat ] ) ? $all_cat_labels[ $cat ] : $cat;
							}
						} elseif ( ! empty( $role_def['categories'] ) ) {
							foreach ( $role_def['categories'] as $cat ) {
								$display_cats[] = isset( $all_cat_labels[ $cat ] ) ? $all_cat_labels[ $cat ] : $cat;
							}
						}
					?>
					<tr<?php echo ! empty( $key['revoked_at'] ) ? ' style="opacity:0.5;"' : ''; ?>>
						<td><strong><?php echo esc_html( $key['label'] ); ?></strong></td>
						<td>
							<span style="display:inline-block;background:<?php echo esc_attr( $badge_bg ); ?>;color:#fff;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;">
								<?php echo esc_html( $role_def['label'] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( implode( ', ', $display_cats ) ); ?></td>
						<td><?php echo ! empty( $key['created_at'] ) ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $key['created_at'] ) ) ) : '&mdash;'; ?></td>
						<td><?php echo ! empty( $key['last_used_at'] ) ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $key['last_used_at'] ) ) ) : '&mdash;'; ?></td>
						<td>
							<?php if ( ! empty( $key['revoked_at'] ) ) : ?>
								<span class="mcpwp-status mcpwp-status-inactive"><?php esc_html_e( 'Revoked', 'mumega-mcp' ); ?></span>
							<?php else : ?>
								<span class="mcpwp-status mcpwp-status-active"><?php esc_html_e( 'Active', 'mumega-mcp' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( empty( $key['revoked_at'] ) ) : ?>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'mcpwp_manage_scoped_keys', 'mcpwp_scoped_keys_nonce' ); ?>
								<input type="hidden" name="mcpwp_scoped_key_id" value="<?php echo esc_attr( $key['id'] ); ?>" />
								<button type="submit" name="mcpwp_revoke_scoped_key" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Revoke this key?', 'mumega-mcp' ) ); ?>');">
									<?php esc_html_e( 'Revoke', 'mumega-mcp' ); ?>
								</button>
							</form>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<!-- ============================= SECTION 2: CONNECT YOUR AI ============================= -->
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-cloud"></span>
				<?php esc_html_e( 'Connect Your AI', 'mumega-mcp' ); ?>
			</h2>
			<p class="description">
				<?php
				if ( $is_hidden ) {
					esc_html_e( 'Your key is saved. Generate a new one above to auto-fill this config.', 'mumega-mcp' );
				} else {
					esc_html_e( 'Copy the config for your AI client and paste it in. Your API key has been pre-filled below.', 'mumega-mcp' );
				}
				?>
			</p>

			<nav class="nav-tab-wrapper mcpwp-tabs mcpwp-tabs--inner" id="mcpwp-connect-tabs">
				<a href="#" class="nav-tab nav-tab-active mcpwp-inner-tab" data-tab="claude-code"><?php esc_html_e( 'Claude Code', 'mumega-mcp' ); ?></a>
				<a href="#" class="nav-tab mcpwp-inner-tab" data-tab="claude-desktop"><?php esc_html_e( 'Claude Desktop', 'mumega-mcp' ); ?></a>
				<a href="#" class="nav-tab mcpwp-inner-tab" data-tab="cursor"><?php esc_html_e( 'Cursor', 'mumega-mcp' ); ?></a>
				<a href="#" class="nav-tab mcpwp-inner-tab" data-tab="windsurf"><?php esc_html_e( 'Windsurf', 'mumega-mcp' ); ?></a>
			</nav>

			<!-- Claude Code -->
			<div class="mcpwp-inner-tab-content" id="mcpwp-tab-claude-code">
				<p><?php esc_html_e( 'Add to .mcp.json in your project root or ~/.claude.json for global access:', 'mumega-mcp' ); ?></p>
				<div class="mcpwp-code-wrapper">
					<pre class="mcpwp-code-block" id="mcpwp-claude-code-cfg">{
  "mcpServers": {
    "mcpwp-<?php echo esc_html( $site_slug ); ?>": {
      "url": "<?php echo esc_url( $mcp_url ); ?>",
      "headers": {
        "X-API-Key": "<?php echo $is_hidden ? 'YOUR_API_KEY' : esc_attr( $display_key ); ?>"
      }
    }
  }
}</pre>
					<button type="button" class="button mcpwp-copy-code-btn" data-target="mcpwp-claude-code-cfg">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy', 'mumega-mcp' ); ?>
					</button>
				</div>
			</div>

			<!-- Claude Desktop -->
			<div class="mcpwp-inner-tab-content" id="mcpwp-tab-claude-desktop" style="display:none;">
				<p>
					<?php esc_html_e( 'Fastest method: in Claude Desktop go to Settings → Connectors → Add custom connector and paste the MCP URL:', 'mumega-mcp' ); ?>
				</p>
				<div class="mcpwp-code-wrapper">
					<pre class="mcpwp-code-block" id="mcpwp-claude-desktop-url"><?php echo esc_url( add_query_arg( 'api_key', ( $is_hidden ? 'YOUR_API_KEY' : $display_key ), $mcp_url ) ); ?></pre>
					<button type="button" class="button mcpwp-copy-code-btn" data-target="mcpwp-claude-desktop-url">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy URL', 'mumega-mcp' ); ?>
					</button>
				</div>
				<p><?php esc_html_e( 'Or add to claude_desktop_config.json:', 'mumega-mcp' ); ?></p>
				<div class="mcpwp-code-wrapper">
					<pre class="mcpwp-code-block" id="mcpwp-claude-desktop-cfg">{
  "mcpServers": {
    "mcpwp-<?php echo esc_html( $site_slug ); ?>": {
      "url": "<?php echo esc_url( $mcp_url ); ?>",
      "headers": {
        "X-API-Key": "<?php echo $is_hidden ? 'YOUR_API_KEY' : esc_attr( $display_key ); ?>"
      }
    }
  }
}</pre>
					<button type="button" class="button mcpwp-copy-code-btn" data-target="mcpwp-claude-desktop-cfg">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy', 'mumega-mcp' ); ?>
					</button>
				</div>
			</div>

			<!-- Cursor -->
			<div class="mcpwp-inner-tab-content" id="mcpwp-tab-cursor" style="display:none;">
				<p><?php esc_html_e( 'In Cursor go to Settings → MCP and add a new server:', 'mumega-mcp' ); ?></p>
				<div class="mcpwp-code-wrapper">
					<pre class="mcpwp-code-block" id="mcpwp-cursor-cfg">{
  "mcpServers": {
    "mcpwp-<?php echo esc_html( $site_slug ); ?>": {
      "url": "<?php echo esc_url( $mcp_url ); ?>",
      "headers": {
        "X-API-Key": "<?php echo $is_hidden ? 'YOUR_API_KEY' : esc_attr( $display_key ); ?>"
      }
    }
  }
}</pre>
					<button type="button" class="button mcpwp-copy-code-btn" data-target="mcpwp-cursor-cfg">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy', 'mumega-mcp' ); ?>
					</button>
				</div>
			</div>

			<!-- Windsurf -->
			<div class="mcpwp-inner-tab-content" id="mcpwp-tab-windsurf" style="display:none;">
				<p><?php esc_html_e( 'Add to your Windsurf MCP config (~/.codeium/windsurf/mcp_config.json):', 'mumega-mcp' ); ?></p>
				<div class="mcpwp-code-wrapper">
					<pre class="mcpwp-code-block" id="mcpwp-windsurf-cfg">{
  "mcpServers": {
    "mcpwp-<?php echo esc_html( $site_slug ); ?>": {
      "serverUrl": "<?php echo esc_url( $mcp_url ); ?>",
      "headers": {
        "X-API-Key": "<?php echo $is_hidden ? 'YOUR_API_KEY' : esc_attr( $display_key ); ?>"
      }
    }
  }
}</pre>
					<button type="button" class="button mcpwp-copy-code-btn" data-target="mcpwp-windsurf-cfg">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy', 'mumega-mcp' ); ?>
					</button>
				</div>
			</div>

		</div>

		<!-- ============================= SECTION 3: CONNECTION STATUS ============================= -->
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Connection Status', 'mumega-mcp' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Verify that the REST API is working and the plugin is reachable.', 'mumega-mcp' ); ?>
			</p>

			<div class="mcpwp-library-summary" style="margin-bottom:20px;">
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value">
						<?php if ( $last_activity_time ) : ?>
							<span style="color:#28a745;">&#9679;</span>
						<?php else : ?>
							<span style="color:#dba617;">&#9679;</span>
						<?php endif; ?>
					</span>
					<span class="mcpwp-library-stat__label"><?php $last_activity_time ? esc_html_e( 'Activity Detected', 'mumega-mcp' ) : esc_html_e( 'No Activity Yet', 'mumega-mcp' ); ?></span>
				</div>
				<?php if ( $last_activity_time ) : ?>
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value" style="font-size:13px;"><?php echo esc_html( $last_activity_time ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Last API Call', 'mumega-mcp' ); ?></span>
				</div>
				<?php endif; ?>
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value"><?php echo esc_html( MCPWP_VERSION ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Plugin Version', 'mumega-mcp' ); ?></span>
				</div>
			</div>

			<div class="mcpwp-test-connection">
				<button type="button" class="button button-primary" id="mcpwp-test-btn">
					<span class="dashicons dashicons-yes-alt"></span>
					<?php esc_html_e( 'Test Connection', 'mumega-mcp' ); ?>
				</button>
				<div class="mcpwp-test-result" id="mcpwp-test-result" style="display:none;">
					<div class="mcpwp-test-success" style="display:none;">
						<span class="dashicons dashicons-yes"></span>
						<span class="mcpwp-test-message"></span>
					</div>
					<div class="mcpwp-test-error" style="display:none;">
						<span class="dashicons dashicons-no"></span>
						<span class="mcpwp-test-message"></span>
					</div>
					<div class="mcpwp-test-details" style="display:none;"></div>
				</div>
			</div>

			<?php if ( ! defined( 'MCPWP_WPORG_BUILD' ) ) : ?>
			<div style="margin-top:16px;">
				<?php if ( $update_channel['update_available'] ) : ?>
				<div class="notice notice-warning inline" style="margin-bottom:12px;">
					<p>
							<?php
							printf(
								/* translators: 1: current plugin version, 2: available plugin version */
								esc_html__( 'Update available: v%1$s → v%2$s.', 'mumega-mcp' ),
								esc_html( $update_channel['current_version'] ),
								esc_html( $update_channel['remote_version'] )
						);
						?>
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-primary" style="margin-left:8px;"><?php esc_html_e( 'Update Now', 'mumega-mcp' ); ?></a>
						<a href="<?php echo esc_url( $update_channel['download_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button" style="margin-left:4px;"><?php esc_html_e( 'Download ZIP', 'mumega-mcp' ); ?></a>
					</p>
				</div>
				<?php else : ?>
				<div class="notice notice-success inline" style="margin-bottom:12px;">
					<p>
						<?php esc_html_e( 'You are running the latest version.', 'mumega-mcp' ); ?>
						<strong>v<?php echo esc_html( MCPWP_VERSION ); ?></strong>
					</p>
				</div>
				<?php endif; ?>
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( 'mcpwp_check_update', 'mcpwp_update_nonce' ); ?>
					<button type="submit" name="mcpwp_force_update_check" class="button">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Check for Updates', 'mumega-mcp' ); ?>
					</button>
				</form>
				<p class="description" style="margin-top:8px;">
					<?php esc_html_e( 'Update channel:', 'mumega-mcp' ); ?>
					<code><?php echo esc_html( $update_channel['manifest_url'] ); ?></code>
					<br /><span class="description"><?php esc_html_e( 'This is the URL mumcp checks for new versions. It should point to the mumega.com manifest.', 'mumega-mcp' ); ?></span>
				</p>
			</div>
			<?php endif; // ! MCPWP_WPORG_BUILD ?>
		</div>

		<!-- ============================= SECTION 4: QUICK LINKS ============================= -->
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-admin-links"></span>
				<?php esc_html_e( 'Quick Links', 'mumega-mcp' ); ?>
			</h2>
			<ul class="mcpwp-quick-links">
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Mcpwp_Admin::LIBRARY_PAGE_SLUG ) ); ?>" class="button">
						<span class="dashicons dashicons-screenoptions"></span>
						<?php esc_html_e( 'Library', 'mumega-mcp' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Archetypes, reusable parts, and design references.', 'mumega-mcp' ); ?></span>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Mcpwp_Tools_Admin::PAGE_SLUG ) ); ?>" class="button">
						<span class="dashicons dashicons-admin-tools"></span>
						<?php esc_html_e( 'Tools', 'mumega-mcp' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Enable or disable MCP tool categories.', 'mumega-mcp' ); ?></span>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Mcpwp_Admin::ACTIVITY_LOG_PAGE_SLUG ) ); ?>" class="button">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'Activity Log', 'mumega-mcp' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Full history of API calls from connected AI tools.', 'mumega-mcp' ); ?></span>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Mcpwp_Admin::SETTINGS_PAGE_SLUG ) ); ?>" class="button">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php esc_html_e( 'Settings', 'mumega-mcp' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Rate limits, site context, logging, and more.', 'mumega-mcp' ); ?></span>
				</li>
				<li>
					<a href="https://mumcp.com/docs/" target="_blank" rel="noopener noreferrer" class="button">
						<span class="dashicons dashicons-book"></span>
						<?php esc_html_e( 'Documentation', 'mumega-mcp' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Full API reference and mumcp guides.', 'mumega-mcp' ); ?></span>
				</li>
			</ul>
		</div>

	</div><!-- .mcpwp-setup-page -->
</div><!-- .wrap -->
