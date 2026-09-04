<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/*
 * ۱۰.۱۱-UX — Live Pipeline (Hero / Operations Control Center)
 * BACKEND FREEZE: فقط presentation. داده از STI_GS_Line::monitor() (همان 10.11)؛
 * AJAX contracts: sti_gs_pipeline_poll / sti_gs_line_start / sti_gs_line_stop — بی‌تغییر.
 */
global $wpdb;

$data = class_exists( 'STI_GS_Line' ) ? STI_GS_Line::monitor() : array(
	'line' => array( 'state' => 'STOPPED' ), 'request' => array(), 'summary' => null,
	'current' => null, 'sessions' => array(), 'events' => array(), 'ok' => false,
);

$line     = isset( $data['line']['state'] ) ? $data['line']['state'] : 'STOPPED';
$summary  = isset( $data['summary'] ) && is_array( $data['summary'] ) ? $data['summary'] : null;
$cur      = isset( $data['current'] ) ? $data['current'] : null;
$sessions = isset( $data['sessions'] ) ? (array) $data['sessions'] : array();
$events   = isset( $data['events'] ) ? (array) $data['events'] : array();

$stage_keys = array( 'DISCOVER', 'BOT', 'MATCH', 'DOWNLOAD', 'MEDIA', 'PRODUCT', 'PUBLISH' );
$stage_fa   = array( 'DISCOVER' => 'اسکن', 'BOT' => 'ربات', 'MATCH' => 'تطبیق', 'DOWNLOAD' => 'دانلود', 'MEDIA' => 'مدیا', 'PRODUCT' => 'محصول', 'PUBLISH' => 'انتشار' );

/* شمارش Sessionهای فعال به تفکیک Stage — از داده‌ی موجود poll (بدون query جدید) */
$stage_counts = array_fill_keys( $stage_keys, 0 );
foreach ( $sessions as $it ) {
	if ( isset( $it['stage_idx'] ) && $it['stage_idx'] >= 0 && $it['stage_idx'] < count( $stage_keys ) ) {
		$stage_counts[ $stage_keys[ $it['stage_idx'] ] ]++;
	}
}
/* بالاترین Stage فعال (برای state گراف) */
$max_active = -1;
foreach ( $stage_keys as $i => $k ) {
	if ( $stage_counts[ $k ] > 0 ) {
		$max_active = max( $max_active, $i );
	}
}
$line_meta = array(
	'RUNNING'  => array( 'running',  'در حال اجرا' ),
	'STOPPED'  => array( 'stopped',  'توقف کرده' ),
	'PAUSING'  => array( 'pausing',  'در حال توقف امن…' ),
	'DEGRADED' => array( 'degraded', 'کاهش‌یافته (فشار منابع)' ),
	'ERROR'    => array( 'error',    'خطا — خودترمیم در تیک بعدی' ),
);
$lm = isset( $line_meta[ $line ] ) ? $line_meta[ $line ] : $line_meta['STOPPED'];

$g_level   = isset( $data['line']['level'] ) ? $data['line']['level'] : 'OK';
$g_factor  = isset( $data['line']['factor'] ) ? (float) $data['line']['factor'] : 1.0;
$g_reasons = isset( $data['line']['reasons'] ) ? (array) $data['line']['reasons'] : array();
$active_now = 0;
foreach ( $sessions as $it ) {
	if ( ! empty( $it['active'] ) ) {
		$active_now++;
	}
}
$worker_interval = (int) ( class_exists( 'STI_GS_Automation' ) ? STI_GS_Automation::get( 'worker_interval' ) : 300 );

/* ۱۰.۱۲ — نمای وضعیت: ۷ سطل تصویب‌شده از stateهای واقعی (render-time).
 * زنده‌بودن با KPIهای بالا؛ این کارت عددِ دقیقِ لحظه‌ی بارگذاری است. */
global $wpdb;
$gs_tbl2        = STI_GS_DB::pipeline_items_table();
$gs_bucket_rows = (array) $wpdb->get_results( "SELECT state, COUNT(*) AS c FROM {$gs_tbl2} GROUP BY state", ARRAY_A );
$gs_state_bucket = array(
	'awaiting'    => array( 'SCANNED', 'BUTTON_FOUND', 'WAITING_BOT', 'CHAIN_WAITING', 'CHAIN_STEP', 'BOT_RESPONSE', 'FILE_MATCHED', 'DOWNLOAD_PENDING', 'DOWNLOADED', 'STORED', 'MEDIA_PENDING', 'MEDIA_READY' ),
	'downloading' => array( 'DOWNLOADING' ),
	'building'    => array( 'MEDIA_BUILDING', 'PRODUCT_BUILDING' ),
	'in_queue'    => array( 'PRODUCT_READY', 'REVIEW_READY' ),
	'published'   => array( 'PUBLISHED' ),
	'review'      => array( 'REVIEW', 'NEEDS_REVIEW', 'ERROR_FILE_NOT_FOUND', 'DEAD_LETTER' ),
	'error'       => array( 'ERROR_BUTTON', 'ERROR_CLICK', 'ERROR_BOT_TIMEOUT', 'CHAIN_FAILED', 'ERROR_MATCH', 'DOWNLOAD_FAILED', 'MEDIA_FAILED', 'PRODUCT_FAILED' ),
	'cancelled'   => array( 'SKIPPED', 'CANCELLED' ),
);
$gs_buckets = array_fill_keys( array_keys( $gs_state_bucket ), 0 );
foreach ( $gs_bucket_rows as $gs_br ) {
	foreach ( $gs_state_bucket as $gs_bk => $gs_states ) {
		if ( in_array( (string) $gs_br['state'], $gs_states, true ) ) {
			$gs_buckets[ $gs_bk ] += (int) $gs_br['c'];
		}
	}
}
$gs_bucket_labels = array(
	'awaiting'    => array( '⏳', 'منتظر پردازش' ),
	'downloading' => array( '⬇️', 'در حال دانلود' ),
	'building'    => array( '🏗️', 'در حال ساخت محصول' ),
	'in_queue'    => array( '📦', 'در صف انتشار' ),
	'published'   => array( '✅', 'منتشر شده' ),
	'review'      => array( '🟡', 'نیازمند بازبینی' ),
	'error'       => array( '🔴', 'خطا' ),
);
?>
<div class="gi-console" id="gi-pipeline-page" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<?php
	$gs_steps_active = 7;
	$gs_steps_note   = 'محصول‌های منتشرشده در ووکامرس می‌نشینند — استثناها به Review می‌روند';
	include STI_PATH . 'admin/views/golden-scan/partial-steps.php';
	?>

	<?php if ( ! empty( $data['ok'] ) ) : ?>

	<div class="gi-bento">

		<!-- ═══ HERO: LINE STATUS ═══ -->
		<div class="gi-card gi-hero gi-span-12" id="gs-hero">
			<div class="gi-hero-state">
				<span class="gi-dot gi-dot--<?php echo esc_attr( $lm[0] ); ?> <?php echo 'running' === $lm[0] ? 'gi-pulse' : ''; ?>" aria-hidden="true"></span>
				<div>
					<div class="gi-hero-state-label" id="gs-line-label"><?php echo esc_html( $lm[1] ); ?></div>
					<div class="gi-hero-state-sub gi-mono" id="gs-line-state"><?php echo esc_html( $line ); ?></div>
				</div>
			</div>
			<div class="gi-hero-metrics" id="gs-hero-metrics">
				<span class="gi-hero-metric">Worker <b><?php echo (int) ceil( $worker_interval / 60 ); ?> دقیقه</b></span>
				<span class="gi-hero-metric">Governor <b><?php echo esc_html( $g_level ); ?> ×<?php echo number_format_i18n( $g_factor, 2 ); ?></b></span>
				<span class="gi-hero-metric">Active <b id="gs-hm-active"><?php echo number_format_i18n( $active_now ); ?></b></span>
				<span class="gi-hero-metric">Queue <b id="gs-hm-queue"><?php echo number_format_i18n( isset( $summary['waiting'] ) ? $summary['waiting'] : 0 ); ?></b></span>
			</div>
			<div class="gi-hero-actions">
				<?php if ( 'RUNNING' === $line || 'DEGRADED' === $line ) : ?>
					<button type="button" class="gi-btn gi-btn--danger" id="gs-line-stop">■ STOP LINE</button>
					<button type="button" class="gi-btn gi-btn--subtle" id="gs-line-start" hidden>▶ START LINE</button>
				<?php else : ?>
					<button type="button" class="gi-btn gi-btn--success" id="gs-line-start">▶ START LINE</button>
					<button type="button" class="gi-btn gi-btn--danger" id="gs-line-stop" hidden>■ STOP LINE</button>
				<?php endif; ?>
				<span id="gs-line-msg" class="gi-inline-res" role="status" aria-live="polite"></span>
			</div>
			<?php if ( $g_reasons ) : ?>
				<div class="gi-hero-state-sub" style="width:100%;margin-top:2px;">
					<?php echo esc_html( implode( ' · ', $g_reasons ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( 'PAUSING' === $line ) : ?>
				<div class="gi-hero-state-sub" style="width:100%;margin-top:2px;color:var(--gi-warning);">
					در حال انتظار تا Stageهای در حال اجرا تمام شوند… (graceful)
				</div>
			<?php endif; ?>
			<?php if ( 'STOPPED' === $line ) : ?>
				<div class="gi-hero-state-sub" style="width:100%;margin-top:2px;">
					STOP امن است: هیچ process kill نمی‌شود، داده‌ای حذف نمی‌شود؛ START ادامه‌ی واقعی است.
					محصول تازه برای انتشار از <a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=publish-queue' ) ); ?>">📦 صف انتشار</a> اضافه می‌شود.
				</div>
			<?php endif; ?>
		</div>

		<!-- ═══ ۱۰.۱۲ — نمای وضعیت (۷ سطل واقعی) ═══ -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<h2 class="gi-card-title">نمای وضعیت</h2>
				<span class="gi-card-sub">تعداد دقیق ردیف‌ها در هر وضعیت — لحظه‌ی بارگذاری صفحه (رویدادهای زنده در بخش‌های پایین)</span>
			</div>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:var(--gi-s4);">
				<?php foreach ( $gs_bucket_labels as $gs_bk => $gs_bl ) : ?>
				<div class="gi-stat gi-stat--<?php echo ( $gs_bk === 'error' && $gs_buckets[ $gs_bk ] > 0 ) ? 'warning' : ( $gs_bk === 'published' ? 'success' : 'info' ); ?>">
					<div class="gi-stat-v gi-nums"><?php echo number_format_i18n( $gs_buckets[ $gs_bk ] ); ?></div>
					<div class="gi-stat-l"><?php echo esc_html( $gs_bl[0] . ' ' . $gs_bl[1] ); ?></div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $gs_buckets['cancelled'] > 0 ) : ?>
				<p class="gi-card-sub" style="margin-top:var(--gi-s3);">لغو‌شده (در شش وضعیت بالا نمی‌شمارد): <b><?php echo number_format_i18n( $gs_buckets['cancelled'] ); ?></b></p>
			<?php endif; ?>
		</div>

		<!-- ═══ PIPELINE FLOW (vertical, UI only — state machine دست‌نخورده) ═══ -->
		<div class="gi-card gi-span-4">
			<div class="gi-card-head">
				<h2 class="gi-card-title">Pipeline Flow</h2>
				<span class="gi-card-sub">Sessionهای فعال در هر Stage</span>
			</div>
			<ul class="gi-flow" id="gs-flow" aria-label="جریان Stageها">
				<?php foreach ( $stage_keys as $i => $k ) :
					$cnt = $stage_counts[ $k ];
					$st  = ( $cnt > 0 ) ? ( $i === $max_active ? 'active' : ( $i < $max_active ? 'done' : 'active' ) ) : ( ( $max_active > $i && $max_active > 0 ) ? 'done' : '' );
				?>
					<li data-stage="<?php echo esc_attr( $k ); ?>" data-state="<?php echo esc_attr( $st ); ?>">
						<span class="gi-flow-node" aria-hidden="true"><?php echo $i < $max_active && $max_active > 0 ? '✓' : ( $i === $max_active ? '⟳' : ( $i + 1 ) ); ?></span>
						<span class="gi-flow-name"><?php echo esc_html( $k ); ?><small><?php echo esc_html( $stage_fa[ $k ] ); ?></small></span>
						<span class="gi-flow-count" data-gi-stage-count="<?php echo esc_attr( $k ); ?>"><?php echo number_format_i18n( $cnt ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="gi-flex" style="margin-top:var(--gi-s3);gap:var(--gi-s2);">
				<span class="gi-badge gi-badge--success">✓ Published <span class="gi-nums" id="gs-flow-pub"><?php echo number_format_i18n( isset( $summary['published'] ) ? $summary['published'] : 0 ); ?></span></span>
				<span class="gi-badge gi-badge--danger">⚠ Review <span class="gi-nums" id="gs-flow-review"><?php echo number_format_i18n( isset( $summary['review'] ) ? $summary['review'] : 0 ); ?></span></span>
				<span class="gi-badge">✕ Cancelled <span class="gi-nums" id="gs-flow-cancel"><?php echo number_format_i18n( isset( $summary['cancelled'] ) ? $summary['cancelled'] : 0 ); ?></span></span>
			</div>
		</div>

		<!-- ═══ CURRENT ACTIVITY ═══ -->
		<div class="gi-card gi-span-4" id="gs-current-card">
			<div class="gi-card-head">
				<h2 class="gi-card-title">⚡ Current Activity</h2>
				<span class="gi-card-sub">حالا چه می‌شود</span>
			</div>
			<?php if ( $cur ) : ?>
				<div class="gi-flex-between" style="margin-bottom:var(--gi-s3);">
					<span class="gi-badge gi-badge--brand" style="font-size:var(--gi-fs1);">
						Session <a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=sessions' ) ); ?>" class="gi-nums">#<?php echo (int) $cur['id']; ?></a>
					</span>
					<?php if ( ! empty( $cur['file'] ) ) : ?><span class="gi-card-sub"><?php echo esc_html( $cur['file'] ); ?></span><?php endif; ?>
				</div>
				<div style="margin-bottom:var(--gi-s3);">
					<span class="gi-badge gi-badge--info" id="gs-cur-stage" style="font-size:var(--gi-fs1);"><?php echo esc_html( (string) $cur['label'] ); ?></span>
				</div>
				<div class="gi-exc-grid">
					<div><div class="gi-exc-k">Retry</div><div class="gi-exc-v" id="gs-cur-retry"><span class="gi-nums"><?php echo (int) $cur['attempts']; ?></span> / <span class="gi-nums"><?php echo (int) $cur['retry_limit']; ?></span></div></div>
					<div><div class="gi-exc-k">Worker</div><div class="gi-exc-v gi-mono" id="gs-cur-worker"><?php echo esc_html( (string) $cur['worker_id'] ); ?></div></div>
					<div><div class="gi-exc-k">آخرین فعالیت</div><div class="gi-exc-v" id="gs-cur-updated"><?php echo esc_html( (string) $cur['updated_at'] ); ?></div></div>
					<div><div class="gi-exc-k">در صف اجرا</div><div class="gi-exc-v" id="gs-cur-queue"><?php echo (int) $cur['queue'] > 0 ? number_format_i18n( (int) $cur['queue'] ) . ' Session' : '—'; ?></div></div>
				</div>
			<?php else : ?>
				<div class="gi-empty" style="padding:var(--gi-s5);">
					<div class="gi-empty-ico" aria-hidden="true">⏸</div>
					<div class="gi-empty-title">فعلاً Sessionی در حال اجرا نیست</div>
					<div class="gi-empty-sub">Worker در تیک بعدی نوبت می‌دهد.
					<?php if ( 'STOPPED' === $line ) : ?>خط STOP است — برای ادامه ▶ START LINE.<?php endif; ?></div>
				</div>
			<?php endif; ?>
		</div>

		<!-- ═══ LIVE SUMMARY (KPI bento) ═══ -->
		<div class="gi-card gi-span-4">
			<div class="gi-card-head">
				<h2 class="gi-card-title">Live Summary</h2>
				<span class="gi-card-sub">از state واقعی — بدون عدد تقریبی</span>
			</div>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--gi-s4) var(--gi-s3);">
				<div class="gi-stat gi-stat--muted"><div class="gi-stat-v gi-nums" id="gs-k-requested"><?php echo null === ( $summary['requested'] ?? null ) ? 'نامشخص' : number_format_i18n( $summary['requested'] ); ?></div><div class="gi-stat-l">Requested</div></div>
				<div class="gi-stat gi-stat--muted"><div class="gi-stat-v gi-nums" id="gs-k-created"><?php echo number_format_i18n( $summary['created'] ?? 0 ); ?></div><div class="gi-stat-l">Created</div></div>
				<div class="gi-stat gi-stat--brand"><div class="gi-stat-v gi-nums" id="gs-k-processing"><?php echo number_format_i18n( $summary['processing'] ?? 0 ); ?></div><div class="gi-stat-l">Processing</div></div>
				<div class="gi-stat gi-stat--warning"><div class="gi-stat-v gi-nums" id="gs-k-waiting"><?php echo number_format_i18n( $summary['waiting'] ?? 0 ); ?></div><div class="gi-stat-l">Waiting</div></div>
				<div class="gi-stat gi-stat--danger"><div class="gi-stat-v gi-nums" id="gs-k-failed"><?php echo number_format_i18n( $summary['failed'] ?? 0 ); ?></div><div class="gi-stat-l">Failed (retry)</div></div>
				<div class="gi-stat gi-stat--success"><div class="gi-stat-v gi-nums" id="gs-k-published"><?php echo number_format_i18n( $summary['published'] ?? 0 ); ?></div><div class="gi-stat-l">Published</div></div>
				<div class="gi-stat gi-stat--danger"><div class="gi-stat-v gi-nums" id="gs-k-review"><?php echo number_format_i18n( $summary['review'] ?? 0 ); ?></div><div class="gi-stat-l">Review</div></div>
				<div class="gi-stat gi-stat--muted"><div class="gi-stat-v gi-nums" id="gs-k-cancelled"><?php echo number_format_i18n( $summary['cancelled'] ?? 0 ); ?></div><div class="gi-stat-l">Cancelled</div></div>
			</div>
		</div>

		<?php if ( ! empty( $summary['unknown'] ) ) : ?>
			<div class="gi-span-12" style="display:flex;align-items:center;gap:var(--gi-s2);background:var(--gi-warning-soft);border-radius:14px;padding:var(--gi-s3) var(--gi-s4);font-size:var(--gi-fs1);font-weight:700;color:var(--gi-warning);">
				⚠ <span class="gi-nums"><?php echo number_format_i18n( $summary['unknown'] ); ?></span> Session با state ناشناخته — Supervisor آن‌ها را anomaly ثبت کرده است.
			</div>
		<?php endif; ?>

		<!-- ═══ SESSION PIPELINE ═══ -->
		<div class="gi-card gi-card--flush gi-span-8">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) 0;">
				<h2 class="gi-card-title">Session Pipeline</h2>
				<span class="gi-card-sub">Sessionهای فعال — جدیدترین‌ها</span>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table gi-responsive" id="gs-sessions-table">
					<thead>
						<tr>
							<th scope="col">Session</th>
							<th scope="col">Stage</th>
							<th scope="col">تلاش</th>
							<th scope="col">آخرین فعالیت</th>
						</tr>
					</thead>
					<tbody id="gs-sessions-body">
						<?php if ( empty( $sessions ) ) : ?>
							<tr><td colspan="4" style="text-align:center;color:var(--gi-text-faint);padding:var(--gi-s6);">
								Session فعالی نیست — صف خالی است 🎉
							</td></tr>
						<?php else : ?>
							<?php foreach ( $sessions as $it ) :
								$idx = (int) $it['stage_idx'];
							?>
								<tr>
									<td data-label="Session">
										<strong class="gi-nums">#<?php echo (int) $it['id']; ?></strong>
										<?php if ( ! empty( $it['file'] ) ) : ?><div class="gi-faint" style="font-size:var(--gi-fs0);"><?php echo esc_html( $it['file'] ); ?></div><?php endif; ?>
									</td>
									<td data-label="Stage">
										<span class="gi-chips" role="img" aria-label="<?php echo esc_attr( (string) $it['label'] ); ?>">
											<?php foreach ( $stage_keys as $i => $k ) :
												$cls = ( $idx < 0 ) ? '' : ( $i < $idx ? 'gi-chip--done' : ( $i === $idx ? 'gi-chip--cur' : '' ) );
												$mark = ( $idx < 0 ) ? '·' : ( $i < $idx ? '✓' : ( $i === $idx ? '⟳' : '·' ) );
											?>
												<span class="gi-chip <?php echo $cls; ?>" title="<?php echo esc_attr( $stage_fa[ $k ] ); ?>"><?php echo $mark; ?></span>
											<?php endforeach; ?>
										</span>
										<div class="gi-faint gi-mono" style="font-size:10px;"><?php echo esc_html( (string) $it['label'] ); ?></div>
									</td>
									<td data-label="تلاش" class="gi-nums"><?php echo (int) $it['attempts']; ?></td>
									<td data-label="آخرین فعالیت" style="white-space:nowrap;"><?php echo esc_html( (string) $it['updated_at'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<!-- ═══ LIVE EVENT STREAM ═══ -->
		<div class="gi-card gi-span-4">
			<div class="gi-card-head">
				<h2 class="gi-card-title">Live Event Stream</h2>
				<span class="gi-card-sub">آخرین <span class="gi-nums" id="gs-ev-count"><?php echo count( $events ); ?></span> رویداد</span>
			</div>
			<div class="gi-stream-scroll">
				<ul class="gi-stream" id="gs-events">
					<?php if ( empty( $events ) ) : ?>
						<li style="color:var(--gi-text-faint);border:none;">هنوز رویدادی ثبت نشده است.</li>
					<?php else : ?>
						<?php foreach ( $events as $ev ) : ?>
							<li data-r="<?php echo esc_attr( in_array( $ev['r'], array( 'ok', 'retry', 'error' ), true ) ? $ev['r'] : 'ok' ); ?>">
								<time datetime="<?php echo esc_attr( (string) $ev['t'] ); ?>"><?php echo esc_html( (string) $ev['t'] ); ?></time>
								<span class="gi-stream-kind">
									<?php if ( (int) $ev['s'] > 0 ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=sessions' ) ); ?>">#<?php echo (int) $ev['s']; ?></a><?php endif; ?> <?php echo esc_html( (string) $ev['k'] ); ?>
								</span>
								<span class="gi-stream-msg"><?php echo esc_html( (string) $ev['m'] ); ?></span>
							</li>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
			</div>
		</div>

	</div><!-- /gi-bento -->

	<?php else : ?>
		<div class="gi-empty gi-mt-5" style="grid-column:1/-1;">
			<div class="gi-empty-ico" aria-hidden="true">📡</div>
			<div class="gi-empty-title">داده‌های زنده در دسترس نیستند</div>
			<div class="gi-empty-sub">جداول مهاجرت‌نشده یا خطای گذرا — صفحه در poll بعدی دوباره بررسی می‌کند.</div>
		</div>
	<?php endif; ?>
</div><!-- /gi-console -->

<script>
(function () {
	if (typeof jQuery === 'undefined') { return; }
	var A = window.STI || {};
	var page = document.getElementById('gi-pipeline-page');
	if (!page) { return; }

	/* Polling موجود 10.11 — بدون تغییر contract: action/params/nonce یکسان. */
	var pollSec  = <?php echo (int) ( class_exists( 'STI_GS_Automation' ) ? STI_GS_Automation::get( 'poll_interval' ) : 4 ); ?>;
	var SESSIONS_URL = '<?php echo esc_js( admin_url( 'admin.php?page=sti-golden-scan&gs_view=sessions' ) ); ?>';
	var inFlight = false;
	var stageKeys = <?php echo wp_json_encode( array( 'DISCOVER', 'BOT', 'MATCH', 'DOWNLOAD', 'MEDIA', 'PRODUCT', 'PUBLISH' ) ); ?>;
	var lineMeta = {
		RUNNING:  ['running',  'در حال اجرا'],
		STOPPED:  ['stopped',  'توقف کرده'],
		PAUSING:  ['pausing',  'در حال توقف امن…'],
		DEGRADED: ['degraded', 'کاهش‌یافته (فشار منابع)'],
		ERROR:    ['error',    'خطا — خودترمیم در تیک بعدی']
	};

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}
	function num(v) {
		return (v === null || v === undefined) ? 'نامشخص' : v;
	}
	function setNum(id, v) {
		var el = document.getElementById(id);
		if (el && window.GI) { window.GI.setNumber(el, num(v)); }
	}
	function setLine(state) {
		var lm = lineMeta[state] || lineMeta.STOPPED;
		var label = document.getElementById('gs-line-label');
		var st = document.getElementById('gs-line-state');
		var dot = page.querySelector('.gi-hero-state .gi-dot');
		if (label) { label.textContent = lm[1]; }
		if (st) { st.textContent = state; }
		if (dot) {
			dot.className = 'gi-dot gi-dot--' + lm[0] + (lm[0] === 'running' ? ' gi-pulse' : '');
		}
		var chip = page.querySelector('.gi-line-chip');
		if (chip) {
			var d = chip.querySelector('.gi-dot');
			if (d) { d.className = 'gi-dot gi-dot--' + lm[0] + (lm[0] === 'running' ? ' gi-pulse' : ''); }
			chip.lastChild.textContent = ' ' + lm[1];
		}
		var $stop = $('#gs-line-stop'), $start = $('#gs-line-start');
		if (state === 'RUNNING' || state === 'DEGRADED') {
			$stop.attr('hidden', null).show(); $start.attr('hidden', true).hide();
		} else {
			$stop.attr('hidden', true).hide(); $start.attr('hidden', null).show();
		}
	}
	function renderSummary(s) {
		setNum('gs-k-requested', s.requested);
		setNum('gs-k-created', s.created);
		setNum('gs-k-processing', s.processing);
		setNum('gs-k-waiting', s.waiting);
		setNum('gs-k-failed', s.failed);
		setNum('gs-k-published', s.published);
		setNum('gs-k-review', s.review);
		setNum('gs-k-cancelled', s.cancelled);
		setNum('gs-hm-queue', s.waiting);
		setNum('gs-flow-pub', s.published);
		setNum('gs-flow-review', s.review);
		setNum('gs-flow-cancel', s.cancelled);
	}
	function renderFlow(sessions, cur) {
		/* شمارش از داده‌ی poll (همان contract) */
		var counts = {};
		stageKeys.forEach(function (k) { counts[k] = 0; });
		(sessions || []).forEach(function (it) {
			if (it.stage_idx >= 0 && it.stage_idx < stageKeys.length) {
				counts[stageKeys[it.stage_idx]]++;
			}
		});
		var maxActive = -1;
		stageKeys.forEach(function (k, i) { if (counts[k] > 0) { maxActive = Math.max(maxActive, i); } });
		$('#gs-flow li').each(function () {
			var $li = $(this), k = $li.data('stage');
			var cnt = counts[k] || 0;
			var i = stageKeys.indexOf(k);
			var st = cnt > 0 ? (i <= maxActive ? 'active' : 'done') : ((maxActive > i && maxActive > 0) ? 'done' : '');
			if ($li.data('state') !== st) { $li.attr('data-state', st); }
			var $c = $li.find('.gi-flow-count');
			if (window.GI) { window.GI.setNumber($c[0], cnt); }
			var $n = $li.find('.gi-flow-node');
			$n.text(i < maxActive && maxActive > 0 ? '✓' : (i === maxActive ? '⟳' : String(i + 1)));
		});
	}
	function renderCurrent(c) {
		var $card = $('#gs-current-card');
		if (!c) {
			/* اگر server-side داده بود، به empty state برگردان */
			if ($card.find('#gs-cur-stage').length) {
				$card.html(
					'<div class="gi-card-head"><h2 class="gi-card-title">⚡ Current Activity</h2><span class="gi-card-sub">حالا چه می‌شود</span></div>' +
					'<div class="gi-empty" style="padding:var(--gi-s5);"><div class="gi-empty-ico" aria-hidden="true">⏸</div>' +
					'<div class="gi-empty-title">فعلاً Sessionی در حال اجرا نیست</div>' +
					'<div class="gi-empty-sub">Worker در تیک بعدی نوبت می‌دهد.</div></div>'
				);
			}
			return;
		}
		if (!$card.find('#gs-cur-stage').length) {
			$card.html(
				'<div class="gi-card-head"><h2 class="gi-card-title">⚡ Current Activity</h2><span class="gi-card-sub">حالا چه می‌شود</span></div>' +
				'<div class="gi-flex-between" style="margin-bottom:var(--gi-s3);">' +
				'<span class="gi-badge gi-badge--brand" style="font-size:var(--gi-fs1);">Session <a href="' + SESSIONS_URL + '" class="gi-nums">#' + c.id + '</a></span></div>' +
				'<div style="margin-bottom:var(--gi-s3);"><span class="gi-badge gi-badge--info" id="gs-cur-stage" style="font-size:var(--gi-fs1);">' + esc(c.label) + '</span></div>' +
				'<div class="gi-exc-grid">' +
				'<div><div class="gi-exc-k">Retry</div><div class="gi-exc-v" id="gs-cur-retry"></div></div>' +
				'<div><div class="gi-exc-k">Worker</div><div class="gi-exc-v gi-mono" id="gs-cur-worker"></div></div>' +
				'<div><div class="gi-exc-k">آخرین فعالیت</div><div class="gi-exc-v" id="gs-cur-updated"></div></div>' +
				'<div><div class="gi-exc-k">در صف اجرا</div><div class="gi-exc-v" id="gs-cur-queue"></div></div>' +
				'</div>'
			);
		}
		$('#gs-cur-stage').text(c.label);
		$('#gs-cur-retry').html('<span class="gi-nums">' + c.attempts + '</span> / <span class="gi-nums">' + c.retry_limit + '</span>');
		$('#gs-cur-worker').text(c.worker_id || '—');
		$('#gs-cur-updated').text(c.updated_at);
		$('#gs-cur-queue').text(c.queue > 0 ? c.queue + ' Session' : '—');
	}
	function renderSessions(items) {
		var $tb = $('#gs-sessions-body');
		if (!items || !items.length) {
			$tb.html('<tr><td colspan="4" style="text-align:center;color:var(--gi-text-faint);padding:var(--gi-s6);">Session فعالی نیست — صف خالی است 🎉</td></tr>');
			return;
		}
		var html = '';
		$.each(items, function (i, it) {
			var chips = '';
			stageKeys.forEach(function (k, j) {
				var cls = it.stage_idx < 0 ? '' : (j < it.stage_idx ? 'gi-chip--done' : (j === it.stage_idx ? 'gi-chip--cur' : ''));
				var mark = it.stage_idx < 0 ? '·' : (j < it.stage_idx ? '✓' : (j === it.stage_idx ? '⟳' : '·'));
				chips += '<span class="gi-chip ' + cls + '">' + mark + '</span>';
			});
			html += '<tr>' +
				'<td data-label="Session"><strong class="gi-nums">#' + it.id + '</strong>' +
				(it.file ? '<div class="gi-faint" style="font-size:var(--gi-fs0);">' + esc(it.file) + '</div>' : '') + '</td>' +
				'<td data-label="Stage"><span class="gi-chips">' + chips + '</span>' +
				'<div class="gi-faint gi-mono" style="font-size:10px;">' + esc(it.label) + '</div></td>' +
				'<td data-label="تلاش" class="gi-nums">' + it.attempts + '</td>' +
				'<td data-label="آخرین فعالیت" style="white-space:nowrap;">' + esc(it.updated_at) + '</td></tr>';
		});
		$tb.html(html);
	}
	function renderEvents(events) {
		var $ul = $('#gs-events');
		if (!events || !events.length) {
			$ul.html('<li style="color:var(--gi-text-faint);border:none;">هنوز رویدادی ثبت نشده است.</li>');
			return;
		}
		var html = '';
		$.each(events, function (i, ev) {
			var r = (ev.r === 'ok' || ev.r === 'retry' || ev.r === 'error') ? ev.r : 'ok';
			var sid = ev.s > 0 ? '<a href="' + SESSIONS_URL + '">#' + ev.s + '</a>' : '';
			html += '<li data-r="' + r + '"><time>' + esc(ev.t) + '</time>' +
				'<span class="gi-stream-kind">' + sid + ' ' + esc(ev.k) + '</span>' +
				'<span class="gi-stream-msg">' + esc(ev.m) + '</span></li>';
		});
		$ul.html(html);
		$('#gs-ev-count').text(events.length);
	}

	/* poll — single-flight + توقف هنگام پنهان بودن تب (بدون AJAX flood) */
	function poll() {
		if (inFlight) { return; }
		if (document.hidden) { return; }
		inFlight = true;
		$.post(A.ajaxUrl, { action: 'sti_gs_pipeline_poll', nonce: A.nonce })
			.done(function (res) {
				if (!res || !res.success || !res.data) { return; }
				var d = res.data;
				if (d.line && d.line.state) { setLine(d.line.state); }
				if (d.summary) { renderSummary(d.summary); }
				renderFlow(d.sessions, d.current);
				renderCurrent(d.current || null);
				renderSessions(d.sessions);
				renderEvents(d.events);
				var active = 0;
				(d.sessions || []).forEach(function (it) { if (it.active) { active++; } });
				setNum('gs-hm-active', active);
			})
			.always(function () { inFlight = false; });
	}

	/* START / STOP — همان endpointهای 10.11 */
	function lineAction(action, msg) {
		var $m = $('#gs-line-msg');
		$m.text(msg).removeClass('ok err');
		$.post(A.ajaxUrl, { action: action, nonce: A.nonce }).done(function (res) {
			if (res && res.success && res.data) {
				setLine(res.data.state);
				$m.text(res.data.state === 'PAUSING' ? 'در حال توقف امن — Stage در حال اجرا تمام می‌شود…' : 'وضعیت: ' + res.data.state).addClass('ok');
			} else {
				$m.text('❌ خطا در تغییر وضعیت').addClass('err');
			}
		}).fail(function () {
			$m.text('❌ خطای ارتباط').addClass('err');
		});
	}
	$('#gs-line-start').on('click', function () { lineAction('sti_gs_line_start', 'در حال START…'); });
	$('#gs-line-stop').on('click', function () { lineAction('sti_gs_line_stop', 'در حال STOP امن…'); });

	jQuery(function ($) {
		setInterval(poll, Math.max(2, pollSec) * 1000);
	});
})();
</script>
