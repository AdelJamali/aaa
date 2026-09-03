<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Channel Watcher.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * حلقه‌ی گمشده
 *
 * تا امروز همه‌ی قطعات وجود داشتند ولی زنجیره وصل نبود:
 *
 *   اسکن کانال      →  دستی (دکمه)
 *   اجرای پروفایل   →  دستی (دکمه)
 *   ساخت Session    →  دستی (انتخاب از فهرست)
 *   پردازش          →  ✅ خودکار (Auto Worker)
 *   انتشار          →  ✅ خودکار (Publish Queue)
 *
 * شاهدش در آمار خودتان: ۱۴٬۴۵۳ پیام، ۵٬۷۸۵ Candidate، ولی فقط ۷۷ Session.
 * یعنی ۵٬۷۰۸ فرصت پردازش‌نشده مانده بود.
 *
 * این کلاس همان سه قدم دستی را خودکار می‌کند.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * سه محافظ، چون مقیاس ۶۰٬۰۰۰ فایلی است
 *
 * ۱. **سقف روزانه‌ی Session.** بدون آن، اولین اجرا ۵٬۷۸۵ Session می‌سازد و
 *    صف منفجر می‌شود. سرعت ورود باید کمتر از سرعت خروج باشد، وگرنه صف هر
 *    روز بزرگ‌تر می‌شود.
 *
 * ۲. **فشار معکوس (backpressure).** اگر تعداد Sessionهای ناتمام از آستانه
 *    بگذرد، ساخت Session تازه متوقف می‌شود تا صف خالی شود. این از سقف
 *    روزانه مهم‌تر است چون به وضعیت واقعی سیستم واکنش نشان می‌دهد، نه به
 *    یک عدد ثابت.
 *
 * ۳. **سقف روزانه‌ی اسکن.** جدا از سقف Session، چون اسکن و پردازش دو
 *    مصرف‌کننده‌ی متفاوت‌اند.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Channel_Watcher {

	const HOOK          = 'sti_gs_channel_watcher';
	const ENABLED_KEY   = 'sti_gs_watcher_enabled';
	const STATS_KEY     = 'sti_gs_watcher_stats';
	const LAST_RUN_KEY  = 'sti_gs_watcher_last_run';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );

		/**
		 * زمان‌بندی فقط در admin یا کران بررسی می‌شود.
		 *
		 * `init()` روی **هر درخواست** اجرا می‌شود — از جمله بازدید هر
		 * بازدیدکننده‌ی سایت. چهار ماژول × دو فراخوانی
		 * (`wp_next_scheduled` + `get_option`) یعنی هشت پرس‌وجوی اضافه روی
		 * هر بارگذاری صفحه، حتی وقتی همه‌ی قابلیت‌ها خاموش‌اند.
		 *
		 * هوک همیشه ثبت می‌شود (ارزان است و کران به آن نیاز دارد)، ولی
		 * بررسی زمان‌بندی فقط جایی انجام می‌شود که واقعاً لازم است.
		 */
		if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}


		if ( ! wp_next_scheduled( self::HOOK ) ) {
			/**
			 * بازه‌ی واقعی، نه «هر دقیقه».
			 *
			 * WP-Cron روی هاست اشتراکی کران واقعی نیست؛ روی بارگذاری صفحه
			 * اجرا می‌شود. پنج کران دقیقه‌ای یعنی هر بازدیدکننده ممکن است
			 * یکی از آن‌ها را راه بیندازد — و همان چیزی است که سایت را
			 * چند دقیقه از دسترس خارج می‌کرد.
			 *
			 * این کران هر ۳۰ دقیقه کافی است.
			 */
			$schedules = wp_get_schedules();
			$every     = isset( $schedules['sti_gs_30min'] ) ? 'sti_gs_30min' : 'hourly';
			wp_schedule_event( time() + 240, $every, self::HOOK );
		}
	}

	/* ============================== تنظیمات ============================== */

	public static function is_enabled() {
		return (bool) get_option( self::ENABLED_KEY, 0 );
	}

	public static function set_enabled( $on ) {
		update_option( self::ENABLED_KEY, $on ? 1 : 0, true );
		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::info( 'گلدن اسکن Watcher: ' . ( $on ? 'روشن' : 'خاموش' ) . ' شد.' );
		}
		return self::is_enabled();
	}

	public static function setting( $key, $default ) {
		$v = class_exists( 'STI_Settings' ) ? STI_Settings::get( 'gs_watcher_' . $key, null ) : null;
		return ( null === $v || '' === $v ) ? $default : (int) $v;
	}

	/** فاصله‌ی بین اجراها. */
	public static function interval_seconds() { return max( 300, self::setting( 'interval', 1800 ) ); }

	/** حداکثر Session تازه در هر اجرا. */
	public static function batch_size() { return max( 1, min( 200, self::setting( 'batch', 20 ) ) ); }

	/** سقف Session تازه در روز. صفر = بی‌نهایت. */
	public static function daily_cap() { return max( 0, self::setting( 'daily_cap', 200 ) ); }

	/** اگر Sessionهای ناتمام از این بیشتر شد، ورودی تازه نگیر. */
	public static function backlog_limit() { return max( 10, self::setting( 'backlog', 100 ) ); }

	/* ============================== شمارنده ============================== */

	public static function created_today() {
		$row = get_option( self::STATS_KEY, array() );
		$day = wp_date( 'Y-m-d' );
		return ( is_array( $row ) && ( $row['day'] ?? '' ) === $day ) ? (int) $row['created'] : 0;
	}

	protected static function record( $created, $note = '' ) {
		$day = wp_date( 'Y-m-d' );
		$row = get_option( self::STATS_KEY, array() );
		if ( ! is_array( $row ) || ( $row['day'] ?? '' ) !== $day ) {
			$row = array( 'day' => $day, 'created' => 0, 'runs' => 0 );
		}
		$row['created'] = (int) $row['created'] + max( 0, (int) $created );
		$row['runs']    = (int) $row['runs'] + 1;
		$row['last']    = current_time( 'mysql' );
		if ( '' !== $note ) { $row['note'] = $note; }
		update_option( self::STATS_KEY, $row, false );
	}

	/** تعداد Sessionهای ناتمام — پایه‌ی فشار معکوس. */
	public static function backlog() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();

		$done = array( 'REVIEW_READY', 'PUBLISHED', 'SKIPPED', 'NEEDS_REVIEW', 'DEAD_LETTER', 'ERROR_FILE_NOT_FOUND' );
		$place = implode( ',', array_fill( 0, count( $done ), '%s' ) );

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE state NOT IN ({$place})", $done
		) );
	}

	/* =============================== اجرا =============================== */

	public static function tick() {
		if ( ! self::is_enabled() ) {
			return;
		}

		/*
		 * ۱۰.۹.۳ — نگهبان اتمیک: خواندن/مقایسه/نوشتن قدیمی (TOCTOU)
		 * جایگزین شد؛ دو درخواست هم‌زمان دیگر هر دو نمی‌توانند رد شوند.
		 */
		if ( class_exists( 'STI_GS_Cron_Gate' )
			&& ! STI_GS_Cron_Gate::pass( 'watcher', self::interval_seconds() ) ) {
			return;
		}
		update_option( self::LAST_RUN_KEY, time(), false );

		if ( function_exists( 'sti_v7_safe_mode' ) && sti_v7_safe_mode() ) {
			return;
		}
		if ( class_exists( 'STI_GS_DB' ) && STI_GS_DB::is_halted() ) {
			return;
		}

		self::run();
	}

	/**
	 * یک چرخه‌ی کامل: اسکن → پروفایل → ساخت Session.
	 *
	 * @return array گزارش اجرا
	 */
	public static function run() {
		$report = array(
			'scanned'  => 0,
			'profiles' => 0,
			'created'  => 0,
			'skipped'  => '',
		);

		// ── فشار معکوس ────────────────────────────────────────────────
		$backlog = self::backlog();
		if ( $backlog >= self::backlog_limit() ) {
			$report['skipped'] = sprintf(
				'%d Session ناتمام در صف است (سقف %d) — ورودی تازه گرفته نشد.',
				$backlog, self::backlog_limit()
			);
			self::record( 0, $report['skipped'] );
			return $report;
		}

		// ── سقف روزانه ────────────────────────────────────────────────
		$cap  = self::daily_cap();
		$left = $cap > 0 ? max( 0, $cap - self::created_today() ) : PHP_INT_MAX;
		if ( $left < 1 ) {
			$report['skipped'] = 'سقف روزانه‌ی ساخت Session پر شده است.';
			self::record( 0, $report['skipped'] );
			return $report;
		}

		$room  = min( self::batch_size(), $left, self::backlog_limit() - $backlog );
		if ( $room < 1 ) {
			$report['skipped'] = 'ظرفیت این دور صفر است.';
			self::record( 0, $report['skipped'] );
			return $report;
		}

		// ── ۱) اسکن پیام‌های تازه ─────────────────────────────────────
		$report['scanned'] = self::scan_new_messages();

		// ── ۲) اجرای پروفایل‌ها روی Inventory ──────────────────────────
		$report['profiles'] = self::refresh_profiles();

		// ── ۳) ساخت Session از Candidateهای تازه ──────────────────────
		$report['created'] = self::create_sessions( $room );

		self::record( $report['created'] );
		return $report;
	}

	/**
	 * اسکن افزایشی کانال‌های فعال.
	 *
	 * از همان `STI_GS_Scanner` استفاده می‌شود، نه یک مسیر موازی — پس
	 * Scan Run، شمارنده‌ها و منطق Resume همه یکسان می‌مانند.
	 */
	protected static function scan_new_messages() {
		if ( ! class_exists( 'STI_GS_Channel' ) || ! class_exists( 'STI_GS_Scan_Run' ) ) {
			return 0;
		}

		/**
		 * سقف روزانه‌ی اسکن در این سورس وجود ندارد.
		 *
		 * اگر بعداً اضافه شود، همین‌جا بدون تغییر دیگری رعایت می‌شود. تا آن
		 * زمان، سقف Session و فشار معکوس نقش محدودکننده را بازی می‌کنند —
		 * که برای جلوگیری از انفجار صف کافی است، چون اسکن بدون ساخت
		 * Session فقط Inventory را پر می‌کند و بار پردازشی ندارد.
		 */
		if ( method_exists( 'STI_GS_Scan_Run', 'daily_budget_left' )
			&& STI_GS_Scan_Run::daily_budget_left() < 1 ) {
			return 0;
		}

		$started = 0;
		foreach ( (array) STI_GS_Channel::all( 20 ) as $ch ) {
			$channel_id = (int) $ch['id'];

			// اگر Run بازی هست، دست نزن — خودش ادامه دارد.
			if ( STI_GS_Scan_Run::current_for_channel( $channel_id ) ) {
				continue;
			}
			if ( STI_GS_Channel::STATUS_RUNNING === ( $ch['scan_status'] ?? '' ) ) {
				continue;
			}

			$run_id = STI_GS_Scan_Run::start( $channel_id, STI_GS_Scan_Run::MODE_FULL );
			if ( is_wp_error( $run_id ) ) {
				continue;
			}

			STI_GS_Channel::update( $channel_id, array(
				'scan_status' => STI_GS_Channel::STATUS_RUNNING,
				'last_error'  => '',
			) );

			// از همان مسیر کران اسکنر، نه اجرای درون‌خطی.
			$args = array( $channel_id );
			if ( ! wp_next_scheduled( 'sti_gs_scan_worker', $args ) ) {
				wp_schedule_single_event( time() - 1, 'sti_gs_scan_worker', $args );
			}
			$started++;
		}

		/*
		 * 10.9.3 — spawn_cron() از داخل حلقه‌ی کانال‌ها بیرون آمد.
		 *
		 * هر spawn_cron() کل صف WP-Cron را **هم‌اکنون** اجرا می‌کند —
		 * یعنی با N کانال، هر دورِ Watcher، N بار کل کران (همه‌ی crons
		 * GS + legacy) پشت‌سرهم اجرا می‌شد: هم CPU هاست اشتراکی را
		 * می‌خواباند و هم اجرای همزمانِ cronهای GS با هم را می‌ساخت
		 * (همان «فشار کران» گزارش‌شده). بیدار کردن یک‌بار، برای هر
		 * run کافی است؛ رویدادهای single-event به‌هیچ‌وجه از دست
		 * نمی‌روند.
		 */
		if ( $started > 0 && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return $started;
	}

	/**
	 * تازه‌سازی Candidateها.
	 *
	 * `STI_GS_Profile::run()` فقط یک کوئری روی Inventory است — نه تماس
	 * تلگرام، نه AI. پس اجرای مکرر آن ارزان است.
	 */
	protected static function refresh_profiles() {
		if ( ! class_exists( 'STI_GS_Profile' ) ) {
			return 0;
		}

		/**
		 * گران‌ترین کار این کلاس — و لازم نیست هر دور اجرا شود.
		 *
		 * هر پروفایل یک کوئری `LIKE` روی ۱۴٬۴۵۳ ردیف Inventory می‌زند.
		 * با ۸ پروفایل یعنی هشت اسکن کامل جدول. اگر همزمان با Worker و
		 * Watchdog اجرا شود، MySQL هاست اشتراکی را می‌خواباند — همان
		 * «چند دقیقه از دسترس خارج و بعد درست می‌شود».
		 *
		 * پروفایل‌ها فقط وقتی نتیجه‌ی تازه می‌دهند که پیام تازه‌ای آمده
		 * باشد. پس حداکثر هر ۶ ساعت، و فقط اگر Inventory رشد کرده باشد.
		 */
		$last  = (int) get_option( 'sti_gs_profiles_refreshed_at', 0 );
		$count = (int) get_option( 'sti_gs_profiles_refreshed_count', 0 );

		global $wpdb;
		$now_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . STI_GS_DB::messages_table() );

		$stale   = ( time() - $last ) > 6 * HOUR_IN_SECONDS;
		$grew    = $now_count > $count;

		if ( ! $stale && ! $grew ) {
			return 0;
		}

		update_option( 'sti_gs_profiles_refreshed_at', time(), false );
		update_option( 'sti_gs_profiles_refreshed_count', $now_count, false );

		// پروفایل‌ها به کانال وصل‌اند؛ متد سراسری all() وجود ندارد.
		$n = 0;
		foreach ( (array) STI_GS_Channel::all( 20 ) as $ch ) {
			foreach ( (array) STI_GS_Profile::all_for_channel( (int) $ch['id'] ) as $p ) {
				$res = STI_GS_Profile::run( (int) $p['id'] );
				if ( ! is_wp_error( $res ) ) {
					$n++;
				}
			}
		}
		return $n;
	}

	/**
	 * ساخت Session از Candidateهای در انتظار.
	 *
	 * فقط پروفایل‌هایی که `default_category_id` دارند — بدون دسته، محصول
	 * بی‌قیمت و بی‌دسته ساخته می‌شود که بعداً باید بازسازی شود.
	 *
	 * `create_from_profile_item()` خودش idempotent است: اگر برای آن پیام
	 * Session وجود داشته باشد همان را برمی‌گرداند.
	 */
	protected static function create_sessions( $room ) {
		global $wpdb;

		/**
		 * وضعیت درست `available` است، نه `pending`.
		 *
		 * `STI_GS_Profile::run()` هنگام ساخت Candidate مقدار `'available'`
		 * می‌نویسد، و `create_from_profile_item()` بعد از ساخت Session آن
		 * را به `'queued'` تغییر می‌دهد. کلمه‌ی `pending` هیچ‌جای این جدول
		 * وجود ندارد.
		 *
		 * پیامدش این بود که Watcher **هرگز هیچ Sessionی نمی‌ساخت** — یعنی
		 * تمام زنجیره‌ی خودکارسازی بی‌صدا بی‌اثر بود.
		 *
		 * همین نگاشت `available → queued` توضیح می‌دهد چرا پروفایل
		 * فایل‌هایی را که Session دارند «دوباره می‌آورد»: آن ردیف‌ها
		 * `queued` هستند و `ON DUPLICATE KEY UPDATE` وضعیتشان را دست
		 * نمی‌زند، پس در فهرست پروفایل باقی می‌مانند ولی دوباره پردازش
		 * نمی‌شوند.
		 */

		$items    = STI_GS_DB::profile_items_table();
		$profiles = STI_GS_DB::profiles_table();

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT pi.id
			 FROM {$items} pi
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = %s
			   AND p.default_category_id IS NOT NULL
			   AND p.default_category_id > 0
			 ORDER BY pi.id ASC
			 LIMIT %d",
			'available', max( 1, (int) $room )
		), ARRAY_A );

		$created = 0;
		foreach ( $rows as $row ) {
			$res = STI_GS_Session::create_from_profile_item( (int) $row['id'] );
			if ( ! is_wp_error( $res ) ) {
				$created++;
			}
		}

		if ( $created > 0 ) {
			STI_Logger::info( 'گلدن اسکن Watcher: ' . $created . ' Session تازه ساخته شد.' );
		}
		return $created;
	}

	/* ============================== گزارش ============================== */

	public static function stats() {
		global $wpdb;
		$items    = STI_GS_DB::profile_items_table();
		$profiles = STI_GS_DB::profiles_table();

		$ready = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items} pi
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = %s AND p.default_category_id > 0",
			'available'
		) );

		$no_category = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items} pi
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = %s AND ( p.default_category_id IS NULL OR p.default_category_id = 0 )",
			'available'
		) );

		$today = get_option( self::STATS_KEY, array() );
		$today = is_array( $today ) ? $today : array();

		return array(
			'enabled'       => self::is_enabled(),
			'interval_min'  => (int) round( self::interval_seconds() / 60 ),
			'batch'         => self::batch_size(),
			'daily_cap'     => self::daily_cap(),
			'created_today' => self::created_today(),
			'backlog'       => self::backlog(),
			'backlog_limit' => self::backlog_limit(),
			'ready'         => $ready,
			'no_category'   => $no_category,
			'note'          => (string) ( $today['note'] ?? '' ),
			'last_run'      => (string) ( $today['last'] ?? '' ),
			'next_tick'     => wp_next_scheduled( self::HOOK ),
		);
	}
}
