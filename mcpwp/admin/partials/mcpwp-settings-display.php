<?php
/**
 * Settings page template.
 *
 * Variables available from render_settings_page():
 *   $site_profile         — array   structured site profile
 *   $site_context_preview — string  generated canonical site context
 *   $llms_url             — string  public llms.txt URL
 *   $llms_preview         — string  llms.txt content preview
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$license       = class_exists( 'Mcpwp_License' ) ? Mcpwp_License::get_instance() : null;
$license_label = $license ? $license->get_plan_label() : __( 'Free', 'mcpwp' );
?>

<div class="wrap mcpwp-admin">
	<h1 class="mcpwp-header">
		<span class="mcpwp-logo">
			<span class="dashicons dashicons-admin-generic"></span>
		</span>
		<?php esc_html_e( 'Settings', 'mcpwp' ); ?>
		<span class="mcpwp-version">v<?php echo esc_html( MCPWP_VERSION ); ?></span>
	</h1>

	<?php settings_errors( 'mcpwp_messages' ); ?>

	<div class="mcpwp-tab-content">

		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-awards"></span>
				<?php esc_html_e( 'About', 'mcpwp' ); ?>
			</h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: active plan name */
					esc_html__( 'MCPWP connects your WordPress site to AI assistants via the Model Context Protocol (MCP). Current plan: %s.', 'mcpwp' ),
					esc_html( $license_label )
				);
				?>
			</p>
			<p style="margin-top:10px;">
				<a href="https://mumega.com/" target="_blank" class="button">
					<span class="dashicons dashicons-external" style="margin-top:4px;"></span>
					<?php esc_html_e( 'Visit Mumega', 'mcpwp' ); ?>
				</a>
			</p>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'General Settings', 'mcpwp' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'mcpwp_settings_group' );
				do_settings_sections( 'mcpwp_settings' );
				submit_button();
				?>
			</form>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Rate Limiting', 'mcpwp' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'mcpwp_rate_limit_group' );
				do_settings_sections( 'mcpwp_rate_limit_settings' );
				submit_button( __( 'Save Rate Limits', 'mcpwp' ) );
				?>
			</form>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'AI Site Context', 'mcpwp' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'mcpwp_site_context_group' );
				do_settings_sections( 'mcpwp_site_context_settings' );
				submit_button( __( 'Save Site Context', 'mcpwp' ) );
				?>
			</form>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Guided Site Character', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Use this builder if you want a structured way to define the site character. Saving this form regenerates the canonical site context used by MCP and published in /llms.txt.', 'mcpwp' ); ?>
			</p>

			<form method="post" class="mcpwp-site-profile-form">
				<?php wp_nonce_field( 'mcpwp_site_profile_actions', 'mcpwp_site_profile_nonce' ); ?>
				<div class="mcpwp-site-profile-grid">
					<p>
						<label for="mcpwp_site_profile_brand_name"><strong><?php esc_html_e( 'Brand Name', 'mcpwp' ); ?></strong></label><br />
						<input type="text" id="mcpwp_site_profile_brand_name" name="mcpwp_site_profile[brand_name]" class="regular-text" value="<?php echo esc_attr( isset( $site_profile['brand_name'] ) ? $site_profile['brand_name'] : '' ); ?>" />
					</p>
					<p>
						<label for="mcpwp_site_profile_target_audience"><strong><?php esc_html_e( 'Target Audience', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_target_audience" name="mcpwp_site_profile[target_audience]" rows="4" class="large-text"><?php echo esc_textarea( isset( $site_profile['target_audience'] ) ? $site_profile['target_audience'] : '' ); ?></textarea>
					</p>
					<p class="mcpwp-site-profile-grid__full">
						<label for="mcpwp_site_profile_brand_summary"><strong><?php esc_html_e( 'Brand Summary', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_brand_summary" name="mcpwp_site_profile[brand_summary]" rows="4" class="large-text"><?php echo esc_textarea( isset( $site_profile['brand_summary'] ) ? $site_profile['brand_summary'] : '' ); ?></textarea>
					</p>
					<p>
						<label for="mcpwp_site_profile_brand_voice"><strong><?php esc_html_e( 'Brand Voice', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_brand_voice" name="mcpwp_site_profile[brand_voice]" rows="5" class="large-text"><?php echo esc_textarea( isset( $site_profile['brand_voice'] ) ? $site_profile['brand_voice'] : '' ); ?></textarea>
					</p>
					<p>
						<label for="mcpwp_site_profile_visual_style"><strong><?php esc_html_e( 'Visual Style', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_visual_style" name="mcpwp_site_profile[visual_style]" rows="5" class="large-text"><?php echo esc_textarea( isset( $site_profile['visual_style'] ) ? $site_profile['visual_style'] : '' ); ?></textarea>
					</p>
					<p>
						<label for="mcpwp_site_profile_primary_colors"><strong><?php esc_html_e( 'Colors', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_primary_colors" name="mcpwp_site_profile[primary_colors]" rows="5" class="large-text"><?php echo esc_textarea( isset( $site_profile['primary_colors'] ) ? $site_profile['primary_colors'] : '' ); ?></textarea>
					</p>
					<p>
						<label for="mcpwp_site_profile_typography"><strong><?php esc_html_e( 'Typography', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_typography" name="mcpwp_site_profile[typography]" rows="5" class="large-text"><?php echo esc_textarea( isset( $site_profile['typography'] ) ? $site_profile['typography'] : '' ); ?></textarea>
					</p>
					<p>
						<label for="mcpwp_site_profile_header_rules"><strong><?php esc_html_e( 'Header Rules', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_header_rules" name="mcpwp_site_profile[header_rules]" rows="5" class="large-text"><?php echo esc_textarea( isset( $site_profile['header_rules'] ) ? $site_profile['header_rules'] : '' ); ?></textarea>
					</p>
					<p>
						<label for="mcpwp_site_profile_footer_rules"><strong><?php esc_html_e( 'Footer Rules', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_footer_rules" name="mcpwp_site_profile[footer_rules]" rows="5" class="large-text"><?php echo esc_textarea( isset( $site_profile['footer_rules'] ) ? $site_profile['footer_rules'] : '' ); ?></textarea>
					</p>
					<p>
						<label for="mcpwp_site_profile_core_page_patterns"><strong><?php esc_html_e( 'Core Page Patterns', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_core_page_patterns" name="mcpwp_site_profile[core_page_patterns]" rows="6" class="large-text"><?php echo esc_textarea( isset( $site_profile['core_page_patterns'] ) ? $site_profile['core_page_patterns'] : '' ); ?></textarea>
					</p>
					<p>
						<label for="mcpwp_site_profile_reusable_sections"><strong><?php esc_html_e( 'Reusable Sections', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_reusable_sections" name="mcpwp_site_profile[reusable_sections]" rows="6" class="large-text"><?php echo esc_textarea( isset( $site_profile['reusable_sections'] ) ? $site_profile['reusable_sections'] : '' ); ?></textarea>
					</p>
					<p>
						<label for="mcpwp_site_profile_conversion_goals"><strong><?php esc_html_e( 'Conversion Goals', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_conversion_goals" name="mcpwp_site_profile[conversion_goals]" rows="5" class="large-text"><?php echo esc_textarea( isset( $site_profile['conversion_goals'] ) ? $site_profile['conversion_goals'] : '' ); ?></textarea>
					</p>
					<p>
						<label for="mcpwp_site_profile_claims_to_avoid"><strong><?php esc_html_e( 'Claims To Avoid', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_claims_to_avoid" name="mcpwp_site_profile[claims_to_avoid]" rows="5" class="large-text"><?php echo esc_textarea( isset( $site_profile['claims_to_avoid'] ) ? $site_profile['claims_to_avoid'] : '' ); ?></textarea>
					</p>
					<p class="mcpwp-site-profile-grid__full">
						<label for="mcpwp_site_profile_ai_instructions"><strong><?php esc_html_e( 'Instructions For AI Systems', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_site_profile_ai_instructions" name="mcpwp_site_profile[ai_instructions]" rows="6" class="large-text"><?php echo esc_textarea( isset( $site_profile['ai_instructions'] ) ? $site_profile['ai_instructions'] : '' ); ?></textarea>
					</p>
				</div>
				<p>
					<button type="submit" name="mcpwp_save_site_profile" class="button button-primary"><?php esc_html_e( 'Save Guided Character', 'mcpwp' ); ?></button>
				</p>
			</form>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Live AI Preview', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'This is what your connected AI tools and public AI crawlers will see. The site context feeds MCP. The llms.txt preview is the public-facing summary exposed on your site.', 'mcpwp' ); ?>
			</p>

			<div class="mcpwp-ai-preview-grid">
				<div class="mcpwp-ai-preview-panel">
					<h3><?php esc_html_e( 'Canonical Site Context', 'mcpwp' ); ?></h3>
					<?php if ( '' !== trim( $site_context_preview ) ) : ?>
						<div class="mcpwp-code-wrapper">
							<pre class="mcpwp-code-block" id="mcpwp-site-context-preview"><?php echo esc_html( $site_context_preview ); ?></pre>
							<button type="button" class="button mcpwp-copy-code-btn" data-target="mcpwp-site-context-preview">
								<span class="dashicons dashicons-clipboard"></span>
								<?php esc_html_e( 'Copy', 'mcpwp' ); ?>
							</button>
						</div>
					<?php else : ?>
						<p><em><?php esc_html_e( 'No site context generated yet.', 'mcpwp' ); ?></em></p>
					<?php endif; ?>
				</div>

				<div class="mcpwp-ai-preview-panel">
					<h3><?php esc_html_e( 'Public llms.txt', 'mcpwp' ); ?></h3>
					<p class="description">
						<strong><?php esc_html_e( 'URL:', 'mcpwp' ); ?></strong>
						<a href="<?php echo esc_url( $llms_url ); ?>" target="_blank"><?php echo esc_html( $llms_url ); ?></a>
					</p>
					<?php if ( '' !== trim( $llms_preview ) ) : ?>
						<div class="mcpwp-code-wrapper">
							<pre class="mcpwp-code-block" id="mcpwp-llms-preview"><?php echo esc_html( $llms_preview ); ?></pre>
							<button type="button" class="button mcpwp-copy-code-btn" data-target="mcpwp-llms-preview">
								<span class="dashicons dashicons-clipboard"></span>
								<?php esc_html_e( 'Copy', 'mcpwp' ); ?>
							</button>
						</div>
					<?php else : ?>
						<p><em><?php esc_html_e( 'llms.txt preview is not available.', 'mcpwp' ); ?></em></p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="mcpwp-settings-card">
			<h2><?php esc_html_e( 'Chat Model', 'mcpwp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Select the AI model used by the MCPWP Chat tab. Auto picks OpenAI if a key is configured, then Gemini, then the free Workers AI fallback.', 'mcpwp' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'mcpwp_chat_group' ); ?>
				<?php $model = get_option( 'mcpwp_chat_model', 'auto' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mcpwp_chat_model"><?php esc_html_e( 'Model preference', 'mcpwp' ); ?></label></th>
						<td>
							<select name="mcpwp_chat_model" id="mcpwp_chat_model">
								<option value="auto" <?php selected( $model, 'auto' ); ?>><?php esc_html_e( 'Auto (recommended)', 'mcpwp' ); ?></option>
								<option value="openai" <?php selected( $model, 'openai' ); ?>><?php esc_html_e( 'OpenAI GPT-4o mini', 'mcpwp' ); ?></option>
								<option value="gemini" <?php selected( $model, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini 2.5 Flash', 'mcpwp' ); ?></option>
								<option value="workers" <?php selected( $model, 'workers' ); ?>><?php esc_html_e( 'Cloudflare Workers AI (free)', 'mcpwp' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Configure provider API keys in the Integrations tab.', 'mcpwp' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Chat Model', 'mcpwp' ) ); ?>
			</form>
		</div>

		<?php
		/**
		 * Action for Pro add-on to render additional settings cards.
		 */
		do_action( 'mcpwp_admin_settings_cards' );
		?>

	</div><!-- .mcpwp-tab-content -->
</div><!-- .wrap -->
