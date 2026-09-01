<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI Group Monitor — Mode 2
 *
 * پایش گروه‌ها و تاپیک‌های تلگرام برای پیام‌های جدید محصول و
 * ایجاد خودکار محصولات ووکامرس. از مود ۱ موجود پشتیبانی می‌کند
 * و در کار آن تداخلی ایجاد نمی‌کند.
 *
 * Class STI_Group_Monitor
 */
class STI_Group_Monitor {

	protected static $instance;

	/**
	 * کلید wp_options برای ذخیره‌ی گروه‌های پایش‌شده.
	 */
	const OPTION_KEY  = 'sti_monitored_groups';

	/**
	 * پیشوند transient برای session های باز هر چت.
	 */
	const SESSION_PREFIX = 'sti_gm_session_';

	/**
	 * حداکثر تعداد session های هم‌زمان برای یک چت.
	 */
	const MAX_OPEN_SESSIONS = 50;

	/**
	 * مدت‌زمان زنده ماندن هر session باز (ثانیه).
	 */
	const SESSION_TTL = DAY_IN_SECONDS;

	/**
	 * حداقل فاصله‌ی زمانی مجاز بین پیام‌های یک چت (ثانیه) — rate limiting.
	 */
	const RATE_LIMIT_SECONDS = 2;

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
	 * Constructor — hooks into existing plugin flows.
	 */
	protected function __construct() {
		add_action( 'sti_group_message_received', array( $this, 'dispatch_group_message' ), 10, 3 );

		/* AJAX handlers برای مدیریت از پنل ادمین */
		add_action( 'wp_ajax_sti_gm_add_group',       array( $this, 'ajax_add_group' ) );
		add_action( 'wp_ajax_sti_gm_remove_group',    array( $this, 'ajax_remove_group' ) );
		add_action( 'wp_ajax_sti_gm_toggle_group',    array( $this, 'ajax_toggle_group' ) );
		add_action( 'wp_ajax_sti_gm_test_group',      array( $this, 'ajax_test_group' ) );
		add_action( 'wp_ajax_sti_gm_save_ds_settings', array( $this, 'ajax_save_ds_settings' ) );
	}

	/* =========================== WEBHOOK INTERCEPTION =========================== */

	/**
	 * نقطه‌ی ورود اصلی — توسط sti_webhook_processed یا مستقیم از webhook handler صدا زده می‌شود.
	 *
	 * @param array $message  پیام خام تلگرام.
	 * @return bool  true اگر پیام توسط مانیتور پردازش شد، false در غیر این‌صورت.
	 */
	public function maybe_process_group_message( $message ) {
		if ( empty( $message['chat']['id'] ) ) {
			return false;
		}

		$chat    = $message['chat'];
		$chat_id = $chat['id'];
		$type    = $chat['type'] ?? '';

		// فقط چت‌های گروهی و سوپرگروه
		if ( ! in_array( $type, array( 'group', 'supergroup' ), true ) ) {
			return false;
		}

		// بررسی اینکه گروه در فهرست پایش باشد
		if ( ! $this->is_monitored( $chat_id ) ) {
			return false;
		}

		// بررسی rate limit
		if ( $this->is_rate_limited( $chat_id ) ) {
			STI_Logger::info( 'Group Monitor: rate limit active for chat ' . $chat_id . ', پیام رد شد.' );
			return true; // ادعا می‌کنیم پردازش کردیم تا webhook سایلنت شود
		}

		// بروزرسانی timestamp rate limit
		$this->touch_rate_limit( $chat_id );

		// بررسی پیام تکراری
		$message_id = $message['message_id'] ?? 0;
		if ( $message_id && $this->is_duplicate( $chat_id, $message_id ) ) {
			STI_Logger::info( 'Group Monitor: پیام تکراری در chat ' . $chat_id . '، message_id=' . $message_id . ' — نادیده گرفته شد.' );
			return true;
		}
		$this->mark_processed( $chat_id, $message_id );

		$group_config = $this->get_group_config( $chat_id );

		// صدا زدن هوک بین‌افزونه‌ای (قابل استفاده توسط کدهای دیگر)
		$gm_result = apply_filters( 'sti_group_message_intercept', null, $chat_id, $message, $group_config );
		if ( null !== $gm_result ) {
			return (bool) $gm_result;
		}

		// فایر کردن action سفارشی
		do_action( 'sti_group_message_received', $chat_id, $message, $group_config );

		return true;
	}

	/**
	 * بررسی اینکه یک chat_id در فهرست گروه‌های پایش‌شده هست یا نه.
	 *
	 * @param int|string $chat_id
	 * @return bool
	 */
	public function is_monitored( $chat_id ) {
		$groups = $this->get_monitored_groups();
		foreach ( $groups as $group ) {
			if ( (int) $group['chat_id'] === (int) $chat_id && ! empty( $group['enabled'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * دریافت تنظیمات یک گروه خاص.
	 *
	 * @param int|string $chat_id
	 * @return array|null
	 */
	public function get_group_config( $chat_id ) {
		$groups = $this->get_monitored_groups();
		foreach ( $groups as $group ) {
			if ( (int) $group['chat_id'] === (int) $chat_id ) {
				return $group;
			}
		}
		return null;
	}

	/**
	 * افزودن یک گروه به فهرست پایش.
	 *
	 * @param int    $chat_id      شناسه‌ی عددی گروه (برای سوپرگروه‌ها منفی).
	 * @param int    $category_id  شناسه‌ی دسته‌بندی ووکامرس.
	 * @param int    $topic_id     شناسه‌ی تاپیک انجمنی (0 برای بدون تاپیک).
	 * @param string $label        برچسب فارسی برای نمایش در پنل.
	 * @return bool
	 */
	public function add_monitored_group( $chat_id, $category_id, $topic_id = 0, $label = '' ) {
		$groups = $this->get_monitored_groups();

		// بررسی تکراری نبودن: اگر topic_id=0 باشد یعنی کل گروه،
		// پس با هر ورودی دیگری برای همین chat_id تداخل دارد.
		// اگر هر دو topic_id غیرصفر و متفاوت باشند، مجاز است (تاپیک‌های مختلف).
		foreach ( $groups as $group ) {
			if ( (int) $group['chat_id'] !== (int) $chat_id ) {
				continue; // چت متفاوت — رد نمی‌شود
			}

			$existing_topic = (int) ( $group['topic_id'] ?? 0 );
			$new_topic      = (int) $topic_id;

			// اگر یکی از دو ورودی topic_id=0 داشته باشد (کل گروه) → تکراری
			if ( 0 === $existing_topic || 0 === $new_topic ) {
				return false;
			}

			// هر دو topic_id غیرصفر هستند — فقط اگر دقیقاً یکی باشند تکراری
			if ( $existing_topic === $new_topic ) {
				return false;
			}
		}

		$groups[] = array(
			'chat_id'     => (int) $chat_id,
			'category_id' => (int) $category_id,
			'topic_id'    => (int) $topic_id,
			'label'       => $label ? sanitize_text_field( $label ) : 'گروه ' . $chat_id,
			'enabled'     => true,
			'added_at'    => current_time( 'mysql' ),
		);

		$saved = update_option( self::OPTION_KEY, $groups, false );
		STI_Logger::info( 'Group Monitor: گروه جدید اضافه شد — chat_id=' . $chat_id . ', category_id=' . $category_id );

		return $saved;
	}

	/**
	 * حذف یک گروه از فهرست پایش.
	 *
	 * @param int|string $chat_id
	 * @return bool
	 */
	public function remove_monitored_group( $chat_id ) {
		$groups = $this->get_monitored_groups();
		$before = count( $groups );

		$groups = array_values( array_filter( $groups, function ( $g ) use ( $chat_id ) {
			return (int) $g['chat_id'] !== (int) $chat_id;
		} ) );

		if ( count( $groups ) === $before ) {
			return false;
		}

		$saved = update_option( self::OPTION_KEY, $groups, false );
		STI_Logger::info( 'Group Monitor: گروه حذف شد — chat_id=' . $chat_id );

		return $saved;
	}

	/**
	 * فعال/غیرفعال کردن یک گروه.
	 *
	 * @param int  $chat_id
	 * @param bool $enabled
	 * @return bool
	 */
	public function toggle_monitored_group( $chat_id, $enabled ) {
		$groups = $this->get_monitored_groups();
		foreach ( $groups as &$group ) {
			if ( (int) $group['chat_id'] === (int) $chat_id ) {
				$group['enabled'] = (bool) $enabled;
				$saved = update_option( self::OPTION_KEY, $groups, false );
				STI_Logger::info( 'Group Monitor: گروه ' . ( $enabled ? 'فعال' : 'غیرفعال' ) . ' شد — chat_id=' . $chat_id );
				return $saved;
			}
		}
		return false;
	}

	/**
	 * دریافت همه‌ی گروه‌های پایش‌شده.
	 *
	 * @return array
	 */
	public function get_monitored_groups() {
		$groups = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $groups ) ) {
			return array();
		}
		return $groups;
	}

	/* =========================== DISPATCH & PROCESS =========================== */

	/**
	 * دریافت پیام از action hook و dispatch آن به پردازش‌گر اصلی.
	 *
	 * @param int   $chat_id
	 * @param array $message
	 * @param array $group_config
	 */
	public function dispatch_group_message( $chat_id, $message, $group_config ) {
		$this->process_message( $chat_id, $message, $group_config );
	}

	/**
	 * پردازش یک پیام از گروه پایش‌شده — مشابه منطق bulk mode.
	 *
	 * @param int   $chat_id
	 * @param array $message
	 * @param array $group_config
	 * @return bool
	 */
	protected function process_message( $chat_id, $message, $group_config ) {
		$category_id = (int) ( $group_config['category_id'] ?? 0 );
		if ( ! $category_id ) {
			STI_Logger::warning( 'Group Monitor: category_id برای chat_id=' . $chat_id . ' تنظیم نشده است.' );
			return false;
		}

		$category = STI_Category::get( $category_id );
		if ( ! $category || ! $category->is_active ) {
			STI_Logger::warning( 'Group Monitor: دسته‌بندی غیرفعال یا ناموجود برای chat_id=' . $chat_id . ', category_id=' . $category_id );
			return false;
		}

		// بررسی topic_id (اگر گروه انجمنی باشد)
		if ( ! empty( $group_config['topic_id'] ) ) {
			$msg_topic = $message['message_thread_id'] ?? ( $message['is_topic_message'] ? $message['chat']['id'] : null );
			// اگر topic تنظیم شده ولی با topic پیام تطابق ندارد، رد کن
			if ( $msg_topic && (int) $msg_topic !== (int) $group_config['topic_id'] ) {
				return false;
			}
		}

		// ============ استخراج text و entities ============
		$text     = trim( isset( $message['caption'] ) ? $message['caption'] : ( $message['text'] ?? '' ) );
		$entities = isset( $message['caption'] ) ? ( $message['caption_entities'] ?? array() ) : ( $message['entities'] ?? array() );

		// پارس کردن کپشن
		$parsed    = $text ? STI_Caption_Parser::parse( $text, $entities ) : array();
		$file_code = trim( (string) ( $parsed['file_code'] ?? '' ) );

		// استخراج URL (مستقیم یا از text_entities)
		$url = STI_Caption_Parser::extract_url( $text );
		if ( ! $url && ! empty( $entities ) ) {
			foreach ( $entities as $e ) {
				if ( 'text_link' === ( $e['type'] ?? '' ) && ! empty( $e['url'] ) ) {
					$url = $e['url'];
					break;
				}
			}
		}

		// File Code را از URL هم استخراج کن اگر در کپشن پیدا نشده باشد
		if ( ! $file_code && $url && preg_match( '/(?<!\d)(\d{4,})(?!\d)/', $url, $match ) ) {
			$file_code = $match[1];
		}

		// بدون File Code نمی‌توان محصول ساخت
		if ( ! $file_code ) {
			STI_Logger::info( 'Group Monitor: پیام بدون File Code در chat_id=' . $chat_id . ' — نادیده گرفته شد.' );
			return false;
		}

		// ============ مدیریت Session ============
		$session = $this->get_open_session( $chat_id, $file_code );
		$is_new  = ! $session;

		if ( ! $session ) {
			// ایجاد session جدید
			$session_id = STI_Session::create( $chat_id, $message['from']['id'] ?? null, $category_id );
			$session    = STI_Session::get( $session_id );
			if ( ! $session ) {
				STI_Logger::error( 'Group Monitor: ایجاد session ناموفق بود — chat_id=' . $chat_id . ', file_code=' . $file_code );
				return false;
			}
			// ثبت پیوند file_code → session_id در transient
			$this->map_file_code_to_session( $chat_id, $file_code, $session->id );
		}

		// ============ تشخیص و پردازش نوع پیام ============
		$updated = array( 'file_code' => $file_code );

		if ( ! empty( $parsed['file_name'] ) ) { $updated['file_name'] = $parsed['file_name']; }
		if ( ! empty( $parsed['file_type'] ) ) { $updated['file_type'] = $parsed['file_type']; }
		if ( ! empty( $parsed['source_url'] ) ) { $updated['source_url'] = $parsed['source_url']; }
		if ( ! empty( $parsed['dimensions'] ) ) { $updated['dimensions'] = $parsed['dimensions']; }
		if ( ! empty( $parsed['resolution'] ) ) { $updated['resolution'] = $parsed['resolution']; }
		if ( ! empty( $parsed['color'] ) ) { $updated['color'] = $parsed['color']; }
		if ( $text ) { $updated['caption_raw'] = $text; }

		// پردازش عکس
		if ( ! empty( $message['photo'] ) ) {
			$this->handle_photo( $chat_id, $session, $message, $updated );
		}

		// پردازش فایل/سند
		if ( ! empty( $message['document'] ) ) {
			$this->handle_document( $chat_id, $session, $message, $updated );
		}

		// پردازش متن/لینک
		if ( ! empty( $text ) && empty( $message['photo'] ) && empty( $message['document'] ) ) {
			$this->handle_text( $chat_id, $session, $message, $text, $url, $updated );
		}

		// URL مستقیم (بدون عکس)
		if ( $url && empty( $message['photo'] ) && empty( $message['document'] ) ) {
			$updated['download_url_raw'] = $url;
		}

		// ============ مدیریت album (media_group_id) ============
		$media_group_id = $message['media_group_id'] ?? null;
		if ( $media_group_id ) {
			$this->handle_album_item( $media_group_id, $message );
			// اگر هنوز همه‌ی آیتم‌ها نرسیده‌اند، session را ذخیره کن ولی finalize نکن
			if ( ! $this->is_album_complete( $media_group_id, $message ) ) {
				STI_Session::update( $session->id, $updated );
				return true;
			}
		}

		// اعمال بروزرسانی‌ها
		if ( ! empty( $updated ) ) {
			STI_Session::update( $session->id, $updated );
			$session = STI_Session::get( $session->id );
		}

		STI_Logger::info( sprintf(
			'Group Monitor: merge code=%s, session=#%d, new=%s, image=%s, document=%s, link=%s',
			$file_code, $session->id, $is_new ? 'yes' : 'no',
			$session->image_file_id ? 'yes' : 'no',
			$session->doc_file_id ? 'yes' : 'no',
			$session->download_url_raw ? 'yes' : 'no'
		), $session->id );

		// ============ Finalize در صورت تکمیل ============
		$this->finalize_if_complete( $session );

		return true;
	}

	/**
	 * پردازش عکس‌های دریافتی.
	 *
	 * @param int    $chat_id
	 * @param object $session
	 * @param array  $message
	 * @param array  &$updated
	 */
	protected function handle_photo( $chat_id, $session, $message, &$updated ) {
		$photos   = $message['photo'];
		$largest  = end( $photos );
		$updated['image_file_id'] = $largest['file_id'];

		STI_Logger::info( 'Group Monitor: عکس دریافت شد — chat_id=' . $chat_id . ', file_code=' . $session->file_code, $session->id );
	}

	/**
	 * پردازش فایل‌ها/اسناد دریافتی.
	 *
	 * @param int    $chat_id
	 * @param object $session
	 * @param array  $message
	 * @param array  &$updated
	 */
	protected function handle_document( $chat_id, $session, $message, &$updated ) {
		$updated['doc_file_id']   = $message['document']['file_id'];
		$updated['doc_file_name'] = $message['document']['file_name'] ?? '';

		STI_Logger::info( 'Group Monitor: فایل دریافت شد — chat_id=' . $chat_id . ', file=' . $updated['doc_file_name'], $session->id );
	}

	/**
	 * پردازش پیام‌های متنی (کپشن یا لینک).
	 *
	 * @param int         $chat_id
	 * @param object      $session
	 * @param array       $message
	 * @param string      $text
	 * @param string|null $url
	 * @param array       &$updated
	 */
	protected function handle_text( $chat_id, $session, $message, $text, $url, &$updated ) {
		if ( STI_Caption_Parser::looks_like_caption( $text ) ) {
			// کپشن قبلاً توسط parse_and_merge به updated اضافه شده
			STI_Logger::info( 'Group Monitor: کپشن پردازش شد — chat_id=' . $chat_id, $session->id );
		} elseif ( $url && STI_Caption_Parser::looks_like_download_link( $text ) ) {
			if ( preg_match( '/\.(jpg|jpeg|png|webp|gif)(\?|$)/i', $url ) && empty( $session->image_url ) && empty( $session->image_file_id ) ) {
				$updated['image_url'] = $url;
				STI_Logger::info( 'Group Monitor: لینک تصویر ثبت شد — chat_id=' . $chat_id, $session->id );
			} else {
				$updated['download_url_raw'] = $url;
				STI_Logger::info( 'Group Monitor: لینک دانلود ثبت شد — chat_id=' . $chat_id, $session->id );
			}
		}
	}

	/* =========================== ALBUM HANDLING =========================== */

	/**
	 * ثبت یک آیتم از آلبوم در transient برای تجمیع با آیتم‌های بعدی.
	 *
	 * @param string $media_group_id
	 * @param array  $message
	 */
	protected function handle_album_item( $media_group_id, $message ) {
		$key  = 'sti_gm_album_' . $media_group_id;
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data = array( 'items' => array(), 'first_seen' => time() );
		}

		// استخراج داده‌های مفید از این آیتم
		$item = array();
		if ( ! empty( $message['caption'] ) ) {
			$item['caption']         = $message['caption'];
			$item['caption_entities'] = $message['caption_entities'] ?? array();
		}
		if ( ! empty( $message['photo'] ) ) {
			$photos = $message['photo'];
			$largest = end( $photos );
			$item['image_file_id'] = $largest['file_id'];
		}
		if ( ! empty( $message['document'] ) ) {
			$item['doc_file_id']   = $message['document']['file_id'];
			$item['doc_file_name'] = $message['document']['file_name'] ?? '';
		}

		if ( ! empty( $item ) ) {
			$data['items'][] = $item;
		}

		set_transient( $key, $data, 120 ); // 2 دقیقه برای دریافت همه‌ی آیتم‌های آلبوم
	}

	/**
	 * بررسی اینکه آیا آلبوم کامل دریافت شده است.
	 * Telegram معمولاً همه‌ی آیتم‌های یک آلبوم را در یک batch ارسال می‌کند،
	 * بنابراین کمی تأخیر می‌دهیم تا همه برسند.
	 *
	 * @param string $media_group_id
	 * @param array  $message
	 * @return bool
	 */
	protected function is_album_complete( $media_group_id, $message ) {
		$key  = 'sti_gm_album_' . $media_group_id;
		$data = get_transient( $key );

		if ( ! is_array( $data ) ) {
			return true; // بدون داده، پردازش کن (نباید اینجا برسیم)
		}

		$elapsed = time() - ( $data['first_seen'] ?? time() );

		// بعد از ۳ ثانیه، فرض می‌کنیم همه رسیده‌اند
		if ( $elapsed >= 3 ) {
			return true;
		}

		// اگر آیتم فعلی حداقل یک فایل داشته باشد، پردازش را شروع کن
		if ( ! empty( $message['document'] ) ) {
			return true;
		}

		return false;
	}

	/* =========================== SESSION MANAGEMENT =========================== */

	/**
	 * دریافت session باز بر اساس chat_id و file_code
	 * (مشابه get_open_by_file_code در STI_Session).
	 *
	 * @param int    $chat_id
	 * @param string $file_code
	 * @return object|null
	 */
	protected function get_open_session( $chat_id, $file_code ) {
		// ابتدا از دیتابیس جست‌وجو کن
		$session = STI_Session::get_open_by_file_code( $chat_id, $file_code );
		if ( $session ) {
			return $session;
		}
		return null;
	}

	/**
	 * ثبت نگاشت file_code → session_id در transient
	 * تا برای پیام‌های بعدی بتوانیم session درست را پیدا کنیم
	 * (در صورتی که STI_Session::get_open_by_file_code جواب ندهد).
	 *
	 * @param int    $chat_id
	 * @param string $file_code
	 * @param int    $session_id
	 */
	protected function map_file_code_to_session( $chat_id, $file_code, $session_id ) {
		$key       = self::SESSION_PREFIX . $chat_id;
		$sessions  = get_transient( $key );
		if ( ! is_array( $sessions ) ) {
			$sessions = array();
		}

		// محدود کردن تعداد session‌های باز
		if ( count( $sessions ) >= self::MAX_OPEN_SESSIONS ) {
			array_shift( $sessions );
		}

		$sessions[ $file_code ] = array(
			'session_id' => $session_id,
			'created_at' => time(),
		);

		set_transient( $key, $sessions, self::SESSION_TTL );
	}

	/* =========================== FINALIZE =========================== */

	/**
	 * بررسی و finalize session در صورت تکمیل بودن.
	 *
	 * @param object $session
	 */
	protected function finalize_if_complete( $session ) {
		if ( ! $session || 'open' !== $session->status ) {
			return;
		}

		if ( ! STI_Session::is_complete( $session ) ) {
			return; // هنوز اطلاعات کافی نیست
		}

		STI_Session::update( $session->id, array( 'status' => 'processing' ) );
		STI_Logger::info( 'Group Monitor: session #' . $session->id . ' تکمیل شد، در حال ساخت محصول...', $session->id );

		try {
			STI_Webhook::instance()->finalize_session_by_id( $session->id );
		} catch ( \Throwable $e ) {
			STI_Session::mark_error( $session->id, $e->getMessage() );
			STI_Logger::error( 'Group Monitor: خطا در finalize session #' . $session->id . ': ' . $e->getMessage(), $session->id );
		}
	}

	/* =========================== RATE LIMITING =========================== */

	/**
	 * بررسی rate limit برای یک چت.
	 *
	 * @param int $chat_id
	 * @return bool
	 */
	protected function is_rate_limited( $chat_id ) {
		$key   = 'sti_gm_rl_' . $chat_id;
		$last  = (int) get_transient( $key );
		return $last && ( time() - $last ) < self::RATE_LIMIT_SECONDS;
	}

	/**
	 * ثبت timestamp درخواست فعلی.
	 *
	 * @param int $chat_id
	 */
	protected function touch_rate_limit( $chat_id ) {
		$key = 'sti_gm_rl_' . $chat_id;
		set_transient( $key, time(), MINUTE_IN_SECONDS );
	}

	/* =========================== DUPLICATE DETECTION =========================== */

	/**
	 * بررسی پیام تکراری بر اساس message_id.
	 *
	 * @param int $chat_id
	 * @param int $message_id
	 * @return bool
	 */
	protected function is_duplicate( $chat_id, $message_id ) {
		$key       = 'sti_gm_proc_' . $chat_id;
		$processed = get_transient( $key );
		if ( ! is_array( $processed ) ) {
			return false;
		}
		return in_array( $message_id, $processed, true );
	}

	/**
	 * ثبت یک message_id به‌عنوان پردازش‌شده.
	 *
	 * @param int $chat_id
	 * @param int $message_id
	 */
	protected function mark_processed( $chat_id, $message_id ) {
		$key       = 'sti_gm_proc_' . $chat_id;
		$processed = get_transient( $key );
		if ( ! is_array( $processed ) ) {
			$processed = array();
		}

		if ( count( $processed ) > 1000 ) {
			$processed = array_slice( $processed, -500 );
		}

		$processed[] = $message_id;
		set_transient( $key, $processed, DAY_IN_SECONDS );
	}

	/* =========================== AJAX HANDLERS =========================== */

	/**
	 * افزودن گروه از طریق AJAX.
	 */
	public function ajax_add_group() {
		$this->check_ajax_nonce();

		$chat_id     = isset( $_POST['chat_id'] ) ? (int) $_POST['chat_id'] : 0;
		$category_id = isset( $_POST['category_id'] ) ? (int) $_POST['category_id'] : 0;
		$topic_id    = isset( $_POST['topic_id'] ) ? (int) $_POST['topic_id'] : 0;
		$label       = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		if ( ! $chat_id ) {
			wp_send_json_error( array( 'message' => 'شناسه‌ی عددی گروه (Chat ID) الزامی است.' ) );
		}
		if ( ! $category_id ) {
			wp_send_json_error( array( 'message' => 'انتخاب دسته‌بندی الزامی است.' ) );
		}

		$result = $this->add_monitored_group( $chat_id, $category_id, $topic_id, $label );
		if ( $result ) {
			wp_send_json_success( array( 'message' => 'گروه با موفقیت به فهرست پایش اضافه شد.' ) );
		} else {
			wp_send_json_error( array( 'message' => 'این گروه قبلاً در فهرست پایش وجود دارد.' ) );
		}
	}

	/**
	 * حذف گروه از طریق AJAX.
	 */
	public function ajax_remove_group() {
		$this->check_ajax_nonce();

		$chat_id = isset( $_POST['chat_id'] ) ? (int) $_POST['chat_id'] : 0;
		if ( ! $chat_id ) {
			wp_send_json_error( array( 'message' => 'شناسه‌ی گروه الزامی است.' ) );
		}

		$result = $this->remove_monitored_group( $chat_id );
		if ( $result ) {
			wp_send_json_success( array( 'message' => 'گروه از فهرست پایش حذف شد.' ) );
		} else {
			wp_send_json_error( array( 'message' => 'گروهی با این شناسه در فهرست پایش یافت نشد.' ) );
		}
	}

	/**
	 * فعال/غیرفعال کردن گروه از طریق AJAX.
	 */
	public function ajax_toggle_group() {
		$this->check_ajax_nonce();

		$chat_id = isset( $_POST['chat_id'] ) ? (int) $_POST['chat_id'] : 0;
		$enabled = ! empty( $_POST['enabled'] );

		if ( ! $chat_id ) {
			wp_send_json_error( array( 'message' => 'شناسه‌ی گروه الزامی است.' ) );
		}

		$result = $this->toggle_monitored_group( $chat_id, $enabled );
		if ( $result ) {
			$status = $enabled ? 'فعال' : 'غیرفعال';
			wp_send_json_success( array( 'message' => "گروه {$status} شد." ) );
		} else {
			wp_send_json_error( array( 'message' => 'گروهی با این شناسه یافت نشد.' ) );
		}
	}

	/**
	 * آزمایش اتصال به گروه از طریق AJAX.
	 *
	 * ابتدا getChat (قابل‌اعتمادتر، فقط نیاز به عضویت بات دارد)
	 * سپس sendMessage. در صورت خطا، راهنمایی دقیق فارسی نمایش می‌دهد.
	 */
	public function ajax_test_group() {
		$this->check_ajax_nonce();

		$chat_id = isset( $_POST['chat_id'] ) ? (int) $_POST['chat_id'] : 0;
		if ( ! $chat_id ) {
			wp_send_json_error( array( 'message' => 'شناسه‌ی گروه الزامی است.' ) );
		}

		$api = new STI_Telegram_API();

		// ── Step 1: getChat — مطمئن‌ترین روش بررسی عضویت بات ──
		$chat_info = $api->call( 'getChat', array( 'chat_id' => $chat_id ) );
		if ( ! $chat_info ) {
			$err = $api->get_last_error();
			$raw = ! empty( $err['message'] ) ? $err['message'] : '';

			// تحلیل خطاهای رایج تلگرام و ارائه راهنمایی فارسی
			if ( false !== strpos( $raw, 'chat not found' ) || false !== strpos( $raw, 'Chat not found' ) ) {
				wp_send_json_error( array(
					'message' => '❌ گروه پیدا نشد. مطمئن شو:',
					'details' => array(
						'1️⃣ بات (@YourBot) را به گروه اضافه کرده‌ای؟ (Add Member)',
						'2️⃣ اگر گروه Forum است، بات باید حتماً Administrator باشد.',
						'3️⃣ در BotFather دستور /setprivacy را بزن و گزینه Disable را انتخاب کن.',
						'4️⃣ بعد از اضافه کردن بات، یک پیام در گروه بفرست (هر متنی) تا Webhook فعال شود.',
						'5️⃣ Chat ID را دوباره چک کن — برای سوپرگروه‌ها باید با -100 شروع شود.',
					),
				) );
			}

			if ( false !== strpos( $raw, 'Forbidden' ) || false !== strpos( $raw, 'not a member' ) ) {
				wp_send_json_error( array(
					'message' => '⛔ بات در این گروه عضو نیست یا دسترسی کافی ندارد.',
					'details' => array(
						'1️⃣ بات را به گروه اضافه کن (Add Member → @YourBot).',
						'2️⃣ در BotFather: /setprivacy → Disable (تا بات بتواند همه پیام‌ها را بخواند).',
						'3️⃣ اگر گروه Forum است، بات را Administrator کن.',
					),
				) );
			}

			if ( false !== strpos( $raw, 'bot was kicked' ) ) {
				wp_send_json_error( array(
					'message' => '🚫 بات قبلاً از گروه حذف شده. دوباره Add Member کن.',
					'details' => array(),
				) );
			}

			wp_send_json_error( array(
				'message' => 'ارتباط با گروه برقرار نشد: ' . ( $raw ?: 'خطای نامشخص' ),
				'details' => array(),
			) );
			return;
		}

		// ── Step 2: sendMessage — تأیید نهایی ──
		$chat_title = ! empty( $chat_info['title'] ) ? $chat_info['title'] : '';
		$chat_type  = ! empty( $chat_info['type'] ) ? $chat_info['type'] : '';

		$test_msg = $api->send_message( $chat_id, '✅ تست ارتباط از پنل Golden Importer — Mode 2 فعال است! 🚀' );

		if ( $test_msg ) {
			$info = array();
			if ( $chat_title ) { $info[] = 'نام: ' . $chat_title; }
			if ( $chat_type )  { $info[] = 'نوع: ' . ( 'supergroup' === $chat_type ? 'سوپرگروه' : ( 'group' === $chat_type ? 'گروه' : $chat_type ) ); }

			wp_send_json_success( array(
				'message' => '✅ ارتباط با گروه برقرار است. پیام تست ارسال شد.' . ( $info ? ' (' . implode( '، ', $info ) . ')' : '' ),
			) );
		} else {
			$err = $api->get_last_error();
			$raw = ! empty( $err['message'] ) ? $err['message'] : '';

			if ( false !== strpos( $raw, 'not enough rights' ) || false !== strpos( $raw, 'need administrator' ) ) {
				wp_send_json_error( array(
					'message' => '⚠️ گروه شناسایی شد (نام: ' . ( $chat_title ?: '?' ) . ')، اما بات اجازه ارسال پیام ندارد.',
					'details' => array(
						'1️⃣ بات را Administrator گروه کن (Manage Group → Administrators → Add Admin).',
						'2️⃣ حداقل دسترسی Send Messages را به بات بده.',
					),
				) );
			} else {
				wp_send_json_error( array(
					'message' => '⚠️ گروه شناسایی شد ولی ارسال پیام ناموفق بود: ' . ( $raw ?: 'خطای نامشخص' ),
					'details' => array(),
				) );
			}
		}
	}

	/**
	 * ذخیره‌سازی تنظیمات Download Strategy از طریق AJAX.
	 */
	public function ajax_save_ds_settings() {
		$this->check_ajax_nonce();

		$vps_enabled   = ! empty( $_POST['sti_ds_vps_enabled'] ) ? 1 : 0;
		$vps_endpoint  = isset( $_POST['sti_ds_vps_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['sti_ds_vps_endpoint'] ) ) : '';
		$ssh_enabled   = ! empty( $_POST['sti_ds_ssh_enabled'] ) ? 1 : 0;
		$large_mode    = isset( $_POST['sti_ds_large_file_mode'] ) ? sanitize_key( wp_unslash( $_POST['sti_ds_large_file_mode'] ) ) : 'auto';

		update_option( 'sti_ds_vps_enabled',   $vps_enabled,   false );
		update_option( 'sti_ds_vps_endpoint',  $vps_endpoint,  false );
		update_option( 'sti_ds_ssh_enabled',   $ssh_enabled,   false );
		update_option( 'sti_ds_large_file_mode', $large_mode,  false );

		STI_Logger::info( 'Group Monitor: تنظیمات Download Strategy ذخیره شد.' );
		wp_send_json_success( array( 'message' => 'تنظیمات استراتژی دانلود ذخیره شد.' ) );
	}

	/**
	 * بررسی nonce و دسترسی ادمین.
	 */
	protected function check_ajax_nonce() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	/* =========================== UTILITY =========================== */

	/**
	 * شمارش محصولات ایجادشده از یک گروه در بازه‌ی امروز / کل.
	 *
	 * @param int $chat_id
	 * @return array { today, total }
	 */
	public function get_group_product_counts( $chat_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sti_sessions';

		$today = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE chat_id = %d AND DATE(created_at) = CURDATE() AND status IN ('scheduled', 'published', 'processing')",
			$chat_id
		) );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE chat_id = %d AND status IN ('scheduled', 'published')",
			$chat_id
		) );

		return array( 'today' => $today, 'total' => $total );
	}

	/**
	 * آخرین فعالیت یک گروه.
	 *
	 * @param int $chat_id
	 * @return string|null
	 */
	public function get_group_last_activity( $chat_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sti_sessions';

		return $wpdb->get_var( $wpdb->prepare(
			"SELECT updated_at FROM {$table} WHERE chat_id = %d ORDER BY updated_at DESC LIMIT 1",
			$chat_id
		) );
	}
}
