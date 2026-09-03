<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

STI_GS_DB::install();
STI_GS_Session_Ajax::instance();
$gs_channels = STI_GS_Channel::all( 100 );
?>
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">🏭 Session ها</h1>
		<p class="gi-h1-sub">فقط تشخیص دکمه — هیچ کلیک، دانلود یا ساخت محصولی خودکار انجام نمی‌شود. روی هر Session «▶ ادامه پردازش» را بزن؛ از هر مرحله‌ای که مانده باشد خودش تا آخر می‌رود و اگر خطا بخورد، همان دکمه تبدیل به «🔄 تلاش مجدد» می‌شود. همه‌ی جزئیات در Artifact/Event ثبت می‌شود (دکمه‌ی 👁).</p>
	</div>

	<div class="gi-bento">

		<!-- Bot Inbox maintenance -->
		<div class="gi-card gi-span-7">
			<div class="gi-card-head">
				<h2 class="gi-card-title">🧹 نگهداری Bot Inbox</h2>
				<span class="gi-card-sub">Duplicate Protection — سه قدم جدا؛ هیچ‌کدام خودکار اجرا نمی‌شود</span>
			</div>
			<div class="gi-flex" style="align-items:flex-start;flex-wrap:wrap;">
				<button id="gs-dup-report" class="gi-btn gi-btn--subtle">۱) 🔍 گزارش تکراری‌ها</button>
				<button id="gs-dup-merge" class="gi-btn gi-btn--subtle">۲) 🧹 ادغام امن تکراری‌ها</button>
				<button id="gs-dup-enforce" class="gi-btn gi-btn--primary">۳) 🔒 فعال‌سازی محدودیت یکتای دیتابیسی</button>
			</div>
			<div id="gs-dup-result" class="gi-mt-5"></div>
		</div>

		<!-- Info -->
		<div class="gi-card gi-span-5">
			<div class="gi-card-head"><h2 class="gi-card-title">ℹ️ راهنمای Session</h2></div>
			<p class="gi-card-sub" style="font-size:var(--gi-fs1);">Sessionها از تب «پروفایل‌ها» (دکمه‌ی ➕ صف روی هر نمونه) ساخته می‌شوند. مرحله‌های تکی زیر «🔧 ابزار توسعه» فقط برای دیباگ‌اند؛ در کار روزمره فقط «ادامه پردازش» کافی است.</p>
		</div>

		<!-- Sessions table -->
		<div class="gi-card gi-card--flush gi-span-12">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) var(--gi-s3);">
				<div>
					<h2 class="gi-card-title">🗂 Session ها</h2>
					<span class="gi-card-sub">فیلتر بر اساس کانال + بروزرسانی</span>
				</div>
				<div class="gi-flex" style="gap:var(--gi-s2);">
					<select id="gs-s-channel" dir="ltr" aria-label="فیلتر کانال">
						<option value="">همه‌ی کانال‌ها</option>
						<?php foreach ( $gs_channels as $c ) : ?>
							<option value="<?php echo (int) $c['id']; ?>"><?php echo esc_html( $c['title'] ?: $c['identifier'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button id="gs-s-refresh" class="gi-btn gi-btn--subtle">⟳ بروزرسانی</button>
				</div>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table gi-responsive">
					<thead>
						<tr>
							<th scope="col">کد فایل</th>
							<th scope="col">وضعیت (State)</th>
							<th scope="col">نوع دکمه</th>
							<th scope="col">اطمینان</th>
							<th scope="col">خطا</th>
							<th scope="col">عملیات</th>
						</tr>
					</thead>
					<tbody id="gs-sessions"><tr><td colspan="6" class="sti-empty"><span class="gi-skel" style="display:inline-block;width:160px;height:14px;margin:0 auto;"></span> در حال بارگذاری…</td></tr></tbody>
				</table>
			</div>
			<div id="gs-s-trace" class="gi-mt-5"></div>
		</div>

	</div>
</div>
<script>
(function () {
	function boot() {
		jQuery(function ($) {
			'use strict';
			var A = window.STI || {};

			function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
			function post(action, data) { data = data || {}; data.action = action; data.nonce = A.nonce; return $.post(A.ajaxUrl, data); }

			/** ستون خطا را به‌روزرسانی می‌کند؛ اگر Session به STORED رسیده، به‌جای خطا لینک عمومی («📦 Storage Info») نشان می‌دهد. */
			function updateStatusCell( $row, s ) {
				$row.find('.gs-s-state .sti-badge').attr('class', 'sti-badge ' + (STATE_BADGE[s.state] || '')).text(s.state);
				if (LINK_STATES[s.state] && s.storage_url) {
					var extra = s.product_id ? (' — محصول #' + esc(s.product_id)) : '';
					$row.find('.gs-s-error').html('<a href="' + esc(s.storage_url) + '" target="_blank" rel="noopener">📦 ' + esc(s.storage_url) + '</a>' + extra);
				} else {
					$row.find('.gs-s-error').text(s.error_reason || '—');
				}
			}

			var STATE_BADGE = {
				SCANNED: '', BUTTON_FOUND: 'ok', ERROR_BUTTON: 'error', NEEDS_REVIEW: 'warn',
				WAITING_BOT: '', BOT_RESPONSE: 'ok', ERROR_MATCH: 'error', FILE_MATCHED: 'ok',
				DOWNLOAD_PENDING: '', DOWNLOADING: '', DOWNLOADED: 'ok', STORED: 'ok', DOWNLOAD_FAILED: 'error',
				MEDIA_BUILDING: '', MEDIA_READY: 'ok', MEDIA_FAILED: 'error',
				PRODUCT_BUILDING: '', PRODUCT_READY: 'ok', PRODUCT_FAILED: 'error', REVIEW_READY: 'ok'
			};

			var LINK_STATES = { STORED: 1, MEDIA_READY: 1, PRODUCT_BUILDING: 1, PRODUCT_READY: 1, REVIEW_READY: 1 };

			function row(s) {
				var cls = STATE_BADGE[s.state] || '';
				var isFailed = /FAILED|ERROR/.test(String(s.state || ''));
				var errorCell = (LINK_STATES[s.state] && s.storage_url)
					? '<a href="' + esc(s.storage_url) + '" target="_blank" rel="noopener">📦 ' + esc(s.storage_url) + '</a>'
					: esc(s.error_reason || '—');
				return '' +
					'<tr data-id="' + s.id + '">' +
					'<td data-label="کد فایل"><code dir="ltr" style="font-size:var(--gi-fs0);">' + esc(s.file_code || '—') + '</code></td>' +
					'<td data-label="وضعیت" class="gs-s-state"><span class="sti-badge ' + cls + '">' + esc(s.state) + '</span></td>' +
					'<td data-label="نوع دکمه" class="gs-s-method">' + esc(s.button_resolution_method || '—') + '</td>' +
					'<td data-label="اطمینان" class="gs-s-conf gi-nums">' + (s.button_confidence != null ? esc(s.button_confidence) + '٪' : '—') + '</td>' +
					'<td data-label="خطا" class="gs-s-error">' + errorCell + '</td>' +
					'<td data-label="عملیات">' +
					// دکمه‌ی اصلی: از هرجا مانده ادامه بده. همان Auto Pipeline
					// است، فقط با نامی که کاربر بفهمد. برای Sessionهای خطادار
					// همان دکمه نقش «تلاش مجدد» را دارد چون از State فعلی
					// شروع می‌کند.
					'<button class="gi-btn gi-btn--subtle gs-s-auto" data-id="' + s.id + '">' + (isFailed ? '🔄 تلاش مجدد' : '▶ ادامه پردازش') + '</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-s-trace" data-id="' + s.id + '">👁 جزئیات</button> ' +
					// ابزارهای مرحله‌به‌مرحله فقط برای دیباگ. جمع‌شده‌اند تا
					// در کار روزمره کسی به‌اشتباه یک مرحله را دوباره نزند.
					'<details class="gs-devtools">' +
					'<summary>🔧 ابزار توسعه</summary>' +
					'<div class="gs-devtools-inner">' +
					'<button class="gi-btn gi-btn--ghost gs-s-resolve" data-id="' + s.id + '">▶ Resolve Button</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-s-execute" data-id="' + s.id + '">⚡ Execute Action</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-s-poll" data-id="' + s.id + '">🔍 Poll Bot</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-s-diag" data-id="' + s.id + '">🩺 Diagnostic</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-s-match" data-id="' + s.id + '">🎯 Match File</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-s-download" data-id="' + s.id + '">⬇ Download</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-s-media" data-id="' + s.id + '">🖼 Build Media</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-s-product" data-id="' + s.id + '">📦 Build Product</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-s-validate" data-id="' + s.id + '">✅ Validate</button> ' +
					'</div></details>' +
					'</td></tr>';
			}

			function emptySessions() {
				return '<tr><td colspan="6">' +
					'<div class="gi-empty" style="padding:var(--gi-s6) var(--gi-s4);">' +
					'<div class="gi-empty-ico" aria-hidden="true">🗂</div>' +
					'<div class="gi-empty-title">هنوز Session‌ای صف نشده.</div>' +
					'<div class="gi-empty-sub">از تب «پروفایل‌ها» چند مورد را ➕ صف کن تا اینجا ظاهر شوند.</div></div></td></tr>';
			}

			function loadSessions() {
				post('sti_gs_session_list', { channel_id: $('#gs-s-channel').val() }).done(function (r) {
					if (!r || !r.success) { return; }
					var sessions = r.data.sessions || [];
					if (!sessions.length) {
						$('#gs-sessions').html(emptySessions());
						return;
					}
					var html = '';
					$.each(sessions, function (_, s) { html += row(s); });
					$('#gs-sessions').html(html);
				});
			}

			$('#gs-s-refresh, #gs-s-channel').on('click change', loadSessions);

			$(document).on('click', '.gs-s-resolve', function () {
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('در حال تشخیص...');
				post('sti_gs_session_resolve_button', { session_id: id }).done(function (r) {
					if (r && r.success) {
						var $row = $('#gs-sessions tr[data-id="' + id + '"]');
						var s = r.data.session;
						updateStatusCell($row, s);
						$row.find('.gs-s-method').text(s.button_resolution_method || '—');
						$row.find('.gs-s-conf').text(s.button_confidence != null ? s.button_confidence + '٪' : '—');
					} else {
						window.alert((r.data && r.data.message) || 'خطا');
					}
				}).always(function () {
					$btn.prop('disabled', false).text('▶ Resolve Button');
				});
			});

			$(document).on('click', '.gs-s-execute', function () {
				var $btn = $(this), id = $btn.data('id');
				if (!window.confirm('این دکمه واقعاً روی تلگرام زده می‌شود. مطمئنی؟')) { return; }
				$btn.prop('disabled', true).text('در حال اجرا...');
				post('sti_gs_session_execute_action', { session_id: id }).done(function (r) {
					var s = (r && r.data && r.data.session) || null;
					if (s) {
						var $row = $('#gs-sessions tr[data-id="' + id + '"]');
						updateStatusCell($row, s);
					}
					if (!r || !r.success) {
						window.alert((r.data && r.data.message) || 'خطا در اجرای Action');
					}
				}).always(function () {
					$btn.prop('disabled', false).text('⚡ Execute Action');
				});
			});

			$(document).on('click', '.gs-s-poll', function () {
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('در حال Poll...');
				post('sti_gs_session_poll_bot', { session_id: id }).done(function (r) {
					var s = (r && r.data && r.data.session) || null;
					if (s) {
						var $row = $('#gs-sessions tr[data-id="' + id + '"]');
						updateStatusCell($row, s);
					}
					if (!r || !r.success) {
						window.alert((r.data && r.data.message) || 'خطا در Poll');
					}
				}).always(function () {
					$btn.prop('disabled', false).text('🔍 Poll Bot');
				});
			});

			$(document).on('click', '.gs-s-match', function () {
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('در حال تطبیق...');
				post('sti_gs_session_match_file', { session_id: id }).done(function (r) {
					var s = (r && r.data && r.data.session) || null;
					if (s) {
						var $row = $('#gs-sessions tr[data-id="' + id + '"]');
						updateStatusCell($row, s);
					}
					if (!r || !r.success) {
						window.alert((r.data && r.data.message) || 'خطا در تطبیق فایل');
					}
				}).always(function () {
					$btn.prop('disabled', false).text('🎯 Match File');
				});
			});

			$(document).on('click', '.gs-s-download', function () {
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('در حال دانلود...');
				post('sti_gs_session_download', { session_id: id }).done(function (r) {
					var s = (r && r.data && r.data.session) || null;
					if (s) {
						var $row = $('#gs-sessions tr[data-id="' + id + '"]');
						updateStatusCell($row, s);
					}
					if (!r || !r.success) {
						window.alert((r.data && r.data.message) || 'خطا در دانلود/ذخیره‌سازی');
					}
				}).always(function () {
					$btn.prop('disabled', false).text('⬇ Download');
				});
			});

			$(document).on('click', '.gs-s-media', function () {
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('در حال جست‌وجوی عکس...');
				post('sti_gs_session_build_media', { session_id: id }).done(function (r) {
					var s = (r && r.data && r.data.session) || null;
					if (s) { updateStatusCell($('#gs-sessions tr[data-id="' + id + '"]'), s); }
					if (!r || !r.success) { window.alert((r.data && r.data.message) || 'خطا در Build Media'); }
				}).always(function () { $btn.prop('disabled', false).text('🖼 Build Media'); });
			});

			$(document).on('click', '.gs-s-product', function () {
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('در حال ساخت محصول...');
				post('sti_gs_session_build_product', { session_id: id }).done(function (r) {
					var s = (r && r.data && r.data.session) || null;
					if (s) { updateStatusCell($('#gs-sessions tr[data-id="' + id + '"]'), s); }
					if (!r || !r.success) { window.alert((r.data && r.data.message) || 'خطا در Build Product'); }
				}).always(function () { $btn.prop('disabled', false).text('📦 Build Product'); });
			});

			$(document).on('click', '.gs-s-validate', function () {
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('در حال بررسی...');
				post('sti_gs_session_validate', { session_id: id }).done(function (r) {
					var s = (r && r.data && r.data.session) || null;
					if (s) { updateStatusCell($('#gs-sessions tr[data-id="' + id + '"]'), s); }
					if (!r || !r.success) { window.alert((r.data && r.data.message) || 'خطا در Validate'); }
				}).always(function () { $btn.prop('disabled', false).text('✅ Validate'); });
			});

			$(document).on('click', '.gs-s-auto', function () {
				// هر مرحله یک درخواست جداگانه است. سرور هرگز بیش از یک
				// مرحله در یک درخواست اجرا نمی‌کند، پس هیچ درخواستی به سقف
				// زمان وب‌سرور نمی‌خورد. زنجیره را مرورگر پیش می‌برد.
				var originalLabel = $(this).text();
				var $btn = $(this), id = $btn.data('id');
				var $row = $('#gs-sessions tr[data-id="' + id + '"]');
				var guard = 0;

				function finish(msg) {
					$btn.prop('disabled', false).text(originalLabel);
					if (msg) { window.alert(msg); }
					load();
				}

				var waits = 0;
				var retries = 0;      // تلاش دوباره پس از خطای موقت سرور
				var MAX_WAITS = 14;   // با backoff، حدود ۲.۵ دقیقه انتظار برای ربات

				function step() {
					if (++guard > 25) { return finish('سقف مراحل پر شد — دوباره «ادامه پردازش» را بزنید.'); }

					post('sti_gs_session_auto_pipeline', { session_id: id }).done(function (r) {
						var d = (r && r.data) || {};
						if (d.session) { updateStatusCell($row, d.session); }

						if (!r || !r.success) { return finish(d.message || 'Pipeline متوقف شد'); }

						// ربات هنوز جواب نداده: صبر کن و دوباره بزن، نه اینکه
						// کاربر مجبور شود دستی «Poll Bot» بزند.
						if (d.waiting) {
							if (++waits > MAX_WAITS) {
								return finish(
									'ربات پس از حدود ' + Math.round(MAX_WAITS * 11 / 60) + ' دقیقه انتظار فایلی نفرستاد.\n\n' +
									'وضعیت فعلی: ' + (d.to || d.from) + '\n' +
									'محتمل‌ترین علت‌ها: ربات به این کد فایلی ندارد، یا deep link منقضی شده، ' +
									'یا اکانت تلگرام به ربات دسترسی ندارد.\n\n' +
									'برای جزئیات دقیق «👁 جزئیات» را باز کنید و Artifact بخش global_poll را ببینید.'
								);
							}
							// Backoff ملایم: هر تلاش یک global_poll واقعی روی
							// MTProto است، پس فاصله‌ها کم‌کم بازتر می‌شود.
							var delay = Math.min(15, (d.retry_after || 6) + waits);
							$btn.text('منتظر پاسخ ربات… (' + waits + '/' + MAX_WAITS + ')');
							return window.setTimeout(step, delay * 1000);
						}

						waits = 0;
						retries = 0;
						$btn.text((d.stage || 'در حال اجرا') + '… (' + guard + ')');

						if (d.done) { return finish(d.to === 'REVIEW_READY' ? null : d.message); }
						step();
					}).fail(function (xhr) {
						/**
						 * خطای موقت سرور نباید کار را به کاربر برگرداند.
						 *
						 * 503/504/502/429 و قطعی شبکه (status 0) همگی گذرا
						 * هستند — همان چیزی که باعث شد یک بار دستی «تلاش
						 * مجدد» بزنید. حالا خودش تا سه بار با فاصله‌ی
						 * فزاینده دوباره امتحان می‌کند و فقط بعد از آن
						 * تسلیم می‌شود.
						 */
						var code = (xhr && xhr.status) || 0;
						var transient = (code === 0 || code === 429 || code === 502 || code === 503 || code === 504);

						if (transient && retries < 3) {
							retries++;
							var wait = retries * 5;
							$btn.text('خطای موقت — تلاش دوباره تا ' + wait + 'ث (' + retries + '/3)');
							return window.setTimeout(step, wait * 1000);
						}

						finish(transient
							? 'سرور بعد از ۳ تلاش پاسخ نداد (HTTP ' + code + '). چند دقیقه بعد «ادامه پردازش» را بزنید.'
							: 'درخواست ناتمام ماند (HTTP ' + code + '). تب «گزارش‌ها» را ببینید.');
					});
				}

				$btn.prop('disabled', true).text('در حال اجرا...');
				step();
			});

			$(document).on('click', '.gs-s-diag', function () {
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('در حال بررسی...');
				post('sti_gs_session_diagnostic', { session_id: id }).done(function (r) {
					if (r && r.success) {
						var d = r.data.result, html = '<div class="gi-card"><div class="gi-card-head" style="padding:var(--gi-s4) var(--gi-s4) 0;"><h2 class="gi-card-title">🩺 Diagnostic — peer: ' + esc(d.peer) + '</h2><span class="gi-card-sub">' + esc(d.messages_total_in_history) + ' پیام در تاریخچه</span></div>';
						html += '<div class="gi-table-wrap" style="border:none;border-radius:0;"><table class="gi-table"><thead><tr><th>msg_id</th><th>date</th><th>type</th><th>file_name</th></tr></thead><tbody>';
						$.each(d.messages, function (_, m) {
							html += '<tr><td dir="ltr">' + esc(m.msg_id) + '</td><td dir="ltr">' + esc(m.date) + '</td><td>' + esc(m.type) + '</td><td>' + esc(m.file_name) + '</td></tr>';
						});
						html += '</tbody></table></div></div>';
						$('#gs-s-trace').html(html);
					} else {
						window.alert((r.data && r.data.message) || 'خطا در Diagnostic');
					}
				}).always(function () {
					$btn.prop('disabled', false).text('🩺 Diagnostic');
				});
			});

			$(document).on('click', '.gs-s-trace', function () {
				var id = $(this).data('id');
				post('sti_gs_session_trace', { session_id: id }).done(function (r) {
					if (!r || !r.success) { return; }
					var html = '<div class="gi-card"><div class="gi-card-head" style="padding:var(--gi-s4) var(--gi-s4) 0;"><h2 class="gi-card-title">👁 جزئیات Session #' + id + '</h2></div>';

					html += '<h4 style="margin:var(--gi-s3) var(--gi-s4) var(--gi-s2);font-size:var(--gi-fs1);">Bot Candidates</h4>';
					if (!r.data.candidates || !r.data.candidates.length) {
						html += '<p class="sti-empty" style="margin-inline:var(--gi-s4);">هنوز candidate‌ای ساخته نشده.</p>';
					} else {
						html += '<div class="gi-table-wrap" style="border:none;border-radius:0;margin-inline:var(--gi-s4);"><table class="gi-table"><thead><tr><th>کد فایل</th><th>نام فایل</th><th>score</th><th>جزئیات امتیاز</th></tr></thead><tbody>';
						$.each(r.data.candidates, function (_, c) {
							html += '<tr><td dir="ltr">' + esc(c.candidate_file_code || '—') + '</td><td>' + esc(c.file_name || '—') + '</td><td><strong>' + esc(c.total_score) + '</strong></td>' +
								'<td dir="ltr">code:' + esc(c.score_file_code) + ' name:' + esc(c.score_file_name) + ' time:' + esc(c.score_time) + '</td></tr>';
						});
						html += '</tbody></table></div>';
					}

					html += '<h4 style="margin:var(--gi-s3) var(--gi-s4) var(--gi-s2);font-size:var(--gi-fs1);">Artifacts</h4>';
					if (!r.data.artifacts.length) {
						html += '<p class="sti-empty" style="margin-inline:var(--gi-s4);">هیچ artifact ثبت نشده.</p>';
					} else {
						$.each(r.data.artifacts, function (_, a) {
							html += '<div style="margin:0 var(--gi-s4) var(--gi-s3);"><strong>' + esc(a.type) + '</strong> — <code>' + esc(a.created_at) + '</code>' +
								'<pre class="gi-pre">' + esc(a.payload_json) + '</pre></div>';
						});
					}

					html += '<h4 style="margin:var(--gi-s3) var(--gi-s4) var(--gi-s2);font-size:var(--gi-fs1);">Events</h4>';
					if (!r.data.events.length) {
						html += '<p class="sti-empty" style="margin-inline:var(--gi-s4);">هیچ رویدادی ثبت نشده.</p>';
					} else {
						html += '<div class="gi-table-wrap" style="border:none;border-radius:0;margin:0 var(--gi-s4) var(--gi-s4);"><table class="gi-table"><thead><tr><th>Stage</th><th>Result</th><th>Message</th><th>زمان</th></tr></thead><tbody>';
						$.each(r.data.events, function (_, e) {
							html += '<tr><td>' + esc(e.stage) + '</td><td>' + esc(e.result) + '</td><td>' + esc(e.message) + '</td><td dir="ltr">' + esc(e.created_at) + '</td></tr>';
						});
						html += '</tbody></table></div>';
					}

					html += '</div>';
					$('#gs-s-trace').html(html);
				});
			});

			$('#gs-dup-report').on('click', function () {
				var $btn = $(this);
				$btn.prop('disabled', true);
				post('sti_gs_inbox_dup_report', {}).done(function (r) {
					if (r && r.success) {
						$('#gs-dup-result').html('<pre class="gi-pre">' + esc(JSON.stringify(r.data, null, 2)) + '</pre>');
					} else {
						window.alert((r.data && r.data.message) || 'خطا در گزارش');
					}
				}).always(function () { $btn.prop('disabled', false); });
			});

			$('#gs-dup-merge').on('click', function () {
				if (!window.confirm('ردیف‌های تکراری inbox ادغام شوند؟ این عملیات ردیف‌های اضافه را حذف می‌کند (پس از گزارش، برگشت‌پذیر نیست).')) { return; }
				var $btn = $(this);
				$btn.prop('disabled', true);
				post('sti_gs_inbox_dup_merge', {}).done(function (r) {
					if (r && r.success) {
						$('#gs-dup-result').html('<pre class="gi-pre">' + esc(JSON.stringify(r.data, null, 2)) + '</pre>');
					} else {
						window.alert((r.data && r.data.message) || 'خطا در ادغام');
					}
				}).always(function () { $btn.prop('disabled', false); });
			});

			$('#gs-dup-enforce').on('click', function () {
				var $btn = $(this);
				$btn.prop('disabled', true);
				post('sti_gs_inbox_dup_enforce', {}).done(function (r) {
					$('#gs-dup-result').text(r && r.success ? '✅ محدودیت یکتا فعال شد.' : '❌ ' + ((r.data && r.data.message) || 'خطا'));
				}).always(function () { $btn.prop('disabled', false); });
			});

			loadSessions();
		});
	}
	if (window.jQuery) { boot(); } else {
		var t = setInterval(function () { if (window.jQuery) { clearInterval(t); boot(); } }, 50);
	}
})();
</script>
