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
		'image_label'       => '',
		'category_id'       => 0,
		'template_id'       => 0,
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
				$image_label                    = isset( $_POST['roi_image_label'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_image_label'] ) ) : '';
				$category_id                    = isset( $_POST['roi_category_id'] ) ? absint( $_POST['roi_category_id'] ) : 0;
				$template_id                    = isset( $_POST['roi_template_id'] ) ? absint( $_POST['roi_template_id'] ) : 0;
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
					$validation_errors[] = __( 'Title Prefix is required.', 'roi-influencer-importer' );
				}

				if ( '' === $image_label ) {
					$validation_errors[] = __( 'Image Label is required.', 'roi-influencer-importer' );
				}

				$last_name_index  = roi_influencer_importer_find_header_index( $preview_data['headers'], 'lastname' );
				$first_name_index = roi_influencer_importer_find_header_index( $preview_data['headers'], 'firstname' );
				$full_name_index  = roi_influencer_importer_find_header_index( $preview_data['headers'], 'fullname' );
				$title_index      = roi_influencer_importer_find_header_index( $preview_data['headers'], 'title' );
				$company_index    = roi_influencer_importer_find_header_index( $preview_data['headers'], 'company' );
				$writeup_index    = roi_influencer_importer_find_header_index( $preview_data['headers'], 'writeup' );

				if ( false === $last_name_index || false === $full_name_index ) {
					$validation_errors[] = __( 'CSV must include lastname and fullname columns.', 'roi-influencer-importer' );
				}

				if ( false === $first_name_index ) {
					$validation_errors[] = __( 'CSV must include a firstname column.', 'roi-influencer-importer' );
				}

				if ( false === $title_index ) {
					$validation_errors[] = __( 'CSV must include a title column.', 'roi-influencer-importer' );
				}

				if ( false === $company_index ) {
					$validation_errors[] = __( 'CSV must include a company column.', 'roi-influencer-importer' );
				}

				if ( false === $writeup_index ) {
					$validation_errors[] = __( 'CSV must include a writeup column.', 'roi-influencer-importer' );
				}

				if ( empty( $spacing_interval ) ) {
					$spacing_interval = 5;
				}

				if ( ! in_array( $selected_status, array( 'draft', 'publish' ), true ) ) {
					$selected_status = 'draft';
				}

				$site_timezone = wp_timezone();
				$base_datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $base_publish_date . ' ' . $base_publish_time, $site_timezone );
				if ( false === $base_datetime ) {
					$validation_errors[] = __( 'Base date/time is invalid.', 'roi-influencer-importer' );
				}

				if ( empty( $validation_errors ) ) {
					$rows = $preview_data['rows'];
					usort(
						$rows,
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
					$missing_images   = array();
					$images_assigned  = 0;
					$total_rows       = count( $rows );
					$max_posts_reached = false;

					foreach ( $rows as $row_index => $row ) {
						if ( $total_rows > 0 && $total_attempted >= $total_rows ) {
							$max_posts_reached = true;
							break;
						}

						++$total_attempted;

						$row_data = array(
							'lastname'  => isset( $row[ $last_name_index ] ) ? (string) $row[ $last_name_index ] : '',
							'firstname' => ( false !== $first_name_index && isset( $row[ $first_name_index ] ) ) ? (string) $row[ $first_name_index ] : '',
							'fullname'  => ( false !== $full_name_index && isset( $row[ $full_name_index ] ) ) ? (string) $row[ $full_name_index ] : '',
							'title'     => ( false !== $title_index && isset( $row[ $title_index ] ) ) ? (string) $row[ $title_index ] : '',
							'company'   => ( false !== $company_index && isset( $row[ $company_index ] ) ) ? (string) $row[ $company_index ] : '',
							'writeup'   => ( false !== $writeup_index && isset( $row[ $writeup_index ] ) ) ? (string) $row[ $writeup_index ] : '',
						);

						$raw_filename = $row_data['lastname'] . ', ' . $row_data['firstname'] . ' - ' . $image_label;

						$title = rtrim( $title_prefix ) . ' ' . $row_data['fullname'];

						$content = '';
						if ( ! empty( $top_content_block ) ) {
							$content .= '<p style="text-align: center;">' . $top_content_block . '</p>';
						}
						$content .= '<p style="text-align: center;">';
						$content .= '<strong>' . esc_html( $row_data['fullname'] ) . '</strong><br>';
						$content .= esc_html( $row_data['title'] ) . '<br>';
						$content .= '<strong><em>' . esc_html( $row_data['company'] ) . '</em></strong>';
						$content .= '</p>';
						$content .= wp_kses_post( $row_data['writeup'] );

						$offset_minutes   = (int) $spacing_interval * (int) $row_index;
						$scheduled_date   = $base_datetime->modify( '+' . $offset_minutes . ' minutes' )->format( 'Y-m-d H:i:s' );
						$post_status      = ( 'publish' === $selected_status ) ? 'publish' : 'draft';

						$post_id = wp_insert_post(
							array(
								'post_title'    => $title,
								'post_content'  => $content,
								'post_author'   => $author_id,
								'post_status'   => $post_status,
								'post_date'     => $scheduled_date,
							),
							true
						);

						if ( is_wp_error( $post_id ) ) {
							$failures[] = $row_data['fullname'];
							continue;
						}

						if ( $category_id > 0 ) {
							wp_set_post_categories( $post_id, array( $category_id ) );
						}

						if ( $template_id > 0 ) {
							$settings = array(
								'td_post_template' => 'tdb_template_' . $template_id,
							);

							update_post_meta(
								$post_id,
								'td_post_theme_settings',
								$settings
							);
						}

						update_post_meta( $post_id, 'roi_import_batch_id', $batch_id );

						$attachment_id = roi_influencer_importer_find_attachment_id_by_filename( $raw_filename );
						if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
							$attachment_meta = wp_get_attachment_metadata( $attachment_id );
							if ( ! empty( $attachment_meta ) ) {
								// Layer 6 image assignment re-enabled after CDN migration fix.
								set_post_thumbnail( $post_id, $attachment_id );
								++$images_assigned;
							} else {
								$missing_images[] = $raw_filename;
							}
						} else {
							$missing_images[] = $raw_filename;
						}

						++$total_successful;
					}

					if ( $max_posts_reached ) {
						$config_notice_type    = 'error';
						$config_notice_message = __( 'Safety check triggered: attempted to create more posts than CSV rows. Import stopped.', 'roi-influencer-importer' );
					}

					if ( $total_successful > 0 ) {
						delete_transient( 'roi_import_preview' );
					}

					$import_results = array(
						'total_rows_processed' => $total_attempted,
						'total_created'        => $total_successful,
						'batch_id'             => $batch_id,
						'failures'             => $failures,
						'images_assigned'      => $images_assigned,
						'missing_images'       => $missing_images,
					);

					if ( ! $max_posts_reached ) {
						$config_notice_type    = 'success';
						$config_notice_message = __( 'Import completed. Review Step 4 for results.', 'roi-influencer-importer' );
					}
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
			$config_values['image_label']       = isset( $_POST['roi_image_label'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_image_label'] ) ) : '';
			$config_values['category_id']       = isset( $_POST['roi_category_id'] ) ? absint( $_POST['roi_category_id'] ) : 0;
			$config_values['template_id']       = isset( $_POST['roi_template_id'] ) ? absint( $_POST['roi_template_id'] ) : 0;
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
				$validation_errors[] = __( 'Title Prefix is required.', 'roi-influencer-importer' );
			}

			if ( '' === $config_values['image_label'] ) {
				$validation_errors[] = __( 'Image Label is required.', 'roi-influencer-importer' );
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
			$full_name_index  = false;
			if ( is_array( $preview_data ) && isset( $preview_data['headers'] ) && is_array( $preview_data['headers'] ) ) {
				$last_name_index = roi_influencer_importer_find_header_index( $preview_data['headers'], 'lastname' );
				$full_name_index = roi_influencer_importer_find_header_index( $preview_data['headers'], 'fullname' );
			}

			if ( false === $last_name_index ) {
				$validation_errors[] = __( 'CSV must include a lastname column.', 'roi-influencer-importer' );
			}

			if ( false === $full_name_index ) {
				$validation_errors[] = __( 'CSV must include a fullname column.', 'roi-influencer-importer' );
			}

			$site_timezone = wp_timezone();
			$base_datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $config_values['base_publish_date'] . ' ' . $config_values['base_publish_time'], $site_timezone );
			if ( false === $base_datetime ) {
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
				// Preview-only loop: computes display data and never creates posts.
				foreach ( $sorted_rows as $row_index => $row ) {
					$fullname   = ( false !== $full_name_index && isset( $row[ $full_name_index ] ) ) ? (string) $row[ $full_name_index ] : '';
					$title      = rtrim( $config_values['title_suffix'] ) . ' ' . $fullname;
					$offset     = (int) $config_values['spacing_interval'] * (int) $row_index;
					$timestamp  = $base_datetime->modify( '+' . $offset . ' minutes' )->getTimestamp();

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
						<label for="roi_title_suffix"><strong><?php echo esc_html__( 'Title Prefix (required)', 'roi-influencer-importer' ); ?></strong></label><br />
						<input type="text" id="roi_title_suffix" name="roi_title_suffix" class="regular-text" required value="<?php echo esc_attr( $config_values['title_suffix'] ); ?>" />
					</p>

					<p>
						<label for="roi_top_content_block"><strong><?php echo esc_html__( 'Top Content Block (optional)', 'roi-influencer-importer' ); ?></strong></label><br />
						<textarea id="roi_top_content_block" name="roi_top_content_block" class="large-text" rows="5"><?php echo esc_textarea( $config_values['top_content'] ); ?></textarea>
					</p>

					<p>
						<label for="roi_image_label"><strong><?php echo esc_html__( 'Image Label (required)', 'roi-influencer-importer' ); ?></strong></label><br />
						<input type="text" id="roi_image_label" name="roi_image_label" class="regular-text" required value="<?php echo esc_attr( $config_values['image_label'] ); ?>" />
						<br />
						<span class="description"><?php echo esc_html__( 'This must match the label used when exporting images from Canva.', 'roi-influencer-importer' ); ?></span>
					</p>

					<p>
						<label for="roi_category_id"><strong><?php echo esc_html__( 'Category', 'roi-influencer-importer' ); ?></strong></label><br />
						<?php
						$roi_parent_category = get_term_by( 'slug', 'roi-influencers', 'category' );
						$roi_child_terms     = array();

						if ( $roi_parent_category instanceof WP_Term ) {
							$roi_child_terms = get_terms(
								array(
									'taxonomy'   => 'category',
									'hide_empty' => false,
									'child_of'   => (int) $roi_parent_category->term_id,
									'orderby'    => 'name',
									'order'      => 'ASC',
								)
							);

							if ( is_wp_error( $roi_child_terms ) || ! is_array( $roi_child_terms ) ) {
								$roi_child_terms = array();
							}
						}

						$roi_child_terms_by_parent = array();
						foreach ( $roi_child_terms as $roi_child_term ) {
							$parent_term_id = (int) $roi_child_term->parent;
							if ( ! isset( $roi_child_terms_by_parent[ $parent_term_id ] ) ) {
								$roi_child_terms_by_parent[ $parent_term_id ] = array();
							}
							$roi_child_terms_by_parent[ $parent_term_id ][] = $roi_child_term;
						}

						$render_roi_category_branch = static function( $parent_term_id, $depth ) use ( &$render_roi_category_branch, $roi_child_terms_by_parent, $config_values ) {
							if ( empty( $roi_child_terms_by_parent[ $parent_term_id ] ) ) {
								return;
							}

							foreach ( $roi_child_terms_by_parent[ $parent_term_id ] as $roi_branch_term ) {
								$label_prefix = str_repeat( '- ', max( 0, (int) $depth ) );
								?>
								<option value="<?php echo esc_attr( (string) $roi_branch_term->term_id ); ?>" <?php selected( (int) $config_values['category_id'], (int) $roi_branch_term->term_id ); ?>><?php echo esc_html( $label_prefix . $roi_branch_term->name ); ?></option>
								<?php
								$render_roi_category_branch( (int) $roi_branch_term->term_id, $depth + 1 );
							}
						};
						?>
						<select id="roi_category_id" name="roi_category_id">
							<option value=""><?php echo esc_html__( '-- Select a category --', 'roi-influencer-importer' ); ?></option>
							<?php if ( $roi_parent_category instanceof WP_Term ) : ?>
								<option value="<?php echo esc_attr( (string) $roi_parent_category->term_id ); ?>" <?php selected( (int) $config_values['category_id'], (int) $roi_parent_category->term_id ); ?>><?php echo esc_html( $roi_parent_category->name ); ?></option>
								<?php $render_roi_category_branch( (int) $roi_parent_category->term_id, 1 ); ?>
							<?php endif; ?>
						</select>
						<?php if ( ! ( $roi_parent_category instanceof WP_Term ) ) : ?>
							<br />
							<span class="description"><?php echo esc_html__( 'ROI Influencers category branch not found. Please create a parent category with slug "roi-influencers".', 'roi-influencer-importer' ); ?></span>
						<?php endif; ?>
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
						<label for="roi_template_id"><strong><?php echo esc_html__( 'Cloud Template (optional)', 'roi-influencer-importer' ); ?></strong></label><br />
						<?php
						$templates = get_posts(
							array(
								'post_type'      => 'tdb_templates',
								'posts_per_page' => -1,
							)
						);
						?>
						<select id="roi_template_id" name="roi_template_id">
							<option value=""><?php echo esc_html__( 'None', 'roi-influencer-importer' ); ?></option>
							<?php foreach ( $templates as $template ) : ?>
								<option value="<?php echo esc_attr( (string) $template->ID ); ?>" <?php selected( (int) $config_values['template_id'], (int) $template->ID ); ?>><?php echo esc_html( $template->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
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
					<input type="hidden" name="roi_image_label" value="<?php echo esc_attr( $config_values['image_label'] ); ?>" />
					<input type="hidden" name="roi_category_id" value="<?php echo esc_attr( (string) $config_values['category_id'] ); ?>" />
					<input type="hidden" name="roi_template_id" value="<?php echo esc_attr( (string) $config_values['template_id'] ); ?>" />
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
				<p><strong><?php echo esc_html__( 'Images successfully assigned:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( (string) $import_results['images_assigned'] ); ?></p>
				<p><strong><?php echo esc_html__( 'Images missing:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( (string) count( $import_results['missing_images'] ) ); ?></p>
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

/**
 * Find an attachment ID by raw filename.
 *
 * @param string $raw_filename Raw image filename without extension.
 *
 * @return int
 */
function roi_influencer_importer_find_attachment_id_by_filename( $raw_filename ) {
	$raw_filename = trim( (string) $raw_filename );
	if ( '' === $raw_filename ) {
		return 0;
	}

	// Layer 6 image assignment re-enabled after CDN migration fix.
	$sanitized_filename = sanitize_title( $raw_filename );
	if ( '' === $sanitized_filename ) {
		return 0;
	}

	$recent_query = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => 300,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $recent_query->posts ) ) {
		foreach ( $recent_query->posts as $attachment_id ) {
			$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			$basename      = wp_basename( $attached_file );
			if ( '' !== $basename && false !== strpos( strtolower( $basename ), $sanitized_filename ) ) {
				if ( wp_attachment_is_image( $attachment_id ) ) {
					$attachment_meta = wp_get_attachment_metadata( $attachment_id );
					if ( ! empty( $attachment_meta ) ) {
						return (int) $attachment_id;
					}
				}
			}
		}
	}

	$fallback_query = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_wp_attached_file',
					'value'   => $sanitized_filename,
					'compare' => 'LIKE',
				),
			),
		)
	);

	if ( ! empty( $fallback_query->posts ) ) {
		foreach ( $fallback_query->posts as $attachment_id ) {
			if ( wp_attachment_is_image( $attachment_id ) ) {
				$attachment_meta = wp_get_attachment_metadata( $attachment_id );
				if ( ! empty( $attachment_meta ) ) {
					return (int) $attachment_id;
				}
			}
		}
	}

	return 0;
}

