== 10.8.2 ==
* FIX State machine invariant: WAITING_BOT ≠ BUTTON_FOUND. Converting state is only done by explicit recovery paths (requeue_click / timeout_recovery), never by a user click on Execute Action; clicked_at is treated as evidence ("was an action dispatched?"), never as the sole decision for recovery.
* FIX next_stage routes by the session's own chain_mode (D6): SCANNED + chain_mode ∈ {auto, chain} → Chain Init; SCANNED + NULL → Legacy Resolver always. The global UI mode never changes the meaning of an old NULL session — only recover() can explicitly migrate it with evidence.
* FIX recover() is now evidence-based: (A) real deep_link/start_param in button_payload (bot_start → DEEP_LINK, bot_webapp → WEBAPP, invite → CHAT_INVITE), (B) bot_username + an independent witness (file_code / clicked_at / button_url / button_method), (C) decodable source message with an executable classification. No evidence → NEEDS_REVIEW (never a silent fallback). Reads the session again after claim() (TOCTOU).
* FIX NODE_TEXT is no longer executable: informational bot texts are recorded as an informational sink in poll() (with MAX_INFORMATIONAL_STEPS cap) instead of being echoed back to the bot (ping-pong removed).
* FIX ERROR_MATCH is never blindly requeued to Execute Action: match_recovery() classifies the failure — ALL_CANDIDATES_CLAIMED → WAITING_BOT + bounded backoff (Match retry); NO_IDENTIFIABLE_FILE / ambiguous → NEEDS_REVIEW.
* NEW NEEDS_REVIEW is a real terminal state (worker TERMINAL, stats counter, worker dashboard, manual pipeline message) used only for ambiguity / missing evidence / unsafe decisions. Temporary Telegram errors keep their retry/backoff paths.
* FIX Per-hop retry bound: HandoffStep.attempts now means "number of retries after the initial execution" — the only place that increments it is the retry gate in advance() when entering from CHAIN_FAILED (STEP_ATTEMPTS_MAX = 3, then NEEDS_REVIEW). mark() writes attempts to the real column (previously it went into meta JSON and never worked); process-failure mark_failed no longer increments it; mark_done / CHAIN_WAITING never reset it. Session.attempts stays the session-level failure counter with exponential backoff (5/10/20/40/80 min) and 6-hour give-up, fully decoupled.
* FIX Anti-loop: requeue_click() / timeout_recovery() return a deliberate WP_Error after moving to BUTTON_FOUND so the worker applies attempts+1 + backoff (a dead bot is retried at most once per 6 hours instead of every ~15 minutes forever); requeue without a button payload now goes to NEEDS_REVIEW instead of an infinite error loop.

== 10.8.1 ==
* FIX The chain engine now actually takes over EXISTING sessions: in `chain` mode, `next_stage()` routes every SCANNED session to Chain Init (even old sessions with `chain_mode = NULL`), and stuck old-path states (WAITING_BOT / BUTTON_FOUND / ERROR_CLICK / ERROR_BOT_TIMEOUT / ERROR_MATCH with no chain steps) are migrated into the chain via the new `STI_GS_Chain_Engine::recover()` — it rebuilds step 1 from the stored deep link, bot username, or the original message classification.
* FIX Manual "Execute Action" / "ادامه پردازش" on a WAITING_BOT session no longer ends in INVALID_STATE: the Action Executor requeues to BUTTON_FOUND itself when a button payload exists (retry-click path), so the old dev-tool buttons work again.
* FIX Loop guard: sessions the chain already marked legacy are not re-recovered (recover → fallback → recover infinite loop closed).
* FIX Auto Worker: WAITING_BOT timeout requeue now also guards missing button payload (skip with a clear reason instead of silent loop).
* FIX MTProto press_button: "endpoint does not exist" / "event loop terminated" / "query_id_invalid" with a t.me deep-link as callback data falls back to start_bot_dialog instead of failing hard.
* FIX chain-engine advance: chat_info() is wrapped in try/catch so a MadelineProto fiber crash cannot destroy a successful step; session attempts reset to 0 on each successful chain step (real 5+ hop chains no longer hit MAX_ATTEMPTS and get shelved).

== 10.8.0 ==
* NEW Chain architecture: Golden Scan is now a Telegram Interaction Engine (Telegram Node → Node → Node → Asset) instead of the obsolete Button → File resolver.
* NEW STI_GS_Node_Classifier (replaces the resolver's click-and-expect-file assumption) + STI_GS_Node_Processor (executes exactly one hop) + STI_GS_Chain_Engine (iterative, one Chain Step per worker run — recursion is forbidden by design).
* NEW node types: ASSET / BUTTON / DEEP_LINK / BOT / TEXT / GATE / WEBAPP / CHAT_INVITE / UNKNOWN — covers Deep Links, Start Params, Mini Apps, WebApps, Join Requests and Chat Invites.
* NEW independent deep link parser: https://t.me/Bot?start=PAHCZG2, https://t.me/Bot?start=24943123, tg://resolve?domain=...&start=..., tg://join, t.me/+hash, t.me/Bot/app.
* NEW official messages.startBot(bot, peer, start_param) is used for deep links instead of sending "/start payload" text (with automatic fallback to the old text path on older MadelineProto builds).
* NEW handoff storage table sti_gs_handoff_steps: every node is one step row (1 CHANNEL_BUTTON → 2 BOT → 3 BUTTON → 4 BOT → 5 ASSET) — crash recovery without recursion.
* NEW loop protection: visited-bots set (PartyManagerBot → FileechBot → PartyManagerBot is detected) + MAX_HANDOFF_DEPTH = 20.
* NEW feature flag gs_chain_mode (legacy | auto | chain): legacy keeps the old pipeline untouched; auto sends Asset to the old path and DeepLink/Button/Bot to the new chain; chain routes everything through the engine.
* NEW asset detection contract: end-of-chain files are resolved by the existing Identity Engine with priority CODE_MATCH → NAME_MATCH → CAPTION_MATCH → HASH_MATCH (candidate scoring + correlation, no duplicated logic).
* FIX File Code rule: file_code / payload / start_param are strings everywhere in the chain — numeric-looking codes (24943123) and alphanumeric codes (PAHCZG2, X5LZPEA) are handled identically; intval/absint/(int)/%d/sanitize_key are forbidden on them.
* IMPROVE multi-worker safety kept: every chain engine operation claims/releases the session lock (locked_until/worker_id); Auto Worker, Manual Processing and Retry all share the same next_stage map.
* IMPROVE Golden Scan worker page: chain mode selector + explanation; existing sessions (chain_mode NULL) keep the legacy behavior untouched.

== 10.0.1 ==
* FIX `/done` and `/cancel` now close the new «بدون مرز» and «ترتیبات» modes instead of reporting that only the legacy bulk mode is active.
* FIX «بدون مرز» uses the legacy File Code join rule while downloading the actual file through MTProto without the Bot API 20MB limit.
* FIX «ترتیبات» keeps the first photo/text content when the following document contains only a File Code caption.

== 10.0.0 ==
* NEW Bot mode «بدون مرز»: group registration through the personal MTProto account, without the Bot API 20MB limitation.
* NEW Bot mode «ترتیبات»: no File Code required; photo+text followed by a file is matched FIFO for multiple simultaneous products.
* NEW durable bot-mode inbox with retries, exact message recovery, direct MTProto downloads, AI content cleanup and publication queue integration.
* IMPROVE existing single/bulk modes remain unchanged and are deactivated automatically when a new mode is selected.

== 9.2.1 ==
* FIX GoldTel direct photo import: featured images are downloaded directly from the source photo message, validated with getimagesize, refreshed once, and only then passed to Media Library.
* FIX stale/invalid preview paths cannot be used as featured images.

== 9.2.0 ==
* FIX GoldTel stage 1 direct-channel import no longer requires File Code, Callback or Fileech; it downloads the original channel archive directly.
* FIX GoldTel duplicate checks for stage 1 use file name/title instead of the legacy File Code gate.
* NEW detailed Dispatcher status table with exact per-record errors and Retry.
* IMPROVE GoldTel rehydrates existing records and groups nearby photo/button/archive messages before direct download.

== 9.1.0 ==
* GoldTel stage 1 now downloads files directly from the original channel; File Code, Fileech and buttons are not required.
* Duplicate checks for GoldTel stage 1 use the original file name/title, with a generated internal identity only for stable SKU/retry tracking.
* GoldTel direct-channel dispatcher downloads the archive and related photo, then uses the existing Product Builder and publication queue.
* GoldTel selected-record action no longer rejects records merely because they have no File Code or Button.

== 9.0.3 ==
* FIX GoldTel selected records can now use a File Code + bot username found in caption/promotion text when the source message does not expose a structured button.
* FIX GoldTel rehydrates raw reply_markup/button/code metadata for existing Profile records before dispatch.
* IMPROVE Dispatcher reports per-record rejection reasons instead of only «0 رکورد».
* IMPROVE photo/text/archive grouping is finalized before the Profile is marked indexed.

== 9.0.2 ==
* FIX GoldTel finalizes keyword/category gates and photo/button/archive grouping when a scan finishes, so existing Profiles become dispatch-ready before the records table opens.
* UX GoldTel now labels the Profile action «رکوردها» and explains that indexing is read-only; selected records must be dispatched explicitly.

== 9.0.1 ==
* FIX GoldTel dispatch worker is scheduled and polled independently from the indexer.
* IMPROVE GoldTel downloads the related featured photo before Product Builder and supports direct-file dispatch as well as Fileech Inbox matching.
* IMPROVE GoldTel stores site/source metadata, profile counters and richer server-side filters.

== 9.0.0 ==
* NEW GoldTel Control Center: Scan Profiles, read-only full-channel indexing, server-side filters, explicit dispatch to Fileech, Bot Inbox search-only retry, download/product/publish statuses and AJAX mobile dashboard.
* NEW GoldTel tables for profiles, index records, dispatches and queue leases; existing Telegram, AutoCat, Storage, Product Builder and Scheduler services are reused.
* NEW detailed GoldTel record states, retry/backoff and duplicate/file-code gates.

== 8.0.2 ==
* FIX AI page safe-mode guard: missing STI_AI no longer causes a fatal admin screen; a re-enable link is shown instead.
* FIX Importek source query returning false: missing/partially-created tables are recreated and count(false) is prevented.
* IMPROVE Importek titles use «دانلود رابط کاربری با موضوع ...» without brackets, while preserving the English source title in the description.

== 8.0.1 ==
* FIX AI admin page registration: the existing «هوش مصنوعی» sidebar link now opens a real registered admin page instead of returning «شما اجازه دسترسی ندارید».
* IMPROVE Importek content: optional per-job AI rewrite, requested title format «دانلود رابط کاربری [موضوع]», English source title preserved in the description, and channel promotion/URL lines removed.
* IMPROVE Importek uses the configured AI service when enabled and falls back to cleaned source content if AI is unavailable.

== 8.0.0 ==
* NEW Importek: chronological MTProto importer for keyword-filtered photo + text + ZIP message groups, with AJAX dashboard, mobile UI, AI content rewrite fallback, WooCommerce categories, storage and publication queue integration.
* NEW Importek jobs/sources/items tables with resumable scan, oldest-first assembly, idempotent file identity and explicit error/duplicate states.
* NEW Importek parses the first text line as the title, keeps the remaining text as description, optionally rewrites content through the configured AI service, and falls back to the source text on AI failure.

== 7.1.4 ==
* FIX PHP 8.2+ dynamic-property deprecations in STI_Session_Row; session fields now use the existing magic accessors without creating runtime properties.

== 7.1.3 ==
* FIX strict category evidence: a Search/History fallback candidate is rejected unless a configured search term from the selected category is present in its caption, filename or button metadata.
* FIX unrelated Photo/Preview messages cannot pass Mockup import through a low-confidence/AI AutoCat decision.
* HOTFIX central stat-safe file-size helper is used for disappearing temporary files.

== 7.1.2 ==
* HOTFIX all temporary-file size checks now use a stat-safe helper; disappearing preview files cannot throw a filesize warning.
* FIX preview documents/audio are skipped before Fileech trigger when an actionable button exists; the bot request is no longer blocked by preview.m4a.
* FIX raw callback/start-button detection is applied before media handling, including unusual MadelineProto reply_markup shapes.

== 7.1.1 ==
* HOTFIX Search Import: preview media such as preview.m4a no longer blocks the download-button trigger; only photo media is treated as the featured image before Fileech is called.
* FIX warning-safe filesize checks for MadelineProto paths that disappear after a failed/partial download.
* FIX raw reply_markup fallback so bot start URLs are detected even when a MadelineProto version exposes an unusual button shape.
* FIX worker polling timezone drift that could leave queued batches permanently skipped.

== 7.1.0 ==
* NEW Search-first Channel Import: server-side MTProto search per category term, durable candidate index, validation before button press, and reusable press/wait/download pipeline.
* NEW per-category channel search terms with safe built-in aliases for Mockup, Logo, Vector, PSD, Font and other common categories.
* NEW durable channel item table with idempotent source message keys, explicit states, retry recovery and product/download audit fields.
* FIX File Code normalization, button URL payload extraction, stale Bot Inbox claims, exact code matching, forwarded-message authorization and source-channel notification leakage.
* SECURITY local/MTProto/Agent files now pass the same extension/size validator before entering public storage; executable extensions are rejected.
* IMPROVE MTProto search is preferred by Auto mode when a logged-in personal account is available; classic history/scrape paths remain available as fallback.

== 7.0.4 ==
* FIX سایدبار موبایل: backdrop دیگر روی خود منو قرار نمی‌گیرد، drawer پیش‌فرض بسته است، z-indexها تثبیت شدند و ورودی‌های لغت‌نامه از عرض صفحه بیرون نمی‌زنند.
* FIX کارگاه زنده: خروجی اتوکت دوباره کنار عنوان نمایش داده می‌شود، شامل دسته‌ی انتخابی، امتیاز اطمینان و پنج امتیاز برتر. نام کلیدهای متای سئو هم با موتور عنوان واقعی همسان شد.
* FIX لوگو: آدرس لوگوی منوی وردپرس و سایدبار versioned شد تا کش قدیمی دیگر لوگوی قبلی را نشان ندهد.

== 7.0.3 ==
* CLEANUP حذف کامل سه بخش قدیمی و بلااستفاده: Import Plus، رابط Mode 2 و مدیریت API قدیمی از صفحه محتوا. موتورهای فعال Channel Import، AutoCat، Group Monitor و مرکز AI دست‌نخورده باقی ماندند.
* CONTENT صفحه «محتوا و قالب‌ها» اکنون فقط قالب توضیحات، واکشی متن مرجع، ویژگی‌های ووکامرس و سیاست تکراری را مدیریت می‌کند؛ انتخاب API و پرامپت‌ها فقط در مرکز هوش مصنوعی است.

== 7.0.2 ==
== 7.0.1 ==
* HOTFIX خطای مرگبار «Too few arguments to function STI_AI::health()» که داشبورد را از کار می‌انداخت: کنترلر و صفحه‌های تازه با رابط واقعی هسته‌ی هوش مصنوعی و موتور عنوان هم‌تراز شدند (health، providers، stats، test_provider، rotation، prompt و rules همه با امضای درست صدا زده می‌شوند).
* صفحه‌ی «هوش مصنوعی» با تنظیمات واقعی هسته بازنویسی شد: استراتژی چرخش (اولویتی/دستی/گردشی/زمانی)، سرویس فعال، سقف تماس روزانه‌ی هر سرویس، انتخاب پراکسی به‌صورت هر-سرویس، تست تک‌سرویس و تست همه، تست پراکسی، ویرایش راهنمای سبک و پرامپت عنوان/توضیح/ترجمه/دسته.
* استودیوی عنوان روی موتور واقعی سوار شد: اسکن با scan_products، اعمال با apply_to_post (پشتیبان‌گیری خودکار)، بازگردانی با undo_post، قوانین کامل (پیشوند، الگو، حد کلمه/کاراکتر، حذف لاتین، یکتاسازی) و لغت‌نامه/اصلاح‌های دوستونه.
* ذخیره‌ی هر تب فقط فیلدهای همان تب را می‌نویسد؛ دیگر ذخیره‌ی «قوانین» لغت‌نامه را خالی نمی‌کند.
* داشبورد: سلامت هوش مصنوعی از خلاصه‌ی وضعیت و سلامت هر سرویس خوانده می‌شود و امتیاز عنوان‌ها با داور واقعی محاسبه می‌شود.

== 7.0.0 ==
* NEW «مرکز هوش مصنوعی»: همه‌ی کلیدهای API یکجا (OpenAI، OpenRouter، Gemini، Groq، DeepSeek، Claude، واسط‌های ایرانی، Ollama و Pollinations رایگان). زنجیره‌ی جایگزینی خودکار: اگر سرویسی خطا داد، توکنش تمام شد یا 429 خورد، ۳۰ دقیقه «سرد» می‌شود و سرویس بعدی بی‌وقفه کار را ادامه می‌دهد. پراکسی اختصاصی AI (SOCKS5/HTTP) مستقل از پراکسی تلگرام برای دور زدن محدودیت‌ها، تست اتصال با زمان پاسخ، آزمایشگاه پرامپت، آمار مصرف و سلامت هر سرویس. کلیدهای قبلی (ai_profiles) خودکار منتقل می‌شوند.
* NEW قالب پرامپت قابل ویرایش برای عنوان، توضیحات و تشخیص دسته — استاندارد نوشتاری سایت از یک نقطه کنترل می‌شود.
* NEW «استودیوی عنوان» بازنویسی کامل: موتور سه‌لایه (قانون‌ها → هوش مصنوعی → داوری). امتیاز کیفیت ۰-۱۰۰ برای هر عنوان، کارگاه زنده برای آزمایش قبل از اعمال، اسکن انبوه با ویرایش درجا، اعمال گروهی، تاریخچه و بازگردانی، الگوی عنوان قابل تنظیم، لغت‌نامه‌ی اختصاصی با خروجی/ورودی JSON، کلمات ممنوعه و متای سئو (Yoast / Rank Math / SEOPress) + برچسب‌گذاری خودکار.
* FIX (بحرانی) باگ «هاله روی صفحه» در بخش‌های «دسته‌بندی‌ها» و «محتوا و قالب‌ها»: اورلی مودال (.sti-modal-bg) در CSS هیچ حالت پنهانی نداشت و همیشه روی محتوا می‌افتاد و کلیک‌ها را می‌خورد؛ فرزندش هم استایل اورلی می‌گرفت. هر دو اصلاح شد.
* FIX (بحرانی) دریافت فایل از ربات در «واردات از کانال»: صندوق ورودی پایدار برای فایل‌های ربات، جستجوی گسترده (همه‌ی ربات‌های یادگرفته‌شده + Saved Messages + همه‌ی دیالوگ‌ها)، پذیرش همه‌ی انواع رسانه (نه فقط document)، تطبیق امتیازی چندلایه (کد دقیق ← reply ← شباهت نام فایل ← تک‌انتظاری ← ترتیب زمانی)، صف باریک (حداکثر ۲ فایل در انتظار) برای حذف ابهام تطبیق، و نردبان تشدید تلاش (کالبک → /start payload → کد خام → فشار دوباره) قبل از اعلام خطا.
* FIX (بحرانی) خطای «The endpoint does not exist!» هنگام دانلود: نام متد دانلود بین نسخه‌های MadelineProto عوض شده و method_exists هم روی __call جواب نمی‌دهد؛ حالا زنجیره‌ی ۵ روشی (downloadToFile / download_to_file / downloadToDir / download_to_dir / downloadToStream) امتحان می‌شود.
* FIX (بحرانی) خطای «Uncaught Amp SignalException — SIGTERM received»: سیگنال‌های هاست نادیده گرفته می‌شوند و هندلر خطای Revolt فقط لاگ می‌کند تا event loop نمیرد و کار نصفه نماند. getDialogs/getHistory هم کامل پوشش خطا گرفتند.
* FIX (دائمی) خطای «bot is not a member of the supergroup chat»: کد خطا از خود پاسخ تلگرام خوانده می‌شود (نه فقط HTTP code)، همه‌ی حالت‌ها (kicked/blocked/upgraded/not enough rights) پوشش داده شد و chat_id مقصر از فهرست ادمین‌ها و گروه‌های تحت نظر پاک می‌شود؛ یک راهنمای روشن لاگ می‌شود و خطا دیگر تکرار نمی‌شود.
* FIX رفرش file_reference: برای کانال‌ها channels.getMessages صدا زده می‌شود (نه messages.getMessages) — علت بخشی از خطاهای «Not Found».
* NEW «اتوکت حرفه‌ای»: داور هوش مصنوعی برای موارد مشکوک (امتیاز پایین یا دو دسته‌ی نزدیک)، یادگیری خودکار کلیدواژه از تصمیم AI (دفعه‌ی بعد بدون هزینه‌ی توکن)، افزودن گروهی کلیدواژه، خروجی/ورودی کامل دیکشنری و تاریخچه‌ی تصمیم‌ها.
* NEW داشبورد «اتاق کنترل»: زنده و خودبه‌روزشو (هر ۸ ثانیه)، KPIهای امروز، نمودار ۱۴ روز، سلامت Cron و AI، کیفیت عنوان‌ها، واردات‌های در جریان با درصد پیشرفت و تعداد فایل‌های در انتظار، دسته‌های پرکار، خطاهای پرتکرار و جریان رویدادها.
* SECURITY مقایسه‌ی زمان‌ثابت سکرت وبهوک (hash_equals)، هشدار سکرت ضعیف، پاک‌سازی ورودی‌های صفحات ادمین، nonce + بررسی سطح دسترسی روی همه‌ی endpointهای تازه، ماسک‌شدن کلیدهای API در رابط کاربری.
* NEW لوگوی تازه: آیکون منوی وردپرس تک‌رنگ و هماهنگ با پنل (با هر رنگ‌بندی ادمین درست دیده می‌شود) و لوگوی گرادیانی برای سایدبار افزونه.
* IMPROVE مدت پیش‌فرض انتظار دریافت فایل ۵ → ۱۰ دقیقه، تلاش مجدد فشار دکمه ۲ → ۴ بار، کلید تکراری autocat_min_score در تنظیمات حذف شد.

== 5.8.0 ==
* FIX: مشکل «فهرست بخش‌ها کار نمیکنه» با یک کلیک (دو هندلر کلیک باعث خنثی شدن هم می‌شد) کاملاً رفع شد — حالا سایدبار موبایل با یک هندلر واحد، backdrop و کلید ESC باز/بسته می‌شود و بعد از انتخاب منو خودکار بسته می‌شود.
* FIX: جداول و نوشته‌های طولانی دیگر به صورت عمودی نمی‌افتند — word-break:break-all حذف شد، از overflow-wrap:break-word و ellipsis و line-clamp ۲ خطی برای نام فایل استفاده شد؛ جدول داخل sti-table-wrap اسکرول افقی نرم دارد و هیچ کادری از قالب بیرون نمی‌زند.
* FIX: فرم «شروع واردات جدید» (شناسه پست، تعداد محصول، استراتژی، دسته‌بندی) روی موبایل تک‌ستونه و مرتب شد — inputs 16px برای جلوگیری از زوم iOS و فاصله‌های استاندارد.
* CRITICAL FIX: خطای «Undefined property: stdClass::$last_error» که باعث می‌شد محصول بعد از دانلود ساخته نشود — کلاس STI_Session_Row امن ساخته شد (هر ویژگی ناموجود null برمی‌گرداند بدون هشدار)، همه‌ی دسترسی‌های خطرناک با ?? محافظت شد و finalize_session با error handler سفارشی هشدارها را سرکوب می‌کند تا هاست‌هایی که هشدار را به Exception تبدیل می‌کنند محصول را خراب نکنند.
* بهبود ذخیره تصویر MTProto: عکس‌های کانال خصوصی مستقیماً به مدیا لایبرری وردپرس ذخیره می‌شوند (Attachment ID هم ذخیره می‌شود) تا Product Builder دوباره مجبور به دانلود از FTP نباشد و خطای Not Found ندهد؛ fallback به روش قدیمی حفظ شد.
* Product Builder مقاوم شد: اگر image_url قبلاً در مدیا باشد از همان Attachment استفاده می‌کند، در غیر این صورت sideload می‌کند؛ Attachment ID عددی مستقیم برگردانده می‌شود؛ کل build با error handler امن اجرا می‌شود و Exception به WP_Error تمیز تبدیل می‌شود.
* UI مدرن: گرادیان، پیل‌ها، progress bar، کارت‌ها و مودال‌ها پولیش شدند؛ نسخه در ذخیره‌سازی نمایش داده می‌شود و پیام «به‌روز است» دارد.

== 5.7.0 ==
* CRITICAL: FTP code is now bulletproof against PHP warnings-as-exceptions (the "ftp_chdir(): Can't change directory to 08" killer). All FTP operations run inside a warning-swallowing error handler (warnings are captured for diagnostics instead of aborting), every fallback target has its own try/catch so one failure can't kill the chain, and ftp_ensure_dir now auto-detects the real account root (chrooted accounts like public_html) before walking parts.
* NEW ultimate fallback: if remote/FTP storage fails for ANY reason, the file is automatically stored locally on the WordPress host and the product is still created with a working local download link (a clear warning is logged). The product can no longer be lost to a download-host problem. Tested: dead FTP port → local fallback works.
* Product build retries once after 3s if the first attempt fails (slow download host / Telegram hiccup), and featured-image sideload retries after 2s — the "Not Found" product-build failure now self-heals.
* Speed: the Channel Import page now drives processing itself via a new AJAX poll (pumps a chunk each poll + returns the list) — imports progress every ~4s while the page is open, no dependence on WP-Cron timing; wait-stage polls tightened to 5-9s.
* Menu cleaned up: "Mode 2", "ایمپورت پلاس" and "ابزار اصلاح عنوان" removed from the menu (their code is preserved, nothing breaks). Sidebar now includes ALL main pages including "واردات از کانال".
* New modern plugin icon (gradient indigo→violet with a download arrow, rounded corners) — consistent with the dashboard theme instead of the old orange square.
* Hard mobile overflow guarantee: box-sizing:border-box everywhere, word-break/overflow-wrap on all table cells, code/pre wrap instead of overflowing, all panels/inputs/tables capped at 100% width, smaller table minimum widths on phones (500px), tiny-screen (360px) rules. Nothing can stick out of the template anymore — no more zoom-out needed.
* Mobile sidebar drawer fixed to work with the new design (fixed right drawer + backdrop on ≤900px, column nav).
* CRITICAL "Not Found" fix: product featured images are now stored directly into the WordPress media library (from the already-downloaded local file) instead of being sideloaded from the FTP URL at build time — slow download hosts could return 404 ("Not Found") at that moment and fail the whole product build even though the file itself was uploaded. Images are now always available locally, so product creation can no longer fail on the image step.
* Speed: import pipeline tuned to be noticeably faster while staying human-like — shorter delays between button presses (1.5-4s, up to 8 per chunk), faster wait polls (8-15s), reduced fetch timeout (5 min), faster chunk scheduling.
* Fully responsive mobile UI: single-column forms (16px inputs to prevent iOS zoom), horizontally scrollable tables that never overflow the template, drawer-style sidebar with hamburger + backdrop on phones, stacked hero/metrics/cards, touch-friendly targets — the whole admin works comfortably on a phone.
* Polished motion: subtle fade-in animations on panels/cards, refined focus rings and selection color.
* Brand-new "گزارش‌ها (Logs)" page: dedicated log viewer with level filter (info/success/warning/error), free-text search, server-side pagination (25 per page) and a one-click purge button — logs no longer clutter the dashboard or get lost in a wall of text.
* Comprehensive design-system upgrade applied to EVERY admin page: hero banner, metric cards, health bar, queue cards, log rows, modal dialogs, form tables, pagination — all in one consistent modern visual language with soft shadows, gradient accents, status pills and a fully responsive mobile layout.
* Dashboard cleaned up: activity feed now shows only the 8 most recent events and links to the full Logs page.
* Auto-retention: logs are cleaned automatically after 7 days (and can be purged manually from the Logs page).
* Robust media download: the "Not Found" download error (expired Telegram file references) is fixed — download_media_robust() re-fetches the message fresh via getMessages (peer+id) and retries up to 3 times with human-like pauses before giving up; friendly Persian error messages for NOT_FOUND / FILE_REFERENCE_EXPIRED.
* Batch completion safety: a batch can no longer stay "running at 100%" forever — if the stage machine reaches "done", the batch is force-completed; progress now reflects actually-downloaded files (not just imported sessions), so the bar is honest while files are still coming from the bot.
* Channel Import page is fully AJAX now: live polling every 5s (only while a batch is active), no more full-page reloads; the table, progress bars, status pills and stage labels ("خواندن پیام‌ها / فشار دکمه‌ها / در انتظار فایل ربات") update in place.
* Complete modern UI overhaul (v5.3): new design layer (modern.css) — consistent cards, gradient primary buttons, soft status pills, real progress bars, clean scrollable tables, blurred modal, refined sidebar, fully responsive for mobile. Inline style clutter removed from Channel Import and Sessions pages.
* CRITICAL FIX: inline button parser now correctly reads the MTProto structure (reply_markup.rows[].buttons[]) — previously no button was ever detected, so the bot was never asked for the file. Verified against the real Fileech flow: the channel button is a keyboardButtonUrl to t.me/FileechBot?start=CODE; the plugin now opens the bot dialog with /start <payload> (falling back to the file code as payload), waits for the bot's ZIP (which is matched by code in the caption OR in the filename, e.g. Magnific_415467254.zip), downloads it, attaches it to the session and builds the product.
* Full button strategy matrix: keyboardButtonCallback → getBotCallbackAnswer; keyboardButtonUrl → t.me/Bot?start=… → open bot dialog; plain URL → direct download link; unsupported types (switch_inline/web_app) → full reply_markup logged and shown in the batch detail (scrollable) so any future bot variant can be matched.
* Duplicate import requests are now prevented: starting an import for a channel+category that already has an active batch returns the existing batch instead of creating a second one (fixes "2 requests registered").
* Fixed a batch that could stall with the 6s auto-refresh: inline pump now processes batches updated more than 3s ago (was 10s).
* Human-like behavior: random 4-10s delays between button presses (max 4 per chunk), random 15-30s gaps between wait polls, jittered chunk scheduling — no bot-like bursts that could trigger flood/firewall detection.
* MTProto import rewritten as a 3-stage state machine matching the real Fileech flow: (1) collect — reads history, creates a session per code with image+caption, queues the download buttons; (2) press — presses each callback "download" button (or opens the bot chat via t.me/Bot?start=CODE when the button is a bot URL); (3) wait — polls all recent dialogs (not just top messages) for the file the bot sends, matches it to the waiting code (by caption code or FIFO order), downloads it, attaches it to the session and builds the product. When all files arrive or the wait deadline (8 min) passes, waiting sessions are closed with a clear error and the batch finishes (partial).
* Diagnostic: if a message has no detectable download button, the raw reply_markup structure is logged and shown in the batch detail so the exact bot button type can be identified and matched.
* Duplicates are only counted for existing products (draft or published, non-trash); incomplete sessions never count as duplicates and their codes can be re-imported.
* Batch detail view now shows "⏳ در انتظار ربات" for messages whose file is coming from the bot.
* Fixed Channel Import importing more messages than requested (e.g. 7 instead of 2): the MTProto chunk now stops as soon as the desired count is reached.
* The import batch detail view now shows exactly why a session is incomplete (image ✅/❌, file ✅/❌, and the exact download/storage error), and the sessions list shows the missing fields per session (category / title / featured image / download file).
* Queue publishing rewritten to be fully native (WP-Cron tick every 60s + atomic DB lock): no more dependency on Action Scheduler, no more "5 minutes becomes 1 hour" stalls or "2 posts in 1 minute" bursts. Exactly one product per tick, spaced precisely by the configured interval; self-healing cron scheduling; "publish now" button per session on the sessions page.
* File storage verification is now non-fatal: if the just-uploaded file's public URL is not immediately reachable (slow hosts/CDN), the file is kept and only a warning is logged instead of failing the whole session.
* Session list page now also shows the exact missing fields ("ناقص — کمبود: تصویر شاخص، فایل دانلود...") so you always know what a draft product is missing.
* FTP storage is now bulletproof with a 3-layer fallback: full path (category/2026/08) → category only → the configured root folder. If the FTP host refuses to create directories (MKD blocked — common on shared hosts), the file still gets uploaded to the existing root folder and the product is created with a working link. Directory creation walks part-by-part from the account root using simple MKD names (the FTP standard) and falls back gracefully.
* Added a "تست کامل FTP" button on the storage settings page: connects, logs in, creates a test folder, uploads a real test file and reports exactly which capability the FTP host allows — so problems are found before an import runs.
* Added the plugin version display on the storage settings page with a warning if an old version is running (a stale OPcache/old zip is the usual reason a fixed error still appears).
* Fixed FTP "Can't change directory to .../2026/08" on shared hosts: ftp_mkdir was called with an absolute path, which many FTP servers (Pure-FTPd/ProFTPD) reject. Directory creation now walks part-by-part: chdir to parent, MKD with the simple name, chdir into it. Clear Persian error if the FTP account cannot create directories.
* Fixed the public download URL for remote (FTP) storage: the web root prefix (public_html/htdocs/www/...) is now stripped from the public URL so the download link matches the real file location (e.g. FTP path public_html/gfx/font → URL /gfx/font/...).
* Fixed "Call to undefined method danog\MadelineProto\API::get_pwr_chat()" — in MadelineProto v9 the method is getPwrChat() (snake_case renamed to camelCase); the plugin now tries getPwrChat() first, falls back to get_pwr_chat()/getInfo()/getFullInfo() for older builds, and normalizes all result shapes (Chat/fullChat/chat/user wrappers).
* Friendly Persian messages for channel-related RPC errors (USERNAME_NOT_OCCUPIED, CHANNEL_PRIVATE, CHANNEL_INVALID, PEER_ID_INVALID, ...).
* Login flow fixes: replaced the broken getAuthorizationState()/start() detection (start() echoes HTML in web context and corrupted AJAX responses) with the v9 getAuthorization() int state; MadelineProto logger now writes to a file instead of stdout so JSON responses stay clean; all AJAX replies are buffered (any stray output is discarded before sending JSON).
* The login code hash (phone_code_hash) is now stored in a transient, so completing the login works even if the session state is lost between the "send code" and "enter code" requests (common on shared hosts); the login state machine is re-hydrated automatically when needed.
* 2FA (two-step password) now has a raw fallback via PasswordCalculator when the session state is lost.
* Friendly Persian error messages for Telegram RPC errors (PHONE_CODE_INVALID, PHONE_CODE_EXPIRED, FLOOD_WAIT, API_ID_INVALID, ...).
* "Send code" button now locks for 25s after success to prevent duplicate SMS codes.
* Fixed "Composer autoloader detected" error when loading the MadelineProto engine on sites that load a Composer autoloader (most sites with modern plugins): the plugin now defines MADELINE_ALLOW_COMPOSER before loading the phar, exactly as MadelineProto officially recommends, so login/imports work on any WordPress site.
* Engine installer now tries 4 download sources (official GitHub + phar.madelineproto.xyz + ghfast.top + gh.llkk.cc + gh-proxy.com mirrors), supports resume of interrupted downloads, and shows precise cURL error details instead of a generic "HTTP 0".
* Fixed engine file naming: the MadelineProto phar must keep its original filename (madeline81.phar / madeline74.phar) because it has an internal self-reference; the plugin now always stores and loads it under the correct name.
* Fixed a lazy-load detection bug: MadelineProto classes are lazy-loaded, so health checks now verify the API class with autoload enabled.
* Channel Import: added "اکانت شخصی تلگرام (MTProto)" strategy (MadelineProto) — the only way to import from PRIVATE channels/groups like @FileechParty. Login with your own api_id/api_hash/phone (my.telegram.org), read full history, download files without the 20MB bot limit, and auto-press inline "download" buttons as the user.
* Fixed the broken "t.me/s/@Channel" URL format in connection tests; all formats are now supported (t.me/User, t.me/s/User, t.me/User/123, t.me/+invite, @User).
* Fixed false-positive scrape detection: private channels used to pass the check because og:description/og:image exist on their page too; now real message markup (data-post) is required.
* Imports now run in the background (WP-Cron worker, chunked) so AJAX no longer times out and progress updates live; "پردازش فوری" button for hosts where WP-Cron is disabled.
* Better connection test: detects public/private/not-found, shows channel title/member count, suggests the right strategy.
* Channel Import category dropdown now lists ALL categories (inactive ones are marked) so categories like "logo" no longer disappear.

== 4.2.0 ==

* Fixed the "file saved but final link is 404" bug for categories with a Persian/emoji label: file storage paths (local + FTP) now use a dedicated ASCII-only folder key per category instead of the raw Telegram button label, so FTP-vs-HTTP charset differences can no longer break the public download link. Existing categories are migrated automatically; new categories get an auto-generated folder key that you can also edit in دسته‌بندی‌ها.
* Content generation now supports registering multiple AI API profiles (محتوا و قالب‌ها) with a manual/time-based/round-robin rotation strategy — if the selected API fails or is out of quota, the others are tried automatically before falling back to the free template, so a single provider's token limit no longer blocks product creation.
* Fixed a rare race condition where two overlapping queue ticks could both acquire the publish lock after it went stale, risking a product being published/updated twice; lock acquisition is now a single atomic database operation.
* Refreshed the admin UI (dashboard + all settings pages) with a new visual design.

== 4.1.0 ==
* Compact WooCommerce-style operations UI, server-side session filtering/pagination, session bulk actions, and Telegram immediate queue publishing.

== 4.0.0 ==
* Operations dashboard redesign, queue health monitoring, a real queue lock, retry backoff, accurate queue counts, and queue database indexes.
* This upgrade preserves existing products, settings, sessions and queue items; database migration only adds queue metadata/indexes.

== 3.4.0 ==
* Fixed binary Telegram downloads when both a custom API gateway and proxy are configured: custom gateways are now reached directly.
* Tries gateway-direct, official Telegram via proxy, and official Telegram direct; records safe diagnostic logs for each failure.

== 3.2.0 ==
* Added robust Telegram binary-download fallback: configured gateway/proxy then official Telegram endpoint.
* Added Photo/JPG/JPEG/PNG title and format mappings.

== 3.1.0 ==
* Added a safe title find/replace tool with category filter, preview and explicit apply action.
* Bulk import stays quiet but reports a compact progress summary after every five newly collected items.

== 3.0.1 ==
* PSD category now always creates a "دانلود فایل لایه‌باز" product title, even if the forwarded caption has an incorrect file type.
* Bulk collection is silent until product completion or a real error.

== 3.0.0 ==
* Replaced the bulk message handler completely. File Code now deterministically joins photo, caption, document and link into one session, in any order.
* Added diagnostic Bulk merge logs for each received component.

== 2.9.0 ==
* New workflow: choose Single or Bulk first, then category; adds inline cancellation.
* Bulk matching uses File Code across independently forwarded photo/caption/document/link messages.

== 2.8.0 ==
* High-throughput File Code matching: photos, captions, documents and direct links can arrive in any order and are paired deterministically.
* Removes lingering Telegram reply keyboard while retaining Telegram native commands menu.

== 2.7.2 ==
* Fixed large-file direct-link handling in bulk mode; image, caption and link may arrive in either order.

== 2.7.1 ==
* Fixed bulk workflow: document and featured image can now be sent in either order as separate Telegram messages.
* Removed bot slash-command registration; the inline menu remains available through /start or /menu.

== 2.7.0 ==
* Security hardening: Telegram access is deny-by-default and supports allowed Telegram User IDs.
* Products now require a successful featured image; old products can be repaired from Sessions.
* Added album-aware bulk image wait, safe inline Telegram menu, permanent delete releases SKU, and safer URL/file checks.

=== Sanil Telegram Importer ===
نسخه: 7.1.0
نیازمندی: وردپرس + ووکامرس فعال

## معرفی

این افزونه یک "اپراتور فروش فایل خودکار" است: در تلگرام دسته‌بندی، عکس، کپشن و لینک دانلود می‌فرستی
و افزونه به‌صورت خودکار محصول ووکامرس (پیش‌نویس → زمان‌بندی → انتشار) می‌سازد.

## نصب

1. پوشه sanil-telegram-importer را در wp-content/plugins آپلود کن (یا از افزونه‌ها > افزودن > آپلود افزونه، فایل zip را بده).
2. افزونه را فعال کن (ووکامرس باید از قبل فعال باشد).
3. برو به منوی «تلگرام ایمپورتر» در پیشخوان وردپرس.

## راه‌اندازی اولیه

1. **تنظیمات تلگرام:** توکن بات (از BotFather)، چت‌آیدی‌های مجاز، و در صورت نیاز پراکسی را وارد کن.
   سپس دکمه «ساخت کد امنیتی Webhook» و بعد «ثبت Webhook» را بزن.
2. **دسته‌بندی‌ها:** هر دسته را به یک دسته‌بندی واقعی ووکامرس وصل کن، قیمت و تاخیر انتشار اختصاصی بده.
3. **ذخیره‌سازی فایل:** انتخاب کن فایل نهایی روی هاست همین سایت ذخیره شود یا روی یک هاست خارجی (FTP/API).
4. **محتوا و قالب‌ها:** قالب پیش‌فرض توضیحات محصول و تاخیر انتشار سراسری را تنظیم کن.

## استفاده در تلگرام

- `/start` → انتخاب دسته‌بندی از دکمه‌های شیشه‌ای
- سپس به هر ترتیب: عکس، کپشن (شامل File Name / File Type / File Code)، و یک پیام جدا حاوی لینک دانلود مستقیم فایل
  (لینک باید روی هاست/سرور خودتان و پایدار باشد — نه لینک موقت تلگرامی/سرویس‌های اشتراک‌گذاری که منقضی می‌شوند)
- `/status` → دیدن وضعیت فعلی و موارد باقی‌مانده
- `/cancel` → لغو Session باز فعلی

به‌محض تکمیل اطلاعات، افزونه به‌طور خودکار فایل را از لینک شما دانلود کرده، در مقصد انتخابی ذخیره‌ی دائمی می‌کند،
توضیحات را (با اسکرپینگ رایگان از لینک مرجع + قالب دسته‌بندی) می‌سازد، محصول را Draft می‌کند و طبق زمان‌بندی منتشر می‌کند.

## نکات فنی مهم

- این افزونه به‌صورت پیش‌فرض به هیچ API پولی وابسته نیست (تولید توضیحات کاملاً رایگان است).
- اگر هاست شما به api.telegram.org محدود دسترسی دارد (پراکسی فقط برای فراخوانی‌های متنی)،
  حتماً از مسیر «لینک دانلود مستقیم» به‌جای پیوست مستقیم فایل تلگرامی استفاده کنید.
- افزونه کاملاً portable است: تمام تنظیمات (توکن، دسته‌ها، قیمت‌ها، قالب‌ها، محل ذخیره) در دیتابیس همین سایت است
  و می‌توانید افزونه را روی هر سایت وردپرسی دیگری هم نصب و پیکربندی کنید.
