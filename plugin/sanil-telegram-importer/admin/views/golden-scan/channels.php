<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

STI_GS_DB::install();
STI_GS_Scanner::instance();
$channels = STI_GS_Channel::all( 100 );
foreach ( $channels as &$gs_c ) {
	$gs_c['message_count'] = STI_GS_Channel::message_count( (int) $gs_c['id'] );
	if ( 'segmented' === $gs_c['scan_mode'] ) {
		$gs_c['segments'] = STI_GS_Segment::progress_summary( (int) $gs_c['id'] );
	}
}
unset( $gs_c );
?>
<?php
/* ۱۰.۱۲ — صفحه‌ی منابع فقط: افزودن کانال → اسکن → آمار.
 * ساخت محصول به «📦 صف انتشار» منتقل شده است. */
$gs_ready_total = (int) ( STI_GS_Channel_Watcher::stats()['ready'] ?? 0 );
?>
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">📡 منابع</h1>
		<p class="gi-h1-sub">مرحله‌ی ۱ و ۲: افزودن کانال و اسکن. این صفحه فقط ایندکس می‌کند؛ محصول در «📦 صف انتشار» ساخته و منتشر می‌شود.</p>
	</div>

	<div class="gi-bento">

		<!-- ۱) افزودن کانال (اولین کارت) -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<h2 class="gi-card-title">➕ افزودن کانال</h2>
				<span class="gi-card-sub">یوزرنیم، لینک t.me یا لینک دعوت خصوصی</span>
			</div>
			<div class="gi-form-row">
				<label for="gs-identifier">شناسه‌ی کانال</label>
				<input id="gs-identifier" dir="ltr" placeholder="@ChannelName یا https://t.me/ChannelName" style="max-width:520px;">
			</div>
			<div class="gi-flex" style="align-items:center;gap:var(--gi-s3);flex-wrap:wrap;">
				<button id="gs-add" class="gi-btn gi-btn--primary">➕ ثبت کانال</button>
				<span id="gs-add-result" class="gi-inline-res" role="status" aria-live="polite"></span>
			</div>
		</div>

		<!-- کانال‌های ثبت‌شده -->
		<div class="gi-card gi-card--flush gi-span-12">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) var(--gi-s3);">
				<div>
					<h2 class="gi-card-title">📡 کانال‌های ثبت‌شده</h2>
					<span class="gi-card-sub">وضعیت اسکن هر کانال زنده به‌روزرسانی می‌شود (هر ۳ ثانیه)</span>
				</div>
				<button id="gs-refresh" class="gi-btn gi-btn--subtle">⟳ بروزرسانی</button>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table gi-responsive" id="gs-channels-table">
					<thead>
						<tr>
							<th scope="col">عنوان</th>
							<th scope="col">شناسه</th>
							<th scope="col">پیشرفت</th>
							<th scope="col">وضعیت</th>
							<th scope="col">خطا</th>
							<th scope="col">عملیات</th>
						</tr>
					</thead>
					<tbody id="gs-channels">
						<?php if ( empty( $channels ) ) : ?>
							<tr><td colspan="6">
								<div class="gi-empty" style="padding:var(--gi-s7) var(--gi-s5);">
									<div class="gi-empty-ico" aria-hidden="true">📡</div>
									<div class="gi-empty-title">هنوز کانالی اضافه نشده.</div>
									<div class="gi-empty-sub">کانال تلگرام اولت را بالای صفحه ثبت کن — فقط ایندکس می‌شود، نه دانلود/انتشار.</div>
								</div>
							</td></tr>
						<?php else : ?>
							<?php foreach ( $channels as $c ) :
								$progress_html = (int) $c['message_count'] . ' پیام · نقطه‌ی ادامه: ' . (int) $c['last_scanned_message_id'];
								if ( ! empty( $c['segments'] ) ) {
									$s = $c['segments'];
									$progress_html = (int) $s['messages_saved'] . ' پیام · ' . (int) $s['done_segments'] . '/' . (int) $s['total_segments'] . ' بخش کامل';
								}
								$scan_state = 'running' === $c['scan_status'] ? 'running' : ( (int) $c['message_count'] > 0 ? 'done' : ( 'paused' === $c['scan_status'] || 'segmented' === $c['scan_status'] ? 'paused' : '' ) );
							?>
								<tr data-id="<?php echo (int) $c['id']; ?>">
									<td data-label="عنوان"><strong><?php echo esc_html( $c['title'] ?: '—' ); ?></strong></td>
									<td data-label="شناسه"><code dir="ltr" style="font-size:var(--gi-fs0);"><?php echo esc_html( $c['identifier'] ); ?></code></td>
									<td data-label="پیشرفت" class="gs-col-progress"><?php echo esc_html( $progress_html ); ?></td>
									<td data-label="وضعیت" class="gs-col-status"><span class="sti-badge"><?php echo esc_html( $c['scan_status'] ); ?></span></td>
									<td data-label="خطا" class="gs-col-error"><?php echo esc_html( $c['last_error'] ?: '—' ); ?></td>
									<td data-label="عملیات" class="gs-col-actions">
										<button class="gi-btn gi-btn--subtle gs-start" data-id="<?php echo (int) $c['id']; ?>">▶ شروع/ادامه</button>
										<button class="gi-btn gi-btn--ghost gs-edit" data-id="<?php echo (int) $c['id']; ?>" data-identifier="<?php echo esc_attr( $c['identifier'] ); ?>">✏️ ویرایش</button>
										<button class="gi-btn gi-btn--subtle gs-parallel" data-id="<?php echo (int) $c['id']; ?>">⚡ موازی</button>
										<button class="gi-btn gi-btn--ghost gs-pause" data-id="<?php echo (int) $c['id']; ?>">⏸ توقف</button>
										<button class="gi-btn gi-btn--ghost gs-delete" data-id="<?php echo (int) $c['id']; ?>" aria-label="حذف کانال" title="حذف">🗑</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					</table>
				</div>
			</div>

			<!-- اسکن موازی (بعد از لیست) -->
			<div class="gi-card gi-span-12">
				<div class="gi-card-head">
					<h2 class="gi-card-title">⚡ اسکن موازی برای کانال‌های بزرگ</h2>
				</div>
				<p class="gi-card-sub" style="font-size:var(--gi-fs1);">بازه‌ی شناسه‌ی پیام‌ها به چند بخش تقسیم می‌شود و هم‌زمان جلو می‌روند — چند برابر سریع‌تر از حالت ساده، و بدون خطر ذخیره‌ی تکراری (هر پیام با شناسه‌ی یکتا فقط یک‌بار ثبت می‌شود، حتی اگر دو بخش هم‌مرز به آن برسند). اسکن در هر دو حالت قابل توقف/ادامه است.</p>
			</div>

			<!-- ۱۰.۱۲ — CTA: موجودی آماده برای انتشار (ساخت محصول اینجا نیست) -->
			<div class="gi-card gi-card--accent gi-span-12">
				<div class="gi-card-head">
					<h2 class="gi-card-title">📦 <?php echo number_format_i18n( $gs_ready_total ); ?> محصول آماده برای انتشار</h2>
					<span class="gi-card-sub">مرحله‌ی بعد: انتخاب دسته‌ها و افزودن به صف انتشار</span>
				</div>
				<div class="gi-flex" style="align-items:center;gap:var(--gi-s3);flex-wrap:wrap;">
					<a class="gi-btn gi-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=publish-queue' ) ); ?>">انتخاب دسته و افزودن به صف ←</a>
					<button id="gs-start-diagnose" class="gi-btn" title="فقط‌خواندنی — هیچ چیزی ساخته یا تغییر نمی‌کند؛ دقیقاً همان مسیر ساخت را می‌ساید و می‌گوید هر محصول در کدام دروازه می‌ماند">🔍 اگر به صف اضافه نشد — تشخیص</button>
					<span class="gi-field" style="margin:0;display:inline-flex;align-items:center;gap:var(--gi-s2);"><span class="gi-field-label">نمونه</span><input type="number" id="gs-diag-count" value="10" min="1" max="100" style="width:80px;"></span>
					<span id="gs-diag-result" class="gi-inline-res" role="status" aria-live="polite"></span>
				</div>
				<div id="gs-diag-panel" hidden style="margin-top:var(--gi-s4);"></div>
			</div>

	</div>
</div>
<script>
(function () {
	function boot() {
		jQuery(function ($) {
			'use strict';
			var A = window.STI || {};
			var pollTimers = {};

			function esc(s) {
				return $('<div>').text(s == null ? '' : String(s)).html();
			}

			function post(action, data) {
				data = data || {};
				data.action = action;
				data.nonce = A.nonce;
				return $.post(A.ajaxUrl, data);
			}

			function progressText(c) {
				if (c.segments && c.segments.total_segments) {
					return esc(c.segments.messages_saved || 0) + ' پیام · ' + esc(c.segments.done_segments || 0) + '/' + esc(c.segments.total_segments || 0) + ' بخش کامل';
				}
				return esc(c.message_count || 0) + ' پیام · نقطه‌ی ادامه: ' + esc(c.last_scanned_message_id || 0);
			}

			function rowHtml(c) {
				return '' +
					'<tr data-id="' + c.id + '">' +
					'<td data-label="عنوان"><strong>' + esc(c.title || '—') + '</strong></td>' +
					'<td data-label="شناسه"><code dir="ltr" style="font-size:var(--gi-fs0);">' + esc(c.identifier) + '</code></td>' +
					'<td data-label="پیشرفت" class="gs-col-progress">' + progressText(c) + '</td>' +
					'<td data-label="وضعیت" class="gs-col-status"><span class="sti-badge">' + esc(c.scan_status) + '</span></td>' +
					'<td data-label="خطا" class="gs-col-error">' + esc(c.last_error || '—') + '</td>' +
					'<td data-label="عملیات" class="gs-col-actions">' +
					'<button class="gi-btn gi-btn--subtle gs-start" data-id="' + c.id + '">▶ شروع/ادامه</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-edit" data-id="' + c.id + '" data-identifier="' + esc(c.identifier) + '">✏️ ویرایش</button> ' +
					'<button class="gi-btn gi-btn--subtle gs-parallel" data-id="' + c.id + '">⚡ موازی</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-pause" data-id="' + c.id + '">⏸ توقف</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-delete" data-id="' + c.id + '" aria-label="حذف کانال">🗑</button>' +
					'</td></tr>';
			}

			function emptyRow() {
				return '<tr><td colspan="6">' +
					'<div class="gi-empty" style="padding:var(--gi-s7) var(--gi-s5);">' +
					'<div class="gi-empty-ico" aria-hidden="true">📡</div>' +
					'<div class="gi-empty-title">هنوز کانالی اضافه نشده.</div>' +
					'<div class="gi-empty-sub">کانال تلگرام اولت را بالای صفحه ثبت کن.</div></div></td></tr>';
			}

			function refreshList() {
				post('sti_gs_channel_list', {}).done(function (r) {
					if (!r || !r.success) { return; }
					var channels = r.data.channels || [];
					if (!channels.length) {
						$('#gs-channels').html(emptyRow());
						return;
					}
					var html = '';
					$.each(channels, function (_, c) { html += rowHtml(c); });
					$('#gs-channels').html(html);
					channels.forEach(function (c) {
						if ('running' === c.scan_status) { startPolling(c.id); }
					});
				});
			}

			function updateRow($row, c, segments) {
				if (segments) { c.segments = segments; }
				$row.find('.gs-col-progress').text(progressText(c));
				$row.find('.gs-col-status .sti-badge').text(c.scan_status);
				$row.find('.gs-col-error').text(c.last_error || '—');
			}

			function startPolling(id) {
				if (pollTimers[id]) { return; }
				pollTimers[id] = setInterval(function () {
					if (document.hidden) { return; }
					post('sti_gs_scan_poll', { channel_id: id }).done(function (r) {
						if (!r || !r.success) { return; }
						var c = r.data.channel;
						if (!c) { return; }
						c.message_count = r.data.message_count;
						var $row = $('#gs-channels tr[data-id="' + id + '"]');
						updateRow($row, c, r.data.segments);
						if ('running' !== c.scan_status) { stopPolling(id); }
					});
				}, 3000);
			}

			function stopPolling(id) {
				if (pollTimers[id]) {
					clearInterval(pollTimers[id]);
					delete pollTimers[id];
				}
			}

			$('#gs-add').on('click', function () {
				var $btn = $(this), $r = $('#gs-add-result');
				var identifier = $('#gs-identifier').val();
				if (!identifier) { $r.text('شناسه‌ی کانال را وارد کن.'); return; }
				$btn.prop('disabled', true);
				$r.text('در حال ثبت...');
				post('sti_gs_channel_add', { identifier: identifier }).done(function (r) {
					$r.text(r && r.success ? '✅ ' + r.data.message : '❌ ' + ((r.data && r.data.message) || 'خطا'));
					if (r && r.success) { $('#gs-identifier').val(''); refreshList(); }
				}).fail(function (xhr) {
					var raw = (xhr && xhr.responseText) ? xhr.responseText.slice(0, 300) : '';
					$r.text('❌ خطای ارتباط' + (raw ? ' — ' + raw : ''));
				}).always(function () {
					$btn.prop('disabled', false);
				});
			});

			/* ۱۰.۱۲ — ساخت محصول به «📦 صف انتشار» منتقل شد؛ اینجا فقط CTA است. */

			/* ۱۰.۱۱-UX+ — تشخیص ساخت (فقط‌خواندنی) */
			function diagRow(label, val, note) {
				return '<tr><td><strong>' + esc(label) + '</strong></td><td dir="ltr" style="font-weight:700;">' +
					(val === null || val === undefined ? '—' : Number(val)) +
					'</td><td>' + esc(note || '') + '</td></tr>';
			}

			function renderStartDiag(d) {
				var v = d.verdict || {}, sk = v.skipped || {}, cls = d.classification || {}, db = d.db || {}, sel = d.selection || {}, cr = d.cron || {}, g = d.gates || {};
				var expected = Number(v.created_expected) || 0;
				var h = '';

				/* ۱ — حکم */
				h += '<div style="padding:var(--gi-s4);border-radius:14px;border:1px solid var(--gi-border);background:' +
					(expected > 0 ? 'var(--gi-success-soft)' : 'var(--gi-danger-soft)') + ';">' +
					'<strong>' + (expected > 0 ? '✅ حکم: ' : '⛔ حکم: ') + '</strong>' +
					'از <b>' + (v.ready || 0) + '</b> آماده، <b>' + (v.eligible || 0) + '</b> واجدِ شرایط — در این اجرا تقریباً <b>' + expected +
					'</b> محصول به صف <u>اضافه خواهد</u> شد (فقط پیش‌بینی؛ هنوز چیزی ساخته نشده).' +
					(expected === 0 ? ' دروازه‌ی دقیقِ گیر در جدول زیر مشخص شده.' : '') + '</div>';

				/* ۲ — جدول دروازه‌ها */
				h += '<div class="gi-table-wrap" style="margin-top:var(--gi-s3);"><table class="gi-table gi-responsive"><thead><tr><th>دروازه</th><th>تعداد</th><th>توضیح</th></tr></thead><tbody>' +
					diagRow('READY — available', v.ready, 'profile_items با وضعیت available (همان عدد صف آماده)') +
					diagRow('ELIGIBLE — category معتبر', v.eligible, 'از فیلتر انتخاب create_sessions رد می‌شوند (default_category_id بزرگ‌تر از 0)') +
					diagRow('رد: بدون category', sk.no_category, 'پروفایل بدون دسته‌بندی پیش‌فرض — هرگز نمی‌سازند') +
					diagRow('رد: message_pk یتیم', sk.message_missing, 'پیام در جدول messages نیست → sti_gs_no_item') +
					diagRow('رد: Session قبلی برای همان پیام', sk.existing_session, 'محافظت تکراری — بی‌ضرر، ولی چیزی تازه ساخته نمی‌شود') +
					diagRow('قابلِ درج (کل)', cls.would_insert_total, 'JOIN سالم + Session قبلی ندارد') +
					'</tbody></table></div>';

				/* ۳ — dry-run ۲۰ مورد اول */
				var cands = d.candidates || [];
				if (cands.length) {
					h += '<p class="gi-card-sub" style="margin-top:var(--gi-s3);">Dry-runِ <b>' + cands.length + '</b> مورد اول — همان ترتیبی که Start امتحان می‌کند:</p>';
					h += '<div class="gi-table-wrap"><table class="gi-table gi-responsive"><thead><tr><th>profile_item</th><th>profile_id</th><th>message_pk</th><th>channel</th><th>file_code</th><th>JOIN</th><th>Session قبلی</th><th>skip_reason</th></tr></thead><tbody>';
					cands.forEach(function (c) {
						h += '<tr>' +
							'<td dir="ltr">' + (c.profile_item_id == null ? '—' : c.profile_item_id) + '</td>' +
							'<td dir="ltr">' + (c.profile_id == null ? '—' : c.profile_id) + '</td>' +
							'<td dir="ltr">' + (c.message_pk == null ? '—' : c.message_pk) + '</td>' +
							'<td dir="ltr">' + (c.channel_id == null ? '—' : c.channel_id) + '</td>' +
							'<td dir="ltr"><code style="font-size:var(--gi-fs0);">' + esc(c.file_code || '—') + '</code></td>' +
							'<td>' + (c.join_ok ? '<span class="gi-badge gi-badge--success">✔ سالم</span>' : '<span class="gi-badge gi-badge--danger">✘ شکست</span>') + '</td>' +
							'<td dir="ltr">' + (c.existing_session != null ? '#' + c.existing_session : '—') + '</td>' +
							'<td>' + (c.skip_reason ? '<code dir="ltr" style="font-size:var(--gi-fs0);">' + esc(c.skip_reason) + '</code>' : '→ قرار است درج شود') + '</td>' +
							'</tr>';
					});
					h += '</tbody></table></div>';
				}

				/* ۴ — SQL انتخاب (دقیق) */
				h += '<p class="gi-card-sub" style="margin-top:var(--gi-s3);">همان کوئری انتخابِ create_sessions — سطرهای برگشتی برای این اجرا: <b>' + (sel.rows_returned || 0) + '</b>:</p>';
				h += '<pre dir="ltr" style="text-align:left;padding:var(--gi-s3);border-radius:10px;border:1px solid var(--gi-border);background:var(--gi-surface-sunken);font-size:var(--gi-fs0);overflow-x:auto;white-space:pre-wrap;word-break:break-all;">' + esc(sel.sql || '—') + '</pre>';

				/* ۵ — جدول مقصد و ساختار */
				h += '<p class="gi-card-sub" style="margin-top:var(--gi-s3);">جدولِ مقصدِ درج: <code dir="ltr">' + esc(db.target_table || '—') + '</code> (' + (db.target_rows == null ? '—' : db.target_rows) +
					' سطر) · aliasِ sessions_table: ' + (db.alias_is_same ? '<span class="gi-badge gi-badge--success">همان جدول</span>' : '<span class="gi-badge gi-badge--warning">' + esc(db.alias_sessions_tbl) + '</span>') +
					' · جدول‌های فیزیکی: ' + (db.physical && db.physical.sti_gs_pipeline_items ? 'pipeline_items ✔' : 'pipeline_items ✘') + ' / ' + (db.physical && db.physical.sti_gs_sessions ? 'sessions ✔' : 'sessions ✘') +
					' · جدول messages: ' + (db.messages_rows == null ? '—' : db.messages_rows) + ' سطر' +
					' · ستون‌های درج: ' + ((db.missing_columns && db.missing_columns.length) ? '<span class="gi-badge gi-badge--danger">گمشده: ' + db.missing_columns.map(esc).join(', ') + '</span>' : '<span class="gi-badge gi-badge--success">همه ✔</span>') +
					' · UNIQUE message_pk: ' + (db.unique_message_pk ? '<span class="gi-badge gi-badge--success">✔</span>' : '<span class="gi-badge gi-badge--danger">✘</span>') +
					(db.halted ? ' · <span class="gi-badge gi-badge--danger">HALT فعال: ' + esc(db.halt_reason) + '</span>' : '') +
					'</p>';

				/* ۶ — Cron */
				if (cr.sti_gs_scan_worker === undefined) {
					h += '<p class="gi-card-sub" style="margin-top:var(--gi-s3);">Cron: <code dir="ltr">wp_next_scheduled</code> — scan_worker: —' + (cr.note ? ' · ' + esc(cr.note) : '') + '</p>';
				} else {
					var sw = cr.sti_gs_scan_worker;
					h += '<p class="gi-card-sub" style="margin-top:var(--gi-s3);">Cron — scan_worker: ' + (sw ? '<span class="gi-badge gi-badge--success">جدول‌بندی‌شده (' + esc(sw.in) + ' دیگر)</span>' : '<span class="gi-badge gi-badge--info">جدول‌بندی‌نشده</span>') +
						' · auto_worker: ' + (cr.sti_gs_auto_worker ? 'جدول‌بندی‌شده' : 'جدول‌بندی‌نشده') +
						' · ' + esc(cr.note || '') + '</p>';
				}

				/* ۷ — وضعیت خط تولید */
				var workerTxt = g.worker_enabled === true
					? '<span class="gi-badge gi-badge--success">روشن</span>'
					: (g.worker_enabled === false ? '<span class="gi-badge gi-badge--warning">خاموش</span>' : '<span class="gi-badge gi-badge--info">نامشخص</span>');
				h += '<p class="gi-card-sub" style="margin-top:var(--gi-s3);">حالا: Auto-Worker ' + workerTxt + ' · وضعیت خط: ' + esc(g.line_state || '—') + '</p>';

				var $p = $('#gs-diag-panel');
				$p.html(h).prop('hidden', false);
			}

			$('#gs-start-diagnose').on('click', function () {
				var $btn = $(this), $r = $('#gs-diag-result');
				var count = parseInt($('#gs-diag-count').val(), 10) || 0;
				if (count < 1 || count > 100) { $r.text('تعداد باید بین ۱ و ۱۰۰ باشد.'); return; }
				$btn.prop('disabled', true);
				$r.text('در حال ساید کردن مسیر ساخت (فقط‌خواندنی؛ معمولاً چند ثانیه)...');
				post('sti_gs_start_diagnostic', { count: count }).done(function (res) {
					if (res && res.success) {
						renderStartDiag(res.data);
						$r.text('✅ تشخیص تمام شد — گزارش بالای همین کارت.').addClass('ok');
					} else {
						$r.text('❌ ' + ((res.data && res.data.message) || 'خطا')).addClass('err');
					}
				}).fail(function () {
					$r.text('❌ خطای ارتباط').addClass('err');
				}).always(function () {
					$btn.prop('disabled', false);
				});
			});

			$('#gs-refresh').on('click', refreshList);

			$(document).on('click', '.gs-start', function () {
				var id = $(this).data('id');
				post('sti_gs_scan_start', { channel_id: id }).done(function (r) {
					if (r && r.success) {
						var $row = $('#gs-channels tr[data-id="' + id + '"]');
						updateRow($row, r.data.channel);
						startPolling(id);
					}
				});
			});

			$(document).on('click', '.gs-parallel', function () {
				var id = $(this).data('id');
				var n = window.prompt('تعداد بخش‌های موازی (۱ تا ۱۰)؟ برای کانال‌های خیلی بزرگ ۵ تا ۸ پیشنهاد می‌شود.', '5');
				if (!n) { return; }
				n = parseInt(n, 10);
				if (!n || n < 1) { n = 5; }
				post('sti_gs_scan_start_parallel', { channel_id: id, segments: n }).done(function (r) {
					if (r && r.success) {
						var $row = $('#gs-channels tr[data-id="' + id + '"]');
						updateRow($row, r.data.channel, r.data.segments);
						startPolling(id);
					} else {
						window.alert((r.data && r.data.message) || 'خطا در شروع اسکن موازی');
					}
				});
			});

			$(document).on('click', '.gs-pause', function () {
				var id = $(this).data('id');
				post('sti_gs_scan_pause', { channel_id: id }).done(function (r) {
					if (r && r.success) {
						var $row = $('#gs-channels tr[data-id="' + id + '"]');
						updateRow($row, r.data.channel);
					}
					stopPolling(id);
				});
			});

			$(document).on('click', '.gs-edit', function () {
				var $btn = $(this), id = $btn.data('id');
				var current = $btn.data('identifier');
				var next = window.prompt('شناسه‌ی درست کانال را وارد کن:', current);
				if (!next || next === current) { return; }
				post('sti_gs_channel_update_identifier', { id: id, identifier: next }).done(function (r) {
					if (r && r.success) {
						refreshList();
					} else {
						window.alert((r.data && r.data.message) || 'خطا در ویرایش شناسه');
					}
				});
			});

			$(document).on('click', '.gs-delete', function () {
				var id = $(this).data('id');
				if (!window.confirm('کانال و تمام پیام‌های اسکن‌شده‌ی آن حذف شود؟')) { return; }
				stopPolling(id);
				post('sti_gs_channel_delete', { id: id }).done(function () { refreshList(); });
			});

			// شروع polling برای کانال‌هایی که از قبل در حال اسکن بودند.
			$('#gs-channels tr[data-id]').each(function () {
				var status = $(this).find('.gs-col-status .sti-badge').text().trim();
				if ('running' === status) { startPolling($(this).data('id')); }
			});
		});
	}
	if (window.jQuery) { boot(); } else {
		var t = setInterval(function () { if (window.jQuery) { clearInterval(t); boot(); } }, 50);
	}
})();
</script>
