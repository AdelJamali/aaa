# پروتکل تست Runtime — تست ۱: Happy Path با Session کاملاً جدید

> هدف این تست فقط اثبات **مسیر سالم اولیه** است. هیچ تست retry / recovery /
> 420474760 همزمان وارد نشود. آن‌ها تست‌های بعدی‌اند.

## مسیر هدف

```
SCANNED
 → CHAIN INIT
 → DEEP_LINK
 → startBot
 → CHAIN_WAITING
 → Bot Poll
 → Bot Response (گام بعدی یا ASSET)
 → ... → REVIEW_READY → Publish Queue
```

## پیش‌نیازها

- [ ] کد اصلاح‌شده (شامل ۱۰.۸.۲/۱۰.۸.۳) روی هاست نصب شده باشد — ⚠️ ZIP فعلی شما (10.8.1) فاقد این اصلاحات است
- [ ] اتصال تلگرام (MadelineProto) سالم باشد
- [ ] یک پیام کانال که **دکمه‌ی deep link واقعی** دارد (مثل `t.me/SomeBot?start=XXX`) آماده باشد
- [ ] Worker می‌تواند خاموش باشد — این تست با دکمه‌ی دستی انجام می‌شود

---

## مرحله ۰ — آماده‌سازی

1. صفحه‌ی **Golden Scan ← پردازش خودکار** → حالت پردازش را روی **`chain`** بگذارید و **ذخیره حالت** بزنید.
   - لاگ باید ثبت کند: «گلدن اسکن: حالت معماری زنجیره به chain تغییر کرد.»
2. از صفحه‌ی **پروفایل‌ها**، روی نمونه‌ای که پیامش deep link واقعی دارد، دکمه‌ی **«+ صف»** را بزنید → یک Session جدید ساخته می‌شود.
3. **تأیید ساخت** (SQL روی دیتابیس هاست):

```sql
SELECT id, state, chain_mode, chain_current_step, message_pk, file_code, created_at
FROM wp_sti_gs_pipeline_items
ORDER BY id DESC LIMIT 3;
```

| انتظار | مقدار |
|---|---|
| `state` | `SCANNED` |
| `chain_mode` | `chain` ← (چون Session تازه است، از تنظیم سراسری گرفته شده) |

> اگر `chain_mode` خالی/NULL بود، یعنی کد قدیمی روی هاست است — متوقف شوید.

---

## مرحله ۱ — Chain Init

- روی همان Session در صفحه‌ی **Session ها**، دکمه‌ی **«▶ ادامه پردازش»** را بزنید.
- **انتظار:** `state → CHAIN_STEP` و `chain_current_step = 1`

```sql
SELECT id, state, chain_mode, chain_current_step, stage, error_reason
FROM wp_sti_gs_pipeline_items WHERE id = <ID>;

SELECT step_no, node_type, node_kind, bot_username, payload, status
FROM wp_sti_gs_handoff_steps WHERE session_id = <ID>;
```

| انتظار | مقدار |
|---|---|
| ردیف handoff | یک ردیف: `node_type = DEEP_LINK` (یا BUTTON/WEBAPP بسته به پیام)، `status = pending` |
| Artifact | `chain_init_classified` با node_type منطبق |

---

## مرحله ۲ — advance (startBot)

- دوباره **«▶ ادامه پردازش»** → **انتظار:** `state = CHAIN_WAITING`، `clicked_at` پر شده، `bot_username` پر شده

```sql
SELECT id, state, bot_username, clicked_at, chain_current_step
FROM wp_sti_gs_pipeline_items WHERE id = <ID>;
```

| انتظار | مقدار |
|---|---|
| Event | «گام ۱ انجام شد: Deep Link → @bot — منتظر پاسخ ربات» |
| Artifact | `chain_step_done` با `method = start_bot` |
| ردیف handoff | همان ردیف → `status = done` |

---

## مرحله ۳ — Poll

- دوباره **«▶ ادامه پردازش»** (اگر «منتظر پاسخ ربات» گرفت، چند بار با فاصله‌ی چند ثانیه دوباره بزنید — پنجره تا ۹۰۰ ثانیه باز است).
- سه حالت ممکن:

| حالت | انتظار |
|---|---|
| الف) گره‌ی جدید از ربات رسید | `state → CHAIN_STEP` + ردیف جدید در handoff (node_type جدید) + Artifact `chain_next_node` |
| ب) فایل (ASSET) رسید | `state → WAITING_BOT` + ردیف ASSET در handoff + ثبت در inbox + Artifact `chain_asset_detected` |
| ج) هنوز چیزی نرسیده | `state` همان `CHAIN_WAITING` می‌ماند (طبیعی — دوباره تلاش کنید) |

Artifactهای کلیدی: `chain_global_poll` (نتیجه‌ی poll سراسری)، سپس `chain_next_node` یا `chain_asset_detected`.

---

## مرحله ۴ — ادامه تا انتها

- اگر ASSET رسید: مسیر قدیم Asset ادامه می‌یابد: `WAITING_BOT → Poll Bot → BOT_RESPONSE → Match File → FILE_MATCHED → Download → ... → REVIEW_READY`
- اگر گره‌ی جدید رسید: مراحل ۲–۳ برای گام بعدی تکرار می‌شود تا به ASSET برسید.
- **انتظار نهایی:**

```sql
SELECT id, state, stage, product_id, queue_status, error_reason
FROM wp_sti_gs_pipeline_items WHERE id = <ID>;
```

| انتظار | مقدار |
|---|---|
| `state` | `REVIEW_READY` |
| `queue_status` | `queued` (اگر `gs_auto_enqueue` روشن باشد — پیش‌فرض ۱) |
| `product_id` | عدد (محصول ساخته شده) |

---

## معیار موفقیت

- [ ] کل مسیر بدون خطا تا `REVIEW_READY`
- [ ] در لاگ‌های Session فقط `ok` / `retry` دیده شود، بدون `error`
- [ ] هیچ ورودی `NEEDS_REVIEW` یا `CHAIN_FAILED` ثبت نشود
- [ ] زنجیره‌ی handoff: ردیف‌ها به ترتیب `DEEP_LINK → ... → ASSET` با status مناسب

---

## اگر خطایی دیدید — دقیقاً این‌ها را بفرستید

1. خروجی هر ۳ کوئری SQL بالا (با ID همان Session)
2. همه‌ی ردیف‌های `wp_sti_gs_handoff_steps` برای آن Session
3. Artifactهای آن Session (دکمه‌ی **👁 جزئیات** → تب Artifact)، مخصوصاً:
   - `chain_init_classified`
   - `chain_step_done`
   - `chain_global_poll`
   - `chain_next_node` / `chain_asset_detected` (هرکدام که هست)
4. آخرین Eventهای آن Session (صفحه‌ی **گزارش‌ها** → فیلتر با Session ID)
5. `error_reason` دقیق از جدول pipeline

---

## ممنوعیت‌های این تست

- ❌ روی 420474760 یا هر Session قدیمی اجرا نشود
- ❌ حالت `auto` یا `legacy` انتخاب نشود (فقط `chain`)
- ❌ Worker روشن نشود (تست دستی، قابل اندازه‌گیری)
- ❌ هم‌زمان تست retry/recovery انجام نشود
