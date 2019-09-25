<?php
/*
Plugin Name: Datafeedr WooCommerce Importer
Plugin URI: https://www.datafeedr.com
Description: Import products from the Datafeedr Product Sets plugin into your WooCommerce store. <strong>REQUIRES: </strong><a href="http://wordpress.org/plugins/datafeedr-api/">Datafeedr API plugin</a>, <a href="http://wordpress.org/plugins/datafeedr-product-sets/">Datafeedr Product Sets plugin</a>, <a href="http://wordpress.org/plugins/woocommerce/">WooCommerce</a> (v3.0+).
Author: datafeedr.com
Author URI: https://www.datafeedr.com
License: GPL v3
Requires at least: 3.8
Tested up to: 5.2.3
Version: 1.2.40

WC requires at least: 3.0
WC tested up to: 3.7.0

Datafeedr WooCommerce Importer plugin
Copyright (C) 2019, Datafeedr - help@datafeedr.com

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * Exit if accessed directly
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define constants.
 */
define( 'DFRPSWC_VERSION', '1.2.40' );
define( 'DFRPSWC_DB_VERSION', '1.2.0' );
define( 'DFRPSWC_URL', plugin_dir_url( __FILE__ ) );
define( 'DFRPSWC_PATH', plugin_dir_path( __FILE__ ) );
define( 'DFRPSWC_BASENAME', plugin_basename( __FILE__ ) );
define( 'DFRPSWC_DOMAIN', 'dfrpswc_integration' );
define( 'DFRPSWC_POST_TYPE', 'product' );
define( 'DFRPSWC_TAXONOMY', 'product_cat' );
define( 'DFRPSWC_CONTACT', 'https://www.datafeedr.com/contact' );

/**
 * Load upgrade file.
 */
require_once( DFRPSWC_PATH . 'upgrade.php' );
require_once( DFRPSWC_PATH . 'class-dfrpswc-plugin-dependency.php' );
require_once( DFRPSWC_PATH . 'class-dfrpswc-attribute-importer.php' );


/*******************************************************************
 * ADMIN NOTICES
 *******************************************************************/

/**
 * Display admin notices for each required plugin that needs to be
 * installed, activated and/or updated.
 *
 * @since 1.2.17
 */
function dfrpswc_admin_notice_plugin_dependencies() {

	/**
	 * @var Dfrpswc_Plugin_Dependency[] $dependencies
	 */
	$dependencies = array(
		new Dfrpswc_Plugin_Dependency( 'Datafeedr API', 'datafeedr-api/datafeedr-api.php', '1.0.75' ),
		new Dfrpswc_Plugin_Dependency( 'Datafeedr Product Sets', 'datafeedr-product-sets/datafeedr-product-sets.php',
			'1.2.24' ),
		new Dfrpswc_Plugin_Dependency( 'WooCommerce', 'woocommerce/woocommerce.php', '3.0' ),
	);

	foreach ( $dependencies as $dependency ) {

		$action = $dependency->action_required();

		if ( ! $action ) {
			continue;
		}

		echo '<div class="notice notice-error"><p>';
		echo $dependency->msg( 'Datafeedr WooCommerce Importer' );
		echo $dependency->link();
		echo '</p></div>';
	}
}

add_action( 'admin_notices', 'dfrpswc_admin_notice_plugin_dependencies' );

/**
 * Display admin notices upon update.
 */
add_action( 'admin_notices', 'dfrpswc_settings_updated' );
function dfrpswc_settings_updated() {
	if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] == true && isset( $_GET['page'] ) && 'dfrpswc_options' == $_GET['page'] ) {
		echo '<div class="updated">';
		_e( 'Configuration successfully updated!', DFRPSWC_DOMAIN );
		echo '</div>';
	}
}

/**
 * Notify user that their version of DFRPSWC is not compatible with their version of DFRPS.
 */
add_action( 'admin_notices', 'dfrpswc_not_compatible_with_dfrps' );
function dfrpswc_not_compatible_with_dfrps() {
	if ( defined( 'DFRPS_VERSION' ) ) {
		if ( version_compare( DFRPS_VERSION, '1.2.0', '<' ) ) {

			// Disable updates!
			$dfrps_configuration                    = get_option( 'dfrps_configuration' );
			$dfrps_configuration['updates_enabled'] = 'disabled';
			update_option( 'dfrps_configuration', $dfrps_configuration );

			$file = 'datafeedr-product-sets/datafeedr-product-sets.php';
			$url  = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . $file,
				'upgrade-plugin_' . $file );

			?>

            <div class="error">
                <p>
                    <strong style="color:#E44532;"><?php _e( 'URGENT - ACTION REQUIRED!', DFRPSWC_DOMAIN ); ?></strong>

                    <br/>

					<?php
					_e(
						'Your version of the <strong><em>Datafeedr Product Sets</em></strong> plugin is not compatible with your version of the <strong><em>Datafeedr WooCommerce Importer</em></strong> plugin.',
						'dfrpswc_integration'
					);
					?>

                    <br/>

					<?php
					_e( 'Failure to upgrade will result in data loss. Please update your version of the <strong><em>Datafeedr Product Sets</em></strong> plugin now.',
						'dfrpswc_integration'
					);
					?>

                    <br/>

                    <a class="button button-primary button-large" style="margin-top: 6px" href="<?php echo $url; ?>">
						<?php _e( 'Update Now', 'dfrpswc_integration' ); ?>
                    </a>
                </p>
            </div>

			<?php
		}
	}
}


/*******************************************************************
 * REGISTER CUSTOM POST TYPE FOR PRODUCT SETS
 *******************************************************************/

/**
 * This registers the third party integration's Custom
 * Post Type with the Datafeedr Product Sets plugin.
 */
add_action( 'init', 'dfrpswc_register_cpt' );
function dfrpswc_register_cpt() {
	if ( function_exists( 'dfrps_register_cpt' ) ) {
		$args = array(
			'taxonomy'         => DFRPSWC_TAXONOMY,
			'name'             => _x( 'WooCommerce Products', DFRPSWC_DOMAIN ),
			'tax_name'         => _x( 'WooCommerce Categories', DFRPSWC_DOMAIN ),
			'tax_instructions' => _x( 'Add this Product Set to a Product Category.', DFRPSWC_DOMAIN ),
		);
		dfrps_register_cpt( DFRPSWC_POST_TYPE, $args );
	}
}

/**
 * This unregisters the third party integration's Custom
 * Post Type from the Datafeedr Product Sets plugin. This
 * must be unregistered using the register_deactivation_hook()
 * hook.
 */
register_deactivation_hook( __FILE__, 'dfrpswc_unregister_cpt' );
function dfrpswc_unregister_cpt() {
	if ( function_exists( 'dfrps_unregister_cpt' ) ) {
		dfrps_unregister_cpt( DFRPSWC_POST_TYPE );
	}
}


/*******************************************************************
 * BUILD ADMIN OPTIONS PAGE
 *******************************************************************/

/**
 * Add settings page.
 */
add_action( 'admin_menu', 'dfrpswc_admin_menu', 999 );
function dfrpswc_admin_menu() {

	add_submenu_page(
		'dfrps',
		__( 'Options &#8212; Datafeedr WooCommerce Importer', DFRPSWC_DOMAIN ),
		__( 'WC Importer', DFRPSWC_DOMAIN ),
		'manage_options',
		'dfrpswc_options',
		'dfrpswc_options_output'
	);
}

/**
 * Get current options or set default ones.
 */
function dfrpswc_get_options() {
	$options = get_option( 'dfrpswc_options', array() );
	if ( empty( $options ) ) {
		$options                = array();
		$options['button_text'] = __( 'Buy Now', DFRPSWC_DOMAIN );
		update_option( 'dfrpswc_options', $options );
	}

	return $options;
}

/**
 * Build settings page.
 */
function dfrpswc_options_output() {
	echo '<div class="wrap" id="dfrpswc_options">';
	echo '<h2>' . __( 'Options &#8212; Datafeedr WooCommerce Importer', DFRPSWC_DOMAIN ) . '</h2>';
	echo '<form method="post" action="options.php">';
	wp_nonce_field( 'dfrpswc-update-options' );
	settings_fields( 'dfrpswc_options-page' );
	do_settings_sections( 'dfrpswc_options-page' );
	submit_button();
	echo '</form>';
	echo '</div>';
}

/**
 * Register settings.
 */
add_action( 'admin_init', 'dfrpswc_register_settings' );
function dfrpswc_register_settings() {

	register_setting( 'dfrpswc_options-page', 'dfrpswc_options', 'dfrpswc_validate' );

	add_settings_section(
		'dfrpswc_general_settings',
		__( 'General Settings', 'dfrpswc_integration' ),
		'dfrpswc_general_settings_section',
		'dfrpswc_options-page'
	);

	add_settings_field(
		'dfrpswc_button_text',
		__( 'Button Text', 'dfrpswc_integration' ),
		'dfrpswc_button_text_field',
		'dfrpswc_options-page',
		'dfrpswc_general_settings'
	);
}

/**
 * General settings section description.
 */
function dfrpswc_general_settings_section() {
	//echo __( 'General settings for importing products into your WooCommerce store.', DFRPSWC_DOMAIN );
}

/**
 * Button Text field.
 */
function dfrpswc_button_text_field() {
	$options = dfrpswc_get_options();
	echo '<input type="text" class="regular-text" name="dfrpswc_options[button_text]" value="' . esc_attr( $options['button_text'] ) . '" />';
	echo '<p class="description">';
	echo __( 'The text on the button which links to the merchant\'s website.', 'dfrpswc_integration' );
	echo '</p>';
}

/**
 * Validate user's input and save.
 */
function dfrpswc_validate( $input ) {
	if ( ! isset( $input ) || ! is_array( $input ) || empty( $input ) ) {
		return $input;
	}

	$new_input = array();
	foreach ( $input as $key => $value ) {
		if ( $key == 'button_text' ) {
			$new_input['button_text'] = trim( $value );
		}
	}

	return $new_input;
}

/**
 * Change Button Text for DFRPSWC imported products.
 *
 * @param string $button_text
 * @param WC_Product $product
 *
 * @return string
 */
function dfrpswc_single_add_to_cart_text( $button_text, $product ) {

	if ( $product->get_type() != 'external' ) {
		return $button_text;
	}

	if ( ! dfrpswc_is_dfrpswc_product( $product->get_id() ) ) {
		return $button_text;
	}

	$options = dfrpswc_get_options();

	if ( $options['button_text'] != '' ) {
		$button_text = $options['button_text'];
	}

	return $button_text;
}

add_filter( 'woocommerce_product_add_to_cart_text', 'dfrpswc_single_add_to_cart_text', 10, 2 );
add_filter( 'woocommerce_product_single_add_to_cart_text', 'dfrpswc_single_add_to_cart_text', 10, 2 );


/*******************************************************************
 * UPDATE FUNCTIONS
 *******************************************************************/

/**
 *
 * This unsets products from their categories before updating products.
 *
 * Why?
 *
 * We need to remove all products which were imported via a product set
 * from the categories they were added to when they were imported
 * so that at the end of the update, if these products weren't re-imported
 * during the update, the post/product's category information (for this
 * set) will no longer be available so that if this post/product was
 * added via another Product Set, only that Product Set's category IDs
 * will be attributed to this post/product.
 *
 * This processes batches at a time as this is a server/time
 * intensive process.
 *
 * @param object $obj This is the entire "Update" object from Dfrps_Update().
 */
add_action( 'dfrps_preprocess-' . DFRPSWC_POST_TYPE, 'dfrpswc_unset_post_categories' );
function dfrpswc_unset_post_categories( $obj ) {

	global $wpdb;

	/**
	 * Here is the process of this function:
	 *
	 * 1. Check if 'dfrpswc_temp_post_ids_by_set_id' table exists. If table does not exist, that means
	 *    we have not run the 'dfrpswc_unset_post_categories' action yet.
	 * 2. If table does not exist:
	 *      - Create temp table.
	 *      - Insert post IDs into table.
	 * 3. Get $config['preprocess_maximum'] ($limit) number of records.
	 * 4. Loop through records and process them.
	 * 5. Delete those ($limit) number of records from table.
	 * 6. If 0 records remain to be processed:
	 *      - Update_post_meta( $obj->set['ID'], '_dfrps_preprocess_complete_' . DFRPSWC_POST_TYPE, true );
	 *      - Delete temp table.
	 */

	/**
	 * Check if 'dfrpswc_temp_post_ids_by_set_id' table exists. If it does not exist:
	 *  - Create the table.
	 *  - Query all post IDs for this Set ID.
	 *  - Insert all post IDs into table.
	 */
	$table_name = $wpdb->prefix . 'dfrpswc_temp_post_ids_by_set_id';
	$query      = $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name );
	if ( $wpdb->get_var( $query ) != $table_name ) {

		// Create the temp table to store the post IDs.
		dfrpswc_create_temp_post_ids_table( $table_name );

		// Get all post IDs (as an array) set by this Product Set
		$ids = dfrps_get_all_post_ids_by_set_id( $obj->set['ID'] );

		dfrpswc_insert_ids_into_temp_table( $ids, $table_name );

	}

	/**
	 * Get X ($limit) number of records to process where X is $config['preprocess_maximum'].
	 * Also get the $total number of posts in this table. This will be used to
	 * determine if we must repeat the 'dfrps_preprocess' action or not.
	 *
	 * The uniqid() part is related to ticket #10866 .
	 */
	$config = (array) get_option( 'dfrps_configuration' );
	$limit  = ( isset( $config['preprocess_maximum'] ) ) ? intval( $config['preprocess_maximum'] ) : 100;

	$uid = uniqid();
	$wpdb->query( "UPDATE $table_name SET uid='$uid' WHERE uid='' ORDER BY post_id ASC LIMIT " . $limit );

	$sql   = "SELECT post_id FROM $table_name WHERE uid='$uid' ORDER BY post_id ASC";
	$posts = $wpdb->get_results( $sql, OBJECT );

	/**
	 * If $posts is empty, then:
	 *  - Set _dfrps_preprocess_complete_ to true.
	 *  - DROP the new table.
	 *  - return.
	 */
	if ( ! $posts ) {
		update_post_meta( $obj->set['ID'], '_dfrps_preprocess_complete_' . DFRPSWC_POST_TYPE, true );
		dfrpswc_drop_temp_post_ids_table( $table_name );

		return true;
	}

	/**
	 * If $posts contains post IDs, we will grab the first X ($limit) number of
	 * IDs from the array (where X is "preprocess_maximum") and get all
	 * term_ids that the product is associated with from other Product Sets.
	 *
	 * Then we will have an array of term_ids that this product belongs to
	 * except the term_ids that this Product Set is responsible for adding.
	 *
	 * Why?
	 *
	 * Let's say we have the following situation:
	 *
	 * SET A adds PRODUCT 1 to CATEGORY X
	 * SET B adds PRODUCT 1 to CATEGORY X
	 *
	 * What happens when SET A removes PRODUCT 1 from CATEGORY X?
	 *
	 * We need to make sure that PRODUCT 1 remains in CATEGORY X. By getting
	 * term_ids from all other Sets that added this product, we will keep
	 * PRODUCT 1 in CATEGORY X.
	 */
	foreach ( $posts as $post ) {
		$post_id = intval( $post->post_id );
		wp_remove_object_terms( $post_id, dfrps_get_cpt_terms( $obj->set['ID'] ), DFRPSWC_TAXONOMY );
		delete_post_meta( $post_id, '_dfrps_product_set_id', $obj->set['ID'] );
	}

	/**
	 * Now we delete this set of post IDs from the table. This ensures
	 * that we don't process them again.
	 */
	$wpdb->query( "DELETE FROM $table_name WHERE uid='$uid'" );

}

/**
 * Adds the action "dfrps_action_do_products_{cpt}" where
 * {cpt} is the post_type you are inserting products into.
 */
add_action( 'dfrps_action_do_products_' . DFRPSWC_POST_TYPE, 'dfrpswc_do_products', 10, 2 );
function dfrpswc_do_products( $data, $set ) {

	// Check if there are products available.
	if ( ! isset( $data['products'] ) || empty( $data['products'] ) ) {
		return;
	}

	// Loop thru products.
	foreach ( $data['products'] as $product ) {

		// Get post if it already exists.
		$existing_post = dfrps_get_existing_post( $product, $set );

		// Disable W3TC's caching while processing products.
		add_filter( 'w3tc_flushable_post', '__return_false', 20, 3 );

		// Determine what to do based on if post exists or not.
		if ( $existing_post && $existing_post['post_type'] == DFRPSWC_POST_TYPE ) {
			$action = 'update';
			$post   = dfrpswc_update_post( $existing_post, $product, $set, $action );
		} else {
			$action = 'insert';
			$post   = dfrpswc_insert_post( $product, $set, $action );
		}

		// Handle other facets for this product such as postmeta, terms and attributes.
		if ( $post ) {
			dfrpswc_update_postmeta( $post, $product, $set, $action );
			dfrpswc_update_terms( $post, $product, $set, $action );
			dfrpswc_update_attributes( $post, $product, $set, $action );
			do_action( 'dfrpswc_do_product', $post, $product, $set, $action );
		}

	}
}

/**
 * This updates a post.
 *
 * This should return a FULL $post object in ARRAY_A format.
 */
function dfrpswc_update_post( $existing_post, $product, $set, $action ) {

	$post = array(
		'ID'           => $existing_post['ID'],
		'post_title'   => isset( $product['name'] ) ? $product['name'] : '',
		'post_content' => isset( $product['description'] ) ? $product['description'] : '',
		'post_excerpt' => isset( $product['shortdescription'] ) ? $product['shortdescription'] : '',
		'post_status'  => 'publish',
	);

	/**
	 * Allow the $post array to be modified before updating.
	 *
	 * Hook into this filter to change any $post related information before it's  updated.
	 * Useful for changing the post_status of a product or modifying its name
	 * or description before persisting.
	 *
	 * @since 0.9.3
	 *
	 * @param array $post Array containing WordPress Post information.
	 * @param array $product Array containing Datafeedr Product information.
	 * @param array $set Array containing Product Set information.
	 * @param string $action Either "update" or "insert" depending on what the Product Set is doing.
	 */
	$post = apply_filters( 'dfrpswc_filter_post_array', $post, $product, $set, $action );

	wp_update_post( $post );

	return $post;
}

/**
 * This inserts a new post.
 *
 * This should return a FULL $post object in ARRAY_A format.
 */
function dfrpswc_insert_post( $product, $set, $action ) {

	$post = array(
		'post_title'   => isset( $product['name'] ) ? $product['name'] : '',
		'post_content' => isset( $product['description'] ) ? $product['description'] : '',
		'post_excerpt' => isset( $product['shortdescription'] ) ? $product['shortdescription'] : '',
		'post_status'  => 'publish',
		'post_author'  => $set['post_author'],
		'post_type'    => DFRPSWC_POST_TYPE,
	);

	/**
	 * Allow the $post array to be modified before saving.
	 *
	 * Hook into this filter to change any $post related information before it's saved.
	 * Useful for changing the post_status of a product or modifying its name
	 * or description before persisting.
	 *
	 * @since 0.9.3
	 *
	 * @param array $post Array containing WordPress Post information.
	 * @param array $product Array containing Datafeedr Product information.
	 * @param array $set Array containing Product Set information.
	 * @param string $action Either "update" or "insert" depending on what the Product Set is doing.
	 */
	$post = apply_filters( 'dfrpswc_filter_post_array', $post, $product, $set, $action );

	$id         = wp_insert_post( $post );
	$post['ID'] = $id;

	return $post;
}

/**
 * Update the postmeta for this product.
 */
function dfrpswc_update_postmeta( $post, $product, $set, $action ) {

	$meta = array();

	$meta['_visibility']               = 'visible';
	$meta['_stock']                    = '';
	$meta['_downloadable']             = 'no';
	$meta['_virtual']                  = 'no';
	$meta['_backorders']               = 'no';
	$meta['_stock_status']             = 'instock';
	$meta['_product_type']             = 'external';
	$meta['_product_url']              = $product['url'];
	$meta['_sku']                      = $product['_id'];
	$meta['_dfrps_is_dfrps_product']   = true;
	$meta['_dfrps_is_dfrpswc_product'] = true;
	$meta['_dfrps_product_id']         = $product['_id'];
	$meta['_dfrps_product']            = $product; // This stores all info about the product in 1 array.

	// Update image check field.
	$meta['_dfrps_product_check_image'] = 1;

	// Set featured image url (if there's an image)
	if ( @$product['image'] != '' ) {
		$meta['_dfrps_featured_image_url'] = @$product['image'];
	} elseif ( @$product['thumbnail'] != '' ) {
		$meta['_dfrps_featured_image_url'] = @$product['thumbnail'];
	}

	// Get highest and lowest price for this product.
	$highest_price = ( isset( $product['price'] ) ) ? absint( $product['price'] ) : 0;
	$lowest_price  = ( isset( $product['finalprice'] ) ) ? absint( $product['finalprice'] ) : $highest_price;

	// Handle regular price.
	if ( $highest_price > 0 ) {
		$meta['_regular_price'] = dfrps_int_to_price( $highest_price );
		$meta['_price']         = dfrps_int_to_price( $highest_price );
	} else {
		$meta['_regular_price'] = '';
		$meta['_price']         = '';
	}

	// Handle sale price.
	if ( $highest_price > $lowest_price ) {
		$meta['_sale_price'] = dfrps_int_to_price( $lowest_price );
		$meta['_price']      = dfrps_int_to_price( $lowest_price );
	} else {
		$meta['_sale_price'] = '';
	}

	// Handle sale discount.
	$meta['_dfrps_salediscount'] = ( isset( $product['salediscount'] ) ) ? $product['salediscount'] : 0;

	/**
	 * Allow the $meta array to be modified before saving/updating.
	 *
	 * Hook into this filter to change any postmeta related information before it's saved or updated.
	 * Useful for modifying pricing information or other product related information.
	 *
	 * @since 0.9.3
	 *
	 * @param array $meta Array containing postmeta data for this WordPress $post.
	 * @param array $post Array containing WordPress Post information.
	 * @param array $product Array containing Datafeedr Product information.
	 * @param array $set Array containing Product Set information.
	 * @param string $action Either "update" or "insert" depending on what the Product Set is doing.
	 */
	$meta = apply_filters( 'dfrpswc_filter_postmeta_array', $meta, $post, $product, $set, $action );

	foreach ( $meta as $meta_key => $meta_value ) {
		update_post_meta( $post['ID'], $meta_key, $meta_value );
	}

	add_post_meta( $post['ID'], '_dfrps_product_set_id', $set['ID'] );
	add_post_meta( $post['ID'], 'total_sales', '0', true );
}

/**
 * Update the terms/taxonomy for this product.
 */
function dfrpswc_update_terms( $post, $product, $set, $action ) {

	// Get the IDs of the categories this product is associated with.
	$terms = dfrpswc_get_all_term_ids_for_product( $post, $set );

	// Create an array with key of taxonomy and values of terms
	$taxonomies = array(
		DFRPSWC_TAXONOMY => $terms,
		'product_tag'    => '',
		'product_type'   => 'external',
	);

	/**
	 * Allow the $taxonomies array to be modified before saving/updating.
	 *
	 * Hook into this filter to change any $taxonomies related information before it's saved or updated.
	 *
	 * @since 0.9.3
	 *
	 * @param array $taxonomies Array keyed by taxonomy name and having values of taxonomy values.
	 * @param array $post Array containing WordPress Post information.
	 * @param array $product Array containing Datafeedr Product information.
	 * @param array $set Array containing Product Set information.
	 * @param string $action Either "update" or "insert" depending on what the Product Set is doing.
	 */
	$taxonomies = apply_filters( 'dfrpswc_filter_taxonomy_array', $taxonomies, $post, $product, $set, $action );

	// Remove 'product_tag' from array if value is empty.
	if ( empty( $taxonomies['product_tag'] ) ) {
		unset( $taxonomies['product_tag'] );
	}

	// Then iterate over the array using wp_set_post_terms()
	foreach ( $taxonomies as $taxonomy => $terms ) {
		wp_set_post_terms( $post['ID'], $terms, $taxonomy, false );
	}
}

/**
 * Get all term IDs associated with a specific Product Set.
 *
 * @since 1.2.22
 *
 * @param array $post Array containing WordPress Post information.
 * @param array $set Array containing Product Set information.
 *
 * @return array
 */
function dfrpswc_get_all_term_ids_for_product( $post, $set ) {

	$terms = [];

	// Get all Product Set IDs which added this product. This returns an array of Product Set IDs.
	$product_set_ids = get_post_meta( $post['ID'], '_dfrps_product_set_id', false );

	if ( ! isset( $product_set_ids ) || empty( $product_set_ids ) ) {
		return $terms;
	}

	foreach ( $product_set_ids as $product_set_id ) {
		$terms = array_merge( $terms, dfrps_get_cpt_terms( $product_set_id ) );
	}

	$terms = array_map( 'intval', $terms ); // Make sure these $terms are integers
	$terms = array_unique( $terms );

	return $terms;
}

/**
 * Update the attributes (unique to WC) for this product.
 * Most code from:
 * ~/wp-content/plugins/woocommerce/includes/admin/post-types/meta-boxes/class-wc-meta-box-product-data.php (Line #397)
 */
function dfrpswc_update_attributes( $post, $product, $set, $action ) {

	$attrs = array();

	// Array of defined attribute taxonomies
	$attribute_taxonomies = wc_get_attribute_taxonomies();

	// Product attributes - taxonomies and custom, ordered, with visibility and variation attributes set
	$attributes = maybe_unserialize( get_post_meta( $post['ID'], '_product_attributes', true ) );

	/**
	 * Allow the $attributes array to be modified before saving/updating.
	 *
	 * @since 0.9.3
	 *
	 * @param array $attributes Array or attribute values.
	 * @param array $post Array containing WordPress Post information.
	 * @param array $product Array containing Datafeedr Product information.
	 * @param array $set Array containing Product Set information.
	 * @param string $action Either "update" or "insert" depending on what the Product Set is doing.
	 */
	$attributes = apply_filters( 'dfrpswc_product_attributes', $attributes, $post, $product, $set, $action );

	$i = - 1;

	// Taxonomies (attributes)
	if ( $attribute_taxonomies ) {

		foreach ( $attribute_taxonomies as $tax ) {

			// Get name of taxonomy we're now outputting (pa_xxx)
			$attribute_taxonomy_name = wc_attribute_taxonomy_name( $tax->attribute_name );

			// Ensure it exists
			if ( ! taxonomy_exists( $attribute_taxonomy_name ) ) {
				continue;
			}

			$i ++;

			// Get product data values for current taxonomy - this contains ordering and visibility data
			if ( isset( $attributes[ sanitize_title( $attribute_taxonomy_name ) ] ) ) {
				$attribute = $attributes[ sanitize_title( $attribute_taxonomy_name ) ];
			}

			$position   = empty( $attribute['position'] ) ? 0 : absint( $attribute['position'] );
			$visibility = 1;
			$variation  = 0;

			// Get terms of this taxonomy associated with current product
			$post_terms = wp_get_post_terms( $post['ID'], $attribute_taxonomy_name );

			if ( $post_terms ) {
				$value = array();
				foreach ( $post_terms as $term ) {
					$value[] = $term->slug;
				}
			} else {
				$value = '';
			}

			$attrs['attribute_names'][ $i ]       = $attribute_taxonomy_name;
			$attrs['attribute_is_taxonomy'][ $i ] = 1;

			$attrs['attribute_values'][ $i ] = apply_filters(
				'dfrpswc_filter_attribute_value',
				$value,
				$attribute_taxonomy_name,
				$post,
				$product,
				$set,
				$action
			);

			$attrs['attribute_position'][ $i ] = apply_filters(
				'dfrpswc_filter_attribute_position',
				$position,
				$attribute_taxonomy_name,
				$post,
				$product,
				$set,
				$action
			);

			$attrs['attribute_visibility'][ $i ] = apply_filters(
				'dfrpswc_filter_attribute_visibility',
				$visibility,
				$attribute_taxonomy_name,
				$post,
				$product,
				$set,
				$action
			);

			$attrs['attribute_variation'][ $i ] = apply_filters(
				'dfrpswc_filter_attribute_variation',
				$variation,
				$attribute_taxonomy_name,
				$post,
				$product,
				$set,
				$action
			);

		} // foreach ( $attribute_taxonomies as $tax ) {

	} // if ( $attribute_taxonomies ) {

	// Custom Attributes
	if ( ! empty( $attributes ) ) {

		foreach ( $attributes as $attribute ) {

			if ( isset( $attribute['is_taxonomy'] ) && 1 == intval( $attribute['is_taxonomy'] ) ) {
				continue;
			}

			$i ++;

			$attribute_name = $attribute['name'];

			$position   = empty( $attribute['position'] ) ? 0 : absint( $attribute['position'] );
			$visibility = 1;
			$variation  = 0;

			// Get value.
			$value = ( isset( $attribute['value'] ) ) ? $attribute['value'] : '';

			$attrs['attribute_names'][ $i ]       = $attribute_name;
			$attrs['attribute_is_taxonomy'][ $i ] = 0;

			$attrs['attribute_values'][ $i ] = apply_filters(
				'dfrpswc_filter_attribute_value',
				$value,
				$attribute_name,
				$post,
				$product,
				$set,
				$action
			);

			$attrs['attribute_position'][ $i ] = apply_filters(
				'dfrpswc_filter_attribute_position',
				$position,
				$attribute_name,
				$post,
				$product,
				$set,
				$action
			);

			$attrs['attribute_visibility'][ $i ] = apply_filters(
				'dfrpswc_filter_attribute_visibility',
				$visibility,
				$attribute_name,
				$post,
				$product,
				$set,
				$action
			);

			$attrs['attribute_variation'][ $i ] = apply_filters(
				'dfrpswc_filter_attribute_variation',
				$variation,
				$attribute_name,
				$post,
				$product,
				$set,
				$action
			);

		} // foreach ( $attributes as $attribute ) {

	} // if ( ! empty( $attributes ) ) {

	/**
	 * Allow the $attrs array to be modified before saving/updating.
	 *
	 * @since 0.9.3
	 *
	 * @param array $attrs Array or attribute values.
	 * @param array $post Array containing WordPress Post information.
	 * @param array $product Array containing Datafeedr Product information.
	 * @param array $set Array containing Product Set information.
	 * @param string $action Either "update" or "insert" depending on what the Product Set is doing.
	 */
	$attrs = apply_filters( 'dfrpswc_pre_save_attributes', $attrs, $post, $product, $set, $action );

	// Save Attributes
	dfrpswc_save_attributes( $post['ID'], $attrs );
}

/**
 * Add network attribute.
 */
add_filter( 'dfrpswc_filter_attribute_value', 'dfrpswc_add_network_attribute', 10, 6 );
function dfrpswc_add_network_attribute( $value, $attribute, $post, $product, $set, $action ) {
	if ( $attribute == 'pa_network' ) {
		$value = $product['source'];
	}

	return $value;
}

/**
 * Set "position" of network attribute.
 */
add_filter( 'dfrpswc_filter_attribute_position', 'dfrpswc_set_network_attribute_position', 10, 6 );
function dfrpswc_set_network_attribute_position( $position, $attribute, $post, $product, $set, $action ) {
	if ( $attribute == 'pa_network' ) {
		$position = 1;
	}

	return $position;
}

/**
 * Set "visibility" of network attribute to hidden (0).
 */
add_filter( 'dfrpswc_filter_attribute_visibility', 'dfrpswc_hide_network_attribute', 10, 6 );
function dfrpswc_hide_network_attribute( $visibility, $attribute, $post, $product, $set, $action ) {
	if ( $attribute == 'pa_network' ) {
		$visibility = 0;
	}

	return $visibility;
}

/**
 * Add merchant attribute.
 */
add_filter( 'dfrpswc_filter_attribute_value', 'dfrpswc_add_merchant_attribute', 10, 6 );
function dfrpswc_add_merchant_attribute( $value, $attribute, $post, $product, $set, $action ) {
	if ( $attribute == 'pa_merchant' ) {
		$value = $product['merchant'];
	}

	return $value;
}

/**
 * Set "position" of merchant attribute.
 */
add_filter( 'dfrpswc_filter_attribute_position', 'dfrpswc_set_merchant_attribute_position', 10, 6 );
function dfrpswc_set_merchant_attribute_position( $position, $attribute, $post, $product, $set, $action ) {
	if ( $attribute == 'pa_merchant' ) {
		$position = 2;
	}

	return $position;
}

/**
 * Add brand attribute.
 */
add_filter( 'dfrpswc_filter_attribute_value', 'dfrpswc_add_brand_attribute', 10, 6 );
function dfrpswc_add_brand_attribute( $value, $attribute, $post, $product, $set, $action ) {
	if ( $attribute == 'pa_brand' ) {
		if ( isset( $product['brand'] ) ) {
			$value = $product['brand'];
		}
	}

	return $value;
}

/**
 * Set "position" of brand attribute.
 */
add_filter( 'dfrpswc_filter_attribute_position', 'dfrpswc_set_brand_attribute_position', 10, 6 );
function dfrpswc_set_brand_attribute_position( $position, $attribute, $post, $product, $set, $action ) {
	if ( $attribute == 'pa_brand' ) {
		$position = 3;
	}

	return $position;
}

/**
 * This saves WC attribute data.
 *
 * Most code comes from Line #1000 here:
 * ~/wp-content/plugins/woocommerce/includes/admin/post-types/meta-boxes/class-wc-meta-box-product-data.php
 */
function dfrpswc_save_attributes( $post_id, $dfrpswc_attributes ) {

	// Save Attributes
	$attributes = array();

	if ( isset( $dfrpswc_attributes['attribute_names'] ) && isset( $dfrpswc_attributes['attribute_values'] ) ) {

		$attribute_names  = $dfrpswc_attributes['attribute_names'];
		$attribute_values = $dfrpswc_attributes['attribute_values'];

		if ( isset( $dfrpswc_attributes['attribute_visibility'] ) ) {
			$attribute_visibility = $dfrpswc_attributes['attribute_visibility'];
		}

		if ( isset( $dfrpswc_attributes['attribute_variation'] ) ) {
			$attribute_variation = $dfrpswc_attributes['attribute_variation'];
		}

		$attribute_is_taxonomy = $dfrpswc_attributes['attribute_is_taxonomy'];
		$attribute_position    = $dfrpswc_attributes['attribute_position'];

		$attribute_names_count = sizeof( $attribute_names );

		for ( $i = 0; $i < $attribute_names_count; $i ++ ) {

			if ( ! $attribute_names[ $i ] ) {
				continue;
			}

			$is_visible   = ( isset( $attribute_visibility[ $i ] ) && $attribute_visibility[ $i ] != 0 ) ? 1 : 0;
			$is_variation = ( isset( $attribute_variation[ $i ] ) && $attribute_variation[ $i ] != 0 ) ? 1 : 0;
			$is_taxonomy  = $attribute_is_taxonomy[ $i ] ? 1 : 0;

			if ( $is_taxonomy ) {

				if ( isset( $attribute_values[ $i ] ) ) {

					// Select based attributes - Format values (posted values are slugs)
					if ( is_array( $attribute_values[ $i ] ) ) {
						$values = array_map( 'sanitize_title', $attribute_values[ $i ] );

						// Text based attributes - Posted values are term names - don't change to slugs
					} else {
						$values = array_map( 'stripslashes',
							array_map( 'strip_tags', explode( WC_DELIMITER, $attribute_values[ $i ] ) ) );
					}

					// Remove empty items in the array
					$values = array_filter( $values, 'strlen' );

				} else {

					$values = array();
				}

				// Update post terms
				if ( taxonomy_exists( $attribute_names[ $i ] ) ) {
					wp_set_object_terms( $post_id, $values, $attribute_names[ $i ] );
				}

				if ( $values ) {
					// Add attribute to array, but don't set values
					$attributes[ sanitize_title( $attribute_names[ $i ] ) ] = array(
						'name'         => wc_clean( $attribute_names[ $i ] ),
						'value'        => '',
						'position'     => $attribute_position[ $i ],
						'is_visible'   => $is_visible,
						'is_variation' => $is_variation,
						'is_taxonomy'  => $is_taxonomy
					);
				}

			} elseif ( isset( $attribute_values[ $i ] ) ) {

				// Text based, separate by pipe
				$values = implode( ' ' . WC_DELIMITER . ' ',
					array_map( 'wc_clean', explode( WC_DELIMITER, $attribute_values[ $i ] ) ) );

				// Custom attribute - Add attribute to array and set the values
				$attributes[ sanitize_title( $attribute_names[ $i ] ) ] = array(
					'name'         => wc_clean( $attribute_names[ $i ] ),
					'value'        => $values,
					'position'     => $attribute_position[ $i ],
					'is_visible'   => $is_visible,
					'is_variation' => $is_variation,
					'is_taxonomy'  => $is_taxonomy
				);
			}

		}
	}

	if ( ! function_exists( 'attributes_cmp' ) ) {
		function attributes_cmp( $a, $b ) {
			if ( $a['position'] == $b['position'] ) {
				return 0;
			}

			return ( $a['position'] < $b['position'] ) ? - 1 : 1;
		}
	}

	uasort( $attributes, 'attributes_cmp' );

	update_post_meta( $post_id, '_product_attributes', $attributes );
}

/**
 * This is clean up after the update is finished.
 * Here we will:
 *
 * Delete (move to Trash) all products which were "stranded" after the update.
 * Strandad means they no longer have a Product Set ID associated with them.
 */
add_action( 'dfrps_postprocess-' . DFRPSWC_POST_TYPE, 'dfrpswc_delete_stranded_products' );
function dfrpswc_delete_stranded_products( $obj ) {

	global $wpdb;

	$config = (array) get_option( 'dfrps_configuration' );

	// Should we even delete missing products?
	if ( isset( $config['delete_missing_products'] ) && ( $config['delete_missing_products'] == 'no' ) ) {
		update_post_meta( $obj->set['ID'], '_dfrps_postprocess_complete_' . DFRPSWC_POST_TYPE, true );

		return true;
	}

	/**
	 * Here is the process of this function:
	 *
	 * 1. Check if 'dfrpswc_temp_trashable_posts' table exists. If table does not exist, that means
	 *    we have not run the 'dfrps_postprocess-product' action yet.
	 * 2. If table does not exist:
	 *      - Create temp table.
	 *      - Insert post IDs into table.
	 * 3. Get $config['postprocess_maximum'] ($limit) number of records.
	 * 4. Loop through records and process them.
	 * 5. Delete those ($limit) number of records from table.
	 * 6. If 0 records remain to be processed:
	 *      - Update_post_meta( $obj->set['ID'], '_dfrps_postprocess_complete_' . DFRPSWC_POST_TYPE, true );
	 *      - Delete temp table.
	 */

	/**
	 * Check if 'dfrpswc_temp_trashable_posts' table exists. If it does not exist:
	 *  - Create the table.
	 *  - Query all post IDs for this Set ID.
	 *  - Insert all post IDs into table.
	 */
	$table_name = $wpdb->prefix . 'dfrpswc_temp_trashable_posts';
	$query      = $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name );
	if ( $wpdb->get_var( $query ) != $table_name ) {

		// Create the temp table to store the post IDs.
		dfrpswc_create_temp_post_ids_table( $table_name );

		/**
		 * Get all post IDs that should be trashed.
		 *
		 * This query finds all post IDs where the post was created
		 * by the DFRPS plugin but where the Product Set ID is NULL because
		 * it wasn't re-imported during the update.
		 */
		$trashable_posts = $wpdb->get_results( "
			SELECT pm.post_id
			FROM $wpdb->postmeta pm
			LEFT JOIN $wpdb->postmeta pm1
				ON pm.post_id = pm1.post_id
					AND pm1.meta_key = '_dfrps_product_set_id'
			JOIN $wpdb->posts p
				ON pm.post_id = p.ID
			WHERE
				pm.meta_key = '_dfrps_is_dfrps_product'
				AND pm.meta_value = 1
				AND pm1.post_id IS NULL
				AND p.post_status = 'publish'
		", ARRAY_A );

		$ids = array();
		foreach ( $trashable_posts as $trashable_post ) {
			$ids[] = $trashable_post['post_id'];
		}
		$ids = array_unique( $ids );

		$ids = dfrpswc_insert_ids_into_temp_table( $ids, $table_name );

		if ( empty( $ids ) ) {
			update_post_meta( $obj->set['ID'], '_dfrps_postprocess_complete_' . DFRPSWC_POST_TYPE, true );
			update_post_meta( $obj->set['ID'], '_dfrps_cpt_last_update_num_products_deleted', count( $ids ) );
			dfrpswc_drop_temp_post_ids_table( $table_name );

			return true;
		}

		update_post_meta( $obj->set['ID'], '_dfrps_cpt_last_update_num_products_deleted', count( $ids ) );

	}

	/**
	 * Get X ($limit) number of records to process where X is $config['postprocess_maximum'].
	 * Also get the $total number of posts in this table. This will be used to
	 * determine if we must repeat the 'dfrps_postprocess' action or not.
	 */
	$limit = ( isset( $config['postprocess_maximum'] ) ) ? intval( $config['postprocess_maximum'] ) : 100;

	$uid = uniqid();
	$wpdb->query( "UPDATE $table_name SET uid='$uid' WHERE uid='' ORDER BY post_id ASC LIMIT " . $limit );

	$sql   = "SELECT post_id FROM $table_name WHERE uid='$uid' ORDER BY post_id ASC";
	$posts = $wpdb->get_results( $sql, OBJECT );

	/**
	 * If $posts is empty, then:
	 *  - Set _dfrps_postprocess_complete_ to true.
	 *  - DROP the new table.
	 *  - return.
	 */
	if ( ! $posts ) {
		update_post_meta( $obj->set['ID'], '_dfrps_postprocess_complete_' . DFRPSWC_POST_TYPE, true );
		dfrpswc_drop_temp_post_ids_table( $table_name );

		return true;
	}

	/**
	 * The function to pass the post ID to when it is no longer in the store (ie. deleted, trashed).
	 *
	 * Default is wp_trash_post().
	 *
	 * We use a filter here instead of an action because if a do_action was used within the foreach()
	 * then the post (ie. $id) could possibly be put through multiple actions, causing too much unnecessary load
	 * during an already intense process.
	 *
	 * By applying a filter to the function name, we guarantee that the $id will only be passed
	 * through to one function. Also, we don't make multiple calls to apply_filters() or do_action()
	 * from within the foreach() loop. Keep it outside of the loop to prevent more than one
	 * call to apply_filters().
	 */
	$func = apply_filters( 'dfrpswc_process_stranded_product', 'wp_trash_post' );

	foreach ( $posts as $post ) {
		$post_id = intval( $post->post_id );
		$func( $post_id );
	}

	/**
	 * Now we delete this set of post IDs from the table. This ensures
	 * that we don't process them again.
	 */
	$wpdb->query( "DELETE FROM $table_name WHERE uid='$uid'" );

}

/**
 * When update is complete, Recount Terms.
 *
 * This code is taken from public function status_tools()
 * in ~/wp-content/plugins/woocommerce/includes/admin/class-wc-admin-status.php
 */
add_action( 'dfrps_set_update_complete', 'dfrpswc_update_complete' );
function dfrpswc_update_complete( $set ) {

	$product_cats = get_terms( DFRPSWC_TAXONOMY, array( 'hide_empty' => false, 'fields' => 'id=>parent' ) );
	_wc_term_recount( $product_cats, get_taxonomy( DFRPSWC_TAXONOMY ), true, false );

	$product_tags = get_terms( DFRPSWC_TAXONOMY, array( 'hide_empty' => false, 'fields' => 'id=>parent' ) );
	_wc_term_recount( $product_tags, get_taxonomy( DFRPSWC_TAXONOMY ), true, false );

	$use_cache = wp_using_ext_object_cache( false );
	delete_transient( 'wc_term_counts' );
	wp_using_ext_object_cache( $use_cache );
}


/*******************************************************************
 * INSERT AFFILIATE ID INTO AFFILIATE LINK
 *******************************************************************/

/**
 * Extend "WC_Product_External" class.
 * This tells WC to use the "Dfrpswc_Product_External" class if
 * a product is an external product.
 *
 * This returns the default class if the WooCommerce Cloak Affiliate Links
 * plugin is activated.
 */
add_filter( 'woocommerce_product_class', 'dfrpswc_woocommerce_product_class', 40, 4 );
function dfrpswc_woocommerce_product_class( $classname, $product_type, $post_type, $product_id ) {

	$valid_classes = array( 'WC_Product_External', 'WooZoneWcProductModify_External' );

	/**
	 * Allow the $valid_classes array to be modified
	 *
	 * If there's another Product class that should be allowed to be extended, add it here.
	 *
	 * @since 1.2.14
	 *
	 * @param array $valid_classes Array of valid product classes.
	 */
	$valid_classes = apply_filters( 'dfrpswc_valid_product_classes', $valid_classes );

	if ( ! in_array( $classname, $valid_classes ) ) {
		return $classname;
	}

	if ( class_exists( 'Wccal' ) ) {
		return $classname;
	}

	if ( ! dfrpswc_is_dfrpswc_product( $product_id ) ) {
		return $classname;
	}

	return 'Dfrpswc_Product_External';
}

/**
 * Creates the "Dfrpswc_Product_External" class in order to modify
 * the product_url() method.
 *
 * The product_url() method returns the affiliate link with the affiliate
 * id inserted.
 *
 * This does nothing if the WooCommerce Cloak Affiliate Links
 * plugin is activated.
 */
function dfrpswc_extend_wc_product_external_class() {

	if ( ! class_exists( 'WC_Product_External' ) ) {
		return;
	}

	if ( class_exists( 'Wccal' ) ) {
		return;
	}

	class Dfrpswc_Product_External extends WC_Product_External {

		public function get_product_url( $context = 'view' ) {

			if ( ! dfrpswc_is_dfrpswc_product( $this->id ) ) {
				return esc_url( $this->get_prop( 'product_url', $context ) );
			}

			$product       = get_post_meta( $this->id, '_dfrps_product', true );
			$external_link = dfrapi_url( $product );
			$url           = ( $external_link != '' ) ? $external_link : get_permalink( $this->id );

			// @todo Should we use esc_url() here?
			return $url;
		}
	}
}

add_action( 'plugins_loaded', 'dfrpswc_extend_wc_product_external_class' );

/**
 * This returns the affiliate link with affiliate ID inserted
 * if the WooCommerce Cloak Affiliate Links plugin is activated.
 */
add_filter( 'wccal_filter_url', 'dfrpswc_add_affiliate_id_to_url', 20, 2 );
function dfrpswc_add_affiliate_id_to_url( $external_link, $post_id ) {
	if ( dfrpswc_is_dfrpswc_product( $post_id ) ) {
		$product       = get_post_meta( $post_id, '_dfrps_product', true );
		$external_link = dfrapi_url( $product );
	}

	return $external_link;
}


/*******************************************************************
 * ADD METABOX TO PRODUCT'S EDIT PAGE.
 *******************************************************************/

/**
 * Add meta box to WC product pages so that a user can
 * see which product sets added this product.
 */
add_action( 'admin_menu', 'dfrpswc_add_meta_box' );
function dfrpswc_add_meta_box() {
	add_meta_box(
		'dfrpswc_product_sets_relationships',
		_x( 'Datafeedr Product Sets', DFRPSWC_DOMAIN ),
		'dfrpswc_product_sets_relationships_metabox',
		DFRPSWC_POST_TYPE,
		'side',
		'low',
		array()
	);
}

/**
 * The metabox content.
 */
function dfrpswc_product_sets_relationships_metabox( $post, $box ) {
	$set_ids = get_post_meta( $post->ID, '_dfrps_product_set_id', false );
	$set_ids = array_unique( $set_ids );
	if ( ! empty( $set_ids ) ) {
		echo '<p>' . __( 'This product was added by the following Product Set(s)', DFRPSWC_DOMAIN ) . '</p>';
		foreach ( $set_ids as $set_id ) {
			$url = get_edit_post_link( $set_id );
			echo '<div>';
			echo '<a href="' . $url . '" title="' . __( 'View this Product Set', 'dfrpswc_integration' ) . '">';
			echo get_the_title( $set_id );
			echo '</a>';
			echo '</div>';
		}
	} else {
		echo '<p>' . __( 'This product was not added by a Datafeedr Product Set.', DFRPSWC_DOMAIN ) . '</p>';
	}
}


/*******************************************************************
 * WOOCOMMERCE HOOKS
 *******************************************************************/

/**
 * Add impressionurl for Home Depot products.
 *
 * Display the "impressionurl" field in an <img> tag for all
 * Home Depot (#61292) and Home Depot Canada (#61293) products
 * on the shop homepage, category pages and single product pages.
 *
 * @since 1.2.9
 *
 * @return null|string Returns <img> tag if product is from Home Depot, else null.
 */
add_action( 'woocommerce_after_shop_loop_item', 'dfrpswc_add_home_depot_impression_url' );
add_action( 'woocommerce_after_single_product_summary', 'dfrpswc_add_home_depot_impression_url' );
function dfrpswc_add_home_depot_impression_url() {

	$product = get_post_meta( get_the_ID(), '_dfrps_product', true );

	// Return if not a Datafeedr product.
	if ( ! isset( $product['merchant_id'] ) ) {
		return;
	}

	// Return if not a Home Depot product.
	if ( '61292' != $product['merchant_id'] && '61293' != $product['merchant_id'] ) {
		return;
	}

	// Return if 'impressionurl' field does not exist.
	if ( ! isset( $product['impressionurl'] ) ) {
		return;
	}

	$networks      = (array) get_option( 'dfrapi_networks' );
	$affiliate_id  = trim( $networks['ids'][ $product['source_id'] ]['aid'] );
	$impressionurl = str_replace( "@@@", $affiliate_id, $product['impressionurl'] );

	echo '<img src="' . $impressionurl . '" width="1" height="1" border="0" />';
}


/*******************************************************************
 * MISCELLANEOUS FUNCTIONS
 *******************************************************************/

/**
 * Returns true if product was imported by this plugin (Datafeedr WooCommerce Importer)
 */
function dfrpswc_is_dfrpswc_product( $product_id ) {
	if ( get_post_meta( $product_id, '_dfrps_is_dfrpswc_product', true ) != '' ) {
		return true;
	}

	return false;
}

/**
 * A helper function which allows a user to add additional WooCommerce
 * attributes to their product.
 */
function dfrpswc_add_attribute(
	$product,
	$attributes,
	$field,
	$taxonomy,
	$is_taxonomy,
	$position = 1,
	$is_visible = 1,
	$is_variation = 0
) {
	if ( isset( $product[ $field ] ) && ( $product[ $field ] != '' ) ) {
		$attributes[ $taxonomy ] = array(
			'name'         => $taxonomy,
			'value'        => $product[ $field ],
			'position'     => $position,
			'is_visible'   => $is_visible,
			'is_variation' => $is_variation,
			'is_taxonomy'  => $is_taxonomy,
			'field'        => $field,
		);
	}

	return $attributes;
}

/**
 * A helper function to determine if either the preprocess or postprocess
 * processes are complete.
 *
 * Returns true if complete, false if not complete.
 */
function dfrpswc_process_complete( $process, $set_id ) {
	$status = get_post_meta( $set_id, '_dfrps_' . $process . '_complete_' . DFRPSWC_POST_TYPE, true );
	if ( $status == '' ) {
		return false;
	}

	return true;
}

/**
 * Add extra links to plugin page.
 */
add_filter( 'plugin_row_meta', 'dfrpswc_plugin_row_meta', 10, 2 );
function dfrpswc_plugin_row_meta( $links, $plugin_file ) {
	if ( $plugin_file == DFRPSWC_BASENAME ) {
		$links[] = sprintf( '<a href="' . DFRPSWC_CONTACT . '">%s</a>', __( 'Support', DFRPSWC_DOMAIN ) );

		return $links;
	}

	return $links;
}

/**
 * Links to other related or required plugins.
 */
function dfrpswc_plugin_links( $plugin ) {
	$map = array(
		'dfrapi'      => 'https://wordpress.org/plugins/datafeedr-api/',
		'dfrps'       => 'https://wordpress.org/plugins/datafeedr-product-sets/',
		'woocommerce' => 'https://wordpress.org/plugins/woocommerce/',
		//'importers' => admin_url( 'plugin-install.php?tab=search&type=term&s=dfrps_importer&plugin-search-input=Search+Plugins' ),
		'importers'   => admin_url( 'plugins.php' ),
	);

	return $map[ $plugin ];
}

add_filter( 'plugin_action_links_' . DFRPSWC_BASENAME, 'dfrpswc_action_links' );
function dfrpswc_action_links( $links ) {
	return array_merge(
		$links,
		array(
			'config' => '<a href="' . admin_url( 'admin.php?page=dfrpswc_options' ) . '">' . __( 'Configuration',
					DFRPSWC_DOMAIN ) . '</a>',
		)
	);
}

/**
 * When a term is split, ensure postmeta for Product Set is maintained.
 *
 * Whenever a term is edited, this function loops through all postmeta values of '_dfrps_cpt_terms' and looks for any
 * old term_ids. If they exist, the '_dfrps_cpt_terms' is updated with the new term_id.
 *
 * This only happens when a shared term is updated (eg, when its name is updated in the Dashboard).
 *
 * @since 1.2.1
 *
 * @link https://make.wordpress.org/core/2015/02/16/taxonomy-term-splitting-in-4-2-a-developer-guide/
 *
 * @param  int $old_term_id The old term ID to search for.
 * @param  int $new_term_id The new term ID to replace the old one with.
 * @param  int $term_obj_taxonomy_id The term's tax ID.
 * @param  string $taxonomy The corresponding taxonomy.
 */
add_action( 'split_shared_term', 'dfrpswc_update_terms_for_split_terms', 20, 4 );
function dfrpswc_update_terms_for_split_terms( $old_term_id, $new_term_id, $term_obj_taxonomy_id, $taxonomy ) {

	if ( $taxonomy !== 'product_cat' ) {
		return true;
	}

	global $wpdb;

	$current_cpt_terms = $wpdb->get_results( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_dfrps_cpt_terms'" );

	if ( empty( $current_cpt_terms ) ) {
		return true;
	}

	foreach ( $current_cpt_terms as $item => $term_obj ) {

		$current_meta_value = maybe_unserialize( $term_obj->meta_value );

		if ( in_array( $old_term_id, $current_meta_value ) ) {

			// @link http://stackoverflow.com/a/8668861
			$new_meta_value = array_replace(
				$current_meta_value,
				array_fill_keys(
					array_keys( $current_meta_value, $old_term_id ),
					$new_term_id
				)
			);

			update_post_meta( $term_obj->post_id, '_dfrps_cpt_terms', $new_meta_value );

		}
	}
}

/**
 * Create temp table for storing post IDs.
 *
 * This function creates simple, temporary tables to be used to store a
 * list of post IDs. Those post IDs are then referenced by other
 * functions and processed appropriately.
 *
 * @since 1.2.3
 *
 * @global object $wpdb WP Database Object.
 *
 * @param         $table_name string The name of the table we will create. This
 * should already be prefixed with $wpdb->prefix;
 */
function dfrpswc_create_temp_post_ids_table( $table_name ) {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
 			post_id bigint(20) unsigned NOT NULL,
 			uid varchar(13) NOT NULL default '',
 			PRIMARY KEY  (post_id),
 			KEY uid (uid)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
}

/**
 * Drop temp table.
 *
 * Drops a temp table which was used for temporarily storing a list
 * of post IDs.
 *
 * @since 1.2.3
 *
 * @global object $wpdb WP Database Object.
 *
 * @param         $table_name string The name of the table we will create. This
 * should already be prefixed with $wpdb->prefix;
 */
function dfrpswc_drop_temp_post_ids_table( $table_name ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
}

/**
 * Action to run when DFRPS plugin is updated.
 *
 * If the DFRPS plugin is updated, we hook into the 'dfrps_update_reset' action
 * to reset the update. In this case, we DROP our temporarily created
 * tables.
 *
 * @since 1.2.3
 *
 * @global object $wpdb WP Database Object.
 */
add_action( 'dfrps_update_reset', 'dfrpswc_dfrps_update_reset' );
function dfrpswc_dfrps_update_reset() {
	global $wpdb;
	dfrpswc_drop_temp_post_ids_table( $wpdb->prefix . 'dfrpswc_temp_post_ids_by_set_id' );
	dfrpswc_drop_temp_post_ids_table( $wpdb->prefix . 'dfrpswc_temp_trashable_posts' );
}

/**
 * Inserts array of IDs into table..
 *
 * This inserts an array of post IDs into the temporary table $table_name.
 *
 * @since 1.2.3
 *
 * @global object $wpdb WP Database Object.
 *
 * @param array $ids An array of Post IDs.
 * @param string $table_name The name of the table (with wp_prefix) to import IDs into.
 *
 * @return array Returns array of inserted post IDs.
 */
function dfrpswc_insert_ids_into_temp_table( $ids, $table_name ) {

	global $wpdb;

	if ( empty( $ids ) || empty( $table_name ) ) {
		return array();
	}

	$ids = array_unique( $ids );
	$ids = array_map( 'intval', $ids );

	// Insert all post IDs into our new temp table.
	// @link http://wordpress.stackexchange.com/a/126912
	$q = "INSERT INTO $table_name (post_id) VALUES ";
	foreach ( $ids as $id ) {
		$q .= $wpdb->prepare( "(%d),", $id );
	}
	$q = rtrim( $q, ',' ) . ';';
	$wpdb->query( $q );

	return $ids;

}


/**
 * Returns true if plugin is installed, else returns false.
 *
 * @param string $plugin_file Plugin file name formatted like: woocommerce/woocommerce.php
 *
 * @return bool
 */
function dfrpswc_plugin_is_installed( $plugin_file ) {
	$file_name = plugin_dir_path( __DIR__ ) . $plugin_file;

	return ( file_exists( $file_name ) );
}

/**
 * Returns a URL for installing a plugin.
 *
 * @since 1.2.13
 *
 * @param string $plugin_file Plugin file name formatted like: woocommerce/woocommerce.php
 *
 * @return string URL or empty string if user is not allowed.
 */
function dfrpswc_plugin_installation_url( $plugin_file ) {

	if ( ! current_user_can( 'install_plugins' ) ) {
		return '';
	}

	$plugin_name = explode( '/', $plugin_file );
	$plugin_name = str_replace( '.php', '', $plugin_name[1] );

	$url = add_query_arg( array(
		'action' => 'install-plugin',
		'plugin' => $plugin_name
	), wp_nonce_url( admin_url( 'update.php' ), 'install-plugin_' . $plugin_name ) );

	return $url;
}

/**
 * Returns a URL for activating a plugin.
 *
 * @since 1.2.13
 *
 * @param string $plugin_file Plugin file name formatted like: woocommerce/woocommerce.php
 *
 * @return string URL or empty string if user is not allowed.
 */
function dfrpswc_plugin_activation_url( $plugin_file ) {

	if ( ! current_user_can( 'activate_plugin', $plugin_file ) ) {
		return '';
	}

	$url = add_query_arg( array(
		'action' => 'activate',
		'plugin' => urlencode( $plugin_file ),
		'paged'  => '1',
		's'      => '',
	), wp_nonce_url( admin_url( 'plugins.php' ), 'activate-plugin_' . $plugin_file ) );

	return $url;
}

/**
 * Override WooCommerce template files if they are not already being overridden by
 * the user's theme.
 *
 * @since 1.2.28
 *
 * @global $woocommerce
 *
 * @param string $template Full path to current template file. Example: /home/user/public_html/wp-content/themes/storefront-child/woocommerce/single-product/add-to-cart/external.php
 * @param string $template_name Relative path to template. Example: single-product/add-to-cart/external.php
 * @param string $template_path Path to WooCommerce template files. Example: woocommerce/
 *
 * @return string Full path to template file.
 */
function dfrpswc_override_woocommerce_template_files( $template, $template_name, $template_path ) {

	global $woocommerce;

	$_template = $template;

	if ( ! $template_path ) {
		$template_path = $woocommerce->template_url;
	}

	$plugin_path = trailingslashit( DFRPSWC_PATH ) . $template_path;

	// Get the template file from the theme... This takes priority.
	$template = locate_template( [ $template_path . $template_name, $template_name ] );

	// Load DFRPSWC template file if one does not exist in the user's theme.
	if ( ! $template && file_exists( $plugin_path . $template_name ) ) {
		$template = $plugin_path . $template_name;
	}

	if ( ! $template ) {
		$template = $_template;
	}

	return $template;
}

add_filter( 'woocommerce_locate_template', 'dfrpswc_override_woocommerce_template_files', 10, 3 );
