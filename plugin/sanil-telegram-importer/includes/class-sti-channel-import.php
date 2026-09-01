<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI Channel Import — دریافت تاریخچه‌ی پیام‌ها از کانال/گروه تلگرام
 *
 * این کلاس وظیفه‌ی import دسته‌جمعی پیام‌های قدیمی/موجود را بر عهده دارد.
 * سه استراتژی به ترتیب اولویت امتحان می‌شوند:
 *
 * Strategy A — Web Scraping (کانال‌های عمومی):
 *   با fetch کردن t.me/s/ChannelUsername/MessageID و پارس HTML استاتیک
 *   (og:description, og:image, background-image CSS و ...)
 *   پیام‌ها استخراج می‌شوند. مناسب کانال‌هایی که @username عمومی دارند.
 *
 * Strategy B — Bot API getUpdates (اگر بات عضو باشد):
 *   اگر بات已经在 گروه/کانال عضو باشد و privacy disabled شده باشد،
 *   با getUpdates تلاش می‌کند پیام‌ها را دریافت کند. محدود به پیام‌های بعد از عضویت بات.
 *
 * Strategy C — Manual Forward Mode (همیشه کار می‌کند):
 *   کاربر پیام‌ها را دستی از کانال به بات forward می‌کند.
 *   سیستم پیام‌های forwarded را پردازش کرده و با File Code تطبیق می‌دهد.
 *
 * Class STI_Channel_Import
 */
class STI_Channel_Import {

	/**
	 * @var self
	 */
	protected static $instance;

	/**
	 * کلید wp_options برای ذخیره‌ی batch های import.
	 */
	const BATCH_OPTION_KEY = 'sti_channel_import_batches';

	/**
	 * حداکثر تعداد batch های هم‌زمان.
	 */
	const MAX_ACTIVE_BATCHES = 5;

	/**
	 * حداکثر تعداد پیام‌های قابل import در یک batch.
	 */
	const MAX_BATCH_SIZE = 500;

	/**
	 * حداکثر پیام‌های قابل اسکن برای import_unique.
	 */
	const MAX_SCAN = 200;

	/**
	 * شناسه‌ی استراتژی اکانت شخصی (MTProto).
	 */
	const STRATEGY_MT = 'mtproto';

	/**
	 * تعداد اسکن در هر «چانک» پردازش پس‌زمینه (استراتژی اسکرپینگ).
	 * هر چانک در یک اجرای WP-Cron جدا انجام می‌شود تا PHP timeout نشود.
	 */
	const CHUNK_SCAN_LIMIT = 10;

	/**
	 * تعداد پیام دریافتی از MTProto در هر چانک.
	 */
	const MT_CHUNK_LIMIT = 20;

	/**
	 * حداکثر زمان انتظار پیش‌فرض (ثانیه) — مقدار واقعی از تنظیمات ci_fetch_timeout_minutes.
	 */
	const MT_FETCH_TIMEOUT = 300;

	/** مدت انتظار دریافت فایل از ربات (ثانیه) — قابل تنظیم در پنل واردات از کانال. */
	public static function fetch_timeout_seconds() {
		$minutes = (int) STI_Settings::get( 'ci_fetch_timeout_minutes', 5 );
		if ( $minutes < 1 ) { $minutes = 5; }
		if ( $minutes > 60 ) { $minutes = 60; }
		return $minutes * 60;
	}

	/** حداکثر تعداد فشار دکمه در هر چانک (کمتر = رفتار انسانی‌تر و کمتر FLOOD). */
	const MT_PRESS_PER_CHUNK = 5;

	/** حداکثر تعداد فایل دریافتی از ربات که در هر چانک پردازش می‌شود. */
	const MT_FILES_PER_CHUNK = 10;

	/**
	 * فاصله‌ی زمانی (ثانیه) بین چانک‌ها.
	 */
	const WORKER_INTERVAL = 10;

	/** حداکثر تعداد re-press برای یک آیتم قبل از error نهایی. */
	const MT_MAX_REPRESS = 4;

	/** Search API page size and candidate validation budget. */
	const MT_SEARCH_CHUNK_LIMIT = 50;
	const MT_SEARCH_VALIDATE_LIMIT = 10;

	/**
	 * v7 — هم‌زمان حداکثر همین تعداد فایل در انتظار بماند.
	 * وقتی ۱۰ دکمه پشت‌سرهم زده می‌شد، ربات ۱۰ فایل می‌فرستاد و تطبیق «کدام فایل
	 * برای کدام کد است» مبهم می‌شد. با صف باریک، هر فایل بدون ابهام به کد خودش
	 * می‌چسبد و شانس موفقیت عملاً به سقف می‌رسد.
	 */
	const MT_MAX_CONCURRENT_WAIT = 2;

	/** بعد از این مدت (ثانیه) بدون فایل، مسیر جایگزین امتحان می‌شود. */
	const MT_ESCALATE_AFTER = 75;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — hooks into plugin flows.
	 */
	protected function __construct() {
		/* ── Webhook integration: پردازش پیام forwarded ── */
		add_action( 'sti_webhook_channel_import', array( $this, 'process_forwarded_message' ), 10, 2 );

		/* ── AJAX handlers برای مدیریت از پنل ادمین ── */
		add_action( 'wp_ajax_sti_ci_start_import',    array( $this, 'ajax_start_import' ) );
		add_action( 'wp_ajax_sti_ci_batch_status',    array( $this, 'ajax_get_batch_status' ) );
		add_action( 'wp_ajax_sti_ci_cancel_batch',    array( $this, 'ajax_cancel_batch' ) );
		add_action( 'wp_ajax_sti_ci_all_batches',     array( $this, 'ajax_get_all_batches' ) );
		add_action( 'wp_ajax_sti_ci_poll',            array( $this, 'ajax_poll' ) );
		add_action( 'wp_ajax_sti_ci_process_now',     array( $this, 'ajax_process_now' ) );
		add_action( 'wp_ajax_sti_ci_test_connection', array( $this, 'ajax_test_connection' ) );

		/* ── پردازش پس‌زمینه (WP-Cron) — هر batch جداگانه پردازش می‌شود ── */
		add_action( 'sti_ci_worker', array( $this, 'process_batch_chunk' ), 10, 1 );
	}

	/* ======================================================================
	   SECTION 1: CORE IMPORT FLOW
	   ====================================================================== */

	/**
	 * ورودی اصلی: import تعداد مشخصی پیام از یک کانال.
	 * استراتژی مناسب را به صورت خودکار تشخیص می‌دهد (یا اجباری از پارامتر strategy):
	 *
	 *   mtproto_search — جست‌وجوی سروری با اکانت شخصی + ایندکس پایدار (اولویت پیشنهادی).
	 *   mtproto — اکانت شخصی تلگرام با اسکن ترتیبی تاریخچه (fallback).
	 *   scrape  — اسکرپینگ t.me/s (فقط کانال عمومی).
	 *   bot_api — فقط پیام‌های بعد از عضویت بات.
	 *   manual  — کاربر دستی forward می‌کند (همیشه آخرین راه).
	 *
	 * در حالت auto: اگر اکانت شخصی آماده باشد ابتدا mtproto_search اجرا می‌شود؛
	 * در غیر این صورت کانال عمومی با scrape و سپس bot_api/manual امتحان می‌شود.
	 *
	 * @param string $chat_username  username کانال، لینک t.me (با یا بدون /MessageID) یا لینک دعوت t.me/+xxx.
	 * @param int    $topic_id       شناسه‌ی topic (پست شروع).
	 * @param int    $count          تعداد پیام موردنیاز.
	 * @param int    $category_id    شناسه‌ی دسته‌بندی ووکامرس.
	 * @param string $label          برچسب فارسی برای batch.
	 * @param string $strategy       auto | mtproto_search | mtproto | scrape | bot_api | manual
	 * @return array|false  نتیجه‌ی import یا false.
	 */
	public function import_messages( $chat_username, $topic_id, $count, $category_id, $label = '', $strategy = 'auto' ) {
		$strategy = sanitize_key( (string) $strategy );
		if ( ! in_array( $strategy, array( 'auto', 'mtproto_search', self::STRATEGY_MT, 'scrape', 'bot_api', 'manual' ), true ) ) {
			$strategy = 'auto';
		}

		$count    = max( 1, min( (int) $count, self::MAX_BATCH_SIZE ) );
		$topic_id = $topic_id ? (int) $topic_id : 0;
		$category_id = (int) $category_id;

		// پارس ورودی: لینک کامل t.me/User/123 یا t.me/+hash یا @username
		$parsed = $this->parse_chat_identifier( $chat_username );
		$username = $parsed['username'];
		if ( ! $topic_id && $parsed['message_id'] ) {
			$topic_id = (int) $parsed['message_id'];
		}

		if ( ! $username && ! $parsed['is_join_link'] ) {
			STI_Logger::error( 'Channel Import: نام کاربری کانال نامعتبر است — ' . $chat_username );
			return false;
		}

		STI_Logger::info( 'Channel Import: شروع import — target=' . ( $parsed['is_join_link'] ? 'لینک دعوت' : '@' . $username ) . ', topic_id=' . $topic_id . ', count=' . $count . ', strategy=' . $strategy );

		$mt_ready = STI_MTProto::is_configured() && 'logged_in' === STI_MTProto::instance()->auth_state();

		/* ── استراتژی جدید: جست‌وجوی سروری + ایندکس پایدار ── */
		if ( 'mtproto_search' === $strategy ) {
			if ( ! STI_MTProto::is_configured() ) {
				return $this->strategy_error( 'برای جست‌وجوی تاریخچه و دکمه‌های کانال، اکانت شخصی MTProto باید تنظیم شود.' );
			}
			if ( ! $mt_ready ) {
				return $this->strategy_error( 'اکانت MTProto تنظیم است ولی ورود کامل نشده است.' );
			}
			return $this->start_mtproto_search_batch( $username, $topic_id, $count, $category_id, $label, $parsed );
		}

		/* ── استراتژی صریح: MTProto (اکانت شخصی) ── */
		if ( self::STRATEGY_MT === $strategy ) {
			if ( ! STI_MTProto::is_configured() ) {
				return $this->strategy_error( 'اکانت شخصی تلگرام (api_id / api_hash / شماره) در «تنظیمات تلگرام» تنظیم نشده است.' );
			}
			if ( ! $mt_ready ) {
				return $this->strategy_error( 'اکانت شخصی تنظیم است ولی ورود انجام نشده. از «تنظیمات تلگرام» کد ورود را وارد کنید.' );
			}
			return $this->start_mtproto_batch( $username, $topic_id, $count, $category_id, $label, $parsed );
		}

		/* ── حالت خودکار: Search را قبل از هر HTTP/Web Scrape اجرا کن ── */
		if ( 'auto' === $strategy && $mt_ready && $category_id && STI_Settings::get( 'ci_search_enabled', 1 ) ) {
			return $this->start_mtproto_search_batch( $username, $topic_id, $count, $category_id, $label, $parsed );
		}

		/* ── حالت auto: تعیین عمومی/خصوصی بودن کانال ── */
		if ( 'auto' === $strategy && ! $parsed['is_join_link'] ) {
			$web = $this->get_channel_web_info( $username );
			if ( $web && ! $web['public'] && $mt_ready ) {
				if ( STI_Settings::get( 'ci_search_enabled', 1 ) ) {
					STI_Logger::info( 'Channel Import: کانال خصوصی است — جست‌وجوی سروری با اکانت شخصی (MTProto) — @' . $username );
					return $this->start_mtproto_search_batch( $username, $topic_id, $count, $category_id, $label, $parsed );
				}
				return $this->start_mtproto_batch( $username, $topic_id, $count, $category_id, $label, $parsed );
			}
		}
		// لینک دعوت = کانال خصوصی است؛ فقط MTProto می‌تواند.
		if ( 'auto' === $strategy && $parsed['is_join_link'] ) {
			if ( $mt_ready ) {
				return STI_Settings::get( 'ci_search_enabled', 1 )
					? $this->start_mtproto_search_batch( $username, $topic_id, $count, $category_id, $label, $parsed )
					: $this->start_mtproto_batch( $username, $topic_id, $count, $category_id, $label, $parsed );
			}
			return $this->strategy_error( 'این لینک دعوت یک کانال خصوصی است؛ برای واردات باید اکانت شخصی تلگرام را در «تنظیمات تلگرام» فعال و وارد شوید.' );
		}

		/* ── Strategy A: Web Scraping (کانال‌های عمومی) ── */
		if ( in_array( $strategy, array( 'auto', 'scrape', 'bot_api', 'manual' ), true ) ) {
			$test_url = 'https://t.me/s/' . $username;
			if ( $topic_id ) {
				$test_url .= '/' . $topic_id;
			}

			$test_response = self::human_http_get( $test_url, 2 );

			if ( $test_response && ! empty( $test_response['body'] ) ) {
				$html = $test_response['body'];

				// بررسی وجود محتوای واقعی قابل scrape:
				// (توجه: og:description و og:image را نباید ملاک گرفت — کانال‌های خصوصی هم دارند!)
				if ( false !== strpos( $html, 'tgme_widget_message' ) &&
				     false !== strpos( $html, 'data-post' ) ) {

					STI_Logger::info( 'Channel Import: Strategy A (Web Scraping) فعال شد — @' . $username );

					return $this->import_unique( $username, $topic_id, $count, $category_id, $label, self::MAX_SCAN );
				}

				STI_Logger::info( 'Channel Import: کانال پیام عمومی ندارد (خصوصی یا بسته) — @' . $username . ' — HTTP ' . ( $test_response['http_code'] ?? '?' ) );
			}
		}

		/* ── حالت auto: اگر خصوصی بود و اکانت شخصی در دسترس است، برو سراغش ── */
		if ( 'auto' === $strategy && $mt_ready ) {
			STI_Logger::info( 'Channel Import: fallback به اکانت شخصی (MTProto) — @' . $username );
			return $this->start_mtproto_batch( $username, $topic_id, $count, $category_id, $label, $parsed );
		}

		/* ── Strategy B: Bot API getUpdates ── */
		if ( in_array( $strategy, array( 'auto', 'bot_api' ), true ) ) {
			$bot_result = $this->try_bot_api_import( $username, $count );
			if ( $bot_result && ! empty( $bot_result['imported'] ) ) {
				STI_Logger::info( 'Channel Import: Strategy B (Bot API) فعال شد — @' . $username );
				return $bot_result;
			}
		}

		/* ── Strategy C: Manual Forward Mode ── */
		STI_Logger::info( 'Channel Import: Strategy C (Manual Forward) فعال شد — @' . $username );

		return $this->start_manual_forward_batch( $chat_username, $count, $category_id, $topic_id, $label );
	}

	/* ======================================================================
	   SECTION 2: STRATEGY A — WEB SCRAPING
	   ====================================================================== */

	/**
	 * Scrape یک پیام از t.me/s/ChannelUsername/MessageID.
	 *
	 * @param string $username   نام کاربری کانال (بدون @).
	 * @param int    $message_id شناسه‌ی پیام.
	 * @return array|false  داده‌های استخراج‌شده یا false.
	 */
	public function scrape_message( $username, $message_id ) {
		$urls = array(
			'https://t.me/s/' . $username . '/' . $message_id,
			'https://t.me/' . $username . '/' . $message_id . '?embed=1',
			'https://t.me/' . $username . '/' . $message_id . '?embed=1&mode=compact',
		);

		$html = null;
		$used_url = '';

		foreach ( $urls as $url ) {
			$response = self::human_http_get( $url, 2 );
			if ( $response && ! empty( $response['body'] ) && strlen( $response['body'] ) > 500 ) {
				$html = $response['body'];
				$used_url = $url;
				break;
			}
			// تأخیر کوتاه قبل از URL بعدی
			usleep( 300000 );
		}

		if ( ! $html ) {
			STI_Logger::warning( 'Channel Import: scrape ناموفق — نمی‌توان HTML دریافت کرد — @' . $username . '/' . $message_id );
			return false;
		}

		$data = $this->parse_message_html( $html, $username, $message_id );

		if ( ! empty( $data ) ) {
			STI_Logger::info( 'Channel Import: scrape موفق — @' . $username . '/' . $message_id . ' — url=' . $used_url );
		}

		return $data;
	}

	/**
	 * Scrape تعدادی پیام از یک کانال، شروع از start_id و حرکت به سمت پایین.
	 *
	 * @param string $username نام کاربری کانال.
	 * @param int    $start_id شناسه‌ی شروع.
	 * @param int    $count    تعداد پیام.
	 * @return array  آرایه‌ای از داده‌های scrape شده برای هر message_id.
	 */
	public function scrape_messages( $username, $start_id, $count ) {
		$results = array();
		$test_id = (int) $start_id;
		$found   = 0;
		$target  = (int) $count;
		$max_attempts = $target * 3;

		STI_Logger::info( 'Channel Import: شروع scrape ' . $target . ' پیام — @' . $username . ' از ID=' . $test_id );

		for ( $attempt = 0; $attempt < $max_attempts && $found < $target && $test_id > 0; $attempt++ ) {

			$data = $this->scrape_message( $username, $test_id );

			if ( $data ) {
				$results[ $test_id ] = $data;
				$found++;

				STI_Logger::info( 'Channel Import: scrape progress — ' . $found . '/' . $target . ' — message_id=' . $test_id );
			}

			$test_id--;

			// تأخیر تصادفی بین هر درخواست
			if ( $found < $target && $test_id > 0 ) {
				self::random_delay( 0.5, 1.5 );

				// گاهی اوقات تأخیر بیشتر (شبیه‌سازی رفتار انسانی)
				if ( wp_rand( 1, 10 ) === 1 ) {
					STI_Logger::info( 'Channel Import: تأخیر طولانی (شبیه‌سازی رفتار انسانی)...' );
					self::random_delay( 3.0, 7.0 );
				}
			}
		}

		STI_Logger::success( 'Channel Import: scrape کامل شد — ' . $found . '/' . $target . ' پیام — @' . $username );

		return $results;
	}

	/* ======================================================================
	   SECTION 3: STRATEGY B — BOT API
	   ====================================================================== */

	/**
	 * تلاش برای import از طریق Bot API getUpdates.
	 * این روش فقط برای پیام‌هایی کار می‌کند که بعد از عضویت بات ارسال شده باشند.
	 *
	 * @param string $username  نام کاربری کانال.
	 * @param int    $count     تعداد پیام.
	 * @return array|false  نتیجه یا false.
	 */
	public function try_bot_api_import( $username, $count ) {
		$api = new STI_Telegram_API();

		// ابتدا chat_id را از username حل کن
		$chat = $api->call( 'getChat', array( 'chat_id' => '@' . $username ) );

		if ( ! $chat || empty( $chat['id'] ) ) {
			STI_Logger::info( 'Channel Import: Strategy B غیرقابل استفاده — getChat ناموفق برای @' . $username );
			return false;
		}

		$chat_id = $chat['id'];

		// بررسی اینکه آیا بات عضو است و آپدیت دریافت می‌کند
		$updates = $api->call( 'getUpdates', array(
			'timeout' => 2,
			'limit'   => 5,
		) );

		if ( ! $updates || ! is_array( $updates ) ) {
			STI_Logger::info( 'Channel Import: Strategy B غیرقابل استفاده — getUpdates ناموفق' );
			return false;
		}

		// بررسی اینکه آیا هیچ آپدیتی از این chat_id هست
		$has_channel_updates = false;
		foreach ( $updates as $update ) {
			$channel_post = $update['channel_post'] ?? $update['message'] ?? array();
			$update_chat_id = $channel_post['chat']['id'] ?? 0;
			if ( (int) $update_chat_id === (int) $chat_id ) {
				$has_channel_updates = true;
				break;
			}
		}

		if ( ! $has_channel_updates ) {
			STI_Logger::info( 'Channel Import: Strategy B غیرقابل استفاده — هیچ آپدیتی از @' . $username . ' دریافت نمی‌شود. بات باید عضو باشد و privacy disabled شود.' );
			return false;
		}

		// جمع‌آوری پیام‌ها از getUpdates
		$messages = array();
		$collected = 0;
		$max_pages = 10;
		$offset = 0;

		for ( $page = 0; $page < $max_pages && $collected < $count; $page++ ) {
			$params = array(
				'timeout' => 5,
				'limit'   => min( 100, ( $count - $collected ) + 10 ),
			);
			if ( $offset > 0 ) {
				$params['offset'] = $offset;
			}

			$updates = $api->call( 'getUpdates', $params );

			if ( ! $updates || ! is_array( $updates ) ) {
				break;
			}

			foreach ( $updates as $update ) {
				$offset = max( $offset, (int) $update['update_id'] + 1 );

				$channel_post = $update['channel_post'] ?? $update['message'] ?? array();
				$update_chat_id = $channel_post['chat']['id'] ?? 0;

				if ( (int) $update_chat_id !== (int) $chat_id ) {
					continue;
				}

				$messages[] = $channel_post;
				$collected++;

				if ( $collected >= $count ) {
					break;
				}
			}

			if ( count( $updates ) < ( $params['limit'] - 5 ) ) {
				break;
			}

			usleep( 300000 );
		}

		STI_Logger::info( 'Channel Import: Strategy B — ' . $collected . ' پیام از getUpdates دریافت شد.' );

		return array(
			'strategy'  => 'bot_api',
			'chat_id'   => $chat_id,
			'messages'  => $messages,
			'imported'  => $collected,
			'total'     => $count,
		);
	}

	/* ======================================================================
	   SECTION 4: STRATEGY C — MANUAL FORWARD MODE
	   ====================================================================== */

	/**
	 * شروع یک batch از نوع manual forward.
	 * دستورالعمل‌های لازم را به کاربر نمایش می‌دهد.
	 *
	 * @param string $chat_username  @username کانال.
	 * @param int    $count          تعداد پیام.
	 * @param int    $category_id    دسته‌بندی.
	 * @param int    $topic_id       شناسه‌ی topic.
	 * @param string $label          برچسب.
	 * @return array  داده‌های batch ایجادشده.
	 */
	public function start_manual_forward_batch( $chat_username, $count, $category_id, $topic_id, $label ) {
		$username    = ltrim( $chat_username, '@' );
		$count       = max( 1, min( (int) $count, self::MAX_BATCH_SIZE ) );
		$topic_id    = $topic_id ? (int) $topic_id : 0;
		$category_id = (int) $category_id;

		// تلاش برای حل chat_id از username (برای تطبیق بهتر)
		$chat_id = 0;
		$api = new STI_Telegram_API();
		$chat = $api->call( 'getChat', array( 'chat_id' => '@' . $username ) );
		if ( $chat && ! empty( $chat['id'] ) ) {
			$chat_id = (int) $chat['id'];
		}

		$batch_id = 'b_' . time() . '_' . wp_rand( 1000, 9999 );
		$now = current_time( 'mysql' );

		$batch = array(
			'id'                => $batch_id,
			'username'          => $username,
			'chat_id'           => $chat_id,
			'topic_id'          => $topic_id,
			'category_id'       => $category_id,
			'label'             => $label ? sanitize_text_field( $label ) : 'Manual Import ' . date( 'Y-m-d H:i' ),
			'strategy'          => 'manual',
			'desired_count'     => $count,
			'total_scanned'     => 0,
			'imported'          => 0,
			'duplicates_skipped'=> 0,
			'status'            => 'awaiting_forward',
			'message_results'   => array(),
			'instructions'      => array(
				'step_1' => sprintf(
					'%d پیام از کانال @%s را به ربات forward کنید.',
					$count,
					$username
				),
				'step_2' => 'فایل‌های مربوطه را از @FileechBot دریافت و به ربات forward کنید.',
				'step_3' => 'سیستم به‌صورت خودکار پیام‌ها را با File Code تطبیق داده و محصول ایجاد می‌کند.',
				'note'   => 'هر پیام باید دارای File Code در کپشن باشد. پیام‌های بدون File Code نادیده گرفته می‌شوند.',
			),
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$batches = $this->get_batches();
		$batches[ $batch_id ] = $batch;
		update_option( self::BATCH_OPTION_KEY, $batches, false );

		STI_Logger::info( 'Channel Import: Manual Forward batch ایجاد شد — id=' . $batch_id . ', username=@' . $username . ', count=' . $count );

		return $batch;
	}

	/* ======================================================================
	   SECTION 5: DUPLICATE DETECTION
	   ====================================================================== */

	/**
	 * بررسی وجود محصول با File Code مشخص (از طریق SKU).
	 *
	 * @param string $file_code
	 * @return bool  true اگر محصول تکراری باشد.
	 */
	public function is_duplicate( $file_code ) {
		if ( empty( $file_code ) ) {
			return false;
		}

		$file_code = trim( (string) $file_code );
		$canonical_code = class_exists( 'STI_Channel_Index' ) ? STI_Channel_Index::normalize_code( $file_code ) : sanitize_title( $file_code );
		if ( '' === $canonical_code ) { return false; }

		/* ── ۱) آیا همین الان در صف/در حال پردازش است؟ (قبل از این‌که محصولی ساخته شود) ──
		 * این جلوی انتخاب دوباره‌ی فایلی را می‌گیرد که در یک batch دیگر (یا همین batch،
		 * پیام تکراری در کانال) قبلاً session باز/در حال ساخت/در صف انتشار دارد. */
		if ( class_exists( 'STI_Session' ) ) {
			$active = STI_Session::get_active_by_file_code( $file_code );
			if ( ! $active && $canonical_code !== $file_code ) { $active = STI_Session::get_active_by_file_code( $canonical_code ); }
			if ( $active ) {
				STI_Logger::info( 'Channel Import: فایل در صف/در حال پردازش است — file_code=' . $file_code . ', session_id=' . $active->id . ', status=' . $active->status );
				return true;
			}
		}

		/* ── ۲) محصول منتشرشده / پیش‌نویس / خصوصی با SKU استاندارد ── */
		$sku = 'STI-' . sanitize_title( $canonical_code );
		$product_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $sku ) : 0;
		if ( ! $product_id && $canonical_code !== $file_code && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id = wc_get_product_id_by_sku( 'STI-' . $file_code );
		}

		if ( $product_id ) {
			$post_status = get_post_status( $product_id );
			// trash را تکراری حساب نکن تا بتوان دوباره وارد کرد
			if ( 'trash' !== $post_status ) {
				STI_Logger::info( 'Channel Import: محصول تکراری یافت شد — SKU=' . $sku . ', product_id=' . $product_id . ', status=' . $post_status );
				return true;
			}
		}

		/* ── ۳) جستجوی اضافی با meta _sti_file_code یا _sku جایگزین (محصولات قدیمی) ── */
		global $wpdb;
		$meta_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key IN ('_sti_file_code', 'sti_file_code', '_sku')
			   AND (pm.meta_value = %s OR pm.meta_value = %s OR pm.meta_value = %s)
			   AND p.post_type = 'product'
			   AND p.post_status NOT IN ('trash','auto-draft')
			 LIMIT 1",
			$file_code,
			$canonical_code,
			$sku
		) );
		if ( $meta_id ) {
			STI_Logger::info( 'Channel Import: محصول تکراری از طریق meta یافت شد — file_code=' . $file_code . ', product_id=' . $meta_id );
			return true;
		}

		return false;
	}

	/**
	 * بررسی سخت‌گیرانه‌ی تطبیق دسته‌ی انتخاب‌شده با تشخیص اتوکت — منطق مشترک برای
	 * تمام استراتژی‌ها (MTProto / Scrape / Manual Forward).
	 *
	 * این متد همان منطقی است که قبلاً فقط داخل mt_stage_collect() بود؛ اکنون به یک
	 * متد مشترک تبدیل شده تا هر مسیر importی (اسکرپ، فوروارد دستی، MTProto) دقیقاً
	 * همان قانون را اجرا کند و پیامی که اتوکت با آن مخالف است، هرگز برای گرفتن فایل
	 * به ربات ارسال نشود.
	 *
	 * @param string $caption_text  متن کامل کپشن/عنوان (برای تشخیص دسته اصلی).
	 * @param string $type_text     نوع فایل + نام فایل (برای تشخیص فرمت).
	 * @param int    $category_id   دسته‌ی انتخاب‌شده توسط کاربر.
	 * @return array {
	 *     @type bool   allowed      آیا اجازه‌ی ادامه (ارسال به ربات) دارد؟
	 *     @type int    category_id دسته‌ی نهایی که باید روی session ست شود.
	 *     @type string reason       دلیل رد شدن (وقتی allowed=false).
	 * }
	 */
	/**
	 * پیدا کردن امتیاز یک دسته‌ی مشخص (با اسلاگ نرمال‌شده) در آرایه‌ی all_scores
	 * که STI_AutoCat::detect() برمی‌گرداند. اگر دسته اصلاً در دیکشنری اتوکت
	 * تعریف نشده باشد (دسته‌ی کاملاً سفارشی کاربر)، null برمی‌گرداند.
	 *
	 * @param array  $all_scores  خروجی $autocat_result['all_scores'].
	 * @param string $selected_norm  اسلاگ نرمال‌شده‌ی دسته‌ی انتخابی.
	 * @return int|null
	 */
	protected static function find_score_for_slug( $all_scores, $selected_norm ) {
		if ( empty( $all_scores ) || ! $selected_norm ) {
			return null;
		}
		foreach ( $all_scores as $entry ) {
			$entry_slug = sanitize_title( $entry['slug'] ?? '' );
			if ( ! $entry_slug ) {
				continue;
			}
			if ( $entry_slug === $selected_norm
				|| false !== mb_strpos( $entry_slug, $selected_norm )
				|| false !== mb_strpos( $selected_norm, $entry_slug ) ) {
				return (int) ( $entry['score'] ?? 0 );
			}
		}
		return null;
	}

	public function evaluate_autocat_match( $caption_text, $type_text, $category_id ) {
		$category_id = (int) $category_id;

		if ( ! $category_id ) {
			// نباید اتفاق بیفتد چون دسته الزامی است، ولی fallback امن.
			return array( 'allowed' => true, 'category_id' => 0, 'reason' => '' );
		}

		/* حداقل امتیاز دسته‌ی انتخابی برای قبول شدن (قابل تنظیم در تنظیمات) */
		$min_score = (int) STI_Settings::get( 'autocat_min_score', 100 );
		if ( $min_score < 1 ) {
			$min_score = 100;
		}

		$autocat_result    = null;
		$autocat_main_slug = null;
		$autocat_main_id   = 0;
		$autocat_conf      = 0;
		$autocat_format    = null;
		$all_scores        = array();

		if ( class_exists( 'STI_AutoCat' ) ) {
			try {
				$autocat_result    = STI_AutoCat::detect( $caption_text, $type_text );
				$autocat_main_slug = $autocat_result['main_category'] ?? null;
				$autocat_conf      = (int) ( $autocat_result['confidence'] ?? 0 );
				$autocat_format    = $autocat_result['format_category'] ?? null;
				$all_scores        = $autocat_result['all_scores'] ?? array();
				if ( $autocat_main_slug ) {
					$autocat_main_id = STI_AutoCat::map_slug_to_wc_category_id( $autocat_main_slug );
				}
			} catch ( \Throwable $e ) {
				STI_Logger::warning( 'AutoCat detect failed: ' . $e->getMessage() );
			}
		}

		// fallback به متد قدیمی اگر اتوکت چیزی پیدا نکرد
		if ( ! $autocat_main_id && method_exists( $this, 'detect_category' ) ) {
			$fallback_detected = $this->detect_category( $caption_text, $type_text );
			if ( $fallback_detected ) {
				$autocat_main_id   = (int) $fallback_detected;
				$autocat_main_slug = 'fallback-' . $fallback_detected;
				$autocat_conf      = 50;
			}
		}

		$selected_cat_obj = STI_Category::get( $category_id );
		$selected_slug    = '';
		$selected_label   = '';
		if ( $selected_cat_obj ) {
			$selected_label = (string) ( $selected_cat_obj->telegram_label ?? '' );
			$selected_slug  = sanitize_title( $selected_cat_obj->folder_key ?: $selected_label );
			// اگر folder_key خالی/فارسی بود و sanitize چیزی نداد، از label خام هم استفاده کن
			if ( '' === $selected_slug && $selected_label ) {
				$selected_slug = sanitize_title( $selected_label );
			}
		}

		/* تلاش برای نگاشت دسته‌ی کاربر به اسلاگ شناخته‌شده‌ی اتوکت
		 * (مثلاً اگر دسته «موکاپ» یا «Mockup» باشد → mockup) */
		$selected_autocat_slug = $this->resolve_selected_to_autocat_slug( $selected_cat_obj, $all_scores );

		if ( $autocat_result ) {
			STI_Logger::info( sprintf(
				'AutoCat check: title="%s" → main=%s (%s%%) format=%s | selected=%s (resolved=%s) min_score=%d',
				mb_substr( $caption_text, 0, 60 ),
				$autocat_main_slug ?: '—',
				$autocat_conf,
				$autocat_format ?: '—',
				$selected_slug ?: '—',
				$selected_autocat_slug ?: '—',
				$min_score
			) );
		}

		/* ── قانون طلایی ۱: امتیاز دسته‌ی انتخابی باید >= حداقل باشد ── */
		$lookup_slug     = $selected_autocat_slug ?: $selected_slug;
		$selected_score  = self::find_score_for_slug( $all_scores, $lookup_slug );
		// اگر با slug اصلی پیدا نشد، با slug رزولوشن‌شده دوباره امتحان کن
		if ( null === $selected_score && $selected_autocat_slug && $selected_autocat_slug !== $selected_slug ) {
			$selected_score = self::find_score_for_slug( $all_scores, $selected_autocat_slug );
		}

		// اگر دسته در دیکشنری اتوکت تعریف شده (score پیدا شد) و کمتر از حداقل است → رد
		if ( null !== $selected_score && $selected_score < $min_score ) {
			return array(
				'allowed'     => false,
				'category_id' => $category_id,
				'reason'      => sprintf(
					'اتوکت: امتیاز دسته‌ی «%s» برای این عنوان فقط %d است (حداقل لازم %d) — رد شد. امتیازهای دیگر: %s',
					$lookup_slug,
					$selected_score,
					$min_score,
					$this->format_top_scores( $all_scores, 5 )
				),
			);
		}

		/* ── اگر اتوکت هیچ دسته‌ای پیدا نکرد ── */
		if ( ! $autocat_main_slug ) {
			if ( null !== $selected_score && $selected_score < $min_score ) {
				return array(
					'allowed'     => false,
					'category_id' => $category_id,
					'reason'      => "اتوکت هیچ کلمه‌ی مرتبط با دسته‌ی «{$lookup_slug}» پیدا نکرد (امتیاز {$selected_score}) — رد شد",
				);
			}
			// دسته‌ی کاملاً سفارشی خارج از دیکشنری — اجازه بده
			return array( 'allowed' => true, 'category_id' => $category_id, 'reason' => '' );
		}

		$autocat_norm  = sanitize_title( $autocat_main_slug );
		$selected_norm = $lookup_slug ? sanitize_title( $lookup_slug ) : $selected_slug;

		/* ── بررسی ناسازگاری فرمت ── */
		$is_selected_format = false;
		$format_keywords    = array( 'vector', 'psd', 'photo', 'png', 'motion', 'video', '3d', 'font', 'icon' );
		if ( $selected_cat_obj ) {
			$sel_label = mb_strtolower( $selected_label . ' ' . ( $selected_cat_obj->folder_key ?? '' ) );
			foreach ( $format_keywords as $fk ) {
				if ( false !== mb_strpos( $sel_label, $fk ) ) {
					$is_selected_format = true;
					break;
				}
			}
		}

		if ( $is_selected_format ) {
			if ( $autocat_format ) {
				if ( 'vector' === $selected_norm && in_array( $autocat_format, array( 'motion', 'video', '3d' ), true ) ) {
					return array(
						'allowed'     => false,
						'category_id' => $category_id,
						'reason'      => "اتوکت: فرمت {$autocat_format} با دسته وکتور ناسازگار — رد شد",
					);
				}
				if ( $autocat_conf >= 60 && $autocat_format !== $selected_norm && $autocat_format !== str_replace( '-format', '', $selected_norm ) ) {
					if ( in_array( $selected_norm, array( 'vector', 'psd', 'photo' ), true ) && in_array( $autocat_format, array( 'motion', 'video' ), true ) ) {
						return array(
							'allowed'     => false,
							'category_id' => $category_id,
							'reason'      => "اتوکت: فرمت {$autocat_format} با {$selected_norm} ناسازگار — رد شد",
						);
					}
				}
			}
			// برای دسته‌های فرمتی، اگر امتیاز کافی داشت قبول کن
			return array( 'allowed' => true, 'category_id' => $category_id, 'reason' => '' );
		}

		/* ── قانون طلایی ۲: دسته‌ی اصلی تشخیص‌داده‌شده باید با انتخاب کاربر یکی باشد ──
		 * (حتی اگر امتیاز دسته‌ی انتخابی مثبت باشد، اگر اتوکت با اطمینان بالا
		 * دسته‌ی دیگری را برنده اعلام کرده، رد کن — مثال: mockup=0 / background=200) */
		$is_match = false;
		if ( $selected_norm ) {
			$is_match = ( $autocat_norm === $selected_norm )
				|| ( false !== mb_strpos( $autocat_norm, $selected_norm ) )
				|| ( false !== mb_strpos( $selected_norm, $autocat_norm ) );
		}
		// مقایسه با اسلاگ رزولوشن‌شده
		if ( ! $is_match && $selected_autocat_slug ) {
			$res_norm = sanitize_title( $selected_autocat_slug );
			$is_match = ( $autocat_norm === $res_norm )
				|| ( false !== mb_strpos( $autocat_norm, $res_norm ) )
				|| ( false !== mb_strpos( $res_norm, $autocat_norm ) );
		}

		if ( ! $is_match ) {
			// اگر conf پایین است و selected_score به اندازه‌ی کافی بالاست، شاید بپذیریم
			// ولی طبق درخواست کاربر: فقط وقتی main == selected قبول شود.
			return array(
				'allowed'     => false,
				'category_id' => $category_id,
				'reason'      => sprintf(
					'اتوکت: «%s» به عنوان %s (%d%%) تشخیص داده شد، نه %s — رد شد. امتیازها: %s',
					mb_substr( $caption_text, 0, 50 ),
					$autocat_main_slug,
					$autocat_conf,
					$selected_norm ?: $lookup_slug,
					$this->format_top_scores( $all_scores, 5 )
				),
			);
		}

		/* ── اگر match بود ولی selected_score هنوز null است (دسته سفارشی نزدیک) ── */
		if ( null === $selected_score ) {
			// match شده، اجازه بده
			return array( 'allowed' => true, 'category_id' => $category_id, 'reason' => '' );
		}

		return array( 'allowed' => true, 'category_id' => $category_id, 'reason' => '' );
	}

	/**
	 * نگاشت دسته‌ی انتخاب‌شده‌ی کاربر به اسلاگ شناخته‌شده‌ی دیکشنری اتوکت.
	 * مثال: label/folder_key حاوی «mockup» یا «موکاپ» → mockup
	 *
	 * @param object|null $cat_obj
	 * @param array       $all_scores
	 * @return string|null
	 */
	protected function resolve_selected_to_autocat_slug( $cat_obj, $all_scores = array() ) {
		if ( ! $cat_obj ) {
			return null;
		}
		$candidates = array();
		$folder = mb_strtolower( (string) ( $cat_obj->folder_key ?? '' ) );
		$label  = mb_strtolower( (string) ( $cat_obj->telegram_label ?? '' ) );
		$combined = $folder . ' ' . $label;

		// اسلاگ‌های شناخته‌شده‌ی اتوکت (از تعریف هاردکد)
		$known = array(
			'mockup', 'logo', 'business-card', 'businesscard', 'text-effect', 'texteffect',
			'flyer', 'brochure', 'poster', 'banner', 'infographic', 'template',
			'png', 'transparent', 'isolated', 'background', 'texture', 'pattern',
			'typography', 'sticker', 'mascot', 'illustration', 'icon', 'flags', 'flag',
			'vector', 'psd', 'photo', 'ui', 'packaging', 'certificate', 'resume', 'cv',
		);

		foreach ( $known as $k ) {
			$k_norm = str_replace( '-', '', $k );
			if ( false !== mb_strpos( $combined, $k )
				|| false !== mb_strpos( $combined, $k_norm )
				|| false !== mb_strpos( sanitize_title( $combined ), $k )
				|| false !== mb_strpos( sanitize_title( $combined ), $k_norm ) ) {
				// نرمال‌سازی به فرم استاندارد دیکشنری
				$map = array(
					'businesscard' => 'business-card',
					'texteffect'   => 'text-effect',
					'flag'         => 'flags',
				);
				return isset( $map[ $k ] ) ? $map[ $k ] : ( str_replace( ' ', '-', $k ) );
			}
		}

		// اگر در all_scores اسلاگی پیدا شد که با label/folder همپوشانی دارد
		foreach ( $all_scores as $entry ) {
			$es = sanitize_title( $entry['slug'] ?? '' );
			if ( ! $es ) {
				continue;
			}
			if ( $folder && ( $es === sanitize_title( $folder ) || false !== mb_strpos( $es, sanitize_title( $folder ) ) ) ) {
				return $es;
			}
			if ( $label && false !== mb_strpos( $es, sanitize_title( $label ) ) ) {
				return $es;
			}
		}

		return $folder ? sanitize_title( $folder ) : null;
	}

	/**
	 * فرمت کوتاه امتیازهای برتر برای پیام خطا.
	 */
	protected function format_top_scores( $all_scores, $limit = 5 ) {
		if ( empty( $all_scores ) || ! is_array( $all_scores ) ) {
			return '—';
		}
		$parts = array();
		$i = 0;
		foreach ( $all_scores as $entry ) {
			if ( $i >= $limit ) {
				break;
			}
			$s = (int) ( $entry['score'] ?? 0 );
			if ( $s <= 0 ) {
				continue;
			}
			$parts[] = ( $entry['slug'] ?? '?' ) . ':' . $s;
			$i++;
		}
		return $parts ? implode( ', ', $parts ) : '—';
	}

	/**
	 * Import تعداد مشخصی پیام یکتا (بدون duplicate) از کانال عمومی (استراتژی Scrape).
	 *
	 * از نسخه‌ی ۵.۲ به بعد پردازش به‌صورت «پس‌زمینه و چانک‌به‌چانک» انجام می‌شود:
	 * این متد فقط batch را می‌سازد و اولین چانک را در WP-Cron زمان‌بندی می‌کند؛
	 * به این ترتیب درخواست AJAX سریع برمی‌گردد و PHP هرگز timeout نمی‌شود.
	 *
	 * @param string $username      نام کاربری کانال.
	 * @param int    $topic_id      شناسه‌ی topic (پست شروع).
	 * @param int    $desired_count تعداد محصول یکتای موردنیاز.
	 * @param int    $category_id   دسته‌بندی.
	 * @param string $label         برچسب.
	 * @param int    $max_scan      حداکثر پیام‌های قابل اسکن.
	 * @return array  نتیجه شامل batch و آمار.
	 */
	public function import_unique( $username, $topic_id, $desired_count, $category_id, $label, $max_scan = 200 ) {
		$username      = ltrim( $username, '@' );
		$desired_count = max( 1, min( (int) $desired_count, self::MAX_BATCH_SIZE ) );
		$topic_id      = $topic_id ? (int) $topic_id : 0;
		$category_id   = (int) $category_id;
		$max_scan      = max( $desired_count, min( (int) $max_scan, 500 ) );

		$batch_id = 'b_' . time() . '_' . wp_rand( 1000, 9999 );
		$now = current_time( 'mysql' );

		$batch = array(
			'id'                => $batch_id,
			'username'          => $username,
			'chat_id'           => 0,
			'topic_id'          => $topic_id,
			'category_id'       => $category_id,
			'label'             => $label ? sanitize_text_field( $label ) : 'Scrape Import ' . date( 'Y-m-d H:i' ),
			'strategy'          => 'scrape',
			'desired_count'     => $desired_count,
			'total_scanned'     => 0,
			'imported'          => 0,
			'duplicates_skipped'=> 0,
			'max_scan'          => $max_scan,
			'next_message_id'   => 0, // نقطه‌ی ادامه برای چانک بعدی
			'status'            => 'queued',
			'message_results'   => array(),
			'last_error'        => '',
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$this->save_batch( $batch_id, $batch );

		STI_Logger::info( 'Channel Import: batch اسکرپینگ ساخته شد — id=' . $batch_id . ', @' . $username . ', desired=' . $desired_count . ', max_scan=' . $max_scan );

		$this->schedule_worker( $batch_id );

		return $batch;
	}

	/**
	 * پردازش یک چانک از batch اسکرپینگ (در WP-Cron یا پردازش فوری).
	 * در هر چانک حداکثر CHUNK_SCAN_LIMIT پیام اسکن می‌شود و اگر کار تمام نشده
	 * باشد، چانک بعدی زمان‌بندی می‌شود.
	 *
	 * @param string $batch_id
	 * @param array  $batch
	 * @return array  batch به‌روزشده.
	 */
	protected function process_scrape_batch( $batch_id, $batch ) {
		$username     = $batch['username'];
		$category_id  = (int) $batch['category_id'];
		$desired_count = max( 1, (int) $batch['desired_count'] );
		$max_scan     = (int) ( $batch['max_scan'] ?? self::MAX_SCAN );

		// نقطه‌ی شروع: ادامه از چانک قبل ← topic_id ← آخرین پیام قابل اسکرپ
		$test_id = (int) ( $batch['next_message_id'] ?? 0 );
		if ( ! $test_id ) {
			$test_id = (int) ( $batch['topic_id'] ?? 0 );
		}
		if ( ! $test_id ) {
			$test_id = $this->get_latest_scrapable_id( $username );
		}

		if ( ! $test_id || $test_id < 0 ) {
			$batch['status']     = 'error';
			$batch['last_error'] = ( -1 === $test_id )
				? 'کانال خصوصی است و در وب پیامی ندارد؛ از استراتژی «اکانت شخصی (MTProto)» استفاده کنید.'
				: 'نقطه‌ی شروع اسکن پیدا نشد. آیا کانال عمومی است؟';
			$batch['updated_at'] = current_time( 'mysql' );
			$this->save_batch( $batch_id, $batch );

			STI_Logger::error( 'Channel Import: ' . $batch['last_error'] . ' — batch=' . $batch_id );
			return $batch;
		}

		$batch['status']     = 'running';
		$batch['updated_at'] = current_time( 'mysql' );
		$this->save_batch( $batch_id, $batch );

		$scans_in_chunk = 0;

		while ( $batch['imported'] < $desired_count
		        && $batch['total_scanned'] < $max_scan
		        && $test_id > 0
		        && $scans_in_chunk < self::CHUNK_SCAN_LIMIT ) {

			$data = $this->scrape_message( $username, $test_id );
			$batch['total_scanned']++;
			$scans_in_chunk++;

			if ( $data && ! empty( $data['caption'] ) ) {
				$file_code = $this->extract_file_code_from_text( $data['caption'] );

				if ( $file_code ) {
					/* ترتیب: ۱) تکراری بودن ۲) تطبیق اتوکت ۳) ساخت session */
					if ( $this->is_duplicate( $file_code ) ) {
						$batch['duplicates_skipped']++;
						$batch['message_results'][ $test_id ] = array(
							'status'       => 'skipped_duplicate',
							'file_code'    => $file_code,
							'is_duplicate' => true,
						);
						STI_Logger::info( 'Channel Import: skip duplicate — message_id=' . $test_id . ', file_code=' . $file_code );
					} else {
						$autocat_title = trim( $data['caption'] . ' ' . ( $data['file_name'] ?? '' ) . ' ' . ( $data['file_type'] ?? '' ) );
						$autocat_type  = trim( (string) ( $data['file_type'] ?? '' ) . ' ' . ( $data['file_name'] ?? '' ) );
						$autocat_check = $this->evaluate_autocat_match( $autocat_title, $autocat_type, $category_id );

						if ( ! $autocat_check['allowed'] ) {
							STI_Logger::info( 'Channel Import: پیام ' . $test_id . ' رد شد — ' . $autocat_check['reason'] );
							$batch['message_results'][ $test_id ] = array(
								'status'       => 'no_category',
								'file_code'    => $file_code,
								'is_duplicate' => false,
								'error'        => $autocat_check['reason'],
							);
						} else {
							$msg_category = (int) $autocat_check['category_id'];
							$session_id = STI_Session::create( 0, null, $msg_category );

							if ( $session_id ) {
								$session_data = array(
									'file_code'   => $file_code,
									'file_name'   => $data['file_name'] ?? '',
									'file_type'   => $data['file_type'] ?? '',
									'caption_raw' => $data['caption'],
									'source_url'  => $data['source_url'] ?? '',
								);

								if ( ! empty( $data['image_url'] ) ) {
									$session_data['image_url'] = $data['image_url'];
								}

								if ( ! empty( $data['button_urls'] ) && is_array( $data['button_urls'] ) ) {
									$session_data['download_url_raw'] = $data['button_urls'][0];
								}

								STI_Session::update( $session_id, $session_data );

								$batch['imported']++;
								$batch['message_results'][ $test_id ] = array(
									'status'       => 'imported',
									'session_id'   => $session_id,
									'file_code'    => $file_code,
									'is_duplicate' => false,
								);

								STI_Logger::info( 'Channel Import: پیام import شد — message_id=' . $test_id . ', session_id=' . $session_id . ', file_code=' . $file_code );

								$session = STI_Session::get( $session_id );
								if ( $session && STI_Session::is_complete( $session ) ) {
									$this->finalize_import_session( $session, $batch_id );
								}
							} else {
								STI_Logger::error( 'Channel Import: ایجاد session ناموفق — message_id=' . $test_id );

								$batch['message_results'][ $test_id ] = array(
									'status'       => 'session_failed',
									'file_code'    => $file_code,
									'is_duplicate' => false,
								);
							}
						}
					}
				} else {
					$batch['message_results'][ $test_id ] = array(
						'status'       => 'no_file_code',
						'file_code'    => '',
						'is_duplicate' => false,
					);
				}
			} else {
				$batch['message_results'][ $test_id ] = array(
					'status'       => $data ? 'no_caption' : 'scrape_failed',
					'file_code'    => '',
					'is_duplicate' => false,
				);
			}

			$batch['updated_at'] = current_time( 'mysql' );
			$this->save_batch( $batch_id, $batch );

			$test_id--;

			// تأخیر انسانی بین اسکن‌ها
			if ( $batch['imported'] < $desired_count
			     && $batch['total_scanned'] < $max_scan
			     && $test_id > 0
			     && $scans_in_chunk < self::CHUNK_SCAN_LIMIT ) {
				self::random_delay( 0.8, 2.2 );
				if ( wp_rand( 1, 8 ) === 1 ) {
					self::random_delay( 3.0, 6.0 );
				}
			}
		}

		$batch['next_message_id'] = $test_id;

		/* ── وضعیت نهایی چانک ── */
		if ( $batch['imported'] >= $desired_count ) {
			$batch['status'] = 'completed';
		} elseif ( $batch['total_scanned'] >= $max_scan ) {
			$batch['status'] = 'partial';
		} elseif ( $test_id <= 0 ) {
			$batch['status'] = 'completed';
		} else {
			$batch['status'] = 'running';
		}

		$batch['updated_at'] = current_time( 'mysql' );
		$this->save_batch( $batch_id, $batch );

		if ( 'running' === $batch['status'] ) {
			$this->schedule_worker( $batch_id, self::WORKER_INTERVAL );
		}

		STI_Logger::info( 'Channel Import: چانک اسکرپینگ تمام شد — batch=' . $batch_id . ', scanned=' . $batch['total_scanned'] . ', imported=' . $batch['imported'] . ', status=' . $batch['status'] );

		return $batch;
	}

	/* ======================================================================
	   SECTION 5b: STRATEGY MT — اکانت شخصی تلگرام (MTProto)
	   ====================================================================== */

	/**
	 * شروع یک batch با استراتژی اکانت شخصی (MTProto).
	 * مناسب کانال/گروه خصوصی که کاربر عضو آن است — دقیقاً مثل این‌که خود کاربر
	 * داخل تلگرام تاریخچه را باز کند و فایل‌ها را دانلود کند.
	 *
	 * @param string $username
	 * @param int    $topic_id
	 * @param int    $count
	 * @param int    $category_id
	 * @param string $label
	 * @param array  $parsed  خروجی parse_chat_identifier (برای لینک دعوت).
	 * @return array
	 */
	public function start_mtproto_batch( $username, $topic_id, $count, $category_id, $label, $parsed = array() ) {
		$username      = ltrim( $username, '@' );
		$count         = max( 1, min( (int) $count, self::MAX_BATCH_SIZE ) );
		$topic_id      = $topic_id ? (int) $topic_id : 0;
		$category_id   = (int) $category_id;

		$batch_id = 'b_' . time() . '_' . wp_rand( 1000, 9999 );
		$now = current_time( 'mysql' );

		$batch = array(
			'id'                => $batch_id,
			'username'          => $username,
			'chat_id'           => 0,
			'invite_hash'       => ! empty( $parsed['invite_hash'] ) ? $parsed['invite_hash'] : '',
			'topic_id'          => $topic_id,
			'category_id'       => $category_id,
			'label'             => $label ? sanitize_text_field( $label ) : 'MTProto Import ' . date( 'Y-m-d H:i' ),
			'strategy'          => self::STRATEGY_MT,
			'desired_count'     => $count,
			'total_scanned'     => 0,
			'imported'          => 0,
			'duplicates_skipped'=> 0,
			'next_offset_id'    => 0, // getHistory از آخر شروع می‌کند
			'status'            => 'queued',
			'message_results'   => array(),
			'last_error'        => '',
			'channel_title'     => '',
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$this->save_batch( $batch_id, $batch );

		STI_Logger::info( 'Channel Import: batch MTProto ساخته شد — id=' . $batch_id . ', @' . $username . ', desired=' . $count );

		$this->schedule_worker( $batch_id );

		return $batch;
	}

	/**
	 * پردازش یک چانک از batch MTProto.
	 *
	 * در هر چانک: یک تکه تاریخچه می‌خواند، پیام‌ها را پردازش می‌کند (کد فایل،
	 * تکراری‌ها، دانلود رسانه، فشار دادن دکمه‌ی دانلود)، session می‌سازد و در
	 * صورت کامل بودن محصول را می‌سازد. سپس اگر هنوز به تعداد خواسته‌شده نرسیده،
	 * چانک بعدی را زمان‌بندی می‌کند.
	 *
	 * @param string $batch_id
	 * @param array  $batch
	 * @return array
	 */
	/**
	 * پردازش یک چانک از batch MTProto — ماشین حالت سه‌مرحله‌ای:
	 *
	 *   collect ← خواندن تاریخچه، ساخت سشن (عکس+متن+کد)، ساخت صف دکمه‌های دانلود
	 *   press   ← فشار دادن دکمه‌های «دانلود» (کالبک) یا باز کردن گفتگوی ربات (start)
	 *   wait    ← انتظار برای فایل‌های ارسالی ربات، تطبیق با کد، دانلود و ساخت محصول
	 *
	 * بعد از پایان انتظار (همه‌ی فایل‌ها یا MT_FETCH_TIMEOUT) سشن‌های بدون فایل
	 * بسته می‌شوند (error) و batch وضعیت نهایی می‌گیرد.
	 *
	 * @param string $batch_id
	 * @param array  $batch
	 * @return array
	 */
	/**
	 * Start the search-first batch. Search results are stored in
	 * STI_Channel_Index before any download button is pressed.
	 */
	public function start_mtproto_search_batch( $username, $topic_id, $count, $category_id, $label, $parsed = array() ) {
		$category_id = (int) $category_id;
		$category = STI_Category::get( $category_id );
		$terms = class_exists( 'STI_Channel_Index' ) ? STI_Channel_Index::search_terms( $category ) : array();
		if ( empty( $terms ) ) {
			return $this->strategy_error( 'برای این دسته عبارت جست‌وجویی تعریف نشده است. از صفحه دسته‌بندی‌ها عبارت‌ها را وارد کنید.' );
		}

		$username = ltrim( (string) $username, '@' );
		$count = max( 1, min( (int) $count, self::MAX_BATCH_SIZE ) );
		$topic_id = $topic_id ? (int) $topic_id : 0;
		$batch_id = 'b_' . time() . '_' . wp_rand( 1000, 9999 );
		$now = current_time( 'mysql' );
		$batch = array(
			'id'                 => $batch_id,
			'username'           => $username,
			'chat_id'            => 0,
			'invite_hash'        => ! empty( $parsed['invite_hash'] ) ? $parsed['invite_hash'] : '',
			'topic_id'           => $topic_id,
			'category_id'        => $category_id,
			'label'              => $label ? sanitize_text_field( $label ) : 'MTProto Search ' . date( 'Y-m-d H:i' ),
			'strategy'           => 'mtproto_search',
			'desired_count'      => $count,
			'total_scanned'      => 0,
			'imported'           => 0,
			'accepted_count'     => 0,
			'indexed_count'      => 0,
			'files_downloaded'   => 0,
			'products_created'   => 0,
			'duplicates_skipped' => 0,
			'status'             => 'queued',
			'stage'              => 'search',
			'search_terms'       => array_values( $terms ),
			'search_term_index'  => 0,
			'search_offset_id'   => 0,
			'search_exhausted'   => false,
			'fallback_history'   => false,
			'next_offset_id'     => 0,
			'message_results'    => array(),
			'file_queue'         => array(),
			'last_error'         => '',
			'channel_title'      => '',
			'created_at'         => $now,
			'updated_at'         => $now,
		);

		$this->save_batch( $batch_id, $batch );
		STI_Logger::info( 'Channel Import: batch جست‌وجوی MTProto ساخته شد — id=' . $batch_id . ', terms=' . implode( ', ', $terms ) . ', desired=' . $count );
		$this->schedule_worker( $batch_id, 1 );
		return $batch;
	}

	/** Process one search/validation/press/wait slice of a search-first batch. */
	protected function process_mtproto_search_batch( $batch_id, $batch ) {
		$mt = STI_MTProto::instance();
		$batch['status'] = 'running';
		$batch['updated_at'] = current_time( 'mysql' );

		$peer = (int) ( $batch['chat_id'] ?? 0 );
		if ( ! $peer ) {
			$identifier = ! empty( $batch['invite_hash'] ) ? 'https://t.me/+' . $batch['invite_hash'] : $batch['username'];
			$info = $mt->chat_info( $identifier );
			if ( is_wp_error( $info ) ) {
				$batch['status'] = 'error';
				$batch['last_error'] = 'خطا در پیدا کردن کانال برای جست‌وجو: ' . $info->get_error_message();
				$this->save_batch( $batch_id, $batch );
				$this->schedule_search_worker( $batch_id, $batch );
				return $batch;
			}
			$peer = (int) $info['id'];
			$batch['chat_id'] = $peer;
			$batch['channel_title'] = (string) ( $info['title'] ?? '' );
		}

		$tmp_dir = $this->mtproto_tmp_dir();
		$stage = (string) ( $batch['stage'] ?? 'search' );

		if ( 'collect' === $stage ) {
			// Search is a fast prefilter, not a correctness boundary. If the
			// configured aliases did not yield enough accepted candidates, use
			// the proven history scanner once as a controlled fallback.
			$batch = $this->mt_stage_collect( $batch, $mt, $peer, $tmp_dir );
			$batch['updated_at'] = current_time( 'mysql' );
			$this->save_batch( $batch_id, $batch );
			$this->schedule_search_worker( $batch_id, $batch );
			return $batch;
		}

		if ( 'search' === $stage ) {
			$terms = array_values( (array) ( $batch['search_terms'] ?? array() ) );
			$term_index = (int) ( $batch['search_term_index'] ?? 0 );
			if ( $term_index >= count( $terms ) ) {
				$batch['search_exhausted'] = true;
				$batch['stage'] = 'validate';
				$this->save_batch( $batch_id, $batch );
				$this->schedule_search_worker( $batch_id, $batch );
				return $batch;
			}

			$term = (string) $terms[ $term_index ];
			$limit = max( 10, min( 100, (int) STI_Settings::get( 'ci_search_page_limit', self::MT_SEARCH_CHUNK_LIMIT ) ) );
			$found = $mt->search_messages( $peer, $term, $limit, (int) ( $batch['search_offset_id'] ?? 0 ), (int) ( $batch['topic_id'] ?? 0 ) );
			if ( is_wp_error( $found ) ) {
				$batch['status'] = 'error';
				$batch['last_error'] = 'جست‌وجوی «' . $term . '» ناموفق بود: ' . $found->get_error_message();
				$this->save_batch( $batch_id, $batch );
				$this->schedule_search_worker( $batch_id, $batch );
				return $batch;
			}

			$messages = (array) ( $found['messages'] ?? array() );
			$inserted = 0;
			foreach ( $messages as $m ) {
				$batch['total_scanned']++;
				$code = $this->extract_mt_file_code( $m );
				$button = $this->extract_mt_button( $m );
				$index_id = STI_Channel_Index::discover( array(
					'batch_id'          => $batch_id,
					'source_chat_id'    => $peer,
					'source_username'   => $batch['username'],
					'source_message_id' => (int) ( $m['id'] ?? 0 ),
					'source_date'       => (int) ( $m['date'] ?? 0 ),
					'category_id'       => (int) $batch['category_id'],
					'search_term'       => $term,
					'file_code'         => $code,
					'file_name'         => $m['file_name'] ?? '',
					'file_type'         => $this->infer_message_file_type( $m ),
					'caption_raw'       => $m['text'] ?? '',
					'button_type'       => $button['type'] ?? '',
					'button_url'        => $button['url'] ?? '',
					'bot_username'      => $button['bot'] ?? '',
					'bot_payload'       => $button['payload'] ?? '',
					'button_data'       => $button['data'] ?? '',
					'raw_payload'       => $m['raw'] ?? $m,
				) );
				if ( $index_id ) {
					$indexed = STI_Channel_Index::get( $index_id );
					if ( $indexed && (string) $indexed['batch_id'] === (string) $batch_id && self::same_index_candidate( $indexed ) ) {
						$inserted++;
					}
				}
			}
			$batch['indexed_count'] = (int) ( $batch['indexed_count'] ?? 0 ) + $inserted;

			$last_id = 0;
			if ( ! empty( $messages ) ) {
				$last = end( $messages );
				$last_id = (int) ( $last['id'] ?? 0 );
			}
			if ( empty( $messages ) || count( $messages ) < $limit || ! $last_id ) {
				$batch['search_term_index'] = $term_index + 1;
				$batch['search_offset_id'] = 0;
				if ( $term_index + 1 >= count( $terms ) ) {
					$batch['search_exhausted'] = true;
				}
			} else {
				$batch['search_offset_id'] = $last_id;
			}
			$batch['stage'] = 'validate';
			$this->save_batch( $batch_id, $batch );
			$this->schedule_search_worker( $batch_id, $batch );
			return $batch;
		}

		if ( 'validate' === $stage ) {
			STI_Channel_Index::reclaim_stale();
			$accepted = (int) ( $batch['accepted_count'] ?? $batch['imported'] ?? 0 );
			$remaining = max( 1, (int) $batch['desired_count'] - $accepted );
			$rows = STI_Channel_Index::get_batch_by_status( $batch_id, STI_Channel_Index::DISCOVERED, min( self::MT_SEARCH_VALIDATE_LIMIT, $remaining + 2 ) );
			foreach ( $rows as $row ) {
				if ( $accepted >= (int) $batch['desired_count'] ) { break; }
				if ( $this->validate_search_candidate( $batch, $row, $mt, $peer, $tmp_dir ) ) {
					$accepted++;
				}
			}
			$batch['accepted_count'] = $accepted;
			$batch['imported'] = $accepted;

			$left = STI_Channel_Index::count_batch_status( $batch_id, STI_Channel_Index::DISCOVERED );
			if ( $left > 0 && $accepted < (int) $batch['desired_count'] ) {
				$batch['stage'] = 'validate';
			} elseif ( $accepted >= (int) $batch['desired_count'] ) {
				$batch['stage'] = ! empty( $batch['file_queue'] ) ? 'press' : 'done';
				if ( 'done' === $batch['stage'] ) { $batch['status'] = 'completed'; }
			} elseif ( ! empty( $batch['search_exhausted'] ) && empty( $batch['fallback_history'] ) && STI_Settings::get( 'ci_search_fallback_history', 1 ) ) {
				$batch['fallback_history'] = true;
				$batch['next_offset_id'] = 0;
				$batch['stage'] = 'collect';
			} elseif ( ! empty( $batch['search_exhausted'] ) ) {
				$batch['stage'] = ! empty( $batch['file_queue'] ) ? 'press' : 'done';
				if ( 'done' === $batch['stage'] ) { $batch['status'] = 'completed'; }
			} else {
				$batch['stage'] = 'search';
			}
			$this->save_batch( $batch_id, $batch );
			$this->schedule_search_worker( $batch_id, $batch );
			return $batch;
		}

		if ( 'press' === $stage ) {
			$batch = $this->mt_stage_press( $batch, $mt, $peer );
		} elseif ( 'wait' === $stage ) {
			$batch = $this->mt_stage_wait( $batch, $mt, $tmp_dir );
		} elseif ( 'done' === $stage ) {
			$batch['status'] = 'completed';
		}
		$batch['updated_at'] = current_time( 'mysql' );
		$this->save_batch( $batch_id, $batch );
		$this->schedule_search_worker( $batch_id, $batch );
		return $batch;
	}

	protected function schedule_search_worker( $batch_id, $batch ) {
		if ( 'running' !== ( $batch['status'] ?? '' ) || 'done' === ( $batch['stage'] ?? '' ) ) { return; }
		$delay = 'wait' === ( $batch['stage'] ?? '' ) ? wp_rand( 5, 9 ) : wp_rand( 2, 4 );
		$this->schedule_worker( $batch_id, $delay );
	}

	/** Return true when a discovered row belongs to this batch and is still new. */
	protected static function same_index_candidate( $row ) {
		return $row && STI_Channel_Index::DISCOVERED === (string) ( $row['status'] ?? '' );
	}

	/**
	 * Deterministic category evidence. Telegram search is only a candidate
	 * retriever; this second check prevents an unrelated message from entering
	 * a category if a Telegram/MadelineProto search response is too broad or if
	 * the history fallback is used.
	 */
	protected function has_category_search_evidence( $category_id, $blob ) {
		$category = STI_Category::get( (int) $category_id );
		$terms = class_exists( 'STI_Channel_Index' ) ? STI_Channel_Index::search_terms( $category ) : array();
		$blob = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $blob ) ) );
		if ( '' === $blob || empty( $terms ) ) { return false; }
		foreach ( $terms as $term ) {
			$term = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $term ) ) );
			if ( '' === $term ) { continue; }
			if ( mb_strlen( $term ) <= 3 && preg_match( '/^[a-z0-9]+$/i', $term ) ) {
				if ( preg_match( '/(?<![a-z0-9])' . preg_quote( $term, '/' ) . '(?![a-z0-9])/iu', $blob ) ) { return true; }
			} elseif ( false !== mb_strpos( $blob, $term ) ) {
				return true;
			}
		}
		return false;
	}

	protected function message_search_blob( $message, $extra = '' ) {
		$parts = array( $message['text'] ?? '', $message['file_name'] ?? '', $extra );
		foreach ( (array) ( $message['buttons'] ?? array() ) as $button ) {
			$parts[] = $button['text'] ?? '';
			$parts[] = $button['url'] ?? '';
		}
		return implode( ' ', $parts );
	}

	/** Extract a code from caption, button payload/text/url, or a file name. */
	protected function extract_mt_file_code( $message ) {
		$text = (string) ( $message['text'] ?? '' );
		$parsed = STI_Caption_Parser::parse( $text );
		$code = STI_Channel_Index::normalize_code( $parsed['file_code'] ?? '' );
		if ( $code ) { return $code; }
		foreach ( (array) ( $message['buttons'] ?? array() ) as $button ) {
			$url = (string) ( $button['url'] ?? '' );
			if ( $url && preg_match( '#[?&]start=([A-Za-z0-9_-]+)#i', $url, $m ) ) {
				return STI_Channel_Index::normalize_code( $m[1] );
			}
			$data = STI_Channel_Index::normalize_code( $button['data'] ?? '' );
			if ( $data && preg_match( '/\d{4,}/', $data ) ) { return $data; }
			$text_button = (string) ( $button['text'] ?? '' );
			if ( preg_match( '/(?<!\d)(\d{4,})(?!\d)/', $text_button, $m ) ) {
				return STI_Channel_Index::normalize_code( $m[1] );
			}
		}
		if ( ! empty( $message['raw']['reply_markup'] ) ) {
			$raw_bot = $this->find_bot_button_in_markup( $message['raw']['reply_markup'] );
			if ( $raw_bot && ! empty( $raw_bot['payload'] ) ) {
				$raw_code = STI_Channel_Index::normalize_code( $raw_bot['payload'] );
				if ( $raw_code ) { return $raw_code; }
			}
		}
		$file_name = (string) ( $message['file_name'] ?? '' );
		if ( preg_match( '/(?<!\d)(\d{4,})(?!\d)/', $file_name, $m ) ) {
			return STI_Channel_Index::normalize_code( $m[1] );
		}
		// Do not treat arbitrary numbers in prose as a File Code: that creates
		// false products from dates, dimensions or phone numbers. The caption
		// parser already handles explicit Code/File Code labels.
		return '';
	}

	/** Extract the first actionable button from a normalized MTProto message. */
	protected function extract_mt_button( $message ) {
		foreach ( (array) ( $message['buttons'] ?? array() ) as $button ) {
			$data = (string) ( $button['data'] ?? '' );
			$url  = (string) ( $button['url'] ?? '' );
			if ( $data ) {
				return array( 'type' => 'callback', 'data' => $data, 'url' => '', 'bot' => '', 'payload' => '' );
			}
			if ( $url && preg_match( '#(?:t\.me|telegram\.me)/([A-Za-z0-9_]{3,64})(?:\?start=([A-Za-z0-9_-]*))?#i', $url, $m ) ) {
				return array( 'type' => 'start', 'data' => '', 'url' => $url, 'bot' => $m[1], 'payload' => ! empty( $m[2] ) ? $m[2] : '' );
			}
			if ( $url ) {
				return array( 'type' => 'direct', 'data' => '', 'url' => $url, 'bot' => '', 'payload' => '' );
			}
		}
		return array( 'type' => '', 'data' => '', 'url' => '', 'bot' => '', 'payload' => '' );
	}

	protected function infer_message_file_type( $message ) {
		$parsed = STI_Caption_Parser::parse( (string) ( $message['text'] ?? '' ) );
		if ( ! empty( $parsed['file_type'] ) ) { return $parsed['file_type']; }
		$ext = strtoupper( ltrim( (string) pathinfo( (string) ( $message['file_name'] ?? '' ), PATHINFO_EXTENSION ), '.' ) );
		return $ext;
	}

	/**
	 * Validate one indexed message, create its session, and only then queue a
	 * callback/start action. Returns true when an accepted candidate was created.
	 */
	protected function validate_search_candidate( &$batch, $row, $mt, $peer, $tmp_dir ) {
		if ( ! STI_Channel_Index::claim_for_validation( (int) $row['id'] ) ) { return false; }
		$raw = ! empty( $row['raw_payload'] ) ? json_decode( $row['raw_payload'], true ) : array();
		$m = $raw ? $mt->normalize_message( $raw ) : null;
		if ( ! $m ) {
			STI_Channel_Index::update( $row['id'], array( 'status' => STI_Channel_Index::ERROR, 'error_message' => 'پیام خام برای اعتبارسنجی قابل بازیابی نیست.' ) );
			return false;
		}
		$m['sender_chat_id'] = $peer;
		$text = (string) ( $m['text'] ?? '' );
		$parsed = STI_Caption_Parser::parse( $text );
		$file_code = $this->extract_mt_file_code( $m );
		$button = $this->extract_mt_button( $m );
		// Fallback for MadelineProto variants whose reply_markup shape is not
		// normalized into buttons[]. This is especially important for t.me/Bot
		// URLs attached to preview media such as preview.m4a.
		if ( '' === $button['type'] && ! empty( $m['raw']['reply_markup'] ) ) {
			$raw_bot = $this->find_bot_button_in_markup( $m['raw']['reply_markup'] );
			if ( $raw_bot ) {
				$button = array(
					'type' => 'start',
					'data' => '',
					'url' => 'https://t.me/' . $raw_bot['bot'] . ( ! empty( $raw_bot['payload'] ) ? '?start=' . rawurlencode( $raw_bot['payload'] ) : '' ),
					'bot' => (string) $raw_bot['bot'],
					'payload' => (string) ( $raw_bot['payload'] ?? '' ),
				);
			}
		}

		if ( ! $file_code ) {
			STI_Channel_Index::update( $row['id'], array( 'status' => STI_Channel_Index::REJECTED_NO_CODE, 'error_message' => 'File Code در کپشن، نام فایل یا دکمه پیدا نشد.' ) );
			$batch['message_results'][ (int) $row['source_message_id'] ] = array( 'status' => 'no_file_code', 'file_code' => '' );
			return false;
		}

		if ( ! STI_Channel_Index::bind_code( (int) $row['id'], $file_code ) ) {
			STI_Channel_Index::update( $row['id'], array( 'status' => STI_Channel_Index::SKIPPED_DUPLICATE, 'error_message' => 'این File Code قبلاً برای یک کاندیدای دیگر رزرو شده است.' ) );
			$batch['duplicates_skipped'] = (int) ( $batch['duplicates_skipped'] ?? 0 ) + 1;
			$batch['message_results'][ (int) $row['source_message_id'] ] = array( 'status' => 'skipped_duplicate', 'file_code' => $file_code );
			return false;
		}

		if ( $this->is_duplicate( $file_code ) ) {
			STI_Channel_Index::update( $row['id'], array( 'file_code' => $file_code, 'status' => STI_Channel_Index::SKIPPED_DUPLICATE, 'error_message' => 'محصول یا Session فعال با همین File Code وجود دارد.' ) );
			$batch['duplicates_skipped'] = (int) ( $batch['duplicates_skipped'] ?? 0 ) + 1;
			$batch['message_results'][ (int) $row['source_message_id'] ] = array( 'status' => 'skipped_duplicate', 'file_code' => $file_code );
			return false;
		}

		$evidence_blob = $this->message_search_blob( $m, $button['url'] );
		if ( ! $this->has_category_search_evidence( (int) $batch['category_id'], $evidence_blob ) ) {
			$reason = 'هیچ عبارت جست‌وجوی معتبر از دسته انتخاب‌شده در عنوان/نام فایل این پیام پیدا نشد؛ پیام رد شد.';
			STI_Channel_Index::update( $row['id'], array( 'file_code' => $file_code, 'status' => STI_Channel_Index::REJECTED_CATEGORY, 'error_message' => $reason ) );
			$batch['message_results'][ (int) $row['source_message_id'] ] = array( 'status' => 'no_category', 'file_code' => $file_code, 'error' => $reason );
			return false;
		}

		$autocat_title = trim( $text . ' ' . ( $m['file_name'] ?? '' ) . ' ' . $parsed['file_type'] . ' ' . $button['url'] );
		$autocat_type = trim( (string) $parsed['file_type'] . ' ' . (string) ( $m['file_name'] ?? '' ) );
		$check = $this->evaluate_autocat_match( $autocat_title, $autocat_type, (int) $batch['category_id'] );
		if ( empty( $check['allowed'] ) ) {
			STI_Channel_Index::update( $row['id'], array( 'file_code' => $file_code, 'status' => STI_Channel_Index::REJECTED_CATEGORY, 'error_message' => $check['reason'] ) );
			$batch['message_results'][ (int) $row['source_message_id'] ] = array( 'status' => 'no_category', 'file_code' => $file_code, 'error' => $check['reason'] );
			return false;
		}

		$session_id = STI_Session::create( $peer, null, (int) $check['category_id'] );
		if ( $session_id ) { STI_Session::update( $session_id, array( 'notify_chat_id' => 0 ) ); }
		if ( ! $session_id ) {
			STI_Channel_Index::update( $row['id'], array( 'status' => STI_Channel_Index::ERROR, 'error_message' => 'ساخت Session ناموفق بود.' ) );
			return false;
		}
		$sdata = array(
			'file_code' => $file_code,
			'file_name' => $parsed['file_name'] ?: ( $m['file_name'] ?? '' ),
			'file_type' => $parsed['file_type'] ?: $this->infer_message_file_type( $m ),
			'caption_raw' => $text,
		);
		foreach ( array( 'source_url', 'dimensions', 'resolution', 'color' ) as $field ) {
			if ( ! empty( $parsed[ $field ] ) ) { $sdata[ $field ] = $parsed[ $field ]; }
		}
		STI_Session::update( $session_id, $sdata );

		$file_ready = false;
		$image_ready = false;
		if ( 'photo' === ( $m['media_type'] ?? '' ) && ! STI_Settings::get( 'mtproto_auto_download', 1 ) ) {
			$error = 'دانلود خودکار رسانه خاموش است؛ بدون تصویر شاخص، این کاندیدا رد شد.';
			STI_Session::mark_error( $session_id, $error );
			STI_Channel_Index::update( $row['id'], array( 'status' => STI_Channel_Index::ERROR, 'session_id' => $session_id, 'file_code' => $file_code, 'error_message' => $error ) );
			return false;
		}
		if ( 'none' !== ( $m['media_type'] ?? 'none' ) && STI_Settings::get( 'mtproto_auto_download', 1 ) && ( 'photo' === ( $m['media_type'] ?? '' ) || ! in_array( $button['type'], array( 'callback', 'start', 'direct' ), true ) ) ) {
			$dl = $mt->download_media( $m, $tmp_dir );
			if ( is_wp_error( $dl ) ) {
				STI_Session::mark_error( $session_id, 'دانلود رسانه پیام ناموفق بود: ' . $dl->get_error_message() );
				STI_Channel_Index::update( $row['id'], array( 'status' => STI_Channel_Index::ERROR, 'session_id' => $session_id, 'file_code' => $file_code, 'error_message' => $dl->get_error_message() ) );
				return false;
			}
			if ( 'photo' === ( $m['media_type'] ?? '' ) ) {
				$att = STI_File_Storage::store_image_from_local_file( $dl['path'], $sdata['file_name'] ?: $file_code, $dl['name'] ?: ( 'photo_' . $row['source_message_id'] . '.jpg' ) );
				if ( is_wp_error( $att ) || ! $att ) {
					STI_Session::mark_error( $session_id, 'ذخیره تصویر شاخص ناموفق بود.' );
					STI_Channel_Index::update( $row['id'], array( 'status' => STI_Channel_Index::ERROR, 'session_id' => $session_id, 'file_code' => $file_code, 'error_message' => 'ذخیره تصویر شاخص ناموفق بود.' ) );
					return false;
				}
				$att_url = wp_get_attachment_url( $att );
				STI_Session::update( $session_id, array( 'image_file_id' => (string) $att, 'image_url' => $att_url ?: '' ) );
				$image_ready = true;
			} else {
				$stored = $this->store_downloaded_file( $dl, $file_code, $sdata['file_name'], (int) $check['category_id'], $session_id );
				if ( ! $stored || empty( $stored['url'] ) ) {
					STI_Session::mark_error( $session_id, 'ذخیره فایل ضمیمه ناموفق بود.' );
					STI_Channel_Index::update( $row['id'], array( 'status' => STI_Channel_Index::ERROR, 'session_id' => $session_id, 'file_code' => $file_code, 'error_message' => 'ذخیره فایل ضمیمه ناموفق بود.' ) );
					return false;
				}
				STI_Session::update( $session_id, array( 'download_url_final' => $stored['url'], 'file_size_bytes' => $stored['size_bytes'] ?? null ) );
				$file_ready = true;
			}
		}

		if ( 'direct' === $button['type'] ) {
			STI_Session::update( $session_id, array( 'download_url_raw' => $button['url'] ) );
			$file_ready = true;
		}

		$queued = false;
		if ( ! STI_Settings::get( 'mtproto_press_buttons', 1 ) && in_array( $button['type'], array( 'callback', 'start' ), true ) ) {
			$button['type'] = '';
			$button['data'] = '';
			$button['bot'] = '';
			$button['payload'] = '';
		}
		if ( 'callback' === $button['type'] ) {
			$batch['file_queue'][] = $this->make_search_queue_item( $row, $file_code, $session_id, 'callback', $button['data'], '', (int) $peer );
			$queued = true;
		} elseif ( 'start' === $button['type'] ) {
			$payload = $button['payload'] ?: $file_code;
			$batch['file_queue'][] = $this->make_search_queue_item( $row, $file_code, $session_id, 'start', $payload, $button['bot'], (int) $peer );
			$queued = true;
		}

		// Fail closed: a candidate without a featured image and without a
		// resolvable download path must never become a product later by accident.
		$session_after_media = STI_Session::get( $session_id );
		if ( ! $queued && ( ! $session_after_media || ! STI_Session::is_complete( $session_after_media ) ) ) {
			$error = 'دکمه دانلود یا تصویر/فایل کامل برای این پیام پیدا نشد؛ محصول ساخته نمی‌شود.';
			STI_Session::mark_error( $session_id, $error );
			STI_Channel_Index::update( $row['id'], array( 'status' => STI_Channel_Index::ERROR, 'session_id' => $session_id, 'file_code' => $file_code, 'error_message' => $error ) );
			$batch['message_results'][ (int) $row['source_message_id'] ] = array( 'status' => 'error', 'session_id' => $session_id, 'file_code' => $file_code, 'error' => $error );
			return false;
		}

		STI_Channel_Index::update( $row['id'], array(
			'file_code' => $file_code,
			'file_name' => $sdata['file_name'],
			'file_type' => $sdata['file_type'],
			'session_id' => $session_id,
			'button_type' => $button['type'],
			'button_url' => $button['url'],
			'bot_username' => $button['bot'],
			'bot_payload' => $button['payload'] ?: $file_code,
			'button_data' => $button['data'],
			'status' => $queued ? STI_Channel_Index::VALIDATED : STI_Channel_Index::WAITING_FILE,
			'error_message' => '',
		) );

		$batch['message_results'][ (int) $row['source_message_id'] ] = array(
			'status' => 'imported',
			'session_id' => $session_id,
			'file_code' => $file_code,
			'image' => $image_ready ? 'yes' : 'no',
			'file' => $file_ready ? 'downloaded' : ( $queued ? 'waiting_bot' : 'none' ),
			'error' => '',
		);

		$session = STI_Session::get( $session_id );
		if ( $session && STI_Session::is_complete( $session ) && ! $queued ) {
			$this->finalize_import_session( $session, $batch['id'] );
		}
		return true;
	}

	protected function make_search_queue_item( $row, $code, $session_id, $press_type, $press_data, $target, $peer ) {
		return array(
			'index_id' => (int) $row['id'],
			'code' => $code,
			'session_id' => (int) $session_id,
			'msg_id' => (int) $row['source_message_id'],
			'press_type' => $press_type,
			'press_data' => (string) $press_data,
			'press_target' => (string) $target,
			'press_peer' => (int) $peer,
			'pressed' => false,
			'press_ts' => 0,
			'attempts' => 0,
			'repress' => 0,
			'error' => '',
		);
	}

	protected function process_mtproto_batch( $batch_id, $batch ) {
		$mt = STI_MTProto::instance();

		$batch['status']     = 'running';
		$batch['updated_at'] = current_time( 'mysql' );
		$this->save_batch( $batch_id, $batch );

		$stage = (string) ( $batch['stage'] ?? 'collect' );

		/* ── resolve کانال (هر بار مطمئن شو) ── */
		$peer = (int) ( $batch['chat_id'] ?? 0 );
		if ( ! $peer ) {
			$identifier = ! empty( $batch['invite_hash'] ) ? 'https://t.me/+' . $batch['invite_hash'] : $batch['username'];
			$info = $mt->chat_info( $identifier );

			if ( is_wp_error( $info ) ) {
				$batch['status']     = 'error';
				$batch['last_error'] = 'خطا در پیدا کردن کانال: ' . $info->get_error_message();
				$batch['updated_at'] = current_time( 'mysql' );
				$this->save_batch( $batch_id, $batch );
				return $batch;
			}

			$peer = (int) $info['id'];
			$batch['chat_id']       = $peer;
			$batch['channel_title'] = $info['title'];
			$this->save_batch( $batch_id, $batch );
		}

		$tmp_dir = $this->mtproto_tmp_dir();

		if ( 'collect' === $stage ) {
			$batch = $this->mt_stage_collect( $batch, $mt, $peer, $tmp_dir );
		} elseif ( 'press' === $stage ) {
			$batch = $this->mt_stage_press( $batch, $mt, $peer );
		} elseif ( 'wait' === $stage ) {
			$batch = $this->mt_stage_wait( $batch, $mt, $tmp_dir );
		}

		/* ── تکمیل امن: اگر مرحله done است ولی status هنوز running مانده، کامل کن ──
		   (جلوگیری از «۱۰۰٪ ولی در حال اجرا» که همیشه رفرش می‌شد) ── */
		if ( 'running' === ( $batch['status'] ?? '' ) && 'done' === ( $batch['stage'] ?? '' ) ) {
			$batch['status'] = 'completed';
		}

		$batch['updated_at'] = current_time( 'mysql' );
		$this->save_batch( $batch_id, $batch );

		/* ── زمان‌بندی چانک بعدی ── */
		if ( 'running' === ( $batch['status'] ?? '' ) ) {
			// فاصله‌ی بین چانک‌ها تصادفی (رفتار انسانی): wait = ۱۵-۳۰ ثانیه، بقیه = ۳-۷ ثانیه
			if ( 'wait' === ( $batch['stage'] ?? '' ) ) {
				$delay = wp_rand( 5, 9 );
			} else {
				$delay = wp_rand( 2, 4 );
			}
			$this->schedule_worker( $batch_id, $delay );
		}

		STI_Logger::info( 'Channel Import: MTProto — چانک تمام شد — batch=' . $batch_id . ', stage=' . ( $batch['stage'] ?? '?' ) . ', scanned=' . ( $batch['total_scanned'] ?? 0 ) . ', imported=' . ( $batch['imported'] ?? 0 ) . ', status=' . ( $batch['status'] ?? '?' ) );

		return $batch;
	}

	/**
	 * جستجوی بازگشتی در reply_markup (هر ساختار، هر عمق) برای پیدا کردن دکمه‌ی
	 * لینک به ربات: t.me/<Bot>?start=<payload> یا t.me/<Bot>.
	 * روی آرایه‌ی decode‌شده کار می‌کند — نه روی JSON رشته — پس مشکل escape
	 * اسلش‌ها (\/) اصلاً وجود ندارد.
	 *
	 * @param mixed $markup  reply_markup خام.
	 * @return array|null  ['bot'=>…, 'payload'=>…] یا null.
	 */
	public function find_bot_button_in_markup( $markup ) {
		if ( ! is_array( $markup ) ) {
			return null;
		}
		$found = null;
		array_walk_recursive( $markup, function ( $value, $key ) use ( &$found ) {
			if ( $found ) {
				return;
			}
			if ( 'url' === $key && is_string( $value ) && preg_match( '#t\.me/([A-Za-z0-9_]{3,64})(?:\?start=([A-Za-z0-9_\-]*))?#i', $value, $m ) ) {
				$found = array( 'bot' => $m[1], 'payload' => isset( $m[2] ) ? $m[2] : '' );
			}
		} );
		return $found;
	}

	/**
	 * مرحله‌ی ۱: collect — خواندن تاریخچه و ساخت سشن‌ها.
	 * عکس پیام → تصویر شاخص. فایل ضمیمه → فایل محصول. دکمه‌ها → صف برای مرحله‌ی press.
	 */
	protected function mt_stage_collect( $batch, $mt, $peer, $tmp_dir ) {
		$category_id = (int) ( $batch['category_id'] ?? 0 );

		$remaining = (int) $batch['desired_count'] - (int) $batch['imported'];
		$limit     = min( self::MT_CHUNK_LIMIT, max( 2, $remaining + 2 ) );

		$history = $mt->get_history( $peer, $limit, (int) ( $batch['next_offset_id'] ?? 0 ) );

		if ( is_wp_error( $history ) ) {
			$batch['status']     = 'error';
			$batch['last_error'] = 'خطا در خواندن تاریخچه: ' . $history->get_error_message();
			return $batch;
		}

		$messages = $history['messages'];

		if ( empty( $messages ) ) {
			// انتهای تاریخچه — برو به مرحله‌ی فشار دکمه یا پایان
			$batch['collect_done'] = true;
			$queue = (array) ( $batch['file_queue'] ?? array() );
			if ( empty( $queue ) ) {
				$batch['stage']  = 'done';
				$batch['status'] = ( (int) $batch['imported'] > 0 ) ? 'completed' : 'completed';
			} else {
				$batch['stage'] = 'press';
			}
			return $batch;
		}

		$queue = (array) ( $batch['file_queue'] ?? array() );

		foreach ( $messages as $m ) {
			$mid  = (int) $m['id'];
			$text = (string) $m['text'];

			$batch['total_scanned']++;

			/* ── استخراج File Code ── */
			$parsed    = STI_Caption_Parser::parse( $text );
			$file_code = trim( (string) ( $parsed['file_code'] ?? '' ) );

			if ( ! $file_code ) {
				// Include button URL/start payload and filename; many channel posts
				// do not repeat the code in the visible caption.
				$file_code = $this->extract_mt_file_code( $m );
			}

			if ( ! $file_code ) {
				$batch['message_results'][ $mid ] = array(
					'status'    => 'no_file_code',
					'file_code' => '',
				);
				continue;
			}

			if ( ! empty( $batch['fallback_history'] ) && ! $this->has_category_search_evidence( $category_id, $this->message_search_blob( $m ) ) ) {
				$batch['message_results'][ $mid ] = array( 'status' => 'no_category', 'file_code' => $file_code, 'error' => 'عبارت دسته در پیام پیدا نشد.' );
				continue;
			}

			/* ── ترتیب صحیح (طبق مشخصات):
			 * ۱) استخراج File Code (بالا)
			 * ۲) بررسی تکراری بودن (محصول / پیش‌نویس / صف / سشن فعال)
			 * ۳) بررسی AutoCat (امتیاز دسته‌ی انتخابی + تطابق main)
			 * ۴) فقط بعد از این دو، session ساخته و به ربات ارسال می‌شود
			 */
			if ( $this->is_duplicate( $file_code ) ) {
				$batch['duplicates_skipped']++;
				$batch['message_results'][ $mid ] = array(
					'status'    => 'skipped_duplicate',
					'file_code' => $file_code,
				);
				STI_Logger::info( "Channel Import: پیام {$mid} تکراری — file_code={$file_code}" );
				continue;
			}

			/* ── دسته‌ی این پیام — سیستم اتوکت (هوشمند)، از طریق متد مشترک ── */
			$autocat_title = trim( $text . ' ' . ( $m['file_name'] ?? '' ) . ' ' . ( $parsed['file_type'] ?? '' ) );
			$autocat_type  = trim( (string) ( $parsed['file_type'] ?? '' ) . ' ' . ( $m['file_name'] ?? '' ) );

			$autocat_check = $this->evaluate_autocat_match( $autocat_title, $autocat_type, $category_id );

			if ( ! $autocat_check['allowed'] ) {
				STI_Logger::info( "AutoCat: پیام {$mid} رد شد — " . $autocat_check['reason'] );
				$batch['message_results'][ $mid ] = array(
					'status'    => 'no_category',
					'file_code' => $file_code,
					'error'     => $autocat_check['reason'],
				);
				continue;
			}

			$msg_category = (int) $autocat_check['category_id'];
			if ( ! $msg_category ) {
				$batch['message_results'][ $mid ] = array(
					'status'    => 'no_category',
					'file_code' => $file_code,
					'error'     => 'اتوکت دسته‌ای تشخیص نداد',
				);
				continue;
			}

			/* ── ساخت session ── */
			$session_id = STI_Session::create( $peer, null, $msg_category );
			if ( $session_id ) { STI_Session::update( $session_id, array( 'notify_chat_id' => 0 ) ); }
			if ( ! $session_id ) {
				$batch['message_results'][ $mid ] = array(
					'status'    => 'session_failed',
					'file_code' => $file_code,
				);
				continue;
			}

			$sdata = array(
				'file_code'    => $file_code,
				'caption_raw'  => $text,
				'doc_file_name'=> (string) ( $m['file_name'] ?? '' ),
			);
			if ( ! empty( $parsed['file_name'] ) ) { $sdata['file_name'] = $parsed['file_name']; }
			if ( ! empty( $parsed['file_type'] ) ) { $sdata['file_type'] = $parsed['file_type']; }
			if ( ! empty( $parsed['source_url'] ) ) { $sdata['source_url'] = $parsed['source_url']; }

			if ( empty( $sdata['file_name'] ) && ! empty( $m['file_name'] ) ) { $sdata['file_name'] = $m['file_name']; }
			if ( empty( $sdata['file_type'] ) && ! empty( $m['file_name'] ) ) {
				$ext = strtoupper( ltrim( (string) pathinfo( $m['file_name'], PATHINFO_EXTENSION ), '.' ) );
				if ( $ext ) { $sdata['file_type'] = $ext; }
			}

			STI_Session::update( $session_id, $sdata );

			$file_stored = false;
			$image_ok    = false;
			$media_note  = 'none';
			$file_error  = '';

			/* ── رسانه‌ی ضمیمه: عکس → تصویر، داکیومنت → فایل ── */
			$early_button = $this->extract_mt_button( $m );
			if ( 'none' !== $m['media_type'] && STI_Settings::get( 'mtproto_auto_download', 1 ) && ( 'photo' === $m['media_type'] || ! in_array( $early_button['type'], array( 'callback', 'start', 'direct' ), true ) ) ) {
				$media_note = $m['media_type'];
				$dl = $mt->download_media( $m, $tmp_dir );

				if ( ! is_wp_error( $dl ) ) {
					if ( 'photo' === $m['media_type'] ) {
						// عکس کانال: مستقیماً به مدیا لایبرری وردپرس (نه FTP) — سریع‌تر و بی‌خطا
						$att_id = STI_File_Storage::store_image_from_local_file(
							$dl['path'],
							$sdata['file_name'] ?? $file_code,
							$dl['name'] ?? ( 'photo_' . $mid . '.jpg' )
						);
						if ( ! is_wp_error( $att_id ) && $att_id ) {
							$att_url = wp_get_attachment_url( $att_id );
							if ( $att_url ) {
								STI_Session::update( $session_id, array(
									'image_url'     => $att_url,
									'image_file_id' => (string) $att_id, // ذخیره Attachment ID برای استفاده‌ی مستقیم در Product Builder
								) );
								$image_ok = true;
							} else {
								// حتی اگر URL نگرفتیم، Attachment ID کافی است
								STI_Session::update( $session_id, array( 'image_file_id' => (string) $att_id ) );
								$image_ok = true;
							}
						} else {
							// Fallback: روش قبلی (FTP)
							$stored = $this->store_downloaded_file( $dl, $file_code, $sdata['file_name'] ?? '', $msg_category, $session_id );
							if ( $stored && ! empty( $stored['url'] ) ) {
								STI_Session::update( $session_id, array( 'image_url' => $stored['url'] ) );
								$image_ok = true;
							} else {
								$file_error = 'ذخیره‌سازی تصویر روی هاست ناموفق بود';
							}
						}
					} else {
						$stored = $this->store_downloaded_file( $dl, $file_code, $sdata['file_name'] ?? '', $msg_category, $session_id );
						if ( $stored && ! empty( $stored['url'] ) ) {
							STI_Session::update( $session_id, array(
								'download_url_final' => $stored['url'],
								'file_size_bytes'    => $stored['size_bytes'] ?? $dl['size'],
							) );
							$file_stored = true;
						} else {
							$file_error = 'ذخیره‌سازی فایل روی هاست ناموفق بود (لاگ را ببینید)';
						}
					}
				} else {
					$file_error = 'دانلود رسانه از تلگرام ناموفق: ' . $dl->get_error_message();
				}
			}

			/* ── دکمه‌ی دانلود → صف مرحله‌ی press ──
			 * ماتریس استراتژی بر اساس نوع دکمه (ساختار استاندارد تلگرام):
			 *   keyboardButtonCallback (data)  → getBotCallbackAnswer (فشار دکمه)
			 *   keyboardButtonUrl → t.me/Bot?start=…  → باز کردن گفتگوی ربات با /start <payload>
			 *   keyboardButtonUrl → t.me/Bot (بدون start) → /start + کد فایل به‌عنوان payload
			 *   keyboardButtonUrl → لینک عادی        → لینک دانلود مستقیم
			 *   keyboardButtonSwitchInline (query) → قابل اتوماسیون نیست → لاگ کامل
			 *   keyboardButtonWebApp / نامشخص       → لاگ کامل + خطای واضح
			 */
			$queued_button = false;
			$markup_json = '';
			if ( ! empty( $m['raw']['reply_markup'] ) ) {
				// JSON_UNESCAPED_SLASHES ضروری است: پیش‌فرض json_encode اسلش‌ها را
				// به \/ تبدیل می‌کند و regex روی لینک‌های t.me کار نمی‌کند!
				$markup_json = wp_json_encode( $m['raw']['reply_markup'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}

			foreach ( ( STI_Settings::get( 'mtproto_press_buttons', 1 ) ? ( $m['buttons'] ?? array() ) : array() ) as $b ) {
				$btype = (string) ( $b['type'] ?? '' );
				$burl  = (string) ( $b['url'] ?? '' );

				/* ۱) دکمه‌ی کالبک */
				if ( '' !== (string) ( $b['data'] ?? '' ) ) {
					$queue[] = array(
						'code'        => $file_code,
						'session_id'  => $session_id,
						'msg_id'      => $mid,
						'press_type'  => 'callback',
						'press_data'  => (string) $b['data'],
						'press_target'=> '',
						'pressed'     => false,
						'press_ts'    => 0,
						'attempts'    => 0,
						'error'       => '',
					);
					$queued_button = true;
					break;
				}

				/* ۲) دکمه‌ی URL به ربات — الگوی Fileech: t.me/FileechBot?start=CODE */
				if ( '' !== $burl && preg_match( '#(?:t\.me|telegram\.me)/([A-Za-z0-9_]{3,64})(?:\?start=([A-Za-z0-9_\-]*))?#i', $burl, $u ) ) {
					$payload = (string) ( $u[2] ?? '' );
					if ( '' === $payload ) {
						$payload = $file_code; // دکمه بدون payload → خودِ کد فایل
					}
					$queue[] = array(
						'code'        => $file_code,
						'session_id'  => $session_id,
						'msg_id'      => $mid,
						'press_type'  => 'start',
						'press_data'  => $payload,
						'press_target'=> (string) $u[1],
						'pressed'     => false,
						'press_ts'    => 0,
						'attempts'    => 0,
						'error'       => '',
					);
					$queued_button = true;
					STI_Logger::info( 'Channel Import: MTProto — دکمه‌ی URL به ربات ' . $u[1] . ' با start=' . $payload . ' (msg=' . $mid . ', code=' . $file_code . ')' );
					break;
				}

				/* ۳) لینک مستقیم */
				if ( '' !== $burl && ! $file_stored ) {
					STI_Session::update( $session_id, array( 'download_url_raw' => $burl ) );
					$file_stored = true;
					$file_error  = '';
					break;
				}

				/* ۴) انواع غیرقابل اتوماسیون — لاگ کامل */
				STI_Logger::warning( 'Channel Import: MTProto — نوع دکمه‌ی پشتیبانی‌نشده: ' . $btype . ' (msg=' . $mid . ') — کل reply_markup: ' . mb_substr( $markup_json, 0, 800 ) );
			}

			/* ── لایه‌ی امنیتی دوم: پیمایش بازگشتی روی reply_markup خام ──
			   اگر پارس ساختیافته چیزی پیدا نکرد، در هر عمق و هر ساختاری
			   (MTProto یا Bot API) دنبال t.me/<Bot>?start=<CODE> می‌گردد. ── */
			if ( STI_Settings::get( 'mtproto_press_buttons', 1 ) && ! $queued_button && ! $file_stored && ! empty( $m['raw']['reply_markup'] ) ) {
				$bot_btn = $this->find_bot_button_in_markup( $m['raw']['reply_markup'] );
				if ( $bot_btn ) {
					$payload = (string) ( $bot_btn['payload'] ?? '' );
					if ( '' === $payload ) {
						$payload = $file_code;
					}
					$queue[] = array(
						'code'        => $file_code,
						'session_id'  => $session_id,
						'msg_id'      => $mid,
						'press_type'  => 'start',
						'press_data'  => $payload,
						'press_target'=> (string) $bot_btn['bot'],
						'pressed'     => false,
						'press_ts'    => 0,
						'attempts'    => 0,
						'error'       => '',
					);
					$queued_button = true;
					STI_Logger::info( 'Channel Import: MTProto — دکمه از reply_markup خام پیدا شد (recursive fallback): bot=' . $bot_btn['bot'] . ', start=' . $payload . ' (msg=' . $mid . ')' );
				}
			}

			if ( ! $queued_button && ! $file_stored ) {
				// دیاگنوستیک کامل: ساختار خام reply_markup
				STI_Logger::warning( 'Channel Import: MTProto — پیام ' . $mid . ' (کد ' . $file_code . ') دکمه‌ی قابل‌تشخیص ندارد! reply_markup کامل: ' . mb_substr( $markup_json, 0, 1000 ) );
				$file_error = 'دکمه‌ی دانلود در پیام شناسایی نشد — نوع دکمه: ' . $markup_json;
			}

			$batch['message_results'][ $mid ] = array(
				'status'     => 'imported',
				'session_id' => $session_id,
				'file_code'  => $file_code,
				'media'      => $media_note,
				'image'      => $image_ok ? 'yes' : 'no',
				'file'       => $file_stored ? 'downloaded' : ( $queued_button ? 'waiting_bot' : 'none' ),
				'error'      => $file_error,
			);

			$batch['imported']++;

			/* ── ساخت محصول اگر همین حالا کامل است (فایل ضمیمه داشت) ── */
			$session = STI_Session::get( $session_id );
			if ( $session && STI_Session::is_complete( $session ) ) {
				$this->finalize_import_session( $session, $batch_id );
			} elseif ( $queued_button ) {
				STI_Logger::info( 'Channel Import: MTProto — سشن #' . $session_id . ' در انتظار فایل از ربات — code=' . $file_code );
			}

			/* ── رسیدن به تعداد خواسته‌شده؟ ── */
			if ( $batch['imported'] >= (int) $batch['desired_count'] ) {
				break;
			}
		}

		$batch['file_queue'] = $queue;

		// آخرین offset برای چانک بعدی collect
		if ( ! empty( $messages ) ) {
			$last = end( $messages );
			$batch['next_offset_id'] = (int) $last['id'];
		}

		/* ── اگر به تعداد خواسته رسیدیم یا تاریخچه تمام شد → press ── */
		if ( (int) $batch['imported'] >= (int) $batch['desired_count'] ) {
			$batch['collect_done'] = true;
		}
		if ( ! empty( $batch['collect_done'] ) ) {
			if ( empty( $queue ) ) {
				$batch['stage']  = 'done';
				$batch['status'] = 'completed';
			} else {
				$batch['stage'] = 'press';
			}
		}

		return $batch;
	}

	/**
	 * مرحله‌ی ۲: press — فشار دادن دکمه‌های دانلود (کالبک) یا باز کردن گفتگوی ربات.
	 * با تأخیر انسانی بیشتر و محدودیت تعداد در هر چانک برای جلوگیری از FLOOD_WAIT.
	 */
	protected function mt_stage_press( $batch, $mt, $peer ) {
		$queue = (array) ( $batch['file_queue'] ?? array() );
		$pressed_any   = false;
		$pressed_count = 0;
		$max_per_chunk = self::MT_PRESS_PER_CHUNK;

		// احترام به FLOOD_WAIT قبلی
		$flood_until = (int) ( $batch['flood_wait_until'] ?? 0 );
		if ( $flood_until > time() ) {
			STI_Logger::info( 'Channel Import: MTProto — منتظر پایان FLOOD_WAIT تا ' . date( 'H:i:s', $flood_until ) );
			// به wait برو اگر موردی pending هست، وگرنه همین‌جا بمان
			$has_pending = false;
			foreach ( $queue as $it ) {
				if ( ! empty( $it['pressed'] ) && empty( $it['error'] ) ) {
					$has_pending = true;
					break;
				}
			}
			if ( $has_pending ) {
				$batch['stage'] = 'wait';
			}
			return $batch;
		}

		// v7: صف باریک — چند فایل هم‌زمان در انتظار نگذار
		$already_waiting = 0;
		foreach ( $queue as $it ) {
			if ( ! empty( $it['pressed'] ) && empty( $it['error'] ) ) { $already_waiting++; }
		}
		$max_per_chunk = min( $max_per_chunk, max( 0, self::MT_MAX_CONCURRENT_WAIT - $already_waiting ) );

		foreach ( $queue as &$item ) {
			if ( ! empty( $item['pressed'] ) || ! empty( $item['error'] ) ) {
				continue;
			}
			if ( $pressed_count >= $max_per_chunk ) {
				break;
			}

			// تأخیر انسانی — برای start طولانی‌تر تا ربات rate-limit نشود
			$is_start = ( 'start' === ( $item['press_type'] ?? '' ) );
			$this->human_delay( $is_start ? 3.5 : 2.0, $is_start ? 8.0 : 5.0 );

			$ok = false;
			$err_msg = '';

			if ( 'callback' === ( $item['press_type'] ?? '' ) ) {
				$answer = $mt->press_button( $peer, (int) $item['msg_id'], (string) $item['press_data'] );
				if ( is_array( $answer ) || ( is_object( $answer ) && ! is_wp_error( $answer ) ) ) {
					$ok = true;
				} elseif ( is_wp_error( $answer ) ) {
					// بعضی بات‌ها پاسخ کالبک نمی‌دهند ولی فایل می‌فرستند — به‌عنوان موفقیت نرم حساب کن
					$err_low = mb_strtolower( $answer->get_error_message() );
					if ( false !== strpos( $err_low, 'query_id_invalid' )
						|| false !== strpos( $err_low, 'bot_response_timeout' )
						|| false !== strpos( $err_low, 'timeout' ) ) {
						$ok = true; // دکمه احتمالاً زده شده
						STI_Logger::info( 'Channel Import: MTProto — callback soft-ok (ربات پاسخ نداد ولی احتمالاً فایل می‌فرستد) — code=' . ( $item['code'] ?? '' ) );
					} else {
						$err_msg = $answer->get_error_message();
					}
				} else {
					$err_msg = 'خطای نامشخص در فشار دکمه';
				}
			} elseif ( 'start' === ( $item['press_type'] ?? '' ) ) {
				$result = $mt->start_bot_dialog( (string) $item['press_target'], (string) $item['press_data'] );
				if ( ! is_wp_error( $result ) ) {
					$ok = true;
				} else {
					$err_msg = $result->get_error_message();
					// FLOOD_WAIT — صبر کن و در چانک بعد دوباره
					if ( preg_match( '/FLOOD_WAIT[_\s]*(\d+)/i', $err_msg, $fm ) ) {
						$wait_sec = max( 5, (int) $fm[1] );
						STI_Logger::warning( 'Channel Import: MTProto — FLOOD_WAIT ' . $wait_sec . 's — توقف فشار تا چانک بعد' );
						$batch['flood_wait_until'] = time() + $wait_sec;
						break;
					}
				}
			} else {
				$item['error'] = 'نوع دکمه‌ی ناشناخته: ' . ( $item['press_type'] ?? '' );
				STI_Session::mark_error( (int) $item['session_id'], $item['error'] );
				continue;
			}

			if ( $ok ) {
				$item['pressed']  = true;
				$item['press_ts'] = time();
				$item['repress']  = (int) ( $item['repress'] ?? 0 );
				$pressed_any = true;
				$pressed_count++;
				if ( ! empty( $item['index_id'] ) && class_exists( 'STI_Channel_Index' ) ) {
					STI_Channel_Index::update( (int) $item['index_id'], array( 'status' => STI_Channel_Index::WAITING_FILE, 'error_message' => '' ) );
				}
				STI_Logger::info( 'Channel Import: MTProto — دکمه/start زده شد — type=' . ( $item['press_type'] ?? '' ) . ', code=' . ( $item['code'] ?? '' ) . ', msg=' . ( $item['msg_id'] ?? 0 ) );
			} else {
				$item['attempts'] = (int) ( $item['attempts'] ?? 0 ) + 1;
				if ( $item['attempts'] >= 3 ) {
					$item['error'] = 'فشار دکمه ناموفق پس از ۳ تلاش: ' . $err_msg;
					STI_Session::mark_error( (int) $item['session_id'], $item['error'] );
					if ( ! empty( $item['index_id'] ) && class_exists( 'STI_Channel_Index' ) ) {
						STI_Channel_Index::update( (int) $item['index_id'], array( 'status' => STI_Channel_Index::ERROR, 'error_message' => $item['error'] ) );
					}
				}
				// وگرنه در چانک بعد دوباره امتحان می‌شود
			}
		}
		unset( $item );

		$batch['file_queue'] = $queue;

		// آیا موردی برای press باقی مانده؟
		$still_to_press = false;
		$has_pending_wait = false;
		foreach ( $queue as $item ) {
			if ( empty( $item['pressed'] ) && empty( $item['error'] ) ) {
				$still_to_press = true;
			}
			if ( ! empty( $item['pressed'] ) && empty( $item['error'] ) ) {
				$has_pending_wait = true;
			}
		}

		// اگر حداقل چند تا pressed شده، همزمان wait را شروع کن (pipeline)
		// تا فایل‌های زودرس از دست نروند؛ بقیه در چانک‌های بعد press می‌شوند.
		if ( $has_pending_wait && ( ! $still_to_press || $pressed_any ) ) {
			if ( ! $still_to_press ) {
				// همه press شدند → فقط wait
				$batch['stage'] = 'wait';
			} else {
				// هنوز press باقی است ولی چند تا pressed → برو wait، بعداً به press برگرد
				$batch['stage'] = 'wait';
				$batch['press_remaining'] = true;
			}
			// deadline سراسری فقط به‌عنوان سقف کلی؛ هر آیتم deadline خودش را دارد
			$batch['fetch_deadline'] = max(
				(int) ( $batch['fetch_deadline'] ?? 0 ),
				time() + self::fetch_timeout_seconds()
			);
		} elseif ( ! $still_to_press && ! $has_pending_wait ) {
			$batch['stage']  = 'done';
			$batch['status'] = 'completed';
		}

		return $batch;
	}

	/**
	 * مرحله‌ی ۳: wait — انتظار برای فایل‌های ربات، تطبیق با کد، دانلود و ساخت محصول.
	 * - timeout به‌ازای هر آیتم از press_ts
	 * - یک‌بار (یا بیشتر تا MT_MAX_REPRESS) re-press قبل از error نهایی
	 * - اگر هنوز press باقی مانده، بعد از پردازش به stage=press برگرد
	 */
	protected function mt_stage_wait( $batch, $mt, $tmp_dir ) {
		$queue = (array) ( $batch['file_queue'] ?? array() );
		$peer  = null; // برای re-press در صورت نیاز

		// فقط مواردی که دکمه‌شان زده شده و خطا ندارند در انتظارند
		$waiting = array();
		$earliest_ts = PHP_INT_MAX;
		foreach ( $queue as $item ) {
			if ( ! empty( $item['pressed'] ) && empty( $item['error'] ) ) {
				$waiting[] = $item;
				$pts = (int) ( $item['press_ts'] ?? 0 );
				if ( $pts > 0 ) {
					$earliest_ts = min( $earliest_ts, $pts );
				}
			}
		}

		if ( empty( $waiting ) ) {
			// شاید هنوز press باقی مانده
			if ( ! empty( $batch['press_remaining'] ) ) {
				$batch['stage'] = 'press';
				$batch['press_remaining'] = false;
				return $batch;
			}
			$batch['stage']  = 'done';
			$batch['status'] = 'completed';
			return $batch;
		}

		if ( PHP_INT_MAX === $earliest_ts ) {
			$earliest_ts = time() - 60;
		}
		// کمی عقب‌تر بگیر تا فایل‌هایی که دقیقاً همزمان رسیده‌اند از دست نروند
		$since = max( 0, $earliest_ts - 30 );

		/* ── جستجوی فایل‌های جدید از ربات ── */
		/* ── ۱) هر فایلی که دیده می‌شود فوراً در صندوق ورودی پایدار ثبت شود ── */
		$docs = $mt->find_recent_documents( $since, self::fetch_timeout_seconds() + 600 );
		if ( class_exists( 'STI_Bot_Inbox' ) ) {
			$recorded = STI_Bot_Inbox::record_many( $docs );
			if ( $recorded > 0 ) {
				STI_Logger::info( 'Channel Import: ' . $recorded . ' فایل جدید در صندوق ورودی ثبت شد.' );
			}
		}

		$processed = 0;
		$got_any   = false;
		$inbox_since = max( 0, $since - 3600 );

		/* ── ۲) تطبیق قطعی: برای هر آیتمِ در انتظار، فایل با همان کد را از صندوق بردار ── */
		foreach ( $queue as $qi => $qitem ) {
			if ( $processed >= self::MT_FILES_PER_CHUNK ) { break; }
			if ( empty( $qitem['pressed'] ) || ! empty( $qitem['error'] ) ) { continue; }
			if ( ! class_exists( 'STI_Bot_Inbox' ) ) { break; }

			$code = (string) ( isset( $qitem['code'] ) ? $qitem['code'] : '' );
			$session_id = (int) ( isset( $qitem['session_id'] ) ? $qitem['session_id'] : 0 );
			if ( ! $session_id ) { continue; }

			$row = $code ? STI_Bot_Inbox::find_for_code( $code, $inbox_since ) : null;
			// Search-first batches are fail-closed: never attach an unlabelled or
			// merely similar file to a product. The legacy history strategy keeps
			// its compatibility fallbacks for old installations.
			if ( ! $row && 'mtproto_search' !== ( $batch['strategy'] ?? '' ) ) {
				$row = $this->match_inbox_by_similarity( $qitem, $inbox_since );
				if ( ! $row && 1 === $this->count_waiting( $queue ) ) {
					$free = STI_Bot_Inbox::unclaimed( $inbox_since, 1 );
					if ( ! empty( $free ) ) { $row = $free[0]; }
				}
			}
			if ( ! $row ) { continue; }

			$batch_ref = (string) ( isset( $batch['id'] ) ? $batch['id'] : '' );
			if ( ! STI_Bot_Inbox::claim( (int) $row['id'], $session_id, $batch_ref ) ) { continue; }

			$doc = STI_Bot_Inbox::payload( $row );
			if ( empty( $doc ) ) { STI_Bot_Inbox::mark( (int) $row['id'], 'ignored' ); continue; }

			$dl = $mt->download_media_robust( $doc, $tmp_dir );
			if ( is_wp_error( $dl ) ) {
				STI_Bot_Inbox::release( (int) $row['id'] );
				$fails = (int) ( isset( $queue[ $qi ]['dl_fails'] ) ? $queue[ $qi ]['dl_fails'] : 0 ) + 1;
				$queue[ $qi ]['dl_fails'] = $fails;
				if ( $fails >= 5 ) {
					$queue[ $qi ]['error'] = 'دانلود فایل ربات ناموفق: ' . $dl->get_error_message();
					STI_Session::mark_error( $session_id, $queue[ $qi ]['error'] );
				}
				STI_Logger::warning( 'Channel Import: دانلود ناموفق code=' . $code . ' — ' . $dl->get_error_message() );
				$processed++;
				continue;
			}

			$session = STI_Session::get( $session_id );
			$cat_id  = $session ? (int) $session->category_id : 0;
			$fname   = $session ? (string) $session->file_name : '';
			$stored  = $this->store_downloaded_file( $dl, $code, $fname, $cat_id, $session_id );

			if ( $stored && ! empty( $stored['url'] ) ) {
				$size = isset( $stored['size_bytes'] ) ? $stored['size_bytes'] : ( isset( $dl['size'] ) ? $dl['size'] : 0 );
				STI_Session::update( $session_id, array(
					'download_url_final' => $stored['url'],
					'file_size_bytes'    => $size,
				) );
				STI_Bot_Inbox::mark( (int) $row['id'], 'downloaded' );
				if ( ! empty( $qitem['index_id'] ) && class_exists( 'STI_Channel_Index' ) ) {
					STI_Channel_Index::update( (int) $qitem['index_id'], array( 'status' => STI_Channel_Index::DOWNLOADED, 'inbox_id' => (int) $row['id'], 'session_id' => $session_id, 'error_message' => '' ) );
				}
				$got_any = true;
				$batch['files_downloaded'] = (int) ( $batch['files_downloaded'] ?? 0 ) + 1;
				STI_Logger::success( 'Channel Import: فایل کد ' . $code . ' دریافت و ذخیره شد — session #' . $session_id );

				$session = STI_Session::get( $session_id );
				if ( $session && STI_Session::is_complete( $session ) ) {
					$built = $this->finalize_import_session( $session, $batch_ref );
					if ( ! empty( $qitem['index_id'] ) && class_exists( 'STI_Channel_Index' ) ) {
						STI_Channel_Index::update( (int) $qitem['index_id'], array( 'status' => $built ? STI_Channel_Index::PRODUCT_CREATED : STI_Channel_Index::ERROR, 'error_message' => $built ? '' : 'ساخت محصول ناموفق بود.' ) );
					}
				}
				$mid = (int) ( isset( $qitem['msg_id'] ) ? $qitem['msg_id'] : 0 );
				if ( $mid && isset( $batch['message_results'][ $mid ] ) ) {
					$batch['message_results'][ $mid ]['file']  = 'downloaded';
					$batch['message_results'][ $mid ]['error'] = '';
				}
				unset( $queue[ $qi ] );
				$queue = array_values( $queue );
				$processed++;
				break;
			}

			STI_Bot_Inbox::release( (int) $row['id'] );
			$fails2 = (int) ( isset( $queue[ $qi ]['dl_fails'] ) ? $queue[ $qi ]['dl_fails'] : 0 ) + 1;
			$queue[ $qi ]['dl_fails'] = $fails2;
			if ( $fails2 >= 5 ) {
				$queue[ $qi ]['error'] = 'ذخیره‌سازی فایل روی هاست ناموفق بود';
				STI_Session::mark_error( $session_id, 'ذخیره‌سازی فایل دریافتی از ربات ناموفق بود.' );
			}
			$processed++;
		}

		/* ── timeout / re-press به‌ازای هر آیتم ── */
		$now = time();
		$need_repress = false;
		foreach ( $queue as &$item ) {
			if ( empty( $item['pressed'] ) || ! empty( $item['error'] ) ) {
				continue;
			}
			$pts = (int) ( $item['press_ts'] ?? 0 );
			if ( $pts <= 0 ) {
				$item['press_ts'] = $now;
				continue;
			}
			$age = $now - $pts;

			/*
			 * v7 — نردبان تشدید تلاش: قبل از تمام شدن وقت آیتم، مسیرهای جایگزین امتحان
			 * می‌شوند. بعضی ربات‌ها به کالبک جواب نمی‌دهند ولی با پیام مستقیم /start CODE
			 * فایل را می‌فرستند (و برعکس). هر پله وقت تازه به آیتم می‌دهد.
			 */
			if ( $age >= self::MT_ESCALATE_AFTER ) {
				$step   = (int) ( isset( $item['escalated'] ) ? $item['escalated'] : 0 );
				$code   = (string) ( isset( $item['code'] ) ? $item['code'] : '' );
				$target = trim( (string) ( isset( $item['press_target'] ) ? $item['press_target'] : '' ) );
				if ( '' === $target && class_exists( 'STI_File_Hunter' ) ) {
					$bots = STI_File_Hunter::known_bots();
					if ( ! empty( $bots ) ) { $target = (string) $bots[0]; }
				}
				$peer_id = (int) ( isset( $batch['chat_id'] ) ? $batch['chat_id'] : 0 );

				if ( 0 === $step && $target && $code ) {
					$item['escalated'] = 1;
					$item['press_ts']  = $now;
					$payload = ! empty( $item['press_data'] ) ? (string) $item['press_data'] : $code;
					$mt->start_bot_dialog( $target, $payload );
					STI_Logger::info( 'واردات: تشدید ۱ — پیام /start به ' . $target . ' برای کد ' . $code );
					continue;
				}
				if ( 1 === $step && $target && $code ) {
					$item['escalated'] = 2;
					$item['press_ts']  = $now;
					$mt->start_bot_dialog( $target, $code );
					STI_Logger::info( 'واردات: تشدید ۲ — ارسال کد خام ' . $code . ' به ' . $target );
					continue;
				}
				if ( 2 === $step && $peer_id && ! empty( $item['press_data'] ) && 'callback' === ( isset( $item['press_type'] ) ? $item['press_type'] : '' ) ) {
					$item['escalated'] = 3;
					$item['press_ts']  = $now;
					$mt->press_button( $peer_id, (int) $item['msg_id'], (string) $item['press_data'] );
					STI_Logger::info( 'واردات: تشدید ۳ — فشار دوباره‌ی دکمه‌ی کالبک برای کد ' . $code );
					continue;
				}
			}

			if ( $age < self::fetch_timeout_seconds() ) {
				continue;
			}

			// زمان این آیتم تمام شده
			$repress = (int) ( $item['repress'] ?? 0 );
			if ( $repress < self::MT_MAX_REPRESS ) {
				// re-press: reset و برگرد به صف press
				$item['pressed']  = false;
				$item['press_ts'] = 0;
				$item['repress']  = $repress + 1;
				$item['attempts'] = 0;
				$need_repress = true;
				STI_Logger::info( 'Channel Import: MTProto — re-press #' . $item['repress'] . ' برای code=' . ( $item['code'] ?? '' ) . ' (timeout ' . $age . 's)' );
			} else {
				$item['error'] = 'فایل از ربات دریافت نشد پس از ' . ( $repress + 1 ) . ' تلاش (زمان انتظار تمام شد).';
				STI_Session::mark_error(
					(int) $item['session_id'],
					'فایل از ربات دریافت نشد (زمان انتظار تمام شد). دکمه‌ی دانلود زده شد ولی ربات فایل را نفرستاد.'
				);
				$mid = (int) ( $item['msg_id'] ?? 0 );
				if ( $mid ) {
					$batch['message_results'][ $mid ]['error'] = 'فایل از ربات دریافت نشد (timeout)';
				}
			}
		}
		unset( $item );

		$batch['file_queue'] = $queue;

		// اگر فایلی گرفتیم، deadline کلی را تمدید کن
		if ( $got_any ) {
			$batch['fetch_deadline'] = max(
				(int) ( $batch['fetch_deadline'] ?? 0 ),
				time() + self::fetch_timeout_seconds()
			);
		}

		/* ── وضعیت صف ── */
		$still_waiting = 0;
		$still_to_press = 0;
		foreach ( $queue as $item ) {
			if ( ! empty( $item['error'] ) ) {
				continue;
			}
			if ( empty( $item['pressed'] ) ) {
				$still_to_press++;
			} else {
				$still_waiting++;
			}
		}

		if ( $need_repress || $still_to_press > 0 || ! empty( $batch['press_remaining'] ) ) {
			$batch['stage'] = 'press';
			$batch['press_remaining'] = ( $still_to_press > 0 );
			return $batch;
		}

		if ( 0 === $still_waiting ) {
			$batch['stage']  = 'done';
			$batch['status'] = 'completed';
			return $batch;
		}

		// هنوز waiting — در wait بمان
		$batch['stage'] = 'wait';
		return $batch;
	}

	/**
	 * تطبیق یک فایل دریافتی از ربات با یک کدِ در انتظار.
	 * اول کد داخل کپشن فایل (اگر باشد) با کدهای صف تطبیق داده می‌شود؛
	 * اگر نبود، اولین کدِ در انتظار (FIFO — به ترتیب فشار دکمه‌ها) انتخاب می‌شود.
	 *
	 * @param array $doc    پیام فایل نرمال‌شده.
	 * @param array $queue  صف آیتم‌های در انتظار (by reference نمی‌شود).
	 * @return int  ایندکس در صف یا -1.
	 */
	/** تعداد آیتم‌هایی که دکمه‌شان زده شده و منتظر فایل هستند. */
	public function count_waiting( $queue ) {
		$n = 0;
		foreach ( (array) $queue as $it ) {
			if ( ! empty( $it['pressed'] ) && empty( $it['error'] ) ) { $n++; }
		}
		return $n;
	}

	/**
	 * تطبیق درجه ۲ — وقتی ربات کد فایل را در کپشن/نام نمی‌گذارد.
	 * نام فایل ربات را با عنوان انتظار مقایسه می‌کند (اشتراک توکن‌ها).
	 */
	public function match_inbox_by_similarity( $qitem, $since_ts ) {
		if ( ! class_exists( 'STI_Bot_Inbox' ) ) { return null; }
		$session_id = (int) ( isset( $qitem['session_id'] ) ? $qitem['session_id'] : 0 );
		$expected = '';
		if ( $session_id ) {
			$s = STI_Session::get( $session_id );
			if ( $s ) { $expected = (string) $s->file_name; }
		}
		if ( '' === trim( $expected ) ) { return null; }

		$want = $this->similarity_tokens( $expected );
		if ( count( $want ) < 2 ) { return null; }

		$best = null;
		$best_score = 0.0;
		foreach ( STI_Bot_Inbox::unclaimed( $since_ts, 40 ) as $row ) {
			$blob = (string) $row['file_name'] . ' ' . (string) $row['caption'];
			$have = $this->similarity_tokens( $blob );
			if ( empty( $have ) ) { continue; }
			$common = count( array_intersect( $want, $have ) );
			$score = $common / max( 1, count( $want ) );
			if ( $score > $best_score ) { $best_score = $score; $best = $row; }
		}
		return ( $best_score >= 0.6 ) ? $best : null;
	}

	protected function similarity_tokens( $text ) {
		$t = mb_strtolower( (string) $text );
		$t = preg_replace( '/\.[a-z0-9]{2,5}$/u', ' ', $t );
		$t = preg_replace( '/[^a-z0-9\x{0600}-\x{06FF}]+/u', ' ', $t );
		$parts = preg_split( '/\s+/u', trim( $t ) );
		$out = array();
		foreach ( (array) $parts as $w ) {
			if ( mb_strlen( $w ) < 3 ) { continue; }
			if ( preg_match( '/^[0-9]+$/', $w ) ) { continue; }
			$out[ $w ] = true;
		}
		return array_keys( $out );
	}

	public function mt_match_doc_to_queue( $doc, $queue ) {
		$doc_text = (string) ( $doc['text'] ?? '' );
		$candidates = array(); // کدهای استخراج‌شده از کپشن/نام فایل

		if ( $doc_text ) {
			$parsed = STI_Caption_Parser::parse( $doc_text );
			$c = trim( (string) ( $parsed['file_code'] ?? '' ) );
			if ( $c ) {
				$candidates[] = $c;
			}
			$c2 = (string) $this->extract_file_code_from_text( $doc_text );
			if ( $c2 && ! in_array( $c2, $candidates, true ) ) {
				$candidates[] = $c2;
			}
			// همه اعداد ۴+ رقمی داخل متن
			if ( preg_match_all( '/(?<!\d)(\d{5,})(?!\d)/', $doc_text, $mm ) ) {
				foreach ( $mm[1] as $num ) {
					if ( ! in_array( $num, $candidates, true ) ) {
						$candidates[] = $num;
					}
				}
			}
		}

		// کد داخل نام فایل (مثل Magnific_415467254.zip → 415467254)
		if ( ! empty( $doc['file_name'] ) && preg_match_all( '/(\d{5,})/', (string) $doc['file_name'], $fm ) ) {
			foreach ( $fm[1] as $num ) {
				if ( ! in_array( $num, $candidates, true ) ) {
					$candidates[] = $num;
				}
			}
		}

		// تطبیق دقیق با کد صف — اولویت مطلق
		if ( ! empty( $candidates ) ) {
			foreach ( $queue as $i => $item ) {
				if ( empty( $item['pressed'] ) || ! empty( $item['error'] ) ) {
					continue;
				}
				$code = (string) ( $item['code'] ?? '' );
				if ( $code && in_array( $code, $candidates, true ) ) {
					return $i;
				}
			}
		}

		// Fallback FIFO فقط وقتی هیچ کدی در فایل پیدا نشد
		// (و فقط یک مورد در صف باشد تا اشتباه نسبت داده نشود)
		$waiting_indices = array();
		foreach ( $queue as $i => $item ) {
			if ( ! empty( $item['pressed'] ) && empty( $item['error'] ) ) {
				$waiting_indices[] = $i;
			}
		}
		if ( 1 === count( $waiting_indices ) && empty( $candidates ) ) {
			return $waiting_indices[0];
		}

		// اگر چند مورد waiting و کد نداریم → تطبیق نکن (جلوگیری از فایل اشتباه)
		return -1;
	}

	protected function store_downloaded_file( $dl, $file_code, $file_name, $category_id, $session_id ) {
		if ( empty( $dl['path'] ) || ! file_exists( $dl['path'] ) ) {
			STI_Logger::error( "Channel Import: فایل موقت وجود ندارد — path=" . ( $dl['path'] ?? 'empty' ) . " — session={$session_id}" );
			return false;
		}

		// لاگ سایز و نام برای دیباگ
		$size = STI_Security::safe_file_size( $dl['path'] );
		STI_Logger::info( "Channel Import: شروع ذخیره فایل — code={$file_code} name={$file_name} size={$size} path={$dl['path']} — session={$session_id}" );

		$category = $category_id ? STI_Category::get( $category_id ) : null;

		$file_meta = array(
			'file_code'       => $file_code,
			'file_name'       => $file_name,
			'original_name'   => $dl['name'] ?? basename( $dl['path'] ),
			'category_folder' => $category
				? ( $category->folder_key ?: STI_Category::sanitize_folder_key( $category->telegram_label, $category->id ) )
				: '',
		);

		$storage_mode = $category ? STI_Category::storage_mode( $category ) : null;

		// تلاش ۱: مسیر اصلی (remote یا local بر اساس تنظیمات)
		$result = STI_File_Storage::process_local_temp_file( $dl['path'], $file_meta, $storage_mode );

		// تلاش ۲: اگر remote بود و شکست خورد، مستقیم local
		if ( is_wp_error( $result ) ) {
			STI_Logger::warning( "Channel Import: ذخیره اصلی ناموفق — {$result->get_error_message()} — تلاش fallback محلی — session={$session_id}" );
			$local_fallback = STI_File_Storage::process_local_temp_file( $dl['path'], $file_meta, 'local' );
			if ( ! is_wp_error( $local_fallback ) ) {
				$result = $local_fallback;
				STI_Logger::info( "Channel Import: fallback محلی موفق — session={$session_id}" );
			}
		}

		// تلاش ۳: fallback نهایی — کپی مستقیم به uploads/sti-files/fallback بدون اعتبارسنجی سخت‌گیرانه
		if ( is_wp_error( $result ) ) {
			STI_Logger::warning( "Channel Import: fallback محلی هم ناموفق — {$result->get_error_message()} — تلاش ultimate copy — session={$session_id}" );
			$ultimate = self::ultimate_local_copy( $dl['path'], $file_meta );
			if ( ! is_wp_error( $ultimate ) ) {
				$result = $ultimate;
				STI_Logger::success( "Channel Import: ultimate copy موفق — url={$ultimate['url']} — session={$session_id}" );
			}
		}

		// پاکسازی فایل موقت اصلی (اگر ultimate کپی کرده باشد، فایل اصلی هنوز هست)
		@unlink( $dl['path'] );

		if ( is_wp_error( $result ) ) {
			STI_Logger::error( 'Channel Import: ذخیره‌سازی نهایی ناموفق — ' . $result->get_error_message() . " — session={$session_id} code={$file_code}" );
			return false;
		}

		if ( empty( $result['url'] ) ) {
			STI_Logger::error( "Channel Import: URL نهایی خالی است — session={$session_id}" );
			return false;
		}

		return array(
			'url'        => $result['url'] ?? '',
			'size_bytes' => $result['size_bytes'] ?? ( $dl['size'] ?? null ),
		);
	}

	/**
	 * آخرین راه‌حل: کپی مستقیم فایل به wp-content/uploads/sti-files/fallback/YYYY/MM
	 * بدون چک سخت‌گیرانه پسوند — همیشه باید موفق شود مگر دیسک پر باشد
	 */
	protected static function ultimate_local_copy( $tmp_path, $meta ) {
		if ( ! file_exists( $tmp_path ) ) {
			return new WP_Error( 'sti_no_tmp', 'فایل موقت یافت نشد برای ultimate copy' );
		}
		$upload_dir = wp_upload_dir();
		$base = trailingslashit( $upload_dir['basedir'] ) . 'sti-files/fallback/' . date( 'Y/m' );
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		$ext = pathinfo( $meta['original_name'] ?? $tmp_path, PATHINFO_EXTENSION );
		$ext = $ext ? '.' . preg_replace( '/[^a-zA-Z0-9]/', '', $ext ) : '.zip';
		$code = sanitize_file_name( $meta['file_code'] ?? uniqid() );
		$name_part = ! empty( $meta['file_name'] ) ? sanitize_title( $meta['file_name'] ) : 'file';
		$name_part = mb_substr( $name_part, 0, 40 );
		$filename = $name_part . '-' . $code . $ext;

		$dest_path = trailingslashit( $base ) . $filename;
		if ( ! @copy( $tmp_path, $dest_path ) ) {
			// تلاش با نام منحصر به فرد
			$filename = uniqid( 'sti_' ) . '-' . $code . $ext;
			$dest_path = trailingslashit( $base ) . $filename;
			if ( ! @copy( $tmp_path, $dest_path ) ) {
				return new WP_Error( 'sti_ultimate_copy_failed', 'کپی نهایی به fallback ناموفق بود' );
			}
		}

		$rel = 'sti-files/fallback/' . date( 'Y/m' ) . '/' . $filename;
		$url = trailingslashit( $upload_dir['baseurl'] ) . $rel;

		return array(
			'url' => $url,
			'path' => $dest_path,
			'size_bytes' => STI_Security::safe_file_size( $dest_path ),
		);
	}

	/* ======================================================================
	   SECTION 5c: WORKER (پردازش پس‌زمینه)
	   ====================================================================== */

	/**
	 * زمان‌بندی اجرای یک چانک پردازش برای batch.
	 *
	 * @param string $batch_id
	 * @param int    $delay  ثانیه تا اجرا.
	 */
	public function schedule_worker( $batch_id, $delay = 2 ) {
		if ( wp_next_scheduled( 'sti_ci_worker', array( $batch_id ) ) ) {
			return; // قبلاً زمان‌بندی شده
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), 'sti_ci_worker', array( $batch_id ) );
	}

	/**
	 * Dispatch پردازش یک چانک — هم برای WP-Cron (sti_ci_worker) و هم برای
	 * دکمه‌ی «پردازش فوری» در پنل.
	 *
	 * @param string $batch_id
	 */
	public function process_batch_chunk( $batch_id ) {
		$batch = $this->get_batch( $batch_id );
		if ( ! $batch ) {
			return;
		}

		$status = $batch['status'] ?? '';
		if ( ! in_array( $status, array( 'queued', 'running' ), true ) ) {
			return;
		}

		// جلوگیری از اجرای هم‌زمان (WP-Cron + پردازش فوری)
		if ( get_transient( 'sti_ci_lock_' . $batch_id ) ) {
			$this->schedule_worker( $batch_id, 15 ); // بعداً دوباره امتحان کن
			return;
		}
		set_transient( 'sti_ci_lock_' . $batch_id, 1, 5 * MINUTE_IN_SECONDS );

		@set_time_limit( 120 );

		try {
			if ( 'mtproto_search' === ( $batch['strategy'] ?? '' ) ) {
				$this->process_mtproto_search_batch( $batch_id, $batch );
			} elseif ( self::STRATEGY_MT === ( $batch['strategy'] ?? '' ) ) {
				$this->process_mtproto_batch( $batch_id, $batch );
			} elseif ( 'scrape' === ( $batch['strategy'] ?? '' ) ) {
				$this->process_scrape_batch( $batch_id, $batch );
			}
		} catch ( \Throwable $e ) {
			STI_Logger::error( 'Channel Import: خطای پردازش batch ' . $batch_id . ' — ' . $e->getMessage() );
			$batch['status']     = 'error';
			$batch['last_error'] = $e->getMessage();
			$batch['updated_at'] = current_time( 'mysql' );
			$this->save_batch( $batch_id, $batch );
		}

		delete_transient( 'sti_ci_lock_' . $batch_id );
	}

	/**
	 * اجرای فوری چانک‌ها — برای هاست‌هایی که WP-Cron کار نمی‌کند (DISABLE_WP_CRON).
	 * در بازدید صفحه‌ی Channel Import یا کلیک روی «پردازش فوری» صدا زده می‌شود.
	 *
	 * @param int $budget_seconds  حداکثر زمان (ثانیه).
	 * @param int $max_batches     حداکثر تعداد batch در هر بار.
	 * @return int  تعداد batch پردازش‌شده.
	 */
	public function pump_workers_inline( $budget_seconds = 20, $max_batches = 2, $force = false ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return 0;
		}

		$start  = microtime( true );
		$ran    = 0;
		$batches = $this->get_batches();

		foreach ( $batches as $b ) {
			if ( $ran >= $max_batches ) {
				break;
			}
			if ( ! in_array( ( $b['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
				continue;
			}
			// اگر همین الان پردازش شده (کمتر از ۳ ثانیه پیش)، رد شو — قبلاً ۱۰ بود و
			// با رفرش خودکار ۶ ثانیه‌ای صفحه، بعضی batch ها هرگز پردازش نمی‌شدند.
			$updated = $this->batch_timestamp( $b['updated_at'] ?? '' );
			$age = $updated ? ( time() - $updated ) : 999;
			// current_time('mysql') is site-local while PHP strtotime() may use
			// the server timezone. Never let a future-looking timestamp block a
			// batch forever; only skip a genuinely recent update.
			if ( ! $force && $age >= 0 && $age < 3 ) {
				continue;
			}

			$this->process_batch_chunk( $b['id'] );
			$ran++;

			if ( ( microtime( true ) - $start ) > $budget_seconds ) {
				break;
			}
		}

		return $ran;
	}

	/** Parse a WordPress site-local MySQL timestamp without timezone drift. */
	protected function batch_timestamp( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return 0; }
		try {
			$dt = date_create_from_format( 'Y-m-d H:i:s', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * تأخیر انسانی تصادفی بین عملیات‌های تلگرام (فشار دکمه، جستجوی فایل و…).
	 * هدف: رفتار شبیه کاربر واقعی — نه ربات — تا Flood/محدودیت تلگرام و
	 * الگوهای تشخیص ربات فعال نشوند. در حالت تست، تأخیر صفر است.
	 *
	 * @param float $min
	 * @param float $max
	 */
	protected function human_delay( $min = 2.0, $max = 5.0 ) {
		if ( defined( 'STI_CI_TEST_MODE' ) && STI_CI_TEST_MODE ) {
			return;
		}
		$delay = $min + ( ( $max - $min ) * ( wp_rand( 0, 1000 ) / 1000 ) );
		usleep( (int) ( $delay * 1000000 ) );
	}

	/** پوشه‌ی موقت دانلود MTProto (داخل پوشه‌ی محافظت‌شده‌ی sti-mtproto). */
	protected function mtproto_tmp_dir() {
		$dir = STI_MTProto::base_dir() . '/tmp';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.php', "<?php // silence is golden\n" );
		}
		return $dir;
	}

	/** ساخت یک batch خطا (برای استراتژی‌های غیرقابل استفاده). */
	protected function strategy_error( $message ) {
		STI_Logger::warning( 'Channel Import: ' . $message );
		return array(
			'strategy'  => 'error',
			'status'    => 'error',
			'last_error'=> $message,
			'imported'  => 0,
			'message'   => $message,
		);
	}

	/* ======================================================================
	   SECTION 5d: URL PARSING & CHANNEL DETECTION
	   ====================================================================== */

	/**
	 * ذخیره‌ی یک batch (جایگزین تکراری update_option).
	 *
	 * @param string $batch_id
	 * @param array  $batch
	 */
	public function save_batch( $batch_id, $batch ) {
		$batches = $this->get_batches();
		$batches[ $batch_id ] = $batch;
		update_option( self::BATCH_OPTION_KEY, $batches, false );
	}

	/**
	 * پیدا کردن batch فعال موجود برای یک کانال+دسته (جلوگیری از درخواست تکراری).
	 *
	 * @param string $chat_username
	 * @param int    $category_id
	 * @param string $strategy
	 * @return array|null
	 */
	public function find_active_batch_for( $chat_username, $category_id, $strategy = '' ) {
		$parsed = $this->parse_chat_identifier( $chat_username );
		$username = strtolower( $parsed['username'] );
		$invite   = strtolower( (string) ( $parsed['invite_hash'] ?? '' ) );

		$active_statuses = array( 'queued', 'running', 'awaiting_forward' );

		foreach ( $this->get_batches() as $b ) {
			if ( ! in_array( ( $b['status'] ?? '' ), $active_statuses, true ) ) {
				continue;
			}
			if ( $strategy && 'auto' !== $strategy && ( $b['strategy'] ?? '' ) !== $strategy ) {
				continue;
			}
			if ( $category_id && (int) ( $b['category_id'] ?? 0 ) !== (int) $category_id ) {
				continue;
			}
			// تطبیق با username یا لینک دعوت
			if ( $invite && strtolower( (string) ( $b['invite_hash'] ?? '' ) ) === $invite ) {
				return $b;
			}
			if ( $username && strtolower( (string) ( $b['username'] ?? '' ) ) === $username ) {
				return $b;
			}
		}

		return null;
	}

	/**
	 * پارس هر ورودی کانال به username + message_id:
	 *
	 *   FileechParty
	 *   @FileechParty
	 *   t.me/FileechParty
	 *   t.me/s/FileechParty
	 *   t.me/FileechParty/60301
	 *   t.me/s/FileechParty/60301
	 *   https://telegram.me/FileechParty/158602
	 *   t.me/+abcXYZ123 (لینک دعوت کانال خصوصی)
	 *
	 * توجه: در t.me/s/ نباید @ گذاشت (t.me/s/@User نامعتبر است).
	 *
	 * @param string $input
	 * @return array  ['username','message_id','is_join_link','invite_hash','raw']
	 */
	public function parse_chat_identifier( $input ) {
		$input = trim( (string) $input );

		$result = array(
			'username'    => '',
			'message_id'  => 0,
			'is_join_link'=> false,
			'invite_hash' => '',
			'raw'         => $input,
		);

		if ( '' === $input ) {
			return $result;
		}

		// لینک دعوت خصوصی: t.me/+hash
		if ( preg_match( '#(?:^|/)\+([A-Za-z0-9_\-]{5,})#', $input, $m ) ) {
			$result['is_join_link'] = true;
			$result['invite_hash']  = $m[1];
			return $result;
		}

		// حذف پروتکل/دامنه
		$clean = preg_replace( '#^https?://(?:www\.)?(?:t\.me|telegram\.me|telegram\.dog)/?#i', '', $input );
		$clean = preg_replace( '#^t\.me/?#i', '', $clean );

		// حذف s/ (پیش‌نمایش وب) — t.me/s/User یا t.me/s/User/123
		$clean = preg_replace( '#^s/#i', '', $clean );

		// حذف کوئری
		$clean = preg_replace( '#\?.*$#', '', $clean );

		// جدا کردن message_id انتهایی: …/123
		if ( preg_match( '#^([A-Za-z0-9_]{3,32})/(\d{1,10})$#', $clean, $m ) ) {
			$result['message_id'] = (int) $m[2];
			$clean = $m[1];
		}

		$clean = ltrim( $clean, '@' );
		$clean = rtrim( $clean, '/' );

		// اعتبارسنجی username (الگوی استاندارد تلگرام)
		if ( preg_match( '/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $clean ) ) {
			$result['username'] = $clean;
		}

		return $result;
	}

	/**
	 * بررسی وضعیت وب یک کانال عمومی/خصوصی از روی صفحه‌ی t.me/s.
	 *
	 * @param string $username
	 * @return array|null  ['public'=>bool,'title'=>string,'members'=>int,'preview_posts'=>int] یا null اگر قابل تشخیص نبود.
	 */
	public function get_channel_web_info( $username ) {
		$username = ltrim( (string) $username, '@' );
		if ( ! $username ) {
			return null;
		}

		$response = self::human_http_get( 'https://t.me/s/' . $username, 2 );

		if ( ! $response || empty( $response['body'] ) ) {
			return null;
		}

		$html = $response['body'];

		// عنوان
		$title = '';
		if ( preg_match( '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$title = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		// تعداد عضو
		$members = 0;
		if ( preg_match( '/([0-9][0-9\s.,]{0,12})\s*members?/i', $html, $m ) ) {
			$members = (int) preg_replace( '/[^0-9]/', '', $m[1] );
		}

		// تعداد پیام‌های پیش‌نمایش
		$preview_posts = substr_count( $html, 'data-post=' );

		// صفحه‌ی نامعتبر (یوزرنیم پیدا نشد)
		if ( false !== strpos( $html, 'tgme_page_not_found' ) || false !== strpos( $html, 'tgme_page_unknown' ) ) {
			return array(
				'public'       => false,
				'title'        => $title,
				'members'      => $members,
				'preview_posts'=> 0,
				'not_found'    => true,
			);
		}

		// کانال عمومی با پیش‌نمایش واقعی پیام‌ها
		if ( false !== strpos( $html, 'tgme_widget_message' ) && $preview_posts > 0 ) {
			return array(
				'public'       => true,
				'title'        => $title,
				'members'      => $members,
				'preview_posts'=> $preview_posts,
				'not_found'    => false,
			);
		}

		// کانال خصوصی: اطلاعات کانال هست ولی هیچ پیامی نمایش داده نمی‌شود
		return array(
			'public'       => false,
			'title'        => $title,
			'members'      => $members,
			'preview_posts'=> 0,
			'not_found'    => false,
		);
	}

	/**
	 * تست کامل اتصال به کانال — وب + اکانت شخصی (MTProto).
	 * برای دکمه‌ی «تست دسترسی» در پنل.
	 *
	 * @param string $input
	 * @return array
	 */
	public function test_connection( $input ) {
		$parsed = $this->parse_chat_identifier( $input );

		$result = array(
			'parsed'   => $parsed,
			'web'      => null,
			'mtproto'  => null,
			'strategy' => 'manual', // scrape | mtproto | manual
			'message'  => '',
		);

		// لینک دعوت = کانال خصوصی
		if ( $parsed['is_join_link'] ) {
			$result['message'] = 'این یک لینک دعوت (کانال خصوصی) است؛ اسکرپینگ وب ممکن نیست.';

			if ( STI_MTProto::is_configured() ) {
				$state = STI_MTProto::instance()->auth_state();
				if ( 'logged_in' === $state ) {
					$info = STI_MTProto::instance()->chat_info( $input );
					if ( ! is_wp_error( $info ) ) {
						$result['mtproto']  = $info;
						$result['strategy'] = self::STRATEGY_MT;
						$result['message']  = '✅ کانال خصوصی با اکانت شخصی پیدا شد: «' . $info['title'] . '» (' . number_format_i18n( $info['members'] ) . ' عضو). واردات با MTProto ممکن است.';
					} else {
						$result['message'] .= ' خطای MTProto: ' . $info->get_error_message();
					}
				} else {
					$result['message'] .= ' اکانت شخصی تنظیم است ولی ورود انجام نشده — از «تنظیمات تلگرام» وارد شوید.';
				}
			} else {
				$result['message'] .= ' برای واردات باید اکانت شخصی تلگرام (MTProto) تنظیم شود.';
			}
			return $result;
		}

		if ( ! $parsed['username'] ) {
			$result['message'] = 'آدرس کانال نامعتبر است. فرمت‌های درست: FileechParty ، @FileechParty ، t.me/FileechParty ، t.me/FileechParty/60301';
			return $result;
		}

		/* ── بررسی وب ── */
		$web = $this->get_channel_web_info( $parsed['username'] );
		$result['web'] = $web;

		if ( $web ) {
			if ( ! empty( $web['not_found'] ) ) {
				$result['message'] = '❌ یوزرنیم @' . $parsed['username'] . ' در تلگرام پیدا نشد.';
				return $result;
			}
			if ( $web['public'] ) {
				$test_id = $parsed['message_id'] ?: $this->get_latest_scrapable_id( $parsed['username'] );
				if ( $test_id && $test_id > 0 ) {
					$result['strategy'] = 'scrape';
					$result['message']  = '✅ کانال عمومی است: «' . ( $web['title'] ?: '@' . $parsed['username'] ) . '»' . ( $web['members'] ? '، ' . number_format_i18n( $web['members'] ) . ' عضو' : '' ) . ' — آخرین پیام قابل اسکن: ' . $test_id . ' — اسکرپینگ ممکن است.';
				} else {
					$result['strategy'] = 'scrape';
					$result['message']  = '⚠️ کانال عمومی است ولی آخرین Message ID پیدا نشد؛ Topic ID را دستی وارد کنید.';
				}
			} else {
				$result['message'] = '🔒 کانال @' . $parsed['username'] . ' خصوصی است — در وب هیچ پیامی نمایش داده نمی‌شود (همان خطای قبلی شما). برای واردات باید اکانت شخصی (MTProto) استفاده شود.';
			}
		} else {
			$result['message'] = 'وضعیت کانال از روی وب قابل تشخیص نبود (ممکن است تلگرام موقتاً در دسترس نباشد).';
		}

		/* ── بررسی MTProto ── */
		if ( STI_MTProto::is_configured() ) {
			$state = STI_MTProto::instance()->auth_state();
			if ( 'logged_in' === $state ) {
				$info = STI_MTProto::instance()->chat_info( $parsed['username'] );
				if ( ! is_wp_error( $info ) ) {
					$result['mtproto'] = $info;
					if ( 'scrape' !== $result['strategy'] || ( $web && ! $web['public'] ) ) {
						$result['strategy'] = self::STRATEGY_MT;
						$result['message']  = '✅ کانال با اکانت شخصی پیدا شد: «' . $info['title'] . '» (' . $info['type'] . ( $info['members'] ? '، ' . number_format_i18n( $info['members'] ) . ' عضو' : '' ) . ') — واردات کامل (تاریخچه + دانلود فایل) ممکن است.';
					}
				} else {
					$result['mtproto_error'] = $info->get_error_message();
				}
			} else {
				$result['mtproto_state'] = $state;
			}
		}

		return $result;
	}

	/* ======================================================================
	   SECTION 5e: AUTO CATEGORY DETECTION
	   ====================================================================== */

	/**
	 * تشخیص خودکار دسته‌بندی از روی کپشن پیام.
	 *
	 * روش‌ها به ترتیب:
	 *  ۱) File Type (مثل TTF/PSD/AI) با telegram_label یا folder_key دسته‌ها مقایسه می‌شود
	 *  ۲) کلمات کلیدی telegram_label دسته‌ها داخل متن کپشن جستجو می‌شوند
	 *  ۳) برچسب Site (مثل #magnific) در کپشن با telegram_label مقایسه می‌شود
	 *
	 * @param string $caption   متن کپشن.
	 * @param string $file_type نوع فایل (اختیاری — از parser).
	 * @return int|false  شناسه‌ی دسته یا false اگر تشخیص داده نشد.
	 */
	public function detect_category( $caption, $file_type = '' ) {
		$categories = STI_Category::get_all();
		if ( empty( $categories ) ) {
			return false;
		}

		$caption_raw = (string) $caption;
		$caption  = mb_strtolower( $caption_raw );
		$file_type_raw = (string) $file_type;
		$file_type = mb_strtolower( $file_type_raw );

		// استخراج پسوند از file_type اگر شامل نام فایل باشد (مثلاً flying...mp4)
		if ( preg_match( '/\.([a-z0-9]{2,5})(\s|$)/i', $file_type_raw, $em ) ) {
			$file_type = mb_strtolower( $em[1] ) . ' ' . $file_type;
		}

		// ── روش ۱: تطبیق File Type با telegram_label / folder_key ──
		if ( $file_type ) {
			// کلمات کلیدی پایه
			$type_keywords = array( $file_type );
			// جدا کردن کلمات داخل file_type (مثلاً "Video Background" → ["video","background"])
			$parts = preg_split( '/[^a-z0-9]+/i', $file_type );
			if ( $parts ) {
				$type_keywords = array_merge( $type_keywords, $parts );
			}

			// نگاشت جامع
			$ext_map = array(
				// فونت
				'ttf'    => array( 'فونت', 'font' ),
				'otf'    => array( 'فونت', 'font' ),
				'woff'   => array( 'فونت', 'font' ),
				'woff2'  => array( 'فونت', 'font' ),
				'eot'    => array( 'فونت', 'font' ),
				'font'   => array( 'فونت', 'font' ),
				// PSD / Mockup
				'psd'    => array( 'psd', 'لایه‌باز', 'photoshop', 'mockup', 'موکاپ', 'قالب', 'بنر' ),
				// وکتور
				'ai'     => array( 'وکتور', 'vector', 'illustrator', 'لوگو' ),
				'eps'    => array( 'وکتور', 'vector', 'لوگو' ),
				'cdr'    => array( 'وکتور', 'vector', 'corel' ),
				'svg'    => array( 'وکتور', 'vector', 'آیکون', 'icon' ),
				'vector' => array( 'وکتور', 'vector' ),
				// آیکون
				'icon'   => array( 'آیکون', 'icon', 'svg' ),
				// موکاپ
				'mockup' => array( 'موکاپ', 'mockup', 'psd' ),
				// پترن / تکسچر
				'pattern'=> array( 'پترن', 'pattern' ),
				'texture'=> array( 'تکسچر', 'texture', 'بافت' ),
				'template'=> array( 'قالب', 'template' ),
				// ویدئو / موشن
				'mp4'    => array( 'موشن', 'motion', 'ویدئو', 'video', 'افترافکت', 'after', 'premiere' ),
				'mov'    => array( 'موشن', 'motion', 'ویدئو', 'video' ),
				'avi'    => array( 'موشن', 'motion', 'ویدئو', 'video' ),
				'mkv'    => array( 'موشن', 'motion', 'ویدئو', 'video' ),
				'video'  => array( 'موشن', 'motion', 'ویدئو', 'video' ),
				'motion' => array( 'موشن', 'motion', 'ویدئو', 'video', 'افترافکت' ),
				'after'  => array( 'موشن', 'motion', 'افترافکت' ),
				'ae'     => array( 'موشن', 'motion', 'افترافکت' ),
				// سه‌بعدی
				'3d'     => array( 'سه‌بعدی', '3d', 'blender', 'cinema', 'fbx', 'obj' ),
				'fbx'    => array( 'سه‌بعدی', '3d' ),
				'obj'    => array( 'سه‌بعدی', '3d' ),
				'blend'  => array( 'سه‌بعدی', '3d' ),
				// عکس
				'jpg'    => array( 'عکس', 'photo' ),
				'jpeg'   => array( 'عکس', 'photo' ),
				'png'    => array( 'عکس', 'لوگو', 'آیکون' ),
				'webp'   => array( 'عکس' ),
			);

			// افزودن کلمات کلیدی از نگاشت
			$extra = array();
			foreach ( $ext_map as $key => $words ) {
				if ( false !== mb_strpos( $file_type, $key ) ) {
					$extra = array_merge( $extra, $words );
				}
			}
			$type_keywords = array_merge( $type_keywords, $extra );
			$type_keywords = array_unique( array_filter( $type_keywords ) );

			foreach ( $categories as $cat ) {
				$label = mb_strtolower( (string) $cat->telegram_label );
				$key   = mb_strtolower( (string) ( $cat->folder_key ?? '' ) );
				$needle = $label . ' ' . $key;

				foreach ( $type_keywords as $kw ) {
					$kw = mb_strtolower( (string) $kw );
					if ( mb_strlen( $kw ) < 2 ) { continue; }
					// تطبیق دو طرفه
					if ( false !== mb_strpos( $needle, $kw ) || false !== mb_strpos( $kw, $needle ) ) {
						return (int) $cat->id;
					}
					// تطبیق جزئی برای کلمات انگلیسی (مثلاً vector داخل caption)
					if ( mb_strlen( $kw ) >= 3 && false !== mb_strpos( $caption, $kw ) ) {
						// اگر کلمه داخل کپشن هم بود، دسته را برگردان
						if ( false !== mb_strpos( $label, $kw ) || false !== mb_strpos( $key, $kw ) ) {
							return (int) $cat->id;
						}
					}
				}
			}
		}

		// ── روش ۲: جستجوی نام دسته در متن کپشن (دقیق‌تر) ──
		// اولویت با دسته‌های با نام بلندتر (مثلاً "موکاپ" قبل از "مو")
		$sorted_cats = $categories;
		usort( $sorted_cats, function( $a, $b ) {
			return mb_strlen( $b->telegram_label ) <=> mb_strlen( $a->telegram_label );
		});
		foreach ( $sorted_cats as $cat ) {
			$label = mb_strtolower( (string) $cat->telegram_label );
			$label_en = sanitize_title( $label );
			if ( mb_strlen( $label ) >= 2 && false !== mb_strpos( $caption, $label ) ) {
				return (int) $cat->id;
			}
			// اگر folder_key مثل "vector" داخل کپشن بود
			$key = mb_strtolower( (string) ( $cat->folder_key ?? '' ) );
			if ( $key && mb_strlen( $key ) >= 3 && false !== mb_strpos( $caption, $key ) ) {
				return (int) $cat->id;
			}
		}

		// ── روش ۳: جستجوی File Type داخل کپشن (مثلاً "#Vector" یا "File Type: Vector") ──
		if ( preg_match_all( '/#([a-z0-9_]+)/i', $caption_raw, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$tag_low = mb_strtolower( $tag );
				foreach ( $categories as $cat ) {
					$label = mb_strtolower( (string) $cat->telegram_label );
					$key   = mb_strtolower( (string) ( $cat->folder_key ?? '' ) );
					if ( $label === $tag_low || $key === $tag_low ) {
						return (int) $cat->id;
					}
					// تطبیق ناقص برای تگ‌ها
					if ( mb_strlen( $tag_low ) >= 3 && ( false !== mb_strpos( $label, $tag_low ) || false !== mb_strpos( $tag_low, $label ) ) ) {
						return (int) $cat->id;
					}
				}
			}
		}

		// ── روش ۴: تشخیص از روی پسوند نام فایل داخل کپشن ──
		if ( preg_match( '/\.([a-z0-9]{2,5})(?:\s|$)/i', $caption_raw, $m2 ) ) {
			$ext = mb_strtolower( $m2[1] );
			// بازگشت برای تشخیص مجدد با همین پسوند
			if ( in_array( $ext, array( 'mp4','mov','avi','mkv' ), true ) ) {
				// پیدا کردن دسته Motion
				foreach ( $categories as $cat ) {
					$label = mb_strtolower( (string) $cat->telegram_label );
					if ( false !== mb_strpos( $label, 'موشن' ) || false !== mb_strpos( $label, 'motion' ) || false !== mb_strpos( $label, 'ویدئو' ) || false !== mb_strpos( $label, 'video' ) ) {
						return (int) $cat->id;
					}
				}
			}
		}

		return false;
	}

	/* ======================================================================
	   SECTION 6: BATCH MANAGEMENT
	   ====================================================================== */

	/**
	 * شروع یک batch جدید.
	 *
	 * @param string $username    نام کاربری کانال.
	 * @param int    $topic_id    شناسه‌ی topic.
	 * @param int    $count       تعداد پیام.
	 * @param int    $category_id دسته‌بندی.
	 * @param string $label       برچسب.
	 * @return array|false
	 */
	public function start_batch( $username, $topic_id, $count, $category_id, $label ) {
		return $this->import_messages( $username, $topic_id, $count, $category_id, $label );
	}

	/**
	 * دریافت اطلاعات یک batch.
	 *
	 * @param string $batch_id
	 * @return array|null
	 */
	public function get_batch( $batch_id ) {
		$batches = $this->get_batches();
		return isset( $batches[ $batch_id ] ) ? $batches[ $batch_id ] : null;
	}

	/**
	 * دریافت همه‌ی batch ها.
	 *
	 * @return array
	 */
	public function get_batches() {
		$batches = get_option( self::BATCH_OPTION_KEY, array() );
		if ( ! is_array( $batches ) ) {
			return array();
		}
		return $batches;
	}

	/**
	 * لغو یک batch.
	 *
	 * @param string $batch_id
	 * @return bool
	 */
	public function cancel_batch( $batch_id ) {
		$batches = $this->get_batches();
		if ( ! isset( $batches[ $batch_id ] ) ) {
			return false;
		}

		$batches[ $batch_id ]['status']     = 'cancelled';
		$batches[ $batch_id ]['updated_at'] = current_time( 'mysql' );

		$result = update_option( self::BATCH_OPTION_KEY, $batches, false );

		if ( $result ) {
			STI_Logger::info( 'Channel Import: batch لغو شد — id=' . $batch_id );
		}

		return $result;
	}

	/* ======================================================================
	   SECTION 7: PROGRESS TRACKING
	   ====================================================================== */

	/**
	 * به‌روزرسانی داده‌های پیشرفت یک batch.
	 *
	 * @param string $batch_id
	 * @param array  $data       کلید-مقادیر جدید.
	 * @return bool
	 */
	public function update_batch_progress( $batch_id, $data ) {
		$batches = $this->get_batches();
		if ( ! isset( $batches[ $batch_id ] ) ) {
			return false;
		}

		foreach ( $data as $key => $value ) {
			$batches[ $batch_id ][ $key ] = $value;
		}

		$batches[ $batch_id ]['updated_at'] = current_time( 'mysql' );

		return update_option( self::BATCH_OPTION_KEY, $batches, false );
	}

	/**
	 * علامت‌گذاری یک پیام به عنوان import شده در batch.
	 *
	 * @param string $batch_id
	 * @param int    $message_id
	 * @param int    $session_id
	 * @param string $status
	 * @return bool
	 */
	public function mark_message_imported( $batch_id, $message_id, $session_id, $status = 'imported' ) {
		$batches = $this->get_batches();
		if ( ! isset( $batches[ $batch_id ] ) ) {
			return false;
		}

		$batches[ $batch_id ]['message_results'][ $message_id ] = array(
			'status'     => $status,
			'session_id' => (int) $session_id,
		);

		if ( 'imported' === $status ) {
			$batches[ $batch_id ]['imported'] = (int) ( $batches[ $batch_id ]['imported'] ?? 0 ) + 1;
		}

		$batches[ $batch_id ]['updated_at'] = current_time( 'mysql' );

		return update_option( self::BATCH_OPTION_KEY, $batches, false );
	}

	/* ======================================================================
	   SECTION 8: AJAX HANDLERS
	   ====================================================================== */

	/**
	 * بررسی nonce و دسترسی ادمین.
	 */
	protected function check_ajax_nonce() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	/**
	 * AJAX: شروع یک import جدید.
	 */
	public function ajax_start_import() {
		$this->check_ajax_nonce();

		$chat_username = isset( $_POST['chat_username'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_username'] ) ) : '';
		$category_id   = isset( $_POST['category_id'] ) ? (int) $_POST['category_id'] : 0;
		$topic_id      = isset( $_POST['topic_id'] ) ? (int) $_POST['topic_id'] : 0;
		$count         = isset( $_POST['count'] ) ? (int) $_POST['count'] : 10;
		$label         = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$strategy      = isset( $_POST['strategy'] ) ? sanitize_key( wp_unslash( $_POST['strategy'] ) ) : 'auto';

		// ذخیره زمان انتظار فایل از ربات (دقیقه)
		if ( isset( $_POST['fetch_timeout'] ) || isset( $_POST['search_enabled'] ) ) {
			$settings_update = array();
			if ( isset( $_POST['fetch_timeout'] ) ) { $settings_update['ci_fetch_timeout_minutes'] = max( 1, min( 60, (int) $_POST['fetch_timeout'] ) ); }
			if ( isset( $_POST['search_enabled'] ) ) { $settings_update['ci_search_enabled'] = ! empty( $_POST['search_enabled'] ) ? 1 : 0; }
			if ( $settings_update ) { STI_Settings::update( $settings_update ); }
		}

		if ( ! $chat_username ) {
			wp_send_json_error( array( 'message' => 'نام کاربری کانال (مثلاً FileechParty) یا لینک t.me الزامی است.' ) );
		}

		// اتوکت: دسته‌بندی الزامی است برای همه استراتژی‌ها (دیگر گزینه خودکار حذف شد)
		if ( ! $category_id ) {
			wp_send_json_error( array( 'message' => 'دسته‌بندی الزامی است — سیستم اتوکت برای دسته‌بندی هوشمند به دسته پایه نیاز دارد. لطفاً یک دسته انتخاب کنید تا اتوکت دسته نهایی را با دیکشنری عظیم تشخیص دهد.' ) );
		}
		if ( $count < 1 || $count > self::MAX_BATCH_SIZE ) {
			wp_send_json_error( array( 'message' => 'تعداد پیام‌ها باید بین ۱ تا ' . self::MAX_BATCH_SIZE . ' باشد.' ) );
		}

		/* ── جلوگیری از درخواست تکراری: اگر batch فعال برای همین کانال+دسته هست،
		   یکی جدید نساز — همان را برگردان (پیشگیری از «۲ درخواست ثبت شد») ── */
		$existing = $this->find_active_batch_for( $chat_username, $category_id, $strategy );
		if ( $existing ) {
			$strategy_labels2 = array(
				'mtproto_search' => '🔎 جست‌وجوی سروری MTProto',
				self::STRATEGY_MT => '👤 اکانت شخصی تلگرام (MTProto)',
				'scrape'  => '🌐 Web Scraping (کانال عمومی)',
				'bot_api' => '🤖 Bot API (getUpdates)',
				'manual'  => '👤 Manual Forward',
			);
			wp_send_json_success( array(
				'message'  => 'ℹ️ همین الان یک واردات فعال برای این کانال وجود دارد — همان در حال پردازش است (از ساخت درخواست تکراری جلوگیری شد).',
				'batch'    => $existing,
				'strategy' => $strategy_labels2[ $existing['strategy'] ?? '' ] ?? ( $existing['strategy'] ?? '' ),
			) );
		}

		$result = $this->import_messages( $chat_username, $topic_id, $count, $category_id, $label, $strategy );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => 'شروع import ناموفق بود. لاگ‌ها را بررسی کنید.' ) );
		}

		if ( 'error' === ( $result['strategy'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => '❌ ' . ( $result['message'] ?? $result['last_error'] ?? 'خطای ناشناخته' ) ) );
		}

		$strategy_labels = array(
			'mtproto_search' => '🔎 جست‌وجوی سروری MTProto',
			self::STRATEGY_MT => '👤 اکانت شخصی تلگرام (MTProto)',
			'scrape'  => '🌐 Web Scraping (کانال عمومی)',
			'bot_api' => '🤖 Bot API (getUpdates)',
			'manual'  => '👤 Manual Forward (کاربر پیام‌ها را forward می‌کند)',
		);

		$strategy_label = isset( $strategy_labels[ $result['strategy'] ] )
			? $strategy_labels[ $result['strategy'] ]
			: $result['strategy'];

		$auto_note = '';
		if ( ! $category_id && ! empty( $result['strategy'] ) && 'manual' !== $result['strategy'] ) {
			$auto_note = ' دسته‌بندی هر پیام به‌صورت خودکار از روی کپشن تشخیص داده می‌شود.';
		}

		$response = array(
			'message'  => '✅ عملیات import با استراتژی «' . $strategy_label . '» شروع شد.' . $auto_note . ' پردازش در پس‌زمینه انجام می‌شود و پیشرفت همین‌جا به‌روز می‌شود.',
			'batch'    => $result,
		);

		if ( 'manual' === $result['strategy'] && ! empty( $result['instructions'] ) ) {
			$response['instructions'] = $result['instructions'];
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: تست کامل دسترسی به کانال (وب + اکانت شخصی).
	 */
	public function ajax_test_connection() {
		$this->check_ajax_nonce();

		$chat_username = isset( $_POST['chat_username'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_username'] ) ) : '';

		if ( ! $chat_username ) {
			wp_send_json_error( array( 'message' => 'ابتدا آدرس/یوزرنیم کانال را وارد کنید.' ) );
		}

		$result = $this->test_connection( $chat_username );

		wp_send_json_success( array(
			'message'  => $result['message'] ?: 'نتیجه‌ای حاصل نشد.',
			'strategy' => $result['strategy'],
			'web'      => $result['web'],
			'mtproto'  => $result['mtproto'],
			'parsed'   => $result['parsed'],
		) );
	}

	/**
	 * AJAX: پولینگ زنده — یک چانک پردازش می‌کند (اگر batch فعالی هست) و بعد
	 * لیست را برمی‌گرداند. با باز بودن صفحه، واردات بدون نیاز به WP-Cron
	 * جلو می‌رود (سرعت بالا) و پیشرفت هم‌زمان به‌روز می‌شود.
	 */
	public function ajax_poll() {
		$this->check_ajax_nonce();

		$this->pump_workers_inline( 12, 1 );

		$this->ajax_get_all_batches(); // JSON خروجی را می‌فرستد و تمام می‌کند
	}

	/**
	 * AJAX: پردازش فوری چانک‌ها (برای هاست‌هایی که WP-Cron کار نمی‌کند).
	 */
	public function ajax_process_now() {
		$this->check_ajax_nonce();

		$ran = $this->pump_workers_inline( 25, 2, true );

		wp_send_json_success( array(
			'message'   => $ran ? $ran . ' واردات پردازش شد. اگر هنوز در حال اجراست دوباره کلیک کنید.' : 'واردات فعالی برای پردازش نیست.',
			'processed' => $ran,
		) );
	}

	/**
	 * AJAX: دریافت وضعیت یک batch.
	 */
	public function ajax_get_batch_status() {
		$this->check_ajax_nonce();

		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';

		if ( ! $batch_id ) {
			wp_send_json_error( array( 'message' => 'شناسه‌ی batch الزامی است.' ) );
		}

		$batch = $this->get_batch( $batch_id );
		if ( ! $batch ) {
			wp_send_json_error( array( 'message' => 'batch یافت نشد.' ) );
		}

		// محاسبه‌ی درصد پیشرفت
		$progress = 0;
		if ( 'manual' === $batch['strategy'] ) {
			$desired = max( 1, (int) ( $batch['desired_count'] ?? 0 ) );
			$imported = (int) ( $batch['imported'] ?? 0 );
			$progress = min( 100, round( ( $imported / $desired ) * 100, 1 ) );
		} elseif ( ! empty( $batch['desired_count'] ) ) {
			$desired = max( 1, (int) $batch['desired_count'] );
			$imported = (int) ( $batch['imported'] ?? 0 );
			$progress = min( 100, round( ( $imported / $desired ) * 100, 1 ) );
		}

		wp_send_json_success( array(
			'batch'    => $batch,
			'progress' => $progress,
		) );
	}

	/**
	 * AJAX: لغو یک batch.
	 */
	public function ajax_cancel_batch() {
		$this->check_ajax_nonce();

		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';

		if ( ! $batch_id ) {
			wp_send_json_error( array( 'message' => 'شناسه‌ی batch الزامی است.' ) );
		}

		$batch = $this->get_batch( $batch_id );
		if ( ! $batch ) {
			wp_send_json_error( array( 'message' => 'batch یافت نشد.' ) );
		}

		if ( 'cancelled' === $batch['status'] || 'completed' === $batch['status'] ) {
			wp_send_json_error( array(
				'message' => 'این batch قبلاً ' . ( 'completed' === $batch['status'] ? 'تکمیل' : 'لغو' ) . ' شده است.',
			) );
		}

		$this->cancel_batch( $batch_id );

		wp_send_json_success( array(
			'message' => '✅ batch با موفقیت لغو شد.',
			'batch'   => $this->get_batch( $batch_id ),
		) );
	}

	/**
	 * AJAX: دریافت لیست همه‌ی batch ها.
	 */
	public function ajax_get_all_batches() {
		$this->check_ajax_nonce();

		$batches = $this->get_batches();

		// مرتب‌سازی بر اساس created_at (جدیدترین اول)
		uasort( $batches, function( $a, $b ) {
			$a_time = isset( $a['created_at'] ) ? $a['created_at'] : '';
			$b_time = isset( $b['created_at'] ) ? $b['created_at'] : '';
			return strcmp( $b_time, $a_time );
		} );

		// محاسبه‌ی پیشرفت برای هر batch
		$result = array();
		foreach ( $batches as $b ) {
			$progress = 0;

			if ( 'manual' === ( $b['strategy'] ?? '' ) ) {
				$desired  = max( 1, (int) ( $b['desired_count'] ?? 0 ) );
				$imported = (int) ( $b['imported'] ?? 0 );
				$progress = min( 100, round( ( $imported / $desired ) * 100, 1 ) );
			} elseif ( ! empty( $b['desired_count'] ) ) {
				$desired  = max( 1, (int) $b['desired_count'] );
				// پیشرفت واقعی: تعداد پیام‌هایی که فایلشان واقعاً دانلود شده
				$done = 0;
				foreach ( ( $b['message_results'] ?? array() ) as $mr ) {
					if ( 'downloaded' === ( $mr['file'] ?? '' ) ) {
						$done++;
					}
				}
				if ( ! $done && 'scrape' === ( $b['strategy'] ?? '' ) ) {
					$done = (int) ( $b['imported'] ?? 0 ); // در scrape، imported = ساخته‌شده
				}
				$progress = min( 100, round( ( $done / $desired ) * 100, 1 ) );
			}

			$result[] = array_merge( $b, array( 'progress' => $progress ) );
		}

		wp_send_json_success( array( 'batches' => $result ) );
	}

	/* ======================================================================
	   SECTION 9: HUMAN-LIKE HTTP
	   ====================================================================== */

	/**
	 * دریافت یک User-Agent تصادفی از لیست مرورگرهای واقعی.
	 *
	 * @return string
	 */
	public static function random_user_agent() {
		$agents = array(
			// Chrome 120 on Windows 10
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			// Chrome 119 on macOS
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
			// Firefox 121 on Windows 10
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
			// Firefox 120 on macOS
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:120.0) Gecko/20100101 Firefox/120.0',
			// Edge 120 on Windows 11
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
			// Chrome 118 on Linux
			'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36',
			// Safari 17 on macOS
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
			// Chrome 119 on Windows 10 (second variant)
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.6045.160 Safari/537.36',
		);

		return $agents[ array_rand( $agents ) ];
	}

	/**
	 * دریافت هدرهای HTTP شبیه‌سازی‌شده‌ی مرورگر.
	 *
	 * @return array
	 */
	public static function human_headers() {
		$accept_languages = array(
			'en-US,en;q=0.9',
			'en-US,en;q=0.9,fa;q=0.8',
			'en-GB,en;q=0.9,en-US;q=0.8,fa;q=0.7',
		);

		$accepts = array(
			'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
			'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
		);

		$referrers = array(
			'https://www.google.com/',
			'https://t.me/',
			'https://www.bing.com/',
			'https://duckduckgo.com/',
		);

		return array(
			'User-Agent'      => self::random_user_agent(),
			'Accept'          => $accepts[ array_rand( $accepts ) ],
			'Accept-Language' => $accept_languages[ array_rand( $accept_languages ) ],
			'Accept-Encoding' => 'gzip, deflate, br',
			'Cache-Control'   => wp_rand( 0, 1 ) ? 'no-cache' : 'max-age=0',
			'DNT'             => '1',
			'Referer'         => $referrers[ array_rand( $referrers ) ],
			'Sec-Fetch-Dest'  => 'document',
			'Sec-Fetch-Mode'  => 'navigate',
			'Sec-Fetch-Site'  => 'none',
			'Sec-Fetch-User'  => '?1',
			'Upgrade-Insecure-Requests' => '1',
		);
	}

	/**
	 * تأخیر تصادفی بین min و max ثانیه (با jitter).
	 *
	 * @param float $min  حداقل تأخیر به ثانیه.
	 * @param float $max  حداکثر تأخیر به ثانیه.
	 */
	public static function random_delay( $min = 0.8, $max = 2.5 ) {
		if ( defined( 'STI_CI_TEST_MODE' ) && STI_CI_TEST_MODE ) {
			return; // حالت تست — بدون تأخیر
		}
		$delay = $min + ( ( $max - $min ) * ( wp_rand( 0, 1000 ) / 1000 ) );

		// اضافه کردن jitter ±30%
		$jitter_pct = ( wp_rand( -300, 300 ) / 1000 );
		$delay = $delay * ( 1 + $jitter_pct );

		// حداقل تأخیر: 0.5 ثانیه
		$delay = max( 0.5, $delay );

		$sleep_us = (int) ( $delay * 1000000 );
		usleep( $sleep_us );
	}

	/**
	 * درخواست HTTP GET شبیه‌سازی‌شده با retry و exponential backoff.
	 *
	 * @param string $url      آدرس.
	 * @param int    $retries  تعداد تلاش مجدد.
	 * @return array|false  آرایه با کلیدهای body, http_code, headers یا false.
	 */
	public static function human_http_get( $url, $retries = 3 ) {
		$retries = max( 1, min( (int) $retries, 5 ) );

		for ( $attempt = 1; $attempt <= $retries; $attempt++ ) {

			$headers = self::human_headers();

			$args = array(
				'timeout'     => 15,
				'redirection' => 3,
				'httpversion' => '1.1',
				'user-agent'  => $headers['User-Agent'],
				'headers'     => $headers,
				'sslverify'   => true,
			);

			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				$error_msg = $response->get_error_message();
				STI_Logger::warning( 'Channel Import: HTTP GET خطا — url=' . $url . ', attempt=' . $attempt . '/' . $retries . ', error=' . $error_msg );

				if ( $attempt < $retries ) {
					// exponential backoff
					$backoff = min( 120, pow( 3, $attempt ) );
					STI_Logger::info( 'Channel Import: backoff ' . $backoff . 's قبل از تلاش مجدد...' );
					sleep( $backoff );
				}
				continue;
			}

			$http_code = wp_remote_retrieve_response_code( $response );
			$body      = wp_remote_retrieve_body( $response );

			// اگر 429 (Too Many Requests) — backoff و تلاش مجدد
			if ( 429 === $http_code ) {
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				$sleep = $retry_after ? (int) $retry_after : pow( 3, $attempt );
				$sleep = min( 120, $sleep );

				STI_Logger::warning( 'Channel Import: 429 Too Many Requests — ' . $sleep . 's صبر — url=' . $url );
				sleep( $sleep );
				continue;
			}

			// اگر 5xx — تلاش مجدد
			if ( $http_code >= 500 && $http_code < 600 ) {
				STI_Logger::warning( 'Channel Import: HTTP ' . $http_code . ' — url=' . $url . ', attempt=' . $attempt . '/' . $retries );
				if ( $attempt < $retries ) {
					sleep( pow( 2, $attempt ) );
				}
				continue;
			}

			// 403/404 — دیگر تلاش نکن
			if ( 403 === $http_code || 404 === $http_code ) {
				STI_Logger::warning( 'Channel Import: HTTP ' . $http_code . ' — url=' . $url );
				return false;
			}

			return array(
				'body'      => $body,
				'http_code' => $http_code,
				'headers'   => wp_remote_retrieve_headers( $response ),
			);
		}

		STI_Logger::error( 'Channel Import: HTTP GET ناموفق پس از ' . $retries . ' تلاش — url=' . $url );
		return false;
	}

	/* ======================================================================
	   SECTION 10: WEB SCRAPING HELPERS
	   ====================================================================== */

	/**
	 * پارس HTML استاتیک یک پیام از t.me/s و استخراج داده‌ها.
	 *
	 * @param string $html       HTML خام صفحه.
	 * @param string $username   نام کاربری کانال.
	 * @param int    $message_id شناسه‌ی پیام.
	 * @return array  داده‌های استخراج‌شده.
	 */
	public function parse_message_html( $html, $username, $message_id ) {
		$data = array(
			'username'    => $username,
			'message_id'  => $message_id,
			'caption'     => '',
			'image_url'   => '',
			'file_name'   => '',
			'file_type'   => '',
			'source_url'  => '',
			'button_urls' => array(),
			'has_photo'   => false,
			'has_document'=> false,
		);

		/* ── استخراج کپشن از og:description ── */
		$caption = $this->extract_caption( $html );
		if ( $caption ) {
			$data['caption'] = $caption;

			// پارس کپشن برای File Name, File Type, File Code, Source URL
			$parsed = STI_Caption_Parser::parse( $caption );

			if ( ! empty( $parsed['file_name'] ) ) {
				$data['file_name'] = $parsed['file_name'];
			}
			if ( ! empty( $parsed['file_type'] ) ) {
				$data['file_type'] = $parsed['file_type'];
			}
			if ( ! empty( $parsed['source_url'] ) ) {
				$data['source_url'] = $parsed['source_url'];
			}
		}

		/* ── استخراج URL تصویر از og:image ── */
		$image_url = $this->extract_image_url( $html );
		if ( $image_url ) {
			$data['image_url'] = $image_url;
			$data['has_photo'] = true;
		}

		/* ── استخراج URL های inline button ── */
		$button_urls = $this->extract_button_urls_from_html( $html );
		if ( ! empty( $button_urls ) ) {
			$data['button_urls'] = $button_urls;

			// اگر در کپشن source_url نبود، از اولین button url استفاده کن
			if ( empty( $data['source_url'] ) && ! empty( $button_urls[0] ) ) {
				$data['source_url'] = $button_urls[0];
			}
		}

		/* ── استخراج متن پیام از tgme_widget_message_text در noscript ── */
		if ( empty( $data['caption'] ) ) {
			// تلاش برای استخراج از div.tgme_widget_message_text
			if ( preg_match( '/<div[^>]*class="[^"]*tgme_widget_message_text[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m ) ) {
				$text = wp_strip_all_tags( $m[1], true );
				$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$text = trim( $text );

				if ( $text ) {
					$data['caption'] = $text;
				}
			}
		}

		/* ── بررسی وجود document (فایل) ── */
		if ( false !== strpos( $html, 'tgme_widget_message_document' ) ) {
			$data['has_document'] = true;
		}

		/* ── اگر نه کپشن داریم نه عکس، احتمالاً scrape فایده‌ای ندارد ── */
		if ( empty( $data['caption'] ) && empty( $data['image_url'] ) ) {
			return array(); // خالی برگردان تا نادیده گرفته شود
		}

		return $data;
	}

	/**
	 * استخراج URL تصویر از og:image یا background-image CSS.
	 *
	 * @param string $html
	 * @return string|null
	 */
	public function extract_image_url( $html ) {
		/* ── روش ۱: og:image meta tag ── */
		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m ) ) {
			return $m[1];
		}

		/* ── روش ۲: twitter:image ── */
		if ( preg_match( '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/i', $html, $m ) ) {
			return $m[1];
		}

		/* ── روش ۳: background-image در tgme_widget_message_photo_wrap ── */
		if ( preg_match( '/class=["\']tgme_widget_message_photo_wrap[^"\']*["\'].*?background-image:\s*url\([\'"]?([^\'")]+)[\'"]?\)/is', $html, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/background-image:\s*url\([\'"]?([^\'")]+)[\'"]?\).*?class=["\']tgme_widget_message_photo_wrap/is', $html, $m ) ) {
			return $m[1];
		}

		/* ── روش ۴: هر background-image حاوی photo ── */
		if ( preg_match( '/background-image:\s*url\([\'"]?([^\'")]*\/file\/[^\'")]+)[\'"]?\)/i', $html, $m ) ) {
			return $m[1];
		}

		/* ── روش ۵: img tag در noscript (embed mode) ── */
		if ( preg_match( '/<img[^>]+class=["\'][^"\']*tgme_widget_message_photo[^"\']*["\'][^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * استخراج متن کپشن از meta tag ها یا noscript.
	 *
	 * @param string $html
	 * @return string|null
	 */
	public function extract_caption( $html ) {
		/* ── روش ۱: og:description (مطمئن‌ترین روش) ── */
		if ( preg_match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$caption = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			return trim( $caption );
		}
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\']/i', $html, $m ) ) {
			$caption = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			return trim( $caption );
		}

		/* ── روش ۲: توضیحات در description meta ── */
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$caption = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			return trim( $caption );
		}

		/* ── روش ۳: متن داخل tgme_widget_message_text ── */
		if ( preg_match( '/<div[^>]*class="[^"]*tgme_widget_message_text[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m ) ) {
			$text = wp_strip_all_tags( $m[1], true );
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$text = trim( $text );
			if ( strlen( $text ) > 5 ) {
				return $text;
			}
		}

		return null;
	}

	/**
	 * استخراج URL های دکمه‌های inline از HTML استاتیک.
	 *
	 * @param string $html
	 * @return array
	 */
	public function extract_button_urls_from_html( $html ) {
		$urls = array();

		// جستجوی <a class="tgme_widget_message_inline_button"> elements
		if ( preg_match_all( '/<a[^>]*class="[^"]*tgme_widget_message_inline_button[^"]*"[^>]*href="([^"]+)"[^>]*>/i', $html, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( ! empty( $url ) && ! in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
			}
		}

		return $urls;
	}

	/**
	 * استخراج File Code از متن کپشن.
	 *
	 * @param string $text
	 * @return string|null
	 */
	protected function extract_file_code_from_text( $text ) {
		if ( empty( $text ) ) {
			return null;
		}

		// ابتدا با STI_Caption_Parser
		$parsed = STI_Caption_Parser::parse( $text );
		if ( ! empty( $parsed['file_code'] ) ) {
			return $parsed['file_code'];
		}

		// جستجوی الگوهای عددی ۴+ رقمی
		if ( preg_match( '/(?<!\d)(\d{4,})(?!\d)/', $text, $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * دریافت آخرین message_id قابل scrape برای یک کانال.
	 * با تست چند URL سعی می‌کند یک ID معتبر پیدا کند.
	 *
	 * @param string $username
	 * @return int|false  -1 یعنی کانال خصوصی است و پیامی در وب ندارد.
	 */
	protected function get_latest_scrapable_id( $username ) {
		// از URL اصلی t.me/s/Username استفاده کن (که آخرین پست‌ها را نشان می‌دهد)
		$response = self::human_http_get( 'https://t.me/s/' . $username, 2 );

		if ( ! $response || empty( $response['body'] ) ) {
			return false;
		}

		$html = $response['body'];

		// جستجوی data-post در لینک‌های پست
		if ( preg_match( '/data-post="' . preg_quote( $username, '/' ) . '\/(\d+)"/i', $html, $m ) ) {
			return (int) $m[1];
		}

		// جستجوی message ID در href
		if ( preg_match( '/href="\/' . preg_quote( $username, '/' ) . '\/(\d+)"/i', $html, $m ) ) {
			return (int) $m[1];
		}

		// جستجوی آخرین post ID در meta og:url
		if ( preg_match( '/<meta[^>]+property=["\']og:url["\'][^>]+content=["\'][^"\']*\/' . preg_quote( $username, '/' ) . '\/(\d+)["\']/i', $html, $m ) ) {
			return (int) $m[1];
		}

		/* ── تشخیص کانال خصوصی: اطلاعات کانال هست ولی هیچ پیامی نمایش داده نمی‌شود ── */
		if ( false !== strpos( $html, 'tgme_page' ) && false === strpos( $html, 'data-post' ) && false === strpos( $html, 'tgme_widget_message' ) ) {
			STI_Logger::warning( 'Channel Import: کانال خصوصی است (بدون پیش‌نمایش پیام در وب) — @' . $username );
			return -1;
		}

		/* ── fallback: صفحه‌ی embed یک پیام خاص ── */
		$embed = self::human_http_get( 'https://t.me/' . $username . '/1?embed=1', 1 );
		if ( $embed && ! empty( $embed['body'] ) && preg_match( '/data-post="' . preg_quote( $username, '/' ) . '\/(\d+)"/i', $embed['body'], $m ) ) {
			return (int) $m[1];
		}

		STI_Logger::warning( 'Channel Import: نمی‌توان آخرین message_id را از صفحه‌ی اصلی تشخیص داد — @' . $username );
		return false;
	}

	/* ======================================================================
	   SECTION 11: FORWARDED MESSAGE PROCESSING (WEBHOOK INTEGRATION)
	   ====================================================================== */

	/**
	 * پردازش پیام forwarded شده از کانال.
	 * توسط webhook handler (STI_Webhook) یا action hook صدا زده می‌شود.
	 *
	 * @param array  $message   پیام خام تلگرام.
	 * @param string $batch_id  شناسه‌ی batch (اختیاری — کشف خودکار).
	 * @return bool
	 */
	public function process_forwarded_message( $message, $batch_id = '' ) {
		/* ── تشخیص پیام forwarded ── */
		$forward_from_chat    = $message['forward_from_chat'] ?? null;
		$forward_from_msg_id  = $message['forward_from_message_id'] ?? 0;
		$forward_origin       = $message['forward_origin'] ?? null;

		if ( ! $forward_from_chat && ! $forward_origin ) {
			return false;
		}

		$chat_id = $message['chat']['id'] ?? 0;
		$user_id = $message['from']['id'] ?? null;
		// Forwarded channel messages are processed before the normal message
		// router, so enforce the same operator authorization here.
		if ( ! STI_Settings::is_authorized_update( $chat_id, $user_id ) ) {
			STI_Logger::warning( 'Channel Import: پیام forwarded از کاربر غیرمجاز رد شد — chat=' . $chat_id );
			return false;
		}

		// شناسه‌ی کانال مبدأ
		$source_chat_id = null;
		$source_username = null;

		if ( ! empty( $forward_origin['chat']['id'] ) ) {
			$source_chat_id = $forward_origin['chat']['id'];
			$source_username = $forward_origin['chat']['username'] ?? null;
		} elseif ( ! empty( $forward_from_chat['id'] ) ) {
			$source_chat_id = $forward_from_chat['id'];
			$source_username = $forward_from_chat['username'] ?? null;
		}

		STI_Logger::info( 'Channel Import: پیام forwarded دریافت شد — from_chat_id=' . $source_chat_id . ', username=' . $source_username . ', msg=' . $forward_from_msg_id . ', to=' . $chat_id );

		/* ── تشخیص متن و کپشن ── */
		$text     = trim( isset( $message['caption'] ) ? $message['caption'] : ( $message['text'] ?? '' ) );
		$entities = isset( $message['caption'] ) ? ( $message['caption_entities'] ?? array() ) : ( $message['entities'] ?? array() );

		/* ── تحلیل inline keyboard ── */
		$reply_markup = $message['reply_markup'] ?? array();
		$button_urls  = $this->extract_button_urls( $reply_markup );
		$has_callback_buttons = $this->has_callback_only_buttons( $reply_markup );

		/* ── پارس کپشن ── */
		$parsed    = $text ? STI_Caption_Parser::parse( $text, $entities ) : array();
		$file_code = trim( (string) ( $parsed['file_code'] ?? '' ) );
		$file_name = trim( (string) ( $parsed['file_name'] ?? '' ) );
		$file_type = trim( (string) ( $parsed['file_type'] ?? '' ) );

		/* ── تشخیص download URL ── */
		$download_url = null;

		// اولویت ۱: دکمه‌ی URL در inline keyboard
		if ( ! empty( $button_urls ) ) {
			$download_url = $button_urls[0];
			STI_Logger::info( 'Channel Import: لینک دانلود از inline keyboard استخراج شد — ' . $download_url );
		}

		// اولویت ۲: text_link entity در کپشن
		if ( ! $download_url && ! empty( $entities ) ) {
			foreach ( $entities as $e ) {
				if ( 'text_link' === ( $e['type'] ?? '' ) && ! empty( $e['url'] ) ) {
					$download_url = $e['url'];
					STI_Logger::info( 'Channel Import: لینک دانلود از text_link entity استخراج شد — ' . $download_url );
					break;
				}
			}
		}

		// اولویت ۳: URL مستقیم در متن
		if ( ! $download_url && $text ) {
			$url = STI_Caption_Parser::extract_url( $text );
			if ( $url ) {
				$download_url = $url;
				STI_Logger::info( 'Channel Import: لینک دانلود از متن کپشن استخراج شد — ' . $download_url );
			}
		}

		// اولویت ۴: اگر فقط callback button وجود دارد، منتظر فایل از @FileechBot هستیم
		$awaiting_file = false;
		if ( ! $download_url && $has_callback_buttons ) {
			$awaiting_file = true;
			STI_Logger::info( 'Channel Import: پیام فقط دکمه‌ی callback دارد — منتظر فایل از FileechBot...' );
		}

		/* ── استخراج File Code از URL (اگر در کپشن نباشد) ── */
		if ( ! $file_code && $download_url && preg_match( '/(?<!\d)(\d{4,})(?!\d)/', $download_url, $match ) ) {
			$file_code = $match[1];
		}
		if ( ! $file_code && $text && preg_match( '/(?<!\d)(\d{4,})(?!\d)/', $text, $match ) ) {
			$file_code = $match[1];
		}

		/* ── بدون File Code نمی‌توان پردازش کرد ── */
		if ( ! $file_code ) {
			STI_Logger::info( 'Channel Import: پیام forwarded بدون File Code — نادیده گرفته شد.' );
			if ( $batch_id ) {
				$this->mark_message_imported( $batch_id, $forward_from_msg_id, 0, 'no_file_code' );
			}
			return false;
		}

		/* ── بررسی تکراری بودن ── */
		if ( $this->is_duplicate( $file_code ) ) {
			STI_Logger::info( 'Channel Import: پیام تکراری — file_code=' . $file_code . ' — skip شد.' );
			if ( $batch_id ) {
				$this->mark_message_imported( $batch_id, $forward_from_msg_id, 0, 'skipped_duplicate' );
			}
			return false;
		}

		/* ── پیدا کردن batch فعال برای این chat_id یا username ── */
		if ( ! $batch_id ) {
			$batch_id = $this->find_active_batch_for_source( $source_chat_id, $source_username );
		}

		/* ── ایجاد یا به‌روزرسانی session ── */
		$session = STI_Session::get_open_by_file_code( $chat_id, $file_code );
		$is_new  = ! $session;

		if ( ! $session ) {
			$category_id = 0;
			if ( $batch_id ) {
				$b = $this->get_batch( $batch_id );
				if ( $b ) {
					$category_id = (int) $b['category_id'];
				}
			}

			if ( ! $category_id ) {
				STI_Logger::warning( 'Channel Import: category_id نامشخص برای file_code=' . $file_code );
				return false;
			}

			/* ── تطبیق اتوکت: قبل از ساخت session (و قبل از این‌که پیام به ربات فایلیچ
			 * فوروارد/تأیید شود) بررسی کن که این پیام واقعاً با دسته‌ی انتخاب‌شده در
			 * batch می‌خواند. تا الان این مسیر (فوروارد دستی / ربات فایلیچ) هیچ
			 * بررسی اتوکتی نداشت و هر پیامی — حتی با دسته‌ی کاملاً نامرتبط — رد می‌شد. ── */
			$autocat_title = trim( $text . ' ' . $file_name . ' ' . $file_type );
			$autocat_type  = trim( $file_type . ' ' . $file_name );
			$autocat_check = $this->evaluate_autocat_match( $autocat_title, $autocat_type, $category_id );

			if ( ! $autocat_check['allowed'] ) {
				STI_Logger::info( 'Channel Import: پیام forwarded رد شد — file_code=' . $file_code . ' — ' . $autocat_check['reason'] );
				if ( $batch_id ) {
					$this->mark_message_imported( $batch_id, $forward_from_msg_id, 0, 'no_category' );
				}
				return false;
			}
			$category_id = (int) $autocat_check['category_id'];

			$session_id = STI_Session::create( $chat_id, null, $category_id );
			if ( ! $session_id ) {
				STI_Logger::error( 'Channel Import: ایجاد session ناموفق — file_code=' . $file_code );
				return false;
			}
			$session = STI_Session::get( $session_id );
			if ( ! $session ) {
				return false;
			}

			STI_Logger::info( 'Channel Import: session جدید ایجاد شد — id=' . $session_id . ', file_code=' . $file_code );
		}

		/* ── جمع‌آوری داده‌ها ── */
		$updated = array( 'file_code' => $file_code );

		if ( $file_name ) { $updated['file_name'] = $file_name; }
		if ( $file_type ) { $updated['file_type'] = $file_type; }
		if ( ! empty( $parsed['source_url'] ) ) { $updated['source_url'] = $parsed['source_url']; }
		if ( ! empty( $parsed['dimensions'] ) ) { $updated['dimensions'] = $parsed['dimensions']; }
		if ( ! empty( $parsed['resolution'] ) ) { $updated['resolution'] = $parsed['resolution']; }
		if ( ! empty( $parsed['color'] ) ) { $updated['color'] = $parsed['color']; }
		if ( $text ) { $updated['caption_raw'] = $text; }

		/* ── عکس ── */
		if ( ! empty( $message['photo'] ) ) {
			$photos = $message['photo'];
			$largest = end( $photos );
			$updated['image_file_id'] = $largest['file_id'];
		}

		/* ── فایل/سند ── */
		if ( ! empty( $message['document'] ) ) {
			$updated['doc_file_id']   = $message['document']['file_id'];
			$updated['doc_file_name'] = $message['document']['file_name'] ?? '';
		}

		/* ── لینک دانلود ── */
		if ( $download_url && empty( $message['photo'] ) && empty( $message['document'] ) ) {
			$updated['download_url_raw'] = $download_url;
		}

		/* ── علامت‌گذاری awaiting file ── */
		if ( $awaiting_file ) {
			$await_key = 'sti_ci_awaiting_' . $file_code;
			set_transient( $await_key, array(
				'session_id'    => $session->id,
				'chat_id'       => $source_chat_id,
				'message_id'    => $forward_from_msg_id,
				'batch_id'      => $batch_id,
				'file_code'     => $file_code,
				'created_at'    => current_time( 'mysql' ),
			), DAY_IN_SECONDS );

			STI_Logger::info( 'Channel Import: session #' . $session->id . ' در انتظار فایل از FileechBot — file_code=' . $file_code );
		}

		/* ── به‌روزرسانی session ── */
		if ( ! empty( $updated ) ) {
			STI_Session::update( $session->id, $updated );
			$session = STI_Session::get( $session->id );
		}

		/* ── به‌روزرسانی batch ── */
		if ( $batch_id && $forward_from_msg_id ) {
			$this->mark_message_imported( $batch_id, $forward_from_msg_id, $session->id, $awaiting_file ? 'awaiting_file' : 'imported' );
		}

		/* ── تلاش برای finalize در صورت تکمیل ── */
		if ( STI_Session::is_complete( $session ) ) {
			$this->finalize_import_session( $session, $batch_id );
		} else {
			STI_Logger::info( sprintf(
				'Channel Import: session #%d منتظر تکمیل — image=%s, document=%s, link=%s',
				$session->id,
				! empty( $session->image_file_id ) || ! empty( $session->image_url ) ? 'yes' : 'no',
				! empty( $session->doc_file_id ) ? 'yes' : 'no',
				! empty( $session->download_url_raw ) ? 'yes' : 'no'
			), $session->id );
		}

		return true;
	}

	/**
	 * Finalize یک session import و ساخت محصول.
	 *
	 * @param object $session
	 * @param string $batch_id
	 */
	protected function finalize_import_session( $session, $batch_id = '' ) {
		if ( ! $session || 'open' !== $session->status ) {
			return false;
		}
		$success = false;

		STI_Logger::info( 'Channel Import: finalize session #' . $session->id . ' — file_code=' . $session->file_code );
		STI_Session::update( $session->id, array( 'status' => 'processing' ) );

		try {
			$webhook = STI_Webhook::instance();
			$success = $webhook->finalize_session_by_id( $session->id );

			if ( $success ) {
				if ( $batch_id ) {
					$batches = $this->get_batches();
					if ( isset( $batches[ $batch_id ] ) ) {
						$batches[ $batch_id ]['products_created'] = (int) ( $batches[ $batch_id ]['products_created'] ?? 0 ) + 1;
						$batches[ $batch_id ]['updated_at'] = current_time( 'mysql' );
						update_option( self::BATCH_OPTION_KEY, $batches, false );
					}
				}
				$success = true;
				STI_Logger::success( 'Channel Import: محصول ایجاد شد — session #' . $session->id . ', file_code=' . $session->file_code );
			} else {
				STI_Logger::warning( 'Channel Import: ساخت محصول موفق نبود — session #' . $session->id, $session->id );
			}
		} catch ( \Throwable $e ) {
			STI_Session::mark_error( $session->id, $e->getMessage() );
			STI_Logger::error( 'Channel Import: خطا در finalize session #' . $session->id . ': ' . $e->getMessage(), $session->id );
		}
		return $success;
	}

	/* ======================================================================
	   SECTION 12: FILE MATCHING
	   ====================================================================== */

	/**
	 * تطبیق فایل دریافتی (از @FileechBot) با session در انتظار.
	 *
	 * @param string     $file_code  کد فایل.
	 * @param int|string $chat_id    شناسه‌ی چت.
	 * @return object|false  session به‌روزرسانی‌شده یا false.
	 */
	public function match_file_to_session( $file_code, $chat_id ) {
		if ( ! $file_code ) {
			return false;
		}

		/* ── ابتدا از transient (awaiting file) چک کن ── */
		$await_key = 'sti_ci_awaiting_' . $file_code;
		$awaiting  = get_transient( $await_key );

		if ( is_array( $awaiting ) && ! empty( $awaiting['session_id'] ) ) {
			$session = STI_Session::get( $awaiting['session_id'] );
			if ( $session && 'open' === $session->status ) {
				delete_transient( $await_key );

				STI_Logger::info( 'Channel Import: فایل به session #' . $session->id . ' وصل شد — file_code=' . $file_code );

				return $session;
			}
		}

		/* ── سپس از دیتابیس چک کن ── */
		$session = STI_Session::get_open_by_file_code( $chat_id, $file_code );
		if ( $session ) {
			STI_Logger::info( 'Channel Import: session #' . $session->id . ' با file_code=' . $file_code . ' یافت شد.' );
			return $session;
		}

		STI_Logger::info( 'Channel Import: هیچ session بازی برای file_code=' . $file_code . ' یافت نشد.' );
		return false;
	}

	/**
	 * پیدا کردن batch فعال بر اساس source_chat_id یا username.
	 *
	 * @param int|null    $source_chat_id
	 * @param string|null $source_username
	 * @return string|null
	 */
	protected function find_active_batch_for_source( $source_chat_id, $source_username = null ) {
		$batches = $this->get_batches();
		$active_statuses = array( 'running', 'awaiting_forward' );

		foreach ( $batches as $b ) {
			if ( ! in_array( $b['status'] ?? '', $active_statuses, true ) ) {
				continue;
			}

			// تطبیق با chat_id
			if ( $source_chat_id && ! empty( $b['chat_id'] ) && (int) $b['chat_id'] === (int) $source_chat_id ) {
				return $b['id'];
			}

			// تطبیق با username
			if ( $source_username && ! empty( $b['username'] ) && strtolower( $b['username'] ) === strtolower( $source_username ) ) {
				return $b['id'];
			}
		}

		return null;
	}

	/* ======================================================================
	   SECTION 13: INLINE KEYBOARD ANALYSIS
	   ====================================================================== */

	/**
	 * استخراج URL های inline keyboard از reply_markup پیام.
	 *
	 * @param array $reply_markup  reply_markup خام تلگرام.
	 * @return array  لیست URL های یافت‌شده.
	 */
	public function extract_button_urls( $reply_markup ) {
		$urls = array();

		if ( empty( $reply_markup['inline_keyboard'] ) || ! is_array( $reply_markup['inline_keyboard'] ) ) {
			return $urls;
		}

		foreach ( $reply_markup['inline_keyboard'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $button ) {
				if ( ! empty( $button['url'] ) ) {
					$urls[] = $button['url'];
				}
			}
		}

		return $urls;
	}

	/**
	 * بررسی اینکه آیا inline keyboard فقط دکمه‌های callback_data دارد.
	 *
	 * @param array $reply_markup
	 * @return bool
	 */
	public function has_callback_only_buttons( $reply_markup ) {
		if ( empty( $reply_markup['inline_keyboard'] ) || ! is_array( $reply_markup['inline_keyboard'] ) ) {
			return false;
		}

		$has_buttons = false;
		foreach ( $reply_markup['inline_keyboard'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $button ) {
				$has_buttons = true;
				if ( ! empty( $button['url'] ) ||
				     ! empty( $button['switch_inline_query'] ) ||
				     ! empty( $button['switch_inline_query_current_chat'] ) ||
				     ! empty( $button['callback_game'] ) ||
				     ! empty( $button['pay'] ) ||
				     ! empty( $button['login_url'] ) ||
				     ! empty( $button['web_app'] ) ) {
					return false;
				}
			}
		}

		return $has_buttons;
	}

}
