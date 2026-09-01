<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI Scheduler — صف انتشار بومی و قابل‌اعتماد
 *
 * چرا بازنویسی شد؟
 * نسخه‌ی قبلی فقط با Action Scheduler (as_schedule_single_action) زمان‌بندی می‌کرد.
 * اگر آن افزونه نصب نبود (یا WP-Cron هاست از کار افتاده بود) دو مشکل رخ می‌داد:
 *   - هیچ پستی منتشر نمی‌شد (صف تا «ساعت‌ها» می‌ماند) یا
 *   - چند event با هم اجرا می‌شدند و در یک دقیقه ۲ پست منتشر می‌شد.
 *
 * حالا صف به‌صورت کاملاً بومی کار می‌کند:
 *   - یک cron هر ۶۰ ثانیه (sti_queue_tick) سشن‌های «در صف» را چک می‌کند.
 *   - فقط سشن‌هایی منتشر می‌شوند که queue_next_attempt_at آن‌ها رسیده باشد.
 *   - یک قفل اتمی (دیتابیس) مانع اجرای هم‌زمان دو tick می‌شود → حداکثر ۱ انتشار در هر دقیقه.
 *   - فاصله‌ی دقیق بین انتشارها = queue_interval_minutes (پیش‌فرض ۳۰ دقیقه).
 */
class STI_Scheduler {

	protected static $instance;

	const LAST_PUBLISHED_OPTION = 'sti_queue_last_published_at';
	const LOCK_OPTION           = 'sti_queue_lock';
	const TICK_HOOK             = 'sti_queue_tick';
	const TICK_INTERVAL         = 60; // ثانیه

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );
		add_action( self::TICK_HOOK, array( $this, 'tick' ) );
		add_action( 'sti_publish_product_action', array( $this, 'publish_session_by_id' ) ); // سازگاری با event های قبلی AS
	}

	/** ثبت interval هر ۶۰ ثانیه برای WP-Cron. */
	public function register_cron_interval( $schedules ) {
		$schedules['sti_every_minute'] = array(
			'interval' => self::TICK_INTERVAL,
			'display'  => 'هر دقیقه (STI Queue)',
		);
		return $schedules;
	}

	/** اطمینان از زمان‌بندی tick (خودترمیمی — در هر بار اجرا). */
	public static function ensure_tick_scheduled() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + 30, 'sti_every_minute', self::TICK_HOOK );
		}
	}

	/**
	 * افزودن محصول به صف انتشار.
	 * فقط دیتابیس به‌روز می‌شود؛ tick خودش در زمان مناسب انتشار می‌دهد.
	 */
	public static function enqueue( $session_id, $product_id ) {
		STI_Session::update( $session_id, array(
			'status'    => 'scheduled',
			'product_id'=> $product_id,
			'queue_attempts' => 0,
			'queue_last_attempt_at' => null,
		) );

		if ( ! STI_Settings::get( 'queue_enabled', 1 ) ) {
			return;
		}

		$interval = self::interval_seconds();
		$last     = (int) get_option( self::LAST_PUBLISHED_OPTION, 0 );
		$next     = max( time(), $last + $interval );

		STI_Session::update( $session_id, array(
			'queue_next_attempt_at' => wp_date( 'Y-m-d H:i:s', $next, wp_timezone() ),
		) );

		// سشن بعدی باید حداقل interval بعد از این یکی منتشر شود
		update_option( self::LAST_PUBLISHED_OPTION, $next, false );

		self::ensure_tick_scheduled();
	}

	/** تیک هر دقیقه — قلب صف. */
	public function tick() {
		if ( ! STI_Settings::get( 'queue_enabled', 1 ) ) {
			return;
		}

		// قفل اتمی: اگر tick دیگری همین الان در حال کار است، این یکی رد شود.
		$lock = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $lock && ( time() - $lock ) < 45 ) {
			return;
		}
		update_option( self::LOCK_OPTION, time(), false );

		try {
			$now = current_time( 'mysql' );
			$next = STI_Session::get_next_due_scheduled( $now );

			if ( $next ) {
				$this->publish_session( $next );
			}
		} catch ( \Throwable $e ) {
			STI_Logger::error( 'Queue tick error: ' . $e->getMessage() );
		}

		delete_option( self::LOCK_OPTION );
	}

	/** انتشار مستقیم (دکمه‌ی «انتشار فوری»). */
	public function publish_now( $session_id ) {
		$session = STI_Session::get( $session_id );
		if ( ! $session || 'scheduled' !== $session->status ) {
			return new WP_Error( 'sti_not_queued', 'این Session در صف انتشار نیست.' );
		}
		$this->publish_session( $session );
		$updated = STI_Session::get( $session_id );
		return 'published' === $updated->status ? true : new WP_Error( 'sti_publish_failed', $updated->error_message ?: 'انتشار ناموفق بود.' );
	}

	/** سازگاری با event های قدیمی Action Scheduler. */
	public function publish_session_by_id( $session_id ) {
		$session = STI_Session::get( $session_id );
		if ( ! $session || 'scheduled' !== $session->status ) {
			return;
		}
		$this->publish_session( $session );
	}

	protected function publish_session( $session ) {
		STI_Session::update( $session->id, array(
			'queue_attempts'      => (int) $session->queue_attempts + 1,
			'queue_last_attempt_at' => current_time( 'mysql' ),
		) );

		$product = wc_get_product( $session->product_id );
		if ( ! $product ) {
			$this->handle_publish_failure( $session, "محصول #{$session->product_id} برای انتشار پیدا نشد." );
			return;
		}

		try {
			$product->set_status( 'publish' );
			$product->save();

			update_option( self::LAST_PUBLISHED_OPTION, time(), false );

			STI_Session::update( $session->id, array(
				'status' => 'published',
				'queue_next_attempt_at' => null,
			) );

			STI_Logger::success( "محصول #{$session->product_id} از صف منتشر شد.", $session->id );

			$notify_chat_id = isset( $session->notify_chat_id ) && null !== $session->notify_chat_id ? (int) $session->notify_chat_id : (int) $session->chat_id;
			if ( $notify_chat_id ) {
				$api = new STI_Telegram_API();
				$api->send_message( $notify_chat_id, "🚀 محصول منتشر شد!\n🔗 " . admin_url( "post.php?post={$session->product_id}&action=edit" ) . "\n📬 باقی‌مانده در صف: " . STI_Session::count_queued() );
			}
		} catch ( \Throwable $e ) {
			$this->handle_publish_failure( $session, $e->getMessage() );
		}
	}

	protected function handle_publish_failure( $session, $reason ) {
		$attempts = (int) $session->queue_attempts + 1;
		if ( $attempts >= 4 ) {
			STI_Session::mark_error( $session->id, 'انتشار صف پس از چند تلاش ناموفق بود: ' . $reason );
			STI_Logger::error( 'Queue publish permanently failed: ' . $reason, $session->id );
			return;
		}
		$minutes = array( 1, 5, 15 );
		$retry_time = time() + $minutes[ $attempts - 1 ] * MINUTE_IN_SECONDS;
		STI_Session::update( $session->id, array(
			'status' => 'scheduled',
			'queue_next_attempt_at' => wp_date( 'Y-m-d H:i:s', $retry_time, wp_timezone() ),
			'error_message' => 'تلاش انتشار ناموفق؛ تلاش بعدی زمان‌بندی شد: ' . $reason,
		) );
		STI_Logger::warning( "Queue publish failed; retry #{$attempts} scheduled: {$reason}", $session->id );
	}

	public static function is_running() {
		return (bool) STI_Settings::get( 'queue_enabled', 1 );
	}

	public static function set_running( $running ) {
		STI_Settings::update( array( 'queue_enabled' => $running ? 1 : 0 ) );

		if ( $running ) {
			// صف را از نو فعال کن: قفل را پاک کن و tick را مطمئن شو
			delete_option( self::LOCK_OPTION );
			self::ensure_tick_scheduled();

			// سشن‌های در صف بدون زمان‌بندی (از نسخه‌های قدیمی) را فوراً زمان‌بندی کن
			$queued = STI_Session::get_queue_list( 1000 );
			$interval = self::interval_seconds();
			$last = (int) get_option( self::LAST_PUBLISHED_OPTION, 0 );
			$next = max( time(), $last + $interval );
			foreach ( $queued as $q ) {
				if ( empty( $q['queue_next_attempt_at'] ) ) {
					STI_Session::update( $q['id'], array( 'queue_next_attempt_at' => wp_date( 'Y-m-d H:i:s', $next, wp_timezone() ) ) );
					$next += $interval;
				}
			}
			update_option( self::LAST_PUBLISHED_OPTION, $next - $interval, false );
		} else {
			// توقف صف — فقط پرچم؛ سشن‌ها دست‌نخورده می‌مانند
		}
	}

	/**
	 * باززمان‌بندی تمام صف بر اساس interval جدید — با رفتار انسانی
	 * هر محصول دقیقاً interval بعد از قبلی، با کمی jitter تصادفی 0-15% برای جلوگیری از الگوی رباتیک
	 */
	public static function reschedule_all() {
		$queued = STI_Session::get_queue_list( 1000 );
		if ( empty( $queued ) ) { return 0; }

		$interval = self::interval_seconds();
		$last = (int) get_option( self::LAST_PUBLISHED_OPTION, 0 );
		// اگر آخرین انتشار خیلی قدیمی باشد (بیش از 1 روز پیش)، از الان شروع کن
		if ( $last < ( time() - DAY_IN_SECONDS ) ) {
			$last = time() - $interval;
		}
		$next = max( time() + 60, $last + $interval ); // حداقل 60 ثانیه بعد

		$count = 0;
		foreach ( $queued as $q ) {
			// jitter انسانی: 0 تا 15% اضافه برای جلوگیری از تشخیص ربات، ولی نه کمتر از interval (تا فشار نیاریم)
			$jitter = 0;
			if ( $interval >= 120 ) { // فقط برای interval های >=2 دقیقه jitter اضافه کن
				$jitter = wp_rand( 0, (int)( $interval * 0.15 ) );
			}
			$scheduled_ts = $next + $jitter;
			STI_Session::update( $q->id, array(
				'queue_next_attempt_at' => wp_date( 'Y-m-d H:i:s', $scheduled_ts, wp_timezone() ),
			) );
			$next = $scheduled_ts + $interval;
			$count++;
		}
		update_option( self::LAST_PUBLISHED_OPTION, $last, false );
		STI_Logger::info( "صف انتشار باززمان‌بندی شد — {$count} محصول با فاصله هر " . ( $interval / 60 ) . " دقیقه (با jitter انسانی)" );
		return $count;
	}

	public static function interval_seconds() {
		$mode = STI_Settings::get( 'queue_mode', 'fixed' );
		if ( 'smart' === $mode ) {
			return self::smart_interval_seconds();
		}
		return max( 1, (int) STI_Settings::get( 'queue_interval_minutes', 30 ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * فاصله‌ی هوشمند: بین min و max دقیقه، با jitter و در نظر گرفتن ساعت روز
	 * تا الگوی انتشار شبیه انسان باشد و فشار یکنواخت به سرور/تلگرام نیاید.
	 */
	public static function smart_interval_seconds() {
		$min = max( 3, (int) STI_Settings::get( 'queue_smart_min_minutes', 8 ) );
		$max = max( $min + 1, (int) STI_Settings::get( 'queue_smart_max_minutes', 45 ) );

		// ساعت محلی سایت
		$hour = (int) wp_date( 'G' );

		// شب (0-7) فاصله بیشتر؛ روز کاری فاصله متوسط؛ عصر اوج کمتر
		if ( $hour >= 0 && $hour < 7 ) {
			$base = (int) ( ( $min + $max ) * 0.75 );
		} elseif ( $hour >= 18 && $hour <= 22 ) {
			$base = (int) ( ( $min + $max ) * 0.4 );
		} else {
			$base = (int) ( ( $min + $max ) * 0.55 );
		}
		$base = max( $min, min( $max, $base ) );

		// jitter ±30%
		$jitter = (int) round( $base * ( wp_rand( -30, 30 ) / 100 ) );
		$minutes = max( $min, min( $max, $base + $jitter ) );

		return $minutes * MINUTE_IN_SECONDS;
	}

	public static function get_health() {
		return array(
			'last_tick' => null,
			'age'       => null,
			'healthy'   => (bool) wp_next_scheduled( self::TICK_HOOK ),
			'next_scheduled' => wp_next_scheduled( self::TICK_HOOK ),
		);
	}

	public static function get_status() {
		$interval = (int) STI_Settings::get( 'queue_interval_minutes', 30 );
		$mode = STI_Settings::get( 'queue_mode', 'fixed' );
		$last = (int) get_option( self::LAST_PUBLISHED_OPTION, 0 );
		$count = STI_Session::count_queued();
		return array(
			'running' => self::is_running(),
			'mode' => $mode,
			'interval_minutes' => $interval,
			'smart_min' => (int) STI_Settings::get( 'queue_smart_min_minutes', 8 ),
			'smart_max' => (int) STI_Settings::get( 'queue_smart_max_minutes', 45 ),
			'queued_count' => $count,
			'queued_items' => STI_Session::get_queue_list( 100 ),
			'last_published_at' => $last,
			'next_publish_at' => $count ? ( $last ? $last + self::interval_seconds() : time() + self::interval_seconds() ) : null,
			'health' => self::get_health(),
		);
	}

	/**
	 * انتشار فوری همه یا بخشی از صف بر اساس فیلتر دسته.
	 *
	 * @param int $category_id 0 = همه
	 * @param int $limit حداکثر تعداد
	 * @return array{published:int, failed:int}
	 */
	public static function publish_batch_now( $category_id = 0, $limit = 20 ) {
		$items = STI_Session::get_queue_list( 500 );
		$published = 0;
		$failed = 0;
		$limit = max( 1, min( 50, (int) $limit ) );
		$sched = self::instance();

		foreach ( $items as $item ) {
			if ( $published + $failed >= $limit ) {
				break;
			}
			if ( $category_id && (int) $item->category_id !== (int) $category_id ) {
				continue;
			}
			$result = $sched->publish_now( $item->id );
			if ( is_wp_error( $result ) ) {
				$failed++;
			} else {
				$published++;
			}
			// فاصله کوتاه بین انتشارهای فوری برای فشار کمتر به سرور
			if ( $published + $failed < $limit ) {
				usleep( 300000 );
			}
		}
		return array( 'published' => $published, 'failed' => $failed );
	}
}
