<?php
/**
 * Upgrade-time key hygiene: revoke keys whose secret matches a stored verifier.
 *
 * A key's secret is never a verifier the database already holds. After an
 * upgrade, every ACTIVE record in the scoped store (`mcpwp_api_keys`) is
 * checked against stored verifiers beside it — values of `mcpwp_api_key` and
 * `spai_api_key` that pass Mcpwp_Key_State::is_stored_verifier(), each
 * `spai_api_keys[*].hash`, and the hash of every other record that is itself
 * a stored verifier — with the same matcher authentication uses
 * (Mcpwp_Api_Auth::is_api_key_hash_match()). A record that verifies against
 * one of them is revoked. A normal record never does: its secret is a random
 * key that appears nowhere as a verifier. A record that carries a stored
 * verifier VERBATIM — the shape Mcpwp_Activator::adopt_spai_credentials()
 * writes, since it never re-hashes a verifier — does not match either: the
 * matcher never accepts a stored value as its own key. Plaintext option
 * leftovers and non-verifier record hashes are not candidates: they are
 * credentials, not verifiers, and a record that verifies one must stay
 * active so that credential still authenticates.
 *
 * One-time and resumable:
 * - the work is bounded per run (a time budget and a cap on hash checks); the
 *   position is kept in a non-autoloaded state option, so the next run carries
 *   on where this one stopped;
 * - progress is keyed per (record, candidate) so adding a record or a hash
 *   does not discard checks already done;
 * - each record is checked against the small external verifier set first
 *   (admin_init, short budget); record-vs-record checks continue on a spaced
 *   WP-Cron event with a larger budget, with a tiny admin_init fallback slice
 *   so a site without working cron still finishes;
 * - revocation happens inside Mcpwp_Key_Store::mutate() (lock + compare-and-
 *   swap), and each match is re-verified under the lock from a fingerprint
 *   (the matched candidate string is never persisted);
 * - a matched entry whose target is no longer an active record (revoked or
 *   deleted elsewhere) is dropped and its pending entry cleared, so the sweep
 *   can complete;
 * - the autoloaded flag is set only when every active record has been checked
 *   against every candidate and that final state was confirmed under the lock.
 *
 * Request gating: resumed on normal administrator screen loads only — not on
 * wp_doing_ajax(), admin-post.php, REST, or cron. The WP-Cron handler is a
 * separate hook and is not gated by that admin skip list.
 *
 * Fails closed: an unreadable store, a busy lock or a refused write revokes
 * nothing and leaves the flag unset. A refused revoke keeps the match (as a
 * fingerprint) and is reported in Site Health (ids only) so the next eligible
 * request or cron pass can retry. A record without a hash string is never
 * revoked — it cannot be evaluated (and authenticates nothing).
 *
 * Revoked records are listed (id and label, never a hash) in an admin notice
 * and a Site Health item.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_Key_Hygiene {

	/** Autoloaded flag: the sweep finished on this site. */
	const FLAG = 'mcpwp_key_hygiene_swept';

	/** Sweep position (not autoloaded). */
	const STATE_OPTION = 'mcpwp_key_hygiene_state';

	/** Records the sweep revoked: id, label, revoked_at. */
	const REVOKED_OPTION = 'mcpwp_key_hygiene_revoked';

	/** Matched ids whose revoke write was refused (ids/labels only). */
	const PENDING_OPTION = 'mcpwp_key_hygiene_pending';

	/** Lock row: one sweep at a time. */
	const LOCK = 'mcpwp_key_hygiene_lock';

	/** User meta: this user dismissed the notice. */
	const DISMISSED_META = 'mcpwp_key_hygiene_notice_dismissed';

	/** admin-post action (and nonce action) for dismissing the notice. */
	const DISMISS_ACTION = 'mcpwp_key_hygiene_notice_dismiss';

	/** Nonce field name. */
	const NONCE_FIELD = 'mcpwp_key_hygiene_nonce';

	/** WP-Cron hook for the record-vs-record phase. */
	const CRON_HOOK = 'mcpwp_key_hygiene_sweep';

	/** Seconds between cron passes. */
	const CRON_SPACING = 60;

	/** Default seconds of hash checks per run (activation / full pass). */
	const TIME_BUDGET = 1.0;

	/** Default hash checks per run (activation / full pass). */
	const MAX_CHECKS = 100;

	/** Admin external-phase budget (seconds). */
	const ADMIN_TIME_BUDGET = 0.15;

	/** Admin external-phase check cap. */
	const ADMIN_MAX_CHECKS = 40;

	/** Admin fallback internal-slice budget (seconds). */
	const ADMIN_FALLBACK_TIME_BUDGET = 0.05;

	/** Admin fallback internal-slice check cap. */
	const ADMIN_FALLBACK_MAX_CHECKS = 5;

	/**
	 * Sweep lock lifetime (seconds) — same row as {@see Mcpwp_OAuth_Clients::LOCK_TTL}.
	 * A cron pass holds this lock for the whole budget plus bcrypt and a patient mutate().
	 */
	const SWEEP_LOCK_LIFETIME = 10;

	/**
	 * Minimum seconds the cron budget must stay below {@see self::SWEEP_LOCK_LIFETIME}.
	 * A larger budget risks overlapping cron workers after lock TTL expiry.
	 */
	const CRON_LOCK_HEADROOM = 3.0;

	/** Cron pass budget (seconds) — must stay below SWEEP_LOCK_LIFETIME - CRON_LOCK_HEADROOM. */
	const CRON_TIME_BUDGET = 5.0;

	/** Cron pass check cap. */
	const CRON_MAX_CHECKS = 500;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_for_admin' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_run_for_cron' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_status_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Whether the sweep has finished.
	 *
	 * @return bool
	 */
	public static function is_done() {
		return (bool) get_option( self::FLAG );
	}

	/**
	 * admin_init: external verifiers under a short budget, schedule cron for
	 * record-vs-record work, then a tiny internal fallback slice so a site
	 * without working cron still finishes. One autoloaded read when done;
	 * only an administrator's normal screen load does the work (not ajax,
	 * admin-post, REST, or cron).
	 *
	 * @return void
	 */
	public static function maybe_run_for_admin() {
		if ( self::is_done() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::should_skip_request() ) {
			return;
		}
		self::run_safely( self::ADMIN_TIME_BUDGET, self::ADMIN_MAX_CHECKS, 'external' );
		self::schedule_cron();
		if ( ! self::is_done() ) {
			self::run_safely( self::ADMIN_FALLBACK_TIME_BUDGET, self::ADMIN_FALLBACK_MAX_CHECKS, 'internal' );
			self::schedule_cron();
		}
	}

	/**
	 * WP-Cron: continue the sweep with a larger budget; re-schedule until done.
	 *
	 * @return void
	 */
	public static function maybe_run_for_cron() {
		if ( self::is_done() ) {
			self::clear_cron();
			return;
		}
		$outcome = self::run_safely( self::CRON_TIME_BUDGET, self::CRON_MAX_CHECKS, 'all' );
		if ( is_array( $outcome ) && 'unreadable' === $outcome['status'] ) {
			// Store health already reports an unreadable store; do not re-queue every minute.
			self::clear_cron();
			return;
		}
		if ( self::is_done() ) {
			self::clear_cron();
			return;
		}
		self::schedule_cron( true );
	}

	/**
	 * Whether this request should not run the admin_init sweep.
	 *
	 * @return bool
	 */
	public static function should_skip_request() {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}
		$is_rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		/**
		 * Whether the current request is a REST request for key-hygiene skip.
		 *
		 * @param bool $is_rest Detected REST request.
		 */
		if ( apply_filters( 'mcpwp_key_hygiene_is_rest_request', $is_rest ) ) {
			return true;
		}
		if ( self::is_admin_post_request() ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether the current request is admin-post.php (script name only).
	 *
	 * @return bool
	 */
	private static function is_admin_post_request() {
		$detected = false;
		global $pagenow;
		if ( isset( $pagenow ) && 'admin-post.php' === $pagenow ) {
			$detected = true;
		}
		if ( ! $detected ) {
			foreach ( array( 'PHP_SELF', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'REQUEST_URI' ) as $key ) {
				if ( empty( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
					continue;
				}
				if ( self::server_value_is_admin_post( $_SERVER[ $key ] ) ) {
					$detected = true;
					break;
				}
			}
		}
		/**
		 * Whether the current request is admin-post.php for key-hygiene skip.
		 *
		 * @param bool $detected Detected admin-post request.
		 */
		return (bool) apply_filters( 'mcpwp_key_hygiene_is_admin_post', $detected );
	}

	/**
	 * Whether a server path/URI names admin-post.php as its script.
	 *
	 * Query-string occurrences (e.g. admin.php?redirect=admin-post.php) do not
	 * count — only the script basename does.
	 *
	 * @param string $value Server value.
	 * @return bool
	 */
	private static function server_value_is_admin_post( $value ) {
		$value = str_replace( '\\', '/', (string) $value );
		$path  = $value;
		if ( function_exists( 'wp_parse_url' ) ) {
			$parsed = wp_parse_url( $value, PHP_URL_PATH );
			if ( is_string( $parsed ) && '' !== $parsed ) {
				$path = $parsed;
			}
		} else {
			$q = strpos( $value, '?' );
			if ( false !== $q ) {
				$path = substr( $value, 0, $q );
			}
		}
		return 'admin-post.php' === basename( $path );
	}

	/**
	 * Run the sweep, never letting it fatal the request.
	 *
	 * @param float|null  $time_budget Seconds of hash checks (null: TIME_BUDGET).
	 * @param int|null    $max_checks  Hash checks (null: MAX_CHECKS).
	 * @param string|null $phases      'all', 'external', or 'internal' (null: all).
	 * @return array|null Outcome, or null when it threw.
	 */
	public static function run_safely( $time_budget = null, $max_checks = null, $phases = null ) {
		try {
			return self::run( $time_budget, $max_checks, $phases );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MCPWP key hygiene error: ' . get_class( $e ) );
			}
		}
		return null;
	}

	/**
	 * One bounded pass of the sweep.
	 *
	 * @param float|null  $time_budget Seconds of hash checks (null: TIME_BUDGET).
	 * @param int|null    $max_checks  Hash checks (null: MAX_CHECKS). At least one
	 *                                check runs per pass, so every pass progresses.
	 * @param string|null $phases      'all' (default), 'external', or 'internal'.
	 * @return array{status:string,revoked:array} status is 'done' (finished, flag
	 *         set), 'partial' (budget ran out; run again), 'unreadable', 'busy',
	 *         'changed' (the store changed under the sweep; run again) or the
	 *         Mcpwp_Key_Store::mutate() reason that refused the write.
	 */
	public static function run( $time_budget = null, $max_checks = null, $phases = null ) {
		$time_budget = null === $time_budget ? self::TIME_BUDGET : (float) $time_budget;
		$max_checks  = null === $max_checks ? self::MAX_CHECKS : max( 1, (int) $max_checks );
		$phases      = self::normalize_phases( $phases );
		$outcome     = array(
			'status'  => 'done',
			'revoked' => array(),
		);

		if ( self::is_done() ) {
			return $outcome;
		}

		self::load_dependencies();
		if ( Mcpwp_Key_State::store_is_unreadable() ) {
			$outcome['status'] = 'unreadable';
			return $outcome;
		}

		$lock = Mcpwp_OAuth_Clients::acquire_lock( 0, self::LOCK );
		if ( false === $lock ) {
			$outcome['status'] = 'busy';
			return $outcome;
		}

		try {
			// Another request may have finished it while this one waited.
			Mcpwp_OAuth_Clients::flush_option_cache( self::FLAG );
			if ( self::is_done() ) {
				return $outcome;
			}

			// The store as the database holds it, read under the store lock.
			$snapshot = Mcpwp_Key_Store::mutate(
				static function ( array $keys ) {
					return array( null, $keys );
				}
			);
			if ( ! $snapshot['written'] || ! is_array( $snapshot['result'] ) ) {
				$outcome['status'] = '' !== $snapshot['reason'] ? $snapshot['reason'] : 'busy';
				return $outcome;
			}
			$keys         = $snapshot['result'];
			$store_sig    = self::store_signature( $keys );
			$external     = self::external_candidates();
			$internal     = self::internal_candidates( $keys );
			$all          = self::normalize_candidates( array_merge( $external, $internal ) );
			$targets      = self::targets( $keys );
			$state        = self::load_state();
			$done         = $state['done'];
			$matched      = $state['matched'];
			$matcher      = new Mcpwp_Key_Hygiene_Matcher();
			$checks       = 0;
			$started      = microtime( true );
			$out_of       = false;

			$phase_list = array();
			if ( in_array( 'external', $phases, true ) ) {
				$phase_list[] = $external;
			}
			if ( in_array( 'internal', $phases, true ) ) {
				$phase_list[] = $internal;
			}

			foreach ( $phase_list as $phase_candidates ) {
				foreach ( $targets as $target => $meta ) {
					if ( isset( $matched[ $target ] ) ) {
						continue;
					}
					$hash = $meta['hash'];
					foreach ( $phase_candidates as $candidate ) {
						$cfp = self::candidate_fp( $candidate );
						if ( isset( $done[ $target ][ $cfp ] ) ) {
							continue;
						}
						if ( $checks > 0 && ( $checks >= $max_checks || ( microtime( true ) - $started ) >= $time_budget ) ) {
							$out_of = true;
							break 3;
						}
						++$checks;
						$done[ $target ][ $cfp ] = true;
						if ( $matcher->matches( $candidate, $hash ) ) {
							$matched[ $target ] = array(
								'id'           => $meta['id'],
								'label'        => $meta['label'],
								'candidate_fp' => $cfp,
							);
							break;
						}
					}
				}
			}

			/**
			 * Fires between the check phase and the revoke write (tests only).
			 */
			do_action( 'mcpwp_key_hygiene_between_checks' );

			$complete = ! $out_of && self::all_targets_done( $targets, $all, $done, $matched );
			if ( ! empty( $matched ) || $complete ) {
				$now   = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
				$write = Mcpwp_Key_Store::mutate(
					self::revoke_mutator( $store_sig, $external, $internal, $done, $matched, $complete, $now ),
					true
				);
				if ( ! $write['written'] ) {
					self::save_state( $done, $matched );
					self::save_pending_from_matched( $matched );
					$outcome['status'] = '' !== $write['reason'] ? $write['reason'] : 'busy';
					return $outcome;
				}
				if ( ! is_array( $write['result'] ) ) {
					// Store changed under the sweep: keep progress, retry next run.
					self::save_state( $done, $matched );
					$outcome['status'] = 'changed';
					return $outcome;
				}
				$outcome['revoked'] = $write['result']['revoked'];
				self::record_revoked( $write['result']['revoked'] );
				$clear_ids = array_column( $write['result']['revoked'], 'id' );
				if ( ! empty( $write['result']['resolved'] ) && is_array( $write['result']['resolved'] ) ) {
					$clear_ids = array_merge( $clear_ids, $write['result']['resolved'] );
				}
				self::clear_pending_ids( $clear_ids );
				foreach ( array_keys( $matched ) as $target ) {
					unset( $done[ $target ] );
				}
				$matched  = isset( $write['result']['matched'] ) && is_array( $write['result']['matched'] ) ? $write['result']['matched'] : array();
				$complete = $complete && ! empty( $write['result']['complete'] );
				$done     = isset( $write['result']['done'] ) && is_array( $write['result']['done'] ) ? $write['result']['done'] : $done;
			}

			if ( ! $complete ) {
				self::save_state( $done, $matched );
				$outcome['status'] = 'partial';
				return $outcome;
			}

			delete_option( self::STATE_OPTION );
			delete_option( self::PENDING_OPTION );
			update_option( self::FLAG, 1, true );
			self::clear_cron();
			return $outcome;
		} finally {
			Mcpwp_OAuth_Clients::release_lock( $lock, self::LOCK );
		}
	}

	/**
	 * Normalize the phases argument.
	 *
	 * @param mixed $phases Requested phases.
	 * @return array<int,string>
	 */
	private static function normalize_phases( $phases ) {
		if ( null === $phases || 'all' === $phases || '' === $phases ) {
			return array( 'external', 'internal' );
		}
		if ( 'external' === $phases ) {
			return array( 'external' );
		}
		if ( 'internal' === $phases ) {
			return array( 'internal' );
		}
		return array( 'external', 'internal' );
	}

	/**
	 * Schedule a spaced cron pass when the sweep is unfinished.
	 *
	 * @param bool $force Replace an existing schedule.
	 * @return void
	 */
	public static function schedule_cron( $force = false ) {
		if ( self::is_done() ) {
			self::clear_cron();
			return;
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		$next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( self::CRON_HOOK ) : false;
		if ( $next && ! $force ) {
			return;
		}
		if ( $next && $force && function_exists( 'wp_unschedule_event' ) ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
		wp_schedule_single_event( time() + self::CRON_SPACING, self::CRON_HOOK );
	}

	/**
	 * Drop any scheduled cron pass.
	 *
	 * @return void
	 */
	public static function clear_cron() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * The mutator that revokes the matched records.
	 *
	 * Applies nothing (result null) when the store signature changed since the
	 * snapshot. Otherwise re-resolves each match's candidate under the lock,
	 * re-verifies, and revokes every active record for that target. A match
	 * whose target is no longer active is dropped (and returned in resolved)
	 * so the sweep can complete.
	 *
	 * @param string $store_sig Snapshot signature.
	 * @param array  $external  External verifier candidates.
	 * @param array  $internal  Internal candidates from the snapshot.
	 * @param array  $done      Target => candidate_fp => true.
	 * @param array  $matched   Target => id/label/candidate_fp.
	 * @param bool   $complete  Whether every snapshot target was fully checked.
	 * @param string $now       Revocation time.
	 * @return callable
	 */
	private static function revoke_mutator( $store_sig, array $external, array $internal, array $done, array $matched, $complete, $now ) {
		return static function ( array $keys ) use ( $store_sig, $external, $internal, $done, $matched, $complete, $now ) {
			if ( self::store_signature( $keys ) !== $store_sig ) {
				return array( null, null );
			}
			$matcher  = new Mcpwp_Key_Hygiene_Matcher();
			$revoked  = array();
			$left     = array();
			$resolved = array();
			foreach ( $matched as $target => $info ) {
				$fp        = isset( $info['candidate_fp'] ) ? (string) $info['candidate_fp'] : '';
				$id        = isset( $info['id'] ) ? (string) $info['id'] : '';
				$candidate = '' !== $fp ? self::resolve_candidate( $fp, $external, $internal ) : '';
				$applied   = false;

				if ( '' === $candidate ) {
					// Cannot re-verify: drop the match and re-check this target.
					unset( $done[ $target ] );
					if ( '' !== $id ) {
						$resolved[] = $id;
					}
					continue;
				}

				foreach ( $keys as $index => $record ) {
					if ( ! self::is_active_with_hash( $record ) ) {
						continue;
					}
					if ( self::target_key( $record['hash'] ) !== $target ) {
						continue;
					}
					if ( ! $matcher->matches( $candidate, $record['hash'] ) ) {
						continue;
					}
					$keys[ $index ]['revoked_at'] = $now;
					$revoked[]                    = array(
						'id'         => isset( $record['id'] ) && is_scalar( $record['id'] ) ? (string) $record['id'] : $id,
						'label'      => isset( $record['label'] ) && is_scalar( $record['label'] ) ? (string) $record['label'] : ( isset( $info['label'] ) ? (string) $info['label'] : '' ),
						'revoked_at' => $now,
					);
					$applied = true;
				}

				if ( $applied ) {
					continue;
				}

				// Still an active record for this target? Keep for retry.
				$still_active = false;
				foreach ( $keys as $record ) {
					if ( self::is_active_with_hash( $record ) && self::target_key( $record['hash'] ) === $target ) {
						$still_active = true;
						break;
					}
				}
				if ( $still_active ) {
					$left[ $target ] = $info;
				} elseif ( '' !== $id ) {
					$resolved[] = $id;
				}
			}

			$still_complete = (bool) $complete;
			if ( $still_complete ) {
				$current_targets = self::targets( $keys );
				$current_all     = self::normalize_candidates( array_merge( $external, self::internal_candidates( $keys ) ) );
				$still_complete  = self::all_targets_done( $current_targets, $current_all, $done, $left );
			}

			return array(
				empty( $revoked ) ? null : $keys,
				array(
					'revoked'  => $revoked,
					'complete' => $still_complete && empty( $left ),
					'matched'  => $left,
					'done'     => $done,
					'resolved' => $resolved,
				),
			);
		};
	}

	/**
	 * Re-resolve a candidate string from its fingerprint under the lock.
	 *
	 * @param string $fp       Candidate fingerprint.
	 * @param array  $external External candidates.
	 * @param array  $internal Internal candidates.
	 * @return string Empty when not found.
	 */
	private static function resolve_candidate( $fp, array $external, array $internal ) {
		foreach ( self::normalize_candidates( array_merge( $external, $internal ) ) as $candidate ) {
			if ( self::candidate_fp( $candidate ) === $fp ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Whether every active target has either matched or checked every candidate.
	 *
	 * @param array $targets Target map.
	 * @param array $all     All candidates.
	 * @param array $done    Done set.
	 * @param array $matched Matched set.
	 * @return bool
	 */
	private static function all_targets_done( array $targets, array $all, array $done, array $matched ) {
		foreach ( $targets as $target => $_meta ) {
			if ( isset( $matched[ $target ] ) ) {
				continue;
			}
			foreach ( $all as $candidate ) {
				if ( ! isset( $done[ $target ][ self::candidate_fp( $candidate ) ] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Candidate strings from the options beside the store: verifiers only.
	 *
	 * @return array<string>
	 */
	private static function external_candidates() {
		$values = array();
		foreach ( array( get_option( 'mcpwp_api_key', '' ), get_option( 'spai_api_key', '' ) ) as $value ) {
			if ( is_string( $value ) && Mcpwp_Key_State::is_stored_verifier( $value ) ) {
				$values[] = $value;
			}
		}
		$spai = get_option( 'spai_api_keys', array() );
		if ( is_array( $spai ) ) {
			foreach ( $spai as $record ) {
				if ( is_array( $record ) && isset( $record['hash'] ) && is_string( $record['hash'] ) && Mcpwp_Key_State::is_stored_verifier( $record['hash'] ) ) {
					$values[] = $record['hash'];
				}
			}
		}
		return self::normalize_candidates( $values );
	}

	/**
	 * Hash strings from every store record that are themselves stored verifiers.
	 *
	 * @param array $keys Store records.
	 * @return array<string>
	 */
	private static function internal_candidates( array $keys ) {
		$values = array();
		foreach ( $keys as $record ) {
			if ( is_array( $record ) && isset( $record['hash'] ) && is_string( $record['hash'] ) && Mcpwp_Key_State::is_stored_verifier( $record['hash'] ) ) {
				$values[] = $record['hash'];
			}
		}
		return self::normalize_candidates( $values );
	}

	/**
	 * Strings only, plus canonical / alternate hex forms for MD5-shaped
	 * values, unique, sorted.
	 *
	 * @param array $values Raw values.
	 * @return array<int,string>
	 */
	private static function normalize_candidates( array $values ) {
		$out = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$out[ $value ] = true;
			$canonical     = Mcpwp_Key_State::canonical_verifier( $value );
			$out[ $canonical ] = true;
			if ( Mcpwp_Key_State::is_md5_shaped( $value ) ) {
				$out[ strtolower( $value ) ] = true;
				$out[ strtoupper( $value ) ] = true;
			}
		}
		$out = array_map( 'strval', array_keys( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Active records to judge: target fingerprint => id/label/hash, sorted.
	 *
	 * @param array $keys Store records.
	 * @return array<string,array{id:string,label:string,hash:string}>
	 */
	private static function targets( array $keys ) {
		$targets = array();
		foreach ( $keys as $record ) {
			if ( ! self::is_active_with_hash( $record ) ) {
				continue;
			}
			$targets[ self::target_key( $record['hash'] ) ] = array(
				'id'    => isset( $record['id'] ) && is_scalar( $record['id'] ) ? (string) $record['id'] : '',
				'label' => isset( $record['label'] ) && is_scalar( $record['label'] ) ? (string) $record['label'] : '',
				'hash'  => $record['hash'],
			);
		}
		ksort( $targets, SORT_STRING );
		return $targets;
	}

	/**
	 * Whether a record is active and carries a hash string to judge.
	 *
	 * @param mixed $record Store record.
	 * @return bool
	 */
	private static function is_active_with_hash( $record ) {
		return is_array( $record )
			&& empty( $record['revoked_at'] )
			&& isset( $record['hash'] )
			&& is_string( $record['hash'] )
			&& '' !== $record['hash'];
	}

	/**
	 * Fingerprint of one record's hash (the state never stores a hash).
	 *
	 * @param string $hash Stored hash.
	 * @return string
	 */
	private static function target_key( $hash ) {
		return 't' . hash( 'sha256', (string) $hash );
	}

	/**
	 * Fingerprint of one candidate string (HMAC with site salt).
	 *
	 * @param string $candidate Candidate.
	 * @return string
	 */
	private static function candidate_fp( $candidate ) {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'mcpwp-key-hygiene';
		return 'c' . hash_hmac( 'sha256', (string) $candidate, $salt );
	}

	/**
	 * Signature of the store's active and revoked hash set (ids + hashes).
	 *
	 * @param array $keys Store records.
	 * @return string
	 */
	private static function store_signature( array $keys ) {
		$parts = array();
		foreach ( $keys as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['hash'] ) || ! is_string( $record['hash'] ) ) {
				continue;
			}
			$id = isset( $record['id'] ) && is_scalar( $record['id'] ) ? (string) $record['id'] : '';
			$parts[] = $id . "\0" . $record['hash'] . "\0" . ( empty( $record['revoked_at'] ) ? '0' : '1' );
		}
		sort( $parts, SORT_STRING );
		return hash( 'sha256', implode( "\n", $parts ) );
	}

	/**
	 * Load durable progress. Orphaned entries from removed records are kept
	 * harmlessly; new records simply have empty done sets. Matched entries
	 * store only a candidate fingerprint — never the candidate string.
	 *
	 * @return array{done:array,matched:array}
	 */
	private static function load_state() {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$done = isset( $state['done'] ) && is_array( $state['done'] ) ? $state['done'] : array();
		// Migrate the previous index-based progress shape by discarding it.
		if ( isset( $state['progress'] ) && ! isset( $state['done'] ) ) {
			$done = array();
		}
		$matched_in = isset( $state['matched'] ) && is_array( $state['matched'] ) ? $state['matched'] : array();
		$matched    = array();
		foreach ( $matched_in as $target => $info ) {
			if ( ! is_string( $target ) || ! is_array( $info ) ) {
				continue;
			}
			$fp = '';
			if ( isset( $info['candidate_fp'] ) && is_scalar( $info['candidate_fp'] ) ) {
				$fp = (string) $info['candidate_fp'];
			} elseif ( isset( $info['candidate'] ) && is_scalar( $info['candidate'] ) && '' !== (string) $info['candidate'] ) {
				// One-time migrate: fingerprint a previously stored candidate and drop the plaintext.
				$fp = self::candidate_fp( (string) $info['candidate'] );
			}
			if ( '' === $fp ) {
				continue;
			}
			$matched[ $target ] = array(
				'id'           => isset( $info['id'] ) && is_scalar( $info['id'] ) ? (string) $info['id'] : '',
				'label'        => isset( $info['label'] ) && is_scalar( $info['label'] ) ? (string) $info['label'] : '',
				'candidate_fp' => $fp,
			);
		}
		return array(
			'done'    => $done,
			'matched' => $matched,
		);
	}

	/**
	 * Persist the sweep position (fingerprints only in matched).
	 *
	 * @param array $done    Target => candidate_fp => true.
	 * @param array $matched Target => id/label/candidate_fp.
	 * @return void
	 */
	private static function save_state( array $done, array $matched ) {
		$safe = array();
		foreach ( $matched as $target => $info ) {
			if ( ! is_string( $target ) || ! is_array( $info ) ) {
				continue;
			}
			$fp = isset( $info['candidate_fp'] ) ? (string) $info['candidate_fp'] : '';
			if ( '' === $fp ) {
				continue;
			}
			$safe[ $target ] = array(
				'id'           => isset( $info['id'] ) ? (string) $info['id'] : '',
				'label'        => isset( $info['label'] ) ? (string) $info['label'] : '',
				'candidate_fp' => $fp,
			);
		}
		update_option(
			self::STATE_OPTION,
			array(
				'done'    => $done,
				'matched' => $safe,
			),
			false
		);
	}

	/**
	 * Remember matched ids when a revoke write was refused.
	 *
	 * @param array $matched Matched map.
	 * @return void
	 */
	private static function save_pending_from_matched( array $matched ) {
		$list = self::pending_records();
		$have = array();
		foreach ( $list as $entry ) {
			$have[ $entry['id'] ] = true;
		}
		foreach ( $matched as $info ) {
			$id = isset( $info['id'] ) ? (string) $info['id'] : '';
			if ( '' === $id || isset( $have[ $id ] ) ) {
				continue;
			}
			$list[] = array(
				'id'    => $id,
				'label' => isset( $info['label'] ) ? (string) $info['label'] : '',
			);
			$have[ $id ] = true;
		}
		update_option( self::PENDING_OPTION, $list, true );
	}

	/**
	 * Drop pending entries that were revoked or resolved elsewhere.
	 *
	 * @param array $ids Ids to clear.
	 * @return void
	 */
	private static function clear_pending_ids( array $ids ) {
		if ( empty( $ids ) ) {
			return;
		}
		$drop = array_fill_keys( array_map( 'strval', $ids ), true );
		$list = array();
		foreach ( self::pending_records() as $entry ) {
			if ( ! isset( $drop[ $entry['id'] ] ) ) {
				$list[] = $entry;
			}
		}
		if ( empty( $list ) ) {
			delete_option( self::PENDING_OPTION );
			return;
		}
		update_option( self::PENDING_OPTION, $list, true );
	}

	/**
	 * Ids that matched but whose revoke write was refused.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	public static function pending_records() {
		$list = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id = isset( $entry['id'] ) && is_scalar( $entry['id'] ) ? (string) $entry['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'label' => isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? (string) $entry['label'] : '',
			);
		}
		return $out;
	}

	/**
	 * Add revoked records to the list the notice shows.
	 *
	 * @param array $revoked Revoked id/label/revoked_at entries.
	 * @return void
	 */
	private static function record_revoked( array $revoked ) {
		if ( empty( $revoked ) ) {
			return;
		}
		$list = self::revoked_records();
		foreach ( $revoked as $entry ) {
			$list[] = $entry;
		}
		update_option( self::REVOKED_OPTION, $list, true );
	}

	/**
	 * The records the sweep revoked.
	 *
	 * @return array<int,array{id:string,label:string,revoked_at:string}>
	 */
	public static function revoked_records() {
		$list = get_option( self::REVOKED_OPTION, array() );
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[] = array(
				'id'         => isset( $entry['id'] ) && is_scalar( $entry['id'] ) ? (string) $entry['id'] : '',
				'label'      => isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? (string) $entry['label'] : '',
				'revoked_at' => isset( $entry['revoked_at'] ) && is_scalar( $entry['revoked_at'] ) ? (string) $entry['revoked_at'] : '',
			);
		}
		return $out;
	}

	/**
	 * Load what the sweep needs; on the activation request mcpwp_load_plugin()
	 * has not run yet.
	 *
	 * @return void
	 */
	private static function load_dependencies() {
		if ( ! class_exists( 'Mcpwp_Key_State' ) ) {
			require_once __DIR__ . '/core/class-mcpwp-key-state.php';
		}
		if ( ! class_exists( 'Mcpwp_Key_Store' ) ) {
			require_once __DIR__ . '/core/class-mcpwp-key-store.php';
		}
		if ( ! class_exists( 'Mcpwp_OAuth_Clients' ) ) {
			require_once __DIR__ . '/core/class-mcpwp-oauth-clients.php';
		}
		if ( ! class_exists( 'Mcpwp_Key_Hygiene_Matcher' ) ) {
			require_once __DIR__ . '/class-mcpwp-key-hygiene-matcher.php';
		}
	}

	// ── reporting ─────────────────────────────────────────────────────────

	/**
	 * What to tell an administrator after a successful revoke.
	 *
	 * @return array<int,string> Sentences.
	 */
	private static function message_lines() {
		return array(
			__( 'API keys whose secret matched a stored verifier were revoked.', 'mcpwp' ),
			__( 'Create a new key under MCPWP → Setup and give it to any client that used one of these keys.', 'mcpwp' ),
			__( 'A 2.8 single key (spai_api_key) stored in MD5 form is adopted revoked when other keys exist; create a new key for any client that still uses it.', 'mcpwp' ),
		);
	}

	/**
	 * One escaped list item per revoked record: label and id, never a hash.
	 *
	 * @param array $records Revoked records.
	 * @return string HTML.
	 */
	private static function records_html( array $records ) {
		$items = '';
		foreach ( $records as $record ) {
			$label = '' !== $record['label'] ? $record['label'] : __( '(no label)', 'mcpwp' );
			$items .= '<li>' . esc_html( $label ) . ' <code>' . esc_html( $record['id'] ) . '</code></li>';
		}
		return '<ul>' . $items . '</ul>';
	}

	/**
	 * Add the test to Site Health.
	 *
	 * @param array $tests Registered tests.
	 * @return array
	 */
	public static function register_site_status_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$tests['direct']['mcpwp_key_hygiene'] = array(
			'label' => __( 'MCPWP API keys have their own secrets', 'mcpwp' ),
			'test'  => array( __CLASS__, 'run_site_status_test' ),
		);
		return $tests;
	}

	/**
	 * Site Health result.
	 *
	 * @return array
	 */
	public static function run_site_status_test() {
		$result = array(
			'label'       => __( 'MCPWP API keys have their own secrets', 'mcpwp' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'mcpwp' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'No MCPWP API key was revoked for having a secret that matched a stored verifier.', 'mcpwp' ) . '</p>',
			'actions'     => '',
			'test'        => 'mcpwp_key_hygiene',
		);

		$pending = self::pending_records();
		if ( ! empty( $pending ) ) {
			$ids_html = self::records_html( $pending );
			$result['status']         = 'critical';
			$result['label']          = __( 'MCPWP matched API keys but could not revoke them', 'mcpwp' );
			$result['badge']['color'] = 'red';
			$result['description']    = '<p>' . esc_html__( 'API keys matched a stored verifier but could not be revoked. The sweep will retry on the next eligible admin screen load or scheduled pass.', 'mcpwp' ) . '</p>' . $ids_html;
			$result['actions']        = '<p><a href="' . esc_url( admin_url( 'admin.php?page=mcpwp' ) ) . '">' . esc_html__( 'Open Setup', 'mcpwp' ) . '</a></p>';
			return $result;
		}

		$records = self::revoked_records();
		if ( empty( $records ) ) {
			return $result;
		}

		$description = '';
		foreach ( self::message_lines() as $line ) {
			$description .= '<p>' . esc_html( $line ) . '</p>';
		}
		$result['status']         = 'recommended';
		$result['label']          = __( 'MCPWP revoked API keys whose secret matched a stored verifier', 'mcpwp' );
		$result['badge']['color'] = 'orange';
		$result['description']    = $description . self::records_html( $records );
		$result['actions']        = '<p><a href="' . esc_url( admin_url( 'admin.php?page=mcpwp' ) ) . '">' . esc_html__( 'Create a new API key', 'mcpwp' ) . '</a></p>';
		return $result;
	}

	/**
	 * Whether the notice is shown to the current user.
	 *
	 * @return bool
	 */
	public static function should_show_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( empty( self::revoked_records() ) ) {
			return false;
		}
		return ! get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
	}

	/**
	 * admin_notices callback.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! self::should_show_notice() ) {
			return;
		}
		$lines = self::message_lines();
		echo '<div class="notice notice-warning" id="mcpwp-key-hygiene-notice">';
		echo '<p><strong>' . esc_html__( 'MCPWP revoked API keys.', 'mcpwp' ) . '</strong> ' . esc_html( $lines[0] ) . '</p>';
		echo self::records_html( self::revoked_records() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in records_html().
		echo '<p>' . esc_html( $lines[1] ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=mcpwp' ) ) . '">' . esc_html__( 'Open Setup', 'mcpwp' ) . '</a></p>';
		echo '<p>' . esc_html( $lines[2] ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 8px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::DISMISS_ACTION ) . '" />';
		wp_nonce_field( self::DISMISS_ACTION, self::NONCE_FIELD );
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Dismiss', 'mcpwp' ) . '</button>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Dismiss the notice for the current user.
	 *
	 * @param array $post Unslashed request fields.
	 * @return bool True when dismissed.
	 */
	public static function process_dismiss( array $post ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$nonce = isset( $post[ self::NONCE_FIELD ] ) ? (string) $post[ self::NONCE_FIELD ] : '';
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_ACTION ) ) {
			return false;
		}
		update_user_meta( get_current_user_id(), self::DISMISSED_META, 1 );
		return true;
	}

	/**
	 * admin-post handler for the dismiss button.
	 *
	 * @return void
	 */
	public static function handle_dismiss() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process_dismiss().
		if ( ! self::process_dismiss( wp_unslash( $_POST ) ) ) {
			wp_die( esc_html__( 'The request expired or you are not allowed to do this.', 'mcpwp' ), '', array( 'response' => 403 ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=mcpwp' ) );
		exit;
	}
}
