<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

$status = STI_Scheduler::get_status();
$health = $status['health'];
$categories = STI_Category::get_active();
$mode = $status['mode'] ?? 'fixed';
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">

			<div class="sti-header">
				<h1><span class="dashicons dashicons-clock"></span> صف انتشار</h1>
			</div>

			<div class="sti-health <?php echo $health['healthy'] ? 'ok' : 'warning'; ?>">
				<span class="sti-health-dot"></span>
				<div>
					<strong><?php echo $health['healthy'] ? 'Cron صف فعال است' : 'Cron صف به‌موقع اجرا نشده است'; ?></strong><br>
					<small><?php echo $health['last_tick'] ? 'آخرین اجرا: ' . esc_html( human_time_diff( $health['last_tick'], time() ) ) . ' پیش' : 'هنوز اجرای صف ثبت نشده است'; ?></small>
				</div>
			</div>

			<div class="sti-cards">
				<div class="sti-card <?php echo $status['running'] ? 'accent-green' : 'accent-red'; ?>">
					<div class="num"><?php echo $status['running'] ? '🟢 فعال' : '🔴 متوقف'; ?></div>
					<div class="label">وضعیت صف</div>
				</div>
				<div class="sti-card accent-blue">
					<div class="num"><?php echo (int) $status['queued_count']; ?></div>
					<div class="label">محصول در صف</div>
				</div>
				<div class="sti-card accent-orange">
					<div class="num"><?php echo 'smart' === $mode ? 'هوشمند' : ( (int) $status['interval_minutes'] . ' دقیقه' ); ?></div>
					<div class="label">حالت زمان‌بندی</div>
				</div>
			</div>

			<!-- کنترل صف -->
			<div class="sti-panel">
				<div class="sti-panel-head">
					<div>
						<h2>⚙️ کنترل صف</h2>
						<p>محصولات ساخته‌شده (از جمله واردات کانال) به‌جای انتشار فوری وارد این صف می‌شوند. انتشار تدریجی از فشار به سرور، فایروال و تشخیص ربات‌گونه جلوگیری می‌کند.</p>
					</div>
				</div>

				<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
					<button type="button" id="sti-queue-toggle" class="sti-btn <?php echo $status['running'] ? 'danger' : ''; ?>">
						<?php echo $status['running'] ? '⏸ توقف صف' : '▶️ شروع صف'; ?>
					</button>
					<button type="button" id="sti-queue-run-now" class="sti-btn secondary">🔄 اجرای یک نوبت اکنون</button>
					<div id="sti-queue-toggle-result" class="sti-inline-result"></div>
				</div>

				<div class="sti-form-row">
					<div class="sti-field">
						<label>حالت زمان‌بندی</label>
						<select id="sti-queue-mode">
							<option value="fixed" <?php selected( $mode, 'fixed' ); ?>>ثابت — هر N دقیقه یک محصول</option>
							<option value="smart" <?php selected( $mode, 'smart' ); ?>>هوشمند — فاصله متغیر ضد‌ربات</option>
						</select>
						<span class="hint">حالت هوشمند با توجه به ساعت روز و jitter تصادفی فاصله را تغییر می‌دهد.</span>
					</div>
					<div class="sti-field" id="sti-queue-fixed-fields" style="<?php echo 'smart' === $mode ? 'display:none' : ''; ?>">
						<label>فاصله ثابت (دقیقه)</label>
						<input type="number" id="sti-queue-interval" min="1" max="1440" value="<?php echo (int) $status['interval_minutes']; ?>">
					</div>
				</div>

				<div class="sti-form-row" id="sti-queue-smart-fields" style="<?php echo 'smart' !== $mode ? 'display:none' : ''; ?>">
					<div class="sti-field">
						<label>حداقل فاصله هوشمند (دقیقه)</label>
						<input type="number" id="sti-queue-smart-min" min="3" max="120" value="<?php echo (int) ( $status['smart_min'] ?? 8 ); ?>">
					</div>
					<div class="sti-field">
						<label>حداکثر فاصله هوشمند (دقیقه)</label>
						<input type="number" id="sti-queue-smart-max" min="5" max="180" value="<?php echo (int) ( $status['smart_max'] ?? 45 ); ?>">
					</div>
				</div>

				<button type="button" id="sti-queue-save-settings" class="sti-btn secondary">💾 ذخیره تنظیمات صف</button>
				<div id="sti-queue-interval-result" class="sti-inline-result"></div>
			</div>

			<!-- انتشار فوری -->
			<div class="sti-panel">
				<div class="sti-panel-head">
					<div>
						<h2>⚡ انتشار فوری انتخابی</h2>
						<p>دسته‌بندی و تعداد را انتخاب کنید تا همان لحظه از صف خارج و منتشر شوند (بدون انتظار زمان‌بندی).</p>
					</div>
				</div>
				<div class="sti-form-row">
					<div class="sti-field">
						<label>دسته‌بندی</label>
						<select id="sti-queue-pub-cat">
							<option value="0">همه دسته‌ها</option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo (int) $cat->id; ?>"><?php echo esc_html( $cat->telegram_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="sti-field">
						<label>تعداد (حداکثر ۵۰)</label>
						<input type="number" id="sti-queue-pub-limit" min="1" max="50" value="5">
					</div>
				</div>
				<button type="button" id="sti-queue-publish-batch" class="sti-btn">🚀 انتشار فوری</button>
				<div id="sti-queue-pub-result" class="sti-inline-result"></div>
			</div>

			<!-- لیست صف -->
			<div class="sti-panel">
				<div class="sti-panel-head">
					<div>
						<h2>📬 محصولات در صف</h2>
						<p>«خروج از صف» انتشار خودکار را متوقف می‌کند. «حذف کامل» محصول را به زباله‌دان می‌فرستد.</p>
					</div>
				</div>
				<div class="sti-table-wrap">
					<table class="sti-table">
						<thead><tr><th>#</th><th>عنوان / فایل</th><th>دسته</th><th>نوبت</th><th>عملیات</th></tr></thead>
						<tbody>
						<?php if ( empty( $status['queued_items'] ) ) : ?>
							<tr><td colspan="5">صف خالی است.</td></tr>
						<?php else : foreach ( $status['queued_items'] as $i => $item ) :
							$cat = $item->category_id ? STI_Category::get( $item->category_id ) : null;
							?>
							<tr>
								<td>#<?php echo (int) $item->id; ?></td>
								<td><?php if ( $item->product_id ) : ?><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $item->product_id . '&action=edit' ) ); ?>" target="_blank"><?php echo esc_html( $item->file_name ?: ( '#' . $item->product_id ) ); ?></a><?php else : ?>—<?php endif; ?></td>
								<td><?php echo $cat ? esc_html( $cat->telegram_label ) : '—'; ?></td>
								<td><?php echo (int) ( $i + 1 ); ?></td>
								<td>
									<button type="button" class="sti-btn secondary sti-queue-remove" data-id="<?php echo (int) $item->id; ?>" data-delete="0">خروج از صف</button>
									<button type="button" class="sti-btn danger sti-queue-remove" data-id="<?php echo (int) $item->id; ?>" data-delete="1">حذف کامل</button>
								</td>
							</tr>
						<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>

		</div>
	</div>
</div>
<script>
jQuery(function($){
	$('#sti-queue-mode').on('change', function(){
		var m = $(this).val();
		$('#sti-queue-fixed-fields').toggle(m === 'fixed');
		$('#sti-queue-smart-fields').toggle(m === 'smart');
	});
	$('#sti-queue-save-settings').on('click', function(){
		var $btn = $(this).prop('disabled', true);
		$.post(STI.ajaxUrl, {
			action: 'sti_queue_save_interval',
			nonce: STI.nonce,
			interval: $('#sti-queue-interval').val(),
			mode: $('#sti-queue-mode').val(),
			smart_min: $('#sti-queue-smart-min').val(),
			smart_max: $('#sti-queue-smart-max').val()
		}).done(function(r){
			$('#sti-queue-interval-result').text(r.success ? '✅ ' + (r.data && r.data.message ? r.data.message : 'ذخیره شد') : '❌ خطا');
		}).always(function(){ $btn.prop('disabled', false); });
	});
	$('#sti-queue-publish-batch').on('click', function(){
		if (!confirm('محصولات انتخاب‌شده همین الان منتشر شوند؟')) return;
		var $btn = $(this).prop('disabled', true);
		$.post(STI.ajaxUrl, {
			action: 'sti_queue_publish_batch',
			nonce: STI.nonce,
			category_id: $('#sti-queue-pub-cat').val(),
			limit: $('#sti-queue-pub-limit').val()
		}).done(function(r){
			$('#sti-queue-pub-result').text(r.success ? '✅ ' + (r.data && r.data.message ? r.data.message : 'انجام شد') : '❌ ' + (r.data && r.data.message ? r.data.message : 'خطا'));
			if (r.success) setTimeout(function(){ location.reload(); }, 1200);
		}).always(function(){ $btn.prop('disabled', false); });
	});
});
</script>
