<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

/* ۱۰.۱۱ — مرکز عملیات زنده: داده‌ی اولیه سمت سرور (بدون JS هم کامل است). */
$data = class_exists( 'STI_GS_Line' ) ? STI_GS_Line::monitor() : array(
	'line' => array( 'state' => 'STOPPED' ), 'request' => array(), 'summary' => null,
	'current' => null, 'sessions' => array(), 'events' => array(), 'ok' => false,
);

$line     = isset( $data['line']['state'] ) ? $data['line']['state'] : 'STOPPED';
$summary  = isset( $data['summary'] ) && is_array( $data['summary'] ) ? $data['summary'] : null;
$cur      = isset( $data['current'] ) ? $data['current'] : null;
$stages   = array( 'DISCOVER' => 'اسکن', 'BOT' => 'ربات', 'MATCH' => 'تطبیق', 'DOWNLOAD' => 'دانلود', 'MEDIA' => 'مدیا', 'PRODUCT' => 'محصول', 'PUBLISH' => 'انتشار' );
$stage_ord = array( 'DISCOVER', 'BOT', 'MATCH', 'DOWNLOAD', 'MEDIA', 'PRODUCT', 'PUBLISH' );

$line_styles = array(
	'RUNNING'  => array( '🟢', '#e8f5e9', '#1e7e34', 'در حال کار' ),
	'STOPPED'  => array( '⚫', '#eceff1', '#37474f', 'توقف کرده' ),
	'PAUSING'  => array( '🟡', '#fff8e1', '#9a6b00', 'در حال توقف امن…' ),
	'DEGRADED' => array( '🟠', '#fff3e0', '#b25e00', 'کاهش‌یافته (فشار منابع)' ),
	'ERROR'    => array( '🔴', '#ffebee', '#c62828', 'خطا (خودترمیم در تیک بعدی)' ),
);
$ls = isset( $line_styles[ $line ] ) ? $line_styles[ $line ] : $line_styles['STOPPED'];

/** چیپ‌های Stage یک Session (از state — نه حدس). */
function gs_line_stage_chips( $stage_idx, $stages, $stage_ord ) {
	$html = '';
	foreach ( $stage_ord as $i => $st ) {
		if ( -1 === $stage_idx ) {
			$cls = 'gs-chip gs-chip-wait';
		} elseif ( $i < $stage_idx ) {
			$cls = 'gs-chip gs-chip-done';
		} elseif ( $i === $stage_idx ) {
			$cls = 'gs-chip gs-chip-cur';
		} else {
			$cls = 'gs-chip gs-chip-wait';
		}
		$mark = ( -1 === $stage_idx ) ? '·' : ( $i < $stage_idx ? '✓' : ( $i === $stage_idx ? '⟳' : '·' ) );
		$html .= '<span class="' . $cls . '" title="' . esc_attr( $stages[ $st ] ) . '">' . $mark . '</span>';
	}
	return $html;
}
?>
<style>
.gs-chip{display:inline-block;min-width:26px;padding:2px 5px;margin:1px;border-radius:10px;font-size:11px;text-align:center;font-weight:700;}
.gs-chip-done{background:#e8f5e9;color:#1e7e34;}
.gs-chip-cur{background:#e3f2fd;color:#0d47a1;animation:gs-pulse 1.6s ease-in-out infinite;}
.gs-chip-wait{background:#f5f5f5;color:#bbb;}
@keyframes gs-pulse{0%,100%{opacity:1}50%{opacity:.45}}
.gs-line-badge{display:inline-block;padding:10px 22px;border-radius:12px;font-size:20px;font-weight:800;}
.gs-card{background:#fff;border:1px solid #e3e6ea;border-radius:10px;padding:14px 16px;box-shadow:0 1px 2px rgba(20,30,50,.04);}
.gs-card h3{margin:0 0 10px;font-size:13px;color:#555;text-transform:none;}
.gs-stat-row{display:flex;flex-wrap:wrap;gap:10px;}
.gs-stat{flex:1;min-width:110px;background:#fff;border:1px solid #e3e6ea;border-radius:10px;padding:10px 12px;}
.gs-stat .v{font-size:24px;font-weight:800;line-height:1.2;}
.gs-stat .l{font-size:11px;color:#777;}
.gs-ev{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;line-height:1.9;border-bottom:1px dashed #eee;padding:3px 4px;}
.gs-ev-err{color:#c62828;}
.gs-ev-retry{color:#9a6b00;}
.gs-ev-ok{color:#37474f;}
</style>
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — 🏭 خط تولید (Live Pipeline)</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<?php if ( ! empty( $data['ok'] ) ) : ?>

	<!-- ══════════════ LINE STATUS ══════════════ -->
	<div class="gs-card" style="margin-top:16px;">
		<div style="display:flex;flex-wrap:wrap;align-items:center;gap:14px;">
			<span class="gs-line-badge" id="gs-line-badge" style="background:<?php echo $ls[1]; ?>;color:<?php echo $ls[2]; ?>;border:1px solid <?php echo $ls[2]; ?>33;">
				<?php echo $ls[0]; ?> <?php echo esc_html( $line ); ?> — <?php echo esc_html( $ls[3] ); ?>
			</span>
			<?php if ( 'RUNNING' === $line || 'DEGRADED' === $line ) : ?>
				<button type="button" class="button button-secondary" id="gs-line-stop" style="font-size:13px;font-weight:700;">■ STOP LINE</button>
			<?php else : ?>
				<button type="button" class="button button-primary" id="gs-line-start" style="font-size:13px;font-weight:700;">▶ START LINE</button>
			<?php endif; ?>
			<span id="gs-line-msg" style="font-size:12px;color:#777;"></span>
		</div>
		<?php
		$g_level   = isset( $data['line']['level'] ) ? $data['line']['level'] : 'OK';
		$g_factor  = isset( $data['line']['factor'] ) ? (float) $data['line']['factor'] : 1.0;
		$g_reasons = isset( $data['line']['reasons'] ) ? (array) $data['line']['reasons'] : array();
		?>
		<div style="margin-top:10px;font-size:12px;color:#666;">
			Governor:
			<strong style="color:<?php echo 'EMERGENCY' === $g_level ? '#c62828' : ( 'THROTTLE' === $g_level ? '#9a6b00' : '#1e7e34' ); ?>"><?php echo esc_html( $g_level ); ?></strong>
			(ضریب <?php echo number_format_i18n( $g_factor, 2 ); ?>)
			<?php if ( $g_reasons ) : ?> — <?php echo esc_html( implode( ' · ', array_map( 'mb_substr', $g_reasons, array_fill( 0, count( $g_reasons ), 80 ) ) ) ); ?><?php endif; ?>
			<?php if ( 'STOPPED' === $line ) : ?>
				<br><span style="color:#888;">STOP امن است: Sessionهای در حال اجرا Stage خود را تا انتها می‌رانند، هیچ process kill نمی‌شود و هیچ داده‌ای حذف نمی‌شود. START ادامه‌ی واقعی از همان جاست.</span>
			<?php endif; ?>
			<?php if ( 'PAUSING' === $line ) : ?>
				<br><span style="color:#9a6b00;">در حال انتظار تا Stageهای در حال اجرا تمام شوند…</span>
			<?php endif; ?>
		</div>
	</div>

	<!-- ══════════════ LIVE SUMMARY ══════════════ -->
	<div class="gs-stat-row" style="margin-top:12px;">
		<?php
		$cards = array(
			'Total requested' => array( isset( $summary['requested'] ) ? $summary['requested'] : null, '#37474f' ),
			'Created'         => array( isset( $summary['created'] ) ? $summary['created'] : null, '#37474f' ),
			'Processing'      => array( isset( $summary['processing'] ) ? $summary['processing'] : null, '#0d47a1' ),
			'Waiting'         => array( isset( $summary['waiting'] ) ? $summary['waiting'] : null, '#9a6b00' ),
			'Failed (retry)'  => array( isset( $summary['failed'] ) ? $summary['failed'] : null, '#c62828' ),
			'Published'       => array( isset( $summary['published'] ) ? $summary['published'] : null, '#1e7e34' ),
			'Review'          => array( isset( $summary['review'] ) ? $summary['review'] : null, '#6a1b9a' ),
			'Cancelled'       => array( isset( $summary['cancelled'] ) ? $summary['cancelled'] : null, '#78909c' ),
		);
		foreach ( $cards as $label => $c ) : ?>
			<div class="gs-stat"><div class="v" style="color:<?php echo $c[1]; ?>"><?php echo null === $c[0] ? 'نامشخص' : number_format_i18n( $c[0] ); ?></div><div class="l"><?php echo esc_html( $label ); ?></div></div>
		<?php endforeach; ?>
	</div>

	<?php if ( ! empty( $summary['unknown'] ) ) : ?>
		<div class="notice notice-warning" style="margin-top:12px;font-size:12px;">
			⚠ <?php echo number_format_i18n( $summary['unknown'] ); ?> Session با state ناشناخته — Supervisor آن‌ها را anomaly ثبت کرده است.
		</div>
	<?php endif; ?>

	<!-- ══════════════ CURRENT ACTIVITY ══════════════ -->
	<div class="gs-card" id="gs-current-card" style="margin-top:12px;">
		<h3>⚡ CURRENT ACTIVITY (فعلاً چه می‌شود)</h3>
		<?php if ( $cur ) : ?>
			<table class="widefat striped" id="gs-cur-table" style="max-width:900px;">
				<tbody>
					<tr><td style="width:150px;"><strong>Session</strong></td>
						<td id="gs-cur-session"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=sessions' ) ); ?>">#<?php echo (int) $cur['id']; ?></a>
						<?php if ( ! empty( $cur['file'] ) ) : ?><span style="color:#888;font-size:11px;">— <?php echo esc_html( $cur['file'] ); ?></span><?php endif; ?></td></tr>
					<tr><td><strong>Stage / Status</strong></td>
						<td id="gs-cur-stage"><?php echo esc_html( (string) $cur['label'] ); ?>
						<?php echo gs_line_stage_chips( (int) $cur['stage_idx'], $stages, $stage_ord ); ?></td></tr>
					<tr><td><strong>Retry</strong></td>
						<td id="gs-cur-retry"><?php echo number_format_i18n( (int) $cur['attempts'] ); ?> / <?php echo number_format_i18n( (int) $cur['retry_limit'] ); ?></td></tr>
					<tr><td><strong>Worker</strong></td><td id="gs-cur-worker"><code dir="ltr"><?php echo esc_html( (string) $cur['worker_id'] ); ?></code></td></tr>
					<tr><td><strong>آخرین فعالیت</strong></td><td id="gs-cur-updated"><?php echo esc_html( (string) $cur['updated_at'] ); ?></td></tr>
					<tr><td><strong>در صف اجرا</strong></td><td id="gs-cur-queue"><?php echo number_format_i18n( (int) $cur['queue'] ); ?> Session دیگر</td></tr>
				</tbody>
			</table>
			<p id="gs-cur-empty" style="display:none;margin:0;color:#888;font-size:13px;">فعلاً Sessionی در حال اجرا نیست — Worker در تیک بعدی نوبت می‌دهد.</p>
		<?php else : ?>
			<p id="gs-cur-empty" style="margin:0;color:#888;font-size:13px;">فعلاً Sessionی در حال اجرا نیست — Worker در تیک بعدی نوبت می‌دهد.
			<?php if ( 'STOPPED' === $line ) : ?><br>خط تولید STOP است؛ برای ادامه ▶ START LINE بزنید.<?php endif; ?></p>
			<table class="widefat striped" id="gs-cur-table" style="max-width:900px;display:none;">
				<tbody>
					<tr><td style="width:150px;"><strong>Session</strong></td><td id="gs-cur-session"></td></tr>
					<tr><td><strong>Stage / Status</strong></td><td id="gs-cur-stage"></td></tr>
					<tr><td><strong>Retry</strong></td><td id="gs-cur-retry"></td></tr>
					<tr><td><strong>Worker</strong></td><td id="gs-cur-worker"></td></tr>
					<tr><td><strong>آخرین فعالیت</strong></td><td id="gs-cur-updated"></td></tr>
					<tr><td><strong>در صف اجرا</strong></td><td id="gs-cur-queue"></td></tr>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- ══════════════ SESSION PIPELINE ══════════════ -->
	<div class="gs-card" style="margin-top:12px;">
		<h3>📦 SESSION PIPELINE (Sessionهای فعال — نهایی‌ها در تب‌های جدا)</h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Session</th>
					<th style="width:220px;">DISCOVER</th><th>BOT</th><th>MATCH</th>
					<th>DOWNLOAD</th><th>MEDIA</th><th>PRODUCT</th><th>PUBLISH</th>
					<th>تلاش</th><th>آخرین فعالیت</th>
				</tr>
			</thead>
			<tbody id="gs-sessions-body">
				<?php if ( empty( $data['sessions'] ) ) : ?>
					<tr><td colspan="10" style="color:#888;">Session فعالی نیست — صف خالی است. 🎉</td></tr>
				<?php else : ?>
					<?php foreach ( $data['sessions'] as $it ) : ?>
						<tr>
							<td><strong>#<?php echo (int) $it['id']; ?></strong>
								<?php if ( ! empty( $it['file'] ) ) : ?><br><span style="font-size:11px;color:#888;"><?php echo esc_html( $it['file'] ); ?></span><?php endif; ?>
								<br><span style="font-size:10px;color:#aaa;" dir="ltr"><?php echo esc_html( (string) $it['label'] ); ?></span></td>
							<td colspan="7"><?php echo gs_line_stage_chips( (int) $it['stage_idx'], $stages, $stage_ord ); ?></td>
							<td><?php echo number_format_i18n( (int) $it['attempts'] ); ?></td>
							<td style="white-space:nowrap;"><?php echo esc_html( (string) $it['updated_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<!-- ══════════════ LIVE EVENT STREAM ══════════════ -->
	<div class="gs-card" style="margin-top:12px;">
		<h3>📡 LIVE EVENT STREAM (آخرین ۳۰ رویداد — <span id="gs-ev-count"><?php echo count( $data['events'] ); ?></span>)</h3>
		<div id="gs-events" style="max-height:340px;overflow-y:auto;">
			<?php if ( empty( $data['events'] ) ) : ?>
				<p style="margin:0;color:#888;font-size:12px;">هنوز رویدادی ثبت نشده است.</p>
			<?php else : ?>
				<?php foreach ( $data['events'] as $ev ) :
					$cls = 'gs-ev gs-ev-' . ( in_array( $ev['r'], array( 'ok', 'retry', 'error' ), true ) ? $ev['r'] : 'ok' );
				?>
					<div class="<?php echo $cls; ?>">
						<span dir="ltr"><?php echo esc_html( (string) $ev['t'] ); ?></span>
						<?php if ( $ev['s'] > 0 ) : ?> Session <a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=sessions' ) ); ?>">#<?php echo (int) $ev['s']; ?></a><?php endif; ?>
						→ <strong><?php echo esc_html( (string) $ev['k'] ); ?></strong> <?php echo esc_html( (string) $ev['m'] ); ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>

	<?php else : ?>
		<div class="notice notice-warning" style="margin-top:16px;">
			⚠ مانیتور داده کامل برنگرداند (جداول مهاجرت‌نشده یا خطای گذرا) — صفحه بعد از چند ثانیه دوباره بررسی می‌کند.
		</div>
	<?php endif; ?>
</div>

<script>
(function () {
	if (typeof jQuery === 'undefined') { return; }
	var A = window.STI || {};
	var pollSec = <?php echo (int) ( class_exists( 'STI_GS_Automation' ) ? STI_GS_Automation::get( 'poll_interval' ) : 4 ); ?>;
	var inFlight = false;
	var lineState = '<?php echo esc_js( $line ); ?>';
	var stages = <?php echo wp_json_encode( array( 'DISCOVER' => 'اسکن', 'BOT' => 'ربات', 'MATCH' => 'تطبیق', 'DOWNLOAD' => 'دانلود', 'MEDIA' => 'مدیا', 'PRODUCT' => 'محصول', 'PUBLISH' => 'انتشار' ), JSON_UNESCAPED_UNICODE ); ?>;
	var stageOrd = <?php echo wp_json_encode( array( 'DISCOVER', 'BOT', 'MATCH', 'DOWNLOAD', 'MEDIA', 'PRODUCT', 'PUBLISH' ) ); ?>;
	var lineStyles = {
		RUNNING:  ['🟢', '#e8f5e9', '#1e7e34', 'در حال کار'],
		STOPPED:  ['⚫', '#eceff1', '#37474f', 'توقف کرده'],
		PAUSING:  ['🟡', '#fff8e1', '#9a6b00', 'در حال توقف امن…'],
		DEGRADED: ['🟠', '#fff3e0', '#b25e00', 'کاهش‌یافته (فشار منابع)'],
		ERROR:    ['🔴', '#ffebee', '#c62828', 'خطا (خودترمیم در تیک بعدی)']
	};

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}
	function num(v) {
		return (v === null || v === undefined) ? 'نامشخص' : v;
	}
	function chips(idx) {
		var h = '';
		for (var i = 0; i < stageOrd.length; i++) {
			var cls, mark;
			if (idx === -1) { cls = 'gs-chip-wait'; mark = '·'; }
			else if (i < idx) { cls = 'gs-chip-done'; mark = '✓'; }
			else if (i === idx) { cls = 'gs-chip-cur'; mark = '⟳'; }
			else { cls = 'gs-chip-wait'; mark = '·'; }
			h += '<span class="gs-chip ' + cls + '" title="' + esc(stages[stageOrd[i]]) + '">' + mark + '</span>';
		}
		return h;
	}
	function setBadge(state) {
		var ls = lineStyles[state] || lineStyles.STOPPED;
		var $b = $('#gs-line-badge');
		$b.css({ background: ls[1], color: ls[2], border: '1px solid ' + ls[2] + '33' })
			.html(ls[0] + ' ' + esc(state) + ' — ' + esc(ls[3]));
		/* دکمه‌ها */
		if (state === 'RUNNING' || state === 'DEGRADED') {
			$('#gs-line-stop').show(); $('#gs-line-start').hide();
		} else {
			$('#gs-line-stop').hide(); $('#gs-line-start').show();
		}
		/* اگر دکمه‌ای که render نشده را می‌خواهیم، بسازیم */
		if (!$('#gs-line-stop').length && (state === 'RUNNING' || state === 'DEGRADED')) {
			$('<button type="button" class="button button-secondary" id="gs-line-stop" style="font-size:13px;font-weight:700;">■ STOP LINE</button>').insertBefore('#gs-line-msg');
			bindButtons();
		}
		if (!$('#gs-line-start').length && !(state === 'RUNNING' || state === 'DEGRADED')) {
			$('<button type="button" class="button button-primary" id="gs-line-start" style="font-size:13px;font-weight:700;">▶ START LINE</button>').insertBefore('#gs-line-msg');
			bindButtons();
		}
	}
	function renderSummary(s) {
		var map = {
			'Total requested': 'requested', 'Created': 'created', 'Processing': 'processing',
			'Waiting': 'waiting', 'Failed (retry)': 'failed', 'Published': 'published',
			'Review': 'review', 'Cancelled': 'cancelled'
		};
		$('.gs-stat').each(function () {
			var $v = $(this).find('.v');
			var label = $(this).find('.l').text().trim();
			var key = map[label];
			if (key) { $v.text(num(s[key])); }
		});
	}
	function renderCurrent(c) {
		var $t = $('#gs-cur-table'), $e = $('#gs-cur-empty');
		if (!c) {
			$t.hide();
			$e.show().text('فعلاً Sessionی در حال اجرا نیست — Worker در تیک بعدی نوبت می‌دهد.');
			return;
		}
		$t.show(); $e.hide();
		var url = '<?php echo esc_js( admin_url( 'admin.php?page=sti-golden-scan&gs_view=sessions' ) ); ?>';
		$('#gs-cur-session').html('<a href="' + url + '">#' + c.id + '</a>' + (c.file ? ' <span style="color:#888;font-size:11px;">— ' + esc(c.file) + '</span>' : ''));
		$('#gs-cur-stage').html(esc(c.label) + ' ' + chips(c.stage_idx));
		$('#gs-cur-retry').text(c.attempts + ' / ' + c.retry_limit);
		$('#gs-cur-worker').html(c.worker_id ? '<code dir="ltr">' + esc(c.worker_id) + '</code>' : '—');
		$('#gs-cur-updated').text(c.updated_at);
		$('#gs-cur-queue').text((c.queue > 0) ? c.queue + ' Session دیگر' : '—');
	}
	function renderSessions(items) {
		var $tb = $('#gs-sessions-body');
		if (!items || !items.length) {
			$tb.html('<tr><td colspan="10" style="color:#888;">Session فعالی نیست — صف خالی است. 🎉</td></tr>');
			return;
		}
		var html = '';
		$.each(items, function (i, it) {
			html += '<tr>'
				+ '<td><strong>#' + it.id + '</strong>'
				+ (it.file ? '<br><span style="font-size:11px;color:#888;">' + esc(it.file) + '</span>' : '')
				+ '<br><span style="font-size:10px;color:#aaa;" dir="ltr">' + esc(it.label) + '</span></td>'
				+ '<td colspan="7">' + chips(it.stage_idx) + '</td>'
				+ '<td>' + it.attempts + '</td>'
				+ '<td style="white-space:nowrap;">' + esc(it.updated_at) + '</td>'
				+ '</tr>';
		});
		$tb.html(html);
	}
	function renderEvents(events) {
		var $box = $('#gs-events');
		if (!events || !events.length) {
			$box.html('<p style="margin:0;color:#888;font-size:12px;">هنوز رویدادی ثبت نشده است.</p>');
			return;
		}
		var html = '';
		$.each(events, function (i, ev) {
			var r = (ev.r === 'ok' || ev.r === 'retry' || ev.r === 'error') ? ev.r : 'ok';
			html += '<div class="gs-ev gs-ev-' + r + '">'
				+ '<span dir="ltr">' + esc(ev.t) + '</span>'
				+ (ev.s > 0 ? ' Session #' + ev.s : '')
				+ ' → <strong>' + esc(ev.k) + '</strong> ' + esc(ev.m)
				+ '</div>';
		});
		$box.html(html);
		$('#gs-ev-count').text(events.length);
	}

	/* poll سبک + single-flight + توقف هنگام پنهان بودن تب (no AJAX flood) */
	function poll() {
		if (inFlight) { return; }
		if (document.hidden) { return; }
		inFlight = true;
		$.post(A.ajaxUrl, { action: 'sti_gs_pipeline_poll', nonce: A.nonce })
			.done(function (res) {
				if (!res || !res.success || !res.data) { return; }
				var d = res.data;
				lineState = d.line ? d.line.state : lineState;
				setBadge(lineState);
				if (d.summary) { renderSummary(d.summary); }
				renderCurrent(d.current || null);
				renderSessions(d.sessions);
				renderEvents(d.events);
			})
			.always(function () { inFlight = false; });
	}

	function bindButtons() {
		$('#gs-line-start').off('click').on('click', function () {
			lineAction('sti_gs_line_start', 'در حال START…');
		});
		$('#gs-line-stop').off('click').on('click', function () {
			lineAction('sti_gs_line_stop', 'در حال STOP امن…');
		});
	}
	function lineAction(action, msg) {
		var $m = $('#gs-line-msg');
		$m.text(msg);
		$.post(A.ajaxUrl, { action: action, nonce: A.nonce }).done(function (res) {
			if (res && res.success && res.data) {
				lineState = res.data.state;
				setBadge(lineState);
				$m.text(lineState === 'PAUSING' ? 'در حال توقف امن — Stage در حال اجرا تمام می‌شود…' : 'وضعیت: ' + lineState);
			} else {
				$m.text('❌ خطا در تغییر وضعیت');
			}
		}).fail(function () {
			$m.text('❌ خطای ارتباط');
		});
	}

	jQuery(function ($) {
		bindButtons();
		setInterval(poll, Math.max(2, pollSec) * 1000);
	});
})();
</script>
