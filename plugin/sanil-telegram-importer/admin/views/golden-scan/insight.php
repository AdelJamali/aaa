<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$sum      = STI_GS_Channel_Insight::summarize();
$channels = STI_GS_Channel::all();
?>
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">🔍 تحلیل محتوا</h1>
		<p class="gi-h1-sub">مرحله‌ی ۳: تحلیل پیام‌هایی که <strong>از قبل</strong> در Inventory هستند — بدون اسکن دوباره‌ی تلگرام و بدون هزینه‌ی AI. هدف: پیش از انتخاب دسته‌های انتشار، عدد واقعی داشته باشید، نه حدس.</p>
	</div>

	<?php
	$gs_steps_active = 3;
	$gs_steps_next   = array(
		'url'   => admin_url( 'admin.php?page=sti-golden-scan&gs_view=publish-queue' ),
		'label' => 'انتخاب دسته‌های انتشار',
	);
	$gs_steps_note = number_format_i18n( (int) $sum['scanned'] ) . ' پیام تحلیل‌شده · ' . count( $sum['categories'] ) . ' دسته';
	include STI_PATH . 'admin/views/golden-scan/partial-steps.php';
	?>

	<div class="gi-card gi-span-12" style="margin-bottom:var(--gi-s5);">
		<div class="gi-flex" style="align-items:center;flex-wrap:wrap;gap:var(--gi-s3);padding:var(--gi-s4);">
			<label class="gi-field" style="margin:0;">
				<span class="gi-field-label">کانال</span>
				<select id="gs-in-channel">
					<option value="0">همه‌ی کانال‌ها</option>
					<?php foreach ( $channels as $ch ) : ?>
						<option value="<?php echo (int) $ch['id']; ?>"><?php echo esc_html( $ch['title'] ?: $ch['identifier'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button class="gi-btn gi-btn--primary" id="gs-in-run">🔍 شروع تحلیل</button>
			<span id="gs-in-progress" class="gi-card-sub" role="status" aria-live="polite"></span>
		</div>
	</div>

	<?php if ( $sum['scanned'] > 0 ) : ?>
	<div class="gi-bento">

		<!-- KPI row -->
		<div class="gi-card gi-span-12">
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:var(--gi-s4);">
				<div class="gi-stat gi-stat--info"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( $sum['scanned'] ); ?></div><div class="gi-stat-l">پیام تحلیل‌شده<?php echo $sum['done'] ? '' : ' (ناتمام)'; ?></div></div>
				<div class="gi-stat"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( count( $sum['categories'] ) ); ?></div><div class="gi-stat-l">دسته‌ی تشخیص‌داده‌شده</div></div>
				<div class="gi-stat gi-stat--<?php echo count( $sum['suggested'] ) ? 'warning' : 'success'; ?>"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( count( $sum['suggested'] ) ); ?></div><div class="gi-stat-l">بدون نگاشت ووکامرس</div></div>
				<div class="gi-stat"><div class="gi-stat-v gi-nums"><?php echo size_format( $sum['avg_size'] ); ?></div><div class="gi-stat-l">میانگین حجم فایل</div></div>
			</div>
		</div>

		<!-- Categories -->
		<div class="gi-card gi-card--flush gi-span-12">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) var(--gi-s3);">
				<div>
					<h2 class="gi-card-title">دسته‌های تشخیص‌داده‌شده</h2>
					<span class="gi-card-sub">ستون «نگاشت» می‌گوید آیا دسته‌ی ووکامرسی برایش وجود دارد. ستون‌های اطمینان نشان می‌دهند چه نسبتی خودکار منتشر می‌شوند (≥۸۵) و چه نسبتی بازبینی لازم دارند.</span>
				</div>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table gi-responsive">
					<thead><tr>
						<th scope="col">دسته</th><th scope="col" style="width:90px;">تعداد</th><th scope="col" style="width:80px;">سهم</th>
						<th scope="col" style="width:80px;">≥۸۵٪</th><th scope="col" style="width:80px;">۶۰-۸۴</th><th scope="col" style="width:80px;">&lt;۶۰</th>
						<th scope="col" style="width:110px;">نگاشت</th><th scope="col">نمونه</th>
					</tr></thead>
					<tbody>
						<?php foreach ( $sum['categories'] as $slug => $c ) :
							$share  = $sum['scanned'] ? round( $c['count'] * 100 / $sum['scanned'] ) : 0;
							$is_map = ! empty( $sum['mapped'][ $slug ] );
						?>
							<tr<?php echo ( ! $is_map && '__none__' !== $slug && $c['count'] >= 50 ) ? ' class="gi-row-warn"' : ''; ?>>
								<td data-label="دسته"><strong><?php echo esc_html( $c['label'] ); ?></strong><br><code dir="ltr" style="font-size:var(--gi-fs0);"><?php echo esc_html( $slug ); ?></code></td>
								<td data-label="تعداد" class="gi-nums"><?php echo number_format_i18n( $c['count'] ); ?></td>
								<td data-label="سهم"><span class="gi-nums"><?php echo $share; ?>٪</span><div class="gi-bar" style="margin-top:4px;"><span style="width:<?php echo (int) min( 100, $share ); ?>%;"></span></div></td>
								<td data-label="≥۸۵٪" class="gi-nums"><?php echo number_format_i18n( $c['conf_hi'] ); ?></td>
								<td data-label="۶۰-۸۴" class="gi-nums"><?php echo number_format_i18n( $c['conf_mid'] ); ?></td>
								<td data-label="&lt;۶۰" class="gi-nums"><?php echo number_format_i18n( $c['conf_low'] ); ?></td>
								<td data-label="نگاشت"><?php echo '__none__' === $slug ? '—' : ( $is_map ? '<span class="gi-badge gi-badge--success">🟢 دارد</span>' : '<span class="gi-badge gi-badge--warning">🟡 ندارد</span>' ); ?></td>
								<td data-label="نمونه" style="font-size:var(--gi-fs0);color:var(--gi-text-muted);"><?php echo esc_html( implode( ' · ', $c['samples'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<?php if ( ! empty( $sum['suggested'] ) ) : ?>
		<div class="gi-card gi-card--flush gi-span-12" style="border-inline-start:4px solid var(--gi-warning);">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) var(--gi-s3);">
				<div>
					<h2 class="gi-card-title">🟡 دسته‌های پیشنهادی برای ساخت</h2>
					<span class="gi-card-sub">این دسته‌ها تشخیص داده می‌شوند ولی دسته‌ی ووکامرسی ندارند؛ محصولاتشان به دسته‌ی پیش‌فرض می‌افتند. به ترتیب اهمیت (دسته‌هایی با کمتر از ۵ مورد نمایش داده نشده‌اند — ساختن دسته برای آن‌ها ارزش ندارد):</span>
				</div>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table gi-responsive">
					<thead><tr><th scope="col" style="width:80px;">اولویت</th><th scope="col">دسته</th><th scope="col" style="width:100px;">تعداد</th><th scope="col" style="width:90px;">سهم</th><th scope="col">نمونه</th></tr></thead>
					<tbody>
						<?php $i = 1; foreach ( $sum['suggested'] as $slug => $c ) :
							if ( $c['count'] < 5 ) { continue; }
							$share = $sum['scanned'] ? round( $c['count'] * 100 / $sum['scanned'] ) : 0; ?>
							<tr>
								<td data-label="اولویت"><strong class="gi-nums"><?php echo $i++; ?></strong></td>
								<td data-label="دسته"><?php echo esc_html( $c['label'] ); ?></td>
								<td data-label="تعداد" class="gi-nums"><?php echo number_format_i18n( $c['count'] ); ?></td>
								<td data-label="سهم"><span class="gi-nums"><?php echo $share; ?>٪</span><div class="gi-bar" style="margin-top:4px;"><span style="width:<?php echo (int) min( 100, $share ); ?>%;"></span></div></td>
								<td data-label="نمونه" style="font-size:var(--gi-fs0);color:var(--gi-text-muted);"><?php echo esc_html( implode( ' · ', $c['samples'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>

		<!-- Composition -->
		<div class="gi-card gi-span-6">
			<div class="gi-card-head"><h2 class="gi-card-title">فرمت فایل</h2></div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table">
					<tbody>
						<?php foreach ( array_slice( $sum['formats'], 0, 12, true ) as $k => $v ) : ?>
							<tr><td><?php echo esc_html( $k ); ?></td><td class="gi-nums" style="text-align:end;"><?php echo number_format_i18n( $v ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<div class="gi-card gi-span-6">
			<div class="gi-card-head"><h2 class="gi-card-title">منبع</h2></div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table">
					<tbody>
						<?php foreach ( $sum['sites'] as $k => $v ) : ?>
							<tr><td><?php echo esc_html( $k ); ?></td><td class="gi-nums" style="text-align:end;"><?php echo number_format_i18n( $v ); ?></td></tr>
						<?php endforeach; ?>
						<tr><td>دارای دکمه‌ی دانلود</td><td class="gi-nums" style="text-align:end;"><?php echo number_format_i18n( $sum['buttons']['with'] ); ?></td></tr>
						<tr><td>بدون دکمه</td><td class="gi-nums" style="text-align:end;"><?php echo number_format_i18n( $sum['buttons']['without'] ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>

		<!-- ۱۰.۱۲ — CTA به مرحله‌ی بعد: انتخاب دسته‌های انتشار -->
		<div class="gi-card gi-card--accent gi-span-12">
			<div class="gi-card-head">
				<h2 class="gi-card-title">مرحله‌ی بعد: انتخاب دسته‌های انتشار</h2>
				<span class="gi-card-sub">از بین دسته‌های تحلیل‌شده، دسته‌هایی که می‌خواهید منتشر شوند را انتخاب کنید</span>
			</div>
			<div class="gi-flex" style="align-items:center;gap:var(--gi-s3);flex-wrap:wrap;">
				<a class="gi-btn gi-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=publish-queue' ) ); ?>">انتخاب دسته‌های انتشار ←</a>
			</div>
		</div>

	</div>
	<?php else : ?>
	<div class="gi-empty gi-mt-5" style="padding:var(--gi-s8) var(--gi-s5);">
		<div class="gi-empty-ico" aria-hidden="true">🔍</div>
		<div class="gi-empty-title">هنوز پیامی تحلیل نشده.</div>
		<div class="gi-empty-sub">کانال را انتخاب کن و «شروع تحلیل» را بزن — فقط روی Inventory موجود، بدون تماس با تلگرام.</div>
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
