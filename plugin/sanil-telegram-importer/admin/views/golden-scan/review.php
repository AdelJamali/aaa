<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$tbl = STI_GS_DB::pipeline_items_table();

/* Sessionهای REVIEW (نهایی‌های معنوی REVIEW) */
$review_states = STI_GS_Stage::review_states();
$place = implode( ',', array_fill( 0, count( $review_states ), '%s' ) );
$items = (array) $wpdb->get_results( $wpdb->prepare(
	"SELECT id, file_name, state, error_reason, attempts FROM {$tbl} WHERE state IN ({$place}) ORDER BY updated_at DESC LIMIT 200",
	$review_states
), ARRAY_A );

$state_labels = array( 'NEEDS_REVIEW' => 'REVIEW', 'ERROR_FILE_NOT_FOUND' => 'REVIEW (فایل گم)', 'DEAD_LETTER' => 'REVIEW (صف مرده)' );
?>
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — Review Queue (فهرست بررسی انسانی)</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<p style="max-width:760px;color:#555;">
		این فهرست فقط Sessionهایی را نشان می‌دهد که سیستم خودترمیمی‌شان را تمام کرده
		و یکی از ۴ دلیل مجاز REVIEW را دارند: جریان ناشناخته‌ی ربات، تأیید انسانی،
		دوقلویی حل‌نشده، داده خراب. هر آیتم یک <strong>Fix پیشنهادی</strong> قطعی
		دارد که با یک کلیک اجرا می‌شود (بازتعیین State — بدون حذف داده) و بلافاصله
		<strong>Verify</strong> می‌شود: اگر موفق بود Session به خط تولید بازمی‌گردد،
		اگر ناموفق بود دلیل جدید ثبت شده است.
	</p>

	<div class="notice notice-info" style="margin:16px 0;max-width:760px;font-size:12px;">
		🎯 هدف Review Queue «محل توقف عادی» نیست — آخرین fallback است. هر چیزی که
		قابل خودترمیمی باشد، قبل از رسیدن به اینجا، خودش ترمیم می‌شود.
	</div>

	<?php if ( empty( $items ) ) : ?>
		<div class="notice notice-success" id="gs-review-empty" style="margin:16px 0;">
			<p>✅ صف REVIEW خالی است — خط تولید بدون مداخله‌ی انسانی کار می‌کند.</p>
		</div>
	<?php else : ?>
		<table class="widefat striped" id="gs-review-table">
			<thead>
				<tr>
					<th>Session</th><th>Telegram File</th><th>Stage</th><th>دلیل FAILURE</th>
					<th>آخرین خطا</th><th>Attempts</th><th>Recovery</th><th>Fix پیشنهادی</th>
					<th>Status</th><th></th>
				</tr>
			</thead>
			<tbody id="gs-review-body">
			<?php foreach ( $items as $row ) :
				$session_id = (int) $row['id'];
				$reason     = STI_GS_Review::reason_of( $row );
				$fix        = STI_GS_Review::suggested_fix( $row );
				$state      = (string) $row['state'];
				$run        = STI_GS_Run_Log::for_session( $session_id );
				$attempts   = (int) ( $row['attempts'] ?? 0 );
				$recoveries = (int) ( $run['recovery_count'] ?? 0 );
				$err        = (string) ( $row['error_reason'] ?? '' );
				$err_short  = $err;
				/* دلیل صریح REVIEW (فرمت [REVIEW:REASON]) را از نمایش خارج کن */
				if ( preg_match( '/^\[?REVIEW:[A-Z_]+\]/u', $err, $m ) ) {
					$err_short = trim( substr( $err, strlen( $m[0] ) ) );
				}
			?>
				<tr data-session="<?php echo $session_id; ?>">
					<td><strong>#<?php echo $session_id; ?></strong></td>
					<td style="max-width:180px;"><?php echo esc_html( mb_substr( (string) $row['file_name'], 0, 36 ) ?: '—' ); ?></td>
					<td><?php echo esc_html( STI_GS_Stage::label( $state ) ); ?></td>
					<td><?php echo esc_html( STI_GS_Review::label( $reason ) ); ?></td>
					<td style="max-width:240px;"><?php echo esc_html( mb_substr( $err_short, 0, 140 ) ?: '—' ); ?></td>
					<td><?php echo number_format_i18n( $attempts ); ?></td>
					<td><?php echo number_format_i18n( $recoveries ); ?></td>
					<td>
						<strong><?php echo esc_html( $fix['label'] ); ?></strong>
						<br><span style="font-size:11px;color:#777;"><?php echo esc_html( mb_substr( $fix['description'], 0, 90 ) ); ?></span>
					</td>
					<td><span class="sti-badge" style="background:#6a1b9a;color:#fff;"><?php echo esc_html( $state_labels[ $state ] ?? 'REVIEW' ); ?></span></td>
					<td style="white-space:nowrap;">
						<?php if ( $fix['action'] ) : ?>
							<button type="button" class="button button-primary button-small gs-review-fix"
								data-session="<?php echo $session_id; ?>"
								data-action="<?php echo esc_attr( $fix['action'] ); ?>">
								▶ اجرای Fix پیشنهادی
							</button>
						<?php else : ?>
							<span style="font-size:11px;color:#777;">مداخله‌ی دستی لازم است (ورود اکانت)</span>
						<?php endif; ?>
						<div class="gs-fix-result" style="font-size:11px;margin-top:3px;"></div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<script>
(function () {
	if (typeof jQuery === 'undefined') { return; }
	var A = window.STI || {};

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	/* Run Suggested Fix + Verify */
	jQuery(document).on('click', '.gs-review-fix', function () {
		var $btn = $(this),
			$row = $btn.closest('tr'),
			$out = $row.find('.gs-fix-result');
		$btn.prop('disabled', true);
		$out.text('در حال اجرا…').css('color', '#9a6b00');
		$.post(A.ajaxUrl, { action: 'sti_gs_review_fix', nonce: A.nonce, session_id: $btn.data('session'), fix_action: $btn.data('action') })
			.done(function (res) {
				if (res && res.success) {
					var v = res.data.verify;
					var txt = '✅ ' + esc(res.data.message) + (v ? ' — Verify: state جدید «' + esc(v.label) + '» — ادامه‌ی pipeline.' : '');
					$out.html(txt).css('color', '#1e7e34');
					/* poll بعدی ردیف را از صف خارج می‌کند */
				} else {
					$out.text('❌ ' + ((res.data && res.data.message) || 'اجرا نشد')).css('color', '#c62828');
					$btn.prop('disabled', false);
				}
			})
			.fail(function () {
				$out.text('❌ خطای ارتباط').css('color', '#c62828');
				$btn.prop('disabled', false);
			});
	});

	/* poll سبک — جدول static نیست (هر ۱۰ ثانیه؛ single-flight) */
	var inFlight = false;
	function poll() {
		if (inFlight || document.hidden) { return; }
		inFlight = true;
		$.post(A.ajaxUrl, { action: 'sti_gs_review_poll', nonce: A.nonce })
			.done(function (res) {
				if (!res || !res.success || !res.data) { return; }
				var items = res.data.items;
				var $tb = $('#gs-review-body');
				if (!items.length) {
					if ($('#gs-review-table').length) {
						$('#gs-review-table').hide();
						if (!$('#gs-review-empty').length) {
							$('<div class="notice notice-success" id="gs-review-empty" style="margin:16px 0;"><p>✅ صف REVIEW خالی است — خط تولید بدون مداخله‌ی انسانی کار می‌کند.</p></div>').insertAfter('.sti-info-box, h1');
						} else {
							$('#gs-review-empty').show();
						}
					}
					return;
				}
				/* اگر صف دوباره پر شد (بعد از خالی)، جدول را برگردان */
				if (!$('#gs-review-table').is(':visible')) {
					$('#gs-review-table').show();
					$('#gs-review-empty').hide();
				}
				if (!$tb.length) { return; }
				var html = '';
				$.each(items, function (i, it) {
					html += '<tr data-session="' + it.id + '">'
						+ '<td><strong>#' + it.id + '</strong></td>'
						+ '<td style="max-width:180px;">' + esc(it.file || '—') + '</td>'
						+ '<td>' + esc(it.stage) + '</td>'
						+ '<td>' + esc(it.reason) + '</td>'
						+ '<td style="max-width:240px;">' + esc(it.error || '—') + '</td>'
						+ '<td>' + it.attempts + '</td>'
						+ '<td>' + it.recovery + '</td>'
						+ '<td><strong>' + esc(it.fix_label) + '</strong><br><span style="font-size:11px;color:#777;">' + esc(it.fix_desc) + '</span></td>'
						+ '<td><span class="sti-badge" style="background:#6a1b9a;color:#fff;">REVIEW</span></td>'
						+ '<td style="white-space:nowrap;">'
						+ (it.fix_action
							? '<button type="button" class="button button-primary button-small gs-review-fix" data-session="' + it.id + '" data-action="' + esc(it.fix_action) + '">▶ اجرای Fix پیشنهادی</button>'
							: '<span style="font-size:11px;color:#777;">مداخله‌ی دستی لازم است (ورود اکانت)</span>')
						+ '<div class="gs-fix-result" style="font-size:11px;margin-top:3px;"></div></td>'
						+ '</tr>';
				});
				$tb.html(html);
			})
			.always(function () { inFlight = false; });
	}
	setInterval(poll, 10000);
})();
</script>
