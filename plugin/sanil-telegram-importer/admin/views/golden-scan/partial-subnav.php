<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ۱۰.۱۱-UX — Console chrome (topbar + tabs + mobile bottom nav).
 *
 * BACKEND FREEZE: همه‌ی مقادیر gs_view، deep-linkها و capability check
 * دست‌نخورده‌اند؛ فقط presentation عوض شده است.
 * وضعیت خط فقط **خوانده** می‌شود (get_option) — هیچ تغییری انجام نمی‌شود.
 */
$gs_all_views = array( 'profiles', 'sessions', 'system-check', 'test-wizard', 'logs', 'worker', 'insight', 'automation', 'review', 'environment', 'automation-settings', 'publish-queue' );
$gs_current   = ( isset( $_GET['gs_view'] ) && in_array( $_GET['gs_view'], $gs_all_views, true ) ) ? $_GET['gs_view'] : 'channels';

$gs_groups = array(
	'channels' => array(
		'label' => '📡 منابع',
		'icon'  => '📡',
		'views' => array(
			'channels' => 'کانال‌ها',
			'insight'  => 'تحلیل محتوا',
			'profiles' => 'پروفایل‌ها',
		),
	),
	'automation' => array(
		'label' => '🏭 خط تولید',
		'icon'  => '🏭',
		'views' => array(
			'publish-queue' => '📦 صف انتشار',
			'automation'    => 'Live Pipeline',
			'sessions'      => 'Session ها',
			'worker'        => 'Queue / پردازش',
			'review'        => 'Review',
		),
	),
	'automation-settings' => array(
		'label' => '⚙️ اتوماسیون',
		'icon'  => '⚙️',
		'views' => array(
			'automation-settings' => 'تنظیمات + Recovery',
		),
	),
	'system-check' => array(
		'label' => '🩺 سلامت سیستم',
		'icon'  => '🩺',
		'views' => array(
			'system-check' => 'System Health',
			'environment'  => 'Environment',
			'test-wizard'  => 'Tests',
		),
	),
	'logs' => array(
		'label' => '📝 گزارش‌ها',
		'icon'  => '📝',
		'views' => array(
			'logs' => 'گزارش‌ها',
		),
	),
);

/* گروه فعال + وضعیت خط (read-only) */
$gs_active_group = null;
foreach ( $gs_groups as $gs_gkey => $gs_g ) {
	if ( array_key_exists( $gs_current, $gs_g['views'] ) ) {
		$gs_active_group = $gs_gkey;
		break;
	}
}
$gs_line = ( class_exists( 'STI_GS_Line' ) ) ? STI_GS_Line::state() : 'STOPPED';
$gs_line_meta = array(
	'RUNNING'  => array( 'running',  'در حال اجرا' ),
	'STOPPED'  => array( 'stopped',  'توقف کرده' ),
	'PAUSING'  => array( 'pausing',  'در حال توقف امن…' ),
	'DEGRADED' => array( 'degraded', 'کاهش‌یافته' ),
	'ERROR'    => array( 'error',    'خطا' ),
);
$gs_lm = isset( $gs_line_meta[ $gs_line ] ) ? $gs_line_meta[ $gs_line ] : $gs_line_meta['STOPPED'];

$gs_url = function ( $view ) {
	return ( 'channels' === $view )
		? admin_url( 'admin.php?page=sti-golden-scan' )
		: admin_url( 'admin.php?page=sti-golden-scan&gs_view=' . $view );
};
?>
<div class="gi-topbar">
	<div class="gi-brand">
		<div class="gi-brand-mark" aria-hidden="true">G</div>
		<div>
			<div class="gi-brand-name">گلدن اسکن</div>
			<div class="gi-brand-sub">GOLDEN IMPORTER v<?php echo esc_html( STI_VERSION ); ?></div>
		</div>
	</div>
	<span class="gi-line-chip" role="status" aria-live="polite" title="وضعیت خط تولید (read-only)">
		<span class="gi-dot gi-dot--<?php echo esc_attr( $gs_lm[0] ); ?> <?php echo 'running' === $gs_lm[0] ? 'gi-pulse' : ''; ?>" aria-hidden="true"></span>
		<?php echo esc_html( $gs_lm[1] ); ?>
	</span>
	<button type="button" class="gi-theme-btn" id="gi-theme-btn" aria-label="تغییر تم روشن/تیره" title="تم روشن/تیره">🌓</button>
	<nav class="gi-tabs gi-tabs--groups" aria-label="گروه‌های کنسول">
		<?php foreach ( $gs_groups as $gs_gkey => $gs_g ) :
			$gs_gdefault = array_key_first( $gs_g['views'] );
		?>
			<a href="<?php echo esc_url( $gs_url( $gs_gdefault ) ); ?>"
			   class="gi-tab gi-tab--group"
			   <?php echo $gs_gkey === $gs_active_group ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $gs_g['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
	<nav class="gi-tabs gi-tabs--views" aria-label="صفحات گروه">
		<?php foreach ( $gs_groups as $gs_gkey => $gs_g ) :
			if ( $gs_gkey !== $gs_active_group ) {
				continue;
			}
			foreach ( $gs_g['views'] as $gs_v => $gs_vlabel ) :
		?>
			<a href="<?php echo esc_url( $gs_url( $gs_v ) ); ?>"
			   class="gi-tab"
			   <?php echo $gs_current === $gs_v ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $gs_vlabel ); ?>
			</a>
		<?php
			endforeach;
		endforeach;
		?>
	</nav>
</div>

<?php /* Mobile bottom navigation — ۵ گروه، glass + floating */ ?>
<nav class="gi-bnav" aria-label="ناوبری موبایل">
	<?php foreach ( $gs_groups as $gs_gkey => $gs_g ) :
		$gs_gdefault = array_key_first( $gs_g['views'] );
		$gs_is_group_active = ( $gs_gkey === $gs_active_group );
	?>
		<a href="<?php echo esc_url( $gs_url( $gs_is_group_active ? $gs_current : $gs_gdefault ) ); ?>"
		   <?php echo $gs_is_group_active ? 'aria-current="page"' : ''; ?>>
			<span class="gi-bnav-ico" aria-hidden="true"><?php echo esc_html( $gs_g['icon'] ); ?></span>
			<?php echo esc_html( $gs_gkey === 'automation' ? 'خط تولید' : ( $gs_gkey === 'channels' ? 'منابع' : ( $gs_gkey === 'automation-settings' ? 'اتوماسیون' : ( $gs_gkey === 'system-check' ? 'سلامت' : 'گزارش‌ها' ) ) ) ); ?>
		</a>
	<?php endforeach; ?>
</nav>
