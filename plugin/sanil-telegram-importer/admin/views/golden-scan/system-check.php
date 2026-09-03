<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$checks  = STI_GS_System_Check::run();
$summary = STI_GS_System_Check::summarize( $checks );

$grouped = array();
foreach ( $checks as $c ) { $grouped[ $c['group'] ][] = $c; }

$icons = array(
	STI_GS_System_Check::PASS => '🟢',
	STI_GS_System_Check::WARN => '🟡',
	STI_GS_System_Check::FAIL => '🔴',
);
?>
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">🩺 سلامت سیستم</h1>
		<p class="gi-h1-sub">بررسی سلامت زیرساخت — پیش از اسکن و ساخت محصول.</p>
	</div>

	<div class="gi-card gi-span-12" style="margin-bottom:var(--gi-s5);">
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:var(--gi-s4);">
			<div class="gi-stat gi-stat--success"><div class="gi-stat-v">🟢 <?php echo (int) $summary[ STI_GS_System_Check::PASS ]; ?></div><div class="gi-stat-l">سالم</div></div>
			<div class="gi-stat gi-stat--warning"><div class="gi-stat-v">🟡 <?php echo (int) $summary[ STI_GS_System_Check::WARN ]; ?></div><div class="gi-stat-l">هشدار</div></div>
			<div class="gi-stat gi-stat--danger"><div class="gi-stat-v">🔴 <?php echo (int) $summary[ STI_GS_System_Check::FAIL ]; ?></div><div class="gi-stat-l">خطا</div></div>
		</div>
	</div>

	<?php if ( $summary[ STI_GS_System_Check::FAIL ] > 0 ) : ?>
		<div class="gi-notice gi-notice--danger" style="margin:0 0 var(--gi-s4);">
			<strong>سیستم آماده نیست.</strong> موارد قرمز پایین باید رفع شوند. تا آن زمان اسکن یا ساخت محصول انجام ندهید.
		</div>
	<?php elseif ( $summary[ STI_GS_System_Check::WARN ] > 0 ) : ?>
		<div class="gi-notice gi-notice--warning" style="margin:0 0 var(--gi-s4);">
			سیستم کار می‌کند ولی چند هشدار دارد. می‌توانید ادامه دهید.
		</div>
	<?php else : ?>
		<div class="gi-notice gi-notice--success" style="margin:0 0 var(--gi-s4);">
			<strong>همه‌چیز سالم است.</strong>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=test-wizard' ) ); ?>">به تب «تست خودکار» بروید ←</a>
		</div>
	<?php endif; ?>

	<div class="gi-bento">
	<?php foreach ( $grouped as $group => $rows ) : ?>
		<div class="gi-card gi-card--flush gi-span-12">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) var(--gi-s3);">
				<h2 class="gi-card-title"><?php echo esc_html( $group ); ?></h2>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table gi-responsive">
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td data-label="نتیجه" style="width:44px;font-size:18px;"><?php echo $icons[ $row['status'] ]; ?></td>
								<td data-label="بررسی" style="font-weight:700;min-width:200px;"><?php echo esc_html( $row['label'] ); ?></td>
								<td data-label="جزئیات"><?php echo esc_html( $row['detail'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endforeach; ?>

	<div class="gi-span-12">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=system-check' ) ); ?>"
		   class="gi-btn gi-btn--primary" style="text-decoration:none;display:inline-flex;">⟳ بررسی دوباره</a>
	</div>
	</div>
</div>
