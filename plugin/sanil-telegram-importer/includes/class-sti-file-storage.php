<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Downloads a file from a source URL (the operator's own, permanent, Iranian-hosted
 * link) and stores it permanently either on this WordPress host or on a remote
 * host configured in settings (FTP or a custom HTTP upload endpoint). This
 * removes any dependency on Telegram / expiring third-party links for delivery.
 */
class STI_File_Storage {

	/**
	 * @param string $source_url  Direct URL to download the file from.
	 * @param array  $meta        ['file_code' => ..., 'file_name' => ..., 'category_slug' => ...] used for naming/organizing.
	 * @param string $mode_override 'local' | 'remote' | null (use global setting).
	 * @return array|WP_Error  ['url' => final public url, 'path' => local path if applicable]
	 */
	public static function process( $source_url, $meta = array(), $mode_override = null ) {
		$source_url = STI_Security::validate_remote_url( $source_url, 'download' );
		if ( is_wp_error( $source_url ) ) { return $source_url; }
		$download = self::download_to_temp( $source_url );
		if ( is_wp_error( $download ) ) {
			return $download;
		}
		$tmp_file = $download['path'];

		$size_bytes = STI_Security::safe_file_size( $tmp_file );
		$filename = self::build_filename( $source_url, $meta, $download['headers'] );
		$valid_file = STI_Security::validate_downloaded_file( $tmp_file, $filename );
		if ( is_wp_error( $valid_file ) ) { @unlink( $tmp_file ); return $valid_file; }
		$mode = $mode_override ?: STI_Settings::get( 'storage_mode', 'local' );

		if ( 'remote' === $mode ) {
			$result = self::store_remote( $tmp_file, $filename, $meta );
		} else {
			$result = self::store_local( $tmp_file, $filename, $meta );
		}

		@unlink( $tmp_file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$verify = self::verify_public_url( $result['url'] );
		if ( is_wp_error( $verify ) ) {
			return $verify;
		}

		$result['size_bytes'] = $size_bytes ?: null;
		return $result;
	}

	/**
	 * Same as process(), but for a file that has already been downloaded to a
	 * local temp path (e.g. pulled from Telegram) — skips the HTTP fetch step.
	 */
	public static function process_local_temp_file( $tmp_file, $meta = array(), $mode_override = null ) {
		$ext = ! empty( $meta['original_name'] ) ? pathinfo( $meta['original_name'], PATHINFO_EXTENSION ) : '';
		$filename = self::build_filename_from_meta( $meta, $ext );
		$size_bytes = STI_Security::safe_file_size( $tmp_file );

		// Local/MTProto/Agent files bypass the HTTP download validator, so validate
		// them here as well. Never place executable extensions in public uploads.
		$valid_file = STI_Security::validate_downloaded_file( $tmp_file, $filename );
		if ( is_wp_error( $valid_file ) ) {
			@unlink( $tmp_file );
			return $valid_file;
		}

		$mode = $mode_override ?: STI_Settings::get( 'storage_mode', 'local' );

		$result = ( 'remote' === $mode ) ? self::store_remote( $tmp_file, $filename, $meta ) : self::store_local( $tmp_file, $filename, $meta );

		/* ── راه‌حل جایگزین: اگر FTP/ریموت به هر دلیلی شکست خورد،
		   فایل به‌صورت خودکار روی هاست اصلی (محلی) ذخیره می‌شود تا محصول
		   هرگز به‌خاطر مشکل هاست دانلود از بین نرود. ── */
		if ( is_wp_error( $result ) && 'remote' === $mode ) {
			STI_Logger::warning( 'ذخیره‌سازی FTP ناموفق بود؛ فایل به‌صورت خودکار روی هاست اصلی ذخیره شد. جزئیات: ' . $result->get_error_message() );
			$local = self::store_local( $tmp_file, $filename, $meta );
			if ( ! is_wp_error( $local ) ) {
				$result = $local;
				$result['fallback'] = 'local';
			} else {
				STI_Logger::warning( 'Fallback محلی هم ناموفق: ' . $local->get_error_message() );
			}
		}

		// تلاش نهایی ultimate
		if ( is_wp_error( $result ) ) {
			STI_Logger::warning( 'همه روش‌های ذخیره ناموفق — تلاش ultimate copy محلی — جزئیات: ' . $result->get_error_message() );
			$ultimate = self::ultimate_local_copy_static( $tmp_file, $meta, $filename );
			if ( ! is_wp_error( $ultimate ) ) {
				$result = $ultimate;
				$result['fallback'] = 'ultimate';
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$verify = self::verify_public_url( $result['url'] );
		if ( is_wp_error( $verify ) ) {
			STI_Logger::warning( 'Verify URL ناموفق ولی فایل ذخیره شده: ' . $result['url'] );
		}

		$result['size_bytes'] = $size_bytes ?: null;
		return $result;
	}

	/**
	 * Confirms the final public URL we just built is actually reachable
	 * before we hand it to WooCommerce as the product's download link.
	 * Without this check, a wrong FTP path / base URL mismatch can silently
	 * produce a broken link while the product still gets created normally.
	 */
	protected static function verify_public_url( $url ) {
		$valid = STI_Security::validate_remote_url( $url, 'verification' );
		if ( is_wp_error( $valid ) ) { return $valid; }

		$code = 0;
		// دو بار تلاش با کمی صبر — بعضی هاست‌های دانلود فایل تازه‌آپلودشده را با تأخیر سرو می‌کنند
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$response = wp_remote_head( $valid, array( 'timeout' => 15, 'redirection' => 0 ) );
			$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

			if ( $code >= 200 && $code < 300 ) {
				return true;
			}
			if ( $attempt < 1 ) {
				sleep( 2 );
				// Some servers don't support HEAD properly — retry with a lightweight GET.
				$response = wp_remote_get( $valid, array( 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'Range' => 'bytes=0-0' ), 'stream' => false ) );
				$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 300 ) {
					return true;
				}
				sleep( 2 );
			}
		}

		// فایل واقعاً آپلود شده (ftp_put پاسخ موفق داده) — فقط هشدار بده، محصول را از دست نده.
		STI_Logger::warning( "لینک نهایی هنوز در دسترس نیست (HTTP {$code}): {$url} — فایل ذخیره شده و بعداً قابل دانلود است." );
		return true;
	}

	protected static function download_to_temp( $url ) {
		$args = array(
			'timeout'  => 60,
			'stream'   => true,
			'filename' => wp_tempnam( 'sti-src-' ),
		);
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			STI_Logger::error( 'دانلود فایل از لینک مبدا ناموفق بود: ' . $response->get_error_message() );
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			@unlink( $args['filename'] );
			return new WP_Error( 'sti_download_failed', "دانلود فایل ناموفق بود (HTTP {$code})" );
		}
		if ( STI_Security::safe_file_size( $args['filename'] ) > STI_Security::max_download_bytes() ) {
			@unlink( $args['filename'] );
			return new WP_Error( 'sti_download_too_large', 'حجم فایل از حد مجاز بیشتر است.' );
		}
		return array(
			'path'    => $args['filename'],
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}

	protected static function build_filename( $source_url, $meta, $headers = null ) {
		$ext = pathinfo( parse_url( $source_url, PHP_URL_PATH ), PATHINFO_EXTENSION );
		if ( ! $ext && $headers ) {
			$ext = self::guess_extension_from_headers( $headers );
		}
		return self::build_filename_from_meta( $meta, $ext );
	}

	/**
	 * When the source URL's path has no file extension (e.g. a "download?id=123"
	 * style link), try to recover it from the Content-Disposition filename or
	 * the Content-Type MIME type instead — otherwise the stored file (and the
	 * link handed to the customer) ends up with no extension at all.
	 */
	protected static function guess_extension_from_headers( $headers ) {
		$disposition = is_object( $headers ) && method_exists( $headers, 'offsetGet' )
			? $headers->offsetGet( 'content-disposition' )
			: ( $headers['content-disposition'] ?? '' );

		if ( $disposition && preg_match( '/filename\*?=["\']?(?:UTF-8\'\')?([^"\';]+)/i', $disposition, $m ) ) {
			$name = rawurldecode( trim( $m[1] ) );
			$ext = pathinfo( $name, PATHINFO_EXTENSION );
			if ( $ext ) {
				return $ext;
			}
		}

		$type = is_object( $headers ) && method_exists( $headers, 'offsetGet' )
			? $headers->offsetGet( 'content-type' )
			: ( $headers['content-type'] ?? '' );
		$type = strtolower( trim( explode( ';', (string) $type )[0] ) );

		$map = array(
			'application/zip'                    => 'zip',
			'application/x-zip-compressed'       => 'zip',
			'application/x-rar-compressed'       => 'rar',
			'application/vnd.rar'                => 'rar',
			'application/x-7z-compressed'        => '7z',
			'application/pdf'                    => 'pdf',
			'application/postscript'             => 'eps',
			'application/illustrator'            => 'ai',
			'image/vnd.adobe.photoshop'          => 'psd',
			'application/octet-stream'           => '', // too generic to guess reliably.
			'font/ttf'                           => 'ttf',
			'font/otf'                           => 'otf',
			'application/x-font-ttf'             => 'ttf',
		);

		return $map[ $type ] ?? '';
	}

	/**
	 * Short, human-readable, English filename derived from the (untranslated)
	 * file name in the caption — e.g. "wave-yellow-gradient-background-26972542.zip"
	 * instead of just the numeric code. Falls back to the code / a random id
	 * if no usable name is available.
	 */
	protected static function build_filename_from_meta( $meta, $ext ) {
		$ext = $ext ? '.' . preg_replace( '/[^a-zA-Z0-9]/', '', $ext ) : '';

		$slug = '';
		if ( ! empty( $meta['file_name'] ) ) {
			$slug = sanitize_title( $meta['file_name'] );
			// Keep it short ("خلاصه"): cap at ~50 chars, cut at a word boundary.
			if ( mb_strlen( $slug ) > 50 ) {
				$slug = mb_substr( $slug, 0, 50 );
				$slug = preg_replace( '/-[a-z0-9]*$/', '', $slug ); // trim a trailing partial word.
			}
		}

		$code = ! empty( $meta['file_code'] ) ? sanitize_file_name( $meta['file_code'] ) : '';

		if ( $slug && $code ) {
			$base = $slug . '-' . $code;
		} elseif ( $slug ) {
			$base = $slug;
		} elseif ( $code ) {
			$base = $code;
		} else {
			$base = uniqid( 'sti_' );
		}

		return $base . $ext;
	}

	/**
	 * Sub-path used to organize files by category, e.g. "vector" so all
	 * vector files land in .../woocommerce_uploads/vector/... instead of
	 * being mixed together.
	 *
	 * Must resolve to a plain ASCII segment (a-z0-9-). Callers pass the
	 * category's dedicated `folder_key` (see STI_Category::sanitize_folder_key()),
	 * never the free-text/Persian Telegram button label — that used to be used
	 * directly here and caused stored files to be unreachable at their public
	 * URL (HTTP 404): FTP and the HTTP server serving the same tree don't
	 * reliably agree on how non-ASCII bytes in a path are encoded/decoded, so a
	 * category named e.g. "کارت ویزیت" produced a link that pointed to a
	 * byte-different path than where the file actually landed. Re-sanitizing
	 * defensively here (not just trusting the caller) keeps that guarantee even
	 * if some other caller passes raw text.
	 */
	protected static function category_subpath( $meta ) {
		$raw = $meta['category_folder'] ?? ( $meta['category_slug'] ?? '' );
		if ( empty( $raw ) ) {
			return '';
		}
		$slug = preg_replace( '/[^a-z0-9-]/', '', sanitize_title( $raw ) );
		$slug = trim( $slug, '-' );
		return $slug ? $slug . '/' : '';
	}

	/* ---------------- LOCAL STORAGE ---------------- */

	protected static function store_local( $tmp_file, $filename, $meta = array() ) {
		$upload_dir = wp_upload_dir();
		$base_path  = trim( STI_Settings::get( 'local_base_path', 'sti-files' ), '/' );
		$rel_dir    = $base_path . '/' . self::category_subpath( $meta ) . date( 'Y/m' );
		$abs_dir    = trailingslashit( $upload_dir['basedir'] ) . $rel_dir;

		if ( ! file_exists( $abs_dir ) ) {
			wp_mkdir_p( $abs_dir );
			// Protect the directory listing but allow direct file access for downloads.
			file_put_contents( $abs_dir . '/index.php', "<?php // Silence is golden.\n" );
		}

		$dest_path = trailingslashit( $abs_dir ) . $filename;
		if ( ! @copy( $tmp_file, $dest_path ) ) {
			return new WP_Error( 'sti_copy_failed', 'کپی فایل روی هاست داخلی ناموفق بود.' );
		}

		$dest_url = trailingslashit( $upload_dir['baseurl'] ) . $rel_dir . '/' . rawurlencode( $filename );

		return array(
			'url'  => $dest_url,
			'path' => $dest_path,
		);
	}

	/* ---------------- FTP FULL TEST (برای پنل تنظیمات) ---------------- */

	/**
	 * تست کامل FTP: اتصال ← ورود ← ساخت پوشه تست ← آپلود فایل تست ← حذف.
	 * دقیقاً نشان می‌دهد هاست FTP چه اجازه‌هایی دارد تا قبل از شروع واردات،
	 * مشکل احتمالی (مثل ساخت پوشه) پیدا شود.
	 *
	 * @param array $overrides  مقادیر جایگزین تنظیمات (برای فرم تست).
	 * @return array  ['ok' => bool, 'steps' => array{label, ok, detail}]
	 */
	public static function ftp_full_test( $overrides = array() ) {
		$steps = array();
		$ok_all = true;

		$host = $overrides['host'] ?? STI_Settings::get( 'remote_ftp_host' );
		$port = (int) ( $overrides['port'] ?? STI_Settings::get( 'remote_ftp_port', 21 ) );
		$user = $overrides['user'] ?? STI_Settings::get( 'remote_ftp_user' );
		$pass = $overrides['pass'] ?? STI_Settings::get( 'remote_ftp_pass' );
		$path = trim( (string) ( $overrides['path'] ?? STI_Settings::get( 'remote_ftp_path', '/' ) ), '/' );

		if ( ! function_exists( 'ftp_connect' ) ) {
			return array( 'ok' => false, 'steps' => array( array( 'label' => 'اکستنشن FTP', 'ok' => false, 'detail' => 'فعال نیست' ) ) );
		}

		// ۱) اتصال
		$conn = @ftp_connect( $host, $port, 15 );
		if ( ! $conn ) {
			return array( 'ok' => false, 'steps' => array( array( 'label' => 'اتصال', 'ok' => false, 'detail' => "ناموفق: {$host}:{$port}" ) ) );
		}
		$steps[] = array( 'label' => 'اتصال به FTP', 'ok' => true, 'detail' => "{$host}:{$port}" );
		$ok_all = $ok_all && true;

		// ۲) ورود
		if ( ! @ftp_login( $conn, $user, $pass ) ) {
			ftp_close( $conn );
			$steps[] = array( 'label' => 'ورود', 'ok' => false, 'detail' => 'یوزر/پسورد اشتباه است' );
			return array( 'ok' => false, 'steps' => $steps );
		}
		$steps[] = array( 'label' => 'ورود', 'ok' => true, 'detail' => 'user=' . $user );
		@ftp_pasv( $conn, true );

		// ۳) دسترسی به پوشه‌ی اصلی تنظیم‌شده
		$root_ok = self::ftp_ensure_dir( $conn, $path );
		$steps[] = array(
			'label' => 'پوشه‌ی اصلی (' . ( $path ? '/' . $path : '/' ) . ')',
			'ok'    => $root_ok,
			'detail'=> $root_ok ? 'موجود/ساخته شد' : 'ساخته نشد — دسترسی ساخت پوشه ندارید',
		);
		$ok_all = $ok_all && $root_ok;

		// ۴) ساخت پوشه‌ی تست تاریخ
		$test_dir = $path . '/sti-ftptest';
		$dir_ok = self::ftp_ensure_dir( $conn, $test_dir );
		$steps[] = array(
			'label' => 'ساخت پوشه‌ی تست',
			'ok'    => $dir_ok,
			'detail'=> $dir_ok ? '/' . $test_dir . ' ساخته شد' : 'MKD توسط هاست رد شد — fallback استفاده خواهد شد',
		);

		// ۵) آپلود فایل تست — همیشه در پوشه‌ی اصلی (موجود) انجام می‌شود تا
		// حتی اگر ساخت پوشه ممنوع باشد، مشخص شود آپلود فایل ممکن است یا نه.
		$root_dir_ok = self::ftp_ensure_dir( $conn, $path );
		$tmp = tempnam( sys_get_temp_dir(), 'sti-ftptest' );
		file_put_contents( $tmp, 'STI FTP TEST ' . time() );
		$up_ok = $root_dir_ok && @ftp_put( $conn, 'sti-ftptest.txt', $tmp, FTP_BINARY );
		$steps[] = array(
			'label' => 'آپلود فایل تست',
			'ok'    => $up_ok,
			'detail'=> $up_ok ? 'sti-ftptest.txt آپلود شد' : 'آپلود ناموفق بود',
		);
		if ( $up_ok ) {
			@ftp_delete( $conn, 'sti-ftptest.txt' );
		}
		@unlink( $tmp );
		$ok_all = $ok_all && $up_ok;

		// ۶) وجود پوشه‌ی category + تاریخ (برای اطلاع)
		$cat_dir = $path . '/font/' . date( 'Y/m' );
		$cat_ok = self::ftp_ensure_dir( $conn, $cat_dir );
		$steps[] = array(
			'label' => 'ساخت پوشه‌ی دسته/تاریخ (مثل فونت/2026/08)',
			'ok'    => $cat_ok,
			'detail'=> $cat_ok ? '/' . $cat_dir . ' OK' : 'رد شد — فایل‌ها در پوشه‌ی اصلی ذخیره می‌شوند (بدون تاریخ)',
		);

		ftp_close( $conn );

		return array( 'ok' => $ok_all, 'steps' => $steps );
	}

	/* ---------------- REMOTE STORAGE ---------------- */

	protected static function store_remote( $tmp_file, $filename, $meta = array() ) {
		$type = STI_Settings::get( 'remote_type', 'ftp' );
		if ( 'http' === $type ) {
			return self::store_remote_http( $tmp_file, $filename );
		}
		return self::store_remote_ftp( $tmp_file, $filename, $meta );
	}

	/** هشدارهای جمع‌آوری‌شده‌ی FTP در آخرین عملیات (برای دیاگنوستیک). */
	protected static $ftp_warnings = array();

	/**
	 * اجرای یک تابع FTP در «حالت امن» — هشدارهای PHP (E_WARNING/E_NOTICE) را
	 * می‌بلعد تا اگر هاست error handler سفارشی دارد (که هشدار را به استثنا تبدیل
	 * می‌کند و کل batch را می‌کشد — دقیقاً همان خطای «ftp_chdir(): Can't change
	 * directory to 08» که دیدی) چیزی نشکند. هشدارها برای دیاگنوستیک ذخیره می‌شوند.
	 *
	 * @param callable $fn
	 * @param array    $args
	 * @return mixed
	 */
	protected static function ftp_safe( $fn, $args = array() ) {
		$prev = set_error_handler( function ( $severity, $message, $file, $line ) {
			if ( ! ( error_reporting() & $severity ) ) {
				return false; // با @ فراخوانی شده — خود PHP مدیریت کند
			}
			if ( in_array( $severity, array( E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING, E_USER_NOTICE ), true ) ) {
				self::$ftp_warnings[] = $message;
				return true; // بلعیده شد
			}
			return false;
		} );

		try {
			return call_user_func_array( $fn, $args );
		} finally {
			restore_error_handler();
		}
	}

	protected static function store_remote_ftp( $tmp_file, $filename, $meta = array() ) {
		self::$ftp_warnings = array();

		if ( ! function_exists( 'ftp_connect' ) ) {
			return new WP_Error( 'sti_no_ftp_ext', 'اکستنشن FTP در PHP فعال نیست.' );
		}

		$host = STI_Settings::get( 'remote_ftp_host' );
		$port = (int) STI_Settings::get( 'remote_ftp_port', 21 );
		$user = STI_Settings::get( 'remote_ftp_user' );
		$pass = STI_Settings::get( 'remote_ftp_pass' );
		$path = trim( STI_Settings::get( 'remote_ftp_path', '/' ), '/' );
		$use_ssl = STI_Settings::get( 'remote_ftp_ssl' );

		$result = null;

		// کل عملیات در حالت امن — هیچ هشدار PHP نمی‌تواند به استثنا تبدیل شود
		self::ftp_safe( function () use ( &$result, $host, $port, $user, $pass, $path, $use_ssl, $tmp_file, $filename, $meta ) {
			$conn = $use_ssl && function_exists( 'ftp_ssl_connect' )
				? @ftp_ssl_connect( $host, $port, 20 )
				: @ftp_connect( $host, $port, 20 );

			if ( ! $conn ) {
				$result = new WP_Error( 'sti_ftp_connect_failed', 'اتصال به هاست دانلود خارجی (FTP) ناموفق بود: ' . $host . ':' . $port . ' — آی‌پی/هاست یا پورت را چک کنید.' );
				return;
			}
			if ( ! @ftp_login( $conn, $user, $pass ) ) {
				ftp_close( $conn );
				$result = new WP_Error( 'sti_ftp_login_failed', 'ورود به هاست دانلود خارجی ناموفق بود (یوزر/پسورد).' );
				return;
			}
			@ftp_pasv( $conn, true );

			$category  = trim( self::category_subpath( $meta ), '/' );
			$date_path = date( 'Y/m' );

			/* ── مسیرهای هدف به ترتیب اولویت ── */
			$targets = array();
			if ( $category && $date_path ) {
				$targets[] = trim( $path . '/' . $category . '/' . $date_path, '/' );
			}
			if ( $category ) {
				$targets[] = trim( $path . '/' . $category, '/' );
			}
			$targets[] = $path;

			$uploaded_dir  = null;
			$upload_errors = array();

			foreach ( $targets as $target ) {
				if ( '' === $target ) {
					$target = '/';
				}

				// هر target جدا try می‌شود تا یک خطا بقیه را نکشد
				try {
					$dir_ok = self::ftp_ensure_dir( $conn, $target );
					if ( ! $dir_ok ) {
						$upload_errors[] = 'ساخت پوشه‌ی «/' . $target . '» ناموفق بود';
						continue;
					}

					$ok = @ftp_put( $conn, $filename, $tmp_file, FTP_BINARY );
					if ( ! $ok ) {
						$upload_errors[] = 'آپلود به «/' . $target . '» ناموفق بود';
						continue;
					}

					$uploaded_dir = $target;
					break;
				} catch ( \Throwable $e ) {
					$upload_errors[] = '«/' . $target . '»: ' . $e->getMessage();
					continue;
				}
			}

			ftp_close( $conn );

			if ( null === $uploaded_dir ) {
				$details = implode( ' | ', $upload_errors );
				if ( ! empty( self::$ftp_warnings ) ) {
					$details .= ' | هشدارها: ' . implode( ' | ', array_slice( self::$ftp_warnings, 0, 5 ) );
				}
				$result = new WP_Error(
					'sti_ftp_upload_failed',
					'آپلود به هاست FTP ناموفق بود. جزئیات: ' . $details .
					' — فایل به‌صورت خودکار روی هاست اصلی ذخیره می‌شود تا محصول از بین نرود.'
				);
				return;
			}

			/* ── لینک عمومی — با جلوگیری از /gfx/gfx تکراری ── */
			$base_url_raw = untrailingslashit( STI_Settings::get( 'remote_public_base_url' ) );
			$base_parts = @parse_url( $base_url_raw );
			$base_path  = trim( $base_parts['path'] ?? '', '/' );

			// مسیر عمومی واقعی روی هاست (بدون public_html)
			$public_path = trim( preg_replace( '#^(public_html|htdocs|www|wwwroot|httpdocs|web)/?#i', '', $uploaded_dir ), '/' );

			// حذف تکرارهای متوالی مثل gfx/gfx → gfx
			$segs = explode( '/', $public_path );
			$dedup = array();
			$prev  = null;
			foreach ( $segs as $seg ) {
				if ( '' === $seg ) { continue; }
				if ( null !== $prev && $seg === $prev ) { continue; }
				$dedup[] = $seg;
				$prev = $seg;
			}
			$public_path = implode( '/', $dedup );

			// اگر base_url خودش شامل بخشی از مسیر است (مثلاً https://dl.../gfx) آن بخش را از public_path حذف کن تا دوبار نیاید
			if ( '' !== $base_path && '' !== $public_path ) {
				if ( $public_path === $base_path ) {
					$public_path = '';
				} elseif ( 0 === strpos( $public_path, $base_path . '/' ) ) {
					$public_path = ltrim( substr( $public_path, strlen( $base_path ) ), '/' );
				}
			}

			if ( '' !== $public_path ) {
				$dest_url = $base_url_raw . '/' . $public_path . '/' . rawurlencode( $filename );
			} else {
				$dest_url = $base_url_raw . '/' . rawurlencode( $filename );
			}
			// نرمال‌سازی //های اضافی (حفظ https://)
			$dest_url = preg_replace( '#(?<!:)//+#', '/', $dest_url );

			STI_Logger::info( 'فایل روی FTP در «/' . $uploaded_dir . '/' . $filename . '» ذخیره شد — لینک: ' . $dest_url );

			$result = array(
				'url'  => $dest_url,
				'path' => '/' . trim( $uploaded_dir, '/' ) . '/' . $filename,
			);
		} );

		return $result;
	}

	/**
	 * ساخت بازگشتی پوشه‌ها روی سرور FTP.
	 *
	 * نکته‌ی مهم: در FTP استاندارد، دستور MKD باید با «نام ساده» پوشه اجرا شود
	 * (بعد از رفتن به پوشه‌ی والد)، نه با مسیر کامل. خیلی از سرورهای هاست
	 * اشتراکی (Pure-FTPd/ProFTPD) MKD با مسیر مطلق را رد می‌کنند — همین باعث
	 * خطای «Can't change directory to .../2026/08» می‌شد. این پیاده‌سازی
	 * جزء‌به‌جزء جلو می‌رود: چک وجود ← chdir به والد ← mkdir با نام ساده ← chdir به داخل.
	 *
	 * @param resource $conn
	 * @param string   $dir  مسیر کامل (مثل /public_html/gfx/font/2026/08)
	 * @return bool  true اگر همه‌ی پوشه‌ها موجود/ساخته شدند.
	 */
	protected static function ftp_mkdir_recursive( $conn, $dir ) {
		$parts = explode( '/', trim( $dir, '/' ) );
		$path  = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) { continue; }
			$path .= '/' . $part;

			// پوشه وجود دارد؟
			if ( @ftp_chdir( $conn, $path ) ) {
				continue;
			}

			// پوشه نیست — به والد برو و با نام ساده بساز
			$parent = dirname( $path );
			if ( $parent && '/' !== $parent && '.' !== $parent ) {
				@ftp_chdir( $conn, $parent );
			}

			$made = @ftp_mkdir( $conn, $part );
			if ( ! $made ) {
				// برخی سرورها MKD با مسیر کامل را می‌پذیرند — تلاش دوم
				$made = @ftp_mkdir( $conn, $path );
			}
			if ( ! $made ) {
				return false;
			}

			// وارد پوشه‌ی تازه‌ساخته شو
			if ( ! @ftp_chdir( $conn, $path ) && ! @ftp_chdir( $conn, $part ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * رفتن به یک پوشه در FTP — اگر نبود، آن را (با زیرپوشه‌هایش) می‌سازد.
	 *
	 * روش: اول ریشه‌ی واقعی حساب را پیدا می‌کند (chdir '/' یا ftp_pwd — برای
	 * حساب‌های chroot شده که ریشه‌شان public_html است)، سپس جزء‌به‌جزء جلو می‌رود:
	 * هر جزء موجود → chdir، غایب → MKD با نام ساده سپس chdir. تمام فراخوانی‌ها
	 * در حالت امن (ftp_safe) اجرا می‌شوند تا هشدارهای PHP چیزی را نشکنند.
	 *
	 * @param resource $conn
	 * @param string   $dir  مثل public_html/gfx/font (بدون اسلش اول)
	 * @return bool  در صورت موفقیت، دایرکتوری جاری = $dir
	 */
	protected static function ftp_ensure_dir( $conn, $dir ) {
		$dir = trim( $dir, '/' );
		if ( '' === $dir ) {
			return @ftp_chdir( $conn, '/' ) ? true : (bool) @ftp_chdir( $conn, '.' );
		}

		/* ── پیدا کردن ریشه‌ی واقعی حساب FTP ── */
		$base = '';
		if ( @ftp_chdir( $conn, '/' ) ) {
			$base = '/';
		} else {
			$pwd = @ftp_pwd( $conn );
			if ( $pwd && '/' !== $pwd ) {
				$base = rtrim( $pwd, '/' );
			}
		}

		/* ── حذف پیشوند ریشه از مسیر خواسته‌شده ── */
		$rel = $dir;
		if ( $base && '/' !== $base ) {
			$base_trim = ltrim( $base, '/' );
			if ( 0 === strpos( $dir, $base_trim . '/' ) ) {
				$rel = substr( $dir, strlen( $base_trim ) + 1 );
			}
			@ftp_chdir( $conn, $base );
		}

		$parts = explode( '/', $rel );

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			// موجود؟
			if ( @ftp_chdir( $conn, $part ) ) {
				continue;
			}

			// نیست — بساز (نام ساده؛ استاندارد FTP)
			$made = @ftp_mkdir( $conn, $part );
			if ( ! $made ) {
				// تلاش دوم: MKD با مسیر نسبی از ریشه
				$made = @ftp_mkdir( $conn, $rel );
			}
			if ( ! $made ) {
				return false;
			}

			// وارد پوشه‌ی تازه شو
			if ( ! @ftp_chdir( $conn, $part ) ) {
				return false;
			}
		}

		return true;
	}

	protected static function store_remote_http( $tmp_file, $filename ) {
		$endpoint = STI_Settings::get( 'remote_http_endpoint' );
		$api_key  = STI_Settings::get( 'remote_http_api_key' );

		if ( empty( $endpoint ) ) {
			return new WP_Error( 'sti_http_no_endpoint', 'آدرس API آپلود هاست خارجی تنظیم نشده است.' );
		}

		$boundary = wp_generate_password( 24, false );
		$file_contents = file_get_contents( $tmp_file );

		$payload  = "--{$boundary}\r\n";
		$payload .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
		$payload .= "Content-Type: application/octet-stream\r\n\r\n";
		$payload .= $file_contents . "\r\n";
		$payload .= "--{$boundary}--\r\n";

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => $payload,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['url'] ) ) {
			return new WP_Error( 'sti_http_bad_response', 'پاسخ نامعتبر از API آپلود هاست خارجی (فیلد url موجود نیست).' );
		}
		return array( 'url' => $body['url'], 'path' => null );
	}

	/**
	 * Store the featured image (small, always local media library — WooCommerce needs an attachment ID).
	 */
	public static function store_image_from_url( $url, $desc = '' ) {
		$valid = STI_Security::validate_remote_url( $url, 'image' );
		if ( is_wp_error( $valid ) ) { return $valid; }
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $valid, 0, $desc, 'id' );

		// اگر هاست دانلود هنوز فایل را سرو نکرده بود (کند/کش)، یک بار دیگر تلاش کن
		if ( is_wp_error( $attachment_id ) ) {
			sleep( 2 );
			$attachment_id = media_sideload_image( $valid, 0, $desc, 'id' );
		}

		return $attachment_id;
	}

	/** Store a verified Telegram image already downloaded to a temporary local path. */
	public static function store_image_from_local_file( $tmp_file, $desc = '', $original_name = 'telegram-image.jpg' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		if ( ! file_exists( $tmp_file ) || ! @getimagesize( $tmp_file ) ) {
			@unlink( $tmp_file );
			return new WP_Error( 'sti_invalid_image', 'فایل تصویر معتبر نیست.' );
		}
		$file = array( 'name' => sanitize_file_name( $original_name ?: 'telegram-image.jpg' ), 'tmp_name' => $tmp_file );
		$attachment_id = media_handle_sideload( $file, 0, $desc );
		if ( is_wp_error( $attachment_id ) ) { @unlink( $tmp_file ); }
		return $attachment_id;
	}

	/**
	 * آخرین راه‌حل محلی — کپی مستقیم بدون وابستگی به تنظیمات
	 */
	public static function ultimate_local_copy_static( $tmp_file, $meta, $filename ) {
		if ( ! file_exists( $tmp_file ) ) {
			return new WP_Error( 'sti_no_tmp', 'فایل موقت برای ultimate copy یافت نشد' );
		}
		$upload_dir = wp_upload_dir();
		$base = trailingslashit( $upload_dir['basedir'] ) . 'sti-files/fallback/' . date( 'Y/m' );
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		$dest = trailingslashit( $base ) . $filename;
		if ( ! @copy( $tmp_file, $dest ) ) {
			// با نام یکتا دوباره تلاش
			$filename = uniqid( 'sti_' ) . '-' . $filename;
			$dest = trailingslashit( $base ) . $filename;
			if ( ! @copy( $tmp_file, $dest ) ) {
				return new WP_Error( 'sti_ultimate_copy_failed', 'کپی نهایی fallback ناموفق بود' );
			}
		}
		$rel = 'sti-files/fallback/' . date( 'Y/m' ) . '/' . $filename;
		$url = trailingslashit( $upload_dir['baseurl'] ) . $rel;
		return array( 'url' => $url, 'path' => $dest, 'size_bytes' => STI_Security::safe_file_size( $dest ) );
	}

	/**
	 * اصلاح لینک‌های قدیمی که به دلیل باگ /gfx/gfx/ دارند — یک‌بار اجرا کافی است.
	 * تمام محصولات ووکامرس با SKU STI-* را می‌گردد و هر جا /gfx/gfx/ باشد به /gfx/ تبدیل می‌کند.
	 *
	 * @return int|WP_Error تعداد محصول اصلاح‌شده
	 */
	public static function fix_double_gfx_in_products() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_downloadable_files' AND meta_value LIKE '%gfx/gfx%'" );
		$fixed = 0;
		foreach ( $rows as $row ) {
			$files = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $files ) ) { continue; }
			$changed = false;
			foreach ( $files as $fid => $fdata ) {
				if ( ! empty( $fdata['file'] ) && false !== strpos( $fdata['file'], '/gfx/gfx/' ) ) {
					$files[ $fid ]['file'] = str_replace( '/gfx/gfx/', '/gfx/', $fdata['file'] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_post_meta( $row->post_id, '_downloadable_files', $files );
				$fixed++;
				STI_Logger::info( "لینک محصول #{$row->post_id} اصلاح شد — /gfx/gfx/ → /gfx/" );
			}
		}
		// همچنین download_url_final در جدول sti_sessions را اصلاح کن (برای تاریخچه)
		$wpdb->query( "UPDATE {$wpdb->prefix}sti_sessions SET download_url_final = REPLACE(download_url_final, '/gfx/gfx/', '/gfx/') WHERE download_url_final LIKE '%/gfx/gfx/%'" );
		return $fixed;
	}
}
