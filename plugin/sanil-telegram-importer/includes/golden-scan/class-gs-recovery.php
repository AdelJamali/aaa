<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — لایه‌ی بازیابی زیرساخت.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * مرز مالکیت — اصل طراحی این کلاس
 *
 *   Chain Engine  →  منطق دامنه
 *                    CHAIN_STEP · CHAIN_WAITING · CHAIN_FAILED
 *                    انتخاب Node · Evidence · Executable · handoff_steps
 *
 *   Recovery      →  زیرساخت
 *                    قفل کهنه · Session یتیم · صف مرده · دسته‌بندی خطا
 *
 * این کلاس **هرگز**:
 *   • درباره‌ی CHAIN_STEP یا CHAIN_WAITING تصمیم نمی‌گیرد
 *   • chain_mode را تغییر نمی‌دهد
 *   • Node انتخاب نمی‌کند
 *   • handoff_steps را بازنویسی نمی‌کند
 *   • Session را به SCANNED برنمی‌گرداند
 *
 * وقتی چیزی گیر کرده، Recovery فقط **قفل را آزاد می‌کند** و کنار می‌رود.
 * تصمیم بعدی با Chain Engine است.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * چرا این لایه لازم است در حالی که Chain خودش recover() دارد
 *
 * `Chain_Engine::recover()` و `timeout_recovery()` **درون‌درخواستی** هستند:
 * کد باید اجرا شود تا تصمیم بگیرند.
 *
 * اگر درخواست بمیرد — مهلت وب‌سرور، حافظه، ری‌استارت PHP-FPM، kill هاست —
 * هیچ کدی اجرا نمی‌شود که قفل را آزاد کند. Session با `locked_until`
 * منقضی و بدون محرک می‌ماند، و `claim()` تا انقضای TTL همه را رد می‌کند.
 *
 * `STI_GS_Deadline` هم اینجا بی‌اثر است: وقتی پروسه کشته شده، حتی
 * shutdown handler اجرا نمی‌شود.
 *
 *   Deadline  →  «این تماس بیش از N ثانیه طول نکشد»    (از داخل)
 *   Recovery  →  «هیچ قفلی بیش از N دقیقه نماند»        (از بیرون)
 */
class STI_GS_Recovery {

	const HOOK       = 'sti_gs_watchdog';
	const STATS_KEY  = 'sti_gs_recovery_stats';
	const DEAD_STATE = 'DEAD_LETTER';

	/* دسته‌های خطا */
	const TRANSIENT   = 'transient';
	const RECOVERABLE = 'recoverable';
	const PERMANENT   = 'permanent';

	/**
	 * حالت‌های مسیر قدیمی که Recovery مالکشان است.
	 *
	 * اینها هیچ‌ربطی به Chain ندارند: دانلود و مدیا مراحل زیرساختی‌اند و
	 * Chain درباره‌شان تصمیمی نمی‌گیرد. پس بازگرداندنشان نقض مرز نیست.
	 *
	 * هیچ حالت CHAIN_* اینجا نیست و نباید اضافه شود.
	 */
	const LEGACY_REWIND = array(
		'DOWNLOADING'    => 'DOWNLOAD_PENDING',
		'MEDIA_BUILDING' => 'MEDIA_PENDING',
	);

	/**
	 * حالت‌هایی که Chain مالکشان است.
	 *
	 * برای اینها Recovery فقط قفل را آزاد می‌کند و State را دست نمی‌زند.
	 */
	const CHAIN_OWNED = array(
		'CHAIN_STEP', 'CHAIN_WAITING', 'CHAIN_FAILED',
		'WAITING_BOT', 'BUTTON_FOUND',
	);

	/** پس از این مدت بدون قفل فعال، یک رکورد «یتیم» است. */
	const ORPHAN_AFTER = 900;

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
			 * این کران هر ۱۵ دقیقه کافی است.
			 */
			$schedules = wp_get_schedules();
			$every     = isset( $schedules['sti_gs_15min'] ) ? 'sti_gs_15min' : 'hourly';
			wp_schedule_event( time() + 180, $every, self::HOOK );
		}
	}

	/* ========================= دسته‌بندی خطا ========================= */

	/**
	 * سه سطح، بر اساس خطاهای واقعی همین پروژه.
	 *
	 * این تصمیم درباره‌ی **زمان‌بندی تلاش دوباره** است، نه درباره‌ی مسیر
	 * زنجیره — پس نقض مرز نیست.
	 */
	public static function classify( $message, $state = '' ) {
		$m = mb_strtolower( (string) $message );

		// حالت‌هایی که خودِ Chain قطعی اعلام کرده
		if ( in_array( $state, array( 'ERROR_FILE_NOT_FOUND', 'CHAIN_DEPTH_EXCEEDED', 'CHAIN_LOOP_DETECTED' ), true ) ) {
			return self::PERMANENT;
		}

		$permanent = array(
			'no_button', 'دکمه‌ای پیدا نشد', 'payload دکمه لازم است',
			'پیام پیدا نشد', 'message not found', 'دیگر وجود ندارد',
			'file not found', 'فایل یافت نشد', 'channel_access_denied',
			'user_deactivated', 'peer_id_invalid', 'bot_blocked',
		);
		foreach ( $permanent as $n ) {
			if ( false !== mb_strpos( $m, $n ) ) { return self::PERMANENT; }
		}

		$transient = array(
			'flood', 'timeout', 'timed out', 'مهلت', 'connection', 'اتصال',
			'429', '502', '503', '504', 'temporarily', 'موقت',
			'قفل', 'locked', 'claim', 'سهمیه', 'quota',
			'event loop', 'endpoint does not exist',
		);
		foreach ( $transient as $n ) {
			if ( false !== mb_strpos( $m, $n ) ) { return self::TRANSIENT; }
		}

		return self::RECOVERABLE;
	}

	public static function backoff_seconds( $class, $attempts ) {
		$attempts = max( 1, (int) $attempts );
		switch ( $class ) {
			case self::TRANSIENT:
				return (int) min( HOUR_IN_SECONDS, 120 * pow( 2, $attempts - 1 ) );
			case self::RECOVERABLE:
				return (int) min( DAY_IN_SECONDS, 30 * MINUTE_IN_SECONDS * pow( 2, $attempts - 1 ) );
			default:
				return 0;
		}
	}

	/* =========================== صف مرده =========================== */

	public static function to_dead_letter( $session_id, $stage, $reason ) {
		if ( ! class_exists( 'STI_GS_Flags' ) || ! STI_GS_Flags::on( 'dead_letter' ) ) {
			return false;
		}

		// State قبلی نگه داشته می‌شود تا بازگرداندن، تصمیم Chain را خراب نکند.
		$session = STI_GS_Session::get( $session_id );
		$prev    = $session ? (string) $session['state'] : '';

		STI_GS_Session::update( $session_id, array(
			'state'         => self::DEAD_STATE,
			'stage'         => $stage,
			'next_retry_at' => null,
			'error_reason'  => mb_substr( 'DEAD_LETTER: ' . $reason, 0, 250 ),
		) );

		STI_GS_Artifact::log( $session_id, 'dead_letter', array(
			'stage'          => $stage,
			'reason'         => $reason,
			'previous_state' => $prev,
			'at'             => current_time( 'mysql' ),
		) );
		STI_GS_Event::log( $session_id, 'recovery', 'error',
			'به صف مرده منتقل شد (' . $stage . '): ' . $reason );

		self::bump( 'dead' );
		return true;
	}

	/**
	 * بازگرداندن از صف مرده.
	 *
	 * State قبلی از artifact خوانده می‌شود، نه اینکه Recovery خودش
	 * تصمیم بگیرد به کجا برگردد. اگر پیدا نشد، Session دست‌نخورده می‌ماند
	 * و فقط شمارنده صفر می‌شود — بگذار Chain در تیک بعدی تصمیم بگیرد.
	 */
	public static function revive( $session_id ) {
		$session = STI_GS_Session::get( $session_id );
		if ( ! $session || self::DEAD_STATE !== $session['state'] ) {
			return false;
		}

		$prev = self::previous_state( $session_id );

		$patch = array(
			'attempts'      => 0,
			'next_retry_at' => null,
			'error_reason'  => null,
		);
		if ( '' !== $prev ) {
			$patch['state'] = $prev;
		}

		STI_GS_Session::update( $session_id, $patch );
		STI_GS_Event::log( $session_id, 'recovery', 'ok',
			'از صف مرده برگردانده شد' . ( '' !== $prev ? ' به وضعیت ' . $prev : '' ) . '.' );
		return true;
	}

	/** State پیش از انتقال به صف مرده، از روی artifact. */
	protected static function previous_state( $session_id ) {
		global $wpdb;
		$table = STI_GS_DB::artifacts_table();

		$json = $wpdb->get_var( $wpdb->prepare(
			"SELECT payload_json FROM {$table}
			 WHERE session_id = %d AND type = %s
			 ORDER BY id DESC LIMIT 1",
			(int) $session_id, 'dead_letter'
		) );

		if ( ! $json ) {
			return '';
		}
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? (string) ( $data['previous_state'] ?? '' ) : '';
	}

	public static function revive_all() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();

		$ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE state = %s LIMIT 200", self::DEAD_STATE
		) );

		$n = 0;
		foreach ( $ids as $id ) {
			if ( self::revive( (int) $id ) ) { $n++; }
		}
		return $n;
	}

	/* =========================== Watchdog =========================== */

	public static function tick( $force = false ) {
		/*
		 * ۱۰.۹.۳ — دروازه‌ی اتمیک: با کران درون‌خطی، دو اجرای هم‌زمان
		 * (یا schedule تکراری) دیگر هر دو tick را اجرا نمی‌کنند.
		 * دکمه‌ی دستیِ «اجرای watchdog» با $force=true این دروازه را
		 * دور می‌زند — عمل دستی کاربر همیشه اجرا می‌شود.
		 */
		if ( ! $force && class_exists( 'STI_GS_Cron_Gate' )
			&& ! STI_GS_Cron_Gate::pass( 'watchdog', 600 ) ) {
			return;
		}

		if ( ! class_exists( 'STI_GS_Flags' ) || ! STI_GS_Flags::on( 'watchdog' ) ) {
			return;
		}
		if ( function_exists( 'sti_v7_safe_mode' ) && sti_v7_safe_mode() ) {
			return;
		}
		if ( class_exists( 'STI_GS_DB' ) && STI_GS_DB::is_halted() ) {
			return;
		}

		self::release_stale_locks();
		self::rewind_legacy_stages();
		self::release_stale_steps();
		self::reap_ipc_orphans();
	}

	/**
	 * ۴) 10.9.3 — جمع‌آوری worker های یتیمِ madeline-ipc.
	 *
	 * در MadelineProto v8، هر سشن یک فرآیند worker IPC جداگانه دارد که
	 * با درخواست وب زنده نمی‌ماند: اگر درخواست وسط کار بمیرد
	 * (timeout/OOM/kill هاست)، worker زنده می‌ماند و حافظه‌اش آزاد نمی‌شود.
	 * انباشت این workerها یکی از ریشه‌های «هاست هر چند وقت یک‌بار
	 * قفل می‌کند و بعد از چند دقیقه درست می‌شود» است.
	 *
	 * STI_MTProto::ipc_heal() فقط workerهای **همین سایت** (با دامنه‌ی
	 * دقیق مسیر سشن) را می‌بندد — روی هاست اشتراکی به workerهای سایر
	 * سایت‌ها دست نمی‌زند.
	 *
	 * بیش از یک worker زنده برای یک سشن، غیرطبیعی است (سابقه‌ی خرابی
	 * باقی مانده). سقف با $max_alive تنظیم می‌شود.
	 */
	protected static function reap_ipc_orphans( $max_alive = 1 ) {
		if ( ! class_exists( 'STI_MTProto' ) ) {
			return;
		}
		try {
			$count = STI_MTProto::ipc_worker_count();
			if ( $count < 0 || $count <= $max_alive ) {
				return;
			}
			$report = STI_MTProto::ipc_heal( 'watchdog' );
			STI_GS_Event::log( 0, 'recovery', 'ok', sprintf(
				'Watchdog: %d worker زنده‌ی madeline-ipc برای سشن این سایت پیدا شد (سقف: %d) — %d بسته شد و %d فایل IPC فرسوده پاک شد.',
				$count,
				$max_alive,
				$report['killed'],
				$report['stale_files']
			) );
			self::bump( 'ipc_orphans' );
		} catch ( \Throwable $e ) {
			// watchdog هرگز نباید روی خطای غیرحیاتی متوقف شود
		}
	}

	/**
	 * ۱) آزادسازی قفل کهنه — بدون هیچ تصمیمی درباره‌ی State.
	 *
	 * این هسته‌ی مرز مالکیت است. برای Sessionهایی که Chain مالکشان است،
	 * تنها کاری که Recovery می‌کند برداشتن قفل رهاشده است. Chain در تیک
	 * بعدی خودش می‌بیند و تصمیم می‌گیرد.
	 */
	protected static function release_stale_locks() {
		global $wpdb;
		$table  = STI_GS_DB::pipeline_items_table();
		$cutoff = self::mysql_time( time() - self::ORPHAN_AFTER );
		$now    = current_time( 'mysql' );

		$states = self::CHAIN_OWNED;
		$place  = implode( ',', array_fill( 0, count( $states ), '%s' ) );

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, state FROM {$table}
			 WHERE state IN ({$place})
			   AND worker_id IS NOT NULL
			   AND locked_until IS NOT NULL
			   AND locked_until < %s
			   AND updated_at < %s
			 ORDER BY id ASC LIMIT 20",
			array_merge( $states, array( $now, $cutoff ) )
		), ARRAY_A );

		foreach ( $rows as $row ) {
			$wpdb->update(
				$table,
				array( 'locked_until' => null, 'worker_id' => null ),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			STI_GS_Event::log( (int) $row['id'], 'recovery', 'ok', sprintf(
				'Watchdog: قفل رهاشده در وضعیت «%s» آزاد شد. تصمیم بعدی با Chain Engine است.',
				$row['state']
			) );
			self::bump( 'recovered' );
		}
	}

	/**
	 * ۲) مراحل زیرساختی مسیر قدیمی.
	 *
	 * دانلود و مدیا مال Chain نیستند — Chain درباره‌شان تصمیمی نمی‌گیرد.
	 * پس بازگرداندنشان به PENDING نقض مرز نیست.
	 */
	protected static function rewind_legacy_stages() {
		global $wpdb;
		$table  = STI_GS_DB::pipeline_items_table();
		$cutoff = self::mysql_time( time() - self::ORPHAN_AFTER );

		$states = array_keys( self::LEGACY_REWIND );
		$place  = implode( ',', array_fill( 0, count( $states ), '%s' ) );

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, state FROM {$table}
			 WHERE state IN ({$place})
			   AND ( locked_until IS NULL OR locked_until < %s )
			   AND updated_at < %s
			 ORDER BY id ASC LIMIT 20",
			array_merge( $states, array( current_time( 'mysql' ), $cutoff ) )
		), ARRAY_A );

		foreach ( $rows as $row ) {
			$state  = (string) $row['state'];
			$target = self::LEGACY_REWIND[ $state ];

			if ( class_exists( 'STI_GS_Flags' ) && ! STI_GS_Flags::on( 'pending_states' ) ) {
				$fallback = array( 'DOWNLOADING' => 'FILE_MATCHED', 'MEDIA_BUILDING' => 'STORED' );
				$target   = $fallback[ $state ] ?? $target;
			}

			STI_GS_Session::update( (int) $row['id'], array(
				'state'        => $target,
				'stage'        => 'watchdog',
				'error_reason' => null,
			) );

			STI_GS_Event::log( (int) $row['id'], 'recovery', 'ok', sprintf(
				'Watchdog: مرحله‌ی «%s» ناتمام مانده بود (قفل منقضی) — بازگشت به %s.',
				$state, $target
			) );
			self::bump( 'recovered' );
		}
	}

	/**
	 * ۳) گام‌های زنجیره که در حالت running رها شده‌اند.
	 *
	 * فقط **قفل** آزاد می‌شود. `status` دست‌نخورده می‌ماند و هیچ گام تازه‌ای
	 * ساخته نمی‌شود — تصمیم اینکه با این گام چه شود مال Chain است.
	 *
	 * اگر جدول ستون قفل نداشته باشد، هیچ کاری انجام نمی‌شود؛ بازنویسی
	 * status نقض مرز بود.
	 */
	protected static function release_stale_steps() {
		if ( ! class_exists( 'STI_GS_Handoff_Steps' ) || ! class_exists( 'STI_GS_Flags' )
			|| ! STI_GS_Flags::on( 'chain_watchdog' ) ) {
			return;
		}

		global $wpdb;
		$table = STI_GS_DB::handoff_steps_table();

		$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		if ( ! in_array( 'locked_until', $cols, true ) ) {
			return; // ستون قفل ندارد — کاری از دست Recovery برنمی‌آید
		}

		$cutoff = self::mysql_time( time() - self::ORPHAN_AFTER );

		$n = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET locked_until = NULL, worker_id = NULL
			 WHERE status = %s AND locked_until IS NOT NULL
			   AND locked_until < %s AND updated_at < %s",
			STI_GS_Handoff_Steps::STATUS_RUNNING, current_time( 'mysql' ), $cutoff
		) );

		if ( $n > 0 ) {
			STI_Logger::info( 'گلدن اسکن Watchdog: قفل ' . $n . ' گام رهاشده آزاد شد.' );
			self::bump( 'recovered' );
		}
	}

	/* ============================ آمار ============================ */

	protected static function mysql_time( $ts ) {
		return gmdate( 'Y-m-d H:i:s', (int) $ts + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
	}

	protected static function bump( $key ) {
		$day = wp_date( 'Y-m-d' );
		$s   = get_option( self::STATS_KEY, array() );
		if ( ! is_array( $s ) || ( $s['day'] ?? '' ) !== $day ) {
			$s = array( 'day' => $day, 'recovered' => 0, 'dead' => 0 );
		}
		$s[ $key ] = (int) ( $s[ $key ] ?? 0 ) + 1;
		update_option( self::STATS_KEY, $s, false );
	}

	public static function stats() {
		global $wpdb;
		$table  = STI_GS_DB::pipeline_items_table();
		$cutoff = self::mysql_time( time() - self::ORPHAN_AFTER );
		$now    = current_time( 'mysql' );

		$chain  = self::CHAIN_OWNED;
		$cplace = implode( ',', array_fill( 0, count( $chain ), '%s' ) );
		$stale_locks = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE state IN ({$cplace}) AND worker_id IS NOT NULL
			   AND locked_until IS NOT NULL AND locked_until < %s AND updated_at < %s",
			array_merge( $chain, array( $now, $cutoff ) )
		) );

		$legacy  = array_keys( self::LEGACY_REWIND );
		$lplace  = implode( ',', array_fill( 0, count( $legacy ), '%s' ) );
		$orphans = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE state IN ({$lplace})
			   AND ( locked_until IS NULL OR locked_until < %s ) AND updated_at < %s",
			array_merge( $legacy, array( $now, $cutoff ) )
		) );

		$dead = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE state = %s", self::DEAD_STATE
		) );

		$retry = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE next_retry_at IS NOT NULL AND next_retry_at > %s", $now
		) );

		$today = get_option( self::STATS_KEY, array() );
		$today = is_array( $today ) ? $today : array();

		return array(
			'enabled'         => class_exists( 'STI_GS_Flags' ) && STI_GS_Flags::on( 'watchdog' ),
			'stale_locks'     => $stale_locks,
			'orphans'         => $orphans,
			'dead'            => $dead,
			'retry_queue'     => $retry,
			'recovered_today' => (int) ( $today['recovered'] ?? 0 ),
			'dead_today'      => (int) ( $today['dead'] ?? 0 ),
			'deadline_mode'   => class_exists( 'STI_GS_Deadline' ) && STI_GS_Deadline::real_timeout_available()
				? 'signal' : 'time_limit',
			'next_tick'       => wp_next_scheduled( self::HOOK ),
		);
	}

	public static function dead_letters( $limit = 25 ) {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, file_code, stage, error_reason, updated_at
			 FROM {$table} WHERE state = %s ORDER BY updated_at DESC LIMIT %d",
			self::DEAD_STATE, max( 1, (int) $limit )
		), ARRAY_A );
	}
}
