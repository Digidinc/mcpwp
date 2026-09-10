<?php
/**
 * MCPWP
 *
 * Connect WordPress to AI assistants via the Model Context Protocol (MCP).
 * Expose your WordPress site's functionality to AI assistants like Claude.
 *
 * @package           MCPWP
 * @author            Mumega
 * @copyright         2026 Mumega
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       MCPWP
 * Plugin URI:        https://mcpwp.net/
 * Description:       Connect WordPress to AI assistants via the Model Context Protocol (MCP). Manage posts, pages, media, and Elementor through natural language.
 * x-release-please-start-version
 * Version:           3.11.0
 * x-release-please-end
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Mumega
 * Author URI:        https://mumega.com/
 * Text Domain:       mcpwp
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuse to bootstrap twice.
 *
 * Two copies of this plugin can be active at once — most often `mcpwp/` and
 * `mcpwp-premium/`, which ship the same constants under different basenames.
 * When that happened the second load re-ran every bare define() below, and PHP
 * emitted "already defined" warnings on a request that had not yet sent its
 * headers. The REST response then went out as HTTP 200 text/html — warning
 * markup, including absolute server paths, followed by the JSON that was
 * supposed to be a clean 401. A permission check that correctly denied the call
 * was delivered as a success (#660).
 *
 * Guarding each define() individually would silence the warnings and leave the
 * real problem — a second, partial bootstrap registering hooks over the first —
 * running unobserved. So the whole file is a no-op on a second load instead,
 * first copy wins, and the fact is recorded rather than swallowed: a site
 * running two copies must be able to find out, which is the whole reason this
 * cost a live incident. `mcpwp_double_load` holds the losing basename and when
 * it was last seen.
 */
if ( defined( 'MCPWP_VERSION' ) ) {
	if ( function_exists( 'update_option' ) ) {
		update_option(
			'mcpwp_double_load',
			array(
				'ignored_basename' => plugin_basename( __FILE__ ),
				'active_version'   => MCPWP_VERSION,
				'last_seen'        => time(),
			),
			false
		);
	}

	return;
}

/**
 * Plugin version.
 */
define( 'MCPWP_VERSION', '3.11.0' ); // x-release-please-version

/**
 * Whether custom MCP tool scope filtering is supported.
 */
define( 'MCPWP_SUPPORTS_CUSTOM_TOOL_SCOPE_FILTER', true );

/**
 * Whether trusted custom-tool callback handlers are supported.
 */
define( 'MCPWP_SUPPORTS_CUSTOM_TOOL_CALLBACKS', true );

/**
 * Plugin directory path.
 */
define( 'MCPWP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'MCPWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'MCPWP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum WordPress version.
 */
define( 'MCPWP_MIN_WP_VERSION', '6.1' );

/**
 * Minimum PHP version.
 */
define( 'MCPWP_MIN_PHP_VERSION', '7.4' );

/**
 * Default PostHog values.
 */
if ( ! defined( 'MCPWP_POSTHOG_DEFAULT_HOST' ) ) {
	define( 'MCPWP_POSTHOG_DEFAULT_HOST', 'https://us.i.posthog.com' );
}

/**
 * Read an environment variable with fallback.
 *
 * @param string $name    Environment key.
 * @param string $default Fallback value.
 * @return string
 */
if ( ! function_exists( 'mcpwp_env_var' ) ) {
	function mcpwp_env_var( $name, $default = '' ) {
		$source = array( getenv( $name ) );
		if ( isset( $_ENV[ $name ] ) ) {
			$source[] = $_ENV[ $name ];
		}
		if ( isset( $_SERVER[ $name ] ) ) {
			$source[] = $_SERVER[ $name ];
		}

		foreach ( $source as $value ) {
			if ( is_string( $value ) ) {
				$value = trim( $value );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return $default;
	}
}

/**
 * Check requirements before loading.
 *
 * @return bool True if requirements met.
 */
if ( ! function_exists( 'mcpwp_requirements_met' ) ) {
	function mcpwp_requirements_met() {
	global $wp_version;

	if ( version_compare( PHP_VERSION, MCPWP_MIN_PHP_VERSION, '<' ) ) {
		add_action( 'admin_notices', 'mcpwp_php_version_notice' );
		return false;
	}

	if ( version_compare( $wp_version, MCPWP_MIN_WP_VERSION, '<' ) ) {
		add_action( 'admin_notices', 'mcpwp_wp_version_notice' );
		return false;
	}

	return true;
	}
}

/**
 * PHP version notice.
 */
if ( ! function_exists( 'mcpwp_php_version_notice' ) ) {
	function mcpwp_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: Required PHP version 2: Current PHP version */
				esc_html__( 'MCPWP requires PHP %1$s or higher. You are running PHP %2$s.', 'mcpwp' ),
				esc_html( MCPWP_MIN_PHP_VERSION ),
				esc_html( PHP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
	}
}

/**
 * WordPress version notice.
 */
if ( ! function_exists( 'mcpwp_wp_version_notice' ) ) {
	function mcpwp_wp_version_notice() {
	global $wp_version;
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: Required WP version 2: Current WP version */
				esc_html__( 'MCPWP requires WordPress %1$s or higher. You are running WordPress %2$s.', 'mcpwp' ),
				esc_html( MCPWP_MIN_WP_VERSION ),
				esc_html( $wp_version )
			);
			?>
		</p>
	</div>
	<?php
	}
}

/**
 * Load plugin files.
 */
if ( ! function_exists( 'mcpwp_load_plugin' ) ) {
	function mcpwp_load_plugin() {
	// Check requirements
	if ( ! mcpwp_requirements_met() ) {
		return;
	}

	// Load traits first
	require_once MCPWP_PLUGIN_DIR . 'includes/traits/trait-mcpwp-api-auth.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/traits/trait-mcpwp-sanitization.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/traits/trait-mcpwp-logging.php';

	// Load core classes
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-loader.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-i18n.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-activator.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-deactivator.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-rate-limiter.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-error-hints.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-security.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-webhooks.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-alerts.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-duplicate-copy.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-license.php';
	if ( defined( 'MCPWP_FREEMIUS_BUILD' ) && MCPWP_FREEMIUS_BUILD && file_exists( MCPWP_PLUGIN_DIR . 'includes/freemius-init.php' ) ) {
		require_once MCPWP_PLUGIN_DIR . 'includes/freemius-init.php';
	}
	// Self-updater excluded from WP.org builds — only loaded when present.
	if ( file_exists( MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-updater.php' ) ) {
		require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-updater.php';
	}
	// Guarded the same way as the updater: distribution builds prune files by
	// pattern, and a pruned require would be a fatal on activation.
	if ( file_exists( MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-update-channel-health.php' ) ) {
		require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-update-channel-health.php';
	}
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-ai-presence.php';

	// Load core functionality
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-core.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-idempotency-claim.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-post-fields.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-option-access.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-destructive-gate.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-posts.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-pages.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-media.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-drafts.php';
	// Loaded before the traits and every _elementor_data write path that calls
	// it — the single definition of which settings keys Elementor ignores.
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-public-route.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-elementor-page-settings.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-slug-search.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-elementor-registry.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-elementor-element-keys.php';
	// Elementor engine verb traits (mixed into Mcpwp_Elementor_Basic — G4 split).
	require_once MCPWP_PLUGIN_DIR . 'includes/core/traits/trait-mcpwp-elementor-reader.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/traits/trait-mcpwp-elementor-writer.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/traits/trait-mcpwp-elementor-validator.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/traits/trait-mcpwp-elementor-css.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-elementor-basic.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-elementor-widgets.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-elementor-data-audit.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-screenshot.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-guides.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-workflows.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-feedback.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-encryption.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-encryption-migration.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-integration-manager.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-design-references.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-event-store.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-site-state.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-agent-playbooks.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-content-coherence.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-approvals.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-action-log.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-seo-audit-store.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-seo-autofix.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-search-performance.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-woocommerce-seo.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-keyword-research.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-figma.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-mupot-connector.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-provider-openai.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-provider-gemini.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-provider-elevenlabs.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-provider-pexels.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-provider-cloudflare.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-provider-dataforseo.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-seo-visibility.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-seo-opportunities.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-seo-cache.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-cloudflare-cache-sync.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-analytics.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-white-label.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-site-memory.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-signals.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-site-blueprints.php';

	// Load MCP tool registries
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/class-mcpwp-mcp-tool-registry.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/class-mcpwp-integration.php';
	// Free-tool category trait groups (mixed into Mcpwp_MCP_Free_Tools — G2 split).
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-free-tools-site.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-free-tools-content.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-free-tools-media.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-free-tools-elementor.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-free-tools-blocks.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-free-tools-ops.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/class-mcpwp-mcp-free-tools.php';
	// Pro-tool category trait groups (mixed into Mcpwp_MCP_Pro_Tools — G2 split).
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-pro-tools-seo.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-pro-tools-forms.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-pro-tools-elementor.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-pro-tools-menus.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-pro-tools-commerce.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-pro-tools-network.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/traits/trait-mcpwp-pro-tools-staging.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/class-mcpwp-mcp-pro-tools.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/class-mcpwp-mcp-ai-integration.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/class-mcpwp-mcp-figma-integration.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/mcp/class-mcpwp-custom-tool-registry.php';

	// Load Pro modules only for non-WP.org builds with an active entitlement.
	$pro_bootstrap = MCPWP_PLUGIN_DIR . 'includes/pro/class-mcpwp-pro-bootstrap.php';
	$pro_active    = ! defined( 'MCPWP_WPORG_BUILD' )
		&& class_exists( 'Mcpwp_License' )
		&& Mcpwp_License::get_instance()->is_pro();
	if ( $pro_active && file_exists( $pro_bootstrap ) ) {
		require_once $pro_bootstrap;
		if ( class_exists( 'Mcpwp_Pro_Bootstrap' ) ) {
			Mcpwp_Pro_Bootstrap::init();
		}
	}

	// Load REST API
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-api.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-posts.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-pages.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-media.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-info.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-settings.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-content.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-taxonomy.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-custom-css.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-updates.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-library.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-network.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-cache.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-content-graph.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-seo-audit.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-seo-market.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-content-quality.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-menus.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-content.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-elementor.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-webhooks.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-screenshot.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-feedback.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-blocks.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-approvals.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-action-log.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-memory.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-signals.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-site-blueprints.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-mcp.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-batch.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-openapi.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-integrations.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-cloudflare.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-figma.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-oauth.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-broker.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-broker-auth.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-oauth-well-known.php';

	// Load admin
	// Admin page-area trait groups (mixed into Mcpwp_Admin — G3 split).
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/traits/trait-mcpwp-admin-setup.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/traits/trait-mcpwp-admin-control-room.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/traits/trait-mcpwp-admin-chat.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/traits/trait-mcpwp-admin-library.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/traits/trait-mcpwp-admin-settings.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/traits/trait-mcpwp-admin-activity.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/traits/trait-mcpwp-admin-mupot.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/class-mcpwp-admin.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/class-mcpwp-activity-log.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/class-mcpwp-settings.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/class-mcpwp-mupot-profile.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/class-mcpwp-integrations-admin.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/class-mcpwp-broker-admin.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/class-mcpwp-tools-admin.php';

	// Bridge migration: copy spai_* options + tables → mcpwp_* (idempotent).
	// Runs early, before REST routes are registered, so auth is available
	// on the first request after cutover.  Guarded by mcpwp_migrated_from_spai.
	// Wrapped in try/catch so a migration error never fatals the site.
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-migrate.php';
	try {
		Mcpwp_Migrate::run();
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP migration error (plugins_loaded): ' . $e->getMessage() );
		}
		// Log-and-continue — never fatal the site.
	} catch ( \Exception $e ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP migration error (plugins_loaded): ' . $e->getMessage() );
		}
	}

	// Encryption key migration: re-encrypt provider blobs from AUTH_SALT-derived
	// key → dedicated MCPWP key (non-destructive, idempotent, runs once).
	// Registered here (class already required above); fires on plugins_loaded prio 20.
	Mcpwp_Encryption_Migration::register();

	// Slug matching for simple listing searches. Gated per-query on a query
	// var, so hooking it globally changes nothing for queries that did not ask
	// (#785).
	Mcpwp_Slug_Search::register();

	// Check if database needs updating
	$installed_db_version = get_option( 'mcpwp_db_version', '0' );
	if ( version_compare( $installed_db_version, MCPWP_VERSION, '<' ) ) {
		require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-activator.php';
		Mcpwp_Activator::activate();
		update_option( 'mcpwp_db_version', MCPWP_VERSION );
	}

	// One-time purge of identity-less pending approvals — gated on its OWN flag,
	// not the version check above. A same-version deployment (e.g. 3.6.1 -> 3.6.1)
	// never enters the activate() branch, so relying on the version gate alone
	// would skip this security migration and leave a pre-fix pending approval
	// exploitable by a reconnected OAuth principal. Idempotent; after the first
	// run this is a single (cached) get_option() per load. Runs on plugins_loaded,
	// before REST routes, so the purge precedes any approve/apply request.
	if ( ! get_option( 'mcpwp_approvals_identity_migrated' ) ) {
		require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-activator.php';
		Mcpwp_Activator::migrate_identity_less_approvals();
	}

	// One-time reset of the destructive-approval switch. An early build of
	// the gate exposed destructive_requires_approval through the settings
	// API; a principal that flipped it off then would keep the gate off after
	// upgrade, silently. Own flag, same reasoning as the purge above.
	if ( ! get_option( 'mcpwp_destructive_switch_reset' ) ) {
		require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-activator.php';
		Mcpwp_Activator::reset_destructive_switch();
	}

	// One-time: label analytics opt-ins stored before 3.11.1 as legacy — any
	// API key could write them — so they are not consent on Free. Runs after
	// the bridge migration above. Own flag, same reasoning.
	if ( ! get_option( 'mcpwp_analytics_choice_stamped' ) ) {
		require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-activator.php';
		Mcpwp_Activator::stamp_analytics_choice();
	}

	// Initialize the plugin
	$loader = new Mcpwp_Loader();
	$loader->run();

	// Refuse a second installed copy and surface a double load (#802).
	Mcpwp_Duplicate_Copy::register();

	// A Free site whose analytics opt-in predates recorded consent: ask an
	// administrator to confirm it; the notice can be dismissed (review r2 N1).
	add_action( 'admin_notices', array( 'Mcpwp_Analytics', 'render_legacy_consent_notice' ) );
	add_action( 'admin_post_mcpwp_dismiss_analytics_notice', array( 'Mcpwp_Analytics', 'handle_dismiss_legacy_notice' ) );

	// Wire analytics: Mcpwp_Analytics listens on every MCP tool call.
	add_action( 'mcpwp_tool_called', array( 'Mcpwp_Analytics', 'on_tool_called' ), 10, 4 );

	// OAuth 2.1 well-known discovery endpoints + WWW-Authenticate hint on 401.
	if ( class_exists( 'Mcpwp_OAuth_Well_Known' ) ) {
		Mcpwp_OAuth_Well_Known::init();
	}

	// Initialize white-label (shortcode, branding hooks, Elementor widget).
	if ( class_exists( 'Mcpwp_White_Label' ) ) {
		Mcpwp_White_Label::init();
	}

	// Elementor data write/delete audit trail — see #612.
	if ( class_exists( 'Mcpwp_Elementor_Data_Audit' ) ) {
		Mcpwp_Elementor_Data_Audit::init();
	}

	// Auto-purge Cloudflare cache for the edited permalink after a content write.
	if ( class_exists( 'Mcpwp_Cloudflare_Cache_Sync' ) ) {
		Mcpwp_Cloudflare_Cache_Sync::init();
	}

	// Signals cron — compute signal feed on schedule.
	if ( class_exists( 'Mcpwp_Signals' ) ) {
		Mcpwp_Signals::schedule();
		add_action( Mcpwp_Signals::CRON_HOOK, array( 'Mcpwp_Signals', 'compute' ) );
	}

	// Figma design-token sync cron — pulls colors/typography from the configured
	// Figma file's shared styles so a companion theme can read them as CSS custom
	// properties. cron_sync_design_tokens() no-ops when Figma isn't configured or
	// has no default_file_key, so scheduling is always safe.
	if ( class_exists( 'Mcpwp_Figma' ) ) {
		Mcpwp_Figma::schedule();
		add_action( Mcpwp_Figma::CRON_HOOK, array( 'Mcpwp_Figma', 'cron_sync_design_tokens' ) );
	}

	// Schedule daily prune of old action log entries.
	add_action( 'mcpwp_action_log_daily_prune', function() {
		if ( class_exists( 'Mcpwp_Action_Log' ) ) {
			Mcpwp_Action_Log::prune();
		}
	} );
	if ( ! wp_next_scheduled( 'mcpwp_action_log_daily_prune' ) ) {
		wp_schedule_event( time(), 'daily', 'mcpwp_action_log_daily_prune' );
	}

	// Self-hosted update checker (excluded from WP.org builds).
	if ( class_exists( 'Mcpwp_Updater' ) ) {
		Mcpwp_Updater::instance();
	}

	// Report the update channel's state where a human or an agent will see it.
	// A channel that cannot deliver is otherwise indistinguishable from a site
	// that is simply up to date (#716).
	if ( class_exists( 'Mcpwp_Update_Channel_Health' ) ) {
		Mcpwp_Update_Channel_Health::init();
	}

	}
}

/**
 * Activation hook — supports network-wide activation on multisite.
 *
 * @param bool $network_wide True when activated network-wide.
 */
if ( ! function_exists( 'mcpwp_activate' ) ) {
	function mcpwp_activate( $network_wide = false ) {
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-activator.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-encryption.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-migrate.php';

	if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
		$sites = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $sites as $blog_id ) {
			switch_to_blog( $blog_id );
			Mcpwp_Activator::activate();
			try {
				Mcpwp_Migrate::run();
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'MCPWP migration error (activate, blog ' . $blog_id . '): ' . $e->getMessage() );
				}
			} catch ( \Exception $e ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'MCPWP migration error (activate, blog ' . $blog_id . '): ' . $e->getMessage() );
				}
			}
			restore_current_blog();
		}
	} else {
		Mcpwp_Activator::activate();
		try {
			Mcpwp_Migrate::run();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MCPWP migration error (activate): ' . $e->getMessage() );
			}
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MCPWP migration error (activate): ' . $e->getMessage() );
			}
		}
	}
	}
}

/**
 * Provision MCPWP tables/options when a new site is created in a multisite network.
 *
 * @param WP_Site $new_site New site object.
 */
if ( ! function_exists( 'mcpwp_on_new_site' ) ) {
	function mcpwp_on_new_site( $new_site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// Only run if MCPWP is network-activated.
	if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-activator.php';

	switch_to_blog( $new_site->blog_id );
	Mcpwp_Activator::activate();
	restore_current_blog();
	}
}
add_action( 'wp_insert_site', 'mcpwp_on_new_site' );

/**
 * Deactivation hook.
 */
if ( ! function_exists( 'mcpwp_deactivate' ) ) {
	function mcpwp_deactivate() {
	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-deactivator.php';
	Mcpwp_Deactivator::deactivate();
	}
}

// Register hooks
register_activation_hook( __FILE__, 'mcpwp_activate' );
register_deactivation_hook( __FILE__, 'mcpwp_deactivate' );

// Load plugin after WordPress is loaded
add_action( 'plugins_loaded', 'mcpwp_load_plugin' );
