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
 *    each, a 100-character plain-text name, a hard cap of 500 clients, a purge
 *    of clients never used and older than 7 days on every registration, and
 *    10 registrations per IP per hour.
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

	/** A client never used and older than this (7 days) is purged. */
	const UNUSED_CLIENT_MAX_AGE = 604800;

	/** Registrations allowed per IP address per window. */
	const REGISTRATIONS_PER_WINDOW = 10;

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

		if ( array_key_exists( 'token_endpoint_auth_method', $metadata ) && 'none' !== $metadata['token_endpoint_auth_method'] ) {
			return self::metadata_error( 'Only public clients are supported: token_endpoint_auth_method must be "none" (PKCE S256 is required instead of a secret).' );
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
	 * Register a client.
	 *
	 * Order matters: rate limit (cheapest, counts every attempt) → validate →
	 * purge stale clients → cap → write.  The id is returned only once the
	 * record is stored, so a client is never told about an id that does not
	 * exist.
	 *
	 * @param mixed  $metadata Decoded JSON body.
	 * @param string $ip       Caller IP (REMOTE_ADDR unless proxy headers are trusted).
	 * @param int    $now      Current time (testable).
	 * @return array|WP_Error RFC 7591 §3.2.1 client information response.
	 */
	public static function register( $metadata, $ip, $now = 0 ) {
		$now = $now > 0 ? (int) $now : time();

		$rate = self::consume_registration_slot( $ip, $now );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$draft = self::validate_registration_metadata( $metadata );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$clients = self::all();
		$before  = count( $clients );
		$clients = self::purge_stale( $clients, $now );

		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			if ( count( $clients ) !== $before ) {
				update_option( self::OPTION, $clients, false );
			}
			return new WP_Error(
				'temporarily_unavailable',
				'This site has too many registered apps. An administrator can remove unused ones; try again later.',
				array( 'status' => 429 )
			);
		}

		$client_id = self::CLIENT_ID_PREFIX . bin2hex( random_bytes( 16 ) );
		$record    = array(
			'client_id'            => $client_id,
			'client_name'          => $draft['client_name'],
			'redirect_uris'        => $draft['redirect_uris'],
			'created_at'           => $now,
			'last_used_at'         => 0,
			'registration_ip_hash' => self::hash_ip( $ip ),
		);

		$clients[ $client_id ] = $record;
		if ( ! update_option( self::OPTION, $clients, false ) ) {
			return new WP_Error( 'server_error', 'The client could not be stored.', array( 'status' => 500 ) );
		}

		return array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => $now,
			'client_name'                => $record['client_name'],
			'redirect_uris'              => $record['redirect_uris'],
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * Drop clients that were never used and are older than 7 days.
	 *
	 * @param array $clients Records keyed by id.
	 * @param int   $now     Current time.
	 * @return array
	 */
	public static function purge_stale( array $clients, $now ) {
		$cutoff = (int) $now - self::UNUSED_CLIENT_MAX_AGE;
		foreach ( $clients as $client_id => $record ) {
			if ( empty( $record['last_used_at'] ) && (int) $record['created_at'] < $cutoff ) {
				unset( $clients[ $client_id ] );
			}
		}
		return $clients;
	}

	/**
	 * Record that a client was used (a code was approved or redeemed), at
	 * most once per TOUCH_INTERVAL.  A used client is never purged.
	 *
	 * @param string $client_id Client id.
	 * @param int    $now       Current time (testable).
	 */
	public static function touch( $client_id, $now = 0 ) {
		$now = $now > 0 ? (int) $now : time();
		if ( ! self::looks_like_client_id( $client_id ) ) {
			return;
		}
		$clients = self::all();
		if ( ! isset( $clients[ $client_id ] ) ) {
			return;
		}
		if ( (int) $clients[ $client_id ]['last_used_at'] > $now - self::TOUCH_INTERVAL ) {
			return;
		}
		$clients[ $client_id ]['last_used_at'] = $now;
		update_option( self::OPTION, $clients, false );
	}

	// -------------------------------------------------------------------------
	// Rate limit
	// -------------------------------------------------------------------------

	/**
	 * Count one registration attempt against the caller's IP.
	 *
	 * Fixed one-hour window in a transient keyed by a hash of the IP, so the
	 * raw address is never stored.  Every attempt counts, valid or not: a
	 * malformed request costs the server work too.
	 *
	 * @param string $ip  Caller IP.
	 * @param int    $now Current time.
	 * @return true|WP_Error
	 */
	public static function consume_registration_slot( $ip, $now ) {
		$key    = 'mcpwp_oauth_dcr_' . substr( self::hash_ip( $ip ), 0, 32 );
		$bucket = get_transient( $key );

		if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['reset'] ) || (int) $bucket['reset'] <= $now ) {
			$bucket = array(
				'count' => 0,
				'reset' => $now + self::RATE_LIMIT_WINDOW,
			);
		}

		if ( (int) $bucket['count'] >= self::REGISTRATIONS_PER_WINDOW ) {
			return new WP_Error(
				'temporarily_unavailable',
				'Too many client registrations from this address. Try again later.',
				array(
					'status'      => 429,
					'retry_after' => max( 1, (int) $bucket['reset'] - $now ),
				)
			);
		}

		$bucket['count'] = (int) $bucket['count'] + 1;
		set_transient( $key, $bucket, max( 1, (int) $bucket['reset'] - $now ) );

		return true;
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
