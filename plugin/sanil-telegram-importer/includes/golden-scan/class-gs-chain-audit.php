<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — REAL AUTOMATION CHAIN AUDIT (Internal Runtime Diagnostic — 10.12.3)
 *
 * «🩺 تست واقعی زنجیره اتوماسیون» — با یک کلیک از داخل پنل، روی Runtime واقعی
 * همان سایت اجرا می‌شود و زنجیره کامل را با داده واقعی گزارش می‌کند:
 *
 *   Profile → Profile Item → Message → Selection → Watcher State → Watcher UI
 *   → AJAX Registration → run() path → create_sessions (read-only simulation)
 *   → Session → Pipeline → Worker pickup → Telegram → Fiber/Memory
 *
 * ضمانت ایمنی (قانون تست):
 *   - فقط SELECT / SHOW / get_option / class_exists / method_exists.
 *   - هیچ INSERT / UPDATE / DELETE، هیچ set_enabled، هیچ run()، هیچ update_option.
 *   - شبیه‌سازی create_sessions دقیقاً همان query انتخاب و همان شرط‌های
 *     create_from_profile_item را اجرا می‌کند — بدون INSERT.
 *   - دکمه‌های اختیاری «تست واقعی» (Toggle / Run) تنها بخش تغییر‌دهنده‌اند و
 *     فقط با کلیک صریح کاربر (با confirm) endpointهای موجود واقعی را صدا می‌زنند.
 *
 * REMOVE AFTER AUDIT (با درخواست ارباب‌کار — ابزار موقت تشخیصی).
 */
class STI_GS_Chain_Audit {

	/** وضعیت‌های ترمینال — عین کد class-gs-auto-worker.php (const TERMINAL, L63). */
	const TERMINAL = array( 'REVIEW_READY', 'PUBLISHED', 'SKIPPED', 'NEEDS_REVIEW', 'ERROR_FILE_NOT_FOUND', 'DEAD_LETTER' );

	/* ============================ AJAX ============================ */

	public static function init() {
		add_action( 'wp_ajax_sti_gs_chain_audit', array( __CLASS__, 'ajax_run' ) );
	}

	/**
	 * فقط خواندنی. همان check_ajaxِ test-wizard (nonce + capability).
	 */
	public static function ajax_run() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
		wp_send_json_success( self::run() );
	}

	/* ============================ ابزارهای read-only ============================ */

	protected static function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/** @return int تعداد ردیف، یا -1 اگر جدول وجود ندارد. */
	protected static function count_table( $table ) {
		global $wpdb;
		if ( ! self::table_exists( $table ) ) {
			return -1;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
	}

	/**
	 * registration واقعی hook از Runtime (نه جستجوی متن):
	 * خواندن $wp_filter و استخراج callback ثبت‌شده.
	 */
	protected static function registered( $hook_name ) {
		global $wp_filter;
		$res = array( 'registered' => false, 'callback' => null, 'priority' => null );
		if ( isset( $wp_filter[ $hook_name ] ) && is_object( $wp_filter[ $hook_name ] ) ) {
			$cbs = isset( $wp_filter[ $hook_name ]->callbacks ) && is_array( $wp_filter[ $hook_name ]->callbacks ) ? $wp_filter[ $hook_name ]->callbacks : array();
			foreach ( $cbs as $priority => $list ) {
				foreach ( (array) $list as $entry ) {
					if ( isset( $entry['function'] ) ) {
						$f = $entry['function'];
						if ( is_array( $f ) ) {
							$class = is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0];
							$res = array( 'registered' => true, 'callback' => $class . '::' . (string) $f[1], 'priority' => (int) $priority );
							return $res;
						}
						$res = array( 'registered' => true, 'callback' => ( $f instanceof \Closure ) ? 'Closure' : (string) $f, 'priority' => (int) $priority );
						return $res;
					}
				}
			}
		}
		$res['registered'] = (bool) has_action( $hook_name );
		return $res;
	}

	/** bytes از رشته memory_limit (مقدار -1 = بی‌نهایت). */
	protected static function memory_limit_bytes() {
		$lim = ini_get( 'memory_limit' );
		if ( '-1' === $lim ) {
			return null;
		}
		$bytes = (int) $lim;
		$unit  = strtolower( (string) $lim );
		if ( false !== strpos( $unit, 'g' ) ) {
			$bytes *= 1024 * 1024 * 1024;
		} elseif ( false !== strpos( $unit, 'm' ) ) {
			$bytes *= 1024 * 1024;
		} elseif ( false !== strpos( $unit, 'k' ) ) {
			$bytes *= 1024;
		}
		return $bytes > 0 ? $bytes : null;
	}

	/* ============================ اجرای audit ============================ */

	public static function run() {
		$out = array();
		try { $out['snapshot'] = self::part1_snapshot(); } catch ( \Throwable $e ) { $out['snapshot'] = array( 'error' => $e->getMessage() ); }
		try { $out['db'] = self::part2_db(); } catch ( \Throwable $e ) { $out['db'] = array( 'error' => $e->getMessage() ); }
		try { $out['watcher_state'] = self::part3_watcher_state(); } catch ( \Throwable $e ) { $out['watcher_state'] = array( 'error' => $e->getMessage() ); }
		try { $out['ajax_registration'] = self::part5_ajax(); } catch ( \Throwable $e ) { $out['ajax_registration'] = array( 'error' => $e->getMessage() ); }
		try { $out['run_path'] = self::part7_run_path(); } catch ( \Throwable $e ) { $out['run_path'] = array( 'error' => $e->getMessage() ); }
		try { $out['simulation'] = self::part8_simulation(); } catch ( \Throwable $e ) { $out['simulation'] = array( 'error' => $e->getMessage() ); }
		try { $out['session_path'] = self::part9_session_path(); } catch ( \Throwable $e ) { $out['session_path'] = array( 'error' => $e->getMessage() ); }
		try { $out['session_sample'] = self::part10_sample( $out['simulation'] ); } catch ( \Throwable $e ) { $out['session_sample'] = array( 'error' => $e->getMessage() ); }
		try { $out['pipeline_sample'] = self::part11_pipeline_sample(); } catch ( \Throwable $e ) { $out['pipeline_sample'] = array( 'error' => $e->getMessage() ); }
		try { $out['worker'] = self::part12_worker(); } catch ( \Throwable $e ) { $out['worker'] = array( 'error' => $e->getMessage() ); }
		try { $out['fiber_memory'] = self::part13_fiber_memory(); } catch ( \Throwable $e ) { $out['fiber_memory'] = array( 'error' => $e->getMessage() ); }
		try { $out['telegram'] = self::part_telemgram(); } catch ( \Throwable $e ) { $out['telegram'] = array( 'error' => $e->getMessage() ); }
		try { $out['selection_window'] = self::part14_selection_window(); } catch ( \Throwable $e ) { $out['selection_window'] = array( 'error' => $e->getMessage() ); }

		$out['rows']    = self::rows( $out );
		$out['verdict'] = self::verdict( $out );
		$out['generated_at'] = current_time( 'mysql' );
		$out['version'] = defined( 'STI_VERSION' ) ? STI_VERSION : '?';
		return $out;
	}

	/* ==================== PART 1 — Runtime Snapshot ==================== */

	protected static function part1_snapshot() {
		$w = get_option( 'sti_gs_watcher_enabled' );
		return array(
			'watcher_enabled_option' => array( 'raw' => $w, 'bool' => (bool) $w ),
			'php_version'            => PHP_VERSION,
			'memory_limit'           => ini_get( 'memory_limit' ),
			'memory_usage_bytes'     => memory_get_usage(),
			'memory_peak_bytes'      => memory_get_peak_usage(),
			'wp_version'             => isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '?',
			'woocommerce'            => class_exists( 'WooCommerce' ),
			'classes'                => array(
				'STI_GS_Channel_Watcher' => class_exists( 'STI_GS_Channel_Watcher' ),
				'STI_GS_Session'         => class_exists( 'STI_GS_Session' ),
				'STI_GS_Test_Wizard'     => class_exists( 'STI_GS_Test_Wizard' ),
				'STI_GS_Auto_Worker'     => class_exists( 'STI_GS_Auto_Worker' ),
				'STI_GS_Line'            => class_exists( 'STI_GS_Line' ),
				'STI_GS_Publish_Queue'   => class_exists( 'STI_GS_Publish_Queue' ),
			),
		);
	}

	/* ==================== PART 2 — REAL DATABASE READ ==================== */

	protected static function part2_db() {
		global $wpdb;
		$p       = $wpdb->prefix;
		$items   = $p . 'sti_gs_profile_items';
		$by_stat = array();
		if ( self::table_exists( $items ) ) {
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$items} GROUP BY status" );
			foreach ( (array) $rows as $r ) {
				$by_stat[ (string) $r->status ] = (int) $r->c;
			}
		}
		$sessions_table = class_exists( 'STI_GS_DB' ) ? STI_GS_DB::pipeline_items_table() : ( $p . 'sti_gs_pipeline_items' );
		$sessions_total = self::count_table( $sessions_table );
		return array(
			'profiles_total'       => self::count_table( $p . 'sti_gs_profiles' ),
			'profile_items_total'  => self::count_table( $items ),
			'profile_items_by_status' => $by_stat,
			'messages_total'       => self::count_table( $p . 'sti_gs_messages' ),
			'sessions_total'       => $sessions_total,
			'pipeline_items_total' => $sessions_total,
			'session_events_total' => self::count_table( $p . 'sti_gs_session_events' ),
			'logs_total'           => self::count_table( $p . 'sti_logs' ),
			'pipeline_table_name'  => $sessions_table,
			'note'                 => 'در این schema، «Session» و «Pipeline Item» همان جدول (pipeline items) هستند — sessions_table() alias غیرفعال‌شده‌ی pipeline_items_table() است.',
		);
	}

	/* ==================== PART 3 — REAL WATCHER STATE ==================== */

	protected static function part3_watcher_state() {
		$cls    = class_exists( 'STI_GS_Channel_Watcher' );
		$opt_raw = get_option( 'sti_gs_watcher_enabled' );
		$opt_bool = (bool) $opt_raw;
		$is_enabled = $cls ? STI_GS_Channel_Watcher::is_enabled() : null;
		$stats = $cls ? STI_GS_Channel_Watcher::stats() : array();
		$stats_enabled = isset( $stats['enabled'] ) ? $stats['enabled'] : null;
		$inconsistent = false;
		if ( null !== $is_enabled && null !== $stats_enabled ) {
			$inconsistent = ( (bool) $is_enabled !== (bool) $stats_enabled ) || ( $opt_bool !== (bool) $is_enabled );
		}
		$res = array(
			'option_value'        => $opt_raw,
			'option_bool'         => $opt_bool,
			'is_enabled'          => $is_enabled,
			'stats_enabled'       => $stats_enabled,
			'state_inconsistency' => $inconsistent,
			'cron_next_tick_ts'   => wp_next_scheduled( 'sti_gs_channel_watcher' ),
			'last_run_ts'         => (int) get_option( 'sti_gs_watcher_last_run', 0 ),
			'gate_last_ts'        => (int) get_option( 'sti_gs_gate_watcher', 0 ),
			'safe_mode'           => ( function_exists( 'sti_v7_safe_mode' ) && sti_v7_safe_mode() ),
			'halted'              => ( class_exists( 'STI_GS_DB' ) && STI_GS_DB::is_halted() ),
			'line_state'          => class_exists( 'STI_GS_Line' ) ? STI_GS_Line::state() : null,
			'worker_enabled'      => (bool) get_option( 'sti_gs_worker_enabled' ),
			'stats'               => $stats,
		);
		if ( $cls ) {
			$res['interval_sec'] = STI_GS_Channel_Watcher::interval_seconds();
			$res['batch']        = STI_GS_Channel_Watcher::batch_size();
			$res['daily_cap']    = STI_GS_Channel_Watcher::daily_cap();
			$res['backlog']      = STI_GS_Channel_Watcher::backlog();
			$res['backlog_limit'] = STI_GS_Channel_Watcher::backlog_limit();
			$res['created_today'] = STI_GS_Channel_Watcher::created_today();
		}
		return $res;
	}

	/* ==================== PART 5 — AJAX REGISTRATION (runtime) ==================== */

	protected static function part5_ajax() {
		return array(
			'sti_gs_watcher_toggle' => array_merge( self::registered( 'wp_ajax_sti_gs_watcher_toggle' ), array( 'expected_callback' => 'STI_GS_Test_Wizard::ajax_watcher_toggle' ) ),
			'sti_gs_watcher_run'    => array_merge( self::registered( 'wp_ajax_sti_gs_watcher_run' ), array( 'expected_callback' => 'STI_GS_Test_Wizard::ajax_watcher_run' ) ),
		);
	}

	/* ==================== PART 7 — REAL RUN PATH TRACE ====================
	 * مسیر واقعی، از کد 10.12.2 trace شده (خط‌ها دقیق). مقادیر EXISTS/CONDITION
	 * در لحظهٔ اجرا از Runtime خوانده می‌شوند.
	 */

	protected static function part7_run_path() {
		$w  = 'includes/golden-scan/class-gs-channel-watcher.php';
		$tz = 'includes/golden-scan/class-gs-test-wizard.php';
		$ws = self::part3_watcher_state();
		return array(
			'cron_path' => array(
				array(
					'step'      => 'WP-Cron → hook «sti_gs_channel_watcher»',
					'file'      => 'wp-cron (core)',
					'line'      => '—',
					'exists'    => (bool) wp_next_scheduled( 'sti_gs_channel_watcher' ),
					'caller'    => 'add_action( HOOK, [ STI_GS_Channel_Watcher::tick ] ) @ ' . $w . ' L47 (داخل init L46)',
					'condition' => 'event scheduled = ' . ( wp_next_scheduled( 'sti_gs_channel_watcher' ) ? date( 'Y-m-d H:i:s', wp_next_scheduled( 'sti_gs_channel_watcher' ) ) : 'NOT SCHEDULED' ),
				),
				array(
					'step'      => 'STI_GS_Channel_Watcher::tick()',
					'file'      => $w,
					'line'      => 149,
					'exists'    => method_exists( 'STI_GS_Channel_Watcher', 'tick' ),
					'caller'    => 'hook above',
					'condition' => 'gates: is_enabled(L150)=' . var_export( $ws['option_bool'], true )
						. ' | Cron_Gate::pass watcher(L159, last_ts=' . $ws['gate_last_ts'] . ') | safe_mode=' . var_export( $ws['safe_mode'], true )
						. ' | halted=' . var_export( $ws['halted'], true )
						. ' | last_run_ts=' . $ws['last_run_ts'],
				),
				array(
					'step'      => 'STI_GS_Channel_Watcher::run()',
					'file'      => $w,
					'line'      => 179,
					'exists'    => method_exists( 'STI_GS_Channel_Watcher', 'run' ),
					'caller'    => 'tick() @ L171',
					'condition' => 'gates: backlog ' . $ws['backlog'] . ' >= limit ' . $ws['backlog_limit'] . ' (L189) | daily_cap left < 1 (L201, created_today=' . $ws['created_today'] . '/' . $ws['daily_cap'] . ') | room < 1 (L208, batch=' . $ws['batch'] . ')',
				),
				array( 'step' => 'scan_new_messages()', 'file' => $w, 'line' => 233, 'exists' => method_exists( 'STI_GS_Channel_Watcher', 'scan_new_messages' ), 'caller' => 'run() @ L215', 'condition' => 'فقط داخل run() — Telegram/Scanner' ),
				array( 'step' => 'refresh_profiles()', 'file' => $w, 'line' => 305, 'exists' => method_exists( 'STI_GS_Channel_Watcher', 'refresh_profiles' ), 'caller' => 'run() @ L218', 'condition' => 'فقط داخل run() — profile matching' ),
				array( 'step' => 'create_sessions( $room )', 'file' => $w, 'line' => 369, 'exists' => method_exists( 'STI_GS_Channel_Watcher', 'create_sessions' ), 'caller' => 'run() @ L221', 'condition' => 'selection L405-420 · loop L421 · create_from_profile_item L422' ),
			),
			'ui_path' => array(
				array(
					'step'      => 'AJAX «sti_gs_watcher_run» → STI_GS_Test_Wizard::ajax_watcher_run()',
					'file'      => $tz,
					'line'      => 86,
					'exists'    => method_exists( 'STI_GS_Test_Wizard', 'ajax_watcher_run' ),
					'caller'    => 'UI button #gs-wt-run (worker.php) → admin-ajax.php',
					'condition' => 'check_ajax(L87) → STI_GS_Channel_Watcher::run() مستقیم @ L91 — **از tick() و gateهای L150/L159/safe_mode/halt عبور نمی‌کند** (فقط gateهای داخل run: L189/L201/L208)',
				),
			),
			'note'    => 'run() مستقیماً tick() را صدا نمی‌زند — مسیر معکوس است: tick() در L171 run() را صدا می‌زند. دکمهٔ Run از طریق AJAX مستقیم به run() می‌رسد.',
		);
	}

	/* ==================== PART 8 — create_sessions READ-ONLY SIMULATION ====================
	 * دقیقاً همان query انتخاب (L405-420) + همان شرط‌های create_from_profile_item
	 * (3-way JOIN L25-36 + existing check L38-40) — بدون INSERT.
	 */

	protected static function part8_simulation() {
		global $wpdb;
		$items    = STI_GS_DB::profile_items_table();
		$profiles = STI_GS_DB::profiles_table();
		$messages = STI_GS_DB::messages_table();
		$table    = STI_GS_DB::pipeline_items_table();

		/* gateهای قبل از create_sessions — همان فرمول run() (L188-208)، فقط خواندنی: */
		$backlog       = STI_GS_Channel_Watcher::backlog();
		$backlog_limit = STI_GS_Channel_Watcher::backlog_limit();
		$cap           = STI_GS_Channel_Watcher::daily_cap();
		$created_today = STI_GS_Channel_Watcher::created_today();
		$batch         = STI_GS_Channel_Watcher::batch_size();
		$left          = $cap > 0 ? max( 0, $cap - $created_today ) : PHP_INT_MAX;
		$room          = min( $batch, $left, $backlog_limit - $backlog );
		$blocked_at    = null;
		if ( $backlog >= $backlog_limit ) { $blocked_at = 'BACKLOG_LIMIT (run L189)'; }
		elseif ( $left < 1 ) { $blocked_at = 'DAILY_CAP (run L201)'; }
		elseif ( $room < 1 ) { $blocked_at = 'CAPACITY_ZERO (run L208)'; }

		/* eligible بدون LIMIT + بدون دسته (دلیل‌بندی would_create=0): */
		$eligible = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items} pi INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = 'available' AND p.default_category_id IS NOT NULL AND p.default_category_id > 0"
		) );
		$no_category = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items} pi INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = 'available' AND ( p.default_category_id IS NULL OR p.default_category_id = 0 )"
		) );

		/* room مؤثر: اگر این دور gate-blocked است، شبیه‌سازی فرضی با room=1: */
		$room_eff = ( $room < 1 ) ? 1 : min( (int) $room, 200 );

		/* ۱) همان query انتخاب create_sessions (L405-420) — فقط SELECT: */
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT pi.id FROM {$items} pi
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = 'available'
			   AND p.default_category_id IS NOT NULL
			   AND p.default_category_id > 0
			 ORDER BY pi.id ASC
			 LIMIT %d",
			max( 1, (int) $room_eff )
		), ARRAY_A );
		$ids = array();
		foreach ( $rows as $r ) { $ids[] = (int) $r['id']; }

		$outcomes = array( 'NO_ITEM' => 0, 'EXISTING_SESSION' => 0, 'WOULD_CREATE' => 0 );
		$found    = array();
		$sessions_by_mp = array();
		if ( $ids ) {
			$place = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			/* ۲) همان 3-way JOINِ create_from_profile_item (L25-36) — فقط SELECT: */
			$rows3 = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT pi.id AS profile_item_id, pi.status AS item_status, pi.profile_id, m.id AS message_pk, m.channel_id, m.file_code, p.default_category_id
				 FROM {$items} pi
				 INNER JOIN {$messages} m ON m.id = pi.message_pk
				 INNER JOIN {$profiles} p ON p.id = pi.profile_id
				 WHERE pi.id IN ({$place})",
				$ids
			), ARRAY_A );
			foreach ( $rows3 as $r ) {
				$found[ (int) $r['profile_item_id'] ] = $r;
			}
			$mps = array();
			foreach ( $found as $r ) { $mps[] = (int) $r['message_pk']; }
			if ( $mps ) {
				$place2 = implode( ',', array_fill( 0, count( $mps ), '%d' ) );
				/* ۳) همان existing-check (get_by_message_pk, L38-40) — فقط SELECT: */
				$rowsS = (array) $wpdb->get_results( $wpdb->prepare(
					"SELECT id, message_pk, state, created_at FROM {$table} WHERE message_pk IN ({$place2})",
					$mps
				), ARRAY_A );
				foreach ( $rowsS as $r ) {
					$sessions_by_mp[ (int) $r['message_pk'] ] = $r;
				}
			}
		}
		$sample = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $found[ $id ] ) ) {
				/* 3-way JOIN ردیف ندارد = همان WP_Error('sti_gs_no_item') — ORPHAN message: */
				$outcomes['NO_ITEM']++;
				if ( count( $sample ) < 10 ) { $sample[] = array( 'profile_item_id' => $id, 'outcome' => 'NO_ITEM', 'code' => 'sti_gs_no_item', 'message_pk' => null, 'existing_session' => null ); }
				continue;
			}
			$r = $found[ $id ];
			if ( isset( $sessions_by_mp[ (int) $r['message_pk'] ] ) ) {
				/* existing session — در کد واقعی int برمی‌گرداند و به‌عنوان «created» شمارش می‌شود: */
				$outcomes['EXISTING_SESSION']++;
				if ( count( $sample ) < 10 ) {
					$s = $sessions_by_mp[ (int) $r['message_pk'] ];
					$sample[] = array(
						'profile_item_id' => $id, 'item_status' => $r['item_status'], 'profile_id' => (int) $r['profile_id'],
						'message_pk' => (int) $r['message_pk'], 'channel_id' => (int) $r['channel_id'],
						'default_category_id' => $r['default_category_id'] ? (int) $r['default_category_id'] : null,
						'outcome' => 'EXISTING_SESSION', 'code' => null,
						'existing_session' => array( 'id' => (int) $s['id'], 'state' => $s['state'], 'created_at' => $s['created_at'] ),
					);
				}
				continue;
			}
			$outcomes['WOULD_CREATE']++;
			if ( count( $sample ) < 10 ) {
				$sample[] = array(
					'profile_item_id' => $id, 'item_status' => $r['item_status'], 'profile_id' => (int) $r['profile_id'],
					'message_pk' => (int) $r['message_pk'], 'channel_id' => (int) $r['channel_id'],
					'default_category_id' => $r['default_category_id'] ? (int) $r['default_category_id'] : null,
					'outcome' => 'WOULD_CREATE', 'code' => null, 'existing_session' => null,
				);
			}
		}

		return array(
			'round_gates' => array(
				'backlog'           => $backlog,
				'backlog_limit'     => $backlog_limit,
				'daily_cap'         => $cap,
				'created_today'     => $created_today,
				'left'              => ( $cap > 0 ) ? $left : null,
				'batch'             => $batch,
				'room'              => $room,
				'room_effective'    => $room_eff,
				'blocked_at'        => $blocked_at,
			),
			'candidate_items'    => count( $ids ),
			'eligible_no_limit'  => $eligible,
			'no_category'        => $no_category,
			'outcomes'           => $outcomes,
			'would_create'       => $outcomes['WOULD_CREATE'],
			'sample'             => $sample,
			'rejection_map'      => array(
				'NO_ITEM'          => 'code واقعی: sti_gs_no_item — 3-way JOIN خالی (message/profile orphan) [create_from_profile_item L33-36]',
				'EXISTING_SESSION' => 'بدون code — existing session برگردانده می‌شود و در کد واقعی به‌عنوان created شمارش می‌شود [L38-40]',
				'WOULD_CREATE'     => '— (INSERT واقعی فقط در create_from_profile_item L42-74)',
				'NOT_ELIGIBLE'     => 'خارج از selection SQL: status!=available یا default_category_id NULL/0 [L412-418]',
				'BACKLOG_LIMIT'    => 'skip در run() L189 (قبل از create_sessions)',
				'DAILY_CAP'        => 'skip در run() L201 (قبل از create_sessions)',
				'CAPACITY_ZERO'    => 'skip در run() L208 (قبل از create_sessions)',
				'LOCKED'           => 'N/A — create_sessions هیچ شرط lock ندارد (lock در لایه Worker است)',
				'OTHER'            => 'code واقعی: sti_gs_session_insert_failed — فقط روی INSERT واقعی؛ در شبیه‌سازی read-only ارزیابی‌پذیر نیست',
			),
		);
	}

	/* ==================== PART 9 — create_from_profile_item PATH ==================== */

	protected static function part9_session_path() {
		$fn = method_exists( 'STI_GS_Session', 'create_from_profile_item' );
		return array(
			'function_exists'        => $fn,
			'function_location'      => 'includes/golden-scan/class-gs-session.php L22',
			'automatic_caller_exists' => true,
			'automatic_caller'       => 'STI_GS_Channel_Watcher::create_sessions()',
			'caller_file'            => 'includes/golden-scan/class-gs-channel-watcher.php',
			'caller_line'            => 422,
			'caller_chain'           => 'tick() L171 → run() L221 → create_sessions() L422',
			'manual_caller'          => 'AJAX دستی: class-gs-session-ajax.php L42 (دکمه‌های ساخت دستی Session)',
			'chain_break'            => ! $fn,
		);
	}

	/* ==================== PART 10 — REAL SESSION CHECK (sample ≤ 10) ==================== */

	protected static function part10_sample( $sim ) {
		if ( ! is_array( $sim ) || empty( $sim['sample'] ) ) {
			return array( 'rows' => array(), 'note' => 'no candidates' );
		}
		return array( 'rows' => array_slice( (array) $sim['sample'], 0, 10 ) );
	}

	/* ==================== PART 11 — REAL PIPELINE CHECK ==================== */

	protected static function part11_pipeline_sample() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();
		if ( ! self::table_exists( $table ) ) {
			return array( 'rows' => array(), 'table' => $table, 'note' => 'table missing' );
		}
		$cols = array( 'id', 'profile_item_id', 'message_pk', 'state', 'created_at' );
		foreach ( array( 'queue_status', 'scheduled_at', 'product_id', 'attempts', 'next_retry_at', 'locked_until' ) as $c ) {
			if ( STI_GS_DB::column_exists( $table, $c ) ) { $cols[] = $c; }
		}
		$rows = (array) $wpdb->get_results( 'SELECT ' . implode( ', ', $cols ) . " FROM {$table} ORDER BY id DESC LIMIT 10", ARRAY_A );
		$terminal_place = implode( ',', array_fill( 0, count( self::TERMINAL ), '%s' ) );
		$counts = array(
			'total'    => self::count_table( $table ),
			'terminal' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state IN ({$terminal_place})", self::TERMINAL ) ),
		);
		$has_product = in_array( 'product_id', $cols, true );
		$has_queue   = in_array( 'queue_status', $cols, true );
		$has_sched   = in_array( 'scheduled_at', $cols, true );
		$counts['with_product'] = $has_product ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state NOT IN ({$terminal_place}) AND product_id > 0", self::TERMINAL ) ) : null;
		$orphan_sql = "SELECT COUNT(*) FROM {$table} WHERE state NOT IN ({$terminal_place}) AND created_at < %s";
		$orphan_par = array_merge( self::TERMINAL, array( date( 'Y-m-d H:i:s', time() - 86400 ) ) );
		if ( $has_product ) { $orphan_sql .= ' AND (product_id IS NULL OR product_id = 0)'; }
		if ( $has_queue ) { $orphan_sql .= ' AND (queue_status IS NULL OR queue_status = \'\')'; }
		if ( $has_sched ) { $orphan_sql .= ' AND scheduled_at IS NULL'; }
		$counts['orphan_24h'] = (int) $wpdb->get_var( $wpdb->prepare( $orphan_sql, $orphan_par ) );
		$linked = array();
		foreach ( $rows as $r ) {
			if ( $has_product && (int) ( $r['product_id'] ?? 0 ) > 0 ) {
				$linked[] = 'product#' . (int) $r['product_id'];
			} elseif ( $has_queue && ! empty( $r['queue_status'] ) ) {
				$linked[] = 'queued(' . $r['queue_status'] . ')';
			} elseif ( $has_sched && ! empty( $r['scheduled_at'] ) ) {
				$linked[] = 'scheduled';
			} else {
				$linked[] = ( 'SCANNED' === ( $r['state'] ?? '' ) ) ? 'waiting-worker' : 'no-queue-link';
			}
		}
		return array( 'rows' => $rows, 'link' => $linked, 'counts' => $counts, 'table' => $table );
	}

	/* ==================== PART 12 — REAL WORKER READ-ONLY CHECK ==================== */

	protected static function part12_worker() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();
		$now   = current_time( 'mysql' );
		$terminal_place = implode( ',', array_fill( 0, count( self::TERMINAL ), '%s' ) );
		/* همان query pick() (class-gs-auto-worker.php L375-403) — فقط SELECT: */
		$eligible_rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, state, attempts FROM {$table}
			 WHERE state NOT IN ({$terminal_place})
			   AND ( locked_until IS NULL OR locked_until < %s )
			   AND ( next_retry_at IS NULL OR next_retry_at <= %s )
			 ORDER BY ( attempts >= %d ) ASC, priority DESC, id ASC
			 LIMIT 100",
			array_merge( self::TERMINAL, array( $now, $now, 5 ) )
		), ARRAY_A );
		$max_active = 1;
		if ( class_exists( 'STI_GS_Automation' ) && method_exists( 'STI_GS_Automation', 'get' ) ) {
			$max_active = (int) STI_GS_Automation::get( 'max_active_sessions' ) ?: 1;
		}
		$active = class_exists( 'STI_GS_Auto_Worker' ) ? STI_GS_Auto_Worker::active_sessions() : 0;
		$total  = self::count_table( $table );
		return array(
			'worker_enabled'     => (bool) get_option( 'sti_gs_worker_enabled' ),
			'line_state'         => class_exists( 'STI_GS_Line' ) ? STI_GS_Line::state() : null,
			'safe_mode'          => ( function_exists( 'sti_v7_safe_mode' ) && sti_v7_safe_mode() ),
			'halted'             => ( class_exists( 'STI_GS_DB' ) && STI_GS_DB::is_halted() ),
			'cron_next_tick_ts'  => wp_next_scheduled( 'sti_gs_auto_worker' ),
			'gate_last_ts'       => (int) get_option( 'sti_gs_gate_auto_worker', 0 ),
			'active_sessions'    => $active,
			'max_active'         => $max_active,
			'max_active_blocked' => ( $active >= $max_active ),
			'total'              => $total,
			'terminal'           => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state IN ({$terminal_place})", self::TERMINAL ) ),
			'locked'             => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state NOT IN ({$terminal_place}) AND locked_until IS NOT NULL AND locked_until > %s", array_merge( self::TERMINAL, array( $now ) ) ) ),
			'retry_wait'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state NOT IN ({$terminal_place}) AND (locked_until IS NULL OR locked_until < %s) AND next_retry_at IS NOT NULL AND next_retry_at > %s", array_merge( self::TERMINAL, array( $now, $now ) ) ) ),
			'eligible_for_worker' => count( $eligible_rows ),
			'already_processing'  => $active,
			'blocked_for_worker'  => max( 0, $total - (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state IN ({$terminal_place})", self::TERMINAL ) ) ) - count( $eligible_rows ),
			'eligible_sample'     => array_slice( $eligible_rows, 0, 10 ),
			'pick_condition'      => 'state NOT IN (terminal 6) AND (locked_until IS NULL OR < now) AND (next_retry_at IS NULL OR <= now) ORDER BY (attempts>=5) ASC, priority DESC, id ASC — class-gs-auto-worker.php L375-403',
			'tick_gates_lines'    => 'is_enabled L209 · line STOPPED/PAUSING L225/228 · Cron_Gate L267 · safe_mode L273 · halt L276 · active>=max L289 · pick L301',
		);
	}

	/* ==================== PART 13 — FIBER / MEMORY ==================== */

	protected static function part13_fiber_memory() {
		global $wpdb;
		$logs_table = $wpdb->prefix . 'sti_logs';
		$keywords   = array( 'Fiber', 'mmap', 'Cannot allocate memory', 'MadelineProto', 'channel already closed', 'endpoint does not exist' );
		$logs       = array();
		$total      = 0;
		if ( self::table_exists( $logs_table ) ) {
			$where  = array();
			$params = array();
			foreach ( $keywords as $k ) {
				$where[]  = 'message LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $k ) . '%';
			}
			$wsql = implode( ' OR ', $where );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE ({$wsql})", $params ) );
			$logs  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, level, message, created_at FROM {$logs_table} WHERE ({$wsql}) ORDER BY id DESC LIMIT 20", $params ), ARRAY_A );
		}
		$lim_bytes = self::memory_limit_bytes();
		$peak      = memory_get_peak_usage();
		return array(
			'memory_limit'         => ini_get( 'memory_limit' ),
			'memory_limit_bytes'   => $lim_bytes,
			'memory_usage_bytes'   => memory_get_usage(),
			'memory_peak_bytes'    => $peak,
			'peak_pct_of_limit'    => ( $lim_bytes ? round( $peak / $lim_bytes * 100, 1 ) : null ),
			'fiber_logs_recent'    => $logs,
			'fiber_logs_total'     => $total,
			'keywords'             => $keywords,
			'classification_note'  => 'طبق دستور: صرفِ وجود این خطاها علت Session Creation اعلام نمی‌شود؛ فقط اگر chain code (run_path) نشان دهد خطا قبل از create_sessions رخ داده، blocker محسوب می‌شود.',
		);
	}

	/* ==================== TELEGRAM row ==================== */

	protected static function part_telemgram() {
		global $wpdb;
		$ch_table = STI_GS_DB::channels_table();
		$runs     = STI_GS_DB::scan_runs_table();
		$last     = null;
		if ( self::table_exists( $runs ) && STI_GS_DB::column_exists( $runs, 'created_at' ) ) {
			$last = $wpdb->get_var( "SELECT MAX(created_at) FROM {$runs}" );
		}
		return array(
			'mtproto_configured'    => class_exists( 'STI_MTProto' ) ? STI_MTProto::is_configured() : null,
			'channels_total'        => self::count_table( $ch_table ),
			'channels_running_scan' => self::table_exists( $ch_table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ch_table} WHERE scan_status = 'RUNNING'" ) : null,
			'scan_daily_budget_left'=> ( class_exists( 'STI_GS_Scan_Run' ) && method_exists( 'STI_GS_Scan_Run', 'daily_budget_left' ) ) ? STI_GS_Scan_Run::daily_budget_left() : 'n/a',
			'last_scan_run'         => $last,
		);
	}

	/* ==================== 🔬 SELECTION WINDOW AUDIT (10.12.4) — read-only ====================
	 * Rیشه‌یابی NO_ITEM: ۵۰۰ کاندیدای اول (همان query انتخاب production، ORDER BY pi.id ASC)
	 * با خواندن مستقیم message_pk از profile_items (نه از JOIN) + 3-way JOIN + existing session
	 * + شمارش orphan در کل eligible (حکم A/B/C) + تطبیق با DB همین لحظه.
	 * فقط SELECT/SHOW/get_option — صفر نوشتن.
	 */

	protected static function part14_selection_window() {
		global $wpdb;
		$items    = STI_GS_DB::profile_items_table();
		$profiles = STI_GS_DB::profiles_table();
		$messages = STI_GS_DB::messages_table();
		$table    = STI_GS_DB::pipeline_items_table();
		$W_MAX    = 500;

		/* eligible کل (بدون LIMIT) + orphan کل (حکم A/B/C): */
		$eligible_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items} pi INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = 'available' AND p.default_category_id IS NOT NULL AND p.default_category_id > 0"
		) );
		$orphan_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items} pi
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 LEFT JOIN {$messages} m ON m.id = pi.message_pk
			 WHERE pi.status = 'available' AND p.default_category_id IS NOT NULL AND p.default_category_id > 0
			   AND m.id IS NULL"
		) );
		$valid_total = $eligible_total - $orphan_total;

		/* همان query انتخاب production (L404-414) — فقط LIMIT 500: */
		$cand = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT pi.id FROM {$items} pi
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = 'available'
			   AND p.default_category_id IS NOT NULL
			   AND p.default_category_id > 0
			 ORDER BY pi.id ASC
			 LIMIT %d",
			$W_MAX
		), ARRAY_A );
		$ids = array();
		foreach ( $cand as $c ) { $ids[] = (int) $c['id']; }
		$n   = count( $ids );
		if ( ! $n ) {
			return array( 'eligible_total' => $eligible_total, 'orphan_total' => $orphan_total, 'valid_total' => $valid_total, 'scanned_candidates' => 0, 'note' => 'no candidates in window', 'audit_text' => 'SELECTION AUDIT\neligible_total = ' . $eligible_total . "\ncandidate_window = 0" );
		}
		$place = implode( ',', array_fill( 0, $n, '%d' ) );

		/* ۱) خواندن مستقیم ردیف‌های profile_items — اثبات مقدار واقعی message_pk: */
		$pi_rows  = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT pi.id, pi.profile_id, pi.message_pk, pi.status, pi.matched_keyword, pi.created_at FROM {$items} WHERE pi.id IN ({$place})",
			$ids
		), ARRAY_A );
		$pi_by_id = array();
		foreach ( $pi_rows as $r ) { $pi_by_id[ (int) $r['id'] ] = $r; }

		/* ۲) همان 3-way JOINِ create_from_profile_item (L25-36): */
		$rows3 = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT pi.id AS pid, pi.profile_id, m.id AS message_pk, m.channel_id AS msg_channel_id, m.file_code, p.id AS profile_id2, p.default_category_id, p.channel_id AS profile_channel_id
			 FROM {$items} pi
			 INNER JOIN {$messages} m ON m.id = pi.message_pk
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.id IN ({$place})",
			$ids
		), ARRAY_A );
		$found = array();
		foreach ( $rows3 as $r ) { $found[ (int) $r['pid'] ] = $r; }

		/* ۳) existing session برای message_pks پیداشده: */
		$mps        = array();
		foreach ( $found as $r ) { $mps[] = (int) $r['message_pk']; }
		$sess_by_mp = array();
		if ( $mps ) {
			$p2    = implode( ',', array_fill( 0, count( $mps ), '%d' ) );
			$ss    = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, message_pk, state, created_at FROM {$table} WHERE message_pk IN ({$p2})",
				$mps
			), ARRAY_A );
			foreach ( $ss as $r ) { $sess_by_mp[ (int) $r['message_pk'] ] = $r; }
		}

		/* ۴) برای orphanها: آیا message_pk>0 هنوز زنده است؟ (تفکیک NO_PK از DANGLING): */
		$orphan_pks    = array();
		$orphan_prof   = array();
		foreach ( $ids as $id ) {
			if ( isset( $found[ $id ] ) ) { continue; }
			$pr = isset( $pi_by_id[ $id ] ) ? $pi_by_id[ $id ] : null;
			if ( $pr && (int) $pr['message_pk'] > 0 ) { $orphan_pks[] = (int) $pr['message_pk']; }
			if ( $pr ) { $orphan_prof[] = (int) $pr['profile_id']; }
		}
		$alive_msgs = array();
		if ( $orphan_pks ) {
			$p3 = implode( ',', array_fill( 0, count( $orphan_pks ), '%d' ) );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$messages} WHERE id IN ({$p3})", $orphan_pks ) ) as $mid ) { $alive_msgs[ (int) $mid ] = true; }
		}
		$alive_profs = array();
		if ( $orphan_prof ) {
			$p4 = implode( ',', array_fill( 0, count( $orphan_prof ), '%d' ) );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$profiles} WHERE id IN ({$p4})", $orphan_prof ) ) as $pid ) { $alive_profs[ (int) $pid ] = true; }
		}

		/* ۵) طبقه‌بندی هر کاندید + پنجره‌های 20/100/500 + نمونه‌ها: */
		$counts    = array( 'NO_ITEM' => 0, 'VALID' => 0, 'EXISTING_SESSION' => 0 );
		$win       = array( 20 => array( 'NO_ITEM' => 0, 'VALID' => 0, 'EXISTING_SESSION' => 0 ), 100 => array( 'NO_ITEM' => 0, 'VALID' => 0, 'EXISTING_SESSION' => 0 ), 500 => array( 'NO_ITEM' => 0, 'VALID' => 0, 'EXISTING_SESSION' => 0 ) );
		$otypes    = array( 'NO_PK(message_pk=0)' => 0, 'DANGLING_PK(message row deleted)' => 0, 'PROFILE_MISSING' => 0, 'OTHER' => 0 );
		$first_valid = null;
		$first_valid_rank = null;
		$first_orphan = null;
		$sample_rows = array();
		$rank = 0;
		foreach ( $ids as $id ) {
			$rank++;
			$pr = isset( $pi_by_id[ $id ] ) ? $pi_by_id[ $id ] : null;
			if ( isset( $found[ $id ] ) ) {
				$r  = $found[ $id ];
				$cl = isset( $sess_by_mp[ (int) $r['message_pk'] ] ) ? 'EXISTING_SESSION' : 'VALID';
				if ( $cl === 'VALID' && null === $first_valid ) {
					$first_valid      = array(
						'profile_item_id'     => $id,
						'profile_id'          => (int) $r['profile_id'],
						'message_pk'          => (int) $r['message_pk'],
						'message_exists'      => true,
						'message_channel_id'  => (int) $r['msg_channel_id'],
						'profile_exists'      => true,
						'profile_channel_id'  => $r['profile_channel_id'] !== null ? (int) $r['profile_channel_id'] : null,
						'status'              => $pr ? $pr['status'] : '?',
						'default_category_id' => $r['default_category_id'] !== null ? (int) $r['default_category_id'] : null,
						'existing_session'    => null,
						'rank'                => $rank,
					);
					$first_valid_rank = $rank;
				}
			} else {
				$cl = 'NO_ITEM';
				if ( ! $pr || (int) $pr['message_pk'] === 0 ) {
					$otypes['NO_PK(message_pk=0)']++;
					$otype = 'NO_PK';
				} elseif ( isset( $alive_msgs[ (int) $pr['message_pk'] ] ) ) {
					/* پیام هست ولی JOIN شکست → profile (که در selection JOIN شده بود) حذف شده؟ — غیرممکن؛ ثبت OTHER: */
					$otypes['OTHER']++;
					$otype = 'OTHER';
				} elseif ( ! isset( $alive_profs[ (int) $pr['profile_id'] ] ) ) {
					$otypes['PROFILE_MISSING']++;
					$otype = 'PROFILE_MISSING';
				} else {
					$otypes['DANGLING_PK(message row deleted)']++;
					$otype = 'DANGLING_PK';
				}
				if ( null === $first_orphan ) {
					$first_orphan = array(
						'profile_item_id'     => $id,
						'profile_id'          => $pr ? (int) $pr['profile_id'] : null,
						'message_pk'          => $pr ? $pr['message_pk'] : null,
						'message_exists'      => $pr && (int) $pr['message_pk'] > 0 ? ( isset( $alive_msgs[ (int) $pr['message_pk'] ] ) ? true : false ) : false,
						'profile_exists'      => $pr ? ( isset( $alive_profs[ (int) $pr['profile_id'] ] ) ? true : false ) : false,
						'status'              => $pr ? $pr['status'] : '?',
						'matched_keyword'     => $pr ? $pr['matched_keyword'] : null,
						'created_at'          => $pr ? $pr['created_at'] : null,
						'classification'      => $otype,
						'rank'                => $rank,
					);
				}
			}
			$counts[ $cl ]++;
			foreach ( $win as $w => $v ) {
				if ( $rank <= $w ) { $win[ $w ][ $cl ]++; }
			}
			if ( $rank <= 10 ) {
				$sample_rows[] = array(
					'id'           => $id,
					'profile_id'   => $pr ? (int) $pr['profile_id'] : null,
					'message_pk'   => $pr ? $pr['message_pk'] : null,
					'status'       => $pr ? $pr['status'] : '?',
					'class'        => $cl,
					'rank'         => $rank,
				);
			}
		}

		/* ۶) تطبیق با DB همین لحظه (بعد از Run واقعی): */
		$max_sess_id        = $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
		$max_sess_created   = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table}" );
		$sess_count         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$avail_range        = $wpdb->get_row( $wpdb->prepare(
			"SELECT MIN(pi.id) AS min_id, MAX(pi.id) AS max_id, COUNT(*) AS c FROM {$items} pi
			 INNER JOIN {$profiles} p ON p.id = pi.profile_id
			 WHERE pi.status = 'available' AND p.default_category_id IS NOT NULL AND p.default_category_id > 0"
		), ARRAY_A );
		$reconcile = array(
			'last_run_ts'          => (int) get_option( 'sti_gs_watcher_last_run', 0 ),
			'watcher_stats'        => get_option( 'sti_gs_watcher_stats', array() ),
			'sessions_count'       => $sess_count,
			'max_session_id'       => $max_sess_id === null ? null : (int) $max_sess_id,
			'max_session_created'  => $max_sess_created,
			'available_range'      => $avail_range ? array( 'min_id' => (int) $avail_range['min_id'], 'max_id' => (int) $avail_range['max_id'], 'count' => (int) $avail_range['c'] ) : null,
			'created_0_consistent' => ( (int) $max_sess_created ? false : null ),
		);

		/* ۷) حکم A/B/C + متن SELECTION AUDIT: */
		$break = '';
		$ev    = array();
		if ( $eligible_total === 0 ) {
			$break = 'SELECTION EMPTY — eligible=0';
		} elseif ( $orphan_total >= $eligible_total ) {
			$break = 'B — PROFILE_ITEM / MESSAGE DATA INTEGRITY';
			$ev[]  = "همه‌ی {$eligible_total} مورد eligible، orphan هستند (orphan_total=" . $orphan_total . ') — دادهٔ source خراب است، نه ترتیب Selection.';
		} elseif ( $orphan_total > 0 && $valid_total > 0 && ( null === $first_valid_rank || $first_valid_rank > 20 ) ) {
			$break = 'A — SELECTION ORDER / ORPHAN STARVATION';
			$ev[]  = "orphanها قدیمی‌ترین ردیف‌ها (کمترین id) هستند و Selection با ORDER BY pi.id ASC (L401) همیشه همان ۲۰ اول را می‌گیرد.";
			$ev[]  = 'first_valid_candidate rank=' . ( $first_valid_rank === null ? '>500 (درون پنجره یافت نشد)' : $first_valid_rank ) . ' — یعنی window 20 هرگز به آیتم سالم نمی‌رسد.';
			$ev[]  = 'orphan_total=' . $orphan_total . ' / eligible_total=' . $eligible_total . ' / valid_total=' . $valid_total;
			$ev[]  = 'بعد از NO_ITEM، status ردیف تغییر نمی‌کند (فلش available→queued فقط بعد از INSERT موفق — session.php L71) و هیچ skip/offset/marking وجود ندارد (loop L421-433 فقط لاگ می‌زند).';
		} elseif ( $orphan_total === 0 ) {
			$break = 'NO ORPHAN STARVATION — window سالم است (بررسی مسیر create)';
		} else {
			$break = 'MIXED — orphanها داخل window 20 نیستند (بررسی دقیق‌تر sample_rows)';
		}
		$audit_text = "SELECTION AUDIT\n"
			. 'eligible_total = ' . $eligible_total . "\n"
			. 'candidate_window = ' . $n . ' (ORDER BY pi.id ASC — همان query production, L404-414)' . "\n"
			. 'NO_ITEM = ' . $win[20]['NO_ITEM'] . ' (first 20) / ' . $win[100]['NO_ITEM'] . ' (first 100) / ' . $win[500]['NO_ITEM'] . ' (first ' . min( 500, $n ) . ')' . "\n"
			. 'VALID = ' . $win[20]['VALID'] . ' / ' . $win[100]['VALID'] . ' / ' . $win[500]['VALID'] . "\n"
			. 'EXISTING = ' . $win[20]['EXISTING_SESSION'] . ' / ' . $win[100]['EXISTING_SESSION'] . ' / ' . $win[500]['EXISTING_SESSION'] . "\n\n"
			. 'orphan_total_in_eligible = ' . $orphan_total . ' / valid_total = ' . $valid_total . "\n"
			. 'orphan_types(window) = ' . wp_json_encode( $otypes ) . "\n"
			. 'first_valid_candidate = ' . ( $first_valid ? ( 'id=' . $first_valid['profile_item_id'] . ' (message_pk=' . $first_valid['message_pk'] . ', profile=' . $first_valid['profile_id'] . ', rank=' . $first_valid['rank'] . ')' ) : 'NONE in first ' . min( 500, $n ) ) . "\n\n"
			. "FIRST HARD BREAK:\n" . $break . "\n\nEvidence:\n" . ( $ev ? implode( "\n", array_map( 'strval', $ev ) ) : 'see window counts above' ) . "\n\n"
			. "File:\nincludes/golden-scan/class-gs-channel-watcher.php\nFunction:\nSTI_GS_Channel_Watcher::create_sessions\nLine:\n401 (ORDER BY pi.id ASC) · 404-414 (selection WHERE/LIMIT) · 421-433 (loop — reject = log only)\n"
			. "join: includes/golden-scan/class-gs-session.php L25-36 (3-way INNER JOIN) · status flip L71 (queued only after INSERT ok)";

		return array(
			'eligible_total'     => $eligible_total,
			'orphan_total'       => $orphan_total,
			'valid_total'        => $valid_total,
			'scanned_candidates' => $n,
			'windows'            => $win,
			'counts'             => $counts,
			'orphan_types'       => $otypes,
			'first_valid'        => $first_valid,
			'first_orphan'       => $first_orphan,
			'sample_rows'        => $sample_rows,
			'reconcile'          => $reconcile,
			'verdict_break'      => $break,
			'audit_text'         => $audit_text,
		);
	}

	/* ==================== FINAL TABLE ==================== */

	protected static function rows( $out ) {
		global $wpdb;
		$rows = array();
		$db   = isset( $out['db'] ) ? $out['db'] : array();
		$ws   = isset( $out['watcher_state'] ) ? $out['watcher_state'] : array();
		$sim  = isset( $out['simulation'] ) ? $out['simulation'] : array();
		$ax   = isset( $out['ajax_registration'] ) ? $out['ajax_registration'] : array();
		$sp   = isset( $out['session_path'] ) ? $out['session_path'] : array();
		$wk   = isset( $out['worker'] ) ? $out['worker'] : array();
		$tg   = isset( $out['telegram'] ) ? $out['telegram'] : array();
		$fm   = isset( $out['fiber_memory'] ) ? $out['fiber_memory'] : array();
		$pp   = isset( $out['pipeline_sample'] ) ? $out['pipeline_sample'] : array();

		/* 1 — Profile */
		$p_total = isset( $db['profiles_total'] ) ? (int) $db['profiles_total'] : -1;
		$ready_p = 0;
		$p_table = $wpdb->prefix . 'sti_gs_profiles';
		if ( self::table_exists( $p_table ) ) {
			$ready_p = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p_table} WHERE default_category_id IS NOT NULL AND default_category_id > 0" );
		}
		$rows[] = array( 'name' => 'Profile', 'letter' => 'A', 'result' => ( $p_total > 0 && $ready_p > 0 ) ? 'PASS' : 'FAIL', 'evidence' => "profiles_total={$p_total}, with_default_category={$ready_p}" );

		/* 2 — Profile Item */
		$pi_total = isset( $db['profile_items_total'] ) ? (int) $db['profile_items_total'] : -1;
		$avail    = isset( $db['profile_items_by_status']['available'] ) ? (int) $db['profile_items_by_status']['available'] : 0;
		$rows[] = array( 'name' => 'Profile Item', 'letter' => 'B', 'result' => ( $pi_total > 0 && $avail > 0 ) ? 'PASS' : 'FAIL', 'evidence' => 'total=' . $pi_total . ', available=' . $avail . ', by_status=' . wp_json_encode( isset( $db['profile_items_by_status'] ) ? $db['profile_items_by_status'] : array() ) );

		/* 3 — Message */
		$msg = isset( $db['messages_total'] ) ? (int) $db['messages_total'] : -1;
		$rows[] = array( 'name' => 'Message', 'letter' => 'C1', 'result' => ( $msg > 0 ) ? 'PASS' : 'FAIL', 'evidence' => 'messages_total=' . $msg );

		/* 4 — Selection */
		$elig  = isset( $sim['eligible_no_limit'] ) ? (int) $sim['eligible_no_limit'] : -1;
		$ncat  = isset( $sim['no_category'] ) ? (int) $sim['no_category'] : -1;
		$rows[] = array( 'name' => 'Selection', 'letter' => 'C', 'result' => ( $elig > 0 ) ? 'PASS' : 'FAIL', 'evidence' => "eligible(available+category)={$elig}, no_category_excluded={$ncat}" );

		/* 5 — Watcher State */
		$w_enabled = isset( $ws['option_bool'] ) ? (bool) $ws['option_bool'] : false;
		$w_cron    = isset( $ws['cron_next_tick_ts'] ) ? $ws['cron_next_tick_ts'] : null;
		$w_pass    = $w_enabled && ( empty( $ws['state_inconsistency'] ) ) && (bool) $w_cron;
		$w_ev      = 'option=' . var_export( isset( $ws['option_value'] ) ? $ws['option_value'] : null, true )
			. ', is_enabled=' . var_export( isset( $ws['is_enabled'] ) ? $ws['is_enabled'] : null, true )
			. ', stats.enabled=' . var_export( isset( $ws['stats_enabled'] ) ? $ws['stats_enabled'] : null, true )
			. ( ! empty( $ws['state_inconsistency'] ) ? ' → STATE INCONSISTENCY' : '' )
			. ', cron_next=' . ( $w_cron ? date( 'Y-m-d H:i:s', (int) $w_cron ) : 'NOT SCHEDULED' )
			. ', last_run=' . ( ! empty( $ws['last_run_ts'] ) ? date( 'Y-m-d H:i:s', (int) $ws['last_run_ts'] ) : 'never' )
			. ', line_state=' . ( isset( $ws['line_state'] ) ? $ws['line_state'] : '?' )
			. ', safe_mode=' . var_export( isset( $ws['safe_mode'] ) ? $ws['safe_mode'] : null, true )
			. ', halted=' . var_export( isset( $ws['halted'] ) ? $ws['halted'] : null, true );
		$rows[] = array( 'name' => 'Watcher State', 'letter' => 'D', 'result' => $w_pass ? 'PASS' : 'FAIL', 'evidence' => $w_ev );

		/* 6 — Watcher UI (JS — بعداً در client پر می‌شود) */
		$rows[] = array( 'name' => 'Watcher UI', 'letter' => 'D2', 'result' => 'JS', 'evidence' => 'پر می‌شود توسط DOM check صفحه (DOMContentLoaded)' );

		/* 7 — AJAX Registration */
		$ax_pass = false;
		$ax_ev   = '';
		foreach ( array( 'sti_gs_watcher_toggle', 'sti_gs_watcher_run' ) as $action ) {
			$a = isset( $ax[ $action ] ) ? $ax[ $action ] : array();
			$ok = ! empty( $a['registered'] ) && isset( $a['callback'] ) && $a['callback'] === $a['expected_callback'];
			$ax_pass = $ax_pass && $ok;
			$ax_ev .= $action . ': ' . ( ! empty( $a['registered'] ) ? 'YES → ' . $a['callback'] : 'NO' ) . ( $ok ? ' ✓' : ' ✗' ) . '  ';
		}
		$rows[] = array( 'name' => 'AJAX Registration', 'letter' => 'D3', 'result' => $ax_pass ? 'PASS' : 'FAIL', 'evidence' => trim( $ax_ev ) );

		/* 8 — create_sessions path */
		$cs_pass = method_exists( 'STI_GS_Channel_Watcher', 'tick' )
			&& method_exists( 'STI_GS_Channel_Watcher', 'run' )
			&& method_exists( 'STI_GS_Channel_Watcher', 'create_sessions' )
			&& method_exists( 'STI_GS_Session', 'create_from_profile_item' );
		$rows[] = array(
			'name'     => 'create_sessions path',
			'letter'   => 'E0',
			'result'   => $cs_pass ? 'PASS' : 'FAIL',
			'evidence' => 'tick() L149 [gates L150/L159/safe/halt] → run() L179 [gates L189/L201/L208] → create_sessions() L369 [selection L405-420, caller→create_from_profile_item L422] · UI path: ajax_watcher_run L86 → run() مستقیم L91 (بای‌پس از gateهای tick)',
		);

		/* 9 — Would Create (value row) */
		$would = isset( $sim['would_create'] ) ? (int) $sim['would_create'] : -1;
		$oc    = isset( $sim['outcomes'] ) ? $sim['outcomes'] : array();
		$gate  = isset( $sim['round_gates']['blocked_at'] ) ? $sim['round_gates']['blocked_at'] : null;
		$rows[] = array(
			'name'     => 'Would Create',
			'letter'   => '—',
			'result'   => (string) $would,
			'evidence' => 'candidates=' . ( isset( $sim['candidate_items'] ) ? $sim['candidate_items'] : '?' ) . ', NO_ITEM=' . ( $oc['NO_ITEM'] ?? 0 ) . ', EXISTING_SESSION=' . ( $oc['EXISTING_SESSION'] ?? 0 ) . ', WOULD_CREATE=' . $would . ( $gate ? ' · THIS ROUND BLOCKED AT: ' . $gate : '' ),
		);

		/* 10 — Session */
		$s_total = isset( $db['sessions_total'] ) ? (int) $db['sessions_total'] : -1;
		$no_item = (int) ( $oc['NO_ITEM'] ?? 0 );
		$s_pass  = ( $s_total >= 0 ) && ( $no_item === 0 );
		$rows[] = array( 'name' => 'Session', 'letter' => 'E', 'result' => $s_pass ? 'PASS' : 'FAIL', 'evidence' => 'sessions_total=' . $s_total . ', orphan_candidates(NO_ITEM)=' . $no_item . ', would_create=' . $would );

		/* 11 — Pipeline */
		$pc = isset( $pp['counts'] ) ? $pp['counts'] : array();
		$p_orphan = isset( $pc['orphan_24h'] ) ? (int) $pc['orphan_24h'] : null;
		$rows[] = array(
			'name'     => 'Pipeline',
			'letter'   => 'F',
			'result'   => ( null === $p_orphan ? 'FAIL' : ( $p_orphan > 0 ? 'FAIL' : 'PASS' ) ),
			'evidence' => 'sessions_total=' . ( $pc['total'] ?? '?' ) . ', terminal=' . ( $pc['terminal'] ?? '?' ) . ', with_product=' . ( $pc['with_product'] ?? '?' ) . ', orphan_24h_no_queue=' . ( $pc['orphan_24h'] ?? '?' ),
		);

		/* 12 — Worker pickup */
		$w_elig  = isset( $wk['eligible_for_worker'] ) ? (int) $wk['eligible_for_worker'] : -1;
		$w_on    = isset( $wk['worker_enabled'] ) ? (bool) $wk['worker_enabled'] : false;
		$w_line  = isset( $wk['line_state'] ) ? $wk['line_state'] : null;
		$w_pass  = ( $w_elig > 0 ) && $w_on && ( 'STOPPED' !== $w_line ) && empty( $wk['safe_mode'] ) && empty( $wk['halted'] ) && empty( $wk['max_active_blocked'] );
		$rows[] = array(
			'name'     => 'Worker pickup',
			'letter'   => 'F2',
			'result'   => $w_pass ? 'PASS' : 'FAIL',
			'evidence' => 'eligible=' . $w_elig . ', locked=' . ( $wk['locked'] ?? '?' ) . ', retry_wait=' . ( $wk['retry_wait'] ?? '?' ) . ', terminal=' . ( $wk['terminal'] ?? '?' ) . ', active=' . ( $wk['active_sessions'] ?? '?' ) . '/' . ( $wk['max_active'] ?? '?' ) . ', worker_enabled=' . var_export( $w_on, true ) . ', line=' . $w_line . ', cron_next=' . ( ! empty( $wk['cron_next_tick_ts'] ) ? date( 'Y-m-d H:i:s', (int) $wk['cron_next_tick_ts'] ) : 'NOT SCHEDULED' ),
		);

		/* 13 — Telegram */
		$tg_conf = isset( $tg['mtproto_configured'] ) ? $tg['mtproto_configured'] : null;
		$rows[] = array(
			'name'     => 'Telegram',
			'letter'   => 'G',
			'result'   => ( true === $tg_conf ) ? 'PASS' : 'FAIL',
			'evidence' => 'mtproto_configured=' . var_export( $tg_conf, true ) . ', channels=' . ( $tg['channels_total'] ?? '?' ) . ', running_scan=' . ( $tg['channels_running_scan'] ?? '?' ) . ', budget_left=' . var_export( $tg['scan_daily_budget_left'] ?? null, true ) . ', last_scan=' . ( ! empty( $tg['last_scan_run'] ) ? $tg['last_scan_run'] : 'never' ),
		);

		/* 14 — Fiber / Memory */
		$fm_pct  = isset( $fm['peak_pct_of_limit'] ) ? $fm['peak_pct_of_limit'] : null;
		$fm_pass = ( null === $fm_pct ) || ( $fm_pct < 90 );
		$rows[] = array(
			'name'     => 'Fiber / mmap',
			'letter'   => 'H',
			'result'   => $fm_pass ? 'PASS' : 'FAIL',
			'evidence' => 'memory_limit=' . ( $fm['memory_limit'] ?? '?' ) . ', peak=' . ( $fm['memory_peak_bytes'] ?? 0 ) . ' bytes' . ( null !== $fm_pct ? ' (' . $fm_pct . '%)' : '' ) . ', recent_fiber_logs=' . ( $fm['fiber_logs_total'] ?? 0 ) . ' — logs evidence-only (classification rule: see fiber_memory.classification_note)',
		);

		return $rows;
	}

	/* ==================== VERDICT ==================== */

	protected static function verdict( $out ) {
		$sim     = isset( $out['simulation'] ) ? $out['simulation'] : array();
		$ws      = isset( $out['watcher_state'] ) ? $out['watcher_state'] : array();
		$would   = isset( $sim['would_create'] ) ? (int) $sim['would_create'] : -1;
		$enabled = isset( $ws['option_bool'] ) ? (bool) $ws['option_bool'] : false;

		$headline = '';
		$detail   = '';
		if ( $would > 0 && ! $enabled ) {
			$headline = 'BLOCKED — WATCHER DISABLED';
			$detail   = 'would_create=' . $would . ' ولی get_option(sti_gs_watcher_enabled)=' . var_export( isset( $ws['option_value'] ) ? $ws['option_value'] : null, true ) . ' → tick() در L150 برمی‌گردد.';
		} elseif ( $would > 0 && $enabled ) {
			$last_session = null;
			$table_ok     = false;
			try {
				$table = STI_GS_DB::pipeline_items_table();
				if ( self::table_exists( $table ) ) {
					$table_ok = true;
					$last_session = $GLOBALS['wpdb']->get_var( "SELECT MAX(created_at) FROM {$table}" );
				}
			} catch ( \Throwable $e ) { $last_session = null; }
			$lr = isset( $ws['last_run_ts'] ) ? (int) $ws['last_run_ts'] : 0;
			if ( empty( $ws['cron_next_tick_ts'] ) ) {
				$headline = 'AUTOMATION EXECUTION FAILURE';
				$detail   = 'watcher روشن و would_create=' . $would . '، ولی event کران «sti_gs_channel_watcher» زمان‌بندی نشده (wp_next_scheduled=false) — tick() هرگز اجرا نمی‌شود.';
			} elseif ( $table_ok && $last_session && $lr && strtotime( $last_session ) >= $lr ) {
				$headline = 'OK — Session in the last run';
				$detail   = 'last_run=' . date( 'Y-m-d H:i:s', $lr ) . ', last_session_created=' . $last_session . ' — زنجیره در آخرین دور Session ساخته است.';
			} else {
				$headline = 'AUTOMATION EXECUTION FAILURE';
				$detail   = 'watcher روشن و would_create=' . $would . '، ولی هیچ Session جدیدی بعد از last_run=(' . ( $lr ? date( 'Y-m-d H:i:s', $lr ) : 'never' ) . ') ساخته نشده. gateهای فعلی: ' . wp_json_encode( isset( $sim['round_gates'] ) ? $sim['round_gates'] : array() ) . ' · line_state=' . ( isset( $ws['line_state'] ) ? $ws['line_state'] : '?' );
			}
		} else {
			$headline = 'REJECTION PATH — would_create=0';
			$oc  = isset( $sim['outcomes'] ) ? $sim['outcomes'] : array();
			$detail = 'eligible=' . ( isset( $sim['eligible_no_limit'] ) ? $sim['eligible_no_limit'] : '?' )
				. ', no_category=' . ( isset( $sim['no_category'] ) ? $sim['no_category'] : '?' )
				. ', candidates=' . ( isset( $sim['candidate_items'] ) ? $sim['candidate_items'] : '?' )
				. ', NO_ITEM=' . ( $oc['NO_ITEM'] ?? 0 )
				. ', EXISTING_SESSION=' . ( $oc['EXISTING_SESSION'] ?? 0 )
				. ' — اگر eligible=0: هیچ profile item «available + با دسته» وجود ندارد (ریشه در مرحله‌ی Profile/Selection است، نه Session Creation).' . ( isset( $sim['round_gates']['blocked_at'] ) && $sim['round_gates']['blocked_at'] ? ' · این دور در هر حال: ' . $sim['round_gates']['blocked_at'] : '' );
		}

		$first = null;
		foreach ( (array) ( isset( $out['rows'] ) ? $out['rows'] : array() ) as $r ) {
			if ( 'FAIL' === $r['result'] ) { $first = $r; break; }
		}

		return array(
			'headline'            => $headline,
			'headline_detail'     => $detail,
			'first_hard_break'    => $first ? ( $first['letter'] . ' — ' . $first['name'] ) : null,
			'first_hard_break_ev' => $first ? $first['evidence'] : null,
			'note'                => 'ردیف Watcher UI (JS) بعداً در client پر می‌شود؛ اگر آن FAIL باشد و بقیه PASS باشند، FIRST HARD BREAK در UI به‌روزرسانی می‌شود.',
		);
	}
}
