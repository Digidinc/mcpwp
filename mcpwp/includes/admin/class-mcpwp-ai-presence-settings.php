<?php
/**
 * Admin settings for AI presence: llms.txt override, AI crawler policy, and
 * IndexNow.
 *
 * Deliberately kept out of Mcpwp_Settings (class-mcpwp-settings.php): that
 * class's sanitize_settings() has careful, load-bearing "was this checkbox's
 * form actually posted" semantics (see its docblock) that a new, unrelated
 * option has no reason to entangle with. These options are registered under
 * the same 'mcpwp_settings_group' / 'mcpwp_settings' page so they save via
 * the existing Settings screen and form nonce without touching that class
 * or its partial template.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the AI Presence & Crawlers settings section.
 */
class Mcpwp_AI_Presence_Settings {

	/**
	 * Register settings, section, and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'mcpwp_settings_group',
			Mcpwp_AI_Presence::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_ai_presence_settings' ),
				'default'           => array( 'force_llms_txt' => false ),
			)
		);

		register_setting(
			'mcpwp_settings_group',
			Mcpwp_IndexNow::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_indexnow_settings' ),
				'default'           => array(
					'enabled'              => true,
					'force_non_production' => false,
				),
			)
		);

		register_setting(
			'mcpwp_settings_group',
			Mcpwp_AI_Crawler_Policy::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_crawler_policy' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'mcpwp_ai_presence_section',
			__( 'AI Presence & Crawlers', 'mcpwp' ),
			array( $this, 'render_section_intro' ),
			'mcpwp_settings'
		);

		add_settings_field(
			'mcpwp_ai_presence_force_llms_txt',
			__( 'Force MCPWP llms.txt', 'mcpwp' ),
			array( $this, 'render_force_llms_txt_field' ),
			'mcpwp_settings',
			'mcpwp_ai_presence_section'
		);

		add_settings_field(
			'mcpwp_indexnow_settings_fields',
			__( 'IndexNow', 'mcpwp' ),
			array( $this, 'render_indexnow_fields' ),
			'mcpwp_settings',
			'mcpwp_ai_presence_section'
		);

		add_settings_field(
			'mcpwp_ai_crawler_policy_fields',
			__( 'AI Crawler Policy', 'mcpwp' ),
			array( $this, 'render_crawler_policy_fields' ),
			'mcpwp_settings',
			'mcpwp_ai_presence_section'
		);
	}

	/**
	 * Section intro text.
	 *
	 * @return void
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Controls for llms.txt, per-bot AI crawler access, and IndexNow — all designed to coexist with Yoast, Rank Math, AIOSEO, or SEOPress rather than duplicate them.', 'mcpwp' ) . '</p>';
	}

	/**
	 * Force-llms-txt checkbox.
	 *
	 * @return void
	 */
	public function render_force_llms_txt_field() {
		$settings = get_option( Mcpwp_AI_Presence::SETTINGS_OPTION, array() );
		$checked  = ! empty( $settings['force_llms_txt'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Mcpwp_AI_Presence::SETTINGS_OPTION ); ?>[force_llms_txt]" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( 'Serve MCPWP\'s own /llms.txt even when Yoast, Rank Math, AIOSEO, or SEOPress is active.', 'mcpwp' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'By default MCPWP steps aside for any of those plugins to avoid shadowing theirs. Leave this off unless you specifically want MCPWP\'s version.', 'mcpwp' ); ?></p>
		<?php
	}

	/**
	 * IndexNow settings fields.
	 *
	 * @return void
	 */
	public function render_indexnow_fields() {
		$indexnow = new Mcpwp_IndexNow();
		$settings = $indexnow->get_settings();
		?>
		<p class="description">
			<?php esc_html_e( 'When on, publishing or updating a public post or page sends its URL — never its content — to the shared IndexNow endpoint at api.indexnow.org, which relays it to participating search engines (Bing, Yandex, Naver, Seznam, Yep, Amazon — not Google).', 'mcpwp' ); ?>
		</p>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Mcpwp_IndexNow::SETTINGS_OPTION ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> />
			<?php esc_html_e( 'Automatically ping IndexNow when a public post or page is published or updated. Off by default.', 'mcpwp' ); ?>
		</label><br />
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Mcpwp_IndexNow::SETTINGS_OPTION ); ?>[force_non_production]" value="1" <?php checked( $settings['force_non_production'] ); ?> />
			<?php esc_html_e( 'Also submit outside a production environment (normally off, so staging/local sites never ping the real endpoint).', 'mcpwp' ); ?>
		</label>
		<p class="description">
			<?php if ( ! $indexnow->blog_public_allows_indexing() ) : ?>
				<strong><?php esc_html_e( 'This site\'s Settings > Reading > "Discourage search engines from indexing this site" is on — IndexNow will not run regardless of the setting above, until that is turned off.', 'mcpwp' ); ?></strong><br />
			<?php endif; ?>
			<?php
			$key_url = $indexnow->key_url();
			if ( '' !== $key_url ) {
				printf(
					/* translators: %s: key file URL */
					esc_html__( 'Key file: %s', 'mcpwp' ),
					'<code>' . esc_html( $key_url ) . '</code>'
				);
			} else {
				esc_html_e( 'No key has been generated yet — one is created automatically the first time this is turned on.', 'mcpwp' );
			}
			?>
			<?php if ( $indexnow->defer_to_rankmath() ) : ?>
				<br /><?php esc_html_e( 'Rank Math\'s own Instant Indexing module is configured to auto-submit on this site — MCPWP is deferring to it and will not submit automatically.', 'mcpwp' ); ?>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Per-bot allow/deny table.
	 *
	 * @return void
	 */
	public function render_crawler_policy_fields() {
		$policy = Mcpwp_AI_Crawler_Policy::get_policy();
		$bots   = Mcpwp_AI_Crawler_Policy::known_bots();
		?>
		<table class="widefat" style="max-width:640px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Bot', 'mcpwp' ); ?></th>
					<th><?php esc_html_e( 'Type', 'mcpwp' ); ?></th>
					<th><?php esc_html_e( 'Allow', 'mcpwp' ); ?></th>
					<th><?php esc_html_e( 'Deny', 'mcpwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $bots as $bot => $meta ) : ?>
				<tr>
					<td><code><?php echo esc_html( $bot ); ?></code><br /><span class="description"><?php echo esc_html( isset( $meta['label'] ) ? $meta['label'] : '' ); ?></span></td>
					<td>
						<?php
						$type = isset( $meta['type'] ) ? $meta['type'] : '';
						echo Mcpwp_AI_Crawler_Policy::TYPE_SEARCH === $type
							? esc_html__( 'Search (live answers)', 'mcpwp' )
							: esc_html__( 'Training only', 'mcpwp' );
						?>
					</td>
					<td><input type="radio" name="<?php echo esc_attr( Mcpwp_AI_Crawler_Policy::OPTION_NAME ); ?>[<?php echo esc_attr( $bot ); ?>]" value="allow" <?php checked( 'allow' === ( $policy[ $bot ] ?? 'allow' ) ); ?> /></td>
					<td><input type="radio" name="<?php echo esc_attr( Mcpwp_AI_Crawler_Policy::OPTION_NAME ); ?>[<?php echo esc_attr( $bot ); ?>]" value="deny" <?php checked( 'deny' === ( $policy[ $bot ] ?? 'allow' ) ); ?> /></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Denying a SEARCH bot removes this site from that AI product\'s live answers. Denying a TRAINING bot only opts out of future model training and has no effect on current answers.', 'mcpwp' ); ?></p>
		<?php if ( Mcpwp_AI_Crawler_Policy::physical_robots_file_exists() ) : ?>
			<p class="description" style="color:#b32d2e"><?php esc_html_e( 'A physical robots.txt file exists at the site root, which overrides WordPress\'s virtual robots.txt. This policy cannot take effect until that file is removed or edited directly.', 'mcpwp' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Sanitize the AI presence settings option.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_ai_presence_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		return array( 'force_llms_txt' => ! empty( $input['force_llms_txt'] ) );
	}

	/**
	 * Sanitize the IndexNow settings option.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_indexnow_settings( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$enabled = ! empty( $input['enabled'] );

		if ( $enabled && class_exists( 'Mcpwp_IndexNow' ) ) {
			// Generate on enable (#937 WARN-1) — this Settings API sanitize
			// callback IS the save path the admin form goes through, so the
			// key must be created here rather than assuming some other
			// caller already handled it.
			( new Mcpwp_IndexNow() )->ensure_key_generated();
		}

		return array(
			'enabled'              => $enabled,
			'force_non_production' => ! empty( $input['force_non_production'] ),
		);
	}

	/**
	 * Sanitize the crawler policy option: only known bots, only "allow"/"deny".
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_crawler_policy( $input ) {
		$input = is_array( $input ) ? $input : array();
		$known = Mcpwp_AI_Crawler_Policy::known_bots();

		$clean = array();
		foreach ( $input as $bot => $value ) {
			if ( ! isset( $known[ $bot ] ) ) {
				continue;
			}
			$clean[ $bot ] = ( 'deny' === $value ) ? 'deny' : 'allow';
		}

		return $clean;
	}

	/**
	 * Admin notice when a physical robots.txt file blocks the crawler
	 * policy from taking effect and at least one bot is set to "deny"
	 * (i.e. the setting would otherwise do something).
	 *
	 * @return void
	 */
	public function maybe_render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Mcpwp_AI_Crawler_Policy::physical_robots_file_exists() ) {
			return;
		}

		$policy      = Mcpwp_AI_Crawler_Policy::get_policy();
		$has_denials = in_array( 'deny', $policy, true );
		if ( ! $has_denials ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'MCPWP: the AI crawler policy denies at least one bot, but a physical robots.txt file exists at the site root. WordPress serves that file instead of the virtual robots.txt this policy writes to, so the denial is not actually in effect.', 'mcpwp' )
		);
	}
}
