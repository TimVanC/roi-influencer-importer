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
	$computed_preview       = null;
	$import_results         = null;
	$current_user_id        = get_current_user_id();
	$config_values          = array(
		'title_suffix'      => '',
		'top_content'       => '',
		'category_id'       => 0,
		'author_id'         => $current_user_id,
		'base_publish_date' => '',
		'base_publish_time' => '',
		'spacing_interval' => 5,
		'post_status'      => 'draft',
	);

	$stored_preview_data = get_transient( 'roi_import_preview' );
	if ( is_array( $stored_preview_data ) ) {
		$preview_data = array(
			'headers'   => isset( $stored_preview_data['headers'] ) && is_array( $stored_preview_data['headers'] ) ? $stored_preview_data['headers'] : array(),
			'row_count' => isset( $stored_preview_data['row_count'] ) ? absint( $stored_preview_data['row_count'] ) : 0,
			'rows'      => isset( $stored_preview_data['rows'] ) && is_array( $stored_preview_data['rows'] ) ? $stored_preview_data['rows'] : array(),
		);
		$show_config_form = true;
	}

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
						$parsed_rows = array();

						while ( false !== ( $row = fgetcsv( $handle ) ) ) {
							$parsed_rows[] = $row;
						}

						fclose( $handle );

						$preview_data = array(
							'row_count' => count( $parsed_rows ),
							'headers'   => is_array( $header_row ) ? $header_row : array(),
							'rows'      => $parsed_rows,
						);

						set_transient( 'roi_import_preview', $preview_data, 5 * MINUTE_IN_SECONDS );

						$notice_type    = 'success';
						$notice_message = __( 'CSV uploaded successfully. Preview generated below.', 'roi-influencer-importer' );
						$show_config_form = true;
					}
				}
			}
		}
	}

	if ( isset( $_POST['roi_run_import_submit'] ) ) {
		$show_config_form = true;

		$run_import_nonce = isset( $_POST['roi_run_import_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_run_import_nonce'] ) ) : '';
		if ( empty( $run_import_nonce ) || ! wp_verify_nonce( $run_import_nonce, 'roi_run_import_action' ) ) {
			$config_notice_type    = 'error';
			$config_notice_message = __( 'Security check failed for import run. Please try again.', 'roi-influencer-importer' );
		} else {
			$preview_data = get_transient( 'roi_import_preview' );
			if ( ! is_array( $preview_data ) || ! isset( $preview_data['headers'], $preview_data['rows'] ) || ! is_array( $preview_data['headers'] ) || ! is_array( $preview_data['rows'] ) ) {
				$config_notice_type    = 'error';
				$config_notice_message = __( 'CSV preview data is missing or expired. Please upload the CSV again.', 'roi-influencer-importer' );
			} else {
				$title_prefix                   = isset( $_POST['roi_title_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_title_suffix'] ) ) : '';
				$top_content_block              = isset( $_POST['roi_top_content_block'] ) ? sanitize_textarea_field( wp_unslash( $_POST['roi_top_content_block'] ) ) : '';
				$category_id                    = isset( $_POST['roi_category_id'] ) ? absint( $_POST['roi_category_id'] ) : 0;
				$author_id                      = isset( $_POST['roi_import_author'] ) ? intval( $_POST['roi_import_author'] ) : 0;
				$base_publish_date              = isset( $_POST['roi_base_publish_date'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_base_publish_date'] ) ) : '';
				$base_publish_time              = isset( $_POST['roi_base_publish_time'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_base_publish_time'] ) ) : '';
				$spacing_interval               = isset( $_POST['roi_spacing_interval'] ) ? absint( $_POST['roi_spacing_interval'] ) : 5;
				$selected_status                = isset( $_POST['roi_post_status'] ) ? sanitize_key( wp_unslash( $_POST['roi_post_status'] ) ) : 'draft';

				$validation_errors = array();

				$user           = get_userdata( $author_id );
				$allowed_roles  = array( 'administrator', 'editor', 'author' );
				$has_valid_role = ( $user && isset( $user->roles ) ) ? array_intersect( $allowed_roles, $user->roles ) : array();
				if ( $author_id <= 0 || ! $user || empty( $has_valid_role ) ) {
					$validation_errors[] = __( 'Author is required and must be an administrator, editor, or author.', 'roi-influencer-importer' );
				}

				if ( '' === $title_prefix ) {
					$validation_errors[] = __( 'Title Suffix is required.', 'roi-influencer-importer' );
				}

				$last_name_index  = roi_influencer_importer_find_header_index( $preview_data['headers'], 'lastname' );
				$first_name_index = roi_influencer_importer_find_header_index( $preview_data['headers'], 'firstname' );
				$position_index   = roi_influencer_importer_find_header_index( $preview_data['headers'], 'position' );
				$company_index    = roi_influencer_importer_find_header_index( $preview_data['headers'], 'company' );
				$writeup_index    = roi_influencer_importer_find_header_index( $preview_data['headers'], 'writeup' );
				if ( false === $writeup_index ) {
					$writeup_index = roi_influencer_importer_find_header_index( $preview_data['headers'], 'newwriteup' );
				}

				if ( false === $last_name_index || false === $first_name_index ) {
					$validation_errors[] = __( 'CSV must include firstname and lastname columns.', 'roi-influencer-importer' );
				}

				if ( empty( $spacing_interval ) ) {
					$spacing_interval = 5;
				}

				if ( ! in_array( $selected_status, array( 'draft', 'publish' ), true ) ) {
					$selected_status = 'draft';
				}

				$base_timestamp = strtotime( $base_publish_date . ' ' . $base_publish_time );
				if ( false === $base_timestamp ) {
					$validation_errors[] = __( 'Base date/time is invalid.', 'roi-influencer-importer' );
				}

				if ( empty( $validation_errors ) ) {
					$sorted_rows = $preview_data['rows'];
					usort(
						$sorted_rows,
						static function( $row_a, $row_b ) use ( $last_name_index ) {
							$last_a = isset( $row_a[ $last_name_index ] ) ? (string) $row_a[ $last_name_index ] : '';
							$last_b = isset( $row_b[ $last_name_index ] ) ? (string) $row_b[ $last_name_index ] : '';
							return strcasecmp( $last_a, $last_b );
						}
					);

					$batch_id         = 'roi_batch_' . time();
					$total_attempted  = 0;
					$total_successful = 0;
					$failures         = array();

					foreach ( $sorted_rows as $row_index => $row ) {
						++$total_attempted;

						$last_name = isset( $row[ $last_name_index ] ) ? trim( (string) $row[ $last_name_index ] ) : '';
						$first_name = isset( $row[ $first_name_index ] ) ? trim( (string) $row[ $first_name_index ] ) : '';
						$position = ( false !== $position_index && isset( $row[ $position_index ] ) ) ? (string) $row[ $position_index ] : '';
						$company = ( false !== $company_index && isset( $row[ $company_index ] ) ) ? (string) $row[ $company_index ] : '';
						$writeup = ( false !== $writeup_index && isset( $row[ $writeup_index ] ) ) ? (string) $row[ $writeup_index ] : '';

						$fullname = trim( $last_name . ', ' . $first_name, ", \t\n\r\0\x0B" );
						$title    = $title_prefix . $fullname;

						$content = '';
						if ( ! empty( $top_content_block ) ) {
							$content .= '<p style="text-align: center;">' . $top_content_block . '</p>';
						}
						$content .= '<p style="text-align: center;">';
						$content .= '<strong>' . esc_html( $fullname ) . '</strong><br>';
						$content .= esc_html( $position ) . '<br>';
						$content .= '<strong><em>' . esc_html( $company ) . '</em></strong>';
						$content .= '</p>';
						$content .= wp_kses_post( $writeup );

						$timestamp     = (int) $base_timestamp + ( (int) $spacing_interval * 60 * (int) $row_index );
						$post_date     = wp_date( 'Y-m-d H:i:s', $timestamp );
						$post_date_gmt = get_gmt_from_date( $post_date );
						$post_status   = ( 'publish' === $selected_status ) ? 'publish' : 'draft';

						$post_id = wp_insert_post(
							array(
								'post_title'    => $title,
								'post_content'  => $content,
								'post_author'   => $author_id,
								'post_status'   => $post_status,
								'post_date'     => $post_date,
								'post_date_gmt' => $post_date_gmt,
							),
							true
						);

						if ( is_wp_error( $post_id ) ) {
							$failures[] = $fullname;
							continue;
						}

						if ( $category_id > 0 ) {
							wp_set_post_categories( $post_id, array( $category_id ) );
						}

						update_post_meta( $post_id, 'roi_import_batch_id', $batch_id );
						++$total_successful;
					}

					if ( $total_successful > 0 ) {
						delete_transient( 'roi_import_preview' );
					}

					$import_results = array(
						'total_rows_processed' => $total_attempted,
						'total_created'        => $total_successful,
						'batch_id'             => $batch_id,
						'failures'             => $failures,
					);

					$config_notice_type    = 'success';
					$config_notice_message = __( 'Import completed. Review Step 4 for results.', 'roi-influencer-importer' );
				} else {
					$config_notice_type    = 'error';
					$config_notice_message = implode( ' ', $validation_errors );
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
			$author_id                          = isset( $_POST['roi_import_author'] ) ? intval( $_POST['roi_import_author'] ) : 0;
			$config_values['author_id']         = $author_id;
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

			$user           = get_userdata( $author_id );
			$allowed_roles  = array( 'administrator', 'editor', 'author' );
			$has_valid_role = ( $user && isset( $user->roles ) ) ? array_intersect( $allowed_roles, $user->roles ) : array();
			if ( $author_id <= 0 || ! $user || empty( $has_valid_role ) ) {
				$validation_errors[] = __( 'Author is required and must be an administrator, editor, or author.', 'roi-influencer-importer' );
			}

			if ( '' === $config_values['base_publish_date'] ) {
				$validation_errors[] = __( 'Base Publish Date is required.', 'roi-influencer-importer' );
			}

			if ( '' === $config_values['base_publish_time'] ) {
				$validation_errors[] = __( 'Base Publish Time is required.', 'roi-influencer-importer' );
			}

			if ( ! is_array( $preview_data ) || ! isset( $preview_data['headers'], $preview_data['rows'] ) || ! is_array( $preview_data['headers'] ) || ! is_array( $preview_data['rows'] ) ) {
				$validation_errors[] = __( 'CSV preview data is missing or expired. Please upload the CSV again.', 'roi-influencer-importer' );
			}

			$last_name_index  = false;
			$first_name_index = false;
			if ( is_array( $preview_data ) && isset( $preview_data['headers'] ) && is_array( $preview_data['headers'] ) ) {
				$last_name_index  = roi_influencer_importer_find_header_index( $preview_data['headers'], 'lastname' );
				$first_name_index = roi_influencer_importer_find_header_index( $preview_data['headers'], 'firstname' );
			}

			if ( false === $last_name_index ) {
				$validation_errors[] = __( 'CSV must include a lastname column.', 'roi-influencer-importer' );
			}

			if ( false === $first_name_index ) {
				$validation_errors[] = __( 'CSV must include a firstname column.', 'roi-influencer-importer' );
			}

			$base_timestamp = strtotime( $config_values['base_publish_date'] . ' ' . $config_values['base_publish_time'] );
			if ( false === $base_timestamp ) {
				$validation_errors[] = __( 'Base date/time is invalid.', 'roi-influencer-importer' );
			}

			if ( empty( $validation_errors ) && is_array( $preview_data ) ) {
				$sorted_rows = $preview_data['rows'];
				usort(
					$sorted_rows,
					static function( $row_a, $row_b ) use ( $last_name_index ) {
						$last_a = isset( $row_a[ $last_name_index ] ) ? (string) $row_a[ $last_name_index ] : '';
						$last_b = isset( $row_b[ $last_name_index ] ) ? (string) $row_b[ $last_name_index ] : '';
						return strcasecmp( $last_a, $last_b );
					}
				);

				$computed_items = array();
				foreach ( $sorted_rows as $row_index => $row ) {
					$last_name  = isset( $row[ $last_name_index ] ) ? trim( (string) $row[ $last_name_index ] ) : '';
					$first_name = isset( $row[ $first_name_index ] ) ? trim( (string) $row[ $first_name_index ] ) : '';
					$title      = $last_name . ', ' . $first_name . ' - ' . $config_values['title_suffix'];
					$offset     = (int) $config_values['spacing_interval'] * (int) $row_index;
					$timestamp  = strtotime( '+' . $offset . ' minutes', $base_timestamp );

					$computed_items[] = array(
						'title'            => $title,
						'publish_datetime' => wp_date( 'Y-m-d H:i:s', $timestamp ),
					);
				}

				$selected_author_name = __( 'Unknown', 'roi-influencer-importer' );
				$selected_author      = get_userdata( $config_values['author_id'] );
				if ( $selected_author instanceof WP_User ) {
					$selected_author_name = $selected_author->display_name;
				}

				$selected_category_name = __( 'None selected', 'roi-influencer-importer' );
				if ( $config_values['category_id'] > 0 ) {
					$category = get_category( $config_values['category_id'] );
					if ( $category && ! is_wp_error( $category ) ) {
						$selected_category_name = $category->name;
					}
				}

				$computed_preview = array(
					'total_posts'          => count( $computed_items ),
					'selected_author_name' => $selected_author_name,
					'selected_category'    => $selected_category_name,
					'first_titles'         => array_slice( wp_list_pluck( $computed_items, 'title' ), 0, 3 ),
					'first_datetimes'      => array_slice( wp_list_pluck( $computed_items, 'publish_datetime' ), 0, 3 ),
				);

				$config_notice_type    = 'success';
				$config_notice_message = __( 'Import data prepared successfully. Posts have not been created yet.', 'roi-influencer-importer' );
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
						<label for="roi_author_id"><strong><?php echo esc_html__( 'Author (required)', 'roi-influencer-importer' ); ?></strong></label><br />
						<?php
						wp_dropdown_users(
							array(
								'name'             => 'roi_import_author',
								'role__in'         => array( 'administrator', 'editor', 'author' ),
								'selected'         => get_current_user_id(),
								'show_option_none' => '-- Select an author --',
								'required'         => true,
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

			</div>
		<?php endif; ?>

		<?php if ( is_array( $computed_preview ) ) : ?>
			<div class="card">
				<h2><?php echo esc_html__( 'Step 3: Computed Import Preview', 'roi-influencer-importer' ); ?></h2>

				<p><strong><?php echo esc_html__( 'Total posts to be created:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( (string) $computed_preview['total_posts'] ); ?></p>
				<p><strong><?php echo esc_html__( 'Selected author name:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( $computed_preview['selected_author_name'] ); ?></p>
				<p><strong><?php echo esc_html__( 'Selected category name:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( $computed_preview['selected_category'] ); ?></p>

				<p><strong><?php echo esc_html__( 'First 3 computed titles:', 'roi-influencer-importer' ); ?></strong></p>
				<?php if ( ! empty( $computed_preview['first_titles'] ) ) : ?>
					<ul>
						<?php foreach ( $computed_preview['first_titles'] as $computed_title ) : ?>
							<li><?php echo esc_html( $computed_title ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><?php echo esc_html__( 'No computed titles available.', 'roi-influencer-importer' ); ?></p>
				<?php endif; ?>

				<p><strong><?php echo esc_html__( 'First 3 computed publish datetimes:', 'roi-influencer-importer' ); ?></strong></p>
				<?php if ( ! empty( $computed_preview['first_datetimes'] ) ) : ?>
					<ul>
						<?php foreach ( $computed_preview['first_datetimes'] as $computed_datetime ) : ?>
							<li><?php echo esc_html( $computed_datetime ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><?php echo esc_html__( 'No computed publish datetimes available.', 'roi-influencer-importer' ); ?></p>
				<?php endif; ?>

				<p><em><?php echo esc_html__( 'Posts have not been created yet.', 'roi-influencer-importer' ); ?></em></p>

				<form method="post">
					<?php wp_nonce_field( 'roi_run_import_action', 'roi_run_import_nonce' ); ?>
					<input type="hidden" name="roi_title_suffix" value="<?php echo esc_attr( $config_values['title_suffix'] ); ?>" />
					<input type="hidden" name="roi_top_content_block" value="<?php echo esc_attr( $config_values['top_content'] ); ?>" />
					<input type="hidden" name="roi_category_id" value="<?php echo esc_attr( (string) $config_values['category_id'] ); ?>" />
					<input type="hidden" name="roi_import_author" value="<?php echo esc_attr( (string) $config_values['author_id'] ); ?>" />
					<input type="hidden" name="roi_base_publish_date" value="<?php echo esc_attr( $config_values['base_publish_date'] ); ?>" />
					<input type="hidden" name="roi_base_publish_time" value="<?php echo esc_attr( $config_values['base_publish_time'] ); ?>" />
					<input type="hidden" name="roi_spacing_interval" value="<?php echo esc_attr( (string) $config_values['spacing_interval'] ); ?>" />
					<input type="hidden" name="roi_post_status" value="<?php echo esc_attr( $config_values['post_status'] ); ?>" />
					<p>
						<?php submit_button( __( 'Confirm and Run Import', 'roi-influencer-importer' ), 'primary', 'roi_run_import_submit', false ); ?>
					</p>
				</form>
			</div>
		<?php endif; ?>

		<?php if ( is_array( $import_results ) ) : ?>
			<div class="card">
				<h2><?php echo esc_html__( 'Step 4: Import Results', 'roi-influencer-importer' ); ?></h2>
				<p><strong><?php echo esc_html__( 'Total rows processed:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( (string) $import_results['total_rows_processed'] ); ?></p>
				<p><strong><?php echo esc_html__( 'Posts successfully created:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( (string) $import_results['total_created'] ); ?></p>
				<p><strong><?php echo esc_html__( 'Batch ID:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( $import_results['batch_id'] ); ?></p>
				<?php if ( ! empty( $import_results['failures'] ) ) : ?>
					<p><strong><?php echo esc_html__( 'Failed rows:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( (string) count( $import_results['failures'] ) ); ?></p>
				<?php endif; ?>
				<p><em><?php echo esc_html__( 'Images have not been assigned yet.', 'roi-influencer-importer' ); ?></em></p>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Find a CSV header index using normalized matching.
 *
 * @param array  $headers Header row values.
 * @param string $target  Target header key.
 *
 * @return int|false
 */
function roi_influencer_importer_find_header_index( $headers, $target ) {
	$target_normalized = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $target ) );

	foreach ( $headers as $header_index => $header_value ) {
		$header_normalized = preg_replace( '/[^a-z0-9]/', '', strtolower( trim( (string) $header_value ) ) );
		if ( $header_normalized === $target_normalized ) {
			return (int) $header_index;
		}
	}

	return false;
}
