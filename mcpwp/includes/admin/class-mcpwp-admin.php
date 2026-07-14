<?php
/**
 * Admin functionality
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class.
 */
class Mcpwp_Admin {

	use Mcpwp_Api_Auth;

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'mcpwp';

	/**
	 * Activity log page slug.
	 *
	 * @var string
	 */
	const ACTIVITY_LOG_PAGE_SLUG = 'mcpwp-activity-log';

	/**
	 * Control room page slug.
	 *
	 * @var string
	 */
	const CONTROL_ROOM_PAGE_SLUG = 'mcpwp-control-room';

	/**
	 * SVG icon for menu (base64 encoded).
	 *
	 * @var string
	 */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iIzJGN0NGRiIgZD0iTTExLjUgMS44IDQuOCAxMGg0bC0xLjMgOC4yIDctOWgtNC4xbDEuMS03LjRaIi8+PHBhdGggZmlsbD0iIzI3QzQ2QSIgZD0iTTEzLjcgMTIuOGEyLjIgMi4yIDAgMSAwIDAgNC40IDIuMiAyLjIgMCAwIDAgMC00LjRabTAgMS40YS44LjggMCAxIDEgMCAxLjYuOC44IDAgMCAxIDAtMS42WiIvPjwvc3ZnPg==';

	/**
	 * Library page slug.
	 *
	 * @var string
	 */
	const LIBRARY_PAGE_SLUG = 'mcpwp-library';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const SETTINGS_PAGE_SLUG = 'mcpwp-settings';

	/**
	 * Add admin menu - top-level with icon.
	 */
	use Mcpwp_Admin_Setup_Trait;
	use Mcpwp_Admin_Control_Room_Trait;
	use Mcpwp_Admin_Chat_Trait;
	use Mcpwp_Admin_Library_Trait;
	use Mcpwp_Admin_Settings_Trait;
	use Mcpwp_Admin_Activity_Trait;

	public function add_admin_menu() {
		// Top-level menu + default submenu (Setup) share the same slug so
		// clicking "MCPWP" always lands on the Setup page.
		add_menu_page(
			__( 'MCPWP', 'mcpwp' ),
			__( 'MCPWP', 'mcpwp' ),
			'activate_plugins',
			self::PAGE_SLUG,
			array( $this, 'render_setup_page' ),
			self::MENU_ICON,
			80
		);

		// Setup — same slug as parent so it becomes the first visible item.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Setup', 'mcpwp' ),
			__( 'Setup', 'mcpwp' ),
			'activate_plugins',
			self::PAGE_SLUG,
			array( $this, 'render_setup_page' )
		);

		// Control Room - human supervision for agent work.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Control Room', 'mcpwp' ),
			__( 'Control Room', 'mcpwp' ),
			'activate_plugins',
			self::CONTROL_ROOM_PAGE_SLUG,
			array( $this, 'render_control_room_page' )
		);

		// Chat — AI assistant.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Chat', 'mcpwp' ),
			__( 'Chat', 'mcpwp' ),
			'edit_posts',
			'mcpwp-chat',
			array( $this, 'render_chat_page' )
		);

		// Library.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Library', 'mcpwp' ),
			__( 'Library', 'mcpwp' ),
			'activate_plugins',
			self::LIBRARY_PAGE_SLUG,
			array( $this, 'render_library_page' )
		);

		// Integrations (already exists).
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Integrations', 'mcpwp' ),
			__( 'Integrations', 'mcpwp' ),
			'activate_plugins',
			Mcpwp_Integrations_Admin::PAGE_SLUG,
			array( new Mcpwp_Integrations_Admin(), 'render' )
		);

		// Tools (renamed from "MCP Tools").
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Tools', 'mcpwp' ),
			__( 'Tools', 'mcpwp' ),
			'activate_plugins',
			Mcpwp_Tools_Admin::PAGE_SLUG,
			array( new Mcpwp_Tools_Admin(), 'render' )
		);

		// Settings.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', 'mcpwp' ),
			__( 'Settings', 'mcpwp' ),
			'activate_plugins',
			self::SETTINGS_PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);

		// Activity Log.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Activity Log', 'mcpwp' ),
			__( 'Activity Log', 'mcpwp' ),
			'activate_plugins',
			self::ACTIVITY_LOG_PAGE_SLUG,
			array( $this, 'render_activity_log_page' )
		);

	}

	/**
	 * Get the current MCPWP admin page slug.
	 *
	 * @return string
	 */
	private function get_current_admin_page_slug() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing value.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Determine whether the current admin request is an MCPWP screen.
	 *
	 * @return bool
	 */
	private function is_mcpwp_admin_page() {
		$page = $this->get_current_admin_page_slug();

		return in_array(
			$page,
			array(
				self::PAGE_SLUG,
				self::CONTROL_ROOM_PAGE_SLUG,
				'mcpwp-chat',
				self::LIBRARY_PAGE_SLUG,
				Mcpwp_Integrations_Admin::PAGE_SLUG,
				Mcpwp_Tools_Admin::PAGE_SLUG,
				self::SETTINGS_PAGE_SLUG,
				self::ACTIVITY_LOG_PAGE_SLUG,
			),
			true
		);
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_styles( $hook ) {
		if ( ! $this->is_mcpwp_admin_page() ) {
			return;
		}

		wp_enqueue_style(
			'mcpwp-admin',
			MCPWP_PLUGIN_URL . 'admin/css/mcpwp-admin.css',
			array(),
			MCPWP_VERSION
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * Fires on ALL MCPWP admin pages under one canonical handle ('mcpwp-admin').
	 * All PHP→JS data is passed via wp_localize_script — no inline <script> blocks.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		if ( ! $this->is_mcpwp_admin_page() ) {
			return;
		}

		wp_enqueue_script(
			'mcpwp-admin',
			MCPWP_PLUGIN_URL . 'admin/js/mcpwp-admin.js',
			array( 'jquery' ),
			MCPWP_VERSION,
			true
		);

		$posthog       = Mcpwp_Integration_Manager::get_instance()->get_posthog_config();
		$posthog_token = $posthog['token'];
		$posthog_host  = $posthog['host'];

		// Integrations nonce only needed on the Integrations page, but generating
		// it on every MCPWP page is harmless and keeps one unified data object.
		wp_localize_script(
			'mcpwp-admin',
			'mcpwpAdmin',
			array(
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'mcpwp_admin_nonce' ),
				'integrationsNonce'    => wp_create_nonce( 'mcpwp_integrations_nonce' ),
				'toolsNonce'           => wp_create_nonce( 'mcpwp_tools_nonce' ),
				'restUrl'              => rest_url( 'mcpwp/v1/' ),
				'siteUrl'              => site_url(),
				'posthogToken'         => $posthog_token,
				'posthogHost'          => $posthog_host,
				// Category labels for the role-based key UI on the Setup page.
				'catLabels'            => self::get_all_tool_category_labels(),
				'chatGreeting'         => sprintf(
					/* translators: %s: site name */
					__( 'Hi! I can help you manage %s. Try: "Build a services page" or "List all pages" or "Add a testimonials section to the homepage."', 'mcpwp' ),
					get_bloginfo( 'name' )
				),
				'streamOk'             => ( function () {
					$manager    = Mcpwp_Integration_Manager::get_instance();
					$chat_model = get_option( 'mcpwp_chat_model', 'auto' );
					$has_openai = ! empty( $manager->get_provider_key( 'openai' ) );
					return ( $has_openai && in_array( $chat_model, array( 'openai', 'auto' ), true ) );
				} )(),
				'strings'              => array(
					'copied'             => __( 'Copied!', 'mcpwp' ),
					'copyFailed'         => __( 'Copy failed', 'mcpwp' ),
					'confirm'            => __( 'Are you sure you want to regenerate the API key? The old key will stop working immediately.', 'mcpwp' ),
					'testing'            => __( 'Testing...', 'mcpwp' ),
					'connected'          => __( 'Connected!', 'mcpwp' ),
					'testFailed'         => __( 'Connection failed', 'mcpwp' ),
					'saving'             => __( 'Saving...', 'mcpwp' ),
					'saved'              => __( 'Saved!', 'mcpwp' ),
					'saveFailed'         => __( 'Save failed', 'mcpwp' ),
					'removing'           => __( 'Removing...', 'mcpwp' ),
					'removed'            => __( 'Removed!', 'mcpwp' ),
					'requestFailed'      => __( 'Request failed', 'mcpwp' ),
					'confirmRemove'      => __( 'Are you sure you want to remove this API key?', 'mcpwp' ),
					'fillOneField'       => __( 'Please fill in at least one field.', 'mcpwp' ),
					'enterApiKey'        => __( 'Please enter an API key.', 'mcpwp' ),
					'revokeKey'          => __( 'Revoke this key?', 'mcpwp' ),
					'clearHistory'       => __( 'Clear all chat history?', 'mcpwp' ),
					'yesProceed'         => __( 'Yes, proceed', 'mcpwp' ),
					'cancel'             => __( 'Cancel', 'mcpwp' ),
					'allCategoriesLabel' => __( 'All categories (unrestricted)', 'mcpwp' ),
				),
			)
		);
	}

	/**
	 * Handle AJAX test connection.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'mcpwp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		// Verify REST API is reachable by checking site info directly.
		// We don't use rest_do_request() because the permission_callback
		// requires an API key header which isn't present in admin AJAX.
		$rest_url = rest_url( 'mcpwp/v1/' );

		// Check that the API key exists.
		$stored_key = get_option( 'mcpwp_api_key' );
		if ( empty( $stored_key ) ) {
			wp_send_json_error( array(
				'message' => __( 'No API key configured. Please generate one on the Setup tab.', 'mcpwp' ),
			) );
		}

		// Gather site info directly (same data the REST endpoint returns).
		global $wp_version;
		$site_name = get_bloginfo( 'name' );

		wp_send_json_success( array(
			'site_name'      => $site_name,
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'plugin_version' => MCPWP_VERSION,
			'rest_url'       => $rest_url,
		) );
	}

	/**
	 * Handle AJAX dismiss welcome.
	 */
	public function ajax_dismiss_welcome() {
		check_ajax_referer( 'mcpwp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		delete_option( 'mcpwp_first_activation' );
		delete_transient( 'mcpwp_new_api_key' );
		wp_send_json_success();
	}

	/**
	 * Display admin notices.
	 */
	public function admin_notices() {
		settings_errors( 'mcpwp_messages' );
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
			__( 'Settings', 'mcpwp' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add network admin menu page for multisite.
	 */
	public function add_network_admin_menu() {
		add_menu_page(
			__( 'MCPWP — Network', 'mcpwp' ),
			__( 'MCPWP', 'mcpwp' ),
			'manage_network_plugins',
			'mcpwp-network',
			array( $this, 'render_network_admin_page' ),
			self::MENU_ICON,
			80
		);
	}

	/**
	 * Handle "Setup All Sites" POST action from network admin page.
	 */
	private function handle_network_setup_all() {
		if ( ! isset( $_POST['mcpwp_network_setup_all'] ) ) {
			return;
		}

		check_admin_referer( 'mcpwp_network_setup_all', 'mcpwp_network_nonce' );

		if ( ! current_user_can( 'manage_network_plugins' ) ) {
			return;
		}

		require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-activator.php';

		$sites = get_sites( array( 'fields' => 'ids' ) );
		$count = 0;

		foreach ( $sites as $blog_id ) {
			switch_to_blog( $blog_id );
			Mcpwp_Activator::activate();
			$count++;
			restore_current_blog();
		}

		add_settings_error(
			'mcpwp_network_messages',
			'mcpwp_network_setup_done',
			sprintf(
				/* translators: %d: number of sites */
				__( 'MCPWP activated on %d site(s).', 'mcpwp' ),
				$count
			),
			'updated'
		);
	}

	/**
	 * Render the network admin page.
	 */
	public function render_network_admin_page() {
		if ( ! current_user_can( 'manage_network_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcpwp' ) );
		}

		$this->handle_network_setup_all();

		$sites      = get_sites( array( 'number' => 500 ) );
		$site_data  = array();

		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );

			$version        = get_option( 'mcpwp_version', '' );
			$has_api_key    = ! empty( get_option( 'mcpwp_api_key' ) );
			$scoped_keys    = get_option( 'mcpwp_api_keys', array() );
			$active_keys    = 0;
			if ( is_array( $scoped_keys ) ) {
				foreach ( $scoped_keys as $key ) {
					if ( empty( $key['revoked_at'] ) ) {
						$active_keys++;
					}
				}
			}

			$tool_count = 0;
			if ( class_exists( 'Mcpwp_MCP_Tool_Registry' ) ) {
				$registry   = new Mcpwp_MCP_Tool_Registry();
				$tool_count = count( $registry->get_all_tools() );
			}

			$site_data[] = array(
				'blog_id'     => $site->blog_id,
				'blogname'    => get_option( 'blogname', $site->domain . $site->path ),
				'siteurl'     => get_option( 'siteurl' ),
				'version'     => $version,
				'has_api_key' => $has_api_key,
				'active_keys' => $active_keys,
				'tool_count'  => $tool_count,
			);

			restore_current_blog();
		}

		settings_errors( 'mcpwp_network_messages' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MCPWP — Network Overview', 'mcpwp' ); ?></h1>

			<form method="post">
				<?php wp_nonce_field( 'mcpwp_network_setup_all', 'mcpwp_network_nonce' ); ?>
				<p>
					<input type="submit" name="mcpwp_network_setup_all" class="button button-primary"
						value="<?php esc_attr_e( 'Setup All Sites', 'mcpwp' ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Run activation (tables, options, bot user) on every site in the network?', 'mcpwp' ) ); ?>');" />
					<span class="description"><?php esc_html_e( 'Runs activation on every site to ensure tables, options, and the bot user are provisioned.', 'mcpwp' ); ?></span>
				</p>
			</form>

			<table class="widefat striped" style="margin-top:20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Site', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'URL', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Version', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'API Key', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Active Keys', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Tools', 'mcpwp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $site_data as $s ) : ?>
					<tr>
						<td><?php echo esc_html( $s['blog_id'] ); ?></td>
						<td><?php echo esc_html( $s['blogname'] ); ?></td>
						<td><a href="<?php echo esc_url( $s['siteurl'] ); ?>" target="_blank"><?php echo esc_html( $s['siteurl'] ); ?></a></td>
						<td>
							<?php if ( $s['version'] ) : ?>
								<?php echo esc_html( $s['version'] ); ?>
							<?php else : ?>
								<span style="color:#b32d2e;"><?php esc_html_e( 'Not activated', 'mcpwp' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $s['has_api_key'] ) : ?>
								<span style="color:#00a32a;">&#10003;</span>
							<?php else : ?>
								<span style="color:#b32d2e;">&#10007;</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $s['active_keys'] ); ?></td>
						<td><?php echo esc_html( $s['tool_count'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Get capabilities for display.
	 *
	 * @return array Capabilities.
	 */
	public function get_capabilities_display() {
		$core = new Mcpwp_Core();
		$capabilities = $core->get_capabilities();

		$display = array();

		// Elementor
		$display['elementor'] = array(
			'label'  => __( 'Elementor', 'mcpwp' ),
			'active' => $capabilities['elementor'],
			'pro'    => $capabilities['elementor_pro'],
		);

		// SEO
		$seo_active = $capabilities['yoast'] || $capabilities['rankmath'] || $capabilities['aioseo'] || $capabilities['seopress'];
		$seo_name = '';
		if ( $capabilities['yoast'] ) {
			$seo_name = 'Yoast SEO';
		} elseif ( $capabilities['rankmath'] ) {
			$seo_name = 'RankMath';
		} elseif ( $capabilities['aioseo'] ) {
			$seo_name = 'All in One SEO';
		} elseif ( $capabilities['seopress'] ) {
			$seo_name = 'SEOPress';
		}
		$display['seo'] = array(
			'label'  => __( 'SEO Plugin', 'mcpwp' ),
			'active' => $seo_active,
			'name'   => $seo_name,
		);

		// Forms
		$forms_active = $capabilities['cf7'] || $capabilities['wpforms'] || $capabilities['gravityforms'] || $capabilities['ninjaforms'];
		$forms = array();
		if ( $capabilities['cf7'] ) {
			$forms[] = 'CF7';
		}
		if ( $capabilities['wpforms'] ) {
			$forms[] = 'WPForms';
		}
		if ( $capabilities['gravityforms'] ) {
			$forms[] = 'Gravity Forms';
		}
		if ( $capabilities['ninjaforms'] ) {
			$forms[] = 'Ninja Forms';
		}
		$display['forms'] = array(
			'label'  => __( 'Form Plugins', 'mcpwp' ),
			'active' => $forms_active,
			'names'  => $forms,
		);

		// WooCommerce
		$display['woocommerce'] = array(
			'label'  => __( 'WooCommerce', 'mcpwp' ),
			'active' => $capabilities['woocommerce'],
		);

		return $display;
	}

	/**
	 * Build the WooCommerce archetype controller used by admin actions.
	 *
	 * @return Mcpwp_REST_WooCommerce|WP_Error
	 */
	private function get_woocommerce_archetype_controller() {
		if ( ! class_exists( 'Mcpwp_REST_WooCommerce' ) || ! class_exists( 'Mcpwp_WooCommerce' ) ) {
			return new WP_Error( 'missing_woocommerce_controller', __( 'WooCommerce archetype tools are not available.', 'mcpwp' ) );
		}

		$handler = new Mcpwp_WooCommerce();
		if ( ! $handler->is_active() ) {
			return new WP_Error( 'wc_not_active', __( 'WooCommerce is not active on this site.', 'mcpwp' ) );
		}

		return new Mcpwp_REST_WooCommerce( $handler );
	}
}
