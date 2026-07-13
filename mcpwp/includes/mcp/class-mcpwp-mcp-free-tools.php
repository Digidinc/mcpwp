<?php
/**
 * Free-only MCP tool inventory.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_MCP_Free_Tools extends Mcpwp_MCP_Tool_Registry {
	use Mcpwp_Free_Tools_Site_Trait;
	use Mcpwp_Free_Tools_Content_Trait;
	use Mcpwp_Free_Tools_Media_Trait;
	use Mcpwp_Free_Tools_Ops_Trait;

	public function get_tools() {
		$descriptions = array(
			'wp_site_info'              => 'Get local WordPress site information.',
			'wp_list_posts'             => 'List WordPress posts.',
			'wp_get_post'               => 'Get one WordPress post.',
			'wp_create_post'            => 'Create a WordPress post.',
			'wp_update_post'            => 'Update a WordPress post.',
			'wp_list_pages'             => 'List WordPress pages.',
			'wp_get_page'               => 'Get one WordPress page.',
			'wp_create_page'            => 'Create a WordPress page.',
			'wp_update_page'            => 'Update a WordPress page.',
			'wp_list_drafts'            => 'List local draft posts and pages.',
			'wp_delete_draft'           => 'Delete one local draft post or page.',
			'wp_list_media'             => 'List media attachments.',
			'wp_get_media'              => 'Get one media attachment.',
			'wp_upload_media'           => 'Upload a local media file.',
			'wp_upload_media_b64'       => 'Upload media from base64 data.',
			'wp_list_categories'        => 'List post categories.',
			'wp_list_tags'              => 'List post tags.',
			'wp_create_term'            => 'Create a category or tag.',
		);

		$tools = array();
		$inventory = array_merge( $this->mcpwp_site_tool_names(), $this->mcpwp_content_tool_names(), $this->mcpwp_media_tool_names(), $this->mcpwp_taxonomy_tool_names() );
		foreach ( $inventory as $name ) {
			$description = $descriptions[ $name ];
			$tools[] = $this->define_tool( $name, $description, $this->get_input_properties( $name ) );
		}
		return $tools;
	}

	private function get_input_properties( $name ) {
		if ( in_array( $name, array( 'wp_get_post', 'wp_update_post', 'wp_get_page', 'wp_update_page', 'wp_delete_draft', 'wp_get_media' ), true ) ) {
			return array( 'id' => array( 'type' => 'integer', 'description' => 'Resource identifier.', 'required' => true ) );
		}
		if ( 'wp_upload_media_b64' === $name ) {
			return array(
				'data'     => array( 'type' => 'string', 'description' => 'Base64-encoded file data.', 'required' => true ),
				'filename' => array( 'type' => 'string', 'description' => 'Local file name.', 'required' => true ),
			);
		}
		return array();
	}

	public function get_tool_map() {
		return array(
			'wp_site_info'              => array( 'method' => 'GET', 'route' => '/site-info' ),
			'wp_list_posts'             => array( 'method' => 'GET', 'route' => '/posts' ),
			'wp_get_post'               => array( 'method' => 'GET', 'route' => '/posts/{id}' ),
			'wp_create_post'            => array( 'method' => 'POST', 'route' => '/posts' ),
			'wp_update_post'            => array( 'method' => 'PATCH', 'route' => '/posts/{id}' ),
			'wp_list_pages'             => array( 'method' => 'GET', 'route' => '/pages' ),
			'wp_get_page'               => array( 'method' => 'GET', 'route' => '/pages/{id}' ),
			'wp_create_page'            => array( 'method' => 'POST', 'route' => '/pages' ),
			'wp_update_page'            => array( 'method' => 'PATCH', 'route' => '/pages/{id}' ),
			'wp_list_drafts'            => array( 'method' => 'GET', 'route' => '/drafts' ),
			'wp_delete_draft'           => array( 'method' => 'DELETE', 'route' => '/drafts/{id}' ),
			'wp_list_media'             => array( 'method' => 'GET', 'route' => '/media' ),
			'wp_get_media'              => array( 'method' => 'GET', 'route' => '/media/{id}' ),
			'wp_upload_media'           => array( 'method' => 'POST', 'route' => '/media' ),
			'wp_upload_media_b64'       => array( 'method' => 'POST', 'route' => '/media/from-base64' ),
			'wp_list_categories'        => array( 'method' => 'GET', 'route' => '/categories' ),
			'wp_list_tags'              => array( 'method' => 'GET', 'route' => '/tags' ),
			'wp_create_term'            => array( 'method' => 'POST', 'route' => '/terms' ),
		);
	}

	public function get_tool_categories() {
		$categories = array();
		foreach ( array_keys( $this->get_tool_map() ) as $name ) {
			if ( false !== strpos( $name, 'media' ) ) {
				$categories[ $name ] = 'media';
			} elseif ( false !== strpos( $name, 'term' ) || false !== strpos( $name, 'categories' ) || false !== strpos( $name, 'tags' ) ) {
				$categories[ $name ] = 'taxonomy';
			} elseif ( 'wp_site_info' === $name ) {
				$categories[ $name ] = 'site';
			} else {
				$categories[ $name ] = 'content';
			}
		}
		return $categories;
	}

	protected function get_destructive_tools() {
		return array( 'wp_delete_draft' );
	}

}
