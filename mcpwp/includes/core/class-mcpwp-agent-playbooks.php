<?php
/**
 * Deterministic agent playbook contracts.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides concise, machine-readable playbooks for agent workflows.
 */
class Mcpwp_Agent_Playbooks {

	/**
	 * List available playbooks.
	 *
	 * @return array
	 */
	public static function get_all() {
		$playbooks = array();

		foreach ( self::definitions() as $name => $definition ) {
			$playbooks[] = array(
				'name'        => $name,
				'title'       => $definition['title'],
				'description' => $definition['description'],
				'risk_level'  => $definition['risk_level'],
				'mutates'     => $definition['mutates'],
			);
		}

		return $playbooks;
	}

	/**
	 * Get one playbook contract, or all summaries when no name is provided.
	 *
	 * @param string $name Playbook name.
	 * @return array|WP_Error
	 */
	public static function get_playbook( $name = '' ) {
		$name = sanitize_key( (string) $name );

		if ( '' === $name ) {
			return array(
				'schema_version' => '2026-05-20',
				'playbooks'      => self::get_all(),
			);
		}

		$definitions = self::definitions();
		if ( empty( $definitions[ $name ] ) ) {
			return new WP_Error(
				'invalid_playbook',
				sprintf(
					/* translators: 1: requested playbook name, 2: available playbook names */
					__( 'Unknown playbook: %1$s. Available playbooks: %2$s', 'mumcp' ),
					$name,
					implode( ', ', array_keys( $definitions ) )
				),
				array( 'status' => 404 )
			);
		}

		return array_merge(
			array(
				'name'           => $name,
				'schema_version' => '2026-05-20',
			),
			$definitions[ $name ],
			array(
				'global_rules' => self::global_rules(),
			)
		);
	}

	/**
	 * Shared global rules for every playbook.
	 *
	 * @return array
	 */
	private static function global_rules() {
		return array(
			'read_first'          => array( 'wp_get_site_state' ),
			'never_do'            => array(
				'Do not push raw HTML or JavaScript as a shortcut for Gutenberg-native content.',
				'Do not mutate production content without an approval request unless the playbook explicitly says read-only.',
				'Do not invent facts, citations, entities, products, or SEO claims.',
			),
			'approval_principle'  => 'Any publish-facing mutation must create or pass through an approval request.',
			'validation_principle' => 'Run the listed validation tools after preparing changes and before asking for approval.',
		);
	}

	/**
	 * Playbook definitions.
	 *
	 * @return array
	 */
	private static function definitions() {
		return array(
			'build_gutenberg_page' => array(
				'title'       => 'Build a Gutenberg Page',
				'description' => 'Create a draft page using native Gutenberg blocks, site context, SEO gates, and approval-first publishing.',
				'risk_level'  => 'medium',
				'mutates'     => true,
				'required_tools' => array( 'wp_get_site_state', 'wp_get_site_context', 'wp_create_page', 'wp_validate_blocks', 'wp_validate_seo_readiness', 'wp_list_approvals' ),
				'steps'       => array(
					'Read site state and site context.',
					'Create or select a draft page only.',
					'Build with valid Gutenberg blocks, not raw scripts or classic HTML.',
					'Validate blocks and SEO readiness.',
					'Create an approval request for publish-facing content.',
				),
				'validation_gates' => array( 'wp_validate_blocks', 'wp_validate_seo_readiness', 'wp_audit_content_quality' ),
				'approval_gates'   => array( 'content_update', 'publish_status_change', 'seo_metadata_change' ),
				'rollback'         => array( 'wp_list_approvals', 'wp_rollback_approval' ),
				'stop_conditions'  => array( 'missing_site_context', 'block_validation_error', 'seo_readiness_error', 'human_rejects_approval' ),
			),
			'update_gutenberg_section' => array(
				'title'       => 'Update One Gutenberg Section',
				'description' => 'Patch one selected block section with approval, validation, and rollback instead of rewriting a whole page.',
				'risk_level'  => 'medium',
				'mutates'     => true,
				'required_tools' => array( 'wp_get_site_state', 'wp_fetch', 'wp_parse_blocks', 'wp_patch_block_section', 'wp_validate_blocks', 'wp_list_approvals' ),
				'steps'       => array(
					'Read site state and fetch the target post.',
					'Parse blocks and identify one section by path, anchor, or heading.',
					'Prepare a minimal replacement section.',
					'Validate the replacement blocks.',
					'Create an approval request and wait for human apply.',
				),
				'validation_gates' => array( 'wp_validate_blocks', 'wp_validate_seo_readiness' ),
				'approval_gates'   => array( 'section_patch' ),
				'rollback'         => array( 'wp_rollback_approval' ),
				'stop_conditions'  => array( 'ambiguous_section', 'invalid_blocks', 'target_post_missing', 'human_rejects_approval' ),
			),
			'seo_audit_triage' => array(
				'title'       => 'SEO Audit Triage',
				'description' => 'Run a stored SEO audit, inspect open issues, and propose approval-safe fixes without automatic mutation.',
				'risk_level'  => 'low',
				'mutates'     => false,
				'required_tools' => array( 'wp_get_site_state', 'wp_seo_audit_site', 'wp_get_seo_issues', 'wp_run_seo_autofix_plan', 'wp_get_content_graph' ),
				'steps'       => array(
					'Read site state.',
					'Run a stored site SEO audit.',
					'List open issues by severity and category.',
					'Group issues by URL and business priority.',
					'Run the approval-safe autofix planner and recommend the next human-reviewed fix.',
				),
				'validation_gates' => array( 'wp_seo_audit_site', 'wp_get_seo_issues', 'wp_run_seo_autofix_plan' ),
				'approval_gates'   => array( 'required_before_any_fix' ),
				'rollback'         => array( 'not_applicable_read_only' ),
				'stop_conditions'  => array( 'audit_unavailable', 'no_search_facing_content' ),
			),
			'internal_link_improvement' => array(
				'title'       => 'Improve Internal Links',
				'description' => 'Use the content graph to suggest or apply internal links through approval-first changes.',
				'risk_level'  => 'medium',
				'mutates'     => true,
				'required_tools' => array( 'wp_get_site_state', 'wp_get_content_graph', 'wp_suggest_internal_links', 'wp_apply_internal_link', 'wp_validate_internal_links' ),
				'steps'       => array(
					'Read site state and content graph.',
					'Prioritize orphan or weakly linked published pages.',
					'Suggest links from existing graph targets only.',
					'Apply accepted links through approval-first requests.',
					'Validate internal links after approval apply.',
				),
				'validation_gates' => array( 'wp_validate_internal_links', 'wp_validate_seo_readiness' ),
				'approval_gates'   => array( 'content_link_insert' ),
				'rollback'         => array( 'wp_rollback_approval' ),
				'stop_conditions'  => array( 'no_safe_target', 'anchor_not_supported_by_visible_content', 'human_rejects_approval' ),
			),
			'rollback_change' => array(
				'title'       => 'Roll Back an Applied Change',
				'description' => 'Find an applied approval request and roll it back through the stored rollback payload.',
				'risk_level'  => 'high',
				'mutates'     => true,
				'required_tools' => array( 'wp_get_site_state', 'wp_list_approvals', 'wp_get_approval', 'wp_rollback_approval' ),
				'steps'       => array(
					'Read site state and rollback-ready approvals.',
					'Fetch the exact approval request.',
					'Confirm the target resource and rollback reason.',
					'Run rollback.',
					'Validate the resource after rollback.',
				),
				'validation_gates' => array( 'wp_fetch', 'wp_validate_blocks', 'wp_validate_seo_readiness' ),
				'approval_gates'   => array( 'human_confirm_rollback' ),
				'rollback'         => array( 'rollback_is_the_mutation' ),
				'stop_conditions'  => array( 'approval_not_applied', 'resource_hash_mismatch', 'human_does_not_confirm' ),
			),
		);
	}
}
