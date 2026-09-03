# Golden Importer 10.10 — خط تولید خودکار (Autonomous Processing Pipeline)

## هدف
پس از ۵ گام کاربر (ثبت کانال ← اسکن ← پروفایل ← تعداد Session ← Start)،
سیستم هر Session را تا **PUBLISHED** یا **REVIEW** می‌رساند — بدون هیچ کلیک بعدی.

## اصل طراحی (مستقیم از دستور کار)
افزایش Retry هدف نیست. هدف یک **State Machine قطعی و خودترمیم** است:

* هر Session فقط **یک Stage فعال** + **یک Status** دارد.
* تمام تصمیم‌ها فقط از **State** (ستون state در دیتابیس) — نه از حدس، لاگ یا Artifact.
* هر Tick فقط `next_valid_transition()` — هیچ پرش بین Stage.
* تا راهکار Recovery هست، Session به REVIEW نمی‌رود (Recover ← Retry ← Replay ← Rewind).
* پایداری مهم‌تر از سرعت است (هاست اشتراکی): پیش‌فرض ۱ Session فعال، ۱ client، ۱ دانلود، ۱ محصول، ۱ Session در هر Tick.

## مدل Stage/Status

| Stage    | Statusها        |
|----------|-----------------|
| DISCOVER | PENDING/RUNNING/WAITING/FAILED/COMPLETED |
| BOT      | PENDING/RUNNING/WAITING/FAILED/COMPLETED |
| MATCH    | PENDING/RUNNING/WAITING/FAILED/COMPLETED |
| DOWNLOAD | PENDING/RUNNING/WAITING/FAILED/COMPLETED |
| MEDIA    | PENDING/RUNNING/WAITING/FAILED/COMPLETED |
| PRODUCT  | PENDING/RUNNING/WAITING/FAILED/COMPLETED |
| PUBLISH  | PENDING/RUNNING/WAITING/FAILED/COMPLETED |

Statusها: `PENDING` (نوبتش رسیده، هنوز شروع نشده) · `RUNNING` (موتور مشغول است) ·
`WAITING` (منتظر ربات/بیرون — خرابی نیست) · `FAILED` (خطا — قابل Recovery) · `COMPLETED`.

**وضعیت‌های نهایی مجاز — فقط سه:** `PUBLISHED` · `REVIEW` · `CANCELLED`.

## نگاشت State فعلی → (Stage, Status) — قطعی و تک‌به‌تک

| State | Stage | Status |
|---|---|---|
| SCANNED, ERROR_BUTTON | DISCOVER | PENDING / FAILED |
| BUTTON_FOUND, ERROR_CLICK | BOT | PENDING / FAILED |
| WAITING_BOT | BOT | WAITING |
| ERROR_BOT_TIMEOUT | BOT | FAILED |
| CHAIN_STEP / CHAIN_WAITING / CHAIN_FAILED | BOT | RUNNING / WAITING / FAILED |
| BOT_RESPONSE | MATCH | PENDING |
| ERROR_MATCH | MATCH | FAILED |
| FILE_MATCHED, DOWNLOAD_PENDING, DOWNLOADING, DOWNLOAD_FAILED | DOWNLOAD | PENDING / PENDING / RUNNING / FAILED |
| DOWNLOADED, STORED, MEDIA_PENDING | MEDIA | PENDING |
| MEDIA_BUILDING, MEDIA_FAILED | MEDIA | RUNNING / FAILED |
| MEDIA_READY | PRODUCT | PENDING |
| PRODUCT_BUILDING, PRODUCT_FAILED | PRODUCT | RUNNING / FAILED |
| PRODUCT_READY, REVIEW_READY* | PUBLISH | PENDING / (→REVIEW) |
| PUBLISHED | PUBLISH | COMPLETED → نهایی PUBLISHED |
| NEEDS_REVIEW, ERROR_FILE_NOT_FOUND, DEAD_LETTER | — | نهایی REVIEW |
| SKIPPED, CANCELLED | — | نهایی CANCELLED |

*REVIEW_READY در نگاشت نهایی REVIEW است (سازگاری با جدول فعلی — تغییر داده نمی‌شود؛
لایه‌ی Stage فقط **معنای** واحد به آنها می‌دهد).

## قوانین گذار (next_valid_transition)
* هم‌Stage: هر تغییری مجاز (Recovery درون مرحله).
* Stage فعلی ← Stage بعدی: مجاز (پیش‌رفت).
* پرش ۲ یا بیشتر: **ممنوع** — Supervisor ثبت anomaly + event می‌کند.
* بازگشت به Stage قبل: فقط Rewindهای تعریف‌شده (DOWNLOADING→FILE_MATCHED،
  MEDIA_BUILDING→STORED — هر دو در عمل هم‌Stage‌اند).

## Recovery آگاه از Stage

| Stage | خطاها | Recovery (به ترتیب) |
|---|---|---|
| BOT | BOT_TIMEOUT, WAITING_REPLY | Poll Again ← Inbox Scan ← Re-click |
| MATCH | NO_MATCH, MATCH_FAILED | Rebuild Candidates ← Recalculate Scores ← Retry Match |
| DOWNLOAD | FILE_REFERENCE_EXPIRED, RPC_ERROR, DOWNLOAD_TIMEOUT | Refresh Reference ← Reconnect Client (IPC heal) ← Retry Download |
| MEDIA | THUMBNAIL_ERROR, IMAGE_ERROR | Regenerate ← Skip Optional Assets ← Continue (Session متوقف **نمی‌شود**) |
| PRODUCT | TITLE_ERROR, AI_ERROR, CATEGORY_ERROR | Fallback Builder ← Fallback Template ← Rebuild |
| PUBLISH | WC_ERROR, META_ERROR, QUEUE_ERROR | Repair Metadata ← Retry Publish |

IPC (هر Stage): `ipc_heal()` ← restart scoped worker ← recycle client ← retry — بدون دخالت کاربر (از 10.9.3).

## REVIEW Gate — فقط ۴ دلیل
`UNKNOWN_BOT_FLOW` · `HUMAN_VERIFICATION` · `UNRESOLVED_DUPLICATE` · `CORRUPTED_DATA`
هر خطای دیگری **باید ادامه پیدا کند** (Retry با backoff) — نه DEAD_END بی‌دلیل.
هر آیتم REVIEW: Session ID، Stage فعلی، Stage شکست، آخرین خطا، تعداد Recovery،
**Recommended Fix** + دکمه‌ی **Run Suggested Fix** (بازتعیین قطعی State — بدون حذف داده).

## Queue Governor
سیگنال‌ها: RAM هاست (/proc/meminfo) · Load (/proc/loadavg، نرمال‌شده بر core) ·
نرخ خرابی IPC (پنجره‌ی ۳۰ دقیقه) · Backlog صف.
سطوح: OK (×1.0) · THROTTLE (×0.5) · EMERGENCY (×0.25 + ممنوعیت کارهای سنگین).
Governor batch و heavy-work را خفه می‌کند؛ هرگز Session را «خطا» نمی‌کند (waiting می‌شود).

## بودجه‌های منابع (پیش‌فرض — تنظیم‌پذیر)
Max Active Sessions = 1 · Max MTProto Client = 1 (singleton) · Max Download/Tick = 1 ·
Max Product Build/Tick = 1 · Sessions Per Tick = 1.

## Watchdog: Detect ← Repair ← Verify ← Log
IPC dead → ipc_heal → verify (سوکت/worker) → log موفقیت/شکست (گسترش 10.9.3).

## Log هر Session (جدول جدید sti_gs_session_runs — فقط اضافه، بدون تغییر داده)
started_at · ended_at · duration · stage_history (JSON، سقف ۵۰ رکورد) ·
retry_count · recovery_count · ipc_heal_count · download_retry_count ·
publish_retry_count · final_result.

## داشبورد
* **Automation Health**: ماتریس Stage×Status، نهایی‌ها، IPC workers، RAM/Load/حافظه،
  شمارنده‌های Recovery/Retry، وضعیت Governor، last/next tick.
* **Review Dashboard**: فهرست REVIEW با fix پیشنهادی + دکمه‌ی اجرای آن.
* **Environment Health**: WP_DEBUG/WP_DEBUG_LOG/WP_DEBUG_DISPLAY (قرمز اگر DISPLAY روشن)،
  DISABLE_WP_CRON، last cron run، cron health.
* **Automation Settings**: همه‌ی سقف‌های retry، بودجه‌ها و آستانه‌های Governor — بدون ویرایش فایل.

## معماری (لایه‌بندی)
* `STI_GS_Stage` — نگاشت قطعی + اعتبارسنجی گذار (بدون عوارض).
* `STI_GS_Automation` — تنظیمات/بودجه‌ها (options).
* `STI_GS_Governor` — کنترل فشار (فقط می‌خواند + یک option وضعیت).
* `STI_GS_Review` — REVIEW gate + suggested fix.
* `STI_GS_Run_Log` — لاگ هر Session.
* Auto Worker / Recovery / Watchdog — مصرف‌کننده‌ی این لایه‌ها.

**قاعده‌ی تغییر:** هیچ موتور موجود (Chain/Engines) بازنویسی نمی‌شود؛ فقط **دوربرگردان
مرکزی** (Worker + Recovery) از این لایه‌ها پیروی می‌کند و موتورها همان stateها را همان‌طور
نویسند که همیشه نوشته‌اند. این یعنی صفر ریسک بر روی ماهیت‌هایی که در runtime تأیید شده‌اند.
