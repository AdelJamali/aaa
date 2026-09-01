<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class STI_DB {
const DB_VERSION = '3.0'; // v7
public static function maybe_upgrade() {
if ( get_option( 'sti_db_version' ) !== self::DB_VERSION ) {
self::install();
update_option( 'sti_db_version', self::DB_VERSION );
}
}
public static function install() {
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$charset_collate = $wpdb->get_charset_collate();
$sessions = $wpdb->prefix . 'sti_sessions';
$categories = $wpdb->prefix . 'sti_categories';
$logs = $wpdb->prefix . 'sti_logs';
/* ── Sessions (unchanged) ─────────────────────────────── */
$sql = "CREATE TABLE {$sessions} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
chat_id BIGINT NOT NULL,
notify_chat_id BIGINT DEFAULT NULL,
product_title_override TEXT NULL,
description_override LONGTEXT NULL,
user_id BIGINT DEFAULT NULL,
status VARCHAR(20) NOT NULL DEFAULT 'open',
category_id BIGINT UNSIGNED DEFAULT NULL,
caption_raw TEXT NULL,
file_name VARCHAR(255) NULL,
file_type VARCHAR(50) NULL,
file_code VARCHAR(100) NULL,
source_url TEXT NULL,
image_file_id VARCHAR(255) NULL,
image_url TEXT NULL,
doc_file_id VARCHAR(255) NULL,
doc_file_name VARCHAR(255) NULL,
download_url_raw TEXT NULL,
download_url_final TEXT NULL,
file_size_bytes BIGINT UNSIGNED DEFAULT NULL,
dimensions VARCHAR(100) NULL,
resolution VARCHAR(50) NULL,
color VARCHAR(50) NULL,
product_id BIGINT UNSIGNED DEFAULT NULL,
error_message TEXT NULL,
queue_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
queue_last_attempt_at DATETIME NULL,
queue_next_attempt_at DATETIME NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY (id),
KEY chat_id (chat_id),
KEY status (status),
KEY chat_code_status (chat_id, file_code, status),
KEY queue_status_id (status, id),
KEY queue_next_attempt (status, queue_next_attempt_at)
) {$charset_collate};";
dbDelta( $sql );
/* ── Categories (unchanged) ───────────────────────────── */
$sql2 = "CREATE TABLE {$categories} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
telegram_label VARCHAR(100) NOT NULL,
folder_key VARCHAR(80) DEFAULT NULL,
woo_term_id BIGINT UNSIGNED DEFAULT NULL,
price DECIMAL(12,2) NOT NULL DEFAULT 0,
publish_delay_minutes INT DEFAULT NULL,
description_template TEXT NULL,
search_terms TEXT NULL,
storage_mode_override VARCHAR(20) DEFAULT NULL,
sort_order INT NOT NULL DEFAULT 0,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at DATETIME NOT NULL,
PRIMARY KEY (id)
) {$charset_collate};";
dbDelta( $sql2 );
$rows = $wpdb->get_results( "SELECT id, telegram_label FROM {$categories} WHERE folder_key IS NULL OR folder_key = ''" );
foreach ( $rows as $row ) {
$key = STI_Category::sanitize_folder_key( $row->telegram_label, $row->id );
$wpdb->update( $categories, array( 'folder_key' => $key ), array( 'id' => $row->id ) );
}
/* ── Logs (unchanged) ─────────────────────────────────── */
$sql3 = "CREATE TABLE {$logs} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
session_id BIGINT UNSIGNED DEFAULT 0,
level VARCHAR(20) NOT NULL DEFAULT 'info',
message TEXT NULL,
context TEXT NULL,
created_at DATETIME NOT NULL,
PRIMARY KEY (id),
KEY session_id (session_id),
KEY level (level)
) {$charset_collate};";
dbDelta( $sql3 );
/* ── Durable Channel Import index (search candidates) ── */
if ( class_exists( 'STI_Channel_Index' ) ) { STI_Channel_Index::install(); }
/* ── Agent Bridge table (NEW - additive) ──────────────── */
STI_Agent_Bridge::maybe_create_table();
/* ── v7: صندوق ورودی فایل‌های ربات (تطبیق پایدار) ───────── */
if ( class_exists( 'STI_Bot_Inbox' ) ) { STI_Bot_Inbox::install(); }
/* ── Seed categories (unchanged) ──────────────────────── */
if ( ! get_option( 'sti_categories_seeded' ) ) {
$vector_template = "%name%\n\n%excerpt%\n\nدر این طرح لایه‌باز از %type% استفاده شده.\nشما می‌توانید این فایل را در نرم‌افزار %software% ویرایش کنید.";
$psd_template = "%name%\n\n%excerpt%\n\nاین فایل با نرم‌افزار %software% قابل ویرایش است.";
$generic_template = "%name%\n\n%excerpt%\n\nبا نرم‌افزار %software% قابل استفاده است.";
$defaults = array(
'Mockup' => $psd_template,
'Vector' => $vector_template,
'PSD' => $psd_template,
'Font' => $generic_template,
'Icon' => $vector_template,
'Pattern' => $vector_template,
'Template' => $generic_template,
'Texture' => $vector_template,
'Motion' => $generic_template,
'3D' => $generic_template,
);
$order = 0;
foreach ( $defaults as $label => $tpl ) {
$wpdb->insert( $categories, array(
'telegram_label' => $label,
'price' => 0,
'description_template' => $tpl,
'sort_order' => $order++,
'is_active' => 1,
'created_at' => current_time( 'mysql' ),
) );
}
update_option( 'sti_categories_seeded', 1 );
}
}
public static function cleanup_old_logs() {
global $wpdb;
$table = $wpdb->prefix . 'sti_logs';
$wpdb->query( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
if ( class_exists( 'STI_Bot_Inbox' ) ) { STI_Bot_Inbox::cleanup(); }
if ( class_exists( 'STI_Channel_Index' ) ) { STI_Channel_Index::cleanup(); }
}
}
