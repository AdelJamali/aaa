<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class STI_Category {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_categories';
	}

	public static function get_active() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE is_active = 1 ORDER BY sort_order ASC, id ASC' );
	}

	public static function get_all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY sort_order ASC, id ASC' );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	public static function create( $data ) {
		global $wpdb;
		$data['created_at'] = current_time( 'mysql' );
		// insert_id isn't known yet, so the id-based fallback ("cat-{id}") can't be
		// produced up front for a label with no usable ASCII characters — insert
		// without folder_key in that case, then fix it up once the id exists.
		$raw_slug = empty( $data['folder_key'] ) ? self::raw_ascii_slug( $data['telegram_label'] ?? '' ) : self::raw_ascii_slug( $data['folder_key'] );
		$needs_id_fallback = '' === $raw_slug;
		if ( empty( $data['folder_key'] ) ) {
			$data['folder_key'] = $needs_id_fallback ? null : $raw_slug;
		}
		$wpdb->insert( self::table(), $data );
		$id = $wpdb->insert_id;
		if ( $needs_id_fallback ) {
			$wpdb->update( self::table(), array( 'folder_key' => 'cat-' . $id ), array( 'id' => $id ) );
		}
		return $id;
	}

	public static function update( $id, $data ) {
		global $wpdb;
		if ( array_key_exists( 'folder_key', $data ) ) {
			$data['folder_key'] = self::sanitize_folder_key( $data['folder_key'] ?: ( $data['telegram_label'] ?? '' ), $id );
		}
		return $wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	/**
	 * Turns arbitrary (often Persian/emoji) text into a short ASCII-only slug
	 * safe to use as an FTP/local directory name AND as a URL path segment.
	 * Deliberately strict (only a-z0-9-) rather than relying on sanitize_title()
	 * alone: sanitize_title() happily keeps non-Latin UTF-8 text (e.g. Persian),
	 * and raw non-ASCII bytes in a folder name are exactly what causes the
	 * "file saved but the final link 404s" bug — FTP servers and the HTTP
	 * server fronting the same files don't reliably agree on how those bytes
	 * are encoded/decoded. Falls back to "cat-{id}" if nothing usable remains.
	 */
	public static function sanitize_folder_key( $raw, $fallback_id ) {
		$slug = self::raw_ascii_slug( $raw );
		if ( '' === $slug ) {
			$slug = 'cat-' . max( 0, (int) $fallback_id );
		}
		return $slug;
	}

	/** ASCII-only slug with NO id-based fallback — may return ''. See sanitize_folder_key(). */
	protected static function raw_ascii_slug( $raw ) {
		$slug = sanitize_title( (string) $raw );
		$slug = preg_replace( '/[^a-z0-9-]/', '', $slug );
		$slug = trim( $slug, '-' );
		return mb_substr( $slug, 0, 60 );
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	/**
	 * Publish delay for a category: category override, else global default.
	 */
	public static function publish_delay( $category ) {
		if ( ! empty( $category->publish_delay_minutes ) ) {
			return (int) $category->publish_delay_minutes;
		}
		return (int) STI_Settings::get( 'default_publish_delay', 30 );
	}

	public static function storage_mode( $category ) {
		if ( ! empty( $category->storage_mode_override ) ) {
			return $category->storage_mode_override;
		}
		return null; // caller will use global setting
	}

	/**
	 * WooCommerce product categories, for the settings-page dropdown.
	 */
	public static function get_woo_terms() {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}
		return get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	}

	public static function build_inline_keyboard() {
		$cats = self::get_active();
		$rows = array();
		$row = array();
		foreach ( $cats as $i => $cat ) {
			$row[] = array( 'text' => $cat->telegram_label, 'callback_data' => 'sti_cat_' . $cat->id );
			if ( count( $row ) === 2 ) {
				$rows[] = $row;
				$row = array();
			}
		}
		if ( ! empty( $row ) ) {
			$rows[] = $row;
		}
		return array( 'inline_keyboard' => $rows );
	}
}
