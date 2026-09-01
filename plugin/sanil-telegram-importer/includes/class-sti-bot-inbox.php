<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI_Bot_Inbox — صندوق ورودی پایدار فایل‌های ربات (v7)
 *
 * مشکل نسخه‌های قبل: فایل‌ها فقط «در لحظه» اسکن می‌شدند. اگر ربات فایل را
 * زودتر از رسیدن به مرحله‌ی انتظار می‌فرستاد، یا اگر در همان چانک تطبیق پیدا
 * نمی‌شد، آن فایل برای همیشه گم می‌شد و سشن با خطای «فایل دریافت نشد» می‌مرد.
 *
 * راه‌حل: هر فایلی که از هر گفتگویی دیده می‌شود، فوراً در یک جدول دیتابیس ثبت
 * می‌شود (یک‌بار، با کلید یکتا peer+msg_id). تطبیق روی این «استخر پایدار»
 * انجام می‌گیرد، نه روی نتیجه‌ی لحظه‌ای اسکن. پس هیچ فایلی گم نمی‌شود؛
 * حتی اگر ۱۰ دقیقه بعد سراغش برویم.
 */
class STI_Bot_Inbox {

	const DB_VER_KEY   = 'sti_bot_inbox_db_ver';
	const DB_VER       = '1.4';
	const PEERS_OPTION = 'sti_bot_learned_peers';
	const KEEP_HOURS   = 72;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_bot_inbox';
	}

	public static function install() {
		global $wpdb;
		$table = self::table();
		// اگر قبلاً نسخه‌ی جدید ثبت شده ولی به هر دلیل ستون‌های تازه واقعاً
		// روی جدول اعمال نشدند (مثلاً dbDelta بی‌سروصدا نیمه‌کاره مانده)،
		// همان یک‌بار دوباره تلاش می‌کنیم — به‌جای اینکه برای همیشه رد شویم.
		$already_installed = get_option( self::DB_VER_KEY ) === self::DB_VER;
		if ( $already_installed ) {
			$has_col = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = %s AND table_name = %s AND column_name = 'telegram_document_id'",
				DB_NAME, $table
			) );
			if ( $has_col ) {
				return;
			}
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			peer VARCHAR(190) NOT NULL DEFAULT '',
			msg_id BIGINT NOT NULL DEFAULT 0,
			file_name VARCHAR(255) NULL,
			caption TEXT NULL,
			codes VARCHAR(255) NULL,
			size_bytes BIGINT UNSIGNED DEFAULT 0,
			date_ts BIGINT UNSIGNED DEFAULT 0,
			telegram_document_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			mime_type VARCHAR(120) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			session_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			batch_id VARCHAR(64) NULL,
			tries SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			claimed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			payload LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY peer_msg (peer, msg_id),
			KEY status_date (status, date_ts),
			KEY peer_date (peer, date_ts),
			KEY session_id (session_id),
			KEY telegram_document_id (telegram_document_id)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::DB_VER_KEY, self::DB_VER, false );
	}

	/** استخراج همه‌ی کدهای ممکن از کپشن و نام فایل. */
	public static function extract_codes( $text, $file_name = '' ) {
		$codes = array();
		$blob = (string) $text . ' ' . (string) $file_name;
		if ( preg_match_all( '/(?<!\d)(\d{5,})(?!\d)/', $blob, $m ) ) {
			foreach ( $m[1] as $c ) { $codes[ $c ] = true; }
		}
		if ( preg_match_all( '/\b([A-Za-z]{2,}[-_]?\d{4,})\b/', $blob, $m2 ) ) {
			foreach ( $m2[1] as $c ) {
				if ( preg_match( '/(\d{4,})/', $c, $dm ) ) { $codes[ $dm[1] ] = true; }
			}
		}
		if ( class_exists( 'STI_Caption_Parser' ) ) {
			$p = STI_Caption_Parser::parse( (string) $text );
			if ( ! empty( $p['file_code'] ) ) { $codes[ trim( $p['file_code'] ) ] = true; }
		}
		return array_keys( $codes );
	}

	/**
	 * ثبت یک فایل دیده‌شده. اگر قبلاً ثبت شده باشد دست نمی‌خورد.
	 * @return int  شناسه‌ی ردیف (۰ = ثبت نشد)
	 */
	public static function record( $doc ) {
		global $wpdb;
		self::install();
		$peer = (string) ( isset( $doc['sender_chat_id'] ) ? $doc['sender_chat_id'] : '' );
		$msg_id = (int) ( isset( $doc['id'] ) ? $doc['id'] : 0 );
		if ( '' === $peer || ! $msg_id ) { return 0; }

		$file_name = (string) ( isset( $doc['file_name'] ) ? $doc['file_name'] : '' );
		$caption = (string) ( isset( $doc['text'] ) ? $doc['text'] : '' );
		$codes = self::extract_codes( $caption, $file_name );
		$telegram_document_id = (int) ( $doc['telegram_document_id'] ?? 0 );

		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE peer = %s AND msg_id = %d LIMIT 1', $peer, $msg_id
		) );
		if ( $existing ) { return $existing; }

		// همان فایل فیزیکی، صرف‌نظر از این‌که peer/منبع Poll چه بود — دقیقاً
		// همان چیزی که باعث ردیف‌های تکراری واقعی در تست شد (peer های مختلف
		// برای یک telegram_document_id یکسان). این چک قبل از NULL/0 رد می‌شود.
		if ( $telegram_document_id > 0 ) {
			$existing_doc = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE telegram_document_id = %d LIMIT 1', $telegram_document_id
			) );
			if ( $existing_doc ) { return $existing_doc; }
		}

		$wpdb->insert( self::table(), array(
			'peer'       => $peer,
			'msg_id'     => $msg_id,
			'file_name'  => mb_substr( $file_name, 0, 250 ),
			'caption'    => mb_substr( $caption, 0, 2000 ),
			'codes'      => implode( ',', array_slice( $codes, 0, 12 ) ),
			'size_bytes' => (int) ( isset( $doc['file_size'] ) ? $doc['file_size'] : 0 ),
			'date_ts'    => (int) ( isset( $doc['date'] ) ? $doc['date'] : time() ),
			'telegram_document_id' => $telegram_document_id ?: null, // NULL نه ۰، چون sever ستون UNIQUE است و چند NULL مجاز است
			'mime_type'  => (string) ( $doc['mime_type'] ?? '' ),
			'status'     => 'new',
			'payload'    => wp_json_encode( $doc ),
			'created_at' => current_time( 'mysql' ),
		) );

		// اگر هم‌زمان یک worker دیگر روی UNIQUE(telegram_document_id) برنده شد
		// (بین SELECT بالا و همین INSERT) — به‌جای بازگرداندن ۰، همان ردیف
		// واقعی را پیدا و برگردان. این همان محافظت سطح-دیتابیس خواسته‌شده است.
		if ( ! $wpdb->insert_id && $telegram_document_id > 0 && false !== strpos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
			$winner = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE telegram_document_id = %d LIMIT 1', $telegram_document_id
			) );
			return $winner ?: 0;
		}

		if ( $wpdb->insert_id && $peer ) { self::learn_peer( $peer ); }
		return (int) $wpdb->insert_id;
	}

	public static function record_many( $docs ) {
		$n = 0;
		foreach ( (array) $docs as $d ) { if ( self::record( $d ) ) { $n++; } }
		return $n;
	}

	/**
	 * مثل record_many اما به‌ازای هر document دلیل دقیق ثبت/رد را برمی‌گرداند —
	 * برای دیباگ گلوگاه «Document Found → Bot Inbox Insert». هیچ منطق درج را
	 * تکرار نمی‌کند (خودِ record() را صدا می‌زند)، فقط قبل از آن طبقه‌بندی می‌کند.
	 */
	public static function record_many_verbose( $docs ) {
		global $wpdb;
		self::install();

		$report = array(
			'docs_seen'     => count( (array) $docs ),
			'docs_recorded' => 0,
			'docs_duplicate'=> 0,
			'docs_rejected' => 0,
			'items'         => array(),
		);

		foreach ( (array) $docs as $d ) {
			$peer      = (string) ( $d['sender_chat_id'] ?? '' );
			$msg_id    = (int) ( $d['id'] ?? 0 );
			$file_name = (string) ( $d['file_name'] ?? '' );
			$tg_doc_id = (int) ( $d['telegram_document_id'] ?? 0 );

			$item = array(
				'msg_id' => $msg_id, 'peer' => $peer, 'file_name' => $file_name,
				'telegram_document_id' => $tg_doc_id,
			);

			if ( '' === $peer || ! $msg_id ) {
				$item['result'] = 'rejected';
				$item['reason'] = ( '' === $peer ) ? 'missing_peer' : 'missing_msg_id';
				$report['docs_rejected']++;
				$report['items'][] = $item;
				continue;
			}

			$existing = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE peer = %s AND msg_id = %d LIMIT 1', $peer, $msg_id
			) );
			if ( $existing ) {
				$item['result']   = 'duplicate';
				$item['reason']   = 'already_in_inbox';
				$item['inbox_id'] = $existing;
				$report['docs_duplicate']++;
				$report['items'][] = $item;
				continue;
			}

			$id = self::record( $d );
			if ( $id ) {
				$item['result']   = 'recorded';
				$item['inbox_id'] = $id;
				$report['docs_recorded']++;
			} else {
				$item['result'] = 'rejected';
				$item['reason'] = $wpdb->last_error ? ( 'db_insert_failed: ' . $wpdb->last_error ) : 'unknown_record_failure';
				$report['docs_rejected']++;
			}
			$report['items'][] = $item;
		}

		return $report;
	}

	/** پیدا کردن ردیف مناسب برای یک کد مشخص (اولویت: کد دقیق). */
	public static function find_for_code( $code, $since_ts = 0 ) {
		global $wpdb;
		self::install();
		self::reclaim_stale_claims();
		$code = trim( (string) $code );
		if ( '' === $code ) { return null; }
		// Match a complete stored code, never a substring such as 12345 in 912345.
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . "
			 WHERE status = 'new' AND date_ts >= %d
			   AND FIND_IN_SET(%s, codes)
			 ORDER BY date_ts ASC LIMIT 1",
			(int) $since_ts, $code
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** ردیف‌های آزاد (برای تطبیق FIFO یا شباهت عنوان). */
	public static function unclaimed( $since_ts = 0, $limit = 40 ) {
		global $wpdb;
		self::install();
		self::reclaim_stale_claims();
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE status = 'new' AND date_ts >= %d ORDER BY date_ts ASC LIMIT %d",
			(int) $since_ts, (int) $limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function count_unclaimed( $since_ts = 0 ) {
		global $wpdb;
		self::install();
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'new' AND date_ts >= %d", (int) $since_ts
		) );
	}

	/** قفل‌گذاری اتمی روی یک ردیف تا دو سشن یک فایل را نگیرند. */
	public static function claim( $id, $session_id, $batch_id = '' ) {
		global $wpdb;
		$ok = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET status = 'claimed', session_id = %d, batch_id = %s, claimed_at = %d, tries = tries + 1 WHERE id = %d AND status = 'new'",
			(int) $session_id, (string) $batch_id, time(), (int) $id
		) );
		return (bool) $ok;
	}

	public static function release( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET status = 'new', session_id = 0, batch_id = NULL, claimed_at = 0 WHERE id = %d", (int) $id
		) );
	}

	public static function mark( $id, $status ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => (string) $status, 'claimed_at' => in_array( $status, array( 'claimed' ), true ) ? time() : 0 ), array( 'id' => (int) $id ) );
	}

	public static function payload( $row ) {
		if ( empty( $row['payload'] ) ) { return array(); }
		$d = json_decode( $row['payload'], true );
		return is_array( $d ) ? $d : array();
	}

	/* ============ یادگیری گفتگوهای ربات ============ */

	public static function learn_peer( $peer ) {
		$list = get_option( self::PEERS_OPTION, array() );
		if ( ! is_array( $list ) ) { $list = array(); }
		$key = (string) $peer;
		$list[ $key ] = time();
		if ( count( $list ) > 40 ) {
			arsort( $list );
			$list = array_slice( $list, 0, 40, true );
		}
		update_option( self::PEERS_OPTION, $list, false );
	}

	/** گفتگوهایی که باید برای فایل جدید اسکن شوند — یادگرفته + پیش‌فرض + دستی. */
	public static function bot_peers() {
		$learned = get_option( self::PEERS_OPTION, array() );
		$peers = is_array( $learned ) ? array_keys( $learned ) : array();
		$defaults = array( 'FileechBot', 'me' );
		$manual = class_exists( 'STI_Settings' ) ? (string) STI_Settings::get( 'ci_bot_usernames', '' ) : '';
		foreach ( preg_split( '/[\s,]+/', $manual ) as $m ) {
			$m = trim( $m, " @\t" );
			if ( '' !== $m ) { $defaults[] = $m; }
		}
		$all = array_merge( $defaults, $peers );
		$out = array();
		foreach ( $all as $p ) {
			$p = trim( (string) $p );
			if ( '' === $p || isset( $out[ $p ] ) ) { continue; }
			$out[ $p ] = true;
		}
		return array_keys( $out );
	}

	/** Release claims left behind by a killed PHP request. */
	public static function reclaim_stale_claims( $minutes = 30 ) {
		global $wpdb;
		self::install();
		$cut = time() - max( 5, (int) $minutes ) * MINUTE_IN_SECONDS;
		return $wpdb->query( $wpdb->prepare(
			"UPDATE " . self::table() . " SET status = 'new', session_id = 0, batch_id = NULL, claimed_at = 0 WHERE status = 'claimed' AND claimed_at > 0 AND claimed_at < %d",
			$cut
		) );
	}

	public static function cleanup() {
		global $wpdb;
		self::install();
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table() . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)', self::KEEP_HOURS
		) );
	}

	public static function stats() {
		global $wpdb;
		self::install();
		$t = self::table();
		return array(
			'new'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status='new'" ),
			'claimed'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status='claimed'" ),
			'downloaded' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status='downloaded'" ),
			'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ),
		);
	}

	/* ───────────────── Task 3: Duplicate telegram_document_id protection ───────────────── */

	/** فقط گزارش (Read-Only) — گروه‌های ردیف‌های تکراری با یک telegram_document_id مشترک. */
	public static function find_duplicate_document_groups() {
		global $wpdb;
		self::install();
		return $wpdb->get_results(
			'SELECT telegram_document_id, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id ASC) AS ids
			 FROM ' . self::table() . '
			 WHERE telegram_document_id > 0
			 GROUP BY telegram_document_id
			 HAVING COUNT(*) > 1',
			ARRAY_A
		);
	}

	/**
	 * ادغام امن گروه‌های تکراری: قدیمی‌ترین ردیف (کوچک‌ترین id) به‌عنوان
	 * canonical نگه داشته می‌شود. Candidateهایی که به ردیف‌های تکراری اشاره
	 * می‌کردند، به canonical منتقل می‌شوند (یا اگر تداخل با UNIQUE(session_id,
	 * inbox_id) داشتند، همان ردیف تکراری‌شان حذف می‌شود چون canonical از قبل
	 * برای همان Session پوشش دارد). هیچ داده‌ای بی‌گزارش پاک نمی‌شود.
	 */
	public static function dedupe_document_groups() {
		global $wpdb;
		self::install();
		$groups = self::find_duplicate_document_groups();
		$report = array( 'groups_found' => count( $groups ), 'merged' => array() );

		if ( ! class_exists( 'STI_GS_DB' ) ) {
			$report['note'] = 'STI_GS_Bot_Candidate در دسترس نیست؛ فقط ردیف‌های inbox ادغام شدند، candidate دست‌نخورده ماند.';
		}

		foreach ( $groups as $g ) {
			$ids = array_map( 'intval', explode( ',', $g['ids'] ) );
			sort( $ids );
			$canonical = array_shift( $ids ); // کوچک‌ترین id = قدیمی‌ترین ردیف

			foreach ( $ids as $dup_id ) {
				if ( class_exists( 'STI_GS_DB' ) ) {
					$cand_table = STI_GS_DB::bot_candidates_table();
					$dup_candidates = $wpdb->get_results( $wpdb->prepare(
						"SELECT id, session_id FROM {$cand_table} WHERE inbox_id = %d", $dup_id
					), ARRAY_A );
					foreach ( $dup_candidates as $dc ) {
						$conflict = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT id FROM {$cand_table} WHERE session_id = %d AND inbox_id = %d", (int) $dc['session_id'], $canonical
						) );
						if ( $conflict ) {
							$wpdb->delete( $cand_table, array( 'id' => (int) $dc['id'] ) ); // canonical از قبل پوشش می‌دهد
						} else {
							$wpdb->update( $cand_table, array( 'inbox_id' => $canonical ), array( 'id' => (int) $dc['id'] ) );
						}
					}
				}
				$wpdb->delete( self::table(), array( 'id' => $dup_id ) );
			}

			$report['merged'][] = array( 'telegram_document_id' => $g['telegram_document_id'], 'canonical_id' => $canonical, 'removed_ids' => $ids );
		}

		return $report;
	}

	/**
	 * فقط بعد از اطمینان از نبود Duplicate صدا زده شود (dedupe_document_groups
	 * اول). ستون را NULL-پذیر می‌کند (به‌جای ۰) و UNIQUE KEY واقعی دیتابیسی
	 * اضافه می‌کند — محافظت سطح-DB، نه فقط SELECT→INSERT برنامه‌ای.
	 */
	public static function ensure_document_unique_index() {
		global $wpdb;
		self::install();
		$table = self::table();

		$dups = self::find_duplicate_document_groups();
		if ( ! empty( $dups ) ) {
			return new WP_Error( 'sti_dup_exists', count( $dups ) . ' گروه تکراری هنوز وجود دارد — اول dedupe_document_groups() را اجرا کن.' );
		}

		$wpdb->query( "UPDATE {$table} SET telegram_document_id = NULL WHERE telegram_document_id = 0" );
		$wpdb->query( "ALTER TABLE {$table} MODIFY telegram_document_id BIGINT UNSIGNED NULL DEFAULT NULL" );

		$has_index = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = %s AND table_name = %s AND index_name = 'telegram_document_id_unique'",
			DB_NAME, $table
		) );
		if ( ! $has_index ) {
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY telegram_document_id_unique (telegram_document_id)" );
		}

		return empty( $wpdb->last_error );
	}
}
