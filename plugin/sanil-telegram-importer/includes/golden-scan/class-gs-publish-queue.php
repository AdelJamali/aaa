<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — صف انتشار.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * چرا صف جدا و نه STI_Scheduler؟
 *
 * `STI_Scheduler::enqueue()` روی `STI_Session` کار می‌کند — یعنی جدول
 * `sti_sessions` مسیر قدیمی (ربات تلگرام). گلدن اسکن آیتم‌هایش در
 * `sti_gs_pipeline_items` است. دادن شناسه‌ی گلدن اسکن به آن متد یا کاری
 * نمی‌کند یا ردیف اشتباهی را دست‌کاری می‌کند.
 *
 * دستکاری زمان‌بند مشترک هم طبق §4 مجاز نیست.
 *
 * راه‌حل: صف مستقل روی جدول خودمان، ولی با **همان تنظیمات اپراتور** —
 * روشن/خاموش بودن صف، فاصله‌ی انتشار و حالت هوشمند همگی از
 * STI_Scheduler خوانده می‌شوند. یعنی کاربر یک پنل کنترل دارد، نه دو تا.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Publish_Queue {

	const HOOK           = 'sti_gs_publish_tick';
	const LAST_PUB_KEY   = 'sti_gs_queue_last_published_at';
	const DAILY_KEY      = 'sti_gs_queue_daily';
	const LOCK_SECONDS   = 300;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );

		// ترمیم یک‌باره‌ی آیتم‌هایی که به‌خاطر باگ whitelist زمان نگرفتند.
		if ( ! get_option( 'sti_gs_queue_schedule_repaired_v2' ) ) {
			update_option( 'sti_gs_queue_schedule_repaired_v2', 1, false );

			/**
			 * ترمیم دوم: زمان‌هایی که با تقویم جلالی ذخیره شدند.
			 *
			 * «1405-06-07» در ستون DATETIME یعنی سال ۱۴۰۵ میلادی — قرن‌ها
			 * پیش. مقایسه‌هایش بی‌معناست. همه‌ی این‌ها پاک می‌شوند تا
			 * repair دوباره با تقویم درست زمان‌بندی‌شان کند.
			 */
			global $wpdb;
			$table = STI_GS_DB::pipeline_items_table();
			$bad   = (int) $wpdb->query(
				"UPDATE {$table} SET scheduled_at = NULL
				 WHERE queue_status = 'queued' AND scheduled_at IS NOT NULL AND scheduled_at < '2000-01-01'"
			);
			if ( $bad ) {
				STI_Logger::warning( 'گلدن اسکن صف: ' . $bad . ' زمان‌بندی با تقویم اشتباه پاک شد.' );
			}

			self::repair_missing_schedule();
		}

		// نام بازه همان چیزی است که STI_Scheduler ثبت می‌کند
		// («sti_every_minute»). با نام ناموجود، wp_schedule_event بی‌صدا
		// شکست می‌خورد و صف هرگز اجرا نمی‌شد.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$schedules = wp_get_schedules();
			$every     = isset( $schedules['sti_every_minute'] ) ? 'sti_every_minute' : 'hourly';
			wp_schedule_event( time() + 60, $every, self::HOOK );
		}
	}

	/** برای اپراتور: اجرای فوری یک تیک بدون انتظار کران. */
	public static function run_now() {
		self::tick();
		return self::stats();
	}

	/**
	 * انتشار نوبت بعدی **بدون توجه به زمان‌بندی**.
	 *
	 * tick() عمداً `scheduled_at <= now` را رعایت می‌کند تا فاصله حفظ شود.
	 * این متد برای وقتی است که اپراتور آگاهانه می‌خواهد زودتر منتشر کند.
	 */
	public static function publish_next_now() {
		if ( ! self::is_running() ) {
			return false;
		}

		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();
		$now   = current_time( 'mysql' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE queue_status = 'queued'
			   AND product_id IS NOT NULL AND product_id > 0
			   AND ( locked_until IS NULL OR locked_until < %s )
			 ORDER BY priority DESC, scheduled_at ASC, id ASC
			 LIMIT 1",
			$now
		), ARRAY_A );

		if ( ! $row ) {
			return false;
		}

		$session_id = (int) $row['id'];
		$worker_id  = 'gsq_now_' . wp_generate_password( 5, false );
		if ( ! STI_GS_Session::claim( $session_id, $worker_id, self::LOCK_SECONDS ) ) {
			return false;
		}

		try {
			self::publish( $session_id, (int) $row['product_id'] );
			return true;
		} catch ( \Throwable $e ) {
			STI_GS_Session::update( $session_id, array(
				'queue_status' => 'failed',
				'error_reason' => mb_substr( 'PUBLISH_FAILED: ' . $e->getMessage(), 0, 250 ),
			) );
			STI_GS_Event::log( $session_id, 'publish_queue', 'error', 'PUBLISH_FAILED: ' . $e->getMessage() );
			return false;
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
		}
	}

	/**
	 * زمان محلی به فرمت MySQL — **همیشه میلادی**.
	 *
	 * `wp_date()` تقویم را از زبان سایت می‌گیرد. روی سایت فارسی خروجی
	 * جلالی است: «1405-06-07 05:53:08». آن رشته در ستون DATETIME ذخیره
	 * می‌شد و مقایسه‌هایش با `current_time('mysql')` (که میلادی است) بی‌معنا
	 * بود — در پنل هم همان تاریخ عجیب دیده شد.
	 *
	 * برای ستون‌های دیتابیس هرگز نباید از wp_date استفاده کرد.
	 */
	protected static function mysql_time( $timestamp ) {
		$offset = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		return gmdate( 'Y-m-d H:i:s', (int) $timestamp + $offset );
	}

	/* ============================== تنظیمات ============================== */

	const RUNNING_KEY  = 'sti_gs_queue_running';
	const INTERVAL_KEY = 'sti_gs_queue_interval';

	/**
	 * کنترل مستقل صف.
	 *
	 * تا ۱۰.۵.۶ این صف روشن/خاموش بودنش را از STI_Scheduler می‌خواند —
	 * صف مسیر قدیمی. نتیجه‌اش سردرگمی بود: صفحه‌ی «صف انتشار» «۰ محصول»
	 * نشان می‌داد چون جدول sti_sessions را می‌خواند، در حالی که صف گلدن
	 * اسکن ۱۴ مورد داشت.
	 *
	 * صف قدیمی حذف نشد چون **پنج ماژول فعال** هنوز از آن استفاده می‌کنند:
	 * Webhook، GoldTel، Agent Bridge، Importek و Bot Modes. حذفش هر پنج
	 * مسیر ربات تلگرام را می‌شکست.
	 *
	 * به‌جایش این صف خودکفا شد: تنظیمات خودش را دارد و دیگر به آن صفحه
	 * وابسته نیست. دو صف، دو مسیر، بدون تداخل.
	 *
	 * برای سازگاری، اگر هنوز تنظیم مستقلی ثبت نشده باشد، یک‌بار از
	 * STI_Scheduler ارث می‌برد تا رفتار فعلی سایت عوض نشود.
	 */
	public static function is_running() {
		$v = get_option( self::RUNNING_KEY, null );
		if ( null === $v ) {
			$v = ( class_exists( 'STI_Scheduler' ) && STI_Scheduler::is_running() ) ? 1 : 0;
			update_option( self::RUNNING_KEY, $v, true );
		}
		return (bool) $v;
	}

	public static function set_running( $on ) {
		update_option( self::RUNNING_KEY, $on ? 1 : 0, true );
		return self::is_running();
	}

	public static function interval_seconds() {
		$v = (int) get_option( self::INTERVAL_KEY, 0 );
		if ( $v > 0 ) {
			return max( 60, min( 24 * HOUR_IN_SECONDS, $v ) );
		}

		// ارث یک‌باره از تنظیم قبلی اپراتور
		if ( class_exists( 'STI_Scheduler' ) ) {
			$inherited = (int) STI_Scheduler::interval_seconds();
			if ( $inherited > 0 ) {
				return max( 60, $inherited );
			}
		}
		return 5 * MINUTE_IN_SECONDS;
	}

	public static function set_interval_minutes( $minutes ) {
		$sec = max( 1, (int) $minutes ) * MINUTE_IN_SECONDS;
		update_option( self::INTERVAL_KEY, $sec, true );
		return self::interval_seconds();
	}

	/** سقف روزانه. صفر یعنی بی‌نهایت. */
	public static function daily_cap() {
		return (int) ( class_exists( 'STI_Settings' ) ? STI_Settings::get( 'gs_queue_daily_cap', 0 ) : 0 );
	}

	protected static function published_today() {
		$row = get_option( self::DAILY_KEY, array() );
		$day = wp_date( 'Y-m-d' );
		return ( is_array( $row ) && ( $row['day'] ?? '' ) === $day ) ? (int) $row['count'] : 0;
	}

	protected static function bump_today() {
		$day = wp_date( 'Y-m-d' );
		$row = get_option( self::DAILY_KEY, array() );
		$n   = ( is_array( $row ) && ( $row['day'] ?? '' ) === $day ) ? (int) $row['count'] + 1 : 1;
		update_option( self::DAILY_KEY, array( 'day' => $day, 'count' => $n ), false );
	}

	/* ============================== ترمیم صف ============================== */

	/**
	 * ترمیم آیتم‌هایی که `queued` هستند ولی `scheduled_at` ندارند.
	 *
	 * اینها قربانی باگ whitelist در STI_GS_Session::update() شدند:
	 * enqueue() زمان را می‌فرستاد و بی‌صدا دور ریخته می‌شد.
	 *
	 * قواعد عمدی:
	 *   • فقط `queued` با `scheduled_at IS NULL` هدف است.
	 *   • به `published` و به آیتم‌هایی که زمان دارند دست نمی‌زند.
	 *   • ترتیب صف بر اساس `id` حفظ می‌شود — همان ترتیبی که وارد شده‌اند.
	 *   • فاصله‌ها با همان `interval_seconds()` چیده می‌شود که enqueue()
	 *     استفاده می‌کند، نه تصادفی.
	 *
	 * @return array{found:int, repaired:int, first:?string, last:?string}
	 */
	public static function repair_missing_schedule() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();

		$rows = $wpdb->get_results(
			"SELECT id FROM {$table}
			 WHERE queue_status = 'queued'
			   AND scheduled_at IS NULL
			   AND product_id IS NOT NULL AND product_id > 0
			 ORDER BY id ASC",
			ARRAY_A
		);

		$found = count( (array) $rows );
		if ( ! $found ) {
			return array( 'found' => 0, 'repaired' => 0, 'first' => null, 'last' => null );
		}

		$interval = self::interval_seconds();
		$last     = (int) get_option( self::LAST_PUB_KEY, 0 );
		$next     = max( time() + 60, $last + $interval );

		$repaired = 0;
		$first_at = null;
		$last_at  = null;

		foreach ( $rows as $row ) {
			$when = self::mysql_time( $next );

			$ok = $wpdb->update(
				$table,
				array( 'scheduled_at' => $when ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false !== $ok ) {
				$repaired++;
				$first_at = $first_at ?: $when;
				$last_at  = $when;
				$next    += $interval;
			}
		}

		update_option( self::LAST_PUB_KEY, $next - $interval, false );

		STI_Logger::info( sprintf(
			'گلدن اسکن صف: %d آیتم بدون زمان‌بندی پیدا شد، %d ترمیم شد (از %s تا %s).',
			$found, $repaired, (string) $first_at, (string) $last_at
		) );

		return array( 'found' => $found, 'repaired' => $repaired, 'first' => $first_at, 'last' => $last_at );
	}

	/* =============================== ورود به صف =============================== */

	/**
	 * یک Pipeline Item آماده را در صف می‌گذارد.
	 *
	 * زمان انتشار طوری چیده می‌شود که فاصله‌ی تنظیم‌شده بین محصولات رعایت
	 * شود — نه اینکه ۲۰ محصول با هم منتشر شوند.
	 */
	public static function enqueue( $session_id ) {
		$session = STI_GS_Session::get( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
		}
		if ( empty( $session['product_id'] ) ) {
			return new WP_Error( 'sti_gs_no_product', 'این Session هنوز محصولی ندارد.' );
		}
		if ( 'queued' === $session['queue_status'] || 'published' === $session['queue_status'] ) {
			return true; // idempotent
		}

		$interval = self::interval_seconds();
		$last     = (int) get_option( self::LAST_PUB_KEY, 0 );
		$next     = max( time() + 60, $last + $interval );

		STI_GS_Session::update( $session_id, array(
			'queue_status' => 'queued',
			'scheduled_at' => self::mysql_time( $next ),
		) );

		// آیتم بعدی حداقل یک interval بعد از این یکی نوبت می‌گیرد.
		update_option( self::LAST_PUB_KEY, $next, false );

		STI_GS_Event::log( $session_id, 'publish_queue', 'ok', sprintf(
			'در صف انتشار قرار گرفت — زمان: %s', self::mysql_time( $next )
		) );

		return true;
	}

	/* ================================= تیک ================================= */

	public static function tick() {
		if ( ! self::is_running() ) {
			return;
		}

		$cap = self::daily_cap();
		if ( $cap > 0 && self::published_today() >= $cap ) {
			return; // سقف روزانه پر شده
		}

		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();
		$now   = current_time( 'mysql' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE queue_status = 'queued'
			   AND product_id IS NOT NULL AND product_id > 0
			   AND scheduled_at IS NOT NULL AND scheduled_at <= %s
			   AND ( locked_until IS NULL OR locked_until < %s )
			 ORDER BY priority DESC, scheduled_at ASC
			 LIMIT 1",
			$now, $now
		), ARRAY_A );

		if ( ! $row ) {
			return;
		}

		$session_id = (int) $row['id'];
		$worker_id  = 'gsq_' . wp_generate_password( 6, false );

		// قفل اتمی — تا دو تیک هم‌زمان یک محصول را دو بار منتشر نکنند.
		if ( ! STI_GS_Session::claim( $session_id, $worker_id, self::LOCK_SECONDS ) ) {
			return;
		}

		try {
			self::publish( $session_id, (int) $row['product_id'] );
		} catch ( \Throwable $e ) {
			STI_GS_Session::update( $session_id, array(
				'queue_status' => 'failed',
				'error_reason' => mb_substr( 'PUBLISH_FAILED: ' . $e->getMessage(), 0, 250 ),
			) );
			STI_GS_Event::log( $session_id, 'publish_queue', 'error', 'PUBLISH_FAILED: ' . $e->getMessage() );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
		}
	}

	/**
	 * انتشار واقعی + راستی‌آزمایی.
	 *
	 * §123: «منتشر شد» یعنی پست دوباره خوانده شود و وضعیتش publish باشد،
	 * نه اینکه wp_update_post خطا نداده باشد.
	 */
	protected static function publish( $session_id, $product_id ) {
		if ( 'product' !== get_post_type( $product_id ) ) {
			throw new \RuntimeException( 'محصول #' . $product_id . ' دیگر وجود ندارد.' );
		}

		$result = wp_update_post( array( 'ID' => $product_id, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() );
		}

		clean_post_cache( $product_id );
		if ( 'publish' !== get_post_status( $product_id ) ) {
			throw new \RuntimeException( 'وضعیت پست بعد از انتشار «publish» نشد.' );
		}

		STI_GS_Session::update( $session_id, array(
			'queue_status' => 'published',
			'state'        => 'PUBLISHED',
			'error_reason' => null,
		) );

		self::bump_today();

		STI_GS_Artifact::log( $session_id, 'published', array(
			'product_id' => $product_id,
			'permalink'  => get_permalink( $product_id ),
			'at'         => current_time( 'mysql' ),
		) );
		STI_GS_Event::log( $session_id, 'publish_queue', 'ok',
			'محصول #' . $product_id . ' منتشر شد.' );
	}

	/* ================================ گزارش ================================ */

	/**
	 * فهرست محصولات صف — چون تا امروز هیچ‌جا دیده نمی‌شد.
	 *
	 * صفحه‌ی «صف انتشار» قدیمی جدول sti_sessions مسیر قدیمی را می‌خواند و
	 * محصولات گلدن اسکن را نمی‌بیند؛ برای همین «۰ محصول در صف» نشان
	 * می‌داد در حالی که ۱۲ مورد در صف بود.
	 */
	public static function items( $status = 'queued', $limit = 50 ) {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, file_code, product_id, category_id, queue_status, scheduled_at, state
			 FROM {$table}
			 WHERE queue_status = %s AND product_id IS NOT NULL AND product_id > 0
			 ORDER BY scheduled_at ASC, id ASC
			 LIMIT %d",
			$status, max( 1, (int) $limit )
		), ARRAY_A );

		foreach ( $rows as &$r ) {
			$pid = (int) $r['product_id'];
			$r['title']     = $pid ? get_the_title( $pid ) : '';
			$r['edit_link'] = $pid ? get_edit_post_link( $pid, '' ) : '';
			$r['view_link'] = $pid ? get_permalink( $pid ) : '';
			$r['price']     = ( $pid && function_exists( 'wc_get_product' ) )
				? ( ( $p = wc_get_product( $pid ) ) ? $p->get_regular_price() : '' )
				: '';

			$terms = $pid ? wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) ) : array();
			$r['category'] = ( ! is_wp_error( $terms ) && $terms ) ? implode( '، ', $terms ) : '—';
		}
		unset( $r );

		return $rows;
	}

	public static function stats() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();
		$row = $wpdb->get_row(
			"SELECT
				SUM( queue_status = 'queued' )    AS queued,
				SUM( queue_status = 'published' ) AS published,
				SUM( queue_status = 'failed' )    AS failed,
				MIN( CASE WHEN queue_status = 'queued' THEN scheduled_at END ) AS next_at
			 FROM {$table}", ARRAY_A
		);

		// آیتم‌هایی که هنوز زمان‌بندی ندارند — اگر عددی جز صفر باشد، یعنی
		// همان باگ دوباره جایی رخ داده.
		$unscheduled = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE queue_status = 'queued' AND scheduled_at IS NULL"
		);

		return array(
			'running'         => self::is_running(),
			'unscheduled'     => $unscheduled,
			'queued'          => (int) ( $row['queued'] ?? 0 ),
			'published'       => (int) ( $row['published'] ?? 0 ),
			'failed'          => (int) ( $row['failed'] ?? 0 ),
			'next_at'         => $row['next_at'] ?? null,
			'interval_min'    => (int) round( self::interval_seconds() / 60 ),
			'daily_cap'       => self::daily_cap(),
			'published_today' => self::published_today(),
		);
	}
}
