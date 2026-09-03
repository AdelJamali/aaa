<?php
/**
 * Plugin Name:       Golden Importer
 * Plugin URI:        https://goldenfile.ir
 * Description:       Telegram to WooCommerce importer with Agent Bridge for large files
 * Version:           10.10
 * Author:            Golden File Team
 * Text Domain:       flavor-flavor
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 */
if ( ! defined( 'ABSPATH' ) ) {
exit;
}
define( 'STI_VERSION', '10.10' );
define( 'STI_FILE', __FILE__ );
define( 'STI_PATH', plugin_dir_path( __FILE__ ) );
define( 'STI_URL', plugin_dir_url( __FILE__ ) );
define( 'STI_BASENAME', plugin_basename( __FILE__ ) );
/* ── Core includes (unchanged) ────────────────────────────────── */
require_once STI_PATH . 'includes/class-sti-logger.php';
require_once STI_PATH . 'includes/class-sti-db.php';
require_once STI_PATH . 'includes/class-sti-settings.php';
require_once STI_PATH . 'includes/class-sti-security.php';
require_once STI_PATH . 'includes/class-sti-telegram-api.php';
require_once STI_PATH . 'includes/class-sti-caption-parser.php';
require_once STI_PATH . 'includes/class-sti-file-storage.php';
require_once STI_PATH . 'includes/class-sti-content-generator.php';
require_once STI_PATH . 'includes/class-sti-product-builder.php';
require_once STI_PATH . 'includes/class-sti-product-attributes.php';
require_once STI_PATH . 'includes/class-sti-scheduler.php';
require_once STI_PATH . 'includes/class-sti-session.php';
require_once STI_PATH . 'includes/class-sti-webhook.php';
require_once STI_PATH . 'includes/class-sti-category.php';
require_once STI_PATH . 'includes/class-sti-channel-index.php';
/* ── AutoCat — سیستم اتوکت (دسته‌بندی هوشمند) ─────────────────── */
require_once STI_PATH . 'includes/class-sti-autocat.php';

/* ══════════════════════════════════════════════════════════════════════════
   v7 — بارگذاری ایمن ماژول‌های تازه

   چرا این‌طور نوشته شده؟ اگر روی هاستی یکی از ماژول‌های تازه به هر دلیلی
   (نسخه‌ی PHP، افزونه‌ی ناسازگار، فایل نیمه‌آپلود‌شده) مشکل داشته باشد، نباید
   کل افزونه از کار بیفتد. این لودر:
     ۱) نسخه‌ی PHP را چک می‌کند (کمتر از 7.4 → ماژول‌های تازه خاموش، هسته سالم)
     ۲) وجود هر فایل را قبل از require بررسی می‌کند
     ۳) با تعریف STI_SAFE_MODE در wp-config.php کاملاً خاموش می‌شود
     ۴) خطای مرگبار را ثبت و در پنل نمایش می‌دهد تا دقیقاً معلوم شود کجا بود
   ══════════════════════════════════════════════════════════════════════════ */
define( 'STI_V7_MIN_PHP', '7.4' );

function sti_v7_modules() {
	return array(
		'class-sti-ai.php',
		'class-sti-title-engine.php',
		'class-sti-file-hunter.php',
		'class-sti-bot-inbox.php',
		'class-sti-autocat-pro.php',
		'class-sti-studio.php',
	);
}

function sti_v7_safe_mode() {
	if ( defined( 'STI_SAFE_MODE' ) && STI_SAFE_MODE ) { return true; }
	return (bool) get_option( 'sti_v7_disabled', false );
}

function sti_v7_load() {
	if ( sti_v7_safe_mode() ) { return; }

	if ( version_compare( PHP_VERSION, STI_V7_MIN_PHP, '<' ) ) {
		update_option( 'sti_v7_last_error', 'نسخه‌ی PHP هاست ' . PHP_VERSION . ' است؛ بخش‌های تازه به PHP ' . STI_V7_MIN_PHP . ' یا بالاتر نیاز دارند. هسته‌ی افزونه مثل قبل کار می‌کند.', false );
		return;
	}

	/**
	 * محافظ سقوط — با یک اصلاح مهم.
	 *
	 * شرط قبلی این بود:
	 *
	 *     if ( $flag && ( time() - $flag ) < 30 )   ← خاموش کردن دائمی
	 *
	 * یعنی وقتی پرچم **تازه** بود، حالت ایمن قفل می‌شد. اما پرچم تازه
	 * دقیقاً یعنی «درخواست دیگری همین الان در حال بارگذاری است» — نه
	 * «دفعه‌ی قبل سقوط کرد».
	 *
	 * چون sti_v7_load() روی هر درخواست اجرا می‌شود و پنل گلدن اسکن هر چند
	 * ثانیه AJAX می‌زند، دو درخواست هم‌زمان کافی بود تا افزونه خودش را
	 * خاموش کند. این همان چیزی است که مرتب اتفاق می‌افتاد و
	 * class-sti-bot-inbox.php را از دسترس خارج می‌کرد — که نتیجه‌اش
	 * docs_recorded: 0 و BOT_TIMEOUT بود.
	 *
	 * سقوط واقعی پرچمی به جا می‌گذارد که **کهنه** می‌شود، چون هیچ‌وقت پاک
	 * نشده. پس شرط باید برعکس باشد.
	 */
	$flag = (int) get_option( 'sti_v7_loading', 0 );
	$age  = $flag ? ( time() - $flag ) : 0;

	if ( $flag && $age > 120 ) {
		// پرچمی که بیش از دو دقیقه مانده = بارگذاری قبلی واقعاً ناتمام مانده.
		update_option( 'sti_v7_disabled', 1, false );
		update_option( 'sti_v7_last_error', 'بارگذاری بخش‌های تازه ناتمام ماند و برای امنیت سایت خاموش شد. از صفحه‌ی افزونه می‌توانی دوباره روشنش کنی.', false );
		delete_option( 'sti_v7_loading' );
		return;
	}

	// پرچم تازه = درخواست هم‌زمان دیگری در حال بارگذاری است. require_once
	// خودش تکراری‌ها را می‌گیرد، پس ادامه می‌دهیم و پرچم را دست نمی‌زنیم.
	$owns_flag = ! $flag;
	if ( $owns_flag ) {
		update_option( 'sti_v7_loading', time(), false );
	}

	foreach ( sti_v7_modules() as $file ) {
		$path = STI_PATH . 'includes/' . $file;
		if ( ! file_exists( $path ) ) {
			update_option( 'sti_v7_last_error', 'فایل ' . $file . ' آپلود نشده است — زیپ افزونه را کامل جایگزین کن.', false );
			continue;
		}
		require_once $path;
	}

	if ( $owns_flag ) {
		delete_option( 'sti_v7_loading' );
	}
}
sti_v7_load();

/* گزارش خطای مرگبار — تا اگر جایی خراب شد، متن دقیقش را ببینی و بشود درستش کرد */
register_shutdown_function( function () {
	$e = error_get_last();
	if ( ! $e || ! in_array( (int) $e['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ), true ) ) { return; }
	if ( false === strpos( (string) $e['file'], 'sanil-telegram-importer' ) ) { return; }
	update_option( 'sti_v7_last_error', sprintf(
		'%s — فایل %s خط %d',
		mb_substr( (string) $e['message'], 0, 400 ),
		basename( (string) $e['file'] ),
		(int) $e['line']
	), false );
} );

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
	$err = get_option( 'sti_v7_last_error' );
	if ( ! $err ) { return; }
	echo '<div class="notice notice-warning is-dismissible"><p><strong>Golden Importer:</strong> '
		. esc_html( $err )
		. '</p><p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=sti-dashboard&sti_clear_error=1' ), 'sti_clear_error' ) ) . '">فهمیدم، پاکش کن</a></p></div>';
} );

add_action( 'admin_init', function () {
	if ( empty( $_GET['sti_clear_error'] ) ) { return; }
	if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
	check_admin_referer( 'sti_clear_error' );
	delete_option( 'sti_v7_last_error' );
	delete_option( 'sti_v7_disabled' );
	delete_option( 'sti_v7_loading' );
	wp_safe_redirect( admin_url( 'admin.php?page=sti-dashboard' ) );
	exit;
} );
/* ── Agent Bridge (NEW) ───────────────────────────────────────── */
require_once STI_PATH . 'includes/class-sti-agent-bridge.php';
/* ── Mode 2: Group Monitor + Download Strategy (NEW) ──────────── */
require_once STI_PATH . 'includes/class-sti-group-monitor.php';
require_once STI_PATH . 'includes/class-sti-download-strategy.php';
/* ── Channel Import (NEW) ─────────────────────────────────────── */
require_once STI_PATH . 'includes/class-sti-channel-import.php';
require_once STI_PATH . 'includes/class-sti-importek.php';
require_once STI_PATH . 'includes/class-sti-goldtel.php';
require_once STI_PATH . 'includes/class-sti-bot-modes.php';
/* ── MTProto: اکانت شخصی تلگرام (برای کانال‌های خصوصی) ──────────── */
require_once STI_PATH . 'includes/class-sti-mtproto.php';

/* ── گلدن اسکن (Golden Scan) — ماژول کاملاً مستقل، فقط از زیرساخت بالا استفاده می‌کند ── */
require_once STI_PATH . 'includes/golden-scan/class-gs-db.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-scan-run.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-media-ids.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-correlation.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-confidence.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-publish-queue.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-auto-worker.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-channel-insight.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-system-check.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-test-wizard.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-channel.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-segment.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-scanner.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-profile.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-profile-ajax.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-autocat-bridge.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-session.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-artifact.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-event.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-button-resolver.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-retry.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-flags.php';
/* ۱۰.۹.۳ — دروازه‌ی اتمیک تیک‌های کران (پیش از مصرف‌کننده‌ها) */
require_once STI_PATH . 'includes/golden-scan/class-gs-cron-gate.php';
/* ۱۰.۱۰ — خط تولید خودکار: Stage/Status قطعی + تنظیمات + Governor + Review + Run Log */
require_once STI_PATH . 'includes/golden-scan/class-gs-stage.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-automation.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-governor.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-review.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-run-log.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-recovery.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-channel-watcher.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-deadline.php';
/* ═══ معماری زنجیره‌ای ۱۰.۸ — Node Classifier / Processor / Chain Engine ═══ */
require_once STI_PATH . 'includes/golden-scan/class-gs-node.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-deep-link-parser.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-node-classifier.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-node-processor.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-handoff-steps.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-chain-engine.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-action-executor.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-bot-candidate.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-bot-candidate-collector.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-file-matcher.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-download-engine.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-media-engine.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-content-engine.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-product-builder.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-product-validator.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-inbox-maintenance-ajax.php';
require_once STI_PATH . 'includes/golden-scan/class-gs-session-ajax.php';

/* ── AJAX handlers بخش اکانت شخصی (MTProto) ────────────────────── */
add_action( 'wp_ajax_sti_mt_status',         array( 'STI_MTProto', 'ajax_status' ) );
add_action( 'wp_ajax_sti_mt_install',        array( 'STI_MTProto', 'ajax_install' ) );
add_action( 'wp_ajax_sti_mt_send_code',      array( 'STI_MTProto', 'ajax_send_code' ) );
add_action( 'wp_ajax_sti_mt_complete_login', array( 'STI_MTProto', 'ajax_complete_login' ) );
add_action( 'wp_ajax_sti_mt_logout',         array( 'STI_MTProto', 'ajax_logout' ) );
add_action( 'wp_ajax_sti_mt_probe_chat',     array( 'STI_MTProto', 'ajax_probe_chat' ) );
if ( is_admin() ) {
require_once STI_PATH . 'admin/class-sti-admin.php';
}

/* ── v7 boot: انتقال تنظیمات قدیمی AI + راه‌اندازی کنترلر استودیو ─────────────── */
add_action( 'init', function () {
	if ( ! class_exists( 'STI_AI' ) ) { return; }
	try {
		STI_AI::maybe_migrate();
	} catch ( \Throwable $e ) {
		update_option( 'sti_v7_last_error', 'انتقال تنظیمات هوش مصنوعی: ' . mb_substr( $e->getMessage(), 0, 300 ), false );
	}
}, 5 );
if ( is_admin() ) {
	add_action( 'plugins_loaded', function () {
		if ( ! class_exists( 'STI_Studio' ) ) { return; }
		try {
			STI_Studio::instance();
		} catch ( \Throwable $e ) {
			update_option( 'sti_v7_last_error', 'راه‌اندازی استودیو: ' . mb_substr( $e->getMessage(), 0, 300 ), false );
		}
	}, 20 );
}
/* ── Activation ───────────────────────────────────────────────── */
function sti_activate_plugin() {
require_once STI_PATH . 'includes/class-sti-db.php';
STI_DB::install();
STI_Channel_Index::install();
/* Agent Bridge table */
STI_Agent_Bridge::maybe_create_table();
STI_Agent_Bridge::ensure_temp_dir();
if ( class_exists( 'STI_Bot_Inbox' ) ) { STI_Bot_Inbox::install(); }
if ( class_exists( 'STI_Importek' ) ) { STI_Importek::install(); }
if ( class_exists( 'STI_GoldTel' ) ) { STI_GoldTel::install(); }
if ( class_exists( 'STI_Bot_Modes' ) ) { STI_Bot_Modes::install(); }
/* AutoCat tables + dictionary */
if ( class_exists( 'STI_AutoCat' ) ) { STI_AutoCat::install(); }
/* گلدن اسکن — جدول‌های مستقل */
if ( class_exists( 'STI_GS_DB' ) ) { STI_GS_DB::install(); }
if ( ! wp_next_scheduled( 'sti_cleanup_cron' ) ) {
wp_schedule_event( time(), 'daily', 'sti_cleanup_cron' );
}
/* Queue tick — هر ۶۰ ثانیه (interval سفارشی در Scheduler ثبت می‌شود) */
if ( ! wp_next_scheduled( 'sti_queue_tick' ) ) {
wp_schedule_event( time() + 30, 'sti_every_minute', 'sti_queue_tick' );
}
}
register_activation_hook( __FILE__, 'sti_activate_plugin' );
/* ── Deactivation ─────────────────────────────────────────────── */
function sti_deactivate_plugin() {
wp_clear_scheduled_hook( 'sti_cleanup_cron' );
wp_clear_scheduled_hook( 'sti_queue_tick' );
wp_clear_scheduled_hook( 'sti_agent_cleanup_temp' );
wp_clear_scheduled_hook( 'sti_ci_worker' );
wp_clear_scheduled_hook( 'sti_importek_worker' );
wp_clear_scheduled_hook( 'sti_goldtel_worker' );
wp_clear_scheduled_hook( 'sti_goldtel_dispatch_worker' );
wp_clear_scheduled_hook( 'sti_bot_modes_worker' );
wp_clear_scheduled_hook( 'sti_gs_scan_worker' );
wp_clear_scheduled_hook( 'sti_gs_scan_segments_worker' );
}
register_deactivation_hook( __FILE__, 'sti_deactivate_plugin' );
add_action( 'sti_cleanup_cron', array( 'STI_DB', 'cleanup_old_logs' ) );
add_action( 'admin_notices', function () {
if ( ! class_exists( 'WooCommerce' ) ) {
echo '<div class="notice notice-error"><p><strong>Golden Importer:</strong> این افزونه برای کار کردن نیاز به فعال بودن ووکامرس دارد.</p></div>';
}
} );
/* ── WooCommerce: اجازه دانلود از هاست خارجی (رفع خطای Approved Directories) ── */
add_filter( 'woocommerce_approved_download_directories', function( $dirs ){
	$dirs = is_array( $dirs ) ? $dirs : array();
	if ( class_exists( 'STI_Settings' ) ) {
		$remote = STI_Settings::get( 'remote_public_base_url' );
		if ( $remote ) {
			$p = @parse_url( $remote );
			if ( ! empty( $p['host'] ) ) {
				$scheme = $p['scheme'] ?? 'https';
				$base = $scheme . '://' . $p['host'];
				if ( ! empty( $p['port'] ) ) { $base .= ':' . $p['port']; }
				if ( ! empty( $p['path'] ) ) { $base .= rtrim( $p['path'], '/' ); }
				$dirs[] = untrailingslashit( $base );
				$dirs[] = $scheme . '://' . $p['host'];
			}
		}
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['baseurl'] ) ) {
			$dirs[] = $uploads['baseurl'];
			$dirs[] = dirname( $uploads['baseurl'] );
		}
	}
	return array_values( array_unique( $dirs ) );
});
/* ── Boot ──────────────────────────────────────────────────────── */
function sti_boot() {
STI_DB::maybe_upgrade();
if ( class_exists( 'STI_Channel_Index' ) ) { STI_Channel_Index::install(); }
if ( class_exists( 'STI_AutoCat' ) ) { STI_AutoCat::install(); }
STI_Webhook::instance();
STI_Scheduler::instance();
STI_Scheduler::ensure_tick_scheduled(); // خودترمیمی — اگر cron ثبت نشده بود ثبت کن
/* Agent Bridge */
STI_Agent_Bridge::instance();
/* Mode 2: Group Monitor */
STI_Group_Monitor::instance();
/* Channel Import */
STI_Channel_Import::instance();
STI_Importek::install();
STI_Importek::instance();
STI_GoldTel::install();
STI_GoldTel::instance();
STI_Bot_Modes::install();
STI_Bot_Modes::instance();
/* گلدن اسکن */
if ( class_exists( 'STI_GS_DB' ) ) { STI_GS_DB::install(); }
if ( class_exists( 'STI_GS_DB' ) ) { STI_GS_DB::init_admin_notices(); }
if ( class_exists( 'STI_AI' ) && method_exists( 'STI_AI', 'repair_provider_ids' ) && ! get_option( 'sti_ai_ids_repaired' ) ) { STI_AI::repair_provider_ids(); update_option( 'sti_ai_ids_repaired', 1, false ); }
if ( class_exists( 'STI_GS_Test_Wizard' ) ) { new STI_GS_Test_Wizard(); }
/**
 * بازه‌های کران باید پیش از هر init ثبت شوند، وگرنه wp_schedule_event
 * آن‌ها را نمی‌شناسد و بی‌صدا به hourly سقوط می‌کند.
 */
if ( class_exists( 'STI_GS_Flags' ) ) { STI_GS_Flags::register_intervals(); }
if ( class_exists( 'STI_GS_Publish_Queue' ) ) { STI_GS_Publish_Queue::init(); }
if ( class_exists( 'STI_GS_Auto_Worker' ) ) { STI_GS_Auto_Worker::init(); }
if ( class_exists( 'STI_GS_Recovery' ) ) { STI_GS_Recovery::init(); }
if ( class_exists( 'STI_GS_Channel_Watcher' ) ) { STI_GS_Channel_Watcher::init(); }
if ( class_exists( 'STI_GS_Scanner' ) ) { STI_GS_Scanner::instance(); }
if ( class_exists( 'STI_GS_Profile_Ajax' ) ) { STI_GS_Profile_Ajax::instance(); }
if ( class_exists( 'STI_GS_Session_Ajax' ) ) { STI_GS_Session_Ajax::instance(); }
if ( class_exists( 'STI_GS_Inbox_Maintenance_Ajax' ) ) { STI_GS_Inbox_Maintenance_Ajax::instance(); }
if ( is_admin() ) {
STI_Admin::instance();
}
}
add_action( 'plugins_loaded', 'sti_boot' );
