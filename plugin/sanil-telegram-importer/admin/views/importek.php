<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
STI_Importek::instance()->pump_inline( 1 );
$categories = STI_Category::get_all();
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">
			<div class="sti-header">
				<h1><span class="dashicons dashicons-upload"></span> ایمپورتک | واردات ساده کانال</h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-telegram' ) ); ?>" class="sti-btn secondary">⚙️ تنظیمات تلگرام</a>
			</div>

			<div class="sti-info-box" style="margin-bottom:18px;">
				<strong>ایمپورتک چه کار می‌کند؟</strong>
				<p style="margin:8px 0 0;">با اکانت شخصی MTProto تاریخچه کانال را از قدیمی‌ترین پیام بررسی می‌کند، پیام‌های شامل کلمه کلیدی را پیدا می‌کند و الگوی <b>عکس + متن + فایل ZIP</b> را به یک محصول متصل می‌کند. عنوان از خط اول متن و توضیحات از ادامه متن ساخته می‌شود؛ سپس محصول با صف انتشار فعلی ثبت می‌شود.</p>
				<p style="margin:8px 0 0;color:#92400e;">برای کانال خصوصی، اکانت شخصی باید در کانال عضو و در تنظیمات تلگرام لاگین شده باشد. Bot API به‌تنهایی تاریخچه قدیمی را نمی‌خواند.</p>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>🚀 شروع ایمپورتک</h2><p>هر فایل فشرده یک محصول می‌شود و طبق صف انتشار فعلی زمان‌بندی خواهد شد.</p></div></div>
				<div class="sti-form-row">
					<div class="sti-field" style="grid-column:span 2;">
						<label>آدرس کانال/گروه</label>
						<input id="ik-identifier" type="text" dir="ltr" placeholder="@FileechParty یا https://t.me/FileechParty">
						<span class="hint">از نام کاربری، لینک کانال یا لینک دعوت پشتیبانی می‌شود.</span>
					</div>
				</div>
				<div class="sti-form-row">
					<div class="sti-field">
						<label>کلمه کلیدی</label>
						<input id="ik-keyword" type="text" dir="ltr" placeholder="UI kit">
						<span class="hint">جست‌وجو در متن، کپشن و نام فایل انجام می‌شود.</span>
					</div>
					<div class="sti-field">
						<label>دسته‌بندی محصول</label>
						<select id="ik-category" required><option value="">— انتخاب کنید —</option><?php foreach ( $categories as $cat ) : ?><option value="<?php echo (int) $cat->id; ?>"><?php echo esc_html( $cat->telegram_label ); ?><?php echo empty( $cat->is_active ) ? ' (غیرفعال)' : ''; ?></option><?php endforeach; ?></select>
					</div>
					<div class="sti-field">
						<label>حداکثر محصول در این Job</label>
						<input id="ik-max-items" type="number" min="1" max="2000" value="500">
						<span class="hint">برای کانال‌های بزرگ، چند Job جدا اجرا کنید.</span>
						<label class="sti-toggle" style="margin-top:8px;"><input id="ik-use-ai" type="checkbox" checked> بازنویسی عنوان و توضیحات با هوش مصنوعی</label>
					</div>
				</div>
				<div class="sti-form-actions">
					<button id="ik-start" class="sti-btn">🚀 شروع ایمپورتک</button>
					<button id="ik-process" class="sti-btn secondary">⚡ پردازش فوری</button>
					<span id="ik-result" class="sti-inline-result"></span>
				</div>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>📋 Jobهای ایمپورتک</h2><p>پردازش زنده و قابل توقف</p></div><button id="ik-refresh" class="sti-btn secondary">🔄 بروزرسانی</button></div>
				<div class="sti-table-wrap">
					<table class="sti-table widefat"><thead><tr><th>کانال</th><th>کلمه</th><th>دسته</th><th>اسکن</th><th>پیدا شده</th><th>وارد شده</th><th>خطا</th><th>مرحله</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="ik-jobs"><tr><td colspan="10" class="sti-empty">در حال بارگذاری...</td></tr></tbody></table>
				</div>
			</div>
		</div>
	</div>
</div>
<script>
(function(){
	function boot(){jQuery(function($){'use strict';
		var A=window.STI||{}, timer=null;
		var stages={resolve:'اتصال به کانال',scan:'خواندن تاریخچه',assemble:'ساخت گروه‌های عکس/متن/ZIP',process:'ساخت محصولات',done:'پایان'};
		var statuses={queued:['در صف','queued'],running:['در حال اجرا','running'],completed:['تکمیل','completed'],partial:['ناقص','partial'],error:['خطا','error'],cancelled:['لغو شده','cancelled']};
		function esc(s){return $('<div>').text(s==null?'':String(s)).html();}
		function active(j){return j&&(['queued','running'].indexOf(j.status)>=0);}
		function render(jobs){var html=''; var on=0;
			if(!jobs||!jobs.length){$('#ik-jobs').html('<tr><td colspan="10" class="sti-empty">هنوز Jobی ثبت نشده است.</td></tr>');return;}
			$.each(jobs,function(_,j){if(active(j))on++;var st=statuses[j.status]||[j.status||'—',''];html+='<tr>'+
			'<td><code>'+esc(j.identifier||'—')+'</code></td><td><strong>'+esc(j.keyword)+'</strong></td><td>#'+esc(j.category_id)+'</td>'+
			'<td>'+esc(j.scanned||0)+'</td><td>'+esc(j.matched||0)+'</td><td>'+esc(j.imported||0)+'</td><td>'+esc(j.failed||0)+'</td>'+
			'<td>'+esc(stages[j.stage]||j.stage||'—')+'</td><td><span class="sti-badge '+st[1]+'">'+esc(st[0])+'</span>'+(j.last_error&&j.status==='error'?'<div class="sti-error-note">'+esc(j.last_error)+'</div>':'')+'</td><td style="white-space:nowrap;">'+(active(j)?'<button class="sti-btn-sm danger ik-cancel" data-id="'+j.id+'">لغو</button> ':'')+'<button class="sti-btn-sm ik-detail" data-id="'+j.id+'">جزئیات</button></td></tr>';});
			$('#ik-jobs').html(html); if(on&&!timer)timer=setInterval(load,4000); if(!on&&timer){clearInterval(timer);timer=null;}
		}
		function load(){if(!A.ajaxUrl)return;$.post(A.ajaxUrl,{action:'sti_importek_poll',nonce:A.nonce}).done(function(r){if(r&&r.success)render(r.data.jobs);});}
		$('#ik-start').on('click',function(){var b=$(this),r=$('#ik-result');b.prop('disabled',true);r.text('در حال ساخت Job...');$.post(A.ajaxUrl,{action:'sti_importek_start',nonce:A.nonce,identifier:$('#ik-identifier').val(),keyword:$('#ik-keyword').val(),category_id:$('#ik-category').val(),max_items:$('#ik-max-items').val(),use_ai:$('#ik-use-ai').is(':checked')?1:0}).done(function(x){r.text(x&&x.success?'✅ '+x.data.message:'❌ '+(x.data&&x.data.message||'خطا'));load();}).fail(function(){r.text('❌ خطای ارتباط با سرور');}).always(function(){b.prop('disabled',false);});});
		$('#ik-process').on('click',function(){var b=$(this),r=$('#ik-result');b.prop('disabled',true);$.post(A.ajaxUrl,{action:'sti_importek_process_now',nonce:A.nonce}).done(function(x){r.text(x&&x.success?'✅ '+x.data.message:'❌ خطا');render(x.data.jobs||[]);}).always(function(){b.prop('disabled',false);});});
		$('#ik-refresh').on('click',load);
		$(document).on('click','.ik-cancel',function(){var id=$(this).data('id');if(!confirm('این Job لغو شود؟'))return;$.post(A.ajaxUrl,{action:'sti_importek_cancel',nonce:A.nonce,id:id}).done(load);});
		$(document).on('click','.ik-detail',function(){var id=$(this).data('id');$.post(A.ajaxUrl,{action:'sti_importek_status',nonce:A.nonce,id:id}).done(function(x){if(x&&x.success){var j=x.data.job;alert('Job #'+j.id+'\nمرحله: '+(stages[j.stage]||j.stage)+'\nاسکن: '+j.scanned+'\nپیدا شده: '+j.matched+'\nوارد شده: '+j.imported+'\nخطا: '+j.failed+(j.last_error?'\n\n'+j.last_error:''));}});});
		load();
	});}
	if(window.jQuery)boot();else{var t=setInterval(function(){if(window.jQuery){clearInterval(t);boot();}},50);}
})();
</script>
