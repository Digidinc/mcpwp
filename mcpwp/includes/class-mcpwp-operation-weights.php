<?php
/**
 * Operation weights.
 *
 * What one operation costs (#802, #752 F9), for both ceilings that price work:
 *
 * - the weighted rate limiter (tool_weight): a call is charged for the work it
 *   will do, not for being one HTTP message — a list of items costs its length,
 *   and a tool that acts on many posts without carrying a list costs a fixed
 *   weight from one map;
 * - the per-request spend budget (spend_weight): the same, plus a price on
 *   credential-spending and egress routes, where the ceiling that matters is
 *   money and outbound traffic rather than CPU.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operation weights.
 */
final class Mcpwp_Operation_Weights {

	/**
	 * Tools that act on many posts without carrying a list of them.
	 *
	 * wp_bulk_find_replace rewrites Elementor data across a post type or the
	 * whole site, up to 200 posts a run; wp_delete_all_drafts removes every
	 * draft. Weighed by their list arguments alone, each cost one slot. `ids`
	 * names the argument an explicit id list arrives in; a list longer than the
	 * weight is charged per id. `route` is the REST route the tool dispatches, so
	 * a batch operation that reaches the same handler is priced the same.
	 *
	 * @var array<string,array{weight:int,ids:string,route:string}>
	 */
	const TOOL_WEIGHTS = array(
		'wp_bulk_find_replace' => array(
			'weight' => 10,
			'ids'    => 'ids',
			'route'  => '/elementor/find-replace',
			// #891: the fixed weight prices a run the caller did NOT scope to
			// named posts — a whole post type or the whole site. A call that
			// names its posts (id or ids) is priced by scope instead, in
			// tool_weight() below.
			'scoped' => true,
		),
		'wp_delete_all_drafts' => array(
			'weight' => 10,
			'ids'    => 'ids',
			'route'  => '/drafts/delete-all',
		),
	);

	/**
	 * Spend units of one credential-spending or egress call (#752 F9).
	 */
	const SPEND_EGRESS_WEIGHT = 25;

	/**
	 * Routes that spend a stored provider credential or fetch from the network,
	 * one call each. Relative to the plugin namespace, lower case.
	 *
	 * @var string[]
	 */
	const SPEND_EGRESS_ROUTES = array(
		'/integrations/generate-image',
		'/integrations/generate-featured-image',
		'/integrations/text-to-speech',
		'/integrations/describe-image',
		'/integrations/generate-alt-text',
		'/integrations/generate-excerpt',
		'/media/from-url',
	);

	/**
	 * Every other route that makes an outbound request with a site credential
	 * or to a caller-chosen URL, by pattern against the normalised route, with
	 * the methods that do: 'any', or 'write' where the same path's GET is a
	 * local read (anything but GET, HEAD and OPTIONS — an unreadable method is
	 * priced as the write).
	 *
	 * Patterns match both a request path ("/webhooks/5/test") and the route
	 * pattern core matched ("/webhooks/(?p<id>\d+)/test"). Not listed, because
	 * they spend no credential and cannot be pointed elsewhere: /fetch (resolves
	 * a URL to a local post), and the same-host loopbacks to this site's own
	 * permalinks (/rendered-copy, /seo/readiness, /seo/structured-data,
	 * /elementor/{id}/preview, /custom-css). /rendered-html is priced instead
	 * by requested size — SPEND_SIZE_ROUTES below — since it holds its whole
	 * window in memory even though it spends no credential (#804 W5).
	 *
	 * @var array<string,string>
	 */
	const SPEND_EGRESS_PATTERNS = array(
		// Cloudflare screenshot worker token; the URL is the caller's.
		'#^/screenshot$#'                            => 'any',
		// Pexels key: search spends quota, download also fetches the image.
		'#^/integrations/stock-photos(/download)?$#' => 'any',
		// Probes stored provider credentials.
		'#^/integrations/test$#'                     => 'any',
		'#^/integrations/providers/[^/]+/test$#'     => 'any',
		// Cloudflare API token.
		'#^/cloudflare(/|$)#'                        => 'any',
		// Figma token; status may refresh an OAuth token.
		'#^/figma(/|$)#'                             => 'any',
		// Google service account.
		'#^/google-indexing(/|$)#'                   => 'any',
		// Hosting provider API credentials.
		'#^/staging(/|$)#'                           => 'any',
		// Google Suggest, plus DataForSEO enrichment.
		'#^/keyword-research$#'                      => 'any',
		// DataForSEO.
		'#^/seo/visibility/refresh$#'                => 'any',
		'#^/seo/opportunities$#'                     => 'any',
		// Cloudflare analytics when a Cloudflare token is configured.
		'#^/seo/audit$#'                             => 'any',
		// Delivers to the webhook's URL, which the caller chose.
		'#^/webhooks/[^/]+/test$#'                   => 'any',
		// GitHub token (issue relay).
		'#^/feedback/relay$#'                        => 'any',
		'#^/feedback$#'                              => 'write',
		// Downloads from a caller-supplied URL.
		'#^/favicon$#'                               => 'write',
		'#^/update$#'                                => 'write',
		'#^/design-references(/[^/]+)?$#'            => 'write',
	);

	/**
	 * Routes that fetch one URL per item, and the arguments the items arrive
	 * in. The larger list is the price, whichever one the handler reads.
	 *
	 * @var array<string,string[]>
	 */
	const SPEND_URL_ITEM_ROUTES = array(
		'/media/bulk'             => array( 'urls', 'items' ),
		// One Indexing API request per URL (up to 200).
		'/google-indexing/submit' => array( 'urls' ),
		// #804 adversarial review (BLOCK-1): a widths sweep is one render — and
		// one media attachment when save_to_media is on — per width, not one.
		// Before this, /screenshot's flat 25-unit egress price (SPEND_EGRESS_
		// PATTERNS below) let a single call with 6 widths do 6x the outbound
		// renders and attachments of an ordinary screenshot for the same
		// charge. Listing it here (checked before SPEND_EGRESS_PATTERNS)
		// prices it per width instead; a call with no widths still resolves to
		// max(1, 0) = 1 item, unchanged from before. '/screenshot' is priced
		// from effective_screenshot_widths() (round 2, N-R2-2), not a raw
		// count of whatever the caller sent — see default_spend_weight().
		'/screenshot'             => array( 'widths' ),
	);

	/**
	 * Bulk routes whose items arrive as an id list — an array, or one
	 * comma-separated string — rather than a list of objects, and the argument
	 * it arrives in. Each id is one item processed.
	 *
	 * @var array<string,string>
	 */
	const SPEND_ID_LIST_ROUTES = array(
		'/elementor/bulk' => 'ids',
	);

	/**
	 * Routes priced by how much data one call asks for rather than by a list
	 * of items (#804 adversarial review, W5/W-R2-2): a call at or under
	 * `baseline` costs 1 (unchanged from before this pricing existed —
	 * every default-sized caller stays at 1), and anything above `baseline`
	 * costs `1 + ceil((clamped - baseline) / unit_bytes)`, clamped to
	 * `ceiling` FIRST.
	 *
	 * /rendered-html holds its whole requested window in memory for one
	 * response; before this it carried no list argument and so priced as an
	 * ordinary 1-unit call regardless of max_bytes, letting a batch of
	 * near-ceiling reads cost the same as a batch of tiny ones.
	 *
	 * Round 2 (W-R2-2): the first pass priced 1 unit per 64KB flat from
	 * zero, which put the 512KB ceiling at only 8 units — a batch of 100
	 * ceiling-sized reads (800) still fit inside the 1000-unit budget, so
	 * the batch-level ceiling this exists to bind never actually bound.
	 * Pricing flat from zero at 16KB/unit fixed that (ceiling = 32) but
	 * also repriced the ordinary default (51200) at 4 units — a quiet 4x
	 * increase for every existing default caller that never asked for
	 * anything unusual.
	 *
	 * Round 3 fixes that: the baseline (the handler's own default,
	 * RENDERED_HTML_DEFAULT_MAX_BYTES) stays flat at 1, and only bytes
	 * requested ABOVE it are metered — so nothing changes for a default
	 * caller, while a call that actually asks for more still scales:
	 * 512KB is 1 + ceil((524288-51200)/16384) = 1 + 29 = 30 units, and a
	 * batch of 100 such reads (3000) is still refused whole against the
	 * 1000 budget.
	 *
	 * `ceiling` here must be kept equal to
	 * Mcpwp_REST_Site_Content::RENDERED_HTML_MAX_BYTES_CEILING, and
	 * `baseline` equal to Mcpwp_REST_Site_Content::RENDERED_HTML_DEFAULT_
	 * MAX_BYTES — an operation-weights unit test asserts both match, since
	 * this class must not (and, being lower-level, cannot cleanly) depend
	 * on that REST class directly.
	 *
	 * Pricing clamps the requested value to `ceiling` BEFORE dividing
	 * (N-R2-1 — the other half of W-R2-2): the handler itself clamps
	 * max_bytes to the ceiling before ever reading that many bytes, so
	 * pricing an unclamped number would price memory the call never
	 * actually holds.
	 *
	 * @var array<string,array{param:string,baseline:int,unit_bytes:int,ceiling:int}>
	 */
	const SPEND_SIZE_ROUTES = array(
		'/rendered-html' => array(
			'param'      => 'max_bytes',
			'baseline'   => 51200,
			'unit_bytes' => 16384,
			'ceiling'    => 524288,
		),
	);

	/**
	 * Entry points that only dispatch other operations. Each operation they
	 * dispatch is charged as it runs, so the container itself is free —
	 * charging it too would bill every operation twice.
	 *
	 * @var string[]
	 */
	const SPEND_CONTAINER_ROUTES = array(
		'/batch',
		'/mcp',
	);

	/**
	 * Arguments that fan out into one operation per item.
	 *
	 * Aliases of one list sit in one group and are charged once — the largest
	 * (urls beside items is one upload list spelled twice); genuinely different
	 * lists are charged each — the sum (pages beside posts). #802 rounds 4–5.
	 *
	 * @var array<int,string[]>
	 */
	const LIST_GROUPS = array(
		array( 'operations' ),
		array( 'updates', 'items', 'urls', 'files', 'media' ),
		array( 'pages' ),
		array( 'posts' ),
		array( 'page_ids', 'post_ids' ),
		array( 'rows' ),
		array( 'widgets' ),
		array( 'sections' ),
		// #804: a screenshot widths sweep fans out into one render per width —
		// the weighted rate limiter must see that too, not just the spend
		// budget (SPEND_URL_ITEM_ROUTES above).
		array( 'widths' ),
	);

	/**
	 * How many items the list arguments of a call carry. Zero when none.
	 *
	 * @param mixed $arguments Tool or route arguments.
	 * @return int
	 */
	public static function list_count( $arguments ) {
		if ( ! is_array( $arguments ) ) {
			return 0;
		}

		$count = 0;
		foreach ( self::LIST_GROUPS as $group ) {
			$largest = 0;
			foreach ( $group as $key ) {
				if ( ! isset( $arguments[ $key ] ) ) {
					continue;
				}

				// #804 N-R2-2/N-R3-2: widths is rate-limited (and priced,
				// via SPEND_URL_ITEM_ROUTES below) by the EFFECTIVE count —
				// after the same clamp(320-1920)/dedupe/cap(6) pipeline
				// capture_multi_width() actually runs — not the raw list
				// length or a plain count(). Without this, 40 copies of the
				// same width (or 40 out-of-range widths that all clamp to
				// one value) would cost 40 rate-limit slots for a call that
				// only ever renders one image; a comma-separated string
				// (some MCP clients encode it that way) would count as
				// zero. effective_screenshot_widths() handles both shapes.
				if ( 'widths' === $key ) {
					$largest = max( $largest, count( self::effective_screenshot_widths( $arguments[ $key ] ) ) );
					continue;
				}

				if ( is_array( $arguments[ $key ] ) ) {
					$largest = max( $largest, count( $arguments[ $key ] ) );
				}
			}
			$count += $largest;
		}

		return $count;
	}

	/**
	 * The viewport widths a /screenshot call will actually render, after the
	 * same pipeline Mcpwp_REST_Screenshot::capture_multi_width() applies:
	 * clamp each to 320-1920, de-duplicate, cap at 6 (#804 N-R2-2).
	 *
	 * Pricing and the weighted rate limiter both call this — not their own
	 * copy of the clamp/dedupe/cap logic — so neither can price a different
	 * width count than what the request actually runs. Accepts a comma-
	 * separated string the same way an id-list argument is accepted
	 * elsewhere in this class.
	 *
	 * @param mixed $widths Raw widths value: an array, or a comma-separated
	 *                       string/scalar.
	 * @return array<int,int> Effective widths, in the order they will run.
	 */
	public static function effective_screenshot_widths( $widths ) {
		if ( is_string( $widths ) || is_int( $widths ) ) {
			$widths = array_filter( array_map( 'trim', explode( ',', (string) $widths ) ), 'strlen' );
		}

		if ( ! is_array( $widths ) ) {
			return array();
		}

		$clamped = array_map(
			static function ( $w ) {
				return max( 320, min( 1920, absint( $w ) ) );
			},
			$widths
		);

		return array_slice( array_values( array_unique( $clamped ) ), 0, 6 );
	}

	/**
	 * Rate-limit slots one tool call costs.
	 *
	 * The largest of: one; the items its lists carry; the tool's own weight —
	 * TOOL_WEIGHTS, else 1 — as filtered by `mcpwp_tool_operation_weight`; and
	 * the length of an explicit id list for a tool in the map. A filter can move
	 * a tool's own weight up or down, never below the lists the call carries,
	 * and a filtered value that is not a weight leaves the default in place.
	 *
	 * @param string $tool_name Tool name ('' when unknown).
	 * @param mixed  $arguments Tool arguments.
	 * @return int At least 1.
	 */
	public static function tool_weight( $tool_name, $arguments ) {
		$arguments = is_array( $arguments ) ? $arguments : array();
		$weight    = max( 1, self::list_count( $arguments ) );

		if ( ! is_string( $tool_name ) || '' === $tool_name ) {
			return $weight;
		}

		$spec    = array_key_exists( $tool_name, self::TOOL_WEIGHTS ) ? self::TOOL_WEIGHTS[ $tool_name ] : null;
		$default = null !== $spec ? (int) $spec['weight'] : 1;

		// #891: a `scoped` tool named its posts (id or ids) rather than
		// asking for a whole post type or the whole site — one post, or an
		// explicit id list, costs what it touches, not the fixed weight a
		// genuinely wide run costs. A single-id wp_bulk_find_replace was
		// billed the full fixed weight regardless of scope, which could
		// exhaust a caller's entire write burst on one post.
		if ( null !== $spec && ! empty( $spec['scoped'] ) && self::find_replace_names_posts( $arguments ) ) {
			$default = max( 1, self::id_list_count( $arguments, $spec['ids'] ) );
		}

		$own = $default;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the rate-limit weight of one tool call (#802).
			 *
			 * The call is still charged at least the items its list arguments
			 * carry, and a value that is not a whole number of at least 1 is
			 * ignored.
			 *
			 * @param int    $weight    Default: the TOOL_WEIGHTS entry, else 1.
			 * @param string $tool_name Tool name.
			 * @param array  $arguments Tool arguments.
			 */
			$own = self::read_amount( apply_filters( 'mcpwp_tool_operation_weight', $default, $tool_name, $arguments ), $default, 1 );
		}

		$weight = max( $weight, $own );

		// An id list may arrive as an array or as "1,2,3": core sanitises the
		// route's `ids` into a list either way, and the handler runs every id.
		if ( null !== $spec ) {
			$weight = max( $weight, self::id_list_count( $arguments, $spec['ids'] ) );
		}

		return $weight;
	}

	/**
	 * Spend units one dispatched operation costs (#752 F9).
	 *
	 * - a container (/batch, /mcp): 0 — its operations are charged one by one;
	 * - a credential-spending or egress route: 25 (SPEND_EGRESS_ROUTES and
	 *   SPEND_EGRESS_PATTERNS);
	 * - /media/bulk and /google-indexing/submit: 25 per URL item;
	 * - /elementor/bulk: one per id it reads;
	 * - a route a TOOL_WEIGHTS tool dispatches: that tool's weight;
	 * - anything else: the items its list arguments carry, at least 1.
	 *
	 * The route is normalised first (see normalize_route()), so the price does
	 * not depend on how the caller spelled a path WordPress routes the same way.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route, with or without the /mcpwp/vN prefix.
	 * @param mixed  $params Request parameters.
	 * @return int At least 0.
	 */
	public static function spend_weight( $method, $route, $params ) {
		$path    = self::normalize_route( $route );
		$params  = is_array( $params ) ? $params : array();
		$method  = is_string( $method ) ? strtoupper( trim( $method ) ) : '';
		$default = self::default_spend_weight( $path, $method, $params );

		if ( ! function_exists( 'apply_filters' ) ) {
			return $default;
		}

		/**
		 * Filters the spend units one dispatched operation costs (#752 F9).
		 *
		 * May run twice for one dispatch — for the route pattern WordPress
		 * matched and for the route the caller sent — and the larger price
		 * is charged. A value that is not a whole number of at least 0 is
		 * ignored: the default applies, never "free".
		 *
		 * @param int    $weight Default price.
		 * @param string $path   Normalised route, e.g. "/integrations/generate-image".
		 * @param string $method Upper-case HTTP method.
		 * @param array  $params Request parameters.
		 */
		$filtered = apply_filters( 'mcpwp_spend_weight', $default, $path, $method, $params );

		return self::read_amount( $filtered, $default, 0 );
	}

	/**
	 * The unfiltered price of a normalised route.
	 *
	 * @param string $path   Normalised route.
	 * @param string $method Upper-case HTTP method ('' when unreadable).
	 * @param array  $params Request parameters.
	 * @return int
	 */
	private static function default_spend_weight( $path, $method, array $params ) {
		if ( in_array( $path, self::SPEND_CONTAINER_ROUTES, true ) ) {
			return 0;
		}

		// Routes that make one outbound request per item come first: a flat
		// price would make a 200-URL call as cheap as one.
		if ( array_key_exists( $path, self::SPEND_URL_ITEM_ROUTES ) ) {
			$items = 0;
			foreach ( self::SPEND_URL_ITEM_ROUTES[ $path ] as $key ) {
				if ( ! isset( $params[ $key ] ) ) {
					continue;
				}

				// #804 N-R2-2: /screenshot's widths sweep clamps, dedupes,
				// and caps at 6 before it runs — price that effective count,
				// not the raw length of whatever the caller sent (which
				// could overstate it — 20 duplicate/out-of-range widths
				// collapse to far fewer real renders — or understate a
				// comma-string the plain is_array() check below ignores
				// entirely).
				if ( '/screenshot' === $path && 'widths' === $key ) {
					$items = max( $items, count( self::effective_screenshot_widths( $params[ $key ] ) ) );
					continue;
				}

				if ( is_array( $params[ $key ] ) ) {
					$items = max( $items, count( $params[ $key ] ) );
				}
			}
			return max( 1, $items ) * self::SPEND_EGRESS_WEIGHT;
		}

		// Routes priced by requested size rather than by a list (#804 W5 /
		// W-R2-2 / round 3). Clamped to the route's own ceiling BEFORE
		// dividing (N-R2-1): the handler never actually holds more than the
		// ceiling in memory, so pricing an unclamped number a caller merely
		// asked for would price memory the call never allocates.
		//
		// A clamped value at or under `baseline` (the handler's own
		// default) costs 1 — unchanged for every ordinary caller. Only
		// bytes requested ABOVE the baseline are metered, at 1 + one unit
		// per `unit_bytes` over it, so the 512KB ceiling costs 30 (still
		// enough to refuse a batch of 100 whole) without quietly raising
		// the price of a call that never asked for anything unusual.
		if ( array_key_exists( $path, self::SPEND_SIZE_ROUTES ) ) {
			$spec      = self::SPEND_SIZE_ROUTES[ $path ];
			$requested = isset( $params[ $spec['param'] ] ) ? absint( $params[ $spec['param'] ] ) : 0;
			$clamped   = min( $requested, $spec['ceiling'] );

			if ( $clamped <= $spec['baseline'] ) {
				return 1;
			}

			return 1 + (int) ceil( ( $clamped - $spec['baseline'] ) / $spec['unit_bytes'] );
		}

		// A paid route given a list costs at least the list, like any other
		// route would.
		if ( in_array( $path, self::SPEND_EGRESS_ROUTES, true ) ) {
			return max( self::SPEND_EGRESS_WEIGHT, self::list_count( $params ) );
		}

		$is_read = in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );
		foreach ( self::SPEND_EGRESS_PATTERNS as $pattern => $methods ) {
			if ( 1 === preg_match( $pattern, $path ) && ( 'any' === $methods || ! $is_read ) ) {
				return max( self::SPEND_EGRESS_WEIGHT, self::list_count( $params ) );
			}
		}

		if ( array_key_exists( $path, self::SPEND_ID_LIST_ROUTES ) ) {
			return max( max( 1, self::list_count( $params ) ), self::id_list_count( $params, self::SPEND_ID_LIST_ROUTES[ $path ] ) );
		}

		foreach ( self::TOOL_WEIGHTS as $tool_name => $spec ) {
			if ( $spec['route'] === $path ) {
				return self::tool_weight( $tool_name, $params );
			}
		}

		return max( 1, self::list_count( $params ) );
	}

	/**
	 * How many ids an id-list argument names: array entries, or the non-empty
	 * pieces of a comma-separated string.
	 *
	 * @param array  $params Request parameters.
	 * @param string $key    Argument name.
	 * @return int
	 */
	private static function id_list_count( array $params, $key ) {
		if ( ! isset( $params[ $key ] ) ) {
			return 0;
		}

		if ( is_array( $params[ $key ] ) ) {
			return count( $params[ $key ] );
		}

		if ( ! is_string( $params[ $key ] ) && ! is_int( $params[ $key ] ) ) {
			return 0;
		}

		return count( array_filter( array_map( 'trim', explode( ',', (string) $params[ $key ] ) ), 'strlen' ) );
	}

	/**
	 * Whether a find/replace call names posts (`id` or `ids`) rather than
	 * asking for a whole post type or the whole site (#891).
	 *
	 * The exact negation of Mcpwp_REST_Elementor::find_replace_is_wide() —
	 * the REST auth route table's own answer to the same question, reused
	 * here so pricing cannot silently disagree with what the handler decided
	 * "named" means. Falls back to an inline copy of that predicate only
	 * when the class is not loaded (e.g. this class exercised in isolation);
	 * the two are kept in sync deliberately, as find_replace_is_wide()'s own
	 * docblock already asks of every caller of that predicate.
	 *
	 * @param array $arguments Tool arguments.
	 * @return bool
	 */
	private static function find_replace_names_posts( array $arguments ) {
		if ( class_exists( 'Mcpwp_REST_Elementor' ) && method_exists( 'Mcpwp_REST_Elementor', 'find_replace_is_wide' ) ) {
			return ! Mcpwp_REST_Elementor::find_replace_is_wide( $arguments );
		}

		$present = function ( $key ) use ( $arguments ) {
			if ( ! isset( $arguments[ $key ] ) ) {
				return false;
			}
			$v = $arguments[ $key ];
			return is_array( $v ) ? ! empty( $v ) : ( is_scalar( $v ) && '' !== trim( (string) $v ) );
		};

		return $present( 'ids' ) || ( $present( 'id' ) && ( ! is_scalar( $arguments['id'] ) || 0 !== (int) $arguments['id'] ) );
	}

	/**
	 * A route in the one spelling prices are keyed on.
	 *
	 * WordPress matches REST routes case-insensitively, and core's anchored
	 * matcher lets a trailing newline through; the batch controller collapses
	 * repeated slashes before its own checks. The price must be at least as
	 * forgiving as every one of those, or a spelling that still reaches a paid
	 * handler would be priced as an ordinary one. So: trim, lower-case, cut a
	 * query string or fragment, collapse slashes, drop the /mcpwp/vN prefix and
	 * any trailing slash. A route this cannot normalise prices as an ordinary
	 * operation — never as a free container.
	 *
	 * @param mixed $route Route.
	 * @return string Leading-slash path relative to the plugin namespace; '' when unreadable.
	 */
	public static function normalize_route( $route ) {
		if ( ! is_string( $route ) ) {
			return '';
		}

		$route = strtolower( trim( $route ) );
		// Cut a query string or fragment — but not the "(?" of a named group:
		// core hands rest_dispatch_request the registered route pattern, e.g.
		// "/webhooks/(?P<id>\d+)/test", and cutting there would price it as "/webhooks/(".
		$route = preg_replace( '/(?<!\()[?#].*$/s', '', $route );

		if ( ! is_string( $route ) ) {
			return '';
		}

		$route = preg_replace( '#/+#', '/', $route );

		if ( ! is_string( $route ) ) {
			return '';
		}

		$route = preg_replace( '#^/?mcpwp/v[0-9]+(?=/|$)#', '', $route );

		if ( ! is_string( $route ) ) {
			return '';
		}

		return '/' . trim( $route, "/ \t\n\r\0\x0B" );
	}

	/**
	 * A filtered amount when it is one, else the default.
	 *
	 * A filter that returns something that is not a whole number at or above
	 * $min — a word, null, a bool, an array, NaN, a negative — has a bug. A bug
	 * must not turn a price into zero or a ceiling into none, so it reads as
	 * the default, not as the value PHP would coerce it to.
	 *
	 * @param mixed $value   Filtered value.
	 * @param int   $default Default.
	 * @param int   $min     Smallest acceptable value.
	 * @return int
	 */
	public static function read_amount( $value, $default, $min ) {
		if ( is_int( $value ) ) {
			return $value >= $min ? $value : (int) $default;
		}

		if ( is_float( $value ) ) {
			return ( is_finite( $value ) && $value >= $min && $value < PHP_INT_MAX ) ? (int) $value : (int) $default;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,18}$/', $value ) ) {
			return (int) $value >= $min ? (int) $value : (int) $default;
		}

		return (int) $default;
	}
}
