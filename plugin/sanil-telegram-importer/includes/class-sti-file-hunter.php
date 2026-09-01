<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI_File_Hunter — «شکارچی فایل» (v7)
 *
 * مشکل نسخه‌های قبل: بعد از زدن دکمه‌ی دانلود، ربات فایل را می‌فرستاد ولی افزونه
 * آن را پیدا/تطبیق/دانلود نمی‌کرد و سشن با «کمبود: فایل دانلود» خطا می‌خورد.
 * چهار علت واقعی: (۱) جستجوی محدود، (۲) فقط media_type=document،
 * (۳) تطبیق کد سخت‌گیرانه که در حالت چند-انتظاری خاموش می‌شد،
 * (۴) یک متد دانلود بدون جایگزین (خطای «The endpoint does not exist!»).
 */
class STI_File_Hunter {

	const LEARNED_BOTS_OPTION = 'sti_learned_file_bots';
	const CLAIMED_OPTION      = 'sti_hunter_claimed_docs';

	public static function known_bots() {
		$base = array( 'FileechBot', 'Fileech_bot', 'FileToBot', 'filetobot' );
		$learned = get_option( self::LEARNED_BOTS_OPTION, array() );
		if ( ! is_array( $learned ) ) { $learned = array(); }
		return apply_filters( 'sti_file_bots', array_values( array_unique( array_merge( array_keys( $learned ), $base ) ) ) );
	}

	/** هر بار دکمه‌ی t.me/Bot?start=CODE زده شود، نام ربات یاد گرفته می‌شود. */
	public static function learn_bot( $username ) {
		$username = trim( (string) $username, '@ ' );
		if ( '' === $username ) { return; }
		$learned = get_option( self::LEARNED_BOTS_OPTION, array() );
		if ( ! is_array( $learned ) ) { $learned = array(); }
		$learned[ $username ] = time();
		if ( count( $learned ) > 40 ) { $learned = array_slice( $learned, -40, null, true ); }
		update_option( self::LEARNED_BOTS_OPTION, $learned, false );
	}

	/* ---------------- علامت‌گذاری فایل‌های مصرف‌شده ---------------- */

	protected static function claimed() {
		$c = get_option( self::CLAIMED_OPTION, array() );
		if ( ! is_array( $c ) ) { $c = array(); }
		$cut = time() - 6 * HOUR_IN_SECONDS;
		return array_filter( $c, function ( $ts ) use ( $cut ) { return (int) $ts > $cut; } );
	}

	public static function doc_key( $doc ) {
		return md5( (string) ( $doc['sender_chat_id'] ?? '' ) . ':' . (string) ( $doc['id'] ?? '' ) . ':' . (string) ( $doc['file_name'] ?? '' ) );
	}

	public static function is_claimed( $doc ) {
		$c = self::claimed();
		return isset( $c[ self::doc_key( $doc ) ] );
	}

	public static function claim( $doc ) {
		$c = self::claimed();
		$c[ self::doc_key( $doc ) ] = time();
		update_option( self::CLAIMED_OPTION, $c, false );
	}

	/* ---------------- جستجوی گسترده ---------------- */

	public static function collect_incoming( $mt, $since_ts, $max_age = 900 ) {
		$min_date = max( (int) $since_ts, time() - max( 120, (int) $max_age ) );
		$docs = array();

		foreach ( self::known_bots() as $bot ) {
			foreach ( self::history_docs( $mt, $bot, 50, $min_date ) as $d ) {
				$d['sender_chat_id'] = $bot;
				$d['source'] = 'bot:' . $bot;
				$docs[] = $d;
			}
		}

		foreach ( self::history_docs( $mt, 'me', 30, $min_date ) as $d ) {
			$d['sender_chat_id'] = 'me';
			$d['source'] = 'saved';
			$docs[] = $d;
		}

		$dialogs = self::scan_dialogs( $mt, 100 );
		foreach ( $dialogs['inline_docs'] as $d ) { $docs[] = $d; }

		$scanned = 0;
		foreach ( array_keys( $dialogs['peers'] ) as $peer_id ) {
			if ( $scanned >= 30 || count( $docs ) >= 120 ) { break; }
			$scanned++;
			foreach ( self::history_docs( $mt, $peer_id, 25, $min_date ) as $d ) {
				$d['sender_chat_id'] = $peer_id;
				$d['source'] = 'dialog';
				$docs[] = $d;
			}
		}

		$seen = array(); $unique = array();
		foreach ( $docs as $d ) {
			if ( (int) ( $d['date'] ?? 0 ) < $min_date ) { continue; }
			$k = self::doc_key( $d );
			if ( isset( $seen[ $k ] ) ) { continue; }
			$seen[ $k ] = true;
			if ( self::is_claimed( $d ) ) { continue; }
			$unique[] = $d;
		}
		usort( $unique, function ( $a, $b ) { return (int) ( $a['date'] ?? 0 ) <=> (int) ( $b['date'] ?? 0 ); } );

		if ( ! empty( $unique ) ) {
			STI_Logger::info( 'شکارچی فایل: ' . count( $unique ) . ' فایل تازه پیدا شد (از ' . wp_date( 'H:i:s', $min_date ) . ').' );
		}
		return $unique;
	}

	protected static function history_docs( $mt, $peer, $limit, $min_date ) {
		$out = array();
		foreach ( (array) $mt->safe_history( $peer, $limit ) as $m ) {
			$n = $mt->normalize_message( $m );
			if ( ! $n || ! self::is_file_message( $n ) ) { continue; }
			/* ۱۰.۸.۴ — فایل‌هایی که خودمان برای ربات فرستاده‌ایم، پاسخ نیستند (BUG-3). */
			if ( ! empty( $n['out'] ) ) { continue; }
			if ( (int) ( $n['date'] ?? 0 ) < $min_date ) { continue; }
			$out[] = $n;
		}
		return $out;
	}

	/** فایل = داکیومنت/ویدیو/صوت/انیمیشن، و عکسی که نام فایل دارد. */
	public static function is_file_message( $n ) {
		$type = (string) ( $n['media_type'] ?? '' );
		if ( in_array( $type, array( 'document', 'video', 'audio', 'voice', 'animation' ), true ) ) { return true; }
		if ( 'photo' === $type && ! empty( $n['file_name'] ) ) { return true; }
		return false;
	}

	protected static function scan_dialogs( $mt, $limit = 100 ) {
		$peers = array();
		$inline = array();
		$res = $mt->safe_dialogs( $limit );
		foreach ( (array) ( $res['dialogs'] ?? array() ) as $d ) {
			$peer = $d['peer'] ?? array();
			$id = 0;
			$is_user = false;
			if ( ! empty( $peer['user_id'] ) )        { $id = (int) $peer['user_id']; $is_user = true; }
			elseif ( ! empty( $peer['chat_id'] ) )    { $id = (int) $peer['chat_id']; }
			elseif ( ! empty( $peer['channel_id'] ) ) { $id = (int) $peer['channel_id']; }
			if ( $id ) { $peers[ $id ] = $is_user; }
		}
		// چت‌های خصوصی (ربات‌ها) اول بررسی شوند
		arsort( $peers );

		foreach ( (array) ( $res['messages'] ?? array() ) as $m ) {
			$n = $mt->normalize_message( $m );
			if ( ! $n || ! self::is_file_message( $n ) ) { continue; }
			$n['sender_chat_id'] = (int) ( $m['peer_id']['user_id'] ?? $m['peer_id']['chat_id'] ?? $m['peer_id']['channel_id'] ?? 0 );
			$n['source'] = 'dialog-top';
			$inline[] = $n;
		}
		return array( 'peers' => $peers, 'inline_docs' => $inline );
	}

	/* ---------------- تطبیق امتیازی ---------------- */

	public static function match( $doc, $queue ) {
		$candidates = self::codes_in_doc( $doc );
		$waiting = array();
		foreach ( (array) $queue as $i => $item ) {
			if ( empty( $item['pressed'] ) || ! empty( $item['error'] ) ) { continue; }
			$waiting[ $i ] = $item;
		}
		if ( empty( $waiting ) ) {
			return array( 'index' => -1, 'confidence' => 0, 'reason' => 'صف انتظار خالی است' );
		}

		/* ۱) کد دقیق */
		if ( ! empty( $candidates ) ) {
			foreach ( $waiting as $i => $item ) {
				$code = (string) ( $item['code'] ?? '' );
				if ( '' !== $code && in_array( $code, $candidates, true ) ) {
					return array( 'index' => $i, 'confidence' => 100, 'reason' => 'کد فایل ' . $code . ' در پیام ربات' );
				}
			}
		}

		/* ۲) پاسخ به پیام کانال */
		$reply_to = (int) ( $doc['reply_to_msg_id'] ?? 0 );
		if ( $reply_to ) {
			foreach ( $waiting as $i => $item ) {
				if ( (int) ( $item['msg_id'] ?? 0 ) === $reply_to ) {
					return array( 'index' => $i, 'confidence' => 95, 'reason' => 'پاسخ به پیام کانال' );
				}
			}
		}

		/* ۳) شباهت نام فایل با عنوان سشن */
		$fname = mb_strtolower( (string) ( $doc['file_name'] ?? '' ) . ' ' . (string) ( $doc['text'] ?? '' ) );
		if ( '' !== trim( $fname ) ) {
			$best_i = -1; $best = 0;
			foreach ( $waiting as $i => $item ) {
				$title = mb_strtolower( (string) ( $item['file_name'] ?? '' ) );
				if ( '' === trim( $title ) ) { continue; }
				$tokens = array_filter( preg_split( '/[^a-z0-9]+/', $title ), function ( $t ) { return mb_strlen( $t ) >= 4; } );
				if ( empty( $tokens ) ) { continue; }
				$hits = 0;
				foreach ( $tokens as $t ) {
					if ( false !== mb_strpos( $fname, $t ) ) { $hits++; }
				}
				$ratio = (int) round( 100 * $hits / max( 1, count( $tokens ) ) );
				if ( $ratio > $best ) { $best = $ratio; $best_i = $i; }
			}
			if ( $best_i >= 0 && $best >= 50 ) {
				return array( 'index' => $best_i, 'confidence' => (int) min( 90, 40 + $best / 2 ), 'reason' => 'شباهت نام فایل (' . $best . '٪)' );
			}
		}

		/* ۴) تنها یک مورد در انتظار */
		if ( 1 === count( $waiting ) ) {
			$keys = array_keys( $waiting );
			return array( 'index' => (int) $keys[0], 'confidence' => 85, 'reason' => 'تنها یک فایل در انتظار بود' );
		}

		/* ۵) ترتیب زمانی فشار دکمه */
		$doc_date = (int) ( $doc['date'] ?? time() );
		$ordered = $waiting;
		uasort( $ordered, function ( $a, $b ) { return (int) ( $a['press_ts'] ?? 0 ) <=> (int) ( $b['press_ts'] ?? 0 ); } );
		foreach ( $ordered as $i => $item ) {
			$pts = (int) ( $item['press_ts'] ?? 0 );
			if ( $pts && $doc_date >= $pts - 15 ) {
				return array( 'index' => (int) $i, 'confidence' => 60, 'reason' => 'تطبیق ترتیبی با فشار دکمه' );
			}
		}

		return array( 'index' => -1, 'confidence' => 0, 'reason' => 'هیچ تطبیقی پیدا نشد' );
	}

	public static function codes_in_doc( $doc ) {
		$out = array();
		foreach ( array( (string) ( $doc['text'] ?? '' ), (string) ( $doc['file_name'] ?? '' ) ) as $h ) {
			if ( '' === trim( $h ) ) { continue; }
			if ( class_exists( 'STI_Caption_Parser' ) ) {
				$p = STI_Caption_Parser::parse( $h );
				$c = trim( (string) ( $p['file_code'] ?? '' ) );
				if ( '' !== $c ) { $out[] = $c; }
			}
			if ( preg_match_all( '/(?<![0-9])([0-9]{5,})(?![0-9])/', $h, $m ) ) {
				foreach ( $m[1] as $num ) { $out[] = $num; }
			}
			if ( preg_match_all( '/([A-Za-z]{2,}[-_]?[0-9]{4,})/', $h, $m2 ) ) {
				foreach ( $m2[1] as $mix ) {
					$out[] = $mix;
					if ( preg_match( '/([0-9]{4,})/', $mix, $dm ) ) { $out[] = $dm[1]; }
				}
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/* ---------------- زنجیره‌ی دانلود ---------------- */

	public static function download( $mt, $doc, $dest_dir ) {
		$mad = $mt->client();
		if ( is_wp_error( $mad ) ) { return $mad; }

		if ( ! is_dir( $dest_dir ) ) { wp_mkdir_p( $dest_dir ); }
		$name = ! empty( $doc['file_name'] ) ? sanitize_file_name( $doc['file_name'] ) : ( 'gi_' . (int) ( $doc['id'] ?? 0 ) . '.bin' );
		$dest = trailingslashit( $dest_dir ) . $name;

		$errors = array();

		/* ۱۰.۸.۳: کران‌دار — نه بی‌کران. مهلت دقیق دانلود با guard پایین. */
		@set_time_limit( class_exists( 'STI_MTProto' ) ? STI_MTProto::MAX_PHP_SECONDS : 590 );

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$media = self::media_payload( $mt, $doc, $attempt > 1 );
			if ( empty( $media ) ) {
				$errors[] = 'رسانه‌ی پیام خالی است';
				sleep( 2 );
				continue;
			}

			/*
			 * نکته‌ی حیاتی: MadelineProto متدها را با __call می‌گیرد، پس method_exists
			 * همیشه false است و نمی‌شود به آن اعتماد کرد. علت خطای
			 * «The endpoint does not exist!» هم همین بود: نام متد دانلود بین نسخه‌های
			 * MadelineProto عوض شده (camelCase در v8/v9، snake_case در v7).
			 * پس همه‌ی نام‌های محتمل به‌ترتیب امتحان می‌شوند.
			 */
			$methods = array(
				'downloadToFile'   => function () use ( $mad, $media, $dest ) { return $mad->downloadToFile( $media, $dest ); },
				'download_to_file' => function () use ( $mad, $media, $dest ) { return $mad->download_to_file( $media, $dest ); },
				'downloadToDir'    => function () use ( $mad, $media, $dest_dir ) { return $mad->downloadToDir( $media, untrailingslashit( $dest_dir ) ); },
				'download_to_dir'  => function () use ( $mad, $media, $dest_dir ) { return $mad->download_to_dir( $media, untrailingslashit( $dest_dir ) ); },
				'downloadToStream' => function () use ( $mad, $media, $dest ) {
					$fp = fopen( $dest, 'wb' );
					if ( ! $fp ) { throw new RuntimeException( 'فایل مقصد قابل نوشتن نیست' ); }
					try { $mad->downloadToStream( $media, $fp ); } finally { fclose( $fp ); }
					return $dest;
				},
			);

			foreach ( $methods as $label => $fn ) {
				/* ۱۰.۸.۳: هر تلاش دانلود مهلت کران‌دار دارد (اگر Deadline در دسترس بود). */
				try {
					if ( class_exists( 'STI_GS_Deadline' ) ) {
						try {
							$path = STI_GS_Deadline::guard( $fn, 560, 'file_download' );
						} catch ( \STI_GS_Deadline_Exception $e ) {
							$errors[] = $label . ': مهلت دانلود تمام شد';
							continue;
						}
					} else {
						$path = $fn();
					}
					$ok = ( is_string( $path ) && $path && @is_file( $path ) && STI_Security::safe_file_size( $path ) > 0 ) ? $path : '';
					if ( ! $ok && @is_file( $dest ) && STI_Security::safe_file_size( $dest ) > 0 ) { $ok = $dest; }
					if ( $ok ) {
						STI_Logger::info( 'شکارچی فایل: دانلود موفق با روش ' . $label . ' — ' . basename( $ok ) );
						return array(
							'path'   => $ok,
							'name'   => basename( $ok ),
							'size'   => STI_Security::safe_file_size( $ok ),
							'type'   => (string) ( $doc['media_type'] ?? 'document' ),
							'method' => $label,
						);
					}
					$errors[] = $label . ': فایل خالی';
				} catch ( Throwable $e ) {
					$msg = $e->getMessage();
					if ( false !== stripos( $msg, 'endpoint does not exist' ) || false !== stripos( $msg, 'undefined method' ) ) {
						continue; // این نام متد در این نسخه نیست — بعدی
					}
					$errors[] = $label . ': ' . $msg;
				}
			}
			usleep( 900000 * $attempt );
		}

		return new WP_Error( 'sti_hunter_download', 'دانلود ناموفق — ' . implode( ' | ', array_slice( array_unique( $errors ), 0, 3 ) ) );
	}

	/**
	 * استخراج رسانه‌ی قابل دانلود؛ اگر file_reference منقضی شده باشد پیام
	 * تازه از سرور گرفته می‌شود (علت اصلی «Not Found / FILE_REFERENCE_EXPIRED»).
	 */
	protected static function media_payload( $mt, $doc, $force_refresh = false ) {
		$raw = is_array( $doc['raw'] ?? null ) ? $doc['raw'] : array();
		if ( $force_refresh || empty( $raw['media'] ) ) {
			$fresh = $mt->refresh_message( $doc['sender_chat_id'] ?? null, (int) ( $doc['id'] ?? 0 ) );
			if ( is_array( $fresh ) && ! empty( $fresh['media'] ) ) { $raw = $fresh; }
		}
		return ! empty( $raw['media'] ) ? $raw : array();
	}
}
