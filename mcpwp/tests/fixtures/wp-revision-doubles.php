<?php
/**
 * WordPress revision API doubles for the Elementor revision tests (#820).
 *
 * Deliberately NOT named *Test.php and loaded with require_once from inside a
 * @runInSeparateProcess test only: declaring these functions in the shared run
 * would make every Elementor write in the rest of the suite start creating
 * revision posts in the post double, which none of those tests expect.
 *
 * The behaviour mirrors wp-includes/revision.php (WordPress 7.0) where the
 * helper depends on it:
 *   - wp_save_post_revision() refuses when revisions are off for the post,
 *     compares post fields with the latest revision unless the
 *     wp_save_post_revision_check_for_changes filter says not to, fires
 *     _wp_put_post_revision, and trims the oldest revisions past the limit.
 *   - update_metadata() writes to the id it is given — unlike
 *     update_post_meta(), which core redirects from a revision to its parent.
 *
 * Knobs: $GLOBALS['mcpwp_test_revisions_to_keep'] (int, default -1 = keep all).
 *
 * @package MCPWP
 */

if ( ! isset( $GLOBALS['mcpwp_test_revision_next_id'] ) ) {
	$GLOBALS['mcpwp_test_revision_next_id'] = 9000;
}

function wp_revisions_to_keep( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );

	if ( ! $post || ! post_type_supports( $post->post_type, 'revisions' ) ) {
		return 0;
	}

	return isset( $GLOBALS['mcpwp_test_revisions_to_keep'] ) ? (int) $GLOBALS['mcpwp_test_revisions_to_keep'] : -1;
}

function wp_revisions_enabled( $post ) {
	return 0 !== wp_revisions_to_keep( $post );
}

function wp_is_post_revision( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );

	return ( $post && 'revision' === $post->post_type ) ? (int) $post->post_parent : false;
}

function wp_get_post_revisions( $post = 0, $args = null ) {
	$post = is_object( $post ) ? $post : get_post( $post );
	$args = is_array( $args ) ? $args : array();

	if ( ! $post ) {
		return array();
	}

	if ( ( ! isset( $args['check_enabled'] ) || $args['check_enabled'] ) && ! wp_revisions_enabled( $post ) ) {
		return array();
	}

	$out = array();

	foreach ( $GLOBALS['_mcpwp_test_posts'] as $id => $candidate ) {
		if ( $candidate && 'revision' === $candidate->post_type && (int) $candidate->post_parent === (int) $post->ID ) {
			$out[ (int) $id ] = $candidate;
		}
	}

	krsort( $out );

	$limit = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : -1;

	if ( $limit > 0 ) {
		$out = array_slice( $out, 0, $limit, true );
	}

	return $out;
}

function update_metadata( $meta_type, $object_id, $meta_key, $meta_value ) {
	$GLOBALS['_mcpwp_test_meta'][ (int) $object_id ][ $meta_key ] = wp_unslash( $meta_value );

	return true;
}

function delete_metadata( $meta_type, $object_id, $meta_key ) {
	unset( $GLOBALS['_mcpwp_test_meta'][ (int) $object_id ][ $meta_key ] );

	return true;
}

function metadata_exists( $meta_type, $object_id, $meta_key ) {
	return isset( $GLOBALS['_mcpwp_test_meta'][ (int) $object_id ] )
		&& array_key_exists( $meta_key, $GLOBALS['_mcpwp_test_meta'][ (int) $object_id ] );
}

function wp_delete_post_revision( $revision ) {
	$revision = is_object( $revision ) ? $revision : get_post( $revision );

	if ( ! $revision || 'revision' !== $revision->post_type ) {
		return false;
	}

	unset( $GLOBALS['_mcpwp_test_posts'][ (int) $revision->ID ], $GLOBALS['_mcpwp_test_meta'][ (int) $revision->ID ] );
	$GLOBALS['mcpwp_test_deleted_revisions'][] = (int) $revision->ID;

	return $revision;
}

function wp_save_post_revision( $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post || 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
		return null;
	}

	if ( ! wp_revisions_enabled( $post ) ) {
		return null;
	}

	$revisions = wp_get_post_revisions( $post );
	$latest    = $revisions ? reset( $revisions ) : null;

	if ( $latest && apply_filters( 'wp_save_post_revision_check_for_changes', true, $latest, $post ) ) {
		$changed = false;

		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			if ( (string) $post->$field !== (string) $latest->$field ) {
				$changed = true;
			}
		}

		if ( ! $changed ) {
			return null;
		}
	}

	$id = ++$GLOBALS['mcpwp_test_revision_next_id'];

	$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
		array(
			'ID'            => $id,
			'post_type'     => 'revision',
			'post_parent'   => (int) $post->ID,
			'post_name'     => $post->ID . '-revision-v1',
			'post_status'   => 'inherit',
			'post_title'    => $post->post_title,
			'post_content'  => $post->post_content,
			'post_excerpt'  => $post->post_excerpt,
			'post_author'   => (int) get_current_user_id(),
			'post_date'     => '2026-09-10 12:00:' . sprintf( '%02d', $id % 60 ),
			'post_date_gmt' => '2026-09-10 12:00:' . sprintf( '%02d', $id % 60 ),
		)
	);

	if ( function_exists( 'do_action' ) ) {
		do_action( '_wp_put_post_revision', $id, (int) $post->ID );
	}

	$keep = wp_revisions_to_keep( $post );

	if ( $keep >= 0 ) {
		$all = wp_get_post_revisions( $post, array( 'check_enabled' => false ) );
		ksort( $all );

		while ( count( $all ) > $keep ) {
			$oldest = array_shift( $all );
			wp_delete_post_revision( $oldest );
		}
	}

	return $id;
}
