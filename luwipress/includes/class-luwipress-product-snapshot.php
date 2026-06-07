<?php
/**
 * LuwiPress Product Snapshot — WooCommerce product snapshot / rollback.
 *
 * Mirrors the Elementor snapshot pattern (10-deep ring buffer in post meta,
 * pre-rollback auto-snapshot) but captures a full WooCommerce product: post
 * fields, ALL post meta, gallery, term assignments, and every variation
 * (child post fields + meta). Built so risky bulk edits — price/stock sweeps,
 * attribute restructures, AI re-enrichment — can be reverted in one call.
 *
 * Storage is slash-safe: the payload is serialize()d then base64-encoded, so
 * nested quotes/backslashes in meta (Elementor JSON, ACF, etc.) survive the
 * round-trip through the WP meta API untouched. On restore, meta is
 * maybe_unserialize()d then wp_slash()d so add_post_meta re-serializes
 * exactly once (the classic double-(un)slash trap is avoided).
 *
 * WooCommerce is a soft dependency: routes register always but return 503
 * when WC is inactive.
 *
 * @package LuwiPress
 * @since   3.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Product_Snapshot {

	/**
	 * Singleton instance.
	 *
	 * @var LuwiPress_Product_Snapshot|null
	 */
	private static $instance = null;

	/**
	 * Post meta key holding the snapshot ring buffer.
	 */
	const META_KEY = '_luwipress_product_snapshots';

	/**
	 * Max snapshots retained per product.
	 */
	const MAX_SNAPSHOTS = 10;

	/**
	 * Meta keys never touched on restore (WP/internal bookkeeping + our own
	 * snapshot history, which must survive a rollback).
	 *
	 * @var string[]
	 */
	const PROTECTED_META = array( '_edit_lock', '_edit_last', self::META_KEY );

	/**
	 * Get the singleton instance.
	 *
	 * @return LuwiPress_Product_Snapshot
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/**
	 * Permission gate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_permission( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	/**
	 * Register REST endpoints.
	 */
	public function register_endpoints() {
		register_rest_route( 'luwipress/v1', '/product/snapshot', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_create' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'product_id' => array( 'type' => 'integer', 'required' => true ),
				'label'      => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/product/rollback', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_rollback' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'product_id'   => array( 'type' => 'integer', 'required' => true ),
				'snapshot_id'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'replace_meta' => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/product/snapshots/(?P<product_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_list' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
	}

	/**
	 * Guard: WooCommerce active?
	 *
	 * @return bool
	 */
	private function wc_active() {
		return function_exists( 'wc_get_product' );
	}

	/**
	 * WP_Error for WC-inactive case.
	 *
	 * @return WP_Error
	 */
	private function wc_error() {
		return new WP_Error( 'wc_inactive', 'WooCommerce is not active — product snapshots require WooCommerce.', array( 'status' => 503 ) );
	}

	// ------------------------------------------------------------------
	// Handlers
	// ------------------------------------------------------------------

	/**
	 * POST /product/snapshot
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_create( $request ) {
		if ( ! $this->wc_active() ) {
			return $this->wc_error();
		}
		$data       = $this->body( $request );
		$product_id = (int) ( $data['product_id'] ?? 0 );
		$label      = isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : 'Snapshot';

		$err = $this->validate_product( $product_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$record = $this->create_snapshot( $product_id, $label );
		LuwiPress_Logger::log( 'Product snapshot created (product ' . $product_id . ', snapshot ' . $record['id'] . ')', 'info' );

		return rest_ensure_response( array(
			'success'     => true,
			'product_id'  => $product_id,
			'snapshot_id' => $record['id'],
			'snapshot'    => $this->strip_payload( $record ),
		) );
	}

	/**
	 * GET /product/snapshots/{product_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_list( $request ) {
		if ( ! $this->wc_active() ) {
			return $this->wc_error();
		}
		$product_id = (int) $request->get_param( 'product_id' );
		$snapshots  = $this->get_snapshots( $product_id );
		$list       = array_map( array( $this, 'strip_payload' ), $snapshots );
		return rest_ensure_response( array(
			'product_id' => $product_id,
			'count'      => count( $list ),
			'snapshots'  => array_values( $list ),
		) );
	}

	/**
	 * POST /product/rollback
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_rollback( $request ) {
		if ( ! $this->wc_active() ) {
			return $this->wc_error();
		}
		$data         = $this->body( $request );
		$product_id   = (int) ( $data['product_id'] ?? 0 );
		$snapshot_id  = isset( $data['snapshot_id'] ) ? sanitize_text_field( (string) $data['snapshot_id'] ) : '';
		$replace_meta = ! empty( $data['replace_meta'] );

		$err = $this->validate_product( $product_id );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		$snapshots = $this->get_snapshots( $product_id );
		if ( empty( $snapshots ) ) {
			return new WP_Error( 'no_snapshots', 'No snapshots exist for this product.', array( 'status' => 404 ) );
		}

		$target = null;
		if ( '' === $snapshot_id ) {
			$target = $snapshots[0]; // most recent
		} else {
			foreach ( $snapshots as $snap ) {
				if ( $snap['id'] === $snapshot_id ) {
					$target = $snap;
					break;
				}
			}
		}
		if ( null === $target ) {
			return new WP_Error( 'snapshot_not_found', 'Snapshot id not found for this product.', array( 'status' => 404 ) );
		}

		$payload = $this->decode_payload( $target );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Safety net: snapshot the current state BEFORE overwriting it.
		$this->create_snapshot( $product_id, 'Pre-rollback backup' );

		$report = $this->restore_payload( $product_id, $payload, $replace_meta );

		// Bust WC + post caches so the restored values render immediately.
		clean_post_cache( $product_id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		LuwiPress_Logger::log( 'Product rolled back (product ' . $product_id . ' → snapshot ' . $target['id'] . ')', 'info' );

		return rest_ensure_response( array(
			'success'     => true,
			'product_id'  => $product_id,
			'snapshot_id' => $target['id'],
			'label'       => $target['label'],
			'timestamp'   => $target['timestamp'],
			'report'      => $report,
		) );
	}

	// ------------------------------------------------------------------
	// Snapshot capture
	// ------------------------------------------------------------------

	/**
	 * Capture a product into a snapshot record and store it (ring buffer).
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $label      Label.
	 * @return array The stored record.
	 */
	private function create_snapshot( $product_id, $label ) {
		$payload = $this->capture_payload( $product_id );
		$raw     = serialize( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- internal slash-safe blob, base64'd below.

		$record = array(
			'id'              => substr( md5( wp_generate_uuid4() ), 0, 8 ),
			'label'           => $label,
			'timestamp'       => current_time( 'c' ),
			'user'            => get_current_user_id(),
			'product_id'      => $product_id,
			'variation_count' => count( $payload['variations'] ),
			'meta_keys'       => count( $payload['meta'] ),
			'byte_length'     => strlen( $raw ),
			'sha256_16'       => substr( hash( 'sha256', $raw ), 0, 16 ),
			'payload_b64'     => base64_encode( $raw ),
		);

		$snapshots = $this->get_snapshots( $product_id );
		array_unshift( $snapshots, $record );
		$snapshots = array_slice( $snapshots, 0, self::MAX_SNAPSHOTS );

		// payload_b64 is base64 (no backslashes/quotes); label may carry them,
		// so wp_slash the whole array for a correct meta write.
		update_post_meta( $product_id, self::META_KEY, wp_slash( $snapshots ) );

		return $record;
	}

	/**
	 * Build the full capture payload for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function capture_payload( $product_id ) {
		$post = get_post( $product_id );

		$payload = array(
			'schema'     => 1,
			'post'       => $this->capture_post_fields( $post ),
			'meta'       => $this->capture_meta( $product_id ),
			'terms'      => $this->capture_terms( $product_id ),
			'variations' => array(),
		);

		$variations = get_posts( array(
			'post_type'        => 'product_variation',
			'post_parent'      => $product_id,
			'numberposts'      => -1,
			'post_status'      => 'any',
			'orderby'          => 'menu_order',
			'order'            => 'ASC',
			'suppress_filters' => true,
		) );
		foreach ( $variations as $variation ) {
			$payload['variations'][] = array(
				'id'   => (int) $variation->ID,
				'post' => $this->capture_post_fields( $variation ),
				'meta' => $this->capture_meta( $variation->ID ),
			);
		}

		return $payload;
	}

	/**
	 * Capture the editable post fields of a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function capture_post_fields( $post ) {
		return array(
			'post_title'    => $post->post_title,
			'post_content'  => $post->post_content,
			'post_excerpt'  => $post->post_excerpt,
			'post_status'   => $post->post_status,
			'post_name'     => $post->post_name,
			'menu_order'    => (int) $post->menu_order,
			'post_parent'   => (int) $post->post_parent,
			'comment_status'=> $post->comment_status,
		);
	}

	/**
	 * Capture all meta for a post (raw, possibly-serialized strings — exactly
	 * as WP stores them). The snapshot-history key is excluded so it never
	 * nests inside itself.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function capture_meta( $post_id ) {
		$meta = get_post_meta( $post_id );
		unset( $meta[ self::META_KEY ] );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Capture term assignments across every taxonomy attached to products.
	 *
	 * @param int $product_id Product ID.
	 * @return array taxonomy => int[] term IDs
	 */
	private function capture_terms( $product_id ) {
		$out        = array();
		$taxonomies = get_object_taxonomies( 'product' );
		foreach ( $taxonomies as $tax ) {
			$ids = wp_get_object_terms( $product_id, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) && ! empty( $ids ) ) {
				$out[ $tax ] = array_map( 'intval', $ids );
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Restore
	// ------------------------------------------------------------------

	/**
	 * Restore a decoded payload onto the product.
	 *
	 * @param int   $product_id   Product ID.
	 * @param array $payload      Decoded payload.
	 * @param bool  $replace_meta Full meta replace vs. overlay (default overlay).
	 * @return array Report.
	 */
	private function restore_payload( $product_id, $payload, $replace_meta ) {
		$report = array(
			'post_restored'        => false,
			'meta_mode'            => $replace_meta ? 'replace' : 'overlay',
			'terms_restored'       => array(),
			'variations_restored'  => array(),
			'variations_missing'   => array(),
			'variations_unmanaged' => array(),
		);

		// Post fields.
		if ( ! empty( $payload['post'] ) && is_array( $payload['post'] ) ) {
			$fields       = $payload['post'];
			$fields['ID'] = $product_id;
			$res          = wp_update_post( wp_slash( $fields ), true );
			$report['post_restored'] = ! is_wp_error( $res );
		}

		// Meta.
		if ( isset( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
			$this->restore_meta( $product_id, $payload['meta'], $replace_meta );
		}

		// Terms.
		if ( isset( $payload['terms'] ) && is_array( $payload['terms'] ) ) {
			foreach ( $payload['terms'] as $tax => $term_ids ) {
				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}
				wp_set_object_terms( $product_id, array_map( 'intval', (array) $term_ids ), $tax, false );
				$report['terms_restored'][ $tax ] = count( (array) $term_ids );
			}
		}

		// Variations — restore those that still exist; report drift.
		$snapshot_var_ids = array();
		if ( isset( $payload['variations'] ) && is_array( $payload['variations'] ) ) {
			foreach ( $payload['variations'] as $var ) {
				$vid                 = (int) ( $var['id'] ?? 0 );
				$snapshot_var_ids[]  = $vid;
				$vpost               = $vid ? get_post( $vid ) : null;
				if ( ! $vpost || 'product_variation' !== $vpost->post_type || (int) $vpost->post_parent !== $product_id ) {
					$report['variations_missing'][] = $vid;
					continue;
				}
				if ( ! empty( $var['post'] ) && is_array( $var['post'] ) ) {
					$vf       = $var['post'];
					$vf['ID'] = $vid;
					wp_update_post( wp_slash( $vf ), true );
				}
				if ( isset( $var['meta'] ) && is_array( $var['meta'] ) ) {
					$this->restore_meta( $vid, $var['meta'], $replace_meta );
				}
				$report['variations_restored'][] = $vid;
			}
		}

		// Variations that exist now but weren't in the snapshot (added since).
		$current_vars = get_posts( array(
			'post_type'   => 'product_variation',
			'post_parent' => $product_id,
			'numberposts' => -1,
			'post_status' => 'any',
			'fields'      => 'ids',
		) );
		foreach ( (array) $current_vars as $cvid ) {
			if ( ! in_array( (int) $cvid, $snapshot_var_ids, true ) ) {
				$report['variations_unmanaged'][] = (int) $cvid;
			}
		}

		return $report;
	}

	/**
	 * Restore meta onto a post. Each captured value is maybe_unserialize()d
	 * then wp_slash()d so add_post_meta serializes it exactly once.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $meta    key => array of raw values.
	 * @param bool  $replace Replace-all vs. overlay.
	 */
	private function restore_meta( $post_id, $meta, $replace ) {
		if ( $replace ) {
			$current = get_post_meta( $post_id );
			foreach ( array_keys( (array) $current ) as $k ) {
				if ( in_array( $k, self::PROTECTED_META, true ) ) {
					continue;
				}
				delete_post_meta( $post_id, $k );
			}
		}

		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, self::PROTECTED_META, true ) ) {
				continue;
			}
			if ( ! $replace ) {
				delete_post_meta( $post_id, $key );
			}
			foreach ( (array) $values as $v ) {
				$real = maybe_unserialize( $v );
				add_post_meta( $post_id, $key, wp_slash( $real ) );
			}
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Validate a product id.
	 *
	 * @param int $product_id Product ID.
	 * @return true|WP_Error
	 */
	private function validate_product( $product_id ) {
		if ( $product_id <= 0 ) {
			return new WP_Error( 'invalid_product', 'A positive product_id is required.', array( 'status' => 400 ) );
		}
		$post = get_post( $product_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return new WP_Error( 'not_a_product', 'No WooCommerce product found with that id.', array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Read the snapshots ring buffer for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_snapshots( $product_id ) {
		$snapshots = get_post_meta( $product_id, self::META_KEY, true );
		return is_array( $snapshots ) ? $snapshots : array();
	}

	/**
	 * Decode a snapshot record's payload blob.
	 *
	 * @param array $record Snapshot record.
	 * @return array|WP_Error
	 */
	private function decode_payload( $record ) {
		$b64 = $record['payload_b64'] ?? '';
		if ( '' === $b64 ) {
			return new WP_Error( 'empty_payload', 'Snapshot payload is empty.', array( 'status' => 500 ) );
		}
		$raw = base64_decode( $b64, true );
		if ( false === $raw ) {
			return new WP_Error( 'bad_payload', 'Snapshot payload failed base64 decode.', array( 'status' => 500 ) );
		}
		$payload = @unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.unserialize_unserialize -- internal blob we wrote.
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'corrupt_payload', 'Snapshot payload could not be unserialized.', array( 'status' => 500 ) );
		}
		return $payload;
	}

	/**
	 * Strip the heavy payload from a record for list responses.
	 *
	 * @param array $record Record.
	 * @return array
	 */
	private function strip_payload( $record ) {
		unset( $record['payload_b64'] );
		return $record;
	}

	/**
	 * Read JSON (or form) body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function body( $request ) {
		$data = $request->get_json_params();
		if ( empty( $data ) ) {
			$data = $request->get_body_params();
		}
		return (array) $data;
	}
}
