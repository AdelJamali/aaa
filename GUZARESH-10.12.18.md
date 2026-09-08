# گزارش ۱۰.۱۲.۱۸ — رفع گرسنگی صف Worker

> نسخه: **10.12.18** · commit کد `b3a76aa` · commit فایل ZIP `6ed0325`
> فایل: `golden-importer-10.12.18.zip` · ۱۳۲ فایل · sha256 `359af9d52e515b3e5e825c3c73cd3191c880d43ccc0f4875fe5959d54455ed54`

---

## ۱) خطای اصلی (Original Error)

این نسخه به خطای Fiber کار ندارد. آن پرونده دست‌نخورده باقی است:

```
Fiber stack allocate failed: mmap failed: Cannot allocate memory (12)
```

وضعیت آن همچنان **ROOT_CAUSE_UNKNOWN** است و منتظر سه عدد
`/proc/self/maps` · `vm.max_map_count` · `RLIMIT_DATA` می‌ماند.

مسئله‌ی این نسخه چیز دیگری است: **خط تولید متوقف بود ولی هیچ خطایی نمی‌داد.**

---

## ۲) علت ریشه‌ای — اثبات‌شده در سطح کد

### شاهد میدانی

از میزبان، بازه‌ی ۱۶:۳۹ تا ۱۸:۱۲ (نسخه‌ی ۱۰.۱۲.۱۷ فعال)، در **هر هشت تیک متوالی**:

```
AUTO_WORKER_TICK  enabled=true line=RUNNING active=0 max_active=1 eligible_queue=108
AUTO_WORKER_PICK: selected=1 ids=[68]
AUTO_WORKER_STAGE session=#68 from=CHAIN_WAITING to=CHAIN_WAITING result=waiting
```

و در آمار همان روز: `advanced=0` · `completed=0` · `with_product=0` · `ticks=42`.

۱۰۸ Session در صف بود و Worker در هر دور فقط و فقط `#68` را برمی‌داشت.

### زنجیره‌ی علّی — سه فایل

**گام ۱ —** موتور زنجیره بدون زمان‌بندی برمی‌گردد
`includes/golden-scan/class-gs-chain-engine.php:1332`

```php
return array( 'state' => 'CHAIN_WAITING', 'waiting' => true );
```

هیچ `next_retry_at` نوشته نمی‌شود.

**گام ۲ —** قفل بلافاصله آزاد می‌شود
`class-gs-chain-engine.php:1333` → `finally { STI_GS_Session::release(); }`
→ `class-gs-session.php:188` → `locked_until = NULL`, `worker_id = NULL`

**گام ۳ —** Worker فقط برچسب می‌زند
`class-gs-auto-worker.php` در `tick_inner()`:

```php
$outcome = 'waiting';   // فقط برای گزارش — هیچ نوشتنی در دیتابیس
```

**گام ۴ —** کوئری انتخاب دوباره همان را برمی‌دارد
`class-gs-auto-worker.php:513-518`

```sql
WHERE state NOT IN (6 terminal)
  AND ( locked_until  IS NULL OR locked_until  < NOW )
  AND ( next_retry_at IS NULL OR next_retry_at <= NOW )
ORDER BY ( attempts >= 5 ) ASC, priority DESC, id ASC
LIMIT 1
```

Session با **هر دو ستون NULL** باقی می‌ماند، هر سه شرط را پاس می‌کند، و چون
مرتب‌سازی `id ASC` است دوباره کوچک‌ترین id یعنی `#68` انتخاب می‌شود.

> **حلقه‌ی بسته:** انتخاب → `CHAIN_WAITING` → `waiting` → آزادسازی قفل → بدون تأخیر → انتخاب مجدد

### راستی‌آزمایی: هیچ مسیر دیگری وجود ندارد

گرپ سراسری روی کل افزونه (۵۰ مورد `next_retry_at`) نشان داد **تنها** این
جاها مقدار آینده می‌نویسند:

| محل | شرایط |
|---|---|
| `class-gs-auto-worker.php:748` | `handle_failure` — پس از سقف تلاش، ۶ ساعت |
| `class-gs-auto-worker.php:792` و `:809` | backoff خطا |
| `class-gs-chain-engine.php:885` و `:913` | `FLOOD_WAIT` تلگرام |
| `class-gs-action-executor.php:151`، `:209` | خطای اجرا / flood |
| `class-gs-download-engine.php:120` | خطای دانلود |

**هیچ‌کدام مسیر «انتظار» نیست** — چون در طراحی، انتظار عمداً «خطا» شمرده نمی‌شود.
همان تصمیم درست، این عارضه را ساخته بود.

---

## ۳) یک ادعا که رد شد — سیستم Lock سالم است

در بازبینی مطرح شد که «قفل عملاً بی‌اثر است و هیچ‌جا `locked_until = future_time`
نوشته نمی‌شود». **کد این را رد می‌کند.**

`class-gs-session.php:167`:

```php
$until = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + max( 10, (int) $lock_seconds ) );
$wpdb->query( $wpdb->prepare(
    "UPDATE {$table} SET locked_until = %s, worker_id = %s, updated_at = %s
     WHERE id = %d AND (locked_until IS NULL OR locked_until < %s)",
    $until, $worker_id, $now, (int) $id, $now
) );
```

این یک `UPDATE … WHERE` شرطی و اتمی است — الگوی درست قفل خوش‌بینانه.
و **۱۶ نقطه** آن را صدا می‌زنند: Chain Engine (۴ بار، با ۶۰ و ۹۰ و ۴۵ ثانیه)،
Action Executor (۹۰)، Button Resolver (۶۰)، File Matcher (۴۵)، Download،
Media، Product Builder، Validator، Publish Queue (۲ بار)، Bot Collector (۴۵).

**قفل کار می‌کند.** اشکال در قفل نبود؛ در نبودِ زمان‌بندیِ پس از انتظار بود.
اگر قفل خراب بود، `active=0` در لاگ‌ها دیده نمی‌شد.

---

## ۴) اصلاح انجام‌شده

یک فایل، فقط افزودنی: `includes/golden-scan/class-gs-auto-worker.php`

### الف) جدول مهلت به تفکیک حالت

پیشنهاد اولیه‌ی من ۹۰ ثانیه‌ی ثابت بود. این اصلاح شد چون سه حالت انتظار
از نظر معنایی یکی نیستند. مقادیر از روی قفل‌های خودِ موتور انتخاب شده‌اند:

```php
const WAITING_BACKOFF = array(
    'CHAIN_WAITING'     => 60,    // POLL_LOCK_SECONDS = 45
    'WAITING_BOT'       => 120,   // STEP_LOCK_SECONDS = 90
    'ERROR_BOT_TIMEOUT' => 300,   // یک‌بار مهلتش تمام شده
);
const WAITING_BACKOFF_DEFAULT = 90;
```

قابل تنظیم از بیرون:

```php
apply_filters( 'sti_gs_waiting_backoff', $base, $state, $session_id );
```

### ب) تابع `defer_waiting()`

فقط `next_retry_at` می‌نویسد. عمداً:

- ❌ `attempts` را **دست نمی‌زند** — انتظار خرابی نیست، سقف تلاش نباید بسوزد
- ❌ `state` را عوض نمی‌کند — منطق زنجیره دست‌نخورده
- ❌ اگر `FLOOD_WAIT` قبلاً مهلت آینده گذاشته باشد، **بازنویسی نمی‌کند** — زمان تلگرام اولویت دارد
- ✅ محدود به بازه‌ی [۱۰ ثانیه، ۱ ساعت]
- ✅ خط `AUTO_WORKER_DEFER` در لاگ ثبت می‌کند

### ج) نقطه‌ی فراخوانی — `skipped` هم پوشش دارد

```php
if ( 'waiting' === $outcome || 'skipped' === $outcome ) {
```

`skipped` عمداً اضافه شد. مسیرهای `no_progress` در `class-gs-chain-engine.php`
(خطوط ۱۲۲، ۱۲۸، ۱۳۴، ۱۴۰، ۱۵۴، ۲۱۹، ۲۸۹ و…) هم بدون تغییر `state` و بدون
`next_retry_at` برمی‌گردند و `finally` قفل را آزاد می‌کند — **دقیقاً همان
حلقه با برچسبی متفاوت.** اگر فقط `waiting` پوشش داده می‌شد، باگ نصفه می‌ماند.

---

## ۵) آنچه تغییر نکرد

طبق محدودیت‌های اعلام‌شده، هیچ‌کدام از این‌ها لمس نشد:

سقف تلاش · `backlog_limit` · فهرست `TERMINAL` · فهرست `WAITING` · گیت
`max_active` · Fiber · MTProto · منطق صف · ماشین حالت Session · هیچ Sessionای
حذف یا ویرایش نشد · هیچ رکورد orphan دست نخورد · Watcher روشن نشد.

---

## ۶) اعتبارسنجی

| مجموعه | نتیجه |
|---|---|
| `test-10-12-18-starvation.py` (جدید، ۳۴ ادعا) | ALL PASS |
| `test-10-12-16-oomdiag2.py` | ALL PASS |
| `test-10-12-15-oomdiag.py` | ALL PASS |
| `test-10-12-14-fixes.py` | ALL PASS |
| `test-10-12-12-envdiag.py` | ALL PASS |
| `test-10-12-11-mtproto.py` | ALL PASS |
| `test-10-12-workflow.py` | 43/43 PASS |
| `test-10-11-regression.py` | ALL PASS |
| `test-p0-governor-loadavg.py` | ALL PASS |

تست جدید علاوه بر خودِ اصلاح، **وضعیت اولیه را هم قفل می‌کند** (`S1`-`S4`):
اگر روزی موتور زنجیره خودش `next_retry_at` بنویسد یا ترتیب `id ASC` عوض شود،
تست هشدار می‌دهد که این اصلاح باید بازبینی شود.

خودِ فایل ZIP هم مستقل باز و بررسی شد: نسخه ۱۰.۱۲.۱۸، `defer_waiting` حاضر،
و `safe_read` تشخیصی (۱۱ مورد) دست‌نخورده.

> ⚠️ **محدودیت:** در محیط توسعه مفسر PHP نصب نیست. اعتبارسنجی از راه بازرسی
> کد و مجموعه‌های تست ایستا انجام شده، نه اجرای واقعی. تأیید نهایی فقط روی
> میزبان واقعی ممکن است.

---

## ۷) انتظار پس از نصب

در لاگ باید این الگو دیده شود:

```
AUTO_WORKER_PICK:  selected=1 ids=[68]
AUTO_WORKER_DEFER  session=#68 state=CHAIN_WAITING delay=60s reason=waiting_backoff
   ← تیک بعد
AUTO_WORKER_PICK:  selected=1 ids=[69]      ← شناسه عوض می‌شود
```

**نشانه‌ی موفقیت:** شناسه در `AUTO_WORKER_PICK` بین تیک‌ها تغییر کند و
`advanced` بزرگ‌تر از صفر شود.

---

## ۸) تصمیم بعدی درباره‌ی Root Cause

دو باگ **مستقل** باقی است و رفع یکی دیگری را حل نمی‌کند:

| # | مسئله | وضعیت |
|---|---|---|
| ۱ | گرسنگی Worker روی `#68` | **PROVEN** — در ۱۰.۱۲.۱۸ رفع شد |
| ۲ | آلودگی صف با ۸۲۵ رکورد orphan | شناسایی‌شده، **رفع نشده** |
| ۳ | `Fiber stack allocate failed` | **ROOT_CAUSE_UNKNOWN** |

درباره‌ی `backlog=109`: رابطه‌ی علّی مطرح‌شده تأیید می‌شود —
`WORKER_STARVATION → advanced=0 → backlog>100 → Watcher بسته می‌شود`.
**Watcher معلول است، نه علت.** ولی حتی با باز شدن backlog، مانع دوم
سر جایش است: ۸۲۵ رکورد orphan پنجره‌ی ۵۰۰تایی اول انتخاب را پر کرده‌اند
(`first_valid_id=23339` در رتبه‌ی ۸۲۶).

برای مسئله‌ی ۳ همچنان بین دو فرضیه تصمیم نمی‌گیرم:
**الف)** محدودیت محیط/میزبان · **ب)** ناسازگاری ذاتی معماری با این runtime.

**«Root Cause مسئله‌ی Fiber هنوز اثبات نشده است.»**

---

## ۹) داده‌های همچنان در دسترس نبودن (Unavailable Data)

| داده | وضعیت |
|---|---|
| `/proc/self/maps` (`maps_count`) | unavailable — هنوز در هیچ لاگی ثبت نشده |
| `vm.max_map_count` | unavailable |
| `RLIMIT_DATA` | unavailable |

این سه فیلد در ۱۰.۱۲.۱۷ اضافه شدند ولی فقط **هنگام وقوع خطای Fiber**
نوشته می‌شوند. از زمان نصب ۱۰.۱۲.۱۷ خطای تازه‌ای رخ نداده، پس هنوز
نمونه‌ای ثبت نشده است. اگر دوباره رخ دهد، این بار داده‌ی کامل ثبت می‌شود.
