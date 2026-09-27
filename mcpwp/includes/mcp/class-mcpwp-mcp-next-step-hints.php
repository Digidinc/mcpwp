<?php
/**
 * Next-step hints for successful tools/call results (#912).
 *
 * Anthropic's tool-use guidance found that surfacing a suggested next call
 * after a workflow step measurably improves multi-step agent accuracy. This
 * class is the single map of "after tool X succeeds, Y is usually the next
 * useful call" — consulted from ONE chokepoint in Mcpwp_REST_MCP
 * (apply_next_step_hints(), called from conform_tool_call_result()) so a
 * fresh dispatch and an idempotent replay (#802) get the exact same hints
 * from the exact same tool_name + arguments + result, rather than each write
 * path growing its own copy.
 *
 * A hint is advisory only:
 * - It is appended as a short "Next: ..." line on the text content block,
 *   AND given machine-readable under result._meta['mcpwp/next'] as
 *   [{tool, why, arguments}, ...] — never inside structuredContent, which is
 *   validated against the tool's declared outputSchema (#906) and must not
 *   carry a third-party addition no schema describes.
 * - A hint naming a tool the caller cannot actually reach right now
 *   (category disabled by the site admin, or not in the API key's allowed
 *   categories) is dropped before it is shown — Mcpwp_REST_MCP filters with
 *   the same tool_currently_permitted() gate the idempotent-replay path
 *   already uses, so "next" never suggests a call that would just bounce.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static hint map + resolution for MCP next-step suggestions.
 */
class Mcpwp_MCP_Next_Step_Hints {

	/**
	 * Source tool name => list of hint specs. Each spec:
	 *   'tool'  => suggested next tool name
	 *   'why'   => short human reason, used in both the text line and _meta
	 *   'build' => key into build_args() describing how to derive the
	 *              suggested call's arguments from this call's own
	 *              arguments/result.
	 *
	 * Deliberately narrow: only tools whose next step is genuinely
	 * predictable from the call just made. A tool not listed here simply
	 * gets no hint — that is the default, not an omission to fix.
	 *
	 * @var array<string, array<int, array<string, string>>>
	 */
	const HINT_SOURCES = array(
		'wp_create_page'           => array(
			array(
				'tool'  => 'wp_set_elementor',
				'why'   => 'lay out the new page with Elementor',
				'build' => 'id_from_result_or_args',
			),
			array(
				'tool'  => 'wp_set_seo',
				'why'   => 'set SEO title/description before publishing',
				'build' => 'id_from_result_or_args',
			),
		),
		'wp_create_post'           => array(
			array(
				'tool'  => 'wp_set_seo',
				'why'   => 'set SEO title/description before publishing',
				'build' => 'id_from_result_or_args',
			),
		),
		'wp_set_elementor'         => array(
			array(
				'tool'  => 'wp_regenerate_elementor_css',
				'why'   => 'regenerate the cached CSS so the new layout renders correctly',
				'build' => 'id_from_result_or_args',
			),
			array(
				'tool'  => 'wp_get_rendered_html',
				'why'   => 'verify the saved layout renders as expected',
				'build' => 'id_from_result_or_args',
			),
		),
		'wp_build_page'            => array(
			array(
				'tool'  => 'wp_get_elementor_summary',
				'why'   => 'verify the generated section structure before further edits',
				'build' => 'id_from_result_or_args',
			),
			array(
				'tool'  => 'wp_set_seo',
				'why'   => 'set SEO title/description before publishing',
				'build' => 'id_from_result_or_args',
			),
		),
		'wp_edit_widget'           => array(
			array(
				'tool'  => 'wp_get_rendered_html',
				'why'   => 'confirm the widget change rendered as expected',
				'build' => 'id_from_result_or_args',
			),
		),
		'wp_add_section'           => array(
			array(
				'tool'  => 'wp_get_elementor_summary',
				'why'   => 'get the new element\'s ID for further edits',
				'build' => 'id_from_result_or_args',
			),
		),
		'wp_set_seo'               => array(
			array(
				'tool'  => 'wp_validate_seo_readiness',
				'why'   => 'run a pre-publish SEO check with the new metadata',
				'build' => 'id_from_result_or_args',
			),
		),
		'wp_upload_media_from_url' => array(
			array(
				'tool'  => 'wp_generate_alt_text',
				'why'   => 'generate accessible alt text since none was supplied',
				'build' => 'alt_text_for_uploaded_media',
			),
		),
		'wp_graph_query'           => array(
			array(
				'tool'  => 'wp_graph_node',
				'why'   => 'inspect the top match\'s edges, signals, and 28-day trend in detail',
				'build' => 'graph_node_from_first_result',
			),
		),
		'wp_onboard'               => array(
			array(
				'tool'  => 'wp_get_site_context',
				'why'   => 'read the full brand/style guide before writing or designing content',
				'build' => 'no_args',
			),
		),
	);

	/**
	 * Resolve the next-step hints for one tools/call result.
	 *
	 * @param string $tool_name Tool name as called.
	 * @param array  $arguments Arguments as the client sent them.
	 * @param array  $result    This call's own result data (JSON-decoded
	 *                          content, associative), or empty array when it
	 *                          could not be decoded.
	 * @return array<int, array{tool:string, why:string, arguments:array}>
	 */
	public static function for_tool( $tool_name, array $arguments, array $result ) {
		if ( ! isset( self::HINT_SOURCES[ $tool_name ] ) ) {
			return array();
		}

		$hints = array();
		foreach ( self::HINT_SOURCES[ $tool_name ] as $spec ) {
			$args = self::build_args( isset( $spec['build'] ) ? $spec['build'] : '', $arguments, $result );
			if ( null === $args ) {
				continue;
			}
			$hints[] = array(
				'tool'      => $spec['tool'],
				'why'       => $spec['why'],
				'arguments' => $args,
			);
		}
		return $hints;
	}

	/**
	 * Build the suggested call's arguments, or null when this call's own
	 * arguments/result do not carry enough to build one (never guess).
	 *
	 * @param string $builder   Builder key.
	 * @param array  $arguments This call's arguments.
	 * @param array  $result    This call's result data.
	 * @return array|null
	 */
	private static function build_args( $builder, array $arguments, array $result ) {
		switch ( $builder ) {
			case 'id_from_result_or_args':
				$id = self::extract_id( $arguments, $result );
				return null === $id ? null : array( 'id' => $id );

			case 'no_args':
				return array();

			case 'alt_text_for_uploaded_media':
				// Skip the hint when the caller already supplied alt text —
				// nothing to fix.
				if ( ! empty( $arguments['alt'] ) ) {
					return null;
				}
				$id = isset( $result['id'] ) && is_scalar( $result['id'] ) ? $result['id'] : null;
				return null === $id ? null : array( 'attachment_id' => $id );

			case 'graph_node_from_first_result':
				if ( empty( $result['results'][0]['node_key'] ) || ! is_scalar( $result['results'][0]['node_key'] ) ) {
					return null;
				}
				return array( 'node_key' => (string) $result['results'][0]['node_key'] );

			default:
				return null;
		}
	}

	/**
	 * The id a hint should target: prefer the result's own `id` (a newly
	 * created page/post), fall back to the call's own `id` argument (an
	 * edit tool that already targeted one).
	 *
	 * @param array $arguments Call arguments.
	 * @param array $result    Call result data.
	 * @return int|string|null
	 */
	private static function extract_id( array $arguments, array $result ) {
		if ( isset( $result['id'] ) && is_scalar( $result['id'] ) ) {
			return $result['id'];
		}
		if ( isset( $arguments['id'] ) && is_scalar( $arguments['id'] ) ) {
			return $arguments['id'];
		}
		return null;
	}
}
