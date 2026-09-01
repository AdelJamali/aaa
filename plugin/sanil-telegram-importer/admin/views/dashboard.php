<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

$snap = null;
if ( class_exists( 'STI_Studio' ) ) {
	try {
		$snap = STI_Studio::dashboard_snapshot();
	} catch ( \Throwable $e ) {
		$snap = null;
		update_option( 'sti_v7_last_error', 'داشبورد: ' . mb_substr( $e->getMessage(), 0, 300 ), false );
	}
}
if ( ! is_array( $snap ) ) {
	// اگر داشبورد تازه به هر دلیلی کار نکرد، شاخص‌های پایه نمایش داده می‌شوند
	$counts_fb = STI_Session::counts_today();
	$snap = array(
		'time'   => wp_date( 'H:i:s' ),
		'counts' => array( 'error' => STI_Session::count_by_status( 'error' ) ),
		'today'  => array(
			'created'   => (int) $counts_fb['created'],
			'published' => (int) $counts_fb['published'],
			'errors'    => (int) $counts_fb['error'],
		),
		'series' => array(), 'categories' => array(),
		'queue'  => array( 'running' => false, 'queued' => STI_Session::count_queued(), 'interval' => 0, 'next_at' => 0, 'healthy' => false, 'last_tick' => 0 ),
		'top_errors' => array(), 'batches' => array(),
		'ai' => array( 'stats' => array(), 'health' => array() ),
		'titles' => array( 'good' => 0, 'ok' => 0, 'bad' => 0 ),
		'logs'   => array(),
	);
}
?>
<div class="wrap sti-wrap sti-dashboard-v7">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">

		<div class="sti-hero">
			<div>
				<span class="sti-eyebrow">GOLDEN IMPORTER v<?php echo esc_html( STI_VERSION ); ?></span>
				<h1>اتاق کنترل</h1>
				<p><span class="sti-live-dot"></span> زنده — آخرین به‌روزرسانی <span id="d-time"><?php echo esc_html( $snap['time'] ); ?></span></p>
			</div>
			<div class="sti-hero-actions">
				<a class="sti-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-channel-import' ) ); ?>">واردات از کانال</a>
				<a class="sti-btn secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-title-tools' ) ); ?>">استودیوی عنوان</a>
				<button type="button" class="sti-btn secondary" id="d-refresh">↻</button>
			</div>
		</div>

		<!-- لایه ۱: شاخص‌های امروز -->
		<div class="sti-grid g4" style="margin-bottom:14px;">
			<div class="sti-kpi info">
				<div class="k-top"><span>ورودی امروز</span><span class="dashicons dashicons-download"></span></div>
				<div class="k-val" id="k-created"><?php echo (int) $snap['today']['created']; ?></div>
				<div class="k-sub">Session ساخته‌شده از تلگرام</div>
			</div>
			<div class="sti-kpi ok">
				<div class="k-top"><span>منتشرشده امروز</span><span class="dashicons dashicons-yes-alt"></span></div>
				<div class="k-val" id="k-published"><?php echo (int) $snap['today']['published']; ?></div>
				<div class="k-sub" id="k-queue-sub">در صف: <?php echo (int) $snap['queue']['queued']; ?> · هر <?php echo (int) $snap['queue']['interval']; ?> دقیقه</div>
			</div>
			<div class="sti-kpi warn">
				<div class="k-top"><span>در صف انتشار</span><span class="dashicons dashicons-clock"></span></div>
				<div class="k-val" id="k-queued"><?php echo (int) $snap['queue']['queued']; ?></div>
				<div class="k-sub" id="k-next"><?php echo $snap['queue']['next_at'] ? 'انتشار بعدی: ' . esc_html( human_time_diff( time(), $snap['queue']['next_at'] ) ) : 'صف خالی است'; ?></div>
			</div>
			<div class="sti-kpi bad">
				<div class="k-top"><span>نیازمند رسیدگی</span><span class="dashicons dashicons-warning"></span></div>
				<div class="k-val" id="k-errors"><?php echo (int) ( $snap['counts']['error'] ?? 0 ); ?></div>
				<div class="k-sub">خطای امروز: <span id="k-errors-today"><?php echo (int) $snap['today']['errors']; ?></span></div>
			</div>
		</div>

		<!-- لایه ۲: روند + سلامت -->
		<div class="sti-grid g2" style="margin-bottom:14px;">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>روند ۱۴ روز</h2><p>ورودی در برابر خطا</p></div></div>
				<div class="sti-spark" id="d-spark"></div>
				<div style="display:flex;justify-content:space-between;font-size:11px;color:#9aa1ae;margin-top:6px;">
					<span id="d-spark-from">—</span><span id="d-spark-to">امروز</span>
				</div>
			</div>
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>سلامت سامانه</h2><p>موتورهایی که باید همیشه روشن باشند</p></div></div>
				<table class="sti-table"><tbody>
					<tr><td>موتور صف (Cron)</td><td id="h-queue"></td></tr>
					<tr><td>هوش مصنوعی</td><td id="h-ai"></td></tr>
					<tr><td>کیفیت عنوان‌ها</td><td id="h-titles"></td></tr>
					<tr><td>وضعیت صف</td><td>
						<button id="sti-queue-toggle" class="sti-btn <?php echo ! empty( $snap['queue']['running'] ) ? 'danger' : ''; ?>"><?php echo ! empty( $snap['queue']['running'] ) ? 'توقف صف' : 'شروع صف'; ?></button>
						<span id="sti-queue-toggle-result" class="sti-inline-result"></span>
					</td></tr>
				</tbody></table>
			</div>
		</div>

		<!-- لایه ۳: واردات فعال + دسته‌ها -->
		<div class="sti-grid g2" style="margin-bottom:14px;">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>واردات‌های در جریان</h2><p>وضعیت لحظه‌ای هر بچ</p></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-channel-import' ) ); ?>">مدیریت ←</a></div>
				<div id="d-batches"><p class="sti-empty">در حال بارگذاری…</p></div>
			</div>
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>دسته‌های پرکار (۷ روز)</h2></div></div>
				<div id="d-cats"></div>
			</div>
		</div>

		<!-- لایه ۴: خطاها + لاگ -->
		<div class="sti-grid g2">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>خطاهای پرتکرار (۷ روز)</h2><p>اول این‌ها را ببند</p></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-sessions' ) ); ?>">Sessionها ←</a></div>
				<div id="d-errors"></div>
			</div>
			<div class="sti-panel sti-activity">
				<div class="sti-panel-head"><div><h2>جریان رویدادها</h2></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-logs' ) ); ?>">همه ←</a></div>
				<ul class="sti-activity-list" id="d-logs"></ul>
			</div>
		</div>

		</div>
	</div>
</div>

<script>
jQuery(function ($) {
	var A = window.STI || {};
	var boot = <?php echo wp_json_encode( $snap ); ?>;

	function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
	function pill(text, cls) { return '<span class="sti-health-pill ' + cls + '">' + esc(text) + '</span>'; }

	function paint(d) {
		$('#d-time').text(d.time);
		$('#k-created').text(d.today.created);
		$('#k-published').text(d.today.published);
		$('#k-queued').text(d.queue.queued);
		$('#k-errors').text((d.counts && d.counts.error) || 0);
		$('#k-errors-today').text(d.today.errors);
		$('#k-queue-sub').text('در صف: ' + d.queue.queued + ' · هر ' + d.queue.interval + ' دقیقه');

		/* نمودار */
		var max = 1;
		(d.series || []).forEach(function (r) { max = Math.max(max, r.created, r.errors); });
		$('#d-spark').html((d.series || []).map(function (r) {
			var h = Math.round((r.created / max) * 100);
			var he = Math.round((r.errors / max) * 100);
			return '<i title="' + esc(r.d) + ': ' + r.created + ' ورودی، ' + r.errors + ' خطا" style="height:' + Math.max(4, h) + '%"></i>' +
				(r.errors ? '<i class="err" title="' + r.errors + ' خطا" style="height:' + Math.max(4, he) + '%"></i>' : '');
		}).join('') || '<span style="font-size:12px;color:#9aa1ae">داده‌ای نیست</span>');
		if (d.series && d.series.length) { $('#d-spark-from').text(d.series[0].d); }

		/* سلامت */
		$('#h-queue').html(
			(d.queue.healthy ? pill('پاسخگو', 'on') : pill('نیاز به بررسی', 'off')) +
			' <small>' + (d.queue.last_tick ? 'آخرین بررسی: ' + new Date(d.queue.last_tick * 1000).toLocaleTimeString('fa-IR') : 'ثبت نشده') + '</small>'
		);

		var ai = (d.ai && d.ai.health) || [];
		if (!ai.length) {
			$('#h-ai').html(pill('سرویسی ثبت نشده', 'off') + ' <a href="' + esc('<?php echo esc_url_raw( admin_url( "admin.php?page=sti-ai" ) ); ?>') + '">تنظیم</a>');
		} else {
			$('#h-ai').html(ai.map(function (p) {
				var cls = !p.enabled ? 'off' : (p.cooling ? 'cool' : 'on');
				return pill(p.name + ' (' + p.ok + '✓/' + p.fail + '✕)', cls);
			}).join(' '));
		}

		var t = d.titles || { good: 0, ok: 0, bad: 0 };
		var tot = Math.max(1, t.good + t.ok + t.bad);
		$('#h-titles').html(
			'<span class="sti-score s-good">' + Math.round(t.good / tot * 100) + '٪ سالم</span> ' +
			'<span class="sti-score s-ok">' + t.ok + ' متوسط</span> ' +
			'<span class="sti-score s-bad">' + t.bad + ' ضعیف</span>'
		);

		/* بچ‌ها */
		var b = d.batches || [];
		$('#d-batches').html(b.length ? b.map(function (x) {
			var pct = x.target ? Math.min(100, Math.round(x.imported / x.target * 100)) : 0;
			return '<div style="margin-bottom:12px;">' +
				'<div style="display:flex;justify-content:space-between;font-size:12.5px;"><strong>' + esc(x.channel || x.id) + '</strong>' +
				'<span>' + esc(x.status) + ' · ' + esc(x.stage) + (x.waiting ? ' · ' + x.waiting + ' در انتظار فایل' : '') + '</span></div>' +
				'<div class="sti-progress"><span style="width:' + pct + '%"></span></div>' +
				'<small>' + x.imported + ' از ' + x.target + '</small></div>';
		}).join('') : '<p class="sti-empty">واردات فعالی در جریان نیست.</p>');

		/* دسته‌ها */
		var cats = d.categories || [];
		var cmax = 1; cats.forEach(function (c) { cmax = Math.max(cmax, c.count); });
		$('#d-cats').html(cats.length ? cats.map(function (c) {
			return '<div style="margin-bottom:8px;"><div style="display:flex;justify-content:space-between;font-size:12.5px;"><span>' + esc(c.label) + '</span><strong>' + c.count + '</strong></div>' +
				'<div class="sti-progress"><span style="width:' + Math.round(c.count / cmax * 100) + '%"></span></div></div>';
		}).join('') : '<p class="sti-empty">داده‌ای نیست.</p>');

		/* خطاها */
		var errs = d.top_errors || [];
		$('#d-errors').html(errs.length ? '<table class="sti-table"><tbody>' + errs.map(function (e) {
			return '<tr><td>' + esc(e.message) + '</td><td style="width:60px;"><strong>' + e.count + '</strong></td></tr>';
		}).join('') + '</tbody></table>' : '<p class="sti-empty">هیچ خطایی در ۷ روز اخیر نبوده. 🎉</p>');

		/* لاگ */
		$('#d-logs').html((d.logs || []).map(function (l) {
			return '<li><span class="sti-log-dot ' + esc(l.level) + '"></span><div><strong>' + esc(l.message) + '</strong><small>' + esc(l.at) + '</small></div></li>';
		}).join('') || '<li>رویدادی نیست.</li>');
	}

	function refresh() {
		$.post(A.ajaxUrl, { action: 'sti_dash_stats', nonce: A.nonce }, function (res) {
			if (res && res.success) { paint(res.data); }
		});
	}

	paint(boot);
	$('#d-refresh').on('click', refresh);
	setInterval(refresh, 8000);
});
</script>
