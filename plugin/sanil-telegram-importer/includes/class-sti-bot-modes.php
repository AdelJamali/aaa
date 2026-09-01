<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Two additive Telegram bot modes:
 *  - Bedoon Marz: group registration with MTProto-sized files.
 *  - Tartibaat: no File Code; photo/text followed by a document, matched FIFO.
 *
 * Existing single/bulk/Webhook flows are deliberately untouched. These modes
 * use their own durable inbox table and the existing MTProto, Storage,
 * Product Builder and Scheduler services.
 */
class STI_Bot_Modes {

	const TABLE_KEY = 'sti_bot_mode_items';
	const DB_VER_KEY = 'sti_bot_modes_db_ver';
	const DB_VER = '1.0';
	const MODE_UNLIMITED = 'unlimited';
	const MODE_ORDERED = 'ordered';

	protected static $instance;

	public static function instance() {
		if ( ! self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'sti_bot_modes_worker', array( $this, 'worker' ) );
	}

	public static function table() { global $wpdb; return $wpdb->prefix . self::TABLE_KEY; }

	public static function install() {
		global $wpdb;
		if ( get_option( self::DB_VER_KEY ) === self::DB_VER && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) === self::table() ) { return; }
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate(); $table = self::table();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			chat_id BIGINT NOT NULL,
			user_id BIGINT DEFAULT NULL,
			category_id BIGINT UNSIGNED NOT NULL,
			mode VARCHAR(20) NOT NULL,
			sequence_no BIGINT UNSIGNED NOT NULL DEFAULT 0,
			file_code VARCHAR(100) NULL,
			photo_message_id BIGINT NOT NULL DEFAULT 0,
			text_message_id BIGINT NOT NULL DEFAULT 0,
			file_message_id BIGINT NOT NULL DEFAULT 0,
			photo_file_id VARCHAR(255) NULL,
			doc_file_id VARCHAR(255) NULL,
			doc_file_name VARCHAR(255) NULL,
			text_raw LONGTEXT NULL,
			content_title TEXT NULL,
			content_description LONGTEXT NULL,
			status VARCHAR(25) NOT NULL DEFAULT 'open',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_retry_at DATETIME NULL,
			session_id BIGINT UNSIGNED DEFAULT NULL,
			product_id BIGINT UNSIGNED DEFAULT NULL,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY chat_mode_status (chat_id, mode, status, id),
			KEY code (chat_id, file_code),
			KEY file_message (chat_id, file_message_id),
			KEY retry (status, next_retry_at)
		) {$charset};";
		dbDelta( $sql ); update_option( self::DB_VER_KEY, self::DB_VER, false );
	}

	public static function activate( $chat_id, $user_id, $category_id, $mode ) {
		self::install();
		$mode = in_array( $mode, array( self::MODE_UNLIMITED, self::MODE_ORDERED ), true ) ? $mode : self::MODE_ORDERED;
		update_option( 'sti_bot_mode_' . (int) $chat_id, array( 'mode' => $mode, 'category_id' => (int) $category_id, 'user_id' => (int) $user_id, 'started_at' => time() ), false );
		return true;
	}

	public static function deactivate( $chat_id ) { delete_option( 'sti_bot_mode_' . (int) $chat_id ); }
	public static function config( $chat_id ) { $v = get_option( 'sti_bot_mode_' . (int) $chat_id, array() ); return is_array( $v ) ? $v : array(); }
	public static function active( $chat_id ) { $c = self::config( $chat_id ); return ! empty( $c['mode'] ) && ! empty( $c['category_id'] ); }

	protected function next_sequence( $chat_id, $mode ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sequence_no),0)+1 FROM ' . self::table() . ' WHERE chat_id = %d AND mode = %s', (int) $chat_id, $mode ) );
	}

	protected function text_parts( $text ) {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', trim( (string) $text ) ) ) ) );
		$title = $lines ? array_shift( $lines ) : 'فایل گرافیکی';
		$title = trim( preg_replace( '/^(?:title|name|نام|عنوان)\s*[:：]\s*/iu', '', $title ) );
		$desc = trim( implode( "\n", $lines ) );
		return array( 'title' => $title ?: 'فایل گرافیکی', 'description' => $desc ?: (string) $text );
	}

	protected function clean_line( $line ) {
		$line = trim( (string) $line ); if ( '' === $line ) { return ''; }
		if ( preg_match( '/(?:get\s+any|دریافت\s+هر|دانلود\s+هر).*(?:https?:\/\/|@\w*bot\b|t\.me)/iu', $line ) ) { return ''; }
		if ( preg_match( '/^\s*(?:https?:\/\/|www\.)\S+\s*$/iu', $line ) ) { return ''; }
		$line = preg_replace( '/\[([^\]]+)\]\([^\)]+\)/u', '$1', $line );
		$line = preg_replace( '/https?:\/\/\S+/iu', '', $line );
		$line = preg_replace( '/@\w*bot\b/iu', '', $line );
		return trim( preg_replace( '/\s{2,}/u', ' ', $line ) );
	}

	protected function content( $text, $file_type = 'ZIP' ) {
		$parts = $this->text_parts( $text ); $raw_title = $parts['title']; $desc = implode( "\n", array_values( array_filter( array_map( array( $this, 'clean_line' ), preg_split( '/\r\n|\r|\n/', $parts['description'] ) ) ) ) );
		$ai_title = $raw_title; $ai_desc = $desc;
		if ( class_exists( 'STI_AI' ) && STI_AI::is_ready() ) {
			try { $ai = STI_Content_Generator::ai_improve_title_and_description( $raw_title, $raw_title, $file_type, '' ); if ( is_array( $ai ) ) { if ( ! empty( $ai['title'] ) ) { $ai_title = $ai['title']; } if ( ! empty( $ai['description'] ) ) { $ai_desc = $ai['description']; } } } catch ( \Throwable $e ) { STI_Logger::warning( 'Bot Modes AI: ' . $e->getMessage() ); }
		}
		$subject = trim( preg_replace( '/^(?:دانلود|download)\s+/iu', '', (string) $ai_title ) ); $subject = trim( preg_replace( '/^(?:فایل|file)\s+/iu', '', $subject ) ); $subject = trim( $subject, "[]() \t\r\n" );
		$is_ui = false !== mb_stripos( $raw_title . ' ' . $subject, 'ui' ) || false !== mb_stripos( $raw_title . ' ' . $subject, 'user interface' ) || false !== mb_strpos( $raw_title . ' ' . $subject, 'رابط کاربری' );
		$subject = trim( preg_replace( '/\b(?:ui\s*kit|ui|user\s*interface|interface)\b/iu', '', $subject ) ); $subject = trim( preg_replace( '/رابط\s*کاربری/u', '', $subject ) ); if ( '' === $subject ) { $subject = $raw_title; }
		$title = 'دانلود ' . ( $is_ui ? 'رابط کاربری' : 'فایل' ) . ' با موضوع ' . trim( preg_replace( '/\s+/u', ' ', $subject ) );
		$clean_desc = implode( "\n", array_values( array_filter( array_map( array( $this, 'clean_line' ), preg_split( '/\r\n|\r|\n/', (string) $ai_desc ) ) ) ) );
		return array( 'title' => $title, 'description' => trim( 'عنوان اصلی فایل: ' . $raw_title . "\n\n" . $clean_desc ), 'english_title' => $raw_title );
	}

	protected function extract_code( $text, $file_name = '' ) {
		$parsed = STI_Caption_Parser::parse( (string) $text );
		$code = class_exists( 'STI_Channel_Index' ) ? STI_Channel_Index::normalize_code( $parsed['file_code'] ?? '' ) : '';
		if ( $code ) { return $code; }
		if ( preg_match( '/(?<!\d)(\d{5,})(?!\d)/', (string) $file_name, $m ) ) { return class_exists( 'STI_Channel_Index' ) ? STI_Channel_Index::normalize_code( $m[1] ) : $m[1]; }
		return '';
	}

	public static function cancel_open( $chat_id ) {
		global $wpdb; self::install();
		$wpdb->query( $wpdb->prepare( "UPDATE " . self::table() . " SET status = 'cancelled', updated_at = %s WHERE chat_id = %d AND status IN ('open','ready','processing')", current_time( 'mysql' ), (int) $chat_id ) );
	}

	protected function find_open_item( $chat_id, $mode, $file_code = '' ) {
		global $wpdb;
		if ( $file_code ) { $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE chat_id = %d AND mode = %s AND file_code = %s AND status = 'open' ORDER BY id DESC LIMIT 1", (int) $chat_id, $mode, $file_code ), ARRAY_A ); if ( $row ) { return $row; } }
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE chat_id = %d AND mode = %s AND status = 'open' AND (photo_message_id = 0 OR text_message_id = 0 OR file_message_id = 0) ORDER BY sequence_no ASC LIMIT 1", (int) $chat_id, $mode ), ARRAY_A );
	}

	protected function create_item( $chat_id, $user_id, $category_id, $mode, $code = '' ) {
		global $wpdb; $now = current_time( 'mysql' ); $wpdb->insert( self::table(), array( 'chat_id' => (int) $chat_id, 'user_id' => (int) $user_id, 'category_id' => (int) $category_id, 'mode' => $mode, 'sequence_no' => $this->next_sequence( $chat_id, $mode ), 'file_code' => $code ?: null, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now ) ); return (int) $wpdb->insert_id;
	}

	protected function update_item( $id, $data ) {
		global $wpdb; $allowed = array( 'file_code','photo_message_id','text_message_id','file_message_id','photo_file_id','doc_file_id','doc_file_name','text_raw','content_title','content_description','status','attempts','next_retry_at','session_id','product_id','last_error','updated_at' ); $row = array(); foreach ( $data as $k => $v ) { if ( in_array( $k, $allowed, true ) ) { $row[ $k ] = $v; } } if ( empty( $row ) ) { return 0; } $row['updated_at'] = current_time( 'mysql' ); return $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	public function receive( $message, $mode, $category_id ) {
		if ( empty( $message['chat']['id'] ) ) { return false; }
		$chat_id = (int) $message['chat']['id']; $user_id = (int) ( $message['from']['id'] ?? 0 ); $text = trim( (string) ( $message['caption'] ?? $message['text'] ?? '' ) ); $entities = $message['caption_entities'] ?? $message['entities'] ?? array(); $parsed = $text ? STI_Caption_Parser::parse( $text, $entities ) : array(); $code = $this->extract_code( $text, $message['document']['file_name'] ?? '' );
		if ( self::MODE_UNLIMITED === $mode && ! $code ) {
			return true; // File Code is the join key for the unlimited legacy workflow.
		}
		$item = $this->find_open_item( $chat_id, $mode, $code );
		if ( ! $item ) { $id = $this->create_item( $chat_id, $user_id, $category_id, $mode, $code ); $item = $this->get( $id ); }
		$updates = array();
		if ( $code && empty( $item['file_code'] ) ) { $updates['file_code'] = $code; }
		if ( ! empty( $message['photo'] ) ) { $photos = $message['photo']; $largest = end( $photos ); $updates['photo_message_id'] = (int) ( $message['message_id'] ?? 0 ); $updates['photo_file_id'] = $largest['file_id']; }
		if ( $text && ( empty( $item['text_raw'] ) || empty( $message['document'] ) ) ) { $updates['text_message_id'] = (int) ( $message['message_id'] ?? 0 ); $updates['text_raw'] = $text; $parts = $this->text_parts( $text ); $updates['content_title'] = $parts['title']; $updates['content_description'] = $parts['description']; }
		if ( ! empty( $message['document'] ) ) { $updates['file_message_id'] = (int) ( $message['message_id'] ?? 0 ); $updates['doc_file_id'] = $message['document']['file_id']; $updates['doc_file_name'] = $message['document']['file_name'] ?? ''; }
		if ( $updates ) { $this->update_item( $item['id'], $updates ); $item = $this->get( $item['id'] ); }
		if ( $item && $item['photo_message_id'] && $item['text_message_id'] && $item['file_message_id'] ) {
			$this->update_item( $item['id'], array( 'status' => 'ready' ) ); $this->schedule_worker();
			return true;
		}
		return true;
	}

	public function get( $id ) { global $wpdb; self::install(); return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); }

	protected function source_history_messages( $mt, $chat_id, $ids ) {
		$out = array(); $wanted = array_map( 'intval', (array) $ids );
		// Resolve exact messages first; this avoids losing older files when the
		// bot chat contains more than the latest 100 messages.
		foreach ( $wanted as $id ) {
			if ( ! $id ) { continue; }
			$fresh = $mt->refresh_message( $chat_id, $id );
			if ( is_array( $fresh ) ) {
				$normalized = $mt->normalize_message( $fresh );
				if ( $normalized ) { $normalized['sender_chat_id'] = $chat_id; $out[ $id ] = $normalized; }
			}
		}
		if ( count( $out ) < count( array_filter( $wanted ) ) ) {
			$history = $mt->get_history( $chat_id, 100, 0 );
			if ( ! is_wp_error( $history ) ) { foreach ( (array) ( $history['messages'] ?? array() ) as $m ) { if ( in_array( (int) $m['id'], $wanted, true ) ) { $out[ (int) $m['id'] ] = $m; } } }
		}
		return $out;
	}

	public function worker() {
		global $wpdb; $rows = $wpdb->get_results( "SELECT * FROM " . self::table() . " WHERE status = 'ready' AND (next_retry_at IS NULL OR next_retry_at <= NOW()) ORDER BY sequence_no ASC LIMIT 1", ARRAY_A ); if ( empty( $rows ) ) { return; } $item = $rows[0]; $mt = STI_MTProto::instance(); $history = $this->source_history_messages( $mt, (int) $item['chat_id'], array( $item['photo_message_id'], $item['file_message_id'] ) ); $photo = isset( $history[ (int) $item['photo_message_id'] ] ) ? $history[ (int) $item['photo_message_id'] ] : null; $file = isset( $history[ (int) $item['file_message_id'] ] ) ? $history[ (int) $item['file_message_id'] ] : null; if ( ! $photo || ! $file ) { return $this->fail( $item, 'پیام عکس یا فایل در تاریخچه MTProto پیدا نشد.' ); }
		$this->update_item( $item['id'], array( 'status' => 'processing', 'attempts' => (int) $item['attempts'] + 1 ) ); $tmp = trailingslashit( STI_MTProto::base_dir() ) . 'tmp'; if ( ! is_dir( $tmp ) ) { wp_mkdir_p( $tmp ); }
		$photo['sender_chat_id'] = $item['chat_id']; $file['sender_chat_id'] = $item['chat_id']; $img = $mt->download_media_robust( $photo, $tmp ); if ( is_wp_error( $img ) ) { return $this->fail( $item, $img->get_error_message() ); } $att = STI_File_Storage::store_image_from_local_file( $img['path'], $item['content_title'], 'bot-mode-' . $item['photo_message_id'] . '.jpg' ); if ( is_wp_error( $att ) ) { return $this->fail( $item, $att->get_error_message() ); }
		$zip = $mt->download_media_robust( $file, $tmp ); if ( is_wp_error( $zip ) ) { return $this->fail( $item, $zip->get_error_message() ); } $cat = STI_Category::get( $item['category_id'] ); $code = $item['file_code'] ?: 'bot-' . abs( (int) $item['chat_id'] ) . '-' . (int) $item['file_message_id']; $content = $this->content( $item['text_raw'], strtoupper( pathinfo( $item['doc_file_name'], PATHINFO_EXTENSION ) ?: 'ZIP' ) ); $meta = array( 'file_code' => $code, 'file_name' => $content['title'], 'original_name' => $zip['name'], 'category_folder' => $cat ? ( $cat->folder_key ?: STI_Category::sanitize_folder_key( $cat->telegram_label, $cat->id ) ) : '' ); $stored = STI_File_Storage::process_local_temp_file( $zip['path'], $meta, $cat ? STI_Category::storage_mode( $cat ) : null ); if ( is_wp_error( $stored ) ) { return $this->fail( $item, $stored->get_error_message() ); }
		$sid = STI_Session::create( $item['chat_id'], $item['user_id'], $item['category_id'] ); STI_Session::update( $sid, array( 'notify_chat_id' => $item['chat_id'], 'file_code' => $code, 'file_name' => $content['title'], 'file_type' => strtoupper( pathinfo( $item['doc_file_name'], PATHINFO_EXTENSION ) ?: 'ZIP' ), 'caption_raw' => $item['text_raw'], 'product_title_override' => $content['title'], 'description_override' => $content['description'], 'image_file_id' => (string) $att, 'image_url' => wp_get_attachment_url( $att ) ?: '', 'download_url_final' => $stored['url'], 'file_size_bytes' => $stored['size_bytes'] ?? null, 'status' => 'processing' ) ); $session = STI_Session::get( $sid ); $pid = STI_Product_Builder::build( $session, $cat ); if ( is_wp_error( $pid ) ) { return $this->fail( $item, $pid->get_error_message(), $sid ); } STI_Scheduler::enqueue( $sid, $pid ); $this->update_item( $item['id'], array( 'file_code' => $code, 'status' => 'product_created', 'session_id' => $sid, 'product_id' => $pid, 'last_error' => '' ) ); $api = new STI_Telegram_API(); $api->send_message( $item['chat_id'], '✅ محصول ساخته شد و در صف انتشار قرار گرفت. محصول #' . $pid ); $this->schedule_worker();
	}

	protected function fail( $item, $message, $sid = 0 ) { $attempts = (int) $item['attempts'] + 1; $final = $attempts >= 5; $this->update_item( $item['id'], array( 'status' => $final ? 'error' : 'ready', 'attempts' => $attempts, 'next_retry_at' => $final ? null : wp_date( 'Y-m-d H:i:s', time() + min( 3600, 60 * ( 2 ** min( 6, $attempts ) ) ), wp_timezone() ), 'session_id' => $sid ?: null, 'last_error' => mb_substr( (string) $message, 0, 1000 ) ) ); STI_Logger::error( 'Bot Modes #' . $item['id'] . ': ' . $message ); }

	protected function schedule_worker( $delay = 3 ) { if ( ! wp_next_scheduled( 'sti_bot_modes_worker' ) ) { wp_schedule_single_event( time() + max( 1, (int) $delay ), 'sti_bot_modes_worker' ); } }

	public function process_commands( $chat_id, $text ) { if ( '/done' === trim( $text ) || '/cancel' === trim( $text ) ) { self::deactivate( $chat_id ); return true; } return false; }
}
