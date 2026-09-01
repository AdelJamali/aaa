<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Scan Run (یک «اجرای اسکن»).
 *
 * این همان موجودیتی است که Spec §12 «Session» می‌نامد و تا امروز در پروژه
 * وجود نداشت. نباید با sti_gs_pipeline_items (یک محصول در حال پردازش)
 * اشتباه گرفته شود.
 *
 *   Scan Run      = «اسکن ۵۰۰ پیام آخر کانال FileechParty»  → یک ردیف
 *   Pipeline Item = «محصول در حال ساخت از پیام #12345»       → یک ردیف
 *
 * این کلاس فقط لایه‌ی داده است. هیچ‌جا خودش را به Scanner وصل نمی‌کند —
 * اتصال در اولویت سه (Limited Scan) انجام می‌شود، تا کد کارکنندهٔ فعلی
 * دست‌نخورده بماند (§157).
 *
 * تمام شمارنده‌ها با UPDATE اتمی (col = col + n) بالا می‌روند، نه با
 * read-modify-write — چون اسکن موازی چند segment را هم‌زمان اجرا می‌کند و
 * هر کدام روی همان Scan Run گزارش می‌دهند.
 */
class STI_GS_Scan_Run {

	/* حالت‌های اسکن — Spec §9، §10 */
	const MODE_FULL    = 'full';     // کل کانال
	const MODE_LIMITED = 'limited';  // N پیام آخر
	const MODE_RANGE   = 'range';    // بازه‌ی زمانی
	const MODE_RESUME  = 'resume';   // ادامه‌ی اسکن قبلی

	/* وضعیت‌ها — Spec §81 (بخش Scan) */
	const STATUS_PENDING   = 'PENDING';
	const STATUS_RUNNING   = 'RUNNING';
	const STATUS_PAUSED    = 'PAUSED';
	const STATUS_COMPLETED = 'COMPLETED';
	const STATUS_FAILED    = 'FAILED';
	const STATUS_CANCELLED = 'CANCELLED';

	/**
	 * لنگر (offset شروع) هر Run.
	 *
	 * جای درستش یک ستون در sti_gs_scan_runs بود، اما لایه‌ی Schema فعلاً
	 * Freeze است. تا باز شدن آن، در یک option به‌ازای هر Run نگهداری می‌شود.
	 * انتقالش بعداً یک ALTER افزودنی + یک backfill ساده است.
	 */
	const ANCHOR_OPTION_PREFIX = 'sti_gs_run_anchor_';

	public static function table() {
		return STI_GS_DB::scan_runs_table();
	}

	public static function set_anchor( $run_id, $from_message_id ) {
		update_option( self::ANCHOR_OPTION_PREFIX . (int) $run_id, (int) $from_message_id, false );
	}

	public static function anchor( $run_id ) {
		return (int) get_option( self::ANCHOR_OPTION_PREFIX . (int) $run_id, 0 );
	}

	public static function is_limited( $run ) {
		return is_array( $run ) && self::MODE_LIMITED === $run['scan_mode'];
	}

	public static function modes() {
		return array( self::MODE_FULL, self::MODE_LIMITED, self::MODE_RANGE, self::MODE_RESUME );
	}

	public static function open_statuses() {
		return array( self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_PAUSED );
	}

	/**
	 * شروع یک اجرای اسکن تازه.
	 *
	 * @param int    $channel_id
	 * @param string $mode  یکی از MODE_*
	 * @param array  $args  limit_count, range_from, range_to
	 * @return int|WP_Error شناسه‌ی Scan Run
	 */
	public static function start( $channel_id, $mode = self::MODE_FULL, $args = array() ) {
		global $wpdb;

		$channel_id = (int) $channel_id;
		if ( ! $channel_id || ! STI_GS_Channel::get( $channel_id ) ) {
			return new WP_Error( 'sti_gs_no_channel', 'کانال پیدا نشد.' );
		}

		$mode = in_array( $mode, self::modes(), true ) ? $mode : self::MODE_FULL;
		$limit_count = max( 0, (int) ( $args['limit_count'] ?? 0 ) );

		if ( self::MODE_LIMITED === $mode && $limit_count < 1 ) {
			return new WP_Error( 'sti_gs_no_limit', 'برای اسکن محدود باید تعداد پیام مشخص شود.' );
		}

		$now = current_time( 'mysql' );
		$ok = $wpdb->insert( self::table(), array(
			'channel_id'         => $channel_id,
			'scan_mode'          => $mode,
			'limit_count'        => $limit_count,
			'range_from'         => ! empty( $args['range_from'] ) ? $args['range_from'] : null,
			'range_to'           => ! empty( $args['range_to'] ) ? $args['range_to'] : null,
			'status'             => self::STATUS_RUNNING,
			'requested_messages' => $limit_count,
			'started_at'         => $now,
			'created_at'         => $now,
			'updated_at'         => $now,
		) );

		if ( ! $ok ) {
			return new WP_Error( 'sti_gs_scan_run_insert_failed', 'ثبت Scan Run ناموفق بود: ' . $wpdb->last_error );
		}

		$id = (int) $wpdb->insert_id;

		// لنگر: 0 یعنی «از جدیدترین پیام کانال». هر مقدار دیگری یعنی
		// «از این message_id به پایین» — همان «From message ID» در §10.
		self::set_anchor( $id, (int) ( $args['from_message_id'] ?? 0 ) );

		if ( class_exists( 'STI_GS_Event' ) ) {
			STI_GS_Event::log( 0, 'scan_run_started', 'ok',
				'Scan Run #' . $id . ' شروع شد.', null,
				array( 'scan_run_id' => $id, 'channel_id' => $channel_id, 'mode' => $mode, 'limit' => $limit_count )
			);
		}

		return $id;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id
		), ARRAY_A );
	}

	/** آخرین Scan Run باز (PENDING/RUNNING/PAUSED) برای یک کانال. */
	public static function current_for_channel( $channel_id ) {
		global $wpdb;
		$statuses = self::open_statuses();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params = array_merge( array( (int) $channel_id ), $statuses );
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE channel_id = %d AND status IN ({$placeholders}) ORDER BY id DESC LIMIT 1",
			$params
		), ARRAY_A );
	}

	public static function list_for_channel( $channel_id, $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE channel_id = %d ORDER BY id DESC LIMIT %d',
			(int) $channel_id, max( 1, (int) $limit )
		), ARRAY_A );
	}

	/**
	 * ثبت نتیجه‌ی یک Batch — اتمی، برای اسکن موازی امن است.
	 *
	 * نکته برای اولویت سه: تفکیک «تازه» از «تکراری» با همان
	 * INSERT ... ON DUPLICATE KEY UPDATE فعلی ممکن است — MySQL مقدار
	 * affected_rows را ۱ برای درج تازه و ۲ برای بروزرسانی برمی‌گرداند.
	 * یعنی save_message() فقط باید همان عدد را برگرداند، نه true.
	 *
	 * @param array $counts processed, inserted, duplicates, errors
	 */
	public static function record_batch( $id, $counts = array() ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! $id ) {
			return false;
		}

		$processed  = max( 0, (int) ( $counts['processed'] ?? 0 ) );
		$inserted   = max( 0, (int) ( $counts['inserted'] ?? 0 ) );
		$duplicates = max( 0, (int) ( $counts['duplicates'] ?? 0 ) );
		$errors     = max( 0, (int) ( $counts['errors'] ?? 0 ) );

		$table = self::table();
		return false !== $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET
				processed_messages = processed_messages + %d,
				inserted_messages  = inserted_messages + %d,
				duplicate_messages = duplicate_messages + %d,
				error_messages     = error_messages + %d,
				updated_at         = %s
			 WHERE id = %d",
			$processed, $inserted, $duplicates, $errors, current_time( 'mysql' ), $id
		) );
	}

	/**
	 * آیا سقف Limited Scan پر شده؟ Scanner باید قبل از خواندن صفحه‌ی بعدی
	 * این را بپرسد — این تنها چیزی است که «۵۰۰ پیام» را واقعاً ۵۰۰ نگه می‌دارد.
	 */
	public static function limit_reached( $id ) {
		$run = is_array( $id ) ? $id : self::get( $id );
		if ( ! $run || self::MODE_LIMITED !== $run['scan_mode'] ) {
			return false;
		}
		$limit = (int) $run['limit_count'];
		return $limit > 0 && (int) $run['processed_messages'] >= $limit;
	}

	/** چند پیام دیگر تا رسیدن به سقف باقی مانده؟ برای اندازه‌ی صفحه‌ی بعدی. */
	public static function remaining( $id ) {
		$run = is_array( $id ) ? $id : self::get( $id );
		if ( ! $run || self::MODE_LIMITED !== $run['scan_mode'] ) {
			return PHP_INT_MAX;
		}
		$limit = (int) $run['limit_count'];
		if ( $limit < 1 ) {
			return PHP_INT_MAX;
		}
		return max( 0, $limit - (int) $run['processed_messages'] );
	}

	public static function set_status( $id, $status, $error = '' ) {
		global $wpdb;
		$valid = array(
			self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_PAUSED,
			self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED,
		);
		if ( ! in_array( $status, $valid, true ) ) {
			return new WP_Error( 'sti_gs_bad_status', 'وضعیت نامعتبر: ' . $status );
		}

		$row = array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) );
		if ( in_array( $status, array( self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ), true ) ) {
			$row['finished_at'] = current_time( 'mysql' );
		}
		if ( '' !== $error ) {
			$row['last_error'] = mb_substr( (string) $error, 0, 480 );
		}

		$result = $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );

		if ( class_exists( 'STI_GS_Event' ) ) {
			STI_GS_Event::log( 0, 'scan_run_status', '' !== $error ? 'error' : 'ok',
				'Scan Run #' . (int) $id . ' → ' . $status . ( '' !== $error ? ' — ' . $error : '' ),
				null, array( 'scan_run_id' => (int) $id, 'status' => $status )
			);
		}

		return $result;
	}

	public static function finish( $id, $status = self::STATUS_COMPLETED, $error = '' ) {
		return self::set_status( $id, $status, $error );
	}

	/**
	 * خلاصه‌ی قابل نمایش — دقیقاً همان چیزی که Spec §12 و §139 می‌خواهند.
	 */
	public static function summary( $id ) {
		$run = is_array( $id ) ? $id : self::get( $id );
		if ( ! $run ) {
			return null;
		}
		$requested = (int) $run['requested_messages'];
		$processed = (int) $run['processed_messages'];
		return array(
			'id'         => (int) $run['id'],
			'channel_id' => (int) $run['channel_id'],
			'mode'       => $run['scan_mode'],
			'status'     => $run['status'],
			'requested'  => $requested,
			'processed'  => $processed,
			'inserted'   => (int) $run['inserted_messages'],
			'duplicates' => (int) $run['duplicate_messages'],
			'errors'     => (int) $run['error_messages'],
			'progress'   => $requested > 0 ? min( 100, (int) round( $processed / $requested * 100 ) ) : null,
			'started_at' => $run['started_at'],
			'finished_at'=> $run['finished_at'],
			'last_error' => $run['last_error'],
		);
	}

	/**
	 * تکرار یک Run با همان پارامترها — قلب «Fixture تکرارپذیر».
	 *
	 * همان کانال، همان mode، همان limit و مهم‌تر از همه **همان لنگر**. چون
	 * ذخیره‌سازی Inventory با ON DUPLICATE KEY UPDATE کار می‌کند، اجرای
	 * دوباره دقیقاً همان مجموعه پیام را دوباره پردازش می‌کند و رکورد تکراری
	 * نمی‌سازد. یعنی می‌شود Correlation را تغییر داد و روی همان ۵۰۰ پیام
	 * دوباره سنجید.
	 *
	 * @return int|WP_Error شناسه‌ی Run تازه
	 */
	public static function repeat( $run_id ) {
		$source = self::get( $run_id );
		if ( ! $source ) {
			return new WP_Error( 'sti_gs_run_not_found', 'Run پیدا نشد.' );
		}
		return self::start( (int) $source['channel_id'], $source['scan_mode'], array(
			'limit_count'     => (int) $source['limit_count'],
			'range_from'      => $source['range_from'],
			'range_to'        => $source['range_to'],
			'from_message_id' => self::anchor( $run_id ),
		) );
	}
}
