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
	if ( function_exists( 'wp_enqueue_media' ) ) {
		wp_enqueue_media();
	}

	$notice_type            = '';
	$notice_message         = '';
	$preview_data           = null;
	$show_config_form       = false;
	$config_notice_type     = '';
	$config_notice_message  = '';
	$computed_preview       = null;
	$image_mapping_rows     = array();
	$import_results         = null;
	$chunk_progress         = null;
	$preview_payload        = '';
	$mapping_is_valid       = false;
	$mapped_images          = array();
	$auto_mapped_images     = array();
	$current_user_id        = get_current_user_id();
	$config_values          = array(
		'title_suffix'      => '',
		'include_rank_in_title' => 0,
		'allow_missing_optional_columns' => 1,
		'map_lastname'      => '',
		'map_firstname'     => '',
		'map_fullname'      => '',
		'map_rank'          => '',
		'map_title'         => '',
		'map_company'       => '',
		'map_category'      => '',
		'map_imagefilename' => '',
		'map_writeup'       => '',
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

	$stored_preview_data = roi_influencer_importer_get_preview_data();
	if ( is_array( $stored_preview_data ) ) {
		$preview_data = array(
			'headers'   => isset( $stored_preview_data['headers'] ) && is_array( $stored_preview_data['headers'] ) ? $stored_preview_data['headers'] : array(),
			'row_count' => isset( $stored_preview_data['row_count'] ) ? absint( $stored_preview_data['row_count'] ) : 0,
			'rows'      => isset( $stored_preview_data['rows'] ) && is_array( $stored_preview_data['rows'] ) ? $stored_preview_data['rows'] : array(),
		);
		$show_config_form = true;
		$preview_payload  = roi_influencer_importer_encode_preview_payload( $preview_data );
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
						if ( is_array( $header_row ) ) {
							$header_row = roi_influencer_importer_normalize_csv_row( $header_row );
						}
						$parsed_rows = array();

						while ( false !== ( $row = fgetcsv( $handle ) ) ) {
							$parsed_rows[] = roi_influencer_importer_normalize_csv_row( $row );
						}

						fclose( $handle );

						$preview_data = array(
							'row_count' => count( $parsed_rows ),
							'headers'   => is_array( $header_row ) ? $header_row : array(),
							'rows'      => $parsed_rows,
						);

						roi_influencer_importer_set_preview_data( $preview_data );
						$preview_payload = roi_influencer_importer_encode_preview_payload( $preview_data );
						delete_transient( 'roi_import_run_state' );

						$notice_type    = 'success';
						$notice_message = __( 'CSV uploaded successfully. Preview generated below.', 'roi-influencer-importer' );
						$show_config_form = true;
					}
				}
			}
		}
	}

	if ( isset( $_POST['roi_mapping_submit'] ) ) {
		$show_config_form = true;

		$mapping_nonce = isset( $_POST['roi_mapping_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_mapping_nonce'] ) ) : '';
		if ( empty( $mapping_nonce ) || ! wp_verify_nonce( $mapping_nonce, 'roi_mapping_action' ) ) {
			$config_notice_type    = 'error';
			$config_notice_message = __( 'Security check failed for mapping step. Please try again.', 'roi-influencer-importer' );
		} else {
			$preview_data = roi_influencer_importer_get_preview_data();
			if ( ! is_array( $preview_data ) ) {
				$preview_data = roi_influencer_importer_decode_preview_payload(
					isset( $_POST['roi_preview_payload'] ) ? sanitize_textarea_field( wp_unslash( $_POST['roi_preview_payload'] ) ) : ''
				);
				if ( is_array( $preview_data ) ) {
					roi_influencer_importer_set_preview_data( $preview_data );
				}
			}

			$config_values['map_lastname']      = isset( $_POST['roi_map_lastname'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_lastname'] ) ) : '';
			$config_values['map_firstname']     = isset( $_POST['roi_map_firstname'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_firstname'] ) ) : '';
			$config_values['map_fullname']      = isset( $_POST['roi_map_fullname'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_fullname'] ) ) : '';
			$config_values['map_rank']          = isset( $_POST['roi_map_rank'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_rank'] ) ) : '';
			$config_values['map_title']         = isset( $_POST['roi_map_title'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_title'] ) ) : '';
			$config_values['map_company']       = isset( $_POST['roi_map_company'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_company'] ) ) : '';
			$config_values['map_category']      = isset( $_POST['roi_map_category'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_category'] ) ) : '';
			$config_values['map_imagefilename'] = isset( $_POST['roi_map_imagefilename'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_imagefilename'] ) ) : '';
			$config_values['map_writeup']       = isset( $_POST['roi_map_writeup'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_writeup'] ) ) : '';
			$config_values['allow_missing_optional_columns'] = isset( $_POST['roi_allow_missing_optional_columns'] ) ? 1 : 0;

			if ( ! is_array( $preview_data ) || ! isset( $preview_data['headers'] ) || ! is_array( $preview_data['headers'] ) ) {
				$config_notice_type    = 'error';
				$config_notice_message = __( 'CSV preview data is missing or expired. Please upload the CSV again in Step 1.', 'roi-influencer-importer' );
			} else {
				$last_name_index  = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_lastname'], 'lastname' );
				$first_name_index = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_firstname'], 'firstname' );
				$full_name_index  = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_fullname'], 'fullname' );
				$title_index      = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_title'], 'title' );
				$company_index    = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_company'], 'company' );
				$writeup_index    = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_writeup'], 'writeup' );
				$rank_index       = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_rank'], 'rank' );
				$category_index   = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_category'], 'category' );
				$imagefile_index  = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_imagefilename'], 'imagefilename' );

				$mapping_errors = array();
				if ( false === $last_name_index ) {
					$mapping_errors[] = __( 'Mapped Last name column is missing.', 'roi-influencer-importer' );
				}
				if ( false === $first_name_index ) {
					$mapping_errors[] = __( 'Mapped First name column is missing.', 'roi-influencer-importer' );
				}
				if ( false === $full_name_index ) {
					$mapping_errors[] = __( 'Mapped Full name column is missing.', 'roi-influencer-importer' );
				}
				if ( false === $title_index ) {
					$mapping_errors[] = __( 'Mapped Title column is missing.', 'roi-influencer-importer' );
				}
				if ( false === $company_index ) {
					$mapping_errors[] = __( 'Mapped Company column is missing.', 'roi-influencer-importer' );
				}
				if ( false === $writeup_index ) {
					$mapping_errors[] = __( 'Mapped Write up column is missing.', 'roi-influencer-importer' );
				}
				if ( 0 === (int) $config_values['allow_missing_optional_columns'] ) {
					if ( '' !== $config_values['map_rank'] && false === $rank_index ) {
						$mapping_errors[] = __( 'Mapped Rank column is missing.', 'roi-influencer-importer' );
					}
					if ( '' !== $config_values['map_category'] && false === $category_index ) {
						$mapping_errors[] = __( 'Mapped Category column is missing.', 'roi-influencer-importer' );
					}
					if ( '' !== $config_values['map_imagefilename'] && false === $imagefile_index ) {
						$mapping_errors[] = __( 'Mapped ImageFileName column is missing.', 'roi-influencer-importer' );
					}
				}

				if ( empty( $mapping_errors ) ) {
					$mapping_is_valid     = true;
					$config_notice_type   = 'success';
					$config_notice_message = __( 'Step 2 mapping validated. Continue to Step 3 configuration.', 'roi-influencer-importer' );
					roi_influencer_importer_set_preview_data( $preview_data );
					$preview_payload = roi_influencer_importer_encode_preview_payload( $preview_data );
				} else {
					$config_notice_type    = 'error';
					$config_notice_message = implode( ' ', $mapping_errors );
				}
			}
		}
	}

	if ( isset( $_POST['roi_run_import_submit'] ) ) {
		$show_config_form = true;
		$mapping_is_valid = true;
		$mapped_images    = roi_influencer_importer_parse_mapped_images( isset( $_POST['roi_mapped_images'] ) ? wp_unslash( $_POST['roi_mapped_images'] ) : array() );
		$auto_mapped_images = roi_influencer_importer_parse_mapped_images( isset( $_POST['roi_auto_mapped_images'] ) ? wp_unslash( $_POST['roi_auto_mapped_images'] ) : array() );
		$import_lock_key      = 'roi_import_run_lock';
		$import_lock_acquired = false;

		$run_import_nonce = isset( $_POST['roi_run_import_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_run_import_nonce'] ) ) : '';
		if ( empty( $run_import_nonce ) || ! wp_verify_nonce( $run_import_nonce, 'roi_run_import_action' ) ) {
			$config_notice_type    = 'error';
			$config_notice_message = __( 'Security check failed for import run. Please try again.', 'roi-influencer-importer' );
		} elseif ( false !== get_transient( $import_lock_key ) ) {
			$config_notice_type    = 'error';
			$config_notice_message = __( 'An import is already running. Please wait a minute and try again.', 'roi-influencer-importer' );
		} else {
			set_transient( $import_lock_key, 1, 10 * MINUTE_IN_SECONDS );
			$import_lock_acquired = true;

			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 );
			}

			$preview_data = roi_influencer_importer_get_preview_data();
			if ( ! is_array( $preview_data ) ) {
				$preview_data = roi_influencer_importer_decode_preview_payload(
					isset( $_POST['roi_preview_payload'] ) ? sanitize_textarea_field( wp_unslash( $_POST['roi_preview_payload'] ) ) : ''
				);
				if ( is_array( $preview_data ) ) {
					roi_influencer_importer_set_preview_data( $preview_data );
				}
			}
			if ( ! is_array( $preview_data ) || ! isset( $preview_data['headers'], $preview_data['rows'] ) || ! is_array( $preview_data['headers'] ) || ! is_array( $preview_data['rows'] ) ) {
				$config_notice_type    = 'error';
				$config_notice_message = __( 'CSV preview data is missing or expired, so field validation/mapping cannot run. Please upload the CSV again in Step 1, then confirm mappings for required fields: Last name, First name, Full name, Title, Company, Write up. Optional fields: Rank, Category, ImageFileName.', 'roi-influencer-importer' );
			} else {
				roi_influencer_importer_set_preview_data( $preview_data );
				$preview_payload = roi_influencer_importer_encode_preview_payload( $preview_data );
				$title_prefix                   = isset( $_POST['roi_title_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_title_suffix'] ) ) : '';
				$include_rank_in_title          = isset( $_POST['roi_include_rank_in_title'] ) ? absint( $_POST['roi_include_rank_in_title'] ) : 0;
				$top_content_block              = isset( $_POST['roi_top_content_block'] ) ? sanitize_textarea_field( wp_unslash( $_POST['roi_top_content_block'] ) ) : '';
				$image_label                    = isset( $_POST['roi_image_label'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_image_label'] ) ) : '';
				$category_id                    = isset( $_POST['roi_category_id'] ) ? absint( $_POST['roi_category_id'] ) : 0;
				$template_id                    = isset( $_POST['roi_template_id'] ) ? absint( $_POST['roi_template_id'] ) : 0;
				$author_id                      = isset( $_POST['roi_import_author'] ) ? intval( $_POST['roi_import_author'] ) : 0;
				$base_publish_date              = isset( $_POST['roi_base_publish_date'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_base_publish_date'] ) ) : '';
				$base_publish_time              = isset( $_POST['roi_base_publish_time'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_base_publish_time'] ) ) : '';
				$spacing_interval               = isset( $_POST['roi_spacing_interval'] ) ? absint( $_POST['roi_spacing_interval'] ) : 5;
				$selected_status                = isset( $_POST['roi_post_status'] ) ? sanitize_key( wp_unslash( $_POST['roi_post_status'] ) ) : 'draft';
				$map_lastname                   = isset( $_POST['roi_map_lastname'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_lastname'] ) ) : '';
				$map_firstname                  = isset( $_POST['roi_map_firstname'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_firstname'] ) ) : '';
				$map_fullname                   = isset( $_POST['roi_map_fullname'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_fullname'] ) ) : '';
				$map_rank                       = isset( $_POST['roi_map_rank'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_rank'] ) ) : '';
				$map_title                      = isset( $_POST['roi_map_title'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_title'] ) ) : '';
				$map_company                    = isset( $_POST['roi_map_company'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_company'] ) ) : '';
				$map_category                   = isset( $_POST['roi_map_category'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_category'] ) ) : '';
				$map_imagefilename              = isset( $_POST['roi_map_imagefilename'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_imagefilename'] ) ) : '';
				$map_writeup                    = isset( $_POST['roi_map_writeup'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_writeup'] ) ) : '';
				$allow_missing_optional_columns = isset( $_POST['roi_allow_missing_optional_columns'] ) ? 1 : 0;
				$config_values['title_suffix'] = $title_prefix;
				$config_values['include_rank_in_title'] = $include_rank_in_title;
				$config_values['allow_missing_optional_columns'] = $allow_missing_optional_columns;
				$config_values['map_lastname'] = $map_lastname;
				$config_values['map_firstname'] = $map_firstname;
				$config_values['map_fullname'] = $map_fullname;
				$config_values['map_rank'] = $map_rank;
				$config_values['map_title'] = $map_title;
				$config_values['map_company'] = $map_company;
				$config_values['map_category'] = $map_category;
				$config_values['map_imagefilename'] = $map_imagefilename;
				$config_values['map_writeup'] = $map_writeup;
				$config_values['top_content'] = $top_content_block;
				$config_values['image_label'] = $image_label;
				$config_values['category_id'] = $category_id;
				$config_values['template_id'] = $template_id;
				$config_values['author_id'] = $author_id;
				$config_values['base_publish_date'] = $base_publish_date;
				$config_values['base_publish_time'] = $base_publish_time;
				$config_values['spacing_interval'] = $spacing_interval;
				$config_values['post_status'] = $selected_status;

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

				$last_name_index  = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $map_lastname, 'lastname' );
				$first_name_index = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $map_firstname, 'firstname' );
				$full_name_index  = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $map_fullname, 'fullname' );
				$rank_index       = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $map_rank, 'rank' );
				$title_index      = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $map_title, 'title' );
				$company_index    = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $map_company, 'company' );
				$category_index   = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $map_category, 'category' );
				$imagefile_index  = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $map_imagefilename, 'imagefilename' );
				$writeup_index    = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $map_writeup, 'writeup' );

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

				if ( 0 === (int) $allow_missing_optional_columns ) {
					if ( '' !== $map_rank && false === $rank_index ) {
						$validation_errors[] = __( 'Mapped Rank column is missing.', 'roi-influencer-importer' );
					}
					if ( '' !== $map_category && false === $category_index ) {
						$validation_errors[] = __( 'Mapped Category column is missing.', 'roi-influencer-importer' );
					}
					if ( '' !== $map_imagefilename && false === $imagefile_index ) {
						$validation_errors[] = __( 'Mapped ImageFileName column is missing.', 'roi-influencer-importer' );
					}
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

					$total_rows          = count( $rows );
					$chunk_size          = 10;
					$import_state_key    = 'roi_import_run_state';
					$config_signature    = md5(
						wp_json_encode(
							array(
								'title_prefix'          => $title_prefix,
								'include_rank_in_title' => (int) $include_rank_in_title,
								'top_content_block'     => $top_content_block,
								'image_label'           => $image_label,
								'category_id'           => (int) $category_id,
								'template_id'           => (int) $template_id,
								'author_id'             => (int) $author_id,
								'base_publish_date'     => $base_publish_date,
								'base_publish_time'     => $base_publish_time,
								'spacing_interval'      => (int) $spacing_interval,
								'selected_status'       => $selected_status,
								'total_rows'            => (int) $total_rows,
								'mapped_images'         => $mapped_images,
								'auto_mapped_images'    => $auto_mapped_images,
							)
						)
					);
					$import_state = get_transient( $import_state_key );

					if (
						! is_array( $import_state ) ||
						! isset( $import_state['config_signature'] ) ||
						$config_signature !== (string) $import_state['config_signature']
					) {
						$import_state = array(
							'config_signature' => $config_signature,
							'batch_id'         => 'roi_batch_' . time(),
							'offset'           => 0,
							'total_attempted'  => 0,
							'total_successful' => 0,
							'failures'         => array(),
							'missing_images'   => array(),
							'images_assigned'  => 0,
						);
					}

					$batch_id          = (string) $import_state['batch_id'];
					$total_attempted   = (int) $import_state['total_attempted'];
					$total_successful  = (int) $import_state['total_successful'];
					$failures          = is_array( $import_state['failures'] ) ? $import_state['failures'] : array();
					$missing_images    = is_array( $import_state['missing_images'] ) ? $import_state['missing_images'] : array();
					$images_assigned   = (int) $import_state['images_assigned'];
					$offset            = max( 0, min( (int) $import_state['offset'], $total_rows ) );
					$chunk_rows        = array_slice( $rows, $offset, $chunk_size, true );
					$next_offset       = $offset;

					$persist_import_state = static function( $state_offset, $state_attempted, $state_successful, $state_failures, $state_missing_images, $state_images_assigned ) use ( $import_state_key, $config_signature, $batch_id ) {
						$state = array(
							'config_signature' => $config_signature,
							'batch_id'         => $batch_id,
							'offset'           => $state_offset,
							'total_attempted'  => $state_attempted,
							'total_successful' => $state_successful,
							'failures'         => $state_failures,
							'missing_images'   => $state_missing_images,
							'images_assigned'  => $state_images_assigned,
						);
						set_transient( $import_state_key, $state, 30 * MINUTE_IN_SECONDS );
					};

					foreach ( $chunk_rows as $row_index => $row ) {
						++$next_offset;
						++$total_attempted;

						$row_data = array(
							'lastname'  => isset( $row[ $last_name_index ] ) ? (string) $row[ $last_name_index ] : '',
							'firstname' => ( false !== $first_name_index && isset( $row[ $first_name_index ] ) ) ? (string) $row[ $first_name_index ] : '',
							'fullname'  => ( false !== $full_name_index && isset( $row[ $full_name_index ] ) ) ? (string) $row[ $full_name_index ] : '',
							'rank'      => ( false !== $rank_index && isset( $row[ $rank_index ] ) ) ? trim( (string) $row[ $rank_index ] ) : '',
							'title'     => ( false !== $title_index && isset( $row[ $title_index ] ) ) ? (string) $row[ $title_index ] : '',
							'company'   => ( false !== $company_index && isset( $row[ $company_index ] ) ) ? (string) $row[ $company_index ] : '',
							'category'  => ( false !== $category_index && isset( $row[ $category_index ] ) ) ? (string) $row[ $category_index ] : '',
							'imagefilename' => ( false !== $imagefile_index && isset( $row[ $imagefile_index ] ) ) ? trim( (string) $row[ $imagefile_index ] ) : '',
							'writeup'   => ( false !== $writeup_index && isset( $row[ $writeup_index ] ) ) ? (string) $row[ $writeup_index ] : '',
						);

						$raw_filename = $row_data['lastname'] . ', ' . $row_data['firstname'] . ' - ' . $image_label;
						if ( '' !== $row_data['imagefilename'] ) {
							$raw_filename = $row_data['imagefilename'];
						}

						$normalized_title_prefix = trim( (string) $title_prefix );
						$normalized_category     = trim( (string) $row_data['category'] );
						if ( 'ROI Influencers: Women 2026' === $normalized_title_prefix ) {
							if ( 'Top 50' === $normalized_category ) {
								$title = 'ROI Influencers: Women 2026: Top 50 — ' . $row_data['fullname'];
							} else {
								$title = 'ROI Influencers: Women 2026 — ' . $row_data['fullname'];
							}
						} else {
							$title = rtrim( $title_prefix ) . ' ' . $row_data['fullname'];
							if ( 1 === (int) $include_rank_in_title && '' !== $row_data['rank'] ) {
								$title = rtrim( $title_prefix ) . ' No. ' . $row_data['rank'] . ' ' . $row_data['fullname'];
							}
						}

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
						$row_fingerprint  = md5( strtolower( trim( (string) $title_prefix ) ) . '|' . strtolower( trim( (string) $row_data['fullname'] ) ) . '|' . $scheduled_date );

						$existing_post = get_posts(
							array(
								'post_type'      => 'post',
								'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
								'posts_per_page' => 1,
								'fields'         => 'ids',
								'meta_key'       => 'roi_import_row_fingerprint',
								'meta_value'     => $row_fingerprint,
							)
						);

						if ( ! empty( $existing_post ) ) {
							$persist_import_state( $next_offset, $total_attempted, $total_successful, $failures, $missing_images, $images_assigned );
							continue;
						}

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
							$persist_import_state( $next_offset, $total_attempted, $total_successful, $failures, $missing_images, $images_assigned );
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
						update_post_meta( $post_id, 'roi_import_row_fingerprint', $row_fingerprint );

						$override_attachment_id = isset( $mapped_images[ (int) $row_index ] ) ? (int) $mapped_images[ (int) $row_index ] : 0;
						$auto_attachment_id     = isset( $auto_mapped_images[ (int) $row_index ] ) ? (int) $auto_mapped_images[ (int) $row_index ] : 0;
						$assigned_image         = false;

						// Priority: manual override, then auto-matched suggestion, then no image.
						if ( $override_attachment_id > 0 && wp_attachment_is_image( $override_attachment_id ) ) {
							set_post_thumbnail( $post_id, $override_attachment_id );
							$assigned_image = true;
							++$images_assigned;
						} elseif ( $auto_attachment_id > 0 && wp_attachment_is_image( $auto_attachment_id ) ) {
							set_post_thumbnail( $post_id, $auto_attachment_id );
							$assigned_image = true;
							++$images_assigned;
						}

						if ( ! $assigned_image ) {
							$attachment_id = roi_influencer_importer_find_attachment_id_by_filename( $raw_filename );
							if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
								$attachment_meta = wp_get_attachment_metadata( $attachment_id );
								if ( ! empty( $attachment_meta ) ) {
									// Layer 6 image assignment re-enabled after CDN migration fix.
									set_post_thumbnail( $post_id, $attachment_id );
									++$images_assigned;
									$assigned_image = true;
								}
							}
						}

						if ( ! $assigned_image ) {
							$missing_images[] = $raw_filename;
						}

						++$total_successful;
						$persist_import_state( $next_offset, $total_attempted, $total_successful, $failures, $missing_images, $images_assigned );
					}

					$offset = $next_offset;
					if ( $offset < $total_rows ) {
						$persist_import_state( $offset, $total_attempted, $total_successful, $failures, $missing_images, $images_assigned );

						$chunk_progress = array(
							'processed' => $offset,
							'total'     => $total_rows,
						);
						$config_notice_type    = 'success';
						$config_notice_message = sprintf(
							/* translators: 1: processed rows, 2: total rows */
							__( 'Import in progress: %1$d of %2$d rows processed. Continue import to finish.', 'roi-influencer-importer' ),
							(int) $offset,
							(int) $total_rows
						);
					} else {
						delete_transient( $import_state_key );
						if ( $total_successful > 0 ) {
							roi_influencer_importer_delete_preview_data();
						}

						$import_results = array(
							'total_rows_processed' => $total_attempted,
							'total_created'        => $total_successful,
							'batch_id'             => $batch_id,
							'failures'             => $failures,
							'images_assigned'      => $images_assigned,
							'missing_images'       => $missing_images,
						);

						$config_notice_type    = 'success';
						$config_notice_message = __( 'Import completed. Review Step 6 for results.', 'roi-influencer-importer' );
					}
				} else {
					$config_notice_type    = 'error';
					$config_notice_message = implode( ' ', $validation_errors );
				}
			}
		}

		if ( $import_lock_acquired ) {
			delete_transient( $import_lock_key );
		}
	}

	if ( isset( $_POST['roi_import_config_submit'] ) ) {
		$show_config_form = true;
		$mapping_is_valid = true;
		$mapped_images    = roi_influencer_importer_parse_mapped_images( isset( $_POST['roi_mapped_images'] ) ? wp_unslash( $_POST['roi_mapped_images'] ) : array() );
		$auto_mapped_images = roi_influencer_importer_parse_mapped_images( isset( $_POST['roi_auto_mapped_images'] ) ? wp_unslash( $_POST['roi_auto_mapped_images'] ) : array() );
		delete_transient( 'roi_import_run_state' );

		$config_nonce = isset( $_POST['roi_import_config_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_import_config_nonce'] ) ) : '';
		if ( empty( $config_nonce ) || ! wp_verify_nonce( $config_nonce, 'roi_import_config_action' ) ) {
			$config_notice_type    = 'error';
			$config_notice_message = __( 'Security check failed for import configuration. Please try again.', 'roi-influencer-importer' );
		} else {
			$preview_data = roi_influencer_importer_get_preview_data();
			if ( ! is_array( $preview_data ) ) {
				$preview_data = roi_influencer_importer_decode_preview_payload(
					isset( $_POST['roi_preview_payload'] ) ? sanitize_textarea_field( wp_unslash( $_POST['roi_preview_payload'] ) ) : ''
				);
				if ( is_array( $preview_data ) ) {
					roi_influencer_importer_set_preview_data( $preview_data );
				}
			}

			$config_values['title_suffix']      = isset( $_POST['roi_title_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_title_suffix'] ) ) : '';
			$config_values['include_rank_in_title'] = isset( $_POST['roi_include_rank_in_title'] ) ? 1 : 0;
			$config_values['allow_missing_optional_columns'] = isset( $_POST['roi_allow_missing_optional_columns'] ) ? 1 : 0;
			$config_values['map_lastname']      = isset( $_POST['roi_map_lastname'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_lastname'] ) ) : '';
			$config_values['map_firstname']     = isset( $_POST['roi_map_firstname'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_firstname'] ) ) : '';
			$config_values['map_fullname']      = isset( $_POST['roi_map_fullname'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_fullname'] ) ) : '';
			$config_values['map_rank']          = isset( $_POST['roi_map_rank'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_rank'] ) ) : '';
			$config_values['map_title']         = isset( $_POST['roi_map_title'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_title'] ) ) : '';
			$config_values['map_company']       = isset( $_POST['roi_map_company'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_company'] ) ) : '';
			$config_values['map_category']      = isset( $_POST['roi_map_category'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_category'] ) ) : '';
			$config_values['map_imagefilename'] = isset( $_POST['roi_map_imagefilename'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_imagefilename'] ) ) : '';
			$config_values['map_writeup']       = isset( $_POST['roi_map_writeup'] ) ? sanitize_text_field( wp_unslash( $_POST['roi_map_writeup'] ) ) : '';
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

			$has_preview_data = is_array( $preview_data ) && isset( $preview_data['headers'], $preview_data['rows'] ) && is_array( $preview_data['headers'] ) && is_array( $preview_data['rows'] );
			if ( ! $has_preview_data ) {
				$validation_errors[] = __( 'CSV preview data is missing or expired, so field validation/mapping cannot run. Please upload the CSV again in Step 1, then confirm mappings for required fields: Last name, First name, Full name, Title, Company, Write up. Optional fields: Rank, Category, ImageFileName.', 'roi-influencer-importer' );
			} else {
				roi_influencer_importer_set_preview_data( $preview_data );
				$preview_payload = roi_influencer_importer_encode_preview_payload( $preview_data );
			}

			$last_name_index  = false;
			$first_name_index = false;
			$full_name_index  = false;
			$rank_index       = false;
			$title_index      = false;
			$company_index    = false;
			$category_index   = false;
			$imagefile_index  = false;
			if ( is_array( $preview_data ) && isset( $preview_data['headers'] ) && is_array( $preview_data['headers'] ) ) {
				$last_name_index = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_lastname'], 'lastname' );
				$first_name_index = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_firstname'], 'firstname' );
				$full_name_index = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_fullname'], 'fullname' );
				$rank_index      = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_rank'], 'rank' );
				$title_index     = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_title'], 'title' );
				$company_index   = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_company'], 'company' );
				$category_index  = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_category'], 'category' );
				$imagefile_index = roi_influencer_importer_resolve_header_index( $preview_data['headers'], $config_values['map_imagefilename'], 'imagefilename' );
			}

			if ( $has_preview_data && false === $last_name_index ) {
				$validation_errors[] = __( 'CSV must include a lastname column.', 'roi-influencer-importer' );
			}

			if ( $has_preview_data && false === $full_name_index ) {
				$validation_errors[] = __( 'CSV must include a fullname column.', 'roi-influencer-importer' );
			}

			if ( $has_preview_data && 0 === (int) $config_values['allow_missing_optional_columns'] ) {
				if ( '' !== $config_values['map_rank'] && false === $rank_index ) {
					$validation_errors[] = __( 'Mapped Rank column is missing.', 'roi-influencer-importer' );
				}
				if ( '' !== $config_values['map_category'] && false === $category_index ) {
					$validation_errors[] = __( 'Mapped Category column is missing.', 'roi-influencer-importer' );
				}
				if ( '' !== $config_values['map_imagefilename'] && false === $imagefile_index ) {
					$validation_errors[] = __( 'Mapped ImageFileName column is missing.', 'roi-influencer-importer' );
				}
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
					$rank_value = ( false !== $rank_index && isset( $row[ $rank_index ] ) ) ? trim( (string) $row[ $rank_index ] ) : '';
					$category_value          = ( false !== $category_index && isset( $row[ $category_index ] ) ) ? trim( (string) $row[ $category_index ] ) : '';
					$normalized_title_prefix = trim( (string) $config_values['title_suffix'] );
					if ( 'ROI Influencers: Women 2026' === $normalized_title_prefix ) {
						if ( 'Top 50' === $category_value ) {
							$title = 'ROI Influencers: Women 2026: Top 50 — ' . $fullname;
						} else {
							$title = 'ROI Influencers: Women 2026 — ' . $fullname;
						}
					} else {
						$title = rtrim( $config_values['title_suffix'] ) . ' ' . $fullname;
						if ( 1 === (int) $config_values['include_rank_in_title'] && '' !== $rank_value ) {
							$title = rtrim( $config_values['title_suffix'] ) . ' No. ' . $rank_value . ' ' . $fullname;
						}
					}
					$offset     = (int) $config_values['spacing_interval'] * (int) $row_index;
					$timestamp  = $base_datetime->modify( '+' . $offset . ' minutes' )->getTimestamp();

					$computed_items[] = array(
						'title'            => $title,
						'publish_datetime' => wp_date( 'Y-m-d H:i:s', $timestamp ),
					);

					$row_lastname      = ( false !== $last_name_index && isset( $row[ $last_name_index ] ) ) ? (string) $row[ $last_name_index ] : '';
					$row_firstname     = ( false !== $first_name_index && isset( $row[ $first_name_index ] ) ) ? (string) $row[ $first_name_index ] : '';
					$row_title         = ( false !== $title_index && isset( $row[ $title_index ] ) ) ? (string) $row[ $title_index ] : '';
					$row_company       = ( false !== $company_index && isset( $row[ $company_index ] ) ) ? (string) $row[ $company_index ] : '';
					$row_imagefilename = ( false !== $imagefile_index && isset( $row[ $imagefile_index ] ) ) ? trim( (string) $row[ $imagefile_index ] ) : '';
					$raw_filename      = $row_lastname . ', ' . $row_firstname . ' - ' . $config_values['image_label'];
					if ( '' !== $row_imagefilename ) {
						$raw_filename = $row_imagefilename;
					}

					$current_attachment_id = roi_influencer_importer_find_attachment_id_by_filename( $raw_filename );
					$image_mapping_rows[]  = array(
						'row_index'             => (int) $row_index,
						'lastname'              => $row_lastname,
						'fullname'              => $fullname,
						'title'                 => $row_title,
						'company'               => $row_company,
						'raw_filename'          => $raw_filename,
						'current_attachment_id' => ( $current_attachment_id > 0 && wp_attachment_is_image( $current_attachment_id ) ) ? (int) $current_attachment_id : 0,
						'override_attachment_id' => isset( $mapped_images[ (int) $row_index ] ) ? (int) $mapped_images[ (int) $row_index ] : 0,
					);
					if ( $current_attachment_id > 0 && wp_attachment_is_image( $current_attachment_id ) ) {
						$auto_mapped_images[ (int) $row_index ] = (int) $current_attachment_id;
					}
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
		<?php $show_step3_config = $mapping_is_valid || is_array( $computed_preview ) || is_array( $chunk_progress ) || is_array( $import_results ) || isset( $_POST['roi_import_config_submit'] ) || isset( $_POST['roi_run_import_submit'] ); ?>
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
				<h2><?php echo esc_html__( 'Step 2 & 3: Map and Configure Import', 'roi-influencer-importer' ); ?></h2>

				<?php if ( ! empty( $config_notice_message ) ) : ?>
					<div class="notice notice-<?php echo esc_attr( $config_notice_type ); ?> inline">
						<p><?php echo esc_html( $config_notice_message ); ?></p>
					</div>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( 'roi_import_config_action', 'roi_import_config_nonce' ); ?>
					<?php wp_nonce_field( 'roi_mapping_action', 'roi_mapping_nonce' ); ?>
					<input type="hidden" name="roi_preview_payload" value="<?php echo esc_attr( $preview_payload ); ?>" />

					<?php if ( $show_step3_config ) : ?>
						<p>
							<label for="roi_title_suffix"><strong><?php echo esc_html__( 'Title Prefix (required)', 'roi-influencer-importer' ); ?></strong></label><br />
							<input type="text" id="roi_title_suffix" name="roi_title_suffix" class="regular-text" required value="<?php echo esc_attr( $config_values['title_suffix'] ); ?>" />
						</p>
						<p>
							<label for="roi_include_rank_in_title">
								<input type="checkbox" id="roi_include_rank_in_title" name="roi_include_rank_in_title" value="1" <?php checked( (int) $config_values['include_rank_in_title'], 1 ); ?> />
								<strong><?php echo esc_html__( 'Include Rank in Title', 'roi-influencer-importer' ); ?></strong>
							</label>
						</p>
					<?php endif; ?>

					<?php if ( is_array( $preview_data ) && ! empty( $preview_data['headers'] ) ) : ?>
						<h3><?php echo esc_html__( 'Step 2: Map CSV Columns', 'roi-influencer-importer' ); ?></h3>
						<p><strong><?php echo esc_html__( 'Column Mapping (optional)', 'roi-influencer-importer' ); ?></strong><br />
							<span class="description"><?php echo esc_html__( 'Use this if your CSV headers vary. Leave as Auto-detect when possible.', 'roi-influencer-importer' ); ?></span>
						</p>
						<p>
							<label for="roi_allow_missing_optional_columns">
								<input type="checkbox" id="roi_allow_missing_optional_columns" name="roi_allow_missing_optional_columns" value="1" <?php checked( (int) $config_values['allow_missing_optional_columns'], 1 ); ?> />
								<strong><?php echo esc_html__( 'Allow missing optional columns (Rank, Category, ImageFileName)', 'roi-influencer-importer' ); ?></strong>
							</label>
						</p>
						<p>
							<label for="roi_map_lastname"><strong><?php echo esc_html__( 'Last Name column', 'roi-influencer-importer' ); ?></strong></label><br />
							<select id="roi_map_lastname" name="roi_map_lastname">
								<option value=""><?php echo esc_html__( 'Auto-detect (lastname)', 'roi-influencer-importer' ); ?></option>
								<?php foreach ( $preview_data['headers'] as $header_index => $header_label ) : ?>
									<option value="<?php echo esc_attr( (string) $header_index ); ?>" <?php selected( (string) $config_values['map_lastname'], (string) $header_index ); ?>><?php echo esc_html( (string) $header_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="roi_map_firstname"><strong><?php echo esc_html__( 'First Name column', 'roi-influencer-importer' ); ?></strong></label><br />
							<select id="roi_map_firstname" name="roi_map_firstname">
								<option value=""><?php echo esc_html__( 'Auto-detect (firstname)', 'roi-influencer-importer' ); ?></option>
								<?php foreach ( $preview_data['headers'] as $header_index => $header_label ) : ?>
									<option value="<?php echo esc_attr( (string) $header_index ); ?>" <?php selected( (string) $config_values['map_firstname'], (string) $header_index ); ?>><?php echo esc_html( (string) $header_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="roi_map_fullname"><strong><?php echo esc_html__( 'Full Name column', 'roi-influencer-importer' ); ?></strong></label><br />
							<select id="roi_map_fullname" name="roi_map_fullname">
								<option value=""><?php echo esc_html__( 'Auto-detect (fullname)', 'roi-influencer-importer' ); ?></option>
								<?php foreach ( $preview_data['headers'] as $header_index => $header_label ) : ?>
									<option value="<?php echo esc_attr( (string) $header_index ); ?>" <?php selected( (string) $config_values['map_fullname'], (string) $header_index ); ?>><?php echo esc_html( (string) $header_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="roi_map_rank"><strong><?php echo esc_html__( 'Rank column', 'roi-influencer-importer' ); ?></strong></label><br />
							<select id="roi_map_rank" name="roi_map_rank">
								<option value=""><?php echo esc_html__( 'Auto-detect (rank)', 'roi-influencer-importer' ); ?></option>
								<?php foreach ( $preview_data['headers'] as $header_index => $header_label ) : ?>
									<option value="<?php echo esc_attr( (string) $header_index ); ?>" <?php selected( (string) $config_values['map_rank'], (string) $header_index ); ?>><?php echo esc_html( (string) $header_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="roi_map_title"><strong><?php echo esc_html__( 'Title column', 'roi-influencer-importer' ); ?></strong></label><br />
							<select id="roi_map_title" name="roi_map_title">
								<option value=""><?php echo esc_html__( 'Auto-detect (title)', 'roi-influencer-importer' ); ?></option>
								<?php foreach ( $preview_data['headers'] as $header_index => $header_label ) : ?>
									<option value="<?php echo esc_attr( (string) $header_index ); ?>" <?php selected( (string) $config_values['map_title'], (string) $header_index ); ?>><?php echo esc_html( (string) $header_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="roi_map_company"><strong><?php echo esc_html__( 'Company column', 'roi-influencer-importer' ); ?></strong></label><br />
							<select id="roi_map_company" name="roi_map_company">
								<option value=""><?php echo esc_html__( 'Auto-detect (company)', 'roi-influencer-importer' ); ?></option>
								<?php foreach ( $preview_data['headers'] as $header_index => $header_label ) : ?>
									<option value="<?php echo esc_attr( (string) $header_index ); ?>" <?php selected( (string) $config_values['map_company'], (string) $header_index ); ?>><?php echo esc_html( (string) $header_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="roi_map_category"><strong><?php echo esc_html__( 'Category column', 'roi-influencer-importer' ); ?></strong></label><br />
							<select id="roi_map_category" name="roi_map_category">
								<option value=""><?php echo esc_html__( 'Auto-detect (category)', 'roi-influencer-importer' ); ?></option>
								<?php foreach ( $preview_data['headers'] as $header_index => $header_label ) : ?>
									<option value="<?php echo esc_attr( (string) $header_index ); ?>" <?php selected( (string) $config_values['map_category'], (string) $header_index ); ?>><?php echo esc_html( (string) $header_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="roi_map_imagefilename"><strong><?php echo esc_html__( 'ImageFileName column', 'roi-influencer-importer' ); ?></strong></label><br />
							<select id="roi_map_imagefilename" name="roi_map_imagefilename">
								<option value=""><?php echo esc_html__( 'Auto-detect (imagefilename)', 'roi-influencer-importer' ); ?></option>
								<?php foreach ( $preview_data['headers'] as $header_index => $header_label ) : ?>
									<option value="<?php echo esc_attr( (string) $header_index ); ?>" <?php selected( (string) $config_values['map_imagefilename'], (string) $header_index ); ?>><?php echo esc_html( (string) $header_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="roi_map_writeup"><strong><?php echo esc_html__( 'Writeup column', 'roi-influencer-importer' ); ?></strong></label><br />
							<select id="roi_map_writeup" name="roi_map_writeup">
								<option value=""><?php echo esc_html__( 'Auto-detect (writeup)', 'roi-influencer-importer' ); ?></option>
								<?php foreach ( $preview_data['headers'] as $header_index => $header_label ) : ?>
									<option value="<?php echo esc_attr( (string) $header_index ); ?>" <?php selected( (string) $config_values['map_writeup'], (string) $header_index ); ?>><?php echo esc_html( (string) $header_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endif; ?>

					<?php if ( ! $show_step3_config ) : ?>
						<p>
							<?php submit_button( __( 'Validate Mapping and Continue', 'roi-influencer-importer' ), 'secondary', 'roi_mapping_submit', false ); ?>
						</p>
					<?php endif; ?>

					<?php if ( $show_step3_config ) : ?>
					<h3><?php echo esc_html__( 'Step 3: Configure Import Settings', 'roi-influencer-importer' ); ?></h3>

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
						<input type="text" id="roi_category_search" class="regular-text" placeholder="<?php echo esc_attr__( 'Search categories...', 'roi-influencer-importer' ); ?>" />
						<br />
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
						<select id="roi_category_id" name="roi_category_id" data-search-input="roi_category_search">
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
						<input type="text" id="roi_template_search" class="regular-text" placeholder="<?php echo esc_attr__( 'Search cloud templates...', 'roi-influencer-importer' ); ?>" />
						<br />
						<?php
						$templates = get_posts(
							array(
								'post_type'      => 'tdb_templates',
								'posts_per_page' => -1,
							)
						);
						?>
						<select id="roi_template_id" name="roi_template_id" data-search-input="roi_template_search">
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
					<?php endif; ?>
				</form>

			</div>
		<?php endif; ?>

		<?php if ( is_array( $computed_preview ) ) : ?>
			<div class="card">
				<h2><?php echo esc_html__( 'Step 4: Image Mapping', 'roi-influencer-importer' ); ?></h2>

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

				<p><em><?php echo esc_html__( 'Posts have not been created yet. Optionally override images before running the import.', 'roi-influencer-importer' ); ?></em></p>

				<form method="post">
					<?php wp_nonce_field( 'roi_run_import_action', 'roi_run_import_nonce' ); ?>
					<input type="hidden" name="roi_preview_payload" value="<?php echo esc_attr( $preview_payload ); ?>" />
					<input type="hidden" name="roi_title_suffix" value="<?php echo esc_attr( $config_values['title_suffix'] ); ?>" />
					<input type="hidden" name="roi_include_rank_in_title" value="<?php echo esc_attr( (string) $config_values['include_rank_in_title'] ); ?>" />
					<?php if ( (int) $config_values['allow_missing_optional_columns'] === 1 ) : ?>
						<input type="hidden" name="roi_allow_missing_optional_columns" value="1" />
					<?php endif; ?>
					<input type="hidden" name="roi_map_lastname" value="<?php echo esc_attr( (string) $config_values['map_lastname'] ); ?>" />
					<input type="hidden" name="roi_map_firstname" value="<?php echo esc_attr( (string) $config_values['map_firstname'] ); ?>" />
					<input type="hidden" name="roi_map_fullname" value="<?php echo esc_attr( (string) $config_values['map_fullname'] ); ?>" />
					<input type="hidden" name="roi_map_rank" value="<?php echo esc_attr( (string) $config_values['map_rank'] ); ?>" />
					<input type="hidden" name="roi_map_title" value="<?php echo esc_attr( (string) $config_values['map_title'] ); ?>" />
					<input type="hidden" name="roi_map_company" value="<?php echo esc_attr( (string) $config_values['map_company'] ); ?>" />
					<input type="hidden" name="roi_map_category" value="<?php echo esc_attr( (string) $config_values['map_category'] ); ?>" />
					<input type="hidden" name="roi_map_imagefilename" value="<?php echo esc_attr( (string) $config_values['map_imagefilename'] ); ?>" />
					<input type="hidden" name="roi_map_writeup" value="<?php echo esc_attr( (string) $config_values['map_writeup'] ); ?>" />
					<input type="hidden" name="roi_top_content_block" value="<?php echo esc_attr( $config_values['top_content'] ); ?>" />
					<input type="hidden" name="roi_image_label" value="<?php echo esc_attr( $config_values['image_label'] ); ?>" />
					<input type="hidden" name="roi_category_id" value="<?php echo esc_attr( (string) $config_values['category_id'] ); ?>" />
					<input type="hidden" name="roi_template_id" value="<?php echo esc_attr( (string) $config_values['template_id'] ); ?>" />
					<input type="hidden" name="roi_import_author" value="<?php echo esc_attr( (string) $config_values['author_id'] ); ?>" />
					<input type="hidden" name="roi_base_publish_date" value="<?php echo esc_attr( $config_values['base_publish_date'] ); ?>" />
					<input type="hidden" name="roi_base_publish_time" value="<?php echo esc_attr( $config_values['base_publish_time'] ); ?>" />
					<input type="hidden" name="roi_spacing_interval" value="<?php echo esc_attr( (string) $config_values['spacing_interval'] ); ?>" />
					<input type="hidden" name="roi_post_status" value="<?php echo esc_attr( $config_values['post_status'] ); ?>" />

					<h3><?php echo esc_html__( 'Map Images (Optional)', 'roi-influencer-importer' ); ?></h3>
					<?php if ( ! empty( $image_mapping_rows ) ) : ?>
						<p>
							<button type="button" class="button roi-bulk-select-image-button"><?php echo esc_html__( 'Select Image for Selected Rows', 'roi-influencer-importer' ); ?></button>
						</p>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Select', 'roi-influencer-importer' ); ?></th>
									<th><?php echo esc_html__( 'Full Name', 'roi-influencer-importer' ); ?></th>
									<th><?php echo esc_html__( 'Title / Company', 'roi-influencer-importer' ); ?></th>
									<th><?php echo esc_html__( 'Current Image', 'roi-influencer-importer' ); ?></th>
									<th><?php echo esc_html__( 'Override Image', 'roi-influencer-importer' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $image_mapping_rows as $image_map_row ) : ?>
									<?php
									$row_index              = (int) $image_map_row['row_index'];
									$current_attachment_id  = (int) $image_map_row['current_attachment_id'];
									$override_attachment_id = (int) $image_map_row['override_attachment_id'];
									$search_query           = trim( (string) $image_map_row['lastname'] );
									if ( '' === $search_query ) {
										$search_query = trim( (string) $image_map_row['fullname'] );
									}
									?>
									<tr>
										<td>
											<input type="checkbox" class="roi-bulk-row-select" data-row-index="<?php echo esc_attr( (string) $row_index ); ?>" />
										</td>
										<td><?php echo esc_html( (string) $image_map_row['fullname'] ); ?></td>
										<td>
											<?php
											$title_company = trim( (string) $image_map_row['title'] );
											$company_text  = trim( (string) $image_map_row['company'] );
											if ( '' !== $company_text ) {
												$title_company = ( '' !== $title_company ? $title_company . ' / ' : '' ) . $company_text;
											}
											echo esc_html( '' !== $title_company ? $title_company : __( 'N/A', 'roi-influencer-importer' ) );
											?>
										</td>
										<td>
											<?php if ( $current_attachment_id > 0 ) : ?>
												<?php echo wp_kses_post( wp_get_attachment_image( $current_attachment_id, array( 72, 72 ) ) ); ?>
											<?php else : ?>
												<?php echo esc_html__( 'No image', 'roi-influencer-importer' ); ?>
											<?php endif; ?>
										</td>
										<td>
											<div class="roi-image-override-preview" data-row-index="<?php echo esc_attr( (string) $row_index ); ?>">
												<?php if ( $override_attachment_id > 0 ) : ?>
													<?php echo wp_kses_post( wp_get_attachment_image( $override_attachment_id, array( 72, 72 ) ) ); ?>
												<?php else : ?>
													<?php echo esc_html__( 'No override selected', 'roi-influencer-importer' ); ?>
												<?php endif; ?>
											</div>
											<input type="hidden" class="roi-mapped-image-input" name="roi_mapped_images[<?php echo esc_attr( (string) $row_index ); ?>]" value="<?php echo esc_attr( (string) $override_attachment_id ); ?>" />
											<p>
												<button type="button" class="button roi-select-image-button" data-row-index="<?php echo esc_attr( (string) $row_index ); ?>" data-search-query="<?php echo esc_attr( $search_query ); ?>"><?php echo esc_html__( 'Select Image', 'roi-influencer-importer' ); ?></button>
											</p>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php foreach ( $auto_mapped_images as $mapped_row_index => $mapped_attachment_id ) : ?>
							<input type="hidden" name="roi_auto_mapped_images[<?php echo esc_attr( (string) $mapped_row_index ); ?>]" value="<?php echo esc_attr( (string) $mapped_attachment_id ); ?>" />
						<?php endforeach; ?>
					<?php else : ?>
						<p><?php echo esc_html__( 'No rows available for image mapping.', 'roi-influencer-importer' ); ?></p>
						<?php foreach ( $mapped_images as $mapped_row_index => $mapped_attachment_id ) : ?>
							<input type="hidden" name="roi_mapped_images[<?php echo esc_attr( (string) $mapped_row_index ); ?>]" value="<?php echo esc_attr( (string) $mapped_attachment_id ); ?>" />
						<?php endforeach; ?>
						<?php foreach ( $auto_mapped_images as $mapped_row_index => $mapped_attachment_id ) : ?>
							<input type="hidden" name="roi_auto_mapped_images[<?php echo esc_attr( (string) $mapped_row_index ); ?>]" value="<?php echo esc_attr( (string) $mapped_attachment_id ); ?>" />
						<?php endforeach; ?>
					<?php endif; ?>
					<p>
						<?php submit_button( __( 'Run Import', 'roi-influencer-importer' ), 'primary', 'roi_run_import_submit', false ); ?>
					</p>
				</form>
			</div>
		<?php endif; ?>

		<?php if ( is_array( $chunk_progress ) ) : ?>
			<div class="card">
				<h2><?php echo esc_html__( 'Step 5: Continue Import', 'roi-influencer-importer' ); ?></h2>
				<p><strong><?php echo esc_html__( 'Rows processed:', 'roi-influencer-importer' ); ?></strong> <?php echo esc_html( (string) $chunk_progress['processed'] ); ?> / <?php echo esc_html( (string) $chunk_progress['total'] ); ?></p>
				<p><?php echo esc_html__( 'Auto-continuing in a moment. You can also continue manually.', 'roi-influencer-importer' ); ?></p>
				<form method="post" id="roi-continue-import-form">
					<?php wp_nonce_field( 'roi_run_import_action', 'roi_run_import_nonce' ); ?>
					<input type="hidden" name="roi_preview_payload" value="<?php echo esc_attr( $preview_payload ); ?>" />
					<input type="hidden" name="roi_run_import_submit" value="1" />
					<input type="hidden" name="roi_title_suffix" value="<?php echo esc_attr( $config_values['title_suffix'] ); ?>" />
					<input type="hidden" name="roi_include_rank_in_title" value="<?php echo esc_attr( (string) $config_values['include_rank_in_title'] ); ?>" />
					<?php if ( (int) $config_values['allow_missing_optional_columns'] === 1 ) : ?>
						<input type="hidden" name="roi_allow_missing_optional_columns" value="1" />
					<?php endif; ?>
					<input type="hidden" name="roi_map_lastname" value="<?php echo esc_attr( (string) $config_values['map_lastname'] ); ?>" />
					<input type="hidden" name="roi_map_firstname" value="<?php echo esc_attr( (string) $config_values['map_firstname'] ); ?>" />
					<input type="hidden" name="roi_map_fullname" value="<?php echo esc_attr( (string) $config_values['map_fullname'] ); ?>" />
					<input type="hidden" name="roi_map_rank" value="<?php echo esc_attr( (string) $config_values['map_rank'] ); ?>" />
					<input type="hidden" name="roi_map_title" value="<?php echo esc_attr( (string) $config_values['map_title'] ); ?>" />
					<input type="hidden" name="roi_map_company" value="<?php echo esc_attr( (string) $config_values['map_company'] ); ?>" />
					<input type="hidden" name="roi_map_category" value="<?php echo esc_attr( (string) $config_values['map_category'] ); ?>" />
					<input type="hidden" name="roi_map_imagefilename" value="<?php echo esc_attr( (string) $config_values['map_imagefilename'] ); ?>" />
					<input type="hidden" name="roi_map_writeup" value="<?php echo esc_attr( (string) $config_values['map_writeup'] ); ?>" />
					<input type="hidden" name="roi_top_content_block" value="<?php echo esc_attr( $config_values['top_content'] ); ?>" />
					<input type="hidden" name="roi_image_label" value="<?php echo esc_attr( $config_values['image_label'] ); ?>" />
					<input type="hidden" name="roi_category_id" value="<?php echo esc_attr( (string) $config_values['category_id'] ); ?>" />
					<input type="hidden" name="roi_template_id" value="<?php echo esc_attr( (string) $config_values['template_id'] ); ?>" />
					<input type="hidden" name="roi_import_author" value="<?php echo esc_attr( (string) $config_values['author_id'] ); ?>" />
					<input type="hidden" name="roi_base_publish_date" value="<?php echo esc_attr( $config_values['base_publish_date'] ); ?>" />
					<input type="hidden" name="roi_base_publish_time" value="<?php echo esc_attr( $config_values['base_publish_time'] ); ?>" />
					<input type="hidden" name="roi_spacing_interval" value="<?php echo esc_attr( (string) $config_values['spacing_interval'] ); ?>" />
					<input type="hidden" name="roi_post_status" value="<?php echo esc_attr( $config_values['post_status'] ); ?>" />
					<?php foreach ( $mapped_images as $mapped_row_index => $mapped_attachment_id ) : ?>
						<input type="hidden" name="roi_mapped_images[<?php echo esc_attr( (string) $mapped_row_index ); ?>]" value="<?php echo esc_attr( (string) $mapped_attachment_id ); ?>" />
					<?php endforeach; ?>
					<?php foreach ( $auto_mapped_images as $mapped_row_index => $mapped_attachment_id ) : ?>
						<input type="hidden" name="roi_auto_mapped_images[<?php echo esc_attr( (string) $mapped_row_index ); ?>]" value="<?php echo esc_attr( (string) $mapped_attachment_id ); ?>" />
					<?php endforeach; ?>
					<p>
						<?php submit_button( __( 'Continue Import', 'roi-influencer-importer' ), 'primary', 'roi_run_import_submit', false ); ?>
					</p>
				</form>
				<script>
					document.addEventListener('DOMContentLoaded', function () {
						var continueForm = document.getElementById('roi-continue-import-form');
						if (!continueForm) {
							return;
						}

						window.setTimeout(function () {
							continueForm.submit();
						}, 1200);
					});
				</script>
			</div>
		<?php endif; ?>

		<?php if ( is_array( $import_results ) ) : ?>
			<div class="card">
				<h2><?php echo esc_html__( 'Step 6: Import Results', 'roi-influencer-importer' ); ?></h2>
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
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var searchableSelects = document.querySelectorAll('select[data-search-input]');

				searchableSelects.forEach(function (selectEl) {
					var inputId = selectEl.getAttribute('data-search-input');
					var searchInput = inputId ? document.getElementById(inputId) : null;
					if (!searchInput) {
						return;
					}

					searchInput.addEventListener('input', function () {
						var query = searchInput.value.toLowerCase().trim();
						var options = Array.prototype.slice.call(selectEl.querySelectorAll('option'));

						if (selectEl.id === 'roi_category_id') {
							if (query === '') {
								options.forEach(function (optionEl) {
									optionEl.hidden = false;
								});
								return;
							}

							var categoryNodes = options.slice(1).map(function (optionEl, idx) {
								var rawLabel = optionEl.textContent;
								var depthPrefix = rawLabel.match(/^(-\s)*/);
								var prefix = depthPrefix ? depthPrefix[0] : '';
								var depth = prefix.length > 0 ? (prefix.match(/-\s/g) || []).length : 0;
								var normalizedLabel = rawLabel.replace(/^(-\s)*/, '').toLowerCase().trim();

								return {
									index: idx,
									depth: depth,
									label: normalizedLabel,
									option: optionEl
								};
							});

							var visibleIndexes = new Set();

							function includeDescendants(nodeIndex) {
								var parentDepth = categoryNodes[nodeIndex].depth;
								for (var i = nodeIndex + 1; i < categoryNodes.length; i++) {
									if (categoryNodes[i].depth <= parentDepth) {
										break;
									}
									visibleIndexes.add(i);
								}
							}

							function includeAncestors(nodeIndex) {
								var requiredDepth = categoryNodes[nodeIndex].depth - 1;
								for (var i = nodeIndex - 1; i >= 0 && requiredDepth >= 0; i--) {
									if (categoryNodes[i].depth === requiredDepth) {
										visibleIndexes.add(i);
										requiredDepth--;
									}
								}
							}

							categoryNodes.forEach(function (node) {
								if (node.label.indexOf(query) !== -1) {
									visibleIndexes.add(node.index);
									includeAncestors(node.index);
									includeDescendants(node.index);
								}
							});

							options[0].hidden = false;
							categoryNodes.forEach(function (node) {
								node.option.hidden = !visibleIndexes.has(node.index);
							});

							return;
						}

						options.forEach(function (optionEl, optionIndex) {
							if (optionIndex === 0) {
								optionEl.hidden = false;
								return;
							}

							var optionText = optionEl.textContent.toLowerCase();
							optionEl.hidden = query !== '' && optionText.indexOf(query) === -1;
						});
					});
				});

				var mediaFrames = {};
				function getPreferredImageUrl(attachment) {
					var imageUrl = attachment.url || '';
					if (attachment.sizes) {
						if (attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
							imageUrl = attachment.sizes.thumbnail.url;
						} else if (attachment.sizes.medium && attachment.sizes.medium.url) {
							imageUrl = attachment.sizes.medium.url;
						}
					}
					return imageUrl;
				}

				function applyImageToRow(rowIndex, attachment) {
					if (!rowIndex) {
						return;
					}

					var hiddenInput = document.querySelector('.roi-mapped-image-input[name="roi_mapped_images[' + rowIndex + ']"]');
					var previewEl = document.querySelector('.roi-image-override-preview[data-row-index="' + rowIndex + '"]');

					if (hiddenInput) {
						hiddenInput.value = attachment.id || '';
					}

					if (previewEl) {
						var imageUrl = getPreferredImageUrl(attachment);
						if (imageUrl) {
							previewEl.innerHTML = '<img src="' + imageUrl + '" alt="" style="max-width:72px;height:auto;" />';
						}
					}
				}

				var selectImageButtons = document.querySelectorAll('.roi-select-image-button');
				selectImageButtons.forEach(function (buttonEl) {
					buttonEl.addEventListener('click', function () {
						if (typeof wp === 'undefined' || !wp.media) {
							return;
						}

						var rowIndex = buttonEl.getAttribute('data-row-index');
						var rowSearchQuery = (buttonEl.getAttribute('data-search-query') || '').trim();
						if (!rowIndex) {
							return;
						}

						if (!mediaFrames[rowIndex]) {
							mediaFrames[rowIndex] = wp.media({
								title: 'Select Image',
								library: {
									type: 'image'
								},
								button: {
									text: 'Use this image'
								},
								multiple: false
							});

							mediaFrames[rowIndex].on('select', function () {
								var selection = mediaFrames[rowIndex].state().get('selection').first();
								if (!selection) {
									return;
								}

								var attachment = selection.toJSON();
								applyImageToRow(rowIndex, attachment);
							});
						}

						var rowFrame = mediaFrames[rowIndex];
						if (rowFrame && rowFrame.state && rowFrame.state()) {
							var state = rowFrame.state();
							if (state && state.get) {
								var library = state.get('library');
								if (library && library.props) {
									library.props.set('search', rowSearchQuery);
								}
							}
						}

						mediaFrames[rowIndex].open();
					});
				});

				var bulkButton = document.querySelector('.roi-bulk-select-image-button');
				if (bulkButton) {
					bulkButton.addEventListener('click', function () {
						if (typeof wp === 'undefined' || !wp.media) {
							return;
						}

						var selectedCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.roi-bulk-row-select:checked'));
						if (selectedCheckboxes.length === 0) {
							return;
						}

						if (!mediaFrames.bulk) {
							mediaFrames.bulk = wp.media({
								title: 'Select Image',
								library: {
									type: 'image'
								},
								button: {
									text: 'Use this image'
								},
								multiple: false
							});

							mediaFrames.bulk.on('select', function () {
								var selection = mediaFrames.bulk.state().get('selection').first();
								if (!selection) {
									return;
								}

								var attachment = selection.toJSON();
								selectedCheckboxes.forEach(function (checkboxEl) {
									var selectedRowIndex = checkboxEl.getAttribute('data-row-index');
									applyImageToRow(selectedRowIndex, attachment);
									checkboxEl.checked = false;
								});
							});
						} else {
							mediaFrames.bulk.off('select');
							mediaFrames.bulk.on('select', function () {
								var selection = mediaFrames.bulk.state().get('selection').first();
								if (!selection) {
									return;
								}

								var attachment = selection.toJSON();
								selectedCheckboxes.forEach(function (checkboxEl) {
									var selectedRowIndex = checkboxEl.getAttribute('data-row-index');
									applyImageToRow(selectedRowIndex, attachment);
									checkboxEl.checked = false;
								});
							});
						}

						mediaFrames.bulk.open();
					});
				}
			});
		</script>
	</div>
	<?php
}

/**
 * Normalize one CSV cell value to UTF-8 and trim whitespace.
 *
 * @param mixed $value Raw CSV cell value.
 *
 * @return string
 */
function roi_influencer_importer_normalize_csv_text_value( $value ) {
	$value = (string) $value;
	if ( function_exists( 'mb_convert_encoding' ) ) {
		$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252' );
	}

	return trim( $value );
}

/**
 * Normalize all values in a CSV row.
 *
 * @param array $row Raw CSV row.
 *
 * @return array
 */
function roi_influencer_importer_normalize_csv_row( $row ) {
	if ( ! is_array( $row ) ) {
		return array();
	}

	foreach ( $row as $index => $value ) {
		$row[ $index ] = roi_influencer_importer_normalize_csv_text_value( $value );
	}

	return $row;
}

/**
 * Parse and sanitize mapped image overrides from request data.
 *
 * @param mixed $raw_mappings Raw mapping values.
 *
 * @return array
 */
function roi_influencer_importer_parse_mapped_images( $raw_mappings ) {
	if ( ! is_array( $raw_mappings ) ) {
		return array();
	}

	$mapped_images = array();
	foreach ( $raw_mappings as $row_index => $attachment_id ) {
		$row_index_int      = absint( $row_index );
		$attachment_id_int  = absint( $attachment_id );
		if ( $attachment_id_int <= 0 ) {
			continue;
		}

		$mapped_images[ $row_index_int ] = $attachment_id_int;
	}

	return $mapped_images;
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
 * Resolve CSV header index using manual mapping first, then auto-detect.
 *
 * @param array  $headers      Header row values.
 * @param string $mapped_value Mapped header index from form.
 * @param string $target       Target header key for auto-detect fallback.
 *
 * @return int|false
 */
function roi_influencer_importer_resolve_header_index( $headers, $mapped_value, $target ) {
	$mapped_value = trim( (string) $mapped_value );
	if ( '' !== $mapped_value && ctype_digit( $mapped_value ) ) {
		$mapped_index = (int) $mapped_value;
		if ( isset( $headers[ $mapped_index ] ) ) {
			return $mapped_index;
		}
	}

	return roi_influencer_importer_find_header_index( $headers, $target );
}

/**
 * Encode preview payload for multi-step form fallback.
 *
 * @param array $preview_data Preview payload.
 *
 * @return string
 */
function roi_influencer_importer_encode_preview_payload( $preview_data ) {
	if ( ! is_array( $preview_data ) ) {
		return '';
	}

	$json = wp_json_encode( $preview_data );
	if ( ! is_string( $json ) || '' === $json ) {
		return '';
	}

	return base64_encode( $json );
}

/**
 * Decode preview payload from hidden form input.
 *
 * @param string $encoded_payload Encoded payload.
 *
 * @return array|null
 */
function roi_influencer_importer_decode_preview_payload( $encoded_payload ) {
	$encoded_payload = trim( (string) $encoded_payload );
	if ( '' === $encoded_payload ) {
		return null;
	}

	$decoded_json = base64_decode( $encoded_payload, true );
	if ( false === $decoded_json || '' === $decoded_json ) {
		return null;
	}

	$decoded_data = json_decode( $decoded_json, true );
	if (
		! is_array( $decoded_data ) ||
		! isset( $decoded_data['headers'], $decoded_data['rows'] ) ||
		! is_array( $decoded_data['headers'] ) ||
		! is_array( $decoded_data['rows'] )
	) {
		return null;
	}

	return array(
		'headers'   => $decoded_data['headers'],
		'rows'      => $decoded_data['rows'],
		'row_count' => isset( $decoded_data['row_count'] ) ? absint( $decoded_data['row_count'] ) : count( $decoded_data['rows'] ),
	);
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

/**
 * Store preview data with transient and user-meta fallback.
 *
 * @param array $preview_data Preview payload.
 *
 * @return void
 */
function roi_influencer_importer_set_preview_data( $preview_data ) {
	$ttl_seconds  = DAY_IN_SECONDS;
	$current_user = get_current_user_id();
	$option_key   = roi_influencer_importer_preview_option_key( $current_user );

	set_transient( 'roi_import_preview', $preview_data, $ttl_seconds );
	if ( $current_user > 0 ) {
		update_user_meta( $current_user, 'roi_import_preview_data', $preview_data );
		update_option( $option_key, $preview_data, false );
	}
}

/**
 * Read preview data with user-meta fallback when transient is missing.
 *
 * @return array|null
 */
function roi_influencer_importer_get_preview_data() {
	$preview_data = get_transient( 'roi_import_preview' );
	if ( is_array( $preview_data ) ) {
		return $preview_data;
	}

	$current_user = get_current_user_id();
	if ( $current_user <= 0 ) {
		return null;
	}
	$option_key = roi_influencer_importer_preview_option_key( $current_user );

	$fallback_data = get_user_meta( $current_user, 'roi_import_preview_data', true );
	if ( is_array( $fallback_data ) ) {
		set_transient( 'roi_import_preview', $fallback_data, DAY_IN_SECONDS );
		return $fallback_data;
	}

	$option_fallback_data = get_option( $option_key, null );
	if ( is_array( $option_fallback_data ) ) {
		update_user_meta( $current_user, 'roi_import_preview_data', $option_fallback_data );
		set_transient( 'roi_import_preview', $option_fallback_data, DAY_IN_SECONDS );
		return $option_fallback_data;
	}

	return null;
}

/**
 * Clear preview data from transient and user meta.
 *
 * @return void
 */
function roi_influencer_importer_delete_preview_data() {
	$current_user = get_current_user_id();
	$option_key   = roi_influencer_importer_preview_option_key( $current_user );

	delete_transient( 'roi_import_preview' );
	if ( $current_user > 0 ) {
		delete_user_meta( $current_user, 'roi_import_preview_data' );
		delete_option( $option_key );
	}
}

/**
 * Build persistent preview option key for the current user.
 *
 * @param int $user_id User ID.
 *
 * @return string
 */
function roi_influencer_importer_preview_option_key( $user_id ) {
	return 'roi_import_preview_data_user_' . absint( $user_id );
}

