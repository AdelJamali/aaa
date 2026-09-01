<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — فاز ۳-الف: Button Resolver + Confidence Score + Artifact Logging.
 *
 * فقط تشخیص می‌کند و امتیاز می‌دهد؛ هیچ کلیکی زده نمی‌شود، هیچ درخواستی به
 * تلگرام/ربات ارسال نمی‌شود، دانلود یا محصولی ساخته نمی‌شود. دکمه‌ها مستقیم
 * از raw_json ذخیره‌شده در فاز ۱ می‌آیند (با STI_MTProto::normalize_message
 * که در همان فاز استفاده شد) — یعنی این کلاس هیچ تماس شبکه‌ای ندارد.
 *
 * اولویت لایه‌ها طبق سند MotoGold:
 *   callback(95) > deep_link(90) > url(80) > reply_keyboard(55-70) >
 *   text_pattern(50) > similarity(≤60% از نسبت شباهت) > unknown(15)
 */
class STI_GS_Button_Resolver {

	// کلمات «قوی» → یقیناً یعنی دانلود؛ کلمات «ضعیف» → سرنخ اما نه قطعی؛ کلمات «منفی» → یعنی این دکمه چیز دیگری‌ست (حالت J).
	const STRONG_WORDS   = array( 'دانلود', 'download', 'دریافت فایل', 'دریافت لینک', 'get file', 'get the file' );
	const WEAK_WORDS      = array( 'دریافت', 'فایل', 'لینک', 'zip', 'file', '⬇️', '⬇', '📥' );
	const NEGATIVE_WORDS  = array( 'مشاهده', 'پیش نمایش', 'پیش‌نمایش', 'خرید', 'اطلاعات', 'منبع', 'سایت', 'preview', 'buy', 'purchase', 'info', 'information', 'source', 'website', 'view', 'shop', 'more info', 'detail' );
	const PAYLOAD_HINTS   = array( 'file', 'download', 'dl_', 'get_', 'fetch' );

	const THRESHOLD_HIGH   = 80;
	const THRESHOLD_MEDIUM = 50;

	/** این حالت‌ها یعنی دکمه قبلاً resolve شده (یا مراحل بعدی‌تر) — اجرای دوباره باید Skip شود، نه خطا. */
	const PAST_STATES = array(
		'BUTTON_FOUND', 'WAITING_BOT', 'ERROR_CLICK', 'BOT_RESPONSE', 'ERROR_MATCH', 'FILE_MATCHED',
		'DOWNLOAD_PENDING', 'DOWNLOADING', 'DOWNLOAD_FAILED', 'STORED',
		'MEDIA_BUILDING', 'MEDIA_FAILED', 'MEDIA_READY',
		'PRODUCT_BUILDING', 'PRODUCT_FAILED', 'PRODUCT_READY', 'REVIEW_READY',
	);

	/** سطح اطمینان به‌صورت انسانی — Action Executor (فاز ۳-ب) بر همین اساس تصمیم می‌گیرد که خودکار کلیک کند یا نه؛ اینجا فقط محاسبه می‌شود. */
	public static function tier( $confidence ) {
		$confidence = (int) $confidence;
		if ( $confidence >= self::THRESHOLD_HIGH ) { return 'HIGH'; }
		if ( $confidence >= self::THRESHOLD_MEDIUM ) { return 'MEDIUM'; }
		return 'LOW';
	}

	public static function resolve( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'resolver-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, 60 ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط یک worker دیگر پردازش می‌شود.' );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}

			if ( in_array( $session['state'], self::PAST_STATES, true ) ) {
				STI_GS_Event::log( $session_id, 'button_resolver', 'ok',
					'Stage قبلاً کامل شده — Skip.',
					array( 'stage' => 'button_resolver', 'reason' => 'already_completed', 'current_state' => $session['state'] )
				);
				return array( 'state' => $session['state'], 'skipped' => true, 'method' => $session['button_resolution_method'], 'confidence' => $session['button_confidence'] );
			}

			$message = self::load_message( (int) $session['message_pk'] );
			if ( ! $message ) {
				self::fail( $session_id, 'MESSAGE_NOT_FOUND: پیام مبدأ در sti_gs_messages پیدا نشد.' );
				return new WP_Error( 'sti_gs_no_message', 'پیام مبدأ پیدا نشد.' );
			}

			$raw = json_decode( (string) ( $message['raw_json'] ?? '' ), true );
			if ( empty( $message['raw_json'] ) ) {
				self::fail( $session_id, 'RAW_JSON_MISSING: این پیام raw_json ذخیره‌شده ندارد (نیاز به اسکن مجدد).' );
				return new WP_Error( 'sti_gs_no_raw', 'raw_json موجود نیست.' );
			}
			if ( ! is_array( $raw ) ) {
				self::fail( $session_id, 'RAW_JSON_MALFORMED: raw_json این پیام قابل decode نیست.' );
				return new WP_Error( 'sti_gs_bad_raw', 'raw_json خراب است.' );
			}

			$buttons = self::extract_buttons( $raw );
			STI_GS_Artifact::log( $session_id, 'button_detection', array(
				'buttons_found' => count( $buttons ),
				'buttons'       => $buttons,
			) );

			if ( empty( $buttons ) ) {
				self::fail( $session_id, 'NO_BUTTON_FOUND: هیچ دکمه‌ای (reply_markup) در این پیام وجود ندارد.' );
				return array( 'state' => 'ERROR_BUTTON', 'confidence' => 0 );
			}

			$scored = array();
			foreach ( $buttons as $i => $b ) {
				$s = self::score_button( $b );
				$s['index']  = $i;
				$s['button'] = $b;
				$scored[] = $s;
			}
			usort( $scored, function ( $a, $b ) {
				// اولویت با امتیاز بالاتر؛ در تساوی، دکمه‌ای که زودتر در پیام آمده (index کوچک‌تر)
				// برنده می‌شود — تا نتیجه روی همه‌ی نسخه‌های PHP (even <8.0 با usort ناپایدار) قطعی بماند.
				return $b['confidence'] <=> $a['confidence'] ?: $a['index'] <=> $b['index'];
			} );
			$best = $scored[0];

			STI_GS_Artifact::log( $session_id, 'button_selection', array(
				'selected'   => $best,
				'candidates' => $scored,
			) );

			STI_GS_Session::update( $session_id, array(
				'state'                    => 'BUTTON_FOUND',
				'button_type'              => $best['method'],
				'button_payload'           => wp_json_encode( $best['button'], JSON_UNESCAPED_UNICODE ),
				'button_confidence'        => $best['confidence'],
				'button_resolution_method' => $best['method'],
				'stage'                    => 'button_resolver',
				'error_reason'             => null,
			) );

			STI_GS_Event::log(
				$session_id, 'button_resolver', 'ok',
				"دکمه با روش «{$best['method']}» و اطمینان {$best['confidence']}٪ (" . self::tier( $best['confidence'] ) . ') انتخاب شد.',
				array( 'buttons_seen' => count( $buttons ) ),
				$best
			);

			return array( 'state' => 'BUTTON_FOUND', 'confidence' => $best['confidence'], 'method' => $best['method'], 'tier' => self::tier( $best['confidence'] ) );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
		}
	}

	protected static function fail( $session_id, $reason ) {
		STI_GS_Session::update( $session_id, array(
			'state'        => 'ERROR_BUTTON',
			'stage'        => 'button_resolver',
			'error_reason' => $reason,
		) );
		STI_GS_Event::log( $session_id, 'button_resolver', 'error', $reason );
	}

	protected static function load_message( $message_pk ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . STI_GS_DB::messages_table() . ' WHERE id = %d', (int) $message_pk
		), ARRAY_A );
	}

	/**
	 * دکمه‌ها را مستقیم از reply_markup خامِ raw_json استخراج می‌کند — نه از
	 * خروجی flatten‌شده‌ی normalize_message — چون این‌جا برخلاف فاز اسکن،
	 * موقعیت row/col هر دکمه هم برای Artifact لازم است. همان قرارداد ساختاری
	 * MadelineProto (فیلدهای rows/inline_keyboard، کلید '_' برای نوع) رعایت
	 * می‌شود؛ کلاس STI_MTProto دست‌نخورده می‌ماند.
	 */
	protected static function extract_buttons( $raw ) {
		$rows = $raw['reply_markup']['rows'] ?? array();
		if ( empty( $rows ) ) {
			$rows = $raw['reply_markup']['inline_keyboard'] ?? array();
		}

		$buttons = array();
		foreach ( (array) $rows as $row_index => $row ) {
			$row_buttons = ( is_array( $row ) && isset( $row['buttons'] ) && is_array( $row['buttons'] ) )
				? $row['buttons']
				: ( is_array( $row ) ? $row : array() );

			$col_index = 0;
			foreach ( (array) $row_buttons as $b ) {
				if ( ! is_array( $b ) ) {
					continue;
				}
				$text = (string) ( $b['text'] ?? '' );
				$type = (string) ( $b['_'] ?? '' );
				if ( '' === $text && '' === $type ) {
					continue; // نه متن نه نوع مشخص → این آرایه یک دکمه‌ی واقعی نیست.
				}
				$buttons[] = array(
					'row'     => (int) $row_index,
					'col'     => (int) $col_index,
					'text'    => $text,
					'url'     => (string) ( $b['url'] ?? '' ),
					'data'    => (string) ( $b['data'] ?? '' ),
					'payload' => (string) ( $b['data'] ?? '' ), // نام مستعار صریح برای callback_data
					'type'    => $type,
					'query'   => (string) ( $b['query'] ?? '' ),
				);
				$col_index++;
			}
		}
		return $buttons;
	}

	/**
	 * امتیازدهی جمع‌شونده و قابل‌توضیح: پایه‌ی ساختاری + سرنخ متن + جریمه‌ی
	 * کلمات نامرتبط (حالت J) + سرنخ درون URL/callback (حالت G). برخلاف نسخه‌ی
	 * قبلی، متن دکمه در تمام مسیرها (نه فقط fallback) اثر می‌گذارد تا دو دکمه‌ی
	 * هم‌نوع (مثلاً هر دو callback) صرفاً به‌خاطر نوع یکسان امتیاز یکسان نگیرند.
	 */
	protected static function score_button( $button ) {
		$type = mb_strtolower( (string) ( $button['type'] ?? '' ) );
		$url  = mb_strtolower( (string) ( $button['url'] ?? '' ) );
		$data = mb_strtolower( (string) ( $button['data'] ?? '' ) );
		$text = mb_strtolower( trim( (string) ( $button['text'] ?? '' ) ) );

		$reasons = array();
		$score   = 0;
		$method  = 'unknown';

		if ( '' !== $data || false !== strpos( $type, 'callback' ) ) {
			$method = 'callback';
			$score += 45;
			$reasons[] = '+45 callback button';
		} elseif ( '' !== $url && preg_match( '#[?&]start=([A-Za-z0-9_-]+)#i', $url ) ) {
			$method = 'deep_link';
			$score += 42;
			$reasons[] = '+42 deep link (start=)';
		} elseif ( '' !== $url ) {
			$method = 'url';
			$score += 35;
			$reasons[] = '+35 url button';
		} elseif ( '' !== $text && false !== strpos( $type, 'keyboardbutton' ) ) {
			$method = 'reply_keyboard';
			$score += 20;
			$reasons[] = '+20 reply keyboard button';
		} else {
			$reasons[] = '+0 نوع دکمه نامشخص';
		}

		$text_bonus = 0;
		foreach ( self::STRONG_WORDS as $w ) {
			if ( '' !== $text && false !== mb_strpos( $text, mb_strtolower( $w ) ) ) {
				$text_bonus = 40;
				$reasons[] = "+40 exact download text (\"{$w}\")";
				break;
			}
		}
		if ( 0 === $text_bonus ) {
			foreach ( self::WEAK_WORDS as $w ) {
				if ( '' !== $text && false !== mb_strpos( $text, mb_strtolower( $w ) ) ) {
					$text_bonus = 20;
					$reasons[] = "+20 weak download hint (\"{$w}\")";
					break;
				}
			}
		}
		$score += $text_bonus;

		foreach ( self::NEGATIVE_WORDS as $w ) {
			if ( '' !== $text && false !== mb_strpos( $text, mb_strtolower( $w ) ) ) {
				$score -= 35;
				$reasons[] = "-35 negative word (\"{$w}\") — این دکمه احتمالاً چیز دیگری‌ست";
				break;
			}
		}

		foreach ( self::PAYLOAD_HINTS as $hint ) {
			if ( ( '' !== $url && false !== strpos( $url, $hint ) ) || ( '' !== $data && false !== strpos( $data, $hint ) ) ) {
				$score += 10;
				$reasons[] = "+10 payload hint (\"{$hint}\" در url/data)";
				break;
			}
		}

		// Similarity فقط وقتی به‌کار می‌رود که هیچ سرنخ متنی قوی/ضعیفی پیدا نشد — آستانه‌ی بالاتر و سقف پایین‌تر از قبل، برای کاهش False Positive.
		if ( 0 === $text_bonus && '' !== $text ) {
			$sim = self::best_similarity( $text );
			if ( $sim >= 55 ) {
				$bonus = (int) round( $sim * 0.3 );
				$score += $bonus;
				if ( 'unknown' === $method ) {
					$method = 'similarity';
				}
				$reasons[] = "+{$bonus} text similarity ({$sim}%)";
			}
		}

		$score = max( 0, min( 99, $score ) );

		return array( 'method' => $method, 'confidence' => $score, 'reasons' => $reasons );
	}

	protected static function best_similarity( $text ) {
		$norm = mb_strtolower( trim( (string) $text ) );
		if ( '' === $norm ) {
			return 0;
		}
		$best = 0;
		foreach ( array_merge( self::STRONG_WORDS, self::WEAK_WORDS ) as $w ) {
			similar_text( $norm, mb_strtolower( $w ), $pct );
			if ( $pct > $best ) {
				$best = $pct;
			}
		}
		return $best;
	}
}
