<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ── داده‌ها ─────────────────────────────────────────────── */
global $wpdb;
$tbl     = STI_GS_DB::pipeline_items_table();
$rows    = (array) $wpdb->get_results( "SELECT state, COUNT(*) AS n FROM `{$tbl}` GROUP BY state", ARRAY_A );
$summary = STI_GS_Stage::summarize( $rows );

$gov    = class_exists( 'STI_GS_Governor' ) ? STI_GS_Governor::status() : array();
$runs   = class_exists( 'STI_GS_Run_Log' ) ? STI_GS_Run_Log::summary() : array();
$stats  = STI_GS_Auto_Worker::stats();
$ipc    = class_exists( 'STI_MTProto' ) ? STI_MTProto::ipc_diagnostic() : array();
$cfg    = STI_GS_Automation::all();

$stage_labels = array(
	STI_GS_Stage::DISCOVER => 'کشف دکمه',
	STI_GS_Stage::BOT      => 'ربات',
	STI_GS_Stage::MATCH    => 'تطبیق',
	STI_GS_Stage::DOWNLOAD => 'دانلود',
	STI_GS_Stage::MEDIA    => 'مدیا',
	STI_GS_Stage::PRODUCT  => 'محصول',
	STI_GS_Stage::PUBLISH  => 'انتشار',
);
$gov_labels = array(
	STI_GS_Governor::LEVEL_OK        => array( '🟢 عادی', '#e8f5e9', '#a5d6a7' ),
	STI_GS_Governor::LEVEL_THROTTLE  => array( '🟡 خفه‌سازی', '#fff8e1', '#ffe082' ),
	STI_GS_Governor::LEVEL_EMERGENCY => array( '🔴 اورژانس', '#ffebee', '#ef9a9a' ),
);
$gov_level = isset( $gov['level'] ) ? $gov['level'] : STI_GS_Governor::LEVEL_OK;
$gov_ui    = isset( $gov_labels[ $gov_level ] ) ? $gov_labels[ $gov_level ] : $gov_labels[ STI_GS_Governor::LEVEL_OK ];
?>
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — Automation Health (سلامت خط تولید)</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div style="display:flex;gap:12px;margin:16px 0;flex-wrap:wrap;">
		<div style="flex:1;min-width:130px;padding:14px;border-radius:8px;background:<?php echo $stats['enabled'] ? '#e8f5e9' : '#f5f5f5'; ?>;border:1px solid #ccc;">
			<div style="font-size:22px;font-weight:700;"><?php echo $stats['enabled'] ? '🟢 خط تولید روشن' : '⚪ خط تولید خاموش'; ?></div>
			<div>Worker (هر <?php echo (int) round( $stats['interval'] / 60 ); ?> دقیقه، <?php echo (int) $cfg['sessions_per_tick']; ?> Session در تیک)</div>
		</div>
		<div style="flex:1;min-width:130px;padding:14px;border-radius:8px;background:<?php echo $gov_ui[1]; ?>;border:1px solid <?php echo $gov_ui[2]; ?>;">
			<div style="font-size:22px;font-weight:700;"><?php echo $gov_ui[0]; ?></div>
			<div>Governor (ضریب <?php echo isset( $gov['factor'] ) ? $gov['factor'] : 1.0; ?>)</div>
			<?php if ( ! empty( $gov['reasons'] ) ) : ?>
				<div style="font-size:12px;color:#555;margin-top:4px;"><?php echo esc_html( implode( ' · ', (array) $gov['reasons'] ) ); ?></div>
			<?php endif; ?>
		</div>
		<div style="flex:1;min-width:130px;padding:14px;border-radius:8px;background:#e8f5e9;border:1px solid #a5d6a7;">
			<div style="font-size:26px;font-weight:700;"><?php echo number_format_i18n( $summary['final'][ STI_GS_Stage::FINAL_PUBLISHED ] ?? 0 ); ?></div>
			<div>منتشرشده (PUBLISHED)</div>
		</div>
		<div style="flex:1;min-width:130px;padding:14px;border-radius:8px;background:#fff8e1;border:1px solid #ffe082;">
			<div style="font-size:26px;font-weight:700;"><?php echo number_format_i18n( $summary['final'][ STI_GS_Stage::FINAL_REVIEW ] ?? 0 ); ?></div>
			<div>در REVIEW (نیاز به شما)</div>
		</div>
		<div style="flex:1;min-width:130px;padding:14px;border-radius:8px;background:#f5f5f5;border:1px solid #ccc;">
			<div style="font-size:26px;font-weight:700;"><?php echo number_format_i18n( $summary['final'][ STI_GS_Stage::FINAL_CANCELLED ] ?? 0 ); ?></div>
			<div>لغوشده (CANCELLED)</div>
		</div>
	</div>

	<?php if ( ! empty( $summary['unknown'] ) ) : ?>
		<div class="notice notice-error" style="margin:0 0 16px;">
			<p><strong>State ناشناخته:</strong>
			<?php foreach ( $summary['unknown'] as $st => $n ) : ?> «<?php echo esc_html( $st ); ?>» ×<?php echo (int) $n; ?>، <?php endforeach; ?>
			— در نگاشت Stage ثبت نشده؛ بررسی کنید.</p>
		</div>
	<?php endif; ?>

	<h2 style="margin-top:24px;">صف به تفکیک Stage</h2>
	<table class="widefat striped" style="max-width:900px;">
		<thead>
			<tr><th>Stage</th><th>در صف</th><th>تجزیه (PENDING / RUNNING / WAITING / FAILED)</th></tr>
		</thead>
		<tbody>
		<?php foreach ( STI_GS_Stage::STAGE_ORDER as $stage ) :
			$ss = $summary['stage_status'][ $stage ];
		?>
			<tr>
				<td><strong><?php echo $stage; ?></strong> — <?php echo $stage_labels[ $stage ]; ?></td>
				<td><?php echo number_format_i18n( $summary['by_stage'][ $stage ] ); ?></td>
				<td><?php echo esc_html( sprintf( '%d / %d / %d / %d', $ss[ STI_GS_Stage::PENDING ], $ss[ STI_GS_Stage::RUNNING ], $ss[ STI_GS_Stage::WAITING ], $ss[ STI_GS_Stage::FAILED ] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2 style="margin-top:24px;">IPC / Worker / منابع</h2>
	<table class="widefat striped" style="max-width:900px;">
		<tbody>
		<?php if ( ! empty( $ipc ) ) : ?>
			<tr>
				<td>Workerهای madeline-ipc</td>
				<td><?php
					$w = (int) ( $ipc['worker_count'] ?? -1 );
					echo ( $w < 0 ) ? '— (بدون shell)' : number_format_i18n( $w );
					if ( $w > 1 ) { echo ' <span style="color:#c62828;">(انباشت — watchdog جمع می‌کند)</span>'; }
				?></td>
			</tr>
			<tr><td>سوکت IPC</td><td><?php echo esc_html( (string) ( $ipc['socket'] ?? '—' ) ); ?> / callback: <?php echo esc_html( (string) ( $ipc['callback_socket'] ?? '—' ) ); ?></td></tr>
			<tr>
				<td>سازگاری phar ↔ PHP</td>
				<td><?php echo ! empty( $ipc['php_ok'] ) ? '✅' : '🔴'; ?>
				<?php echo esc_html( (string) ( $ipc['phar_required_php'] ?? '' ) ); ?> (نیاز) / <?php echo esc_html( (string) ( $ipc['php_version'] ?? PHP_VERSION ) ); ?> (هاست)</td>
			</tr>
		<?php endif; ?>
		<?php if ( ! empty( $gov['signals'] ) ) : ?>
			<tr>
				<td>RAM هاست</td>
				<td><?php
					$r = $gov['signals']['ram_pct'];
					echo ( null === $r ) ? '—' : number_format_i18n( $r ) . '٪ (آستانه ' . (int) $gov['signals']['thresholds']['ram'] . '٪)';
				?></td>
			</tr>
			<tr>
				<td>Load (بر Core)</td>
				<td><?php
					$l = $gov['signals']['load'];
					echo ( null === $l ) ? '—' : number_format_i18n( $l, 2 ) . ' (آستانه ' . $gov['signals']['thresholds']['load'] . ')';
				?></td>
			</tr>
			<tr><td>خرابی IPC (۳۰ دقیقه)</td><td><?php echo number_format_i18n( (int) ( $gov['signals']['ipc_faults'] ?? 0 ) ); ?></td></tr>
			<tr><td>انباشت صف</td><td><?php echo number_format_i18n( (int) ( $gov['signals']['backlog'] ?? 0 ) ); ?></td></tr>
		<?php endif; ?>
			<tr>
				<td>حافظه PHP</td>
				<td>
					<?php echo esc_html( (string) ini_get( 'memory_limit' ) ); ?>
					— اوج این فرآیند: <?php echo number_format_i18n( round( memory_get_peak_usage( true ) / 1048576, 1 ) ); ?>M
				</td>
			</tr>
		</tbody>
	</table>

	<h2 style="margin-top:24px;">شمارنده‌های خودترمیمی (کل)</h2>
	<table class="widefat striped" style="max-width:900px;">
		<thead><tr><th>اجرا</th><th>Retry</th><th>Recovery</th><th>IPC Heal</th><th>دانلود دوباره</th><th>انتشار دوباره</th></tr></thead>
		<tbody>
			<tr>
				<td><?php echo number_format_i18n( (int) ( $runs['total_runs'] ?? 0 ) ); ?> Session / <?php echo number_format_i18n( (int) ( $runs['finished'] ?? 0 ) ); ?> خاتمه‌یافته</td>
				<td><?php echo number_format_i18n( (int) ( $runs['retries'] ?? 0 ) ); ?></td>
				<td><?php echo number_format_i18n( (int) ( $runs['recoveries'] ?? 0 ) ); ?></td>
				<td><?php echo number_format_i18n( (int) ( $runs['ipc_heals'] ?? 0 ) ); ?></td>
				<td><?php echo number_format_i18n( (int) ( $runs['download_retries'] ?? 0 ) ); ?></td>
				<td><?php echo number_format_i18n( (int) ( $runs['publish_retries'] ?? 0 ) ); ?></td>
			</tr>
		</tbody>
	</table>

	<h2 style="margin-top:24px;">Worker</h2>
	<p>
		آخرین تیک: <?php echo esc_html( (string) ( $stats['today']['last'] ?? 'هنوز تیکی نکرده' ) ); ?>
		· نوبت بعدی: <?php echo $stats['next_tick'] ? date_i18n( 'H:i', $stats['next_tick'] ) : '—'; ?>
		· امروز: <?php echo number_format_i18n( (int) ( $stats['today']['advanced'] ?? 0 ) ); ?> پیشرفت /
		<?php echo number_format_i18n( (int) ( $stats['today']['failed'] ?? 0 ) ); ?> خطا /
		<?php echo number_format_i18n( (int) ( $stats['today']['completed'] ?? 0 ) ); ?> تکمیل
	</p>
</div>
