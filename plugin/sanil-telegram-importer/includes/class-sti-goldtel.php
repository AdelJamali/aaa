<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * GoldTel Control Center.
 *
 * A durable, two-step control plane around the existing Telegram/AutoCat/
 * Storage/Product/Scheduler services.  Indexing is read-only; dispatch and
 * product creation are explicit, resumable jobs.
 */
class STI_GoldTel {

	const DB_VER_KEY = 'sti_goldtel_db_ver';
	const DB_VER = '1.1';
	const PROFILE_TABLE_KEY = 'sti_goldtel_profiles';
	const INDEX_TABLE_KEY = 'sti_goldtel_index';
	const DISPATCH_TABLE_KEY = 'sti_goldtel_dispatches';
	const QUEUE_TABLE_KEY = 'sti_goldtel_queue';
	const HISTORY_PAGE = 50;
	const MAX_PROFILE_MESSAGES = 500000;
	const MAX_RETRIES = 20;

	protected static $instance;

	public static function instance() {
		if ( ! self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'wp_ajax_sti_goldtel_profile_start', array( $this, 'ajax_profile_start' ) );
		add_action( 'wp_ajax_sti_goldtel_profile_poll', array( $this, 'ajax_profile_poll' ) );
		add_action( 'wp_ajax_sti_goldtel_profile_cancel', array( $this, 'ajax_profile_cancel' ) );
		add_action( 'wp_ajax_sti_goldtel_index', array( $this, 'ajax_index' ) );
		add_action( 'wp_ajax_sti_goldtel_dispatch', array( $this, 'ajax_dispatch' ) );
		add_action( 'wp_ajax_sti_goldtel_retry', array( $this, 'ajax_retry' ) );
		add_action( 'wp_ajax_sti_goldtel_records', array( $this, 'ajax_records' ) );
		add_action( 'wp_ajax_sti_goldtel_dispatches', array( $this, 'ajax_dispatches' ) );
		add_action( 'wp_ajax_sti_goldtel_process_now', array( $this, 'ajax_process_now' ) );
		add_action( 'sti_goldtel_worker', array( $this, 'worker' ), 10, 1 );
		add_action( 'sti_goldtel_dispatch_worker', array( $this, 'dispatch_worker' ), 10, 1 );
	}

	public static function profile_table() { global $wpdb; return $wpdb->prefix . self::PROFILE_TABLE_KEY; }
	public static function index_table() { global $wpdb; return $wpdb->prefix . self::INDEX_TABLE_KEY; }
	public static function dispatch_table() { global $wpdb; return $wpdb->prefix . self::DISPATCH_TABLE_KEY; }
	public static function queue_table() { global $wpdb; return $wpdb->prefix . self::QUEUE_TABLE_KEY; }

	public static function install() {
		global $wpdb;
		$tables = array( self::profile_table(), self::index_table(), self::dispatch_table(), self::queue_table() );
		$complete = true;
		foreach ( $tables as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { $complete = false; break; }
		}
		if ( get_option( self::DB_VER_KEY ) === self::DB_VER && $complete ) { return; }
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$profiles = self::profile_table();
		$index = self::index_table();
		$dispatch = self::dispatch_table();
		$queue = self::queue_table();

		$sql1 = "CREATE TABLE {$profiles} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			identifier VARCHAR(255) NOT NULL,
			username VARCHAR(190) NULL,
			invite_hash VARCHAR(190) NULL,
			chat_id BIGINT NOT NULL DEFAULT 0,
			channel_title VARCHAR(255) NULL,
			keyword VARCHAR(190) NULL,
			category_id BIGINT UNSIGNED DEFAULT NULL,
			max_messages INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(30) NOT NULL DEFAULT 'draft',
			stage VARCHAR(30) NOT NULL DEFAULT 'resolve',
			next_offset_id BIGINT NOT NULL DEFAULT 0,
			total_messages INT UNSIGNED NOT NULL DEFAULT 0,
			with_photo INT UNSIGNED NOT NULL DEFAULT 0,
			with_file INT UNSIGNED NOT NULL DEFAULT 0,
			with_button INT UNSIGNED NOT NULL DEFAULT 0,
			with_code INT UNSIGNED NOT NULL DEFAULT 0,
			matched INT UNSIGNED NOT NULL DEFAULT 0,
			sent INT UNSIGNED NOT NULL DEFAULT 0,
			downloaded INT UNSIGNED NOT NULL DEFAULT 0,
			products_created INT UNSIGNED NOT NULL DEFAULT 0,
			failed INT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY status_stage (status, stage, id),
			KEY chat_id (chat_id)
		) {$charset};";
		$sql2 = "CREATE TABLE {$index} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			profile_id BIGINT UNSIGNED NOT NULL,
			source_chat_id BIGINT NOT NULL,
			source_message_id BIGINT NOT NULL,
			source_date BIGINT UNSIGNED NOT NULL DEFAULT 0,
			group_key VARCHAR(100) NULL,
			caption_raw LONGTEXT NULL,
			text_raw LONGTEXT NULL,
			file_name VARCHAR(255) NULL,
			file_type VARCHAR(80) NULL,
			file_code VARCHAR(100) NULL,
			site VARCHAR(190) NULL,
			source_url TEXT NULL,
			photo_message_id BIGINT NOT NULL DEFAULT 0,
			text_message_id BIGINT NOT NULL DEFAULT 0,
			file_message_id BIGINT NOT NULL DEFAULT 0,
			button_type VARCHAR(40) NULL,
			button_text TEXT NULL,
			button_url TEXT NULL,
			button_data TEXT NULL,
			bot_username VARCHAR(190) NULL,
			bot_payload VARCHAR(255) NULL,
			has_photo TINYINT(1) NOT NULL DEFAULT 0,
			has_text TINYINT(1) NOT NULL DEFAULT 0,
			has_file TINYINT(1) NOT NULL DEFAULT 0,
			has_button TINYINT(1) NOT NULL DEFAULT 0,
			has_file_code TINYINT(1) NOT NULL DEFAULT 0,
			file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			raw_payload LONGTEXT NULL,
			category_id BIGINT UNSIGNED DEFAULT NULL,
			autocat_category VARCHAR(100) NULL,
			confidence SMALLINT NOT NULL DEFAULT 0,
			matched_keywords LONGTEXT NULL,
			is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
			duplicate_reason TEXT NULL,
			index_status VARCHAR(30) NOT NULL DEFAULT 'indexed',
			dispatch_status VARCHAR(30) NOT NULL DEFAULT 'not_sent',
			download_status VARCHAR(30) NOT NULL DEFAULT 'not_downloaded',
			product_status VARCHAR(30) NOT NULL DEFAULT 'not_created',
			publish_status VARCHAR(30) NOT NULL DEFAULT 'not_published',
			product_id BIGINT UNSIGNED DEFAULT NULL,
			session_id BIGINT UNSIGNED DEFAULT NULL,
			content_title TEXT NULL,
			content_description LONGTEXT NULL,
			english_title TEXT NULL,
			error_code VARCHAR(80) NULL,
			last_error TEXT NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_retry_at DATETIME NULL,
			locked_until DATETIME NULL,
			locked_by VARCHAR(100) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY profile_message (profile_id, source_message_id),
			KEY profile_status (profile_id, index_status, id),
			KEY dispatch_status (dispatch_status, next_retry_at, id),
			KEY download_status (download_status, id),
			KEY product_status (product_status, id),
			KEY file_code (file_code),
			KEY category_confidence (category_id, confidence)
		) {$charset};";
		$sql3 = "CREATE TABLE {$dispatch} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			index_id BIGINT UNSIGNED NOT NULL,
			profile_id BIGINT UNSIGNED NOT NULL,
			method VARCHAR(30) NOT NULL DEFAULT 'unknown',
			bot_username VARCHAR(190) NULL,
			payload VARCHAR(255) NULL,
			callback_data TEXT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'pending',
			search_only TINYINT(1) NOT NULL DEFAULT 0,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_checked_at DATETIME NULL,
			sent_at DATETIME NULL,
			file_message_id BIGINT DEFAULT NULL,
			inbox_id BIGINT UNSIGNED DEFAULT NULL,
			error_code VARCHAR(80) NULL,
			error_message TEXT NULL,
			next_retry_at DATETIME NULL,
			locked_until DATETIME NULL,
			locked_by VARCHAR(100) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY index_dispatch (index_id),
			KEY status_retry (status, next_retry_at, id),
			KEY profile_status (profile_id, status, id)
		) {$charset};";
		$sql4 = "CREATE TABLE {$queue} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_type VARCHAR(40) NOT NULL,
			reference_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			profile_id BIGINT UNSIGNED DEFAULT NULL,
			priority SMALLINT NOT NULL DEFAULT 10,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			payload LONGTEXT NULL,
			last_error TEXT NULL,
			next_retry_at DATETIME NULL,
			locked_until DATETIME NULL,
			locked_by VARCHAR(100) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY queue_pick (status, next_retry_at, priority, id)
		) {$charset};";
		dbDelta( $sql1 ); dbDelta( $sql2 ); dbDelta( $sql3 ); dbDelta( $sql4 );
		update_option( self::DB_VER_KEY, self::DB_VER, false );
	}

	protected function check_ajax() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 ); }
	}

	protected function parse_identifier( $identifier ) {
		return class_exists( 'STI_Channel_Import' ) ? STI_Channel_Import::instance()->parse_chat_identifier( $identifier ) : array( 'username' => '', 'invite_hash' => '', 'is_join_link' => false );
	}

	public function get_profile( $id ) {
		global $wpdb; self::install();
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::profile_table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	public function profiles( $limit = 50 ) {
		global $wpdb; self::install();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::profile_table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 100, (int) $limit ) ) ), ARRAY_A );
	}

	protected function update_profile( $id, $data ) {
		global $wpdb;
		$allowed = array( 'chat_id','channel_title','status','stage','next_offset_id','total_messages','with_photo','with_file','with_button','with_code','matched','sent','downloaded','products_created','failed','last_error','updated_at','completed_at' );
		$row = array(); foreach ( $data as $k => $v ) { if ( in_array( $k, $allowed, true ) ) { $row[ $k ] = $v; } }
		if ( empty( $row ) ) { return 0; }
		$row['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::profile_table(), $row, array( 'id' => (int) $id ) );
	}

	protected function normalize( $text ) {
		$text = mb_strtolower( (string) $text );
		$text = str_replace( array( 'ي','ى','ك','ۀ','ة' ), array( 'ی','ی','ک','ه','ه' ), $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	protected function keyword_hit( $blob, $keyword ) {
		$keyword = $this->normalize( $keyword );
		return '' === $keyword || false !== mb_strpos( $this->normalize( $blob ), $keyword );
	}

	protected function extract_code( $message ) {
		$text = (string) ( $message['text'] ?? '' );
		$parsed = STI_Caption_Parser::parse( $text );
		$code = class_exists( 'STI_Channel_Index' ) ? STI_Channel_Index::normalize_code( $parsed['file_code'] ?? '' ) : sanitize_title( $parsed['file_code'] ?? '' );
		if ( $code ) { return $code; }
		foreach ( (array) ( $message['buttons'] ?? array() ) as $button ) {
			$url = (string) ( $button['url'] ?? '' );
			if ( preg_match( '#[?&]start=([A-Za-z0-9_-]+)#i', $url, $m ) ) { return STI_Channel_Index::normalize_code( $m[1] ); }
			if ( preg_match( '/(?<!\d)(\d{5,})(?!\d)/', (string) ( $button['text'] ?? '' ), $m ) ) { return STI_Channel_Index::normalize_code( $m[1] ); }
		}
		$button = $this->extract_button( $message );
		if ( ! empty( $button['payload'] ) ) { return STI_Channel_Index::normalize_code( $button['payload'] ); }
		if ( ! empty( $message['raw']['reply_markup'] ) && class_exists( 'STI_Channel_Import' ) ) {
			$found = STI_Channel_Import::instance()->find_bot_button_in_markup( $message['raw']['reply_markup'] );
			if ( $found && ! empty( $found['payload'] ) ) { return STI_Channel_Index::normalize_code( $found['payload'] ); }
		}
		if ( preg_match( '/(?<!\d)(\d{5,})(?!\d)/', (string) ( $message['file_name'] ?? '' ), $m ) ) { return STI_Channel_Index::normalize_code( $m[1] ); }
		return '';
	}

	protected function extract_button( $message ) {
		foreach ( (array) ( $message['buttons'] ?? array() ) as $button ) {
			$data = (string) ( $button['data'] ?? '' ); $url = (string) ( $button['url'] ?? '' );
			if ( $data ) { return array( 'type' => 'callback', 'text' => $button['text'] ?? '', 'url' => '', 'data' => $data, 'bot' => '', 'payload' => '' ); }
			if ( $url && preg_match( '#(?:t\.me|telegram\.me)/([A-Za-z0-9_]{3,64})(?:\?start=([A-Za-z0-9_-]*))?#i', $url, $m ) ) { return array( 'type' => 'start', 'text' => $button['text'] ?? '', 'url' => $url, 'data' => '', 'bot' => $m[1], 'payload' => $m[2] ?? '' ); }
			if ( $url ) { return array( 'type' => 'direct', 'text' => $button['text'] ?? '', 'url' => $url, 'data' => '', 'bot' => '', 'payload' => '' ); }
		}
		if ( ! empty( $message['raw']['reply_markup'] ) && class_exists( 'STI_Channel_Import' ) ) {
			$found = STI_Channel_Import::instance()->find_bot_button_in_markup( $message['raw']['reply_markup'] );
			if ( $found ) { return array( 'type' => 'start', 'text' => 'دانلود', 'url' => 'https://t.me/' . $found['bot'] . ( ! empty( $found['payload'] ) ? '?start=' . rawurlencode( $found['payload'] ) : '' ), 'data' => '', 'bot' => $found['bot'], 'payload' => $found['payload'] ?? '' ); }
		}
		return array( 'type' => '', 'text' => '', 'url' => '', 'data' => '', 'bot' => '', 'payload' => '' );
	}

	protected function extract_bot_username( $message ) {
		$blob = (string) ( $message['text'] ?? '' );
		if ( preg_match( '/@([A-Za-z0-9_]{3,64}bot)\b/i', $blob, $m ) ) { return $m[1]; }
		return '';
	}

	protected function category_result( $text, $file_type, $selected_id ) {
		$out = array( 'category_id' => $selected_id ? (int) $selected_id : 0, 'slug' => '', 'confidence' => 0, 'keywords' => array() );
		if ( class_exists( 'STI_AutoCat' ) ) {
			try {
				$d = STI_AutoCat::detect( $text, $file_type );
				$out['slug'] = (string) ( $d['main_category'] ?? '' );
				$out['confidence'] = (int) ( $d['confidence'] ?? 0 );
				$out['keywords'] = $d['matched_keywords'] ?? array();
				if ( ! $selected_id && $out['slug'] ) { $out['category_id'] = (int) STI_AutoCat::map_slug_to_wc_category_id( $out['slug'] ); }
			} catch ( \Throwable $e ) { STI_Logger::warning( 'GoldTel AutoCat: ' . $e->getMessage() ); }
		}
		return $out;
	}

	public function create_profile( $data ) {
		global $wpdb; self::install();
		$identifier = trim( (string) ( $data['identifier'] ?? '' ) ); $name = trim( sanitize_text_field( $data['name'] ?? '' ) ); $keyword = trim( sanitize_text_field( $data['keyword'] ?? '' ) );
		$category_id = (int) ( $data['category_id'] ?? 0 ); $parsed = $this->parse_identifier( $identifier );
		if ( '' === $name ) { $name = 'اسکن ' . wp_date( 'Y-m-d H:i' ); }
		if ( '' === $identifier || empty( $parsed['username'] ) && empty( $parsed['is_join_link'] ) ) { return new WP_Error( 'gi_identifier', 'آدرس کانال/گروه معتبر نیست.' ); }
		if ( $category_id && ( ! STI_Category::get( $category_id ) ) ) { return new WP_Error( 'gi_category', 'دسته‌بندی معتبر نیست.' ); }
		$now = current_time( 'mysql' );
		$wpdb->insert( self::profile_table(), array( 'name' => $name, 'identifier' => $identifier, 'username' => sanitize_text_field( $parsed['username'] ?? '' ), 'invite_hash' => sanitize_text_field( $parsed['invite_hash'] ?? '' ), 'keyword' => $keyword, 'category_id' => $category_id ?: null, 'max_messages' => max( 0, min( self::MAX_PROFILE_MESSAGES, (int) ( $data['max_messages'] ?? 0 ) ) ), 'status' => 'queued', 'stage' => 'resolve', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
		$id = (int) $wpdb->insert_id; if ( ! $id ) { return new WP_Error( 'gi_profile_db', 'ساخت Profile ناموفق بود.' ); }
		$this->schedule_profile( $id, 1 ); return $this->get_profile( $id );
	}

	protected function schedule_profile( $id, $delay = 2 ) {
		if ( ! wp_next_scheduled( 'sti_goldtel_worker', array( (int) $id ) ) ) { wp_schedule_single_event( time() + max( 1, (int) $delay ), 'sti_goldtel_worker', array( (int) $id ) ); }
	}

	protected function schedule_dispatch_worker( $delay = 3 ) {
		if ( ! wp_next_scheduled( 'sti_goldtel_dispatch_worker', array( 0 ) ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), 'sti_goldtel_dispatch_worker', array( 0 ) );
		}
	}

	public function dispatch_worker( $ignored = 0 ) {
		$this->process_dispatch_queue( 1 );
		global $wpdb;
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::dispatch_table() . " WHERE status IN ('pending','waiting','retry')" );
		if ( $pending > 0 ) { $this->schedule_dispatch_worker( 'waiting' === $this->next_dispatch_status() ? 10 : 3 ); }
	}

	protected function next_dispatch_status() {
		global $wpdb;
		return (string) $wpdb->get_var( "SELECT status FROM " . self::dispatch_table() . " WHERE status IN ('pending','waiting','retry') ORDER BY id ASC LIMIT 1" );
	}

	protected function category_matches( $detected_slug, $selected_id ) {
		$selected_id = (int) $selected_id;
		if ( ! $selected_id || '' === (string) $detected_slug ) { return true; }
		$cat = STI_Category::get( $selected_id );
		if ( ! $cat ) { return false; }
		$wanted = sanitize_title( $cat->folder_key ?: $cat->telegram_label );
		$detected = sanitize_title( $detected_slug );
		return $wanted && $detected && ( $wanted === $detected || false !== mb_strpos( $detected, $wanted ) || false !== mb_strpos( $wanted, $detected ) );
	}

	protected function save_index_record( $profile, $peer, $message ) {
		global $wpdb;
		if ( empty( $message['id'] ) ) { return 0; }
		$button = $this->extract_button( $message ); $code = $this->extract_code( $message ); $text = (string) ( $message['text'] ?? '' ); $fallback_bot = ! empty( $button['bot'] ) ? $button['bot'] : $this->extract_bot_username( $message ); if ( '' === $button['type'] && $fallback_bot && $code ) { $button['type'] = 'start'; $button['bot'] = $fallback_bot; $button['payload'] = $code; } $parsed = STI_Caption_Parser::parse( $text ); $file_type = (string) ( $parsed['file_type'] ?? '' ); if ( '' === $file_type ) { $file_type = strtoupper( ltrim( (string) pathinfo( (string) ( $message['file_name'] ?? '' ), PATHINFO_EXTENSION ), '.' ) ); }
		$cat = $this->category_result( $text . ' ' . ( $message['file_name'] ?? '' ), $file_type, (int) $profile['category_id'] );
		$keyword = (string) $profile['keyword']; $blob = $text . ' ' . ( $message['file_name'] ?? '' ) . ' ' . ( $button['text'] ?? '' ) . ' ' . ( $button['url'] ?? '' );
		$hit = $this->keyword_hit( $blob, $keyword );
		$category_allowed = $this->category_matches( $cat['slug'], (int) $profile['category_id'] );
		// GoldTel stage 1 does not require File Code and does not use the old
		// Channel Import duplicate gate. Duplicate identity is checked later by
		// file name/title and then confirmed during direct download.
		$is_duplicate = false; $duplicate_reason = '';
		$wpdb->insert( self::index_table(), array(
			'profile_id' => (int) $profile['id'], 'source_chat_id' => (int) $peer, 'source_message_id' => (int) $message['id'], 'source_date' => (int) ( $message['date'] ?? 0 ), 'group_key' => 'gt-' . (int) $profile['id'] . '-' . (int) $message['id'], 'caption_raw' => $text, 'text_raw' => $text, 'file_name' => $message['file_name'] ?? '', 'file_type' => $file_type, 'file_code' => $code ?: null, 'site' => sanitize_text_field( $parsed['site'] ?? '' ), 'source_url' => esc_url_raw( $parsed['source_url'] ?? '' ), 'photo_message_id' => 'photo' === ( $message['media_type'] ?? '' ) ? (int) $message['id'] : 0, 'text_message_id' => $text ? (int) $message['id'] : 0, 'file_message_id' => in_array( $message['media_type'] ?? '', array( 'document','audio','video','animation' ), true ) ? (int) $message['id'] : 0, 'button_type' => $button['type'], 'button_text' => $button['text'], 'button_url' => $button['url'], 'button_data' => $button['data'], 'bot_username' => $button['bot'], 'bot_payload' => $button['payload'], 'has_photo' => 'photo' === ( $message['media_type'] ?? '' ) ? 1 : 0, 'has_text' => $text ? 1 : 0, 'has_file' => in_array( $message['media_type'] ?? '', array( 'document','audio','video','animation' ), true ) ? 1 : 0, 'has_button' => $button['type'] ? 1 : 0, 'has_file_code' => $code ? 1 : 0, 'file_size_bytes' => (int) ( $message['file_size'] ?? 0 ), 'raw_payload' => wp_json_encode( $message['raw'] ?? $message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'category_id' => $cat['category_id'] ?: null, 'autocat_category' => $cat['slug'], 'confidence' => $cat['confidence'], 'matched_keywords' => wp_json_encode( array( 'profile_keyword_hit' => $hit, 'category_allowed' => $category_allowed, 'autocat' => $cat['keywords'] ), JSON_UNESCAPED_UNICODE ), 'is_duplicate' => $is_duplicate ? 1 : 0, 'duplicate_reason' => $duplicate_reason, 'index_status' => ( $hit || ! $keyword ) && $category_allowed ? 'indexed' : 'filtered', 'dispatch_status' => 'not_sent', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' )
		) );
		return (int) $wpdb->insert_id;
	}

	public function worker( $profile_id ) {
		$profile = $this->get_profile( $profile_id ); if ( ! $profile || ! in_array( $profile['status'], array( 'queued','running' ), true ) ) { return; }
		$lock = 'sti_goldtel_lock_' . (int) $profile_id; if ( get_transient( $lock ) ) { $this->schedule_profile( $profile_id, 15 ); return; } set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );
		try {
			$mt = STI_MTProto::instance(); $peer = (int) $profile['chat_id'];
			if ( ! $peer ) { $identifier = $profile['invite_hash'] ? 'https://t.me/+' . $profile['invite_hash'] : $profile['username']; $info = $mt->chat_info( $identifier ); if ( is_wp_error( $info ) ) { $this->fail_profile( $profile_id, $info->get_error_message() ); return; } $peer = (int) $info['id']; $this->update_profile( $profile_id, array( 'chat_id' => $peer, 'channel_title' => $info['title'] ?? '', 'status' => 'running', 'stage' => 'scan' ) ); $profile = $this->get_profile( $profile_id ); }
			if ( 'scan' === $profile['stage'] ) {
				if ( (int) $profile['max_messages'] > 0 && (int) $profile['total_messages'] >= (int) $profile['max_messages'] ) { $this->update_profile( $profile_id, array( 'status' => 'indexed', 'stage' => 'done', 'completed_at' => current_time( 'mysql' ) ) ); return; }
				$history = $mt->get_history( $peer, self::HISTORY_PAGE, (int) $profile['next_offset_id'] );
				if ( is_wp_error( $history ) ) { $this->fail_profile( $profile_id, $history->get_error_message() ); return; }
				$messages = (array) ( $history['messages'] ?? array() );
				if ( empty( $messages ) ) {
					global $wpdb;
					// Finalize the read-only index before showing it in the UI:
					// recalculate keyword/category gates and connect nearby photo,
					// button and archive messages into the candidate row.
					$this->refresh_profile_filter_state( $profile_id );
					$this->assemble_profile_records( $profile_id );
					$matched = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . self::index_table() . " WHERE profile_id = %d AND index_status = 'indexed'", (int) $profile_id ) );
					$this->update_profile( $profile_id, array( 'matched' => $matched, 'status' => 'indexed', 'stage' => 'done', 'completed_at' => current_time( 'mysql' ) ) );
				}
				else {
					$photo_count = 0; $file_count = 0; $button_count = 0; $code_count = 0; $matched_count = 0;
					foreach ( $messages as $message ) {
						$this->save_index_record( $profile, $peer, $message );
						if ( 'photo' === ( $message['media_type'] ?? '' ) ) { $photo_count++; }
						if ( in_array( $message['media_type'] ?? '', array( 'document','audio','video','animation' ), true ) ) { $file_count++; }
						if ( ! empty( $message['buttons'] ) ) { $button_count++; }
						if ( $this->extract_code( $message ) ) { $code_count++; }
						if ( ! $profile['keyword'] || $this->keyword_hit( ( $message['text'] ?? '' ) . ' ' . ( $message['file_name'] ?? '' ), $profile['keyword'] ) ) { $matched_count++; }
					}
					$last = end( $messages ); $this->update_profile( $profile_id, array( 'next_offset_id' => (int) ( $last['id'] ?? 0 ), 'total_messages' => (int) $profile['total_messages'] + count( $messages ), 'with_photo' => (int) $profile['with_photo'] + $photo_count, 'with_file' => (int) $profile['with_file'] + $file_count, 'with_button' => (int) $profile['with_button'] + $button_count, 'with_code' => (int) $profile['with_code'] + $code_count, 'matched' => (int) $profile['matched'] + $matched_count ) );
				}
			}
		} catch ( \Throwable $e ) { $this->fail_profile( $profile_id, $e->getMessage() ); } finally { delete_transient( $lock ); }
		$after = $this->get_profile( $profile_id ); if ( $after && in_array( $after['status'], array( 'queued','running' ), true ) ) { $this->schedule_profile( $profile_id, 2 ); }
	}

	protected function fail_profile( $id, $message ) { $this->update_profile( $id, array( 'status' => 'failed', 'stage' => 'done', 'last_error' => mb_substr( (string) $message, 0, 1000 ) ) ); STI_Logger::error( 'GoldTel Profile #' . (int) $id . ': ' . $message ); }

	protected function refresh_profile_filter_state( $profile_id ) {
		global $wpdb;
		$profile = $this->get_profile( $profile_id );
		if ( ! $profile ) { return; }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, caption_raw, file_name, button_text, button_url, autocat_category, index_status FROM ' . self::index_table() . ' WHERE profile_id = %d', (int) $profile_id ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$blob = (string) $row['caption_raw'] . ' ' . (string) $row['file_name'] . ' ' . (string) $row['button_text'] . ' ' . (string) $row['button_url'];
			$hit = ! $profile['keyword'] || $this->keyword_hit( $blob, $profile['keyword'] );
			$allowed = $this->category_matches( (string) $row['autocat_category'], (int) $profile['category_id'] );
			$status = ( $hit && $allowed ) ? 'indexed' : 'filtered';
			if ( $status !== (string) $row['index_status'] ) { $wpdb->update( self::index_table(), array( 'index_status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row['id'] ) ); }
		}
	}

	/** Enrich keyword-hit rows with the photo/button/archive rows around them. */
	protected function assemble_profile_records( $profile_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::index_table() . ' WHERE profile_id = %d ORDER BY source_message_id ASC', (int) $profile_id ), ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) { return 0; }
		$changed = 0; $count = count( $rows );
		for ( $i = 0; $i < $count; $i++ ) {
			$candidate = $rows[ $i ];
			if ( 'indexed' !== (string) ( $candidate['index_status'] ?? '' ) ) { continue; }
			if ( ! empty( $candidate['raw_payload'] ) ) {
				$raw = json_decode( $candidate['raw_payload'], true );
				$normalized = is_array( $raw ) ? STI_MTProto::instance()->normalize_message( $raw ) : null;
				if ( $normalized ) {
					$hydrated_button = $this->extract_button( $normalized ); $hydrated_code = $this->extract_code( $normalized ); $hydrated_bot = $hydrated_button['bot'] ?: $this->extract_bot_username( $normalized );
					$hydrate = array();
					if ( empty( $candidate['file_code'] ) && $hydrated_code ) { $hydrate['file_code'] = $hydrated_code; $hydrate['has_file_code'] = 1; }
					if ( empty( $candidate['has_button'] ) && $hydrated_button['type'] ) { $hydrate['has_button'] = 1; $hydrate['button_type'] = $hydrated_button['type']; $hydrate['button_text'] = $hydrated_button['text']; $hydrate['button_url'] = $hydrated_button['url']; $hydrate['button_data'] = $hydrated_button['data']; $hydrate['bot_username'] = $hydrated_bot; $hydrate['bot_payload'] = $hydrated_button['payload'] ?: $hydrated_code; }
					if ( empty( $candidate['has_button'] ) && $hydrated_bot && $hydrated_code ) { $hydrate['button_type'] = 'start'; $hydrate['bot_username'] = $hydrated_bot; $hydrate['bot_payload'] = $hydrated_code; }
					if ( $hydrate ) { $wpdb->update( self::index_table(), $hydrate, array( 'id' => (int) $candidate['id'] ) ); $candidate = array_merge( $candidate, $hydrate ); }
				}
			}
			$photo = ( ! empty( $candidate['has_photo'] ) ) ? $candidate : null;
			$file = ( ! empty( $candidate['has_file'] ) ) ? $candidate : null;
			$button = ( ! empty( $candidate['has_button'] ) ) ? $candidate : null;
			for ( $j = max( 0, $i - 3 ); $j < $i; $j++ ) {
				if ( ! $photo && ! empty( $rows[ $j ]['has_photo'] ) ) { $photo = $rows[ $j ]; }
				if ( ! $button && ! empty( $rows[ $j ]['has_button'] ) ) { $button = $rows[ $j ]; }
			}
			for ( $j = $i; $j < min( $count, $i + 9 ); $j++ ) {
				if ( ! $file && ! empty( $rows[ $j ]['has_file'] ) ) { $file = $rows[ $j ]; }
				if ( ! $button && ! empty( $rows[ $j ]['has_button'] ) ) { $button = $rows[ $j ]; }
				if ( $file && $button ) { break; }
			}
			$updates = array();
			if ( $photo ) { $updates['has_photo'] = 1; $updates['photo_message_id'] = (int) $photo['source_message_id']; }
			if ( $file ) { $updates['has_file'] = 1; $updates['file_message_id'] = (int) $file['source_message_id']; if ( empty( $candidate['file_name'] ) ) { $updates['file_name'] = $file['file_name']; } if ( empty( $candidate['file_type'] ) ) { $updates['file_type'] = $file['file_type']; } $updates['file_size_bytes'] = (int) $file['file_size_bytes']; if ( empty( $candidate['file_code'] ) && ! empty( $file['file_code'] ) ) { $updates['file_code'] = $file['file_code']; $updates['has_file_code'] = 1; } }
			if ( $button ) { $updates['has_button'] = 1; $updates['button_type'] = $button['button_type']; $updates['button_text'] = $button['button_text']; $updates['button_url'] = $button['button_url']; $updates['button_data'] = $button['button_data']; $updates['bot_username'] = $button['bot_username']; $updates['bot_payload'] = $button['bot_payload']; if ( empty( $candidate['file_code'] ) && ! empty( $button['bot_payload'] ) ) { $updates['file_code'] = $button['bot_payload']; $updates['has_file_code'] = 1; } }
			if ( $photo || $file || $button ) { $updates['group_key'] = 'gt-' . (int) $profile_id . '-g' . (int) $candidate['source_message_id']; } $updates['is_duplicate'] = 0; $updates['duplicate_reason'] = '';
			if ( ! empty( $updates ) ) { $wpdb->update( self::index_table(), $updates, array( 'id' => (int) $candidate['id'] ) ); $changed++; }
		}
		return $changed;
	}

	protected function query_records( $filters ) {
		global $wpdb; self::install(); $profile_id = (int) ( $filters['profile_id'] ?? 0 ); $this->refresh_profile_filter_state( $profile_id ); $this->assemble_profile_records( $profile_id ); $matched_now = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . self::index_table() . " WHERE profile_id = %d AND index_status = 'indexed'", $profile_id ) ); if ( $profile_id ) { $this->update_profile( $profile_id, array( 'matched' => $matched_now ) ); } $where = array( 'profile_id = %d' ); $params = array( $profile_id );
		if ( ! isset( $filters['index_status'] ) || '' === (string) $filters['index_status'] ) { $where[] = 'index_status = %s'; $params[] = 'indexed'; }
		if ( '' !== (string) ( $filters['keyword'] ?? '' ) ) { $where[] = '(caption_raw LIKE %s OR file_name LIKE %s OR button_text LIKE %s)'; $like = '%' . $wpdb->esc_like( $filters['keyword'] ) . '%'; $params[] = $like; $params[] = $like; $params[] = $like; }
		if ( ! empty( $filters['category_id'] ) ) { $where[] = 'category_id = %d'; $params[] = (int) $filters['category_id']; }
		if ( '' !== (string) ( $filters['file_type'] ?? '' ) ) { $where[] = 'file_type LIKE %s'; $params[] = '%' . $wpdb->esc_like( sanitize_text_field( $filters['file_type'] ) ) . '%'; }
		if ( '' !== (string) ( $filters['site'] ?? '' ) ) { $where[] = 'site LIKE %s'; $params[] = '%' . $wpdb->esc_like( sanitize_text_field( $filters['site'] ) ) . '%'; }
		if ( isset( $filters['confidence_min'] ) && '' !== (string) $filters['confidence_min'] ) { $where[] = 'confidence >= %d'; $params[] = max( 0, min( 100, (int) $filters['confidence_min'] ) ); }
		foreach ( array( 'dispatch_status','download_status','product_status','index_status' ) as $field ) { if ( ! empty( $filters[ $field ] ) ) { $where[] = $field . ' = %s'; $params[] = sanitize_key( $filters[ $field ] ); } }
		if ( isset( $filters['is_duplicate'] ) && '' !== (string) $filters['is_duplicate'] ) { $where[] = 'is_duplicate = %d'; $params[] = ! empty( $filters['is_duplicate'] ) ? 1 : 0; }
		if ( isset( $filters['has_button'] ) && '' !== (string) $filters['has_button'] ) { $where[] = 'has_button = %d'; $params[] = ! empty( $filters['has_button'] ) ? 1 : 0; }
		foreach ( array( 'has_photo','has_file','has_text','has_file_code' ) as $flag ) { if ( isset( $filters[ $flag ] ) && '' !== (string) $filters[ $flag ] ) { $where[] = $flag . ' = %d'; $params[] = ! empty( $filters[ $flag ] ) ? 1 : 0; } }
		$base = ' FROM ' . self::index_table() . ' WHERE ' . implode( ' AND ', $where ); $total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $base, $params ) ); $page = max( 1, (int) ( $filters['page'] ?? 1 ) ); $per = max( 10, min( 100, (int) ( $filters['per_page'] ?? 25 ) ) ); $list_params = array_merge( $params, array( $per, ( $page - 1 ) * $per ) ); $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT *' . $base . ' ORDER BY source_message_id ASC LIMIT %d OFFSET %d', $list_params ), ARRAY_A ); return array( 'rows' => is_array( $rows ) ? $rows : array(), 'total' => $total, 'pages' => max( 1, (int) ceil( $total / $per ) ), 'page' => $page );
	}

	public function dispatches( $profile_id = 0, $limit = 50 ) {
		global $wpdb; self::install();
		$where = $profile_id ? $wpdb->prepare( 'WHERE d.profile_id = %d', (int) $profile_id ) : '';
		return $wpdb->get_results( "SELECT d.*, i.source_message_id, i.file_name, i.file_code, i.content_title, i.last_error AS index_error FROM " . self::dispatch_table() . " d LEFT JOIN " . self::index_table() . " i ON i.id = d.index_id {$where} ORDER BY d.id DESC LIMIT " . max( 1, min( 100, (int) $limit ) ), ARRAY_A );
	}

	public function dispatch_records( $ids, $search_only = false ) {
		global $wpdb; self::install(); $ok = 0; $errors = array();
		foreach ( array_slice( array_values( array_filter( array_map( 'absint', (array) $ids ) ) ), 0, 100 ) as $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::index_table() . ' WHERE id = %d', $id ), ARRAY_A ); if ( ! $row ) { continue; }
			$this->assemble_profile_records( (int) $row['profile_id'] );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::index_table() . ' WHERE id = %d', $id ), ARRAY_A );
			$duplicate_id = $this->duplicate_by_name_or_title( $row, $row['file_name'] ?: $row['caption_raw'] );
			if ( $duplicate_id ) { $wpdb->update( self::index_table(), array( 'is_duplicate' => 1, 'duplicate_reason' => 'نام فایل/عنوان تکراری', 'product_id' => $duplicate_id, 'dispatch_status' => 'duplicate', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) ); $errors[] = '#' . $id . ': تکراری بر اساس نام فایل/عنوان'; continue; }
			if ( ! $row['has_file'] && ! $row['file_message_id'] ) { $errors[] = '#' . $id . ': پیام فایل اصلی پیدا نشد'; continue; }
			if ( empty( $row['file_code'] ) ) { $generated_code = 'gt-' . abs( (int) $row['source_chat_id'] ) . '-' . (int) ( $row['file_message_id'] ?: $row['source_message_id'] ); $wpdb->update( self::index_table(), array( 'file_code' => $generated_code, 'has_file_code' => 1, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) ); $row['file_code'] = $generated_code; }
			$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::dispatch_table() . ' WHERE index_id = %d LIMIT 1', $id ) ); if ( $existing ) { continue; }
			$wpdb->insert( self::dispatch_table(), array( 'index_id' => $id, 'profile_id' => $row['profile_id'], 'method' => 'channel_direct', 'bot_username' => $row['bot_username'], 'payload' => $row['bot_payload'] ?: $row['file_code'], 'callback_data' => $row['button_data'], 'status' => $search_only ? 'waiting' : 'pending', 'search_only' => $search_only ? 1 : 0, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
			$wpdb->update( self::index_table(), array( 'dispatch_status' => $search_only ? 'waiting' : 'queued', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) ); $ok++;
		}
		if ( $ok ) { $this->schedule_dispatch_worker( 1 ); }
		return array( 'queued' => $ok, 'errors' => $errors );
	}

	public function ajax_profile_start() { $this->check_ajax(); $result = $this->create_profile( array( 'name' => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 'identifier' => sanitize_text_field( wp_unslash( $_POST['identifier'] ?? '' ) ), 'keyword' => sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) ), 'category_id' => (int) ( $_POST['category_id'] ?? 0 ), 'max_messages' => (int) ( $_POST['max_messages'] ?? 0 ) ) ); if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); } wp_send_json_success( array( 'message' => 'پروفایل اسکن ساخته شد.', 'profile' => $result ) ); }
	public function ajax_profile_poll() { $this->check_ajax(); $this->process_dispatch_queue( 1 ); foreach ( $this->profiles( 50 ) as $p ) { if ( in_array( $p['status'], array( 'queued','running' ), true ) ) { $this->worker( (int) $p['id'] ); break; } } wp_send_json_success( array( 'profiles' => $this->profiles( 50 ) ) ); }
	public function ajax_profile_cancel() { $this->check_ajax(); $id = (int) ( $_POST['id'] ?? 0 ); $this->update_profile( $id, array( 'status' => 'cancelled', 'stage' => 'done' ) ); wp_send_json_success( array( 'message' => 'Profile لغو شد.' ) ); }
	public function ajax_records() { $this->check_ajax(); wp_send_json_success( $this->query_records( $_POST ) ); }
	public function ajax_dispatch() { $this->check_ajax(); $r = $this->dispatch_records( $_POST['ids'] ?? array(), ! empty( $_POST['search_only'] ) ); wp_send_json_success( array( 'message' => $r['queued'] . ' رکورد به صف اضافه شد.' . ( $r['errors'] ? ' خطاها: ' . implode( ' | ', array_slice( $r['errors'], 0, 8 ) ) : '' ), 'result' => $r ) ); }
	public function ajax_dispatches() { $this->check_ajax(); wp_send_json_success( array( 'dispatches' => $this->dispatches( (int) ( $_POST['profile_id'] ?? 0 ), 50 ) ) ); }

	public function ajax_retry() { $this->check_ajax(); global $wpdb; $id = (int) ( $_POST['id'] ?? 0 ); $d = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::dispatch_table() . ' WHERE id = %d', $id ), ARRAY_A ); if ( ! $d ) { wp_send_json_error( array( 'message' => 'Dispatch پیدا نشد.' ) ); } $wpdb->update( self::dispatch_table(), array( 'status' => ! empty( $_POST['search_only'] ) ? 'waiting' : 'pending', 'attempts' => 0, 'next_retry_at' => null, 'error_message' => null, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) ); wp_send_json_success( array( 'message' => 'برای تلاش دوباره آماده شد.' ) ); }
	public function ajax_process_now() { $this->check_ajax(); $ran = $this->process_dispatch_queue( 1 ); foreach ( $this->profiles( 20 ) as $p ) { if ( in_array( $p['status'], array( 'queued','running' ), true ) ) { $this->worker( (int) $p['id'] ); break; } } wp_send_json_success( array( 'message' => $ran ? 'یک مرحله Dispatch پردازش شد.' : 'یک مرحله از Profile/صف اجرا شد.', 'dispatches' => $this->dispatches( 0, 20 ) ) ); }

	protected function get_dispatch( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::dispatch_table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); }
	protected function process_dispatch_queue( $limit = 1 ) { $n = 0; global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . self::dispatch_table() . " WHERE status IN ('pending','waiting','retry') AND (next_retry_at IS NULL OR next_retry_at <= %s) ORDER BY id ASC LIMIT %d", current_time( 'mysql' ), max( 1, min( 5, (int) $limit ) ) ), ARRAY_A ); foreach ( (array) $rows as $d ) { if ( $this->process_dispatch( $d ) ) { $n++; } } return $n; }

	protected function clean_content_line( $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) { return ''; }
		if ( preg_match( '/(?:get\s+any|دریافت\s+هر|دانلود\s+هر).*(?:https?:\/\/|@\w*bot\b|t\.me)/iu', $line ) ) { return ''; }
		if ( preg_match( '/^\s*(?:https?:\/\/|www\.)\S+\s*$/iu', $line ) ) { return ''; }
		$line = preg_replace( '/\[([^\]]+)\]\([^\)]+\)/u', '$1', $line );
		$line = preg_replace( '/https?:\/\/\S+/iu', '', $line );
		$line = preg_replace( '/@\w*bot\b/iu', '', $line );
		return trim( preg_replace( '/\s{2,}/u', ' ', $line ) );
	}

	protected function goldtel_content( $index, $category ) {
		$text = trim( (string) ( $index['caption_raw'] ?? $index['text_raw'] ?? '' ) );
		$raw_lines = preg_split( '/\r\n|\r|\n/', $text );
		$lines = array_values( array_filter( array_map( array( $this, 'clean_content_line' ), (array) $raw_lines ) ) );
		$raw_title = $lines ? array_shift( $lines ) : ( $index['file_name'] ?: 'فایل گرافیکی' );
		$raw_title = trim( preg_replace( '/^(?:title|name|نام|عنوان)\s*[:：]\s*/iu', '', $raw_title ) );
		$description = trim( implode( "\n", $lines ) );
		if ( '' === $description ) { $description = $text; }
		$ai_title = $raw_title; $ai_desc = $description;
		if ( class_exists( 'STI_AI' ) && STI_AI::is_ready() ) {
			try {
				$ai = STI_Content_Generator::ai_improve_title_and_description( $raw_title, $raw_title, $index['file_type'] ?: 'ZIP', $category ? $category->telegram_label : '' );
				if ( is_array( $ai ) ) {
					if ( ! empty( $ai['title'] ) ) { $ai_title = $ai['title']; }
					if ( ! empty( $ai['description'] ) ) { $ai_desc = $ai['description']; }
				}
			} catch ( \Throwable $e ) { STI_Logger::warning( 'GoldTel AI content: ' . $e->getMessage() ); }
		}
		$subject = trim( preg_replace( '/^(?:دانلود|download)\s+/iu', '', (string) $ai_title ) );
		$subject = trim( preg_replace( '/^(?:فایل|file)\s+/iu', '', $subject ) );
		$subject = trim( $subject, "[]() \t\r\n" );
		$full_hay = $raw_title . ' ' . ( $index['file_type'] ?? '' ) . ' ' . $subject;
		$is_ui = false !== mb_stripos( $full_hay, 'ui' ) || false !== mb_stripos( $full_hay, 'user interface' ) || false !== mb_strpos( $full_hay, 'رابط کاربری' );
		$subject = trim( preg_replace( '/\b(?:ui\s*kit|ui|user\s*interface|interface)\b/iu', '', $subject ) );
		$subject = trim( preg_replace( '/رابط\s*کاربری/u', '', $subject ) );
		if ( '' === $subject ) { $subject = $raw_title; }
		$title = 'دانلود ' . ( $is_ui ? 'رابط کاربری' : 'فایل' ) . ' با موضوع ' . trim( preg_replace( '/\s+/u', ' ', $subject ) );
		$desc_lines = preg_split( '/\r\n|\r|\n/', (string) $ai_desc );
		$description_clean = implode( "\n", array_values( array_filter( array_map( array( $this, 'clean_content_line' ), (array) $desc_lines ) ) ) );
		$description_final = trim( 'عنوان اصلی فایل: ' . $raw_title . "\n\n" . $description_clean );
		return array( 'title' => $title, 'description' => $description_final, 'english_title' => $raw_title );
	}

	protected function decode_index_message( $row, $mt, $peer ) {
		if ( ! $row || empty( $row['raw_payload'] ) ) { return null; }
		$raw = json_decode( $row['raw_payload'], true );
		$message = is_array( $raw ) ? $mt->normalize_message( $raw ) : null;
		if ( ! $message ) { return null; }
		$message['sender_chat_id'] = $peer;
		return $message;
	}

	protected function is_valid_image_path( $path ) {
		return is_string( $path ) && STI_Security::safe_file_size( $path ) > 0 && @getimagesize( $path );
	}

	protected function obtain_photo_attachment( $index, $mt, $peer ) {
		global $wpdb;
		$photo_row = null;
		if ( ! empty( $index['photo_message_id'] ) ) {
			$photo_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::index_table() . ' WHERE profile_id = %d AND source_message_id = %d LIMIT 1', (int) $index['profile_id'], (int) $index['photo_message_id'] ), ARRAY_A );
		}
		if ( ! $photo_row && ! empty( $index['has_photo'] ) && ! empty( $index['raw_payload'] ) ) { $photo_row = $index; }
		if ( ! $photo_row ) {
			$photo_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::index_table() . ' WHERE profile_id = %d AND has_photo = 1 AND source_message_id <= %d ORDER BY source_message_id DESC LIMIT 1', (int) $index['profile_id'], (int) $index['source_message_id'] ), ARRAY_A );
		}
		$photo = $photo_row ? $this->decode_index_message( $photo_row, $mt, $peer ) : null;
		if ( ! $photo || 'photo' !== ( $photo['media_type'] ?? '' ) ) { return new WP_Error( 'goldtel_no_photo', 'پیام عکس معتبر برای تصویر شاخص پیدا نشد.' ); }
		$tmp = trailingslashit( STI_MTProto::base_dir() ) . 'tmp'; if ( ! is_dir( $tmp ) ) { wp_mkdir_p( $tmp ); }
		$photo['file_name'] = 'goldtel-photo-' . (int) $photo['id'] . '.jpg';
		$download = null;
		// Direct photo download first: FileHunter is intentionally not used for
		// the featured image because a stale preview/document path can be returned
		// by some MadelineProto versions.
		$direct = $mt->download_media( $photo, $tmp );
		if ( ! is_wp_error( $direct ) && $this->is_valid_image_path( $direct['path'] ?? '' ) ) { $download = $direct; }
		if ( ! $download ) {
			if ( is_array( $direct ) && ! empty( $direct['path'] ) ) { @unlink( $direct['path'] ); }
			$fresh_raw = $mt->refresh_message( $peer, (int) $photo['id'] );
			if ( is_array( $fresh_raw ) ) {
				$fresh = $mt->normalize_message( $fresh_raw );
				if ( $fresh ) { $fresh['sender_chat_id'] = $peer; $fresh['file_name'] = 'goldtel-photo-' . (int) $photo['id'] . '.jpg'; $retry = $mt->download_media( $fresh, $tmp ); if ( ! is_wp_error( $retry ) && $this->is_valid_image_path( $retry['path'] ?? '' ) ) { $download = $retry; } elseif ( ! empty( $retry['path'] ) ) { @unlink( $retry['path'] ); } }
			}
		}
		if ( ! $download ) {
			$fallback = $mt->download_media_robust( $photo, $tmp );
			if ( ! is_wp_error( $fallback ) && $this->is_valid_image_path( $fallback['path'] ?? '' ) ) { $download = $fallback; }
		}
		if ( ! $download ) { return new WP_Error( 'goldtel_invalid_photo', 'فایل دریافت‌شده تصویر معتبر نیست؛ پیام عکس تازه‌سازی و دوباره بررسی شد.' ); }
		return STI_File_Storage::store_image_from_local_file( $download['path'], $index['file_name'] ?: $index['file_code'], 'goldtel-' . (int) $photo_row['source_message_id'] . '.jpg' );
	}

	protected function source_file_for_index( $index, $mt, $peer ) {
		global $wpdb;
		$message_id = ! empty( $index['file_message_id'] ) ? (int) $index['file_message_id'] : (int) $index['source_message_id'];
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::index_table() . ' WHERE profile_id = %d AND source_message_id = %d LIMIT 1', (int) $index['profile_id'], $message_id ), ARRAY_A );
		return $row ? $this->decode_index_message( $row, $mt, $peer ) : null;
	}

	protected function duplicate_by_name_or_title( $index, $title ) {
		global $wpdb;
		$names = array_values( array_unique( array_filter( array( trim( (string) ( $index['file_name'] ?? '' ) ), trim( (string) $title ) ) ) ) );
		foreach ( $names as $name ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ('_sti_file_name','_sti_file_code') AND pm.meta_value = %s AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') LIMIT 1", $name ) );
			if ( $found ) { return (int) $found; }
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft') AND post_title = %s LIMIT 1", $name ) );
			if ( $found ) { return (int) $found; }
		}
		return 0;
	}

	protected function process_channel_direct( $dispatch, $index, $profile, $mt, $category, $peer ) {
		global $wpdb;
		$title_hint = $index['content_title'] ?: ( $index['file_name'] ?: 'فایل گرافیکی' );
		$duplicate_id = $this->duplicate_by_name_or_title( $index, $title_hint );
		if ( $duplicate_id ) {
			$wpdb->update( self::dispatch_table(), array( 'status' => 'duplicate', 'error_message' => 'محصولی با نام فایل یا عنوان یکسان از قبل وجود دارد.', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $dispatch['id'] ) );
			$wpdb->update( self::index_table(), array( 'is_duplicate' => 1, 'duplicate_reason' => 'نام فایل/عنوان تکراری', 'dispatch_status' => 'duplicate', 'product_id' => $duplicate_id, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $index['id'] ) );
			return true;
		}
		$file_message = $this->source_file_for_index( $index, $mt, $peer );
		if ( ! $file_message ) { return $this->dispatch_error( $dispatch, 'پیام فایل اصلی پیدا نشد.' ); }
		$tmp = trailingslashit( STI_MTProto::base_dir() ) . 'tmp'; if ( ! is_dir( $tmp ) ) { wp_mkdir_p( $tmp ); }
		$wpdb->update( self::dispatch_table(), array( 'status' => 'downloading', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $dispatch['id'] ) );
		$download = $mt->download_media_robust( $file_message, $tmp );
		if ( is_wp_error( $download ) ) { return $this->dispatch_error( $dispatch, $download->get_error_message() ); }
		$meta = array( 'file_code' => $index['file_code'], 'file_name' => $title_hint, 'original_name' => $download['name'], 'category_folder' => $category ? ( $category->folder_key ?: STI_Category::sanitize_folder_key( $category->telegram_label, $category->id ) ) : '' );
		$stored = STI_File_Storage::process_local_temp_file( $download['path'], $meta, $category ? STI_Category::storage_mode( $category ) : null );
		if ( is_wp_error( $stored ) ) { return $this->dispatch_error( $dispatch, $stored->get_error_message() ); }
		$wpdb->update( self::dispatch_table(), array( 'status' => 'downloaded', 'file_message_id' => (int) ( $index['file_message_id'] ?: $index['source_message_id'] ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $dispatch['id'] ) );
		$wpdb->update( self::index_table(), array( 'download_status' => 'downloaded', 'dispatch_status' => 'downloaded', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $index['id'] ) );
		$content = $this->goldtel_content( $index, $category );
		$wpdb->update( self::index_table(), array( 'content_title' => $content['title'], 'content_description' => $content['description'], 'english_title' => $content['english_title'], 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $index['id'] ) );
		$index['content_title'] = $content['title']; $index['content_description'] = $content['description'];
		$attachment = $this->obtain_photo_attachment( $index, $mt, $peer );
		if ( is_wp_error( $attachment ) ) { return $this->dispatch_error( $dispatch, $attachment->get_error_message() ); }
		return $this->create_product_from_index( $index, $stored, $category, $dispatch, null, $attachment );
	}


	protected function process_dispatch( $dispatch ) {
		global $wpdb;
		$index = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::index_table() . ' WHERE id = %d', (int) $dispatch['index_id'] ), ARRAY_A );
		if ( ! $index ) { return false; }
		$profile = $this->get_profile( $dispatch['profile_id'] );
		$mt = STI_MTProto::instance();
		$peer = (int) ( $index['source_chat_id'] ?? ( $profile['chat_id'] ?? 0 ) );
		$category = $index['category_id'] ? STI_Category::get( $index['category_id'] ) : null;

		if ( 'channel_direct' === $dispatch['method'] ) { return $this->process_channel_direct( $dispatch, $index, $profile, $mt, $category, $peer ); }

		if ( 'pending' === $dispatch['status'] ) {
			$sent = false; $error = '';
			if ( 'callback' === $dispatch['method'] ) {
				$res = $mt->press_button( $peer, (int) $index['source_message_id'], (string) $dispatch['callback_data'] );
				$sent = ! is_wp_error( $res ); $error = is_wp_error( $res ) ? $res->get_error_message() : '';
			} elseif ( 'start' === $dispatch['method'] ) {
				$res = $mt->start_bot_dialog( (string) $dispatch['bot_username'], (string) ( $dispatch['payload'] ?: $index['file_code'] ) );
				$sent = ! is_wp_error( $res ); $error = is_wp_error( $res ) ? $res->get_error_message() : '';
			} elseif ( 'direct' === $dispatch['method'] ) {
				$sent = true;
			} else {
				$error = 'روش ارسال برای این رکورد مشخص نیست.';
			}
			if ( ! $sent ) { return $this->dispatch_error( $dispatch, $error ?: 'ارسال ناموفق' ); }
			$wpdb->update( self::dispatch_table(), array( 'status' => 'waiting', 'sent_at' => current_time( 'mysql' ), 'last_checked_at' => current_time( 'mysql' ), 'attempts' => (int) $dispatch['attempts'] + 1, 'next_retry_at' => wp_date( 'Y-m-d H:i:s', time() + 20, wp_timezone() ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $dispatch['id'] ) );
			$wpdb->update( self::index_table(), array( 'dispatch_status' => 'sent', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $index['id'] ) );
			if ( $profile ) { $this->update_profile( $profile['id'], array( 'sent' => (int) $profile['sent'] + 1 ) ); }
			return true;
		}

		$stored = null; $inbox_row = null;
		if ( 'direct' === $dispatch['method'] && ! empty( $index['button_url'] ) ) {
			$meta = array( 'file_code' => $index['file_code'], 'file_name' => $index['file_name'] ?: $index['file_code'], 'original_name' => basename( (string) parse_url( $index['button_url'], PHP_URL_PATH ) ), 'category_folder' => $category ? ( $category->folder_key ?: STI_Category::sanitize_folder_key( $category->telegram_label, $category->id ) ) : '' );
			$stored = STI_File_Storage::process( $index['button_url'], $meta, $category ? STI_Category::storage_mode( $category ) : null );
			if ( is_wp_error( $stored ) ) { return $this->dispatch_error( $dispatch, $stored->get_error_message() ); }
		} else {
			$since = time() - 3600;
			if ( ! empty( $dispatch['sent_at'] ) ) {
				try { $dt = date_create_from_format( 'Y-m-d H:i:s', $dispatch['sent_at'], wp_timezone() ); if ( $dt ) { $since = $dt->getTimestamp() - 30; } } catch ( \Throwable $e ) { /* fallback */ }
			}
			$docs = $mt->find_recent_documents( max( 0, $since ), 1800 );
			if ( class_exists( 'STI_Bot_Inbox' ) ) { STI_Bot_Inbox::record_many( $docs ); }
			if ( ! class_exists( 'STI_Bot_Inbox' ) ) { return $this->dispatch_error( $dispatch, 'ماژول Bot Inbox در حالت ایمن بارگذاری نشده است.' ); }
			$inbox_row = STI_Bot_Inbox::find_for_code( $index['file_code'], max( 0, $since ) );
			if ( ! $inbox_row ) { return $this->dispatch_wait_or_fail( $dispatch ); }
			if ( ! STI_Bot_Inbox::claim( (int) $inbox_row['id'], 0, 'goldtel-' . $dispatch['id'] ) ) { return false; }
			$doc = STI_Bot_Inbox::payload( $inbox_row );
			if ( empty( $doc ) ) { STI_Bot_Inbox::mark( $inbox_row['id'], 'ignored' ); return $this->dispatch_error( $dispatch, 'Payload فایل Inbox خالی است.' ); }
			$tmp = trailingslashit( STI_MTProto::base_dir() ) . 'tmp'; if ( ! is_dir( $tmp ) ) { wp_mkdir_p( $tmp ); }
			$download = $mt->download_media_robust( $doc, $tmp );
			if ( is_wp_error( $download ) ) { STI_Bot_Inbox::release( $inbox_row['id'] ); return $this->dispatch_error( $dispatch, $download->get_error_message() ); }
			$meta = array( 'file_code' => $index['file_code'], 'file_name' => $index['file_name'] ?: $index['file_code'], 'original_name' => $download['name'], 'category_folder' => $category ? ( $category->folder_key ?: STI_Category::sanitize_folder_key( $category->telegram_label, $category->id ) ) : '' );
			$stored = STI_File_Storage::process_local_temp_file( $download['path'], $meta, $category ? STI_Category::storage_mode( $category ) : null );
			if ( is_wp_error( $stored ) ) { STI_Bot_Inbox::release( $inbox_row['id'] ); return $this->dispatch_error( $dispatch, $stored->get_error_message() ); }
		}
		if ( is_wp_error( $stored ) || empty( $stored['url'] ) ) { return $this->dispatch_error( $dispatch, 'فایل ذخیره نشد.' ); }

		$wpdb->update( self::dispatch_table(), array( 'status' => 'downloaded', 'inbox_id' => $inbox_row ? $inbox_row['id'] : null, 'file_message_id' => $inbox_row ? (int) ( $inbox_row['msg_id'] ?? 0 ) : 0, 'last_checked_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $dispatch['id'] ) );
		$wpdb->update( self::index_table(), array( 'download_status' => 'downloaded', 'dispatch_status' => 'downloaded', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $index['id'] ) );
		$content = $this->goldtel_content( $index, $category );
		$wpdb->update( self::index_table(), array( 'content_title' => $content['title'], 'content_description' => $content['description'], 'english_title' => $content['english_title'], 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $index['id'] ) );
		$index['content_title'] = $content['title']; $index['content_description'] = $content['description']; $index['english_title'] = $content['english_title'];
		$attachment = $this->obtain_photo_attachment( $index, $mt, $peer );
		if ( is_wp_error( $attachment ) ) { return $this->dispatch_error( $dispatch, $attachment->get_error_message() ); }
		return $this->create_product_from_index( $index, $stored, $category, $dispatch, $inbox_row, $attachment );
	}

	protected function dispatch_error( $dispatch, $message ) { global $wpdb; $attempts = (int) $dispatch['attempts'] + 1; $failed = $attempts >= self::MAX_RETRIES; $wpdb->update( self::dispatch_table(), array( 'status' => $failed ? 'failed' : 'retry', 'attempts' => $attempts, 'error_code' => 'goldtel', 'error_message' => mb_substr( (string) $message, 0, 1000 ), 'next_retry_at' => $failed ? null : wp_date( 'Y-m-d H:i:s', time() + min( 86400, 60 * ( 2 ** min( 8, $attempts ) ) ), wp_timezone() ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $dispatch['id'] ) ); $wpdb->update( self::index_table(), array( 'dispatch_status' => $failed ? 'failed' : 'retry', 'last_error' => $message, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $dispatch['index_id'] ) ); STI_Logger::error( 'GoldTel Dispatch #' . $dispatch['id'] . ': ' . $message ); return false; }
	protected function dispatch_wait_or_fail( $dispatch ) { $attempts = (int) $dispatch['attempts'] + 1; if ( $attempts >= self::MAX_RETRIES ) { return $this->dispatch_error( $dispatch, 'فایل با File Code دقیق در Bot Inbox پیدا نشد.' ); } global $wpdb; $wpdb->update( self::dispatch_table(), array( 'attempts' => $attempts, 'last_checked_at' => current_time( 'mysql' ), 'next_retry_at' => wp_date( 'Y-m-d H:i:s', time() + 30, wp_timezone() ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $dispatch['id'] ) ); return true; }

	protected function create_product_from_index( $index, $stored, $category, $dispatch, $inbox_row, $attachment_id = 0 ) {
		global $wpdb; $title = $index['content_title'] ?: ( $index['file_name'] ?: 'فایل گرافیکی' ); $description = $index['content_description'] ?: ( $index['caption_raw'] ?: '' ); $sid = STI_Session::create( 0, null, (int) ( $index['category_id'] ?? 0 ) ); if ( ! $sid ) { return $this->dispatch_error( $dispatch, 'ساخت Session محصول ناموفق بود.' ); }
		STI_Session::update( $sid, array( 'notify_chat_id' => 0, 'file_code' => $index['file_code'], 'file_name' => $title, 'file_type' => $index['file_type'], 'caption_raw' => $index['caption_raw'], 'product_title_override' => $title, 'description_override' => $description, 'image_file_id' => (string) $attachment_id, 'image_url' => $attachment_id ? ( wp_get_attachment_url( $attachment_id ) ?: '' ) : '', 'download_url_final' => $stored['url'], 'file_size_bytes' => $stored['size_bytes'] ?? null, 'status' => 'processing' ) ); $session = STI_Session::get( $sid ); $pid = STI_Product_Builder::build( $session, $category ); if ( is_wp_error( $pid ) ) { STI_Session::mark_error( $sid, $pid->get_error_message() ); return $this->dispatch_error( $dispatch, $pid->get_error_message() ); } STI_Scheduler::enqueue( $sid, $pid ); $wpdb->update( self::index_table(), array( 'product_id' => $pid, 'session_id' => $sid, 'product_status' => 'created', 'dispatch_status' => 'product_created', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $index['id'] ) ); $wpdb->update( self::dispatch_table(), array( 'status' => 'product_created', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $dispatch['id'] ) ); $profile = $this->get_profile( $index['profile_id'] ); if ( $profile ) { $this->update_profile( $profile['id'], array( 'products_created' => (int) $profile['products_created'] + 1 ) ); } if ( $inbox_row && class_exists( 'STI_Bot_Inbox' ) ) { STI_Bot_Inbox::mark( $inbox_row['id'], 'downloaded' ); } return true;
	}
}
