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
			'ERROR_BOT_TIMEOUT'  => array( 'Execute Action (کلیک دوباره)', array( 'STI_GS_Action_Executor', 'execute' ) ),
			'BOT_RESPONSE'       => array( 'Match File', array( 'STI_GS_File_Matcher', 'match' ) ),
			// همان استدلال: بدون فایل تازه، تطبیق دوباره نتیجه‌ی یکسان می‌دهد.
			'ERROR_MATCH'        => array( 'Execute Action (کلیک دوباره)', array( 'STI_GS_Action_Executor', 'execute' ) ),
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

		/* ═══════════ معماری زنجیره‌ای (۱۰.۸) ═══════════ */
		if ( class_exists( 'STI_GS_Chain_Engine' )
			&& STI_GS_Node::MODE_LEGACY !== STI_GS_Chain_Engine::mode() ) {

			// حالت‌های زنجیره همیشه در دسترس‌اند (حتی اگر وسط زنجیره Flag عوض شود).
			$map['CHAIN_STEP']    = array( 'Chain Step', array( 'STI_GS_Chain_Engine', 'advance' ) );
			$map['CHAIN_FAILED']  = array( 'Chain Step (تلاش دوباره)', array( 'STI_GS_Chain_Engine', 'advance' ) );
			$map['CHAIN_WAITING'] = array( 'Chain Poll', array( 'STI_GS_Chain_Engine', 'poll' ) );

			// SCANNED تازه: مسیر بر اساس chain_mode ثبت‌شده روی Session.
			//   auto/chain → Chain Init (تصمیم نهایی را خودش می‌گیرد)
			//   legacy/بدون مقدار → Resolver قدیمی (سازگاری کامل با قبل)
			$session_chain = '';
			if ( $session_id ) {
				$s = STI_GS_Session::get( (int) $session_id );
				if ( $s ) {
					$session_chain = (string) ( $s['chain_mode'] ?? '' );
				}
			}
			if ( in_array( $session_chain, array( STI_GS_Node::MODE_AUTO, STI_GS_Node::MODE_CHAIN ), true ) ) {
				$map['SCANNED'] = array( 'Chain Init', array( 'STI_GS_Chain_Engine', 'init' ) );
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
