<?php
namespace ElementorPro\Modules\CustomCode;

/** Models the native print call stack without changing metadata read semantics. */
class Document {
	public static function print_content( $post_id ) {
		return \Mcpwp_Custom_Code_Conditions::filter_changed_view_source( null, $post_id, '_elementor_code', true, 'post' );
	}
}
