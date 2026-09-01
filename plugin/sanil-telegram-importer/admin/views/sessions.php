<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

$statuses = array(
	''           => 'همه وضعیت‌ها',
	'open'       => 'باز (ناقص)',
	'processing' => 'در حال ساخت',
	'scheduled'  => 'در صف انتشار',
	'published'  => 'منتشرشده',
	'cancelled'  => 'لغوشده',
	'error'      => 'خطا',
);

$filters = array(
	'status'      => sanitize_key( $_GET['sti_status'] ?? '' ),
	'category_id' => absint( $_GET['sti_category'] ?? 0 ),
	'file_code'   => sanitize_text_field( $_GET['sti_code'] ?? '' ),
	'date_from'   => sanitize_text_field( $_GET['sti_from'] ?? '' ),
	'date_to'     => sanitize_text_field( $_GET['sti_to'] ?? '' ),
);
$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
$data = STI_Session::get_filtered_page( $filters, $page, 30 );
$cats = STI_Category::get_all();
$queue_status = STI_Scheduler::get_status();
?>
<div class="wrap sti-wrap"><div class="sti-shell"><?php include __DIR__ . '/partials-tabs.php'; ?><div class="sti-content">
<div class="sti-header">
	<h1><span class="dashicons dashicons-list-view"></span> Session ها و عملیات گروهی</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-queue' ) ); ?>" class="sti-btn secondary">🕓 مدیریت صف انتشار</a>
</div>

<?php if ( ! empty( $queue_status['health']['healthy'] ) ) : ?>
	<div class="sti-info-box" style="margin-bottom:16px;">
		✅ صف انتشار فعال است — هر <strong><?php echo (int) $queue_status['interval_minutes']; ?> دقیقه</strong> یک محصول منتشر می‌شود
		(<?php echo (int) $queue_status['queued_count']; ?> در صف)
	</div>
<?php else : ?>
	<div class="sti-info-box" style="margin-bottom:16px;border-color:#fcd34d;background:#fffbeb;">
		⚠️ زمان‌بند صف (WP-Cron) ثبت نشده است. برای رفع: از صفحه‌ی «صف انتشار» دکمه‌ی توقف/شروع را بزنید.
	</div>
<?php endif; ?>

<div class="sti-panel">
	<form method="get" class="sti-bulk-toolbar">
		<input type="hidden" name="page" value="sti-sessions">
		<select name="sti_status">
			<?php foreach ( $statuses as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="sti_category">
			<option value="0">همه دسته‌ها</option>
			<?php foreach ( $cats as $cat ) : ?>
				<option value="<?php echo (int) $cat->id; ?>" <?php selected( $filters['category_id'], $cat->id ); ?>><?php echo esc_html( $cat->telegram_label ); ?></option>
			<?php endforeach; ?>
		</select>
		<input name="sti_code" value="<?php echo esc_attr( $filters['file_code'] ); ?>" placeholder="File Code" style="min-width:120px;">
		<input type="date" name="sti_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
		<input type="date" name="sti_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
		<button class="sti-btn secondary">فیلتر</button>
		<a class="sti-btn secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-sessions' ) ); ?>">پاک‌سازی</a>
	</form>

	<div class="sti-bulk-toolbar" style="margin-top:12px;">
		<strong>عملیات انتخاب‌شده‌ها:</strong>
		<select id="sti-bulk-action">
			<option value="">انتخاب عملیات</option>
			<option value="retry">تلاش دوباره</option>
			<option value="cancel">لغو Session</option>
			<option value="remove_queue">خروج از صف</option>
			<option value="publish_now">انتشار فوری</option>
			<option value="repair_image">ترمیم تصویر</option>
		</select>
		<button type="button" id="sti-bulk-run" class="sti-btn">اجرا</button>
		<span id="sti-bulk-result" class="sti-inline-result"></span>
	</div>

	<div class="sti-table-wrap" style="margin-top:14px;">
		<table class="sti-table">
			<thead>
				<tr>
					<th><input type="checkbox" id="sti-select-all"></th>
					<th>#</th>
					<th>کد فایل</th>
					<th>دسته</th>
					<th>نام فایل</th>
					<th>وضعیت / نقص</th>
					<th>محصول</th>
					<th>تاریخ</th>
					<th>عملیات</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $data['rows'] ) ) : ?>
					<tr><td colspan="9" class="sti-empty">موردی پیدا نشد.</td></tr>
				<?php else :
					foreach ( $data['rows'] as $s ) :
						$cat = $s->category_id ? STI_Category::get( $s->category_id ) : null;
						$missing = '';
						if ( in_array( $s->status, array( 'open', 'error', 'processing' ), true ) ) {
							$missing = STI_Session::missing_fields_message( $s );
						}
					?>
					<tr>
						<td><input class="sti-session-select" type="checkbox" value="<?php echo (int) $s->id; ?>"></td>
						<td>#<?php echo (int) $s->id; ?></td>
						<td dir="ltr"><code><?php echo esc_html( $s->file_code ?: '—' ); ?></code></td>
						<td><?php echo $cat ? esc_html( $cat->telegram_label ) : '—'; ?></td>
						<td class="sti-filename-cell"><span class="sti-filename" title="<?php echo esc_attr( $s->file_name ?: '' ); ?>"><?php echo esc_html( $s->file_name ?: '—' ); ?></span></td>
						<td>
							<span class="sti-badge <?php echo esc_attr( $s->status ); ?>"><?php echo esc_html( $statuses[ $s->status ] ?? $s->status ); ?></span>
							<?php if ( $missing ) : ?>
								<div class="sti-missing-note"><?php echo esc_html( $missing ); ?></div>
							<?php endif; ?>
							<?php if ( 'error' === $s->status && $s->error_message ) : ?>
								<div class="sti-error-note"><?php echo esc_html( $s->error_message ); ?></div>
							<?php endif; ?>
							<?php if ( 'scheduled' === $s->status && $s->queue_next_attempt_at ) : ?>
								<div style="color:#4f46e5;font-size:11.5px;margin-top:4px;line-height:1.4;">⏰ انتشار: <?php echo esc_html( $s->queue_next_attempt_at ); ?></div>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $s->product_id ) : ?>
								<a target="_blank" href="<?php echo esc_url( get_edit_post_link( $s->product_id ) ); ?>">#<?php echo (int) $s->product_id; ?></a>
							<?php else : ?>—<?php endif; ?>
						</td>
						<td style="white-space:nowrap;font-size:12px;"><?php echo esc_html( $s->created_at ); ?></td>
						<td style="white-space:nowrap;">
							<?php if ( 'scheduled' === $s->status ) : ?>
								<button type="button" class="sti-btn-sm sti-publish-now" data-id="<?php echo (int) $s->id; ?>">🚀 انتشار فوری</button>
							<?php endif; ?>
							<?php if ( in_array( $s->status, array( 'error', 'processing' ), true ) ) : ?>
								<button type="button" class="sti-btn-sm secondary sti-retry-session" data-id="<?php echo (int) $s->id; ?>">Retry</button>
							<?php endif; ?>
							<?php if ( in_array( $s->status, array( 'open', 'processing', 'error', 'scheduled' ), true ) ) : ?>
								<button type="button" class="sti-btn-sm danger sti-cancel-session" data-id="<?php echo (int) $s->id; ?>">لغو</button>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>

	<?php if ( $data['pages'] > 1 ) : ?>
		<div class="sti-pagination" style="display:flex;gap:6px;justify-content:center;margin-top:18px;flex-wrap:wrap;">
			<?php for ( $i = max( 1, $page - 2 ); $i <= min( $data['pages'], $page + 2 ); $i++ ) :
				$url = add_query_arg( array_merge( array( 'page' => 'sti-sessions', 'paged' => $i ), array_filter( $filters ) ), admin_url( 'admin.php' ) ); ?>
				<a class="<?php echo $i === $page ? 'current' : ''; ?>" style="display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 10px;border-radius:9px;border:1.5px solid #e2e6ef;background:<?php echo $i === $page ? '#4f46e5' : '#fff'; ?>;color:<?php echo $i === $page ? '#fff' : '#4b5563'; ?>;font-size:12.5px;font-weight:600;text-decoration:none;" href="<?php echo esc_url( $url ); ?>"><?php echo (int) $i; ?></a>
			<?php endfor; ?>
		</div>
	<?php endif; ?>
</div>
</div></div></div>

<script>
jQuery(function ($) {
	'use strict';
	$(document).on('click', '.sti-publish-now', function () {
		var $btn = $(this);
		if (!confirm('محصول این Session همین حالا منتشر شود؟')) return;
		$btn.prop('disabled', true);
		$.post(STI.ajaxUrl, { action: 'sti_publish_now', nonce: STI.nonce, session_id: $btn.data('id') })
		.done(function (r) {
			alert(r && r.success ? '✅ منتشر شد.' : '❌ ' + ((r && r.data && r.data.message) || 'خطا'));
			location.reload();
		})
		.fail(function () { alert('❌ خطای ارتباط.'); $btn.prop('disabled', false); });
	});
});
</script>
