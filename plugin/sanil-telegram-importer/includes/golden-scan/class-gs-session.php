<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — لایه‌ی داده‌ی Session (هسته‌ی موتور MotoGold).
 * قفل بر اساس claim/release اتمی روی locked_until/worker_id است، نه صرفاً
 * تغییر status — طبق تصمیم تاییدشده، برای جلوگیری از پردازش هم‌زمان یک
 * Session توسط Ajax tick / cron / تلاش دستی.
 */
class STI_GS_Session {

	public static function table() {
		return STI_GS_DB::sessions_table();
	}

	/**
	 * یک Session از یک آیتم پروفایل می‌سازد. به‌خاطر UNIQUE KEY(message_pk)
	 * روی جدول sessions، اگر قبلاً برای همین پیام Session ساخته شده باشد
	 * (حتی هم‌زمان از دو درخواست موازی)، همان Session موجود برگردانده
	 * می‌شود — نه یک ردیف تکراری.
	 */
	public static function create_from_profile_item( $profile_item_id ) {
		global $wpdb;
		$profile_item_id = (int) $profile_item_id;
		$items_table = STI_GS_DB::profile_items_table();
		/* 10.12.8 — eligibility مشترک با Selection: STI_GS_DB::candidate_joins() */

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT pi.id AS profile_item_id, pi.profile_id, m.id AS message_pk, m.channel_id, m.file_code, p.default_category_id
			 FROM {$items_table} pi
			 " . STI_GS_DB::candidate_joins() . "
			 WHERE pi.id = %d",
			$profile_item_id
		), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error( 'sti_gs_no_item', 'آیتم پروفایل پیدا نشد.' );
		}

		$existing = self::get_by_message_pk( (int) $row['message_pk'] );
		if ( $existing ) {
			return (int) $existing['id'];
		}

		$now = current_time( 'mysql' );
		$insert = array(
			'profile_item_id' => (int) $row['profile_item_id'],
			'message_pk'      => (int) $row['message_pk'],
			'channel_id'      => (int) $row['channel_id'],
			'file_code'       => $row['file_code'],
			'category_id'     => $row['default_category_id'] ? (int) $row['default_category_id'] : null,
			'state'           => 'SCANNED',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		// معماری زنجیره‌ای (۱۰.۸): حالت فعلی Feature Flag روی Session تازه ثبت می‌شود.
		// Sessionهای قدیمی (chain_mode NULL) به‌صورت خودکار legacy می‌مانند.
		if ( class_exists( 'STI_GS_Chain_Engine' )
			&& STI_GS_DB::column_exists( self::table(), 'chain_mode' ) ) {
			$insert['chain_mode'] = STI_GS_Chain_Engine::mode();
		}
		$ok = $wpdb->insert( self::table(), $insert );

		if ( ! $ok ) {
			// اگر هم‌زمان یک درخواست دیگر روی UNIQUE KEY(message_pk) برنده شد، همان را برگردان.
			$existing = self::get_by_message_pk( (int) $row['message_pk'] );
			if ( $existing ) {
				return (int) $existing['id'];
			}
			return new WP_Error( 'sti_gs_session_insert_failed', 'ساخت Session ناموفق بود.' );
		}

		$id = (int) $wpdb->insert_id;
		$wpdb->update( $items_table, array( 'status' => 'queued' ), array( 'id' => (int) $row['profile_item_id'] ) );

		return $id;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	public static function get_by_message_pk( $message_pk ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE message_pk = %d', (int) $message_pk ), ARRAY_A );
	}

	public static function list( $args = array() ) {
		global $wpdb;
		$where = array( '1=1' );
		$params = array();
		if ( ! empty( $args['channel_id'] ) ) {
			$where[] = 'channel_id = %d';
			$params[] = (int) $args['channel_id'];
		}
		if ( ! empty( $args['state'] ) ) {
			$where[] = 'state = %s';
			$params[] = (string) $args['state'];
		}
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 100;
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$params[] = $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	public static function update( $id, $data ) {
		global $wpdb;
		if ( empty( $data ) ) {
			return 0;
		}
		$allowed = array(
			'state', 'priority', 'queue_status', 'button_type', 'button_payload',
			'button_confidence', 'button_resolution_method', 'bot_username', 'bot_chat_id',
			'clicked_at', 'bot_verified_at', 'matched_inbox_id', 'match_score', 'match_breakdown',
			'downloaded_path', 'download_temp_path', 'download_status', 'bytes_downloaded', 'total_bytes',
			'file_size_bytes', 'telegram_file_id', 'telegram_unique_id', 'file_hash', 'storage_url',
			'duplicate_action', 'duplicate_of_product_id', 'product_id', 'attempts', 'stage',
			'error_reason', 'next_retry_at', 'category_id', 'last_polled_at', 'image_url',
			'scheduled_at', 'chain_mode', 'chain_current_step',
		);
		$row = array();
		$ignored = array();
		foreach ( $data as $k => $v ) {
			if ( in_array( $k, $allowed, true ) ) {
				$row[ $k ] = $v;
			} else {
				$ignored[] = $k;
			}
		}

		/**
		 * شکست بی‌صدا اینجا رخ می‌داد.
		 *
		 * ستون scheduled_at در نسخه‌ی ۲.۲ به Schema اضافه شد ولی به این
		 * فهرست اضافه نشد. enqueue() آن را می‌فرستاد، همین‌جا بی‌صدا دور
		 * ریخته می‌شد، و tick() که روی `scheduled_at IS NOT NULL` فیلتر
		 * می‌کند هیچ‌وقت چیزی پیدا نمی‌کرد — پس هیچ محصولی منتشر نشد.
		 *
		 * از بیرون شبیه «صف کار نمی‌کند» دیده می‌شد، در حالی که صف درست
		 * بود و فقط یک ستون گم می‌شد.
		 *
		 * رفتار قبلی حفظ شده (ستون ناشناخته همچنان نادیده گرفته می‌شود)،
		 * فقط دیگر بی‌صدا نیست.
		 */
		if ( $ignored && class_exists( 'STI_Logger' ) ) {
			STI_Logger::warning(
				'STI_GS_Session::update(): ستون ناشناخته نادیده گرفته شد: '
				. implode( ', ', $ignored ) . ' (Session #' . (int) $id . ')'
			);
		}
		if ( empty( $row ) ) {
			return 0;
		}
		$row['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	/** claim اتمی: فقط اگر قفل نبود یا قفل قدیمی منقضی شده بود، تصاحب می‌کند. */
	public static function claim( $id, $worker_id, $lock_seconds = 120 ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		$until = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + max( 10, (int) $lock_seconds ) );

		$affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET locked_until = %s, worker_id = %s, updated_at = %s
			 WHERE id = %d AND (locked_until IS NULL OR locked_until < %s)",
			$until, $worker_id, $now, (int) $id, $now
		) );
		return (bool) $affected;
	}

	/**
	 * release مالکیت‌محور: اگر $worker_id داده شود، فقط زمانی قفل باز می‌شود
	 * که هنوز متعلق به همان worker باشد. جلوی این سناریو را می‌گیرد: worker A
	 * کند است، قفلش به‌خاطر انقضا توسط worker B تصاحب می‌شود، بعد worker A
	 * دیر می‌رسد و با یک release بدون شرط، قفل تازه‌ی B را پاک می‌کند.
	 */
	public static function release( $id, $worker_id = null ) {
		global $wpdb;
		if ( null !== $worker_id ) {
			return $wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::table() . ' SET locked_until = NULL, worker_id = NULL WHERE id = %d AND worker_id = %s',
				(int) $id, (string) $worker_id
			) );
		}
		return $wpdb->update( self::table(), array( 'locked_until' => null, 'worker_id' => null ), array( 'id' => (int) $id ) );
	}
}
