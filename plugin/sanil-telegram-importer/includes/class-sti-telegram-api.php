<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin wrapper around the Telegram Bot API using cURL directly so that a
 * text-only proxy can be applied (per user's server constraints: proxy works
 * for JSON/text API calls but NOT for binary file downloads).
 */
class STI_Telegram_API {

	protected $token;

	public function __construct( $token = null ) {
		$this->token = $token ?: STI_Settings::get( 'bot_token' );
	}

	protected function api_url( $method ) {
		$base = trim( STI_Settings::get( 'api_base_url', 'https://api.telegram.org' ) );
		$base = $base ? untrailingslashit( $base ) : 'https://api.telegram.org';
		return "{$base}/bot{$this->token}/{$method}";
	}

	protected function file_base_url() {
		$base = trim( STI_Settings::get( 'api_base_url', 'https://api.telegram.org' ) );
		return $base ? untrailingslashit( $base ) : 'https://api.telegram.org';
	}

	/**
	 * Holds diagnostic info (errno/error/http_code) for the most recent call(),
	 * so admin-side "test connection" buttons can show a precise reason.
	 */
	protected $last_error = array();

	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Call a Telegram Bot API method (JSON in/out). Routed through the proxy if configured.
	 */
	public function call( $method, $params = array(), $retries = 1 ) {
		$this->last_error = array();

		if ( empty( $this->token ) ) {
			$this->last_error = array( 'message' => 'Bot Token خالی است.' );
			STI_Logger::error( 'Telegram API: bot token خالی است.' );
			return false;
		}

		$attempts = max( 1, $retries + 1 );
		for ( $i = 1; $i <= $attempts; $i++ ) {
			$result = $this->do_call( $method, $params );
			if ( false !== $result ) {
				return $result;
			}
			// Only worth retrying on network-level failures (filtering flakiness),
			// not on a valid-but-erroring API response.
			if ( empty( $this->last_error['stage'] ) || 'curl' !== $this->last_error['stage'] ) {
				break;
			}
			if ( $i < $attempts ) {
				usleep( 400000 ); // 0.4s backoff before retry
			}
		}
		return false;
	}

	protected function do_call( $method, $params = array() ) {

		if ( ! function_exists( 'curl_init' ) ) { $this->last_error = array( 'stage' => 'curl', 'message' => 'اکستنشن cURL در PHP فعال نیست.' ); return false; }
		$ch = curl_init( $this->api_url( $method ) );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $params ),
			CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 12,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_SSL_VERIFYPEER => true,
		) );

		$this->maybe_apply_proxy( $ch );

		$response  = curl_exec( $ch );
		$errno     = curl_errno( $ch );
		$error     = curl_error( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $errno ) {
			$this->last_error = array(
				'stage'   => 'curl',
				'errno'   => $errno,
				'message' => $this->humanize_curl_error( $errno, $error ),
			);
			STI_Logger::error( "Telegram API cURL error ({$method}) [{$errno}]: {$error}" );
			return false;
		}

		$data = json_decode( $response, true );
		if ( empty( $data['ok'] ) ) {
			$desc = ! empty( $data['description'] ) ? $data['description'] : "پاسخ نامعتبر از تلگرام (HTTP {$http_code})";
			$this->last_error = array(
				'stage'     => 'api',
				'http_code' => $http_code,
				'message'   => $desc,
			);

			// خطای 403 «ربات عضو سوپرگروه نیست» را mute کن تا لاگ را اشباع نکند.
			// معمولاً وقتی chat_id قدیمی/گروه حذف‌شده در admin_chat_ids یا session باقی مانده.
			/*
			 * v7 — رفع دائمی خطای «bot is not a member of the supergroup chat».
			 * دو ایراد قبلی: (۱) فقط به HTTP code نگاه می‌شد و بعضی هاست‌ها ۰ برمی‌گردانند
			 * پس خطا mute نمی‌شد و لاگ را پر می‌کرد؛ (۲) chat_id مقصر در تنظیمات باقی
			 * می‌ماند و هر بار دوباره امتحان می‌شد. حالا کد خطا از خود پاسخ تلگرام خوانده
			 * می‌شود و آن chat_id برای همیشه از فهرست ادمین‌ها پاک می‌شود.
			 */
			$api_code = (int) ( $data['error_code'] ?? $http_code );
			$is_not_member = in_array( $api_code, array( 400, 403 ), true )
				&& ( false !== stripos( $desc, 'not a member' )
					|| false !== stripos( $desc, 'Forbidden' )
					|| false !== stripos( $desc, 'bot was kicked' )
					|| false !== stripos( $desc, 'chat not found' )
					|| false !== stripos( $desc, 'deactivated' )
					|| false !== stripos( $desc, 'kicked' )
					|| false !== stripos( $desc, 'blocked' )
					|| false !== stripos( $desc, 'upgraded to a supergroup' )
					|| false !== stripos( $desc, 'not enough rights' )
					|| false !== stripos( $desc, 'group chat was deactivated' ) );

			if ( $is_not_member ) {
				$chat_id = isset( $params['chat_id'] ) ? (string) $params['chat_id'] : '';
				if ( $chat_id ) {
					self::mute_chat( $chat_id, $desc );
					self::purge_dead_chat( $chat_id );
					self::forget_chat_everywhere( $chat_id, $desc );
				}
				// فقط یک‌بار در ساعت لاگ هشدار بزن
				$throttle_key = 'sti_tg_403_' . md5( $chat_id . $method );
				if ( false === get_transient( $throttle_key ) ) {
					set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
					STI_Logger::warning( "Telegram API 403 (muted): {$method} chat={$chat_id} — {$desc}" );
				}
			} else {
				STI_Logger::error( "Telegram API responded with error ({$method}) [HTTP {$http_code}]: " . $response );
			}
			return false;
		}
		return $data['result'];
	}

	/**
	 * لیست chat_id هایی که ربات دیگر به آن‌ها دسترسی ندارد.
	 * قبل از send_message چک می‌شود تا درخواست بیهوده و لاگ تکراری نسازیم.
	 */
	/**
	 * راه‌حل دائمی خطای «bot is not a member of the supergroup chat».
	 * وقتی تلگرام ۴۰۳ می‌دهد یعنی آن گروه/کانال دیگر در دسترس ربات نیست
	 * (ربات حذف شده، گروه به سوپرگروه ارتقا پیدا کرده و chat_id عوض شده،
	 * یا گروه پاک شده). فقط mute کافی نیست چون منبع خطا در تنظیمات باقی می‌ماند
	 * و هر بار دوباره تلاش می‌شود. اینجا ریشه‌ی خطا از تنظیمات حذف می‌شود.
	 */
	public static function purge_dead_chat( $chat_id ) {
		$chat_id = (string) $chat_id;
		if ( '' === $chat_id ) { return; }

		/* ۱) از لیست chat_id های مدیر حذف شود */
		if ( class_exists( 'STI_Settings' ) ) {
			$raw = (string) STI_Settings::get( 'admin_chat_ids', '' );
			if ( '' !== $raw ) {
				$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
				$kept = array();
				foreach ( $parts as $p ) {
					if ( (string) $p !== $chat_id ) { $kept[] = $p; }
				}
				if ( count( $kept ) !== count( $parts ) ) {
					STI_Settings::update( array( 'admin_chat_ids' => implode( ',', $kept ) ) );
					STI_Logger::warning( 'شناسه‌ی چت ' . $chat_id . ' از تنظیمات حذف شد چون ربات دیگر به آن دسترسی ندارد.' );
				}
			}
		}

		/* ۲) اگر گروه پایش‌شده است، غیرفعال شود (کدش پاک نمی‌شود) */
		if ( class_exists( 'STI_Group_Monitor' ) ) {
			try {
				$gm = STI_Group_Monitor::instance();
				if ( method_exists( $gm, 'is_monitored' ) && $gm->is_monitored( $chat_id ) ) {
					if ( method_exists( $gm, 'toggle_monitored_group' ) ) {
						$gm->toggle_monitored_group( $chat_id, false );
						STI_Logger::warning( 'گروه پایش‌شده ' . $chat_id . ' خودکار غیرفعال شد (ربات عضو نیست).' );
					}
				}
			} catch ( \Throwable $e ) {
				// بی‌خیال
			}
		}

		/* ۳) حالت ثبت سریع همان چت پاک شود تا پیام‌ها بی‌جواب نمانند */
		delete_option( 'sti_bulk_mode_' . $chat_id );
	}

	public static function mute_chat( $chat_id, $reason = '' ) {
		$chat_id = (string) $chat_id;
		if ( '' === $chat_id ) {
			return;
		}
		$list = get_option( 'sti_muted_chat_ids', array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		$list[ $chat_id ] = array(
			'reason' => mb_substr( (string) $reason, 0, 200 ),
			'at'     => time(),
		);
		// حداکثر ۲۰۰ مورد نگه دار
		if ( count( $list ) > 200 ) {
			$list = array_slice( $list, -200, null, true );
		}
		update_option( 'sti_muted_chat_ids', $list, false );
	}

	/**
	 * پاک کردن یک chat_id از همه‌ی جاهایی که باعث تلاش دوباره می‌شود:
	 * فهرست ادمین‌های قدیمی، گروه‌های تحت نظر (Mode 2) و session های باز آن چت.
	 * نتیجه: خطای 403 دیگر هرگز تکرار نمی‌شود و یک راهنمای روشن لاگ می‌شود.
	 */
	public static function forget_chat_everywhere( $chat_id, $reason = '' ) {
		$chat_id = (string) $chat_id;
		if ( '' === $chat_id ) { return; }

		$raw = (string) STI_Settings::get( 'admin_chat_ids', '' );
		if ( '' !== $raw ) {
			$list = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
			$kept = array_values( array_filter( $list, function ( $id ) use ( $chat_id ) {
				return (string) $id !== $chat_id;
			} ) );
			if ( count( $kept ) !== count( $list ) ) {
				STI_Settings::update( array( 'admin_chat_ids' => implode( ',', $kept ) ) );
				STI_Logger::warning( 'چت ' . $chat_id . ' از فهرست ادمین‌ها حذف شد چون ربات دیگر در آن عضو نیست. اگر این گروه را می‌خواهی، ربات را دوباره عضو و ادمین کن و شناسه‌ی جدید سوپرگروه را وارد کن (شناسه‌ی گروه بعد از ارتقا به سوپرگروه عوض می‌شود).' );
			}
		}

		if ( class_exists( 'STI_Group_Monitor' ) ) {
			$gm = STI_Group_Monitor::instance();
			if ( method_exists( $gm, 'is_monitored' ) && $gm->is_monitored( $chat_id ) ) {
				if ( method_exists( $gm, 'toggle_monitored_group' ) ) {
					$gm->toggle_monitored_group( $chat_id, false );
					STI_Logger::warning( 'گروه ' . $chat_id . ' موقتاً غیرفعال شد (ربات عضو نیست).' );
				}
			}
		}
	}

	public static function is_chat_muted( $chat_id ) {
		$list = get_option( 'sti_muted_chat_ids', array() );
		return is_array( $list ) && isset( $list[ (string) $chat_id ] );
	}

	public static function unmute_chat( $chat_id ) {
		$list = get_option( 'sti_muted_chat_ids', array() );
		if ( ! is_array( $list ) ) {
			return;
		}
		unset( $list[ (string) $chat_id ] );
		update_option( 'sti_muted_chat_ids', $list, false );
	}

	/**
	 * Turn a raw cURL errno into a message a non-developer admin can act on.
	 */
	protected function humanize_curl_error( $errno, $raw_message ) {
		$map = array(
			CURLE_COULDNT_RESOLVE_PROXY => 'آدرس پراکسی پیدا نشد (DNS). آدرس پراکسی را دوباره چک کن.',
			CURLE_COULDNT_RESOLVE_HOST  => 'آدرس api.telegram.org قابل شناسایی نبود — یعنی پراکسی اعمال نشده یا خودش کار نمی‌کند.',
			CURLE_COULDNT_CONNECT       => 'اتصال به پراکسی یا به تلگرام برقرار نشد (سرور/پورت را چک کن یا پراکسی خاموش است).',
			CURLE_OPERATION_TIMEDOUT    => 'زمان اتصال تمام شد (Timeout) — پراکسی کند است یا فیلتر شده.',
			CURLE_SSL_CONNECT_ERROR     => 'خطای SSL هنگام اتصال — معمولاً یعنی پراکسی از نوع درستی انتخاب نشده (HTTP در برابر SOCKS5).',
			CURLE_RECV_ERROR            => 'داده‌ای از پراکسی/تلگرام دریافت نشد؛ اتصال قطع شد.',
		);
		if ( isset( $map[ $errno ] ) ) {
			return $map[ $errno ] . ' (کد ' . $errno . ')';
		}
		// CURLE_PROXY (specific auth/proxy failures are often 5,7,28,35,56 handled above)
		return 'خطای اتصال: ' . $raw_message . ' (کد ' . $errno . ')';
	}

	protected function maybe_apply_proxy( $ch ) {
		if ( ! STI_Settings::get( 'proxy_enabled' ) ) {
			return;
		}
		$host = STI_Settings::get( 'proxy_host' );
		$port = STI_Settings::get( 'proxy_port' );
		if ( empty( $host ) ) {
			return;
		}
		curl_setopt( $ch, CURLOPT_PROXY, $host );
		if ( ! empty( $port ) ) {
			curl_setopt( $ch, CURLOPT_PROXYPORT, (int) $port );
		}

		$type = STI_Settings::get( 'proxy_type', 'http' );
		$type_map = array(
			'http'    => CURLPROXY_HTTP,
			'socks5'  => CURLPROXY_SOCKS5,
			'socks5h' => CURLPROXY_SOCKS5_HOSTNAME, // resolves DNS through the proxy too — best for blocked hosts.
			'socks4'  => CURLPROXY_SOCKS4,
		);
		curl_setopt( $ch, CURLOPT_PROXYTYPE, $type_map[ $type ] ?? CURLPROXY_HTTP );

		$user = STI_Settings::get( 'proxy_user' );
		$pass = STI_Settings::get( 'proxy_pass' );
		if ( ! empty( $user ) ) {
			curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $user . ':' . $pass );
		}
	}

	/* ---------- convenience wrappers ---------- */

	public function send_message( $chat_id, $text, $reply_markup = null, $extra = array() ) {
		// اگر این چت قبلاً 403 داده، درخواست نفرست (جلوگیری از اسپم لاگ و فشار به API)
		if ( self::is_chat_muted( $chat_id ) ) {
			return false;
		}
		$params = array_merge( array(
			'chat_id'    => $chat_id,
			'text'       => $text,
			'parse_mode' => 'HTML',
		), $extra );
		if ( $reply_markup ) {
			$params['reply_markup'] = $reply_markup;
		}
		return $this->call( 'sendMessage', $params );
	}

	public function edit_message_text( $chat_id, $message_id, $text, $reply_markup = null ) {
		$params = array(
			'chat_id'    => $chat_id,
			'message_id' => $message_id,
			'text'       => $text,
			'parse_mode' => 'HTML',
		);
		if ( $reply_markup ) {
			$params['reply_markup'] = $reply_markup;
		}
		return $this->call( 'editMessageText', $params );
	}

	public function answer_callback_query( $callback_id, $text = '', $show_alert = false ) {
		return $this->call( 'answerCallbackQuery', array(
			'callback_query_id' => $callback_id,
			'text'              => $text,
			'show_alert'        => $show_alert,
		) );
	}

	public function set_webhook( $url ) {
		return $this->call( 'setWebhook', array( 'url' => $url, 'allowed_updates' => array( 'message', 'callback_query' ) ) );
	}

	public function delete_webhook() {
		return $this->call( 'deleteWebhook' );
	}

	/** Removes Telegram's persistent slash-command panel; inline menu remains available. */
	public function delete_my_commands() {
		return $this->call( 'deleteMyCommands' );
	}

	/** Removes old persistent ReplyKeyboardMarkup; Telegram then shows its native menu button. */
	public function remove_reply_keyboard( $chat_id ) {
		return $this->send_message( $chat_id, 'منوی قدیمی بسته شد. از دکمهٔ منوی تلگرام استفاده کن.', array( 'remove_keyboard' => true ) );
	}

	public function get_webhook_info() {
		return $this->call( 'getWebhookInfo' );
	}

	public function get_me() {
		return $this->call( 'getMe' );
	}

	/**
	 * Registers the bot's command list with Telegram so it appears in the
	 * native "/" command menu in every Telegram client (mobile/desktop/web).
	 */
	public function set_my_commands() {
		$commands = array(
			array( 'command' => 'start', 'description' => 'شروع ثبت محصول جدید' ),
			array( 'command' => 'menu', 'description' => 'نمایش منوی دکمه‌ای' ),
			array( 'command' => 'queue', 'description' => 'وضعیت صف انتشار + شروع/توقف' ),
			array( 'command' => 'preview', 'description' => 'پیش‌نمایش ۵ محصول آخر' ),
			array( 'command' => 'status', 'description' => 'وضعیت محصول در حال ساخت' ),
			array( 'command' => 'done', 'description' => 'پایان حالت ثبت سریع' ),
			array( 'command' => 'cancel', 'description' => 'لغو محصول در حال ساخت' ),
		);
		return $this->call( 'setMyCommands', array( 'commands' => $commands ) );
	}

	/**
	 * Persistent quick-access reply keyboard shown at the bottom of the chat
	 * (in addition to the "/" command menu), for one-tap access to the most
	 * common actions without typing.
	 */
	public static function quick_menu_keyboard() {
		return array(
			'keyboard' => array(
				array( array( 'text' => '/start' ), array( 'text' => '/queue' ) ),
				array( array( 'text' => '/preview' ), array( 'text' => '/status' ) ),
			),
			'resize_keyboard'   => true,
			'is_persistent'     => true,
		);
	}

	/**
	 * Attempt to resolve a downloadable URL for a telegram file_id.
	 * NOTE: on hosts where the proxy only forwards JSON API calls (not the
	 * binary /file/ endpoint) this will typically fail - callers must treat
	 * this as a best-effort fallback, not the primary file path.
	 */
	public function get_file_url( $file_id ) {
		$file = $this->call( 'getFile', array( 'file_id' => $file_id ) );
		if ( empty( $file['file_path'] ) ) {
			return false;
		}
		return $this->file_base_url() . "/file/bot{$this->token}/{$file['file_path']}";
	}

	/**
	 * Download raw bytes of a telegram file (subject to the same proxy limitation as get_file_url).
	 * Retries once on failure since this connection is known to be intermittent on some hosts.
	 * Returns a local temp file path on success, false on failure.
	 */
	public function download_file_to_temp( $file_id, $retries = 2 ) {
		$file = $this->call( 'getFile', array( 'file_id' => $file_id ) );
		if ( empty( $file['file_path'] ) ) {
			return false;
		}

		$path = ltrim( $file['file_path'], '/' );
		$configured_base = $this->file_base_url();
		$configured_host = strtolower( (string) wp_parse_url( $configured_base, PHP_URL_HOST ) );
		$is_official_base = 'api.telegram.org' === $configured_host;
		$proxy_enabled = (bool) STI_Settings::get( 'proxy_enabled' );

		/*
		 * JSON Bot API calls may need a proxy, while a Cloudflare Worker/custom
		 * gateway is already the proxy and must be reached directly. The previous
		 * implementation incorrectly sent custom gateways through the configured
		 * proxy too, which commonly breaks binary JPG/ZIP requests.
		 */
		$candidates = array();
		$candidates[] = array(
			'url'   => $configured_base . "/file/bot{$this->token}/{$path}",
			'proxy' => $is_official_base && $proxy_enabled,
			'label' => $is_official_base ? 'Telegram API' : 'API gateway مستقیم',
		);
		if ( ! $is_official_base && $proxy_enabled ) {
			// A few hosts can only reach the public endpoint via their SOCKS/HTTP proxy.
			$candidates[] = array( 'url' => "https://api.telegram.org/file/bot{$this->token}/{$path}", 'proxy' => true, 'label' => 'Telegram API با proxy' );
		}
		$candidates[] = array( 'url' => "https://api.telegram.org/file/bot{$this->token}/{$path}", 'proxy' => false, 'label' => 'Telegram API مستقیم' );

		$attempts = max( 1, $retries + 1 );
		for ( $round = 1; $round <= $attempts; $round++ ) {
			foreach ( $candidates as $candidate ) {
				$result = $this->attempt_download_url( $candidate['url'], $candidate['proxy'], $candidate['label'] );
				if ( $result ) { return $result; }
			}
			if ( $round < $attempts ) { usleep( 500000 ); }
		}
		return false;
	}

	/** Downloads a Telegram binary endpoint to disk; never holds the file in PHP memory. */
	protected function attempt_download_url( $url, $use_proxy, $label = 'Telegram' ) {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->last_error = array( 'stage' => 'download', 'message' => 'اکستنشن cURL در PHP فعال نیست.' );
			return false;
		}
		$tmp = wp_tempnam( 'sti-tg-' );
		$fp = @fopen( $tmp, 'wb' );
		if ( ! $fp ) { $this->last_error = array( 'stage' => 'download', 'message' => 'فایل موقت ساخته نشد.' ); return false; }
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_FILE           => $fp,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => true,
		) );
		if ( $use_proxy ) { $this->maybe_apply_proxy( $ch ); }
		$ok = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$error = curl_error( $ch );
		$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		fclose( $fp );
		$size = STI_Security::safe_file_size( $tmp );
		if ( ! $ok || $errno || $http_code < 200 || $http_code >= 300 || $size < 1 ) {
			@unlink( $tmp );
			$message = $errno ? $this->humanize_curl_error( $errno, $error ) : "دریافت فایل تلگرام ناموفق بود (HTTP {$http_code}).";
			$this->last_error = array( 'stage' => 'download', 'http_code' => $http_code, 'errno' => $errno, 'message' => $label . ': ' . $message );
			STI_Logger::warning( 'Telegram binary download failed via ' . $label . ' [HTTP ' . $http_code . ', cURL ' . $errno . ']: ' . $message );
			return false;
		}
		return $tmp;
	}

}
