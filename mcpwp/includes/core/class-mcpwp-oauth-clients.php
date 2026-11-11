<?php
/**
 * OAuth 2.1 client store — RFC 7591 Dynamic Client Registration.
 *
 * ChatGPT and Claude do not use a client id an administrator typed in: they
 * register themselves (DCR) and then send the user to the authorize endpoint
 * with the id they were given.  This class is the store behind
 * POST /wp-json/mcpwp/v1/oauth/register and the lookup the authorize and
 * token endpoints use.
 *
 * Design constraints (PR-2 design D3-D5):
 *  - Public clients only (token_endpoint_auth_method "none").  PKCE S256 is
 *    the proof of possession, so no client secret is ever minted or stored.
 *  - The registration endpoint is unauthenticated by design, so everything it
 *    can write is bounded: at most 5 redirect URIs of at most 2,000 characters
 *    each, a 100-character plain-text name, 10 registrations per (IP, redirect
 *    hosts) per hour, a count of LIVE pending registrations (never used to
 *    complete an authorization, for at most one hour) of 20 per address and
 *    100 per site, and a site-wide ceiling of 60 registrations per minute.  A
 *    pending client expires after one hour and stops counting; nothing is ever
 *    evicted before that, so every client_id handed out stays valid until its
 *    expiry.  A cap that is hit answers 429 with a Retry-After equal to the
 *    moment the oldest pending registration expires.  A client registered by
 *    an authenticated administrator, or one that completed an authorization,
 *    never counts and never expires.  The store is capped at 500 clients,
 *    enforced by evicting the oldest never-authorized, non-administrator
 *    client that has outlived the expiry window (refusing when none has).
 *  - Every read-modify-write of the store runs under a lock row, and a
 *    registration is verified in storage before its id is returned; a lost
 *    race is a 503 "retry", never a later invalid_client.
 *  - Redirect URIs are shape-checked here (https, or http on a loopback host
 *    for native apps; no fragment; no userinfo).  What actually stops an
 *    attacker-registered redirect is the consent screen, which shows the
 *    redirect host the code will go to (D7).
 *  - The store is its own non-autoloaded option, NEVER a key inside
 *    mcpwp_settings: Mcpwp_Settings::sanitize_settings() rebuilds that array
 *    from a fixed key list and silently drops everything else, which is how
 *    the old `oauth_redirect_uris` allow-list was deleted by any Settings save.
 *
 * @package MCPWP
 * @since   3.14.11
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registered OAuth clients: validation, registration, lookup, purge.
 */
class Mcpwp_OAuth_Clients {

	/**
	 * Option holding the client records, keyed by client_id.  Not autoloaded:
	 * it is read only on the OAuth endpoints, never on an ordinary page view.
	 *
	 * @var string
	 */
	const OPTION = 'mcpwp_oauth_clients';

	/**
	 * Prefix of every registered client id.  `mcpwpc_` + 32 lowercase hex
	 * characters survives sanitize_key() unchanged, which every endpoint
	 * applies to client_id.
	 *
	 * @var string
	 */
	const CLIENT_ID_PREFIX = 'mcpwpc_';

	/** Hard cap on stored clients. */
	const MAX_CLIENTS = 500;

	/** Redirect URIs per client. */
	const MAX_REDIRECT_URIS = 5;

	/** Characters per redirect URI. */
	const MAX_REDIRECT_URI_LENGTH = 2000;

	/** Characters in a client name. */
	const MAX_CLIENT_NAME_LENGTH = 100;

	/**
	 * A client never authorized (no code ever issued for it) and older than
	 * this (1 hour) is purged and stops counting against the registration
	 * limits — a real client completes consent within minutes of registering
	 * (and registers again if it did not), so an hour bounds both the junk an
	 * anonymous caller can park in the store and how long a flood can hold
	 * the pending-registration limits.  An authorized client is never purged
	 * or evicted.
	 */
	const UNUSED_CLIENT_MAX_AGE = 3600;

	/** Registrations allowed per (IP address, redirect hosts) per window. */
	const REGISTRATIONS_PER_WINDOW = 10;

	/**
	 * Live pending registrations (never authorized, not registered by an
	 * administrator, younger than UNUSED_CLIENT_MAX_AGE) one address (IPv4, or
	 * an IPv6 /64) may hold at once, across all redirect hosts.  Counted from
	 * the store, so a registration that expired or was authorized frees its
	 * slot and the limit can never outlive the registrations it counts.
	 */
	const ADDRESS_PENDING_LIMIT = 20;

	/**
	 * Live pending registrations the whole site may hold at once.  Above what
	 * three addresses can hold (3 x 20), so a small flood does not shut out a
	 * caller from a fresh address.  Nothing is displaced at the limit: a
	 * newcomer is refused with a 429 until the oldest pending one expires.
	 */
	const SITE_PENDING_LIMIT = 100;

	/**
	 * Registrations per minute the whole site accepts from non-administrator
	 * callers, whatever the number of addresses: the bound on storage writes.
	 */
	const SITE_REGISTRATIONS_PER_MINUTE = 60;

	/** Transient holding the per-minute site counter. */
	const MINUTE_BUCKET = 'mcpwp_oauth_dcr_site_minute';

	/** Lock row guarding every read-modify-write of the store. */
	const LOCK_OPTION = 'mcpwp_oauth_clients_lock';

	/** Seconds a lock holder is trusted before the lock may be stolen. */
	const LOCK_TTL = 10;

	/** Lock attempts before giving up with a 503. */
	const LOCK_ATTEMPTS = 3;

	/**
	 * Lock attempts a registration makes (about one second at the retry
	 * pause): a parallel burst of in-limit registrations queues on the lock
	 * instead of failing with a 503 after 150 ms.
	 */
	const REGISTER_LOCK_ATTEMPTS = 20;

	/** Pause between lock attempts (50 ms). */
	const LOCK_RETRY_MICROSECONDS = 50000;

	/** Rate-limit window (1 hour). */
	const RATE_LIMIT_WINDOW = 3600;

	/**
	 * last_used_at is rewritten at most this often (1 hour), so a busy client
	 * does not turn every token exchange into an option write.
	 */
	const TOUCH_INTERVAL = 3600;

	/**
	 * Hosts that count as loopback for native-app redirects (RFC 8252 §7.3).
	 * IPv6 appears bracketed because that is how wp_parse_url() reports it.
	 *
	 * @var string[]
	 */
	const LOOPBACK_HOSTS = array( 'localhost', '127.0.0.1', '[::1]' );

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Whether a string is shaped like a client id this store issues.
	 *
	 * @param string $client_id Candidate id.
	 * @return bool
	 */
	public static function looks_like_client_id( $client_id ) {
		return is_string( $client_id ) && 1 === preg_match( '/^mcpwpc_[0-9a-f]{32}$/', $client_id );
	}

	/**
	 * Validate one redirect URI for registration.
	 *
	 * Deliberately stricter than a URL parser: anything a browser, PHP and a
	 * proxy might read differently is refused rather than normalised, so the
	 * string that is stored is exactly the string a later authorize request
	 * must present.
	 *
	 * @param mixed $uri Candidate redirect URI.
	 * @return true|WP_Error
	 */
	public static function validate_redirect_uri( $uri ) {
		if ( ! is_string( $uri ) || '' === $uri ) {
			return self::redirect_error( 'Each redirect URI must be a non-empty string.' );
		}
		if ( strlen( $uri ) > self::MAX_REDIRECT_URI_LENGTH ) {
			return self::redirect_error( 'A redirect URI may be at most 2000 characters.' );
		}
		// Printable ASCII only: whitespace, control characters and raw
		// non-ASCII must arrive percent-encoded.  Backslash is refused too —
		// browsers treat it as "/" in the authority and PHP does not.
		if ( preg_match( '/[^\x21-\x7E]/', $uri ) || false !== strpos( $uri, '\\' ) ) {
			return self::redirect_error( 'A redirect URI may contain only printable ASCII characters (percent-encode anything else).' );
		}
		if ( false !== strpos( $uri, '#' ) ) {
			return self::redirect_error( 'A redirect URI must not contain a fragment.' );
		}

		// Lowercase scheme only, so exact matching never has to case-fold.
		if ( 0 === strpos( $uri, 'https://' ) ) {
			$scheme = 'https';
		} elseif ( 0 === strpos( $uri, 'http://' ) ) {
			$scheme = 'http';
		} else {
			return self::redirect_error( 'A redirect URI must use https:// (or http:// on localhost, 127.0.0.1 or [::1]).' );
		}

		// Authority = everything between "://" and the first "/" or "?".
		$rest      = substr( $uri, strlen( $scheme ) + 3 );
		$authority = preg_split( '#[/?]#', $rest, 2 );
		$authority = (string) $authority[0];
		if ( false !== strpos( $authority, '@' ) ) {
			return self::redirect_error( 'A redirect URI must not contain user information.' );
		}

		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return self::redirect_error( 'A redirect URI must be an absolute URL with a host.' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return self::redirect_error( 'A redirect URI must not contain user information or a fragment.' );
		}

		$host = (string) $parts['host'];
		if ( ! preg_match( '/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)$/', $host ) ) {
			return self::redirect_error( 'A redirect URI has an invalid host.' );
		}
		if ( isset( $parts['port'] ) && ( (int) $parts['port'] < 1 || (int) $parts['port'] > 65535 ) ) {
			return self::redirect_error( 'A redirect URI has an invalid port.' );
		}

		if ( 'http' === $scheme && ! in_array( strtolower( $host ), self::LOOPBACK_HOSTS, true ) ) {
			return self::redirect_error( 'Plain http:// redirect URIs are allowed only on localhost, 127.0.0.1 or [::1].' );
		}

		// The authorize endpoint reads redirect_uri through esc_url_raw().  A
		// URI that esc_url_raw() would rewrite could never match exactly, so
		// refuse it here rather than store something unusable.
		if ( esc_url_raw( $uri ) !== $uri ) {
			return self::redirect_error( 'A redirect URI contains characters WordPress would rewrite.' );
		}

		return true;
	}

	/**
	 * Validate the redirect_uris member of a registration request.
	 *
	 * @param mixed $uris Candidate list.
	 * @return string[]|WP_Error De-duplicated list, in the order given.
	 */
	public static function validate_redirect_uris( $uris ) {
		if ( ! is_array( $uris ) || empty( $uris ) || array_values( $uris ) !== $uris ) {
			return self::redirect_error( 'redirect_uris must be a non-empty JSON array of strings.' );
		}
		if ( count( $uris ) > self::MAX_REDIRECT_URIS ) {
			return self::redirect_error( 'At most 5 redirect URIs may be registered per client.' );
		}

		$clean = array();
		foreach ( $uris as $uri ) {
			$valid = self::validate_redirect_uri( $uri );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			if ( ! in_array( $uri, $clean, true ) ) {
				$clean[] = $uri;
			}
		}

		return $clean;
	}

	/**
	 * Whether a URI is an http:// loopback redirect (port-agnostic per RFC 8252).
	 *
	 * @param string $uri URI.
	 * @return bool
	 */
	public static function is_loopback_redirect( $uri ) {
		if ( ! is_string( $uri ) || 0 !== strpos( $uri, 'http://' ) ) {
			return false;
		}
		$host = wp_parse_url( $uri, PHP_URL_HOST );
		return is_string( $host ) && in_array( strtolower( $host ), self::LOOPBACK_HOSTS, true );
	}

	/**
	 * Whether a presented redirect_uri matches one registered URI.
	 *
	 * Exact string match, with the single RFC 8252 §7.3 exception: for an
	 * http loopback URI the port is ignored, because a native app picks a free
	 * port at run time.  Scheme, host, path and query must still be identical,
	 * and the presented URI must itself pass registration validation.
	 *
	 * @param string $registered Registered URI.
	 * @param string $presented  URI from the authorize request.
	 * @return bool
	 */
	public static function redirect_uri_matches( $registered, $presented ) {
		if ( ! is_string( $registered ) || ! is_string( $presented ) || '' === $presented ) {
			return false;
		}
		if ( hash_equals( $registered, $presented ) ) {
			return true;
		}
		if ( ! self::is_loopback_redirect( $registered ) || ! self::is_loopback_redirect( $presented ) ) {
			return false;
		}
		if ( true !== self::validate_redirect_uri( $presented ) ) {
			return false;
		}

		$a = wp_parse_url( $registered );
		$b = wp_parse_url( $presented );
		if ( ! is_array( $a ) || ! is_array( $b ) ) {
			return false;
		}

		foreach ( array( 'scheme', 'path', 'query' ) as $part ) {
			$left  = isset( $a[ $part ] ) ? (string) $a[ $part ] : '';
			$right = isset( $b[ $part ] ) ? (string) $b[ $part ] : '';
			if ( $left !== $right ) {
				return false;
			}
		}

		return strtolower( (string) $a['host'] ) === strtolower( (string) $b['host'] );
	}

	/**
	 * Validate a registration request body (RFC 7591 §2) into a record draft.
	 *
	 * Only public clients are accepted.  Members this server does not use
	 * (scope, logo_uri, contacts, …) are ignored, which RFC 7591 §3.2.1
	 * permits; members it does use must be values it can honour.
	 *
	 * @param mixed $metadata Decoded JSON body.
	 * @return array|WP_Error {client_name:string, redirect_uris:string[]}
	 */
	public static function validate_registration_metadata( $metadata ) {
		if ( ! is_array( $metadata ) ) {
			return self::metadata_error( 'The registration request must be a JSON object.' );
		}

		$redirect_uris = self::validate_redirect_uris( isset( $metadata['redirect_uris'] ) ? $metadata['redirect_uris'] : null );
		if ( is_wp_error( $redirect_uris ) ) {
			return $redirect_uris;
		}

		// RFC 7591 §3.2.1: the server may replace requested metadata with
		// values it supports.  Only public clients exist here, so any
		// requested token_endpoint_auth_method is replaced with "none" (and
		// the 201 response says so) instead of refusing a client that merely
		// asked for client_secret_post.  No secret is ever issued.
		if ( array_key_exists( 'token_endpoint_auth_method', $metadata ) && ! is_string( $metadata['token_endpoint_auth_method'] ) ) {
			return self::metadata_error( 'token_endpoint_auth_method must be a string.' );
		}

		if ( array_key_exists( 'grant_types', $metadata ) ) {
			$grants = $metadata['grant_types'];
			if ( ! is_array( $grants ) || array_diff( $grants, array( 'authorization_code', 'refresh_token' ) ) || ! in_array( 'authorization_code', $grants, true ) ) {
				return self::metadata_error( 'grant_types must include "authorization_code" and may add only "refresh_token".' );
			}
		}

		if ( array_key_exists( 'response_types', $metadata ) ) {
			$types = $metadata['response_types'];
			if ( ! is_array( $types ) || array( 'code' ) !== array_values( array_unique( $types ) ) ) {
				return self::metadata_error( 'response_types may only be ["code"].' );
			}
		}

		$name = '';
		if ( array_key_exists( 'client_name', $metadata ) ) {
			if ( ! is_string( $metadata['client_name'] ) ) {
				return self::metadata_error( 'client_name must be a string.' );
			}
			$name = self::clean_client_name( $metadata['client_name'] );
		}

		return array(
			'client_name'   => $name,
			'redirect_uris' => $redirect_uris,
		);
	}

	/**
	 * Reduce a client-supplied name to short plain text.
	 *
	 * The name is attacker-controlled (DCR is anonymous), and it is shown on
	 * the consent screen.  Tags go, and so do control and format characters —
	 * the latter include the Unicode bidi overrides that make "ChatGPT\u{202E}…"
	 * render as something else.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function clean_client_name( $name ) {
		$name = sanitize_text_field( (string) $name );
		$name = (string) preg_replace( '/[\p{Cc}\p{Cf}]+/u', '', $name );
		$name = trim( $name );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $name, 0, self::MAX_CLIENT_NAME_LENGTH, 'UTF-8' );
		}
		return substr( $name, 0, self::MAX_CLIENT_NAME_LENGTH );
	}

	// -------------------------------------------------------------------------
	// Store
	// -------------------------------------------------------------------------

	/**
	 * All stored client records, keyed by client id.  Malformed entries are
	 * dropped on read, so a corrupt row can never be mistaken for a client.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clients = array();
		foreach ( $stored as $client_id => $record ) {
			if ( self::is_valid_record( $client_id, $record ) ) {
				$clients[ $client_id ] = $record;
			}
		}

		return $clients;
	}

	/**
	 * Look up one registered client.
	 *
	 * @param string $client_id Client id (already sanitize_key()ed by the caller).
	 * @return array|null Record, or null when unknown or malformed.
	 */
	public static function find( $client_id ) {
		if ( ! self::looks_like_client_id( $client_id ) ) {
			return null;
		}
		$clients = self::all();
		return isset( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
	}

	/**
	 * Whether a presented redirect_uri is registered for a client.
	 *
	 * @param array  $client    Client record from find().
	 * @param string $presented redirect_uri from the request.
	 * @return bool
	 */
	public static function client_allows_redirect( $client, $presented ) {
		if ( ! is_array( $client ) || empty( $client['redirect_uris'] ) || ! is_array( $client['redirect_uris'] ) ) {
			return false;
		}
		foreach ( $client['redirect_uris'] as $registered ) {
			if ( self::redirect_uri_matches( $registered, $presented ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a client has ever been authorized (a code was issued for it).
	 *
	 * `last_used_at` counts too: records written before authorized_at existed
	 * were only ever touched on approval or redemption.
	 *
	 * @param array $record Client record.
	 * @return bool
	 */
	public static function has_been_authorized( $record ) {
		return is_array( $record ) && ( ! empty( $record['authorized_at'] ) || ! empty( $record['last_used_at'] ) );
	}

	/**
	 * Whether any registered client has ever been authorized.
	 *
	 * @return bool
	 */
	public static function any_client_authorized() {
		foreach ( self::all() as $record ) {
			if ( self::has_been_authorized( $record ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Register a client.
	 *
	 * Order matters:
	 *  1. validate (no state touched by a malformed request);
	 *  2. WITHOUT the lock: the rate limits are checked against the stored
	 *     state — a refused request never takes the store lock (so a flood of
	 *     refused requests cannot starve in-limit ones into a 503) and never
	 *     writes anything;
	 *  3. under the store lock: the same checks again on a fresh read (the
	 *     authoritative ones), the 1-hour purge, the 500 cap (refused, never
	 *     displacing a registration that has not expired), the write, and a
	 *     re-read that proves the new client landed; only then are the hourly
	 *     and per-minute buckets counted.  A request that passed the lock-free
	 *     pre-check and fails the re-check did take the lock; it counts
	 *     nothing.
	 *
	 * The limits are keyed on the address the caller passes — handle_register()
	 * passes the plugin's trusted resolution (REMOTE_ADDR unless
	 * MCPWP_TRUST_PROXY_HEADERS says otherwise).
	 *
	 * The id is returned only once the record is verified in storage.  Any
	 * failure to get the lock or to verify is a 503 "try again" — never a
	 * client_id that later turns out not to exist.
	 *
	 * @param mixed  $metadata   Decoded JSON body.
	 * @param string $ip         Caller IP.
	 * @param int    $now        Current time (testable).
	 * @param bool   $privileged True for an authenticated site administrator:
	 *                           exempt from the per-address, per-site and
	 *                           hourly limits (never from the 500 cap).
	 * @return array|WP_Error RFC 7591 §3.2.1 client information response.
	 */
	public static function register( $metadata, $ip, $now = 0, $privileged = false ) {
		$now = $now > 0 ? (int) $now : time();

		$draft = self::validate_registration_metadata( $metadata );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$hosts = self::redirect_hosts( $draft['redirect_uris'] );

		// Lock-free pre-check: a request the limits refuse never contends.
		$pre = self::plan_registration( self::purge_stale( self::all(), $now ), $ip, $hosts, $now, (bool) $privileged );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}

		$client_id = self::CLIENT_ID_PREFIX . bin2hex( random_bytes( 16 ) );
		$record    = array(
			'client_id'            => $client_id,
			'client_name'          => $draft['client_name'],
			'redirect_uris'        => $draft['redirect_uris'],
			'created_at'           => $now,
			'last_used_at'         => 0,
			'authorized_at'        => 0,
			'registration_ip_hash' => self::hash_ip( self::rate_limit_address( $ip ) ),
		);
		if ( $privileged ) {
			// Kept until used or deleted; counts against no limit.
			$record['registered_by_admin'] = true;
		}

		$token = self::acquire_lock( $now, self::LOCK_OPTION, self::REGISTER_LOCK_ATTEMPTS );
		if ( false === $token ) {
			return self::busy_error();
		}

		$result = self::register_locked( $record, $now, $ip, $hosts, (bool) $privileged );

		self::release_lock( $token );

		return $result;
	}

	/**
	 * The part of register() that runs under the store lock.
	 *
	 * @param array    $record     New client record.
	 * @param int      $now        Current time.
	 * @param string   $ip         Caller IP.
	 * @param string[] $hosts      Redirect hosts.
	 * @param bool     $privileged Exempt from the rate limits.
	 * @return array|WP_Error
	 */
	private static function register_locked( array $record, $now, $ip, array $hosts, $privileged ) {
		self::flush_option_cache( self::OPTION );

		$clients = self::purge_stale( self::all(), $now );

		$plan = self::plan_registration( $clients, $ip, $hosts, $now, $privileged );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		// At the cap, evict the oldest never-authorized client to make room.
		// An authorized client is never evicted: someone is using it.
		while ( count( $clients ) >= self::MAX_CLIENTS ) {
			$victim = self::oldest_never_authorized( $clients );
			if ( null === $victim ) {
				return new WP_Error(
					'temporarily_unavailable',
					'Too many app registrations right now; try again later.',
					array( 'status' => 429 )
				);
			}
			unset( $clients[ $victim ] );
		}

		$clients[ $record['client_id'] ] = $record;
		update_option( self::OPTION, $clients, false );

		// Verify from storage, not from the write's return value: update_option()
		// answers false for "unchanged" as well as "failed", and an object
		// cache can make a lost write look present.  Only a client that is
		// really there gets its id back.
		self::flush_option_cache( self::OPTION );
		if ( null === self::find( $record['client_id'] ) ) {
			return self::busy_error();
		}

		self::commit_registration_slot( $plan['state'], $now );

		return array(
			'client_id'                  => $record['client_id'],
			'client_id_issued_at'        => $now,
			'client_name'                => $record['client_name'],
			'redirect_uris'              => $record['redirect_uris'],
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * Decide a registration against the limits; change nothing.
	 *
	 * The pending registrations are the clients in $clients that were never
	 * authorized and were not registered by an administrator (the caller has
	 * already purged the expired ones).  Returns the bucket state to count
	 * after a verified write.
	 *
	 * @param array    $clients    Live client records (purge_stale() applied).
	 * @param string   $ip         Caller IP.
	 * @param string[] $hosts      Redirect hosts.
	 * @param int      $now        Current time.
	 * @param bool     $privileged Authenticated administrator: no limits.
	 * @return array|WP_Error array( 'state' => array ), or the 429.
	 */
	private static function plan_registration( array $clients, $ip, array $hosts, $now, $privileged ) {
		if ( $privileged ) {
			return array( 'state' => array() );
		}

		$address = self::hash_ip( self::rate_limit_address( $ip ) );

		$mine   = 0;
		$total  = 0;
		$oldest = PHP_INT_MAX;
		$mine_oldest = PHP_INT_MAX;
		foreach ( $clients as $record ) {
			if ( self::is_exempt_from_limits( $record ) ) {
				continue;
			}
			$created = (int) ( $record['created_at'] ?? 0 );
			++$total;
			$oldest = min( $oldest, $created );
			if ( isset( $record['registration_ip_hash'] ) && $address === $record['registration_ip_hash'] ) {
				++$mine;
				$mine_oldest = min( $mine_oldest, $created );
			}
		}

		if ( $mine >= self::ADDRESS_PENDING_LIMIT ) {
			return self::limit_error( $mine_oldest + self::UNUSED_CLIENT_MAX_AGE - $now );
		}
		if ( $total >= self::SITE_PENDING_LIMIT ) {
			return self::limit_error( $oldest + self::UNUSED_CLIENT_MAX_AGE - $now );
		}

		$minute = get_transient( self::MINUTE_BUCKET );
		if ( ! is_array( $minute ) || ! isset( $minute['count'], $minute['reset'] ) || (int) $minute['reset'] <= $now ) {
			$minute = array(
				'count' => 0,
				'reset' => $now + 60,
			);
		}
		if ( (int) $minute['count'] >= self::SITE_REGISTRATIONS_PER_MINUTE ) {
			return self::limit_error( (int) $minute['reset'] - $now );
		}

		$state = self::check_registration_slot( $ip, $hosts, $now );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$state['minute'] = array( self::MINUTE_BUCKET, $minute );

		return array( 'state' => $state );
	}

	/**
	 * Whether a client counts against no registration limit and is never
	 * purged for age: it completed an authorization, or an authenticated
	 * administrator registered it.
	 *
	 * @param mixed $record Client record.
	 * @return bool
	 */
	public static function is_exempt_from_limits( $record ) {
		return self::has_been_authorized( $record ) || ( is_array( $record ) && ! empty( $record['registered_by_admin'] ) );
	}

	/**
	 * The 429 "too many registrations" answer.
	 *
	 * @param int $retry_after Seconds until the oldest counted registration expires.
	 * @return WP_Error
	 */
	private static function limit_error( $retry_after ) {
		return new WP_Error(
			'temporarily_unavailable',
			'Too many app registrations right now; try again later.',
			array(
				'status'      => 429,
				'retry_after' => max( 1, (int) $retry_after ),
			)
		);
	}

	/**
	 * Id of the oldest (by created_at) client never authorized, or null.
	 *
	 * @param array $clients Records keyed by id.
	 * @return string|null
	 */
	private static function oldest_never_authorized( array $clients ) {
		$evictable_before = time() - self::UNUSED_CLIENT_MAX_AGE;
		$victim           = null;
		$oldest = PHP_INT_MAX;
		foreach ( $clients as $client_id => $record ) {
			// Only a client that has outlived its expiry window may be
			// evicted: a registration is valid until then.
			if ( self::is_exempt_from_limits( $record ) || (int) $record['created_at'] >= $evictable_before ) {
				continue;
			}
			if ( (int) $record['created_at'] < $oldest ) {
				$oldest = (int) $record['created_at'];
				$victim = $client_id;
			}
		}
		return $victim;
	}

	/**
	 * Drop clients never authorized and older than UNUSED_CLIENT_MAX_AGE
	 * (1 hour) — except a client an administrator registered.
	 *
	 * @param array $clients Records keyed by id.
	 * @param int   $now     Current time.
	 * @return array
	 */
	public static function purge_stale( array $clients, $now ) {
		$cutoff = (int) $now - self::UNUSED_CLIENT_MAX_AGE;
		foreach ( $clients as $client_id => $record ) {
			if ( ! self::is_exempt_from_limits( $record ) && (int) $record['created_at'] < $cutoff ) {
				unset( $clients[ $client_id ] );
			}
		}
		return $clients;
	}

	/**
	 * Record that a code is being issued for a client — BEFORE the code is
	 * minted, so an authorized client is never evicted while it holds one.
	 *
	 * @param string $client_id Client id.
	 * @param int    $now       Current time (testable).
	 * @return bool True when recorded (or already recorded); false when the
	 *              store could not be updated — the caller must not mint.
	 */
	public static function mark_authorized( $client_id, $now = 0 ) {
		$now = $now > 0 ? (int) $now : time();
		if ( ! self::looks_like_client_id( $client_id ) ) {
			return false;
		}

		$existing = self::find( $client_id );
		if ( null === $existing ) {
			return false;
		}
		if ( ! empty( $existing['authorized_at'] ) ) {
			return true;
		}

		$token = self::acquire_lock( $now );
		if ( false === $token ) {
			return false;
		}

		self::flush_option_cache( self::OPTION );
		$clients = self::all();
		$ok      = false;
		if ( isset( $clients[ $client_id ] ) ) {
			$clients[ $client_id ]['authorized_at'] = $now;
			$clients[ $client_id ]['last_used_at']  = $now;
			update_option( self::OPTION, $clients, false );
			self::flush_option_cache( self::OPTION );
			$check = self::find( $client_id );
			$ok    = is_array( $check ) && ! empty( $check['authorized_at'] );
		}

		self::release_lock( $token );

		return $ok;
	}

	/**
	 * Record that a client was used (a code was redeemed), at most once per
	 * TOUCH_INTERVAL.  Best effort: skipped when the lock is busy, because
	 * last_used_at only orders reporting, never eviction.
	 *
	 * @param string $client_id Client id.
	 * @param int    $now       Current time (testable).
	 */
	public static function touch( $client_id, $now = 0 ) {
		$now = $now > 0 ? (int) $now : time();
		if ( ! self::looks_like_client_id( $client_id ) ) {
			return;
		}
		$existing = self::find( $client_id );
		if ( null === $existing || (int) $existing['last_used_at'] > $now - self::TOUCH_INTERVAL ) {
			return;
		}

		$token = self::acquire_lock( $now );
		if ( false === $token ) {
			return;
		}
		self::flush_option_cache( self::OPTION );
		$clients = self::all();
		if ( isset( $clients[ $client_id ] ) ) {
			$clients[ $client_id ]['last_used_at'] = $now;
			update_option( self::OPTION, $clients, false );
		}
		self::release_lock( $token );
	}

	/**
	 * Set a client's revocation epoch (#1101 PR-3a): every token issued to it
	 * at or before $epoch stops working (Mcpwp_OAuth_Revocation::is_revoked()).
	 * Never lowers an epoch already stored.
	 *
	 * @param string $client_id Client id.
	 * @param int    $epoch     Revocation time, microseconds (Mcpwp_OAuth_Revocation::now()).
	 * @return bool True when the record now carries an epoch >= $epoch.
	 */
	public static function set_revoked_before( $client_id, $epoch ) {
		if ( null === self::find( $client_id ) ) {
			return false;
		}
		$token = self::acquire_lock();
		if ( false === $token ) {
			return false;
		}
		self::flush_option_cache( self::OPTION );
		$clients = self::all();
		$ok      = false;
		if ( isset( $clients[ $client_id ] ) ) {
			$clients[ $client_id ]['revoked_before'] = max( (int) $epoch, Mcpwp_OAuth_Revocation::to_micros( $clients[ $client_id ]['revoked_before'] ?? 0 ) );
			update_option( self::OPTION, $clients, false );
			self::flush_option_cache( self::OPTION );
			$check = self::find( $client_id );
			$ok    = is_array( $check ) && Mcpwp_OAuth_Revocation::to_micros( $check['revoked_before'] ?? 0 ) >= (int) $epoch;
		}
		self::release_lock( $token );
		return $ok;
	}

	/**
	 * Delete a registered client (#1101 PR-3a).  Its tokens die with it: a
	 * tombstone epoch is written first (Mcpwp_OAuth_Revocation), because the
	 * record — and the revoked_before it could carry — is about to be gone,
	 * and issued tokens live in transients that cannot be enumerated.  If the
	 * tombstone cannot be written, nothing is deleted.
	 *
	 * @param string $client_id Client id.
	 * @return bool True when the client is gone and its tokens are revoked.
	 */
	public static function delete( $client_id ) {
		if ( null === self::find( $client_id ) ) {
			return false;
		}
		if ( ! Mcpwp_OAuth_Revocation::tombstone_client( $client_id ) ) {
			return false;
		}
		$token = self::acquire_lock();
		if ( false === $token ) {
			return false;
		}
		self::flush_option_cache( self::OPTION );
		$clients = self::all();
		unset( $clients[ $client_id ] );
		update_option( self::OPTION, $clients, false );
		self::flush_option_cache( self::OPTION );
		$gone = null === self::find( $client_id );
		self::release_lock( $token );
		// #1101 PR-3b: the client's rows are marked revoked too (the
		// tombstone above is what refuses them; this keeps lists honest).
		if ( $gone && class_exists( 'Mcpwp_OAuth_Token_Store' ) && ! Mcpwp_OAuth_Token_Store::revoke_client_rows( $client_id ) ) {
			Mcpwp_OAuth_Revocation::report_write_failure( 'client', $client_id );
		}
		return $gone;
	}

	// -------------------------------------------------------------------------
	// Store lock
	// -------------------------------------------------------------------------

	/**
	 * Take the store lock: a plain INSERT of a lock row.
	 *
	 * Not add_option(): in WordPress that is a cached read-then-write whose
	 * INSERT uses ON DUPLICATE KEY UPDATE, so two concurrent callers can both
	 * "succeed" (see Mcpwp_Idempotency_Claim).  A plain INSERT against the
	 * UNIQUE key on option_name lets exactly one win.  The row holds its
	 * expiry; a holder that died is stolen after LOCK_TTL by a
	 * compare-and-swap UPDATE, so two stealers cannot both win.  A few short
	 * retries absorb ordinary contention.
	 *
	 * The same lock pattern guards other OAuth stores under their own row
	 * name (the D10 migration, Mcpwp_OAuth_Revocation): pass $name.
	 *
	 * @param int    $now  Current time.
	 * @param string $name Lock row (option_name); the client store's by default.
	 * @param int    $attempts Tries before giving up (LOCK_ATTEMPTS by default).
	 * @return string|false Token for release_lock(), or false.
	 */
	public static function acquire_lock( $now = 0, $name = self::LOCK_OPTION, $attempts = self::LOCK_ATTEMPTS ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
			return false; // Nothing to arbitrate with: refuse rather than run unlocked.
		}

		for ( $attempt = 0; $attempt < max( 1, (int) $attempts ); $attempt++ ) {
			if ( $attempt > 0 ) {
				usleep( self::LOCK_RETRY_MICROSECONDS );
			}
			$t     = $now > 0 ? (int) $now : time();
			$token = (string) ( $t + self::LOCK_TTL ) . '|' . bin2hex( random_bytes( 4 ) );

			$suppressed = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$inserted   = $wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => $token,
					'autoload'     => 'no',
				),
				array( '%s', '%s', '%s' )
			);
			if ( null !== $suppressed ) {
				$wpdb->suppress_errors( $suppressed );
			}
			if ( 1 === (int) $inserted ) {
				return $token;
			}

			// Held.  Steal only an expired lock, by compare-and-swap.
			if ( ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'update' ) ) {
				continue;
			}
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( ! is_string( $current ) || '' === $current ) {
				continue;
			}
			$expires = (int) strtok( $current, '|' );
			if ( $expires >= $t ) {
				continue;
			}
			$stolen = $wpdb->update(
				$wpdb->options,
				array( 'option_value' => $token ),
				array(
					'option_name'  => $name,
					'option_value' => $current,
				),
				array( '%s' ),
				array( '%s', '%s' )
			);
			if ( 1 === (int) $stolen ) {
				return $token;
			}
		}

		return false;
	}

	/**
	 * Release the store lock — compare-and-delete, so a holder that overran
	 * LOCK_TTL never deletes the lock someone else has since stolen.
	 *
	 * @param string $token Token from acquire_lock().
	 * @param string $name  Lock row the token was taken on.
	 */
	public static function release_lock( $token, $name = self::LOCK_OPTION ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'delete' ) ) {
			return;
		}
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => (string) $token,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Drop the object-cache copy of an option so the next get_option() reads
	 * the database (the option is not autoloaded, so it is cached on its own).
	 *
	 * @param string $name Option name.
	 */
	public static function flush_option_cache( $name ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
		}
	}

	/**
	 * The "store busy, retry" answer.
	 *
	 * @return WP_Error
	 */
	private static function busy_error() {
		return new WP_Error(
			'temporarily_unavailable',
			'The app could not be registered because the site was busy. Nothing was saved; retry the registration.',
			array(
				'status'      => 503,
				'retry_after' => 1,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Rate limit
	// -------------------------------------------------------------------------

	/**
	 * The distinct, lower-cased hosts of a redirect list, sorted.
	 *
	 * @param string[] $uris Validated redirect URIs.
	 * @return string[]
	 */
	public static function redirect_hosts( array $uris ) {
		$hosts = array();
		foreach ( $uris as $uri ) {
			$host = strtolower( (string) wp_parse_url( $uri, PHP_URL_HOST ) );
			if ( '' !== $host ) {
				$hosts[ $host ] = true;
			}
		}
		$hosts = array_keys( $hosts );
		sort( $hosts );
		return $hosts;
	}

	/**
	 * Read and check the (caller IP, redirect hosts) hourly bucket; count
	 * nothing.  Varying the hosts buys an attacker new hourly buckets but not
	 * more pending registrations: the per-address and per-site pending limits
	 * (plan_registration()) cap those across all hosts.
	 *
	 * @param string   $ip    Caller IP.
	 * @param string[] $hosts Redirect hosts.
	 * @param int      $now   Current time.
	 * @return array|WP_Error Bucket state for commit_registration_slot(), or the 429.
	 */
	public static function check_registration_slot( $ip, array $hosts, $now ) {
		$address = self::rate_limit_address( $ip );
		$key     = 'mcpwp_oauth_dcr_' . substr( self::hash_ip( $address . '|' . implode( ',', $hosts ) ), 0, 32 );
		$bucket  = get_transient( $key );
		if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['reset'] ) || (int) $bucket['reset'] <= $now ) {
			$bucket = array(
				'count' => 0,
				'reset' => $now + self::RATE_LIMIT_WINDOW,
			);
		}
		if ( (int) $bucket['count'] >= self::REGISTRATIONS_PER_WINDOW ) {
			return self::limit_error( (int) $bucket['reset'] - $now );
		}
		return array( 'hosts' => array( $key, $bucket ) );
	}

	/**
	 * Count one registration in the bucket checked by check_registration_slot().
	 *
	 * @param array $state Bucket state.
	 * @param int   $now   Current time.
	 */
	public static function commit_registration_slot( array $state, $now ) {
		foreach ( $state as list( $key, $bucket ) ) {
			$bucket['count'] = (int) $bucket['count'] + 1;
			set_transient( $key, $bucket, max( 1, (int) $bucket['reset'] - $now ) );
		}
	}

	/**
	 * The address a rate-limit bucket is keyed on (P1-B).
	 *
	 * IPv4: the address itself.  IPv6: its /64 prefix — one end site is
	 * normally handed a whole /64, so keying on the full address would give
	 * every caller 2^64 fresh buckets.  An IPv4-mapped IPv6 address
	 * (::ffff:a.b.c.d) is the IPv4 address it carries.  Anything that does
	 * not parse is keyed as given (all unparseable callers share it).
	 *
	 * @param string $ip Caller IP.
	 * @return string
	 */
	public static function rate_limit_address( $ip ) {
		$ip     = trim( (string) $ip );
		$binary = '' !== $ip ? @inet_pton( $ip ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on garbage; false is the answer.
		if ( false === $binary ) {
			return $ip;
		}
		if ( 4 === strlen( $binary ) ) {
			return (string) inet_ntop( $binary );
		}
		if ( 16 !== strlen( $binary ) ) {
			return $ip;
		}
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $binary, 0, 12 ) ) {
			return (string) inet_ntop( substr( $binary, 12, 4 ) );
		}
		return (string) inet_ntop( substr( $binary, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * Keyed hash of an IP address (never stored in clear).
	 *
	 * @param string $ip IP address.
	 * @return string 64 hex characters.
	 */
	public static function hash_ip( $ip ) {
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'mcpwp-oauth-clients';
		return hash_hmac( 'sha256', (string) $ip, $secret );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Whether a stored entry is a well-formed client record.
	 *
	 * @param mixed $client_id Array key.
	 * @param mixed $record    Stored value.
	 * @return bool
	 */
	private static function is_valid_record( $client_id, $record ) {
		return self::looks_like_client_id( $client_id )
			&& is_array( $record )
			&& isset( $record['client_id'] ) && $client_id === $record['client_id']
			&& isset( $record['redirect_uris'] ) && is_array( $record['redirect_uris'] ) && ! empty( $record['redirect_uris'] )
			&& isset( $record['created_at'] )
			&& array_key_exists( 'last_used_at', $record );
	}

	/**
	 * @param string $message Description.
	 * @return WP_Error
	 */
	private static function redirect_error( $message ) {
		return new WP_Error( 'invalid_redirect_uri', $message, array( 'status' => 400 ) );
	}

	/**
	 * @param string $message Description.
	 * @return WP_Error
	 */
	private static function metadata_error( $message ) {
		return new WP_Error( 'invalid_client_metadata', $message, array( 'status' => 400 ) );
	}
}
