<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ── WP Debug ─────────────────────────────────────────────── */
$wp_debug     = defined( 'WP_DEBUG' ) && WP_DEBUG;
$wp_debug_log = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
$wp_debug_disp = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;

/* ── Cron ─────────────────────────────────────────────────── */
$disable_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
$cron_array   = _get_cron_array();

/*
 * ۱۰.۱۱ — تشخیص Real Cron (P15).
 * فقط وقتی DISABLE_WP_CRON روشن است مهم است. بدون دستکاری هیچ فایلی —
 * فقط crontab کاربرِ همین کاربر (اگر exec در دسترس باشد) خوانده می‌شود.
 */
$real_cron        = 'unknown';
$real_cron_detail = '';
if ( $disable_cron ) {
	if ( function_exists( 'shell_exec' ) ) {
		$crontab = @shell_exec( 'crontab -l 2>/dev/null' );
		if ( is_string( $crontab ) && '' !== trim( $crontab ) && false !== stripos( $crontab, 'wp-cron' ) ) {
			$real_cron        = 'detected';
			$real_cron_detail = 'سطری با wp-cron در crontab پیدا شد.';
		} elseif ( is_string( $crontab ) && '' !== trim( $crontab ) ) {
			$real_cron        = 'not_detected';
			$real_cron_detail = 'crontab وجود دارد ولی سطر wp-cron ندارد.';
		} else {
			$real_cron        = 'unknown';
			$real_cron_detail = 'crontab خالی یا در دسترس نبود.';
		}
	} else {
		$real_cron_detail = 'بدون تابع exec، تشخیص سمت سرور ممکن نیست.';
	}
}
$gs_cron_line = '*/5 * * * * curl -s ' . home_url( '/wp-cron.php?doing_wp_cron' ) . ' >/dev/null 2>&1';
$gs_hooks     = array( 'sti_gs_auto_worker', 'sti_gs_watchdog', 'sti_gs_channel_watcher', 'sti_gs_publish_tick', 'sti_gs_scan_worker' );
$next_events  = array();
$all_events   = 0;
foreach ( (array) $cron_array as $ts => $events ) {
	foreach ( (array) $events as $hook => $h ) {
		$all_events++;
		if ( in_array( $hook, $gs_hooks, true ) && empty( $next_events[ $hook ] ) ) {
			$next_events[ $hook ] = (int) $ts;
		}
	}
}
$soonest = ! empty( $cron_array ) ? (int) min( array_keys( (array) $cron_array ) ) : 0;

/* ── PHP / هاست ────────────────────────────────────────────── */
$exec_ok    = function_exists( 'exec' ) && is_callable( 'exec' );
$gov        = class_exists( 'STI_GS_Governor' ) ? STI_GS_Governor::status() : array();
$db_status  = STI_GS_DB::migration_status();
$db_ok      = ( $db_status['current_version'] === $db_status['expected_version'] ) && '' === $db_status['problem'];
$ipc        = class_exists( 'STI_MTProto' ) ? STI_MTProto::ipc_diagnostic() : array();

function gs_env_row( $label, $value, $status = 'pass' ) {
	$bg   = 'fail' === $status ? '#ffebee' : ( 'warn' === $status ? '#fff8e1' : '#e8f5e9' );
	$icon = 'fail' === $status ? '🔴' : ( 'warn' === $status ? '🟡' : '🟢' );
	echo '<tr style="background:' . $bg . ';"><td><strong>' . esc_html( $label ) . '</strong></td><td>' . $icon . ' ' . $value . '</td></tr>';
}
?>
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — Environment Health (سلامت محیط)</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<h2 style="margin-top:24px;">وردپرس / دیباگ</h2>
	<table class="widefat striped" style="max-width:820px;">
		<tbody>
			<?php
			gs_env_row( 'WP_DEBUG', $wp_debug ? 'روشن' : 'خاموش', $wp_debug ? 'warn' : 'pass' );
			gs_env_row( 'WP_DEBUG_LOG', $wp_debug_log ? 'روشن (خطاها لاگ می‌شوند)' : 'خاموش', 'pass' );
			gs_env_row( 'WP_DEBUG_DISPLAY', $wp_debug_disp ? 'روشن — خطاها روی صفحه چاپ می‌شوند' : 'خاموش', $wp_debug_disp ? 'fail' : 'pass' );
			?>
		</tbody>
	</table>
	<?php if ( $wp_debug_disp ) : ?>
		<div class="notice notice-error" style="margin:12px 0;max-width:820px;">
			<p><strong>هشدار:</strong> وقتی <code>WP_DEBUG_DISPLAY</code> روشن است، هر Notice/Warning
			داخل خروجی AJAX چاپ می‌شود و JSON را خراب می‌کند — همان «پاسخ نامعتبر از سرور».
			در wp-config.php: <code>define( 'WP_DEBUG_DISPLAY', false );</code> (و برعکس،
			<code>WP_DEBUG_LOG</code> را روشن نگه دارید تا خطاها را در لاگ ببینید).</p>
		</div>
	<?php endif; ?>

	<h2 style="margin-top:24px;">کران (WP-Cron / Real Cron)</h2>
	<table class="widefat striped" style="max-width:820px;">
		<tbody>
			<?php
			gs_env_row( 'WP-Cron (درون‌خطی وردپرس)', $disable_cron ? 'خاموش' : 'روشن', 'pass' );
			gs_env_row( 'DISABLE_WP_CRON', $disable_cron ? 'روشن (کران فقط با crontab سیستمی)' : 'خاموش', $disable_cron ? 'warn' : 'pass' );
			if ( $disable_cron ) {
				$rc_label = 'detected' === $real_cron ? 'Detected ✅' : ( 'not_detected' === $real_cron ? 'Not detected ❌' : 'Unknown ⚠️' );
				gs_env_row( 'Real Cron (crontab سیستمی)', $rc_label . ' — ' . $real_cron_detail, 'detected' === $real_cron ? 'pass' : ( 'not_detected' === $real_cron ? 'fail' : 'warn' ) );
			} else {
				gs_env_row( 'Real Cron', '— (نیاز نیست: WP-Cron درون‌خطی فعال است)', 'pass' );
			}
			if ( $soonest ) {
				$delta = time() - $soonest;
				$age_txt = $delta < 0 ? 'در آینده (' . (int) ( -$delta / 60 ) . ' دقیقه)' : ( $delta < 900 ? (int) ( $delta / 60 ) . ' دقیقه پیش' : (int) ( $delta / 3600 ) . ' ساعت پیش' );
				gs_env_row( 'نزدیک‌ترین رویداد کران', esc_html( date_i18n( 'Y-m-d H:i', $soonest ) ) . ' — ' . $age_txt, ( $delta > 3600 ) ? 'warn' : 'pass' );
			} else {
				gs_env_row( 'رویدادهای کران', 'هیچ رویدادی در صف نیست', 'warn' );
			}
			$missing = array();
			foreach ( $gs_hooks as $h ) {
				if ( empty( $next_events[ $h ] ) ) { $missing[] = $h; }
			}
			gs_env_row( 'کران‌های گلدن اسکن', empty( $missing ) ? 'همه در صف: ' . count( $gs_hooks ) : 'گم‌شده: ' . implode( ', ', $missing ), empty( $missing ) ? 'pass' : 'fail' );
			gs_env_row( 'کل رویدادهای کران', number_format_i18n( $all_events ) . ' مورد در صف', 'pass' );
			?>
		</tbody>
	</table>
	<?php if ( $disable_cron && 'detected' !== $real_cron ) : ?>
		<div class="notice <?php echo 'not_detected' === $real_cron ? 'notice-error' : 'notice-warning'; ?>" style="margin:12px 0;max-width:820px;">
			<p><strong><?php echo 'not_detected' === $real_cron ? 'هشدار جدی:' : 'هشدار:'; ?></strong>
			وقتی <code>DISABLE_WP_CRON</code> روشن است ولی Real Cron تشخیص داده نمی‌شود،
			هیچ تیکی (Worker / Publish / Watchdog) اجرا <strong>نمی‌شود</strong> — خط تولید بی‌صدا متوقف است.
			این سطر را در Cron Jobs هاست خود وارد کنید (بدون ویرایش هیچ فایل افزونه):</p>
			<p><code dir="ltr" style="display:block;padding:8px;background:#f6f8fa;border:1px solid #ddd;border-radius:6px;font-size:12px;"><?php echo esc_html( $gs_cron_line ); ?></code></p>
			<p style="font-size:12px;color:#666;">(WP-Cron را هم با یک بارلود صفحه‌ی واقعی می‌توانید فعال بگذارید — ولی روی هاست‌های با ترافیک کم، crontab واقعی مطمئن‌تر است.)</p>
		</div>
	<?php endif; ?>

	<h2 style="margin-top:24px;">PHP / هاست / دیتابیس</h2>
	<table class="widefat striped" style="max-width:820px;">
		<tbody>
			<?php
			$php_ok = version_compare( PHP_VERSION, '7.4', '>=' );
			gs_env_row( 'PHP', PHP_VERSION, $php_ok ? 'pass' : 'fail' );
			if ( ! empty( $ipc['phar_required_php'] ) ) {
				gs_env_row( 'phar Madeline ↔ PHP', 'نیاز: ' . esc_html( (string) $ipc['phar_required_php'] ) . ' / هاست: ' . esc_html( (string) $ipc['php_version'] ), ! empty( $ipc['php_ok'] ) ? 'pass' : 'fail' );
			}
			gs_env_row( 'memory_limit', esc_html( (string) ini_get( 'memory_limit' ) ), ( (int) wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) ) < 256 * MB_IN_BYTES ? 'warn' : 'pass' );
			gs_env_row( 'تابع exec (برای شمارش/بستن worker)', $exec_ok ? 'در دسترس' : 'غیرفعال — شمارش worker و ترمیم خودکار IPC ممکن نیست', $exec_ok ? 'pass' : 'warn' );
			$ram = isset( $gov['signals']['ram_pct'] ) ? $gov['signals']['ram_pct'] : null;
			gs_env_row( 'RAM هاست', ( null === $ram ) ? '—' : number_format_i18n( $ram ) . '٪', ( null !== $ram && $ram >= 80 ) ? 'fail' : 'pass' );
			$load = isset( $gov['signals']['load'] ) ? $gov['signals']['load'] : null;
			gs_env_row( 'Load (بر Core)', ( null === $load ) ? '—' : number_format_i18n( $load, 2 ), ( null !== $load && $load >= 2 ) ? 'warn' : 'pass' );
			gs_env_row( 'مهاجرت دیتابیس', 'فرضه ' . esc_html( (string) $db_status['current_version'] ) . ' / مورد انتظار ' . esc_html( (string) $db_status['expected_version'] ) . ( '' !== $db_status['problem'] ? ' — مشکل: ' . esc_html( $db_status['problem'] ) : '' ), $db_ok ? 'pass' : 'fail' );
			?>
		</tbody>
	</table>
</div>
