<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — فاز ۲: پروفایل‌ها و فیلتر کلمه‌کلیدی محلی.
 *
 * کاملاً روی داده‌ی از قبل اسکن‌شده (sti_gs_messages) کار می‌کند؛ هیچ تماسی
 * با تلگرام نمی‌گیرد. فیلتر با یک یا چند کوئری SQL روی متن/عنوان دکمه/نام
 * فایل هر پیام انجام می‌شود؛ برای همین حتی روی ۶۰هزار پیام هم تقریباً آنی است
 * و نیازی به Ajax polling/رزومه ندارد.
 */
class STI_GS_Profile {

	const STATUS_PENDING = 'pending';
	const STATUS_DONE    = 'done';
	const STATUS_ERROR   = 'error';

	public static function table() {
		return STI_GS_DB::profiles_table();
	}

	public static function items_table() {
		return STI_GS_DB::profile_items_table();
	}

	/** کلمات را از یک متن چندخطی/کاما‌جدا به آرایه‌ی تمیز تبدیل می‌کند. */
	public static function parse_keywords( $raw ) {
		$parts = preg_split( '/[\r\n,]+/u', (string) $raw );
		$out = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( '' !== $p && ! in_array( $p, $out, true ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	public static function keywords_array( $profile ) {
		return self::parse_keywords( $profile['keywords'] ?? '' );
	}

	public static function create( $channel_id, $name, $keywords_raw, $match_mode = 'any', $default_category_id = 0 ) {
		global $wpdb;
		STI_GS_DB::install();

		$channel_id = (int) $channel_id;
		$name = trim( (string) $name );
		$keywords = self::parse_keywords( $keywords_raw );

		if ( ! $channel_id || ! STI_GS_Channel::get( $channel_id ) ) {
			return new WP_Error( 'sti_gs_no_channel', 'کانال پیدا نشد.' );
		}
		if ( '' === $name ) {
			return new WP_Error( 'sti_gs_no_name', 'نام پروفایل را وارد کن.' );
		}
		if ( empty( $keywords ) ) {
			return new WP_Error( 'sti_gs_no_keywords', 'حداقل یک کلمه‌کلیدی وارد کن.' );
		}

		$now = current_time( 'mysql' );
		$ok = $wpdb->insert( self::table(), array(
			'channel_id'           => $channel_id,
			'name'                 => $name,
			'keywords'             => implode( "\n", $keywords ),
			'match_mode'           => 'all' === $match_mode ? 'all' : 'any',
			'default_category_id'  => $default_category_id ? (int) $default_category_id : null,
			'status'               => self::STATUS_PENDING,
			'matched_count'        => 0,
			'created_at'           => $now,
			'updated_at'           => $now,
		) );

		if ( ! $ok ) {
			return new WP_Error( 'sti_gs_profile_insert_failed', 'ثبت پروفایل ناموفق بود.' );
		}
		return (int) $wpdb->insert_id;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	public static function all_for_channel( $channel_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE channel_id = %d ORDER BY id DESC',
			(int) $channel_id
		), ARRAY_A );
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$allowed = array( 'name', 'keywords', 'match_mode', 'default_category_id', 'status', 'matched_count' );
		$row = array();
		foreach ( $data as $k => $v ) {
			if ( in_array( $k, $allowed, true ) ) {
				$row[ $k ] = $v;
			}
		}
		if ( empty( $row ) ) { return 0; }
		$row['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( self::items_table(), array( 'profile_id' => $id ) );
		return $wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	/**
	 * اجرای فیلتر: روی sti_gs_messages کانالِ پروفایل، پیام‌هایی که با کلمات
	 * کلیدی مطابقت دارند را در sti_gs_profile_items ثبت می‌کند. Idempotent
	 * است؛ اجرای دوباره فقط ردیف‌های تکراری را نادیده می‌گیرد.
	 *
	 * @return array|WP_Error { matched_count }
	 */
	public static function run( $profile_id ) {
		global $wpdb;
		$profile = self::get( $profile_id );
		if ( ! $profile ) {
			return new WP_Error( 'sti_gs_no_profile', 'پروفایل پیدا نشد.' );
		}
		$keywords = self::keywords_array( $profile );
		if ( empty( $keywords ) ) {
			return new WP_Error( 'sti_gs_no_keywords', 'این پروفایل هیچ کلمه‌کلیدی ندارد.' );
		}

		$messages_table = STI_GS_DB::messages_table();
		$items_table    = self::items_table();
		$channel_id     = (int) $profile['channel_id'];
		$now            = current_time( 'mysql' );

		self::update( $profile_id, array( 'status' => self::STATUS_PENDING ) );

		try {
			if ( 'all' === $profile['match_mode'] ) {
				// همه‌ی کلمات باید هم‌زمان در یک پیام باشند → یک کوئری با AND.
				$conditions = array();
				$args = array();
				foreach ( $keywords as $kw ) {
					$like = '%' . $wpdb->esc_like( $kw ) . '%';
					$conditions[] = '(m.text_raw LIKE %s OR m.button_summary LIKE %s OR IFNULL(m.file_name,\'\') LIKE %s)';
					array_push( $args, $like, $like, $like );
				}
				$where = implode( ' AND ', $conditions );
				$matched_label = implode( ', ', $keywords );

				$sql = "INSERT INTO {$items_table} (profile_id, message_pk, matched_keyword, status, created_at)
					SELECT %d, m.id, %s, 'available', %s
					FROM {$messages_table} m
					WHERE m.channel_id = %d AND ( {$where} )
					ON DUPLICATE KEY UPDATE matched_keyword = matched_keyword";

				$prepared_args = array_merge( array( $profile_id, $matched_label, $now, $channel_id ), $args );
				$wpdb->query( $wpdb->prepare( $sql, $prepared_args ) );
			} else {
				// هر کلمه به‌تنهایی کافی است → یک کوئری جدا به‌ازای هر کلمه (OR طبیعی از طریق UNIQUE KEY تجمیع می‌شود).
				foreach ( $keywords as $kw ) {
					$like = '%' . $wpdb->esc_like( $kw ) . '%';
					$sql = "INSERT INTO {$items_table} (profile_id, message_pk, matched_keyword, status, created_at)
						SELECT %d, m.id, %s, 'available', %s
						FROM {$messages_table} m
						WHERE m.channel_id = %d
						AND ( m.text_raw LIKE %s OR m.button_summary LIKE %s OR IFNULL(m.file_name,'') LIKE %s )
						ON DUPLICATE KEY UPDATE matched_keyword = matched_keyword";
					$wpdb->query( $wpdb->prepare( $sql, $profile_id, $kw, $now, $channel_id, $like, $like, $like ) );
				}
			}
		} catch ( \Throwable $e ) {
			self::update( $profile_id, array( 'status' => self::STATUS_ERROR ) );
			return new WP_Error( 'sti_gs_filter_failed', $e->getMessage() );
		}

		/**
		 * شمارش فقط Candidateهای پردازش‌نشده.
		 *
		 * پیش از این همه‌ی ردیف‌ها شمرده می‌شدند، از جمله آن‌هایی که
		 * `queued` شده و Session دارند. برای همین فهرست پروفایل فایل‌هایی
		 * را «دوباره می‌آورد» که قبلاً پردازش شده بودند.
		 *
		 * داده حذف نمی‌شود — فقط شمارش و نمایش واقعیت را نشان می‌دهند.
		 */
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $items_table . ' WHERE profile_id = %d AND status = %s',
			(int) $profile_id, 'available'
		) );

		self::update( $profile_id, array( 'status' => self::STATUS_DONE, 'matched_count' => $count ) );

		return array( 'matched_count' => $count );
	}

	/** برای پیش‌نمایش/بررسی کیفیت: چند نمونه از پیام‌های match‌شده به‌همراه متن اصلی. */
	public static function sample_items( $profile_id, $limit = 20 ) {
		global $wpdb;
		$items_table = self::items_table();
		$messages_table = STI_GS_DB::messages_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT pi.id AS profile_item_id, pi.matched_keyword, pi.status, m.message_id, m.text_raw, m.button_summary, m.file_code, m.file_name, m.media_type
			 FROM {$items_table} pi
			 INNER JOIN {$messages_table} m ON m.id = pi.message_pk
			 WHERE pi.profile_id = %d AND pi.status = %s
			 ORDER BY m.message_id DESC
			 LIMIT %d",
			(int) $profile_id, 'available', max( 1, (int) $limit )
		), ARRAY_A );
	}

	public static function item_count( $profile_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::items_table() . ' WHERE profile_id = %d', (int) $profile_id
		) );
	}
}
