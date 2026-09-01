<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI_AutoCat_Pro — لایه‌ی حرفه‌ای اتوکت (v7)
 *
 * اتوکت پایه فقط امتیاز کلیدواژه می‌داد؛ اگر عنوانی کلیدواژه نداشت، دسته‌بندی
 * اشتباه یا هیچ می‌شد. این لایه سه چیز اضافه می‌کند:
 *  ۱) داور هوش مصنوعی برای موارد مشکوک (امتیاز پایین یا دو دسته‌ی نزدیک)
 *  ۲) یادگیری خودکار: هر تصمیم AI به‌صورت کلیدواژه‌ی پیشنهادی ثبت می‌شود تا
 *     دفعه‌ی بعد بدون هزینه‌ی توکن، خود اتوکت درست تشخیص دهد
 *  ۳) آمار دقت و صف بازبینی
 */
class STI_AutoCat_Pro {

	const DECISION_OPTION = 'sti_autocat_ai_decisions';
	const AMBIGUITY_GAP   = 25;   // اگر فاصله‌ی دسته‌ی اول و دوم کمتر از این بود = مشکوک
	const LOW_CONFIDENCE  = 70;

	/** آیا داور AI فعال است؟ */
	public static function ai_enabled() {
		$on = (int) STI_Settings::get( 'autocat_ai_judge', 1 );
		return $on && class_exists( 'STI_AI' ) && STI_AI::is_ready();
	}

	/**
	 * تصمیم نهایی دسته‌بندی: قانون‌ها + داور AI.
	 *
	 * @param array $detection خروجی STI_AutoCat::detect()
	 * @return array همان ساختار، ولی احتمالاً اصلاح‌شده (+ کلید judge)
	 */
	public static function refine( $detection, $title, $file_type = '' ) {
		if ( ! is_array( $detection ) ) { return $detection; }

		$scores = (array) ( $detection['all_scores'] ?? array() );
		$first  = $scores[0] ?? null;
		$second = $scores[1] ?? null;
		$confidence = (int) ( $detection['confidence'] ?? 0 );

		$no_match  = empty( $detection['main_category'] );
		$low       = $confidence < self::LOW_CONFIDENCE;
		$ambiguous = $first && $second && ( (int) $first['score'] - (int) $second['score'] ) < self::AMBIGUITY_GAP && (int) $second['score'] > 0;

		if ( ! $no_match && ! $low && ! $ambiguous ) {
			$detection['judge'] = 'rules';
			return $detection;
		}
		if ( ! self::ai_enabled() ) {
			$detection['judge'] = 'rules-only';
			return $detection;
		}

		$ai = self::ai_detect( $title, $file_type );
		if ( is_wp_error( $ai ) || empty( $ai['slug'] ) ) {
			$detection['judge'] = 'rules-fallback';
			return $detection;
		}

		$defs = STI_AutoCat::get_main_categories_definition();
		if ( ! isset( $defs[ $ai['slug'] ] ) ) {
			$detection['judge'] = 'ai-invalid';
			return $detection;
		}

		$old = (string) ( $detection['main_category'] ?? '' );
		$detection['main_category'] = $ai['slug'];
		$detection['main_label']    = $defs[ $ai['slug'] ]['label'] ?? $ai['slug'];
		$detection['confidence']    = max( $confidence, min( 96, (int) $ai['confidence'] ) );
		$detection['judge']         = 'ai';
		$detection['ai_reason']     = (string) ( $ai['reason'] ?? '' );

		// امتیاز دسته‌ی انتخابی AI را در جدول امتیازها بالا بیاور تا آستانه‌ی
		// autocat_min_score هم این تصمیم را قبول کند.
		$min = (int) STI_Settings::get( 'autocat_min_score', 100 );
		$boosted = false;
		foreach ( $detection['all_scores'] as $i => $row ) {
			if ( ( $row['slug'] ?? '' ) === $ai['slug'] ) {
				$detection['all_scores'][ $i ]['score'] = max( (int) $row['score'], $min );
				$boosted = true;
			}
		}
		if ( ! $boosted ) {
			array_unshift( $detection['all_scores'], array(
				'slug' => $ai['slug'],
				'score' => $min,
				'priority' => 1,
				'label' => $detection['main_label'],
			) );
		}
		usort( $detection['all_scores'], function ( $a, $b ) { return (int) $b['score'] <=> (int) $a['score']; } );

		self::remember_decision( $title, $ai['slug'], $old, (string) ( $ai['reason'] ?? '' ) );
		self::maybe_learn( $title, $ai['slug'] );

		STI_Logger::info( 'اتوکت هوشمند: «' . mb_substr( $title, 0, 60 ) . '» → ' . $detection['main_label'] . ' (داور AI، اطمینان ' . (int) $ai['confidence'] . '٪)' );
		return $detection;
	}

	/** پرسش از هوش مصنوعی برای انتخاب دسته از میان دسته‌های مجاز. */
	public static function ai_detect( $title, $file_type = '' ) {
		if ( ! class_exists( 'STI_AI' ) || ! STI_AI::is_ready() ) {
			return new WP_Error( 'sti_ac_no_ai', 'هیچ سرویس هوش مصنوعی فعالی ثبت نشده است.' );
		}
		$defs = STI_AutoCat::get_main_categories_definition();
		$lines = array();
		foreach ( $defs as $slug => $def ) {
			$lines[] = '- ' . $slug . ': ' . ( $def['label'] ?? $slug );
		}
		$template = (string) STI_AI::get( 'prompt_category', '' );
		if ( '' === trim( $template ) ) {
			$template = "عنوان یک فایل گرافیکی را می‌دهم؛ دقیقاً یکی از دسته‌های مجاز را انتخاب کن.\n\n"
				. "عنوان: {title}\nنوع فایل: {file_type}\n\nدسته‌های مجاز (slug: برچسب):\n{categories}\n\n"
				. 'خروجی فقط این JSON: {"slug":"...","confidence":0-100,"reason":"دلیل کوتاه فارسی"}';
		}
		$prompt = STI_AI::render_prompt( $template, array(
			'title'      => $title,
			'file_type'  => $file_type,
			'categories' => implode( "\n", $lines ),
		) );
		$res = STI_AI::json( $prompt, array(
			'cache_key'   => 'autocat|' . mb_strtolower( $title . '|' . $file_type ),
			'temperature' => 0.1,
			'max_tokens'  => 250,
		) );
		if ( is_wp_error( $res ) ) { return $res; }
		$slug = sanitize_key( (string) ( $res['slug'] ?? '' ) );
		if ( '' === $slug ) { return new WP_Error( 'sti_ac_bad_ai', 'پاسخ AI دسته‌ای مشخص نکرد.' ); }
		return array(
			'slug'       => $slug,
			'label'      => $defs[ $slug ]['label'] ?? $slug,
			'confidence' => max( 0, min( 100, (int) ( $res['confidence'] ?? 80 ) ) ),
			'reason'     => sanitize_text_field( (string) ( $res['reason'] ?? '' ) ),
			'provider'   => (string) ( $res['_provider'] ?? '' ),
		);
	}

	/**
	 * یادگیری: کلیدواژه‌ی معنادار از عنوان استخراج و برای دسته‌ی تشخیص‌داده‌شده
	 * ثبت می‌شود تا دفعه‌ی بعد بدون AI هم درست تشخیص داده شود.
	 */
	public static function maybe_learn( $title, $slug ) {
		if ( ! (int) STI_Settings::get( 'autocat_auto_learn', 1 ) ) { return; }
		$tokens = STI_AutoCat::tokenize( (string) $title );
		$stop = array_merge( STI_Title_Engine::noise_words(), array( 'mockup', 'template', 'vector', 'psd' ) );
		$picked = array();
		foreach ( (array) $tokens as $t ) {
			if ( mb_strlen( $t ) < 4 ) { continue; }
			if ( in_array( $t, $stop, true ) ) { continue; }
			if ( preg_match( '/[0-9]/', $t ) ) { continue; }
			$picked[] = $t;
			if ( count( $picked ) >= 2 ) { break; }
		}
		foreach ( $picked as $kw ) {
			STI_AutoCat::add_keyword( $slug, $kw, 45, 'learned' );
		}
	}

	protected static function remember_decision( $title, $slug, $old_slug, $reason ) {
		$list = get_option( self::DECISION_OPTION, array() );
		if ( ! is_array( $list ) ) { $list = array(); }
		array_unshift( $list, array(
			'title'  => mb_substr( (string) $title, 0, 120 ),
			'slug'   => $slug,
			'old'    => $old_slug,
			'reason' => mb_substr( (string) $reason, 0, 120 ),
			'at'     => current_time( 'mysql' ),
		) );
		update_option( self::DECISION_OPTION, array_slice( $list, 0, 100 ), false );
	}

	public static function decisions( $limit = 30 ) {
		$list = get_option( self::DECISION_OPTION, array() );
		return is_array( $list ) ? array_slice( $list, 0, $limit ) : array();
	}
}
