<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

STI_Channel_Import::instance()->pump_workers_inline( 15, 1 );

$categories      = STI_Category::get_all();
$mtproto_configured = STI_MTProto::is_configured();
$mtproto_state      = STI_MTProto::instance()->auth_state();
$mtproto_ready      = $mtproto_configured && 'logged_in' === $mtproto_state;

$active_count = 0;
foreach ( STI_Channel_Import::instance()->get_batches() as $b ) {
	if ( in_array( $b['status'], array( 'queued', 'running' ), true ) ) {
		$active_count++;
	}
}
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">

			<div class="sti-header">
				<h1><span class="dashicons dashicons-download"></span> Channel Import | واردات از کانال</h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-telegram' ) ); ?>" class="sti-btn secondary">⚙️ تنظیمات اتصال</a>
			</div>

			<p class="desc">
				دریافت تاریخچه‌ی پیام‌ها از کانال خصوصی <code>@FileechParty</code> با اکانت شخصی شما (MTProto).
				پردازش پس‌زمینه با رفتار انسانی، بدون فشار به فایروال تلگرام.
			</p>

			<?php if ( ! $mtproto_ready ) : ?>
			<div class="sti-info-box" style="margin-bottom:18px;">
				<h3>🔒 کانال خصوصی دارید؟</h3>
				<p style="margin:0;">
					کانال <code>@FileechParty</code> خصوصی است و اسکرپینگ وب برای آن کار نمی‌کند.
					در «<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-telegram' ) ); ?>">تنظیمات تلگرام</a>» بخش
					«اکانت شخصی» را با api_id و api_hash و شماره‌ی خودتان فعال و وارد شوید.
					<?php echo $mtproto_configured ? '<br><strong>⚠️ اکانت تنظیم شده ولی ورود کامل نشده — کد ورود را وارد کنید.</strong>' : ''; ?>
				</p>
			</div>
			<?php endif; ?>

			<!-- شروع واردات جدید -->
			<div class="sti-panel">
				<div class="sti-panel-head">
					<div>
						<h2>🚀 شروع واردات جدید</h2>
						<p>لینک کانال (با یا بدون Message ID) را وارد کنید — تکراری‌ها خودکار رد می‌شوند. فقط محصول (پیش‌نویس/منتشرشده) تکراری محسوب می‌شود.</p>
					</div>
				</div>

				<div class="sti-form-row">
					<div class="sti-field" style="grid-column: span 2;">
						<label>لینک یا یوزرنیم کانال/گروه</label>
						<input type="text" id="ci-username" dir="ltr" placeholder="https://t.me/FileechParty یا t.me/FileechParty/60301 یا @FileechParty"
							value="<?php echo isset( $_GET['username'] ) ? esc_attr( $_GET['username'] ) : ''; ?>" style="font-family:monospace;">
						<span class="hint">همه‌ی فرمت‌ها پشتیبانی می‌شود؛ Message ID از لینک خودکار استخراج می‌شود.</span>
					</div>
				</div>

				<div class="sti-form-row">
					<div class="sti-field">
						<label>شناسه‌ی پست شروع (Message ID)</label>
						<input type="number" id="ci-topic-id" dir="ltr" placeholder="60301"
							value="<?php echo isset( $_GET['topic_id'] ) ? esc_attr( (int) $_GET['topic_id'] ) : '0'; ?>">
						<span class="hint">0 = پیدا کردن خودکار از آخرین پیام.</span>
					</div>
					<div class="sti-field">
						<label>تعداد محصول (غیرتکراری)</label>
						<input type="number" id="ci-count" value="10" min="1" max="500">
						<span class="hint">فقط محصول واقعی تکراری محسوب می‌شود، سشن ناقص نه.</span>
					</div>
					<div class="sti-field">
						<label>زمان انتظار فایل از ربات (دقیقه)</label>
						<input type="number" id="ci-fetch-timeout" min="1" max="60" value="<?php echo (int) STI_Settings::get( 'ci_fetch_timeout_minutes', 10 ); ?>">
						<span class="hint">پیش‌فرض ۱۰ دقیقه. پس از زدن دکمه دانلود، سیستم این مدت منتظر فایل می‌ماند.</span>
						<label class="sti-toggle" style="margin-top:8px;"><input type="checkbox" id="ci-search-enabled" <?php checked( STI_Settings::get( 'ci_search_enabled', 1 ) ); ?>> استفاده از جست‌وجوی سروری قبل از اسکن ترتیبی</label>
					</div>
				</div>

				<div class="sti-form-row">
					<div class="sti-field">
						<label>استراتژی</label>
						<select id="ci-strategy">
							<option value="auto">✨ خودکار با جست‌وجوی کانال (پیشنهادی)</option>
							<option value="mtproto_search" <?php echo $mtproto_ready ? '' : 'disabled'; ?>>🔎 جست‌وجوی کلمه‌ای MTProto — کاندیدا قبل از دانلود</option>
							<option value="mtproto" <?php echo $mtproto_ready ? '' : 'disabled'; ?>>👤 اکانت شخصی (MTProto) — تاریخچه ترتیبی</option>
							<option value="scrape">🌐 اسکرپینگ وب (فقط عمومی) + اتوکت</option>
							<option value="manual">👤 Manual Forward + اتوکت</option>
						</select>
						<span class="hint">جست‌وجو با عبارت‌های دسته انجام می‌شود؛ سپس Duplicate و AutoCat قبل از فشار دکمه بررسی می‌شوند.</span>
					</div>
					<div class="sti-field">
						<label>دسته‌بندی ووکامرس (الزامی) — تشخیص نهایی با اتوکت</label>
						<select id="ci-category" required>
							<option value="">— انتخاب کنید —</option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo (int) $cat->id; ?>"
									<?php echo isset( $_GET['category_id'] ) && (int) $_GET['category_id'] === (int) $cat->id ? 'selected' : ''; ?>>
									<?php echo esc_html( $cat->telegram_label ); ?><?php echo empty( $cat->is_active ) ? ' (غیرفعال)' : ''; ?>
								</option>
							<?php endforeach; ?>
						</select>
						<span class="hint">🤖 اتوکت: دسته نهایی بر اساس عنوان، تگ‌ها، نوع فایل، قوانین، اولویت و Confidence تشخیص داده می‌شود. فرمت (Vector/PSD) هرگز دسته اصلی نیست — قانون طلایی.</span>
					</div>
				</div>

				<div class="sti-info-box" style="margin-top:12px;background:linear-gradient(135deg,#eef2ff,#faf5ff);">
					<strong>🤖 اتوکت چطور کار می‌کند؟</strong><br>
					۱) عنوان نرمال می‌شود → توکنایز → ۲) بررسی قوانین قطعی (100 امتیاز) → ۳) کلمات کلیدی (70) → ۴) مترادف‌ها و الگوهای ترکیبی مثل <code>business + card</code> → ۵) اعمال کلمات منفی (-150) → ۶) اولویت‌بندی (Mockup > Logo > Business Card > Flyer > Text Effect > Infographic > ...) → ۷) محاسبه Confidence% → انتخاب بهترین دسته + تشخیص فرمت جداگانه (Vector/PSD/MP4).<br>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-autocat' ) ); ?>">مدیریت دیکشنری اتوکت و تست زنده ←</a>
				</div>

				<div class="sti-form-row">
					<div class="sti-field">
						<label>برچسب (اختیاری)</label>
						<input type="text" id="ci-label" placeholder="مثلاً: واردات فونت">
					</div>
					<div class="sti-field" style="justify-content:flex-end;">
						<div class="sti-form-actions">
							<button id="ci-start-import" class="sti-btn">🚀 شروع واردات</button>
							<button id="ci-test-scrape" class="sti-btn secondary">📡 تست دسترسی</button>
						</div>
					</div>
				</div>

				<div class="sti-form-actions" style="margin-top:14px;">
					<button id="ci-process-now" class="sti-btn secondary">⚡ پردازش فوری صف</button>
					<span class="hint">برای هاست‌هایی که WP-Cron کار نمی‌کند؛ چند بار کلیک کنید.</span>
				</div>

				<div id="ci-start-result" class="sti-inline-result"></div>
				<div id="ci-test-result" class="sti-inline-result"></div>
				<div id="ci-process-result" class="sti-inline-result"></div>
			</div>

			<!-- لیست واردات‌ها -->
			<div class="sti-panel">
				<div class="sti-panel-head">
					<div>
						<h2>📥 واردات‌های فعال/قبلی <span id="ci-active-count" class="sti-badge"><?php echo (int) $active_count; ?> فعال</span></h2>
						<p>پیشرفت زنده — هر ۴ ثانیه به‌روزرسانی (بدون رفرش صفحه)</p>
					</div>
					<button id="ci-refresh-batches" class="sti-btn secondary">🔄 بروزرسانی</button>
				</div>

				<div class="sti-table-wrap">
					<table class="sti-table widefat" id="ci-batches-table">
						<thead>
							<tr>
								<th>برچسب</th>
								<th>کانال</th>
								<th>استراتژی</th>
								<th>خواسته</th>
								<th>اسکن</th>
								<th>وارد</th>
								<th>تکراری</th>
								<th>پیشرفت</th>
								<th>وضعیت</th>
								<th>عملیات</th>
							</tr>
						</thead>
						<tbody id="ci-batches-tbody">
							<tr><td colspan="10" class="sti-empty">در حال بارگذاری...</td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- جزئیات (Modal) -->
			<div id="ci-detail-modal" class="sti-modal" style="display:none;">
				<div class="sti-modal-inner">
					<div class="sti-modal-header">
						<h3>جزئیات واردات</h3>
						<button class="sti-modal-close" onclick="document.getElementById('ci-detail-modal').style.display='none'">✕</button>
					</div>
					<div id="ci-detail-content" style="padding:18px 22px;max-height:70vh;overflow-y:auto;">
						<p class="sti-empty">در حال بارگذاری...</p>
					</div>
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

			var AJAX_URL = (window.STI && window.STI.ajaxUrl) ? window.STI.ajaxUrl : '';
			var AJAX_NONCE = (window.STI && window.STI.nonce) ? window.STI.nonce : '';
			var pollTimer = null;

			var STRATEGY = { 'mtproto_search': '🔎 MTProto Search', 'mtproto': '👤 MTProto', 'scrape': '🌐 Scraping', 'bot_api': '🤖 Bot API', 'manual': '👤 Manual' };
			var STAGES = {
				'search': 'جست‌وجوی عبارت‌های دسته', 'validate': 'اعتبارسنجی کاندیداها', 'collect': 'خواندن پیام‌ها', 'press': 'فشار دکمه‌ها', 'wait': 'در انتظار فایل ربات',
				'done': 'نهایی‌سازی', 'error': 'خطا'
			};
			var STATUS = {
				'queued': ['در صف', 'queued'], 'running': ['در حال اجرا', 'running'],
				'completed': ['تکمیل', 'completed'], 'partial': ['ناقص', 'partial'],
				'cancelled': ['لغو شده', 'cancelled'], 'error': ['خطا', 'error'],
				'awaiting_forward': ['در انتظار فوروارد', 'awaiting_forward']
			};

			function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

			function renderBatches(batches) {
				var $tbody = $('#ci-batches-tbody');
				var active = 0;
				if (!batches || !batches.length) {
					$tbody.html('<tr><td colspan="10" class="sti-empty">هنوز هیچ وارداتی انجام نشده است. از فرم بالا شروع کنید.</td></tr>');
					$('#ci-active-count').text('0 فعال');
					return;
				}
				var html = '';
				$.each(batches, function (i, b) {
					var st = STATUS[b.status] || [b.status || '?', ''];
					var pct = Math.min(100, Math.round(b.progress || 0));
					var stage = b.stage ? (STAGES[b.stage] || b.stage) : '';
					if (b.status === 'queued' || b.status === 'running') { active++; }
					html += '<tr>' +
						'<td><strong class="sti-filename" style="max-width:140px;">' + esc(b.label) + '</strong></td>' +
						'<td><code>@' + esc(b.username) + '</code></td>' +
						'<td>' + (STRATEGY[b.strategy] || esc(b.strategy)) + '</td>' +
						'<td>' + (b.desired_count || 0) + '</td>' +
						'<td>' + (b.total_scanned || 0) + '</td>' +
						'<td>' + (b.imported || 0) + '</td>' +
						'<td>' + (b.duplicates_skipped || 0) + '</td>' +
						'<td><span class="sti-progress"><span class="sti-progress-bar"><span class="sti-progress-fill ' + (pct >= 100 ? 'full' : '') + '" style="width:' + pct + '%;"></span></span><span class="sti-progress-val">' + pct + '٪</span></span></td>' +
						'<td><span class="sti-badge ' + st[1] + '">' + esc(st[0]) + '</span>' +
							(stage && b.status === 'running' ? '<div class="sti-stage">' + esc(stage) + '</div>' : '') +
							(b.last_error && b.status === 'error' ? '<div class="sti-error-note">' + esc(b.last_error) + '</div>' : '') +
						'</td>' +
						'<td style="white-space:nowrap;">' +
							((b.status === 'queued' || b.status === 'running') ? '<button class="sti-btn-sm danger ci-cancel-batch" data-batch-id="' + esc(b.id) + '">🗑 لغو</button> ' : '') +
							'<button class="sti-btn-sm ci-view-batch" data-batch-id="' + esc(b.id) + '">👁 جزئیات</button>' +
						'</td></tr>';
				});
				$tbody.html(html);
				$('#ci-active-count').text(active + ' فعال');
				if (active > 0) { startPolling(); } else { stopPolling(); }
			}

			function loadBatches() {
				if (!AJAX_URL) { return; }
				$.post(AJAX_URL, { action: 'sti_ci_poll', nonce: AJAX_NONCE })
				.done(function (res) { if (res && res.success) { renderBatches(res.data.batches); } })
				.fail(function () {});
			}
			function startPolling() { if (pollTimer) { return; } pollTimer = setInterval(loadBatches, 4000); }
			function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

			function parseChannelInput(val) {
				var username = (val || '').trim();
				var topicId = 0;
				if (!username) { return { username: '', topicId: 0 }; }
				var m = username.match(/\/(\d{1,10})\s*$/);
				if (m) { topicId = parseInt(m[1], 10) || 0; }
				username = username.replace(/^https?:\/\/(www\.)?(t\.me|telegram\.me|telegram\.dog)\/?/i, '');
				username = username.replace(/^t\.me\/?/i, '');
				username = username.replace(/^s\//i, '');
				username = username.replace(/\?.*$/, '');
				username = username.replace(/\/\d{1,10}$/, '');
				username = username.replace(/^@/, '');
				username = username.replace(/\/+$/, '');
				return { username: username, topicId: topicId };
			}

			$('#ci-username').on('blur change', function () {
				var p = parseChannelInput($(this).val());
				if (p.topicId && !$('#ci-topic-id').val()) { $('#ci-topic-id').val(p.topicId); }
			});

			$('#ci-test-scrape').on('click', function () {
				var $btn = $(this), $res = $('#ci-test-result');
				if (!AJAX_URL) { $res.html('<span style="color:#dc2626">❌ AJAX در دسترس نیست؛ صفحه را رفرش کنید.</span>'); return; }
				$btn.prop('disabled', true);
				$res.html('<span style="color:#6b7280">⏳ در حال بررسی کانال (وب + اکانت شخصی)...</span>');
				$.post(AJAX_URL, { action: 'sti_ci_test_connection', nonce: AJAX_NONCE, chat_username: $('#ci-username').val() })
				.done(function (res) {
					if (res && res.success) {
						var h = '<span style="color:#16a34a">✅ ' + esc(res.data.message) + '</span>';
						if (res.data.mtproto) { h += '<div style="color:#16a34a;margin-top:4px;">👤 اکانت شخصی: ' + esc(res.data.mtproto.title) + ' (id=' + esc(res.data.mtproto.id) + ')</div>'; }
						$res.html(h);
					} else { $res.html('<span style="color:#dc2626">❌ ' + esc(res.data && res.data.message) + '</span>'); }
				})
				.fail(function () { $res.html('<span style="color:#dc2626">❌ خطای ارتباط.</span>'); })
				.always(function () { $btn.prop('disabled', false); });
			});

			$('#ci-start-import').on('click', function () {
				var $btn = $(this), $res = $('#ci-start-result');
				if (!AJAX_URL) { $res.html('<span style="color:#dc2626">❌ AJAX در دسترس نیست.</span>'); return; }
				var input = $('#ci-username').val();
				var p = parseChannelInput(input);
				var data = {
					action: 'sti_ci_start_import', nonce: AJAX_NONCE,
					chat_username: input,
					topic_id: $('#ci-topic-id').val() || p.topicId || 0,
					count: $('#ci-count').val() || 10,
					category_id: $('#ci-category').val(),
					label: $('#ci-label').val(),
					strategy: $('#ci-strategy').val(),
					fetch_timeout: $('#ci-fetch-timeout').val() || 10,
					search_enabled: $('#ci-search-enabled').is(':checked') ? 1 : 0
				};
				if (!data.chat_username) { $res.html('<span style="color:#dc2626">❌ آدرس کانال الزامی است.</span>'); return; }
				if (!data.category_id) { $res.html('<span style="color:#dc2626">❌ دسته‌بندی الزامی است — اتوکت برای دسته‌بندی هوشمند به دسته پایه نیاز دارد. لطفاً یک دسته انتخاب کنید.</span>'); return; }
				$btn.prop('disabled', true);
				$res.html('<span style="color:#6b7280">⏳ در حال شروع واردات...</span>');
				$.post(AJAX_URL, data)
				.done(function (res) {
					if (res && res.success) { $res.html('<span style="color:#16a34a">✅ ' + esc(res.data.message) + '</span>'); loadBatches(); }
					else { $res.html('<span style="color:#dc2626">❌ ' + esc(res.data && res.data.message) + '</span>'); }
				})
				.fail(function () { $res.html('<span style="color:#dc2626">❌ خطای ارتباط.</span>'); })
				.always(function () { $btn.prop('disabled', false); });
			});

			$('#ci-process-now').on('click', function () {
				var $btn = $(this), $res = $('#ci-process-result');
				if (!AJAX_URL) { return; }
				$btn.prop('disabled', true);
				$res.html('<span style="color:#6b7280">⏳ در حال پردازش...</span>');
				$.post(AJAX_URL, { action: 'sti_ci_process_now', nonce: AJAX_NONCE })
				.done(function (res) {
					$res.html(res && res.success ? '<span style="color:#16a34a">' + esc(res.data.message) + '</span>' : '<span style="color:#dc2626">خطا</span>');
					loadBatches();
				})
				.fail(function () { $res.html('<span style="color:#dc2626">❌ خطای ارتباط.</span>'); })
				.always(function () { $btn.prop('disabled', false); });
			});

			$(document).on('click', '.ci-cancel-batch', function () {
				if (!confirm('آیا از لغو این واردات مطمئن هستید؟')) { return; }
				var $btn = $(this);
				$btn.prop('disabled', true);
				$.post(AJAX_URL, { action: 'sti_ci_cancel_batch', nonce: AJAX_NONCE, batch_id: $btn.data('batch-id') })
				.done(function () { loadBatches(); })
				.fail(function () { $btn.prop('disabled', false); });
			});

			$(document).on('click', '.ci-view-batch', function () {
				var batchId = $(this).data('batch-id');
				var $modal = $('#ci-detail-modal');
				var $content = $('#ci-detail-content');
				$modal.show();
				$content.html('<p class="sti-empty">در حال بارگذاری...</p>');
				$.post(AJAX_URL, { action: 'sti_ci_batch_status', nonce: AJAX_NONCE, batch_id: batchId })
				.done(function (res) {
					if (!(res && res.success && res.data.batch)) { $content.html('<p class="sti-empty">اطلاعات یافت نشد.</p>'); return; }
					var b = res.data.batch;
					var results = b.message_results || {};
					var stMap = {
						'imported': '✅ وارد شده', 'error': '❌ خطا', 'waiting_file': '⏳ در انتظار فایل', 'skipped_duplicate': '🔄 تکراری',
						'no_file_code': '⏭ بدون کد', 'scrape_failed': '❌ ناموفق',
						'session_failed': '❌ سشن ناموفق', 'no_caption': '⏭ بدون کپشن', 'no_category': '⏭ بدون دسته'
					};
					var html = '<div class="sti-info-box" style="margin-bottom:14px;">' +
						'<strong>استراتژی:</strong> ' + esc(STRATEGY[b.strategy] || b.strategy) +
						' | <strong>اسکن شده:</strong> ' + (b.total_scanned || 0) +
						' | <strong>وارد شده:</strong> ' + (b.imported || 0) +
						' | <strong>تکراری:</strong> ' + (b.duplicates_skipped || 0) +
						(b.stage ? ' | <strong>مرحله:</strong> ' + esc(STAGES[b.stage] || b.stage) : '') + '</div>';
					if (b.last_error) { html += '<div class="sti-error-note" style="margin-bottom:12px;">❌ ' + esc(b.last_error) + '</div>'; }
					html += '<div class="sti-table-wrap"><table class="sti-table widefat"><thead><tr>' +
						'<th>Message ID</th><th>File Code</th><th>وضعیت</th><th>Session</th><th>تصویر</th><th>فایل</th><th>نقص/خطا</th>' +
						'</tr></thead><tbody>';
					var msgIds = Object.keys(results).sort(function (a, b2) { return parseInt(b2) - parseInt(a); });
					if (!msgIds.length) {
						html += '<tr><td colspan="7" class="sti-empty">در حال پردازش...</td></tr>';
					} else {
						for (var i = 0; i < msgIds.length; i++) {
							var mid = msgIds[i];
							var r = results[mid] || {};
							var fileCell = r.file === 'downloaded' ? '💾 دانلود شد' : (r.file === 'waiting_bot' ? '⏳ در انتظار ربات' : (r.file === 'none' ? '❌ ندارد' : '—'));
							var imgCell = r.image === 'yes' ? '✅' : (r.status === 'imported' ? '❌' : '—');
							var errCell = r.error ? '<div class="sti-error-note" style="max-width:320px;max-height:80px;overflow:auto;">' + esc(r.error) + '</div>' : '—';
							html += '<tr><td><code>' + esc(mid) + '</code></td>' +
								'<td>' + esc(r.file_code || '—') + '</td>' +
								'<td>' + esc(stMap[r.status] || r.status || '⏳') + '</td>' +
								'<td>' + (r.session_id ? '#' + esc(r.session_id) : '—') + '</td>' +
								'<td>' + imgCell + '</td><td>' + fileCell + '</td><td>' + errCell + '</td></tr>';
						}
					}
					html += '</tbody></table></div>';
					$content.html(html);
				})
				.fail(function () { $content.html('<p class="sti-empty">خطا در دریافت اطلاعات.</p>'); });
			});

			$('#ci-detail-modal').on('click', function (e) { if (e.target === this) { this.style.display = 'none'; } });

			$('#ci-refresh-batches').on('click', loadBatches);
			loadBatches();
		});
	}
	if (window.jQuery) { boot(); }
	else { var t = setInterval(function () { if (window.jQuery) { clearInterval(t); boot(); } }, 50); }
})();
</script>
