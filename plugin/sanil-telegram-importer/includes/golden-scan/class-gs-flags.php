<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — کلیدهای قابلیت.
 *
 * هر قابلیت تازه پشت یک کلید مستقل است تا اگر مشکلی پیش آمد، لازم نباشد
 * کل نسخه برگردانده شود؛ فقط همان یک قابلیت خاموش می‌شود.
 *
 * پیش‌فرض‌ها عمداً محافظه‌کارانه‌اند: چیزی که رفتار موجود را عوض می‌کند
 * خاموش شروع می‌شود، و چیزی که فقط اطلاعات اضافه می‌کند روشن.
 */
class STI_GS_Flags {

	/**
	 * ثبت بازه‌های کران گلدن اسکن.
	 *
	 * یک‌جا ثبت می‌شوند تا هر ماژول بازه‌ی خودش را نسازد و همه از یک منبع
	 * بخوانند.
	 */
	public static function register_intervals() {
		add_filter( 'cron_schedules', function ( $schedules ) {
			$schedules['sti_gs_5min']  = array( 'interval' => 5 * MINUTE_IN_SECONDS,  'display' => 'هر ۵ دقیقه (گلدن اسکن)' );
			$schedules['sti_gs_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'هر ۱۵ دقیقه (گلدن اسکن)' );
			$schedules['sti_gs_30min'] = array( 'interval' => 30 * MINUTE_IN_SECONDS, 'display' => 'هر ۳۰ دقیقه (گلدن اسکن)' );
			return $schedules;
		} );
	}

	const OPTION = 'sti_gs_flags';

	/**
	 * @return array<string, array{label:string, default:int, note:string}>
	 */
	public static function definitions() {
		return array(
			'error_classification' => array(
				'label'   => 'دسته‌بندی خطا (موقت / قابل بازیابی / دائمی)',
				'default' => 1,
				'note'    => 'بدون آن، خطای «فایل حذف شده» و «تلگرام قطع بود» یکسان تلاش می‌شوند.',
			),
			'pending_states' => array(
				'label'   => 'حالت‌های PENDING برای بازیابی دقیق‌تر',
				'default' => 1,
				'note'    => 'تفکیک «نیمه‌کاره ماند» از «تازه رسید». فقط برای دانلود و مدیا.',
			),
			'watchdog' => array(
				'label'   => 'Watchdog بازیابی (کران مستقل)',
				'default' => 1,
				'note'    => 'Sessionهای یتیم را حتی وقتی Worker خاموش است پیدا و ترمیم می‌کند.',
			),
			'dead_letter' => array(
				'label'   => 'صف مرده برای خطاهای دائمی',
				'default' => 1,
				'note'    => 'جلوی تلاش بی‌پایان روی موردی که هرگز درست نمی‌شود را می‌گیرد.',
			),
			'chain_watchdog' => array(
				'label'   => 'Watchdog برای گام‌های زنجیره',
				'default' => 1,
				'note'    => 'گام‌هایی که در حالت running مانده‌اند و قفلشان منقضی شده را آزاد می‌کند.',
			),
			'scan_limit' => array(
				'label'   => 'سقف روزانه‌ی اسکن',
				'default' => 1,
				'note'    => 'بدون آن، Channel Watcher می‌تواند در یک روز هزاران Session بسازد.',
			),
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$out = array();
		foreach ( self::definitions() as $key => $def ) {
			$out[ $key ] = array_key_exists( $key, $saved )
				? (bool) $saved[ $key ]
				: (bool) $def['default'];
		}
		return $out;
	}

	public static function on( $key ) {
		$all = self::all();
		return ! empty( $all[ $key ] );
	}

	public static function set( $key, $value ) {
		if ( ! isset( self::definitions()[ $key ] ) ) {
			return false;
		}
		$saved         = get_option( self::OPTION, array() );
		$saved         = is_array( $saved ) ? $saved : array();
		$saved[ $key ] = $value ? 1 : 0;
		update_option( self::OPTION, $saved, true );

		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::info( 'گلدن اسکن: قابلیت «' . $key . '» ' . ( $value ? 'روشن' : 'خاموش' ) . ' شد.' );
		}
		return true;
	}
}
