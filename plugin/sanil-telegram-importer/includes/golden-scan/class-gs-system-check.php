<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — System Check.
 *
 * تمام بررسی‌هایی که تا امروز باید با phpMyAdmin و Console انجام می‌شد،
 * اینجا داخل خود پنل اجرا می‌شوند. کاربر هیچ SQL ای نمی‌نویسد.
 *
 * فقط می‌خواند و گزارش می‌دهد — هیچ چیزی را تغییر نمی‌دهد.
 */
class STI_GS_System_Check {

	const PASS = 'pass';
	const WARN = 'warn';
	const FAIL = 'fail';

	const RESULT_OPTION = 'sti_gs_last_system_check';

	/** @return array<int, array{group:string, label:string, status:string, detail:string}> */
	public static function run() {
		$checks = array_merge(
			self::check_environment(),
			self::check_migration(),
			self::check_tables(),
			self::check_columns(),
			self::check_classes(),
			self::check_routes(),
			self::check_cron(),
			self::check_lock(),
			self::check_mtproto(),
			self::check_category_mapping()
		);

		update_option( self::RESULT_OPTION, array(
			'at'      => current_time( 'mysql' ),
			'summary' => self::summarize( $checks ),
		), false );

		return $checks;
	}

	public static function summarize( $checks ) {
		$out = array( self::PASS => 0, self::WARN => 0, self::FAIL => 0 );
		foreach ( $checks as $c ) {
			$out[ $c['status'] ] = ( $out[ $c['status'] ] ?? 0 ) + 1;
		}
		return $out;
	}

	public static function has_failure( $checks ) {
		foreach ( $checks as $c ) {
			if ( self::FAIL === $c['status'] ) {
				return true;
			}
		}
		return false;
	}

	protected static function row( $group, $label, $status, $detail = '' ) {
		return compact( 'group', 'label', 'status', 'detail' );
	}

	/* ================================ بررسی‌ها ================================ */

	protected static function check_environment() {
		global $wp_version, $wpdb;
		$out = array();

		// چند بار نشد تشخیص داد کدام نسخه روی سایت نصب است و همان باعث
		// تحلیل اشتباه شد. حالا نسخه اولین چیزی است که دیده می‌شود.
		$out[] = self::row( 'محیط', 'نسخه افزونه', self::PASS,
			defined( 'STI_VERSION' ) ? STI_VERSION : 'نامشخص' );

		$php_ok = version_compare( PHP_VERSION, '7.4', '>=' );
		$out[] = self::row( 'محیط', 'نسخه PHP', $php_ok ? self::PASS : self::FAIL,
			PHP_VERSION . ( $php_ok ? '' : ' — حداقل ۷.۴ لازم است' ) );

		$wp_ok = version_compare( $wp_version, '5.8', '>=' );
		$out[] = self::row( 'محیط', 'نسخه وردپرس', $wp_ok ? self::PASS : self::WARN, $wp_version );

		/**
		 * حالت ایمن چند بار بی‌سروصدا روشن شد و ماژول‌های حیاتی را خاموش
		 * کرد — از جمله صندوق ورودی ربات. از بیرون شبیه «ربات جواب نداد»
		 * دیده می‌شد. حالا اولین چیزی است که در بررسی سیستم می‌بینید.
		 */
		$safe = function_exists( 'sti_v7_safe_mode' ) && sti_v7_safe_mode();
		$out[] = self::row( 'محیط', 'حالت ایمن افزونه', $safe ? self::FAIL : self::PASS,
			$safe
				? 'روشن است — صندوق ورودی ربات، AI و موتور عنوان بارگذاری نشده‌اند. از نوار بالای پنل خاموشش کنید.'
				: 'خاموش (همه‌ی ماژول‌ها فعال‌اند)' );

		$out[] = self::row( 'محیط', 'صندوق ورودی ربات', class_exists( 'STI_Bot_Inbox' ) ? self::PASS : self::FAIL,
			class_exists( 'STI_Bot_Inbox' ) ? 'در دسترس' : 'بارگذاری نشده — هیچ فایلی از ربات ثبت نمی‌شود' );

		$out[] = self::row( 'محیط', 'ووکامرس', class_exists( 'WooCommerce' ) ? self::PASS : self::FAIL,
			class_exists( 'WooCommerce' ) ? 'فعال' : 'فعال نیست — ساخت محصول ممکن نخواهد بود' );

		$db_version = $wpdb->get_var( 'SELECT VERSION()' );
		$out[] = self::row( 'محیط', 'نسخه دیتابیس', self::PASS, (string) $db_version );

		// این سه، مستقیماً روی تولید عنوان و توضیحات فارسی اثر دارند.
		$pcre_u = (bool) @preg_match( '/^./u', 'a' );
		$out[] = self::row( 'محیط', 'پشتیبانی PCRE از UTF-8', $pcre_u ? self::PASS : self::FAIL,
			$pcre_u ? 'فعال' : 'غیرفعال — تمام الگوهای /u شکست می‌خورند و عنوان فارسی ساخته نمی‌شود' );

		$out[] = self::row( 'محیط', 'افزونه mbstring', function_exists( 'mb_convert_encoding' ) ? self::PASS : self::WARN,
			function_exists( 'mb_convert_encoding' ) ? 'نصب است' : 'نصب نیست — پاک‌سازی متن فارسی ضعیف‌تر می‌شود' );

		$out[] = self::row( 'محیط', 'افزونه iconv', function_exists( 'iconv' ) ? self::PASS : self::WARN,
			function_exists( 'iconv' ) ? 'نصب است' : 'نصب نیست' );

		$mem = (string) ini_get( 'memory_limit' );
		$mem_bytes = wp_convert_hr_to_bytes( $mem );
		$out[] = self::row( 'محیط', 'حافظه PHP', $mem_bytes >= 256 * MB_IN_BYTES ? self::PASS : self::WARN,
			$mem . ( $mem_bytes >= 256 * MB_IN_BYTES ? '' : ' — برای اسکن‌های بزرگ ۲۵۶M توصیه می‌شود' ) );

		return $out;
	}

	protected static function check_migration() {
		$out = array();
		$status = STI_GS_DB::migration_status();

		$version_ok = $status['current_version'] === $status['expected_version'];
		$out[] = self::row( 'مهاجرت', 'نسخه Schema', $version_ok ? self::PASS : self::FAIL,
			$version_ok
				? $status['current_version']
				: sprintf( 'نسخه فعلی %s است ولی %s انتظار می‌رود — مهاجرت ناتمام مانده.',
					$status['current_version'] ?: '—', $status['expected_version'] ) );

		$problem = $status['problem'];
		$out[] = self::row( 'مهاجرت', 'خطای مهاجرت', '' === $problem ? self::PASS : self::FAIL,
			'' === $problem ? 'هیچ خطایی ثبت نشده' : $problem );

		$halted = STI_GS_DB::is_halted();
		$out[] = self::row( 'مهاجرت', 'توقف اضطراری', $halted ? self::FAIL : self::PASS,
			$halted ? STI_GS_DB::halt_reason() : 'غیرفعال' );

		$out[] = self::row( 'مهاجرت', 'جدول Pipeline', self::PASS, $status['pipeline_table'] );

		return $out;
	}

	protected static function required_tables() {
		return array(
			STI_GS_DB::channels_table()        => 'کانال‌ها',
			STI_GS_DB::messages_table()        => 'Inventory',
			STI_GS_DB::profiles_table()        => 'پروفایل‌ها',
			STI_GS_DB::profile_items_table()   => 'Candidateها',
			STI_GS_DB::scan_runs_table()       => 'Scan Runها',
			STI_GS_DB::pipeline_items_table()  => 'Pipeline Itemها',
			STI_GS_DB::session_events_table()  => 'رویدادها',
			STI_GS_DB::artifacts_table()       => 'Artifactها',
			STI_GS_DB::bot_candidates_table()  => 'Bot Candidateها',
			STI_GS_DB::scan_segments_table()   => 'بخش‌های اسکن',
		);
	}

	protected static function check_tables() {
		global $wpdb;
		$out = array();
		foreach ( self::required_tables() as $table => $label ) {
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$detail = $table;
			if ( $exists ) {
				$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
				$detail .= sprintf( ' — %s ردیف', number_format_i18n( $rows ) );
			} else {
				$detail .= ' — یافت نشد';
			}
			$out[] = self::row( 'جدول‌ها', $label, $exists ? self::PASS : self::FAIL, $detail );
		}

		// جدول قدیمی نباید باقی مانده باشد.
		$legacy = $wpdb->prefix . 'sti_gs_sessions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) ) {
			$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$legacy}`" );
			$out[] = self::row( 'جدول‌ها', 'جدول قدیمی sti_gs_sessions',
				$rows > 0 ? self::FAIL : self::WARN,
				$rows > 0
					? sprintf( 'هنوز %s ردیف دارد — وضعیت مبهم، ادغام دستی لازم است.', number_format_i18n( $rows ) )
					: 'خالی است و می‌تواند نادیده گرفته شود.' );
		}

		return $out;
	}

	protected static function check_columns() {
		global $wpdb;
		$out = array();

		$required = array(
			STI_GS_DB::messages_table() => array(
				'telegram_document_id', 'photo_file_id', 'video_file_id',
				'correlation_key', 'scan_run_id', 'updated_at', 'normalized_text',
			),
			STI_GS_DB::profile_items_table() => array( 'score', 'confidence', 'match_reason' ),
			STI_GS_DB::scan_runs_table()     => array( 'processed_messages', 'inserted_messages', 'duplicate_messages' ),
			STI_GS_DB::pipeline_items_table()=> array( 'match_breakdown', 'match_score', 'matched_inbox_id' ),
		);

		foreach ( $required as $table => $columns ) {
			$present = $wpdb->get_col( $wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = %s AND table_name = %s',
				DB_NAME, $table
			) );
			$missing = array_values( array_diff( $columns, (array) $present ) );
			$short = str_replace( $wpdb->prefix, '', $table );
			$out[] = self::row( 'ستون‌ها', $short,
				empty( $missing ) ? self::PASS : self::FAIL,
				empty( $missing )
					? sprintf( 'هر %d ستون موجود است', count( $columns ) )
					: 'ستون‌های جاافتاده: ' . implode( '، ', $missing ) );
		}

		return $out;
	}

	protected static function check_classes() {
		$classes = array(
			'STI_GS_DB'          => 'لایه دیتابیس',
			'STI_GS_Scanner'     => 'اسکنر',
			'STI_GS_Scan_Run'    => 'Scan Run',
			'STI_GS_Media_Ids'   => 'شناسه رسانه',
			'STI_GS_Correlation' => 'Correlation',
			'STI_GS_Confidence'  => 'Confidence',
			'STI_GS_File_Matcher'=> 'File Matcher',
			'STI_GS_Channel'     => 'کانال',
			'STI_GS_Profile'     => 'پروفایل',
			'STI_GS_Session'     => 'Pipeline Item',
			'STI_MTProto'        => 'MTProto',
			'STI_Logger'         => 'لاگر',
		);
		$out = array();
		foreach ( $classes as $class => $label ) {
			$ok = class_exists( $class );
			$out[] = self::row( 'کلاس‌ها', $label, $ok ? self::PASS : self::FAIL,
				$ok ? $class : $class . ' بارگذاری نشده — احتمالاً require در فایل اصلی افزونه جا افتاده' );
		}
		return $out;
	}

	protected static function required_routes() {
		return array(
			'sti_gs_channel_list', 'sti_gs_channel_add', 'sti_gs_scan_start',
			'sti_gs_scan_repeat_run', 'sti_gs_scan_pause', 'sti_gs_scan_poll',
			'sti_gs_wizard_step', 'sti_gs_system_check',
		);
	}

	protected static function check_routes() {
		$out = array();
		foreach ( self::required_routes() as $route ) {
			$ok = has_action( 'wp_ajax_' . $route );
			$out[] = self::row( 'مسیرهای AJAX', $route, $ok ? self::PASS : self::FAIL,
				$ok ? 'ثبت شده' : 'ثبت نشده' );
		}
		return $out;
	}

	protected static function check_cron() {
		$out = array();

		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$out[] = self::row( 'کران', 'WP-Cron', $disabled ? self::WARN : self::PASS,
			$disabled
				? 'غیرفعال است. اگر کران سیستمی تنظیم نشده باشد، اسکن پس از شروع ادامه پیدا نمی‌کند.'
				: 'فعال' );

		$scheduled = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( $hook, 'sti_gs_' ) ) {
					$scheduled++;
				}
			}
		}
		$out[] = self::row( 'کران', 'کارهای زمان‌بندی‌شده گلدن اسکن', self::PASS,
			$scheduled . ' مورد در صف' );

		return $out;
	}

	protected static function check_lock() {
		global $wpdb;
		$out = array();

		$name = 'sti_gs_selftest_' . wp_generate_password( 6, false );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 0 ) );

		if ( '1' === (string) $got ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$out[] = self::row( 'قفل', 'GET_LOCK دیتابیس', self::PASS, 'در دسترس است' );
		} else {
			$out[] = self::row( 'قفل', 'GET_LOCK دیتابیس', self::WARN,
				'در دسترس نیست — سیستم به قفل جایگزین transient برمی‌گردد.' );
		}

		$stuck = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . STI_GS_DB::pipeline_items_table() . ' WHERE locked_until IS NOT NULL AND locked_until > %s',
			current_time( 'mysql' )
		) );
		$out[] = self::row( 'قفل', 'Pipeline Itemهای قفل‌شده', $stuck > 20 ? self::WARN : self::PASS,
			$stuck . ' مورد' );

		return $out;
	}

	/**
	 * هر پروفایل یک دسته‌بندی ووکامرس انتخاب می‌کند؛ اگر آن دسته در جدول
	 * دسته‌بندی‌های افزونه نگاشت نشده باشد، محصول بدون قیمت ساخته می‌شود.
	 * بهتر است این را قبل از اسکن انبوه بدانیم، نه بعد از ۶۰٬۰۰۰ محصول.
	 */
	protected static function check_category_mapping() {
		global $wpdb;
		$out = array();

		if ( ! class_exists( 'STI_GS_DB' ) || ! class_exists( 'STI_Category' ) ) {
			return $out;
		}

		$profiles = $wpdb->get_results(
			'SELECT name, default_category_id FROM ' . STI_GS_DB::profiles_table()
			. ' WHERE default_category_id IS NOT NULL AND default_category_id > 0',
			ARRAY_A
		);

		if ( empty( $profiles ) ) {
			$out[] = self::row( 'دسته‌بندی', 'نگاشت دسته‌ی پروفایل‌ها', self::WARN,
				'هیچ پروفایلی دسته‌بندی پیش‌فرض ندارد — محصولات بدون قیمت ساخته می‌شوند.' );
			return $out;
		}

		foreach ( $profiles as $profile ) {
			$term_id = (int) $profile['default_category_id'];
			$term    = get_term( $term_id, 'product_cat' );
			$name    = ( $term && ! is_wp_error( $term ) ) ? $term->name : '؟';

			$price = $wpdb->get_var( $wpdb->prepare(
				'SELECT price FROM ' . STI_Category::table() . ' WHERE woo_term_id = %d LIMIT 1',
				$term_id
			) );

			$mapped = ( null !== $price );
			$out[] = self::row( 'دسته‌بندی', 'پروفایل «' . $profile['name'] . '»',
				$mapped ? self::PASS : self::WARN,
				$mapped
					? sprintf( '%s (#%d) — قیمت %s', $name, $term_id, number_format_i18n( (float) $price ) )
					: sprintf( '%s (#%d) در «دسته‌بندی‌ها» نگاشت نشده — محصول بدون قیمت ساخته می‌شود.', $name, $term_id )
			);
		}

		return $out;
	}

	protected static function check_mtproto() {
		$out = array();

		if ( ! class_exists( 'STI_MTProto' ) ) {
			return array( self::row( 'تلگرام', 'MTProto', self::FAIL, 'کلاس بارگذاری نشده' ) );
		}

		// نسخه‌ی قبلی is_connected()/is_authorized() را صدا می‌زد که اصلاً
		// وجود ندارند، پس همیشه «متصل نیست» گزارش می‌شد حتی وقتی اکانت
		// واقعاً وارد شده بود. متد درست auth_state() است.
		$configured = STI_MTProto::is_configured();
		$out[] = self::row( 'تلگرام', 'تنظیمات MTProto', $configured ? self::PASS : self::FAIL,
			$configured ? 'api_id و api_hash و شماره ثبت شده‌اند' : 'ناقص است — «تنظیمات تلگرام» را کامل کنید' );

		if ( ! $configured ) {
			return $out;
		}

		$state = method_exists( 'STI_MTProto', 'auth_state' )
			? (string) STI_MTProto::instance()->auth_state()
			: 'unknown';

		$labels = array(
			'logged_in'     => array( self::PASS, 'وارد شده‌اید' ),
			'awaiting_code' => array( self::WARN, 'منتظر کد ورود — از «تنظیمات تلگرام» ورود را کامل کنید' ),
			'not_logged'    => array( self::WARN, 'وارد نشده‌اید — بدون آن اسکن اجرا نمی‌شود' ),
			'error'         => array( self::FAIL, 'خطا در بررسی وضعیت — بخش گزارش‌ها را ببینید' ),
		);
		$verdict = $labels[ $state ] ?? array( self::WARN, 'وضعیت نامشخص: ' . $state );

		$out[] = self::row( 'تلگرام', 'ورود اکانت', $verdict[0], $verdict[1] );

		return $out;
	}
}
