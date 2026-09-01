<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Content Generation Selector.
 * موتور جدیدی نیست؛ فقط بر اساس تنظیم gs_content_generation_mode (free/sti_ai/existing)
 * یکی از زیرساخت‌های موجود (STI_Title_Engine / STI_AI / STI_Content_Generator) را صدا می‌زند.
 * قانون Scrape: در حالت existing، source_url هرگز ست نمی‌شود → اسکرپ خارجی هرگز فعال نمی‌شود.
 */
class STI_GS_Content_Engine {

	const TYPE_KEYWORDS = array(
		'mockup'   => array( 'mockup', 'موکاپ' ),
		'logo'     => array( 'logo', 'لوگو' ),
		'font'     => array( 'font', 'فونت', 'typeface' ),
		'photo'    => array( 'photo', 'عکس', 'تصویر', 'image' ),
		'vector'   => array( 'vector', 'وکتور', 'illustrator' ),
		'icon'     => array( 'icon', 'آیکون' ),
		'template' => array( 'template', 'قالب' ),
		'flyer'    => array( 'flyer', 'تراکت' ),
		'poster'   => array( 'poster', 'پوستر' ),
		'banner'   => array( 'banner', 'بنر' ),
		'brochure' => array( 'brochure', 'بروشور', 'کاتالوگ' ),
	);

	public static function generate( $session, $message, $category_label = '' ) {
		$mode = (string) STI_Settings::get( 'gs_content_generation_mode', 'auto' );
		/**
		 * پاک‌سازی UTF-8 پیش از تحویل به موتورهای مشترک.
		 *
		 * STI_Title_Engine::validate() از preg_split با مودیفایر /u استفاده
		 * می‌کند. اگر رشته UTF-8 معتبر نباشد، preg_split مقدار false
		 * برمی‌گرداند و count(false) در PHP 8 یک TypeError کشنده است —
		 * همان AI_FAILED ای که در لاگ دیده شد.
		 *
		 * caption تلگرام می‌تواند بایت ناقص داشته باشد (مثلاً وقتی متن روی
		 * مرز یک کاراکتر چندبایتی بریده شده). چون کلاس‌های مشترک READ ONLY
		 * هستند (§4)، پاک‌سازی در مرز گلدن اسکن انجام می‌شود.
		 */
		$file_name = self::clean_utf8( self::clean_subject( $session, $message ) );
		$file_type = self::resolve_file_type( $message );
		$content_type = self::detect_content_type( $file_name . ' ' . self::clean_utf8( (string) ( $message['text_raw'] ?? '' ) ), $file_type );
		$software = class_exists( 'STI_Content_Generator' ) ? STI_Content_Generator::type_software_public( $file_type ) : '';
		$filesize = ! empty( $session['file_size_bytes'] ) ? size_format( (int) $session['file_size_bytes'] ) : '';

		$facts = array(
			'file_name' => $file_name, 'file_type' => $file_type, 'content_type' => $content_type,
			'category' => self::clean_utf8( $category_label ), 'software' => $software, 'filesize' => $filesize,
			'file_code' => (string) ( $session['file_code'] ?? '' ),
		);

		/**
		 * AI-First.
		 *
		 * موتور قاعده‌محور برای موضوع‌های دلخواه («metallic arch shaped
		 * stickers mosaic») ذاتاً جواب نمی‌دهد: واژه‌نامه فقط اصطلاح‌های
		 * تخصصی طراحی را دارد، و با strip_latin=1 هرچه ترجمه نشود دور
		 * ریخته می‌شود — یعنی عنوان خالی و بعد Fallback انگلیسی.
		 *
		 * پس اگر AI آماده باشد، اول از آن استفاده می‌شود. قاعده‌محور به
		 * جایگاه درست خودش برمی‌گردد: شبکه‌ی ایمنی، نه مسیر اصلی.
		 *
		 * حالت صریح در تنظیمات همچنان محترم است — این منطق فقط وقتی وارد
		 * می‌شود که مقدار تنظیم‌شده «auto» یا خالی باشد.
		 */
		$requested = $mode;
		if ( '' === $mode || 'auto' === $mode ) {
			$mode = self::ai_ready() ? 'sti_ai' : 'existing';
		}

		$attempts = array();
		$result   = self::run_mode( $mode, $facts, $session, $file_name, $file_type );
		$attempts[] = array( 'mode' => $mode, 'title' => (string) ( $result['title'] ?? '' ) );

		/**
		 * زنجیره‌ی سقوط: اگر حالت انتخاب‌شده عنوان خالی داد، به‌جای رفتن
		 * مستقیم به Fallback انگلیسیِ Product Builder، حالت بعدی امتحان
		 * می‌شود. ترتیب از باکیفیت به کم‌کیفیت است.
		 */
		$chain = array( 'sti_ai', 'existing', 'free' );
		foreach ( $chain as $next_mode ) {
			if ( ! empty( $result['title'] ) ) {
				break;
			}
			if ( $next_mode === $mode ) {
				continue;
			}
			if ( 'sti_ai' === $next_mode && ! self::ai_ready() ) {
				continue;
			}
			$result = self::run_mode( $next_mode, $facts, $session, $file_name, $file_type );
			$attempts[] = array( 'mode' => $next_mode, 'title' => (string) ( $result['title'] ?? '' ) );
			$mode = $next_mode;
		}

		$result['title']        = self::final_safety( $result['title'] ?? $file_name );
		$result['engine']       = $mode;
		$result['content_type'] = $content_type;
		$result['engine_debug'] = array(
			'requested_mode' => $requested,
			'used_mode'      => $mode,
			'ai_ready'       => self::ai_ready(),
			'attempts'       => $attempts,
		);
		return $result;
	}

	/** آیا موتور AI واقعاً قابل استفاده است؟ */
	protected static function ai_ready() {
		return class_exists( 'STI_AI' )
			&& method_exists( 'STI_AI', 'is_ready' )
			&& (bool) STI_AI::is_ready();
	}

	protected static function run_mode( $mode, $facts, $session, $file_name, $file_type ) {
		if ( 'free' === $mode ) {
			return self::mode_free( $facts );
		}
		if ( 'sti_ai' === $mode ) {
			return self::mode_sti_ai( $facts );
		}
		return self::mode_existing( $session, $file_name, $file_type );
	}

	/**
	 * نام دسته را به کلیدی تبدیل می‌کند که STI_Title_Engine::type_labels()
	 * می‌شناسد.
	 *
	 * جدول برچسب‌ها کلیدهای انگلیسی دارد (`MOCKUP => موکاپ`) ولی دسته‌های
	 * شما فارسی‌اند («موکاپ»، «لوگو»). بدون این نگاشت، دسته شناخته نمی‌شد و
	 * کلمه‌ی نوع خالی می‌ماند.
	 */
	protected static function category_to_type_key( $category ) {
		$c = trim( (string) $category );
		if ( '' === $c ) {
			return '';
		}

		$map = array(
			'موکاپ'            => 'MOCKUP',
			'وکتور'            => 'VECTOR',
			'لوگو'             => 'LOGO',
			'فونت'             => 'FONT',
			'آیکن'             => 'ICON',
			'آیکون'            => 'ICON',
			'لایه باز'         => 'PSD',
			'لایه‌باز'          => 'PSD',
			'عکس خام'          => 'JPG',
			'کیت رابط کاربری'  => 'UIKIT',
			'کارت ویزیت'       => 'BUSINESSCARD',
			'قالب'             => 'TEMPLATE',
			'پترن'             => 'PATTERN',
			'تکسچر'            => 'TEXTURE',
		);

		if ( isset( $map[ $c ] ) ) {
			return $map[ $c ];
		}

		// دسته‌ی انگلیسی یا کلیدی که موتور از قبل می‌شناسد
		$upper = strtoupper( $c );
		if ( class_exists( 'STI_Title_Engine' ) && method_exists( 'STI_Title_Engine', 'type_labels' ) ) {
			$labels = STI_Title_Engine::type_labels();
			if ( isset( $labels[ $upper ] ) ) {
				return $upper;
			}
			// اگر خودِ دسته همان برچسب فارسی باشد («موکاپ» در مقادیر جدول)
			$key = array_search( $c, $labels, true );
			if ( false !== $key ) {
				return $key;
			}
		}

		return '';
	}

	/** بایت‌های نامعتبر UTF-8 را حذف می‌کند تا توابع regex با /u نشکنند. */
	protected static function clean_utf8( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		/**
		 * mb_substitute_character پیش‌فرض «?» است. به همین دلیل در لاگ
		 * «دانلود ?انلود» دیده شد: بایت خرابِ حرف «د» به «?» تبدیل شده بود،
		 * نه حذف. اینجا موقتاً روی «حذف» تنظیم می‌شود.
		 */
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$previous = function_exists( 'mb_substitute_character' ) ? mb_substitute_character() : null;
			if ( null !== $previous ) {
				mb_substitute_character( 'none' );
			}
			$converted = (string) @mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
			if ( null !== $previous ) {
				mb_substitute_character( $previous );
			}
			if ( '' !== $converted ) {
				return $converted;
			}
		}

		$clean = wp_check_invalid_utf8( $value, true );
		// wp_check_invalid_utf8 در نبود iconv/mbstring رشته‌ی خالی می‌دهد؛
		// در آن حالت به حذف بایت‌های غیر ASCII اکتفا می‌کنیم تا دست‌کم معتبر بماند.
		if ( '' === $clean && '' !== $value ) {
			$clean = (string) preg_replace( '/[^\x00-\x7F]/', '', $value );
		}
		return $clean;
	}

	protected static function mode_free( $facts ) {
		$t = self::title_via_engine( $facts, false );
		return array(
			'title' => $t['title'], 'focus_keyword' => $t['focus_keyword'], 'slug' => $t['slug'],
			'description' => self::free_description( $facts ),
			'meta_description' => wp_trim_words( $t['title'], 20, '' ),
			'validation' => $t['validation'],
		);
	}

	protected static function mode_sti_ai( $facts ) {
		$t = self::title_via_engine( $facts, true );
		return array(
			'title' => $t['title'], 'focus_keyword' => $t['focus_keyword'], 'slug' => $t['slug'],
			'description' => self::ai_description( $facts, $t['title'] ),
			'meta_description' => wp_trim_words( $t['title'], 20, '' ),
			'validation' => $t['validation'],
		);
	}

	protected static function mode_existing( $session, $file_name, $file_type ) {
		if ( ! class_exists( 'STI_Content_Generator' ) ) {
			return array( 'title' => $file_name, 'description' => '', 'focus_keyword' => '', 'slug' => sanitize_title( $file_name ), 'meta_description' => '', 'validation' => array() );
		}
		$fake = new \stdClass();
		$fake->id = $session['id']; $fake->file_name = $file_name; $fake->file_type = $file_type;
		$fake->file_code = (string) ( $session['file_code'] ?? '' );
		$fake->file_size_bytes = (int) ( $session['file_size_bytes'] ?? 0 );
		$fake->source_url = null; // قانون معماری: هرگز Scrape خارجی برای گلدن‌اسکن

		$fc = new \stdClass();
		$content = STI_Content_Generator::build_full( $fake, $fc );
		$title = $content['title'] ?? $file_name;
		return array(
			'title' => $title, 'description' => $content['description'] ?? '',
			'focus_keyword' => '', 'slug' => sanitize_title( $title ),
			'meta_description' => wp_trim_words( $title, 20, '' ), 'validation' => array(),
		);
	}

	protected static function title_via_engine( $facts, $use_ai ) {
		if ( ! class_exists( 'STI_Title_Engine' ) ) {
			return array( 'title' => $facts['file_name'], 'focus_keyword' => '', 'slug' => sanitize_title( $facts['file_name'] ), 'validation' => array() );
		}
		/**
		 * کلمه‌ی نوع باید از **دسته** بیاید، نه از فرمت فایل.
		 *
		 * موتور عنوان `file_type` را به برچسب تبدیل می‌کند: PSD → «لایه‌باز».
		 * نتیجه‌اش این بود:
		 *
		 *     دانلود PSD لایه‌باز لوگوی عطر مشکی      ← فرمت
		 *     دانلود موکاپ لایه‌باز عطر مشکی          ← دسته (درست)
		 *
		 * فرمت می‌گوید فایل چطور باز می‌شود؛ دسته می‌گوید محصول **چیست**.
		 * برای عنوان فروشگاهی دومی مهم است.
		 *
		 * فرمت حذف نمی‌شود — همچنان به‌عنوان `file_type` فرستاده می‌شود و
		 * در توضیحات می‌آید. فقط دیگر کلمه‌ی اول عنوان نیست.
		 */
		$type_for_title = self::category_to_type_key( $facts['category'] );
		if ( '' === $type_for_title ) {
			$type_for_title = $facts['file_type'];   // بدون نگاشت، همان رفتار قبلی
		}

		$r = STI_Title_Engine::build( array(
			'file_name' => $facts['file_name'], 'file_type' => $type_for_title,
			'category'  => $facts['category'], 'software' => $facts['software'],
			'file_code' => $facts['file_code'] ?? '',
		), array( 'use_ai' => $use_ai ) );
		/**
		 * `??` فقط null را می‌گیرد، نه رشته‌ی خالی.
		 *
		 * وقتی موتور قاعده‌محور عنوان خالی برمی‌گرداند (واژه‌ای در واژه‌نامه
		 * پیدا نمی‌شود و strip_latin هم لاتین را دور می‌ریزد)، این خط رشته‌ی
		 * خالی را عبور می‌داد و Product Builder ناچار به Fallback می‌شد —
		 * بدون اینکه معلوم باشد چرا. با `?:` دست‌کم نام فایل جای عنوان می‌نشیند.
		 */
		/**
		 * ترتیب مهم است.
		 *
		 * Title Engine عنوان AI را فقط وقتی می‌پذیرد که validate() امتیاز
		 * ۷۰ یا بیشتر بدهد؛ وگرنه `title` را روی عنوان قاعده‌محور می‌گذارد —
		 * که برای موضوع‌های خارج از واژه‌نامه **خالی** است. نتیجه‌اش این بود
		 * که یک عنوان فارسی کاملاً قابل قبول دور ریخته می‌شد چون مثلاً
		 * کلمه‌ی «PSD» در آن بود (۲۰- امتیاز بابت لاتین).
		 *
		 * پس اگر عنوان قاعده‌محور خالی بود، عنوان AI بهتر از نام فایل
		 * انگلیسی است.
		 */
		$title = '';
		foreach ( array( $r['title'] ?? '', $r['ai_title'] ?? '', $r['rule_title'] ?? '' ) as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate ) {
				$title = $candidate;
				break;
			}
		}
		if ( '' === $title ) {
			$title = $facts['file_name'];
		}
		$validation = method_exists( 'STI_Title_Engine', 'validate' ) ? STI_Title_Engine::validate( $title ) : array();

		return array(
			'title'         => $title,
			'focus_keyword' => $r['focus_keyword'] ?? '',
			'slug'          => ! empty( $r['slug'] ) ? $r['slug'] : sanitize_title( $title ),
			'validation'    => $validation,
			// چرا موتور این عنوان را ساخت — بدون این، هر بار باید حدس بزنیم.
			'engine_debug'  => array(
				'rule_title'   => $r['rule_title'] ?? '',
				'ai_title'     => $r['ai_title'] ?? '',
				'source'       => $r['source'] ?? '',
				'score'        => $r['score'] ?? null,
				'issues'       => $r['issues'] ?? array(),
				'untranslated' => $r['untranslated'] ?? array(),
				'used_fallback'=> empty( $r['title'] ),
			),
		);
	}

	/** بدون AI، بدون شبکه — فقط از داده‌ی واقعی، متناسب با نوع فایل. */
	protected static function free_description( $facts ) {
		$lines = array();
		$subject = esc_html( $facts['file_name'] );
		switch ( $facts['content_type'] ) {
			case 'mockup':
				$lines[] = "یک موکاپ آماده برای نمایش طرح «{$subject}» — مناسب برای ارائه‌ی حرفه‌ای طرح به مشتری.";
				$lines[] = 'با جای‌گذاری طرح خودتان روی این موکاپ، نتیجه‌ی نهایی را واقعی و قابل‌ارائه نمایش دهید.';
				break;
			case 'logo':
				$lines[] = "فایل لوگوی «{$subject}» — مناسب برای استفاده در برندینگ و هویت بصری.";
				break;
			case 'photo':
				$lines[] = "تصویر «{$subject}» — قابل استفاده در طراحی، تبلیغات و شبکه‌های اجتماعی.";
				break;
			case 'font':
				$lines[] = "فونت «{$subject}» — مناسب برای تیتر یا متن، بسته به سبک طراحی شما.";
				break;
			default:
				$lines[] = "فایل «{$subject}» — آماده برای دانلود و استفاده در پروژه‌ی شما.";
		}
		$specs = array();
		if ( $facts['file_type'] ) { $specs[] = 'فرمت: ' . esc_html( $facts['file_type'] ); }
		if ( $facts['software'] ) { $specs[] = 'نرم‌افزار مورد نیاز: ' . esc_html( $facts['software'] ); }
		if ( $facts['filesize'] ) { $specs[] = 'حجم فایل: ' . esc_html( $facts['filesize'] ); }
		if ( $facts['file_code'] ) { $specs[] = 'کد فایل: ' . esc_html( $facts['file_code'] ); }

		$html = '<h2>معرفی فایل</h2><p>' . implode( ' ', $lines ) . '</p>';
		if ( $specs ) {
			$html .= '<h2>مشخصات فایل</h2><ul><li>' . implode( '</li><li>', $specs ) . '</li></ul>';
		}
		return $html;
	}

	/** پرامپت متناسب با نوع فایل؛ فقط از STI_AI::render_prompt()+json() استفاده می‌کند — بدون API Client جدید. */
	protected static function ai_description( $facts, $title ) {
		if ( ! class_exists( 'STI_AI' ) || ! STI_AI::is_ready() ) {
			return self::free_description( $facts ); // بدون AI موازی؛ fallback به همان موتور قطعی
		}
		$focus = self::type_focus_fa( $facts['content_type'] );
		// دسته به پرامپت داده می‌شود تا مدل بداند «موکاپ» است، نه فقط PSD.
		$facts['category'] = (string) ( $facts['category'] ?? '' );

		/**
		 * چرا توضیحات همیشه شکست می‌خورد.
		 *
		 * این تابع `STI_AI::json()` را صدا می‌زند که انتظار **یک شیء JSON**
		 * دارد و بعد `$res['description']` را می‌خواند. اما پرامپت قبلی از
		 * مدل **HTML خام** می‌خواست و اصلاً کلمه‌ی JSON یا نام کلید
		 * `description` در آن نبود.
		 *
		 * پس مدل HTML برمی‌گرداند → `parse_json()` شکست می‌خورد یا آرایه‌ای
		 * بدون کلید description می‌دهد → `empty($res['description'])` →
		 * fallback. هر بار. در تمام لاگ‌های ۰۷:۵۴ و ۱۷:۱۰ و ۲۲:۲۱ و ۲۲:۳۹.
		 *
		 * حالا صریحاً JSON با کلید مشخص خواسته می‌شود و HTML **داخل** همان
		 * کلید قرار می‌گیرد.
		 */
		/**
		 * قواعد نگارش، بر اساس نمونه‌هایی که اپراتور اصلاح کرد.
		 *
		 * خروجی قبلی: «معرفی فایل فایل PSD طراحی کاتالوگ محصولات خلاق یک
		 * موکاپ حرفه‌ای است…» — دو ایراد داشت: تکرار «فایل فایل» و شروع با
		 * نام فرمت به‌جای نوع محصول.
		 *
		 * نمونه‌ی درست: «موکاپ لایه‌باز طراحی کاتالوگ محصولات خلاق یک فایل
		 * حرفه‌ای با فرمت PSD است که…»
		 *
		 * یعنی جمله باید با **نوع محصول به فارسی** شروع شود، نه با فرمت.
		 */
		$template = "یک توضیح محصول فارسی برای فروشگاه فایل بنویس.\n"
			. "عنوان محصول: {title}\n"
			. "دسته: {category} | نوع: {content_type}\n"
			. "فرمت: {file_type} | نرم‌افزار: {software} | حجم: {filesize} | کد فایل: {file_code}\n\n"
			. "قواعد نگارش:\n"
			. "۱. جمله‌ی اول با **نوع محصول به فارسی** شروع شود (موکاپ، وکتور، لوگو، فونت…)، نه با «فایل» یا نام فرمت.\n"
			. "۲. کلمه‌ی «فایل» را پشت سر هم تکرار نکن. «فایل PSD» بنویس، نه «فایل فایل PSD».\n"
			. "۳. فرمت را طبیعی در جمله بیاور: «… یک فایل حرفه‌ای با فرمت PSD است که…».\n"
			. "۴. لحن بازاریابی فارسی روان باشد، نه ترجمه‌ی تحت‌اللفظی انگلیسی.\n"
			. "۵. تمرکز روی: {focus}.\n"
			. "۶. فقط از اطلاعات بالا استفاده کن. رزولوشن، تعداد صحنه، تعداد فایل یا قابل‌ویرایش بودن را اختراع نکن.\n"
			. "۷. در متن، عنوان بخش را تکرار نکن (بعد از تیتر «معرفی فایل» دوباره ننویس «معرفی فایل»).\n\n"
			. "پاسخ را **فقط** به‌صورت یک شیء JSON معتبر برگردان، بدون هیچ متن اضافه و بدون بلوک کد:\n"
			. '{"description": "<p>…</p><h2>ویژگی‌ها و کاربردها</h2><ul><li>…</li></ul>"}' . "\n"
			. "مقدار description باید HTML فارسی باشد و علامت‌های نقل‌قول داخلش escape شده باشند.";
		$prompt = STI_AI::render_prompt( $template, array(
			'title' => $title, 'content_type' => $facts['content_type'], 'focus' => $focus,
			'file_type' => $facts['file_type'], 'software' => $facts['software'],
			'filesize' => $facts['filesize'], 'file_code' => $facts['file_code'],
			'category' => $facts['category'],
		) );
		$res = STI_AI::json( $prompt );

		if ( is_wp_error( $res ) ) {
			STI_Logger::warning( 'GS Content Engine (sti_ai) description ناموفق، fallback به free: ' . $res->get_error_message() );
			return self::free_description( $facts );
		}

		// مدل‌های مختلف گاهی کلید را جور دیگری می‌نامند. به‌جای شکست، همان
		// را می‌پذیریم.
		foreach ( array( 'description', 'desc', 'content', 'html', 'text', 'body' ) as $key ) {
			if ( ! empty( $res[ $key ] ) && is_string( $res[ $key ] ) ) {
				return $res[ $key ];
			}
		}

		STI_Logger::warning( sprintf(
			'GS Content Engine (sti_ai) description ناموفق، fallback به free — کلیدهای پاسخ: %s (پروایدر: %s)',
			implode( ', ', array_slice( array_keys( (array) $res ), 0, 8 ) ) ?: 'هیچ',
			$res['_provider'] ?? '?'
		) );
		return self::free_description( $facts );
	}

	protected static function type_focus_fa( $type ) {
		$map = array(
			'mockup' => 'موضوع موکاپ، صحنه، کاربرد ارائه‌ی طرح',
			'logo'   => 'سبک، موضوع، کاربرد برندینگ',
			'photo'  => 'سوژه، فضای تصویر، کاربردهای احتمالی',
			'font'   => 'سبک فونت، کاربرد تایپوگرافی',
		);
		return $map[ $type ] ?? 'ویژگی‌ها و کاربردهای واقعی فایل';
	}

	public static function detect_content_type( $text, $file_type ) {
		$norm = mb_strtolower( (string) $text );
		foreach ( self::TYPE_KEYWORDS as $type => $words ) {
			foreach ( $words as $w ) {
				if ( false !== mb_strpos( $norm, mb_strtolower( $w ) ) ) { return $type; }
			}
		}
		return 'other';
	}

	protected static function resolve_file_type( $message ) {
		if ( $message && ! empty( $message['text_raw'] ) && class_exists( 'STI_Caption_Parser' ) ) {
			$p = STI_Caption_Parser::parse( $message['text_raw'] );
			if ( ! empty( $p['file_type'] ) ) { return (string) $p['file_type']; }
		}
		return '';
	}

	protected static function clean_subject( $session, $message ) {
		$name = '';
		if ( $message && ! empty( $message['text_raw'] ) && class_exists( 'STI_Caption_Parser' ) ) {
			$p = STI_Caption_Parser::parse( $message['text_raw'] );
			$name = (string) ( $p['file_name'] ?? '' );
		}
		if ( '' === $name && ! empty( $session['downloaded_path'] ) ) {
			$name = trim( preg_replace( '/[_\-]+/', ' ', pathinfo( $session['downloaded_path'], PATHINFO_FILENAME ) ) );
		}
		/**
		 * همان تله‌ی final_safety.
		 *
		 * `preg_replace` با مودیفایر `/u` روی رشته‌ی UTF-8 نامعتبر **null**
		 * برمی‌گرداند. کپشن تلگرام مرتباً بایت خراب دارد (همان هشدارهای
		 * مکرر Title Engine). نتیجه: موضوع عنوان خالی می‌شد و به
		 * «فایل ۱۲۳۴۵» سقوط می‌کرد — به همین دلیل عنوان‌ها عمومی و
		 * بی‌ربط به کپشن بودند.
		 */
		$name = self::clean_utf8( $name );

		$stripped = preg_replace( '/#\S+/u', '', $name );
		$name     = ( null === $stripped ) ? $name : $stripped;

		$collapsed = preg_replace( '/\s{2,}/u', ' ', $name );
		$name      = trim( ( null === $collapsed ) ? $name : $collapsed );

		return '' !== $name ? $name : ( 'فایل ' . ( $session['file_code'] ?? '' ) );
	}

	/**
	 * آخرین پاک‌سازی پیش از تحویل به Product Builder.
	 *
	 * اینجا بود که عنوان خوب AI نابود می‌شد: `preg_replace` با مودیفایر
	 * `/u` روی رشته‌ی UTF-8 نامعتبر مقدار **null** برمی‌گرداند، نه رشته.
	 * بعد `trim(null)` می‌شد رشته‌ی خالی و Builder ناچار به Fallback
	 * انگلیسی می‌شد — بدون هیچ خطایی.
	 *
	 * پس اول متن سالم می‌شود، بعد regex اجرا می‌شود، و خروجی هر مرحله
	 * در برابر null محافظت می‌شود.
	 */
	protected static function final_safety( $title ) {
		$title = self::clean_utf8( (string) $title );
		if ( '' === $title ) {
			return '';
		}

		$stripped = preg_replace( '/#\S+/u', '', $title );
		if ( null === $stripped ) {
			// regex شکست خورد؛ متن اصلی بهتر از هیچ است.
			$stripped = $title;
		}

		$collapsed = preg_replace( '/\s{2,}/u', ' ', $stripped );
		if ( null === $collapsed ) {
			$collapsed = $stripped;
		}

		return self::dedupe_prefix( trim( $collapsed ) );
	}

	/**
	 * «دانلود دانلود PSD ...» → «دانلود PSD ...»
	 *
	 * وقتی AI خودش عنوان را با پیشوند شروع می‌کند و enforce_prefix هم
	 * دوباره پیشوند می‌گذارد، کلمه تکرار می‌شود. validate() هم برای همین
	 * ۱۰ امتیاز کم می‌کند.
	 */
	protected static function dedupe_prefix( $title ) {
		$words = preg_split( '/\s+/u', $title );
		if ( ! is_array( $words ) || count( $words ) < 2 ) {
			return $title;
		}
		$out = array();
		foreach ( $words as $w ) {
			$prev = $out ? end( $out ) : '';

			if ( '' !== $prev && $prev === $w ) {
				continue; // تکرار عیناً یکسان
			}

			/**
			 * تکرار «تقریبی» هم باید حذف شود.
			 *
			 * وقتی یک بایت از ابتدای کلمه خراب و حذف می‌شود،
			 * «دانلود دانلود» تبدیل می‌شود به «دانلود انلود» — دو کلمه‌ی
			 * متفاوت که مقایسه‌ی ساده نمی‌گیردشان. اگر یکی پسوند دیگری باشد
			 * و اختلاف طول کم باشد، همان کلمه‌ی بریده است.
			 */
			if ( '' !== $prev && $w !== $prev ) {
				$short = mb_strlen( $w ) < mb_strlen( $prev ) ? $w : $prev;
				$long  = mb_strlen( $w ) < mb_strlen( $prev ) ? $prev : $w;
				$diff  = mb_strlen( $long ) - mb_strlen( $short );

				if ( $diff > 0 && $diff <= 2 && mb_strlen( $short ) >= 3
					&& mb_substr( $long, -mb_strlen( $short ) ) === $short ) {
					if ( $short === $w ) {
						continue;              // کلمه‌ی بریده را دور می‌ریزیم
					}
					array_pop( $out );          // نسخه‌ی بریده قبلاً ثبت شده بود
				}
			}

			$out[] = $w;
		}
		return implode( ' ', $out );
	}
}
