<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class STI_Webhook {

	protected static $instance;
	protected $api;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'sti_finalize_session_event', array( $this, 'finalize_session_by_id' ) );
		add_action( 'sti_finalize_bulk_session_event', array( $this, 'finalize_bulk_session_by_id' ), 10, 2 );
	}

	public function register_routes() {
		register_rest_route( 'sti/v1', '/webhook/(?P<secret>[a-zA-Z0-9_\-]+)', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_request' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function webhook_url() {
		$secret = STI_Settings::get( 'webhook_secret' );
		return rest_url( 'sti/v1/webhook/' . $secret );
	}

	public function handle_request( WP_REST_Request $request ) {
		$secret = $request->get_param( 'secret' );
		$expected = (string) STI_Settings::get( 'webhook_secret' );
		// v7 — مقایسه‌ی زمان‌ثابت (جلوگیری از حملات timing). سکرت کوتاه فقط هشدار می‌گیرد
		// تا وبهوک فعلی از کار نیفتد.
		if ( $expected && strlen( $expected ) < 20 && false === get_transient( 'sti_weak_secret_notice' ) ) {
			set_transient( 'sti_weak_secret_notice', 1, DAY_IN_SECONDS );
			STI_Logger::warning( 'سکرت وبهوک کوتاه است؛ از صفحه‌ی تنظیمات تلگرام یک سکرت تازه بساز و وبهوک را دوباره ثبت کن.' );
		}
		if ( empty( $expected ) || ! hash_equals( $expected, (string) $secret ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		$update = $request->get_json_params();
		if ( empty( $update ) ) {
			return new WP_REST_Response( array( 'ok' => true ) );
		}

		$this->api = new STI_Telegram_API();

		try {
			if ( ! empty( $update['callback_query'] ) ) {
				$this->handle_callback( $update['callback_query'] );
			} elseif ( ! empty( $update['message'] ) ) {
				$this->handle_message( $update['message'] );
			}
		} catch ( \Throwable $e ) {
			STI_Logger::error( 'Webhook exception: ' . $e->getMessage() );
		}

		do_action( 'sti_webhook_processed', $update );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/* =========================== MESSAGE HANDLING =========================== */

	protected function handle_message( $message ) {
		$chat_id = $message['chat']['id'];
		$user_id = $message['from']['id'] ?? null;

		/* ── Mode 2: Group Monitor check (before auth) ──────────────── */
		if ( class_exists( 'STI_Group_Monitor' ) && STI_Group_Monitor::instance()->maybe_process_group_message( $message ) ) {
			return; // Group Monitor took over
		}

		/* ── Channel Import: forwarded messages ─────────────────────── */
		if ( class_exists( 'STI_Channel_Import' ) && STI_Channel_Import::instance()->process_forwarded_message( $message ) ) {
			return; // Channel Import processed it
		}

		if ( ! STI_Settings::is_authorized_update( $chat_id, $user_id ) ) {
			$this->api->send_message( $chat_id, '⛔ شما اجازه استفاده از این ربات را ندارید.' );
			return;
		}

		$text = trim( $message['text'] ?? ( $message['caption'] ?? '' ) );

		if ( 0 === strpos( $text, '/menu' ) ) {
			$this->show_inline_menu( $chat_id );
			return;
		}
		if ( 0 === strpos( $text, '/start' ) ) {
			$this->set_bulk_category( $chat_id, null );
			if ( class_exists( 'STI_Bot_Modes' ) ) { STI_Bot_Modes::deactivate( $chat_id ); }
			$this->start_flow( $chat_id, $user_id );
			return;
		}
		if ( 0 === strpos( $text, '/cancel' ) ) {
			$this->set_bulk_category( $chat_id, null );
			if ( class_exists( 'STI_Bot_Modes' ) && STI_Bot_Modes::active( $chat_id ) ) { STI_Bot_Modes::cancel_open( $chat_id ); STI_Bot_Modes::deactivate( $chat_id ); $this->api->send_message( $chat_id, '🗑 حالت جدید و موارد نیمه‌کاره لغو شد.' ); return; }
			$this->cancel_flow( $chat_id );
			return;
		}
		if ( 0 === strpos( $text, '/status' ) ) {
			$this->status_flow( $chat_id );
			return;
		}
		if ( 0 === strpos( $text, '/queue' ) ) {
			$this->queue_flow( $chat_id );
			return;
		}
		if ( 0 === strpos( $text, '/done' ) ) {
			if ( class_exists( 'STI_Bot_Modes' ) && STI_Bot_Modes::active( $chat_id ) ) { $cfg = STI_Bot_Modes::config( $chat_id ); STI_Bot_Modes::deactivate( $chat_id ); $label = 'unlimited' === ( $cfg['mode'] ?? '' ) ? 'بدون مرز' : 'ترتیبات'; $this->api->send_message( $chat_id, '🏁 حالت «' . $label . '» خاموش شد. موارد تکمیل‌شده در صف پردازش می‌شوند.' ); return; }
			$this->done_flow( $chat_id );
			return;
		}
		if ( 0 === strpos( $text, '/preview' ) ) {
			$this->preview_flow( $chat_id );
			return;
		}

		/* ── New bot modes: unlimited MTProto group and ordered no-code import ── */
		if ( class_exists( 'STI_Bot_Modes' ) && STI_Bot_Modes::active( $chat_id ) ) {
			$mode_cfg = STI_Bot_Modes::config( $chat_id );
			if ( STI_Bot_Modes::instance()->receive( $message, $mode_cfg['mode'], (int) $mode_cfg['category_id'] ) ) { return; }
		}

		/* ── Mode 2: Allow Group Monitor to intercept ─────────────────── */
		$gm_result = apply_filters( 'sti_group_message_received', null, $chat_id, $message );
		if ( true === $gm_result ) {
			return; // Group Monitor handled it
		}

		$bulk_category_id = $this->get_bulk_category( $chat_id );
		if ( $bulk_category_id ) {
			$this->handle_bulk_message( $chat_id, $message, $bulk_category_id );
			return;
		}

		$session = STI_Session::get_open_for_chat( $chat_id );
		if ( ! $session ) {
			$this->api->send_message( $chat_id, 'برای شروع یک محصول جدید دستور /start را بفرست.' );
			return;
		}

		$this->ingest_message( $session, $message, $text );
	}

	/* ---------------- bulk mode state (per-chat option, no DB migration needed) ---------------- */

	protected function get_bulk_category( $chat_id ) {
		$val = (int) get_option( 'sti_bulk_mode_' . $chat_id, 0 );
		return $val ?: null;
	}

	protected function set_bulk_category( $chat_id, $category_id ) {
		if ( $category_id ) {
			update_option( 'sti_bulk_mode_' . $chat_id, (int) $category_id );
		} else {
			delete_option( 'sti_bulk_mode_' . $chat_id );
		}
	}

	protected function done_flow( $chat_id ) {
		$was_bulk = $this->get_bulk_category( $chat_id );
		$this->set_bulk_category( $chat_id, null );
		if ( $was_bulk ) {
			$this->api->send_message( $chat_id, '🏁 حالت ثبت سریع خاموش شد. برای شروع دوباره /start بزن.' );
		} else {
			$this->api->send_message( $chat_id, 'در حال حاضر در حالت ثبت سریع نیستی.' );
		}
	}

	protected function preview_flow( $chat_id ) {
		$recent = STI_Session::get_recent( 5 );
		if ( empty( $recent ) ) {
			$this->api->send_message( $chat_id, 'هنوز هیچ محصولی ثبت نشده.' );
			return;
		}
		$badge = array(
			'open' => '🟡 باز', 'processing' => '⚙️ در حال ساخت', 'scheduled' => '📬 در صف',
			'published' => '✅ منتشرشده', 'cancelled' => '🗑 لغوشده', 'error' => '❌ خطا',
		);
		$lines = array( '🖼 <b>۵ محصول آخر</b>' );
		foreach ( $recent as $s ) {
			$title = $s->file_name ?: '(بدون نام)';
			$status = $badge[ $s->status ] ?? $s->status;
			$link = $s->product_id ? admin_url( "post.php?post={$s->product_id}&action=edit" ) : '';
			$lines[] = "\n#{$s->id} — {$title}\n{$status}" . ( $link ? "\n🔗 {$link}" : '' );
		}
		$this->api->send_message( $chat_id, implode( "\n", $lines ) );
	}

	/**
	 * Bulk mode: every message with a document (and a caption on that same
	 * message) is treated as a fully self-contained, independent product —
	 * built and queued immediately, without accumulating across messages.
	 * Lets the operator forward dozens of pre-made messages back-to-back.
	 */
	protected function handle_bulk_message( $chat_id, $message, $category_id ) {
		$category = STI_Category::get( $category_id );
		if ( ! $category || ! $category->is_active ) {
			$this->api->send_message( $chat_id, '⚠️ دسته‌ی انتخاب‌شده دیگر فعال نیست. /done را بزن و دوباره /start کن.' );
			return;
		}

		/*
		 * High-throughput bulk matching. A File Code is the immutable join key:
		 * - forwarded photo+caption creates/updates an open row for that code;
		 * - document or direct URL finds that same open row, regardless of order;
		 * - dozens of codes may remain open at the same time.
		 */
		$text = trim( isset( $message['caption'] ) ? $message['caption'] : ( $message['text'] ?? '' ) );
		$entities = isset( $message['caption'] ) ? ( $message['caption_entities'] ?? array() ) : ( $message['entities'] ?? array() );
		$parsed = $text ? STI_Caption_Parser::parse( $text, $entities ) : array();
		$file_code = trim( (string) ( $parsed['file_code'] ?? '' ) );
		$url = STI_Caption_Parser::extract_url( $text );

		// Link-only messages can still join when the numeric file code is in its filename.
		if ( ! $file_code && $url && preg_match( '/(?<!\d)(\d{4,})(?!\d)/', $url, $match ) ) {
			$file_code = $match[1];
		}
		if ( ! $file_code ) {
			$this->api->send_message( $chat_id, '⚠️ File Code در این پیام پیدا نشد. برای ثبت دسته‌ای، کد فایل باید در کپشن عکس، فایل یا پیام لینک باشد.' );
			return;
		}

		$session = STI_Session::get_open_by_file_code( $chat_id, $file_code );
		$is_new = ! $session;
		if ( ! $session ) {
			$session_id = STI_Session::create( $chat_id, $message['from']['id'] ?? null, $category_id );
			$session = STI_Session::get( $session_id );
		}

		$updated = array( 'file_code' => $file_code );
		if ( ! empty( $parsed['file_name'] ) ) { $updated['file_name'] = $parsed['file_name']; }
		if ( ! empty( $parsed['file_type'] ) ) { $updated['file_type'] = $parsed['file_type']; }
		if ( ! empty( $parsed['source_url'] ) ) { $updated['source_url'] = $parsed['source_url']; }
		if ( ! empty( $parsed['dimensions'] ) ) { $updated['dimensions'] = $parsed['dimensions']; }
		if ( ! empty( $parsed['resolution'] ) ) { $updated['resolution'] = $parsed['resolution']; }
		if ( ! empty( $parsed['color'] ) ) { $updated['color'] = $parsed['color']; }
		if ( $text ) { $updated['caption_raw'] = $text; }

		if ( ! empty( $message['photo'] ) ) {
			$photos = $message['photo'];
			$largest = end( $photos );
			$updated['image_file_id'] = $largest['file_id'];
		}
		if ( ! empty( $message['document'] ) ) {
			$updated['doc_file_id'] = $message['document']['file_id'];
			$updated['doc_file_name'] = $message['document']['file_name'] ?? '';
		}
		// A plain URL is a download URL only when it is not merely the source link on a photo caption.
		if ( $url && empty( $message['photo'] ) ) { $updated['download_url_raw'] = $url; }

		STI_Session::update( $session->id, $updated );
		$session = STI_Session::get( $session->id );
		// One quiet progress summary per five newly collected items, not one message per source post.
		if ( $is_new && empty( $session->doc_file_id ) && empty( $session->download_url_raw ) ) {
			$notice_key = 'sti_bulk_notice_' . $chat_id;
			$count = (int) get_transient( $notice_key ) + 1;
			set_transient( $notice_key, $count, HOUR_IN_SECONDS );
			if ( 0 === $count % 5 ) {
				$this->api->send_message( $chat_id, "📥 {$count} عکس/کپشن ثبت شد؛ فایل‌ها یا لینک‌ها را با File Code متناظر بفرست." );
			}
		}
		STI_Logger::info( sprintf( 'Bulk merge: code=%s, session=#%d, new=%s, image=%s, document=%s, link=%s', $file_code, $session->id, $is_new ? 'yes' : 'no', $session->image_file_id ? 'yes' : 'no', $session->doc_file_id ? 'yes' : 'no', $session->download_url_raw ? 'yes' : 'no' ), $session->id );
		$this->finalize_open_bulk_session( $session );
	}

	/**
	 * When any message that's part of a Telegram album (media_group_id)
	 * carries a caption and/or a photo, stash whichever it has briefly so a
	 * sibling item in the same album that arrives without them (a well-known
	 * Telegram quirk when forwarding grouped media) can reuse them. Merges
	 * with anything already cached for this group rather than overwriting it.
	 */
	/** Finish an open bulk session only when image, metadata and file/link are all present. */
	protected function finalize_open_bulk_session( $session ) {
		if ( ! $session || 'open' !== $session->status ) { return; }
		if ( ! STI_Session::is_complete( $session ) ) {
			// Deliberately silent: bulk import may contain hundreds of source messages.
			// The session/log page remains the audit trail; only completion/failure is announced.
			return;
		}
		STI_Session::update( $session->id, array( 'status' => 'processing' ) );
		try {
			$this->finalize_session( STI_Session::get( $session->id ) );
		} catch ( \Throwable $e ) {
			STI_Session::mark_error( $session->id, $e->getMessage() );
			STI_Logger::error( 'bulk finalize exception: ' . $e->getMessage(), $session->id );
			$this->api->send_message( $session->chat_id, '❌ خطا در پردازش فایل: ' . $e->getMessage() );
		}
	}

	protected function remember_media_group_item( $message ) {
		$group_id = $message['media_group_id'] ?? null;
		if ( ! $group_id ) {
			return;
		}
		$data = get_transient( 'sti_mg_' . $group_id );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$caption = trim( $message['caption'] ?? '' );
		if ( $caption ) {
			$data['caption'] = $caption;
			$data['entities'] = $message['caption_entities'] ?? array();
		}
		if ( ! empty( $message['photo'] ) ) {
			$photos = $message['photo'];
			$largest = end( $photos );
			$data['image_file_id'] = $largest['file_id'];
		}

		if ( $data ) {
			set_transient( 'sti_mg_' . $group_id, $data, 60 );
		}
	}

	protected function recall_media_group_item( $message ) {
		$group_id = $message['media_group_id'] ?? null;
		if ( ! $group_id ) {
			return null;
		}
		$cached = get_transient( 'sti_mg_' . $group_id );
		return is_array( $cached ) ? $cached : null;
	}

	/** Inline menu replaces Telegram's persistent reply keyboard, so it never occupies the chat input. */
	protected function menu_keyboard() {
		return array( 'inline_keyboard' => array(
			array( array( 'text' => '➕ شروع ثبت', 'callback_data' => 'sti_menu_start' ), array( 'text' => '📬 صف انتشار', 'callback_data' => 'sti_menu_queue' ) ),
			array( array( 'text' => '📋 وضعیت فعلی', 'callback_data' => 'sti_menu_status' ), array( 'text' => '🖼 محصولات اخیر', 'callback_data' => 'sti_menu_preview' ) ),
		) );
	}

	protected function show_inline_menu( $chat_id ) {
		$this->api->send_message( $chat_id, '☰ <b>منوی مدیریت</b>
یک گزینه را انتخاب کن:', $this->menu_keyboard() );
	}

	protected function edit_inline_menu( $chat_id, $message_id ) {
		$this->api->edit_message_text( $chat_id, $message_id, '☰ <b>منوی مدیریت</b>
یک گزینه را انتخاب کن:', $this->menu_keyboard() );
	}

	protected function start_flow( $chat_id, $user_id ) {
		$open = STI_Session::get_open_for_chat( $chat_id );
		if ( $open ) {
			$kb = array( 'inline_keyboard' => array(
				array( array( 'text' => '▶️ ادامه ثبت‌های باز', 'callback_data' => 'sti_resume' ), array( 'text' => '✖️ لغو همه', 'callback_data' => 'sti_cancel_all' ) ),
			) );
			$this->api->send_message( $chat_id, 'چند اطلاعات ثبت‌نشده داری. ادامه بده یا لغو کن؟', $kb );
			return;
		}
		$this->send_registration_mode_menu( $chat_id );
	}

	protected function send_registration_mode_menu( $chat_id ) {
		$kb = array( 'inline_keyboard' => array(
			array( array( 'text' => '📦 ثبت تکی', 'callback_data' => 'sti_mode_single' ), array( 'text' => '⚡ ثبت دسته‌ای', 'callback_data' => 'sti_mode_bulk' ) ),
			array( array( 'text' => '♾️ بدون مرز (حجم نامحدود)', 'callback_data' => 'sti_mode_unlimited' ), array( 'text' => '🔢 ترتیبات (بدون کد)', 'callback_data' => 'sti_mode_ordered' ) ),
			array( array( 'text' => '✖️ انصراف', 'callback_data' => 'sti_cancel_all' ) ),
		) );
		$this->api->send_message( $chat_id, "روش ثبت را انتخاب کن:\n\n📦 ثبت تکی: برای یک محصول.\n⚡ ثبت دسته‌ای: روال قدیمی با File Code.\n♾️ بدون مرز: ثبت گروهی پیش‌فرض با دانلود مستقیم MTProto و بدون محدودیت ۲۰ مگابایت.\n🔢 ترتیبات: عکس+متن، سپس فایل؛ بدون نیاز به File Code و با تطبیق FIFO.", $kb );
	}

	protected function send_category_menu( $chat_id, $mode ) {
		$cats = STI_Category::get_active();
		if ( empty( $cats ) ) {
			$this->api->send_message( $chat_id, '⚠️ هیچ دسته‌بندی فعالی تنظیم نشده. از پنل وردپرس دسته‌بندی اضافه کن.' );
			return;
		}
		$rows = array();
		$row = array();
		foreach ( $cats as $cat ) {
			$row[] = array( 'text' => $cat->telegram_label, 'callback_data' => 'sti_cat_' . $mode . '_' . $cat->id );
			if ( count( $row ) === 2 ) { $rows[] = $row; $row = array(); }
		}
		if ( $row ) { $rows[] = $row; }
		$rows[] = array( array( 'text' => '✖️ انصراف', 'callback_data' => 'sti_cancel_all' ) );
		$label = 'single' === $mode ? 'ثبت تکی' : ( 'bulk' === $mode ? 'ثبت دسته‌ای' : ( 'unlimited' === $mode ? 'بدون مرز' : 'ترتیبات' ) );
		$this->api->send_message( $chat_id, '📂 دسته‌بندی را برای «' . $label . '» انتخاب کن:', array( 'inline_keyboard' => $rows ) );
	}

	protected function cancel_flow( $chat_id ) {
		$this->set_bulk_category( $chat_id, null );
		$count = STI_Session::cancel_all_open_for_chat( $chat_id );
		$this->api->send_message( $chat_id, $count ? '🗑 عملیات ثبت لغو شد.' : 'عملیات بازی برای لغو وجود ندارد.' );
	}

	protected function status_flow( $chat_id ) {
		$session = STI_Session::get_open_for_chat( $chat_id );
		if ( ! $session ) {
			$this->api->send_message( $chat_id, 'هیچ Session بازی وجود ندارد.' );
			return;
		}
		$cat = $session->category_id ? STI_Category::get( $session->category_id ) : null;
		$lines = array(
			'📦 <b>وضعیت Session فعلی</b>',
			'دسته: ' . ( $cat ? $cat->telegram_label : '—' ),
			'نام فایل: ' . ( $session->file_name ?: '—' ),
			'نوع: ' . ( $session->file_type ?: '—' ),
			'کد: ' . ( $session->file_code ?: '—' ),
			'تصویر: ' . ( $session->image_url ? '✅' : '—' ),
			'فایل/لینک: ' . ( ( $session->download_url_raw || $session->doc_file_id ) ? '✅' : '—' ),
		);
		$missing = STI_Session::missing_fields_message( $session );
		if ( $missing ) {
			$lines[] = '⏳ ' . $missing;
		}
		$this->api->send_message( $chat_id, implode( "\n", $lines ) );
	}

	protected function queue_flow( $chat_id ) {
		$status = STI_Scheduler::get_status();
		$state_label = $status['running'] ? '🟢 در حال اجرا' : '🔴 متوقف';
		$lines = array(
			'📬 <b>وضعیت صف انتشار</b>',
			'وضعیت: ' . $state_label,
			'فاصله‌ی انتشار: هر ' . $status['interval_minutes'] . ' دقیقه',
			'تعداد در صف: ' . $status['queued_count'],
		);
		if ( $status['queued_count'] > 0 && $status['next_publish_at'] ) {
			$mins = max( 0, ceil( ( $status['next_publish_at'] - time() ) / 60 ) );
			$lines[] = 'انتشار بعدی: حدود ' . $mins . ' دقیقه دیگر';
		}
		$kb = array(
			'inline_keyboard' => array(
				array( $status['running'] ? array( 'text' => '⏸ توقف صف', 'callback_data' => 'sti_queue_stop' ) : array( 'text' => '▶️ شروع صف', 'callback_data' => 'sti_queue_start' ) ),
				array( array( 'text' => '🚀 انتشار یک محصول اکنون', 'callback_data' => 'sti_queue_publish_next' ) ),
			),
		);
		$this->api->send_message( $chat_id, implode( "\n", $lines ), $kb );
	}

	/* =========================== CALLBACK HANDLING =========================== */

	protected function handle_callback( $cq ) {
		$chat_id = $cq['message']['chat']['id'];
		$user_id = $cq['from']['id'] ?? null;
		$message_id = $cq['message']['message_id'];
		$data = $cq['data'];

		if ( ! STI_Settings::is_authorized_update( $chat_id, $user_id ) ) {
			$this->api->answer_callback_query( $cq['id'], '⛔ اجازه نداری.', true );
			return;
		}

		if ( 'sti_show_menu' === $data ) {
			$this->api->answer_callback_query( $cq['id'] );
			$this->edit_inline_menu( $chat_id, $message_id );
			return;
		}

		if ( 'sti_menu_start' === $data ) {
			$this->api->answer_callback_query( $cq['id'] );
			$this->start_flow( $chat_id, $user_id );
			return;
		}
		if ( 'sti_menu_queue' === $data ) { $this->api->answer_callback_query( $cq['id'] ); $this->queue_flow( $chat_id ); return; }
		if ( 'sti_menu_status' === $data ) { $this->api->answer_callback_query( $cq['id'] ); $this->status_flow( $chat_id ); return; }
		if ( 'sti_menu_preview' === $data ) { $this->api->answer_callback_query( $cq['id'] ); $this->preview_flow( $chat_id ); return; }

		if ( in_array( $data, array( 'sti_mode_single', 'sti_mode_bulk', 'sti_mode_unlimited', 'sti_mode_ordered' ), true ) ) {
			$this->api->answer_callback_query( $cq['id'] );
			$mode = 'sti_mode_single' === $data ? 'single' : ( 'sti_mode_bulk' === $data ? 'bulk' : ( 'sti_mode_unlimited' === $data ? 'unlimited' : 'ordered' ) );
			$this->send_category_menu( $chat_id, $mode );
			return;
		}

		if ( 'sti_cancel_all' === $data ) {
			if ( class_exists( 'STI_Bot_Modes' ) ) { STI_Bot_Modes::deactivate( $chat_id ); }
			$this->api->answer_callback_query( $cq['id'], 'لغو شد' );
			$this->cancel_flow( $chat_id );
			return;
		}

		if ( preg_match( '/^sti_cat_(single|bulk|unlimited|ordered)_(\d+)$/', $data, $match ) ) {
			$mode = $match[1];
			$cat_id = (int) $match[2];
			$cat = STI_Category::get( $cat_id );
			if ( ! $cat || ! $cat->is_active ) {
				$this->api->answer_callback_query( $cq['id'], 'دسته‌بندی نامعتبر است.', true );
				return;
			}
			$this->api->answer_callback_query( $cq['id'], 'دسته انتخاب شد ✅' );
			if ( in_array( $mode, array( 'unlimited', 'ordered' ), true ) ) {
				if ( ! STI_MTProto::is_configured() || 'logged_in' !== STI_MTProto::instance()->auth_state() ) {
					$this->api->edit_message_text( $chat_id, $message_id, '⚠️ این حالت به اکانت شخصی MTProto نیاز دارد. ابتدا از تنظیمات تلگرام وارد شوید.' );
					return;
				}
				$this->set_bulk_category( $chat_id, null );
				STI_Bot_Modes::activate( $chat_id, (int) $cq['from']['id'], $cat_id, 'unlimited' === $mode ? STI_Bot_Modes::MODE_UNLIMITED : STI_Bot_Modes::MODE_ORDERED );
				$label_text = 'unlimited' === $mode ? '♾️ بدون مرز' : '🔢 ترتیبات';
				$instructions = 'unlimited' === $mode ? 'برای هر محصول عکس+متن دارای File Code و سپس فایل دارای همان File Code را بفرست؛ فایل‌های حجیم با اکانت شخصی دریافت می‌شوند.' : 'برای هر محصول: عکس+متن را بفرست، سپس فایل را بفرست. می‌توانی چند محصول را پشت‌سرهم بفرستی؛ سیستم FIFO را رعایت می‌کند.';
				$this->api->edit_message_text( $chat_id, $message_id, "✅ {$label_text} فعال شد — دسته: <b>{$cat->telegram_label}</b>\n\n{$instructions}\n\nبرای پایان /done را بفرست." );
				return;
			}
			if ( 'single' === $mode ) {
				$this->set_bulk_category( $chat_id, null );
				$session_id = STI_Session::create( $chat_id, $cq['from']['id'] ?? null, $cat_id );
				$kb = array( 'inline_keyboard' => array( array( array( 'text' => '✖️ انصراف', 'callback_data' => 'sti_cancel_all' ) ) ) );
				$this->api->edit_message_text( $chat_id, $message_id, "✅ ثبت تکی — دسته: <b>{$cat->telegram_label}</b>\n\nعکس، کپشن و فایل یا لینک مستقیم را بفرست. برای فایل بالای ۲۰ مگ، لینک مستقیم دانلود را بفرست.", $kb );
				STI_Logger::info( "Session #{$session_id} باز شد برای ثبت تکی.", $session_id );
			} else {
				STI_Session::cancel_all_open_for_chat( $chat_id );
				$this->set_bulk_category( $chat_id, $cat_id );
				$kb = array( 'inline_keyboard' => array( array( array( 'text' => '✖️ پایان / انصراف', 'callback_data' => 'sti_cancel_all' ) ) ) );
				$this->api->edit_message_text( $chat_id, $message_id, "✅ ثبت دسته‌ای — دسته: <b>{$cat->telegram_label}</b>\n\nمی‌توانی ده‌ها عکس/کپشن را اول بفرستی و فایل‌ها یا لینک‌ها را بعداً با هر ترتیبی بفرستی. هر بخش باید <b>File Code</b> داشته باشد تا خودکار به محصول درست وصل شود.", $kb );
			}
			return;
		}

		if ( 0 === strpos( $data, 'sti_normal_start_' ) ) {
			$cat_id = (int) str_replace( 'sti_normal_start_', '', $data );
			$cat = STI_Category::get( $cat_id );
			if ( ! $cat ) {
				$this->api->answer_callback_query( $cq['id'], 'دسته‌بندی نامعتبر است.', true );
				return;
			}
			$this->set_bulk_category( $chat_id, null ); // make sure bulk mode is off.
			$session_id = STI_Session::create( $chat_id, $cq['from']['id'] ?? null, $cat_id );
			$this->api->answer_callback_query( $cq['id'], 'آماده‌ست ✅' );
			$this->api->edit_message_text( $chat_id, $message_id, "✅ دسته انتخاب شد: <b>{$cat->telegram_label}</b>\n\nحالا عکس، کپشن و فایل/لینک دانلود را به هر ترتیبی بفرست.\n(اگر فایل زیر ۲۰ مگ را پیوست کنی، دیگر نیازی به لینک نیست.)" );
			STI_Logger::info( "Session #{$session_id} باز شد برای دسته {$cat->telegram_label}", $session_id );
			return;
		}

		if ( 0 === strpos( $data, 'sti_bulk_start_' ) ) {
			$cat_id = (int) str_replace( 'sti_bulk_start_', '', $data );
			$cat = STI_Category::get( $cat_id );
			if ( ! $cat ) {
				$this->api->answer_callback_query( $cq['id'], 'دسته‌بندی نامعتبر است.', true );
				return;
			}
			$open = STI_Session::get_open_for_chat( $chat_id );
			if ( $open ) {
				STI_Session::cancel( $open->id );
			}
			$this->set_bulk_category( $chat_id, $cat_id );
			$this->api->answer_callback_query( $cq['id'], 'حالت ثبت سریع فعال شد 🚀' );
			$this->api->edit_message_text(
				$chat_id, $message_id,
				"🚀 <b>حالت ثبت سریع فعال شد</b> — دسته: {$cat->telegram_label}\n\n" .
				"از این به بعد، هر فایلی که با کپشن (شامل File Name/Type/Code) بفرستی یا فوروارد کنی، به‌عنوان یک محصول جدا و مستقل شناسایی و ساخته می‌شود — می‌توانی ده‌ها فایل را پشت‌سرهم بفرستی.\n\n" .
				"⚠️ کپشن باید روی همان پیامِ فایل باشد (نه پیام جدا).\n\n" .
				"برای پایان: /done"
			);
			STI_Logger::info( "حالت ثبت سریع فعال شد برای دسته {$cat->telegram_label}" );
			return;
		}

		if ( 'sti_resume' === $data ) {
			$this->api->answer_callback_query( $cq['id'], 'ادامه می‌دهیم' );
			$this->api->edit_message_text( $chat_id, $message_id, '▶️ باشه، ادامه بده. هر چیزی که مونده رو بفرست.' );
			return;
		}

		if ( 'sti_new' === $data ) {
			$open = STI_Session::get_open_for_chat( $chat_id );
			if ( $open ) {
				STI_Session::cancel( $open->id );
			}
			$this->api->answer_callback_query( $cq['id'], 'لغو شد' );
			$this->api->edit_message_text( $chat_id, $message_id, '🗑 Session قبلی لغو شد.' );
			$this->send_registration_mode_menu( $chat_id );
			return;
		}

		if ( 0 === strpos( $data, 'sti_samecat_' ) ) {
			$cat_id = (int) str_replace( 'sti_samecat_', '', $data );
			$cat = STI_Category::get( $cat_id );
			if ( ! $cat || ! $cat->is_active ) {
				$this->api->answer_callback_query( $cq['id'], 'این دسته دیگر فعال نیست.', true );
				$this->send_registration_mode_menu( $chat_id );
				return;
			}
			$existing_open = STI_Session::get_open_for_chat( $chat_id );
			if ( $existing_open ) {
				$this->api->answer_callback_query( $cq['id'], 'یک Session باز داری، اول آن را کامل یا لغو کن.', true );
				return;
			}
			$session_id = STI_Session::create( $chat_id, $cq['from']['id'] ?? null, $cat_id );
			$this->api->answer_callback_query( $cq['id'], 'آماده‌ست ✅' );
			$this->api->send_message( $chat_id, "✅ محصول جدید در دسته‌ی <b>{$cat->telegram_label}</b>\n\nعکس، کپشن و فایل/لینک دانلود را بفرست." );
			STI_Logger::info( "Session #{$session_id} باز شد برای دسته {$cat->telegram_label} (ادامه‌ی سریع)", $session_id );
			return;
		}

		if ( 'sti_queue_publish_next' === $data ) {
			$next = STI_Session::get_oldest_queued();
			if ( ! $next ) { $this->api->answer_callback_query( $cq['id'], 'صف خالی است.', true ); return; }
			$result = STI_Scheduler::instance()->publish_now( $next->id );
			$this->api->answer_callback_query( $cq['id'], is_wp_error( $result ) ? 'انتشار ناموفق بود.' : 'یک محصول منتشر شد.' );
			$this->queue_flow( $chat_id );
			return;
		}

		if ( 'sti_queue_start' === $data || 'sti_queue_stop' === $data ) {
			STI_Scheduler::set_running( 'sti_queue_start' === $data );
			$this->api->answer_callback_query( $cq['id'], 'sti_queue_start' === $data ? 'صف فعال شد ▶️' : 'صف متوقف شد ⏸' );
			$this->queue_flow( $chat_id );
			return;
		}
	}

	/* =========================== CONTENT INGESTION =========================== */

	protected function ingest_message( $session, $message, $text ) {
		$chat_id = $session->chat_id;
		$updated = array();

		// 1) Document attachment (fallback path for small files).
		if ( ! empty( $message['document'] ) ) {
			$updated['doc_file_id']   = $message['document']['file_id'];
			$updated['doc_file_name'] = $message['document']['file_name'] ?? '';
			$this->api->send_message( $chat_id, '📎 فایل دریافت شد.' );
		}

		// 2) Photo -> featured image.
		if ( ! empty( $message['photo'] ) ) {
			$photos = $message['photo'];
			$largest = end( $photos );
			$updated['image_file_id'] = $largest['file_id'];

			$url = $this->api->get_file_url( $largest['file_id'] );
			if ( $url ) {
				// Persist file_id only. The image is downloaded securely just before product creation.
				$this->api->send_message( $chat_id, '🖼 تصویر دریافت شد.' );
			} else {
				$this->api->send_message( $chat_id, '⚠️ دانلود مستقیم تصویر از تلگرام ممکن نشد. لطفاً لینک تصویر را به‌صورت متن بفرست.' );
				STI_Logger::warning( 'دانلود تصویر از تلگرام ناموفق بود (محدودیت شبکه؟).', $session->id );
			}

			if ( ! empty( $message['caption'] ) ) {
				$this->parse_and_merge_caption( $message['caption'], $message['caption_entities'] ?? array(), $updated );
				$this->api->send_message( $chat_id, '📝 کپشن ثبت شد.' );
			}
		}

		// 3) Plain text: could be caption, could be a download link, could be an image URL.
		if ( empty( $message['document'] ) && empty( $message['photo'] ) && ! empty( $text ) ) {
			if ( STI_Caption_Parser::looks_like_caption( $text ) ) {
				$this->parse_and_merge_caption( $text, $message['entities'] ?? array(), $updated );
				$this->api->send_message( $chat_id, '📝 کپشن ثبت شد.' );
			} elseif ( STI_Caption_Parser::looks_like_download_link( $text ) ) {
				$url = STI_Caption_Parser::extract_url( $text );
				if ( preg_match( '/\.(jpg|jpeg|png|webp|gif)(\?|$)/i', $url ) && empty( $session->image_url ) ) {
					$updated['image_url'] = $url;
					$this->api->send_message( $chat_id, '🖼 لینک تصویر ثبت شد.' );
				} else {
					$updated['download_url_raw'] = $url;
					$this->api->send_message( $chat_id, '🔗 لینک دانلود ثبت شد.' );
				}
			} else {
				$this->api->send_message( $chat_id, '❓ این پیام تشخیص داده نشد. کپشن، عکس یا لینک دانلود بفرست. (یا /status برای دیدن وضعیت)' );
			}
		}

		if ( ! empty( $updated ) ) {
			STI_Session::update( $session->id, $updated );
			$session = STI_Session::get( $session->id );
		}

		if ( STI_Session::is_complete( $session ) ) {
			$this->api->send_message( $chat_id, '⚙️ اطلاعات کامل شد، در حال ساخت محصول...' );
			STI_Session::update( $session->id, array( 'status' => 'processing' ) );

			// Give this specific request more time, in case the host allows it -
			// then run the build directly (synchronous). We avoid relying on
			// WP-Cron/loopback here because on some hosts that mechanism itself
			// is unreliable, which left sessions stuck in "processing" forever
			// with no error reported at all.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 );
			}

			try {
				$session = STI_Session::get( $session->id ); // re-fetch fresh state
				$this->finalize_session( $session );
			} catch ( \Throwable $e ) {
				STI_Session::mark_error( $session->id, $e->getMessage() );
				STI_Logger::error( 'finalize_session exception: ' . $e->getMessage(), $session->id );
				$this->api->send_message( $chat_id, '❌ خطای غیرمنتظره در ساخت محصول: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Optional fallback entry point for hosts where WP-Cron/loopback DOES work
	 * reliably: the dashboard's "تلاش دوباره" (retry) button schedules this event
	 * as a backup path, in case the site's PHP process was killed mid-request
	 * before it could finish synchronously.
	 */
	/** Finalize a bulk item after Telegram has had time to deliver all album siblings. */
	public function finalize_bulk_session_by_id( $session_id, $group_id ) {
		$session = STI_Session::get( $session_id );
		if ( ! $session || 'open' !== $session->status ) { return; }
		$this->api = new STI_Telegram_API();
		$sibling = get_transient( 'sti_mg_' . $group_id );
		$updated = array();
		if ( is_array( $sibling ) ) {
			if ( ! empty( $sibling['image_file_id'] ) ) { $updated['image_file_id'] = $sibling['image_file_id']; }
			if ( empty( $session->caption_raw ) && ! empty( $sibling['caption'] ) ) { $this->parse_and_merge_caption( $sibling['caption'], $sibling['entities'] ?? array(), $updated ); }
		}
		if ( $updated ) { STI_Session::update( $session_id, $updated ); $session = STI_Session::get( $session_id ); }
		if ( ! STI_Session::is_complete( $session ) ) {
			STI_Session::mark_error( $session_id, 'تصویر یا اطلاعات این آیتم گروهی تلگرام تا زمان مقرر دریافت نشد.' );
			$this->api->send_message( $session->chat_id, '⚠️ محصول ثبت نشد؛ تصویر شاخص یا اطلاعات کامل در پیام‌های گروهی پیدا نشد.' );
			return;
		}
		STI_Session::update( $session_id, array( 'status' => 'processing' ) );
		$this->finalize_session( STI_Session::get( $session_id ) );
	}

	public function finalize_session_by_id( $session_id ) {
		$session = STI_Session::get( $session_id );
		if ( ! $session || 'processing' !== $session->status ) {
			return false;
		}
		$this->api = new STI_Telegram_API();
		try {
			$this->finalize_session( $session );
		} catch ( \Throwable $e ) {
			STI_Session::mark_error( $session_id, $e->getMessage() );
			STI_Logger::error( 'finalize_session_by_id exception: ' . $e->getMessage(), $session_id );
		}
		$updated = STI_Session::get( $session_id );
		return (bool) ( $updated && in_array( $updated->status, array( 'scheduled', 'published' ), true ) );
	}

	protected function parse_and_merge_caption( $text, $entities, &$updated ) {
		$parsed = STI_Caption_Parser::parse( $text, $entities );
		$updated['caption_raw'] = $text;
		if ( $parsed['file_name'] ) { $updated['file_name'] = $parsed['file_name']; }
		if ( $parsed['file_type'] ) { $updated['file_type'] = $parsed['file_type']; }
		if ( $parsed['file_code'] ) { $updated['file_code'] = $parsed['file_code']; }
		if ( $parsed['source_url'] ) { $updated['source_url'] = $parsed['source_url']; }
		if ( $parsed['dimensions'] ) { $updated['dimensions'] = $parsed['dimensions']; }
		if ( $parsed['resolution'] ) { $updated['resolution'] = $parsed['resolution']; }
		if ( $parsed['color'] ) { $updated['color'] = $parsed['color']; }
	}

	/* =========================== FINALIZE / BUILD PRODUCT =========================== */

	protected function finalize_session( $session ) {
		// محافظ: هاست‌هایی که هشدار PHP را به Exception تبدیل می‌کنند (همان خطای last_error) نباید محصول را بشکنند
		$prev = set_error_handler( function( $errno, $errstr, $file, $line ) {
			if ( $errno === E_WARNING || $errno === E_NOTICE || $errno === E_DEPRECATED || $errno === E_USER_WARNING || $errno === E_USER_NOTICE ) {
				if ( strpos( $errstr, 'Undefined property' ) !== false || strpos( $file, 'sanil-telegram' ) !== false || strpos( $file, 'sti-' ) !== false || strpos( $errstr, 'last_error' ) !== false ) {
					error_log( "STI suppressed: $errstr in $file:$line" );
					return true;
				}
			}
			return false;
		});

		try {
			$chat_id = $session->chat_id ?? 0;
			$notify_chat_id = isset( $session->notify_chat_id ) && null !== $session->notify_chat_id ? (int) $session->notify_chat_id : (int) $chat_id;
			$category = STI_Category::get( $session->category_id ?? 0 );

			STI_Logger::info( 'شروع ساخت محصول (نسخه‌ی افزونه: ' . STI_VERSION . ')', $session->id ?? 0 );

			$file_meta = array(
				'file_code'       => $session->file_code ?? '',
				'file_name'       => $session->file_name ?? '',
				'original_name'   => $session->doc_file_name ?? '',
				'category_folder' => $category ? ( $category->folder_key ?: STI_Category::sanitize_folder_key( $category->telegram_label, $category->id ) ) : '',
			);
			$storage_mode = $category ? STI_Category::storage_mode( $category ) : null;

			$final_url = $session->download_url_final ?? '';
			if ( empty( $final_url ) ) {
				$result = null;
				if ( ! empty( $session->doc_file_id ) ) {
					$tmp = $this->api->download_file_to_temp( $session->doc_file_id );
					if ( $tmp ) {
						$result = STI_File_Storage::process_local_temp_file( $tmp, $file_meta, $storage_mode );
						@unlink( $tmp );
						if ( is_wp_error( $result ) ) {
							STI_Logger::warning( 'دانلود مستقیم از تلگرام ناموفق بود، تلاش با لینک دستی: ' . $result->get_error_message(), $session->id ?? 0 );
							$result = null;
						}
					} else {
						$download_error = $this->api->get_last_error();
						STI_Logger::warning( 'دریافت فایل تلگرام ناموفق بود؛ جزئیات: ' . ( $download_error['message'] ?? 'نامشخص' ), $session->id ?? 0 );
					}
				}
				if ( ! $result && ! empty( $session->download_url_raw ) ) {
					$result = STI_File_Storage::process( $session->download_url_raw, $file_meta, $storage_mode );
				}
				if ( ! $result ) {
					STI_Session::mark_error( $session->id, 'دریافت فایل تلگرام ناموفق بود و لینک مستقیم جایگزین هم موجود نیست.' );
					if ( $notify_chat_id ) { $this->api->send_message( $notify_chat_id, '❌ دریافت فایل ناموفق بود.' ); }
					restore_error_handler();
					return;
				}
				if ( is_wp_error( $result ) ) {
					STI_Session::mark_error( $session->id, $result->get_error_message() );
					if ( $notify_chat_id ) { $this->api->send_message( $notify_chat_id, '❌ ذخیره‌سازی فایل ناموفق بود: ' . $result->get_error_message() ); }
					restore_error_handler();
					return;
				}
				$final_url = $result['url'];
				STI_Session::update( $session->id, array(
					'download_url_final' => $final_url,
					'file_size_bytes'    => $result['size_bytes'] ?? null,
				) );
			}

			$session = STI_Session::get( $session->id );
			$product_id = STI_Product_Builder::build( $session, $category );

			if ( is_wp_error( $product_id ) ) {
				STI_Logger::warning( 'ساخت محصول در تلاش اول ناموفق بود؛ تلاش مجدد... ' . $product_id->get_error_message(), $session->id );
				sleep( 2 );
				$session = STI_Session::get( $session->id );
				if ( $session ) {
					$product_id = STI_Product_Builder::build( $session, $category );
				}
			}

			if ( is_wp_error( $product_id ) ) {
				STI_Session::mark_error( $session->id, $product_id->get_error_message() );
				if ( $notify_chat_id ) { $this->api->send_message( $notify_chat_id, '❌ ساخت محصول ناموفق بود: ' . $product_id->get_error_message() ); }
				restore_error_handler();
				return;
			}

			STI_Scheduler::enqueue( $session->id, $product_id );
			$queue_status = STI_Scheduler::get_status();
			$position = $queue_status['queued_count'];
			$interval = $queue_status['interval_minutes'];
			$eta_minutes = $position * $interval;

			STI_Logger::success( "محصول #{$product_id} ساخته شد و به صف انتشار اضافه شد (موقعیت #{$position}).", $session->id );

			$edit_link = admin_url( "post.php?post={$product_id}&action=edit" );
			$queue_note = $queue_status['running']
				? "📬 در صف انتشار، موقعیت #{$position} (فاصله انتشار: هر {$interval} دقیقه، حدود {$eta_minutes} دقیقه دیگر)"
				: '⏸ صف انتشار الان متوقف است — تا فعال نشود منتشر نمی‌شود.';
			$kb = array(
				'inline_keyboard' => array( array(
					array( 'text' => '➕ ثبت محصول جدید', 'callback_data' => 'sti_new' ),
					array( 'text' => '☰ منو', 'callback_data' => 'sti_show_menu' ),
				) ),
			);
			if ( $notify_chat_id ) {
				$this->api->send_message( $notify_chat_id, "✅ محصول ساخته شد (پیش‌نویس)\n{$queue_note}\n🔗 {$edit_link}", $kb );
			}
		} catch ( \Throwable $e ) {
			restore_error_handler();
			STI_Logger::error( 'finalize_session exception (safe): ' . $e->getMessage(), $session->id ?? 0 );
			STI_Session::mark_error( $session->id ?? 0, 'خطای غیرمنتظره در ساخت محصول (safe): ' . $e->getMessage() );
			if ( isset( $notify_chat_id ) && $notify_chat_id && $this->api ) {
				$this->api->send_message( $notify_chat_id, '❌ خطای غیرمنتظره: ' . $e->getMessage() );
			}
			return;
		}
		restore_error_handler();
	}
}
