<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$level = isset( $_GET['gs_level'] ) ? sanitize_key( $_GET['gs_level'] ) : '';
$page  = max( 1, (int) ( $_GET['gs_page'] ?? 1 ) );

$filters = array();
if ( $level ) { $filters['level'] = $level; }
// STI_Logger::get_filtered() کلید 'rows' برمی‌گرداند، نه 'items'.
// نسخه‌ی قبلی 'items' می‌خواند، به (array) $result سقوط می‌کرد و در نتیجه
// چهار عنصرِ خودِ آرایه (rows/total/pages/page) را به‌عنوان چهار ردیف خالی
// چاپ می‌کرد — همان چیزی که در اسکرین‌شات دیده شد.
$result = STI_Logger::get_filtered( $filters, $page, 30 );
$rows   = isset( $result['rows'] ) ? (array) $result['rows'] : array();
$total  = isset( $result['total'] ) ? (int) $result['total'] : count( $rows );
$pages  = isset( $result['pages'] ) ? (int) $result['pages'] : 1;

$badge = array(
	'error'   => array( '🔴', '#ffebee' ),
	'warning' => array( '🟡', '#fff8e1' ),
	'success' => array( '🟢', '#e8f5e9' ),
	'info'    => array( '⚪', '#f5f5f5' ),
);
$base = admin_url( 'admin.php?page=sti-golden-scan&gs_view=logs' );
?>
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — گزارش‌ها</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<p>اینجا همان چیزی است که در حالت عادی باید داخل <code>debug.log</code> دنبالش می‌گشتید.</p>

	<p style="display:flex;gap:8px;flex-wrap:wrap;">
		<?php foreach ( array( '' => 'همه', 'error' => '🔴 خطا', 'warning' => '🟡 هشدار', 'success' => '🟢 موفق', 'info' => '⚪ اطلاع' ) as $key => $label ) : ?>
			<a class="button <?php echo $level === $key ? 'button-primary' : ''; ?>"
			   href="<?php echo esc_url( $key ? add_query_arg( 'gs_level', $key, $base ) : $base ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</p>

	<?php if ( empty( $rows ) ) : ?>
		<div class="notice notice-info"><p>هیچ گزارشی ثبت نشده.</p></div>
	<?php else : ?>
	<table class="widefat striped">
		<thead><tr>
			<th style="width:150px;">زمان</th>
			<th style="width:90px;">سطح</th>
			<th style="width:110px;">Session</th>
			<th>پیام</th>
		</tr></thead>
		<tbody>
		<?php foreach ( $rows as $row ) :
			$row = (array) $row;
			$lv  = (string) ( $row['level'] ?? 'info' );
			$b   = $badge[ $lv ] ?? $badge['info'];
			?>
			<tr style="background:<?php echo esc_attr( $b[1] ); ?>;">
				<td><?php echo esc_html( $row['created_at'] ?? '' ); ?></td>
				<td><?php echo $b[0] . ' ' . esc_html( $lv ); ?></td>
				<td><?php echo (int) ( $row['session_id'] ?? 0 ) ?: '—'; ?></td>
				<td>
					<div><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></div>
					<?php if ( ! empty( $row['context'] ) ) : ?>
						<details style="margin-top:4px;">
							<summary style="cursor:pointer;color:#666;">جزئیات</summary>
							<pre style="white-space:pre-wrap;font-size:11px;margin:6px 0 0;"><?php
								echo esc_html( is_string( $row['context'] ) ? $row['context'] : wp_json_encode( $row['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
							?></pre>
						</details>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<p style="margin-top:12px;display:flex;gap:8px;">
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'gs_page', $page - 1, $base ) ); ?>">قبلی</a>
			<?php endif; ?>
			<span style="align-self:center;">صفحه <?php echo (int) $page; ?> از <?php echo (int) $pages; ?></span>
			<?php if ( $page < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'gs_page', $page + 1, $base ) ); ?>">بعدی</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
	<?php endif; ?>
</div>
