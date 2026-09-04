# DESIGN-10.12-WORKFLOW — هم‌راستایی Workflow با UI (گلدن اسکن) — نسخه‌ی نهایی (v2)

> دستور: «Golden Scan 10.11 — UX / Workflow Refactor Directive» + تصویب‌های معماری کاربر
> نقش‌ها: Product Architect · UX Architect · UI Designer · WP Plugin Engineer · Workflow Auditor
> اصل اول: اول تحلیل کامل → طراحی معماری → سپس پیاده‌سازی. هیچ Patch کورکورانه.
> نسخه: 10.12 (پس از 10.11.1-UX)

---

## ۰) خلاصه + تصمیم‌های تصویب‌شده

| # | تصمیم | وضعیت |
|---|---|---|
| ۱ | تب «📦 صف انتشار» = **مرکز مدیریت انتشار** با سه بخش (انتخاب دسته‌ها / افزودن محصولات / برنامه‌ی انتشار) | ✅ تصویب |
| ۲ | حذف Start از صفحه‌ی کانال‌ها (این صفحه فقط: افزودن → اسکن → آمار) | ✅ تصویب |
| ۳ | B2 — احترام به `scheduled_at` از‌پیش در `enqueue()` | ✅ تصویب کامل |
| ۴ | **زبان UI: «محصول برای انتشار»** — Session فقط مفهوم داخلی Backend | ✅ تصویب (اصلاح کلیدی) |
| ۵ | انتشار جزئی با اولویت: «دسته: موکاپ / موجود: 2200 / تعداد انتشار: 50» | ✅ تصویب |
| ۶ | پیش‌نمایش کامل قبل از افزودن (دسته/موجود/انتخاب/قیمت/برنامه) | ✅ تصویب |
| ۷ | خط تولید = فقط مانیتورینگ (۷ وضعیت) | ✅ تصویب |
| ۸ | دکمه‌ی 🔍 تشخیص: فعلاً می‌ماند؛ بعد از پایدارشدن → 🩺 سلامت سیستم | ✅ تصویب (جابه‌جایی بعدی) |

**حقیقت Backend (مستندشده در کد):**
- شمارش به‌تفکیک دسته، `scheduled_at` end-to-end، فاصله‌ی انتشار، وضعیت خط و مانیتور **از قبل وجود دارند**.
- `profile.default_category_id` = **term ID ووکامرس (`product_cat`)** (wp_dropdown_categories در profiles.php) — فیلتر B1 دقیقاً روی همین فضا کار می‌کند.
- قیمت هر محصول از **`sti_categories.price`** (DECIMAL(12,2)، «قیمت پیش‌فرض (تومان)» در صفحه‌ی دسته‌بندی‌ها) می‌آید؛ اتصال با `woo_term_id`. پیش‌نمایش قیمت = داده‌ی واقعی، نه حدس.
- اولویت = **`profile_items.score`** (INT، ستون موجود) + `id` به‌عنوان شکست‌بخش.

---

## ۱) تحلیل معماری فعلی (As-Is) — خلاصه‌ی تأییدشده

### ۱.۱ ساختار تب‌ها (۵ گروه / ۱۳ ویو)

| گروه | ویو (`gs_view`) | کاربرد فعلی |
|---|---|---|
| 📡 منابع | `channels` (default) | اسکن موازی + **Start ساخت Session** + افزودن کانال + لیست کانال‌ها |
| 📡 منابع | `insight` | «شناخت کانال» — تحلیل Inventory به‌تفکیک دسته + نگاشت WC + اطمینان |
| 📡 منابع | `profiles` | پروفایل‌ها (دسته‌ی پیش‌فرض = WC term) |
| 🏭 خط تولید | `automation` | Live Pipeline: LINE STATUS + START/STOP + KPI + pipeline + event stream |
| 🏭 خط تولید | `sessions` | لیست Session + چیپ مرحله‌ها + Fix |
| 🏭 خط تولید | `worker` | Queue/پردازش: وضعیت worker + بخش صف انتشار + سلامت + بازسازی |
| 🏭 خط تولید | `review` | Review Queue (صندوق استثناها) + اجرای Fix |
| ⚙️ اتوماسیون | `automation-settings` | تنظیمات (Worker interval / Poll) + Recovery |
| 🩺 سلامت | `system-check` / `environment` / `test-wizard` | سلامت / محیط / تست‌ها |
| 📝 گزارش‌ها | `logs` | Run Log / eventها |

### ۱.۲ مسیر ساخت Session (Backend) — دست‌نخورده

```
start_pipeline(count) → create_sessions(count)
  SELECT pi.id … WHERE status='available' AND default_category_id>0 ORDER BY pi.id ASC LIMIT room
  → per-row create_from_profile_item → سطر pipeline (SCANNED) + profile_item→queued
Auto Worker: SCANNED → DISCOVER→BOT→MATCH→DOWNLOAD→MEDIA→PRODUCT→PUBLISH
  → Publish_Queue::enqueue (queued + scheduled_at) → tick (scheduled_at<=now) → WooCommerce
```

**State machine واقعی** (برای نگاشت ۷ وضعیت مانیتور):
Stages: `DISCOVER → BOT → MATCH → DOWNLOAD → MEDIA → PRODUCT → PUBLISH` · Finals: `PUBLISHED / REVIEW / CANCELLED`
Pipeline states: `SCANNED · DOWNLOADING · MEDIA_BUILDING · PRODUCT_BUILDING · PRODUCT_READY · REVIEW_READY · PUBLISHED · REVIEW · CANCELLED`

### ۱.۳ گپ‌ها

| # | گپ | لایه |
|---|---|---|
| G1 | ساخت Session فیلتر دسته و اولویت ندارد | Backend (سیم‌کشی) |
| G2 | `enqueue()` مقدار `scheduled_at` از‌پیش را نادیده می‌گیرد | Backend (سیم‌کشی) |
| G3 | UI «افزودن دسته‌ای + زمان‌بندی + پیش‌نمایش» نیست؛ ساخت Session در جای اشتباه (channels) | UI |
| G4 | «فاصله‌ی انتشار» در UI نیست | UI |
| G5 | ترتیب کارت‌ها منطقی نیست + Stepper نیست + زبان Session‌محور است | UI |
| G6 | عناصر خارج Viewport | CSS |
| G7 | برخی دکمه‌ها بدون Loading/Success/Error کامل | UI |

---

## ۲) معماری Workflow نهایی (To-Be — تصویب‌شده)

```
📡 منابع              🔍 تحلیل محتوا          📦 صف انتشار              🕒 انتشار            🏭 خط تولید
1 افزودن کانال   →   3 شناخت کانال     →   5 انتخاب دسته‌ها   →  7 فوری / 8 زمان‌بندی →  9 مانیتورینگ
2 اسکن کانال         4 تحلیل دسته‌ها       6 تعیین تعداد محصولات                          10 بازبینی خطاها
```

### ۲.۱ قانون زبان (کلیدی)

| ❌ زبان فعلی (Session-محور) | ✅ زبان 10.12 (محصول‌محور) |
|---|---|
| «10 Session بساز» | «10 محصول از دسته‌ی موکاپ به صف انتشار اضافه کن» |
| «تعداد Session برای ساخت» | «تعداد محصول برای انتشار» |
| «Session ساخته شد» | «محصول به صف انتشار اضافه شد» |
| «0 Session created» | «0 محصول به صف اضافه شد» |

قواعد:
- در **تمام** UIهای جدید/دستکاری‌شده: واژه‌ی «Session» حذف می‌شود و «محصول (برای انتشار)» جایگزین می‌شود.
- Session فقط در لایه‌های داخلی می‌ماند: کد، AJAX contract، لاگ‌ها، debug. (اسکرین‌شات/گزارش فنی می‌تواند آیدی Session را به‌عنوان «شناسه‌ی ردیف» نشان دهد — ولی نه به‌عنوان واحد کاری.)
- ویوهای `sessions`/`review` (فنی) عنوانشان حفظ می‌شود ولی سطر تشریحی می‌گیرند: «ردیف‌های داخلی سیستم (Session) — هر ردیف = یک محصول در حال پردازش.»

### ۲.۲ تعریف‌های جدید

- **محصول برای انتشار** = یک `profile_item` واجد شرایط (available + دسته + message سالم) که به صف آمده است. در Backend همان ساختار Session است؛ کاربر فقط واحد محصول را می‌بیند.
- **انتشار جزئی با اولویت**: از 2200 موجودِ موکاپ، فقط 50 موردِ **اولویت‌دار** (score بالاتر؛ برابر score → قدیمی‌تر/ردیف کوچک‌تر id) انتخاب می‌شود.
- **برنامه‌ی انتشار**: فوری (فاصله‌ی حداقلی ۶۰ ثانیه) یا «هر X دقیقه از ساعت Y» → توالی `scheduled_at`.

### ۲.۳ Stepper (قانون UX چهارسؤالی)

مکمل بالای صفحات ۱–۹: **الان کجا هستم؟ / مرحله‌ی بعد چیست؟ / چه چیزی ساخته خواهد شد؟ / چند مورد باقی مانده؟**
داده: فقط read-only از `monitor()` + `summarize()` + `Publish_Queue::stats()` + شمارش‌های render-time — AJAX جدیدی برای Stepper **ساخته نمی‌شود**.

---

## ۳) Wireframe نهایی (Mobile-First)

### ۳.۱ 📡 کانال‌ها — فقط منابع و اسکن (مرحله ۱–۲)

```
┌────────────────────────────────┐ 320px+
│ 📡 کانال‌ها                     │
│ [۱ افزودن] [۲ اسکن] [۳ تحلیل←] │ ← Stepper
├────────────────────────────────┤
│ ➕ افزودن کانال                │ ← اولین کارت
│ [شناسه/لینک کانال        ] [+ ثبت] │
├────────────────────────────────┤
│ 📡 کانال‌های ثبت‌شده (3)         │
│ ┌────────────────────────────┐ │
│ │ کانال A        [📡 اسکن]    │ │ ← هر ردیف:
│ │ 14,500 پیام · 5,800 مورد    │ │   آمار واقعی Inventory
│ │ اسکن آخر: دیروز 14:00       │ │
│ └────────────────────────────┘ │
│ ⚡ اسکن موازی (بخش‌بندی کانال‌های بزرگ)│
│ [از … تا …] [شروع اسکن موازی]   │
├────────────────────────────────┤
│ 📦 5,806 محصول آماده در صف       │ ← CTA — دیگر فرم ساخت نیست
│ [انتخاب دسته و افزودن به صف ←]   │
│ 🔍 اگر انتشار صفر بود: تشخیص      │ ← فعلاً همین‌جا (بعد: 🩺)
└────────────────────────────────┘
```
- کارت «🏭 Start — خط تولید» **حذف** می‌شود (فرم به صف انتشار می‌رود).
- دکمه‌ی 🔍 تشخیص می‌ماند (تا پایدارشدن؛ جابه‌جایی به System Health کار بعدی است).
- اکشن اسکن هر کانال همان اکشن‌های موجود `sti_gs_channel_*` است (بدون تغییر).

### ۳.۲ 🔍 تحلیل محتوا (مرحله ۳–۴) — بازنام `insight` + CTA

```
┌────────────────────────────────┐
│  تحلیل محتوا                  │
│ [۱ کانال] [۲ اسکن] [۳•تحلیل]    │
├────────────────────────────────┤
│ [کانال: همه ▾] [🔍 شروع تحلیل]   │
├────────────────────────────────┤
│ 14,500 تحلیل‌شده · 7 دسته · 2 بدون نگاشت │
│ ┌──────────────────────────────┐│
│ │ دسته      تعداد  سهم  نگاشت  ││
│ │ Mockup   2,200   15%    ✓    ││
│ │ Font       800    6%    ✓    ││
│ │ Logo       600    4%    ✓    ││
│ │ UI Kit     400    3%    ✗    ││
│ └──────────────────────────────┘│
│ 🟡 دسته‌های پیشنهادی برای ساخت    │
├────────────────────────────────┤
│ ✅ تحلیل کامل است —               │
│ [انتخاب دسته‌های انتشار ←]        │ ← CTA به صف انتشار
└────────────────────────────────┘
```
(منطق `summarize()` دست‌نخورده — فقط عنوان + CTA + Stepper)

### ۳.۳ 📦 صف انتشار (مرحله ۵–8) — تب جدید `gs_view=publish-queue` — **مرکز مدیریت انتشار**

```
┌────────────────────────────────┐
│ 📦 صف انتشار                    │
│ [۵•انتخاب] [۶ افزودن] [۷/۸ برنامه] │
├────────────────────────────────┤
│ ۱) انتخاب دسته‌ها                 │ ← داده: GROUP BY default_category_id
│ ☑ Mockup   موجود 2,200  قیمت 25,000 │    + sti_categories.price
│ ☑ Logo     موجود   600  قیمت 10,000 │    (read-only, render-time)
│ ☐ Font     موجود   800  قیمت 15,000 │
│ ☐ UI Kit   موجود   400  قیمت 12,000 │
│ 💾 ذخیره انتخاب                   │
├────────────────────────────────┤
│ ۲) افزودن محصول به صف             │
│ Mockup:  موجود 2,200 | تعداد [50] │ ← انتشار جزئی
│ Logo:    موجود   600 | تعداد [20] │
│ ┌── پیش‌نمایش ──────────────────┐ │
│ │ 70 محصول از 2 دسته             │ │
│ │ Mockup 50 × 25,000 | Logo 20 × 10,000 │
│ │ انتخاب: 50+20 اولویت‌دار (score) │ │
│ │ انتشار: هر 30 دقیقه از 09:00    │ │
│ │ 09:00 · 09:30 · 10:00 · … (70 ردیف)│ │
│ └───────────────────────────────┘ │
│ حالت: ○ فوری  ● زمان‌بندی‌شده      │
│ فاصله: [30] دقیقه                 │ ← B4
│ شروع از: [2026-09-05 09:00] (اختیاری)│
│ [📦 افزودن به صف انتشار]           │ → B3
│ ✅ 70 محصول به صف اضافه شد —         │
│    اولین انتشار 09:00              │
├────────────────────────────────┤
│ ۳) برنامه‌ی انتشار (فقط‌خواندنی)     │ ← pipeline + queue stats
│ در صف: 12 · زمان‌بندی‌شده: 70 · بعدی: 09:30 │
│ ┌──────────────────────────────┐│
│ │ 09:00 Mockup #…  منتظر انتشار ││ ← 10 ردیف بعدی
│ │ 09:30 Mockup #…  منتظر انتشار ││
│ └──────────────────────────────┘│
│ ⚙️ جزئیات پردازش ← Queue/پردازش    │ ← deep-link worker
└────────────────────────────────┘
```

### ۳.۴ 🏭 خط تولید (مرحله ۹–۱۰) — مانیتور خالص

- LINE STATUS + ▶ START LINE / ■ STOP LINE (کنترل خط باقی می‌ماند — ساخت محصول در صف انتشار است).
- در STOPPED: CTA «محصول تازه در 📦 صف انتشار اضافه می‌شود».
- **نمای وضعیت با ۷ برچسب تصویب‌شده ↔ داده‌ی واقعی:**

| برچسب UI | داده‌ی واقعی (state) |
|---|---|
| منتظر پردازش | `SCANNED` + stage PENDING غیر-نهایی |
| در حال دانلود | `DOWNLOADING` (RUNNING) |
| در حال ساخت محصول | `MEDIA_BUILDING` / `PRODUCT_BUILDING` (RUNNING) |
| در صف انتشار | `PRODUCT_READY` / `REVIEW_READY` (PUBLISH) / `queue_status='queued'` |
| منتشر شده | `PUBLISHED` |
| نیازمند بازبینی | `REVIEW` (+ link به Review) |
| خطا | شکست stage / line `ERROR` (با reason از eventها) |

(همان monitor payload موجود — فقط برچسب‌بندی و گروه‌بندی مجدد)

### ۳.۵ صفحات دست‌نخورده

`profiles` · `sessions` (با سطر تشریحی «ردیف = یک محصول در پردازش») · `worker` · `review` ·
`automation-settings` · `system-check` · `environment` · `test-wizard` · `logs`
— فقط: Stepper + دکمه‌های Audit-state + CSS fixes.

---

## ۴) Mapping کامل (قدیم → جدید)

| قبلی | بعد | یادداشت |
|---|---|---|
| کارت «🏭 Start — خط تولید» (channels) | **حذف** → بخش ۲ صف انتشار | منطق به B3 می‌رود؛ اکشن قدیمی `sti_gs_pipeline_start` برای سازگاری می‌ماند |
| دکمه‌ی 🔍 تشخیص (10.11.1) | موقتاً همان‌جا؛ **بعد**: 🩺 System Health | کار بعد از پایدارشدن |
| «شناخت کانال» (`insight`) | **«تحلیل محتوا»** (همان gs_view) | فقط عنوان + CTA + Stepper |
| کارت «⚡ اسکن موازی» (بالای channels) | پایین channels (بعد از لیست) | منطق دست‌نخورده |
| `automation` (Live Pipeline) | همان — **مانیتور خالص** + ۷ برچسب | CTA توقف: «محصول جدید از صف انتشار» |
| تب جدید `publish-queue` | **📦 صف انتشار** — اولین ویو گروه «خط تولید» | gs_view جدید، capability/nonce همان |
| بخش «صف انتشار — قلب موفقیت» در `worker` | می‌ماند (جزئیات پردازش) | صف انتشار جدید = انتخاب+ساخت+برنامه؛ worker = پردازش/سلامت |
| زبان «Session» در UIهای دستکاری‌شده | «محصول (برای انتشار)» | Backend/لاگ/AJAX: بدون تغییر |
| 58 اکشن + nonce + capability + deep-linkها | دست‌نخورده | +۱ gs_view، +۱ اکشن (B3) |

---

## ۵) سیم‌کشی‌های Backend (مینیمال)

### B1 — فیلتر دسته + اولویت در ساخت (گپ G1) ✅
`create_sessions( $count, $wc_term_id = null, $priority_order = false )` و عبور پارامتر در `start_pipeline()`:
- `$wc_term_id` ≠ null → `AND p.default_category_id = %d` (فضای ID = term ووکامرس — تأییدشده).
- `$priority_order` → `ORDER BY pi.score DESC, pi.id ASC` (انتخاب ۵۰ اولویت‌دار). در غیراینصورت `ORDER BY pi.id ASC` **دقیقاً همان قبل**.
- هر پارامتر null/false = رفتار فعلی کلمه‌به‌کلمه (مسیر اکشن قدیمی و cron دست‌نخورده).
- پاسخ `start_pipeline` کلید `ids` (آیدی‌های ساخته‌شده) را هم برمی‌گرداند (افزودنی — JS فعلی فقط created/ready/worker_on می‌خواند).

### B2 — احترام به `scheduled_at` از‌پیش (گپ G2) ✅ تصویب‌شده
```php
$existing = ! empty( $session['scheduled_at'] ) ? strtotime( $session['scheduled_at'] ) : 0;
$next     = max( time() + 60, $last + $interval, $existing );
```
تک‌مقداری؛ اگر scheduled_at خالی/گذشته باشد → رفتار دقیقاً همان قبل.

### B3 — اکشن `sti_gs_publish_queue_create` (گپ G3) ✅
پارامترها: `items[]` = `[{"wc_term_id": 12, "count": 50}, …]` (count: 1..1000 هرکدام؛ مجموع ≤ 1000)،
`mode` = `immediate|interval`، `interval_minutes` (1..1440)، `start_at` (اختیاری `Y-m-d H:i`).
فرایند: برای هر آیتم (به‌ترتیب): `start_pipeline(count, wc_term_id, priority_order=true)` →
در حالت interval: `set_interval_minutes(x)` + تخصیص `scheduled_at = start + i*interval` به Sessionهای جدید
(از طریق `STI_GS_Session::update` — whitelist شامل scheduled_at است ✓).
پاسخ: `{created_total, per_category:[{wc_term_id, created, first_scheduled_at}], schedule_preview:[…10…], worker_on}`.
Nonce/ability: همان `sti_admin_nonce` + `manage_woocommerce` (check_ajax موجود).

### B4 — option انتخاب + فاصله (گپ G4)
- `sti_gs_publish_categories` (آرایه wc_term_id) — «ذخیره انتخاب».
- فاصله‌ی انتشار در فرم صف انتشار (همان option موجود `Publish_Queue` — فقط در UI می‌آید؛ setter موجود `set_interval_minutes`).

**حجم Backend: ~۸۰ سطر + تست‌های استاتیک. بدون Schema، بدون جدول جدید، بدون حذف داده، بدون تغییر 58 اکشن.**

---

## ۶) رفع شناور شدن (G6) — همان Spec v1 + تکمیل
- Audit همه‌ی `position:absolute/fixed`، `transform`، `scale/translate`، `overflow`، `max-width`، flex sizing در `gi-console.css` + inline style هر ویو.
- ```css
  .gi-console{max-width:100%;overflow-x:clip}
  .gi-console *,.gi-console *::before,.gi-console *::after{box-sizing:border-box}
  .gi-console [class*="flex"]>*{min-width:0}
  .gi-console pre,.gi-console code,.gi-console img,.gi-console svg{max-width:100%}
  .gi-console table{max-width:100%}
  ```
- عنصر fixed مجاز فقط bottom-nav موبایل (+ safe-area + padding محتوا).
- ویزوای flow: در ≤390px اسکرول افقی داخلی (بدون scale/transform).
- **تست عرض‌ها: 320 / 360 / 390 / 412 / 768 + دسکتاپ 1280 / 1440 — بدون Zoom Out.**

## ۷) Button Audit (G7)
فهرست ۷ستونه (`Action | AJAX | Nonce | Callback | Success | Error | Loading`) برای **همه** دکمه‌های ۱۴ ویو.
قواعد: Loading = disabled + متن در حال…؛ Success = پیام ok واقعی از پاسخ؛ Error = دلیل واقعی؛ **هیچ دکمه‌ی کور** — خروجی: جدول Audit در گزارش نهایی.

## ۸) Data Safety
- **هیچ DELETE** (محصول/Session/پیام/Pipeline) در کد جدید.
- B1 پیش‌فرض = رفتار فعلی؛ B2 monotonic (هرگز جلو نمی‌کشد، فقط نادیده‌نگیری را رفع می‌کند).
- انتشار جزئی = فقط تغییر وضعیت انتخاب‌شده‌ها (`available→queued`)؛ بقیه‌ی موجودات دست‌نخورده.
- 58 اکشن + nonce + capability + deep-linkها + Schema دست‌نخورده.

## ۹) تست و پذیرش
| لایه | تست |
|---|---|
| استاتیک | Balance PHP/JS + selector-audit (JS↔markup) |
| رگرسیون | 10.11 suite 45/45 + P0 6/6 سبز (مسیر پیش‌فرض B1 تغییر نکند) |
| استاتیک جدید | B1 (با/بدون فیلتر + ترتیب اولویت)، B2 (max سه‌تایی)، B3 (contract) |
| موبایل | 320/360/390/412/768 — هیچ عنصری خارج Viewport |
| دسکتاپ | 1280/1440 + RTL |
| AJAX | جدول Audit + اکشن B3 (immediate و interval) |
| هاست | walkthrough ۱۰مرحله‌ای + انتشار جزئی (50 از 2200) + زمان‌بندی 09:00/09:30 + تست 15 مرحله‌ی 10.11-UX |

## ۱۰) فازبندی (پس از تأیید این سند)
| فاز | محتوا | خروجی |
|---|---|---|
| P2 | Backend B1–B4 + تست‌های استاتیک | commit |
| P3 | تب 📦 صف انتشار (3 بخش + پیش‌نمایش + برنامه) + بازچینش channels + بازنام تحلیل محتوا + CTAها + **جایگزینی زبان Session→محصول** | commit |
| P4 | Stepper + Button Audit states + CSS fixes + ۷ برچسب خط تولید | commit |
| P5 | QA کامل → **نسخه 10.12 + ZIP + push GitHub** (بر اساس قانون جدید، بدون سؤال تکراری) | release |

---

### وضعیت تصمیم‌های باز: **صفر** — تمام تصمیم‌ها تصویب شدند. آماده‌ی P2.
