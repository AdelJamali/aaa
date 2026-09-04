<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ۱۰.۱۲ — 📦 صف انتشار: مرکز مدیریت انتشار.
 *
 * سه بخش: ۱) انتخاب دسته‌ها  ۲) افزودن محصولات به صف  ۳) برنامه‌ی انتشار.
 * زبان UI: «محصول برای انتشار» — Session فقط مفهوم داخلی Backend است.
 *
 * داده‌ها (read-only، render-time):
 *   - شمارش هر دسته: GROUP BY profile.default_category_id (term_id ووکامرس)
 *   - قیمت هر دسته:  sti_categories.price از طریق woo_term_id
 *   - وضعیت صف:      STI_GS_Publish_Queue::stats()
 */

global $wpdb;

$items_t    = STI_GS_DB::profile_items_table();
$profiles_t = STI_GS_DB::profiles_table();
$pipeline_t = STI_GS_DB::pipeline_items_table();

$cat_counts = (array) $wpdb->get_results(
	"SELECT p.default_category_id AS wc_term_id, COUNT(*) AS cnt
	   FROM {$items_t} pi
	   INNER JOIN {$profiles_t} p ON p.id = pi.profile_id
	  WHERE pi.status = 'available'
	    AND p.default_category_id IS NOT NULL
	    AND p.default_category_id > 0
	  GROUP BY p.default_category_id
	  ORDER BY cnt DESC",
	ARRAY_A
);

/* قیمت + برچسب از جدول دسته‌های افزونه (woo_term_id → price) */
$cat_prices = array();
if ( class_exists( 'STI_Category' ) && method_exists( 'STI_Category', 'get_all' ) ) {
	foreach ( (array) STI_Category::get_all() as $c ) {
		$wid = (int) ( $c->woo_term_id ?? 0 );
		if ( $wid > 0 ) {
			$cat_prices[ $wid ] = (string) ( $c->price ?? '' );
		}
	}
}

$cat_rows  = array();
$total_all = 0;
foreach ( $cat_counts as $cc ) {
	$wid  = (int) $cc['wc_term_id'];
	$term = get_term( $wid, 'product_cat' );
	$cat_rows[] = array(
		'wc_term_id' => $wid,
		'name'       => ( $term instanceof WP_Term ) ? $term->name : ( 'دسته #' . $wid ),
		'available'  => (int) $cc['cnt'],
		'price'      => isset( $cat_prices[ $wid ] ) ? $cat_prices[ $wid ] : '',
	);
	$total_all += (int) $cc['cnt'];
}

$selected_cats = get_option( 'sti_gs_publish_categories', array() );
$selected_cats = is_array( $selected_cats ) ? array_map( 'intval', $selected_cats ) : array();

$q_stats      = class_exists( 'STI_GS_Publish_Queue' ) ? STI_GS_Publish_Queue::stats() : array();
$next_sched = (array) $wpdb->get_results(
	"SELECT id, state, category_id, file_name, scheduled_at
	   FROM {$pipeline_t}
	  WHERE scheduled_at IS NOT NULL
	    AND state NOT IN ('PUBLISHED', 'REVIEW', 'CANCELLED')
	  ORDER BY scheduled_at ASC, id ASC
	  LIMIT 10",
	ARRAY_A
);
?>
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">📦 صف انتشار</h1>
		<p class="gi-h1-sub">مرکز مدیریت انتشار: انتخاب دسته‌ها، افزودن محصولات به صف، و برنامه‌ی انتشار. هر «محصول» یک فایل آماده برای تبدیل به محصول ووکامرس است.</p>
	</div>

	<?php
	$gs_steps_active = 4;
	$gs_steps_next   = array(
		'url'   => admin_url( 'admin.php?page=sti-golden-scan&gs_view=automation' ),
		'label' => 'پیگیری در خط تولید',
	);
	$gs_steps_note = number_format_i18n( (int) $total_all ) . ' محصول آماده در ' . count( $cat_rows ) . ' دسته';
	include STI_PATH . 'admin/views/golden-scan/partial-steps.php';
	?>

	<?php if ( ! $cat_rows ) : ?>
	<div class="gi-card gi-span-12">
		<div class="gi-empty" style="text-align:center;padding:var(--gi-s6) var(--gi-s4);">
			<div style="font-size:40px;">📭</div>
			<h3 style="margin:var(--gi-s3) 0 var(--gi-s2);">هنوز محصول آماده‌ای برای انتشار نیست</h3>
			<p class="gi-card-sub" style="max-width:520px;margin:0 auto var(--gi-s4);">اول یک کانال اضافه کنید و اسکن را اجرا کنید؛ بعد از تحلیل محتوا، همین‌جا دسته‌ها را انتخاب می‌کنید.</p>
			<a class="gi-btn gi-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan' ) ); ?>">📡 برو به کانال‌ها</a>
		</div>
	</div>
	<?php else : ?>

	<div class="gi-bento">

		<!-- ۱) انتخاب دسته‌ها -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<h2 class="gi-card-title">۱) انتخاب دسته‌ها</h2>
				<span class="gi-card-sub">موجود = محصولات آماده در هر دسته · قیمت = قیمت پیش‌فرض دسته (از صفحه‌ی دسته‌بندی‌ها)</span>
			</div>
			<div class="gi-table-wrap">
				<table class="gi-table gi-responsive">
					<thead>
						<tr>
							<th scope="col" style="width:44px;"></th>
							<th scope="col">دسته</th>
							<th scope="col" style="width:110px;">موجود</th>
							<th scope="col" style="width:120px;">قیمت</th>
							<th scope="col" style="width:130px;">تعداد انتشار</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $cat_rows as $cr ) :
						$checked = in_array( (int) $cr['wc_term_id'], $selected_cats, true );
						$def_cnt = min( 50, (int) $cr['available'] );
						?>
						<tr>
							<td data-label="انتخاب"><input type="checkbox" class="gs-pq-cat" data-cat="<?php echo (int) $cr['wc_term_id']; ?>" <?php echo $checked ? 'checked' : ''; ?> style="width:20px;height:20px;"></td>
							<td data-label="دسته"><strong><?php echo esc_html( $cr['name'] ); ?></strong></td>
							<td data-label="موجود" class="gi-nums" dir="ltr"><?php echo number_format_i18n( $cr['available'] ); ?></td>
							<td data-label="قیمت" class="gi-nums" dir="ltr"><?php echo '' !== $cr['price'] ? number_format_i18n( (float) $cr['price'] ) : '—'; ?></td>
							<td data-label="تعداد"><input type="number" class="gs-pq-count" data-cat="<?php echo (int) $cr['wc_term_id']; ?>" data-max="<?php echo (int) $cr['available']; ?>" min="1" max="<?php echo (int) $cr['available']; ?>" value="<?php echo (int) $def_cnt; ?>" style="width:90px;"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="gi-flex" style="align-items:center;gap:var(--gi-s3);flex-wrap:wrap;margin-top:var(--gi-s3);">
				<button id="gs-pq-save" class="gi-btn">💾 ذخیره‌ی انتخاب</button>
				<span id="gs-pq-save-result" class="gi-inline-res" role="status" aria-live="polite"></span>
			</div>
		</div>

		<!-- ۲) افزودن محصولات به صف -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<h2 class="gi-card-title">۲) افزودن محصولات به صف</h2>
				<span class="gi-card-sub">انتخاب از هر دسته «اولویت‌دارترین» موارد است (امتیاز بالاتر = اولویت بالاتر)</span>
			</div>

			<div id="gs-pq-preview" class="gi-preview" style="border:1px solid var(--gi-border);border-radius:14px;padding:var(--gi-s4);background:var(--gi-surface-sunken);"></div>

			<div class="gi-form-row" style="flex-wrap:wrap;gap:var(--gi-s4);">
				<label class="gi-field">
					<span class="gi-field-label">حالت انتشار</span>
					<span class="gi-flex" style="gap:var(--gi-s4);align-items:center;">
						<label><input type="radio" name="gs-pq-mode" value="immediate" checked> فوری</label>
						<label><input type="radio" name="gs-pq-mode" value="interval"> زمان‌بندی‌شده</label>
					</span>
				</label>
				<label class="gi-field" id="gs-pq-interval-wrap">
					<span class="gi-field-label">فاصله (دقیقه)</span>
					<input type="number" id="gs-pq-interval" value="30" min="1" max="1440" style="width:110px;">
				</label>
				<label class="gi-field" id="gs-pq-start-wrap">
					<span class="gi-field-label">شروع از (اختیاری)</span>
					<input type="datetime-local" id="gs-pq-start">
				</label>
			</div>

			<div class="gi-flex" style="align-items:center;gap:var(--gi-s3);flex-wrap:wrap;margin-top:var(--gi-s3);">
				<button id="gs-pq-add" class="gi-btn gi-btn--primary">📦 افزودن به صف انتشار</button>
				<button id="gs-pq-dryrun" class="gi-btn" title="فقط‌خواندنی — مسیر واقعی را ردیف‌به‌ردیف بازمی‌سازد (همان کوئری، همان دروازه‌ها) ولی هیچ چیزی نمی‌سازد">🔍 پیش‌نمایش دقیق (بدون ساخت)</button>
				<span id="gs-pq-add-result" class="gi-inline-res" role="status" aria-live="polite"></span>
			</div>

			<div id="gs-pq-dryrun-panel" hidden style="margin-top:var(--gi-s4);"></div>
		</div>

		<!-- ۳) برنامه‌ی انتشار (فقط‌خواندنی) -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<h2 class="gi-card-title">۳) برنامه‌ی انتشار</h2>
				<span class="gi-card-sub">فقط‌خواندنی — از وضعیت واقعی صف</span>
			</div>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:var(--gi-s4);">
				<div class="gi-stat gi-stat--info"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( (int) ( $q_stats['queued'] ?? 0 ) ); ?></div><div class="gi-stat-l">در صف</div></div>
				<div class="gi-stat"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( (int) ( $q_stats['published'] ?? 0 ) ); ?></div><div class="gi-stat-l">منتشر شده</div></div>
				<div class="gi-stat gi-stat--<?php echo (int) ( $q_stats['failed'] ?? 0 ) ? 'warning' : 'success'; ?>"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( (int) ( $q_stats['failed'] ?? 0 ) ); ?></div><div class="gi-stat-l">خطا</div></div>
				<div class="gi-stat"><div class="gi-stat-v gi-nums" dir="ltr"><?php echo ! empty( $q_stats['next_at'] ) ? esc_html( date( 'H:i', strtotime( $q_stats['next_at'] ) ) ) : '—'; ?></div><div class="gi-stat-l">نوبت بعدی</div></div>
				<div class="gi-stat"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( (int) ( $q_stats['interval_min'] ?? 0 ) ); ?></div><div class="gi-stat-l">فاصله (دقیقه)</div></div>
			</div>

			<?php if ( $next_sched ) : ?>
			<p class="gi-card-sub" style="margin-top:var(--gi-s4);">۱۰ مورد بعدی زمان‌بندی‌شده:</p>
			<div class="gi-table-wrap">
				<table class="gi-table gi-responsive">
					<thead>
						<tr><th scope="col" style="width:110px;">زمان</th><th scope="col">فایل</th><th scope="col" style="width:120px;">وضعیت</th></tr>
					</thead>
					<tbody>
					<?php foreach ( $next_sched as $ns ) : ?>
						<tr>
							<td class="gi-nums" dir="ltr"><?php echo esc_html( (string) $ns['scheduled_at'] ); ?></td>
							<td><?php echo esc_html( mb_substr( (string) ( $ns['file_name'] ?? '' ), 0, 40 ) ?: '—' ); ?></td>
							<td><code dir="ltr" style="font-size:var(--gi-fs0);"><?php echo esc_html( (string) ( $ns['state'] ?? '' ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php else : ?>
			<p class="gi-card-sub" style="margin-top:var(--gi-s4);">هنوز مورد زمان‌بندی‌شده‌ای نیست — با «افزودن به صف» در حالت زمان‌بندی‌شده، برنامه ساخته می‌شود.</p>
			<?php endif; ?>

			<div class="gi-flex" style="margin-top:var(--gi-s4);">
				<a class="gi-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=worker' ) ); ?>">⚙️ جزئیات پردازش — Queue/پردازش</a>
			</div>
		</div>

	</div>
	<?php endif; ?>
</div>

<script>
(function () {
	function boot() {
		jQuery(function ($) {
			'use strict';
			var A = window.STI || {};

			function esc(s) {
				return $('<div>').text(s == null ? '' : String(s)).html();
			}
			function post(action, data) {
				data = data || {};
				data.action = action;
				data.nonce = A.nonce;
				return $.post(A.ajaxUrl, data);
			}
			function num(n) {
				n = Number(n) || 0;
				return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
			}
			function pad(n) { return (n < 10 ? '0' : '') + n; }

			if (!$('#gs-pq-preview').length) { return; }

			var $mode = $('input[name="gs-pq-mode"]');
			var $interval = $('#gs-pq-interval');
			var $start = $('#gs-pq-start');

			function selected() {
				var out = [];
				$('.gs-pq-cat:checked').each(function () {
					var cat = $(this).data('cat');
					var $c = $('.gs-pq-count[data-cat="' + cat + '"]');
					var max = Number($c.data('max')) || 0;
					var cnt = Math.max(1, Math.min(max, parseInt($c.val(), 10) || 1));
					out.push({ cat: Number(cat), count: cnt, max: max });
				});
				return out;
			}

			function rebuildPreview() {
				var items = selected();
				var isInt = $mode.filter(':checked').val() === 'interval';
				var intMin = Math.max(1, parseInt($interval.val(), 10) || 30);
				var h = '';
				if (!items.length) {
					h = '<p class="gi-card-sub" style="margin:0;">هیچ دسته‌ای انتخاب نشده — در جدول بالایی حداقل یک دسته را تیک بزنید.</p>';
				} else {
					var total = 0;
					var rows = '';
					items.forEach(function (it) {
						total += it.count;
						var name = $('.gs-pq-cat[data-cat="' + it.cat + '"]').closest('tr').find('td').eq(1).text();
						var price = $('.gs-pq-cat[data-cat="' + it.cat + '"]').closest('tr').find('td').eq(3).text();
						rows += '<div style="display:flex;justify-content:space-between;gap:var(--gi-s3);flex-wrap:wrap;padding:6px 0;border-bottom:1px dashed var(--gi-border);">' +
							'<span>' + esc(name) + ': <b>' + num(it.count) + '</b> از ' + num(it.max) + ' موجود</span>' +
							'<span class="gi-nums" dir="ltr">' + esc(price) + '</span></div>';
					});
					h = '<p class="gi-card-sub" style="margin:0 0 var(--gi-s2);"><b>' + num(total) + '</b> محصول از <b>' + items.length + '</b> دسته انتخاب خواهد شد</p>' + rows;
					if (isInt) {
						var startStr = $start.val() || '';
						var t = startStr ? new Date(startStr) : new Date(Date.now() + 60000);
						if (isNaN(t.getTime())) { t = new Date(Date.now() + 60000); }
						var seq = [];
						for (var i = 0; i < 10 && i < total; i++) {
							var tt = new Date(t.getTime() + i * intMin * 60000);
							seq.push(pad(tt.getHours()) + ':' + pad(tt.getMinutes()));
						}
						h += '<p class="gi-card-sub" style="margin:var(--gi-s2) 0 0;">برنامه: هر <b>' + num(intMin) + '</b> دقیقه — ' +
							'<span class="gi-nums" dir="ltr">' + esc(seq.join(' · ')) + '</span>' +
							(total > 10 ? ' · … (تا ' + num(total) + ' ردیف)' : '') + '</p>';
					} else {
						h += '<p class="gi-card-sub" style="margin:var(--gi-s2) 0 0;">برنامه: فوری — به‌همان‌زودی با فاصله‌ی حداقلی منتشر می‌شوند.</p>';
					}
				}
				$('#gs-pq-preview').html(h);
			}

			function syncMode() {
				var isInt = $mode.filter(':checked').val() === 'interval';
				$('#gs-pq-interval-wrap, #gs-pq-start-wrap').toggle(isInt);
				rebuildPreview();
			}

			$mode.on('change', syncMode);
			$interval.on('input', rebuildPreview);
			$start.on('input', rebuildPreview);
			$('.gs-pq-cat, .gs-pq-count').on('change input', rebuildPreview);
			syncMode();

			/* ذخیره‌ی انتخاب (B4) */
			$('#gs-pq-save').on('click', function () {
				var $btn = $(this), $r = $('#gs-pq-save-result');
				var cats = [];
				$('.gs-pq-cat:checked').each(function () { cats.push($(this).data('cat')); });
				if (!cats.length) { $r.text('اول حداقل یک دسته انتخاب کنید.').addClass('err'); return; }
				$btn.prop('disabled', true);
				$r.text('در حال ذخیره...');
				post('sti_gs_publish_queue_save_selection', { categories: cats }).done(function (res) {
					if (res && res.success) {
						$r.text('✅ انتخاب ذخیره شد (' + cats.length + ' دسته).').addClass('ok');
					} else {
						$r.text('❌ ' + ((res.data && res.data.message) || 'خطا')).addClass('err');
					}
				}).fail(function () {
					$r.text('❌ خطای ارتباط').addClass('err');
				}).always(function () {
					$btn.prop('disabled', false);
				});
			});

			/* افزودن به صف (B3) */
			$('#gs-pq-add').on('click', function () {
				var $btn = $(this), $r = $('#gs-pq-add-result');
				var items = selected();
				if (!items.length) { $r.text('اول حداقل یک دسته انتخاب کنید.').addClass('err'); return; }
				var total = items.reduce(function (s, it) { return s + it.count; }, 0);
				if (total > 1000) { $r.text('مجموع هر بار حداکثر ۱۰۰۰ محصول.').addClass('err'); return; }
				var mode = $mode.filter(':checked').val();
				var payload = {
					items: items.map(function (it) { return { wc_term_id: it.cat, count: it.count }; }),
					mode: mode,
					interval_minutes: Math.max(1, parseInt($interval.val(), 10) || 30),
					start_at: mode === 'interval' ? ($start.val() || '') : ''
				};
				$btn.prop('disabled', true);
				$r.text('در حال ساخت ' + total + ' محصول و افزودن به صف...');
				post('sti_gs_publish_queue_create', payload).done(function (res) {
					if (res && res.success) {
						var d = res.data;
						var msg = '✅ ' + d.created_total + ' محصول به صف انتشار اضافه شد' +
							((d.mode === 'interval' && d.schedule_preview && d.schedule_preview.length)
								? ' — اولین انتشار: ' + esc(d.schedule_preview[0].scheduled_at)
								: '') + '.';
						$r.html(msg + ' <a href="' + window.location.href + '">به‌روزرسانی صفحه</a>').addClass('ok');
						setTimeout(function () { window.location.reload(); }, 2500);
					} else {
						$r.text('❌ ' + ((res.data && res.data.message) || 'خطا')).addClass('err');
					}
			}).fail(function () {
				$r.text('❌ خطای ارتباط').addClass('err');
			}).always(function () {
				$btn.prop('disabled', false);
			});
			});

			/* ۱۰.۱۲-RC — تشخیص خشک (فقط‌خواندنی): یک کلیک واقعی دقیقاً چه خواهد کرد */
			function dryrunVerdictText(v, t, il) {
				if (v === 'P2_ORPHAN_MESSAGES') {
					return '⛔ حکم: P2_ORPHAN_MESSAGES — ریشه: پیام‌های یتیم؛ یک کلیک واقعی همه‌ی ' + t.selected + ' ردیف انتخاب‌شده را با sti_gs_no_item رد می‌کند (صفر Session)';
				}
				if (v === 'P3_INSERT_LAYER') {
					return '⛔ حکم: P3_INSERT_LAYER — ریشه: لایه‌ی درج؛ همه‌ی ' + t.selected + ' ردیف با sti_gs_session_insert_failed رد می‌شوند' +
						((il.missing_columns && il.missing_columns.length) ? ' (ستون‌های گمشده: ' + il.missing_columns.join(', ') + ')' : ' (جدول مقصد ناموجود/غیرمعمول)');
				}
				if (v === 'P1_SELECTION_ERROR') {
					return '⛔ حکم: P1_SELECTION_ERROR — خود کوئری انتخاب خطا می‌دهد (متن خطا در جدول)؛ یک کلیک واقعی ۰ ردیف انتخاب می‌کند و بی‌صدا صفر می‌شود';
				}
				if (v === 'P1_SELECTION_EMPTY') {
					return '⛔ حکم: P1_SELECTION_EMPTY — کوئری انتخاب ۰ ردیف برمی‌گرداند (کوئری سالم)؛ مغایرت بین موجودی نمایشی و انتخاب';
				}
				if (v === 'MIXED') {
					var parts = [];
					if (t.sti_gs_no_item) { parts.push(t.sti_gs_no_item + ' ردیف یتیم (sti_gs_no_item)'); }
					if (t.sti_gs_session_insert_failed) { parts.push(t.sti_gs_session_insert_failed + ' ردیف (sti_gs_session_insert_failed)'); }
					if (t.would_create) { parts.push(t.would_create + ' ردیف ساخته‌شده (would_create)'); }
					if (t.existing_session) { parts.push(t.existing_session + ' Session قبلی (به‌حساب ساخته‌شده)'); }
					return '⚠ حکم: MIXED — ' + parts.join(' + ');
				}
				return '✅ حکم: SHOULD_CREATE — لایه‌ی درج سالم؛ با همین ورودی کلیک واقعی ' + t.created_predicted + ' مورد می‌سازد (اگر کلیک واقعی ۰ داد، ورودی یا زمان اجرا متفاوت بوده است)';
			}
			$('#gs-pq-dryrun').on('click', function () {
				var $btn = $(this);
				var $panel = $('#gs-pq-dryrun-panel');
				var items = selected();
				if (!items.length) {
					$('#gs-pq-add-result').text('اول حداقل یک دسته را در جدول بالایی تیک بزنید.').addClass('err');
					return;
				}
				var total = items.reduce(function (s, it) { return s + it.count; }, 0);
				if (total > 1000) {
					$('#gs-pq-add-result').text('مجموع هر بار حداکثر ۱۰۰۰ محصول.').addClass('err');
					return;
				}
				$btn.prop('disabled', true);
				$panel.html('<p class="gi-card-sub">در حال بازنمایی مسیر واقعی (فقط‌خواندنی؛ هیچ چیزی ساخته نمی‌شود)…</p>').prop('hidden', false);
				post('sti_gs_publish_queue_dryrun', { items: items.map(function (it) { return { wc_term_id: it.cat, count: it.count }; }) })
					.done(function (res) {
						if (!res || !res.success || !res.data) {
							$panel.html('<p class="gi-card-sub">❌ خطا در تشخیص: ' + esc((res && res.data && res.data.message) || 'نامشخص') + '</p>');
							return;
						}
						var d = res.data, t = d.totals || {}, il = d.insert_layer || {};
						var h = '<p class="gi-card-sub" style="font-weight:700;">' + esc(dryrunVerdictText(d.verdict, t, il)) + '</p>';
						h += '<div class="gi-table-wrap"><table class="gi-table gi-responsive"><thead><tr>' +
							'<th>دسته (term_id)</th><th>درخواستی</th><th>موجودی UI</th><th>انتخاب‌شده</th>' +
							'<th>رد: یتیم<br><small dir="ltr">no_item</small></th>' +
							'<th>Session قبلی<br><small dir="ltr">existing</small></th>' +
							'<th>ساخته می‌شود<br><small dir="ltr">would_create</small></th>' +
							'<th>رد: درج<br><small dir="ltr">insert_failed</small></th>' +
							'<th>پیش‌بینی ساخته‌شده</th>' +
							'</tr></thead><tbody>';
						(d.categories || []).forEach(function (c) {
							var o = c.outcomes || {};
							h += '<tr>' +
								'<td dir="ltr">' + esc(c.wc_term_id) + '</td>' +
								'<td>' + c.requested + '</td>' +
								'<td>' + c.ui_available + '</td>' +
								'<td>' + c.selected + '</td>' +
								'<td>' + (o.sti_gs_no_item || 0) + '</td>' +
								'<td>' + (o.existing_session || 0) + '</td>' +
								'<td>' + (o.would_create || 0) + '</td>' +
								'<td>' + (o.sti_gs_session_insert_failed || 0) + '</td>' +
								'<td>' + c.created_predicted + '</td>' +
								'</tr>';
							if (c.selection_error) {
								h += '<tr><td colspan="9" dir="ltr" style="color:var(--gi-danger);"><code>' + esc(c.selection_error) + '</code></td></tr>';
							}
							if (c.no_item_sample && c.no_item_sample.length) {
								h += '<tr><td colspan="9" dir="ltr" style="font-size:var(--gi-fs0);">نمونه‌ی profile_item‌های یتیم: ' + esc(c.no_item_sample.join(', ')) + '</td></tr>';
							}
							if (c.items && c.items.length) {
								var pairs = c.items.map(function (it) { return it.id + '=' + it.outcome; }).join(', ');
								h += '<tr><td colspan="9" dir="ltr"><details style="font-size:var(--gi-fs0);"><summary style="cursor:pointer;">برچسب هر ' + c.items.length + ' profile_item انتخاب‌شده</summary>' +
									'<code style="display:block;white-space:pre-wrap;word-break:break-all;">' + esc(pairs) + '</code></details></td></tr>';
							}
						});
						h += '</tbody></table></div>';
						h += '<p class="gi-card-sub" style="margin-top:var(--gi-s3);">لایه‌ی درج: جدول <code dir="ltr">' + esc(il.physical_table || '—') + '</code>' +
							' · ستون‌های گمشده: ' + ((il.missing_columns && il.missing_columns.length) ? esc(il.missing_columns.join(', ')) : 'هیچ') +
							' · UNIQUE message_pk: ' + (il.unique_message_pk ? '✔' : '✘') +
							' · جدول فیزیکی: ' + (il.table_exists === false ? '✘' : '✔') + '</p>';
						$panel.html(h);
					})
					.fail(function () {
						$panel.html('<p class="gi-card-sub">❌ خطای ارتباط</p>');
					})
					.always(function () {
						$btn.prop('disabled', false);
					});
			});
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
</script>
