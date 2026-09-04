# DESIGN-10.11-UX — Golden Scan Console (Premium SaaS Operations Console)

**حوزه:** فقط presentation — لایه UI. موتور (backend) کاملاً دست‌نخورده است.
«ظاهر کاملاً جدید. موتور کاملاً محفوظ.»

## ۱. اصل BACKEND FREEZE
هیچ تغییری در: منطق کسب‌وکار، State Machine، Session transitions، Worker، Auto Worker،
Governor، Line State، Queue Engine، Publish Queue، Telegram، Bot، Download Strategy،
File Storage، Product Builder، AI Engine، Recovery Engine، Retry/Backoff، Cron،
نام/پارامتر/ساختار پاسخ AJAX، نام nonce، capability check، مقادیر `gs_view`، hooks.

فایل‌های تغییرکرده (۱۴+۲):
- `admin/views/golden-scan/*.php` — ۱۲ view (presentation-only rewrites)
- `admin/class-sti-admin.php` — فقط بلوک enqueue برای صفحه `sti-golden-scan`
- `admin/assets/css/gi-console.css` — جدید (design system)
- `admin/assets/js/gi-console.js` — جدید (helpers: theme / sheet / number transition)

## ۲. Design System (`gi-console.css`)
- Scope: `.gi-console` (chrome وردپرس دست‌نخورده می‌ماند)
- Tokens: `--gi-bg/surface/surface-raised/surface-sunken/border/text/text-muted/text-faint/
  brand/accent/success/warning/danger/info` (+ soft variants) — Light/Dark
- Dark: `prefers-color-scheme` + override دستی (🌓 در topbar → localStorage + class)
- Spacing 4–64 (4px scale) · Radius: badge 999 / btn 14 / card 22 / sheet 28
- Type: Vazirmatn (Google Fonts, enqueue فقط صفحه GS) fallback `Segoe UI, Tahoma, Noto Sans Arabic`
- Scale 12/14/16/20/26/34/44 · body lh 1.7 · heading 1.15
- RTL: فقط logical properties (margin-inline, inset-inline-start, …)
- Motion: `cubic-bezier(0.22,1,0.36,1)`؛ micro 180ms / component 280ms / page 360ms
- `@media (prefers-reduced-motion: reduce)` → کل انیمیشن‌ها خاموش
- Status: رنگ هرگز تنها carrier نیست (icon + label + color)

## ۳. Components
`.gi-topbar` (brand + line chip + theme) · `.gi-tabs` (2-row: groups + views) ·
`.gi-bnav` (glass bottom-nav موبایل، ۵ گروه) · `.gi-bento` ۱۲-col (span 3–12، بدون equal cards)
`.gi-hero` · `.gi-stat` · `.gi-flow` (pipeline ۷ مرحله، UI-only) · `.gi-chips` (mini progress)
`.gi-table` + `.gi-responsive` (tables→cards <700px با `data-label`) · `.gi-stream` (events)
`.gi-exc` (exception inbox cards) · `.gi-empty` (icon+text+CTA) · `.gi-skel` (skeleton)
`.gi-overlay/.gi-sheet` (bottom sheet موبایل / side panel دسکتاپ + focus trap + Escape + swipe)
Legacy remap: `.sti-btn/.sti-badge(+ok/error/warn)/.sti-panel/.sti-table/widefat` داخل console هم پوستی جدید می‌گیرند.

## ۴. ناوبری (مقادیر `gs_view` بی‌تغییر)
📡 منابع (channels/insight/profiles) · 🏭 خط تولید (automation/sessions/worker/review) ·
⚙️ اتوماسیون (automation-settings) · 🩺 سلامت سیستم (system-check/environment/test-wizard) ·
📝 گزارش‌ها (logs)

## ۵. خط تولید = Operations Control Center (Phase 10)
Hero: LINE STATUS (🟢//🟠//⚪ + pulse) + Worker/Governor/Active/Queue + START/STOP
Flow: DISCOVER→BOT→MATCH→DOWNLOAD→MEDIA→PRODUCT→PUBLISH با شمارش Sessionهای فعال
(صرفاً از داده‌ی موجود `monitor()` — بدون query جدید و بدون تغییر پاسخ)

## ۶. Motion Discipline (Phase 18/19)
- فقط انیمیشن توضیحی: flash روی تغییر عدد (`GI.setNumber`)، pulse روی dot در حال اجرا،
  transition ملایم hover/background
- **هیچ polling جدیدی اضافه نشده** — همه pollها (3s scan / 10s review / 4s pipeline /
  20s worker / 10s wizard) با همان action/params/nonce قبل
- اضافه‌شدن guard `document.hidden` به pollهای worker/wizard/channels (جلوگیری از AJAX flood
  در تب پنهان) — رفتار تب فعال بی‌تغییر

## ۷. دسترسی‌پذیری (Phase 21)
focus-visible واضح · ARIA روی icon buttons/aria-live پیام‌ها/aria-current تب فعال ·
touch target ≥44px (موبایل 40px) · keyboard: Tab/Escape (sheet focus trap) ·
reduced-motion · کنتراست semantic colors در هر دو تم

## ۸. ریسک‌های باقی‌مانده (runtime)
1. Vazirmatn از Google Fonts بارگذاری می‌شود (آنسای فایروال ایران ممکن است نرسد) →
   fallback stack فعال می‌شود؛ برای self-host: `define('STI_GI_SELF_HOST_FONT', true)`
   و قرار دادن `gi-fonts.css` در `admin/assets/css/`
2. ظاهر نهایی باید در host بررسی شود (۳۶/۳۹۰/۷۶۸/۱۰۲۴/۱۴۴۰px + Dark/Light)
3. ZIP نهایی پس از تأیید تست runtime در هاست ساخته می‌شود
