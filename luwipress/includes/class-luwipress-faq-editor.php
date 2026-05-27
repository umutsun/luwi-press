<?php
/**
 * LuwiPress FAQ Tab Editor
 *
 * Inline metabox UI for editing the `_luwipress_faq` post meta. Until now
 * this meta was only writable through MCP / REST (`aeo_save_faq` tool) or
 * the AI generation pipeline (`aeo_generate_faq`), which made the FAQ tab
 * effectively invisible to non-WebMCP operators — they had to either
 * trigger AI and hope for the best, or write JSON in a custom field plugin.
 *
 * This module closes that gap with a standard WP metabox:
 *   - Row repeater (add / remove / reorder) for {question, answer} pairs
 *   - "Generate with AI" button → triggers /aeo/generate-faq async pipeline
 *   - Inline status pill (completed / processing / failed / not-yet)
 *   - Save-with-post flow: standard $_POST handling, no extra REST round-trip
 *
 * Strategic role: this is the single biggest UI gap closed in Sprint 1B.
 * The FAQ tab on product/page/post is one of the highest-citation AEO
 * surfaces; operators who couldn't edit it without MCP were either stuck
 * with whatever AI generated or paying their dev to write JSON.
 *
 * Targets are filter-controlled: by default product, post, page. Themes
 * or companions can extend via `luwipress_faq_editor_post_types`.
 *
 * @package LuwiPress
 * @since   3.5.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_FAQ_Editor {

	const META_KEY        = '_luwipress_faq';
	const META_STATUS_KEY = '_luwipress_aeo_faq_status';
	const META_UPDATED    = '_luwipress_aeo_faq_updated';
	const NONCE_ACTION    = 'luwipress_faq_editor_save';
	const NONCE_FIELD     = 'luwipress_faq_editor_nonce';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes',        array( $this, 'register_metaboxes' ) );
		add_action( 'save_post',             array( $this, 'save_metabox' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Post types that surface the FAQ editor metabox.
	 *
	 * Defaults: product (when WC active), post, page. Filter to extend
	 * for custom post types — e.g. a vendor / luthier / artist CPT in a
	 * primary-source authority theme.
	 *
	 * @return string[]
	 */
	public function get_post_types() {
		$defaults = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$defaults[] = 'product';
		}
		return (array) apply_filters( 'luwipress_faq_editor_post_types', $defaults );
	}

	public function register_metaboxes() {
		foreach ( $this->get_post_types() as $post_type ) {
			add_meta_box(
				'luwipress-faq-editor',
				__( 'LuwiPress FAQ', 'luwipress' ),
				array( $this, 'render_metabox' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Read + decode the stored FAQ payload for a post into the canonical
	 * shape `[ {question, answer}, ... ]`. Handles three legacy storage
	 * formats so a customer migrating from older installs doesn't lose
	 * existing FAQ data when the metabox first loads:
	 *
	 *   1. Canonical array of {question, answer} maps (current shape).
	 *   2. Serialized PHP array (WP default if some legacy caller used
	 *      `update_post_meta` with a raw array — get_post_meta unserialises
	 *      transparently, but we still normalise here).
	 *   3. JSON string (some early TASK-048 sync scripts wrote a JSON
	 *      string instead of a PHP array — discovered 2026-05 92. otrm).
	 *
	 * @param int $post_id
	 * @return array<int,array{question:string,answer:string}>
	 */
	public function get_faq_rows( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( empty( $raw ) ) {
			return array();
		}

		// Form 3 — JSON string.
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				return array();
			}
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q = isset( $item['question'] ) ? (string) $item['question'] : '';
			$a = isset( $item['answer'] )   ? (string) $item['answer']   : '';
			// Drop fully-empty entries on read so the editor isn't seeded
			// with phantom rows.
			if ( '' === trim( $q ) && '' === trim( $a ) ) {
				continue;
			}
			$rows[] = array( 'question' => $q, 'answer' => $a );
		}
		return $rows;
	}

	/**
	 * Lightweight status descriptor for the inline pill at the top of the
	 * metabox. Mirrors the values the AEO generation pipeline writes.
	 *
	 * @param int $post_id
	 * @return array{status:string,label:string,updated:string}
	 */
	public function get_status( $post_id ) {
		$status  = (string) get_post_meta( $post_id, self::META_STATUS_KEY, true );
		$updated = (string) get_post_meta( $post_id, self::META_UPDATED, true );

		$label_map = array(
			'completed'  => __( 'FAQ saved', 'luwipress' ),
			'processing' => __( 'AI generating…', 'luwipress' ),
			'failed'     => __( 'AI generation failed', 'luwipress' ),
		);

		if ( '' === $status ) {
			$status = empty( $this->get_faq_rows( $post_id ) ) ? 'empty' : 'manual';
		}

		$label_map['empty']  = __( 'No FAQ yet', 'luwipress' );
		$label_map['manual'] = __( 'FAQ saved manually', 'luwipress' );

		return array(
			'status'  => $status,
			'label'   => $label_map[ $status ] ?? $status,
			'updated' => $updated,
		);
	}

	public function render_metabox( $post ) {
		// Hand off to the template so the class stays focused on data
		// shaping and the markup is grep-able in one place.
		$post_id = (int) $post->ID;
		$rows    = $this->get_faq_rows( $post_id );
		$status  = $this->get_status( $post_id );
		$is_wc_product = ( 'product' === $post->post_type && post_type_exists( 'product' ) );

		// REST surface for AI generate + cross-links surfaced to the JS.
		$rest_base      = esc_url_raw( rest_url( 'luwipress/v1/' ) );
		$rest_nonce     = wp_create_nonce( 'wp_rest' );
		$schema_preview = admin_url( 'admin.php?page=luwipress-schema-preview' );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		include LUWIPRESS_PLUGIN_DIR . 'admin/faq-editor-metabox.php';
	}

	/**
	 * Persist the metabox contents on every save_post. Defensive against
	 * autosave / revision noise so a writer typing in Gutenberg doesn't
	 * trigger empty-row churn on every keystroke.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public function save_metabox( $post_id, $post = null ) {
		// 0. Skip the noisy save_post triggers.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! ( $post instanceof WP_Post ) ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return;
			}
		}
		if ( in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return;
		}

		// 1. Post type gate — only persist if the metabox actually rendered.
		if ( ! in_array( $post->post_type, $this->get_post_types(), true ) ) {
			return;
		}

		// 2. Nonce + capability — standard WP belt-and-braces. A missing
		// nonce means the metabox didn't submit (e.g. quick-edit), so we
		// silently return instead of failing the whole post save.
		if ( empty( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST[ self::NONCE_FIELD ], self::NONCE_ACTION ) ) {
			return;
		}
		$cap = ( 'product' === $post->post_type ) ? 'edit_product' : 'edit_post';
		if ( ! current_user_can( $cap, $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// 3. Read the submitted rows. Editor JS posts a parallel-indexed
		// pair of arrays (`luwipress_faq_q[]` + `luwipress_faq_a[]`) so the
		// PHP side doesn't have to defend against half-formed maps.
		$raw_questions = isset( $_POST['luwipress_faq_q'] ) ? (array) wp_unslash( $_POST['luwipress_faq_q'] ) : array();
		$raw_answers   = isset( $_POST['luwipress_faq_a'] ) ? (array) wp_unslash( $_POST['luwipress_faq_a'] ) : array();

		$rows = array();
		$pairs = max( count( $raw_questions ), count( $raw_answers ) );
		for ( $i = 0; $i < $pairs; $i++ ) {
			$q = isset( $raw_questions[ $i ] ) ? (string) $raw_questions[ $i ] : '';
			$a = isset( $raw_answers[ $i ] )   ? (string) $raw_answers[ $i ]   : '';

			// Trim and drop fully-empty rows; require BOTH parts for an
			// FAQ entry to count (matches the upstream `save_faq_data`
			// sanitiser so the metabox can never produce shapes the REST
			// path rejects).
			$q = trim( $q );
			$a = trim( $a );
			if ( '' === $q || '' === $a ) {
				continue;
			}

			$rows[] = array(
				'question' => sanitize_text_field( $q ),
				'answer'   => wp_kses_post( $a ),
			);
		}

		// 4. Hard cap to keep schemas sane (Google FAQPage rich results
		// stop deduping past ~10 entries; over-large arrays bloat the
		// JSON-LD without benefit).
		if ( count( $rows ) > 20 ) {
			$rows = array_slice( $rows, 0, 20 );
		}

		// 5. Persist + status flag.
		if ( empty( $rows ) ) {
			// Operator cleared every row — drop the meta entirely so the
			// schema renderer doesn't emit an empty FAQPage block.
			delete_post_meta( $post_id, self::META_KEY );
			delete_post_meta( $post_id, self::META_STATUS_KEY );
			delete_post_meta( $post_id, self::META_UPDATED );
		} else {
			update_post_meta( $post_id, self::META_KEY, $rows );
			update_post_meta( $post_id, self::META_STATUS_KEY, 'completed' );
			update_post_meta( $post_id, self::META_UPDATED, current_time( 'c' ) );
		}

		// 6. Cache invalidations — only ones not covered by other listeners.
		// AEO coverage transient: nobody else clears it for FAQ writes, so
		// keep this explicit. Health Score: has its own save_post_<type>
		// p20 listener (see class-luwipress-health-score.php::__construct),
		// so the invalidate happens automatically when this save_post runs.
		// Net change vs 3.5.5: one redundant delete_transient saved per FAQ
		// save (the Health Score one).
		// Plugin Detector purge_post_cache: keep — it bridges to the page-
		// cache plugin (Rocket / LiteSpeed) which WP's automatic save_post
		// → clean_post_cache does NOT cover.
		delete_transient( 'luwipress_aeo_coverage' );
		if ( class_exists( 'LuwiPress_Plugin_Detector' ) ) {
			LuwiPress_Plugin_Detector::get_instance()->purge_post_cache( $post_id );
		}
	}

	/**
	 * Enqueue the editor assets only on post edit screens for opted-in
	 * post types. Keeps the rest of wp-admin clean.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, $this->get_post_types(), true ) ) {
			return;
		}

		$ver = defined( 'LUWIPRESS_VERSION' ) ? LUWIPRESS_VERSION : '1.0';

		wp_enqueue_style(
			'luwipress-faq-editor',
			LUWIPRESS_PLUGIN_URL . 'assets/css/faq-editor.css',
			array(),
			$ver
		);

		wp_enqueue_script(
			'luwipress-faq-editor',
			LUWIPRESS_PLUGIN_URL . 'assets/js/faq-editor.js',
			array(),
			$ver,
			true
		);

		// REST handoff for the "Generate with AI" button + status polling.
		wp_localize_script(
			'luwipress-faq-editor',
			'LuwiPressFAQEditor',
			array(
				'restBase'  => esc_url_raw( rest_url( 'luwipress/v1/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'addRow'      => __( 'Add question', 'luwipress' ),
					'removeRow'   => __( 'Remove', 'luwipress' ),
					'moveUp'      => __( 'Move up', 'luwipress' ),
					'moveDown'    => __( 'Move down', 'luwipress' ),
					'aiQueued'    => __( 'AI generation queued — reload the page in ~30s to see results.', 'luwipress' ),
					'aiCompleted' => __( 'AI generation completed.', 'luwipress' ),
					'aiFailed'    => __( 'AI generation failed.', 'luwipress' ),
					'aiOnlyWoo'   => __( 'AI generation is only available for WooCommerce products in this release.', 'luwipress' ),
					'confirmClear'=> __( 'Remove this question? Unsaved row content will be lost.', 'luwipress' ),
				),
			)
		);
	}
}
