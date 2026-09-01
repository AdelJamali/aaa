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
	<div class="sti-header"><h1><span class="dashicons dashicons-database"></span> ذخیره‌سازی فایل</h1></div>

	<form method="post">
		<?php wp_nonce_field( 'sti_save_settings', 'sti_nonce' ); ?>
		<input type="hidden" name="sti_form" value="storage">

		<div class="sti-panel">
			<h2>محل ذخیره دائمی فایل‌ها</h2>
			<p class="desc">وقتی اطلاعات یک محصول کامل شد، افزونه فایل را از لینکی که خودت می‌فرستی دانلود کرده و <strong>دائمی</strong> ذخیره می‌کند (نه لینک موقت). این تنظیم مشخص می‌کند فایل نهایی کجا ذخیره شود.</p>

			<div class="sti-field">
				<label><input type="radio" name="storage_mode" value="local" <?php checked( $s['storage_mode'], 'local' ); ?>> روی هاست همین سایت (ریشه سایت / پوشه آپلود وردپرس)</label>
			</div>
			<div class="sti-field">
				<label><input type="radio" name="storage_mode" value="remote" <?php checked( $s['storage_mode'], 'remote' ); ?>> روی یک هاست دانلود خارجی (FTP یا API آپلود)</label>
			</div>

			<div class="sti-local-fields">
				<div class="sti-field">
					<label>مسیر پوشه داخل uploads</label>
					<input type="text" name="local_base_path" value="<?php echo esc_attr( $s['local_base_path'] ); ?>" dir="ltr">
					<div class="hint">فایل‌ها در wp-content/uploads/{این مسیر}/سال/ماه/ ذخیره می‌شوند. <strong>مقدار پیشنهادی: woocommerce_uploads</strong> — این پوشه از قبل توسط ووکامرس «تایید‌شده» است و نیاز به تنظیم اضافه ندارد. اگر مقدار دیگری بگذارید (مثلاً یک پوشه‌ی دلخواه)، باید آن را دستی در ووکامرس > تنظیمات > محصولات > محصولات قابل دانلود > Approved Download Directories اضافه کنید، وگرنه محصول با خطای «دایرکتوری تایید‌نشده» ساخته نمی‌شود.</div>
				</div>
			</div>

			<div class="sti-remote-fields">
				<div class="sti-field">
					<label>نوع اتصال هاست خارجی</label>
					<select name="remote_type">
						<option value="ftp" <?php selected( $s['remote_type'], 'ftp' ); ?>>FTP / FTPS</option>
						<option value="http" <?php selected( $s['remote_type'], 'http' ); ?>>API آپلود اختصاصی (HTTP)</option>
					</select>
				</div>

				<div class="sti-ftp-fields">
					<div class="sti-row">
						<div class="sti-field"><label>هاست FTP</label><input type="text" name="remote_ftp_host" value="<?php echo esc_attr( $s['remote_ftp_host'] ); ?>" dir="ltr"></div>
						<div class="sti-field"><label>پورت</label><input type="number" name="remote_ftp_port" value="<?php echo esc_attr( $s['remote_ftp_port'] ); ?>" dir="ltr"></div>
					</div>
					<div class="sti-row">
						<div class="sti-field"><label>یوزرنیم</label><input type="text" name="remote_ftp_user" value="<?php echo esc_attr( $s['remote_ftp_user'] ); ?>" dir="ltr"></div>
						<div class="sti-field"><label>پسورد</label><input type="password" name="remote_ftp_pass" value="<?php echo esc_attr( $s['remote_ftp_pass'] ); ?>" dir="ltr"></div>
					</div>
					<div class="sti-field"><label>مسیر پوشه روی هاست خارجی</label><input type="text" name="remote_ftp_path" value="<?php echo esc_attr( $s['remote_ftp_path'] ); ?>" dir="ltr"></div>
					<div class="sti-field"><label class="sti-toggle"><input type="checkbox" name="remote_ftp_ssl" <?php checked( $s['remote_ftp_ssl'] ); ?>> استفاده از FTPS (اتصال امن)</label></div>
					<div class="sti-field">
						<button type="button" id="sti-test-ftp" class="sti-btn secondary">🔌 تست اتصال FTP</button>
						<div id="sti-test-ftp-result" class="sti-inline-result"></div>
					</div>
					<div class="sti-field" style="margin-top:10px;border-top:1px dashed #e2e6ef;padding-top:12px;">
						<button type="button" id="sti-fix-gfx" class="sti-btn secondary">🛠️ اصلاح لینک‌های /gfx/gfx/ → /gfx/</button>
						<div id="sti-fix-gfx-result" class="sti-inline-result"></div>
						<div class="hint">اگر محصولات قبلی با لینک تکراری مثل /gfx/gfx/vector ساخته شدند، این دکمه همه را یک‌بار اصلاح می‌کند.</div>
					</div>
				</div>

				<div class="sti-http-fields">
					<div class="sti-field"><label>آدرس API آپلود</label><input type="url" name="remote_http_endpoint" value="<?php echo esc_attr( $s['remote_http_endpoint'] ); ?>" dir="ltr" placeholder="https://download.example.com/api/upload"></div>
					<div class="sti-field"><label>کلید API</label><input type="text" name="remote_http_api_key" value="<?php echo esc_attr( $s['remote_http_api_key'] ); ?>" dir="ltr"></div>
				</div>

				<div class="sti-field">
					<label>آدرس عمومی دانلود (Base URL)</label>
					<input type="url" name="remote_public_base_url" value="<?php echo esc_attr( $s['remote_public_base_url'] ); ?>" dir="ltr" placeholder="https://dl.example.com/files">
					<div class="hint">لینک نهایی محصول از این آدرس + مسیر فایل ساخته می‌شود. مثال درست: اگر پوشه FTP شما public_html/gfx است و فایل باید در https://dl.goldenfile.ir/gfx/vector/... باشد، Base URL را https://dl.goldenfile.ir بگذار (نه https://dl.goldenfile.ir/gfx).</div>
				</div>
			</div>

			<button type="submit" class="sti-btn">💾 ذخیره تنظیمات</button>
		</div>
	</form>

	<div class="sti-panel">
		<h2>💡 نکته مهم درباره تصویر محصول</h2>
		<p class="desc">تصویر شاخص همیشه در کتابخانه رسانه‌ی همین وردپرس ذخیره می‌شود (چون حجم آن کوچک است و ووکامرس برای تصویر شاخص به یک Attachment ID داخلی نیاز دارد). این تنظیم فقط روی <strong>فایل قابل دانلود اصلی</strong> محصول اثر دارد.</p>
	</div>

	<div class="sti-panel" style="margin-top:12px;">
		<h2>ℹ️ نسخه‌ی افزونه</h2>
		<p class="desc">نسخه‌ی نصب‌شده: <strong><?php echo esc_html( STI_VERSION ); ?></strong>
		<?php if ( version_compare( STI_VERSION, '6.0.0', '<' ) ) : ?>
			<span style="color:#dc2626;font-weight:bold;"> — ⚠️ این نسخه قدیمی است! فایل ZIP جدید (6.0.0) را نصب کنید. ذخیره‌سازی ۳ مرحله‌ای (FTP → محلی → fallback نهایی) برای جلوگیری از خطای «ذخیره‌سازی فایل دریافتی ناموفق بود» اضافه شده.</span>
		<?php else : ?>
			<span style="color:#16a34a;font-weight:bold;"> — ✅ نسخه به‌روز است (6.0.0) — ذخیره‌سازی ۳ مرحله‌ای فعال، اتوکت با دیکشنری 1000+ کلمه، بدون بیرون‌زدگی روی موبایل.</span>
		<?php endif; ?>
		</p>
	</div>
		</div>
	</div>
</div>

<script>
jQuery(function ($) {
	'use strict';

	var $btn = $('#sti-test-ftp');
	var $res = $('#sti-test-ftp-result');

	$btn.on('click', function () {
		$btn.prop('disabled', true);
		$res.html('<span style="color:#666">⏳ در حال تست کامل FTP (اتصال، ورود، ساخت پوشه، آپلود)...</span>');

		$.post(STI.ajaxUrl, {
			action: 'sti_test_ftp_full',
			nonce: STI.nonce
		})
		.done(function (r) {
			if (r && r.success && r.data && r.data.steps) {
				var html = '<div style="margin-top:8px;line-height:2">';
				$.each(r.data.steps, function (i, s) {
					var icon = s.ok ? '✅' : '❌';
					html += '<div>' + icon + ' ' + $('<div>').text(s.label).html() +
						' — <span style="color:' + (s.ok ? '#16a34a' : '#dc2626') + '\">' + $('<div>').text(s.detail || '').html() + '</span></div>';
				});
				html += '</div>';
				$res.html(html);
			} else {
				$res.html('<span style="color:#dc2626">❌ ' + ((r && r.data && r.data.message) || 'خطا') + '</span>');
			}
		})
		.fail(function () { $res.html('<span style="color:#dc2626">❌ خطای ارتباط.</span>'); })
		.always(function () { $btn.prop('disabled', false); });
	});

	$('#sti-fix-gfx').on('click', function () {
		var $b = $(this), $r = $('#sti-fix-gfx-result');
		if (!confirm('لینک‌های /gfx/gfx/ به /gfx/ تبدیل شوند؟')) return;
		$b.prop('disabled', true);
		$r.html('<span style="color:#666">⏳ در حال اصلاح...</span>');
		$.post(STI.ajaxUrl, { action: 'sti_fix_gfx_urls', nonce: STI.nonce })
		.done(function (res) { $r.html(res && res.success ? '<span style="color:#16a34a">✅ ' + res.data.message + '</span>' : '<span style="color:#dc2626">❌ ' + (res.data && res.data.message) + '</span>'); })
		.fail(function () { $r.html('<span style="color:#dc2626">❌ خطای ارتباط.</span>'); })
		.always(function () { $b.prop('disabled', false); });
	});
});
</script>
