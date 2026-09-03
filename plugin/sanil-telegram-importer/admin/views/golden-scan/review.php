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
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">📥 Inbox استثنائات — Review</h1>
		<p class="gi-h1-sub">این فهرست فقط Sessionهایی را نشان می‌دهد که سیستم خودترمیمی‌شان را تمام کرده و یکی از ۴ دلیل مجاز REVIEW را دارند: جریان ناشناخته‌ی ربات، تأیید انسانی، دوقلویی حل‌نشده، داده خراب. هر آیتم یک <strong>Fix پیشنهادی</strong> قطعی دارد که با یک کلیک اجرا می‌شود (بازتعیین State — بدون حذف داده) و بلافاصله <strong>Verify</strong> می‌شود.</p>
	</div>

	<div class="gi-flex" style="margin-bottom:var(--gi-s5);">
		<span class="gi-badge gi-badge--danger" style="font-size:var(--gi-fs1);padding:8px 14px;">
			⚠ در صف Review: <span class="gi-nums" id="gs-review-count"><?php echo count( $items ); ?></span>
		</span>
		<span class="gi-card-sub" style="margin-inline-start:auto;">🎯 Review آخرین fallback است — هر چیزی که قابل خودترمیمی باشد، قبل از رسیدن به اینجا ترمیم می‌شود. (به‌روزرسانی زنده هر ۱۰ ثانیه)</span>
	</div>

	<div id="gs-review-list" class="gi-exc-grid--list" aria-live="polite">
		<?php if ( empty( $items ) ) : ?>
			<div class="gi-empty gi-mt-5" style="grid-column:1/-1;padding:var(--gi-s8) var(--gi-s5);" id="gs-review-empty">
				<div class="gi-empty-ico" aria-hidden="true">✅</div>
				<div class="gi-empty-title">صف REVIEW خالی است — خط تولید بدون مداخله‌ی انسانی کار می‌کند.</div>
				<div class="gi-empty-sub">هر Sessionی که اینجا ظاهر شود، یعنی خودترمیمی تمام شده و یک Fix قطعی پیشنهاد شده است.</div>
			</div>
		<?php else : ?>
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
				<article class="gi-exc gi-card" data-session="<?php echo $session_id; ?>">
					<header class="gi-exc-head">
						<span class="gi-dot gi-dot--error" aria-hidden="true"></span>
						<span class="gi-badge gi-badge--brand" style="font-size:var(--gi-fs1);">Session #<span class="gi-nums"><?php echo $session_id; ?></span></span>
						<span class="gi-badge"><?php echo esc_html( STI_GS_Stage::label( $state ) ); ?></span>
						<span class="gi-badge gi-badge--warning"><?php echo esc_html( STI_GS_Review::label( $reason ) ); ?></span>
						<span class="gi-card-sub" style="margin-inline-start:auto;"><?php echo esc_html( mb_substr( (string) $row['file_name'], 0, 40 ) ?: '—' ); ?></span>
					</header>
					<div class="gi-exc-grid">
						<div><div class="gi-exc-k">چه چیزی شکست؟</div><div class="gi-exc-v"><?php echo esc_html( STI_GS_Stage::label( $state ) ); ?></div></div>
						<div><div class="gi-exc-k">Session / فایل</div><div class="gi-exc-v gi-mono" style="font-size:var(--gi-fs0);">#<?php echo $session_id; ?> · <?php echo esc_html( mb_substr( (string) $row['file_name'], 0, 36 ) ?: '—' ); ?></div></div>
						<div><div class="gi-exc-k">دلیل FAILURE</div><div class="gi-exc-v"><?php echo esc_html( STI_GS_Review::label( $reason ) ); ?></div></div>
						<div><div class="gi-exc-k">آخرین خطا</div><div class="gi-exc-v gi-mono" style="font-size:var(--gi-fs0);"><?php echo esc_html( mb_substr( $err_short, 0, 140 ) ?: '—' ); ?></div></div>
						<div><div class="gi-exc-k">تلاش‌ها</div><div class="gi-exc-v gi-nums"><?php echo number_format_i18n( $attempts ); ?> بار</div></div>
						<div><div class="gi-exc-k">بازگشت‌های خودکار</div><div class="gi-exc-v gi-nums"><?php echo number_format_i18n( $recoveries ); ?> بار</div></div>
					</div>
					<div class="gi-exc-fix" style="margin-top:var(--gi-s3);background:var(--gi-brand-soft);border-radius:12px;padding:var(--gi-s3);">
						<div class="gi-exc-k" style="color:var(--gi-brand);">💡 Fix پیشنهادی</div>
						<div class="gi-exc-v"><strong><?php echo esc_html( $fix['label'] ); ?></strong> — <span class="gi-card-sub"><?php echo esc_html( mb_substr( $fix['description'], 0, 120 ) ); ?></span></div>
					</div>
					<footer class="gi-exc-actions">
						<?php if ( $fix['action'] ) : ?>
							<button type="button" class="gi-btn gi-btn--primary gs-review-fix"
								data-session="<?php echo $session_id; ?>"
								data-action="<?php echo esc_attr( $fix['action'] ); ?>">
								⚡ اجرای Fix پیشنهادی
							</button>
						<?php else : ?>
							<span class="gi-badge gi-badge--warning">مداخله‌ی دستی لازم است (ورود اکانت)</span>
						<?php endif; ?>
						<span class="gs-fix-result gi-inline-res" role="status" aria-live="polite"></span>
					</footer>
				</article>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>

<script>
(function () {
	if (typeof jQuery === 'undefined') { return; }
	var A = window.STI || {};

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	/* Run Suggested Fix + Verify — contract 10.11: session_id + fix_action */
	jQuery(document).on('click', '.gs-review-fix', function () {
		var $btn = $(this),
			$card = $btn.closest('.gi-exc'),
			$out = $card.find('.gs-fix-result');
		$btn.prop('disabled', true);
		$out.text('در حال اجرا…');
		$.post(A.ajaxUrl, { action: 'sti_gs_review_fix', nonce: A.nonce, session_id: $btn.data('session'), fix_action: $btn.data('action') })
			.done(function (res) {
				if (res && res.success) {
					var v = res.data.verify;
					var txt = '✅ ' + esc(res.data.message) + (v ? ' — Verify: state جدید «' + esc(v.label) + '» — ادامه‌ی pipeline.' : '');
					$out.html(txt).addClass('ok');
					/* poll بعدی ردیف را از صف خارج می‌کند */
				} else {
					$out.text('❌ ' + ((res.data && res.data.message) || 'اجرا نشد')).addClass('err');
					$btn.prop('disabled', false);
				}
			})
			.fail(function () {
				$out.text('❌ خطای ارتباط').addClass('err');
				$btn.prop('disabled', false);
			});
	});

	/* poll سبک — contract 10.11: هر ۱۰ ثانیه؛ single-flight */
	var inFlight = false;
	function excCard(it) {
		return '<article class="gi-exc gi-card" data-session="' + it.id + '">'
			+ '<header class="gi-exc-head">'
			+ '<span class="gi-dot gi-dot--error" aria-hidden="true"></span>'
			+ '<span class="gi-badge gi-badge--brand" style="font-size:var(--gi-fs1);">Session #<span class="gi-nums">' + it.id + '</span></span>'
			+ '<span class="gi-badge">' + esc(it.stage) + '</span>'
			+ '<span class="gi-badge gi-badge--warning">' + esc(it.reason) + '</span>'
			+ '<span class="gi-card-sub" style="margin-inline-start:auto;">' + esc(it.file || '—') + '</span>'
			+ '</header>'
			+ '<div class="gi-exc-grid">'
			+ '<div><div class="gi-exc-k">چه چیزی شکست؟</div><div class="gi-exc-v">' + esc(it.stage) + '</div></div>'
			+ '<div><div class="gi-exc-k">Session / فایل</div><div class="gi-exc-v gi-mono" style="font-size:var(--gi-fs0);">#' + it.id + ' · ' + esc(it.file || '—') + '</div></div>'
			+ '<div><div class="gi-exc-k">دلیل FAILURE</div><div class="gi-exc-v">' + esc(it.reason) + '</div></div>'
			+ '<div><div class="gi-exc-k">آخرین خطا</div><div class="gi-exc-v gi-mono" style="font-size:var(--gi-fs0);">' + esc(it.error || '—') + '</div></div>'
			+ '<div><div class="gi-exc-k">تلاش‌ها</div><div class="gi-exc-v gi-nums">' + it.attempts + ' بار</div></div>'
			+ '<div><div class="gi-exc-k">بازگشت‌های خودکار</div><div class="gi-exc-v gi-nums">' + it.recovery + ' بار</div></div>'
			+ '</div>'
			+ '<div class="gi-exc-fix" style="margin-top:var(--gi-s3);background:var(--gi-brand-soft);border-radius:12px;padding:var(--gi-s3);">'
			+ '<div class="gi-exc-k" style="color:var(--gi-brand);">💡 Fix پیشنهادی</div>'
			+ '<div class="gi-exc-v"><strong>' + esc(it.fix_label) + '</strong> — <span class="gi-card-sub">' + esc(it.fix_desc) + '</span></div>'
			+ '</div>'
			+ '<footer class="gi-exc-actions">'
			+ (it.fix_action
				? '<button type="button" class="gi-btn gi-btn--primary gs-review-fix" data-session="' + it.id + '" data-action="' + esc(it.fix_action) + '">⚡ اجرای Fix پیشنهادی</button>'
				: '<span class="gi-badge gi-badge--warning">مداخله‌ی دستی لازم است (ورود اکانت)</span>')
			+ '<span class="gs-fix-result gi-fix-result gi-inline-res" role="status" aria-live="polite"></span>'
			+ '</footer>'
			+ '</article>';
	}
	function emptyCard() {
		return '<div class="gi-empty gi-mt-5" style="grid-column:1/-1;padding:var(--gi-s8) var(--gi-s5);" id="gs-review-empty">'
			+ '<div class="gi-empty-ico" aria-hidden="true">✅</div>'
			+ '<div class="gi-empty-title">صف REVIEW خالی است — خط تولید بدون مداخله‌ی انسانی کار می‌کند.</div>'
			+ '<div class="gi-empty-sub">هر Sessionی که اینجا ظاهر شود، یعنی خودترمیمی تمام شده و یک Fix قطعی پیشنهاد شده است.</div>'
			+ '</div>';
	}
	function poll() {
		if (inFlight || document.hidden) { return; }
		inFlight = true;
		$.post(A.ajaxUrl, { action: 'sti_gs_review_poll', nonce: A.nonce })
			.done(function (res) {
				if (!res || !res.success || !res.data) { return; }
				var items = res.data.items;
				var $list = $('#gs-review-list');
				if (!items.length) {
					$list.html(emptyCard());
					$('#gs-review-count').text(0);
					return;
				}
				$('#gs-review-count').text(items.length);
				var html = '';
				$.each(items, function (i, it) { html += excCard(it); });
				$list.html(html);
			})
			.always(function () { inFlight = false; });
	}
	setInterval(poll, 10000);
})();
</script>
