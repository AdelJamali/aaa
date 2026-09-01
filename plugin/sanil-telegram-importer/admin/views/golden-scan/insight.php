<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$sum      = STI_GS_Channel_Insight::summarize();
$channels = STI_GS_Channel::all();
?>
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — شناخت کانال</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<p>تحلیل پیام‌هایی که <strong>از قبل</strong> در Inventory هستند — بدون اسکن دوباره‌ی
	تلگرام و بدون هزینه‌ی AI. هدف این است که پیش از ساخت دسته‌بندی‌ها، عدد واقعی داشته باشید
	نه حدس.</p>

	<p>
		<label>کانال:
			<select id="gs-in-channel">
				<option value="0">همه‌ی کانال‌ها</option>
				<?php foreach ( $channels as $ch ) : ?>
					<option value="<?php echo (int) $ch['id']; ?>"><?php echo esc_html( $ch['title'] ?: $ch['identifier'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<button class="button button-primary" id="gs-in-run">🔍 شروع تحلیل</button>
		<span id="gs-in-progress" style="margin-inline-start:10px;color:#666;"></span>
	</p>

	<?php if ( $sum['scanned'] > 0 ) : ?>

		<div style="display:flex;gap:12px;margin:16px 0;flex-wrap:wrap;">
			<div style="flex:1;min-width:140px;padding:14px;border-radius:8px;background:#e3f2fd;border:1px solid #90caf9;">
				<div style="font-size:24px;font-weight:700;"><?php echo number_format_i18n( $sum['scanned'] ); ?></div>
				<div>پیام تحلیل‌شده<?php echo $sum['done'] ? '' : ' (ناتمام)'; ?></div>
			</div>
			<div style="flex:1;min-width:140px;padding:14px;border-radius:8px;background:#f5f5f5;border:1px solid #ccc;">
				<div style="font-size:24px;font-weight:700;"><?php echo number_format_i18n( count( $sum['categories'] ) ); ?></div>
				<div>دسته‌ی تشخیص‌داده‌شده</div>
			</div>
			<div style="flex:1;min-width:140px;padding:14px;border-radius:8px;background:<?php echo count( $sum['suggested'] ) ? '#fff8e1' : '#e8f5e9'; ?>;border:1px solid #ccc;">
				<div style="font-size:24px;font-weight:700;"><?php echo number_format_i18n( count( $sum['suggested'] ) ); ?></div>
				<div>بدون نگاشت ووکامرس</div>
			</div>
			<div style="flex:1;min-width:140px;padding:14px;border-radius:8px;background:#f5f5f5;border:1px solid #ccc;">
				<div style="font-size:24px;font-weight:700;"><?php echo size_format( $sum['avg_size'] ); ?></div>
				<div>میانگین حجم فایل</div>
			</div>
		</div>

		<h2>دسته‌های تشخیص‌داده‌شده</h2>
		<p style="color:#666;">ستون «نگاشت» می‌گوید آیا دسته‌ی ووکامرسی برایش وجود دارد یا نه.
		ستون‌های اطمینان نشان می‌دهند چه نسبتی خودکار منتشر می‌شوند (≥۸۵) و چه نسبتی بازبینی
		لازم دارند.</p>
		<table class="widefat striped">
			<thead><tr>
				<th>دسته</th><th style="width:90px;">تعداد</th><th style="width:80px;">سهم</th>
				<th style="width:80px;">≥۸۵٪</th><th style="width:80px;">۶۰-۸۴</th><th style="width:80px;">&lt;۶۰</th>
				<th style="width:110px;">نگاشت</th><th>نمونه</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $sum['categories'] as $slug => $c ) :
				$share  = $sum['scanned'] ? round( $c['count'] * 100 / $sum['scanned'] ) : 0;
				$is_map = ! empty( $sum['mapped'][ $slug ] );
				?>
				<tr<?php echo ( ! $is_map && '__none__' !== $slug && $c['count'] >= 50 ) ? ' style="background:#fff8e1;"' : ''; ?>>
					<td><strong><?php echo esc_html( $c['label'] ); ?></strong><br><code style="font-size:11px;"><?php echo esc_html( $slug ); ?></code></td>
					<td><?php echo number_format_i18n( $c['count'] ); ?></td>
					<td><?php echo $share; ?>٪</td>
					<td><?php echo number_format_i18n( $c['conf_hi'] ); ?></td>
					<td><?php echo number_format_i18n( $c['conf_mid'] ); ?></td>
					<td><?php echo number_format_i18n( $c['conf_low'] ); ?></td>
					<td><?php echo '__none__' === $slug ? '—' : ( $is_map ? '🟢 دارد' : '🟡 ندارد' ); ?></td>
					<td style="font-size:11px;color:#666;"><?php echo esc_html( implode( ' · ', $c['samples'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $sum['suggested'] ) ) : ?>
			<h2 style="margin-top:24px;">🟡 دسته‌های پیشنهادی برای ساخت</h2>
			<p style="color:#666;">این دسته‌ها تشخیص داده می‌شوند ولی دسته‌ی ووکامرسی ندارند،
			پس محصولاتشان به دسته‌ی پیش‌فرض می‌افتند. به ترتیب اهمیت:</p>
			<table class="widefat striped">
				<thead><tr><th style="width:60px;">اولویت</th><th>دسته</th><th style="width:100px;">تعداد</th><th style="width:90px;">سهم</th><th>نمونه</th></tr></thead>
				<tbody>
				<?php $i = 1; foreach ( $sum['suggested'] as $slug => $c ) :
					if ( $c['count'] < 5 ) { continue; }
					$share = $sum['scanned'] ? round( $c['count'] * 100 / $sum['scanned'] ) : 0; ?>
					<tr>
						<td><strong><?php echo $i++; ?></strong></td>
						<td><?php echo esc_html( $c['label'] ); ?></td>
						<td><?php echo number_format_i18n( $c['count'] ); ?></td>
						<td><?php echo $share; ?>٪</td>
						<td style="font-size:11px;color:#666;"><?php echo esc_html( implode( ' · ', $c['samples'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="color:#666;">دسته‌هایی با کمتر از ۵ مورد نمایش داده نشده‌اند — ساختن دسته برای آن‌ها ارزش ندارد.</p>
		<?php endif; ?>

		<h2 style="margin-top:24px;">ترکیب کانال</h2>
		<div style="display:flex;gap:20px;flex-wrap:wrap;">
			<div style="flex:1;min-width:260px;">
				<h3>فرمت فایل</h3>
				<table class="widefat striped"><tbody>
				<?php foreach ( array_slice( $sum['formats'], 0, 12, true ) as $k => $v ) : ?>
					<tr><td><?php echo esc_html( $k ); ?></td><td style="width:90px;"><?php echo number_format_i18n( $v ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
			<div style="flex:1;min-width:260px;">
				<h3>منبع</h3>
				<table class="widefat striped"><tbody>
				<?php foreach ( $sum['sites'] as $k => $v ) : ?>
					<tr><td><?php echo esc_html( $k ); ?></td><td style="width:90px;"><?php echo number_format_i18n( $v ); ?></td></tr>
				<?php endforeach; ?>
				<tr><td>دارای دکمه‌ی دانلود</td><td><?php echo number_format_i18n( $sum['buttons']['with'] ); ?></td></tr>
				<tr><td>بدون دکمه</td><td><?php echo number_format_i18n( $sum['buttons']['without'] ); ?></td></tr>
				</tbody></table>
			</div>
		</div>

	<?php endif; ?>

	<script>
	(function(){
		function post(action, extra){
			var body = new URLSearchParams(Object.assign({ action: action, nonce: STI.nonce }, extra || {}));
			return fetch(STI.ajaxUrl || ajaxurl, { method:'POST', credentials:'same-origin', body: body })
				.then(function(r){ return r.text(); })
				.then(function(t){ try { return JSON.parse(t); } catch(e){ throw new Error(t.slice(0,300)); } });
		}
		var btn = document.getElementById('gs-in-run');
		var out = document.getElementById('gs-in-progress');

		btn.addEventListener('click', function(){
			btn.disabled = true;
			var ch = document.getElementById('gs-in-channel').value;
			var after = 0, guard = 0;

			function step(){
				if (++guard > 200) { btn.disabled = false; out.textContent = 'سقف دسته‌ها پر شد.'; return; }
				post('sti_gs_insight_batch', { channel_id: ch, after_id: after }).then(function(r){
					if (!r.success) { btn.disabled = false; out.textContent = (r.data && r.data.message) || 'خطا'; return; }
					out.textContent = 'تحلیل‌شده: ' + r.data.scanned.toLocaleString('fa-IR');
					if (r.data.done) { out.textContent += ' — تمام شد'; location.reload(); return; }
					after = r.data.last_id;
					step();
				}).catch(function(e){ btn.disabled = false; out.textContent = e.message; });
			}
			out.textContent = 'در حال شروع...';
			step();
		});
	})();
	</script>
</div>
