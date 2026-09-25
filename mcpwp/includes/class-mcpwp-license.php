<?php
/**
 * License management for MCPWP.
 *
 * Freemius is the single source of truth for entitlement in the production
 * build, with narrow, non-wp-config overrides:
 *   - MCPWP_WPORG_BUILD defined         => always free (WP.org build).
 *   - Agency build marker file present  => always pro (agency distribution).
 *   - MCPWP_TESTS true AND PHP_SAPI cli => always pro (test-only switch).
 *
 * SECURITY (issue #863): a bare `define( 'MCPWP_PRO', true )` in
 * wp-config.php is DELIBERATELY not honoured. Before this fix, any Free or
 * Freemius install could self-grant every Pro tool by adding that one line
 * to wp-config.php — it was even documented publicly in
 * `docs/skills/dev-pro.md`. The agency tier is now anchored to a real file,
 * `includes/agency-license.php`, that only `scripts/build-agency.sh` writes
 * (into the agency ZIP's build directory, never into this source tree) —
 * see `agency_license_active()`. A wp-config.php constant cannot create
 * that file. This is not DRM: a site owner with actual filesystem write
 * access to the plugin directory can still forge it (or just edit this
 * class) — same as always. The fix removes the one-line, no-file-access
 * bypass; nothing more.
 *
 * BRIDGE FALLBACK (issue #505)
 * When a site migrated from site-pilot-ai 2.8.x and Freemius has not yet
 * re-confirmed entitlement (e.g. the site has not yet loaded the Freemius
 * SDK or the SDK reports free), a local-entitlement fallback may apply.
 * The fallback is gated on the `mcpwp_migrated_from_spai` migration flag.
 * Note: that flag is set by Mcpwp_Migrate::run() on every completed run,
 * including a fresh install with no spai_ data — so the flag alone does not
 * prove a 2.8.x origin. The real protection is that `mcpwp_pro_license` and
 * `mcpwp_trial_started` are written ONLY by the migration's OPTION_MAP copy
 * (no REST/MCP surface writes an arbitrary option name), so a fresh install
 * never has them and a low-privilege token cannot inject them.
 *
 * The fallback honours the same validity rules as Spai_License::is_pro()
 * in site-pilot-ai 2.8.56:
 *   (a) mcpwp_pro_license — valid array with key='...', valid=true, and not
 *       expired (empty/absent expires_at = lifetime; strtotime test otherwise).
 *   (b) mcpwp_trial_started — Unix timestamp; active when elapsed < 14 days.
 *
 * Both options are copied from their spai_ equivalents by Mcpwp_Migrate
 * (see OPTION_MAP entries). The fallback is intentionally minimal and
 * auditable — it replicates only the subset of Spai_License logic that
 * determines is_pro(), not the full plan hierarchy.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * License handler.
 */
class Mcpwp_License {

	/**
	 * Trial duration in days — mirrors Spai_License::TRIAL_DAYS from 2.8.56.
	 *
	 * @var int
	 */
	const BRIDGE_TRIAL_DAYS = 14;

	/**
	 * Migrated-from-spai flag option name.  Presence means this was a 2.8.x
	 * site.  Used to gate the local-entitlement fallback so it cannot be
	 * triggered by an arbitrary option injection on a fresh v3 install.
	 *
	 * @var string
	 */
	const BRIDGE_MIGRATED_FLAG = 'mcpwp_migrated_from_spai';

	/**
	 * Freemius product ID — mirrors the 'id' passed to fs_dynamic_init() in
	 * freemius-init.php. Used only as a substring marker to detect residual
	 * Freemius account data (see get_unlicensed_reason()); not a credential.
	 *
	 * @var string
	 */
	const FREEMIUS_PRODUCT_ID = '23824';

	/**
	 * Expected return value of the agency build marker file. Bumping this
	 * (and the string a future build-agency.sh writes) invalidates any
	 * marker file copied out of an old ZIP into a new one.
	 *
	 * @var string
	 */
	const AGENCY_MARKER_TOKEN = 'MCPWP_AGENCY_LICENSE_V1';

	/**
	 * Singleton instance.
	 *
	 * @var Mcpwp_License
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Mcpwp_License
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Whether the agency build marker is present and valid.
	 *
	 * The marker is a plain PHP file, `includes/agency-license.php`, that
	 * `scripts/build-agency.sh` writes into the agency distribution's build
	 * directory only — it is never part of this source tree and is never
	 * shipped in the Free (wp.org / self-hosted) or Freemius ZIPs. Unlike a
	 * constant, a site owner editing wp-config.php cannot create this file;
	 * doing so requires actual filesystem write access to the plugin
	 * directory (see the class docblock for that limit).
	 *
	 * The file is `include`d (not merely checked for existence) and must
	 * `return` the exact expected token, so an empty or truncated file left
	 * behind by a broken build/copy does not silently grant Pro.
	 *
	 * @return bool
	 */
	private static function agency_license_active(): bool {
		$marker_file = __DIR__ . '/agency-license.php';
		if ( ! is_file( $marker_file ) ) {
			return false;
		}
		return self::AGENCY_MARKER_TOKEN === include $marker_file;
	}

	/**
	 * Test-only Pro switch.
	 *
	 * Requires BOTH `MCPWP_TESTS` truthy AND PHP running under the CLI SAPI,
	 * so it can never be reached by a normal web request even if a site
	 * owner defines MCPWP_TESTS in wp-config.php — is_pro() is never called
	 * from CLI in production request handling. This is what the PHPUnit
	 * bootstrap / CI harness uses instead of the removed MCPWP_PRO override
	 * (issue #863); it is not present in any shipped ZIP.
	 *
	 * @return bool
	 */
	private static function test_override_active(): bool {
		return defined( 'MCPWP_TESTS' ) && MCPWP_TESTS && 'cli' === PHP_SAPI;
	}

	/**
	 * Check if licensed features are active.
	 *
	 * Always false when MCPWP_WPORG_BUILD is defined (free build). Otherwise
	 * pro is active when the agency build marker or the CLI-only test switch
	 * is present, or when Freemius reports premium access, a paying plan, or
	 * an active trial.
	 *
	 * @return bool
	 */
	public function is_pro() {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return false;
		}

		if ( self::agency_license_active() || self::test_override_active() ) {
			return true;
		}

		if ( function_exists( 'mcpwp_get_fs_instance' ) ) {
			$fs = mcpwp_get_fs_instance();
			if ( is_object( $fs ) ) {
				if ( method_exists( $fs, 'can_use_premium_code' ) && $fs->can_use_premium_code() ) {
					return true;
				}
				if ( method_exists( $fs, 'is_paying' ) && $fs->is_paying() ) {
					return true;
				}
				if ( method_exists( $fs, 'is_trial' ) && $fs->is_trial() ) {
					return true;
				}
			}
		}

		// ----------------------------------------------------------------
		// BRIDGE FALLBACK — issue #505
		//
		// Applies ONLY to sites that genuinely migrated from site-pilot-ai
		// 2.8.x. If Freemius has not (yet) confirmed entitlement, honour the
		// local license / trial the site carried over.
		//
		// Validity rules mirror Spai_License::is_pro() from 2.8.56:
		//   (a) Stored license: must have key + valid=true + not expired.
		//       expires_at absent/null => lifetime (never expired).
		//   (b) Trial: unix timestamp; active when elapsed < BRIDGE_TRIAL_DAYS.
		//
		// SECURITY (Warden 3.1.0 regression sweep — entitlement-bypass P0):
		// entitlement is read EXCLUSIVELY from the ORIGINAL spai_ options, never
		// the migrated mcpwp_ copies, and is NOT gated on mcpwp_migrated_from_spai.
		// All three mcpwp_ items live in the writable mcpwp_ option namespace,
		// which the settings REST/MCP surface ( PUT /option, wp_update_option )
		// exposes to any write-scope token via the `mcpwp_` allow-list prefix.
		// Trusting them let a write-scope token forge Pro on ANY install with no
		// migration. The spai_ prefix is NOT in that allow-list and cannot be set
		// through any REST/MCP surface, so a migrated spai_ original is an
		// un-forgeable proof of a real 2.8.x entitlement. A fresh v3 install has
		// no spai_ options, so both helpers return false and this path is inert.
		// ----------------------------------------------------------------
		if ( $this->bridge_local_license_is_valid() ) {
			return true;
		}
		if ( $this->bridge_trial_is_active() ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether the migrated local license blob grants Pro access.
	 *
	 * Replicates the Spai_License stored-license check from 2.8.56:
	 *   - Option: spai_pro_license (array) — the ORIGINAL, un-forgeable source.
	 *   - Valid when: key non-empty AND valid===true AND not expired.
	 *   - expires_at absent or null => lifetime license (no expiry check).
	 *   - expires_at present => compare strtotime(expires_at) vs time().
	 *
	 * SECURITY: reads spai_pro_license (NOT the writable mcpwp_pro_license copy)
	 * so a write-scope token cannot forge entitlement. See is_pro() for detail.
	 *
	 * This is a private bridge helper — not part of the public API.
	 *
	 * @return bool
	 */
	private function bridge_local_license_is_valid(): bool {
		$license = get_option( 'spai_pro_license', array() );
		if ( ! is_array( $license ) ) {
			return false;
		}
		if ( empty( $license['key'] ) || empty( $license['valid'] ) ) {
			return false;
		}
		// Check expiry only when expires_at is present and non-empty.
		// Mirror Spai_License::is_expired() exactly: an unparseable expires_at
		// (strtotime === false) is treated as EXPIRED (deny), not skipped —
		// 2.8.56 casts false to 0 and 0 < time() is true. Failing closed here
		// avoids a corrupted/hand-edited license blob granting Pro too leniently.
		if ( ! empty( $license['expires_at'] ) ) {
			$expiry = strtotime( (string) $license['expires_at'] );
			if ( false === $expiry || $expiry < time() ) {
				return false; // Expired or unparseable.
			}
		}
		return true;
	}

	/**
	 * Check whether the migrated trial timestamp indicates an active trial.
	 *
	 * Replicates Spai_License::is_trial_active() from 2.8.56:
	 *   - Option: spai_trial_started (unix timestamp) — the ORIGINAL source.
	 *   - Active when elapsed time < BRIDGE_TRIAL_DAYS * DAY_IN_SECONDS.
	 *
	 * SECURITY: reads spai_trial_started (NOT the writable mcpwp_trial_started
	 * copy) so a write-scope token cannot forge a trial. See is_pro() for detail.
	 *
	 * This is a private bridge helper — not part of the public API.
	 *
	 * @return bool
	 */
	private function bridge_trial_is_active(): bool {
		$trial_started = get_option( 'spai_trial_started', '' );
		if ( empty( $trial_started ) ) {
			return false;
		}
		$elapsed = time() - (int) $trial_started;
		return $elapsed < ( self::BRIDGE_TRIAL_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Check if user is paying (has a paid plan, not a trial).
	 *
	 * @return bool
	 */
	public function is_paying() {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return false;
		}

		if ( self::agency_license_active() || self::test_override_active() ) {
			return true;
		}
		if ( function_exists( 'mcpwp_get_fs_instance' ) ) {
			$fs = mcpwp_get_fs_instance();
			if ( is_object( $fs ) && method_exists( $fs, 'is_paying' ) && $fs->is_paying() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if agency tier.
	 *
	 * @return bool
	 */
	public function is_agency() {
		return 'agency' === $this->get_plan();
	}

	/**
	 * Get current plan.
	 *
	 * @return string 'unlicensed', 'pro', 'agency', or 'trial'
	 */
	public function get_plan() {
		if ( ! $this->is_pro() ) {
			return 'unlicensed';
		}

		$freemius_plan = $this->get_freemius_plan();
		if ( '' !== $freemius_plan ) {
			return $freemius_plan;
		}

		// Agency marker or CLI test override active with no Freemius instance.
		return 'pro';
	}

	/**
	 * Human-readable label for the current plan, for admin UI display only.
	 *
	 * get_plan() is a stable API/internal value ('unlicensed', 'pro', 'agency',
	 * 'trial', …) read by the site-info capabilities payload and covered by
	 * tests that assert the literal string — it must not change. But shown
	 * verbatim in the admin Setup/Settings pages, title-cased 'unlicensed'
	 * reads as "Plan: Unlicensed", which looks like an error rather than the
	 * normal state of a Free install. Map that one value to "Free"; every
	 * other plan (Pro, Agency, Trial, or any Freemius plan slug) keeps its
	 * ordinary title-cased label.
	 *
	 * @return string
	 */
	public function get_plan_label() {
		$plan = $this->get_plan();

		if ( 'unlicensed' === $plan || 'free' === $plan ) {
			return __( 'Free', 'mcpwp' );
		}

		return ucwords( str_replace( '_', ' ', $plan ) );
	}

	/**
	 * Get the normalized Freemius plan slug when available.
	 *
	 * @return string Plan slug, or empty string when unknown.
	 */
	private function get_freemius_plan() {
		if ( ! function_exists( 'mcpwp_get_fs_instance' ) ) {
			return '';
		}

		$fs = mcpwp_get_fs_instance();
		if ( ! is_object( $fs ) ) {
			return '';
		}

		if ( method_exists( $fs, 'is_trial' ) && $fs->is_trial() && ( ! method_exists( $fs, 'is_paying' ) || ! $fs->is_paying() ) ) {
			return 'trial';
		}

		$raw_plan = '';
		if ( method_exists( $fs, 'get_plan' ) ) {
			$plan = $fs->get_plan();
			if ( is_object( $plan ) ) {
				foreach ( array( 'name', 'title', 'slug' ) as $property ) {
					if ( isset( $plan->{$property} ) && is_scalar( $plan->{$property} ) ) {
						$raw_plan = (string) $plan->{$property};
						break;
					}
				}
				foreach ( array( 'get_name', 'get_title', 'get_slug' ) as $method ) {
					if ( '' === $raw_plan && method_exists( $plan, $method ) ) {
						$value = $plan->{$method}();
						if ( is_scalar( $value ) ) {
							$raw_plan = (string) $value;
						}
					}
				}
			} elseif ( is_string( $plan ) ) {
				$raw_plan = $plan;
			}
		}

		$raw_plan = strtolower( sanitize_key( $raw_plan ) );
		if ( false !== strpos( $raw_plan, 'agency' ) ) {
			return 'agency';
		}
		if ( false !== strpos( $raw_plan, 'trial' ) ) {
			return 'trial';
		}
		// 'free' is the Freemius slug for the unpaid tier. A pro entitlement
		// must never resolve to a free plan (issue #319), so fall through to
		// the is_paying check / 'pro' default instead.
		if ( 'free' === $raw_plan ) {
			$raw_plan = '';
		}
		if ( '' !== $raw_plan ) {
			return $raw_plan;
		}
		if ( method_exists( $fs, 'is_paying' ) && $fs->is_paying() ) {
			return 'pro';
		}

		return '';
	}

	/**
	 * Diagnose why is_pro() is false, without exposing any license key.
	 *
	 * Requested by issue #656 (DME regression report): a licensed site can lose
	 * Pro after an update with no visible explanation. The most common real
	 * cause found while triaging that report is 'no_freemius_sdk' — the
	 * self-hosted distribution (mumega.com/updates.mcpwp.net) deliberately
	 * ships with no Freemius SDK at all (see build-selfhosted.sh), so a site
	 * that was Pro under the Freemius-built package silently loses Pro if a
	 * self-hosted update ever gets installed over it — the two packages share
	 * the same plugin slug/name, so this is easy to do by accident.
	 *
	 * @return string Empty string when is_pro() is true; otherwise one of:
	 *                'wporg_build', 'no_freemius_sdk', 'freemius_removed_had_license',
	 *                'anonymous', 'pending_activation', 'no_active_license'.
	 */
	public function get_unlicensed_reason() {
		if ( $this->is_pro() ) {
			return '';
		}

		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return 'wporg_build';
		}

		if ( ! function_exists( 'mcpwp_get_fs_instance' ) || ! is_object( mcpwp_get_fs_instance() ) ) {
			// No Freemius SDK loaded at all (self-hosted build) — distinguish a
			// genuinely fresh free install from a site that WAS Freemius-licensed
			// before a self-hosted zip got installed over it (#656): both
			// packages share the same plugin slug, so this is an easy accident,
			// and it silently strips every path to Pro with no visible cause.
			// fs_accounts is Freemius's own site-wide account store — it
			// persists in wp_options regardless of whether the SDK files are
			// currently present, so its content survives the accidental
			// downgrade even though the code that would normally read it
			// doesn't exist right now. This is a best-effort heuristic (a
			// substring check, not a parse of Freemius's private option
			// schema), used only for a diagnostic message — never for gating.
			$accounts = get_option( 'fs_accounts', '' );
			// get_option() auto-unserializes into an array/object; flatten back
			// to a string for a simple substring check rather than parsing
			// Freemius's private nested schema.
			$accounts_raw = is_string( $accounts ) ? $accounts : maybe_serialize( $accounts );
			if ( '' !== $accounts_raw && false !== strpos( $accounts_raw, self::FREEMIUS_PRODUCT_ID ) ) {
				return 'freemius_removed_had_license';
			}
			return 'no_freemius_sdk';
		}

		$fs = mcpwp_get_fs_instance();

		if ( method_exists( $fs, 'is_anonymous' ) && $fs->is_anonymous() ) {
			return 'anonymous';
		}

		if ( method_exists( $fs, 'is_pending_activation' ) && $fs->is_pending_activation() ) {
			return 'pending_activation';
		}

		return 'no_active_license';
	}

	/**
	 * Check if a Freemius trial is active.
	 *
	 * @return bool
	 */
	public function is_trial_active() {
		if ( ! function_exists( 'mcpwp_get_fs_instance' ) ) {
			return false;
		}

		$fs = mcpwp_get_fs_instance();
		return is_object( $fs ) && method_exists( $fs, 'is_trial' ) && $fs->is_trial();
	}

	/**
	 * Upgrade URL.
	 *
	 * Prefers Freemius's own install-linked checkout URL — it carries this
	 * site's install_id, so a completed purchase activates THIS site and
	 * redirects back into wp-admin. The plain marketing-site URL has no
	 * install context: a purchase made there never syncs back to the plugin
	 * (reported bug: "pay with coupon, nothing happens, no way back to WP").
	 * Only falls back to the marketing URL when Freemius isn't available.
	 *
	 * @return string
	 */
	public function get_upgrade_url() {
		if ( function_exists( 'mcpwp_get_fs_instance' ) ) {
			$fs = mcpwp_get_fs_instance();
			if ( is_object( $fs ) ) {
				if ( method_exists( $fs, 'is_anonymous' ) && $fs->is_anonymous()
					&& method_exists( $fs, 'get_reconnect_url' ) ) {
					return $fs->get_reconnect_url();
				}
				if ( method_exists( $fs, 'is_pending_activation' ) && $fs->is_pending_activation()
					&& method_exists( $fs, 'get_reconnect_url' ) ) {
					return $fs->get_reconnect_url();
				}
				if ( method_exists( $fs, 'get_upgrade_url' ) ) {
					return $fs->get_upgrade_url();
				}
			}
		}
		return 'https://mcpwp.net/pricing/';
	}

	/**
	 * Account URL.
	 *
	 * Same install-linked preference as get_upgrade_url() — see there.
	 *
	 * @return string
	 */
	public function get_account_url() {
		if ( function_exists( 'mcpwp_get_fs_instance' ) ) {
			$fs = mcpwp_get_fs_instance();
			if ( is_object( $fs ) && method_exists( $fs, 'get_account_url' ) ) {
				return $fs->get_account_url();
			}
		}
		return 'https://mcpwp.net/account/';
	}

	/**
	 * Canonical license/entitlement accessor.
	 *
	 * Returns a single, internally consistent snapshot of the entitlement state
	 * so every consumer (MCP / REST / CLI / admin) reports the same plan and pro
	 * status. Plan and is_pro can never contradict.
	 *
	 * Consistency guarantees:
	 * - When is_pro is false, plan is always 'unlicensed'.
	 * - When is_pro is true, plan is always a non-free value ('pro', 'agency',
	 *   'trial', or a Freemius-provided slug). If the resolved plan is empty or
	 *   collapses to a free/unlicensed value, it is coerced to a sane paid value.
	 *
	 * @return array {
	 *     @type string $plan      Plan slug ('unlicensed', 'trial', 'pro', 'agency', ...).
	 *     @type bool   $is_pro    Whether licensed features are active.
	 *     @type bool   $is_paying Whether the site has a paid (non-trial) entitlement.
	 *     @type bool   $is_agency Whether the agency tier is active.
	 * }
	 */
	public function get_license_info() {
		$is_pro    = $this->is_pro();
		$is_paying = $this->is_paying();

		// When not pro, plan is unconditionally unlicensed regardless of stale data.
		if ( ! $is_pro ) {
			return array(
				'plan'      => 'unlicensed',
				'is_pro'    => false,
				'is_paying' => false,
				'is_agency' => false,
				'reason'    => $this->get_unlicensed_reason(),
			);
		}

		$plan = $this->get_plan();

		// Self-validate: a pro entitlement must never resolve to a free/unlicensed plan.
		if ( '' === $plan || 'unlicensed' === $plan || 'free' === $plan ) {
			if ( $is_paying ) {
				$plan = 'pro';
			} elseif ( $this->is_trial_active() ) {
				$plan = 'trial';
			} else {
				$plan = 'pro';
			}
		}

		return array(
			'plan'      => $plan,
			'is_pro'    => true,
			'is_paying' => $is_paying,
			'is_agency' => ( 'agency' === $plan ) || $this->is_agency(),
			'reason'    => '',
		);
	}
}

/**
 * Get license instance.
 *
 * @return Mcpwp_License
 */
function mcpwp_license() {
	return Mcpwp_License::get_instance();
}

/**
 * Warn loudly when this site looks like it was Freemius-licensed before, but
 * currently has no Freemius SDK loaded at all — the self-hosted and Freemius
 * packages share the same plugin slug, so installing the self-hosted zip over
 * a previously-Pro site is an easy accident that otherwise fails completely
 * silently (#656).
 */
function mcpwp_maybe_show_freemius_removed_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( 'freemius_removed_had_license' !== mcpwp_license()->get_unlicensed_reason() ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		wp_kses_post(
			__( '<strong>MCPWP:</strong> This site looks like it previously had a licensed Pro install, but the currently installed plugin package has no Freemius licensing component at all — likely the free/self-hosted package was installed over the Pro one by accident (both share the same plugin name). Reinstall the Pro package from your Freemius account to restore your license and Pro features.', 'mcpwp' )
		)
	);
}
add_action( 'admin_notices', 'mcpwp_maybe_show_freemius_removed_notice' );

/**
 * Warn when a newer MCPWP version exists but WordPress's own update-check
 * can't reach it, because Freemius only pushes updates to a connected/
 * licensed site — an anonymous, pending, or otherwise disconnected Pro
 * install silently stops seeing new versions with no visible cause.
 *
 * Deliberately READ-ONLY: this only compares version numbers against the
 * public canonical manifest and never surfaces a download/install action.
 * The self-hosted package's zip has no Freemius SDK at all — offering it
 * as an "update" here would recreate the exact wrong-package accident this
 * class already detects elsewhere (freemius_removed_had_license), just via
 * an automated click instead of a manual one.
 */
function mcpwp_maybe_notify_freemius_update_unreachable() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! defined( 'MCPWP_FREEMIUS_BUILD' ) || ! MCPWP_FREEMIUS_BUILD ) {
		// The self-hosted build already has its own real updater
		// (Mcpwp_Updater) that isn't gated on Freemius connection state.
		return;
	}

	$reason = mcpwp_license()->get_unlicensed_reason();
	// Only the states where Freemius exists but isn't actively delivering
	// updates to THIS site — not a fresh/never-decided install (that one
	// still gets Freemius's real connect screen, not stuck at all) and not
	// the self-hosted-package-installed case (its own separate notice above
	// already covers that, with a stronger, more specific message).
	$update_blocked_reasons = array( 'anonymous', 'pending_activation', 'no_active_license' );
	if ( ! in_array( $reason, $update_blocked_reasons, true ) ) {
		return;
	}

	$cache_key = 'mcpwp_canonical_version_check';
	$remote    = get_transient( $cache_key );
	if ( false === $remote ) {
		$response = wp_remote_get(
			'https://mumega.com/mcp-updates/version.json',
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		$remote = ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) )
			? json_decode( wp_remote_retrieve_body( $response ), true )
			: array();
		// Cache both the successful result and a failed lookup, so a
		// down/unreachable manifest doesn't retry on every single page load.
		set_transient( $cache_key, $remote, 12 * HOUR_IN_SECONDS );
	}

	if ( empty( $remote['version'] ) || ! version_compare( $remote['version'], MCPWP_VERSION, '>' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		wp_kses_post(
			sprintf(
				/* translators: 1: latest available version, 2: reconnect URL */
				__( '<strong>MCPWP:</strong> Version %1$s is available, but this site isn\'t currently connected/licensed with Freemius, so the automatic update can\'t reach it. <a href="%2$s">Reconnect your license</a>, or download the latest Pro package manually from your Freemius account.', 'mcpwp' ),
				esc_html( $remote['version'] ),
				esc_url( mcpwp_license()->get_upgrade_url() )
			)
		)
	);
}
add_action( 'admin_notices', 'mcpwp_maybe_notify_freemius_update_unreachable' );
