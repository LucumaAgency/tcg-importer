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
	 * @param string $set_code Optional set code for fallback search.
	 * @return int|WP_Error
	 */
	public function count_cards( $set_name, $set_code = '' ) {
		$url = add_query_arg( [
			'cardset' => $set_name,
			'num'     => 1,
			'offset'  => 0,
		], self::API_BASE . 'cardinfo.php' );

		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $body['error'] ) ) {
				if ( isset( $body['meta']['total_rows'] ) ) {
					return (int) $body['meta']['total_rows'];
				}
				if ( ! empty( $body['data'] ) ) {
					return count( $body['data'] );
				}
			}
		}

		// Fallback: search by set_code in full database.
		if ( $set_code ) {
			$cards = $this->fetch_cards_by_code( $set_code, $set_name );
			if ( is_wp_error( $cards ) ) {
				return $cards;
			}
			$count = count( $cards );
			if ( $count > 0 ) {
				return $count;
			}
		}

		return new WP_Error( 'api_error', 'No se encontraron cartas para este set en la API.' );
	}

	/**
	 * Fetch a batch of cards from the API.
	 *
	 * @param string $set_name
	 * @param int    $num
	 * @param int    $offset
	 * @param string $set_code Optional set code for fallback.
	 * @return array|WP_Error
	 */
	public function fetch_cards( $set_name, $num = 20, $offset = 0, $set_code = '' ) {
		$url = add_query_arg( [
			'cardset' => $set_name,
			'num'     => $num,
			'offset'  => $offset,
		], self::API_BASE . 'cardinfo.php' );

		$response = wp_remote_get( $url, [ 'timeout' => 60 ] );

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
				return $body['data'];
			}
		}

		// Fallback: use cached full-DB cards filtered by set_code.
		if ( $set_code ) {
			$all_cards = $this->fetch_cards_by_code( $set_code, $set_name );
			if ( is_wp_error( $all_cards ) ) {
				return $all_cards;
			}
			return array_slice( $all_cards, $offset, $num );
		}

		return [];
	}

	/**
	 * Fetch all cards for a set by filtering the full database using set_code.
	 * Results are cached in a transient for 1 hour to avoid re-downloading.
	 *
	 * @param string $set_code The set code prefix (e.g. "L5DD").
	 * @param string $set_name The set name for matching.
	 * @return array|WP_Error
	 */
	public function fetch_cards_by_code( $set_code, $set_name ) {
		$cache_key = 'tcg_import_fallback_' . sanitize_key( $set_code );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		// Download full database (no filters).
		$response = wp_remote_get( self::API_BASE . 'cardinfo.php', [
			'timeout' => 120,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['data'] ) ) {
			return new WP_Error( 'api_error', 'No se pudo descargar la base de datos completa.' );
		}

		// Filter cards that have this set_code in their card_sets.
		$filtered = [];
		$code_prefix = strtoupper( $set_code );

		foreach ( $body['data'] as $card ) {
			if ( empty( $card['card_sets'] ) ) {
				continue;
			}
			foreach ( $card['card_sets'] as $cs ) {
				$cs_code = strtoupper( $cs['set_code'] ?? '' );
				if ( strpos( $cs_code, $code_prefix . '-' ) === 0 || $cs_code === $code_prefix ) {
					$filtered[] = $card;
					break;
				}
			}
		}

		// Cache for 1 hour.
		set_transient( $cache_key, $filtered, HOUR_IN_SECONDS );

		return $filtered;
	}

	/**
	 * Find the set entry matching the imported set name within card_sets.
	 *
	 * @param array  $card_sets Array of set entries from the API.
	 * @param string $set_name  The set being imported.
	 * @return array|null
	 */
	private function find_set_entry( $card_sets, $set_name, $set_code = '' ) {
		// Try exact name match first.
		foreach ( $card_sets as $cs ) {
			if ( isset( $cs['set_name'] ) && $cs['set_name'] === $set_name ) {
				return $cs;
			}
		}
		// Try HTML-decoded name match.
		$decoded = html_entity_decode( $set_name, ENT_QUOTES, 'UTF-8' );
		foreach ( $card_sets as $cs ) {
			$cs_decoded = html_entity_decode( $cs['set_name'] ?? '', ENT_QUOTES, 'UTF-8' );
			if ( $cs_decoded === $decoded || $cs_decoded === $set_name ) {
				return $cs;
			}
		}
		// Try set_code prefix match.
		if ( $set_code ) {
			$prefix = strtoupper( $set_code );
			foreach ( $card_sets as $cs ) {
				if ( strpos( strtoupper( $cs['set_code'] ?? '' ), $prefix . '-' ) === 0 ) {
					return $cs;
				}
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
	public function import_card( $card_data, $set_name, $set_code = '' ) {
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
			$set_entry = $this->find_set_entry( $card_data['card_sets'], $set_name, $set_code );
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
	 * Import a card by searching the API by exact name, with a custom set.
	 *
	 * @param string $card_name Exact card name to search.
	 * @param string $set_name  Custom set name.
	 * @param string $set_code  Custom set code (e.g. "L26D-ENM01").
	 * @return array|WP_Error
	 */
	public function import_card_by_name( $card_name, $set_name, $set_code = '' ) {
		// Search by exact name.
		$url = add_query_arg( [
			'name' => $card_name,
		], self::API_BASE . 'cardinfo.php' );

		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $card_name . ': Error de red.' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) || empty( $body['data'][0] ) ) {
			// Try fuzzy search.
			$url = add_query_arg( [
				'fname' => $card_name,
				'num'   => 1,
			], self::API_BASE . 'cardinfo.php' );

			$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'api_error', $card_name . ': Error de red.' );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! empty( $body['error'] ) || empty( $body['data'][0] ) ) {
				return [
					'status'  => 'error',
					'message' => $card_name . ': No encontrada en la API.',
				];
			}
		}

		$card_data = $body['data'][0];

		// Build a custom set entry since the set doesn't exist in the API.
		$custom_set_entry = [
			'set_name'       => $set_name,
			'set_code'       => $set_code,
			'set_rarity'     => '',
			'set_rarity_code' => '',
			'set_price'      => '',
		];

		// Try to find rarity from an existing set entry (use most common).
		if ( ! empty( $card_data['card_sets'] ) ) {
			$custom_set_entry['set_rarity']      = $card_data['card_sets'][0]['set_rarity'] ?? '';
			$custom_set_entry['set_rarity_code'] = $card_data['card_sets'][0]['set_rarity_code'] ?? '';
		}

		return $this->import_card_with_custom_set( $card_data, $set_name, $custom_set_entry );
	}

	/**
	 * Import a card with a custom set entry (for sets not in the API).
	 */
	private function import_card_with_custom_set( $card_data, $set_name, $set_entry ) {
		$card_id   = $card_data['id'] ?? 0;
		$card_name = $card_data['name'] ?? '';
		$set_code  = $set_entry['set_code'] ?? '';

		if ( empty( $card_id ) || empty( $card_name ) ) {
			return [
				'status'  => 'error',
				'message' => 'Datos de carta incompletos.',
			];
		}

		// Dedup by _ygo_card_id + _ygo_set_code.
		$existing = get_posts( [
			'post_type'      => 'ygo_card',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_ygo_card_id', 'value' => $card_id ],
				[ 'key' => '_ygo_set_code', 'value' => $set_code ],
			],
		] );

		$is_update = ! empty( $existing );
		$post_id   = $is_update ? $existing[0] : 0;

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

		$this->save_meta( $post_id, $card_data, $set_entry );
		$this->save_taxonomies( $post_id, $card_data, $set_name );

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
	 * Import a batch of cards from a set.
	 *
	 * @param string $set_name
	 * @param int    $offset
	 * @param int    $limit
	 * @return array|WP_Error Stats array with created, updated, errors, log.
	 */
	public function import_batch( $set_name, $offset = 0, $limit = 20, $set_code = '' ) {
		$cards = $this->fetch_cards( $set_name, $limit, $offset, $set_code );

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
			$result = $this->import_card( $card_data, $set_name, $set_code );

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
			delete_transient( 'tcg_manager_cards_js' );
		}

		return $stats;
	}
}
