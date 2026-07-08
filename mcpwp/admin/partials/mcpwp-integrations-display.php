<?php
/**
 * Integrations admin page template.
 *
 * @package mumcp
 * @since   1.1.0
 *
 * @var array $providers Provider status data from Mcpwp_Integration_Manager.
 * @var bool  $is_pro    Whether Pro license is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$integrations_admin = new Mcpwp_Integrations_Admin();
$figma_oauth_notice = isset( $_GET['mcpwp_figma_oauth'] ) ? sanitize_key( wp_unslash( $_GET['mcpwp_figma_oauth'] ) ) : '';
$figma_oauth_message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
?>
<div class="wrap mcpwp-admin mcpwp-integrations-page">
	<h1 class="mcpwp-header">
		<span class="mcpwp-logo">
			<span class="dashicons dashicons-admin-plugins"></span>
		</span>
		<?php esc_html_e( 'AI Integrations', 'mumcp' ); ?>
		<!-- F-20: version pill matching other pages -->
		<span class="mcpwp-version">v<?php echo esc_html( MCPWP_VERSION ); ?></span>
	</h1>
	<p class="description mcpwp-page-intro">
		<?php esc_html_e( 'Connect third-party AI and design services to unlock image generation, vision analysis, text-to-speech, screenshots, stock photos, and design-context intake via MCP tools.', 'mumcp' ); ?>
	</p>
	<p class="description mcpwp-subtext">
		<?php esc_html_e( 'AI assistants can also configure these integrations via the wp_configure_integration MCP tool.', 'mumcp' ); ?>
	</p>

	<?php if ( in_array( $figma_oauth_notice, array( 'success', 'error' ), true ) && '' !== $figma_oauth_message ) : ?>
		<div class="notice <?php echo 'success' === $figma_oauth_notice ? 'notice-success' : 'notice-error'; ?> is-dismissible">
			<p><?php echo esc_html( $figma_oauth_message ); ?></p>
		</div>
	<?php endif; ?>

	<div class="mcpwp-integrations-grid">
		<?php foreach ( $providers as $slug => $provider ) : ?>
			<?php
			$is_pro_provider  = 'free' !== $provider['tier'];
			$locked           = $is_pro_provider && ! $is_pro;
			$is_multi_field   = ! empty( $provider['fields'] );
			$has_description  = ! empty( $provider['description'] );
			$is_figma         = 'figma' === $slug;
			$figma_auth_mode  = $is_figma && ! empty( $provider['auth_mode'] ) ? (string) $provider['auth_mode'] : '';
			$figma_mode_label = 'oauth' === $figma_auth_mode ? __( 'OAuth Connected', 'mumcp' ) : ( 'personal_token' === $figma_auth_mode ? __( 'Personal Token Active', 'mumcp' ) : __( 'Not Connected Yet', 'mumcp' ) );
			?>
			<div class="mcpwp-integration-card <?php echo $locked ? 'is-locked' : ''; ?>">
				<?php if ( $is_pro_provider ) : ?>
					<span class="mcpwp-tier-badge <?php echo $is_pro ? 'is-pro' : 'is-locked'; ?>">
						<?php echo $is_pro ? 'PRO' : esc_html__( 'PRO REQUIRED', 'mumcp' ); ?>
					</span>
				<?php else : ?>
					<span class="mcpwp-tier-badge is-free">
						<?php esc_html_e( 'FREE', 'mumcp' ); ?>
					</span>
				<?php endif; ?>

				<h3>
					<?php echo esc_html( $provider['name'] ); ?>
				</h3>

				<?php if ( $has_description ) : ?>
					<p class="mcpwp-integration-card__description">
						<?php echo esc_html( $provider['description'] ); ?>
					</p>
				<?php endif; ?>

				<p class="mcpwp-api-link">
					<a href="<?php echo esc_url( $provider['url'] ); ?>" target="_blank" rel="noopener">
						<?php echo $is_multi_field ? esc_html__( 'Setup Guide', 'mumcp' ) : esc_html__( 'Get API Key', 'mumcp' ); ?> &rarr;
					</a>
				</p>

				<?php if ( $is_figma ) : ?>
					<div class="mcpwp-figma-panel">
						<div class="mcpwp-figma-panel__row">
							<strong><?php esc_html_e( 'Use Case', 'mumcp' ); ?></strong>
							<span><?php esc_html_e( 'Approved design intake for archetypes, parts, and site briefs.', 'mumcp' ); ?></span>
						</div>
						<div class="mcpwp-figma-panel__row">
							<strong><?php esc_html_e( 'Auth Status', 'mumcp' ); ?></strong>
							<span><?php echo esc_html( $figma_mode_label ); ?></span>
						</div>
						<div class="mcpwp-figma-panel__row">
							<strong><?php esc_html_e( 'OAuth Redirect URI', 'mumcp' ); ?></strong>
							<code><?php echo esc_html( admin_url( 'admin-post.php?action=mcpwp_figma_oauth_callback' ) ); ?></code>
						</div>
						<div class="mcpwp-figma-panel__row">
							<strong><?php esc_html_e( 'Model Flow', 'mumcp' ); ?></strong>
							<span><?php esc_html_e( 'Inspect Figma, then translate it into local archetypes and reusable parts.', 'mumcp' ); ?></span>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $locked ) : ?>
					<?php
					// F-14: per-provider unlock descriptions; always show upgrade link regardless of license function.
					$unlock_descriptions = array(
						'openai'          => __( 'Unlocks AI image generation, vision analysis, alt-text generation, and content summarisation via GPT models.', 'mumcp' ),
						'gemini'          => __( 'Unlocks image generation and vision analysis via Google Gemini — use alongside or instead of OpenAI.', 'mumcp' ),
						'elevenlabs'      => __( 'Unlocks text-to-speech audio generation from any page content via the ElevenLabs API.', 'mumcp' ),
						'google_indexing' => __( 'Unlocks direct URL submission to Google Search via the Indexing API — speeds up content discovery.', 'mumcp' ),
						'figma'           => __( 'Unlocks Figma design context intake — inspect approved frames and convert them into archetypes and reusable parts.', 'mumcp' ),
					);
					$unlock_desc = isset( $unlock_descriptions[ $slug ] ) ? $unlock_descriptions[ $slug ] : '';
					?>
					<p class="mcpwp-locked-msg">
						<?php if ( $unlock_desc ) : ?>
							<?php echo esc_html( $unlock_desc ); ?><br />
						<?php endif; ?>
						<a href="<?php echo esc_url( 'https://mumcp.com/pricing/' ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Upgrade to Pro &rarr;', 'mumcp' ); ?>
						</a>
					</p>
				<?php else : ?>
					<div class="mcpwp-integration-key-form" data-provider="<?php echo esc_attr( $slug ); ?>" data-multi-field="<?php echo $is_multi_field ? '1' : '0'; ?>">
						<?php if ( $provider['configured'] ) : ?>
							<div class="mcpwp-key-configured">
								<span class="mcpwp-status-dot" style="background:<?php echo 'ok' === $provider['test_status'] ? '#00a32a' : ( 'failed' === $provider['test_status'] ? '#d63638' : '#dba617' ); ?>;"></span>
								<code class="mcpwp-key-code">
									<?php
									esc_html_e( 'Configured', 'mumcp' );
									if ( $provider['configured_at'] ) {
										echo ' &mdash; ' . esc_html( human_time_diff( strtotime( $provider['configured_at'] ) ) ) . ' ago';
									}
									?>
								</code>
							</div>
							<div class="mcpwp-action-row">
								<button type="button" class="button mcpwp-test-integration" data-provider="<?php echo esc_attr( $slug ); ?>">
									<?php esc_html_e( 'Test Connection', 'mumcp' ); ?>
								</button>
								<button type="button" class="button mcpwp-remove-integration mcpwp-remove-btn" data-provider="<?php echo esc_attr( $slug ); ?>">
									<?php esc_html_e( 'Remove', 'mumcp' ); ?>
								</button>
								<?php if ( $is_figma && ! empty( $provider['oauth_ready'] ) ) : ?>
									<a href="<?php echo esc_url( $integrations_admin->get_figma_oauth_start_url() ); ?>" class="button">
										<?php esc_html_e( 'Reconnect OAuth', 'mumcp' ); ?>
									</a>
								<?php endif; ?>
							</div>
							<div class="mcpwp-update-section">
								<?php if ( $is_figma && ! empty( $provider['auth_mode'] ) ) : ?>
									<p class="description mcpwp-desc-mb">
										<?php
										if ( 'personal_token' === $figma_auth_mode ) {
											esc_html_e( 'Using a personal token. OAuth is ready if you want the cleaner long-term setup.', 'mumcp' );
										} elseif ( 'oauth' === $figma_auth_mode ) {
											esc_html_e( 'Using OAuth. Models can rely on this connection for approved Figma design context.', 'mumcp' );
										} else {
											esc_html_e( 'Figma credentials are stored, but the connection is not complete yet.', 'mumcp' );
										}
										?>
									</p>
								<?php endif; ?>
								<?php if ( $is_multi_field ) : ?>
									<div class="mcpwp-multi-field-inputs is-hidden">
										<?php foreach ( $provider['fields'] as $field_key => $field_info ) : ?>
											<div class="mcpwp-field-row">
												<label class="mcpwp-field-label"><?php echo esc_html( $field_info['label'] ); ?></label>
												<input type="<?php echo 'password' === $field_info['type'] ? 'password' : 'text'; ?>"
													class="regular-text mcpwp-config-field"
													data-field="<?php echo esc_attr( $field_key ); ?>"
													placeholder="<?php echo esc_attr( isset( $field_info['placeholder'] ) ? $field_info['placeholder'] : '' ); ?>" />
											</div>
										<?php endforeach; ?>
									</div>
								<?php else : ?>
									<input type="text" class="regular-text mcpwp-integration-key-input is-hidden" placeholder="<?php esc_attr_e( 'Paste new key to update...', 'mumcp' ); ?>" />
								<?php endif; ?>
								<button type="button" class="button mcpwp-update-key-toggle" data-provider="<?php echo esc_attr( $slug ); ?>">
									<?php esc_html_e( 'Update', 'mumcp' ); ?>
								</button>
								<button type="button" class="button button-primary mcpwp-save-integration is-hidden" data-provider="<?php echo esc_attr( $slug ); ?>">
									<?php esc_html_e( 'Save', 'mumcp' ); ?>
								</button>
							</div>
						<?php else : ?>
							<?php if ( $is_figma && ! empty( $provider['oauth_ready'] ) ) : ?>
								<p class="mcpwp-desc-mb-sm">
									<a href="<?php echo esc_url( $integrations_admin->get_figma_oauth_start_url() ); ?>" class="button">
										<?php esc_html_e( 'Connect with Figma OAuth', 'mumcp' ); ?>
									</a>
								</p>
								<p class="description mcpwp-desc-mb-md">
									<?php esc_html_e( 'Recommended for production. Use a personal token only for quick testing or temporary access.', 'mumcp' ); ?>
								</p>
							<?php endif; ?>
							<?php if ( $is_multi_field ) : ?>
								<div class="mcpwp-multi-field-inputs">
									<?php foreach ( $provider['fields'] as $field_key => $field_info ) : ?>
										<div class="mcpwp-field-row">
											<label class="mcpwp-field-label"><?php echo esc_html( $field_info['label'] ); ?></label>
											<input type="<?php echo 'password' === $field_info['type'] ? 'password' : 'text'; ?>"
												class="regular-text mcpwp-config-field"
												data-field="<?php echo esc_attr( $field_key ); ?>"
												placeholder="<?php echo esc_attr( isset( $field_info['placeholder'] ) ? $field_info['placeholder'] : '' ); ?>" />
										</div>
									<?php endforeach; ?>
								</div>
								<button type="button" class="button button-primary mcpwp-save-integration" data-provider="<?php echo esc_attr( $slug ); ?>">
									<?php esc_html_e( 'Save', 'mumcp' ); ?>
								</button>
							<?php else : ?>
								<div class="mcpwp-inline-action">
									<input type="text" class="regular-text mcpwp-integration-key-input" placeholder="<?php esc_attr_e( 'Paste your API key...', 'mumcp' ); ?>" />
									<button type="button" class="button button-primary mcpwp-save-integration" data-provider="<?php echo esc_attr( $slug ); ?>">
										<?php esc_html_e( 'Save', 'mumcp' ); ?>
									</button>
								</div>
							<?php endif; ?>
						<?php endif; ?>
						<span class="mcpwp-integration-status"></span>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="mcpwp-info-panel">
		<h3><?php esc_html_e( 'Available MCP Tools', 'mumcp' ); ?></h3>
		<p class="mcpwp-info-text">
			<?php esc_html_e( 'Once configured, these tools become available to AI assistants via MCP:', 'mumcp' ); ?>
		</p>
		<table class="widefat striped mcpwp-data-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tool', 'mumcp' ); ?></th>
					<th><?php esc_html_e( 'Provider', 'mumcp' ); ?></th>
					<th><?php esc_html_e( 'Tier', 'mumcp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>wp_screenshot_url</code></td><td>Screenshot Worker</td><td>Core</td></tr>
				<tr><td><code>wp_search_stock_photos</code></td><td>Pexels</td><td>Core</td></tr>
				<tr><td><code>wp_download_stock_photo</code></td><td>Pexels</td><td>Core</td></tr>
				<tr><td><code>wp_generate_image</code></td><td>OpenAI / Gemini</td><td>Paid</td></tr>
				<tr><td><code>wp_generate_featured_image</code></td><td>OpenAI / Gemini</td><td>Paid</td></tr>
				<tr><td><code>wp_generate_alt_text</code></td><td>OpenAI / Gemini</td><td>Paid</td></tr>
				<tr><td><code>wp_describe_image</code></td><td>OpenAI / Gemini</td><td>Paid</td></tr>
				<tr><td><code>wp_generate_excerpt</code></td><td>OpenAI / Gemini</td><td>Paid</td></tr>
				<tr><td><code>wp_text_to_speech</code></td><td>ElevenLabs</td><td>Paid</td></tr>
				<tr><td><code>wp_figma_status</code></td><td>Figma</td><td>Paid</td></tr>
				<tr><td><code>wp_get_figma_file</code></td><td>Figma</td><td>Paid</td></tr>
				<tr><td><code>wp_get_figma_node</code></td><td>Figma</td><td>Paid</td></tr>
			</tbody>
		</table>
		<h4><?php esc_html_e( 'Integration Management Tools', 'mumcp' ); ?></h4>
		<p class="mcpwp-info-text">
			<?php esc_html_e( 'AI assistants can manage integrations directly via MCP:', 'mumcp' ); ?>
		</p>
		<table class="widefat striped mcpwp-data-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tool', 'mumcp' ); ?></th>
					<th><?php esc_html_e( 'Description', 'mumcp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>wp_integrations_status</code></td><td><?php esc_html_e( 'List all integrations and their configuration status', 'mumcp' ); ?></td></tr>
				<tr><td><code>wp_configure_integration</code></td><td><?php esc_html_e( 'Set up a provider (API key or URL+token)', 'mumcp' ); ?></td></tr>
				<tr><td><code>wp_test_integration</code></td><td><?php esc_html_e( 'Test a provider connection', 'mumcp' ); ?></td></tr>
				<tr><td><code>wp_remove_integration</code></td><td><?php esc_html_e( 'Remove a provider configuration', 'mumcp' ); ?></td></tr>
			</tbody>
		</table>
	</div>
</div>

<!-- Inline JS removed in sprint11-batch3; all integration interactions handled by
     mcpwp-admin.js, reading mcpwpAdmin.integrationsNonce and mcpwpAdmin.strings.
     Visual affordance (success/fail class) applied via mcpwp-status-active/inactive. -->
