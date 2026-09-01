<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$stats = STI_GS_Auto_Worker::stats();
$queue = class_exists( 'STI_GS_Publish_Queue' ) ? STI_GS_Publish_Queue::stats() : null;
$today = $stats['today'] ?? array();
?>
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — پردازش خودکار</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<p>وقتی روشن باشد، Sessionها بدون هیچ کلیکی خودشان جلو می‌روند. هر
	<?php echo (int) round( $stats['interval'] / 60 ); ?> دقیقه،
	<?php echo (int) $stats['batch']; ?> مورد، هرکدام یک مرحله.</p>

	<div style="display:flex;gap:12px;margin:16px 0;flex-wrap:wrap;">
		<div style="flex:1;min-width:130px;padding:14px;border-radius:8px;background:<?php echo $stats['enabled'] ? '#e8f5e9' : '#f5f5f5'; ?>;border:1px solid #ccc;">
			<div style="font-size:22px;font-weight:700;"><?php echo $stats['enabled'] ? '🟢 روشن' : '⚪ خاموش'; ?></div>
			<div>وضعیت Worker</div>
		</div>
		<div style="flex:1;min-width:130px;padding:14px;border-radius:8px;background:#e3f2fd;border:1px solid #90caf9;">
			<div style="font-size:26px;font-weight:700;" id="gs-w-pending"><?php echo (int) $stats['pending']; ?></div>
			<div>در انتظار پردازش</div>
		</div>
		<div style="flex:1;min-width:130px;padding:14px;border-radius:8px;background:<?php echo $stats['stuck'] > 0 ? '#ffebee' : '#f5f5f5'; ?>;border:1px solid #ccc;">
			<div style="font-size:26px;font-weight:700;" id="gs-w-stuck"><?php echo (int) ( $stats['stuck'] + ( $stats['review'] ?? 0 ) ); ?></div>
			<div>نیازمند بازبینی (شامل NEEDS_REVIEW)</div>
		</div>
	</div>

	<p>
		<button class="button button-primary" id="gs-w-toggle" data-on="<?php echo $stats['enabled'] ? '1' : '0'; ?>">
			<?php echo $stats['enabled'] ? '⏸ خاموش کردن' : '▶ روشن کردن'; ?>
		</button>
		<button class="button" id="gs-w-run">اجرای فوری یک دور</button>
		<button class="button" id="gs-w-reset">آزادسازی موارد گیرکرده</button>
		<button class="button" id="gs-w-refresh">به‌روزرسانی</button>
	</p>

	<h2 style="margin-top:24px;">معماری زنجیره (Chain Mode) — v10.8</h2>
	<p style="color:#555;max-width:760px;">
		گلدن اسکن حالا تلگرام را به‌صورت زنجیره‌ای می‌بیند:
		<code>Telegram Node → Node → Node → Asset</code> (مثلاً کانال → دکمه → PartyManagerBot → دکمه → FileechBot → فایل).
		این حالت فقط روی Sessionهای <strong>تازه</strong> اثر می‌گذارد؛ Sessionهای قدیمی و کانال‌های تک‌دکمه‌ای با حالت legacy بدون تغییر کار می‌کنند.
	</p>
	<p>
		<label for="gs-chain-mode" style="font-weight:600;">حالت پردازش:</label>
		<select id="gs-chain-mode" style="min-width:220px;">
			<option value="legacy" <?php selected( $stats['chain_mode'] ?? 'auto', 'legacy' ); ?>>legacy — مسیر قدیمی Button → File (دست‌نخورده)</option>
			<option value="auto" <?php selected( $stats['chain_mode'] ?? 'auto', 'auto' ); ?>>auto — Asset → قدیم | DeepLink/Button/Bot → زنجیره (پیشنهادی)</option>
			<option value="chain" <?php selected( $stats['chain_mode'] ?? 'auto', 'chain' ); ?>>chain — همه‌چیز از زنجیره</option>
		</select>
		<button class="button" id="gs-chain-save">ذخیره حالت</button>
		<span id="gs-chain-status" style="color:#666;margin-right:8px;"></span>
	</p>
	<details style="margin-bottom:12px;">
		<summary style="cursor:pointer;color:#666;">توضیح حالت‌ها</summary>
		<ul style="line-height:1.9;color:#555;margin:8px 0 0;">
			<li><strong>legacy:</strong> دقیقاً رفتار نسخه‌های قبل — Resolver قدیمی، فقط <code>Button → File</code>.</li>
			<li><strong>auto:</strong> فایل (Asset) → همان مسیر قدیم (Matcher با اولویت CODE→NAME→CAPTION→HASH)؛ دکمه/DeepLink/ربات → زنجیره‌ی جدید با <code>messages.startBot</code>.</li>
			<li><strong>chain:</strong> همه‌ی مسیرها از Chain Engine می‌گذرند (برای کانال‌های چندرباتی مثل PartyManagerBot → FileechBot).</li>
		</ul>
	</details>

	<h2 style="margin-top:24px;">گزارش امروز</h2>
	<table class="widefat striped" id="gs-w-today">
		<tbody>
			<tr><td style="width:260px;">مرحله جلو رفت</td><td><?php echo (int) ( $today['advanced'] ?? 0 ); ?></td></tr>
			<tr><td>منتظر پاسخ ربات</td><td><?php echo (int) ( $today['waiting'] ?? 0 ); ?></td></tr>
			<tr><td>کامل شد</td><td><?php echo (int) ( $today['completed'] ?? 0 ); ?></td></tr>
			<tr><td>خطا خورد</td><td><?php echo (int) ( $today['failed'] ?? 0 ); ?></td></tr>
			<tr><td>تعداد دورها</td><td><?php echo (int) ( $today['ticks'] ?? 0 ); ?></td></tr>
		</tbody>
	</table>

	<?php
	$fails = $stats['failures'] ?? array( 'items' => array(), 'by_reason' => array() );
	if ( ! empty( $fails['by_reason'] ) ) : ?>
		<h2 style="margin-top:24px;">چرا خطا خورد</h2>
		<p style="color:#666;">گزارش «۱۵ خطا» به‌تنهایی چیزی نمی‌گوید. اینجا علت‌ها گروه‌بندی شده‌اند.</p>
		<table class="widefat striped">
			<thead><tr><th style="width:70px;">تعداد</th><th>مرحله و علت</th></tr></thead>
			<tbody>
			<?php foreach ( $fails['by_reason'] as $reason => $count ) : ?>
				<tr>
					<td><strong><?php echo (int) $count; ?></strong></td>
					<td><?php echo esc_html( $reason ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<details style="margin-top:10px;">
			<summary style="cursor:pointer;">آخرین <?php echo count( $fails['items'] ); ?> مورد به تفکیک</summary>
			<table class="widefat striped" style="margin-top:8px;">
				<thead><tr><th style="width:90px;">Session</th><th style="width:140px;">وضعیت</th><th style="width:140px;">مرحله</th><th>پیام</th><th style="width:140px;">زمان</th></tr></thead>
				<tbody>
				<?php foreach ( $fails['items'] as $f ) : ?>
					<tr>
						<td>#<?php echo (int) $f['session_id']; ?></td>
						<td><code><?php echo esc_html( $f['state'] ); ?></code></td>
						<td><?php echo esc_html( $f['stage'] ); ?></td>
						<td><?php echo esc_html( $f['message'] ); ?></td>
						<td><?php echo esc_html( $f['at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	<?php endif; ?>

	<?php if ( $queue ) :
		$queued_items = STI_GS_Publish_Queue::items( 'queued', 50 );
		$done_items   = STI_GS_Publish_Queue::items( 'published', 10 );
	?>
		<h2 style="margin-top:24px;">صف انتشار</h2>
		<p style="color:#666;">این صف مستقل از صفحه‌ی «صف انتشار» قدیمی است. آن صفحه مسیر ربات
		تلگرام را سرویس می‌دهد و محصولات گلدن اسکن را نمی‌بیند — به همین دلیل همیشه «۰ محصول»
		نشان می‌دهد. همه‌ی کنترل‌های لازم اینجاست.</p>

		<p>
			<button class="button button-primary" id="gs-q-toggle" data-on="<?php echo $queue['running'] ? '1' : '0'; ?>">
				<?php echo $queue['running'] ? '⏸ توقف صف' : '▶ شروع صف'; ?>
			</button>
			<label style="margin-inline-start:12px;">فاصله (دقیقه):
				<input type="number" id="gs-q-interval" min="1" style="width:80px;"
					value="<?php echo (int) round( $queue['interval_min'] ); ?>">
			</label>
			<button class="button" id="gs-q-save">💾 ذخیره</button>
			<label style="margin-inline-start:12px;">انتشار فوری:
				<input type="number" id="gs-q-count" min="1" max="50" value="5" style="width:70px;">
			</label>
			<button class="button" id="gs-q-now">🚀 انتشار فوری</button>
		</p>
		<table class="widefat striped">
			<tbody>
				<tr><td style="width:260px;">وضعیت صف</td><td><?php echo $queue['running'] ? '🟢 روشن' : '⚪ خاموش (از صفحه‌ی «صف انتشار»)'; ?></td></tr>
				<tr><td>در صف</td><td><?php echo (int) $queue['queued']; ?></td></tr>
				<tr><td>بدون زمان‌بندی</td>
					<td><?php
						$un = (int) ( $queue['unscheduled'] ?? 0 );
						echo $un > 0
							? '🔴 ' . $un . ' — این‌ها هرگز منتشر نمی‌شوند'
							: '🟢 ۰';
					?></td></tr>
				<tr><td>نوبت بعدی</td><td><?php echo esc_html( $queue['next_at'] ?: '—' ); ?></td></tr>
				<tr><td>منتشرشده</td><td><?php echo (int) $queue['published']; ?></td></tr>
				<tr><td>فاصله‌ی انتشار</td><td><?php echo (int) $queue['interval_min']; ?> دقیقه</td></tr>
				<tr><td>اجرای دستی</td>
					<td><button class="button" id="gs-q-run">🚀 انتشار نوبت بعدی همین حالا</button>
					<span style="color:#666;margin-inline-start:8px;">دکمه‌ی صفحه‌ی «صف انتشار» فقط صف قدیمی را اجرا می‌کند</span></td></tr>
				<tr><td>سقف روزانه</td><td><?php echo $queue['daily_cap'] > 0 ? (int) $queue['daily_cap'] . ' (امروز: ' . (int) $queue['published_today'] . ')' : 'بدون سقف'; ?></td></tr>
			</tbody>
		</table>

		<h3 style="margin-top:20px;">📬 محصولات در صف (<?php echo count( $queued_items ); ?>)</h3>
		<?php if ( empty( $queued_items ) ) : ?>
			<p>صف خالی است.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th style="width:70px;">محصول</th><th>عنوان</th>
					<th style="width:130px;">دسته</th><th style="width:90px;">قیمت</th>
					<th style="width:150px;">نوبت انتشار</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $queued_items as $it ) : ?>
					<tr>
						<td>#<?php echo (int) $it['product_id']; ?></td>
						<td><a href="<?php echo esc_url( $it['edit_link'] ); ?>" target="_blank"><?php echo esc_html( $it['title'] ?: '—' ); ?></a></td>
						<td><?php echo esc_html( $it['category'] ); ?></td>
						<td><?php echo $it['price'] !== '' ? esc_html( number_format_i18n( (float) $it['price'] ) ) : '—'; ?></td>
						<td><?php echo esc_html( $it['scheduled_at'] ?: '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $done_items ) ) : ?>
			<h3 style="margin-top:20px;">✅ آخرین منتشرشده‌ها</h3>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( $done_items as $it ) : ?>
					<tr>
						<td style="width:70px;">#<?php echo (int) $it['product_id']; ?></td>
						<td><a href="<?php echo esc_url( $it['view_link'] ); ?>" target="_blank"><?php echo esc_html( $it['title'] ?: '—' ); ?></a></td>
						<td style="width:130px;"><?php echo esc_html( $it['category'] ); ?></td>
						<td style="width:90px;"><?php echo $it['price'] !== '' ? esc_html( number_format_i18n( (float) $it['price'] ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	<h2 style="margin-top:26px;">🛠 بازسازی محصولات ساخته‌شده</h2>
	<p style="color:#666;">اصلاح موتور عنوان فقط روی محصولات <strong>تازه</strong> اثر دارد.
	محصولاتی که با منطق قبلی ساخته شده‌اند («دانلود PSD لایه‌باز لوگوی…» بدون دسته و قیمت)
	خودشان درست نمی‌شوند. این ابزار عنوان، دسته و قیمت آن‌ها را با منطق فعلی دوباره می‌سازد —
	بدون دانلود دوباره و بدون ساخت محصول تازه.</p>

	<p>
		<label>تعداد: <input type="number" id="gs-rb-count" min="1" max="50" value="10" style="width:70px;"></label>
		<label style="margin-inline-start:10px;"><input type="checkbox" id="gs-rb-desc"> توضیحات هم بازنویسی شود</label>
		<label style="margin-inline-start:10px;"><input type="checkbox" id="gs-rb-price" checked> قیمت هم اصلاح شود</label>
	</p>
	<p>
		<button class="button" id="gs-rb-preview">👁 پیش‌نمایش تغییرات</button>
		<button class="button button-primary" id="gs-rb-apply" disabled>✅ اعمال</button>
	</p>
	<div id="gs-rb-result"></div>

	<div class="notice notice-info" style="margin-top:20px;">
		<p><strong>ترتیب درست روشن کردن:</strong> اول Worker را روشن کنید و بگذارید
		چند دور بچرخد و محصولات ساخته شوند. وقتی از کیفیت عنوان، دسته و قیمت
		مطمئن شدید، آن‌وقت صف انتشار را روشن کنید. تا صف خاموش است، هیچ محصولی
		منتشر نمی‌شود و همه به‌صورت پیش‌نویس می‌مانند.</p>
	</div>

	<script>
	(function(){
		function post(action, extra){
			var body = new URLSearchParams(Object.assign({ action: action, nonce: STI.nonce }, extra || {}));
			return fetch(STI.ajaxUrl || ajaxurl, { method:'POST', credentials:'same-origin', body: body })
				.then(function(r){ return r.text(); })
				.then(function(t){
					try { return JSON.parse(t); }
					catch(e){ throw new Error('پاسخ نامعتبر از سرور:\n' + t.slice(0,300)); }
				});
		}
		function paint(d){
			if (!d) return;
			document.getElementById('gs-w-pending').textContent = d.pending;
			document.getElementById('gs-w-stuck').textContent   = d.stuck + (d.review || 0);
			var t = d.today || {};
			var rows = document.querySelectorAll('#gs-w-today tbody tr td:last-child');
			[t.advanced||0, t.waiting||0, t.completed||0, t.failed||0, t.ticks||0]
				.forEach(function(v,i){ if (rows[i]) rows[i].textContent = v; });
			var btn = document.getElementById('gs-w-toggle');
			btn.dataset.on = d.enabled ? '1' : '0';
			btn.textContent = d.enabled ? '⏸ خاموش کردن' : '▶ روشن کردن';
		}
		function refresh(){ post('sti_gs_worker_stats').then(function(r){ if (r.success) paint(r.data); }); }

		document.getElementById('gs-w-toggle').addEventListener('click', function(){
			var on = this.dataset.on === '1';
			post('sti_gs_worker_toggle', { enabled: on ? '' : '1' })
				.then(function(r){ if (r.success) { paint(r.data); location.reload(); } });
		});
		document.getElementById('gs-w-run').addEventListener('click', function(){
			var b = this; b.disabled = true; b.textContent = 'در حال اجرا...';
			post('sti_gs_worker_run_now').then(function(r){
				b.disabled = false; b.textContent = 'اجرای فوری یک دور';
				if (!r.success) { alert((r.data && r.data.message) || 'خطا'); return; }
				paint(r.data);
			}).catch(function(e){ b.disabled=false; b.textContent='اجرای فوری یک دور'; alert(e.message); });
		});
		document.getElementById('gs-w-reset').addEventListener('click', function(){
			if (!confirm('شمارنده‌ی تلاش همه‌ی موارد گیرکرده صفر شود؟')) return;
			post('sti_gs_worker_reset').then(function(r){
				if (r.success) { alert(r.data.message); paint(r.data); }
			});
		});
		var qRun = document.getElementById('gs-q-run');
		if (qRun) {
			qRun.addEventListener('click', function(){
				var b = this; b.disabled = true; b.textContent = 'در حال انتشار...';
				post('sti_gs_queue_run_now').then(function(r){
					b.disabled = false; b.textContent = '🚀 انتشار نوبت بعدی همین حالا';
					if (!r.success) { alert((r.data && r.data.message) || 'خطا'); return; }
					alert('منتشرشده تا این لحظه: ' + (r.data.published || 0) + '\nدر صف: ' + (r.data.queued || 0));
					location.reload();
				}).catch(function(e){ b.disabled=false; b.textContent='🚀 انتشار نوبت بعدی همین حالا'; alert(e.message); });
			});
		}

		function bind(id, fn){ var el = document.getElementById(id); if (el) el.addEventListener('click', fn); }

		bind('gs-q-toggle', function(){
			var on = this.dataset.on === '1';
			post('sti_gs_queue_toggle', { enabled: on ? '' : '1' })
				.then(function(r){ if (r.success) location.reload(); });
		});

		bind('gs-q-save', function(){
			var m = document.getElementById('gs-q-interval').value;
			post('sti_gs_queue_interval', { minutes: m }).then(function(r){
				alert(r.success ? 'فاصله ذخیره شد.' : ((r.data && r.data.message) || 'خطا'));
				if (r.success) location.reload();
			});
		});

		bind('gs-chain-save', function(){
			var m = document.getElementById('gs-chain-mode').value;
			var st = document.getElementById('gs-chain-status');
			st.textContent = 'در حال ذخیره...';
			post('sti_gs_worker_chain_mode', { mode: m }).then(function(r){
				if (r.success) { st.textContent = '✓ ذخیره شد (حالت: ' + r.data.chain_mode + ').'; }
				else { st.textContent = 'خطا: ' + ((r.data && r.data.message) || 'نامشخص'); }
			}).catch(function(e){ st.textContent = 'خطا: ' + e.message; });
		});

		bind('gs-q-now', function(){
			var n = document.getElementById('gs-q-count').value;
			if (!confirm(n + ' محصول همین حالا منتشر شود؟')) return;
			var b = this; b.disabled = true; b.textContent = 'در حال انتشار...';
			post('sti_gs_queue_publish_now', { count: n }).then(function(r){
				b.disabled = false; b.textContent = '🚀 انتشار فوری';
				alert((r.data && r.data.message) || 'انجام شد');
				location.reload();
			}).catch(function(e){ b.disabled=false; b.textContent='🚀 انتشار فوری'; alert(e.message); });
		});

		bind('gs-rb-preview', function(){
			var b = this; b.disabled = true; b.textContent = 'در حال محاسبه...';
			post('sti_gs_rebuild_preview', { count: document.getElementById('gs-rb-count').value })
				.then(function(r){
					b.disabled = false; b.textContent = '👁 پیش‌نمایش تغییرات';
					if (!r.success) { alert((r.data && r.data.message) || 'خطا'); return; }
					var rows = r.data.rows || [];
					var html = '<table class="widefat striped"><thead><tr>' +
						'<th style="width:70px;">محصول</th><th>عنوان فعلی</th><th>عنوان تازه</th>' +
						'<th style="width:120px;">دسته</th><th style="width:90px;">قیمت</th></tr></thead><tbody>';
					rows.forEach(function(x){
						var changed = x.after && x.after !== x.before;
						html += '<tr' + (changed ? ' style="background:#e8f5e9;"' : '') + '>' +
							'<td>#' + x.product_id + '</td>' +
							'<td style="color:#888;">' + (x.before || '—') + '</td>' +
							'<td><strong>' + (x.after || '(بدون تغییر)') + '</strong></td>' +
							'<td>' + (x.category || '—') + '</td>' +
							'<td>' + (x.price || '—') + '</td></tr>';
					});
					html += '</tbody></table>';
					document.getElementById('gs-rb-result').innerHTML = html;
					document.getElementById('gs-rb-apply').disabled = rows.length === 0;
				}).catch(function(e){ b.disabled=false; b.textContent='👁 پیش‌نمایش تغییرات'; alert(e.message); });
		});

		bind('gs-rb-apply', function(){
			if (!confirm('عنوان و دسته و قیمت این محصولات بازنویسی شود؟')) return;
			var b = this; b.disabled = true; b.textContent = 'در حال اعمال...';
			post('sti_gs_rebuild_apply', {
				count: document.getElementById('gs-rb-count').value,
				description: document.getElementById('gs-rb-desc').checked ? '1' : '',
				price: document.getElementById('gs-rb-price').checked ? '1' : ''
			}).then(function(r){
				b.textContent = '✅ اعمال';
				alert((r.data && r.data.message) || 'انجام شد');
				location.reload();
			}).catch(function(e){ b.disabled=false; b.textContent='✅ اعمال'; alert(e.message); });
		});

		document.getElementById('gs-w-refresh').addEventListener('click', refresh);
		setInterval(refresh, 20000);
	})();
	</script>
</div>
