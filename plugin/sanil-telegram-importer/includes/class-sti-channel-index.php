<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable index for Channel Import search candidates.
 *
 * This table intentionally sits between Telegram discovery and the download
 * pipeline.  Discovery can be retried without pressing a button again, and
 * download/product creation can be retried without scanning the channel again.
 * No candidate is allowed to reach Fileech until it has passed the duplicate
 * and category gates.
 */
class STI_Channel_Index {

	const TABLE_KEY = 'sti_channel_items';
	const DB_VER_KEY = 'sti_channel_index_db_ver';
	const DB_VER = '1.1';

	const DISCOVERED = 'discovered';
	const VALIDATED = 'validated';
	const TRIGGERED = 'triggered';
	const WAITING_FILE = 'waiting_file';
	const DOWNLOADED = 'downloaded';
	const PRODUCT_CREATED = 'product_created';
	const SKIPPED_DUPLICATE = 'skipped_duplicate';
	const REJECTED_CATEGORY = 'rejected_category';
	const REJECTED_NO_CODE = 'rejected_no_code';
	const ERROR = 'error';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_KEY;
	}

	public static function install() {
		global $wpdb;
		if ( get_option( self::DB_VER_KEY ) === self::DB_VER ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table = self::table();
		// The index is new, but a partially upgraded 1.0 table may already
		// contain duplicate codes. Keep the oldest row before adding UNIQUE.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists ) {
			$wpdb->query( "DELETE a FROM {$table} a INNER JOIN {$table} b ON a.file_code IS NOT NULL AND a.file_code <> '' AND a.file_code = b.file_code AND a.id > b.id" );
		}
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(64) NOT NULL DEFAULT '',
			source_chat_id BIGINT NOT NULL DEFAULT 0,
			source_username VARCHAR(190) NULL,
			source_message_id BIGINT NOT NULL DEFAULT 0,
			source_date BIGINT UNSIGNED NOT NULL DEFAULT 0,
			category_id BIGINT UNSIGNED DEFAULT NULL,
			search_term VARCHAR(190) NULL,
			file_code VARCHAR(100) NULL,
			file_name VARCHAR(255) NULL,
			file_type VARCHAR(80) NULL,
			caption_raw LONGTEXT NULL,
			button_type VARCHAR(40) NULL,
			button_url TEXT NULL,
			bot_username VARCHAR(190) NULL,
			bot_payload VARCHAR(255) NULL,
			button_data TEXT NULL,
			raw_payload LONGTEXT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'discovered',
			session_id BIGINT UNSIGNED DEFAULT NULL,
			inbox_id BIGINT UNSIGNED DEFAULT NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NULL,
			last_attempt_at DATETIME NULL,
			error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY source_message (source_chat_id, source_message_id),
			UNIQUE KEY file_code_unique (file_code),
			KEY batch_status_id (batch_id, status, id),
			KEY code_status (file_code, status),
			KEY next_attempt (status, next_attempt_at)
		) {$charset};";
	dbDelta( $sql );
		update_option( self::DB_VER_KEY, self::DB_VER, false );
	}

	/** Normalize a File Code once, before it is used for matching/SKU checks. */
	public static function normalize_code( $code ) {
		$code = trim( (string) $code );
		$from = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹', '٠','١','٢','٣','٤','٥','٦','٧','٨','٩' );
		$to   = array( '0','1','2','3','4','5','6','7','8','9', '0','1','2','3','4','5','6','7','8','9' );
		$code = str_replace( $from, $to, $code );
		$code = preg_replace( '/\s+/u', '', $code );
		$code = ltrim( $code, '#' );
		$code = strtolower( $code );
		$code = preg_replace( '/[^a-z0-9_-]/i', '', $code );
		return substr( (string) $code, 0, 100 );
	}

	/**
	 * Search terms for a category. Administrators can override these in the
	 * category editor; the built-in aliases are only a safe default.
	 */
	public static function search_terms( $category ) {
		if ( ! $category ) {
			return array();
		}
		$raw = (string) ( $category->search_terms ?? '' );
		$terms = preg_split( '/[\r\n,]+/u', $raw );
		$terms = is_array( $terms ) ? $terms : array();
		$label = mb_strtolower( trim( (string) ( $category->telegram_label ?? '' ) ) );
		$folder = mb_strtolower( trim( (string) ( $category->folder_key ?? '' ) ) );
		$combined = $label . ' ' . $folder;

		$aliases = array(
			'mockup' => array( 'mockup', 'mock up', 'product mockup', 'branding mockup', 'packaging mockup', 'logo mockup', 'موکاپ', 'مکاپ' ),
			'logo' => array( 'logo', 'logotype', 'brand mark', 'لوگو' ),
			'vector' => array( 'vector', 'illustration', 'ai', 'eps', 'svg', 'وکتور' ),
			'psd' => array( 'psd', 'photoshop', 'فایل لایه باز', 'لایه باز' ),
			'font' => array( 'font', 'ttf', 'otf', 'woff', 'فونت' ),
			'icon' => array( 'icon', 'icons', 'آیکون' ),
			'pattern' => array( 'pattern', 'seamless pattern', 'پترن' ),
			'texture' => array( 'texture', 'بافت', 'تکسچر' ),
			'template' => array( 'template', 'قالب' ),
			'motion' => array( 'motion', 'video', 'mp4', 'after effects', 'موشن', 'ویدئو' ),
			'3d' => array( '3d', 'blender', 'fbx', 'obj', 'سه بعدی', 'سه‌بعدی' ),
		);
		foreach ( $aliases as $key => $list ) {
			if ( false !== mb_strpos( $combined, $key ) ) {
				$terms = array_merge( $terms, $list );
			}
		}

		// Custom categories may be Persian/Arabic; their label is still a
		// valid Telegram search term and must not be discarded.
		foreach ( array( $label, $folder ) as $candidate ) {
			if ( $candidate && mb_strlen( $candidate ) >= 2 && 'cat-0' !== $candidate ) {
				$terms[] = $candidate;
			}
		}

		$out = array();
		foreach ( $terms as $term ) {
			$term = trim( preg_replace( '/\s+/u', ' ', (string) $term ) );
			if ( '' === $term || mb_strlen( $term ) < 2 ) { continue; }
			$key = mb_strtolower( $term );
			if ( isset( $out[ $key ] ) ) { continue; }
			$out[ $key ] = $term;
			if ( count( $out ) >= 20 ) { break; }
		}
		return array_values( $out );
	}

	/** Insert a message once; the unique source key makes discovery idempotent. */
	public static function discover( $data ) {
		global $wpdb;
		self::install();
		$chat = (int) ( $data['source_chat_id'] ?? 0 );
		$msg  = (int) ( $data['source_message_id'] ?? 0 );
		if ( ! $chat || ! $msg ) { return 0; }
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE source_chat_id = %d AND source_message_id = %d LIMIT 1',
			$chat, $msg
		) );
		if ( $existing ) {
			$row = self::get( $existing );
			if ( self::can_requeue( $row ) && (string) ( $row['batch_id'] ?? '' ) !== (string) ( $data['batch_id'] ?? '' ) ) {
				self::update( $existing, array(
					'batch_id' => sanitize_text_field( $data['batch_id'] ?? '' ),
					'category_id' => ! empty( $data['category_id'] ) ? (int) $data['category_id'] : null,
					'search_term' => sanitize_text_field( $data['search_term'] ?? '' ),
					'status' => self::DISCOVERED,
					'error_message' => '',
				) );
			}
			return $existing;
		}

		$now = current_time( 'mysql' );
		$row = array(
			'batch_id'          => sanitize_text_field( $data['batch_id'] ?? '' ),
			'source_chat_id'    => $chat,
			'source_username'   => sanitize_text_field( $data['source_username'] ?? '' ),
			'source_message_id' => $msg,
			'source_date'       => (int) ( $data['source_date'] ?? 0 ),
			'category_id'       => ! empty( $data['category_id'] ) ? (int) $data['category_id'] : null,
			'search_term'       => sanitize_text_field( $data['search_term'] ?? '' ),
			'file_code'         => self::normalize_code( $data['file_code'] ?? '' ) ?: null,
			'file_name'         => mb_substr( sanitize_text_field( $data['file_name'] ?? '' ), 0, 250 ),
			'file_type'         => mb_substr( sanitize_text_field( $data['file_type'] ?? '' ), 0, 80 ),
			'caption_raw'       => (string) ( $data['caption_raw'] ?? '' ),
			'button_type'       => sanitize_key( $data['button_type'] ?? '' ),
			'button_url'        => esc_url_raw( $data['button_url'] ?? '' ),
			'bot_username'      => sanitize_text_field( $data['bot_username'] ?? '' ),
			'bot_payload'       => sanitize_text_field( $data['bot_payload'] ?? '' ),
			'button_data'       => (string) ( $data['button_data'] ?? '' ),
			'raw_payload'       => ! empty( $data['raw_payload'] ) ? wp_json_encode( $data['raw_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
			'status'            => self::DISCOVERED,
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		$ok = $wpdb->insert( self::table(), $row );
		if ( ! $ok ) {
			// A concurrent worker may have inserted the same source message, or
			// the File Code may already be indexed. Reuse only a non-terminal row.
			$source_id = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE source_chat_id = %d AND source_message_id = %d LIMIT 1', $chat, $msg
			) );
			if ( $source_id ) { return $source_id; }
			$code = self::normalize_code( $data['file_code'] ?? '' );
			if ( $code ) {
				$code_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE file_code = %s LIMIT 1', $code ) );
				$row = $code_id ? self::get( $code_id ) : null;
				if ( $code_id && self::can_requeue( $row ) ) {
					self::update( $code_id, array( 'batch_id' => sanitize_text_field( $data['batch_id'] ?? '' ), 'category_id' => (int) ( $data['category_id'] ?? 0 ), 'search_term' => sanitize_text_field( $data['search_term'] ?? '' ), 'status' => self::DISCOVERED, 'error_message' => '' ) );
					return $code_id;
				}
			}
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	protected static function can_requeue( $row ) {
		if ( ! is_array( $row ) ) { return false; }
		if ( ! empty( $row['session_id'] ) ) { return false; }
		return in_array( (string) ( $row['status'] ?? '' ), array( self::DISCOVERED, self::ERROR, self::REJECTED_CATEGORY, self::REJECTED_NO_CODE ), true );
	}

	public static function get( $id ) {
		global $wpdb;
		self::install();
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	public static function get_batch_by_status( $batch_id, $status, $limit = 10 ) {
		global $wpdb;
		self::install();
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE batch_id = %s AND status = %s ORDER BY id ASC LIMIT %d',
			(string) $batch_id, (string) $status, max( 1, min( 100, (int) $limit ) )
		), ARRAY_A );
	}

	public static function count_batch_status( $batch_id, $status ) {
		global $wpdb;
		self::install();
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE batch_id = %s AND status = %s',
			(string) $batch_id, (string) $status
		) );
	}

	/** Atomically reserve a normalized File Code for an indexed row. */
	public static function bind_code( $id, $code ) {
		global $wpdb;
		self::install();
		$code = self::normalize_code( $code );
		if ( ! $code ) { return false; }
		$current = self::get( $id );
		if ( ! $current ) { return false; }
		if ( $code === (string) ( $current['file_code'] ?? '' ) ) { return true; }
		$ok = $wpdb->query( $wpdb->prepare(
				"UPDATE " . self::table() . " SET file_code = %s, updated_at = %s WHERE id = %d AND (file_code IS NULL OR file_code = '')",
			$code, current_time( 'mysql' ), (int) $id
		) );
		if ( $ok ) { return true; }
		$after = self::get( $id );
		return $after && $code === (string) ( $after['file_code'] ?? '' );
	}

	public static function update( $id, $data ) {
		global $wpdb;
		self::install();
		$allowed = array(
			'batch_id','category_id','search_term','file_code','file_name','file_type','caption_raw',
			'button_type','button_url','bot_username','bot_payload','button_data','raw_payload',
			'status','session_id','inbox_id','attempts','next_attempt_at','last_attempt_at','error_message',
			'source_date','updated_at',
		);
		$row = array();
		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) { continue; }
			if ( 'file_code' === $key ) { $value = self::normalize_code( $value ) ?: null; }
			$row[ $key ] = $value;
		}
		if ( empty( $row ) ) { return 0; }
		$row['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	/** Atomically move a candidate forward; duplicate workers cannot trigger twice. */
	public static function claim_for_validation( $id ) {
		global $wpdb;
		self::install();
		return (bool) $wpdb->query( $wpdb->prepare(
			"UPDATE " . self::table() . " SET status = %s, attempts = attempts + 1, last_attempt_at = %s, updated_at = %s WHERE id = %d AND status = %s",
			self::VALIDATED, current_time( 'mysql' ), current_time( 'mysql' ), (int) $id, self::DISCOVERED
		) );
	}

	/** Remove terminal audit rows after a retention window. */
	public static function cleanup( $days = 90 ) {
		global $wpdb;
		self::install();
		$cut = wp_date( 'Y-m-d H:i:s', time() - max( 7, (int) $days ) * DAY_IN_SECONDS, wp_timezone() );
		$terminal = array( self::PRODUCT_CREATED, self::SKIPPED_DUPLICATE, self::REJECTED_CATEGORY, self::REJECTED_NO_CODE );
		$placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
		$args = array_merge( $terminal, array( $cut ) );
		return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE status IN ({$placeholders}) AND updated_at < %s", $args ) );
	}

	public static function reclaim_stale( $minutes = 30 ) {
		global $wpdb;
		self::install();
		$cut = wp_date( 'Y-m-d H:i:s', time() - max( 5, (int) $minutes ) * MINUTE_IN_SECONDS, wp_timezone() );
		return $wpdb->query( $wpdb->prepare(
			"UPDATE " . self::table() . " SET status = %s, error_message = %s, updated_at = %s WHERE ((status = %s AND (session_id IS NULL OR session_id = 0)) OR status = %s) AND last_attempt_at IS NOT NULL AND last_attempt_at < %s",
			self::DISCOVERED, 'تلاش قبلی نیمه‌کاره ماند و برای پردازش دوباره آزاد شد.', current_time( 'mysql' ), self::VALIDATED, self::TRIGGERED, $cut
		) );
	}
}
