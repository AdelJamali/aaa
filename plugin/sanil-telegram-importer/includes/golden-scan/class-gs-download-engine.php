<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — فاز ۴-A: Download Engine.
 *
 * فقط دو تکه‌ی موجود و اثبات‌شده را به هم وصل می‌کند — هیچ Downloader/Storage
 * جدیدی ساخته نشده:
 *
 *   STI_MTProto::download_media_robust()  → دانلود واقعی از تلگرام (temp محلی)
 *   STI_File_Storage::process_local_temp_file() → آپلود FTP به dl.goldenfile.ir
 *                                                  (با fallback خودکار به محلی)
 *
 * State: FILE_MATCHED → DOWNLOAD_PENDING → DOWNLOADING → DOWNLOADED → STORED
 *        (خطا در هر نقطه → DOWNLOAD_FAILED، قابل retry)
 */
class STI_GS_Download_Engine {

	const LOCK_SECONDS = 600; // دانلود فایل‌های بزرگ ممکن است چند دقیقه طول بکشد

	/** یعنی دانلود/ذخیره‌سازی قبلاً انجام شده (یا فراتر) — دوباره دانلود نکن. */
	const PAST_STATES = array(
		'STORED', 'MEDIA_BUILDING', 'MEDIA_FAILED', 'MEDIA_READY',
		'PRODUCT_BUILDING', 'PRODUCT_FAILED', 'PRODUCT_READY', 'REVIEW_READY',
	);

	public static function download( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'downloader-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, self::LOCK_SECONDS ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط worker دیگری پردازش می‌شود.' );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}
			if ( in_array( $session['state'], self::PAST_STATES, true ) ) {
				STI_GS_Event::log( $session_id, 'download_engine', 'ok',
					'دانلود قبلاً انجام شده — Skip.',
					array( 'stage' => 'download_engine', 'reason' => 'already_completed', 'current_state' => $session['state'] )
				);
				return array( 'state' => $session['state'], 'skipped' => true, 'public_url' => $session['storage_url'] );
			}
			// از FILE_MATCHED (مسیر عادی) یا DOWNLOAD_FAILED (تلاش دوباره) وارد می‌شویم.
			if ( ! in_array( $session['state'], array( 'FILE_MATCHED', 'DOWNLOAD_FAILED' ), true ) ) {
				$reason = 'INVALID_STATE: Session باید FILE_MATCHED یا DOWNLOAD_FAILED باشد (الان: ' . $session['state'] . ').';
				STI_GS_Event::log( $session_id, 'download_engine', 'error', $reason );
				return new WP_Error( 'sti_gs_invalid_state', $reason );
			}

			$inbox = self::load_inbox_row( (int) $session['matched_inbox_id'] );
			if ( ! $inbox ) {
				self::fail( $session_id, 'NO_INBOX_RECORD: matched_inbox_id این Session در sti_bot_inbox پیدا نشد.' );
				return new WP_Error( 'sti_gs_no_inbox', 'ردیف inbox پیدا نشد.' );
			}

			$doc = json_decode( (string) $inbox['payload'], true );
			if ( ! is_array( $doc ) || empty( $doc['raw'] ) ) {
				self::fail( $session_id, 'INVALID_PAYLOAD: payload این ردیف inbox قابل decode نیست یا raw ندارد.' );
				return new WP_Error( 'sti_gs_bad_payload', 'payload خراب است.' );
			}

			$telegram_document_id = (string) ( $inbox['telegram_document_id'] ?? '' );

			// دوپلیکیت: اگر یک Session دیگر همین فایل فیزیکی را قبلاً کامل STORED کرده، reuse کن — دانلود دوباره ممنوع.
			if ( $telegram_document_id && '0' !== $telegram_document_id ) {
				$reused = self::find_already_stored( $telegram_document_id, $session_id );
				if ( $reused ) {
					STI_GS_Session::update( $session_id, array(
						'state'            => 'STORED',
						'stage'            => 'download_engine',
						'downloaded_path'  => $reused['downloaded_path'],
						'storage_url'      => $reused['storage_url'],
						'file_size_bytes'  => $reused['file_size_bytes'],
						'telegram_file_id' => $telegram_document_id,
						'error_reason'     => null,
					) );
					STI_GS_Artifact::log( $session_id, 'download_reused', array(
						'reused_from_session_id' => (int) $reused['id'],
						'telegram_document_id'   => $telegram_document_id,
						'public_url'              => $reused['storage_url'],
					) );
					STI_GS_Event::log( $session_id, 'download_engine', 'ok', 'فایل قبلاً توسط Session #' . $reused['id'] . ' دانلود/ذخیره شده بود؛ reuse شد (بدون دانلود مجدد).' );
					return array( 'state' => 'STORED', 'reused_from_session' => (int) $reused['id'], 'public_url' => $reused['storage_url'] );
				}
			}

			STI_GS_Session::update( $session_id, array( 'state' => 'DOWNLOAD_PENDING', 'stage' => 'download_engine' ) );

			$mt = STI_MTProto::instance();
			$dest_dir = trailingslashit( STI_MTProto::base_dir() ) . 'tmp';

			STI_GS_Session::update( $session_id, array( 'state' => 'DOWNLOADING' ) );
			STI_GS_Event::log( $session_id, 'download_engine', 'ok', 'دانلود از تلگرام شروع شد.' );

			$t0 = microtime( true );
			$result = $mt->download_media_robust( $doc, $dest_dir );
			$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			if ( is_wp_error( $result ) ) {
				$err_msg = $result->get_error_message();
				$next_retry = STI_GS_Retry::flood_wait_until( $err_msg );
				STI_GS_Artifact::log( $session_id, 'download_failed', array( 'error' => $err_msg, 'elapsed_ms' => $elapsed_ms ) );
				STI_GS_Session::update( $session_id, array(
					'state'         => 'DOWNLOAD_FAILED',
					'stage'         => 'download_engine',
					'error_reason'  => 'DOWNLOAD_FAILED: ' . $err_msg,
					'attempts'      => (int) $session['attempts'] + 1,
					'next_retry_at' => $next_retry,
				) );
				STI_GS_Event::log( $session_id, 'download_engine', 'error', "دانلود ناموفق: {$err_msg} — {$elapsed_ms}ms" );
				return new WP_Error( 'sti_gs_download_failed', $err_msg );
			}

			STI_GS_Session::update( $session_id, array(
				'state'            => 'DOWNLOADED',
				'stage'            => 'download_engine',
				'downloaded_path'  => $result['path'],
				'file_size_bytes'  => (int) $result['size'],
				'telegram_file_id' => $telegram_document_id ?: null,
			) );
			STI_GS_Event::log( $session_id, 'download_engine', 'ok', "دانلود کامل شد ({$result['size']} بایت) — {$elapsed_ms}ms" );

			/* ── ذخیره‌سازی (FTP → dl.goldenfile.ir، با fallback محلی خودکار داخل خودِ STI_File_Storage) ── */
			$meta = array(
				'file_name'     => $session['file_code'] ? ( 'Magnific_' . $session['file_code'] ) : ( $result['name'] ?? '' ),
				'file_code'     => $session['file_code'],
				'original_name' => $result['name'],
			);

			$t1 = microtime( true );
			$storage = STI_File_Storage::process_local_temp_file( $result['path'], $meta );
			$storage_elapsed_ms = (int) round( ( microtime( true ) - $t1 ) * 1000 );

			if ( is_wp_error( $storage ) ) {
				STI_GS_Artifact::log( $session_id, 'storage_failed', array( 'error' => $storage->get_error_message(), 'elapsed_ms' => $storage_elapsed_ms ) );
				STI_GS_Session::update( $session_id, array(
					'state'        => 'DOWNLOAD_FAILED',
					'stage'        => 'download_engine',
					'error_reason' => 'STORAGE_FAILED: ' . $storage->get_error_message(),
					'attempts'     => (int) $session['attempts'] + 1,
				) );
				STI_GS_Event::log( $session_id, 'download_engine', 'error', 'ذخیره‌سازی ناموفق: ' . $storage->get_error_message() );
				return new WP_Error( 'sti_gs_storage_failed', $storage->get_error_message() );
			}

			STI_GS_Session::update( $session_id, array(
				'state'        => 'STORED',
				'stage'        => 'download_engine',
				'storage_url'  => $storage['url'],
				'error_reason' => null,
			) );

			$artifact = array(
				'telegram_document_id' => $telegram_document_id,
				'file_name'            => $result['name'],
				'temp_path'            => $result['path'],
				'bytes'                => (int) $result['size'],
				'elapsed_ms'           => $elapsed_ms,
				'storage_result'       => $storage,
				'public_url'           => $storage['url'],
				'download_host'        => ! empty( $storage['fallback'] ) ? ( 'local_fallback:' . $storage['fallback'] ) : 'ftp',
				'storage_elapsed_ms'   => $storage_elapsed_ms,
			);
			STI_GS_Artifact::log( $session_id, 'download_complete', $artifact );
			STI_GS_Event::log( $session_id, 'download_engine', 'ok', 'فایل روی هاست دانلود ذخیره شد: ' . $storage['url'] );

			// پاک‌سازی temp — فقط بعد از موفقیت قطعی ذخیره‌سازی.
			if ( empty( $storage['fallback'] ) && @is_file( $result['path'] ) ) {
				@unlink( $result['path'] );
			}

			return array( 'state' => 'STORED', 'public_url' => $storage['url'], 'artifact' => $artifact );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
		}
	}

	protected static function load_inbox_row( $inbox_id ) {
		global $wpdb;
		if ( ! class_exists( 'STI_Bot_Inbox' ) || ! $inbox_id ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . STI_Bot_Inbox::table() . ' WHERE id = %d', (int) $inbox_id
		), ARRAY_A );
	}

	/** دوپلیکیت‌یابی سطح Session: همان telegram_document_id قبلاً کامل STORED شده؟ */
	protected static function find_already_stored( $telegram_document_id, $exclude_session_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT id, downloaded_path, storage_url, file_size_bytes FROM ' . STI_GS_Session::table() . "
			 WHERE telegram_file_id = %s AND state = 'STORED' AND id != %d
			 ORDER BY id DESC LIMIT 1",
			(string) $telegram_document_id, (int) $exclude_session_id
		), ARRAY_A );
	}

	protected static function fail( $session_id, $reason ) {
		STI_GS_Session::update( $session_id, array( 'state' => 'DOWNLOAD_FAILED', 'stage' => 'download_engine', 'error_reason' => $reason ) );
		STI_GS_Event::log( $session_id, 'download_engine', 'error', $reason );
	}
}
