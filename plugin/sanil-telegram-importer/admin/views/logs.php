<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

$filters = array(
	'level' => sanitize_key( $_GET['sti_lvl'] ?? '' ),
	'search' => sanitize_text_field( $_GET['sti_q'] ?? '' ),
);
$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
$data = STI_Logger::get_filtered( $filters, $page, 25 );

$level_labels = array(
	'info'    => 'ℹ️ اطلاعات',
	'success' => '✅ موفق',
	'warning' => '⚠️ هشدار',
	'error'   => '❌ خطا',
);
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">

			<div class="sti-header">
				<h1><span class="dashicons dashicons-list-view"></span> گزارش‌ها (Logs)</h1>
				<button type="button" id="sti-logs-clear" class="sti-btn danger">🗑 پاک‌سازی لاگ‌ها</button>
			</div>

			<div class="sti-info-box" style="margin-bottom:16px;">
				📊 <strong><?php echo (int) $data['total']; ?></strong> رویداد ثبت‌شده.
				لاگ‌ها به‌صورت خودکار بعد از ۷ روز پاک می‌شوند.
			</div>

			<div class="sti-panel">
				<form method="get" class="sti-logs-toolbar">
					<input type="hidden" name="page" value="sti-logs">
					<select name="sti_lvl">
						<option value="">همه سطوح</option>
						<?php foreach ( $level_labels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['level'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input name="sti_q" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="جستجو در متن...">
					<button class="sti-btn secondary">فیلتر</button>
					<a class="sti-btn secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-logs' ) ); ?>">پاک‌سازی فیلتر</a>
				</form>

				<?php if ( empty( $data['rows'] ) ) : ?>
					<p class="sti-empty">هیچ رویدادی با این فیلتر پیدا نشد.</p>
				<?php else : ?>
					<div class="sti-table-wrap">
						<table class="sti-table">
							<thead>
								<tr>
									<th>سطح</th>
									<th>پیام</th>
									<th>Session</th>
									<th>زمان</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $data['rows'] as $log ) : ?>
									<tr>
										<td><span class="sti-log-level <?php echo esc_attr( $log->level ); ?>"><?php echo esc_html( $level_labels[ $log->level ] ?? $log->level ); ?></span></td>
										<td><?php echo esc_html( $log->message ); ?></td>
										<td><?php echo $log->session_id ? '#' . (int) $log->session_id : '—'; ?></td>
										<td style="white-space:nowrap;" dir="ltr"><?php echo esc_html( $log->created_at ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php if ( $data['pages'] > 1 ) : ?>
						<div class="sti-pagination">
							<?php for ( $i = max( 1, $page - 2 ); $i <= min( $data['pages'], $page + 2 ); $i++ ) :
								$url = add_query_arg( array_merge( array( 'page' => 'sti-logs', 'paged' => $i ), array_filter( $filters ) ), admin_url( 'admin.php' ) ); ?>
								<a class="<?php echo $i === $page ? 'current' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo (int) $i; ?></a>
							<?php endfor; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>

		</div>
	</div>
</div>

<script>
jQuery(function ($) {
	'use strict';

	$('#sti-logs-clear').on('click', function () {
		if (!confirm('همه‌ی لاگ‌ها پاک شوند؟ (غیرقابل بازگشت)')) return;
		var $btn = $(this);
		$btn.prop('disabled', true);
		$.post(STI.ajaxUrl, { action: 'sti_logs_clear', nonce: STI.nonce })
		.done(function (r) {
			alert(r && r.success ? '✅ لاگ‌ها پاک شدند.' : '❌ ' + ((r && r.data && r.data.message) || 'خطا'));
			location.reload();
		})
		.fail(function () { alert('❌ خطای ارتباط.'); $btn.prop('disabled', false); });
	});
});
</script>
