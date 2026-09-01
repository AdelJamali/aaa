<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Importek — simple, chronological importer.
 *
 * Workflow: scan the complete MTProto history oldest-to-newest, find a photo+
 * text followed by an archive, download both assets, preserve/rewrite the
 * source content, build a WooCommerce product and enqueue it in the existing
 * publication queue. It is intentionally separate from Channel Import's
 * Fileech button workflow.
 */
class STI_Importek {

	const JOB_TABLE_KEY = 'sti_importek_jobs';
	const SOURCE_TABLE_KEY = 'sti_importek_sources';
	const ITEM_TABLE_KEY = 'sti_importek_items';
	const DB_VER_KEY = 'sti_importek_db_ver';
	const DB_VER = '1.1';
	const MAX_ITEMS = 2000;
	const HISTORY_PAGE = 50;
	const PROCESS_PER_CHUNK = 1;

	protected static $instance;

	public static function instance() {
		if ( ! self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'wp_ajax_sti_importek_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_sti_importek_poll', array( $this, 'ajax_poll' ) );
		add_action( 'wp_ajax_sti_importek_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_sti_importek_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_sti_importek_process_now', array( $this, 'ajax_process_now' ) );
		add_action( 'sti_importek_worker', array( $this, 'process_job' ), 10, 1 );
	}

	public static function jobs_table() { global $wpdb; return $wpdb->prefix . self::JOB_TABLE_KEY; }
	public static function sources_table() { global $wpdb; return $wpdb->prefix . self::SOURCE_TABLE_KEY; }
	public static function items_table() { global $wpdb; return $wpdb->prefix . self::ITEM_TABLE_KEY; }

	public static function install() {
		global $wpdb;
		$tables = array( self::jobs_table(), self::sources_table(), self::items_table() );
		$complete = true;
		foreach ( $tables as $table_check ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_check ) ) !== $table_check ) { $complete = false; break; }
		}
		if ( get_option( self::DB_VER_KEY ) === self::DB_VER && $complete ) { return; }
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$jobs = self::jobs_table();
		$sources = self::sources_table();
		$items = self::items_table();
		$sql1 = "CREATE TABLE {$jobs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			identifier VARCHAR(255) NOT NULL,
			username VARCHAR(190) NULL,
			invite_hash VARCHAR(190) NULL,
			chat_id BIGINT NOT NULL DEFAULT 0,
			channel_title VARCHAR(255) NULL,
			category_id BIGINT UNSIGNED NOT NULL,
			keyword VARCHAR(190) NOT NULL,
			use_ai TINYINT(1) NOT NULL DEFAULT 1,
			max_items INT UNSIGNED NOT NULL DEFAULT 500,
			stage VARCHAR(30) NOT NULL DEFAULT 'resolve',
			status VARCHAR(30) NOT NULL DEFAULT 'queued',
			next_offset_id BIGINT NOT NULL DEFAULT 0,
			scanned INT UNSIGNED NOT NULL DEFAULT 0,
			matched INT UNSIGNED NOT NULL DEFAULT 0,
			imported INT UNSIGNED NOT NULL DEFAULT 0,
			failed INT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status_stage (status, stage, id),
			KEY chat_keyword (chat_id, keyword)
		) {$charset};";
		$sql2 = "CREATE TABLE {$sources} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL,
			source_chat_id BIGINT NOT NULL,
			message_id BIGINT NOT NULL,
			date_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
			text LONGTEXT NULL,
			media_type VARCHAR(30) NOT NULL DEFAULT 'none',
			file_name VARCHAR(255) NULL,
			file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			raw_payload LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY job_message (job_id, message_id),
			KEY job_order (job_id, message_id),
			KEY job_media (job_id, media_type)
		) {$charset};";
		$sql3 = "CREATE TABLE {$items} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL,
			source_chat_id BIGINT NOT NULL,
			photo_message_id BIGINT NOT NULL DEFAULT 0,
			text_message_id BIGINT NOT NULL DEFAULT 0,
			file_message_id BIGINT NOT NULL DEFAULT 0,
			file_code VARCHAR(100) NOT NULL,
			title TEXT NULL,
			description LONGTEXT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'ready',
			session_id BIGINT UNSIGNED DEFAULT NULL,
			product_id BIGINT UNSIGNED DEFAULT NULL,
			error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY job_file (job_id, file_message_id),
			KEY job_status (job_id, status, id),
			KEY file_code (file_code)
		) {$charset};";
		dbDelta( $sql1 );
		dbDelta( $sql2 );
		dbDelta( $sql3 );
		update_option( self::DB_VER_KEY, self::DB_VER, false );
	}

	public function get_job( $id ) {
		global $wpdb;
		self::install();
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::jobs_table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	public function get_jobs( $limit = 50 ) {
		global $wpdb;
		self::install();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::jobs_table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 100, (int) $limit ) ) ), ARRAY_A );
	}

	protected function update_job( $id, $data ) {
		global $wpdb;
		$allowed = array( 'chat_id','channel_title','stage','status','next_offset_id','scanned','matched','imported','failed','last_error','updated_at' );
		$row = array();
		foreach ( $data as $k => $v ) { if ( in_array( $k, $allowed, true ) ) { $row[ $k ] = $v; } }
		if ( empty( $row ) ) { return 0; }
		$row['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::jobs_table(), $row, array( 'id' => (int) $id ) );
	}

	protected function save_source( $job_id, $peer, $message ) {
		global $wpdb;
		if ( empty( $message['id'] ) ) { return 0; }
		$raw = $message['raw'] ?? $message;
		$row = array(
			'job_id' => (int) $job_id,
			'source_chat_id' => (int) $peer,
			'message_id' => (int) $message['id'],
			'date_ts' => (int) ( $message['date'] ?? 0 ),
			'text' => (string) ( $message['text'] ?? '' ),
			'media_type' => sanitize_key( $message['media_type'] ?? 'none' ),
			'file_name' => mb_substr( sanitize_text_field( $message['file_name'] ?? '' ), 0, 250 ),
			'file_size' => (int) ( $message['file_size'] ?? 0 ),
			'raw_payload' => wp_json_encode( $raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'created_at' => current_time( 'mysql' ),
		);
		$wpdb->insert( self::sources_table(), $row );
		return (int) $wpdb->insert_id;
	}

	protected function source_rows( $job_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::sources_table() . ' WHERE job_id = %d ORDER BY message_id ASC', (int) $job_id ), ARRAY_A );
		if ( false === $rows ) { STI_Logger::error( 'Importek: خواندن جدول پیام‌های منبع ناموفق بود: ' . $wpdb->last_error ); return null; }
		return is_array( $rows ) ? $rows : array();
	}

	protected function item_count( $job_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::items_table() . ' WHERE job_id = %d', (int) $job_id ) );
	}

	protected function ready_items( $job_id, $limit = 1 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::items_table() . " WHERE job_id = %d AND status = 'ready' ORDER BY id ASC LIMIT %d", (int) $job_id, max( 1, min( 10, (int) $limit ) ) ), ARRAY_A );
	}

	protected function update_item( $id, $data ) {
		global $wpdb;
		$allowed = array( 'status','session_id','product_id','error_message','updated_at' );
		$row = array();
		foreach ( $data as $k => $v ) { if ( in_array( $k, $allowed, true ) ) { $row[ $k ] = $v; } }
		if ( empty( $row ) ) { return 0; }
		$row['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::items_table(), $row, array( 'id' => (int) $id ) );
	}

	public function start_job( $identifier, $keyword, $category_id, $max_items = 500, $use_ai = 1 ) {
		global $wpdb;
		self::install();
		$identifier = trim( (string) $identifier );
		$keyword = trim( sanitize_text_field( $keyword ) );
		$category_id = (int) $category_id;
		$category = STI_Category::get( $category_id );
		if ( ! $category || empty( $category->is_active ) ) { return new WP_Error( 'sti_importek_category', 'دسته‌بندی انتخاب‌شده معتبر یا فعال نیست.' ); }
		if ( '' === $identifier || '' === $keyword || ! $category_id ) { return new WP_Error( 'sti_importek_input', 'آدرس، کلمه کلیدی و دسته‌بندی الزامی است.' ); }
		$parsed = class_exists( 'STI_Channel_Import' ) ? STI_Channel_Import::instance()->parse_chat_identifier( $identifier ) : array( 'username' => '', 'invite_hash' => '' );
		if ( empty( $parsed['username'] ) && empty( $parsed['is_join_link'] ) ) { return new WP_Error( 'sti_importek_channel', 'آدرس کانال یا گروه معتبر نیست.' ); }
		$max_items = max( 1, min( self::MAX_ITEMS, (int) $max_items ) );
		$now = current_time( 'mysql' );
		$wpdb->insert( self::jobs_table(), array(
			'identifier' => $identifier,
			'username' => sanitize_text_field( $parsed['username'] ?? '' ),
			'invite_hash' => sanitize_text_field( $parsed['invite_hash'] ?? '' ),
			'category_id' => $category_id,
			'keyword' => $keyword,
			'use_ai' => ! empty( $use_ai ) ? 1 : 0,
			'max_items' => $max_items,
			'stage' => 'resolve',
			'status' => 'queued',
			'created_at' => $now,
			'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { return new WP_Error( 'sti_importek_db', 'ساخت Job ایمپورتک ناموفق بود.' ); }
		$this->schedule( $id, 1 );
		return $this->get_job( $id );
	}

	protected function schedule( $job_id, $delay = 2 ) {
		if ( ! wp_next_scheduled( 'sti_importek_worker', array( (int) $job_id ) ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), 'sti_importek_worker', array( (int) $job_id ) );
		}
	}

	public function process_job( $job_id ) {
		$job = $this->get_job( $job_id );
		if ( ! $job || ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) { return; }
		$lock = 'sti_importek_lock_' . (int) $job_id;
		if ( get_transient( $lock ) ) { $this->schedule( $job_id, 15 ); return; }
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );
		try {
			$mt = STI_MTProto::instance();
			$stage = (string) ( $job['stage'] ?? 'resolve' );
			$peer = (int) ( $job['chat_id'] ?? 0 );
			if ( ! $peer ) {
				$identifier = ! empty( $job['invite_hash'] ) ? 'https://t.me/+' . $job['invite_hash'] : $job['username'];
				$info = $mt->chat_info( $identifier );
				if ( is_wp_error( $info ) ) { $this->fail_job( $job_id, 'پیدا کردن کانال ناموفق بود: ' . $info->get_error_message() ); return; }
				$peer = (int) $info['id'];
				$this->update_job( $job_id, array( 'chat_id' => $peer, 'channel_title' => $info['title'] ?? '', 'stage' => 'scan', 'status' => 'running' ) );
				$job = $this->get_job( $job_id );
				$stage = 'scan';
			}

			if ( 'scan' === $stage ) {
				$history = $mt->get_history( $peer, self::HISTORY_PAGE, (int) ( $job['next_offset_id'] ?? 0 ) );
				if ( is_wp_error( $history ) ) { $this->fail_job( $job_id, 'خواندن تاریخچه ناموفق بود: ' . $history->get_error_message() ); return; }
				$messages = (array) ( $history['messages'] ?? array() );
				if ( empty( $messages ) ) {
					$this->update_job( $job_id, array( 'stage' => 'assemble', 'status' => 'running' ) );
				} else {
					foreach ( $messages as $message ) {
						// Keep all text/media needed for deterministic chronological grouping.
						if ( ( $message['text'] ?? '' ) || 'none' !== ( $message['media_type'] ?? 'none' ) ) { $this->save_source( $job_id, $peer, $message ); }
					}
					$last = end( $messages );
				$this->update_job( $job_id, array( 'next_offset_id' => (int) ( $last['id'] ?? 0 ), 'scanned' => (int) $job['scanned'] + count( $messages ) ) );
				}
			}

			$job = $this->get_job( $job_id );
			if ( $job && 'assemble' === $job['stage'] ) { $this->assemble_items( $job ); }
			$job = $this->get_job( $job_id );
			if ( $job && 'process' === $job['stage'] ) { $this->process_one_item( $job ); }
			$job = $this->get_job( $job_id );
			if ( $job && in_array( $job['status'], array( 'queued', 'running' ), true ) && 'done' !== $job['stage'] ) { $this->schedule( $job_id, 'process' === $job['stage'] ? 2 : 1 ); }
		} catch ( \Throwable $e ) {
			$this->fail_job( $job_id, 'خطای ایمپورتک: ' . $e->getMessage() );
		} finally {
			delete_transient( $lock );
		}
	}

	protected function fail_job( $job_id, $message ) {
		$this->update_job( $job_id, array( 'status' => 'error', 'stage' => 'done', 'last_error' => mb_substr( (string) $message, 0, 1000 ) ) );
		STI_Logger::error( 'Importek #' . (int) $job_id . ': ' . $message );
	}

	protected function normalize_search_text( $text ) {
		$text = mb_strtolower( (string) $text );
		$text = str_replace( array( 'ي', 'ى', 'ك', 'ۀ', 'ة' ), array( 'ی', 'ی', 'ک', 'ه', 'ه' ), $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	protected function contains_keyword( $text, $keyword ) {
		$text = $this->normalize_search_text( $text );
		$keyword = $this->normalize_search_text( $keyword );
		return '' !== $keyword && false !== mb_strpos( $text, $keyword );
	}

	protected function is_archive( $file_name ) {
		$ext = strtolower( pathinfo( (string) $file_name, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'zip', 'rar', '7z' ), true );
	}

	protected function parse_content( $text ) {
		$text = trim( (string) $text );
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ) ) );
		$title = '';
		$description = '';
		foreach ( $lines as $line ) {
			if ( preg_match( '/^(?:title|name|نام|عنوان)\s*[:：]\s*(.+)$/iu', $line, $m ) ) { $title = trim( $m[1] ); continue; }
			if ( preg_match( '/^(?:description|توضیحات|شرح)\s*[:：]\s*(.*)$/iu', $line, $m ) ) { $description = trim( $m[1] ); continue; }
			if ( '' === $title ) { $title = $line; } else { $description .= ( $description ? "\n" : '' ) . $line; }
		}
		if ( '' === $title ) { $title = 'فایل گرافیکی'; }
		return array( 'title' => $title, 'description' => trim( $description ?: $text ) );
	}

	protected function clean_source_description( $text ) {
		$lines = preg_split( '/
|
|
/', (string) $text );
		$kept = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) { continue; }
			// Remove channel promotion lines such as:
			// Get any [UI8.net](http://UI8.net) file → @get_ui8_bot
			if ( preg_match( '/(?:get\s+any|دریافت\s+هر|دانلود\s+هر).*(?:https?:\/\/|@\w*bot\b|t\.me)/iu', $line ) ) { continue; }
			if ( preg_match( '/^\s*(?:https?:\/\/|www\.)\S+\s*$/iu', $line ) ) { continue; }
			$line = preg_replace( '/\[([^\]]+)\]\([^\)]+\)/u', '$1', $line );
			$line = preg_replace( '/https?:\/\/\S+/iu', '', $line );
			$line = preg_replace( '/@\w*bot\b/iu', '', $line );
			$line = trim( preg_replace( '/\s{2,}/u', ' ', $line ) );
			if ( '' !== $line ) { $kept[] = $line; }
		}
		return trim( implode( "\n", $kept ) );
	}

	protected function format_importek_title( $raw_title, $ai_title, $keyword ) {
		$raw_title = trim( (string) $raw_title );
		$subject = trim( (string) $ai_title );
		$subject = preg_replace( '/^(?:دانلود|download)\s+/iu', '', $subject );
		$subject = preg_replace( '/^\s*(?:فایل|file)\s+/iu', '', $subject );
		$subject = trim( preg_replace( '/\s+/u', ' ', $subject ) );
		$subject = trim( $subject, "[]() \t\r\n" );
		$hay = mb_strtolower( $raw_title . ' ' . $keyword . ' ' . $subject );
		$is_ui = false !== mb_stripos( $hay, 'ui' ) || false !== mb_stripos( $hay, 'user interface' ) || false !== mb_strpos( $hay, 'رابط کاربری' );
		$subject = preg_replace( '/\b(?:ui\s*kit|ui|user\s*interface|interface)\b/iu', '', $subject );
		$subject = preg_replace( '/رابط\s*کاربری/u', '', $subject );
		$subject = trim( preg_replace( '/\s+/u', ' ', $subject ) );
		if ( '' === $subject ) { $subject = trim( preg_replace( '/\b(?:ui\s*kit|ui)\b/iu', '', $raw_title ) ); }
		if ( '' === $subject ) { $subject = 'بدون عنوان'; }
		$type = $is_ui ? 'رابط کاربری' : 'فایل';
		return 'دانلود ' . $type . ' با موضوع ' . $subject;
	}

	protected function make_content( $title, $description, $category, $use_ai = 1, $keyword = '' ) {
		$raw_title = trim( preg_replace( '/^(?:دانلود|download)\s+/iu', '', (string) $title ) );
		$clean_description = $this->clean_source_description( $description );
		$ai_title = $raw_title;
		$ai_description = $clean_description;
		if ( $use_ai && class_exists( 'STI_AI' ) && STI_AI::is_ready() ) {
			$ai = STI_Content_Generator::ai_improve_title_and_description( $raw_title, $raw_title, 'ZIP', $category ? $category->telegram_label : '' );
			if ( is_array( $ai ) ) {
				if ( ! empty( $ai['title'] ) ) { $ai_title = $ai['title']; }
				if ( ! empty( $ai['description'] ) ) { $ai_description = $this->clean_source_description( $ai['description'] ); }
			}
		}
		$final_title = $this->format_importek_title( $raw_title, $ai_title, $keyword );
		$english_title = 'عنوان اصلی فایل: ' . $raw_title;
		$final_description = trim( $english_title . "\n\n" . $ai_description );
		return array( 'title' => $final_title, 'description' => $final_description );
	}

	protected function assemble_items( $job ) {
		global $wpdb;
		$rows = $this->source_rows( (int) $job['id'] );
		if ( null === $rows ) { $this->fail_job( $job['id'], 'خواندن پیام‌های ذخیره‌شده ایمپورتک ناموفق بود؛ جدول‌ها را بررسی کنید.' ); return; }
		if ( empty( $rows ) ) { $this->update_job( $job['id'], array( 'status' => 'partial', 'stage' => 'done', 'last_error' => 'هیچ پیام متنی/رسانه‌ای در تاریخچه پیدا نشد.' ) ); return; }
		$keyword = $job['keyword'];
		$used_files = array();
		$existing = $wpdb->get_col( $wpdb->prepare( 'SELECT file_message_id FROM ' . self::items_table() . ' WHERE job_id = %d', (int) $job['id'] ) );
		foreach ( (array) $existing as $id ) { $used_files[ (int) $id ] = true; }
		$matched = 0;
		$count = count( $rows );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( $matched >= (int) $job['max_items'] ) { break; }
			$current = $rows[ $i ];
			$blob = (string) $current['text'] . ' ' . (string) $current['file_name'];
			if ( ! $this->contains_keyword( $blob, $keyword ) ) { continue; }
			$text_row = $current;
			$photo = ( 'photo' === $current['media_type'] ) ? $current : null;
			if ( ! $photo ) {
				for ( $p = max( 0, $i - 3 ); $p < $i; $p++ ) {
					if ( 'photo' === $rows[ $p ]['media_type'] ) { $photo = $rows[ $p ]; break; }
				}
			}
			$file = null;
			for ( $j = $i; $j < min( $count, $i + 9 ); $j++ ) {
				if ( 'document' === $rows[ $j ]['media_type'] && $this->is_archive( $rows[ $j ]['file_name'] ) ) { $file = $rows[ $j ]; break; }
			}
			if ( ! $photo || ! $file || isset( $used_files[ (int) $file['message_id'] ] ) ) { continue; }
			$content = $this->parse_content( $text_row['text'] );
			$category = STI_Category::get( (int) $job['category_id'] );
			$made = $this->make_content( $content['title'], $content['description'], $category, ! empty( $job['use_ai'] ), $keyword );
			$code = 'imk-' . abs( (int) $job['chat_id'] ) . '-' . (int) $file['message_id'];
			$wpdb->insert( self::items_table(), array(
				'job_id' => (int) $job['id'],
				'source_chat_id' => (int) $job['chat_id'],
				'photo_message_id' => (int) $photo['message_id'],
				'text_message_id' => (int) $text_row['message_id'],
				'file_message_id' => (int) $file['message_id'],
				'file_code' => $code,
				'title' => $made['title'],
				'description' => $made['description'],
				'status' => 'ready',
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			) );
			$used_files[ (int) $file['message_id'] ] = true;
			$matched++;
		}
		$this->update_job( $job['id'], array( 'matched' => $matched, 'stage' => $matched ? 'process' : 'done', 'status' => $matched ? 'running' : 'partial', 'last_error' => $matched ? '' : 'پیامی با ساختار عکس + متن + فایل فشرده پیدا نشد.' ) );
	}

	protected function decode_source( $row, $mt, $peer ) {
		$raw = ! empty( $row['raw_payload'] ) ? json_decode( $row['raw_payload'], true ) : array();
		$message = $raw ? $mt->normalize_message( $raw ) : null;
		if ( ! $message ) { return null; }
		$message['sender_chat_id'] = $peer;
		return $message;
	}

	protected function process_one_item( $job ) {
		global $wpdb;
		$items = $this->ready_items( (int) $job['id'], self::PROCESS_PER_CHUNK );
		if ( empty( $items ) ) {
			$this->update_job( $job['id'], array( 'stage' => 'done', 'status' => ( (int) $job['imported'] > 0 ? 'completed' : 'partial' ) ) );
			return;
		}
		$item = $items[0];
		$existing_product = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( 'STI-' . sanitize_title( $item['file_code'] ) ) : 0;
		$active_session = class_exists( 'STI_Session' ) ? STI_Session::get_active_by_file_code( $item['file_code'] ) : null;
		if ( ( $existing_product && 'trash' !== get_post_status( $existing_product ) ) || $active_session ) {
			$this->update_item( $item['id'], array( 'status' => 'skipped_duplicate', 'product_id' => $existing_product ?: null, 'error_message' => 'محصول یا Session فعال با این کد Importek وجود دارد.' ) );
			return;
		}
		if ( ! $this->update_item( $item['id'], array( 'status' => 'processing' ) ) ) { return; }
		$category = STI_Category::get( (int) $job['category_id'] );
		$mt = STI_MTProto::instance();
		$photo_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sources_table() . ' WHERE job_id = %d AND message_id = %d', (int) $job['id'], (int) $item['photo_message_id'] ), ARRAY_A );
		$file_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sources_table() . ' WHERE job_id = %d AND message_id = %d', (int) $job['id'], (int) $item['file_message_id'] ), ARRAY_A );
		$photo = $photo_row ? $this->decode_source( $photo_row, $mt, (int) $job['chat_id'] ) : null;
		$file = $file_row ? $this->decode_source( $file_row, $mt, (int) $job['chat_id'] ) : null;
		if ( ! $photo || ! $file ) { $this->item_error( $job['id'], $item['id'], 'رسانه‌های موردنیاز پیام پیدا نشدند.' ); return; }
		$session_id = STI_Session::create( 0, null, (int) $job['category_id'] );
		if ( ! $session_id ) { $this->item_error( $job['id'], $item['id'], 'ساخت Session ناموفق بود.' ); return; }
		STI_Session::update( $session_id, array( 'notify_chat_id' => 0, 'file_code' => $item['file_code'], 'file_name' => $item['title'], 'file_type' => 'ZIP', 'caption_raw' => $item['description'], 'product_title_override' => $item['title'], 'description_override' => $item['description'], 'status' => 'open' ) );
		$tmp_dir = trailingslashit( STI_MTProto::base_dir() ) . 'tmp';
		if ( ! is_dir( $tmp_dir ) ) { wp_mkdir_p( $tmp_dir ); }
		$img = $mt->download_media_robust( $photo, $tmp_dir );
		if ( is_wp_error( $img ) ) { STI_Session::mark_error( $session_id, $img->get_error_message() ); $this->item_error( $job['id'], $item['id'], $img->get_error_message(), $session_id ); return; }
		$att = STI_File_Storage::store_image_from_local_file( $img['path'], $item['title'], 'importek-' . $item['photo_message_id'] . '.jpg' );
		if ( is_wp_error( $att ) || ! $att ) { STI_Session::mark_error( $session_id, 'ذخیره تصویر ناموفق بود.' ); $this->item_error( $job['id'], $item['id'], 'ذخیره تصویر ناموفق بود.', $session_id ); return; }
		$zip = $mt->download_media_robust( $file, $tmp_dir );
		if ( is_wp_error( $zip ) ) { STI_Session::mark_error( $session_id, $zip->get_error_message() ); $this->item_error( $job['id'], $item['id'], $zip->get_error_message(), $session_id ); return; }
		$meta = array(
			'file_code' => $item['file_code'],
			'file_name' => $item['title'],
			'original_name' => $zip['name'] ?: ( 'importek-' . $item['file_message_id'] . '.zip' ),
			'category_folder' => $category ? ( $category->folder_key ?: STI_Category::sanitize_folder_key( $category->telegram_label, $category->id ) ) : '',
		);
		$stored = STI_File_Storage::process_local_temp_file( $zip['path'], $meta, $category ? STI_Category::storage_mode( $category ) : null );
		if ( is_wp_error( $stored ) ) { STI_Session::mark_error( $session_id, $stored->get_error_message() ); $this->item_error( $job['id'], $item['id'], $stored->get_error_message(), $session_id ); return; }
		@unlink( $zip['path'] );
		STI_Session::update( $session_id, array( 'image_file_id' => (string) $att, 'image_url' => wp_get_attachment_url( $att ) ?: '', 'download_url_final' => $stored['url'], 'file_size_bytes' => $stored['size_bytes'] ?? null, 'status' => 'processing' ) );
		$session = STI_Session::get( $session_id );
		$product_id = STI_Product_Builder::build( $session, $category );
		if ( is_wp_error( $product_id ) ) { STI_Session::mark_error( $session_id, $product_id->get_error_message() ); $this->item_error( $job['id'], $item['id'], $product_id->get_error_message(), $session_id ); return; }
		STI_Scheduler::enqueue( $session_id, $product_id );
		$this->update_item( $item['id'], array( 'status' => 'product_created', 'session_id' => $session_id, 'product_id' => $product_id, 'error_message' => '' ) );
		$this->update_job( $job['id'], array( 'imported' => (int) $job['imported'] + 1 ) );
		STI_Logger::success( 'Importek: محصول #' . $product_id . ' ساخته شد — job #' . $job['id'] );
	}

	protected function item_error( $job_id, $item_id, $message, $session_id = 0 ) {
		$this->update_item( $item_id, array( 'status' => 'error', 'session_id' => $session_id ?: null, 'error_message' => mb_substr( (string) $message, 0, 1000 ) ) );
		$job = $this->get_job( $job_id );
		$this->update_job( $job_id, array( 'failed' => (int) ( $job['failed'] ?? 0 ) + 1 ) );
		STI_Logger::error( 'Importek #' . $job_id . ' item #' . $item_id . ': ' . $message );
	}

	protected function check_ajax() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 ); }
	}

	public function pump_inline( $limit = 1 ) {
		$ran = 0;
		foreach ( $this->get_jobs( 50 ) as $job ) {
			if ( $ran >= $limit || ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) { continue; }
			$this->process_job( (int) $job['id'] );
			$ran++;
		}
		return $ran;
	}

	public function ajax_start() {
		$this->check_ajax();
		$result = $this->start_job(
			sanitize_text_field( wp_unslash( $_POST['identifier'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) ),
			(int) ( $_POST['category_id'] ?? 0 ),
			(int) ( $_POST['max_items'] ?? 500 ),
			! empty( $_POST['use_ai'] )
		);
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
		wp_send_json_success( array( 'message' => 'ایمپورتک شروع شد؛ تاریخچه از اولین پیام بررسی می‌شود.', 'job' => $result ) );
	}

	public function ajax_poll() {
		$this->check_ajax();
		$this->pump_inline( 1 );
		wp_send_json_success( array( 'jobs' => $this->get_jobs( 50 ) ) );
	}

	public function ajax_process_now() {
		$this->check_ajax();
		$ran = $this->pump_inline( 1 );
		wp_send_json_success( array( 'message' => $ran ? 'یک مرحله از ایمپورتک پردازش شد.' : 'Job فعالی وجود ندارد.', 'jobs' => $this->get_jobs( 50 ) ) );
	}

	public function ajax_status() {
		$this->check_ajax();
		$job = $this->get_job( (int) ( $_POST['id'] ?? 0 ) );
		if ( ! $job ) { wp_send_json_error( array( 'message' => 'Job پیدا نشد.' ) ); }
		wp_send_json_success( array( 'job' => $job ) );
	}

	public function ajax_cancel() {
		$this->check_ajax();
		$id = (int) ( $_POST['id'] ?? 0 );
		$job = $this->get_job( $id );
		if ( ! $job ) { wp_send_json_error( array( 'message' => 'Job پیدا نشد.' ) ); }
		$this->update_job( $id, array( 'status' => 'cancelled', 'stage' => 'done' ) );
		wp_send_json_success( array( 'message' => 'Job لغو شد.' ) );
	}
}
