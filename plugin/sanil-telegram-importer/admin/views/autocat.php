<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

STI_AutoCat::install();
$categories = STI_AutoCat::get_main_categories_definition();
$kw_stats = STI_AutoCat::get_all_keywords_grouped();
$total_keywords = array_sum( array_map( 'count', $kw_stats ) );
$learning = STI_AutoCat::get_learning_suggestions(1);
$logs = array();
global $wpdb;
$log_table = STI_AutoCat::table_logs();
$logs = $wpdb->get_results( "SELECT * FROM {$log_table} ORDER BY id DESC LIMIT 20", ARRAY_A );
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">

			<div class="sti-hero">
				<div>
					<span class="sti-eyebrow">AUTOCAT — اتوکت</span>
					<h1>🤖 سیستم دسته‌بندی هوشمند اتوکت</h1>
					<p>تشخیص دسته اصلی بر اساس دیکشنری عظیم، قانون طلایی «فرمت هرگز دسته اصلی نیست»، امتیازدهی، اولویت و Confidence</p>
				</div>
				<div class="sti-hero-actions">
					<span class="sti-badge" style="background:#fff;color:#4f46e5;font-size:13px;padding:8px 14px;">📚 <?php echo (int) $total_keywords; ?> کلیدواژه</span>
					<span class="sti-badge" style="background:rgba(255,255,255,.15);color:#fff;font-size:13px;padding:8px 14px;">🎯 <?php echo count( $categories ); ?> دسته اصلی</span>
				</div>
			</div>

			<div class="sti-metric-grid">
				<div class="sti-metric blue"><div class="sti-metric-top"><span class="sti-chip indigo"><span class="dashicons dashicons-search"></span></span><span>کلیدواژه‌ها</span></div><strong><?php echo (int) $total_keywords; ?></strong><small>در دیتابیس + هاردکد</small></div>
				<div class="sti-metric purple"><div class="sti-metric-top"><span class="sti-chip violet"><span class="dashicons dashicons-lightbulb"></span></span><span>یادگیری</span></div><strong><?php echo count( $learning ); ?></strong><small>اصلاحات مدیر</small></div>
				<div class="sti-metric green"><div class="sti-metric-top"><span class="sti-chip green"><span class="dashicons dashicons-chart-bar"></span></span><span>لاگ تشخیص</span></div><strong><?php echo count( $logs ); ?></strong><small>آخرین تشخیص‌ها</small></div>
				<div class="sti-metric orange"><div class="sti-metric-top"><span class="sti-chip amber"><span class="dashicons dashicons-yes-alt"></span></span><span>دقت هدف</span></div><strong>95-99%</strong><small>با 3000-5000 کلمه</small></div>
			</div>

			<!-- تست زنده -->
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>🧪 تست زنده تشخیص</h2><p>عنوان فایل + نوع فایل رو وارد کن، نتیجه اتوکت رو ببین</p></div></div>
				<div class="sti-form-row">
					<div class="sti-field" style="grid-column: span 2;">
						<label>عنوان فایل (مثلاً business card mockup / flying thick abundant forest)</label>
						<input type="text" id="ac-test-title" placeholder="modern white minimalist kitchen design / business card mockup / logo design">
					</div>
				</div>
				<div class="sti-form-row">
					<div class="sti-field"><label>نوع فایل (اختیاری) مثل Vector, PSD, MP4, JPG</label><input type="text" id="ac-test-type" placeholder="Vector / PSD / MP4"></div>
					<div class="sti-field" style="justify-content:flex-end;"><button id="ac-test-btn" class="sti-btn">🔍 تشخیص</button></div>
				</div>
				<div id="ac-test-result" class="sti-inline-result" style="display:block;margin-top:12px;"></div>
			</div>

			<!-- مدیریت کلیدواژه‌ها -->
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>📚 دیکشنری کلیدواژه‌ها</h2><p>هر دسته شامل کلمات قطعی (100)، قوی (70-90)، کمکی (40-50)، منفی (-150) و اولویت</p></div>
					<button id="ac-add-kw-toggle" class="sti-btn secondary">➕ افزودن کلیدواژه</button>
				</div>

				<div id="ac-add-kw-form" style="display:none;background:#f8f9fc;border:1px solid #e8ebf2;border-radius:10px;padding:16px;margin-bottom:16px;">
					<div class="sti-form-row">
						<div class="sti-field"><label>دسته</label><select id="ac-kw-cat"><?php foreach ( $categories as $slug=>$def ) : ?><option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $def['label'] . ' (' . $def['label_fa'] . ')' ); ?></option><?php endforeach; ?></select></div>
						<div class="sti-field"><label>کلیدواژه</label><input type="text" id="ac-kw-word" placeholder="business card"></div>
					</div>
					<div class="sti-form-row">
						<div class="sti-field"><label>امتیاز</label><select id="ac-kw-score"><option value="100">100 - قطعی</option><option value="90">90 - خیلی قوی</option><option value="70" selected>70 - قوی</option><option value="50">50 - کمکی</option><option value="40">40 - Alias</option><option value="-150">-150 - منفی</option></select></div>
						<div class="sti-field"><label>نوع</label><select id="ac-kw-type"><option value="exact">exact</option><option value="strong">strong</option><option value="normal" selected>normal</option><option value="alias">alias</option><option value="negative">negative</option><option value="combined">combined (با +)</option></select></div>
					</div>
					<button id="ac-kw-save" class="sti-btn">💾 ذخیره</button>
					<span id="ac-kw-result" class="sti-inline-result"></span>
				</div>

				<div class="sti-table-wrap">
					<table class="sti-table">
						<thead><tr><th>دسته</th><th>کلیدواژه</th><th>امتیاز</th><th>نوع</th><th>عملیات</th></tr></thead>
						<tbody>
						<?php
						$kw_data = STI_AutoCat::get_keywords( '', '', 1, 100 );
						foreach ( $kw_data['rows'] as $kw ) :
						?>
							<tr>
								<td><span class="sti-badge"><?php echo esc_html( $kw['category_slug'] ); ?></span></td>
								<td><code><?php echo esc_html( $kw['keyword'] ); ?></code></td>
								<td><?php echo (int) $kw['score']; ?></td>
								<td><?php echo esc_html( $kw['type'] ); ?></td>
								<td><button class="sti-btn-sm danger ac-del-kw" data-id="<?php echo (int) $kw['id']; ?>">حذف</button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="desc" style="margin-top:10px;">نمایش ۱۰۰ تای آخر — برای مدیریت کامل از فیلتر دسته در آینده استفاده کن. دیکشنری اولیه عظیم (Mockup, Logo, Business Card, Flyer, Brochure, Poster, Banner, Text Effect, Infographic, PNG, Background, Texture, Pattern, Typography, Sticker, Mascot, Illustration, Icon, Flags, Social Media, UI Elements, Web Template و...) از قبل سید شده.</p>
			</div>

			<!-- یادگیری -->
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>🧠 یادگیری از اصلاحات مدیر</h2><p>هر بار مدیر دسته را اصلاح کند، سیستم ثبت می‌کند. اگر ۳ بار تکرار شود، پیشنهاد قانون جدید می‌دهد</p></div></div>
				<?php if ( empty( $learning ) ) : ?>
					<p class="sti-empty">هنوز اصلاحی ثبت نشده.</p>
				<?php else : ?>
					<div class="sti-table-wrap"><table class="sti-table"><thead><tr><th>عنوان</th><th>تشخیص داده شده</th><th>تصحیح مدیر</th><th>تعداد</th><th>آخرین بار</th></tr></thead><tbody>
						<?php foreach ( $learning as $l ) : ?>
							<tr><td><span class="sti-filename" title="<?php echo esc_attr( $l['title'] ); ?>"><?php echo esc_html( mb_substr( $l['title'], 0, 80 ) ); ?></span></td><td><code><?php echo esc_html( $l['detected_category'] ); ?></code></td><td><code style="color:#16a34a;"><?php echo esc_html( $l['correct_category'] ); ?></code></td><td><?php echo (int) $l['count']; ?></td><td><?php echo esc_html( $l['last_updated'] ); ?></td></tr>
						<?php endforeach; ?>
					</tbody></table></div>
				<?php endif; ?>
			</div>

			<!-- لاگ -->
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>📋 لاگ تشخیص‌های اخیر</h2><p>هر واردات از کانال اینجا لاگ می‌شود</p></div></div>
				<div class="sti-table-wrap"><table class="sti-table"><thead><tr><th>عنوان</th><th>نوع فایل</th><th>دسته تشخیص</th><th>فرمت</th><th>Confidence</th><th>زمان</th></tr></thead><tbody>
					<?php foreach ( $logs as $log ) : ?>
						<tr><td><span class="sti-filename"><?php echo esc_html( mb_substr( $log['title'], 0, 100 ) ); ?></span></td><td><?php echo esc_html( $log['file_type'] ); ?></td><td><span class="sti-badge"><?php echo esc_html( $log['detected_category'] ?: '—' ); ?></span></td><td><?php echo esc_html( $log['format_category'] ?: '—' ); ?></td><td><?php echo (int) $log['confidence']; ?>%</td><td style="white-space:nowrap;font-size:11px;"><?php echo esc_html( $log['created_at'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody></table></div>
			</div>


			<!-- ============ v7: دیکشنری، ورود/خروج و داور هوشمند ============ -->
			<div class="sti-panel">
				<div class="sti-panel-head">
					<div><h2>🧬 داور هوشمند و دیکشنری</h2>
					<p>اگر امتیاز کلیدواژه‌ها پایین بود یا دو دسته امتیاز نزدیک داشتند، هوش مصنوعی دسته را انتخاب می‌کند و کلیدواژه‌ی آن یاد گرفته می‌شود — دفعه‌ی بعد بدون هزینه‌ی توکن درست تشخیص می‌دهد.</p></div>
				</div>

				<div class="sti-grid g3">
					<div class="sti-field">
						<label class="sti-toggle"><input type="checkbox" id="ac-judge" <?php checked( (int) STI_Settings::get( 'autocat_ai_judge', 1 ) ); ?>> داور هوش مصنوعی روشن</label>
						<label class="sti-toggle"><input type="checkbox" id="ac-learn" <?php checked( (int) STI_Settings::get( 'autocat_auto_learn', 1 ) ); ?>> یادگیری خودکار کلیدواژه‌ها</label>
					</div>
					<div class="sti-field">
						<label>حداقل امتیاز قبولی دسته</label>
						<input type="number" id="ac-min" min="0" value="<?php echo (int) STI_Settings::get( 'autocat_min_score', 100 ); ?>">
						<div class="hint">پایین‌تر = پذیرش بیشتر ولی ریسک دسته‌ی اشتباه. با داور هوشمند می‌توانی این را روی ۱۰۰ نگه داری.</div>
					</div>
					<div class="sti-field">
						<label>&nbsp;</label>
						<button type="button" class="sti-btn" id="ac-save-judge">💾 ذخیره</button>
					</div>
				</div>

				<hr>
				<h3 style="font-size:13px;">افزودن گروهی کلیدواژه</h3>
				<div class="sti-grid g3">
					<div class="sti-field">
						<label>دسته</label>
						<select id="ac-bulk-slug">
							<?php foreach ( STI_AutoCat::get_main_categories_definition() as $slug => $def ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $def['label'] ?? $slug ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="sti-field"><label>امتیاز هر کلمه</label><input type="number" id="ac-bulk-score" value="70"></div>
					<div class="sti-field"><label>&nbsp;</label><button type="button" class="sti-btn secondary" id="ac-bulk-add">➕ افزودن</button></div>
				</div>
				<textarea id="ac-bulk-words" style="width:100%;min-height:110px;" dir="ltr" placeholder="هر خط یک کلیدواژه&#10;coffee cup&#10;paper cup&#10;barista"></textarea>

				<hr>
				<h3 style="font-size:13px;">پشتیبان دیکشنری</h3>
				<div style="display:flex;gap:10px;flex-wrap:wrap;">
					<button type="button" class="sti-btn secondary" id="ac-export">⬇️ خروجی JSON</button>
					<button type="button" class="sti-btn secondary" id="ac-import">⬆️ ورود از JSON</button>
				</div>
				<textarea id="ac-json" class="sti-mono" style="width:100%;min-height:130px;margin-top:10px;" placeholder="دیکشنری JSON را اینجا بچسبان"></textarea>
				<div id="ac-result" class="sti-inline-result"></div>

				<?php $decisions = class_exists( 'STI_AutoCat_Pro' ) ? STI_AutoCat_Pro::decisions( 15 ) : array(); ?>
				<?php if ( ! empty( $decisions ) ) : ?>
					<hr>
					<h3 style="font-size:13px;">آخرین تصمیم‌های داور هوشمند</h3>
					<div class="sti-table-wrap">
						<table class="sti-table"><thead><tr><th>عنوان</th><th>دسته‌ی انتخابی</th><th>دلیل</th><th>زمان</th></tr></thead><tbody>
						<?php foreach ( $decisions as $d ) : ?>
							<tr><td><?php echo esc_html( $d['title'] ); ?></td><td><strong><?php echo esc_html( $d['slug'] ); ?></strong></td><td><?php echo esc_html( $d['reason'] ); ?></td><td><?php echo esc_html( $d['at'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody></table>
					</div>
				<?php endif; ?>
			</div>

			<script>
			jQuery(function ($) {
				var A = window.STI || {};
				function post(action, data, cb) {
					$.post(A.ajaxUrl, $.extend({ action: action, nonce: A.nonce }, data || {}), cb)
						.fail(function () { cb({ success: false, data: { message: 'خطای شبکه' } }); });
				}
				function say(res) {
					var ok = res && res.success;
					$('#ac-result').html('<div class="' + (ok ? 'sti-ok' : 'sti-err') + '">' + ((res.data && res.data.message) || (ok ? 'انجام شد' : 'خطا')) + '</div>');
				}
				$('#ac-save-judge').on('click', function () {
					post('sti_autocat_settings_v7', {
						judge: $('#ac-judge').is(':checked') ? 1 : 0,
						learn: $('#ac-learn').is(':checked') ? 1 : 0,
						min_score: $('#ac-min').val()
					}, say);
				});
				$('#ac-bulk-add').on('click', function () {
					post('sti_ac_bulk_add', { slug: $('#ac-bulk-slug').val(), score: $('#ac-bulk-score').val(), words: $('#ac-bulk-words').val() }, say);
				});
				$('#ac-export').on('click', function () {
					post('sti_ac_export', {}, function (res) {
						if (res && res.success) { $('#ac-json').val(res.data.json); say({ success: true, data: { message: res.data.count + ' کلیدواژه در خروجی — کپی و ذخیره کن.' } }); }
						else { say(res); }
					});
				});
				$('#ac-import').on('click', function () {
					post('sti_ac_import', { json: $('#ac-json').val() }, say);
				});
			});
			</script>
		</div>
	</div>
</div>

<script>
jQuery(function($){
	'use strict';
	$('#ac-add-kw-toggle').on('click', function(){ $('#ac-add-kw-form').toggle(); });

	$('#ac-test-btn').on('click', function(){
		var $b=$(this), $r=$('#ac-test-result');
		var title=$('#ac-test-title').val(), type=$('#ac-test-type').val();
		if(!title){ $r.html('<span style="color:#dc2626">❌ عنوان را وارد کن</span>'); return; }
		$b.prop('disabled', true); $r.html('⏳ در حال تشخیص...');
		$.post(STI.ajaxUrl, {action:'sti_autocat_test', nonce:STI.nonce, title:title, file_type:type})
		.done(function(res){
			if(res && res.success){
				var d=res.data;
				var html='<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px;line-height:1.9;">';
				html+='<strong>دسته اصلی:</strong> <span class="sti-badge published">'+(d.main_category||'—')+' ('+(d.main_label||'—')+')</span> ';
				html+='<strong>فرمت:</strong> '+(d.format_category||'—')+' ';
				html+='<strong>Confidence:</strong> '+d.confidence+'%<br>';
				html+='<strong>زمان:</strong> '+d.elapsed_ms+'ms<br>';
				html+='<strong>همه امتیازها:</strong><br>';
				if(d.all_scores){
					for(var i=0;i<Math.min(8,d.all_scores.length);i++){
						var s=d.all_scores[i];
						if(s.score>0) html+= '<code>'+s.slug+':'+s.score+'</code> ';
					}
				}
				html+='<br><strong>کلمات مؤثر:</strong> ';
				if(d.matched_keywords && d.matched_keywords.length){
					for(var j=0;j<d.matched_keywords.length;j++){ html+='<code>'+d.matched_keywords[j].keyword+'('+d.matched_keywords[j].score+')</code> '; }
				}else{ html+='—'; }
				html+='</div>';
				$r.html(html);
			}else{ $r.html('<span style="color:#dc2626">❌ '+(res.data&&res.data.message||'خطا')+'</span>'); }
		}).fail(function(){ $r.html('<span style="color:#dc2626">❌ خطای ارتباط</span>'); }).always(function(){ $b.prop('disabled', false); });
	});

	$('#ac-kw-save').on('click', function(){
		var $b=$(this), $r=$('#ac-kw-result');
		$b.prop('disabled', true); $r.html('⏳ ذخیره...');
		$.post(STI.ajaxUrl, {action:'sti_autocat_add_keyword', nonce:STI.nonce, category_slug:$('#ac-kw-cat').val(), keyword:$('#ac-kw-word').val(), score:$('#ac-kw-score').val(), type:$('#ac-kw-type').val()})
		.done(function(res){ $r.html(res && res.success ? '<span style="color:#16a34a">✅ '+res.data.message+'</span>' : '<span style="color:#dc2626">❌ '+(res.data&&res.data.message||'خطا')+'</span>'); if(res && res.success) setTimeout(function(){location.reload();},800); })
		.fail(function(){ $r.html('<span style="color:#dc2626">❌ خطای ارتباط</span>'); }).always(function(){ $b.prop('disabled', false); });
	});

	$(document).on('click', '.ac-del-kw', function(){
		if(!confirm('حذف شود؟')) return;
		var id=$(this).data('id'), $b=$(this);
		$b.prop('disabled', true);
		$.post(STI.ajaxUrl, {action:'sti_autocat_delete_keyword', nonce:STI.nonce, id:id})
		.done(function(){ location.reload(); }).fail(function(){ $b.prop('disabled', false); });
	});
});
</script>
