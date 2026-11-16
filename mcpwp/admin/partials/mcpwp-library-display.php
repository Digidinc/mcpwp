<?php
/**
 * Library page template.
 *
 * Variables available from render_library_page():
 *   $library_inventory      — array  filtered library items
 *   $library_filters        — array  active filter values
 *   $library_filter_options — array  available filter options (classes, styles)
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$unused_reference_count = count(
	array_filter(
		$library_inventory['design_references'],
		static function ( $item ) {
			return empty( $item['page_count'] ) && empty( $item['linked_part_count'] ) && empty( $item['linked_archetype_count'] );
		}
	)
);
$unlinked_part_count = count(
	array_filter(
		$library_inventory['parts'],
		static function ( $item ) {
			return empty( $item['reference_count'] );
		}
	)
);
$unlinked_archetype_count = count(
	array_filter(
		$library_inventory['page_archetypes'],
		static function ( $item ) {
			return empty( $item['reference_count'] );
		}
	)
);
?>

<div class="wrap mcpwp-admin">
	<h1 class="mcpwp-header">
		<span class="mcpwp-logo">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped in helper.
			echo Mcpwp_Admin_Brand::get_header_badge_markup();
			?>
		</span>
		<?php esc_html_e( 'Library', 'mcpwp' ); ?>
		<span class="mcpwp-version">v<?php echo esc_html( MCPWP_VERSION ); ?></span>
	</h1>

	<?php settings_errors( 'mcpwp_messages' ); ?>

	<div class="mcpwp-tab-content">
		<!-- F-19: Operating Sequence moved to first card after page header -->
		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Operating Sequence', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'This is the operator loop MCPWP is designed around. New models and humans should follow this path instead of building pages from scratch every time.', 'mcpwp' ); ?>
			</p>
			<div class="mcpwp-workflow-sequence">
				<div class="mcpwp-workflow-step">
					<span class="mcpwp-workflow-step__number">1</span>
					<strong><?php esc_html_e( 'Define Character', 'mcpwp' ); ?></strong>
					<div class="mcpwp-design-reference__meta"><?php esc_html_e( 'Set the site voice, audience, and structure rules first.', 'mcpwp' ); ?></div>
				</div>
				<div class="mcpwp-workflow-step">
					<span class="mcpwp-workflow-step__number">2</span>
					<strong><?php esc_html_e( 'Store References', 'mcpwp' ); ?></strong>
					<div class="mcpwp-design-reference__meta"><?php esc_html_e( 'Turn screenshots, mockups, and approved designs into reusable references.', 'mcpwp' ); ?></div>
				</div>
				<div class="mcpwp-workflow-step">
					<span class="mcpwp-workflow-step__number">3</span>
					<strong><?php esc_html_e( 'Reuse Archetypes', 'mcpwp' ); ?></strong>
					<div class="mcpwp-design-reference__meta"><?php esc_html_e( 'Start from saved page or product structures before inventing anything new.', 'mcpwp' ); ?></div>
				</div>
				<div class="mcpwp-workflow-step">
					<span class="mcpwp-workflow-step__number">4</span>
					<strong><?php esc_html_e( 'Build Drafts', 'mcpwp' ); ?></strong>
					<div class="mcpwp-design-reference__meta"><?php esc_html_e( 'Create draft pages and products, then review instead of publishing blindly.', 'mcpwp' ); ?></div>
				</div>
				<div class="mcpwp-workflow-step">
					<span class="mcpwp-workflow-step__number">5</span>
					<strong><?php esc_html_e( 'Save Reusable Parts', 'mcpwp' ); ?></strong>
					<div class="mcpwp-design-reference__meta"><?php esc_html_e( 'Good sections should compound into the library for the next build.', 'mcpwp' ); ?></div>
				</div>
			</div>
		</div>

		<div class="mcpwp-card">
			<h2>
				<span class="dashicons dashicons-screenoptions"></span>
				<?php esc_html_e( 'Structured Design Library', 'mcpwp' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'This is the reusable system your models should build against. MCPWP stores reusable page structures and sections as Elementor templates, then adds archetype and part metadata on top so they stay editable in Elementor and reusable via MCP.', 'mcpwp' ); ?>
			</p>

			<div class="mcpwp-library-summary">
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value"><?php echo esc_html( count( $library_inventory['page_archetypes'] ) ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Page Archetypes', 'mcpwp' ); ?></span>
				</div>
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value"><?php echo esc_html( count( $library_inventory['product_archetypes'] ) ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Product Archetypes', 'mcpwp' ); ?></span>
				</div>
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value"><?php echo esc_html( count( $library_inventory['parts'] ) ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Reusable Parts', 'mcpwp' ); ?></span>
				</div>
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value"><?php echo esc_html( count( $library_inventory['design_references'] ) ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Design References', 'mcpwp' ); ?></span>
				</div>
			</div>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Library Health', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Use this to spot references that have not produced assets yet, and library items that are not connected back to a source design.', 'mcpwp' ); ?>
			</p>
			<div class="mcpwp-library-summary">
				<div class="mcpwp-library-stat mcpwp-library-stat--warning">
					<span class="mcpwp-library-stat__value"><?php echo esc_html( $unused_reference_count ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Unused References', 'mcpwp' ); ?></span>
				</div>
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value"><?php echo esc_html( $unlinked_part_count ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Parts Without Reference Links', 'mcpwp' ); ?></span>
				</div>
				<div class="mcpwp-library-stat">
					<span class="mcpwp-library-stat__value"><?php echo esc_html( $unlinked_archetype_count ); ?></span>
					<span class="mcpwp-library-stat__label"><?php esc_html_e( 'Archetypes Without Reference Links', 'mcpwp' ); ?></span>
				</div>
			</div>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Find Assets', 'mcpwp' ); ?></h2>
			<form method="get" class="mcpwp-library-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Mcpwp_Admin::LIBRARY_PAGE_SLUG ); ?>" />
				<div class="mcpwp-library-filters__grid">
					<p>
						<label for="library_search"><strong><?php esc_html_e( 'Search', 'mcpwp' ); ?></strong></label><br />
						<input type="text" id="library_search" name="library_search" class="regular-text" value="<?php echo esc_attr( $library_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'hero, blog_post, premium, homepage', 'mcpwp' ); ?>" />
					</p>
					<p>
						<label for="library_asset_type"><strong><?php esc_html_e( 'Asset Type', 'mcpwp' ); ?></strong></label><br />
						<select id="library_asset_type" name="library_asset_type">
							<option value="all" <?php selected( 'all', $library_filters['asset_type'] ); ?>><?php esc_html_e( 'All', 'mcpwp' ); ?></option>
							<option value="archetypes" <?php selected( 'archetypes', $library_filters['asset_type'] ); ?>><?php esc_html_e( 'Page Archetypes', 'mcpwp' ); ?></option>
							<option value="products" <?php selected( 'products', $library_filters['asset_type'] ); ?>><?php esc_html_e( 'Product Archetypes', 'mcpwp' ); ?></option>
							<option value="parts" <?php selected( 'parts', $library_filters['asset_type'] ); ?>><?php esc_html_e( 'Reusable Parts', 'mcpwp' ); ?></option>
							<option value="references" <?php selected( 'references', $library_filters['asset_type'] ); ?>><?php esc_html_e( 'Design References', 'mcpwp' ); ?></option>
						</select>
					</p>
					<p>
						<label for="library_class"><strong><?php esc_html_e( 'Class / Kind', 'mcpwp' ); ?></strong></label><br />
						<select id="library_class" name="library_class">
							<option value=""><?php esc_html_e( 'All', 'mcpwp' ); ?></option>
							<?php foreach ( $library_filter_options['classes'] as $class_option ) : ?>
								<option value="<?php echo esc_attr( $class_option ); ?>" <?php selected( $class_option, $library_filters['class'] ); ?>><?php echo esc_html( $class_option ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="library_style"><strong><?php esc_html_e( 'Style', 'mcpwp' ); ?></strong></label><br />
						<select id="library_style" name="library_style">
							<option value=""><?php esc_html_e( 'All', 'mcpwp' ); ?></option>
							<?php foreach ( $library_filter_options['styles'] as $style_option ) : ?>
								<option value="<?php echo esc_attr( $style_option ); ?>" <?php selected( $style_option, $library_filters['style'] ); ?>><?php echo esc_html( $style_option ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				</div>
				<p class="mcpwp-library-filters__actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'mcpwp' ); ?></button>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Mcpwp_Admin::LIBRARY_PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Reset', 'mcpwp' ); ?></a>
				</p>
			</form>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Design References', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Store screenshots, mockups, and design inspiration here before turning them into archetypes or reusable Elementor parts. This gives models a visual source of truth to work from.', 'mcpwp' ); ?>
			</p>
			<?php if ( empty( $library_inventory['design_references'] ) ) : ?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-format-image"></span>
					<p><?php esc_html_e( 'No design references saved yet — add one below to give models a visual source of truth.', 'mcpwp' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Reference', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'ID', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Intent / Class', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Style', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Source', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Tags / Reuse Notes', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'mcpwp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $library_inventory['design_references'] as $item ) : ?>
						<tr>
							<td>
								<div class="mcpwp-design-reference">
									<?php if ( ! empty( $item['image_url'] ) ) : ?>
										<img class="mcpwp-design-reference__thumb" src="<?php echo esc_url( $item['image_url'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" />
									<?php endif; ?>
									<div class="mcpwp-design-reference__body">
										<strong><?php echo esc_html( $item['title'] ? $item['title'] : __( 'Untitled Reference', 'mcpwp' ) ); ?></strong>
										<?php if ( ! empty( $item['analysis_summary'] ) ) : ?>
											<div class="mcpwp-design-reference__meta"><?php echo esc_html( $item['analysis_summary'] ); ?></div>
										<?php elseif ( ! empty( $item['notes'] ) ) : ?>
											<div class="mcpwp-design-reference__meta"><?php echo esc_html( $item['notes'] ); ?></div>
										<?php endif; ?>
										<div class="mcpwp-design-reference__meta">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: page count */
													_n( 'Used on %d page', 'Used on %d pages', (int) $item['page_count'], 'mcpwp' ),
													(int) $item['page_count']
												)
											);
											?>
										</div>
										<?php if ( ! empty( $item['linked_pages'] ) ) : ?>
											<div class="mcpwp-design-reference__meta">
												<strong><?php esc_html_e( 'Pages:', 'mcpwp' ); ?></strong>
												<?php foreach ( $item['linked_pages'] as $page_link ) : ?>
													<a href="<?php echo esc_url( $page_link['url'] ); ?>"><?php echo esc_html( $page_link['title'] ? $page_link['title'] : '#' . $page_link['id'] ); ?></a><?php echo end( $item['linked_pages'] ) === $page_link ? '' : ', '; ?>
												<?php endforeach; ?>
											</div>
										<?php endif; ?>
										<div class="mcpwp-row-actions mcpwp-row-actions--stack">
											<form method="post" class="mcpwp-inline-action mcpwp-inline-action--grid">
												<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
												<input type="hidden" name="mcpwp_action_design_reference_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
												<input type="text" name="mcpwp_design_reference_page_title" class="regular-text" placeholder="<?php esc_attr_e( 'Draft page title', 'mcpwp' ); ?>" />
												<button type="submit" name="mcpwp_create_page_from_design_reference" class="button button-small"><?php esc_html_e( 'Create Draft Page', 'mcpwp' ); ?></button>
											</form>
										</div>
									</div>
								</div>
							</td>
							<td><code><?php echo esc_html( $item['id'] ); ?></code></td>
							<td>
								<div><code><?php echo esc_html( $item['page_intent'] ? $item['page_intent'] : 'general' ); ?></code></div>
								<?php if ( ! empty( $item['archetype_class'] ) ) : ?>
									<div class="mcpwp-design-reference__meta"><?php echo esc_html( $item['archetype_class'] ); ?></div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $item['style'] ? $item['style'] : 'default' ); ?></td>
							<td>
								<span class="mcpwp-origin-badge"><?php echo esc_html( $item['source_type'] ? $item['source_type'] : 'manual' ); ?></span>
								<?php if ( ! empty( $item['media_id'] ) ) : ?>
									<div class="mcpwp-design-reference__meta"><?php echo esc_html( 'Media #' . $item['media_id'] ); ?></div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $item['tags'] ) ) : ?>
									<div class="mcpwp-tag-list">
										<?php foreach ( $item['tags'] as $tag ) : ?>
											<span class="mcpwp-tag"><?php echo esc_html( $tag ); ?></span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<?php if ( ! empty( $item['must_keep'] ) ) : ?>
									<div class="mcpwp-design-reference__meta"><strong><?php esc_html_e( 'Keep:', 'mcpwp' ); ?></strong> <?php echo esc_html( implode( ', ', $item['must_keep'] ) ); ?></div>
								<?php endif; ?>
								<?php if ( ! empty( $item['avoid'] ) ) : ?>
									<div class="mcpwp-design-reference__meta"><strong><?php esc_html_e( 'Avoid:', 'mcpwp' ); ?></strong> <?php echo esc_html( implode( ', ', $item['avoid'] ) ); ?></div>
								<?php endif; ?>
								<?php if ( ! empty( $item['linked_part_count'] ) || ! empty( $item['linked_archetype_count'] ) ) : ?>
									<div class="mcpwp-design-reference__meta">
										<strong><?php esc_html_e( 'Linked:', 'mcpwp' ); ?></strong>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: part count 2: archetype count */
												__( '%1$d parts, %2$d archetypes', 'mcpwp' ),
												(int) $item['linked_part_count'],
												(int) $item['linked_archetype_count']
											)
										);
										?>
									</div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $item['updated_at'] ? $item['updated_at'] : '' ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Add Design Reference', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Upload a screenshot, paste a design URL, or point at existing media. Save the intent and reuse rules now so future models can turn it into archetypes and reusable parts.', 'mcpwp' ); ?>
			</p>

			<!-- F-11: split into Required / Optional fieldsets -->
			<form method="post" enctype="multipart/form-data" class="mcpwp-library-form">
				<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>

				<fieldset class="mcpwp-fieldset">
					<legend class="mcpwp-fieldset__legend"><?php esc_html_e( 'Required', 'mcpwp' ); ?></legend>
					<p>
						<label for="mcpwp_design_reference_title">
							<strong><?php esc_html_e( 'Title', 'mcpwp' ); ?></strong>
							<span class="required" aria-label="<?php esc_attr_e( 'required', 'mcpwp' ); ?>"> *</span>
						</label><br />
						<input type="text" id="mcpwp_design_reference_title" name="mcpwp_design_reference_title" class="regular-text" placeholder="<?php esc_attr_e( 'Homepage Hero Inspiration / SaaS', 'mcpwp' ); ?>" required aria-required="true" />
					</p>
					<p>
						<label for="mcpwp_design_reference_file"><strong><?php esc_html_e( 'Upload Image', 'mcpwp' ); ?></strong></label><br />
						<input type="file" id="mcpwp_design_reference_file" name="mcpwp_design_reference_file" accept="image/*" />
					</p>
					<p>
						<label for="mcpwp_design_reference_url"><strong><?php esc_html_e( 'Image URL', 'mcpwp' ); ?></strong></label><br />
						<input type="url" id="mcpwp_design_reference_url" name="mcpwp_design_reference_url" class="large-text" placeholder="<?php esc_attr_e( 'https://example.com/reference.png', 'mcpwp' ); ?>" />
					</p>
					<p>
						<label for="mcpwp_design_reference_media_id"><strong><?php esc_html_e( 'Existing Media ID', 'mcpwp' ); ?></strong></label><br />
						<input type="number" min="1" id="mcpwp_design_reference_media_id" name="mcpwp_design_reference_media_id" class="small-text" />
					</p>
					<p>
						<label for="mcpwp_design_reference_intent"><strong><?php esc_html_e( 'Page Intent', 'mcpwp' ); ?></strong></label><br />
						<input type="text" id="mcpwp_design_reference_intent" name="mcpwp_design_reference_intent" class="regular-text" placeholder="<?php esc_attr_e( 'landing_page, blog_post, product_page', 'mcpwp' ); ?>" />
					</p>
				</fieldset>

				<details class="mcpwp-advanced-fields">
					<summary class="mcpwp-advanced-fields__toggle">
						<?php esc_html_e( 'Show advanced fields', 'mcpwp' ); ?> <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					</summary>
					<fieldset class="mcpwp-fieldset mcpwp-fieldset--optional">
						<legend class="mcpwp-fieldset__legend"><?php esc_html_e( 'Optional: Analysis &amp; Rules', 'mcpwp' ); ?></legend>
						<p>
							<label for="mcpwp_design_reference_class"><strong><?php esc_html_e( 'Archetype Class', 'mcpwp' ); ?></strong></label><br />
							<input type="text" id="mcpwp_design_reference_class" name="mcpwp_design_reference_class" class="regular-text" placeholder="<?php esc_attr_e( 'saas_landing, editorial_blog, digital_product', 'mcpwp' ); ?>" />
						</p>
						<p>
							<label for="mcpwp_design_reference_style"><strong><?php esc_html_e( 'Style', 'mcpwp' ); ?></strong></label><br />
							<input type="text" id="mcpwp_design_reference_style" name="mcpwp_design_reference_style" class="regular-text" placeholder="<?php esc_attr_e( 'showcase, editorial, premium', 'mcpwp' ); ?>" />
						</p>
						<p>
							<label for="mcpwp_design_reference_tags"><strong><?php esc_html_e( 'Tags', 'mcpwp' ); ?></strong></label><br />
							<input type="text" id="mcpwp_design_reference_tags" name="mcpwp_design_reference_tags" class="regular-text" placeholder="<?php esc_attr_e( 'hero, pricing, b2b', 'mcpwp' ); ?>" />
						</p>
						<p>
							<label for="mcpwp_design_reference_notes"><strong><?php esc_html_e( 'Notes', 'mcpwp' ); ?></strong></label><br />
							<textarea id="mcpwp_design_reference_notes" name="mcpwp_design_reference_notes" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Why this design matters and where it should be used.', 'mcpwp' ); ?>"></textarea>
						</p>
						<p>
							<label for="mcpwp_design_reference_summary"><strong><?php esc_html_e( 'Analysis Summary', 'mcpwp' ); ?></strong></label><br />
							<textarea id="mcpwp_design_reference_summary" name="mcpwp_design_reference_summary" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Short structural summary of the design.', 'mcpwp' ); ?>"></textarea>
						</p>
						<p>
							<label for="mcpwp_design_reference_must_keep"><strong><?php esc_html_e( 'Must Keep', 'mcpwp' ); ?></strong></label><br />
							<textarea id="mcpwp_design_reference_must_keep" name="mcpwp_design_reference_must_keep" rows="4" class="large-text" placeholder="<?php esc_attr_e( "One item per line:\nstrong headline\nleft-aligned proof strip", 'mcpwp' ); ?>"></textarea>
						</p>
						<p>
							<label for="mcpwp_design_reference_avoid"><strong><?php esc_html_e( 'Avoid', 'mcpwp' ); ?></strong></label><br />
							<textarea id="mcpwp_design_reference_avoid" name="mcpwp_design_reference_avoid" rows="4" class="large-text" placeholder="<?php esc_attr_e( "One item per line:\ncarousel\ndense paragraph blocks", 'mcpwp' ); ?>"></textarea>
						</p>
						<p>
							<label for="mcpwp_design_reference_outline"><strong><?php esc_html_e( 'Section Outline', 'mcpwp' ); ?></strong></label><br />
							<textarea id="mcpwp_design_reference_outline" name="mcpwp_design_reference_outline" rows="5" class="large-text" placeholder="<?php esc_attr_e( "One section per line:\nhero\nfeature grid\ntestimonials\ncta", 'mcpwp' ); ?>"></textarea>
						</p>
					</fieldset>
				</details>

				<p style="margin-top:14px">
					<button type="submit" name="mcpwp_create_design_reference" class="button button-primary"><?php esc_html_e( 'Save Design Reference', 'mcpwp' ); ?></button>
				</p>
			</form>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Page Archetypes', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Page archetypes are Elementor templates marked as canonical structures for blog posts, landing pages, service pages, and other repeatable layouts. Models should start from one of these before generating a page from scratch.', 'mcpwp' ); ?>
			</p>
			<?php if ( empty( $library_inventory['page_archetypes'] ) ) : ?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-layout"></span>
					<p><?php esc_html_e( 'No page archetypes saved yet — promote an Elementor template below to create the first one.', 'mcpwp' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Template', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'ID', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Class', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Style', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Type', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Modified', 'mcpwp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $library_inventory['page_archetypes'] as $item ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
								<?php if ( ! empty( $item['edit_url'] ) ) : ?>
									<div class="mcpwp-row-actions"><a href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php esc_html_e( 'Open in Elementor', 'mcpwp' ); ?></a></div>
								<?php endif; ?>
								<div class="mcpwp-design-reference__meta"><span class="mcpwp-origin-badge"><?php echo esc_html( $item['provenance_label'] ); ?></span></div>
								<div class="mcpwp-design-reference__meta">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: reference count */
											_n( 'Linked from %d design reference', 'Linked from %d design references', (int) $item['reference_count'], 'mcpwp' ),
											(int) $item['reference_count']
										)
									);
									?>
								</div>
								<?php if ( ! empty( $item['linked_references'] ) ) : ?>
									<div class="mcpwp-design-reference__meta">
										<strong><?php esc_html_e( 'References:', 'mcpwp' ); ?></strong>
										<?php foreach ( $item['linked_references'] as $reference_link ) : ?>
											<a href="<?php echo esc_url( $reference_link['url'] ); ?>"><?php echo esc_html( $reference_link['title'] ? $reference_link['title'] : $reference_link['id'] ); ?></a><?php echo end( $item['linked_references'] ) === $reference_link ? '' : ', '; ?>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<div class="mcpwp-row-actions mcpwp-row-actions--stack">
									<form method="post" class="mcpwp-inline-action">
										<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
										<input type="hidden" name="mcpwp_action_archetype_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
										<button type="submit" name="mcpwp_create_page_from_archetype" class="button button-small"><?php esc_html_e( 'Create Draft Page', 'mcpwp' ); ?></button>
									</form>
									<form method="post" class="mcpwp-inline-action">
										<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
										<input type="hidden" name="mcpwp_action_archetype_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
										<button type="submit" name="mcpwp_demote_archetype" class="button button-small"><?php esc_html_e( 'Remove Archetype Tag', 'mcpwp' ); ?></button>
									</form>
								</div>
							</td>
							<td><code><?php echo esc_html( $item['id'] ); ?></code></td>
							<td><code><?php echo esc_html( $item['archetype_class'] ? $item['archetype_class'] : 'default' ); ?></code></td>
							<td><?php echo esc_html( $item['archetype_style'] ? $item['archetype_style'] : 'default' ); ?></td>
							<td><?php echo esc_html( $item['type'] ? $item['type'] : 'page' ); ?></td>
							<td><?php echo esc_html( $item['modified'] ? $item['modified'] : '' ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Product Archetypes', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Use product archetypes to standardize WooCommerce product pages and field structure. This is where simple products, variable products, and other catalog patterns should live.', 'mcpwp' ); ?>
			</p>
			<?php if ( empty( $library_inventory['product_archetypes'] ) ) : ?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-cart"></span>
					<p><?php esc_html_e( 'No product archetypes saved yet — define one below to standardize WooCommerce product pages.', 'mcpwp' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'ID', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Class', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Style', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Product Type', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Status Default', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'mcpwp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $library_inventory['product_archetypes'] as $item ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $item['name'] ); ?></strong>
								<div class="mcpwp-row-actions mcpwp-row-actions--stack">
									<form method="post" class="mcpwp-inline-action mcpwp-inline-action--grid">
										<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
										<input type="hidden" name="mcpwp_action_product_archetype_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
										<input type="text" name="mcpwp_product_name" class="regular-text" placeholder="<?php esc_attr_e( 'Draft product name', 'mcpwp' ); ?>" />
										<button type="submit" name="mcpwp_create_product_from_archetype" class="button button-small"><?php esc_html_e( 'Create Draft Product', 'mcpwp' ); ?></button>
									</form>
									<form method="post" class="mcpwp-inline-action">
										<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
										<input type="hidden" name="mcpwp_action_product_archetype_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
										<button type="submit" name="mcpwp_delete_product_archetype" class="button button-small"><?php esc_html_e( 'Remove Archetype', 'mcpwp' ); ?></button>
									</form>
								</div>
							</td>
							<td><code><?php echo esc_html( $item['id'] ); ?></code></td>
							<td><code><?php echo esc_html( $item['archetype_class'] ? $item['archetype_class'] : 'default' ); ?></code></td>
							<td><?php echo esc_html( $item['archetype_style'] ? $item['archetype_style'] : 'default' ); ?></td>
							<td><?php echo esc_html( $item['product_type'] ? $item['product_type'] : 'simple' ); ?></td>
							<td><?php echo esc_html( $item['status'] ? $item['status'] : 'draft' ); ?></td>
							<td><?php echo esc_html( $item['updated_at'] ? $item['updated_at'] : '' ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Create Product Archetype', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Define a canonical WooCommerce product pattern once, then let models and humans generate consistent draft products from it.', 'mcpwp' ); ?>
			</p>

			<form method="post" class="mcpwp-library-form">
				<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
				<p>
					<label for="mcpwp_product_archetype_name"><strong><?php esc_html_e( 'Archetype Name', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_product_archetype_name" name="mcpwp_product_archetype_name" class="regular-text" placeholder="<?php esc_attr_e( 'Digital Course / Premium / Default', 'mcpwp' ); ?>" required />
				</p>
				<p>
					<label for="mcpwp_product_archetype_class"><strong><?php esc_html_e( 'Archetype Class', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_product_archetype_class" name="mcpwp_product_archetype_class" class="regular-text" placeholder="<?php esc_attr_e( 'simple_product, digital_product, variable_product', 'mcpwp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_product_archetype_style"><strong><?php esc_html_e( 'Style', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_product_archetype_style" name="mcpwp_product_archetype_style" class="regular-text" placeholder="<?php esc_attr_e( 'premium, minimal, editorial', 'mcpwp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_product_type"><strong><?php esc_html_e( 'Product Type', 'mcpwp' ); ?></strong></label><br />
					<select id="mcpwp_product_type" name="mcpwp_product_type">
						<option value="simple"><?php esc_html_e( 'Simple', 'mcpwp' ); ?></option>
						<option value="variable"><?php esc_html_e( 'Variable', 'mcpwp' ); ?></option>
						<option value="grouped"><?php esc_html_e( 'Grouped', 'mcpwp' ); ?></option>
						<option value="external"><?php esc_html_e( 'External', 'mcpwp' ); ?></option>
					</select>
				</p>
				<p>
					<label for="mcpwp_product_status"><strong><?php esc_html_e( 'Default Status', 'mcpwp' ); ?></strong></label><br />
					<select id="mcpwp_product_status" name="mcpwp_product_status">
						<option value="draft"><?php esc_html_e( 'Draft', 'mcpwp' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Publish', 'mcpwp' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending', 'mcpwp' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private', 'mcpwp' ); ?></option>
					</select>
				</p>
				<p>
					<label for="mcpwp_product_regular_price"><strong><?php esc_html_e( 'Regular Price', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_product_regular_price" name="mcpwp_product_regular_price" class="regular-text" placeholder="<?php esc_attr_e( '99.00', 'mcpwp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_product_sale_price"><strong><?php esc_html_e( 'Sale Price', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_product_sale_price" name="mcpwp_product_sale_price" class="regular-text" placeholder="<?php esc_attr_e( '79.00', 'mcpwp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_product_stock_status"><strong><?php esc_html_e( 'Stock Status', 'mcpwp' ); ?></strong></label><br />
					<select id="mcpwp_product_stock_status" name="mcpwp_product_stock_status">
						<option value="instock"><?php esc_html_e( 'In stock', 'mcpwp' ); ?></option>
						<option value="outofstock"><?php esc_html_e( 'Out of stock', 'mcpwp' ); ?></option>
						<option value="onbackorder"><?php esc_html_e( 'On backorder', 'mcpwp' ); ?></option>
					</select>
				</p>
				<p>
					<label><input type="checkbox" name="mcpwp_product_virtual" value="1" /> <?php esc_html_e( 'Virtual product', 'mcpwp' ); ?></label>
					<label style="margin-left:12px;"><input type="checkbox" name="mcpwp_product_downloadable" value="1" /> <?php esc_html_e( 'Downloadable product', 'mcpwp' ); ?></label>
				</p>
				<p>
					<label for="mcpwp_product_categories"><strong><?php esc_html_e( 'Default Categories', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_product_categories" name="mcpwp_product_categories" class="regular-text" placeholder="<?php esc_attr_e( 'Courses, Membership', 'mcpwp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_product_tags"><strong><?php esc_html_e( 'Default Tags', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_product_tags" name="mcpwp_product_tags" class="regular-text" placeholder="<?php esc_attr_e( 'featured, evergreen', 'mcpwp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_product_short_description"><strong><?php esc_html_e( 'Short Description', 'mcpwp' ); ?></strong></label><br />
					<textarea id="mcpwp_product_short_description" name="mcpwp_product_short_description" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Concise merchandising copy for the product summary.', 'mcpwp' ); ?>"></textarea>
				</p>
				<p>
					<label for="mcpwp_product_description"><strong><?php esc_html_e( 'Full Description', 'mcpwp' ); ?></strong></label><br />
					<textarea id="mcpwp_product_description" name="mcpwp_product_description" rows="6" class="large-text" placeholder="<?php esc_attr_e( 'Long-form product description or structure starter.', 'mcpwp' ); ?>"></textarea>
				</p>
				<p>
					<label for="mcpwp_product_archetype_brief"><strong><?php esc_html_e( 'Archetype Override Brief', 'mcpwp' ); ?></strong></label><br />
					<textarea id="mcpwp_product_archetype_brief" name="mcpwp_product_archetype_brief" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'Specific guidance for this product class.', 'mcpwp' ); ?>"></textarea>
				</p>
				<p>
					<button type="submit" name="mcpwp_create_product_archetype" class="button button-primary"><?php esc_html_e( 'Save Product Archetype', 'mcpwp' ); ?></button>
				</p>
			</form>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Reusable Elementor Parts', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Reusable parts are Elementor templates marked as reusable sections. Every strong hero, feature grid, FAQ block, testimonial strip, and CTA should be saved here so future models can reuse it instead of reinventing it.', 'mcpwp' ); ?>
			</p>
			<?php if ( empty( $library_inventory['parts'] ) ) : ?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-forms"></span>
					<p><?php esc_html_e( 'No reusable parts saved yet — extract a strong section from a live page or promote an Elementor template below.', 'mcpwp' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Part', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'ID', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Kind', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Style', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Tags', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Source', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Modified', 'mcpwp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $library_inventory['parts'] as $item ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
								<?php if ( ! empty( $item['edit_url'] ) ) : ?>
									<div class="mcpwp-row-actions"><a href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php esc_html_e( 'Open in Elementor', 'mcpwp' ); ?></a></div>
								<?php endif; ?>
								<div class="mcpwp-design-reference__meta"><span class="mcpwp-origin-badge"><?php echo esc_html( $item['provenance_label'] ); ?></span></div>
								<div class="mcpwp-design-reference__meta">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: reference count */
											_n( 'Linked from %d design reference', 'Linked from %d design references', (int) $item['reference_count'], 'mcpwp' ),
											(int) $item['reference_count']
										)
									);
									?>
								</div>
								<?php if ( ! empty( $item['linked_references'] ) ) : ?>
									<div class="mcpwp-design-reference__meta">
										<strong><?php esc_html_e( 'References:', 'mcpwp' ); ?></strong>
										<?php foreach ( $item['linked_references'] as $reference_link ) : ?>
											<a href="<?php echo esc_url( $reference_link['url'] ); ?>"><?php echo esc_html( $reference_link['title'] ? $reference_link['title'] : $reference_link['id'] ); ?></a><?php echo end( $item['linked_references'] ) === $reference_link ? '' : ', '; ?>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<div class="mcpwp-row-actions mcpwp-row-actions--stack">
									<form method="post" class="mcpwp-inline-action mcpwp-inline-action--grid">
										<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
										<input type="hidden" name="mcpwp_action_part_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
										<label for="mcpwp_target_page_id_<?php echo esc_attr( (string) $item['id'] ); ?>" class="screen-reader-text"><?php esc_html_e( 'Target page ID', 'mcpwp' ); ?></label>
								<input type="number" min="1" id="mcpwp_target_page_id_<?php echo esc_attr( (string) $item['id'] ); ?>" name="mcpwp_target_page_id" class="small-text" placeholder="<?php esc_attr_e( 'Page ID', 'mcpwp' ); ?>" required />
										<select name="mcpwp_part_apply_mode">
											<option value="insert"><?php esc_html_e( 'Insert', 'mcpwp' ); ?></option>
											<option value="replace"><?php esc_html_e( 'Replace', 'mcpwp' ); ?></option>
										</select>
										<select name="mcpwp_part_apply_position">
											<option value="end"><?php esc_html_e( 'End', 'mcpwp' ); ?></option>
											<option value="start"><?php esc_html_e( 'Start', 'mcpwp' ); ?></option>
										</select>
										<button type="submit" name="mcpwp_apply_part_to_page" class="button button-small"><?php esc_html_e( 'Apply to Page', 'mcpwp' ); ?></button>
									</form>
									<form method="post" class="mcpwp-inline-action">
										<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
										<input type="hidden" name="mcpwp_action_part_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
										<button type="submit" name="mcpwp_demote_part" class="button button-small"><?php esc_html_e( 'Remove Part Tag', 'mcpwp' ); ?></button>
									</form>
								</div>
							</td>
							<td><code><?php echo esc_html( $item['id'] ); ?></code></td>
							<td><code><?php echo esc_html( $item['part_kind'] ? $item['part_kind'] : 'section' ); ?></code></td>
							<td><?php echo esc_html( $item['part_style'] ? $item['part_style'] : 'default' ); ?></td>
							<td>
								<?php if ( ! empty( $item['part_tags'] ) ) : ?>
									<div class="mcpwp-tag-list">
										<?php foreach ( $item['part_tags'] as $tag ) : ?>
											<span class="mcpwp-tag"><?php echo esc_html( $tag ); ?></span>
										<?php endforeach; ?>
									</div>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $item['source_page_id'] ) ) : ?>
									<?php echo esc_html( $item['source_page_title'] ? $item['source_page_title'] : '#' . $item['source_page_id'] ); ?>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $item['modified'] ? $item['modified'] : '' ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Promote Existing Template', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Use this when you already have an Elementor template and want MCPWP to classify it as a canonical archetype or reusable part without duplicating the template.', 'mcpwp' ); ?>
			</p>

			<div class="mcpwp-library-actions">
				<form method="post" class="mcpwp-library-form">
					<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
					<h3><?php esc_html_e( 'Promote to Page Archetype', 'mcpwp' ); ?></h3>
					<p>
						<label for="mcpwp_archetype_template_id"><strong><?php esc_html_e( 'Template ID', 'mcpwp' ); ?></strong></label><br />
						<input type="number" min="1" id="mcpwp_archetype_template_id" name="mcpwp_archetype_template_id" class="small-text" required />
					</p>
					<p>
						<label for="mcpwp_archetype_title"><strong><?php esc_html_e( 'Title Override', 'mcpwp' ); ?></strong></label><br />
						<input type="text" id="mcpwp_archetype_title" name="mcpwp_archetype_title" class="regular-text" placeholder="<?php esc_attr_e( 'Optional', 'mcpwp' ); ?>" />
					</p>
					<p>
						<label for="mcpwp_archetype_scope"><strong><?php esc_html_e( 'Scope', 'mcpwp' ); ?></strong></label><br />
						<select id="mcpwp_archetype_scope" name="mcpwp_archetype_scope">
							<option value="page"><?php esc_html_e( 'Page', 'mcpwp' ); ?></option>
							<option value="product"><?php esc_html_e( 'Product', 'mcpwp' ); ?></option>
						</select>
					</p>
					<p>
						<label for="mcpwp_archetype_class"><strong><?php esc_html_e( 'Archetype Class', 'mcpwp' ); ?></strong></label><br />
						<input type="text" id="mcpwp_archetype_class" name="mcpwp_archetype_class" class="regular-text" placeholder="<?php esc_attr_e( 'blog_post, landing_page, service_page', 'mcpwp' ); ?>" />
					</p>
					<p>
						<label for="mcpwp_archetype_style"><strong><?php esc_html_e( 'Style', 'mcpwp' ); ?></strong></label><br />
						<input type="text" id="mcpwp_archetype_style" name="mcpwp_archetype_style" class="regular-text" placeholder="<?php esc_attr_e( 'editorial, minimal, bold', 'mcpwp' ); ?>" />
					</p>
					<p>
						<label for="mcpwp_archetype_brief"><strong><?php esc_html_e( 'Archetype Override Brief', 'mcpwp' ); ?></strong></label><br />
						<textarea id="mcpwp_archetype_brief" name="mcpwp_archetype_brief" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'Specific guidance for this page type.', 'mcpwp' ); ?>"></textarea>
					</p>
					<p>
						<button type="submit" name="mcpwp_promote_template_archetype" class="button button-primary"><?php esc_html_e( 'Save Archetype', 'mcpwp' ); ?></button>
					</p>
				</form>

				<form method="post" class="mcpwp-library-form">
					<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
					<h3><?php esc_html_e( 'Promote to Reusable Part', 'mcpwp' ); ?></h3>
					<p>
						<label for="mcpwp_part_template_id"><strong><?php esc_html_e( 'Template ID', 'mcpwp' ); ?></strong></label><br />
						<input type="number" min="1" id="mcpwp_part_template_id" name="mcpwp_part_template_id" class="small-text" required />
					</p>
					<p>
						<label for="mcpwp_part_title"><strong><?php esc_html_e( 'Title Override', 'mcpwp' ); ?></strong></label><br />
						<input type="text" id="mcpwp_part_title" name="mcpwp_part_title" class="regular-text" placeholder="<?php esc_attr_e( 'Optional', 'mcpwp' ); ?>" />
					</p>
					<p>
						<label for="mcpwp_part_kind"><strong><?php esc_html_e( 'Part Kind', 'mcpwp' ); ?></strong></label><br />
						<input type="text" id="mcpwp_part_kind" name="mcpwp_part_kind" class="regular-text" placeholder="<?php esc_attr_e( 'hero, faq, cta, testimonials', 'mcpwp' ); ?>" />
					</p>
					<p>
						<label for="mcpwp_part_style"><strong><?php esc_html_e( 'Style', 'mcpwp' ); ?></strong></label><br />
						<input type="text" id="mcpwp_part_style" name="mcpwp_part_style" class="regular-text" placeholder="<?php esc_attr_e( 'clean, editorial, premium', 'mcpwp' ); ?>" />
					</p>
					<p>
						<label for="mcpwp_part_tags"><strong><?php esc_html_e( 'Tags', 'mcpwp' ); ?></strong></label><br />
						<input type="text" id="mcpwp_part_tags" name="mcpwp_part_tags" class="regular-text" placeholder="<?php esc_attr_e( 'comma, separated, tags', 'mcpwp' ); ?>" />
					</p>
					<p>
						<button type="submit" name="mcpwp_promote_template_part" class="button button-primary"><?php esc_html_e( 'Save Reusable Part', 'mcpwp' ); ?></button>
					</p>
				</form>
			</div>
		</div>

		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Extract Live Section to Part', 'mcpwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Use this when a live page contains a strong section you want to preserve for future pages. Enter the source page ID and the Elementor element ID for the section or container.', 'mcpwp' ); ?>
			</p>

			<form method="post" class="mcpwp-library-form">
				<?php wp_nonce_field( 'mcpwp_library_actions', 'mcpwp_library_nonce' ); ?>
				<p>
					<label for="mcpwp_source_page_id"><strong><?php esc_html_e( 'Source Page ID', 'mcpwp' ); ?></strong></label><br />
					<input type="number" min="1" id="mcpwp_source_page_id" name="mcpwp_source_page_id" class="small-text" required />
				</p>
				<p>
					<label for="mcpwp_source_element_id"><strong><?php esc_html_e( 'Elementor Element ID', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_source_element_id" name="mcpwp_source_element_id" class="regular-text" required />
				</p>
				<p>
					<label for="mcpwp_extract_part_title"><strong><?php esc_html_e( 'Part Title', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_extract_part_title" name="mcpwp_extract_part_title" class="regular-text" placeholder="<?php esc_attr_e( 'Homepage Hero / Default', 'mcpwp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_extract_part_kind"><strong><?php esc_html_e( 'Part Kind', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_extract_part_kind" name="mcpwp_extract_part_kind" class="regular-text" placeholder="<?php esc_attr_e( 'hero, faq, cta, pricing', 'mcpwp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_extract_part_style"><strong><?php esc_html_e( 'Style', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_extract_part_style" name="mcpwp_extract_part_style" class="regular-text" placeholder="<?php esc_attr_e( 'bold, minimal, editorial', 'mcpwp' ); ?>" />
				</p>
				<p>
					<label for="mcpwp_extract_part_tags"><strong><?php esc_html_e( 'Tags', 'mcpwp' ); ?></strong></label><br />
					<input type="text" id="mcpwp_extract_part_tags" name="mcpwp_extract_part_tags" class="regular-text" placeholder="<?php esc_attr_e( 'homepage, saas, lead-gen', 'mcpwp' ); ?>" />
				</p>
				<p>
					<button type="submit" name="mcpwp_extract_section_part" class="button button-primary"><?php esc_html_e( 'Extract to Library', 'mcpwp' ); ?></button>
				</p>
			</form>
		</div>

		<?php
		/**
		 * Action for Pro add-on to render additional library cards.
		 */
		do_action( 'mcpwp_admin_library_cards' );

		// ── Site Blueprints (#364) ──────────────────────────────────────────── ?>
		<!-- F-12: wrapped in .mcpwp-card like every other section -->
		<div class="mcpwp-card">
			<h2><?php esc_html_e( 'Site Blueprints', 'mcpwp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Multi-page site structures ready to deploy. Use wp_deploy_site_blueprint to create all pages, menus, and site context in one step.', 'mcpwp' ); ?></p>
			<?php
			$site_blueprints = $library_inventory['site_blueprints'] ?? array();
			if ( empty( $site_blueprints ) ) :
			?>
				<div class="mcpwp-control-empty is-muted">
					<span class="dashicons dashicons-admin-site"></span>
					<p><?php esc_html_e( 'No blueprints found — extract the current site as a blueprint to create one.', 'mcpwp' ); ?></p>
				</div>
			<?php else : ?>
			<table class="widefat" style="margin-top:.5rem">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'ID', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Category', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Pages', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mcpwp' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mcpwp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $site_blueprints as $bp ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $bp['name'] ?? '' ); ?></strong></td>
						<td><code><?php echo esc_html( $bp['id'] ?? '' ); ?></code></td>
						<td><?php echo esc_html( $bp['category'] ?? '' ); ?></td>
						<td><?php echo esc_html( (string) count( $bp['pages'] ?? array() ) ); ?> <?php esc_html_e( 'pages', 'mcpwp' ); ?></td>
						<td style="color:#555"><?php echo esc_html( $bp['description'] ?? '' ); ?></td>
						<td>
							<?php if ( ! empty( $bp['is_starter'] ) ) : ?>
								<span style="color:#7c3aed"><?php esc_html_e( 'starter', 'mcpwp' ); ?></span>
							<?php else : ?>
								<span style="color:#16a34a"><?php esc_html_e( 'custom', 'mcpwp' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
			<p style="margin-top:.75rem;font-size:12px;color:#555">
				<?php esc_html_e( 'Deploy with: ', 'mcpwp' ); ?>
				<code>wp_deploy_site_blueprint(id="law-firm")</code>
				&nbsp;&middot;&nbsp;
				<?php esc_html_e( 'Save current site as blueprint: ', 'mcpwp' ); ?>
				<code>wp_extract_site_blueprint(save=true)</code>
			</p>
		</div>

	</div><!-- .mcpwp-tab-content -->
</div><!-- .wrap -->
