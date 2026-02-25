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
	$notice_type    = '';
	$notice_message = '';
	$preview_data   = null;

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
					}
				}
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
	</div>
	<?php
}
