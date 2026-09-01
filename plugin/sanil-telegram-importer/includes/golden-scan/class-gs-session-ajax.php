<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class STI_GS_Session_Ajax {

	protected static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'wp_ajax_sti_gs_session_create', array( $this, 'ajax_create' ) );
		add_action( 'wp_ajax_sti_gs_session_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_sti_gs_session_resolve_button', array( $this, 'ajax_resolve_button' ) );
		add_action( 'wp_ajax_sti_gs_session_execute_action', array( $this, 'ajax_execute_action' ) );
		add_action( 'wp_ajax_sti_gs_session_poll_bot', array( $this, 'ajax_poll_bot' ) );
		add_action( 'wp_ajax_sti_gs_session_diagnostic', array( $this, 'ajax_diagnostic' ) );
		add_action( 'wp_ajax_sti_gs_session_match_file', array( $this, 'ajax_match_file' ) );
		add_action( 'wp_ajax_sti_gs_session_download', array( $this, 'ajax_download' ) );
		add_action( 'wp_ajax_sti_gs_session_build_media', array( $this, 'ajax_build_media' ) );
		add_action( 'wp_ajax_sti_gs_session_build_product', array( $this, 'ajax_build_product' ) );
		add_action( 'wp_ajax_sti_gs_session_validate', array( $this, 'ajax_validate' ) );
		add_action( 'wp_ajax_sti_gs_session_auto_pipeline', array( $this, 'ajax_auto_pipeline' ) );
		add_action( 'wp_ajax_sti_gs_session_trace', array( $this, 'ajax_trace' ) );
		add_action( 'wp_ajax_sti_gs_session_chain_step', array( $this, 'ajax_chain_step' ) );
		add_action( 'wp_ajax_sti_gs_session_chain_reset', array( $this, 'ajax_chain_reset' ) );
	}

	protected function check_ajax() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	public function ajax_create() {
		$this->check_ajax();
		$id = STI_GS_Session::create_from_profile_item( (int) ( $_POST['profile_item_id'] ?? 0 ) );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'به صف اضافه شد.', 'session' => STI_GS_Session::get( $id ) ) );
	}

	public function ajax_list() {
		$this->check_ajax();
		$args = array( 'limit' => 100 );
		if ( ! empty( $_POST['channel_id'] ) ) { $args['channel_id'] = (int) $_POST['channel_id']; }
		if ( ! empty( $_POST['state'] ) ) { $args['state'] = sanitize_text_field( wp_unslash( $_POST['state'] ) ); }
		wp_send_json_success( array( 'sessions' => STI_GS_Session::list( $args ) ) );
	}

	public function ajax_resolve_button() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		$result = STI_GS_Button_Resolver::resolve( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'result' => $result, 'session' => STI_GS_Session::get( $id ) ) );
	}

	public function ajax_execute_action() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		$result = STI_GS_Action_Executor::execute( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'session' => STI_GS_Session::get( $id ) ) );
		}
		wp_send_json_success( array( 'result' => $result, 'session' => STI_GS_Session::get( $id ) ) );
	}

	public function ajax_match_file() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		$result = STI_GS_File_Matcher::match( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'session' => STI_GS_Session::get( $id ) ) );
		}
		wp_send_json_success( array( 'result' => $result, 'session' => STI_GS_Session::get( $id ) ) );
	}

	public function ajax_download() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		$result = STI_GS_Download_Engine::download( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'session' => STI_GS_Session::get( $id ) ) );
		}
		wp_send_json_success( array( 'result' => $result, 'session' => STI_GS_Session::get( $id ) ) );
	}

	public function ajax_build_media() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		$result = STI_GS_Media_Engine::build( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'session' => STI_GS_Session::get( $id ) ) );
		}
		wp_send_json_success( array( 'result' => $result, 'session' => STI_GS_Session::get( $id ) ) );
	}

	public function ajax_build_product() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		$result = STI_GS_Product_Builder::build( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'session' => STI_GS_Session::get( $id ) ) );
		}
		wp_send_json_success( array( 'result' => $result, 'session' => STI_GS_Session::get( $id ) ) );
	}

	public function ajax_validate() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		$result = STI_GS_Product_Validator::validate( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'session' => STI_GS_Session::get( $id ) ) );
		}
		wp_send_json_success( array( 'result' => $result, 'session' => STI_GS_Session::get( $id ) ) );
	}

	/** فقط سه صدازدن پشت‌سرهم؛ اولین شکست همه‌چیز را متوقف می‌کند. هیچ منطق جدیدی اینجا نیست. */
	/**
	 * «ادامه پردازش» — از هر State ای که Session در آن مانده، تا انتها.
	 *
	 * نسخه‌ی قبلی فقط سه مرحله‌ی آخر (Media → Product → Validate) را اجرا
	 * می‌کرد. وقتی دکمه‌ی تب Session به «ادامه پردازش» تغییر نام داد، این
	 * ناهماهنگی خودش را نشان داد: زدن دکمه روی یک Session تازه، خطای
	 * «INVALID_STATE: باید STORED باشد (الان: SCANNED)» می‌داد چون از وسط
	 * زنجیره شروع می‌کرد.
	 *
	 * حالا State تعیین می‌کند قدم بعدی چیست، و بعد از هر قدم دوباره خوانده
	 * می‌شود. حلقه کران‌دار است و اگر State پیش نرود متوقف می‌شود — پس
	 * حلقه‌ی بی‌نهایت ممکن نیست.
	 */
	/**
	 * «ادامه پردازش» — **یک مرحله در هر درخواست**.
	 *
	 * علت ریشه‌ای گیر کردن در PRODUCT_BUILDING همین بود: نسخه‌ی قبلی کل
	 * زنجیره را در یک درخواست AJAX اجرا می‌کرد. برای Session ای که فایلش
	 * ۵۳ مگابایت است، دانلود به‌تنهایی ۲۲ ثانیه طول می‌کشد؛ با FTP و مدیا و
	 * تولید محتوا، درخواست از سقف وب‌سرور عبور می‌کرد و کشته می‌شد.
	 *
	 * و چون kill از سمت وب‌سرور است نه PHP، نه finally اجرا می‌شود، نه
	 * shutdown handler، نه هیچ لاگی. Session روی PRODUCT_BUILDING می‌ماند
	 * بدون هیچ ردی — دقیقاً همان چیزی که دیدیم.
	 *
	 * حالا هر فراخوانی فقط یک مرحله را اجرا می‌کند و برمی‌گردد. مرورگر
	 * زنجیره را ادامه می‌دهد. هیچ درخواستی بیش از یک مرحله طول نمی‌کشد.
	 */
	public function ajax_auto_pipeline() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );

		$session = STI_GS_Session::get( $id );
		if ( ! $session ) {
			wp_send_json_error( array( 'message' => 'Session پیدا نشد.' ) );
		}

		$state = (string) $session['state'];

		if ( in_array( $state, array( 'REVIEW_READY', 'PUBLISHED' ), true ) ) {
			wp_send_json_success( array(
				'done'    => true,
				'message' => 'این Session کامل است.',
				'session' => $session,
			) );
		}

		$next = self::next_stage( $state, $id );
		if ( ! $next ) {
			wp_send_json_error( array(
				'done'    => true,
				'message' => 'برای وضعیت «' . $state . '» قدم بعدی تعریف نشده. از «🔧 ابزار توسعه» مرحله را دستی اجرا کنید.',
				'session' => $session,
			) );
		}

		$result = call_user_func( $next['run'], $id );
		$after  = STI_GS_Session::get( $id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'done'    => true,
				'stage'   => $next['label'],
				'message' => 'توقف در ' . $next['label'] . ': ' . $result->get_error_message(),
				'session' => $after,
			) );
		}

		$moved = $after && (string) $after['state'] !== $state;

		/**
		 * انتظار برای پاسخ ربات یک «توقف» نیست.
		 *
		 * بعد از Execute Action، ربات چند ثانیه تا چند ده ثانیه طول می‌کشد
		 * تا فایل را بفرستد. تا آن لحظه Poll هیچ candidate ای پیدا نمی‌کند و
		 * State جابه‌جا نمی‌شود. نسخه‌ی قبلی این را «متوقف شد» می‌دید و کاربر
		 * باید دستی Poll Bot می‌زد.
		 *
		 * حالا این حالت جدا علامت می‌خورد تا مرورگر صبر کند و دوباره تلاش
		 * کند — با سقف مشخص، نه بی‌نهایت.
		 */
		/**
		 * دو حالت «انتظار» داریم، نه یکی:
		 *
		 *  ۱. State جابه‌جا نشد و هنوز WAITING_BOT است → پاسخ نرسیده.
		 *  ۲. State از BOT_RESPONSE به WAITING_BOT **برگشت** → فایل‌هایی
		 *     رسیده ولی هنوز آنکه کد درست را دارد نیامده.
		 *
		 * حالت دوم قبلاً «پیش‌رفت» حساب می‌شد و حلقه ادامه می‌داد تا سقف
		 * تمام شود؛ حالا مثل انتظار عادی با فاصله دوباره تلاش می‌کند.
		 */
		$after_state = $after ? (string) $after['state'] : $state;
		$waiting = in_array( $after_state, array( 'WAITING_BOT', 'ERROR_BOT_TIMEOUT', 'CHAIN_WAITING' ), true )
			&& ( ! $moved || 'WAITING_BOT' === $after_state || 'CHAIN_WAITING' === $after_state );

		// NEEDS_REVIEW = توقف قطعی برای بررسی انسانی (نه خطای موقت، نه waiting).
		if ( 'NEEDS_REVIEW' === $after_state ) {
			wp_send_json_success( array(
				'done'    => true,
				'waiting' => false,
				'stage'   => $next['label'],
				'from'    => $state,
				'to'      => 'NEEDS_REVIEW',
				'message' => 'نیاز به بررسی انسانی: ' . ( $after['error_reason'] ?? 'evidence کافی برای ادامه یافت نشد' ) . ' — از «👁 جزئیات» وضعیت را ببینید.',
				'session' => $after,
			) );
		}

		wp_send_json_success( array(
			'done'        => ! $waiting && ! $moved,
			'waiting'     => $waiting,
			'retry_after' => $waiting ? 6 : 0,
			'stage'       => $next['label'],
			'from'        => $state,
			'to'          => $after ? (string) $after['state'] : $state,
			'message'     => $moved
				? $next['label'] . ' انجام شد: ' . $state . ' → ' . $after['state']
				: ( $waiting
					? 'هنوز پاسخی از ربات نرسیده — دوباره تلاش می‌شود.'
					: 'در وضعیت «' . $state . '» متوقف شد (' . $next['label'] . ' پیش‌رفتی نداشت). چند لحظه بعد دوباره بزنید.' ),
			'session'     => $after,
		) );
	}

	/**
	 * مرحله‌ی Poll Bot — دقیقاً همان کاری که دکمه‌ی دستی انجام می‌دهد.
	 *
	 * دو کار لازم است و نه یکی:
	 *   global_poll()        از تلگرام پیام‌های تازه‌ی ربات را می‌گیرد و در
	 *                        sti_bot_inbox می‌نویسد.
	 *   build_for_session()  از همان جدول candidate می‌سازد.
	 *
	 * نسخه‌ی قبلی فقط دومی را صدا می‌زد، پس جدولی را می‌خواند که هیچ‌چیز
	 * پرش نمی‌کرد و تا ابد rows_seen=0 می‌دید. به همین دلیل حالت خودکار از
	 * کلیک دستی بدتر عمل می‌کرد.
	 */
	public static function poll_bot_stage( $session_id ) {
		$global = STI_GS_Bot_Candidate_Collector::global_poll();
		STI_GS_Artifact::log( $session_id, 'global_poll', $global );
		return STI_GS_Bot_Candidate_Collector::build_for_session( (int) $session_id );
	}

	/**
	 * برگرداندن Session به BUTTON_FOUND برای کلیک دوباره.
	 *
	 * گارد Action Executor فقط BUTTON_FOUND و ERROR_CLICK را می‌پذیرد؛
	 * ERROR_BOT_TIMEOUT/ERROR_MATCH مستقیم = INVALID_STATE (همان سه خطای
	 * گزارش). این مرحله فقط state را عوض می‌کند و کار دیگری نمی‌کند —
	 * قدم بعدیِ worker همان Execute Action است.
	 */
	public static function requeue_click( $session_id ) {
		$session = STI_GS_Session::get( (int) $session_id );
		if ( ! $session ) {
			return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
		}
		if ( ! in_array( (string) $session['state'], array( 'ERROR_BOT_TIMEOUT', 'ERROR_MATCH' ), true ) ) {
			return array( 'state' => $session['state'], 'skipped' => true, 'no_progress' => true );
		}
		if ( empty( $session['button_payload'] ) ) {
			// بدون payload، action هرگز قابل تکرار نیست → بررسی انسانی (نه backoff بی‌پایان).
			STI_GS_Session::update( (int) $session_id, array(
				'state'        => 'NEEDS_REVIEW',
				'stage'        => 'session_ajax',
				'error_reason' => 'CHAIN_REQUEUE_NO_PAYLOAD: پنجره‌ی پاسخ بسته شد و button_payload برای کلیک دوباره موجود نیست.',
			) );
			STI_GS_Event::log( (int) $session_id, 'session_ajax', 'error',
				'CHAIN_REQUEUE_NO_PAYLOAD — به NEEDS_REVIEW رفت.' );
			return array( 'state' => 'NEEDS_REVIEW', 'no_progress' => true, 'review' => true );
		}
		STI_GS_Session::update( (int) $session_id, array(
			'state'        => 'BUTTON_FOUND',
			'stage'        => 'session_ajax',
			'error_reason' => null,
		) );
		STI_GS_Event::log( (int) $session_id, 'session_ajax', 'ok',
			'برای کلیک دوباره به BUTTON_FOUND برگردانده شد (از ' . $session['state'] . ').' );

		/**
		 * WP_Error عمدی (backoff) — ضدحلقه.
		 *
		 * اگر success برگردانده شود، advance_one شمارنده‌ی attempts را ریست
		 * می‌کند (چون state تغییر کرده) و چرخه‌ی بی‌پایان می‌شود:
		 *
		 *   ERROR_BOT_TIMEOUT → BUTTON_FOUND → Execute Action (Bot Action)
		 *   → WAITING_BOT → poll → (۹۰۰s) → ERROR_BOT_TIMEOUT → …
		 *
		 * با WP_Error، worker آن را failure می‌شمارد → attempts+1 + backoff
		 * نمایی (۵→۱۰→۲۰→۴۰→۸۰ دقیقه) → بعد از MAX_ATTEMPTS: ۶ ساعت صبر.
		 * یعنی حداکثر یک Bot Action در هر ۶ ساعت برای یک Session بی‌پاسخ.
		 */
		return new WP_Error( 'sti_gs_requeue_backoff', 'کلیک دوباره زمان‌بندی شد — تلاش بعدی با backoff (ضدحلقه).' );
	}

	/**
	 * ERROR_MATCH — ابتدا نوع شکست مشخص می‌شود؛ هیچ‌وقت کورکورانه به
	 * Execute Action برنمی‌گردد (WAITING_BOT ≠ BUTTON_FOUND):
	 *
	 *   • ALL_CANDIDATES_CLAIMED / claim (رقابت یا فایل هنوز نرسیده)
	 *     → Match Retry: WAITING_BOT + poll تازه. با WP_Error برمی‌گردد تا
	 *       Auto Worker backoff/سقف تلاش خودش را اعمال کند (محدود).
	 *   • NO_IDENTIFIABLE_FILE / هویت → NEEDS_REVIEW (بررسی انسانی).
	 *   • سایر → NEEDS_REVIEW (تصمیم ناامن).
	 *
	 * خطاهای موقت تلگرام (timeout/network/rate-limit) اینجا نمی‌آیند —
	 * آن‌ها در مسیرهای خودشان retry/backoff دارند.
	 *
	 * @return array|WP_Error
	 */
	public static function match_recovery( $session_id ) {
		$session = STI_GS_Session::get( (int) $session_id );
		if ( ! $session ) {
			return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
		}
		if ( 'ERROR_MATCH' !== (string) $session['state'] ) {
			return array( 'state' => $session['state'], 'skipped' => true, 'no_progress' => true );
		}

		$reason = (string) ( $session['error_reason'] ?? '' );
		$low    = mb_strtolower( $reason );

		/* شکست از نوع رقابت claim — فایلِ خودِ Session معمولاً چند لحظه بعد می‌رسد */
		if ( false !== strpos( $low, 'claim' ) || false !== strpos( $low, 'all_candidates' ) ) {
			STI_GS_Session::update( (int) $session_id, array(
				'state'        => 'WAITING_BOT',
				'stage'        => 'session_ajax',
				'error_reason' => null,
			) );
			STI_GS_Event::log( (int) $session_id, 'session_ajax', 'ok',
				'Match Retry: ' . $reason . ' — به WAITING_BOT برگشت تا poll تازه فایلِ خودِ Session را بیاورد.' );
			// WP_Error عمدی: worker آن را failure می‌شمارد → attempts+1 و backoff (محدود).
			return new WP_Error( 'sti_gs_match_retry', 'رقابت claim — poll دوباره با backoff.' );
		}

		/* هویت نامشخص یا هر شکست غیرقابل‌تشخیص → بررسی انسانی */
		$code = 'CHAIN_MATCH_AMBIGUOUS';
		if ( false !== strpos( $low, 'identifiable' ) || false !== strpos( $low, 'no_identity' ) ) {
			$code = 'CHAIN_MATCH_NO_IDENTITY';
		}
		STI_GS_Session::update( (int) $session_id, array(
			'state'        => 'NEEDS_REVIEW',
			'stage'        => 'session_ajax',
			'error_reason' => mb_substr( $code . ': ' . $reason, 0, 250 ),
		) );
		STI_GS_Event::log( (int) $session_id, 'session_ajax', 'error', $code . ': ' . $reason );
		return array( 'state' => 'NEEDS_REVIEW', 'no_progress' => true, 'review' => true );
	}

	/** نگاشت State فعلی به موتوری که باید اجرا شود. */
	/**
	 * عمومی است تا Auto Worker از **همین** نگاشت استفاده کند.
	 * دو نگاشت موازی یعنی دو رفتار متفاوت بین حالت دستی و خودکار — همان
	 * چیزی که §157 منع کرده.
	 *
	 * معماری زنجیره‌ای (۱۰.۸): وقتی gs_chain_mode != legacy باشد، حالت‌های
	 * زنجیره (CHAIN_STEP/CHAIN_WAITING/CHAIN_FAILED) به Chain Engine وصل
	 * می‌شوند. تصمیم SCANNED به chain_mode ثبت‌شده روی خود Session بستگی
	 * دارد: تازه‌ها (auto/chain) → Chain Init؛ legacy/قدیمی‌ها → Resolver
	 * قبلی. Sessionهای قدیمی بدون chain_mode دست‌نخورده legacy می‌مانند.
	 *
	 * @param string $state
	 * @param int    $session_id  برای تصمیم SCANNED بر اساس chain_mode خود Session.
	 */
	public static function next_stage( $state, $session_id = 0 ) {
		$map = array(
			'SCANNED'            => array( 'Resolve Button', array( 'STI_GS_Button_Resolver', 'resolve' ) ),
			'ERROR_BUTTON'       => array( 'Resolve Button', array( 'STI_GS_Button_Resolver', 'resolve' ) ),
			'BUTTON_FOUND'       => array( 'Execute Action', array( 'STI_GS_Action_Executor', 'execute' ) ),
			'ERROR_CLICK'        => array( 'Execute Action', array( 'STI_GS_Action_Executor', 'execute' ) ),
			// عمداً build_for_session مستقیم صدا زده نمی‌شود: آن فقط جدول
			// inbox را می‌خواند. اگر چیزی از تلگرام نگرفته باشد، تا ابد صفر
			// می‌بیند. مسیر درست همان است که دکمه‌ی دستی «Poll Bot» می‌رود.
			'WAITING_BOT'        => array( 'Poll Bot', array( __CLASS__, 'poll_bot_stage' ) ),
			/**
			 * پس از پایان مهلت، دوباره Poll کردن بی‌فایده است.
			 *
			 * ربات فقط **در پاسخ به کلیک** فایل می‌فرستد. اگر فایل این
			 * Session را Session دیگری برداشته باشد یا اصلاً نرسیده باشد،
			 * خواندن دوباره‌ی صندوق ورودی تا ابد همان صفر را می‌دهد.
			 *
			 * مسیر درست بازیابی، کلیک دوباره روی همان deep link است.
			 */
			'ERROR_BOT_TIMEOUT'  => array( 'Execute Action (کلیک دوباره)', array( __CLASS__, 'requeue_click' ) ),
			'BOT_RESPONSE'       => array( 'Match File', array( 'STI_GS_File_Matcher', 'match' ) ),
			// همان استدلال: بدون فایل تازه، تطبیق دوباره نتیجه‌ی یکسان می‌دهد.
			'ERROR_MATCH'        => array( 'Match Recovery', array( __CLASS__, 'match_recovery' ) ),
			'FILE_MATCHED'       => array( 'Download', array( 'STI_GS_Download_Engine', 'download' ) ),
			'DOWNLOAD_PENDING'   => array( 'Download', array( 'STI_GS_Download_Engine', 'download' ) ),
			'DOWNLOADING'        => array( 'Download', array( 'STI_GS_Download_Engine', 'download' ) ),
			'DOWNLOAD_FAILED'    => array( 'Download', array( 'STI_GS_Download_Engine', 'download' ) ),
			'DOWNLOADED'         => array( 'Build Media', array( 'STI_GS_Media_Engine', 'build' ) ),
			'STORED'             => array( 'Build Media', array( 'STI_GS_Media_Engine', 'build' ) ),
			'MEDIA_FAILED'       => array( 'Build Media', array( 'STI_GS_Media_Engine', 'build' ) ),
			'MEDIA_BUILDING'     => array( 'Build Media', array( 'STI_GS_Media_Engine', 'build' ) ),
			'MEDIA_READY'        => array( 'Build Product', array( 'STI_GS_Product_Builder', 'build' ) ),
			'PRODUCT_BUILDING'   => array( 'Build Product', array( 'STI_GS_Product_Builder', 'build' ) ),
			'PRODUCT_FAILED'     => array( 'Build Product', array( 'STI_GS_Product_Builder', 'build' ) ),
			'PRODUCT_READY'      => array( 'Validate', array( 'STI_GS_Product_Validator', 'validate' ) ),
		);

		/* ═══════════ معماری زنجیره‌ای (۱۰.۸) — مسیریابی بر اساس chain_mode خود Session ═══════════ */
		$global_mode = class_exists( 'STI_GS_Chain_Engine' ) ? STI_GS_Chain_Engine::mode() : STI_GS_Node::MODE_LEGACY;
		if ( STI_GS_Node::MODE_LEGACY !== $global_mode ) {

			// حالت‌های زنجیره همیشه در دسترس‌اند (حتی اگر وسط زنجیره Flag عوض شود).
			$map['CHAIN_STEP']    = array( 'Chain Step', array( 'STI_GS_Chain_Engine', 'advance' ) );
			$map['CHAIN_FAILED']  = array( 'Chain Step (تلاش دوباره)', array( 'STI_GS_Chain_Engine', 'advance' ) );
			$map['CHAIN_WAITING'] = array( 'Chain Poll', array( 'STI_GS_Chain_Engine', 'poll' ) );

			$session_chain = '';
			if ( $session_id ) {
				$s = STI_GS_Session::get( (int) $session_id );
				if ( $s ) {
					$session_chain = (string) ( $s['chain_mode'] ?? '' );
				}
			}

			$is_chain_session = in_array( $session_chain, array( STI_GS_Node::MODE_AUTO, STI_GS_Node::MODE_CHAIN ), true );

			/**
			 * D6 — invariant قطعی:
			 *   SCANNED + chain_mode ∈ {auto, chain} → Chain Init
			 *   SCANNED + chain_mode = NULL          → Legacy Resolver
			 * Global UI mode هرگز معنای Session قدیمی NULL را تغییر نمی‌دهد.
			 * فقط recover() می‌تواند با evidence صریح یک Session قدیمی را migrate کند
			 * (و آن هم فقط از حالت‌های «گیرکرده»، نه از SCANNED).
			 */
			if ( $is_chain_session ) {
				$map['SCANNED'] = array( 'Chain Init', array( 'STI_GS_Chain_Engine', 'init' ) );
			}

			/**
			 * NULL + global chain → فقط کاندید recover (evidence-gated).
			 * recover() بدون evidence → NEEDS_REVIEW، نه CHAIN_STEP و نه fallback بی‌صدا.
			 */
			$is_null_candidate = ( '' === $session_chain && STI_GS_Node::MODE_CHAIN === $global_mode );
			$chain_candidate   = $is_chain_session || $is_null_candidate;

			$has_steps = $session_id && class_exists( 'STI_GS_Handoff_Steps' )
				&& STI_GS_Handoff_Steps::depth( (int) $session_id ) > 0;

			if ( $chain_candidate && ! $has_steps && STI_GS_Node::MODE_LEGACY !== $session_chain ) {
				// بدون گام = مسیر قدیم گیرکرده → انتقال با evidence.
				// WAITING_BOT از دیسپچر waiting() می‌گذرد (Poll داخل پنجره / recover خارج پنجره).
				$map['WAITING_BOT']       = array( 'Chain Waiting', array( 'STI_GS_Chain_Engine', 'waiting' ) );
				$map['BUTTON_FOUND']      = array( 'Chain Recover', array( 'STI_GS_Chain_Engine', 'recover' ) );
				$map['ERROR_CLICK']       = array( 'Chain Recover', array( 'STI_GS_Chain_Engine', 'recover' ) );
				$map['ERROR_BOT_TIMEOUT'] = array( 'Chain Recover', array( 'STI_GS_Chain_Engine', 'recover' ) );
				$map['ERROR_MATCH']       = array( 'Chain Recover', array( 'STI_GS_Chain_Engine', 'recover' ) );
			} elseif ( $chain_candidate && $has_steps ) {
				// با گام = مسیر Asset زنجیره (بعد از ASSET): WAITING_BOT → Poll قدیم درست است.
				// timeout → بازیابی صریح فقط با button_payload؛ ERROR_MATCH → match retry / review.
				$map['ERROR_BOT_TIMEOUT'] = array( 'Chain Timeout Recovery', array( 'STI_GS_Chain_Engine', 'timeout_recovery' ) );
				$map['ERROR_MATCH']       = array( 'Match Recovery', array( __CLASS__, 'match_recovery' ) );
			} else {
				// legacy: ERROR_BOT_TIMEOUT → requeue_click (بازیابی صریح timeout، از قبل در map).
				// ERROR_MATCH دیگر کورکورانه به Execute Action نمی‌رود → match_recovery.
				$map['ERROR_MATCH'] = array( 'Match Recovery', array( __CLASS__, 'match_recovery' ) );
			}
		}

		if ( empty( $map[ $state ] ) ) {
			return null;
		}
		list( $label, $callable ) = $map[ $state ];
		if ( ! class_exists( $callable[0] ) || ! method_exists( $callable[0], $callable[1] ) ) {
			return null;
		}
		return array( 'label' => $label, 'run' => $callable );
	}

	public function ajax_trace() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		wp_send_json_success( array(
			'session'      => STI_GS_Session::get( $id ),
			'artifacts'    => STI_GS_Artifact::for_session( $id ),
			'events'       => STI_GS_Event::for_session( $id ),
			'candidates'   => class_exists( 'STI_GS_Bot_Candidate' ) ? STI_GS_Bot_Candidate::for_session( $id ) : array(),
			'chain_steps'  => class_exists( 'STI_GS_Handoff_Steps' ) ? STI_GS_Handoff_Steps::steps( $id ) : array(),
			'chain_mode'   => class_exists( 'STI_GS_Chain_Engine' ) ? STI_GS_Chain_Engine::mode() : 'legacy',
		) );
	}

	/** ابزار توسعه: اجرای گام بعدی زنجیره بر اساس State فعلی (دستی). */
	public function ajax_chain_step() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		$session = STI_GS_Session::get( $id );
		if ( ! $session ) {
			wp_send_json_error( array( 'message' => 'Session پیدا نشد.' ) );
		}
		$state = (string) $session['state'];
		$next  = self::next_stage( $state, $id );
		if ( ! $next ) {
			wp_send_json_error( array( 'message' => 'برای وضعیت «' . $state . '» قدم زنجیره‌ای تعریف نشده.' ) );
		}
		$result = call_user_func( $next['run'], $id );
		wp_send_json_success( array(
			'result'  => $result,
			'stage'   => $next['label'],
			'session' => STI_GS_Session::get( $id ),
			'steps'   => class_exists( 'STI_GS_Handoff_Steps' ) ? STI_GS_Handoff_Steps::steps( $id ) : array(),
		) );
	}

	/** ابزار توسعه: پاک کردن زنجیره و برگرداندن Session به SCANNED. */
	public function ajax_chain_reset() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		if ( class_exists( 'STI_GS_Handoff_Steps' ) ) {
			STI_GS_Handoff_Steps::clear( $id );
		}
		STI_GS_Session::update( $id, array(
			'state'             => 'SCANNED',
			'stage'             => 'chain_engine',
			'chain_current_step'=> 0,
			'error_reason'      => null,
		) );
		wp_send_json_success( array( 'message' => 'زنجیره پاک شد؛ Session به SCANNED برگشت.', 'session' => STI_GS_Session::get( $id ) ) );
	}

	public function ajax_poll_bot() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		$global = STI_GS_Bot_Candidate_Collector::global_poll();
		STI_GS_Artifact::log( $id, 'global_poll', $global );
		$result = STI_GS_Bot_Candidate_Collector::build_for_session( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'session' => STI_GS_Session::get( $id ) ) );
		}
		wp_send_json_success( array( 'result' => $result, 'global_poll' => $global, 'session' => STI_GS_Session::get( $id ) ) );
	}

	/** حالت تشخیصی: بدون Poll واقعی، فقط ۲۰ پیام آخر گفتگوی این ربات را خام نشان می‌دهد. */
	public function ajax_diagnostic() {
		$this->check_ajax();
		$id = (int) ( $_POST['session_id'] ?? 0 );
		$session = STI_GS_Session::get( $id );
		if ( ! $session || empty( $session['bot_username'] ) ) {
			wp_send_json_error( array( 'message' => 'bot_username روی این Session موجود نیست.' ) );
		}
		$result = STI_MTProto::instance()->debug_peer_history( $session['bot_username'], 20 );
		if ( is_wp_error( $result ) ) {
			STI_GS_Artifact::log( $id, 'diagnostic', array( 'peer' => $session['bot_username'], 'error' => $result->get_error_message() ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		STI_GS_Artifact::log( $id, 'diagnostic', $result );
		wp_send_json_success( array( 'result' => $result ) );
	}
}
