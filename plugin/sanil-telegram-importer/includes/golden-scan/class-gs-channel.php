<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — مدیریت کانال‌های ثبت‌شده برای اسکن.
 *
 * هر کانال یک ردیف در sti_gs_channels دارد که پیشرفت اسکن (نقطه‌ی resume) را
 * نگه می‌دارد تا هرگز نیازی به اسکن مجدد از صفر نباشد.
 */
class STI_GS_Channel {

	const STATUS_IDLE     = 'idle';
	const STATUS_RUNNING  = 'running';
	const STATUS_PAUSED   = 'paused';
	const STATUS_DONE     = 'done';
	const STATUS_ERROR    = 'error';

	public static function table() {
		return STI_GS_DB::channels_table();
	}

	/**
	 * ورودی خام کاربر (یوزرنیم، لینک t.me، یا لینک دعوت خصوصی) را به یک
	 * شناسه‌ی قابل‌ذخیره تبدیل می‌کند. به‌جای بازنویسی پارسر، مستقیماً از
	 * STI_MTProto::normalize_identifier() (زیرساخت مشترک موجود) استفاده
	 * می‌شود؛ تشخیص chat_id عددی واقعی هم بر عهده‌ی STI_MTProto::chat_info() است.
	 */
	public static function clean_identifier( $input ) {
		$input = trim( (string) $input );
		if ( '' === $input ) {
			return '';
		}
		if ( class_exists( 'STI_MTProto' ) ) {
			$clean = STI_MTProto::normalize_identifier( $input );
			return '' !== $clean ? $clean : $input;
		}
		return ltrim( rtrim( $input, '/' ), '@' );
	}

	public static function get( $id ) {
		global $wpdb;
		STI_GS_DB::install();
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	public static function get_by_identifier( $identifier ) {
		global $wpdb;
		STI_GS_DB::install();
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE identifier = %s', (string) $identifier ), ARRAY_A );
	}

	public static function all( $limit = 100 ) {
		global $wpdb;
		STI_GS_DB::install();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', max( 1, (int) $limit ) ), ARRAY_A );
	}

	/**
	 * ثبت کانال جدید. اگر قبلاً ثبت شده باشد، همان ردیف موجود برگردانده می‌شود
	 * (اسکن مجدد از صفر انجام نمی‌شود).
	 *
	 * @return int|WP_Error شناسه‌ی ردیف
	 */
	public static function add( $raw_identifier ) {
		global $wpdb;
		STI_GS_DB::install();

		$identifier = self::clean_identifier( $raw_identifier );
		if ( '' === $identifier ) {
			return new WP_Error( 'sti_gs_bad_identifier', 'شناسه‌ی کانال نامعتبر است.' );
		}

		$existing = self::get_by_identifier( $identifier );
		if ( $existing ) {
			return (int) $existing['id'];
		}

		$now = current_time( 'mysql' );
		$ok = $wpdb->insert( self::table(), array(
			'identifier'               => $identifier,
			'chat_id'                  => 0,
			'title'                    => null,
			'total_messages'           => 0,
			'last_scanned_message_id'  => 0,
			'scan_status'              => self::STATUS_IDLE,
			'created_at'               => $now,
			'updated_at'               => $now,
		) );

		if ( ! $ok ) {
			return new WP_Error( 'sti_gs_channel_insert_failed', 'ثبت کانال ناموفق بود.' );
		}
		$new_id = (int) $wpdb->insert_id;
		if ( class_exists( 'STI_GS_Event' ) ) {
			STI_GS_Event::log( 0, 'channel_created', 'ok', 'کانال ثبت شد: ' . $identifier,
				null, array( 'channel_id' => $new_id, 'identifier' => $identifier ) );
		}
		return $new_id;
	}

	public static function update( $id, $data ) {
		global $wpdb;
		STI_GS_DB::install();
		$allowed = array(
			'chat_id', 'title', 'total_messages', 'last_scanned_message_id',
			'scan_status', 'scan_mode', 'top_message_id', 'last_error', 'last_scanned_at',
		);
		$row = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$row[ $key ] = $value;
			}
		}
		if ( empty( $row ) ) {
			return 0;
		}
		$row['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * حذف کانال به‌همراه تمام وابستگی‌های گلدن اسکن: پیام‌ها، بخش‌های اسکن
	 * موازی، پروفایل‌ها و آیتم‌های آن‌ها، و تمام Sessionهای مرتبط (با
	 * Artifact/Event/Candidate خودشان) — تا هیچ داده‌ی یتیمی نماند و ثبت
	 * دوباره‌ی یک کانال هرگز به داده‌ی قدیمی گیر نکند.
	 */
	public static function delete( $id ) {
		global $wpdb;
		STI_GS_DB::install();
		$id = (int) $id;
		$before = self::get( $id );

		// ۱) Sessionهای این کانال + لاگ‌هایشان
		if ( class_exists( 'STI_GS_Session' ) ) {
			$session_ids = $wpdb->get_col( $wpdb->prepare(
				'SELECT id FROM ' . STI_GS_Session::table() . ' WHERE channel_id = %d', $id
			) );
			foreach ( $session_ids as $sid ) {
				if ( class_exists( 'STI_GS_Artifact' ) ) {
					$wpdb->delete( STI_GS_DB::artifacts_table(), array( 'session_id' => (int) $sid ) );
				}
				if ( class_exists( 'STI_GS_Event' ) ) {
					$wpdb->delete( STI_GS_DB::session_events_table(), array( 'session_id' => (int) $sid ) );
				}
				if ( class_exists( 'STI_GS_Bot_Candidate' ) ) {
					$wpdb->delete( STI_GS_DB::bot_candidates_table(), array( 'session_id' => (int) $sid ) );
				}
			}
			$wpdb->delete( STI_GS_Session::table(), array( 'channel_id' => $id ) );
		}

		// ۲) پروفایل‌ها و آیتم‌های این کانال
		if ( class_exists( 'STI_GS_Profile' ) ) {
			$profile_ids = $wpdb->get_col( $wpdb->prepare(
				'SELECT id FROM ' . STI_GS_Profile::table() . ' WHERE channel_id = %d', $id
			) );
			foreach ( $profile_ids as $pid ) {
				$wpdb->delete( STI_GS_DB::profile_items_table(), array( 'profile_id' => (int) $pid ) );
			}
			$wpdb->delete( STI_GS_Profile::table(), array( 'channel_id' => $id ) );
		}

		// ۳) پیام‌ها و بخش‌های اسکن موازی
		$wpdb->delete( STI_GS_DB::messages_table(), array( 'channel_id' => $id ) );
		if ( class_exists( 'STI_GS_Segment' ) ) {
			STI_GS_Segment::delete_for_channel( $id );
		}

		// ۴) خودِ کانال
		$result = $wpdb->delete( self::table(), array( 'id' => $id ) );
		if ( $result && class_exists( 'STI_GS_Event' ) && $before ) {
			STI_GS_Event::log( 0, 'channel_deleted', 'ok', 'کانال #' . $id . ' حذف شد.',
				null, array( 'channel_id' => $id, 'identifier' => $before['identifier'] ) );
		}
		return $result;
	}

	/**
	 * ویرایش شناسه‌ی یک کانال موجود (مثلاً وقتی اشتباه تایپی ثبت شده) — بدون
	 * نیاز به حذف و ثبت دوباره یا SQL دستی. وضعیت resolve از نو شروع می‌شود.
	 */
	public static function update_identifier( $id, $new_raw_identifier ) {
		global $wpdb;
		STI_GS_DB::install();
		$id = (int) $id;

		// طبق Task 5: Validate → Normalize → Check duplicate → فقط بعد از عبور از همه، UPDATE بزن.
		// اگر تداخل پیدا شد، کانال قدیمی دست‌نخورده می‌ماند (هیچ UPDATE ای زده نمی‌شود).
		$before = self::get( $id );
		if ( ! $before ) {
			return new WP_Error( 'sti_gs_no_channel', 'کانال پیدا نشد.' );
		}

		$new_identifier = self::clean_identifier( $new_raw_identifier );
		if ( '' === $new_identifier ) {
			return new WP_Error( 'sti_gs_bad_identifier', 'شناسه‌ی جدید نامعتبر است.' );
		}

		$conflict = self::get_by_identifier( $new_identifier );
		if ( $conflict && (int) $conflict['id'] !== $id ) {
			return new WP_Error( 'sti_gs_identifier_conflict', 'این شناسه قبلاً برای کانال دیگری ثبت شده است (کانال فعلی دست‌نخورده ماند).' );
		}

		$ok = $wpdb->update( self::table(), array(
			'identifier'   => $new_identifier,
			'chat_id'      => 0, // باید دوباره resolve شود
			'title'        => null,
			'scan_status'  => self::STATUS_IDLE,
			'last_error'   => '',
			'updated_at'   => current_time( 'mysql' ),
		), array( 'id' => $id ) );

		if ( false === $ok ) {
			return new WP_Error( 'sti_gs_update_failed', 'بروزرسانی شناسه ناموفق بود.' );
		}
		if ( class_exists( 'STI_GS_Event' ) ) {
			STI_GS_Event::log( 0, 'channel_updated', 'ok', 'شناسه‌ی کانال #' . $id . ' بروزرسانی شد.',
				null, array( 'channel_id' => $id, 'old_identifier' => $before['identifier'], 'new_identifier' => $new_identifier ) );
		}
		return true;
	}

	public static function message_count( $id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . STI_GS_DB::messages_table() . ' WHERE channel_id = %d',
			(int) $id
		) );
	}
}
