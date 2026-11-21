<?php
/**
 * White-label branding for MCPWP — agency name, logo, colors, chat widget.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages white-label settings and applies agency branding across the plugin UI.
 */
class Mcpwp_White_Label {

	const OPTION_KEY = 'mcpwp_white_label';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'             => false,
			'agency_name'         => '',
			'logo_url'            => '',
			'primary_color'       => '#7c3aed',
			'chat_greeting'       => 'Hi! How can I help you today?',
			'hide_mcpwp_branding' => true,
		);
	}

	/**
	 * Validate and normalize a logo URL for storage (http/https only).
	 *
	 * @param string $url Raw setting value.
	 * @return string esc_url_raw() output or empty when unusable.
	 */
	public static function validate_logo_url_for_storage( $url ) {
		$url = (string) $url;
		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		$raw = esc_url_raw( $url );
		return '' !== $raw ? $raw : '';
	}

	/**
	 * Whether a logo URL is allowed for output (same validation as storage).
	 *
	 * @param string $url Stored or raw logo URL.
	 * @return string Normalized URL or empty when unusable.
	 */
	public static function safe_logo_url( $url ) {
		return self::validate_logo_url_for_storage( $url );
	}

	/**
	 * Escape a validated logo URL for a CSS url("...") value.
	 *
	 * @param string $url Stored logo URL.
	 * @return string Escaped URL or empty when unusable.
	 */
	public static function logo_url_for_css( $url ) {
		$url = self::validate_logo_url_for_storage( $url );
		if ( '' === $url ) {
			return '';
		}
		return addcslashes( $url, "\"\\\n\r\f" );
	}

	/**
	 * Get white-label settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Merge, coerce types, and validate white-label settings for storage.
	 *
	 * Pure sanitizer — does not persist. Used by save_settings() and the
	 * Settings API sanitize callback (register_setting / update_option).
	 *
	 * @param array $settings Partial or full settings array.
	 * @return array Sanitized settings ready for get_option / update_option.
	 */
	public static function prepare_settings_for_storage( array $settings ): array {
		$current  = self::get_settings();
		$defaults = self::defaults();
		$merged   = array();

		foreach ( $defaults as $key => $default ) {
			if ( array_key_exists( $key, $settings ) ) {
				$value = $settings[ $key ];
			} elseif ( array_key_exists( $key, $current ) ) {
				$value = $current[ $key ];
			} else {
				$value = $default;
			}

			// Type coercion to match defaults.
			if ( is_bool( $default ) ) {
				$value = (bool) $value;
			} elseif ( is_string( $default ) ) {
				if ( 'logo_url' === $key ) {
					$value = (string) $value;
				} else {
					$value = sanitize_text_field( (string) $value );
				}
			}

			$merged[ $key ] = $value;
		}

		// Validate color.
		if ( ! preg_match( '/^#[0-9a-fA-F]{3,6}$/', $merged['primary_color'] ) ) {
			$merged['primary_color'] = '#7c3aed';
		}

		// Validate logo URL: http(s) only; store esc_url_raw() so query strings keep &.
		if ( ! empty( $merged['logo_url'] ) ) {
			$merged['logo_url'] = self::validate_logo_url_for_storage( $merged['logo_url'] );
		} else {
			$merged['logo_url'] = '';
		}

		return $merged;
	}

	/**
	 * Save white-label settings.
	 *
	 * @param array $settings Partial or full settings array.
	 * @return void
	 */
	public static function save_settings( array $settings ): void {
		update_option( self::OPTION_KEY, self::prepare_settings_for_storage( $settings ) );
	}

	/**
	 * Whether white-label mode is enabled with an agency name set.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$s = self::get_settings();
		return ! empty( $s['enabled'] ) && ! empty( $s['agency_name'] );
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'mcpwp_chat', array( __CLASS__, 'shortcode_chat' ) );

		if ( self::is_enabled() ) {
			add_action( 'admin_head', array( __CLASS__, 'inject_admin_branding_css' ) );
			add_filter( 'admin_footer_text', array( __CLASS__, 'filter_admin_footer' ) );
		}

		// Register Elementor widget when Elementor is ready.
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_elementor_widget' ) );
	}

	/**
	 * Inject admin CSS overrides for white-label branding.
	 *
	 * @return void
	 */
	public static function inject_admin_branding_css(): void {
		$s = self::get_settings();
		if ( ! $s['enabled'] || empty( $s['agency_name'] ) ) {
			return;
		}

		$primary = esc_attr( $s['primary_color'] );
		$logo    = self::logo_url_for_css( $s['logo_url'] );

		echo '<style id="mcpwp-white-label-css">';
		echo '.mcpwp-product-name { display: none !important; }';

		if ( ! empty( $s['agency_name'] ) ) {
			$name = esc_js( $s['agency_name'] );
			echo '.mcpwp-card-header .mcpwp-brand::after { content: "' . $name . '"; }'; // phpcs:ignore
		}

		if ( $primary ) {
			echo ':root { --mcpwp-primary: ' . $primary . '; --mcpwp-accent: ' . $primary . '; }'; // phpcs:ignore
		}

		if ( $logo ) {
			echo '.mcpwp-logo { align-items: center; background: none; border: none; border-radius: 0; box-shadow: none; display: inline-flex; height: auto; justify-content: center; padding: 0; vertical-align: middle; width: auto; }'; // phpcs:ignore
			echo '.mcpwp-logo .mcpwp-logo-mark { display: none !important; }'; // phpcs:ignore
			echo '.mcpwp-logo::before { content: ""; display: inline-block; width: 44px; height: 34px; max-height: 32px; background: url("' . $logo . '") center / contain no-repeat; vertical-align: middle; }'; // phpcs:ignore
		}

		echo '</style>';
	}

	/**
	 * Filter the WP Admin footer text for white-label branding.
	 *
	 * @param string $text Default footer text.
	 * @return string
	 */
	public static function filter_admin_footer( string $text ): string {
		global $current_screen;
		if ( ! $current_screen || false === strpos( $current_screen->id, 'mcpwp' ) ) {
			return $text;
		}

		$s = self::get_settings();
		if ( $s['hide_mcpwp_branding'] && ! empty( $s['agency_name'] ) ) {
			return sprintf(
				/* translators: %s: agency name */
				esc_html__( 'Powered by %s', 'mcpwp' ),
				esc_html( $s['agency_name'] )
			);
		}

		return $text;
	}

	/**
	 * Render the [mcpwp_chat] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function shortcode_chat( $atts ): string {
		$atts = shortcode_atts(
			array(
				'greeting' => '',
				'position' => 'bottom-right',
				'style'    => 'floating',
			),
			$atts,
			'mcpwp_chat'
		);

		$s        = self::get_settings();
		$greeting = ! empty( $atts['greeting'] ) ? sanitize_text_field( $atts['greeting'] ) : $s['chat_greeting'];
		$position = in_array( $atts['position'], array( 'bottom-right', 'bottom-left', 'inline' ), true ) ? $atts['position'] : 'bottom-right';
		$color    = esc_attr( $s['primary_color'] );
		$name     = self::is_enabled() ? esc_html( $s['agency_name'] ) : esc_html__( 'AI Assistant', 'mcpwp' );
		$chat_url = admin_url( 'admin.php?page=mcpwp-chat' );

		$logo_html = '';
		if ( self::is_enabled() && ! empty( $s['logo_url'] ) ) {
			$logo_html = '<img src="' . esc_url( $s['logo_url'] ) . '" alt="' . esc_attr( $name ) . '" class="mcpwp-chat-logo" style="max-height:28px;vertical-align:middle;margin-right:6px" />';
		}

		$style_attr = '';
		if ( 'floating' === $atts['style'] ) {
			$pos_css = 'bottom-right' === $position ? 'right:24px;bottom:24px;' : 'left:24px;bottom:24px;';
			$style_attr = 'position:fixed;' . $pos_css . 'z-index:9999;';
		}

		ob_start();
		?>
		<div class="mcpwp-chat-widget" style="<?php echo esc_attr( $style_attr ); ?>">
			<button
				id="mcpwp-chat-toggle"
				style="background:<?php echo $color; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $color is pre-escaped with esc_attr() at assignment ?>;color:#fff;border:none;border-radius:99px;padding:10px 20px;font-size:15px;font-weight:600;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.2);display:flex;align-items:center;gap:6px"
				onclick="(function(btn){
					var panel = document.getElementById('mcpwp-chat-panel');
					if(!panel){
						panel = document.createElement('iframe');
						panel.id='mcpwp-chat-panel';
						panel.src='<?php echo esc_js( $chat_url ); ?>';
						panel.style.cssText='position:fixed;<?php echo esc_js( $pos_css ?? '' ); ?>width:420px;height:600px;border:none;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.2);z-index:9998;margin-bottom:60px;background:#fff';
						document.body.appendChild(panel);
						btn.textContent='✕ Close';
					} else {
						panel.remove();
						btn.innerHTML='<?php echo esc_js( $logo_html . $name ); ?>';
					}
				})(this)"
			>
				<?php echo wp_kses_post( $logo_html ); ?>
				<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name is pre-escaped with esc_html() at assignment ?>
			</button>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Register the Elementor widget (called on elementor/widgets/register action).
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widget manager.
	 * @return void
	 */
	public static function register_elementor_widget( $widgets_manager ): void {
		// The widget class is defined conditionally at the bottom of this file,
		// guarded on \Elementor\Widget_Base existing AT FILE-LOAD time. If this
		// file loaded before Elementor was ready (load-order varies with other
		// plugins), the class was never defined — so guard registration here too,
		// otherwise instantiating a missing class fatals the entire front-end
		// during Elementor's widget init.
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! class_exists( 'Mcpwp_Elementor_Chat_Widget' ) ) {
			return;
		}

		$widgets_manager->register( new Mcpwp_Elementor_Chat_Widget() );
	}
}

if ( ! class_exists( 'Mcpwp_Elementor_Chat_Widget' ) && class_exists( '\Elementor\Widget_Base' ) ) {
	/**
	 * Elementor chat widget for the [mcpwp_chat] shortcode.
	 */
	class Mcpwp_Elementor_Chat_Widget extends \Elementor\Widget_Base {

		public function get_name(): string {
			return 'mcpwp_chat';
		}

		public function get_title(): string {
			return esc_html__( 'AI Chat Widget', 'mcpwp' );
		}

		public function get_icon(): string {
			return 'eicon-chat';
		}

		public function get_categories(): array {
			return array( 'general' );
		}

		protected function register_controls(): void {
			$this->start_controls_section(
				'content_section',
				array(
					'label' => esc_html__( 'Chat Settings', 'mcpwp' ),
					'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
				)
			);

			$this->add_control(
				'greeting',
				array(
					'label'       => esc_html__( 'Greeting', 'mcpwp' ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => esc_html__( 'Hi! How can I help?', 'mcpwp' ),
					'placeholder' => esc_html__( 'Hi! How can I help?', 'mcpwp' ),
				)
			);

			$this->add_control(
				'position',
				array(
					'label'   => esc_html__( 'Position', 'mcpwp' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'default' => 'bottom-right',
					'options' => array(
						'bottom-right' => esc_html__( 'Bottom Right', 'mcpwp' ),
						'bottom-left'  => esc_html__( 'Bottom Left', 'mcpwp' ),
						'inline'       => esc_html__( 'Inline', 'mcpwp' ),
					),
				)
			);

			$this->end_controls_section();
		}

		protected function render(): void {
			$settings = $this->get_settings_for_display();
			echo do_shortcode( sprintf(
				'[mcpwp_chat greeting="%s" position="%s"]',
				esc_attr( $settings['greeting'] ?? '' ),
				esc_attr( $settings['position'] ?? 'bottom-right' )
			) );
		}
	}
}
