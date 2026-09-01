<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — فاز ۱: Message Scanner.
 *
 * فقط از STI_MTProto (زیرساخت مشترک موجود) برای خواندن تاریخچه‌ی کانال
 * استفاده می‌کند. هر پیام با تمام ساختار خامش (raw_json) در sti_gs_messages
 * ذخیره می‌شود تا اگر بعداً الگوریتم فیلتر/تطبیق عوض شد، نیازی به مراجعه‌ی
 * دوباره به تلگرام نباشد.
 *
 * الگوی اجرا مطابق ماژول‌های موجود (گلدتل/چنل‌ایمپورت): چون WP-Cron روی این
 * هاست قابل‌اتکا نیست، هر tick فقط «یک صفحه» پیام می‌خواند و در صورت باز بودن
 * پنل، از طریق Ajax polling ادامه پیدا می‌کند؛ به‌صورت موازی یک self-chain
 * با wp_schedule_single_event هم برای پیشرفت پس‌زمینه ثبت می‌شود.
 */
class STI_GS_Scanner {

	const HISTORY_PAGE        = 50;   // حالت ساده (تک‌مسیره)
	const PARALLEL_PAGE       = 100;  // حالت موازی — سقف واقعی تلگرام برای هر صفحه
	const LOCK_TTL            = 5 * MINUTE_IN_SECONDS;
	const SEGMENT_LOCK_SECONDS = 90;
	const TICK_BUDGET_SECONDS  = 12;  // بودجه‌ی زمانی هر Ajax poll برای پمپاژ چند بخش موازی
	const MAX_SEGMENTS         = 10;

	protected static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'wp_ajax_sti_gs_channel_add', array( $this, 'ajax_channel_add' ) );
		add_action( 'wp_ajax_sti_gs_channel_list', array( $this, 'ajax_channel_list' ) );
		add_action( 'wp_ajax_sti_gs_channel_delete', array( $this, 'ajax_channel_delete' ) );
		add_action( 'wp_ajax_sti_gs_channel_update_identifier', array( $this, 'ajax_channel_update_identifier' ) );
		add_action( 'wp_ajax_sti_gs_scan_start', array( $this, 'ajax_scan_start' ) );
		add_action( 'wp_ajax_sti_gs_scan_start_parallel', array( $this, 'ajax_scan_start_parallel' ) );
		add_action( 'wp_ajax_sti_gs_scan_repeat_run', array( $this, 'ajax_scan_repeat_run' ) );
		add_action( 'wp_ajax_sti_gs_scan_pause', array( $this, 'ajax_scan_pause' ) );
		add_action( 'wp_ajax_sti_gs_scan_poll', array( $this, 'ajax_scan_poll' ) );
		add_action( 'sti_gs_scan_worker', array( $this, 'worker' ), 10, 1 );
		add_action( 'sti_gs_scan_segments_worker', array( $this, 'background_pump' ), 10, 1 );
	}

	protected function check_ajax() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	/* ---------------------------- Ajax: کانال‌ها ---------------------------- */

	public function ajax_channel_add() {
		$this->check_ajax();
		try {
			$identifier = sanitize_text_field( wp_unslash( $_POST['identifier'] ?? '' ) );
			$id = STI_GS_Channel::add( $identifier );
			if ( is_wp_error( $id ) ) {
				wp_send_json_error( array( 'message' => $id->get_error_message() ) );
			}

			// تلاش برای resolve فوری عنوان/chat_id، برای تجربه‌ی بهتر کاربر؛
			// این بار نتیجه (موفق/ناموفق) هم فوراً روی ردیف ذخیره و هم در پاسخ Ajax گزارش می‌شود
			// — قبلاً اینجا خطا را نادیده می‌گرفتیم و کاربر تا اولین «شروع/ادامه» چیزی نمی‌دید.
			$channel = STI_GS_Channel::get( $id );
			$resolve_message = '';
			if ( $channel && ! (int) $channel['chat_id'] ) {
				$resolved = $this->resolve_channel( $channel );
				$channel = STI_GS_Channel::get( $id );
				if ( is_wp_error( $resolved ) ) {
					$resolve_message = ' — ⚠️ resolve ناموفق: ' . $resolved->get_error_message();
				}
			}

			wp_send_json_success( array( 'message' => 'کانال ثبت شد.' . $resolve_message, 'channel' => $channel ) );
		} catch ( \Throwable $e ) {
			STI_Logger::error( 'گلدن اسکن ajax_channel_add: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => 'خطای داخلی: ' . $e->getMessage() ) );
		}
	}

	public function ajax_channel_list() {
		$this->check_ajax();
		$channels = STI_GS_Channel::all( 100 );
		foreach ( (array) $channels as &$c ) {
			$c['message_count'] = STI_GS_Channel::message_count( (int) $c['id'] );
			if ( 'segmented' === $c['scan_mode'] ) {
				$c['segments'] = STI_GS_Segment::progress_summary( (int) $c['id'] );
			}
		}
		wp_send_json_success( array( 'channels' => $channels ) );
	}

	public function ajax_channel_delete() {
		$this->check_ajax();
		$id = (int) ( $_POST['id'] ?? 0 );
		STI_GS_Channel::delete( $id );
		wp_send_json_success( array( 'message' => 'کانال و پیام‌های اسکن‌شده‌ی آن حذف شد.' ) );
	}

	/** ویرایش شناسه‌ی یک کانال (مثلاً اصلاح تایپو) بدون حذف/ثبت دوباره یا SQL دستی. */
	public function ajax_channel_update_identifier() {
		$this->check_ajax();
		try {
			$id = (int) ( $_POST['id'] ?? 0 );
			$new_identifier = sanitize_text_field( wp_unslash( $_POST['identifier'] ?? '' ) );

			$updated = STI_GS_Channel::update_identifier( $id, $new_identifier );
			if ( is_wp_error( $updated ) ) {
				wp_send_json_error( array( 'message' => $updated->get_error_message() ) );
			}

			$channel = STI_GS_Channel::get( $id );
			$resolve_message = '';
			if ( $channel && ! (int) $channel['chat_id'] ) {
				$resolved = $this->resolve_channel( $channel );
				$channel = STI_GS_Channel::get( $id );
				if ( is_wp_error( $resolved ) ) {
					$resolve_message = ' — ⚠️ resolve ناموفق: ' . $resolved->get_error_message();
				}
			}

			wp_send_json_success( array( 'message' => 'شناسه بروزرسانی شد.' . $resolve_message, 'channel' => $channel ) );
		} catch ( \Throwable $e ) {
			STI_Logger::error( 'گلدن اسکن ajax_channel_update_identifier: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => 'خطای داخلی: ' . $e->getMessage() ) );
		}
	}

	/* ------------------------------ Ajax: اسکن ------------------------------ */

	/**
	 * شروع/ادامه‌ی اسکن.
	 *
	 * P3.1 — با فرستادن «limit» (مثلاً 100 / 500 / 1000) یک Limited Scan
	 * ساخته می‌شود. بدون limit، رفتار دقیقاً همان Full Scan قبلی است.
	 *
	 * «from_message_id» اختیاری است و همان «From message ID» در §10 را
	 * پیاده می‌کند؛ برای ساختن Fixture تکرارپذیر روی یک بازه‌ی ثابت.
	 */
	public function ajax_scan_start() {
		$this->check_ajax();
		$id = (int) ( $_POST['channel_id'] ?? 0 );
		$limit = max( 0, (int) ( $_POST['limit'] ?? 0 ) );
		$from = max( 0, (int) ( $_POST['from_message_id'] ?? 0 ) );

		$channel = STI_GS_Channel::get( $id );
		if ( ! $channel ) {
			wp_send_json_error( array( 'message' => 'کانال پیدا نشد.' ) );
		}

		// اگر Run بازی وجود دارد، همان ادامه پیدا می‌کند — Resume نباید
		// با هر بار زدن «شروع» از نو بشمارد.
		$run = STI_GS_Scan_Run::current_for_channel( $id );

		if ( ! $run ) {
			$mode = $limit > 0 ? STI_GS_Scan_Run::MODE_LIMITED : STI_GS_Scan_Run::MODE_FULL;
			$created = STI_GS_Scan_Run::start( $id, $mode, array(
				'limit_count'     => $limit,
				'from_message_id' => $from,
			) );
			if ( is_wp_error( $created ) ) {
				wp_send_json_error( array( 'message' => $created->get_error_message() ) );
			}
			$run = STI_GS_Scan_Run::get( $created );

			// لنگر فقط هنگام ساخت Run تازه اعمال می‌شود، نه هنگام Resume.
			if ( $from > 0 ) {
				STI_GS_Channel::update( $id, array( 'last_scanned_message_id' => $from ) );
			} elseif ( $limit > 0 ) {
				// «N پیام آخر» یعنی از جدیدترین پیام شروع کن.
				STI_GS_Channel::update( $id, array( 'last_scanned_message_id' => 0 ) );
			}
		} else {
			STI_GS_Scan_Run::set_status( $run['id'], STI_GS_Scan_Run::STATUS_RUNNING );
		}

		STI_GS_Channel::update( $id, array( 'scan_status' => STI_GS_Channel::STATUS_RUNNING, 'last_error' => '' ) );
		if ( class_exists( 'STI_GS_Event' ) ) {
			STI_GS_Event::log( 0, 'channel_scan_started', 'ok', 'اسکن کانال #' . $id . ' شروع شد.', null, array(
				'channel_id'  => $id,
				'scan_run_id' => (int) $run['id'],
				'mode'        => $run['scan_mode'],
				'limit'       => (int) $run['limit_count'],
			) );
		}

		// Limited Scan همیشه از مسیر تک‌مسیره می‌رود: شمارش دقیق N پیام در
		// حالت موازی معنا ندارد (چند بخش هم‌زمان روی بازه‌های مختلف). حالت
		// موازی دست‌نخورده باقی می‌ماند و فقط برای Full Scan استفاده می‌شود.
		if ( 'segmented' === $channel['scan_mode'] && ! STI_GS_Scan_Run::is_limited( $run ) ) {
			$this->schedule_segments_worker( $id, 1 );
		} else {
			$this->schedule_worker( $id, 1 );
		}

		wp_send_json_success( array(
			'message'  => STI_GS_Scan_Run::is_limited( $run )
				? sprintf( 'اسکن محدود روی %d پیام شروع شد.', (int) $run['limit_count'] )
				: 'اسکن شروع شد.',
			'channel'  => STI_GS_Channel::get( $id ),
			'scan_run' => STI_GS_Scan_Run::summary( $run['id'] ),
		) );
	}

	/** تکرار یک Run قبلی با همان لنگر و همان تعداد — Fixture توسعه. */
	public function ajax_scan_repeat_run() {
		$this->check_ajax();
		$run_id = (int) ( $_POST['run_id'] ?? 0 );
		$source = STI_GS_Scan_Run::get( $run_id );
		if ( ! $source ) {
			wp_send_json_error( array( 'message' => 'Run پیدا نشد.' ) );
		}
		$channel_id = (int) $source['channel_id'];

		$open = STI_GS_Scan_Run::current_for_channel( $channel_id );
		if ( $open ) {
			wp_send_json_error( array( 'message' => 'یک Run باز روی این کانال وجود دارد؛ اول آن را تمام یا لغو کنید.' ) );
		}

		$new_id = STI_GS_Scan_Run::repeat( $run_id );
		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( array( 'message' => $new_id->get_error_message() ) );
		}

		STI_GS_Channel::update( $channel_id, array(
			'last_scanned_message_id' => STI_GS_Scan_Run::anchor( $new_id ),
			'scan_status'             => STI_GS_Channel::STATUS_RUNNING,
			'last_error'              => '',
		) );
		$this->schedule_worker( $channel_id, 1 );

		wp_send_json_success( array(
			'message'  => 'Run تکرار شد.',
			'scan_run' => STI_GS_Scan_Run::summary( $new_id ),
		) );
	}

	/**
	 * شروع اسکن موازی: بازه‌ی شناسه‌ی پیام‌ها به N بخش تقسیم می‌شود و هر بخش
	 * مستقل جلو می‌رود — برای کانال‌های بزرگ (مثلاً ۶۰هزار پیام) چند برابر سریع‌تر
	 * از حالت تک‌مسیره است، بدون این‌که پیامی دوبار در دیتابیس ذخیره شود.
	 */
	public function ajax_scan_start_parallel() {
		$this->check_ajax();
		$id = (int) ( $_POST['channel_id'] ?? 0 );
		$segment_count = max( 1, min( self::MAX_SEGMENTS, (int) ( $_POST['segments'] ?? 5 ) ) );

		$channel = STI_GS_Channel::get( $id );
		if ( ! $channel ) {
			wp_send_json_error( array( 'message' => 'کانال پیدا نشد.' ) );
		}

		if ( ! (int) $channel['chat_id'] ) {
			$resolved = $this->resolve_channel( $channel );
			if ( is_wp_error( $resolved ) ) {
				wp_send_json_error( array( 'message' => $resolved->get_error_message() ) );
			}
			$channel = STI_GS_Channel::get( $id );
		}

		$mt = STI_MTProto::instance();
		$top = $mt->get_history( (int) $channel['chat_id'], 1, 0 );
		if ( is_wp_error( $top ) ) {
			wp_send_json_error( array( 'message' => $top->get_error_message() ) );
		}
		$top_messages = (array) ( $top['messages'] ?? array() );
		$top_id = (int) ( reset( $top_messages )['id'] ?? 0 );
		if ( ! $top_id ) {
			wp_send_json_error( array( 'message' => 'کانال خالی است یا هیچ پیامی خوانده نشد.' ) );
		}

		// حالت موازی همیشه Full Scan است — Limited Scan از مسیر تک‌مسیره می‌رود.
		if ( ! STI_GS_Scan_Run::current_for_channel( $id ) ) {
			STI_GS_Scan_Run::start( $id, STI_GS_Scan_Run::MODE_FULL );
		}

		STI_GS_Segment::create_for_channel( $id, $top_id, $segment_count );
		STI_GS_Channel::update( $id, array(
			'scan_mode'     => 'segmented',
			'top_message_id'=> $top_id,
			'scan_status'   => STI_GS_Channel::STATUS_RUNNING,
			'last_error'    => '',
		) );
		$this->schedule_segments_worker( $id, 1 );

		wp_send_json_success( array(
			'message'  => "اسکن موازی با {$segment_count} بخش روی {$top_id} پیام شروع شد.",
			'channel'  => STI_GS_Channel::get( $id ),
			'segments' => STI_GS_Segment::progress_summary( $id ),
		) );
	}

	public function ajax_scan_pause() {
		$this->check_ajax();
		$id = (int) ( $_POST['channel_id'] ?? 0 );
		STI_GS_Channel::update( $id, array( 'scan_status' => STI_GS_Channel::STATUS_PAUSED ) );

		// Run باز می‌ماند (نه COMPLETED، نه FAILED) تا Resume بتواند دقیقاً از
		// همان‌جا و با همان شمارنده ادامه دهد.
		$run = STI_GS_Scan_Run::current_for_channel( $id );
		if ( $run ) {
			STI_GS_Scan_Run::set_status( $run['id'], STI_GS_Scan_Run::STATUS_PAUSED );
		}

		wp_send_json_success( array(
			'message'  => 'اسکن متوقف شد.',
			'channel'  => STI_GS_Channel::get( $id ),
			'scan_run' => $run ? STI_GS_Scan_Run::summary( $run['id'] ) : null,
		) );
	}

	/**
	 * پنل هر چند ثانیه این را صدا می‌زند. برای کانال موازی، در همین یک
	 * درخواست چند بخش پشت‌سرهم پردازش می‌شوند (در بودجه‌ی زمانی مشخص) تا
	 * سرعت اسکن چند برابر شود؛ برای حالت ساده مثل قبل یک صفحه پردازش می‌شود.
	 */
	public function ajax_scan_poll() {
		$this->check_ajax();
		$id = (int) ( $_POST['channel_id'] ?? 0 );
		$channel = STI_GS_Channel::get( $id );
		$segments_summary = null;

		$run = STI_GS_Scan_Run::current_for_channel( $id );
		$limited = STI_GS_Scan_Run::is_limited( $run );

		if ( $channel && STI_GS_Channel::STATUS_RUNNING === $channel['scan_status'] ) {
			// یک Limited Run همیشه تک‌مسیره پیش می‌رود، حتی اگر کانال قبلاً
			// روی حالت موازی تنظیم شده باشد.
			if ( 'segmented' === $channel['scan_mode'] && ! $limited ) {
				$segments_summary = $this->pump_segments( $id, self::TICK_BUDGET_SECONDS );
				if ( $segments_summary['all_done'] ) {
					STI_GS_Channel::update( $id, array(
						'scan_status'     => STI_GS_Channel::STATUS_DONE,
						'total_messages'  => $segments_summary['messages_saved'],
						'last_scanned_at' => current_time( 'mysql' ),
					) );
					if ( class_exists( 'STI_GS_Event' ) ) {
						STI_GS_Event::log( 0, 'channel_scan_completed', 'ok', 'اسکن کانال #' . $id . ' کامل شد.', null, array( 'channel_id' => $id, 'total_messages' => $segments_summary['messages_saved'] ) );
					}
				}
			} else {
				$this->worker( $id );
			}
		} elseif ( $channel && 'segmented' === $channel['scan_mode'] ) {
			$segments_summary = STI_GS_Segment::progress_summary( $id );
		}

		$channel = STI_GS_Channel::get( $id );
		wp_send_json_success( array(
			'channel'       => $channel,
			'message_count' => STI_GS_Channel::message_count( $id ),
			'segments'      => $segments_summary,
			'scan_run'      => $run ? STI_GS_Scan_Run::summary( $run['id'] ) : null,
		) );
	}

	/* -------------------------------- Worker -------------------------------- */

	protected function schedule_worker( $channel_id, $delay = 2 ) {
		if ( ! wp_next_scheduled( 'sti_gs_scan_worker', array( (int) $channel_id ) ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), 'sti_gs_scan_worker', array( (int) $channel_id ) );
		}
	}

	protected function schedule_segments_worker( $channel_id, $delay = 2 ) {
		if ( ! wp_next_scheduled( 'sti_gs_scan_segments_worker', array( (int) $channel_id ) ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), 'sti_gs_scan_segments_worker', array( (int) $channel_id ) );
		}
	}

	/** برای پیشرفت پس‌زمینه (بدون نیاز به پنل باز)؛ همان pump_segments با بودجه‌ی کوتاه‌تر. */
	public function background_pump( $channel_id ) {
		$channel_id = (int) $channel_id;
		$channel = STI_GS_Channel::get( $channel_id );
		if ( ! $channel || STI_GS_Channel::STATUS_RUNNING !== $channel['scan_status'] ) {
			return;
		}
		$summary = $this->pump_segments( $channel_id, self::TICK_BUDGET_SECONDS );
		if ( $summary['all_done'] ) {
			STI_GS_Channel::update( $channel_id, array(
				'scan_status'     => STI_GS_Channel::STATUS_DONE,
				'total_messages'  => $summary['messages_saved'],
				'last_scanned_at' => current_time( 'mysql' ),
			) );
			if ( class_exists( 'STI_GS_Event' ) ) {
				STI_GS_Event::log( 0, 'channel_scan_completed', 'ok', 'اسکن کانال #' . $channel_id . ' کامل شد (پس‌زمینه).', null, array( 'channel_id' => $channel_id, 'total_messages' => $summary['messages_saved'] ) );
			}
			return;
		}
		$this->schedule_segments_worker( $channel_id, 2 );
	}

	/**
	 * در بودجه‌ی زمانی مشخص، پشت‌سرهم بخش‌های آماده را claim و یک صفحه از
	 * هرکدام را می‌خواند — این همان «موازی‌سازی در یک درخواست» است که سرعت
	 * اسکن کانال‌های ۶۰هزار پیامی را چند برابر می‌کند.
	 */
	protected function pump_segments( $channel_id, $budget_seconds ) {
		// بن‌بست شناخته‌شده: اگر کانال قبلاً هیچ‌وقت resolve نشده (chat_id=0) —
		// مثلاً یک بار error خورده و کاربر با «شروع/ادامه» (نه «موازی») دوباره
		// تلاش کرده — بدون این چک، هر segment با chat_id=0 بی‌صدا شکست می‌خورد
		// و دوباره زمان‌بندی می‌شود؛ یعنی یک حلقه‌ی بی‌پایان و بی‌خطا.
		$channel = STI_GS_Channel::get( $channel_id );
		if ( $channel && ! (int) $channel['chat_id'] ) {
			$resolved = $this->resolve_channel( $channel );
			if ( is_wp_error( $resolved ) ) {
				STI_GS_Channel::update( $channel_id, array( 'scan_status' => STI_GS_Channel::STATUS_ERROR ) );
				return STI_GS_Segment::progress_summary( $channel_id );
			}
		}

		$start = microtime( true );
		while ( microtime( true ) - $start < $budget_seconds ) {
			$segment = STI_GS_Segment::next_available( $channel_id );
			if ( ! $segment ) {
				break;
			}
			if ( ! STI_GS_Segment::claim( (int) $segment['id'], self::SEGMENT_LOCK_SECONDS ) ) {
				// یک درخواست موازی دیگر (مثلاً cron) همین الان آن را قاپید؛ برو سراغ بعدی.
				continue;
			}
			$this->worker_segment( (int) $segment['id'] );
		}
		return STI_GS_Segment::progress_summary( $channel_id );
	}

	/** یک صفحه (تا ۱۰۰ پیام) از یک بخشِ مشخص را می‌خواند و ذخیره می‌کند. فرض: قبلاً claim شده. */
	protected function worker_segment( $segment_id ) {
		try {
			$segment = STI_GS_Segment::get( $segment_id );
			if ( ! $segment || STI_GS_Segment::STATUS_DONE === $segment['status'] ) {
				return;
			}
			$channel = STI_GS_Channel::get( (int) $segment['channel_id'] );
			if ( ! $channel ) {
				return;
			}

			$mt = STI_MTProto::instance();
			$history = $mt->get_history( (int) $channel['chat_id'], self::PARALLEL_PAGE, (int) $segment['current_offset'] );
			if ( is_wp_error( $history ) ) {
				STI_GS_Segment::update( $segment_id, array(
					'status'     => STI_GS_Segment::STATUS_ERROR,
					'last_error' => mb_substr( $history->get_error_message(), 0, 480 ),
				) );
				return;
			}

			$messages = (array) ( $history['messages'] ?? array() );
			if ( empty( $messages ) ) {
				STI_GS_Segment::update( $segment_id, array( 'status' => STI_GS_Segment::STATUS_DONE ) );
				return;
			}

			$segment_run = STI_GS_Scan_Run::current_for_channel( (int) $segment['channel_id'] );
			$segment_run_id = $segment_run ? (int) $segment_run['id'] : 0;
			$saved = 0;
			$min_id_in_batch = PHP_INT_MAX;
			foreach ( $messages as $message ) {
				$mid = (int) ( $message['id'] ?? 0 );
				if ( $mid && $mid < $min_id_in_batch ) {
					$min_id_in_batch = $mid;
				}
				// اگر پیام از مرز پایینی این بخش رد شده، رد شود؛ آن پیام قبلاً به بخش
				// پایین‌تر تعلق دارد و توسط خودش (یا با overlap بی‌ضرر) ذخیره می‌شود.
				if ( $this->save_message( (int) $segment['channel_id'], $message, $segment_run_id ) ) {
					$saved++;
				}
			}

			$reached_boundary = $min_id_in_batch !== PHP_INT_MAX && $min_id_in_batch <= (int) $segment['range_from'];
			$new_saved_total  = (int) $segment['messages_saved'] + $saved;

			if ( $reached_boundary || count( $messages ) < self::PARALLEL_PAGE ) {
				STI_GS_Segment::update( $segment_id, array(
					'status'         => STI_GS_Segment::STATUS_DONE,
					'messages_saved' => $new_saved_total,
				) );
			} else {
				STI_GS_Segment::update( $segment_id, array(
					'current_offset' => $min_id_in_batch,
					'status'         => STI_GS_Segment::STATUS_RUNNING,
					'messages_saved' => $new_saved_total,
				) );
			}
		} catch ( \Throwable $e ) {
			STI_GS_Segment::update( $segment_id, array(
				'status'     => STI_GS_Segment::STATUS_ERROR,
				'last_error' => mb_substr( $e->getMessage(), 0, 480 ),
			) );
		} finally {
			STI_GS_Segment::release( $segment_id );
		}
	}

	/** یک صفحه (تا ۵۰ پیام) از تاریخچه‌ی کانال را می‌خواند و ذخیره می‌کند. (حالت ساده‌ی تک‌مسیره) */
	public function worker( $channel_id ) {
		$channel_id = (int) $channel_id;
		$lock = 'sti_gs_scan_lock_' . $channel_id;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, self::LOCK_TTL );

		try {
			$channel = STI_GS_Channel::get( $channel_id );
			if ( ! $channel || STI_GS_Channel::STATUS_RUNNING !== $channel['scan_status'] ) {
				return;
			}

			if ( ! (int) $channel['chat_id'] ) {
				$resolved = $this->resolve_channel( $channel );
				if ( is_wp_error( $resolved ) ) {
					$this->fail_channel( $channel_id, $resolved->get_error_message() );
					return;
				}
				$channel = STI_GS_Channel::get( $channel_id );
			}

			// P3.1 — Limited Scan. بدون Run یا با Run از نوع full، اندازه‌ی
			// صفحه و رفتار دقیقاً همان قبل است.
			$run  = STI_GS_Scan_Run::current_for_channel( $channel_id );
			$page = self::HISTORY_PAGE;
			$remaining = PHP_INT_MAX;

			if ( STI_GS_Scan_Run::is_limited( $run ) ) {
				$remaining = STI_GS_Scan_Run::remaining( $run );
				if ( $remaining < 1 ) {
					// رسیدن به سقف یک پایان **موفق** است، نه خطا.
					$this->complete_scan( $channel_id, $run, 'limit_reached' );
					return;
				}
				$page = min( $page, $remaining );
			}

			$mt = STI_MTProto::instance();
			$history = $mt->get_history( (int) $channel['chat_id'], $page, (int) $channel['last_scanned_message_id'] );
			if ( is_wp_error( $history ) ) {
				$this->fail_channel( $channel_id, $history->get_error_message() );
				return;
			}

			$messages = (array) ( $history['messages'] ?? array() );

			if ( empty( $messages ) ) {
				$this->complete_scan( $channel_id, $run, 'channel_exhausted' );
				return;
			}

			// اگر تلگرام بیشتر از درخواست برگرداند، مازاد را نگه نمی‌داریم —
			// «۵۰۰ پیام» باید دقیقاً ۵۰۰ باشد.
			if ( count( $messages ) > $remaining ) {
				$messages = array_slice( $messages, 0, $remaining );
			}

			$saved = 0;
			$inserted = 0;
			$duplicates = 0;
			$errors = 0;
			foreach ( $messages as $message ) {
				$affected = null;
				if ( ! $this->save_message( $channel_id, $message, $run ? (int) $run['id'] : 0, $affected ) ) {
					$errors++;
					continue;
				}
				$saved++;
				// MySQL در ON DUPLICATE KEY UPDATE مقدار ۱ برای درج تازه و ۲
				// برای بروزرسانی برمی‌گرداند؛ ۰ یعنی ردیف بود و چیزی تغییر نکرد.
				if ( 1 === (int) $affected ) {
					$inserted++;
				} else {
					$duplicates++;
				}
			}

			$last = end( $messages );
			$next_offset = (int) ( $last['id'] ?? $channel['last_scanned_message_id'] );

			STI_GS_Channel::update( $channel_id, array(
				'last_scanned_message_id' => $next_offset,
				'total_messages'          => (int) $channel['total_messages'] + $saved,
				'last_scanned_at'         => current_time( 'mysql' ),
			) );

			if ( $run ) {
				STI_GS_Scan_Run::record_batch( $run['id'], array(
					'processed'  => count( $messages ),
					'inserted'   => $inserted,
					'duplicates' => $duplicates,
					'errors'     => $errors,
				) );

				// شمارنده‌ها تازه بالا رفته‌اند، پس Run دوباره خوانده می‌شود.
				if ( STI_GS_Scan_Run::is_limited( $run ) && STI_GS_Scan_Run::limit_reached( $run['id'] ) ) {
					$this->complete_scan( $channel_id, $run, 'limit_reached' );
					return;
				}
			}
		} catch ( \Throwable $e ) {
			$this->fail_channel( $channel_id, $e->getMessage() );
		} finally {
			delete_transient( $lock );
		}

		// اگر هنوز در حال اجراست، برای پیشرفت پس‌زمینه (بدون نیاز به پنل باز) دوباره زمان‌بندی شود.
		$after = STI_GS_Channel::get( $channel_id );
		if ( $after && STI_GS_Channel::STATUS_RUNNING === $after['scan_status'] ) {
			$this->schedule_worker( $channel_id, 2 );
		}
	}

	/**
	 * پایان موفق اسکن — چه به‌خاطر رسیدن به سقف Limited Scan، چه به‌خاطر
	 * تمام شدن پیام‌های کانال. هر دو COMPLETED هستند، نه FAILED.
	 *
	 * چون وضعیت کانال به DONE می‌رود، حلقه‌ی self-chain انتهای worker()
	 * دیگر خودش را زمان‌بندی نمی‌کند — یعنی Loop بی‌نهایت ممکن نیست.
	 */
	protected function complete_scan( $channel_id, $run, $reason ) {
		STI_GS_Channel::update( $channel_id, array(
			'scan_status'     => STI_GS_Channel::STATUS_DONE,
			'last_scanned_at' => current_time( 'mysql' ),
		) );

		$summary = null;
		$correlation = null;
		if ( $run ) {
			STI_GS_Scan_Run::finish( $run['id'], STI_GS_Scan_Run::STATUS_COMPLETED );
			$summary = STI_GS_Scan_Run::summary( $run['id'] );

			// P3.3 — پاس Correlation بلافاصله پس از پایان اسکن.
			//
			// جای درستش همین‌جاست و نه داخل save_message(): منطق Correlation
			// در فاز توسعه بارها عوض می‌شود و باید بشود بدون اسکن دوباره
			// روی همان Inventory اجرایش کرد. اینجا فقط «اولین اجرا» است؛
			// اجرای مجدد با STI_GS_Correlation::run_for_scan_run() ممکن است.
			if ( class_exists( 'STI_GS_Correlation' ) ) {
				$correlation = STI_GS_Correlation::run_for_scan_run( (int) $run['id'] );
			}
		}

		if ( class_exists( 'STI_GS_Event' ) ) {
			STI_GS_Event::log( 0, 'channel_scan_completed', 'ok', 'اسکن کانال #' . $channel_id . ' کامل شد.', null, array(
				'channel_id'  => (int) $channel_id,
				'reason'      => $reason,
				'scan_run_id' => $run ? (int) $run['id'] : 0,
				'summary'     => $summary,
				'correlation' => $correlation,
			) );
		}
		STI_Logger::success( sprintf(
			'گلدن اسکن: اسکن کانال #%d کامل شد (%s).%s',
			(int) $channel_id,
			$reason,
			$summary ? sprintf( ' پردازش‌شده: %d، تازه: %d، تکراری: %d، خطا: %d.',
				$summary['processed'], $summary['inserted'], $summary['duplicates'], $summary['errors'] ) : ''
		) );

		if ( $correlation ) {
			STI_Logger::info( sprintf(
				'گلدن اسکن Correlation: %d پیام بررسی شد، %d کلید ساخته شد، %d بدون کلید.',
				$correlation['scanned'], $correlation['keyed'], $correlation['unresolved']
			) );
		}
	}

	protected function fail_channel( $channel_id, $message ) {
		STI_GS_Channel::update( $channel_id, array(
			'scan_status' => STI_GS_Channel::STATUS_ERROR,
			'last_error'  => mb_substr( (string) $message, 0, 1000 ),
		) );
		if ( class_exists( 'STI_GS_Event' ) ) {
			STI_GS_Event::log( 0, 'channel_scan_failed', 'error', (string) $message, null, array( 'channel_id' => (int) $channel_id ) );
		}
		$run = STI_GS_Scan_Run::current_for_channel( $channel_id );
		if ( $run ) {
			STI_GS_Scan_Run::finish( $run['id'], STI_GS_Scan_Run::STATUS_FAILED, (string) $message );
		}
		STI_Logger::error( 'گلدن اسکن #' . (int) $channel_id . ': ' . $message );
	}

	protected function resolve_channel( $channel ) {
		$mt = STI_MTProto::instance();
		$info = $mt->chat_info( $channel['identifier'] );
		if ( is_wp_error( $info ) ) {
			// همیشه (نه فقط از داخل worker) ذخیره شود، وگرنه کاربر تا اولین
			// «شروع/ادامه» هیچ خطایی روی ردیف نمی‌بیند — دقیقاً همان گزارش باگ.
			STI_GS_Channel::update( (int) $channel['id'], array(
				'last_error' => 'پیدا کردن کانال ناموفق: ' . mb_substr( $info->get_error_message(), 0, 900 ),
			) );
			return $info;
		}
		if ( empty( $info['id'] ) ) {
			$err = new WP_Error( 'sti_gs_no_chat_id', 'شناسه‌ی عددی کانال پیدا نشد.' );
			STI_GS_Channel::update( (int) $channel['id'], array( 'last_error' => $err->get_error_message() ) );
			return $err;
		}
		STI_GS_Channel::update( (int) $channel['id'], array(
			'chat_id'    => (int) $info['id'],
			'title'      => (string) ( $info['title'] ?? '' ),
			'last_error' => '',
		) );
		return true;
	}

	/**
	 * ذخیره‌ی یک پیام نرمال‌شده (خروجی STI_MTProto::normalize_message) در
	 * جدول sti_gs_messages. Idempotent: پیام تکراری فقط بروزرسانی می‌شود.
	 */
	/**
	 * @param int   $channel_id
	 * @param array $message   خروجی normalize_message()
	 * @param int   $scan_run_id  Run ای که این پیام را آورده. صفر یعنی نامشخص
	 *                 (مثلاً اسکنی که پیش از P3.1 شروع شده بود).
	 * @param int|null $affected خروجی: تعداد ردیف تحت‌تأثیر MySQL.
	 *                 ۱ = درج تازه، ۲ = بروزرسانی، ۰ = ردیف بود و تغییری نکرد.
	 *                 Scan Run با همین عدد «تازه» را از «تکراری» تفکیک می‌کند.
	 * @return bool false یعنی نوشتن انجام نشد.
	 */
	protected function save_message( $channel_id, $message, $scan_run_id = 0, &$affected = null ) {
		$affected = 0;
		global $wpdb;
		$msg_id = (int) ( $message['id'] ?? 0 );
		if ( ! $msg_id ) {
			return false;
		}

		$text = (string) ( $message['text'] ?? '' );
		$file_code = $this->extract_file_code( $text, $message );

		$buttons = (array) ( $message['buttons'] ?? array() );
		$has_button = ! empty( $buttons ) ? 1 : 0;
		$button_summary = '';
		if ( $has_button ) {
			$first = reset( $buttons );
			$button_summary = mb_substr( trim( (string) ( $first['text'] ?? '' ) ), 0, 250 );
		}

		$raw = $message['raw'] ?? $message;
		$views = (int) ( $raw['views'] ?? 0 );
		$forwards = (int) ( $raw['forwards'] ?? 0 );
		$raw_json = wp_json_encode( $raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// شناسه‌های رسانه‌ی MTProto. این ستون‌ها در مسیر MTProto حاوی
		// MTProto media ID هستند، نه Bot API file_id — قرارداد کامل در
		// class-gs-media-ids.php. telegram_unique_id عمداً NULL می‌ماند چون
		// file_unique_id مفهوم Bot API است و معادل‌سازی جعلی انجام نمی‌شود.
		// P3.2 — telegram_document_id تنها منبع حقیقت هویت سند است.
		// document_file_id عمداً نوشته نمی‌شود و NULL می‌ماند.
		$media_ids    = STI_GS_Media_Ids::from_message( $message );
		$doc_id_sql   = STI_GS_Media_Ids::sql_literal( $media_ids['telegram_document_id'] );
		$photo_id_sql = STI_GS_Media_Ids::sql_literal( $media_ids['photo_file_id'] );
		$video_id_sql = STI_GS_Media_Ids::sql_literal( $media_ids['video_file_id'] );

		$table = STI_GS_DB::messages_table();
		$now = current_time( 'mysql' );

		// $wpdb->prepare هیچ راهی برای تولید NULL ندارد: %s مقدار null را به
		// رشته‌ی خالی و %d آن را به صفر تبدیل می‌کند. نسخه‌ی قبلی همین کد
		// «null» را به prepare می‌داد و در عمل '' ذخیره می‌شد — یعنی
		// message_date خالی به تاریخ صفر تبدیل می‌شد و
		// «WHERE file_code IS NULL» هیچ‌وقت چیزی برنمی‌گرداند.
		// راه‌حل: برای ستون‌های nullable، جای placeholder یا literal NULL
		// می‌گذاریم و آرگومان را فقط وقتی مقدار واقعی هست اضافه می‌کنیم.
		$args = array( (int) $channel_id, $msg_id );

		$message_date = ! empty( $message['date_mysql'] ) ? (string) $message['date_mysql'] : '';
		if ( '' !== $message_date ) {
			$date_sql = '%s';
			$args[]   = $message_date;
		} else {
			$date_sql = 'NULL';
		}

		$args[] = $text;
		$args[] = (string) ( $message['media_type'] ?? 'none' );
		$args[] = mb_substr( (string) ( $message['file_name'] ?? '' ), 0, 250 );
		$args[] = (int) ( $message['file_size'] ?? 0 );

		$file_code = (string) $file_code;
		if ( '' !== $file_code ) {
			$code_sql = '%s';
			$args[]   = $file_code;
		} else {
			$code_sql = 'NULL';
		}

		$args[] = $button_summary;
		$args[] = $has_button;
		$args[] = $views;
		$args[] = $forwards;
		$args[] = $raw_json;
		$args[] = (int) $scan_run_id;
		$args[] = $now;

		$sql = "INSERT INTO {$table}
			(channel_id, message_id, message_date, text_raw, media_type, file_name, file_size, file_code, button_summary, has_button, views, forwards, raw_json, telegram_document_id, photo_file_id, video_file_id, scan_run_id, indexed_at)
			VALUES (%d, %d, {$date_sql}, %s, %s, %s, %d, {$code_sql}, %s, %d, %d, %d, %s, {$doc_id_sql}, {$photo_id_sql}, {$video_id_sql}, %d, %s)
			ON DUPLICATE KEY UPDATE
				text_raw = VALUES(text_raw), media_type = VALUES(media_type), file_name = VALUES(file_name),
				file_size = VALUES(file_size), file_code = VALUES(file_code), button_summary = VALUES(button_summary),
				has_button = VALUES(has_button), views = VALUES(views), forwards = VALUES(forwards),
				raw_json = VALUES(raw_json), telegram_document_id = VALUES(telegram_document_id),
				photo_file_id = VALUES(photo_file_id), video_file_id = VALUES(video_file_id),
				scan_run_id = VALUES(scan_run_id),
				message_date = VALUES(message_date), indexed_at = VALUES(indexed_at)";

		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		if ( false === $result ) {
			// قبلاً این حالت بی‌صدا true برمی‌گرداند و پیامِ ذخیره‌نشده
			// «ذخیره‌شده» شمرده می‌شد (§84).
			STI_Logger::error( sprintf(
				'گلدن اسکن: ذخیره‌ی پیام #%d از کانال #%d ناموفق بود: %s',
				$msg_id, (int) $channel_id, $wpdb->last_error
			) );
			return false;
		}

		$affected = (int) $result;
		return true;
	}

	/**
	 * استخراج کد فایل با استفاده از پارسر مشترک موجود (STI_Caption_Parser)،
	 * به‌علاوه‌ی جست‌وجوی الگوی start= در دکمه‌ها به‌عنوان جایگزین.
	 */
	protected function extract_file_code( $text, $message ) {
		$code = '';
		if ( class_exists( 'STI_Caption_Parser' ) ) {
			$parsed = STI_Caption_Parser::parse( $text );
			$code = (string) ( $parsed['file_code'] ?? '' );
		}
		if ( '' === $code ) {
			foreach ( (array) ( $message['buttons'] ?? array() ) as $button ) {
				$url = (string) ( $button['url'] ?? '' );
				if ( preg_match( '#[?&]start=([A-Za-z0-9_-]+)#i', $url, $m ) ) {
					$code = $m[1];
					break;
				}
			}
		}
		if ( '' !== $code && class_exists( 'STI_Channel_Index' ) ) {
			$code = STI_Channel_Index::normalize_code( $code );
		}
		return $code;
	}
}
