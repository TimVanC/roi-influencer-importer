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
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'ROI Influencer Importer', 'roi-influencer-importer' ); ?></h1>
		<p><?php echo esc_html__( 'Internal CSV importer for ROI Influencers and Power Lists.', 'roi-influencer-importer' ); ?></p>

		<div class="card">
			<h2><?php echo esc_html__( 'Step 1: Upload CSV (Coming Soon)', 'roi-influencer-importer' ); ?></h2>
		</div>
	</div>
	<?php
}
