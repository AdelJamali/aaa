<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI MTProto — اتصال با اکانت شخصی تلگرام (MadelineProto)
 *
 * ── چرا این کلاس وجود دارد؟ ──────────────────────────────────────────────
 * صفحات وب تلگرام (t.me/s/Username) فقط محتوای کانال‌ها/گروه‌های عمومی را
 * نشان می‌دهند. کانال یا گروهِ خصوصی — مثل @FileechParty — در وب هیچ پیامی
 * منتشر نمی‌کند و حتی صفحه‌ی تک‌پیام (t.me/s/User/123) هم فقط دکمه‌ی «Join»
 * نشان می‌دهد. تنها راهِ خواندن تاریخچه و دانلود فایل از چنین کانال‌هایی،
 * اتصال با خودِ اکانت کاربر از طریق پروتکل MTProto است:
 *
 *     api_id + api_hash (از my.telegram.org) + شماره تلفن + کد ورود یک‌بارمصرف
 *
 * ── این کلاس چه کار می‌کند؟ ──────────────────────────────────────────────
 *  1. موتور MadelineProto را به‌صورت تک‌فایل (Phar) دانلود می‌کند — بدون نیاز
 *     به Composer و بدون وابستگی اضافه؛ نسخه‌ی متناسب با PHP هاست انتخاب می‌شود.
 *  2. سشن اکانت را در wp-content/uploads/sti-mtproto نگه می‌دارد
 *     (پوشه با .htaccess و index.php از دسترسی وب محفوظ است).
 *  3. جریان ورود (کد + رمز دومرحله‌ای) را از پنل مدیریت انجام می‌دهد؛
 *     رمز دومرحله‌ای هرگز ذخیره نمی‌شود.
 *  4. به‌عنوان کاربر: کانال/گروه را resolve می‌کند (حتی کانال خصوصی یا
 *     لینک دعوت t.me/+xxx)، تاریخچه می‌خواند، فایل دانلود می‌کند و دکمه‌های
 *     inline (کالبک) را فشار می‌دهد — دقیقاً همان کاری که کاربر خودش می‌کند.
 *
 * توجه: کلاس‌های MadelineProto فقط وقتی بارگذاری می‌شوند که موتور نصب شده
 * باشد؛ در غیر این صورت افزونه بدون هیچ خطایی به مسیرهای قبلی ادامه می‌دهد.
 */
class STI_MTProto {

	/** @var self|null */
	protected static $instance;

	/** @var \danog\MadelineProto\API|null */
	protected $client = null;

	/** @var string|null آخرین خطای client (برای نمایش در پنل). */
	protected $client_error = null;

	/** @var string نام موقتِ منتظرِ کد ورود. */
	const PENDING_KEY = 'sti_mt_pending';

	/** @var bool هندلر حلقه‌ی رویداد یک‌بار نصب شود. */
	protected static $loop_guard_installed = false;

	/** @var string عمر سشن فعال در حافظه (ثانیه) — برای کش‌کردن client. */
	const CLIENT_TTL = 120;

	protected static function option_ttl() {
		return HOUR_IN_SECONDS;
	}

	/* ======================================================================
	   SINGLETON / SETTINGS
	   ====================================================================== */

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function is_configured() {
		return (bool) STI_Settings::get( 'mtproto_enabled' )
			&& STI_Settings::get( 'mtproto_api_id' )
			&& STI_Settings::get( 'mtproto_api_hash' )
			&& STI_Settings::get( 'mtproto_phone' );
	}

	public static function api_id() {
		return (int) STI_Settings::get( 'mtproto_api_id', 0 );
	}

	public static function api_hash() {
		return trim( (string) STI_Settings::get( 'mtproto_api_hash', '' ) );
	}

	public static function phone() {
		return preg_replace( '/[^0-9+]/', '', (string) STI_Settings::get( 'mtproto_phone', '' ) );
	}

	/* ======================================================================
	   ENGINE (MadelineProto Phar) MANAGEMENT
	   ====================================================================== */

	/**
	 * پوشه‌ی اصلی سشن و موتور.
	 * Prefer a directory outside the public document root. Shared hosts that
	 * do not allow that fallback to uploads, where ensure_dir adds web-server
	 * deny rules for Apache/IIS-compatible setups.
	 */
	public static function base_dir() {
		$uploads = wp_get_upload_dir();
		$public = trailingslashit( $uploads['basedir'] ) . 'sti-mtproto';
		$candidates = array();
		if ( defined( 'STI_MT_DATA_DIR' ) && STI_MT_DATA_DIR ) {
			$candidates[] = untrailingslashit( STI_MT_DATA_DIR );
		}
		$candidates[] = trailingslashit( dirname( ABSPATH ) ) . 'golden-importer-secure/sti-mtproto';
		foreach ( $candidates as $candidate ) {
			if ( is_dir( $candidate ) && is_writable( $candidate ) ) { return untrailingslashit( $candidate ); }
			if ( ! file_exists( $candidate ) && @wp_mkdir_p( $candidate ) && is_writable( $candidate ) ) {
				return untrailingslashit( $candidate );
			}
		}
		return $public;
	}

	/**
	 * مسیر فایل موتور.
	 *
	 * توجه مهم: فایل Phar MadelineProto یک ارجاع داخلی به نام خودش دارد
	 * (phar://madeline81.phar/...) — اگر فایل با نام دیگری ذخیره شود،
	 * بارگذاری‌اش شکست می‌خورد. پس همیشه با نام اصلی (engine_filename) ذخیره می‌شود.
	 */
	public static function phar_path() {
		return self::base_dir() . '/' . self::engine_filename();
	}

	public static function session_path() {
		return self::base_dir() . '/session-' . substr( md5( self::phone() ), 0, 12 ) . '.madeline';
	}

	/** نام فایل Phar متناسب با نسخه‌ی PHP هاست (نسخه‌های ۷.۴ به بالا پشتیبانی می‌شوند). */
	public static function engine_filename() {
		if ( version_compare( PHP_VERSION, '8.1', '>=' ) ) {
			return 'madeline81.phar'; // v9 — آخرین نسخه (به‌روز تا ۲۰۲۶)
		}
		if ( version_compare( PHP_VERSION, '7.4', '>=' ) ) {
			return 'madeline74.phar'; // v8.6.5 — آخرین نسخه‌ی سازگار با PHP 7.4/8.0
		}
		return '';
	}

	public static function engine_supported() {
		return '' !== self::engine_filename();
	}

	public static function engine_installed() {
		return file_exists( self::phar_path() ) && filesize( self::phar_path() ) > 1024 * 1024;
	}

	/**
	 * بارگذاری امن فایل موتور MadelineProto.
	 *
	 * اگر سایت Composer autoloader داشته باشد (بسیاری از افزونه‌ها vendor/autoload.php
	 * را لود می‌کنند)، فایل phar از لود شدن خودداری می‌کند و این خطا را می‌دهد:
	 *   «Composer autoloader detected: madeline.phar is incompatible with Composer…»
	 *
	 * راه‌حل رسمی MadelineProto: تعریف ثابت MADELINE_ALLOW_COMPOSER قبل از require.
	 * هشدارهای deprecation مربوط به composer قدیمی داخل phar هم موقتاً بی‌صدا می‌شوند.
	 */
	protected static function load_engine_phar() {
		if ( ! defined( 'MADELINE_ALLOW_COMPOSER' ) ) {
			define( 'MADELINE_ALLOW_COMPOSER', true );
		}
		$old_level = error_reporting();
		error_reporting( $old_level & ~E_DEPRECATED );
		require_once self::phar_path();
		error_reporting( $old_level );
	}

	/** تست اینکه Phar واقعاً قابل بارگذاری است (بدون نگه‌داشتن کلاس‌ها). */
	public static function engine_healthy() {
		if ( ! self::engine_installed() ) {
			return false;
		}
		try {
			// توجه: MadelineProto کلاس‌ها را lazy-load می‌کند — autoloader تعریف می‌شود
			// ولی خود کلاس API فقط موقع استفاده لود می‌شود. پس باید با autoload چک کنیم.
			if ( ! class_exists( '\danog\MadelineProto\API' ) ) {
				self::load_engine_phar(); // phar شامل autoloader است (سازگار با Composer)
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return class_exists( '\danog\MadelineProto\API' );
	}

	/**
	 * دانلود موتور MadelineProto (تک‌فایل Phar، حدود ۶۰-۸۰ مگابایت).
	 * از همان پراکسی تنظیم‌شده‌ی افزونه استفاده می‌کند تا روی هاست‌های ایران هم جواب بدهد.
	 *
	 * @return true|WP_Error
	 */
	public static function install_engine() {
		if ( ! self::engine_supported() ) {
			return new WP_Error( 'sti_mt_php', 'نسخه‌ی PHP هاست (' . PHP_VERSION . ') برای MadelineProto کافی نیست؛ PHP 7.4 به بالا لازم است.' );
		}

		self::ensure_dir();

		if ( ! wp_is_writable( self::base_dir() ) ) {
			return new WP_Error( 'sti_mt_write', 'پوشه‌ی سشن قابل نوشتن نیست: ' . self::base_dir() . ' — دسترسی پوشه را ۷۷۵/۷۷۷ کنید یا فایل را دستی آپلود کنید.' );
		}

		@set_time_limit( 0 );

		$sources = self::engine_download_sources();
		$tmp     = self::base_dir() . '/' . self::engine_filename() . '.part';
		$errors  = array();
		$proxy   = (bool) STI_Settings::get( 'proxy_enabled' ) && ! empty( STI_Settings::get( 'proxy_host' ) );

		// دور ۱: با پراکسی (اگر تنظیم است) — دور ۲: بدون پراکسی (بعضی هاست‌ها پراکسی خراب دارند).
		for ( $round = 1; $round <= 2; $round++ ) {
			$use_proxy = ( 1 === $round ) && $proxy;
			if ( 2 === $round && ! $proxy ) {
				break; // پراکسی‌ای وجود ندارد — فقط یک دور کافی است
			}

			foreach ( $sources as $label => $url ) {
				$result = self::download_engine_source( $url, $tmp, $use_proxy );

				if ( true === $result ) {
					$size = STI_Security::safe_file_size( $tmp );
					if ( $size < 5 * 1024 * 1024 ) {
						@unlink( $tmp );
						$errors[] = $label . ': فایل ناقص (' . round( $size / 1048576, 1 ) . ' MB)';
						continue;
					}

					@rename( $tmp, self::phar_path() );
					@chmod( self::phar_path(), 0644 );

					if ( ! self::engine_healthy() ) {
						@unlink( self::phar_path() );
						@unlink( $tmp ); // part ناقص — دفعه‌ی بعد از صفر شروع شود
						$errors[] = $label . ': فایل قابل بارگذاری نبود';
						continue;
					}

					STI_Logger::success( 'MTProto: موتور MadelineProto نصب شد از ' . $label . ' (' . round( $size / 1048576, 1 ) . ' MB' . ( $use_proxy ? '، از طریق پراکسی' : '، مستقیم' ) . ').' );
					return true;
				}

				$errors[] = $label . ( $use_proxy ? ' (پراکسی)' : ' (مستقیم)' ) . ': ' . $result;
			}
		}

		// اگر همه شکست خوردند، فایل ناقص را پاک نکن (برای دانلود دستی بعدی) ولی پیام کامل بده
		$error_msg = 'دانلود موتور از هیچ منبعی ممکن نشد. جزئیات: ' . implode( ' | ', array_slice( $errors, 0, 6 ) );
		STI_Logger::error( 'MTProto: ' . $error_msg );
		return new WP_Error(
			'sti_mt_download',
			$error_msg .
			' — راه‌حل: فایل ' . self::engine_filename() . ' را از همین صفحه (یا phar.madelineproto.xyz) دانلود و با همان نام در پوشه‌ی wp-content/uploads/sti-mtproto/ آپلود کنید.'
		);
	}

	/**
	 * منابع دانلود موتور MadelineProto — رسمی + چند آینه‌ی GitHub که در ایران
	 * معمولاً بدون تحریم در دسترس هستند.
	 *
	 * @return array  label => url
	 */
	public static function engine_download_sources() {
		$file = self::engine_filename();

		return array(
			'GitHub رسمی'   => 'https://github.com/danog/MadelineProto/releases/latest/download/' . $file,
			'phar.madelineproto.xyz' => 'https://phar.madelineproto.xyz/' . $file,
			'آینه ghfast.top' => 'https://ghfast.top/https://github.com/danog/MadelineProto/releases/latest/download/' . $file,
			'آینه gh.llkk.cc' => 'https://gh.llkk.cc/https://github.com/danog/MadelineProto/releases/latest/download/' . $file,
			'آینه gh-proxy.com' => 'https://gh-proxy.com/https://github.com/danog/MadelineProto/releases/latest/download/' . $file,
		);
	}

	/**
	 * دانلود یک منبع به فایل part — با قابلیت ادامه از جایی که قطع شده (resume).
	 *
	 * @param string $url
	 * @param string $tmp
	 * @param bool   $use_proxy
	 * @return true|string  true=موفق، string=پیام خطا
	 */
	protected static function download_engine_source( $url, $tmp, $use_proxy ) {
		$resume = STI_Security::safe_file_size( $tmp ); // اگر دانلود قبلی ناقص مانده، از همان‌جا ادامه بده

		$fp = @fopen( $tmp, 'ab' );
		if ( ! $fp ) {
			return 'پوشه‌ی سشن قابل نوشتن نیست: ' . self::base_dir();
		}

		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_FILE           => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 6,
			CURLOPT_CONNECTTIMEOUT => 25,
			CURLOPT_TIMEOUT        => 900,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; GoldenImporter/' . STI_VERSION . ')',
		) );

		if ( $resume > 0 ) {
			curl_setopt( $ch, CURLOPT_RESUME_FROM, $resume );
		}
		if ( $use_proxy ) {
			self::apply_proxy_to_curl( $ch );
		}

		$ok      = curl_exec( $ch );
		$errno   = (int) curl_errno( $ch );
		$error   = trim( (string) curl_error( $ch ) );
		$code    = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$size    = STI_Security::safe_file_size( $tmp );
		curl_close( $ch );
		fclose( $fp );

		// خطای شبکه — فایل part نگه داشته می‌شود تا دفعه‌ی بعد ادامه پیدا کند
		if ( ! $ok || $errno ) {
			return 'cURL ' . $errno . ( $error ? ': ' . $error : '' ) . ' (تا ' . round( $size / 1048576, 1 ) . ' MB دانلود شد)';
		}

		// 416 = درخواست ادامه از انتهای فایل — یعنی فایل part قبلاً کامل دانلود شده است
		if ( 416 === $code && $size >= 5 * 1024 * 1024 ) {
			return true;
		}

		// اگر resume درخواست شده بود ولی سرور Range پشتیبانی نکرد (200 کامل)، فایل خراب است
		if ( $resume > 0 && 206 !== $code && $code >= 200 && $code < 300 ) {
			@unlink( $tmp );
			return 'سرور ادامه‌ی دانلود را پشتیبانی نکرد — از اول';
		}

		// resume با 206 ولی 0 بایت اضافه (مثلاً ادامه از انتهای فایل ناقص) → فایل هنوز ناقص است
		if ( $resume > 0 && 206 === $code && $size < 5 * 1024 * 1024 ) {
			@unlink( $tmp );
			return 'فایل هنوز ناقص است (' . round( $size / 1048576, 1 ) . ' MB) — از اول';
		}

		if ( $code < 200 || $code >= 300 ) {
			@unlink( $tmp );
			return 'HTTP ' . $code;
		}

		return true;
	}

	/**
	 * پراکسی تنظیم‌شده در افزونه را روی cURL اعمال می‌کند (برای دانلود موتور).
	 *
	 * @param resource $ch
	 * @return bool  آیا پراکسی اعمال شد؟
	 */
	protected static function apply_proxy_to_curl( $ch ) {
		if ( ! STI_Settings::get( 'proxy_enabled' ) || empty( STI_Settings::get( 'proxy_host' ) ) ) {
			return false;
		}
		$host = STI_Settings::get( 'proxy_host' );
		$port = (int) STI_Settings::get( 'proxy_port', 1080 );
		$type = STI_Settings::get( 'proxy_type', 'http' );

		curl_setopt( $ch, CURLOPT_PROXY, $host );
		if ( $port > 0 ) {
			curl_setopt( $ch, CURLOPT_PROXYPORT, $port );
		}
		$map = array(
			'http'    => CURLPROXY_HTTP,
			'socks5'  => CURLPROXY_SOCKS5,
			'socks5h' => CURLPROXY_SOCKS5_HOSTNAME,
			'socks4'  => CURLPROXY_SOCKS4,
		);
		curl_setopt( $ch, CURLOPT_PROXYTYPE, isset( $map[ $type ] ) ? $map[ $type ] : CURLPROXY_HTTP );

		// برای پراکسی HTTP، از CONNECT tunneling استفاده کن تا ریدایرکت‌ها (GitHub) هم از پراکسی بروند
		if ( 'http' === $type ) {
			curl_setopt( $ch, CURLOPT_HTTPPROXYTUNNEL, true );
		}

		$user = STI_Settings::get( 'proxy_user' );
		$pass = STI_Settings::get( 'proxy_pass' );
		if ( ! empty( $user ) ) {
			curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $user . ':' . $pass );
		}
		return true;
	}

	/** ساخت پوشه‌ی سشن + محافظت از دسترسی وب. */
	public static function ensure_dir() {
		$dir = self::base_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// One-time migration from the old public uploads location.
		$uploads = wp_get_upload_dir();
		$old_dir = trailingslashit( $uploads['basedir'] ) . 'sti-mtproto';
		if ( untrailingslashit( $old_dir ) !== untrailingslashit( $dir ) && is_dir( $old_dir ) ) {
			foreach ( glob( $old_dir . '/session-*' ) ?: array() as $old_file ) {
				$target = trailingslashit( $dir ) . basename( $old_file );
				if ( ! file_exists( $target ) ) { @copy( $old_file, $target ); }
			}
			$old_phar = $old_dir . '/' . self::engine_filename();
			$new_phar = trailingslashit( $dir ) . self::engine_filename();
			if ( file_exists( $old_phar ) && ! file_exists( $new_phar ) ) { @copy( $old_phar, $new_phar ); }
		}
		@file_put_contents( $dir . '/index.php', "<?php // silence is golden\n" );
		@file_put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all\n" );
		@file_put_contents( $dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted=\"true\"><add fileExtension=\".madeline\" allowed=\"false\"/></fileExtensions></requestFiltering></security></system.webServer></configuration>" );
		return is_dir( $dir );
	}

	/* ======================================================================
	   CLIENT FACTORY
	   ====================================================================== */

	/**
	 * ساخت/کش کردن نمونه‌ی MadelineProto.
	 *
	 * @return \danog\MadelineProto\API|WP_Error
	 */
	/**
	 * مهار خطاهای حلقه‌ی رویداد (Revolt/Amp).
	 * روی هاست‌های اشتراکی، PHP-FPM هنگام پایان درخواست SIGTERM می‌فرستد و
	 * MadelineProto آن را به Amp\SignalException تبدیل می‌کند که کل چانک ایمپورت را
	 * می‌کشت. اینجا هندلر سراسری می‌گذاریم تا فقط لاگ شود و روند نمیرد.
	 */
	protected static function install_loop_guard() {
		if ( self::$loop_guard_installed ) { return; }
		self::$loop_guard_installed = true;
		if ( ! class_exists( '\Revolt\EventLoop' ) ) { return; }
		try {
			\Revolt\EventLoop::setErrorHandler( function ( $e ) {
				$msg = is_object( $e ) && method_exists( $e, 'getMessage' ) ? $e->getMessage() : (string) $e;
				if ( false !== stripos( $msg, 'SIGTERM' ) || false !== stripos( $msg, 'SignalException' ) ) {
					return; // پایان طبیعی درخواست — نادیده بگیر
				}
				if ( class_exists( 'STI_Logger' ) ) {
					STI_Logger::warning( 'MTProto loop: ' . mb_substr( $msg, 0, 240 ) );
				}
			} );
		} catch ( \Throwable $e ) {
			// نسخه‌ای که setErrorHandler ندارد — بی‌خیال
		}
	}

	/**
	 * دانلود آبشاری — رفع خطای «The endpoint does not exist!».
	 * نسخه‌های مختلف MadelineProto نام متد دانلود را عوض کرده‌اند، و بعضی
	 * ساخت‌ها فقط downloadToDir یا getDownloadInfo دارند. همه را به ترتیب
	 * امتحان می‌کنیم تا یکی جواب دهد.
	 */
	protected function download_cascade( $mad, $raw, $dest, $dest_dir ) {
		$errors = array();

		foreach ( array( 'downloadToFile', 'downloadToDir' ) as $method ) {
			try {
				if ( ! method_exists( $mad, $method ) ) { continue; }
				$path = ( 'downloadToFile' === $method )
					? $mad->downloadToFile( $raw, $dest )
					: $mad->downloadToDir( $raw, $dest_dir );
				if ( $path && @is_file( $path ) && STI_Security::safe_file_size( $path ) > 0 ) { return $path; }
				$errors[] = $method . ': فایل خالی';
			} catch ( \Throwable $e ) {
				$errors[] = $method . ': ' . $e->getMessage();
			}
		}

		/* آخرین راه: گرفتن اطلاعات دانلود و نوشتن دستی */
		try {
			if ( method_exists( $mad, 'downloadToStream' ) ) {
				$fh = fopen( $dest, 'wb' );
				if ( $fh ) {
					$mad->downloadToStream( $raw, $fh );
					fclose( $fh );
					if ( @is_file( $dest ) && STI_Security::safe_file_size( $dest ) > 0 ) { return $dest; }
				}
			}
		} catch ( \Throwable $e ) {
			$errors[] = 'downloadToStream: ' . $e->getMessage();
		}

		throw new \RuntimeException( implode( ' | ', array_slice( $errors, 0, 3 ) ) );
	}

	public function client() {
		if ( $this->client instanceof \danog\MadelineProto\API ) {
			return $this->client;
		}
		if ( ! self::is_configured() ) {
			return new WP_Error( 'sti_mt_not_configured', 'اکانت تلگرام (api_id / api_hash / شماره) تنظیم نشده یا غیرفعال است.' );
		}
		if ( ! self::engine_installed() ) {
			return new WP_Error( 'sti_mt_no_engine', 'موتور MadelineProto نصب نیست. اول از صفحه‌ی «تنظیمات تلگرام» نصبش کنید.' );
		}
		if ( ! class_exists( '\danog\MadelineProto\API' ) ) {
			try {
				self::load_engine_phar();
			} catch ( \Throwable $e ) {
				$this->client_error = $e->getMessage();
				return new WP_Error( 'sti_mt_engine', 'بارگذاری موتور ناموفق: ' . $e->getMessage() );
			}
		}
		if ( ! class_exists( '\danog\MadelineProto\API' ) ) {
			return new WP_Error( 'sti_mt_engine', 'کلاس‌های MadelineProto بارگذاری نشدند.' );
		}

		self::install_loop_guard();

		// روی هاست‌های اشتراکی memory_limit اغلب ۱۲۸M است که برای MadelineProto
		// (همراه وردپرس) کافی نیست — سعی کن بالا ببری (اگر هاست اجازه دهد).
		$current_limit = (int) ini_get( 'memory_limit' );
		if ( $current_limit > 0 && $current_limit < 512 ) {
			@ini_set( 'memory_limit', '512M' );
		}

		self::ensure_dir();
		self::harden_runtime();

		$candidates = $this->build_settings_candidates();

		$last_error = null;
		foreach ( $candidates as $settings ) {
			try {
				$mad = new \danog\MadelineProto\API( self::session_path(), $settings );
				$this->client = $mad;
				$this->client_error = null;
				return $mad;
			} catch ( \Throwable $e ) {
				$last_error = $e->getMessage();
			}
		}

		$this->client_error = $last_error;
		STI_Logger::error( 'MTProto: ساخت client ناموفق — ' . $last_error );
		return new WP_Error( 'sti_mt_client', 'ساخت client ناموفق: ' . $last_error );
	}

	/**
	 * ساخت آبجکت Settings مخصوص MadelineProto v8/v9 (کلاس‌ها به‌جای آرایه).
	 * اگر سایت بدون Composer باشد و نسخه‌ی phar قدیمی‌تر (v7) باشد،
	 * فرمت آرایه‌ای قبلی هم به‌عنوان fallback امتحان می‌شود.
	 *
	 * @return array  لیست کاندیداها: هر کدام یا آبجکت Settings یا آرایه (برای v7).
	 */
	protected function build_settings_candidates() {
		// ── روش مدرن: آبجکت Settings (MadelineProto v8/v9) ──
		if ( class_exists( '\\danog\\MadelineProto\\Settings' ) ) {
			$candidates = array();

			$settings = new \danog\MadelineProto\Settings();
			$app_info = new \danog\MadelineProto\Settings\AppInfo();
			$app_info->setApiId( self::api_id() );
			$app_info->setApiHash( self::api_hash() );
			$settings->setAppInfo( $app_info );

			$logger = new \danog\MadelineProto\Settings\Logger();
			if ( method_exists( $logger, 'setType' ) ) {
				// مهم: LOGGER_FILE نه LOGGER_ECHO — خروجی کنسول JSON پاسخ AJAX را خراب می‌کند
				$logger->setType( \danog\MadelineProto\Logger::LOGGER_FILE );
			}
			if ( method_exists( $logger, 'setExtra' ) ) {
				$logger->setExtra( self::base_dir() . '/madeline.log' );
			}
			if ( method_exists( $logger, 'setLevel' ) ) {
				$logger->setLevel( \danog\MadelineProto\Logger::LEVEL_WARNING );
			} elseif ( method_exists( $logger, 'setLoggerLevel' ) ) {
				$logger->setLoggerLevel( 3 );
			}
			$settings->setLogger( $logger );

			// اول بدون پراکسی (ساده‌ترین حالت — خیلی از هاست‌ها مستقیم وصل می‌شوند)
			$candidates[] = $settings;

			// اگر پراکسی تنظیم شده بود، نسخه‌ی با پراکسی را بعد از آن بگذار
			if ( STI_Settings::get( 'proxy_enabled' ) && ! empty( STI_Settings::get( 'proxy_host' ) ) ) {
				$proxied = $this->apply_proxy_settings( clone $settings );
				if ( $proxied ) {
					$candidates[] = $proxied;
				}
			}

			return $candidates;
		}

		// ── روش قدیمی: آرایه (MadelineProto v6/v7) ──
		$base = array(
			'app_info' => array(
				'api_id'  => self::api_id(),
				'api_hash'=> self::api_hash(),
			),
			'logger'   => array(
				'logger_level' => 3, // فقط هشدارها و خطاها
			),
		);
		if ( STI_Settings::get( 'proxy_enabled' ) && ! empty( STI_Settings::get( 'proxy_host' ) ) ) {
			$proxy = array(
				'address'  => STI_Settings::get( 'proxy_host' ),
				'port'     => (int) STI_Settings::get( 'proxy_port', 1080 ),
				'username' => (string) STI_Settings::get( 'proxy_user', '' ),
				'password' => (string) STI_Settings::get( 'proxy_pass', '' ),
			);
			$proxy['type'] = ( 'http' === STI_Settings::get( 'proxy_type', 'socks5h' ) ) ? 'http' : 'socks5';

			$c1 = $base;
			$c1['connection']['proxy'] = $proxy;
			$candidates[] = $c1;

			$c2 = $base;
			$c2['connection_settings']['all']['proxy'] = $proxy;
			$candidates[] = $c2;
		}
		$candidates[] = $base;

		return $candidates;
	}

	/**
	 * اعمال پراکسی روی Settings نسخه‌ی v9 — فرمت رسمی:
	 *   SocksProxy::class  → ['address'=>…, 'port'=>…, 'username'=>…, 'password'=>…]
	 *   HttpProxy::class   → ['url'=>…, 'username'=>…, 'password'=>…]
	 *
	 * @param \danog\MadelineProto\Settings $settings
	 * @return \danog\MadelineProto\Settings|null
	 */
	protected function apply_proxy_settings( $settings ) {
		if ( ! is_object( $settings ) || ! method_exists( $settings, 'getConnection' ) ) {
			return null;
		}

		try {
			$connection = $settings->getConnection();

			$raw_type = STI_Settings::get( 'proxy_type', 'socks5h' );
			$host     = (string) STI_Settings::get( 'proxy_host' );
			$port     = (int) STI_Settings::get( 'proxy_port', 1080 );
			$user     = (string) STI_Settings::get( 'proxy_user', '' );
			$pass     = (string) STI_Settings::get( 'proxy_pass', '' );

			if ( 'http' === $raw_type && class_exists( '\\danog\\MadelineProto\\Stream\\Proxy\\HttpProxy' ) ) {
				$extra = array( 'url' => 'http://' . $host . ':' . $port );
				if ( $user ) { $extra['username'] = $user; }
				if ( $pass ) { $extra['password'] = $pass; }

				if ( method_exists( $connection, 'addProxy' ) ) {
					$connection->addProxy( \danog\MadelineProto\Stream\Proxy\HttpProxy::class, $extra );
				} elseif ( method_exists( $connection, 'setProxies' ) ) {
					$connection->setProxies( array( \danog\MadelineProto\Stream\Proxy\HttpProxy::class => array( $extra ) ) );
				}
			} elseif ( class_exists( '\\danog\MadelineProto\\Stream\\Proxy\\SocksProxy' ) ) {
				$extra = array(
					'address' => $host,
					'port'    => $port,
				);
				if ( $user ) { $extra['username'] = $user; }
				if ( $pass ) { $extra['password'] = $pass; }

				if ( method_exists( $connection, 'addProxy' ) ) {
					$connection->addProxy( \danog\MadelineProto\Stream\Proxy\SocksProxy::class, $extra );
				} elseif ( method_exists( $connection, 'setProxies' ) ) {
					$connection->setProxies( array( \danog\MadelineProto\Stream\Proxy\SocksProxy::class => array( $extra ) ) );
				}
			}

			return $settings;
		} catch ( \Throwable $e ) {
			STI_Logger::warning( 'MTProto: اعمال پراکسی ناموفق — ' . $e->getMessage() );
			return null;
		}
	}

	/* ======================================================================
	   AUTH FLOW (ورود با اکانت شخصی)
	   ====================================================================== */

	/**
	 * وضعیت ورود اکانت.
	 *
	 * برای اینکه موتور سنگین MadelineProto (~۷۰MB) در هر بازدید صفحه‌ی ادمین
	 * بارگذاری نشود، نتیجه به مدت کوتاهی در option کش می‌شود.
	 *
	 * @return string  'logged_in' | 'awaiting_code' | 'not_logged' | 'error'
	 */
	public function auth_state() {
		if ( ! self::is_configured() ) {
			return 'not_logged';
		}

		$cache_key = 'sti_mt_state_' . substr( md5( self::phone() ), 0, 10 );
		$cache     = get_option( $cache_key, array() );
		if ( ! empty( $cache['state'] ) && ( time() - (int) ( $cache['ts'] ?? 0 ) ) < 90 ) {
			return $cache['state'];
		}

		$state = $this->auth_state_real();

		update_option( $cache_key, array( 'state' => $state, 'ts' => time() ), false );
		return $state;
	}

	/** پاک کردن کش وضعیت (بعد از ورود/خروج). */
	public function clear_state_cache() {
		if ( ! self::is_configured() ) {
			return;
		}
		delete_option( 'sti_mt_state_' . substr( md5( self::phone() ), 0, 10 ) );
	}

	/** محاسبه‌ی واقعی وضعیت (بدون کش) — بارگذاری موتور در همین‌جا انجام می‌شود. */
	protected function auth_state_real() {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			$msg = strtolower( $mad->get_error_message() );
			// اگر client ساخته نشد ولی سشن فیزیکی وجود دارد، ممکن است صرفاً نیاز به کد باشد.
			if ( file_exists( self::session_path() ) && ( strpos( $msg, 'code' ) !== false || strpos( $msg, 'login' ) !== false ) ) {
				return 'awaiting_code';
			}
			return 'not_logged';
		}

		try {
			// v8/v9: getAuthorization() یک عدد (ثابت) برمی‌گرداند — بدون هیچ عارضه‌ی جانبی.
			if ( method_exists( $mad, 'getAuthorization' ) ) {
				$state = $mad->getAuthorization();
				if ( is_int( $state ) ) {
					if ( \danog\MadelineProto\API::LOGGED_IN === $state ) {
						return 'logged_in';
					}
					if ( \danog\MadelineProto\API::WAITING_CODE === $state || \danog\MadelineProto\API::WAITING_PASSWORD === $state ) {
						return 'awaiting_code';
					}
					return 'not_logged';
				}
				// نسخه‌های قدیمی‌تر (v7) رشته برمی‌گردانند
				$state = (string) $state;
				if ( 'loggedIn' === $state ) { return 'logged_in'; }
				if ( 'waitingCode' === $state || 'waitingPassword' === $state ) { return 'awaiting_code'; }
				return 'not_logged';
			}

			// v7 بدون getAuthorization: وضعیت را از روی فایل سشن حدس بزن (بدون start!)
			if ( file_exists( self::session_path() ) ) {
				return 'awaiting_code';
			}
		} catch ( \Throwable $e ) {
			STI_Logger::warning( 'MTProto: خطا در بررسی وضعیت — ' . $e->getMessage() );
		}

		// توجه: start() هرگز در محیط وب صدا زده نمی‌شود — در وب فرم HTML چاپ می‌کند و JSON را خراب می‌کند.
		return 'not_logged';
	}

	/** اطلاعات اکانت لاگین‌شده (نام/یوزرنیم/آیدی). */
	public function account_info() {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return null;
		}
		try {
			$self = $mad->get_self();
			return array(
				'name'     => trim( ( $self['first_name'] ?? '' ) . ' ' . ( $self['last_name'] ?? '' ) ),
				'username' => $self['username'] ?? '',
				'id'       => $self['id'] ?? 0,
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * ارسال کد ورود به شماره‌ی تنظیم‌شده.
	 * phone_code_hash در transient ذخیره می‌شود تا ورود حتی اگر state سشن
	 * بین دو درخواست از بین رفته باشد قابل انجام باشد.
	 *
	 * @return true|WP_Error
	 */
	public function send_code() {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'sti_mt_not_configured', 'ابتدا api_id و api_hash و شماره تلفن را ذخیره کنید.' );
		}

		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			self::stop_client();
			return $mad;
		}

		// اگر قبلاً لاگین است کاری نکن
		try {
			$state = $mad->getAuthorization();
			if ( is_int( $state ) && \danog\MadelineProto\API::LOGGED_IN === $state ) {
				return true;
			}
		} catch ( \Throwable $e ) {
			// بی‌خیال — phoneLogin خودش خطا را می‌دهد
		}

		try {
			$auth = $mad->phoneLogin( self::phone() );
		} catch ( \Throwable $e ) {
			$msg = $e->getMessage();
			if ( false !== stripos( $msg, 'already logged' ) ) {
				return true; // از قبل لاگین است
			}
			return new WP_Error( 'sti_mt_sendcode', 'ارسال کد ناموفق: ' . $this->friendly_rpc_error( $msg ) );
		}

		// ── منبع حقیقت ما برای تکمیل ورود (مستقل از سشن) ──
		$login_data = array(
			'phone'           => self::phone(),
			'phone_code_hash' => (string) ( $auth['phone_code_hash'] ?? '' ),
			'ts'              => time(),
		);
		set_transient( 'sti_mt_login', $login_data, 15 * MINUTE_IN_SECONDS );
		set_transient( self::PENDING_KEY, array( 'phone' => self::phone(), 'ts' => time() ), 10 * MINUTE_IN_SECONDS );

		// توجه: serialize اجباری روی wrapper در MadelineProto v9 پروسس را می‌کشد (exit) —
		// به آن دست نمی‌زنیم؛ ذخیره‌ی سشن در shutdown طبیعی انجام می‌شود و
		// phone_code_hash در ترنزینت به‌عنوان پشتیبان برای تکمیل ورود کافی است.

		$this->clear_state_cache();

		return true;
	}



	/**
	 * تکمیل ورود با کد دریافتی (و در صورت نیاز رمز دومرحله‌ای).
	 *
	 * @param string $code      کد دریافتی در تلگرام.
	 * @param string $password  رمز دومرحله‌ای (اختیاری).
	 * @return true|WP_Error
	 */
	public function complete_login( $code, $password = '' ) {
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return new WP_Error( 'sti_mt_code', 'کد ورود را وارد کنید.' );
		}

		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}

		$login = get_transient( 'sti_mt_login' );
		$login = is_array( $login ) ? $login : array();

		/* ── مسیر اصلی: completePhoneLogin ── */
		try {
			$authorization = $mad->completePhoneLogin( $code );
		} catch ( \Throwable $e ) {
			$msg = $e->getMessage();

			// اگر state سشن بین دو درخواست از بین رفته بود، سعی کن آن را بازسازی کنی
			if ( false !== stripos( $msg, 'waiting for the code' ) ) {
				$rehydrated = $this->rehydrate_login_state( $mad, $login );
				if ( $rehydrated ) {
					try {
						$authorization = $mad->completePhoneLogin( $code );
					} catch ( \Throwable $e2 ) {
						return new WP_Error( 'sti_mt_code', 'ورود ناموفق: ' . $this->friendly_rpc_error( $e2->getMessage() ) );
					}
				} else {
					return new WP_Error(
						'sti_mt_code',
						'وضعیت ورود بین دو درخواست از بین رفته است. دوباره «ارسال کد ورود» را بزنید و بلافاصله کد جدید را وارد کنید. (' . $msg . ')'
					);
				}
			} else {
				return new WP_Error( 'sti_mt_code', 'خطا در تکمیل ورود: ' . $this->friendly_rpc_error( $msg ) );
			}
		}

		/* ── 2FA (رمز دومرحله‌ای) ── */
		if ( is_array( $authorization ) && ( $authorization['_'] ?? '' ) === 'account.password' ) {
			if ( '' === $password ) {
				// state را برای مرحله‌ی بعد نگه دار
				set_transient( 'sti_mt_login', array_merge( $login, array( 'awaiting_password' => 1, 'ts' => time() ) ), 15 * MINUTE_IN_SECONDS );
				return new WP_Error( 'sti_mt_2fa', 'ورود دومرحله‌ای فعال است؛ رمز عبور را وارد کنید.' );
			}
			try {
				$authorization = $mad->complete2faLogin( $password );
			} catch ( \Throwable $e ) {
				// اگر state باز هم از بین رفته بود، با PasswordCalculator مستقیم
				try {
					if ( class_exists( '\\danog\\MadelineProto\\MTProtoTools\\PasswordCalculator' ) ) {
						$calc = new \danog\MadelineProto\MTProtoTools\PasswordCalculator( $authorization );
						$authorization = $mad->auth->checkPassword( array( 'password' => $calc->getCheckPassword( $password ) ) );
					} else {
						throw $e;
					}
				} catch ( \Throwable $e2 ) {
					return new WP_Error( 'sti_mt_2fa', 'خطا در ورود با رمز: ' . $this->friendly_rpc_error( $e2->getMessage() ) );
				}
			}
		}

		/* ── ثبت‌نام لازم است (شماره بدون اکانت تلگرام) ── */
		if ( is_array( $authorization ) && ( $authorization['_'] ?? '' ) === 'account.needSignup' ) {
			return new WP_Error( 'sti_mt_signup', 'این شماره در تلگرام ثبت‌نام نیست؛ از یک شماره‌ی معتبر استفاده کنید.' );
		}

		/* ── موفق ── */
		delete_transient( self::PENDING_KEY );
		delete_transient( 'sti_mt_login' );
		$this->client = null; // مجبور به ساخت مجدد با سشن کامل
		$this->clear_state_cache();

		STI_Logger::success( 'MTProto: ورود با اکانت شخصی موفق شد.' );
		return true;
	}

	/**
	 * بازسازی state ورود روی نمونه‌ی MadelineProto وقتی سشن بین دو درخواست
	 * state خود را از دست داده باشد (مشکل رایج روی هاست‌های اشتراکی).
	 * phone_code_hash از transient خوانده می‌شود — نیازی به ارسال کد جدید نیست.
	 *
	 * @param object $mad
	 * @param array  $login
	 * @return bool
	 */
	protected function rehydrate_login_state( $mad, $login ) {
		if ( empty( $login['phone'] ) || empty( $login['phone_code_hash'] ) ) {
			return false;
		}
		try {
			$ref   = new \ReflectionProperty( $mad, 'wrapper' );
			$wrap  = $ref->getValue( $mad );
			$inner = $wrap;

			if ( is_object( $wrap ) && method_exists( $wrap, 'getAPI' ) ) {
				$inner = $wrap->getAPI();
			}
			if ( ! is_object( $inner ) || ! method_exists( $inner, 'setLoginState' ) ) {
				return false;
			}

			$inner->setLoginState( \danog\MadelineProto\API::WAITING_CODE );
			$inner->authorization = array(
				'phone_number'    => (string) $login['phone'],
				'phone_code_hash' => (string) $login['phone_code_hash'],
			);

			STI_Logger::info( 'MTProto: state ورود بازسازی شد (rehydrate) — phone=' . $login['phone'] );
			return true;
		} catch ( \Throwable $e ) {
			STI_Logger::warning( 'MTProto: rehydrate ناموفق — ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * تبدیل خطاهای RPC تلگرام به پیام‌های فارسی قابل فهم.
	 *
	 * @param string $raw
	 * @return string
	 */
	protected function friendly_rpc_error( $raw ) {
		$raw = trim( (string) $raw );
		$map = array(
			'PHONE_CODE_INVALID'      => 'کد واردشده اشتباه است.',
			'PHONE_CODE_EXPIRED'      => 'کد منقضی شده است. دوباره «ارسال کد ورود» را بزنید.',
			'PHONE_NUMBER_UNOCCUPIED' => 'این شماره در تلگرام ثبت‌نام نیست.',
			'PHONE_NUMBER_INVALID'    => 'شماره تلفن نامعتبر است. با کد کشور و بدون صفر اول وارد کنید (مثلاً +98912xxxxxxx).',
			'PHONE_NUMBER_FLOOD'      => 'تلگرام محدودیت موقت گذاشته؛ چند دقیقه صبر کنید و دوباره تلاش کنید.',
			'PHONE_CODE_HASH_EMPTY'   => 'کد قبلی منقضی شده؛ دوباره «ارسال کد ورود» را بزنید.',
			'API_ID_INVALID'          => 'api_id یا api_hash اشتباه است. از my.telegram.org دوباره بگیرید.',
			'API_ID_PUBLISHED_FLOOD'  => 'api_id شما بیش از حد استفاده شده؛ یک api_id جدید از my.telegram.org بسازید.',
			'AUTH_KEY_UNREGISTERED'   => 'نشست معتبر نیست؛ دوباره «ارسال کد ورود» را بزنید.',
			'NETWORK_ERROR'           => 'خطای شبکه در اتصال به تلگرام؛ پراکسی/اینترنت هاست را بررسی کنید.',
			'FLOOD_WAIT'              => 'تلگرام محدودیت موقت گذاشته (Flood)؛ چند دقیقه بعد دوباره تلاش کنید.',
			'PASSWORD_HASH_INVALID'   => 'رمز دومرحله‌ای اشتباه است.',
			'SESSION_PASSWORD_NEEDED' => 'ورود دومرحله‌ای فعال است.',
			'CONNECTION_NOT_INITED'   => 'اتصال به تلگرام برقرار نشد؛ دوباره تلاش کنید.',
			'USERNAME_NOT_OCCUPIED'   => 'این یوزرنیم در تلگرام پیدا نشد (شاید اشتباه تایپ شده یا حذف شده).',
			'USERNAME_INVALID'        => 'فرمت یوزرنیم نامعتبر است.',
			'CHANNEL_PRIVATE'         => 'کانال خصوصی است؛ با اکانت شخصی باید عضو آن باشید.',
			'CHANNEL_INVALID'         => 'کانال معتبر نیست یا دسترسی ندارید.',
			'CHAT_ADMIN_REQUIRED'     => 'برای این عملیات ادمین بودن لازم است.',
			'PEER_ID_INVALID'         => 'شناسه/یوزرنیم کانال نامعتبر است.',
			'INPUT_USER_DEACTIVATED'  => 'کاربر/کانال غیرفعال شده است.',
			'AUTH_KEY_UNREGISTERED'   => 'نشست معتبر نیست؛ دوباره «ارسال کد ورود» را بزنید.',
			'NOT_FOUND'               => 'فایل در تلگرام یافت نشد (ممکن است منقضی شده باشد) — با رفرش خودکار پیام دوباره تلاش شد.',
			'FILE_REFERENCE_EXPIRED'  => 'ارجاع فایل منقضی شده — با رفرش پیام دوباره تلاش شد.',
			'FILE_PART_MISSING'       => 'بخشی از فایل در تلگرام موجود نیست — دوباره تلاش شد.',
		);
		foreach ( $map as $key => $fa ) {
			if ( false !== stripos( $raw, $key ) ) {
				return $fa;
			}
		}
		// بریدن پیام‌های طولانی
		if ( mb_strlen( $raw ) > 160 ) {
			$raw = mb_substr( $raw, 0, 160 ) . '…';
		}
		return $raw;
	}

	/** خروج از اکانت و حذف سشن محلی. */
	public function logout() {
		$mad = $this->client();
		if ( ! is_wp_error( $mad ) ) {
			try {
				$mad->logout();
			} catch ( \Throwable $e ) {
				// بی‌خیال — سشن را هم حذف می‌کنیم
			}
		}
		$this->client = null;
		$file = self::session_path();
		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
		foreach ( glob( self::base_dir() . '/session-*.madeline.lock' ) ?: array() as $lock ) {
			@unlink( $lock );
		}
		delete_transient( self::PENDING_KEY );
		$this->clear_state_cache();
		return true;
	}

	/* ======================================================================
	   CHANNEL / GROUP OPERATIONS
	   ====================================================================== */

	/**
	 * اطلاعات کانال/گروه/کاربر — حتی کانال خصوصی که کاربر عضو آن است.
	 * اگر ورودی لینک دعوت (t.me/+xxx) باشد اول با همان اکانت join می‌شود.
	 *
	 * @param string $identifier  username | @username | لینک t.me | لینک دعوت t.me/+
	 * @return array|WP_Error  ['id','type','title','username','members','private','about','join_link']
	 */
	public function chat_info( $identifier ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}

		$identifier = trim( (string) $identifier );

		// لینک دعوت خصوصی
		if ( preg_match( '#(?:^|/)\+([A-Za-z0-9_\-]{5,})#', $identifier, $m ) ) {
			try {
				$imported = $mad->messages->importChatInvite( array( 'hash' => $m[1] ) );
				$chat_id  = $imported['chats'][0]['id'] ?? 0;
				if ( $chat_id ) {
					$identifier = (int) $chat_id;
					STI_Logger::info( 'MTProto: لینک دعوت استفاده شد — chat_id=' . $chat_id );
				} else {
					return new WP_Error( 'sti_mt_invite', 'پیوستن با لینک دعوت ناموفق بود (شاید قبلاً عضو هستید یا لینک منقضی شده).' );
				}
			} catch ( \Throwable $e ) {
				// اگر قبلاً عضو است، resolve از روی id ممکن نیست؛ سعی می‌کنیم با خود لینک کاری نکنیم.
				return new WP_Error( 'sti_mt_invite', 'پیوستن با لینک دعوت ناموفق: ' . $e->getMessage() );
			}
		} else {
			// نرمال‌سازی ورودی به username یا id
			$identifier = self::normalize_identifier( $identifier );
		}

		$last_error = null;
		try {
			// v9: getPwrChat — v6/v7: get_pwr_chat
			if ( method_exists( $mad, 'getPwrChat' ) ) {
				$info = $mad->getPwrChat( $identifier, false );
			} elseif ( method_exists( $mad, 'get_pwr_chat' ) ) {
				$info = $mad->get_pwr_chat( $identifier );
			} elseif ( method_exists( $mad, 'getInfo' ) ) {
				$info = $mad->getInfo( $identifier );
			} else {
				return new WP_Error( 'sti_mt_chat', 'متد خواندن اطلاعات کانال در این نسخه‌ی MadelineProto موجود نیست.' );
			}
		} catch ( \Throwable $e ) {
			$last_error = $e->getMessage();

			// fallback: حل از طریق پیام‌ها/چت‌ها — بعضی سرورها getPwrChat را برای
			// username های ساده نمی‌پذیرند ولی getChats/چت‌های موجود را می‌دهند
			try {
				if ( method_exists( $mad, 'getFullInfo' ) ) {
					$info = $mad->getFullInfo( $identifier );
				} else {
					throw new \Exception( 'no getFullInfo' );
				}
			} catch ( \Throwable $e2 ) {
				return new WP_Error( 'sti_mt_chat', 'پیدا کردن کانال ناموفق: ' . $this->friendly_rpc_error( $last_error ) );
			}
		}

		// نرمال‌سازی خروجی — شکل‌های مختلف getPwrChat / getFullInfo / getInfo
		if ( isset( $info['Chat'] ) ) { $info = $info['Chat']; }
		if ( isset( $info['chats'] ) && is_array( $info['chats'] ) ) { $info = reset( $info['chats'] ); }
		if ( isset( $info['users'] ) && is_array( $info['users'] ) && empty( $info['chats'] ) ) { $info = reset( $info['users'] ); }
		if ( isset( $info['chat'] ) && is_array( $info['chat'] ) ) { $info = $info['chat']; }
		if ( isset( $info['user'] ) && is_array( $info['user'] ) ) { $info = $info['user']; }

		$type = strtolower( (string) ( $info['type'] ?? $info['_'] ?? '' ) );
		$type = in_array( $type, array( 'channel', 'chat', 'user', 'bot' ), true ) ? $type : ( ( $info['Type'] ?? '' ) ? strtolower( $info['Type'] ) : 'chat' );

		return array(
			'id'       => (int) ( $info['id'] ?? 0 ),
			'type'     => $type,
			'title'    => (string) ( $info['title'] ?? $info['name'] ?? '' ),
			'username' => (string) ( $info['username'] ?? '' ),
			'members'  => (int) ( $info['participants_count'] ?? $info['members_count'] ?? 0 ),
			'private'  => empty( $info['username'] ) && empty( $info['usernames'] ) && 'user' !== $type,
			'about'    => (string) ( $info['about'] ?? '' ),
			'join_link'=> (string) ( $info['invite_link'] ?? '' ),
		);
	}

	/** نرمال‌سازی username: حذف @، t.me/، t.me/s/، /123 در انتها. */
	public static function normalize_identifier( $input ) {
		$input = trim( (string) $input );
		// حذف پروتکل و دامنه (با یا بدون https)
		$input = preg_replace( '#^https?://(?:www\.)?(?:t\.me|telegram\.me|telegram\.dog)/?#i', '', $input );
		$input = preg_replace( '#^(?:t\.me|telegram\.me|telegram\.dog)/?#i', '', $input );
		// حذف s/ پیش‌نمایش وب
		$input = preg_replace( '#^s/#i', '', $input );
		// حذف مسیر پیام خاص: …/123 سپس …/s در صورت باقی‌ماندن
		$input = preg_replace( '#/\d{1,10}(?:\?.*)?$#', '', $input );
		$input = preg_replace( '#/s$#i', '', $input );
		// حذف کوئری
		$input = preg_replace( '#\?.*$#', '', $input );
		$input = ltrim( $input, '@' );
		return trim( $input );
	}

	/**
	 * Server-side channel search used by the search-first Channel Importer.
	 * The personal MTProto account must be a member of the channel/group.
	 *
	 * @return array|WP_Error ['messages' => normalized messages, 'count' => int]
	 */
	public function search_messages( $peer, $query, $limit = 50, $offset_id = 0, $max_id = 0 ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) { return $mad; }
		$query = trim( (string) $query );
		if ( '' === $query ) { return array( 'messages' => array(), 'count' => 0 ); }
		$limit = max( 1, min( 100, (int) $limit ) );
		try {
			$params = array(
				'peer'        => $peer,
				'q'           => $query,
				'filter'      => array( '_' => 'inputMessagesFilterEmpty' ),
				'min_date'    => 0,
				'max_date'    => 0,
				'offset_id'   => max( 0, (int) $offset_id ),
				'add_offset'  => 0,
				'limit'       => $limit,
				'max_id'      => max( 0, (int) $max_id ),
				'min_id'      => 0,
				'hash'        => 0,
			);
			$result = $mad->messages->search( $params );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sti_mt_search', 'جست‌وجوی تلگرام ناموفق: ' . $e->getMessage() );
		}

		$messages = array();
		foreach ( (array) ( $result['messages'] ?? array() ) as $raw ) {
			$normalized = $this->normalize_message( $raw );
			if ( ! $normalized ) { continue; }
			$normalized['sender_chat_id'] = $peer;
			$messages[] = $normalized;
		}
		return array( 'messages' => $messages, 'count' => count( $messages ) );
	}

	/**
	 * خواندن تاریخچه‌ی پیام‌های کانال/گروه.
	 *
	 * @param string $peer       username یا chat_id عددی یا لینک دعوت.
	 * @param int    $limit      حداکثر تعداد پیام (۱ تا ۱۰۰).
	 * @param int    $offset_id  پیام‌های قدیمی‌تر از این آیدی برگردانده می‌شوند (0 = از آخر).
	 * @return array|WP_Error  ['messages' => normalized[], 'count' => int]
	 */
	public function get_history( $peer, $limit = 50, $offset_id = 0 ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}

		// اگر لینک دعوت است اول resolve کن
		if ( is_string( $peer ) && preg_match( '#(?:^|/)\+[A-Za-z0-9_\-]{5,}#', $peer ) ) {
			$info = $this->chat_info( $peer );
			if ( is_wp_error( $info ) ) {
				return $info;
			}
			$peer = $info['id'];
		}

		$limit = max( 1, min( (int) $limit, 100 ) );

		try {
			$result = $mad->messages->getHistory( array(
				'peer'        => $peer,
				'offset_id'   => max( 0, (int) $offset_id ),
				'offset_date' => 0,
				'add_offset'  => 0,
				'limit'       => $limit,
				'max_id'      => 0,
				'min_id'      => 0,
				'hash'        => 0,
			) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sti_mt_history', 'خواندن تاریخچه ناموفق: ' . $e->getMessage() );
		}

		$messages = array();
		foreach ( ( $result['messages'] ?? array() ) as $m ) {
			$normalized = $this->normalize_message( $m );
			if ( $normalized ) {
				$normalized['sender_chat_id'] = $peer;
				$messages[] = $normalized;
			}
		}

		return array(
			'messages' => $messages,
			'count'    => count( $messages ),
		);
	}

	/**
	 * تبدیل پیام خام MTProto به آرایه‌ی استاندارد افزونه.
	 * پیام‌های سرویسی (عضو شدن و …) نادیده گرفته می‌شوند.
	 *
	 * @param array $m
	 * @return array|null
	 */
	public function normalize_message( $m ) {
		if ( empty( $m ) || ! is_array( $m ) ) {
			return null;
		}
		if ( isset( $m['_'] ) && 0 === strpos( (string) $m['_'], 'messageService' ) ) {
			return null;
		}

		$id      = (int) ( $m['id'] ?? 0 );
		$date    = (int) ( $m['date'] ?? 0 );
		$text    = (string) ( $m['message'] ?? '' );
		$media   = $m['media'] ?? array();
		$media_t = strtolower( (string) ( $media['_'] ?? '' ) );

		// نوع رسانه (هر دو طرف مقایسه lowercase شود!)
		$media_type = 'none';
		if ( $media_t ) {
			if ( false !== strpos( $media_t, 'messagemediadocument' ) ) {
				$media_type = 'document';
				$doc = $media['document'] ?? array();
				$attrs = $doc['attributes'] ?? array();
				foreach ( $attrs as $a ) {
					if ( isset( $a['file_name'] ) ) {
						$file_name = $a['file_name'];
						break;
					}
				}
				$file_name = $file_name ?? ( 'file_' . $id . self::ext_from_mime( $doc['mime_type'] ?? '' ) );
				$file_size = (int) ( $doc['size'] ?? 0 );
				// شناسه‌ی عددی سند تلگرام — معادل MTProto برای «file_unique_id»؛ روی
				// هر دو طرف کانال/ربات برای همان فایل واقعی یکسان و پایدار است، پس
				// برای File Matcher (فاز ۳-D) دقیق‌ترین سیگنال ممکن است.
				$telegram_document_id = (int) ( $doc['id'] ?? 0 );
				$mime_type = (string) ( $doc['mime_type'] ?? '' );
			} elseif ( false !== strpos( $media_t, 'messagemediaphoto' ) ) {
				$media_type = 'photo';
				$file_name  = 'photo_' . $id . '.jpg';
				$file_size  = 0;
			} elseif ( false !== strpos( $media_t, 'messagemediavideo' ) ) {
				$media_type = 'video';
				$file_name  = 'video_' . $id . '.mp4';
				$file_size  = (int) ( $media['video']['size'] ?? 0 );
			} elseif ( false !== strpos( $media_t, 'messagemediaaudio' ) ) {
				$media_type = 'audio';
				$file_name  = 'audio_' . $id . '.mp3';
				$file_size  = (int) ( $media['audio']['size'] ?? 0 );
			} elseif ( false !== strpos( $media_t, 'messagemediavoice' ) ) {
				$media_type = 'voice';
				$file_name  = 'voice_' . $id . '.ogg';
				$file_size  = (int) ( $media['voice']['size'] ?? 0 );
			}
		}

		// دکمه‌های inline — ساختار MTProto: reply_markup.rows[].buttons[]
		// هر row یک keyboardButtonRow است با کلید «buttons»؛ پارس قبلی روی خودِ row
		// حلقه می‌زد (کلیدهای '_' و 'buttons') و به همین دلیل هیچ دکمه‌ای پیدا نمی‌شد.
		$buttons = array();
		$rows = $m['reply_markup']['rows'] ?? array();
		if ( empty( $rows ) ) {
			$rows = $m['reply_markup']['inline_keyboard'] ?? array();
		}
		foreach ( (array) $rows as $row ) {
			$row_buttons = ( is_array( $row ) && isset( $row['buttons'] ) && is_array( $row['buttons'] ) )
				? $row['buttons']
				: ( is_array( $row ) ? $row : array() );
			foreach ( $row_buttons as $b ) {
				if ( ! is_array( $b ) ) {
					continue;
				}
				$item = array(
					'text' => (string) ( $b['text'] ?? '' ),
					'url'  => (string) ( $b['url'] ?? '' ),
					'data' => (string) ( $b['data'] ?? '' ),
					'type' => (string) ( $b['_'] ?? '' ),
					'query'=> (string) ( $b['query'] ?? '' ),
				);
				if ( '' !== $item['text'] || '' !== $item['type'] ) {
					$buttons[] = $item;
				}
			}
		}

		$out = array(
			'id'         => $id,
			'date'       => $date,
			'date_mysql' => gmdate( 'Y-m-d H:i:s', $date ),
			'text'       => $text,
			'media_type' => $media_type,
			'file_name'  => $file_name ?? '',
			'file_size'  => $file_size ?? 0,
			'telegram_document_id' => $telegram_document_id ?? 0, // فقط برای media_type=document پر می‌شود
			'mime_type'  => $mime_type ?? '',
			'buttons'    => $buttons,
			'has_callback_button' => false,
			'raw'        => $m, // برای دانلود مستقیم توسط MadelineProto
		);
		foreach ( $buttons as $b ) {
			if ( '' !== $b['data'] ) {
				$out['has_callback_button'] = true;
				break;
			}
		}
		return $out;
	}

	protected static function ext_from_mime( $mime ) {
		$map = array(
			'application/zip' => '.zip', 'application/x-zip-compressed' => '.zip',
			'application/x-rar-compressed' => '.rar', 'application/vnd.rar' => '.rar',
			'application/x-7z-compressed' => '.7z',
			'application/pdf' => '.pdf',
			'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp',
			'image/svg+xml' => '.svg',
			'font/ttf' => '.ttf', 'font/otf' => '.otf', 'application/x-font-ttf' => '.ttf',
			'application/vnd.ms-fontobject' => '.eot', 'application/x-font-woff' => '.woff',
			'application/vnd.ms-excel' => '.xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
			'application/vnd.adobe.photoshop' => '.psd',
			'application/illustrator' => '.ai',
			'application/postscript' => '.eps',
			'application/octet-stream' => '',
		);
		$mime = strtolower( (string) $mime );
		if ( isset( $map[ $mime ] ) ) {
			return $map[ $mime ];
		}
		if ( preg_match( '#/([a-z0-9]+)$#', $mime, $mm ) && strlen( $mm[1] ) <= 5 ) {
			return '.' . $mm[1];
		}
		return '';
	}

	/**
	 * دانلود رسانه‌ی یک پیام با retry و رفرش — مقاوم در برابر خطای «Not Found».
	 *
	 * خطای Not Found معمولاً وقتی رخ می‌دهد که reference فایل (file_reference) در
	 * پیامِ ذخیره‌شده منقضی شده باشد (تلگرام references را کوتاه‌عمر می‌کند).
	 * راه‌حل: پیام را تازه از سرور می‌گیریم (getMessages با peer+id) و دوباره تلاش
	 * می‌کنیم — تا ۳ بار.
	 *
	 * @param array  $message   پیام نرمال‌شده (شامل raw و sender_chat_id).
	 * @param string $dest_dir  پوشه‌ی مقصد.
	 * @return array|WP_Error
	 */
	public function download_media_robust( $message, $dest_dir ) {
		// v7: زنجیره‌ی دانلود چندروشی شکارچی فایل (منطق قبلی پشتیبان می‌ماند)
		if ( class_exists( 'STI_File_Hunter' ) ) {
			$hunted = STI_File_Hunter::download( $this, $message, $dest_dir );
			if ( ! is_wp_error( $hunted ) ) { return $hunted; }
		}
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}

		$raw    = $message['raw'] ?? array();
		$peer   = $message['sender_chat_id'] ?? null;
		$msg_id = (int) ( $message['id'] ?? 0 );

		if ( empty( $raw['media'] ) && $peer && $msg_id ) {
			// اگر media در raw نبود، پیام را تازه بگیر
			try {
				$res = $mad->messages->getMessages( array( 'peer' => $peer, 'id' => array( $msg_id ) ) );
				$msgs = $res['messages'] ?? array();
				if ( ! empty( $msgs ) && is_array( $msgs[0] ) ) {
					$raw = $msgs[0];
					$message['raw'] = $raw;
				}
			} catch ( \Throwable $e ) {
				// بی‌خیال — با همان raw تلاش می‌کنیم
			}
		}

		$last_error = null;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			// در تلاش‌های بعدی، پیام را تازه بگیر (رفرش file_reference)
			if ( $attempt > 0 && $peer && $msg_id ) {
				try {
					$res = $mad->messages->getMessages( array( 'peer' => $peer, 'id' => array( $msg_id ) ) );
					$msgs = $res['messages'] ?? array();
					if ( ! empty( $msgs ) && is_array( $msgs[0] ) ) {
						$raw = $msgs[0];
					}
				} catch ( \Throwable $e ) {
					// بی‌خیال
				}
			}

			if ( empty( $raw['media'] ) ) {
				return new WP_Error( 'sti_mt_no_media', 'پیام رسانه ندارد (raw/media خالی است).' );
			}

			if ( ! is_dir( $dest_dir ) ) {
				wp_mkdir_p( $dest_dir );
			}

			$name = ! empty( $message['file_name'] ) ? sanitize_file_name( $message['file_name'] ) : ( 'file_' . $msg_id . '.bin' );
			$dest = trailingslashit( $dest_dir ) . $name;

			@set_time_limit( 0 );
			try {
				$path = $this->download_cascade( $mad, $raw, $dest, $dest_dir );
				$path = is_string( $path ) ? $path : '';
				$download_size = STI_Security::safe_file_size( $path );
				if ( $path && $download_size > 0 ) {
					return array(
						'path' => $path,
						'name' => basename( $path ),
						'size' => $download_size,
						'type' => $message['media_type'] ?? 'document',
					);
				}
				$last_error = 'فایل دانلود نشد یا خالی است';
			} catch ( \Throwable $e ) {
				$last_error = $e->getMessage();
				// صبر کوتاه انسانی بین تلاش‌ها
				usleep( ( $attempt + 1 ) * 1200000 );
			}
		}

		return new WP_Error( 'sti_mt_download', $last_error ?: 'دانلود ناموفق' );
	}

	/**
	 * دانلود رسانه‌ی یک پیام (داکیومنت/عکس/ویدیو/…) با اکانت کاربر — بدون محدودیت ۲۰MB بات.
	 *
	 * @param array  $message   پیام نرمال‌شده (شامل کلید raw).
	 * @param string $dest_dir  پوشه‌ی مقصد (محلی).
	 * @return array|WP_Error   ['path','name','size','type']
	 */
	public function download_media( $message, $dest_dir ) {
		if ( empty( $message['raw'] ) || empty( $message['raw']['media'] ) ) {
			return new WP_Error( 'sti_mt_no_media', 'پیام رسانه ندارد.' );
		}
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}
		if ( ! is_dir( $dest_dir ) ) {
			wp_mkdir_p( $dest_dir );
		}

		$name = ! empty( $message['file_name'] ) ? sanitize_file_name( $message['file_name'] ) : ( 'file_' . $message['id'] . '.bin' );
		$dest = trailingslashit( $dest_dir ) . $name;

		@set_time_limit( 0 );
		try {
			$path = $mad->downloadToFile( $message['raw'], $dest );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sti_mt_download', 'دانلود با اکانت شخصی ناموفق: ' . $e->getMessage() );
		}

		$path = is_string( $path ) ? $path : '';
		$size = STI_Security::safe_file_size( $path );
		if ( ! $path || $size < 1 ) {
			return new WP_Error( 'sti_mt_download', 'فایل دانلود نشد یا خالی است.' );
		}

		return array(
			'path' => $path,
			'name' => basename( $path ),
			'size' => $size,
			'type' => $message['media_type'] ?? 'document',
		);
	}

	/**
	 * فشار دادن دکمه‌ی inline (کالبک) روی یک پیام — مثل این‌که کاربر خودش دکمه‌ی
	 * «دانلود» را بزند. ربات‌هایی مثل FileechBot بعد از این، فایل را برای کاربر
	 * (در چت خصوصی یا خود کانال) ارسال می‌کنند.
	 *
	 * @param string $peer    کانال.
	 * @param int    $msg_id  شماره‌ی پیام.
	 * @param string $data    داده‌ی دکمه (callback_data).
	 * @return array|WP_Error پاسخ ربات (message/alert) یا خطا.
	 */
	public function press_button( $peer, $msg_id, $data ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}
		try {
			$answer = $mad->messages->getBotCallbackAnswer( array(
				'peer'     => $peer,
				'msg_id'   => (int) $msg_id,
				'data'     => (string) $data,
				'password' => '',
			) );
			return is_array( $answer ) ? $answer : array();
		} catch ( \Throwable $e ) {
			// بعضی بات‌ها پاسخ کالبک را نمی‌پذیرند ولی باز هم فایل می‌فرستند؛ پس فقط لاگ کن.
			STI_Logger::warning( 'MTProto: getBotCallbackAnswer — ' . $e->getMessage() );
			return new WP_Error( 'sti_mt_callback', $e->getMessage() );
		}
	}

	/**
	 * پیدا کردن همه‌ی فایل‌های (داکیومنت) جدید که «بعد از زمان مشخص» رسیده‌اند.
	 *
	 * استفاده: بعد از فشار دادن دکمه‌ی دانلود، ربات فایل را در چت خصوصی کاربر،
	 * در چت با خود ربات، یا در پیام‌های ذخیره‌شده می‌فرستد. این متد گفتگوهای
	 * اخیر را می‌گردد و در هر کدام، تاریخچه‌ی تازه را بررسی می‌کند تا فایل‌ها را
	 * پیدا کند — نه فقط آخرین پیام هر گفتگو (روش قبلی که فایل را از دست می‌داد).
	 *
	 * @param int $since_ts  بر حسب ثانیه (epoch) — فقط فایل‌های جدیدتر از این.
	 * @param int $max_age   حداکثر سن فایل (ثانیه) — پیش‌فرض ۱۰ دقیقه.
	 * @return array  لیست پیام‌های فایل نرمال‌شده (مرتب بر اساس زمان، قدیمی‌تر اول).
	 */
	public function find_recent_documents( $since_ts, $max_age = 600 ) {
		// v7: جستجوی گسترده‌ی شکارچی فایل (ربات‌ها، Saved Messages و همه‌ی دیالوگ‌ها)
		if ( class_exists( 'STI_File_Hunter' ) ) {
			$found = STI_File_Hunter::collect_incoming( $this, $since_ts, $max_age );
			if ( ! empty( $found ) ) { return $found; }
		}
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return array();
		}

		$docs = array();
		$min_date = max( (int) $since_ts, time() - max( 60, (int) $max_age ) );

		/* ── اولویت ۱: گفتگوی مستقیم با ربات‌های فایل (Fileech و مشابه) ── */
		/* گفتگوهای ربات: یادگرفته‌شده از تحویل‌های قبلی + لیست دستی + پیش‌فرض‌ها */
		$priority_bots = class_exists( 'STI_Bot_Inbox' )
			? STI_Bot_Inbox::bot_peers()
			: array( 'FileechBot', 'fileechbot', 'Fileech', 'filetobot', 'FileToBot' );
		foreach ( $priority_bots as $bot_user ) {
			try {
				$h = $mad->messages->getHistory( array(
					'peer'        => $bot_user,
					'offset_id'   => 0,
					'offset_date' => 0,
					'add_offset'  => 0,
					'limit'       => 60,
					'max_id'      => 0,
					'min_id'      => 0,
					'hash'        => 0,
				) );
				foreach ( ( $h['messages'] ?? array() ) as $m ) {
					$n = $this->normalize_message( $m );
					if ( ! $n || 'document' !== $n['media_type'] ) {
						continue;
					}
					if ( (int) $n['date'] < $min_date ) {
						continue;
					}
					$n['sender_chat_id'] = $bot_user;
					$docs[] = $n;
				}
			} catch ( \Throwable $e ) {
				// ربات در لیست دیالوگ‌ها نیست یا peer resolve نشد — بی‌خیال
			}
		}

		/* ── اولویت ۲: Saved Messages (پیام‌های ذخیره‌شده) ── */
		try {
			$h = $mad->messages->getHistory( array(
				'peer'        => 'me',
				'offset_id'   => 0,
				'offset_date' => 0,
				'add_offset'  => 0,
				'limit'       => 20,
				'max_id'      => 0,
				'min_id'      => 0,
				'hash'        => 0,
			) );
			foreach ( ( $h['messages'] ?? array() ) as $m ) {
				$n = $this->normalize_message( $m );
				if ( ! $n || 'document' !== $n['media_type'] ) {
					continue;
				}
				if ( (int) $n['date'] < $min_date ) {
					continue;
				}
				$n['sender_chat_id'] = 'me';
				$docs[] = $n;
			}
		} catch ( \Throwable $e ) {
			// ignore
		}

		/* ── اولویت ۳: دیالوگ‌های اخیر ── */
		try {
			$dialogs = $mad->messages->getDialogs( array(
				'offset_date' => 0,
				'offset_id'   => 0,
				'offset_peer' => array( '_' => 'inputPeerEmpty' ),
				'limit'       => 80,
				'hash'        => 0,
			) );
		} catch ( \Throwable $e ) {
			STI_Logger::warning( 'MTProto: getDialogs — ' . $e->getMessage() );
			$dialogs = array();
		}

		$candidates = array();
		foreach ( ( $dialogs['dialogs'] ?? array() ) as $d ) {
			$peer = $d['peer'] ?? array();
			$chat_id = 0;
			if ( ! empty( $peer['channel_id'] ) )      { $chat_id = (int) $peer['channel_id']; }
			elseif ( ! empty( $peer['chat_id'] ) )     { $chat_id = (int) $peer['chat_id']; }
			elseif ( ! empty( $peer['user_id'] ) )     { $chat_id = (int) $peer['user_id']; }
			if ( $chat_id ) {
				$candidates[ $chat_id ] = true;
			}
		}

		foreach ( ( $dialogs['messages'] ?? array() ) as $m ) {
			$n = $this->normalize_message( $m );
			if ( ! $n || 'document' !== $n['media_type'] ) {
				continue;
			}
			$chat_id = (int) ( $m['peer_id']['channel_id'] ?? $m['peer_id']['chat_id'] ?? $m['peer_id']['user_id'] ?? 0 );
			if ( (int) $n['date'] >= $min_date ) {
				$n['sender_chat_id'] = $chat_id;
				$docs[] = $n;
			}
		}

		$scanned = 0;
		foreach ( array_keys( $candidates ) as $chat_id ) {
			if ( count( $docs ) >= 60 || $scanned >= 25 ) {
				break;
			}
			$scanned++;
			try {
				$h = $mad->messages->getHistory( array(
					'peer'        => $chat_id,
					'offset_id'   => 0,
					'offset_date' => 0,
					'add_offset'  => 0,
					'limit'       => 25,
					'max_id'      => 0,
					'min_id'      => 0,
					'hash'        => 0,
				) );
			} catch ( \Throwable $e ) {
				continue;
			}
			foreach ( ( $h['messages'] ?? array() ) as $m ) {
				$n = $this->normalize_message( $m );
				if ( ! $n || 'document' !== $n['media_type'] ) {
					continue;
				}
				if ( (int) $n['date'] < $min_date ) {
					continue;
				}
				$n['sender_chat_id'] = $chat_id;
				$docs[] = $n;
			}
		}

		/* ── حذف تکراری و مرتب‌سازی ── */
		$seen = array();
		$unique = array();
		foreach ( $docs as $n ) {
			$key = (string) ( $n['sender_chat_id'] ?? 0 ) . ':' . ( $n['id'] ?? 0 ) . ':' . ( $n['file_name'] ?? '' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[] = $n;
		}
		usort( $unique, function ( $a, $b ) {
			return (int) $a['date'] <=> (int) $b['date'];
		} );

		if ( count( $unique ) > 0 ) {
			STI_Logger::info( 'MTProto: find_recent_documents — ' . count( $unique ) . ' فایل از ' . date( 'H:i:s', $min_date ) . ' تا الان' );
		}

		return $unique;
	}

	/**
	 * حالت تشخیصی — فقط برای دیباگ گلدن اسکن، در مسیر واقعی صدا زده نمی‌شود.
	 * برخلاف find_recent_documents، خطای واقعی هر peer را برمی‌گرداند (نه
	 * catch خاموش) تا معلوم شود مشکل «peer resolve نشد» است یا «تاریخچه خالیست»
	 * یا «پیام هست ولی document نیست».
	 *
	 * @return array|WP_Error { peer, messages: [{msg_id,date,type,file_name}] }
	 */
	public function debug_peer_history( $peer, $limit = 20 ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}
		try {
			$h = $mad->messages->getHistory( array(
				'peer'        => $peer,
				'offset_id'   => 0,
				'offset_date' => 0,
				'add_offset'  => 0,
				'limit'       => max( 1, min( 100, (int) $limit ) ),
				'max_id'      => 0,
				'min_id'      => 0,
				'hash'        => 0,
			) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sti_mt_debug_history_failed', $peer . ' → ' . $e->getMessage() );
		}

		$messages = array();
		foreach ( ( $h['messages'] ?? array() ) as $m ) {
			$n = $this->normalize_message( $m );
			$messages[] = array(
				'msg_id'    => $n['id'] ?? ( $m['id'] ?? null ),
				'date'      => $n['date_mysql'] ?? null,
				'type'      => $n['media_type'] ?? 'unknown',
				'file_name' => $n['file_name'] ?? '',
			);
		}
		return array( 'peer' => $peer, 'messages_total_in_history' => count( (array) ( $h['messages'] ?? array() ) ), 'messages' => $messages );
	}

	/**
	 * باز کردن گفتگوی ربات با پیام start — معادل کلیک روی دکمه‌ی
	 * «دانلود» که لینکش t.me/Bot?start=CODE است. ربات با دریافت این
	 * پیام، فایل مربوط به کد را برای کاربر می‌فرستد.
	 *
	 * @param string $bot_username  یوزرنیم ربات (بدون @).
	 * @param string $payload       مقدار start (کد فایل و…).
	 * @return array|WP_Error
	 */
	public function start_bot_dialog( $bot_username, $payload = '' ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}
		$text = '/start' . ( $payload ? ' ' . trim( $payload ) : '' );
		if ( class_exists( 'STI_File_Hunter' ) ) { STI_File_Hunter::learn_bot( $bot_username ); }
		try {
			return $mad->messages->sendMessage( array(
				'peer'      => trim( $bot_username, '@' ),
				'message'   => $text,
				'no_webpage'=> true,
			) );
		} catch ( \Throwable $e ) {
			STI_Logger::warning( 'MTProto: start_bot_dialog — ' . $e->getMessage() );
			return new WP_Error( 'sti_mt_start', $e->getMessage() );
		}
	}

	/**
	 * شروع رسمی گفتگو با ربات از طریق deep link — معادل دقیق کلیک روی
	 * دکمه‌ی t.me/Bot?start=CODE با متد رسمی تلگرام messages.startBot.
	 *
	 * چرا این متد؟ تلگرام messages.startBot را برای Deep Link تعریف کرده:
	 *
	 *     messages.startBot(bot, peer, start_param)
	 *
	 * به‌جای فرستادن متن «/start payload» — که در زنجیره‌های چندرباتی
	 * (PartyManagerBot → FileechBot) رفتار قابل اتکایی ندارد.
	 *
	 * ⚠️ قانون File Code: $payload همیشه string می‌ماند
	 * (24943123 / PAHCZG2 / X5LZPEA) — هرگز intval/absint/(int)/%d روی آن.
	 *
	 * @param string $bot_username  یوزرنیم ربات (بدون @).
	 * @param string $payload       start_param — فقط string.
	 * @param mixed  $peer          اختیاری: peer از پیش resolve‌شده (عدد/نام).
	 * @return array|WP_Error
	 */
	public function start_bot( $bot_username, $payload = '', $peer = null ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}

		$bot     = trim( (string) $bot_username, "@ \t" );
		$payload = trim( (string) $payload ); // string-only — قانون File Code
		if ( '' === $bot ) {
			return new WP_Error( 'sti_mt_start_bot_empty', 'نام ربات خالی است.' );
		}
		if ( class_exists( 'STI_File_Hunter' ) ) {
			STI_File_Hunter::learn_bot( $bot );
		}

		// peer عددی ربات — messages.startBot با InputUser/InputPeer قابل اتکاتر است.
		$bot_peer = $peer;
		if ( ! $bot_peer ) {
			try {
				$info = $this->chat_info( $bot );
				if ( ! is_wp_error( $info ) && ! empty( $info['id'] ) ) {
					$bot_peer = (int) $info['id'];
				}
			} catch ( \Throwable $e ) {
				// بی‌خیال — fallback روی username
			}
		}
		if ( ! $bot_peer ) {
			$bot_peer = $bot;
		}

		/* ۱) روش رسمی: messages.startBot — همان چیزی که تلگرام هنگام کلیک روی deep link اجرا می‌کند */
		try {
			return $mad->messages->startBot( array(
				'bot'         => $bot_peer,
				'peer'        => $bot_peer,
				'start_param' => $payload,
				'random_id'   => mt_rand( 1, 0x7fffffff ),
			) );
		} catch ( \Throwable $e ) {
			// نسخه‌های قدیمی MadelineProto یا خطای موقت — با پیام متنی fallback می‌کنیم.
			STI_Logger::warning( 'MTProto: messages.startBot — ' . $e->getMessage() . ' — fallback به /start' );
		}

		/* ۲) fallback: پیام متنی (رفتار قبلی) */
		return $this->start_bot_dialog( $bot, $payload );
	}

	/**
	 * پیوستن به گروه/کانال با لینک دعوت (messages.importChatInvite).
	 *
	 * @param string $hash  هش دعوت (بعد از t.me/+ یا joinchat/).
	 * @return array|WP_Error  { chat_id, title, username, ... }
	 */
	public function join_by_hash( $hash ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}
		$hash = trim( (string) $hash );
		if ( '' === $hash ) {
			return new WP_Error( 'sti_mt_join_empty', 'هش دعوت خالی است.' );
		}
		try {
			$imported = $mad->messages->importChatInvite( array( 'hash' => $hash ) );
			$chat     = $imported['chats'][0] ?? array();
			$chat_id  = (int) ( $chat['id'] ?? 0 );
			if ( ! $chat_id ) {
				return new WP_Error( 'sti_mt_join', 'پیوستن با لینک دعوت ناموفق بود (شاید قبلاً عضو هستید یا لینک منقضی شده).' );
			}
			return array(
				'chat_id'  => $chat_id,
				'title'    => (string) ( $chat['title'] ?? '' ),
				'username' => (string) ( $chat['username'] ?? '' ),
			);
		} catch ( \Throwable $e ) {
			// اگر قبلاً عضو است، importChatInvite خطا می‌دهد؛ تلاش با resolve نام.
			$err = $e->getMessage();
			$info = $this->chat_info( (int) ( $imported['chats'][0]['id'] ?? 0 ) ?: $hash );
			if ( ! is_wp_error( $info ) && ! empty( $info['id'] ) ) {
				return $info;
			}
			return new WP_Error( 'sti_mt_join', 'پیوستن با لینک دعوت ناموفق: ' . $err );
		}
	}

	/**
	 * باز کردن WebApp/Mini App یک ربات (messages.requestWebView).
	 * معمولاً مسیر ترجیحی startBot است؛ این فقط تلاش دوم برای WebApp است.
	 *
	 * @param string $bot_username
	 * @param string $app_name     نام اپ یا startapp
	 * @return array|WP_Error
	 */
	public function open_webview( $bot_username, $app_name = '' ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}
		$bot = trim( (string) $bot_username, "@ \t" );
		if ( '' === $bot ) {
			return new WP_Error( 'sti_mt_webview_empty', 'نام ربات خالی است.' );
		}

		$bot_peer = $bot;
		try {
			$info = $this->chat_info( $bot );
			if ( ! is_wp_error( $info ) && ! empty( $info['id'] ) ) {
				$bot_peer = (int) $info['id'];
			}
		} catch ( \Throwable $e ) {
			// بی‌خیال
		}

		$url = 'https://t.me/' . $bot . ( '' !== $app_name ? '/' . rawurlencode( $app_name ) : '' );

		try {
			return $mad->messages->requestWebView( array(
				'peer'      => $bot_peer,
				'bot'       => $bot_peer,
				'url'       => $url,
				'platform'  => 'android',
				'from_peer' => array( '_' => 'inputPeerEmpty' ),
			) );
		} catch ( \Throwable $e ) {
			STI_Logger::warning( 'MTProto: requestWebView — ' . $e->getMessage() );
			// آخرین تلاش: همان پیام متنی
			return $this->start_bot_dialog( $bot, $app_name );
		}
	}

	/**
	 * ارسال یک پیام متنی ساده به یک peer (برای گره‌های GATE/TEXT).
	 *
	 * @param string|int $peer
	 * @param string     $text
	 * @return array|WP_Error
	 */
	public function send_message_to_peer( $peer, $text ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return new WP_Error( 'sti_mt_send_empty', 'متن خالی است.' );
		}
		try {
			return $mad->messages->sendMessage( array(
				'peer'      => $peer,
				'message'   => $text,
				'no_webpage'=> true,
			) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sti_mt_send', $e->getMessage() );
		}
	}

	/**
	 * تاریخچه‌ی اخیر یک peer به‌صورت نرمال‌شده — برای Chain Poll.
	 * شامل پیام‌های غیر-فایل (دکمه، متن، دعوت) هم می‌شود، نه فقط documentها.
	 *
	 * @param string|int $peer
	 * @param int        $limit
	 * @param int        $since_ts  فقط پیام‌های جدیدتر از این timestamp
	 * @return array|WP_Error  array از normalize_message
	 */
	public function recent_peer_messages( $peer, $limit = 30, $since_ts = 0 ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) {
			return $mad;
		}
		try {
			$h = $mad->messages->getHistory( array(
				'peer'        => $peer,
				'offset_id'   => 0,
				'offset_date' => 0,
				'add_offset'  => 0,
				'limit'       => max( 1, min( 100, (int) $limit ) ),
				'max_id'      => 0,
				'min_id'      => 0,
				'hash'        => 0,
			) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sti_mt_history_failed', $peer . ' → ' . $e->getMessage() );
		}

		$out = array();
		foreach ( ( $h['messages'] ?? array() ) as $m ) {
			$n = $this->normalize_message( $m );
			if ( ! $n ) {
				continue;
			}
			if ( $since_ts && (int) $n['date'] < (int) $since_ts ) {
				continue;
			}
			$out[] = $n;
		}
		return $out;
	}

	/* ======================================================================
	   AJAX
	   ====================================================================== */

	protected static function check_ajax_nonce() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	/**
	 * ارسال پاسخ AJAX تمیز — هر خروجی ناخواسته (لاگ MadelineProto و …) که داخل
	 * بافر مانده را قبل از ارسال JSON دور می‌ریزد تا پاسخ همیشه JSON خالص باشد.
	 *
	 * @param string $type  success|error
	 * @param array  $payload
	 * @param int    $status_code
	 */
	protected static function ajax_reply( $type, $payload, $status_code = 200 ) {
		// مهم: قبل از ارسال پاسخ، client را متوقف کن تا worker پس‌زمینه‌ی MadelineProto
		// (IPC) زنده نماند و حافظه‌ی هاست را نخورد. بدون این، هر باز شدن صفحه/درخواست
		// یک worker جدید جمع می‌کند و بعد از چند بار، هاست OOM می‌شود و همه‌ی AJAXها
		// با «خطای ارتباط با سرور» fail می‌شوند — دقیقاً همان مشکلی که دیده شد.
		self::stop_client();

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( 'success' === $type ) {
			wp_send_json_success( $payload );
		}
		wp_send_json_error( $payload, $status_code );
	}

	/**
	 * متوقف کردن client فعلی و آزاد کردن پروسس‌های پس‌زمینه (IPC worker).
	 * در پایان هر درخواست AJAX صدا زده می‌شود تا worker ها orphan نشوند.
	 */
	public static function stop_client() {
		try {
			$mt  = self::instance();
			$mad = $mt->client;
			if ( is_object( $mad ) && $mad instanceof \danog\MadelineProto\API ) {
				try {
					if ( method_exists( $mad, 'stop' ) ) {
						$mad->stop();
					}
				} catch ( \Throwable $e ) {
					// بی‌خیال — شاید قبلاً متوقف شده
				}
			}
			$mt->client = null;
		} catch ( \Throwable $e ) {
			// بی‌خیال
		}
	}

	/**
	 * کشتن worker های به‌جامانده‌ی MadelineProto مربوط به سشن این سایت.
	 * روی هاست‌های اشتراکی، worker های قدیمی ممکن است از جلسات قبل مانده باشند
	 * و حافظه بگیرند؛ این متد با pkill آنها را می‌بندد (اگر shell مجاز باشد).
	 *
	 * @return int  تعداد worker های کشته‌شده (یا -1 اگر shell در دسترس نباشد).
	 */
	public static function cleanup_orphan_workers() {
		if ( ! function_exists( 'exec' ) || ! is_callable( 'exec' ) ) {
			return -1;
		}
		$pattern = 'madeline-ipc';
		$before  = array();
		@exec( 'pgrep -fc ' . escapeshellarg( $pattern ) . ' 2>/dev/null', $before );
		@exec( 'pkill -f ' . escapeshellarg( $pattern ) . ' 2>/dev/null; pkill -f ' . escapeshellarg( 'MadelineProto worker' ) . ' 2>/dev/null; true' );
		return isset( $before[0] ) ? (int) $before[0] : -1;
	}

	/** آیا سایت Composer autoloader دارد؟ (بسیاری از افزونه‌ها دارند) */
	public static function site_has_composer() {
		return class_exists( '\\Composer\\Autoload\\ClassLoader' );
	}

	/** وضعیت کامل بخش MTProto — برای رفرش پنل. */
	public static function ajax_status() {
		self::check_ajax_nonce();
		try {
			$mt = self::instance();

			$state = $mt->auth_state();
			$account = ( 'logged_in' === $state ) ? $mt->account_info() : null;

			self::ajax_reply( 'success', array(
				'configured'     => self::is_configured(),
				'php'            => PHP_VERSION,
				'composer'       => self::site_has_composer(),
				'engine_supported'=> self::engine_supported(),
				'engine_installed'=> self::engine_installed(),
				'engine_healthy' => self::engine_healthy(),
				'state'          => $state,
				'phone'          => self::is_configured() ? self::phone() : '',
				'account'        => $account,
				'error'          => $mt->client_error,
				'pending'        => (bool) get_transient( self::PENDING_KEY ),
			) );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			STI_Logger::error( 'MTProto: خطا در ajax_status — ' . $e->getMessage() );
			self::ajax_reply( 'error', array( 'message' => 'خطا در بررسی وضعیت: ' . $e->getMessage() ) );
		}
	}

	/** نصب موتور MadelineProto. */
	public static function ajax_install() {
		self::check_ajax_nonce();
		try {
			if ( self::engine_healthy() ) {
				self::ajax_reply( 'success', array( 'message' => 'موتور از قبل نصب و سالم است.' ) );
			}

			$result = self::install_engine();

			if ( is_wp_error( $result ) ) {
				self::ajax_reply( 'error', array( 'message' => $result->get_error_message() ) );
			}

			self::ajax_reply( 'success', array(
				'message' => '✅ موتور MadelineProto نصب شد (' . round( filesize( self::phar_path() ) / 1048576, 1 ) . ' MB).',
				'size'    => (int) filesize( self::phar_path() ),
			) );
		} catch ( \Throwable $e ) {
			STI_Logger::error( 'MTProto: خطا در ajax_install — ' . $e->getMessage() );
			self::ajax_reply( 'error', array( 'message' => 'خطا در نصب موتور: ' . $e->getMessage() ) );
		}
	}

	/** ارسال کد ورود. */
	public static function ajax_send_code() {
		self::check_ajax_nonce();
		try {
			$result = self::instance()->send_code();
			ob_end_clean();
			if ( is_wp_error( $result ) ) {
				self::ajax_reply( 'error', array( 'message' => $result->get_error_message() ) );
			}
			self::ajax_reply( 'success', array(
				'message' => '📲 کد ورود به شماره‌ی ' . self::phone() . ' ارسال شد (ممکن است چند دقیقه طول بکشد). کد را از تلگرام وارد کنید.',
			) );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			STI_Logger::error( 'MTProto: خطا در ajax_send_code — ' . $e->getMessage() );
			self::ajax_reply( 'error', array( 'message' => 'خطا در ارسال کد: ' . $e->getMessage() ) );
		}
	}

	/** تکمیل ورود با کد (+ رمز دومرحله‌ای در صورت نیاز). */
	public static function ajax_complete_login() {
		self::check_ajax_nonce();
		try {
			$code     = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
			$password = isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '';

			$result = self::instance()->complete_login( $code, $password );
			ob_end_clean();
			if ( is_wp_error( $result ) ) {
				self::ajax_reply( 'error', array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				) );
			}
			self::ajax_reply( 'success', array( 'message' => '✅ ورود موفق بود. حالا می‌توانید از کانال‌های خصوصی import کنید.' ) );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			STI_Logger::error( 'MTProto: خطا در ajax_complete_login — ' . $e->getMessage() );
			self::ajax_reply( 'error', array( 'message' => 'خطا در تکمیل ورود: ' . $e->getMessage() ) );
		}
	}

	/** خروج از اکانت. */
	public static function ajax_logout() {
		self::check_ajax_nonce();
		self::instance()->logout();
		self::ajax_reply( 'success', array( 'message' => 'خروج انجام شد و سشن محلی پاک شد.' ) );
	}

	/** بررسی سریع دسترسی به یک کانال/گروه با اکانت شخصی (برای دکمه‌ی تست). */
	public static function ajax_probe_chat() {
		self::check_ajax_nonce();
		try {
			$identifier = isset( $_POST['chat_username'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_username'] ) ) : '';
			if ( ! $identifier ) {
				self::ajax_reply( 'error', array( 'message' => 'نام کانال را وارد کنید.' ) );
			}

			$info = self::instance()->chat_info( $identifier );

			if ( is_wp_error( $info ) ) {
				ob_end_clean();
				self::ajax_reply( 'error', array( 'message' => $info->get_error_message() ) );
			}

			self::ajax_reply( 'success', array(
				'message' => sprintf(
					'✅ کانال «%s» پیدا شد (%s%s) — دسترسی به تاریخچه از طریق اکانت شخصی ممکن است.',
					$info['title'],
					$info['type'],
					$info['members'] ? '، ' . number_format_i18n( $info['members'] ) . ' عضو' : ''
				),
				'info' => $info,
			) );
		} catch ( \Throwable $e ) {
			STI_Logger::error( 'MTProto: خطا در ajax_probe_chat — ' . $e->getMessage() );
			self::ajax_reply( 'error', array( 'message' => 'خطا: ' . $e->getMessage() ) );
		}
	}

	/* ======================================================================
	   v7 — مقاوم‌سازی و پوشش امن (استفاده‌شده توسط STI_File_Hunter)
	   ====================================================================== */

	/**
	 * علت خطای «Uncaught Amp SignalException ... SIGTERM received»:
	 * MadelineProto هندلر سیگنال ثبت می‌کند؛ وقتی هاست به پروسه SIGTERM می‌دهد
	 * (پایان مهلت درخواست یا ری‌استارت pool) استثنا داخل event loop پرت می‌شود و
	 * کار نصفه می‌ماند. راه‌حل دائمی: سیگنال‌ها نادیده گرفته می‌شوند و هندلر خطای
	 * Revolt فقط لاگ می‌کند تا loop نمیرد.
	 */
	public static function harden_runtime() {
		static $done = false;
		if ( $done ) { return; }
		$done = true;

		@ignore_user_abort( true );
		@set_time_limit( 0 );

		if ( function_exists( 'pcntl_signal' ) && function_exists( 'pcntl_async_signals' ) ) {
			try {
				pcntl_async_signals( true );
				if ( defined( 'SIGTERM' ) ) { pcntl_signal( SIGTERM, SIG_IGN ); }
				if ( defined( 'SIGHUP' ) )  { pcntl_signal( SIGHUP, SIG_IGN ); }
				if ( defined( 'SIGINT' ) )  { pcntl_signal( SIGINT, SIG_IGN ); }
			} catch ( \Throwable $e ) {
				$ignored = 1;
			}
		}

		if ( class_exists( '\Revolt\EventLoop' ) ) {
			try {
				\Revolt\EventLoop::setErrorHandler( function ( $e ) {
					$msg = is_object( $e ) && method_exists( $e, 'getMessage' ) ? $e->getMessage() : (string) $e;
					if ( false !== stripos( $msg, 'SIGTERM' ) || false !== stripos( $msg, 'SignalException' ) ) {
						return;
					}
					STI_Logger::warning( 'MTProto loop: ' . mb_substr( $msg, 0, 220 ) );
				} );
			} catch ( \Throwable $e ) {
				$ignored = 1;
			}
		}
	}

	/** getHistory با پوشش کامل خطا — هر peer، بدون شکستن جریان. */
	public function safe_history( $peer, $limit = 30 ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) { return array(); }
		try {
			$h = $mad->messages->getHistory( array(
				'peer'        => $peer,
				'offset_id'   => 0,
				'offset_date' => 0,
				'add_offset'  => 0,
				'limit'       => max( 1, min( 100, (int) $limit ) ),
				'max_id'      => 0,
				'min_id'      => 0,
				'hash'        => 0,
			) );
			return (array) ( $h['messages'] ?? array() );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** getDialogs با پوشش خطا. */
	public function safe_dialogs( $limit = 100 ) {
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) { return array(); }
		try {
			return (array) $mad->messages->getDialogs( array(
				'offset_date' => 0,
				'offset_id'   => 0,
				'offset_peer' => array( '_' => 'inputPeerEmpty' ),
				'limit'       => max( 10, min( 200, (int) $limit ) ),
				'hash'        => 0,
			) );
		} catch ( \Throwable $e ) {
			$msg = $e->getMessage();
			if ( false === stripos( $msg, 'SIGTERM' ) ) {
				STI_Logger::warning( 'MTProto: getDialogs — ' . mb_substr( $msg, 0, 200 ) );
			}
			return array();
		}
	}

	/**
	 * نسخه‌ی تازه‌ی یک پیام (رفرش file_reference).
	 * برای کانال باید channels->getMessages صدا زده شود نه messages->getMessages —
	 * همین تفاوت یکی از علت‌های «Not Found» و «endpoint does not exist» بود.
	 */
	public function refresh_message( $peer, $msg_id ) {
		$msg_id = (int) $msg_id;
		if ( ! $msg_id ) { return null; }
		$mad = $this->client();
		if ( is_wp_error( $mad ) ) { return null; }

		$tries = array();
		if ( null !== $peer && '' !== $peer ) {
			$tries[] = function () use ( $mad, $peer, $msg_id ) {
				return $mad->channels->getMessages( array( 'channel' => $peer, 'id' => array( $msg_id ) ) );
			};
			$tries[] = function () use ( $mad, $peer, $msg_id ) {
				return $mad->messages->getMessages( array( 'peer' => $peer, 'id' => array( $msg_id ) ) );
			};
		}
		$tries[] = function () use ( $mad, $msg_id ) {
			return $mad->messages->getMessages( array( 'id' => array( $msg_id ) ) );
		};

		foreach ( $tries as $fn ) {
			try {
				$res  = $fn();
				$msgs = (array) ( $res['messages'] ?? array() );
				if ( ! empty( $msgs[0] ) && is_array( $msgs[0] ) ) { return $msgs[0]; }
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return null;
	}
}
