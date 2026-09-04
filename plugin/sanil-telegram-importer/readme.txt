== 10.12.3 ==
* Version: 10.12.3 — Installable ZIP: `golden-importer-10.12.3.zip` (repo root).
* INTERNAL RUNTIME DIAGNOSTIC «🩺 تست واقعی زنجیره اتوماسیون» on the Worker page: one click reads the REAL runtime of the site (via a new read-only AJAX action `sti_gs_chain_audit`, same nonce/capability) and prints in-page: Runtime Snapshot (PHP/mem/WP/Woo/classes) · real DB counts (profiles/profile_items/messages/sessions) · Watcher state triple-check (get_option vs is_enabled() vs stats() — explicit STATE INCONSISTENCY) · DOM check of Watcher card/buttons at DOMContentLoaded · AJAX registration from runtime hooks ($wp_filter — real callback names) · full run() path trace (tick L149 → run L179 → create_sessions L369, UI path ajax_watcher_run L86 → run() direct L91) · read-only create_sessions simulation (same selection SQL + same rejection conditions, zero INSERT: NO_ITEM/EXISTING_SESSION/WOULD_CREATE) · create_from_profile_item caller (L422 automatic + manual) · session/pipeline/worker DB samples · Fiber/mem + Telegram status · FINAL table (14 rows) + FIRST HARD BREAK + PART-15 headline (BLOCKED — WATCHER DISABLED / AUTOMATION EXECUTION FAILURE / REJECTION PATH).
* SAFETY: audit is strictly read-only (SELECT/SHOW/get_option/class_exists/method_exists — no INSERT/UPDATE/DELETE, no set_enabled, no run()). Two OPTIONAL buttons («⚠️ تست واقعی Toggle» / «⚠️ تست واقعی Watcher Run») exist but are never auto-run; they call the existing real endpoints only after explicit user confirm.
* New file: `includes/golden-scan/class-gs-chain-audit.php`; wired in main file (require + init). No change to any business logic — all other files byte-identical to 10.12.2.
* Tests: 10.12 workflow 17/17 + 10.11 regression + P0 governor — all PASS.

== 10.12.2 ==
* Version: 10.12.2 — Installable ZIP: `golden-importer-10.12.2.zip` (repo root).
* TEMPORARY READ-ONLY DIAGNOSTIC (Worker page): «🧪 Watcher Runtime Diagnostic» box on the Queue / پردازش خودکار page traces the Watcher button chain at runtime — HTML ← Script ← bind() ← click ← handler ← AJAX(fetch) ← PHP — and prints the FIRST BROKEN POINT / EVIDENCE / FILE / LINE / NEXT ACTION directly in the UI (no console needed). Pure observation: pass-through wrappers on `addEventListener`/`fetch` + a capture-phase click probe; no AJAX action, no DB, no state change, no modification of any existing handler or business logic.
* Scope: only `admin/views/golden-scan/worker.php` (view + inline diagnostic script) — Session creation, Watcher logic, Worker, Queue, Database, AJAX handlers and all business logic are byte-identical to 10.12.1. REMOVE AFTER AUDIT (marked `STI-TMP-DIAG`).
* Tests: 10.12 workflow 17/17 + 10.11 regression + P0 governor — all PASS.

== 10.12.1 ==
* Version: 10.12.1 — Installable ZIP: `golden-importer-10.12.1.zip` (repo root).
* BUGFIX (START/STOP freeze — root cause: cache inconsistency, accepted): `STI_GS_Line::set_state()` / `transition()` write the line state with raw SQL on `wp_options` without object-cache synchronization. On hosts with a persistent object cache (Redis/Memcached), every `get_option()` reader — the UI, AJAX responses, the auto-worker tick and the diagnostics — kept reading the stale state indefinitely: START «did nothing» (green success response), state frozen at STOPPED, Active=0 with a non-empty Queue. Fix: `wp_cache_set( self::OPTION, $to, 'options' )` after each successful raw write — the same contract `update_option()` maintains. Scope: only `class-gs-line.php`, only those two functions; no refactor, no UI, no cron/worker changes.
* NEW read-only diagnostic on 📦 صف انتشار: «🔍 پیش‌نمایش دقیق (بدون ساخت)» dry-runs the exact production path per selected item (same selection SQL incl. category + priority, same gates as `create_from_profile_item`) and labels every selected profile item `sti_gs_no_item` / `existing_session` / `would_create` / `sti_gs_session_insert_failed`, with a closed verdict (P1_SELECTION_ERROR / P1_SELECTION_EMPTY / P2_ORPHAN_MESSAGES / P3_INSERT_LAYER / MIXED / SHOULD_CREATE). Strictly read-only: SELECT/DESCRIBE/SHOW only — no INSERT/UPDATE/DELETE, no session/queue/state/worker/cron side effects; one warning line retained in the reports page. New read-only AJAX action `sti_gs_publish_queue_dryrun` (same nonce/capability as the rest).
* No destructive DB changes; all existing actions, nonces and capabilities untouched. Tests: 10.12 workflow 17/17 + 10.11 regression + P0 governor — all PASS.

== 10.12 ==
* Version: 10.12 — Installable ZIP: `golden-importer-10.12.zip` (repo root). Workflow refactor per the approved architecture: the UI now follows the real system order — Channel → Scan → Analyze → Select Categories → Publish Queue → Schedule → Pipeline → Publish.
* NEW 📦 صف انتشار tab (publish center, gs_view=publish-queue) with three sections: (1) category selection — real available counts per category (GROUP BY profile.default_category_id) + real prices (sti_categories.price via woo_term_id) + per-category publish counts; (2) add products to queue — live preview (category/available/selected/price/schedule 09:00·09:30·…) with immediate or scheduled mode (interval + optional start time); (3) publish schedule — read-only queue stats + next 10 scheduled rows.
* Product-first language: the UI now says «محصول برای انتشار» — Session remains a backend-internal concept only (API/logs unchanged).
* Partial publishing with priority: «دسته: موکاپ / موجود: 2200 / تعداد: 50» selects the 50 highest-score items (score DESC, id ASC) — `create_sessions($room, $wc_term_id=null, $priority_order=false, &$created_ids=null)`; defaults keep the legacy path word-for-word (cron + old action untouched).
* Scheduled publishing (approved B2): `Publish_Queue::enqueue()` now respects an operator pre-set future `scheduled_at` via max(now+60, last+interval, existing) — the queue can no longer pull a scheduled row forward; empty/past values behave exactly as before.
* Channel page restructured: Add Channel first → channel list (real inventory stats) → parallel scan → CTA card («N products ready — select categories & add to queue» + the read-only 🔍 diagnostic until the flow stabilizes). The Start card is gone from channels.
* «شناخت کانال» → «تحلیل محتوا» (content analysis) + CTA to category selection. Pipeline page is monitoring-only: LINE START/STOP + new «نمای وضعیت» card with the seven approved buckets (waiting / downloading / building / in publish queue / published / review / error) from real states.
* Workflow Stepper (four questions: where am I / next step / what gets created / how many remain) on the four main pages — read-only, no new AJAX.
* Floating-window fix (viewport): the mobile table→card thead used inset:-9999px (forced zoom-out); replaced with a visually-hidden pattern + a 10.12 viewport guard block (max-width:100%, overflow-x:hidden, min-width:0 on flex children, max-width on pre/code/img/svg/tables). Test widths: 320/360/390/412/768 + desktop 1280/1440.
* Button audit: every button on the main views has Action/AJAX/Nonce/Callback/Success/Error/Loading; gs-delete gained error handling.
* Backend wiring only: +2 read/write AJAX actions (sti_gs_publish_queue_create, sti_gs_publish_queue_save_selection), +1 gs_view; the existing 58 actions, nonces, capabilities, deep links and schema are untouched. No DELETE anywhere; no destructive DB changes.
* Tests: tests/test-10-12-workflow.py (17/17 static) + 10.11 regression 45/45 + P0 6/6 green; PHP/JS/CSS balance verified.

== 10.11-UX ==
* Version: 10.11.1 — Installable ZIP: `golden-importer-10.11.1-ux.zip` (repo root).
* UX PILOT — Golden Scan is now a Premium SaaS Operations Console (Persian, RTL, mobile-first). Presentation layer only: zero changes to business logic, state machine, worker, governor, queues, Telegram, capability checks or `gs_view` deep links; all 58 existing AJAX actions/params/nonces verified identical. «ظاهر کاملاً جدید. موتور کاملاً محفوظ.»
* New design system (`gi-console.css`): CSS tokens for Light/Dark, 4–64px spacing scale, Vazirmatn font (browser-side; graceful fallback if unreachable), 7-stage pipeline flow visualization (UI-only, counts derived from the existing monitor payload — no new queries, no response-structure changes), bento layouts (no equal cards), exception-inbox cards for Review, responsive tables→cards on mobile, glass bottom navigation (5 groups), skeleton loaders, empty states with CTA.
* Live Pipeline = Operations Control Center: LINE STATUS hero (dot + label + state), Worker/Governor/Active/Queue metrics, START/STOP, live KPIs with subtle number-change highlight, session pipeline with stage chips, live event stream.
* Review = Exception Inbox: each item answers what broke / which session / stage / cause / attempts / auto-recoveries / suggested fix, with the same «⚡ اجرای Fix پیشنهادی» (same endpoint, same params, same Verify).
* Accessibility + motion discipline: icon+label+color (color never the only status carrier), visible focus, ARIA live regions, ≥44px touch targets, `prefers-reduced-motion` kill switch, easing `cubic-bezier(0.22,1,0.36,1)`, micro 180ms / component 280ms.
* No new polling and no new AJAX: existing intervals untouched (scan 3s / review 10s / pipeline 4s / worker 20s / wizard 10s); hidden-tab guard added to worker/wizard/scan polls only (visible-tab behavior unchanged).
* Theme toggle (🌓) persists per browser; auto dark/light otherwise.
* Assets enqueued on the Golden Scan page only (`gi-console.css/js` + Vazirmatn). Self-host option: `define('STI_GI_SELF_HOST_FONT', true)` + your own `admin/assets/css/gi-fonts.css`.
* Start-Diagnostic (read-only, RC investigation) — new «🔍 اگر Start صفر ساخت — تشخیص» button on the Start card: `STI_GS_Channel_Watcher::diagnose_start()` dry-runs the exact Start path (target table/columns/UNIQUE key, READY/ELIGIBLE counts, the verbatim selection SQL, 20-candidate join/duplicate dry-run, all 5 GS cron hooks, READY/ELIGIBLE/SKIPPED/CREATED verdict) and `create_sessions()` now logs every rejected row (capped at 20 + per-code summary). Zero behavior change to session creation; one new read-only AJAX action `sti_gs_start_diagnostic` (same nonce/capability as the rest).
* Environment Health: `sti_gs_scan_worker` is no longer reported as «missing» (it is a one-shot event scheduled only while a scan runs) — shown on its own informational row.
* Docs: DESIGN-10.11-UX.md. Regression: P0 6/6 + 10.11 suite 45/45 still PASS. Installable ZIP for this version: `golden-importer-10.11.1-ux.zip` (committed at repo root, per explicit user request; host runtime acceptance still recommended before treating it as final).

== 10.11 ==
* FATAL FIX (P0) — the worker tick and dashboards were killed by `ArgumentCountError: sys_getloadavg() ...`. Root cause: the governor called `sys_getloadavg( $loads, 1 )` (two arguments) while PHP's API is `sys_getloadavg( array &$loads ): bool` (exactly one argument); and the `@` operator cannot silence it because it is an Error, not a notice. Now: correct arity, `try/catch(\Throwable)` (a non-standard host signature can never fatal), `/proc/loadavg` fallback, `null` = "no signal" (no guess), and `Governor::evaluate()` is exception-safe — it can never stop the pipeline.
* NEW Start/Stop line (P5) — `STI_GS_Line`: real line state STOPPED / RUNNING / PAUSING / DEGRADED / ERROR stored atomically (single conditional UPDATE on wp_options — the same compare-and-set pattern as the 10.9.3 cron gate; no TOCTOU). STOP is graceful: no new sessions start, the stage in flight finishes in its own request, nothing is killed, no data is deleted. START is a true continuation (worker enabled + cron ensured + RUNNING). DEGRADED is set by the Governor in EMERGENCY; ERROR is set when a tick throws and self-clears on the next successful tick.
* NEW Live Pipeline monitor (P6) — the 🏭 line tab is now the operations hub: LINE STATUS + ▶ START / ■ STOP, LIVE SUMMARY (requested/created/processing/waiting/failed/published/review/cancelled — all from real state; unavailable metric shows «نامشخص», never a fake zero), CURRENT ACTIVITY (session/stage/status/retry/worker/last activity), SESSION PIPELINE (per-session stage chips derived from the stage map, not guesswork) and LIVE EVENT STREAM (last 30 events). Light single-flight polling (configurable 2–30 s, paused while the tab is hidden) — no AJAX flood.
* UI consolidation (P8) — 12 scattered tabs merged into 5 groups (منابع / خط تولید / اتوماسیون / سلامت سیستم / گزارش‌ها) in a two-level subnav. All `gs_view` values, deep links, AJAX actions, nonces and capability checks are unchanged — nothing was removed, only regrouped. Sessions and Review Queue now live under the line.
* Review Queue upgraded (P9) — columns: Session, Telegram file, Stage, failure reason, last error, attempts, recovery count, suggested fix, status + working «▶ اجرای Fix پیشنهادی» with immediate Verify (the new state is returned and shown; a failed fix reports its real reason). The table auto-refreshes every 10 s — it is no longer static.
* Environment diagnostics (P1/P15) — real-cron detection: WP-Cron ON/OFF, DISABLE_WP_CRON, Real Cron Detected / Not detected / Unknown (read-only `crontab -l` when exec is available). When DISABLE_WP_CRON is on and real cron is missing, a clear warning + the exact crontab line to paste into the host (no plugin files touched, wp-config never modified). WP_DEBUG_DISPLAY still shows the actionable red banner.
* Governor + worker settings (P7) — tick interval, retry backoff base (minutes, doubling per failure) and monitor poll interval are now UI settings (defaults conservative: 300 s / 5 min / 4 s). Governor thresholds, budgets and retry limits were already there.
* Failure observability (P3) — every failure path now logs a standard event: stage, error class, attempt count, Telegram identity (file_code), retry delay in human-readable form — plus the existing Run Log counters and recovery/IPC-heal bumps. No credential/secret is ever logged.
* Worker tick is exception-safe: an unexpected throw becomes line state ERROR + a log line instead of a fatal in cron.
* Tests: `tests/test-p0-governor-loadavg.py` (6 assertions on the exact fatal) and `tests/test-10-11-regression.py` (45 static checks: hooks, nonces, transition invariants, retry/IPC/publish/duplicate/STOP/START/leak regressions) — all passing in CI-less static mode; runtime acceptance on the host still required.
* No destructive DB changes (the line state is a plain option; no new table).

== 10.10 ==
* AUTONOMOUS PIPELINE RELEASE — «Fully Autonomous Processing». After the user's 5 steps (register channel, scan, create profile, set session count, Start) there is no manual step left: Channel → Scan → Profile Match → Session Create → Bot Interaction → File Discovery → Download → Media Build → Product Build → Publish Queue → Published. Architecture doc: DESIGN-10.10.md.
* NEW Deterministic State Model (STI_GS_Stage): every session state maps to exactly ONE Stage (DISCOVER/BOT/MATCH/DOWNLOAD/MEDIA/PRODUCT/PUBLISH) + ONE Status (PENDING/RUNNING/WAITING/FAILED/COMPLETED); valid final states are only PUBLISHED / REVIEW / CANCELLED — ERROR/FAILED/BROKEN/UNKNOWN can never be a final state. Every advance must pass `valid_transition()` (same stage or +1 stage; PUBLISHED only from PRODUCT_READY/REVIEW_READY) and any violation is logged as an ANOMALY event, never guessed from logs or artifacts.
* NEW Stage-Specific Recovery (self-healing, not more blind retries): BOT → poll/inbox/resume reply; MATCH → rebuild candidates + rescore; DOWNLOAD → refresh file reference + reconnect client; MEDIA → regenerate thumbnail / skip OPTIONAL assets (a session never dies on a thumbnail); PRODUCT → fallback builder + fallback template; PUBLISH → repair metadata + republish. Priority order: Recover > Retry > Replay > Rewind; while any recovery option exists the session is NOT sent to review.
* REVIEW gate — exactly 4 reasons (STI_GS_Review): Unknown Bot Flow, Human Verification (captcha/phone), Unresolved Duplicate, Corrupted Data. Everything else keeps self-healing. Each review item carries a machine-readable recommended fix; the Review Queue tab shows Session ID, current stage, failure stage, last error, attempts, recovery count and a working «Run Suggested Fix» button (deterministic reset back into the pipeline — e.g. re-press button, rebuild candidates, re-download).
* IPC self-healing in the worker: «endpoint does not exist» / fiber-resume / missing socket → ipc_heal (scoped kill + file cleanup + fresh worker) → retry — the 10.9.3 supervisor is now wired into every session failure path, with an IPC fault window feeding the governor.
* NEW GS Queue Governor (STI_GS_Governor): samples RAM, load-per-core, recent IPC faults and queue backlog every tick. OK → full batch; THROTTLE → half; EMERGENCY (RAM ≥ 80% or IPC faults piling up) → quarter + no heavy operations. On shared hosting stability wins over speed; throttling is automatic and reversible.
* Resource budgets (Automation Settings, no file editing): Session/IPC/Download/Publish/AI retry limits, Max Active Sessions (default 1), Sessions Per Tick (default 1), Max Downloads/Products Per Tick (default 1). Max Active Sessions is a real gate: when N sessions are locked, the worker idles the tick instead of starting more.
* NEW Automation Health tab: stage matrix (per-stage PENDING/RUNNING/WAITING/FAILED), final counts, IPC workers + sockets, phar↔PHP compatibility, host RAM/load, IPC fault window, queue backlog, PHP memory peak, and the cumulative self-healing counters (retries / recoveries / IPC heals / download retries / publish retries) plus worker last/next tick.
* NEW Environment Health tab: WP_DEBUG / WP_DEBUG_LOG / WP_DEBUG_DISPLAY (red banner when DISPLAY is ON — it corrupts AJAX JSON and hides errors), DISABLE_WP_CRON, soonest cron event, missing GS cron hooks, PHP/memory/exec/RAM/load and DB migration status.
* NEW per-session run log (STI_GS_Run_Log, table sti_gs_session_runs): start/end time, duration, stage history, retry/recovery/IPC-heal/download-retry/publish-retry counts and final result — one row per session run, reset automatically on re-entry.
* NEW «Start Pipeline» (channels page): set the session count, press Start — sessions are created from ready candidates, the auto worker is guaranteed ON and an immediate tick is scheduled. From that moment nothing but the Review Queue (for the 4 human cases) requires a human.
* Watchdog step 4 (10.9.3) is now the Verify step of the Detect→Repair→Verify→Log loop for IPC; auto worker reports every tick (processed/waiting/skipped counts) for the dashboard.
* DB: version 2.4 (adds sti_gs_session_runs); no destructive changes.

== 10.9.3 ==
* STABILITY RELEASE — IPC layer, memory, workers, cron, visibility (based on the 10.9.2 audit).
* FIX (root cause) «The endpoint does not exist!» misdiagnosis — the assumption "the download method was renamed" is removed everywhere (file-hunter, press_button). Verified against the phar source: this error comes from `Amp\Ipc\connect` when the session's IPC socket file (`<session>/ipc`) is missing — i.e. the `madeline-ipc` worker process is dead or never started. In web SAPI **every** RPC (all download method names included) goes through that one IPC layer, so it is an IPC health signal, not a method-name issue.
* NEW IPC Supervisor (class-sti-mtproto.php): `rpc_fatal()` central detection of request-poisoning errors (fiber "Must call resume…" / IPC "endpoint does not exist") in every RPC catch — the poisoned client is recycled with a per-request circuit breaker (max 2); IPC errors additionally run `ipc_heal()`: scoped kill of this site's workers (pgrep matched to the exact session dir — never a bare `pkill -f madeline-ipc` that would kill other sites on a shared host) + removal of stale IPC files (`ipc`, `callback.ipc`, `ipcState.php`, `lock`) so the next call starts a clean worker. `ipc_preflight()` (once per request) clears stale state (state > 30 min without a live worker) **before** the first RPC — otherwise the phar spins 25 seconds on the dead socket. File-hunter: on IPC/fiber errors it stops burning through method names (all share the same layer) and retries the next attempt with a fresh file_reference.
* FIX (OOM) memory_limit raise now happens **before** the phar `require` inside `load_engine_phar()` (previously after it — when the require itself OOM'd on 128M hosts, the raise never ran). Plus a pre-require stub check: if the phar requires PHP 8.2+ and the host runs older PHP, a controlled error is returned instead of the phar's `die()` killing the whole WP request (the raw «MadelineProto requires at least PHP 8.2» page).
* FIX engine_healthy() result cached 1 hour per PHP version — a 19.5MB phar was being compiled on every admin refresh just for a class_exists check.
* FIX (worker leak) `cleanup_orphan_workers()` rewired: the old bare `pkill -f madeline-ipc` (unscoped, and never called) is replaced by scoped logic via `ipc_heal()`; the watchdog (Recovery tick, new step 4) now reaps orphans — more than 1 live `madeline-ipc` worker for this session is abnormal and gets cleaned with an event log + stat (this accumulation is one of the "host freezes for a few minutes" root causes).
* FIX (cron storm) Watcher: `spawn_cron()` moved out of the per-channel loop — it ran the entire WP-Cron queue once **per channel** per cycle; now once per run. New atomic cron gate `STI_GS_Cron_Gate` (single CAS UPDATE on wp_options, autoload='no') replaces the read/compare/write (TOCTOU) interval guards in auto-worker, watcher and watchdog ticks — two concurrent fake-cron requests can no longer both pass; the manual watchdog button bypasses the gate (`$force`).
* NEW Health Dashboard (System Check page): «IPC / موتور» group — phar↔PHP compatibility, memory limit/usage/peak, shell (exec) availability, IPC + callback socket state, live worker count, IPC state age (stale-state warning), worker-accumulation warning; «صف پردازش» group — pipeline depth by state with stuck-download / dead-letter warnings. Read-only; repair stays with the supervisor paths above.
* NOTE: the IPC/worker-leak conclusions are host-verified hypotheses — run the diagnostics in the 10.9.2 audit (ps aux | grep madeline-ipc, top, madeline.log "Starting process with", php -v) and compare with the new dashboard before trusting the numbers.

== 10.9.2 ==
* NEW Channel Watcher (class-gs-channel-watcher.php): the missing loop closed — the three manual steps (scan channels, run profiles, create Sessions) are automated, so «کانال → محصول منتشرشده» completes without a click. Three guards for 60k-file scale: daily Session cap, backpressure (creation pauses while unfinished Sessions exceed the threshold), and a separate daily scan cap. Candidates of profiles without a default category are deliberately NOT sessioned (no uncategorized products); they are counted and shown on the worker page. Runs every 30 minutes; worker page: status, «شروع/توقف پایش», «اجرای فوری یک چرخه».
* NEW Infrastructure Recovery layer (class-gs-recovery.php): an independent cron (sti_gs_watchdog, every 15 min) repairs what a dead request cannot repair itself — stale expired locks, orphaned Sessions, chain steps stuck in `running` (chain watchdog), error classification (transient / recoverable / permanent) driving retry scheduling, and a dead-letter state (DEAD_LETTER, worker TERMINAL) for permanent errors with one-click bulk revival. Hard ownership boundary: it never decides chain logic, never changes chain_mode, never rewrites handoff_steps, never picks a Node — it only releases locks and lets the Chain Engine decide next.
* NEW Feature Flags (class-gs-flags.php): six independent flags (error_classification, pending_states, watchdog, dead_letter, chain_watchdog, scan_limit) — a misbehaving capability can be switched off without rolling back the whole release; conservative defaults. Golden-Scan cron intervals (5/15/30 min) are registered once, centrally, before any init.
* FIX (critical) False bot timeout (Sessions 49/50): a failed history read was treated as «the bot sent nothing» and killed the Session with CHAIN_BOT_TIMEOUT while the file was already in the Inbox (chain_global_poll docs_recorded > 0, zero candidates). A read failure now falls back to the Bot Inbox as Source of Truth (chain_inbox_fallback — same anchor rules, no second getHistory); if the Inbox is also empty the Session returns CHAIN_WAITING with CHAIN_HISTORY_READ_FAILED (a runtime error that retries — not counted toward the bot's deadline). Final rescue before any timeout declaration (chain_timeout_rescued): a file present in the Inbox cancels the timeout.
* FIX STI_GS_Deadline pcntl mode was silently inert: without pcntl_async_signals(true) the SIGALRM handler was never delivered — exactly while PHP was inside a blocking network call, the moment it was needed — so the request died via the set_time_limit fallback without finally, and the lock waited out its TTL contrary to the «controlled timeout» claim. Async signal delivery is now enabled (guarded); when unavailable it honestly falls back to the safe bounded path.
* FIX DOWNLOAD_PENDING / MEDIA_PENDING: each engine moves its Session into its own pending state but its entry guard did not accept it — every retry (manual, worker, or watchdog) hit INVALID_STATE and the Session was frozen there forever. Both engines now accept their pending states, and MEDIA_PENDING is routed to Build Media in the manual pipeline.
* FIX Cron overhead on every page load: the GS scheduling check (wp_next_scheduled + get_option) no longer runs on every visitor request — hooks are registered (cheap) but scheduling is only evaluated in admin or DOING_CRON context. The GS modules stop riding on the core per-minute tick: worker and publish queue use a real 5-minute interval, recovery 15 min, watcher 30 min (previously each page load on a shared host could trigger the per-minute crons and take the site down for minutes).
* FIX Profile counts: «available files» now counts only unprocessed candidates (status = 'available') — items already queued or already holding a Session are no longer shown as available again (no data deletion, only the count and the list tell the truth).
* IMPROVE Worker page: new «پایش کانال (خودکارسازی کامل)» dashboard (ready-to-session, backpressure, created today, no-category warning) and «خودترمیمی زیرسافت» panel (recovery stats, chain watchdog, dead-letter count + revival button, manual watchdog run).

== 10.8.5 ==
* NEW Conversation-Bot support (FileechBot & similar): DeepLink → file info → code request → send code → file.
* NEW Rule 4: definitive bot text "file not found" (متاسفانه فایل درخواستی یافت نشد / file not found) → terminal state ERROR_FILE_NOT_FOUND (worker TERMINAL, review counter, dashboard) instead of 15-minute pointless polling; also applied retroactively to stuck sessions whose recorded info_last matches (first poll after upgrade).
* NEW Rule 4 guard: a "file not found" text that predates the step's last action (anchor, 10s tolerance) is a stale answer — recorded as informational (STALE_NOT_FOUND) instead of terminating, so the real file that follows the sent code is still accepted.
* NEW Rule 2/5: bot text containing "File Name :" + "File Code :" is a valid fresh response — code extracted (File Code: X / کد فایل: X), stored in step meta (file_code_seen), response window extended (clicked_at refreshed, step anchor untouched), artifacts chain_file_info + fresh_response.
* NEW Rule 3: GATE payload resolution now falls back to the code extracted from the bot's own request text, then to the last seen file code (file_code_seen); step meta is kept in sync within the same poll so a GATE request arriving right after the file info in the same batch still gets the code; if no code is available the GATE is recorded as informational (gate_no_code, no empty send, no step explosion).
* NEW Rule 3 (legacy): pre-10.8.4 TEXT steps whose text is a code are executed as send_text (chain_text_step_as_code) instead of failing as non-executable.
* Retrofit ordering: the info_last check runs after the new-message scan, so a freshly arrived file wins over an old "not found" note (no false terminal).

== 10.8.4 ==
* FIX Response Correlation (BUG-1/2/3 from Session 228963153 runtime):
* FIX Engine's own outgoing messages are no longer treated as bot responses: normalize_message keeps the `out` flag; recent_peer_messages / poll / File_Hunter::history_docs filter them out (prevents self-echo creating fake TEXT/GATE steps and fake inbox documents).
* NEW per-step Correlation Anchor stored at action dispatch (advance): expected_peer + action_at_ts + anchor_msg_id in step meta. poll() only accepts messages with id > anchor, date >= action_at - 10s, and never messages that existed before the action (before_action rejected + chain_correlate_rejected artifact).
* FIX Step Explosion: poll() now uses the last executable step (current_executable, TEXT/UNKNOWN rows ignored); informational bot texts are recorded on the current waiting step (info_count meta) instead of appending new TEXT steps; repeated GATE (gate_repeat) is informational, not a new node.
* FIX Global Poll decoupled from session decision: global_poll stays observation-only (cache kept); the candidate collector now rejects inbox rows whose codes conflict with the session file_code (candidate_rejected artifact) — a shared observation can never become session evidence without file-code correlation.

== 10.8.3 ==
* ARCH Bot Handoff stability: no Telegram/MTProto operation may hang the worker or pipeline forever.
* NEW STI_GS_Deadline guard (class-gs-deadline.php): pcntl SIGALRM → controlled STI_GS_Deadline_Exception (finally runs, lock released); no-pcntl fallback → bounded set_time_limit + stale-lock recovery via lock TTL. Honest per-mode behavior, no fake timeouts.
* FIX harden_runtime() no longer sets set_time_limit(0): every MTProto request is capped at MAX_PHP_SECONDS=590; install_engine / download_media_robust / download_media / File_Hunter::download are all bounded (previously unlimited → Session 68 hang).
* FIX FLOOD_WAIT is non-blocking: RPC flood timeout configured (method_exists-guarded) so MadelineProto throws instead of sleeping; flood detection extended (FLOOD_WAIT_n / "flood wait: n" / FloodWait exception props) → next_retry_at + WAITING return without consuming attempts.
* FIX Global Poll decoupled (Shared Observation): result cached 60s (transient) so one session's poll no longer triggers a full multi-bot getHistory scan for every tick/session; heavy scan deadline-bounded (45s).
* NEW Poll observability breadcrumbs: chain_poll_started / chain_step_started / chain_retry_scheduled artifacts pinpoint exactly where any hang occurs; step status running→waiting mapping (HandoffStep = source of truth; STATUS_RUNNING added, latest_done covers done+waiting).
* NEW Worker tick budget (240s): heavy sessions no longer starve the rest of the batch.
* DB: idempotent unique index (session_id, step_no) on handoff_steps (migrate_v24) — no destructive changes.
* Timeout levels: step exec 60s (lock 90s), global poll 45s, peer poll 25s, chat_info 15s, media photo search 20s, executor press/start_bot 80s (lock 90s), file download 560s (lock 600s).

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
