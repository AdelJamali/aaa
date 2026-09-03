<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — ۱۰.۱۰ — دروازه‌ی REVIEW + Fix پیشنهادی.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * SESSION SURVIVAL RULE:
 * تا زمانی که راهکار Recovery وجود دارد، Session نباید وارد REVIEW شود.
 *
 * REVIEW فقط برای ۴ دلیل مجاز است:
 *   UNKNOWN_BOT_FLOW     — ساختار دکمه/جریان ربات عوض شده، evidence نیست
 *   HUMAN_VERIFICATION   — کپچا / تأیید شماره / دخالت انسانی لازم
 *   UNRESOLVED_DUPLICATE — هم‌پوشانی/دوقلویی که سیستم نمی‌تواند حل کند
 *   CORRUPTED_DATA       — داده خراب (فایل ناقص، JSON خراب، مدیا بی‌اعتبار)
 *
 * هر خطای **دیگر** باید ادامه پیدا کند (Retry با backoff) — REVIEW شدن
 * بی‌دلیل، شکست خودترمیمی است.
 *
 * هر آیتم REVIEW یک **Recommended Fix** قطعی دارد که با یک کلیک اجرا می‌شود:
 * بازتعیین State + صفر کردن شمارنده‌ها + لاگ. هیچ داده‌ای حذف نمی‌شود.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Review {

	const UNKNOWN_BOT_FLOW     = 'UNKNOWN_BOT_FLOW';
	const HUMAN_VERIFICATION   = 'HUMAN_VERIFICATION';
	const UNRESOLVED_DUPLICATE = 'UNRESOLVED_DUPLICATE';
	const CORRUPTED_DATA       = 'CORRUPTED_DATA';

	const REASON_LABELS = array(
		self::UNKNOWN_BOT_FLOW     => 'جریان ناشناخته‌ی ربات (ساختار دکمه/رفتار عوض شده)',
		self::HUMAN_VERIFICATION   => 'نیاز به تأیید انسانی (کپچا / شماره / ورود)',
		self::UNRESOLVED_DUPLICATE => 'دوقلویی/هم‌پوشانی حل‌نشده',
		self::CORRUPTED_DATA       => 'داده خراب',
	);

	/**
	 * آیا این خطا/eligible برای REVIEW است؟
	 *
	 * @param string $state   state فعلی
	 * @param string $error   پیام خطا (error_reason)
	 * @return string|null  دلیل REVIEW یا null (باید ادامه بدهد)
	 */
	public static function eligible( $state, $error ) {
		$state = (string) $state;
		$err   = mb_strtolower( (string) $error );

		/* stateهایی که خودشان معنای REVIEW دارند */
		if ( in_array( $state, array( 'ERROR_FILE_NOT_FOUND' ), true ) ) {
			return self::UNKNOWN_BOT_FLOW; // ربات فایل را صریح نیاورد — جریانش عوض/منقصری
		}
		if ( in_array( $state, array( 'DEAD_LETTER' ), true ) ) {
			return self::classify_error( $error );
		}

		/* متن خطا — ترتیب مهم است (اول اختصاصی‌ترها) */
		if ( false !== strpos( $err, 'captcha' )
			|| false !== strpos( $err, 'phone verification' )
			|| false !== strpos( $err, '2fa required' )
			|| false !== strpos( $err, 'AUTH_KEY_UNREGISTERED' )
			|| false !== strpos( $err, 'فایل درخواستی یافت نشد' )
			|| false !== strpos( $err, 'file not found' ) ) {
			// «فایل یافت نشد» پاسخ صریح ربات است: جریان ربات فایل را ندارد → UNKNOWN_BOT_FLOW
			if ( false !== strpos( $err, 'captcha' ) || false !== strpos( $err, 'phone verification' )
				|| false !== strpos( $err, '2fa required' ) ) {
				return self::HUMAN_VERIFICATION;
			}
			return self::UNKNOWN_BOT_FLOW;
		}

		if ( false !== strpos( $err, 'chain_match_ambiguous' )
			|| false !== strpos( $err, 'chain_match_no_identity' )
			|| false !== strpos( $err, 'duplicate' )
			|| false !== strpos( $err, 'ambiguous' ) ) {
			return self::UNRESOLVED_DUPLICATE;
		}

		if ( false !== strpos( $err, 'corrupt' )
			|| false !== strpos( $err, 'invalid json' )
			|| false !== strpos( $err, 'file is empty' )
			|| false !== strpos( $err, 'فایل خالی' )
			|| false !== strpos( $err, 'invalid media' )
			|| false !== strpos( $err, 'unreadable' )
			|| false !== strpos( $err, 'invalid data' ) ) {
			return self::CORRUPTED_DATA;
		}

		if ( false !== strpos( $err, 'chain_recover_no_evidence' ) ) {
			return self::UNKNOWN_BOT_FLOW;
		}

		return null; // eligible نیست — باید ادامه بدهد
	}

	/**
	 * برای DEAD_LETTERها: از متن خطا، دلیل REVIEW را استخراج می‌کند.
	 * اگر هیچ‌کدام نشست، UNKNOWN_BOT_FLOW (حداقل امن).
	 */
	protected static function classify_error( $error ) {
		$err = mb_strtolower( (string) $error );
		if ( false !== strpos( $err, 'captcha' ) || false !== strpos( $err, '2fa' )
			|| false !== strpos( $err, 'phone verification' ) ) {
			return self::HUMAN_VERIFICATION;
		}
		if ( false !== strpos( $err, 'duplicate' ) || false !== strpos( $err, 'ambiguous' ) ) {
			return self::UNRESOLVED_DUPLICATE;
		}
		if ( false !== strpos( $err, 'corrupt' ) || false !== strpos( $err, 'invalid') ) {
			return self::CORRUPTED_DATA;
		}
		return self::UNKNOWN_BOT_FLOW;
	}

	/**
	 * Fix پیشنهادی برای یک Session در REVIEW.
	 *
	 * @param array $session  ردیف کامل pipeline item
	 * @return array{reason:string, label:string, action:string|null, description:string}
	 *         action=null یعنی هیچ اقدام اتوماتیک امنی نیست (دستورالعمل دستی).
	 */
	public static function suggested_fix( $session ) {
		$reason = self::reason_of( $session );
		$state  = (string) ( $session['state'] ?? '' );

		switch ( $reason ) {
			case self::HUMAN_VERIFICATION:
				return array(
					'reason'      => $reason,
					'label'       => 'ورود دستی اکانت',
					'action'      => null,
					'description' => 'از «تنظیمات تلگرام» ورود را کامل کنید (کد/رمز دومرحله‌ای)، سپس Session را دوباره اجرا کنید. سیستم نمی‌تواند به‌جای شما کد را وارد کند.',
				);

			case self::CORRUPTED_DATA:
				return array(
					'reason'      => $reason,
					'label'       => 'دانلود مجدد فایل',
					'action'      => 'redownload',
					'description' => 'Session به مرحله‌ی دانلود بازمی‌گردد؛ فایل خراب با دانلود تازه جایگزین می‌شود.',
				);

			case self::UNRESOLVED_DUPLICATE:
				return array(
					'reason'      => $reason,
					'label'       => 'تطبیق دوباره',
					'action'      => 'rematch',
					'description' => 'Session به مرحله‌ی تطبیق برمی‌گردد و کاندیدها دوباره امتحان می‌شوند (با file_reference تازه).',
				);

			case self::UNKNOWN_BOT_FLOW:
			default:
				return array(
					'reason'      => $reason,
					'label'       => 'شروع دوباره‌ی گفت‌وگو با ربات',
					'action'      => 'rebot',
					'description' => 'Session به BUTTON_FOUND بازمی‌گردد و کلیک/گفت‌وگو با ربات از اول انجام می‌شود.',
				);
		}
	}

	/** دلیل REVIEWِ ثبت‌شده (از error_reason) یا برآورد. */
	public static function reason_of( $session ) {
		$err = (string) ( $session['error_reason'] ?? '' );

		// ثبت صریحِ دلیل (فرمت [REVIEW:REASON] ...)
		if ( preg_match( '/REVIEW:([A-Z_]+)/', $err, $m ) ) {
			$stored = $m[1];
			if ( isset( self::REASON_LABELS[ $stored ] ) ) {
				return $stored;
			}
		}

		$reason = self::eligible( (string) ( $session['state'] ?? '' ), $err );
		return $reason ? $reason : self::UNKNOWN_BOT_FLOW;
	}

	/**
	 * اجرای Fix پیشنهادی — بازتعیین قطعی State. بدون حذف داده.
	 *
	 * @param int    $session_id
	 * @param string $action  rebot | rematch | redownload
	 * @return bool
	 */
	public static function run_fix( $session_id, $action ) {
		$session_id = (int) $session_id;
		$session    = STI_GS_Session::get( $session_id );
		if ( ! $session ) {
			return false;
		}
		if ( ! STI_GS_Stage::is_final( (string) $session['state'] ) ) {
			return false; // فقط برای Sessionهای REVIEW (نهایی)
		}
		if ( 'PUBLISHED' === STI_GS_Stage::final_of( (string) $session['state'] ) ) {
			return false; // PUBLISHED با Fix عوض نمی‌شود
		}

		$map = array(
			'rebot'      => 'BUTTON_FOUND',
			'rematch'    => 'BOT_RESPONSE',
			'redownload' => 'FILE_MATCHED',
		);
		if ( ! isset( $map[ $action ] ) ) {
			return false;
		}

		STI_GS_Session::update( $session_id, array(
			'state'         => $map[ $action ],
			'stage'         => 'review_fix',
			'attempts'      => 0,
			'next_retry_at' => null,
			'error_reason'  => null,
		) );
		STI_GS_Event::log( $session_id, 'review_fix', 'ok', sprintf(
			'Run Suggested Fix: «%s» — State به %s بازگشت (شمارنده‌ها صفر).'
		) );
		return true;
	}

	/**
	 * برچسب فارسیِ دلیل.
	 */
	public static function label( $reason ) {
		return isset( self::REASON_LABELS[ $reason ] ) ? self::REASON_LABELS[ $reason ] : $reason;
	}
}
