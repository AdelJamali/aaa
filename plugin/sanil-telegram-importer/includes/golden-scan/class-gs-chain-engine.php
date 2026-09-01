<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Chain Engine: موتور تعامل زنجیره‌ای تلگرام.
 *
 * ═════════════════════════════════════════════════════════════════════════
 * این کلاس همان چیزی است که معماری «Button → File» را به
 * «Telegram Node → Telegram Node → Telegram Node → Asset» مهاجرت می‌دهد.
 *
 * نمونه‌ی واقعی که این موتور باید عبور کند:
 *
 *   Channel Post → Download Button → PartyManagerBot → Button
 *                → FileechBot → File
 *
 * قوانین غیرقابل‌مذاکره (از درخواست تغییر معماری):
 *
 *   ۱) One Stage Per Run حفظ می‌شود — اما «Stage» حالا «Chain Step» است.
 *      هر اجرای Worker دقیقاً یک گام را جلو می‌برد (init / advance / poll).
 *
 *   ۲) Recursion ممنوع است. هیچ متدی خودش را صدا نمی‌زند؛ پیشرفت فقط از
 *      طریق Step Log (جدول sti_gs_handoff_steps) + Worker تکرارشونده است.
 *
 *   ۳) Multi Worker Safety: همه‌ی عملیاتِ تغییردهنده claim/release اتمی
 *      روی locked_until/worker_id دارند (Auto Worker / Manual / Retry).
 *
 *   ۴) Loop Protection دو لایه است:
 *        a. Visited Bots — رباتی که دوباره در زنجیره ظاهر شود = حلقه
 *        b. MAX_HANDOFF_DEPTH = 20 — سقف عمق (نه ۵)
 *
 *   ۵) قانون File Code: payload/start_param فقط string — ممنوع:
 *      intval() / absint() / (int) / %d / sanitize_key().
 *
 *   ۶) پایان زنجیره (ASSET) به Identity Engine موجود سپرده می‌شود با اولویت:
 *      CODE_MATCH → NAME_MATCH → CAPTION_MATCH → HASH_MATCH
 *      (همان مسیر ثابت‌شده‌ی Candidate/Matcher — کپی‌ای ساخته نمی‌شود).
 * ═════════════════════════════════════════════════════════════════════════
 */
class STI_GS_Chain_Engine {

	const STEP_LOCK_SECONDS = 90;   // اجرای یک گام (شامل تماس تلگرام)
	const POLL_LOCK_SECONDS = 45;   // poll — فقط خواندن
	const BOT_TIMEOUT_SEC   = 900;  // هماهنگ با Bot Candidate Collector

	/* ═══════════════ Feature Flag: gs_chain_mode ═══════════════ */

	/**
	 * حالت فعلی زنجیره: legacy | auto | chain.
	 *   legacy → مسیر قبلی کاملاً دست‌نخورده (Button → File)
	 *   auto   → Asset → مسیر قدیم | DeepLink/Button/Bot → مسیر جدید
	 *   chain  → همه‌چیز از زنجیره عبور می‌کند
	 */
	public static function mode() {
		$m = class_exists( 'STI_Settings' )
			? STI_Settings::get( STI_GS_Node::MODE_OPTION, STI_GS_Node::MODE_AUTO )
			: get_option( 'sti_gs_chain_mode', STI_GS_Node::MODE_AUTO );
		$m = (string) $m;
		if ( ! in_array( $m, array( STI_GS_Node::MODE_LEGACY, STI_GS_Node::MODE_AUTO, STI_GS_Node::MODE_CHAIN ), true ) ) {
			$m = STI_GS_Node::MODE_AUTO;
		}
		return $m;
	}

	public static function set_mode( $mode ) {
		$mode = (string) $mode;
		if ( ! in_array( $mode, array( STI_GS_Node::MODE_LEGACY, STI_GS_Node::MODE_AUTO, STI_GS_Node::MODE_CHAIN ), true ) ) {
			return false;
		}
		if ( class_exists( 'STI_Settings' ) ) {
			STI_Settings::update( array( STI_GS_Node::MODE_OPTION => $mode ) );
		} else {
			update_option( 'sti_gs_chain_mode', $mode, true );
		}
		return true;
	}

	/* ═══════════════ گام ۰: init — تصمیم مسیر + گام اول ═══════════════ */

	/**
	 * فقط از SCANNED صدا زده می‌شود. پیام مبدأ را طبقه‌بندی می‌کند:
	 *   • گره‌ی قابل اجرا (DeepLink/Button/Bot/WebApp/Invite/Gate) → زنجیره
	 *   • در غیر این صورت → حالت legacy (روی خود Session ثبت می‌شود تا
	 *     نگاشت Stage، قدم بعدی را به Resolver قدیمی بدهد — سازگاری عقب‌رو).
	 *
	 * @return array|WP_Error
	 */
	public static function init( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'chain-init-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, 60 ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط worker دیگری پردازش می‌شود.' );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}
			if ( 'SCANNED' !== (string) $session['state'] ) {
				return array( 'state' => $session['state'], 'skipped' => true, 'no_progress' => true );
			}

			$mode = self::mode();
			if ( STI_GS_Node::MODE_LEGACY === $mode ) {
				// حالت legacy: اصلاً دست به کاری نمی‌زنیم — Resolver قدیمی ادامه می‌دهد.
				return array( 'state' => 'SCANNED', 'skipped' => true, 'no_progress' => true, 'mode' => $mode );
			}

			$message = self::load_message( (int) $session['message_pk'] );
			if ( ! $message ) {
				self::fallback_to_legacy( $session_id, 'پیام مبدأ در sti_gs_messages پیدا نشد.' );
				return array( 'state' => 'SCANNED', 'skipped' => true, 'no_progress' => true, 'decision' => 'legacy' );
			}

			$raw = json_decode( (string) ( $message['raw_json'] ?? '' ), true );
			if ( ! is_array( $raw ) ) {
				self::fallback_to_legacy( $session_id, 'raw_json پیام مبدأ قابل decode نیست.' );
				return array( 'state' => 'SCANNED', 'skipped' => true, 'no_progress' => true, 'decision' => 'legacy' );
			}

			$node = STI_GS_Node_Classifier::classify( $raw );
			STI_GS_Artifact::log( $session_id, 'chain_init_classified', array(
				'node_type' => $node->type,
				'label'     => STI_GS_Node::type_label( $node->type ),
				'text'      => mb_substr( $node->text, 0, 200 ),
				'mode'      => $mode,
			) );

			// فقط گره‌های قابل اجرا وارد زنجیره می‌شوند؛ ASSET/UNKNOWN/متن ساده → legacy.
			if ( ! $node->is_actionable() ) {
				self::fallback_to_legacy( $session_id, 'گره‌ی مبدأ قابل اجرا نیست (' . STI_GS_Node::type_label( $node->type ) . ').' );
				return array( 'state' => 'SCANNED', 'skipped' => true, 'no_progress' => true, 'decision' => 'legacy' );
			}

			// کانتکست دکمه‌ی کانال: peer و msg_id برای callback لازم است.
			$channel = STI_GS_Channel::get( (int) $session['channel_id'] );
			if ( $channel && (int) $channel['chat_id'] ) {
				$node->peer   = (int) $channel['chat_id'];
				$node->msg_id = (int) $message['message_id'];
			}
			if ( STI_GS_Node::NODE_GATE === $node->type && '' !== (string) $session['file_code'] ) {
				$node->text = STI_GS_Node::string_code( $session['file_code'] );
			}

			$step_id = STI_GS_Handoff_Steps::append( $session_id, $node, STI_GS_Handoff_Steps::STATUS_PENDING );
			if ( is_wp_error( $step_id ) ) {
				self::fail_chain( $session_id, 'CHAIN_INIT_FAILED', $step_id->get_error_message() );
				return new WP_Error( 'sti_gs_chain_init_failed', $step_id->get_error_message() );
			}

			STI_GS_Session::update( $session_id, array(
				'state'             => 'CHAIN_STEP',
				'stage'             => 'chain_engine',
				'chain_mode'        => $mode,
				'chain_current_step'=> 1,
				'error_reason'      => null,
			) );

			STI_GS_Event::log( $session_id, 'chain_engine', 'ok',
				'زنجیره شروع شد: گام ۱ = ' . STI_GS_Node::type_label( $node->type ) . ' (حالت ' . $mode . ').',
				array( 'mode' => $mode, 'node' => $node->to_array() ) );

			return array( 'state' => 'CHAIN_STEP', 'step_no' => 1, 'node_type' => $node->type );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
			if ( class_exists( 'STI_MTProto' ) ) {
				STI_MTProto::stop_client();
			}
		}
	}

	/* ═══════════════ گام ۱: advance — اجرای دقیقاً یک گام ═══════════════ */

	/**
	 * از CHAIN_STEP (گام عادی) یا CHAIN_FAILED (تلاش دوباره) صدا زده می‌شود.
	 * دقیقاً یک گام زنجیره را به تلگرام ارسال می‌کند (کلیک / startBot / متن /
	 * دعوت) و Session را در CHAIN_WAITING می‌گذارد. هرگز خودش را صدا نمی‌زند.
	 *
	 * @return array|WP_Error
	 */
	public static function advance( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'chain-step-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, self::STEP_LOCK_SECONDS ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط worker دیگری پردازش می‌شود.' );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}
			if ( ! in_array( (string) $session['state'], array( 'CHAIN_STEP', 'CHAIN_FAILED' ), true ) ) {
				$reason = 'INVALID_STATE: Session باید CHAIN_STEP یا CHAIN_FAILED باشد (الان: ' . $session['state'] . ').';
				STI_GS_Event::log( $session_id, 'chain_engine', 'error', $reason );
				return new WP_Error( 'sti_gs_invalid_state', $reason );
			}

			/* حفاظت عمق — سقف ۲۰، نه ۵ */
			$depth = STI_GS_Handoff_Steps::depth( $session_id );
			if ( $depth >= STI_GS_Node::MAX_HANDOFF_DEPTH ) {
				self::fail_chain( $session_id, 'CHAIN_DEPTH_EXCEEDED',
					sprintf( 'زنجیره به سقف عمق %d گام رسید — احتمالاً حلقه‌ای بدون ربات تکراری.', STI_GS_Node::MAX_HANDOFF_DEPTH ) );
				return new WP_Error( 'sti_gs_chain_depth', 'سقف عمق زنجیره رد شد.' );
			}

			$step = STI_GS_Handoff_Steps::current( $session_id );
			if ( ! $step ) {
				self::fail_chain( $session_id, 'CHAIN_NO_STEP', 'گامی برای اجرا وجود ندارد.' );
				return new WP_Error( 'sti_gs_chain_no_step', 'گامی برای اجرا وجود ندارد.' );
			}

			$node = STI_GS_Handoff_Steps::row_to_node( $step );

			/* حفاظت حلقه‌ی ربات‌ها — PartyManagerBot → FileechBot → PartyManagerBot = Loop */
			if ( '' !== $node->bot_username && STI_GS_Handoff_Steps::has_bot_loop( $session_id, $node->bot_username ) ) {
				self::fail_chain( $session_id, 'CHAIN_LOOP_DETECTED',
					'ربات «' . $node->bot_username . '» قبلاً در این زنجیره دیده شده — حلقه متوقف شد.' );
				return new WP_Error( 'sti_gs_chain_loop', 'حلقه‌ی ربات شناسایی شد.' );
			}

			/* تلاش دوباره‌ی یک گامِ شکست‌خورده */
			$step_attempts = (int) ( $step['attempts'] ?? 0 ) + 1;
			if ( STI_GS_Handoff_Steps::STATUS_FAILED === (string) $step['status'] ) {
				STI_GS_Handoff_Steps::mark( (int) $step['id'], STI_GS_Handoff_Steps::STATUS_PENDING, array(
					'attempts'    => $step_attempts,
					'retry_at'    => current_time( 'mysql' ),
					'error_reason'=> null,
				) );
				STI_GS_Event::log( $session_id, 'chain_engine', 'retry',
					'تلاش دوباره برای گام ' . (int) $step['step_no'] . ' (' . STI_GS_Node::type_label( $node->type ) . ').' );
			}

			self::human_delay( 1.5, 4.0 );

			$t0 = microtime( true );
			$result = STI_GS_Node_Processor::process( $node );
			$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			if ( is_wp_error( $result ) ) {
				$err_msg = $result->get_error_message();
				STI_GS_Handoff_Steps::mark_failed( (int) $step['id'], $err_msg, array(
					'attempts'   => $step_attempts,
					'elapsed_ms'=> $elapsed_ms,
				) );
				$next_retry = STI_GS_Retry::flood_wait_until( $err_msg );
				STI_GS_Session::update( $session_id, array(
					'state'         => 'CHAIN_FAILED',
					'stage'         => 'chain_engine',
					'error_reason'  => mb_substr( 'CHAIN_STEP_FAILED: ' . $err_msg, 0, 250 ),
					'attempts'      => (int) $session['attempts'] + 1,
					'next_retry_at' => $next_retry,
				) );
				STI_GS_Artifact::log( $session_id, 'chain_step_failed', array(
					'step_no' => (int) $step['step_no'], 'node_type' => $node->type,
					'error' => $err_msg, 'elapsed_ms' => $elapsed_ms,
				) );
				STI_GS_Event::log( $session_id, 'chain_engine', 'error',
					'گام ' . (int) $step['step_no'] . ' (' . STI_GS_Node::type_label( $node->type ) . ') ناموفق: ' . $err_msg );
				return new WP_Error( 'sti_gs_chain_step_failed', $err_msg );
			}

			/* موفق — گام done شد و منتظر پاسخ ربات می‌مانیم */
			STI_GS_Handoff_Steps::mark_done( (int) $step['id'], array(
				'result'    => is_array( $result ) ? $result : array( 'ok' => true ),
				'elapsed_ms'=> $elapsed_ms,
				'done_at'   => current_time( 'mysql' ),
			) );

			$bot_username = STI_GS_Node::string_code( $node->bot_username );
			$bot_chat_id  = $node->bot_chat_id ? (int) $node->bot_chat_id : null;
			if ( '' !== $bot_username && ! $bot_chat_id && class_exists( 'STI_MTProto' ) ) {
				try {
					// شناسه‌ی عددی ربات برای poll مطمئن‌تر است.
					$info = STI_MTProto::instance()->chat_info( $bot_username );
					if ( ! is_wp_error( $info ) && ! empty( $info['id'] ) ) {
						$bot_chat_id = (int) $info['id'];
					}
				} catch ( \Throwable $e ) {
					// کراش فیبر MadelineProto نباید گامِ موفق را خراب کند؛
					// bot_chat_id اختیاری است و poll با username هم کار می‌کند.
					STI_GS_Event::log( $session_id, 'chain_engine', 'retry',
						'chat_info برای ' . $bot_username . ' ناموفق (بی‌اهمیت): ' . $e->getMessage() );
				}
			}

			STI_GS_Session::update( $session_id, array(
				'state'             => 'CHAIN_WAITING',
				'stage'             => 'chain_engine',
				'clicked_at'        => current_time( 'mysql' ),
				'bot_username'      => '' !== $bot_username ? $bot_username : (string) ( $session['bot_username'] ?? '' ),
				'bot_chat_id'       => $bot_chat_id ?: ( $session['bot_chat_id'] ?? null ),
				'chain_current_step'=> (int) $step['step_no'],
				'error_reason'      => null,
				// شمارنده‌ی تلاش را بالا نمی‌بریم: هر گام موفق زنجیره یک
				// «پیشرفت» است، نه تلاش. بالا بردن آن باعث می‌شد زنجیره‌های
				// واقعی (۵+ گام) بعد از ۵ گام به سقف MAX_ATTEMPTS برسند و
				// Session شش ساعت کنار گذاشته شود.
				'attempts'          => 0,
			) );

			STI_GS_Artifact::log( $session_id, 'chain_step_done', array(
				'step_no' => (int) $step['step_no'],
				'node_type' => $node->type,
				'method'  => is_array( $result ) ? ( $result['method'] ?? '' ) : '',
				'elapsed_ms' => $elapsed_ms,
			) );
			STI_GS_Event::log( $session_id, 'chain_engine', 'ok',
				'گام ' . (int) $step['step_no'] . ' انجام شد: ' . STI_GS_Node::type_label( $node->type )
				. ( '' !== $bot_username ? ' → ' . $bot_username : '' ) . ' — منتظر پاسخ ربات.',
				array( 'node' => $node->to_array() ), is_array( $result ) ? $result : array() );

			return array( 'state' => 'CHAIN_WAITING', 'step_no' => (int) $step['step_no'], 'node_type' => $node->type, 'method' => is_array( $result ) ? ( $result['method'] ?? '' ) : '' );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
			if ( class_exists( 'STI_MTProto' ) ) {
				STI_MTProto::stop_client();
			}
		}
	}

	/* ═══════════════ گام ۲: poll — خواندن پاسخ ربات ═══════════════ */

	/**
	 * از CHAIN_WAITING صدا زده می‌شود. یک‌بار پاسخ ربات جاری را می‌خواند:
	 *
	 *   • فایل (ASSET) رسیده؟  → ثبت در inbox + رفتن به مسیر قدیم Asset
	 *     (WAITING_BOT → Collector → Matcher با اولویت CODE→NAME→CAPTION→HASH)
	 *   • گره‌ی جدید (Button/DeepLink/Bot/WebApp/Invite/Gate) رسیده؟
	 *     → گام بعدی ساخته می‌شود و Session به CHAIN_STEP می‌رود
	 *   • متن اطلاعاتی؟ → ثبت می‌شود و همچنان منتظر می‌مانیم (با سقف)
	 *   • هیچ‌چیز؟ → CHAIN_WAITING می‌ماند؛ بعد از مهلت → CHAIN_FAILED
	 *
	 * @return array|WP_Error
	 */
	public static function poll( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'chain-poll-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, self::POLL_LOCK_SECONDS ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط worker دیگری پردازش می‌شود.' );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}
			if ( 'CHAIN_WAITING' !== (string) $session['state'] ) {
				return array( 'state' => $session['state'], 'skipped' => true, 'no_progress' => true );
			}

			$step = STI_GS_Handoff_Steps::current( $session_id );
			if ( ! $step ) {
				// Session زنجیره‌ای بدون گام (حالت ناسازگار): به مسیر قدیم برگرد.
				STI_GS_Session::update( $session_id, array(
					'state' => 'WAITING_BOT', 'stage' => 'chain_engine',
					'error_reason' => 'CHAIN_NO_STEP_RECOVERY: گامی نبود؛ به مسیر قدیم برگشت.',
				) );
				return array( 'state' => 'WAITING_BOT', 'waiting' => true );
			}

			$clicked_ts = self::to_ts( $session['clicked_at'] );
			$timeout    = class_exists( 'STI_GS_Bot_Candidate_Collector' )
				? STI_GS_Bot_Candidate_Collector::BOT_TIMEOUT_SEC
				: self::BOT_TIMEOUT_SEC;
			$since_ts   = $clicked_ts ? max( 0, $clicked_ts - 10 ) : 0;

			$node = STI_GS_Handoff_Steps::row_to_node( $step );
			$peer = STI_GS_Node::string_code( $node->bot_username );
			if ( '' === $peer && $node->peer ) {
				$peer = (string) $node->peer;
			}

			/* ۱) poll سراسری — فایل‌های همه‌ی ربات‌های شناخته‌شده در inbox ثبت می‌شوند */
			$global = array( 'polled' => false );
			if ( class_exists( 'STI_GS_Bot_Candidate_Collector' ) ) {
				$global = STI_GS_Bot_Candidate_Collector::global_poll();
			}
			STI_GS_Artifact::log( $session_id, 'chain_global_poll', is_array( $global ) ? $global : array() );

			/* ۲) خواندن پیام‌های تازه‌ی ربات جاری (برای گره‌های میانی) */
			$new_messages = array();
			$step_meta    = json_decode( (string) ( $step['meta'] ?? '' ), true );
			$step_meta    = is_array( $step_meta ) ? $step_meta : array();
			$last_msg_id  = (int) ( $step_meta['last_msg_id'] ?? 0 );

			if ( '' !== $peer && class_exists( 'STI_MTProto' ) ) {
				$msgs = STI_MTProto::instance()->recent_peer_messages( $peer, 30, $since_ts );
				if ( is_wp_error( $msgs ) ) {
					STI_GS_Event::log( $session_id, 'chain_engine', 'error',
						'خواندن تاریخچه‌ی ' . $peer . ' ناموفق: ' . $msgs->get_error_message() );
					$msgs = array();
				}
				foreach ( (array) $msgs as $m ) {
					if ( (int) ( $m['id'] ?? 0 ) <= $last_msg_id ) {
						continue; // مصرف‌شده
					}
					$new_messages[] = $m;
				}
			}

			/* ۳) طبقه‌بندی پیام‌های تازه */
			$asset_found  = false;
			$asset_inbox  = 0;
			$max_msg_id   = $last_msg_id;

			foreach ( $new_messages as $m ) {
				$max_msg_id = max( $max_msg_id, (int) ( $m['id'] ?? 0 ) );
				$incoming   = STI_GS_Node_Classifier::classify( $m );

				if ( STI_GS_Node::NODE_ASSET === $incoming->type ) {
					/* پیش‌نمایش‌های بی‌نام (photo_123.jpg) فایل واقعی نیستند —
					   همان فلسفه‌ی Identity Engine: «نام‌دار در برابر بی‌نام». */
					if ( self::is_auto_named( (string) ( $m['file_name'] ?? '' ) ) ) {
						STI_GS_Artifact::log( $session_id, 'chain_preview_skipped', array(
							'file_name' => $m['file_name'] ?? '',
							'msg_id'    => $m['id'] ?? 0,
						) );
						continue;
					}
					$doc = $m;
					$doc['sender_chat_id'] = '' !== $peer ? $peer : (string) ( $m['peer_id'] ?? '' );
					if ( class_exists( 'STI_Bot_Inbox' ) ) {
						$inbox_id = STI_Bot_Inbox::record( $doc );
						if ( $inbox_id ) {
							$asset_found = true;
							$asset_inbox = (int) $inbox_id;
						}
					}
					continue;
				}

				if ( $incoming->is_actionable() ) {
					/* حفاظت حلقه‌ی ربات‌ها */
					if ( '' !== $incoming->bot_username
						&& STI_GS_Handoff_Steps::has_bot_loop( $session_id, $incoming->bot_username ) ) {
						self::fail_chain( $session_id, 'CHAIN_LOOP_DETECTED',
							'ربات «' . $incoming->bot_username . '» قبلاً در این زنجیره دیده شده — حلقه متوقف شد.' );
						return new WP_Error( 'sti_gs_chain_loop', 'حلقه‌ی ربات شناسایی شد.' );
					}

					/* گیت: اگر ربات کد می‌خواهد، کد همین Session را بفرست */
					if ( STI_GS_Node::NODE_GATE === $incoming->type && '' !== (string) ( $session['file_code'] ?? '' ) ) {
						$incoming->text = STI_GS_Node::string_code( $session['file_code'] );
					}

					$incoming->peer   = '' !== $peer ? $peer : $incoming->peer;
					$incoming->msg_id = (int) ( $m['id'] ?? 0 );

					$next_id = STI_GS_Handoff_Steps::append( $session_id, $incoming, STI_GS_Handoff_Steps::STATUS_PENDING );
					if ( is_wp_error( $next_id ) ) {
						if ( 'sti_gs_chain_depth' === $next_id->get_error_code() ) {
							self::fail_chain( $session_id, 'CHAIN_DEPTH_EXCEEDED', $next_id->get_error_message() );
							return new WP_Error( 'sti_gs_chain_depth', $next_id->get_error_message() );
						}
						STI_GS_Event::log( $session_id, 'chain_engine', 'error', 'افزودن گام بعدی ناموفق: ' . $next_id->get_error_message() );
						continue;
					}

					$next_step_no = (int) ( is_array( $next_id ) ? 0 : $next_id );
					STI_GS_Handoff_Steps::mark( (int) $next_id, STI_GS_Handoff_Steps::STATUS_PENDING, array(
						'last_msg_id' => (int) ( $m['id'] ?? 0 ),
						'from_msg_id' => (int) ( $m['id'] ?? 0 ),
						'from_peer'   => $peer,
					) );
					STI_GS_Session::update( $session_id, array(
						'state'             => 'CHAIN_STEP',
						'stage'             => 'chain_engine',
						'chain_current_step'=> $next_step_no ?: (int) $step['step_no'] + 1,
						'error_reason'      => null,
					) );
					STI_GS_Event::log( $session_id, 'chain_engine', 'ok',
						'گره‌ی جدید از ربات دریافت شد: ' . STI_GS_Node::type_label( $incoming->type )
						. ( '' !== $incoming->bot_username ? ' → ' . $incoming->bot_username : '' )
						. ' — گام بعدی ساخته شد.' );
					STI_GS_Artifact::log( $session_id, 'chain_next_node', $incoming->to_array() );
					return array( 'state' => 'CHAIN_STEP', 'next_node' => $incoming->type, 'step_no' => $next_step_no ?: (int) $step['step_no'] + 1 );
				}

				if ( STI_GS_Node::NODE_TEXT === $incoming->type && '' !== trim( (string) $incoming->text ) ) {
					/* متن اطلاعاتی — ثبت می‌شود ولی اکشنی ندارد */
					$info_id = STI_GS_Handoff_Steps::append( $session_id, $incoming, STI_GS_Handoff_Steps::STATUS_DONE );
					if ( ! is_wp_error( $info_id ) ) {
						STI_GS_Handoff_Steps::mark( (int) $info_id, STI_GS_Handoff_Steps::STATUS_DONE, array(
							'last_msg_id' => (int) ( $m['id'] ?? 0 ),
							'info'        => mb_substr( trim( (string) $incoming->text ), 0, 200 ),
						) );
					}
					if ( STI_GS_Handoff_Steps::consecutive_informational( $session_id ) >= STI_GS_Node::MAX_INFORMATIONAL_STEPS ) {
						self::fail_chain( $session_id, 'CHAIN_NO_PROGRESS',
							'بیش از ' . STI_GS_Node::MAX_INFORMATIONAL_STEPS . ' پیام اطلاعاتی پشت‌سرهم بدون گره‌ی قابل اجرا.' );
						return new WP_Error( 'sti_gs_chain_no_progress', 'زنجیره پیشرفتی نداشت.' );
					}
					continue;
				}

				/* UNKNOWN — ثبت و رد شدن */
				STI_GS_Artifact::log( $session_id, 'chain_unhandled_message', array(
					'msg_id' => $m['id'] ?? 0, 'type' => $incoming->type, 'text' => mb_substr( (string) $incoming->text, 0, 120 ),
				) );
			}

			/* ۴) فایل رسیده؟ → مسیر قدیم Asset (Collector → Matcher → Download) */
			if ( $asset_found ) {
				$bot_for_session = (string) ( $session['bot_username'] ?? '' );
				if ( '' === $bot_for_session && '' !== $peer ) {
					$bot_for_session = $peer;
				}
				$asset_node = new STI_GS_Node( STI_GS_Node::NODE_ASSET );
				$asset_node->bot_username = $bot_for_session;
				$asset_node->meta['inbox_id']   = $asset_inbox;
				$asset_node->meta['detected_at']= current_time( 'mysql' );
				$asset_id = STI_GS_Handoff_Steps::append( $session_id, $asset_node, STI_GS_Handoff_Steps::STATUS_DONE );
				if ( ! is_wp_error( $asset_id ) ) {
					STI_GS_Handoff_Steps::mark( (int) $asset_id, STI_GS_Handoff_Steps::STATUS_DONE, array(
						'last_msg_id' => $max_msg_id,
					) );
				}

				/* به مسیر قدیم Asset می‌رویم: WAITING_BOT → Collector → Matcher.
				   Collector خودش candidateها را با اولویت CODE→NAME→CAPTION→HASH
				   می‌سازد و Matcher تصمیم نهایی را می‌گیرد. */
				STI_GS_Session::update( $session_id, array(
					'state'        => 'WAITING_BOT',
					'stage'        => 'chain_engine',
					'bot_username' => $bot_for_session,
					'error_reason' => null,
				) );
				STI_GS_Event::log( $session_id, 'chain_engine', 'ok',
					'پایان زنجیره: فایل (ASSET) دریافت شد — تحویل به مسیر قدیم Asset (Matcher با اولویت CODE→NAME→CAPTION→HASH).' );
				STI_GS_Artifact::log( $session_id, 'chain_asset_detected', array(
					'inbox_id' => $asset_inbox, 'peer' => $peer, 'max_msg_id' => $max_msg_id,
				) );
				return array( 'state' => 'WAITING_BOT', 'asset_detected' => true, 'inbox_id' => $asset_inbox );
			}

			/* ذخیره‌ی آخرین msg_id دیده‌شده روی گام جاری */
			if ( $max_msg_id > $last_msg_id ) {
				STI_GS_Handoff_Steps::mark( (int) $step['id'], (string) $step['status'], array(
					'last_msg_id' => $max_msg_id,
				) );
			}

			/* ۵) مهلت پاسخ ربات تمام شد؟ → CHAIN_FAILED (worker دوباره تلاش می‌کند) */
			if ( $clicked_ts && ( time() - $clicked_ts ) > $timeout ) {
				self::fail_chain( $session_id, 'CHAIN_BOT_TIMEOUT',
					sprintf( 'پاسخی از ربات طی %d ثانیه نیامد (گام %d).', $timeout, (int) $step['step_no'] ) );
				return new WP_Error( 'sti_gs_chain_timeout', 'مهلت پاسخ ربات تمام شد.' );
			}

			STI_GS_Event::log( $session_id, 'chain_engine', 'retry',
				'هنوز پاسخ تازه‌ای از ربات نرسیده؛ Poll بعدی ادامه می‌دهد.' );
			return array( 'state' => 'CHAIN_WAITING', 'waiting' => true );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
			if ( class_exists( 'STI_MTProto' ) ) {
				STI_MTProto::stop_client();
			}
		}
	}

	/* ═══════════════ ابزارها ═══════════════ */

	/** ثبت خطای زنجیره روی Session (state=CHAIN_FAILED). */
	protected static function fail_chain( $session_id, $code, $reason ) {
		STI_GS_Session::update( $session_id, array(
			'state'        => 'CHAIN_FAILED',
			'stage'        => 'chain_engine',
			'error_reason' => mb_substr( $code . ': ' . $reason, 0, 250 ),
		) );
		STI_GS_Event::log( $session_id, 'chain_engine', 'error', $code . ': ' . $reason );
		STI_GS_Artifact::log( $session_id, 'chain_failed', array( 'code' => $code, 'reason' => $reason ) );
	}

	/** تصمیم legacy روی Session ثبت می‌شود تا نگاشت Stage به Resolver قدیمی برگردد. */
	protected static function fallback_to_legacy( $session_id, $reason ) {
		STI_GS_Session::update( $session_id, array(
			'chain_mode'   => STI_GS_Node::MODE_LEGACY,
			'stage'        => 'chain_engine',
			'error_reason' => null,
		) );
		STI_GS_Event::log( $session_id, 'chain_engine', 'ok',
			'زنجیره فعال نشد؛ مسیر قدیمی ادامه می‌دهد: ' . $reason );
	}

	protected static function load_message( $message_pk ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . STI_GS_DB::messages_table() . ' WHERE id = %d', (int) $message_pk
		), ARRAY_A );
	}

	/** نام خودکار تلگرام (photo_12345 / image-987 / img_1 / file_3) — همان فلسفه‌ی Identity Engine. */
	public static function is_auto_named( $file_name ) {
		$base = preg_replace( '~\.[A-Za-z0-9]{1,5}$~', '', trim( (string) $file_name ) );
		if ( '' === $base ) {
			return true;
		}
		return (bool) preg_match( '~^(photo|image|img|file|video|document)[ _\-]?\d*$~i', $base );
	}

	protected static function human_delay( $min = 1.5, $max = 4.0 ) {
		if ( defined( 'STI_CI_TEST_MODE' ) && STI_CI_TEST_MODE ) {
			return;
		}
		$delay = $min + ( ( $max - $min ) * ( wp_rand( 0, 1000 ) / 1000 ) );
		usleep( (int) ( $delay * 1000000 ) );
	}

	protected static function to_ts( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) ) {
			return 0;
		}
		$dt = date_create_from_format( 'Y-m-d H:i:s', $mysql_datetime, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}
}
