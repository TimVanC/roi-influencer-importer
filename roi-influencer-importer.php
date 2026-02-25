<?php
/**
 * Plugin Name: ROI Influencer Importer
 * Description: Internal admin importer for ROI Influencers and Power Lists.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'roi_influencer_importer_register_admin_menu' );

/**
 * Register the ROI Importer admin submenu under Posts.
 *
 * @return void
 */
function roi_influencer_importer_register_admin_menu() {
	add_submenu_page(
		'edit.php',
		__( 'ROI Influencer Importer', 'roi-influencer-importer' ),
		__( 'ROI Importer', 'roi-influencer-importer' ),
		'manage_options',
		'roi-influencer-importer',
		'roi_influencer_importer_render_admin_page'
	);
}

/**
 * Render the ROI Influencer Importer admin page.
 *
 * @return void
 */
function roi_influencer_importer_render_admin_page() {
	$notice_type            = '';
	$notice_message         = '';
	$preview_data           = null;
	$show_config_form       = false;
	$config_notice_type     = '';
	$config_notice_message  = '';
	$config_confirmation    = null;
	$config_values          = array(
		'title_suffix'     => '',
		'top_content'      => '',
		'category_id'      => 0,
		'base_publish_date'=> '',
		'base_publish_time'=> '',
		'spacing_interval' => 5,
		'post_status'      => 'draft',
	);

	if ( isset( $_POST['roi_csv_upload_submit'] ) ) {
		$nonce = isset( $_POST['roi_csv_upload_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_csv_upload_nonce'] ) ) : '';

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'roi_csv_upload_action' ) ) {
			$notice_type    = 'error';
			$notice_message = __( 'Security check failed. Please try again.', 'roi-influencer-importer' );
		} elseif ( ! isset( $_FILES['roi_csv_file'] ) || ! is_array( $_FILES['roi_csv_file'] ) ) {
			$notice_type    = 'error';
			$notice_message = __( 'Please choose a CSV file to upload.', 'roi-influencer-importer' );
		} else {
			$file = $_FILES['roi_csv_file'];

			if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] || empty( $file['tmp_name'] ) ) {
				$notice_type    = 'error';
				$notice_message = __( 'Please choose a CSV file to upload.', 'roi-influencer-importer' );
			} elseif ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				$notice_type    = 'error';
				$notice_message = __( 'Upload failed. Please try again.', 'roi-influencer-importer' );
			} else {
				$filename   = sanitize_file_name( wp_unslash( $file['name'] ) );
				$file_check = wp_check_filetype( $filename );

				if ( 'csv' !== strtolower( (string) $file_check['ext'] ) ) {
					$notice_type    = 'error';
					$notice_message = __( 'Invalid file type. Please upload a .csv file.', 'roi-influencer-importer' );
				} else {
					$handle = fopen( $file['tmp_name'], 'r' );

					if ( false === $handle ) {
						$notice_type    = 'error';
						$notice_message = __( 'Could not read the uploaded CSV file.', 'roi-influencer-importer' );
					} else {
						$header_row = fgetcsv( $handle );
						$row_count  = 0;

						while ( false !== fgetcsv( $handle ) ) {
							++$row_count;
						}

						fclose( $handle );

						$preview_data = array(
							'file_name' => $filename,
							'row_count' => $row_count,
							'headers'   => is_array( $header_row ) ? $header_row : array(),
						);

						$notice_type    = 'success';
						$notice_message = __( 'CSV uploaded successfully. Preview generated below.', 'roi-influencer-importer' );
						$show_config_form = true;
					}
				}
			}
		}
	}

	if ( isset( $_POST['roi_import_config_submit'] ) ) {
		$show_config_form = true;

		$config_nonce = isset( $_POST['roi_import_config_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_import_config_nonce'] ) ) : '';
		if ( empty( $config_nonce ) || ! wp_verify_nonce( $config_nonce, 'roi_import_config_action' ) ) {
			$config_notice_type    = 'error';
			$config_notice_message = __( 'Security check failed for import configuration. Please try again.', 'roi-influencer-importer' );
		} else {
			$config_values['title_suffix']      = isset( $_POST['roi_title_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_title_suffix'] ) ) : '';
			$config_values['top_content']       = isset( $_POST['roi_top_content_block'] ) ? sanitize_textarea_field( wp_unslash( $_POST['roi_top_content_block'] ) ) : '';
			$config_values['category_id']       = isset( $_POST['roi_category_id'] ) ? absint( $_POST['roi_category_id'] ) : 0;
			$config_values['base_publish_date'] = isset( $_POST['roi_base_publish_date'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_base_publish_date'] ) ) : '';
			$config_values['base_publish_time'] = isset( $_POST['roi_base_publish_time'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_base_publish_time'] ) ) : '';
			$config_values['spacing_interval']  = isset( $_POST['roi_spacing_interval'] ) ? absint( $_POST['roi_spacing_interval'] ) : 5;
			$config_values['post_status']       = isset( $_POST['roi_post_status'] ) ? sanitize_key( wp_unslash( $_POST['roi_post_status'] ) ) : 'draft';

			if ( empty( $config_values['spacing_interval'] ) ) {
				$config_values['spacing_interval'] = 5;
			}

			if ( ! in_array( $config_values['post_status'], array( 'draft', 'publish' ), true ) ) {
				$config_values['post_status'] = 'draft';
			}

			$validation_errors = array();

			if ( '' === $config_values['title_suffix'] ) {
				$validation_errors[] = __( 'Title Suffix is required.', 'roi-influencer-importer' );
			}

			if ( '' === $config_values['base_publish_date'] ) {
				$validation_errors[] = __( 'Base Publish Date is required.', 'roi-influencer-importer' );
			}

			if ( '' === $config_values['base_publish_time'] ) {
				$validation_errors[] = __( 'Base Publish Time is required.', 'roi-influencer-importer' );
			}

			if ( empty( $validation_errors ) ) {
				$selected_category_name = __( 'None selected', 'roi-influencer-importer' );
				if ( $config_values['category_id'] > 0 ) {
					$category = get_category( $config_values['category_id'] );
					if ( $category && ! is_wp_error( $category ) ) {
						$selected_category_name = $category->name;
					}
				}

				$config_confirmation = array(
					'title_suffix'      => $config_values['title_suffix'],
					'category_display'  => $selected_category_name,
					'base_datetime'     => $config_values['base_publish_date'] . ' ' . $config_values['base_publish_time'],
					'spacing_interval'  => $config_values['spacing_interval'],
					'post_status_label' => ( 'publish' === $config_values['post_status'] ) ? __( 'Publish Immediately', 'roi-influencer-importer' ) : __( 'Draft', 'roi-influencer-importer' ),
				);

				$config_notice_type    = 'success';
				$config_notice_message = __( 'Import configuration captured. No posts were created.', 'roi-influencer-importer' );
			} else {
				$config_notice_type    = 'error';
				$config_notice_message = implode( ' ', $validation_errors );
			}
		}
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'ROI Influencer Importer', 'roi-influencer-importer' ); ?></h1>
		<p><?php echo esc_html__( 'Internal CSV importer for ROI Influencers and Power Lists.', 'roi-influencer-importer' ); ?></p>

		<div class="card">
			<h2><?php echo esc_html__( 'Step 1: Upload CSV', 'roi-influencer-importer' ); ?></h2>

			<?php if ( ! empty( $notice_message ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> inline">
					<p><?php echo esc_html( $notice_message ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'roi_csv_upload_action', 'roi_csv_upload_nonce' ); ?>
				<p>
					<input type="file" name="roi_csv_file" accept=".csv,text/csv" />
				</p>
				<p>
					<?php submit_button( __( 'Upload and Preview', 'roi-influencer-importer' ), 'primary', 'roi_csv_upload_submit', false ); ?>
				</p>
			</form>

			<?php if ( is_array( $preview_data ) ) : ?>
				<hr />
				<h3><?php echo esc_html__( 'Preview', 'roi-influencer-importer' ); ?></h3>
				<p><strong><?php echo esc_html__( 'File name:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( $preview_data['file_name'] ); ?></p>
				<p><strong><?php echo esc_html__( 'Total rows detected:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( (string) $preview_data['row_count'] ); ?></p>

				<p><strong><?php echo esc_html__( 'Header columns found:', 'roi-influencer-importer' ); ?></strong></p>
				<?php if ( ! empty( $preview_data['headers'] ) ) : ?>
					<ul>
						<?php foreach ( $preview_data['headers'] as $header_column ) : ?>
							<li><?php echo esc_html( (string) $header_column ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><?php echo esc_html__( 'No header columns were found.', 'roi-influencer-importer' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php if ( $show_config_form ) : ?>
			<div class="card">
				<h2><?php echo esc_html__( 'Step 2: Configure Import', 'roi-influencer-importer' ); ?></h2>

				<?php if ( ! empty( $config_notice_message ) ) : ?>
					<div class="notice notice-<?php echo esc_attr( $config_notice_type ); ?> inline">
						<p><?php echo esc_html( $config_notice_message ); ?></p>
					</div>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( 'roi_import_config_action', 'roi_import_config_nonce' ); ?>

					<p>
						<label for="roi_title_suffix"><strong><?php echo esc_html__( 'Title Suffix (required)', 'roi-influencer-importer' ); ?></strong></label><br />
						<input type="text" id="roi_title_suffix" name="roi_title_suffix" class="regular-text" required value="<?php echo esc_attr( $config_values['title_suffix'] ); ?>" />
					</p>

					<p>
						<label for="roi_top_content_block"><strong><?php echo esc_html__( 'Top Content Block (optional)', 'roi-influencer-importer' ); ?></strong></label><br />
						<textarea id="roi_top_content_block" name="roi_top_content_block" class="large-text" rows="5"><?php echo esc_textarea( $config_values['top_content'] ); ?></textarea>
					</p>

					<p>
						<label for="roi_category_id"><strong><?php echo esc_html__( 'Category', 'roi-influencer-importer' ); ?></strong></label><br />
						<?php
						wp_dropdown_categories(
							array(
								'taxonomy'         => 'category',
								'hide_empty'       => 0,
								'name'             => 'roi_category_id',
								'id'               => 'roi_category_id',
								'selected'         => (int) $config_values['category_id'],
								'show_option_none' => __( '-- Select a category --', 'roi-influencer-importer' ),
							)
						);
						?>
					</p>

					<p>
						<label for="roi_base_publish_date"><strong><?php echo esc_html__( 'Base Publish Date (required)', 'roi-influencer-importer' ); ?></strong></label><br />
						<input type="date" id="roi_base_publish_date" name="roi_base_publish_date" required value="<?php echo esc_attr( $config_values['base_publish_date'] ); ?>" />
					</p>

					<p>
						<label for="roi_base_publish_time"><strong><?php echo esc_html__( 'Base Publish Time (required)', 'roi-influencer-importer' ); ?></strong></label><br />
						<input type="time" id="roi_base_publish_time" name="roi_base_publish_time" required value="<?php echo esc_attr( $config_values['base_publish_time'] ); ?>" />
					</p>

					<p>
						<label for="roi_spacing_interval"><strong><?php echo esc_html__( 'Spacing Interval (minutes)', 'roi-influencer-importer' ); ?></strong></label><br />
						<input type="number" id="roi_spacing_interval" name="roi_spacing_interval" min="1" step="1" value="<?php echo esc_attr( (string) $config_values['spacing_interval'] ); ?>" />
					</p>

					<p>
						<label for="roi_post_status"><strong><?php echo esc_html__( 'Post Status', 'roi-influencer-importer' ); ?></strong></label><br />
						<select id="roi_post_status" name="roi_post_status">
							<option value="draft" <?php selected( $config_values['post_status'], 'draft' ); ?>><?php echo esc_html__( 'Draft', 'roi-influencer-importer' ); ?></option>
							<option value="publish" <?php selected( $config_values['post_status'], 'publish' ); ?>><?php echo esc_html__( 'Publish Immediately', 'roi-influencer-importer' ); ?></option>
						</select>
					</p>

					<p>
						<?php submit_button( __( 'Run Import', 'roi-influencer-importer' ), 'primary', 'roi_import_config_submit', false ); ?>
					</p>
				</form>

				<?php if ( is_array( $config_confirmation ) ) : ?>
					<hr />
					<h3><?php echo esc_html__( 'Configuration Confirmation', 'roi-influencer-importer' ); ?></h3>
					<p><strong><?php echo esc_html__( 'Title suffix:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( $config_confirmation['title_suffix'] ); ?></p>
					<p><strong><?php echo esc_html__( 'Category selected:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( $config_confirmation['category_display'] ); ?></p>
					<p><strong><?php echo esc_html__( 'Base date/time:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( $config_confirmation['base_datetime'] ); ?></p>
					<p><strong><?php echo esc_html__( 'Spacing interval:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( (string) $config_confirmation['spacing_interval'] ); ?></p>
					<p><strong><?php echo esc_html__( 'Selected status:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( $config_confirmation['post_status_label'] ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
