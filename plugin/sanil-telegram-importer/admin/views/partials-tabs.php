<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'sti-dashboard';
$tabs = array(
	'sti-dashboard' => array( 'label' => 'داشبورد', 'icon' => 'dashicons-chart-bar' ),
	'sti-telegram'  => array( 'label' => 'تنظیمات تلگرام', 'icon' => 'dashicons-admin-network' ),
	'sti-categories'=> array( 'label' => 'دسته‌بندی‌ها', 'icon' => 'dashicons-category' ),
	'sti-storage'   => array( 'label' => 'ذخیره‌سازی فایل', 'icon' => 'dashicons-database' ),
	'sti-ai'        => array( 'label' => 'هوش مصنوعی', 'icon' => 'dashicons-superhero-alt' ),
	'sti-content'   => array( 'label' => 'محتوا و قالب‌ها', 'icon' => 'dashicons-edit-page' ),
	'sti-title-tools' => array( 'label' => 'استودیوی عنوان', 'icon' => 'dashicons-editor-spellcheck' ),
	'sti-queue'     => array( 'label' => 'صف انتشار', 'icon' => 'dashicons-clock' ),
	'sti-sessions'  => array( 'label' => 'Session ها و گزارش', 'icon' => 'dashicons-list-view' ),
	'sti-logs'      => array( 'label' => 'گزارش‌ها', 'icon' => 'dashicons-clipboard' ),
	'sti-channel-import' => array( 'label' => 'واردات از کانال', 'icon' => 'dashicons-download' ),
	'sti-importek' => array( 'label' => 'ایمپورتک', 'icon' => 'dashicons-upload' ),
	'sti-goldtel' => array( 'label' => 'گلدتل', 'icon' => 'dashicons-admin-site-alt3' ),
	'sti-golden-scan' => array( 'label' => 'گلدن اسکن', 'icon' => 'dashicons-search' ),
	'sti-autocat' => array( 'label' => 'اتوکت', 'icon' => 'dashicons-superhero' ),
);
?>
<button type="button" class="sti-sidebar-toggle" id="sti-sidebar-toggle" aria-label="نمایش فهرست بخش‌ها">
	<span class="dashicons dashicons-menu-alt"></span>
	<span>فهرست بخش‌ها</span>
</button>
<nav class="sti-sidebar" id="sti-sidebar" aria-label="منوی اصلی افزونه">
	<div class="sti-sidebar-brand">
		<img src="<?php echo esc_url( STI_URL . 'admin/assets/golden-importer-logo.svg?ver=' . STI_VERSION ); ?>" alt="" width="28" height="28" style="border-radius:8px;">
		<div>
			<strong>گلدن ایمپورتر</strong>
			<small>GOLDEN IMPORTER v<?php echo esc_html( STI_VERSION ); ?></small>
		</div>
	</div>
	<ul class="sti-nav">
		<?php foreach ( $tabs as $slug => $tab ) : ?>
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="<?php echo $current === $slug ? 'active' : ''; ?>">
				<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
				<span><?php echo esc_html( $tab['label'] ); ?></span>
			</a>
		</li>
		<?php endforeach; ?>
	</ul>
	<div class="sti-sidebar-footer">
		<small>برای سرعت بیشتر، افزونه را غیرفعال/فعال کنید تا OPcache پاک شود.</small>
	</div>
</nav>
<div class="sti-sidebar-backdrop" id="sti-sidebar-backdrop" aria-hidden="true"></div>
