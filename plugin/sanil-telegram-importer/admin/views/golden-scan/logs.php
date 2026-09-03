<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$level = isset( $_GET['gs_level'] ) ? sanitize_key( $_GET['gs_level'] ) : '';
$page  = max( 1, (int) ( $_GET['gs_page'] ?? 1 ) );

$filters = array();
if ( $level ) { $filters['level'] = $level; }
// STI_Logger::get_filtered() کلید 'rows' برمی‌گرداند، نه 'items'.
$result = STI_Logger::get_filtered( $filters, $page, 30 );
$rows   = isset( $result['rows'] ) ? (array) $result['rows'] : array();
$total  = isset( $result['total'] ) ? (int) $result['total'] : count( $rows );
$pages  = isset( $result['pages'] ) ? (int) $result['pages'] : 1;

/* icon + label — رنگ هرگز تنها نشانگر وضعیت نیست */
$badge = array(
	'error'   => '🔴',
	'warning' => '🟡',
	'success' => '🟢',
	'info'    => '⚪',
);
$base = admin_url( 'admin.php?page=sti-golden-scan&gs_view=logs' );
?>
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">📝 گزارش‌ها</h1>
		<p class="gi-h1-sub">همان چیزی که در حالت عادی باید داخل <code>debug.log</code> دنبالش می‌گشتید — از backend واقعی، بدون mock.</p>
	</div>

	<div class="gi-flex" style="margin-bottom:var(--gi-s5);">
		<?php foreach ( array( '' => 'همه', 'error' => '🔴 خطا', 'warning' => '🟡 هشدار', 'success' => '🟢 موفق', 'info' => '⚪ اطلاع' ) as $key => $label ) : ?>
			<a class="gi-btn <?php echo $level === $key ? 'gi-btn--primary' : 'gi-btn--subtle'; ?>"
			   style="text-decoration:none;display:inline-flex;"
			   <?php echo $level === $key ? 'aria-current="page"' : ''; ?>
			   href="<?php echo esc_url( $key ? add_query_arg( 'gs_level', $key, $base ) : $base ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
		<span class="gi-card-sub" style="margin-inline-start:auto;"><span class="gi-nums"><?php echo number_format_i18n( $total ); ?></span> ردیف</span>
	</div>

	<?php if ( empty( $rows ) ) : ?>
		<div class="gi-empty gi-mt-5" style="padding:var(--gi-s8) var(--gi-s5);">
			<div class="gi-empty-ico" aria-hidden="true">📝</div>
			<div class="gi-empty-title">هیچ گزارشی ثبت نشده.</div>
			<div class="gi-empty-sub">با اجرا شدن خط تولید، رویدادها اینجا ظاهر می‌شوند.</div>
		</div>
	<?php else : ?>
	<div class="gi-card gi-card--flush">
		<div class="gi-table-wrap" style="border:none;border-radius:0;">
			<table class="gi-table gi-responsive">
				<thead>
					<tr>
						<th scope="col" style="width:170px;">زمان</th>
						<th scope="col" style="width:110px;">سطح</th>
						<th scope="col" style="width:110px;">Session</th>
						<th scope="col">پیام</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) :
					$row = (array) $row;
					$lv  = (string) ( $row['level'] ?? 'info' );
					$b   = $badge[ $lv ] ?? $badge['info'];
				?>
					<tr data-level="<?php echo esc_attr( $lv ); ?>">
						<td data-label="زمان" style="white-space:nowrap;"><?php echo esc_html( $row['created_at'] ?? '' ); ?></td>
						<td data-label="سطح"><span class="gi-badge <?php echo 'error' === $lv ? 'gi-badge--danger' : ( 'warning' === $lv ? 'gi-badge--warning' : ( 'success' === $lv ? 'gi-badge--success' : '' ) ); ?>"><?php echo $b . ' ' . esc_html( $lv ); ?></span></td>
						<td data-label="Session" class="gi-nums"><?php echo (int) ( $row['session_id'] ?? 0 ) ?: '—'; ?></td>
						<td data-label="پیام">
							<div><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></div>
							<?php if ( ! empty( $row['context'] ) ) : ?>
								<details style="margin-top:4px;">
									<summary style="cursor:pointer;color:var(--gi-text-faint);font-weight:700;font-size:var(--gi-fs0);min-height:40px;display:inline-flex;align-items:center;">جزئیات</summary>
									<pre class="gi-pre"><?php
										echo esc_html( is_string( $row['context'] ) ? $row['context'] : wp_json_encode( $row['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
									?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php if ( $pages > 1 ) : ?>
		<div class="gi-flex" style="margin-top:var(--gi-s4);justify-content:center;">
			<?php if ( $page > 1 ) : ?>
				<a class="gi-btn gi-btn--subtle" style="text-decoration:none;display:inline-flex;" href="<?php echo esc_url( add_query_arg( 'gs_page', $page - 1, $base ) ); ?>">→ قبلی</a>
			<?php endif; ?>
			<span class="gi-card-sub">صفحه <span class="gi-nums"><?php echo (int) $page; ?></span> از <span class="gi-nums"><?php echo (int) $pages; ?></span></span>
			<?php if ( $page < $pages ) : ?>
				<a class="gi-btn gi-btn--subtle" style="text-decoration:none;display:inline-flex;" href="<?php echo esc_url( add_query_arg( 'gs_page', $page + 1, $base ) ); ?>">بعدی ←</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php endif; ?>
</div>
