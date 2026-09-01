<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

$cats = STI_Category::get_all();
$woo_terms = STI_Category::get_woo_terms();
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">
	<div class="sti-header">
		<h1><span class="dashicons dashicons-category"></span> دسته‌بندی‌ها</h1>
		<button type="button" id="sti-add-category" class="sti-btn">➕ افزودن دسته‌بندی</button>
	</div>

	<div class="sti-panel">
		<div class="sti-panel-head">
			<div>
				<h2>📁 مدیریت دسته‌بندی‌ها</h2>
				<p>هر دسته‌بندی به یک دسته‌بندی واقعی ووکامرس متصل می‌شود؛ این افزونه دسته‌بندی جدید در ووکامرس نمی‌سازد، فقط به دسته‌های موجود وصل می‌شود. این لیست همان منویی است که در تلگرام هم نمایش داده می‌شود (انتخاب هم از اینجا هم از تلگرام).</p>
			</div>
		</div>
		<p class="desc">⚠️ اگر یک دسته‌بندی «قالب توضیحات محصول» اختصاصی خودش را داشته باشد (ستون آخر جدول زیر، دکمه‌ی ویرایش)، همیشه به‌جای قالب سراسری (صفحه‌ی محتوا و قالب‌ها) استفاده می‌شود. اگر بعد از عوض‌کردن قالب سراسری هنوز نتیجه فرق نکرد، احتمالاً همین است.</p>
		<button type="button" id="sti-clear-templates" class="sti-btn secondary">🧹 پاک کردن قالب اختصاصی همه‌ی دسته‌ها (استفاده از قالب سراسری)</button>
		<div id="sti-clear-templates-result" class="sti-inline-result"></div>

		<div class="sti-table-wrap" style="margin-top:16px;">
		<table class="sti-table">
			<thead>
				<tr>
					<th>ترتیب</th><th>عنوان در تلگرام</th><th>دسته ووکامرس</th><th>قیمت</th><th>تاخیر انتشار</th><th>وضعیت</th><th>عملیات</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $cats ) ) : ?>
				<tr><td colspan="7">هنوز دسته‌بندی‌ای ثبت نشده.</td></tr>
			<?php else : foreach ( $cats as $cat ) :
				$term_name = '—';
				foreach ( $woo_terms as $t ) { if ( (int) $t->term_id === (int) $cat->woo_term_id ) { $term_name = $t->name; } }
				?>
				<tr>
					<td><?php echo (int) $cat->sort_order; ?></td>
					<td><strong><?php echo esc_html( $cat->telegram_label ); ?></strong></td>
					<td><?php echo esc_html( $term_name ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $cat->price ) ); ?> تومان</td>
					<td><?php echo $cat->publish_delay_minutes ? esc_html( $cat->publish_delay_minutes ) . ' دقیقه' : 'پیش‌فرض سراسری'; ?></td>
					<td><span class="sti-badge <?php echo $cat->is_active ? 'published' : 'cancelled'; ?>"><?php echo $cat->is_active ? 'فعال' : 'غیرفعال'; ?></span></td>
					<td>
						<button type="button" class="sti-btn secondary sti-edit-category"
							data-id="<?php echo (int) $cat->id; ?>"
							data-telegram_label="<?php echo esc_attr( $cat->telegram_label ); ?>"
							data-folder_key="<?php echo esc_attr( $cat->folder_key ); ?>"
							data-search_terms="<?php echo esc_attr( $cat->search_terms ?? '' ); ?>"
							data-woo_term_id="<?php echo (int) $cat->woo_term_id; ?>"
							data-price="<?php echo esc_attr( $cat->price ); ?>"
							data-publish_delay_minutes="<?php echo esc_attr( $cat->publish_delay_minutes ); ?>"
							data-description_template="<?php echo esc_attr( $cat->description_template ); ?>"
							data-storage_mode_override="<?php echo esc_attr( $cat->storage_mode_override ); ?>"
							data-sort_order="<?php echo esc_attr( $cat->sort_order ); ?>"
							data-is_active="<?php echo (int) $cat->is_active; ?>"
						>ویرایش</button>
						<button type="button" class="sti-btn danger sti-delete-category" data-id="<?php echo (int) $cat->id; ?>">حذف</button>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		</div>
	</div>
		</div>
	</div>
</div>

<div class="sti-modal-bg" id="sti-category-modal-bg">
	<div class="sti-modal">
		<h3 id="sti-category-modal-title">افزودن دسته‌بندی</h3>
		<form id="sti-category-form">
			<input type="hidden" id="cat_id">
			<div class="sti-field">
				<label>عنوان در تلگرام</label>
				<input type="text" id="cat_label" required placeholder="مثلا PSD">
			</div>
			<div class="sti-field">
				<label>پوشه ذخیره فایل (فقط حروف/عدد انگلیسی)</label>
				<input type="text" id="cat_folder_key" dir="ltr" placeholder="خالی = ساخته می‌شود از عنوان بالا">
				<div class="hint">این مقدار برای نام‌گذاری مسیر فایل روی هاست/FTP و لینک نهایی دانلود استفاده می‌شود — باید همیشه انگلیسی بماند. اگر عنوان تلگرام فارسی یا شامل ایموجی باشد ولی این فیلد خالی بماند، به‌طور خودکار یک مقدار انگلیسی امن ساخته می‌شود (هرگز از متن فارسی مستقیم در مسیر فایل استفاده نمی‌شود؛ همین موضوع باعث خطای ۴۰۴ در لینک نهایی دانلود می‌شد). بعد از ساخته‌شدن یک دسته‌بندی، این مقدار را عوض نکن مگر بخواهی فایل‌های بعدی در مسیر جدید ذخیره شوند.</div>
			</div>
			<div class="sti-field">
				<label>عبارت‌های جست‌وجوی کانال (اختیاری)</label>
				<textarea id="cat_search_terms" dir="ltr" placeholder="هر خط یک عبارت: mockup&#10;mock up&#10;موکاپ"></textarea>
				<div class="hint">برای جست‌وجوی سریع در Channel Import استفاده می‌شود؛ هر خط یا ویرگول یک عبارت است. این فهرست فقط کاندیدا پیدا می‌کند و قبل از فشار دکمه، AutoCat و Duplicate Check همچنان اجرا می‌شوند.</div>
			</div>
			<div class="sti-field">
				<label>دسته‌بندی متصل در ووکامرس</label>
				<select id="cat_woo_term">
					<option value="">— انتخاب کن —</option>
					<?php foreach ( $woo_terms as $t ) : ?>
						<option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="sti-row">
				<div class="sti-field"><label>قیمت پیش‌فرض (تومان)</label><input type="number" id="cat_price" min="0"></div>
				<div class="sti-field"><label>تاخیر انتشار (دقیقه، خالی = پیش‌فرض سراسری)</label><input type="number" id="cat_delay" min="0"></div>
			</div>
			<div class="sti-field">
				<label>قالب توضیحات محصول</label>
				<textarea id="cat_template" placeholder="دانلود %type% با عنوان «%name%»&#10;&#10;%excerpt%&#10;&#10;🛠 قابل استفاده در: %software%&#10;📦 حجم فایل: %filesize%&#10;🔖 کد فایل: %code%"></textarea>
				<div class="hint">جای‌گزین‌ها: %name% %latin_name% %type% %code% %excerpt% %software% %filesize% %dimensions% %resolution% %color% %jalali_date% — هر کدام که مقدارش برای یک فایل خالی باشد، کل آن خط از توضیحات حذف می‌شود. اگر خالی بماند از قالب پیش‌فرض سراسری استفاده می‌شود.</div>
			</div>
			<div class="sti-row">
				<div class="sti-field">
					<label>محل ذخیره فایل (اختیاری - override)</label>
					<select id="cat_storage_override">
						<option value="">پیش‌فرض سراسری</option>
						<option value="local">فقط هاست داخلی</option>
						<option value="remote">فقط هاست خارجی</option>
					</select>
				</div>
				<div class="sti-field"><label>ترتیب نمایش</label><input type="number" id="cat_sort" value="0"></div>
			</div>
			<div class="sti-field">
				<label class="sti-toggle"><input type="checkbox" id="cat_active" checked> فعال (در منوی تلگرام نمایش داده شود)</label>
			</div>
			<div class="sti-modal-actions">
				<button type="button" id="sti-category-cancel" class="sti-btn secondary">انصراف</button>
				<button type="submit" id="sti-category-save" class="sti-btn">ذخیره</button>
			</div>
		</form>
	</div>
</div>
