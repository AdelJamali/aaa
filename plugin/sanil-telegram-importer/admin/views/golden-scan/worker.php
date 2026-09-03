<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$stats = STI_GS_Auto_Worker::stats();
$queue = class_exists( 'STI_GS_Publish_Queue' ) ? STI_GS_Publish_Queue::stats() : null;
$today = $stats['today'] ?? array();
?>
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">⚙️ Queue / پردازش خودکار</h1>
		<p class="gi-h1-sub">وقتی روشن باشد، Sessionها بدون هیچ کلیکی خودشان جلو می‌روند. هر <span class="gi-nums"><?php echo (int) round( $stats['interval'] / 60 ); ?></span> دقیقه، <span class="gi-nums"><?php echo (int) $stats['batch']; ?></span> مورد، هرکدام یک مرحله.</p>
	</div>

	<div class="gi-bento">

		<!-- Worker control hero -->
		<div class="gi-card gi-hero gi-span-5">
			<div class="gi-hero-state">
				<span class="gi-dot gi-dot--<?php echo $stats['enabled'] ? 'running' : 'stopped'; ?> <?php echo $stats['enabled'] ? 'gi-pulse' : ''; ?>" aria-hidden="true"></span>
				<div>
					<div class="gi-hero-state-label" style="font-size:var(--gi-fs3);"><?php echo $stats['enabled'] ? 'Worker روشن' : 'Worker خاموش'; ?></div>
					<div class="gi-hero-state-sub">هر <span class="gi-nums"><?php echo (int) round( $stats['interval'] / 60 ); ?></span> دقیقه · <span class="gi-nums"><?php echo (int) $stats['batch']; ?></span> Session در هر دور</div>
				</div>
			</div>
			<div class="gi-hero-actions">
				<button class="gi-btn <?php echo $stats['enabled'] ? 'gi-btn--danger' : 'gi-btn--success'; ?>" id="gs-w-toggle" data-on="<?php echo $stats['enabled'] ? '1' : '0'; ?>">
					<?php echo $stats['enabled'] ? '⏸ خاموش کردن' : '▶ روشن کردن'; ?>
				</button>
				<button class="gi-btn gi-btn--subtle" id="gs-w-run">اجرای فوری یک دور</button>
				<button class="gi-btn gi-btn--ghost" id="gs-w-reset">آزادسازی موارد گیرکرده</button>
				<button class="gi-btn gi-btn--ghost" id="gs-w-refresh">⟳ به‌روزرسانی</button>
			</div>
		</div>

		<!-- KPIs -->
		<div class="gi-card gi-span-7">
			<div class="gi-card-head">
				<h2 class="gi-card-title">وضعیت صف</h2>
				<span class="gi-card-sub">به‌روزرسانی زنده هر ۲۰ ثانیه</span>
			</div>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--gi-s4);">
				<div class="gi-stat gi-stat--brand"><div class="gi-stat-v gi-nums" id="gs-w-pending"><?php echo (int) $stats['pending']; ?></div><div class="gi-stat-l">در انتظار پردازش</div></div>
				<div class="gi-stat gi-stat--danger"><div class="gi-stat-v gi-nums" id="gs-w-stuck"><?php echo (int) ( $stats['stuck'] + ( $stats['review'] ?? 0 ) ); ?></div><div class="gi-stat-l">نیازمند بازبینی (NEEDS_REVIEW / فایل یافت‌نشده)</div></div>
			</div>
		</div>

		<!-- Today report — ساختار ۵ ردیفی برای JS (tbody tr td:last-child) حفظ شد -->
		<div class="gi-card gi-span-4">
			<div class="gi-card-head"><h2 class="gi-card-title">📊 گزارش امروز</h2></div>
			<table class="gi-table" id="gs-w-today">
				<tbody>
					<tr><td>مرحله جلو رفت</td><td class="gi-nums" style="text-align:end;font-weight:800;"><?php echo (int) ( $today['advanced'] ?? 0 ); ?></td></tr>
					<tr><td>منتظر پاسخ ربات</td><td class="gi-nums" style="text-align:end;font-weight:800;"><?php echo (int) ( $today['waiting'] ?? 0 ); ?></td></tr>
					<tr><td>کامل شد</td><td class="gi-nums" style="text-align:end;font-weight:800;"><?php echo (int) ( $today['completed'] ?? 0 ); ?></td></tr>
					<tr><td>خطا خورد</td><td class="gi-nums" style="text-align:end;font-weight:800;color:var(--gi-danger);"><?php echo (int) ( $today['failed'] ?? 0 ); ?></td></tr>
					<tr><td>تعداد دورها</td><td class="gi-nums" style="text-align:end;font-weight:800;"><?php echo (int) ( $today['ticks'] ?? 0 ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<!-- Chain mode -->
		<div class="gi-card gi-span-8">
			<div class="gi-card-head">
				<h2 class="gi-card-title">🔗 معماری زنجیره (Chain Mode) — v10.8</h2>
			</div>
			<p class="gi-card-sub" style="font-size:var(--gi-fs1);margin-bottom:var(--gi-s4);">
				گلدن اسکن حالا تلگرام را به‌صورت زنجیره‌ای می‌بیند: <code dir="ltr" class="gi-mono">Telegram Node → Node → Node → Asset</code> (مثلاً کانال → دکمه → PartyManagerBot → دکمه → FileechBot → فایل).
				این حالت فقط روی Sessionهای <strong>تازه</strong> اثر می‌گذارد؛ Sessionهای قدیمی و کانال‌های تک‌دکمه‌ای با حالت legacy بدون تغییر کار می‌کنند.
			</p>
			<div class="gi-form-row">
				<div class="gi-field">
					<label for="gs-chain-mode">حالت پردازش</label>
					<select id="gs-chain-mode" style="min-width:280px;">
						<option value="legacy" <?php selected( $stats['chain_mode'] ?? 'auto', 'legacy' ); ?>>legacy — مسیر قدیمی Button → File (دست‌نخورده)</option>
						<option value="auto" <?php selected( $stats['chain_mode'] ?? 'auto', 'auto' ); ?>>auto — Asset → قدیم | DeepLink/Button/Bot → زنجیره (پیشنهادی)</option>
						<option value="chain" <?php selected( $stats['chain_mode'] ?? 'auto', 'chain' ); ?>>chain — همه‌چیز از زنجیره</option>
					</select>
				</div>
			</div>
			<div class="gi-flex" style="align-items:center;gap:var(--gi-s3);">
				<button class="gi-btn gi-btn--primary" id="gs-chain-save">ذخیره حالت</button>
				<span id="gs-chain-status" class="gi-card-sub"></span>
			</div>
			<details style="margin-top:var(--gi-s3);">
				<summary style="cursor:pointer;color:var(--gi-text-faint);font-weight:700;font-size:var(--gi-fs1);">توضیح حالت‌ها</summary>
				<ul style="line-height:1.9;color:var(--gi-text-muted);margin:var(--gi-s2) 0 0;padding-inline-start:20px;font-size:var(--gi-fs1);">
					<li><strong>legacy:</strong> دقیقاً رفتار نسخه‌های قبل — Resolver قدیمی، فقط <code dir="ltr">Button → File</code>.</li>
					<li><strong>auto:</strong> فایل (Asset) → همان مسیر قدیم (Matcher با اولویت CODE→NAME→CAPTION→HASH)؛ دکمه/DeepLink/ربات → زنجیره‌ی جدید با <code dir="ltr">messages.startBot</code>.</li>
					<li><strong>chain:</strong> همه‌ی مسیرها از Chain Engine می‌گذرند (برای کانال‌های چندرباتی مثل PartyManagerBot → FileechBot).</li>
				</ul>
			</details>
		</div>

		<?php
		$fails = $stats['failures'] ?? array( 'items' => array(), 'by_reason' => array() );
		if ( ! empty( $fails['by_reason'] ) ) : ?>

		<!-- Failures -->
		<div class="gi-card gi-span-8" style="border-inline-start:4px solid var(--gi-danger);">
			<div class="gi-card-head">
				<h2 class="gi-card-title">🔴 چرا خطا خورد</h2>
				<span class="gi-card-sub">گزارش «۱۵ خطا» به‌تنهایی چیزی نمی‌گوید — اینجا علت‌ها گروه‌بندی شده‌اند</span>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table gi-responsive">
					<thead><tr><th scope="col" style="width:90px;">تعداد</th><th scope="col">مرحله و علت</th></tr></thead>
					<tbody>
						<?php foreach ( $fails['by_reason'] as $reason => $count ) : ?>
							<tr>
								<td data-label="تعداد"><strong class="gi-nums"><?php echo (int) $count; ?></strong></td>
								<td data-label="مرحله و علت"><?php echo esc_html( $reason ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<details style="margin:var(--gi-s3) 0;">
				<summary style="cursor:pointer;font-weight:700;font-size:var(--gi-fs1);min-height:40px;display:inline-flex;align-items:center;">آخرین <?php echo count( $fails['items'] ); ?> مورد به تفکیک</summary>
				<div class="gi-table-wrap" style="margin-top:var(--gi-s2);">
					<table class="gi-table gi-responsive">
						<thead><tr><th scope="col">Session</th><th scope="col">وضعیت</th><th scope="col">مرحله</th><th scope="col">پیام</th><th scope="col">زمان</th></tr></thead>
						<tbody>
							<?php foreach ( $fails['items'] as $f ) : ?>
								<tr>
									<td data-label="Session" class="gi-nums">#<?php echo (int) $f['session_id']; ?></td>
									<td data-label="وضعیت"><code dir="ltr" style="font-size:var(--gi-fs0);"><?php echo esc_html( $f['state'] ); ?></code></td>
									<td data-label="مرحله"><?php echo esc_html( $f['stage'] ); ?></td>
									<td data-label="پیام"><?php echo esc_html( $f['message'] ); ?></td>
									<td data-label="زمان" style="white-space:nowrap;"><?php echo esc_html( $f['at'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</details>
		</div>

		<div class="gi-card gi-span-4">
			<div class="gi-card-head"><h2 class="gi-card-title">🩺 سلامت پردازش</h2></div>
			<p class="gi-card-sub" style="font-size:var(--gi-fs1);">اگر تعداد خطا رو به افزایش است، از «گزارش‌ها» علت‌ها را ببینید. خطاهای گذرا توسط Retry/Backoff خودکار مدیریت می‌شوند و فقط موارد نهایی به Review می‌روند.</p>
			<a class="gi-btn gi-btn--subtle" style="text-decoration:none;display:inline-flex;margin-top:var(--gi-s3);" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=logs' ) ); ?>">📝 دیدن گزارش‌ها</a>
		</div>

		<?php endif; ?>

		<?php if ( $queue ) :
			$queued_items = STI_GS_Publish_Queue::items( 'queued', 50 );
			$done_items   = STI_GS_Publish_Queue::items( 'published', 10 );
		?>

		<!-- Publish queue -->
		<div class="gi-card gi-card--accent gi-span-12">
			<div class="gi-card-head">
				<div>
					<h2 class="gi-card-title">📤 صف انتشار — قلب موفقیت خط تولید</h2>
					<span class="gi-card-sub">این صف مستقل از صفحه‌ی «صف انتشار» قدیمی است (آن صفحه مسیر ربات تلگرام را سرویس می‌دهد و محصولات گلدن اسکن را نمی‌بیند — به همین دلیل همیشه «۰ محصول» نشان می‌دهد). همه‌ی کنترل‌های لازم اینجاست.</span>
				</div>
			</div>
			<div class="gi-flex" style="align-items:center;flex-wrap:wrap;gap:var(--gi-s3);">
				<button class="gi-btn <?php echo $queue['running'] ? 'gi-btn--danger' : 'gi-btn--success'; ?>" id="gs-q-toggle" data-on="<?php echo $queue['running'] ? '1' : '0'; ?>">
					<?php echo $queue['running'] ? '⏸ توقف صف' : '▶ شروع صف'; ?>
				</button>
				<label class="gi-field" style="margin:0;"><span class="gi-field-label" style="display:block;">فاصله (دقیقه)</span>
					<input type="number" id="gs-q-interval" min="1" style="width:90px;" value="<?php echo (int) round( $queue['interval_min'] ); ?>">
				</label>
				<button class="gi-btn gi-btn--subtle" id="gs-q-save">💾 ذخیره</button>
				<label class="gi-field" style="margin:0;"><span class="gi-field-label" style="display:block;">انتشار فوری</span>
					<input type="number" id="gs-q-count" min="1" max="50" value="5" style="width:80px;">
				</label>
				<button class="gi-btn gi-btn--primary" id="gs-q-now">🚀 انتشار فوری</button>
			</div>
		</div>

		<div class="gi-card gi-span-4">
			<div class="gi-card-head"><h2 class="gi-card-title">وضعیت صف</h2></div>
			<table class="gi-table">
				<tbody>
					<tr><td>وضعیت صف</td><td style="text-align:end;font-weight:700;"><?php echo $queue['running'] ? '<span class="gi-badge gi-badge--success">🟢 روشن</span>' : '<span class="gi-badge">⚪ خاموش</span>'; ?></td></tr>
					<tr><td>در صف</td><td class="gi-nums" style="text-align:end;font-weight:800;"><?php echo (int) $queue['queued']; ?></td></tr>
					<tr><td>بدون زمان‌بندی</td>
						<td style="text-align:end;font-weight:700;">
							<?php
							$un = (int) ( $queue['unscheduled'] ?? 0 );
							echo $un > 0
								? '<span class="gi-badge gi-badge--danger">🔴 ' . $un . ' — هرگز منتشر نمی‌شوند</span>'
								: '<span class="gi-badge gi-badge--success">🟢 ۰</span>';
							?></td></tr>
					<tr><td>نوبت بعدی</td><td style="text-align:end;"><?php echo esc_html( $queue['next_at'] ?: '—' ); ?></td></tr>
					<tr><td>منتشرشده</td><td class="gi-nums" style="text-align:end;font-weight:800;color:var(--gi-success);"><?php echo (int) $queue['published']; ?></td></tr>
					<tr><td>فاصله‌ی انتشار</td><td style="text-align:end;"><span class="gi-nums"><?php echo (int) $queue['interval_min']; ?></span> دقیقه</td></tr>
					<tr><td>اجرای دستی</td>
						<td style="text-align:end;"><button class="gi-btn gi-btn--subtle gi-btn--sm" id="gs-q-run">🚀 نوبت بعدی همین حالا</button>
						<div class="gi-card-sub" style="margin-top:4px;">دکمه‌ی صفحه‌ی «صف انتشار» فقط صف قدیمی را اجرا می‌کند</div></td></tr>
					<tr><td>سقف روزانه</td><td style="text-align:end;"><?php echo $queue['daily_cap'] > 0 ? '<span class="gi-nums">' . (int) $queue['daily_cap'] . '</span> (امروز: <span class="gi-nums">' . (int) $queue['published_today'] . '</span>)' : 'بدون سقف'; ?></td></tr>
				</tbody>
			</table>
		</div>

		<div class="gi-card gi-card--flush gi-span-8">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) var(--gi-s3);">
				<h2 class="gi-card-title">📬 محصولات در صف <span class="gi-nums"><?php echo count( $queued_items ); ?></span></h2>
			</div>
			<?php if ( empty( $queued_items ) ) : ?>
				<div class="gi-empty" style="padding:var(--gi-s6);">
					<div class="gi-empty-ico" aria-hidden="true">📭</div>
					<div class="gi-empty-title">صف انتشار خالی است.</div>
					<div class="gi-empty-sub">محصولاتی که PRODUCT_READY شدند، اینجا صف انتشار می‌گیرند.</div>
				</div>
			<?php else : ?>
				<div class="gi-table-wrap" style="border:none;border-radius:0;">
					<table class="gi-table gi-responsive">
						<thead><tr>
							<th scope="col" style="width:90px;">محصول</th><th scope="col">عنوان</th>
							<th scope="col" style="width:140px;">دسته</th><th scope="col" style="width:100px;">قیمت</th>
							<th scope="col" style="width:160px;">نوبت انتشار</th>
						</tr></thead>
						<tbody>
							<?php foreach ( $queued_items as $it ) : ?>
								<tr>
									<td data-label="محصول" class="gi-nums">#<?php echo (int) $it['product_id']; ?></td>
									<td data-label="عنوان"><a href="<?php echo esc_url( $it['edit_link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ?: '—' ); ?></a></td>
									<td data-label="دسته"><?php echo esc_html( $it['category'] ); ?></td>
									<td data-label="قیمت" class="gi-nums"><?php echo $it['price'] !== '' ? esc_html( number_format_i18n( (float) $it['price'] ) ) : '—'; ?></td>
									<td data-label="نوبت انتشار" style="white-space:nowrap;"><?php echo esc_html( $it['scheduled_at'] ?: '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $done_items ) ) : ?>
				<h3 style="margin:var(--gi-s4) var(--gi-s5) var(--gi-s2);font-size:var(--gi-fs1);">✅ آخرین منتشرشده‌ها</h3>
				<div class="gi-table-wrap" style="border:none;border-radius:0;margin-bottom:var(--gi-s4);">
					<table class="gi-table">
						<tbody>
							<?php foreach ( $done_items as $it ) : ?>
								<tr>
									<td class="gi-nums" style="width:70px;">#<?php echo (int) $it['product_id']; ?></td>
									<td><a href="<?php echo esc_url( $it['view_link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ?: '—' ); ?></a></td>
									<td style="width:130px;"><?php echo esc_html( $it['category'] ); ?></td>
									<td class="gi-nums" style="width:90px;"><?php echo $it['price'] !== '' ? esc_html( number_format_i18n( (float) $it['price'] ) ) : '—'; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<?php endif; ?>

		<!-- Rebuild -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<div>
					<h2 class="gi-card-title">🛠 بازسازی محصولات ساخته‌شده</h2>
					<span class="gi-card-sub">اصلاح موتور عنوان فقط روی محصولات <strong>تازه</strong> اثر دارد. محصولاتی که با منطق قبلی ساخته شده‌اند خودشان درست نمی‌شوند — این ابزار عنوان، دسته و قیمت آن‌ها را با منطق فعلی دوباره می‌سازد؛ بدون دانلود دوباره و بدون ساخت محصول تازه.</span>
				</div>
			</div>
			<div class="gi-flex" style="align-items:center;flex-wrap:wrap;gap:var(--gi-s4);">
				<label class="gi-field" style="margin:0;"><span class="gi-field-label" style="display:block;">تعداد</span>
					<input type="number" id="gs-rb-count" min="1" max="50" value="10" style="width:90px;">
				</label>
				<label style="display:flex;gap:8px;align-items:center;min-height:44px;font-weight:600;">
					<input type="checkbox" id="gs-rb-desc"> توضیحات هم بازنویسی شود
				</label>
				<label style="display:flex;gap:8px;align-items:center;min-height:44px;font-weight:600;">
					<input type="checkbox" id="gs-rb-price" checked> قیمت هم اصلاح شود
				</label>
			</div>
			<div class="gi-flex" style="margin-top:var(--gi-s3);">
				<button class="gi-btn gi-btn--subtle" id="gs-rb-preview">👁 پیش‌نمایش تغییرات</button>
				<button class="gi-btn gi-btn--primary" id="gs-rb-apply" disabled>✅ اعمال</button>
			</div>
			<div id="gs-rb-result" class="gi-mt-5"></div>
		</div>

		<?php $wt = class_exists( 'STI_GS_Channel_Watcher' ) ? STI_GS_Channel_Watcher::stats() : null; ?>
		<?php if ( $wt ) : ?>

		<!-- Watcher -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<div>
					<h2 class="gi-card-title">🛰 پایش کانال (خودکارسازی کامل)</h2>
					<span class="gi-card-sub">حلقه‌ی گمشده: اسکن کانال، اجرای پروفایل‌ها و ساخت Session تا امروز دستی بودند. با روشن بودن این، مسیر «کانال → محصول منتشرشده» بدون کلیک کامل می‌شود. هر <span class="gi-nums"><?php echo (int) $wt['interval_min']; ?></span> دقیقه، حداکثر <span class="gi-nums"><?php echo (int) $wt['batch']; ?></span> Session.</span>
				</div>
			</div>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:var(--gi-s3);">
				<div class="gi-stat gi-stat--<?php echo $wt['enabled'] ? 'success' : 'muted'; ?>"><div class="gi-stat-v" style="font-size:var(--gi-fs2);"><?php echo $wt['enabled'] ? '🟢 روشن' : '⚪ خاموش'; ?></div><div class="gi-stat-l">وضعیت Watcher</div></div>
				<div class="gi-stat gi-stat--info"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( $wt['ready'] ); ?></div><div class="gi-stat-l">آماده‌ی ساخت Session</div></div>
				<div class="gi-stat gi-stat--<?php echo $wt['backlog'] >= $wt['backlog_limit'] ? 'danger' : 'muted'; ?>"><div class="gi-stat-v gi-nums"><?php echo (int) $wt['backlog']; ?> / <?php echo (int) $wt['backlog_limit']; ?></div><div class="gi-stat-l">صف ناتمام (فشار معکوس)</div></div>
				<div class="gi-stat"><div class="gi-stat-v gi-nums"><?php echo (int) $wt['created_today']; ?><?php echo $wt['daily_cap'] ? ' / ' . (int) $wt['daily_cap'] : ''; ?></div><div class="gi-stat-l">ساخته‌شده امروز</div></div>
				<div class="gi-stat gi-stat--<?php echo $wt['no_category'] ? 'warning' : 'muted'; ?>"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( $wt['no_category'] ); ?></div><div class="gi-stat-l">بدون دسته (نادیده)</div></div>
			</div>

			<?php if ( $wt['no_category'] > 0 ) : ?>
				<div class="gi-notice gi-notice--warning" style="margin:var(--gi-s4) 0;">
					⚠ <span class="gi-nums"><?php echo number_format_i18n( $wt['no_category'] ); ?></span> Candidate به پروفایلی تعلق دارند که <strong>دسته‌ی پیش‌فرض ندارد</strong>. Watcher عمداً از آن‌ها Session نمی‌سازد — وگرنه محصول بی‌دسته و بی‌قیمت تولید می‌شود که بعداً باید بازسازی شود. در تب «پروفایل‌ها» برایشان دسته تعیین کنید.
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $wt['note'] ) ) : ?>
				<p class="gi-card-sub" style="font-size:var(--gi-fs1);">آخرین اجرا: <?php echo esc_html( $wt['note'] ); ?></p>
			<?php endif; ?>

			<div class="gi-flex" style="align-items:center;flex-wrap:wrap;margin-top:var(--gi-s3);">
				<button class="gi-btn <?php echo $wt['enabled'] ? 'gi-btn--danger' : 'gi-btn--success'; ?>" id="gs-wt-toggle" data-on="<?php echo $wt['enabled'] ? '1' : '0'; ?>">
					<?php echo $wt['enabled'] ? '⏸ توقف پایش' : '▶ شروع پایش'; ?>
				</button>
				<button class="gi-btn gi-btn--subtle" id="gs-wt-run">🔄 اجرای فوری یک چرخه</button>
			</div>
		</div>

		<?php endif; ?>

		<?php $rec = class_exists( 'STI_GS_Recovery' ) ? STI_GS_Recovery::stats() : null; ?>
		<?php if ( $rec ) : ?>

		<!-- Recovery -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<div>
					<h2 class="gi-card-title">🩹 خودترمیمی زیرساخت</h2>
					<span class="gi-card-sub">این لایه فقط قفل‌های رهاشده را آزاد می‌کند. تصمیم درباره‌ی مسیر زنجیره با Chain Engine است.</span>
				</div>
			</div>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:var(--gi-s3);">
				<div class="gi-stat gi-stat--<?php echo $rec['stale_locks'] ? 'warning' : 'success'; ?>"><div class="gi-stat-v gi-nums"><?php echo (int) $rec['stale_locks']; ?></div><div class="gi-stat-l">قفل رهاشده (Chain)</div></div>
				<div class="gi-stat gi-stat--<?php echo $rec['orphans'] ? 'danger' : 'success'; ?>"><div class="gi-stat-v gi-nums"><?php echo (int) $rec['orphans']; ?></div><div class="gi-stat-l">یتیم (دانلود/مدیا)</div></div>
				<div class="gi-stat gi-stat--success"><div class="gi-stat-v gi-nums"><?php echo (int) $rec['recovered_today']; ?></div><div class="gi-stat-l">ترمیم‌شده امروز</div></div>
				<div class="gi-stat gi-stat--<?php echo $rec['dead'] ? 'warning' : 'muted'; ?>"><div class="gi-stat-v gi-nums"><?php echo (int) $rec['dead']; ?></div><div class="gi-stat-l">صف مرده</div></div>
				<div class="gi-stat"><div class="gi-stat-v gi-nums"><?php echo (int) $rec['retry_queue']; ?></div><div class="gi-stat-l">در صف تلاش دوباره</div></div>
			</div>

			<p class="gi-card-sub" style="font-size:var(--gi-fs1);margin-top:var(--gi-s4);">حالت مهلت‌گذاری:
				<?php if ( 'signal' === $rec['deadline_mode'] ) : ?>
					<span class="gi-badge gi-badge--success">🟢 سیگنال</span> — تماس قفل‌شده با خطای کنترل‌شده متوقف می‌شود و قفل بلافاصله آزاد می‌گردد.
				<?php else : ?>
					<span class="gi-badge gi-badge--warning">🟡 محدودیت زمان</span> — این هاست <code dir="ltr">pcntl</code> ندارد؛ درخواست کشته می‌شود و قفل تا انقضای TTL می‌ماند. Watchdog همان‌ها را آزاد می‌کند.
				<?php endif; ?>
			</p>

			<div class="gi-flex" style="align-items:center;flex-wrap:wrap;margin-top:var(--gi-s3);">
				<button class="gi-btn gi-btn--subtle" id="gs-wd-run">🩹 اجرای فوری Watchdog</button>
				<?php if ( $rec['dead'] > 0 ) : ?>
					<button class="gi-btn gi-btn--ghost" id="gs-wd-revive">↩ بازگرداندن صف مرده</button>
				<?php endif; ?>
			</div>

			<?php $dl = STI_GS_Recovery::dead_letters( 10 ); if ( $dl ) : ?>
				<details style="margin:var(--gi-s4) 0 0;">
					<summary style="cursor:pointer;font-weight:700;font-size:var(--gi-fs1);min-height:40px;display:inline-flex;align-items:center;">صف مرده (<?php echo count( $dl ); ?> مورد اخیر)</summary>
					<div class="gi-table-wrap" style="margin-top:var(--gi-s2);">
						<table class="gi-table gi-responsive">
							<thead><tr><th scope="col">Session</th><th scope="col">کد فایل</th><th scope="col">مرحله</th><th scope="col">دلیل</th></tr></thead>
							<tbody>
								<?php foreach ( $dl as $d ) : ?>
									<tr>
										<td data-label="Session" class="gi-nums">#<?php echo (int) $d['id']; ?></td>
										<td data-label="کد فایل" dir="ltr"><code style="font-size:var(--gi-fs0);"><?php echo esc_html( $d['file_code'] ?: '—' ); ?></code></td>
										<td data-label="مرحله"><?php echo esc_html( $d['stage'] ); ?></td>
										<td data-label="دلیل" style="font-size:var(--gi-fs0);"><?php echo esc_html( $d['error_reason'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</details>
			<?php endif; ?>

			<?php if ( class_exists( 'STI_GS_Flags' ) ) : ?>
				<h3 style="margin:var(--gi-s5) 0 var(--gi-s3);font-size:var(--gi-fs1);">کلیدهای قابلیت</h3>
				<div class="gi-table-wrap">
					<table class="gi-table">
						<tbody>
						<?php foreach ( STI_GS_Flags::definitions() as $key => $def ) : ?>
							<tr>
								<td style="width:60px;"><input type="checkbox" class="gs-flag" data-flag="<?php echo esc_attr( $key ); ?>" <?php checked( STI_GS_Flags::on( $key ) ); ?> style="width:22px;height:22px;"></td>
								<td><strong><?php echo esc_html( $def['label'] ); ?></strong><br>
									<span class="gi-card-sub"><?php echo esc_html( $def['note'] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<?php endif; ?>

		<div class="gi-notice gi-notice--info gi-span-12">
			<strong>ترتیب درست روشن کردن:</strong> اول Worker را روشن کنید و بگذارید چند دور بچرخد و محصولات ساخته شوند. وقتی از کیفیت عنوان، دسته و قیمت مطمئن شدید، آن‌وقت صف انتشار را روشن کنید. تا صف خاموش است، هیچ محصولی منتشر نمی‌شود و همه به‌صورت پیش‌نویس می‌مانند.
		</div>

	</div><!-- /gi-bento -->

	<script>
	(function(){
		function post(action, extra){
			var body = new URLSearchParams(Object.assign({ action: action, nonce: STI.nonce }, extra || {}));
			return fetch(STI.ajaxUrl || ajaxurl, { method:'POST', credentials:'same-origin', body: body })
				.then(function(r){ return r.text(); })
				.then(function(t){
					try { return JSON.parse(t); }
					catch(e){ throw new Error('پاسخ نامعتبر از سرور:\n' + t.slice(0,300)); }
				});
		}
		function paint(d){
			if (!d) return;
			var pe = document.getElementById('gs-w-pending');
			var st = document.getElementById('gs-w-stuck');
			if (pe && window.GI) { window.GI.setNumber(pe, d.pending); } else if (pe) { pe.textContent = d.pending; }
			if (st && window.GI) { window.GI.setNumber(st, d.stuck + (d.review || 0)); } else if (st) { st.textContent = d.stuck + (d.review || 0); }
			var t = d.today || {};
			var rows = document.querySelectorAll('#gs-w-today tbody tr td:last-child');
			[t.advanced||0, t.waiting||0, t.completed||0, t.failed||0, t.ticks||0]
				.forEach(function(v,i){ if (rows[i]) rows[i].textContent = v; });
			var btn = document.getElementById('gs-w-toggle');
			btn.dataset.on = d.enabled ? '1' : '0';
			btn.textContent = d.enabled ? '⏸ خاموش کردن' : '▶ روشن کردن';
		}
		function refresh(){ if (!document.hidden) { post('sti_gs_worker_stats').then(function(r){ if (r.success) paint(r.data); }); } }

		document.getElementById('gs-w-toggle').addEventListener('click', function(){
			var on = this.dataset.on === '1';
			post('sti_gs_worker_toggle', { enabled: on ? '' : '1' })
				.then(function(r){ if (r.success) { paint(r.data); location.reload(); } });
		});
		document.getElementById('gs-w-run').addEventListener('click', function(){
			var b = this; b.disabled = true; b.textContent = 'در حال اجرا...';
			post('sti_gs_worker_run_now').then(function(r){
				b.disabled = false; b.textContent = 'اجرای فوری یک دور';
				if (!r.success) { alert((r.data && r.data.message) || 'خطا'); return; }
				paint(r.data);
			}).catch(function(e){ b.disabled=false; b.textContent='اجرای فوری یک دور'; alert(e.message); });
		});
		document.getElementById('gs-w-reset').addEventListener('click', function(){
			if (!confirm('شمارنده‌ی تلاش همه‌ی موارد گیرکرده صفر شود؟')) return;
			post('sti_gs_worker_reset').then(function(r){
				if (r.success) { alert(r.data.message); paint(r.data); }
			});
		});
		var qRun = document.getElementById('gs-q-run');
		if (qRun) {
			qRun.addEventListener('click', function(){
				var b = this; b.disabled = true; b.textContent = 'در حال انتشار...';
				post('sti_gs_queue_run_now').then(function(r){
					b.disabled = false; b.textContent = '🚀 نوبت بعدی همین حالا';
					if (!r.success) { alert((r.data && r.data.message) || 'خطا'); return; }
					alert('منتشرشده تا این لحظه: ' + (r.data.published || 0) + '\nدر صف: ' + (r.data.queued || 0));
					location.reload();
				}).catch(function(e){ b.disabled=false; b.textContent='🚀 نوبت بعدی همین حالا'; alert(e.message); });
			});
		}

		function bind(id, fn){ var el = document.getElementById(id); if (el) el.addEventListener('click', fn); }

		bind('gs-q-toggle', function(){
			var on = this.dataset.on === '1';
			post('sti_gs_queue_toggle', { enabled: on ? '' : '1' })
				.then(function(r){ if (r.success) location.reload(); });
		});

		bind('gs-q-save', function(){
			var m = document.getElementById('gs-q-interval').value;
			post('sti_gs_queue_interval', { minutes: m }).then(function(r){
				alert(r.success ? 'فاصله ذخیره شد.' : ((r.data && r.data.message) || 'خطا'));
				if (r.success) location.reload();
			});
		});

		bind('gs-chain-save', function(){
			var m = document.getElementById('gs-chain-mode').value;
			var st = document.getElementById('gs-chain-status');
			st.textContent = 'در حال ذخیره...';
			post('sti_gs_worker_chain_mode', { mode: m }).then(function(r){
				if (r.success) { st.textContent = '✓ ذخیره شد (حالت: ' + r.data.chain_mode + ').'; }
				else { st.textContent = 'خطا: ' + ((r.data && r.data.message) || 'نامشخص'); }
			}).catch(function(e){ st.textContent = 'خطا: ' + e.message; });
		});

		bind('gs-q-now', function(){
			var n = document.getElementById('gs-q-count').value;
			if (!confirm(n + ' محصول همین حالا منتشر شود؟')) return;
			var b = this; b.disabled = true; b.textContent = 'در حال انتشار...';
			post('sti_gs_queue_publish_now', { count: n }).then(function(r){
				b.disabled = false; b.textContent = '🚀 انتشار فوری';
				alert((r.data && r.data.message) || 'انجام شد');
				location.reload();
			}).catch(function(e){ b.disabled=false; b.textContent='🚀 انتشار فوری'; alert(e.message); });
		});

		bind('gs-rb-preview', function(){
			var b = this; b.disabled = true; b.textContent = 'در حال محاسبه...';
			post('sti_gs_rebuild_preview', { count: document.getElementById('gs-rb-count').value })
				.then(function(r){
					b.disabled = false; b.textContent = '👁 پیش‌نمایش تغییرات';
					if (!r.success) { alert((r.data && r.data.message) || 'خطا'); return; }
					var rows = r.data.rows || [];
					var html = '<div class="gi-table-wrap"><table class="gi-table gi-responsive"><thead><tr>' +
						'<th scope="col">محصول</th><th scope="col">عنوان فعلی</th><th scope="col">عنوان تازه</th>' +
						'<th scope="col">دسته</th><th scope="col">قیمت</th></tr></thead><tbody>';
					rows.forEach(function(x){
						var changed = x.after && x.after !== x.before;
						html += '<tr' + (changed ? ' class="gi-row-ok"' : '') + '>' +
							'<td data-label="محصول" class="gi-nums">#' + x.product_id + '</td>' +
							'<td data-label="عنوان فعلی" class="gi-faint">' + (x.before || '—') + '</td>' +
							'<td data-label="عنوان تازه"><strong>' + (x.after || '(بدون تغییر)') + '</strong></td>' +
							'<td data-label="دسته">' + (x.category || '—') + '</td>' +
							'<td data-label="قیمت" class="gi-nums">' + (x.price || '—') + '</td></tr>';
					});
					html += '</tbody></table></div>';
					document.getElementById('gs-rb-result').innerHTML = html;
					document.getElementById('gs-rb-apply').disabled = rows.length === 0;
				}).catch(function(e){ b.disabled=false; b.textContent='👁 پیش‌نمایش تغییرات'; alert(e.message); });
		});

		bind('gs-rb-apply', function(){
			if (!confirm('عنوان و دسته و قیمت این محصولات بازنویسی شود؟')) return;
			var b = this; b.disabled = true; b.textContent = 'در حال اعمال...';
			post('sti_gs_rebuild_apply', {
				count: document.getElementById('gs-rb-count').value,
				description: document.getElementById('gs-rb-desc').checked ? '1' : '',
				price: document.getElementById('gs-rb-price').checked ? '1' : ''
			}).then(function(r){
				b.textContent = '✅ اعمال';
				alert((r.data && r.data.message) || 'انجام شد');
				location.reload();
			}).catch(function(e){ b.disabled=false; b.textContent='✅ اعمال'; alert(e.message); });
		});

		document.getElementById('gs-w-refresh').addEventListener('click', refresh);
		setInterval(refresh, 20000);
	})();
	</script>

	<script>
	(function(){
		function post(a, x){
			var b=new URLSearchParams(Object.assign({action:a,nonce:STI.nonce}, x||{}));
			return fetch(STI.ajaxUrl||ajaxurl,{method:'POST',credentials:'same-origin',body:b})
				.then(function(r){return r.text();})
				.then(function(t){try{return JSON.parse(t);}catch(e){throw new Error(t.slice(0,300));}});
		}
		function bind(id,fn){var e=document.getElementById(id); if(e) e.addEventListener('click',fn);}


		bind('gs-wt-toggle', function(){
			var on = this.dataset.on === '1';
			post('sti_gs_watcher_toggle', { enabled: on ? '' : '1' })
				.then(function(){ location.reload(); });
		});
		bind('gs-wt-run', function(){
			var b=this; b.disabled=true; b.textContent='در حال اجرا...';
			post('sti_gs_watcher_run').then(function(r){
				b.disabled=false; b.textContent='🔄 اجرای فوری یک چرخه';
				alert((r.data && r.data.message) || 'انجام شد');
				location.reload();
			}).catch(function(e){ b.disabled=false; b.textContent='🔄 اجرای فوری یک چرخه'; alert(e.message); });
		});
		bind('gs-wd-run', function(){
			var b=this; b.disabled=true; b.textContent='در حال بررسی...';
			post('sti_gs_watchdog_run').then(function(){location.reload();})
				.catch(function(e){b.disabled=false;b.textContent='🩹 اجرای فوری Watchdog';alert(e.message);});
		});
		bind('gs-wd-revive', function(){
			if(!confirm('همه‌ی موارد صف مرده به چرخه برگردند؟'))return;
			post('sti_gs_revive_dead').then(function(r){
				alert((r.data&&r.data.message)||'انجام شد'); location.reload();});
		});
		document.querySelectorAll('.gs-flag').forEach(function(cb){
			cb.addEventListener('change', function(){
				post('sti_gs_flag_toggle',{flag:cb.dataset.flag,enabled:cb.checked?'1':''})
					.then(function(r){ if(!r.success){alert('خطا'); cb.checked=!cb.checked;} });
			});
		});
	})();
	</script>

</div>
