<?php
/**
 * Setup page template — first thing users see after installing MCPWP.
 *
 * Variables available from render_setup_page():
 *   $new_key        — string|null  plaintext key just generated (or from first-activation transient)
 *   $new_scoped_key — array|null   newly created scoped key payload
 *   $scoped_keys    — array        list of all scoped API keys
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stored_key_hash = get_option( 'mcpwp_api_key', '' );
// "Has a key" = legacy option OR an active scoped record (#1097): the legacy
// option alone is only a copy and may be empty on a site with live keys.
$site_has_key    = Mcpwp_Key_State::site_has_api_key();
$admin           = new Mcpwp_Admin();
$is_first        = get_option( 'mcpwp_first_activation', false );
$rest_base       = rest_url( 'mcpwp/v1/' );
$mcp_url         = rest_url( 'mcpwp/v1/mcp' );
$site_name       = get_bloginfo( 'name' );
$site_slug       = sanitize_title( $site_name );
// In the WP.org build no update-channel data is needed — the method early-returns a neutral stub.
$update_channel  = $admin->get_update_channel_status();
$license         = class_exists( 'Mcpwp_License' ) ? Mcpwp_License::get_instance() : null;
$license_label   = $license ? $license->get_plan_label() : __( 'Free', 'mcpwp' );

// Prefer the Freemius SDK's own install-linked URL — it carries the site's
// install_id so checkout activates THIS site and redirects back into
// wp-admin. A plain marketing-site link has no install context, so a
// completed purchase there never syncs back to the plugin (issue reported
// by Hadi: "pay with coupon, nothing happens, no way back to WP").
$mcpwp_fs_obj        = function_exists( 'mcpwp_get_fs_instance' ) ? mcpwp_get_fs_instance() : null;
$license_manage_url = $license ? $license->get_upgrade_url() : 'https://mcpwp.net/pricing/';
if ( is_object( $mcpwp_fs_obj ) ) {
	$fs_is_stuck = ( method_exists( $mcpwp_fs_obj, 'is_anonymous' ) && $mcpwp_fs_obj->is_anonymous() )
		|| ( method_exists( $mcpwp_fs_obj, 'is_pending_activation' ) && $mcpwp_fs_obj->is_pending_activation() );

	if ( $fs_is_stuck && method_exists( $mcpwp_fs_obj, 'get_reconnect_url' ) ) {
		// Site skipped connect (or an old dev-cycle install left it in
		// pending-activation) — the opt-in screen never shows again on its
		// own. get_reconnect_url() clears that state and forces the real
		// connect screen back up (Freemius's own official flow for this,
		// not a raw option edit).
		$license_manage_url = $mcpwp_fs_obj->get_reconnect_url();
	} elseif ( method_exists( $mcpwp_fs_obj, 'is_registered' ) && $mcpwp_fs_obj->is_registered() && method_exists( $mcpwp_fs_obj, 'get_upgrade_url' ) ) {
		$license_manage_url = $mcpwp_fs_obj->get_upgrade_url();
	} elseif ( method_exists( $mcpwp_fs_obj, 'get_account_url' ) ) {
		$license_manage_url = $mcpwp_fs_obj->get_account_url();
	}
}

// Non-Latin site names (Persian, Arabic, CJK) produce URL-encoded slugs — fall back to hostname.
if ( empty( $site_slug ) || false !== strpos( $site_slug, '%' ) ) {
	$site_slug = preg_replace( '/^www\./', '', wp_parse_url( home_url(), PHP_URL_HOST ) );
	$site_slug = str_replace( '.', '-', $site_slug );
}

// Determine key display state.
if ( isset( $new_key ) && $new_key ) {
	$display_key = $new_key;
	$is_hidden   = false;
} elseif ( $site_has_key ) {
	$display_key = 'mcpwp_******************** (Hidden)';
	$is_hidden   = true;
} else {
	$display_key = '';
	$is_hidden   = false;
}

$role_definitions = Mcpwp_Admin::get_role_definitions();
$all_cat_labels   = Mcpwp_Admin::get_all_tool_category_labels();

// Onboarding checklist state.
$onboard_key_done  = $site_has_key;
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
		<?php esc_html_e( 'MCPWP', 'mcpwp' ); ?>
		<span class="mcpwp-version">v<?php echo esc_html( MCPWP_VERSION ); ?></span>
	</h1>

	<?php if ( $is_first && isset( $new_key ) && $new_key ) : ?>
	<!-- First-time welcome banner -->
	<div class="mcpwp-welcome-banner" id="mcpwp-welcome">
		<div class="mcpwp-welcome-icon">
			<span class="dashicons dashicons-yes-alt"></span>
		</div>
		<div class="mcpwp-welcome-content">
			<h2><?php esc_html_e( 'MCPWP is ready!', 'mcpwp' ); ?></h2>
			<p><?php esc_html_e( 'Your API key has been generated. Copy it now and use it to connect Claude Desktop, Claude Code, or ChatGPT to your WordPress site.', 'mcpwp' ); ?></p>
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
					<?php esc_html_e( 'Copy Key', 'mcpwp' ); ?>
				</button>
			</div>
			<p class="mcpwp-welcome-warning">
				<strong><?php esc_html_e( 'Save this key now!', 'mcpwp' ); ?></strong>
				<?php esc_html_e( 'It will not be shown again after you leave this page. You can always regenerate a new key.', 'mcpwp' ); ?>
			</p>
			<button type="button" class="button mcpwp-dismiss-welcome" id="mcpwp-dismiss-welcome">
				<?php esc_html_e( 'Got it, I\'ve saved my key', 'mcpwp' ); ?>
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
					esc_html__( 'Plan: %s', 'mcpwp' ),
					esc_html( $license_label )
				);
				?>
			</strong>
			&mdash;
			<a href="<?php echo esc_url( $license_manage_url ); ?>" class="mcpwp-upgrade-link"<?php echo ( false !== strpos( $license_manage_url, 'mcpwp.net' ) ) ? ' target="_blank"' : ''; ?>><?php esc_html_e( 'Manage pricing and license', 'mcpwp' ); ?></a>
		</div>
	</div>

	<?php settings_errors( 'mcpwp_messages' ); ?>

	<div class="mcpwp-setup-page">

		<?php if ( ! $onboard_all_done ) : ?>
		<!-- ============================= ONBOARDING CHECKLIST ============================= -->
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Getting Started', 'mcpwp' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Complete these three steps to connect your first AI client.', 'mcpwp' ); ?>
			</p>
			<div class="mcpwp-onboarding-checklist">
				<div class="mcpwp-onboarding-step">
					<span class="mcpwp-onboarding-step__status <?php echo $onboard_key_done ? 'is-done' : 'is-open'; ?>">
						<?php echo $onboard_key_done ? esc_html__( 'Done', 'mcpwp' ) : esc_html__( 'To do', 'mcpwp' ); ?>
					</span>
					<div class="mcpwp-onboarding-step__body">
						<strong><?php esc_html_e( 'Generate an API key', 'mcpwp' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Create your first key in the "Your API Key" section below.', 'mcpwp' ); ?></p>
					</div>
				</div>
				<div class="mcpwp-onboarding-step">
					<span class="mcpwp-onboarding-step__status <?php echo $onboard_conn_done ? 'is-done' : 'is-open'; ?>">
						<?php echo $onboard_conn_done ? esc_html__( 'Done', 'mcpwp' ) : esc_html__( 'To do', 'mcpwp' ); ?>
					</span>
					<div class="mcpwp-onboarding-step__body">
						<strong><?php esc_html_e( 'Make your first connection', 'mcpwp' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Paste the config snippet into your AI client and send a request. The "Test Connection" button below can verify connectivity.', 'mcpwp' ); ?></p>
					</div>
				</div>
				<div class="mcpwp-onboarding-step">
					<span class="mcpwp-onboarding-step__status <?php echo $onboard_tool_done ? 'is-done' : 'is-open'; ?>">
						<?php echo $onboard_tool_done ? esc_html__( 'Done', 'mcpwp' ) : esc_html__( 'To do', 'mcpwp' ); ?>
					</span>
					<div class="mcpwp-onboarding-step__body">
						<strong><?php esc_html_e( 'Run your first AI tool call', 'mcpwp' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Ask your AI client to list pages or read site info. The first write-tool action will appear in the AI Action Log.', 'mcpwp' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<?php Mcpwp_OAuth_Local_Admin::render_address_notice(); ?>

		<!-- ============================= CONNECT AN AI APP ============================= -->
		<?php Mcpwp_OAuth_Apps_Admin::render_connect_card( ! empty( $local_oauth_enabled ) ); ?>

		<!-- ============================= ADD TO CHATGPT ============================= -->
		<div class="mcpwp-card" id="mcpwp-add-to-chatgpt">
			<h2>
				<span class="dashicons dashicons-format-chat"></span>
				<?php esc_html_e( 'Add to ChatGPT', 'mcpwp' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'One link connects ChatGPT to this site. No account, no sign-in: create the link, paste it into ChatGPT, done.', 'mcpwp' ); ?>
			</p>

			<?php if ( 'broker' === $chatgpt_oauth_conflict ) : ?>
				<div class="notice notice-warning inline" style="margin:10px 0;">
					<p>
						<strong><?php esc_html_e( 'Disconnect mumcp.com first.', 'mcpwp' ); ?></strong>
						<?php esc_html_e( 'This site is enrolled with the mumcp.com sign-in broker. While it is, a mistyped or replaced link makes ChatGPT ask you to sign in to mumcp.com instead of showing a plain error.', 'mcpwp' ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 10px;">
						<input type="hidden" name="action" value="mcpwp_broker_disconnect" />
						<?php wp_nonce_field( 'mcpwp_broker_disconnect', 'mcpwp_broker_nonce' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Disconnect mumcp.com', 'mcpwp' ); ?></button>
						<span class="description"><?php esc_html_e( 'Also under Connect Your AI → Claude Desktop → Advanced.', 'mcpwp' ); ?></span>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $chatgpt_new_url ) ) : ?>
				<div class="mcpwp-code-wrapper">
					<pre class="mcpwp-code-block" id="mcpwp-chatgpt-url"><?php echo esc_url( $chatgpt_new_url ); ?></pre>
					<button type="button" class="button button-primary mcpwp-copy-code-btn" data-target="mcpwp-chatgpt-url">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy link', 'mcpwp' ); ?>
					</button>
				</div>
				<div class="notice notice-warning inline" style="margin:10px 0 0;">
					<p>
						<strong><?php esc_html_e( 'This link contains a key. Treat it like a password.', 'mcpwp' ); ?></strong>
						<?php esc_html_e( 'It is shown once. Paste it into ChatGPT now; do not email it or post it in a chat.', 'mcpwp' ); ?>
					</p>
				</div>
			<?php elseif ( ! empty( $chatgpt_active_key ) ) : ?>
				<p>
					<span class="mcpwp-status mcpwp-status-active"><?php esc_html_e( 'ChatGPT link active', 'mcpwp' ); ?></span>
					<?php
					printf(
						/* translators: 1: creation date, 2: last-used date or "never" */
						esc_html__( 'Created %1$s · last used %2$s.', 'mcpwp' ),
						esc_html( ! empty( $chatgpt_active_key['created_at'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $chatgpt_active_key['created_at'] ) ) : '—' ),
						esc_html( ! empty( $chatgpt_active_key['last_used_at'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $chatgpt_active_key['last_used_at'] ) ) : __( 'never', 'mcpwp' ) )
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'The link was shown only once. To see a link again, create a new one; the old link stops working at that moment.', 'mcpwp' ); ?>
				</p>
			<?php endif; ?>

			<div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;">
				<form method="post">
					<?php wp_nonce_field( Mcpwp_ChatGPT_Connector::NONCE_ACTION, 'mcpwp_chatgpt_connector_nonce' ); ?>
					<input type="hidden" name="mcpwp_chatgpt_connector_action" value="create" />
					<?php if ( empty( $chatgpt_active_key ) && empty( $chatgpt_new_url ) ) : ?>
						<button type="submit" class="button button-primary button-hero">
							<span class="dashicons dashicons-admin-links"></span>
							<?php esc_html_e( 'Create ChatGPT link', 'mcpwp' ); ?>
						</button>
					<?php else : ?>
						<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Create a new ChatGPT link? The current link stops working immediately.', 'mcpwp' ) ); ?>');">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Make a new link (the old one stops working)', 'mcpwp' ); ?>
						</button>
					<?php endif; ?>
				</form>
				<?php if ( ! empty( $chatgpt_active_key ) ) : ?>
				<form method="post">
					<?php wp_nonce_field( Mcpwp_ChatGPT_Connector::NONCE_ACTION, 'mcpwp_chatgpt_connector_nonce' ); ?>
					<input type="hidden" name="mcpwp_chatgpt_connector_action" value="revoke" />
					<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Revoke the ChatGPT link? ChatGPT loses access to this site.', 'mcpwp' ) ); ?>');">
						<?php esc_html_e( 'Revoke', 'mcpwp' ); ?>
					</button>
				</form>
				<?php endif; ?>
			</div>

			<h4><?php esc_html_e( 'In ChatGPT', 'mcpwp' ); ?></h4>
			<ol>
				<li><?php esc_html_e( 'Open chatgpt.com/plugins, select +, then "Add custom MCP server".', 'mcpwp' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: site name */
						esc_html__( 'Name it "%s" and paste the link into the MCP server URL field.', 'mcpwp' ),
						esc_html( $site_name )
					);
					?>
				</li>
				<li><?php esc_html_e( 'Authentication: choose "No authentication". The key is already inside the link.', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'Accept the risk notice ("I understand and want to continue"), then create it. Ask ChatGPT to "list my pages" to check.', 'mcpwp' ); ?></li>
			</ol>
			<?php if ( $local_oauth_enabled ) : ?>
			<p>
				<strong><?php esc_html_e( 'Or connect with WordPress sign-in:', 'mcpwp' ); ?></strong>
				<?php esc_html_e( 'paste this URL into ChatGPT and choose OAuth as the authentication. ChatGPT sends you to this site\'s WordPress login and a consent screen; no link and no key to keep safe.', 'mcpwp' ); ?>
			</p>
			<div class="mcpwp-code-wrapper">
				<pre class="mcpwp-code-block" id="mcpwp-chatgpt-oauth-url"><?php echo esc_url( Mcpwp_ChatGPT_Connector::endpoint_url() ); ?></pre>
				<button type="button" class="button mcpwp-copy-code-btn" data-target="mcpwp-chatgpt-oauth-url">
					<span class="dashicons dashicons-clipboard"></span>
					<?php esc_html_e( 'Copy URL', 'mcpwp' ); ?>
				</button>
			</div>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Custom MCP servers depend on your ChatGPT plan, and in a Business or Enterprise workspace an admin may have to allow them first.', 'mcpwp' ); ?>
			</p>

			<div class="notice notice-info inline" style="margin:12px 0;">
				<p><strong><?php esc_html_e( 'If ChatGPT shows an error', 'mcpwp' ); ?></strong></p>
				<ul style="list-style:disc;margin-left:20px;">
					<li><?php esc_html_e( 'No "Add custom MCP server" option: your ChatGPT plan or workspace does not allow custom MCP servers. A workspace admin can turn it on.', 'mcpwp' ); ?></li>
					<li><?php esc_html_e( 'Connection failed: a security plugin, firewall/WAF (for example Cloudflare bot rules) or a disabled REST API is blocking ChatGPT from reaching /wp-json/mcpwp/v1/mcp.', 'mcpwp' ); ?></li>
					<?php if ( 'broker' === $chatgpt_oauth_conflict ) : ?>
					<li><?php esc_html_e( 'ChatGPT asks you to sign in to mumcp.com: this site is enrolled with mumcp.com. Disconnect it (above), then add the link again.', 'mcpwp' ); ?></li>
					<?php else : ?>
					<li><?php esc_html_e( 'ChatGPT asks you to sign in: with OAuth chosen, that is this site\'s WordPress sign-in and it is expected. If you used the link with "No authentication" and it still asks, the link\'s key was revoked or replaced; make a new link.', 'mcpwp' ); ?></li>
					<?php endif; ?>
				</ul>
			</div>

			<h4><?php esc_html_e( 'What this link can and cannot do', 'mcpwp' ); ?></h4>
			<ul style="list-style:disc;margin-left:20px;">
				<li><?php esc_html_e( 'Roughly the access of a WordPress Editor, without theme templates. It can never act as an administrator.', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'Can read published content, drafts and private posts. Whatever ChatGPT reads is sent to OpenAI.', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'Can create drafts; edit, unpublish or move to trash pages and posts, including the live home page; manage media, categories, tags and SEO fields; edit Elementor layouts; and change site-wide Elementor styles (global colours, fonts and kit CSS).', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'Can add HTML to pages, including scripts, as a WordPress Editor can. A malicious page or document read by ChatGPT could try to make it do so.', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'Cannot publish new content, create or change theme templates (headers, footers), change site settings, menus or plugins, manage users, or create or see API keys.', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'Permanent deletes wait in the approval queue by default. Moving to trash does not, and can be undone from the Trash.', 'mcpwp' ); ?></li>
			</ul>

			<h4><?php esc_html_e( 'For your IT team', 'mcpwp' ); ?></h4>
			<ul style="list-style:disc;margin-left:20px;">
				<li><?php esc_html_e( 'Where the secret sits: the key is part of the link. OpenAI stores the full link with the connector in your ChatGPT account, and anyone who can see your connector settings there can see it.', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'The link is sent on every request, so web server, CDN, proxy and security-plugin access logs (nginx or Apache logs, Wordfence Live Traffic and similar) can record it in plain text. If those logs leak or are shared, make a new link.', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'This site doesn\'t keep a readable copy of the key, only a one-way hash.', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'mumcp.com is not involved: ChatGPT talks to this site directly.', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'Only users who can activate plugins (administrators) can create, replace or revoke this link.', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'Every change it makes is recorded in the AI Action Log: the tool, its arguments and which key made it. API requests also appear in the Activity Log (on by default).', 'mcpwp' ); ?></li>
				<li><?php esc_html_e( 'Make a new link or Revoke above to cut access on the next request.', 'mcpwp' ); ?></li>
			</ul>
		</div>

		<!-- ============================= SECTION 1: YOUR API KEY ============================= -->
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-admin-network"></span>
				<?php esc_html_e( 'Your API Key', 'mcpwp' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'This key authenticates AI assistants when they connect to your site. Generate one key per AI client so you can revoke access individually.', 'mcpwp' ); ?>
			</p>

			<?php if ( ! $site_has_key && ! ( isset( $new_key ) && $new_key ) ) : ?>
			<!-- No keys yet — prominent Generate button -->
			<div class="mcpwp-no-key-prompt">
				<p class="description"><strong><?php esc_html_e( 'No API key configured yet.', 'mcpwp' ); ?></strong> <?php esc_html_e( 'Generate one to start connecting AI tools to this site.', 'mcpwp' ); ?></p>
				<form method="post" class="mcpwp-regenerate-form">
					<?php wp_nonce_field( 'mcpwp_regenerate_key', 'mcpwp_nonce' ); ?>
					<button type="submit" name="mcpwp_regenerate_key" class="button button-primary button-hero">
						<span class="dashicons dashicons-plus-alt"></span>
						<?php esc_html_e( 'Generate API Key', 'mcpwp' ); ?>
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
					<?php esc_html_e( 'Copy', 'mcpwp' ); ?>
				</button>
				<?php endif; ?>
			</div>

			<?php if ( $is_hidden ) : ?>
			<p class="description">
				<?php esc_html_e( 'Your API key is stored securely (hashed). To see it again, regenerate a new one below.', 'mcpwp' ); ?>
			</p>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'A key revoked under Active Keys stays revoked, the primary key included. The old single-key setting is only used on a site that has never had role-based keys, and only to move that key into Active Keys.', 'mcpwp' ); ?>
			</p>

			<form method="post" class="mcpwp-regenerate-form" style="margin-top:10px;">
				<?php wp_nonce_field( 'mcpwp_regenerate_key', 'mcpwp_nonce' ); ?>
				<button type="submit" name="mcpwp_regenerate_key" class="button mcpwp-regenerate-btn">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Regenerate Key', 'mcpwp' ); ?>
				</button>
				<span class="description"><?php esc_html_e( 'This revokes every key on this site — the primary key, all role-based keys and the ChatGPT link — and makes one new primary key.', 'mcpwp' ); ?></span>
			</form>
			<?php endif; ?>

			<!-- Role-based API keys -->
			<hr style="margin:24px 0;" />
			<h3><?php esc_html_e( 'Create Role-Based Key', 'mcpwp' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Each role limits which MCP tools the AI can access. Use Designer for Elementor-only access, Editor for content, Author for drafts.', 'mcpwp' ); ?>
				<?php esc_html_e( 'Make one key per integration, labelled with what uses it (for example "Cursor — Hadi" or "n8n content flow"), with the narrowest role that works. Then you can revoke one without breaking the others, and you always know what a key is for.', 'mcpwp' ); ?>
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
					<?php esc_html_e( 'Copy Key', 'mcpwp' ); ?>
				</button>
			</div>
			<?php endif; ?>

			<form method="post" class="mcpwp-regenerate-form">
				<?php wp_nonce_field( 'mcpwp_manage_scoped_keys', 'mcpwp_scoped_keys_nonce' ); ?>
				<p>
					<label for="mcpwp_scoped_key_label"><strong><?php esc_html_e( 'Label', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_scoped_key_label" name="mcpwp_scoped_key_label" class="regular-text" placeholder="<?php esc_attr_e( 'Example: Content Writer Bot', 'mcpwp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_scoped_key_role"><strong><?php esc_html_e( 'Role', 'mcpwp' ); ?></strong></label><br />
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
					<strong><?php esc_html_e( 'Tool Categories', 'mcpwp' ); ?></strong><br />
					<p class="description" style="margin-bottom:6px;">
						<?php esc_html_e( 'Select which tool categories this key can access.', 'mcpwp' ); ?>
					</p>
					<?php foreach ( $all_cat_labels as $cat_slug => $cat_label ) : ?>
					<label style="display:inline-block;min-width:120px;margin:2px 0;">
						<input type="checkbox" name="mcpwp_scoped_key_categories[]" value="<?php echo esc_attr( $cat_slug ); ?>" class="mcpwp-category-checkbox" />
						<?php echo esc_html( $cat_label ); ?>
					</label>
					<?php endforeach; ?>
				</div>
				<div style="margin-bottom:12px;">
					<strong><?php esc_html_e( 'Permissions', 'mcpwp' ); ?></strong><br />
					<p class="description" style="margin-bottom:6px;">
						<?php esc_html_e( 'Uncheck Write to create a read-only key (safe for reporting/inspection bots).', 'mcpwp' ); ?>
					</p>
					<div style="display:flex;flex-wrap:wrap;gap:8px 16px;max-width:100%;">
						<label style="display:inline-block;">
							<input type="checkbox" name="mcpwp_scoped_key_scopes[]" value="read" checked="checked" disabled="disabled" />
							<input type="hidden" name="mcpwp_scoped_key_scopes[]" value="read" />
							<?php esc_html_e( 'Read', 'mcpwp' ); ?>
						</label>
						<label style="display:inline-block;">
							<input type="checkbox" name="mcpwp_scoped_key_scopes[]" value="write" checked="checked" />
							<?php esc_html_e( 'Write', 'mcpwp' ); ?>
						</label>
						<label style="display:inline-block;">
							<input type="checkbox" name="mcpwp_scoped_key_scopes[]" value="admin" checked="checked" />
							<?php esc_html_e( 'Admin ops', 'mcpwp' ); ?>
						</label>
					</div>
				</div>
				<div id="mcpwp-role-preview" style="margin-bottom:12px;padding:8px 12px;background:#f0f0f1;border-radius:4px;display:none;">
					<strong><?php esc_html_e( 'Access:', 'mcpwp' ); ?></strong>
					<span id="mcpwp-role-preview-categories"></span>
				</div>
				<button type="submit" name="mcpwp_create_scoped_key" class="button button-primary">
					<?php esc_html_e( 'Create API Key', 'mcpwp' ); ?>
				</button>
			</form>

			<!-- Active keys table -->
			<?php if ( ! empty( $scoped_keys ) ) : ?>
			<h3 style="margin-top:24px;"><?php esc_html_e( 'Active Keys', 'mcpwp' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Role', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Categories', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Created', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Last Used', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Action', 'mcpwp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $scoped_keys as $key ) :
						$key_role = isset( $key['role'] ) ? $key['role'] : 'admin';
						// #1041 NIT-1: list_scoped_api_keys() now resolves the sentinel
						// cases itself — 'deny_all' is explicit, and 'role' is never the
						// raw unknown_role_marker() (a malformed 'role' field, #1041
						// WARN-1, presents as the string 'unknown', which is not a real
						// role slug either — both need their own display branch here,
						// not the admin fallback below, or a deny-all key would show as
						// "Admin".
						$is_unknown_role = 'unknown' === $key_role;
						$role_def         = ( ! $is_unknown_role && isset( $role_definitions[ $key_role ] ) )
							? $role_definitions[ $key_role ]
							: array( 'label' => __( 'Unknown', 'mcpwp' ) );
						$badge_bg = $is_unknown_role ? '#8a1f11' : ( isset( $role_colors[ $key_role ] ) ? $role_colors[ $key_role ] : '#50575e' );
						$display_cats = array();
						if ( ! empty( $key['deny_all'] ) ) {
							$display_cats = array( __( 'None (all denied)', 'mcpwp' ) );
						} elseif ( 'admin' === $key_role ) {
							$display_cats = array( __( 'All', 'mcpwp' ) );
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
						<td>
							<strong><?php echo esc_html( $key['label'] ); ?></strong>
							<?php if ( 'spai' === ( $key['migrated_from'] ?? '' ) && 'admin' === $key_role && empty( $key['revoked_at'] ) ) : // #1098: adopted 2.8 admin key. ?>
							<p class="description mcpwp-migrated-key-note"><?php esc_html_e( 'Migrated from an older version — has full admin access. Create a new key with only what it needs, then revoke this one.', 'mcpwp' ); ?></p>
							<?php endif; ?>
						</td>
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
								<span class="mcpwp-status mcpwp-status-inactive"><?php esc_html_e( 'Revoked', 'mcpwp' ); ?></span>
							<?php else : ?>
								<span class="mcpwp-status mcpwp-status-active"><?php esc_html_e( 'Active', 'mcpwp' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( empty( $key['revoked_at'] ) ) : ?>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'mcpwp_manage_scoped_keys', 'mcpwp_scoped_keys_nonce' ); ?>
								<input type="hidden" name="mcpwp_scoped_key_id" value="<?php echo esc_attr( $key['id'] ); ?>" />
								<button type="submit" name="mcpwp_revoke_scoped_key" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Revoke this key?', 'mcpwp' ) ); ?>');">
									<?php esc_html_e( 'Revoke', 'mcpwp' ); ?>
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
				<?php esc_html_e( 'Connect Your AI', 'mcpwp' ); ?>
			</h2>
			<p class="description">
				<?php
				if ( $is_hidden ) {
					esc_html_e( 'Your key is saved. Generate a new one above to auto-fill this config.', 'mcpwp' );
				} else {
					esc_html_e( 'Copy the config for your AI client and paste it in. Your API key has been pre-filled below.', 'mcpwp' );
				}
				?>
			</p>

			<nav class="nav-tab-wrapper mcpwp-tabs mcpwp-tabs--inner" id="mcpwp-connect-tabs">
				<a href="#" class="nav-tab nav-tab-active mcpwp-inner-tab" data-tab="claude-code"><?php esc_html_e( 'Claude Code', 'mcpwp' ); ?></a>
				<a href="#" class="nav-tab mcpwp-inner-tab" data-tab="claude-desktop"><?php esc_html_e( 'Claude Desktop', 'mcpwp' ); ?></a>
				<a href="#" class="nav-tab mcpwp-inner-tab" data-tab="cursor"><?php esc_html_e( 'Cursor', 'mcpwp' ); ?></a>
				<a href="#" class="nav-tab mcpwp-inner-tab" data-tab="windsurf"><?php esc_html_e( 'Windsurf', 'mcpwp' ); ?></a>
			</nav>

			<!-- Claude Code -->
			<div class="mcpwp-inner-tab-content" id="mcpwp-tab-claude-code">
				<p><?php esc_html_e( 'Add to .mcp.json in your project root or ~/.claude.json for global access:', 'mcpwp' ); ?></p>
				<div class="mcpwp-code-wrapper">
					<pre class="mcpwp-code-block" id="mcpwp-claude-code-cfg">{
  "mcpServers": {
    "mcpwp-<?php echo esc_html( $site_slug ); ?>": {
      "type": "http",
      "url": "<?php echo esc_url( $mcp_url ); ?>",
      "headers": {
        "X-API-Key": "<?php echo $is_hidden ? 'YOUR_API_KEY' : esc_attr( $display_key ); ?>"
      }
    }
  }
}</pre>
					<button type="button" class="button mcpwp-copy-code-btn" data-target="mcpwp-claude-code-cfg">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy', 'mcpwp' ); ?>
					</button>
				</div>
			</div>

			<!-- Claude Desktop -->
			<div class="mcpwp-inner-tab-content" id="mcpwp-tab-claude-desktop" style="display:none;">

				<!-- Method 1: API key — works today -->
				<div class="mcpwp-connect-method">
					<h4 class="mcpwp-connect-method__title">
						<?php esc_html_e( 'API key', 'mcpwp' ); ?>
						<span class="mcpwp-status mcpwp-status-active"><?php esc_html_e( 'Works today · Accepted', 'mcpwp' ); ?></span>
					</h4>
					<p>
						<?php esc_html_e( 'In Claude Desktop go to Settings → Connectors → Add custom connector and paste this URL. Your API key is embedded, so the connection works immediately:', 'mcpwp' ); ?>
					</p>
					<div class="mcpwp-code-wrapper">
						<pre class="mcpwp-code-block" id="mcpwp-claude-desktop-url"><?php echo esc_url( add_query_arg( 'api_key', ( $is_hidden ? 'YOUR_API_KEY' : $display_key ), $mcp_url ) ); ?></pre>
						<button type="button" class="button button-primary mcpwp-copy-code-btn" data-target="mcpwp-claude-desktop-url">
							<span class="dashicons dashicons-clipboard"></span>
							<?php esc_html_e( 'Copy URL', 'mcpwp' ); ?>
						</button>
					</div>
					<?php if ( $is_hidden ) : ?>
					<p class="description">
						<?php esc_html_e( 'This shows a placeholder because your real key was only visible in the session it was generated. Regenerate a key above to get a working, key-filled URL.', 'mcpwp' ); ?>
					</p>
					<?php else : ?>
					<div class="notice notice-warning inline" style="margin:10px 0 0;">
						<p>
							<strong><?php esc_html_e( 'This link contains a secret — treat it like a password.', 'mcpwp' ); ?></strong>
							<?php esc_html_e( 'Use a role-scoped key for this connector and revoke it above if it ever leaks.', 'mcpwp' ); ?>
						</p>
					</div>
					<?php endif; ?>
					<p class="mcpwp-connect-method__hint">
						<?php esc_html_e( 'Prefer the key to never appear in a URL? The Claude Code and Cursor tabs send it as a request header instead — the safer option when your client supports it.', 'mcpwp' ); ?>
					</p>
				</div>

				<!-- Method 2: OAuth sign-in via the mumcp.com broker (advanced, off the default path) -->
				<details class="mcpwp-connect-method"<?php echo $broker_enrolled ? ' open' : ''; ?>>
					<summary><strong><?php esc_html_e( 'Advanced: OAuth sign-in via mumcp.com', 'mcpwp' ); ?></strong></summary>
					<h4 class="mcpwp-connect-method__title">
						<?php esc_html_e( 'OAuth sign-in', 'mcpwp' ); ?>
						<?php if ( $broker_enrolled ) : ?>
							<span class="mcpwp-status mcpwp-status-active"><?php esc_html_e( 'Connected', 'mcpwp' ); ?></span>
						<?php else : ?>
							<span class="mcpwp-status mcpwp-status-info"><?php esc_html_e( 'Via mumcp.com', 'mcpwp' ); ?></span>
						<?php endif; ?>
					</h4>
					<p class="description">
						<?php esc_html_e( 'Optional, for advanced setups. Sign-in goes through a separate mumcp.com account (an emailed link), not your WordPress login, and only the mumcp.com account that connected this site can authorize clients. For ChatGPT, use Add to ChatGPT at the top of this page instead.', 'mcpwp' ); ?>
					</p>

					<?php if ( 'connected' === $broker_notice ) : ?>
						<div class="notice notice-success inline" style="margin:10px 0;">
							<p><?php esc_html_e( 'Connected to mumcp.com. OAuth sign-in is now available for this site.', 'mcpwp' ); ?></p>
						</div>
					<?php elseif ( 'disconnected' === $broker_notice ) : ?>
						<div class="notice notice-success inline" style="margin:10px 0;">
							<p><?php esc_html_e( 'Disconnected from mumcp.com.', 'mcpwp' ); ?></p>
						</div>
					<?php endif; ?>

					<?php if ( $broker_enrolled ) : ?>
						<p>
							<strong><?php esc_html_e( 'Connected', 'mcpwp' ); ?></strong>
							<?php if ( '' !== $broker_audience ) : ?>
								&middot; <code><?php echo esc_html( $broker_audience ); ?></code>
							<?php endif; ?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mcpwp_broker_disconnect" />
							<?php wp_nonce_field( 'mcpwp_broker_disconnect', 'mcpwp_broker_nonce' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'mcpwp' ); ?></button>
						</form>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mcpwp_broker_connect" />
							<?php wp_nonce_field( 'mcpwp_broker_connect', 'mcpwp_broker_nonce' ); ?>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect to mumcp.com', 'mcpwp' ); ?></button>
						</form>
					<?php endif; ?>
				</details>

				<p><?php esc_html_e( 'Or add to claude_desktop_config.json (Claude Desktop config supports only command-based servers, so the URL goes through the mcp-remote bridge):', 'mcpwp' ); ?></p>
				<div class="mcpwp-code-wrapper">
					<pre class="mcpwp-code-block" id="mcpwp-claude-desktop-cfg">{
  "mcpServers": {
    "mcpwp-<?php echo esc_html( $site_slug ); ?>": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "<?php echo esc_url( $mcp_url ); ?>",
        "--header",
        "X-API-Key:<?php echo $is_hidden ? 'YOUR_API_KEY' : esc_attr( $display_key ); ?>"
      ]
    }
  }
}</pre>
					<button type="button" class="button mcpwp-copy-code-btn" data-target="mcpwp-claude-desktop-cfg">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy', 'mcpwp' ); ?>
					</button>
				</div>
			</div>

			<!-- Cursor -->
			<div class="mcpwp-inner-tab-content" id="mcpwp-tab-cursor" style="display:none;">
				<p><?php esc_html_e( 'In Cursor go to Settings → MCP and add a new server:', 'mcpwp' ); ?></p>
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
						<?php esc_html_e( 'Copy', 'mcpwp' ); ?>
					</button>
				</div>
			</div>

			<!-- Windsurf -->
			<div class="mcpwp-inner-tab-content" id="mcpwp-tab-windsurf" style="display:none;">
				<p><?php esc_html_e( 'Add to your Windsurf MCP config (~/.codeium/windsurf/mcp_config.json):', 'mcpwp' ); ?></p>
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
						<?php esc_html_e( 'Copy', 'mcpwp' ); ?>
					</button>
				</div>
			</div>

		</div>

		<!-- ============================= SECTION 3: CONNECTION STATUS ============================= -->
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Connection Status', 'mcpwp' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Verify that the REST API is working and the plugin is reachable.', 'mcpwp' ); ?>
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
					<span class="mcpwp-library-stat__label"><?php $last_activity_time ? esc_html_e( 'Activity Detected', 'mcpwp' ) : esc_html_e( 'No Activity Yet', 'mcpwp' ); ?></span>
				</div>
				<?php if ( $last_activity_time ) : ?>
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value" style="font-size:13px;"><?php echo esc_html( $last_activity_time ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Last API Call', 'mcpwp' ); ?></span>
				</div>
				<?php endif; ?>
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value"><?php echo esc_html( MCPWP_VERSION ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Plugin Version', 'mcpwp' ); ?></span>
				</div>
			</div>

			<div class="mcpwp-test-connection">
				<button type="button" class="button button-primary" id="mcpwp-test-btn">
					<span class="dashicons dashicons-yes-alt"></span>
					<?php esc_html_e( 'Test Connection', 'mcpwp' ); ?>
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
								esc_html__( 'Update available: v%1$s → v%2$s.', 'mcpwp' ),
								esc_html( $update_channel['current_version'] ),
								esc_html( $update_channel['remote_version'] )
						);
						?>
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-primary" style="margin-left:8px;"><?php esc_html_e( 'Update Now', 'mcpwp' ); ?></a>
						<a href="<?php echo esc_url( $update_channel['download_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button" style="margin-left:4px;"><?php esc_html_e( 'Download ZIP', 'mcpwp' ); ?></a>
					</p>
				</div>
				<?php else : ?>
				<div class="notice notice-success inline" style="margin-bottom:12px;">
					<p>
						<?php esc_html_e( 'You are running the latest version.', 'mcpwp' ); ?>
						<strong>v<?php echo esc_html( MCPWP_VERSION ); ?></strong>
					</p>
				</div>
				<?php endif; ?>
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( 'mcpwp_check_update', 'mcpwp_update_nonce' ); ?>
					<button type="submit" name="mcpwp_force_update_check" class="button">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Check for Updates', 'mcpwp' ); ?>
					</button>
				</form>
				<p class="description" style="margin-top:8px;">
					<?php esc_html_e( 'Update channel:', 'mcpwp' ); ?>
					<code><?php echo esc_html( $update_channel['manifest_url'] ); ?></code>
					<br /><span class="description"><?php esc_html_e( 'This is the URL MCPWP checks for new versions. It should point to the updates.mcpwp.net manifest (the legacy mumega.com manifest also still works).', 'mcpwp' ); ?></span>
				</p>
			</div>
			<?php endif; // ! MCPWP_WPORG_BUILD ?>
		</div>

		<!-- ============================= ADVANCED ============================= -->
		<div class="mcpwp-card" id="mcpwp-setup-advanced">
			<h2>
				<span class="dashicons dashicons-admin-generic"></span>
				<?php esc_html_e( 'Advanced', 'mcpwp' ); ?>
			</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Mcpwp_OAuth_Local_Admin::TOGGLE_ACTION ); ?>" />
				<?php wp_nonce_field( Mcpwp_OAuth_Local_Admin::TOGGLE_ACTION, Mcpwp_OAuth_Local_Admin::NONCE_FIELD ); ?>
				<input type="hidden" name="mcpwp_local_oauth" value="0" />
				<label>
					<input type="checkbox" name="mcpwp_local_oauth" value="1" <?php checked( $local_oauth_enabled ); ?> />
					<strong><?php esc_html_e( 'Allow AI apps to connect by signing in with WordPress (OAuth)', 'mcpwp' ); ?></strong>
				</label>
				<p class="description">
					<?php esc_html_e( 'An app such as ChatGPT or Claude sends you to this site\'s WordPress login and a consent screen. It acts as the WordPress user who approved it, with read and write access at most, never administrator access, and only for this site\'s MCP endpoint. Links and API keys keep working either way.', 'mcpwp' ); ?>
				</p>
				<p>
					<label for="mcpwp_local_oauth_min_role"><strong><?php esc_html_e( 'Who may connect an AI app', 'mcpwp' ); ?></strong></label><br />
					<select id="mcpwp_local_oauth_min_role" name="mcpwp_local_oauth_min_role">
						<?php
						$mcpwp_min_role_labels = array(
							'author'        => __( 'Authors and above', 'mcpwp' ),
							'editor'        => __( 'Editors and above (default)', 'mcpwp' ),
							'administrator' => __( 'Administrators only', 'mcpwp' ),
						);
						foreach ( $mcpwp_min_role_labels as $mcpwp_role => $mcpwp_label ) :
							?>
							<option value="<?php echo esc_attr( $mcpwp_role ); ?>" <?php selected( Mcpwp_OAuth_Well_Known::get_min_role(), $mcpwp_role ); ?>><?php echo esc_html( $mcpwp_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'mcpwp' ); ?></button></p>
			</form>
			<?php Mcpwp_OAuth_Apps_Admin::render_clients_list(); ?>
		</div>

		<!-- ============================= SECTION 4: QUICK LINKS ============================= -->
		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-admin-links"></span>
				<?php esc_html_e( 'Quick Links', 'mcpwp' ); ?>
			</h2>
			<ul class="mcpwp-quick-links">
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Mcpwp_Admin::LIBRARY_PAGE_SLUG ) ); ?>" class="button">
						<span class="dashicons dashicons-screenoptions"></span>
						<?php esc_html_e( 'Library', 'mcpwp' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Archetypes, reusable parts, and design references.', 'mcpwp' ); ?></span>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Mcpwp_Tools_Admin::PAGE_SLUG ) ); ?>" class="button">
						<span class="dashicons dashicons-admin-tools"></span>
						<?php esc_html_e( 'Tools', 'mcpwp' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Enable or disable MCP tool categories.', 'mcpwp' ); ?></span>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Mcpwp_Admin::ACTIVITY_LOG_PAGE_SLUG ) ); ?>" class="button">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'Activity Log', 'mcpwp' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Full history of API calls from connected AI tools.', 'mcpwp' ); ?></span>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Mcpwp_Admin::SETTINGS_PAGE_SLUG ) ); ?>" class="button">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php esc_html_e( 'Settings', 'mcpwp' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Rate limits, site context, logging, and more.', 'mcpwp' ); ?></span>
				</li>
				<li>
					<a href="https://mcpwp.net/docs/" target="_blank" rel="noopener noreferrer" class="button">
						<span class="dashicons dashicons-book"></span>
						<?php esc_html_e( 'Documentation', 'mcpwp' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Full API reference and MCPWP guides.', 'mcpwp' ); ?></span>
				</li>
			</ul>
		</div>

	</div><!-- .mcpwp-setup-page -->
</div><!-- .wrap -->
