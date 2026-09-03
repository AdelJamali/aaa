<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — سقف زمانی (Deadline) برای عملیات خارجی.
 *
 * اصل ۱۰.۸.۳: «هیچ عملیات Telegram/MTProto نباید بتواند Worker یا
 * Session Pipeline را برای مدت نامحدود متوقف کند.»
 *
 * دو حالت:
 *   ۱) pcntl_alarm (اگر هاست اجازه دهد): SIGALRM بعد از مهلت یک
 *      STI_GS_Deadline_Exception پرتاب می‌کند — finally های بالادست
 *      (release قفل و …) اجرا می‌شوند و خطا «کنترل‌شده» است.
 *   ۲) بدون pcntl (بیشتر هاست‌های اشتراکی): set_time_limit کران‌دار —
 *      اگر تماس واقعاً قفل شود، PHP بعد از مهلت درخواست را می‌کشد؛
 *      finally اجرا نمی‌شود ولی Lock دارای TTL است (locked_until) و
 *      pick() بعد از انقضا دوباره برمی‌دارد (Stale Lock Recovery).
 *
 * این کلاس «وانمود به timeout» نمی‌کند — در هر حالت رفتار واقعی مشخص
 * است (Audit §۹-P1 / §۱۰-۱).
 */
class STI_GS_Deadline_Exception extends \Exception {}

class STI_GS_Deadline {

	/** آیا timeout واقعی (سیگنال) ممکن است؟ */
	public static function real_timeout_available() {
		return function_exists( 'pcntl_alarm' )
			&& function_exists( 'pcntl_signal' )
			&& function_exists( 'pcntl_async_signals' );
	}

	/**
	 * تحویل ناهمگام سیگنال — بدون این، SIGALRM هرگز به کد PHP نمی‌رسد.
	 *
	 * از PHP 7.1 به بعد، سیگنال‌ها فقط وقتی تحویل داده می‌شوند که یا
	 * `pcntl_async_signals(true)` فعال باشد یا `declare(ticks=1)` وجود
	 * داشته باشد. هیچ‌کدام در این کلاس نبود.
	 *
	 * نتیجه: `pcntl_alarm()` تنظیم می‌شد، مهلت می‌گذشت، و handler **اجرا
	 * نمی‌شد** — دقیقاً وقتی PHP داخل یک تماس شبکه‌ی مسدودکننده بود، یعنی
	 * همان لحظه‌ای که به آن نیاز داشتیم.
	 *
	 * عملاً حالت pcntl بی‌صدا به مسیر set_time_limit سقوط می‌کرد: درخواست
	 * کشته می‌شد ولی `finally` اجرا نمی‌شد، پس قفل تا انقضای TTL می‌ماند —
	 * دقیقاً برخلاف ادعای «خطای کنترل‌شده با آزادسازی قفل».
	 *
	 * تنها جایی که فعال می‌شد `STI_MTProto` بود، آن هم با گارد
	 * `static $done` و فقط اگر آن مسیر پیش‌تر اجرا شده بود.
	 */
	protected static function enable_async_signals() {
		static $enabled = null;
		if ( null !== $enabled ) {
			return $enabled;
		}
		$enabled = false;
		if ( function_exists( 'pcntl_async_signals' ) ) {
			try {
				pcntl_async_signals( true );
				$enabled = true;
			} catch ( \Throwable $e ) {
				$enabled = false;
			}
		}
		return $enabled;
	}

	/**
	 * اجرای $fn با مهلت $timeout_sec ثانیه.
	 *
	 * @param callable $fn
	 * @param int      $timeout_sec
	 * @param string   $label       برچسب (پیام خطا / breadcrumb)
	 * @return mixed
	 * @throws STI_GS_Deadline_Exception  فقط در حالت pcntl
	 */
	public static function guard( $fn, $timeout_sec, $label = 'operation' ) {
		$timeout_sec = max( 5, (int) $timeout_sec );

		if ( self::real_timeout_available() ) {
			return self::guard_signal( $fn, $timeout_sec, $label );
		}

		return self::guard_time_limit( $fn, $timeout_sec, $label );
	}

	/**
	 * حالت ۱: سیگنال — خطای کنترل‌شده با اجرای finally ها.
	 *
	 * یک لایه‌ی پشتیبان هم دارد: اگر استثنای SIGALRM به‌دلیل ساختار
	 * event loop (Revolt/MadelineProto) بلعیده شود، set_time_limit کران‌دار
	 * درخواست را می‌کشد و Stale-Lock Recovery (TTL) قفل را آزاد می‌کند.
	 */
	protected static function guard_signal( $fn, $timeout_sec, $label ) {
		$prev_handler = null;
		if ( function_exists( 'pcntl_signal_get_handler' ) ) {
			$prev_handler = pcntl_signal_get_handler( SIGALRM );
		}

		// بدون تحویل ناهمگام، alarm بی‌اثر است — پس به مسیر امن سقوط می‌کنیم.
		if ( ! self::enable_async_signals() ) {
			return self::guard_time_limit( $fn, $timeout_sec, $label );
		}

		$prev_limit = ini_get( 'max_execution_time' );
		@set_time_limit( $timeout_sec + 30 );

		pcntl_signal( SIGALRM, function () use ( $label ) {
			throw new STI_GS_Deadline_Exception(
				$label . ': مهلت اجرا تمام شد (' . $label . ' > deadline).'
			);
		} );
		pcntl_alarm( $timeout_sec );

		try {
			return call_user_func( $fn );
		} finally {
			pcntl_alarm( 0 );
			if ( null !== $prev_handler ) {
				@pcntl_signal( SIGALRM, $prev_handler );
			}
			if ( false !== $prev_limit ) {
				@set_time_limit( (int) $prev_limit );
			}
		}
	}

	/**
	 * حالت ۲: set_time_limit کران‌دار — مرگ کران‌دار درخواست + Stale-Lock
	 * Recovery (TTL قفل). اگر $fn برگردد، محدودیت قبلی بازگردانده می‌شود.
	 */
	protected static function guard_time_limit( $fn, $timeout_sec, $label ) {
		$prev = ini_get( 'max_execution_time' );
		@set_time_limit( $timeout_sec + 10 );

		try {
			return call_user_func( $fn );
		} finally {
			if ( false !== $prev ) {
				@set_time_limit( (int) $prev );
			}
		}
	}

	/**
	 * بودجه‌ی باقی‌مانده از یک مهلت (برای Worker Tick Budget).
	 *
	 * @param int $started_ts  time() شروع
	 * @param int $budget_sec
	 * @return int  ثانیه‌ی باقی‌مانده (حداقل ۰)
	 */
	public static function remaining( $started_ts, $budget_sec ) {
		return max( 0, (int) $budget_sec - ( time() - (int) $started_ts ) );
	}
}
