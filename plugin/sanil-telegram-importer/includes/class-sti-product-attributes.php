<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Automatically fills WooCommerce's native "Attributes" (ویژگی‌ها) for each
 * product, using the exact same values already used in the description
 * (%format%, %software%, %filesize%, %dimensions%, %resolution%, %color%,
 * %jalali_date%, %code%). This lets a theme's built-in specs/attributes box
 * (like the "ویژگی‌ها" panel shown in WooCommerce) display them cleanly,
 * separate from the free-text description.
 *
 * - "فرمت" (format) uses the site's existing GLOBAL attribute taxonomy
 *   (pa_format) if one is already set up (as in your screenshots) — the
 *   correct term is auto-selected/created, matching your existing setup.
 * - Everything else uses simple per-product custom attributes, which need
 *   no pre-configuration and work on any WooCommerce site out of the box.
 * - Existing attributes already on a product (e.g. added manually) are
 *   preserved; only the plugin's own attributes are added/updated.
 * - Empty values (e.g. no dimensions supplied for this file) are skipped
 *   entirely rather than showing a blank row.
 */
class STI_Product_Attributes {

	/**
	 * Global attribute taxonomy slug to use for "format", if it exists on
	 * this site. Matches the "فرمت" / slug "format" attribute seen in your
	 * WooCommerce > Attributes screen.
	 */
	const FORMAT_ATTRIBUTE_SLUG = 'format';

	public static function apply( $product_id, $session, $category ) {
		if ( ! STI_Settings::get( 'auto_fill_attributes', 1 ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		// Re-key existing attributes by their own name ourselves (rather than
		// trusting WC's internal array keys), so our attributes correctly
		// overwrite same-named ones instead of appearing twice.
		$existing = array();
		foreach ( $product->get_attributes() as $attr ) {
			$existing[ $attr->get_name() ] = $attr;
		}

		$ours = self::build_attributes( $session );
		$merged = array_merge( $existing, $ours );

		$product->set_attributes( array_values( $merged ) );
		$product->save();
	}

	/**
	 * @return WC_Product_Attribute[] keyed by their internal name, so callers
	 * can merge them into an existing attribute set without duplicating.
	 */
	protected static function build_attributes( $session ) {
		$attrs = array();

		$format = STI_Content_Generator::type_format_public( $session->file_type );
		$format_attr = self::build_format_attribute( $format );
		if ( $format_attr ) {
			$attrs[ $format_attr->get_name() ] = $format_attr;
		}

		$specs = array(
			'نرم‌افزار'    => STI_Content_Generator::type_software_public( $session->file_type ),
			'حجم فایل'     => self::human_filesize( $session->file_size_bytes ?? null ),
			'ابعاد'        => $session->dimensions ?? '',
			'رزولوشن'      => $session->resolution ?? '',
			'رنگ'          => $session->color ?? '',
			'تاریخ انتشار' => STI_Content_Generator::jalali_today_public(),
			'شناسه مطلب'   => $session->file_code ?? '',
		);

		foreach ( $specs as $label => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue; // don't create empty attribute rows.
			}
			$attr = new WC_Product_Attribute();
			$attr->set_id( 0 ); // 0 = local/custom (non-global) attribute, no taxonomy needed.
			$attr->set_name( $label );
			$attr->set_options( array( $value ) );
			$attr->set_position( 0 );
			$attr->set_visible( true );
			$attr->set_variation( false );
			$attrs[ $label ] = $attr;
		}

		return $attrs;
	}

	/**
	 * Builds the "فرمت" attribute against the site's existing global
	 * pa_format taxonomy when available (auto-creating the specific term,
	 * e.g. "eps", if it doesn't exist yet); falls back to a plain local
	 * attribute if no such global taxonomy is configured on this site.
	 */
	protected static function build_format_attribute( $format_value ) {
		$format_value = trim( (string) $format_value );
		if ( '' === $format_value ) {
			return null;
		}

		$taxonomy = 'pa_' . self::FORMAT_ATTRIBUTE_SLUG;

		if ( taxonomy_exists( $taxonomy ) ) {
			// A site may list "EPS / AI" etc. — use only the first as the term to assign.
			$first_value = trim( explode( '/', $format_value )[0] );
			$term_slug = sanitize_title( $first_value );

			$term = get_term_by( 'slug', $term_slug, $taxonomy );
			if ( ! $term ) {
				$inserted = wp_insert_term( $first_value, $taxonomy, array( 'slug' => $term_slug ) );
				if ( is_wp_error( $inserted ) ) {
					STI_Logger::warning( 'ساخت ترم فرمت ناموفق بود: ' . $inserted->get_error_message() );
					return self::build_local_format_attribute( $format_value );
				}
				$term_id = $inserted['term_id'];
			} else {
				$term_id = $term->term_id;
			}

			$attribute_id = self::get_wc_attribute_id( self::FORMAT_ATTRIBUTE_SLUG );

			$attr = new WC_Product_Attribute();
			$attr->set_id( $attribute_id );
			$attr->set_name( $taxonomy );
			$attr->set_options( array( $term_id ) );
			$attr->set_position( 0 );
			$attr->set_visible( true );
			$attr->set_variation( false );
			return $attr;
		}

		// No global "format" taxonomy on this site — still show it, just as a local attribute.
		return self::build_local_format_attribute( $format_value );
	}

	protected static function build_local_format_attribute( $format_value ) {
		$attr = new WC_Product_Attribute();
		$attr->set_id( 0 );
		$attr->set_name( 'فرمت' );
		$attr->set_options( array( $format_value ) );
		$attr->set_position( 0 );
		$attr->set_visible( true );
		$attr->set_variation( false );
		return $attr;
	}

	protected static function get_wc_attribute_id( $slug ) {
		foreach ( wc_get_attribute_taxonomies() as $tax ) {
			if ( $tax->attribute_name === $slug ) {
				return (int) $tax->attribute_id;
			}
		}
		return 0;
	}

	protected static function human_filesize( $bytes ) {
		if ( empty( $bytes ) ) {
			return '';
		}
		return size_format( (int) $bytes, 2 );
	}
}
