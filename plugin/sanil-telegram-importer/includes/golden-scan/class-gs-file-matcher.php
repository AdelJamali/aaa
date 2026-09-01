<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — فاز ۳-D: File Matcher.
 *
 * فقط تصمیم می‌گیرد کدام Candidate درست است؛ هیچ دانلودی انجام نمی‌شود.
 *
 * قانون انتخاب: اگر حداقل یک Candidate با score_file_code>0 وجود داشت، فقط
 * از میان آن‌ها بالاترین total_score انتخاب می‌شود (اولویت مطلق File Code)؛
 * وگرنه بالاترین total_score از کل لیست.
 *
 * محافظ Race Condition (یافته‌ی ممیزی B/C): چون sti_gs_bot_candidates فقط
 * UNIQUE(session_id, inbox_id) دارد، همان فایل فیزیکی (inbox_id) می‌تواند
 * candidate چند Session مختلف باشد. برای جلوگیری از FILE_MATCHED دوگانه‌ی
 * یک فایل، claim آن روی خودِ sti_bot_inbox.status با یک UPDATE اتمی
 * (WHERE status='new') انجام می‌شود؛ اگر رقیب دیگری زودتر برده، به candidate
 * بعدیِ صف می‌رویم — نه شکست فوری.
 */
class STI_GS_File_Matcher {

	/** یعنی Match قبلاً انجام شده (یا فراتر) — دوباره Match نکن. */
	const PAST_STATES = array(
		'DOWNLOAD_PENDING', 'DOWNLOADING', 'DOWNLOAD_FAILED', 'STORED',
		'MEDIA_BUILDING', 'MEDIA_FAILED', 'MEDIA_READY',
		'PRODUCT_BUILDING', 'PRODUCT_FAILED', 'PRODUCT_READY', 'REVIEW_READY',
	);

	public static function match( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'matcher-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, 45 ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط worker دیگری پردازش می‌شود.' );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}
			if ( in_array( $session['state'], self::PAST_STATES, true ) ) {
				STI_GS_Event::log( $session_id, 'file_matcher', 'ok',
					'Match قبلاً انجام شده — Skip.',
					array( 'stage' => 'file_matcher', 'reason' => 'already_completed', 'current_state' => $session['state'] )
				);
				return array( 'state' => $session['state'], 'skipped' => true, 'matched_inbox_id' => $session['matched_inbox_id'] );
			}
			// از BOT_RESPONSE (مسیر عادی) یا ERROR_MATCH (تلاش دوباره) وارد می‌شویم؛
			// از FILE_MATCHED عمداً رد می‌شویم — دقیقاً همان گارد Idempotency.
			if ( ! in_array( $session['state'], array( 'BOT_RESPONSE', 'ERROR_MATCH' ), true ) ) {
				$reason = 'INVALID_STATE: Session باید BOT_RESPONSE یا ERROR_MATCH باشد (الان: ' . $session['state'] . ').';
				STI_GS_Event::log( $session_id, 'file_matcher', 'error', $reason );
				return new WP_Error( 'sti_gs_invalid_state', $reason );
			}

			$candidates = STI_GS_Bot_Candidate::for_session( $session_id );
			if ( empty( $candidates ) ) {
				self::fail( $session_id, 'NO_CANDIDATES: هیچ candidate‌ای برای این Session ثبت نشده — احتمالاً باگ در فاز قبل.' );
				return new WP_Error( 'sti_gs_no_candidates', 'candidate‌ای موجود نیست.' );
			}

			// اولویت مطلق File Code: اگر دست‌کم یکی score_file_code>0 دارد، فقط از میان آن‌ها انتخاب کن.
			$with_code = array_values( array_filter( $candidates, function ( $c ) { return (int) $c['score_file_code'] > 0; } ) );

			/**
			 * زنجیره‌ی مسیرهای جایگزین.
			 *
			 * چهار حالت واقعی در همین کانال دیده شده:
			 *
			 *   Magnific_268186115.jpg   #Photo        کد عددی
			 *   Magnific_283994940.jpg   #AiGenerated  کد عددی
			 *   envato_X5LZPEA.zip       #null         کد حروفی
			 *   envato_BKHFMJS.zip       #null         کد حروفی
			 *
			 * پس هیچ‌کدام از این‌ها شرط قابل اتکایی نیستند: نه پسوند، نه
			 * عددی بودن کد، نه یکی بودن کد ربات با کد Session.
			 *
			 * تنها الگوی ثابت این است که ربات **اول یک تصویر «درحال دریافت
			 * پیش‌نمایش»** می‌فرستد و بعد فایل واقعی. آن تصویر نام خودکار
			 * تلگرام دارد (photo_12345.jpg) و هیچ نام معناداری ندارد.
			 *
			 * نسخه‌ی ۱۰.۳.۶ هر فایل .jpg را کنار می‌گذاشت — که محصولات
			 * عکسی را می‌کشت. تفکیک درست «عکس در برابر سند» نیست،
			 * «پیش‌نمایش بی‌نام در برابر فایل نام‌دار» است.
			 *
			 * هیچ مسیری بن‌بست نیست: اگر هر چهار لایه خالی بماند، باز هم
			 * بهترین candidate موجود انتخاب می‌شود.
			 */
			$identified = array_values( array_filter( $candidates, function ( $c ) {
				return self::identity_strength( $c ) >= 60;
			} ) );

			/**
			 * لایه‌ی سوم عمداً «هر چیزی» نیست.
			 *
			 * در ۱۰.۳.۴ همین جمله باعث شد عکس سرگردان یک Session دیگر به
			 * محصول #1499 بچسبد. اگر هیچ فایلی هویت قابل استخراج نداشته
			 * باشد، **حدس نمی‌زنیم** — منتظر می‌مانیم.
			 *
			 * انتظار بن‌بست نیست: پنجره‌ی ۱۵ دقیقه‌ای ربات باز است و Poll
			 * خودکار ادامه می‌دهد. فقط اگر آن پنجره هم تمام شود خطا ثبت
			 * می‌شود.
			 */
			if ( empty( $with_code ) && empty( $identified ) ) {
				$clicked_ts = ! empty( $session['clicked_at'] ) ? strtotime( $session['clicked_at'] ) : 0;
				$waited     = $clicked_ts ? ( time() - $clicked_ts ) : 0;
				$timeout    = class_exists( 'STI_GS_Bot_Candidate_Collector' )
					? STI_GS_Bot_Candidate_Collector::BOT_TIMEOUT_SEC
					: 900;

				STI_GS_Artifact::log( $session_id, 'match_no_identity', array(
					'candidates' => array_map( function ( $c ) {
						return array(
							'file_name' => $c['file_name'] ?? '',
							'identity'  => self::identity_strength( $c ),
						);
					}, $candidates ),
					'waited'  => $waited,
					'timeout' => $timeout,
				) );

				if ( ! $clicked_ts || $waited < $timeout ) {
					STI_GS_Session::update( $session_id, array(
						'state'        => 'WAITING_BOT',
						'stage'        => 'file_matcher',
						'error_reason' => null,
					) );
					STI_GS_Event::log( $session_id, 'file_matcher', 'ok', sprintf(
						'هنوز فایل هویت‌داری نرسیده (%d مورد دریافت شده، همه بدون کد یا نام معتبر). منتظر می‌مانیم.',
						count( $candidates )
					) );
					return array( 'state' => 'WAITING_BOT', 'waiting' => true );
				}

				self::fail( $session_id, sprintf(
					'NO_IDENTIFIABLE_FILE: بعد از %d ثانیه، هیچ‌کدام از %d فایل دریافتی کد یا نام قابل تشخیص نداشتند.',
					$waited, count( $candidates )
				), 'ERROR_MATCH' );
				return new WP_Error( 'sti_gs_no_identity', 'فایل قابل تشخیصی دریافت نشد.' );
			}

			$strategy = ! empty( $with_code ) ? 'file_code' : 'identity';

			STI_GS_Artifact::log( $session_id, 'match_strategy', array(
				'strategy'     => $strategy,
				'total'        => count( $candidates ),
				'with_code'    => count( $with_code ),
				'identified'   => count( $identified ),
				'session_code' => (string) ( $session['file_code'] ?? '' ),
			) );

			$pool = ! empty( $with_code ) ? $with_code : $identified; // هر دو از قبل بر اساس total_score DESC, id ASC مرتب‌اند

			// P3.3 — Correlation جلوتر از امتیازدهی می‌نشیند.
			//
			// امتیازدهی می‌گوید «این فایل شبیه چیزی است که خواستیم». Correlation
			// می‌پرسد «آیا این فایل به همان پیامی برمی‌گردد که این Session از آن
			// آمده؟» — که سؤال قوی‌تری است. اگر دقیقاً یک candidate این را
			// تأیید کند، جلوی صف می‌رود.
			//
			// صف حذف نمی‌شود، فقط مرتب می‌شود: اگر claim آن candidate شکست
			// بخورد (رقابت با Session دیگر)، همان منطق قبلی سراغ بعدی می‌رود.
			$correlation = self::correlate_pool( $session, $pool );
			if ( $correlation['winner_index'] > 0 ) {
				$promoted = array_splice( $pool, $correlation['winner_index'], 1 );
				array_unshift( $pool, $promoted[0] );
			}

			STI_GS_Artifact::log( $session_id, 'match_decision_pool', array(
				'total_candidates'  => count( $candidates ),
				'file_code_priority'=> ! empty( $with_code ),
				'pool_size'         => count( $pool ),
				'correlation'       => $correlation,
				'pool'              => $pool,
			) );

			$winner = null;
			$rejected = array();
			foreach ( $pool as $candidate ) {
				if ( self::claim_inbox_row( (int) $candidate['inbox_id'], $session_id ) ) {
					$winner = $candidate;
					break;
				}
				// «قبلاً claim شده» به‌تنهایی بن‌بست است. وضعیت واقعی ردیف
				// inbox ثبت می‌شود تا معلوم شود چه چیزی آن را برداشته —
				// یک Session دیگر، یا مصرف‌کننده‌ای بیرون از گلدن اسکن.
				$rejected[] = array_merge(
					array( 'candidate_id' => (int) $candidate['id'], 'inbox_id' => (int) $candidate['inbox_id'], 'reason' => 'inbox_not_claimable' ),
					self::inbox_diagnosis( (int) $candidate['inbox_id'] )
				);
			}

			if ( ! $winner ) {
				STI_GS_Artifact::log( $session_id, 'match_decision', array( 'result' => 'no_winner', 'rejected' => $rejected ) );
				$detail = '';
				if ( ! empty( $rejected[0]['inbox_status'] ) ) {
					$detail = sprintf( ' وضعیت ردیف inbox #%d الان «%s» است%s.',
						(int) $rejected[0]['inbox_id'],
						$rejected[0]['inbox_status'],
						! empty( $rejected[0]['claimed_by_session_id'] )
							? ' و Session #' . (int) $rejected[0]['claimed_by_session_id'] . ' آن را برداشته'
							: ' (هیچ Session گلدن اسکنی آن را نگرفته — یعنی مصرف‌کننده‌ای بیرون از گلدن اسکن آن را مصرف کرده)'
					);
				}
				/**
				 * «همه claim شده‌اند» یعنی فایل من هنوز نرسیده، نه اینکه
				 * شکست خوردم.
				 *
				 * candidateها از یک بازه‌ی زمانی مشترک ساخته می‌شوند، نه از
				 * پاسخ اختصاصی هر Session. وقتی چند Session هم‌زمان با ربات
				 * حرف می‌زنند، همگی همان چند فایل را می‌بینند؛ اولی
				 * برمی‌دارد و بقیه دست خالی می‌مانند.
				 *
				 * پیش از Auto Worker این تقریباً رخ نمی‌داد چون Sessionها
				 * یکی‌یکی دستی پیش می‌رفتند. با سه‌تا در هر تیک، به قاعده
				 * تبدیل شد — همان ۷ خطای گزارش.
				 *
				 * پس تا وقتی پنجره‌ی ربات باز است منتظر می‌مانیم؛ فایل
				 * مخصوص این Session معمولاً چند ثانیه بعد می‌رسد.
				 */
				$clicked_ts = ! empty( $session['clicked_at'] ) ? strtotime( $session['clicked_at'] ) : 0;
				$waited     = $clicked_ts ? ( time() - $clicked_ts ) : 0;
				$timeout    = class_exists( 'STI_GS_Bot_Candidate_Collector' )
					? STI_GS_Bot_Candidate_Collector::BOT_TIMEOUT_SEC
					: 900;

				if ( ! $clicked_ts || $waited < $timeout ) {
					STI_GS_Session::update( $session_id, array(
						'state'        => 'WAITING_BOT',
						'stage'        => 'file_matcher',
						'error_reason' => null,
					) );
					STI_GS_Event::log( $session_id, 'file_matcher', 'ok',
						'فایل‌های موجود را Sessionهای دیگر برداشته‌اند؛ منتظر فایل مخصوص این Session می‌مانیم.' );
					return array( 'state' => 'WAITING_BOT', 'waiting' => true );
				}

				self::fail( $session_id, 'ALL_CANDIDATES_CLAIMED: هیچ‌کدام از ' . count( $pool ) . ' candidate قابل claim نبودند.' . $detail );
				return new WP_Error( 'sti_gs_all_claimed', 'همه‌ی candidateها قبلاً claim شده‌اند.' );
			}

			global $wpdb;
			$wpdb->update(
				STI_GS_Bot_Candidate::table(),
				array( 'claimed_by_session_id' => $session_id, 'status' => 'matched' ),
				array( 'id' => (int) $winner['id'] )
			);
			// بقیه‌ی candidateهای همین Session صریحاً رد می‌شوند (برای گزارش‌گیری، نه برای منطق).
			foreach ( $candidates as $c ) {
				if ( (int) $c['id'] !== (int) $winner['id'] ) {
					$wpdb->update( STI_GS_Bot_Candidate::table(), array( 'status' => 'rejected' ), array( 'id' => (int) $c['id'] ) );
				}
			}

			$breakdown = array(
				'file_code' => (int) $winner['score_file_code'],
				'file_name' => (int) $winner['score_file_name'],
				'time'      => (int) $winner['score_time'],
				'total'     => (int) $winner['total_score'],
			);

			// P3.4 — اطمینان یکپارچه. هیچ‌کدام از امتیازهای بالا تغییر نمی‌کنند؛
			// این فقط همه را روی یک مقیاس ۰..۱۰۰ می‌آورد. در match_breakdown
			// می‌نشیند که از قبل TEXT است — بدون تغییر Schema.
			$confidence = class_exists( 'STI_GS_Confidence' )
				? STI_GS_Confidence::for_match( array(
					'correlation' => $correlation,
					'candidate'   => $winner,
					'session'     => $session,
				) )
				: null;

			if ( $confidence ) {
				$breakdown['confidence']    = $confidence['confidence'];
				$breakdown['tier']          = $confidence['tier'];
				$breakdown['matched_method']= $confidence['primary'];
				$breakdown['deterministic'] = $confidence['deterministic'];
				$breakdown['sources']       = $confidence['sources'];
				$breakdown['correlation']   = array(
					'confirmed' => (int) ( $correlation['winner_index'] ?? -1 ) >= 0,
					'method'    => (string) ( $correlation['method'] ?? '' ),
					'message_pk'=> (int) ( $correlation['message_pk'] ?? 0 ),
				);
			}

			STI_GS_Session::update( $session_id, array(
				'state'           => 'FILE_MATCHED',
				'stage'           => 'file_matcher',
				'matched_inbox_id'=> (int) $winner['inbox_id'],
				'match_score'     => (int) $winner['total_score'],
				'match_breakdown' => wp_json_encode( $breakdown, JSON_UNESCAPED_UNICODE ),
				'error_reason'    => null,
			) );

			STI_GS_Artifact::log( $session_id, 'match_decision', array( 'result' => 'matched', 'winner' => $winner, 'rejected' => $rejected ) );
			STI_GS_Event::log( $session_id, 'file_matcher', 'ok',
				'Candidate #' . $winner['id'] . ' (فایل ' . $winner['file_name'] . ') با امتیاز ' . $winner['total_score'] . ' انتخاب شد.',
				array( 'pool_size' => count( $pool ) ), $breakdown
			);

			return array( 'state' => 'FILE_MATCHED', 'matched_inbox_id' => (int) $winner['inbox_id'], 'total_score' => (int) $winner['total_score'] );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
		}
	}

	/** claim اتمی روی خودِ sti_bot_inbox — انحصار فایل فیزیکی بین همه‌ی Sessionها (نه فقط این یکی). */
	/**
	 * قدرت هویت یک فایل — مستقل از پسوند.
	 *
	 * «jpg بد، zip خوب» غلط بود؛ «نام‌دار در برابر بی‌نام» بهتر بود ولی هنوز
	 * مبهم. ملاک دقیق این است: آیا از نام فایل می‌شود **هویت** استخراج کرد؟
	 *
	 *   envato_X5LZPEA.zip      → ۱۰۰  (پیشوند سایت + کد)
	 *   Magnific_268186115.jpg  → ۱۰۰  (همان الگو، پسوند فرق دارد)
	 *   my-mockup-1204993.rar   →  ۶۰  (کد دارد، پیشوند ندارد)
	 *   photo_162111.jpg        →   ۰  (نام خودکار تلگرام)
	 *   file.jpg                →   ۰
	 *
	 * چون ملاک هویت است نه فرمت، افزوده شدن هر پسوند تازه (png، rar، 7z)
	 * چرخه‌ی «قاعده → استثنا → باگ» را دوباره راه نمی‌اندازد.
	 */
	protected static function identity_strength( $candidate ) {
		$name = trim( (string) ( $candidate['file_name'] ?? '' ) );
		if ( '' === $name ) {
			return 0;
		}

		$base = preg_replace( '~\.[A-Za-z0-9]{1,5}$~', '', $name );

		// نام خودکار تلگرام: photo_12345 / image-987 / img_1 / file_3
		if ( preg_match( '~^(photo|image|img|file|video|document)[ _-]?\d*$~i', $base ) ) {
			return 0;
		}

		// پیشوند سایت + کد: envato_X5LZPEA ، Magnific_268186115
		if ( preg_match( '~^[A-Za-z][A-Za-z0-9]*_[A-Za-z0-9]{5,16}$~', $base ) ) {
			return 100;
		}

		// کد قابل استخراج در هر جای نام
		if ( preg_match( '~\d{5,}~', $base ) || preg_match( '~\b[A-Z0-9]{6,12}\b~', $base ) ) {
			return 60;
		}

		// نام معنادار ولی بدون هویت قابل استخراج
		return 30;
	}

	protected static function claim_inbox_row( $inbox_id, $session_id = 0 ) {
		global $wpdb;
		if ( ! class_exists( 'STI_Bot_Inbox' ) ) {
			return false;
		}
		$affected = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . STI_Bot_Inbox::table() . " SET status = 'gs_matched' WHERE id = %d AND status = 'new'",
			(int) $inbox_id
		) );
		if ( $affected ) {
			return true;
		}

		// Idempotency: اگر همین Session قبلاً این ردیف را برداشته بود (تلاش
		// قبلی که وسط کار مرد)، دوباره برداشتنش مجاز است. بدون این، هر
		// Retry روی همان Session تا ابد «claim شده» می‌گرفت.
		if ( $session_id > 0 ) {
			$owner = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT claimed_by_session_id FROM ' . STI_GS_Bot_Candidate::table() . ' WHERE inbox_id = %d AND claimed_by_session_id IS NOT NULL LIMIT 1',
				(int) $inbox_id
			) );
			if ( $owner === (int) $session_id ) {
				return true;
			}
		}
		return false;
	}

	/** وضعیت واقعی یک ردیف inbox — برای اینکه شکست claim قابل تشخیص باشد. */
	protected static function inbox_diagnosis( $inbox_id ) {
		global $wpdb;
		$out = array( 'inbox_status' => 'unknown', 'claimed_by_session_id' => 0 );

		if ( class_exists( 'STI_Bot_Inbox' ) ) {
			$status = $wpdb->get_var( $wpdb->prepare(
				'SELECT status FROM ' . STI_Bot_Inbox::table() . ' WHERE id = %d',
				(int) $inbox_id
			) );
			$out['inbox_status'] = ( null === $status ) ? 'row_missing' : (string) $status;
		}

		$out['claimed_by_session_id'] = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT claimed_by_session_id FROM ' . STI_GS_Bot_Candidate::table() . ' WHERE inbox_id = %d AND claimed_by_session_id IS NOT NULL LIMIT 1',
			(int) $inbox_id
		) );

		return $out;
	}

	protected static function fail( $session_id, $reason ) {
		STI_GS_Session::update( $session_id, array( 'state' => 'ERROR_MATCH', 'stage' => 'file_matcher', 'error_reason' => $reason ) );
		STI_GS_Event::log( $session_id, 'file_matcher', 'error', $reason );
	}

	/**
	 * تطبیق قطعی: آیا یکی از candidateها به همان پیام Inventory برمی‌گردد که
	 * این Session از آن ساخته شده؟
	 *
	 * برخلاف امتیازدهی که شباهت را می‌سنجد، اینجا هویت سنجیده می‌شود:
	 * کلید فایل دریافتی ساخته می‌شود و باید به همان message_pk اشاره کند.
	 *
	 * سه حالت عمداً «تأیید» حساب نمی‌شوند و به امتیازدهی واگذار می‌شوند (§38):
	 *   • کلید به پیام دیگری اشاره کند
	 *   • lookup مبهم باشد (دو پیام با یک کلید)
	 *   • بیش از یک candidate تأیید شود
	 *
	 * @return array گزارش تصمیم؛ winner_index = -1 یعنی هیچ تأییدی نبود.
	 */
	protected static function correlate_pool( $session, $pool ) {
		$report = array(
			'available'    => class_exists( 'STI_GS_Correlation' ),
			'message_pk'   => (int) ( $session['message_pk'] ?? 0 ),
			'winner_index' => -1,
			'method'       => '',
			'confidence'   => 0,
			'checked'      => array(),
		);

		if ( ! $report['available'] || ! $report['message_pk'] ) {
			return $report;
		}

		$confirmed = array();
		foreach ( $pool as $index => $candidate ) {
			$keys = STI_GS_Correlation::keys_for_received_file( $candidate );
			if ( empty( $keys ) ) {
				continue;
			}

			$hit = STI_GS_Correlation::lookup( $keys, (int) ( $session['channel_id'] ?? 0 ) );
			$matched_pk = $hit['row'] ? (int) $hit['row']['id'] : 0;

			$report['checked'][] = array(
				'candidate_id' => (int) $candidate['id'],
				'keys'         => wp_list_pluck( $keys, 'key' ),
				'matched_pk'   => $matched_pk,
				'ambiguous'    => (bool) $hit['ambiguous'],
			);

			if ( $matched_pk === $report['message_pk'] && ! $hit['ambiguous'] ) {
				$confirmed[] = array( 'index' => $index, 'hit' => $hit );
			}
		}

		if ( 1 !== count( $confirmed ) ) {
			// صفر یعنی سیگنالی نبود؛ بیش از یکی یعنی خودِ Correlation مبهم است.
			// هر دو حالت به همان امتیازدهی قبلی برمی‌گردند.
			$report['multiple_confirmed'] = count( $confirmed );
			return $report;
		}

		$report['winner_index'] = (int) $confirmed[0]['index'];
		$report['method']       = (string) $confirmed[0]['hit']['method'];
		$report['confidence']   = (int) $confirmed[0]['hit']['confidence'];
		return $report;
	}
}
