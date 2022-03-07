<?php

/**
 * Exit if accessed directly
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns true if a feature flag has been enabled. Otherwise returns false.
 *
 * @param string $feature Example: 'product_update_handler'
 *
 * @return bool
 */
function dfrpswc_feature_flag_is_enabled( string $feature ): bool {
	$flags = dfrpswc_feature_flags();

	return isset( $flags[ $feature ] ) && $flags[ $feature ];
}

/**
 * Returns an array of all available feature flags as associative array.
 *
 * @return array
 */
function dfrpswc_feature_flags(): array {

	$flags = [];

	$flags['product_update_handler'] = (bool) apply_filters( 'dfrpswc_enable_product_update_handler_feature_flag', true );

	return $flags;
}

/**
 * Inserts or Updates a Datafeedr product.
 *
 * @param array $dfr_product An array of Product Data from the Datafeedr API.
 * @param array $product_set Product Set data.
 */
function dfrpswc_upsert_product( array $dfr_product, array $product_set ) {

	// Get post if it already exists.
	$existing_post = dfrps_get_existing_post( $dfr_product, $product_set );

	// If $existing_post equals "skip", that means the product has been imported but attempts to query it return false because of a race condition.
	if ( $existing_post === 'skip' ) {
		return;
	}

	// Disable W3TC's caching while processing products.
	add_filter( 'w3tc_flushable_post', '__return_false', 20, 3 );

	$action = ( $existing_post && $existing_post['post_type'] == DFRPSWC_POST_TYPE ) ? 'update' : 'insert';

	$type = apply_filters( 'dfrpswc_product_instance_type', 'external', $dfr_product, $product_set, $action );

	$wc_product = 'update' == $action
		? wc_get_product( $existing_post['ID'] )
		: dfrpswc_get_product_instance( $dfr_product['_id'], $type );

	if ( ! $wc_product ) {
		return;
	}

	if ( is_wp_error( $wc_product ) ) {
		return;
	}

	$product_handler = new Dfrpswc_Product_Update_Handler( $wc_product, $dfr_product, $product_set, $action );

	$product_handler->update();
}

/**
 * Returns a persisted instance of a WC_Product.
 *
 * @param string $sku This is the Datafeedr Product's "_id" field.
 * @param string $type This is the type of WooCommerce Product we want to create/update.
 *
 * @return WC_Product|WC_Product_External|WP_Error
 */
function dfrpswc_get_product_instance( string $sku, $type = 'external' ) {

	$wc_product_id = absint( wc_get_product_id_by_sku( $sku ) );

	if ( $wc_product_id > 0 ) {
		return wc_get_product( $wc_product_id );
	}

	/** @var WC_Product $product Unsaved instance of WC_Product */
	$product = dfrpswc_get_wc_product_object( $type );

	// We're dealing with a new product, attempt to set the SKU, save and return or return WP_Error.
	try {
		$product->set_sku( wc_clean( $sku ) );
		$product->save();
	} catch ( WC_Data_Exception $e ) {
		return new WP_Error( $e->getErrorCode(), $e->getMessage(), [
			'sku'           => $sku,
			'wc_product_id' => $wc_product_id
		] );
	}

	return $product;
}

/**
 * Return a child class of WC_Product() based on the $type.
 *
 * @param string $type
 *
 * @return WC_Product
 */
function dfrpswc_get_wc_product_object( string $type ) {
	if ( $type === 'variable' ) {
		return new WC_Product_Variable();
	} elseif ( $type === 'grouped' ) {
		return new WC_Product_Grouped();
	} elseif ( $type === 'external' ) {
		return new WC_Product_External();
	} else {
		return new WC_Product_Simple();
	}
}

function dfrpswc_int_to_price_with_two_decimal_places( $price ) {
	$price = intval( $price );

	return number_format( ( $price / 100 ), 2 );
}

/**
 * Returns an array of Product Set IDs responsible for importing this product or an empty array
 * if no Product Set IDs were found.
 *
 * @param int $product_id
 *
 * @return array
 */
function dfrpswc_get_product_set_ids_for_product( int $product_id ) {
	$set_ids = get_post_meta( $product_id, '_dfrps_product_set_id' );

	return array_unique( array_filter( $set_ids ) );
}

/**
 * Returns the post_author field for the Product Set which imported the $product_id
 * or returns 0 if a Product Set is not found.
 *
 * @param int $product_id
 *
 * @return int Author ID or 0 if Product Set was not found.
 */
function dfrpswc_get_post_author_of_product_set_for_product( int $product_id ) {
	$set_ids = dfrpswc_get_product_set_ids_for_product( $product_id );

	return ! empty( $set_ids ) ? absint( get_post_field( 'post_author', $set_ids[0] ) ) : 0;
}
