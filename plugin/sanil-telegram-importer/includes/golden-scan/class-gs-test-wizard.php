<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Test Wizard.
 *
 * همان شش فازی که تا امروز باید دستی با SQL و Console اجرا می‌شد، اینجا
 * پشت پنج دکمه قرار گرفته‌اند. هیچ مرحله‌ای نیازمند phpMyAdmin، Console،
 * ویرایش wp-config یا SSH نیست.
 *
 * هر مرحله وضعیت خودش را ذخیره می‌کند تا گزارش نهایی قابل ساخت باشد.
 */
class STI_GS_Test_Wizard {

	const STATE_OPTION = 'sti_gs_wizard_state';

	const STEPS = array(
		'scan100'     => 'اسکن محدود ۱۰۰ پیام',
		'scan500'     => 'اسکن محدود ۵۰۰ پیام',
		'repeat'      => 'تکرار همان Run',
		'correlation' => 'اجرای Correlation',
		'product'     => 'بررسی ساخت محصول',
	);

	public function __construct() {
		add_action( 'wp_ajax_sti_gs_wizard_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_sti_gs_flag_toggle', array( $this, 'ajax_flag_toggle' ) );
		add_action( 'wp_ajax_sti_gs_watcher_toggle', array( $this, 'ajax_watcher_toggle' ) );
		add_action( 'wp_ajax_sti_gs_watcher_run', array( $this, 'ajax_watcher_run' ) );
		add_action( 'wp_ajax_sti_gs_watchdog_run', array( $this, 'ajax_watchdog_run' ) );
		add_action( 'wp_ajax_sti_gs_revive_dead', array( $this, 'ajax_revive_dead' ) );
		add_action( 'wp_ajax_sti_gs_wizard_state', array( $this, 'ajax_state' ) );
		add_action( 'wp_ajax_sti_gs_wizard_reset', array( $this, 'ajax_reset' ) );
		add_action( 'wp_ajax_sti_gs_wizard_close_run', array( $this, 'ajax_close_run' ) );
		add_action( 'wp_ajax_sti_gs_system_check', array( $this, 'ajax_system_check' ) );
		add_action( 'wp_ajax_sti_gs_worker_toggle', array( $this, 'ajax_worker_toggle' ) );
		add_action( 'wp_ajax_sti_gs_worker_stats', array( $this, 'ajax_worker_stats' ) );
		add_action( 'wp_ajax_sti_gs_worker_run_now', array( $this, 'ajax_worker_run_now' ) );
		add_action( 'wp_ajax_sti_gs_worker_reset', array( $this, 'ajax_worker_reset' ) );
		add_action( 'wp_ajax_sti_gs_worker_chain_mode', array( $this, 'ajax_worker_chain_mode' ) );
		/* ۱۰.۱۰ — خط تولید */
		add_action( 'wp_ajax_sti_gs_review_fix', array( $this, 'ajax_review_fix' ) );
		add_action( 'wp_ajax_sti_gs_automation_save', array( $this, 'ajax_automation_save' ) );
		add_action( 'wp_ajax_sti_gs_pipeline_start', array( $this, 'ajax_pipeline_start' ) );
		add_action( 'wp_ajax_sti_gs_start_diagnostic', array( $this, 'ajax_start_diagnostic' ) );
		add_action( 'wp_ajax_sti_gs_publish_queue_create', array( $this, 'ajax_publish_queue_create' ) );
		add_action( 'wp_ajax_sti_gs_publish_queue_save_selection', array( $this, 'ajax_publish_queue_save_selection' ) );
		/* ۱۰.۱۱ — Start/Stop + مانیتور زنده + Review زنده */
		add_action( 'wp_ajax_sti_gs_line_start', array( $this, 'ajax_line_start' ) );
		add_action( 'wp_ajax_sti_gs_line_stop', array( $this, 'ajax_line_stop' ) );
		add_action( 'wp_ajax_sti_gs_pipeline_poll', array( $this, 'ajax_pipeline_poll' ) );
		add_action( 'wp_ajax_sti_gs_review_poll', array( $this, 'ajax_review_poll' ) );
		add_action( 'wp_ajax_sti_gs_queue_run_now', array( $this, 'ajax_queue_run_now' ) );
		add_action( 'wp_ajax_sti_gs_queue_toggle', array( $this, 'ajax_queue_toggle' ) );
		add_action( 'wp_ajax_sti_gs_queue_interval', array( $this, 'ajax_queue_interval' ) );
		add_action( 'wp_ajax_sti_gs_queue_publish_now', array( $this, 'ajax_queue_publish_now' ) );
		add_action( 'wp_ajax_sti_gs_rebuild_preview', array( $this, 'ajax_rebuild_preview' ) );
		add_action( 'wp_ajax_sti_gs_rebuild_apply', array( $this, 'ajax_rebuild_apply' ) );
		add_action( 'wp_ajax_sti_gs_insight_batch', array( $this, 'ajax_insight_batch' ) );
	}

	protected function check_ajax() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	/* ============================ Auto Worker ============================ */

	/* ================= زیرساخت خودترمیمی (بدون منطق Chain) ================= */

	/* ===================== Channel Watcher ===================== */

	public function ajax_watcher_toggle() {
		$this->check_ajax();
		if ( ! class_exists( 'STI_GS_Channel_Watcher' ) ) {
			wp_send_json_error( array( 'message' => 'ماژول Watcher بارگذاری نشده.' ) );
		}
		STI_GS_Channel_Watcher::set_enabled( ! empty( $_POST['enabled'] ) );
		wp_send_json_success( STI_GS_Channel_Watcher::stats() );
	}

	/** اجرای فوری یک چرخه — بدون انتظار کران. */
	public function ajax_watcher_run() {
		$this->check_ajax();
		if ( ! class_exists( 'STI_GS_Channel_Watcher' ) ) {
			wp_send_json_error( array( 'message' => 'ماژول Watcher بارگذاری نشده.' ) );
		}
		$report = STI_GS_Channel_Watcher::run();
		wp_send_json_success( array_merge( STI_GS_Channel_Watcher::stats(), array(
			'message' => '' !== $report['skipped']
				? $report['skipped']
				: sprintf(
					'%d کانال اسکن شد، %d پروفایل تازه شد، %d Session ساخته شد.',
					$report['scanned'], $report['profiles'], $report['created']
				),
		) ) );
	}

	public function ajax_flag_toggle() {
		$this->check_ajax();
		$key = sanitize_key( $_POST['flag'] ?? '' );
		if ( ! class_exists( 'STI_GS_Flags' ) || ! STI_GS_Flags::set( $key, ! empty( $_POST['enabled'] ) ) ) {
			wp_send_json_error( array( 'message' => 'کلید نامعتبر است.' ) );
		}
		wp_send_json_success( STI_GS_Flags::all() );
	}

	public function ajax_watchdog_run() {
		$this->check_ajax();
		if ( ! class_exists( 'STI_GS_Recovery' ) ) {
			wp_send_json_error( array( 'message' => 'ماژول بازیابی بارگذاری نشده.' ) );
		}
		/* ۱۰.۹.۳ — اجرای دستی هرگز نباید توسط دروازه‌ی کران مسدود شود. */
		STI_GS_Recovery::tick( true );
		wp_send_json_success( STI_GS_Recovery::stats() );
	}

	public function ajax_revive_dead() {
		$this->check_ajax();
		$n = STI_GS_Recovery::revive_all();
		wp_send_json_success( array_merge( STI_GS_Recovery::stats(),
			array( 'message' => $n . ' مورد از صف مرده برگردانده شد.' ) ) );
	}

	public function ajax_worker_toggle() {
		$this->check_ajax();
		$on = ! empty( $_POST['enabled'] );
		STI_GS_Auto_Worker::set_enabled( $on );
		STI_Logger::info( 'گلدن اسکن: Auto Worker ' . ( $on ? 'روشن' : 'خاموش' ) . ' شد.' );
		wp_send_json_success( STI_GS_Auto_Worker::stats() );
	}

	public function ajax_worker_stats() {
		$this->check_ajax();
		wp_send_json_success( STI_GS_Auto_Worker::stats() );
	}

	/** تغییر حالت معماری زنجیره (legacy | auto | chain) — Feature Flag ۱۰.۸. */
	public function ajax_worker_chain_mode() {
		$this->check_ajax();
		$mode = sanitize_key( wp_unslash( (string) ( $_POST['mode'] ?? '' ) ) );
		if ( ! class_exists( 'STI_GS_Chain_Engine' ) || ! STI_GS_Chain_Engine::set_mode( $mode ) ) {
			wp_send_json_error( array( 'message' => 'حالت نامعتبر است (legacy | auto | chain).' ) );
		}
		STI_Logger::info( 'گلدن اسکن: حالت معماری زنجیره به «' . STI_GS_Chain_Engine::mode() . '» تغییر کرد.' );
		wp_send_json_success( STI_GS_Auto_Worker::stats() );
	}

	/** اجرای فوری یک تیک، بدون انتظار کران — برای دیدن نتیجه همان لحظه. */
	public function ajax_worker_run_now() {
		$this->check_ajax();
		if ( ! STI_GS_Auto_Worker::is_enabled() ) {
			wp_send_json_error( array( 'message' => 'اول Worker را روشن کنید.' ) );
		}
		delete_option( STI_GS_Auto_Worker::STATS_KEY . '_last' ); // فاصله را دور بزن
		STI_GS_Auto_Worker::tick();
		wp_send_json_success( STI_GS_Auto_Worker::stats() );
	}

	/**
	 * اجرای دستی صف گلدن اسکن.
	 *
	 * دکمه‌ی «اجرای یک نوبت اکنون» در صفحه‌ی «صف انتشار» فقط صف قدیمی
	 * (STI_Scheduler) را اجرا می‌کند و محصولات گلدن اسکن را نمی‌بیند. تا
	 * امروز راهی برای اجرای دستی این صف وجود نداشت و فقط باید منتظر کران
	 * می‌ماندیم.
	 */
	public function ajax_queue_run_now() {
		$this->check_ajax();
		if ( ! class_exists( 'STI_GS_Publish_Queue' ) ) {
			wp_send_json_error( array( 'message' => 'صف در دسترس نیست.' ) );
		}
		if ( ! STI_GS_Publish_Queue::is_running() ) {
			wp_send_json_error( array( 'message' => 'اول صف را از صفحه‌ی «صف انتشار» روشن کنید.' ) );
		}
		wp_send_json_success( STI_GS_Publish_Queue::run_now() );
	}

	public function ajax_queue_toggle() {
		$this->check_ajax();
		STI_GS_Publish_Queue::set_running( ! empty( $_POST['enabled'] ) );
		STI_Logger::info( 'گلدن اسکن صف: ' . ( STI_GS_Publish_Queue::is_running() ? 'روشن' : 'خاموش' ) . ' شد.' );
		wp_send_json_success( STI_GS_Publish_Queue::stats() );
	}

	public function ajax_queue_interval() {
		$this->check_ajax();
		$min = (int) ( $_POST['minutes'] ?? 0 );
		if ( $min < 1 ) {
			wp_send_json_error( array( 'message' => 'فاصله باید حداقل ۱ دقیقه باشد.' ) );
		}
		STI_GS_Publish_Queue::set_interval_minutes( $min );
		wp_send_json_success( STI_GS_Publish_Queue::stats() );
	}

	/** انتشار فوری چند مورد، بدون انتظار زمان‌بندی. */
	public function ajax_queue_publish_now() {
		$this->check_ajax();
		$n = max( 1, min( 50, (int) ( $_POST['count'] ?? 1 ) ) );

		if ( ! STI_GS_Publish_Queue::is_running() ) {
			wp_send_json_error( array(
				'message' => 'صف خاموش است. اول «▶ شروع صف» را بزنید.',
			) );
		}

		// publish_next_now خودش true/false برمی‌گرداند؛ دیگر لازم نیست از
		// روی آمار حدس بزنیم که چیزی منتشر شد یا نه.
		$done = 0;
		for ( $i = 0; $i < $n; $i++ ) {
			if ( ! STI_GS_Publish_Queue::publish_next_now() ) {
				break;
			}
			$done++;
		}

		$stats = STI_GS_Publish_Queue::stats();

		wp_send_json_success( array_merge( $stats, array(
			'message' => $done > 0
				? sprintf( '%d محصول منتشر شد. باقی‌مانده در صف: %d', $done, (int) $stats['queued'] )
				: 'چیزی برای انتشار پیدا نشد — صف خالی است یا همه قفل‌اند.',
		) ) );
	}

	/** Sessionهایی که محصول دارند — پایه‌ی بازسازی. */
	/**
	 * نسخه‌ی منطق بازسازی. با هر تغییر در عنوان‌سازی بالا می‌رود تا
	 * محصولات قبلی دوباره واجد بازسازی شوند.
	 */
	const REBUILD_VERSION = 2;

	/**
	 * Sessionهایی که هنوز با منطق فعلی بازسازی نشده‌اند.
	 *
	 * نسخه‌ی قبلی همیشه `ORDER BY id DESC LIMIT N` می‌گرفت، پس هر بار
	 * **همان محصولات قبلی** — که از قبل درست شده بودند — دوباره بالا
	 * می‌آمدند و هیچ‌وقت به بقیه نمی‌رسید.
	 *
	 * حالا هر محصول بعد از بازسازی علامت می‌خورد و از فهرست خارج می‌شود.
	 */
	protected function rebuildable( $limit = 50 ) {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id, p.product_id
			 FROM {$table} p
			 LEFT JOIN {$wpdb->postmeta} m
			        ON m.post_id = p.product_id
			       AND m.meta_key = '_gs_rebuild_version'
			 WHERE p.product_id IS NOT NULL AND p.product_id > 0
			   AND ( m.meta_value IS NULL OR CAST( m.meta_value AS UNSIGNED ) < %d )
			 ORDER BY p.id ASC
			 LIMIT %d",
			self::REBUILD_VERSION,
			max( 1, (int) $limit )
		), ARRAY_A );

		return $rows;
	}

	/** چند محصول هنوز بازسازی نشده‌اند. */
	protected function rebuild_pending_count() {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$table} p
			 LEFT JOIN {$wpdb->postmeta} m
			        ON m.post_id = p.product_id AND m.meta_key = '_gs_rebuild_version'
			 WHERE p.product_id IS NOT NULL AND p.product_id > 0
			   AND ( m.meta_value IS NULL OR CAST( m.meta_value AS UNSIGNED ) < %d )",
			self::REBUILD_VERSION
		) );
	}

	/**
	 * پیش‌نمایش بازسازی — بدون تغییر هیچ‌چیز.
	 *
	 * ۲۲ محصول را نباید کورکورانه تغییر داد. اول نشان می‌دهیم عنوان از چه
	 * به چه تبدیل می‌شود، بعد اپراتور تصمیم می‌گیرد.
	 */
	public function ajax_rebuild_preview() {
		$this->check_ajax();
		$limit = max( 1, min( 50, (int) ( $_POST['count'] ?? 10 ) ) );

		$rows = array();
		foreach ( $this->rebuildable( $limit ) as $r ) {
			$pid     = (int) $r['product_id'];
			$p = STI_GS_Product_Builder::preview( (int) $r['id'] );

			$rows[] = array(
				'session_id' => (int) $r['id'],
				'product_id' => $pid,
				'before'     => get_the_title( $pid ),
				'after'      => $p['title'],
				'category'   => $p['category'],
				'price'      => $p['price'],
				'status'     => get_post_status( $pid ),
			);
		}

		wp_send_json_success( array(
			'rows'      => $rows,
			'remaining' => $this->rebuild_pending_count(),
		) );
	}

	public function ajax_rebuild_apply() {
		$this->check_ajax();
		$limit = max( 1, min( 50, (int) ( $_POST['count'] ?? 10 ) ) );
		$opts  = array(
			'description' => ! empty( $_POST['description'] ),
			'price'       => ! empty( $_POST['price'] ),
		);

		$done = 0;
		$fail = 0;
		foreach ( $this->rebuildable( $limit ) as $r ) {
			$res = STI_GS_Product_Builder::rebuild( (int) $r['id'], $opts );
			if ( is_wp_error( $res ) ) {
				$fail++;
				continue;
			}

			// علامت‌گذاری تا دفعه‌ی بعد دوباره انتخاب نشود.
			update_post_meta( (int) $r['product_id'], '_gs_rebuild_version', self::REBUILD_VERSION );
			$done++;
		}

		$remaining = $this->rebuild_pending_count();

		STI_Logger::info( sprintf(
			'گلدن اسکن: %d محصول بازسازی شد، %d ناموفق، %d باقی‌مانده.', $done, $fail, $remaining
		) );

		wp_send_json_success( array(
			'remaining' => $remaining,
			'message'   => sprintf(
				'%d محصول بازسازی شد%s. باقی‌مانده: %d',
				$done, $fail ? "، {$fail} ناموفق" : '', $remaining
			),
		) );
	}

	/** یک دسته از تحلیل شناخت کانال. */
	public function ajax_insight_batch() {
		$this->check_ajax();
		if ( ! class_exists( 'STI_GS_Channel_Insight' ) ) {
			wp_send_json_error( array( 'message' => 'ماژول شناخت کانال بارگذاری نشده.' ) );
		}
		wp_send_json_success( STI_GS_Channel_Insight::run_batch(
			(int) ( $_POST['channel_id'] ?? 0 ),
			(int) ( $_POST['after_id'] ?? 0 )
		) );
	}

	public function ajax_worker_reset() {
		$this->check_ajax();
		$n = STI_GS_Auto_Worker::reset_stuck();
		wp_send_json_success( array_merge(
			STI_GS_Auto_Worker::stats(),
			array( 'message' => $n . ' Session برای تلاش دوباره آزاد شد.' )
		) );
	}

	/* =============================== State =============================== */

	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * کانال قفل‌شده‌ی تست.
	 *
	 * بدون این، هر مرحله روی هر کانالی که همان لحظه در فهرست انتخاب بود
	 * اجرا می‌شد — و چون فهرست همیشه روی اولین کانال برمی‌گشت، مرحله ۱ روی
	 * یک کانال و مرحله ۳ و ۴ روی کانال دیگری اجرا می‌شدند. نتیجه‌اش
	 * خطاهای گیج‌کننده‌ی «یک Run باز وجود دارد» بود.
	 *
	 * حالا اولین مرحله کانال را قفل می‌کند و تا «شروع دوباره» همان می‌ماند.
	 */
	public static function locked_channel() {
		$state = self::state();
		return (int) ( $state['_channel_id'] ?? 0 );
	}

	protected static function lock_channel( $channel_id ) {
		$state = self::state();
		if ( empty( $state['_channel_id'] ) ) {
			$state['_channel_id'] = (int) $channel_id;
			update_option( self::STATE_OPTION, $state, false );
		}
		return (int) $state['_channel_id'];
	}

	protected static function save_step( $step, $status, $message, $data = array() ) {
		$state = self::state();
		$state[ $step ] = array(
			'status'  => $status, // pass | fail | running
			'message' => $message,
			'data'    => $data,
			'at'      => current_time( 'mysql' ),
		);
		update_option( self::STATE_OPTION, $state, false );
		return $state[ $step ];
	}

	/* =============================== AJAX =============================== */

	public function ajax_state() {
		$this->check_ajax();
		wp_send_json_success( array( 'state' => self::state(), 'report' => self::report() ) );
	}

	public function ajax_reset() {
		$this->check_ajax();
		delete_option( self::STATE_OPTION );
		wp_send_json_success( array( 'state' => array() ) );
	}

	public function ajax_system_check() {
		$this->check_ajax();
		$checks = STI_GS_System_Check::run();
		wp_send_json_success( array(
			'checks'  => $checks,
			'summary' => STI_GS_System_Check::summarize( $checks ),
		) );
	}

	public function ajax_step() {
		$this->check_ajax();

		$step       = sanitize_key( $_POST['step'] ?? '' );
		$channel_id = (int) ( $_POST['channel_id'] ?? 0 );

		if ( ! isset( self::STEPS[ $step ] ) ) {
			wp_send_json_error( array( 'message' => 'مرحله نامعتبر است.' ) );
		}
		if ( ! $channel_id ) {
			wp_send_json_error( array( 'message' => 'اول یک کانال انتخاب کنید.' ) );
		}

		$locked = self::lock_channel( $channel_id );
		if ( $locked !== $channel_id ) {
			$ch = STI_GS_Channel::get( $locked );
			wp_send_json_error( array( 'message' => sprintf(
				'این تست روی کانال «%s» شروع شده و تا پایان روی همان ادامه پیدا می‌کند. برای تست کانال دیگر، اول «شروع دوباره» را بزنید.',
				$ch ? ( $ch['title'] ?: $ch['identifier'] ) : '#' . $locked
			) ) );
		}

		try {
			switch ( $step ) {
				case 'scan100':
					$result = $this->start_limited( $channel_id, 100 );
					break;
				case 'scan500':
					$result = $this->start_limited( $channel_id, 500 );
					break;
				case 'repeat':
					$result = $this->repeat_last( $channel_id );
					break;
				case 'correlation':
					$result = $this->run_correlation( $channel_id );
					break;
				default:
					$result = $this->check_product( $channel_id );
			}
		} catch ( \Throwable $e ) {
			$result = self::save_step( $step, 'fail', 'خطای غیرمنتظره: ' . $e->getMessage() );
			STI_Logger::error( 'Wizard/' . $step . ': ' . $e->getMessage() );
		}

		wp_send_json_success( array( 'step' => $step, 'result' => $result, 'report' => self::report() ) );
	}

	/* =============================== مراحل =============================== */

	/**
	 * راه‌انداختن worker اسکن.
	 *
	 * نسخه‌ی قبلی do_action() را مستقیم داخل همان درخواست AJAX صدا می‌زد.
	 * دو مشکل داشت: اسکن می‌توانست از مهلت درخواست عبور کند و وسط کار بمیرد،
	 * و در آن صورت هیچ‌چیز در صف کران نمی‌ماند تا ادامه بدهد — یعنی Run
	 * برای همیشه روی RUNNING گیر می‌کرد و کاربر فقط «در حال اجرا» می‌دید.
	 *
	 * حالا رویداد زمان‌بندی می‌شود و کران بی‌درنگ بیدار می‌شود؛ خودِ worker
	 * زنجیره‌ی بعدی را ادامه می‌دهد.
	 */
	protected static function kick_worker( $channel_id ) {
		$args = array( (int) $channel_id );
		if ( ! wp_next_scheduled( 'sti_gs_scan_worker', $args ) ) {
			wp_schedule_single_event( time() - 1, 'sti_gs_scan_worker', $args );
		}
		spawn_cron();
	}

	/**
	 * پیش‌بررسی: چیزهایی که بدون آن‌ها اسکن قطعاً شکست می‌خورد.
	 * بهتر است همین‌جا با پیام روشن رد شود تا اینکه Run بسازد و بعد بمیرد.
	 */
	protected static function preflight() {
		if ( ! class_exists( 'STI_MTProto' ) || ! STI_MTProto::is_configured() ) {
			return 'تنظیمات MTProto کامل نیست. «تنظیمات تلگرام» را کامل کنید.';
		}
		if ( method_exists( 'STI_MTProto', 'auth_state' ) ) {
			$state = (string) STI_MTProto::instance()->auth_state();
			if ( 'logged_in' !== $state ) {
				return 'اکانت تلگرام وارد نشده (وضعیت: ' . $state . '). بدون ورود، اسکن اجرا نمی‌شود.';
			}
		}
		if ( function_exists( 'sti_v7_safe_mode' ) && sti_v7_safe_mode() ) {
			return 'افزونه در حالت ایمن است و بخشی از ماژول‌ها بارگذاری نشده‌اند. اول از صفحه‌ی افزونه حالت ایمن را خاموش کنید.';
		}
		return '';
	}

	protected function start_limited( $channel_id, $limit ) {
		$step = 100 === $limit ? 'scan100' : 'scan500';

		$blocker = self::preflight();
		if ( '' !== $blocker ) {
			return self::save_step( $step, 'fail', $blocker );
		}

		$open = STI_GS_Scan_Run::current_for_channel( $channel_id );
		if ( $open ) {
			return self::save_step( $step, 'fail', self::describe_open_run( $open ) );
		}

		$run_id = STI_GS_Scan_Run::start( $channel_id, STI_GS_Scan_Run::MODE_LIMITED, array(
			'limit_count' => $limit,
		) );
		if ( is_wp_error( $run_id ) ) {
			return self::save_step( $step, 'fail', $run_id->get_error_message() );
		}

		// «N پیام آخر» یعنی از جدیدترین پیام شروع کن.
		STI_GS_Channel::update( $channel_id, array(
			'last_scanned_message_id' => 0,
			'scan_status'             => STI_GS_Channel::STATUS_RUNNING,
			'last_error'              => '',
		) );

		self::kick_worker( $channel_id );

		return self::save_step( $step, 'running',
			sprintf( 'اسکن %d پیامی شروع شد. تا رسیدن به «کامل شد» صبر کنید.', $limit ),
			array( 'run_id' => (int) $run_id, 'channel_id' => $channel_id, 'limit' => $limit )
		);
	}

	protected function repeat_last( $channel_id ) {
		$open = STI_GS_Scan_Run::current_for_channel( $channel_id );
		if ( $open ) {
			return self::save_step( 'repeat', 'fail', self::describe_open_run( $open ) );
		}

		$runs = STI_GS_Scan_Run::list_for_channel( $channel_id, 1 );
		if ( empty( $runs ) ) {
			return self::save_step( 'repeat', 'fail', 'هنوز هیچ Run ای روی این کانال اجرا نشده.' );
		}
		$source = $runs[0];

		$before = self::inventory_snapshot( $channel_id );

		$new_id = STI_GS_Scan_Run::repeat( (int) $source['id'] );
		if ( is_wp_error( $new_id ) ) {
			return self::save_step( 'repeat', 'fail', $new_id->get_error_message() );
		}

		STI_GS_Channel::update( $channel_id, array(
			'last_scanned_message_id' => STI_GS_Scan_Run::anchor( $new_id ),
			'scan_status'             => STI_GS_Channel::STATUS_RUNNING,
			'last_error'              => '',
		) );

		self::kick_worker( $channel_id );

		return self::save_step( 'repeat', 'running',
			'تکرار شروع شد. پس از پایان، دوباره روی همین دکمه بزنید تا نتیجه سنجیده شود.',
			array( 'run_id' => (int) $new_id, 'source_run_id' => (int) $source['id'], 'before' => $before )
		);
	}

	protected function run_correlation( $channel_id ) {
		$runs = STI_GS_Scan_Run::list_for_channel( $channel_id, 1 );
		if ( empty( $runs ) ) {
			return self::save_step( 'correlation', 'fail', 'هنوز هیچ Run ای اجرا نشده.' );
		}
		$run_id = (int) $runs[0]['id'];

		$stats = STI_GS_Correlation::run_for_scan_run( $run_id );
		$breakdown = self::correlation_breakdown( $run_id );

		// شرط شکست: هیچ کلیدی ساخته نشده باشد، یا کد جعلی کوتاه دیده شود.
		if ( (int) $stats['keyed'] < 1 ) {
			return self::save_step( 'correlation', 'fail',
				sprintf( 'از %d پیام، هیچ کلیدی ساخته نشد.', (int) $stats['scanned'] ),
				array( 'stats' => $stats, 'breakdown' => $breakdown ) );
		}

		if ( $breakdown['suspicious_codes'] > 0 ) {
			return self::save_step( 'correlation', 'fail',
				sprintf( '%d کلید با کد کمتر از ۵ رقم ساخته شده — یعنی استخراج کد اشتباه است.',
					$breakdown['suspicious_codes'] ),
				array( 'stats' => $stats, 'breakdown' => $breakdown ) );
		}

		return self::save_step( 'correlation', 'pass',
			sprintf( 'از %d پیام، %d کلید ساخته شد (%d بدون کلید).',
				(int) $stats['scanned'], (int) $stats['keyed'], (int) $stats['unresolved'] ),
			array( 'stats' => $stats, 'breakdown' => $breakdown ) );
	}

	protected function check_product( $channel_id ) {
		global $wpdb;
		$table = STI_GS_DB::pipeline_items_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, state, product_id, matched_inbox_id, match_score, match_breakdown
			 FROM {$table} WHERE channel_id = %d ORDER BY id DESC LIMIT 10",
			$channel_id
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return self::save_step( 'product', 'fail',
				'هیچ Pipeline Item ای روی این کانال ساخته نشده. اول از تب Session ها یک مورد را Prepare کنید.' );
		}

		$built = 0;
		$orphan = 0;   // محصول ساخته شده ولی به فایلی گره نخورده
		$out_of_range = 0;
		$samples = array();

		foreach ( $rows as $row ) {
			$breakdown = json_decode( (string) $row['match_breakdown'], true );
			$confidence = is_array( $breakdown ) && isset( $breakdown['confidence'] )
				? (int) $breakdown['confidence'] : null;

			if ( (int) $row['product_id'] > 0 ) {
				$built++;
				if ( ! (int) $row['matched_inbox_id'] ) {
					$orphan++;
				}
			}
			if ( null !== $confidence && ( $confidence < 0 || $confidence > 100 ) ) {
				$out_of_range++;
			}

			$samples[] = array(
				'id'          => (int) $row['id'],
				'state'       => $row['state'],
				'product_id'  => (int) $row['product_id'],
				'inbox_id'    => (int) $row['matched_inbox_id'],
				'confidence'  => $confidence,
				'tier'        => is_array( $breakdown ) ? ( $breakdown['tier'] ?? '' ) : '',
				'method'      => is_array( $breakdown ) ? ( $breakdown['matched_method'] ?? '' ) : '',
			);
		}

		$data = compact( 'built', 'orphan', 'out_of_range', 'samples' );

		if ( $orphan > 0 ) {
			return self::save_step( 'product', 'fail',
				sprintf( '%d محصول ساخته شده ولی به هیچ فایلی گره نخورده (matched_inbox_id خالی) — یعنی Matching کار نکرده.', $orphan ),
				$data );
		}
		if ( $out_of_range > 0 ) {
			return self::save_step( 'product', 'fail',
				sprintf( '%d مورد confidence خارج از بازه ۰..۱۰۰ دارد.', $out_of_range ), $data );
		}
		if ( $built < 1 ) {
			return self::save_step( 'product', 'fail',
				'هنوز هیچ محصولی ساخته نشده. مسیر Prepare را تا انتها اجرا کنید.', $data );
		}

		return self::save_step( 'product', 'pass',
			sprintf( '%d محصول ساخته شده و همه به فایل مشخص گره خورده‌اند.', $built ), $data );
	}

	protected static function describe_open_run( $run ) {
		return sprintf(
			'Run #%d (%s، %s) روی این کانال باز است — %d از %d پیام پردازش شده. با دکمه‌ی «بستن Run باز» آن را ببندید.',
			(int) $run['id'], $run['scan_mode'], $run['status'],
			(int) $run['processed_messages'], (int) $run['limit_count']
		);
	}

	/** بستن دستی یک Run باز که گیر کرده. */
	public function ajax_close_run() {
		$this->check_ajax();
		$channel_id = (int) ( $_POST['channel_id'] ?? 0 );
		$open = STI_GS_Scan_Run::current_for_channel( $channel_id );
		if ( ! $open ) {
			wp_send_json_success( array( 'message' => 'هیچ Run بازی روی این کانال نیست.' ) );
		}
		STI_GS_Scan_Run::finish( (int) $open['id'], STI_GS_Scan_Run::STATUS_CANCELLED, 'بسته شدن دستی از Test Wizard' );
		STI_GS_Channel::update( $channel_id, array( 'scan_status' => STI_GS_Channel::STATUS_DONE ) );
		wp_clear_scheduled_hook( 'sti_gs_scan_worker', array( $channel_id ) );
		wp_send_json_success( array( 'message' => sprintf( 'Run #%d بسته شد.', (int) $open['id'] ) ) );
	}

	/* ============================ سنجش نتیجه ============================ */

	/** پس از پایان اسکن، وضعیت واقعی Run و کانال سنجیده می‌شود. */
	public static function evaluate_scan( $step ) {
		$state = self::state();
		if ( empty( $state[ $step ]['data']['run_id'] ) ) {
			return null;
		}

		$run_id = (int) $state[ $step ]['data']['run_id'];
		$run    = STI_GS_Scan_Run::get( $run_id );
		if ( ! $run ) {
			return self::save_step( $step, 'fail', 'Run پیدا نشد.' );
		}

		if ( STI_GS_Scan_Run::STATUS_RUNNING === $run['status'] ) {
			// هنوز در حال اجراست — ولی پیام را زنده نگه می‌داریم تا کاربر
			// بفهمد پیش می‌رود یا گیر کرده. نسخه‌ی قبلی پیام ثابتی نشان
			// می‌داد و «گیر کرده» از «در حال اجرا» قابل تفکیک نبود.
			$channel = STI_GS_Channel::get( (int) $run['channel_id'] );
			$stalled = ! wp_next_scheduled( 'sti_gs_scan_worker', array( (int) $run['channel_id'] ) );

			$note = sprintf( '%d از %d پیام پردازش شده.',
				(int) $run['processed_messages'], (int) $run['limit_count'] );
			if ( ! empty( $run['last_error'] ) ) {
				$note .= ' آخرین خطا: ' . $run['last_error'];
			} elseif ( $channel && ! empty( $channel['last_error'] ) ) {
				$note .= ' خطای کانال: ' . $channel['last_error'];
			} elseif ( $stalled ) {
				$note .= ' ⚠ هیچ کار زمان‌بندی‌شده‌ای در صف نیست — احتمالاً متوقف شده. «به‌روزرسانی وضعیت» را بزنید تا دوباره راه بیفتد.';
			}

			$state = self::state();
			$data  = $state[ $step ]['data'] ?? array();

			// اگر زنجیره قطع شده، خودمان دوباره راهش می‌اندازیم.
			if ( $stalled && $channel && STI_GS_Channel::STATUS_RUNNING === $channel['scan_status'] ) {
				self::kick_worker( (int) $run['channel_id'] );
			}

			self::save_step( $step, 'running', $note, $data );
			return null;
		}

		$channel  = STI_GS_Channel::get( (int) $run['channel_id'] );
		$expected = (int) $run['limit_count'];
		$actual   = (int) $run['processed_messages'];

		$problems = array();
		if ( STI_GS_Scan_Run::STATUS_COMPLETED !== $run['status'] ) {
			$problems[] = 'وضعیت Run ' . $run['status'] . ' است، نه COMPLETED';
		}
		if ( $actual !== $expected ) {
			$problems[] = sprintf( '%d پیام پردازش شد ولی %d درخواست شده بود', $actual, $expected );
		}
		if ( $channel && STI_GS_Channel::STATUS_DONE !== $channel['scan_status'] ) {
			$problems[] = 'وضعیت کانال ' . $channel['scan_status'] . ' است، نه done';
		}

		$data = $state[ $step ]['data'];
		$data['summary'] = STI_GS_Scan_Run::summary( $run_id );

		return empty( $problems )
			? self::save_step( $step, 'pass', sprintf(
				'%d پیام پردازش شد — %d تازه، %d تکراری، %d خطا.',
				$actual, (int) $run['inserted_messages'], (int) $run['duplicate_messages'], (int) $run['error_messages']
			), $data )
			: self::save_step( $step, 'fail', implode( '؛ ', $problems ), $data );
	}

	/** پس از پایان تکرار: هیچ ردیفی نباید اضافه یا بازنویسی شده باشد. */
	public static function evaluate_repeat() {
		$state = self::state();
		if ( empty( $state['repeat']['data']['run_id'] ) || empty( $state['repeat']['data']['before'] ) ) {
			return null;
		}

		$run = STI_GS_Scan_Run::get( (int) $state['repeat']['data']['run_id'] );
		if ( ! $run || STI_GS_Scan_Run::STATUS_RUNNING === $run['status'] ) {
			return null;
		}

		$before = $state['repeat']['data']['before'];
		$after  = self::inventory_snapshot( (int) $run['channel_id'] );

		$problems = array();
		if ( $after['total_rows'] !== $before['total_rows'] ) {
			$problems[] = sprintf( 'تعداد پیام از %d به %d تغییر کرد (نباید تغییر می‌کرد)',
				$before['total_rows'], $after['total_rows'] );
		}
		if ( $after['with_document_id'] < $before['with_document_id'] ) {
			$problems[] = sprintf( 'telegram_document_id از %d به %d کاهش یافت',
				$before['with_document_id'], $after['with_document_id'] );
		}
		if ( $after['max_id'] !== $before['max_id'] ) {
			$problems[] = 'بالاترین id تغییر کرد — یعنی ردیف‌ها حذف و دوباره درج شده‌اند';
		}
		if ( (int) $run['inserted_messages'] > 0 ) {
			$problems[] = sprintf( '%d ردیف تازه درج شد؛ در تکرار باید همه تکراری می‌بودند',
				(int) $run['inserted_messages'] );
		}

		$data = $state['repeat']['data'];
		$data['after'] = $after;

		return empty( $problems )
			? self::save_step( 'repeat', 'pass', sprintf(
				'هیچ ردیف تازه‌ای ساخته نشد؛ %d پیام به‌عنوان تکراری شناسایی شد و scan_run_id به Run تازه منتقل شد.',
				(int) $run['duplicate_messages']
			), $data )
			: self::save_step( 'repeat', 'fail', implode( '؛ ', $problems ), $data );
	}

	protected static function inventory_snapshot( $channel_id ) {
		global $wpdb;
		$table = STI_GS_DB::messages_table();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total_rows,
			        COUNT(telegram_document_id) AS with_document_id,
			        COALESCE(MAX(id), 0) AS max_id
			 FROM {$table} WHERE channel_id = %d",
			$channel_id
		), ARRAY_A );
		return array(
			'total_rows'       => (int) ( $row['total_rows'] ?? 0 ),
			'with_document_id' => (int) ( $row['with_document_id'] ?? 0 ),
			'max_id'           => (int) ( $row['max_id'] ?? 0 ),
		);
	}

	protected static function correlation_breakdown( $run_id ) {
		global $wpdb;
		$table = STI_GS_DB::messages_table();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				SUM( correlation_key LIKE 'doc:%%' )  AS by_document,
				SUM( correlation_key LIKE 'code:%%' ) AS by_code,
				SUM( correlation_key LIKE 'link:%%' ) AS by_link,
				SUM( correlation_key IS NULL )        AS no_key,
				SUM( correlation_key REGEXP '^code:[0-9]{1,4}$' ) AS suspicious_codes
			 FROM {$table} WHERE scan_run_id = %d",
			$run_id
		), ARRAY_A );

		return array(
			'by_document'      => (int) ( $row['by_document'] ?? 0 ),
			'by_code'          => (int) ( $row['by_code'] ?? 0 ),
			'by_link'          => (int) ( $row['by_link'] ?? 0 ),
			'no_key'           => (int) ( $row['no_key'] ?? 0 ),
			'suspicious_codes' => (int) ( $row['suspicious_codes'] ?? 0 ),
		);
	}

	/* ============================ گزارش نهایی ============================ */

	public static function report() {
		// مراحل در حال اجرا هر بار دوباره سنجیده می‌شوند.
		foreach ( array( 'scan100', 'scan500' ) as $step ) {
			$state = self::state();
			if ( ( $state[ $step ]['status'] ?? '' ) === 'running' ) {
				self::evaluate_scan( $step );
			}
		}
		$state = self::state();
		if ( ( $state['repeat']['status'] ?? '' ) === 'running' ) {
			self::evaluate_repeat();
		}

		$state  = self::state();
		$checks = get_option( STI_GS_System_Check::RESULT_OPTION, array() );

		$rows = array();
		$rows[] = array(
			'label'  => 'نصب و سلامت سیستم',
			'status' => empty( $checks['summary'] )
				? 'pending'
				: ( ( (int) ( $checks['summary']['fail'] ?? 0 ) > 0 ) ? 'fail' : 'pass' ),
		);
		foreach ( self::STEPS as $key => $label ) {
			$rows[] = array(
				'label'  => $label,
				'status' => $state[ $key ]['status'] ?? 'pending',
			);
		}
		return $rows;
	}

	/* ═══════════ ۱۰.۱۰ — AJAX خط تولید ═══════════ */

	/**
	 * Run Suggested Fix برای یک آیتم REVIEW.
	 *
	 * ۱۰.۱۱ (P9): بعد از Fix، **Verify** — state جدید Session در پاسخ
	 * برمی‌گردد تا UI نتیجه را همان‌جا نشان دهد (موفق → ادامه pipeline؛
	 * ناموفق → دلیل جدید ثبت شده است).
	 */
	public function ajax_review_fix() {
		$this->check_ajax();
		$session_id = (int) ( $_POST['session_id'] ?? 0 );
		$action     = sanitize_key( (string) ( $_POST['fix_action'] ?? '' ) );
		if ( ! $session_id || ! in_array( $action, array( 'rebot', 'rematch', 'redownload' ), true ) ) {
			wp_send_json_error( array( 'message' => 'ورودی نامعتبر' ), 400 );
		}
		$ok = STI_GS_Review::run_fix( $session_id, $action );
		if ( $ok ) {
			$session = STI_GS_Session::get( $session_id );
			$state   = $session ? (string) $session['state'] : '';
			$label   = ( $state && class_exists( 'STI_GS_Stage' ) ) ? STI_GS_Stage::label( $state ) : $state;
			wp_send_json_success( array(
				'message' => 'Session #' . $session_id . ' به خط تولید بازگشت.',
				'verify'  => array( 'state' => $state, 'label' => $label ),
			) );
		}
		/* دلیل ناموفق بودن را صریح بگو — نه یک «خطا» بی‌معنی. */
		$session = STI_GS_Session::get( $session_id );
		if ( ! $session ) {
			wp_send_json_error( array( 'message' => 'Session پیدا نشد (ممکن است حذف شده باشد).' ), 404 );
		}
		$st = (string) $session['state'];
		if ( class_exists( 'STI_GS_Stage' ) && STI_GS_Stage::FINAL_PUBLISHED === STI_GS_Stage::final_of( $st ) ) {
			wp_send_json_success( array( 'message' => 'Session در PUBLISHED است — کاری برای Fix نیست.', 'verify' => array( 'state' => $st, 'label' => 'PUBLISHED' ) ) );
		}
		wp_send_json_error( array( 'message' => 'اجرا نشد — Session در REVIEW نیست (state فعلی: ' . $st . ').' ), 400 );
	}

	/* ═══════════ ۱۰.۱۱ — AJAX خط تولید (Start/Stop + مانیتور زنده) ═══════════ */

	/** START LINE — continuation واقعی (Worker+کران+RUNNING). */
	public function ajax_line_start() {
		$this->check_ajax();
		$state = STI_GS_Line::start();
		wp_send_json_success( array( 'state' => $state ) );
	}

	/** STOP LINE — graceful (PAUSING → STOPPED وقتی قفل زنده نماند). */
	public function ajax_line_stop() {
		$this->check_ajax();
		$state = STI_GS_Line::stop();
		wp_send_json_success( array( 'state' => $state ) );
	}

	/** مانیتور زنده — داده‌ی کاملِ خط تولید (سبک؛ poll هر چند ثانیه). */
	public function ajax_pipeline_poll() {
		$this->check_ajax();
		wp_send_json_success( STI_GS_Line::monitor() );
	}

	/** Review Queue — poll سبک برای رندر زنده (P9: جدول static نیست). */
	public function ajax_review_poll() {
		$this->check_ajax();
		global $wpdb;
		if ( ! class_exists( 'STI_GS_DB' ) || ! class_exists( 'STI_GS_Stage' ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}
		$tbl = STI_GS_DB::pipeline_items_table();
		$states = STI_GS_Stage::review_states();
		$place = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		$items = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, file_name, state, error_reason, attempts FROM {$tbl} WHERE state IN ({$place}) ORDER BY updated_at DESC LIMIT 200",
			$states
		), ARRAY_A );
		$out = array();
		foreach ( $items as $row ) {
			$reason = class_exists( 'STI_GS_Review' ) ? STI_GS_Review::reason_of( $row ) : '';
			$fix    = class_exists( 'STI_GS_Review' ) ? STI_GS_Review::suggested_fix( $row ) : array();
			$run    = class_exists( 'STI_GS_Run_Log' ) ? STI_GS_Run_Log::for_session( (int) $row['id'] ) : null;
			$err    = (string) ( $row['error_reason'] ?? '' );
			if ( preg_match( '/^\[?REVIEW:[A-Z_]+\]/u', $err, $m ) ) {
				$err = trim( substr( $err, strlen( $m[0] ) ) );
			}
			$out[] = array(
				'id'         => (int) $row['id'],
				'file'       => mb_substr( (string) $row['file_name'], 0, 40 ),
				'stage'      => STI_GS_Stage::label( (string) $row['state'] ),
				'state'      => (string) $row['state'],
				'reason'     => class_exists( 'STI_GS_Review' ) ? STI_GS_Review::label( $reason ) : $reason,
				'error'      => mb_substr( $err, 0, 160 ),
				'attempts'   => (int) ( $row['attempts'] ?? 0 ),
				'recovery'   => (int) ( $run['recovery_count'] ?? 0 ),
				'fix_label'  => isset( $fix['label'] ) ? $fix['label'] : '',
				'fix_action' => isset( $fix['action'] ) ? $fix['action'] : null,
				'fix_desc'   => isset( $fix['description'] ) ? mb_substr( $fix['description'], 0, 160 ) : '',
			);
		}
		wp_send_json_success( array( 'items' => $out ) );
	}

	/** ۱۰.۱۰ — گام پنجم: تعیین تعداد Session + Start. */
	public function ajax_pipeline_start() {
		$this->check_ajax();
		$count = (int) ( $_POST['count'] ?? 0 );
		if ( $count < 1 || $count > 1000 ) {
			wp_send_json_error( array( 'message' => 'تعداد باید بین ۱ و ۱۰۰۰ باشد.' ), 400 );
		}
		$res = STI_GS_Channel_Watcher::start_pipeline( $count );
		wp_send_json_success( $res );
	}

	/**
	 * ۱۰.۱۱-UX+ — AJAX تشخیص Start Pipeline (فقط‌خواندنی).
	 *
	 * دقیقاً همان مسیری که دکمه‌ی Start می‌رود را می‌ساید و می‌گوید
	 * Candidateهای آماده در کدام دروازه (category / message / duplicate)
	 * می‌مانند. هیچ Session/Candidate/Cron را تغییر نمی‌دهد.
	 */
	public function ajax_start_diagnostic() {
		$this->check_ajax();
		$count = (int) ( $_POST['count'] ?? 0 );
		if ( $count < 1 || $count > 100 ) {
			wp_send_json_error( array( 'message' => 'تعداد باید بین ۱ و ۱۰۰ باشد.' ), 400 );
		}
		wp_send_json_success( STI_GS_Channel_Watcher::diagnose_start( $count ) );
	}

	/**
	 * ۱۰.۱۲ — AJAX «افزودن محصول به صف انتشار» (تب صف انتشار).
	 *
	 * ورودی:
	 *   items[]          = [ { "wc_term_id": 12, "count": 50 }, … ]
	 *   mode             = immediate | interval
	 *   interval_minutes = 1..1440 (فقط interval)
	 *   start_at         = اختیاری 'Y-m-d H:i' (فقط interval)
	 *
	 * انتخاب از هر دسته «N مورد اولویت‌دار» است (score DESC).
	 * در حالت interval: فاصله‌ی صف تنظیم می‌شود و توالی scheduled_at
	 * (start + i*interval) روی ردیف‌های تازه نوشته می‌شود؛ B2 در
	 * enqueue() جلوی جلوکشیدن این زمان‌ها را می‌گیرد.
	 */
	public function ajax_publish_queue_create() {
		$this->check_ajax();

		$raw_items = ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) ? (array) wp_unslash( $_POST['items'] ) : array();
		if ( count( $raw_items ) < 1 || count( $raw_items ) > 20 ) {
			wp_send_json_error( array( 'message' => 'بین ۱ تا ۲۰ دسته انتخاب کنید.' ), 400 );
		}
		$mode = ( (string) ( $_POST['mode'] ?? 'immediate' ) ) === 'interval' ? 'interval' : 'immediate';
		$interval_minutes = max( 1, min( 1440, (int) ( $_POST['interval_minutes'] ?? 30 ) ) );
		$start_at = trim( (string) ( $_POST['start_at'] ?? '' ) );

		$items = array();
		$total = 0;
		foreach ( $raw_items as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$cat = (int) ( $it['wc_term_id'] ?? 0 );
			$cnt = (int) ( $it['count'] ?? 0 );
			if ( $cat < 1 || $cnt < 1 || $cnt > 1000 ) {
				wp_send_json_error( array( 'message' => 'تعداد هر دسته باید بین ۱ و ۱۰۰۰ باشد.' ), 400 );
			}
			$items[] = array( 'wc_term_id' => $cat, 'count' => $cnt );
			$total  += $cnt;
		}
		if ( ! $items ) {
			wp_send_json_error( array( 'message' => 'حداقل یک دسته با تعداد معتبر انتخاب کنید.' ), 400 );
		}
		if ( $total > 1000 ) {
			wp_send_json_error( array( 'message' => 'مجموع هر بار حداکثر ۱۰۰ محصول.' ), 400 );
		}

		$int_sec = 0;
		$t0      = 0;
		if ( 'interval' === $mode ) {
			$int_sec = STI_GS_Publish_Queue::set_interval_minutes( $interval_minutes );
			$t0      = ( '' !== $start_at && strtotime( $start_at ) ) ? strtotime( $start_at ) : ( time() + 60 );
			if ( $t0 < time() + 60 ) {
				$t0 = time() + 60;
			}
		}

		$all_ids      = array();
		$per_category = array();
		foreach ( $items as $it ) {
			$res = STI_GS_Channel_Watcher::start_pipeline( $it['count'], $it['wc_term_id'], true );
			$per_category[] = array(
				'wc_term_id' => $it['wc_term_id'],
				'requested'  => $it['count'],
				'created'    => (int) ( $res['created'] ?? 0 ),
			);
			foreach ( (array) ( $res['ids'] ?? array() ) as $sid ) {
				$all_ids[] = (int) $sid;
			}
		}

		/* توالی زمان‌بندی — فقط ردیف‌هایی که هنوز به حالت نهایی نرسیده‌اند. */
		$offset           = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		$schedule_preview = array();
		if ( 'interval' === $mode && $all_ids && $int_sec > 0 ) {
			$i = 0;
			foreach ( $all_ids as $sid ) {
				$when = $t0 + ( $i * $int_sec );
				$i++;
				$sess = STI_GS_Session::get( $sid );
				if ( ! $sess ) {
					continue;
				}
				$st = (string) ( $sess['state'] ?? '' );
				$qs = (string) ( $sess['queue_status'] ?? '' );
				if ( in_array( $st, array( 'PUBLISHED', 'REVIEW', 'CANCELLED' ), true ) || 'published' === $qs ) {
					continue;
				}
				$dt = gmdate( 'Y-m-d H:i:s', $when + $offset );
				STI_GS_Session::update( $sid, array( 'scheduled_at' => $dt ) );
				if ( count( $schedule_preview ) < 10 ) {
					$schedule_preview[] = array(
						'session_id'   => $sid,
						'scheduled_at' => $dt,
					);
				}
			}
		}

		$created_total = 0;
		foreach ( $per_category as $pc ) {
			$created_total += $pc['created'];
		}

		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::info( sprintf(
				'گلدن اسکن صف انتشار: %d محصول به صف اضافه شد (حالت: %s).',
				$created_total, $mode
			) );
		}

		wp_send_json_success( array(
			'created_total'    => $created_total,
			'per_category'     => $per_category,
			'mode'             => $mode,
			'interval_seconds' => $int_sec,
			'schedule_preview' => $schedule_preview,
			'worker_on'        => class_exists( 'STI_GS_Auto_Worker' ) ? (bool) STI_GS_Auto_Worker::is_enabled() : null,
		) );
	}

	/**
	 * ۱۰.۱۲ — ذخیره‌ی انتخاب دسته‌های انتشار (optionِ B4).
	 */
	public function ajax_publish_queue_save_selection() {
		$this->check_ajax();
		$raw  = ( isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) ? (array) $_POST['categories'] : array();
		$cats = array();
		foreach ( $raw as $c ) {
			$c = (int) $c;
			if ( $c > 0 ) {
				$cats[] = $c;
			}
		}
		update_option( 'sti_gs_publish_categories', $cats, false );
		wp_send_json_success( array( 'categories' => $cats ) );
	}

	/** ذخیره‌ی Automation Settings. */
	public function ajax_automation_save() {
		$this->check_ajax();
		$all = STI_GS_Automation::all();
		$vals = array();
		foreach ( array_keys( $all ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$vals[ $key ] = $_POST[ $key ];
			}
		}
		if ( empty( $vals ) ) {
			wp_send_json_error( array( 'message' => 'مقداری برای ذخیره نفرستاده شد' ), 400 );
		}
		$saved = STI_GS_Automation::save( $vals );
		wp_send_json_success( array( 'values' => $saved ) );
	}
}
