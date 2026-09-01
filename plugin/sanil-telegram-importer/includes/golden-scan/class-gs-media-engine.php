<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Media Engine.
 *
 * تصویر شاخص اجباری است — هیچ Placeholder مجاز نیست. اگر تصویر واقعی پیدا
 * نشود، Session وارد MEDIA_FAILED می‌شود و Product Builder هرگز اجرا نخواهد شد
 * (گارد دوباره در خودِ Product Builder هم هست، این‌جا صرفاً منبع تصمیم است).
 *
 * هیچ Downloader جدیدی ساخته نشده: فقط STI_MTProto::download_media_robust()
 * و STI_File_Storage::process_local_temp_file() — همان دو تکه‌ی تثبیت‌شده‌ی فاز ۴.
 * برای اولویت ۲/۳ (عکس در چت ربات) مستقیماً از client()/normalize_message()ی
 * عمومی STI_MTProto استفاده می‌شود — چیزی در آن فایل تغییر نمی‌کند.
 */
class STI_GS_Media_Engine {

	const LOCK_SECONDS = 300;

	/** یعنی تصویر شاخص قبلاً پیدا/ذخیره شده (یا فراتر) — دوباره دانلود عکس نکن. */
	const PAST_STATES = array(
		'MEDIA_READY', 'PRODUCT_BUILDING', 'PRODUCT_FAILED', 'PRODUCT_READY', 'REVIEW_READY',
	);

	public static function build( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'media-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, self::LOCK_SECONDS ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط worker دیگری پردازش می‌شود.' );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}
			if ( in_array( $session['state'], self::PAST_STATES, true ) ) {
				STI_GS_Event::log( $session_id, 'media_engine', 'ok',
					'تصویر شاخص قبلاً آماده شده — Skip.',
					array( 'stage' => 'media_engine', 'reason' => 'already_completed', 'current_state' => $session['state'] )
				);
				return array( 'state' => $session['state'], 'skipped' => true, 'image_url' => $session['image_url'] );
			}
			if ( ! in_array( $session['state'], array( 'STORED', 'MEDIA_FAILED' ), true ) ) {
				$reason = 'INVALID_STATE: Session باید STORED یا MEDIA_FAILED باشد (الان: ' . $session['state'] . ').';
				STI_GS_Event::log( $session_id, 'media_engine', 'error', $reason );
				return new WP_Error( 'sti_gs_invalid_state', $reason );
			}

			STI_GS_Session::update( $session_id, array( 'state' => 'MEDIA_BUILDING', 'stage' => 'media_engine' ) );

			$resolved = self::resolve_photo_message( $session );
			STI_GS_Artifact::log( $session_id, 'media_resolve', array(
				'found'  => null !== $resolved,
				'source' => $resolved['source'] ?? null,
			) );

			if ( ! $resolved ) {
				STI_GS_Artifact::log( $session_id, 'media_resolve_failed', array(
					'checked' => array( 'channel_message', 'bot_response_adjacent', 'bot_response_nearby' ),
				) );
				self::fail( $session_id, 'NO_IMAGE_FOUND: نه پیام کانال نه پاسخ ربات هیچ عکسی نداشت.' );
				return array( 'state' => 'MEDIA_FAILED' );
			}

			$mt = STI_MTProto::instance();
			$dest_dir = trailingslashit( STI_MTProto::base_dir() ) . 'tmp';

			$t0 = microtime( true );
			$result = $mt->download_media_robust( $resolved['message'], $dest_dir );
			$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			if ( is_wp_error( $result ) ) {
				STI_GS_Artifact::log( $session_id, 'media_download', array( 'result' => 'error', 'error' => $result->get_error_message(), 'elapsed_ms' => $elapsed_ms ) );
				self::fail( $session_id, 'MEDIA_DOWNLOAD_FAILED: ' . $result->get_error_message() );
				return new WP_Error( 'sti_gs_media_download_failed', $result->get_error_message() );
			}
			STI_GS_Artifact::log( $session_id, 'media_download', array( 'result' => 'ok', 'bytes' => (int) $result['size'], 'elapsed_ms' => $elapsed_ms, 'source' => $resolved['source'] ) );

			$meta = array(
				'file_name'     => 'cover-' . ( $session['file_code'] ?: $session_id ),
				'original_name' => $result['name'],
			);
			$storage = STI_File_Storage::process_local_temp_file( $result['path'], $meta );

			if ( is_wp_error( $storage ) ) {
				STI_GS_Artifact::log( $session_id, 'media_store', array( 'result' => 'error', 'error' => $storage->get_error_message() ) );
				self::fail( $session_id, 'MEDIA_STORE_FAILED: ' . $storage->get_error_message() );
				return new WP_Error( 'sti_gs_media_store_failed', $storage->get_error_message() );
			}
			STI_GS_Artifact::log( $session_id, 'media_store', array( 'result' => 'ok', 'url' => $storage['url'] ) );

			if ( empty( $storage['fallback'] ) && @is_file( $result['path'] ) ) {
				@unlink( $result['path'] );
			}

			STI_GS_Session::update( $session_id, array(
				'state'        => 'MEDIA_READY',
				'stage'        => 'media_engine',
				'image_url'    => $storage['url'],
				'error_reason' => null,
			) );
			STI_GS_Event::log( $session_id, 'media_engine', 'ok', 'تصویر شاخص از «' . $resolved['source'] . '» پیدا و ذخیره شد: ' . $storage['url'] );

			return array( 'state' => 'MEDIA_READY', 'image_url' => $storage['url'], 'source' => $resolved['source'] );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
		}
	}

	/**
	 * سه اولویت طبق دستور: پیام اصلی کانال → عکس همراه پاسخ ربات (همسایه‌ی نزدیک) →
	 * جست‌وجوی گسترده‌تر در چت ربات. هیچ‌کدام Placeholder نیست؛ یا واقعی پیدا می‌شود یا هیچ.
	 */
	protected static function resolve_photo_message( $session ) {
		// Priority 1: خودِ پیام کانال (raw_json از فاز ۱)
		$message = self::load_message( (int) $session['message_pk'] );
		if ( $message && ! empty( $message['raw_json'] ) ) {
			$raw = json_decode( (string) $message['raw_json'], true );
			if ( is_array( $raw ) ) {
				$normalized = STI_MTProto::instance()->normalize_message( $raw );

				/**
				 * شرط قبلی فقط `media_type === 'photo'` را می‌پذیرفت.
				 *
				 * اما پیام کانال معمولاً یک **سند با تصویر پیش‌نمایش** است،
				 * نه یک عکس خالص — پس media_type آن `document` می‌شود و این
				 * اولویت همیشه رد می‌شد. نتیجه‌اش این بود که تصویر شاخص از
				 * چت ربات برداشته می‌شد، جایی که پیش‌نمایش‌ها اغلب ناقص
				 * بارگذاری می‌شوند.
				 *
				 * حالا اگر پیام کانال هر تصویری داشته باشد — چه عکس خالص،
				 * چه thumbnail سند — همان اولویت دارد.
				 */
				if ( $normalized && self::message_has_image( $normalized, $raw ) ) {
					return array( 'message' => $normalized, 'source' => 'channel_message' );
				}
			}
		}

		// Priority 2/3: عکس در چت ربات، نزدیک همان پیامی که فایل از آن آمد
		if ( ! empty( $session['bot_username'] ) && ! empty( $session['matched_inbox_id'] ) ) {
			$inbox = self::load_inbox_row( (int) $session['matched_inbox_id'] );
			$target_msg_id = (int) ( $inbox['msg_id'] ?? 0 );
			if ( $target_msg_id ) {
				$near = self::find_photo_near( $session['bot_username'], $target_msg_id, 1 );
				if ( $near ) {
					return array( 'message' => $near, 'source' => 'bot_response_adjacent' );
				}
				$wide = self::find_photo_near( $session['bot_username'], $target_msg_id, 5 );
				if ( $wide ) {
					return array( 'message' => $wide, 'source' => 'bot_response_nearby' );
				}
			}
		}

		return null;
	}

	/** جست‌وجوی خام (فقط خواندن تاریخچه، نه تغییر چیزی در STI_MTProto) دور یک msg_id مشخص برای پیدا کردن عکس. */
	protected static function find_photo_near( $peer, $target_msg_id, $window ) {
		$mt = STI_MTProto::instance();
		$mad = $mt->client();
		if ( is_wp_error( $mad ) ) {
			return null;
		}
		try {
			$h = $mad->messages->getHistory( array(
				'peer'        => $peer,
				'offset_id'   => $target_msg_id + $window + 1,
				'offset_date' => 0,
				'add_offset'  => 0,
				'limit'       => max( 3, $window * 2 + 4 ),
				'max_id'      => 0,
				'min_id'      => max( 0, $target_msg_id - $window - 1 ),
				'hash'        => 0,
			) );
		} catch ( \Throwable $e ) {
			return null;
		}
		foreach ( ( $h['messages'] ?? array() ) as $m ) {
			$n = $mt->normalize_message( $m );
			if ( $n && 'photo' === $n['media_type'] && abs( ( (int) ( $n['id'] ?? 0 ) ) - $target_msg_id ) <= $window ) {
				return $n;
			}
		}
		return null;
	}

	protected static function load_message( $message_pk ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . STI_GS_DB::messages_table() . ' WHERE id = %d', (int) $message_pk
		), ARRAY_A );
	}

	/**
	 * آیا این پیام تصویری برای استفاده به‌عنوان کاور دارد؟
	 *
	 * سه حالت پذیرفته می‌شود:
	 *   • عکس خالص (messageMediaPhoto)
	 *   • سند با thumbnail (بیشتر پست‌های کانال همین‌اند)
	 *   • سندی که خودش تصویر است (mime_type = image/*)
	 */
	protected static function message_has_image( $normalized, $raw ) {
		if ( 'photo' === ( $normalized['media_type'] ?? '' ) ) {
			return true;
		}

		$mime = strtolower( (string) ( $normalized['mime_type'] ?? '' ) );
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return true;
		}

		// thumbnail سند
		$media = $raw['media'] ?? array();
		$doc   = $media['document'] ?? array();
		if ( ! empty( $doc['thumbs'] ) && is_array( $doc['thumbs'] ) ) {
			return true;
		}

		return false;
	}

	protected static function load_inbox_row( $inbox_id ) {
		global $wpdb;
		if ( ! class_exists( 'STI_Bot_Inbox' ) ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . STI_Bot_Inbox::table() . ' WHERE id = %d', (int) $inbox_id
		), ARRAY_A );
	}

	protected static function fail( $session_id, $reason ) {
		STI_GS_Session::update( $session_id, array( 'state' => 'MEDIA_FAILED', 'stage' => 'media_engine', 'error_reason' => $reason ) );
		STI_GS_Event::log( $session_id, 'media_engine', 'error', $reason );
	}
}
