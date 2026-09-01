<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
STI_GoldTel::instance();
$categories = STI_Category::get_all();
$profiles = STI_GoldTel::instance()->profiles( 50 );
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">
			<div class="sti-header">
				<h1><span class="dashicons dashicons-admin-site-alt3"></span> گلدتل | مرکز کنترل واردات</h1>
				<a class="sti-btn secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-telegram' ) ); ?>">⚙️ تنظیمات تلگرام</a>
			</div>
			<div class="sti-info-box" style="margin-bottom:18px;">
				<strong>گلدتل دو مرحله‌ای است:</strong>
				<p style="margin:7px 0 0;">مرحله اول فقط تاریخچه و ساختار پیام‌ها را ایندکس می‌کند؛ هیچ فایل و محصولی ساخته نمی‌شود. بعد از پایان اسکن، روی دکمه «رکوردها» بزن، رکوردها را فیلتر/انتخاب کن و سپس به Dispatcher بفرست. خطای Fileech فقط همان Dispatch را متوقف می‌کند، نه اسکن Profile را.</p>
				<p style="margin:7px 0 0;color:#92400e;">برای کانال خصوصی، اکانت MTProto باید عضو کانال و لاگین شده باشد.</p>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>➕ ساخت Scan Profile</h2><p>هر Profile یک اسکن مستقل و قابل ادامه است.</p></div></div>
				<div class="sti-form-row">
					<div class="sti-field"><label>نام Profile</label><input id="gt-name" placeholder="اسکن UI8 امروز"></div>
					<div class="sti-field" style="grid-column:span 2;"><label>آدرس کانال/گروه</label><input id="gt-identifier" dir="ltr" placeholder="@FileechParty یا https://t.me/FileechParty"></div>
				</div>
				<div class="sti-form-row">
					<div class="sti-field"><label>کلمه کلیدی (اختیاری)</label><input id="gt-keyword" dir="ltr" placeholder="mockup یا UI kit"></div>
					<div class="sti-field"><label>دسته پایه (اختیاری)</label><select id="gt-category"><option value="">تشخیص با AutoCat</option><?php foreach ( $categories as $cat ) : ?><option value="<?php echo (int) $cat->id; ?>"><?php echo esc_html( $cat->telegram_label ); ?></option><?php endforeach; ?></select></div>
					<div class="sti-field"><label>حداکثر پیام (۰ = همه)</label><input id="gt-max" type="number" min="0" max="500000" value="0"></div>
				</div>
			<div class="sti-form-actions"><button id="gt-start" class="sti-btn">🔎 شروع اسکن فقط Metadata</button><button id="gt-process" class="sti-btn secondary">⚡ پردازش یک مرحله</button><span id="gt-result" class="sti-inline-result"></span></div>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>📚 Scan Profiles</h2><p>اسکن هیچ فایلی دانلود نمی‌کند.</p></div><button id="gt-refresh-profiles" class="sti-btn secondary">🔄 بروزرسانی</button></div>
				<div class="sti-table-wrap"><table class="sti-table widefat"><thead><tr><th>نام</th><th>کانال</th><th>کلمه</th><th>پیام</th><th>کاندیدا</th><th>ارسال</th><th>محصول</th><th>مرحله</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="gt-profiles"><tr><td colspan="10" class="sti-empty">در حال بارگذاری...</td></tr></tbody></table></div>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>🎛 فیلتر و Dispatcher</h2><p>فقط رکوردهای انتخاب‌شده به ربات ارسال می‌شوند.</p></div><span id="gt-selected" class="sti-badge">۰ انتخاب</span></div>
				<div class="sti-form-row">
					<div class="sti-field"><label>Profile</label><select id="gt-profile-select"><option value="">— انتخاب Profile —</option><?php foreach ( $profiles as $profile ) : ?><option value="<?php echo (int) $profile['id']; ?>"><?php echo esc_html( $profile['name'] . ' — ' . $profile['keyword'] ); ?></option><?php endforeach; ?></select></div>
					<div class="sti-field"><label>وضعیت ارسال</label><select id="gt-filter-dispatch"><option value="">همه</option><option value="not_sent">ارسال نشده</option><option value="queued">در صف ارسال</option><option value="sent">ارسال شده</option><option value="failed">خطادار</option></select></div>
					<div class="sti-field"><label>وضعیت محصول</label><select id="gt-filter-product"><option value="">همه</option><option value="not_created">ساخته نشده</option><option value="created">ساخته شده</option></select></div>
					<div class="sti-field"><label>دسته</label><select id="gt-filter-category"><option value="">همه</option><?php foreach ( $categories as $cat ) : ?><option value="<?php echo (int) $cat->id; ?>"><?php echo esc_html( $cat->telegram_label ); ?></option><?php endforeach; ?></select></div>
					<div class="sti-field"><label>نوع فایل</label><input id="gt-filter-type" dir="ltr" placeholder="PSD / ZIP"></div><div class="sti-field"><label>حداقل اطمینان</label><input id="gt-filter-confidence" type="number" min="0" max="100" placeholder="0"></div>
				</div>
			<div class="sti-form-actions"><button id="gt-load-records" class="sti-btn secondary">نمایش رکوردها</button><button id="gt-send-selected" class="sti-btn">⬇️ دانلود و ساخت محصول</button><button id="gt-search-inbox" class="sti-btn secondary">🔄 پردازش دوباره انتخاب‌شده‌ها</button></div>
			<div id="gt-dispatch-result" class="sti-inline-result"></div>
			<div class="sti-table-wrap" style="margin-top:14px;"><table class="sti-table widefat"><thead><tr><th><input type="checkbox" id="gt-check-all"></th><th>Message</th><th>File Code</th><th>نام/متن</th><th>دسته</th><th>Confidence</th><th>دکمه</th><th>Duplicate</th><th>ارسال</th><th>محصول</th><th>خطا</th></tr></thead><tbody id="gt-records"><tr><td colspan="11" class="sti-empty">یک Profile انتخاب کن.</td></tr></tbody></table></div>
			<div id="gt-pagination" class="sti-form-actions" style="margin-top:12px;"></div>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>📡 وضعیت Dispatcher</h2><p>اگر رکورد به محصول تبدیل نشود، دلیل دقیق اینجا نمایش داده می‌شود.</p></div></div>
				<div class="sti-table-wrap"><table class="sti-table widefat"><thead><tr><th>ID</th><th>Message</th><th>File/Title</th><th>روش</th><th>وضعیت</th><th>تلاش</th><th>خطای دقیق</th><th>عملیات</th></tr></thead><tbody id="gt-dispatches"><tr><td colspan="8" class="sti-empty">هنوز Dispatchای ثبت نشده.</td></tr></tbody></table></div>
			</div>
		</div>
	</div>
</div>
<script>
(function(){function boot(){jQuery(function($){'use strict';var A=window.STI||{},timer=null,currentPage=1;
function esc(s){return $('<div>').text(s==null?'':String(s)).html();}
var stages={resolve:'اتصال',scan:'اسکن تاریخچه',done:'پایان'};var statuses={queued:['در صف','queued'],running:['در حال اجرا','running'],indexed:['ایندکس شد','completed'],failed:['خطا','error'],cancelled:['لغو','cancelled']};
function active(p){return p&&['queued','running'].indexOf(p.status)>=0;}
function renderProfiles(ps){var h='',on=0;$.each(ps||[],function(_,p){if(active(p))on++;var s=statuses[p.status]||[p.status||'—',''];h+='<tr><td><strong>'+esc(p.name)+'</strong></td><td><code>'+esc(p.identifier)+'</code></td><td>'+esc(p.keyword||'—')+'</td><td>'+esc(p.total_messages||0)+'</td><td>'+esc(p.matched||0)+'</td><td>'+esc(p.sent||0)+'</td><td>'+esc(p.products_created||0)+'</td><td>'+esc(stages[p.stage]||p.stage)+'</td><td><span class="sti-badge '+s[1]+'">'+esc(s[0])+'</span>'+(p.last_error?'<div class="sti-error-note">'+esc(p.last_error)+'</div>':'')+'</td><td>'+(active(p)?'<button class="sti-btn-sm danger gt-cancel" data-id="'+p.id+'">لغو</button> ':'')+'<button class="sti-btn-sm gt-use" data-id="'+p.id+'">📋 رکوردها</button></td></tr>';});$('#gt-profiles').html(h||'<tr><td colspan="10" class="sti-empty">Profileی نیست.</td></tr>');if(on&&!timer)timer=setInterval(loadProfiles,5000);if(!on&&timer){clearInterval(timer);timer=null;}}
function loadProfiles(){if(!A.ajaxUrl)return;$.post(A.ajaxUrl,{action:'sti_goldtel_profile_poll',nonce:A.nonce}).done(function(r){if(r&&r.success)renderProfiles(r.data.profiles);});}
function selected(){var a=[];$('.gt-row-check:checked').each(function(){a.push($(this).val());});$('#gt-selected').text(a.length+' انتخاب');return a;}
function loadDispatches(){var pid=$('#gt-profile-select').val()||0;$.post(A.ajaxUrl,{action:'sti_goldtel_dispatches',nonce:A.nonce,profile_id:pid}).done(function(r){if(!r||!r.success)return;var h='';$.each(r.data.dispatches||[],function(_,d){h+='<tr><td>'+esc(d.id)+'</td><td>'+esc(d.source_message_id||'—')+'</td><td><strong>'+esc(d.content_title||d.file_name||'—')+'</strong><br><code>'+esc(d.file_code||'—')+'</code></td><td>'+esc(d.method||'—')+'</td><td>'+esc(d.status||'—')+'</td><td>'+esc(d.attempts||0)+'</td><td>'+esc(d.error_message||d.index_error||'—')+'</td><td>'+((d.status==='failed'||d.status==='retry'||d.status==='duplicate')?'<button class="sti-btn-sm gt-retry" data-id="'+d.id+'">Retry</button>':'—')+'</td></tr>';});$('#gt-dispatches').html(h||'<tr><td colspan="8" class="sti-empty">هنوز Dispatchای ثبت نشده.</td></tr>');});}
function loadRecords(page){var pid=$('#gt-profile-select').val();if(!pid){$('#gt-records').html('<tr><td colspan="11" class="sti-empty">یک Profile انتخاب کن.</td></tr>');return;}currentPage=page||1;$.post(A.ajaxUrl,{action:'sti_goldtel_records',nonce:A.nonce,profile_id:pid,page:currentPage,per_page:25,dispatch_status:$('#gt-filter-dispatch').val(),product_status:$('#gt-filter-product').val(),category_id:$('#gt-filter-category').val(),file_type:$('#gt-filter-type').val(),confidence_min:$('#gt-filter-confidence').val()}).done(function(r){if(!r||!r.success)return;var h='';$.each(r.data.rows||[],function(_,x){h+='<tr><td><input class="gt-row-check" type="checkbox" value="'+x.id+'"></td><td><code>'+esc(x.source_message_id)+'</code></td><td>'+esc(x.file_code||'—')+'</td><td><strong>'+esc(x.file_name||'—')+'</strong><br><small>'+esc((x.caption_raw||'').slice(0,90))+'</small></td><td>'+esc(x.autocat_category||x.category_id||'—')+'</td><td>'+esc(x.confidence||0)+'٪</td><td>'+esc(x.button_type||'—')+'</td><td>'+(x.is_duplicate?'🔁':'—')+'</td><td>'+esc(x.dispatch_status||'—')+'</td><td>'+esc(x.product_status||'—')+'</td><td>'+esc(x.last_error||'—')+'</td></tr>';});$('#gt-records').html(h||'<tr><td colspan="11" class="sti-empty">رکوردی پیدا نشد.</td></tr>');var p=r.data.pages||1,ph='';for(var i=1;i<=p;i++)ph+='<button class="sti-btn-sm gt-page '+(i===currentPage?'active':'')+'" data-page="'+i+'">'+i+'</button> ';$('#gt-pagination').html(ph);loadDispatches();});}
$('#gt-start').on('click',function(){var b=$(this),r=$('#gt-result');b.prop('disabled',true);r.text('در حال ساخت Profile...');$.post(A.ajaxUrl,{action:'sti_goldtel_profile_start',nonce:A.nonce,name:$('#gt-name').val(),identifier:$('#gt-identifier').val(),keyword:$('#gt-keyword').val(),category_id:$('#gt-category').val(),max_messages:$('#gt-max').val()}).done(function(x){r.text(x&&x.success?'✅ '+x.data.message:'❌ '+(x.data&&x.data.message||'خطا'));loadProfiles();}).fail(function(){r.text('❌ خطای ارتباط');}).always(function(){b.prop('disabled',false);});});
$('#gt-process').on('click',function(){var b=$(this),r=$('#gt-result');b.prop('disabled',true);$.post(A.ajaxUrl,{action:'sti_goldtel_process_now',nonce:A.nonce}).done(function(x){r.text(x&&x.success?'✅ '+x.data.message:'❌ خطا');loadProfiles();loadDispatches();}).always(function(){b.prop('disabled',false);});});
$('#gt-refresh-profiles').on('click',loadProfiles);$(document).on('click','.gt-cancel',function(){var id=$(this).data('id');$.post(A.ajaxUrl,{action:'sti_goldtel_profile_cancel',nonce:A.nonce,id:id}).done(loadProfiles);});$(document).on('click','.gt-use',function(){$('#gt-profile-select').val($(this).data('id')).trigger('change');loadRecords(1);});$('#gt-profile-select,#gt-filter-dispatch,#gt-filter-product,#gt-filter-category').on('change',function(){if($('#gt-profile-select').val())loadRecords(1);});$('#gt-load-records').on('click',function(){loadRecords(1);});$('#gt-check-all').on('change',function(){$('.gt-row-check').prop('checked',this.checked);selected();});$(document).on('change','.gt-row-check',selected);$(document).on('click','.gt-page',function(){loadRecords($(this).data('page'));});$(document).on('click','.gt-retry',function(){var id=$(this).data('id');$.post(A.ajaxUrl,{action:'sti_goldtel_retry',nonce:A.nonce,id:id,search_only:0}).done(loadDispatches);});function dispatch(searchOnly){var ids=selected();if(!ids.length){$('#gt-dispatch-result').text('ابتدا رکورد انتخاب کن.');return;}$.post(A.ajaxUrl,{action:'sti_goldtel_dispatch',nonce:A.nonce,ids:ids,search_only:searchOnly?1:0}).done(function(r){$('#gt-dispatch-result').text(r&&r.success?'✅ '+r.data.message:'❌ '+(r.data&&r.data.message||'خطا'));loadRecords(currentPage);loadDispatches();});}$('#gt-send-selected').on('click',function(){dispatch(false);});$('#gt-search-inbox').on('click',function(){dispatch(false);});loadProfiles();});}if(window.jQuery)boot();else{var t=setInterval(function(){if(window.jQuery){clearInterval(t);boot();}},50);}})();
</script>
