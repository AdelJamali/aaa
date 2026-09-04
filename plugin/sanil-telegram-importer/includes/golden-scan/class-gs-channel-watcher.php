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
	 *
	 * ۱۰.۱۲ — پارامترهای اختیاری برای «صف انتشار»:
	 *   $wc_term_id     — اگر مشخص باشد، فقط محصولات همان دسته (term_id
	 *                     ووکامرس؛ همان فضا که profile.default_category_id
	 *                     نگه می‌دارد) انتخاب می‌شوند.
	 *   $priority_order — اگر true باشد، ترتیب اولویت (score DESC) — یعنی
	 *                     «N مورد اولویت‌دار»، نه «N مورد قدیمی‌تر».
	 *   $created_ids    — آرایه‌ی اختیاری (by-reference) که آیدی هر
	 *                     Session ساخته‌شده را دریافت می‌کند.
	 * پیش‌فرض همه = رفتار دقیقاً همان قبل (مسیر cron و اکشن قدیمی دست‌نخورده).
	 */
	protected static function create_sessions( $room, $wc_term_id = null, $priority_order = false, &$created_ids = null ) {
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

		/* ۱۰.۱۲ — فیلتر دسته (اختیاری) + ترتیب اولویت (اختیاری).
		 * بدون پارامتر، کوئری کلمه‌به‌کلمه همان قبل است. */
		$cat_filter = '';
		$params     = array( 'available' );
		if ( $wc_term_id ) {
			$cat_filter = "\n\t\t\t   AND p.default_category_id = %d";
			$params[]   = (int) $wc_term_id;
		}
		$order    = $priority_order ? 'ORDER BY pi.score DESC, pi.id ASC' : 'ORDER BY pi.id ASC';
		$params[] = max( 1, (int) $room );

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT pi.id
			 FROM {$items} pi
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = %s
			   AND p.default_category_id IS NOT NULL
			   AND p.default_category_id > 0{$cat_filter}
			 {$order}
			 LIMIT %d",
			$params
		), ARRAY_A );

		$created = 0;
		$rejects = array();
		if ( ! is_array( $created_ids ) ) {
			$created_ids = array();
		}
		foreach ( $rows as $row ) {
			$res = STI_GS_Session::create_from_profile_item( (int) $row['id'] );
			if ( ! is_wp_error( $res ) ) {
				$created++;
				if ( is_numeric( $res ) ) {
					$created_ids[] = (int) $res;
				}
			} else {
				/* ۱۰.۱۱-UX+ — ردشدنی‌ها دیگر بی‌صدا نمی‌مانند.
				 * فقط لاگ است؛ رفتار و شمارش دقیقاً همان قبل. */
				$rejects[] = array( (int) $row['id'], $res->get_error_code() );
			}
		}

		if ( $created > 0 ) {
			STI_Logger::info( 'گلدن اسکن Watcher: ' . $created . ' Session تازه ساخته شد.' );
		}
		if ( $rejects && class_exists( 'STI_Logger' ) ) {
			$by_code = array();
			foreach ( $rejects as $rj ) {
				$by_code[ $rj[1] ] = ( $by_code[ $rj[1] ] ?? 0 ) + 1;
			}
			STI_Logger::warning( 'گلدن اسکن create_sessions: ' . count( $rejects ) . ' ردیف رد شد — ' . wp_json_encode( $by_code ) );
			foreach ( array_slice( $rejects, 0, 20 ) as $rj ) {
				STI_Logger::warning( 'گلدن اسکن create_sessions: profile_item #' . $rj[0] . ' → ' . $rj[1] );
			}
			if ( count( $rejects ) > 20 ) {
				STI_Logger::warning( 'گلدن اسکن create_sessions: و ' . ( count( $rejects ) - 20 ) . ' ردیف دیگر (همان دسته).' );
			}
		}
		return $created;
	}

	/**
	 * ۱۰.۱۰ — گام پنجم کاربر: «تعیین تعداد Session + Start».
	 *
	 * تا $count Session از کاندیداهای آماده (available + با دسته‌بندی)
	 * ساخته می‌شود، Worker خودکار تضمین می‌شود روشن باشد، و یک تیک
	 * فوری زمان‌بندی می‌شود. بعد از این فراخوانی، هیچ گام دستی دیگری
	 * برای پردازش عادی لازم نیست:
	 *
	 *   Scan ← Profile Match ← Session Create ← Bot ← Download ← Media
	 *   ← Product ← Publish Queue ← Published (یا REVIEW با Fix مشخص)
	 *
	 * @param int              $count
	 * @param int|null         $wc_term_id     ۱۰.۱۲ — فقط این دسته (term_id ووکامرس)
	 * @param bool             $priority_order ۱۰.۱۲ — انتخاب اولویت‌دار (score DESC)
	 * @return array{created:int, ids:int[], ready:int, worker_on:bool}
	 */
	public static function start_pipeline( $count, $wc_term_id = null, $priority_order = false ) {
		$count = max( 1, min( 1000, (int) $count ) );

		$ids     = array();
		$created = self::create_sessions( $count, $wc_term_id, $priority_order, $ids );
		$ready     = (int) ( self::stats()['ready'] ?? 0 );
		$worker_on = STI_GS_Auto_Worker::is_enabled();
		if ( ! $worker_on ) {
			STI_GS_Auto_Worker::set_enabled( true );
			$worker_on = true;
		}

		/* ۱۰.۱۱ — خط تولید واقعاً START شود (نه فقط Worker). */
		if ( class_exists( 'STI_GS_Line' ) ) {
			STI_GS_Line::record_request( $count, $created );
			STI_GS_Line::start();
		}

		/* تیک فوری — با کران (نه درون‌خطی) تا AJAX گلوگرفتگی نکند */
		if ( $created > 0 && ! wp_next_scheduled( 'sti_gs_auto_worker' ) ) {
			wp_schedule_single_event( time() - 1, 'sti_gs_auto_worker' );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		STI_Logger::info( sprintf( 'گلدن اسکن: Start Pipeline — %d Session ساخته شد (آماده‌ی باقی‌مانده: %d).', $created, $ready ) );

		return array(
			'created'   => $created,
			'ids'       => array_values( $ids ),
			'ready'     => $ready,
			'worker_on' => (bool) $worker_on,
			'line'      => class_exists( 'STI_GS_Line' ) ? STI_GS_Line::state() : 'RUNNING',
		);
	}

	/**
	 * ۱۰.۱۱-UX+ — تشخیص «Start Pipeline ساخت ۰ Session» (فقط‌خواندنی).
	 *
	 * هیچ چیزی را تغییر نمی‌دهد: نه Candidate، نه Session، نه Cron.
	 * دقیقاً همان gateها را که start_pipeline() می‌گذرد، به‌تدریج می‌ساید
	 * و تعداد + دلیل هر ردشدنی را گزارش می‌کند:
	 *
	 *   READY      = profile_items با status=available
	 *   ELIGIBLE   = READY × (profile.default_category_id > 0)  [فیلتر انتخاب]
	 *   JOIN-OK    = ELIGIBLE × (message_pk در جدول messages هست)
	 *   DUPLICATED = JOIN-OK × (Sessionی برای همان message_pk وجود دارد)
	 *   CREATED    = min(requested, JOIN-OK − DUPLICATED)  [تخمین]
	 *
	 * @param int $count همان عددی که کاربر در Start وارد می‌کند
	 * @return array
	 */
	public static function diagnose_start( $count ) {
		$count = max( 1, min( 1000, (int) $count ) );
		global $wpdb;

		$items    = STI_GS_DB::profile_items_table();
		$profiles = STI_GS_DB::profiles_table();
		$messages = STI_GS_DB::messages_table();
		$pipeline = STI_GS_DB::pipeline_items_table();

		$diag = array();

		/* ── G0: وضعیت دیتابیس / جدول هدف ─────────────────────────── */
		$old_name = $wpdb->prefix . 'sti_gs_sessions';
		$new_name = $wpdb->prefix . 'sti_gs_pipeline_items';
		$expected_cols = array( 'profile_item_id', 'message_pk', 'channel_id', 'file_code', 'category_id', 'state', 'chain_mode', 'created_at', 'updated_at' );
		$actual_cols   = (array) $wpdb->get_col( "DESCRIBE {$pipeline}" );
		$indexes       = (array) $wpdb->get_results( "SHOW INDEX FROM {$pipeline}", ARRAY_A );
		$uniq_msg      = false;
		foreach ( $indexes as $idx ) {
			if ( (int) ( $idx['Non_unique'] ?? 1 ) === 0 && ( $idx['Column_name'] ?? '' ) === 'message_pk' ) {
				$uniq_msg = true;
			}
		}
		$diag['db'] = array(
			'halted'            => (bool) STI_GS_DB::is_halted(),
			'halt_reason'       => (string) STI_GS_DB::halt_reason(),
			'migration'         => STI_GS_DB::migration_status(),
			'target_table'      => $pipeline,
			'alias_sessions_tbl'=> STI_GS_DB::sessions_table(),
			'alias_is_same'     => ( STI_GS_DB::sessions_table() === $pipeline ),
			'physical'          => array(
				'sti_gs_pipeline_items' => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_name ) ),
				'sti_gs_sessions'       => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_name ) ),
			),
			'target_rows'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pipeline}" ),
			'missing_columns'   => array_values( array_diff( $expected_cols, $actual_cols ) ),
			'unique_message_pk' => $uniq_msg,
			'messages_rows'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$messages}" ),
		);

		/* ── G1: شمارش‌های READY ──────────────────────────────────── */
		$ready_total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items} WHERE status = %s", 'available' ) );
		$ready_cat    = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items} pi INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = %s AND p.default_category_id > 0", 'available' ) );
		$ready_nocat  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items} pi INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = %s AND ( p.default_category_id IS NULL OR p.default_category_id = 0 )", 'available' ) );
		$diag['gates'] = array(
			'ready_total'         => $ready_total,
			'ready_with_category' => $ready_cat,
			'ready_no_category'   => $ready_nocat,
			'worker_enabled'      => class_exists( 'STI_GS_Auto_Worker' ) ? (bool) STI_GS_Auto_Worker::is_enabled() : null,
			'line_state'          => class_exists( 'STI_GS_Line' ) ? STI_GS_Line::state() : 'n/a',
		);

		/* ── G2: همان کوئری انتخابِ create_sessions (دقیقاً همان) ── */
		$sql    = "SELECT pi.id
			FROM {$items} pi
			INNER JOIN {$profiles} p ON p.id = pi.profile_id
			WHERE pi.status = %s
			  AND p.default_category_id IS NOT NULL
			  AND p.default_category_id > 0
			ORDER BY pi.id ASC
			LIMIT %d";
		$params = array( 'available', max( 1, $count ) );
		$prepared = $wpdb->prepare( $sql, $params );
		$rows = (array) $wpdb->get_results( $prepared, ARRAY_A );
		$diag['selection'] = array(
			'sql'           => $prepared,
			'rows_returned' => count( $rows ),
		);

		/* ── G3: dry-run هر کاندیدا (۲۰ مورد اول) ─────────────────── */
		$candidates = array();
		foreach ( array_slice( $rows, 0, 20 ) as $row ) {
			$pid = (int) $row['id'];
			$r   = $wpdb->get_row( $wpdb->prepare(
				"SELECT pi.id AS profile_item_id, pi.profile_id, pi.message_pk,
				        m.channel_id, m.file_code, p.default_category_id
				 FROM {$items} pi
				 INNER JOIN {$messages} m ON m.id = pi.message_pk
				 INNER JOIN {$profiles} p ON p.id = pi.profile_id
				 WHERE pi.id = %d", $pid ), ARRAY_A );
			$c = array(
				'profile_item_id'    => $pid,
				'profile_id'         => null,
				'message_pk'         => null,
				'channel_id'         => null,
				'file_code'          => null,
				'join_ok'            => (bool) $r,
				'existing_session'   => null,
				'skip_reason'        => null,
			);
			if ( $r ) {
				$c['profile_id'] = (int) $r['profile_id'];
				$c['message_pk'] = (int) $r['message_pk'];
				$c['channel_id'] = (int) $r['channel_id'];
				$c['file_code']  = (string) $r['file_code'];
				$existing        = STI_GS_Session::get_by_message_pk( (int) $r['message_pk'] );
				if ( $existing ) {
					$c['existing_session'] = (int) $existing['id'];
					$c['skip_reason']      = 'existing_session';
				}
			} else {
				/* خودِ item هست (کوئری انتخاب فقط profiles را JOIN می‌کند)؛
				 * پس شکست JOIN یعنی message_pk یتیم است. */
				$bare = $wpdb->get_row( $wpdb->prepare( "SELECT profile_id, message_pk FROM {$items} WHERE id = %d", $pid ), ARRAY_A );
				if ( $bare ) {
					$c['profile_id'] = (int) $bare['profile_id'];
					$c['message_pk'] = (int) $bare['message_pk'];
				}
				$c['skip_reason'] = 'message_missing';
			}
			$candidates[] = $c;
		}
		$diag['candidates'] = $candidates;

		/* ── G4: طبقه‌بندی کل ELIGIBLE (نه فقط نمونه) ────────────── */
		$elig_join_ok = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items} pi
			 INNER JOIN {$messages} m ON m.id = pi.message_pk
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = %s AND p.default_category_id > 0", 'available' ) );
		$elig_dupe = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items} pi
			 INNER JOIN {$messages} m ON m.id = pi.message_pk
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 INNER JOIN {$pipeline} s ON s.message_pk = m.id
			 WHERE pi.status = %s AND p.default_category_id > 0", 'available' ) );
		$would_insert = max( 0, $elig_join_ok - $elig_dupe );
		$diag['classification'] = array(
			'eligible_join_ok'   => $elig_join_ok,
			'eligible_duplicated'=> $elig_dupe,
			'would_insert_total' => $would_insert,
		);

		/* ── G5: Cron ─────────────────────────────────────────────── */
		$cron = array();
		foreach ( array( 'sti_gs_scan_worker', 'sti_gs_auto_worker', 'sti_gs_channel_watcher', 'sti_gs_publish_tick', 'sti_gs_watchdog' ) as $hook ) {
			$ts = wp_next_scheduled( $hook );
			$cron[ $hook ] = $ts
				? array( 'next' => (int) $ts, 'in' => human_time_diff( time(), (int) $ts ) )
				: null;
		}
		$cron['note'] = 'sti_gs_scan_worker یک رویداد one-shot (single) است که فقط هنگام شروع یک اسکن زمان‌بندی می‌شود؛ خالی بودن آن در حالت عادی (بدون اسکن فعال) طبیعی است.';
		$diag['cron'] = $cron;

		/* ── G6: حکم نهایی ───────────────────────────────────────── */
		$diag['verdict'] = array(
			'ready'      => $ready_total,
			'eligible'   => $ready_cat,
			'skipped'    => array(
				'no_category'    => $ready_nocat,
				'message_missing'=> max( 0, $ready_cat - $elig_join_ok ),
				'existing_session' => $elig_dupe,
			),
			'created_expected' => min( $count, $would_insert ),
		);

		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::warning( sprintf(
				'GS Start-Diagnostic: requested=%d ready=%d eligible=%d join_ok=%d duplicated=%d would_insert=%d target=%s rows=%d missing_cols=%s halted=%s',
				$count, $ready_total, $ready_cat, $elig_join_ok, $elig_dupe, $would_insert,
				$pipeline, $diag['db']['target_rows'],
				$diag['db']['missing_columns'] ? implode( ',', $diag['db']['missing_columns'] ) : 'none',
				$diag['db']['halted'] ? 'YES(' . $diag['db']['halt_reason'] . ')' : 'no'
			) );
		}

		return $diag;
	}

	/**
	 * ۱۰.۱۲-RC — تشخیص خشک «افزودن به صف» (dry-run) — فقط‌خواندنی.
	 *
	 * مسیر عملیاتی را کلمه‌به‌کلمه و ردیف‌به‌ردیف بازمی‌سازد (همان کوئری
	 * انتخابِ create_sessions با فیلتر دسته و ترتیب اولویت، همان دروازه‌های
	 * create_from_profile_item) ولی **هیچ اثری ندارد**: نه Session می‌سازد،
	 * نه وضعیت profile_item را لمس می‌کند، نه state خط، نه Worker، نه Cron.
	 * فقط SELECT/DESCRIBE/SHOW است.
	 *
	 * ورودی:  $items = [ ['wc_term_id'=>12, 'count'=>50], ... ]
	 *         (همان payload دکمه‌ی «افزودن به صف»)
	 * خروجی:  برای هر دسته: selected / no_item / existing_session /
	 *         would_insert + فکت‌های لایه‌ی درج + حکم بسته (verdict).
	 */
	public static function diagnose_publish_queue( $items ) {
		global $wpdb;
		$items = is_array( $items ) ? $items : array();

		/* ── لایه‌ی درج: فکت‌هایی که $wpdb->insert در create_from_profile_item به آن‌ها وابسته است ── */
		$pipeline      = STI_GS_DB::pipeline_items_table();
		$expected_cols = array( 'profile_item_id', 'message_pk', 'channel_id', 'file_code', 'category_id', 'state', 'created_at', 'updated_at' );
		$actual_cols   = (array) $wpdb->get_col( "DESCRIBE {$pipeline}" );
		$indexes       = (array) $wpdb->get_results( "SHOW INDEX FROM {$pipeline}", ARRAY_A );
		$uniq_msg      = false;
		foreach ( $indexes as $idx ) {
			if ( (int) ( $idx['Non_unique'] ?? 1 ) === 0 && ( $idx['Column_name'] ?? '' ) === 'message_pk' ) {
				$uniq_msg = true;
			}
		}
		$insert_layer = array(
			'table_exists'      => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pipeline ) ),
			'physical_table'    => $pipeline,
			'missing_columns'   => array_values( array_diff( $expected_cols, $actual_cols ) ),
			'unique_message_pk' => $uniq_msg,
		);
		$broken_insert = ( ! $insert_layer['table_exists'] ) || ! empty( $insert_layer['missing_columns'] );

		$items_t    = STI_GS_DB::profile_items_table();
		$profiles_t = STI_GS_DB::profiles_table();
		$messages_t = STI_GS_DB::messages_table();

		$out = array(
			'insert_layer' => $insert_layer,
			'categories'   => array(),
			'totals'       => array(
				'selected'                       => 0,
				'sti_gs_no_item'                 => 0,
				'existing_session'               => 0,
				'would_create'                   => 0,
				'sti_gs_session_insert_failed'   => 0,
				'created_predicted'              => 0,
			),
		);

		foreach ( $items as $it ) {
			$cat = (int) ( $it['wc_term_id'] ?? 0 );
			$cnt = min( 1000, max( 1, (int) ( $it['count'] ?? 0 ) ) );
			if ( $cat < 1 ) {
				continue;
			}

			/* عددی که UI صف انتشار برای این دسته نشان می‌دهد (همان کوئری ۲جداولی صفحه) */
			$ui_available = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$items_t} pi
				 INNER JOIN {$profiles_t} p ON p.id = pi.profile_id
				 WHERE pi.status = %s AND p.default_category_id = %d",
				'available', $cat
			) );

			/* ۱ — کوئری انتخاب: کلمه‌به‌کلمه همان create_sessions (دسته + اولویت score DESC) */
			$selected = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT pi.id
				 FROM {$items_t} pi
				 INNER JOIN {$profiles_t} p ON p.id = pi.profile_id
				 WHERE pi.status = %s
				   AND p.default_category_id IS NOT NULL
				   AND p.default_category_id > 0
				   AND p.default_category_id = %d
				 ORDER BY pi.score DESC, pi.id ASC
				 LIMIT %d",
				'available', $cat, $cnt
			), ARRAY_A );
			$sel_error = (string) $wpdb->last_error;
			$sel_ids   = array();
			foreach ( $selected as $r ) {
				$sel_ids[] = (int) $r['id'];
			}

			/* ۲ — طبقه‌بندی ردیف‌به‌ردیف از همان دروازه‌های create_from_profile_item (batch، فقط SELECT).
			 * هر ردیفِ انتخاب‌شده دقیقاً یکی از چهار برچسب را می‌گیرد:
			 *   sti_gs_no_item               — پیام در جدول messages نیست
			 *   existing_session             — Sessionی برای همان پیام هست (در مسیر واقعی «ساخته‌شده» شمرده می‌شود)
			 *   would_create                 — درج موفق خواهد بود (لایه‌ی درج سالم)
			 *   sti_gs_session_insert_failed — لایه‌ی درج شکسته است (جدول/ستون) */
			$outcome_of  = array();
			$mks_by_item = array();
			foreach ( $sel_ids as $sid ) {
				$outcome_of[ $sid ] = 'sti_gs_no_item';
			}
			if ( $sel_ids ) {
				$in  = implode( ',', $sel_ids );
				$oks = (array) $wpdb->get_results(
					"SELECT pi.id, pi.message_pk FROM {$items_t} pi
					 INNER JOIN {$messages_t} m ON m.id = pi.message_pk
					 WHERE pi.id IN ({$in})",
					ARRAY_A
				);
				foreach ( $oks as $r ) {
					$pid                = (int) $r['id'];
					$mks_by_item[ $pid ] = (int) $r['message_pk'];
					$outcome_of[ $pid ]  = 'would_create';
				}
			}

			/* ۳ — Session موجود (فقط SELECT) */
			if ( $mks_by_item ) {
				$mks = array_map( 'intval', array_unique( array_values( $mks_by_item ) ) );
				$inm = implode( ',', $mks );
				$ex  = (array) $wpdb->get_results( "SELECT DISTINCT message_pk FROM {$pipeline} WHERE message_pk IN ({$inm})", ARRAY_A );
				$ex_set = array();
				foreach ( $ex as $r ) {
					$ex_set[ (int) $r['message_pk'] ] = true;
				}
				foreach ( $mks_by_item as $pid => $mk ) {
					if ( isset( $ex_set[ (int) $mk ] ) ) {
						$outcome_of[ $pid ] = 'existing_session';
					}
				}
			}

			/* ۴ — اعمال لایه‌ی درج: would_create → sti_gs_session_insert_failed اگر لایه شکسته باشد */
			$tally     = array( 'sti_gs_no_item' => 0, 'existing_session' => 0, 'would_create' => 0, 'sti_gs_session_insert_failed' => 0 );
			$items_out = array();
			foreach ( $sel_ids as $sid ) {
				$oc = $outcome_of[ $sid ];
				if ( 'would_create' === $oc && $broken_insert ) {
					$oc = 'sti_gs_session_insert_failed';
				}
				$tally[ $oc ]++;
				$items_out[] = array( 'id' => $sid, 'outcome' => $oc );
			}
			$created_pred = $tally['existing_session'] + $tally['would_create'];

			/* نمونه‌ی ۱۰ ردیف اولِ یتیم (برای سوابق اجراکننده) */
			$no_item_sample = array();
			foreach ( $items_out as $io ) {
				if ( 'sti_gs_no_item' === $io['outcome'] ) {
					$no_item_sample[] = $io['id'];
					if ( count( $no_item_sample ) >= 10 ) {
						break;
					}
				}
			}

			$out['categories'][] = array(
				'wc_term_id'        => $cat,
				'requested'         => $cnt,
				'ui_available'      => $ui_available,
				'selected'          => count( $sel_ids ),
				'selection_error'   => $sel_error,
				'outcomes'          => $tally,
				'created_predicted' => $created_pred,
				'no_item_sample'    => $no_item_sample,
				'items'             => $items_out,
			);

			$out['totals']['selected'] += count( $sel_ids );
			foreach ( $tally as $k => $v ) {
				$out['totals'][ $k ] += $v;
			}
			$out['totals']['created_predicted'] += $created_pred;
		}

		/* ── حکم بسته: هر خروجی، دقیقاً یک ریشه دارد (هیچ تفسیری نمی‌ماند) ── */
		$tot  = $out['totals'];
		$errs = array();
		foreach ( $out['categories'] as $c ) {
			if ( '' !== (string) $c['selection_error'] ) {
				$errs[] = (string) $c['selection_error'];
			}
		}
		$S = (int) $tot['selected'];
		$N = (int) $tot['sti_gs_no_item'];
		$E = (int) $tot['existing_session'];
		$C = (int) $tot['would_create'];
		$F = (int) $tot['sti_gs_session_insert_failed'];
		if ( $errs ) {
			$verdict = 'P1_SELECTION_ERROR';
		} elseif ( 0 === $S ) {
			$verdict = 'P1_SELECTION_EMPTY';
		} elseif ( $N === $S ) {
			$verdict = 'P2_ORPHAN_MESSAGES';
		} elseif ( $F === $S ) {
			$verdict = 'P3_INSERT_LAYER';
		} elseif ( 0 === $N && 0 === $F ) {
			$verdict = 'SHOULD_CREATE';
		} else {
			$verdict = 'MIXED';
		}
		$out['verdict'] = $verdict;

		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::warning( sprintf(
				'GS Publish-DryRun: verdict=%s selected=%d no_item=%d existing=%d would_create=%d insert_failed=%d broken_insert=%s errors=%s',
				$verdict, $S, $N, $E, $C, $F,
				$broken_insert ? 'YES' : 'no', $errs ? implode( '|', $errs ) : 'none'
			) );
		}

		return $out;
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
