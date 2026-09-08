<?php
/**
 * Elementor Widget Reference Data
 *
 * Static widget schemas for AI models to query at runtime.
 * Covers common free and pro widgets with settings keys, types, defaults, and examples.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget reference registry.
 *
 * Provides static widget schema data including:
 * - Description and category
 * - Key settings with types, defaults, and allowed values
 * - Example JSON element for each widget
 * - Common mistakes to avoid
 */
class Mcpwp_Elementor_Widgets {

	/**
	 * Get all widget schemas.
	 *
	 * @return array Associative array keyed by widget type.
	 */
	public static function get_all() {
		return array_merge( self::get_free_widgets(), self::get_pro_widgets() );
	}

	/**
	 * Get schema for a specific widget type.
	 *
	 * @param string $widget_type Widget type name.
	 * @return array|null Widget schema or null if not found.
	 */
	public static function get( $widget_type ) {
		$all = self::get_all();
		return isset( $all[ $widget_type ] ) ? $all[ $widget_type ] : null;
	}

	/**
	 * Get all known widget type names.
	 *
	 * @return array Widget type names.
	 */
	public static function get_known_types() {
		return array_keys( self::get_all() );
	}

	/**
	 * Get key settings names for a widget type.
	 *
	 * @param string $widget_type Widget type name.
	 * @return array Setting key names, or empty array if unknown widget.
	 */
	public static function get_valid_keys( $widget_type ) {
		$schema = self::get( $widget_type );
		if ( ! $schema || empty( $schema['settings'] ) ) {
			return array();
		}
		return array_keys( $schema['settings'] );
	}

	/**
	 * Find closest matching widget types using Levenshtein distance.
	 *
	 * @param string $input   Unknown widget type.
	 * @param int    $max     Maximum number of suggestions.
	 * @return array Matching widget type names.
	 */
	public static function find_closest( $input, $max = 3 ) {
		$all         = self::get_known_types();
		$matches     = array();

		foreach ( $all as $type ) {
			$distance = levenshtein( $input, $type );
			if ( $distance <= 3 ) {
				$matches[ $type ] = $distance;
			}
		}

		asort( $matches );
		return array_slice( array_keys( $matches ), 0, $max );
	}

	/**
	 * Get free widget schemas.
	 *
	 * @return array Associative array keyed by widget type.
	 */
	public static function get_free_widgets() {
		return array(
			'heading'         => array(
				'description' => 'Display a headline with customizable size, alignment, and color.',
				'category'    => 'basic',
				'settings'    => array(
					'title'                     => array( 'type' => 'text', 'default' => 'Add Your Heading Text Here', 'description' => 'The heading text content' ),
					'header_size'               => array( 'type' => 'select', 'default' => 'h2', 'options' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), 'description' => 'HTML heading tag' ),
					'align'                     => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right', 'justify' ), 'description' => 'Text alignment' ),
					'title_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Heading text color' ),
					'typography_typography'      => array( 'type' => 'switcher', 'default' => '', 'description' => 'Enable custom typography (set to "custom" to activate)' ),
					'typography_font_family'     => array( 'type' => 'font', 'default' => '', 'description' => 'Font family name' ),
					'typography_font_size'       => array( 'type' => 'slider', 'default' => '', 'description' => 'Font size with unit, e.g. {"size":32,"unit":"px"}' ),
					'typography_font_weight'     => array( 'type' => 'select', 'default' => '', 'options' => array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'bold', 'normal' ), 'description' => 'Font weight' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Link URL object: {"url":"https://...","is_external":true,"nofollow":false}' ),
					'size'                      => array( 'type' => 'select', 'default' => 'default', 'options' => array( 'default', 'small', 'medium', 'large', 'xl', 'xxl' ), 'description' => 'Predefined heading size' ),
				),
				'example'     => array(
					'id'         => 'abc12345',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array(
						'title'       => 'Welcome to Our Site',
						'header_size' => 'h1',
						'align'       => 'center',
						'title_color' => '#333333',
					),
				),
				'common_mistakes' => array(
					'Using "text" instead of "title" for the heading content',
					'Using "tag" instead of "header_size" for the HTML tag',
					'Using "color" instead of "title_color"',
					'Using "alignment" instead of "align"',
				),
			),

			'text-editor'     => array(
				'description' => 'WYSIWYG text editor widget for rich text content (paragraphs, lists, etc.).',
				'category'    => 'basic',
				'settings'    => array(
					'editor'                    => array( 'type' => 'wysiwyg', 'default' => '', 'description' => 'HTML content (supports <p>, <strong>, <em>, <a>, <ul>, <ol>, etc.)' ),
					'align'                     => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right', 'justify' ), 'description' => 'Text alignment' ),
					'text_color'                => array( 'type' => 'color', 'default' => '', 'description' => 'Text color' ),
					'typography_typography'      => array( 'type' => 'switcher', 'default' => '', 'description' => 'Enable custom typography' ),
					'typography_font_family'     => array( 'type' => 'font', 'default' => '', 'description' => 'Font family' ),
					'typography_font_size'       => array( 'type' => 'slider', 'default' => '', 'description' => 'Font size' ),
				),
				'example'     => array(
					'id'         => 'abc12346',
					'elType'     => 'widget',
					'widgetType' => 'text-editor',
					'settings'   => array(
						'editor' => '<p>This is a paragraph of text with <strong>bold</strong> and <em>italic</em> formatting.</p>',
					),
				),
				'common_mistakes' => array(
					'Using "content" or "text" instead of "editor"',
					'Forgetting to wrap text in HTML tags like <p>',
				),
			),

			'image'           => array(
				'description' => 'Display an image with optional caption, link, and lightbox.',
				'category'    => 'basic',
				'settings'    => array(
					'image'                     => array( 'type' => 'media', 'default' => '', 'description' => 'Image object: {"url":"https://...","id":123}' ),
					'image_size'                => array( 'type' => 'select', 'default' => 'full', 'options' => array( 'thumbnail', 'medium', 'medium_large', 'large', 'full' ), 'description' => 'WordPress image size' ),
					'align'                     => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right' ), 'description' => 'Image alignment' ),
					'caption_source'            => array( 'type' => 'select', 'default' => 'none', 'options' => array( 'none', 'attachment', 'custom' ), 'description' => 'Caption source' ),
					'caption'                   => array( 'type' => 'text', 'default' => '', 'description' => 'Custom caption text (when caption_source is "custom")' ),
					'link_to'                   => array( 'type' => 'select', 'default' => 'none', 'options' => array( 'none', 'file', 'custom' ), 'description' => 'Link destination' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Custom link URL object' ),
					'open_lightbox'             => array( 'type' => 'select', 'default' => 'default', 'options' => array( 'default', 'yes', 'no' ), 'description' => 'Open in lightbox' ),
					'width'                     => array( 'type' => 'slider', 'default' => '', 'description' => 'Image width with unit' ),
					'height'                    => array( 'type' => 'slider', 'default' => '', 'description' => 'Image height with unit' ),
					'hover_animation'           => array( 'type' => 'select', 'default' => '', 'description' => 'CSS hover animation effect' ),
				),
				'example'     => array(
					'id'         => 'abc12347',
					'elType'     => 'widget',
					'widgetType' => 'image',
					'settings'   => array(
						'image'      => array( 'url' => 'https://example.com/image.jpg', 'id' => '' ),
						'image_size' => 'full',
						'align'      => 'center',
					),
				),
				'common_mistakes' => array(
					'Using "src" or "url" instead of "image" object',
					'Passing a string URL instead of {"url":"...","id":""} object',
					'Using "size" instead of "image_size"',
				),
			),

			'button'          => array(
				'description' => 'Clickable button with customizable text, link, size, and style.',
				'category'    => 'basic',
				'settings'    => array(
					'text'                      => array( 'type' => 'text', 'default' => 'Click here', 'description' => 'Button text' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Button link: {"url":"https://...","is_external":true,"nofollow":false}' ),
					'align'                     => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right', 'justify' ), 'description' => 'Button alignment' ),
					'size'                      => array( 'type' => 'select', 'default' => 'sm', 'options' => array( 'xs', 'sm', 'md', 'lg', 'xl' ), 'description' => 'Button size' ),
					'button_type'               => array( 'type' => 'select', 'default' => '', 'options' => array( '', 'info', 'success', 'warning', 'danger' ), 'description' => 'Button type/color scheme' ),
					'icon'                      => array( 'type' => 'icons', 'default' => '', 'description' => 'Button icon: {"value":"fas fa-arrow-right","library":"fa-solid"}' ),
					'icon_align'                => array( 'type' => 'select', 'default' => 'left', 'options' => array( 'left', 'right' ), 'description' => 'Icon position' ),
					'icon_indent'               => array( 'type' => 'slider', 'default' => '', 'description' => 'Space between icon and text' ),
					'button_css_id'             => array( 'type' => 'text', 'default' => '', 'description' => 'Custom CSS ID for the button' ),
					'background_color'          => array( 'type' => 'color', 'default' => '', 'description' => 'Button background color (normal state)' ),
					// `button_background_color` used to be declared here as a
					// "Pro variant key". It is not a control on the button
					// widget at all — declaring it made the validator accept
					// it, so 19 buttons were styled with it, every call
					// returned success with no warning, and Elementor emitted
					// no background rule: they all fell through to the global
					// Kit accent (#789). Measured on the rig against real CSS
					// generation:
					//
					//   background_color         CSS emits the colour   YES
					//   button_background_color  CSS emits the colour   NO
					//
					// The key IS real on the `form` widget, where it styles
					// the submit button — which is presumably how it reached
					// this schema. It is per-widget, so it stays there and is
					// gone from here.
					'button_text_color'         => array( 'type' => 'color', 'default' => '', 'description' => 'Button text color' ),
					'border_radius'             => array( 'type' => 'dimensions', 'default' => '', 'description' => 'Border radius' ),
					'hover_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Text color on hover' ),
					'button_background_hover_color' => array( 'type' => 'color', 'default' => '', 'description' => 'Background color on hover' ),
					'hover_animation'           => array( 'type' => 'select', 'default' => '', 'description' => 'CSS hover animation' ),
				),
				'example'     => array(
					'id'         => 'abc12348',
					'elType'     => 'widget',
					'widgetType' => 'button',
					'settings'   => array(
						'text' => 'Get Started',
						'link' => array( 'url' => 'https://example.com', 'is_external' => false, 'nofollow' => false ),
						'size' => 'md',
						'align' => 'center',
					),
				),
				'common_mistakes' => array(
					'Using "label" or "title" instead of "text"',
					'Passing link as a string instead of {"url":"..."} object',
					'Using "color" instead of "button_text_color" or "background_color"',
					'Using "button_background_color" for the normal-state background — that is the form widget\'s key; on button it is "background_color"',
				),
			),

			'icon'            => array(
				'description' => 'Display a single icon from Font Awesome or other icon libraries.',
				'category'    => 'basic',
				'settings'    => array(
					'selected_icon'             => array( 'type' => 'icons', 'default' => '', 'description' => 'Icon: {"value":"fas fa-star","library":"fa-solid"}' ),
					'view'                      => array( 'type' => 'select', 'default' => 'default', 'options' => array( 'default', 'stacked', 'framed' ), 'description' => 'Icon view style' ),
					'shape'                     => array( 'type' => 'select', 'default' => 'circle', 'options' => array( 'circle', 'square' ), 'description' => 'Background shape (when view is stacked/framed)' ),
					'align'                     => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right' ), 'description' => 'Icon alignment' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Icon link URL object' ),
					'primary_color'             => array( 'type' => 'color', 'default' => '', 'description' => 'Primary icon color' ),
					'secondary_color'           => array( 'type' => 'color', 'default' => '', 'description' => 'Secondary/background color' ),
					'size'                      => array( 'type' => 'slider', 'default' => '', 'description' => 'Icon size' ),
					'hover_animation'           => array( 'type' => 'select', 'default' => '', 'description' => 'Hover animation effect' ),
				),
				'example'     => array(
					'id'         => 'abc12349',
					'elType'     => 'widget',
					'widgetType' => 'icon',
					'settings'   => array(
						'selected_icon' => array( 'value' => 'fas fa-star', 'library' => 'fa-solid' ),
						'view'          => 'stacked',
						'primary_color' => '#FFD700',
						'align'         => 'center',
					),
				),
				'common_mistakes' => array(
					'Using "icon" instead of "selected_icon"',
					'Passing icon as a string instead of {"value":"fas fa-...","library":"fa-solid"} object',
				),
			),

			'spacer'          => array(
				'description' => 'Add vertical space between elements.',
				'category'    => 'basic',
				'settings'    => array(
					'space'                     => array( 'type' => 'slider', 'default' => array( 'size' => 50, 'unit' => 'px' ), 'description' => 'Space height: {"size":50,"unit":"px"}' ),
				),
				'example'     => array(
					'id'         => 'abc12350',
					'elType'     => 'widget',
					'widgetType' => 'spacer',
					'settings'   => array(
						'space' => array( 'size' => 30, 'unit' => 'px' ),
					),
				),
				'common_mistakes' => array(
					'Using "height" instead of "space"',
					'Passing a plain number instead of {"size":50,"unit":"px"} object',
				),
			),

			'divider'         => array(
				'description' => 'Visual horizontal line/divider between content sections.',
				'category'    => 'basic',
				'settings'    => array(
					'style'                     => array( 'type' => 'select', 'default' => 'solid', 'options' => array( 'solid', 'double', 'dotted', 'dashed' ), 'description' => 'Divider line style' ),
					'weight'                    => array( 'type' => 'slider', 'default' => '', 'description' => 'Line thickness' ),
					'width'                     => array( 'type' => 'slider', 'default' => '', 'description' => 'Divider width percentage' ),
					'color'                     => array( 'type' => 'color', 'default' => '', 'description' => 'Divider color' ),
					'gap'                       => array( 'type' => 'slider', 'default' => '', 'description' => 'Top and bottom gap' ),
					'align'                     => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right' ), 'description' => 'Divider alignment' ),
					'look'                      => array( 'type' => 'select', 'default' => 'line', 'options' => array( 'line', 'line_icon', 'line_text' ), 'description' => 'Divider type with optional text/icon' ),
					'text'                      => array( 'type' => 'text', 'default' => '', 'description' => 'Text to display on divider (when look is "line_text")' ),
					'icon'                      => array( 'type' => 'icons', 'default' => '', 'description' => 'Icon on divider (when look is "line_icon")' ),
				),
				'example'     => array(
					'id'         => 'abc12351',
					'elType'     => 'widget',
					'widgetType' => 'divider',
					'settings'   => array(
						'style' => 'solid',
						'color' => '#CCCCCC',
						'width' => array( 'size' => 100, 'unit' => '%' ),
					),
				),
				'common_mistakes' => array(
					'Using "border-style" instead of "style"',
					'Using "thickness" instead of "weight"',
				),
			),

			'video'           => array(
				'description' => 'Embed a video from YouTube, Vimeo, Dailymotion, or self-hosted.',
				'category'    => 'basic',
				'settings'    => array(
					'video_type'                => array( 'type' => 'select', 'default' => 'youtube', 'options' => array( 'youtube', 'vimeo', 'dailymotion', 'hosted' ), 'description' => 'Video source type' ),
					'youtube_url'               => array( 'type' => 'text', 'default' => 'https://www.youtube.com/watch?v=XHOmBV4js_E', 'description' => 'YouTube video URL' ),
					'vimeo_url'                 => array( 'type' => 'text', 'default' => '', 'description' => 'Vimeo video URL' ),
					'dailymotion_url'           => array( 'type' => 'text', 'default' => '', 'description' => 'Dailymotion video URL' ),
					'autoplay'                  => array( 'type' => 'switcher', 'default' => '', 'description' => 'Auto-play video' ),
					'mute'                      => array( 'type' => 'switcher', 'default' => '', 'description' => 'Mute video' ),
					'loop'                      => array( 'type' => 'switcher', 'default' => '', 'description' => 'Loop video' ),
					'controls'                  => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show player controls' ),
					'show_image_overlay'        => array( 'type' => 'switcher', 'default' => '', 'description' => 'Show custom thumbnail overlay' ),
					'image_overlay'             => array( 'type' => 'media', 'default' => '', 'description' => 'Custom thumbnail image object' ),
					'lightbox'                  => array( 'type' => 'switcher', 'default' => '', 'description' => 'Play in lightbox' ),
					'aspect_ratio'              => array( 'type' => 'select', 'default' => '169', 'options' => array( '169', '219', '43', '32', '11', '916' ), 'description' => 'Video aspect ratio' ),
				),
				'example'     => array(
					'id'         => 'abc12352',
					'elType'     => 'widget',
					'widgetType' => 'video',
					'settings'   => array(
						'video_type'  => 'youtube',
						'youtube_url' => 'https://www.youtube.com/watch?v=XHOmBV4js_E',
					),
				),
				'common_mistakes' => array(
					'Using "url" instead of "youtube_url" or "vimeo_url"',
					'Forgetting to set "video_type" when using non-YouTube sources',
				),
			),

			'icon-box'        => array(
				'description' => 'Icon with a heading and description text, commonly used for feature lists.',
				'category'    => 'basic',
				'settings'    => array(
					'selected_icon'             => array( 'type' => 'icons', 'default' => '', 'description' => 'Icon: {"value":"fas fa-star","library":"fa-solid"}' ),
					'title_text'                => array( 'type' => 'text', 'default' => 'This is the heading', 'description' => 'Title text' ),
					'description_text'          => array( 'type' => 'textarea', 'default' => '', 'description' => 'Description text' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Link URL object' ),
					'position'                    => array( 'type' => 'choose', 'default' => 'top', 'options' => array( 'top', 'left', 'right' ), 'description' => 'Icon position relative to content' ),
					'align'                       => array( 'type' => 'choose', 'default' => 'center', 'options' => array( 'left', 'center', 'right' ), 'description' => 'Horizontal alignment of icon and text' ),
					'title_size'                  => array( 'type' => 'select', 'default' => 'h3', 'options' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), 'description' => 'Title HTML tag' ),
					'primary_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Icon color' ),
					'icon_space'                  => array( 'type' => 'slider', 'default' => '', 'description' => 'Space between icon and content' ),
					'icon_size'                   => array( 'type' => 'slider', 'default' => '', 'description' => 'Icon size' ),
					'title_color'                 => array( 'type' => 'color', 'default' => '', 'description' => 'Title color' ),
					'description_color'           => array( 'type' => 'color', 'default' => '', 'description' => 'Description color' ),
					'title_typography_typography' => array( 'type' => 'switcher', 'default' => '', 'description' => 'Enable custom title typography' ),
					'title_typography_font_size'  => array( 'type' => 'slider', 'default' => '', 'description' => 'Title font size: {"size":18,"unit":"px"}' ),
				),
				'example'     => array(
					'id'         => 'abc12353',
					'elType'     => 'widget',
					'widgetType' => 'icon-box',
					'settings'   => array(
						'selected_icon'    => array( 'value' => 'fas fa-rocket', 'library' => 'fa-solid' ),
						'title_text'       => 'Fast Performance',
						'description_text' => 'Lightning-fast page loads.',
						'position'         => 'top',
					),
				),
				'common_mistakes' => array(
					'Using "title" instead of "title_text"',
					'Using "description" instead of "description_text"',
					'Using "icon" instead of "selected_icon"',
				),
			),

			'image-box'       => array(
				'description' => 'Image with a heading and description, commonly used for feature cards.',
				'category'    => 'basic',
				'settings'    => array(
					'image'                     => array( 'type' => 'media', 'default' => '', 'description' => 'Image object: {"url":"...","id":""}' ),
					'image_size'                => array( 'type' => 'select', 'default' => 'full', 'description' => 'WordPress image size' ),
					'title_text'                => array( 'type' => 'text', 'default' => 'This is the heading', 'description' => 'Title text' ),
					'description_text'          => array( 'type' => 'textarea', 'default' => '', 'description' => 'Description text' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Link URL object' ),
					'position'                  => array( 'type' => 'choose', 'default' => 'top', 'options' => array( 'top', 'left', 'right' ), 'description' => 'Image position' ),
					'title_size'                => array( 'type' => 'select', 'default' => 'h3', 'options' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), 'description' => 'Title HTML tag' ),
					'title_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Title color' ),
					'description_color'         => array( 'type' => 'color', 'default' => '', 'description' => 'Description color' ),
				),
				'example'     => array(
					'id'         => 'abc12354',
					'elType'     => 'widget',
					'widgetType' => 'image-box',
					'settings'   => array(
						'image'            => array( 'url' => 'https://example.com/feature.jpg', 'id' => '' ),
						'title_text'       => 'Our Feature',
						'description_text' => 'Feature description goes here.',
					),
				),
				'common_mistakes' => array(
					'Using "title" instead of "title_text"',
					'Using "description" instead of "description_text"',
				),
			),

			'google_maps'     => array(
				'description' => 'Embed a Google Maps location.',
				'category'    => 'basic',
				'settings'    => array(
					'address'                   => array( 'type' => 'text', 'default' => 'London Eye, London, United Kingdom', 'description' => 'Map address' ),
					'zoom'                      => array( 'type' => 'slider', 'default' => array( 'size' => 10 ), 'description' => 'Map zoom level: {"size":10}' ),
					'height'                    => array( 'type' => 'slider', 'default' => array( 'size' => 300, 'unit' => 'px' ), 'description' => 'Map height' ),
				),
				'example'     => array(
					'id'         => 'abc12355',
					'elType'     => 'widget',
					'widgetType' => 'google_maps',
					'settings'   => array(
						'address' => '1600 Amphitheatre Parkway, Mountain View, CA',
						'zoom'    => array( 'size' => 15 ),
					),
				),
				'common_mistakes' => array(
					'Using "location" instead of "address"',
					'Note: widget type is "google_maps" with underscore, not "google-maps"',
				),
			),

			'counter'         => array(
				'description' => 'Animated number counter with optional prefix/suffix.',
				'category'    => 'basic',
				'settings'    => array(
					'starting_number'                    => array( 'type' => 'number', 'default' => 0, 'description' => 'Counter start value' ),
					'ending_number'                      => array( 'type' => 'number', 'default' => 100, 'description' => 'Counter end value' ),
					'prefix'                             => array( 'type' => 'text', 'default' => '', 'description' => 'Text before the number' ),
					'suffix'                             => array( 'type' => 'text', 'default' => '', 'description' => 'Text after the number' ),
					'title'                              => array( 'type' => 'text', 'default' => '', 'description' => 'Title below the counter' ),
					'duration'                           => array( 'type' => 'number', 'default' => 2000, 'description' => 'Animation duration in milliseconds' ),
					'thousand_separator'                 => array( 'type' => 'switcher', 'default' => '', 'description' => 'Show thousand separator' ),
					'thousand_separator_char'            => array( 'type' => 'select', 'default' => ',', 'options' => array( ',', '.', ' ' ), 'description' => 'Separator character' ),
					'number_color'                       => array( 'type' => 'color', 'default' => '', 'description' => 'Number color' ),
					'title_color'                        => array( 'type' => 'color', 'default' => '', 'description' => 'Title color' ),
					'typography_typography'              => array( 'type' => 'switcher', 'default' => '', 'description' => 'Enable custom number typography' ),
					'typography_font_size'               => array( 'type' => 'slider', 'default' => '', 'description' => 'Number font size: {"size":48,"unit":"px"}' ),
					'typography_font_weight'             => array( 'type' => 'select', 'default' => '', 'options' => array( '100', '200', '300', '400', '500', '600', '700', '800', '900' ), 'description' => 'Number font weight' ),
					'typography_font_family'             => array( 'type' => 'font', 'default' => '', 'description' => 'Number font family' ),
					'title_typography_typography'        => array( 'type' => 'switcher', 'default' => '', 'description' => 'Enable custom title typography' ),
					'title_typography_font_size'         => array( 'type' => 'slider', 'default' => '', 'description' => 'Title font size' ),
					'title_typography_font_weight'       => array( 'type' => 'select', 'default' => '', 'description' => 'Title font weight' ),
				),
				'example'     => array(
					'id'         => 'abc12356',
					'elType'     => 'widget',
					'widgetType' => 'counter',
					'settings'   => array(
						'ending_number' => 500,
						'prefix'        => '$',
						'suffix'        => 'K+',
						'title'         => 'Revenue',
					),
				),
				'common_mistakes' => array(
					'Using "number" or "value" instead of "ending_number"',
					'Using "label" instead of "title"',
				),
			),

			'progress'        => array(
				'description' => 'Animated progress bar with label and percentage.',
				'category'    => 'basic',
				'settings'    => array(
					'title'                     => array( 'type' => 'text', 'default' => '', 'description' => 'Progress bar label' ),
					'percent'                   => array( 'type' => 'slider', 'default' => array( 'size' => 50 ), 'description' => 'Percentage value: {"size":75}' ),
					'display_percentage'        => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show percentage text' ),
					'inner_text'                => array( 'type' => 'text', 'default' => '', 'description' => 'Text inside the progress bar' ),
					'bar_color'                 => array( 'type' => 'color', 'default' => '', 'description' => 'Progress bar color' ),
					'bar_bg_color'              => array( 'type' => 'color', 'default' => '', 'description' => 'Progress bar background color' ),
					'title_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Title color' ),
				),
				'example'     => array(
					'id'         => 'abc12357',
					'elType'     => 'widget',
					'widgetType' => 'progress',
					'settings'   => array(
						'title'   => 'WordPress',
						'percent' => array( 'size' => 90 ),
					),
				),
				'common_mistakes' => array(
					'Using "percentage" instead of "percent"',
					'Passing a plain number instead of {"size":N} for percent',
				),
			),

			'testimonial'     => array(
				'description' => 'Customer testimonial with image, name, title, and quote text.',
				'category'    => 'basic',
				'settings'    => array(
					'testimonial_content'       => array( 'type' => 'textarea', 'default' => '', 'description' => 'Testimonial text content' ),
					'testimonial_image'         => array( 'type' => 'media', 'default' => '', 'description' => 'Author image object' ),
					'testimonial_name'          => array( 'type' => 'text', 'default' => 'John Doe', 'description' => 'Author name' ),
					'testimonial_job'           => array( 'type' => 'text', 'default' => 'Designer', 'description' => 'Author job title' ),
					'testimonial_image_position' => array( 'type' => 'select', 'default' => 'aside', 'options' => array( 'aside', 'top' ), 'description' => 'Image position' ),
					'testimonial_alignment'     => array( 'type' => 'choose', 'default' => 'center', 'options' => array( 'left', 'center', 'right' ), 'description' => 'Content alignment' ),
					'content_color'             => array( 'type' => 'color', 'default' => '', 'description' => 'Content text color' ),
					'name_color'                => array( 'type' => 'color', 'default' => '', 'description' => 'Name color' ),
					'job_color'                 => array( 'type' => 'color', 'default' => '', 'description' => 'Job title color' ),
				),
				'example'     => array(
					'id'         => 'abc12358',
					'elType'     => 'widget',
					'widgetType' => 'testimonial',
					'settings'   => array(
						'testimonial_content' => 'This product changed my life! Highly recommended.',
						'testimonial_name'    => 'Jane Smith',
						'testimonial_job'     => 'CEO, TechCorp',
					),
				),
				'common_mistakes' => array(
					'Using "content" instead of "testimonial_content"',
					'Using "name" instead of "testimonial_name"',
					'Using "image" instead of "testimonial_image"',
				),
			),

			'tabs'            => array(
				'description' => 'Tabbed content with multiple panels.',
				'category'    => 'basic',
				'settings'    => array(
					'tabs'                      => array( 'type' => 'repeater', 'default' => '', 'description' => 'Array of tab items: [{"tab_title":"Tab 1","tab_content":"Content 1"}, ...]' ),
					'type'                      => array( 'type' => 'select', 'default' => 'horizontal', 'options' => array( 'horizontal', 'vertical' ), 'description' => 'Tab orientation' ),
					'tab_text_color'            => array( 'type' => 'color', 'default' => '', 'description' => 'Tab title color' ),
					'tab_active_color'          => array( 'type' => 'color', 'default' => '', 'description' => 'Active tab color' ),
					'content_color'             => array( 'type' => 'color', 'default' => '', 'description' => 'Content text color' ),
				),
				'example'     => array(
					'id'         => 'abc12359',
					'elType'     => 'widget',
					'widgetType' => 'tabs',
					'settings'   => array(
						'tabs' => array(
							array( 'tab_title' => 'Tab 1', 'tab_content' => 'Content for tab 1.' ),
							array( 'tab_title' => 'Tab 2', 'tab_content' => 'Content for tab 2.' ),
						),
					),
				),
				'common_mistakes' => array(
					'Using "items" instead of "tabs"',
					'Using "title" and "content" instead of "tab_title" and "tab_content" in repeater items',
				),
			),

			'accordion'       => array(
				'description' => 'Collapsible accordion sections (only one open at a time).',
				'category'    => 'basic',
				'settings'    => array(
					'tabs'                      => array( 'type' => 'repeater', 'default' => '', 'description' => 'Array of accordion items: [{"tab_title":"Section 1","tab_content":"Content 1"}, ...]' ),
					'selected_icon'             => array( 'type' => 'icons', 'default' => '', 'description' => 'Closed state icon' ),
					'selected_active_icon'      => array( 'type' => 'icons', 'default' => '', 'description' => 'Open/active state icon' ),
					'title_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Title color' ),
					'content_color'             => array( 'type' => 'color', 'default' => '', 'description' => 'Content color' ),
					'title_background'          => array( 'type' => 'color', 'default' => '', 'description' => 'Title background color' ),
					'icon_color'                => array( 'type' => 'color', 'default' => '', 'description' => 'Icon color' ),
				),
				'example'     => array(
					'id'         => 'abc12360',
					'elType'     => 'widget',
					'widgetType' => 'accordion',
					'settings'   => array(
						'tabs' => array(
							array( 'tab_title' => 'FAQ Question 1', 'tab_content' => 'Answer to question 1.' ),
							array( 'tab_title' => 'FAQ Question 2', 'tab_content' => 'Answer to question 2.' ),
						),
					),
				),
				'common_mistakes' => array(
					'Using "items" instead of "tabs" for the repeater field',
					'Using "title" and "content" instead of "tab_title" and "tab_content"',
				),
			),

			'toggle'          => array(
				'description' => 'Toggle sections (multiple can be open simultaneously, unlike accordion).',
				'category'    => 'basic',
				'settings'    => array(
					'tabs'                      => array( 'type' => 'repeater', 'default' => '', 'description' => 'Array of toggle items: [{"tab_title":"Section 1","tab_content":"Content 1"}, ...]' ),
					'selected_icon'             => array( 'type' => 'icons', 'default' => '', 'description' => 'Closed state icon' ),
					'selected_active_icon'      => array( 'type' => 'icons', 'default' => '', 'description' => 'Open state icon' ),
					'title_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Title color' ),
					'content_color'             => array( 'type' => 'color', 'default' => '', 'description' => 'Content color' ),
				),
				'example'     => array(
					'id'         => 'abc12361',
					'elType'     => 'widget',
					'widgetType' => 'toggle',
					'settings'   => array(
						'tabs' => array(
							array( 'tab_title' => 'Toggle 1', 'tab_content' => 'Content 1.' ),
							array( 'tab_title' => 'Toggle 2', 'tab_content' => 'Content 2.' ),
						),
					),
				),
				'common_mistakes' => array(
					'Same field names as accordion — uses "tabs" with "tab_title" and "tab_content"',
				),
			),

			'social-icons'    => array(
				'description' => 'Row of social media icons with links.',
				'category'    => 'basic',
				'settings'    => array(
					'social_icon_list'          => array( 'type' => 'repeater', 'default' => '', 'description' => 'Array of social icons: [{"social_icon":{"value":"fab fa-facebook","library":"fa-brands"},"link":{"url":"https://facebook.com"}}, ...]' ),
					'shape'                     => array( 'type' => 'select', 'default' => 'rounded', 'options' => array( 'rounded', 'square', 'circle' ), 'description' => 'Icon shape' ),
					'align'                     => array( 'type' => 'choose', 'default' => 'center', 'options' => array( 'left', 'center', 'right' ), 'description' => 'Alignment' ),
					'icon_color'                => array( 'type' => 'select', 'default' => 'default', 'options' => array( 'default', 'custom' ), 'description' => 'Color mode' ),
					'icon_primary_color'        => array( 'type' => 'color', 'default' => '', 'description' => 'Icon color (when custom)' ),
					'icon_secondary_color'      => array( 'type' => 'color', 'default' => '', 'description' => 'Background color (when custom)' ),
					'icon_size'                 => array( 'type' => 'slider', 'default' => '', 'description' => 'Icon size' ),
				),
				'example'     => array(
					'id'         => 'abc12362',
					'elType'     => 'widget',
					'widgetType' => 'social-icons',
					'settings'   => array(
						'social_icon_list' => array(
							array(
								'social_icon' => array( 'value' => 'fab fa-facebook', 'library' => 'fa-brands' ),
								'link'        => array( 'url' => 'https://facebook.com' ),
							),
							array(
								'social_icon' => array( 'value' => 'fab fa-twitter', 'library' => 'fa-brands' ),
								'link'        => array( 'url' => 'https://twitter.com' ),
							),
						),
					),
				),
				'common_mistakes' => array(
					'Using "icons" instead of "social_icon_list"',
					'Using "icon" instead of "social_icon" in repeater items',
				),
			),

			'alert'           => array(
				'description' => 'Alert/notification message box with type styling.',
				'category'    => 'basic',
				'settings'    => array(
					'alert_type'                => array( 'type' => 'select', 'default' => 'info', 'options' => array( 'info', 'success', 'warning', 'danger' ), 'description' => 'Alert type/color' ),
					'alert_title'               => array( 'type' => 'text', 'default' => '', 'description' => 'Alert title' ),
					'alert_description'         => array( 'type' => 'textarea', 'default' => '', 'description' => 'Alert description text' ),
					'show_dismiss'              => array( 'type' => 'select', 'default' => 'show', 'options' => array( 'show', 'hide' ), 'description' => 'Show dismiss button' ),
				),
				'example'     => array(
					'id'         => 'abc12363',
					'elType'     => 'widget',
					'widgetType' => 'alert',
					'settings'   => array(
						'alert_type'        => 'warning',
						'alert_title'       => 'Notice',
						'alert_description' => 'This feature is in beta.',
					),
				),
				'common_mistakes' => array(
					'Using "type" instead of "alert_type"',
					'Using "title" instead of "alert_title"',
					'Using "message" instead of "alert_description"',
				),
			),

			'html'            => array(
				'description' => 'Raw HTML code block.',
				'category'    => 'basic',
				'settings'    => array(
					'html'                      => array( 'type' => 'code', 'default' => '', 'description' => 'Raw HTML content' ),
				),
				'example'     => array(
					'id'         => 'abc12364',
					'elType'     => 'widget',
					'widgetType' => 'html',
					'settings'   => array(
						'html' => '<div class="custom-widget">Custom HTML here</div>',
					),
				),
				'common_mistakes' => array(
					'Using "content" or "code" instead of "html"',
				),
			),

			'shortcode'       => array(
				'description' => 'WordPress shortcode embed.',
				'category'    => 'basic',
				'settings'    => array(
					'shortcode'                 => array( 'type' => 'text', 'default' => '', 'description' => 'WordPress shortcode string, e.g. [contact-form-7 id="123"]' ),
				),
				'example'     => array(
					'id'         => 'abc12365',
					'elType'     => 'widget',
					'widgetType' => 'shortcode',
					'settings'   => array(
						'shortcode' => '[contact-form-7 id="123" title="Contact"]',
					),
				),
				'common_mistakes' => array(
					'Using "code" instead of "shortcode"',
				),
			),

			'menu-anchor'     => array(
				'description' => 'Invisible anchor point for scroll-to navigation.',
				'category'    => 'basic',
				'settings'    => array(
					'anchor'                    => array( 'type' => 'text', 'default' => '', 'description' => 'Anchor ID (used in links as #anchor-id)' ),
				),
				'example'     => array(
					'id'         => 'abc12366',
					'elType'     => 'widget',
					'widgetType' => 'menu-anchor',
					'settings'   => array(
						'anchor' => 'about-section',
					),
				),
				'common_mistakes' => array(
					'Including the # symbol in the anchor value',
					'Using "id" instead of "anchor"',
				),
			),

			'sidebar'         => array(
				'description' => 'Display a WordPress widget sidebar/area.',
				'category'    => 'basic',
				'settings'    => array(
					'sidebar'                   => array( 'type' => 'select', 'default' => '', 'description' => 'Sidebar ID to display' ),
				),
				'example'     => array(
					'id'         => 'abc12367',
					'elType'     => 'widget',
					'widgetType' => 'sidebar',
					'settings'   => array(
						'sidebar' => 'sidebar-1',
					),
				),
				'common_mistakes' => array(
					'Using "widget_area" instead of "sidebar"',
				),
			),

			'icon-list'       => array(
				'description' => 'Vertical or horizontal list with icons for each item.',
				'category'    => 'basic',
				'settings'    => array(
					'icon_list'                 => array( 'type' => 'repeater', 'default' => '', 'description' => 'Array of list items: [{"text":"Item 1","selected_icon":{"value":"fas fa-check","library":"fa-solid"},"link":{"url":""}}, ...]' ),
					'view'                      => array( 'type' => 'select', 'default' => '', 'options' => array( '', 'inline' ), 'description' => 'Layout: empty for vertical, "inline" for horizontal' ),
					'space_between'             => array( 'type' => 'slider', 'default' => '', 'description' => 'Space between items' ),
					'icon_color'                => array( 'type' => 'color', 'default' => '', 'description' => 'Icon color' ),
					'icon_size'                 => array( 'type' => 'slider', 'default' => '', 'description' => 'Icon size' ),
					'text_color'                => array( 'type' => 'color', 'default' => '', 'description' => 'Text color' ),
					'text_indent'               => array( 'type' => 'slider', 'default' => '', 'description' => 'Space between icon and text' ),
				),
				'example'     => array(
					'id'         => 'abc12368',
					'elType'     => 'widget',
					'widgetType' => 'icon-list',
					'settings'   => array(
						'icon_list' => array(
							array(
								'text'          => 'Feature One',
								'selected_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ),
							),
							array(
								'text'          => 'Feature Two',
								'selected_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ),
							),
						),
					),
				),
				'common_mistakes' => array(
					'Using "items" or "list" instead of "icon_list"',
					'Using "icon" instead of "selected_icon" in repeater items',
				),
			),

			'star-rating'     => array(
				'description' => 'Star rating display widget.',
				'category'    => 'basic',
				'settings'    => array(
					'rating_scale'              => array( 'type' => 'select', 'default' => '5', 'options' => array( '5', '10' ), 'description' => 'Rating scale' ),
					'rating'                    => array( 'type' => 'number', 'default' => 5, 'description' => 'Rating value' ),
					'star_style'                => array( 'type' => 'select', 'default' => 'star_fontawesome', 'options' => array( 'star_fontawesome', 'star_unicode' ), 'description' => 'Star icon style' ),
					'title'                     => array( 'type' => 'text', 'default' => '', 'description' => 'Title text beside stars' ),
					'icon_size'                 => array( 'type' => 'slider', 'default' => '', 'description' => 'Star size' ),
					'icon_space'                => array( 'type' => 'slider', 'default' => '', 'description' => 'Space between stars' ),
					'stars_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Star color' ),
					'stars_unmarked_color'      => array( 'type' => 'color', 'default' => '', 'description' => 'Unmarked star color' ),
				),
				'example'     => array(
					'id'         => 'abc12369',
					'elType'     => 'widget',
					'widgetType' => 'star-rating',
					'settings'   => array(
						'rating' => 4.5,
						'title'  => 'Customer Rating',
					),
				),
				'common_mistakes' => array(
					'Using "stars" or "value" instead of "rating"',
				),
			),

			'basic-gallery'   => array(
				'description' => 'Simple image gallery grid.',
				'category'    => 'basic',
				'settings'    => array(
					'gallery'                   => array( 'type' => 'gallery', 'default' => '', 'description' => 'Array of image objects: [{"id":123,"url":"https://..."}]' ),
					'gallery_columns'           => array( 'type' => 'select', 'default' => '4', 'options' => array( '1', '2', '3', '4', '5', '6', '7', '8', '9', '10' ), 'description' => 'Number of columns' ),
					'gallery_link'              => array( 'type' => 'select', 'default' => 'file', 'options' => array( 'file', 'attachment', 'none' ), 'description' => 'Link images to' ),
					'gallery_rand'              => array( 'type' => 'select', 'default' => '', 'options' => array( '', 'rand' ), 'description' => 'Randomize order' ),
					'open_lightbox'             => array( 'type' => 'select', 'default' => 'default', 'options' => array( 'default', 'yes', 'no' ), 'description' => 'Open in lightbox' ),
				),
				'example'     => array(
					'id'         => 'abc12370',
					'elType'     => 'widget',
					'widgetType' => 'basic-gallery',
					'settings'   => array(
						'gallery'         => array(
							array( 'id' => '', 'url' => 'https://example.com/img1.jpg' ),
							array( 'id' => '', 'url' => 'https://example.com/img2.jpg' ),
						),
						'gallery_columns' => '3',
					),
				),
				'common_mistakes' => array(
					'Using "images" instead of "gallery"',
					'Using "columns" instead of "gallery_columns"',
				),
			),

			'image-carousel'  => array(
				'description' => 'Image carousel/slider with navigation arrows and dots.',
				'category'    => 'basic',
				'settings'    => array(
					'carousel'                  => array( 'type' => 'gallery', 'default' => '', 'description' => 'Array of image objects for the carousel' ),
					'slides_to_show'            => array( 'type' => 'select', 'default' => '3', 'options' => array( '1', '2', '3', '4', '5', '6', '7', '8', '9', '10' ), 'description' => 'Slides visible at once' ),
					'slides_to_scroll'          => array( 'type' => 'select', 'default' => '1', 'description' => 'Slides to scroll per navigation' ),
					'image_stretch'             => array( 'type' => 'select', 'default' => 'no', 'options' => array( 'no', 'yes' ), 'description' => 'Stretch images to fill' ),
					'navigation'                => array( 'type' => 'select', 'default' => 'both', 'options' => array( 'both', 'arrows', 'dots', 'none' ), 'description' => 'Navigation type' ),
					'autoplay'                  => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Auto-play slides' ),
					'autoplay_speed'            => array( 'type' => 'number', 'default' => 5000, 'description' => 'Auto-play speed in ms' ),
					'infinite'                  => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Infinite loop' ),
					'pause_on_hover'            => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Pause auto-play on hover' ),
					'speed'                     => array( 'type' => 'number', 'default' => 500, 'description' => 'Transition speed in ms' ),
					'link_to'                   => array( 'type' => 'select', 'default' => 'none', 'options' => array( 'none', 'file', 'custom' ), 'description' => 'Link images to' ),
					'caption_type'              => array( 'type' => 'select', 'default' => '', 'options' => array( '', 'title', 'caption', 'description' ), 'description' => 'Show image caption' ),
				),
				'example'     => array(
					'id'         => 'abc12371',
					'elType'     => 'widget',
					'widgetType' => 'image-carousel',
					'settings'   => array(
						'carousel'       => array(
							array( 'id' => '', 'url' => 'https://example.com/slide1.jpg' ),
							array( 'id' => '', 'url' => 'https://example.com/slide2.jpg' ),
						),
						'slides_to_show' => '1',
						'navigation'     => 'both',
						'autoplay'       => 'yes',
					),
				),
				'common_mistakes' => array(
					'Using "images" or "slides" instead of "carousel"',
					'Using "columns" instead of "slides_to_show"',
				),
			),
		);
	}

	/**
	 * Get pro widget schemas.
	 *
	 * @return array Associative array keyed by widget type.
	 */
	public static function get_pro_widgets() {
		return array(
			'form'               => array(
				'description' => 'Contact/lead form with customizable fields and email actions. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'form_name'                 => array( 'type' => 'text', 'default' => 'New Form', 'description' => 'Form name for admin reference' ),
					'form_fields'               => array( 'type' => 'repeater', 'default' => '', 'description' => 'Array of form fields: [{"field_type":"text","field_label":"Name","placeholder":"Your Name","required":"true","width":"100"}, ...]' ),
					'button_text'               => array( 'type' => 'text', 'default' => 'Send', 'description' => 'Submit button text' ),
					'button_size'               => array( 'type' => 'select', 'default' => 'sm', 'options' => array( 'xs', 'sm', 'md', 'lg', 'xl' ), 'description' => 'Button size' ),
					'button_align'              => array( 'type' => 'choose', 'default' => '', 'description' => 'Button alignment' ),
					'button_color'              => array( 'type' => 'color', 'default' => '', 'description' => 'Submit button text color' ),
					'button_background_color'   => array( 'type' => 'color', 'default' => '', 'description' => 'Submit button background color' ),
					'button_border_color'       => array( 'type' => 'color', 'default' => '', 'description' => 'Submit button border color' ),
					'button_hover_color'        => array( 'type' => 'color', 'default' => '', 'description' => 'Submit button text hover color' ),
					'button_background_hover_color' => array( 'type' => 'color', 'default' => '', 'description' => 'Submit button background hover color' ),
					'email_to'                  => array( 'type' => 'text', 'default' => '', 'description' => 'Recipient email address' ),
					'email_subject'             => array( 'type' => 'text', 'default' => '', 'description' => 'Email subject' ),
					'success_message'           => array( 'type' => 'text', 'default' => 'The form was sent successfully.', 'description' => 'Success message' ),
					'error_message'             => array( 'type' => 'text', 'default' => 'An error occurred.', 'description' => 'Error message' ),
					'required_message'          => array( 'type' => 'text', 'default' => 'This field is required.', 'description' => 'Required field message' ),
				),
				'example'     => array(
					'id'         => 'abc12372',
					'elType'     => 'widget',
					'widgetType' => 'form',
					'settings'   => array(
						'form_name'    => 'Contact Form',
						'form_fields'  => array(
							array( 'field_type' => 'text', 'field_label' => 'Name', 'placeholder' => 'Your Name', 'required' => 'true', 'width' => '50' ),
							array( 'field_type' => 'email', 'field_label' => 'Email', 'placeholder' => 'you@example.com', 'required' => 'true', 'width' => '50' ),
							array( 'field_type' => 'textarea', 'field_label' => 'Message', 'placeholder' => 'Your message...', 'required' => '', 'width' => '100' ),
						),
						'button_text'  => 'Send Message',
					),
				),
				'common_mistakes' => array(
					'Using "fields" instead of "form_fields"',
					'Using "submit_text" instead of "button_text"',
					'Forgetting "field_type" in form field items',
				),
			),

			'slides'             => array(
				'description' => 'Full-width slider/slideshow with heading, description, and button on each slide. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'slides'                    => array( 'type' => 'repeater', 'default' => '', 'description' => 'Array of slides: [{"heading":"Slide 1","description":"Text","button_text":"CTA","link":{"url":"..."},"background_image":{"url":"..."}}, ...]' ),
					'slides_height'             => array( 'type' => 'select', 'default' => 'full_screen', 'options' => array( 'full_screen', 'min-height' ), 'description' => 'Slide height mode' ),
					'slides_min_height'         => array( 'type' => 'slider', 'default' => '', 'description' => 'Minimum slide height' ),
					'navigation'                => array( 'type' => 'select', 'default' => 'both', 'options' => array( 'both', 'arrows', 'dots', 'none' ), 'description' => 'Navigation type' ),
					'autoplay'                  => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Auto-play' ),
					'autoplay_speed'            => array( 'type' => 'number', 'default' => 5000, 'description' => 'Auto-play interval in ms' ),
					'pause_on_hover'            => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Pause on hover' ),
					'infinite'                  => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Infinite loop' ),
					'transition_speed'          => array( 'type' => 'number', 'default' => 500, 'description' => 'Transition speed in ms' ),
					'content_animation'         => array( 'type' => 'select', 'default' => 'fadeInUp', 'description' => 'Content entrance animation' ),
				),
				'example'     => array(
					'id'         => 'abc12373',
					'elType'     => 'widget',
					'widgetType' => 'slides',
					'settings'   => array(
						'slides' => array(
							array(
								'heading'          => 'Welcome',
								'description'      => 'Discover our services',
								'button_text'      => 'Learn More',
								'link'             => array( 'url' => '#about' ),
								'background_color' => '#1a1a2e',
							),
						),
					),
				),
				'common_mistakes' => array(
					'Using "items" instead of "slides"',
					'Forgetting that each slide has "heading" not "title"',
				),
			),

			'nav-menu'           => array(
				'description' => 'WordPress navigation menu display. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'menu'                              => array( 'type' => 'select', 'default' => '', 'description' => 'WordPress menu ID or slug' ),
					'layout'                            => array( 'type' => 'select', 'default' => 'horizontal', 'options' => array( 'horizontal', 'vertical', 'dropdown' ), 'description' => 'Menu layout' ),
					'align_items'                       => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right', 'justify' ), 'description' => 'Menu alignment' ),
					'pointer'                           => array( 'type' => 'select', 'default' => 'underline', 'options' => array( 'none', 'underline', 'overline', 'double-line', 'framed', 'background', 'text' ), 'description' => 'Hover indicator style' ),
					'submenu_icon'                      => array( 'type' => 'icons', 'default' => '', 'description' => 'Submenu indicator icon' ),
					'submenu_indicator'                 => array( 'type' => 'select', 'default' => 'classic', 'options' => array( 'none', 'classic', 'chevron', 'angle', 'plus' ), 'description' => 'Dropdown arrow indicator style' ),
					'toggle'                            => array( 'type' => 'select', 'default' => 'burger', 'options' => array( 'burger', 'burger-arrow' ), 'description' => 'Mobile toggle type' ),
					'breakpoint'                        => array( 'type' => 'select', 'default' => 'tablet', 'options' => array( 'mobile', 'tablet', 'none' ), 'description' => 'Mobile menu breakpoint' ),
					'color_menu_item'                   => array( 'type' => 'color', 'default' => '', 'description' => 'Menu item text color' ),
					'color_menu_item_hover'             => array( 'type' => 'color', 'default' => '', 'description' => 'Hover text color' ),
					'color_menu_item_active'            => array( 'type' => 'color', 'default' => '', 'description' => 'Active item text color' ),
					'color_dropdown_item'               => array( 'type' => 'color', 'default' => '', 'description' => 'Dropdown item text color' ),
					'color_dropdown_item_hover'         => array( 'type' => 'color', 'default' => '', 'description' => 'Dropdown item hover text color' ),
					'background_color_dropdown_item'    => array( 'type' => 'color', 'default' => '', 'description' => 'Dropdown item background color' ),
					'background_color_dropdown_item_hover' => array( 'type' => 'color', 'default' => '', 'description' => 'Dropdown item hover background color' ),
					'toggle_color'                      => array( 'type' => 'color', 'default' => '', 'description' => 'Mobile hamburger icon color' ),
					'toggle_size'                       => array( 'type' => 'slider', 'default' => '', 'description' => 'Mobile hamburger icon size with unit, e.g. {"size":22,"unit":"px"}' ),
				),
				'example'     => array(
					'id'         => 'abc12374',
					'elType'     => 'widget',
					'widgetType' => 'nav-menu',
					'settings'   => array(
						'menu'   => 'main-menu',
						'layout' => 'horizontal',
					),
				),
				'common_mistakes' => array(
					'Using "navigation" instead of "menu"',
					'Using "orientation" instead of "layout"',
				),
			),

			'animated-headline'  => array(
				'description' => 'Heading with animated rotating/highlighted text. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'headline_style'            => array( 'type' => 'select', 'default' => 'highlight', 'options' => array( 'highlight', 'rotate' ), 'description' => 'Animation style' ),
					'animation_type'            => array( 'type' => 'select', 'default' => 'typing', 'options' => array( 'typing', 'clip', 'flip', 'swirl', 'blinds', 'drop-in', 'wave', 'slide', 'slide-down' ), 'description' => 'Rotation animation (when style is "rotate")' ),
					'marker'                    => array( 'type' => 'select', 'default' => 'circle', 'options' => array( 'circle', 'curly', 'underline', 'double', 'double-underline', 'underline-zigzag', 'diagonal', 'strikethrough', 'x' ), 'description' => 'Highlight marker shape' ),
					'before_text'               => array( 'type' => 'text', 'default' => 'This page is', 'description' => 'Text before animated part' ),
					'highlighted_text'          => array( 'type' => 'text', 'default' => 'Amazing', 'description' => 'Highlighted/animated text' ),
					'after_text'                => array( 'type' => 'text', 'default' => '', 'description' => 'Text after animated part' ),
					'rotating_text'             => array( 'type' => 'textarea', 'default' => "Better\nBigger\nFaster", 'description' => 'Rotating text entries (one per line)' ),
					'header_size'               => array( 'type' => 'select', 'default' => 'h3', 'options' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), 'description' => 'HTML tag' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Link URL' ),
				),
				'example'     => array(
					'id'         => 'abc12375',
					'elType'     => 'widget',
					'widgetType' => 'animated-headline',
					'settings'   => array(
						'headline_style'   => 'rotate',
						'animation_type'   => 'typing',
						'before_text'      => 'We build',
						'rotating_text'    => "Websites\nApps\nBrands",
						'header_size'      => 'h2',
					),
				),
				'common_mistakes' => array(
					'Using "text" instead of "before_text"/"highlighted_text"/"rotating_text"',
					'Forgetting to set "headline_style" to "rotate" when using "rotating_text"',
				),
			),

			'price-list'         => array(
				'description' => 'Menu/price list with items, descriptions, and prices. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'price_list'                => array( 'type' => 'repeater', 'default' => '', 'description' => 'Array of items: [{"title":"Item","price":"$10","description":"Desc","link":{"url":""},"image":{"url":""}}, ...]' ),
					'title_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Title color' ),
					'price_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Price color' ),
					'description_color'         => array( 'type' => 'color', 'default' => '', 'description' => 'Description color' ),
				),
				'example'     => array(
					'id'         => 'abc12376',
					'elType'     => 'widget',
					'widgetType' => 'price-list',
					'settings'   => array(
						'price_list' => array(
							array( 'title' => 'Espresso', 'price' => '$3.50', 'description' => 'Rich and bold.' ),
							array( 'title' => 'Latte', 'price' => '$4.50', 'description' => 'Smooth and creamy.' ),
						),
					),
				),
				'common_mistakes' => array(
					'Using "items" or "menu" instead of "price_list"',
				),
			),

			'price-table'        => array(
				'description' => 'Pricing table with features list and CTA button. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'heading'                   => array( 'type' => 'text', 'default' => 'Enter your title', 'description' => 'Plan name' ),
					'sub_heading'               => array( 'type' => 'text', 'default' => 'Enter your sub title', 'description' => 'Plan subtitle' ),
					'currency_symbol'           => array( 'type' => 'select', 'default' => 'dollar', 'options' => array( 'dollar', 'euro', 'pound', 'yen', 'krona', 'peso', 'franc', 'lira', 'rupee', 'baht', 'shekel', 'won', 'real', 'custom' ), 'description' => 'Currency symbol preset' ),
					'currency_symbol_custom'    => array( 'type' => 'text', 'default' => '', 'description' => 'Custom currency symbol' ),
					'price'                     => array( 'type' => 'text', 'default' => '39.99', 'description' => 'Price amount' ),
					'currency_format'           => array( 'type' => 'select', 'default' => '', 'options' => array( '', ',', '.' ), 'description' => 'Price decimal separator' ),
					'sale'                      => array( 'type' => 'switcher', 'default' => '', 'description' => 'Show sale/original price' ),
					'original_price'            => array( 'type' => 'text', 'default' => '', 'description' => 'Original price (when on sale)' ),
					'period'                    => array( 'type' => 'text', 'default' => '/mo', 'description' => 'Billing period text' ),
					'features_list'             => array( 'type' => 'repeater', 'default' => '', 'description' => 'Feature list: [{"item_text":"Feature 1","selected_item_icon":{"value":"fas fa-check","library":"fa-solid"}}, ...]' ),
					'button_text'               => array( 'type' => 'text', 'default' => 'Click Here', 'description' => 'CTA button text' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Button link' ),
					'ribbon_title'              => array( 'type' => 'text', 'default' => 'Popular', 'description' => 'Ribbon text' ),
					'show_ribbon'               => array( 'type' => 'switcher', 'default' => '', 'description' => 'Show corner ribbon' ),
					'header_bg_color'           => array( 'type' => 'color', 'default' => '', 'description' => 'Header background color' ),
				),
				'example'     => array(
					'id'         => 'abc12377',
					'elType'     => 'widget',
					'widgetType' => 'price-table',
					'settings'   => array(
						'heading'         => 'Pro Plan',
						'price'           => '49',
						'period'          => '/mo',
						'currency_symbol' => 'dollar',
						'features_list'   => array(
							array( 'item_text' => 'Unlimited projects', 'selected_item_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ) ),
							array( 'item_text' => 'Priority support', 'selected_item_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ) ),
						),
						'button_text'     => 'Get Started',
						'link'            => array( 'url' => '#signup' ),
					),
				),
				'common_mistakes' => array(
					'Using "title" instead of "heading"',
					'Using "features" instead of "features_list"',
					'Using "cta_text" instead of "button_text"',
				),
			),

			'flip-box'           => array(
				'description' => 'Two-sided box with flip animation on hover. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'title_text_a'              => array( 'type' => 'text', 'default' => 'This is the heading', 'description' => 'Front side title' ),
					'description_text_a'        => array( 'type' => 'textarea', 'default' => '', 'description' => 'Front side description' ),
					'graphic_element'           => array( 'type' => 'select', 'default' => 'icon', 'options' => array( 'none', 'image', 'icon' ), 'description' => 'Front graphic type' ),
					'selected_icon'             => array( 'type' => 'icons', 'default' => '', 'description' => 'Front icon' ),
					'image'                     => array( 'type' => 'media', 'default' => '', 'description' => 'Front image' ),
					'title_text_b'              => array( 'type' => 'text', 'default' => 'This is the heading', 'description' => 'Back side title' ),
					'description_text_b'        => array( 'type' => 'textarea', 'default' => '', 'description' => 'Back side description' ),
					'button_text'               => array( 'type' => 'text', 'default' => 'Click Here', 'description' => 'Back button text' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Back button link' ),
					'flip_effect'               => array( 'type' => 'select', 'default' => 'flip', 'options' => array( 'flip', 'slide', 'push', 'zoom-in', 'zoom-out', 'fade' ), 'description' => 'Flip animation type' ),
					'flip_direction'            => array( 'type' => 'select', 'default' => 'left', 'options' => array( 'left', 'right', 'up', 'down' ), 'description' => 'Flip direction' ),
					'background_color_a'        => array( 'type' => 'color', 'default' => '', 'description' => 'Front background color' ),
					'background_color_b'        => array( 'type' => 'color', 'default' => '', 'description' => 'Back background color' ),
				),
				'example'     => array(
					'id'         => 'abc12378',
					'elType'     => 'widget',
					'widgetType' => 'flip-box',
					'settings'   => array(
						'title_text_a'       => 'Front Title',
						'description_text_a' => 'Hover to flip.',
						'selected_icon'      => array( 'value' => 'fas fa-star', 'library' => 'fa-solid' ),
						'title_text_b'       => 'Back Title',
						'description_text_b' => 'More details here.',
						'button_text'        => 'Learn More',
					),
				),
				'common_mistakes' => array(
					'Using "front_title_text" instead of "title_text_a"',
					'Using "back_title_text" instead of "title_text_b"',
					'Using "front_description_text" instead of "description_text_a"',
				),
			),

			'call-to-action'     => array(
				'description' => 'Combined image/background, heading, description, and CTA button. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'skin'                      => array( 'type' => 'select', 'default' => 'classic', 'options' => array( 'classic', 'cover' ), 'description' => 'Layout skin' ),
					'title'                     => array( 'type' => 'text', 'default' => 'This is the heading', 'description' => 'Title text' ),
					'description'               => array( 'type' => 'textarea', 'default' => '', 'description' => 'Description text' ),
					'button'                    => array( 'type' => 'text', 'default' => 'Click Here', 'description' => 'Button text' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Button/card link' ),
					'bg_image'                  => array( 'type' => 'media', 'default' => '', 'description' => 'Background image' ),
					'ribbon_title'              => array( 'type' => 'text', 'default' => '', 'description' => 'Ribbon text' ),
					'title_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Title color' ),
					'description_color'         => array( 'type' => 'color', 'default' => '', 'description' => 'Description color' ),
				),
				'example'     => array(
					'id'         => 'abc12379',
					'elType'     => 'widget',
					'widgetType' => 'call-to-action',
					'settings'   => array(
						'title'       => 'Get Started Today',
						'description' => 'Sign up for a free trial.',
						'button'      => 'Start Free Trial',
						'link'        => array( 'url' => '#signup' ),
					),
				),
				'common_mistakes' => array(
					'Using "button_text" instead of "button" for CTA text',
					'Using "heading" instead of "title"',
				),
			),

			'media-carousel'     => array(
				'description' => 'Advanced image/video carousel with skins. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'slides'                    => array( 'type' => 'repeater', 'default' => '', 'description' => 'Carousel slides: [{"type":"image","image":{"url":"..."}}, ...]' ),
					'skin'                      => array( 'type' => 'select', 'default' => 'carousel', 'options' => array( 'carousel', 'slideshow', 'coverflow' ), 'description' => 'Carousel skin/style' ),
					'slides_per_view'           => array( 'type' => 'number', 'default' => 3, 'description' => 'Visible slides count' ),
					'autoplay'                  => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Auto-play slides' ),
					'autoplay_speed'            => array( 'type' => 'number', 'default' => 5000, 'description' => 'Auto-play speed in ms' ),
					'navigation'                => array( 'type' => 'select', 'default' => 'both', 'options' => array( 'both', 'arrows', 'dots', 'none' ), 'description' => 'Navigation type' ),
					'infinite'                  => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Infinite loop' ),
				),
				'example'     => array(
					'id'         => 'abc12380',
					'elType'     => 'widget',
					'widgetType' => 'media-carousel',
					'settings'   => array(
						'slides' => array(
							array( 'type' => 'image', 'image' => array( 'url' => 'https://example.com/img1.jpg' ) ),
							array( 'type' => 'image', 'image' => array( 'url' => 'https://example.com/img2.jpg' ) ),
						),
						'skin'   => 'carousel',
					),
				),
				'common_mistakes' => array(
					'Using "images" instead of "slides"',
					'Using "slides_to_show" instead of "slides_per_view"',
				),
			),

			'posts'              => array(
				'description' => 'Display posts grid/list with query controls. Requires Elementor Pro. IMPORTANT: Layout keys are skin-prefixed (e.g. classic_columns, cards_columns). Query keys use posts_ prefix (e.g. posts_post_type). Default skin is "classic".',
				'category'    => 'pro',
				'settings'    => array(
					'_skin'                         => array( 'type' => 'select', 'default' => 'classic', 'options' => array( 'classic', 'cards', 'full_content' ), 'description' => 'Display skin — all layout keys below must be prefixed with this skin name (e.g. classic_columns)' ),
					'classic_columns'               => array( 'type' => 'select', 'default' => '3', 'options' => array( '1', '2', '3', '4', '5', '6' ), 'description' => 'Grid columns (skin-prefixed: classic_columns, cards_columns)' ),
					'classic_posts_per_page'        => array( 'type' => 'number', 'default' => 6, 'description' => 'Posts per page (skin-prefixed: classic_posts_per_page, cards_posts_per_page)' ),
					'classic_thumbnail'             => array( 'type' => 'select', 'default' => 'top', 'options' => array( 'top', 'left', 'right', 'none' ), 'description' => 'Image position (skin-prefixed)' ),
					'classic_masonry'               => array( 'type' => 'switcher', 'default' => '', 'description' => 'Enable masonry layout (skin-prefixed)' ),
					'classic_thumbnail_size_size'    => array( 'type' => 'select', 'default' => 'medium', 'description' => 'Image resolution (skin-prefixed)' ),
					'classic_show_title'            => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show post title (skin-prefixed)' ),
					'classic_show_excerpt'          => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show excerpt (skin-prefixed)' ),
					'classic_excerpt_length'        => array( 'type' => 'number', 'default' => 25, 'description' => 'Excerpt word count (skin-prefixed)' ),
					'classic_show_read_more'        => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show read more link (skin-prefixed)' ),
					'classic_read_more_text'        => array( 'type' => 'text', 'default' => 'Read More', 'description' => 'Read more text (skin-prefixed)' ),
					'classic_show_date'             => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show date (skin-prefixed)' ),
					'posts_post_type'               => array( 'type' => 'select', 'default' => 'post', 'description' => 'Post type to query. ALWAYS use posts_post_type (not post_type). Options depend on site CPTs: post, page, product, lp_course, etc.' ),
					'posts_orderby'                 => array( 'type' => 'select', 'default' => 'date', 'options' => array( 'date', 'title', 'rand', 'menu_order', 'comment_count' ), 'description' => 'Sort order' ),
					'posts_order'                   => array( 'type' => 'select', 'default' => 'desc', 'options' => array( 'asc', 'desc' ), 'description' => 'Sort direction' ),
					'pagination_type'               => array( 'type' => 'select', 'default' => '', 'options' => array( '', 'numbers', 'prev_next', 'numbers_and_prev_next', 'load_more_on_click', 'load_more_infinite_scroll' ), 'description' => 'Pagination type' ),
				),
				'example'     => array(
					'id'         => 'abc12381',
					'elType'     => 'widget',
					'widgetType' => 'posts',
					'settings'   => array(
						'_skin'                  => 'classic',
						'classic_posts_per_page' => 6,
						'classic_columns'        => '3',
						'classic_show_excerpt'   => 'yes',
						'posts_post_type'        => 'post',
					),
				),
				'common_mistakes' => array(
					'Using "post_type" instead of "posts_post_type" — query keys need posts_ prefix',
					'Using "columns" instead of "classic_columns" — layout keys need skin prefix',
					'Using "posts_per_page" instead of "classic_posts_per_page" — layout keys need skin prefix',
					'Forgetting the skin prefix when changing _skin from classic to cards (all layout keys change)',
				),
			),

			'portfolio'          => array(
				'description' => 'Filterable project/portfolio grid. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'posts_per_page'            => array( 'type' => 'number', 'default' => 6, 'description' => 'Items per page' ),
					'columns'                   => array( 'type' => 'select', 'default' => '3', 'options' => array( '1', '2', '3', '4', '5', '6' ), 'description' => 'Grid columns' ),
					'show_filter_bar'           => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show category filter' ),
					'show_title'                => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show item title' ),
					'masonry'                   => array( 'type' => 'switcher', 'default' => '', 'description' => 'Enable masonry layout' ),
				),
				'example'     => array(
					'id'         => 'abc12382',
					'elType'     => 'widget',
					'widgetType' => 'portfolio',
					'settings'   => array(
						'columns'         => '3',
						'show_filter_bar' => 'yes',
					),
				),
				'common_mistakes' => array(),
			),

			'countdown'          => array(
				'description' => 'Countdown timer to a specific date. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'countdown_type'            => array( 'type' => 'select', 'default' => 'due_date', 'options' => array( 'due_date', 'evergreen' ), 'description' => 'Countdown type' ),
					'due_date'                  => array( 'type' => 'date_time', 'default' => '', 'description' => 'Target date/time (Y-m-d H:i format)' ),
					'show_days'                 => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show days' ),
					'show_hours'                => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show hours' ),
					'show_minutes'              => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show minutes' ),
					'show_seconds'              => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show seconds' ),
					'show_labels'               => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show unit labels' ),
					'label_days'                => array( 'type' => 'text', 'default' => 'Days', 'description' => 'Days label' ),
					'label_hours'               => array( 'type' => 'text', 'default' => 'Hours', 'description' => 'Hours label' ),
					'label_minutes'             => array( 'type' => 'text', 'default' => 'Minutes', 'description' => 'Minutes label' ),
					'label_seconds'             => array( 'type' => 'text', 'default' => 'Seconds', 'description' => 'Seconds label' ),
					'expire_actions'            => array( 'type' => 'select', 'default' => '', 'description' => 'Action when countdown expires' ),
				),
				'example'     => array(
					'id'         => 'abc12383',
					'elType'     => 'widget',
					'widgetType' => 'countdown',
					'settings'   => array(
						'countdown_type' => 'due_date',
						'due_date'       => '2026-12-31 23:59',
					),
				),
				'common_mistakes' => array(
					'Using "date" or "target_date" instead of "due_date"',
					'Using wrong date format (must be Y-m-d H:i)',
				),
			),

			'share-buttons'      => array(
				'description' => 'Social sharing buttons row. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'share_buttons'             => array( 'type' => 'repeater', 'default' => '', 'description' => 'Array of share buttons: [{"button":"facebook"}, {"button":"twitter"}, ...]' ),
					'view'                      => array( 'type' => 'select', 'default' => 'icon-text', 'options' => array( 'icon-text', 'icon', 'text' ), 'description' => 'Button display mode' ),
					'skin'                      => array( 'type' => 'select', 'default' => 'gradient', 'options' => array( 'gradient', 'minimal', 'framed', 'boxed', 'flat' ), 'description' => 'Button style' ),
					'shape'                     => array( 'type' => 'select', 'default' => 'square', 'options' => array( 'square', 'rounded', 'circle' ), 'description' => 'Button shape' ),
					'columns'                   => array( 'type' => 'select', 'default' => '0', 'description' => 'Number of columns (0 = auto)' ),
				),
				'example'     => array(
					'id'         => 'abc12384',
					'elType'     => 'widget',
					'widgetType' => 'share-buttons',
					'settings'   => array(
						'share_buttons' => array(
							array( 'button' => 'facebook' ),
							array( 'button' => 'twitter' ),
							array( 'button' => 'linkedin' ),
						),
					),
				),
				'common_mistakes' => array(
					'Using "buttons" or "networks" instead of "share_buttons"',
					'Using "network" instead of "button" in repeater items',
				),
			),

			'blockquote'         => array(
				'description' => 'Styled blockquote with optional tweet button. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'blockquote_content'        => array( 'type' => 'textarea', 'default' => '', 'description' => 'Quote text' ),
					'tweet_button'              => array( 'type' => 'switcher', 'default' => '', 'description' => 'Show tweet button' ),
					'tweet_button_view'         => array( 'type' => 'select', 'default' => 'icon-text', 'options' => array( 'icon-text', 'icon', 'text' ), 'description' => 'Tweet button style' ),
					'author_name'               => array( 'type' => 'text', 'default' => '', 'description' => 'Author name' ),
					'blockquote_skin'           => array( 'type' => 'select', 'default' => 'border', 'options' => array( 'border', 'quotation', 'boxed', 'clean' ), 'description' => 'Quote style skin' ),
					'alignment'                 => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right' ), 'description' => 'Text alignment' ),
					'content_color'             => array( 'type' => 'color', 'default' => '', 'description' => 'Content color' ),
				),
				'example'     => array(
					'id'         => 'abc12385',
					'elType'     => 'widget',
					'widgetType' => 'blockquote',
					'settings'   => array(
						'blockquote_content' => 'The only way to do great work is to love what you do.',
						'author_name'        => 'Steve Jobs',
						'blockquote_skin'    => 'quotation',
					),
				),
				'common_mistakes' => array(
					'Using "content" or "quote" instead of "blockquote_content"',
					'Using "author" instead of "author_name"',
				),
			),

			'template'           => array(
				'description' => 'Embed a saved Elementor template. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'template_id'               => array( 'type' => 'select', 'default' => '', 'description' => 'Saved template ID to embed' ),
				),
				'example'     => array(
					'id'         => 'abc12386',
					'elType'     => 'widget',
					'widgetType' => 'template',
					'settings'   => array(
						'template_id' => '123',
					),
				),
				'common_mistakes' => array(
					'Using "id" instead of "template_id"',
				),
			),

			'lottie'             => array(
				'description' => 'Lottie animation player. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'source_type'               => array( 'type' => 'select', 'default' => 'media_file', 'options' => array( 'media_file', 'external_url' ), 'description' => 'Animation source type' ),
					'source_external_url'       => array( 'type' => 'url', 'default' => '', 'description' => 'External Lottie JSON URL' ),
					'source_json'               => array( 'type' => 'media', 'default' => '', 'description' => 'Media library JSON file' ),
					'trigger'                   => array( 'type' => 'select', 'default' => 'arriving_to_viewport', 'options' => array( 'arriving_to_viewport', 'on_click', 'on_hover', 'bind_to_scroll', 'none' ), 'description' => 'Animation trigger' ),
					'loop'                      => array( 'type' => 'switcher', 'default' => '', 'description' => 'Loop animation' ),
					'play_speed'                => array( 'type' => 'slider', 'default' => '', 'description' => 'Playback speed' ),
					'link_to'                   => array( 'type' => 'select', 'default' => 'none', 'options' => array( 'none', 'custom' ), 'description' => 'Link on click' ),
					'custom_link'               => array( 'type' => 'url', 'default' => '', 'description' => 'Custom link URL' ),
				),
				'example'     => array(
					'id'         => 'abc12387',
					'elType'     => 'widget',
					'widgetType' => 'lottie',
					'settings'   => array(
						'source_type'         => 'external_url',
						'source_external_url' => array( 'url' => 'https://assets.lottiefiles.com/packages/lf20_xxx.json' ),
						'loop'                => 'yes',
					),
				),
				'common_mistakes' => array(
					'Using "url" instead of "source_external_url"',
					'Passing URL as string instead of {"url":"..."} object',
				),
			),

			'theme-post-content' => array(
				'description' => 'Displays the post/page content in theme builder templates. Auto-renders the content area. Requires Elementor Pro.',
				'category'    => 'pro-theme-builder',
				'settings'    => array(),
				'example'     => array(
					'id'         => 'abc12388',
					'elType'     => 'widget',
					'widgetType' => 'theme-post-content',
					'settings'   => array(),
				),
				'common_mistakes' => array(
					'Adding content settings — this widget auto-renders the post content with no configuration needed',
					'Using "post-content" instead of "theme-post-content"',
				),
			),

			'theme-post-title'   => array(
				'description' => 'Displays the post/page title in theme builder templates. Requires Elementor Pro.',
				'category'    => 'pro-theme-builder',
				'settings'    => array(
					'header_size'               => array( 'type' => 'select', 'default' => 'h1', 'options' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), 'description' => 'HTML heading tag' ),
					'align'                     => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right', 'justify' ), 'description' => 'Title alignment' ),
					'link'                      => array( 'type' => 'select', 'default' => '', 'options' => array( '', 'yes' ), 'description' => 'Link title to post permalink ("yes" to enable)' ),
					'title_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Title text color' ),
					'typography_typography'      => array( 'type' => 'switcher', 'default' => '', 'description' => 'Enable custom typography' ),
					'typography_font_family'     => array( 'type' => 'font', 'default' => '', 'description' => 'Font family' ),
					'typography_font_size'       => array( 'type' => 'slider', 'default' => '', 'description' => 'Font size with unit' ),
				),
				'example'     => array(
					'id'         => 'abc12389',
					'elType'     => 'widget',
					'widgetType' => 'theme-post-title',
					'settings'   => array(
						'header_size' => 'h1',
						'align'       => 'left',
					),
				),
				'common_mistakes' => array(
					'Using "post-title" instead of "theme-post-title"',
					'Setting a "title" text — this widget auto-pulls the post title',
					'Using "tag" instead of "header_size"',
				),
			),

			'theme-site-logo'    => array(
				'description' => 'Displays the WordPress site logo configured in Customizer. Requires Elementor Pro.',
				'category'    => 'pro-theme-builder',
				'settings'    => array(
					'width'                     => array( 'type' => 'slider', 'default' => '', 'description' => 'Logo width with unit, e.g. {"size":200,"unit":"px"}' ),
					'align'                     => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right' ), 'description' => 'Logo alignment' ),
					'link_to'                   => array( 'type' => 'select', 'default' => 'home', 'options' => array( 'home', 'custom', 'none' ), 'description' => 'Link destination' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Custom link URL (when link_to is "custom")' ),
				),
				'example'     => array(
					'id'         => 'abc12390',
					'elType'     => 'widget',
					'widgetType' => 'theme-site-logo',
					'settings'   => array(
						'width' => array( 'size' => 180, 'unit' => 'px' ),
						'align' => 'left',
					),
				),
				'common_mistakes' => array(
					'Using "site-logo" instead of "theme-site-logo"',
					'Setting an "image" — this widget auto-pulls the site logo from Customizer',
					'Passing width as a number instead of {"size":N,"unit":"px"} object',
				),
			),

			'theme-site-title'   => array(
				'description' => 'Displays the WordPress site title. Requires Elementor Pro.',
				'category'    => 'pro-theme-builder',
				'settings'    => array(
					'header_size'               => array( 'type' => 'select', 'default' => 'h1', 'options' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), 'description' => 'HTML tag' ),
					'align'                     => array( 'type' => 'choose', 'default' => '', 'options' => array( 'left', 'center', 'right', 'justify' ), 'description' => 'Title alignment' ),
					'title_color'               => array( 'type' => 'color', 'default' => '', 'description' => 'Title text color' ),
					'typography_typography'      => array( 'type' => 'switcher', 'default' => '', 'description' => 'Enable custom typography' ),
					'typography_font_family'     => array( 'type' => 'font', 'default' => '', 'description' => 'Font family' ),
					'typography_font_size'       => array( 'type' => 'slider', 'default' => '', 'description' => 'Font size with unit' ),
					'link_to'                   => array( 'type' => 'select', 'default' => 'home', 'options' => array( 'home', 'custom', 'none' ), 'description' => 'Link destination' ),
					'link'                      => array( 'type' => 'url', 'default' => '', 'description' => 'Custom link URL' ),
				),
				'example'     => array(
					'id'         => 'abc12391',
					'elType'     => 'widget',
					'widgetType' => 'theme-site-title',
					'settings'   => array(
						'header_size' => 'h1',
						'align'       => 'left',
					),
				),
				'common_mistakes' => array(
					'Using "site-title" instead of "theme-site-title"',
					'Setting a "title" text — this widget auto-pulls the site title from WordPress settings',
					'Using "tag" instead of "header_size"',
				),
			),

			'loop-grid'          => array(
				'description' => 'Displays posts/CPT in a grid using a loop template. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'template_id'               => array( 'type' => 'select', 'default' => '', 'description' => 'Loop template ID (created in Elementor > Templates > Loop)' ),
					'columns'                   => array( 'type' => 'select', 'default' => '3', 'options' => array( '1', '2', '3', '4', '5', '6' ), 'description' => 'Grid columns' ),
					'posts_per_page'            => array( 'type' => 'number', 'default' => 6, 'description' => 'Number of posts to display' ),
					'query_post_type'           => array( 'type' => 'select', 'default' => 'post', 'description' => 'Post type to query (post, page, or custom post type slug)' ),
					'row_gap'                   => array( 'type' => 'slider', 'default' => '', 'description' => 'Row gap between items' ),
					'column_gap'                => array( 'type' => 'slider', 'default' => '', 'description' => 'Column gap between items' ),
					'pagination_type'           => array( 'type' => 'select', 'default' => '', 'options' => array( '', 'numbers', 'prev_next', 'numbers_and_prev_next', 'load_more_on_click', 'load_more_infinite_scroll' ), 'description' => 'Pagination type' ),
				),
				'example'     => array(
					'id'         => 'abc12392',
					'elType'     => 'widget',
					'widgetType' => 'loop-grid',
					'settings'   => array(
						'template_id'     => '456',
						'columns'         => '3',
						'posts_per_page'  => 6,
						'query_post_type' => 'post',
					),
				),
				'common_mistakes' => array(
					'Forgetting to set template_id — a loop template must be created first',
					'Using "post_type" instead of "query_post_type"',
					'Using "grid_columns" instead of "columns"',
				),
			),

			'gallery'            => array(
				'description' => 'Pro gallery with grid, justified, and masonry layouts. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'gallery_type'              => array( 'type' => 'select', 'default' => 'grid', 'options' => array( 'grid', 'justified', 'masonry' ), 'description' => 'Gallery layout type' ),
					'columns'                   => array( 'type' => 'select', 'default' => '4', 'options' => array( '1', '2', '3', '4', '5', '6', '7', '8', '9', '10' ), 'description' => 'Number of columns' ),
					'gap'                       => array( 'type' => 'slider', 'default' => '', 'description' => 'Gap between images with unit, e.g. {"size":10,"unit":"px"}' ),
					'images'                    => array( 'type' => 'gallery', 'default' => '', 'description' => 'Array of image objects: [{"id":123,"url":"https://..."},...]' ),
					'link_to'                   => array( 'type' => 'select', 'default' => 'file', 'options' => array( 'file', 'custom', 'none' ), 'description' => 'Image link destination' ),
					'open_lightbox'             => array( 'type' => 'select', 'default' => 'default', 'options' => array( 'default', 'yes', 'no' ), 'description' => 'Open images in lightbox' ),
					'show_title'                => array( 'type' => 'select', 'default' => '', 'options' => array( '', 'yes' ), 'description' => 'Show image title in overlay' ),
					'overlay_background'        => array( 'type' => 'color', 'default' => '', 'description' => 'Hover overlay background color' ),
				),
				'example'     => array(
					'id'         => 'abc12393',
					'elType'     => 'widget',
					'widgetType' => 'gallery',
					'settings'   => array(
						'gallery_type' => 'grid',
						'columns'      => '3',
						'images'       => array(
							array( 'id' => '', 'url' => 'https://example.com/img1.jpg' ),
							array( 'id' => '', 'url' => 'https://example.com/img2.jpg' ),
							array( 'id' => '', 'url' => 'https://example.com/img3.jpg' ),
						),
					),
				),
				'common_mistakes' => array(
					'Using "type" instead of "gallery_type"',
					'Using "gallery" instead of "images" for the image list',
					'Passing image URLs as strings instead of {"id":N,"url":"..."} objects',
				),
			),

			'table-of-contents'  => array(
				'description' => 'Automatic table of contents generated from page headings. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'headings_by_tags'          => array( 'type' => 'select2', 'default' => array( 'h2', 'h3', 'h4', 'h5', 'h6' ), 'description' => 'Which heading tags to include (array of h1-h6)' ),
					'title'                     => array( 'type' => 'text', 'default' => 'Table of Contents', 'description' => 'TOC widget title' ),
					'minimize_box'              => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show minimize/collapse toggle' ),
					'minimized_on'              => array( 'type' => 'select', 'default' => '', 'options' => array( '', 'tablet', 'mobile' ), 'description' => 'Minimized by default on device' ),
					'hierarchical_view'         => array( 'type' => 'switcher', 'default' => 'yes', 'description' => 'Show indented hierarchy based on heading level' ),
					'marker_view'               => array( 'type' => 'select', 'default' => 'numbers', 'options' => array( 'numbers', 'bullets', 'none' ), 'description' => 'List marker style' ),
					'sticky'                    => array( 'type' => 'switcher', 'default' => '', 'description' => 'Make TOC sticky on scroll' ),
				),
				'example'     => array(
					'id'         => 'abc12394',
					'elType'     => 'widget',
					'widgetType' => 'table-of-contents',
					'settings'   => array(
						'title'            => 'Table of Contents',
						'headings_by_tags' => array( 'h2', 'h3' ),
						'minimize_box'     => 'yes',
					),
				),
				'common_mistakes' => array(
					'Using "toc" instead of "table-of-contents"',
					'Using "headings" instead of "headings_by_tags"',
					'Passing heading tags as a string instead of an array',
				),
			),

			'hotspot'            => array(
				'description' => 'Image with interactive hotspot points that show tooltips on hover/click. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'image'                     => array( 'type' => 'media', 'default' => '', 'description' => 'Background image: {"url":"https://...","id":123}' ),
					'hotspot'                   => array( 'type' => 'repeater', 'default' => '', 'description' => 'Array of hotspot points: [{"hotspot_label":"Point 1","hotspot_offset_x":{"size":50,"unit":"%"},"hotspot_offset_y":{"size":50,"unit":"%"},"hotspot_tooltip_content":"Tooltip text"}, ...]' ),
					'hotspot_animation'         => array( 'type' => 'select', 'default' => '', 'options' => array( '', 'soft-beat', 'expand', 'shadow' ), 'description' => 'Hotspot pulse animation' ),
					'tooltip_trigger'           => array( 'type' => 'select', 'default' => 'hover', 'options' => array( 'hover', 'click' ), 'description' => 'How to trigger tooltip display' ),
					'tooltip_position'          => array( 'type' => 'select', 'default' => 'top', 'options' => array( 'top', 'bottom', 'left', 'right' ), 'description' => 'Tooltip position relative to hotspot' ),
				),
				'example'     => array(
					'id'         => 'abc12395',
					'elType'     => 'widget',
					'widgetType' => 'hotspot',
					'settings'   => array(
						'image'   => array( 'url' => 'https://example.com/product.jpg', 'id' => '' ),
						'hotspot' => array(
							array(
								'hotspot_label'           => 'Feature 1',
								'hotspot_offset_x'        => array( 'size' => 30, 'unit' => '%' ),
								'hotspot_offset_y'        => array( 'size' => 40, 'unit' => '%' ),
								'hotspot_tooltip_content' => 'This is a key feature of the product.',
							),
						),
					),
				),
				'common_mistakes' => array(
					'Using "hotspots" (plural) instead of "hotspot" for the repeater',
					'Using "x"/"y" instead of "hotspot_offset_x"/"hotspot_offset_y" for position',
					'Forgetting to pass offset values as {"size":N,"unit":"%"} objects',
				),
			),

			'search-form'        => array(
				'description' => 'WordPress search form with multiple skins. Requires Elementor Pro.',
				'category'    => 'pro',
				'settings'    => array(
					'skin'                      => array( 'type' => 'select', 'default' => 'classic', 'options' => array( 'classic', 'minimal', 'full_screen' ), 'description' => 'Search form skin/layout' ),
					'placeholder'               => array( 'type' => 'text', 'default' => 'Search...', 'description' => 'Input placeholder text' ),
					'button_type'               => array( 'type' => 'select', 'default' => 'icon', 'options' => array( 'icon', 'text' ), 'description' => 'Submit button display type' ),
					'button_text'               => array( 'type' => 'text', 'default' => 'Search', 'description' => 'Submit button text (when button_type is "text")' ),
					'size'                      => array( 'type' => 'select', 'default' => 'sm', 'options' => array( 'xs', 'sm', 'md', 'lg', 'xl' ), 'description' => 'Input field size' ),
				),
				'example'     => array(
					'id'         => 'abc12396',
					'elType'     => 'widget',
					'widgetType' => 'search-form',
					'settings'   => array(
						'skin'        => 'classic',
						'placeholder' => 'Search articles...',
					),
				),
				'common_mistakes' => array(
					'Using "search" instead of "search-form"',
					'Using "style" instead of "skin" for the layout option',
					'Using "text" instead of "placeholder" for the input hint',
				),
			),
		);
	}

	/**
	 * Get widget help data for a specific widget type.
	 *
	 * Returns full schema, example, common mistakes, and valid keys.
	 * If widget is not found, suggests closest matches.
	 *
	 * @param string $widget_type Widget type name.
	 * @return array Help data or error info with suggestions.
	 */
	public static function get_widget_help( $widget_type ) {
		$schema = self::get( $widget_type );

		if ( $schema ) {
			$valid_keys = array_keys( $schema['settings'] );

			// A curated guide is not evidence the widget exists here.
			//
			// The live-registry consultation was first added only to the
			// negative branch below, which left the more common half of #785
			// defect 2 untouched: `form`, `posts`, `portfolio` and
			// `search-form` are all curated entries, so on an Elementor FREE
			// site this returned a complete guide — description, valid_keys,
			// a working example — for a widget that is not registered and will
			// render nothing, with nothing in the response saying so.
			$live_types  = Mcpwp_Elementor_Registry::live_types();
			$registered  = null === $live_types ? null : in_array( $widget_type, $live_types, true );

			$response = array(
				'widget_type'     => $widget_type,
				'source'          => 'curated_reference',
				'description'     => $schema['description'],
				'category'        => $schema['category'],
				'valid_keys'      => $valid_keys,
				'settings'        => $schema['settings'],
				'example'         => $schema['example'],
				'common_mistakes' => $schema['common_mistakes'],
				'registry_checked' => null !== $live_types,
				'tips'            => array(
					'Every element needs a unique 8-char alphanumeric "id" field.',
					'Always set "elType" to "widget" and "widgetType" to "' . $widget_type . '".',
					'Use wp_get_widget_schema tool for live controls data from the actual Elementor installation.',
					'Use dry_run=true when setting Elementor data to validate without saving.',
				),
			);

			if ( null !== $registered ) {
				$response['registered_on_this_site'] = $registered;

				if ( ! $registered ) {
					$response['warning'] = sprintf(
						"This guide describes '%s', but Elementor does not have it registered on this site — it is most likely an Elementor Pro widget on an installation without Pro. Using it will save cleanly and render nothing.",
						$widget_type
					);
				}
			} else {
				$response['registry_note'] = 'Elementor\'s live registry could not be read, so whether this widget is actually available here is unknown.';
			}

			return $response;
		}

		// No curated guide. That is not the same as no such widget, and this
		// used to report it as if it were: asked about `theme-archive-title`
		// — which exists on the site, renders, and is the correct spelling of
		// the widget the caller got wrong — it answered `unknown_widget` with
		// no suggestions and a 52-name list that did not contain the answer,
		// actively pointing away from the right name (#785).
		//
		// So ask Elementor before saying no.
		$live_types = Mcpwp_Elementor_Registry::live_types();

		if ( null !== $live_types && in_array( $widget_type, $live_types, true ) ) {
			$meta = Mcpwp_Elementor_Registry::live_widget_meta( $widget_type );
			$keys = Mcpwp_Elementor_Registry::live_control_keys( $widget_type );

			return array(
				'widget_type' => $widget_type,
				'source'      => 'live_registry',
				'description' => ( $meta && '' !== $meta['title'] )
					? $meta['title'] . ' (registered on this site; no curated guide is written for it yet).'
					: 'Registered on this site; no curated guide is written for it yet.',
				'category'    => ( $meta && '' !== $meta['category'] ) ? $meta['category'] : '',
				'valid_keys'  => is_array( $keys ) ? $keys : array(),
				'note'        => 'Keys come from the live Elementor controls, not from this plugin\'s reference data, so they are exactly what this installation accepts.',
				'tips'        => array(
					'Every element needs a unique 8-char alphanumeric "id" field.',
					'Always set "elType" to "widget" and "widgetType" to "' . $widget_type . '".',
					'Use wp_get_widget_schema for the full control definitions including types and defaults.',
					'Use dry_run=true when setting Elementor data to validate without saving.',
				),
			);
		}

		// Genuinely not found. Suggest, and be explicit that all_widgets is
		// this plugin's curated reference list rather than the site's registry
		// — reading it as the registry is what sent the last caller wrong.
		$suggestions = self::find_closest( $widget_type, 5 );
		$all_types   = self::get_known_types();

		if ( empty( $suggestions ) && null !== $live_types ) {
			$live_suggestion = Mcpwp_Elementor_Registry::suggest(
				$widget_type,
				$live_types,
				function ( $input, $candidates ) {
					$best     = null;
					$distance = PHP_INT_MAX;
					foreach ( $candidates as $candidate ) {
						$this_distance = levenshtein( $input, $candidate );
						if ( $this_distance < $distance && $this_distance <= 3 ) {
							$distance = $this_distance;
							$best     = $candidate;
						}
					}
					return $best;
				}
			);

			if ( $live_suggestion ) {
				$suggestions = array( $live_suggestion );
			}
		}

		$response = array(
			'error'               => 'unknown_widget',
			'message'             => sprintf( "Unknown widget type '%s'.", $widget_type ),
			'suggestions'         => $suggestions,
			'all_widgets'         => $all_types,
			'all_widgets_is'      => 'curated reference list, not this site\'s widget registry',
			'tip'                 => 'Use wp_get_elementor_widgets to see all available widgets on this site, including third-party widgets.',
		);

		// Say which question was actually answered. Without this, "Elementor
		// says no" and "Elementor could not be asked" read identically.
		$response['registry_checked'] = null !== $live_types;

		if ( null !== $live_types ) {
			$response['registered_widget_count'] = count( $live_types );
		} else {
			$response['registry_note'] = 'Elementor\'s live registry could not be read, so this answer comes only from the curated list. The widget may still exist on the site.';
		}

		return $response;
	}
}
