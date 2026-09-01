<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI Download Strategy — مدیریت چندمسیره‌ی دانلود فایل
 *
 * استراتژی‌های دانلود (به ترتیب اولویت):
 * 1. Bot API مستقیم (فایل‌های ≤۲۰ مگابایت)
 * 2. URL مستقیم (لینک ارائه‌شده توسط کاربر)
 * 3. VPS Agent (در صورت تنظیم بودن)
 * 4. SSH/FTP مستقیم (در صورت تنظیم بودن)
 *
 * Class STI_Download_Strategy
 */
class STI_Download_Strategy {

	/**
	 * @param object $session  ردیف از جدول wp_sti_sessions.
	 * @param object $category ردیف از جدول wp_sti_categories.
	 * @param array  $options  گزینه‌های اضافی.
	 * @return array|WP_Error  ['url' => final_url, 'path' => path, 'size_bytes' => bytes]
	 */
	public static function download( $session, $category, $options = array() ) {
		$file_meta = array(
			'file_code'       => $session->file_code,
			'file_name'       => $session->file_name,
			'original_name'   => $session->doc_file_name,
			'category_folder' => $category ? ( $category->folder_key ?: STI_Category::sanitize_folder_key( $category->telegram_label, $category->id ) ) : '',
		);

		$storage_mode = $category ? STI_Category::storage_mode( $category ) : null;

		// ---- Strategy 1: Bot API مستقیم (برای فایل‌های پیوست شده به پیام) ----
		if ( ! empty( $session->doc_file_id ) ) {
			STI_Logger::info( 'Download Strategy: تلاش از طریق Bot API مستقیم...', $session->id );
			$result = self::try_bot_api( $session->doc_file_id, $file_meta, $storage_mode, $session->id );
			if ( ! is_wp_error( $result ) && ! empty( $result['url'] ) ) {
				STI_Logger::success( 'Download Strategy: دانلود از Bot API موفق بود.', $session->id );
				return $result;
			}
			STI_Logger::warning( 'Download Strategy: Bot API ناموفق بود — ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'نامشخص' ), $session->id );
		}

		// ---- Strategy 2: URL مستقیم ----
		if ( ! empty( $session->download_url_raw ) ) {
			STI_Logger::info( 'Download Strategy: تلاش از طریق URL مستقیم...', $session->id );
			$result = self::try_direct_url( $session->download_url_raw, $file_meta, $storage_mode, $session->id );
			if ( ! is_wp_error( $result ) && ! empty( $result['url'] ) ) {
				STI_Logger::success( 'Download Strategy: دانلود از URL مستقیم موفق بود.', $session->id );
				return $result;
			}
			STI_Logger::warning( 'Download Strategy: URL مستقیم ناموفق بود — ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'نامشخص' ), $session->id );
		}

		// ---- Strategy 3: VPS Agent ----
		if ( self::is_strategy_available( 'vps_agent' ) && ! empty( $session->doc_file_id ) ) {
			STI_Logger::info( 'Download Strategy: تلاش از طریق VPS Agent...', $session->id );
			$result = self::try_vps_agent( $session->doc_file_id, $session, $file_meta, $storage_mode );
			if ( ! is_wp_error( $result ) && ! empty( $result['url'] ) ) {
				STI_Logger::success( 'Download Strategy: دانلود از VPS Agent موفق بود.', $session->id );
				return $result;
			}
			STI_Logger::warning( 'Download Strategy: VPS Agent ناموفق بود — ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'نامشخص' ), $session->id );
		}

		// ---- Strategy 4: SSH/FTP مستقیم ----
		if ( self::is_strategy_available( 'ssh' ) && ! empty( $session->doc_file_id ) ) {
			STI_Logger::info( 'Download Strategy: تلاش از طریق SSH/FTP مستقیم...', $session->id );
			$result = self::try_ssh_transfer( $session->doc_file_id, $file_meta, $storage_mode, $session->id );
			if ( ! is_wp_error( $result ) && ! empty( $result['url'] ) ) {
				STI_Logger::success( 'Download Strategy: دانلود از طریق SSH/FTP موفق بود.', $session->id );
				return $result;
			}
			STI_Logger::warning( 'Download Strategy: SSH/FTP ناموفق بود — ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'نامشخص' ), $session->id );
		}

		// همه‌ی استراتژی‌ها ناموفق بودند
		return new WP_Error(
			'sti_all_downloads_failed',
			'هیچ‌یک از روش‌های دانلود موفق نبود. فایل را به‌صورت دستی در تلگرام دریافت کنید یا لینک مستقیم معتبر بفرستید.'
		);
	}

	/* =========================== STRATEGY 1: BOT API =========================== */

	/**
	 * تلاش برای دانلود مستقیم از Bot API تلگرام.
	 *
	 * @param string $file_id
	 * @param array  $file_meta
	 * @param string|null $storage_mode
	 * @param int    $session_id
	 * @return array|WP_Error
	 */
	protected static function try_bot_api( $file_id, $file_meta, $storage_mode, $session_id = 0 ) {
		$api = new STI_Telegram_API();
		$tmp = $api->download_file_to_temp( $file_id );

		if ( ! $tmp ) {
			$err = $api->get_last_error();
			$msg = ! empty( $err['message'] ) ? $err['message'] : 'دریافت فایل از تلگرام ممکن نشد.';
			return new WP_Error( 'sti_bot_api_download_failed', 'دریافت از Bot API ناموفق بود: ' . $msg );
		}

		$result = STI_File_Storage::process_local_temp_file( $tmp, $file_meta, $storage_mode );
		@unlink( $tmp );

		return $result;
	}

	/* =========================== STRATEGY 2: DIRECT URL =========================== */

	/**
	 * تلاش برای دانلود از URL مستقیم (لینک ارسالی کاربر).
	 *
	 * @param string $url
	 * @param array  $file_meta
	 * @param string|null $storage_mode
	 * @param int    $session_id
	 * @return array|WP_Error
	 */
	protected static function try_direct_url( $url, $file_meta, $storage_mode, $session_id = 0 ) {
		$valid_url = STI_Security::validate_remote_url( $url, 'download' );
		if ( is_wp_error( $valid_url ) ) {
			return $valid_url;
		}

		return STI_File_Storage::process( $valid_url, $file_meta, $storage_mode );
	}

	/* =========================== STRATEGY 3: VPS AGENT =========================== */

	/**
	 * تلاش برای دانلود از طریق VPS Agent Bridge.
	 *
	 * @param string $file_id
	 * @param object $session
	 * @param array  $file_meta
	 * @param string|null $storage_mode
	 * @return array|WP_Error
	 */
	protected static function try_vps_agent( $file_id, $session, $file_meta, $storage_mode ) {
		$endpoint = get_option( 'sti_ds_vps_endpoint', '' );
		if ( empty( $endpoint ) ) {
			return new WP_Error( 'sti_vps_no_endpoint', 'آدرس VPS Agent تنظیم نشده است.' );
		}

		// استفاده از STI_Agent_Bridge در صورت وجود
		if ( class_exists( 'STI_Agent_Bridge' ) ) {
			$bridge = STI_Agent_Bridge::instance();
			// بررسی وجود متد queue_download
			if ( method_exists( $bridge, 'queue_download' ) ) {
				$job_id = $bridge->queue_download( $file_id, $session, $file_meta );
				if ( is_wp_error( $job_id ) ) {
					return $job_id;
				}

				// انتظار برای تکمیل job (حداکثر ۱۲۰ ثانیه)
				$result = self::wait_for_vps_job( $job_id, 120 );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return $result;
			}
		}

		// Fallback: ارسال مستقیم درخواست به VPS endpoint
		$api_url = rtrim( $endpoint, '/' ) . '/api/v1/download';
		$payload = array(
			'file_id'   => $file_id,
			'file_code' => $session->file_code ?? '',
			'file_name' => $session->file_name ?? '',
			'category'  => $file_meta['category_folder'] ?? '',
		);

		$response = wp_remote_post( $api_url, array(
			'timeout' => 90,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'X-Agent-Key'   => get_option( 'sti_agent_api_key', '' ),
			),
			'body' => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sti_vps_request_failed', 'ارتباط با VPS Agent برقرار نشد: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['url'] ) ) {
			$error_msg = $body['error'] ?? ( 'HTTP ' . $code );
			return new WP_Error( 'sti_vps_error', 'VPS Agent دانلود را انجام نداد: ' . $error_msg );
		}

		return array(
			'url'       => $body['url'],
			'path'      => $body['path'] ?? null,
			'size_bytes' => $body['size_bytes'] ?? null,
		);
	}

	/**
	 * منتظر ماندن برای تکمیل job در VPS Agent.
	 *
	 * @param string $job_id
	 * @param int    $timeout_seconds
	 * @return array|WP_Error
	 */
	protected static function wait_for_vps_job( $job_id, $timeout_seconds = 120 ) {
		if ( ! class_exists( 'STI_Agent_Bridge' ) ) {
			return new WP_Error( 'sti_no_bridge', 'STI_Agent_Bridge در دسترس نیست.' );
		}

		$start    = time();
		$interval = 3; // بررسی هر ۳ ثانیه

		while ( ( time() - $start ) < $timeout_seconds ) {
			$job = STI_Agent_Bridge::instance()->get_job( $job_id );

			if ( ! $job ) {
				return new WP_Error( 'sti_vps_job_lost', 'وظیفه‌ی VPS یافت نشد.' );
			}

			$status = $job->status ?? '';
			if ( 'draft_created' === $status || 'file_ready' === $status ) {
				return array(
					'url'        => $job->download_url ?? '',
					'path'       => $job->file_path ?? null,
					'size_bytes' => $job->file_size_bytes ?? null,
				);
			}

			if ( 'failed' === $status || 'cancelled' === $status ) {
				return new WP_Error( 'sti_vps_job_failed', 'وظیفه‌ی VPS شکست خورد: ' . ( $job->error_message ?? 'نامشخص' ) );
			}

			sleep( $interval );
		}

		return new WP_Error( 'sti_vps_timeout', 'وظیفه‌ی VPS در زمان مقرر تکمیل نشد.' );
	}

	/* =========================== STRATEGY 4: SSH/FTP DIRECT =========================== */

	/**
	 * تلاش برای انتقال مستقیم فایل از تلگرام به سرور از طریق SSH/FTP.
	 *
	 * @param string $file_id
	 * @param array  $file_meta
	 * @param string|null $storage_mode
	 * @param int    $session_id
	 * @return array|WP_Error
	 */
	protected static function try_ssh_transfer( $file_id, $file_meta, $storage_mode, $session_id = 0 ) {
		// ابتدا فایل را از تلگرام دریافت کن (با API مستقیم)
		$api = new STI_Telegram_API();
		$tmp = $api->download_file_to_temp( $file_id );

		if ( ! $tmp ) {
			$err = $api->get_last_error();
			$msg = ! empty( $err['message'] ) ? $err['message'] : 'دریافت فایل از تلگرام برای انتقال SSH ممکن نشد.';
			return new WP_Error( 'sti_ssh_tg_failed', 'دریافت اولیه از تلگرام ناموفق بود: ' . $msg );
		}

		// سپس با File Storage از طریق FTP/Remote ارسال کن
		$result = STI_File_Storage::process_local_temp_file( $tmp, $file_meta, $storage_mode );
		@unlink( $tmp );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'sti_ssh_transfer_failed', 'انتقال فایل به سرور مقصد ناموفق بود: ' . $result->get_error_message() );
		}

		return $result;
	}

	/* =========================== STRATEGY SELECTION =========================== */

	/**
	 * تشخیص بهترین استراتژی بر اساس حجم فایل.
	 *
	 * @param int $file_size_bytes
	 * @return string  'bot_api' | 'direct_url' | 'vps_agent' | 'ssh' | 'auto'
	 */
	public static function get_best_strategy( $file_size_bytes ) {
		$large_mode = get_option( 'sti_ds_large_file_mode', 'auto' );

		if ( 'auto' !== $large_mode ) {
			return $large_mode;
		}

		// منطق auto: بر اساس اندازه‌ی فایل تصمیم بگیر
		$max_bot_api = 20 * 1024 * 1024; // 20 مگابایت — محدودیت Bot API تلگرام

		if ( $file_size_bytes <= $max_bot_api ) {
			return 'bot_api';
		}

		// برای فایل‌های بزرگ: از VPS Agent یا SSH استفاده کن
		if ( self::is_strategy_available( 'vps_agent' ) ) {
			return 'vps_agent';
		}

		if ( self::is_strategy_available( 'ssh' ) ) {
			return 'ssh';
		}

		return 'direct_url'; // fallback: نیاز به لینک مستقیم از کاربر
	}

	/**
	 * بررسی در دسترس بودن یک استراتژی.
	 *
	 * @param string $strategy  'vps_agent' | 'ssh' | 'bot_api' | 'direct_url'
	 * @return bool
	 */
	public static function is_strategy_available( $strategy ) {
		switch ( $strategy ) {
			case 'vps_agent':
				return (bool) get_option( 'sti_ds_vps_enabled', false )
					&& ! empty( get_option( 'sti_ds_vps_endpoint', '' ) );

			case 'ssh':
				return (bool) get_option( 'sti_ds_ssh_enabled', false );

			case 'bot_api':
				return (bool) STI_Settings::get( 'bot_token' );

			case 'direct_url':
				return true; // همیشه در دسترس

			default:
				return false;
		}
	}
}
