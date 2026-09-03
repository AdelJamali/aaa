<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$tbl = STI_GS_DB::pipeline_items_table();

/* Sessionهای REVIEW (نهایی‌های معنوی REVIEW) */
$review_states = STI_GS_Stage::review_states();
$place = implode( ',', array_fill( 0, count( $review_states ), '%s' ) );
$items = (array) $wpdb->get_results( $wpdb->prepare(
	"SELECT * FROM {$tbl} WHERE state IN ({$place}) ORDER BY updated_at DESC LIMIT 200",
	$review_states
), ARRAY_A );
?>
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — Review Queue (فهرست بررسی انسانی)</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<p style="max-width:760px;color:#555;">
		این فهرست فقط Sessionهایی را نشان می‌دهد که سیستم خودترمیمی‌شان را تمام کرده
		و یکی از ۴ دلیل مجاز REVIEW را دارند: جریان ناشناخته‌ی ربات، تأیید انسانی،
		دوقلویی حل‌نشده، داده خراب. هر آیتم یک <strong>Fix پیشنهادی</strong> قطعی
		دارد که با یک کلیک اجرا می‌شود (بازتعیین State — بدون حذف داده).
	</p>

	<?php if ( empty( $items ) ) : ?>
		<div class="notice notice-success" style="margin:16px 0;">
			<p>✅ صف REVIEW خالی است — خط تولید بدون مداخله‌ی انسانی کار می‌کند.</p>
		</div>
	<?php else : ?>
		<table class="widefat striped" id="gs-review-table">
			<thead>
				<tr>
					<th>Session</th><th>Stage فعلی</th><th>دلیل REVIEW</th>
					<th>آخرین خطا</th><th>تلاش‌ها</th><th>Fix پیشنهادی</th><th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $items as $row ) :
				$session_id = (int) $row['id'];
				$reason     = STI_GS_Review::reason_of( $row );
				$fix        = STI_GS_Review::suggested_fix( $row );
				$run        = class_exists( 'STI_GS_Run_Log' ) ? STI_GS_Run_Log::for_session( $session_id ) : null;
				$attempts   = (int) ( $row['attempts'] ?? 0 );
				$recoveries = (int) ( $run['recovery_count'] ?? 0 );
				$stage      = STI_GS_Stage::label( (string) $row['state'] );
				$err        = (string) ( $row['error_reason'] ?? '' );
				$err_short  = $err;
				/* دلیل صریح REVIEW (فرمت [REVIEW:REASON]) را از نمایش خارج کن */
				if ( preg_match( '/^\[?REVIEW:[A-Z_]+\]/u', $err, $m ) ) {
					$err_short = trim( substr( $err, strlen( $m[0] ) ) );
				}
			?>
				<tr>
					<td><strong>#<?php echo $session_id; ?></strong>
						<?php if ( ! empty( $row['file_name'] ) ) : ?>
							<br><span style="font-size:11px;color:#777;"><?php echo esc_html( mb_substr( (string) $row['file_name'], 0, 40 ) ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $stage ); ?></td>
					<td><?php echo esc_html( STI_GS_Review::label( $reason ) ); ?></td>
					<td style="max-width:280px;"><?php echo esc_html( mb_substr( $err_short, 0, 160 ) ); ?></td>
					<td><?php echo number_format_i18n( $attempts ); ?> (Recovery: <?php echo number_format_i18n( $recoveries ); ?>)</td>
					<td>
						<strong><?php echo esc_html( $fix['label'] ); ?></strong>
						<br><span style="font-size:11px;color:#777;"><?php echo esc_html( $fix['description'] ); ?></span>
					</td>
					<td style="white-space:nowrap;">
						<?php if ( $fix['action'] ) : ?>
							<button class="button button-primary button-small gs-review-fix"
								data-session="<?php echo $session_id; ?>"
								data-action="<?php echo esc_attr( $fix['action'] ); ?>">
								▶ Run Suggested Fix
							</button>
						<?php else : ?>
							<span style="font-size:11px;color:#777;">مداخله دستی لازم است</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<script>
	(function () {
		if (typeof jQuery === 'undefined') { return; }
		jQuery(document).on('click', '.gs-review-fix', function () {
			var btn = jQuery(this);
			if (btn.prop('disabled')) { return; }
			btn.prop('disabled', true).text('در حال اجرا…');
			jQuery.post(ajaxurl, {
				action: 'sti_gs_review_fix',
				nonce: '<?php echo wp_create_nonce( 'sti_admin_nonce' ); ?>',
				session_id: btn.data('session'),
				fix_action: btn.data('action')
			}, function (res) {
				if (res && res.success) {
					btn.text('✅ اجرا شد').css('color', '#1e7e34');
				} else {
					btn.text('خطا — دوباره').prop('disabled', false);
				}
			}).fail(function () {
				btn.text('خطا — دوباره').prop('disabled', false);
			});
		});
	})();
	</script>
</div>
