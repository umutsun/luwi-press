<?php
/**
 * LuwiPress Internal Link Audit — read-only "who links to this?" scanner.
 *
 * Given a target URL / path / slug, finds every post whose content (and,
 * optionally, post meta — Elementor data, ACF, builders) references it, with
 * occurrence counts and a context snippet. Built for pre/post-migration link
 * hygiene: before a DNS swap or a slug change, see exactly which pages point
 * at the old URL so they can be repointed — no server log access required.
 *
 * Pure read. No writes, no third-party plugin dependency. Multi-needle: a
 * full URL also matches its relative-path and bare-slug forms, so links
 * written either way are caught.
 *
 * @package LuwiPress
 * @since   3.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Internal_Link_Audit {

	/**
	 * Singleton instance.
	 *
	 * @var LuwiPress_Internal_Link_Audit|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return LuwiPress_Internal_Link_Audit
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
		register_rest_route( 'luwipress/v1', '/internal-links/audit', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_audit' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'target'       => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field', 'description' => 'URL, path, or slug to search for.' ),
				'post_types'   => array( 'type' => 'string', 'required' => false, 'default' => 'post,page,product', 'sanitize_callback' => 'sanitize_text_field' ),
				'status'       => array( 'type' => 'string', 'required' => false, 'default' => 'publish', 'sanitize_callback' => 'sanitize_text_field' ),
				'per_page'     => array( 'type' => 'integer', 'required' => false, 'default' => 50 ),
				'include_meta' => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
			),
		) );
	}

	/**
	 * GET /internal-links/audit
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_audit( $request ) {
		global $wpdb;

		$target = trim( (string) $request->get_param( 'target' ) );
		if ( '' === $target ) {
			return new WP_Error( 'no_target', 'A target URL, path, or slug is required.', array( 'status' => 400 ) );
		}

		$needles = $this->derive_needles( $target );
		if ( empty( $needles ) ) {
			return new WP_Error( 'no_needles', 'Could not derive a searchable needle from the target.', array( 'status' => 400 ) );
		}

		$post_types   = $this->parse_csv( (string) $request->get_param( 'post_types' ), array( 'post', 'page', 'product' ) );
		$status       = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$per_page     = max( 1, min( 200, (int) $request->get_param( 'per_page' ) ) );
		$include_meta = (bool) $request->get_param( 'include_meta' );

		$statuses = ( 'any' === $status ) ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : array( $status );

		// --- Build the LIKE clause for content -------------------------------
		$pt_in     = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$st_in     = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$like_bits = array();
		$like_args = array();
		foreach ( $needles as $needle ) {
			$like_bits[] = 'p.post_content LIKE %s';
			$like_args[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}
		$like_sql = '(' . implode( ' OR ', $like_bits ) . ')';

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			WHERE p.post_type IN ($pt_in)
			AND p.post_status IN ($st_in)
			AND $like_sql
			ORDER BY p.post_modified DESC
			LIMIT %d";
		$args = array_merge( $post_types, $statuses, $like_args, array( $per_page + 1 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders fully bound below.
		$content_ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		$content_ids = array_map( 'intval', (array) $content_ids );

		// --- Optional meta scan ---------------------------------------------
		$meta_ids = array();
		if ( $include_meta ) {
			$mlike_bits = array();
			$mlike_args = array();
			foreach ( $needles as $needle ) {
				$mlike_bits[] = 'pm.meta_value LIKE %s';
				$mlike_args[] = '%' . $wpdb->esc_like( $needle ) . '%';
			}
			$msql = "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type IN ($pt_in)
				AND p.post_status IN ($st_in)
				AND (" . implode( ' OR ', $mlike_bits ) . ")
				LIMIT %d";
			$margs = array_merge( $post_types, $statuses, $mlike_args, array( $per_page + 1 ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders fully bound below.
			$meta_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $msql, $margs ) ) );
		}

		$all_ids   = array_values( array_unique( array_merge( $content_ids, $meta_ids ) ) );
		$truncated = count( $all_ids ) > $per_page;
		$all_ids   = array_slice( $all_ids, 0, $per_page );

		// --- Assemble per-post results --------------------------------------
		$matches = array();
		foreach ( $all_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$content     = (string) $post->post_content;
			$occurrences = 0;
			$sample      = '';
			foreach ( $needles as $needle ) {
				$occurrences += substr_count( $content, $needle );
			}
			$where = $occurrences > 0 ? array( 'content' ) : array();
			if ( $occurrences > 0 ) {
				$sample = $this->snippet( $content, $needles );
			}
			if ( in_array( $pid, $meta_ids, true ) ) {
				$where[] = 'meta';
			}

			$matches[] = array(
				'post_id'     => $pid,
				'title'       => get_the_title( $pid ),
				'post_type'   => $post->post_type,
				'status'      => $post->post_status,
				'permalink'   => get_permalink( $pid ),
				'edit_link'   => get_edit_post_link( $pid, 'raw' ),
				'occurrences' => $occurrences,
				'where'       => array_values( array_unique( $where ) ),
				'sample'      => $sample,
			);
		}

		// Strongest matches first.
		usort( $matches, function ( $a, $b ) {
			return $b['occurrences'] <=> $a['occurrences'];
		} );

		return rest_ensure_response( array(
			'target'        => $target,
			'needles'       => $needles,
			'post_types'    => $post_types,
			'match_count'   => count( $matches ),
			'truncated'     => $truncated,
			'per_page'      => $per_page,
			'meta_scanned'  => $include_meta,
			'matches'       => $matches,
		) );
	}

	/**
	 * Derive distinct search needles from a target so links written as a full
	 * URL, a relative path, or a bare slug all match.
	 *
	 * @param string $target Target.
	 * @return string[] Distinct needles (longest first).
	 */
	private function derive_needles( $target ) {
		$needles = array();
		$target  = trim( $target );

		// Full string as given.
		$needles[] = $target;

		$parsed = wp_parse_url( $target );
		if ( ! empty( $parsed['path'] ) ) {
			$path = $parsed['path'];
			// Path with leading slash (e.g. "/old-page/").
			$needles[] = $path;
			// Bare last slug (e.g. "old-page").
			$trimmed = trim( $path, '/' );
			if ( '' !== $trimmed ) {
				$needles[] = $trimmed;
				$parts = explode( '/', $trimmed );
				$slug  = end( $parts );
				if ( $slug && $slug !== $trimmed ) {
					$needles[] = $slug;
				}
			}
		}

		// De-dupe, drop empties + anything shorter than 3 chars (too noisy).
		$needles = array_values( array_unique( array_filter( $needles, function ( $n ) {
			return is_string( $n ) && strlen( $n ) >= 3;
		} ) ) );

		// Longest needle first so the most specific match drives the snippet.
		usort( $needles, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		return $needles;
	}

	/**
	 * Extract a short context snippet around the first needle occurrence.
	 *
	 * @param string   $content Content.
	 * @param string[] $needles Needles (longest first).
	 * @return string
	 */
	private function snippet( $content, $needles ) {
		foreach ( $needles as $needle ) {
			$pos = strpos( $content, $needle );
			if ( false === $pos ) {
				continue;
			}
			$start   = max( 0, $pos - 60 );
			$raw     = substr( $content, $start, strlen( $needle ) + 120 );
			$snippet = trim( wp_strip_all_tags( $raw ) );
			$snippet = preg_replace( '/\s+/', ' ', $snippet );
			return ( $start > 0 ? '…' : '' ) . $snippet . '…';
		}
		return '';
	}

	/**
	 * Parse a comma-separated list into a sanitized, validated array.
	 *
	 * @param string   $csv      CSV string.
	 * @param string[] $fallback Fallback if empty.
	 * @return string[]
	 */
	private function parse_csv( $csv, $fallback ) {
		$parts = array_filter( array_map( 'trim', explode( ',', $csv ) ) );
		$parts = array_map( 'sanitize_key', $parts );
		$parts = array_values( array_filter( $parts, function ( $pt ) {
			return post_type_exists( $pt );
		} ) );
		return empty( $parts ) ? $fallback : $parts;
	}
}
