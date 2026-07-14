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
			'wp_site_info'        => 'Get readable local WordPress site information.',
			'wp_list_posts'       => 'List visible WordPress posts.',
			'wp_get_post'         => 'Get one visible WordPress post.',
			'wp_create_post'      => 'Create a WordPress post as draft or pending. Publish/private status additionally requires admin key scope and publish_posts.',
			'wp_update_post'      => 'Update a WordPress post. Publishing/private status or editing an existing published/private post additionally requires admin key scope; publish/private also requires publish_posts.',
			'wp_list_pages'       => 'List visible WordPress pages.',
			'wp_get_page'         => 'Get one visible WordPress page.',
			'wp_create_page'      => 'Create a WordPress page as draft or pending. Publish/private status additionally requires admin key scope and publish_pages.',
			'wp_update_page'      => 'Update a WordPress page. Publishing/private status or editing an existing published/private page additionally requires admin key scope; publish/private also requires publish_pages.',
			'wp_list_drafts'      => 'List visible draft posts and pages owned by the bound user unless edit-others capabilities grant broader access.',
			'wp_delete_draft'     => 'Move one draft post or page to Trash.',
			'wp_list_media'       => 'List visible local media attachments.',
			'wp_get_media'        => 'Get one visible local media attachment.',
			'wp_upload_media_b64' => 'Upload one bounded local file from strict Base64 data.',
			'wp_list_categories'  => 'List WordPress post categories.',
			'wp_list_tags'        => 'List WordPress post tags.',
			'wp_create_term'      => 'Create one category or tag.',
		);

		$tools     = array();
		$inventory = array_merge( $this->mcpwp_site_tool_names(), $this->mcpwp_content_tool_names(), $this->mcpwp_media_tool_names(), $this->mcpwp_taxonomy_tool_names() );
		foreach ( $inventory as $name ) {
			$description = $descriptions[ $name ];
			$tools[]     = $this->define_tool( $name, $description, $this->get_input_properties( $name ), $this->get_input_schema_options( $name ) );
		}
		return $tools;
	}

	private function get_input_properties( $name ) {
		$id   = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'maximum'  => 2147483647,
				'required' => true,
			),
		);
		$list = array(
			'posts_per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			),
			'paged'          => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 10000,
				'default' => 1,
			),
			'post_status'    => array(
				'type'    => 'string',
				'enum'    => array( 'publish', 'draft', 'pending', 'private' ),
				'default' => 'publish',
			),
			'orderby'        => array(
				'type' => 'string',
				'enum' => array( 'date', 'modified', 'title', 'id' ),
			),
			'order'          => array(
				'type' => 'string',
				'enum' => array( 'asc', 'desc' ),
			),
		);
		$post = array(
			'title'          => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
				'required'  => true,
			),
			'content'        => array(
				'type'      => 'string',
				'maxLength' => 1000000,
			),
			'status'         => array(
				'type' => 'string',
				'enum' => array( 'draft', 'pending', 'publish', 'private' ),
			),
			'excerpt'        => array(
				'type'      => 'string',
				'maxLength' => 10000,
			),
			'slug'           => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'categories'     => array(
				'type'        => 'array',
				'maxItems'    => 100,
				'uniqueItems' => true,
				'items'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 2147483647,
				),
			),
			'tags'           => array(
				'type'        => 'array',
				'maxItems'    => 100,
				'uniqueItems' => true,
				'items'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
			),
			'featured_image' => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 2147483647,
			),
		);
		$page = array(
			'title'          => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
				'required'  => true,
			),
			'content'        => array(
				'type'      => 'string',
				'maxLength' => 1000000,
			),
			'status'         => array(
				'type' => 'string',
				'enum' => array( 'draft', 'pending', 'publish', 'private' ),
			),
			'slug'           => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'parent'         => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 2147483647,
			),
			'menu_order'     => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 100000,
			),
			'template'       => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'featured_image' => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 2147483647,
			),
		);
		switch ( $name ) {
			case 'wp_list_posts':
				return $list;
			case 'wp_get_post':
			case 'wp_get_page':
			case 'wp_delete_draft':
			case 'wp_get_media':
				return $id;
			case 'wp_create_post':
				return $post;
			case 'wp_update_post':
				$post['title']['required'] = false;
				return array_merge( $id, $post );
			case 'wp_list_pages':
				$list['orderby']['enum'][] = 'menu_order';
				return $list;
			case 'wp_create_page':
				return $page;
			case 'wp_update_page':
				$page['title']['required'] = false;
				return array_merge( $id, $page );
			case 'wp_list_drafts':
				return array(
					'type'           => array(
						'type'    => 'string',
						'enum'    => array( 'all', 'post', 'page' ),
						'default' => 'all',
					),
					'posts_per_page' => $list['posts_per_page'],
					'paged'          => $list['paged'],
					'orderby'        => array(
						'type' => 'string',
						'enum' => array( 'modified', 'date', 'title', 'id' ),
					),
					'order'          => array(
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc' ),
						'default' => 'desc',
					),
				);
			case 'wp_list_media':
				return array(
					'posts_per_page' => $list['posts_per_page'],
					'paged'          => $list['paged'],
					'mime_type'      => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
					'search'         => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'orderby'        => $list['orderby'],
					'order'          => $list['order'],
				);
			case 'wp_upload_media_b64':
				return array(
					'data'      => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 15728640,
						'required'  => true,
					),
					'filename'  => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 255,
						'required'  => true,
					),
					'mime_type' => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
					'title'     => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'alt'       => array(
						'type'      => 'string',
						'maxLength' => 250,
					),
					'caption'   => array(
						'type'      => 'string',
						'maxLength' => 10000,
					),
				);
			case 'wp_list_categories':
			case 'wp_list_tags':
				return array(
					'per_page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 50,
					),
					'page'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 10000,
						'default' => 1,
					),
					'search'     => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'hide_empty' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'orderby'    => array(
						'type' => 'string',
						'enum' => array( 'name', 'slug', 'count', 'id' ),
					),
					'order'      => array(
						'type' => 'string',
						'enum' => array( 'asc', 'desc' ),
					),
				);
			case 'wp_create_term':
				return array(
					'taxonomy'    => array(
						'type'     => 'string',
						'enum'     => array( 'category', 'post_tag' ),
						'required' => true,
					),
					'name'        => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
						'required'  => true,
					),
					'slug'        => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'description' => array(
						'type'      => 'string',
						'maxLength' => 10000,
					),
				);
			default:
				return array();
		}
	}

	private function get_input_schema_options( $name ) {
		return in_array( $name, array( 'wp_update_post', 'wp_update_page' ), true ) ? array( 'minProperties' => 2 ) : array(); }

	public function get_required_capabilities() {
		return array(
			'wp_site_info'        => array( 'read' ),
			'wp_list_posts'       => array( 'read', 'edit_posts/read_private_posts for non-public visibility' ),
			'wp_get_post'         => array( 'read_post:{id}' ),
			'wp_create_post'      => array( 'edit_posts', 'publish_posts for publish/private', 'assign_terms when terms supplied' ),
			'wp_update_post'      => array( 'edit_post:{id}', 'publish_posts for publish/private', 'assign_terms when terms supplied' ),
			'wp_list_pages'       => array( 'read', 'edit_pages/read_private_pages for non-public visibility' ),
			'wp_get_page'         => array( 'read_post:{id}' ),
			'wp_create_page'      => array( 'edit_pages', 'publish_pages for publish/private' ),
			'wp_update_page'      => array( 'edit_post:{id}', 'publish_pages for publish/private' ),
			'wp_list_drafts'      => array( 'read', 'edit_posts and/or edit_pages' ),
			'wp_delete_draft'     => array( 'delete_post:{id}' ),
			'wp_list_media'       => array( 'read', 'read_post per non-public attachment' ),
			'wp_get_media'        => array( 'read_post:{id}' ),
			'wp_upload_media_b64' => array( 'upload_files' ),
			'wp_list_categories'  => array( 'read' ),
			'wp_list_tags'        => array( 'read' ),
			'wp_create_term'      => array( 'selected taxonomy manage_terms' ),
		);
	}

	public function get_tool_map() {
		return array(
			'wp_site_info'        => array(
				'method' => 'GET',
				'route'  => '/site-info',
			),
			'wp_list_posts'       => array(
				'method' => 'GET',
				'route'  => '/posts',
			),
			'wp_get_post'         => array(
				'method' => 'GET',
				'route'  => '/posts/{id}',
			),
			'wp_create_post'      => array(
				'method' => 'POST',
				'route'  => '/posts',
			),
			'wp_update_post'      => array(
				'method' => 'PATCH',
				'route'  => '/posts/{id}',
			),
			'wp_list_pages'       => array(
				'method' => 'GET',
				'route'  => '/pages',
			),
			'wp_get_page'         => array(
				'method' => 'GET',
				'route'  => '/pages/{id}',
			),
			'wp_create_page'      => array(
				'method' => 'POST',
				'route'  => '/pages',
			),
			'wp_update_page'      => array(
				'method' => 'PATCH',
				'route'  => '/pages/{id}',
			),
			'wp_list_drafts'      => array(
				'method' => 'GET',
				'route'  => '/drafts',
			),
			'wp_delete_draft'     => array(
				'method' => 'DELETE',
				'route'  => '/drafts/{id}',
			),
			'wp_list_media'       => array(
				'method' => 'GET',
				'route'  => '/media',
			),
			'wp_get_media'        => array(
				'method' => 'GET',
				'route'  => '/media/{id}',
			),
			'wp_upload_media_b64' => array(
				'method' => 'POST',
				'route'  => '/media/from-base64',
			),
			'wp_list_categories'  => array(
				'method' => 'GET',
				'route'  => '/categories',
			),
			'wp_list_tags'        => array(
				'method' => 'GET',
				'route'  => '/tags',
			),
			'wp_create_term'      => array(
				'method' => 'POST',
				'route'  => '/terms',
			),
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
