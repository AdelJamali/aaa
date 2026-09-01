<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Unified Confidence (P3.4).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * این کلاس هیچ سیستم امتیازدهی تازه‌ای نمی‌سازد.
 *
 * چهار منبع اطمینان از قبل وجود دارند و همگی **دست‌نخورده** می‌مانند:
 *
 *   button_confidence            (STI_GS_Button_Resolver)   ۰..۱۰۰
 *   total_score                  (sti_gs_bot_candidates)    نامحدود
 *   Correlation confidence       (STI_GS_Correlation)       ۰..۱۰۰
 *   profile score                (sti_gs_profile_items)     فعلاً نوشته نمی‌شود
 *
 * کار این کلاس فقط Normalize و ترکیب است: خروجی همیشه یک عدد صحیح در
 * بازه‌ی ۰ تا ۱۰۰ به‌همراه گزارش شفاف اینکه از کجا آمده.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * چرا میانگین ساده نیست
 *
 * شواهد هم‌ارزش نیستند. یک تطبیق قطعی Correlation از ده امتیاز شباهت اسم
 * معتبرتر است، و میانگین‌گیری دقیقاً همان قطعیت را رقیق می‌کند. پس مدل
 * «سطحی» است نه «میانگینی»:
 *
 *   ۱. Correlation قطعی        → سطح تعیین می‌شود، بقیه فقط تعدیل جزئی
 *   ۲. File Code match
 *   ۳. Deep Link / Payload
 *   ۴. Button resolution
 *   ۵. Name similarity
 *   ۶. Time proximity
 *   ۷. Profile / category
 *
 * بالاترین شاهدِ موجود «کف» را می‌سازد؛ شواهد ضعیف‌تر فقط می‌توانند چند
 * واحد بالا/پایینش کنند و هرگز از سطح بعدی عبورش نمی‌دهند.
 */
class STI_GS_Confidence {

	/* کف هر سطح شواهد. فاصله‌ها عمدی است تا سطوح در هم نروند. */
	const BASE_CORRELATION = 90;
	const BASE_FILE_CODE   = 70;
	const BASE_DEEP_LINK   = 60;
	const BASE_BUTTON      = 45;
	const BASE_NAME        = 30;
	const BASE_TIME        = 15;
	const BASE_NONE        = 5;

	/* سقف تعدیل شواهد فرعی — نمی‌گذارد یک سطح به سطح بالاتر سرریز کند. */
	const MAX_ADJUST = 9;

	const TIER_HIGH   = 'HIGH';
	const TIER_MEDIUM = 'MEDIUM';
	const TIER_LOW    = 'LOW';

	/**
	 * محاسبه‌ی اطمینان نهایی یک تطبیق فایل.
	 *
	 * @param array $args {
	 *   @type array $correlation خروجی STI_GS_File_Matcher::correlate_pool()
	 *   @type array $candidate   ردیف برنده از sti_gs_bot_candidates
	 *   @type array $session     ردیف Pipeline Item
	 * }
	 * @return array{confidence:int, tier:string, primary:string, sources:array, deterministic:bool}
	 */
	public static function for_match( $args = array() ) {
		$correlation = isset( $args['correlation'] ) && is_array( $args['correlation'] ) ? $args['correlation'] : array();
		$candidate   = isset( $args['candidate'] ) && is_array( $args['candidate'] ) ? $args['candidate'] : array();
		$session     = isset( $args['session'] ) && is_array( $args['session'] ) ? $args['session'] : array();

		$sources = array();

		// ── سطح ۱: Correlation قطعی ───────────────────────────────────────
		// «قطعی» یعنی کلید دقیقاً به همان message_pk خورده و مبهم نبوده.
		$correlated = ! empty( $correlation['winner_index'] ) || ( isset( $correlation['winner_index'] ) && $correlation['winner_index'] >= 0 );
		$correlated = $correlated && (int) ( $correlation['winner_index'] ?? -1 ) >= 0;

		if ( $correlated ) {
			$raw = self::clamp( (int) ( $correlation['confidence'] ?? 0 ) );
			$sources[] = array( 'source' => 'correlation', 'method' => (string) ( $correlation['method'] ?? '' ), 'raw' => $raw );

			// خروجی deterministic است: برای یک روش مشخص همیشه یک عدد.
			$confidence = self::clamp( self::BASE_CORRELATION + (int) round( ( $raw - 90 ) / 10 * self::MAX_ADJUST ) );
			return self::result( $confidence, 'correlation:' . ( $correlation['method'] ?? '' ), $sources, true );
		}

		if ( ! empty( $correlation['ambiguous'] ) || ! empty( $correlation['multiple_confirmed'] ) ) {
			// Correlation دیده شد ولی رد شد — ثبت می‌شود تا در Audit پیدا باشد،
			// اما در محاسبه نقشی ندارد و کار به امتیازدهی برمی‌گردد.
			$sources[] = array( 'source' => 'correlation', 'method' => 'rejected', 'raw' => 0 );
		}

		// ── سطوح ۲ تا ۶: امتیازدهی موجود File Matcher ─────────────────────
		$code_score = (int) ( $candidate['score_file_code'] ?? 0 );
		$name_score = (int) ( $candidate['score_file_name'] ?? 0 );
		$time_score = (int) ( $candidate['score_time'] ?? 0 );

		$button_conf   = (int) ( $session['button_confidence'] ?? 0 );
		$button_method = (string) ( $session['button_resolution_method'] ?? '' );
		$via_deep_link = in_array( $button_method, array( 'deep_link', 'bot_start' ), true );

		if ( $code_score > 0 ) {
			$sources[] = array( 'source' => 'file_code', 'raw' => $code_score );
			$base = self::BASE_FILE_CODE;
		} elseif ( $via_deep_link && $button_conf > 0 ) {
			$sources[] = array( 'source' => 'deep_link', 'method' => $button_method, 'raw' => $button_conf );
			$base = self::BASE_DEEP_LINK;
		} elseif ( $button_conf > 0 ) {
			$sources[] = array( 'source' => 'button', 'method' => $button_method, 'raw' => $button_conf );
			$base = self::BASE_BUTTON;
		} elseif ( $name_score > 0 ) {
			$sources[] = array( 'source' => 'file_name', 'raw' => $name_score );
			$base = self::BASE_NAME;
		} elseif ( $time_score > 0 ) {
			$sources[] = array( 'source' => 'time', 'raw' => $time_score );
			$base = self::BASE_TIME;
		} else {
			$sources[] = array( 'source' => 'none', 'raw' => 0 );
			$base = self::BASE_NONE;
		}

		// شواهد فرعی فقط تعدیل می‌کنند. total_score نامحدود است، پس با یک
		// اشباع نرم به بازه آورده می‌شود — نه با تقسیم بر عددی دلبخواه که
		// روی امتیازهای بزرگ می‌شکند.
		$total = (int) ( $candidate['total_score'] ?? 0 );
		if ( $total > 0 ) {
			$sources[] = array( 'source' => 'total_score', 'raw' => $total );
		}
		$adjust = (int) round( self::MAX_ADJUST * self::saturate( $total, 150 ) );

		if ( $button_conf > 0 && $base > self::BASE_BUTTON ) {
			// دکمه وقتی شاهد اصلی نیست، فقط کمی تقویت می‌کند.
			$adjust = min( self::MAX_ADJUST, $adjust + (int) round( 3 * self::saturate( $button_conf, 100 ) ) );
		}

		return self::result( self::clamp( $base + $adjust ), $sources[0]['source'], $sources, false );
	}

	/**
	 * اطمینان یک Candidate در مرحله‌ی Profile Match (§26).
	 *
	 * profile score هنوز نوشته نمی‌شود (Deferred). تا آن زمان این متد فقط
	 * سطح ۷ را برمی‌گرداند تا وقتی ستون پر شد، همین‌جا وصل شود و هیچ
	 * فراخواننده‌ای لازم نباشد تغییر کند.
	 */
	public static function for_profile_match( $score, $max_score = 100 ) {
		$score = max( 0, (int) $score );
		if ( $score < 1 ) {
			return self::result( self::BASE_NONE, 'profile', array(), false );
		}
		$confidence = self::clamp( (int) round( 100 * self::saturate( $score, max( 1, (int) $max_score ) ) ) );
		return self::result( $confidence, 'profile', array( array( 'source' => 'profile', 'raw' => $score ) ), false );
	}

	/** تبدیل عدد نامحدود به بازه‌ی ۰..۱ با اشباع نرم — هرگز از ۱ عبور نمی‌کند. */
	protected static function saturate( $value, $half ) {
		$value = max( 0, (float) $value );
		$half  = max( 1, (float) $half );
		return $value / ( $value + $half );
	}

	public static function clamp( $value ) {
		return max( 0, min( 100, (int) $value ) );
	}

	/** همان سه سطحی که Button Resolver از قبل استفاده می‌کند — بدون تغییر آن. */
	public static function tier( $confidence ) {
		$confidence = self::clamp( $confidence );
		if ( $confidence >= 80 ) {
			return self::TIER_HIGH;
		}
		if ( $confidence >= 50 ) {
			return self::TIER_MEDIUM;
		}
		return self::TIER_LOW;
	}

	protected static function result( $confidence, $primary, $sources, $deterministic ) {
		$confidence = self::clamp( $confidence );
		return array(
			'confidence'    => $confidence,
			'tier'          => self::tier( $confidence ),
			'primary'       => (string) $primary,
			'sources'       => array_values( (array) $sources ),
			'deterministic' => (bool) $deterministic,
		);
	}
}
