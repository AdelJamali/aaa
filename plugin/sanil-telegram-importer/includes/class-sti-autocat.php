<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI AutoCat — سیستم اتوکت، تشخیص هوشمند دسته‌بندی
 *
 * معماری بر اساس داکیومنت: https://docs.google.com/document/d/1voQJ3VbOF07-faahxN5smpDWSxAzraaaMe_RcVVZiD0
 * - هسته: Category Detection Engine
 * - موتور قوانین: هر دسته شامل کلمات قطعی، قوی، کمکی، منفی، اولویت، آستانه اطمینان
 * - موتور امتیازدهی هوشمند: برای هر دسته امتیاز، با اولویت و confidence
 * - قانون طلایی: فرمت فایل هرگز دسته اصلی نیست
 * - یادگیری از اصلاحات مدیر
 */
class STI_AutoCat {

	const TABLE_KEYWORDS = 'sti_autocat_keywords';
	const TABLE_LEARNING = 'sti_autocat_learning';
	const TABLE_LOGS     = 'sti_autocat_logs';

	protected static $instance;

	/** @var array کش دسته‌ها */
	protected static $categories_cache = null;

	/** @var array کش کلمات کلیدی */
	protected static $keywords_cache = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		// hooks می‌تواند اینجا اضافه شود
	}

	/* ==================== نصب و دیتابیس ==================== */

	public static function table_keywords() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_KEYWORDS;
	}
	public static function table_learning() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_LEARNING;
	}
	public static function table_logs() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_LOGS;
	}

	/** نگارش دیکشنری — هر بار منطق امتیازدهی/کلمات اصلاح می‌شود، این عدد بالا می‌رود
	 *  تا migrate_dictionary() یک‌بار روی نصب‌های قبلی هم اجرا شود. */
	const DICT_VERSION = 2;

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$kw_table = self::table_keywords();
		$sql1 = "CREATE TABLE {$kw_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			category_slug VARCHAR(80) NOT NULL,
			keyword VARCHAR(190) NOT NULL,
			score SMALLINT NOT NULL DEFAULT 70,
			type VARCHAR(20) NOT NULL DEFAULT 'normal',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY category_slug (category_slug),
			KEY keyword (keyword),
			KEY type (type)
		) {$charset};";
		dbDelta( $sql1 );

		$learn_table = self::table_learning();
		$sql2 = "CREATE TABLE {$learn_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title TEXT NOT NULL,
			detected_category VARCHAR(80) NULL,
			correct_category VARCHAR(80) NULL,
			count INT NOT NULL DEFAULT 1,
			last_updated DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY detected (detected_category),
			KEY correct (correct_category)
		) {$charset};";
		dbDelta( $sql2 );

		$log_table = self::table_logs();
		$sql3 = "CREATE TABLE {$log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title TEXT NOT NULL,
			file_type VARCHAR(80) NULL,
			detected_category VARCHAR(80) NULL,
			format_category VARCHAR(80) NULL,
			confidence FLOAT NULL,
			matched_keywords TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY detected (detected_category)
		) {$charset};";
		dbDelta( $sql3 );

		// اگر جدول کلمات خالی است، دیکشنری اولیه را seed کن
		$cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$kw_table}" );
		if ( $cnt < 50 ) {
			self::seed_initial_dictionary();
		}

		// روی نصب‌های قبلی که دیکشنری قدیمی (نسخه ۱) را دارند، مهاجرت اصلاحی را اجرا کن
		self::migrate_dictionary();
	}

	/**
	 * مهاجرت دیکشنری از نسخه‌های قبلی.
	 *
	 * v1 → v2:
	 * دیکشنری قدیمی مکاپ، اسم اشیاء (بنر، فلایر، پوستر، کارت ویزیت…) را به‌تنهایی
	 * به‌عنوان امتیاز مکاپ حساب می‌کرد — یعنی هر فایل «Banner» یا «Flyer» خالص هم
	 * چند امتیاز مکاپ می‌گرفت. این ردیف‌های قدیمی را حذف و معادل «ترکیبی» آن‌ها
	 * (که فقط با حضور واقعیِ کلمه‌ی mockup/mock up امتیاز می‌گیرند) را اضافه می‌کند.
	 */
	protected static function migrate_dictionary() {
		global $wpdb;
		$current = (int) get_option( 'sti_autocat_dict_version', 1 );
		if ( $current >= self::DICT_VERSION ) {
			return;
		}

		$table = self::table_keywords();

		if ( $current < 2 ) {
			$obsolete_mockup_objects = array(
				'bottle', 'jar', 'can', 'box', 'package', 'packaging', 'phone', 'iphone', 'smartphone',
				'screen', 'monitor', 'laptop', 'business card', 'flyer', 'poster', 'banner', 'billboard',
				'signboard', 'lanyard', 'cup', 'mug', 'tshirt', 't-shirt', 'hoodie', 'bag',
			);
			foreach ( $obsolete_mockup_objects as $kw ) {
				$wpdb->delete( $table, array(
					'category_slug' => 'mockup',
					'keyword'       => mb_strtolower( $kw ),
					'type'          => 'strong',
				) );
			}

			// اگر ردیف‌های ترکیبی جدید مکاپ هنوز در دیتابیس نیستند، اضافه‌شان کن
			$now  = current_time( 'mysql' );
			$defs = self::get_main_categories_definition();
			foreach ( $defs['mockup']['combined'] ?? array() as $kw ) {
				$kw_norm = mb_strtolower( trim( $kw ) );
				$exists  = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE category_slug = %s AND keyword = %s",
					'mockup',
					$kw_norm
				) );
				if ( ! $exists ) {
					$wpdb->insert( $table, array(
						'category_slug' => 'mockup',
						'keyword'       => $kw_norm,
						'score'         => 90,
						'type'          => 'combined',
						'created_at'    => $now,
						'updated_at'    => $now,
					) );
				}
			}

			STI_Logger::info( 'AutoCat: دیکشنری به نسخه ۲ مهاجرت داده شد — کلمات شیء‌ای مکاپ به الگوی ترکیبی تبدیل شدند.' );
		}

		update_option( 'sti_autocat_dict_version', self::DICT_VERSION );
	}

	/* ==================== دیکشنری اولیه عظیم ==================== */

	public static function get_main_categories_definition() {
		// اولویت: هرچه عدد کوچکتر، اولویت بالاتر (Mockup بالاترین)
		return array(
			'mockup' => array(
				'label' => 'Mockup',
				'label_fa' => 'موکاپ',
				'priority' => 1,
				'primary' => array( 'mockup', 'mock up', 'psd mockup', 'branding mockup', 'product mockup', 'packaging mockup', 'app mockup', 'logo mockup' ),
				'secondary' => array( 'presentation', 'showcase', 'display', 'preview', 'scene', 'template showcase', 'branding presentation' ),
				/* ── قانون طلایی: نام یک شیء/محصول (بنر، فلایر، کارت ویزیت، بطری…) به‌تنهایی
				 * دلیل مکاپ بودن نیست — این‌ها فقط زمانی مکاپ محسوب می‌شوند که کلمه‌ی
				 * «مکاپ» هم در کنارشان باشد (مثل «bottle mockup» یا «banner mock up»).
				 * قبلاً این‌ها در فیلد «object» بودند و به‌تنهایی امتیاز می‌گرفتند —
				 * همین باعث می‌شد یک فایل «Banner» خالص هم به‌عنوان Mockup امتیاز بگیرد. */
				'combined' => array(
					'mockup+bottle', 'mock up+bottle', 'mockup+jar', 'mock up+jar', 'mockup+can', 'mock up+can',
					'mockup+box', 'mock up+box', 'mockup+package', 'mock up+package', 'mockup+packaging', 'mock up+packaging',
					'mockup+phone', 'mock up+phone', 'mockup+iphone', 'mock up+iphone', 'mockup+smartphone', 'mock up+smartphone',
					'mockup+screen', 'mock up+screen', 'mockup+monitor', 'mock up+monitor', 'mockup+laptop', 'mock up+laptop',
					'mockup+business card', 'mock up+business card', 'mockup+flyer', 'mock up+flyer', 'mockup+poster', 'mock up+poster',
					'mockup+banner', 'mock up+banner', 'mockup+billboard', 'mock up+billboard', 'mockup+signboard', 'mock up+signboard',
					'mockup+lanyard', 'mock up+lanyard', 'mockup+cup', 'mock up+cup', 'mockup+mug', 'mock up+mug',
					'mockup+tshirt', 'mock up+tshirt', 'mockup+t-shirt', 'mock up+t-shirt', 'mockup+hoodie', 'mock up+hoodie',
					'mockup+bag', 'mock up+bag',
				),
				'negative' => array( 'logo design', 'vector logo', 'infographic', 'text effect', 'font' ),
			),
			'logo' => array(
				'label' => 'Logo',
				'label_fa' => 'لوگو',
				'priority' => 2,
				'primary' => array( 'logo', 'logotype', 'brand mark', 'logo design', 'logo template', 'brand logo' ),
				'secondary' => array( 'monogram', 'lettermark', 'wordmark', 'badge logo', 'emblem', 'symbol', 'icon logo', 'minimal logo', 'abstract logo', 'luxury logo', 'mascot logo', 'modern logo', 'creative logo' ),
				'strong' => array( 'initial letter', 'letter logo', 'business logo' ),
				'negative' => array( 'mockup', 'business card mockup', 'flyer', 'poster' ),
			),
			'business-card' => array(
				'label' => 'Business Card',
				'label_fa' => 'کارت ویزیت',
				'priority' => 3,
				'primary' => array( 'business card', 'name card', 'visiting card', 'corporate card', 'business cards' ),
				'secondary' => array( 'card template', 'company card', 'identity card', 'corporate identity', 'business card design' ),
				'negative' => array( 'business card mockup', 'id card mockup', 'mockup' ),
			),
			'flyer' => array(
				'label' => 'Flyer',
				'label_fa' => 'فلایر',
				'priority' => 4,
				'primary' => array( 'flyer', 'flyer template', 'event flyer', 'party flyer', 'business flyer', 'corporate flyer' ),
				'secondary' => array( 'promotion flyer', 'sale flyer', 'travel flyer', 'club flyer', 'dj flyer', 'festival flyer', 'concert flyer' ),
			),
			'brochure' => array(
				'label' => 'Brochure',
				'label_fa' => 'بروشور',
				'priority' => 5,
				'primary' => array( 'brochure', 'trifold brochure', 'bifold brochure', 'booklet', 'catalog', 'catalogue', 'tri-fold' ),
			),
			'poster' => array(
				'label' => 'Poster',
				'label_fa' => 'پوستر',
				'priority' => 6,
				'primary' => array( 'poster', 'poster design', 'movie poster', 'event poster', 'festival poster', 'concert poster' ),
				'secondary' => array( 'wall poster', 'advertising poster', 'typography poster' ),
			),
			'banner' => array(
				'label' => 'Banner',
				'label_fa' => 'بنر',
				'priority' => 7,
				'primary' => array( 'banner', 'web banner', 'roll up banner', 'stand banner', 'facebook banner', 'youtube banner', 'twitter banner', 'linkedin banner', 'twitch banner', 'banner template' ),
				'secondary' => array( 'cover banner', 'hero banner', 'promotion banner', 'advertising banner', 'header banner' ),
			),
			'text-effect' => array(
				'label' => 'Text Effect',
				'label_fa' => 'افکت متن',
				'priority' => 8,
				'primary' => array( 'text effect', 'editable text effect', '3d text effect', 'text style', 'text effects', 'layered text effect' ),
				'secondary' => array( 'editable font', 'font style', 'title effect', 'typography effect', '3d text', 'psd text effect' ),
				'strong' => array( 'neon', 'chrome', 'gold text', 'silver', 'metallic text', 'comic text', 'cartoon text', 'retro text', 'vintage text', 'glossy text', 'grunge text', 'neon glow' ),
				'negative' => array( 'font family', 'ttf font' ),
			),
			'infographic' => array(
				'label' => 'Infographic',
				'label_fa' => 'اینفوگرافیک',
				'priority' => 9,
				'primary' => array( 'infographic', 'infographics', 'info graphic' ),
				'secondary' => array( 'timeline', 'process infographic', 'workflow', 'steps infographic', 'statistics', 'chart', 'graphs', 'diagram', 'dashboard infographic', 'data visualization', 'comparison infographic', 'pie chart', 'bar chart', 'flowchart' ),
				'strong' => array( '4 steps', '5 steps', '6 steps', 'process icons' ),
			),
			'resume' => array(
				'label' => 'Resume',
				'label_fa' => 'رزومه',
				'priority' => 10,
				'primary' => array( 'resume', 'resume template', 'cv resume', 'curriculum vitae', 'resume design', 'professional resume' ),
				'secondary' => array( 'cv template', 'cv', 'curriculum', 'job resume' ),
			),
			'invoice' => array(
				'label' => 'Invoice',
				'label_fa' => 'فاکتور',
				'priority' => 11,
				'primary' => array( 'invoice', 'invoice template', 'bill template', 'receipt', 'invoice design' ),
			),
			'certificate' => array(
				'label' => 'Certificate',
				'label_fa' => 'گواهینامه',
				'priority' => 12,
				'primary' => array( 'certificate', 'certificate template', 'diploma', 'award certificate', 'certification' ),
			),
			'presentation' => array(
				'label' => 'Presentation',
				'label_fa' => 'پرزنتیشن',
				'priority' => 13,
				'primary' => array( 'presentation', 'powerpoint', 'keynote', 'pitch deck', 'slide template', 'presentation template' ),
			),
			'packaging' => array(
				'label' => 'Packaging',
				'label_fa' => 'بسته‌بندی',
				'priority' => 14,
				'primary' => array( 'packaging', 'package design', 'box packaging', 'product packaging', 'label packaging' ),
				'secondary' => array( 'box', 'bag packaging', 'bottle label', 'jar label' ),
			),
			'background' => array(
				'label' => 'Background',
				'label_fa' => 'بک‌گراند',
				'priority' => 15,
				'primary' => array( 'background', 'backdrop', 'wallpaper', 'backgrounds' ),
				'secondary' => array( 'abstract background', 'technology background', 'gradient background', 'color background', 'geometric background', 'floral background', 'nature background', 'space background', 'grunge background' ),
			),
			'texture' => array(
				'label' => 'Texture',
				'label_fa' => 'تکسچر',
				'priority' => 16,
				'primary' => array( 'texture', 'textures', 'textured', 'seamless texture' ),
				'secondary' => array( 'wood texture', 'paper texture', 'fabric texture', 'marble texture', 'grunge texture', 'noise texture', 'brick texture', 'wall texture', 'concrete texture', 'metal texture', 'leather texture' ),
				'strong' => array( 'surface', 'material', 'grain', 'rough', 'silk texture', 'velvet texture', 'stone texture' ),
			),
			'pattern' => array(
				'label' => 'Pattern',
				'label_fa' => 'پترن',
				'priority' => 17,
				'primary' => array( 'pattern', 'patterns', 'seamless pattern', 'pattern template' ),
				'secondary' => array( 'ornament pattern', 'geometric pattern', 'floral pattern', 'line pattern', 'arabesque pattern', 'islamic pattern', 'damask pattern', 'ethnic pattern' ),
				'strong' => array( 'seamless', 'repeating', 'repeat', 'tileable', 'ornament' ),
			),
			'typography' => array(
				'label' => 'Typography',
				'label_fa' => 'تایپوگرافی',
				'priority' => 18,
				'primary' => array( 'typography', 'quote typography', 'typographic', 'lettering', 'calligraphy', 'hand lettering' ),
				'secondary' => array( 'tshirt typography', 'motivational quote', 'quote design', 'typography design', 'type design' ),
			),
			'sticker' => array(
				'label' => 'Sticker',
				'label_fa' => 'استیکر',
				'priority' => 19,
				'primary' => array( 'sticker', 'stickers', 'sticker pack', 'sticker set' ),
				'secondary' => array( 'emoji sticker', 'travel sticker', 'cute sticker', 'cartoon sticker', 'vinyl sticker' ),
			),
			'mascot' => array(
				'label' => 'Mascot',
				'label_fa' => 'مسکات',
				'priority' => 20,
				'primary' => array( 'mascot', 'mascot logo', 'character', 'cartoon character', 'mascot design' ),
				'secondary' => array( 'cute mascot', 'animal mascot', 'food mascot', 'sports mascot', 'monster mascot', 'robot mascot' ),
			),
			'illustration' => array(
				'label' => 'Illustration',
				'label_fa' => 'تصویرسازی',
				'priority' => 21,
				'primary' => array( 'illustration', 'illustrated', 'vector illustration', 'illustration set', 'flat illustration' ),
				'secondary' => array( 'drawing', 'artwork', 'cartoon illustration', 'hand drawn', 'sketch illustration', 'isometric illustration' ),
			),
			'icon' => array(
				'label' => 'Icon',
				'label_fa' => 'آیکون',
				'priority' => 22,
				'primary' => array( 'icon', 'icons', 'icon set', 'icon pack', 'line icons', 'flat icons', 'glyph icons' ),
				'secondary' => array( 'ui icon', 'app icon', 'web icon', 'symbol set', 'pictogram', 'outline icon' ),
			),
			'flags' => array(
				'label' => 'Flags',
				'label_fa' => 'پرچم',
				'priority' => 23,
				'primary' => array( 'flag', 'flags', 'country flags', 'national flag', 'flag set', 'flag icon' ),
				'secondary' => array( 'world flags', 'flag collection', 'flag pack', 'country flag', 'iran flag', 'usa flag', 'uk flag' ),
			),
			'png' => array(
				'label' => 'PNG',
				'label_fa' => 'پی‌ان‌جی',
				'priority' => 24,
				'primary' => array( 'png', 'transparent png', 'isolated png', 'png file', 'transparent background', 'without background', 'cutout png' ),
				'secondary' => array( 'transparent', 'isolated', 'without background', 'cutout', 'clipart', 'render', 'cut out', 'object isolated' ),
				'negative' => array( 'png mockup' ),
			),
			'social-media' => array(
				'label' => 'Social Media',
				'label_fa' => 'شبکه اجتماعی',
				'priority' => 25,
				'primary' => array( 'social media', 'instagram post', 'facebook post', 'linkedin post', 'social media template', 'instagram story', 'social media kit' ),
			),
			'ui-elements' => array(
				'label' => 'UI Elements',
				'label_fa' => 'المان یوآی',
				'priority' => 26,
				'primary' => array( 'ui elements', 'ui kit', 'ui components', 'interface elements', 'web elements' ),
			),
			'web-template' => array(
				'label' => 'Web Template',
				'label_fa' => 'قالب وب',
				'priority' => 27,
				'primary' => array( 'web template', 'website template', 'landing page', 'landing page template', 'html template', 'web design' ),
			),
		);
	}

	public static function get_format_categories_definition() {
		return array(
			'vector' => array( 'label'=>'Vector', 'priority'=>100, 'keywords'=>array( 'vector', 'ai', 'eps', 'svg', 'cdr' ) ),
			'psd' => array( 'label'=>'PSD', 'priority'=>101, 'keywords'=>array( 'psd', 'photoshop' ) ),
			'photo' => array( 'label'=>'Photo', 'priority'=>102, 'keywords'=>array( 'photo', 'photography', 'jpg', 'jpeg' ) ),
			'png-format' => array( 'label'=>'PNG Format', 'priority'=>103, 'keywords'=>array( 'png' ) ),
			'motion' => array( 'label'=>'Motion', 'label_fa'=>'موشن', 'priority'=>50, 'keywords'=>array( 'motion', 'video', 'mp4', 'mov', 'avi', 'after effects', 'premiere', 'animation' ) ),
			'3d' => array( 'label'=>'3D', 'priority'=>104, 'keywords'=>array( '3d', 'blender', 'cinema 4d', 'fbx', 'obj' ) ),
		);
	}

	public static function seed_initial_dictionary() {
		global $wpdb;
		$table = self::table_keywords();
		$now = current_time( 'mysql' );
		$mains = self::get_main_categories_definition();
		$inserted = 0;

		foreach ( $mains as $slug => $def ) {
			$priority = $def['priority'];

			foreach ( $def['primary'] ?? array() as $kw ) {
				$wpdb->insert( $table, array(
					'category_slug' => $slug,
					'keyword' => mb_strtolower( trim( $kw ) ),
					'score' => 100,
					'type' => 'exact',
					'created_at' => $now,
					'updated_at' => $now,
				) );
				$inserted++;
			}
			foreach ( $def['secondary'] ?? array() as $kw ) {
				$wpdb->insert( $table, array(
					'category_slug' => $slug,
					'keyword' => mb_strtolower( trim( $kw ) ),
					'score' => 70,
					'type' => 'normal',
					'created_at' => $now,
					'updated_at' => $now,
				) );
				$inserted++;
			}
			foreach ( $def['object'] ?? $def['strong'] ?? array() as $kw ) {
				if ( is_array( $kw ) ) continue;
				$wpdb->insert( $table, array(
					'category_slug' => $slug,
					'keyword' => mb_strtolower( trim( $kw ) ),
					'score' => 50,
					'type' => 'strong',
					'created_at' => $now,
					'updated_at' => $now,
				) );
				$inserted++;
			}
			/* ── الگوهای ترکیبی داخل خودِ تعریف دسته (مثل «mockup+bottle») ──
			 * این‌ها فقط وقتی امتیاز می‌گیرند که هر دو بخش در عنوان باشند — برای
			 * جلوگیری از این‌که یک شیء (بنر/فلایر/کارت ویزیت…) به‌تنهایی به عنوان
			 * مکاپ شمرده شود. */
			foreach ( $def['combined'] ?? array() as $kw ) {
				$wpdb->insert( $table, array(
					'category_slug' => $slug,
					'keyword'       => mb_strtolower( trim( $kw ) ),
					'score'         => 90,
					'type'          => 'combined',
					'created_at'    => $now,
					'updated_at'    => $now,
				) );
				$inserted++;
			}
			foreach ( $def['negative'] ?? array() as $kw ) {
				$wpdb->insert( $table, array(
					'category_slug' => $slug,
					'keyword' => mb_strtolower( trim( $kw ) ),
					'score' => -150,
					'type' => 'negative',
					'created_at' => $now,
					'updated_at' => $now,
				) );
				$inserted++;
			}
		}

		// افزودن الگوهای ترکیبی
		$combines = array(
			array( 'business + card', 100, 'business-card' ),
			array( 'text + effect', 100, 'text-effect' ),
			array( 'seamless + pattern', 100, 'pattern' ),
			array( 'wood + texture', 90, 'texture' ),
			array( 'transparent + background', 90, 'png' ),
			array( 'islamic + pattern', 90, 'pattern' ),
			array( 'floral + pattern', 80, 'pattern' ),
			array( 'letter + logo', 80, 'logo' ),
			array( 'minimal + logo', 80, 'logo' ),
			array( 'neon + text', 80, 'text-effect' ),
			array( '3d + text', 80, 'text-effect' ),
			array( 'flying + forest', 60, 'background' ), // برای تست ویدئوی کاربر
		);
		foreach ( $combines as $c ) {
			$wpdb->insert( $table, array(
				'category_slug' => $c[2],
				'keyword' => $c[0],
				'score' => $c[1],
				'type' => 'combined',
				'created_at' => $now,
				'updated_at' => $now,
			) );
		}

		return $inserted;
	}

	/* ==================== موتور تشخیص ==================== */

	/**
	 * نرمال‌سازی عنوان
	 */
	public static function normalize_title( $title ) {
		$title = mb_strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9\x{0600}-\x{06FF}\s#\-+]/u', ' ', $title );
		$title = preg_replace( '/\s+/', ' ', $title );
		return trim( $title );
	}

	public static function tokenize( $title ) {
		$norm = self::normalize_title( $title );
		$tokens = preg_split( '/[\s\-_]+/', $norm );
		return array_values( array_filter( $tokens, function( $t ){ return mb_strlen( $t ) >= 2; } ) );
	}

	/**
	 * تشخیص اصلی — ورودی عنوان + هشتگ‌ها + نوع فایل
	 * خروجی: main_category, format_category, confidence, matched_keywords, scores
	 */
	public static function detect( $title, $file_type = '', $tags = '' ) {
		$start = microtime( true );

		$combined_text = trim( (string) $title . ' ' . (string) $tags . ' ' . (string) $file_type );
		$norm_title = self::normalize_title( $combined_text );
		$tokens = self::tokenize( $combined_text );

		// دریافت دسته‌ها و کلمات کلیدی
		$categories_def = self::get_main_categories_definition();
		$format_def = self::get_format_categories_definition();

		// اگر جدول کلمات پر است، از دیتابیس بخوان و merge کن
		$db_keywords = self::get_all_keywords_grouped();

		$scores = array(); // slug => score
		$matched = array(); // slug => [keywords]
		foreach ( $categories_def as $slug => $def ) {
			$scores[ $slug ] = 0;
			$matched[ $slug ] = array();
		}

		// مرحله ۱: قوانین قطعی (exact)
		// مرحله ۲: کلمات کلیدی
		// مرحله ۳: مترادف‌ها (alias)
		// همه در یک حلقه با امتیاز
		foreach ( $categories_def as $slug => $def ) {
			// کلمات از تعریف هاردکد + از دیتابیس
			$all_kw = array();
			// از تعریف
			foreach ( array( 'primary','secondary','object','strong','combined','negative' ) as $field ) {
				if ( ! empty( $def[ $field ] ) ) {
					foreach ( $def[ $field ] as $kw ) {
						$score = 70;
						if ( 'primary' === $field ) $score = 100;
						if ( 'secondary' === $field ) $score = 70;
						if ( 'object' === $field || 'strong' === $field ) $score = 50;
						if ( 'combined' === $field ) $score = 90;
						if ( 'negative' === $field ) $score = -150;
						$all_kw[] = array( 'keyword'=>mb_strtolower($kw), 'score'=>$score, 'type'=>$field );
					}
				}
			}
			// از دیتابیس
			if ( ! empty( $db_keywords[ $slug ] ) ) {
				foreach ( $db_keywords[ $slug ] as $dbkw ) {
					$all_kw[] = $dbkw;
				}
			}

			/* ── رفع باگ دو‌برابر شمردن امتیاز ──
			 * seed_initial_dictionary() همان کلمات این آرایه‌ی هاردکد را عیناً در
			 * دیتابیس هم ذخیره می‌کند. بدون این حذف تکراری، هر کلمه یک‌بار از آرایه‌ی
			 * هاردکد و یک‌بار از دیتابیس شمرده می‌شد و همه‌ی امتیازها (و در نتیجه
			 * Confidence٪) دقیقاً دو برابر واقعی نمایش داده می‌شد. اگر مدیر از پنل
			 * امتیاز یک کلمه را ویرایش کرده باشد، نسخه‌ی دیتابیس (که آخر پیمایش
			 * می‌آید و 'id' دارد) برنده می‌شود.
			 */
			$dedup = array();
			foreach ( $all_kw as $kw_data ) {
				$key = trim( (string) ( $kw_data['keyword'] ?? '' ) );
				if ( '' === $key ) { continue; }
				if ( ! isset( $dedup[ $key ] ) || isset( $kw_data['id'] ) ) {
					$dedup[ $key ] = $kw_data;
				}
			}
			$all_kw = array_values( $dedup );

			foreach ( $all_kw as $kw_data ) {
				$kw = $kw_data['keyword'] ?? $kw_data['keyword'] ?? '';
				$score = (int) ($kw_data['score'] ?? 70);
				$type = $kw_data['type'] ?? 'normal';
				if ( '' === $kw ) continue;

				// بررسی تطبیق
				$found = false;

				// الگوی ترکیبی مثل "business + card"
				if ( strpos( $kw, '+' ) !== false ) {
					$parts = array_map( 'trim', explode( '+', $kw ) );
					$all_found = true;
					foreach ( $parts as $p ) {
						if ( false === mb_strpos( $norm_title, $p ) ) {
							$all_found = false;
							break;
						}
					}
					if ( $all_found ) {
						$found = true;
					}
				} else {
					// تطبیق دقیق عبارت
					if ( false !== mb_strpos( $norm_title, $kw ) ) {
						$found = true;
					} else {
						// تطبیق تک‌توکن‌ها برای کلمات کوتاه
						if ( mb_strlen( $kw ) >= 3 ) {
							foreach ( $tokens as $tok ) {
								if ( $tok === $kw || ( mb_strlen( $kw ) >= 4 && false !== mb_strpos( $tok, $kw ) ) ) {
									$found = true;
									break;
								}
							}
						}
					}
				}

				if ( $found ) {
					$scores[ $slug ] += $score;
					$matched[ $slug ][] = array( 'keyword'=>$kw, 'score'=>$score, 'type'=>$type );
				}
			}
		}

		// مرحله ۴: بررسی کلمات منفی — اگر کلمه منفی پیدا شد، امتیاز منفی بزرگ می‌دهد (قبلاً اضافه شده)

		// مرحله ۵: اعمال اولویت و انتخاب بهترین دسته
		// مرتب‌سازی بر اساس امتیاز نزولی، سپس اولویت صعودی (عدد کمتر اولویت بیشتر)
		$ranked = array();
		foreach ( $scores as $slug => $score ) {
			$ranked[] = array(
				'slug' => $slug,
				'score' => $score,
				'priority' => $categories_def[ $slug ]['priority'] ?? 999,
				'label' => $categories_def[ $slug ]['label'] ?? $slug,
			);
		}
		usort( $ranked, function( $a, $b ){
			if ( $a['score'] === $b['score'] ) {
				return $a['priority'] <=> $b['priority'];
			}
			return $b['score'] <=> $a['score'];
		});

		$best = $ranked[0] ?? null;
		$confidence = 0;
		$main_category = null;
		$main_label = null;

		if ( $best && $best['score'] > 0 ) {
			$main_category = $best['slug'];
			$main_label = $best['label'];
			// محاسبه confidence: بر اساس امتیاز نسبت به حداکثر تئوری یا ساده
			// اگر امتیاز >=100 → 90%+, اگر 70-99 → 70-89%, اگر 50-69 → 50-69%
			$score = $best['score'];
			if ( $score >= 150 ) $confidence = 95;
			elseif ( $score >= 100 ) $confidence = 90;
			elseif ( $score >= 70 ) $confidence = 75;
			elseif ( $score >= 50 ) $confidence = 60;
			elseif ( $score >= 30 ) $confidence = 45;
			else $confidence = 30;

			// اگر دسته دوم هم امتیاز نزدیک دارد، confidence کم می‌شود (ابهام)
			if ( isset( $ranked[1] ) && $ranked[1]['score'] > 0 ) {
				$diff = $best['score'] - $ranked[1]['score'];
				if ( $diff < 20 ) {
					$confidence -= 15;
				}
			}
			$confidence = max( 10, min( 98, $confidence ) );
		}

		// تشخیص فرمت جداگانه (قانون طلایی: فرمت هرگز دسته اصلی نیست)
		$format_category = self::detect_format( $file_type );

		// لاگ
		$elapsed = microtime( true ) - $start;
		self::log_detection( $title, $file_type, $main_category, $format_category, $confidence, $matched, $elapsed );

		$result = array(
			'main_category' => $main_category,
			'main_label' => $main_label,
			'format_category' => $format_category,
			'confidence' => $confidence,
			'matched_keywords' => $matched[ $main_category ] ?? array(),
			'all_scores' => $ranked,
			'elapsed_ms' => round( $elapsed * 1000, 2 ),
		);

		/*
		 * v7 — داور هوشمند: اگر قانون‌ها نتیجه نداشتند، اطمینان پایین بود یا دو دسته
		 * امتیاز نزدیک داشتند، هوش مصنوعی دسته را انتخاب می‌کند و نتیجه یاد گرفته
		 * می‌شود. این تنها تغییری است که دقت تشخیص را عملاً به سقف می‌رساند.
		 */
		if ( class_exists( 'STI_AutoCat_Pro' ) ) {
			$result = STI_AutoCat_Pro::refine( $result, $title, $file_type );
		}

		return $result;
	}

	public static function detect_format( $file_type ) {
		$file_type = mb_strtolower( (string) $file_type );
		if ( '' === $file_type ) return null;

		$map = array(
			'vector' => array( 'vector', 'ai', 'eps', 'svg', 'cdr' ),
			'psd' => array( 'psd', 'photoshop' ),
			'photo' => array( 'photo', 'jpg', 'jpeg' ),
			'png-format' => array( 'png' ),
			'motion' => array( 'mp4', 'mov', 'avi', 'mkv', 'video', 'motion', 'after effects', 'premiere', 'aep' ),
			'3d' => array( '3d', 'blender', 'fbx', 'obj', 'c4d' ),
		);
		foreach ( $map as $slug => $keywords ) {
			foreach ( $keywords as $kw ) {
				if ( false !== mb_strpos( $file_type, $kw ) ) {
					return $slug;
				}
			}
		}
		return null;
	}

	/* ==================== دیتابیس کمکی ==================== */

	public static function get_all_keywords_grouped() {
		global $wpdb;
		$table = self::table_keywords();
		$rows = $wpdb->get_results( "SELECT category_slug, keyword, score, type FROM {$table} ORDER BY category_slug, score DESC", ARRAY_A );
		$grouped = array();
		foreach ( $rows as $r ) {
			$slug = $r['category_slug'];
			if ( ! isset( $grouped[ $slug ] ) ) $grouped[ $slug ] = array();
			$grouped[ $slug ][] = $r;
		}
		return $grouped;
	}

	public static function add_keyword( $category_slug, $keyword, $score = 70, $type = 'normal' ) {
		global $wpdb;
		$table = self::table_keywords();
		$now = current_time( 'mysql' );
		return $wpdb->insert( $table, array(
			'category_slug' => sanitize_title( $category_slug ),
			'keyword' => mb_strtolower( trim( $keyword ) ),
			'score' => (int) $score,
			'type' => sanitize_key( $type ),
			'created_at' => $now,
			'updated_at' => $now,
		) );
	}

	public static function delete_keyword( $id ) {
		global $wpdb;
		$table = self::table_keywords();
		return $wpdb->delete( $table, array( 'id' => (int) $id ) );
	}

	public static function get_keywords( $category_slug = '', $search = '', $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table = self::table_keywords();
		$where = array( '1=1' );
		$params = array();
		if ( $category_slug ) {
			$where[] = 'category_slug = %s';
			$params[] = $category_slug;
		}
		if ( $search ) {
			$where[] = 'keyword LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$where_sql = implode( ' AND ', $where );
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );
		$offset = ( $page - 1 ) * $per_page;
		$params[] = $per_page;
		$params[] = $offset;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY score DESC, id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A );
		return array( 'rows'=>$rows, 'total'=>(int)$total );
	}

	public static function log_detection( $title, $file_type, $detected, $format, $confidence, $matched, $elapsed ) {
		global $wpdb;
		$table = self::table_logs();
		$wpdb->insert( $table, array(
			'title' => mb_substr( (string) $title, 0, 500 ),
			'file_type' => mb_substr( (string) $file_type, 0, 80 ),
			'detected_category' => $detected,
			'format_category' => $format,
			'confidence' => $confidence,
			'matched_keywords' => wp_json_encode( $matched, JSON_UNESCAPED_UNICODE ),
			'created_at' => current_time( 'mysql' ),
		) );
	}

	/* ==================== یادگیری ==================== */

	public static function log_correction( $title, $detected_category, $correct_category ) {
		global $wpdb;
		$table = self::table_learning();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE title = %s AND detected_category = %s AND correct_category = %s", $title, $detected_category, $correct_category ) );
		if ( $existing ) {
			$wpdb->update( $table, array( 'count' => (int)$existing->count + 1, 'last_updated' => current_time( 'mysql' ) ), array( 'id' => $existing->id ) );
		} else {
			$wpdb->insert( $table, array(
				'title' => mb_substr( $title, 0, 500 ),
				'detected_category' => $detected_category,
				'correct_category' => $correct_category,
				'count' => 1,
				'last_updated' => current_time( 'mysql' ),
			) );
		}

		// اگر یک اصلاح ۳ بار تکرار شد، پیشنهاد قانون جدید
		$count = $existing ? (int)$existing->count + 1 : 1;
		if ( $count >= 3 ) {
			// می‌توانیم خودکار کلمه کلیدی اضافه کنیم یا فقط لاگ کنیم
			// برای نسخه اول، فقط لاگ
			STI_Logger::info( "AutoCat learning: '{$title}' بارها از {$detected_category} به {$correct_category} اصلاح شد — پیشنهاد افزودن قانون جدید." );
		}
	}

	public static function get_learning_suggestions( $min_count = 3 ) {
		global $wpdb;
		$table = self::table_learning();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE count >= %d ORDER BY count DESC, last_updated DESC LIMIT 50", $min_count ), ARRAY_A );
	}

	/* ==================== نگاشت به ووکامرس دسته‌ها ==================== */

	public static function map_slug_to_wc_category_id( $slug ) {
		if ( ! $slug ) return 0;
		$all = STI_Category::get_all();
		$slug_low = mb_strtolower( $slug );
		$slug_norm = sanitize_title( $slug_low );

		// اول دقیق
		foreach ( $all as $cat ) {
			$label = mb_strtolower( $cat->telegram_label );
			$key = mb_strtolower( $cat->folder_key ?? '' );
			if ( $label === $slug_low || $key === $slug_low || $key === $slug_norm ) {
				return (int) $cat->id;
			}
		}
		// بعد ناقص
		foreach ( $all as $cat ) {
			$label = mb_strtolower( $cat->telegram_label );
			$key = mb_strtolower( $cat->folder_key ?? '' );
			if ( false !== mb_strpos( $label, $slug_low ) || false !== mb_strpos( $slug_low, $label ) ) {
				return (int) $cat->id;
			}
			if ( $key && ( false !== mb_strpos( $key, $slug_norm ) || false !== mb_strpos( $slug_norm, $key ) ) ) {
				return (int) $cat->id;
			}
		}
		return 0;
	}

	/* ==================== تست زنده ==================== */

	public static function live_test( $title, $file_type = '' ) {
		return self::detect( $title, $file_type );
	}
}
