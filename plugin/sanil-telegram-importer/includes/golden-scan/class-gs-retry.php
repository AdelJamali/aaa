<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** گلدن اسکن — منطق مشترک retry/backoff. فعلاً فقط تشخیص FLOOD_WAIT؛ فازهای بعد اینجا اضافه می‌شود. */
class STI_GS_Retry {

	/** اگر پیام خطا شامل FLOOD_WAIT_N بود، DATETIME آماده‌ی next_retry_at را برمی‌گرداند؛ وگرنه null. */
	public static function flood_wait_until( $err_msg ) {
		if ( preg_match( '/FLOOD_WAIT[_\s]*(\d+)/i', (string) $err_msg, $m ) ) {
			$wait_sec = max( 5, (int) $m[1] );
			return date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $wait_sec );
		}
		return null;
	}
}
