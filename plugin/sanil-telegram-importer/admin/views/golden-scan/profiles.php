<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

STI_GS_DB::install();
STI_GS_Profile_Ajax::instance();
$gs_channels = STI_GS_Channel::all( 100 );
$gs_selected_channel = isset( $_GET['channel_id'] ) ? (int) $_GET['channel_id'] : ( $gs_channels[0]['id'] ?? 0 );
?>
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">📡 پروفایل‌ها و فیلتر کلمه‌کلیدی</h1>
		<p class="gi-h1-sub">این مرحله فقط روی داده‌ی محلی کار می‌کند؛ به تلگرام مراجعه نمی‌شود. یک پروفایل بساز، کلمات کلیدی بده، اجرا کن — نتیجه معمولاً چند ثانیه‌ای آماده می‌شود. بعداً از داخل نتایج هر پروفایل، پیام‌ها را برای صف انتشار انتخاب می‌کنی.</p>
	</div>

	<?php if ( empty( $gs_channels ) ) : ?>
		<div class="gi-empty gi-mt-5" style="padding:var(--gi-s8) var(--gi-s5);">
			<div class="gi-empty-ico" aria-hidden="true">📡</div>
			<div class="gi-empty-title">اول باید حداقل یک کانال اسکن کنی.</div>
			<div class="gi-empty-sub">از تب «کانال‌ها» یک کانال ثبت و اسکن کن تا پروفایل‌سازی باز شود.</div>
			<a class="gi-btn gi-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan' ) ); ?>">رفتن به کانال‌ها</a>
		</div>
	<?php else : ?>

	<div class="gi-bento">

		<!-- Content generation mode -->
		<div class="gi-card gi-span-5">
			<div class="gi-card-head">
				<h2 class="gi-card-title">🧠 موتور تولید عنوان و توضیحات</h2>
				<span class="gi-card-sub">فقط برای محصولات گلدن‌اسکن؛ روی پایپ‌لاین قدیمی اثر ندارد</span>
			</div>
			<div class="gi-form-row">
				<?php $gs_mode = STI_Settings::get( 'gs_content_generation_mode', 'existing' ); ?>
				<label style="display:flex;gap:10px;align-items:center;min-height:40px;">
					<input type="radio" name="gs-content-mode" value="free" <?php checked( $gs_mode, 'free' ); ?>> موتور رایگان و قطعی — بدون AI، بدون هزینه
				</label>
				<label style="display:flex;gap:10px;align-items:center;min-height:40px;">
					<input type="radio" name="gs-content-mode" value="sti_ai" <?php checked( $gs_mode, 'sti_ai' ); ?>> موتور هوش مصنوعی افزونه
				</label>
				<label style="display:flex;gap:10px;align-items:center;min-height:40px;">
					<input type="radio" name="gs-content-mode" value="existing" <?php checked( $gs_mode, 'existing' ); ?>> موتور فعلی افزونه (پیش‌فرض)
				</label>
			</div>
			<div class="gi-flex" style="align-items:center;gap:var(--gi-s3);">
				<button id="gs-content-mode-save" class="gi-btn gi-btn--primary">ذخیره</button>
				<span id="gs-content-mode-result" class="gi-inline-res" role="status" aria-live="polite"></span>
			</div>
		</div>

		<!-- Channel select -->
		<div class="gi-card gi-span-7">
			<div class="gi-card-head">
				<h2 class="gi-card-title">📡 انتخاب کانال</h2>
				<span class="gi-card-sub">پروفایل‌ها به یک کانال خاص وصل هستند</span>
			</div>
			<div class="gi-form-row">
				<label for="gs-p-channel">کانال</label>
				<select id="gs-p-channel" dir="ltr" style="max-width:460px;">
					<?php foreach ( $gs_channels as $c ) : ?>
						<option value="<?php echo (int) $c['id']; ?>" <?php selected( (int) $c['id'], $gs_selected_channel ); ?>>
							<?php echo esc_html( ( $c['title'] ?: $c['identifier'] ) . '  —  ' . STI_GS_Channel::message_count( (int) $c['id'] ) . ' پیام' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<!-- New profile -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<h2 class="gi-card-title">➕ پروفایل جدید</h2>
				<span class="gi-card-sub">یک کلمه در هر خط (یا با کاما جدا کن)</span>
			</div>
			<div class="gi-form-row gi-form-row--grid">
				<div class="gi-field">
					<label for="gs-p-name">نام پروفایل</label>
					<input id="gs-p-name" placeholder="مثلاً: موکاپ‌های PSD">
				</div>
				<div class="gi-field">
					<label for="gs-p-mode">حالت تطبیق</label>
					<select id="gs-p-mode">
						<option value="any">کافی‌ست یکی از کلمات باشد (any)</option>
						<option value="all">باید همه‌ی کلمات باشند (all)</option>
					</select>
				</div>
			</div>
			<div class="gi-form-row">
				<div class="gi-field">
					<label for="gs-p-keywords">کلمات کلیدی</label>
					<textarea id="gs-p-keywords" rows="4" placeholder="psd&#10;mockup&#10;photoshop"></textarea>
				</div>
			</div>
			<div class="gi-form-row">
				<div class="gi-field">
					<label for="gs-p-category">دسته‌ی پیش‌فرض ووکامرس (اختیاری — برای فاز ساخت محصول)</label>
					<?php
					wp_dropdown_categories( array(
						'taxonomy'         => 'product_cat',
						'id'               => 'gs-p-category',
						'name'             => 'gs-p-category',
						'show_option_none' => '— بدون دسته‌ی پیش‌فرض —',
						'hide_empty'       => false,
					) );
					?>
				</div>
			</div>
			<div class="gi-flex" style="align-items:center;gap:var(--gi-s3);">
				<button id="gs-p-create" class="gi-btn gi-btn--primary">➕ ساخت پروفایل</button>
				<span id="gs-p-create-result" class="gi-inline-res" role="status" aria-live="polite"></span>
			</div>
		</div>

		<!-- Profiles list -->
		<div class="gi-card gi-card--flush gi-span-12">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) var(--gi-s3);">
				<div>
					<h2 class="gi-card-title">🧩 پروفایل‌های این کانال</h2>
				</div>
				<button id="gs-p-refresh" class="gi-btn gi-btn--subtle">⟳ بروزرسانی</button>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table gi-responsive">
					<thead>
						<tr>
							<th scope="col">نام</th>
							<th scope="col">کلمات</th>
							<th scope="col">حالت</th>
							<th scope="col">تعداد match</th>
							<th scope="col">وضعیت</th>
							<th scope="col">عملیات</th>
						</tr>
					</thead>
					<tbody id="gs-profiles"><tr><td colspan="6" class="sti-empty">در حال بارگذاری…</td></tr></tbody>
				</table>
			</div>
			<div id="gs-p-samples" class="gi-mt-5"></div>
		</div>

	</div>
	<?php endif; ?>
</div>
<script>
(function () {
	function boot() {
		jQuery(function ($) {
			'use strict';
			var A = window.STI || {};

			function esc(s) {
				return $('<div>').text(s == null ? '' : String(s)).html();
			}

			function post(action, data) {
				data = data || {};
				data.action = action;
				data.nonce = A.nonce;
				return $.post(A.ajaxUrl, data);
			}

			function currentChannel() {
				return $('#gs-p-channel').val();
			}

			function profileRow(p) {
				var kwCount = (p.keyword_list || []).length;
				return '' +
					'<tr data-id="' + p.id + '">' +
					'<td data-label="نام"><strong>' + esc(p.name) + '</strong></td>' +
					'<td data-label="کلمات" class="gi-nums">' + esc(kwCount) + ' کلمه</td>' +
					'<td data-label="حالت">' + esc(p.match_mode) + '</td>' +
					'<td data-label="تعداد match" class="gs-p-count gi-nums">' + esc(p.matched_count || 0) + '</td>' +
					'<td data-label="وضعیت" class="gs-p-status"><span class="sti-badge">' + esc(p.status) + '</span></td>' +
					'<td data-label="عملیات">' +
					'<button class="gi-btn gi-btn--subtle gs-p-run" data-id="' + p.id + '">▶ اجرا</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-p-samples" data-id="' + p.id + '">👁 نمونه‌ها</button> ' +
					'<button class="gi-btn gi-btn--ghost gs-p-delete" data-id="' + p.id + '" aria-label="حذف پروفایل">🗑</button>' +
					'</td></tr>';
			}

			function loadProfiles() {
				var ch = currentChannel();
				if (!ch) { return; }
				post('sti_gs_profile_list', { channel_id: ch }).done(function (r) {
					if (!r || !r.success) { return; }
					var profiles = r.data.profiles || [];
					if (!profiles.length) {
						$('#gs-profiles').html('<tr><td colspan="6">' +
							'<div class="gi-empty" style="padding:var(--gi-s6) var(--gi-s4);">' +
							'<div class="gi-empty-ico" aria-hidden="true">🧩</div>' +
							'<div class="gi-empty-title">هنوز پروفایلی برای این کانال ساخته نشده.</div>' +
							'<div class="gi-empty-sub">پایین صفحه نام، کلمات کلیدی و حالت تطبیق را بده و «ساخت پروفایل» بزن.</div></div></td>');
						return;
					}
					var html = '';
					$.each(profiles, function (_, p) { html += profileRow(p); });
					$('#gs-profiles').html(html);
				});
				$('#gs-p-samples').empty();
			}

			$('#gs-p-channel').on('change', loadProfiles);
			$('#gs-p-refresh').on('click', loadProfiles);

			$('#gs-p-create').on('click', function () {
				var $btn = $(this), $r = $('#gs-p-create-result');
				var name = $('#gs-p-name').val();
				var keywords = $('#gs-p-keywords').val();
				if (!name || !keywords) { $r.text('نام و کلمات کلیدی را پر کن.'); return; }
				$btn.prop('disabled', true);
				$r.text('در حال ثبت...');
				post('sti_gs_profile_create', {
					channel_id: currentChannel(),
					name: name,
					keywords: keywords,
					match_mode: $('#gs-p-mode').val(),
					default_category_id: $('#gs-p-category').val()
				}).done(function (r) {
					$r.text(r && r.success ? '✅ ' + r.data.message : '❌ ' + ((r.data && r.data.message) || 'خطا'));
					if (r && r.success) {
						$('#gs-p-name').val('');
						$('#gs-p-keywords').val('');
						loadProfiles();
					}
				}).fail(function () {
					$r.text('❌ خطای ارتباط');
				}).always(function () {
					$btn.prop('disabled', false);
				});
			});

			$(document).on('click', '.gs-p-run', function () {
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('در حال اجرا...');
				post('sti_gs_profile_run', { profile_id: id }).done(function (r) {
					if (r && r.success) {
						var $row = $('#gs-profiles tr[data-id="' + id + '"]');
						$row.find('.gs-p-count').text(r.data.profile.matched_count || 0);
						$row.find('.gs-p-status .sti-badge').text(r.data.profile.status);
					} else {
						window.alert((r.data && r.data.message) || 'خطا در اجرای فیلتر');
					}
				}).always(function () {
					$btn.prop('disabled', false).text('▶ اجرا');
				});
			});

			$(document).on('click', '.gs-p-samples', function () {
				var id = $(this).data('id');
				post('sti_gs_profile_samples', { profile_id: id }).done(function (r) {
					if (!r || !r.success) { return; }
					var items = r.data.items || [];
					var html = '<div class="gi-card"><div class="gi-card-head" style="padding:var(--gi-s4) var(--gi-s4) 0;"><h2 class="gi-card-title">👁 نمونه (' + items.length + ' از ' + esc(r.data.total) + ')</h2></div>';
					if (!items.length) {
						html += '<p class="sti-empty" style="margin-inline:var(--gi-s4);">هیچ موردی match نشد.</p>';
					} else {
						html += '<div class="gi-table-wrap" style="border:none;border-radius:0;margin:var(--gi-s3) var(--gi-s4) var(--gi-s4);"><table class="gi-table gi-responsive"><thead><tr><th>کد فایل</th><th>کلمه‌ی match‌شده</th><th>متن/دکمه</th><th>نوع</th><th>عملیات</th></tr></thead><tbody>';
						$.each(items, function (_, it) {
							var snippet = (it.text_raw || it.button_summary || it.file_name || '').toString().slice(0, 140);
							html += '<tr><td data-label="کد فایل" dir="ltr">' + esc(it.file_code || '—') + '</td><td data-label="کلمه">' + esc(it.matched_keyword) + '</td><td data-label="متن/دکمه">' + esc(snippet) + '</td><td data-label="نوع">' + esc(it.media_type || '—') + '</td>' +
								'<td data-label="عملیات"><button class="gi-btn gi-btn--subtle gs-p-queue" data-id="' + it.profile_item_id + '">➕ صف</button></td></tr>';
						});
						html += '</tbody></table></div>';
					}
					html += '</div>';
					$('#gs-p-samples').html(html);
				});
			});

			$(document).on('click', '.gs-p-delete', function () {
				var id = $(this).data('id');
				if (!window.confirm('این پروفایل حذف شود؟')) { return; }
				post('sti_gs_profile_delete', { id: id }).done(loadProfiles);
			});

			$(document).on('click', '.gs-p-queue', function () {
				var $btn = $(this);
				$btn.prop('disabled', true).text('...');
				post('sti_gs_session_create', { profile_item_id: $btn.data('id') }).done(function (r) {
					$btn.text(r && r.success ? '✅ اضافه شد' : '❌ خطا');
				});
			});

			$('#gs-content-mode-save').on('click', function () {
				var $btn = $(this), $r = $('#gs-content-mode-result');
				var mode = $('input[name="gs-content-mode"]:checked').val();
				$btn.prop('disabled', true);
				post('sti_gs_save_content_mode', { mode: mode }).done(function (r) {
					$r.text(r && r.success ? '✅ ذخیره شد' : '❌ خطا');
				}).always(function () { $btn.prop('disabled', false); });
			});

			loadProfiles();
		});
	}
	if (window.jQuery) { boot(); } else {
		var t = setInterval(function () { if (window.jQuery) { clearInterval(t); boot(); } }, 50);
	}
})();
</script>
