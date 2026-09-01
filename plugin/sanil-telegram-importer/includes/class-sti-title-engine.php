<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI_Title_Engine — موتور عنوان‌سازی استودیوی عنوان (v7)
 *
 * زنجیره‌ی تولید عنوان:
 *   ۱) پاکسازی نام خام تلگرام (هشتگ، کد، پسوند، نام سایت، نویز)
 *   ۲) تشخیص نوع فایل و برچسب فارسی آن
 *   ۳) ترجمه‌ی دیکشنری‌محور عبارت‌به‌عبارت (نه لغت‌به‌لغت)
 *   ۴) بازچینش به الگوی استاندارد: دانلود + نوع + ویژگی + موضوع
 *   ۵) اصلاح نگارشی فارسی (نیم‌فاصله، ی/ک عربی، تکرار کلمه)
 *   ۶) بازنویسی با AI (اختیاری) + اعتبارسنجی سخت روی خروجی AI
 *   ۷) امتیاز کیفیت ۰..۱۰۰ + یکتاسازی + کلمه‌ی کلیدی + اسلاگ + متا
 */
class STI_Title_Engine {

	const OPTION      = 'sti_title_rules';
	const META_BACKUP = '_sti_title_backup';
	const META_SCORE  = '_sti_title_score';
	const META_REVIEW = '_sti_title_reviewed';

	public static function defaults() {
		return array(
			'prefix'          => 'دانلود',
			'pattern'         => '{prefix} {type} {modifier} {subject}',
			'max_words'       => 12,
			'min_chars'       => 20,
			'max_chars'       => 70,
			'use_ai'          => 1,
			'ai_first'        => 1,
			'enforce_unique'  => 1,
			'strip_latin'     => 1,
			'append_type_word'=> 1,
			'banned'          => "مکاپ\nموکاپ آزاد\nفایل لایه ای\nدانلود رایگان\nکرک\nتورنت\nبدون واترمارک\nفری",
			'replacements'    => "مکاپ=>موکاپ\nلایه باز=>لایه‌باز\nوکتر=>وکتور\nفونت انگلیسی=>فونت لاتین\nپست اینستاگرام=>پست اینستاگرامی\nتمپلیت=>قالب\nبنر=>بنر تبلیغاتی",
			'custom_glossary' => '',
		);
	}

	public static function rules() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) { $saved = array(); }
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function save_rules( array $values ) {
		$r = array_merge( self::rules(), $values );
		update_option( self::OPTION, $r, false );
		return $r;
	}

	/** برچسب فارسی نوع فایل — پایه‌ی کل عنوان. */
	public static function type_labels() {
		return array(
			'PSD' => 'فایل لایه‌باز', 'PSB' => 'فایل لایه‌باز', 'MOCKUP' => 'موکاپ',
			'VECTOR' => 'وکتور', 'AI' => 'وکتور', 'EPS' => 'وکتور', 'SVG' => 'وکتور',
			'CDR' => 'وکتور کورل', 'FONT' => 'فونت', 'TTF' => 'فونت', 'OTF' => 'فونت',
			'ICON' => 'آیکون', 'PATTERN' => 'پترن', 'TEXTURE' => 'تکسچر',
			'TEMPLATE' => 'قالب آماده', 'JPG' => 'تصویر با کیفیت', 'JPEG' => 'تصویر با کیفیت',
			'PNG' => 'تصویر دوربری‌شده', 'TIFF' => 'تصویر با کیفیت',
			'MOTION' => 'پروژه موشن گرافیک', 'AEP' => 'پروژه افترافکت', 'MOGRT' => 'پریست پریمیر',
			'PRPROJ' => 'پروژه پریمیر', 'MP4' => 'فوتیج ویدیویی', 'MOV' => 'فوتیج ویدیویی',
			'LUT' => 'پریست رنگ', 'CUBE' => 'پریست رنگ', 'PRESET' => 'پریست',
			'LRTEMPLATE' => 'پریست لایتروم', 'XMP' => 'پریست لایتروم',
			'3D' => 'مدل سه‌بعدی', 'OBJ' => 'مدل سه‌بعدی', 'FBX' => 'مدل سه‌بعدی',
			'BLEND' => 'فایل بلندر', 'C4D' => 'پروژه سینمافوردی', 'MAX' => 'مدل تری‌دی‌مکس',
			'PPT' => 'قالب پاورپوینت', 'PPTX' => 'قالب پاورپوینت', 'KEY' => 'قالب کینوت',
			'INDD' => 'فایل ایندیزاین', 'PDF' => 'فایل پی‌دی‌اف', 'FIG' => 'فایل فیگما',
			'XD' => 'فایل ادوبی ایکس‌دی', 'SKETCH' => 'فایل اسکچ',
			'HTML' => 'قالب اچ‌تی‌ام‌ال', 'ZIP' => 'بسته‌ی فایل', 'RAR' => 'بسته‌ی فایل',
			'AUDIO' => 'فایل صوتی', 'MP3' => 'موسیقی بدون کپی‌رایت', 'WAV' => 'افکت صوتی',
		);
	}

	public static function type_label( $file_type, $fallback = '' ) {
		$t = strtoupper( trim( (string) $file_type ) );
		$map = self::type_labels();
		if ( isset( $map[ $t ] ) ) { return $map[ $t ]; }
		return $fallback;
	}

	/**
	 * دیکشنری عبارت‌محور — کلید انگلیسی (lowercase)، مقدار فارسی.
	 * عبارت‌های چندکلمه‌ای اول تطبیق داده می‌شوند تا ترجمه طبیعی بماند.
	 */
	public static function glossary() {
		$g = array(
			/* نوع/فرمت */
			'mock up' => 'موکاپ', 'mockup' => 'موکاپ', 'mock-up' => 'موکاپ',
			'psd template' => 'قالب لایه‌باز', 'layered' => 'لایه‌باز', 'editable' => 'قابل ویرایش',
			'vector' => 'وکتور', 'seamless pattern' => 'پترن تکرارشو', 'seamless' => 'تکرارشو',
			'pattern' => 'پترن', 'texture' => 'تکسچر', 'background' => 'پس‌زمینه',
			'backgrounds' => 'پس‌زمینه', 'wallpaper' => 'والپیپر', 'icon set' => 'مجموعه آیکون',
			'icons' => 'آیکون', 'icon' => 'آیکون', 'font family' => 'خانواده فونت',
			'display font' => 'فونت نمایشی', 'script font' => 'فونت دست‌نویس',
			'serif' => 'سریف', 'sans serif' => 'سن‌سریف', 'handwritten' => 'دست‌نویس',
			'calligraphy' => 'خطاطی', 'lettering' => 'لترینگ', 'typeface' => 'فونت',
			'font' => 'فونت', 'preset' => 'پریست', 'presets' => 'پریست',
			'lightroom' => 'لایتروم', 'photoshop action' => 'اکشن فتوشاپ', 'action' => 'اکشن',
			'brush' => 'براش', 'brushes' => 'براش', 'gradient' => 'گرادیانت',
			'template' => 'قالب', 'templates' => 'قالب', 'bundle' => 'مجموعه',
			'pack' => 'پک', 'collection' => 'مجموعه', 'set' => 'ست',
			'3d model' => 'مدل سه‌بعدی', '3d' => 'سه‌بعدی', 'isometric' => 'ایزومتریک',
			'animation' => 'انیمیشن', 'motion graphics' => 'موشن گرافیک', 'footage' => 'فوتیج',
			'transition' => 'ترانزیشن', 'lower third' => 'زیرنویس تلویزیونی', 'intro' => 'اینترو',
			'slideshow' => 'اسلایدشو', 'infographic' => 'اینفوگرافیک', 'chart' => 'نمودار',
			/* اقلام چاپی و اداری */
			'business card' => 'کارت ویزیت', 'visiting card' => 'کارت ویزیت',
			'letterhead' => 'سربرگ', 'envelope' => 'پاکت نامه', 'invoice' => 'فاکتور',
			'brochure' => 'بروشور', 'tri fold' => 'سه‌لت', 'trifold' => 'سه‌لت',
			'bifold' => 'دولت', 'flyer' => 'تراکت', 'leaflet' => 'تراکت',
			'poster' => 'پوستر', 'banner' => 'بنر', 'roll up' => 'رول‌آپ',
			'billboard' => 'بیلبورد', 'signage' => 'تابلو', 'catalog' => 'کاتالوگ',
			'catalogue' => 'کاتالوگ', 'magazine' => 'مجله', 'newspaper' => 'روزنامه',
			'book cover' => 'جلد کتاب', 'cover' => 'جلد', 'menu' => 'منو',
			'restaurant menu' => 'منوی رستوران', 'price list' => 'لیست قیمت',
			'certificate' => 'گواهینامه', 'invitation' => 'کارت دعوت',
			'wedding invitation' => 'کارت عروسی', 'greeting card' => 'کارت تبریک',
			'calendar' => 'تقویم', 'resume' => 'رزومه', 'cv' => 'رزومه',
			'presentation' => 'ارائه', 'stationery' => 'ست اداری',
			'branding' => 'برندینگ', 'brand identity' => 'هویت بصری',
			'logo' => 'لوگو', 'logotype' => 'لوگوتایپ', 'monogram' => 'مونوگرام',
			'badge' => 'نشان', 'emblem' => 'نشان', 'label' => 'لیبل',
			'sticker' => 'استیکر', 'tag' => 'تگ', 'packaging' => 'بسته‌بندی',
			'box' => 'جعبه', 'bag' => 'کیف', 'paper bag' => 'ساک کاغذی',
			'pouch' => 'پاکت', 'bottle' => 'بطری', 'jar' => 'شیشه',
			'can' => 'قوطی', 'tube' => 'تیوب', 'cup' => 'لیوان',
			'paper cup' => 'لیوان کاغذی', 'coffee cup' => 'لیوان قهوه', 'mug' => 'ماگ',
			'tshirt' => 'تی‌شرت', 't shirt' => 'تی‌شرت', 'hoodie' => 'هودی',
			'apron' => 'پیش‌بند', 'cap' => 'کلاه', 'uniform' => 'یونیفرم',
			/* دیجیتال و وب */
			'social media' => 'شبکه اجتماعی', 'instagram post' => 'پست اینستاگرامی',
			'instagram story' => 'استوری اینستاگرام', 'story' => 'استوری',
			'post' => 'پست', 'facebook cover' => 'کاور فیسبوک', 'youtube thumbnail' => 'تامبنیل یوتیوب',
			'website' => 'وب‌سایت', 'landing page' => 'صفحه فرود', 'ui kit' => 'کیت رابط کاربری',
			'dashboard' => 'داشبورد', 'mobile app' => 'اپلیکیشن موبایل', 'app' => 'اپلیکیشن',
			'wireframe' => 'وایرفریم', 'email template' => 'قالب ایمیل',
			/* موضوعات پرتکرار */
			'coffee' => 'قهوه', 'cafe' => 'کافه', 'barista' => 'باریستا',
			'restaurant' => 'رستوران', 'food' => 'غذا', 'burger' => 'برگر',
			'pizza' => 'پیتزا', 'bakery' => 'نان و شیرینی', 'fruit' => 'میوه',
			'vegetable' => 'سبزیجات', 'juice' => 'آبمیوه', 'milk' => 'شیر',
			'latte art' => 'لته آرت', 'drink' => 'نوشیدنی', 'cosmetic' => 'لوازم آرایشی',
			'skincare' => 'مراقبت پوست', 'perfume' => 'عطر', 'medical' => 'پزشکی',
			'hospital' => 'بیمارستان', 'dental' => 'دندانپزشکی', 'fitness' => 'تناسب اندام',
			'gym' => 'باشگاه', 'sport' => 'ورزشی', 'football' => 'فوتبال',
			'travel' => 'سفر', 'tourism' => 'گردشگری', 'hotel' => 'هتل',
			'real estate' => 'املاک', 'architecture' => 'معماری', 'interior' => 'داخلی',
			'kitchen' => 'آشپزخانه', 'living room' => 'اتاق نشیمن', 'furniture' => 'مبلمان',
			'office' => 'اداری', 'business' => 'کسب‌وکار', 'corporate' => 'شرکتی',
			'finance' => 'مالی', 'bank' => 'بانک', 'crypto' => 'ارز دیجیتال',
			'education' => 'آموزشی', 'school' => 'مدرسه', 'university' => 'دانشگاه',
			'kids' => 'کودکانه', 'baby' => 'نوزاد', 'wedding' => 'عروسی',
			'birthday' => 'تولد', 'party' => 'جشن', 'ramadan' => 'رمضان',
			'eid' => 'عید', 'christmas' => 'کریسمس', 'new year' => 'سال نو',
			'nowruz' => 'نوروز', 'islamic' => 'اسلامی', 'arabic' => 'عربی',
			'car' => 'خودرو', 'automotive' => 'خودرویی', 'technology' => 'تکنولوژی',
			'gaming' => 'گیمینگ', 'music' => 'موسیقی', 'podcast' => 'پادکست',
			'nature' => 'طبیعت', 'forest' => 'جنگل', 'flower' => 'گل',
			'floral' => 'گل‌دار', 'tropical' => 'استوایی', 'animal' => 'حیوانات',
			'city' => 'شهری', 'sky' => 'آسمان', 'sea' => 'دریا',
			/* صفت‌ها و ویژگی‌ها */
			'modern' => 'مدرن', 'minimal' => 'مینیمال', 'minimalist' => 'مینیمال',
			'elegant' => 'شیک', 'luxury' => 'لاکچری', 'premium' => 'حرفه‌ای',
			'professional' => 'حرفه‌ای', 'creative' => 'خلاقانه', 'clean' => 'تمیز',
			'abstract' => 'انتزاعی', 'geometric' => 'هندسی', 'gradient mesh' => 'مش گرادیانی',
			'realistic' => 'واقع‌گرایانه', 'vintage' => 'وینتیج', 'retro' => 'رترو',
			'colorful' => 'رنگارنگ', 'dark' => 'تیره', 'light' => 'روشن',
			'white' => 'سفید', 'black' => 'مشکی', 'gold' => 'طلایی',
			'golden' => 'طلایی', 'silver' => 'نقره‌ای', 'blue' => 'آبی',
			'green' => 'سبز', 'red' => 'قرمز', 'pastel' => 'پاستلی',
			'watercolor' => 'آبرنگی', 'hand drawn' => 'دست‌کشیده', 'flat' => 'فلت',
			'top view' => 'نمای بالا', 'front view' => 'نمای روبرو', 'side view' => 'نمای جانبی',
			'close up' => 'نمای نزدیک', 'high resolution' => 'رزولوشن بالا', 'hd' => 'کیفیت بالا',
			'4k' => 'کیفیت چهار کی', 'transparent' => 'پس‌زمینه شفاف',
			'set of' => 'مجموعه', 'with' => 'با', 'and' => 'و', 'for' => 'برای',
			'on' => '', 'in' => '', 'of' => '', 'the' => '', 'a' => '', 'an' => '',
		);
		/* دیکشنری شخصی مدیر (خط به خط: english=>فارسی) */
		$custom = self::parse_pairs( self::rules()['custom_glossary'] );
		return array_merge( $g, $custom );
	}

	public static function parse_pairs( $raw ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			if ( false === strpos( $line, '=>' ) ) { continue; }
			list( $k, $v ) = array_map( 'trim', explode( '=>', $line, 2 ) );
			if ( '' === $k ) { continue; }
			$out[ mb_strtolower( $k ) ] = $v;
		}
		return $out;
	}

	/* ============== ۱) پاکسازی نام خام تلگرام ============== */

	public static function clean_raw( $raw ) {
		$t = (string) $raw;
		$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
		$t = str_replace( array( '_', '|', '•', '·', '–', '—' ), ' ', $t );
		/* پسوند فایل انتهایی */
		$t = preg_replace( '/\.(psd|psb|ai|eps|svg|cdr|indd|pdf|jpg|jpeg|png|tif|tiff|zip|rar|7z|mp4|mov|aep|prproj|mogrt|ttf|otf|woff2?|fig|xd|sketch|obj|fbx|blend|c4d|max|abr|atn|cube|lut)\b/iu', ' ', $t );
		/* نام سایت‌های منبع و نویز رایج */
		$noise = array( 'freepik', 'envato', 'elements', 'graphicriver', 'creativemarket', 'creative market', 'designbundles', 'yellowimages', 'yellow images', 'pixeden', 'pngtree', 'lovepik', 'vecteezy', 'rawpixel', 'shutterstock', 'adobestock', 'adobe stock', 'istock', 'dribbble', 'behance', 'magnific', 'fileech', 'premium download', 'free download', 'free', 'nulled', 'cracked', 'torrent', 'full version', 'latest version', 'download' );
		foreach ( $noise as $n ) {
			$t = preg_replace( '/\b' . preg_quote( $n, '/' ) . '\b/iu', ' ', $t );
		}
		/* هشتگ‌ها (متن هشتگ نگه داشته می‌شود چون گاهی موضوع است) */
		$t = preg_replace( '/#([\p{L}\p{N}_]+)/u', ' $1 ', $t );
		/* آی‌دی تلگرام و لینک */
		$t = preg_replace( '#https?://\S+#i', ' ', $t );
		$t = preg_replace( '/@[A-Za-z0-9_]{3,}/', ' ', $t );
		/* کدهای عددی بلند و شماره نسخه */
		$t = preg_replace( '/(?<!\d)\d{4,}(?!\d)/', ' ', $t );
		$t = preg_replace( '/\bv\.?\s?\d+(\.\d+)*\b/i', ' ', $t );
		$t = preg_replace( '/\bvol\.?\s?\d+\b/i', ' ', $t );
		/* پرانتز/براکت خالی و علائم اضافه */
		$t = preg_replace( '/[\[\]\(\)\{\}<>*\\\\\/"\x27`~^]+/u', ' ', $t );
		$t = preg_replace( '/\s*[:;,\.\-]+\s*$/u', '', $t );
		$t = preg_replace( '/\s{2,}/u', ' ', $t );
		return trim( $t );
	}

	/* ============== ۲) ترجمه‌ی عبارت‌محور ============== */

	public static function translate_phrase( $latin ) {
		$g = self::glossary();
		/* عبارت‌های بلندتر اول */
		$keys = array_keys( $g );
		usort( $keys, function ( $a, $b ) { return mb_strlen( $b ) <=> mb_strlen( $a ); } );

		$text = ' ' . mb_strtolower( $latin ) . ' ';
		$out  = array();
		foreach ( $keys as $k ) {
			if ( '' === trim( $k ) ) { continue; }
			$pat = '/(?<![\p{L}])' . preg_quote( $k, '/' ) . '(?![\p{L}])/u';
			if ( preg_match( $pat, $text ) ) {
				$fa = trim( (string) $g[ $k ] );
				$text = preg_replace( $pat, ' ', $text, 1 );
				if ( '' !== $fa ) { $out[] = $fa; }
			}
		}
		$leftover = trim( preg_replace( '/\s{2,}/u', ' ', $text ) );
		return array(
			'fa_words'  => $out,
			'untranslated' => $leftover ? self::split_words( $leftover ) : array(),
		);
	}

	/* ============== ۵) اصلاح نگارشی فارسی ============== */

	public static function polish_fa( $text ) {
		$t = (string) $text;
		/* حروف عربی → فارسی */
		$t = str_replace( array( 'ي', 'ك', 'ة', 'أ', 'إ', 'ؤ', 'ٱ' ), array( 'ی', 'ک', 'ه', 'ا', 'ا', 'و', 'ا' ), $t );
		/* اعداد عربی/انگلیسی داخل عنوان فارسی → فارسی */
		$t = str_replace( array( '٠','١','٢','٣','٤','٥','٦','٧','٨','٩' ), array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' ), $t );
		/* اعراب و کاراکترهای کنترلی بی‌مصرف */
		$t = preg_replace( '/[\x{064B}-\x{0652}\x{0640}]/u', '', $t );
		/* نیم‌فاصله‌های استاندارد */
		$zwnj = json_decode( '"\u200c"' );
		$pairs = array(
			'لایه باز' => 'لایه' . $zwnj . 'باز',
			'می توان'  => 'می' . $zwnj . 'توان',
			'می شود'   => 'می' . $zwnj . 'شود',
			'می کند'   => 'می' . $zwnj . 'کند',
			'بسته بندی'=> 'بسته' . $zwnj . 'بندی',
			'کسب و کار'=> 'کسب' . $zwnj . 'وکار',
			'سه بعدی'  => 'سه' . $zwnj . 'بعدی',
			'پس زمینه' => 'پس' . $zwnj . 'زمینه',
			'تی شرت'   => 'تی' . $zwnj . 'شرت',
			'حرفه ای'  => 'حرفه' . $zwnj . 'ای',
			'دست نویس' => 'دست' . $zwnj . 'نویس',
			'رنگ آمیزی'=> 'رنگ' . $zwnj . 'آمیزی',
			'پیش بند'  => 'پیش' . $zwnj . 'بند',
			'هویت بصری'=> 'هویت بصری',
		);
		$t = str_replace( array_keys( $pairs ), array_values( $pairs ), $t );
		/* جانشینی‌های مدیر */
		$reps = self::parse_pairs( self::rules()['replacements'] );
		if ( $reps ) { $t = str_replace( array_keys( $reps ), array_values( $reps ), $t ); }
		/* حذف تکرار کلمه پشت سر هم و تکرار غیرمجاور */
		$words = self::split_words( trim( $t ) );
		$seen = array(); $clean = array();
		foreach ( $words as $w ) {
			$key = mb_strtolower( $w );
			if ( '' === $key ) { continue; }
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$clean[] = $w;
		}
		$t = implode( ' ', $clean );
		/* فاصله‌ها و علائم */
		$t = preg_replace( '/\s*،\s*/u', '، ', $t );
		$t = preg_replace( '/\s{2,}/u', ' ', $t );
		/**
		 * ریشه‌ی خرابی UTF-8 در عنوان‌ها همین یک خط بود.
		 *
		 * trim() با فهرست کاراکتر، **بایت‌به‌بایت** کار می‌کند نه
		 * کاراکتر‌به‌کاراکتر. «،» و «؛» هرکدام دو بایت دارند و بایت اولشان
		 * 0xD8 است — همان بایتی که «د»، «ر»، «و» و بیشتر حروف فارسی هم با
		 * آن شروع می‌شوند.
		 *
		 * نتیجه: trim بایت اول اولین حرف فارسی را می‌تراشید. «دانلود»
		 * می‌شد «انلود» و رشته از نظر UTF-8 نامعتبر می‌شد — همان هشداری که
		 * در هر Session چهار پنج بار تکرار می‌شد، همیشه با «طول قبل ۹۶،
		 * بعد ۹۵».
		 *
		 * preg_replace با مودیفایر /u کاراکترها را درست می‌شناسد.
		 */
		$trimmed = preg_replace( '/^[\s\x00\x0B\-،:؛.]+|[\s\x00\x0B\-،:؛.]+$/u', '', $t );

		return ( null === $trimmed ) ? trim( $t ) : $trimmed;
	}

	/* ============== ۴) ساخت عنوان با قاعده ============== */

	public static function build_rule_title( $args ) {
		$r = self::rules();
		$clean = self::clean_raw( isset( $args['file_name'] ) ? $args['file_name'] : '' );
		$type_label = self::type_label( isset( $args['file_type'] ) ? $args['file_type'] : '', '' );

		$tr = self::translate_phrase( $clean );
		$fa_words = $tr['fa_words'];

		$type_for_parts = $type_label;
		if ( $type_label && in_array( $type_label, $fa_words, true ) ) { $type_for_parts = ''; }

		$subject = implode( ' ', $fa_words );
		$parts = array( $r['prefix'] );
		if ( $type_for_parts && ! empty( $r['append_type_word'] ) ) { $parts[] = $type_for_parts; }
		if ( $subject ) { $parts[] = $subject; }

		if ( ! $subject && ! empty( $tr['untranslated'] ) && empty( $r['strip_latin'] ) ) {
			$parts[] = implode( ' ', array_slice( $tr['untranslated'], 0, 4 ) );
		}

		$title = self::polish_fa( implode( ' ', array_filter( $parts ) ) );
		$title = self::limit_words( $title, (int) $r['max_words'] );
		return array(
			'title'        => $title,
			'clean'        => $clean,
			'type_label'   => $type_label,
			'fa_words'     => $fa_words,
			'untranslated' => $tr['untranslated'],
		);
	}

	/**
	 * تقسیم امن به کلمه — هرگز false برنمی‌گرداند.
	 *
	 * preg_split با مودیفایر /u وقتی رشته UTF-8 معتبر نباشد false می‌دهد، و
	 * در PHP 8 مقدار count(false) یک TypeError کشنده است. این دقیقاً همان
	 * چیزی بود که کل Title Engine را در مسیر گلدن اسکن می‌انداخت و محصول‌ها
	 * را به Fallback قاعده‌محور می‌فرستاد.
	 *
	 * منبع رشته‌ی نامعتبر معمولاً خروجی ترجمه است (پاسخ بریده یا با انکودینگ
	 * متفاوت)، نه ورودی کاربر — پس پاک‌سازی در ورودی جواب نمی‌داد.
	 *
	 * رفتار برای رشته‌های معتبر دقیقاً مثل قبل است.
	 */
	protected static function split_words( $text ) {
		$text = (string) $text;

		$w = preg_split( '/\s+/u', $text );
		if ( false !== $w ) {
			return $w;
		}

		/**
		 * ترتیب اینجا مهم است.
		 *
		 * wp_check_invalid_utf8( $text, true ) وقتی iconv در دسترس نباشد
		 * **رشته‌ی خالی** برمی‌گرداند. نتیجه‌اش این بود که عنوان فارسی کاملاً
		 * حذف می‌شد، Content Engine عنوان خالی تحویل می‌داد و Product Builder
		 * به Fallback انگلیسی می‌افتاد — بدون هیچ خطایی. یعنی باگ از «کشنده»
		 * به «بی‌صدا» تبدیل شده بود، نه حل‌شده.
		 *
		 * mb_convert_encoding از UTF-8 به UTF-8 فقط دنباله‌های نامعتبر را
		 * دور می‌ریزد و متن فارسی سالم را نگه می‌دارد.
		 */
		$safe = '';
		if ( function_exists( 'mb_convert_encoding' ) ) {
			// بدون این، بایت خراب به «?» تبدیل می‌شود نه حذف — و «دانلود»
			// در خروجی به «?انلود» تبدیل می‌شد.
			$previous = function_exists( 'mb_substitute_character' ) ? mb_substitute_character() : null;
			if ( null !== $previous ) {
				mb_substitute_character( 'none' );
			}
			$safe = (string) @mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
			if ( null !== $previous ) {
				mb_substitute_character( $previous );
			}
		}
		if ( '' === $safe ) {
			$safe = (string) wp_check_invalid_utf8( $text, true );
		}
		if ( '' === $safe && '' !== trim( $text ) ) {
			// آخرین راه: فقط ASCII چاپ‌شدنی. بهتر از عنوان خالی.
			$safe = (string) preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', '', $text );
		}

		if ( class_exists( 'STI_Logger' ) ) {
			static $pcre_u = null;
			if ( null === $pcre_u ) {
				$pcre_u = (bool) @preg_match( '/^./u', 'a' );
			}
			STI_Logger::warning( sprintf(
				'Title Engine: رشته‌ی UTF-8 نامعتبر پاک‌سازی شد. (PCRE/u: %s، mbstring: %s، iconv: %s، طول قبل: %d، بعد: %d)',
				$pcre_u ? 'دارد' : 'ندارد',
				function_exists( 'mb_convert_encoding' ) ? 'دارد' : 'ندارد',
				function_exists( 'iconv' ) ? 'دارد' : 'ندارد',
				strlen( $text ), strlen( $safe )
			) );
		}

		$w = preg_split( '/\s+/u', $safe );
		if ( false !== $w ) {
			return $w;
		}

		// آخرین تلاش: بدون مودیفایر /u.
		$w = preg_split( '/\s+/', $safe );
		return is_array( $w ) ? $w : array( $safe );
	}

	public static function limit_words( $text, $max ) {
		$max = max( 4, (int) $max );
		$w = self::split_words( trim( (string) $text ) );
		if ( count( $w ) <= $max ) { return trim( (string) $text ); }
		return implode( ' ', array_slice( $w, 0, $max ) );
	}

	public static function enforce_prefix( $title ) {
		$r = self::rules();
		$prefix = trim( (string) $r['prefix'] );
		if ( '' === $prefix ) { return trim( (string) $title ); }
		$t = trim( (string) $title );
		if ( 0 === mb_strpos( $t, $prefix ) ) { return $t; }
		$t = trim( str_replace( $prefix, '', $t ) );
		return trim( $prefix . ' ' . $t );
	}

	/* ============== ۶) زنجیره‌ی کامل: قاعده + AI + اعتبارسنجی ============== */

	public static function build( $args, $opts = array() ) {
		$r = self::rules();
		$args = self::normalize_args( $args, $opts );
		$args = wp_parse_args( $args, array(
			'file_name' => '', 'file_type' => '', 'category' => '', 'software' => '',
			'excerpt' => '', 'file_code' => '', 'current_title' => '', 'use_ai' => null, 'post_id' => 0,
		) );

		$rule = self::build_rule_title( $args );
		$out = array(
			'title'            => $rule['title'],
			'rule_title'       => $rule['title'],
			'ai_title'         => '',
			'source'           => 'rules',
			'focus_keyword'    => '',
			'slug'             => '',
			'meta_description' => '',
			'provider'         => '',
			'untranslated'     => $rule['untranslated'],
		);

		$use_ai = is_null( $args['use_ai'] ) ? ! empty( $r['use_ai'] ) : (bool) $args['use_ai'];
		if ( $use_ai && class_exists( 'STI_AI' ) && STI_AI::is_ready() ) {
			$prompt = STI_AI::render_prompt( STI_AI::prompt( 'title' ), array(
				'file_name'     => $rule['clean'] ? $rule['clean'] : $args['file_name'],
				'file_type'     => $args['file_type'],
				'type_label_fa' => $rule['type_label'],
				'category'      => $args['category'],
				'software'      => $args['software'],
				'excerpt'       => mb_substr( (string) $args['excerpt'], 0, 600 ),
			) );
			$ai = STI_AI::complete_json( $prompt, array( 'cache_key' => 'title|' . md5( (string) $args['file_name'] . '|' . $args['file_type'] ) ) );
			if ( ! is_wp_error( $ai ) && ! empty( $ai['title'] ) ) {
				$cand = self::polish_fa( sanitize_text_field( $ai['title'] ) );
				$cand = self::enforce_prefix( $cand );
				$cand = self::limit_words( $cand, (int) $r['max_words'] );
				$check = self::validate( $cand );
				$out['ai_title'] = $cand;
				$out['provider'] = isset( $ai['_provider'] ) ? $ai['_provider'] : '';
				if ( $check['score'] >= 70 ) {
					$out['title']  = $cand;
					$out['source'] = 'ai';
					$out['focus_keyword'] = isset( $ai['focus_keyword'] ) ? sanitize_text_field( $ai['focus_keyword'] ) : '';
					$out['slug'] = isset( $ai['slug'] ) ? sanitize_title( $ai['slug'] ) : '';
				}
			} elseif ( is_wp_error( $ai ) && class_exists( 'STI_Logger' ) ) {
				STI_Logger::warning( 'Title Studio: AI ناموفق — ' . $ai->get_error_message() );
			}
		}

		if ( ! empty( $r['enforce_unique'] ) ) {
			$out['title'] = self::make_unique( $out['title'], (int) $args['post_id'], $args );
		}
		if ( ! $out['focus_keyword'] ) { $out['focus_keyword'] = self::guess_focus_keyword( $out['title'] ); }
		if ( ! $out['slug'] ) { $out['slug'] = self::build_slug( $out['title'] ); }

		$v = self::validate( $out['title'] );
		$out['score']  = $v['score'];
		$out['issues'] = $v['issues'];
		return $out;
	}

	/* ============== ۷) اعتبارسنجی و امتیاز کیفیت ============== */

	public static function validate( $title ) {
		$r = self::rules();
		$t = trim( (string) $title );
		$issues = array();
		$score = 100;

		if ( '' === $t ) {
			return array( 'score' => 0, 'issues' => array( array( 'code' => 'empty', 'label' => 'عنوان خالی است', 'sev' => 'error' ) ) );
		}

		$chars = mb_strlen( $t );
		$words = count( self::split_words( $t ) );

		if ( preg_match( '/#/u', $t ) ) { $issues[] = array( 'code' => 'hashtag', 'label' => 'هشتگ دارد', 'sev' => 'error' ); $score -= 25; }
		if ( preg_match( '/[A-Za-z]{2,}/', $t ) ) { $issues[] = array( 'code' => 'latin', 'label' => 'کلمه‌ی انگلیسی دارد', 'sev' => 'error' ); $score -= 20; }
		if ( preg_match( '/(?<!\p{L})\d{3,}/u', $t ) ) { $issues[] = array( 'code' => 'code', 'label' => 'کد عددی دارد', 'sev' => 'error' ); $score -= 20; }
		if ( preg_match( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $t ) ) { $issues[] = array( 'code' => 'emoji', 'label' => 'ایموجی دارد', 'sev' => 'warn' ); $score -= 10; }
		if ( preg_match( '/[!؟]{1,}/u', $t ) ) { $issues[] = array( 'code' => 'punct', 'label' => 'علامت تعجب/سؤال دارد', 'sev' => 'warn' ); $score -= 6; }
		if ( preg_match( '/[_\|\/\\\\]/u', $t ) ) { $issues[] = array( 'code' => 'symbol', 'label' => 'کاراکتر نامناسب دارد', 'sev' => 'warn' ); $score -= 8; }

		$prefix = trim( (string) $r['prefix'] );
		if ( $prefix && 0 !== mb_strpos( $t, $prefix ) ) {
			$issues[] = array( 'code' => 'prefix', 'label' => 'با «' . $prefix . '» شروع نمی‌شود', 'sev' => 'warn' );
			$score -= 12;
		}
		if ( $chars < (int) $r['min_chars'] ) { $issues[] = array( 'code' => 'short', 'label' => 'عنوان خیلی کوتاه است (' . $chars . ' نویسه)', 'sev' => 'warn' ); $score -= 12; }
		if ( $chars > (int) $r['max_chars'] ) { $issues[] = array( 'code' => 'long', 'label' => 'عنوان بلندتر از حد سئو است (' . $chars . ' نویسه)', 'sev' => 'warn' ); $score -= 10; }
		if ( $words > (int) $r['max_words'] ) { $issues[] = array( 'code' => 'words', 'label' => 'تعداد کلمات زیاد است (' . $words . ')', 'sev' => 'warn' ); $score -= 8; }

		/* تکرار کلمه */
		$ws = self::split_words( mb_strtolower( $t ) );
		if ( count( $ws ) !== count( array_unique( $ws ) ) ) {
			$issues[] = array( 'code' => 'dupword', 'label' => 'کلمه تکراری دارد', 'sev' => 'warn' );
			$score -= 10;
		}

		/* کلمات ممنوعه */
		foreach ( preg_split( '/\r\n|\r|\n|,/', (string) $r['banned'] ) as $b ) {
			$b = trim( $b );
			if ( '' === $b ) { continue; }
			if ( false !== mb_strpos( $t, $b ) ) {
				$issues[] = array( 'code' => 'banned', 'label' => 'کلمه‌ی ممنوعه: ' . $b, 'sev' => 'error' );
				$score -= 15;
			}
		}

		/* ترتیب غیرطبیعی: نوع فایل بعد از موضوع آمده */
		$type_words = array( 'موکاپ', 'وکتور', 'فونت', 'پترن', 'آیکون', 'قالب', 'پریست', 'تکسچر' );
		foreach ( $type_words as $tw ) {
			$pos = mb_strpos( $t, $tw );
			if ( false !== $pos && $pos > ( mb_strlen( $t ) / 2 ) ) {
				$issues[] = array( 'code' => 'order', 'label' => 'ترتیب طبیعی نیست («' . $tw . '» باید نزدیک ابتدا باشد)', 'sev' => 'warn' );
				$score -= 10;
				break;
			}
		}

		return array( 'score' => max( 0, min( 100, $score ) ), 'issues' => $issues );
	}

	public static function make_unique( $title, $exclude_id = 0, $args = array() ) {
		global $wpdb;
		$t = trim( (string) $title );
		if ( '' === $t ) { return $t; }
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'product' AND post_status NOT IN ('trash','auto-draft') AND ID <> %d LIMIT 1",
			$t, (int) $exclude_id
		) );
		if ( ! $exists ) { return $t; }

		/* تمایزبخش‌های معنادار (نه عدد) */
		$candidates = array();
		if ( ! empty( $args['category'] ) ) { $candidates[] = (string) $args['category']; }
		$tl = self::type_label( isset( $args['file_type'] ) ? $args['file_type'] : '', '' );
		if ( $tl ) { $candidates[] = $tl; }
		$candidates[] = 'باکیفیت';
		$candidates[] = 'ویژه';
		foreach ( $candidates as $c ) {
			$c = trim( $c );
			if ( '' === $c || false !== mb_strpos( $t, $c ) ) { continue; }
			$try = $t . ' ' . $c;
			$dup = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'product' AND post_status NOT IN ('trash','auto-draft') AND ID <> %d LIMIT 1",
				$try, (int) $exclude_id
			) );
			if ( ! $dup ) { return $try; }
		}
		/* آخرین راه: کد فایل */
		if ( ! empty( $args['file_code'] ) ) { return $t . ' کد ' . $args['file_code']; }
		return $t;
	}

	public static function guess_focus_keyword( $title ) {
		$r = self::rules();
		$t = trim( (string) $title );
		$prefix = trim( (string) $r['prefix'] );
		if ( $prefix && 0 === mb_strpos( $t, $prefix ) ) {
			$t = trim( mb_substr( $t, mb_strlen( $prefix ) ) );
		}
		$w = self::split_words( $t );
		return implode( ' ', array_slice( $w, 0, 4 ) );
	}

	public static function build_slug( $title ) {
		$s = sanitize_title( $title );
		return $s ? $s : sanitize_title( 'file-' . wp_generate_password( 6, false ) );
	}

	/* ============== اسکن انبوه محصولات ============== */

	public static function scan_products( $filters = array() ) {
		$filters = wp_parse_args( $filters, array(
			'term_id' => 0, 'limit' => 50, 'only_problems' => 1, 'hide_reviewed' => 1,
			'min_score' => 100, 'status' => array( 'publish', 'draft', 'pending', 'future' ),
			'use_ai' => 0, 'offset' => 0,
		) );

		$q = array(
			'post_type'      => 'product',
			'post_status'    => $filters['status'],
			'posts_per_page' => max( 1, min( 200, (int) $filters['limit'] ) ),
			'offset'         => max( 0, (int) $filters['offset'] ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( ! empty( $filters['term_id'] ) ) {
			$q['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => (int) $filters['term_id'] ) );
		}
		if ( ! empty( $filters['hide_reviewed'] ) ) {
			$q['meta_query'] = array( array( 'key' => self::META_REVIEW, 'compare' => 'NOT EXISTS' ) );
		}

		$ids = get_posts( $q );
		$rows = array();
		foreach ( $ids as $pid ) {
			$row = self::suggest_for_post( $pid, ! empty( $filters['use_ai'] ) );
			if ( ! $row ) { continue; }
			if ( ! empty( $filters['only_problems'] ) && $row['current_score'] >= (int) $filters['min_score'] ) { continue; }
			$rows[] = $row;
		}
		return $rows;
	}

	/** داده‌ی سشن مربوط به یک محصول (برای بازسازی دقیق عنوان). */
	public static function session_for_post( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sti_sessions';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d ORDER BY id DESC LIMIT 1", (int) $post_id ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	public static function suggest_for_post( $post_id, $use_ai = false ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return null; }

		$s = self::session_for_post( $post_id );
		$file_name = ! empty( $s['file_name'] ) ? $s['file_name'] : $post->post_title;
		$file_type = ! empty( $s['file_type'] ) ? $s['file_type'] : '';
		$file_code = ! empty( $s['file_code'] ) ? $s['file_code'] : '';

		$cats = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) );
		$category = ( ! is_wp_error( $cats ) && $cats ) ? $cats[0] : '';

		$software = class_exists( 'STI_Content_Generator' ) && method_exists( 'STI_Content_Generator', 'type_software_public' )
			? STI_Content_Generator::type_software_public( $file_type ) : '';

		$built = self::build( array(
			'file_name'     => $file_name,
			'file_type'     => $file_type,
			'file_code'     => $file_code,
			'category'      => $category,
			'software'      => $software,
			'current_title' => $post->post_title,
			'post_id'       => $post_id,
			'use_ai'        => $use_ai,
		) );

		$cur = self::validate( $post->post_title );
		return array(
			'id'             => (int) $post_id,
			'edit_url'       => get_edit_post_link( $post_id, '' ),
			'view_url'       => get_permalink( $post_id ),
			'status'         => $post->post_status,
			'current'        => $post->post_title,
			'current_score'  => $cur['score'],
			'current_issues' => $cur['issues'],
			'suggestion'     => $built['title'],
			'rule_title'     => $built['rule_title'],
			'ai_title'       => $built['ai_title'],
			'source'         => $built['source'],
			'provider'       => $built['provider'],
			'new_score'      => $built['score'],
			'new_issues'     => $built['issues'],
			'focus_keyword'  => $built['focus_keyword'],
			'slug'           => $built['slug'],
			'file_type'      => $file_type,
			'file_code'      => $file_code,
			'category'       => $category,
			'has_backup'     => (bool) get_post_meta( $post_id, self::META_BACKUP, true ),
		);
	}

	public static function apply_to_post( $post_id, $title, $opts = array() ) {
		$post_id = (int) $post_id;
		$post = get_post( $post_id );
		if ( ! $post ) { return new WP_Error( 'sti_ts_404', 'محصول پیدا نشد.' ); }
		$title = self::polish_fa( sanitize_text_field( $title ) );
		if ( '' === $title ) { return new WP_Error( 'sti_ts_empty', 'عنوان خالی است.' ); }

		$opts = wp_parse_args( $opts, array( 'sync_slug' => 0, 'sync_desc' => 0, 'mark_reviewed' => 1, 'focus_keyword' => '' ) );

		if ( ! get_post_meta( $post_id, self::META_BACKUP, true ) ) {
			update_post_meta( $post_id, self::META_BACKUP, wp_json_encode( array(
				'title' => $post->post_title, 'slug' => $post->post_name, 'at' => current_time( 'mysql' ),
			) ) );
		}

		$data = array( 'ID' => $post_id, 'post_title' => $title );
		if ( ! empty( $opts['sync_slug'] ) ) { $data['post_name'] = self::build_slug( $title ); }

		if ( ! empty( $opts['sync_desc'] ) ) {
			$desc = self::build_description( $post_id, $title, $opts['focus_keyword'] );
			if ( $desc ) { $data['post_content'] = $desc; }
		}

		$res = wp_update_post( $data, true );
		if ( is_wp_error( $res ) ) { return $res; }

		$v = self::validate( $title );
		update_post_meta( $post_id, self::META_SCORE, (int) $v['score'] );
		if ( ! empty( $opts['mark_reviewed'] ) ) { update_post_meta( $post_id, self::META_REVIEW, current_time( 'mysql' ) ); }

		/* کلمه‌ی کلیدی برای افزونه‌های سئو */
		$kw = $opts['focus_keyword'] ? $opts['focus_keyword'] : self::guess_focus_keyword( $title );
		if ( $kw ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $kw );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $kw );
		}
		return array( 'title' => $title, 'score' => $v['score'] );
	}

	public static function undo_post( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META_BACKUP, true );
		if ( ! $raw ) { return new WP_Error( 'sti_ts_nobackup', 'نسخه‌ی پشتیبانی برای این محصول ذخیره نشده.' ); }
		$b = json_decode( $raw, true );
		if ( empty( $b['title'] ) ) { return new WP_Error( 'sti_ts_nobackup', 'پشتیبان نامعتبر است.' ); }
		$res = wp_update_post( array( 'ID' => (int) $post_id, 'post_title' => $b['title'] ), true );
		if ( is_wp_error( $res ) ) { return $res; }
		delete_post_meta( (int) $post_id, self::META_BACKUP );
		delete_post_meta( (int) $post_id, self::META_REVIEW );
		return array( 'title' => $b['title'] );
	}

	public static function build_description( $post_id, $title, $focus_keyword = '' ) {
		$s = self::session_for_post( $post_id );
		$file_type = ! empty( $s['file_type'] ) ? $s['file_type'] : '';
		$software = class_exists( 'STI_Content_Generator' ) && method_exists( 'STI_Content_Generator', 'type_software_public' )
			? STI_Content_Generator::type_software_public( $file_type ) : '';
		$cats = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) );
		$category = ( ! is_wp_error( $cats ) && $cats ) ? $cats[0] : '';

		if ( class_exists( 'STI_AI' ) && STI_AI::is_ready() ) {
			$prompt = STI_AI::render_prompt( STI_AI::prompt( 'description' ), array(
				'title'         => $title,
				'file_name'     => isset( $s['file_name'] ) ? $s['file_name'] : '',
				'file_type'     => $file_type,
				'type_label_fa' => self::type_label( $file_type, '' ),
				'category'      => $category,
				'software'      => $software,
				'filesize'      => isset( $s['file_size_bytes'] ) ? size_format( (int) $s['file_size_bytes'] ) : '',
				'file_code'     => isset( $s['file_code'] ) ? $s['file_code'] : '',
				'excerpt'       => '',
				'focus_keyword' => $focus_keyword ? $focus_keyword : self::guess_focus_keyword( $title ),
			) );
			$ai = STI_AI::complete_json( $prompt, array( 'cache_key' => 'desc|' . md5( $title ) ) );
			if ( ! is_wp_error( $ai ) && ! empty( $ai['description'] ) ) {
				return wp_kses_post( $ai['description'] );
			}
		}

		if ( class_exists( 'STI_Content_Generator' ) && method_exists( 'STI_Content_Generator', 'build_description_for_title' ) ) {
			return STI_Content_Generator::build_description_for_title(
				$title,
				isset( $s['file_name'] ) ? $s['file_name'] : '',
				$file_type,
				isset( $s['file_code'] ) ? $s['file_code'] : ''
			);
		}
		return '';
	}

	/* ============== لایه‌ی سازگاری با استودیو (v7) ============== */

	public static function normalize_args( $args, $opts = array() ) {
		$args = (array) $args;
		if ( isset( $args['category_label'] ) && empty( $args['category'] ) ) {
			$args['category'] = $args['category_label'];
		}
		if ( is_array( $opts ) && array_key_exists( 'use_ai', $opts ) ) {
			$args['use_ai'] = $opts['use_ai'];
		}
		if ( is_array( $opts ) && ! empty( $opts['post_id'] ) ) {
			$args['post_id'] = (int) $opts['post_id'];
		}
		return $args;
	}

	public static function audit( $title ) {
		$v = self::validate( $title );
		$v['label'] = $v['score'] >= 85 ? 'خوب' : ( $v['score'] >= 60 ? 'قابل قبول' : 'ضعیف' );
		return $v;
	}

	public static function finalize( $title ) {
		$t = self::polish_fa( sanitize_text_field( (string) $title ) );
		$t = self::enforce_prefix( $t );
		return self::limit_words( $t, (int) self::rules()['max_words'] );
	}

	public static function build_by_rules( $file_name, $file_type = '', $category = '' ) {
		$r = self::build_rule_title( array( 'file_name' => $file_name, 'file_type' => $file_type, 'category' => $category ) );
		$v = self::validate( $r['title'] );
		return array(
			'title'        => $r['title'],
			'type_label'   => $r['type_label'],
			'clean'        => $r['clean'],
			'fa_words'     => $r['fa_words'],
			'untranslated' => $r['untranslated'],
			'source'       => 'rules',
			'score'        => $v['score'],
			'issues'       => $v['issues'],
		);
	}

	public static function build_by_ai( $file_name, $file_type = '', $category = '', $rules_only = array() ) {
		if ( ! class_exists( 'STI_AI' ) || ! STI_AI::is_ready() ) {
			return new WP_Error( 'sti_ts_no_ai', 'هیچ سرویس هوش مصنوعی فعالی ثبت نشده — از صفحه‌ی «هوش مصنوعی» یک API اضافه کن.' );
		}
		$type_label = isset( $rules_only['type_label'] ) ? $rules_only['type_label'] : self::type_label( $file_type, '' );
		$clean = isset( $rules_only['clean'] ) ? $rules_only['clean'] : self::clean_raw( $file_name );
		$prompt = STI_AI::render_prompt( STI_AI::prompt( 'title' ), array(
			'file_name'     => $clean ? $clean : $file_name,
			'file_type'     => $file_type,
			'type_label_fa' => $type_label,
			'category'      => $category,
			'software'      => '',
			'excerpt'       => '',
		) );
		$ai = STI_AI::complete_json( $prompt, array( 'cache_key' => 'title|' . md5( (string) $file_name . '|' . $file_type ) ) );
		if ( is_wp_error( $ai ) ) { return $ai; }
		if ( empty( $ai['title'] ) ) { return new WP_Error( 'sti_ts_ai_empty', 'هوش مصنوعی عنوانی برنگرداند.' ); }
		$t = self::finalize( $ai['title'] );
		return array(
			'title'         => $t,
			'source'        => 'ai',
			'provider'      => isset( $ai['_provider'] ) ? $ai['_provider'] : '',
			'ms'            => isset( $ai['_ms'] ) ? (int) $ai['_ms'] : 0,
			'focus_keyword' => isset( $ai['focus_keyword'] ) ? sanitize_text_field( $ai['focus_keyword'] ) : self::guess_focus_keyword( $t ),
			'slug'          => isset( $ai['slug'] ) ? sanitize_title( $ai['slug'] ) : self::build_slug( $t ),
		);
	}

	public static function seo_meta( $title, $ctx = array() ) {
		$ctx = (array) $ctx;
		$kw = ! empty( $ctx['focus_keyword'] ) ? $ctx['focus_keyword'] : self::guess_focus_keyword( $title );
		$type = ! empty( $ctx['type_label'] ) ? $ctx['type_label'] : '';
		$desc = $title . ' با کیفیت بالا و قابل ویرایش. ' . ( $type ? $type . ' آماده‌ی دانلود فوری، ' : '' ) . 'مناسب پروژه‌های تجاری و شخصی. دانلود مستقیم و بدون محدودیت.';
		$desc = trim( preg_replace( '/\s{2,}/u', ' ', $desc ) );
		if ( mb_strlen( $desc ) > 158 ) { $desc = mb_substr( $desc, 0, 155 ) . '…'; }
		return array(
			'focus_keyword'    => $kw,
			'meta_description' => $desc,
			'slug'             => ! empty( $ctx['slug'] ) ? $ctx['slug'] : self::build_slug( $title ),
			'seo_title'        => mb_substr( $title, 0, 60 ),
		);
	}

	public static function apply_seo_meta( $post_id, $meta ) {
		$post_id = (int) $post_id;
		$meta = (array) $meta;
		if ( ! $post_id ) { return false; }
		if ( ! empty( $meta['focus_keyword'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $meta['focus_keyword'] );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $meta['focus_keyword'] );
		}
		if ( ! empty( $meta['meta_description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta['meta_description'] );
			update_post_meta( $post_id, 'rank_math_description', $meta['meta_description'] );
		}
		if ( ! empty( $meta['seo_title'] ) ) {
			update_post_meta( $post_id, 'rank_math_title', $meta['seo_title'] );
		}
		return true;
	}

	public static function remember( $post_id, $old_title, $old_content = null ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) { return; }
		$data = array( 'title' => (string) $old_title, 'at' => current_time( 'mysql' ) );
		if ( ! is_null( $old_content ) ) { $data['content'] = (string) $old_content; }
		update_post_meta( $post_id, self::META_BACKUP, wp_json_encode( $data ) );
	}

	public static function revert( $post_id ) {
		$post_id = (int) $post_id;
		$raw = get_post_meta( $post_id, self::META_BACKUP, true );
		if ( ! $raw ) { return new WP_Error( 'sti_ts_nobackup', 'نسخه‌ی پشتیبان ذخیره نشده.' ); }
		$b = json_decode( $raw, true );
		if ( empty( $b['title'] ) ) { return new WP_Error( 'sti_ts_nobackup', 'پشتیبان نامعتبر است.' ); }
		$update = array( 'ID' => $post_id, 'post_title' => $b['title'] );
		if ( ! empty( $b['content'] ) ) { $update['post_content'] = $b['content']; }
		$res = wp_update_post( $update, true );
		if ( is_wp_error( $res ) ) { return $res; }
		delete_post_meta( $post_id, self::META_BACKUP );
		delete_post_meta( $post_id, '_sti_title_reviewed' );
		delete_post_meta( $post_id, self::META_REVIEW );
		return true;
	}

	public static function noise_words() {
		return array( 'freepik', 'envato', 'elements', 'graphicriver', 'creativemarket', 'designbundles', 'yellowimages', 'pixeden', 'pngtree', 'lovepik', 'vecteezy', 'rawpixel', 'shutterstock', 'adobestock', 'istock', 'magnific', 'fileech', 'download', 'free', 'premium', 'nulled', 'cracked', 'torrent', 'full', 'version', 'vol', 'set', 'pack', 'file', 'files', 'new', 'best', 'top' );
	}

	public static function export_rules() {
		return array(
			'plugin'  => 'golden-importer',
			'section' => 'title-studio',
			'version' => defined( 'STI_VERSION' ) ? STI_VERSION : '7.0.0',
			'date'    => current_time( 'mysql' ),
			'rules'   => self::rules(),
		);
	}

	public static function import_rules( $json, $merge = true ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) { return new WP_Error( 'sti_ts_badjson', 'فایل JSON معتبر نیست.' ); }
		$rules = isset( $data['rules'] ) && is_array( $data['rules'] ) ? $data['rules'] : $data;
		$allowed = array_keys( self::defaults() );
		$clean = array();
		foreach ( $rules as $k => $v ) {
			if ( in_array( $k, $allowed, true ) ) { $clean[ $k ] = is_scalar( $v ) ? $v : ''; }
		}
		if ( empty( $clean ) ) { return new WP_Error( 'sti_ts_empty', 'هیچ قاعده‌ی قابل ورودی پیدا نشد.' ); }
		$base = $merge ? self::rules() : self::defaults();
		update_option( self::OPTION, array_merge( $base, $clean ), false );
		return array( 'imported' => count( $clean ) );
	}
}
