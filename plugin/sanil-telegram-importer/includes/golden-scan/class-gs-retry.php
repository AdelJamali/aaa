<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** گلدن اسکن — منطق مشترک retry/backoff. فعلاً فقط تشخیص FLOOD_WAIT؛ فازهای بعد اینجا اضافه می‌شود. */
class STI_GS_Retry {

	/** اگر پیام خطا شامل FLOOD_WAIT_N بود، DATETIME آماده‌ی next_retry_at را برمی‌گرداند؛ وگرنه null. */
	public static function flood_wait_until( $err_msg ) {
		$msg = (string) $err_msg;

		/* ۱۰.۸.۳: الگوهای بیشتر — FLOOD_WAIT_120 / FLOOD_WAIT 120 / flood wait: 120 / flood_wait_120 */
		$sec = null;
		if ( preg_match( '/FLOOD_WAIT[_\s]*(\d+)/i', $msg, $m ) ) {
			$sec = (int) $m[1];
		} elseif ( preg_match( '/(?:flood[ _]?wait[:\s_]+)(\d+)/i', $msg, $m ) ) {
			$sec = (int) $m[1];
		}

		if ( null === $sec ) {
			return null;
		}
		$wait_sec = max( 5, $sec );
		return date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $wait_sec );
	}
}
