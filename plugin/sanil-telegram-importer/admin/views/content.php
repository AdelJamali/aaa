<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

$s = STI_Settings::all();
settings_errors( 'sti' );
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">
			<div class="sti-info-box sti-content-contract" style="margin-bottom:16px;">
				<strong>قرارداد تولید محتوا:</strong> این صفحه فقط قالب توضیحات، متن مرجع، ویژگی‌های ووکامرس و سیاست تکراری را کنترل می‌کند.
				انتخاب API، چرخش سرویس، پراکسی و پرامپت‌ها فقط در صفحه «هوش مصنوعی» انجام می‌شود؛ عنوان‌ها فقط از «استودیوی عنوان» می‌آیند.
			</div>
			<div class="sti-info-box" style="margin-bottom:16px;">
				<strong>مدیریت هوش مصنوعی منتقل شد.</strong>
				همه‌ی کلیدهای API، پرامپت‌ها، پراکسی و تست اتصال از این نسخه در صفحه‌ی
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-ai' ) ); ?>"><strong>هوش مصنوعی</strong></a>
				جمع شده‌اند و کلیدهای قبلی خودکار منتقل شده‌اند. این صفحه فقط برای قالب‌های متنی است.
			</div>
	<div class="sti-header">
		<h1><span class="dashicons dashicons-edit-page"></span> محتوا و قالب‌ها</h1>
	</div>

	<p class="desc">تنظیم نحوه ساخت عنوان و توضیحات محصولات — از قالب رایگان تا هوش مصنوعی. این صفحه مثل استودیوی عنوان به‌صورت کامل و اسکرول‌پذیر نمایش داده می‌شود.</p>

	<div class="sti-panel">
		<div class="sti-panel-head">
			<div>
				<h2>🤖 نقش هوش مصنوعی</h2>
				<p>با API سازگار با OpenAI می‌توانید عنوان و توضیح طبیعی‌تر بسازید.</p>
			</div>
		</div>
		<p class="desc">
			در حالت پیش‌فرض (رایگان، بدون AI)، افزونه فقط: نوع فایل را به یک برچسب فارسی طبیعی تبدیل می‌کند (مثلاً VECTOR → «وکتور»)،
			نرم‌افزارهای قابل استفاده را از یک جدول ثابت بر اساس نوع فایل اضافه می‌کند (مثلاً PSD → «Adobe Photoshop»)،
			و یک خلاصه‌ی کوتاه از صفحه‌ی مرجع (همان لینک هایپرلینک‌شده روی نام فایل در کپشن تلگرام) استخراج می‌کند — همه‌ی این‌ها بدون هیچ API و رایگان.
			<br><br>
			وقتی «هوش مصنوعی» را فعال و یک کلید API معتبر وارد کنی، افزونه به‌جای این روش ثابت، متن کپشن و خلاصه‌ی صفحه‌ی مرجع را برای مدل هوش مصنوعی می‌فرستد و از آن می‌خواهد: عنوان فایل را به فارسی روان ترجمه/بازنویسی کند، و یک توضیح کامل‌تر و سئو-پسند درباره‌ی کاربرد، فرمت و مزایای فایل بنویسد. اگر تماس با AI به هر دلیلی ناموفق شود (کلید اشتباه، قطعی شبکه)، افزونه خودکار به همان حالت رایگان برمی‌گردد — هیچ‌وقت محصول بدون توضیح نمی‌ماند.
		</p>
	</div>

	<div class="sti-panel">
		<h2>⚠️ نکته‌ی مهم درباره‌ی سرویس‌های هوش مصنوعی آمریکایی</h2>
		<p class="desc">
			طبق قوانین صادراتی آمریکا، شرکت‌های آمریکایی از جمله Google (Gemini)، OpenAI و Anthropic دسترسی به API خود را از ایران مسدود کرده‌اند — این محدودیت در سطح حساب/کلید است، نه صرفاً فیلترینگ شبکه، پس با پراکسی هم معمولاً حل نمی‌شود. اگر کلید Gemini/OpenAI را وارد کنی و کار نکرد، به همین دلیل است.
			افزونه در این حالت خودکار به روش رایگان (ترجمه‌ی ساده + قالب) برمی‌گردد، بدون خطا.
		</p>
		<p class="desc">
			اگر می‌خواهی از یک AI واقعی استفاده کنی، باید دنبال سرویس‌هایی بگردی که مقر آن‌ها خارج از آمریکا و مشمول این محدودیت نیست (مثلاً برخی سرویس‌های چینی مثل DeepSeek که رابط سازگار با OpenAI هم دارند) و آدرس/کلید آن‌ها را در فیلدهای پایین وارد کنی — افزونه با هر سرویس سازگار با فرمت OpenAI Chat Completions کار می‌کند.
		</p>
	</div>

	<form method="post">
		<?php wp_nonce_field( 'sti_save_settings', 'sti_nonce' ); ?>
		<input type="hidden" name="sti_form" value="content">

		<div class="sti-panel">
			<h2>روش تولید عنوان و توضیحات محصول</h2>

			<div class="sti-field">
				<label>قالب پیش‌فرض توضیحات (سراسری، وقتی حالت رایگان فعال است)</label>
				<textarea name="default_template"><?php echo esc_textarea( $s['default_template'] ); ?></textarea>
				<div class="hint">
					جای‌گزین‌های قابل استفاده:
					<code>%name%</code> نام فایل (ترجمه‌شده) ·
					<code>%latin_name%</code> نام اصلی/لاتین ·
					<code>%type%</code> نوع فایل به فارسی (مثلاً «وکتور») ·
					<code>%code%</code> کد فایل ·
					<code>%excerpt%</code> خلاصه از صفحه‌ی مرجع ·
					<code>%software%</code> نرم‌افزارهای قابل استفاده ·
					<code>%filesize%</code> حجم فایل ·
					<code>%dimensions%</code> ابعاد ·
					<code>%resolution%</code> رزولوشن ·
					<code>%color%</code> رنگ ·
					<code>%jalali_date%</code> تاریخ شمسی امروز
					<br>اگر مقدار یکی از این‌ها برای یک فایل خاص وجود نداشته باشد (مثلاً ابعاد در کپشن نیامده)، کل آن خط خودکار از توضیحات حذف می‌شود.
					<br>هر دسته‌بندی می‌تواند قالب اختصاصی خودش را هم داشته باشد (صفحه دسته‌بندی‌ها).
				</div>
			</div>

			<div class="sti-field">
				<label class="sti-toggle"><input type="checkbox" name="auto_scrape_excerpt" <?php checked( $s['auto_scrape_excerpt'] ); ?>> واکشی خودکار توضیحات از سایت مرجع (%excerpt%)</label>
				<div class="hint">اگر خاموش کنی، ساخت محصول سریع‌تر می‌شود (چون منتظر جواب یک سایت خارجی نمی‌ماند) ولی جای‌گزین %excerpt% خالی می‌ماند. برای ثبت سریع/انبوه پیشنهاد می‌شود خاموش باشد.</div>
			</div>

			<div class="sti-field">
				<label class="sti-toggle"><input type="checkbox" name="auto_fill_attributes" <?php checked( $s['auto_fill_attributes'] ); ?>> پر کردن خودکار «ویژگی‌ها» (Attributes) ووکامرس برای هر محصول</label>
				<div class="hint">فرمت، نرم‌افزار، حجم فایل، ابعاد، رزولوشن، رنگ، تاریخ و شناسه — به‌صورت Attribute واقعی ووکامرس ثبت می‌شوند (نه فقط متن در توضیحات)، تا اگر قالب سایتت جعبه‌ی «ویژگی‌ها»/مشخصات دارد، این‌ها آنجا هم نمایش داده شوند. اگر تاکسونومی سراسری «فرمت» (pa_format) در سایتت وجود داشته باشد، همان استفاده می‌شود؛ در غیر این صورت به‌صورت ویژگی اختصاصی همان محصول ثبت می‌شود.</div>
			</div>

			<div class="sti-field">
				<label>محصول تکراری (کد فایل تکراری) چه اتفاقی بیفتد؟</label>
				<select name="duplicate_policy">
					<option value="skip" <?php selected( $s['duplicate_policy'], 'skip' ); ?>>ساخته نشود + پیام خطا در تلگرام</option>
					<option value="update" <?php selected( $s['duplicate_policy'], 'update' ); ?>>محصول موجود بروزرسانی شود (عنوان/توضیحات/فایل/تصویر)</option>
					<option value="duplicate" <?php selected( $s['duplicate_policy'], 'duplicate' ); ?>>همیشه محصول جدید ساخته شود (بدون بررسی)</option>
				</select>
				<div class="hint">تشخیص تکراری بر اساس «کد فایل» (File Code) انجام می‌شود.</div>
			</div>

			<button type="submit" class="sti-btn">💾 ذخیره تنظیمات</button>
		</div>
	</form>
		</div>
	</div>
</div>

