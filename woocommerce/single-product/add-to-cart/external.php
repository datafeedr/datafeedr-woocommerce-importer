<?php
/**
 * External product add to cart
 *
 * This template overrides ~/wp-content/plugins/woocommerce/single-product/add-to-cart/external.php.
 *
 * We override this file because the <form> element WooCommerce replaced the standard <a> element
 * was breaking for a lot of users.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version 3.4.0
 *
 * @var string $product_url Affiliate URL
 * @var string $button_text Button Text
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_add_to_cart_form' ); ?>

<?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>

<?php $target = apply_filters( 'dfrpswc_single_product_add_to_cart_button_target', '_blank', 'external' ); ?>

<p class="cart">
    <a href="<?php echo esc_url( $product_url ); ?>"
       rel="nofollow"
       target="<?php echo esc_attr( $target ); ?>"
       class="single_add_to_cart_button button alt"><?php echo esc_html( $button_text ); ?></a>
</p>

<?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>

<?php do_action( 'woocommerce_after_add_to_cart_form' ); ?>
