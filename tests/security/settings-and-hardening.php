<?php
/**
 * Security regression test: Phase 4 hardening in 1.3.11 (audit findings WC-03 to WC-10).
 *
 * - WC-03: dfrpswc_validate() keeps real settings unchanged, rejects non-array input and
 *          non-scalar values; dfrpswc_get_options() survives a corrupted option.
 * - WC-04: affiliate URLs pass through esc_url_raw() without changing any stored product's URL,
 *          and a javascript: URL is dropped.
 * - WC-05: the product edit meta box escapes Product Set titles and skips missing sets.
 * - WC-06: admin notices print nothing for users without manage_options.
 * - WC-07: prepared batch SQL is identical to the old interpolated SQL.
 * - WC-08: the legacy insert path runs merchant HTML through kses even for an admin.
 * - WC-10: the add-to-cart template still prints rel/target.
 *
 * Nothing is written to the database: fixtures are supplied through filters and the legacy
 * insert is aborted with wp_insert_post_empty_content.
 *
 * Run from the site root (not shipped to wordpress.org, see .distignore):
 *   /opt/homebrew/opt/php@8.5/bin/php /usr/local/bin/wp eval-file \
 *     wp-content/plugins/datafeedr-woocommerce-importer/tests/security/settings-and-hardening.php 2>/dev/null | grep RESULT
 *
 * Expected: every line "RESULT PASS ..." and a final "RESULT OVERALL PASS".
 */

defined( 'ABSPATH' ) || exit;

$failures = 0;

function dfrpswc_test_check( $label, $ok, $detail = '' ) {
	global $failures;
	if ( ! $ok ) {
		$failures ++;
	}
	echo 'RESULT ' . ( $ok ? 'PASS' : 'FAIL' ) . ' ' . $label . ( $detail !== '' ? ' :: ' . $detail : '' ) . "\n";
}

function dfrpswc_test_capture( callable $fn ) {
	ob_start();
	$fn();

	return ob_get_clean();
}

global $wpdb;

/* ---------------------------------------------------------------- WC-03 */

$saved = get_option( 'dfrpswc_options', array() );
dfrpswc_test_check( 'real saved options unchanged by validate', dfrpswc_validate( $saved ) === $saved, wp_json_encode( dfrpswc_validate( $saved ) ) );
dfrpswc_test_check( 'string input keeps saved options', dfrpswc_validate( 'foo' ) === $saved );
dfrpswc_test_check( 'empty array still returned as-is', dfrpswc_validate( array() ) === array() );

$out = dfrpswc_validate( [
	'button_text'   => "<b>Buy</b> 50%25 off\x07",
	'rel_loop'      => [ 'nofollow' ],
	'rel_single'    => 'nofollow sponsored',
	'target_single' => '<script>x</script>',
	'target_loop'   => '_self',
] );
dfrpswc_test_check( 'button text tags/control chars stripped, %XX kept', $out['button_text'] === 'Buy 50%25 off', $out['button_text'] );
dfrpswc_test_check( 'array value ignored', ! isset( $out['rel_loop'] ) );
dfrpswc_test_check( 'multi-token rel kept', $out['rel_single'] === 'nofollow sponsored' );
dfrpswc_test_check( 'html target falls back to default', $out['target_single'] === '_blank', $out['target_single'] );
dfrpswc_test_check( 'valid target kept', $out['target_loop'] === '_self' );

$corrupt = function () {
	return 'broken';
};
add_filter( 'pre_option_dfrpswc_options', $corrupt );
try {
	$opts = dfrpswc_get_options();
	dfrpswc_test_check( 'corrupted option falls back to defaults', $opts === dfrpswc_get_default_options() );
} catch ( Throwable $e ) {
	dfrpswc_test_check( 'corrupted option falls back to defaults', false, get_class( $e ) . ': ' . $e->getMessage() );
}
remove_filter( 'pre_option_dfrpswc_options', $corrupt );

/* ---------------------------------------------------------------- WC-04 */

$ids     = $wpdb->get_col( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_dfrps_is_dfrpswc_product'" );
$changed = 0;
foreach ( $ids as $id ) {
	$product = get_post_meta( $id, '_dfrps_product', true );
	if ( is_array( $product ) ) {
		$raw = dfrapi_url( $product );
		if ( esc_url_raw( $raw ) !== $raw ) {
			$changed ++;
		}
	}
}
dfrpswc_test_check( 'no stored product URL changed by esc_url_raw (' . count( $ids ) . ' products)', $changed === 0, "changed=$changed" );

$test_post_id = (int) $ids[0];
$evil_product = [ 'source' => 'Test', 'source_id' => 999, 'url' => 'javascript:alert(1)//@@@' ];
$meta_filter  = function ( $value, $object_id, $meta_key ) use ( $test_post_id, $evil_product ) {
	if ( (int) $object_id === $test_post_id && '_dfrps_product' === $meta_key ) {
		return [ $evil_product ];
	}

	return $value;
};
$net_filter = function () {
	return [ 'ids' => [ 999 => [ 'nid' => '999', 'aid' => 'AID', 'tid' => '' ] ] ];
};
add_filter( 'get_post_metadata', $meta_filter, 10, 3 );
add_filter( 'pre_option_dfrapi_networks', $net_filter );

$url = dfrpswc_add_affiliate_id_to_url( 'https://example.com/cloaked', $test_post_id );
dfrpswc_test_check( 'wccal callback drops javascript: URL', stripos( $url, 'javascript' ) === false, $url );

if ( class_exists( 'Dfrpswc_Product_External' ) ) {
	$p   = new Dfrpswc_Product_External( $test_post_id );
	$url = $p->get_product_url();
	dfrpswc_test_check( 'get_product_url drops javascript: URL', stripos( $url, 'javascript' ) === false, $url );
} else {
	echo "RESULT SKIP get_product_url (Dfrpswc_Product_External not loaded: WooCommerce Cloak Affiliate Links is active)\n";
}

remove_filter( 'get_post_metadata', $meta_filter, 10 );
remove_filter( 'pre_option_dfrapi_networks', $net_filter );

/* ---------------------------------------------------------------- WC-05 */

$set_id   = (int) get_posts( [ 'post_type' => 'datafeedr-productset', 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any' ] )[0];
$set_meta = function ( $value, $object_id, $meta_key ) use ( $test_post_id, $set_id ) {
	if ( (int) $object_id === $test_post_id && '_dfrps_product_set_id' === $meta_key ) {
		return [ $set_id, 999999999 ];
	}

	return $value;
};
$title_filter = function ( $title, $id = 0 ) use ( $set_id ) {
	return (int) $id === $set_id ? '<script>alert(1)</script>Set' : $title;
};
add_filter( 'get_post_metadata', $set_meta, 10, 3 );
add_filter( 'the_title', $title_filter, 10, 2 );
wp_set_current_user( 1 );
$out = dfrpswc_test_capture( function () use ( $test_post_id ) {
	dfrpswc_product_sets_relationships_metabox( get_post( $test_post_id ), [] );
} );
remove_filter( 'get_post_metadata', $set_meta, 10 );
remove_filter( 'the_title', $title_filter, 10 );
dfrpswc_test_check( 'meta box escapes set title', strpos( $out, '<script>' ) === false && strpos( $out, '&lt;script&gt;' ) !== false );
dfrpswc_test_check( 'meta box skips missing set', substr_count( $out, '<a ' ) === 1, 'links=' . substr_count( $out, '<a ' ) );

/* ---------------------------------------------------------------- WC-06 */

wp_set_current_user( 0 );
$_GET['settings-updated'] = 'true';
$_GET['page']             = 'dfrpswc_options';
$out = dfrpswc_test_capture( function () {
	dfrpswc_admin_notice_plugin_dependencies();
	dfrpswc_settings_updated();
	dfrpswc_not_compatible_with_dfrps();
} );
dfrpswc_test_check( 'notices hidden from users without manage_options', $out === '', $out );
wp_set_current_user( 1 );
$out = dfrpswc_test_capture( 'dfrpswc_settings_updated' );
dfrpswc_test_check( 'settings notice still shown to admins', strpos( $out, 'Configuration successfully updated' ) !== false );
unset( $_GET['settings-updated'], $_GET['page'] );

/* ---------------------------------------------------------------- WC-07 */

$table = $wpdb->prefix . 'dfrpswc_temp_post_ids_by_set_id';
$uid   = uniqid();
$limit = 100;
$pairs = [
	[ "UPDATE $table SET uid='$uid' WHERE uid='' ORDER BY post_id ASC LIMIT " . $limit, $wpdb->prepare( "UPDATE $table SET uid=%s WHERE uid='' ORDER BY post_id ASC LIMIT %d", $uid, $limit ) ],
	[ "SELECT post_id FROM $table WHERE uid='$uid' ORDER BY post_id ASC", $wpdb->prepare( "SELECT post_id FROM $table WHERE uid=%s ORDER BY post_id ASC", $uid ) ],
	[ "DELETE FROM $table WHERE uid='$uid'", $wpdb->prepare( "DELETE FROM $table WHERE uid=%s", $uid ) ],
];
foreach ( $pairs as $i => $pair ) {
	dfrpswc_test_check( "prepared batch SQL #$i identical", $pair[0] === $pair[1], $pair[1] );
}

/* ---------------------------------------------------------------- WC-08 */

wp_set_current_user( 1 ); // Administrator: has unfiltered_html, so core kses would not run.
$captured = null;
$capture  = function ( $post ) use ( &$captured ) {
	$captured = $post;

	return $post;
};
add_filter( 'dfrpswc_filter_post_array', $capture, 999 );
add_filter( 'wp_insert_post_empty_content', '__return_true' ); // Abort the insert.
dfrpswc_insert_post(
	[
		'name'             => 'Tent<script>alert(1)</script>',
		'description'      => '<p onclick="x()">Nice <b>tent</b></p><script>bad()</script>',
		'shortdescription' => '<img src=x onerror=alert(1)>Short',
	],
	[ 'post_author' => 1 ],
	'insert'
);
remove_filter( 'wp_insert_post_empty_content', '__return_true' );
remove_filter( 'dfrpswc_filter_post_array', $capture, 999 );
$all = implode( ' ', [ $captured['post_title'], $captured['post_content'], $captured['post_excerpt'] ] );
dfrpswc_test_check( 'legacy path strips script/handlers', stripos( $all, '<script' ) === false && stripos( $all, 'onclick' ) === false && stripos( $all, 'onerror' ) === false, $all );
dfrpswc_test_check( 'legacy path keeps allowed HTML', strpos( $captured['post_content'], '<b>tent</b>' ) !== false );

/* ---------------------------------------------------------------- WC-10 */

$product_url = 'https://example.com/?a=1&b=2';
$button_text = 'Buy Now';
$out         = dfrpswc_test_capture( function () use ( $product_url, $button_text ) {
	include DFRPSWC_PATH . 'woocommerce/single-product/add-to-cart/external.php';
} );
$rel    = dfrpswc_get_option( 'rel_single', 'nofollow' );
$target = dfrpswc_get_option( 'target_single', '_blank' );
dfrpswc_test_check( 'template prints rel and target', strpos( $out, 'rel="' . esc_attr( $rel ) . '"' ) !== false && strpos( $out, 'target="' . esc_attr( $target ) . '"' ) !== false );

wp_set_current_user( 0 );

echo 'RESULT OVERALL ' . ( $failures ? "FAIL ($failures)" : 'PASS' ) . "\n";
