<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Auto Worker.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * تنها چیزی که بین شما و ۶۰٬۰۰۰ فایل ایستاده بود.
 *
 * تا امروز هر Session به یک کلیک نیاز داشت. با ۶۰٬۰۰۰ فایل و میانگین
 * هشت مرحله، این یعنی نیم‌میلیون کلیک. Worker همان کاری را می‌کند که
 * دکمه‌ی «▶ ادامه پردازش» می‌کرد، فقط با کران.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * چهار قاعده‌ای که از باگ‌های همین پروژه بیرون آمده‌اند
 *
 * ۱. **یک مرحله در هر بار.** درست مثل حلقه‌ی مرورگر. اگر Worker کل زنجیره
 *    را در یک اجرا می‌رفت، دقیقاً همان مرگ بی‌صدای وسط راه تکرار می‌شد که
 *    Sessionها را در PRODUCT_BUILDING گیر می‌انداخت.
 *
 * ۲. **نگاشت مشترک با حالت دستی.** از STI_GS_Session_Ajax::next_stage()
 *    استفاده می‌شود، نه یک کپی. دو نگاشت یعنی دو رفتار متفاوت (§157).
 *
 * ۳. **سقف تلاش.** بدون آن، یک Session خراب می‌تواند تا ابد هر پنج دقیقه
 *    منابع بخورد — روی ۶۰٬۰۰۰ آیتم فاجعه است.
 *
 * ۴. **انتظار ≠ شکست.** WAITING_BOT یعنی هنوز زود است، نه اینکه خراب شده.
 *    شمارنده‌ی تلاش برایش بالا نمی‌رود.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Auto_Worker {

	const HOOK          = 'sti_gs_auto_worker';
	const ENABLED_KEY   = 'sti_gs_worker_enabled';
	const STATS_KEY     = 'sti_gs_worker_stats';
	const LOCK_SECONDS  = 240;
	const MAX_ATTEMPTS  = 5;

	/**
	 * ۱۰.۸.۳ — بودجه‌ی هر تیک (ثانیه): اگر یک Session سنگین شد، بقیه‌ی
	 * صف در تیک بعدی. کمتر از interval_seconds پیش‌فرض (۳۰۰) است تا
	 * تیک‌ها روی هم تلنبار نشوند.
	 */
	const TICK_BUDGET_SEC = 240;

	/**
	 * پس از این مدت، Sessionهایی که به سقف تلاش خورده‌اند دوباره فرصت
	 * می‌گیرند.
	 *
	 * سقف تلاش لازم است تا یک Session خراب منابع را نبلعد، ولی «برای
	 * همیشه کنار گذاشتن» غلط بود: بیشتر شکست‌ها موقتی‌اند (قطعی تلگرام،
	 * سهمیه‌ی AI، ربات کند). به همین دلیل شما مجبور بودید دستی دکمه بزنید
	 * — و همان کلیک دستی دقیقاً همین کار را می‌کرد.
	 *
	 * حالا خودِ سیستم بعد از شش ساعت دوباره امتحان می‌کند.
	 */
	const RETRY_AFTER_GIVEUP = 6 * HOUR_IN_SECONDS;

	/** حالت‌های «در حال اجرا» که اگر قفلشان منقضی شود یعنی وسط کار مرده‌اند. */
	const IN_PROGRESS = array( 'DOWNLOADING', 'MEDIA_BUILDING', 'PRODUCT_BUILDING' );

	/** حالت‌هایی که کار تمام است و Worker نباید دستشان بزند. */
	const TERMINAL = array( 'REVIEW_READY', 'PUBLISHED', 'SKIPPED', 'NEEDS_REVIEW', 'ERROR_FILE_NOT_FOUND', 'DEAD_LETTER' );

	/** حالت‌هایی که یعنی «منتظر ربات» — شمارنده‌ی تلاش بالا نمی‌رود. */
	const WAITING = array( 'WAITING_BOT', 'ERROR_BOT_TIMEOUT', 'CHAIN_WAITING' );

	/**
	 * مرحله‌هایی که با ربات حرف می‌زنند.
	 *
	 * گفت‌وگو با ربات ذاتاً **ترتیبی** است: یک /start می‌فرستیم، ربات جواب
	 * می‌دهد، فایل را برمی‌داریم. اگر سه Session هم‌زمان این کار را بکنند،
	 * هر سه همان چند فایل صندوق ورودی را می‌بینند؛ اولی برمی‌دارد و بقیه
	 * «همه claim شده‌اند» می‌گیرند.
	 *
	 * دقیقاً همین در گزارش دیده شد: ۷ خطا، همگی Match File، همگی بعد از
	 * روشن شدن Worker با سه‌تا در هر تیک.
	 *
	 * پس در هر تیک فقط **یک** Session اجازه دارد وارد این مرحله‌ها شود.
	 * بقیه‌ی مرحله‌ها (دانلود، مدیا، ساخت محصول، اعتبارسنجی) به ربات کاری
	 * ندارند و می‌توانند موازی جلو بروند.
	 */
	const BOT_STATES = array( 'BUTTON_FOUND', 'ERROR_CLICK', 'WAITING_BOT', 'ERROR_BOT_TIMEOUT', 'BOT_RESPONSE', 'ERROR_MATCH', 'CHAIN_STEP', 'CHAIN_WAITING', 'CHAIN_FAILED' );

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


		/**
		 * پاک‌سازی یک‌باره.
		 *
		 * نسخه‌ی اول Worker به‌خاطر برخورد قفل، Sessionهای کاملاً سالم را
		 * «خطا» می‌شمرد و شمارنده‌ی تلاششان را بالا می‌برد. بدون این،
		 * همان‌ها با شمارنده‌ی آلوده می‌مانند و زودتر از موعد کنار گذاشته
		 * می‌شوند.
		 */
		if ( ! get_option( 'sti_gs_worker_lockfix_done' ) ) {
			update_option( 'sti_gs_worker_lockfix_done', 1, false );
			self::reset_stuck();
		}

		/**
		 * پاک‌سازی دوم: Sessionهایی که با ALL_CANDIDATES_CLAIMED کنار
		 * گذاشته شدند خرابی واقعی نداشتند — فقط قربانی رقابت سه‌تایی
		 * Worker شدند. به WAITING_BOT برمی‌گردند تا دوباره شانس بیاورند.
		 */
		if ( ! get_option( 'sti_gs_worker_claimfix_done' ) ) {
			update_option( 'sti_gs_worker_claimfix_done', 1, false );
			self::requeue_claim_victims();
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
			 * این کران هر ۵ دقیقه کافی است.
			 */
			$schedules = wp_get_schedules();
			$every     = isset( $schedules['sti_gs_5min'] ) ? 'sti_gs_5min' : 'hourly';
			wp_schedule_event( time() + 120, $every, self::HOOK );
		}
	}

	/* ============================== تنظیمات ============================== */

	public static function is_enabled() {
		return (bool) get_option( self::ENABLED_KEY, 0 );
	}

	public static function set_enabled( $on ) {
		update_option( self::ENABLED_KEY, $on ? 1 : 0, true );
		return self::is_enabled();
	}

	/** چند Session در هر تیک. عمداً کم — پنج دقیقه بعد دوباره می‌آید. */
	public static function batch_size() {
		$n = (int) ( class_exists( 'STI_Settings' ) ? STI_Settings::get( 'gs_worker_batch', 3 ) : 3 );
		return max( 1, min( 20, $n ) );
	}

	/* ═══════════ ۱۰.۱۰ — بودجه‌ها از Automation Settings ═══════════ */

	/** Session پردازش‌شده در هر تیک (پیش‌فرض دستور کار: ۱). */
	public static function sessions_per_tick() {
		return class_exists( 'STI_GS_Automation' )
			? (int) STI_GS_Automation::get( 'sessions_per_tick' )
			: 1;
	}

	/**
	 * batch مؤثر: min(سقف قدیمی, sessions_per_tick) × ضریب Governor.
	 * Governor هرگز زیر ۱ نمی‌رود — حداقل یک Session در هر تیک زنده می‌ماند.
	 */
	public static function effective_batch_size() {
		$base = min( self::batch_size(), self::sessions_per_tick() );
		if ( class_exists( 'STI_GS_Governor' ) ) {
			$base = (int) floor( $base * STI_GS_Governor::factor() );
		}
		return max( 1, $base );
	}

	/** سقف تلاش هر Session (پیش‌فرض: ۵ — قابل تنظیم). */
	public static function retry_limit() {
		return class_exists( 'STI_GS_Automation' )
			? (int) STI_GS_Automation::get( 'session_retry_limit' )
			: self::MAX_ATTEMPTS;
	}

	/** تعداد Sessionهای با قفل زنده (بودجه‌ی Max Active Sessions). */
	public static function active_sessions() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();
		$now   = current_time( 'mysql' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE locked_until IS NOT NULL AND locked_until > %s",
			$now
		) );
	}

	/** فاصله‌ی بین تیک‌ها به ثانیه. */
	public static function interval_seconds() {
		$n = (int) ( class_exists( 'STI_Settings' ) ? STI_Settings::get( 'gs_worker_interval', 300 ) : 300 );
		return max( 60, min( 3600, $n ) );
	}

	/* =============================== تیک =============================== */

	public static function tick() {
		if ( ! self::is_enabled() ) {
			return;
		}

		/*
		 * فاصله‌ی خودمان را رعایت می‌کنیم حتی اگر کران هر دقیقه صدا بزند.
		 * ۱۰.۹.۳ — نگهبان اتمیک (STI_GS_Cron_Gate) به‌جای خواندن/مقایسه/
		 * نوشتنِ جدا: دو تیک هم‌زمان دیگر نمی‌توانند هر دو رد شوند.
		 * (LAST برای نمایش در «وضعیت» همچنان نوشته می‌شود.)
		 */
		if ( class_exists( 'STI_GS_Cron_Gate' )
			&& ! STI_GS_Cron_Gate::pass( 'auto_worker', self::interval_seconds() ) ) {
			return;
		}
		update_option( self::STATS_KEY . '_last', time(), false );

		// حالت ایمن یا توقف اضطراری = دست نگه دار.
		if ( function_exists( 'sti_v7_safe_mode' ) && sti_v7_safe_mode() ) {
			return;
		}
		if ( class_exists( 'STI_GS_DB' ) && STI_GS_DB::is_halted() ) {
			return;
		}

		/*
		 * ۱۰.۱۰ — بودجه‌ی منابع (هاست اشتراکی):
		 *   Max Active Sessions: اگر همین الان Sessionی با قفل زنده دارد
		 *   (مثلاً وسط دانلود در درخواست دیگر)، تیک جدید شروع نمی‌کند.
		 *   Governor: batch را خفه می‌کند، هرگز Session را خطا نمی‌کند.
		 */
		$max_active = class_exists( 'STI_GS_Automation' )
			? (int) STI_GS_Automation::get( 'max_active_sessions' )
			: 1;
		if ( self::active_sessions() >= $max_active ) {
			self::record( array( 'advanced' => 0, 'waiting' => 0, 'failed' => 0, 'completed' => 0 ) );
			return;
		}

		if ( class_exists( 'STI_GS_Governor' ) ) {
			STI_GS_Governor::evaluate();
		}

		$heavy_left  = class_exists( 'STI_GS_Automation' ) ? (int) STI_GS_Automation::get( 'max_downloads_per_tick' ) : 1;
		$prod_left   = class_exists( 'STI_GS_Automation' ) ? (int) STI_GS_Automation::get( 'max_products_per_tick' ) : 1;

		$sessions = self::pick( self::effective_batch_size() );
		if ( empty( $sessions ) ) {
			return;
		}

		$report = array( 'advanced' => 0, 'waiting' => 0, 'failed' => 0, 'completed' => 0 );
		$bot_used = false;

		/* ۱۰.۸.۳ — بودجه‌ی تیک: مابقی Sessionها به تیک بعدی موکول می‌شوند. */
		$tick_started = time();

		foreach ( $sessions as $session ) {
			if ( ( time() - $tick_started ) >= self::TICK_BUDGET_SEC ) {
				break;
			}

			$state = (string) $session['state'];

			// فقط یک Session در هر تیک اجازه‌ی گفت‌وگو با ربات دارد.
			if ( in_array( $state, self::BOT_STATES, true ) ) {
				if ( $bot_used ) {
					continue; // نوبتش تیک بعدی
				}
				$bot_used = true;
			}

			/*
			 * ۱۰.۱۰ — کارهای سنگین (دانلود/مدیا/محصول):
			 *   Governor در EMERGENCY → Session WAITING می‌ماند (نه FAILED).
			 *   سقف max_downloads/max_products در هر تیک.
			 */
			$stage  = class_exists( 'STI_GS_Stage' ) ? STI_GS_Stage::stage_of( $state ) : null;
			$is_dl  = ( STI_GS_Stage::DOWNLOAD === $stage );
			$is_md  = ( STI_GS_Stage::MEDIA === $stage );
			$is_pr  = ( STI_GS_Stage::PRODUCT === $stage );
			if ( $is_dl || $is_md || $is_pr ) {
				if ( class_exists( 'STI_GS_Governor' ) && ! STI_GS_Governor::allow_heavy() ) {
					$report['waiting']++;
					continue; // فشار زیاد — تیک بعدی؛ خرابی نیست
				}
				if ( $is_dl && $heavy_left <= 0 ) {
					$report['waiting']++;
					continue;
				}
				if ( ( $is_md || $is_pr ) && $prod_left <= 0 ) {
					$report['waiting']++;
					continue;
				}
			}

			$outcome = self::advance_one( $session, array(
				'dl'  => $is_dl,
				'prd' => ( $is_md || $is_pr ),
			) );
			if ( $is_dl ) {
				$heavy_left--;
			}
			if ( $is_md || $is_pr ) {
				$prod_left--;
			}
			if ( isset( $report[ $outcome ] ) ) {
				$report[ $outcome ]++;
			}
		}

		self::record( $report );
	}

	/**
	 * انتخاب Sessionهای ناتمام.
	 *
	 * مرتب‌سازی بر اساس priority سپس قدیمی‌ترین — تا یک Session تازه جلوی
	 * یکی که مدت‌هاست منتظر است را نگیرد.
	 */
	protected static function pick( $limit ) {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();
		$now   = current_time( 'mysql' );

		$terminal = self::TERMINAL;
		$place    = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );

		/**
		 * شرط تلاش تغییر کرد.
		 *
		 * قبلاً `attempts < 5` بود، یعنی Session پس از پنج شکست **برای
		 * همیشه** نامرئی می‌شد و فقط دکمه‌ی دستی نجاتش می‌داد.
		 *
		 * حالا Sessionهای به‌سقف‌خورده هم برداشته می‌شوند، به شرط اینکه
		 * `next_retry_at` آن‌ها رسیده باشد — که در handle_failure روی شش
		 * ساعت بعد تنظیم می‌شود. پس هیچ Sessionای برای همیشه رها نمی‌شود،
		 * ولی Session خراب هم هر پنج دقیقه منابع نمی‌خورد.
		 */
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE state NOT IN ({$place})
			   AND ( locked_until IS NULL OR locked_until < %s )
			   AND ( next_retry_at IS NULL OR next_retry_at <= %s )
			 ORDER BY ( attempts >= %d ) ASC, priority DESC, id ASC
			 LIMIT %d",
			array_merge( $terminal, array( $now, $now, self::retry_limit(), (int) $limit ) )
		), ARRAY_A );
	}

	/**
	 * یک Session را دقیقاً **یک مرحله** جلو می‌برد.
	 *
	 * ۱۰.۱۰: هر Tick فقط next_valid_transition — هیچ پرش. بعد از هر
	 * عملیات، گذار از نظر Stage اعتبارسنجی می‌شود و لاگ Run به‌روز می‌شود.
	 *
	 * @param array $session
	 * @param array $ctx  dl | prd (برای شمارنده‌ها)
	 * @return string advanced | waiting | failed | completed | skipped
	 */
	protected static function advance_one( $session, $ctx = array() ) {
		$session_id = (int) $session['id'];
		$state      = (string) $session['state'];

		if ( ! class_exists( 'STI_GS_Session_Ajax' ) ) {
			return 'skipped';
		}

		/**
		 * انتظارِ بی‌پایان هم یک بن‌بست است.
		 *
		 * Sessionهای ۲۳:۱۳ ساعت‌ها در WAITING_BOT ماندند و هر تیک
		 * «waiting» شمرده شدند — بدون خطا، ولی بدون پیشرفت هم. علتش این
		 * بود که ربات فایلشان را همان موقع فرستاده و Session دیگری برداشته
		 * بود؛ Poll دوباره هرگز چیزی پیدا نمی‌کرد.
		 *
		 * بعد از پایان مهلت، به کلیک دوباره برمی‌گردیم.
		 */
		/**
		 * بازیابی از مرگ وسط راه.
		 *
		 * اگر PHP وسط دانلود یا ساخت مدیا بمیرد (مهلت وب‌سرور، حافظه)،
		 * Session در حالت `DOWNLOADING` یا `MEDIA_BUILDING` می‌ماند. قفلش
		 * بعد از چند دقیقه منقضی می‌شود ولی حالتش عوض نمی‌شود.
		 *
		 * Product Builder برای همین حالت بازیابی داشت (۱۰.۳.۹) ولی دو
		 * مرحله‌ی دیگر نه — و همان‌ها بودند که شما دستی دکمه می‌زدید.
		 *
		 * چون قفل منقضی شده، هیچ‌کس مشغول نیست و برگرداندن به مرحله‌ی
		 * قبل امن است.
		 */
		$rewind = array(
			'DOWNLOADING'    => 'FILE_MATCHED',
			'MEDIA_BUILDING' => 'STORED',
		);
		if ( isset( $rewind[ $state ] ) ) {
			STI_GS_Session::update( $session_id, array(
				'state'        => $rewind[ $state ],
				'stage'        => 'auto_worker',
				'error_reason' => null,
			) );
			STI_GS_Event::log( $session_id, 'auto_worker', 'ok', sprintf(
				'تلاش قبلی در «%s» ناتمام مانده بود (قفل منقضی) — بازگشت به %s.',
				$state, $rewind[ $state ]
			) );
			$state = $rewind[ $state ];
			/* ۱۰.۱۰ — Rewind خودش یک Recovery است (شمارش در Run Log). */
			if ( class_exists( 'STI_GS_Run_Log' ) ) {
				STI_GS_Run_Log::touch( $session_id, $state, 'auto', array( 'recovery' => 1 ) );
			}
		}

		/**
		 * کلیک دوباره / بازیابی — فقط از طریق next_stage.
		 *
		 * ۱۰.۸.۲: این بلوک‌ها (ERROR_BOT_TIMEOUT/ERROR_MATCH → BUTTON_FOUND و
		 * WAITING_BOT → BUTTON_FOUND) حذف شدند. تصمیم‌گیری درباره‌ی requeue،
		 * waiting (Poll داخل پنجره / recover خارج پنجره) و match recovery
		 * فقط در STI_GS_Session_Ajax::next_stage انجام می‌شود تا مسیر دستی و
		 * خودکار یک رفتار داشته باشند (WAITING_BOT ≠ BUTTON_FOUND).
		 */

		$next = STI_GS_Session_Ajax::next_stage( $state, $session_id );
		if ( ! $next ) {
			// وضعیتی که قدم بعدی ندارد — مثل ERROR_BUTTON روی پیامی که اصلاً
			// دکمه ندارد. به‌جای تلاش بی‌پایان، کنار گذاشته می‌شود.
			self::skip( $session_id, 'وضعیت «' . $state . '» قدم بعدی تعریف‌شده‌ای ندارد.' );
			return 'skipped';
		}

		/**
		 * عمداً اینجا قفل گرفته نمی‌شود.
		 *
		 * هر هفت موتور (Button Resolver، Action Executor، File Matcher،
		 * Download، Media، Product Builder، Validator) **خودشان**
		 * STI_GS_Session::claim() را صدا می‌زنند.
		 *
		 * نسخه‌ی اول Worker پیش از فراخوانی موتور قفل می‌گرفت؛ نتیجه این
		 * بود که قفل داخلی موتور شکست می‌خورد و `sti_gs_locked` برمی‌گشت —
		 * برای هر Session، هر بار. در گزارش به‌صورت «۶ خطا، ۰ پیشرفت»
		 * دیده شد.
		 *
		 * مسیر دستی این مشکل را نداشت چون AJAX قفل نمی‌گیرد و مستقیم موتور
		 * را صدا می‌زند. حالا Worker هم دقیقاً همان کار را می‌کند.
		 *
		 * انحصار از بین نرفته: اگر دو تیک هم‌زمان یک Session را بردارند،
		 * قفل خودِ موتور یکی را رد می‌کند و همان‌جا «skipped» شمرده می‌شود،
		 * نه خطا.
		 */
		$outcome = 'skipped';
		try {
			$result = call_user_func( $next['run'], $session_id );
			$after  = STI_GS_Session::get( $session_id );
			$new    = $after ? (string) $after['state'] : $state;

			/*
			 * ۱۰.۱۰ — اعتبارسنجی گذار: اگر موتور پرش غیرمجاز کرده باشد
			 * (پرشی به Stage فراتر از بعدی)، anomaly ثبت می‌شود. تصمیم
			 * برمی‌نمی‌دارد — فقط خطای معماری را آشکار می‌کند.
			 */
			if ( $new !== $state && class_exists( 'STI_GS_Stage' )
				&& ! STI_GS_Stage::valid_transition( $state, $new ) ) {
				STI_GS_Event::log( $session_id, 'auto_worker', 'error', sprintf(
					'ANOMALY transition: %s → %s (Stage: %s → %s) — موتور: %s',
					$state, $new,
					STI_GS_Stage::stage_of( $state ),
					STI_GS_Stage::stage_of( $new ),
					$next['label']
				) );
			}

			if ( is_wp_error( $result ) ) {
				// برخورد قفل یعنی «کس دیگری مشغول است»، نه خرابی. اگر خطا
				// حساب شود، شمارنده‌ی تلاش الکی بالا می‌رود و Session سالم
				// بعد از پنج بار کنار گذاشته می‌شود.
				if ( 'sti_gs_locked' === $result->get_error_code() ) {
					$outcome = 'skipped';
				} else {
					$outcome = self::handle_failure( $session_id, $new, $next['label'], $result->get_error_message(), $ctx );
				}
			} else {
				/**
				 * توقف عمدی (زنجیره) — خطا نیست.
				 *
				 * Chain Init وقتی تصمیم می‌کند Session legacy بماند، State را
				 * جابه‌جا نمی‌کند (SCANNED → SCANNED) ولی این «بی‌پیشرفتی» یک
				 * شکست نیست. بدون این پرچم، worker آن را failure حساب می‌کرد و
				 * شمارنده‌ی تلاش یک Session سالم بالا می‌رفت.
				 */
				if ( is_array( $result ) && ! empty( $result['no_progress'] ) ) {
					$outcome = ! empty( $result['waiting'] ) ? 'waiting' : 'skipped';
				} elseif ( in_array( $new, self::TERMINAL, true ) ) {
					STI_GS_Event::log( $session_id, 'auto_worker', 'ok',
						'Worker مسیر را تا «' . $new . '» کامل کرد.' );
					$outcome = 'completed';
				} elseif ( in_array( $new, self::WAITING, true ) ) {
					// انتظار خرابی نیست — شمارنده بالا نمی‌رود.
					$outcome = 'waiting';
				} elseif ( $new === $state ) {
					// پیش‌رفتی نداشت ولی خطا هم نداد.
					$outcome = self::handle_failure( $session_id, $new, $next['label'], 'بدون پیش‌رفت', $ctx );
				} else {
					STI_GS_Session::update( $session_id, array( 'attempts' => 0 ) );
					$outcome = 'advanced';
				}
			}

		} catch ( \Throwable $e ) {
			$outcome = self::handle_failure( $session_id, $state, $next['label'], $e->getMessage(), $ctx );
		}

		/* ۱۰.۱۰ — لاگ Run: وضعیت نهاییِ این تیک برای Session ثبت می‌شود. */
		if ( class_exists( 'STI_GS_Run_Log' ) ) {
			$after2 = STI_GS_Session::get( $session_id );
			$state2 = $after2 ? (string) $after2['state'] : $state;
			STI_GS_Run_Log::touch( $session_id, $state2, 'auto' );
		}

		return $outcome;
	}

	/**
	 * شکست با عقب‌نشینی نمایی.
	 *
	 * فاصله‌ی تلاش بعدی با هر شکست دو برابر می‌شود: ۵، ۱۰، ۲۰، ۴۰، ۸۰ دقیقه.
	 * یعنی یک Session خراب منابع را نمی‌بلعد ولی اگر مشکل موقتی بود
	 * (قطعی تلگرام، سهمیه‌ی AI) خودش برمی‌گردد.
	 */
	protected static function handle_failure( $session_id, $state, $stage, $message, $ctx = array() ) {
		$session  = STI_GS_Session::get( $session_id );
		$attempts = (int) ( $session['attempts'] ?? 0 ) + 1;

		/* ۱۰.۱۰ — Stage این خطا (برای پلان Recovery + شمارنده‌ها). */
		$gstage = class_exists( 'STI_GS_Stage' ) ? STI_GS_Stage::stage_of( $state ) : null;
		if ( ! $gstage ) {
			$gstage = STI_GS_Stage::DOWNLOAD;
		}
		$limit  = self::retry_limit();

		/* شمارنده‌ی درست در Run Log: دانلود/انتشار/سایر. */
		$bump_key = 'retry';
		if ( STI_GS_Stage::DOWNLOAD === $gstage || ! empty( $ctx['dl'] ) ) {
			$bump_key = 'download_retry';
		} elseif ( STI_GS_Stage::PUBLISH === $gstage ) {
			$bump_key = 'publish_retry';
		}

		/* ۱۰.۱۰ — خطای IPC: برای Governor ثبت می‌شود (ترتیب: ipc_heal هم شمرده می‌شود). */
		$bump = array( $bump_key => 1 );
		if ( class_exists( 'STI_GS_Recovery' ) && STI_GS_Recovery::is_ipc_fault( $message ) ) {
			STI_GS_Recovery::record_ipc_fault();
			$bump['ipc_heal'] = 1;
		}

		/**
		 * SESSION SURVIVAL RULE (۱۰.۱۰):
		 * REVIEW فقط وقتی مجاز است که (۱) به سقف تلاش خورده باشیم و
		 * (۲) خطا از ۴ دلیل eligible REVIEW باشد. وگرنه Session ادامه
		 * پیدا می‌کند — نه DEAD_END بی‌دلیل، نه شکست نهایی.
		 */
		$reason = ( class_exists( 'STI_GS_Review' ) )
			? STI_GS_Review::eligible( $state, $message )
			: null;

		if ( $attempts >= $limit ) {
			if ( $reason && class_exists( 'STI_GS_Recovery' ) ) {
				/* recovery کامل شده + eligible → REVIEW با دلیل صریح */
				STI_GS_Recovery::to_review( $session_id, $stage, $message, $reason );
				self::remember_failure( $session_id, $state, $stage, '[REVIEW:' . $reason . '] ' . $message );
				if ( class_exists( 'STI_GS_Run_Log' ) ) {
					STI_GS_Run_Log::touch( $session_id, 'NEEDS_REVIEW', 'auto', $bump );
				}
				return 'failed';
			}
			/* eligible نیست → باید ادامه بدهد: backoff بلند، شمارنده نگه می‌ماند */
			STI_GS_Session::update( $session_id, array(
				'attempts'      => $attempts,
				'next_retry_at' => self::mysql_time( time() + self::RETRY_AFTER_GIVEUP ),
				'error_reason'  => mb_substr( $stage . ': ' . $message, 0, 250 ),
			) );
			STI_GS_Event::log( $session_id, 'auto_worker', 'error', sprintf(
				'به سقف %d تلاش خوردم در «%s» ولی eligible برای REVIEW نیست — با backoff بلند ادامه: %s',
				$limit, $stage, mb_substr( (string) $message, 0, 160 )
			) );
			self::remember_failure( $session_id, $state, $stage, 'ادامه با backoff بلند: ' . $message );
			if ( class_exists( 'STI_GS_Run_Log' ) ) {
				STI_GS_Run_Log::touch( $session_id, $state, 'auto', $bump );
			}
			return 'failed';
		}

		/**
		 * زیر سقف: دسته‌بندی خطا فقط **زمان‌بندی تلاش دوباره** را تعیین می‌کند
		 * (Recovery آگاه از Stage: پلان هر Stage در STI_GS_Recovery::recovery_plan
		 * مستند است؛ عملیات‌های درون‌خطی مثل refresh reference و ipc_heal داخل
		 * موتورهای همان Stage اجرا می‌شوند).
		 */
		if ( class_exists( 'STI_GS_Recovery' ) && class_exists( 'STI_GS_Flags' )
			&& STI_GS_Flags::on( 'error_classification' ) ) {

			$class = STI_GS_Recovery::classify( $message, $state );

			if ( STI_GS_Recovery::PERMANENT === $class ) {
				/* ۱۰.۱۰ — PERMANENT دیگر مستقیم DEAD_LETTER نیست: REVIEW gate اول. */
				if ( $reason ) {
					STI_GS_Recovery::to_review( $session_id, $stage, $message, $reason );
				} else {
					STI_GS_Recovery::to_dead_letter( $session_id, $stage, $message );
				}
				self::remember_failure( $session_id, $state, $stage, 'دائمی: ' . $message );
				if ( class_exists( 'STI_GS_Run_Log' ) ) {
					STI_GS_Run_Log::touch( $session_id, $state, 'auto', $bump );
				}
				return 'failed';
			}

			$delay = STI_GS_Recovery::backoff_seconds( $class, $attempts );
			if ( $attempts >= $limit ) {
				$delay = max( $delay, self::RETRY_AFTER_GIVEUP );
			}

			STI_GS_Session::update( $session_id, array(
				'attempts'      => $attempts,
				'next_retry_at' => self::mysql_time( time() + $delay ),
				'error_reason'  => mb_substr( '[' . $class . '] ' . $stage . ': ' . $message, 0, 250 ),
			) );

			self::remember_failure( $session_id, $state, $stage, '[' . $class . '] ' . $message );
			if ( class_exists( 'STI_GS_Run_Log' ) ) {
				STI_GS_Run_Log::touch( $session_id, $state, 'auto', $bump );
			}
			return 'failed';
		}

		$delay = self::interval_seconds() * pow( 2, $attempts - 1 );
		STI_GS_Session::update( $session_id, array(
			'attempts'      => $attempts,
			'next_retry_at' => self::mysql_time( time() + $delay ),
			'error_reason'  => mb_substr( $stage . ': ' . $message, 0, 250 ),
		) );

		if ( class_exists( 'STI_GS_Run_Log' ) ) {
			STI_GS_Run_Log::touch( $session_id, $state, 'auto', $bump );
		}
		return 'failed';
	}

	/** زمان محلی به فرمت MySQL — میلادی، نه جلالی. */
	protected static function mysql_time( $timestamp ) {
		return gmdate( 'Y-m-d H:i:s', (int) $timestamp + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
	}

	protected static function skip( $session_id, $reason ) {
		STI_GS_Session::update( $session_id, array(
			'state'        => 'SKIPPED',
			'stage'        => 'auto_worker',
			'error_reason' => mb_substr( $reason, 0, 250 ),
		) );
		STI_GS_Event::log( $session_id, 'auto_worker', 'ok', 'کنار گذاشته شد: ' . $reason );
	}

	/* ============================== گزارش ============================== */

	const RECENT_KEY = 'sti_gs_worker_recent';

	/**
	 * چرا شکست خورد — نه فقط چند تا.
	 *
	 * گزارش «۱۵ خطا» هیچ اطلاعاتی نمی‌دهد. علت هر شکست در error_reason
	 * خودِ Session ثبت می‌شد ولی جایی جمع نمی‌شد، پس تشخیص فقط با لاگ
	 * خواندن ممکن بود.
	 */
	protected static function remember_failure( $session_id, $state, $stage, $message ) {
		$list = get_option( self::RECENT_KEY, array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}

		array_unshift( $list, array(
			'session_id' => (int) $session_id,
			'state'      => (string) $state,
			'stage'      => (string) $stage,
			'message'    => mb_substr( (string) $message, 0, 200 ),
			'at'         => current_time( 'mysql' ),
		) );

		update_option( self::RECENT_KEY, array_slice( $list, 0, 25 ), false );
	}

	/** آخرین شکست‌ها، همراه با شمارش بر اساس علت. */
	public static function recent_failures() {
		$list = get_option( self::RECENT_KEY, array() );
		$list = is_array( $list ) ? $list : array();

		$by_reason = array();
		foreach ( $list as $row ) {
			$key = ( $row['stage'] ?? '?' ) . ' — ' . preg_replace( '/[:،].*$/u', '', (string) ( $row['message'] ?? '' ) );
			$by_reason[ $key ] = ( $by_reason[ $key ] ?? 0 ) + 1;
		}
		arsort( $by_reason );

		return array( 'items' => $list, 'by_reason' => $by_reason );
	}

	protected static function record( $report ) {
		$day  = wp_date( 'Y-m-d' );
		$all  = get_option( self::STATS_KEY, array() );
		$today = ( is_array( $all ) && ( $all['day'] ?? '' ) === $day )
			? $all
			: array( 'day' => $day, 'advanced' => 0, 'waiting' => 0, 'failed' => 0, 'completed' => 0, 'ticks' => 0 );

		foreach ( array( 'advanced', 'waiting', 'failed', 'completed' ) as $k ) {
			$today[ $k ] = (int) $today[ $k ] + (int) $report[ $k ];
		}
		$today['ticks'] = (int) $today['ticks'] + 1;
		$today['last']  = current_time( 'mysql' );

		update_option( self::STATS_KEY, $today, false );
	}

	/** خلاصه‌ی امروز — همان چیزی که در پنل نشان داده می‌شود. */
	public static function stats() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();

		$terminal = self::TERMINAL;
		$place    = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );

		$pending = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE state NOT IN ({$place}) AND attempts < %d",
			array_merge( $terminal, array( self::MAX_ATTEMPTS ) )
		) );

		// «نیازمند بازبینی» یعنی به سقف خورده و هنوز نوبت تلاش دوباره‌اش
		// نرسیده. اینها هم خودشان برمی‌گردند؛ فقط دیرتر.
		$stuck = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE attempts >= %d AND state NOT IN ({$place})",
			array_merge( array( self::MAX_ATTEMPTS ), $terminal )
		) );

		// NEEDS_REVIEW / ERROR_FILE_NOT_FOUND — نیاز به بررسی انسانی (۱۰.۸.۵).
		/**
		 * بدون prepare — این کوئری هیچ ورودی متغیری ندارد.
		 *
		 * از وردپرس ۶.۲، `prepare()` بدون placeholder یک
		 * `_doing_it_wrong()` صادر می‌کند. با WP_DEBUG روشن آن Notice
		 * **داخل خروجی AJAX چاپ می‌شود** و JSON را خراب می‌کند — همان
		 * «پاسخ نامعتبر از سرور» که هنگام «اجرای فوری یک دور» می‌دیدید.
		 *
		 * پیشوند \danog\MadelineProto\Exception گمراه‌کننده بود؛
		 * MadelineProto فقط خروجی آلوده را بسته‌بندی کرده بود.
		 */
		$review = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE state IN ('NEEDS_REVIEW','ERROR_FILE_NOT_FOUND')"
		);

		$today = get_option( self::STATS_KEY, array() );

		return array(
			'enabled'    => self::is_enabled(),
			'interval'   => self::interval_seconds(),
			'batch'      => self::batch_size(),
			'pending'    => $pending,
			'stuck'      => $stuck,
			'review'     => $review,
			'today'      => is_array( $today ) ? $today : array(),
			'failures'   => self::recent_failures(),
			'next_tick'  => wp_next_scheduled( self::HOOK ),
			'chain_mode' => class_exists( 'STI_GS_Chain_Engine' ) ? STI_GS_Chain_Engine::mode() : 'legacy',
		);
	}

	/** بازگرداندن Sessionهایی که فقط به‌خاطر رقابت claim کنار گذاشته شدند. */
	public static function requeue_claim_victims() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();

		/**
		 * دنبال متن فارسی هم می‌گردیم.
		 *
		 * handle_failure مقدار error_reason را با پیام WP_Error بازنویسی
		 * می‌کند، پس رشته‌ی «ALL_CANDIDATES_CLAIMED» که File Matcher نوشته
		 * بود از بین می‌رود و فقط متن فارسی می‌ماند. جست‌وجوی قبلی هیچ‌وقت
		 * چیزی پیدا نمی‌کرد.
		 *
		 * حالت به BUTTON_FOUND برمی‌گردد نه WAITING_BOT: چون فایل قبلی را
		 * کس دیگری برداشته، باید دوباره کلیک شود.
		 */
		$n = $wpdb->query(
			"UPDATE {$table}
			 SET state = 'BUTTON_FOUND', attempts = 0, next_retry_at = NULL, error_reason = NULL
			 WHERE state IN ( 'ERROR_MATCH', 'ERROR_BOT_TIMEOUT' )
			   AND ( error_reason LIKE '%ALL_CANDIDATES_CLAIMED%'
			      OR error_reason LIKE '%claim%'
			      OR error_reason LIKE '%NO_CODE_MATCH%'
			      OR error_reason LIKE '%NO_IDENTIFIABLE%' )
			   AND button_payload IS NOT NULL AND button_payload <> ''"
		);

		if ( $n ) {
			STI_Logger::info( 'گلدن اسکن: ' . (int) $n . ' Session که قربانی رقابت claim شده بودند به صف برگشتند.' );
		}
		return (int) $n;
	}

	/** تلاش دوباره برای همه‌ی Sessionهایی که به سقف خورده‌اند. */
	public static function reset_stuck() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();

		$n = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET attempts = 0, next_retry_at = NULL WHERE attempts >= %d",
			self::MAX_ATTEMPTS
		) );

		STI_Logger::info( 'گلدن اسکن: شمارنده‌ی تلاش ' . (int) $n . ' Session صفر شد.' );
		return (int) $n;
	}
}
