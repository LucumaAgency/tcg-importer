<?php
/**
 * Plugin Name: TCG Importer
 * Description: Importador de cartas Yu-Gi-Oh! desde la API de YGOProDeck al CPT ygo_card.
 * Version: 1.0.0
 * Author: TCG Dev
 * Text Domain: tcg-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TCG_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCG_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once TCG_IMPORTER_PATH . 'includes/class-ygo-importer.php';

/**
 * Register admin menu page.
 */
add_action( 'admin_menu', function () {
	add_menu_page(
		'TCG Importer',
		'TCG Importer',
		'manage_options',
		'tcg-importer',
		'tcg_importer_render_page',
		'dashicons-download',
		80
	);
} );

/**
 * Enqueue assets only on the importer page.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'toplevel_page_tcg-importer' !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'tcg-importer-css',
		TCG_IMPORTER_URL . 'assets/importer.css',
		[],
		'1.0.0'
	);

	wp_enqueue_script(
		'tcg-importer-js',
		TCG_IMPORTER_URL . 'assets/importer.js',
		[ 'jquery' ],
		'1.0.0',
		true
	);

	wp_localize_script( 'tcg-importer-js', 'tcgImporter', [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'tcg_importer_nonce' ),
	] );
} );

/**
 * Render the admin page.
 */
function tcg_importer_render_page() {
	?>
	<div class="wrap tcg-importer-wrap">
		<h1>TCG Importer — Yu-Gi-Oh!</h1>

		<div class="tcg-importer-controls">
			<label for="tcg-set-select">Seleccionar Set:</label>
			<select id="tcg-set-select" disabled>
				<option value="">Cargando sets…</option>
			</select>

			<button id="tcg-import-btn" class="button button-primary" disabled>Importar</button>
			<button id="tcg-cancel-btn" class="button" style="display:none;">Cancelar</button>
		</div>

		<div class="tcg-progress-wrapper" style="display:none;">
			<div class="tcg-progress-bar-outer">
				<div class="tcg-progress-bar-inner" style="width:0%;">
					<span class="tcg-progress-text">0%</span>
				</div>
			</div>
			<p class="tcg-progress-status"></p>
		</div>

		<div class="tcg-summary" style="display:none;"></div>

		<div class="tcg-log-wrapper">
			<h3>Log</h3>
			<div id="tcg-log" class="tcg-log"></div>
		</div>
	</div>
	<?php
}

/**
 * AJAX: Fetch sets from YGOProDeck.
 */
add_action( 'wp_ajax_tcg_fetch_sets', function () {
	check_ajax_referer( 'tcg_importer_nonce', 'nonce' );

	$importer = new TCG_YGO_Importer();
	$sets     = $importer->fetch_sets();

	if ( is_wp_error( $sets ) ) {
		wp_send_json_error( $sets->get_error_message() );
	}

	wp_send_json_success( $sets );
} );

/**
 * AJAX: Count cards in a set.
 */
add_action( 'wp_ajax_tcg_count_cards', function () {
	check_ajax_referer( 'tcg_importer_nonce', 'nonce' );

	$set_name = isset( $_POST['set'] ) ? sanitize_text_field( wp_unslash( $_POST['set'] ) ) : '';

	if ( empty( $set_name ) ) {
		wp_send_json_error( 'No se especificó un set.' );
	}

	$importer = new TCG_YGO_Importer();
	$count    = $importer->count_cards( $set_name );

	if ( is_wp_error( $count ) ) {
		wp_send_json_error( $count->get_error_message() );
	}

	wp_send_json_success( [ 'total' => $count ] );
} );

/**
 * AJAX: Import a batch of cards.
 */
add_action( 'wp_ajax_tcg_import_batch', function () {
	check_ajax_referer( 'tcg_importer_nonce', 'nonce' );

	$set_name = isset( $_POST['set'] ) ? sanitize_text_field( wp_unslash( $_POST['set'] ) ) : '';
	$offset   = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
	$limit    = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 20;

	if ( empty( $set_name ) ) {
		wp_send_json_error( 'No se especificó un set.' );
	}

	$importer = new TCG_YGO_Importer();
	$result   = $importer->import_batch( $set_name, $offset, $limit );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( $result );
} );
