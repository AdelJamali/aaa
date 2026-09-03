<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — ۱۰.۱۰ — تنظیمات اتوماسیون (بدون ویرایش فایل).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * همه‌ی سقف‌ها و بودجه‌های خط تولید در یک option ذخیره می‌شوند.
 * پیش‌فرض‌ها طبق دستور کار: پایداری مهم‌تر از سرعت — هاست اشتراکی.
 *
 *   Max Active Sessions = 1
 *   Max MTProto Client  = 1   (singleton — در STI_MTProto ذاتی است)
 *   Max Download/Tick   = 1
 *   Max Product/Tick    = 1
 *   Sessions Per Tick   = 1
 *
 * مصرف‌کننده‌ها (Auto Worker, MTProto, Governor) از get() می‌خوانند؛
 * اگر option نباشد، پیش‌فرض همان عدد بالا برمی‌گردد — یعنی حتی بدون
 * اینکه کاربر دست بزند، رفتار خط تولید امن است.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Automation {

	const OPTION = 'sti_gs_automation';

	/** پیش‌فرض‌ها + شرح (برای فرم). */
	public static function defaults() {
		return array(
			/* سقف‌های retry — ۱۰.۱۰ */
			'session_retry_limit' => array( 5,   'سقف تلاش هر Session (بعدش REVIEW اگر eligible، وگرنه backoff بلند)', 'int', 1, 50 ),
			'ipc_recovery_limit'  => array( 2,   'بازیابی IPC/Client در هر درخواست (فیوز)', 'int', 1, 10 ),
			'download_retry_limit' => array( 3,  'تلاش‌های دانلود در هر دور', 'int', 1, 10 ),
			'publish_retry_limit'  => array( 3,  'تلاش‌های انتشار در صف', 'int', 1, 10 ),
			'ai_retry_limit'       => array( 2,  'تلاش‌های تولید عنوان/توضیح AI', 'int', 1, 10 ),

			/* بودجه‌های منابع — هاست اشتراکی */
			'max_active_sessions' => array( 1,  'حداکثر Session فعال هم‌زمان (قفل زنده)', 'int', 1, 10 ),
			'sessions_per_tick'   => array( 1,  'Session پردازش‌شده در هر تیک', 'int', 1, 10 ),
			'max_downloads_per_tick' => array( 1, 'حداکثر دانلود در هر تیک', 'int', 1, 5 ),
			'max_products_per_tick'  => array( 1, 'حداکثر ساخت محصول در هر تیک', 'int', 1, 5 ),

			/* آستانه‌های Governor */
			'gov_ram_pct'        => array( 80,  'Governor: آستانه‌ی RAM هاست (٪)', 'int', 10, 100 ),
			'gov_load_per_core'  => array( 2.0, 'Governor: آستانه‌ی Load بر هر Core', 'float', 0.5, 10 ),
			'gov_backlog'        => array( 100, 'Governor: آستانه‌ی انباشت صف', 'int', 0, 100000 ),
			'gov_ipc_faults'     => array( 3,   'Governor: خرابی IPC در ۳۰ دقیقه', 'int', 0, 100 ),

			/* ۱۰.۱۱ — Worker و مانیتور */
			'worker_interval'      => array( 300, 'فاصله‌ی تیک Worker (ثانیه — ۱ تا ۶۰ دقیقه)', 'int', 60, 3600 ),
			'backoff_base_minutes' => array( 5,   'Backoff پایه‌ی شکست (دقیقه — با هر شکست دو برابر می‌شود)', 'int', 1, 60 ),
			'poll_interval'        => array( 4,   'به‌روزرسانی مانیتور زنده (ثانیه — ۲ تا ۳۰)', 'int', 2, 30 ),
		);
	}

	/**
	 * خواندن یک تنظیم.
	 *
	 * @param string $key
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return self::defaults()[ $key ][0];
	}

	/** همه‌ی تنظیم‌ها (مقدار نهایی، بعد از merge با پیش‌فرض). */
	public static function all() {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$out = array();
		foreach ( self::defaults() as $key => $spec ) {
			$def   = $spec[0];
			$kind  = $spec[2];
			$min   = $spec[3];
			$max   = $spec[4];

			if ( array_key_exists( $key, $raw ) ) {
				$v = $raw[ $key ];
				$v = ( 'float' === $kind ) ? (float) $v : (int) $v;
				$v = max( $min, min( $max, $v ) );
				$out[ $key ] = $v;
			} else {
				$out[ $key ] = $def;
			}
		}
		return $out;
	}

	/**
	 * ذخیره‌ی تنظیمات (فقط کلیدهای شناخته‌شده، با کلمپ).
	 *
	 * @param array $values
	 * @return array مقدارهای ذخیره‌شده
	 */
	public static function save( $values ) {
		$cur  = get_option( self::OPTION, array() );
		$cur  = is_array( $cur ) ? $cur : array();
		$spec = self::defaults();

		foreach ( (array) $values as $key => $v ) {
			if ( ! array_key_exists( $key, $spec ) ) {
				continue; // کلید ناشناخته — نادیده
			}
			$kind = $spec[ $key ][2];
			$min  = $spec[ $key ][3];
			$max  = $spec[ $key ][4];
			$v    = ( 'float' === $kind ) ? (float) $v : (int) $v;
			$cur[ $key ] = max( $min, min( $max, $v ) );
		}

		update_option( self::OPTION, $cur, false );
		return self::all();
	}

	/**
	 * خط تولید روشن است؟ (worker enabled + غیر safe-mode).
	 */
	public static function pipeline_on() {
		if ( function_exists( 'sti_v7_safe_mode' ) && sti_v7_safe_mode() ) {
			return false;
		}
		if ( class_exists( 'STI_GS_DB' ) && STI_GS_DB::is_halted() ) {
			return false;
		}
		return class_exists( 'STI_GS_Auto_Worker' ) && STI_GS_Auto_Worker::is_enabled();
	}
}
