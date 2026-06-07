<?php
/**
 * LuwiPress Redirections — Rank Math Redirections CRUD bridge.
 *
 * Wraps the Rank Math Redirections data layer (`RankMath\Redirections\DB`)
 * so redirects can be created / listed / updated / deleted remotely via REST
 * and WebMCP. Built for post-migration redirect management (DNS swap):
 * bulk-seed 301s for old indexed URLs, then maintain them from the agent
 * surface instead of clicking through the RM admin one row at a time.
 *
 * Design constraints (Tapadum FR-042):
 *  - Use the Rank Math PHP API, never raw SQL on the redirections table — RM
 *    serializes `sources`, stamps timestamps, and maintains a separate cache
 *    table; bypassing DB::* would desync all three.
 *  - Rank Math + its Redirections module must be active. Every route degrades
 *    gracefully (503 with a clear message) when it isn't, so the tool is safe
 *    to expose on non-RM sites.
 *  - Mutations are auth-gated (token or admin) — the "operator-approval class"
 *    requirement is satisfied by the standard LuwiPress permission gate.
 *
 * @package LuwiPress
 * @since   3.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Redirections {

	/**
	 * Singleton instance.
	 *
	 * @var LuwiPress_Redirections|null
	 */
	private static $instance = null;

	/**
	 * Allowed redirect header codes (Rank Math supported set).
	 *
	 * @var int[]
	 */
	const HEADER_CODES = array( 301, 302, 307, 410, 451 );

	/**
	 * Allowed source comparison operators (Rank Math set).
	 *
	 * @var string[]
	 */
	const COMPARISONS = array( 'exact', 'contains', 'start', 'end', 'regex' );

	/**
	 * Get the singleton instance.
	 *
	 * @return LuwiPress_Redirections
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register REST routes.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/**
	 * Permission gate — token or admin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_permission( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	/**
	 * Register REST endpoints under luwipress/v1/redirections.
	 */
	public function register_endpoints() {
		register_rest_route( 'luwipress/v1', '/redirections', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'search'   => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'status'   => array( 'type' => 'string', 'required' => false, 'default' => 'all', 'sanitize_callback' => 'sanitize_text_field' ),
					'per_page' => array( 'type' => 'integer', 'required' => false, 'default' => 50 ),
					'paged'    => array( 'type' => 'integer', 'required' => false, 'default' => 1 ),
					'orderby'  => array( 'type' => 'string', 'required' => false, 'default' => 'id', 'sanitize_callback' => 'sanitize_key' ),
					'order'    => array( 'type' => 'string', 'required' => false, 'default' => 'DESC', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_create' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/redirections/diag', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_diag' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'luwipress/v1', '/redirections/batch', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_batch' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'rows' => array(
					'type'        => 'array',
					'required'    => true,
					'description' => 'Array of { sources, url_to, header_code?, status? } redirect specs (max 200).',
				),
			),
		) );

		register_rest_route( 'luwipress/v1', '/redirections/update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_update' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/redirections/delete', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_delete' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'ids' => array( 'type' => 'array', 'required' => true, 'description' => 'Array of redirection IDs to delete.' ),
			),
		) );

		// Single-record fetch — registered last so the literal sub-routes above
		// (diag / batch / update / delete) win the route match.
		register_rest_route( 'luwipress/v1', '/redirections/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_one' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
	}

	// ------------------------------------------------------------------
	// Availability
	// ------------------------------------------------------------------

	/**
	 * Is the Rank Math Redirections data layer available?
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( '\\RankMath\\Redirections\\DB' );
	}

	/**
	 * WP_Error returned when Rank Math redirections is unavailable.
	 *
	 * @return WP_Error
	 */
	private function unavailable_error() {
		$rank_math = class_exists( '\\RankMath' );
		$msg = $rank_math
			? 'Rank Math is active but its Redirections module is not enabled. Enable Rank Math → Dashboard → Redirections, then retry.'
			: 'Rank Math is not active. The redirections bridge requires Rank Math (Free or Pro) with the Redirections module enabled.';
		return new WP_Error( 'rank_math_redirections_unavailable', $msg, array( 'status' => 503 ) );
	}

	// ------------------------------------------------------------------
	// Handlers
	// ------------------------------------------------------------------

	/**
	 * GET /redirections/diag — availability + counts for pre/post-swap checks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_diag( $request ) {
		$available = self::is_available();
		$counts    = array();
		if ( $available ) {
			foreach ( array( 'all', 'active', 'inactive' ) as $status ) {
				$res            = $this->fetch( array( 'status' => $status, 'limit' => 1, 'paged' => 1 ) );
				$counts[ $status ] = is_wp_error( $res ) ? null : (int) $res['count'];
			}
		}
		return rest_ensure_response( array(
			'available'         => $available,
			'rank_math_active'  => class_exists( '\\RankMath' ),
			'rank_math_version' => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null,
			'db_class'          => class_exists( '\\RankMath\\Redirections\\DB' ),
			'cache_class'       => class_exists( '\\RankMath\\Redirections\\Cache' ),
			'counts'            => $counts,
			'header_codes'      => self::HEADER_CODES,
			'comparisons'       => self::COMPARISONS,
		) );
	}

	/**
	 * GET /redirections — paginated list with optional search + status filter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_list( $request ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}

		$per_page = max( 1, min( 200, (int) $request->get_param( 'per_page' ) ) );
		$paged    = max( 1, (int) $request->get_param( 'paged' ) );
		$status   = $request->get_param( 'status' );
		$status   = in_array( $status, array( 'all', 'active', 'inactive' ), true ) ? $status : 'all';
		$order    = strtoupper( (string) $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$orderby  = $request->get_param( 'orderby' );
		$orderby  = in_array( $orderby, array( 'id', 'url_to', 'header_code', 'hits', 'last_accessed', 'created', 'updated' ), true ) ? $orderby : 'id';

		$args = array(
			'limit'   => $per_page,
			'paged'   => $paged,
			'status'  => $status,
			'orderby' => $orderby,
			'order'   => $order,
		);
		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$res = $this->fetch( $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$rows = array();
		foreach ( $res['redirections'] as $row ) {
			$rows[] = $this->normalize_record( $row );
		}

		return rest_ensure_response( array(
			'redirections' => $rows,
			'count'        => (int) $res['count'],
			'per_page'     => $per_page,
			'paged'        => $paged,
		) );
	}

	/**
	 * GET /redirections/{id} — single record.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_one( $request ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}
		$id  = (int) $request->get_param( 'id' );
		$row = \RankMath\Redirections\DB::get_redirection_by_id( $id, 'all' );
		if ( empty( $row ) ) {
			return new WP_Error( 'not_found', 'Redirection not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->normalize_record( $row ) );
	}

	/**
	 * POST /redirections — create a single redirect.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_create( $request ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}
		$data = $this->body( $request );
		$spec = $this->build_spec( $data );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}
		$id = $this->insert( $spec );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$this->purge_cache();
		LuwiPress_Logger::log( 'Redirection created (id ' . $id . ' → ' . $spec['url_to'] . ')', 'info' );
		$row = \RankMath\Redirections\DB::get_redirection_by_id( $id, 'all' );
		return rest_ensure_response( array(
			'success'     => true,
			'id'          => $id,
			'redirection' => $row ? $this->normalize_record( $row ) : null,
		) );
	}

	/**
	 * POST /redirections/batch — bulk create. The DNS-swap workhorse.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_batch( $request ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}
		$data = $this->body( $request );
		$rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
		if ( empty( $rows ) ) {
			return new WP_Error( 'no_rows', 'No redirect rows supplied.', array( 'status' => 400 ) );
		}
		if ( count( $rows ) > 200 ) {
			return new WP_Error( 'too_many', 'Batch capped at 200 rows per call.', array( 'status' => 400 ) );
		}

		$created = array();
		$errors  = array();
		foreach ( $rows as $i => $raw ) {
			$spec = $this->build_spec( is_array( $raw ) ? $raw : array() );
			if ( is_wp_error( $spec ) ) {
				$errors[] = array( 'index' => $i, 'error' => $spec->get_error_message() );
				continue;
			}
			$id = $this->insert( $spec );
			if ( is_wp_error( $id ) ) {
				$errors[] = array( 'index' => $i, 'error' => $id->get_error_message() );
				continue;
			}
			$created[] = array( 'index' => $i, 'id' => $id, 'url_to' => $spec['url_to'] );
		}
		$this->purge_cache();
		LuwiPress_Logger::log( 'Redirection batch: ' . count( $created ) . ' created, ' . count( $errors ) . ' failed', 'info' );

		return rest_ensure_response( array(
			'success'       => empty( $errors ),
			'created_count' => count( $created ),
			'failed_count'  => count( $errors ),
			'created'       => $created,
			'errors'        => $errors,
		) );
	}

	/**
	 * POST /redirections/update — update a redirect by id (partial).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_update( $request ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}
		$data = $this->body( $request );
		$id   = (int) ( $data['id'] ?? 0 );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', 'A positive redirection id is required.', array( 'status' => 400 ) );
		}
		$existing = \RankMath\Redirections\DB::get_redirection_by_id( $id, 'all' );
		if ( empty( $existing ) ) {
			return new WP_Error( 'not_found', 'Redirection not found.', array( 'status' => 404 ) );
		}
		$existing = (array) $existing;

		// Start from existing values; overlay only provided fields (partial update).
		$spec = array(
			'id'          => $id,
			'sources'     => maybe_unserialize( $existing['sources'] ?? array() ),
			'url_to'      => (string) ( $existing['url_to'] ?? '' ),
			'header_code' => (string) ( $existing['header_code'] ?? '301' ),
			'status'      => (string) ( $existing['status'] ?? 'active' ),
		);

		if ( array_key_exists( 'sources', $data ) ) {
			$sources = $this->build_sources( $data['sources'] );
			if ( is_wp_error( $sources ) ) {
				return $sources;
			}
			$spec['sources'] = $sources;
		}
		if ( array_key_exists( 'url_to', $data ) ) {
			$spec['url_to'] = $this->sanitize_target( $data['url_to'] );
		}
		if ( array_key_exists( 'header_code', $data ) ) {
			$spec['header_code'] = (string) $this->sanitize_header_code( $data['header_code'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$spec['status'] = in_array( $data['status'], array( 'active', 'inactive' ), true ) ? $data['status'] : $spec['status'];
		}

		if ( ! is_array( $spec['sources'] ) || empty( $spec['sources'] ) ) {
			return new WP_Error( 'no_sources', 'A redirection needs at least one source pattern.', array( 'status' => 400 ) );
		}
		if ( '410' !== $spec['header_code'] && '451' !== $spec['header_code'] && '' === $spec['url_to'] ) {
			return new WP_Error( 'no_target', 'url_to is required unless header_code is 410 or 451.', array( 'status' => 400 ) );
		}

		$result = \RankMath\Redirections\DB::update_iff( $spec );
		if ( false === $result ) {
			return new WP_Error( 'update_failed', 'Rank Math rejected the update.', array( 'status' => 500 ) );
		}
		$this->purge_cache();
		LuwiPress_Logger::log( 'Redirection updated (id ' . $id . ')', 'info' );
		$row = \RankMath\Redirections\DB::get_redirection_by_id( $id, 'all' );
		return rest_ensure_response( array(
			'success'     => true,
			'id'          => $id,
			'redirection' => $row ? $this->normalize_record( $row ) : null,
		) );
	}

	/**
	 * POST /redirections/delete — delete one or more redirects by id.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_delete( $request ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}
		$data = $this->body( $request );
		$ids  = array();
		if ( isset( $data['ids'] ) && is_array( $data['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', $data['ids'] ) ) );
		} elseif ( isset( $data['id'] ) ) {
			$ids = array( (int) $data['id'] );
		}
		$ids = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'no_ids', 'No redirection ids supplied.', array( 'status' => 400 ) );
		}
		$count = \RankMath\Redirections\DB::delete( $ids );
		$this->purge_cache();
		LuwiPress_Logger::log( 'Redirections deleted: ' . implode( ',', $ids ), 'info' );
		return rest_ensure_response( array(
			'success'       => true,
			'deleted_count' => (int) $count,
			'ids'           => $ids,
		) );
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Read the JSON (or form) body of a request.
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

	/**
	 * Fetch via Rank Math DB layer, normalizing the return shape.
	 *
	 * @param array $args DB::get_redirections args.
	 * @return array|WP_Error { redirections, count }
	 */
	private function fetch( $args ) {
		$res = \RankMath\Redirections\DB::get_redirections( $args );
		if ( ! is_array( $res ) ) {
			return new WP_Error( 'rm_query_failed', 'Rank Math returned an unexpected response.', array( 'status' => 500 ) );
		}
		return array(
			'redirections' => isset( $res['redirections'] ) && is_array( $res['redirections'] ) ? $res['redirections'] : array(),
			'count'        => isset( $res['count'] ) ? (int) $res['count'] : 0,
		);
	}

	/**
	 * Insert a single normalized spec via the RM data layer.
	 *
	 * @param array $spec { sources(array), url_to, header_code, status }.
	 * @return int|WP_Error new id
	 */
	private function insert( $spec ) {
		$id = \RankMath\Redirections\DB::add( array(
			'sources'     => $spec['sources'],
			'url_to'      => $spec['url_to'],
			'header_code' => $spec['header_code'],
			'status'      => $spec['status'],
		) );
		if ( ! $id ) {
			return new WP_Error( 'create_failed', 'Rank Math rejected the redirect (possible duplicate source or invalid pattern).', array( 'status' => 409 ) );
		}
		return (int) $id;
	}

	/**
	 * Validate + normalize an incoming create spec.
	 *
	 * @param array $data Raw input.
	 * @return array|WP_Error { sources, url_to, header_code, status }
	 */
	private function build_spec( $data ) {
		$sources = $this->build_sources( isset( $data['sources'] ) ? $data['sources'] : ( $data['source'] ?? '' ) );
		if ( is_wp_error( $sources ) ) {
			return $sources;
		}
		if ( empty( $sources ) ) {
			return new WP_Error( 'no_sources', 'At least one source pattern is required.', array( 'status' => 400 ) );
		}
		$header_code = (string) $this->sanitize_header_code( $data['header_code'] ?? 301 );
		$url_to      = $this->sanitize_target( $data['url_to'] ?? ( $data['target'] ?? '' ) );
		// 410 Gone / 451 Unavailable for legal reasons don't need a target.
		if ( '410' !== $header_code && '451' !== $header_code && '' === $url_to ) {
			return new WP_Error( 'no_target', 'url_to is required unless header_code is 410 or 451.', array( 'status' => 400 ) );
		}
		$status = ( isset( $data['status'] ) && in_array( $data['status'], array( 'active', 'inactive' ), true ) ) ? $data['status'] : 'active';

		return array(
			'sources'     => $sources,
			'url_to'      => $url_to,
			'header_code' => $header_code,
			'status'      => $status,
		);
	}

	/**
	 * Normalize incoming "sources" into Rank Math's expected array shape.
	 *
	 * Accepts:
	 *  - a plain string ............... "old-page"            (comparison: exact)
	 *  - a list of strings ............ ["a", "b"]            (each: exact)
	 *  - a list of {pattern, comparison} objects
	 *  - a single {pattern, comparison} object
	 *
	 * @param mixed $input Sources input.
	 * @return array|WP_Error [ [pattern, comparison, ignore], ... ]
	 */
	private function build_sources( $input ) {
		$out = array();

		$add = function ( $pattern, $comparison ) use ( &$out ) {
			$pattern = trim( wp_strip_all_tags( (string) $pattern ) );
			if ( '' === $pattern ) {
				return;
			}
			$comparison = in_array( $comparison, self::COMPARISONS, true ) ? $comparison : 'exact';
			$out[]      = array(
				'pattern'    => $pattern,
				'comparison' => $comparison,
				'ignore'     => '',
			);
		};

		if ( is_string( $input ) ) {
			$add( $input, 'exact' );
		} elseif ( is_array( $input ) ) {
			// Single associative {pattern, comparison} object.
			if ( isset( $input['pattern'] ) ) {
				$add( $input['pattern'], $input['comparison'] ?? 'exact' );
			} else {
				foreach ( $input as $item ) {
					if ( is_string( $item ) ) {
						$add( $item, 'exact' );
					} elseif ( is_array( $item ) && isset( $item['pattern'] ) ) {
						$add( $item['pattern'], $item['comparison'] ?? 'exact' );
					}
				}
			}
		}

		if ( empty( $out ) ) {
			return new WP_Error( 'invalid_sources', 'Could not parse any source pattern from input.', array( 'status' => 400 ) );
		}
		return $out;
	}

	/**
	 * Sanitize a redirect target. Preserves relative paths and absolute URLs.
	 *
	 * @param mixed $value Target.
	 * @return string
	 */
	private function sanitize_target( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		return esc_url_raw( $value );
	}

	/**
	 * Clamp header code to the Rank Math supported set.
	 *
	 * @param mixed $value Code.
	 * @return int
	 */
	private function sanitize_header_code( $value ) {
		$code = (int) $value;
		return in_array( $code, self::HEADER_CODES, true ) ? $code : 301;
	}

	/**
	 * Convert a raw RM redirection row into a clean response shape.
	 *
	 * @param mixed $row RM row (array or object).
	 * @return array
	 */
	private function normalize_record( $row ) {
		$row     = (array) $row;
		$sources = maybe_unserialize( $row['sources'] ?? array() );
		$clean   = array();
		if ( is_array( $sources ) ) {
			foreach ( $sources as $s ) {
				if ( is_array( $s ) && isset( $s['pattern'] ) ) {
					$clean[] = array(
						'pattern'    => (string) $s['pattern'],
						'comparison' => (string) ( $s['comparison'] ?? 'exact' ),
					);
				}
			}
		}
		return array(
			'id'            => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'sources'       => $clean,
			'url_to'        => (string) ( $row['url_to'] ?? '' ),
			'header_code'   => (string) ( $row['header_code'] ?? '301' ),
			'status'        => (string) ( $row['status'] ?? 'active' ),
			'hits'          => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
			'last_accessed' => $row['last_accessed'] ?? null,
			'created'       => $row['created'] ?? null,
			'updated'       => $row['updated'] ?? null,
		);
	}

	/**
	 * Purge the Rank Math redirection cache table after a mutation so stale
	 * source→target mappings don't keep serving the old behaviour.
	 */
	private function purge_cache() {
		if ( class_exists( '\\RankMath\\Redirections\\Cache' ) ) {
			\RankMath\Redirections\Cache::purge();
		}
	}
}
