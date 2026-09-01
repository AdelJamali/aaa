<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Correlation Engine (P3.3).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * مسئله‌ای که حل می‌کند
 *
 * یک پیام کانال معمولاً خودِ فایل نیست؛ اشاره‌ای به فایلی است که پشت یک ربات
 * قرار دارد. وقتی ربات بعداً فایل را در sti_bot_inbox تحویل می‌دهد، باید
 * بتوانیم بگوییم «این فایل مال کدام پیام بود».
 *
 * تا امروز این کار فقط با امتیازدهی حدسی در File_Matcher انجام می‌شد
 * (کد + نام فایل + فاصله‌ی زمانی). این موتور یک لایه‌ی قطعی‌تر اضافه می‌کند:
 * یک کلید مشترک که **از هر دو طرف** قابل تولید است.
 *
 *     پیام کانال ──► correlation_key ◄── فایل دریافتی از ربات
 *
 * File_Matcher حذف نمی‌شود؛ این موتور جلوتر از آن می‌نشیند (§157 — موتور
 * موازی نساز). اول تطبیق قطعی روی کلید، بعد امتیازدهی برای بقیه.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * چرا یک پاس جداگانه است و نه بخشی از save_message()
 *
 * §1 می‌گوید Scan و Match باید جدا باشند. عملاً هم منطق Correlation در طول
 * توسعه بارها عوض می‌شود؛ اگر داخل اسکن بود، هر تغییر یعنی اسکن دوباره.
 * با پاس جداگانه، همان Fixture پانصدتایی می‌ماند و فقط کلیدها بازتولید
 * می‌شوند:
 *
 *     STI_GS_Correlation::run_for_scan_run( 27 );   // چند ثانیه
 *
 * ─────────────────────────────────────────────────────────────────────────
 * شکل کلید
 *
 *   code:<کد نرمال‌شده>          ← بالاترین اولویت
 *   link:<ربات>:<payload>
 *   doc:<telegram_document_id>
 *
 * ترتیب اولویت عمدی است: کلید اصلی باید چیزی باشد که **از فایل تحویلی هم
 * قابل بازتولید باشد**. کد از نام فایل درمی‌آید (Magnific_24943123.zip)،
 * ولی payload یک deep-link از فایل تحویلی قابل بازیابی نیست. پس هر جا کدی
 * قابل استخراج باشد، همان کلید اصلی است.
 */
class STI_GS_Correlation {

	const METHOD_CAPTION_CODE = 'caption_code';
	const METHOD_PAYLOAD_CODE = 'payload_code';
	const METHOD_DEEP_LINK    = 'deep_link';
	const METHOD_DOCUMENT_ID  = 'document_id';
	const METHOD_FILENAME     = 'filename_code';

	/** حداقل طول یک رشته‌ی رقمی تا «کد» حساب شود — §39: هر عدد ۴ رقمی کد نیست. */
	const MIN_CODE_DIGITS = 5;

	const MAX_KEY_LENGTH = 190; // عرض ستون correlation_key

	/* ============================ استخراج سیگنال ============================ */

	/**
	 * سیگنال‌های خام یک ردیف Inventory.
	 *
	 * @param array $row یک ردیف از sti_gs_messages
	 * @return array{code:string, bot:string, payload:string, deep_link:string, document_id:int, file_name:string}
	 */
	public static function signals( $row ) {
		$out = array(
			'code'        => '',
			'bot'         => '',
			'payload'     => '',
			'deep_link'   => '',
			'document_id' => (int) ( $row['telegram_document_id'] ?? 0 ),
			'file_name'   => (string) ( $row['file_name'] ?? '' ),
		);

		// ۱) کدی که Scanner از caption استخراج کرده. این مسیر از قبل
		// Context-aware است: STI_Caption_Parser فقط الگوی برچسب‌دار
		// («Code:» / «کد فایل:») را می‌گیرد، نه هر عدد رهایی در متن.
		$out['code'] = self::normalize_code( $row['file_code'] ?? '' );

		// ۲) دکمه‌ها از raw_json — همان ساختاری که normalize_message ساخته.
		$raw = self::decode_raw( $row );
		// یک URL خراب نباید کل پاس Correlation (و در نتیجه کل اسکن) را
		// بیندازد — هر پیام مستقل بررسی می‌شود.
		foreach ( self::buttons_from_raw( $raw ) as $url ) {
			$parsed = self::parse_deep_link( $url );
			if ( ! $parsed ) {
				continue;
			}
			$out['deep_link'] = $url;
			$out['bot']       = $parsed['bot'];
			$out['payload']   = $parsed['payload'];
			break; // اولین deep-link معتبر؛ بقیه معمولاً «کانال ما» و مشابه‌اند.
		}

		// ۳) اگر caption کد نداشت، از payload دربیاور.
		if ( '' === $out['code'] && '' !== $out['payload'] ) {
			$out['code'] = self::code_from_token( $out['payload'] );
		}

		// ۴) آخرین تلاش: از نام فایل — ولی فقط اگر نام فایل «عمومی» نباشد.
		if ( '' === $out['code'] && '' !== $out['file_name'] && ! self::is_generic_media_name( $out['file_name'] ) ) {
			$out['code'] = self::code_from_token( $out['file_name'] );
		}

		return $out;
	}

	/**
	 * تمام کلیدهای قابل تولید برای یک ردیف، به ترتیب اولویت.
	 *
	 * چند کلید برمی‌گرداند نه یکی، چون تطبیق ممکن است از هر کدام برقرار شود.
	 * ستون correlation_key فقط اولی را نگه می‌دارد.
	 *
	 * @return array<int, array{key:string, method:string, confidence:int}>
	 */
	public static function keys( $signals ) {
		$keys = array();

		if ( '' !== $signals['code'] ) {
			$method = ( '' !== ( $signals['payload'] ?? '' ) && false !== strpos( self::normalize_code( $signals['payload'] ), $signals['code'] ) )
				? self::METHOD_PAYLOAD_CODE
				: self::METHOD_CAPTION_CODE;
			$keys[] = array(
				'key'        => self::clip( 'code:' . $signals['code'] ),
				'method'     => $method,
				'confidence' => self::METHOD_CAPTION_CODE === $method ? 95 : 85,
			);
		}

		if ( '' !== $signals['bot'] && '' !== $signals['payload'] ) {
			$keys[] = array(
				'key'        => self::clip( 'link:' . $signals['bot'] . ':' . self::normalize_code( $signals['payload'] ) ),
				'method'     => self::METHOD_DEEP_LINK,
				'confidence' => 90,
			);
		}

		if ( $signals['document_id'] > 0 ) {
			// پیام خودش فایل را دارد: هویت قطعی است، نیازی به ربات نیست.
			$keys[] = array(
				'key'        => 'doc:' . $signals['document_id'],
				'method'     => self::METHOD_DOCUMENT_ID,
				'confidence' => 100,
			);
		}

		return $keys;
	}

	/** کلید اصلی — همانی که در ستون correlation_key می‌نشیند. */
	public static function primary_key( $signals ) {
		$keys = self::keys( $signals );
		return $keys ? $keys[0]['key'] : '';
	}

	/* ======================= پاس دسته‌ای روی Inventory ======================= */

	/**
	 * تولید correlation_key برای تمام پیام‌های یک Scan Run.
	 *
	 * قابل اجرای مکرر: هر بار کلیدها را از نو می‌سازد. یعنی می‌شود منطق
	 * بالا را عوض کرد و روی همان Fixture دوباره اجرا کرد.
	 *
	 * @return array آمار اجرا
	 */
	public static function run_for_scan_run( $scan_run_id, $batch = 500 ) {
		return self::run_where(
			'scan_run_id = %d', array( (int) $scan_run_id ), $batch
		);
	}

	/** همان، اما برای یک کانال. */
	public static function run_for_channel( $channel_id, $batch = 500 ) {
		return self::run_where(
			'channel_id = %d', array( (int) $channel_id ), $batch
		);
	}

	protected static function run_where( $where, $params, $batch ) {
		global $wpdb;
		$table = STI_GS_DB::messages_table();
		$batch = max( 50, min( 2000, (int) $batch ) );

		$stats = array( 'scanned' => 0, 'keyed' => 0, 'unresolved' => 0, 'by_method' => array() );
		$last_id = 0;

		// صفحه‌بندی با کلید اصلی، نه OFFSET — روی جدول‌های بزرگ ثابت‌هزینه است (§141).
		while ( true ) {
			$sql = $wpdb->prepare(
				"SELECT id, channel_id, file_code, file_name, telegram_document_id, raw_json
				 FROM {$table}
				 WHERE {$where} AND id > %d
				 ORDER BY id ASC LIMIT %d",
				array_merge( $params, array( $last_id, $batch ) )
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				$stats['scanned']++;

				try {
					$signals = self::signals( $row );
					$keys    = self::keys( $signals );
				} catch ( \Throwable $e ) {
					// یک ردیف خراب نباید بقیه را متوقف کند.
					$stats['unresolved']++;
					STI_Logger::error( 'گلدن اسکن Correlation: پیام #' . (int) $row['id'] . ' — ' . $e->getMessage() );
					continue;
				}

				if ( empty( $keys ) ) {
					$stats['unresolved']++;
					// عمداً پاک نمی‌کنیم: اگر قبلاً کلیدی داشت و حالا منطق
					// نتوانست بسازد، نگه‌داشتنش بی‌ضررتر از از دست دادنش است.
					continue;
				}

				$primary = $keys[0];
				$updated = $wpdb->update(
					$table,
					array( 'correlation_key' => $primary['key'], 'updated_at' => current_time( 'mysql' ) ),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					STI_Logger::error( 'گلدن اسکن Correlation: نوشتن کلید روی پیام #' . (int) $row['id'] . ' ناموفق بود: ' . $wpdb->last_error );
					continue;
				}

				$stats['keyed']++;
				$m = $primary['method'];
				$stats['by_method'][ $m ] = ( $stats['by_method'][ $m ] ?? 0 ) + 1;
			}

			if ( count( $rows ) < $batch ) {
				break;
			}
		}

		return $stats;
	}

	/* ========================= جهت معکوس: فایل ← پیام ========================= */

	/**
	 * کلیدهایی که از یک فایل دریافتی از ربات قابل تولیدند.
	 *
	 * @param array $inbox یک ردیف sti_bot_inbox
	 * @return array<int, array{key:string, method:string, confidence:int}>
	 */
	public static function keys_for_received_file( $inbox ) {
		$keys = array();

		$document_id = (int) ( $inbox['telegram_document_id'] ?? 0 );
		if ( $document_id > 0 ) {
			$keys[] = array(
				'key'        => 'doc:' . $document_id,
				'method'     => self::METHOD_DOCUMENT_ID,
				'confidence' => 100,
			);
		}

		$inbox_name = (string) ( $inbox['file_name'] ?? '' );
		$code = self::is_generic_media_name( $inbox_name ) ? '' : self::code_from_token( $inbox_name );
		if ( '' !== $code ) {
			$keys[] = array(
				'key'        => self::clip( 'code:' . $code ),
				'method'     => self::METHOD_FILENAME,
				'confidence' => 80,
			);
		}

		return $keys;
	}

	/**
	 * پیدا کردن پیام‌های Inventory که با این کلیدها می‌خوانند.
	 *
	 * ترتیب کلیدها حفظ می‌شود: اولین کلیدی که نتیجه بدهد برنده است، چون
	 * کلیدها از قبل بر اساس اطمینان مرتب شده‌اند.
	 *
	 * @return array{row:?array, method:string, confidence:int, ambiguous:bool}
	 */
	public static function lookup( $keys, $channel_id = 0 ) {
		global $wpdb;
		$table = STI_GS_DB::messages_table();

		foreach ( (array) $keys as $entry ) {
			if ( empty( $entry['key'] ) ) {
				continue;
			}

			$sql = "SELECT * FROM {$table} WHERE correlation_key = %s";
			$params = array( $entry['key'] );
			if ( $channel_id > 0 ) {
				$sql .= ' AND channel_id = %d';
				$params[] = (int) $channel_id;
			}
			$sql .= ' ORDER BY id ASC LIMIT 2';

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
			if ( empty( $rows ) ) {
				continue;
			}

			// دو پیام با یک کلید: کد تکراری در کانال. نتیجه برگردانده می‌شود
			// ولی صریحاً مبهم علامت می‌خورد تا فراخواننده بتواند به
			// امتیازدهی File_Matcher برگردد — نه اینکه کورکورانه اولی را بردارد (§38).
			return array(
				'row'        => $rows[0],
				'method'     => $entry['method'],
				'confidence' => count( $rows ) > 1 ? (int) round( $entry['confidence'] * 0.6 ) : (int) $entry['confidence'],
				'ambiguous'  => count( $rows ) > 1,
			);
		}

		return array( 'row' => null, 'method' => '', 'confidence' => 0, 'ambiguous' => false );
	}

	/* ============================== ابزار داخلی ============================== */

	protected static function decode_raw( $row ) {
		$raw = $row['raw_json'] ?? '';
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** استخراج URL دکمه‌ها از ساختار خام MTProto. */
	protected static function buttons_from_raw( $raw ) {
		$urls = array();
		$rows = $raw['reply_markup']['rows'] ?? ( $raw['reply_markup']['inline_keyboard'] ?? array() );
		foreach ( (array) $rows as $row ) {
			$buttons = ( is_array( $row ) && isset( $row['buttons'] ) && is_array( $row['buttons'] ) )
				? $row['buttons']
				: ( is_array( $row ) ? $row : array() );
			foreach ( (array) $buttons as $button ) {
				if ( is_array( $button ) && ! empty( $button['url'] ) ) {
					$urls[] = (string) $button['url'];
				}
			}
		}
		return $urls;
	}

	/**
	 * تجزیه‌ی deep-link تلگرام.
	 * https://t.me/fileechbot?start=party-576198  →  bot=fileechbot، payload=party-576198
	 */
	protected static function parse_deep_link( $url ) {
		// جداکننده عمداً ~ است، نه #.
		// با جداکننده‌ی #، کاراکتر # داخل کلاس [^#] هم پایان الگو حساب می‌شود
		// (PCRE هنگام پیدا کردن جداکننده‌ی پایانی، کلاس کاراکتری را در نظر
		// نمی‌گیرد). نتیجه‌اش خطای «Unknown modifier ']'» بود و هر پیامی که
		// دکمه داشت، کل اسکن آن کانال را می‌انداخت.
		if ( ! preg_match( '~(?:https?://)?t(?:elegram)?\.me/([A-Za-z0-9_]{3,64})\?(?:[^\#]*&)?start=([A-Za-z0-9_\-=]+)~i', (string) $url, $m ) ) {
			return null;
		}
		return array(
			'bot'     => strtolower( $m[1] ),
			'payload' => $m[2],
		);
	}

	/**
	 * استخراج «کد» از یک رشته (payload یا نام فایل).
	 *
	 * §39: هر عدد کوتاهی کد نیست. فقط بلندترین رشته‌ی رقمی پیوسته با حداقل
	 * MIN_CODE_DIGITS رقم پذیرفته می‌شود — یعنی 24943123 قبول، ولی 2026 و
	 * 500 و 300 رد.
	 */
	protected static function code_from_token( $token ) {
		// عمداً از normalize_code استفاده نمی‌شود: آن متد فاصله‌ها را **حذف**
		// می‌کند، و برای استخراج فاجعه است. «2026 pack 500 300» تبدیل می‌شد
		// به «2026pack500300» و رشته‌ی جعلیِ «500300» به‌عنوان یک کد ۶ رقمی
		// پذیرفته می‌شد — دقیقاً همان اشتباهی که §39 هشدار داده است.
		// اینجا فقط ارقام غیرلاتین یکسان‌سازی می‌شوند و هر کاراکتر غیررقمی
		// (فاصله، خط تیره، زیرخط، نقطه) مرز طبیعی بین توکن‌ها می‌ماند.
		$token = self::latinize_digits( (string) $token );
		if ( '' === $token ) {
			return '';
		}
		if ( ! preg_match_all( '/\d+/', $token, $matches ) ) {
			return '';
		}
		$best = '';
		foreach ( $matches[0] as $run ) {
			if ( strlen( $run ) >= self::MIN_CODE_DIGITS && strlen( $run ) > strlen( $best ) ) {
				$best = $run;
			}
		}
		if ( '' !== $best ) {
			return $best;
		}

		/**
		 * کدهای حروفی.
		 *
		 * همه‌ی کدها عددی نیستند. در همین کانال دیده شده:
		 *
		 *     envato_X5LZPEA.zip   →  کد X5LZPEA
		 *     envato_BKHFMJS.zip   →  کد BKHFMJS
		 *
		 * استخراج قبلی فقط رشته‌ی رقمی می‌گرفت و این‌ها را کامل از دست
		 * می‌داد.
		 *
		 * «دست‌کم یک رقم» شرط کافی نیست: BKHFMJS هیچ رقمی ندارد. پس ملاک
		 * **موقعیت** است، نه محتوا — کد بعد از پیشوند سایت و زیرخط می‌آید:
		 *
		 *     envato_X5LZPEA.zip   →  X5LZPEA
		 *     envato_BKHFMJS.zip   →  BKHFMJS
		 *
		 * این الگو فقط همین ساختار را می‌گیرد و کلمه‌ی رها در متن را کد
		 * نمی‌گیرد.
		 */
		if ( preg_match( '/^[A-Za-z]+_([A-Z0-9]{5,16})(?:\.[A-Za-z0-9]+)?$/', trim( (string) $token ), $m ) ) {
			return $m[1];
		}

		if ( preg_match_all( '/\b(?=[A-Z0-9]{6,12}\b)(?=[A-Z0-9]*[0-9])[A-Z0-9]+\b/', (string) $token, $alnum ) ) {
			foreach ( $alnum[0] as $run ) {
				if ( strlen( $run ) > strlen( $best ) ) {
					$best = $run;
				}
			}
		}

		return $best;
	}

	/** فقط ارقام فارسی/عربی را به لاتین تبدیل می‌کند؛ بقیه‌ی متن دست‌نخورده. */
	protected static function latinize_digits( $value ) {
		$map = array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		);
		return strtr( (string) $value, $map );
	}

	/**
	 * نام فایل‌های خودکارِ تلگرام که عددشان کد نیست.
	 *
	 * «photo_162111.jpg» شماره‌ی پیام است، نه کد فایل. چون ۶ رقم دارد از
	 * فیلتر «حداقل ۵ رقم» رد می‌شد و کلید جعلی «code:162111» می‌ساخت — در
	 * حالی که کد واقعی همان پیام 165976622 بود.
	 *
	 * نام‌های واقعی مثل Magnific_419293531.zip و freepik_165976622.zip
	 * دست‌نخورده می‌مانند، چون پیشوندشان عمومی نیست.
	 */
	protected static function is_generic_media_name( $file_name ) {
		$base = strtolower( trim( (string) $file_name ) );
		return (bool) preg_match( '~^(photo|image|img|video|file|document|doc|cover|preview|thumb|thumbnail|screenshot)[ _\-]?\d+~', $base );
	}

	protected static function normalize_code( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		// از همان نرمال‌سازی مشترک استفاده می‌کنیم (ارقام فارسی/عربی، حروف
		// کوچک، حذف کاراکترهای اضافه) تا دو مسیر هرگز واگرا نشوند.
		if ( class_exists( 'STI_Channel_Index' ) ) {
			return STI_Channel_Index::normalize_code( $value );
		}
		$value = preg_replace( '/\s+/u', '', $value );
		$value = strtolower( ltrim( $value, '#' ) );
		return substr( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ), 0, 100 );
	}

	protected static function clip( $key ) {
		return substr( (string) $key, 0, self::MAX_KEY_LENGTH );
	}
}
