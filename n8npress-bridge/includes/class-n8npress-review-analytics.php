<?php
/**
 * n8nPress Review Analytics
 *
 * Receives sentiment analysis from the AI Review Responder workflow,
 * tracks review themes, and generates AggregateRating schema when
 * the detected SEO plugin doesn't already provide it.
 *
 * @package n8nPress
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_Review_Analytics {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );

		// Output AggregateRating schema on product pages
		add_action( 'wp_head', array( $this, 'output_aggregate_rating_schema' ), 5 );
	}

	public function register_endpoints() {
		// n8n callback: save sentiment analysis for a review
		register_rest_route( 'n8npress/v1', '/review/sentiment-callback', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_sentiment_callback' ),
			'permission_callback' => array( $this, 'check_n8n_token' ),
		) );

		// Get review analytics for a product
		register_rest_route( 'n8npress/v1', '/review/analytics', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_analytics' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'product_id' => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
		) );

		// Get store-wide review summary
		register_rest_route( 'n8npress/v1', '/review/summary', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_summary' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
	}

	// ─── CALLBACK: Receive sentiment from n8n ──────────────────────────

	public function handle_sentiment_callback( $request ) {
		$data = $request->get_json_params();

		$review_id  = isset( $data['review_id'] ) ? absint( $data['review_id'] ) : 0;
		$product_id = isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
		$sentiment  = sanitize_text_field( $data['sentiment'] ?? '' );
		$score      = floatval( $data['sentiment_score'] ?? 0 );
		$themes     = isset( $data['themes'] ) ? array_map( 'sanitize_text_field', (array) $data['themes'] ) : array();
		$summary    = sanitize_text_field( $data['summary'] ?? '' );

		if ( ! $review_id || ! $product_id ) {
			return new WP_Error( 'missing_ids', 'review_id and product_id are required.', array( 'status' => 400 ) );
		}

		if ( ! in_array( $sentiment, array( 'positive', 'neutral', 'negative' ), true ) ) {
			return new WP_Error( 'invalid_sentiment', 'sentiment must be positive, neutral, or negative.', array( 'status' => 400 ) );
		}

		// Save individual review sentiment
		$review_data = array(
			'review_id'       => $review_id,
			'sentiment'       => $sentiment,
			'sentiment_score' => round( $score, 2 ),
			'themes'          => $themes,
			'summary'         => $summary,
			'analyzed_at'     => current_time( 'mysql' ),
		);

		update_comment_meta( $review_id, '_n8npress_sentiment', $review_data );

		// Update product aggregate
		$this->update_product_aggregate( $product_id );

		N8nPress_Logger::log( 'Review sentiment saved', 'info', array(
			'review_id'  => $review_id,
			'product_id' => $product_id,
			'sentiment'  => $sentiment,
			'score'      => $score,
		) );

		return array(
			'success'    => true,
			'review_id'  => $review_id,
			'product_id' => $product_id,
			'sentiment'  => $sentiment,
		);
	}

	// ─── REST: Get analytics for a product ─────────────────────────────

	public function handle_get_analytics( $request ) {
		$product_id = $request->get_param( 'product_id' );

		if ( $product_id ) {
			return rest_ensure_response( $this->get_product_analytics( $product_id ) );
		}

		// Return top products needing attention (negative sentiment)
		return rest_ensure_response( $this->get_attention_needed() );
	}

	// ─── REST: Store-wide summary ──────────────────────────────────────

	public function handle_get_summary( $request ) {
		global $wpdb;

		$sentiments = $wpdb->get_results(
			"SELECT cm.meta_value
			 FROM {$wpdb->commentmeta} cm
			 INNER JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
			 WHERE cm.meta_key = '_n8npress_sentiment'
			   AND c.comment_approved = '1'
			 ORDER BY c.comment_date DESC
			 LIMIT 500"
		);

		$counts = array( 'positive' => 0, 'neutral' => 0, 'negative' => 0 );
		$all_themes = array();
		$total_score = 0;

		foreach ( $sentiments as $row ) {
			$data = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$s = $data['sentiment'] ?? '';
			if ( isset( $counts[ $s ] ) ) {
				$counts[ $s ]++;
			}

			$total_score += floatval( $data['sentiment_score'] ?? 0 );

			foreach ( $data['themes'] ?? array() as $theme ) {
				$all_themes[ $theme ] = ( $all_themes[ $theme ] ?? 0 ) + 1;
			}
		}

		$total = array_sum( $counts );
		arsort( $all_themes );

		return rest_ensure_response( array(
			'total_analyzed'    => $total,
			'sentiment_counts'  => $counts,
			'average_score'     => $total > 0 ? round( $total_score / $total, 2 ) : 0,
			'positive_rate'     => $total > 0 ? round( ( $counts['positive'] / $total ) * 100 ) : 0,
			'top_themes'        => array_slice( $all_themes, 0, 10, true ),
			'attention_needed'  => $this->get_attention_needed(),
		) );
	}

	// ─── SCHEMA: AggregateRating output ────────────────────────────────

	public function output_aggregate_rating_schema() {
		if ( ! is_singular( 'product' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Check if SEO plugin already outputs product schema with ratings
		if ( $this->seo_plugin_handles_rating_schema() ) {
			return;
		}

		$product_id = get_the_ID();
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$rating_count = $product->get_rating_count();
		$avg_rating   = $product->get_average_rating();

		if ( $rating_count < 1 || $avg_rating <= 0 ) {
			return;
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Product',
			'name'     => $product->get_name(),
			'aggregateRating' => array(
				'@type'       => 'AggregateRating',
				'ratingValue' => number_format( $avg_rating, 1 ),
				'reviewCount' => $rating_count,
				'bestRating'  => '5',
				'worstRating' => '1',
			),
		);

		// Add sentiment insights if available
		$aggregate = get_post_meta( $product_id, '_n8npress_review_aggregate', true );
		if ( ! empty( $aggregate['top_themes'] ) ) {
			$schema['review'] = array();
			// Add up to 3 representative review summaries
			$reviews = $this->get_top_reviews_for_schema( $product_id, 3 );
			foreach ( $reviews as $review ) {
				$schema['review'][] = array(
					'@type'        => 'Review',
					'reviewRating' => array(
						'@type'       => 'Rating',
						'ratingValue' => $review['rating'],
					),
					'author'       => array(
						'@type' => 'Person',
						'name'  => $review['author'],
					),
					'reviewBody'   => $review['body'],
					'datePublished' => $review['date'],
				);
			}
		}

		echo "\n<!-- n8nPress AggregateRating Schema -->\n";
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
	}

	// ─── HELPERS ───────────────────────────────────────────────────────

	private function update_product_aggregate( $product_id ) {
		$reviews = get_comments( array(
			'post_id' => $product_id,
			'type'    => 'review',
			'status'  => 'approve',
			'number'  => 200,
		) );

		$counts = array( 'positive' => 0, 'neutral' => 0, 'negative' => 0 );
		$themes = array();
		$total_score = 0;
		$analyzed = 0;

		foreach ( $reviews as $review ) {
			$data = get_comment_meta( $review->comment_ID, '_n8npress_sentiment', true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$analyzed++;
			$s = $data['sentiment'] ?? '';
			if ( isset( $counts[ $s ] ) ) {
				$counts[ $s ]++;
			}

			$total_score += floatval( $data['sentiment_score'] ?? 0 );

			foreach ( $data['themes'] ?? array() as $theme ) {
				$themes[ $theme ] = ( $themes[ $theme ] ?? 0 ) + 1;
			}
		}

		arsort( $themes );

		$aggregate = array(
			'total_reviews'    => count( $reviews ),
			'analyzed'         => $analyzed,
			'sentiment_counts' => $counts,
			'average_score'    => $analyzed > 0 ? round( $total_score / $analyzed, 2 ) : 0,
			'positive_rate'    => $analyzed > 0 ? round( ( $counts['positive'] / $analyzed ) * 100 ) : 0,
			'top_themes'       => array_slice( $themes, 0, 5, true ),
			'updated_at'       => current_time( 'mysql' ),
		);

		update_post_meta( $product_id, '_n8npress_review_aggregate', $aggregate );
	}

	private function get_product_analytics( $product_id ) {
		$aggregate = get_post_meta( $product_id, '_n8npress_review_aggregate', true );

		if ( empty( $aggregate ) ) {
			// Compute on the fly
			$this->update_product_aggregate( $product_id );
			$aggregate = get_post_meta( $product_id, '_n8npress_review_aggregate', true );
		}

		$product = wc_get_product( $product_id );

		return array(
			'product_id'     => $product_id,
			'product_name'   => $product ? $product->get_name() : get_the_title( $product_id ),
			'average_rating' => $product ? $product->get_average_rating() : 0,
			'rating_count'   => $product ? $product->get_rating_count() : 0,
			'sentiment'      => $aggregate ?: array(),
		);
	}

	private function get_attention_needed() {
		global $wpdb;

		// Products with recent negative reviews
		$results = $wpdb->get_results(
			"SELECT DISTINCT c.comment_post_ID as product_id, p.post_title
			 FROM {$wpdb->comments} c
			 INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_id
			 INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
			 WHERE cm.meta_key = '_n8npress_sentiment'
			   AND cm.meta_value LIKE '%\"negative\"%'
			   AND c.comment_date > DATE_SUB(NOW(), INTERVAL 30 DAY)
			   AND c.comment_approved = '1'
			 ORDER BY c.comment_date DESC
			 LIMIT 10"
		);

		$products = array();
		foreach ( $results as $row ) {
			$aggregate = get_post_meta( $row->product_id, '_n8npress_review_aggregate', true );
			$products[] = array(
				'product_id'    => (int) $row->product_id,
				'product_name'  => $row->post_title,
				'negative_count' => $aggregate['sentiment_counts']['negative'] ?? 0,
				'positive_rate' => $aggregate['positive_rate'] ?? 0,
			);
		}

		return $products;
	}

	private function get_top_reviews_for_schema( $product_id, $limit = 3 ) {
		$reviews = get_comments( array(
			'post_id' => $product_id,
			'type'    => 'review',
			'status'  => 'approve',
			'number'  => $limit,
			'orderby' => 'comment_date',
			'order'   => 'DESC',
			'meta_query' => array(
				'relation' => 'AND',
				array( 'key' => 'rating', 'value' => '3', 'compare' => '>=', 'type' => 'NUMERIC' ),
			),
		) );

		$items = array();
		foreach ( $reviews as $review ) {
			$rating = get_comment_meta( $review->comment_ID, 'rating', true );
			$items[] = array(
				'rating' => $rating ?: '5',
				'author' => $review->comment_author,
				'body'   => wp_trim_words( $review->comment_content, 30 ),
				'date'   => gmdate( 'Y-m-d', strtotime( $review->comment_date ) ),
			);
		}

		return $items;
	}

	private function seo_plugin_handles_rating_schema() {
		$detector = N8nPress_Plugin_Detector::get_instance();
		$seo      = $detector->detect_seo();

		// Rank Math and Yoast both generate Product schema with AggregateRating
		if ( in_array( $seo['plugin'], array( 'rank-math', 'yoast' ), true ) ) {
			// Check if their schema module is enabled
			if ( 'rank-math' === $seo['plugin'] && ! empty( $seo['features']['schema'] ) ) {
				return true;
			}
			if ( 'yoast' === $seo['plugin'] ) {
				// Yoast WooCommerce SEO handles product schema
				if ( class_exists( 'Yoast_WooCommerce_SEO' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	// ─── PERMISSIONS ───────────────────────────────────────────────────

	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	public function check_n8n_token( $request ) {
		$auth = $request->get_header( 'Authorization' );
		if ( empty( $auth ) ) {
			return false;
		}

		$token  = str_replace( 'Bearer ', '', $auth );
		$stored = get_option( 'n8npress_seo_api_token', '' );
		return ! empty( $stored ) && hash_equals( $stored, $token );
	}
}
