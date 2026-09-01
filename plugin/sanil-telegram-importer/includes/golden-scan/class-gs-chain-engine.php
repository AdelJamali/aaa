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

	/**
	 * ۱۰.۸.۳ — مهلت‌های عملیات (ثانیه). هر تماس Telegram باید کران‌دار
	 * باشد (STI_GS_Deadline::guard) — هیچ عملیاتی نباید Worker را برای
	 * همیشه معلق کند (Audit §۹-P1).
	 */
	const STEP_EXEC_TIMEOUT   = 60;   // اکشن یک گام (قفل گام ۹۰s است)
	const POLL_GLOBAL_TIMEOUT = 45;   // global_poll (هماهنگ با Collector)
	const POLL_PEER_TIMEOUT   = 25;   // recent_peer_messages
	const WAIT_STAGE_TIMEOUT  = 60;   // poll_bot_stage قدیم (waiting)

	/**
	 * سقف تلاش دوباره برای **همان گام** (per-hop retry bound).
	 *
	 * قرارداد ۱۰.۸.۳: retry همان hop فقط از HandoffStep.attempts شمارش
	 * می‌شود — Session.attempts متعلق به failure سطح Session است و اینجا
	 * استفاده نمی‌شود. موفقیت Action به‌تنهایی step.attempts را صفر
	 * نمی‌کند (موفقیت فقط یعنی «Action dispatch شد»؛ پاسخ معتبر Bot در
	 * poll() مشخص می‌شود). رسیدن به سقف → NEEDS_REVIEW.
	 */
	const STEP_ATTEMPTS_MAX = 3;

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
			if ( ! $node->is_executable() ) {
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

	/* ═══════════════ گام ۰.۵: waiting / recover — مسیر قدیم به زنجیره ═══════════════ */

	/**
	 * دیسپچر WAITING_BOT برای Sessionهای زنجیره‌ای (بدون گام).
	 *
	 * WAITING_BOT ≠ BUTTON_FOUND. اینجا فقط تصمیم گرفته می‌شود؛ هیچ‌وقت
	 * state به‌خاطر کلیک کاربر یا clicked_at=NULL عوض نمی‌شود:
	 *
	 *   • action dispatched (clicked_at موجود) + داخل پنجره → POLL
	 *   • action dispatched + timeout                      → recover() (Retry طبق evidence)
	 *   • action dispatched نشده (clicked_at=NULL)         → recover() فقط اگر evidence کافی
	 *   • بدون evidence                                     → NEEDS_REVIEW (داخل recover)
	 *
	 * clicked_at=NULL به‌تنهایی دلیل Recovery نیست — فقط یکی از ورودی‌های
	 * تصمیم است («ابتدا مشخص کن چرا Session در WAITING_BOT است»).
	 *
	 * @return array|WP_Error
	 */
	public static function waiting( $session_id ) {
		$session_id = (int) $session_id;
		$session    = STI_GS_Session::get( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
		}
		if ( 'WAITING_BOT' !== (string) $session['state'] ) {
			return array( 'state' => $session['state'], 'skipped' => true, 'no_progress' => true );
		}
		// گام دارد = مسیر Asset زنجیره (بعد از ASSET) — همان poll قدیم درست است.
		if ( STI_GS_Handoff_Steps::depth( $session_id ) > 0 ) {
			return array( 'state' => 'WAITING_BOT', 'skipped' => true, 'no_progress' => true );
		}
		if ( STI_GS_Node::MODE_LEGACY === STI_GS_Node::string_code( $session['chain_mode'] ?? '' ) ) {
			return array( 'state' => 'WAITING_BOT', 'skipped' => true, 'no_progress' => true, 'decision' => 'legacy' );
		}

		$clicked = self::to_ts( $session['clicked_at'] );
		$timeout = class_exists( 'STI_GS_Bot_Candidate_Collector' )
			? STI_GS_Bot_Candidate_Collector::BOT_TIMEOUT_SEC
			: self::BOT_TIMEOUT_SEC;

		// action واقعاً dispatch شده و هنوز داخل پنجره → poll واقعی (همان «Poll Bot» دستی).
		if ( $clicked && ( time() - $clicked ) < $timeout ) {
			STI_GS_Event::log( $session_id, 'chain_engine', 'retry',
				'WAITING_BOT داخل پنجره‌ی پاسخ ربات — Poll ادامه می‌دهد (action dispatched).' );
			if ( class_exists( 'STI_GS_Session_Ajax' ) ) {
				/* ۱۰.۸.۳ — Deadline: poll قدیم هم نباید معلق کند. */
				if ( class_exists( 'STI_GS_Deadline' ) ) {
					try {
						return STI_GS_Deadline::guard( function () use ( $session_id ) {
							return STI_GS_Session_Ajax::poll_bot_stage( $session_id );
						}, self::WAIT_STAGE_TIMEOUT, 'chain_wait_stage' );
					} catch ( \STI_GS_Deadline_Exception $e ) {
						return new WP_Error( 'sti_gs_tg_deadline', $e->getMessage() );
					}
				}
				return STI_GS_Session_Ajax::poll_bot_stage( $session_id );
			}
			return array( 'state' => 'WAITING_BOT', 'waiting' => true );
		}

		// timeout شده یا اصلاً action اجرا نشده → recover با evidence.
		// recover() خودش بدون evidence → NEEDS_REVIEW می‌کند.
		STI_GS_Event::log( $session_id, 'chain_engine', 'retry',
			'WAITING_BOT خارج از پنجره یا بدون action dispatched — بررسی evidence برای انتقال به زنجیره.' );
		return self::recover( $session_id );
	}

	/**
	 * انتقال یک Session قدیمی به زنجیره — فقط با evidence کافی.
	 *
	 * قانون: هیچ‌وقت حدس نمی‌زنیم. ترتیب evidence:
	 *
	 *   A) deep_link/start_param واقعی در button_payload (bot_start → DEEP_LINK،
	 *      bot_webapp → WEBAPP، invite → CHAT_INVITE)
	 *   B) bot_username + شاهد مستقل (file_code / clicked_at / button_url /
	 *      button_resolution_method) → BOT
	 *   C) پیام مبدأ قابل decode + طبقه‌بندی executable → همان گره
	 *
	 * TEXT به‌تنهایی evidence نیست. UNKNOWN هم نیست. اگر هیچ‌کدام نبود →
	 * NEEDS_REVIEW (نه CHAIN_STEP، نه fallback بی‌صدا).
	 *
	 * @return array|WP_Error
	 */
	public static function recover( $session_id ) {
		$session_id = (int) $session_id;

		// پیش‌بررسی سبک (بدون قفل): آیا اصلاً کاندید است؟
		$session = STI_GS_Session::get( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
		}
		$state = (string) $session['state'];
		if ( ! in_array( $state, array(
			'WAITING_BOT', 'BUTTON_FOUND', 'ERROR_CLICK', 'ERROR_BOT_TIMEOUT', 'ERROR_MATCH',
		), true ) ) {
			return array( 'state' => $state, 'skipped' => true, 'no_progress' => true );
		}
		// اگر خودِ زنجیره قبلاً این Session را legacy کرده، دوباره تلاش نکن.
		if ( STI_GS_Node::MODE_LEGACY === STI_GS_Node::string_code( $session['chain_mode'] ?? '' ) ) {
			return array( 'state' => $state, 'skipped' => true, 'no_progress' => true, 'decision' => 'legacy' );
		}
		// اگر گام دارد = مسیر Asset زنجیره — recover مربوط به اینجا نیست.
		if ( STI_GS_Handoff_Steps::depth( $session_id ) > 0 ) {
			return array( 'state' => $state, 'skipped' => true, 'no_progress' => true );
		}

		$worker_id = 'chain-recover-' . getmypid() . '-' . wp_generate_password( 6, false );
		if ( ! STI_GS_Session::claim( $session_id, $worker_id, 60 ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط worker دیگری پردازش می‌شود.' );
		}

		try {
			/* بعد از قفل، Session را دوباره می‌خوانیم (TOCTOU) و وضعیت را دوباره validate می‌کنیم. */
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}
			$state = (string) $session['state'];
			if ( ! in_array( $state, array(
				'WAITING_BOT', 'BUTTON_FOUND', 'ERROR_CLICK', 'ERROR_BOT_TIMEOUT', 'ERROR_MATCH',
			), true ) ) {
				return array( 'state' => $state, 'skipped' => true, 'no_progress' => true );
			}
			if ( STI_GS_Node::MODE_LEGACY === STI_GS_Node::string_code( $session['chain_mode'] ?? '' ) ) {
				return array( 'state' => $state, 'skipped' => true, 'no_progress' => true, 'decision' => 'legacy' );
			}
			if ( STI_GS_Handoff_Steps::depth( $session_id ) > 0 ) {
				return array( 'state' => $state, 'skipped' => true, 'no_progress' => true );
			}

			$evidence = array();
			$node     = null;

			/* ── A) معتبرترین: deep link واقعی در button_payload ── */
			$button = json_decode( (string) ( $session['button_payload'] ?? '' ), true );
			$url    = is_array( $button ) ? (string) ( $button['url'] ?? '' ) : '';
			if ( '' !== $url ) {
				$parsed = STI_GS_Deep_Link_Parser::parse( $url );
				$kind   = is_wp_error( $parsed ) ? '' : (string) ( $parsed['kind'] ?? '' );
				if ( 'bot_start' === $kind ) {
					$node = new STI_GS_Node( STI_GS_Node::NODE_DEEP_LINK );
					$node->bot_username = (string) $parsed['bot_username'];
					$node->set_payload( (string) $parsed['start_param'] ); // string-only
					$node->url          = $url;
					$node->confidence   = 90;
					$evidence[] = 'deep_link';
				} elseif ( 'bot_webapp' === $kind ) {
					$node = new STI_GS_Node( STI_GS_Node::NODE_WEBAPP );
					$node->bot_username = (string) $parsed['bot_username'];
					$node->set_payload( (string) ( $parsed['app_name'] ?? '' ) );
					$node->url          = $url;
					$node->confidence   = 85;
					$evidence[] = 'webapp_link';
				} elseif ( 'invite' === $kind ) {
					$node = new STI_GS_Node( STI_GS_Node::NODE_CHAT_INVITE );
					$node->set_payload( (string) ( $parsed['invite_hash'] ?? '' ) );
					$node->url          = $url;
					$node->confidence   = 85;
					$evidence[] = 'invite_link';
				}
			}

			/* ── B) bot_username + شاهد مستقل ── */
			if ( ! $node ) {
				$bot = STI_GS_Node::string_code( $session['bot_username'] ?? '' );
				if ( '' !== $bot ) {
					$witness = '';
					if ( '' !== STI_GS_Node::string_code( $session['file_code'] ?? '' ) ) {
						$witness = 'file_code';
					} elseif ( ! empty( $session['clicked_at'] ) ) {
						$witness = 'clicked_at';
					} elseif ( '' !== $url ) {
						$witness = 'button_url';
					} elseif ( ! empty( $session['button_resolution_method'] ) ) {
						$witness = 'button_method';
					}
					if ( '' !== $witness ) {
						$node = new STI_GS_Node( STI_GS_Node::NODE_BOT );
						$node->bot_username = $bot;
						$node->confidence   = 80;
						$evidence[] = 'bot_username+' . $witness;
					}
				}
			}

			/* ── C) پیام مبدأ قابل decode + طبقه‌بندی executable ── */
			if ( ! $node ) {
				$message = self::load_message( (int) $session['message_pk'] );
				if ( $message ) {
					$raw = json_decode( (string) ( $message['raw_json'] ?? '' ), true );
					if ( is_array( $raw ) ) {
						$classified = STI_GS_Node_Classifier::classify( $raw );
						if ( $classified->is_executable() ) {
							$channel = STI_GS_Channel::get( (int) $session['channel_id'] );
							if ( $channel && (int) $channel['chat_id'] ) {
								$classified->peer   = (int) $channel['chat_id'];
								$classified->msg_id = (int) $message['message_id'];
							}
							$node = $classified;
							$evidence[] = 'source_message:' . $node->type;
						}
					}
				}
			}

			if ( ! $node ) {
				// بدون evidence → NEEDS_REVIEW (نه CHAIN_STEP، نه fallback بی‌صدا).
				self::needs_review( $session_id, 'CHAIN_RECOVER_NO_EVIDENCE',
					'برای انتقال به زنجیره evidence کافی نیست (deep_link / bot+شاهد / پیام مبدأ هیچ‌کدام قابل استفاده نبودند).' );
				return array( 'state' => 'NEEDS_REVIEW', 'no_progress' => true, 'review' => true, 'from_state' => $state );
			}

			// گیت: اگر ربات کد می‌خواهد، کد همین Session را بفرست.
			if ( STI_GS_Node::NODE_GATE === $node->type && '' !== STI_GS_Node::string_code( $session['file_code'] ?? '' ) ) {
				$node->text = STI_GS_Node::string_code( $session['file_code'] );
			}

			$step_id = STI_GS_Handoff_Steps::append( $session_id, $node, STI_GS_Handoff_Steps::STATUS_PENDING );
			if ( is_wp_error( $step_id ) ) {
				self::fail_chain( $session_id, 'CHAIN_RECOVER_FAILED', $step_id->get_error_message() );
				return new WP_Error( 'sti_gs_chain_recover_failed', $step_id->get_error_message() );
			}

			/* مهاجرت صریح: chain_mode روی خود Session ثبت می‌شود (D6). */
			STI_GS_Session::update( $session_id, array(
				'state'             => 'CHAIN_STEP',
				'stage'             => 'chain_engine',
				'chain_mode'        => self::mode(),
				'chain_current_step'=> 1,
				'error_reason'      => null,
			) );

			STI_GS_Event::log( $session_id, 'chain_engine', 'ok',
				'Session از مسیر قدیم (' . $state . ') با evidence [' . implode( ', ', $evidence ) . '] به زنجیره منتقل شد — گام ۱ = '
				. STI_GS_Node::type_label( $node->type )
				. ( '' !== $node->bot_username ? ' → ' . $node->bot_username : '' ) . '.',
				array( 'from_state' => $state, 'evidence' => $evidence, 'node' => $node->to_array() ) );

			return array( 'state' => 'CHAIN_STEP', 'step_no' => 1, 'node_type' => $node->type, 'recovered' => true, 'from_state' => $state, 'evidence' => $evidence );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
			if ( class_exists( 'STI_MTProto' ) ) {
				STI_MTProto::stop_client();
			}
		}
	}

	/**
	 * ERROR_BOT_TIMEOUT برای Session زنجیره‌ایِ دارای گام (مسیر Asset قدیمی).
	 *
	 * پنجره بسته شده. Recovery = کلیک دوباره فقط اگر button_payload موجود
	 * باشد (اثبات اینکه همان action قابل تکرار است)؛ در غیر این صورت
	 * NEEDS_REVIEW — هیچ‌وقت state به‌خاطر کلیک کاربر عوض نمی‌شود.
	 *
	 * @return array|WP_Error
	 */
	public static function timeout_recovery( $session_id ) {
		$session_id = (int) $session_id;
		$session    = STI_GS_Session::get( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
		}
		if ( 'ERROR_BOT_TIMEOUT' !== (string) $session['state'] ) {
			return array( 'state' => $session['state'], 'skipped' => true, 'no_progress' => true );
		}

		if ( ! empty( $session['button_payload'] ) ) {
			STI_GS_Session::update( $session_id, array(
				'state'        => 'BUTTON_FOUND',
				'stage'        => 'chain_engine',
				'error_reason' => null,
			) );
			STI_GS_Event::log( $session_id, 'chain_engine', 'ok',
				'بعد از timeout پنجره، کلیک دوباره (BUTTON_FOUND) — بازیابی صریح با button_payload موجود.' );

			/**
			 * WP_Error عمدی (backoff) — ضدحلقه.
			 *
			 * اگر success برگردد، advance_one attempts را ریست می‌کند و چرخه‌ی
			 * بی‌پایان می‌شود: ERROR_BOT_TIMEOUT → BUTTON_FOUND → Execute
			 * Action (Bot Action) → WAITING_BOT → poll → ERROR_BOT_TIMEOUT → …
			 * با WP_Error → handle_failure → attempts+1 + backoff نمایی →
			 * بعد از MAX_ATTEMPTS: ۶ ساعت صبر (یک Bot Action در هر ۶ ساعت).
			 */
			return new WP_Error( 'sti_gs_requeue_backoff', 'کلیک دوباره زمان‌بندی شد (timeout) — تلاش بعدی با backoff (ضدحلقه).' );
		}

		self::needs_review( $session_id, 'CHAIN_TIMEOUT_NO_ACTION',
			'پنجره‌ی پاسخ ربات بسته شد و button_payload برای کلیک دوباره موجود نیست.' );
		return array( 'state' => 'NEEDS_REVIEW', 'no_progress' => true, 'review' => true );
	}

	/** ثبت NEEDS_REVIEW — state واقعی «نیاز به بررسی انسانی» (نه فقط برچسب JS). */
	protected static function needs_review( $session_id, $code, $reason ) {
		STI_GS_Session::update( (int) $session_id, array(
			'state'        => 'NEEDS_REVIEW',
			'stage'        => 'chain_engine',
			'error_reason' => mb_substr( $code . ': ' . $reason, 0, 250 ),
		) );
		STI_GS_Event::log( (int) $session_id, 'chain_engine', 'error', $code . ': ' . $reason );
		STI_GS_Artifact::log( (int) $session_id, 'chain_needs_review', array( 'code' => $code, 'reason' => $reason ) );
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

			/* ۱۰.۸.۵ — Rule 3: گام‌های TEXT قدیمی (پیش از ۱۰.۸.۴) که
			   «ارسال کد» بودند، به‌جای خطای غیرقابل‌اجرا، همان کد را
			   برای ربات می‌فرستند (send_text مثل GATE). کد: خودِ متن اگر
			   الگوی کد داشته باشد (مثل 228963153) وگرنه file_code سشن. */
			if ( STI_GS_Node::NODE_TEXT === $node->type ) {
				$text_code = STI_GS_Node_Classifier::extract_file_code( $node->text );
				if ( '' === $text_code && preg_match( '/^[A-Za-z0-9_\-]{4,32}$/', trim( (string) $node->text ) ) ) {
					$text_code = trim( (string) $node->text );
				}
				if ( '' === $text_code ) {
					$text_code = (string) ( $session['file_code'] ?? '' );
				}
				if ( '' !== $text_code ) {
					$node->type = STI_GS_Node::NODE_GATE; // ارسال متن = همان اکشن GATE
					$node->text = STI_GS_Node::string_code( $text_code );
					STI_GS_Artifact::log( $session_id, 'chain_text_step_as_code', array(
						'step_no'   => (int) $step['step_no'],
						'code'      => $text_code,
						'converted' => true,
					) );
				}
			}

			/* حفاظت حلقه‌ی ربات‌ها — PartyManagerBot → FileechBot → PartyManagerBot = Loop */
			if ( '' !== $node->bot_username && STI_GS_Handoff_Steps::has_bot_loop( $session_id, $node->bot_username ) ) {
				self::fail_chain( $session_id, 'CHAIN_LOOP_DETECTED',
					'ربات «' . $node->bot_username . '» قبلاً در این زنجیره دیده شده — حلقه متوقف شد.' );
				return new WP_Error( 'sti_gs_chain_loop', 'حلقه‌ی ربات شناسایی شد.' );
			}

			/* ── Per-hop retry bound (۱۰.۸.۳) ─────────────────────────────
			 * چرخه‌ی ممنوع:
			 *   CHAIN_WAITING → timeout → CHAIN_FAILED → advance → Bot Action
			 *   → CHAIN_WAITING → timeout → … (برای همیشه)
			 *
			 * قرارداد: retry همان گام فقط از HandoffStep.attempts شمارش
			 * می‌شود (Session.attempts = failure سطح Session؛ دست نمی‌خورد):
			 *
			 *   گام جدید       → step.attempts = 0   (append)
			 *   retry همان گام → step.attempts++     (سقف STEP_ATTEMPTS_MAX)
			 *   رسیدن به سقف   → NEEDS_REVIEW
			 *
			 * موفقیت Action به‌تنهایی step.attempts را صفر نمی‌کند — موفقیت
			 * فقط یعنی «Action dispatch شد»؛ پاسخ معتبر Bot در poll() مشخص
			 * می‌شود و timeout یعنی همان گام هنوز retry محسوب می‌شود.
			 *
			 * این بلوک هم مسیر process-failure (step.status=failed) و هم
			 * مسیر poll-timeout (step.status=done ولی state=CHAIN_FAILED)
			 * را پوشش می‌دهد: ورود از CHAIN_FAILED = retry، صرف‌نظر از
			 * status فعلی گام. (قبلاً فقط status=failed بررسی می‌شد و مسیر
			 * timeout هرگز شمارش نمی‌شد — همان حلقه‌ی کران‌ناشده.)
			 */
			$step_attempts = (int) ( $step['attempts'] ?? 0 ) + 1;
			if ( 'CHAIN_FAILED' === (string) $session['state'] ) {
				if ( $step_attempts > self::STEP_ATTEMPTS_MAX ) {
					STI_GS_Handoff_Steps::mark_failed( (int) $step['id'],
						'CHAIN_STEP_RETRY_EXHAUSTED: گام ' . (int) $step['step_no'] . ' (' . STI_GS_Node::type_label( $node->type ) . ') بعد از ' . self::STEP_ATTEMPTS_MAX . ' تلاش دوباره پاسخ معتبری نگرفت.',
						array( 'attempts' => $step_attempts ) );
					self::needs_review( $session_id, 'CHAIN_STEP_RETRY_EXHAUSTED',
						'گام ' . (int) $step['step_no'] . ' (' . STI_GS_Node::type_label( $node->type ) . ') به سقف تلاش دوباره (' . self::STEP_ATTEMPTS_MAX . ') رسید.' );
					return array( 'state' => 'NEEDS_REVIEW', 'no_progress' => true, 'review' => true );
				}

				// retry مجاز: گام (حتی done) به pending برمی‌گردد با attempts++
				STI_GS_Handoff_Steps::mark( (int) $step['id'], STI_GS_Handoff_Steps::STATUS_PENDING, array(
					'attempts'    => $step_attempts,
					'retry_at'    => current_time( 'mysql' ),
					'error_reason'=> null,
				) );
				STI_GS_Event::log( $session_id, 'chain_engine', 'retry',
					'تلاش دوباره برای گام ' . (int) $step['step_no'] . ' (' . STI_GS_Node::type_label( $node->type ) . ') — تلاش ' . $step_attempts . '/' . self::STEP_ATTEMPTS_MAX . '.' );
				STI_GS_Artifact::log( $session_id, 'chain_retry_scheduled', array(
					'step_no' => (int) $step['step_no'],
					'node_type' => $node->type,
					'attempt' => $step_attempts,
					'max'     => self::STEP_ATTEMPTS_MAX,
				) );
			}

			self::human_delay( 1.5, 4.0 );

			/* ۱۰.۸.۳ — وضعیت running + breadcrumb قبل از اکشن (مشاهده‌پذیری mid-flight). */
			$run_attempt = (int) ( $step['attempts'] ?? 0 ); // 0 = اجرای اولیه، n = retry #n
			STI_GS_Handoff_Steps::mark( (int) $step['id'], STI_GS_Handoff_Steps::STATUS_RUNNING, array(
				'run_started_at' => current_time( 'mysql' ),
			) );
			STI_GS_Artifact::log( $session_id, 'chain_step_started', array(
				'step_no'   => (int) $step['step_no'],
				'node_type' => $node->type,
				'attempt'   => $run_attempt,
			) );

			$t0 = microtime( true );

			/* ۱۰.۸.۳ — Deadline: اکشن تلگرام نباید هرگز Worker را معلق کند. */
			if ( class_exists( 'STI_GS_Deadline' ) ) {
				try {
					$result = STI_GS_Deadline::guard( function () use ( $node ) {
						return STI_GS_Node_Processor::process( $node );
					}, self::STEP_EXEC_TIMEOUT, 'chain_step_exec' );
				} catch ( \STI_GS_Deadline_Exception $e ) {
					$result = new WP_Error( 'sti_gs_tg_deadline', $e->getMessage() );
				}
			} else {
				$result = STI_GS_Node_Processor::process( $node );
			}

			$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			if ( is_wp_error( $result ) ) {
				$err_msg = $result->get_error_message();
				// قرارداد ۱۰.۸.۳: mark_failed فقط status/error را ثبت می‌کند و
				// attempts را افزایش نمی‌دهد. افزایش HandoffStep.attempts فقط
				// و فقط در retry gate (ورود از CHAIN_FAILED) انجام می‌شود —
				// تا «attempts = تعداد retry پس از اجرای اولیه» دقیق بماند
				// و مسیر timeout و process-failure هر دو initial=1 / retry=3
				// بدهند (در غیر این صورت شکست process یک retry را می‌خورد).
				STI_GS_Handoff_Steps::mark_failed( (int) $step['id'], $err_msg, array(
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

			/**
			 * ۱۰.۸.۴ — Response Correlation Anchor:
			 * در لحظه‌ی dispatch اکشن، anchor گام ذخیره می‌شود تا poll فقط
			 * پیام‌های بعد از این لحظه (و از همین peer) را به‌عنوان پاسخ
			 * بپذیرد (BUG-1/BUG-2). anchor_msg_id = آخرین msg_id شناخته‌شده
			 * قبل از اکشن.
			 */
			$anchor_meta   = json_decode( (string) ( $step['meta'] ?? '' ), true );
			$anchor_meta   = is_array( $anchor_meta ) ? $anchor_meta : array();
			$anchor_peer   = STI_GS_Node::string_code( $node->bot_username );
			if ( '' === $anchor_peer && $node->peer ) {
				$anchor_peer = (string) $node->peer;
			}
			STI_GS_Handoff_Steps::mark_done( (int) $step['id'], array(
				'result'         => is_array( $result ) ? $result : array( 'ok' => true ),
				'elapsed_ms'     => $elapsed_ms,
				'done_at'        => current_time( 'mysql' ),
				'action_at_ts'   => time(),
				'expected_peer'  => $anchor_peer,
				'anchor_msg_id'  => (int) ( $anchor_meta['last_msg_id'] ?? 0 ),
			) );

			$bot_username = STI_GS_Node::string_code( $node->bot_username );
			$bot_chat_id  = $node->bot_chat_id ? (int) $node->bot_chat_id : null;
			if ( '' !== $bot_username && ! $bot_chat_id && class_exists( 'STI_MTProto' ) ) {
				try {
					// شناسه‌ی عددی ربات برای poll مطمئن‌تر است.
					if ( class_exists( 'STI_GS_Deadline' ) ) {
						try {
							$info = STI_GS_Deadline::guard( function () use ( $bot_username ) {
								return STI_MTProto::instance()->chat_info( $bot_username );
							}, 15, 'chain_chat_info' );
						} catch ( \STI_GS_Deadline_Exception $e ) {
							$info = new WP_Error( 'sti_gs_tg_deadline', $e->getMessage() );
						}
					} else {
						$info = STI_MTProto::instance()->chat_info( $bot_username );
					}
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

			/**
			 * ۱۰.۸.۴ — گام جاری فقط «اجرایی» (BUG-2: گام‌های informational
			 * TEXT هرگز نباید گام جاری شوند؛ اگر از نسخه‌های قبل ردیف TEXT
			 * در انتهای زنجیره مانده باشد، نادیده گرفته می‌شود).
			 */
			$step = STI_GS_Handoff_Steps::current_executable( $session_id );
			if ( ! $step ) {
				// Session زنجیره‌ای بدون گام اجرایی (حالت ناسازگار): به مسیر قدیم برگرد.
				STI_GS_Session::update( $session_id, array(
					'state' => 'WAITING_BOT', 'stage' => 'chain_engine',
					'error_reason' => 'CHAIN_NO_STEP_RECOVERY: گام اجرایی‌ای نبود؛ به مسیر قدیم برگشت.',
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

			/* ═══ ۱۰.۸.۴ — Response Correlation: Anchor گام ═══
			 *
			 * هر گامی که اکشن dispatch کرده، در advance() این anchor را در
			 * meta خود ذخیره می‌کند:
			 *   expected_peer   — رباتی که باید پاسخ دهد
			 *   action_at_ts    — لحظه‌ی dispatch اکشن (unix)
			 *   anchor_msg_id   — آخرین msg_id شناخته‌شده قبل از اکشن
			 *
			 * فقط پیام‌هایی که:
			 *   ۱) از همان peer باشند (recent_peer_messages خودش این را تضمین می‌کند)
			 *   ۲) out نباشند (توسط خودِ Engine ارسال نشده باشند)
			 *   ۳) id > آخرین msg_id مصرف‌شده
			 *   ۴) date >= action_at (با tolerance ۱۰ ثانیه) — پیام‌های قبل
			 *      از اکشن هرگز پاسخ این اکشن نیستند
			 * …به‌عنوان «پاسخ معتبر» این گام پذیرفته می‌شوند.
			 */
			$step_meta_anchor = json_decode( (string) ( $step['meta'] ?? '' ), true );
			$step_meta_anchor = is_array( $step_meta_anchor ) ? $step_meta_anchor : array();
			$anchor_action_ts = (int) ( $step_meta_anchor['action_at_ts'] ?? 0 );
			if ( ! $anchor_action_ts ) {
				$anchor_action_ts = $clicked_ts; // backward compat: گام‌های قدیمی
			}
			$anchor_peer = (string) ( $step_meta_anchor['expected_peer'] ?? '' );
			if ( '' === $anchor_peer ) {
				$anchor_peer = $peer; // backward compat
			}

			/**
			 * ۱۰.۸.۳ — Breadcrumb: اگر هر تماس تلگرامی قفل شود، این
			 * Artifact دقیقاً نشان می‌دهد poll تا کجا پیش رفته (Audit §۹-P4).
			 */
			STI_GS_Artifact::log( $session_id, 'chain_poll_started', array(
				'step_no'   => (int) $step['step_no'],
				'node_type' => (string) $step['node_type'],
				'peer'      => $peer,
				'clicked_at'=> (string) $session['clicked_at'],
				'timeout'   => $timeout,
			) );

			/* ۱) poll سراسری — فایل‌های همه‌ی ربات‌های شناخته‌شده در inbox ثبت می‌شوند */
			$global = array( 'polled' => false );
			if ( class_exists( 'STI_GS_Bot_Candidate_Collector' ) ) {
				if ( class_exists( 'STI_GS_Deadline' ) ) {
					try {
						$global = STI_GS_Deadline::guard( function () {
							return STI_GS_Bot_Candidate_Collector::global_poll();
						}, self::POLL_GLOBAL_TIMEOUT, 'chain_poll_global' );
					} catch ( \STI_GS_Deadline_Exception $e ) {
						$global = array( 'polled' => false, 'error' => $e->getMessage(), 'deadline' => true );
					}
				} else {
					$global = STI_GS_Bot_Candidate_Collector::global_poll();
				}
			}
			STI_GS_Artifact::log( $session_id, 'chain_global_poll', is_array( $global ) ? $global : array() );

			/**
			 * ۱۰.۸.۳ — FLOOD_WAIT در global poll: منتظر نمی‌مانیم؛
			 * next_retry_at تنظیم و بدون مصرف attempts برمی‌گردیم.
			 */
			if ( is_array( $global ) && ! empty( $global['error'] ) ) {
				$fw = STI_GS_Retry::flood_wait_until( (string) $global['error'] );
				if ( null !== $fw ) {
					STI_GS_Session::update( $session_id, array( 'next_retry_at' => $fw ) );
					STI_GS_Event::log( $session_id, 'chain_engine', 'retry',
						'FLOOD_WAIT در poll سراسری — تلاش بعدی پس از ' . $fw . ' (بدون مصرف attempts).' );
					return array( 'state' => 'CHAIN_WAITING', 'waiting' => true, 'flood_wait' => $fw );
				}
			}

			/* ۲) خواندن پیام‌های تازه‌ی ربات جاری (برای گره‌های میانی) */
			$new_messages = array();
			$step_meta    = $step_meta_anchor; // decode شده در بخش anchor
			$last_msg_id  = (int) ( $step_meta['last_msg_id'] ?? 0 );

			if ( '' !== $peer && class_exists( 'STI_MTProto' ) ) {
				if ( class_exists( 'STI_GS_Deadline' ) ) {
					try {
						$msgs = STI_GS_Deadline::guard( function () use ( $peer, $since_ts ) {
							return STI_MTProto::instance()->recent_peer_messages( $peer, 30, $since_ts );
						}, self::POLL_PEER_TIMEOUT, 'chain_poll_peer' );
					} catch ( \STI_GS_Deadline_Exception $e ) {
						$msgs = new WP_Error( 'sti_gs_tg_deadline', $e->getMessage() );
					}
				} else {
					$msgs = STI_MTProto::instance()->recent_peer_messages( $peer, 30, $since_ts );
				}
				if ( is_wp_error( $msgs ) ) {
					/* ۱۰.۸.۳ — flood: retry با next_retry_at، بدون مصرف attempts */
					$fw = STI_GS_Retry::flood_wait_until( $msgs->get_error_message() );
					if ( null !== $fw ) {
						STI_GS_Session::update( $session_id, array( 'next_retry_at' => $fw ) );
						STI_GS_Event::log( $session_id, 'chain_engine', 'retry',
							'FLOOD_WAIT از ' . $peer . ' — تلاش بعدی پس از ' . $fw . ' (بدون مصرف attempts).' );
						return array( 'state' => 'CHAIN_WAITING', 'waiting' => true, 'flood_wait' => $fw );
					}
					STI_GS_Event::log( $session_id, 'chain_engine', 'error',
						'خواندن تاریخچه‌ی ' . $peer . ' ناموفق: ' . $msgs->get_error_message() );
					$msgs = array();
				}
				$scan_max_id = $last_msg_id; // برای پیشروی msg_id حتی روی پیام‌های ردشده
				foreach ( (array) $msgs as $m ) {
					$scan_max_id = max( $scan_max_id, (int) ( $m['id'] ?? 0 ) );
					/* ۱۰.۸.۴ — Correlation: پیام‌های ارسالی خودِ Engine پاسخ نیستند. */
					if ( ! empty( $m['out'] ) ) {
						STI_GS_Artifact::log( $session_id, 'chain_correlate_rejected', array(
							'msg_id' => (int) ( $m['id'] ?? 0 ),
							'reason' => 'outgoing_self_message',
							'text'   => mb_substr( (string) ( $m['text'] ?? '' ), 0, 80 ),
						) );
						continue;
					}
					if ( (int) ( $m['id'] ?? 0 ) <= $last_msg_id ) {
						continue; // مصرف‌شده
					}
					/* پیام‌های قبل از اکشنِ این گام، پاسخِ این اکشن نیستند (قانون anchor). */
					if ( $anchor_action_ts && (int) ( $m['date'] ?? 0 ) < ( $anchor_action_ts - 10 ) ) {
						STI_GS_Artifact::log( $session_id, 'chain_correlate_rejected', array(
							'msg_id' => (int) ( $m['id'] ?? 0 ),
							'reason' => 'before_action',
							'date'   => (int) ( $m['date'] ?? 0 ),
							'action_at_ts' => $anchor_action_ts,
						) );
						continue;
					}
					$new_messages[] = $m;
				}
				$last_msg_id = max( $last_msg_id, $scan_max_id );
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

				if ( $incoming->is_executable() ) {
					/* ۱۰.۸.۴ — BUG-3: GATE تکراری (گام جاری خودش GATE است و
					   ربات دوباره درخواست کد کرده) → informational، نه گام
					   جدید. «پیام‌های command-like پاسخِ تازه نیستند.» */
					if ( STI_GS_Node::NODE_GATE === $incoming->type
						&& STI_GS_Node::NODE_GATE === (string) $step['node_type'] ) {
						$info_count = (int) ( $step_meta['info_count'] ?? 0 ) + 1;
						STI_GS_Handoff_Steps::mark( (int) $step['id'], STI_GS_Handoff_Steps::STATUS_WAITING, array(
							'last_msg_id' => (int) ( $m['id'] ?? 0 ),
							'info_count'  => $info_count,
							'info_last'   => 'GATE_REPEAT: ' . mb_substr( trim( (string) $incoming->text ), 0, 120 ),
						) );
						STI_GS_Artifact::log( $session_id, 'chain_informational', array(
							'step_no' => (int) $step['step_no'],
							'text'    => mb_substr( trim( (string) $incoming->text ), 0, 200 ),
							'count'   => $info_count,
							'reason'  => 'gate_repeat',
						) );
						$step_meta = array_merge( $step_meta, array(
							'last_msg_id' => (int) ( $m['id'] ?? 0 ),
							'info_count'  => $info_count,
							'info_last'   => 'GATE_REPEAT: ' . mb_substr( trim( (string) $incoming->text ), 0, 120 ),
						) );
						if ( $info_count >= STI_GS_Node::MAX_INFORMATIONAL_STEPS ) {
							self::fail_chain( $session_id, 'CHAIN_NO_PROGRESS',
								'بیش از ' . STI_GS_Node::MAX_INFORMATIONAL_STEPS . ' درخواست تکراری کد بدون پیشرفت.' );
							return new WP_Error( 'sti_gs_chain_no_progress', 'زنجیره پیشرفتی نداشت.' );
						}
						continue;
					}

					/* حفاظت حلقه‌ی ربات‌ها */
					if ( '' !== $incoming->bot_username
						&& STI_GS_Handoff_Steps::has_bot_loop( $session_id, $incoming->bot_username ) ) {
						self::fail_chain( $session_id, 'CHAIN_LOOP_DETECTED',
							'ربات «' . $incoming->bot_username . '» قبلاً در این زنجیره دیده شده — حلقه متوقف شد.' );
						return new WP_Error( 'sti_gs_chain_loop', 'حلقه‌ی ربات شناسایی شد.' );
					}

					/* گیت: اگر ربات کد می‌خواهد، کد همین Session را بفرست.
					   ۱۰.۸.۵ — Rule 2/3: اگر file_code روی Session نبود، از
					   متن خودِ درخواست (File Code : X) یا آخرین «اطلاعات
					   فایل» دیده‌شده (file_code_seen) استفاده می‌شود. */
					if ( STI_GS_Node::NODE_GATE === $incoming->type ) {
						$gate_code = (string) ( $session['file_code'] ?? '' );
						if ( '' === $gate_code ) {
							$gate_code = STI_GS_Node_Classifier::extract_file_code( $incoming->text );
						}
						if ( '' === $gate_code ) {
							$gate_code = (string) ( $step_meta['file_code_seen'] ?? '' );
						}
						if ( '' !== $gate_code ) {
							$incoming->text = STI_GS_Node::string_code( $gate_code );
						} else {
							/* بدون کدِ قابل‌ارسال: گام جدید نساز (منتظر می‌مانیم)
							   و درخواست را به‌عنوان informational ثبت می‌کنیم. */
							$g_count = (int) ( $step_meta['info_count'] ?? 0 ) + 1;
							STI_GS_Handoff_Steps::mark( (int) $step['id'], STI_GS_Handoff_Steps::STATUS_WAITING, array(
								'last_msg_id' => (int) ( $m['id'] ?? 0 ),
								'info_count'  => $g_count,
								'info_last'   => 'GATE_NO_CODE: ' . mb_substr( trim( (string) $incoming->text ), 0, 120 ),
							) );
						STI_GS_Artifact::log( $session_id, 'chain_informational', array(
							'step_no' => (int) $step['step_no'],
							'text'    => mb_substr( trim( (string) $incoming->text ), 0, 200 ),
							'count'   => $g_count,
							'reason'  => 'gate_no_code',
						) );
						$step_meta = array_merge( $step_meta, array(
							'last_msg_id' => (int) ( $m['id'] ?? 0 ),
							'info_count'  => $g_count,
							'info_last'   => 'GATE_NO_CODE: ' . mb_substr( trim( (string) $incoming->text ), 0, 120 ),
						) );
						if ( $g_count >= STI_GS_Node::MAX_INFORMATIONAL_STEPS ) {
								self::fail_chain( $session_id, 'CHAIN_NO_PROGRESS',
									'ربات کد می‌خواهد ولی file_code در دسترس نیست (' . STI_GS_Node::MAX_INFORMATIONAL_STEPS . ' بار).' );
								return new WP_Error( 'sti_gs_chain_no_progress', 'کد فایل در دسترس نیست.' );
							}
							continue;
						}
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
					/* ۱۰.۸.۵ — Rule 4: پاسخ قطعی «فایل یافت نشد» → ترمینال؛
					   نه ۱۵ دقیقه Poll بی‌فایده. اگر متن مربوط به قبل از
					   اجرای آخرین اکشن گام باشد (anchor)، پاسخِ این اکشن
					   نیست — ربات هنوز کد را دریافت نکرده؛ ترمینال نمی‌کنیم
					   (informational با برچسب STALE) تا اگر فایل رسید
					   گرفته شود. */
					$is_stale_nf = false;
					if ( STI_GS_Node_Classifier::looks_like_file_not_found( $incoming->text ) ) {
						if ( $anchor_action_ts && (int) ( $m['date'] ?? 0 ) < ( $anchor_action_ts - 10 ) ) {
							$is_stale_nf = true;
							STI_GS_Artifact::log( $session_id, 'chain_file_not_found_stale', array(
								'step_no'      => (int) $step['step_no'],
								'msg_id'       => (int) ( $m['id'] ?? 0 ),
								'date'         => (int) ( $m['date'] ?? 0 ),
								'action_at_ts' => $anchor_action_ts,
								'text'         => mb_substr( trim( (string) $incoming->text ), 0, 200 ),
							) );
							STI_GS_Event::log( $session_id, 'chain_engine', 'retry',
								'«فایل یافت نشد» مربوط به قبل از آخرین اکشن بود — منتظر پاسخ واقعی می‌مانیم (نه ترمینال).' );
						} else {
							self::file_not_found( $session_id, (int) $step['step_no'], (int) ( $m['id'] ?? 0 ), $incoming->text );
							return array( 'state' => 'ERROR_FILE_NOT_FOUND', 'terminal' => true );
						}
					}

					/* ۱۰.۸.۵ — Rule 2/5: متن «File Name / File Code» یک پاسخ
					   معتبر ربات است (fresh_response) — کد فایل استخراج و روی
					   گام ذخیره می‌شود تا اگر ربات کد خواست (GATE) با همان
					   کد پاسخ بدهیم؛ پنجره‌ی پاسخ نیز تمدید می‌شود. */
					$info_count = (int) ( $step_meta['info_count'] ?? 0 ) + 1;
					$new_meta = array(
						'last_msg_id' => (int) ( $m['id'] ?? 0 ),
						'info_count'  => $info_count,
						'info_last'   => ( $is_stale_nf ? 'STALE_NOT_FOUND: ' : '' )
							. mb_substr( trim( (string) $incoming->text ), 0, 200 ),
					);
					if ( $info_count <= 10 ) {
						$new_meta[ 'info_' . $info_count ] = mb_substr( trim( (string) $incoming->text ), 0, 200 );
					}

					$file_code_seen = STI_GS_Node_Classifier::extract_file_code( $incoming->text );
					if ( '' !== $file_code_seen ) {
						$new_meta['file_code_seen'] = $file_code_seen;
						STI_GS_Artifact::log( $session_id, 'chain_file_info', array(
							'step_no'    => (int) $step['step_no'],
							'file_code'  => $file_code_seen,
							'msg_id'     => (int) ( $m['id'] ?? 0 ),
							'fresh_response' => true,
						) );
						/* پاسخ معتبر ربات = تمدید پنجره‌ی مکالمه (anchor گام
						   دست نمی‌خورد؛ فقط clicked_at برای timeout تمدید می‌شود). */
						STI_GS_Session::update( $session_id, array( 'clicked_at' => current_time( 'mysql' ) ) );
						STI_GS_Event::log( $session_id, 'chain_engine', 'ok',
							'پاسخ متنی معتبر از ربات: اطلاعات فایل (کد: ' . $file_code_seen . ') — پنجره‌ی پاسخ تمدید شد.' );
					}

					STI_GS_Handoff_Steps::mark( (int) $step['id'], STI_GS_Handoff_Steps::STATUS_WAITING, $new_meta );
					/* ۱۰.۸.۵ — همگام‌سازی محلی: اگر در همین Poll پیام بعدی
					   GATE (درخواست کد) باشد، file_code_seen دیده‌شده باید
					   در دسترس باشد وگرنه «بدون کد» نتیجه می‌دهد (Rule 3). */
					$step_meta = array_merge( $step_meta, $new_meta );
					STI_GS_Artifact::log( $session_id, 'chain_informational', array(
						'step_no' => (int) $step['step_no'],
						'text'    => mb_substr( trim( (string) $incoming->text ), 0, 200 ),
						'count'   => $info_count,
					) );
					if ( $info_count >= STI_GS_Node::MAX_INFORMATIONAL_STEPS ) {
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

			/* ۱۰.۸.۵ — Rule 4 (بازگشتی): Sessionهای گیرکرده‌ای که قبلاً متن
			   «فایل یافت نشد» را به‌عنوان informational ثبت کرده‌اند، به
			   ERROR_FILE_NOT_FOUND می‌روند. عمداً بعد از بلوک Asset: اگر
			   فایلی تازه رسیده باشد اول گرفته می‌شود و ترمینالِ اشتباه رخ
			   نمی‌دهد. برچسب STALE_NOT_FOUND (جوابِ قبل از آخرین اکشن)
			   ترمینال نمی‌شود. */
			$last_info = (string) ( $step_meta['info_last'] ?? '' );
			if ( '' !== $last_info
				&& 0 !== strpos( $last_info, 'STALE_NOT_FOUND:' )
				&& STI_GS_Node_Classifier::looks_like_file_not_found( $last_info ) ) {
				self::file_not_found( $session_id, (int) $step['step_no'], 0, $last_info );
				return array( 'state' => 'ERROR_FILE_NOT_FOUND', 'terminal' => true );
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

			/* ۱۰.۸.۳ — وضعیت «منتظر پاسخ ربات» (mapping رسمی: done→waiting). */
			STI_GS_Handoff_Steps::mark( (int) $step['id'], STI_GS_Handoff_Steps::STATUS_WAITING, array() );

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

	/**
	 * ۱۰.۸.۵ — Rule 4: پاسخ قطعی ربات «فایل یافت نشد».
	 *
	 * Session به حالت ترمینال ERROR_FILE_NOT_FOUND می‌رود (Worker دیگر
	 * آن را pick نمی‌کند؛ در TERMINAL است). برخلاف CHAIN_FAILED، این
	 * retry نمی‌خواهد — ربات صریحاً گفته فایل وجود ندارد. attempts دست
	 * نمی‌خورد.
	 */
	protected static function file_not_found( $session_id, $step_no, $msg_id, $text ) {
		STI_GS_Session::update( (int) $session_id, array(
			'state'        => 'ERROR_FILE_NOT_FOUND',
			'stage'        => 'chain_engine',
			'error_reason' => 'CHAIN_FILE_NOT_FOUND: ربات اعلام کرد فایل درخواستی یافت نشد (گام ' . (int) $step_no . ').',
		) );
		STI_GS_Event::log( (int) $session_id, 'chain_engine', 'error',
			'CHAIN_FILE_NOT_FOUND: ربات اعلام کرد فایل درخواستی یافت نشد (گام ' . (int) $step_no . ').' );
		STI_GS_Artifact::log( (int) $session_id, 'chain_file_not_found', array(
			'step_no' => (int) $step_no,
			'msg_id'  => (int) $msg_id,
			'text'    => mb_substr( (string) $text, 0, 200 ),
		) );
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
