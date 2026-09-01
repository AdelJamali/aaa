<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Builds the WooCommerce product title + description.
 *
 * Default mode is 100% free and needs no API key:
 *  - the file-type is mapped to a natural Persian label ("VECTOR" -> "وکتور")
 *  - the reference page (hyperlink on the file name in the Telegram caption)
 *    is fetched and a clean excerpt is pulled from it, with guards against
 *    picking up bot-protection / error pages by mistake
 *  - a static, per-file-type table of compatible software is merged in
 *    ("PSD" -> "Adobe Photoshop"), again with no API calls
 *  - file size is included when available
 *
 * An optional AI API (e.g. a free Google Gemini key) can be enabled in
 * settings for a fully rewritten, translated, SEO-oriented title+description;
 * on any failure it automatically falls back to the free template above.
 */
class STI_Content_Generator {

	/**
	 * File-type code (as typed in the Telegram caption, e.g. "#PSD") -> natural Persian label.
	 * Used in the auto-generated product title and description.
	 */
	protected static $type_labels = array(
		'PSD'      => 'فایل لایه‌باز',
		'VECTOR'   => 'وکتور',
		'AI'       => 'وکتور (Illustrator)',
		'EPS'      => 'وکتور (EPS)',
		'MOCKUP'   => 'موکاپ',
		'FONT'     => 'فونت',
		'ICON'     => 'آیکون',
		'PATTERN'  => 'پترن',
		'TEMPLATE' => 'قالب',
		'TEXTURE'  => 'تکسچر',
		'MOTION'   => 'فایل موشن',
		'3D'       => 'فایل سه‌بعدی',
		'PHOTO'    => 'عکس',
		'JPG'      => 'عکس',
		'JPEG'     => 'عکس',
		'PNG'      => 'عکس',
	);

	/**
	 * File-type -> compatible software, so the description can honestly say
	 * "usable in Photoshop/Illustrator/..." without needing AI or the source
	 * page to spell it out.
	 */
	protected static $type_software = array(
		'PSD'      => 'Adobe Photoshop',
		'VECTOR'   => 'Adobe Illustrator، CorelDRAW، Inkscape',
		'AI'       => 'Adobe Illustrator',
		'EPS'      => 'Adobe Illustrator، CorelDRAW',
		'MOCKUP'   => 'Adobe Photoshop (فایل لایه‌باز PSD)',
		'FONT'     => 'هر نرم‌افزار طراحی و صفحه‌آرایی مثل Photoshop، Illustrator، InDesign و Word',
		'ICON'     => 'Adobe Illustrator، Figma، Sketch، Photoshop',
		'PATTERN'  => 'Adobe Photoshop، Illustrator',
		'TEMPLATE' => 'بسته به فرمت فایل (Photoshop/Illustrator/PowerPoint)',
		'TEXTURE'  => 'Adobe Photoshop، Blender، نرم‌افزارهای رندر سه‌بعدی',
		'MOTION'   => 'Adobe After Effects، Premiere Pro',
		'3D'       => 'Blender، Cinema 4D، 3ds Max',
		'PHOTO'    => '',
		'JPG'      => '',
		'JPEG'     => '',
		'PNG'      => '',
	);

	/**
	 * File-type -> the actual file extension/format (distinct from %type%,
	 * which is the friendly Persian label). E.g. "VECTOR" -> "EPS".
	 */
	protected static $type_format = array(
		'PSD'      => 'PSD',
		'VECTOR'   => 'EPS',
		'AI'       => 'AI',
		'EPS'      => 'EPS',
		'MOCKUP'   => 'PSD',
		'FONT'     => 'TTF / OTF',
		'ICON'     => 'AI / SVG',
		'PATTERN'  => 'EPS',
		'TEMPLATE' => 'PSD / AI',
		'TEXTURE'  => 'JPG',
		'MOTION'   => 'MP4 / MOV',
		'3D'       => 'OBJ / FBX',
		'PHOTO'    => 'JPG',
		'JPG'      => 'JPG',
		'JPEG'     => 'JPEG',
		'PNG'      => 'PNG',
	);

	/**
	 * Phrases that indicate the fetched page was actually a bot-protection /
	 * error / access-denied page, not real product content. If any of these
	 * show up in what we scraped, we discard it rather than publishing it.
	 */
	protected static $blocked_page_signatures = array(
		'security filter', 'did not go through', "didn't go through", 'access denied',
		'attention required', 'just a moment', 'captcha', 'cloudflare', 'are you human',
		'403 forbidden', 'bot detection', 'permission to access', 'blocked',
		'بررسی امنیتی', 'دسترسی غیرمجاز', 'شما اجازه دسترسی',
	);

	/**
	 * @return array{title:string, description:string}
	 */
	public static function build_full( $session, $category ) {
		$excerpt = '';
		if ( ! empty( $session->source_url ) && STI_Settings::get( 'auto_scrape_excerpt', 1 ) ) {
			$excerpt = self::scrape_excerpt( $session->source_url );
		}

		if ( 'api' === STI_Settings::get( 'content_mode' ) ) {
			$ai = self::try_ai_rewrite( $session, $excerpt );
			if ( $ai && ! empty( $ai['description'] ) ) {
				return array(
					'title'       => ! empty( $ai['title'] ) ? $ai['title'] : self::build_title( $session ),
					'description' => $ai['description'],
				);
			}
		}

		$translated_name = self::translate_to_persian( $session->file_name );

		return array(
			'title'       => self::build_title( $session, $translated_name ),
			'description' => self::render_template( $session, $category, $excerpt, $translated_name ),
		);
	}

	/**
	 * Kept for backward compatibility with any code calling build() directly for description only.
	 */
	public static function build( $session, $category ) {
		$full = self::build_full( $session, $category );
		return $full['description'];
	}

	protected static function type_label( $type ) {
		$type = strtoupper( trim( (string) $type ) );
		return self::$type_labels[ $type ] ?? ( $type ?: 'فایل' );
	}

	protected static function type_software( $type ) {
		$type = strtoupper( trim( (string) $type ) );
		return self::$type_software[ $type ] ?? '';
	}

	protected static function type_format( $type ) {
		$type = strtoupper( trim( (string) $type ) );
		return self::$type_format[ $type ] ?? $type;
	}

	/* ---------- public wrappers (reused by STI_Product_Attributes, avoids duplicating the mapping tables) ---------- */

	public static function type_software_public( $type ) {
		return self::type_software( $type );
	}

	public static function type_format_public( $type ) {
		return self::type_format( $type );
	}

	public static function jalali_today_public() {
		return self::jalali_today();
	}

	protected static function render_template( $session, $category, $excerpt, $translated_name = null ) {
		$template = ! empty( $category->description_template )
			? $category->description_template
			: STI_Settings::get( 'default_template' );

		$software = self::type_software( $session->file_type );

		$replacements = array(
			'%name%'       => $translated_name ?: ( $session->file_name ?: '' ),
			'%latin_name%' => $session->file_name ?: '',
			'%type%'       => self::type_label( $session->file_type ),
			'%format%'     => self::type_format( $session->file_type ),
			'%code%'       => $session->file_code ?: '',
			'%excerpt%'    => $excerpt,
			'%software%'   => $software,
			'%filesize%'   => self::format_filesize( $session->file_size_bytes ?? null ),
			'%dimensions%' => $session->dimensions ?? '',
			'%resolution%' => $session->resolution ?? '',
			'%color%'      => $session->color ?? '',
			'%jalali_date%' => self::jalali_today(),
		);

		$text = strtr( $template, $replacements );
		$text = self::strip_empty_spec_lines( $text );
		$text = trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
		return $text;
	}

	/**
	 * Category templates often look like "برچسب: %value%" for optional specs
	 * (dimensions/resolution/color/...). If the underlying value wasn't
	 * available for this particular file, strip that whole line rather than
	 * publishing "رزولوشن: " with nothing after the colon.
	 */
	protected static function strip_empty_spec_lines( $text ) {
		$lines = explode( "\n", $text );
		$kept = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*\S.{0,30}[:：]\s*$/u', $line ) ) {
				continue; // "Label:" with nothing after it -> drop the line.
			}
			$kept[] = $line;
		}
		return implode( "\n", $kept );
	}

	/**
	 * @param object      $session
	 * @param string|null $translated_name Pre-translated name, if already fetched by the caller (avoids a second API call).
	 */
	/**
	 * ساخت عنوان طبیعی و سئوپسند فارسی.
	 *
	 * الگوی هدف:
	 *   دانلود {نوع/دسته} {شرح شیء} {پسوند کیفیت اختیاری}
	 * مثال‌ها:
	 *   دانلود موکاپ لایه باز لیوان کاغذی
	 *   دانلود وکتور لوگو مینیمال طلایی
	 *   دانلود فایل لایه‌باز پوستر جشن
	 *
	 * مشکل قبلی: ترجمه‌ی خام + باقی‌ماندن هشتگ (#mockup → #مکاپ) + ترتیب غلط کلمات.
	 */
	public static function build_title( $session, $translated_name = null ) {
		/*
		 * v7 — عنوان‌سازی به STI_Title_Engine منتقل شد (قانون‌ها + AI + داوری).
		 * منطق قدیمی پایین دست‌نخورده مانده و اگر موتور تازه نبود، همان اجرا می‌شود.
		 */
		if ( class_exists( 'STI_Title_Engine' ) && null === $translated_name ) {
			$cat_label = '';
			if ( ! empty( $session->category_id ) ) {
				$cat = STI_Category::get( (int) $session->category_id );
				if ( $cat ) { $cat_label = (string) $cat->telegram_label; }
			}
			$built = STI_Title_Engine::build( array(
				'file_name' => (string) ( $session->file_name ?? '' ),
				'file_type' => (string) ( $session->file_type ?? '' ),
				'file_code' => (string) ( $session->file_code ?? '' ),
				'category'  => $cat_label,
				'software'  => self::type_software( $session->file_type ?? '' ),
			) );
			if ( ! empty( $built['title'] ) ) {
				return $built['title'];
			}
		}
		$raw_name  = trim( (string) ( $session->file_name ?? '' ) );
		$file_type = strtoupper( trim( (string) ( $session->file_type ?? '' ) ) );
		$file_type = ltrim( $file_type, '#' );

		// ۱) نرمال‌سازی نام: حذف هشتگ، جداکننده‌ها، کلمات زائد انگلیسی
		$clean = self::normalize_file_name_for_title( $raw_name );

		// ۲) تشخیص نوع/دسته از روی نام + file_type
		$type_label = self::detect_title_type_label( $clean, $file_type, $session );

		// ۳) حذف کلمات نوع از متن تا در عنوان تکرار نشوند
		$object_en = self::strip_type_keywords( $clean );

		// ۴) ترجمه یا استفاده از متن فارسی موجود
		if ( null === $translated_name ) {
			$object_fa = self::translate_to_persian( $object_en );
		} else {
			$object_fa = $translated_name;
		}
		$object_fa = self::polish_persian_title_phrase( $object_fa ?: $object_en );

		// ۵) پسوند کیفیت (لایه باز و …) فقط وقتی معنا دارد
		$quality = self::title_quality_suffix( $file_type, $type_label, $raw_name );

		// ۶) مونتاژ نهایی: دانلود + نوع + کیفیت + شرح
		$parts = array( 'دانلود' );
		if ( $type_label ) {
			$parts[] = $type_label;
		}
		if ( $quality && false === mb_strpos( implode( ' ', $parts ), $quality ) ) {
			$parts[] = $quality;
		}
		if ( $object_fa ) {
			// جلوگیری از تکرار نوع داخل شرح
			$obj_clean = $object_fa;
			foreach ( array( $type_label, $quality ) as $dup ) {
				if ( $dup ) {
					$obj_clean = preg_replace( '/\b' . preg_quote( $dup, '/' ) . '\b/u', '', $obj_clean );
				}
			}
			$obj_clean = trim( preg_replace( '/\s+/u', ' ', $obj_clean ) );
			if ( $obj_clean ) {
				$parts[] = $obj_clean;
			}
		}

		$title = trim( preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) ) );
		$title = self::finalize_title( $title );

		return $title ?: ( 'دانلود فایل گرافیکی ' . ( $raw_name ?: 'بدون عنوان' ) );
	}

	/** حذف #هشتگ، underline، کلمات بی‌معنی از نام فایل. */
	public static function normalize_file_name_for_title( $name ) {
		$name = html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// هشتگ‌ها را به فاصله تبدیل کن (محتوا بماند، علامت # حذف)
		$name = preg_replace( '/#(\S+)/u', '$1', $name );
		$name = str_replace( array( '_', '-', '.', '/', '\\' ), ' ', $name );
		$name = preg_replace( '/\s+/u', ' ', $name );
		// پسوند فایل را حذف کن
		$name = preg_replace( '/\b(psd|png|jpg|jpeg|ai|eps|svg|pdf|zip|rar|7z)\b/iu', '', $name );
		// کلمات کاملاً بی‌فایده
		$noise = array( 'free', 'download', 'premium', 'stock', 'file', 'files', 'hd', 'hq', 'new', 'best', 'editable' );
		$words = preg_split( '/\s+/u', mb_strtolower( trim( $name ) ) );
		$out = array();
		foreach ( $words as $w ) {
			$w = trim( $w );
			if ( '' === $w || in_array( $w, $noise, true ) ) {
				continue;
			}
			// حذف اعداد خیلی بلند (کد فایل)
			if ( preg_match( '/^\d{6,}$/', $w ) ) {
				continue;
			}
			$out[] = $w;
		}
		return trim( implode( ' ', $out ) );
	}

	/** تشخیص برچسب نوع فارسی برای عنوان. */
	protected static function detect_title_type_label( $clean_name, $file_type, $session = null ) {
		$hay = mb_strtolower( $clean_name . ' ' . $file_type );

		$map = array(
			'mockup'       => 'موکاپ',
			'mock up'      => 'موکاپ',
			'logo'         => 'لوگو',
			'logotype'     => 'لوگو',
			'business card'=> 'کارت ویزیت',
			'name card'    => 'کارت ویزیت',
			'flyer'        => 'فلایر',
			'brochure'     => 'بروشور',
			'poster'       => 'پوستر',
			'banner'       => 'بنر',
			'infographic'  => 'اینفوگرافیک',
			'texture'      => 'تکسچر',
			'pattern'      => 'پترن',
			'sticker'      => 'استیکر',
			'icon'         => 'آیکون',
			'illustration' => 'ایلاستریشن',
			'typography'   => 'تایپوگرافی',
			'text effect'  => 'افکت متن',
			'background'   => 'بک‌گراند',
			'template'     => 'قالب',
			'packaging'    => 'بسته‌بندی',
			'certificate'  => 'گواهینامه',
			'resume'       => 'رزومه',
			'cv'           => 'رزومه',
			'mascot'       => 'مسکات',
			'flag'         => 'پرچم',
		);
		foreach ( $map as $en => $fa ) {
			if ( false !== mb_strpos( $hay, $en ) ) {
				return $fa;
			}
		}

		// از file_type جدول
		$from_type = self::type_label( $file_type );
		if ( $from_type && 'فایل' !== $from_type ) {
			return $from_type;
		}

		// از دسته session
		if ( $session && ! empty( $session->category_id ) ) {
			$cat = STI_Category::get( (int) $session->category_id );
			if ( $cat && ! empty( $cat->telegram_label ) ) {
				$label = trim( $cat->telegram_label );
				// اگر برچسب فارسی کوتاه است استفاده کن
				if ( preg_match( '/\p{Arabic}/u', $label ) && mb_strlen( $label ) <= 30 ) {
					return $label;
				}
			}
		}

		return $from_type ?: 'فایل گرافیکی';
	}

	/** حذف کلمات نوع از متن انگلیسی تا در عنوان تکرار نشود. */
	protected static function strip_type_keywords( $text ) {
		$keywords = array(
			'mockup', 'mock up', 'mock-up', 'psd mockup', 'logo', 'logotype', 'brand mark',
			'business card', 'name card', 'visiting card', 'flyer', 'brochure', 'poster',
			'banner', 'infographic', 'texture', 'pattern', 'sticker', 'icon', 'illustration',
			'typography', 'text effect', 'background', 'template', 'packaging', 'certificate',
			'resume', 'cv', 'mascot', 'flag', 'flags', 'vector', 'psd', 'png', 'isolated',
			'transparent', 'clipart', 'editable',
		);
		$out = ' ' . mb_strtolower( $text ) . ' ';
		foreach ( $keywords as $kw ) {
			$out = str_replace( ' ' . $kw . ' ', ' ', $out );
		}
		return trim( preg_replace( '/\s+/u', ' ', $out ) );
	}

	/** پسوند کیفیت مثل «لایه باز» فقط وقتی مناسب است. */
	protected static function title_quality_suffix( $file_type, $type_label, $raw_name ) {
		$ft = strtoupper( (string) $file_type );
		$hay = mb_strtolower( $raw_name . ' ' . $ft );
		// موکاپ و PSD معمولاً لایه‌باز هستند
		if ( in_array( $ft, array( 'PSD', 'MOCKUP' ), true )
			|| false !== mb_strpos( $hay, 'mockup' )
			|| false !== mb_strpos( $hay, 'psd' )
			|| 'موکاپ' === $type_label ) {
			return 'لایه باز';
		}
		if ( in_array( $ft, array( 'VECTOR', 'AI', 'EPS', 'SVG' ), true )
			|| false !== mb_strpos( $hay, 'vector' ) ) {
			return ''; // نوع «وکتور» خودش کافی است
		}
		return '';
	}

	/** پاک‌سازی عبارت فارسی ترجمه‌شده. */
	public static function polish_persian_title_phrase( $text ) {
		$text = (string) $text;
		// حذف باقی‌مانده هشتگ و کاراکترهای عجیب
		$text = preg_replace( '/[#_\/\\\\]+/u', ' ', $text );
		$text = self::clean_translation_artifacts( $text );

		// اصلاح ترجمه‌های رایج غلط/رباتی
		$replacements = array(
			'مکاپ'           => 'موکاپ',
			'موک آپ'         => 'موکاپ',
			'لایه باز شده'   => 'لایه باز',
			'قابل ویرایش'    => '',
			'دانلود رایگان'  => '',
			'فایل رایگان'    => '',
			'بدون پس زمینه'  => 'بدون پس‌زمینه',
			'پس زمینه سفید'  => 'پس‌زمینه سفید',
			'کارت کسب و کار' => 'کارت ویزیت',
			'کارت تجاری'     => 'کارت ویزیت',
			'کارت نام'       => 'کارت ویزیت',
		);
		foreach ( $replacements as $bad => $good ) {
			$text = str_replace( $bad, $good, $text );
		}

		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		// حذف تکرار پشت‌سرهم
		$words = preg_split( '/\s+/u', $text );
		$clean = array();
		foreach ( $words as $i => $w ) {
			if ( $i > 0 && $w === $words[ $i - 1 ] ) {
				continue;
			}
			$clean[] = $w;
		}
		return trim( implode( ' ', $clean ) );
	}

	/** نرمال نهایی عنوان. */
	protected static function finalize_title( $title ) {
		$title = trim( preg_replace( '/\s+/u', ' ', $title ) );
		// حذف «دانلود دانلود»
		$title = preg_replace( '/^(دانلود\s+)+/u', 'دانلود ', $title );
		// محدودیت طول منطقی برای سئو
		if ( mb_strlen( $title ) > 120 ) {
			$title = mb_substr( $title, 0, 117 ) . '…';
		}
		return $title;
	}

	/**
	 * پیشنهاد عنوان هوشمند برای محصول موجود (از روی عنوان فعلی یا نام فایل).
	 *
	 * @return array{suggested:string, issues:string[], score:int}
	 */
	public static function suggest_title_fix( $current_title, $file_name = '', $file_type = '', $category_label = '', $use_ai = false ) {
		// v7 — داوری و پیشنهاد از موتور تازه
		if ( class_exists( 'STI_Title_Engine' ) ) {
			$audit = STI_Title_Engine::audit( $current_title );
			$built = STI_Title_Engine::build( array(
				'file_name'     => $file_name,
				'file_type'     => $file_type,
				'current_title' => $current_title,
				'category'      => $category_label,
			), array( 'use_ai' => (bool) $use_ai ) );
			return array(
				'suggested'   => $built['title'],
				'issues'      => $audit['issues'],
				'score'       => $audit['score'],
				'needs_fix'   => ( $audit['score'] < 85 && $built['title'] !== $current_title ),
				'source'      => $built['source'] ?? 'rules',
				'description' => '',
			);
		}
		$issues = array();
		$current_title = trim( (string) $current_title );
		$file_name = trim( (string) $file_name );
		$file_type = trim( (string) $file_type );

		if ( preg_match( '/#/u', $current_title ) ) {
			$issues[] = 'دارای هشتگ';
		}
		if ( preg_match( '/مکاپ/u', $current_title ) ) {
			$issues[] = 'ترجمه غلط «مکاپ» به‌جای «موکاپ»';
		}
		if ( ! preg_match( '/^دانلود\s/u', $current_title ) ) {
			$issues[] = 'با «دانلود» شروع نمی‌شود';
		}
		if ( preg_match( '/\b(mockup|logo|vector|psd|flyer|banner|template|png)\b/iu', $current_title ) ) {
			$issues[] = 'کلمات انگلیسی باقی مانده';
		}
		if ( preg_match( '/(لیوان|بطری|جعبه|کیف|تیشرت|گوشی|ماگ).{0,25}(موکاپ|مکاپ)/u', $current_title ) ) {
			$issues[] = 'ترتیب کلمات غیرطبیعی';
		}
		if ( preg_match( '/\d{6,}/', $current_title ) ) {
			$issues[] = 'کد عددی داخل عنوان';
		}
		if ( mb_strlen( $current_title ) < 12 ) {
			$issues[] = 'عنوان خیلی کوتاه';
		}

		$fake_session = (object) array(
			'file_name'   => $file_name ?: preg_replace( '/^دانلود\s+/u', '', $current_title ),
			'file_type'   => $file_type,
			'category_id' => 0,
		);
		$suggested = self::build_title( $fake_session );
		$source = 'rules';
		$ai_desc = null;

		if ( $category_label && false === mb_strpos( $suggested, $category_label ) ) {
			if ( preg_match( '/دانلود\s+فایل/u', $suggested ) ) {
				$suggested = preg_replace( '/دانلود\s+فایل(\s+گرافیکی)?/u', 'دانلود ' . $category_label, $suggested, 1 );
			}
		}

		if ( $use_ai ) {
			$ai = self::ai_improve_title_and_description( $current_title, $file_name, $file_type, $category_label );
			if ( is_array( $ai ) && ! empty( $ai['title'] ) ) {
				$suggested = self::finalize_title( $ai['title'] );
				$source = 'ai';
				if ( ! empty( $ai['description'] ) ) {
					$ai_desc = $ai['description'];
				}
			}
		}

		$score = 100 - ( count( $issues ) * 15 );
		if ( $suggested === $current_title ) {
			$score = max( $score, 70 );
		}
		if ( 'ai' === $source ) {
			$score = min( 100, $score + 10 );
		}

		$out = array(
			'suggested'  => $suggested,
			'issues'     => $issues,
			'score'      => max( 0, min( 100, $score ) ),
			'needs_fix' => ( count( $issues ) > 0 ) || ( $suggested !== $current_title ),
			'source'     => $source,
		);
		if ( $ai_desc ) {
			$out['description'] = $ai_desc;
		}
		return $out;
	}

	/**
	 * Free, keyless automatic translation of the (usually English) file name
	 * into natural Persian, using Google Translate's public translation
	 * endpoint. No API key or account needed. Returns null on any failure so
	 * callers can fall back gracefully to the original text.
	 */
	protected static function translate_to_persian( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		// Already looks like Persian/Arabic script — no translation needed.
		if ( preg_match( '/\p{Arabic}/u', $text ) ) {
			return $text;
		}

		$url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=fa&dt=t&q=' . rawurlencode( $text );
		$response = wp_remote_get( $url, array( 'timeout' => 6 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			STI_Logger::warning( 'ترجمه‌ی خودکار عنوان ناموفق بود، از متن اصلی استفاده می‌شود.' );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body[0] ) || ! is_array( $body[0] ) ) {
			return null;
		}

		$translated = '';
		foreach ( $body[0] as $segment ) {
			if ( ! empty( $segment[0] ) ) {
				$translated .= $segment[0];
			}
		}

		$translated = trim( $translated );
		if ( ! $translated ) {
			return null;
		}

		$translated = self::clean_translation_artifacts( $translated );
		return $translated ?: null;
	}

	/**
	 * Raw machine translation of short phrases sometimes produces artifacts
	 * like an immediately repeated word ("مدرن و مدرن"). Strip those out.
	 */
	protected static function clean_translation_artifacts( $text ) {
		// Collapse "X و X" (word AND same-word) artifacts from raw MT.
		$text = preg_replace( '/\b(\S+)\s+و\s+\1\b/u', '$1', $text );

		$words = preg_split( '/\s+/u', $text );
		$clean = array();
		foreach ( $words as $i => $word ) {
			if ( $i > 0 && $word === $words[ $i - 1 ] ) {
				continue; // skip immediate duplicate
			}
			$clean[] = $word;
		}
		return trim( implode( ' ', $clean ) );
	}

	protected static function format_filesize( $bytes ) {
		if ( empty( $bytes ) ) {
			return '';
		}
		return size_format( (int) $bytes, 2 );
	}

	/**
	 * Today's date converted to the Persian (Jalali/Shamsi) calendar, e.g. "1403/07/03".
	 * No external library/API — a self-contained standard Gregorian→Jalali algorithm.
	 */
	protected static function jalali_today() {
		$g_y = (int) current_time( 'Y' );
		$g_m = (int) current_time( 'n' );
		$g_d = (int) current_time( 'j' );

		$g_days_in_month = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$gy = $g_y - 1600;
		$gm = $g_m - 1;
		$gd = $g_d - 1;

		$g_day_no = 365 * $gy + (int) ( ( $gy + 3 ) / 4 ) - (int) ( ( $gy + 99 ) / 100 ) + (int) ( ( $gy + 399 ) / 400 );
		for ( $i = 0; $i < $gm; ++$i ) {
			$g_day_no += $g_days_in_month[ $i ];
		}
		if ( $gm > 1 && ( ( $g_y % 4 === 0 && $g_y % 100 !== 0 ) || ( $g_y % 400 === 0 ) ) ) {
			$g_day_no++;
		}
		$g_day_no += $gd;

		$j_day_no = $g_day_no - 79;
		$j_np = (int) ( $j_day_no / 12053 );
		$j_day_no %= 12053;
		$jy = 979 + 33 * $j_np + 4 * (int) ( $j_day_no / 1461 );
		$j_day_no %= 1461;

		if ( $j_day_no >= 366 ) {
			$jy += (int) ( ( $j_day_no - 1 ) / 365 );
			$j_day_no = ( $j_day_no - 1 ) % 365;
		}

		$j_days_in_month = array( 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 );
		for ( $i = 0; $i < 11 && $j_day_no >= $j_days_in_month[ $i ]; ++$i ) {
			$j_day_no -= $j_days_in_month[ $i ];
		}
		$jm = $i + 1;
		$jd = $j_day_no + 1;

		return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
	}

	/**
	 * Fetches the reference product page and extracts a short, clean excerpt
	 * (meta description or a real paragraph). Pure server-side scraping, no
	 * external API, free — with guards against bot-protection/error pages.
	 */
	protected static function scrape_excerpt( $url ) {
		$url = STI_Security::validate_remote_url( $url, 'scrape' );
		if ( is_wp_error( $url ) ) { return ''; }
		$response = wp_remote_get( $url, array(
			'timeout'     => 8,
			'redirection' => 0,
			'user-agent'  => 'Mozilla/5.0 (compatible; SanilTelegramImporter/2.1; +https://wordpress.org)',
		) );
		if ( is_wp_error( $response ) ) {
			STI_Logger::warning( 'واکشی صفحه مرجع ناموفق بود: ' . $response->get_error_message() );
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			STI_Logger::warning( "واکشی صفحه مرجع HTTP {$code} برگرداند؛ از آن صرف‌نظر شد (احتمالاً فیلتر ربات یا صفحه‌ی امنیتی)." );
			return '';
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return '';
		}

		$excerpt = '';

		// 1) meta description
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$excerpt = $m[1];
		}

		// 2) og:description as fallback
		if ( empty( $excerpt ) && preg_match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$excerpt = $m[1];
		}

		if ( self::looks_like_blocked_page( $excerpt ) ) {
			$excerpt = '';
		}

		// 3) fall back to scanning the first several real paragraphs, skipping short/boilerplate ones.
		if ( empty( $excerpt ) ) {
			$excerpt = self::extract_best_paragraph( $html );
		}

		if ( self::looks_like_blocked_page( $excerpt ) ) {
			return ''; // never publish a bot-protection/error page as product content.
		}

		$excerpt = html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' );
		$excerpt = trim( preg_replace( '/\s+/u', ' ', $excerpt ) );

		if ( mb_strlen( $excerpt ) > 400 ) {
			$excerpt = mb_substr( $excerpt, 0, 400 ) . '...';
		}

		return $excerpt;
	}

	/**
	 * Scans the first handful of <p> tags and picks the first one that reads
	 * like real content (long enough, not boilerplate/navigation text).
	 */
	protected static function extract_best_paragraph( $html ) {
		if ( ! preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $html, $matches ) ) {
			return '';
		}
		foreach ( array_slice( $matches[1], 0, 8 ) as $raw ) {
			$text = trim( wp_strip_all_tags( $raw ) );
			if ( mb_strlen( $text ) < 40 ) {
				continue; // too short to be real content (nav links, labels, etc.)
			}
			if ( self::looks_like_blocked_page( $text ) ) {
				continue;
			}
			return $text;
		}
		return '';
	}

	protected static function looks_like_blocked_page( $text ) {
		if ( empty( $text ) ) {
			return false;
		}
		$lower = mb_strtolower( $text );
		foreach ( self::$blocked_page_signatures as $signature ) {
			if ( false !== mb_strpos( $lower, mb_strtolower( $signature ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Optional: call a configured AI endpoint (OpenAI-compatible schema, e.g.
	 * a free Google Gemini API key) to produce a translated Persian title and
	 * a rich, SEO-oriented description (format, typical use cases, compatible
	 * software, tags). Only used if the admin explicitly enables "api" content
	 * mode — otherwise never called (keeps the plugin 100% free by default).
	 *
	 * Tries the AI profile(s) selected by the configured rotation strategy
	 * (manual / time-based / round-robin — see STI_Settings::get_ai_rotation_order())
	 * in order, moving on to the next registered profile if one fails (wrong/
	 * exhausted key, quota, network) — this is what actually works around a
	 * single API key's token limit. Only once every candidate has failed does
	 * this return false, so the caller falls back to the free template.
	 *
	 * Returns array{title, description}|false.
	 */
	protected static function try_ai_rewrite( $session, $excerpt ) {
		// v7 — همه‌ی تماس‌های AI از هسته‌ی مرکزی رد می‌شوند (زنجیره، پراکسی، کش، آمار)
		if ( class_exists( 'STI_AI' ) && STI_AI::is_ready() ) {
			$cat_label = '';
			if ( ! empty( $session->category_id ) ) {
				$cat = STI_Category::get( (int) $session->category_id );
				if ( $cat ) { $cat_label = (string) $cat->telegram_label; }
			}
			$title = class_exists( 'STI_Title_Engine' )
				? STI_Title_Engine::build( array(
					'file_name' => (string) ( $session->file_name ?? '' ),
					'file_type' => (string) ( $session->file_type ?? '' ),
					'file_code' => (string) ( $session->file_code ?? '' ),
					'category'  => $cat_label,
				) )
				: array( 'title' => (string) ( $session->file_name ?? '' ) );

			$prompt = STI_AI::render_prompt( STI_AI::prompt( 'description' ), array(
				'title'     => isset( $title['title'] ) ? $title['title'] : '',
				'file_name' => (string) ( $session->file_name ?? '' ),
				'file_type' => (string) ( $session->file_type ?? '' ),
				'category'  => $cat_label,
				'software'  => self::type_software( $session->file_type ?? '' ),
				'filesize'  => self::format_filesize( $session->file_size_bytes ?? null ),
				'file_code' => (string) ( $session->file_code ?? '' ),
				'excerpt'   => mb_substr( (string) $excerpt, 0, 1200 ),
			) );
			$res = STI_AI::json( $prompt, array(
				'cache_key'  => 'desc|' . mb_strtolower( (string) ( $session->file_name ?? '' ) . '|' . (string) ( $session->file_type ?? '' ) ),
				'max_tokens' => 1600,
			) );
			if ( ! is_wp_error( $res ) && ! empty( $res['description'] ) ) {
				return array(
					'title'       => isset( $title['title'] ) ? $title['title'] : '',
					'description' => wp_kses_post( (string) $res['description'] ),
					'meta_title'  => sanitize_text_field( (string) ( $res['meta_title'] ?? '' ) ),
					'meta_description' => sanitize_text_field( (string) ( $res['meta_description'] ?? '' ) ),
				);
			}
		}
		$profiles = STI_Settings::get_ai_rotation_order();
		if ( empty( $profiles ) ) {
			return false;
		}

		foreach ( $profiles as $profile ) {
			$result = self::call_ai_profile( $profile, $session, $excerpt );
			if ( $result ) {
				return $result;
			}
		}

		STI_Logger::warning( 'همه‌ی API های هوش مصنوعی ثبت‌شده ناموفق بودند، بازگشت به حالت قالب رایگان.' );
		return false;
	}

	/** Calls a single AI profile ({name, endpoint, api_key, model}); returns array{title,description}|false. */
	protected static function call_ai_profile( $profile, $session, $excerpt ) {
		$endpoint = $profile['endpoint'] ?? '';
		$key      = $profile['api_key'] ?? '';
		$model    = $profile['model'] ?: 'gemini-2.5-flash';
		$label    = $profile['name'] ?: $endpoint;
		if ( empty( $endpoint ) || empty( $key ) ) {
			return false;
		}

		$software = self::type_software( $session->file_type );
		$filesize = self::format_filesize( $session->file_size_bytes ?? null );

		$prompt = "برای یک فروشگاه فایل‌های گرافیکی، برای فایل زیر یک عنوان و توضیح محصول فارسی، جذاب و سئو-پسند بنویس.\n"
			. "نام اصلی فایل (احتمالاً انگلیسی): {$session->file_name}\n"
			. "نوع فایل: {$session->file_type}\n"
			. "کد فایل: {$session->file_code}\n"
			. ( $software ? "نرم‌افزارهای قابل استفاده: {$software}\n" : '' )
			. ( $filesize ? "حجم فایل: {$filesize}\n" : '' )
			. ( $excerpt ? "متن مرجع از سایت اصلی (برای الهام گرفتن، نه کپی مستقیم): {$excerpt}\n" : '' )
			. "\nقوانین:\n"
			. "- عنوان: نام فایل را به فارسی روان ترجمه/بازنویسی کن، کوتاه و جذاب (حداکثر ۱۰ کلمه)، بدون کد فایل.\n"
			. "- توضیح: شامل کاربرد فایل، فرمت، نرم‌افزارهای قابل استفاده، و چرا این فایل مفید است؛ در پایان کد فایل را بیاور.\n"
			. "- فقط خروجی را دقیقاً به‌صورت JSON با این ساختار بده و هیچ متن اضافه‌ای قبل یا بعدش ننویس:\n"
			. '{"title": "...", "description": "..."}';

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $key,
			),
			'body'    => wp_json_encode( array(
				'model'    => $model,
				'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			STI_Logger::warning( "AI API «{$label}» ناموفق بود: " . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			STI_Logger::warning( "AI API «{$label}» خطای HTTP {$code} برگرداند." );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = $body['choices'][0]['message']['content'] ?? '';
		if ( ! $text ) {
			STI_Logger::warning( "AI API «{$label}» پاسخ خالی برگرداند." );
			return false;
		}

		// Be tolerant of the model wrapping the JSON in ```json fences despite instructions.
		$text = trim( preg_replace( '/^```json|```$/m', '', trim( $text ) ) );
		$parsed = json_decode( $text, true );

		if ( empty( $parsed['description'] ) ) {
			STI_Logger::warning( "خروجی AI API «{$label}» قابل تجزیه نبود." );
			return false;
		}

		STI_Logger::info( "محتوا با AI API «{$label}» ساخته شد." );
		return array(
			'title'       => sanitize_text_field( $parsed['title'] ?? '' ),
			'description' => sanitize_textarea_field( $parsed['description'] ),
		);
	}


	/** بهبود عنوان+توضیح با پروفایل‌های AI یا Pollinations رایگان. */
	public static function ai_improve_title_and_description( $current_title, $file_name = '', $file_type = '', $category_label = '' ) {
		$prompt = "تو متخصص سئو فروشگاه فایل گرافیکی فارسی هستی.\n"
			. "برای این محصول یک عنوان و یک توضیح کوتاه فارسی بنویس.\n"
			. "عنوان فعلی: {$current_title}\n"
			. ( $file_name ? "نام فایل اصلی: {$file_name}\n" : '' )
			. ( $file_type ? "نوع فایل: {$file_type}\n" : '' )
			. ( $category_label ? "دسته: {$category_label}\n" : '' )
			. "قوانین سخت:\n"
			. "1) عنوان با الگوی «دانلود [نوع] [کیفیت اختیاری] [شرح شیء]».\n"
			. "2) مثال خوب: دانلود موکاپ لایه باز لیوان کاغذی\n"
			. "3) مثال بد: لیوان کاغذی #مکاپ لایه باز\n"
			. "4) بدون هشتگ، بدون کد عددی، بدون انگلیسی، حداکثر ۱۲ کلمه.\n"
			. "5) توضیح ۲ تا ۴ جمله سئوپسند و طبیعی.\n"
			. "6) فقط JSON: {\"title\":\"...\",\"description\":\"...\"}";

		if ( class_exists( 'STI_AI' ) && STI_AI::is_ready() ) {
			$res = STI_AI::json( $prompt, array( 'cache_key' => 'improve|' . mb_strtolower( $current_title ), 'max_tokens' => 800 ) );
			if ( ! is_wp_error( $res ) && ! empty( $res['title'] ) ) {
				return array(
					'title'       => class_exists( 'STI_Title_Engine' ) ? STI_Title_Engine::finalize( (string) $res['title'] ) : sanitize_text_field( (string) $res['title'] ),
					'description' => isset( $res['description'] ) ? wp_kses_post( (string) $res['description'] ) : '',
				);
			}
		}
		$profiles = method_exists( 'STI_Settings', 'get_ai_rotation_order' ) ? STI_Settings::get_ai_rotation_order() : array();
		foreach ( (array) $profiles as $profile ) {
			$result = self::call_ai_json_prompt( $profile, $prompt );
			if ( $result ) {
				return $result;
			}
		}
		return self::call_pollinations_json( $prompt );
	}

	protected static function call_ai_json_prompt( $profile, $prompt ) {
		$endpoint = trim( (string) ( $profile['endpoint'] ?? '' ) );
		$key      = (string) ( $profile['api_key'] ?? '' );
		$model    = (string) ( $profile['model'] ?? 'gpt-4o-mini' );
		$label    = (string) ( $profile['name'] ?? $endpoint );
		if ( '' === $endpoint ) {
			return false;
		}
		$headers = array( 'Content-Type' => 'application/json' );
		if ( $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$response = wp_remote_post( $endpoint, array(
			'timeout' => 45,
			'headers' => $headers,
			'body'    => wp_json_encode( array(
				'model'    => $model,
				'messages' => array(
					array( 'role' => 'system', 'content' => 'Only output valid JSON. No markdown.' ),
					array( 'role' => 'user', 'content' => $prompt ),
				),
				'temperature' => 0.4,
			) ),
		) );
		if ( is_wp_error( $response ) ) {
			STI_Logger::warning( "AI title «{$label}»: " . $response->get_error_message() );
			return false;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			STI_Logger::warning( "AI title «{$label}» HTTP {$code}" );
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = $body['choices'][0]['message']['content'] ?? ( $body['result']['response'] ?? '' );
		return self::parse_title_desc_json( $text );
	}

	protected static function call_pollinations_json( $prompt ) {
		$url = 'https://text.pollinations.ai/' . rawurlencode( $prompt . "\n\nReturn ONLY JSON." );
		$response = wp_remote_get( $url, array( 'timeout' => 40, 'headers' => array( 'Accept' => 'text/plain' ) ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		return self::parse_title_desc_json( wp_remote_retrieve_body( $response ) );
	}

	protected static function parse_title_desc_json( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return false;
		}
		$text = preg_replace( '/^```(?:json)?\\s*|\\s*```$/m', '', $text );
		if ( preg_match( '/\\{.*\\}/su', $text, $m ) ) {
			$text = $m[0];
		}
		$parsed = json_decode( $text, true );
		if ( ! is_array( $parsed ) || empty( $parsed['title'] ) ) {
			return false;
		}
		$title = self::finalize_title( self::polish_persian_title_phrase( sanitize_text_field( $parsed['title'] ) ) );
		$desc  = isset( $parsed['description'] ) ? wp_kses_post( $parsed['description'] ) : '';
		return array( 'title' => $title, 'description' => $desc );
	}

	/** بازسازی توضیح محصول وقتی عنوان عوض می‌شود. */
	public static function build_description_for_title( $title, $file_name = '', $file_type = '', $file_code = '', $category = null, $ai_description = '' ) {
		if ( $ai_description ) {
			return wp_kses_post( $ai_description );
		}
		$session = (object) array(
			'file_name'       => $file_name ?: $title,
			'file_type'       => $file_type,
			'file_code'       => $file_code,
			'file_size_bytes' => null,
			'source_url'      => '',
		);
		$translated = self::polish_persian_title_phrase( preg_replace( '/^دانلود\\s+/u', '', $title ) );
		return self::render_template( $session, $category, '', $translated );
	}

}
