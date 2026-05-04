<?php
/**
 * Marketplace Adapter Interface
 *
 * All marketplace adapters (Amazon, eBay, Trendyol, Alibaba) implement this
 * interface to provide a normalized API for the Marketplace orchestrator.
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface LuwiPress_Marketplace_Adapter {

	/**
	 * Get the marketplace slug (e.g. 'amazon', 'ebay').
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Get the marketplace display label (e.g. 'Amazon', 'eBay').
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Check whether the adapter has valid credentials configured.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Test the connection to the marketplace API.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection();

	/**
	 * Publish a product to the marketplace.
	 *
	 * @param array $product_data Normalized product data from map_product_data().
	 * @return array|WP_Error  Array with 'marketplace_product_id' on success.
	 */
	public function publish_product( array $product_data );

	/**
	 * Update an existing listing on the marketplace.
	 *
	 * @param string $marketplace_product_id The listing ID on the marketplace.
	 * @param array  $product_data           Normalized product data.
	 * @return true|WP_Error
	 */
	public function update_product( $marketplace_product_id, array $product_data );

	/**
	 * Delete / deactivate a listing from the marketplace.
	 *
	 * @param string $marketplace_product_id The listing ID on the marketplace.
	 * @return true|WP_Error
	 */
	public function delete_product( $marketplace_product_id );

	/**
	 * Map a WooCommerce product to the marketplace-specific data format.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return array Marketplace-formatted product data.
	 */
	public function map_product_data( $product );

	/**
	 * Get the marketplace category list (for mapping UI).
	 *
	 * @param string $query Optional search query.
	 * @return array|WP_Error
	 */
	public function get_categories( $query = '' );
}
