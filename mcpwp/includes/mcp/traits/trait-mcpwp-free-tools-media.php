<?php
/**
 * Free-tier tool definitions — media category group.
 *
 * Carved verbatim from Mcpwp_MCP_Free_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * media free tool providers. Mixed into Mcpwp_MCP_Free_Tools.
 */
trait Mcpwp_Free_Tools_Media_Trait {

	/**
	 * @return array
	 */
	private function get_media_tools() {
		$tools = array();
		// Media
		$tools[] = $this->define_tool(
			'wp_upload_media',
			'Upload a media file (image, video, etc.) to the WordPress media library',
			array(
				'file' => array(
					'type'        => 'string',
					'description' => 'Base64-encoded file content or file URL',
					'required'    => true,
				),
				'name' => array(
					'type'        => 'string',
					'description' => 'File name',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_upload_media_from_url',
			'Upload a single media file from a URL into the WordPress media library. For bulk migrations (5+ images), use wp_bulk_upload_media instead — it avoids hitting the burst rate limit.',
			array(
				'url' => array(
					'type'        => 'string',
					'description' => 'Publicly accessible URL of the file to download and import',
					'required'    => true,
				),
				'filename' => array(
					'type'        => 'string',
					'description' => 'Override the saved filename on disk (e.g., "ontario-workforce-guide.pdf"). Useful when the source URL has a meaningless slug.',
				),
				'title' => array(
					'type'        => 'string',
					'description' => 'Media library title shown in WP Admin',
				),
				'alt' => array(
					'type'        => 'string',
					'description' => 'Alt text for images',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_bulk_upload_media',
			'Import up to 20 media files from URLs in a single request. Use this for content migrations instead of looping wp_upload_media_from_url — it counts as one request against the rate limit, not one per image. Pass "urls" for simple imports or "items" for title/alt metadata per file. Returns uploaded[] and failed[] arrays.',
			array(
				'urls'  => array(
					'type'        => 'array',
					'description' => 'Simple list of publicly accessible image/file URLs to import. Use "items" instead if you need per-file title or alt text.',
					'items'       => array( 'type' => 'string' ),
				),
				'items' => array(
					'type'        => 'array',
					'description' => 'Structured list for importing with metadata. Each item: {url (required), title, alt, filename}.',
					'items'       => array( 'type' => 'object' ),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_upload_media_b64',
			'Upload a media file from Base64 encoded data. Safer than multipart uploads on shared hosting (bypasses ModSecurity).',
			array(
				'data' => array(
					'type'        => 'string',
					'description' => 'Base64-encoded file content (optionally with data URI prefix)',
					'required'    => true,
				),
				'filename' => array(
					'type'        => 'string',
					'description' => 'Filename with extension (e.g., logo.png)',
					'required'    => true,
				),
				'title' => array(
					'type'        => 'string',
					'description' => 'Media title',
				),
				'alt' => array(
					'type'        => 'string',
					'description' => 'Alt text',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_delete_media',
			'Delete a media attachment from the WordPress media library. Permanently removes the file. Confirm with user first.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Attachment ID',
					'required'    => true,
				),
				'force' => array(
					'type'        => 'boolean',
					'description' => 'Permanently delete instead of trashing (default: false)',
					'default'     => false,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_update_media',
			'Update an existing media attachment — change alt text, title, caption, or description without re-uploading',
			array(
				'id'          => array(
					'type'        => 'number',
					'description' => 'Attachment ID',
					'required'    => true,
				),
				'alt'         => array(
					'type'        => 'string',
					'description' => 'Image alt text (accessibility and SEO)',
				),
				'title'       => array(
					'type'        => 'string',
					'description' => 'Attachment title',
				),
				'caption'     => array(
					'type'        => 'string',
					'description' => 'Attachment caption (short description)',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Attachment description (long description)',
				),
			)
		);

		return $tools;
	}
}
