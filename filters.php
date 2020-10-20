<?php

/**
 * Exit if accessed directly
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This filter takes the current WooCommerce Product, finds other Product Sets that
 * it might be associated with and adds those Product Sets' category IDs to this
 * product's array of $category_ids.
 *
 * @param array $category_ids
 * @param Dfrpswc_Product_Update_Handler $updater
 *
 * @return array
 */
function dfrpswc_append_category_ids_from_other_product_sets( array $category_ids, Dfrpswc_Product_Update_Handler $updater ) {

	$product_set_ids = get_post_meta( $updater->wc_product->get_id(), '_dfrps_product_set_id', false );

	if ( ! $product_set_ids ) {
		return $category_ids;
	}

	foreach ( $product_set_ids as $product_set_id ) {
		$category_ids = array_merge( $category_ids, dfrps_get_cpt_terms( $product_set_id ) );
	}

	return $category_ids;
}

add_filter( 'dfrpswc_product_cat_category_ids', 'dfrpswc_append_category_ids_from_other_product_sets', 10, 2 );
