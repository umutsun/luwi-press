<?php
/**
 * LuwiPress BM25 Search Index
 *
 * Full-text search using BM25 ranking algorithm.
 * Indexes WooCommerce products (title, description, excerpt, categories,
 * tags, attributes, SKU) into a custom table for fast, relevant search.
 *
 * Zero external dependencies — runs on any WordPress/MySQL host.
 * Supports multilingual content (WPML translations indexed separately).
 *
 * @package LuwiPress
 * @since   2.0.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Search_Index {

	private static $instance = null;

	/** BM25 tuning parameters */
	const BM25_K1 = 1.2;
	const BM25_B  = 0.75;

	/** Minimum token length to index */
	const MIN_TOKEN_LENGTH = 2;

	/** Stopwords (multilingual — EN, TR, IT, FR, DE, ES, AR) */
	private static $stopwords = array(
		// English
		'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
		'of', 'with', 'by', 'is', 'was', 'are', 'were', 'be', 'been', 'has',
		'have', 'had', 'do', 'does', 'did', 'will', 'would', 'can', 'could',
		'this', 'that', 'these', 'those', 'it', 'its', 'not', 'no', 'from',
		'as', 'if', 'so', 'than', 'too', 'very', 'just', 'about', 'up',
		// Turkish
		'bir', 'bu', 've', 'da', 'de', 'ile', 'için', 'gibi', 'daha',
		'çok', 'var', 'olan', 'olarak', 'kadar', 'sonra', 'den', 'dan',
		// Italian
		'il', 'lo', 'la', 'le', 'gli', 'un', 'una', 'uno', 'di', 'del',
		'della', 'dei', 'che', 'per', 'con', 'su', 'nel', 'nella',
		// French
		'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'en',
		'est', 'que', 'qui', 'dans', 'pour', 'pas', 'sur', 'ce', 'sont',
		// German
		'der', 'die', 'das', 'ein', 'eine', 'und', 'ist', 'von', 'mit',
		'auf', 'den', 'dem', 'nicht', 'sich', 'auch', 'als', 'noch',
		// Spanish
		'el', 'los', 'las', 'una', 'unos', 'unas', 'del', 'al',
		'que', 'en', 'por', 'con', 'para', 'como', 'pero', 'mas',
	);

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Auto-index on product changes
		add_action( 'save_post_product', array( $this, 'index_product' ), 20 );
		add_action( 'delete_post', array( $this, 'remove_product' ) );
		add_action( 'woocommerce_update_product', array( $this, 'index_product' ), 20 );

		// WPML: index translation when saved
		add_action( 'icl_make_duplicate', array( $this, 'index_product_wpml' ), 20, 4 );
	}

	// ─── DATABASE ──────────────────────────────────────────────────

	/**
	 * Create the search index table.
	 */
	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . 'luwipress_search_index';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			token varchar(100) NOT NULL,
			field varchar(20) NOT NULL DEFAULT 'title',
			tf double NOT NULL DEFAULT 0,
			doc_length int unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY post_idx (post_id),
			KEY token_idx (token),
			KEY token_field_idx (token, field)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Store corpus stats
		if ( get_option( 'luwipress_bm25_total_docs' ) === false ) {
			add_option( 'luwipress_bm25_total_docs', 0 );
			add_option( 'luwipress_bm25_avg_doc_length', 0 );
		}
	}

	// ─── TOKENIZER ─────────────────────────────────────────────────

	/**
	 * Tokenize text into searchable terms.
	 *
	 * @param string $text Raw text to tokenize.
	 * @return array Array of lowercase tokens with stopwords removed.
	 */
	public static function tokenize( $text ) {
		// Strip HTML
		$text = wp_strip_all_tags( $text );

		// Normalize: lowercase, remove accents for matching
		$text = mb_strtolower( $text );

		// Replace non-alphanumeric with spaces (keep Unicode letters)
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		// Split into tokens
		$tokens = array_filter( explode( ' ', $text ), function( $t ) {
			return mb_strlen( $t ) >= self::MIN_TOKEN_LENGTH;
		} );

		// Remove stopwords
		$tokens = array_diff( $tokens, self::$stopwords );

		return array_values( $tokens );
	}

	// ─── INDEXER ───────────────────────────────────────────────────

	/**
	 * Index a single product (all its fields).
	 *
	 * @param int $post_id Product post ID.
	 */
	public function index_product( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_search_index';

		// Remove old index for this product
		$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );

		// Collect text by field with weights
		$fields = array();

		// Title (weight: indexed as-is, gets natural BM25 boost from shorter doc_length)
		$fields['title'] = $post->post_title;

		// Description
		$fields['description'] = $post->post_content;

		// Short description / excerpt
		if ( ! empty( $post->post_excerpt ) ) {
			$fields['excerpt'] = $post->post_excerpt;
		}

		// SKU
		$sku = get_post_meta( $post_id, '_sku', true );
		if ( ! empty( $sku ) ) {
			$fields['sku'] = $sku;
		}

		// Categories
		$cats = get_the_terms( $post_id, 'product_cat' );
		if ( $cats && ! is_wp_error( $cats ) ) {
			$fields['category'] = implode( ' ', wp_list_pluck( $cats, 'name' ) );
		}

		// Tags
		$tags = get_the_terms( $post_id, 'product_tag' );
		if ( $tags && ! is_wp_error( $tags ) ) {
			$fields['tag'] = implode( ' ', wp_list_pluck( $tags, 'name' ) );
		}

		// Product attributes (pa_color, pa_size, etc.)
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$attrs = $product->get_attributes();
				$attr_text = array();
				foreach ( $attrs as $attr ) {
					if ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {
						$options = $attr->get_options();
						if ( $attr->is_taxonomy() ) {
							foreach ( $options as $term_id ) {
								$term = get_term( $term_id );
								if ( $term && ! is_wp_error( $term ) ) {
									$attr_text[] = $term->name;
								}
							}
						} else {
							$attr_text = array_merge( $attr_text, $options );
						}
					}
				}
				if ( ! empty( $attr_text ) ) {
					$fields['attribute'] = implode( ' ', $attr_text );
				}
			}
		}

		// AEO FAQ content
		$faq = get_post_meta( $post_id, '_luwipress_faq', true );
		if ( ! empty( $faq ) ) {
			$faq_data = is_string( $faq ) ? maybe_unserialize( $faq ) : $faq;
			if ( ! is_array( $faq_data ) && is_string( $faq ) ) {
				$faq_data = json_decode( $faq, true );
			}
			if ( is_array( $faq_data ) ) {
				$faq_text = array();
				foreach ( $faq_data as $item ) {
					if ( ! empty( $item['question'] ) ) {
						$faq_text[] = $item['question'];
					}
					if ( ! empty( $item['answer'] ) ) {
						$faq_text[] = $item['answer'];
					}
				}
				if ( ! empty( $faq_text ) ) {
					$fields['faq'] = implode( ' ', $faq_text );
				}
			}
		}

		// Tokenize each field and insert
		$total_tokens = 0;
		$insert_rows  = array();

		foreach ( $fields as $field => $text ) {
			$tokens = self::tokenize( $text );
			if ( empty( $tokens ) ) {
				continue;
			}

			// Count term frequencies
			$tf_map = array_count_values( $tokens );
			$doc_len = count( $tokens );
			$total_tokens += $doc_len;

			foreach ( $tf_map as $token => $count ) {
				$insert_rows[] = $wpdb->prepare(
					'(%d, %s, %s, %f, %d)',
					$post_id,
					mb_substr( $token, 0, 100 ),
					$field,
					$count / $doc_len, // normalized TF
					$doc_len
				);
			}
		}

		// Bulk insert
		if ( ! empty( $insert_rows ) ) {
			$chunks = array_chunk( $insert_rows, 500 );
			foreach ( $chunks as $chunk ) {
				$wpdb->query(
					"INSERT INTO {$table} (post_id, token, field, tf, doc_length) VALUES " . implode( ',', $chunk )
				);
			}
		}

		// Update corpus stats
		$this->update_corpus_stats();
	}

	/**
	 * Handle WPML duplicate creation.
	 */
	public function index_product_wpml( $master_post_id, $lang, $post_array, $duplicate_post_id ) {
		if ( $duplicate_post_id ) {
			$this->index_product( $duplicate_post_id );
		}
	}

	/**
	 * Remove product from index.
	 */
	public function remove_product( $post_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'luwipress_search_index', array( 'post_id' => $post_id ), array( '%d' ) );
		$this->update_corpus_stats();
	}

	/**
	 * Reindex all published products (admin action / CLI).
	 *
	 * @return int Number of products indexed.
	 */
	public function reindex_all() {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_search_index';

		// Clear entire index
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		// Get all published products (including WPML translations)
		$products = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'product' AND post_status = 'publish'
			 ORDER BY ID ASC LIMIT 5000"
		);

		$count = 0;
		foreach ( $products as $post_id ) {
			$this->index_product( absint( $post_id ) );
			$count++;
		}

		$this->update_corpus_stats();

		LuwiPress_Logger::log( "BM25 reindex complete: {$count} products indexed", 'info' );

		return $count;
	}

	/**
	 * Update corpus-wide statistics (total docs, avg doc length).
	 */
	private function update_corpus_stats() {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_search_index';

		$stats = $wpdb->get_row(
			"SELECT COUNT(DISTINCT post_id) AS total_docs,
			        AVG(doc_length) AS avg_length
			 FROM {$table}"
		);

		update_option( 'luwipress_bm25_total_docs', absint( $stats->total_docs ?? 0 ), false );
		update_option( 'luwipress_bm25_avg_doc_length', floatval( $stats->avg_length ?? 0 ), false );
	}

	// ─── BM25 SEARCH ───────────────────────────────────────────────

	/**
	 * Search products using BM25 ranking.
	 *
	 * @param string $query     Search query.
	 * @param int    $limit     Max results.
	 * @param array  $field_weights Field importance multipliers.
	 * @return array Ranked results: [{post_id, score, title, ...}]
	 */
	public function search( $query, $limit = 10, $field_weights = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_search_index';

		// Default field weights
		$weights = array_merge( array(
			'title'       => 3.0,
			'sku'         => 2.5,
			'category'    => 2.0,
			'tag'         => 1.5,
			'attribute'   => 1.5,
			'faq'         => 1.2,
			'excerpt'     => 1.0,
			'description' => 0.8,
		), $field_weights );

		// Tokenize query
		$tokens = self::tokenize( $query );
		if ( empty( $tokens ) ) {
			return array();
		}

		// Corpus stats
		$N       = max( 1, (int) get_option( 'luwipress_bm25_total_docs', 1 ) );
		$avg_dl  = max( 1, (float) get_option( 'luwipress_bm25_avg_doc_length', 100 ) );
		$k1      = self::BM25_K1;
		$b       = self::BM25_B;

		// Get document frequencies for query tokens
		$token_ph = implode( ',', array_fill( 0, count( $tokens ), '%s' ) );
		$df_rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT token, COUNT(DISTINCT post_id) AS df
			 FROM {$table}
			 WHERE token IN ({$token_ph})
			 GROUP BY token",
			...$tokens
		) );
		$df_map = array();
		foreach ( $df_rows as $row ) {
			$df_map[ $row->token ] = intval( $row->df );
		}

		// Get matching index entries
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, token, field, tf, doc_length
			 FROM {$table}
			 WHERE token IN ({$token_ph})",
			...$tokens
		) );

		if ( empty( $rows ) ) {
			return array();
		}

		// Calculate BM25 scores per document
		$scores = array();
		foreach ( $rows as $row ) {
			$pid   = $row->post_id;
			$token = $row->token;
			$field = $row->field;
			$tf    = floatval( $row->tf );
			$dl    = intval( $row->doc_length );
			$df    = $df_map[ $token ] ?? 1;

			// IDF component: log((N - df + 0.5) / (df + 0.5) + 1)
			$idf = log( ( $N - $df + 0.5 ) / ( $df + 0.5 ) + 1 );

			// BM25 TF component
			$tf_norm = ( $tf * ( $k1 + 1 ) ) / ( $tf + $k1 * ( 1 - $b + $b * ( $dl / $avg_dl ) ) );

			// Field weight
			$fw = $weights[ $field ] ?? 1.0;

			if ( ! isset( $scores[ $pid ] ) ) {
				$scores[ $pid ] = 0;
			}
			$scores[ $pid ] += $idf * $tf_norm * $fw;
		}

		// Sort by score descending
		arsort( $scores );

		// Get top results
		$top_ids = array_slice( array_keys( $scores ), 0, $limit, true );

		if ( empty( $top_ids ) ) {
			return array();
		}

		// Enrich results with product data
		$results = array();
		foreach ( $top_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post || 'product' !== $post->post_type ) {
				continue;
			}

			$price    = get_post_meta( $pid, '_price', true );
			$stock    = get_post_meta( $pid, '_stock_status', true );
			$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';

			$cats = get_the_terms( $pid, 'product_cat' );
			$cat_names = array();
			if ( $cats && ! is_wp_error( $cats ) ) {
				$cat_names = wp_list_pluck( $cats, 'name' );
			}

			$results[] = array(
				'id'          => $pid,
				'name'        => $post->post_title,
				'price'       => ( $price ?: 'N/A' ) . $currency,
				'stock'       => $stock ?: 'instock',
				'sku'         => get_post_meta( $pid, '_sku', true ) ?: '',
				'categories'  => $cat_names,
				'description' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 ),
				'reviews'     => array(
					'count'      => (int) $post->comment_count,
					'avg_rating' => 0,
				),
				'url'         => get_permalink( $pid ),
				'bm25_score'  => round( $scores[ $pid ], 4 ),
			);
		}

		return $results;
	}

	// ─── UTILITIES ─────────────────────────────────────────────────

	/**
	 * Get index statistics.
	 *
	 * @return array Stats: total_docs, total_tokens, unique_tokens, avg_doc_length.
	 */
	public function get_stats() {
		global $wpdb;
		$table = $wpdb->prefix . 'luwipress_search_index';

		$stats = $wpdb->get_row(
			"SELECT COUNT(DISTINCT post_id) AS total_docs,
			        COUNT(*) AS total_entries,
			        COUNT(DISTINCT token) AS unique_tokens
			 FROM {$table}"
		);

		return array(
			'total_docs'     => absint( $stats->total_docs ?? 0 ),
			'total_entries'  => absint( $stats->total_entries ?? 0 ),
			'unique_tokens'  => absint( $stats->unique_tokens ?? 0 ),
			'avg_doc_length' => floatval( get_option( 'luwipress_bm25_avg_doc_length', 0 ) ),
		);
	}

	/**
	 * Check if index is built.
	 *
	 * @return bool True if index has entries.
	 */
	public function is_indexed() {
		return (int) get_option( 'luwipress_bm25_total_docs', 0 ) > 0;
	}

	/**
	 * Cleanup: drop the index table.
	 */
	public static function drop_table() {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}luwipress_search_index" );
		delete_option( 'luwipress_bm25_total_docs' );
		delete_option( 'luwipress_bm25_avg_doc_length' );
	}
}
