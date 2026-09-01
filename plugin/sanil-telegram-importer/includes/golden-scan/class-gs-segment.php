<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — بخش‌های اسکن موازی.
 *
 * برای اسکن سریع کانال‌های بزرگ (مثلاً ۶۰هزار پیام)، بازه‌ی شناسه‌ی پیام‌ها
 * به N بخش مساوی تقسیم می‌شود و هر بخش مستقل جلو می‌رود. همپوشانی مرزی
 * بی‌ضرر است چون ذخیره‌سازی پیام‌ها idempotent است (ON DUPLICATE KEY UPDATE)،
 * پس هیچ پیامی دوبار در دیتابیس ثبت نمی‌شود؛ این تقسیم‌بندی فقط برای موازی‌سازی
 * سرعت است، نه برای صحت داده.
 *
 * قفل هر بخش در ستون locked_until (نه transient) نگه داشته می‌شود تا اگر
 * Ajax tick و wp-cron tick هم‌زمان اجرا شدند، هرگز یک بخش دوبار پردازش نشود.
 */
class STI_GS_Segment {

	const STATUS_PENDING = 'pending';
	const STATUS_RUNNING = 'running';
	const STATUS_DONE    = 'done';
	const STATUS_ERROR   = 'error';

	public static function table() {
		return STI_GS_DB::scan_segments_table();
	}

	/**
	 * بازه‌ی [1, $top_id] را به $count بخش مساوی تقسیم و ذخیره می‌کند.
	 * اجرای دوباره روی یک کانال، بخش‌های قبلی را پاک و از نو می‌سازد.
	 */
	public static function create_for_channel( $channel_id, $top_id, $count ) {
		global $wpdb;
		$channel_id = (int) $channel_id;
		$top_id     = max( 1, (int) $top_id );
		$count      = max( 1, min( 12, (int) $count ) );

		self::delete_for_channel( $channel_id );

		$size = (int) ceil( $top_id / $count );
		$now  = current_time( 'mysql' );
		$rows = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$range_to   = $top_id - ( $i * $size );
			$range_from = max( 1, $range_to - $size + 1 );
			if ( $range_to < 1 ) {
				break;
			}
			$wpdb->insert( self::table(), array(
				'channel_id'      => $channel_id,
				'segment_index'   => $i,
				'range_from'      => $range_from,
				'range_to'        => $range_to,
				'current_offset'  => $range_to,
				'status'          => self::STATUS_PENDING,
				'created_at'      => $now,
				'updated_at'      => $now,
			) );
			$rows[] = $wpdb->insert_id;
		}
		return $rows;
	}

	public static function delete_for_channel( $channel_id ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'channel_id' => (int) $channel_id ) );
	}

	public static function list_for_channel( $channel_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE channel_id = %d ORDER BY segment_index ASC',
			(int) $channel_id
		), ARRAY_A );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	/** یک بخش‌ِ آماده (قفل‌نشده و ناتمام) را برای پردازش برمی‌گرداند، یا null. */
	public static function next_available( $channel_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . "
			 WHERE channel_id = %d AND status IN ('pending','running')
			 AND (locked_until IS NULL OR locked_until < %s)
			 ORDER BY segment_index ASC LIMIT 1",
			(int) $channel_id,
			current_time( 'mysql' )
		), ARRAY_A );
	}

	/** قفل اتمی: فقط اگر هنوز آزاد بود قفل می‌شود. true = موفق. */
	public static function claim( $id, $lock_seconds = 90 ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		$until = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + max( 10, (int) $lock_seconds ) );

		$affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET locked_until = %s, status = IF(status='pending','running',status), updated_at = %s
			 WHERE id = %d AND status IN ('pending','running') AND (locked_until IS NULL OR locked_until < %s)",
			$until, $now, (int) $id, $now
		) );
		return (bool) $affected;
	}

	public static function release( $id ) {
		global $wpdb;
		return $wpdb->update( self::table(), array( 'locked_until' => null ), array( 'id' => (int) $id ) );
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$allowed = array( 'current_offset', 'status', 'messages_saved', 'last_error', 'locked_until' );
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

	/** خلاصه‌ی پیشرفت همه‌ی بخش‌های یک کانال، برای نمایش در پنل. */
	public static function progress_summary( $channel_id ) {
		$segments = self::list_for_channel( $channel_id );
		$total = count( $segments );
		$done  = 0;
		$saved = 0;
		$errors = 0;
		foreach ( $segments as $s ) {
			if ( self::STATUS_DONE === $s['status'] ) { $done++; }
			if ( self::STATUS_ERROR === $s['status'] ) { $errors++; }
			$saved += (int) $s['messages_saved'];
		}
		return array(
			'total_segments' => $total,
			'done_segments'  => $done,
			'error_segments' => $errors,
			'messages_saved' => $saved,
			'segments'       => $segments,
			'all_done'       => $total > 0 && $done + $errors >= $total,
		);
	}
}
