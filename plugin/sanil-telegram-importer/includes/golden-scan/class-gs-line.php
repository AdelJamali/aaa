<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — ۱۰.۱۱ — وضعیت خط تولید (Start/Stop واقعی).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * وضعیت خط یکی از این پنج حالت است:
 *
 *   STOPPED   — خط خاموش؛ هیچ Session جدیدی شروع نمی‌شود.
 *   RUNNING   — خط فعال؛ Worker هر تیک Session برمی‌دارد.
 *   PAUSING   — STOP درخواست شده؛ Sessionهایی که وسط Stage هستند
 *               همان Stage را تا انتها می‌رسانند (graceful)؛ هیچ
 *               process ناگهانی kill نمی‌شود؛ وقتی دیگر قفل زنده‌ای
 *               نباشد → STOPPED.
 *   DEGRADED  — Governor در EMERGENCY است (فشار منابع)؛ خط کار
 *               می‌کند ولی کارهای سنگین خفه‌شده‌اند.
 *   ERROR     — آخرین تیک Worker با خطا تمام شد؛ تا تیک بعدی که
 *               موفق شود، خط روی ERROR می‌ماند (خودترمیمی).
 *
 * ذخیره‌سازی **اتومیک و سازگار با WordPress** (10.12.9):
 *   • ردیف option در activation یک‌بار با add_option() ساخته می‌شود
 *     (autoload=no)؛ مسیر recovery در رانتایم = ensure_row() — امن و
 *     race-aware، فقط با Option API استاندارد.
 *   • transition() = یک UPDATE شرطی (compare-and-set) روی **ردیف موجود**.
 *     مقایسه داخل همان جمله‌ی UPDATE در دیتابیس انجام می‌شود → race/TOCTOU
 *     وجود ندارد.
 *   • set_state()  = update_option() + **read-back از دیتابیس**؛ فقط بعد
 *     از write تأییدشده cache sync می‌شود و شکست به صورت ERROR واقعی
 *     ثبت و به caller منتقل می‌گردد.
 *
 *   ⚠️ 10.12.9 — ریشه‌یابی P0: نسخه‌های قبلی برای ردیفِ غایب از
 *   `INSERT INTO wp_options (option_name, option_value, option_modified,
 *   option_autoload)` استفاده می‌کردند؛ ستون `option_modified` در schema
 *   استاندارد wp_options وجود ندارد و هر INSERT با SQL error شکست
 *   می‌خورد — یعنی وضعیت هرگز persist نمی‌شد و state() همیشه پیش‌فرض
 *   STOPPED را برمی‌گرداند. این الگو حذف شد؛ هیچ INSERT خام روی
 *   wp_options باقی نمانده است.
 *
 * STOP هیچ داده‌ای حذف نمی‌کند، Worker را غیرفعال نمی‌کند و کران را
 * نمی‌بندد — فقط «نوبت‌دهی» متوقف می‌شود. START یک continuation واقعی
 * است: همان Sessionها، از همان Stage، ادامه می‌دهند.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Line {

	const OPTION   = 'sti_gs_line_state';
	const REQ_KEY  = 'sti_gs_line_request';

	const STOPPED  = 'STOPPED';
	const RUNNING  = 'RUNNING';
	const PAUSING  = 'PAUSING';
	const DEGRADED = 'DEGRADED';
	const ERROR    = 'ERROR';

	const STATES = array( self::STOPPED, self::RUNNING, self::PAUSING, self::DEGRADED, self::ERROR );

	/* ============================ وضعیت ============================ */

	/** وضعیت فعلی (مقدار نامعتبر → STOPPED). */
	public static function state() {
		$s = get_option( self::OPTION, self::STOPPED );
		return in_array( $s, self::STATES, true ) ? $s : self::STOPPED;
	}

	/**
	 * گذار اتمیک compare-and-set (10.12.9 — persistence سازگار).
	 *
	 * فقط UPDATE شرطی روی **ردیف موجود**؛ مقایسه داخل همان UPDATE در
	 * دیتابیس انجام می‌شود (بدون TOCTOU). اگر ردیف غایب باشد، اول با
	 * ensure_row() از طریق Option API استاندارد ساخته می‌شود.
	 * (مسیر INSERT خام با ستون option_modified حذف شد — آن ستون در
	 * schema استاندارد wp_options وجود ندارد و هر write شکست می‌خورد.)
	 *
	 * @param string $from وضعیت مورد انتظار
	 * @param string $to   وضعیت جدید
	 * @return bool true فقط اگر واقعاً گذار انجام شده باشد.
	 */
	public static function transition( $from, $to ) {
		if ( ! in_array( $from, self::STATES, true ) || ! in_array( $to, self::STATES, true ) ) {
			return false;
		}
		if ( ! self::ensure_row() ) {
			return false;
		}
		global $wpdb;

		$affected = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s
			 WHERE option_name = %s AND option_value = %s",
			$to, self::OPTION, $from
		) );
		if ( $affected > 0 ) {
			/* object cache فقط بعد از write موفق sync می‌شود. */
			wp_cache_set( self::OPTION, $to, 'options' );
		}
		return $affected > 0;
	}

	/**
	 * Recovery (10.12.9): اگر ردیف option غایب یا خراب باشد، با Option API
	 * استانداردWordPress آن را بسازد/نرمال کند — امن و race-aware.
	 *
	 * add_option() در رقابت فقط برای یک درخواست true برمی‌گرداند؛ بقیه
	 * فقط می‌بینند که ردیف حالا وجود دارد (idempotent). هیچ INSERT خام
	 * روی wp_options انجام نمی‌شود.
	 *
	 * @return bool true = ردیف موجود است (قبلاً یا تازه ساخته‌شده).
	 */
	public static function ensure_row() {
		$current = get_option( self::OPTION, '__STI_UNSET__' );
		if ( '__STI_UNSET__' === $current ) {
			add_option( self::OPTION, self::STOPPED, '', 'no' );
			/* در رقابت، درخواست دیگر ممکن است ساخته باشد — دوباره بخوان. */
			$current = get_option( self::OPTION, '__STI_UNSET__' );
			return '__STI_UNSET__' !== $current;
		}
		if ( ! in_array( $current, self::STATES, true ) ) {
			/*
			 * مقدار نامعتبر (ردیف خراب از write شکست‌خوردهٔ قدیمی): به همان
			 * مقدار منطقی که state() همیشه برایش برمی‌گرداند نرمال شود تا
			 * قرارداد CAS معتبر بماند.
			 */
			update_option( self::OPTION, self::STOPPED, 'no' );
			wp_cache_set( self::OPTION, self::STOPPED, 'options' );
			if ( class_exists( 'STI_Logger' ) ) {
				STI_Logger::warning( 'گلدن اسکن خط تولید: مقدار option نامعتبر بود (' . var_export( $current, true ) . ') — نرمال شد به ' . self::STOPPED );
			}
		}
		return true;
	}

	/**
	 * مقدار **واقعی** option را مستقیم از دیتابیس بخواند (نه از cache) —
	 * تنها منبع حقیقت برای سؤال «آیا واقعاً persist شد؟».
	 *
	 * @return string|false مقدار ذخیره‌شده؛ false اگر ردیف وجود نداشته باشد.
	 */
	public static function read_back() {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION
		) );
		return null === $v ? false : (string) $v;
	}

	/** ثبت خطای persistence واقعی — هرگز پنهان نمی‌شود. */
	protected static function log_persistence_failure( $op, $requested, $actual, $reason = '' ) {
		global $wpdb;
		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::error( sprintf(
				'LINE_PERSISTENCE_FAILED op=%s requested=%s actual=%s reason=%s last_error=%s',
				$op,
				$requested,
				var_export( $actual, true ),
				$reason,
				$wpdb->last_error
			) );
		}
	}

	/**
	 * تنظیم صریح (10.12.9 — قرارداد persistence، فقط برای عمل صریح کاربر):
	 *
	 *   1. مقدار state را validate کند.
	 *   2. با WordPress Option API ذخیره کند (update_option روی ردیف موجود؛
	 *      ردیف غایب پیش‌تر با ensure_row()/add_option() ساخته می‌شود).
	 *   3. نتیجهٔ persistence را بررسی کند.
	 *   4. بعد از write، state را دوباره **read-back** کند (مستقیم از DB).
	 *   5. اگر مقدار واقعی ≠ موردنظر باشد → ERROR واقعی ثبت شود
	 *      (LINE_PERSISTENCE_FAILED با last_error).
	 *   6. شکست از caller پنهان نمی‌شود: مقدار برگشتی، وضعیت واقعی است،
	 *      نه مقدار درخواستی.
	 *   7. object cache فقط بعد از write موفق synchronize شود.
	 *
	 * (INSERT خام با ستون option_modified حذف شد — schema غیرواقعی بود.)
	 *
	 * @return string وضعیت واقعی پس از write.
	 */
	public static function set_state( $to, $reason = '' ) {
		/* 1) validate */
		if ( ! in_array( $to, self::STATES, true ) ) {
			return self::state();
		}

		/* recovery: ردیف باید موجود باشد (add_option امن و race-aware). */
		if ( ! self::ensure_row() ) {
			self::log_persistence_failure( 'set_state', $to, self::read_back(), $reason );
			return self::state();
		}

		/* 2) WordPress Option API.
		 * نکته: update_option وقتی مقدار تغییری نکند false برمی‌گرداند —
		 * پس return value منبع حقیقت نیست؛ read-back است. */
		update_option( self::OPTION, $to, 'no' );

		/* 4) read-back مستقیم از دیتابیس. */
		$actual = self::read_back();

		if ( $actual !== $to ) {
			/* 5) خطای واقعی. */
			self::log_persistence_failure( 'set_state', $to, $actual, $reason );
			return self::state();
		}

		/* 7) cache فقط بعد از write تأییدشده. */
		wp_cache_set( self::OPTION, $to, 'options' );

		if ( '' !== $reason && class_exists( 'STI_Logger' ) ) {
			/* خط موفقیت فقط وقتی نوشته می‌شود که واقعاً persist شده باشد. */
			STI_Logger::info( 'گلدن اسکن خط تولید: وضعیت → ' . $to . ' (' . $reason . ')' );
		}

		/* 6) مقدار واقعی برگشتی است. */
		return $to;
	}

	/* ============================ START / STOP ============================ */

	/**
	 * START — continuation واقعی.
	 *
	 *   • Worker تضمین می‌شود enabled باشد
	 *   • زمان‌بندی کران تضمین می‌شود (اگر گم شده باشد، تیک فوری)
	 *   • وضعیت → RUNNING (از هر حالتی: STOPPED/PAUSING/ERROR/DEGRADED)
	 *
	 * Sessionها و Queue دست‌نخورده‌اند — از همان جایی که هستند ادامه می‌دهند.
	 */
	public static function start() {
		if ( class_exists( 'STI_GS_Auto_Worker' ) ) {
			STI_GS_Auto_Worker::set_enabled( true );
		}

		/* تضمین زمان‌بندی (اگر کران گم شده باشد، تیک فوری + spawn) */
		if ( class_exists( 'STI_GS_Auto_Worker' ) && ! wp_next_scheduled( STI_GS_Auto_Worker::HOOK ) ) {
			$schedules = wp_get_schedules();
			$every     = isset( $schedules['sti_gs_5min'] ) ? 'sti_gs_5min' : 'hourly';
			wp_schedule_event( time() + 10, $every, STI_GS_Auto_Worker::HOOK );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}

		$actual = self::set_state( self::RUNNING, 'START خط توسط کاربر' );

		/*
		 * 10.12.9 — read-back: موفقیت HTTP ≠ موفقیت persistence.
		 * اگر state بعد از write واقعاً RUNNING نیست، خطای صریح با
		 * تمام زمینه‌ها ثبت می‌شود و به caller منتقل می‌گردد.
		 */
		if ( self::RUNNING !== $actual ) {
			global $wpdb;
			$next_tick = class_exists( 'STI_GS_Auto_Worker' ) ? wp_next_scheduled( STI_GS_Auto_Worker::HOOK ) : false;
			if ( class_exists( 'STI_Logger' ) ) {
				STI_Logger::error( sprintf(
					'LINE_START_FAILED requested_state=%s actual_state=%s last_error=%s worker_enabled=%s cron_next_tick=%s',
					self::RUNNING,
					var_export( $actual, true ),
					$wpdb->last_error,
					class_exists( 'STI_GS_Auto_Worker' ) ? var_export( STI_GS_Auto_Worker::is_enabled(), true ) : 'n/a',
					$next_tick ? (int) $next_tick : 'none'
				) );
			}
		}
		return $actual;
	}

	/**
	 * STOP — graceful.
	 *
	 *   • وضعیت → PAUSING: تیک‌های بعدی Session جدید برنمی‌دارند.
	 *   • هیچ process kill نمی‌شود؛ Stage در حال اجرا در همان
	 *     درخواست تا انتها می‌رسد (هر Stage یک درخواست جداست).
	 *   • هیچ داده/Queue حذف نمی‌شود؛ Worker/کران غیرفعال نمی‌شوند.
	 *   • وقتی دیگر قفل زنده‌ای نماند → STOPPED (finalize_pause).
	 */
	public static function stop() {
		if ( in_array( self::state(), array( self::RUNNING, self::DEGRADED, self::ERROR ), true ) ) {
			self::set_state( self::PAUSING, 'STOP خط توسط کاربر' );
		}
		self::finalize_pause();
		$actual = self::state();
		/*
		 * 10.12.9 — verify: STOP باید خط را در PAUSING (قفل زنده مانده) یا
		 * STOPPED بگذارد؛ هر چیز دیگر = خطای persistence صریح.
		 */
		if ( ! in_array( $actual, array( self::PAUSING, self::STOPPED ), true ) ) {
			self::log_persistence_failure( 'stop', self::PAUSING . '/' . self::STOPPED, $actual, 'STOP خط توسط کاربر' );
		}
		return $actual;
	}

	/** PAUSING → STOPPED فقط وقتی دیگر Session با قفل زنده‌ای نباشد. */
	public static function finalize_pause() {
		if ( self::PAUSING !== self::state() ) {
			return false;
		}
		$active = class_exists( 'STI_GS_Auto_Worker' ) ? STI_GS_Auto_Worker::active_sessions() : 0;
		if ( $active > 0 ) {
			return false; // هنوز Stage در حال اجرا است — صبر
		}
		return self::transition( self::PAUSING, self::STOPPED );
	}

	/* ====================== گذارهای خودکار (Governor/tick) ====================== */

	/** Governor EMERGENCY → خط DEGRADED (فقط اگر الان RUNNING باشد). */
	public static function mark_degraded() {
		return self::transition( self::RUNNING, self::DEGRADED );
	}

	/** Governor OK → برگشت از DEGRADED (فقط اگر الان DEGRADED باشد). */
	public static function recover_from_degraded() {
		return self::transition( self::DEGRADED, self::RUNNING );
	}

	/** تیک Worker با خطا تمام شد → ERROR (فقط اگر RUNNING باشد). */
	public static function mark_error() {
		return self::transition( self::RUNNING, self::ERROR );
	}

	/** تیک موفق → پاک‌سازی ERROR (خودترمیمی؛ فقط اگر ERROR باشد). */
	public static function clear_error() {
		return self::transition( self::ERROR, self::RUNNING );
	}

	/* ====================== درخواست (Total requested / created) ====================== */

	/** ثبت درخواست Start (تعداد خواسته‌شده + تعداد ساخته‌شده). */
	public static function record_request( $requested, $created ) {
		update_option( self::REQ_KEY, array(
			'count'   => max( 0, (int) $requested ),
			'created' => max( 0, (int) $created ),
			'at'      => current_time( 'mysql' ),
		), false );
	}

	public static function request() {
		$r = get_option( self::REQ_KEY, array() );
		return is_array( $r ) ? $r : array();
	}

	/* ====================== مانیتور زنده (P6) ====================== */

	/**
	 * داده‌ی کاملِ مانیتور برای AJAX poll (سبک: ۴ کوئری شاخص‌دار).
	 * هرگز Fatal نمی‌دهد — در صورت خطا، همان چیزی که دارد برمی‌گرداند.
	 */
	public static function monitor() {
		$out = array(
			'line'     => array( 'state' => self::state() ),
			'request'  => self::request(),
			'summary'  => null,
			'current'  => null,
			'sessions' => array(),
			'events'   => array(),
			'ts'       => time(),
			'ok'       => false,
		);

		try {
			if ( ! class_exists( 'STI_GS_DB' ) || ! class_exists( 'STI_GS_Stage' ) ) {
				return $out;
			}
			global $wpdb;
			$tbl = STI_GS_DB::pipeline_items_table();
			$now = current_time( 'mysql' );

			/* 1) خلاصه‌ی stateها */
			$rows = (array) $wpdb->get_results( "SELECT state, COUNT(*) AS n FROM {$tbl} GROUP BY state", ARRAY_A );
			$s    = STI_GS_Stage::summarize( $rows );
			$out['summary'] = array(
				'requested'  => (int) ( $out['request']['count'] ?? 0 ),
				'created'    => (int) ( $out['request']['created'] ?? 0 ),
				'processing' => (int) $s['by_status'][ STI_GS_Stage::RUNNING ],
				'waiting'    => (int) $s['by_status'][ STI_GS_Stage::WAITING ] + (int) $s['by_status'][ STI_GS_Stage::PENDING ],
				'failed'     => (int) $s['by_status'][ STI_GS_Stage::FAILED ],
				'published'  => (int) $s['final'][ STI_GS_Stage::FINAL_PUBLISHED ],
				'review'     => (int) $s['final'][ STI_GS_Stage::FINAL_REVIEW ],
				'cancelled'  => (int) $s['final'][ STI_GS_Stage::FINAL_CANCELLED ],
				'unknown'    => (int) array_sum( $s['unknown'] ),
			);

			/* 2) فعالیت فعلی (Session با قفل زنده) */
			$cur_rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, file_name, state, attempts, worker_id, locked_until, updated_at
				 FROM {$tbl} WHERE locked_until IS NOT NULL AND locked_until > %s
				 ORDER BY locked_until DESC LIMIT 3",
				$now
			), ARRAY_A );
			if ( ! empty( $cur_rows ) ) {
				$c     = $cur_rows[0];
				$stage = STI_GS_Stage::stage_of( (string) $c['state'] );
				$idx   = $stage ? array_search( $stage, STI_GS_Stage::STAGE_ORDER, true ) : false;
				$out['current'] = array(
					'id'           => (int) $c['id'],
					'file'         => mb_substr( (string) $c['file_name'], 0, 40 ),
					'state'        => (string) $c['state'],
					'label'        => STI_GS_Stage::label( (string) $c['state'] ),
					'stage'        => $stage,
					'status'       => STI_GS_Stage::status_of( (string) $c['state'] ),
					'stage_idx'    => false === $idx ? -1 : $idx,
					'attempts'     => (int) $c['attempts'],
					'retry_limit'  => class_exists( 'STI_GS_Auto_Worker' ) ? STI_GS_Auto_Worker::retry_limit() : 5,
					'worker_id'    => (string) $c['worker_id'],
					'updated_at'   => (string) $c['updated_at'],
					'queue'        => count( $cur_rows ) - 1,
				);
			}

			/* 3) فهرست Sessionهای فعال (نهایی‌ها و SKIPPED نه) */
			$terminal = array_merge(
				STI_GS_Stage::review_states(),
				STI_GS_Stage::published_states(),
				STI_GS_Stage::cancelled_states(),
				array( 'SKIPPED' )
			);
			$place = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
			$items = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, file_name, state, attempts, updated_at, locked_until
				 FROM {$tbl}
				 WHERE state NOT IN ({$place})
				 ORDER BY ( locked_until IS NOT NULL AND locked_until > %s ) DESC, updated_at DESC
				 LIMIT 40",
				array_merge( $terminal, array( $now ) )
			), ARRAY_A );
			foreach ( $items as $it ) {
				$stage = STI_GS_Stage::stage_of( (string) $it['state'] );
				$idx   = $stage ? array_search( $stage, STI_GS_Stage::STAGE_ORDER, true ) : false;
				$out['sessions'][] = array(
					'id'          => (int) $it['id'],
					'file'        => mb_substr( (string) $it['file_name'], 0, 30 ),
					'state'       => (string) $it['state'],
					'label'       => STI_GS_Stage::label( (string) $it['state'] ),
					'stage_idx'   => false === $idx ? -1 : $idx,
					'attempts'    => (int) $it['attempts'],
					'updated_at'  => (string) $it['updated_at'],
					'active'      => ( ! empty( $it['locked_until'] ) && strtotime( (string) $it['locked_until'] ) > time() ),
				);
			}

			/* 4) جریان رویدادها */
			if ( class_exists( 'STI_GS_Event' ) ) {
				$ev = (array) $wpdb->get_results(
					'SELECT session_id, stage, result, message, created_at FROM ' . STI_GS_Event::table() . ' ORDER BY id DESC LIMIT 30',
					ARRAY_A
				);
				foreach ( $ev as $e ) {
					$out['events'][] = array(
						't' => (string) $e['created_at'],
						's' => (int) $e['session_id'],
						'k' => (string) $e['stage'],
						'r' => (string) $e['result'],
						'm' => mb_substr( (string) $e['message'], 0, 140 ),
					);
				}
			}

			/* Governor (وضعیت‌سنجی مجدد نمی‌کند — آخرین ارزیابی را می‌خواند) */
			if ( class_exists( 'STI_GS_Governor' ) ) {
				$g = STI_GS_Governor::status();
				$out['line']['level']   = isset( $g['level'] ) ? $g['level'] : 'OK';
				$out['line']['factor']  = isset( $g['factor'] ) ? (float) $g['factor'] : 1.0;
				$out['line']['reasons'] = isset( $g['reasons'] ) ? (array) $g['reasons'] : array();
			}

			/* آمار خودترمیمی (Run Log) — یک کوئری SUM */
			if ( class_exists( 'STI_GS_Run_Log' ) ) {
				$runs = STI_GS_Run_Log::summary();
				if ( is_array( $runs ) && ! empty( $runs ) ) {
					$out['healing'] = $runs;
				}
			}

			$out['ok'] = true;
		} catch ( \Throwable $e ) {
			/* مانیتور هرگز نباید Fatal بدهد — وضعیت موجود برمی‌گردد. */
			if ( class_exists( 'STI_Logger' ) ) {
				STI_Logger::warning( 'Line monitor: خطا در جمع‌آوری داده: ' . $e->getMessage() );
			}
		}

		return $out;
	}
}
