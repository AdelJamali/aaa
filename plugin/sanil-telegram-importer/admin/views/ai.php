<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

$cfg       = STI_AI::config();
$presets   = STI_AI::presets();
$providers = STI_AI::providers();
$summary   = method_exists( 'STI_AI', 'status_summary' ) ? STI_AI::status_summary() : array();
?>
<div class="wrap sti-wrap">
	<div class="sti-shell">
		<?php include __DIR__ . '/partials-tabs.php'; ?>
		<div class="sti-content">

		<div class="sti-hero">
			<div>
				<span class="sti-eyebrow">AI CONTROL</span>
				<h1>مرکز هوش مصنوعی</h1>
				<p>همه‌ی سرویس‌ها، پرامپت‌ها، پراکسی و تست اتصال یکجا. اگر سرویسی بیفتد یا توکنش تمام شود، خودکار سرویس بعدی جای آن را می‌گیرد.</p>
			</div>
			<div class="sti-hero-actions">
				<button type="button" class="sti-btn" id="ai-add">➕ افزودن سرویس</button>
				<button type="button" class="sti-btn secondary" id="ai-reset-health">♻️ ریست سلامت</button>
			</div>
		</div>

		<div class="sti-grid g4" style="margin-bottom:16px;">
			<div class="sti-kpi info"><div class="k-top"><span>سرویس آماده</span></div><div class="k-val"><?php echo (int) ( isset( $summary['ready'] ) ? $summary['ready'] : 0 ); ?></div><div class="k-sub">از <?php echo (int) ( isset( $summary['total'] ) ? $summary['total'] : count( $providers ) ); ?> سرویس ثبت‌شده</div></div>
			<div class="sti-kpi warn"><div class="k-top"><span>در استراحت</span></div><div class="k-val"><?php echo (int) ( isset( $summary['cooling'] ) ? $summary['cooling'] : 0 ); ?></div><div class="k-sub">پس از چند خطای پشت‌سرهم موقتاً کنار می‌روند</div></div>
			<div class="sti-kpi <?php echo ! empty( $cfg['enabled'] ) ? 'ok' : 'bad'; ?>"><div class="k-top"><span>وضعیت موتور</span></div><div class="k-val" style="font-size:20px;padding-top:8px;"><?php echo ! empty( $cfg['enabled'] ) ? 'روشن' : 'خاموش'; ?></div><div class="k-sub"><?php echo ! empty( $cfg['allow_free_fallback'] ) ? 'سنگر رایگان فعال' : 'بدون سنگر رایگان'; ?></div></div>
			<div class="sti-kpi <?php echo ! empty( $cfg['proxy_enabled'] ) ? 'ok' : 'warn'; ?>"><div class="k-top"><span>پراکسی</span></div><div class="k-val" style="font-size:20px;padding-top:8px;"><?php echo ! empty( $cfg['proxy_enabled'] ) ? 'روشن' : 'خاموش'; ?></div><div class="k-sub"><?php echo $cfg['proxy_host'] ? esc_html( $cfg['proxy_type'] . '://' . $cfg['proxy_host'] . ':' . $cfg['proxy_port'] ) : 'تنظیم نشده'; ?></div></div>
		</div>

		<div class="sti-tabs" id="ai-tabs">
			<button class="active" data-tab="providers">سرویس‌ها و کلیدها</button>
			<button data-tab="prompts">قالب پرامپت‌ها</button>
			<button data-tab="network">شبکه و پراکسی</button>
			<button data-tab="play">آزمایشگاه</button>
		</div>

		<div class="sti-tabpane active" id="pane-providers">
			<div class="sti-panel">
				<div class="sti-panel-head">
					<div><h2>زنجیره‌ی سرویس‌ها</h2><p>ترتیب اجرا بر اساس «اولویت» است (عدد کوچک‌تر = اول). سرویسی که چند بار پشت‌سرهم خطا بدهد موقتاً کنار می‌رود و بقیه کار را ادامه می‌دهند.</p></div>
					<button type="button" class="sti-btn secondary" id="ai-test-all">🧪 تست همه</button>
				</div>
				<div class="sti-table-wrap">
				<table class="sti-table">
					<thead><tr><th>اولویت</th><th>نام</th><th>مدل</th><th>کلید</th><th>وضعیت</th><th>عملیات</th></tr></thead>
					<tbody>
					<?php if ( empty( $providers ) ) : ?>
						<tr><td colspan="6">هنوز سرویسی ثبت نشده. پیشنهاد: یک واسط ایرانی (AvalAI/GapGPT) به‌عنوان سرویس اصلی و Groq به‌عنوان پشتیبان.</td></tr>
					<?php else : foreach ( $providers as $p ) :
						$h = STI_AI::health( $p['id'] );
						$cool = method_exists( 'STI_AI', 'is_cooling' ) ? (int) STI_AI::is_cooling( $p['id'] ) : 0;
						$key = (string) $p['api_key'];
						?>
						<tr>
							<td><?php echo (int) $p['priority']; ?></td>
							<td><strong><?php echo esc_html( $p['name'] ); ?></strong><br><small><?php echo esc_html( $presets[ $p['preset'] ]['name'] ?? $p['format'] ); ?></small></td>
							<td class="sti-mono"><?php echo esc_html( $p['model'] ); ?></td>
							<td class="sti-mono"><?php echo $key ? esc_html( mb_substr( $key, 0, 4 ) . '••••' . mb_substr( $key, -3 ) ) : '—'; ?></td>
							<td>
								<?php if ( empty( $p['enabled'] ) ) : ?>
									<span class="sti-health-pill off">خاموش</span>
								<?php elseif ( $cool > 0 ) : ?>
									<span class="sti-health-pill cool">استراحت <?php echo (int) ceil( $cool / 60 ); ?> دقیقه</span>
								<?php else : ?>
									<span class="sti-health-pill on">آماده</span>
								<?php endif; ?>
								<br><small><?php echo (int) ( isset( $h['calls'] ) ? $h['calls'] : 0 ); ?> تماس · <?php echo (int) ( isset( $h['fails'] ) ? $h['fails'] : 0 ); ?> خطای پیوسته<?php
									if ( 'inherit' !== $p['use_proxy'] ) {
										echo ' · پراکسی: ' . esc_html( 'always' === $p['use_proxy'] ? 'همیشه' : 'هرگز' );
									} elseif ( STI_AI::is_domestic( $p ) ) {
										echo ' · پراکسی: هرگز (سرویس ایرانی)';
									}
								?></small>
								<?php if ( ! empty( $h['last_error'] ) ) : ?><br><small style="color:#b91c1c;"><?php echo esc_html( mb_substr( $h['last_error'], 0, 100 ) ); ?></small><?php endif; ?>
							</td>
							<td>
								<button type="button" class="sti-btn secondary ai-test" data-id="<?php echo esc_attr( $p['id'] ); ?>">تست</button>
								<button type="button" class="sti-btn secondary ai-test" data-id="<?php echo esc_attr( $p['id'] ); ?>" data-proxy="1">تست با پراکسی</button>
								<button type="button" class="sti-btn secondary ai-edit" data-json="<?php echo esc_attr( wp_json_encode( array_merge( $p, array( 'api_key' => '' ) ) ) ); ?>">ویرایش</button>
								<button type="button" class="sti-btn danger ai-del" data-id="<?php echo esc_attr( $p['id'] ); ?>">حذف</button>
							</td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
				</div>
				<div id="ai-test-result" class="sti-inline-result"></div>
			</div>

			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>سیاست اجرا</h2><p>چطور بین سرویس‌ها جابه‌جا شود.</p></div></div>
				<div class="sti-grid g3">
					<div class="sti-field">
						<label>استراتژی</label>
						<select id="ai-rotation">
							<option value="priority" <?php selected( $cfg['rotation'], 'priority' ); ?>>اولویتی (اول سرویس اول)</option>
							<option value="manual" <?php selected( $cfg['rotation'], 'manual' ); ?>>دستی (یک سرویس انتخابی)</option>
							<option value="round_robin" <?php selected( $cfg['rotation'], 'round_robin' ); ?>>گردشی در هر تماس</option>
							<option value="time" <?php selected( $cfg['rotation'], 'time' ); ?>>گردشی زمانی</option>
						</select>
					</div>
					<div class="sti-field">
						<label>سرویس فعال (حالت دستی)</label>
						<select id="ai-active">
							<option value="">— خودکار —</option>
							<?php foreach ( $providers as $p ) : ?>
								<option value="<?php echo esc_attr( $p['id'] ); ?>" <?php selected( $cfg['active_id'], $p['id'] ); ?>><?php echo esc_html( $p['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="sti-field"><label>بازه‌ی چرخش (دقیقه)</label><input type="number" id="ai-rotmin" min="1" value="<?php echo (int) $cfg['rotation_minutes']; ?>"></div>
					<div class="sti-field"><label>مهلت هر تماس (ثانیه)</label><input type="number" id="ai-timeout" min="10" max="180" value="<?php echo (int) $cfg['timeout']; ?>"></div>
					<div class="sti-field">
						<label class="sti-toggle"><input type="checkbox" id="ai-enabled" <?php checked( ! empty( $cfg['enabled'] ) ); ?>> موتور هوش مصنوعی روشن</label>
						<label class="sti-toggle"><input type="checkbox" id="ai-cache" <?php checked( ! empty( $cfg['cache_enabled'] ) ); ?>> کش پاسخ‌های یکسان (صرفه‌جویی توکن)</label>
						<label class="sti-toggle"><input type="checkbox" id="ai-free" <?php checked( ! empty( $cfg['allow_free_fallback'] ) ); ?>> سنگر آخر رایگان (Pollinations)</label>
					</div>
				</div>
				<button type="button" class="sti-btn" id="ai-save-1">💾 ذخیره</button>
				<div id="ai-settings-result" class="sti-inline-result"></div>
			</div>
		</div>

		<div class="sti-tabpane" id="pane-prompts">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>استاندارد نوشتاری و پرامپت‌ها</h2><p>قانون نوشتن عنوان و متن سایت از همین‌جا کنترل می‌شود. متغیرها با آکولاد نوشته می‌شوند.</p></div></div>
				<p class="desc sti-chiplist">
					<span>{file_name}</span><span>{file_type}</span><span>{type_label_fa}</span><span>{category}</span><span>{software}</span>
					<span>{excerpt}</span><span>{title}</span><span>{file_code}</span><span>{filesize}</span>
					<span>{style_guide}</span><span>{title_pattern}</span><span>{max_words}</span><span>{forbidden}</span><span>{categories}</span>
				</p>
				<div class="sti-grid g3">
					<div class="sti-field"><label>الگوی عنوان</label><input type="text" id="ai-pattern" value="<?php echo esc_attr( $cfg['title_pattern'] ); ?>"></div>
					<div class="sti-field"><label>حداکثر کلمات عنوان</label><input type="number" id="ai-maxwords" min="4" value="<?php echo (int) $cfg['title_max_words']; ?>"></div>
					<div class="sti-field"><label>کلمات ممنوعه (با کاما)</label><input type="text" id="ai-forbidden" value="<?php echo esc_attr( $cfg['forbidden_words'] ); ?>"></div>
				</div>
				<div class="sti-field"><label>راهنمای سبک نوشتار (Style Guide)</label><textarea id="ai-style" class="sti-prompt-box" style="min-height:130px;"><?php echo esc_textarea( $cfg['style_guide'] ); ?></textarea></div>
				<div class="sti-field"><label>پرامپت عنوان</label><textarea id="ai-p-title" class="sti-prompt-box"><?php echo esc_textarea( STI_AI::prompt( 'title' ) ); ?></textarea></div>
				<div class="sti-field"><label>پرامپت توضیحات</label><textarea id="ai-p-desc" class="sti-prompt-box"><?php echo esc_textarea( STI_AI::prompt( 'description' ) ); ?></textarea></div>
				<div class="sti-field"><label>پرامپت ترجمه</label><textarea id="ai-p-tr" class="sti-prompt-box" style="min-height:120px;"><?php echo esc_textarea( STI_AI::prompt( 'translate' ) ); ?></textarea></div>
				<div class="sti-field"><label>پرامپت تشخیص دسته (داور اتوکت)</label><textarea id="ai-p-cat" class="sti-prompt-box" style="min-height:120px;"><?php echo esc_textarea( (string) STI_AI::get( 'prompt_category', '' ) ); ?></textarea><div class="hint">خالی بگذار تا از پرامپت پیش‌فرض داخلی استفاده شود.</div></div>
				<button type="button" class="sti-btn" id="ai-save-2">💾 ذخیره‌ی پرامپت‌ها</button>
				<div id="ai-prompts-result" class="sti-inline-result"></div>
			</div>
		</div>

		<div class="sti-tabpane" id="pane-network">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>پراکسی هوش مصنوعی</h2><p>OpenAI و Gemini برای ایران بسته‌اند. این پراکسی فقط برای تماس‌های AI است. برای هر سرویس هم جداگانه می‌توانی تعیین کنی از پراکسی رد شود یا نه.</p></div></div>
				<div class="sti-grid g3">
					<div class="sti-field">
						<label class="sti-toggle"><input type="checkbox" id="px-enabled" <?php checked( ! empty( $cfg['proxy_enabled'] ) ); ?>> پراکسی روشن</label>
					</div>
					<div class="sti-field">
						<label>نوع</label>
						<select id="px-type">
							<?php foreach ( array( 'socks5h' => 'SOCKS5 با DNS راه دور (پیشنهادی)', 'socks5' => 'SOCKS5', 'http' => 'HTTP', 'socks4' => 'SOCKS4' ) as $k => $lbl ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['proxy_type'], $k ); ?>><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="sti-field">
						<label>برای کدام سرویس‌ها</label>
						<select id="px-for">
							<option value="all" <?php selected( $cfg['proxy_for'], 'all' ); ?>>همه‌ی سرویس‌ها</option>
							<option value="marked" <?php selected( $cfg['proxy_for'], 'marked' ); ?>>فقط سرویس‌های علامت‌خورده</option>
						</select>
					</div>
					<div class="sti-field"><label>هاست</label><input type="text" id="px-host" dir="ltr" value="<?php echo esc_attr( $cfg['proxy_host'] ); ?>"></div>
					<div class="sti-field"><label>پورت</label><input type="text" id="px-port" dir="ltr" value="<?php echo esc_attr( $cfg['proxy_port'] ); ?>"></div>
					<div class="sti-field"><label>نام کاربری</label><input type="text" id="px-user" dir="ltr" value="<?php echo esc_attr( $cfg['proxy_user'] ); ?>"></div>
					<div class="sti-field"><label>رمز</label><input type="password" id="px-pass" dir="ltr" value="<?php echo esc_attr( $cfg['proxy_pass'] ); ?>"></div>
				</div>
				<div style="display:flex;gap:10px;flex-wrap:wrap;">
					<button type="button" class="sti-btn" id="ai-save-3">💾 ذخیره</button>
					<button type="button" class="sti-btn secondary" id="px-test">🔌 تست پراکسی</button>
				</div>
				<div id="ai-network-result" class="sti-inline-result"></div>
				<p class="desc">راه‌حل بدون پراکسی: یک واسط ایرانی (AvalAI / GapGPT / Liara) به‌عنوان سرویس اصلی ثبت کن تا تولید محتوا هیچ‌وقت متوقف نشود.</p>
			</div>
		</div>

		<div class="sti-tabpane" id="pane-play">
			<div class="sti-panel">
				<div class="sti-panel-head"><div><h2>آزمایشگاه پرامپت</h2><p>قبل از اجرا روی محصولات واقعی، همین‌جا امتحان کن.</p></div></div>
				<div class="sti-field">
					<label>سرویس</label>
					<select id="play-provider">
						<option value="">زنجیره‌ی خودکار (پیشنهادی)</option>
						<?php foreach ( $providers as $p ) : ?>
							<option value="<?php echo esc_attr( $p['id'] ); ?>"><?php echo esc_html( $p['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="sti-field"><label>پرامپت</label><textarea id="play-prompt" class="sti-prompt-box" style="min-height:120px;">یک عنوان فارسی سئو-پسند برای «coffee cup mockup psd» بنویس. فقط JSON: {"title":"..."}</textarea></div>
				<button type="button" class="sti-btn" id="play-run">▶️ اجرا</button>
				<div id="play-result" class="sti-inline-result"></div>
				<pre class="sti-code" id="play-out" style="display:none;white-space:pre-wrap;"></pre>
			</div>
		</div>

		</div>
	</div>
</div>

<div class="sti-modal-bg" id="ai-modal-bg">
	<div class="sti-modal">
		<h3 id="ai-modal-title">افزودن سرویس هوش مصنوعی</h3>
		<form id="ai-form">
			<input type="hidden" id="f-id">
			<div class="sti-field">
				<label>سرویس آماده</label>
				<select id="f-preset">
					<?php foreach ( $presets as $k => $pr ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" data-endpoint="<?php echo esc_attr( $pr['endpoint'] ); ?>" data-model="<?php echo esc_attr( $pr['model'] ); ?>" data-format="<?php echo esc_attr( $pr['format'] ); ?>" data-proxy="<?php echo esc_attr( $pr['proxy_default'] ?? 'inherit' ); ?>" data-note="<?php echo esc_attr( $pr['note'] ); ?>"><?php echo esc_html( $pr['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<div class="hint" id="f-note"></div>
			</div>
			<div class="sti-field"><label>نام دلخواه</label><input type="text" id="f-name" placeholder="مثلاً AvalAI اصلی"></div>
			<div class="sti-field"><label>آدرس Endpoint</label><input type="text" id="f-endpoint" dir="ltr"></div>
			<div class="sti-row">
				<div class="sti-field"><label>مدل</label><input type="text" id="f-model" dir="ltr"></div>
				<div class="sti-field">
					<label>قالب API</label>
					<select id="f-format">
						<option value="openai">OpenAI-compatible</option>
						<option value="gemini">Google Gemini</option>
						<option value="pollinations">Pollinations</option>
					</select>
				</div>
			</div>
			<div class="sti-field"><label>کلید API</label><input type="password" id="f-key" dir="ltr" placeholder="در ویرایش خالی بگذار تا کلید فعلی حفظ شود"></div>
			<div class="sti-row">
				<div class="sti-field"><label>اولویت (کوچک‌تر = اول)</label><input type="number" id="f-priority" min="1" max="99" value="10"></div>
				<div class="sti-field"><label>خلاقیت (temperature)</label><input type="number" id="f-temp" step="0.1" min="0" max="2" value="0.5"></div>
			</div>
			<div class="sti-row">
				<div class="sti-field"><label>حداکثر توکن پاسخ</label><input type="number" id="f-maxt" min="128" max="8192" value="900"></div>
				<div class="sti-field"><label>سقف تماس روزانه (۰ = بی‌نهایت)</label><input type="number" id="f-daily" min="0" value="0"></div>
			</div>
			<div class="sti-row">
				<div class="sti-field">
					<label>پراکسی</label>
					<select id="f-proxy">
						<option value="inherit">پیرو تنظیم کلی</option>
						<option value="always">همیشه با پراکسی</option>
						<option value="never">هرگز با پراکسی</option>
					</select>
				</div>
				<div class="sti-field"><label>مهلت (ثانیه)</label><input type="number" id="f-timeout" min="10" max="180" value="45"></div>
			</div>
			<div class="sti-field"><label class="sti-toggle"><input type="checkbox" id="f-enabled" checked> فعال</label></div>
			<div class="sti-modal-actions">
				<button type="button" class="sti-btn secondary" id="ai-cancel">انصراف</button>
				<button type="submit" class="sti-btn">ذخیره</button>
			</div>
		</form>
	</div>
</div>

<script>
jQuery(function ($) {
	var A = window.STI || {};
	function post(action, data, cb) {
		$.post(A.ajaxUrl, $.extend({ action: action, nonce: A.nonce }, data || {}), cb)
			.fail(function () { cb({ success: false, data: { message: 'خطای شبکه یا سرور' } }); });
	}
	function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
	function say($el, res, okMsg) {
		var ok = res && res.success;
		$el.html('<div class="' + (ok ? 'sti-ok' : 'sti-err') + '">' + esc(okMsg || (res.data && res.data.message) || (ok ? 'انجام شد' : 'خطا')) + '</div>');
	}

	$('#ai-tabs button').on('click', function () {
		$('#ai-tabs button').removeClass('active');
		$(this).addClass('active');
		$('.sti-tabpane').removeClass('active');
		$('#pane-' + $(this).data('tab')).addClass('active');
	});

	var $bg = $('#ai-modal-bg');
	function openModal(p) {
		p = p || {};
		$('#ai-modal-title').text(p.id ? 'ویرایش سرویس' : 'افزودن سرویس هوش مصنوعی');
		$('#f-id').val(p.id || '');
		$('#f-name').val(p.name || '');
		if (p.preset) { $('#f-preset').val(p.preset); }
		$('#f-preset').trigger('change');
		if (p.endpoint) { $('#f-endpoint').val(p.endpoint); }
		if (p.model) { $('#f-model').val(p.model); }
		if (p.format) { $('#f-format').val(p.format); }
		$('#f-key').val('');
		$('#f-priority').val(p.priority || 10);
		$('#f-temp').val(typeof p.temperature !== 'undefined' ? p.temperature : 0.5);
		$('#f-maxt').val(p.max_tokens || 900);
		$('#f-daily').val(p.daily_limit || 0);
		$('#f-timeout').val(p.timeout || 45);
		$('#f-proxy').val(p.use_proxy || 'inherit');
		$('#f-enabled').prop('checked', p.id ? !!Number(p.enabled) : true);
		$bg.addClass('show');
	}
	$('#ai-add').on('click', function () { openModal({}); });
	$('.ai-edit').on('click', function () { openModal($(this).data('json') || {}); });
	$('#ai-cancel').on('click', function () { $bg.removeClass('show'); });
	$bg.on('click', function (e) { if (e.target === this) { $bg.removeClass('show'); } });

	$('#f-preset').on('change', function () {
		var o = $(this).find('option:selected');
		var isNew = $('#f-id').val() === '';
		if (isNew || !$('#f-endpoint').val()) { $('#f-endpoint').val(o.data('endpoint') || ''); }
		if (isNew || !$('#f-model').val()) { $('#f-model').val(o.data('model') || ''); }
		if (isNew) { $('#f-format').val(o.data('format') || 'openai'); }
		if (isNew) { $('#f-proxy').val(o.data('proxy') || 'inherit'); }
		$('#f-note').text(o.data('note') || '');
	});

	$('#ai-form').on('submit', function (e) {
		e.preventDefault();
		post('sti_ai_save_provider', {
			id: $('#f-id').val(), name: $('#f-name').val(), preset: $('#f-preset').val(),
			format: $('#f-format').val(), endpoint: $('#f-endpoint').val(), model: $('#f-model').val(),
			api_key: $('#f-key').val(), priority: $('#f-priority').val(), temperature: $('#f-temp').val(),
			max_tokens: $('#f-maxt').val(), daily_limit: $('#f-daily').val(), timeout: $('#f-timeout').val(),
			use_proxy: $('#f-proxy').val(), enabled: $('#f-enabled').is(':checked') ? 1 : 0
		}, function (res) {
			if (res && res.success) { location.reload(); }
			else { alert((res.data && res.data.message) || 'ذخیره نشد'); }
		});
	});

	$('.ai-del').on('click', function () {
		if (!confirm('این سرویس حذف شود؟')) { return; }
		post('sti_ai_delete_provider', { id: $(this).data('id') }, function () { location.reload(); });
	});

	$('.ai-test').on('click', function () {
		var $b = $(this), txt = $b.text();
		$b.prop('disabled', true).text('…');
		post('sti_ai_test_provider', { id: $b.data('id'), with_proxy: $b.data('proxy') ? 1 : 0 }, function (res) {
			$b.prop('disabled', false).text(txt);
			say($('#ai-test-result'), res);
			if (res && res.success && res.data.sample) {
				$('#ai-test-result').append('<pre class="sti-code">' + esc(res.data.sample) + '</pre>');
			}
		});
	});

	$('#ai-test-all').on('click', function () {
		var $b = $(this).prop('disabled', true).text('در حال تست…');
		post('sti_ai_test_all', {}, function (res) {
			$b.prop('disabled', false).text('🧪 تست همه');
			if (!res || !res.success) { say($('#ai-test-result'), res); return; }
			var r = res.data.results || {};
			var html = '<table class="sti-table"><tbody>';
			$.each(r, function (k, v) {
				var okv = v && (v.ok || v.success);
				var msg = (v && (v.message || v.error)) || '';
				html += '<tr><td>' + esc(v && v.name ? v.name : k) + '</td><td>' + (okv ? '✅' : '❌') + ' ' + esc(msg) + '</td></tr>';
			});
			$('#ai-test-result').html(html + '</tbody></table>');
		});
	});

	$('#ai-reset-health').on('click', function () {
		post('sti_ai_reset_health', {}, function () { location.reload(); });
	});

	$('#px-test').on('click', function () {
		var $b = $(this).prop('disabled', true).text('در حال تست…');
		post('sti_ai_test_proxy', {}, function (res) {
			$b.prop('disabled', false).text('🔌 تست پراکسی');
			say($('#ai-network-result'), res);
		});
	});

	function payload() {
		return {
			enabled: $('#ai-enabled').is(':checked') ? 1 : 0,
			rotation: $('#ai-rotation').val(), active_id: $('#ai-active').val(),
			rotation_minutes: $('#ai-rotmin').val(), timeout: $('#ai-timeout').val(),
			cache_enabled: $('#ai-cache').is(':checked') ? 1 : 0,
			allow_free_fallback: $('#ai-free').is(':checked') ? 1 : 0,
			style_guide: $('#ai-style').val(), title_pattern: $('#ai-pattern').val(),
			title_max_words: $('#ai-maxwords').val(), forbidden_words: $('#ai-forbidden').val(),
			prompt_title: $('#ai-p-title').val(), prompt_description: $('#ai-p-desc').val(),
			prompt_translate: $('#ai-p-tr').val(), prompt_category: $('#ai-p-cat').val(),
			proxy_enabled: $('#px-enabled').is(':checked') ? 1 : 0, proxy_type: $('#px-type').val(),
			proxy_host: $('#px-host').val(), proxy_port: $('#px-port').val(),
			proxy_user: $('#px-user').val(), proxy_pass: $('#px-pass').val(), proxy_for: $('#px-for').val()
		};
	}
	$('#ai-save-1').on('click', function () { post('sti_ai_save_settings', payload(), function (r) { say($('#ai-settings-result'), r); }); });
	$('#ai-save-2').on('click', function () { post('sti_ai_save_settings', payload(), function (r) { say($('#ai-prompts-result'), r); }); });
	$('#ai-save-3').on('click', function () { post('sti_ai_save_settings', payload(), function (r) { say($('#ai-network-result'), r); }); });

	$('#play-run').on('click', function () {
		var $b = $(this).prop('disabled', true).text('در حال اجرا…');
		post('sti_ai_playground', { prompt: $('#play-prompt').val(), provider_id: $('#play-provider').val() }, function (res) {
			$b.prop('disabled', false).text('▶️ اجرا');
			if (res && res.success) {
				$('#play-result').html('<div class="sti-ok">پاسخ از ' + esc(res.data.provider) + ' — ' + res.data.ms + 'ms</div>');
				$('#play-out').show().text(res.data.text);
			} else {
				say($('#play-result'), res);
				$('#play-out').hide();
			}
		});
	});
});
</script>
