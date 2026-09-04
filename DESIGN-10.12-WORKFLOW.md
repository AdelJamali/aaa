# DESIGN-10.12-WORKFLOW — هم‌راستایی Workflow با UI (گلدن اسکن)

> دستور: «Golden Scan 10.11 — UX / Workflow Refactor Directive»
> نقش‌ها: Product Architect · UX Architect · UI Designer · WP Plugin Engineer · Workflow Auditor
> اصل اول: اول تحلیل کامل → طراحی معماری → سپس پیاده‌سازی. هیچ Patch کورکورانه.
> نسخه: 10.12 (پس از 10.11.1-UX)

---

## ۰) خلاصه اجرایی

**مشکل اصلی (تأییدشده در کد):** صفحه «کانال‌ها» این ترتیب را دارد:

```
⚡ اسکن موازی  →  🏭 Start — خط تولید (ساخت Session!)  →  ➕ افزودن کانال  →  📡 لیست کانال‌ها
```

یعنی سیستم از کاربر می‌خواهد **Session بسازد** — در حالی که هنوز کانال اسکن نشده،
محتوا تحلیل نشده و دسته‌بندی انتخاب نشده. دقیقاً همان نقض منطقی که گزارش شده.

**حقیقت خوب:** Backend این Workflow را **تقریباً کامل** پشتیبانی می‌کند:

- شمارش به‌تفکیک دسته (Mockup/Logo/Font/…) ← `STI_GS_Channel_Insight::summarize()` (ویو فعلی «شناخت کانال»)
- انتشار زمان‌بندی‌شده ← ستون `scheduled_at` + ایندکس `queue_schedule` + `Publish_Queue::tick()` که `scheduled_at <= now` را رعایت می‌کند + `repair_missing_schedule`
- فاصله‌ی انتشار ← option فاصله (۶۰ ثانیه تا ۲۴ ساعت) — فقط در UI تعریف نشده
- وضعیت خط / مانیتور / START-STOP واقعی ← `STI_GS_Line` + Live Pipeline

**پس ۱۰.12 یک Refactor با ۴ سیم‌کشی Backendِ مینیمال + باقی presentation است.**

---

## ۱) تحلیل معماری فعلی (As-Is)

### ۱.۱ ساختار تب‌ها (۵ گروه / ۱۳ ویو)

| گروه | ویو (`gs_view`) | کاربرد فعلی | AJAX اصلی |
|---|---|---|---|
| 📡 منابع | `channels` (default) | اسکن موازی + **Start ساخت Session** + افزودن کانال + لیست کانال‌ها | `sti_gs_pipeline_start`, `sti_gs_channel_*`, `sti_gs_start_diagnostic` |
| 📡 منابع | `insight` | «شناخت کانال» — تحلیل Inventory به‌تفکیک دسته + پیشنهاد دسته‌های بدون نگاشت | `sti_gs_insight_*` |
| 📡 منابع | `profiles` | پروفایل‌ها (دسته‌ی پیش‌فرض هر پروفایل) | `sti_gs_profile_*` |
| 🏭 خط تولید | `automation` | Live Pipeline: LINE STATUS + START/STOP + KPI + pipeline + event stream | `sti_gs_line_start/stop`, `sti_gs_line_monitor` |
| 🏭 خط تولید | `sessions` | لیست Session + چیپ مرحله‌ها + Fix | `sti_gs_session_*` |
| 🏭 خط تولید | `worker` | Queue/پردازش: وضعیت worker + **بخش صف انتشار** + سلامت + بازسازی | `sti_gs_worker_*` |
| 🏭 خط تولید | `review` | Review Queue (صندوق استثناها) + اجرای Fix | `sti_gs_review_fix` |
| ⚙️ اتوماسیون | `automation-settings` | تنظیمات (Worker interval / Poll) + Recovery | `sti_gs_automation_save` |
| 🩺 سلامت | `system-check` | System Health | — |
| 🩺 سلامت | `environment` | Environment Health (WP-Cron/Real-Cron/DB) | — |
| 🩺 سلامت | `test-wizard` | Tests (wizard) | `sti_gs_wizard_*` |
| 📝 گزارش‌ها | `logs` | Run Log / eventها | — |

### ۱.۲ مسیر فعلی ساخت Session (Backend)

```
[channels] دکمه Start (count)
  → wp_ajax_sti_gs_pipeline_start
  → STI_GS_Channel_Watcher::start_pipeline(count)
  → create_sessions(count):
      SELECT pi.id FROM {profile_items} pi
      INNER JOIN {profiles} p ON p.id=pi.profile_id
      WHERE pi.status='available' AND p.default_category_id>0
      ORDER BY pi.id ASC LIMIT room
      → برای هر ردیف: STI_GS_Session::create_from_profile_item(pi.id)
      → سطر pipeline (state=SCANNED) + profile_item→queued
  → روشن‌سازی Auto Worker + cron single-event
Auto Worker tick (هر interval):
  SCANNED → [زنجیره: download → media → product → …]
  → به‌سراِ انتشار: STI_GS_Publish_Queue::enqueue(session_id)
      queue_status='queued', scheduled_at = max(now+60, last+interval)
  Publish_Queue::tick(): فقط آیتم‌های scheduled_at <= now → publish به WooCommerce
```

### ۱.۳ موجودی Backend (نه بسازیم؛ استفاده کنیم)

| قابلیت | وضعیت | مرجع |
|---|---|---|
| شمارش به‌تفکیک دسته از Inventory | ✅ موجود | `STI_GS_Channel_Insight::summarize()` — ویو insight |
| نگاشت دسته ↔ WooCommerce + اطمینان | ✅ موجود | همان summarize (ستون نگاشت/اطمینان) |
| `scheduled_at` روی سطر pipeline + ایندکس | ✅ موجود | class-gs-db.php L895-896 |
| tick انتشار رعایت `scheduled_at` | ✅ موجود | class-gs-publish-queue.php tick() |
| فاصله‌ی انتشار (option, ۶۰s–24h) | ⚠️ موجود ولی **در UI نیست** | `Publish_Queue::interval_seconds()/set_interval_minutes()` |
| whitelist `update()` شامل `scheduled_at` | ✅ موجود (۱۰.۱۱ فیکس شد) | class-gs-session.php L122 |
| وضعیت خط + START/STOP واقعی + مانیتور | ✅ موجود | STI_GS_Line + automation.php |
| تشخیص ۵۸۰۶→۰ (فقط‌خواندنی) | ✅ موجود (10.11.1) | `diagnose_start()` |
| شمارش Inventory هر کانال | ✅ موجود | `progressText` در channels (messages_saved/…) |

### ۱.۴ گپ‌ها (Gap Analysis)

| # | گپ | لایه |
|---|---|---|
| G1 | ساخت Session **فیلتر دسته ندارد** — `create_sessions/count` بدون category | Backend (سیم‌کشی) |
| G2 | `enqueue()` مقدار `scheduled_at` از نو می‌سازد؛ **زمان‌بندی از‌قبلِ کاربر (09:00/09:30) نادیده گرفته می‌شود** | Backend (سیم‌کشی) |
| G3 | UI «ساخت دسته‌ای + زمان‌بندی» وجود ندارد؛ ساخت Session در صفحه کانال‌هاست (جای اشتباه) | UI |
| G4 | «فاصله‌ی انتشار» در تنظیمات UI نیست | UI |
| G5 | ترتیب کارت‌های channels منطقی نیست (Start قبل از Scan/تحلیل) + هیچ Stepper «الان کجا هستم» نیست | UI |
| G6 | برخی عناصر خارج از Viewport رندر می‌شوند (Zoom Out لازم است) | CSS |
| G7 | برخی دکمه‌ها حالت Loading/Success/Error کامل ندارند (Audit لازم است) | UI |

**هیچ گپی نیاز به تغییر Schema/جدول جدید/حذف داده ندارد.**

---

## ۲) معماری Workflow جدید (To-Be)

```
 ۱ کانال        ۲ اسکن        ۳ تحلیل       ۴ انتخاب دسته    ۵ صف انتشار      ۶ انتشار          ۷ خط تولید        ۸ منتشر شد
 ➕ افزودن   →  📡 اسکن   →  🔍 تحلیل  →   ✓ Mockup     →  📦 50 Session  →  فوری / هر30د  →  🏭 مانیتور     →  ✅ WooCommerce
    (channels)     (channels)     (insight)      (publish-queue)   (publish-queue)      (publish-queue)     (automation)
```

| مرحله | صاحب (تب) | ورودی | خروجی / آنچه ساخته می‌شود |
|---|---|---|---|
| ۱ افزودن کانال | کانال‌ها — کارت «افزودن کانال» **اول صفحه** | یوزرنیم/لینک | سطر channels |
| ۲ اسکن | کانال‌ها — اکشن اسکن هر کانال + کارت اسکن موازی | کانال | «N پیام اسکن شد / M مورد قابل پردازش» (آمار موجود) |
| ۳ تحلیل محتوا | **تحلیل محتوا** (بازنام «شناخت کانال») | Inventory | جدول به‌تفکیک دسته + نگاشت WC + اطمینان (همین summarize) |
| ۴ انتخاب دسته | صف انتشار — مرحله‌ی ۱ | نتایج تحلیل | چک‌باکس‌ها ← option `sti_gs_publish_categories` |
| ۵ افزودن به صف | صف انتشار — مرحله‌ی ۲ | دسته‌ها + تعداد + حالت | **N Session ساخته می‌شود** (pipeline rows, SCANNED) |
| ۶ فوری/زمان‌بندی | همان فرم مرحله‌ی ۲ | فوری / هر X دقیقه (+ زمان شروع اختیاری) | `scheduled_at` توالی 09:00/09:30/… یا interval حداقلی |
| ۷ خط تولید | Live Pipeline | — | **فقط مانیتور + START/STOP خط** — دیگر جایی برای ساخت Session نیست |
| ۸ انتشار | Publish Queue (WooCommerce) | worker + tick | محصولات منتشرشده + Review در صورت استثنا |

### Stepper (قانون UX)
مکمل مشترک بالای هر صفحه‌ی ۱ تا ۷ — چهار سؤال:

1. **الان کجا هستم؟** ← مرحله‌ی فعال هایلایت
2. **مرحله بعد چیست؟** ← CTA به تب بعد («برو به تحلیل محتوا →»)
3. **چه چیزی ساخته خواهد شد؟** ← مثلاً «۵۰ Session در صف انتشار»
4. **چه تعداد باقی مانده؟** ← از داده‌های موجود: available items / queued / scheduled / published (بدون کوئری جدید — همان payloadهای monitor/summarize/stats)

داده‌ی Stepper: فقط read-only از `monitor()` + `summarize()` + `Publish_Queue::stats()` + `Watcher::stats()` — AJAX جدیدی برای Stepper نمی‌سازد.

---

## ۳) Wireframes (Mobile-First)

### ۳.۱ کانال‌ها (مرحله ۱–۲) — بازچینش کامل

```
┌──────────────────────────────┐ 320px+
│ 📡 کانال‌ها                   │
│ [1 افزودن] [2 اسکن] [3→تحلیل]│ ← Stepper
├──────────────────────────────┤
│ ➕ افزودن کانال              │ ← حالا اولین کارت
│ [شناسه‌ی کانال        ] [+ثبت]│
├──────────────────────────────┤
│ 📡 کانال‌های ثبت‌شده (n)      │
│ ┌──────────────────────────┐ │
│ │ کانال A      [اسکن] [⚙]  │ │ ← هر ردیف:
│ │ 14,500 پیام · 5,800 مورد  │ │   آمار Inventory واقعی
│ └──────────────────────────┘ │
│ ⚡ اسکن موازی (بخش‌بندی)      │ ← کارت اسکن موازی (پایین‌تر)
│ [از … تا …] [شروع اسکن موازی]│
├──────────────────────────────┤
│ 📦 ۵,۸۰۶ مورد آماده در صف —  │
│ «برو به صف انتشار» ← CTA     │ ← کارت Start حذف شد؛
│    🔍 اگر صفر ساخت: تشخیص    │    فقط CTA + دکمه‌ی تشخیص
└──────────────────────────────┘
```

### ۳.۲ تحلیل محتوا (مرحله ۳) — بازنام insight + CTA

```
┌──────────────────────────────┐
│  تحلیل محتوا               │
│ [1 کانال] [2 اسکن] [3•تحلیل] │
├──────────────────────────────┤
│ [کانال: همه ▾] [ شروع تحلیل]│
──────────────────────────────┤
│ KPI: 14,500 تحلیل‌شده · 7 دسته│
│ ├──────────────────────────┤ │
│ │ دسته        تعداد  سهم  نگاشت│ │
│ │ Mockup     2,200   15%  ✓    │ │
│ │ Font         800    6%  ✓    │ │
│ │ Logo         600    4%  ✓    │ │
│ │ UI Kit       400    3%  ✗    │ │
│ └──────────────────────────┘ │
│ 🟡 دسته‌های پیشنهادی برای ساخت│
├──────────────────────────────┤
│ ✅ تحلیل تمام است →           │
│ [انتخاب دسته‌های انتشار ←]    │ ← CTA به صف انتشار
└──────────────────────────────┘
```

### ۳.۳  صف انتشار (مرحله ۴–۶) — تب جدید (`gs_view=publish-queue`)

```
┌──────────────────────────────┐
│ 📦 صف انتشار                 │
│ [… 4 انتخاب] [5•افزودن] [6]  │
├──────────────────────────────┤
│ ۱) دسته‌های انتشار            │
│ ☑ Mockup (2,200)  ☑ Logo (600)│ ← از نتایج تحلیل
│ ☐ Font (800)     ☐ UI Kit (400)│
│ ☐ Flyer (350)                    │
│ 💾 ذخیره انتخاب (option)       │
├──────────────────────────────┤
│ ۲) ساخت Session در صف          │
│ دسته: [انتخاب‌شده‌ها]           │
│ تعداد: [50]                    │
│ حالت: ○ فوری  ● زمان‌بندی‌شده  │
│ فاصله: [30] دقیقه             │ ← B4: در UI می‌آید
│ شروع از: [2026-09-05 09:00] 🕘 │ ← اختیاری
│ پیش‌نمایش: 09:00, 09:30, 10:00…│ ← محاسبه‌ی سمت UI
│ [📦 افزودن به صف انتشار]      │ → B3: N Session ساخته شد
│ ✅ 50 Session ساخته شد —        │
│    اولین انتشار 09:00          │
├──────────────────────────────┤
│ ۳) وضعیت صف (فقط‌خواندنی)      │ ← Publish_Queue::stats()
│ در صف: 12 · زمان‌بندی‌شده: 50   │
│ بعدی: 09:30 · امروز: 3/50     │
│ [لیست 10 بعدی زمان‌بندی‌شده]    │
│ ⚙️ جزئیات پردازش ← Queue/پردازش │ ← deep-link به worker
└──────────────────────────────┘
```

### ۳.۴ خط تولید (مرحله ۷) — مانیتور خالص

- بدون هیچ فرم ساخت Session (الان هم ندارد؛ Start کانال‌ها جابه‌جا شد).
- LINE STATUS + ▶ START LINE / ■ STOP LINE (کنترل خط — باقی می‌ماند).
- در حالت STOPPED یک CTA: «Session تازه در 📦 صف انتشار ساخته می‌شود».

---

## ۴) جدول Mapping (قدیم → جدید)

| قبلی | بعد | یادداشت |
|---|---|---|
| کارت «🏭 Start — خط تولید» در channels | **حذف از channels** → فرم مرحله‌ی ۲ تب «صف انتشار» | همان endpoint-منطق؛ با فیلتر دسته + زمان‌بندی (G1/G3) |
| دکمه «🔍 تشخیص» (10.11.1) | می‌ماند — در همان CTA کارت آماده‌ها در channels | فقط‌خواندنی، تغییر نمی‌کند |
| «شناخت کانال» (insight) | **«تحلیل محتوا»** (همان gs_view=insight) | فقط عنوان + CTA؛ منطق summarize دست‌نخورده |
| کارت «⚡ اسکن موازی» (بالای channels) | پایین channels (بعد از لیست) | منطقی: اول کانال، بعد اسکن |
| تب جدید `publish-queue` | **📦 صف انتشار** در گروه «🏭 خط تولید» (اولین ویو گروه) | gs_view جدید — deep-link امن (capability همان) |
| worker.php «صف انتشار — قلب موفقیت» | می‌ماند (جزئیات پردازش) | صف انتشار جدید = ساخت+زمان‌بندی؛ worker = پردازش/سلامت — بدون تداخل |
| START/STOP در Live Pipeline | می‌ماند | کنترل خط ≠ ساخت Session |
| همه‌ی ۱۳ ویو و ۵۸ اکشن | دست‌نخورده | +۱ gs_view، +۱ اکشن (B3) |

---

## ۵) سیم‌کشی‌های Backend (مینیمال — هرکدام با دلیل Workflow)

### B1 — فیلتر دسته در ساخت Session (گپ G1)
`create_sessions( $count, $cat_ids = null )` و `start_pipeline( $count, $cat_ids = null )`:
وقتی `$cat_ids` (مассив idهای دسته‌ی WooCommerce) خالی نبود، به WHERE اضافه می‌شود:
`AND p.default_category_id IN (…)` (prepare با %d). **`null` = رفتار فعلی کلمه‌به‌کلمه** — هیچ مسیر موجودی تغییر نمی‌کند.

### B2 — احترام به `scheduled_at` از‌پیش (گپ G2)
در `Publish_Queue::enqueue()`:
```php
$existing = ! empty( $session['scheduled_at'] ) ? strtotime( $session['scheduled_at'] ) : 0;
$next     = max( time() + 60, $last + $interval, $existing );
```
تک‌مقداری و بی‌خطر: اگر scheduled_at خالی/گذشته باشد → رفتار دقیقاً همان قبل.
کاربر زمان 09:00 تعیین کرده باشد → صف آن را جلو نمی‌کشد (درخواست مرحله ۶ دستور).

### B3 — اکشن جدید `sti_gs_publish_queue_create` (گپ G3)
پارامترها: `count` (1..1000)، `categories[]` (idها، اختیاری)، `mode` = `immediate|interval`،
`interval_minutes` (1..1440)، `start_at` (اختیاری `Y-m-d H:i`).
فرایند: `start_pipeline(count, cats)` → در حالت interval: `set_interval_minutes(x)` +
برای هر Session جدید `scheduled_at = start + i*interval` از طریق `STI_GS_Session::update`
(whitelist شامل scheduled_at است ✓).
برای پاسخ، `start_pipeline/create_sessions` کلید `ids` (آیدی‌های ساخته‌شده) را هم برمی‌گرداند
(افزودنی — JS فعلی channels فقط created/ready/worker_on می‌خواند؛ خراب نمی‌شود).
Nonce/ability: همان `sti_admin_nonce` + `manage_woocommerce` (check_ajax موجود).

### B4 — option انتخاب‌شده‌ها (گپ G4)
`sti_gs_publish_categories` (آرایه id) — ذخیره با دکمه‌ی «ذخیره انتخاب»؛
مصرف: پیش‌فرضِ فرم B3 + نمایش Stepper. (تبدیل: اگر در فرم B3 دسته‌ای پاس شد، همان حاکم است.)

**حجم کل Backend: ~۶۰ سطر + تست‌های استاتیک. بدون Schema، بدون داده جدید، بدون تغییر 58 اکشن موجود.**

---

## ۶) رفع مشکل شناور شدن (گپ G6)

**Audit (فهرست کامل در فاز پیاده‌سازی):** همه‌ی `position:absolute/fixed`، `transform`،
`scale`، `translate`، `overflow`، `max-width`، flex sizing در `gi-console.css` + inline style هر ۱۳ ویو.

**قوانین اجرایی:**
```css
.gi-console            { max-width: 100%; overflow-x: clip; }
.gi-console *, .gi-console *::before/after { box-sizing: border-box; }  /* اگر گلوبال نیست */
.gi-console [class*="flex"] > * { min-width: 0; }
.gi-console pre, .gi-console code, .gi-console img, .gi-console svg { max-width: 100%; }
.gi-console table       { max-width: 100%; }  /* + .gi-table-wrap overflow-x:auto موجود است */
```
- عنصر fixed مجاز فقط: bottom-nav موبایل — با `padding-bottom: calc(nav + safe-area)` روی محتوا.
- ویزوای flow ۷مرحله‌ای: در ≤390px به اسکرول افقی داخلی تبدیل می‌شود (transform/scale حذف).
- **تست عرض‌ها: 320 / 360 / 390 / 412 / 768 + دسکتاپ 1280 / 1440** — بدون هیچ‌گونه Zoom Out.

## ۷) Button Audit (گپ G7)

فهرست هر دکمه در ۱۳+۱ ویو با ۷ ستون: `Action | AJAX | Nonce | Callback | Success | Error | Loading`.
قوانین:
- هر دکمه AJAX: قبل از POST `disabled` + متن Loading؛ در `.done` پیام ok + در `.fail` دلیل واقعی.
- هیچ دکمه‌ای بدون callback (کور) نماند؛ در صورت کشف دکمه‌ی مرده → یا اتصال به اکشن موجود یا حذف (با گزارش).
- خروجی: جدول Audit در گزارش نهایی (۹).

## ۸) Data Safety (قانون)

- **هیچ DELETE** (Session/Candidate/Pipeline/پیام) — حتی در کدهای جدید.
- B1 بدون پارامتر = رفتار فعلی؛ B2 monotonic (هرگز زمان را جلو نمی‌کشد).
- 58 اکشن + nonce + capability + deep-linkهای `gs_view` دست‌نخورده.
- Schema بدون تغییر (scheduled_at از قبل وجود دارد).

## ۹) تست و پذیرش

| لایه | تست |
|---|---|
| استاتیک | Balance PHP/JS همه‌ی فایل‌های تغییرکرده + selector-audit (JS↔markup) |
| رگرسیون | 10.11 suite 45/45 + P0 6/6 سبز می‌مانند (مسیر پیش‌فرض B1 تغییر نکند) |
| استاتیک جدید | B1 (SQL با IN وقتی cats پاس شد / بدون IN در غیراینصورت)، B2 (max سه‌تایی)، B3 (contract پارامترها) |
| موبایل | 320/360/390/412/768 — هیچ عنصری خارج Viewport |
| دسکتاپ | 1280/1440 + RTL |
| AJAX | هر دکمه‌ی ۷ ستونه جدول Audit + اکشن جدید B3 |
| هاست | walkthrough ۸مرحله‌ای Workflow + تست ۱۵ مرحله‌ی 10.11-UX |

## ۱۰) فازبندی

| فاز | محتوا | خروجی |
|---|---|---|
| P1 | این سند (تحلیل + نقشه + wireframe + mapping) | ✅ این فایل |
| P2 | Backend B1–B4 + تست‌های استاتیک | commit |
| P3 | تب 📦 صف انتشار + بازچینش channels + بازنام تحلیل محتوا + CTAها | commit |
| P4 | Stepper + Button Audit states + CSS fixes (G6) | commit |
| P5 | QA کامل (تست‌ها + عرض‌ها) → **نسخه 10.12 + ZIP + push GitHub** (بر اساس قانون جدید، بدون سؤال تکراری) | release |

---

### تصمیم‌های نیازمند تأیید کاربر (فقط این سه)

1. **نام تب جدید:** «📦 صف انتشار» با `gs_view=publish-queue` در گروه «خط تولید» (پیشنهاد) — یا گروه مستقل؟
2. **B2:** احترام به scheduled_at از‌پیش در `enqueue()` — تأیید می‌شود؟ (سهمه‌ی Workflow؛ رفتار فعلی در حالت عادی دست‌نخورده می‌ماند.)
3. **حذف کارت Start از channels:** دکمه‌ی تشخیص (10.11.1) در همان‌جا می‌ماند و فقط فرم ساخت Session جابه‌جا می‌شود — تأیید می‌شود؟
