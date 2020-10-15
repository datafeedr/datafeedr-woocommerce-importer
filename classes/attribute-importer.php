<?php

/**
 * Class Dfrpswc_Attribute_Importer
 *
 * Supports importing WooCommerce product attributes and using preferred terms and term variants.
 *
 * @link Documentation: https://datafeedrapi.helpscoutdocs.com/article/221-import-attribute-for-a-product
 * @link Examples: https://gist.github.com/EricBusch/fbe9f7164ec0d4a85d70d0f430581e9a
 *
 * @since 1.2.27
 */
class Dfrpswc_Attribute_Importer {

	/**
	 * The WooCommerce attribute slug we want to update.
	 *
	 * This should be something like "pa_color" or just "color".
	 *
	 * @since 1.2.27
	 * @access public
	 * @var string $slug The properly prefixed attribute slug.
	 */
	public $slug;

	/**
	 * The current value of the attribute.
	 *
	 * @since 1.2.27
	 * @access public
	 * @var mixed $value The current value of the attribute.
	 */
	public $value;

	/**
	 * This is the current attribute slug we are processing.
	 *
	 * This will be something like "pa_color" or "pa_size".
	 *
	 * @since 1.2.27
	 * @access public
	 * @var string $attribute Current attribute slug.
	 */
	public $attribute;

	/**
	 * This is the current Datafeedr product we are importing.
	 *
	 * @since 1.2.27
	 * @access public
	 * @var array $product Datafeedr product array.
	 */
	public $product;

	/**
	 * An array of characters that should be replaced in the matched values.
	 *
	 * @since 1.2.27
	 * @access public
	 * @var array $value_search
	 */
	public $value_search = [ "'" ];

	/**
	 * An array of characters that any matches of $value_search should be replaced with.
	 *
	 * @since 1.2.27
	 * @access public
	 * @var array $value_replace
	 */
	public $value_replace = [ "" ];

	/**
	 * A character that any field should be exploded() by.
	 *
	 * @since 1.2.27
	 * @access public
	 * @var string $field_delimiter
	 */
	public $field_delimiter = '';

	/**
	 * Current array of preferred terms and their respective variants.
	 *
	 * array(
	 *     'Red' => array( 'garnet', 'maroon', 'cherry'),
	 *     'Blue' => array( 'navy', 'azure', 'periwinkle'),
	 *     'Yellow' => array(),
	 * )
	 *
	 * @since 1.2.27
	 * @access protected
	 * @var array $vocab
	 */
	protected $vocab = [];

	/**
	 * Array of $product fields to get attribute values from, a fallback field (optional and a
	 * default value (optional) to use if field does not exist.
	 *
	 * Simple usage with NO preferred terms or excluded terms.
	 *
	 *      Add the "color" field if it exists:
	 *
	 *          $this->add_field( [ "color" ] );
	 *
	 *      Add the "color" field if it exists, otherwise, add the "productvariantoption2" if it exists:
	 *
	 *          $this->add_field( [ "color", "productvariantoption2" ] );
	 *
	 * Advanced usage with preferred and excluded terms.
	 *
	 *      Add the "color" field if it exists, otherwise, add the "productvariantoption2" if it exists.
	 *      Use preferred terms for "color" and "productvariantoption2" if one of those fields.
	 *      If no preferred terms are found for the first existing field, import the "raw" value of the fallback field:
	 *
	 *          $this->add_field( [ "color", "productvariantoption2" ], "color" );
	 *
	 *      Add the "color" field if it exists, otherwise, add the "productvariantoption2" if it exists.
	 *      Use preferred terms for "color" and "productvariantoption2" if one of those fields.
	 *      If no preferred terms are found for the first existing field, import the "raw" value of the fallback field.
	 *      If the fallback field is not found, import the default value (in this case "Multicolor"):
	 *
	 *          $this->add_field( [ "color", "productvariantoption2" ], "color", "Multicolor" );
	 *
	 * @since 1.2.27
	 * @access protected
	 * @var array $field_values
	 */
	protected $field_values = [];

	/**
	 * Dfrpswc_Attribute_Importer constructor.
	 *
	 * @param string $slug The slug of the attribute we want to update. Example: "color" or "pa_color".
	 * @param string $value Current value of the attribute.
	 * @param string $attribute The current attribute slug we are processing. Example: "pa_color".
	 * @param array $product Datafeedr product array
	 */
	public function __construct( $slug, $value, $attribute, $product ) {
		$this->slug      = $this->set_slug( $slug );
		$this->value     = $value;
		$this->attribute = $attribute;
		$this->product   = $product;
	}

	/**
	 * Sets the $field_values array with values from the $field or $fallback_field and $default_value.
	 *
	 * @since 1.2.27
	 *
	 * @param array|string $field
	 * @param array|string $fallback_field If array, multiple string will be strung together and used as attribute value.
	 * @param string $default_value
	 */
	public function add_field( $field, $fallback_field = '', $default_value = '' ) {

		if ( ! $this->should_be_imported() ) {
			return;
		}

		$fields          = ( is_array( $field ) ) ? $this->clean_array( $field ) : [ $field ];
		$fallback_fields = ( is_array( $fallback_field ) ) ? $this->clean_array( $fallback_field ) : [ $fallback_field ];

		$value    = $this->get_fields_values( $fields );
		$fallback = $this->get_fields_values( $fallback_fields );

		if ( empty( $value ) && empty( $fallback ) && empty( $default_value ) ) {
			return;
		}

		$this->field_values[] = [
			'value'    => $value,
			'fallback' => $fallback,
			'default'  => $default_value
		];
	}

	/**
	 * Updates the $this->vocab array with controlled vocabulary.
	 *
	 * @since 1.2.27
	 *
	 * @param string $preferred_term Preferred term to use as attribute.
	 * @param array $term_variants Variants of preferred term.
	 * @param array $term_exclusions Variants of terms to skip/exclude.
	 */
	public function add_term( $preferred_term, $term_variants = [], $term_exclusions = [] ) {

		if ( ! $this->should_be_imported() ) {
			return;
		}

		$preferred_term = trim( $preferred_term );

		if ( empty( $preferred_term ) ) {
			return;
		}

		if ( ! is_array( $term_variants ) ) {
			return;
		}

		$term_variants = $this->clean_array( $term_variants );

		array_push( $term_variants, $preferred_term );

		$this->vocab[ $preferred_term ]['term_variants']   = $term_variants;
		$this->vocab[ $preferred_term ]['term_exclusions'] = $this->clean_array( $term_exclusions );
	}

	/**
	 * Returns the value to be used as the attribute value.
	 *
	 * @since 1.2.27
	 *
	 * @return string|array
	 */
	public function result() {

		if ( ! $this->should_be_imported() ) {
			return $this->value;
		}

		if ( ! isset( $this->field_values[0] ) || empty( $this->field_values[0] ) ) {
			return $this->value;
		}

		$field = $this->field_values[0];

		$value    = ( isset( $field['value'] ) ) ? trim( $field['value'] ) : '';
		$fallback = ( isset( $field['fallback'] ) ) ? trim( $field['fallback'] ) : '';
		$default  = ( isset( $field['default'] ) ) ? trim( $field['default'] ) : '';

		$terms = $this->get_terms( $value );

		if ( ! empty( $terms ) ) {
			return implode( $this->wc_delimiter(), $terms );
		} elseif ( ! empty( $fallback ) ) {
			return ( ! empty( $this->field_delimiter ) ) ?
				implode( $this->wc_delimiter(), $this->clean_array( explode( $this->field_delimiter, $fallback ) ) ) :
				$fallback;
		} elseif ( ! empty( $default ) ) {
			return $default;
		}

		return $this->value;
	}

	/**
	 * Get an array of Terms which matched the user's term variants if term variants existed.
	 *
	 * @since 1.2.27
	 *
	 * @param string $value
	 *
	 * @return array
	 */
	private function get_terms( $value ) {

		$terms = [];
		$value = str_ireplace( $this->value_search, $this->value_replace, $value );

		/**
		 * If $value is empty, there are no terms to match against preferred vocabulary.
		 */
		if ( empty( $value ) ) {
			return $terms;
		}

		/**
		 * If there is no vocab, then there's nothing to match the $value against.
		 */
		if ( empty( $this->vocab ) ) {
			return ( ! empty( $this->field_delimiter ) ) ?
				$this->clean_array( explode( $this->field_delimiter, $value ) ) :
				$this->clean_array( [ $value ] );
		}

		/**
		 * We have some preferred vocab, so let's see if our $value matches any of the vocab.
		 */
		foreach ( $this->vocab as $preferred_term => $term ) {

			$variants   = $term['term_variants'];
			$exclusions = $term['term_exclusions'];

			if ( $this->match_exists( $exclusions, $value ) ) {
				continue;
			}

			if ( $this->match_exists( $variants, $value ) ) {
				$terms[] = $preferred_term;
			}
		}

		return $this->clean_array( $terms );
	}

	/**
	 * Returns true if a match is found, otherwise false.
	 *
	 * @since 1.2.27
	 *
	 * @param array|string $needles
	 * @param string $haystack
	 *
	 * @return bool
	 */
	private function match_exists( $needles, $haystack ) {
		$needles = is_array( $needles ) ? $needles : [ $needles ];

		foreach ( $needles as $needle ) {
			if ( preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/iu', $haystack ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns true if we should process the current attribute. Otherwise returns false.
	 *
	 * @since 1.2.27
	 *
	 * @return bool
	 */
	public function should_be_imported() {
		return ( $this->slug === $this->attribute ) ? true : false;
	}

	/**
	 * Append rules to str_ireplace() search patterns.
	 *
	 * @since 1.2.27
	 *
	 * @param $search_pattern
	 * @param $replacement_pattern
	 */
	public function add_search_replace_pattern( $search_pattern, $replacement_pattern ) {
		$this->value_search[]  = $search_pattern;
		$this->value_replace[] = $replacement_pattern;
	}

	/**
	 * Returns properly formatted slug name like "pa_color".
	 *
	 * Converts any slug not prefixed with "pa_" with "pa_".
	 *
	 * @since 1.2.27
	 *
	 * @param string $slug
	 *
	 * @return string
	 */
	private function set_slug( $slug ) {
		$prefix = 'pa_';

		return ( $prefix === substr( $slug, 0, mb_strlen( $prefix ) ) ) ? $slug : $prefix . $slug;
	}

	/**
	 * Returns the values of each $product[ $field ] concatenated into a space-separated string.
	 *
	 * @since 1.2.27
	 *
	 * @param array $fields An array of $product fields
	 *
	 * @return string
	 */
	private function get_fields_values( $fields ) {

		$values = [];

		foreach ( $fields as $field ) {
			$concatenated_fields = explode( '.', $field );
			foreach ( $concatenated_fields as $concatenated_field ) {
				$values[] = $this->get_field_value( $concatenated_field );
			}
		}

		$value = implode( ' ', $this->clean_array( $values ) );

		return trim( $value );
	}

	/**
	 * Returns the value of a single $product's field if it exists, otherwise an empty string.
	 *
	 * @since 1.2.27
	 *
	 * @param string $field A $product field
	 *
	 * @return string
	 */
	private function get_field_value( $field ) {
		return ( isset( $this->product[ $field ] ) ) ? trim( $this->product[ $field ] ) : '';
	}

	/**
	 * Filters, uniquifies and trims values in array.
	 *
	 * @since 1.2.27
	 *
	 * @param array $arr
	 *
	 * @return array
	 */
	private function clean_array( $arr ) {
		return array_unique( array_filter( array_map( 'trim', $arr ) ) );
	}

	/**
	 * Return the WooCommerce term delimiter.
	 *
	 * @since 1.2.27
	 *
	 * @return string
	 */
	public function wc_delimiter() {
		return defined( 'WC_DELIMITER' ) ? WC_DELIMITER : '|';
	}
}
