<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — دروازه‌ی اتمیکِ تیک‌های کران.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ۱۰.۹.۳ — چرا این کلاس لازم شد
 *
 *   نگهبان فاصله‌ی قدیمی این‌طور بود:
 *
 *       $last = get_option( ..._last );          // ۱) خواندن
 *       if ( time() - $last < $interval ) return;
 *       update_option( ..._last, time() );      // ۲) نوشتن
 *
 *   این یک race از نوع TOCTOU است: در کرانِ درون‌خطی وردپرس (fake cron)،
 *   دو بارگذاری صفحه‌ی هم‌زمان هر دو مرحله‌ی «خواندن» را پیش از نوشتنِ
 *   طرف مقابل انجام می‌دهند، هر دو می‌گذرند و تیک **دو بار** اجرا می‌شود —
 *   دوباره‌ی global_poll، دوباره‌ی ساخت client IPC، دوباره‌ی فشار MySQL.
 *
 *   نگهبان جدید یک UPDATE اتمیک است: تنها درخواستی که **ردیف گزینه را
 *   واقعاً به‌روزرسانی کند** برنده‌ی نوبت است. خواندن و نوشتن جدا نیستند؛
 *   مقایسه داخل همان جمله‌ی UPDATE در دیتابیس انجام می‌شود.
 *
 *   ردیف گزینه با `option_autoload='no'` ساخته می‌شود تا هرگز در هر
 *   بارگذاری وردپرس لود نشود.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Cron_Gate {

	/**
	 * آیا اجرای این تیک به من می‌رسد؟
	 *
	 * @param string $name         نام یکتای دروازه (مثلاً 'auto_worker').
	 * @param int    $interval_sec حداقل فاصله‌ی ثانیه‌ای بین اجراها.
	 * @return bool true = نوبت تو است، اجرا کن.
	 */
	public static function pass( $name, $interval_sec ) {
		global $wpdb;

		/*
		 * Fail policy (10.12.10 — آگاهانه و مستند):
		 *   • اولین اجرای قانونی: seed idempotent با add_option() (مقدار 0)
		 *     و بعد CAS — تیک اول همیشه رد می‌شود (by design، TEST J).
		 *     ردیف‌ها هرگز از قبل seed نمی‌شوند تا TEST J واقعی بماند؛
		 *     سازندهٔ طبیعی هر gate خودِ اولین pass() اوست.
		 *   • شکست واقعی (ردیف حتی بعد از add_option ساخته نشد):
		 *     fail-CLOSED (return false) + لاگ CRON_GATE_FAILED با
		 *     last_error — هرگز success جعلی، هرگز ignore بی‌صدا.
		 *   • concurrency: CAS UPDATE — فقط یکی از N درخواست موازی برنده.
		 *
		 * (INSERT قبلی ستون option_modified داشت — ستونی که در schema
		 * استاندارد wp_options وجود ندارد؛ هر INSERT با SQL error شکست
		 * می‌خورد و ردیف هرگز ساخته نمی‌شد ⇒ interval عملاً بی‌اثر می‌شد.)
		 */
		$option    = 'sti_gs_gate_' . sanitize_key( $name );
		$now       = time();
		$threshold = $now - max( 1, (int) $interval_sec );

		$prev = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			$option
		) );
		if ( null === $prev ) {
			/* اولین اجرا: seed = 0 → همیشه «کهنه» ⇒ اولین CAS حتماً رد می‌شود.
			 * add_option() race-aware است: در رقابت، فقط یک درخواست
			 * در‌آوردن را می‌بیند؛ بقیه در بازرسی دوم ردیف موجود را می‌بینند. */
			add_option( $option, 0, '', 'no' );
			$prev = $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option
			) );
			if ( null === $prev ) {
				/* شکست واقعی — fail-closed و با صدا. */
				if ( class_exists( 'STI_Logger' ) ) {
					STI_Logger::error( 'CRON_GATE_FAILED name=' . $name . ' reason=option_row_missing last_error=' . $wpdb->last_error );
				}
				return false;
			}
		}

		/*
		 * compare-and-set اتمیک (بدون تغییر): فقط اگر مقدار ثبت‌شده
		 * **قدیمی‌تر از interval** باشد، به‌روزرسانی می‌شود. تنها
		 * درخواستی که واقعاً ردیف را آپدیت کند برنده‌ی نوبت است.
		 * CAST برای مقادیر غیرعددی ایمن است — آنها به 0 تبدیل و
		 * «کهنه» محسوب می‌شوند.
		 */
		$affected = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %d
			 WHERE option_name = %s
			 AND CAST(option_value AS UNSIGNED) < %d",
			$now,
			$option,
			$threshold
		) );
		$pass = $affected > 0;

		/* 10.12.10 — P4: لاگ هر pass (حداکثر یک تیک در هر hook — بدون spam). */
		if ( class_exists( 'STI_Logger' ) ) {
			$read_back = $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option
			) );
			STI_Logger::info( sprintf(
				'CRON_GATE name=%s prev=%s write_affected=%d read_back=%s result=%s',
				$name,
				var_export( null === $prev ? null : (int) $prev, true ),
				$affected,
				var_export( null === $read_back ? null : (int) $read_back, true ),
				$pass ? 'PASS' : 'BLOCKED'
			) );
		}
		return $pass;
	}
}
