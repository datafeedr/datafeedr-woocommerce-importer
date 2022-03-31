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

/**
 * Add Datafeedr WooCommerce Impoter Plugin's settings and configuration to the WordPress
 * Site Health Info section (WordPress Admin Area > Tools > Site Health).
 *
 * @return array
 */
add_filter( 'debug_information', function ( $info ) {

	$options = dfrpswc_get_options();

	$info['datafeedr-woocommerce-importer-plugin'] = [
		'label'       => __( 'Datafeedr WooCommerce Importer Plugin', 'dfrpswc_integration' ),
		'description' => '',
		'fields'      => [
			'button_text'  => [
				'label' => __( 'Button Text', 'dfrpswc_integration' ),
				'value' => isset( $options['button_text'] ) && ! empty( $options['button_text'] ) ? $options['button_text'] : '—',
			],
			'format_price' => [
				'label' => __( 'Format Prices', 'dfrpswc_integration' ),
				'value' => isset( $options['format_price'] ) && ! empty( $options['format_price'] ) ? ucfirst( $options['format_price'] ) : '—',
				'debug' => isset( $options['format_price'] ) && ! empty( $options['format_price'] ) ? $options['format_price'] : '—',
			],
		]
	];

	return $info;
} );

/**
 * Customize the featured image data before importing image into Media Library.
 *
 * @param array $args Array of args to use when adding image to Media Library.
 * @param string $url URL of image we are importing into the Media Library.
 * @param WP_Post $post
 *
 * @return array
 */
function dfrpswc_image_import_args( array $args, string $url, WP_Post $post ) {

	$product = wc_get_product( $post->ID );

	if ( ! $product ) {
		return $args;
	}

	$args = [
		'title'             => $product->get_name(),
		'file_name'         => $product->get_name(),
		'post_id'           => $product->get_id(),
		'description'       => $product->get_name(),
		'caption'           => $product->get_name(),
		'alt_text'          => $product->get_name(),
		'user_id'           => dfrpswc_get_post_author_of_product_set_for_product( $product->get_id() ),
		'is_post_thumbnail' => true,
		'timeout'           => 5,
		'_source_plugin'    => 'dfrpswc',
	];

	return apply_filters( 'dfrpswc_image_import_args', $args, $url, $post );
}

add_filter( 'dfrps_import_post_thumbnail/args', 'dfrpswc_image_import_args', 10, 3 );

/**
 * Set the rel attribute for the Buy Button on Single Product Pages.
 *
 * @since 1.2.58
 *
 * @param string $product_type
 *
 * @param string $rel
 *
 * @return string
 */
function dfrpswc_single_product_button_rel( string $rel, string $product_type ) {
	return ( $product_type === 'external' )
		? dfrpswc_get_option( 'rel_single', 'nofollow' )
		: $rel;
}

add_filter( 'dfrpswc_single_product_add_to_cart_button_rel', 'dfrpswc_single_product_button_rel', 10, 2 );

/**
 * Set the rel attribute for the Buy Button in the Loop.
 *
 * @since 1.2.58
 *
 * @param WC_Product $product
 *
 * @param array $args
 *
 * @return array
 */
function dfrpswc_loop_button_rel( array $args, WC_Product $product ) {
	if ( $product->is_type( 'external' ) ) {
		$args['attributes']['rel'] = dfrpswc_get_option( 'rel_loop', 'nofollow' );
	}

	return $args;
}

add_filter( 'woocommerce_loop_add_to_cart_args', 'dfrpswc_loop_button_rel', 10, 2 );

/**
 * Set the target attribute for the Buy Button on Single Product Pages.
 *
 * @since 1.2.58
 *
 * @param string $product_type
 *
 * @param string $rel
 *
 * @return string
 */
function dfrpswc_single_product_button_target( string $target, string $product_type ) {
	return ( $product_type === 'external' )
		? dfrpswc_get_option( 'target_single', '_blank' )
		: $target;
}

add_filter( 'dfrpswc_single_product_add_to_cart_button_target', 'dfrpswc_single_product_button_target', 10, 2 );

/**
 * Set the target attribute for the Buy Button in the Loop.
 *
 * @since 1.2.58
 *
 * @param WC_Product $product
 *
 * @param array $args
 *
 * @return array
 *
 */
function dfrpswc_loop_button_target( array $args, WC_Product $product ) {
	if ( $product->is_type( 'external' ) ) {
		$args['attributes']['target'] = dfrpswc_get_option( 'target_loop', '_blank' );
	}

	return $args;
}

add_filter( 'woocommerce_loop_add_to_cart_args', 'dfrpswc_loop_button_target', 10, 2 );

/**
 * This is really a helper filter to simplify adding custom attributes to WooCommerce Products.
 *
 * @param array $attributes Current product's attributes.
 * @param array $post An array of a Product generated by get_post().
 * @param array $dfr_product An array of product data returned from Datafeedr.
 * @param array $product_set An array of a Product Set generated by get_post().
 * @param string $action The current update action (either "insert" or "update").
 *
 * @return array|string
 */
function dfrpswc_import_custom_attribute( array $attributes, array $post, array $dfr_product, array $product_set, string $action ) {

	$default_custom_attribute = [
		'visible'     => true,
		'override'    => true,
		'concatenate' => false,
		'prefix'      => '',
		'suffix'      => '',
		'default'     => null,
		'position'    => 0,
	];

	$custom_attributes = apply_filters( 'dfrpswc_custom_attributes', [], $post, $dfr_product, $product_set, $action );

	foreach ( $custom_attributes as $custom_attribute ) {

		$label       = $custom_attribute['label'] ?? false;
		$fields      = $custom_attribute['fields'] ?? false;
		$visible     = (bool) ( $custom_attribute['visible'] ?? $default_custom_attribute['visible'] );
		$override    = (bool) ( $custom_attribute['override'] ?? $default_custom_attribute['override'] );
		$concatenate = $custom_attribute['concatenate'] ?? $default_custom_attribute['concatenate'];
		$prefix      = (string) ( $custom_attribute['prefix'] ?? $default_custom_attribute['prefix'] );
		$suffix      = (string) ( $custom_attribute['suffix'] ?? $default_custom_attribute['suffix'] );
		$default     = $custom_attribute['default'] ?? $default_custom_attribute['default'];

		if ( empty( $label ) || empty( $fields ) ) {
			continue;
		}

		if ( $action === 'update' && ! $override ) {
			continue;
		}

//		$value = dfrapi_get_product_fields( $dfr_product, $fields, $default, $concatenate );
		$value = dfrapi_get_fields_from_product( $dfr_product, $fields, $default, $concatenate );

		if ( $value !== null ) {
			$attributes[] = [
				'name'    => $label,
				'value'   => $prefix . $value . $suffix,
				'visible' => $visible,
			];
		}
	}


	return $attributes;
}

add_filter( 'dfrpswc_product_attributes', 'dfrpswc_import_custom_attribute', 10, 5 );