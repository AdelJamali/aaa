<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — ۱۰.۱۰ — لاگ هر Session (Session Runs).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * برای هر Session ثبت می‌شود (دستور کار ۱۰.۱۰):
 *   Start Time · End Time · Duration
 *   Stage History (ترتیب Stage/Status با زمان — سقف ۵۰ رکورد)
 *   Retry Count · Recovery Count · IPC Heal Count
 *   Download Retry Count · Publish Retry Count
 *   Final Result
 *
 * هر Session دقیقاً یک ردیف (UNIQUE session_id). اولین تیک ردیف می‌سازد؛
 * بقیه‌ی تیک‌ها فقط به‌روز می‌کنند. وقتی State نهایی شد، ended_at و
 * final_result قفل می‌شوند (اگر بعداً از REVIEW با Fix بازگشت، run جدید
 * با started_at تازه شروع می‌شود — ردیف بازنویسی می‌شود، نه پاک).
 *
 * فقط این جدول را لمس می‌کند؛ به pipeline_items دست نمی‌زند.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Run_Log {

	const HISTORY_MAX = 50;

	/**
	 * به‌روزرسانی لاگ بعد از هر تیکِ یک Session.
	 *
	 * @param int    $session_id
	 * @param string $state      state فعلی (بعد از عملیات)
	 * @param string $ran_by     auto | manual
	 * @param array  $bump       شمارنده‌هایی که این تیک اضافه شدند:
	 *                           retry, recovery, ipc_heal, download_retry, publish_retry
	 */
	public static function touch( $session_id, $state, $ran_by = 'auto', $bump = array() ) {
		global $wpdb;

		if ( ! class_exists( 'STI_GS_DB' ) ) {
			return;
		}
		$table = STI_GS_DB::session_runs_table();
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return; // مهاجرت هنوز اجرا نشده — بی‌سروصدا رد شو
		}

		$session_id = (int) $session_id;
		$state      = (string) $state;
		$now        = current_time( 'mysql' );
		$final      = STI_GS_Stage::final_of( $state );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %d", $session_id ), ARRAY_A );

		/* بازگشت از REVIEW (Run Suggested Fix) → run تازه */
		if ( $row && $row['ended_at'] && ! $final ) {
			$wpdb->update( $table, array(
				'ran_by'         => $ran_by,
				'started_at'     => $now,
				'ended_at'       => null,
				'final_result'   => null,
				'stage_history'  => null,
				'retry_count'    => 0,
				'recovery_count' => 0,
				'ipc_heal_count' => 0,
				'download_retry_count' => 0,
				'publish_retry_count'  => 0,
				'updated_at'     => $now,
			), array( 'session_id' => $session_id ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' ) );
			$row = null;
		}

		if ( ! $row ) {
			$wpdb->insert( $table, array(
				'session_id' => $session_id,
				'ran_by'     => $ran_by,
				'started_at' => $now,
				'updated_at' => $now,
			), array( '%d', '%s', '%s', '%s' ) );
			$row = array( 'retry_count' => 0, 'recovery_count' => 0, 'ipc_heal_count' => 0, 'download_retry_count' => 0, 'publish_retry_count' => 0 );
		}

		/*
		 * افزایش اتمیک شمارنده‌ها با SQL (نه خواندن/نوشتن PHP) — چون ممکن
		 * است دو مسیر (worker + engine) در یک درخواست bump بفرستند.
		 */
		$set_extra = array();
		foreach ( array( 'retry', 'recovery', 'ipc_heal', 'download_retry', 'publish_retry' ) as $k ) {
			$n = (int) ( $bump[ $k ] ?? 0 );
			if ( $n > 0 ) {
				$col = $k . '_count';
				$set_extra[] = "{$col} = {$col} + {$n}";
			}
		}
		if ( $set_extra ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET " . implode( ', ', $set_extra ) . " WHERE session_id = %d",
				$session_id
			) );
		}

		/* Stage History — فقط وقتی Stage/Status عوض شد */
		$stage  = STI_GS_Stage::stage_of( $state );
		$status = STI_GS_Stage::status_of( $state );
		$label  = STI_GS_Stage::label( $state );

		$history = array();
		if ( ! empty( $row['stage_history'] ) ) {
			$decoded = json_decode( (string) $row['stage_history'], true );
			$history = is_array( $decoded ) ? $decoded : array();
		}
		$last_entry = end( $history );
		if ( ! $last_entry || ( is_array( $last_entry ) && ( $last_entry['label'] ?? '' ) !== $label ) ) {
			$history[] = array(
				'label' => $label,
				'at'    => $now,
			);
			if ( count( $history ) > self::HISTORY_MAX ) {
				$history = array_slice( $history, -self::HISTORY_MAX );
			}
			$wpdb->update( $table, array( 'stage_history' => wp_json_encode( $history, JSON_UNESCAPED_UNICODE ) ),
				array( 'session_id' => $session_id ), array( '%s' ), array( '%d' ) );
		}

		/* قفل نتیجه‌ی نهایی */
		if ( $final ) {
			$wpdb->update( $table, array(
				'ended_at'     => $now,
				'final_result' => $final,
			), array( 'session_id' => $session_id ), array( '%s', '%s' ), array( '%d' ) );
		}
	}

	/**
	 * لاگ یک Session.
	 *
	 * @return array|null
	 */
	public static function for_session( $session_id ) {
		global $wpdb;
		if ( ! class_exists( 'STI_GS_DB' ) ) {
			return null;
		}
		$table = STI_GS_DB::session_runs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %d", (int) $session_id ), ARRAY_A );
		if ( $row ) {
			$row['stage_history'] = json_decode( (string) ( $row['stage_history'] ?? '' ), true );
			if ( is_array( $row['stage_history'] ) ) {
				$row['duration_sec'] = $row['ended_at']
					? max( 0, strtotime( $row['ended_at'] ) - strtotime( $row['started_at'] ) )
					: ( time() - strtotime( $row['started_at'] ) );
			}
		}
		return $row;
	}

	/**
	 * آمار تجمعی برای داشبورد.
	 */
	public static function summary() {
		global $wpdb;
		if ( ! class_exists( 'STI_GS_DB' ) ) {
			return array();
		}
		$table = STI_GS_DB::session_runs_table();
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}
		$row = $wpdb->get_row( "SELECT
				COUNT(*) AS total_runs,
				SUM(retry_count) AS retries,
				SUM(recovery_count) AS recoveries,
				SUM(ipc_heal_count) AS ipc_heals,
				SUM(download_retry_count) AS download_retries,
				SUM(publish_retry_count) AS publish_retries,
				SUM(ended_at IS NOT NULL) AS finished
			FROM {$table}", ARRAY_A );
		return $row ? array_map( 'intval', $row ) : array();
	}
}
