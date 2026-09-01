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
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include dirname( __DIR__ ) . '/partials-tabs.php'; ?>
		<div class="sti-content">
			<div class="sti-header">
				<h1><span class="dashicons dashicons-search"></span> گلدن اسکن | فاز ۱: اسکن کانال</h1>
			</div>
			<?php include __DIR__ . '/partial-subnav.php'; ?>

			<div class="sti-info-box" style="margin-bottom:18px;">
				<strong>این مرحله فقط ایندکس می‌کند، چیزی دانلود یا منتشر نمی‌شود.</strong>
				<p style="margin:7px 0 0;">برای کانال‌های بزرگ از «⚡ اسکن موازی» استفاده کن: بازه‌ی شناسه‌ی پیام‌ها به چند بخش تقسیم می‌شود و هم‌زمان جلو می‌روند — چند برابر سریع‌تر از حالت ساده، و بدون خطر ذخیره‌ی تکراری (هر پیام با شناسه‌ی یکتا فقط یک‌بار ثبت می‌شود، حتی اگر دو بخش هم‌مرز به آن برسند). اسکن در هر دو حالت قابل توقف/ادامه است.</p>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>➕ افزودن کانال</h2><p>یوزرنیم، لینک t.me یا لینک دعوت خصوصی را وارد کن.</p></div></div>
				<div class="sti-form-row">
					<div class="sti-field" style="grid-column:span 2;">
						<label>شناسه‌ی کانال</label>
						<input id="gs-identifier" dir="ltr" placeholder="@ChannelName یا https://t.me/ChannelName">
					</div>
				</div>
				<div class="sti-form-actions">
					<button id="gs-add" class="sti-btn">➕ ثبت کانال</button>
					<span id="gs-add-result" class="sti-inline-result"></span>
				</div>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>📡 کانال‌های ثبت‌شده</h2><p>وضعیت اسکن هر کانال زنده به‌روزرسانی می‌شود (هر ۳ ثانیه).</p></div><button id="gs-refresh" class="sti-btn secondary">🔄 بروزرسانی</button></div>
				<div class="sti-table-wrap">
					<table class="sti-table widefat">
						<thead>
							<tr>
								<th>عنوان</th>
								<th>شناسه</th>
								<th>پیشرفت</th>
								<th>وضعیت</th>
								<th>خطا</th>
								<th>عملیات</th>
							</tr>
						</thead>
						<tbody id="gs-channels">
							<?php if ( empty( $channels ) ) : ?>
								<tr><td colspan="6" class="sti-empty">هنوز کانالی ثبت نشده.</td></tr>
							<?php else : ?>
								<?php foreach ( $channels as $c ) : ?>
									<?php
									$progress_html = (int) $c['message_count'] . ' پیام · نقطه‌ی ادامه: ' . (int) $c['last_scanned_message_id'];
									if ( ! empty( $c['segments'] ) ) {
										$s = $c['segments'];
										$progress_html = (int) $s['messages_saved'] . ' پیام · ' . (int) $s['done_segments'] . '/' . (int) $s['total_segments'] . ' بخش کامل';
									}
									?>
									<tr data-id="<?php echo (int) $c['id']; ?>">
										<td><strong><?php echo esc_html( $c['title'] ?: '—' ); ?></strong></td>
										<td><code dir="ltr"><?php echo esc_html( $c['identifier'] ); ?></code></td>
										<td class="gs-col-progress"><?php echo esc_html( $progress_html ); ?></td>
										<td class="gs-col-status"><span class="sti-badge"><?php echo esc_html( $c['scan_status'] ); ?></span></td>
										<td class="gs-col-error"><?php echo esc_html( $c['last_error'] ?: '—' ); ?></td>
										<td class="gs-col-actions">
											<button class="sti-btn-sm gs-start" data-id="<?php echo (int) $c['id']; ?>">▶ شروع/ادامه</button>
										<button class="sti-btn-sm secondary gs-edit" data-id="<?php echo (int) $c['id']; ?>" data-identifier="<?php echo esc_attr( $c['identifier'] ); ?>">✏️ ویرایش</button>
											<button class="sti-btn-sm gs-parallel" data-id="<?php echo (int) $c['id']; ?>">⚡ موازی</button>
											<button class="sti-btn-sm secondary gs-pause" data-id="<?php echo (int) $c['id']; ?>">⏸ توقف</button>
											<button class="sti-btn-sm danger gs-delete" data-id="<?php echo (int) $c['id']; ?>">🗑</button>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
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
					'<td><strong>' + esc(c.title || '—') + '</strong></td>' +
					'<td><code dir="ltr">' + esc(c.identifier) + '</code></td>' +
					'<td class="gs-col-progress">' + progressText(c) + '</td>' +
					'<td class="gs-col-status"><span class="sti-badge">' + esc(c.scan_status) + '</span></td>' +
					'<td class="gs-col-error">' + esc(c.last_error || '—') + '</td>' +
					'<td class="gs-col-actions">' +
					'<button class="sti-btn-sm gs-start" data-id="' + c.id + '">▶ شروع/ادامه</button> ' +
					'<button class="sti-btn-sm secondary gs-edit" data-id="' + c.id + '" data-identifier="' + esc(c.identifier) + '">✏️ ویرایش</button> ' +
					'<button class="sti-btn-sm gs-parallel" data-id="' + c.id + '">⚡ موازی</button> ' +
					'<button class="sti-btn-sm secondary gs-pause" data-id="' + c.id + '">⏸ توقف</button> ' +
					'<button class="sti-btn-sm danger gs-delete" data-id="' + c.id + '">🗑</button>' +
					'</td></tr>';
			}

			function refreshList() {
				post('sti_gs_channel_list', {}).done(function (r) {
					if (!r || !r.success) { return; }
					var channels = r.data.channels || [];
					if (!channels.length) {
						$('#gs-channels').html('<tr><td colspan="6" class="sti-empty">هنوز کانالی ثبت نشده.</td></tr>');
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
