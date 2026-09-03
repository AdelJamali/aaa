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
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">⚙️ تنظیمات خط تولید</h1>
		<p class="gi-h1-sub">پیش‌فرض‌ها برای <strong>هاست اشتراکی</strong> تنظیم شده‌اند: پایداری مهم‌تر از سرعت. اگر هاست قوی دارید می‌توانید بودجه‌ها را بالا ببرید — Governor در هر حال وقتی فشار می‌آید، خودش خفه می‌کند. هیچ‌کدام از این‌ها نیاز به ویرایش فایل ندارند.</p>
	</div>

	<form id="gs-automation-form" class="gi-form">
		<?php foreach ( $grouped as $group => $keys ) : ?>
			<div class="gi-card gi-card--flush" style="margin-bottom:var(--gi-s4);">
				<div class="gi-card-head" style="padding:var(--gi-s4) var(--gi-s5) var(--gi-s3);">
					<h2 class="gi-card-title"><?php echo esc_html( $group ); ?></h2>
				</div>
				<div class="gi-table-wrap" style="border:none;border-radius:0;">
					<table class="gi-table gi-responsive">
						<tbody>
							<?php foreach ( $keys as $key ) :
								$val  = $cfg[ $key ];
								$min  = $spec[ $key ][3];
								$max  = $spec[ $key ][4];
								$def  = $spec[ $key ][0];
							?>
								<tr>
									<td data-label="تنظیم">
										<label for="gs-af-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $spec[ $key ][1] ); ?></strong></label>
										<div class="gi-card-sub">حداقل <span class="gi-nums"><?php echo $min; ?></span> / حداکثر <span class="gi-nums"><?php echo $max; ?></span> / پیش‌فرض <span class="gi-nums"><?php echo $def; ?></span></div>
									</td>
									<td data-label="مقدار" style="text-align:end;min-width:130px;">
										<input type="number" id="gs-af-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
											value="<?php echo esc_attr( $val ); ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>"
											step="<?php echo 'float' === $spec[ $key ][2] ? '0.1' : '1'; ?>"
											style="width:130px;" class="regular-text">
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endforeach; ?>

		<div class="gi-flex" style="align-items:center;gap:var(--gi-s3);">
			<button type="submit" class="gi-btn gi-btn--primary" id="gs-automation-save">💾 ذخیره تنظیمات</button>
			<span id="gs-automation-msg" class="gi-inline-res" role="status" aria-live="polite"></span>
		</div>
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
					msg.text('✅ ذخیره شد').addClass('ok');
				} else {
					msg.text('❌ خطا در ذخیره').addClass('err');
				}
			}).fail(function () {
				msg.text('❌ خطای شبکه').addClass('err');
			});
		});
	})();
	</script>
</div>
