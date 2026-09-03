<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ۱۰.۱۱ — ادغام IA: ۵ گروه به‌جای ۱۲ تب پراکنده.
 *
 * همه‌ی مقادیر gs_view دست‌نخورده‌اند → deep-link، AJAX و capability
 * checks موجود هیچ‌کدام تغییر نکرده‌اند؛ فقط چیدمان دو‌سطحی شد:
 *   ردیف اول: گروه | ردیف دوم: دیدهای همان گروه.
 */
$gs_all_views = array( 'profiles', 'sessions', 'system-check', 'test-wizard', 'logs', 'worker', 'insight', 'automation', 'review', 'environment', 'automation-settings' );
$gs_current   = ( isset( $_GET['gs_view'] ) && in_array( $_GET['gs_view'], $gs_all_views, true ) ) ? $_GET['gs_view'] : 'channels';

$gs_groups = array(
	'channels' => array(
		'label' => '📡 منابع',
		'views' => array(
			'channels' => 'کانال‌ها',
			'insight'  => 'شناخت کانال',
			'profiles' => 'پروفایل‌ها',
		),
	),
	'automation' => array(
		'label' => '🏭 خط تولید',
		'views' => array(
			'automation' => 'Live Pipeline',
			'sessions'   => 'Session ها',
			'worker'     => 'Queue / پردازش',
			'review'     => 'Review',
		),
	),
	'automation-settings' => array(
		'label' => '⚙️ اتوماسیون',
		'views' => array(
			'automation-settings' => 'تنظیمات + Recovery/Retry',
		),
	),
	'system-check' => array(
		'label' => '🩺 سلامت سیستم',
		'views' => array(
			'system-check' => 'System Health',
			'environment'  => 'Environment',
			'test-wizard'  => 'Tests',
		),
	),
	'logs' => array(
		'label' => '📝 گزارش‌ها',
		'views' => array(
			'logs' => 'گزارش‌ها',
		),
	),
);

/* گروه فعال: گروهی که دید فعلی توش هست. */
$gs_active_group = null;
foreach ( $gs_groups as $gs_gkey => $gs_g ) {
	if ( array_key_exists( $gs_current, $gs_g['views'] ) ) {
		$gs_active_group = $gs_gkey;
		break;
	}
}
?>
<?php /* flex-wrap: بدون آن تب‌ها در یک ردیف از کادر بیرون می‌زنند. */ ?>
<div class="sti-subnav" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
	<?php foreach ( $gs_groups as $gs_gkey => $gs_g ) :
		$gs_gdefault = array_key_first( $gs_g['views'] );
		$gs_gurl     = ( 'channels' === $gs_gdefault )
			? admin_url( 'admin.php?page=sti-golden-scan' )
			: admin_url( 'admin.php?page=sti-golden-scan&gs_view=' . $gs_gdefault );
		$gs_gactive  = ( $gs_gkey === $gs_active_group );
	?>
		<a href="<?php echo esc_url( $gs_gurl ); ?>"
		   class="sti-btn-sm <?php echo $gs_gactive ? '' : 'secondary'; ?>"
		   style="<?php echo $gs_gactive ? 'font-weight:800;' : ''; ?>">
			<?php echo esc_html( $gs_g['label'] ); ?>
		</a>
	<?php endforeach; ?>
</div>
<div class="sti-subnav" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;">
	<?php foreach ( $gs_groups as $gs_gkey => $gs_g ) :
		if ( $gs_gkey !== $gs_active_group ) {
			continue;
		}
		foreach ( $gs_g['views'] as $gs_v => $gs_vlabel ) :
	?>
		<a href="<?php echo esc_url( ( 'channels' === $gs_v ) ? admin_url( 'admin.php?page=sti-golden-scan' ) : admin_url( 'admin.php?page=sti-golden-scan&gs_view=' . $gs_v ) ); ?>"
		   class="sti-btn-sm <?php echo $gs_current === $gs_v ? '' : 'secondary'; ?>">
			<?php echo esc_html( $gs_vlabel ); ?>
		</a>
	<?php
		endforeach;
	endforeach;
	?>
</div>
