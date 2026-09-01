# TEST-KIT 10.8.5 — Runtime Tests A–E (FileechBot Conversation Flow)

نصب: ZIP تست = `golden-importer-10.8.5-TEST.zip` (در ریشه‌ی repo — **نه** گلدن نهایی، فقط برای تست).
افزونه را آپدیت کنید و **WP-Cron / Worker روشن** باشد (interval پیش‌فرض).

> نام جدول‌ها با پیشوند `wp_` فرض شده؛ اگر پیشوند دیگری دارید جایگزین کنید.
> Sessions در جدول `wp_sti_gs_pipeline_items` است (نسخه‌های خیلی قدیمی: `wp_sti_gs_sessions`).

---

## Test A — Session کاملاً جدید (مسیر موفقیت)

1. یک item واقعی FileechBot بسازید (مثل قبل — deep link `https://t.me/fileechbot?start=...`).
2. اجازه دهید Worker کل مسیر را اجرا کند (بدون «ادامه پردازش» دستی).

**خروجی موردنیاز — این کوئری‌ها را اجرا و خروجی را کامل paste کنید:**

```sql
-- 1) Session + state + attempts + queue_status (id واقعی را جایگزین کنید)
SELECT id, state, stage, attempts, bot_username, file_code, clicked_at,
       next_retry_at, locked_until, error_reason
FROM wp_sti_gs_pipeline_items
WHERE id = <SESSION_ID>;

-- 2) handoff steps کامل (step_no / node_type / status / peer / msg_id)
SELECT step_no, node_type, status, meta
FROM wp_sti_gs_handoff_steps
WHERE session_id = <SESSION_ID>
ORDER BY step_no;

-- 3) send_text result (گام GATE) — داخل meta گام ۲: result.method / elapsed_ms
--    و همین‌جا: آخرین msg_id و info_last و file_code_seen

-- 4) متن/پیام پاسخ واقعی Bot — پیام‌های ربات در تاریخچه‌ی fileechbot بعد از
--    action_at_ts گام جاری (از event های زیر هم مشخص است)

-- 5) Artifacts کامل مسیر
SELECT id, name, data
FROM wp_sti_gs_artifacts
WHERE session_id = <SESSION_ID>
ORDER BY id;

-- 6) Events
SELECT id, level, message
FROM wp_sti_gs_session_events
WHERE session_id = <SESSION_ID>
ORDER BY id;

-- 7) Matcher نتیجه
SELECT id, inbox_id, session_file_code, candidate_file_code, file_name,
       score_file_code, score_file_name, score_time, total_score, status
FROM wp_sti_gs_bot_candidates
WHERE session_id = <SESSION_ID>
ORDER BY id;
```

**انتظار (معیار قبولی):**
- `DEEP_LINK` (done) → Text ربات با «File Name / File Code» → artifact `chain_file_info` با `fresh_response: true` و `file_code_seen` → گام `GATE` (done، `result.method = send_text`) با همان File Code استخراج‌شده → `ASSET` → `WAITING_BOT` → `BOT_RESPONSE` → `FILE_MATCHED` → **`REVIEW_READY`**.
- `matched_inbox_id` = ردیف inbox مربوط به فایل واقعی (envato_*.zip)؛ `identity_strength ≥ 60` (از `wp_sti_bot_inbox` + matcher artifact `match_strategy`).
- `attempts = 0`؛ هیچ artifact `candidate_rejected` با دلیل `file_code_mismatch` برای فایل خودِ Session نباشد.
- اگر ربات «فایل یافت نشد» را **قبل از** دریافت کد فرستاده باشد: artifact `chain_file_not_found_stale` دیده می‌شود و مسیر ادامه می‌یابد (نه ترمینال).

---

## Test B — فایل پیدا نشد (ترمینال)

1. Session جدید با یک File Code که ربات واقعاً با «متاسفانه فایل درخواستی یافت نشد.» پاسخ می‌دهد (مثلاً کد اشتباه/ناموجود).
2. صبر کنید تا ترمینال شود؛ سپس این‌ها را بگیرید:

```sql
SELECT id, state, stage, attempts, error_reason, next_retry_at
FROM wp_sti_gs_pipeline_items WHERE id = <SESSION_ID>;

SELECT step_no, node_type, status, meta
FROM wp_sti_gs_handoff_steps WHERE session_id = <SESSION_ID> ORDER BY step_no;

SELECT name, data FROM wp_sti_gs_artifacts
WHERE session_id = <SESSION_ID> AND name IN ('chain_file_not_found','chain_poll_started','chain_global_poll')
ORDER BY id;
```

**معیار قبولی:**
- `state = ERROR_FILE_NOT_FOUND`، `error_reason` شامل `CHAIN_FILE_NOT_FOUND`.
- Artifact `chain_file_not_found` ثبت شده؛ **بعد از آن هیچ** `chain_poll_started` جدیدی نیاید.
- `attempts` تغییری نکند؛ رکورد `wp_sti_gs_handoff_steps` جدیدی ساخته نشود (شمارش قبل/بعد).
- Worker دیگر آن Session را pick نکند (در `pick()` از TERMINAL حذف شده) — در Events بعد از ترمینال فقط «Worker مسیر را کامل کرد» باشد.

---

## Test C — مهم‌ترین: Identity / عدم اتصال فایل‌های بی‌ربط

حین اجرای Test A، فایل‌های unrelated در Global Inbox را **عمداً نگه دارید** (طبیعی وجود دارند — مثل `Magnific_11531053.zip`، `photo_857.jpg`، `file_67183.mp4` که peer آنها `0` است).

```sql
-- همه‌ی ردیف‌های inbox در بازه‌ی اجرای Session جدید
SELECT id, peer, file_name, codes, status, date_ts
FROM wp_sti_bot_inbox
WHERE date_ts >= <clicked_at از Test A>
ORDER BY id;

-- آیا هیچ‌کدام candidate شدند؟
SELECT c.id, c.inbox_id, c.file_name, c.status, c.total_score
FROM wp_sti_gs_bot_candidates c
WHERE c.session_id = <SESSION_ID>;
```

**معیار قبولی:**
- `matched_inbox_id` = فقط فایل واقعی همان Session؛ هیچ‌کدام از Magnific/photo_/file_ در candidates نباشند.
- artifact `candidate_rejected` (file_code_mismatch) فقط برای فایل‌های بی‌ربط (اگر اصلاً fetch شده باشند).
- اگر peer فایل‌های بی‌ربط `0` باشد، حتی در `fetch_inbox_rows` هم نمی‌آیند (فیلتر `LOWER(peer)=LOWER('fileechbot')`).

---

## Test D — عدم Step Explosion

1. یک Session در `WAITING_BOT` (یا `CHAIN_WAITING`) بدون پاسخ واقعی بگیرید.
2. قبل از چند Poll: `SELECT COUNT(*) FROM wp_sti_gs_handoff_steps WHERE session_id = <ID>;`
3. حداقل ۳–۵ تیک Worker بگذرد (بدون پاسخ ربات)؛ بعد دوباره COUNT.

**معیار قبولی:** COUNT قبل == COUNT بعد. (پاسخ‌های متنی ربات فقط `chain_informational` می‌شوند؛ گام جدید فقط برای گره‌های اجرایی/ASSET.)

---

## Test E — Retrofit سشن‌های ۶۷ و ۶۸

بعد از آپدیت به 10.8.5، **قبل از** هر اقدامی این‌ها را بگیرید:

```sql
SELECT id, state, stage, attempts, file_code, bot_username, clicked_at, error_reason
FROM wp_sti_gs_pipeline_items WHERE id IN (67, 68);

SELECT session_id, step_no, node_type, status, meta
FROM wp_sti_gs_handoff_steps WHERE session_id IN (67, 68) ORDER BY session_id, step_no;
```

سپس بگذارید Worker یکی‌دو تیک اجرا کند و دوباره همان دو کوئری + شمارش steps:

**برای هرکدام گزارش کنید:**
- state قبل / `info_last` (از meta آخرین گام) / آیا پیام تازه‌ای در تاریخچه‌ی ربات هست (msg_id > last_msg_id) / آیا retrofit اجرا شد (artifact `chain_file_not_found` یا transition) / state بعد / تعداد handoff_steps قبل و بعد / attempts / queue_status (pick شدن یا نه).

**انتظار:**
- 67/68 اگر `info_last` حاوی «فایل یافت نشد» باشد و پیام تازه‌ای نباشد → اولین poll بعد از ارتقا → `ERROR_FILE_NOT_FOUND`.
- اگر فایل واقعی تازه (msg_id جدید) وجود داشته باشد → فایل برنده است → `WAITING_BOT` → مسیر Matcher.
- اگر گام TEXT قدیمی (کد) مانده باشد → `chain_text_step_as_code` → send_text → ادامه.

---

## معیار Release
فقط وقتی A تا E در Runtime سبز شدند و تأیید شما رسید: commit → ZIP گلدن → push → release v10.8.5.
تا آن زمان: **هیچ commit / ZIP گلدن / release ای انجام نمی‌شود.**
