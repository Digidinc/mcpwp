<?php
/**
 * Site graph read surface (#943 S2): the query and visibility layer over the
 * tables Mcpwp_Graph_Builder (S1) writes.
 *
 * This is a data-egress surface. Every public method here is reachable from a
 * read-scope REST route, an MCP tool, or the mcpwp://graph resource, so this
 * class is the ONE place that decides what a caller may see. Callers pass in
 * `$can_see_non_public`, computed by the REST controller through
 * Mcpwp_REST_API::requester_can_see_non_public(), the same gate every other
 * read surface in the plugin uses (write scope or higher, or a trusted
 * admin-AJAX request).
 *
 * VISIBILITY (the chokepoint)
 * ---------------------------
 * One rule, key_visible_sql() in SQL and visible_keys() in PHP, decides
 * whether a node_key is visible. It FAILS CLOSED by kind (#943 S2 review W5):
 *
 *   - `post:<id>`  the LIVE wp_posts row, never the node's stored status.
 *                  Read scope: the post exists and its status is public.
 *                  Privileged: the post exists.
 *   - `term:<tax>:<id>`  read scope: the taxonomy is viewable
 *                  (is_taxonomy_viewable()). Privileged: always.
 *   - `media:<id>` the id is a real attachment. Read scope: its parent post
 *                  is public, or it has no parent.
 *   - anything else (query, url, fact, any future kind)
 *                  read scope: visible only while it has at least one inbound
 *                  edge from a public post. Privileged: always.
 *
 * `$can_see_non_public` is computed by the REST controller through
 * Mcpwp_REST_API::requester_can_see_non_public(), the gate every content
 * endpoint uses (write scope or higher, or a trusted admin-AJAX request).
 *
 * The SQL rule is backed by the same rule in PHP (get_post() through the
 * object cache, plus one batched edge lookup for "other" kinds) before any row
 * leaves this class. A future filter added without the SQL clause, or a
 * broken join, still cannot hand a read-only key a draft title.
 *
 * LIVE VALUES (#943 S2 review W7)
 * -------------------------------
 * For a post-backed row, `label`, `url` and `status` come from the live post
 * (get_the_title(), get_permalink(), post_status), and label ordering sorts by
 * the live post_title. A draft renamed and published before the worker runs
 * therefore never shows its old draft title, even in a cursor.
 *
 * EDGES
 * -----
 * An edge is returned only when BOTH endpoints are visible to the caller. An
 * edge touching a hidden node is OMITTED, never redacted to a bare node_key: a
 * redacted row still reveals that the hidden post exists and what it links to.
 * Omission is also what keeps pagination honest, because hidden edges are
 * excluded in SQL and never occupy a page slot.
 *
 * PASSWORD-PROTECTED POSTS
 * ------------------------
 * A published post with a password has a public title and URL, but its
 * content is not public. For an unprivileged caller, such a node exposes no
 * content-derived data: `word_count` is null, `props.builder` is removed, and
 * its outbound content-derived edges (links_to, uses_media) are treated as if
 * they do not exist, in every response and every filter. The `word_count_lt`
 * filter never matches it, and `no_edge_type` on a content-derived type always
 * matches it, so neither can serve as an oracle for the hidden content.
 *
 * INBOUND COUNTS AND ORPHANS
 * --------------------------
 * `orphan`, `min_inbound`, `max_inbound` and `order_by=inbound` count only
 * edges from LIVE sources: a source a read key could see (a public post, a
 * viewable term, ...), with a password-protected source's content edges not
 * counted. This is the same for every caller. It is the right orphan
 * semantics (a draft's or a trashed post's link does not make a page
 * reachable), and it means a count can never reveal hidden content, even to
 * a read-scope key. The builder keeps a trashed post's edges on purpose
 * (readers filter by status), so this filter is required, not just defensive.
 * The node's own `edges.inbound` / `edges.outbound` counts in wp_graph_node
 * instead count the edges this caller can see, so they always agree with
 * what wp_graph_neighbors returns.
 *
 * SQL SAFETY
 * ----------
 * Every query is one $wpdb->prepare() call over a template this class wrote
 * and a positional parameter list. No caller-supplied string is ever
 * concatenated into SQL text. Enum-shaped inputs (kind, status, edge types,
 * metric names, signal sources) must match a strict token pattern or the
 * request is REJECTED with a 400 (never silently dropped, which would widen
 * the result set). Operators, directions and order_by map through fixed
 * allowlists to server-written fragments. Numeric inputs are cast. The only
 * free-text input, node_key, is always a bound value.
 *
 * LIMITS
 * ------
 * wp_graph_query: limit <= 200. wp_graph_neighbors: limit <= 100 edges per
 * page, depth 2 capped at 200 nodes. Every response also has a hard byte cap
 * (MAX_RESPONSE_BYTES): a page that would exceed it is cut short and returns
 * a next_cursor pointing just after the last row it included. The
 * mcpwp://graph summary has its own, much smaller cap (RESOURCE_MAX_BYTES).
 *
 * CURSORS
 * -------
 * Opaque and encrypted: base64url of a libsodium secretbox (authenticated
 * encryption; WordPress ships sodium_compat) over JSON {k, i, v, q}. `i`/`v`
 * are the last VISIBLE row's id and order value exactly as MySQL returned it
 * (review W2: a row the PHP backstop dropped never becomes the cursor), and
 * `q` fingerprints the normalized query and the caller's privilege. A cursor
 * that fails to decrypt, has the wrong shape, or was minted for a different
 * query, surface or privilege level is rejected with a 400.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only query layer for the site graph.
 */
class Mcpwp_Graph_Reader {

	/** Hard cap on wp_graph_query results per page. */
	const QUERY_LIMIT_MAX = 200;

	/** Default wp_graph_query page size. */
	const QUERY_LIMIT_DEFAULT = 50;

	/** Hard cap on wp_graph_neighbors edges per page. */
	const NEIGHBORS_LIMIT_MAX = 100;

	/** Default wp_graph_neighbors page size. */
	const NEIGHBORS_LIMIT_DEFAULT = 50;

	/** Hard cap on total nodes (center included) across a depth-2 walk. */
	const NEIGHBORS_DEPTH2_NODE_CAP = 200;

	/** Max second-hop edge rows scanned in one depth-2 walk (one query). */
	const HOP2_EDGE_SCAN_MAX = 1000;

	/** Hard cap on the JSON size of one page of results, in bytes. */
	const MAX_RESPONSE_BYTES = 262144;

	/**
	 * Hard cap on the mcpwp://graph summary, in bytes. The design doc sets a
	 * 2k-token budget. At a conservative 3 bytes per token for JSON heavy in
	 * keys and punctuation, 5,600 bytes stays under 2,000 tokens with margin.
	 */
	const RESOURCE_MAX_BYTES = 5600;

	/** Labels in the summary are shortened to this many characters. */
	const RESOURCE_LABEL_CHARS = 80;

	/** Allowed edge directions for wp_graph_neighbors. */
	const DIRECTIONS = array( 'out', 'in', 'both' );

	/** metric.op allowlist: caller token => fixed SQL operator. */
	const METRIC_OPS = array(
		'gt' => '>',
		'lt' => '<',
	);

	/** Fixed order_by keywords. Any other value must be a metric name. */
	const ORDER_BY_KEYWORDS = array( 'label', 'modified', 'inbound' );

	/** Edge types derived from a post's content (hidden for password-protected posts). */
	const CONTENT_EDGE_TYPES = array( 'links_to', 'uses_media' );

	/** node_key prefix of every post-backed node. */
	const POST_PREFIX = 'post:';

	/** Cursor wire-format version. */
	const CURSOR_VERSION = 2;

	// -------------------------------------------------------------------------
	// Visibility primitives
	// -------------------------------------------------------------------------

	/**
	 * Public post statuses (`publish` on a stock site). Server-derived, and
	 * still always passed to SQL as bound values.
	 *
	 * @return array<int,string>
	 */
	public static function public_statuses() {
		$statuses = array();
		if ( function_exists( 'get_post_stati' ) ) {
			$statuses = array_values( (array) get_post_stati( array( 'public' => true ) ) );
		} else {
			foreach ( array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ) as $candidate ) {
				if ( self::is_public_status( $candidate ) ) {
					$statuses[] = $candidate;
				}
			}
		}
		$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );
		return empty( $statuses ) ? array( 'publish' ) : $statuses;
	}

	/**
	 * Viewable taxonomies (is_taxonomy_viewable()), for term: visibility.
	 * Never empty: an impossible sentinel keeps the SQL IN () valid.
	 *
	 * @return array<int,string>
	 */
	public static function viewable_taxonomies() {
		$names = function_exists( 'get_taxonomies' ) ? array_values( (array) get_taxonomies() ) : array( 'category', 'post_tag' );
		$out   = array();
		foreach ( $names as $name ) {
			if ( Mcpwp_Graph_Builder::is_viewable_taxonomy( (string) $name ) ) {
				$out[] = sanitize_key( (string) $name );
			}
		}
		return empty( $out ) ? array( ':none:' ) : array_values( array_unique( $out ) );
	}

	/**
	 * Same semantics as Mcpwp_REST_API::is_public_status(): a registered
	 * status whose object carries the `public` flag.
	 *
	 * @param string $status Post status.
	 * @return bool
	 */
	public static function is_public_status( $status ) {
		$status_obj = get_post_status_object( (string) $status );
		return $status_obj && ! empty( $status_obj->public );
	}

	/**
	 * Whether a node_key names a post-backed node.
	 *
	 * @param string $node_key Node key.
	 * @return bool
	 */
	public static function is_post_key( $node_key ) {
		return 0 === strpos( (string) $node_key, self::POST_PREFIX );
	}

	/**
	 * Numeric id after a `<prefix>` in a key, or 0.
	 *
	 * @param string $node_key Node key.
	 * @param string $prefix   Prefix, e.g. `post:`.
	 * @return int
	 */
	private static function id_after( $node_key, $prefix ) {
		if ( 0 !== strpos( (string) $node_key, $prefix ) ) {
			return 0;
		}
		$rest = substr( (string) $node_key, strlen( $prefix ) );
		return ctype_digit( $rest ) ? (int) $rest : 0;
	}

	/**
	 * Post ID encoded in a `post:<id>` key, or 0.
	 *
	 * @param string $node_key Node key.
	 * @return int
	 */
	public static function post_id_from_key( $node_key ) {
		return self::id_after( $node_key, self::POST_PREFIX );
	}

	/**
	 * Which of these post IDs may this caller see, judged from get_post()
	 * (the live row, through the object cache), not the graph tables.
	 *
	 * @param array<int> $post_ids           Post IDs.
	 * @param bool       $can_see_non_public Caller privilege.
	 * @return array<int,bool> Set of visible IDs (id => true).
	 */
	public static function visible_post_ids( array $post_ids, $can_see_non_public ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $post_ids, false, false );
		}
		$visible = array();
		foreach ( $post_ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || empty( $post->ID ) || 'attachment' === $post->post_type ) {
				continue;
			}
			if ( $can_see_non_public || self::is_public_status( (string) $post->post_status ) ) {
				$visible[ $id ] = true;
			}
		}
		return $visible;
	}

	/**
	 * Which of these post IDs are password-protected (live, via get_post()).
	 *
	 * @param array<int> $post_ids Post IDs.
	 * @return array<int,bool> Set of protected IDs.
	 */
	public static function protected_post_ids( array $post_ids ) {
		$out = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) as $id ) {
			$post = get_post( $id );
			if ( $post && isset( $post->post_password ) && '' !== (string) $post->post_password ) {
				$out[ $id ] = true;
			}
		}
		return $out;
	}

	/**
	 * PHP half of the visibility rule (see the class docblock). Mirrors
	 * key_visible_sql() exactly, and fails closed for any unknown kind.
	 *
	 * @param array<string> $keys               Node keys.
	 * @param bool          $can_see_non_public Caller privilege.
	 * @return array<string,bool> Set of visible keys.
	 */
	public static function visible_keys( array $keys, $can_see_non_public ) {
		$keys     = array_values( array_unique( array_map( 'strval', $keys ) ) );
		$post_ids = array();
		$other    = array();
		foreach ( $keys as $key ) {
			if ( self::is_post_key( $key ) ) {
				$post_ids[] = self::post_id_from_key( $key );
			} elseif ( ! $can_see_non_public && 0 !== strpos( $key, 'term:' ) && 0 !== strpos( $key, 'media:' ) ) {
				$other[] = $key;
			}
		}
		$visible_ids = self::visible_post_ids( $post_ids, $can_see_non_public );
		$viewable    = array_flip( self::viewable_taxonomies() );
		$anchored    = self::keys_with_public_inbound( $other );

		$out = array();
		foreach ( $keys as $key ) {
			if ( self::is_post_key( $key ) ) {
				$id = self::post_id_from_key( $key );
				if ( $id > 0 && isset( $visible_ids[ $id ] ) ) {
					$out[ $key ] = true;
				}
				continue;
			}
			if ( 0 === strpos( $key, 'term:' ) ) {
				$parts = explode( ':', $key );
				if ( $can_see_non_public || ( 3 === count( $parts ) && isset( $viewable[ $parts[1] ] ) ) ) {
					$out[ $key ] = true;
				}
				continue;
			}
			if ( 0 === strpos( $key, 'media:' ) ) {
				if ( self::media_visible( self::id_after( $key, 'media:' ), $can_see_non_public ) ) {
					$out[ $key ] = true;
				}
				continue;
			}
			if ( $can_see_non_public || isset( $anchored[ $key ] ) ) {
				$out[ $key ] = true;
			}
		}
		return $out;
	}

	/**
	 * A media: node is visible only for a real attachment whose parent post
	 * is visible to the caller, or which has no parent (review W3).
	 *
	 * @param int  $id                 Attachment ID.
	 * @param bool $can_see_non_public Caller privilege.
	 * @return bool
	 */
	private static function media_visible( $id, $can_see_non_public ) {
		$att = $id > 0 ? get_post( $id ) : null;
		if ( ! $att || 'attachment' !== $att->post_type ) {
			return false;
		}
		if ( $can_see_non_public || empty( $att->post_parent ) ) {
			return true;
		}
		return ! empty( self::visible_post_ids( array( (int) $att->post_parent ), false ) );
	}

	/**
	 * Of these non-post, non-term, non-media keys, which have an inbound edge
	 * from a public post (review W5: other kinds fail closed without one).
	 *
	 * @param array<string> $keys Node keys.
	 * @return array<string,bool>
	 */
	private static function keys_with_public_inbound( array $keys ) {
		if ( empty( $keys ) ) {
			return array();
		}
		$edges = Mcpwp_Graph_Builder::edges_table();
		$rows  = self::get_results(
			"SELECT e.to_key AS to_key, e.from_key AS from_key, e.type AS type FROM {$edges} e WHERE e.to_key IN (" . self::placeholders( count( $keys ) ) . ") AND LEFT(e.from_key, 5) = 'post:' LIMIT %d",
			array_merge( $keys, array( 5000 ) )
		);
		$ids = array();
		foreach ( $rows as $r ) {
			$ids[] = self::post_id_from_key( $r['from_key'] );
		}
		$public    = self::visible_post_ids( $ids, false );
		$protected = self::protected_post_ids( $ids );
		$out       = array();
		foreach ( $rows as $r ) {
			$from = self::post_id_from_key( $r['from_key'] );
			// Review round 2, NIT-2: a protected post's content edge is not an anchor.
			if ( isset( $protected[ $from ] ) && in_array( (string) ( $r['type'] ?? '' ), self::CONTENT_EDGE_TYPES, true ) ) {
				continue;
			}
			if ( isset( $public[ $from ] ) ) {
				$out[ (string) $r['to_key'] ] = true;
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// SQL fragments. Each returns array( string $sql, array $params ): the
	// fragment is server-written, and every value in it is a placeholder.
	// Column and alias arguments are always fixed strings from this class.
	// -------------------------------------------------------------------------

	/**
	 * A `<column> NOT IN (...)` fragment excluding tool-category-gated kinds
	 * (#1024 round-1 review, WARN-5/BLOCK-2/NIT), or an always-true
	 * no-op when nothing is excluded.
	 *
	 * This reader has no category concept of its own — its visibility is
	 * purely post-status (see the class docblock) — so Mcpwp_REST_Graph
	 * computes $excluded_kinds (post types the CALLER's tool category
	 * cannot read, among Mcpwp_Graph_Builder::covered_post_types()) and
	 * passes it into every public method below. Applied directly in SQL
	 * (never as a post-fetch PHP filter) so a page comes back full and a
	 * cursor never advances past a row that was hidden rather than genuinely
	 * absent — the same reasoning the status-visibility SQL in this class
	 * already follows, just for a second, orthogonal dimension.
	 *
	 * $column is always one of a small set of fixed strings this class
	 * writes itself (e.g. 'n.kind', a joined post's post_type) — never
	 * caller input — so only the VALUES in $excluded_kinds travel as bound
	 * placeholders.
	 *
	 * @param string $column         Fixed column expression holding a kind
	 *                               or post_type value.
	 * @param array  $excluded_kinds Kind/post_type strings to exclude.
	 * @return array{0:string,1:array}
	 */
	private static function kind_excluded_sql( $column, array $excluded_kinds ) {
		if ( empty( $excluded_kinds ) ) {
			return array( '1=1', array() );
		}
		return array(
			"{$column} NOT IN (" . self::placeholders( count( $excluded_kinds ) ) . ')',
			array_values( $excluded_kinds ),
		);
	}

	/**
	 * The fuller node exclusion for the ACTUAL nodes table (alias n, with
	 * node_key/kind/ref_id columns available) — kind_excluded_sql('n.kind', ...)
	 * plus two cases it cannot see (#1024/#1030 round-2 review, NIT-2):
	 *
	 * 1. A TERM node's `kind` is the literal string 'term' for every
	 *    taxonomy (set once at ingestion — Mcpwp_Graph_Builder), never the
	 *    taxonomy name, so excluding by kind can never hide a specific
	 *    taxonomy. $excluded_taxonomies is matched directly against the
	 *    taxonomy segment of a `term:<taxonomy>:<id>` key instead.
	 * 2. A MEDIA node's `kind` is likewise always 'media', regardless of
	 *    what it is an attachment OF. A WooCommerce product image is not
	 *    excluded by $excluded_kinds at all unless its PARENT post's type
	 *    is checked — which this does.
	 *
	 * Both are checked directly against the node_key (term) or a live
	 * lookup of the attachment's post_parent (media), matching this
	 * reader's own "trust the live post" convention elsewhere.
	 *
	 * @param array $excluded_kinds      Tool-category-gated post types.
	 * @param array $excluded_taxonomies Tool-category-gated taxonomies
	 *                                    (e.g. product_cat, product_tag, a
	 *                                    pa_* attribute taxonomy — see
	 *                                    Mcpwp_REST_Graph::category_excluded_taxonomies()).
	 * @return array{0:string,1:array}
	 */
	private static function node_category_excluded_sql( array $excluded_kinds, array $excluded_taxonomies = array() ) {
		if ( empty( $excluded_kinds ) && empty( $excluded_taxonomies ) ) {
			return array( '1=1', array() );
		}

		global $wpdb;
		$posts   = $wpdb->posts;
		$clauses = array();
		$params  = array();

		list( $kind_sql, $kind_params ) = self::kind_excluded_sql( 'n.kind', $excluded_kinds );
		$clauses[] = $kind_sql;
		$params    = array_merge( $params, $kind_params );

		if ( ! empty( $excluded_kinds ) ) {
			$in        = self::placeholders( count( $excluded_kinds ) );
			$clauses[] = "(LEFT(n.node_key, 6) <> 'media:' OR NOT EXISTS (SELECT 1 FROM {$posts} nmp WHERE nmp.ID = n.ref_id AND EXISTS (SELECT 1 FROM {$posts} nmpp WHERE nmpp.ID = nmp.post_parent AND nmpp.post_type IN ({$in}))))";
			$params    = array_merge( $params, $excluded_kinds );
		}

		if ( ! empty( $excluded_taxonomies ) ) {
			$in        = self::placeholders( count( $excluded_taxonomies ) );
			$clauses[] = "(LEFT(n.node_key, 5) <> 'term:' OR SUBSTRING_INDEX(SUBSTRING(n.node_key, 6), ':', 1) NOT IN ({$in}))";
			$params    = array_merge( $params, $excluded_taxonomies );
		}

		return array( '(' . implode( ') AND (', $clauses ) . ')', $params );
	}

	/**
	 * "Neither endpoint of edge alias $alias is a node of an excluded kind."
	 * An edge carries no kind of its own — only its endpoint NODES do — so
	 * this is a NOT EXISTS against the nodes table for each side, additive to
	 * (never a replacement for) edge_visible_sql()'s own status-visibility
	 * check.
	 *
	 * @param string $alias          Edge alias (fixed).
	 * @param array  $excluded_kinds Kind/post_type strings to exclude.
	 * @return array{0:string,1:array}
	 */
	private static function edge_endpoints_kind_excluded_sql( $alias, array $excluded_kinds ) {
		if ( empty( $excluded_kinds ) ) {
			return array( '1=1', array() );
		}
		$nodes = Mcpwp_Graph_Builder::nodes_table();
		$in    = self::placeholders( count( $excluded_kinds ) );
		$sql   = "NOT EXISTS (SELECT 1 FROM {$nodes} kf WHERE kf.node_key = {$alias}.from_key AND kf.kind IN ({$in}))"
			. " AND NOT EXISTS (SELECT 1 FROM {$nodes} kt WHERE kt.node_key = {$alias}.to_key AND kt.kind IN ({$in}))";
		return array( $sql, array_merge( $excluded_kinds, $excluded_kinds ) );
	}

	/**
	 * `LEFT JOIN {posts} p` that attaches the live post row to node alias n.
	 *
	 * @return string
	 */
	private static function posts_join_sql() {
		global $wpdb;
		return " LEFT JOIN {$wpdb->posts} p ON (p.ID = n.ref_id AND LEFT(n.node_key, 5) = 'post:')";
	}

	/**
	 * The visibility rule for one node_key column (see the class docblock).
	 * One CASE, so only the branch for the key's kind is evaluated.
	 *
	 * @param string $col                Fixed column expression.
	 * @param string $a                  Fixed alias prefix, unique per use.
	 * @param bool   $can_see_non_public Caller privilege.
	 * @return array{0:string,1:array}
	 */
	private static function key_visible_sql( $col, $a, $can_see_non_public ) {
		global $wpdb;
		$posts = $wpdb->posts;
		$edges = Mcpwp_Graph_Builder::edges_table();

		if ( $can_see_non_public ) {
			return array(
				"(CASE WHEN LEFT({$col}, 5) = 'post:' THEN EXISTS (SELECT 1 FROM {$posts} {$a}p WHERE {$a}p.ID = CAST(SUBSTRING({$col}, 6) AS UNSIGNED) AND {$a}p.post_type <> 'attachment')"
				. " WHEN LEFT({$col}, 6) = 'media:' THEN EXISTS (SELECT 1 FROM {$posts} {$a}m WHERE {$a}m.ID = CAST(SUBSTRING({$col}, 7) AS UNSIGNED) AND {$a}m.post_type = 'attachment')"
				. ' ELSE 1 END) = 1',
				array(),
			);
		}

		$pub  = self::public_statuses();
		$in   = self::placeholders( count( $pub ) );
		$tax  = self::viewable_taxonomies();
		$sql  = "(CASE WHEN LEFT({$col}, 5) = 'post:' THEN EXISTS (SELECT 1 FROM {$posts} {$a}p WHERE {$a}p.ID = CAST(SUBSTRING({$col}, 6) AS UNSIGNED) AND {$a}p.post_type <> 'attachment' AND {$a}p.post_status IN ({$in}))"
			. " WHEN LEFT({$col}, 5) = 'term:' THEN SUBSTRING_INDEX(SUBSTRING({$col}, 6), ':', 1) IN (" . self::placeholders( count( $tax ) ) . ')'
			. " WHEN LEFT({$col}, 6) = 'media:' THEN EXISTS (SELECT 1 FROM {$posts} {$a}m WHERE {$a}m.ID = CAST(SUBSTRING({$col}, 7) AS UNSIGNED) AND {$a}m.post_type = 'attachment'"
			. " AND ({$a}m.post_parent = 0 OR EXISTS (SELECT 1 FROM {$posts} {$a}mp WHERE {$a}mp.ID = {$a}m.post_parent AND {$a}mp.post_status IN ({$in}))))"
			. " ELSE EXISTS (SELECT 1 FROM {$edges} {$a}x WHERE {$a}x.to_key = {$col} AND LEFT({$a}x.from_key, 5) = 'post:'"
			. " AND EXISTS (SELECT 1 FROM {$posts} {$a}xp WHERE {$a}xp.ID = CAST(SUBSTRING({$a}x.from_key, 6) AS UNSIGNED) AND {$a}xp.post_status IN ({$in}))"
			// Review round 2, NIT-2: a password-protected post's content edge
			// does not anchor a node, the same rule live_inbound_sql() applies.
			. ' AND NOT ' . self::protected_content_edge_sql( "{$a}x", "{$a}xw" ) . ')'
			. ' END) = 1';
		return array( $sql, array_merge( $pub, $tax, $pub, $pub ) );
	}

	/**
	 * Node visibility for alias n.
	 *
	 * @param bool $can_see_non_public Caller privilege.
	 * @return array{0:string,1:array}
	 */
	private static function node_visible_sql( $can_see_non_public ) {
		return self::key_visible_sql( 'n.node_key', 'nv', $can_see_non_public );
	}

	/**
	 * Whether the node (alias n, joined p) is a password-protected post.
	 *
	 * @return string
	 */
	private static function node_protected_sql() {
		return "(p.ID IS NOT NULL AND p.post_password <> '')";
	}

	/**
	 * "This edge is a content-derived edge from a password-protected post."
	 *
	 * @param string $alias  Edge table alias (fixed).
	 * @param string $palias Posts subquery alias (fixed).
	 * @return string
	 */
	private static function protected_content_edge_sql( $alias, $palias ) {
		global $wpdb;
		return "({$alias}.type IN ('links_to', 'uses_media') AND LEFT({$alias}.from_key, 5) = 'post:'"
			. " AND EXISTS (SELECT 1 FROM {$wpdb->posts} {$palias} WHERE {$palias}.ID = CAST(SUBSTRING({$alias}.from_key, 6) AS UNSIGNED) AND {$palias}.post_password <> ''))";
	}

	/**
	 * Edge visibility for the caller: both endpoints visible, and (for an
	 * unprivileged caller) not a content edge from a password-protected post.
	 *
	 * @param string $alias              Edge alias (fixed).
	 * @param bool   $can_see_non_public Caller privilege.
	 * @return array{0:string,1:array}
	 */
	private static function edge_visible_sql( $alias, $can_see_non_public ) {
		list( $from_sql, $from_params ) = self::key_visible_sql( "{$alias}.from_key", "{$alias}f", $can_see_non_public );
		list( $to_sql, $to_params )     = self::key_visible_sql( "{$alias}.to_key", "{$alias}t", $can_see_non_public );
		$sql = "{$from_sql} AND {$to_sql}";
		if ( ! $can_see_non_public ) {
			$sql .= ' AND NOT ' . self::protected_content_edge_sql( $alias, "{$alias}pw" );
		}
		return array( $sql, array_merge( $from_params, $to_params ) );
	}

	/**
	 * Live-source rule for inbound counting (orphan, min/max_inbound,
	 * order_by=inbound): the source is visible to a READ key, and is not a
	 * password-protected post's content edge. Caller-independent except for
	 * $excluded_kinds.
	 *
	 * @param string $alias          Edge alias (fixed).
	 * @param array  $excluded_kinds Tool-category-gated kinds the SOURCE
	 *                                (from_key) must not be — #1024/#1030
	 *                                round-2 review, NIT-1: without this, a
	 *                                gated post's outbound link still counted
	 *                                toward a target's inbound count/orphan
	 *                                status and order_by=inbound sort value,
	 *                                even though the caller can never see
	 *                                that source post or confirm the link
	 *                                exists.
	 * @return array{0:string,1:array}
	 */
	private static function live_inbound_sql( $alias, array $excluded_kinds = array() ) {
		list( $from_sql, $from_params ) = self::key_visible_sql( "{$alias}.from_key", "{$alias}f", false );
		list( $kind_excl_sql, $kind_excl_params ) = self::kind_excluded_sql( "{$alias}fk.post_type", $excluded_kinds );
		if ( ! empty( $excluded_kinds ) ) {
			global $wpdb;
			$posts        = $wpdb->posts;
			$kind_excl_sql = "(LEFT({$alias}.from_key, 5) <> 'post:' OR EXISTS (SELECT 1 FROM {$posts} {$alias}fk WHERE {$alias}fk.ID = CAST(SUBSTRING({$alias}.from_key, 6) AS UNSIGNED) AND {$kind_excl_sql}))";
		}
		return array(
			"{$from_sql} AND NOT " . self::protected_content_edge_sql( $alias, "{$alias}pw" ) . " AND {$kind_excl_sql}",
			array_merge( $from_params, $kind_excl_params ),
		);
	}

	/**
	 * Review W6: live inbound counts computed ONCE per query as an aggregated
	 * derived table (materialized, never re-evaluated per row), joined as
	 * `ic` and reused for the min/max filters, the sort and the cursor.
	 *
	 * @param array $excluded_kinds Tool-category-gated kinds to exclude from
	 *                              the SOURCE side (#1024/#1030 round-2
	 *                              review, NIT-1).
	 * @return array{0:string,1:array}
	 */
	private static function inbound_join_sql( array $excluded_kinds = array() ) {
		$edges = Mcpwp_Graph_Builder::edges_table();
		list( $live_sql, $live_params ) = self::live_inbound_sql( 'ei', $excluded_kinds );
		return array(
			" LEFT JOIN (SELECT ei.to_key AS k, COUNT(*) AS c FROM {$edges} ei WHERE {$live_sql} GROUP BY ei.to_key) ic ON ic.k = n.node_key",
			$live_params,
		);
	}

	/**
	 * @param int $count Number of placeholders.
	 * @return string `%s,%s,...`
	 */
	private static function placeholders( $count ) {
		return implode( ',', array_fill( 0, max( 1, (int) $count ), '%s' ) );
	}
	// -------------------------------------------------------------------------
	// graph_status: the freshness envelope on every response
	// -------------------------------------------------------------------------

	/**
	 * {ready, coverage, built_at, backfill, dirty_count}, decision 5: every
	 * read says how complete and how fresh the graph is.
	 *
	 * `coverage` counts only posts this caller could see: for a read-scope key,
	 * public posts graphed vs public posts that exist. It never discloses how
	 * many drafts or private posts the site holds.
	 *
	 * @param bool $can_see_non_public Caller privilege.
	 * @return array
	 */
	public static function graph_status( $can_see_non_public = false ) {
		global $wpdb;

		$ready    = Mcpwp_Graph_Builder::ready();
		$graphed  = 0;
		$expected = 0;
		$dirty    = 0;

		$types = Mcpwp_Graph_Builder::covered_post_types();
		if ( $ready && ! empty( $types ) ) {
			$nodes = Mcpwp_Graph_Builder::nodes_table();
			$queue = Mcpwp_Graph_Builder::queue_table();

			if ( $can_see_non_public ) {
				$status_sql    = "post_status <> 'auto-draft'";
				$status_params = array();
			} else {
				$statuses      = self::public_statuses();
				$status_sql    = 'post_status IN (' . self::placeholders( count( $statuses ) ) . ')';
				$status_params = $statuses;
			}
			$type_sql = 'post_type IN (' . self::placeholders( count( $types ) ) . ')';

			$sql_expected = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$type_sql} AND {$status_sql}";
			$sql_graphed  = "SELECT COUNT(*) FROM {$nodes} n INNER JOIN {$wpdb->posts} p ON (p.ID = n.ref_id AND LEFT(n.node_key, 5) = 'post:') WHERE p.{$type_sql} AND p.{$status_sql}";
			$params       = array_merge( $types, $status_params );

			$expected = (int) Mcpwp_Graph_Builder::run_suppressed_query(
				function () use ( $wpdb, $sql_expected, $params ) {
					return $wpdb->get_var( $wpdb->prepare( $sql_expected, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- server-written template, values bound.
				}
			);
			$graphed = (int) Mcpwp_Graph_Builder::run_suppressed_query(
				function () use ( $wpdb, $sql_graphed, $params ) {
					return $wpdb->get_var( $wpdb->prepare( $sql_graphed, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- server-written template, values bound.
				}
			);
			// Review NIT: a read key only learns how many VISIBLE nodes are
			// waiting, never how many hidden posts were just edited.
			list( $q_vis_sql, $q_vis_params ) = self::key_visible_sql( 'q.node_key', 'qv', $can_see_non_public );
			$dirty = (int) self::get_var( "SELECT COUNT(*) FROM {$queue} q WHERE {$q_vis_sql}", $q_vis_params );
		}

		$backfill = (string) get_option( Mcpwp_Graph_Builder::BACKFILL_STATUS_OPTION, '' );
		$built_at = get_option( Mcpwp_Graph_Builder::BUILT_AT_OPTION, '' );

		return array(
			'ready'       => (bool) $ready,
			'coverage'    => array(
				'graphed'  => $graphed,
				'expected' => $expected,
				'ratio'    => $expected > 0 ? round( min( 1, $graphed / $expected ), 4 ) : ( $ready ? 1.0 : 0.0 ),
			),
			'built_at'    => ( is_string( $built_at ) && '' !== $built_at ) ? $built_at : null,
			'backfill'    => '' !== $backfill ? $backfill : 'pending',
			'dirty_count' => $dirty,
		);
	}

	/**
	 * @return WP_Error
	 */
	private static function not_ready_error() {
		return new WP_Error(
			'graph_not_ready',
			'The site graph tables are not ready yet. They are created on activation or upgrade; the daily readiness check retries automatically.',
			array( 'status' => 503 )
		);
	}

	// -------------------------------------------------------------------------
	// Resource summary (mcpwp://graph, GET /graph)
	// -------------------------------------------------------------------------

	/**
	 * The mcpwp://graph resource body: counts, signal sources, the top 10
	 * published orphans and freshness. Everything in it is filtered by the
	 * same visibility rules as the tools, so a read-scope key sees counts of
	 * public content only. Hard-capped at RESOURCE_MAX_BYTES.
	 *
	 * @param bool  $can_see_non_public Caller privilege.
	 * @param array $excluded_kinds     Tool-category-gated kinds to exclude
	 *                                  from every count and list below
	 *                                  (#1024 round-1 review, BLOCK-2) — see
	 *                                  kind_excluded_sql()'s docblock.
	 * @return array
	 */
	public static function resource_summary( $can_see_non_public, array $excluded_kinds = array(), array $excluded_taxonomies = array() ) {
		global $wpdb;

		$status = self::graph_status( $can_see_non_public );
		if ( ! $status['ready'] ) {
			return array(
				'ready'        => false,
				'graph_status' => $status,
			);
		}

		$nodes   = Mcpwp_Graph_Builder::nodes_table();
		$edges   = Mcpwp_Graph_Builder::edges_table();
		$signals = Mcpwp_Graph_Builder::signals_table();

		// $excluded_kinds (#1024 round-1 review, BLOCK-2): tool-category-gated
		// kinds this reader has no concept of on its own — see
		// kind_excluded_sql()'s docblock. Applied in SQL so `nodes_by_kind`'s
		// per-row counts, `edges_by_type`'s counts (an edge to/from a gated
		// node must not count either — NIT), and the orphan total/list below
		// are never computed over data the caller cannot see.
		list( $vis_sql, $vis_params )             = self::node_visible_sql( $can_see_non_public );
		list( $kind_excl_sql, $kind_excl_params ) = self::node_category_excluded_sql( $excluded_kinds, $excluded_taxonomies );
		$sql_kinds = "SELECT n.kind AS kind, COUNT(*) AS cnt FROM {$nodes} n" . self::posts_join_sql() . " WHERE {$vis_sql} AND {$kind_excl_sql} GROUP BY n.kind ORDER BY cnt DESC LIMIT %d";
		$kinds     = self::get_results( $sql_kinds, array_merge( $vis_params, $kind_excl_params, array( 25 ) ) );

		list( $edge_sql, $edge_params )                   = self::edge_visible_sql( 'e', $can_see_non_public );
		list( $edge_kind_excl_sql, $edge_kind_excl_params ) = self::edge_endpoints_kind_excluded_sql( 'e', $excluded_kinds );
		$sql_types = "SELECT e.type AS type, COUNT(*) AS cnt FROM {$edges} e WHERE {$edge_sql} AND {$edge_kind_excl_sql} GROUP BY e.type ORDER BY cnt DESC LIMIT %d";
		$types     = self::get_results( $sql_types, array_merge( $edge_params, $edge_kind_excl_params, array( 25 ) ) );

		list( $sig_vis_sql, $sig_vis_params ) = self::key_visible_sql( 's.node_key', 'sv', $can_see_non_public );
		$sql_sources = "SELECT s.source AS source, MAX(s.period_end) AS latest_period_end, COUNT(*) AS cnt FROM {$signals} s WHERE {$sig_vis_sql} GROUP BY s.source ORDER BY cnt DESC LIMIT %d";
		$sources     = self::get_results( $sql_sources, array_merge( $sig_vis_params, array( 10 ) ) );

		$orphans = self::query(
			array(
				'orphan'    => true,
				'order_by'  => 'modified',
				'order_dir' => 'desc',
				'limit'     => 10,
			),
			$can_see_non_public,
			$excluded_kinds,
			$excluded_taxonomies
		);
		$orphan_rows  = is_array( $orphans ) ? $orphans['results'] : array();
		$orphan_total = self::count_orphans( $can_see_non_public, $excluded_kinds, $excluded_taxonomies );

		$summary = array(
			'ready'          => true,
			'nodes_by_kind'  => array_map(
				static function ( $row ) {
					return array(
						'kind'  => (string) $row['kind'],
						'count' => (int) $row['cnt'],
					);
				},
				$kinds
			),
			'edges_by_type'  => array_map(
				static function ( $row ) {
					return array(
						'type'  => (string) $row['type'],
						'count' => (int) $row['cnt'],
					);
				},
				$types
			),
			'signal_sources' => array_map(
				static function ( $row ) {
					return array(
						'source'            => (string) $row['source'],
						'count'             => (int) $row['cnt'],
						'latest_period_end' => ! empty( $row['latest_period_end'] ) ? (string) $row['latest_period_end'] : null,
					);
				},
				$sources
			),
			'orphans_total'  => $orphan_total,
			'top_orphans'    => array_map(
				static function ( $row ) {
					return array(
						'node_key' => $row['node_key'],
						'label'    => self::shorten( $row['label'], self::RESOURCE_LABEL_CHARS ),
					);
				},
				$orphan_rows
			),
			'graph_status'   => $status,
			'tools'          => 'wp_graph_query, wp_graph_node, wp_graph_neighbors',
		);

		return self::fit_summary( $summary );
	}

	/**
	 * Enforce RESOURCE_MAX_BYTES by trimming the longest lists from the end.
	 * Constructed small already; this is the hard backstop.
	 *
	 * @param array $summary Summary.
	 * @return array
	 */
	private static function fit_summary( array $summary ) {
		$lists = array( 'nodes_by_kind', 'edges_by_type', 'top_orphans', 'signal_sources' );
		while ( strlen( (string) wp_json_encode( $summary ) ) > self::RESOURCE_MAX_BYTES ) {
			$longest = null;
			foreach ( $lists as $list ) {
				if ( ! empty( $summary[ $list ] ) && ( null === $longest || count( $summary[ $list ] ) > count( $summary[ $longest ] ) ) ) {
					$longest = $list;
				}
			}
			if ( null === $longest ) {
				break;
			}
			array_pop( $summary[ $longest ] );
			$summary['truncated'] = true;
		}
		return $summary;
	}

	/**
	 * @param string $text  Text.
	 * @param int    $chars Max characters.
	 * @return string
	 */
	public static function shorten( $text, $chars ) {
		$text = (string) $text;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $chars ) {
			return mb_substr( $text, 0, $chars - 1 ) . '…';
		}
		if ( strlen( $text ) > $chars ) {
			return substr( $text, 0, $chars - 1 ) . '…';
		}
		return $text;
	}

	/**
	 * Total published orphans visible to the caller.
	 *
	 * @param bool  $can_see_non_public Caller privilege.
	 * @param array $excluded_kinds     Tool-category-gated kinds to exclude
	 *                                  (#1024 round-1 review, BLOCK-2/WARN-5) —
	 *                                  keeps this total in sync, by construction,
	 *                                  with the category-filtered orphan LIST
	 *                                  Mcpwp_REST_Graph::get_summary() builds
	 *                                  from the same $excluded_kinds via
	 *                                  query(), since this reader has no
	 *                                  category concept of its own.
	 * @return int
	 */
	public static function count_orphans( $can_see_non_public, array $excluded_kinds = array(), array $excluded_taxonomies = array() ) {
		$q = self::parse_query_args( array( 'orphan' => true ), $can_see_non_public );
		if ( is_wp_error( $q ) ) {
			return 0;
		}
		list( $where_sql, $where_params ) = self::build_where( $q, $can_see_non_public, $excluded_kinds, $excluded_taxonomies );
		$nodes = Mcpwp_Graph_Builder::nodes_table();
		$sql   = "SELECT COUNT(*) FROM {$nodes} n" . self::posts_join_sql() . " WHERE {$where_sql}";
		return (int) self::get_var( $sql, $where_params );
	}

	// -------------------------------------------------------------------------
	// wp_graph_node
	// -------------------------------------------------------------------------

	/**
	 * Resolve a caller identifier to a node_key: a bare post ID becomes
	 * `post:<id>`, anything else is used as-is (and only ever as a bound value).
	 *
	 * @param string|int $identifier node_key or post_id.
	 * @return string
	 */
	public static function resolve_node_key( $identifier ) {
		$identifier = trim( (string) $identifier );
		if ( '' !== $identifier && ctype_digit( $identifier ) ) {
			return Mcpwp_Graph_Builder::node_key_for_post( (int) $identifier );
		}
		return $identifier;
	}

	/**
	 * Fetch one node row, visibility-checked in SQL and again in PHP.
	 *
	 * @param string $node_key           Node key.
	 * @param bool   $can_see_non_public Caller privilege.
	 * @param array  $excluded_kinds     Tool-category-gated kinds to exclude (#1024).
	 * @return array|null
	 */
	private static function fetch_visible_node_row( $node_key, $can_see_non_public, array $excluded_kinds = array(), array $excluded_taxonomies = array() ) {
		$node_key = (string) $node_key;
		if ( '' === $node_key || strlen( $node_key ) > 191 ) {
			return null;
		}
		$nodes = Mcpwp_Graph_Builder::nodes_table();
		list( $vis_sql, $vis_params )             = self::node_visible_sql( $can_see_non_public );
		list( $kind_excl_sql, $kind_excl_params ) = self::node_category_excluded_sql( $excluded_kinds, $excluded_taxonomies );
		$sql = "SELECT n.id, n.node_key, n.kind, n.ref_id, n.label, n.url, n.status, n.word_count, n.props, n.updated_at, "
			. self::node_protected_sql() . ' AS is_protected'
			. " FROM {$nodes} n" . self::posts_join_sql()
			. " WHERE n.node_key = %s AND {$vis_sql} AND {$kind_excl_sql} LIMIT 1";
		$rows = self::get_results( $sql, array_merge( array( $node_key ), $vis_params, $kind_excl_params ) );
		if ( empty( $rows ) ) {
			return null;
		}
		$row = $rows[0];
		if ( (string) $row['node_key'] !== $node_key ) {
			return null;
		}
		$visible = self::visible_keys( array( $node_key ), $can_see_non_public );
		return isset( $visible[ $node_key ] ) ? $row : null;
	}

	/**
	 * wp_graph_node: node, props, visible inbound/outbound counts by edge
	 * type, latest value per metric per source, and a 28-day trend.
	 *
	 * Returns null when the node does not exist OR is hidden from this
	 * caller: deliberately indistinguishable (the same "404 that discloses
	 * nothing" as Mcpwp_REST_API::guard_non_public_post()).
	 *
	 * @param string $node_key           Node key.
	 * @param bool   $can_see_non_public Caller privilege.
	 * @param array  $excluded_kinds     Tool-category-gated kinds to exclude,
	 *                                   both for the node itself and from its
	 *                                   own edge counts — a category-hidden
	 *                                   neighbor must not count either (#1024
	 *                                   round-1 review, NIT).
	 * @return array|null|WP_Error
	 */
	public static function get_node( $node_key, $can_see_non_public, array $excluded_kinds = array(), array $excluded_taxonomies = array() ) {
		if ( ! Mcpwp_Graph_Builder::ready() ) {
			return self::not_ready_error();
		}
		$row = self::fetch_visible_node_row( $node_key, $can_see_non_public, $excluded_kinds, $excluded_taxonomies );
		if ( null === $row ) {
			return null;
		}
		$node_key = (string) $row['node_key'];
		$edges    = Mcpwp_Graph_Builder::edges_table();
		$signals  = Mcpwp_Graph_Builder::signals_table();

		list( $edge_sql, $edge_params )                     = self::edge_visible_sql( 'e', $can_see_non_public );
		list( $edge_kind_excl_sql, $edge_kind_excl_params ) = self::edge_endpoints_kind_excluded_sql( 'e', $excluded_kinds );
		$outbound = self::get_results(
			"SELECT e.type AS type, COUNT(*) AS cnt FROM {$edges} e WHERE e.from_key = %s AND {$edge_sql} AND {$edge_kind_excl_sql} GROUP BY e.type",
			array_merge( array( $node_key ), $edge_params, $edge_kind_excl_params )
		);
		$inbound = self::get_results(
			"SELECT e.type AS type, COUNT(*) AS cnt FROM {$edges} e WHERE e.to_key = %s AND {$edge_sql} AND {$edge_kind_excl_sql} GROUP BY e.type",
			array_merge( array( $node_key ), $edge_params, $edge_kind_excl_params )
		);

		$latest = self::get_results(
			"SELECT s.metric AS metric, s.source AS source, s.value AS value, s.period_end AS period_end FROM {$signals} s"
			. ' WHERE s.node_key = %s'
			. " AND s.period_end = (SELECT MAX(s2.period_end) FROM {$signals} s2 WHERE s2.node_key = s.node_key AND s2.metric = s.metric AND s2.source = s.source)"
			. ' ORDER BY s.metric ASC, s.source ASC LIMIT %d',
			array( $node_key, 100 )
		);

		$since = gmdate( 'Y-m-d', time() - 28 * DAY_IN_SECONDS );
		$trend = self::get_results(
			"SELECT s.metric AS metric, s.source AS source, SUM(s.value) AS total, AVG(s.value) AS avg_value, COUNT(*) AS cnt FROM {$signals} s"
			. ' WHERE s.node_key = %s AND s.period_end >= %s GROUP BY s.metric, s.source ORDER BY s.metric ASC, s.source ASC LIMIT %d',
			array( $node_key, $since, 100 )
		);

		$node = self::present_node_row( $row, $can_see_non_public );

		$node['edges']   = array(
			'outbound' => self::counts_by_type( $outbound ),
			'inbound'  => self::counts_by_type( $inbound ),
		);
		$node['signals'] = array(
			'latest'    => array_map(
				static function ( $r ) {
					return array(
						'metric'     => (string) $r['metric'],
						'source'     => (string) $r['source'],
						'value'      => (float) $r['value'],
						'period_end' => (string) $r['period_end'],
					);
				},
				$latest
			),
			'trend_28d' => array(
				'since' => $since,
				'rows'  => array_map(
					static function ( $r ) {
						return array(
							'metric' => (string) $r['metric'],
							'source' => (string) $r['source'],
							'sum'    => (float) $r['total'],
							'avg'    => (float) $r['avg_value'],
							'n'      => (int) $r['cnt'],
						);
					},
					$trend
				),
			),
		);

		return $node;
	}

	/**
	 * Shape a node row for output, applying password-protection redaction.
	 *
	 * @param array $row                Node row (with is_protected).
	 * @param bool  $can_see_non_public Caller privilege.
	 * @return array
	 */
	private static function present_node_row( array $row, $can_see_non_public ) {
		$props = json_decode( (string) ( $row['props'] ?? '' ), true );
		$props = is_array( $props ) ? $props : array();
		$live  = self::live_fields( $row );

		$protected = self::is_post_key( $row['node_key'] ) && ! empty( $row['is_protected'] );
		$redact    = $protected && ! $can_see_non_public;
		if ( $redact ) {
			unset( $props['builder'] );
		}

		$out = array(
			'node_key'   => (string) $row['node_key'],
			'kind'       => (string) $row['kind'],
			'ref_id'     => isset( $row['ref_id'] ) && null !== $row['ref_id'] ? (int) $row['ref_id'] : null,
			'label'      => $live['label'],
			'url'        => $live['url'],
			'status'     => $live['status'],
			'word_count' => $redact ? null : (int) $row['word_count'],
			'props'      => empty( $props ) ? new stdClass() : $props,
			'updated_at' => ! empty( $row['updated_at'] ) ? (string) $row['updated_at'] : null,
		);
		if ( $protected ) {
			$out['password_protected'] = true;
		}
		return $out;
	}

	/**
	 * Review W7: label, url and status of a post-backed row come from the
	 * live post, never the stored node (which lags until the worker runs).
	 * Other kinds keep their stored values.
	 *
	 * @param array $row Node row with node_key, label, url, status.
	 * @return array{label:string,url:string,status:string}
	 */
	private static function live_fields( array $row ) {
		$out = array(
			'label'  => (string) ( $row['label'] ?? '' ),
			'url'    => (string) ( $row['url'] ?? '' ),
			'status' => (string) ( $row['status'] ?? '' ),
		);
		if ( self::is_post_key( $row['node_key'] ?? '' ) ) {
			$post = get_post( self::post_id_from_key( $row['node_key'] ) );
			if ( $post ) {
				$out['label']  = (string) get_the_title( $post );
				$out['url']    = (string) get_permalink( $post );
				$out['status'] = (string) $post->post_status;
			}
		}
		return $out;
	}

	/**
	 * @param array $rows {type, cnt}.
	 * @return array<string,int>|stdClass
	 */
	private static function counts_by_type( array $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['type'] ] = (int) $r['cnt'];
		}
		return empty( $out ) ? new stdClass() : $out;
	}

	// -------------------------------------------------------------------------
	// wp_graph_neighbors
	// -------------------------------------------------------------------------

	/**
	 * wp_graph_neighbors: edges plus minimal neighbor nodes, one or two hops.
	 *
	 * The cursor paginates the FIRST hop (edges ordered by id). With depth 2,
	 * each page also carries the second hop reached from that page's
	 * neighbors, capped at NEIGHBORS_DEPTH2_NODE_CAP nodes in total (first
	 * come by edge id); `truncated` says when the cap was hit.
	 *
	 * @param array $args               {node_key, direction, types[], depth, limit, cursor}.
	 * @param bool  $can_see_non_public Caller privilege.
	 * @param array $excluded_kinds     Tool-category-gated kinds to exclude,
	 *                                  for the center, every edge endpoint,
	 *                                  and every neighbor node, applied in
	 *                                  SQL so a page stays full and the
	 *                                  cursor never counts a hidden edge
	 *                                  (#1024 round-1 review, WARN-5).
	 * @return array|null|WP_Error Null when the center is missing or hidden.
	 */
	public static function get_neighbors( array $args, $can_see_non_public, array $excluded_kinds = array(), array $excluded_taxonomies = array() ) {
		if ( ! Mcpwp_Graph_Builder::ready() ) {
			return self::not_ready_error();
		}

		$node_key  = (string) ( $args['node_key'] ?? '' );
		$direction = (string) ( $args['direction'] ?? 'out' );
		if ( '' === $direction ) {
			$direction = 'out';
		}
		if ( ! in_array( $direction, self::DIRECTIONS, true ) ) {
			return self::invalid( 'direction', 'direction must be one of: out, in, both.' );
		}
		$depth = isset( $args['depth'] ) && '' !== $args['depth'] && null !== $args['depth'] ? $args['depth'] : 1;
		if ( ! is_numeric( $depth ) || ! in_array( (int) $depth, array( 1, 2 ), true ) ) {
			return self::invalid( 'depth', 'depth must be 1 or 2.' );
		}
		$depth = (int) $depth;

		$types = self::parse_token_list( $args['types'] ?? array(), 'types' );
		if ( is_wp_error( $types ) ) {
			return $types;
		}
		$limit = self::clamp_limit( $args['limit'] ?? null, self::NEIGHBORS_LIMIT_DEFAULT, self::NEIGHBORS_LIMIT_MAX );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$center = self::fetch_visible_node_row( $node_key, $can_see_non_public, $excluded_kinds, $excluded_taxonomies );
		if ( null === $center ) {
			return null;
		}
		$node_key = (string) $center['node_key'];

		$fingerprint = self::fingerprint(
			array(
				'surface'   => 'neighbors',
				'node_key'  => $node_key,
				'direction' => $direction,
				'types'     => $types,
				'depth'     => $depth,
			),
			$can_see_non_public
		);
		$after_id = 0;
		if ( isset( $args['cursor'] ) && null !== $args['cursor'] && '' !== $args['cursor'] ) {
			$decoded = self::decode_cursor( $args['cursor'], $fingerprint );
			if ( null === $decoded || ! ctype_digit( $decoded['v'] ) || (int) $decoded['v'] !== $decoded['id'] ) {
				return self::invalid_cursor();
			}
			$after_id = $decoded['id'];
		}

		// ---- Hop 1: one page, visibility applied in SQL. ----
		$edges_table = Mcpwp_Graph_Builder::edges_table();
		$params      = array();
		if ( 'out' === $direction ) {
			$dir_sql  = 'e.from_key = %s';
			$params[] = $node_key;
		} elseif ( 'in' === $direction ) {
			$dir_sql  = 'e.to_key = %s';
			$params[] = $node_key;
		} else {
			$dir_sql  = '(e.from_key = %s OR e.to_key = %s)';
			$params[] = $node_key;
			$params[] = $node_key;
		}
		$where = array( $dir_sql );
		if ( ! empty( $types ) ) {
			$where[] = 'e.type IN (' . self::placeholders( count( $types ) ) . ')';
			$params  = array_merge( $params, $types );
		}
		list( $edge_sql, $edge_params )                     = self::edge_visible_sql( 'e', $can_see_non_public );
		list( $edge_kind_excl_sql, $edge_kind_excl_params ) = self::edge_endpoints_kind_excluded_sql( 'e', $excluded_kinds );
		$where[] = $edge_sql;
		$params  = array_merge( $params, $edge_params );
		$where[] = $edge_kind_excl_sql;
		$params  = array_merge( $params, $edge_kind_excl_params );
		if ( $after_id > 0 ) {
			$where[]  = 'e.id > %d';
			$params[] = $after_id;
		}
		$params[] = $limit + 1;

		$hop1 = self::get_results(
			"SELECT e.id AS id, e.from_key AS from_key, e.to_key AS to_key, e.type AS type, e.weight AS weight, e.source AS source FROM {$edges_table} e WHERE "
			. implode( ' AND ', $where ) . ' ORDER BY e.id ASC LIMIT %d',
			$params
		);

		$has_more = count( $hop1 ) > $limit;
		$hop1     = array_slice( $hop1, 0, $limit );

		$out_edges  = array();
		$node_set   = array( $node_key => true );
		$seen_edges = array();
		$bytes      = 0;
		$last_id    = 0;
		$truncated  = false;

		$filtered = self::filter_edges_visible( $hop1, $can_see_non_public );
		foreach ( $hop1 as $edge ) {
			$last_id = (int) $edge['id'];
			if ( ! isset( $filtered[ $last_id ] ) ) {
				continue; // Hidden by the PHP backstop; the page still advances past it.
			}
			$shaped = self::present_edge( $edge );
			$bytes += strlen( (string) wp_json_encode( $shaped ) ) + 1;
			if ( $bytes > self::MAX_RESPONSE_BYTES / 2 ) {
				$has_more = true;
				$last_id  = (int) $edge['id'] - 1; // Resume AT this edge.
				break;
			}
			$out_edges[]                = $shaped;
			$seen_edges[ $edge['id'] ] = true;
			$neighbor                   = ( $edge['from_key'] === $node_key ) ? $edge['to_key'] : $edge['from_key'];
			$node_set[ $neighbor ]      = true;
		}

		// ---- Hop 2: one query over this page's neighbors. ----
		if ( 2 === $depth && count( $node_set ) > 1 ) {
			$hop1_keys = array_values( array_diff( array_keys( $node_set ), array( $node_key ) ) );
			$hop2      = self::fetch_hop2_edges( $hop1_keys, $direction, $types, $can_see_non_public, $excluded_kinds );
			if ( count( $hop2 ) >= self::HOP2_EDGE_SCAN_MAX ) {
				$truncated = true;
			}
			$hop2_visible = self::filter_edges_visible( $hop2, $can_see_non_public );
			foreach ( $hop2 as $edge ) {
				if ( isset( $seen_edges[ $edge['id'] ] ) || ! isset( $hop2_visible[ (int) $edge['id'] ] ) ) {
					continue;
				}
				$new = array();
				foreach ( array( $edge['from_key'], $edge['to_key'] ) as $k ) {
					if ( ! isset( $node_set[ $k ] ) ) {
						$new[ $k ] = true;
					}
				}
				if ( count( $node_set ) + count( $new ) > self::NEIGHBORS_DEPTH2_NODE_CAP ) {
					$truncated = true;
					continue;
				}
				$shaped = self::present_edge( $edge );
				$size   = strlen( (string) wp_json_encode( $shaped ) ) + 1;
				if ( $bytes + $size > self::MAX_RESPONSE_BYTES / 2 ) {
					$truncated = true;
					break;
				}
				$bytes                     += $size;
				$out_edges[]                = $shaped;
				$seen_edges[ $edge['id'] ] = true;
				$node_set                  += $new;
			}
		}

		$neighbor_keys = array_values( array_diff( array_keys( $node_set ), array( $node_key ) ) );
		$nodes         = array();
		foreach ( self::fetch_visible_minimal_nodes( $neighbor_keys, $can_see_non_public, $excluded_kinds, $excluded_taxonomies ) as $node ) {
			$size = strlen( (string) wp_json_encode( $node ) ) + 1;
			if ( $bytes + $size > self::MAX_RESPONSE_BYTES ) {
				$truncated = true; // Edges stay; the remaining neighbor rows are dropped.
				break;
			}
			$bytes  += $size;
			$nodes[] = $node;
		}

		return array(
			'center'      => self::present_minimal_node( $center ),
			'direction'   => $direction,
			'depth'       => $depth,
			'edges'       => $out_edges,
			'nodes'       => $nodes,
			'truncated'   => $truncated,
			'next_cursor' => $has_more ? self::encode_cursor( $last_id, (string) $last_id, $fingerprint ) : null,
		);
	}

	/**
	 * Second-hop edges touching any of $keys in $direction (one query).
	 *
	 * @param array<string> $keys               Hop-1 neighbor keys (<= 100).
	 * @param string        $direction          out|in|both.
	 * @param array<string> $types              Validated edge types.
	 * @param bool          $can_see_non_public Caller privilege.
	 * @param array         $excluded_kinds     Tool-category-gated kinds to exclude (#1024).
	 * @return array<int,array>
	 */
	private static function fetch_hop2_edges( array $keys, $direction, array $types, $can_see_non_public, array $excluded_kinds = array() ) {
		$edges_table = Mcpwp_Graph_Builder::edges_table();
		$in          = self::placeholders( count( $keys ) );
		$params      = array();
		if ( 'out' === $direction ) {
			$dir_sql = "e.from_key IN ({$in})";
			$params  = $keys;
		} elseif ( 'in' === $direction ) {
			$dir_sql = "e.to_key IN ({$in})";
			$params  = $keys;
		} else {
			$dir_sql = "(e.from_key IN ({$in}) OR e.to_key IN ({$in}))";
			$params  = array_merge( $keys, $keys );
		}
		$where = array( $dir_sql );
		if ( ! empty( $types ) ) {
			$where[] = 'e.type IN (' . self::placeholders( count( $types ) ) . ')';
			$params  = array_merge( $params, $types );
		}
		list( $edge_sql, $edge_params )                     = self::edge_visible_sql( 'e', $can_see_non_public );
		list( $edge_kind_excl_sql, $edge_kind_excl_params ) = self::edge_endpoints_kind_excluded_sql( 'e', $excluded_kinds );
		$where[]  = $edge_sql;
		$params   = array_merge( $params, $edge_params );
		$where[]  = $edge_kind_excl_sql;
		$params   = array_merge( $params, $edge_kind_excl_params );
		$params[] = self::HOP2_EDGE_SCAN_MAX;

		return self::get_results(
			"SELECT e.id AS id, e.from_key AS from_key, e.to_key AS to_key, e.type AS type, e.weight AS weight, e.source AS source FROM {$edges_table} e WHERE "
			. implode( ' AND ', $where ) . ' ORDER BY e.id ASC LIMIT %d',
			$params
		);
	}

	/**
	 * PHP backstop for edge visibility: both endpoints visible, and no
	 * content edge from a password-protected post for an unprivileged caller.
	 *
	 * @param array<int,array> $edges              Edge rows.
	 * @param bool             $can_see_non_public Caller privilege.
	 * @return array<int,bool> Set of visible edge ids.
	 */
	private static function filter_edges_visible( array $edges, $can_see_non_public ) {
		$keys = array();
		foreach ( $edges as $e ) {
			$keys[] = (string) $e['from_key'];
			$keys[] = (string) $e['to_key'];
		}
		$visible   = self::visible_keys( array_unique( $keys ), $can_see_non_public );
		$protected = array();
		if ( ! $can_see_non_public ) {
			$ids = array();
			foreach ( $edges as $e ) {
				if ( in_array( (string) $e['type'], self::CONTENT_EDGE_TYPES, true ) && self::is_post_key( $e['from_key'] ) ) {
					$ids[] = self::post_id_from_key( $e['from_key'] );
				}
			}
			$protected = self::protected_post_ids( $ids );
		}
		$out = array();
		foreach ( $edges as $e ) {
			if ( ! isset( $visible[ (string) $e['from_key'] ], $visible[ (string) $e['to_key'] ] ) ) {
				continue;
			}
			if ( ! $can_see_non_public
				&& in_array( (string) $e['type'], self::CONTENT_EDGE_TYPES, true )
				&& isset( $protected[ self::post_id_from_key( $e['from_key'] ) ] ) ) {
				continue;
			}
			$out[ (int) $e['id'] ] = true;
		}
		return $out;
	}

	/**
	 * @param array $edge Edge row.
	 * @return array
	 */
	private static function present_edge( array $edge ) {
		return array(
			'from_key' => (string) $edge['from_key'],
			'to_key'   => (string) $edge['to_key'],
			'type'     => (string) $edge['type'],
			'weight'   => (float) $edge['weight'],
			'source'   => (string) $edge['source'],
		);
	}

	/**
	 * @param array $row Node row.
	 * @return array
	 */
	private static function present_minimal_node( array $row ) {
		$live = self::live_fields( $row );
		return array(
			'node_key' => (string) $row['node_key'],
			'kind'     => (string) $row['kind'],
			'label'    => $live['label'],
			'url'      => $live['url'],
			'status'   => $live['status'],
		);
	}

	/**
	 * Minimal rows for visible nodes among $keys (SQL + PHP visibility).
	 *
	 * @param array<string> $keys               Node keys (<= 200).
	 * @param bool          $can_see_non_public Caller privilege.
	 * @param array         $excluded_kinds     Tool-category-gated kinds to exclude (#1024).
	 * @return array<int,array>
	 */
	private static function fetch_visible_minimal_nodes( array $keys, $can_see_non_public, array $excluded_kinds = array(), array $excluded_taxonomies = array() ) {
		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		if ( empty( $keys ) ) {
			return array();
		}
		$nodes = Mcpwp_Graph_Builder::nodes_table();
		list( $vis_sql, $vis_params )             = self::node_visible_sql( $can_see_non_public );
		list( $kind_excl_sql, $kind_excl_params ) = self::node_category_excluded_sql( $excluded_kinds, $excluded_taxonomies );
		$rows = self::get_results(
			"SELECT n.node_key AS node_key, n.kind AS kind, n.label AS label, n.url AS url, n.status AS status FROM {$nodes} n" . self::posts_join_sql()
			. ' WHERE n.node_key IN (' . self::placeholders( count( $keys ) ) . ") AND {$vis_sql} AND {$kind_excl_sql} ORDER BY n.id ASC",
			array_merge( $keys, $vis_params, $kind_excl_params )
		);
		$visible = self::visible_keys( array_column( $rows, 'node_key' ), $can_see_non_public );
		$out     = array();
		foreach ( $rows as $row ) {
			if ( isset( $visible[ (string) $row['node_key'] ] ) && in_array( (string) $row['node_key'], $keys, true ) ) {
				$out[] = self::present_minimal_node( $row );
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// wp_graph_query
	// -------------------------------------------------------------------------

	/**
	 * Validate and normalize wp_graph_query arguments. Anything malformed is
	 * a 400, never a silently dropped filter (dropping one widens results).
	 *
	 * @param array $args               Raw args.
	 * @param bool  $can_see_non_public Caller privilege.
	 * @return array|WP_Error Normalized query.
	 */
	public static function parse_query_args( array $args, $can_see_non_public ) {
		$q = array();

		$kinds = self::parse_token_list( $args['kind'] ?? array(), 'kind' );
		if ( is_wp_error( $kinds ) ) {
			return $kinds;
		}
		sort( $kinds );
		$q['kind'] = $kinds;

		// status: read scope is ALWAYS clamped to 'publish', whatever was
		// asked for (same clamp as every other list endpoint). A privileged
		// caller may name any status token, or 'any'.
		$status = self::present( $args, 'status' ) ? $args['status'] : '';
		if ( ! is_string( $status ) ) {
			return self::invalid( 'status', 'status must be a string.' );
		}
		$status = strtolower( trim( $status ) );
		if ( '' !== $status && '' === self::sanitize_enum_token( $status ) ) {
			return self::invalid( 'status', 'status must be a post status name (lowercase letters, digits, _ or -) or "any".' );
		}
		$q['status'] = $can_see_non_public ? ( '' !== $status ? $status : 'publish' ) : 'publish';

		$q['orphan'] = false;
		if ( self::present( $args, 'orphan' ) ) {
			$orphan = self::parse_bool( $args['orphan'] );
			if ( null === $orphan ) {
				return self::invalid( 'orphan', 'orphan must be a boolean.' );
			}
			$q['orphan'] = $orphan;
		}

		foreach ( array( 'min_inbound', 'max_inbound', 'word_count_lt' ) as $int_field ) {
			$q[ $int_field ] = null;
			if ( self::present( $args, $int_field ) ) {
				$value = $args[ $int_field ];
				if ( ! self::is_non_negative_int( $value ) ) {
					return self::invalid( $int_field, "{$int_field} must be a non-negative integer." );
				}
				$q[ $int_field ] = (int) $value;
			}
		}

		$q['has_metric'] = null;
		if ( self::present( $args, 'has_metric' ) ) {
			$metric = self::sanitize_enum_token( $args['has_metric'] );
			if ( '' === $metric ) {
				return self::invalid( 'has_metric', 'has_metric must be a metric name (lowercase letters, digits, _ or -).' );
			}
			$q['has_metric'] = $metric;
		}

		$q['no_edge_type'] = null;
		if ( self::present( $args, 'no_edge_type' ) ) {
			$type = self::sanitize_enum_token( $args['no_edge_type'] );
			if ( '' === $type ) {
				return self::invalid( 'no_edge_type', 'no_edge_type must be an edge type name (lowercase letters, digits, _ or -).' );
			}
			$q['no_edge_type'] = $type;
		}

		$q['modified_before'] = null;
		if ( self::present( $args, 'modified_before' ) ) {
			$ts = self::sanitize_datetime_string( $args['modified_before'] );
			if ( null === $ts ) {
				return self::invalid( 'modified_before', 'modified_before must be a date (Y-m-d) or ISO 8601 date-time.' );
			}
			$q['modified_before'] = $ts;
		}

		$q['metric'] = null;
		if ( self::present( $args, 'metric' ) ) {
			$metric = self::parse_metric_filter( $args['metric'] );
			if ( is_wp_error( $metric ) ) {
				return $metric;
			}
			$q['metric'] = $metric;
		}

		$order_by = self::present( $args, 'order_by' ) ? $args['order_by'] : 'label';
		if ( ! is_string( $order_by ) ) {
			return self::invalid( 'order_by', 'order_by must be a string.' );
		}
		$order_by = trim( $order_by );
		if ( in_array( $order_by, self::ORDER_BY_KEYWORDS, true ) ) {
			$q['order_by'] = $order_by;
		} elseif ( '' !== self::sanitize_enum_token( $order_by ) ) {
			$q['order_by'] = 'metric:' . $order_by;
		} else {
			return self::invalid( 'order_by', 'order_by must be label, modified, inbound, or a metric name.' );
		}

		$dir = self::present( $args, 'order_dir' ) ? $args['order_dir'] : 'asc';
		if ( ! is_string( $dir ) || ! in_array( strtolower( $dir ), array( 'asc', 'desc' ), true ) ) {
			return self::invalid( 'order_dir', 'order_dir must be asc or desc.' );
		}
		$q['order_dir'] = strtolower( $dir );

		$limit = self::clamp_limit( $args['limit'] ?? null, self::QUERY_LIMIT_DEFAULT, self::QUERY_LIMIT_MAX );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$q['fingerprint'] = self::fingerprint( array_merge( array( 'surface' => 'query' ), $q ), $can_see_non_public );
		$q['limit']       = $limit;
		$q['cursor']      = null;

		if ( self::present( $args, 'cursor' ) ) {
			$decoded = self::decode_cursor( $args['cursor'], $q['fingerprint'] );
			if ( null === $decoded ) {
				return self::invalid_cursor();
			}
			// Order values that should be numeric must be numeric strings.
			if ( in_array( $q['order_by'], array( 'inbound' ), true ) || 0 === strpos( $q['order_by'], 'metric:' ) ) {
				if ( ! is_numeric( $decoded['v'] ) ) {
					return self::invalid_cursor();
				}
			}
			$q['cursor'] = $decoded;
		}

		return $q;
	}

	/**
	 * @param mixed $raw Raw metric filter: array/object, or a JSON string of one.
	 * @return array|WP_Error
	 */
	private static function parse_metric_filter( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : null;
		}
		if ( is_object( $raw ) ) {
			$raw = (array) $raw;
		}
		if ( ! is_array( $raw ) ) {
			return self::invalid( 'metric', 'metric must be an object: {name, source?, op: gt|lt, value, window_days?}.' );
		}
		$allowed = array( 'name', 'source', 'op', 'value', 'window_days' );
		foreach ( array_keys( $raw ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				return self::invalid( 'metric', 'metric has an unknown key. Allowed: name, source, op, value, window_days.' );
			}
		}
		$name = self::sanitize_enum_token( $raw['name'] ?? '' );
		if ( '' === $name ) {
			return self::invalid( 'metric', 'metric.name must be a metric name (lowercase letters, digits, _ or -).' );
		}
		$op = is_string( $raw['op'] ?? null ) ? strtolower( $raw['op'] ) : '';
		if ( ! isset( self::METRIC_OPS[ $op ] ) ) {
			return self::invalid( 'metric', 'metric.op must be gt or lt.' );
		}
		if ( ! isset( $raw['value'] ) || ! is_numeric( $raw['value'] ) || ! is_finite( (float) $raw['value'] ) ) {
			return self::invalid( 'metric', 'metric.value must be a number.' );
		}
		$source = null;
		if ( isset( $raw['source'] ) && '' !== $raw['source'] && null !== $raw['source'] ) {
			$source = self::sanitize_enum_token( $raw['source'] );
			if ( '' === $source ) {
				return self::invalid( 'metric', 'metric.source must be a source name (e.g. gsc, posthog).' );
			}
		}
		$window = 28;
		if ( isset( $raw['window_days'] ) && '' !== $raw['window_days'] && null !== $raw['window_days'] ) {
			if ( ! self::is_non_negative_int( $raw['window_days'] ) || (int) $raw['window_days'] < 1 || (int) $raw['window_days'] > 400 ) {
				return self::invalid( 'metric', 'metric.window_days must be an integer from 1 to 400.' );
			}
			$window = (int) $raw['window_days'];
		}
		return array(
			'name'        => $name,
			'source'      => $source,
			'op'          => $op,
			'value'       => (float) $raw['value'],
			'window_days' => $window,
		);
	}

	/**
	 * WHERE clause (without the cursor keyset) for a normalized query.
	 *
	 * @param array $q                  Normalized query.
	 * @param bool  $can_see_non_public Caller privilege.
	 * @param array $excluded_kinds     Tool-category-gated kinds to exclude (#1024 WARN-5).
	 * @return array{0:string,1:array}
	 */
	private static function build_where( array $q, $can_see_non_public, array $excluded_kinds = array(), array $excluded_taxonomies = array() ) {
		$edges   = Mcpwp_Graph_Builder::edges_table();
		$signals = Mcpwp_Graph_Builder::signals_table();

		list( $vis_sql, $params ) = self::node_visible_sql( $can_see_non_public );
		$where = array( $vis_sql );

		list( $kind_excl_sql, $kind_excl_params ) = self::node_category_excluded_sql( $excluded_kinds, $excluded_taxonomies );
		$where[] = $kind_excl_sql;
		$params  = array_merge( $params, $kind_excl_params );

		if ( ! empty( $q['kind'] ) ) {
			$where[] = 'n.kind IN (' . self::placeholders( count( $q['kind'] ) ) . ')';
			$params  = array_merge( $params, $q['kind'] );
		}

		// Status applies to post-backed nodes only; term/media/query/url/fact
		// nodes carry no post status and are not excluded by it. It is the
		// LIVE post status (review NIT), like visibility.
		if ( 'any' !== $q['status'] ) {
			$where[]  = "(LEFT(n.node_key, 5) <> 'post:' OR p.post_status = %s)";
			$params[] = $q['status'];
		}

		if ( $q['orphan'] ) {
			list( $live_sql, $live_params ) = self::live_inbound_sql( 'eo', $excluded_kinds );
			// Review round 2, NIT-3: "published" is every public status, the
			// same public_statuses() set visibility uses, not a literal.
			$statuses = self::public_statuses();
			$where[]  = "LEFT(n.node_key, 5) = 'post:' AND p.post_status IN (" . self::placeholders( count( $statuses ) ) . ')'
				. " AND NOT EXISTS (SELECT 1 FROM {$edges} eo WHERE eo.to_key = n.node_key AND eo.type = 'links_to' AND {$live_sql})";
			$params   = array_merge( $params, $statuses, $live_params );
		}

		if ( null !== $q['min_inbound'] || null !== $q['max_inbound'] ) {
			foreach ( array( 'min_inbound' => '>=', 'max_inbound' => '<=' ) as $field => $op ) {
				if ( null === $q[ $field ] ) {
					continue;
				}
				// ic is the inbound derived table query() joins (review W6).
				$where[]  = "IFNULL(ic.c, 0) {$op} %d";
				$params[] = (int) $q[ $field ];
			}
		}

		if ( null !== $q['has_metric'] ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$signals} sh WHERE sh.node_key = n.node_key AND sh.metric = %s)";
			$params[] = $q['has_metric'];
		}

		if ( null !== $q['no_edge_type'] ) {
			$clause = "NOT EXISTS (SELECT 1 FROM {$edges} en WHERE en.from_key = n.node_key AND en.type = %s)";
			if ( ! $can_see_non_public && in_array( $q['no_edge_type'], self::CONTENT_EDGE_TYPES, true ) ) {
				// A protected post's content edges do not exist for this caller.
				$clause = '(' . self::node_protected_sql() . " OR {$clause})";
			}
			$where[]  = $clause;
			$params[] = $q['no_edge_type'];
		}

		if ( null !== $q['modified_before'] ) {
			$where[]  = "LEFT(n.node_key, 5) = 'post:' AND p.post_modified_gmt < %s";
			$params[] = $q['modified_before'];
		}

		if ( null !== $q['word_count_lt'] ) {
			$clause = 'n.word_count < %d';
			if ( ! $can_see_non_public ) {
				$clause = 'NOT ' . self::node_protected_sql() . " AND {$clause}";
			}
			$where[]  = $clause;
			$params[] = (int) $q['word_count_lt'];
		}

		if ( null !== $q['metric'] ) {
			$m        = $q['metric'];
			$op       = self::METRIC_OPS[ $m['op'] ]; // Fixed '>' or '<', never caller text.
			$since    = gmdate( 'Y-m-d', time() - $m['window_days'] * DAY_IN_SECONDS );
			$sub      = "SELECT SUM(sm.value) FROM {$signals} sm WHERE sm.node_key = n.node_key AND sm.metric = %s AND sm.period_end >= %s";
			$params[] = $m['name'];
			$params[] = $since;
			if ( null !== $m['source'] ) {
				$sub     .= ' AND sm.source = %s';
				$params[] = $m['source'];
			}
			// The threshold is bound as a string: %f would round it to 6 places.
			$where[]  = "IFNULL(({$sub}), 0) {$op} %s";
			$params[] = self::number_string( $m['value'] );
		}

		return array( '(' . implode( ') AND (', $where ) . ')', $params );
	}

	/**
	 * Order expression for a normalized order_by.
	 *
	 * @param string $order_by label|modified|inbound|metric:<name>.
	 * @return array{0:string,1:array}
	 */
	private static function order_expr( $order_by ) {
		if ( 'label' === $order_by ) {
			// Review W7: a post sorts by its LIVE title (the one displayed).
			return array( "(CASE WHEN LEFT(n.node_key, 5) = 'post:' THEN IFNULL(p.post_title, '') ELSE IFNULL(n.label, '') END)", array() );
		}
		if ( 'modified' === $order_by ) {
			return array( "IFNULL(p.post_modified_gmt, '1000-01-01 00:00:00')", array() );
		}
		if ( 'inbound' === $order_by ) {
			return array( 'IFNULL(ic.c, 0)', array() );
		}
		$signals = Mcpwp_Graph_Builder::signals_table();
		$metric  = substr( $order_by, strlen( 'metric:' ) );
		return array(
			"IFNULL((SELECT so.value FROM {$signals} so WHERE so.node_key = n.node_key AND so.metric = %s ORDER BY so.period_end DESC LIMIT 1), 0)",
			array( $metric ),
		);
	}

	/**
	 * wp_graph_query: allowlisted-filter node search with keyset pagination.
	 *
	 * @param array $args               Raw args (see parse_query_args()).
	 * @param bool  $can_see_non_public Caller privilege.
	 * @param array $excluded_kinds     Tool-category-gated kinds to exclude,
	 *                                  applied in SQL so pages stay full and
	 *                                  the cursor never counts a hidden row
	 *                                  (#1024 WARN-5).
	 * @return array|WP_Error {results, next_cursor, truncated}
	 */
	public static function query( array $args, $can_see_non_public, array $excluded_kinds = array(), array $excluded_taxonomies = array() ) {
		if ( ! Mcpwp_Graph_Builder::ready() ) {
			return self::not_ready_error();
		}
		$q = self::parse_query_args( $args, $can_see_non_public );
		if ( is_wp_error( $q ) ) {
			return $q;
		}

		$nodes = Mcpwp_Graph_Builder::nodes_table();
		list( $where_sql, $where_params ) = self::build_where( $q, $can_see_non_public, $excluded_kinds, $excluded_taxonomies );
		list( $order_sql, $order_params ) = self::order_expr( $q['order_by'] );
		$desc = 'desc' === $q['order_dir'];

		$join_sql    = '';
		$join_params = array();
		if ( 'inbound' === $q['order_by'] || null !== $q['min_inbound'] || null !== $q['max_inbound'] ) {
			list( $join_sql, $join_params ) = self::inbound_join_sql( $excluded_kinds );
		}

		$sql    = "SELECT n.id AS id, n.node_key AS node_key, n.kind AS kind, n.ref_id AS ref_id, n.label AS label, n.url AS url, n.status AS status, n.word_count AS word_count, n.props AS props, n.updated_at AS updated_at, "
			. self::node_protected_sql() . " AS is_protected, {$order_sql} AS order_value"
			. " FROM {$nodes} n" . self::posts_join_sql() . $join_sql
			. " WHERE {$where_sql}";
		$params = array_merge( $order_params, $join_params, $where_params );

		if ( null !== $q['cursor'] ) {
			$cmp     = $desc ? '<' : '>';
			$sql    .= " AND (({$order_sql}) {$cmp} %s OR (({$order_sql}) = %s AND n.id {$cmp} %d))";
			$params  = array_merge( $params, $order_params, array( $q['cursor']['v'] ), $order_params, array( $q['cursor']['v'], $q['cursor']['id'] ) );
		}

		$dir     = $desc ? 'DESC' : 'ASC';
		$sql    .= " ORDER BY order_value {$dir}, n.id {$dir} LIMIT %d";
		$params[] = $q['limit'] + 1;

		$rows = self::get_results( $sql, $params );

		$has_more = count( $rows ) > $q['limit'];
		$rows     = array_slice( $rows, 0, $q['limit'] );

		// PHP backstop: re-check every post-backed row against the live post.
		$visible = self::visible_keys( array_column( $rows, 'node_key' ), $can_see_non_public );

		$results      = array();
		$bytes        = 0;
		$last         = null; // Last row processed, visible or not.
		$last_visible = null; // Last row returned to the caller.
		$truncated    = false;
		$numeric   = 'inbound' === $q['order_by'] || 0 === strpos( $q['order_by'], 'metric:' );
		foreach ( $rows as $row ) {
			if ( ! isset( $visible[ (string) $row['node_key'] ] ) ) {
				$last = $row; // Skip, but paginate past it.
				continue;
			}
			$item = self::present_node_row( $row, $can_see_non_public );
			if ( $numeric ) {
				$item['sort_value'] = (float) ( $row['order_value'] ?? 0 );
			}
			$size = strlen( (string) wp_json_encode( $item ) ) + 1;
			if ( $bytes + $size > self::MAX_RESPONSE_BYTES && ! empty( $results ) ) {
				$has_more  = true;
				$truncated = true;
				break;
			}
			$bytes       += $size;
			$results[]    = $item;
			$last         = $row;
			$last_visible = $row;
		}

		// Review W2: the cursor is built from the last row the caller was
		// actually shown, so a row the backstop dropped never becomes its
		// order value. Rows dropped after it are simply re-read (and dropped
		// again) by the next page. Only a page with NO visible row at all
		// (the SQL and PHP halves disagreeing for a whole page, which the
		// shared rule makes a race-only case) resumes after the last row
		// processed; the cursor is encrypted, so even then its order value
		// is not readable, and pagination cannot loop on that page.
		$next   = null;
		$anchor = null !== $last_visible ? $last_visible : $last;
		if ( $has_more && null !== $anchor ) {
			$next = self::encode_cursor( (int) $anchor['id'], (string) ( $anchor['order_value'] ?? '' ), $q['fingerprint'] );
		}

		return array(
			'results'     => $results,
			'count'       => count( $results ),
			'next_cursor' => $next,
			'truncated'   => $truncated,
		);
	}

	// -------------------------------------------------------------------------
	// Validation helpers
	// -------------------------------------------------------------------------

	/**
	 * A token for an enum-shaped column (kind, status, edge type, metric,
	 * source): lowercase letters, digits, `_` and `-`, 1-64 chars. Anything
	 * else returns '' so the caller rejects it.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_enum_token( $value ) {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return '';
		}
		$value = (string) $value;
		return 1 === preg_match( '/^[a-z0-9_-]{1,64}$/D', $value ) ? $value : '';
	}

	/**
	 * Comma string or array of tokens -> validated, de-duplicated list.
	 *
	 * @param mixed  $raw   Raw value.
	 * @param string $field Field name for the error.
	 * @return array<int,string>|WP_Error
	 */
	private static function parse_token_list( $raw, $field ) {
		if ( null === $raw || '' === $raw ) {
			return array();
		}
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return self::invalid( $field, "{$field} must be a list of names." );
		}
		$out = array();
		foreach ( $raw as $item ) {
			$item  = is_string( $item ) ? trim( $item ) : $item;
			if ( '' === $item ) {
				continue;
			}
			$token = self::sanitize_enum_token( $item );
			if ( '' === $token ) {
				return self::invalid( $field, "{$field} entries must be names made of lowercase letters, digits, _ or -." );
			}
			$out[ $token ] = true;
		}
		if ( count( $out ) > 50 ) {
			return self::invalid( $field, "{$field} accepts at most 50 entries." );
		}
		return array_keys( $out );
	}

	/**
	 * @param mixed $raw     Raw limit.
	 * @param int   $default Default.
	 * @param int   $max     Max.
	 * @return int|WP_Error
	 */
	private static function clamp_limit( $raw, $default, $max ) {
		if ( null === $raw || '' === $raw ) {
			return $default;
		}
		if ( ! self::is_non_negative_int( $raw ) ) {
			return self::invalid( 'limit', "limit must be an integer from 1 to {$max}." );
		}
		return max( 1, min( $max, (int) $raw ) );
	}

	/**
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_non_negative_int( $value ) {
		if ( is_int( $value ) ) {
			return $value >= 0;
		}
		if ( is_float( $value ) ) {
			return $value >= 0 && floor( $value ) === $value && $value <= PHP_INT_MAX;
		}
		return is_string( $value ) && 1 === preg_match( '/^\d{1,9}$/D', $value );
	}

	/**
	 * @param mixed $value Raw boolean.
	 * @return bool|null Null when not a boolean-ish value.
	 */
	private static function parse_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 1 === $value || '1' === $value || 'true' === $value ) {
			return true;
		}
		if ( 0 === $value || '0' === $value || 'false' === $value || '' === $value ) {
			return false;
		}
		return null;
	}

	/**
	 * Whether $args has a non-empty value at $key.
	 *
	 * @param array  $args Args.
	 * @param string $key  Key.
	 * @return bool
	 */
	private static function present( array $args, $key ) {
		return array_key_exists( $key, $args ) && null !== $args[ $key ] && '' !== $args[ $key ];
	}

	/**
	 * Parse a caller date/date-time into 'Y-m-d H:i:s' GMT, or null. The
	 * value reaching SQL is always this method's own gmdate() output.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	public static function sanitize_datetime_string( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		// Only date / ISO 8601 shapes: strtotime() also accepts free text
		// ("next monday", "@123"), which is not a documented input.
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', $value ) ) {
			return null;
		}
		$has_tz = 1 === preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/D', $value ) && strlen( $value ) > 10;
		$ts     = strtotime( $has_tz ? $value : $value . ' UTC' );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Round-trip-exact decimal string for a float (bound as %s).
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function number_string( $value ) {
		$json = json_encode( (float) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- plain scalar encode.
		return false === $json ? '0' : $json;
	}

	/**
	 * @param string $field   Field.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function invalid( $field, $message ) {
		return new WP_Error(
			'invalid_filter',
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * @return WP_Error
	 */
	private static function invalid_cursor() {
		return new WP_Error( 'invalid_cursor', 'The cursor is malformed, was altered, or belongs to a different query. Start again without a cursor.', array( 'status' => 400 ) );
	}

	// -------------------------------------------------------------------------
	// Opaque cursors
	// -------------------------------------------------------------------------

	/**
	 * Fingerprint of a normalized query + the caller's privilege. A cursor
	 * minted for one query (or privilege) is refused for any other.
	 *
	 * @param array $parts              Normalized query parts.
	 * @param bool  $can_see_non_public Caller privilege.
	 * @return string
	 */
	private static function fingerprint( array $parts, $can_see_non_public ) {
		$parts['priv'] = $can_see_non_public ? 1 : 0;
		return substr( hash( 'sha256', (string) wp_json_encode( $parts ) ), 0, 16 );
	}

	/**
	 * @return string Key material for cursor_secret().
	 */
	private static function cursor_key() {
		if ( function_exists( 'wp_salt' ) ) {
			return 'mcpwp-graph-cursor|' . wp_salt( 'nonce' );
		}
		return 'mcpwp-graph-cursor|' . ( defined( 'NONCE_SALT' ) ? NONCE_SALT : ABSPATH );
	}

	/**
	 * 32-byte secretbox key derived from the site's nonce salt.
	 *
	 * @return string
	 */
	private static function cursor_secret() {
		return sodium_crypto_generichash( self::cursor_key(), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encode an opaque cursor: base64url( nonce || secretbox(json) ).
	 * Authenticated encryption, so it is both unreadable and tamper-evident.
	 *
	 * @param int    $last_id     Last visible row id.
	 * @param string $order_value Last visible row's order value (as MySQL returned it).
	 * @param string $fingerprint Query fingerprint.
	 * @return string
	 */
	public static function encode_cursor( $last_id, $order_value, $fingerprint ) {
		$payload = (string) wp_json_encode(
			array(
				'k' => self::CURSOR_VERSION,
				'i' => (int) $last_id,
				'v' => (string) $order_value,
				'q' => (string) $fingerprint,
			)
		);
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::b64url_encode( $nonce . sodium_crypto_secretbox( $payload, $nonce, self::cursor_secret() ) );
	}

	/**
	 * Decrypt and validate a cursor. Null for anything that is not exactly a
	 * cursor this class minted for this query fingerprint.
	 *
	 * @param mixed  $cursor      Raw cursor.
	 * @param string $fingerprint Expected fingerprint.
	 * @return array{id:int,v:string}|null
	 */
	public static function decode_cursor( $cursor, $fingerprint ) {
		if ( ! is_string( $cursor ) || '' === $cursor || strlen( $cursor ) > 2048 || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $cursor ) ) {
			return null;
		}
		$raw = self::b64url_decode( $cursor );
		if ( null === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		try {
			$payload = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::cursor_secret() );
		} catch ( Exception $e ) {
			return null;
		}
		if ( false === $payload ) {
			return null;
		}
		$data = json_decode( $payload, true );
		if ( ! is_array( $data )
			|| self::CURSOR_VERSION !== ( $data['k'] ?? null )
			|| ! is_int( $data['i'] ?? null ) || $data['i'] < 0
			|| ! is_string( $data['v'] ?? null ) || strlen( $data['v'] ) > 1024
			|| ! is_string( $data['q'] ?? null ) || ! hash_equals( (string) $fingerprint, $data['q'] ) ) {
			return null;
		}
		return array(
			'id' => $data['i'],
			'v'  => $data['v'],
		);
	}

	/**
	 * @param string $data Raw.
	 * @return string
	 */
	private static function b64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- opaque pagination token.
	}

	/**
	 * @param string $data Encoded.
	 * @return string|null
	 */
	private static function b64url_decode( $data ) {
		$raw = base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- opaque pagination token.
		return false === $raw ? null : $raw;
	}

	// -------------------------------------------------------------------------
	// $wpdb wrappers: one prepare(), errors suppressed via the builder's W6 helper.
	// -------------------------------------------------------------------------

	/**
	 * @param string $sql    Server-written template.
	 * @param array  $params Positional values.
	 * @return array<int,array>
	 */
	private static function get_results( $sql, array $params ) {
		global $wpdb;
		$rows = Mcpwp_Graph_Builder::run_suppressed_query(
			function () use ( $wpdb, $sql, $params ) {
				return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- server-written template, every value bound.
			}
		);
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * @param string $sql    Server-written template.
	 * @param array  $params Positional values.
	 * @return mixed
	 */
	private static function get_var( $sql, array $params ) {
		global $wpdb;
		return Mcpwp_Graph_Builder::run_suppressed_query(
			function () use ( $wpdb, $sql, $params ) {
				return empty( $params ) ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- server-written template, every value bound.
			}
		);
	}
}
