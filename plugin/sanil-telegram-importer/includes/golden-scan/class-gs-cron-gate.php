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

		$option    = 'sti_gs_gate_' . sanitize_key( $name );
		$now       = time();
		$threshold = $now - max( 1, (int) $interval_sec );

		/*
		 * compare-and-set اتمیک: فقط اگر مقدار ثبت‌شده **قدیمی‌تر از
		 * interval** باشد، به‌روزرسانی می‌شود. CAST برای مقادیر
		 * غیرعددیِ احتمالی (ردیف خالی/خراب) ایمن است — آنها به 0
		 * تبدیل شده و همیشه «کهنه» محسوب می‌شوند.
		 */
		$affected = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %d
			 WHERE option_name = %s
			 AND CAST(option_value AS UNSIGNED) < %d",
			$now,
			$option,
			$threshold
		) );
		if ( $affected > 0 ) {
			return true;
		}

		/*
		 * ردیف هنوز وجود ندارد (اولین اجرا): INSERT با key یکتای
		 * option_name — در رقابت، فقط یکی از درخواست‌ها در‌آوردن را
		 * می‌بیند و برنده می‌شود.
		 */
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
			$option
		) );
		if ( ! $exists ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, option_modified, option_autoload)
				 VALUES (%s, %d, %s, 'no')",
				$option,
				$now,
				current_time( 'mysql' )
			) );
			return true;
		}

		return false;
	}
}
