<?php
/*
STI Agent Bridge
 
Receives large files from VPS Telethon Agent via chunked HTTPS,
* stores them using existing STI_File_Storage (FTP to dl.goldenfile.ir), *creates products using existing STI_Product_Builder + STI_Scheduler.
 
ZERO impact on existing plugin functionality.
* All existing files remain UNCHANGED. */
if ( ! defined( 'ABSPATH' ) ) {
exit;
}
class STI_Agent_Bridge {
protected static $instance;
/* Constants */
const REST_NS = 'golden-importer/v1';
const OPT = 'sti_agent_';
const TEMP_DIR = 'golden-agent-temp';
const DB_TABLE = 'sti_agent_jobs';
const DB_VER_KEY = 'sti_agent_db_ver';
const DB_VER = '1.0';
const MAX_CHUNK = 10485760; // 10 MB
const TEMP_EXPIRY_H = 48;
const TS_TOLERANCE = 300; // 5 min
const RL_MAX = 120;
const RL_WINDOW = 60;
/* Job statuses */
const S_CREATED = 'created';
const S_UP_IMG = 'uploading_image';
const S_IMG_READY = 'image_ready';
const S_UP_FILE = 'uploading_file';
const S_FILE_READY = 'file_ready';
const S_VERIFY = 'verifying';
const S_TRANSFER = 'transferring';
const S_BUILD = 'building';
const S_DONE = 'draft_created';
const S_FAIL = 'failed';
const S_CANCEL = 'cancelled';
/* ================================================================ SINGLETON ================================================================ */
public static function instance() {
if ( ! self::$instance ) {
self::$instance = new self();
}
return self::$instance;
}
protected function __construct() {
add_action( 'rest_api_init', array( $this, 'register_routes' ) );
/* Temp cleanup cron */
add_action( 'sti_agent_cleanup_temp', array( $this, 'cron_cleanup' ) );
if ( ! wp_next_scheduled( 'sti_agent_cleanup_temp' ) ) {
wp_schedule_event( time(), 'hourly', 'sti_agent_cleanup_temp' );
}
self::maybe_create_table();
self::ensure_temp_dir();
/* Admin menu */
if ( is_admin() ) {
add_action( 'admin_menu', array( $this, 'admin_menu' ), 90 );
add_action( 'admin_init', array( $this, 'admin_actions' ) );
}
}
/* ================================================================ DATABASE ================================================================ */
public static function table_name() {
global $wpdb;
return $wpdb->prefix . self::DB_TABLE;
}
public static function maybe_create_table() {
if ( get_option( self::DB_VER_KEY ) === self::DB_VER ) {
return;
}
global $wpdb;
$t = self::table_name();
$c = $wpdb->get_charset_collate();
$sql = "CREATE TABLE {$t} (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
job_uuid CHAR(36) NOT NULL,
file_code VARCHAR(100) NOT NULL,
source_chat VARCHAR(255) NULL,
source_message_id BIGINT NULL,
topic_id BIGINT NULL,
category_id BIGINT UNSIGNED NULL,
storage_folder VARCHAR(100) NULL,
file_name VARCHAR(255) NULL,
file_type VARCHAR(50) NULL,
caption_raw LONGTEXT NULL,
image_filename VARCHAR(255) NULL,
image_temp_path TEXT NULL,
image_sha256 CHAR(64) NULL,
image_chunks_expected INT NULL,
image_chunks_received INT DEFAULT 0,
file_filename VARCHAR(255) NULL,
file_temp_path TEXT NULL,
file_size_bytes BIGINT NULL,
file_sha256 CHAR(64) NULL,
file_chunks_expected INT NULL,
file_chunks_received INT DEFAULT 0,
download_url_final TEXT NULL,
product_id BIGINT UNSIGNED NULL,
session_id BIGINT UNSIGNED NULL,
status VARCHAR(40) NOT NULL DEFAULT 'created',
attempts SMALLINT DEFAULT 0,
last_error TEXT NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
completed_at DATETIME NULL,
UNIQUE KEY idx_uuid (job_uuid),
KEY idx_code (file_code),
KEY idx_status (status),
KEY idx_topic (topic_id),
KEY idx_created (created_at)
) {$c};";
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( $sql );
update_option( self::DB_VER_KEY, self::DB_VER );
}
/* ================================================================ TEMP DIRECTORY ================================================================ */
public static function temp_base() {
$u = wp_upload_dir();
return $u['basedir'] . '/' . self::TEMP_DIR;
}
public static function ensure_temp_dir() {
$d = self::temp_base();
if ( ! is_dir( $d ) ) {
wp_mkdir_p( $d );
}
if ( ! file_exists( $d . '/index.php' ) ) {
file_put_contents( $d . '/index.php', '<?php // Silence.' );
}
if ( ! file_exists( $d . '/.htaccess' ) ) {
file_put_contents( $d . '/.htaccess',
"Deny from all\nOptions -Indexes\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
);
}
}
protected static function chunk_dir( $uuid, $asset ) {
return self::temp_base() . '/' . sanitize_file_name( $uuid ) . '/' . sanitize_file_name( $asset );
}
protected static function assembled_path( $uuid, $asset, $fname ) {
return self::temp_base() . '/' . sanitize_file_name( $uuid ) . '/asm_' . $asset . '_' . sanitize_file_name( $fname );
}
/* ================================================================ SETTINGS ================================================================ */
public static function is_enabled() {
return '1' === get_option( self::OPT . 'enabled', '0' );
}
public static function get_mappings() {
$m = get_option( self::OPT . 'topic_mappings', array() );
if ( empty( $m ) ) {
return array( array(
'topic_id' => 60463,
'topic_title' => 'Logo',
'category_id' => 0,
'woo_term_id' => 142,
'storage_folder' => 'logo',
) );
}
return $m;
}
public static function find_mapping( $topic_id ) {
foreach ( self::get_mappings() as $m ) {
if ( (int) $m['topic_id'] === (int) $topic_id ) {
return $m;
}
}
return null;
}
public static function resolve_category( $mapping ) {
if ( ! empty( $mapping['category_id'] ) ) {
$c = STI_Category::get( (int) $mapping['category_id'] );
if ( $c ) return $c;
}
if ( ! empty( $mapping['woo_term_id'] ) ) {
global $wpdb;
$c = $wpdb->get_row( $wpdb->prepare(
'SELECT* FROM ' . $wpdb->prefix . 'sti_categories WHERE woo_term_id = %d AND is_active = 1 LIMIT 1', (int) $mapping['woo_term_id'] ) ); if ( $c ) return $c; } return null; } /* ================================================================ SECURITY (HMAC) ================================================================ */
protected function auth( WP_REST_Request $r, $chunk = false ) {
if ( ! self::is_enabled() ) {
return new WP_Error( 'disabled', 'Agent Bridge غیرفعال.', array( 'status' => 403 ) );
}
$key = $r->get_header( 'X-Golden-Agent-Key' );
$ts = $r->get_header( 'X-Golden-Agent-Timestamp' );
$sig = $r->get_header( 'X-Golden-Agent-Signature' );
if ( ! $key || ! $ts || ! $sig ) {
return new WP_Error( 'no_auth', 'هدرهای احراز هویت ناقص.', array( 'status' => 401 ) );
}
if ( ! hash_equals( get_option( self::OPT . 'api_key', '' ), $key ) ) {
return new WP_Error( 'bad_key', 'کلید نامعتبر.', array( 'status' => 401 ) );
}
if ( abs( time() - intval( $ts ) ) > self::TS_TOLERANCE ) {
return new WP_Error( 'expired', 'Timestamp منقضی.', array( 'status' => 401 ) );
}
$secret = get_option( self::OPT . 'secret', '' );
if ( ! $secret ) {
return new WP_Error( 'no_secret', 'Secret تنظیم نشده.', array( 'status' => 500 ) );
}
if ( $chunk ) {
$payload = implode( '|', array(
$ts,
$r->get_header( 'X-Job-UUID' ) ?: $r->get_param( 'job_id' ),
$r->get_header( 'X-Asset-Type' ),
$r->get_header( 'X-Chunk-Index' ),
$r->get_header( 'X-Chunk-Total' ),
$r->get_header( 'X-Chunk-SHA256' ),
$r->get_header( 'X-File-SHA256' ) ?: '',
) );
} else {
$payload = $ts . '.' . $r->get_body();
}
if ( ! hash_equals( hash_hmac( 'sha256', $payload, $secret ), $sig ) ) {
return new WP_Error( 'bad_sig', 'امضای نامعتبر.', array( 'status' => 401 ) );
}
/* Replay */
$nk = 'sti_ag_n_' . md5( $ts . $sig );
if ( get_transient( $nk ) ) {
return new WP_Error( 'replay', 'تکراری.', array( 'status' => 409 ) );
}
set_transient( $nk, 1, 600 );
/* Rate limit */
$ip = self::ip();
$rlk = 'sti_ag_rl_' . md5( $ip );
$cnt = intval( get_transient( $rlk ) );
if ( $cnt >= self::RL_MAX ) {
return new WP_Error( 'rate', 'محدودیت تعداد.', array( 'status' => 429 ) );
}
set_transient( $rlk, $cnt + 1, self::RL_WINDOW );
/* IP allowlist */
$al = trim( get_option( self::OPT . 'ip_allowlist', '' ) );
if ( $al ) {
$ok = false;
foreach ( array_filter( array_map( 'trim', explode( "\n", $al ) ) ) as $a ) {
if ( $a === $ip ) { $ok = true; break; }
}
if ( ! $ok ) {
return new WP_Error( 'ip', 'IP مجاز نیست.', array( 'status' => 403 ) );
}
}
update_option( self::OPT . 'last_request', current_time( 'mysql' ), false );
return true;
}
protected static function ip() {
foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $h ) {
if ( ! empty( $_SERVER[ $h ] ) ) return trim( explode( ',', $_SERVER[ $h ] )[0] );
}
return '0.0.0.0';
}
/* ================================================================ JOB HELPERS ================================================================ */
protected function job( $uuid ) {
global $wpdb;
return $wpdb->get_row( $wpdb->prepare(
'SELECT* FROM ' . self::table_name() . ' WHERE job_uuid = %s', $uuid ), ARRAY_A ); } protected function job_by_code( $code ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT *FROM ' . self::table_name() . ' WHERE file_code = %s AND status != %s ORDER BY id DESC LIMIT 1',
$code, self::S_CANCEL
), ARRAY_A );
}
protected function upd( $uuid, $d ) {
global $wpdb;
$d['updated_at'] = current_time( 'mysql' );
return $wpdb->update( self::table_name(), $d, array( 'job_uuid' => $uuid ) );
}
protected function status( $uuid, $st, $err = null ) {
$d = array( 'status' => $st );
if ( null !== $err ) $d['last_error'] = $err;
if ( self::S_DONE === $st ) $d['completed_at'] = current_time( 'mysql' );
if ( self::S_FAIL === $st ) {
global $wpdb;
$wpdb->query( $wpdb->prepare(
'UPDATE ' . self::table_name() . ' SET attempts = attempts + 1 WHERE job_uuid = %s', $uuid
) );
}
STI_Logger::info( "Agent job {$uuid} → {$st}" . ( $err ? " | {$err}" : '' ) );
return $this->upd( $uuid, $d );
}
protected function active_count() {
global $wpdb;
return (int) $wpdb->get_var( $wpdb->prepare(
'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE status NOT IN (%s,%s,%s)', self::S_DONE, self::S_FAIL, self::S_CANCEL ) ); } /* ================================================================ REST ROUTES ================================================================ */
public function register_routes() {
$ns = self::REST_NS;
register_rest_route( $ns, '/agent/jobs', array(
'methods' => 'POST', 'callback' => array( $this, 'r_create' ),
'permission_callback' => function( $r ) { return $this->auth( $r ); },
) );
register_rest_route( $ns, '/agent/jobs/(?P<job_id>[a-f0-9\-]{36})/chunks', array(
'methods' => 'POST', 'callback' => array( $this, 'r_chunk' ),
'permission_callback' => function( $r ) { return $this->auth( $r, true ); },
) );
register_rest_route( $ns, '/agent/jobs/(?P<job_id>[a-f0-9\-]{36})/complete-image', array(
'methods' => 'POST', 'callback' => array( $this, 'r_img_done' ),
'permission_callback' => function( $r ) { return $this->auth( $r ); },
) );
register_rest_route( $ns, '/agent/jobs/(?P<job_id>[a-f0-9\-]{36})/complete-file', array(
'methods' => 'POST', 'callback' => array( $this, 'r_file_done' ),
'permission_callback' => function( $r ) { return $this->auth( $r ); },
) );
register_rest_route( $ns, '/agent/jobs/(?P<job_id>[a-f0-9\-]{36})', array(
'methods' => 'GET', 'callback' => array( $this, 'r_status' ),
'permission_callback' => function( $r ) { return $this->auth( $r ); },
) );

		register_rest_route( $ns, '/agent/tasks', array(
			'methods' => 'GET', 'callback' => array( $this, 'r_get_tasks' ),
			'permission_callback' => function( $r ) { return $this->auth( $r ); },
		) );
		register_rest_route( $ns, '/agent/tasks/(?P<id>\d+)/complete', array(
			'methods' => 'POST', 'callback' => array( $this, 'r_complete_task' ),
			'permission_callback' => function( $r ) { return $this->auth( $r ); },
		) );
		register_rest_route( $ns, '/agent/health', array(
'methods' => 'GET', 'callback' => array( $this, 'r_health' ),
'permission_callback' => function( $r ) { return $this->auth( $r ); },
) );
}
/* ================================================================ REST: CREATE JOB ================================================================ */
public function r_create( WP_REST_Request $r ) {
$p = $r->get_json_params();
$code = sanitize_text_field( $p['file_code'] ?? '' );
if ( ! $code ) {
return new WP_Error( 'no_code', 'file_code الزامی.', array( 'status' => 400 ) );
}
/* ── Duplicate check: WooCommerce SKU ── */
$sku = 'STI-' . sanitize_title( $code );
$dup = wc_get_product_id_by_sku( $sku );
if ( $dup && 'trash' !== get_post_status( $dup ) ) {
return new WP_REST_Response( array(
'success' => true, 'is_existing' => true,
'product_id' => $dup, 'message' => 'محصول تکراری.',
), 200 );
}
/* ── Duplicate check: Agent jobs ── */
$ex = $this->job_by_code( $code );
if ( $ex && $ex['status'] === self::S_DONE ) {
return new WP_REST_Response( array(
'success' => true, 'is_existing' => true,
'job_uuid' => $ex['job_uuid'], 'product_id' => (int) $ex['product_id'],
), 200 );
}
if ( $ex && ! in_array( $ex['status'], array( self::S_FAIL, self::S_CANCEL ) ) ) {
return new WP_REST_Response( array(
'success' => true, 'is_existing' => true,
'job_uuid' => $ex['job_uuid'], 'status' => $ex['status'],
), 200 );
}
/* ── Topic mapping (category from SERVER, not from Agent) ── */
$tid = intval( $p['topic_id'] ?? 0 );
$map = self::find_mapping( $tid );
if ( ! $map ) {
return new WP_Error( 'bad_topic', "Topic {$tid} مجاز نیست.", array( 'status' => 400 ) );
}
$cat = self::resolve_category( $map );
if ( ! $cat ) {
return new WP_Error( 'no_cat', 'دسته‌بندی پیدا نشد.', array( 'status' => 400 ) );
}
/* ── Max jobs ── */
$max = intval( get_option( self::OPT . 'max_jobs', 10 ) );
if ( $this->active_count() >= $max ) {
return new WP_Error( 'max', "حداکثر {$max} Job فعال.", array( 'status' => 429 ) );
}
$uuid = wp_generate_uuid4();
$now = current_time( 'mysql' );
global $wpdb;
$wpdb->insert( self::table_name(), array(
'job_uuid' => $uuid,
'file_code' => $code,
'source_chat' => sanitize_text_field( $p['source_chat'] ?? '' ),
'source_message_id' => intval( $p['source_message_id'] ?? 0 ),
'topic_id' => $tid,
'category_id' => $cat->id,
'storage_folder' => sanitize_file_name( $map['storage_folder'] ?? '' ),
'file_name' => sanitize_text_field( $p['file_name'] ?? '' ),
'file_type' => sanitize_text_field( $p['file_type'] ?? '' ),
'caption_raw' => wp_kses_post( $p['caption_raw'] ?? '' ),
'file_size_bytes' => intval( $p['file_size_bytes'] ?? 0 ),
'file_sha256' => sanitize_text_field( $p['file_sha256'] ?? '' ),
'image_sha256' => sanitize_text_field( $p['image_sha256'] ?? '' ),
'file_chunks_expected' => intval( $p['file_chunks_expected'] ?? 0 ),
'image_chunks_expected' => intval( $p['image_chunks_expected'] ?? 0 ),
'status' => self::S_CREATED,
'created_at' => $now,
'updated_at' => $now,
) );
STI_Logger::info( "Agent job created: {$uuid}, code={$code}, cat={$cat->telegram_label}" );
return new WP_REST_Response( array(
'success' => true, 'is_existing' => false,
'job_uuid' => $uuid, 'status' => self::S_CREATED,
'category' => $cat->telegram_label,
), 201 );
}
/* ================================================================ REST: CHUNK UPLOAD ================================================================ */
public function r_chunk( WP_REST_Request $r ) {
$uuid = $r->get_param( 'job_id' );
$asset = $r->get_header( 'X-Asset-Type' ) ?: 'file';
$ci = intval( $r->get_header( 'X-Chunk-Index' ) );
$ct = intval( $r->get_header( 'X-Chunk-Total' ) );
$csha = $r->get_header( 'X-Chunk-SHA256' ) ?: '';
$body = $r->get_body();
if ( ! in_array( $asset, array( 'image', 'file' ) ) ) {
return new WP_Error( 'type', 'نوع نامعتبر.', array( 'status' => 400 ) );
}
if ( strlen( $body ) > self::MAX_CHUNK || strlen( $body ) === 0 ) {
return new WP_Error( 'size', 'اندازه chunk نامعتبر.', array( 'status' => 400 ) );
}
if ( ! hash_equals( $csha, hash( 'sha256', $body ) ) ) {
return new WP_Error( 'corrupt', 'SHA-256 chunk نامعتبر.', array( 'status' => 400 ) );
}
$job = $this->job( $uuid );
if ( ! $job ) {
return new WP_Error( 'nf', 'Job نیست.', array( 'status' => 404 ) );
}
$dir = self::chunk_dir( $uuid, $asset );
if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
file_put_contents( $dir . '/chunk_' . str_pad( $ci, 6, '0', STR_PAD_LEFT ), $body );
$recv = 0;
for ( $i = 0; $i < $ct; $i++ ) {
if ( file_exists( $dir . '/chunk_' . str_pad( $i, 6, '0', STR_PAD_LEFT ) ) ) $recv++;
}
$pfx = ( 'image' === $asset ) ? 'image_' : 'file_';
$this->upd( $uuid, array( $pfx . 'chunks_received' => $recv, $pfx . 'chunks_expected' => $ct ) );
if ( 'image' === $asset && $job['status'] === self::S_CREATED ) {
$this->status( $uuid, self::S_UP_IMG );
} elseif ( 'file' === $asset && in_array( $job['status'], array( self::S_IMG_READY, self::S_CREATED ) ) ) {
$this->status( $uuid, self::S_UP_FILE );
}
return new WP_REST_Response( array(
'success' => true, 'chunk_index' => $ci,
'chunks_received' => $recv, 'chunks_expected' => $ct,
'all_received' => ( $recv >= $ct ),
), 200 );
}
/* ================================================================ ASSEMBLE CHUNKS ================================================================ */
protected function assemble( $uuid, $asset, $fname, $expect_sha ) {
$job = $this->job( $uuid );
$pfx = ( 'image' === $asset ) ? 'image_' : 'file_';
$exp = intval( $job[ $pfx . 'chunks_expected' ] );
if ( $exp <= 0 ) {
return new WP_Error( 'no_chunks', 'chunk انتظار نمی‌رود.' );
}
$dir = self::chunk_dir( $uuid, $asset );
for ( $i = 0; $i < $exp; $i++ ) {
if ( ! file_exists( $dir . '/chunk_' . str_pad( $i, 6, '0', STR_PAD_LEFT ) ) ) {
return new WP_Error( 'miss', "Chunk {$i} نیست." );
}
}
/* Extension validation */
$ext = strtolower( pathinfo( $fname, PATHINFO_EXTENSION ) );
$allowed = ( 'image' === $asset )
? array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' )
: STI_Security::allowed_download_extensions();
if ( ! in_array( $ext, $allowed ) ) {
return new WP_Error( 'ext', "پسوند .{$ext} مجاز نیست." );
}
$out = self::assembled_path( $uuid, $asset, $fname );
$fp = fopen( $out, 'wb' );
$hctx = hash_init( 'sha256' );
$sz = 0;
for ( $i = 0; $i < $exp; $i++ ) {
$chunk = file_get_contents( $dir . '/chunk_' . str_pad( $i, 6, '0', STR_PAD_LEFT ) );
fwrite( $fp, $chunk );
hash_update( $hctx, $chunk );
$sz += strlen( $chunk );
unset( $chunk );
}
fclose( $fp );
$actual = hash_final( $hctx );
if ( $expect_sha && ! hash_equals( $expect_sha, $actual ) ) {
return new WP_Error( 'sha', 'SHA-256 فایل نهایی مطابقت ندارد.' );
}
/* Cleanup chunks */
$files = glob( $dir . '/chunk_*' ); if ( $files ) foreach ( $files as $f ) @unlink( $f ); @rmdir( $dir ); $this->upd( $uuid, array( $pfx . 'temp_path' => $out, $pfx . 'filename' => sanitize_file_name( $fname ), ) ); if ( 'file' === $asset ) { $this->upd( $uuid, array( 'file_size_bytes' => $sz ) ); } return $out; } /* ================================================================ REST: COMPLETE IMAGE ================================================================ */
public function r_img_done( WP_REST_Request $r ) {
$uuid = $r->get_param( 'job_id' );
$p = $r->get_json_params();
$job = $this->job( $uuid );
if ( ! $job ) return new WP_Error( 'nf', 'Job نیست.', array( 'status' => 404 ) );
$fn = sanitize_file_name( $p['filename'] ?? 'image.jpg' );
$sha = sanitize_text_field( $p['sha256'] ?? $job['image_sha256'] ?? '' );
$res = $this->assemble( $uuid, 'image', $fn, $sha );
if ( is_wp_error( $res ) ) {
$this->status( $uuid, self::S_FAIL, $res->get_error_message() );
return $res;
}
$this->status( $uuid, self::S_IMG_READY );
return new WP_REST_Response( array( 'success' => true, 'status' => 'image_ready' ), 200 );
}
/* ================================================================ REST: COMPLETE FILE → FTP + Product ================================================================ */
public function r_file_done( WP_REST_Request $r ) {
if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 600 );
$uuid = $r->get_param( 'job_id' );
$p = $r->get_json_params();
$job = $this->job( $uuid );
if ( ! $job ) return new WP_Error( 'nf', 'Job نیست.', array( 'status' => 404 ) );
/* Image must exist */
if ( empty( $job['image_temp_path'] ) || ! file_exists( $job['image_temp_path'] ) ) {
return new WP_Error( 'no_img', 'تصویر آماده نیست.', array( 'status' => 400 ) );
}
/* Assemble file */
$fn = sanitize_file_name( $p['filename'] ?? $job['file_name'] ?? '[file.zip' );
$sha = sanitize_text_field( $p['sha256'] ?? $job['file_sha256'] ?? '' );
$this->status( $uuid, self::S_FILE_READY );
$asm = $this->assemble( $uuid, 'file', $fn, $sha );
if ( is_wp_error( $asm ) ) {
$this->status( $uuid, self::S_FAIL, $asm->get_error_message() );
return $asm;
}
/* ── STEP 1: Image → Attachment ── */
$this->status( $uuid, self::S_VERIFY );
$job = $this->job( $uuid );
$att = STI_File_Storage::store_image_from_local_file(
$job['image_temp_path'],
$job['file_name'] ?: 'agent-image',
sanitize_file_name( $job['image_filename'] ?: 'agent-' . $job['file_code'] . '.jpg' )
);
if ( is_wp_error( $att ) ) {
$this->status( $uuid, self::S_FAIL, 'تصویر: ' . $att->get_error_message() );
return $att;
}
/* ── STEP 2: File → FTP (existing STI_File_Storage) ── */
$this->status( $uuid, self::S_TRANSFER );
$cat = STI_Category::get( (int) $job['category_id'] );
$smod = $cat ? STI_Category::storage_mode( $cat ) : null;
$fmeta = array(
'file_code' => $job['file_code'],
'file_name' => $job['file_name'],
'original_name' => $job['file_filename'],
'category_folder' => $cat
? ( $cat->folder_key ?: STI_Category::sanitize_folder_key( $cat->telegram_label, $cat->id ) )
: ( $job['storage_folder'] ?: 'misc' ),
);
$stor = STI_File_Storage::process_local_temp_file( $job['file_temp_path'], $fmeta, $smod );
if ( is_wp_error( $stor ) ) {
$this->status( $uuid, self::S_FAIL, 'FTP: ' . $stor->get_error_message() );
/* Do NOT delete temp — allow retry */
return $stor;
}
$final_url = $stor['url'];
$this->upd( $uuid, array(
'download_url_final' => $final_url,
'file_size_bytes' => $stor['size_bytes'] ?? $job['file_size_bytes'],
) );
/* Delete temp file */
if ( file_exists( $job['file_temp_path'] ) ) @unlink( $job['file_temp_path'] );
/* ── STEP 3: Session + Product (existing builders) ── */
$this->status( $uuid, self::S_BUILD );
$job = $this->job( $uuid );
/* DUPLICATE CHECK again right before building */
$sku = 'STI-' . sanitize_title( $job['file_code'] );
$dup = wc_get_product_id_by_sku( $sku );
if ( $dup && 'trash' !== get_post_status( $dup ) ) {
$this->upd( $uuid, array( 'product_id' => $dup ) );
$this->status( $uuid, self::S_DONE );
return new WP_REST_Response( array(
'success' => true, 'status' => 'draft_created',
'product_id' => $dup, 'message' => 'محصول تکراری بود.',
), 200 );
}
/* Create STI_Session */
$sid = STI_Session::create( 0, null, (int) $job['category_id'] );
$parsed = STI_Caption_Parser::parse( $job['caption_raw'] ?: '', array() );
STI_Session::update( $sid, array(
'file_code' => $job['file_code'],
'file_name' => $parsed['file_name'] ?: $job['file_name'],
'file_type' => $parsed['file_type'] ?: $job['file_type'],
'source_url' => $parsed['source_url'] ?: '',
'dimensions' => $parsed['dimensions'] ?: '',
'resolution' => $parsed['resolution'] ?: '',
'color' => $parsed['color'] ?: '',
'caption_raw' => $job['caption_raw'],
'download_url_final' => $final_url,
'file_size_bytes' => $job['file_size_bytes'],
'image_url' => wp_get_attachment_url( $att ),
'status' => 'processing',
) );
$session = STI_Session::get( $sid );
/* Build product */
$pid = STI_Product_Builder::build( $session, $cat );
if ( is_wp_error( $pid ) ) {
STI_Session::mark_error( $sid, $pid->get_error_message() );
$this->status( $uuid, self::S_FAIL, $pid->get_error_message() );
return $pid;
}
/* Ensure correct featured image */
set_post_thumbnail( $pid, $att );
/* Enqueue in scheduler */
STI_Scheduler::enqueue( $sid, $pid );
$this->upd( $uuid, array( 'product_id' => $pid, 'session_id' => $sid ) );
$this->status( $uuid, self::S_DONE );
/* Cleanup job temp dir */
$jdir = self::temp_base() . '/' . sanitize_file_name( $uuid );
if ( is_dir( $jdir ) ) self::rrmdir( $jdir );
$qinfo = STI_Scheduler::get_status();
STI_Logger::success( "Agent: محصول #{$pid} ساخته و به صف اضافه شد. صف: {$qinfo['queued_count']}", $sid );
return new WP_REST_Response( array(
'success' => true,
'status' => 'draft_created',
'product_id' => $pid,
'session_id' => $sid,
'download_url' => $final_url,
'queue_position' => $qinfo['queued_count'],
), 201 );
}
/* ================================================================ REST: GET JOB ================================================================ */
public function r_status( WP_REST_Request $r ) {
$uuid = $r->get_param( 'job_id' );
$job = $this->job( $uuid );
if ( ! $job ) return new WP_Error( 'nf', 'Job نیست.', array( 'status' => 404 ) );
return new WP_REST_Response( array(
'job_uuid' => $job['job_uuid'],
'file_code' => $job['file_code'],
'status' => $job['status'],
'attempts' => (int) $job['attempts'],
'last_error' => $job['last_error'],
'product_id' => $job['product_id'] ? (int) $job['product_id'] : null,
'download_url' => $job['download_url_final'],
'image_chunks' => $this->chunk_info( $uuid, 'image', $job ),
'file_chunks' => $this->chunk_info( $uuid, 'file', $job ),
'created_at' => $job['created_at'],
'updated_at' => $job['updated_at'],
), 200 );
}
protected function chunk_info( $uuid, $asset, $job ) {
$pfx = ( 'image' === $asset ) ? 'image_' : 'file_';
$exp = intval( $job[ $pfx . 'chunks_expected' ] );
$ex = array();
$dir = self::chunk_dir( $uuid, $asset );
if ( is_dir( $dir ) ) {
for ( $i = 0; $i < $exp; $i++ ) {
if ( file_exists( $dir . '/chunk_' . str_pad( $i, 6, '0', STR_PAD_LEFT ) ) ) $ex[] = $i;
}
}
return array( 'expected' => $exp, 'received' => count( $ex ), 'existing_chunks' => $ex );
}
/* ================================================================ REST: HEALTH ================================================================ */
public function r_health( WP_REST_Request $r ) {
return new WP_REST_Response( array(
'status' => 'ok',
'version' => STI_VERSION,
'enabled' => self::is_enabled(),
'active' => $this->active_count(),
'max' => intval( get_option( self::OPT . 'max_jobs', 10 ) ),
'queue' => STI_Scheduler::get_status(),
), 200 );
}
/* ================================================================ CRON CLEANUP ================================================================ */
public function cron_cleanup() {
$base = self::temp_base();
if ( ! is_dir( $base ) ) return;
$cut = time() - ( self::TEMP_EXPIRY_H* 3600 ); $dirs = glob( $base . '/*', GLOB_ONLYDIR ); if ( ! $dirs ) return; foreach ( $dirs as $d ) { if ( filemtime( $d ) < $cut ) { $uuid = basename( $d ); $job = $this->job( $uuid ); if ( ! $job || in_array( $job['status'], array( self::S_DONE, self::S_CANCEL ) ) ) { self::rrmdir( $d ); } } } } protected static function rrmdir( $d ) { if ( ! is_dir( $d ) ) return; foreach ( scandir( $d ) as $i ) { if ( '.' === $i || '..' === $i ) continue; $p = $d . '/' . $i; is_dir( $p ) ? self::rrmdir( $p ) : @unlink( $p ); } @rmdir( $d ); } /* ================================================================ ADMIN MENU ================================================================ */
public function admin_menu() {
add_submenu_page(
'sanil-telegram-importer',
'Agent Bridge', '🤖 Agent Bridge',
'manage_woocommerce', 'sti-agent-bridge',
array( $this, 'admin_page' )
);
}
public function admin_actions() {
if ( ! current_user_can( 'manage_woocommerce' ) ) return;
/* Save */
if ( isset( $_POST['sti_agent_save'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'sti_agent_settings' ) ) {
update_option( self::OPT . 'enabled', isset( $_POST['agent_enabled'] ) ? '1' : '0' );
update_option( self::OPT . 'max_jobs', max( 1, min( 100, intval( $_POST['max_jobs'] ?? 10 ) ) ) );
update_option( self::OPT . 'ip_allowlist', sanitize_textarea_field( $_POST['ip_allowlist'] ?? '' ) );
if ( isset( $_POST['tm'] ) && is_array( $_POST['tm'] ) ) {
$maps = array();
foreach ( $_POST['tm'] as $m ) {
if ( ! empty( $m['topic_id'] ) ) {
$maps[] = array(
'topic_id' => intval( $m['topic_id'] ),
'topic_title' => sanitize_text_field( $m['topic_title'] ?? '' ),
'category_id' => intval( $m['category_id'] ?? 0 ),
'woo_term_id' => intval( $m['woo_term_id'] ?? 0 ),
'storage_folder' => sanitize_file_name( $m['storage_folder'] ?? '' ),
);
}
}
update_option( self::OPT . 'topic_mappings', $maps );
}
add_action( 'admin_notices', function() {
echo '<div class="notice notice-success is-dismissible"><p>تنظیمات Agent Bridge ذخیره شد.</p></div>';
} );
}
/* Generate creds */
if ( isset( $_POST['sti_agent_gen'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'sti_agent_creds' ) ) {
$k = 'gak_' . bin2hex( random_bytes( 24 ) );
$s = 'gas_' . bin2hex( random_bytes( 32 ) );
update_option( self::OPT . 'api_key', $k );
update_option( self::OPT . 'secret', $s );
set_transient( 'sti_agent_new_creds', array( 'api_key' => $k, 'secret' => $s ), 120 );
}
/* Retry */
if ( isset( $_GET['sti_ag_retry'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'sti_ag_retry' ) ) {
$u = sanitize_text_field( $_GET['sti_ag_retry'] );
$j = $this->job( $u );
if ( $j && $j['status'] === self::S_FAIL ) $this->status( $u, self::S_CREATED );
}
/* Cancel */
if ( isset( $_GET['sti_ag_cancel'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'sti_ag_cancel' ) ) {
$u = sanitize_text_field( $_GET['sti_ag_cancel'] );
$this->status( $u, self::S_CANCEL );
$d = self::temp_base() . '/' . sanitize_file_name( $u );
if ( is_dir( $d ) ) self::rrmdir( $d );
}
}
public function admin_page() {
if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'دسترسی ندارید.' );
include STI_PATH . 'admin/views/agent-bridge.php';
}

	/* ================================================================
	   REST: TASKS (Import Plus)
	   ================================================================ */
	public function r_get_tasks( WP_REST_Request $r ) {
		$tasks = get_option( 'sti_agent_tasks', array() );
		if ( empty( $tasks ) ) {
			return new WP_REST_Response( array( 'tasks' => array() ), 200 );
		}
		
		$pending = array_filter( $tasks, function($t) { return $t['status'] === 'pending'; } );
		return new WP_REST_Response( array( 'tasks' => array_values( $pending ) ), 200 );
	}

	public function r_complete_task( WP_REST_Request $r ) {
		$task_id = (int) $r->get_param( 'id' );
		$tasks = get_option( 'sti_agent_tasks', array() );
		
		foreach ( $tasks as &$t ) {
			if ( $t['id'] === $task_id ) {
				$t['status'] = 'completed';
				$t['completed_at'] = current_time( 'mysql' );
			}
		}
		
		update_option( 'sti_agent_tasks', $tasks );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

}
