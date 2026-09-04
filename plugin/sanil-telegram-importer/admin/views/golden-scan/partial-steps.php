<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ۱۰.۱۲ — Workflow Stepper: چهار سؤال UX.
 *
 *   الان کجا هستم؟    → مرحله‌ی فعال هایلایت (aria-current)
 *   مرحله‌ی بعد چیست؟ → CTA پایین استپر
 *   چه چیزی ساخته می‌شود؟ / چند باقی مانده؟ → $gs_steps_note (داده‌ی واقعی)
 *
 * متغیرهای ورودی (اختیاری):
 *   $gs_steps_active  int   ۱..
 *   $gs_steps_next    array( 'url' => ..., 'label' => ... )
 *   $gs_steps_note    string
 *
 * فقط presentation است — هیچ داده‌ای نمی‌سازد و AJAX ندارد.
 */
$gs_st_steps  = isset( $gs_steps ) && is_array( $gs_steps ) ? $gs_steps : array( 'کانال', 'اسکن', 'تحلیل', 'دسته‌ها', 'صف', 'انتشار', 'خط' );
$gs_st_active = (int) ( isset( $gs_steps_active ) ? $gs_steps_active : 0 );
$gs_st_next   = isset( $gs_steps_next ) && is_array( $gs_steps_next ) ? $gs_steps_next : array( 'url' => '', 'label' => '' );
$gs_st_note   = isset( $gs_steps_note ) ? (string) $gs_steps_note : '';
?>
<nav class="gi-steps" aria-label="Workflow">
	<ol class="gi-steps-list">
		<?php foreach ( $gs_st_steps as $gs_st_i => $gs_st_l ) :
			$gs_st_n   = $gs_st_i + 1;
			$gs_st_cls = $gs_st_n < $gs_st_active ? 'is-done' : ( $gs_st_n === $gs_st_active ? 'is-active' : 'is-todo' );
			?>
			<li class="gi-step <?php echo esc_attr( $gs_st_cls ); ?>"<?php echo $gs_st_n === $gs_st_active ? ' aria-current="step"' : ''; ?>>
				<span class="gi-step-n" aria-hidden="true"><?php echo $gs_st_n < $gs_st_active ? '✓' : $gs_st_n; ?></span>
				<span class="gi-step-l"><?php echo esc_html( $gs_st_l ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php if ( ! empty( $gs_st_next['url'] ) && ! empty( $gs_st_next['label'] ) ) : ?>
		<p class="gi-steps-next">مرحله‌ی بعد: <a href="<?php echo esc_url( $gs_st_next['url'] ); ?>"><?php echo esc_html( $gs_st_next['label'] ); ?></a><?php echo '' !== $gs_st_note ? ' — ' . esc_html( $gs_st_note ) : ''; ?></p>
	<?php elseif ( '' !== $gs_st_note ) : ?>
		<p class="gi-steps-next"><?php echo esc_html( $gs_st_note ); ?></p>
	<?php endif; ?>
</nav>
