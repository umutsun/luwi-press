<?php
/**
 * LuwiPress Schema Registry
 *
 * Generic, extensible registry for JSON-LD schema.org types attached to
 * posts or terms. Replaces the hard-coded FAQ/HowTo/Speakable branches in
 * LuwiPress_AEO::output_aeo_schema() with a single context-aware emitter
 * that any module (or third-party plugin) can extend via the
 * `luwipress_schema_registry_init` action.
 *
 * Built-in registrations (3.4.0):
 *   - faq             → FAQPage              (post:product, term:product_cat)
 *   - howto           → HowTo                (post:product) — deprecated for Google rich result, still emitted for AI search
 *   - speakable       → SpeakableSpecification (post:*) — deprecated beta, still emitted for opt-ins
 *   - localbusiness   → LocalBusiness        (post:* — typical: contact / about page)
 *   - service         → Service              (post:*)
 *   - course          → Course               (post:*)
 *   - review          → Review               (post:product)
 *   - aggregaterating → AggregateRating      (post:product)
 *   - itemlist        → ItemList             (term:product_cat — auto-generated, no save path)
 *
 * Adding a new schema type — anywhere in the codebase or a third-party plugin:
 *
 *     add_action('luwipress_schema_registry_init', function($registry) {
 *         $registry->register_type('event', [
 *             'schema_type' => 'Event',
 *             'meta_key'    => '_luwipress_schema_event',
 *             'contexts'    => ['post:*'],
 *             'sanitizer'   => function($data) { ... return $sanitized; },
 *             'renderer'    => function($data, $object_id, $object_type) {
 *                 return ['@context' => 'https://schema.org', '@type' => 'Event', ...];
 *             },
 *         ]);
 *     });
 *
 * @package LuwiPress
 * @since 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Schema_Registry {

	private static $instance = null;

	/**
	 * Registered schema types. type slug → config array.
	 */
	private $types = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_builtin_types();

		// Allow modules + third-party plugins to register additional types.
		do_action( 'luwipress_schema_registry_init', $this );

		// Single wp_head emitter — fires at priority 6, just after
		// LuwiPress_AEO::output_aeo_schema() (priority 5) which still handles
		// post-product FAQ/HowTo/Speakable for backward-compat. The registry
		// covers everything ELSE (new types + taxonomy archives), and tracks
		// what it has already emitted to avoid double-output.
		add_action( 'wp_head', array( $this, 'render_for_request' ), 6 );

		// REST endpoints
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
	}

	// ─── TYPE REGISTRATION ─────────────────────────────────────────────

	/**
	 * Register a schema type.
	 *
	 * @param string $type Lowercase slug, e.g. 'faq', 'localbusiness'.
	 * @param array  $config {
	 *   @type string   $schema_type   Required. Schema.org @type value (e.g. 'FAQPage').
	 *   @type string   $meta_key      Required. Post/term meta key for storage.
	 *   @type array    $contexts      Required. Where this schema applies. Each entry is
	 *                                 'post:{post_type}' or 'term:{taxonomy}'. Use '*' as
	 *                                 wildcard, e.g. ['post:*'] or ['post:product', 'term:product_cat'].
	 *   @type callable $sanitizer     Required. function($data, $context_array) returns sanitized data array.
	 *                                 $context_array = ['object_type'=>'post|term', 'object_id'=>int, 'subtype'=>string].
	 *   @type callable $renderer      Required. function($data, $context_array) returns schema.org array
	 *                                 (or null to skip emission). The returned array will be wp_json_encoded.
	 *   @type callable $auto_data     Optional. function($context_array) returns auto-generated data when
	 *                                 no stored meta exists. Used by 'itemlist' for category archives —
	 *                                 generates ItemList from term's products at render time.
	 *   @type bool     $deprecated    Optional. If true, save endpoints accept but mark with deprecation note.
	 *   @type string   $description   Optional. Human description for /aeo/schema-types listing.
	 * }
	 * @return bool True on success, false if config invalid.
	 */
	public function register_type( $type, $config ) {
		$type = sanitize_key( $type );
		if ( empty( $type ) ) {
			return false;
		}

		$required = array( 'schema_type', 'meta_key', 'contexts', 'sanitizer', 'renderer' );
		foreach ( $required as $key ) {
			if ( ! isset( $config[ $key ] ) ) {
				return false;
			}
		}

		$config = wp_parse_args( $config, array(
			'auto_data'   => null,
			'deprecated'  => false,
			'description' => '',
		) );

		$this->types[ $type ] = $config;
		return true;
	}

	public function get_types() {
		return $this->types;
	}

	public function get_type( $type ) {
		$type = sanitize_key( $type );
		return isset( $this->types[ $type ] ) ? $this->types[ $type ] : null;
	}

	/**
	 * Does this type apply in the given context?
	 * Context format: ['object_type'=>'post|term', 'subtype'=>'product|product_cat|...'].
	 */
	public function type_applies( $type_config, $context ) {
		if ( empty( $type_config['contexts'] ) || ! is_array( $type_config['contexts'] ) ) {
			return false;
		}
		$want = $context['object_type'] . ':' . $context['subtype'];
		$want_wild = $context['object_type'] . ':*';
		foreach ( $type_config['contexts'] as $ctx ) {
			if ( $ctx === $want || $ctx === $want_wild || $ctx === '*' ) {
				return true;
			}
		}
		return false;
	}

	// ─── STORAGE ───────────────────────────────────────────────────────

	/**
	 * Save schema data for a post or term.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post ID or Term ID.
	 * @param string $type        Schema slug.
	 * @param array  $data        Raw data (will be sanitized).
	 * @return array|WP_Error Result with 'saved'=>bool, 'meta_key'=>string OR error.
	 */
	public function save_schema( $object_type, $object_id, $type, $data ) {
		$type_config = $this->get_type( $type );
		if ( null === $type_config ) {
			return new WP_Error( 'unknown_schema_type', 'Unknown schema type: ' . $type, array( 'status' => 400 ) );
		}

		$object_id = absint( $object_id );
		$object_type = ( 'term' === $object_type ) ? 'term' : 'post';

		$subtype = $this->get_object_subtype( $object_type, $object_id );
		if ( '' === $subtype ) {
			return new WP_Error( 'invalid_object', 'Object not found.', array( 'status' => 404 ) );
		}

		$context = array(
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'subtype'     => $subtype,
		);

		if ( ! $this->type_applies( $type_config, $context ) ) {
			return new WP_Error(
				'context_mismatch',
				sprintf(
					'Schema type "%s" does not apply to %s:%s. Allowed contexts: %s',
					$type,
					$object_type,
					$subtype,
					implode( ', ', $type_config['contexts'] )
				),
				array( 'status' => 400 )
			);
		}

		// Sanitize via type's sanitizer.
		$sanitized = call_user_func( $type_config['sanitizer'], $data, $context );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}
		if ( empty( $sanitized ) ) {
			return new WP_Error( 'invalid_data', 'Sanitized data is empty.', array( 'status' => 400 ) );
		}

		$meta_key = $type_config['meta_key'];
		if ( 'term' === $object_type ) {
			update_term_meta( $object_id, $meta_key, $sanitized );
			update_term_meta( $object_id, $meta_key . '_updated', current_time( 'c' ) );
		} else {
			update_post_meta( $object_id, $meta_key, $sanitized );
			update_post_meta( $object_id, $meta_key . '_updated', current_time( 'c' ) );
		}

		// Bust coverage cache + plugin-detector cache for affected post.
		delete_transient( 'luwipress_aeo_coverage' );
		if ( 'post' === $object_type && class_exists( 'LuwiPress_Plugin_Detector' ) ) {
			LuwiPress_Plugin_Detector::get_instance()->purge_post_cache( $object_id );
		}

		/**
		 * Fires after a schema is saved.
		 *
		 * @param string $object_type 'post' or 'term'.
		 * @param int    $object_id
		 * @param string $type        Schema slug.
		 * @param array  $sanitized   Sanitized data.
		 */
		do_action( 'luwipress_schema_saved', $object_type, $object_id, $type, $sanitized );

		return array(
			'saved'       => true,
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'type'        => $type,
			'meta_key'    => $meta_key,
			'schema_type' => $type_config['schema_type'],
		);
	}

	/**
	 * Read raw schema data (pre-render).
	 */
	public function get_schema( $object_type, $object_id, $type ) {
		$type_config = $this->get_type( $type );
		if ( null === $type_config ) {
			return null;
		}
		$meta_key = $type_config['meta_key'];
		if ( 'term' === $object_type ) {
			return get_term_meta( $object_id, $meta_key, true );
		}
		return get_post_meta( $object_id, $meta_key, true );
	}

	/**
	 * Delete saved schema.
	 */
	public function delete_schema( $object_type, $object_id, $type ) {
		$type_config = $this->get_type( $type );
		if ( null === $type_config ) {
			return new WP_Error( 'unknown_schema_type', 'Unknown schema type: ' . $type, array( 'status' => 400 ) );
		}
		$meta_key = $type_config['meta_key'];
		if ( 'term' === $object_type ) {
			delete_term_meta( $object_id, $meta_key );
			delete_term_meta( $object_id, $meta_key . '_updated' );
		} else {
			delete_post_meta( $object_id, $meta_key );
			delete_post_meta( $object_id, $meta_key . '_updated' );
		}
		delete_transient( 'luwipress_aeo_coverage' );
		do_action( 'luwipress_schema_deleted', $object_type, $object_id, $type );
		return array( 'deleted' => true, 'object_type' => $object_type, 'object_id' => $object_id, 'type' => $type );
	}

	private function get_object_subtype( $object_type, $object_id ) {
		if ( 'term' === $object_type ) {
			$term = get_term( $object_id );
			if ( ! $term || is_wp_error( $term ) ) {
				return '';
			}
			return $term->taxonomy;
		}
		$post = get_post( $object_id );
		return $post ? $post->post_type : '';
	}

	// ─── RENDER (wp_head) ──────────────────────────────────────────────

	/**
	 * Render all applicable schemas for the current request.
	 *
	 * Backward-compat: LuwiPress_AEO::output_aeo_schema() (priority 5) still
	 * emits FAQ/HowTo/Speakable on singular product pages so existing sites
	 * keep working even if a custom plugin disables this registry. The
	 * registry tracks emitted (object,type) pairs and skips duplicates.
	 */
	public function render_for_request() {
		if ( is_admin() ) {
			return;
		}

		$context = $this->detect_current_context();
		if ( null === $context ) {
			return;
		}

		$already_emitted = $this->get_aeo_legacy_emissions( $context );

		foreach ( $this->types as $type_slug => $type_config ) {
			if ( ! $this->type_applies( $type_config, $context ) ) {
				continue;
			}

			// Skip if AEO's legacy emitter already produced this type.
			if ( in_array( $type_slug, $already_emitted, true ) ) {
				continue;
			}

			$data = $this->get_schema( $context['object_type'], $context['object_id'], $type_slug );

			// If no stored data, try auto-generator (e.g. ItemList from category products).
			if ( empty( $data ) && is_callable( $type_config['auto_data'] ) ) {
				$data = call_user_func( $type_config['auto_data'], $context );
			}

			if ( empty( $data ) ) {
				continue;
			}

			$schema_array = call_user_func( $type_config['renderer'], $data, $context );
			if ( empty( $schema_array ) || ! is_array( $schema_array ) ) {
				continue;
			}

			echo "<!-- LuwiPress schema: " . esc_html( $type_slug ) . " -->\n";
			echo '<script type="application/ld+json">'
				. wp_json_encode( $schema_array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				. "</script>\n";
		}
	}

	/**
	 * Detect what we're rendering for: post (singular) or term (archive).
	 * Returns null if neither (search pages, 404, etc — don't emit schema).
	 */
	private function detect_current_context() {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof WP_Post ) {
				return null;
			}
			return array(
				'object_type' => 'post',
				'object_id'   => $post->ID,
				'subtype'     => $post->post_type,
			);
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return null;
			}
			return array(
				'object_type' => 'term',
				'object_id'   => $term->term_id,
				'subtype'     => $term->taxonomy,
			);
		}

		return null;
	}

	/**
	 * Which schema types has the legacy AEO emitter (priority 5) already
	 * output on this request? Mirrors the conditions in
	 * LuwiPress_AEO::output_aeo_schema() so we never double-emit.
	 *
	 * The legacy emitter only runs on `is_singular('product')`, so any
	 * non-product post or any term context returns an empty list.
	 */
	private function get_aeo_legacy_emissions( $context ) {
		if ( 'post' !== $context['object_type'] || 'product' !== $context['subtype'] ) {
			return array();
		}
		$emitted = array();
		if ( ! empty( get_post_meta( $context['object_id'], '_luwipress_faq', true ) ) ) {
			$emitted[] = 'faq';
		}
		if ( ! empty( get_post_meta( $context['object_id'], '_luwipress_howto', true ) ) ) {
			$emitted[] = 'howto';
		}
		if ( ! empty( get_post_meta( $context['object_id'], '_luwipress_speakable', true ) ) ) {
			$emitted[] = 'speakable';
		}
		return $emitted;
	}

	// ─── REST API ──────────────────────────────────────────────────────

	public function register_rest_endpoints() {
		$ns = 'luwipress/v1';

		// Generic save endpoint: POST /aeo/save-schema
		register_rest_route( $ns, '/aeo/save-schema', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_save_schema' ),
			'permission_callback' => array( $this, 'permission_token' ),
		) );

		// Generic get endpoint: GET /aeo/get-schema
		register_rest_route( $ns, '/aeo/get-schema', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_get_schema' ),
			'permission_callback' => array( $this, 'permission_token' ),
		) );

		// Generic delete endpoint: DELETE /aeo/delete-schema
		register_rest_route( $ns, '/aeo/delete-schema', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'rest_delete_schema' ),
			'permission_callback' => array( $this, 'permission_token' ),
		) );

		// List registered types: GET /aeo/schema-types
		register_rest_route( $ns, '/aeo/schema-types', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_list_types' ),
			'permission_callback' => array( $this, 'permission_token' ),
		) );

		// Diagnostic — fetch URL + extract JSON-LD blocks: POST /aeo/schema-render
		register_rest_route( $ns, '/aeo/schema-render', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_diagnostic_render' ),
			'permission_callback' => array( $this, 'permission_token' ),
		) );
	}

	public function permission_token( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	public function rest_save_schema( $request ) {
		$body         = $request->get_json_params() ?: array();
		$object_type  = sanitize_key( $request->get_param( 'object_type' ) ?: ( $body['object_type'] ?? 'post' ) );
		$object_id    = absint( $request->get_param( 'object_id' ) ?: ( $body['object_id'] ?? 0 ) );
		$schema_type  = sanitize_key( $request->get_param( 'schema_type' ) ?: ( $body['schema_type'] ?? '' ) );
		$data         = $request->get_param( 'data' ) ?: ( $body['data'] ?? null );

		// Convenience: callers may pass `term_id`/`taxonomy` or `post_id` directly.
		if ( ! $object_id ) {
			$object_id = absint( $request->get_param( 'term_id' ) ?: ( $body['term_id'] ?? 0 ) );
			if ( $object_id ) {
				$object_type = 'term';
			}
		}
		if ( ! $object_id ) {
			$object_id = absint( $request->get_param( 'post_id' ) ?: ( $body['post_id'] ?? 0 ) );
		}

		// JSON string fallback (some clients pass `schema_json` as a serialized string).
		if ( null === $data ) {
			$json_str = $request->get_param( 'schema_json' ) ?: ( $body['schema_json'] ?? null );
			if ( is_string( $json_str ) && '' !== $json_str ) {
				$decoded = json_decode( $json_str, true );
				if ( is_array( $decoded ) ) {
					$data = $decoded;
				}
			}
		}

		if ( ! $object_id || ! $schema_type || ! is_array( $data ) ) {
			return new WP_Error( 'missing_params', 'object_id, schema_type, and data (array) are required.', array( 'status' => 400 ) );
		}

		$result = $this->save_schema( $object_type, $object_id, $schema_type, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function rest_get_schema( $request ) {
		$object_type = sanitize_key( $request->get_param( 'object_type' ) ?: 'post' );
		$object_id   = absint( $request->get_param( 'object_id' ) ?: 0 );
		$schema_type = sanitize_key( $request->get_param( 'schema_type' ) ?: '' );

		if ( ! $object_id ) {
			$object_id = absint( $request->get_param( 'term_id' ) ?: 0 );
			if ( $object_id ) {
				$object_type = 'term';
			}
		}
		if ( ! $object_id ) {
			$object_id = absint( $request->get_param( 'post_id' ) ?: 0 );
		}

		if ( ! $object_id ) {
			return new WP_Error( 'missing_id', 'object_id required.', array( 'status' => 400 ) );
		}

		// If schema_type provided, return that one. Otherwise return all stored schemas for this object.
		if ( $schema_type ) {
			$data = $this->get_schema( $object_type, $object_id, $schema_type );
			return rest_ensure_response( array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'schema_type' => $schema_type,
				'data'        => $data,
			) );
		}

		$all = array();
		foreach ( $this->types as $slug => $cfg ) {
			$data = $this->get_schema( $object_type, $object_id, $slug );
			if ( ! empty( $data ) ) {
				$all[ $slug ] = $data;
			}
		}
		return rest_ensure_response( array(
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'schemas'     => $all,
		) );
	}

	public function rest_delete_schema( $request ) {
		$object_type = sanitize_key( $request->get_param( 'object_type' ) ?: 'post' );
		$object_id   = absint( $request->get_param( 'object_id' ) ?: 0 );
		$schema_type = sanitize_key( $request->get_param( 'schema_type' ) ?: '' );

		if ( ! $object_id ) {
			$object_id = absint( $request->get_param( 'term_id' ) ?: 0 );
			if ( $object_id ) {
				$object_type = 'term';
			}
		}
		if ( ! $object_id ) {
			$object_id = absint( $request->get_param( 'post_id' ) ?: 0 );
		}

		if ( ! $object_id || ! $schema_type ) {
			return new WP_Error( 'missing_params', 'object_id and schema_type required.', array( 'status' => 400 ) );
		}

		$result = $this->delete_schema( $object_type, $object_id, $schema_type );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function rest_list_types( $request ) {
		$out = array();
		foreach ( $this->types as $slug => $cfg ) {
			$out[] = array(
				'slug'        => $slug,
				'schema_type' => $cfg['schema_type'],
				'meta_key'    => $cfg['meta_key'],
				'contexts'    => $cfg['contexts'],
				'deprecated'  => ! empty( $cfg['deprecated'] ),
				'description' => $cfg['description'],
			);
		}
		return rest_ensure_response( array( 'types' => $out, 'count' => count( $out ) ) );
	}

	/**
	 * Diagnostic: fetch a URL and extract all <script type="application/ld+json"> blocks.
	 * Saves the operator the round-trip through chrome-devtools-mcp for schema audits.
	 */
	public function rest_diagnostic_render( $request ) {
		$body = $request->get_json_params() ?: array();
		$url  = esc_url_raw( $request->get_param( 'url' ) ?: ( $body['url'] ?? '' ) );
		$post_id = absint( $request->get_param( 'post_id' ) ?: ( $body['post_id'] ?? 0 ) );
		$term_id = absint( $request->get_param( 'term_id' ) ?: ( $body['term_id'] ?? 0 ) );
		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) ?: ( $body['taxonomy'] ?? '' ) );

		if ( ! $url && $post_id ) {
			$url = get_permalink( $post_id );
		}
		if ( ! $url && $term_id && $taxonomy ) {
			$link = get_term_link( $term_id, $taxonomy );
			if ( ! is_wp_error( $link ) ) {
				$url = $link;
			}
		}

		if ( ! $url ) {
			return new WP_Error( 'missing_url', 'url, post_id, or term_id+taxonomy required.', array( 'status' => 400 ) );
		}

		// Cache-bypass query param + headers — most cache layers respect either.
		$fetch_url = add_query_arg( '_lwp_cb', time(), $url );
		$response  = wp_remote_get( $fetch_url, array(
			'timeout'     => 15,
			'redirection' => 5,
			'headers'     => array(
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			),
			'user-agent'  => 'LuwiPress-Schema-Render/' . LUWIPRESS_VERSION,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$html   = wp_remote_retrieve_body( $response );

		if ( $status >= 400 ) {
			return new WP_Error( 'http_error', 'Fetch returned HTTP ' . $status, array( 'status' => 502 ) );
		}

		// Extract all JSON-LD blocks. Pattern matches the standard form
		// emitted by Rank Math, Yoast, LuwiPress, and most schema plugins.
		$blocks = array();
		if ( preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
			foreach ( $matches[1] as $idx => $json_str ) {
				$json_str = trim( $json_str );
				if ( '' === $json_str ) {
					continue;
				}
				$decoded = json_decode( $json_str, true );
				$blocks[] = array(
					'index'   => $idx,
					'valid'   => null !== $decoded,
					'parsed'  => $decoded,
					'raw'     => mb_substr( $json_str, 0, 4000 ),
					'byte_size' => strlen( $json_str ),
				);
			}
		}

		// Quick @type summary so the operator can scan visually.
		$summary = array();
		foreach ( $blocks as $b ) {
			if ( ! $b['valid'] || ! is_array( $b['parsed'] ) ) {
				continue;
			}
			if ( isset( $b['parsed']['@graph'] ) && is_array( $b['parsed']['@graph'] ) ) {
				foreach ( $b['parsed']['@graph'] as $node ) {
					if ( ! empty( $node['@type'] ) ) {
						$t = is_array( $node['@type'] ) ? implode( '|', $node['@type'] ) : $node['@type'];
						$summary[] = $t;
					}
				}
			} elseif ( ! empty( $b['parsed']['@type'] ) ) {
				$t = is_array( $b['parsed']['@type'] ) ? implode( '|', $b['parsed']['@type'] ) : $b['parsed']['@type'];
				$summary[] = $t;
			}
		}

		return rest_ensure_response( array(
			'url'           => $url,
			'http_status'   => $status,
			'block_count'   => count( $blocks ),
			'schema_types'  => array_values( array_unique( $summary ) ),
			'blocks'        => $blocks,
		) );
	}

	// ─── BUILT-IN TYPE REGISTRATIONS ───────────────────────────────────

	private function register_builtin_types() {
		// FAQ — applies to products AND product_cat terms (FR-013).
		$this->register_type( 'faq', array(
			'schema_type' => 'FAQPage',
			'meta_key'    => '_luwipress_faq',
			'contexts'    => array( 'post:product', 'term:product_cat' ),
			'description' => 'FAQPage — array of {question, answer} pairs. Works on products and product categories.',
			'sanitizer'   => array( $this, 'sanitize_faq' ),
			'renderer'    => array( $this, 'render_faq' ),
		) );

		// HowTo — Google deprecated December 2023 but still emitted for AI search citability.
		$this->register_type( 'howto', array(
			'schema_type' => 'HowTo',
			'meta_key'    => '_luwipress_howto',
			'contexts'    => array( 'post:product' ),
			'deprecated'  => true,
			'description' => 'HowTo — Google deprecated for rich result (Dec 2023); still emitted for AI search.',
			'sanitizer'   => array( $this, 'sanitize_howto' ),
			'renderer'    => array( $this, 'render_howto' ),
		) );

		// Speakable — beta + abandoned for news, still emitted for opt-in.
		$this->register_type( 'speakable', array(
			'schema_type' => 'SpeakableSpecification',
			'meta_key'    => '_luwipress_speakable',
			'contexts'    => array( 'post:*' ),
			'deprecated'  => true,
			'description' => 'Speakable — beta, narrow news-publisher use case.',
			'sanitizer'   => array( $this, 'sanitize_speakable' ),
			'renderer'    => array( $this, 'render_speakable' ),
		) );

		// LocalBusiness — Brisighella showroom + Izmir workshops (FR-1).
		$this->register_type( 'localbusiness', array(
			'schema_type' => 'LocalBusiness',
			'meta_key'    => '_luwipress_schema_localbusiness',
			'contexts'    => array( 'post:*' ),
			'description' => 'LocalBusiness — physical premises with hours, address, geo, phone.',
			'sanitizer'   => array( $this, 'sanitize_passthrough_schema' ),
			'renderer'    => array( $this, 'render_passthrough_schema' ),
		) );

		// Service — workshop / teaching service offerings.
		$this->register_type( 'service', array(
			'schema_type' => 'Service',
			'meta_key'    => '_luwipress_schema_service',
			'contexts'    => array( 'post:*' ),
			'description' => 'Service — workshop, teaching, repair, custom orders.',
			'sanitizer'   => array( $this, 'sanitize_passthrough_schema' ),
			'renderer'    => array( $this, 'render_passthrough_schema' ),
		) );

		// Course — online or hybrid academy courses.
		$this->register_type( 'course', array(
			'schema_type' => 'Course',
			'meta_key'    => '_luwipress_schema_course',
			'contexts'    => array( 'post:*' ),
			'description' => 'Course (or OnlineCourse via @type override) — academy classes and curricula.',
			'sanitizer'   => array( $this, 'sanitize_passthrough_schema' ),
			'renderer'    => array( $this, 'render_passthrough_schema' ),
		) );

		// Event — concerts, workshops, classes the store organizes (FR-024).
		// Writable on any post (default target: blog post). WPML-aware the same
		// way FAQ is: each language sibling carries its own _luwipress_schema_event
		// meta, so a 4-language event writes one schema per translation post_id.
		$this->register_type( 'event', array(
			'schema_type' => 'Event',
			'meta_key'    => '_luwipress_schema_event',
			'contexts'    => array( 'post:*' ),
			'description' => 'Event — concert/workshop/class. Fields: name, startDate, endDate?, eventStatus?, eventAttendanceMode?, location {name,address|url}, organizer?, description?, offers? {price,priceCurrency,url,availability?}, image?, performer?. WPML-aware (write per language sibling).',
			'sanitizer'   => array( $this, 'sanitize_event' ),
			'renderer'    => array( $this, 'render_event' ),
		) );

		// Review — product review schema.
		$this->register_type( 'review', array(
			'schema_type' => 'Review',
			'meta_key'    => '_luwipress_schema_review',
			'contexts'    => array( 'post:product' ),
			'description' => 'Review — single review with author + rating + body.',
			'sanitizer'   => array( $this, 'sanitize_passthrough_schema' ),
			'renderer'    => array( $this, 'render_passthrough_schema' ),
		) );

		// AggregateRating — aggregate rating block.
		$this->register_type( 'aggregaterating', array(
			'schema_type' => 'AggregateRating',
			'meta_key'    => '_luwipress_schema_aggregaterating',
			'contexts'    => array( 'post:product' ),
			'description' => 'AggregateRating — ratingValue + reviewCount + bestRating.',
			'sanitizer'   => array( $this, 'sanitize_passthrough_schema' ),
			'renderer'    => array( $this, 'render_passthrough_schema' ),
		) );

		// ItemList — auto-generated for product_cat archives (FR-2).
		// No save path; ItemList is regenerated each request from the term's products.
		$this->register_type( 'itemlist', array(
			'schema_type' => 'ItemList',
			'meta_key'    => '_luwipress_schema_itemlist', // unused but kept for symmetry
			'contexts'    => array( 'term:product_cat' ),
			'description' => 'ItemList — auto-generated from category products at render time.',
			'sanitizer'   => array( $this, 'sanitize_passthrough_schema' ),
			'renderer'    => array( $this, 'render_passthrough_schema' ),
			'auto_data'   => array( $this, 'auto_data_itemlist' ),
		) );
	}

	// ─── BUILT-IN SANITIZERS ───────────────────────────────────────────

	public function sanitize_faq( $data, $context ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', 'FAQ data must be an array.', array( 'status' => 400 ) );
		}
		// Support nested {faqs:[...]} too.
		if ( isset( $data['faqs'] ) && is_array( $data['faqs'] ) ) {
			$data = $data['faqs'];
		}
		$sanitized = array();
		foreach ( $data as $item ) {
			if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
				$sanitized[] = array(
					'question' => sanitize_text_field( $item['question'] ),
					'answer'   => wp_kses_post( $item['answer'] ),
				);
			}
		}
		return $sanitized;
	}

	public function render_faq( $data, $context ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return null;
		}
		$entities = array();
		foreach ( $data as $item ) {
			if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $item['question'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $item['answer'],
					),
				);
			}
		}
		if ( empty( $entities ) ) {
			return null;
		}
		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
	}

	// ─── EVENT (FR-024) ────────────────────────────────────────────────

	/**
	 * Sanitize an Event payload into a normalized, storable shape. Accepts a
	 * friendly flat object and tolerates partial input — only `name` and
	 * `startDate` are required; everything else is optional and dropped when
	 * absent so we never emit empty schema.org keys.
	 *
	 * Accepted keys: name, startDate, endDate, description, image,
	 * eventStatus, eventAttendanceMode, organizer (string|{name,url}),
	 * performer (string|{name}), location ({name,address,url}), offers
	 * ({price,priceCurrency,url,availability,validFrom}).
	 *
	 * @param mixed $data
	 * @param array $context
	 * @return array|WP_Error
	 */
	public function sanitize_event( $data, $context ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', 'Event data must be an object.', array( 'status' => 400 ) );
		}
		$name  = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		$start = isset( $data['startDate'] ) ? sanitize_text_field( (string) $data['startDate'] ) : '';
		if ( '' === $name || '' === $start ) {
			return new WP_Error( 'invalid_data', 'Event requires at least name and startDate (ISO 8601).', array( 'status' => 400 ) );
		}

		$out = array(
			'name'      => $name,
			'startDate' => $start,
		);

		if ( ! empty( $data['endDate'] ) ) {
			$out['endDate'] = sanitize_text_field( (string) $data['endDate'] );
		}
		if ( ! empty( $data['description'] ) ) {
			$out['description'] = wp_kses_post( (string) $data['description'] );
		}
		if ( ! empty( $data['image'] ) ) {
			$out['image'] = esc_url_raw( (string) $data['image'] );
		}

		// eventStatus — accept short form (Scheduled) or full URL; normalize to a schema.org enum URL.
		if ( ! empty( $data['eventStatus'] ) ) {
			$out['eventStatus'] = $this->normalize_event_enum(
				(string) $data['eventStatus'],
				array( 'EventScheduled', 'EventCancelled', 'EventMovedOnline', 'EventPostponed', 'EventRescheduled' ),
				'EventScheduled'
			);
		}
		// eventAttendanceMode — Offline / Online / MixedEventAttendanceMode.
		if ( ! empty( $data['eventAttendanceMode'] ) ) {
			$out['eventAttendanceMode'] = $this->normalize_event_enum(
				(string) $data['eventAttendanceMode'],
				array( 'OfflineEventAttendanceMode', 'OnlineEventAttendanceMode', 'MixedEventAttendanceMode' ),
				'OfflineEventAttendanceMode'
			);
		}

		// organizer / performer — accept string or {name,url}.
		foreach ( array( 'organizer', 'performer' ) as $agent_key ) {
			if ( empty( $data[ $agent_key ] ) ) {
				continue;
			}
			$agent = $data[ $agent_key ];
			if ( is_string( $agent ) ) {
				$out[ $agent_key ] = sanitize_text_field( $agent );
			} elseif ( is_array( $agent ) && ! empty( $agent['name'] ) ) {
				$node = array( 'name' => sanitize_text_field( (string) $agent['name'] ) );
				if ( ! empty( $agent['url'] ) ) {
					$node['url'] = esc_url_raw( (string) $agent['url'] );
				}
				$out[ $agent_key ] = $node;
			}
		}

		// location — physical ({name,address}) or virtual ({url}); both allowed.
		if ( ! empty( $data['location'] ) && is_array( $data['location'] ) ) {
			$loc = array();
			if ( ! empty( $data['location']['name'] ) ) {
				$loc['name'] = sanitize_text_field( (string) $data['location']['name'] );
			}
			if ( ! empty( $data['location']['address'] ) ) {
				$loc['address'] = sanitize_text_field( (string) $data['location']['address'] );
			}
			if ( ! empty( $data['location']['url'] ) ) {
				$loc['url'] = esc_url_raw( (string) $data['location']['url'] );
			}
			if ( ! empty( $loc ) ) {
				$out['location'] = $loc;
			}
		}

		// offers — optional ticketing block.
		if ( ! empty( $data['offers'] ) && is_array( $data['offers'] ) ) {
			$offers = array();
			if ( isset( $data['offers']['price'] ) && '' !== (string) $data['offers']['price'] ) {
				$offers['price'] = sanitize_text_field( (string) $data['offers']['price'] );
			}
			if ( ! empty( $data['offers']['priceCurrency'] ) ) {
				$offers['priceCurrency'] = sanitize_text_field( (string) $data['offers']['priceCurrency'] );
			}
			if ( ! empty( $data['offers']['url'] ) ) {
				$offers['url'] = esc_url_raw( (string) $data['offers']['url'] );
			}
			if ( ! empty( $data['offers']['availability'] ) ) {
				$offers['availability'] = sanitize_text_field( (string) $data['offers']['availability'] );
			}
			if ( ! empty( $data['offers']['validFrom'] ) ) {
				$offers['validFrom'] = sanitize_text_field( (string) $data['offers']['validFrom'] );
			}
			if ( ! empty( $offers ) ) {
				$out['offers'] = $offers;
			}
		}

		return $out;
	}

	/**
	 * Normalize a schema.org enum value: accept the bare token ("EventScheduled"),
	 * a full https://schema.org/X URL, or a loose label, and return the canonical
	 * https://schema.org/<Token> URL. Falls back to $default on no match.
	 */
	private function normalize_event_enum( $value, $allowed, $default ) {
		$value = trim( $value );
		// Strip any URL prefix to get the bare token.
		$token = preg_replace( '#^https?://schema\.org/#i', '', $value );
		foreach ( $allowed as $candidate ) {
			if ( strcasecmp( $token, $candidate ) === 0 ) {
				return 'https://schema.org/' . $candidate;
			}
		}
		// Loose match: "online" -> OnlineEventAttendanceMode, "cancelled" -> EventCancelled, etc.
		foreach ( $allowed as $candidate ) {
			if ( stripos( $candidate, $token ) !== false && '' !== $token ) {
				return 'https://schema.org/' . $candidate;
			}
		}
		return 'https://schema.org/' . $default;
	}

	public function render_event( $data, $context ) {
		if ( empty( $data ) || ! is_array( $data ) || empty( $data['name'] ) || empty( $data['startDate'] ) ) {
			return null;
		}
		$schema = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'Event',
			'name'      => $data['name'],
			'startDate' => $data['startDate'],
		);
		foreach ( array( 'endDate', 'description', 'image', 'eventStatus', 'eventAttendanceMode' ) as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				$schema[ $k ] = $data[ $k ];
			}
		}

		if ( ! empty( $data['location'] ) ) {
			$loc = $data['location'];
			// A bare url with no address reads as a VirtualLocation; otherwise Place.
			if ( is_array( $loc ) && empty( $loc['address'] ) && empty( $loc['name'] ) && ! empty( $loc['url'] ) ) {
				$schema['location'] = array(
					'@type' => 'VirtualLocation',
					'url'   => $loc['url'],
				);
			} elseif ( is_array( $loc ) ) {
				$place = array( '@type' => 'Place' );
				if ( ! empty( $loc['name'] ) ) {
					$place['name'] = $loc['name'];
				}
				if ( ! empty( $loc['address'] ) ) {
					$place['address'] = $loc['address'];
				}
				if ( ! empty( $loc['url'] ) ) {
					$place['url'] = $loc['url'];
				}
				$schema['location'] = $place;
			}
		}

		foreach ( array( 'organizer', 'performer' ) as $agent_key ) {
			if ( empty( $data[ $agent_key ] ) ) {
				continue;
			}
			$agent = $data[ $agent_key ];
			// Organizer defaults to Organization; performer to Person — but both
			// accept either; Organization is the safe default for a string name.
			$at_type   = ( 'organizer' === $agent_key ) ? 'Organization' : 'Person';
			$to_node   = static function ( $a ) use ( $at_type ) {
				if ( is_string( $a ) && '' !== $a ) {
					return array( '@type' => $at_type, 'name' => $a );
				}
				if ( is_array( $a ) && ! empty( $a['name'] ) ) {
					$node = array( '@type' => ( ! empty( $a['@type'] ) ? $a['@type'] : $at_type ), 'name' => $a['name'] );
					if ( ! empty( $a['url'] ) ) {
						$node['url'] = $a['url'];
					}
					return $node;
				}
				return null;
			};
			// A sequential list of agents → multiple organizers/performers (a
			// concert with several musicians). A single string / {name,url} node
			// keeps its original single-node behaviour (backward compatible).
			$is_list = is_array( $agent ) && empty( $agent['name'] ) && array_keys( $agent ) === range( 0, count( $agent ) - 1 );
			if ( $is_list ) {
				$nodes = array();
				foreach ( $agent as $a ) {
					$n = $to_node( $a );
					if ( null !== $n ) {
						$nodes[] = $n;
					}
				}
				if ( ! empty( $nodes ) ) {
					$schema[ $agent_key ] = count( $nodes ) === 1 ? $nodes[0] : $nodes;
				}
			} else {
				$n = $to_node( $agent );
				if ( null !== $n ) {
					$schema[ $agent_key ] = $n;
				}
			}
		}

		if ( ! empty( $data['offers'] ) && is_array( $data['offers'] ) ) {
			$offer = array( '@type' => 'Offer' );
			foreach ( array( 'price', 'priceCurrency', 'url', 'availability', 'validFrom' ) as $ok ) {
				if ( ! empty( $data['offers'][ $ok ] ) || ( 'price' === $ok && isset( $data['offers']['price'] ) && '0' === (string) $data['offers']['price'] ) ) {
					$offer[ $ok ] = $data['offers'][ $ok ];
				}
			}
			// availability shorthand -> schema.org URL.
			if ( ! empty( $offer['availability'] ) && false === strpos( $offer['availability'], 'schema.org' ) ) {
				$offer['availability'] = 'https://schema.org/' . preg_replace( '#^https?://schema\.org/#i', '', $offer['availability'] );
			}
			if ( count( $offer ) > 1 ) {
				$schema['offers'] = $offer;
			}
		}

		return $schema;
	}

	public function sanitize_howto( $data, $context ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', 'HowTo data must be an array.', array( 'status' => 400 ) );
		}
		$out = array(
			'name'        => sanitize_text_field( $data['name'] ?? '' ),
			'description' => wp_kses_post( $data['description'] ?? '' ),
			'steps'       => array(),
		);
		if ( ! empty( $data['steps'] ) && is_array( $data['steps'] ) ) {
			foreach ( $data['steps'] as $step ) {
				$s = array(
					'name' => sanitize_text_field( $step['name'] ?? '' ),
					'text' => wp_kses_post( $step['text'] ?? '' ),
				);
				if ( ! empty( $step['image'] ) ) {
					$s['image'] = esc_url_raw( $step['image'] );
				}
				$out['steps'][] = $s;
			}
		}
		return $out;
	}

	public function render_howto( $data, $context ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return null;
		}
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'HowTo',
			'name'        => $data['name'] ?? '',
			'description' => $data['description'] ?? '',
		);
		if ( ! empty( $data['steps'] ) && is_array( $data['steps'] ) ) {
			$schema['step'] = array();
			foreach ( $data['steps'] as $i => $step ) {
				$s = array(
					'@type'    => 'HowToStep',
					'name'     => $step['name'] ?? '',
					'text'     => $step['text'] ?? '',
					'position' => $i + 1,
				);
				if ( ! empty( $step['image'] ) ) {
					$s['image'] = $step['image'];
				}
				$schema['step'][] = $s;
			}
		}
		return $schema;
	}

	public function sanitize_speakable( $data, $context ) {
		// Speakable stores arbitrary XPath/CSS selectors; pass through with basic guard.
		if ( ! is_array( $data ) ) {
			return is_string( $data ) ? array( 'xpath' => array( $data ) ) : array();
		}
		return $data;
	}

	public function render_speakable( $data, $context ) {
		if ( empty( $data ) ) {
			return null;
		}
		return array(
			'@context'  => 'https://schema.org',
			'@type'     => 'WebPage',
			'name'      => 'post' === $context['object_type'] ? get_the_title( $context['object_id'] ) : '',
			'speakable' => array(
				'@type' => 'SpeakableSpecification',
				'xpath' => $data['xpath'] ?? array( '/html/head/title', "/html/body//div[@class='luwipress-speakable']" ),
			),
		);
	}

	/**
	 * Passthrough sanitizer for FR-1 schemas (LocalBusiness/Service/Course/Review/AggregateRating).
	 * The operator submits a fully-formed schema.org array; we recursively sanitize values
	 * while preserving the structure. This lets vendors deliver schema fidelity without
	 * the registry having to know the full schema.org spec for every type.
	 */
	public function sanitize_passthrough_schema( $data, $context ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', 'Schema data must be an array.', array( 'status' => 400 ) );
		}
		return $this->sanitize_recursive( $data );
	}

	private function sanitize_recursive( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$key = is_int( $k ) ? $k : sanitize_text_field( $k );
				$out[ $key ] = $this->sanitize_recursive( $v );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			// URLs get URL sanitization; everything else gets kses (allows safe HTML in description fields).
			if ( preg_match( '#^https?://#i', $value ) ) {
				return esc_url_raw( $value );
			}
			return wp_kses_post( $value );
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		return null;
	}

	public function render_passthrough_schema( $data, $context ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return null;
		}
		// Guarantee @context + @type are present. If operator omitted them, fill from registered config.
		$type_config = $this->get_type_by_meta_or_context( $data, $context );
		if ( empty( $data['@context'] ) ) {
			$data = array( '@context' => 'https://schema.org' ) + $data;
		}
		if ( empty( $data['@type'] ) && $type_config ) {
			$data['@type'] = $type_config['schema_type'];
		}
		return $data;
	}

	private function get_type_by_meta_or_context( $data, $context ) {
		// Look up which registered type's meta_key resolves to the current data; fallback to first matching context.
		foreach ( $this->types as $cfg ) {
			if ( $this->type_applies( $cfg, $context ) ) {
				return $cfg;
			}
		}
		return null;
	}

	/**
	 * Auto-generate ItemList for product_cat archives (FR-2).
	 *
	 * Cached per-term for 1h via term meta so wp_head emission stays fast.
	 */
	public function auto_data_itemlist( $context ) {
		if ( 'term' !== $context['object_type'] || 'product_cat' !== $context['subtype'] ) {
			return null;
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return null;
		}

		$cache_key = '_luwipress_itemlist_cache';
		$cached    = get_term_meta( $context['object_id'], $cache_key, true );
		if ( is_array( $cached ) && isset( $cached['expires'] ) && $cached['expires'] > time() ) {
			return $cached['data'];
		}

		$limit = apply_filters( 'luwipress_itemlist_product_limit', 30, $context['object_id'] );
		$products = wc_get_products( array(
			'limit'    => $limit,
			'status'   => 'publish',
			'orderby'  => 'menu_order',
			'order'    => 'ASC',
			'category' => array( get_term( $context['object_id'] )->slug ),
			'return'   => 'objects',
		) );

		if ( empty( $products ) ) {
			return null;
		}

		$elements = array();
		$position = 1;
		foreach ( $products as $product ) {
			$elements[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'url'      => get_permalink( $product->get_id() ),
				'name'     => $product->get_name(),
			);
		}

		$term = get_term( $context['object_id'] );
		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => $term ? $term->name : '',
			'numberOfItems'   => count( $elements ),
			'itemListElement' => $elements,
		);

		update_term_meta( $context['object_id'], $cache_key, array(
			'data'    => $schema,
			'expires' => time() + HOUR_IN_SECONDS,
		) );

		return $schema;
	}

}
