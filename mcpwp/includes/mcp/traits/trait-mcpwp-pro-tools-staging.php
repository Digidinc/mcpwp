<?php
/**
 * Pro-tier tool definitions — staging category group.
 *
 * Provides four MCP tools for staging environment management:
 *   wp_get_staging_info   — detect host + list environments (read-only)
 *   wp_push_to_staging    — copy live → staging (safe, non-destructive)
 *   wp_push_to_live       — copy staging → live (gates via approval request)
 *   wp_get_staging_status — poll async push operation status
 *
 * @package MCPWP_Pro
 * @since   3.3.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Pro_Tools_Staging_Trait {

	/**
	 * Return all staging tool definitions.
	 *
	 * @return array
	 */
	private function get_staging_pro_tools(): array {
		$tools = array();

		$tools[] = $this->define_tool(
			'wp_get_staging_info',
			'Detect the hosting provider and list available staging environments for this site. Returns environment IDs, names, types (staging|production), and URLs. Call this first to discover environment IDs before using push tools. No parameters required.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_push_to_staging',
			'Copy the live (production) environment to staging. Safe and non-destructive — overwrites the staging environment only. Requires the staging integration to be configured in MCPWP > Integrations. On hosts with async APIs (Kinsta) the response includes an operation_id to poll with wp_get_staging_status.',
			array(
				'source'        => array(
					'type'        => 'string',
					'description' => 'Source environment ID (live/production). Get valid IDs from wp_get_staging_info.',
					'required'    => true,
				),
				'destination'   => array(
					'type'        => 'string',
					'description' => 'Destination environment ID (staging). Get valid IDs from wp_get_staging_info.',
					'required'    => true,
				),
				'include_db'    => array(
					'type'        => 'boolean',
					'description' => 'Include the database in the copy (default true).',
					'default'     => true,
				),
				'include_files' => array(
					'type'        => 'boolean',
					'description' => 'Include uploaded files/media in the copy (default true).',
					'default'     => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_push_to_live',
			'Request a staging → live push. IMPORTANT: this tool does NOT push immediately. It creates a pending approval request and returns an approval_id. A human operator must approve the request (via wp_approve_request) before it can be applied (via wp_apply_approval). Always provide a reason describing what changed and why. Marked destructive — irreversible without a backup.',
			array(
				'source'        => array(
					'type'        => 'string',
					'description' => 'Source environment ID (staging). Get valid IDs from wp_get_staging_info.',
					'required'    => true,
				),
				'destination'   => array(
					'type'        => 'string',
					'description' => 'Destination environment ID (live/production). Get valid IDs from wp_get_staging_info.',
					'required'    => true,
				),
				'include_db'    => array(
					'type'        => 'boolean',
					'description' => 'Include the database in the push (default true).',
					'default'     => true,
				),
				'include_files' => array(
					'type'        => 'boolean',
					'description' => 'Include uploaded files/media in the push (default true).',
					'default'     => true,
				),
				'reason'        => array(
					'type'        => 'string',
					'description' => 'Required: describe what changed on staging and why it is ready for live.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_get_staging_status',
			'Poll the status of an asynchronous staging push operation. Use the operation_id returned by wp_push_to_staging or wp_push_to_live (after approval + apply). Returns status, progress percentage (if available), and a message from the hosting API.',
			array(
				'operation_id' => array(
					'type'        => 'string',
					'description' => 'Operation ID returned by a push endpoint.',
					'required'    => true,
				),
			)
		);

		return $tools;
	}
}
