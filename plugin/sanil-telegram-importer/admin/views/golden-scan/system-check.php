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
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — بررسی سیستم</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div style="display:flex;gap:12px;margin:16px 0;flex-wrap:wrap;">
		<div style="flex:1;min-width:120px;padding:14px;border-radius:8px;background:#e8f5e9;border:1px solid #a5d6a7;">
			<div style="font-size:26px;font-weight:700;">🟢 <?php echo (int) $summary[ STI_GS_System_Check::PASS ]; ?></div>
			<div>سالم</div>
		</div>
		<div style="flex:1;min-width:120px;padding:14px;border-radius:8px;background:#fff8e1;border:1px solid #ffe082;">
			<div style="font-size:26px;font-weight:700;">🟡 <?php echo (int) $summary[ STI_GS_System_Check::WARN ]; ?></div>
			<div>هشدار</div>
		</div>
		<div style="flex:1;min-width:120px;padding:14px;border-radius:8px;background:#ffebee;border:1px solid #ef9a9a;">
			<div style="font-size:26px;font-weight:700;">🔴 <?php echo (int) $summary[ STI_GS_System_Check::FAIL ]; ?></div>
			<div>خطا</div>
		</div>
	</div>

	<?php if ( $summary[ STI_GS_System_Check::FAIL ] > 0 ) : ?>
		<div class="notice notice-error" style="margin:0 0 16px;">
			<p><strong>سیستم آماده نیست.</strong> موارد قرمز پایین باید رفع شوند. تا آن زمان اسکن یا ساخت محصول انجام ندهید.</p>
		</div>
	<?php elseif ( $summary[ STI_GS_System_Check::WARN ] > 0 ) : ?>
		<div class="notice notice-warning" style="margin:0 0 16px;">
			<p>سیستم کار می‌کند ولی چند هشدار دارد. می‌توانید ادامه دهید.</p>
		</div>
	<?php else : ?>
		<div class="notice notice-success" style="margin:0 0 16px;">
			<p><strong>همه‌چیز سالم است.</strong> می‌توانید به تب «تست خودکار» بروید.</p>
		</div>
	<?php endif; ?>

	<?php foreach ( $grouped as $group => $rows ) : ?>
		<h2 style="margin-top:22px;"><?php echo esc_html( $group ); ?></h2>
		<table class="widefat striped">
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td style="width:34px;font-size:16px;"><?php echo $icons[ $row['status'] ]; ?></td>
					<td style="width:240px;font-weight:600;"><?php echo esc_html( $row['label'] ); ?></td>
					<td><?php echo esc_html( $row['detail'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>

	<p style="margin-top:18px;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=system-check' ) ); ?>"
		   class="button button-primary">بررسی دوباره</a>
	</p>
</div>
