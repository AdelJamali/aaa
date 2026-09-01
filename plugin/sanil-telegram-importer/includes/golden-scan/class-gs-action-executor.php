<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — فاز ۳-ب: Action Executor.
 *
 * فقط تا WAITING_BOT پیش می‌رود. هیچ دانلود/محصولی اینجا ساخته نمی‌شود.
 * دقیقاً روی همان الگوی امتحان‌شده در Channel Import/GoldTel تکیه می‌کند:
 * press_button/start_bot_dialog + تشخیص soft-ok (query_id_invalid/timeout)
 * و FLOOD_WAIT — چیزی در آن دو ماژول تغییر نکرده، فقط الگویشان اینجا تکرار شده.
 */
class STI_GS_Action_Executor {

	/** یعنی کلیک قبلاً زده شده (یا فراتر) — اجرای دوباره باید Skip شود، هرگز کلیک دوباره نزن. */
	const PAST_STATES = array(
		'WAITING_BOT', 'BOT_RESPONSE', 'ERROR_MATCH', 'FILE_MATCHED',
		'DOWNLOAD_PENDING', 'DOWNLOADING', 'DOWNLOAD_FAILED', 'STORED',
		'MEDIA_BUILDING', 'MEDIA_FAILED', 'MEDIA_READY',
		'PRODUCT_BUILDING', 'PRODUCT_FAILED', 'PRODUCT_READY', 'REVIEW_READY',
	);

	public static function execute( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'executor-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, 90 ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط یک worker دیگر پردازش می‌شود.' );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}

			if ( in_array( $session['state'], self::PAST_STATES, true ) ) {
				STI_GS_Event::log( $session_id, 'action_executor', 'ok',
					'کلیک قبلاً زده شده — Skip (هرگز دوباره کلیک نمی‌زنیم).',
					array( 'stage' => 'action_executor', 'reason' => 'already_completed', 'current_state' => $session['state'] )
				);
				return array( 'state' => $session['state'], 'skipped' => true );
			}

			// فقط از BUTTON_FOUND (اولین تلاش) یا ERROR_CLICK (تلاش دوباره) وارد شو —
			// از WAITING_BOT/DONE عمداً رد می‌شویم تا دکمه هرگز دوبار زده نشود.
			//
			// Invariant (۱۰.۸.۲): WAITING_BOT ≠ BUTTON_FOUND. تبدیل state فقط
			// از مسیرهای صریح recovery (requeue_click / timeout_recovery) انجام
			// می‌شود، نه صرفاً با کلیک کاربر روی «Execute Action».
			if ( ! in_array( (string) $session['state'], array( 'BUTTON_FOUND', 'ERROR_CLICK' ), true ) ) {
				$reason = 'INVALID_STATE: Session باید BUTTON_FOUND یا ERROR_CLICK باشد (الان: ' . $session['state'] . ').';
				STI_GS_Event::log( $session_id, 'action_executor', 'error', $reason );
				return new WP_Error( 'sti_gs_invalid_state', $reason );
			}

			$button = json_decode( (string) ( $session['button_payload'] ?? '' ), true );
			if ( ! is_array( $button ) ) {
				self::fail( $session_id, 'BUTTON_PAYLOAD_MISSING: اطلاعات دکمه‌ی ذخیره‌شده خراب است.' );
				return new WP_Error( 'sti_gs_bad_payload', 'button_payload خراب است.' );
			}

			$method = (string) $session['button_resolution_method'];
			self::human_delay( 1.5, 4.0 );

			if ( 'callback' === $method ) {
				return self::execute_callback( $session, $button );
			}
			if ( 'deep_link' === $method ) {
				return self::execute_deep_link( $session, $button );
			}

			self::fail( $session_id, "UNSUPPORTED_BUTTON_TYPE: نوع دکمه «{$method}» هنوز توسط Action Executor پشتیبانی نمی‌شود." );
			return new WP_Error( 'sti_gs_unsupported', 'نوع دکمه پشتیبانی نمی‌شود.' );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
			if ( class_exists( 'STI_MTProto' ) ) {
				// طبق تجربه‌ی مستندشده‌ی همین افزونه: بدون این، worker پس‌زمینه‌ی
				// MadelineProto رها می‌ماند و بعد از چند بار هاست را OOM می‌کند.
				STI_MTProto::stop_client();
			}
		}
	}

	protected static function execute_callback( $session, $button ) {
		$session_id = (int) $session['id'];
		$channel    = STI_GS_Channel::get( (int) $session['channel_id'] );
		$message    = self::load_message( (int) $session['message_pk'] );

		if ( ! $channel || ! (int) $channel['chat_id'] || ! $message || ! (int) $message['message_id'] ) {
			self::fail( $session_id, 'MISSING_CONTEXT: chat_id یا message_id تلگرام برای این Session موجود نیست.' );
			return new WP_Error( 'sti_gs_no_context', 'اطلاعات لازم برای کلیک موجود نیست.' );
		}

		$peer   = (int) $channel['chat_id'];
		$msg_id = (int) $message['message_id'];
		$data   = (string) ( $button['data'] ?? '' );

		$request = array( 'peer' => $peer, 'msg_id' => $msg_id, 'data' => $data );
		$t0 = microtime( true );
		$answer = STI_MTProto::instance()->press_button( $peer, $msg_id, $data );
		$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		$ok = false;
		$soft_ok = false;
		$err_msg = '';

		if ( is_wp_error( $answer ) ) {
			$err_low = mb_strtolower( $answer->get_error_message() );
			// بعضی بات‌ها پاسخ کالبک نمی‌دهند ولی باز هم فایل می‌فرستند — همان استثنای شناخته‌شده.
			if ( false !== strpos( $err_low, 'query_id_invalid' )
				|| false !== strpos( $err_low, 'bot_response_timeout' )
				|| false !== strpos( $err_low, 'timeout' ) ) {
				$ok = true;
				$soft_ok = true;
			} else {
				$err_msg = $answer->get_error_message();
			}
		} else {
			$ok = true;
		}

		$response = $soft_ok
			? array( 'soft_ok' => true, 'reason' => $err_msg )
			: ( is_wp_error( $answer ) ? array( 'error' => $answer->get_error_message() ) : $answer );

		STI_GS_Event::log(
			$session_id, 'action_executor', $ok ? 'ok' : 'error',
			$ok
				? ( $soft_ok ? "کلیک callback ارسال شد (پاسخ فوری نداد، احتمالاً فایل می‌رسد) — {$elapsed_ms}ms" : "کلیک callback موفق — {$elapsed_ms}ms" )
				: "کلیک callback ناموفق: {$err_msg} — {$elapsed_ms}ms",
			$request, $response
		);

		if ( ! $ok ) {
			$next_retry = STI_GS_Retry::flood_wait_until( $err_msg );
			STI_GS_Session::update( $session_id, array(
				'state' => 'ERROR_CLICK', 'stage' => 'action_executor',
				'error_reason' => 'CLICK_FAILED: ' . $err_msg,
				'attempts' => (int) $session['attempts'] + 1,
				'next_retry_at' => $next_retry,
			) );
			return new WP_Error( 'sti_gs_click_failed', $err_msg );
		}

		STI_GS_Session::update( $session_id, array(
			'state' => 'WAITING_BOT', 'stage' => 'action_executor',
			'clicked_at' => current_time( 'mysql' ), 'error_reason' => null,
			'attempts' => (int) $session['attempts'] + 1,
		) );

		return array( 'state' => 'WAITING_BOT', 'method' => 'callback', 'soft_ok' => $soft_ok, 'elapsed_ms' => $elapsed_ms );
	}

	protected static function execute_deep_link( $session, $button ) {
		$session_id = (int) $session['id'];
		$url = (string) ( $button['url'] ?? '' );

		/* معماری ۱۰.۸: Parser مستقل Deep Link — هم t.me و هم tg://resolve */
		$parsed = STI_GS_Deep_Link_Parser::parse( $url );
		if ( is_wp_error( $parsed ) || 'bot_start' !== (string) ( $parsed['kind'] ?? '' ) ) {
			// تلاش آخر: regex قبلی (فقط t.me) برای لینک‌های غیرعادی.
			if ( ! preg_match( '#t\.me/([A-Za-z0-9_]+)\?start=([A-Za-z0-9_-]+)#i', $url, $m ) ) {
				self::fail( $session_id, 'DEEP_LINK_UNPARSABLE: نمی‌توان bot_username/کد را از URL استخراج کرد: ' . $url );
				return new WP_Error( 'sti_gs_bad_deeplink', 'deep link قابل‌تجزیه نیست.' );
			}
			$bot_username = $m[1];
			$payload      = $m[2];
		} else {
			$bot_username = (string) $parsed['bot_username'];
			$payload      = (string) $parsed['start_param']; // string-only — قانون File Code
		}

		$request = array( 'bot_username' => $bot_username, 'payload' => $payload );
		$t0 = microtime( true );
		/* ۱۰.۸: messages.startBot رسمی با fallback به /start — نه فقط متن */
		$result = STI_MTProto::instance()->start_bot( $bot_username, $payload );
		$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $result ) ) {
			$err_msg = $result->get_error_message();
			STI_GS_Event::log( $session_id, 'action_executor', 'error', "start_bot ناموفق: {$err_msg} — {$elapsed_ms}ms", $request, array( 'error' => $err_msg ) );

			STI_GS_Session::update( $session_id, array(
				'state' => 'ERROR_CLICK', 'stage' => 'action_executor',
				'error_reason' => 'CLICK_FAILED: ' . $err_msg,
				'attempts' => (int) $session['attempts'] + 1,
				'next_retry_at' => STI_GS_Retry::flood_wait_until( $err_msg ),
			) );
			return new WP_Error( 'sti_gs_click_failed', $err_msg );
		}

		STI_GS_Event::log( $session_id, 'action_executor', 'ok', "start_bot موفق — {$elapsed_ms}ms", $request, is_array( $result ) ? $result : array( 'sent' => true ) );

		// این peer دقیق (با همین حروف‌کوچک/بزرگ که واقعاً resolve شد) باید به
		// هر دو لیست «ربات‌های شناخته‌شده» اضافه شود — چون find_recent_documents()
		// اول از STI_File_Hunter::collect_incoming() استفاده می‌کند (که خودش
		// known_bots() را می‌خواند، نه bot_peers()) و فقط اگر آن خالی برگردد
		// می‌رود سراغ فهرست bot_peers(). بدون هر دو، بسته به این‌که کدام مسیر
		// فعال شود، ممکن است فقط نسخه‌ی هاردکد 'FileechBot' امتحان شود.
		if ( class_exists( 'STI_Bot_Inbox' ) ) {
			STI_Bot_Inbox::learn_peer( $bot_username );
		}
		if ( class_exists( 'STI_File_Hunter' ) ) {
			STI_File_Hunter::learn_bot( $bot_username );
		}

		// شناسه‌ی عددی ربات هم ثبت شود، نه فقط username (که ممکن است تغییر کند) —
		// طبق نگرانی درست: فردا ممکن است چند ربات (A/B/C) پشت یک کانال باشند.
		$bot_chat_id = null;
		$info = STI_MTProto::instance()->chat_info( $bot_username );
		if ( ! is_wp_error( $info ) && ! empty( $info['id'] ) ) {
			$bot_chat_id = (int) $info['id'];
		}

		STI_GS_Session::update( $session_id, array(
			'state' => 'WAITING_BOT', 'stage' => 'action_executor',
			'clicked_at' => current_time( 'mysql' ), 'bot_username' => $bot_username,
			'bot_chat_id' => $bot_chat_id, 'error_reason' => null,
			'attempts' => (int) $session['attempts'] + 1,
		) );

		return array( 'state' => 'WAITING_BOT', 'method' => 'deep_link', 'bot_username' => $bot_username, 'elapsed_ms' => $elapsed_ms );
	}

	protected static function fail( $session_id, $reason ) {
		STI_GS_Session::update( $session_id, array(
			'state' => 'ERROR_CLICK', 'stage' => 'action_executor', 'error_reason' => $reason,
		) );
		STI_GS_Event::log( $session_id, 'action_executor', 'error', $reason );
	}

	protected static function load_message( $message_pk ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . STI_GS_DB::messages_table() . ' WHERE id = %d', (int) $message_pk
		), ARRAY_A );
	}

	protected static function human_delay( $min = 1.5, $max = 4.0 ) {
		if ( defined( 'STI_CI_TEST_MODE' ) && STI_CI_TEST_MODE ) {
			return;
		}
		$delay = $min + ( ( $max - $min ) * ( wp_rand( 0, 1000 ) / 1000 ) );
		usleep( (int) ( $delay * 1000000 ) );
	}
}
