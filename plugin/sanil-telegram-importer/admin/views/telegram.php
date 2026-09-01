<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

$s = STI_Settings::all();
settings_errors( 'sti' );
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">
	<div class="sti-header"><h1><span class="dashicons dashicons-telegram"></span> تنظیمات تلگرام</h1></div>

	<form method="post">
		<?php wp_nonce_field( 'sti_save_settings', 'sti_nonce' ); ?>
		<input type="hidden" name="sti_form" value="telegram">

		<div class="sti-panel">
			<h2>اتصال بات</h2>
			<p class="desc">توکن را از @BotFather بگیر. سپس چت‌آیدی خودت (یا هر ادمین دیگری که اجازه ثبت محصول دارد) را وارد کن.</p>

			<div class="sti-field">
				<label>Bot Token</label>
				<input type="text" name="bot_token" value="<?php echo esc_attr( $s['bot_token'] ); ?>" dir="ltr" placeholder="123456:ABC-DEF...">
			</div>

			<div class="sti-field">
				<label>آدرس پایه API تلگرام (API Base URL)</label>
				<input type="url" name="api_base_url" value="<?php echo esc_attr( $s['api_base_url'] ); ?>" dir="ltr">
				<div class="hint">
					پیش‌فرض: <code>https://api.telegram.org</code>. اگر این آدرس روی هاست شما فیلتر/ناپایدار است، به‌جای پراکسی می‌توانید اینجا آدرس یک <strong>Reverse Proxy رایگان روی Cloudflare Workers</strong> بدهید که خودتان می‌سازید (پایدارتر از پراکسی‌های عمومی است، چون از شبکه‌ی Cloudflare استفاده می‌کند). راهنما:
					<ol style="margin:8px 0 0 18px;padding:0;">
						<li>در <a href="https://workers.cloudflare.com" target="_blank">workers.cloudflare.com</a> یک حساب رایگان بساز و یک Worker جدید بساز.</li>
						<li>این کد را در Worker جای‌گذاری کن:</li>
					</ol>
					<pre style="background:#f6f7f7;border:1px solid #e2e4e7;border-radius:6px;padding:10px;direction:ltr;text-align:left;white-space:pre-wrap;margin-top:6px;">export default {
  async fetch(request) {
    const url = new URL(request.url);
    const target = "https://api.telegram.org" + url.pathname + url.search;
    return fetch(target, {
      method: request.method,
      headers: request.headers,
      body: request.method !== "GET" ? await request.blob() : undefined,
    });
  }
}</pre>
					<span>سپس آدرس Worker (چیزی شبیه <code>https://your-worker.your-subdomain.workers.dev</code>) را همین‌جا در این فیلد قرار بده — بدون هیچ پراکسی‌ای، این آدرس معمولاً پایدار خواهد بود.</span>
				</div>
			</div>

			<div class="sti-field">
				<label>Telegram User IDهای مجاز (الزامی)</label>
				<input type="text" name="admin_user_ids" value="<?php echo esc_attr( $s['admin_user_ids'] ); ?>" dir="ltr" placeholder="123456789, 987654321">
				<div class="hint">فقط شناسهٔ عددی کاربران مورداعتماد را با کاما جدا کنید. اگر این فیلد خالی باشد، ربات به‌صورت امن هیچ درخواست تلگرامی را قبول نمی‌کند. برای گروه‌ها هم باید User ID هر مدیر را وارد کنید.</div>
			</div>
			<div class="sti-field">
				<label>چت‌آیدی‌های قدیمی (اختیاری، فقط گفت‌وگوی خصوصی)</label>
				<input type="text" name="admin_chat_ids" value="<?php echo esc_attr( $s['admin_chat_ids'] ); ?>" dir="ltr" placeholder="123456789">
				<div class="hint">برای سازگاری نسخه‌های قبل است؛ با این فیلد به اعضای گروه دسترسی داده نمی‌شود.</div>
			</div>

			<div class="sti-field">
				<p class="desc" style="margin:0 0 10px;">💡 این پراکسی و «آدرس پایه API» بالا جایگزین هم هستند، نه مکمل: یا از یک آدرس API جایگزین (مثل Cloudflare Worker) استفاده کن، یا یک پراکسی فعال کن — لازم نیست هر دو را همزمان روشن کنی.</p>
				<label class="sti-toggle"><input type="checkbox" name="proxy_enabled" <?php checked( $s['proxy_enabled'] ); ?>> استفاده از پراکسی برای فراخوانی API تلگرام (متن/دستورها)</label>
				<div class="hint">این پراکسی فقط برای پیام‌های متنی/دکمه‌ها استفاده می‌شود؛ دانلود مستقیم فایل از تلگرام از این مسیر انجام نمی‌شود.</div>
			</div>

			<div class="sti-row">
				<div class="sti-field">
					<label>نوع پراکسی</label>
					<select name="proxy_type">
						<option value="socks5h" <?php selected( $s['proxy_type'], 'socks5h' ); ?>>SOCKS5 (با DNS از طریق پراکسی — پیشنهادی برای هاست‌های ایران)</option>
						<option value="socks5" <?php selected( $s['proxy_type'], 'socks5' ); ?>>SOCKS5 (معمولی)</option>
						<option value="socks4" <?php selected( $s['proxy_type'], 'socks4' ); ?>>SOCKS4</option>
						<option value="http" <?php selected( $s['proxy_type'], 'http' ); ?>>HTTP/HTTPS Proxy</option>
					</select>
					<div class="hint">اگر پراکسی شما SOCKS5 است ولی گزینه HTTP انتخاب شده باشد، اتصال همیشه با خطا مواجه می‌شود. اگر مطمئن نیستید، اول SOCKS5 را امتحان کنید.</div>
				</div>
			</div>
			<div class="sti-row">
				<div class="sti-field"><label>آدرس پراکسی</label><input type="text" name="proxy_host" value="<?php echo esc_attr( $s['proxy_host'] ); ?>" dir="ltr" placeholder="1.2.3.4 یا proxy.example.com"></div>
				<div class="sti-field"><label>پورت</label><input type="text" name="proxy_port" value="<?php echo esc_attr( $s['proxy_port'] ); ?>" dir="ltr" placeholder="1080"></div>
			</div>
			<div class="sti-row">
				<div class="sti-field"><label>یوزرنیم پراکسی (اختیاری)</label><input type="text" name="proxy_user" value="<?php echo esc_attr( $s['proxy_user'] ); ?>" dir="ltr"></div>
				<div class="sti-field"><label>پسورد پراکسی (اختیاری)</label><input type="password" name="proxy_pass" value="<?php echo esc_attr( $s['proxy_pass'] ); ?>" dir="ltr"></div>
			</div>

			<button type="submit" class="sti-btn">💾 ذخیره تنظیمات</button>
		</div>

		<div class="sti-panel" style="margin-top:18px;border-color:#7c3aed;">
			<h2>👤 اکانت شخصی تلگرام (MTProto) — برای کانال‌های خصوصی</h2>
			<p class="desc">
				این بخش برای واردات از کانال/گروه‌های <strong>خصوصی</strong> (مثل @FileechParty) لازم است.
				اسکرپینگ وب فقط کانال‌های عمومی را می‌بیند؛ برای کانال خصوصی، افزونه با <strong>خودِ اکانت شما</strong>
				وارد تلگرام می‌شود، تاریخچه را می‌خواند و فایل‌ها را دانلود می‌کند — دقیقاً مثل این‌که خودتان داخل تلگرام این کار را می‌کنید.
				<code>api_id</code> و <code>api_hash</code> را از <a href="https://my.telegram.org" target="_blank">my.telegram.org</a> → «API development tools» بگیرید.
			</p>

			<label class="sti-toggle"><input type="checkbox" name="mtproto_enabled" <?php checked( $s['mtproto_enabled'] ); ?>> فعال‌سازی اکانت شخصی</label>

			<div class="sti-row">
				<div class="sti-field">
					<label>API ID (از my.telegram.org)</label>
					<input type="text" name="mtproto_api_id" value="<?php echo esc_attr( $s['mtproto_api_id'] ); ?>" dir="ltr" placeholder="1234567">
				</div>
				<div class="sti-field">
					<label>API Hash</label>
					<input type="text" name="mtproto_api_hash" value="<?php echo esc_attr( $s['mtproto_api_hash'] ); ?>" dir="ltr" placeholder="0123456789abcdef0123456789abcdef">
				</div>
			</div>
			<div class="sti-row">
				<div class="sti-field">
					<label>شماره تلفن تلگرام (با کد کشور، بدون صفر اول)</label>
					<input type="text" name="mtproto_phone" value="<?php echo esc_attr( $s['mtproto_phone'] ); ?>" dir="ltr" placeholder="+98912xxxxxxx">
				</div>
			</div>

			<label class="sti-toggle"><input type="checkbox" name="mtproto_auto_download" <?php checked( $s['mtproto_auto_download'] ?? 1 ); ?>> دانلود خودکار فایل‌ها با اکانت شخصی (بدون محدودیت ۲۰MB بات)</label>
			<label class="sti-toggle"><input type="checkbox" name="mtproto_press_buttons" <?php checked( $s['mtproto_press_buttons'] ?? 1 ); ?>> فشار دادن خودکار دکمه‌های «دانلود» (کالبک) در پیام‌های کانال</label>

			<button type="submit" class="sti-btn" style="margin-top:10px;">💾 ذخیره تنظیمات اکانت</button>
		</div>
	</form>

	<!-- ======================= وضعیت و ورود اکانت شخصی ======================= -->
	<div class="sti-panel">
		<h2>🔑 وضعیت ورود اکانت شخصی</h2>
		<p class="desc">بعد از ذخیره‌ی تنظیمات بالا، موتور MadelineProto را نصب و سپس ورود را کامل کنید (فقط یک بار).</p>

		<?php
		// ── وضعیت اولیه از سمت سرور (بدون وابستگی به JS) ──
		$mt_configured       = STI_MTProto::is_configured();
		$mt_engine_supported = STI_MTProto::engine_supported();
		$mt_engine_installed = STI_MTProto::engine_installed();
		$mt_engine_healthy   = $mt_engine_installed ? STI_MTProto::engine_healthy() : false;
		$mt_has_session      = $mt_configured && file_exists( STI_MTProto::session_path() );

		$mt_lines = array();
		$mt_lines[] = '<strong>وضعیت:</strong> ' . ( $mt_has_session
			? '<span style="color:#d97706">⏳ سشن موجود است — در انتظار بررسی/تکمیل ورود</span>'
			: '<span style="color:#666">ورود نشده</span>' );
		if ( STI_MTProto::site_has_composer() ) {
			$mt_lines[] = '<strong>Composer سایت:</strong> <span style="color:#16a34a">تشخیص داده شد — حالت سازگاری خودکار فعال است ✓</span>';
		}
		$mt_lines[] = '<strong>موتور MadelineProto:</strong> ' . ( $mt_engine_installed
			? ( $mt_engine_healthy
				? '<span style="color:#16a34a">نصب و سالم</span>'
				: '<span style="color:#dc2626">نصب شده ولی خراب — دوباره نصب کنید</span>' )
			: ( $mt_engine_supported
				? '<span style="color:#d97706">نصب نشده — دکمه‌ی پایین را بزنید</span>'
				: '<span style="color:#dc2626">PHP هاست (' . PHP_VERSION . ') پشتیبانی نمی‌شود (۷.۴+ لازم است)</span>' ) );
		$mt_lines[] = '<strong>تنظیمات:</strong> ' . ( $mt_configured
			? 'شماره ' . esc_html( STI_MTProto::phone() ) . ' ثبت شده'
			: '<span style="color:#d97706">تنظیم نشده — فرم بالا را ذخیره کنید</span>' );
		?>
		<div id="sti-mt-status-box" class="sti-field">
			<div style="line-height:2"><?php echo implode( '<br>', $mt_lines ); ?></div>
		</div>

		<div class="sti-field" style="margin-top:10px;">
			<button type="button" id="sti-mt-install" class="sti-btn secondary">⬇️ نصب موتور MadelineProto</button>
			<span id="sti-mt-install-result" class="sti-inline-result"></span>
			<div class="hint" style="margin-top:6px;">
				دانلود موتور (~۱۹MB) ممکن است چند دقیقه طول بکشد و روی بعضی هاست‌ها با محدودیت زمان PHP قطع شود.
				راه‌حل جایگزین: فایل <code><?php echo esc_html( STI_MTProto::engine_filename() ); ?></code> را از
				<a href="https://github.com/danog/MadelineProto/releases/latest" target="_blank">GitHub MadelineProto</a>
				دانلود کنید و با <strong>همان نام</strong> در پوشه‌ی <code>wp-content/uploads/sti-mtproto/</code> آپلود کنید (تغییر نام ندهید!)، سپس صفحه را رفرش کنید.
				<strong>نکته:</strong> اگر سایت شما Composer دارد (بیشتر افزونه‌ها دارند)، افزونه به‌صورت خودکار حالت سازگاری را فعال می‌کند و دیگر خطای «Composer autoloader detected» نمی‌بینید.
			</div>
		</div>

		<div class="sti-field" style="margin-top:10px;">
			<button type="button" id="sti-mt-send-code" class="sti-btn secondary">📲 ارسال کد ورود</button>
			<span id="sti-mt-code-result" class="sti-inline-result"></span>
		</div>

		<div class="sti-row">
			<div class="sti-field">
				<label>کد دریافتی در تلگرام</label>
				<input type="text" id="sti-mt-code" dir="ltr" placeholder="12345" autocomplete="one-time-code">
			</div>
			<div class="sti-field">
				<label>رمز دومرحله‌ای (فقط اگر فعال است — هرگز ذخیره نمی‌شود)</label>
				<input type="password" id="sti-mt-password" dir="ltr" placeholder="اختیاری">
			</div>
			<div class="sti-field" style="align-self:flex-end;">
				<button type="button" id="sti-mt-complete-login" class="sti-btn">🔓 تکمیل ورود</button>
			</div>
		</div>

		<div class="sti-field" style="margin-top:10px;">
			<button type="button" id="sti-mt-logout" class="sti-btn-sm danger">🚪 خروج از اکانت</button>
			<span class="hint">خروج، سشن محلی روی همین سرور را پاک می‌کند (دسترسی تلگرام شما از تلگرام هم قابل لغو است: Devices → terminate session).</span>
		</div>
	</div>

	<div class="sti-panel">
		<h2>وضعیت اتصال و Webhook</h2>
		<p class="desc">بعد از ذخیره توکن، اتصال را تست کن و سپس Webhook را ثبت کن تا پیام‌های تلگرام به سایت شما برسد.</p>

		<div class="sti-field">
			<button type="button" id="sti-test-telegram" class="sti-btn secondary">🔌 تست اتصال بات</button>
			<div id="sti-test-telegram-result" class="sti-inline-result"></div>
		</div>

		<div class="sti-field">
			<button type="button" id="sti-generate-secret" class="sti-btn secondary">🔑 ساخت کد امنیتی Webhook</button>
			<button type="button" id="sti-set-webhook" class="sti-btn">📡 ثبت Webhook</button>
			<div id="sti-webhook-result" class="sti-inline-result"></div>
		</div>

		<?php if ( ! empty( $s['webhook_secret'] ) ) : ?>
		<div class="sti-field">
			<label>آدرس فعلی Webhook</label>
			<div class="sti-code"><?php echo esc_html( STI_Webhook::webhook_url() ); ?></div>
		</div>
		<?php endif; ?>
	</div>

	<div class="sti-panel">
		<h2>دستورات ربات</h2>
		<div class="sti-table-wrap">
		<table class="sti-table">
			<tr><td><code>/start</code></td><td>شروع ثبت محصول جدید (نمایش منوی دسته‌بندی)</td></tr>
			<tr><td><code>/status</code></td><td>نمایش وضعیت Session فعلی و موارد باقی‌مانده</td></tr>
			<tr><td><code>/cancel</code></td><td>لغو Session باز فعلی</td></tr>
			<tr><td><code>/queue</code></td><td>وضعیت صف انتشار + دکمه‌ی شروع/توقف</td></tr>
			<tr><td><code>/preview</code></td><td>پیش‌نمایش ۵ محصول ثبت‌شده‌ی آخر</td></tr>
			<tr><td><code>/done</code></td><td>پایان دادن به حالت «ثبت سریع» (چند فایل زیر ۲۰ مگ)</td></tr>
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

	// STI (ajaxUrl/nonce) توسط وردپرس در فوتر چاپ می‌شود — پس موقع اجرای این
	// بلوک ممکن است هنوز تعریف نشده باشد. از این رو همه‌چیز داخل ready اجرا
	// می‌شود و دسترسی به STI هم محافظت‌شده است.
	var AJAX_URL = (window.STI && window.STI.ajaxUrl) ? window.STI.ajaxUrl : '';
	var AJAX_NONCE = (window.STI && window.STI.nonce) ? window.STI.nonce : '';

	var $box = $('#sti-mt-status-box');

	// ========== نمایش وضعیت ==========
	function renderStatus(d) {
		if (!d) { return; }
		var lines = [];
		lines.push('<strong>وضعیت:</strong> ' +
			(d.state === 'logged_in' ? '<span style="color:#16a34a">✅ وارد شده‌اید</span>' :
			d.state === 'awaiting_code' ? '<span style="color:#d97706">⏳ منتظر کد ورود</span>' :
			'<span style="color:#666">ورود نشده</span>'));

		lines.push('<strong>موتور MadelineProto:</strong> ' +
			(d.engine_installed ? (d.engine_healthy ? '<span style="color:#16a34a">نصب و سالم</span>' : '<span style="color:#dc2626">نصب شده ولی خراب — دوباره نصب کنید</span>') :
			d.engine_supported ? '<span style="color:#d97706">نصب نشده</span>' : '<span style="color:#dc2626">PHP هاست (' + d.php + ') پشتیبانی نمی‌شود (۷.۴+ لازم است)</span>'));

		lines.push('<strong>تنظیمات:</strong> ' + (d.configured ? 'شماره ' + d.phone + ' ثبت شده' : '<span style="color:#d97706">تنظیم نشده — فرم بالا را ذخیره کنید</span>'));

		if (d.composer) {
			lines.push('<strong>Composer سایت:</strong> <span style="color:#16a34a">تشخیص داده شد — حالت سازگاری خودکار فعال است ✓</span>');
		}

		if (d.account && d.account.name) {
			lines.push('<strong>اکانت:</strong> ' + d.account.name + (d.account.username ? ' @' + d.account.username : '') + ' (id=' + d.account.id + ')');
		}
		if (d.error) {
			lines.push('<span style="color:#dc2626">⚠️ ' + d.error + '</span>');
		}
		if (d.pending) {
			lines.push('<span style="color:#d97706">📲 کد ورود قبلاً ارسال شده — کد را وارد کنید.</span>');
		}
		$box.html('<div style="line-height:2">' + lines.join('<br>') + '</div>');
	}

	function refreshStatus() {
		if (!AJAX_URL) {
			$box.html('<p class="sti-empty">⚠️ متغیرهای AJAX هنوز بارگذاری نشده‌اند؛ صفحه را یک‌بار رفرش کنید.</p>');
			return;
		}
		$.post(AJAX_URL, { action: 'sti_mt_status', nonce: AJAX_NONCE })
		.done(function (res) {
			if (res && res.success) { renderStatus(res.data); }
			else if (res && res.data) { $box.html('<p class="sti-empty">' + (res.data.message || 'خطا') + '</p>'); }
			else { $box.html('<p class="sti-empty">⚠️ پاسخ نامعتبر از سرور.</p>'); }
		})
		.fail(function () {
			$box.html('<p class="sti-empty">خطای ارتباط با سرور. اگر ادامه دارد، WP-Cron و افزونه‌های امنیتی هاست را چک کنید.</p>');
		});
	}

	// ========== نصب موتور ==========
	$('#sti-mt-install').on('click', function () {
		var $btn = $(this), $res = $('#sti-mt-install-result');
		if (!AJAX_URL) { $res.text('❌ AJAX در دسترس نیست؛ صفحه را رفرش کنید.'); return; }
		$btn.prop('disabled', true);
		$res.text('⏳ در حال دانلود موتور (۶۰-۸۰ MB)... این کار ۱ تا ۳ دقیقه طول می‌کشد. صفحه را نبندید!');
		$.post(AJAX_URL, { action: 'sti_mt_install', nonce: AJAX_NONCE })
		.done(function (res) {
			if (res && res.success) { $res.text('✅ ' + res.data.message); }
			else if (res && res.data) { $res.text('❌ ' + res.data.message); }
			else { $res.text('❌ پاسخ نامعتبر از سرور.'); }
			refreshStatus();
		})
		.fail(function () {
			$res.text('❌ اتصال هنگام دانلود قطع شد (احتمالاً محدودیت زمان PHP). راهنمای «نصب دستی» پایین را ببینید.');
			refreshStatus();
		})
		.always(function () { $btn.prop('disabled', false); });
	});

	// ========== ارسال کد ==========
	$('#sti-mt-send-code').on('click', function () {
		var $btn = $(this), $res = $('#sti-mt-code-result');
		if (!AJAX_URL) { $res.text('❌ AJAX در دسترس نیست؛ صفحه را رفرش کنید.'); return; }
		$btn.prop('disabled', true);
		$res.text('⏳ در حال ارسال کد...');
		$.post(AJAX_URL, { action: 'sti_mt_send_code', nonce: AJAX_NONCE })
		.done(function (res) {
			$res.text((res && res.success) ? '✅ ' + res.data.message : '❌ ' + (res.data && res.data.message ? res.data.message : 'خطا'));
			refreshStatus();
			if (res && res.success) {
				// بعد از موفقیت، ۲۵ ثانیه دکمه قفل بماند تا کد تکراری ارسال نشود
				setTimeout(function () { $btn.prop('disabled', false); }, 25000);
				return;
			}
			$btn.prop('disabled', false);
		})
		.fail(function () { $res.text('❌ خطای ارتباط.'); refreshStatus(); $btn.prop('disabled', false); })
		.always(function () { /* کنترل در done/fail */ });
	});

	// ========== تکمیل ورود ==========
	$('#sti-mt-complete-login').on('click', function () {
		var $btn = $(this), $res = $('#sti-mt-code-result');
		var code = $('#sti-mt-code').val() || '';
		var password = $('#sti-mt-password').val() || '';

		if (!code) { $res.text('❌ کد ورود را وارد کنید.'); return; }
		if (!AJAX_URL) { $res.text('❌ AJAX در دسترس نیست؛ صفحه را رفرش کنید.'); return; }

		$btn.prop('disabled', true);
		$res.text('⏳ در حال ورود...');
		$.post(AJAX_URL, {
			action: 'sti_mt_complete_login',
			nonce: AJAX_NONCE,
			code: code,
			password: password
		})
		.done(function (res) {
			if (res && res.success) {
				$res.text('✅ ' + res.data.message);
				$('#sti-mt-code').val('');
				$('#sti-mt-password').val('');
			} else {
				$res.text('❌ ' + (res.data && res.data.message ? res.data.message : 'خطا'));
				if (res && res.data && res.data.code === 'sti_mt_2fa') {
					$('#sti-mt-password').focus();
				}
			}
			refreshStatus();
		})
		.fail(function () { $res.text('❌ خطای ارتباط.'); })
		.always(function () { $btn.prop('disabled', false); });
	});

	// ========== خروج ==========
	$('#sti-mt-logout').on('click', function () {
		if (!confirm('از خروج از اکانت شخصی مطمئن هستید؟')) return;
		var $btn = $(this);
		if (!AJAX_URL) { return; }
		$btn.prop('disabled', true);
		$.post(AJAX_URL, { action: 'sti_mt_logout', nonce: AJAX_NONCE })
		.done(function () { refreshStatus(); })
		.fail(function () {})
		.always(function () { $btn.prop('disabled', false); });
	});

	// ========== بروزرسانی زنده‌ی وضعیت ==========
	refreshStatus();
	// هر ۳۰ ثانیه وضعیت را دوباره چک کن (برای بعد از نصب/ورود)
	setInterval(function () {
		if (window.STI) { refreshStatus(); }
	}, 30000);

		});
	}
	if (window.jQuery) { boot(); }
	else { var t = setInterval(function () { if (window.jQuery) { clearInterval(t); boot(); } }, 50); }
})();
</script>
