<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

STI_GS_DB::install();
STI_GS_Profile_Ajax::instance();
$gs_channels = STI_GS_Channel::all( 100 );
$gs_selected_channel = isset( $_GET['channel_id'] ) ? (int) $_GET['channel_id'] : ( $gs_channels[0]['id'] ?? 0 );
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include dirname( __DIR__ ) . '/partials-tabs.php'; ?>
		<div class="sti-content">
			<div class="sti-header">
				<h1><span class="dashicons dashicons-filter"></span> گلدن اسکن | فاز ۲: پروفایل‌ها و فیلتر کلمه‌کلیدی</h1>
			</div>
			<?php include __DIR__ . '/partial-subnav.php'; ?>

			<div class="sti-info-box" style="margin-bottom:18px;">
				<strong>این مرحله فقط روی داده‌ی محلی کار می‌کند، به تلگرام مراجعه نمی‌شود.</strong>
				<p style="margin:7px 0 0;">یک پروفایل بساز، کلمات کلیدی بده، اجرا کن — نتیجه معمولاً چند ثانیه‌ای آماده می‌شود چون فقط یک کوئری روی پیام‌های همان کانال است. بعداً از داخل نتایج هر پروفایل، تعدادی پیام را برای صف انتشار انتخاب می‌کنی (فاز بعدی).</p>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>🧠 موتور تولید عنوان و توضیحات</h2><p>فقط برای محصولات گلدن‌اسکن؛ روی پایپ‌لاین قدیمی اثر ندارد.</p></div></div>
				<div class="sti-form-row">
					<div class="sti-field" style="grid-column:span 2;">
						<?php $gs_mode = STI_Settings::get( 'gs_content_generation_mode', 'existing' ); ?>
						<label><input type="radio" name="gs-content-mode" value="free" <?php checked( $gs_mode, 'free' ); ?>> موتور رایگان و قطعی — بدون AI، بدون هزینه</label><br>
						<label><input type="radio" name="gs-content-mode" value="sti_ai" <?php checked( $gs_mode, 'sti_ai' ); ?>> موتور هوش مصنوعی افزونه</label><br>
						<label><input type="radio" name="gs-content-mode" value="existing" <?php checked( $gs_mode, 'existing' ); ?>> موتور فعلی افزونه (پیش‌فرض)</label>
					</div>
				</div>
				<div class="sti-form-actions">
					<button id="gs-content-mode-save" class="sti-btn">ذخیره</button>
					<span id="gs-content-mode-result" class="sti-inline-result"></span>
				</div>
			</div>

			<?php if ( empty( $gs_channels ) ) : ?>
				<div class="sti-panel"><p>اول باید حداقل یک کانال در تب «کانال‌ها» اسکن کنی.</p></div>
			<?php else : ?>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>📡 انتخاب کانال</h2><p>پروفایل‌ها به یک کانال خاص وصل هستند.</p></div></div>
				<div class="sti-form-row">
					<div class="sti-field" style="grid-column:span 2;">
						<label>کانال</label>
						<select id="gs-p-channel" dir="ltr">
							<?php foreach ( $gs_channels as $c ) : ?>
								<option value="<?php echo (int) $c['id']; ?>" <?php selected( (int) $c['id'], $gs_selected_channel ); ?>>
									<?php echo esc_html( ( $c['title'] ?: $c['identifier'] ) . '  —  ' . STI_GS_Channel::message_count( (int) $c['id'] ) . ' پیام' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>➕ پروفایل جدید</h2><p>یک کلمه در هر خط (یا با کاما جدا کن).</p></div></div>
				<div class="sti-form-row">
					<div class="sti-field">
						<label>نام پروفایل</label>
						<input id="gs-p-name" placeholder="مثلاً: موکاپ‌های PSD">
					</div>
					<div class="sti-field">
						<label>حالت تطبیق</label>
						<select id="gs-p-mode">
							<option value="any">کافی‌ست یکی از کلمات باشد (any)</option>
							<option value="all">باید همه‌ی کلمات باشند (all)</option>
						</select>
					</div>
				</div>
				<div class="sti-form-row">
					<div class="sti-field" style="grid-column:span 2;">
						<label>کلمات کلیدی</label>
						<textarea id="gs-p-keywords" rows="4" placeholder="psd&#10;mockup&#10;photoshop"></textarea>
					</div>
				</div>
				<div class="sti-form-row">
					<div class="sti-field" style="grid-column:span 2;">
						<label>دسته‌ی پیش‌فرض ووکامرس (اختیاری — برای فاز ساخت محصول)</label>
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
				<div class="sti-form-actions">
					<button id="gs-p-create" class="sti-btn">➕ ساخت پروفایل</button>
					<span id="gs-p-create-result" class="sti-inline-result"></span>
				</div>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>🧩 پروفایل‌های این کانال</h2></div><button id="gs-p-refresh" class="sti-btn secondary">🔄 بروزرسانی</button></div>
				<div class="sti-table-wrap">
					<table class="sti-table widefat">
						<thead>
							<tr>
								<th>نام</th>
								<th>کلمات</th>
								<th>حالت</th>
								<th>تعداد match</th>
								<th>وضعیت</th>
								<th>عملیات</th>
							</tr>
						</thead>
						<tbody id="gs-profiles"><tr><td colspan="6" class="sti-empty">در حال بارگذاری...</td></tr></tbody>
					</table>
				</div>
				<div id="gs-p-samples" style="margin-top:14px;"></div>
			</div>

			<?php endif; ?>
		</div>
	</div>
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
					'<td><strong>' + esc(p.name) + '</strong></td>' +
					'<td>' + esc(kwCount) + ' کلمه</td>' +
					'<td>' + esc(p.match_mode) + '</td>' +
					'<td class="gs-p-count">' + esc(p.matched_count || 0) + '</td>' +
					'<td class="gs-p-status"><span class="sti-badge">' + esc(p.status) + '</span></td>' +
					'<td>' +
					'<button class="sti-btn-sm gs-p-run" data-id="' + p.id + '">▶ اجرا</button> ' +
					'<button class="sti-btn-sm secondary gs-p-samples" data-id="' + p.id + '">👁 نمونه‌ها</button> ' +
					'<button class="sti-btn-sm danger gs-p-delete" data-id="' + p.id + '">🗑</button>' +
					'</td></tr>';
			}

			function loadProfiles() {
				var ch = currentChannel();
				if (!ch) { return; }
				post('sti_gs_profile_list', { channel_id: ch }).done(function (r) {
					if (!r || !r.success) { return; }
					var profiles = r.data.profiles || [];
					if (!profiles.length) {
						$('#gs-profiles').html('<tr><td colspan="6" class="sti-empty">هنوز پروفایلی برای این کانال ساخته نشده.</td></tr>');
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
					var html = '<div class="sti-panel"><div class="sti-panel-head"><div><h2>👁 نمونه (' + items.length + ' از ' + esc(r.data.total) + ')</h2></div></div>';
					if (!items.length) {
						html += '<p class="sti-empty">هیچ موردی match نشد.</p>';
					} else {
						html += '<table class="sti-table widefat"><thead><tr><th>کد فایل</th><th>کلمه‌ی match‌شده</th><th>متن/دکمه</th><th>نوع</th><th>عملیات</th></tr></thead><tbody>';
						$.each(items, function (_, it) {
							var snippet = (it.text_raw || it.button_summary || it.file_name || '').toString().slice(0, 140);
							html += '<tr><td dir="ltr">' + esc(it.file_code || '—') + '</td><td>' + esc(it.matched_keyword) + '</td><td>' + esc(snippet) + '</td><td>' + esc(it.media_type || '—') + '</td>' +
								'<td><button class="sti-btn-sm gs-p-queue" data-id="' + it.profile_item_id + '">➕ صف</button></td></tr>';
						});
						html += '</tbody></table>';
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
