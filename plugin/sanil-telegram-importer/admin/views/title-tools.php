<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
if ( ! class_exists( 'STI_Title_Engine' ) ) {
	echo '<div class="wrap"><div class="notice notice-error"><p><strong>Golden Importer:</strong> موتور عنوان بارگذاری نشده است. اگر PHP هاست کمتر از ۷.۴ است یا فایل‌های افزونه کامل آپلود نشده‌اند، این بخش خاموش می‌ماند؛ بقیه‌ی افزونه سالم کار می‌کند.</p></div></div>';
	return;
}

$terms   = STI_Category::get_woo_terms();
$rules   = STI_Title_Engine::rules();
$preview = get_transient( 'sti_title_replace_preview_' . get_current_user_id() );
$ai_ready = class_exists( 'STI_AI' ) && STI_AI::is_ready();
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">

		<div class="sti-hero">
			<div>
				<span class="sti-eyebrow">TITLE STUDIO</span>
				<h1>استودیوی عنوان</h1>
				<p>عنوان محصول = آن چیزی که <strong>باید</strong> باشد، نه آن چیزی که در متن خام تلگرام آمده. الگوی هدف: <code>دانلود موکاپ لایه باز فنجان قهوه</code></p>
			</div>
			<div class="sti-hero-actions">
				<span class="sti-health-pill <?php echo $ai_ready ? 'on' : 'off'; ?>"><?php echo $ai_ready ? 'هوش مصنوعی آماده' : 'هوش مصنوعی تنظیم نشده'; ?></span>
				<a class="sti-btn secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-ai' ) ); ?>">تنظیم سرویس‌ها</a>
			</div>
		</div>

		<div class="sti-tabs" id="ts-tabs">
			<button class="active" data-tab="lab">کارگاه زنده</button>
			<button data-tab="bulk">اسکن و اصلاح انبوه</button>
			<button data-tab="rules">قانون و الگو</button>
			<button data-tab="lex">لغت‌نامه</button>
			<button data-tab="replace">جایگزینی دستی</button>
		</div>

		<!-- ============ کارگاه زنده ============ -->
		<div class="sti-tabpane active" id="pane-lab">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>🔬 کارگاه زنده</h2><p>نام خام تلگرام را بچسبان و ببین موتور چه عنوانی می‌سازد — قبل از این‌که به هزار محصول اعمال شود.</p></div></div>
				<div class="sti-grid g3">
					<div class="sti-field"><label>نام خام فایل (کپشن تلگرام)</label><input type="text" id="lab-name" dir="ltr" placeholder="apron #mockup barista pouring milk create latte art coffee cup"></div>
					<div class="sti-field"><label>نوع فایل</label><input type="text" id="lab-type" dir="ltr" placeholder="PSD"></div>
					<div class="sti-field"><label>دسته‌بندی</label><input type="text" id="lab-cat" placeholder="موکاپ"></div>
				</div>
				<label class="sti-toggle"><input type="checkbox" id="lab-ai" <?php checked( $ai_ready ); ?>> مقایسه با خروجی هوش مصنوعی</label>
				<div style="margin:12px 0;"><button type="button" class="sti-btn" id="lab-run">✨ بساز</button></div>
				<div id="lab-out"></div>
			</div>
		</div>

		<!-- ============ انبوه ============ -->
		<div class="sti-tabpane" id="pane-bulk">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>🧠 اسکن و اصلاح انبوه</h2><p>هر عنوان امتیاز کیفیت می‌گیرد (۰ تا ۱۰۰). می‌توانی پیشنهاد را دستی ویرایش کنی، تک‌تک یا یکجا اعمال کنی و هر زمان به نسخه‌ی قبلی برگردانی.</p></div></div>
				<div class="sti-grid g4">
					<div class="sti-field">
						<label>دسته‌بندی ووکامرس</label>
						<select id="b-term"><option value="0">همه</option>
							<?php foreach ( $terms as $t ) : ?><option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="sti-field">
						<label>وضعیت</label>
						<select id="b-status"><option value="any">همه</option><option value="draft">پیش‌نویس</option><option value="publish">منتشرشده</option></select>
					</div>
					<div class="sti-field"><label>تعداد در هر دور</label><input type="number" id="b-limit" min="5" max="100" value="25"></div>
					<div class="sti-field"><label>آستانه‌ی مشکل‌دار (امتیاز کمتر از)</label><input type="number" id="b-min" min="0" max="100" value="85"></div>
				</div>
				<div class="sti-grid g3">
					<label class="sti-toggle"><input type="checkbox" id="b-only" checked> فقط عنوان‌های مشکل‌دار</label>
					<label class="sti-toggle"><input type="checkbox" id="b-hide" checked> پنهان کردن بازبینی‌شده‌ها</label>
					<label class="sti-toggle"><input type="checkbox" id="b-ai" <?php checked( $ai_ready ); ?>> پیشنهاد با هوش مصنوعی</label>
					<label class="sti-toggle"><input type="checkbox" id="b-desc"> توضیحات هم بازنویسی شود</label>
					<label class="sti-toggle"><input type="checkbox" id="b-seo" checked> متای سئو (Yoast / Rank Math) نوشته شود</label>
				</div>
				<div style="display:flex;flex-wrap:wrap;gap:10px;margin:14px 0;">
					<button type="button" class="sti-btn" id="b-scan">🔎 اسکن</button>
					<button type="button" class="sti-btn secondary" id="b-more" disabled>⬇️ ادامه‌ی اسکن</button>
					<button type="button" class="sti-btn" id="b-apply" disabled>💾 اعمال انتخاب‌شده‌ها</button>
					<button type="button" class="sti-btn secondary" id="b-revert" disabled>↩️ بازگردانی انتخاب‌شده‌ها</button>
				</div>
				<div class="sti-progress" id="b-progress" style="display:none;"><span></span></div>
				<div id="b-result" class="sti-inline-result"></div>
				<div class="sti-table-wrap">
					<table class="sti-table" id="b-table">
						<thead><tr>
							<th style="width:32px;"><input type="checkbox" id="b-all"></th>
							<th>محصول</th><th>عنوان فعلی</th><th>پیشنهاد (قابل ویرایش)</th><th>امتیاز</th><th>مشکلات</th>
						</tr></thead>
						<tbody><tr><td colspan="6">برای شروع «اسکن» را بزن.</td></tr></tbody>
					</table>
				</div>
			</div>
		</div>

		<!-- ============ قوانین ============ -->
		<div class="sti-tabpane" id="pane-rules">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>📐 قانون و الگوی عنوان</h2><p>این قوانین روی همه‌ی عنوان‌های تولیدی (واردات کانال، ربات، اصلاح انبوه) اعمال می‌شود.</p></div></div>
				<div class="sti-grid g3">
					<div class="sti-field"><label>پیشوند ثابت</label><input type="text" id="r-prefix" value="<?php echo esc_attr( $rules['prefix'] ); ?>"></div>
					<div class="sti-field">
						<label>الگوی عنوان</label>
						<input type="text" id="r-pattern" value="<?php echo esc_attr( $rules['pattern'] ); ?>">
						<div class="hint"><code>{prefix}</code> · <code>{type}</code> نوع · <code>{modifier}</code> کیفیت · <code>{subject}</code> موضوع</div>
					</div>
					<div class="sti-field"><label>بیشینه‌ی کلمات</label><input type="number" id="r-maxw" min="4" value="<?php echo (int) $rules['max_words']; ?>"></div>
					<div class="sti-field"><label>کمینه‌ی کاراکتر</label><input type="number" id="r-minc" min="10" value="<?php echo (int) $rules['min_chars']; ?>"></div>
					<div class="sti-field"><label>بیشینه‌ی کاراکتر (سئو)</label><input type="number" id="r-maxc" min="30" value="<?php echo (int) $rules['max_chars']; ?>"></div>
				</div>
				<div class="sti-grid g2">
					<div class="sti-field">
						<label class="sti-toggle"><input type="checkbox" id="r-useai" <?php checked( ! empty( $rules['use_ai'] ) ); ?>> استفاده از هوش مصنوعی</label>
						<label class="sti-toggle"><input type="checkbox" id="r-aifirst" <?php checked( ! empty( $rules['ai_first'] ) ); ?>> خروجی هوش مصنوعی اولویت داشته باشد</label>
						<label class="sti-toggle"><input type="checkbox" id="r-unique" <?php checked( ! empty( $rules['enforce_unique'] ) ); ?>> جلوگیری از عنوان تکراری در سایت</label>
						<label class="sti-toggle"><input type="checkbox" id="r-latin" <?php checked( ! empty( $rules['strip_latin'] ) ); ?>> حذف کلمات انگلیسی از عنوان</label>
						<label class="sti-toggle"><input type="checkbox" id="r-appendtype" <?php checked( ! empty( $rules['append_type_word'] ) ); ?>> اگر نوع در عنوان نبود، اضافه شود</label>
					</div>
					<div class="sti-field">
						<label>کلمات ممنوعه (هر خط یکی)</label>
						<textarea id="r-banned" style="min-height:170px;"><?php echo esc_textarea( $rules['banned'] ); ?></textarea>
					</div>
				</div>
				<button type="button" class="sti-btn" id="r-save">💾 ذخیره‌ی قوانین</button>
				<div id="r-result" class="sti-inline-result"></div>
			</div>
		</div>

		<!-- ============ لغت‌نامه ============ -->
		<div class="sti-tabpane" id="pane-lex">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>📚 لغت‌نامه‌ی اختصاصی</h2><p>هر واژه‌ای که موتور اشتباه ترجمه می‌کند را همین‌جا تعریف کن. این‌ها بر لغت‌نامه‌ی داخلی اولویت دارند.</p></div></div>
				<?php
				$glossary = STI_Title_Engine::parse_pairs( isset( $rules['custom_glossary'] ) ? $rules['custom_glossary'] : '' );
				$replaces = STI_Title_Engine::parse_pairs( isset( $rules['replacements'] ) ? $rules['replacements'] : '' );
				?>
				<div class="sti-grid g2">
					<div>
						<h3 style="font-size:13px;">انگلیسی ← فارسی</h3>
						<div class="sti-row">
							<div class="sti-field"><input type="text" id="lx-key" dir="ltr" placeholder="tote bag"></div>
							<div class="sti-field"><input type="text" id="lx-val" placeholder="ساک خرید"></div>
						</div>
						<button type="button" class="sti-btn secondary lx-add" data-kind="glossary">➕ افزودن</button>
						<div class="sti-table-wrap" style="margin-top:12px;">
							<table class="sti-table"><tbody>
							<?php foreach ( (array) $glossary as $en => $fa ) : ?>
								<tr><td class="sti-mono"><?php echo esc_html( $en ); ?></td><td><?php echo esc_html( $fa ); ?></td>
								<td><button type="button" class="sti-btn danger lx-del" data-kind="glossary" data-key="<?php echo esc_attr( $en ); ?>">حذف</button></td></tr>
							<?php endforeach; ?>
							</tbody></table>
						</div>
					</div>
					<div>
						<h3 style="font-size:13px;">اصلاح نهایی فارسی ← فارسی</h3>
						<div class="sti-row">
							<div class="sti-field"><input type="text" id="rp-key" placeholder="مکاپ"></div>
							<div class="sti-field"><input type="text" id="rp-val" placeholder="موکاپ"></div>
						</div>
						<button type="button" class="sti-btn secondary lx-add" data-kind="replace">➕ افزودن</button>
						<div class="sti-table-wrap" style="margin-top:12px;">
							<table class="sti-table"><tbody>
							<?php foreach ( (array) $replaces as $bad => $good ) : ?>
								<tr><td><?php echo esc_html( $bad ); ?></td><td><?php echo esc_html( $good ); ?></td>
								<td><button type="button" class="sti-btn danger lx-del" data-kind="replace" data-key="<?php echo esc_attr( $bad ); ?>">حذف</button></td></tr>
							<?php endforeach; ?>
							</tbody></table>
						</div>
					</div>
				</div>
				<hr>
				<h3 style="font-size:13px;">پشتیبان‌گیری</h3>
				<div style="display:flex;gap:10px;flex-wrap:wrap;">
					<button type="button" class="sti-btn secondary" id="lx-export">⬇️ خروجی JSON</button>
					<button type="button" class="sti-btn secondary" id="lx-import-btn">⬆️ ورود از JSON</button>
				</div>
				<textarea id="lx-json" class="sti-mono" style="width:100%;min-height:140px;margin-top:10px;" placeholder="محتوای JSON را اینجا بچسبان"></textarea>
				<div id="lx-result" class="sti-inline-result"></div>
			</div>
		</div>

		<!-- ============ جایگزینی دستی (سازگار با نسخه‌ی قبل) ============ -->
		<div class="sti-tabpane" id="pane-replace">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>🔤 جست‌وجو و جایگزینی</h2><p>برای اصلاح‌های ساده در همه‌ی عنوان‌ها.</p></div></div>
				<?php settings_errors( 'sti' ); ?>
				<form method="post">
					<?php wp_nonce_field( 'sti_save_settings', 'sti_nonce' ); ?>
					<input type="hidden" name="sti_form" value="title_replace">
					<div class="sti-row">
						<div class="sti-field"><label>متن مورد جست‌وجو</label><input required type="text" name="find_text" placeholder="مثلاً مکاپ"></div>
						<div class="sti-field"><label>متن جایگزین</label><input type="text" name="replace_text" placeholder="مثلاً موکاپ"></div>
					</div>
					<div class="sti-field">
						<label>محدود به دسته</label>
						<select name="woo_term_id"><option value="0">همه</option>
							<?php foreach ( $terms as $t ) : ?><option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?>
						</select>
					</div>
					<div style="display:flex;gap:10px;flex-wrap:wrap;">
						<button class="sti-btn secondary" name="title_replace_mode" value="preview" type="submit">🔎 پیش‌نمایش</button>
						<button class="sti-btn" name="title_replace_mode" value="apply" type="submit">✅ اعمال</button>
					</div>
				</form>
				<?php if ( ! empty( $preview ) && is_array( $preview ) ) : ?>
					<div class="sti-table-wrap" style="margin-top:14px;">
						<table class="sti-table"><thead><tr><th>محصول</th><th>فعلی</th><th>بعد از تغییر</th></tr></thead><tbody>
						<?php foreach ( array_slice( $preview, 0, 50 ) as $row ) : ?>
							<tr><td>#<?php echo (int) ( $row['id'] ?? 0 ); ?></td><td><?php echo esc_html( $row['old'] ?? '' ); ?></td><td><?php echo esc_html( $row['new'] ?? '' ); ?></td></tr>
						<?php endforeach; ?>
						</tbody></table>
					</div>
				<?php endif; ?>
			</div>
		</div>

		</div>
	</div>
</div>

<script>
jQuery(function ($) {
	var A = window.STI || {};
	var rows = [];
	var offset = 0;

	function post(action, data, cb) {
		$.post(A.ajaxUrl, $.extend({ action: action, nonce: A.nonce }, data || {}), cb)
			.fail(function () { cb({ success: false, data: { message: 'خطای شبکه' } }); });
	}
	function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
	function scoreBadge(n) {
		var cls = n >= 85 ? 's-good' : (n >= 60 ? 's-ok' : 's-bad');
		return '<span class="sti-score ' + cls + '">' + n + '</span>';
	}
	function say($el, res) {
		var ok = res && res.success;
		$el.html('<div class="' + (ok ? 'sti-ok' : 'sti-err') + '">' + esc((res.data && res.data.message) || (ok ? 'انجام شد' : 'خطا')) + '</div>');
	}

	$('#ts-tabs button').on('click', function () {
		$('#ts-tabs button').removeClass('active');
		$(this).addClass('active');
		$('.sti-tabpane').removeClass('active');
		$('#pane-' + $(this).data('tab')).addClass('active');
	});

	/* ---------- کارگاه زنده ---------- */
	$('#lab-run').on('click', function () {
		var $b = $(this).prop('disabled', true).text('در حال ساخت…');
		post('sti_ts_preview', {
			file_name: $('#lab-name').val(), file_type: $('#lab-type').val(),
			category_label: $('#lab-cat').val(), use_ai: $('#lab-ai').is(':checked') ? 1 : 0
		}, function (res) {
			$b.prop('disabled', false).text('✨ بساز');
			if (!res || !res.success) { $('#lab-out').html('<div class="sti-err">' + esc((res.data && res.data.message) || 'خطا') + '</div>'); return; }
			var d = res.data, html = '';
			html += '<div class="sti-grid g2">';
			var cat = d.category || { label: 'تشخیص داده نشد', confidence: 0, top_scores: [] };
			var catRows = (cat.top_scores || []).map(function (r) { return '<span>' + esc(r.label) + ': ' + r.score + '</span>'; }).join('');
			html += '<div class="sti-kpi warn"><div class="k-top"><span>دسته‌بندی اتوکت</span>' + scoreBadge(cat.confidence || 0) + '</div><div style="font-size:17px;font-weight:800;margin-top:8px;">' + esc(cat.label || 'تشخیص داده نشد') + '</div><div class="k-sub">داور: ' + esc(cat.judge || 'قوانین') + '</div><div class="sti-chiplist" style="margin-top:8px;">' + catRows + '</div></div>';

			html += '<div class="sti-kpi info"><div class="k-top"><span>خروجی قانون‌ها</span>' + scoreBadge(d.rules.score) + '</div><div style="font-size:16px;font-weight:800;margin-top:8px;">' + esc(d.rules.title) + '</div><div class="k-sub">نوع: ' + esc(d.rules.type_label || '—') + (d.rules.untranslated && d.rules.untranslated.length ? ' · ترجمه‌نشده: ' + esc(d.rules.untranslated.join(', ')) : '') + '</div></div>';
			if (d.ai) {
				html += '<div class="sti-kpi ok"><div class="k-top"><span>خروجی هوش مصنوعی</span>' + scoreBadge(d.ai.score) + '</div><div style="font-size:16px;font-weight:800;margin-top:8px;">' + esc(d.ai.title) + '</div><div class="k-sub">' + esc(d.ai.provider || '') + '</div></div>';
			} else if (d.ai_error) {
				html += '<div class="sti-kpi bad"><div class="k-top"><span>هوش مصنوعی</span></div><div class="k-sub" style="margin-top:10px;">' + esc(d.ai_error) + '</div></div>';
			}
			html += '</div>';
			html += '<div class="sti-panel" style="margin-top:14px;"><div class="sti-panel-head"><div><h2>عنوان نهایی</h2></div>' + scoreBadge(d.final.score) + '</div>';
			html += '<div style="font-size:20px;font-weight:800;">' + esc(d.final.title) + '</div>';
			if (d.final.issues && d.final.issues.length) {
				html += '<div class="sti-chiplist" style="margin-top:10px;">' + d.final.issues.map(function (i) { return '<span>' + esc(i) + '</span>'; }).join('') + '</div>';
			}
			html += '<table class="sti-table" style="margin-top:12px;"><tbody>';
			html += '<tr><td>متای عنوان</td><td>' + esc(d.seo.seo_title) + '</td></tr>';
			html += '<tr><td>متای توضیحات</td><td>' + esc(d.seo.meta_description) + '</td></tr>';
			html += '<tr><td>کلیدواژه</td><td>' + esc(d.seo.focus_keyword) + '</td></tr>';
			html += '</tbody></table></div>';
			$('#lab-out').html(html);
		});
	});

	/* ---------- انبوه ---------- */
	function render() {
		if (!rows.length) {
			$('#b-table tbody').html('<tr><td colspan="6">موردی پیدا نشد — یعنی عنوان‌ها سالم‌اند یا فیلترها را سخت‌گیرانه گذاشتی.</td></tr>');
			$('#b-apply, #b-revert').prop('disabled', true);
			return;
		}
		var html = rows.map(function (r, i) {
			return '<tr data-i="' + i + '">' +
				'<td><input type="checkbox" class="b-row" checked></td>' +
				'<td><a href="' + esc(r.edit_url) + '" target="_blank">#' + r.id + '</a><br><small>' + esc(r.category || '') + ' · ' + esc(r.status) + '</small></td>' +
				'<td>' + esc(r.old) + '<br>' + scoreBadge(r.old_score) + '</td>' +
				'<td><input class="sti-editable b-new" value="' + esc(r.new) + '"><small>' + esc(r.source) + '</small></td>' +
				'<td>' + scoreBadge(r.new_score) + '</td>' +
				'<td><div class="sti-chiplist">' + (r.issues || []).map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div></td>' +
				'</tr>';
		}).join('');
		$('#b-table tbody').html(html);
		$('#b-apply, #b-revert').prop('disabled', false);
	}

	function scan(append) {
		var $b = $('#b-scan, #b-more').prop('disabled', true);
		$('#b-progress').show().find('span').css('width', '35%');
		post('sti_ts_scan', {
			woo_term_id: $('#b-term').val(), post_status: $('#b-status').val(),
			limit: $('#b-limit').val(), offset: append ? offset : 0,
			only_problems: $('#b-only').is(':checked') ? 1 : 0,
			hide_reviewed: $('#b-hide').is(':checked') ? 1 : 0,
			min_score: $('#b-min').val(),
			use_ai: $('#b-ai').is(':checked') ? 1 : 0
		}, function (res) {
			$('#b-scan').prop('disabled', false);
			$('#b-progress').find('span').css('width', '100%');
			setTimeout(function () { $('#b-progress').hide().find('span').css('width', '0'); }, 400);
			if (!res || !res.success) { say($('#b-result'), res); return; }
			var d = res.data;
			rows = append ? rows.concat(d.items) : d.items;
			offset = d.next_offset;
			$('#b-more').prop('disabled', !!d.done);
			$('#b-result').html('<div class="sti-ok">' + rows.length + ' مورد نیازمند اصلاح از ' + d.total + ' محصول بررسی‌شده</div>');
			render();
		});
	}
	$('#b-scan').on('click', function () { scan(false); });
	$('#b-more').on('click', function () { scan(true); });
	$('#b-all').on('change', function () { $('.b-row').prop('checked', $(this).is(':checked')); });

	function selected() {
		var out = [];
		$('#b-table tbody tr').each(function () {
			var $tr = $(this);
			if (!$tr.find('.b-row').is(':checked')) { return; }
			var r = rows[$tr.data('i')];
			if (!r) { return; }
			out.push({ id: r.id, title: $tr.find('.b-new').val() });
		});
		return out;
	}

	$('#b-apply').on('click', function () {
		var sel = selected();
		if (!sel.length) { alert('موردی انتخاب نشده.'); return; }
		if (!confirm(sel.length + ' عنوان تغییر می‌کند. نسخه‌ی قبلی ذخیره می‌شود و قابل بازگردانی است. ادامه؟')) { return; }
		var $b = $(this).prop('disabled', true).text('در حال اعمال…');
		post('sti_ts_apply', {
			rows: sel,
			sync_description: $('#b-desc').is(':checked') ? 1 : 0,
			write_seo: $('#b-seo').is(':checked') ? 1 : 0
		}, function (res) {
			$b.prop('disabled', false).text('💾 اعمال انتخاب‌شده‌ها');
			say($('#b-result'), res);
			if (res && res.success) { scan(false); }
		});
	});

	$('#b-revert').on('click', function () {
		var ids = selected().map(function (r) { return r.id; });
		if (!ids.length) { return; }
		post('sti_ts_revert', { ids: ids }, function (res) { say($('#b-result'), res); scan(false); });
	});

	/* ---------- قوانین ---------- */
	$('#r-save').on('click', function () {
		post('sti_ts_save_rules', {
			prefix: $('#r-prefix').val(), pattern: $('#r-pattern').val(),
			max_words: $('#r-maxw').val(), min_chars: $('#r-minc').val(), max_chars: $('#r-maxc').val(),
			use_ai: $('#r-useai').is(':checked') ? 1 : 0,
			ai_first: $('#r-aifirst').is(':checked') ? 1 : 0,
			enforce_unique: $('#r-unique').is(':checked') ? 1 : 0,
			strip_latin: $('#r-latin').is(':checked') ? 1 : 0,
			append_type_word: $('#r-appendtype').is(':checked') ? 1 : 0,
			banned: $('#r-banned').val()
		}, function (res) { say($('#r-result'), res); });
	});

	/* ---------- لغت‌نامه ---------- */
	$('.lx-add').on('click', function () {
		var kind = $(this).data('kind');
		var key = kind === 'replace' ? $('#rp-key').val() : $('#lx-key').val();
		var val = kind === 'replace' ? $('#rp-val').val() : $('#lx-val').val();
		post('sti_ts_lexicon', { do: 'add', kind: kind, key: key, value: val }, function (res) {
			if (res && res.success) { location.reload(); } else { say($('#lx-result'), res); }
		});
	});
	$('.lx-del').on('click', function () {
		post('sti_ts_lexicon', { do: 'del', kind: $(this).data('kind'), key: $(this).data('key') }, function () { location.reload(); });
	});
	$('#lx-export').on('click', function () {
		post('sti_ts_export', {}, function (res) {
			if (res && res.success) {
				$('#lx-json').val(res.data.json);
				$('#lx-result').html('<div class="sti-ok">خروجی آماده شد — متن پایین را کپی و جایی ذخیره کن.</div>');
			} else { say($('#lx-result'), res); }
		});
	});
	$('#lx-import-btn').on('click', function () {
		post('sti_ts_import', { json: $('#lx-json').val(), merge: 1 }, function (res) { say($('#lx-result'), res); });
	});
});
</script>
