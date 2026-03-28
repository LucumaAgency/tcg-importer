<?php
/**
 * Plugin Name: TCG Importer
 * Description: Importador de cartas Yu-Gi-Oh! desde la API de YGOProDeck al CPT ygo_card.
 * Version: 1.3.1
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
			<label for="tcg-sort-select">Ordenar por:</label>
			<select id="tcg-sort-select">
				<option value="name">Nombre (A-Z)</option>
				<option value="date">Fecha de lanzamiento (recientes)</option>
				<option value="code">Código de set (A-Z)</option>
			</select>

			<label for="tcg-set-select">Seleccionar Set:</label>
			<select id="tcg-set-select" disabled>
				<option value="">Cargando sets…</option>
			</select>

			<button id="tcg-import-btn" class="button button-primary" disabled>Importar</button>
			<button id="tcg-cancel-btn" class="button" style="display:none;">Cancelar</button>
		</div>

		<hr style="margin:30px 0;">

		<h2>Importar por Lista</h2>
		<p class="description">Para sets que no están en la API. Busca cada carta por nombre y la importa con el set personalizado.</p>

		<div class="tcg-list-controls">
			<div class="tcg-list-fields">
				<div>
					<label for="tcg-list-set-name"><strong>Nombre del set:</strong></label><br>
					<input type="text" id="tcg-list-set-name" placeholder="Legendary Modern Decks 2026" style="width:100%;">
				</div>
				<div>
					<label for="tcg-list-set-code"><strong>Código del set:</strong></label><br>
					<input type="text" id="tcg-list-set-code" placeholder="L26D" style="width:100%;">
				</div>
			</div>

			<div style="margin-top:12px;">
				<label for="tcg-list-cards"><strong>Cartas (una por línea):</strong></label>
				<p class="description">Formato: <code>Nombre de carta | CODIGO-SET</code> — El código individual es opcional.</p>
				<textarea id="tcg-list-cards" rows="12" style="width:100%;font-family:monospace;font-size:13px;" placeholder="Dark Magician | L26D-ENM01&#10;Blue-Eyes White Dragon | L26D-ENM02&#10;Red-Eyes Black Dragon"></textarea>
			</div>

			<div style="margin-top:12px;">
				<button id="tcg-list-import-btn" class="button button-primary">Importar Lista</button>
				<button id="tcg-list-cancel-btn" class="button" style="display:none;">Cancelar</button>
				<span id="tcg-list-status" style="margin-left:12px;color:#555;"></span>
			</div>
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
	$set_code = isset( $_POST['set_code'] ) ? sanitize_text_field( wp_unslash( $_POST['set_code'] ) ) : '';

	if ( empty( $set_name ) ) {
		wp_send_json_error( 'No se especificó un set.' );
	}

	$importer = new TCG_YGO_Importer();
	$count    = $importer->count_cards( $set_name, $set_code );

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
	$set_code = isset( $_POST['set_code'] ) ? sanitize_text_field( wp_unslash( $_POST['set_code'] ) ) : '';
	$offset   = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
	$limit    = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 20;

	if ( empty( $set_name ) ) {
		wp_send_json_error( 'No se especificó un set.' );
	}

	$importer = new TCG_YGO_Importer();
	$result   = $importer->import_batch( $set_name, $offset, $limit, $set_code );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( $result );
} );

/**
 * AJAX: Import a single card by name with a custom set.
 */
add_action( 'wp_ajax_tcg_import_by_name', function () {
	check_ajax_referer( 'tcg_importer_nonce', 'nonce' );

	$card_name = isset( $_POST['card_name'] ) ? wp_specialchars_decode( sanitize_text_field( wp_unslash( $_POST['card_name'] ) ) ) : '';
	$set_name  = isset( $_POST['set_name'] ) ? wp_specialchars_decode( sanitize_text_field( wp_unslash( $_POST['set_name'] ) ) ) : '';
	$set_code  = isset( $_POST['set_code'] ) ? sanitize_text_field( wp_unslash( $_POST['set_code'] ) ) : '';

	if ( empty( $card_name ) || empty( $set_name ) ) {
		wp_send_json_error( 'Nombre de carta y set son obligatorios.' );
	}

	$importer = new TCG_YGO_Importer();
	$result   = $importer->import_card_by_name( $card_name, $set_name, $set_code );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	// Invalidate caches on creation.
	if ( $result['status'] === 'created' ) {
		delete_transient( 'tcg_dokan_cards_js' );
		delete_transient( 'tcg_manager_cards_js' );
		delete_transient( 'tcg_theme_live_search' );
	}

	wp_send_json_success( $result );
} );
