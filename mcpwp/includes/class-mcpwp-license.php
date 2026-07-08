<?php
/**
 * License management for mumcp.
 *
 * Freemius is the single source of truth for entitlement in the production
 * build, with two developer/distribution overrides:
 *   - MCPWP_WPORG_BUILD defined => always free (WP.org build).
 *   - MCPWP_PRO constant true   => always pro (developer override).
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
 * @package mumcp
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
	 * Check if licensed features are active.
	 *
	 * Always false when MCPWP_WPORG_BUILD is defined (free build). Otherwise
	 * pro is active when the MCPWP_PRO developer override is set, or when
	 * Freemius reports premium access, a paying plan, or an active trial.
	 *
	 * @return bool
	 */
	public function is_pro() {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return false;
		}

		// Developer override.
		if ( defined( 'MCPWP_PRO' ) && MCPWP_PRO ) {
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

		if ( defined( 'MCPWP_PRO' ) && MCPWP_PRO ) {
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

		// MCPWP_PRO developer override with no Freemius instance.
		return 'pro';
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
	 * @return string
	 */
	public function get_upgrade_url() {
		return 'https://mumcp.com/pricing/';
	}

	/**
	 * Account URL.
	 *
	 * @return string
	 */
	public function get_account_url() {
		return 'https://mumcp.com/account/';
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
