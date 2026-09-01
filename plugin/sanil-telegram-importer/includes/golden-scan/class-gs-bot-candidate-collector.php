<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — فاز ۳-C: Bot Candidate Collector.
 *
 * دو مرحله‌ی مجزا (نه یک poll به‌ازای هر session، چون
 * STI_MTProto::find_recent_documents() خودش از قبل روی همه‌ی «ربات‌های
 * شناخته‌شده» (STI_Bot_Inbox::bot_peers()، که FileechBot در آن پیش‌فرض است)
 * یک‌جا جستجو می‌کند، نه به‌ازای یک peer مشخص — نکته‌ای که در طراحی اولیه
 * (peer به‌عنوان پارامتر) نبود و همین‌جا اصلاح شد):
 *
 *   ۱) Global Poll  → یک‌بار find_recent_documents + record_many (idempotent)
 *   ۲) Candidate Build → برای هر Session در WAITING_BOT، ردیف‌های تازه‌ی
 *      inbox متعلق به همان ربات را با Snapshot + امتیاز جزئی candidate می‌کند.
 *
 * هیچ تصمیم نهایی (FILE_MATCHED) اینجا گرفته نمی‌شود — فقط جمع‌آوری.
 */
class STI_GS_Bot_Candidate_Collector {

	const LOOKBACK_BUFFER_SEC = 10;   // طبق FIX تاییدشده: clicked_at - 10s
	const MAX_LOOKBACK_SEC    = 1800; // سقف عقب‌رفتن Poll جهانی (۳۰ دقیقه) برای جلوگیری از پول سنگین
	const BOT_TIMEOUT_SEC     = 900;  // بعد از این مدت بدون candidate → ERROR_BOT_TIMEOUT
	const SCORE_TIME_WINDOW   = 300;  // ثانیه؛ برای تابع نزولی score_time

	/**
	 * ۱۰.۸.۳ — Shared Observation.
	 *
	 * GLOBAL_POLL_INTERVAL: نتیجه‌ی اسکن سنگین به مدت این چند ثانیه کش
	 * می‌شود تا هر Session/تیک دوباره کل ربات‌ها را اسکن نکند (coupling
	 * بین Sessionها حذف می‌شود — Audit §۹-P3).
	 * GLOBAL_POLL_TIMEOUT: سقف هر اسکن (توسط STI_GS_Deadline).
	 */
	const GLOBAL_POLL_INTERVAL = 60;
	const GLOBAL_POLL_TIMEOUT  = 45;
	const GLOBAL_POLL_CACHE    = 'sti_gs_global_poll_cache';

	/** یعنی Match قبلاً انجام شده (یا فراتر) — Poll دوباره لازم نیست. */
	const PAST_STATES = array(
		'FILE_MATCHED', 'DOWNLOAD_PENDING', 'DOWNLOADING', 'DOWNLOAD_FAILED', 'STORED',
		'MEDIA_BUILDING', 'MEDIA_FAILED', 'MEDIA_READY',
		'PRODUCT_BUILDING', 'PRODUCT_FAILED', 'PRODUCT_READY', 'REVIEW_READY',
	);

	/** فاز ۱: یک Poll سراسری، مستقل از تعداد Sessionهای در انتظار. */
	public static function global_poll() {
		global $wpdb;
		$sessions_table = STI_GS_Session::table();

		/**
		 * ۱۰.۸.۳ — Shared Observation: اگر همین چند ثانیه‌ی پیش یک اسکن
		 * کامل انجام شده، نتیجه‌اش را برمی‌گردانیم (بدون تماس تلگرام).
		 * نتیجه‌ی «NO_WAITING_SESSIONS» (ارزان) کش نمی‌شود تا Session
		 * تازه‌واردشده سریع اسکن بگیرد.
		 */
		if ( function_exists( 'get_transient' ) ) {
			$cache = get_transient( self::GLOBAL_POLL_CACHE );
			if ( is_array( $cache ) && ! empty( $cache['at'] )
				&& ( time() - (int) $cache['at'] ) < self::GLOBAL_POLL_INTERVAL
				&& ! empty( $cache['polled'] ) ) {
				$cache['cached'] = true;
				return $cache;
			}
		}

		$oldest_clicked = $wpdb->get_var( $wpdb->prepare(
			"SELECT MIN(clicked_at) FROM {$sessions_table} WHERE state = %s AND clicked_at IS NOT NULL",
			'WAITING_BOT'
		) );

		if ( ! $oldest_clicked ) {
			return array( 'polled' => false, 'reason' => 'NO_WAITING_SESSIONS' );
		}

		$since = self::to_ts( $oldest_clicked ) - self::LOOKBACK_BUFFER_SEC;
		$since = max( $since, time() - self::MAX_LOOKBACK_SEC );

		$mt = STI_MTProto::instance();

		/**
		 * ۱۰.۸.۳ — Deadline: اسکن گسترده‌ی getHistory نباید هرگز Worker را
		 * برای همیشه معلق کند. بعد از GLOBAL_POLL_TIMEOUT ثانیه:
		 *   - pcntl → STI_GS_Deadline_Exception (کنترل‌شده)
		 *   - غیر pcntl → مرگ کران‌دار درخواست + Stale-Lock Recovery
		 */
		if ( class_exists( 'STI_GS_Deadline' ) ) {
			try {
				$docs = STI_GS_Deadline::guard( function () use ( $mt, $since ) {
					return $mt->find_recent_documents( $since, min( self::MAX_LOOKBACK_SEC, time() - $since + 60 ) );
				}, self::GLOBAL_POLL_TIMEOUT, 'global_poll' );
			} catch ( \STI_GS_Deadline_Exception $e ) {
				return array( 'polled' => false, 'error' => $e->getMessage(), 'deadline' => true );
			} catch ( \Throwable $e ) {
				/* ۱۰.۸.۳ — flood داخل اسکن: به poll برمی‌گردد تا next_retry_at بگیرد. */
				$flood = class_exists( 'STI_MTProto' ) ? STI_MTProto::flood_error( $e ) : null;
				if ( $flood ) {
					$next_retry = STI_GS_Retry::flood_wait_until( $flood->get_error_message() );
					return array( 'polled' => false, 'error' => $flood->get_error_message(), 'next_retry_at' => $next_retry );
				}
				return array( 'polled' => false, 'error' => $e->getMessage(), 'exception' => true );
			}
		} else {
			try {
				$docs = $mt->find_recent_documents( $since, min( self::MAX_LOOKBACK_SEC, time() - $since + 60 ) );
			} catch ( \Throwable $e ) {
				$flood = class_exists( 'STI_MTProto' ) ? STI_MTProto::flood_error( $e ) : null;
				if ( $flood ) {
					$next_retry = STI_GS_Retry::flood_wait_until( $flood->get_error_message() );
					return array( 'polled' => false, 'error' => $flood->get_error_message(), 'next_retry_at' => $next_retry );
				}
				return array( 'polled' => false, 'error' => $e->getMessage(), 'exception' => true );
			}
		}

		if ( is_wp_error( $docs ) ) {
			$next_retry = STI_GS_Retry::flood_wait_until( $docs->get_error_message() );
			return array( 'polled' => false, 'error' => $docs->get_error_message(), 'next_retry_at' => $next_retry );
		}

		/**
		 * ۱۰.۸.۳ — Shared Observation cache: نتیجه‌ی اسکن سنگین برای
		 * GLOBAL_POLL_INTERVAL ثانیه کش می‌شود تا بقیه‌ی Sessionها/تیک‌ها
		 * دوباره کل ربات‌ها را اسکن نکنند.
		 */
		$cache_result = function ( $result ) {
			if ( function_exists( 'set_transient' ) ) {
				$result['at'] = time();
				set_transient( self::GLOBAL_POLL_CACHE, $result, self::GLOBAL_POLL_INTERVAL + 60 );
			}
			return $result;
		};

		/**
		 * اگر STI_Bot_Inbox بارگذاری نشده باشد، هیچ فایلی ثبت نمی‌شود و
		 * نتیجه `docs_recorded: 0` است — بدون هیچ توضیحی.
		 *
		 * این حالت واقعاً رخ داد: حالت ایمن افزونه
		 * `class-sti-bot-inbox.php` را خاموش کرده بود و از بیرون شبیه
		 * «ربات فایل نفرستاد» دیده می‌شد، در حالی که فایل‌ها دیده می‌شدند
		 * ولی جایی برای ثبت وجود نداشت.
		 */
		if ( ! class_exists( 'STI_Bot_Inbox' ) ) {
			$reason = function_exists( 'sti_v7_safe_mode' ) && sti_v7_safe_mode()
				? 'BOT_INBOX_UNAVAILABLE_SAFE_MODE'
				: 'BOT_INBOX_CLASS_MISSING';

			STI_Logger::error( 'گلدن اسکن: ' . $reason . ' — ' . count( (array) $docs )
				. ' فایل از ربات دیده شد ولی هیچ‌کدام ثبت نشد چون صندوق ورودی در دسترس نیست.' );

			return $cache_result( array(
				'polled'        => true,
				'since'         => $since,
				'docs_seen'     => count( (array) $docs ),
				'docs_recorded' => 0,
				'blocked'       => $reason,
				'hint'          => 'حالت ایمن افزونه را از نوار بالای پنل خاموش کنید.',
			) );
		}

		if ( method_exists( 'STI_Bot_Inbox', 'record_many_verbose' ) ) {
			$report = STI_Bot_Inbox::record_many_verbose( $docs );
			return $cache_result( array_merge( array( 'polled' => true, 'since' => $since ), $report ) );
		}

		// نسخه‌ی بدون گزارش تفصیلی: دست‌کم دلیل هر فایل را خودمان بسازیم.
		$recorded = 0;
		$items    = array();
		foreach ( (array) $docs as $doc ) {
			$before = $recorded;
			$ok     = STI_Bot_Inbox::record( $doc );
			if ( $ok ) {
				$recorded++;
			}
			$items[] = array(
				'msg_id'    => $doc['msg_id'] ?? ( $doc['id'] ?? 0 ),
				'file_name' => $doc['file_name'] ?? '',
				'result'    => ( $recorded > $before ) ? 'recorded' : 'skipped',
				'reason'    => ( $recorded > $before ) ? '' : 'record_returned_false',
			);
		}

		return $cache_result( array(
			'polled'        => true,
			'since'         => $since,
			'docs_seen'     => count( (array) $docs ),
			'docs_recorded' => $recorded,
			'items'         => $items,
		) );
	}

	/** فاز ۲: برای یک Session مشخص، ردیف‌های تازه‌ی inbox را candidate می‌کند. */
	public static function build_for_session( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'collector-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, 45 ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط worker دیگری پردازش می‌شود.' );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}
			if ( in_array( $session['state'], self::PAST_STATES, true ) ) {
				STI_GS_Event::log( $session_id, 'bot_candidate_collector', 'ok',
					'Match قبلاً انجام شده — Skip.',
					array( 'stage' => 'bot_candidate_collector', 'reason' => 'already_completed', 'current_state' => $session['state'] )
				);
				return array( 'state' => $session['state'], 'skipped' => true );
			}
			if ( ! in_array( $session['state'], array( 'WAITING_BOT', 'ERROR_BOT_TIMEOUT' ), true ) ) {
				$reason = 'INVALID_STATE: Session باید WAITING_BOT یا ERROR_BOT_TIMEOUT باشد (الان: ' . $session['state'] . ').';
				STI_GS_Event::log( $session_id, 'bot_candidate_collector', 'error', $reason );
				return new WP_Error( 'sti_gs_invalid_state', $reason );
			}
			if ( empty( $session['bot_username'] ) ) {
				// فعلاً طبق تصمیم DEFERRED: فقط deep_link (که bot_username دارد) پشتیبانی می‌شود.
				self::fail( $session_id, 'NO_BOT_IDENTITY: bot_username روی این Session ثبت نشده؛ فعلاً فقط مسیر deep_link پشتیبانی می‌شود.' );
				return new WP_Error( 'sti_gs_no_bot', 'هویت ربات نامشخص است.' );
			}

			$clicked_ts = self::to_ts( $session['clicked_at'] );
			if ( ! $clicked_ts ) {
				self::fail( $session_id, 'NO_CLICK_TIME: clicked_at ثبت نشده — نمی‌توان پاسخ کهنه را از تازه تشخیص داد.' );
				return new WP_Error( 'sti_gs_no_click_time', 'clicked_at موجود نیست.' );
			}

			$since_ts = ( (int) $session['last_polled_at'] > 0 )
				? (int) $session['last_polled_at']
				: max( 0, $clicked_ts - self::LOOKBACK_BUFFER_SEC );

			$rows = self::fetch_inbox_rows( $session['bot_username'], $since_ts );

			STI_GS_Artifact::log( $session_id, 'bot_poll', array(
				'bot_username' => $session['bot_username'],
				'since_ts'     => $since_ts,
				'rows_seen'    => count( $rows ),
			) );

			$created = 0;
			$max_date_seen = $since_ts;

			foreach ( $rows as $row ) {
				$row_date = (int) $row['date_ts'];
				// Stale Response: هر پاسخی قدیمی‌تر از لحظه‌ی کلیک، متعلق به این Session نیست.
				if ( $row_date < $clicked_ts - self::LOOKBACK_BUFFER_SEC ) {
					continue;
				}
				if ( $row_date > $max_date_seen ) {
					$max_date_seen = $row_date;
				}
				if ( STI_GS_Bot_Candidate::exists( $session_id, (int) $row['id'] ) ) {
					continue; // idempotent — قبلاً candidate شده
				}

				$candidate = self::build_candidate( $session, $row, $clicked_ts );
				if ( null === $candidate ) {
					/* ۱۰.۸.۴ — کاندید رد شد (file code ناسازگار با Session). */
					STI_GS_Artifact::log( $session_id, 'candidate_rejected', array(
						'inbox_id'    => (int) $row['id'],
						'file_name'   => (string) ( $row['file_name'] ?? '' ),
						'reason'      => 'file_code_mismatch',
						'session_code'=> $session['file_code'] ?? '',
						'row_codes'   => (string) ( $row['codes'] ?? '' ),
					) );
					continue;
				}
				if ( STI_GS_Bot_Candidate::create( $candidate ) ) {
					$created++;
				}
			}

			STI_GS_Artifact::log( $session_id, 'candidate_built', array(
				'candidates_created' => $created,
				'total_rows_checked' => count( $rows ),
			) );

			STI_GS_Session::update( $session_id, array( 'last_polled_at' => $max_date_seen ) );

			if ( $created > 0 ) {
				STI_GS_Session::update( $session_id, array( 'state' => 'BOT_RESPONSE', 'stage' => 'bot_candidate_collector', 'error_reason' => null ) );
				STI_GS_Event::log( $session_id, 'bot_candidate_collector', 'ok', "{$created} candidate ساخته شد." );
				return array( 'state' => 'BOT_RESPONSE', 'candidates_created' => $created );
			}

			// هنوز هیچ فایلی نرسیده.
			if ( ( time() - $clicked_ts ) >= self::BOT_TIMEOUT_SEC ) {
				self::fail( $session_id, 'BOT_TIMEOUT: هیچ فایلی طی ' . self::BOT_TIMEOUT_SEC . ' ثانیه از ' . $session['bot_username'] . ' دریافت نشد.', 'ERROR_BOT_TIMEOUT' );
				return array( 'state' => 'ERROR_BOT_TIMEOUT', 'candidates_created' => 0 );
			}

			STI_GS_Event::log( $session_id, 'bot_candidate_collector', 'retry', 'هنوز پاسخی نرسیده؛ Poll بعدی ادامه می‌دهد.' );
			return array( 'state' => 'WAITING_BOT', 'candidates_created' => 0 );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
		}
	}

	/** برای همه‌ی Sessionهای WAITING_BOT، یک global_poll + build_for_session بزند. برای دکمه‌ی تست دستی در پنل. */
	public static function tick() {
		$poll = self::global_poll();
		$sessions = STI_GS_Session::list( array( 'state' => 'WAITING_BOT', 'limit' => 50 ) );
		$results = array();
		foreach ( $sessions as $s ) {
			$results[ $s['id'] ] = self::build_for_session( (int) $s['id'] );
		}
		return array( 'poll' => $poll, 'sessions_processed' => count( $sessions ), 'results' => $results );
	}

	protected static function fetch_inbox_rows( $peer, $since_ts ) {
		global $wpdb;
		if ( ! class_exists( 'STI_Bot_Inbox' ) ) {
			return array();
		}
		// LOWER() عمداً: چون منابع مختلف (collect_incoming با known_bots، یا
		// priority_bots با bot_peers) ممکن است همان ربات را با حروف متفاوت
		// (FileechBot در مقابل fileechbot) ثبت کرده باشند.
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . STI_Bot_Inbox::table() . ' WHERE LOWER(peer) = LOWER(%s) AND date_ts >= %d ORDER BY date_ts ASC',
			(string) $peer, (int) $since_ts
		), ARRAY_A );
	}

	protected static function build_candidate( $session, $inbox_row, $clicked_ts ) {
		$session_file_code = (string) ( $session['file_code'] ?? '' );
		$session_file_name = self::snapshot_file_name( (int) $session['message_pk'] );

		$codes = array_filter( array_map( 'trim', explode( ',', (string) ( $inbox_row['codes'] ?? '' ) ) ) );
		$candidate_file_code = null;
		if ( '' !== $session_file_code && in_array( $session_file_code, $codes, true ) ) {
			$candidate_file_code = $session_file_code; // دقیقاً همان چیزی که دنبالش بودیم
		} elseif ( ! empty( $codes ) ) {
			$candidate_file_code = $codes[0];
		}

		/**
		 * ۱۰.۸.۴ — Response Correlation (BUG-1):
		 * Observation مشترک (global_poll) فقط Candidate تولید می‌کند؛
		 * اما هر Session باید قبل از قبول، کاندید را با file_code خودش
		 * validate کند. اگر Session file_code دارد و ردیف inbox کدِ
		 * متفاوتی دارد (یا کدی دارد که با file_code نمی‌خواند)، این
		 * فایل متعلق به این Session نیست — حتی اگر از همان peer آمده
		 * باشد (ربات‌های عمومی history همه‌ی کاربران را نشان می‌دهند).
		 */
		if ( '' !== $session_file_code && ! empty( $codes )
			&& ! in_array( $session_file_code, $codes, true ) ) {
			return null; // رد کاندید — متعلق به Session دیگر است
		}

		$score_file_code = ( '' !== $session_file_code && $candidate_file_code === $session_file_code ) ? 100 : 0;
		$score_file_name = self::filename_score( $session_file_name, (string) ( $inbox_row['file_name'] ?? '' ) );
		$score_time       = self::time_score( $clicked_ts, (int) $inbox_row['date_ts'] );

		return array(
			'session_id'            => (int) $session['id'],
			'inbox_id'               => (int) $inbox_row['id'],
			'bot_username'           => $session['bot_username'],
			'bot_chat_id'            => $session['bot_chat_id'] ?: null,
			'session_file_code'      => $session_file_code ?: null,
			'session_file_name'      => $session_file_name ?: null,
			'candidate_file_code'    => $candidate_file_code,
			'file_name'              => (string) ( $inbox_row['file_name'] ?? '' ) ?: null,
			'telegram_document_id'   => (int) ( $inbox_row['telegram_document_id'] ?? 0 ),
			'mime_type'              => (string) ( $inbox_row['mime_type'] ?? '' ) ?: null,
			'candidate_source'       => 'bot_poll',
			'score_file_code'        => $score_file_code,
			'score_file_name'        => $score_file_name,
			'score_time'             => $score_time,
			'total_score'            => $score_file_code + $score_file_name + $score_time,
			'status'                 => 'pending',
		);
	}

	/** Snapshot طبق FIX1: از sti_gs_messages فقط همین یک‌بار، در لحظه‌ی ساخت Candidate خوانده می‌شود. */
	protected static function snapshot_file_name( $message_pk ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT file_name FROM ' . STI_GS_DB::messages_table() . ' WHERE id = %d', (int) $message_pk
		) );
	}

	protected static function filename_score( $expected, $actual ) {
		$expected = mb_strtolower( trim( (string) $expected ) );
		$actual   = mb_strtolower( trim( (string) $actual ) );
		if ( '' === $expected || '' === $actual ) {
			return 0;
		}
		similar_text( $expected, $actual, $pct );
		return (int) round( min( 30, $pct * 0.3 ) ); // سقف ۳۰ — سیگنال کمکی، نه قطعی
	}

	/** تابع نزولی: هرچه فاصله از لحظه‌ی کلیک بیشتر، امتیاز کمتر (طبق RISK 3 تاییدشده). */
	protected static function time_score( $clicked_ts, $doc_ts ) {
		$delta = max( 0, $doc_ts - $clicked_ts );
		if ( $delta > self::SCORE_TIME_WINDOW ) {
			return 0;
		}
		return (int) round( 10 * ( 1 - ( $delta / self::SCORE_TIME_WINDOW ) ) );
	}

	protected static function fail( $session_id, $reason, $state = 'ERROR_BOT_TIMEOUT' ) {
		STI_GS_Session::update( $session_id, array( 'state' => $state, 'stage' => 'bot_candidate_collector', 'error_reason' => $reason ) );
		STI_GS_Event::log( $session_id, 'bot_candidate_collector', 'error', $reason );
	}

	protected static function to_ts( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) ) {
			return 0;
		}
		$dt = date_create_from_format( 'Y-m-d H:i:s', $mysql_datetime, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}
}
