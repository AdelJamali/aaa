# AUDIT — Golden Importer 10.9.2 (فقط‌خواندنی)

> تاریخ: ۲۰۲۶-09-03 — نسخه‌ی مورد بررسی: **10.9.2** (سورس repo پس از هم‌ترازی با ZIP گلدن)
> روش: خواندن کامل سورس + **بازبینی جنایی روی خودِ `madeline81.phar`** (این phar یک بایگانی PHP است؛ محتوای داخلی‌اش استخراج و خط‌به‌خط خوانده شد). هیچ کدی تغییر نکرده است.
> اعداد علامت‌دار «تخمین» بر مبنای هاست اشتراکی معمولی (PHP 8.2، memory_limit 128M، MySQL کوچک)‌اند — نه اندازه‌گیری روی سرور شما.

---

## خلاصه‌ی اجرایی

سیستم 10.9.2 از نظر **طراحی** درست است: مرز مالکیت واضح (Chain Engine = منطق، Recovery = زیرساخت)، قفل اتمی، retry bound، guard روی همه‌ی تماس‌های تلگرام، dedup با ایندکس یکتا. اما **لایه‌ی زیرش (MadelineProto) روی هاست اشتراکی با یک معماری می‌دود که با آن هاست سازگار نیست**: phar فعلی یک بیلد **v8 برای PHP 8.4** است که همه‌ی فراخوانی‌های تلگرامی را به یک **پروسه‌ی جداگانه‌ی `madeline-ipc`** (spawn‌شده با `proc_open` از داخل درخواست FPM) منتقل می‌کند. سه خطای مشاهده‌شده شما — `The endpoint does not exist`، `Must call resume() or throw()` و OOM روی خط 172 — هر سه ریشه‌ای دارند که در این بخش گزارش می‌شود (بخش‌های 2، 3، 4، 5).

**پنج نکته‌ی مهم‌تر:**

1. `The endpoint does not exist!` خطای **تلگرام/RPC نیست**؛ خطای لایه‌ی IPC داخل phar است وقتی **فایل سوکت (FIFO) worker زنده نیست**. comment داخل کد افزونه آن را «تغییر نام متد بین نسخه‌ها» تشخیص داده — **اشتباه است** (شواهد: بخش 4).
2. OOM خط 172 = **require همان 19.5 مگابایتی phar** در حالی که پروسه از قبل 103MB مصرف کرده؛ و افزایش `memory_limit` به 512M **بعد از** require انجام می‌شود — یعنی دیر (بخش 2).
3. خودِ کد (توضیح داخل `ajax_reply`) تأیید می‌کند که **workerهای IPC جمع می‌شوند و هاست OOM می‌کند** — و همین باالگوِ «سایت چند دقیقه از دسترس خارج می‌شود» است (بخس 9).
4. `cleanup_orphan_workers()` (pkill workerهای یتیم) **هیچ‌جای کد صدا زده نشده** — کلمرده (بخش 4/9).
5. زنجیره‌ی خودکارسازی تا Watcher وصل شده، ولی **بوتل‌نک واقعی throughput Worker است** (پیش‌فرض: 3 Session/تیک، ≥۵ دقیقه فاصله، ۱ اکشن ربات در هر تیک) — با این پیش‌فرض‌ها بک‌لاگ 5٬785 حدود ۲ ماه طول می‌کشد (بخش 1/8).

---

## نقشه‌ی سریع سیستم (برای مرجع گزارش)

```
[WP-Cron / فیک‌کران] ──► 5 کران: sti_queue_tick(1m, legacy) · sti_gs_auto_worker(5m)
                          sti_gs_publish_tick(5m) · sti_gs_watchdog(15m) · sti_gs_channel_watcher(30m)
                          + on-demand: sti_gs_scan_worker / scan_segments / ci_worker / goldtel×2 / bot_modes

Channel Watcher (30m): backpressure ← daily_cap ← scan_new_messages (کران اسکن)
                       ← refresh_profiles (LIKE روی Inventory؛ هر ۶h) ← create_sessions (available→queued)

Auto Worker (5m, batch=3, budget 240s, 1 bot-action/tick):
  pick() → advance_one() → next_stage(state) → [یک موتور]:
    SCANNED→Chain Init → CHAIN_STEP→advance (Node_Processor: startBot/press/send/join, guard 60s)
    → CHAIN_WAITING→poll (global_poll guard 45s + recent_peer guard 25s + fallback Inbox)
    → ASSET → WAITING_BOT → poll_bot → Match → Download (guard 560s) → Media → Product → Publish Queue
  Recovery (15m): آزادسازی قفل‌های کهنه / rewind مراحل legacy / گام‌های running یتیم — بدون تصمیم زنجیره‌ای
```

---

# بخش‌های مورد درخواست

## 1) مشکل №۱ — نقاط دخالت دستی (Automation Gaps)

### 1.1 وضعیت: چه چیزهایی واقعاً خودکار است

| مرحله | خودکار؟ | توسط |
|---|---|---|
| اسکن کانال | ✅ (با Watcher روشن) | Watcher → Scan Run (incremental، per-tick budget) |
| اجرای پروفایل روی Inventory | ✅ (هر ۶ ساعت یا با رشد Inventory) | Watcher.refresh_profiles |
| ساخت Session از Candidate | ✅ (اگر `default_category_id` داشته باشد) | Watcher.create_sessions (idempotent) |
| کل زنجیره‌ی Bot (Init→…→ASSET) | ✅ | Auto Worker + Chain Engine |
| دانلود + Storage + Media + Product | ✅ | همان Worker (نوبتی) |
| صف انتشار | ✅ | Publish Queue tick (5m) |

### 1.2 جدول نقاط دستی

| Module | Current Manual Step | Why Automation Stops | Required Automation Strategy | Risk |
|---|---|---|---|---|
| Profiles | تعیین `default_category_id` برای هر پروفایل | Watcher عمداً برای پروفایلِ بی‌دسته Session نمی‌سازد (نکته‌ی طراحی: محصول بی‌دسته و بی‌قیمت) | Auto-assign: اگر کانال فقط یک پروفایل داشته باشد یا پروفایل قبلاً دسته داشته باشد، استفاده از همان؛ یا «دسته‌ی پیش‌فرض کانال» | کم — یک‌باری؛ ولی 5٬700 candidate با «بدون دسته» تا مداخله گیر می‌کنند |
| Watcher/Worker/Watchdog | روشن‌کردن سوییچ‌ها (۳ دکمه) | طراحی: قابلیت‌های تازه محافظه‌کارانه خاموش شروع می‌شوند | یک سوییچ «حالت خودکارسازی کامل» که همه را یکجا روشن کند + صفحه‌ی سلامت | کم — یک‌باری |
| Channel | افزودن کانال + ساخت پروفایل | در scope هدف کاربر (دو قدم مجاز) | — | — |
| Sessions | «ادامه پردازش» / Resume / Retry / Execute Action / Poll Bot / Match / Download / Media / Product / Validate / Chain Step / Chain Reset (12 endpoint AJAX) | ابزارهای دستی/دیباگ — همه‌ی این stateها در `next_stage` پوشش Worker را دارند، پس در حالت عادی لازم نیست‌اند؛ ولی وقتی خطای IPC/فایبر می‌آید، کاربر در عمل همین دکمه‌ها را می‌زند | تبدیل به «نمای عیب‌یابی»: فقط برای sessionهای NEEDS_REVIEW/DEAD_LETTER نمایش + «Retry همه‌ی retry‌پذیرها» گروهی | متوسط — هر کلیک = یک درخواست سنگین (phar + IPC) |
| Inbox | سه‌گانه‌ی Duplicate Protection (گزارش/ادغام/فعال‌سازی یکتا) — دکمه‌های `gs-dup-*` | dedup inbox فقط UNIQUE (peer,msg_id) است؛ duplicate‌های «همان فایل با msg_id دیگر» نیاز به ادغام دارد | ادغام خودکار در poll (اگر telegram_document_id تکرار شود → merge به ردیف قدیمی) | متوسط — تا اوست، داده تکراری در Inbox می‌ماند و Candidate‌های تکراری ساخته می‌شود |
| Dead letter / NEEDS_REVIEW | دکمه‌ی «بازگشت از صف مرده» / بررسی دستی | طراحی: خطای دائمی/ابهام نباید خودکار ادامه یابد | «بازتلاش گروهی» برای DEAD_LETTERهایی که دلیلشان TRANSIENT ثبت شده + هشدار تجمعی | کم — تعدادش باید کم باشد |
| Recognition/Channel Insight | (بدون کران — دکمه‌ای) | module بدون کران است | اگر قرار است «شناخت کانال» خودکار بماند، یک tick ارزان با budget | کم |

### 1.3 شکاف‌های خودکارسازی که اصلاً دکمه ندارند

| شکاف | توضیح | اثر |
|---|---|---|
| **WP-Cron وابسته به ترافیک** | روی هاست اشتراکی بدون crontab خارجی، همه‌ی کران‌ها فقط هنگام بازدید کاربر اجرا می‌شوند | اگر ۲ ساعت کسی سایت را باز نکند، **کل پ이프‌لاین خواب است** — بزرگ‌ترین شکاف خودکارسازی |
| **`scan_limit` flag بی‌اثر (dead flag)** | flag تعریف شده (`flags.php:62`) ولی هیچ کدی آن را نمی‌خواند؛ سقف روزانه‌ی اسکن عملاً وجود ندارد (فقط per-tick budget در `pump_segments`) | اسکن می‌تواند هر ۳۰ دقیقه برای همه‌ی کانال‌ها Scan Run کامل راه بیندازد |
| **fallback_to_legacy خاموش** | در Chain Init اگر گره مبدأ executable نباشد، `chain_mode` به legacy می‌خورد و Session در SCANNED می‌ماند — فقط یک Event log؛ هیچ alert یا state مشخصی نیست | «SCANNED / No progress» (مشاهده‌شده شما) — کاربر باید دستی بفهمد چرا (بخش 6) |
| **نداشتن لاین دانلود موازی** | دانلود ۵۶۰ ثانیه‌ای داخل تیک Worker می‌چرخد و budget ۲۴ ثانیه را شکسته (budget فقط بین sessionها چک می‌شود) | ۱ دانلود = کل تیک بلوکه؛ سایر sessionها ۵+ دقیقه منتظر |
| **دو صف انتشار موازی** | `sti_queue_tick` (legacy، هر ۱ دقیقه) هنوز زنده است و کنار `sti_gs_publish_tick` کار می‌کند | اگر هر دو روی همان محصولات کار کنند: رقابت/تکرار (باید مطمئن شوید legacy queue خالی است) |

---

## 2) مشکل №۲ — Memory Explosion (خط 172 class-sti-mtproto.php)

### 2.1 دقیقاً چه اتفاقی در خط 172 می‌افتد

```
class-sti-mtproto.php:172  →  require_once self::phar_path();   // madeline81.phar = 19,499,523 بایت
```

ارقام خطای شما دقیقاً این را می‌گویند:

| مقدار خطا | معنی |
|---|---|
| `tried to allocate 19499555 bytes` | 19,499,555 = **حجم دقیق phar + 32 بایت** — PHP هنگام `require` یک phar، کل بایگانی را یکجا می‌خواند |
| `allocated 108003328 bytes` | پروسه **قبل از require** به ~103MB رسیده بود |
| جمع | ~122.5MB + overhead کامپایل → رد شدن از `memory_limit` (احتمالاً 128M) |

یعنی OOM **در لحظه‌ی لود موتور** است، نه در دانلود. دانلودها استریم به دیسک هستند (بخش 2.2) و مقصر نیستند.

### 2.2 چرا پروسه 103MB شده بود — جدول مصرف حافظه (تخمین بر مبنای هاست اشتراکی)

| Function | Current Memory (تخمین) | Peak Memory (تخمین) | Expected/Target | Root Cause | Fix |
|---|---|---|---|---|---|
| WP core + Woo + قالب + افزونه‌های دیگر | 50–90MB | — | 50–70 | بایس‌لاین طبیعی سایت سنگین | (خارج از کنترل افزونه؛ ولی هدف‌گذاری 128M برای کل پروسه غیرواقعی است) |
| کامپایل سورس خودِ این افزونه (~250 فایل PHP) | +5–15MB | — | یک‌باری | `require_once` همه‌ی includes در هر درخواست | قابل‌قبول؛ پایش شود |
| **`require_once` phar (خط 172)** | +19.5MB (یک‌تکه) | +40–70MB (کامپایل تدریجی کلاس‌های vendor هنگام autoload) | <19.5MB یک‌تکه | **فرمت phar**: کل بایگانی یکجا خوانده می‌شود | (a) `memory_limit` را **قبل از require** بالا ببرید؛ (b) phar را به‌جای require مستقیم، autoload سبک‌تر لود کنید — نیاز به بیلد اختصاصی/تغییر معماری |
| `engine_healthy()` | +19.5MB و کامپایل | همان | ≈0 | **برای چکِ `class_exists` کل phar کامپایل می‌شود!** ۴ caller: صفحه‌ی telegram (admin)، install، `ajax_status` (رفرش پنل) ×2 | نتیجه را در option با TTL (مثلاً 1h) کش کنید؛ یا فقط stub + size + نسخه را چک کنید |
| ساخت `new API()` (session load + settings) | +10–30MB | +50MB | 20–40 | لود session + ساخت event loop | یک client در هر درخواست (داریم)؛ `stop_client()` در پایان (داریم — ولی برای IPC کافی نیست، بخش 4) |
| global_poll (۳۰–۶۰ getHistory) | +5–20MB per call، رها بعد از normalize | 30–60MB اوج هم‌زمانی | <20 | آبجکت‌های Message سنگین Madeline (entities/reply_markup) | guard 45s داریم؛ اوج حافظه کران **ندارد** — اگر OOM مکرر شد: limit پیام‌ها یا دو-pass (اول docs، رها) |
| دانلود فایل | تقریباً ثابت (چند MB) | ثابت | ثابت | **استریم به دیسک** (`downloadToFile`) — RAM نیست | ✅ مشکل نیست |
| Product Builder / تصویر | +20–100MB (GD) | تا سقف `wp_raise_memory_limit('image')` (128–256M) | <150 | پردازش تصویر با GD | resize پیش از پردازش؛ یا limit جدا |
| **IPC worker (پروسه‌ی جدا)** | پروسه‌ی مستقل 50–150MB | — | 1 worker زنده | worker یتیم **باقی می‌ماند** (بخش 4) | مدیریت worker (بخش 4.4) |

### 2.3 ریشه‌ی دقیق OOM شما (ترکیب سه عامل)

1. **ترتیب غلط کد**: `ini_set('memory_limit','512M')` در `client()` **بعد از** `load_engine_phar()` است (`mtproto.php:502` require ← `:516-518` افزایش). اگر require OOM کند، افزایش هرگز اجرا نمی‌شود. **اصلاح = جابه‌جایی دو خط قبل از require.**
2. **بایس‌لاین 103MB**: یعنی درخواستی که OOM خورد، از قبل کار سنگین کرده بود (صفحه‌ی ادمین با AJAXهای پیاپی، یا همان تیک Worker با چند operation) — phar «آخرین شاخ» بود.
3. **`engine_healthy()`**: هر رفرش پنل Telegram (بعد از 90 ثانیه) کل phar را برای یک `class_exists` کامپایل می‌کند — روی پروسه‌ای که 100MB مصرف کرده، این یک OOM تضمین‌شده است.

### 2.4 سؤال‌های شما، مستقیم

- «آیا کل فایل در RAM بارگذاری می‌شود؟» → **بله، فقط برای require phar** (19.5MB یک‌تکه). دانلود فایل تلگرام نه.
- «Telegram Download Stream نیست؟» → دانلود Telegram **استریم است** (downloadToFile مستقیم به دیسک) — مقصر OOM نیست.
- «Buffer دوبل ساخته می‌شود؟» → در دانلود خیر. در `file-storage.php:710` یک `file_get_contents` روی فایل temp وجود دارد (آپلود FTP) — برای فایل‌های بزرگ (چند GB) **بله، buffer کامل در RAM**؛ اگر OOM بعد از دانلود می‌بینید، این است (تکمیل: chunked upload).
- «serialize/unserialize سنگین؟» → `payload` inbox (LONGTEXT با raw پیام) read/write می‌شود ولی نه serialize کل‌ساز محسوس.
- «object leak در MadelineProto؟» → داخل پروسه: خیر (referenced می‌ماند تا پایان request). **بین پروسه‌ها: بله — IPC workerهای یتیم** (بخش 4).

---

## 3) مشکل №۳ — Fiber Runtime Errors (`Must call resume() or throw()`)

### 3.1 منبع دقیق خطا (شواهد از داخل phar)

متن خطا در phar پیدا شد (offset 15612187) — این یک check داخلی **Amp/Revolt event loop** است:

```php
public function suspend(): mixed {
    ...
    if ($this->pending) {
        throw new \Error('Must call resume() or throw() before calling suspend() again');
    }
```

یعنی: **fiber اصلی event loop دو بار بدون resume وسط suspends شده.** این خطا «استثنای شبکه تلگرام» نیست؛ علامتِ **خراب‌شدن event loop داخل همان درخواست** است.

### 3.2 مکانیزم خرابی (چرا این‌جا می‌آید)

زنجیره‌ی منطقی، با شواهد از کد + phar:

1. یک فراخوانی MadelineProto (مثلاً `getHistory`) روی مسیر **IPC** رد می‌شود (بخش 4).
2. در میانه‌ی round-trip IPC خطا می‌آید (سوکت مرده / timeout 30s callback: `Ipc\ServerCallback` watcher 30 ثانیه‌ای) یا پروسه‌ی web توسط هاست SIGTERM/timeout می‌شود (FPM `request_terminate_timeout` — `set_time_limit` آن را لغو نمی‌کند).
3. Amp loop در وضعیت `pending` باقی می‌ماند (هیچ‌کس resume نکرده).
4. **هر فراخوانی Madeline بعدی در همان درخواست** دوباره suspend می‌خواهد → `Must call resume() or throw()`.
5. `install_loop_guard()` / `harden_runtime()` فقط **لاگ** می‌کنند و متوقف نمی‌کنند → کد ادامه می‌دهد و همه‌ی فراخوانی‌های بعدی هم همان خطا را می‌دهند.

### 3.3 نقشه‌ی کامل (Source / Frequency / Impact / Recovery / Permanent Fix)

| Source | کدام مسیر | Frequency (تخمین) | Impact | Recovery فعلی | Permanent Fix |
|---|---|---|---|---|---|
| **IPC round-trip شکسته** (سوکت یتیم/مرده) | همه‌ی RPCها از جمله download (بخش 4) | بالا — هم‌زمان با outage‌های IPC | کل عملیات آن request می‌میرد؛ بعدی‌ها هم‌زنجیره | فقط guard زمان (کران) + retry بعدی تیک (backoff) | **Circuit-breaker**: بعد از اولین خطای fiber/IPC در request، دیگر MTProto call نشود — مستقیم `next_retry_at` + event. و خود IPC را پایدار کنید (بخش 4.4) |
| **SIGTERM/timeout هاست وسط fiber** (FPM kill) | download 560s / global_poll 45s | متوسط — هر request بلندتر از `request_terminate_timeout` | کار نیمه (قفل تا TTL)؛ اگر زنده ماند → خطای fiber | TTL قفل + Recovery watchdog + rewind در advance_one | **جداکردن کارهای بلند از web-request** (بخش 8/CR-3): دانلود = event مستقل با budget خودش؛ فشرده‌کردن tickها |
| **کشتن worker IPC** (OOM هاست / process reaper) | download (بلندترین operation) | متوسط | همان بالا | — | مدیریت worker (4.4) + پایش `madeline.log` |
| `CHAIN_HISTORY_READ_FAILED` | getHistory در poll | کم (از 10.9.2) | دیگر fatal نیست | 10.9.2: fallback به Inbox + CHAIN_WAITING (بدون شمارش timeout) — **درست طراحی شده** | اگر تکرارش مکرر شد → نشانه‌ی همان IPC (بخش 4) |

### 3.4 آیا هنوز مسیر مستقیم به getHistory/getDialogs/download/start_bot هست؟

**بله — ولی همه از طریق wrapperهای `STI_MTProto`** (خود Chain Engine مستقیم به phar نمی‌زند). همه‌ی مسیرهای داغ guard دارند:

| مسیر | guard | وضعیت |
|---|---|---|
| `global_poll` (getDialogs + ۲۵–۳۰ getHistory) | 45s | ✅ |
| `recent_peer_messages` (getHistory) | 25s | ✅ |
| `advance` → Node_Processor (startBot/getBotCallbackAnswer/send/join) | 60s | ✅ |
| `chat_info` | 15s | ✅ |
| `find_photo_near` | 20s | ✅ |
| download (5+3 روش × 3 attempt) | 560s/each | ✅ (ولی root cause IPC را نمی‌دوازد — بخش 4) |
| `auth_state` / `account_info` (پنل ادمین) | **بدون guard** (کش 90s) | ⚠️ یک فراخوانی RPC در هر رفرش پنل |
| اسکنر (pump_segments) | per-tick budget | ✅ |

نتیجه: **مسیر مستقیمِ محافظت‌نشده وجود ندارد**؛ مشکل «چه» نیست، «چرا» است (IPC + kill هاست).

---

## 4) مشکل №۴ — `downloadToFile/ToDir/ToStream: The endpoint does not exist`

این مهم‌ترین بخش گزارش است، چون کد افزونه **اشکال‌زدن غلط** انجام داده و اصلاحات بعدی را به مسیر نادرست می‌برد.

### 4.1 جدول درخواستی

| مورد | پاسخ |
|---|---|
| Expected API (فرض کد افزونه) | MadelineProto کلاسیک in-process: `downloadToFile/ToDir/ToStream` متدهای واقعی هستند و با rename بین نسخه‌ها مشکل می‌افتند (فرض 10.7.x/7.0.0) |
| Actual API (آنچه داخل phar است) | **MadelineProto v8 با معماری IPC**: `API::downloadToFile(...)` → `__call('downloadToFile', $wrapper)` → **fwd به worker فرآیندِ جدا (`madeline-ipc`) از طریق سوکت Unix/FIFO** (استخراج‌شده از phar: `Ipc\EventHandlerProxy`/`IpcCapable`) |
| Installed Version | phar بیلد **©2016-2025** با alias داخلی **`madeline84-v8.phar`** (یعنی **v8 برای PHP 8.4**) — **نه** v9. stub آن: `if (PHP < 8.2) die("MadelineProto requires at least PHP 8.2.")` |
| Breaking Change | تغییر معماری از in-process به **IPC-IPC-by-default در web SAPI**: همه‌ی RPCها (نه فقط دانلود) از طریق پروسه‌ی `madeline-ipc` که با `proc_open` از داخل درخواست spawn می‌شود می‌روند |
| Required Fix | **اصلاح «نام متد» هیچ فایده‌ای ندارد.** باید (a) وضعیت IPC را پایش/بازیابی کنید (4.4)، (b) نسخه‌ی phar را صریح مدیریت کنید (4.5)، (c) کدِ cascade و commentها را با واقعیت IPC هم‌تراز کنید |

### 4.2 مکانیزم دقیق خطا (شواهد از داخل phar)

```
Amp\Ipc\connect($uri):
    if (!\file_exists($uri)) {
        throw new \RuntimeException("The endpoint does not exist!");   // ← همان خطای شما
    }
```

و در کلاینت Madeline (offset 4291708):

```php
$socket = connect($ipcPath);
...
if ($e !== 'The endpoint does not exist!') { Logger::log(...); }
```

یعنی خطا = **فایل سوکت IPC در لحظه‌ی فراخوانی وجود نداشت.** همه‌ی سه متد دانلود (و عملاً هر RPC) از همین یک مسیر می‌گذرند — برای همین **سه خطا با یک پیام** دیدید.

### 4.3 چرا «فقط گاهی» و «فقط دانلود» به نظر می‌رسد

- worker IPC **زنده است** تا زنده باشد همه‌چیز کار می‌کند (به همین دلیل «دانلود فایل انجام می‌شود» را هم دارید).
- دانلود **بلندترین** operation است (چندین ثانیه تا چند دقیقه) → احتمال برخورد با: (الف) kill شدن worker توسط process manager هاست، (ب) اتمام مهلت هاست برای request میزبان، (ج) پاک‌شدن سوکت از tmp — در همین operation زیاد است.
- وقتی worker می‌میرد، **همه‌ی** فراخوانی‌های بعدی تا spin-up worker جدید هم همین خطا را می‌دهند (شامل getHistory → همان CHAIN_HISTORY_READ_FAILEDها).

### 4.4 شواهد از داخل خودِ کد افزونه (تأیید مستقل)

1. `ajax_reply()` (`mtproto.php:2136`): توضیح توسعه‌دهنده: *«مهم: قبل از ارسال پاسخ، client را متوقف کن تا worker پس‌زمینه‌ی MadelineProto (IPC) زنده نماند و حافظه‌ی هاست را نخورد. بدون این، هر باز شدن صفحه/درخواست یک worker جدید جمع می‌کند و بعد از چند بار، **هاست OOM می‌شود و همه‌ی AJAXها با «خطای ارتباط با سرور» fail می‌شوند — دقیقاً همان مشکلی که دیده شد.»*** ← یعنی leak worker IPC **مشاهده و تجربه** شده و stop_client پیش از پاسخ AJAX، درمان موقتی آن است.
2. `cleanup_orphan_workers()` (`mtproto.php:2177`): `pkill -f madeline-ipc` برای «workerهای به‌جامانده که حافظه بگیرند» — **ولی هیچ‌جا صدا زده نمی‌شود** (dead code). ⚠️ و خود `pkill -f madeline-ipc` روی هاست اشتراکی **بدون scope است** — اگر اجراء شود، workerهای IPC **سایت‌های دیگرِ هاست** را هم می‌کشد.

### 4.5 ریسک‌های پنهان دیگرِ نسخه‌ی phar

| ریسک | توضیح |
|---|---|
| **PHP 8.1 می‌میرد** | `engine_filename()`: `PHP >= 8.1 → madeline81.phar`. اما stub این phar **PHP ≥ 8.2** می‌خواهد. روی هاست PHP 8.1: require می‌شود و `die("MadelineProto requires at least PHP 8.2")` — از خارج شبیه «بارگذاری موتور ناموفق/خاموش». |
| **نام‌گذاری دروغ** | فایل «madeline81» در واقع بیلد 8.4-v8 است؛ commentها آن را «v9» معرفی می‌کنند. هر فرض بر روی نسخه (مانند `Settings\RPC::setFloodTimeout`) method_exists-guard دارد و بی‌صدا رد می‌شود — یعنی بخشی از «hardening 10.8.3» ممکن است عملاً روی این بیلد **فعایت نکند** بدون اینکه بدانید. |
| **اسم فایل ↔ alias** | comment کد می‌گوید «phar به نام خودش self-reference دارد» — برای این بیلد درست نیست (alias با `Phar::mapPhar('madeline84-v8.phar')` داخل stub ثابت است و مستقل از نام فایل). پس ریسک rename کم است؛ ریسک واقعی **PHP version** است. |
| **spawn به proc_open وابسته است** | اگر `proc_open` در `disable_functions` باشد یا PHP binary در PATH فPM نباشد (`locateBinary()` → `Could not locate PHP executable binary`)، IPC هرگز بالا نمی‌آید و **همه** RPCها همین خطا را می‌دهند. |

### 4.6 مسیر اصلاح (بدون کدنویسی در این مرحله — فقط استراتژی)

1. **تشخیص روی هاست** (یک‌بار): آیا `proc_open` آزاد است؟ `php` در PATH فPM هست؟ مسیر سوکت IPC کجاست؟ (`madeline.log` خط «Starting process with …» را می‌نویسد)؛ چند پروسه‌ی `madeline-ipc` زنده است؟
2. **Health-probe + self-heal در `STI_MTProto`**: قبل از هر عملیات، `file_exists(ipc_socket)` + `pgrep` scoped؛ اگر سوکت مرده/یتیم → kill **فقط** worker همان session path (regex روی آرگومان session، نه pkill سراسری) → اجازه spin-up مجدد → یک retry.
3. **تصمیم صریح معماری**: یا IPC را کامل بپذیرید (worker manager + monitor + restart) یا اگر بیلد v8 اجازه می‌دهد (تنظیمات IPC در Settings هست: `setIpcPath` — ۴ hit در phar)، سوکت را به مسیر **پایدار و قابل‌پایش** (پوشه‌ی base_dir افزونه، نه tmp) بچسبانید.
4. **pin + verify نسخه**: هنگام نصب/نصب مجدد، stub phar را بخوانید (PHP required + version) و در سیستم‌چک نمایش دهید؛ روی mismatch خطای صریح بدهید.
5. **cascade دانلود را بازنگری کنید**: 5×3 تلاش با sleep برای یک root cause واحد فقط log را گندل می‌کند؛ به‌جایش: یک «IPC preflight» + 1 retry واقعی (refresh file_reference).

---

## 5) مشکل №۵ — Execute Action Failures (نقشه‌ی مسیر)

```
Button Resolver (legacy) ─
                          ├─► Action_Executor::execute ─► MTProto (press_button / start_bot)
Chain advance ─► Node_Processor ─┘                                   │
                                                                     ▼
                                                    guard 60-80s ← IPC round-trip
```

مسیرهای شکست و state نهایی:

| شکست در لحظه‌ی Execute | مکانیزم | state/برداشت |
|---|---|---|
| IPC سوکت مرده | `endpoint does not exist` از worker proxy | `mark_failed` → CHAIN_FAILED → retry gate (step.attempts ≤3) → **NEEDS_REVIEW** (نه WAITING_BOT). اگر legacy: ERROR_CLICK → retry با backoff |
| fiber خراب (بعد از خطای قبلی در همان request) | `Must call resume()` | همان بالا — **ولی باقی‌مانده‌ی request آلوده است** (بخش 3)؛ عملیات‌های بعدی تیک هم ممکن است قربانی شوند ← دلیل اینکه «چند session هم‌زمان خراب می‌شوند» |
| `getBotCallbackAnswer` خطای واقعی (query_id_invalid / timeout) | soft-ok یا fallback start_bot_dialog (deep link) | soft_ok → CHAIN_WAITING؛ fallback شکست → retry |
| flood | `STI_GS_Retry::flood_wait_until` → next_retry_at | بدون مصرف attempts — ✅ |
| deadline 60s | STI_GS_Deadline_Exception → WP_Error | CHAIN_FAILED → retry |

**چرا WAITING_BOT:** فقط اگر action **با موفقیت dispatch شده** (clicked_at پر شد) ولی پاسخ معتبر در پنجره‌ی 900s نرسیده.
**چرا CHAIN_FAILED:** هر شکست process/timeout — با retry bound.
**چرا NEEDS_REVIEW:** سقف step-retry (3) یا ابهام/بدون evidence در recover().
**نکده‌ی کلیدی:** در حالت IPC-outage، sessionها به ترتیب: 1 retry سریع (120s) → 240 → 480 → 960 → بعد از 5 attempt **6 ساعت**. یعنی یک outage دو ساعته‌ی IPC، attemptهای همه‌ی sessionها را «می‌خورد» و آن‌ها را برای ساعت‌ها از صف بیرون می‌گذارد — از این نظر هم IPC fix اثر چند برابر دارد.

---

## 6) مشکل №۶ — Chain Init Stalls (SCANNED گیر می‌کند)

شرایط کاملی که Session در SCANNED می‌ماند (به ترتیب احتمال در عمل):

| # | شرط | مکانیزم | چه دیده می‌شود |
|---|---|---|---|
| 1 | **کران اجرا نمی‌شود** (بدون ترافیک/بدون crontab خارجی) | WP-Cron فقط با بازدید fire می‌شود | هیچ Event جدیدی؛ `locked_until` خالی |
| 2 | **Worker خاموش / safe_mode / halt** | guardهای بالای tick() | بدون هیچ log (ساکت) |
| 3 | **`next_retry_at` در آینده** (backoff 5/10/20/40/80 min؛ بعد از 5 attempt → 6h) | handle_failure | `error_reason` پر، `attempts` بالا |
| 4 | **قفل دیگران** (TTL 45-90s) | claim() | «توسط worker دیگری پردازش می‌شود» — موقتی |
| 5 | **global mode = legacy** | `init()` → `skipped, no_progress, mode=legacy` | ساکت (فقط event در fallback) |
| 6 | **fallback_to_legacy**: message_pk ناموجود / raw_json خراب / گره مبدأ غیر-executable | `chain_mode=legacy` نوشته می‌شود ولی **state در SCANNED می‌ماند** و بعداً در resolver قدیمی می‌چرخد — اگر resolver هم نتواند، همانجا می‌ماند | event: «زنجیره فعال نشد؛ مسیر قدیمی ادامه می‌دهد: …» — **و بعد هیچ.** دقیقاً الگوی «Chain Init stopped / No progress» |
| 7 | **throughput**: batch=3 / interval≥300s / 1 bot-action/tick | نوبت‌دهی | progress بسیار کند (نه stall) |

**پاسخ به سؤالات فرعی شما:**
- «Lock آزاد نمی‌شود؟» → بله **موقتی** (TTL 45-90s) و از 10.9.2 Watchdog هم آزاد می‌کند. Stall دائمی با قفل توجیه نمی‌شود.
- «Queue گیر می‌کند؟» → بله اگر backoff/attempt (شماره 3) یا کران (شماره 1).
- «Worker رد می‌شود؟» → بله: `pick()` فقط stateهای غیر-terminal را می‌گیرد؛ SCANNED همیشه pick می‌شود مگر locked/retry.
- «Retry Counter اشتباه؟» → خیر — منطقش درست است (per-hop و session-level جدا)؛ ولی **یک outage بزرگ attemptها را مصرف می‌کند** (بخش 5).
- «Stage Mapping ناقص؟» → **بله، یک مورد واقعی:** `fallback_to_legacy` یک «mapping ناقص» نیست ولی **نتیجه‌اش** (SCANNED + legacy) یک dead-end بدون state مشخص است. پیشنهاد: وقتی fallback به legacy می‌رود و resolver هم «کار قابل‌اجرا ندارد»، state را روی NEEDS_REVIEW با دلیل بگذارید، نه SCANNED ساکت.

---

## 7) مشکل №۷ — Profile Refresh Cost

### 7.1 کوئری‌های واقعی (`STI_GS_Profile::run`)

```sql
-- به ازای هر keyword (match_mode=any):
INSERT INTO profile_items (profile_id, message_pk, matched_keyword, status, created_at)
SELECT :pid, m.id, :kw, 'available', :now
FROM messages m
WHERE m.channel_id = :ch
  AND ( m.text_raw LIKE %kw% OR m.button_summary LIKE %kw% OR IFNULL(m.file_name,'') LIKE %kw% )
ON DUPLICATE KEY UPDATE matched_keyword = matched_keyword;
```

### 7.2 جدول هزینه (تخمین — Inventory ≈ 14٬453 ردیف، 8 پروفایل)

| Query | Rows Examined (تخمین) | Execution Time (تخمین، MySQL کوچک) | Index Recommendation |
|---|---|---|---|
| یک `LIKE` با wildcard پیشِ‌انداز روی text_raw | ≈ تعداد پیام‌های همان کانال (14k ÷ تعداد کانال‌ها) — **فول اسکن محدوده‌ی کانال** | 20–150ms/کوئری | LIKE با `%kw%` **هیچ** ایندکس B-tree را استفاده نمی‌کند؛ ایندکس `channel_id` (موجود) فقط محدوده را محدود می‌کند. برای واقعی‌سازی: (a) FULLTEXT ایندکس (InnoDB) + MATCH AGAINST؛ (b) یا **معماری بهتر: فقط اسکن پیام‌های تازه** — `WHERE m.id > last_scanned_id` (اینکس PK) + LIKE فقط روی delta. این delta-approach هزینه را از O(کل جدول) به O(پیام‌های جدید) می‌برد و با اسکن افزایشی Watcher天然 هماهنگ است |
| N keyword × 8 پروفایل × 3 ستون LIKE | ×3N اسکن | 8 پروفایل × 5 کلمه = ~120 کوئری ≈ 2–20s CPU مجموع | delta-approach بالا + محدودسازی به یک بار در روز/با رشد (در Watcher هست: 6h — ولی **دکمه‌ی Refresh دستی این throttling را دور می‌زند** و با هر کلیک 120 کوئری می‌زند) |
| `INSERT...SELECT ... ON DUPLICATE` | writes به profile_items | gap/row locks در REPEATABLE-READ؛ با 14k insert تکراری در هر round | UNIQUE (profile_id,message_pk) (موجود) ✅ — تکرارها ارزان‌اند ولی **هر round کل را لمس می‌کند** → با delta-approach حل می‌شود |
| شمارنده‌ها (COUNT(*) available / no_category / ready / backlog) | کوچک (join روی profile_items) | <10ms | ✅ قابل‌قبول |
| `pick()` Worker: `ORDER BY (attempts >= 5), priority DESC, id` | کل pipeline (فیلتر state/retry) | <5ms با چند صد ردیف؛ با 50k ردیف 10–50ms + filesort | expression-based ORDER ایندکس نمی‌خورد؛ اگر صف بزرگ شد: ستون محاسبه‌شده `is_over_cap` یا ORDER ساده‌تر (id) + LIMIT |
| `SELECT COUNT(*) FROM messages` (throttle Watcher) | کل جدول (COUNT) | 5–30ms با 14k (InnoDB: اسکن کامل برای COUNT(*)) | ذخیره شمارنده در option هنگام insert (scanner) به‌جای COUNT |

### 7.3 جمع‌بندی #7

خودِ LIKE‌ها روی 14k ردیف «قاتل» نیستند (ثانیه‌ای واحد)؛ خطر واقعی **هم‌زمانی** است: Watcher (profile refresh + spawnکران‌های اسکن) + Worker (global_poll) + Publish + legacy queue **در یک request کران** پشت‌سرهم، و در requestهای کران موازی **هم‌زمان** اجرا می‌شوند (بخش 8). روی MySQL کوچکِ هاست اشتراکی این ترکیب = saturation چند ثانیه تا چند دقیقه (بخش 9).

---

## 8) مشکل №۸ — WP-Cron Pressure

### 8.1 نقشه‌ی کامل کران‌ها (بعد از 10.9.2)

| Hook | بازه | self-interval guard | کار |
|---|---|---|---|
| `sti_queue_tick` | **هر ۱ دقیقه** (legacy) | — | صف انتشار legacy + کارهای هسته |
| `sti_gs_auto_worker` | هر ۵ دقیقه | 300s (option) | پ이프‌لاین sessionها |
| `sti_gs_publish_tick` | هر ۵ دقیقه | — (یک محصول/tick) | انتشار محصولات |
| `sti_gs_watchdog` | هر ۱۵ دقیقه | — | قفل/گام‌های یتیم |
| `sti_gs_channel_watcher` | هر ۳۰ دقیقه | 1800s (option) | اسکن→پروفایل→Session |
| `sti_gs_scan_worker` / `scan_segments` | on-demand (single events) | per-tick budget | اسکن |
| `sti_ci_worker` / `sti_goldtel_worker` / `sti_goldtel_dispatch_worker` / `sti_bot_modes_worker` | on-demand | — | مسیرهای legacy دیگر |
| `sti_cleanup_cron` | روزانه | — | پاکسازی لاگ |

10.9.2 یک برد بزرگ گرفت: **بررسی زمان‌بندی فقط در admin/DOING_CRON** (دیگر هر بازدیدکننده 8 query اضافه نمی‌کند). ✅

### 8.2 آیا Cron Storm هست؟ — بله، سه منبع

1. **`spawn_cron()` در حلقه‌ی Watcher** (`channel-watcher.php:274`): برای **هر کانال** که Scan Run شروع می‌شود، یک `spawn_cron()` — یعنی تا 20 **اجرای فوریِ کران کامل** در هر چرخه‌ی 30 دقیقه‌ای. هر spawn = یک request `wp-cron.php` که **کل صف به‌سررس** (همه‌ی کران‌های بالا) را اجرا می‌کند. ← این بزرگ‌ترین amplifier است.
2. **WP-Cron lock ندارد**: دو بازدیدکننده هم‌زمان = دو request کران موازی = اجرای تکراری eventهای یکسان. guardهای interval (get_option→time→update_option) **TOCTOU** دارند (دو worker هر دو بخوانند، هر دو بگذرند). اثر: تکرار global_poll (کش 60s نصفه جبران می‌کند)، تکرار pick (claim جلوی double-advance را می‌گیرد ولی کار تکراری دارد).
3. **یک request = یک صف**: در هر اجرای کران، eventها **پشت‌سرهم** اجرا می‌شوند: اگر Worker tick (تا 240s + دانلود 560s!) اول صف باشد، Publish tick و بقیه **پشتش صف می‌کشند** — انتشار محصول ۱۵+ دقیقه تأخیر می‌گیرد و خودِ آن request طولانی روی FPM هاست اشتراکی **child پروسه‌ها را بلوکه** می‌کند (بخش 9).

### 8.3 آیا چند Worker همزمان اجرا می‌شوند؟

- داخل یک request: نه (تک‌تره).
- بین requestهای کران موازی (ماده 8.2-2): **بله، تا تعداد requestهای هم‌زمان**.
- محافظ: `claim()` اتمی ✅ (double-advance یک session ممکن نیست) — ولی **کار موازی** (دو global_poll، دو client/IPC) ممکن است.

### 8.4 آیا Lockها قابل‌اتکایند؟

- Session lock: ✅ اتمی، TTL، مالکیت‌محور release — طراحی خوب.
- Cron-level lock: ❌ **ندارد**. پیشنهاد: یک lock سراسری برای اجرای کران افزونه (option/DB با claim الگوی همان Session) — فقط یک request کران در هر زمان اجازه‌ی اجرای GS crons را داشته باشد؛ بقیه رد کنند.
- ⚠️ ناسازگاری کوچک: `deactivation_hook` چهار hook GS (`auto_worker/publish_tick/watchdog/channel_watcher`) را **clear نمی‌کند** — بعد از غیرفعال‌شدن افزونه، eventها در صف فیک‌کران می‌مانند (بی‌خطر ولی آلودگی).

---

## 9) مشکل №۹ — Site Availability («چند دقیقه از دسترس خارج، بعد درست می‌شود»)

اولویت‌بندی با شواهد:

| # | علت | Probability | Evidence | Impact | Fix |
|---|---|---|---|---|---|
| 1 | **جمع‌شدن IPC workerها → OOM هاست** (host-level، نه process-level) | **بسیار بالا** | comment خودِ `ajax_reply`: «هر درخواست یک worker جدید جمع می‌کند… هاست OOM می‌شود و همه AJAXها fail — **دقیقاً همان مشکلی که دیده شد**»؛ وجود `cleanup_orphan_workers` (که صدا زده نمی‌شود) | کل هاست (چند سایت) چند دقیقه درمی‌رود؛ بعد workerها می‌میرند/پاک می‌شوند و برمی‌گردد — **الگوی دقیق گزارش شما** | مدیریت worker: scope‌شده kill یتیمان در Watchdog (15m) + سقف تعداد + health probe (4.4) |
| 2 | **request کران طولانی (تا ~15-20 دقیقه) بلوکه‌کردن FPM children** | بالا | Worker tick 240s + دانلود 560s در **همان** request که کران است؛ hasted اشتراکی 1-5 child دارد | تا آزادشدن child، درخواست‌های سایت صف می‌کشند/می‌افتند | جداکردن دانلود به event مستقل (CR-3) + سقف wall-time هر کران < 60s |
| 3 | **crash process در 128M** (phar + بایس‌لاین 103MB) | بالا (در صفحات ادمین/تیک‌های سنگین) | OOM خط 172 شما؛ ترتیب غلط افزایش memory | یک child می‌میرد (500/timeout)؛ اگر چند child پشت‌سرهم → «از دسترس خارج» | CR-2 (مخفی‌سازی قبل از require + کش engine_healthy) |
| 4 | **MySQL saturation** (LIKE×N + کران‌های موازی + connection) | متوسط | بخش 7/8؛ Watcher خود توضیح داده: «MySQL هاست اشتراکی را می‌خواباند» | AJAXها کند/timeout می‌شوند (نه قطع کامل) | delta-scanning + cron lock + throttling |
| 5 | **Cron storm** (spawn_cron ×N + اجرای تکراری) | متوسط-بالا (هر 30 دقیقه، موقتی) | بخش 8.2 | اسپایک CPU/PHP چند دقیق‌ه‌ای هم‌زمان با چرخه‌ی Watcher | حذف spawn_cron از حلقه (یک spawn در کل run) + cron lock |
| 6 | **Deadlock** | پایین | claim/release مالکیت‌محور و یک‌جهته؛ الگوی lock-ordering واحد | — | پایش (بدون اقدام) |
| 7 | **Telegram runtime freeze داخل fiber** | متوسط (به‌عنوان محرک موارد بالا) | بخش 3/4 — guard کران دارد ولی kill هاست کران ندارد | کار نیمه + worker یتیم (تغذیه‌کننده‌ی مورد 1 و 2) | CR-1 + CR-3 |
| 8 | **PHP process limit هاست** | متوسط | workerهای IPC = پروسه‌های زنده‌ی اضافه | spawn proc_open جدید ممکن است رد شود → IPC outage (چرخه‌ی معیوب با مورد 1) | مدیریت worker |

**الگوی «چند دقیقه و بعد خودبه‌خود درست شدن» = امضای مورد 1 و 2** (OOM host / بلوکه‌شدن children)، نه یک bug منطق.

---

## 10) مشکل №۱۰ — Duplicate Processing

### 10.1 نقشه‌ی Unique Constraints واقعی (DB)

| جدول | Unique | پوشش |
|---|---|---|
| `channels` | `identifier` | ✅ کانال تکراری نمی‌شود |
| `messages` (Inventory) | `(channel_id, message_id)` | ✅ پیام تکراری نمی‌شود (اسکن هم `duplicate_messages` می‌شمارد) |
| `profile_items` (Candidates) | `(profile_id, message_pk)` | ✅ هر پروفایل هر پیام را یک‌بار |
| `pipeline_items` (Sessions) | `message_pk` | ✅ **یک Session به ازای هر پیام مبدأ — حتی اگر دو پروفایل یک پیام را candidate کنند** |
| `candidates` (bot candidates) | `(session_id, inbox_id)` | ✅ |
| `handoff_steps` | `(session_id, step_no)` | ✅ (از 10.8.3) |
| `sti_bot_inbox` | `(peer, msg_id)` | ✅ |
| `segments` | `(channel_id, segment_index)` | ✅ |
| `profiles` | **هیچ unique (channel_id, name) ندارد** | ⚠️ |
| محصولات (WooCommerce) | **هیچ unique روی SKU/file_code** | ⚠️ |

### 10.2 کجا هنوز Duplicate ممکن است

| محل | مکانیزم | شدت | راه‌حل |
|---|---|---|---|
| **Inbox: همان فایل با msg_idهای متفاوت** (ربات دوباره بفرستد/forward) | UNIQUE (peer,msg_id) رد می‌شود؛ ردیف جدید می‌سازد | **بالا** (همان 5 سند gbqcy28-مانند) | merge خودکار بر `telegram_document_id` در `record()`؛ تا آن‌وقت سه‌گانه‌ی دستی `gs-dup-*` تنها راه است |
| **Products: دو session با یک file_code** | `find_existing_product` یک pre-check است (TOCTOU): اگر Worker + دکمه‌ی دستی Product هم‌زمان، یا دو tick پشت‌سرهم قبل از ثبت SKU | متوسط | UNIQUE functionally روی SKU در جدول محصولات + adopt به‌جای ساخت (الگوی موجود `adopted` را گسترش دهید) |
| **Profiles تکراری دستی** | بدون unique (channel_id,name)؛ دو پروفایل با کلمات تکراری → دو Candidate برای یک پیام (Session یکی می‌شود — پس اثرش display و مصرف LIKE است، نه Session تکراری) | کم | unique اختیاری + هشدار |
| **شمارش «فایل‌های آماده» در پروفایل** | 10.9.2 فقط `available` می‌شمارد ✅ — ولی ردیف‌های `queued` در لیست پروفایل باقی می‌مانند (توضیح داده شده در کد) | کم (UX) | فیلتر نمایش یا برچسب |
| **Session برای پیام با file_code تکراری بین کانال‌ها** | UNIQUE روی message_pk (نه file_code) — درست است (دو کانال می‌توانند همان فایل را داشته باشند) | — | ✅ صحیح |

**جمع‌بندی #10:** dedup در سطح Session/Candidate/Inbox(پیام) **قوی** است؛ دو نقطه‌ی باز واقعی: **merge فایل تکراری در Inbox** (خودکار) و **SKU محصول** (TOCTOU).

---

# بخش نهایی — Top 10s + اولویت‌بندی

## A) Top 10 Critical Bugs

| # | Bug | RCA (یک خط) | P |
|---|---|---|---|
| C1 | `endpoint does not exist` روی همه‌ی RPCها/دانلود هنگام مرگ سوکت IPC | worker فرآیندی `madeline-ipc` بدون supervisor/health-check؛ خطا در `Amp\Ipc\connect` وقتی FIFO نیست | **P0** |
| C2 | OOM خط 172: require 19.5MB phar روی پروسه‌ی 103MB | افزایش memory_limit **بعد از** require + `engine_healthy()` که phar را برای class_exists کامپایل می‌کند | **P0** |
| C3 | نشت IPC workerها → OOM هاست → سقوط چند دقیقه‌ای سایت | workerها پس از پایان request یتیم می‌مانند؛ `cleanup_orphan_workers` dead code است | **P0** |
| C4 | fiber خراب برای کل request: `Must call resume()` و آلودگی فراخوانی‌های بعدی | event loop Amp در وضعیت pending می‌ماند؛ circuit-breaker وجود ندارد | **P0** |
| C5 | phar PHP 8.2-only ولی `engine_filename` آن را برای PHP 8.1 انتخاب می‌کند | بیلد «madeline84-v8» اشتباهاً «madeline81/v9» برچسب خورده | **P1** |
| C6 | `fallback_to_legacy` = SCANNED ساکت (dead-end بدون state/alert) | mapping ناقص: نه NEEDS_REVIEW نه پیام کاربر‌پسند | **P1** |
| C7 | `spawn_cron()` در حلقه‌ی Watcher (تا 20 spawn/run) | نفهمی اینکه spawn = اجرای **کل** صف کران | **P1** |
| C8 | `scan_limit` flag تعریف‌شده ولی بی‌کاربر (dead flag) | قابلیت نیمه‌کاره در 10.9.2 | **P2** |
| C9 | cascade دانلود: 18 attempt + sleep برای یک root cause؛ error misdirecting | عکسالعمل به ریشه‌ی اشتباه (rename متد) — در حالی که همه‌ی متدها یک مسیر IPC دارند | **P2** |
| C10 | guardهای cron TOCTOU + بدون cron-lock سراسری + deactivation hookها را clear نمی‌کند | فرض «فیک‌کران = تک‌نواخت» در PHP | **P1** |

## B) Top 10 Performance Problems

| # | Problem | تخمین اثر | P |
|---|---|---|---|
| P1 | یک request کران = اجرای صفی همه‌ی eventها (Worker 240s+دانلود 560s ← Publish 15+ دقیقه صف) | تاخیر انتشار + بلوکه‌کردن child | **P0** |
| P2 | بایس‌لاین حافظه + phar (103+19.5MB) در 128M | crash در ادمین/تیک‌های سنگین | **P0** |
| P3 | IPC workerها: RAM دائمی + connectionهای دائمی روی هاست | فشار دائمی | **P0** |
| P4 | `engine_healthy()` = کامپایل 19.5MB در هر رفرش پنل (بعد از 90s) | ادمین کند + OOM | **P1** |
| P5 | global_poll: 30-60 getHistory در هر اسکن (اوج 30-60MB؛ با cache 60s) | CPU/RAM تکی | **P2** |
| P6 | Profile LIKE اسکن (14k × N × 8) بدون delta؛ دکمه‌ی Refresh throttling ندارد | اسپایک MySQL | **P1** |
| P7 | `auth_state`/`account_info` RPC در هر رفرش پنل (guard ندارد) | IPC churn | **P2** |
| P8 | `file-storage.php:710` `file_get_contents` کل فایل temp برای FTP | OOM در فایل‌های بزرگ | **P1** |
| P9 | `pick()` ORDER با expression (filesort) + `COUNT(*) FROM messages` در throttle | رشد تدریجی با حجم | **P2** |
| P10 | تکرار کار در کران‌های موازی (دو global_poll/دو client) | ضایعه‌ی دوبرابر | **P1** |

## C) Top 10 Automation Gaps

| # | Gap | اثر بر هدف «فقط کانال + پروفایل» | P |
|---|---|---|---|
| A1 | WP-Cron وابسته به ترافیک (بدون crontab خارجی هیچ tick نمی‌افتد) | **خاموشی کامل** در شب/روزهای کم‌ترافیک | **P0** |
| A2 | throughput Worker: 3/tick، ≥300s، 1 bot-action/tick — بک‌لاگ 5٬785 ≈ ۲ ماه | هدف مقیاس محقق نمی‌شود | **P0** |
| A3 | دانلود داخل تیک Worker (لاین موازی ندارد) | 1 فایل = بلوک ۱۵+ دقیقه برای همه | **P0** |
| A4 | `default_category_id` شرط Watcher؛ auto-assign ندارد | 5٬700 candidate با «بدون دسته» منتظر | **P1** |
| A5 | `scan_limit` پیاده‌سازی نشده | سقف روزانه‌ی اسکن غیرواقعی | **P2** |
| A6 | SCANNED ساکت (fallback_to_legacy) — خودکارسازی «خاموش به نظر می‌رسد» ولی log ندارد | تشخیص مشکل به دستاند | **P1** |
| A7 | merge تکراری‌های Inbox دستی (سه‌گانه) | داده‌ی تکراری + Candidates تکراری | **P1** |
| A8 | دو صف انتشار موازی (legacy هر ۱ دقیقه + GS 5 دقیقه) | نیاز به تنظیم دستی برای جلوگیری از رقابت | **P2** |
| A9 | NEEDS_REVIEW/DEAD_LETTER: بدون «بازتلاش گروهی»/هشدار تجمعی | انباشته‌شدن سانس | **P2** |
| A10 | سوییچ‌های جدا (worker/watcher/watchdog/flags) بدون «حالت کامل» + بدون صفحه‌ی سلامت واحد | setup خطاپذیر | **P2** |

## D) Top 10 Stability Risks

| # | Risk | سناریوی شکست | P |
|---|---|---|---|
| S1 | IPC بدون supervisor (spawn/kill/stale) | هر outage = cascade خطا + مصرف attemptها (5×) + 6h | **P0** |
| S2 | بلوکه‌شدن FPM با request کران طولانی | سایت در دسترس نیست ۵-۲۰ دقیقه | **P0** |
| S3 | سقف حافظه process < phar + بایس‌لاین | 500های مکرر در ادمین/تیک | **P0** |
| S4 | بدون circuit-breaker برای loop خراب | آلودگی کل request بعد از اولین خطا | **P0** |
| S5 | `pkill -f madeline-ipc` بدون scope (اگر روزی صدا زده شود) | کشتن workerهای سایت‌های **دیگرِ** هاست | **P1** |
| S6 | cron بدون lock + spawn storm | اسپایک‌های دوره‌ای هر 30 دقیقه | **P1** |
| S7 | نسخه‌ی phar و PHP هاست (8.1/8.2 مرز) | تغییر PHP هاست = مرگ کامل موتور بدون هشدار واضح | **P1** |
| S8 | TOCTOU در guardهای interval | double-work (کم‌خطر، پرهزینه) | **P2** |
| S9 | file_get_contents کل فایل در storage | OOM با فایل‌های چندGB | **P1** |
| S10 | hookهای کران بعد از deactivate clear نمی‌شوند | آلودگی فیک‌کران | **P2** |

---

# اولویت‌بندی اصلاحات (P0/P1/P2/P3) + تخمین اثر

> اعداد «تخمین اثر» کیفی‌اند: ▲▲▲ = تغییر محسوس، ▲▲ = روشن، ▲ = مرئی

| ID | اصلاح | P | سرعت | حافظه | پایداری | خودکارسازی |
|---|---|---|---|---|---|---|
| **CR-1** | **IPC supervisor در STI_MTProto**: health-probe (سوکت زنده؟ worker زنده؟) + kill scope‌شده (regex روی session path — نه pkill سراسری) + spin-up مجدد + یک retry؛ + ثبت `endpoint does not exist` به‌عنوان نشانه‌ی IPC (نه متد)؛ + حذف/بازنگری cascade 18-tentative | **P0** | ▲▲ | ▲▲ (حذف retries بی‌جدا) | ▲▲▲ | ▲▲▲ |
| **CR-2** | **Memory**: (a) افزایش memory_limit **قبل از** require phar؛ (b) کش `engine_healthy()` در option (TTL 1h)؛ (c) چک stub phar (PHP required/version) با خطای صریح؛ (d) chunked upload به‌جای file_get_contents در storage | **P0** | ▲ | ▲▲▲ | ▲▲▲ | ▲ |
| **CR-3** | **Cron surgery**: (a) دانلود به **event مستقل** (`sti_gs_download_event`، budget خودش) خارج از tick عمومی؛ (b) cron-lock سراسری GS (claim الگوی Session)؛ (c) `spawn_cron` فقط یک‌بار در کل run Watcher (خارج از حلقه)؛ (d) سقف wall-time هر اجرای کران ≈ 60s (باقی‌مانده به single-events بعدی)؛ (e) crontab خارجی (`wp cron run`) یا حداقل مهندسی | **P0** | ▲▲▲ | ▲▲ | ▲▲▲ | ▲▲▲ |
| **CR-4** | **Throughput & pipeline**: (a) دانلود موازی (worker دوم سبک «downloader» که bot-action نیست و 2-3 هم‌زمان می‌گیرد — claim همان است)؛ (b) افزایش batch/interval با سقف حافظه‌محور (مثلاً 8/tick، 300s — با پایش memory)؛ (c) `fallback_to_legacy` → NEEDS_REVIEW با دلیل (SCANNED ساکت دیگر نباشد)؛ (d) پیاده‌سازی `scan_limit`؛ (e) auto-assign دسته از «دسته‌ی کانال» + هشدار تجمعی | **P1** | ▲▲▲ | ▲ | ▲▲ | ▲▲▲ |
| **CR-5** | **Circuit-breaker fiber/IPC**: بعد از اولین خطای `Must call resume`/IPC در request، flag `sti_mt_poisoned` → بقیه‌ی فراخوانی‌های MTProto همان request **بلافاصله** با WP_Error (بدون تماس) + `next_retry_at` برای sessionها؛ + در Watchdog: شمارش `madeline-ipc` زنده و اگر > سقف → kill یتیمان (scope‌شده) + لاگ | **P1** | ▲▲ | ▲ | ▲▲▲ | ▲▲ |
| **CR-6** | **MySQL**: delta-scanning پروفایل (`id > last_scanned`) + FULLTEXT اختیاری؛ شمارنده‌ی messages در option؛ Refresh دستی با throttling 6h (همان Watcher) | **P1** | ▲▲ | ▲ | ▲▲ | ▲ |
| **CR-7** | **Dedup**: merge خودکار Inbox بر `telegram_document_id`؛ adopt-to-existing برای SKU محصول با قفل (نه pre-check) | **P1** | — | — | ▲▲ | ▲▲ |
| **CR-8** | **پایش/شفافیت**: صفحه‌ی سلامت (workerهای IPC زنده، وضعیت سوکت، PHP vs stub phar، memory_limit واقعی، queue depth، dead-letter count) + alert در لاگ برای تکرار `CHAIN_HISTORY_READ_FAILED` | **P1** | — | — | ▲▲ | ▲ |
| CR-9 | clear hookهای GS در deactivation + یکدست‌کردن دو صف انتشار (retire legacy tick یا flag) | **P2** | ▲ | — | ▲ | ▲ |
| CR-10 | guardهای interval را اتمیک کنید (UPDATE ... WHERE last < now) | **P2** | — | — | ▲ | — |
| CR-11 | guard روی `auth_state`/`account_info` + کش بلندتر (5m) | **P2** | ▲ | ▲ | ▲ | — |
| CR-12 | نسخه‌بندی صریح phar: فایل‌های `madeline-<php>-<ver>.phar` + جدول سازگاری + نمایش در سیستم‌چک | **P2** | — | — | ▲▲ | — |
| CR-13 | (P3) بازطراحی poll: دو-pass global_poll (docs-only) برای کاهش اوج حافظه؛ (P3) ایندکس‌های ترکیبی بر اساس profile واقعی pick() پس از رشد صف | **P3** | ▲ | ▲ | ▲ | — |

---

# سؤال نهایی — اگر امروز فقط ۵ اصلاح انجام شود؟

رتبه‌بندی بر اساس **اثر واقعی** بر پایداری و خودکارسازی (نه تعداد باگ‌ها):

### ۱) CR-1 — supervisor برای IPC (P0)
**چرا اول:** سه از ده مشکل مشاهده‌شده (C1، C3، S1) و عملاً S4/S7 همه از این یک ریشه می‌روند: پروسه‌ی `madeline-ipc` بدون ناظر. همین مورد، «endpoint does not exist»ها، نشت workerها و OOM هاست (الگوی «سایت چند دقیقه می‌رود») را **هم‌زمان** از ریشه برمی‌دارد؛ و چون attemptهای retry هم با outageها مصرف می‌شدند، بعد از آن Sessionها هم زودتر زنده می‌مانند. بدون این، هیچ اصلاح دیگری روی پایداریِ واقعی تأثیر نمی‌گذارد.
**اثر:** پایداری ▲▲▲ · خودکارسازی ▲▲▲ (دانلودهای قابل‌اعتماد = پ이프‌لاین کامل خودکار) · حافظه ▲▲

### ۲) CR-2 — جابه‌جایی memory-limit + کش engine_healthy (P0)
**چرا دوم:** کوچک‌ترین تغییر (چند خط) با حذفِ یک کلاس کامل خطا (OOM خط 172) — دقیقاً خطایی که شما گزارش داده‌اید. هزینه‌اش تقریباً صفر است و ریسکش تقریباً صفر؛ پس قبل از هر چیز بزرگ‌تر باید انجام شود.
**اثر:** پایداری ▲▲▲ (در مسیر ادمین/تیک) · حافظه ▲▲▲ · سرعت ▲ (ادمن سریع‌تر)

### ۳) CR-3 — جراحی Cron: دانلود جدا + cron-lock + حذف spawn storm (P0)
**چرا سوم:** بلافاصله بعد از CR-1/2، بزرگ‌ترین عامل باقی‌مانده‌ی «سایت از دسترس خارج می‌شود» همان requestهای کران ۱۵-۲۰ دقیقه‌ای‌اند که FPM children را بلوکه می‌کنند — و بزرگ‌ترین شکاف خودکارسازی (وابستگی به ترافیک + بک‌آپ) هم همین‌جا حل می‌شود (crontab خارجی + eventهای مستقل). این سه، پایداری و خودکارسازی را **با یک تغییر** می‌برند.
**اثر:** پایداری ▲▲▲ · خودکارسازی ▲▲▲ · سرعت ▲▲▲ (انتشار دیگر پشت Worker صف نمی‌کشد)

### ۴) CR-4 — throughput و حذف SCANNED ساکت (P1)
**چرا چهارم:** وقتی سیستم پایدار شد، مانع اصلی رسیدن به هدف «کاربر فقط کانال + پروفایل» **سرعت خط** است: با پیش‌فرض‌های فعلی، 5٬785 candidate ≈ ۲ ماه. دانلود موازی + batch معقول، این عدد را به ۲-۴ هفته می‌رساند. SCANNED ساکت → NEEDS_REVIEW هم یعنی هیچ خودکارسازی‌ای دیگر «بی‌صدا نمی‌میرد». (auto-assign دسته و scan_limit هم همین‌جا بسته می‌شوند.)
**اثر:** خودکارسازی ▲▲▲ · سرعت ▲▲▲ · پایداری ▲▲ (صف انبوه = کمتر crash)

### ۵) CR-5 — circuit-breaker fiber/IPC + پایش workerها (P1)
**چرا پنجم:** بعد از CR-1، خطاهای fiber هنوز ممکن است (kill هاست، timeout). بدون breaker، یک خطا **کل request** را آلوده می‌کند و sessionهای بی‌گناه تیک را هم می‌سوزاند. breaker + شمارش workerها در Watchdog، «خرابی بعدی» را از «خرابیِ همه‌چیز» به «یک retry تمیز» تبدیل می‌کند — یعنی floor پایداری بالا می‌رود حتی در بدترین سناریوهای باقی‌مانده.
**اثر:** پایداری ▲▲▲ (floor) · خودکارسازی ▲▲ · حافظه ▲

---

## چه چیزی عمداً انجام **نشد** (مطابق دستور)

- هیچ خط کدی تغییر نکرد (نه در plugin، نه در ZIP).
- فقط یک فایل گزارش (این سند) به repo اضافه شد.
- همه‌ی یافته‌ها با file:line در سورس یا offset در phar قابل‌تست هستند؛ برای اجرا، بخش‌های 4.6 و 2.3 «دستورالعمل‌های تشخیص روی هاست» را دارند.

## پیوست — مدارک کلیدی (برای audit بعدی)

| مدرک | مکان |
|---|---|
| `require_once self::phar_path()` = خط OOM | `includes/class-sti-mtproto.php:172` |
| افزایش memory بعد از require | `mtproto.php:502` (require) ← `:514-518` (ini_set) |
| call siteهای engine_healthy | `admin/views/telegram.php:137`، `mtproto.php:239/2208/2226` |
| IPC socket error source | phar offset 12133671: `Amp\Ipc\connect` → `throw ... "The endpoint does not exist!"` |
| همه‌ی API methods از IPC می‌روند | phar offset ~3926370: `IpcCapable::__call` wrapper (downloadToFile نمونه) |
| spawn worker با proc_open | phar: `Ipc\Runner\ProcessRunner::start` (PHP_BINARY + `madeline-ipc` + session) |
| fiber error source | phar offset 15612187: Amp loop `Must call resume() or throw()` |
| stub: PHP ≥ 8.2 + alias | phar offset 0: `die("MadelineProto requires at least PHP 8.2")`؛ `Phar::mapPhar("madeline84-v8.phar")` |
| مشاهده‌ی leak worker توسط توسعه‌دهنده | `mtproto.php:2133-2137` (توضیح ajax_reply) |
| dead code pkill | `mtproto.php:2177` (تعریف) — caller صفر |
| spawn_cron در حلقه | `class-gs-channel-watcher.php:274` |
| fallback ساکت | `class-gs-chain-engine.php` → `fallback_to_legacy()` |
| dead flag scan_limit | `class-gs-flags.php:62` — reader صفر |
| LIKE بدون delta | `class-gs-profile.php` → `run()` |
| classify: endpoint=TRANSIENT | `class-gs-recovery.php` → `classify()` (backoff 120×2ⁿ تا 1h؛ بعد از 5 attempt → 6h) |
