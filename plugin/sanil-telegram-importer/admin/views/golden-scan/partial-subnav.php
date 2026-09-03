<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$gs_current = ( isset( $_GET['gs_view'] ) && in_array( $_GET['gs_view'], array( 'profiles', 'sessions', 'system-check', 'test-wizard', 'logs', 'worker', 'insight', 'automation', 'review', 'environment', 'automation-settings' ), true ) ) ? $_GET['gs_view'] : 'channels';
?>
<?php /* flex-wrap: بدون آن تب‌ها در یک ردیف از کادر بیرون می‌زنند. */ ?>
<div class="sti-subnav" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;">
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan' ) ); ?>"
	   class="sti-btn-sm <?php echo 'channels' === $gs_current ? '' : 'secondary'; ?>">📡 کانال‌ها</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=profiles' ) ); ?>"
	   class="sti-btn-sm <?php echo 'profiles' === $gs_current ? '' : 'secondary'; ?>">🧩 پروفایل‌ها</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=sessions' ) ); ?>"
	   class="sti-btn-sm <?php echo 'sessions' === $gs_current ? '' : 'secondary'; ?>">🗂 Session ها</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=worker' ) ); ?>"
	   class="sti-btn-sm <?php echo 'worker' === $gs_current ? '' : 'secondary'; ?>">🤖 پردازش خودکار</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=automation' ) ); ?>"
	   class="sti-btn-sm <?php echo 'automation' === $gs_current ? '' : 'secondary'; ?>">🏭 خط تولید</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=review' ) ); ?>"
	   class="sti-btn-sm <?php echo 'review' === $gs_current ? '' : 'secondary'; ?>">🔎 Review Queue</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=automation-settings' ) ); ?>"
	   class="sti-btn-sm <?php echo 'automation-settings' === $gs_current ? '' : 'secondary'; ?>">⚙️ تنظیمات اتوماسیون</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=insight' ) ); ?>"
	   class="sti-btn-sm <?php echo 'insight' === $gs_current ? '' : 'secondary'; ?>">🔍 شناخت کانال</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=system-check' ) ); ?>"
	   class="sti-btn-sm <?php echo 'system-check' === $gs_current ? '' : 'secondary'; ?>">🩺 بررسی سیستم</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=environment' ) ); ?>"
	   class="sti-btn-sm <?php echo 'environment' === $gs_current ? '' : 'secondary'; ?>">🖥 Environment</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=test-wizard' ) ); ?>"
	   class="sti-btn-sm <?php echo 'test-wizard' === $gs_current ? '' : 'secondary'; ?>">🧪 تست خودکار</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=logs' ) ); ?>"
	   class="sti-btn-sm <?php echo 'logs' === $gs_current ? '' : 'secondary'; ?>">📝 گزارش‌ها</a>
</div>
