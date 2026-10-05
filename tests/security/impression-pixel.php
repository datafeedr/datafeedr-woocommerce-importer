<?php
/**
 * Security regression test: Home Depot impression pixel (audit finding WC-01, fixed in 1.3.11).
 *
 * Feeds fixture product data and network settings to dfrpswc_add_home_depot_impression_url()
 * through filters (nothing is written to the database) and checks that the merchant-supplied
 * impressionurl and the affiliate ID are escaped, and that no pixel is printed when the
 * affiliate ID is missing.
 *
 * Run from the site root (not shipped to wordpress.org, see .distignore):
 *   /opt/homebrew/opt/php@8.5/bin/php /usr/local/bin/wp eval-file \
 *     wp-content/plugins/datafeedr-woocommerce-importer/tests/security/impression-pixel.php 2>/dev/null | grep RESULT
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

// Use any existing post as the "current product"; its meta is replaced by the filter below.
$post_id = (int) get_posts( [ 'post_type' => 'product', 'numberposts' => 1, 'fields' => 'ids' ] )[0];
$GLOBALS['post'] = get_post( $post_id );
setup_postdata( $GLOBALS['post'] );

$fixture_product  = [];
$fixture_networks = [
	'ids' => [
		999 => [ 'nid' => '999', 'aid' => 'AID123', 'tid' => '' ],
		777 => [ 'nid' => '777', 'aid' => '', 'tid' => '' ],
		888 => [ 'nid' => '888', 'aid' => 'A"B<x>', 'tid' => '' ],
	],
];

add_filter( 'get_post_metadata', function ( $value, $object_id, $meta_key, $single ) use ( &$fixture_product, $post_id ) {
	if ( (int) $object_id === $post_id && '_dfrps_product' === $meta_key ) {
		return $single ? [ $fixture_product ] : [ $fixture_product ];
	}

	return $value;
}, 10, 4 );

add_filter( 'pre_option_dfrapi_networks', function () use ( $fixture_networks ) {
	return $fixture_networks;
} );

$render = function ( array $product ) use ( &$fixture_product ) {
	$fixture_product = $product;
	ob_start();
	dfrpswc_add_home_depot_impression_url();

	return ob_get_clean();
};

$base = [ 'merchant_id' => '61292', 'source_id' => 999 ];

// 1. Valid URL: same pixel as before, with the affiliate ID inserted.
$out = $render( $base + [ 'impressionurl' => 'https://www.homedepot.com/imp?aid=@@@&x=1' ] );
dfrpswc_test_check( 'valid url renders pixel', $out === '<img src="https://www.homedepot.com/imp?aid=AID123&#038;x=1" width="1" height="1" border="0" alt="" />', $out );

// 2. Attribute breakout attempt.
$out = $render( $base + [ 'impressionurl' => 'https://x.test/a" onerror="alert(1)' ] );
dfrpswc_test_check( 'attribute breakout neutralised', strpos( $out, '" onerror' ) === false && strpos( $out, '<img src="' ) === 0, $out );

// 3. javascript: scheme.
$out = $render( $base + [ 'impressionurl' => 'javascript:alert(1)' ] );
dfrpswc_test_check( 'javascript: url prints nothing', $out === '', $out );

// 4. Network not configured (was an undefined index warning).
$out = $render( [ 'merchant_id' => '61293', 'source_id' => 555, 'impressionurl' => 'https://www.homedepot.ca/imp?aid=@@@' ] );
dfrpswc_test_check( 'missing network prints nothing', $out === '', $out );

// 5. Empty affiliate ID.
$out = $render( [ 'merchant_id' => '61292', 'source_id' => 777, 'impressionurl' => 'https://www.homedepot.com/imp?aid=@@@' ] );
dfrpswc_test_check( 'empty affiliate id prints nothing', $out === '', $out );

// 6. Affiliate ID containing quotes/brackets cannot break out either.
$out = $render( [ 'merchant_id' => '61292', 'source_id' => 888, 'impressionurl' => 'https://www.homedepot.com/imp?aid=@@@' ] );
dfrpswc_test_check( 'affiliate id escaped', strpos( $out, '"B' ) === false && strpos( $out, '<x>' ) === false, $out );

// 7. Non-Home Depot merchant: unchanged behavior, nothing printed.
$out = $render( [ 'merchant_id' => '12345', 'source_id' => 999, 'impressionurl' => 'https://example.com/imp?aid=@@@' ] );
dfrpswc_test_check( 'other merchants print nothing', $out === '', $out );

// 8. Product without impressionurl: nothing printed.
$out = $render( $base );
dfrpswc_test_check( 'no impressionurl prints nothing', $out === '', $out );

wp_reset_postdata();

echo 'RESULT OVERALL ' . ( $failures ? "FAIL ($failures)" : 'PASS' ) . "\n";
