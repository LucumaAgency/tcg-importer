<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCG_YGO_Importer {

	const API_BASE = 'https://db.ygoprodeck.com/api/v7/';

	/**
	 * Fetch all card sets from YGOProDeck.
	 *
	 * @return array|WP_Error
	 */
	public function fetch_sets() {
		$response = wp_remote_get( self::API_BASE . 'cardsets.php', [
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'api_error', 'Respuesta inválida de la API de sets.' );
		}

		$sets = [];
		foreach ( $body as $set ) {
			if ( ! empty( $set['set_name'] ) ) {
				$sets[] = [
					'set_name'     => $set['set_name'],
					'set_code'     => $set['set_code'] ?? '',
					'num_of_cards' => $set['num_of_cards'] ?? 0,
					'tcg_date'     => $set['tcg_date'] ?? '',
				];
			}
		}

		usort( $sets, function ( $a, $b ) {
			return strcasecmp( $a['set_name'], $b['set_name'] );
		} );

		return $sets;
	}

	/**
	 * Count cards in a set.
	 *
	 * @param string $set_name
	 * @return int|WP_Error
	 */
	public function count_cards( $set_name ) {
		$url = add_query_arg( [
			'cardset' => $set_name,
			'num'     => 1,
			'offset'  => 0,
		], self::API_BASE . 'cardinfo.php' );

		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error( 'api_error', 'La API no tiene cartas para este set. Es posible que aún no haya sido lanzado.' );
		}

		if ( isset( $body['meta']['total_rows'] ) ) {
			return (int) $body['meta']['total_rows'];
		}

		if ( ! empty( $body['data'] ) ) {
			return count( $body['data'] );
		}

		return new WP_Error( 'api_error', 'No se pudieron contar las cartas del set.' );
	}

	/**
	 * Fetch a batch of cards from the API.
	 *
	 * @param string $set_name
	 * @param int    $num
	 * @param int    $offset
	 * @return array|WP_Error
	 */
	public function fetch_cards( $set_name, $num = 20, $offset = 0 ) {
		$url = add_query_arg( [
			'cardset' => $set_name,
			'num'     => $num,
			'offset'  => $offset,
		], self::API_BASE . 'cardinfo.php' );

		$response = wp_remote_get( $url, [ 'timeout' => 60 ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return [];
		}

		return $body['data'];
	}

	/**
	 * Find the set entry matching the imported set name within card_sets.
	 *
	 * @param array  $card_sets Array of set entries from the API.
	 * @param string $set_name  The set being imported.
	 * @return array|null
	 */
	private function find_set_entry( $card_sets, $set_name ) {
		foreach ( $card_sets as $cs ) {
			if ( isset( $cs['set_name'] ) && $cs['set_name'] === $set_name ) {
				return $cs;
			}
		}
		return null;
	}

	/**
	 * Import a single card for a specific set into the ygo_card CPT.
	 * Each card+set combination = 1 post.
	 *
	 * @param array  $card_data Card data from the API.
	 * @param string $set_name  The set being imported.
	 * @return array Result with status and message.
	 */
	public function import_card( $card_data, $set_name ) {
		$card_id   = $card_data['id'] ?? 0;
		$card_name = $card_data['name'] ?? '';

		if ( empty( $card_id ) || empty( $card_name ) ) {
			return [
				'status'  => 'error',
				'message' => 'Datos de carta incompletos.',
			];
		}

		// Find the specific set entry for this import.
		$set_entry = null;
		if ( ! empty( $card_data['card_sets'] ) ) {
			$set_entry = $this->find_set_entry( $card_data['card_sets'], $set_name );
		}

		$set_code = $set_entry['set_code'] ?? '';

		// Dedup by _ygo_card_id + _ygo_set_code.
		$existing = get_posts( [
			'post_type'      => 'ygo_card',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'   => '_ygo_card_id',
					'value' => $card_id,
				],
				[
					'key'   => '_ygo_set_code',
					'value' => $set_code,
				],
			],
		] );

		$is_update = ! empty( $existing );
		$post_id   = $is_update ? $existing[0] : 0;

		// Post title includes set code for uniqueness: "Zoroa, the Magistus of Flame (MZMU-EN094)"
		$post_title = $card_name;
		if ( ! empty( $set_code ) ) {
			$post_title .= ' (' . $set_code . ')';
		}

		$post_args = [
			'post_type'    => 'ygo_card',
			'post_title'   => sanitize_text_field( $post_title ),
			'post_content' => isset( $card_data['desc'] ) ? wp_kses_post( $card_data['desc'] ) : '',
			'post_status'  => 'publish',
		];

		if ( $is_update ) {
			$post_args['ID'] = $post_id;
			$result = wp_update_post( $post_args, true );
		} else {
			$result = wp_insert_post( $post_args, true );
		}

		if ( is_wp_error( $result ) ) {
			return [
				'status'  => 'error',
				'message' => $card_name . ': ' . $result->get_error_message(),
			];
		}

		$post_id = $is_update ? $post_id : $result;

		// Save meta fields.
		$this->save_meta( $post_id, $card_data, $set_entry );

		// Set taxonomies (only this set).
		$this->save_taxonomies( $post_id, $card_data, $set_name );

		// Download featured image if not already set.
		if ( ! has_post_thumbnail( $post_id ) ) {
			$this->save_featured_image( $post_id, $card_data );
		}

		$label = $card_name;
		if ( $set_code ) {
			$label .= ' [' . $set_code . ']';
		}

		return [
			'status'  => $is_update ? 'updated' : 'created',
			'message' => $label,
			'post_id' => $post_id,
		];
	}

	/**
	 * Save card meta fields.
	 *
	 * @param int        $post_id
	 * @param array      $card_data
	 * @param array|null $set_entry The specific set entry for this post.
	 */
	private function save_meta( $post_id, $card_data, $set_entry ) {
		update_post_meta( $post_id, '_ygo_card_id', $card_data['id'] );

		if ( isset( $card_data['frameType'] ) ) {
			update_post_meta( $post_id, '_ygo_frame_type', sanitize_text_field( $card_data['frameType'] ) );
		}

		if ( isset( $card_data['typeline'] ) && is_array( $card_data['typeline'] ) ) {
			update_post_meta( $post_id, '_ygo_typeline', sanitize_text_field( implode( ' / ', $card_data['typeline'] ) ) );
		}

		update_post_meta( $post_id, '_ygo_atk', $card_data['atk'] ?? '' );
		update_post_meta( $post_id, '_ygo_def', $card_data['def'] ?? '' );

		$frame = $card_data['frameType'] ?? '';
		$level = $card_data['level'] ?? '';

		if ( 'xyz' === $frame || 'xyz_pendulum' === $frame ) {
			update_post_meta( $post_id, '_ygo_rank', $level );
			update_post_meta( $post_id, '_ygo_level', '' );
		} else {
			update_post_meta( $post_id, '_ygo_level', $level );
			update_post_meta( $post_id, '_ygo_rank', '' );
		}

		if ( isset( $card_data['linkval'] ) ) {
			update_post_meta( $post_id, '_ygo_linkval', (int) $card_data['linkval'] );
		}

		if ( isset( $card_data['scale'] ) ) {
			update_post_meta( $post_id, '_ygo_scale', (int) $card_data['scale'] );
		}

		if ( isset( $card_data['ygoprodeck_url'] ) ) {
			update_post_meta( $post_id, '_ygo_source_url', esc_url_raw( $card_data['ygoprodeck_url'] ) );
		}

		if ( ! empty( $card_data['card_prices'][0] ) ) {
			update_post_meta( $post_id, '_ygo_ref_prices', wp_json_encode( $card_data['card_prices'][0] ) );
		}

		// Single set fields for this post.
		if ( $set_entry ) {
			update_post_meta( $post_id, '_ygo_set_code', sanitize_text_field( $set_entry['set_code'] ?? '' ) );
			update_post_meta( $post_id, '_ygo_set_rarity', sanitize_text_field( $set_entry['set_rarity'] ?? '' ) );
			update_post_meta( $post_id, '_ygo_set_rarity_code', sanitize_text_field( $set_entry['set_rarity_code'] ?? '' ) );
			update_post_meta( $post_id, '_ygo_set_price', sanitize_text_field( $set_entry['set_price'] ?? '' ) );
		}
	}

	/**
	 * Save card taxonomies. Only assigns the current import set.
	 *
	 * @param int    $post_id
	 * @param array  $card_data
	 * @param string $set_name The set being imported.
	 */
	private function save_taxonomies( $post_id, $card_data, $set_name ) {
		if ( ! empty( $card_data['type'] ) ) {
			wp_set_object_terms( $post_id, $card_data['type'], 'ygo_card_type' );
		}

		if ( ! empty( $card_data['attribute'] ) ) {
			wp_set_object_terms( $post_id, $card_data['attribute'], 'ygo_attribute' );
		}

		if ( ! empty( $card_data['race'] ) ) {
			wp_set_object_terms( $post_id, $card_data['race'], 'ygo_race' );
		}

		if ( ! empty( $card_data['archetype'] ) ) {
			wp_set_object_terms( $post_id, $card_data['archetype'], 'ygo_archetype' );
		}

		// Only this set as taxonomy term.
		if ( ! empty( $set_name ) ) {
			wp_set_object_terms( $post_id, $set_name, 'ygo_set' );
		}
	}

	/**
	 * Download and set the featured image for a card.
	 *
	 * @param int   $post_id
	 * @param array $card_data
	 */
	private function save_featured_image( $post_id, $card_data ) {
		if ( empty( $card_data['card_images'][0]['image_url'] ) ) {
			return;
		}

		$image_url = $card_data['card_images'][0]['image_url'];

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $image_url, $post_id, sanitize_text_field( $card_data['name'] ?? '' ), 'id' );

		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	/**
	 * Import a batch of cards from a set.
	 *
	 * @param string $set_name
	 * @param int    $offset
	 * @param int    $limit
	 * @return array|WP_Error Stats array with created, updated, errors, log.
	 */
	public function import_batch( $set_name, $offset = 0, $limit = 20 ) {
		$cards = $this->fetch_cards( $set_name, $limit, $offset );

		if ( is_wp_error( $cards ) ) {
			return $cards;
		}

		$stats = [
			'created' => 0,
			'updated' => 0,
			'errors'  => 0,
			'log'     => [],
			'count'   => count( $cards ),
		];

		foreach ( $cards as $card_data ) {
			$result = $this->import_card( $card_data, $set_name );

			$stats['log'][] = $result;

			switch ( $result['status'] ) {
				case 'created':
					$stats['created']++;
					break;
				case 'updated':
					$stats['updated']++;
					break;
				default:
					$stats['errors']++;
					break;
			}
		}

		// Invalidate card list cache so new cards appear in vendor search.
		if ( $stats['created'] > 0 ) {
			delete_transient( 'tcg_dokan_cards_js' );
		}

		return $stats;
	}
}
