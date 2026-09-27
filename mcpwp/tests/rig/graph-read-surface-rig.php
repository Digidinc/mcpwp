<?php
/**
 * Real-MySQL rig check for the site graph read surface (#943 S2).
 *
 * Not a PHPUnit test: the unit double cannot execute SQL, so this script
 * checks the SQL semantics against a real database. Run it on a THROWAWAY
 * WordPress install whose graph has been backfilled:
 *
 *   wp eval-file wp-content/plugins/mcpwp/tests/rig/graph-read-surface-rig.php
 *
 * Every check compares Mcpwp_Graph_Reader against an independent brute force:
 * a full-table PHP scan of the graph tables plus get_post() for visibility.
 * Nothing in the brute force reuses the reader's SQL. It prints one line per
 * check and a final JSON summary, and exits non-zero on any failure.
 *
 * The same visibility rules the reader documents are re-derived here:
 *  - a post node is visible to a read key iff its live post is public;
 *  - an inbound link counts iff its source is site-level, or a live public
 *    post (and, for content edges, not password-protected).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with wp eval-file.\n" );
	exit( 1 );
}

global $wpdb;

$mcpwp_rig = array(
	'pass'   => 0,
	'fail'   => 0,
	'checks' => array(),
);

$check = static function ( $name, $ok, $detail = '' ) use ( &$mcpwp_rig ) {
	$mcpwp_rig[ $ok ? 'pass' : 'fail' ]++;
	$mcpwp_rig['checks'][] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? " ({$detail})" : '' );
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? " ({$detail})" : '' ) . "\n";
};

$nodes_t   = Mcpwp_Graph_Builder::nodes_table();
$edges_t   = Mcpwp_Graph_Builder::edges_table();
$signals_t = Mcpwp_Graph_Builder::signals_table();

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names, rig only.
$all_nodes   = $wpdb->get_results( "SELECT * FROM {$nodes_t} ORDER BY id", ARRAY_A );
$all_edges   = $wpdb->get_results( "SELECT * FROM {$edges_t} ORDER BY id", ARRAY_A );
$all_signals = $wpdb->get_results( "SELECT * FROM {$signals_t}", ARRAY_A );
// phpcs:enable

$node_by_key = array();
foreach ( $all_nodes as $n ) {
	$node_by_key[ $n['node_key'] ] = $n;
}

// ---------------------------------------------------------------------------
// Brute-force model
// ---------------------------------------------------------------------------

$post_of = static function ( $key ) {
	if ( 0 !== strpos( $key, 'post:' ) ) {
		return null;
	}
	$post = get_post( (int) substr( $key, 5 ) );
	return $post ? $post : null;
};
$is_public = static function ( $status ) {
	$obj = get_post_status_object( $status );
	return $obj && ! empty( $obj->public );
};
$visible = static function ( $key, $priv ) use ( $post_of, $is_public ) {
	if ( 0 !== strpos( $key, 'post:' ) ) {
		return true;
	}
	$post = $post_of( $key );
	return $post && ( $priv || $is_public( $post->post_status ) );
};
$protected = static function ( $key ) use ( $post_of ) {
	$post = $post_of( $key );
	return $post && '' !== (string) $post->post_password;
};
$live_source = static function ( $edge ) use ( $visible, $protected ) {
	if ( ! $visible( $edge['from_key'], false ) ) {
		return false;
	}
	return ! ( in_array( $edge['type'], array( 'links_to', 'uses_media' ), true ) && $protected( $edge['from_key'] ) );
};
$inbound = array();
$has_links_to_in = array();
$outbound_types  = array();
foreach ( $all_edges as $e ) {
	if ( $live_source( $e ) ) {
		$inbound[ $e['to_key'] ] = ( $inbound[ $e['to_key'] ] ?? 0 ) + 1;
		if ( 'links_to' === $e['type'] ) {
			$has_links_to_in[ $e['to_key'] ] = true;
		}
	}
	$outbound_types[ $e['from_key'] ][ $e['type'] ] = true;
}

/** Brute-force filter over all nodes. */
$brute = static function ( callable $pred, $priv, $status = 'publish' ) use ( $all_nodes, $visible ) {
	$out = array();
	foreach ( $all_nodes as $n ) {
		if ( ! $visible( $n['node_key'], $priv ) ) {
			continue;
		}
		$is_post = 0 === strpos( $n['node_key'], 'post:' );
		$eff     = $priv ? $status : 'publish';
		if ( 'any' !== $eff && $is_post && $n['status'] !== $eff ) {
			continue;
		}
		if ( $pred( $n ) ) {
			$out[] = $n['node_key'];
		}
	}
	sort( $out );
	return $out;
};

/** Paginate a reader query to the end; returns [keys in order, pages, error]. */
$collect = static function ( array $args, $priv, $page_size = 7 ) {
	$keys   = array();
	$cursor = null;
	$pages  = 0;
	do {
		$a = $args + array( 'limit' => $page_size );
		if ( null !== $cursor ) {
			$a['cursor'] = $cursor;
		}
		$r = Mcpwp_Graph_Reader::query( $a, $priv );
		if ( is_wp_error( $r ) ) {
			return array( $keys, $pages, $r->get_error_code() );
		}
		foreach ( $r['results'] as $row ) {
			$keys[] = $row;
		}
		$cursor = $r['next_cursor'];
		$pages++;
	} while ( null !== $cursor && $pages < 500 );
	return array( $keys, $pages, null );
};
$keys_of = static function ( array $rows ) {
	$k = array_column( $rows, 'node_key' );
	sort( $k );
	return $k;
};

$compare = static function ( $name, array $args, callable $pred, $priv = false, $status = 'publish' ) use ( $collect, $brute, $keys_of, $check ) {
	list( $rows, $pages, $err ) = $collect( $args, $priv );
	if ( null !== $err ) {
		$check( $name, false, "error {$err}" );
		return;
	}
	$got  = $keys_of( $rows );
	$want = $brute( $pred, $priv, $status );
	$dups = count( $got ) - count( array_unique( $got ) );
	$check( $name, $got === $want && 0 === $dups, sprintf( 'reader=%d brute=%d pages=%d dups=%d', count( $got ), count( $want ), $pages, $dups ) );
};

$metric_sum = static function ( $key, $metric, $days, $source = null ) use ( $all_signals ) {
	$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
	$sum   = 0.0;
	foreach ( $all_signals as $s ) {
		if ( $s['node_key'] === $key && $s['metric'] === $metric && $s['period_end'] >= $since && ( null === $source || $s['source'] === $source ) ) {
			$sum += (float) $s['value'];
		}
	}
	return $sum;
};
$has_metric = static function ( $key, $metric ) use ( $all_signals ) {
	foreach ( $all_signals as $s ) {
		if ( $s['node_key'] === $key && $s['metric'] === $metric ) {
			return true;
		}
	}
	return false;
};

// ---------------------------------------------------------------------------
// 1. Orphans: reader == brute force over tables == brute force from content
// ---------------------------------------------------------------------------

$orphan_pred = static function ( $n ) use ( $has_links_to_in ) {
	return 0 === strpos( $n['node_key'], 'post:' ) && 'publish' === $n['status'] && empty( $has_links_to_in[ $n['node_key'] ] );
};
$compare( 'orphan (read key, paginated)', array( 'orphan' => true ), $orphan_pred, false );
$compare( 'orphan (write key, paginated)', array( 'orphan' => true ), $orphan_pred, true );

// Fully independent: parse live content of every public, unprotected post.
$linked = array();
$types  = Mcpwp_Graph_Builder::covered_post_types();
foreach ( get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => -1, 'has_password' => false ) ) as $p ) {
	if ( preg_match_all( '/[?&]p=(\d+)/', $p->post_content, $m ) ) {
		foreach ( $m[1] as $id ) {
			if ( (int) $id !== (int) $p->ID ) {
				$linked[ (int) $id ] = true;
			}
		}
	}
}
$content_orphans = 0;
foreach ( get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	if ( empty( $linked[ (int) $id ] ) ) {
		$content_orphans++;
	}
}
$reader_count = Mcpwp_Graph_Reader::count_orphans( false );
$check( 'orphan count == brute force parsed from live post content', $reader_count === $content_orphans, "reader={$reader_count} content={$content_orphans}" );
$summary = Mcpwp_Graph_Reader::resource_summary( false );
$check( 'resource orphans_total == count_orphans', $summary['orphans_total'] === $reader_count, "summary={$summary['orphans_total']}" );

// ---------------------------------------------------------------------------
// 2. Every filter
// ---------------------------------------------------------------------------

$compare( 'no filter (read)', array(), static function () {
	return true;
}, false );
$compare( 'kind=page', array( 'kind' => array( 'page' ) ), static function ( $n ) {
	return 'page' === $n['kind'];
}, false );
$compare( 'kind=term (status does not exclude non-post nodes)', array( 'kind' => 'term' ), static function ( $n ) {
	return 'term' === $n['kind'];
}, false );
foreach ( array( 0, 1, 2, 3, 5 ) as $k ) {
	$compare( "min_inbound={$k}", array( 'min_inbound' => $k ), static function ( $n ) use ( $inbound, $k ) {
		return ( $inbound[ $n['node_key'] ] ?? 0 ) >= $k;
	}, false );
	$compare( "max_inbound={$k}", array( 'max_inbound' => $k ), static function ( $n ) use ( $inbound, $k ) {
		return ( $inbound[ $n['node_key'] ] ?? 0 ) <= $k;
	}, false );
}
$compare( 'min_inbound=1 & max_inbound=2', array( 'min_inbound' => 1, 'max_inbound' => 2 ), static function ( $n ) use ( $inbound ) {
	$c = $inbound[ $n['node_key'] ] ?? 0;
	return $c >= 1 && $c <= 2;
}, false );
foreach ( array( 'clicks', 'pageviews', 'nosuchmetric' ) as $metric ) {
	$compare( "has_metric={$metric}", array( 'has_metric' => $metric ), static function ( $n ) use ( $has_metric, $metric ) {
		return $has_metric( $n['node_key'], $metric );
	}, false );
}
foreach ( array(
	array( 'clicks', 'gt', 50, 28, null ),
	array( 'clicks', 'lt', 20, 7, 'gsc' ),
	array( 'impressions', 'gt', 3000, 40, 'gsc' ),
	array( 'clicks', 'gt', 1, 28, 'posthog' ),
	array( 'pageviews', 'lt', 100, 28, null ),
) as $mf ) {
	list( $name, $op, $value, $days, $source ) = $mf;
	$args = array( 'metric' => array_filter( array( 'name' => $name, 'op' => $op, 'value' => $value, 'window_days' => $days, 'source' => $source ) ) );
	$compare( "metric {$name} {$op} {$value} over {$days}d" . ( $source ? " source={$source}" : '' ), $args, static function ( $n ) use ( $metric_sum, $name, $op, $value, $days, $source ) {
		$sum = $metric_sum( $n['node_key'], $name, $days, $source );
		return 'gt' === $op ? $sum > $value : $sum < $value;
	}, false );
}
foreach ( array( 'links_to', 'in_term', 'targets_query' ) as $type ) {
	$compare( "no_edge_type={$type}", array( 'no_edge_type' => $type ), static function ( $n ) use ( $outbound_types, $type, $protected ) {
		if ( in_array( $type, array( 'links_to', 'uses_media' ), true ) && $protected( $n['node_key'] ) ) {
			return true; // A protected post's content edges do not exist for a read key.
		}
		return empty( $outbound_types[ $n['node_key'] ][ $type ] );
	}, false );
}
$cut = gmdate( 'Y-m-d H:i:s', time() - 60 );
$compare( 'modified_before=now-60s', array( 'modified_before' => str_replace( ' ', 'T', $cut ) . 'Z' ), static function ( $n ) use ( $post_of, $cut ) {
	$p = $post_of( $n['node_key'] );
	return $p && $p->post_modified_gmt < $cut;
}, false );
$compare( 'modified_before=2000-01-01', array( 'modified_before' => '2000-01-01' ), static function () {
	return false;
}, false );
foreach ( array( 3, 8, 1000 ) as $wc ) {
	$compare( "word_count_lt={$wc}", array( 'word_count_lt' => $wc ), static function ( $n ) use ( $wc, $protected ) {
		return ! $protected( $n['node_key'] ) && (int) $n['word_count'] < $wc;
	}, false );
}
$compare( 'status=draft (write key)', array( 'status' => 'draft' ), static function () {
	return true;
}, true, 'draft' );
$compare( 'status=any (write key)', array( 'status' => 'any' ), static function () {
	return true;
}, true, 'any' );
$compare( 'status=draft (read key, clamped to publish)', array( 'status' => 'draft' ), static function () {
	return true;
}, false );
$compare( 'status=trash (write key)', array( 'status' => 'trash' ), static function () {
	return true;
}, true, 'trash' );
$compare( 'combined: kind=post, orphan, word_count_lt=1000, has_metric=clicks', array( 'kind' => 'post', 'orphan' => true, 'word_count_lt' => 1000, 'has_metric' => 'clicks' ), static function ( $n ) use ( $orphan_pred, $has_metric, $protected ) {
	return 'post' === $n['kind'] && $orphan_pred( $n ) && ! $protected( $n['node_key'] ) && (int) $n['word_count'] < 1000 && $has_metric( $n['node_key'], 'clicks' );
}, false );

// ---------------------------------------------------------------------------
// 3. Keyset pagination in every order mode: complete, no duplicates, sorted.
// ---------------------------------------------------------------------------

foreach ( array( 'label', 'modified', 'inbound', 'clicks' ) as $order_by ) {
	foreach ( array( 'asc', 'desc' ) as $dir ) {
		foreach ( array( false, true ) as $priv ) {
			list( $rows, $pages, $err ) = $collect( array( 'order_by' => $order_by, 'order_dir' => $dir, 'status' => $priv ? 'any' : '' ), $priv, 9 );
			$got   = $keys_of( $rows );
			$want  = $brute( static function () {
				return true;
			}, $priv, $priv ? 'any' : 'publish' );
			$dups  = count( $got ) - count( array_unique( $got ) );
			$label = sprintf( 'paginate order_by=%s %s %s', $order_by, $dir, $priv ? 'write' : 'read' );
			$sorted = true;
			if ( in_array( $order_by, array( 'inbound', 'clicks' ), true ) ) {
				$vals = array_column( $rows, 'sort_value' );
				for ( $i = 1; $i < count( $vals ); $i++ ) {
					if ( 'asc' === $dir ? $vals[ $i ] < $vals[ $i - 1 ] : $vals[ $i ] > $vals[ $i - 1 ] ) {
						$sorted = false;
					}
				}
			}
			$check( $label, null === $err && $got === $want && 0 === $dups && $sorted, sprintf( 'rows=%d want=%d pages=%d dups=%d sorted=%s%s', count( $got ), count( $want ), $pages, $dups, $sorted ? 'y' : 'n', $err ? " err={$err}" : '' ) );
		}
	}
}

// ---------------------------------------------------------------------------
// 4. Visibility sweep: every node through every surface, read key.
// ---------------------------------------------------------------------------

$leaks  = 0;
$hidden = array();
foreach ( $all_nodes as $n ) {
	if ( ! $visible( $n['node_key'], false ) ) {
		$hidden[ $n['node_key'] ] = (string) $n['label'];
	}
}
$scan = static function ( $payload ) use ( $hidden ) {
	$json = wp_json_encode( $payload );
	$hits = 0;
	foreach ( $hidden as $key => $label ) {
		if ( false !== strpos( $json, '"' . $key . '"' ) || ( '' !== $label && false !== strpos( $json, wp_json_encode( $label ) ) ) ) {
			$hits++;
		}
	}
	return $hits;
};
foreach ( $all_nodes as $n ) {
	$node = Mcpwp_Graph_Reader::get_node( $n['node_key'], false );
	if ( isset( $hidden[ $n['node_key'] ] ) ) {
		$leaks += null === $node ? 0 : 1;
		continue;
	}
	$leaks += $scan( $node );
	foreach ( array( 'out', 'in', 'both' ) as $dir ) {
		$leaks += $scan( Mcpwp_Graph_Reader::get_neighbors( array( 'node_key' => $n['node_key'], 'direction' => $dir, 'depth' => 2, 'limit' => 100 ), false ) );
	}
}
list( $all_rows ) = $collect( array( 'status' => 'any' ), false, 200 );
$leaks          += $scan( $all_rows );
$leaks          += $scan( Mcpwp_Graph_Reader::resource_summary( false ) );
$check( 'read key: no hidden node key or title on node/neighbors(depth 2, all directions)/query/summary for all ' . count( $all_nodes ) . ' nodes', 0 === $leaks, count( $hidden ) . " hidden nodes, {$leaks} leaks" );

$priv_node = Mcpwp_Graph_Reader::get_node( (string) array_key_first( $hidden ), true );
$check( 'write key: hidden node readable (positive control)', is_array( $priv_node ), (string) array_key_first( $hidden ) );

$pw_key = null;
foreach ( $all_nodes as $n ) {
	if ( $protected( $n['node_key'] ) && $visible( $n['node_key'], false ) ) {
		$pw_key = $n['node_key'];
		break;
	}
}
if ( null !== $pw_key ) {
	$pw  = Mcpwp_Graph_Reader::get_node( $pw_key, false );
	$nb  = Mcpwp_Graph_Reader::get_neighbors( array( 'node_key' => $pw_key, 'direction' => 'out' ), false );
	$bad = array_filter( $nb['edges'], static function ( $e ) {
		return in_array( $e['type'], array( 'links_to', 'uses_media' ), true );
	} );
	$check( "password-protected {$pw_key}: word_count null, no content edges (read key)", null === $pw['word_count'] && empty( $bad ) && empty( $pw['edges']['outbound']->links_to ?? ( is_array( $pw['edges']['outbound'] ) ? ( $pw['edges']['outbound']['links_to'] ?? null ) : null ) ) );
}

// Stale node: flip a published post to draft WITHOUT letting the worker run.
$stale_key = null;
foreach ( $all_nodes as $n ) {
	if ( 0 === strpos( $n['node_key'], 'post:' ) && 'publish' === $n['status'] && ! $protected( $n['node_key'] ) && $visible( $n['node_key'], false ) ) {
		$stale_key = $n['node_key'];
		break;
	}
}
$stale_id = (int) substr( $stale_key, 5 );
$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $stale_id ) ); // Direct: no hook, node keeps 'publish'.
clean_post_cache( $stale_id );
$stale_node = Mcpwp_Graph_Reader::get_node( $stale_key, false );
list( $stale_rows ) = $collect( array(), false, 200 );
$check( "stale node {$stale_key} (stored publish, live draft) hidden from read key", null === $stale_node && ! in_array( $stale_key, array_column( $stale_rows, 'node_key' ), true ) );
$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $stale_id ) );
clean_post_cache( $stale_id );

// ---------------------------------------------------------------------------
// 5. Injection attempts against real MySQL.
// ---------------------------------------------------------------------------

$tables_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$nodes_t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$payloads      = array(
	array( 'kind' => array( "page') OR 1=1 -- " ) ),
	array( 'status' => "publish' OR '1'='1" ),
	array( 'has_metric' => "x' OR '1'='1" ),
	array( 'no_edge_type' => "links_to') OR ('1'='1" ),
	array( 'order_by' => '(SELECT user_pass FROM wp_users LIMIT 1)' ),
	array( 'order_by' => 'label; DROP TABLE wp_posts' ),
	array( 'order_dir' => 'ASC, SLEEP(3)' ),
	array( 'metric' => array( 'name' => 'clicks', 'op' => '> 0 OR 1=1 OR 0 >', 'value' => 1 ) ),
	array( 'metric' => array( 'name' => "clicks' OR 1=1 -- ", 'op' => 'gt', 'value' => 1 ) ),
	array( 'metric' => array( 'name' => 'clicks', 'op' => 'gt', 'value' => '1 OR 1=1' ) ),
	array( 'min_inbound' => '1 OR 1=1' ),
	array( 'word_count_lt' => '10; DELETE FROM wp_posts' ),
	array( 'modified_before' => "2026-01-01' OR '1'='1" ),
	array( 'cursor' => "eyJpIjowfQ.' OR 1=1 -- " ),
);
$refused = 0;
foreach ( $payloads as $p ) {
	$r = Mcpwp_Graph_Reader::query( $p, false );
	if ( is_wp_error( $r ) && 400 === ( $r->get_error_data()['status'] ?? 0 ) ) {
		$refused++;
	}
}
$wpdb->last_error = '';
$literal          = Mcpwp_Graph_Reader::get_node( "post:1' OR '1'='1", false );
$check( 'injection: every filter payload refused with 400', count( $payloads ) === $refused, "{$refused}/" . count( $payloads ) );
$check( 'injection: node_key payload is a bound literal (no row, no SQL error)', null === $literal && '' === $wpdb->last_error );
$check( 'injection: tables intact', $tables_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$nodes_t}" ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) > 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Labels with quotes and % survive keyset pagination (exact bound values).
$odd = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => "O'Brien 100% %s \\ label", 'post_content' => 'x' ) );
Mcpwp_Graph_Builder::rebuild_node_safely( 'post:' . $odd );
list( $rows_odd, $pages_odd, $err_odd ) = $collect( array( 'order_by' => 'label' ), false, 3 );
$check( "label with quote/percent/backslash paginates cleanly", null === $err_odd && in_array( 'post:' . $odd, array_column( $rows_odd, 'node_key' ), true ) && count( $rows_odd ) === count( array_unique( array_column( $rows_odd, 'node_key' ) ) ), 'pages=' . $pages_odd );
wp_delete_post( $odd, true );
Mcpwp_Graph_Builder::rebuild_node_safely( 'post:' . $odd );

// ---------------------------------------------------------------------------
// 6. Size caps
// ---------------------------------------------------------------------------

$summary_bytes = strlen( wp_json_encode( Mcpwp_Graph_Reader::resource_summary( true ) ) );
$check( 'resource summary under RESOURCE_MAX_BYTES (~<2k tokens)', $summary_bytes <= Mcpwp_Graph_Reader::RESOURCE_MAX_BYTES, "{$summary_bytes} bytes" );

$wpdb->last_error = '';
echo wp_json_encode(
	array(
		'pass'           => $mcpwp_rig['pass'],
		'fail'           => $mcpwp_rig['fail'],
		'nodes'          => count( $all_nodes ),
		'edges'          => count( $all_edges ),
		'signals'        => count( $all_signals ),
		'hidden_nodes'   => count( $hidden ),
		'orphans_read'   => $reader_count,
		'summary_bytes'  => $summary_bytes,
	)
) . "\n";

if ( $mcpwp_rig['fail'] > 0 ) {
	exit( 1 );
}
