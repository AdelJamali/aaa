# گزارش ۱۰.۱۲.۱۷ — استخراج سالمِ خطای Fiber

نسخه **10.12.17** · کامیت کد `f8a3da7` · کامیت ZIP `40e44b7`
`golden-importer-10.12.17.zip` (۱۳۲ فایل)
sha256: `045ea921cc479982c711268d1b8560f8d2623a96b0502176f5802efbeee5c9b2`

---

## ۱) Original Error

خطای اصلی، این است و باید دست‌نخورده بماند:

```
Fiber stack allocate failed: mmap failed: Cannot allocate memory (12)
```

**این خطا در ۱۰.۱۲.۱۶ مخدوش می‌شد — و مقصرش کدِ تشخیصی خودم بود.**

### مکانیزم دقیق (اثبات‌شده، نه حدس)

مشاهده‌ی شما درست بود، ولی علتش از چیزی که فکر می‌کردیم بدتر است. این فقط یک Warning آزاردهنده نبود؛ **جایگزینیِ واقعیِ خطا** بود:

۱. MadelineProto در `Magic::start()` این را نصب می‌کند (phar، بایت ۱۱٬۴۶۳٬۳۳۸):
```php
set_error_handler(Exception::exceptionErrorHandler(...));
```

۲. و آن handler هر warning را به **پرتاب Exception** تبدیل می‌کند (phar، بایت ۹٬۲۷۱٬۴۵۵):
```php
throw new self($errstr, $errno, null, $errfile, $errline);
```

۳. تنها راه فرارش این شرط است:
```php
if (error_reporting() === 0 || ...) { return false; }
```

۴. **و اینجا نقطه‌ی شکست است:** از PHP 8.0 عملگر `@` دیگر `error_reporting()` را صفر نمی‌کند — یک ماسک غیرصفر برمی‌گرداند. پس آن گارد **رد نمی‌شود**.

نتیجه: `@file_get_contents('/sys/fs/cgroup/memory.max')` روی هاستی که cgroup v2 ندارد، ساکت نمی‌ماند. یک warning تولید می‌کرد که به `danog\MadelineProto\Exception` تبدیل می‌شد و **از دل بلوک تشخیصی بالا می‌آمد و جای خطای Fiber را می‌گرفت**.

> این یک اشتباه در کار خودم بود. در گزارش ۱۰.۱۲.۱۶ نوشته بودم «`@` فقط diagnostics را پایین می‌آورد و هرگز exception را سرکوب نمی‌کند» — آن جمله درست بود، ولی نتیجه‌گیری‌اش را اشتباه گرفتم: `@` جلوی **ساخته‌شدن** exception توسط یک handler سفارشی را نمی‌گیرد. حالا اصلاح شد.

**از ۱۰.۱۲.۱۷ به بعد:** خطای اصلی در فیلد مستقل `exception_message` ذخیره می‌شود و هیچ خطای تشخیصی نمی‌تواند جایش را بگیرد.

---

## ۲) Diagnostic Data — چه چیزی و چطور اندازه‌گیری می‌شود

### مرحله ۱ — سه لایه‌ی محافظت (`safe_read()`)

تنها راه مجاز خواندن فایل در کد تشخیصی، حالا این تابع است:

| لایه | کار | چرا لازم است |
|---|---|---|
| ۱ | `@is_readable($path)` قبل از هر خواندن | در حالت عادی **اصلاً warningی تولید نمی‌شود** — خواسته‌ی صریح شما |
| ۲ | `set_error_handler()` موقت که فقط `false` برمی‌گرداند، بلافاصله `restore` | حتی اگر بین بررسی و خواندن مسابقه‌ای رخ دهد، handler مادلاین در آن پنجره فعال نیست |
| ۳ | `try/catch (\Throwable)` | اگر باز هم چیزی پرتاب شد، همین‌جا می‌میرد و به مسیر اصلی نمی‌رسد |

قاعده‌ی کلیدی: **نبودن فایل خطا نیست.** مقدار `'unavailable'` برمی‌گردد و در `diagnostic_read_errors` **ثبت نمی‌شود**. فقط شکست واقعاً غیرمنتظره (فایل خوانا بود ولی خواندن شکست خورد) به‌عنوان خطا ثبت می‌شود.

هیچ `file_get_contents` مستقیمی دیگر داخل `oom_context()` باقی نمانده (تست W5 این را تضمین می‌کند).

### مرحله ۲ — سه شاخص اصلی + شش شاخص کمکی

| اولویت | فیلد | منبع |
|---|---|---|
| **۱** | `maps_count` | شمارش خطوط `/proc/self/maps` (فقط عدد؛ محتوا ذخیره نمی‌شود) |
| **۲** | `max_map_count` | `/proc/sys/vm/max_map_count` |
| **۳** | `rlimit_data` | «Max data size» از `/proc/self/limits` |
| کمکی | `rlimit_as` · `rlimit_stack` | همان فایل limits |
| کمکی | `memory_limit` · `memory_usage` · `memory_peak` | PHP |
| کمکی | `cgroup.v2` (`memory.max` / `.current` / `.events`) | با `safe_read` |
| کمکی | `cgroup.v1` (limit/usage/failcnt) | fallback خودکار |

### مرحله ۳ — تفکیک شواهد

هر جزء در فیلد جداگانه‌ی خودش ثبت می‌شود:

`exception_class` · `exception_message` · `exception_origin` · `exception_trace` · `stage` · `action` · `session_id`

کلاس و stack trace در همان `catch` گرفته می‌شوند (`get_class($e)` و `$e->getTraceAsString()`) — قبلاً فقط پیام نگه داشته می‌شد و این دو از بین می‌رفتند.

---

## ۳) Unavailable Data

**آنچه هنوز نداریم و بدون آن تصمیم نمی‌گیریم:**

| داده | وضعیت |
|---|---|
| `maps_count` واقعی از هاست | ❌ **نداریم** |
| `vm.max_map_count` واقعی | ❌ **نداریم** |
| `RLIMIT_DATA` واقعی | ❌ **نداریم** |
| نصب ۱۰.۱۲.۱۷ روی هاست | ❌ تأیید نشده |

**محدودیت‌های محیطی شناخته‌شده (نه خطا):**

- `/sys/fs/cgroup/memory.max` احتمالاً روی هاست شما وجود ندارد (هاست روی cgroup v1 است یا CloudLinux/LVE). این از حالا **`unavailable`** گزارش می‌شود، نه Warning.
- `exec` و `shell_exec` غیرفعال‌اند ⇒ `ipc_heal_possible = false / exec_disabled`. طبق قاعده‌ی خودتان این «محدودیت محیطی» است، نه «نبودِ worker».
- روی CloudLinux، `/proc/meminfo` مربوط به **هاست** است نه LVE شما.
- **در سندباکس من PHP نصب نیست** ⇒ منطق `safe_read()` را با بازرسی کد و ۸ سوئیت تست ایستا (همگی PASS) تأیید کردم، **نه با اجرای واقعی**. تأیید نهایی فقط روی هاست ممکن است.

---

## ۴) Next Root-Cause Decision

> ### Root Cause هنوز اثبات نشده است.

طبق دستور شما، بین این دو **تصمیم نمی‌گیرم**:

- **A)** محدودیت محیط/هاست باعث شکست allocation می‌شود.
- **B)** معماری فعلی Worker/MTProto/Fiber در این runtime ذاتاً ناسازگار است.

**جدول تصمیم — به‌محض رسیدن سه عدد اعمال می‌شود:**

| مشاهده | نتیجه |
|---|---|
| `maps_count` نزدیک `max_map_count` (مثلاً ۶۰٬۰۰۰ از ۶۵٬۵۳۰) | **A** — سقف شمارشی کرنل. راه‌حل: `vm.max_map_count` → ۲۶۲۱۴۴، **بدون هیچ تغییر کد** |
| `maps_count` کم (مثلاً ۲٬۰۰۰) ولی `rlimit_data` تنگ | **A** — سقف RLIMIT_DATA |
| `maps_count` کم و همه‌ی سقف‌ها باز | به سمت **B** می‌رویم — ولی آن‌وقت هم اول باید نشتی را در کد خودمان اثبات کنم |
| `cgroup.v1.failcnt > 0` | **A** — سقف cgroup |

**نکته‌ی فنی که در هر دو حالت صادق است:** Fiber از `mmap` خام استفاده می‌کند نه `emalloc`. پس `memory_limit` نه می‌تواند این خطا را بسازد و نه درمانش کند. عدد `memory_limit` را فقط برای ثبت جمع می‌کنم.

---

## آنچه تغییر نکرد (طبق دستور شما)

Worker · MTProto · Fiber · Retry · Queue · Session state · backlog · `backlog_limit` · retry limit · Watcher — **هیچ‌کدام دست نخوردند.**

هیچ Sessionی حذف نشد، هیچ backlogی پاک نشد، هیچ بازطراحی‌ای انجام نشد. تنها تغییرات: `class-gs-env-diag.php` (کد تشخیصی) و چند خط ثبت شاهد در `class-sti-mtproto.php`. **هر دو فقط خواندنی.**

اگر بخواهید همه‌چیز را خاموش کنید: Worker → «کلیدهای قابلیت» → تیک «تشخیص ENOMEM/Fiber» را بردارید.

---

## قدم بعدی شما

۱. ZIP نسخه ۱۰.۱۲.۱۷ را نصب کنید.
۲. همان کاری که خطا می‌داد را دوباره انجام دهید (Worker → Execute Action).
۳. **گزارش‌ها → فیلتر `error`** → ردیفی که با `OOM_DIAG {` شروع می‌شود را کامل کپی کنید و بفرستید.

این بار انتظار دارم:
- متن `Fiber stack allocate failed` **سالم** در `exception_message` باشد
- هیچ اشاره‌ای به `file_get_contents(...memory.max...)` در جای خطای اصلی نباشد
- سه عدد `maps_count` / `max_map_count` / `rlimit_data` حاضر باشند

با همان یک ردیف، تصمیم A در برابر B را با شاهد مستقیم می‌گیرم.
