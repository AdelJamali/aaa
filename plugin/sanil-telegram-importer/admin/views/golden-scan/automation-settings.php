<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$cfg = STI_GS_Automation::all();
$spec = STI_GS_Automation::defaults();

$groups = array(
	'session_retry_limit'  => 'سقف‌های Retry',
	'ipc_recovery_limit'   => 'سقف‌های Retry',
	'download_retry_limit' => 'سقف‌های Retry',
	'publish_retry_limit'  => 'سقف‌های Retry',
	'ai_retry_limit'       => 'سقف‌های Retry',
	'max_active_sessions'  => 'بودجه‌های منابع (هاست اشتراکی)',
	'sessions_per_tick'    => 'بودجه‌های منابع (هاست اشتراکی)',
	'max_downloads_per_tick' => 'بودجه‌های منابع (هاست اشتراکی)',
	'max_products_per_tick'  => 'بودجه‌های منابع (هاست اشتراکی)',
	'gov_ram_pct'        => 'آستانه‌های Governor',
	'gov_load_per_core'  => 'آستانه‌های Governor',
	'gov_backlog'        => 'آستانه‌های Governor',
	'gov_ipc_faults'     => 'آستانه‌های Governor',
	'worker_interval'      => 'Worker / Backoff (۱۰.۱۱)',
	'backoff_base_minutes' => 'Worker / Backoff (۱۰.۱۱)',
	'poll_interval'        => 'مانیتور زنده (۱۰.۱۱)',
);
$grouped = array();
foreach ( $groups as $key => $g ) {
	$grouped[ $g ][] = $key;
}
?>
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — Automation Settings (تنظیمات خط تولید)</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<p style="max-width:760px;color:#555;">
		پیش‌فرض‌ها برای <strong>هاست اشتراکی</strong> تنظیم شده‌اند: پایداری مهم‌تر از سرعت.
		اگر هاست قوی دارید می‌توانید بودجه‌ها را بالا ببرید — Governor در هر حال وقتی
		فشار می‌آید، خودش خفه می‌کند. هیچ‌کدام از این‌ها نیاز به ویرایش فایل ندارند.
	</p>

	<form id="gs-automation-form" style="max-width:760px;">
		<?php foreach ( $grouped as $group => $keys ) : ?>
			<h2 style="margin-top:20px;"><?php echo esc_html( $group ); ?></h2>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( $keys as $key ) :
					$val  = $cfg[ $key ];
					$min  = $spec[ $key ][3];
					$max  = $spec[ $key ][4];
					$def  = $spec[ $key ][0];
				?>
					<tr>
						<td style="width:45%;">
							<label for="gs-af-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $spec[ $key ][1] ); ?></strong></label>
							<div style="font-size:11px;color:#888;">حداقل <?php echo $min; ?> / حداکثر <?php echo $max; ?> / پیش‌فرض <?php echo $def; ?></div>
						</td>
						<td style="width:25%;">
							<input type="number" id="gs-af-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
								value="<?php echo esc_attr( $val ); ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>"
								step="<?php echo 'float' === $spec[ $key ][2] ? '0.1' : '1'; ?>"
								style="width:110px;" class="regular-text">
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>

		<p style="margin-top:16px;">
			<button type="submit" class="button button-primary" id="gs-automation-save">💾 ذخیره تنظیمات</button>
			<span id="gs-automation-msg" style="margin-right:10px;"></span>
		</p>
	</form>

	<script>
	(function () {
		if (typeof jQuery === 'undefined') { return; }
		jQuery(document).on('submit', '#gs-automation-form', function (e) {
			e.preventDefault();
			var data = { action: 'sti_gs_automation_save', nonce: '<?php echo wp_create_nonce( 'sti_admin_nonce' ); ?>' };
			jQuery(this).find('input[name]').each(function () {
				data[ jQuery(this).attr('name') ] = jQuery(this).val();
			});
			var msg = jQuery('#gs-automation-msg');
			msg.text('در حال ذخیره…');
			jQuery.post(ajaxurl, data, function (res) {
				if (res && res.success) {
					msg.text('✅ ذخیره شد').css('color', '#1e7e34');
				} else {
					msg.text('❌ خطا در ذخیره').css('color', '#c62828');
				}
			}).fail(function () {
				msg.text('❌ خطای شبکه').css('color', '#c62828');
			});
		});
	})();
	</script>
</div>
