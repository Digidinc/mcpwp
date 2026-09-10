<?php
/**
 * Undo for Elementor writes, on WordPress revisions (#820).
 *
 * Wrap, do not build: WordPress already keeps post history as revisions, and
 * Elementor already copies its document meta (`_elementor_data`,
 * `_elementor_page_settings`, ...) onto every revision WordPress makes. A
 * second snapshot store would drift from both.
 *
 * What was missing is that MCPWP's writes never produced a revision. Observed
 * on WordPress 7.0.2 + Elementor 4.2.4 (2026-09-10), over HTTP with an API key:
 * set, edit-widget, edit-section, add/replace/remove-section, patch,
 * find/replace, template create/update/apply, part apply, kit CSS and kit CSS
 * blocks all left the page, the template and the kit with zero revisions.
 *
 *   - API requests run as the `mcpwp_bot` user. Its role has no
 *     `edit_published_pages`, so on a published page Elementor's
 *     Document::is_editable_by_current_user() is false and Document::save()
 *     returns false before saving anything — no plain-text update, no
 *     `post_updated`, no revision. The data is stored by MCPWP's raw
 *     `update_post_meta()` overwrite, which WordPress does not revision.
 *   - Where Document::save() does run (a draft, an editor-capable user),
 *     WordPress makes a revision and Elementor copies ITS copy of the tree
 *     onto it — the tree from before MCPWP's overwrite, which the page does
 *     not hold.
 *
 * So each MCPWP Elementor write calls begin() before it stores anything and
 * commit() after, and between them this class guarantees two things with
 * WordPress's own revision API: the state being replaced is a revision (the
 * newest revision already, or a checkpoint made now), and exactly one revision
 * holds the state that was stored (a revision made during the write is
 * corrected and extras removed; otherwise one is made). A write that changed
 * nothing leaves no new revision.
 *
 * WP_POST_REVISIONS and the wp_revisions_to_keep filter are respected, and
 * reported: "revisions are off" must never look like "this page has no
 * history".
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revision history for Elementor documents.
 */
final class Mcpwp_Elementor_Revisions {

	const DATA_KEY     = '_elementor_data';
	const SETTINGS_KEY = '_elementor_page_settings';

	/**
	 * Post types the Elementor write path accepts (Mcpwp_Elementor_Validator_Trait::validate_post()).
	 */
	const POST_TYPES = array( 'page', 'post', 'elementor_library', 'elementor_snippet' );

	const DEFAULT_PER_PAGE = 20;
	const MAX_PER_PAGE     = 100;

	/**
	 * How many of the newest revisions a commit inspects for ones made during
	 * the write. A single write makes one or two; this is headroom.
	 */
	const RECENT_WINDOW = 25;

	/**
	 * Posts whose writes are recorded by an enclosing operation (a revert).
	 *
	 * Keyed by post id and set only by revert(), which releases it in a
	 * `finally`, so an early return inside an ordinary writer can never leave a
	 * post stuck unrecorded for the rest of the request.
	 *
	 * @var array<int,bool>
	 */
	private static $held = array();

	/**
	 * Whether WordPress's revision API is loaded.
	 *
	 * @return bool
	 */
	public static function api_available() {
		foreach ( array( 'wp_save_post_revision', 'wp_get_post_revisions', 'wp_revisions_to_keep', 'update_metadata', 'delete_metadata', 'wp_delete_post_revision' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether revisions are recorded for a post, and how many are kept.
	 *
	 * @param int $post_id Post id.
	 * @return array{enabled:bool,limit:int,reason:string|null} limit -1 = unlimited.
	 */
	public static function policy( $post_id ) {
		if ( ! self::api_available() ) {
			return array(
				'enabled' => false,
				'limit'   => 0,
				'reason'  => 'WordPress revision functions are not loaded, so no revision can be recorded.',
			);
		}

		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return array(
				'enabled' => false,
				'limit'   => 0,
				'reason'  => 'Post not found.',
			);
		}

		if ( function_exists( 'post_type_supports' ) && ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return array(
				'enabled' => false,
				'limit'   => 0,
				'reason'  => sprintf( "Post type '%s' does not support revisions, so no history is recorded for it.", $post->post_type ),
			);
		}

		$limit = (int) wp_revisions_to_keep( $post );

		if ( 0 === $limit ) {
			return array(
				'enabled' => false,
				'limit'   => 0,
				'reason'  => 'Revisions are disabled on this site (WP_POST_REVISIONS is false or 0, or a wp_revisions_to_keep filter returns 0), so no history is being recorded.',
			);
		}

		return array(
			'enabled' => true,
			'limit'   => $limit,
			'reason'  => null,
		);
	}

	/**
	 * Call before an Elementor write stores anything.
	 *
	 * @param int $post_id Post id.
	 * @return array Token for commit().
	 */
	public static function begin( $post_id ) {
		$post_id = (int) $post_id;

		if ( isset( self::$held[ $post_id ] ) ) {
			return array(
				'post_id' => $post_id,
				'nested'  => true,
			);
		}

		$token = array(
			'post_id'            => $post_id,
			'nested'             => false,
			'policy'             => self::policy( $post_id ),
			'before'             => null,
			'before_revision_id' => null,
			'max_revision_id'    => 0,
		);

		if ( ! $token['policy']['enabled'] ) {
			return $token;
		}

		$state       = self::state_of( $post_id );
		$fingerprint = self::fingerprint( $state );
		$latest      = self::latest_revision( $post_id );

		if ( $latest && self::fingerprint( self::state_of( $latest->ID ) ) === $fingerprint ) {
			$token['before_revision_id'] = (int) $latest->ID;
		} elseif ( self::has_state( $state ) ) {
			// Nothing in the history holds what the page holds now — it was
			// written by a path WordPress never revisioned. Keep it before it
			// is replaced.
			$checkpoint                  = self::create_revision( $post_id );
			$token['before_revision_id'] = $checkpoint ? $checkpoint : null;
		}

		$token['before']          = $fingerprint;
		$token['max_revision_id'] = self::max_revision_id( $post_id );

		return $token;
	}

	/**
	 * Call after an Elementor write, on every path that stored something.
	 *
	 * @param array $token From begin().
	 * @return array Report: revisions_enabled, revision_id (holds the stored
	 *               state), previous_revision_id (holds the replaced state —
	 *               the revert target that undoes this write), created, limit.
	 */
	public static function commit( array $token ) {
		if ( ! empty( $token['nested'] ) ) {
			return array(
				'nested' => true,
				'note'   => 'Recorded by the enclosing operation.',
			);
		}

		$policy = isset( $token['policy'] ) ? $token['policy'] : self::policy( isset( $token['post_id'] ) ? $token['post_id'] : 0 );

		if ( empty( $policy['enabled'] ) ) {
			return array(
				'revisions_enabled'    => false,
				'reason'               => $policy['reason'],
				'revision_id'          => null,
				'previous_revision_id' => null,
				'created'              => false,
			);
		}

		$post_id     = (int) $token['post_id'];
		$fingerprint = self::fingerprint( self::state_of( $post_id ) );

		$made = array();

		foreach ( self::revisions_of( $post_id, self::RECENT_WINDOW ) as $revision ) {
			if ( (int) $revision->ID > (int) $token['max_revision_id'] && ! self::is_autosave( $revision ) ) {
				$made[] = $revision;
			}
		}

		$report = array(
			'revisions_enabled'    => true,
			'limit'                => (int) $policy['limit'],
			'revision_id'          => null,
			'previous_revision_id' => $token['before_revision_id'],
			'created'              => false,
		);

		if ( $fingerprint === $token['before'] ) {
			// Nothing Elementor-visible changed. Anything made during the write
			// duplicates the revision that already holds this state.
			foreach ( $made as $revision ) {
				wp_delete_post_revision( $revision->ID );
			}

			$report['revision_id']          = $token['before_revision_id'];
			$report['previous_revision_id'] = null;
			$report['unchanged']            = true;

			return $report;
		}

		if ( ! empty( $made ) ) {
			// WordPress made a revision during the write (Elementor's own save
			// on a draft). It carries the tree from before MCPWP's overwrite;
			// make it hold what was actually stored, and keep only one.
			$keep = array_shift( $made );
			self::copy_state( $post_id, (int) $keep->ID );

			foreach ( $made as $revision ) {
				wp_delete_post_revision( $revision->ID );
			}

			$report['revision_id'] = (int) $keep->ID;
			$report['created']     = true;
		} else {
			$created = self::create_revision( $post_id );

			if ( ! $created ) {
				$report['error'] = 'WordPress did not record a revision for this write (wp_save_post_revision() returned nothing).';

				return $report;
			}

			$report['revision_id'] = $created;
			$report['created']     = true;
		}

		if ( $report['previous_revision_id'] && ! get_post( $report['previous_revision_id'] ) ) {
			$report['previous_revision_id'] = null;
			$report['note']                 = sprintf( 'The revision holding the replaced state was deleted by the revision limit (%d), so this write cannot be reverted.', (int) $policy['limit'] );
		}

		return $report;
	}

	/**
	 * Revisions of a post, described for a caller choosing a revert target.
	 *
	 * @param int   $post_id Post id.
	 * @param array $args    per_page (1-100), page.
	 * @return array|WP_Error
	 */
	public static function list_revisions( $post_id, array $args = array() ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return new WP_Error(
				'not_found',
				sprintf( 'Post ID %d not found or is not a supported type (page, post, elementor_library, elementor_snippet).', $post_id ),
				array( 'status' => 404 )
			);
		}

		if ( ! self::api_available() ) {
			return new WP_Error( 'revisions_unavailable', 'WordPress revision functions are not loaded.', array( 'status' => 501 ) );
		}

		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : self::DEFAULT_PER_PAGE;
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		$policy  = self::policy( $post_id );
		$all     = self::revisions_of( $post_id );
		$current = self::state_of( $post_id );
		$tree    = self::tree_of( $current['data'] );
		$print   = self::fingerprint( $current );

		$rows = array();

		foreach ( array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) as $revision ) {
			$rows[] = self::describe( $revision, $print );
		}

		$matching = null;

		foreach ( $rows as $row ) {
			if ( $row['matches_current'] ) {
				$matching = $row['id'];
				break;
			}
		}

		$response = array(
			'post_id'           => $post_id,
			'post_type'         => $post->post_type,
			'revisions_enabled' => $policy['enabled'],
			'limit'             => $policy['limit'],
			'total'             => count( $all ),
			'page'              => $page,
			'per_page'          => $per_page,
			'pages'             => (int) ceil( count( $all ) / $per_page ),
			'current'           => array(
				'has_elementor_data'   => self::has_data( $current ),
				'has_page_settings'    => self::has_settings( $current ),
				'sections'             => is_array( $tree ) ? count( $tree ) : null,
				'widgets'              => is_array( $tree ) ? self::count_widgets( $tree ) : null,
				'matching_revision_id' => $matching,
			),
			'revisions'         => $rows,
			'revert_with'       => 'wp_revert_elementor(id, revision_id) — pass dry_run=true first to see what would change.',
		);

		if ( ! $policy['enabled'] ) {
			$response['note'] = $policy['reason'] . ( empty( $all )
				? ' An empty list here means no history is being kept, not that this post has never changed.'
				: ' The revisions listed were recorded before that.' );
		} elseif ( $policy['limit'] > 0 ) {
			$response['note'] = sprintf(
				'WordPress keeps only the newest %d revisions of this post (WP_POST_REVISIONS or the wp_revisions_to_keep filter); older states are deleted as new ones are saved.',
				$policy['limit']
			);
		}

		return $response;
	}

	/**
	 * Restore a post's Elementor document from one of its revisions.
	 *
	 * The element tree goes back through the normal write path
	 * ($writer->set_elementor_data(): validation, id normalisation, CSS
	 * regeneration, render verdict). The state being replaced is kept as a
	 * revision first, so a revert can itself be reverted; for the same reason
	 * a revert is refused while revisions are disabled.
	 *
	 * @param object $writer      Exposes validate_post( $id ) and set_elementor_data( $id, $data, $dry_run ).
	 * @param int    $post_id     Post id.
	 * @param int    $revision_id Revision id.
	 * @param array  $options     dry_run (bool), convert (bool).
	 * @return array|WP_Error
	 */
	public static function revert( $writer, $post_id, $revision_id, array $options = array() ) {
		$post_id     = (int) $post_id;
		$revision_id = (int) $revision_id;
		$dry_run     = ! empty( $options['dry_run'] );
		$convert     = ! empty( $options['convert'] );

		if ( ! self::api_available() ) {
			return new WP_Error( 'revisions_unavailable', 'WordPress revision functions are not loaded.', array( 'status' => 501 ) );
		}

		$post = $writer->validate_post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$revision = $revision_id > 0 ? get_post( $revision_id ) : null;

		if ( ! $revision ) {
			return new WP_Error(
				'revision_not_found',
				sprintf( 'Revision %d does not exist. Use wp_list_elementor_revisions(id=%d) to see this post\'s revisions.', $revision_id, $post_id ),
				array( 'status' => 404 )
			);
		}

		if ( 'revision' !== $revision->post_type ) {
			return new WP_Error(
				'not_a_revision',
				sprintf( 'Post %d is a %s, not a revision.', $revision_id, $revision->post_type ),
				array( 'status' => 400 )
			);
		}

		if ( (int) $revision->post_parent !== $post_id ) {
			return new WP_Error(
				'revision_of_another_post',
				sprintf( 'Revision %d belongs to a different post, not to post %d.', $revision_id, $post_id ),
				array( 'status' => 400 )
			);
		}

		$target  = self::state_of( $revision_id );
		$current = self::state_of( $post_id );
		$is_kit  = 'kit' === get_post_meta( $post_id, '_elementor_template_type', true );

		if ( ! self::has_data( $target ) && ! ( $is_kit && self::has_settings( $target ) ) ) {
			return new WP_Error(
				'revision_has_no_elementor_data',
				sprintf( 'Revision %d carries no Elementor data — it was recorded before this post was built with Elementor, or by a save that did not copy Elementor\'s meta. Nothing was changed.', $revision_id ),
				array( 'status' => 422 )
			);
		}

		$target_tree  = null;
		$current_tree = self::tree_of( $current['data'] );

		if ( self::has_data( $target ) ) {
			$read = Mcpwp_Elementor_Stored_Tree::read( $target['data'] );

			if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $read ) || ! is_array( $read['elements'] ) ) {
				return new WP_Error(
					'revision_data_unusable',
					sprintf( 'Revision %d holds Elementor data that cannot be read as an element tree. Nothing was changed.', $revision_id ),
					array( 'status' => 422 )
				);
			}

			$target_tree = $read['elements'];
		}

		$data_changes     = null !== $target_tree && ! self::trees_equal( $target_tree, $current_tree );
		$settings_changes = self::settings_print( $target['settings'] ) !== self::settings_print( $current['settings'] );

		if ( ! $data_changes && ! $settings_changes ) {
			return new WP_Error(
				'revert_no_change',
				sprintf( 'Post %d already holds the Elementor data of revision %d. Nothing was written.', $post_id, $revision_id ),
				array( 'status' => 409 )
			);
		}

		$summary = array(
			'current'  => array(
				'sections' => is_array( $current_tree ) ? count( $current_tree ) : null,
				'widgets'  => is_array( $current_tree ) ? self::count_widgets( $current_tree ) : null,
			),
			'revision' => array_merge(
				array(
					'id'     => $revision_id,
					'date'   => (string) $revision->post_date,
					'author' => self::author( $revision ),
				),
				array(
					'sections' => is_array( $target_tree ) ? count( $target_tree ) : null,
					'widgets'  => is_array( $target_tree ) ? self::count_widgets( $target_tree ) : null,
				)
			),
		);

		$policy = self::policy( $post_id );

		if ( $dry_run ) {
			$result = array(
				'success'           => true,
				'dry_run'           => true,
				'post_id'           => $post_id,
				'revision_id'       => $revision_id,
				'would_change'      => array(
					'elementor_data' => $data_changes,
					'page_settings'  => $settings_changes,
				),
				'current'           => $summary['current'],
				'revision'          => $summary['revision'],
				'revisions_enabled' => $policy['enabled'],
			);

			if ( $data_changes ) {
				$result['top_level_elements'] = array(
					'added'   => array_values( array_diff( self::top_ids( $target_tree ), self::top_ids( $current_tree ) ) ),
					'removed' => array_values( array_diff( self::top_ids( $current_tree ), self::top_ids( $target_tree ) ) ),
				);

				$check = $writer->set_elementor_data( $post_id, array( 'elementor_data' => wp_json_encode( $target_tree ), 'convert' => $convert ), true );

				if ( is_wp_error( $check ) ) {
					return $check;
				}

				$result['validation'] = array(
					'warnings' => isset( $check['warnings'] ) ? $check['warnings'] : array(),
				);
			}

			if ( ! $policy['enabled'] ) {
				$result['warning'] = 'A real revert would be refused: ' . $policy['reason'] . ' Reverting would replace the current Elementor data with no revision of it to return to.';
			}

			return $result;
		}

		if ( ! $policy['enabled'] ) {
			return new WP_Error(
				'revisions_disabled',
				$policy['reason'] . ' Reverting would replace the current Elementor data with no revision of it to return to, so nothing was changed.',
				array( 'status' => 409 )
			);
		}

		$token = self::begin( $post_id );
		$write = null;

		self::$held[ $post_id ] = true;

		try {
			if ( $data_changes ) {
				$write = $writer->set_elementor_data( $post_id, array( 'elementor_data' => wp_json_encode( $target_tree ), 'convert' => $convert ), false );
			}

			if ( ! is_wp_error( $write ) && $settings_changes ) {
				self::restore_settings( $post_id, $target['settings'], $is_kit, $data_changes );
			}
		} finally {
			unset( self::$held[ $post_id ] );
		}

		$report = self::commit( $token );

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$after      = self::state_of( $post_id );
		$after_tree = self::tree_of( $after['data'] );

		$data_restored     = $data_changes ? self::trees_equal( $after_tree, $target_tree ) : null;
		$data_changed      = $data_changes ? ! self::trees_equal( $after_tree, $current_tree ) : false;
		$settings_restored = $settings_changes ? self::settings_print( $after['settings'] ) === self::settings_print( $target['settings'] ) : null;

		if ( ( $data_changes && ! $data_changed ) || ( ! $data_changes && ! $settings_restored ) ) {
			return new WP_Error(
				'revert_not_applied',
				sprintf( 'The write path reported success, but post %d does not hold revision %d\'s Elementor data afterwards. Treat the post as unchanged and read it back before retrying.', $post_id, $revision_id ),
				array(
					'status'   => 500,
					'revision' => $report,
				)
			);
		}

		$result = array(
			'success'                 => true,
			'post_id'                 => $post_id,
			'reverted_to_revision_id' => $revision_id,
			'restored'                => array(
				'elementor_data' => $data_changes ? true : null,
				'page_settings'  => $settings_changes ? (bool) $settings_restored : null,
			),
			'restored_exactly'        => false !== $data_restored && false !== $settings_restored,
			'current'                 => $summary['revision'],
			'replaced'                => $summary['current'],
			'revision'                => $report,
			'undo'                    => array(
				'tool'        => 'wp_revert_elementor',
				'id'          => $post_id,
				'revision_id' => $report['previous_revision_id'],
			),
			'message'                 => sprintf( 'Post %d restored from revision %d.', $post_id, $revision_id ),
		);

		$warnings = array();

		if ( false === $data_restored ) {
			$warnings[] = 'The write path adjusted the restored tree (validation fixes such as id or key normalisation), so the stored data is not byte-for-byte the revision. See write.warnings.';
		}

		if ( false === $settings_restored ) {
			$warnings[] = 'The element tree was restored, but the page settings could not be stored; the post keeps the settings it had.';
		}

		if ( is_array( $write ) ) {
			$result['write'] = array_intersect_key(
				$write,
				array_flip( array( 'render_verified', 'render_errors', 'css_regenerated', 'save_method', 'warnings', 'settings_check', 'renders_via_elementor' ) )
			);
		}

		if ( ! empty( $warnings ) ) {
			$result['warnings'] = $warnings;
		}

		return $result;
	}

	/**
	 * Write a revision's page settings back, exactly — including their absence.
	 *
	 * @param int        $post_id      Post id.
	 * @param mixed|null $settings     Settings from the revision, null when it had none.
	 * @param bool       $is_kit       Whether the post is the active kit's document.
	 * @param bool       $css_rebuilt  Whether the element write already rebuilt the post CSS.
	 */
	private static function restore_settings( $post_id, $settings, $is_kit, $css_rebuilt ) {
		if ( null === $settings ) {
			delete_post_meta( $post_id, self::SETTINGS_KEY );
		} else {
			update_post_meta( $post_id, self::SETTINGS_KEY, is_string( $settings ) ? maybe_unserialize( $settings ) : $settings );
		}

		if ( ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance ) ) {
			return;
		}

		try {
			if ( $is_kit && ! empty( \Elementor\Plugin::$instance->files_manager ) && method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
				// Kit CSS and globals are compiled into every page's CSS.
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} elseif ( ! $css_rebuilt && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
			}
		} catch ( \Throwable $e ) {
			// The settings are stored; a stale CSS file regenerates on the next
			// view or wp_regenerate_elementor_css. Not a reason to fail the revert.
			unset( $e );
		}
	}

	/**
	 * Elementor document state of a post or revision.
	 *
	 * @param int $id Post or revision id.
	 * @return array{data:mixed,settings:mixed} null where the meta row is absent.
	 */
	public static function state_of( $id ) {
		return array(
			'data'     => self::meta_row( $id, self::DATA_KEY ),
			'settings' => self::meta_row( $id, self::SETTINGS_KEY ),
		);
	}

	/**
	 * One meta value, or null when the row does not exist.
	 *
	 * get_post_meta() reads the id it is given, revisions included (only the
	 * write functions redirect a revision to its parent).
	 *
	 * @param int    $id  Post id.
	 * @param string $key Meta key.
	 * @return mixed|null
	 */
	private static function meta_row( $id, $key ) {
		$id = (int) $id;

		if ( function_exists( 'metadata_exists' ) ) {
			return metadata_exists( 'post', $id, $key ) ? get_post_meta( $id, $key, true ) : null;
		}

		$values = get_post_meta( $id, $key, false );

		return ( is_array( $values ) && ! empty( $values ) ) ? $values[0] : null;
	}

	private static function fingerprint( array $state ) {
		return md5( serialize( array( self::data_print( $state['data'] ), self::settings_print( $state['settings'] ) ) ) );
	}

	private static function data_print( $data ) {
		if ( null === $data ) {
			return null;
		}

		return is_string( $data ) ? $data : serialize( $data );
	}

	private static function settings_print( $settings ) {
		if ( null === $settings || '' === $settings || array() === $settings ) {
			return null;
		}

		return serialize( is_string( $settings ) ? maybe_unserialize( $settings ) : $settings );
	}

	private static function has_data( array $state ) {
		$data = $state['data'];

		return ( is_string( $data ) && '' !== trim( $data ) ) || ( is_array( $data ) && ! empty( $data ) );
	}

	private static function has_settings( array $state ) {
		return null !== self::settings_print( $state['settings'] );
	}

	private static function has_state( array $state ) {
		return self::has_data( $state ) || self::has_settings( $state );
	}

	/**
	 * @param mixed $data Stored `_elementor_data`.
	 * @return array|null Element list, or null when absent or unreadable.
	 */
	private static function tree_of( $data ) {
		if ( null === $data || ( is_string( $data ) && '' === trim( $data ) ) ) {
			return null;
		}

		$read = Mcpwp_Elementor_Stored_Tree::read( $data );

		return is_array( $read['elements'] ) ? $read['elements'] : null;
	}

	private static function trees_equal( $a, $b ) {
		if ( ! is_array( $a ) || ! is_array( $b ) ) {
			return $a === $b;
		}

		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	private static function count_widgets( array $elements ) {
		$count = 0;

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				++$count;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += self::count_widgets( $element['elements'] );
			}
		}

		return $count;
	}

	private static function top_ids( $tree ) {
		$ids = array();

		foreach ( is_array( $tree ) ? $tree : array() as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) ) {
				$ids[] = (string) $element['id'];
			}
		}

		return $ids;
	}

	private static function author( $revision ) {
		$id = (int) $revision->post_author;

		return array(
			'id'   => $id,
			'name' => ( $id && function_exists( 'get_the_author_meta' ) ) ? (string) get_the_author_meta( 'display_name', $id ) : '',
		);
	}

	private static function describe( $revision, $current_print ) {
		$state = self::state_of( $revision->ID );
		$tree  = self::tree_of( $state['data'] );

		return array(
			'id'                 => (int) $revision->ID,
			'date'               => (string) $revision->post_date,
			'date_gmt'           => (string) $revision->post_date_gmt,
			'author'             => self::author( $revision ),
			'type'               => self::is_autosave( $revision ) ? 'autosave' : 'revision',
			'has_elementor_data' => self::has_data( $state ),
			'has_page_settings'  => self::has_settings( $state ),
			'sections'           => is_array( $tree ) ? count( $tree ) : null,
			'widgets'            => is_array( $tree ) ? self::count_widgets( $tree ) : null,
			'matches_current'    => self::has_state( $state ) && self::fingerprint( $state ) === $current_print,
		);
	}

	private static function is_autosave( $revision ) {
		return false !== strpos( (string) $revision->post_name, 'autosave' );
	}

	/**
	 * Revisions of a post, newest first.
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Newest N, or -1 for all.
	 * @return array
	 */
	private static function revisions_of( $post_id, $limit = -1 ) {
		$args = array(
			'order'         => 'DESC',
			'orderby'       => 'ID',
			'check_enabled' => false,
		);

		if ( $limit > 0 ) {
			$args['posts_per_page'] = $limit;
		}

		$revisions = wp_get_post_revisions( (int) $post_id, $args );
		$revisions = is_array( $revisions ) ? array_values( $revisions ) : array();

		usort(
			$revisions,
			static function ( $a, $b ) {
				return (int) $b->ID - (int) $a->ID;
			}
		);

		return $revisions;
	}

	private static function latest_revision( $post_id ) {
		foreach ( self::revisions_of( $post_id, self::RECENT_WINDOW ) as $revision ) {
			if ( ! self::is_autosave( $revision ) ) {
				return $revision;
			}
		}

		return null;
	}

	private static function max_revision_id( $post_id ) {
		$revisions = self::revisions_of( $post_id, 1 );

		return empty( $revisions ) ? 0 : (int) $revisions[0]->ID;
	}

	/**
	 * Make a revision of the post as it is now, carrying its Elementor meta.
	 *
	 * @param int $post_id Post id.
	 * @return int Revision id, 0 when WordPress made none.
	 */
	private static function create_revision( $post_id ) {
		// wp_save_post_revision() compares only title, content and excerpt
		// with the newest revision; an Elementor write changes meta. Elementor's
		// own save path forces the same way (Revisions_Manager::handle_revision).
		$always = static function () {
			return false;
		};

		add_filter( 'wp_save_post_revision_check_for_changes', $always, PHP_INT_MAX );

		try {
			$revision_id = wp_save_post_revision( (int) $post_id );
		} finally {
			remove_filter( 'wp_save_post_revision_check_for_changes', $always, PHP_INT_MAX );
		}

		if ( ! $revision_id || is_wp_error( $revision_id ) ) {
			return 0;
		}

		self::copy_state( $post_id, (int) $revision_id );

		return (int) $revision_id;
	}

	/**
	 * Copy the document meta onto a revision, the way Elementor does.
	 *
	 * Mirrors Elementor\DB::copy_elementor_meta(): every `_elementor*` key plus
	 * `_wp_page_template`, through update_metadata() because update_post_meta()
	 * would redirect a revision id to its parent. Keys the post no longer has
	 * are removed from the revision, so it holds the post's state exactly.
	 *
	 * @param int $from Post id.
	 * @param int $to   Revision id.
	 */
	private static function copy_state( $from, $to ) {
		$source = get_post_meta( (int) $from );
		$source = is_array( $source ) ? $source : array();
		$copied = array();

		foreach ( $source as $key => $values ) {
			if ( ! self::is_document_key( $key ) ) {
				continue;
			}

			$value = ( is_array( $values ) && array_key_exists( 0, $values ) ) ? $values[0] : $values;
			$value = maybe_unserialize( $value );

			if ( self::DATA_KEY === $key ) {
				// The JSON escapes every quote; update_metadata() unslashes.
				$value = wp_slash( $value );
			}

			update_metadata( 'post', (int) $to, $key, $value );
			$copied[ $key ] = true;
		}

		$target = get_post_meta( (int) $to );

		foreach ( is_array( $target ) ? $target : array() as $key => $values ) {
			if ( self::is_document_key( $key ) && ! isset( $copied[ $key ] ) ) {
				delete_metadata( 'post', (int) $to, $key );
			}
		}
	}

	private static function is_document_key( $key ) {
		return 0 === strpos( (string) $key, '_elementor' ) || '_wp_page_template' === $key;
	}
}
