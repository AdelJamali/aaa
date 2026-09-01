# Audit — معماری Bot Handoff گلدن اسکن (پیش از اصلاح ۱۰.۸.۳)

> تاریخ: 1405/06/10 — وضعیت: Audit فقطخواندنی + سپس اصلاحات پیوست.

---

## 1. Current Architecture

| لایه | کلاس | نقش |
|---|---|---|
| Session | `STI_GS_Session` | ردیف pipeline (state/attempts/next_retry_at/locked_until/worker_id/chain_mode) + claim/release اتمی |
| Router | `STI_GS_Session_Ajax::next_stage()` | **تنها** نگاشت state → stage (مشترک دستی/خودکار) |
| Worker | `STI_GS_Auto_Worker` | tick → pick() → advance_one() → next_stage() (یک stage در هر run) |
| Button Resolver | `STI_GS_Button_Resolver` | تشخیص دکمه از پیام مبدأ (deep_link با confidence) |
| Chain Engine | `STI_GS_Chain_Engine` | init/advance/poll/waiting/recover/timeout_recovery/match_recovery + fail_chain/needs_review |
| Handoff | `STI_GS_Handoff_Steps` | جدول `wp_sti_gs_handoff_steps` — منبع وضعیت هر گام |
| Node | `STI_GS_Node_Processor` | اجرای اکشن (press/start_bot/webapp/invite/text) |
| MTProto | `STI_MTProto` | client MadelineProto + RPCها + دانلود |
| Collector | `STI_GS_Bot_Candidate_Collector` | global_poll + build_for_session + BOT_TIMEOUT_SEC=900 |
| File Hunter | `STI_File_Hunter` | collect_incoming (اسکن گسترده) + download() |
| Retry | `STI_GS_Retry` | تشخیص FLOOD_WAIT → next_retry_at |

## 2. Current Handoff Flow

```
Session(SCANNED, chain_mode=chain)
 → next_stage → Chain Init (claim 60s)
 → advance (claim 90s) → Node_Processor::process (Telegram RPC) → mark_done → CHAIN_WAITING
 → poll (claim 45s)
      ├ global_poll() → find_recent_documents() → File_Hunter::collect_incoming()
      │                 (getHistory برای همه‌ی botهای شناخته‌شده + Saved Messages
      │                  + scan_dialogs + تا ۳۰ دیالوگ دیگر)
      ├ recent_peer_messages(peer) → getHistory
      ├ classify → ASSET؟ → WAITING_BOT (مسیر قدیم Matcher)
      │           → گره جدید؟ → CHAIN_STEP (گام بعدی)
      │           → TEXT؟ → informational sink
      └ هیچ؟ → CHAIN_WAITING می‌ماند؛ بعد از 900s → CHAIN_FAILED → retry gate (max 3) → NEEDS_REVIEW
```

## 3. Exact Failure Point (Session 68)

| شاهد | مقدار | معنا |
|---|---|---|
| worker_id | `chain-poll-3945812-19zRio` | poll() شروع شده (claim گرفته) |
| updated_at | 12:33:10 | لحظه‌ی claim |
| locked_until | 12:33:55 | claim + 45s (POLL_LOCK_SECONDS) |
| آخرین Artifact | chain_step_done 12:31:01 | **هیچ chain_global_poll ثبت نشده** |
| آخرین Event | «گام 1 انجام شد» 12:31:01 | هیچ رویداد poll ای |

**نقطه‌ی شکست دقیق:** بین `claim()` و نوشتن `chain_global_poll` — یعنی داخل `global_poll()` → `find_recent_documents()` → `File_Hunter::collect_incoming()` (getHistory های متوالی). درخواست هرگز برنگشته، `finally` (release) اجرا نشده، قفل با TTL منقضی شده.

## 4. Root Cause

1. **تماس Telegram بدون کران زمانی:** `set_time_limit(0)` در مسیرهای MTProto (mtproto:199/1423/1469، file-hunter:292) + نبود watchdog → یک RPC قفل‌شده (اتصال خراب / flood) تا ابد درخواست cron را معلق نگه می‌دارد.
2. **FLOOD_WAIT بلوک‌کننده:** تنظیمات MadelineProto flood را با sleep بلوک‌کننده مدیریت می‌کند (تنظیم نشده در `build_settings_candidates`).
3. **Global Poll سنگین و coupled:** هر poll هر Session، تمام ربات‌ها/دیالوگ‌ها را اسکن می‌کند (تا ~۶۰ getHistory) بدون cache.
4. **عدم مشاهده‌پذیری mid-flight:** هیچ breadcrumb قبل از تماس سنگین ثبت نمی‌شد → نقطه‌ی hang نامرئی.

## 5. Tables Used

| جدول | نقش | نکته |
|---|---|---|
| `wp_sti_gs_pipeline_items` | Session | state/attempts/next_retry_at/locked_until/worker_id/chain_mode/clicked_at/bot_username |
| `wp_sti_gs_handoff_steps` | **منبع وضعیت گام‌ها** | status/attempts/meta + ایندکس‌های session_status و session_bot (از ۱۰.۸) |
| `wp_sti_gs_session_events` | Audit trail | stage/result/message |
| `wp_sti_gs_artifacts` | Audit trail | type/payload_json |
| `wp_sti_bot_inbox` | صندوق ورودی فایل | record/record_many |
| `wp_options` | تنظیمات | sti_gs_worker_enabled / sti_gs_worker_stats / sti_settings / sti_gs_halt |

## 6. State Machine (وضعیت فعلی + نگاشت رسمی)

Session: `SCANNED → CHAIN_STEP → CHAIN_WAITING → CHAIN_FAILED(→retry→) / NEEDS_REVIEW / WAITING_BOT(ASSET) → ... → REVIEW_READY`

HandoffStep (نام‌گذاری فعلی → منطقی):
| فعلی | منطقی | توضیح |
|---|---|---|
| `pending` | pending | ساخته شده، هنوز اجرا نشده |
| — (جای خالی) | **running** | در حال اجرا (اکشن dispatch شده) — اضافه می‌شود |
| `done` | success | اکشن با موفقیت dispatch شد |
| — (const موجود، بی‌استفاده) | **waiting** | منتظر پاسخ ربات — فعال می‌شود |
| `failed` | failed | خطای قطعی/بعد از سقف retry |
| — | skipped | N/A (در زنجیره استفاده نمی‌شود؛ NEEDS_REVIEW معادل منطقی است) |

## 7. Lock Lifecycle

- `claim()` اتمی با TTL (`locked_until`) + مالکیت (`worker_id`) ✅
- `release()` مالکیت‌محور (جلوی آزادکردن قفل دیگران) ✅
- **خلأ:** اگر عملیات برنگردد، release اجرا نمی‌شود؛ فقط TTL (۴۵s برای poll) نجات می‌دهد — ولی درخواست cron تا ابد معلق می‌ماند (مشکل اصلی).
- Download: LOCK_SECONDS=600 ولی `set_time_limit(0)` → قفل می‌تواند زودتر از عملیات بمیرد (ریسک دانلود دوباره).

## 8. Telegram/MTProto Lifecycle

- client() → MadelineProto با settings (بدون RPC flood timeout) → RPC sync → catch Throwable → WP_Error
- `set_time_limit(0)` در: install_engine (ادمین)، download_media_robust (worker)، download_media (worker)، file-hunter::download (worker)، agent-bridge (600)
- loop guard فقط لاگ می‌کند، متوقف نمی‌کند.

## 9. Problems Found

| # | مشکل | شدت |
|---|---|---|
| P1 | تماس Telegram بدون کران (set_time_limit(0) + بدون watchdog) → hang نامحدود Worker | بحرانی |
| P2 | FLOOD_WAIT → sleep بلوک‌کننده‌ی MadelineProto؛ flood_wait_until فقط روی الگوی FLOOD_WAIT_n کار می‌کند | بالا |
| P3 | global_poll سنگین + بدون cache + coupling بین Sessionها | بالا |
| P4 | بدون breadcrumb در poll → نقطه‌ی hang نامرئی | متوسط |
| P5 | فقدان وضعیت `running`/`waiting` در HandoffStep (STATUS_WAITING بی‌استفاده است) | متوسط |
| P6 | نبود Unique (session_id, step_no) — هم‌زمانی append در تئوری | متوسط |
| P7 | Worker بدون بودجه‌ی تیک — یک Session سنگین کل تیک را می‌خورد | متوسط |
| P8 | download: قفل 600s ولی PHP بی‌کران → ناهماهنگی | بالا |

## 10. Proposed Changes

1. **NEW `STI_GS_Deadline`** (class-gs-deadline.php): `guard($fn, $timeout, $label)` — دو حالت: pcntl (SIGALRM → exception کنترل‌شده، finally اجرا می‌شود) یا fallback (set_time_limit کران‌دار → مرگ کران‌دار درخواست + Stale-Lock Recovery با TTL). بدون وانمود به timeout.
2. **MTProto:** تنظیم RPC flood (method_exists-guard)؛ حذف set_time_limit(0) از مسیرهای worker (کران‌دار 590s)؛ helper تشخیص ثانیه‌ی flood از هر خطا.
3. **Collector:** global_poll با cache (60s) + guard (45s) — Shared Observation.
4. **Chain Engine poll:** breadcrumb `chain_poll_started`/phaseها؛ guard دور global_poll و recent_peer_messages؛ flood → `next_retry_at` + waiting (بدون مصرف attempts)؛ وضعیت `waiting` روی گام.
5. **advance:** وضعیت `running` + artifact `chain_step_started` + guard دور process (60s).
6. **Worker:** بودجه‌ی تیک (240s).
7. **DB:** Unique `(session_id, step_no)` — migration idempotent.
8. **Observability:** رویدادهای handoff_* (mapping در §۱۵ گزارش نهایی).
9. **Retry:** الگوی flood_wait_until گسترده‌تر (flood wait: N / flood_wait_N).

## 11. Files To Change

1. `includes/golden-scan/class-gs-deadline.php` (NEW)
2. `sanil-telegram-importer.php` (require)
3. `includes/class-sti-mtproto.php`
4. `includes/golden-scan/class-gs-bot-candidate-collector.php`
5. `includes/golden-scan/class-gs-chain-engine.php`
6. `includes/golden-scan/class-gs-handoff-steps.php`
7. `includes/golden-scan/class-gs-auto-worker.php`
8. `includes/golden-scan/class-gs-db.php`
9. `includes/class-sti-file-hunter.php`
10. `includes/golden-scan/class-gs-session-ajax.php`
11. `includes/golden-scan/class-gs-media-engine.php`
12. `includes/golden-scan/class-gs-action-executor.php`
13. `includes/golden-scan/class-gs-retry.php`

## 12. DB Migrations Required

- **یک migration idempotent:** `ensure_index(handoff_steps, 'session_step', '(session_id, step_no)', unique=true)` — در migrate_v24_columns.
- هیچ ستون/جدولی حذف یا recreate نمی‌شود. `status VARCHAR(20)` مقادیر جدید (running/waiting) را می‌پذیرد.

## 13. Backward Compatibility

- مسیر legacy دست‌نخورده: BUTTON_FOUND→Execute Action→WAITING_BOT→Poll Bot→Matcher همان‌طور می‌ماند (فقط poll_bot_stage هم guard می‌گیرد).
- `done` همچنان معنای موفقیت دارد؛ `waiting` افزودنی است (latest_done هر دو را می‌بیند).
- retry gate و STEP_ATTEMPTS_MAX و NEEDS_REVIEW بدون تغییر.
- Sessionهای قدیمی NULL/legacy: routing قبلی (D6) حفظ می‌شود.

## 14. Test Plan

| تست | سناریو | ابزار |
|---|---|---|
| T1 Deep Link | SCANNED→Init→advance→success | Runtime بعد از تأیید |
| T2 Waiting | poll بدون پاسخ → waiting + release | Runtime |
| T3 Poll Success | پاسخ → ASSET/next node | Runtime |
| T4 Telegram Timeout | RPC قفل → guard → خطای کران‌دار → retry | Runtime + ساختگی |
| T5 Flood Wait | FLOOD_WAIT → next_retry_at + release | Runtime |
| T6 Worker Crash | مرگ وسط Step → TTL → pick دوباره | Runtime |
| T7 Duplicate Worker | claim اتمی → فقط یکی | کد (اثبات) |
| T8 Manual+Auto | همان next_stage | کد (اثبات) |

---

# گزارش پیاده‌سازی ۱۰.۸.۳

## Files changed (۱۳)

| فایل | تغییر |
|---|---|
| `includes/golden-scan/class-gs-deadline.php` | **NEW** — STI_GS_Deadline::guard (pcntl + fallback + پشتیبان time-limit) |
| `sanil-telegram-importer.php` | require کلاس جدید + نسخه ۱۰.۸.۳ |
| `includes/class-sti-mtproto.php` | flood غیرمسدود (RPC settings، method_exists-guard)؛ حذف همه‌ی set_time_limit(0) → MAX_PHP_SECONDS=590؛ helperهای flood_seconds/flood_error؛ غنی‌سازی catch تاریخچه |
| `includes/golden-scan/class-gs-bot-candidate-collector.php` | global_poll: cache 60s (Shared Observation) + guard 45s + catch Throwable با flood enrichment |
| `includes/golden-scan/class-gs-chain-engine.php` | poll: breadcrumb chain_poll_started + گارد global/peer + flood→next_retry_at (بدون attempts) + STATUS_WAITING؛ advance: STATUS_RUNNING + chain_step_started + گارد 60s + chain_retry_scheduled؛ chat_info گارد 15s؛ waiting() گارد 60s |
| `includes/golden-scan/class-gs-handoff-steps.php` | STATUS_RUNNING + نگاشت رسمی ۶ وضعیت + latest_done شامل waiting |
| `includes/golden-scan/class-gs-auto-worker.php` | بودجه‌ی تیک 240s |
| `includes/golden-scan/class-gs-db.php` | migrate_v24: ایندکس یکتای (session_id, step_no) — idempotent |
| `includes/class-sti-file-hunter.php` | دانلود کران‌دار 560s + گارد |
| `includes/golden-scan/class-gs-session-ajax.php` | (global_poll داخل poll_bot_stage از گارد collector بهره می‌برد) |
| `includes/golden-scan/class-gs-media-engine.php` | find_photo_near گارد 20s |
| `includes/golden-scan/class-gs-action-executor.php` | press_button / start_bot گارد 80s |
| `includes/golden-scan/class-gs-retry.php` | flood_wait_until الگوهای بیشتر (flood wait: N) |

## DB migrations

- `migrate_v24_columns()`: `ensure_index(handoff_steps, 'session_step', '(session_id, step_no)', unique=true)` — idempotent؛ هیچ ستون/جدولی حذف یا recreate نشد. مقادیر جدید status (running/waiting) در `VARCHAR(20)` موجود جا می‌شوند.

## Tests executed (این مرحله — بدون Runtime)

- بالانس syntactical ۱۳ فایل (پایتون): ✅ همه
- grep-اثبات T1–T8: ✅ (جدول بالا)
- اثبات: هیچ `set_time_limit(0)` در کد باقی نمانده (همه کران‌دار)
- اثبات: ترتیب require درست (deadline قبل از chain-engine)

## Tests passed (runtime — هنوز انجام نشده)

T1–T8 از §۱۴: **منتظر تأیید کاربر برای Runtime Test** (سشن جدید، نه 420474760). سناریوی Session 68 (poll hang) با این اصلاح باید به‌صورت «خطای کران‌دار + بازیابی قفل» رفتار کند، نه «hang ابدی».

## Known limitations

1. بدون pcntl (بیشتر هاست‌های اشتراکی): timeout = مرگ کران‌دار درخواست (بدون finally) — بازیابی با TTL قفل (۴۵–۹۰s)؛ بینقص ولی نه «کنترل‌شده».
2. با pcntl: اگر Revolt استثنای SIGALRM را بلعیده باشد، پشتیبان set_time_limit درخواست را می‌کشد (همان بازیابی TTL).
3. تنظیم RPC flood با method_exists-guard است — اگر نسخه‌ی MadelineProto متد را نداشت، flood-sleep تا سقف کران‌دار ادامه می‌یابد و با guard خنثی می‌شود (نه بی‌کران).
4. `php -l` در سندباکس ممکن نبود (PHP نصب نیست) — فقط بالانس syntactical انجام شد.
5. دانلود فایل‌های واقعاً بزرگ (>۱۰ دقیقه) ممکن است به کران ۵۹۰s بخورد — برای فایل‌های عادی (تا چند GB روی سرعت خوب) کافی است؛ در صورت نیاز قابل تنظیم است.

## Rollback plan

- حذف `require` کلاس deadline + حذف فایل + بازگرداندن set_time_limit(0) در ۴ محل mtproto/file-hunter (با revert همان ۴ hunk) — یا `git checkout -- <files>` روی ۹d46b63.
- DB: حذف ایندکس `session_step` (اختیاری؛ افزودنی و بی‌خطر).
- نسخه به ۱۰.۸.۲ برگردانده شود.

