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

/**
 * This formats the price for WooCommerce products dependent on its currency code.
 *
 * The format used is from WooCommerce 4.9.1.
 *
 * The order of the {symbol} and the {price} is determined by the format
 * set in the Dfrapi_Currency class therefore the formats in the 2 examples
 * below might not be exactly what is output (symbol and price could be in
 * a different order and there might be differences in spacing).
 *
 * ------------ NOT ON SALE FORMAT ------------
 *
 *      <span class="woocommerce-Price-amount amount">
 *          <bdi>
 *              <span class="woocommerce-Price-currencySymbol">
 *                  {symbol}
 *              </span>
 *              {price}
 *          </bdi>
 *      </span>
 *
 * ------------ ON SALE FORMAT ------------
 *
 *      <del>
 *          <span class="woocommerce-Price-amount amount">
 *              <bdi>
 *                  <span class="woocommerce-Price-currencySymbol">
 *                      {symbol}
 *                  </span>
 *                  {price}
 *              </bdi>
 *          </span>
 *      </del>
 *      <ins>
 *          <span class="woocommerce-Price-amount amount">
 *              <bdi>
 *                  <span class="woocommerce-Price-currencySymbol">
 *                      {symbol}
 *                  </span>
 *                  {price}
 *              </bdi>
 *          </span>
 *      </ins>
 *
 * @param string $price
 * @param WC_Product $product
 *
 * @return string
 */
function dfrpswc_woocommerce_get_price_html( string $price, WC_Product $product ) {

	// If the "format_price" option is not set to "yes", return $price argument.
	$options = dfrpswc_get_options();
	if ( $options['format_price'] !== 'yes' ) {
		return $price;
	}

	// If there is no price, return $price argument.
	if ( '' === $product->get_price() ) {
		return $price;
	}

	// If there is no currency code, return $price argument.
	if ( ! $currency = dfrps_get_product_field( $product->get_id(), 'currency' ) ) {
		return $price;
	}

	// Formats to use in sprintf() calls to generate HTML.
	$sale_format    = apply_filters( 'dfrpswc_woocommerce_get_price_html_sale_format', '<del>%s</del> <ins>%s</ins>' );
	$wrapper_format = apply_filters( 'dfrpswc_woocommerce_get_price_html_wrapper_format', '<span class="woocommerce-Price-amount amount"><bdi>%s</bdi></span>' );
	$symbol_format  = apply_filters( 'dfrpswc_woocommerce_get_price_html_symbol_format', '<span class="woocommerce-Price-currencySymbol">%s</span>' );
	$price_format   = apply_filters( 'dfrpswc_woocommerce_get_price_html_price_format', '%s' );

	// Get a Dfrapi_Price object for this $product's regular price.
	$regular_price = dfrapi_price( $product->get_regular_price(), $currency, 'woocommerce_get_price_html' );

	// Format the regular price in the necessary HTML.
	$formatted_regular_price = sprintf( $wrapper_format, strtr( $regular_price->get_signed_format(), [
		'{symbol}' => sprintf( $symbol_format, $regular_price->currency->get_currency_symbol() ),
		'{price}'  => sprintf( $price_format, $regular_price->get_formatted_number() ),
	] ) );

	if ( $product->is_on_sale() ) {

		// Get a Dfrapi_Price object for this $product's sale price.
		$sale_price = dfrapi_price( $product->get_sale_price(), $currency, 'woocommerce_get_price_html' );

		// Format the sale price in the necessary HTML.
		$formatted_sale_price = sprintf( $wrapper_format, strtr( $sale_price->get_signed_format(), [
			'{symbol}' => sprintf( $symbol_format, $sale_price->currency->get_currency_symbol() ),
			'{price}'  => sprintf( $price_format, $sale_price->get_formatted_number() ),
		] ) );

		// Return the regular and sale prices HTML in the $sale_format.
		return sprintf( $sale_format, $formatted_regular_price, $formatted_sale_price );
	}

	// Return the regular price HTML.
	return $formatted_regular_price;

}

add_filter( 'woocommerce_get_price_html', 'dfrpswc_woocommerce_get_price_html', 11, 2 );
