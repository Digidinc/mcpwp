<?php
/**
 * Upgrade-time key hygiene: revoke keys whose secret matches a stored value.
 *
 * A key's secret is never something the database already holds. After an
 * upgrade, every ACTIVE record in the scoped store (`mcpwp_api_keys`) is
 * checked against the verifier strings stored beside it — the values of
 * `mcpwp_api_key` and `spai_api_key`, each `spai_api_keys[*].hash`, and the
 * hash of every other record — with the same matcher authentication uses
 * (Mcpwp_Api_Auth::is_api_key_hash_match()). A record that verifies against
 * one of them is revoked. A normal record never does: its secret is a random
 * key that appears nowhere in the database. A record that carries a stored
 * verifier VERBATIM — the shape Mcpwp_Activator::adopt_spai_credentials()
 * writes, since it never re-hashes a verifier — does not match either: the
 * matcher never accepts a stored value as its own key. What does match is a
 * record whose hash verifies a value the database holds, such as one hashing
 * a pre-1.0.14 plaintext single key that is still in `mcpwp_api_key`.
 *
 * One-time and resumable:
 * - the work is bounded per run (a time budget and a cap on hash checks); the
 *   position is kept in a non-autoloaded state option, so the next run carries
 *   on where this one stopped;
 * - the position is bound to a fingerprint of the candidate set (every
 *   record's hash is in it), so a new record or a changed hash starts the
 *   sweep over;
 * - revocation happens inside Mcpwp_Key_Store::mutate() (lock + compare-and-
 *   swap), and is applied only when the store still holds exactly the set the
 *   checks were made against;
 * - the autoloaded flag is set only when every active record has been checked
 *   against every candidate and that final state was confirmed under the lock.
 *
 * Fails closed: an unreadable store, a busy lock or a refused write revokes
 * nothing and leaves the flag unset. A record without a hash string is never
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

	/** Lock row: one sweep at a time. */
	const LOCK = 'mcpwp_key_hygiene_lock';

	/** User meta: this user dismissed the notice. */
	const DISMISSED_META = 'mcpwp_key_hygiene_notice_dismissed';

	/** admin-post action (and nonce action) for dismissing the notice. */
	const DISMISS_ACTION = 'mcpwp_key_hygiene_notice_dismiss';

	/** Nonce field name. */
	const NONCE_FIELD = 'mcpwp_key_hygiene_nonce';

	/** Seconds of hash checks per run. Well under the store lock's TTL. */
	const TIME_BUDGET = 1.0;

	/** Hash checks per run, whichever limit is hit first. */
	const MAX_CHECKS = 100;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_for_admin' ) );
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
	 * admin_init: continue an unfinished sweep. One autoloaded read on a site
	 * that has finished; only an administrator's request does the work.
	 *
	 * @return void
	 */
	public static function maybe_run_for_admin() {
		if ( self::is_done() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::run_safely();
	}

	/**
	 * Run the sweep, never letting it fatal the request.
	 *
	 * @return array|null Outcome, or null when it threw.
	 */
	public static function run_safely() {
		try {
			return self::run();
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
	 * @param float|null $time_budget Seconds of hash checks (null: TIME_BUDGET).
	 * @param int|null   $max_checks  Hash checks (null: MAX_CHECKS). At least one
	 *                                check runs per pass, so every pass progresses.
	 * @return array{status:string,revoked:array} status is 'done' (finished, flag
	 *         set), 'partial' (budget ran out; run again), 'unreadable', 'busy',
	 *         'changed' (the store changed under the sweep; run again) or the
	 *         Mcpwp_Key_Store::mutate() reason that refused the write.
	 */
	public static function run( $time_budget = null, $max_checks = null ) {
		$time_budget = null === $time_budget ? self::TIME_BUDGET : (float) $time_budget;
		$max_checks  = null === $max_checks ? self::MAX_CHECKS : max( 1, (int) $max_checks );
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
			$keys = $snapshot['result'];

			$external    = self::external_candidates();
			$candidates  = self::candidates( $external, $keys );
			$targets     = self::targets( $keys );
			$fingerprint = self::fingerprint( $candidates );

			$state = get_option( self::STATE_OPTION, array() );
			if ( ! is_array( $state ) || ! isset( $state['fp'] ) || $state['fp'] !== $fingerprint ) {
				$state = array();
			}
			$progress = isset( $state['progress'] ) && is_array( $state['progress'] ) ? $state['progress'] : array();
			$matched  = isset( $state['matched'] ) && is_array( $state['matched'] ) ? $state['matched'] : array();

			// Hash checks, bounded.
			$matcher = new Mcpwp_Key_Hygiene_Matcher();
			$total   = count( $candidates );
			$checks  = 0;
			$started = microtime( true );
			$out_of  = false;
			foreach ( $targets as $target => $hash ) {
				$next = isset( $progress[ $target ] ) ? (int) $progress[ $target ] : 0;
				while ( $next < $total ) {
					if ( $checks > 0 && ( $checks >= $max_checks || ( microtime( true ) - $started ) >= $time_budget ) ) {
						$out_of = true;
						break;
					}
					++$checks;
					if ( $matcher->matches( $candidates[ $next ], $hash ) ) {
						$matched[ $target ] = true;
						$next               = $total; // Decided; the rest need not be tried.
						break;
					}
					++$next;
				}
				$progress[ $target ] = $next;
				if ( $out_of ) {
					break;
				}
			}

			// Revoke what matched, and confirm a finished sweep, under the lock.
			$complete = ! $out_of;
			if ( ! empty( $matched ) || $complete ) {
				$now   = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
				$write = Mcpwp_Key_Store::mutate( self::revoke_mutator( $external, $fingerprint, $progress, $matched, $total, $now ), true );
				if ( ! $write['written'] ) {
					self::save_state( $fingerprint, $progress, $matched );
					$outcome['status'] = '' !== $write['reason'] ? $write['reason'] : 'busy';
					return $outcome;
				}
				if ( ! is_array( $write['result'] ) ) {
					// The candidate set changed since the snapshot: start over.
					delete_option( self::STATE_OPTION );
					$outcome['status'] = 'changed';
					return $outcome;
				}
				$outcome['revoked'] = $write['result']['revoked'];
				self::record_revoked( $write['result']['revoked'] );
				// A revoked record is no longer a target; its hash stays a
				// candidate, so every other record's position still holds.
				foreach ( array_keys( $matched ) as $target ) {
					unset( $progress[ $target ] ); // Judged again should it ever be active again.
				}
				$matched  = array();
				$complete = $complete && $write['result']['complete'];
			}

			if ( ! $complete ) {
				self::save_state( $fingerprint, $progress, $matched );
				$outcome['status'] = 'partial';
				return $outcome;
			}

			delete_option( self::STATE_OPTION );
			update_option( self::FLAG, 1, true );
			return $outcome;
		} finally {
			Mcpwp_OAuth_Clients::release_lock( $lock, self::LOCK );
		}
	}

	/**
	 * The mutator that revokes the matched records.
	 *
	 * Applies nothing (result null) unless the store still yields exactly the
	 * candidate set the checks were made against. Reports the sweep complete
	 * only when every record active in the store, as read under the lock, was
	 * checked against every candidate.
	 *
	 * @param array  $external    Candidates from outside the store.
	 * @param string $fingerprint Candidate-set fingerprint the checks used.
	 * @param array  $progress    Target => next candidate index.
	 * @param array  $matched     Target => true.
	 * @param int    $total       Number of candidates.
	 * @param string $now         Revocation time.
	 * @return callable
	 */
	private static function revoke_mutator( array $external, $fingerprint, array $progress, array $matched, $total, $now ) {
		return static function ( array $keys ) use ( $external, $fingerprint, $progress, $matched, $total, $now ) {
			if ( self::fingerprint( self::candidates( $external, $keys ) ) !== $fingerprint ) {
				return array( null, null );
			}
			$revoked  = array();
			$complete = true;
			foreach ( $keys as $index => $record ) {
				if ( ! self::is_active_with_hash( $record ) ) {
					continue;
				}
				$target = self::target_key( $record['hash'] );
				if ( ! isset( $matched[ $target ] ) ) {
					if ( ! isset( $progress[ $target ] ) || (int) $progress[ $target ] < $total ) {
						$complete = false;
					}
					continue;
				}
				$keys[ $index ]['revoked_at'] = $now;
				$revoked[]                    = array(
					'id'         => isset( $record['id'] ) && is_scalar( $record['id'] ) ? (string) $record['id'] : '',
					'label'      => isset( $record['label'] ) && is_scalar( $record['label'] ) ? (string) $record['label'] : '',
					'revoked_at' => $now,
				);
			}
			return array(
				empty( $revoked ) ? null : $keys,
				array(
					'revoked'  => $revoked,
					'complete' => $complete,
				),
			);
		};
	}

	/**
	 * Candidate strings from the options beside the store.
	 *
	 * @return array<string>
	 */
	private static function external_candidates() {
		$values = array(
			get_option( 'mcpwp_api_key', '' ),
			get_option( 'spai_api_key', '' ),
		);
		$spai = get_option( 'spai_api_keys', array() );
		if ( is_array( $spai ) ) {
			foreach ( $spai as $record ) {
				if ( is_array( $record ) && isset( $record['hash'] ) ) {
					$values[] = $record['hash'];
				}
			}
		}
		return self::normalize_candidates( $values );
	}

	/**
	 * Every candidate: the external ones and every record's hash (revoked
	 * records included), each as stored and in canonical form, de-duplicated
	 * and sorted so the order is stable from run to run.
	 *
	 * A record's own hash is in the list too; the matcher never accepts a
	 * stored value as its own key.
	 *
	 * @param array $external External candidates.
	 * @param array $keys     Store records.
	 * @return array<int,string>
	 */
	private static function candidates( array $external, array $keys ) {
		$values = $external;
		foreach ( $keys as $record ) {
			if ( is_array( $record ) && isset( $record['hash'] ) ) {
				$values[] = $record['hash'];
			}
		}
		return self::normalize_candidates( $values );
	}

	/**
	 * Strings only, plus canonical forms, unique, sorted.
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
			$out[ Mcpwp_Key_State::canonical_verifier( $value ) ] = true;
		}
		$out = array_map( 'strval', array_keys( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Active records to judge: target fingerprint => hash, sorted.
	 *
	 * @param array $keys Store records.
	 * @return array<string,string>
	 */
	private static function targets( array $keys ) {
		$targets = array();
		foreach ( $keys as $record ) {
			if ( self::is_active_with_hash( $record ) ) {
				$targets[ self::target_key( $record['hash'] ) ] = $record['hash'];
			}
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
	 * Fingerprint of a candidate set.
	 *
	 * Every record's hash is a candidate, so adding a record or changing a
	 * hash changes it; revoking a record does not.
	 *
	 * @param array $candidates Candidates.
	 * @return string
	 */
	private static function fingerprint( array $candidates ) {
		return hash( 'sha256', implode( "\n", array_map( 'strval', $candidates ) ) );
	}

	/**
	 * Persist the sweep position.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @param array  $progress    Target => next candidate index.
	 * @param array  $matched     Target => true, not yet revoked.
	 * @return void
	 */
	private static function save_state( $fingerprint, array $progress, array $matched ) {
		update_option(
			self::STATE_OPTION,
			array(
				'fp'       => $fingerprint,
				'progress' => $progress,
				'matched'  => $matched,
			),
			false
		);
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
	 * What to tell an administrator.
	 *
	 * @return array<int,string> Sentences.
	 */
	private static function message_lines() {
		return array(
			__( 'API keys whose secret matched a value stored in the database were revoked.', 'mcpwp' ),
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
			'description' => '<p>' . esc_html__( 'No MCPWP API key was revoked for having a secret that matched a stored value.', 'mcpwp' ) . '</p>',
			'actions'     => '',
			'test'        => 'mcpwp_key_hygiene',
		);

		$records = self::revoked_records();
		if ( empty( $records ) ) {
			return $result;
		}

		$description = '';
		foreach ( self::message_lines() as $line ) {
			$description .= '<p>' . esc_html( $line ) . '</p>';
		}
		$result['status']         = 'recommended';
		$result['label']          = __( 'MCPWP revoked API keys whose secret matched a stored value', 'mcpwp' );
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
