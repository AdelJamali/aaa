#!/usr/bin/env python3
"""
10.11 regression suite (static — runs without a PHP binary).

Covers the P13 checklist that is statically verifiable:
  R0  P0 sys_getloadavg fix (delegates to test-p0-governor-loadavg.py)
  R1  New AJAX hooks are registered (Start/Stop/poll/review-poll)
  R2  Every new AJAX handler calls check_ajax() (nonce + capability)
  R3  No new DB table in 10.11 (line state is a plain option — no schema change)
  R4  Stage-transition invariants (mirror of STI_GS_Stage::valid_transition)
  R5  Retry regression: bounded + backoff + give-up + standard failure event
  R6  IPC recovery regression: fault record + heal path intact
  R7  Duplicate protection: enqueue idempotent + atomic claim in publish tick
  R8  Publish handoff: post re-read verification before PUBLISHED
  R9  Graceful STOP: worker gates on line state; stop() kills nothing
  R10 START after STOP: start() = worker enable + cron ensure + RUNNING
  R11 Worker-leak protection: reap_ipc_orphans still wired in watchdog
  R12 No output-leak into AJAX (print_r/var_dump/display_errors in GS classes)
  R13 Dashboards never fatal: monitor()/evaluate() exception-wrapped
  R14 class-gs-line.php is required by the main plugin file
"""
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
P = 'plugin/sanil-telegram-importer'

def rd(rel):
    with open(os.path.join(ROOT, rel), encoding='utf-8') as f:
        return f.read()

failures = []
def check(name, cond, detail=''):
    print(('PASS' if cond else 'FAIL'), name, ('— ' + detail if detail and not cond else ''))
    if not cond:
        failures.append(name)

# ── R0: P0 test ─────────────────────────────────────────────
r = subprocess.run([sys.executable, os.path.join(ROOT, 'tests/test-p0-governor-loadavg.py')],
                   capture_output=True, text=True)
check('R0 P0 sys_getloadavg regression', r.returncode == 0, r.stdout[-400:] + r.stderr[-200:])

# ── R1: AJAX hooks registered ───────────────────────────────
tw = rd(P + '/includes/golden-scan/class-gs-test-wizard.php')
for hook, method in [
    ('sti_gs_line_start', 'ajax_line_start'),
    ('sti_gs_line_stop', 'ajax_line_stop'),
    ('sti_gs_pipeline_poll', 'ajax_pipeline_poll'),
    ('sti_gs_review_poll', 'ajax_review_poll'),
    ('sti_gs_review_fix', 'ajax_review_fix'),
    ('sti_gs_automation_save', 'ajax_automation_save'),
    ('sti_gs_pipeline_start', 'ajax_pipeline_start'),
]:
    check(f'R1 hook {hook} registered',
          f"wp_ajax_{hook}" in tw and f"'{method}'" in tw)

# ── R2: every new handler calls check_ajax() ────────────────
for method in ['ajax_line_start', 'ajax_line_stop', 'ajax_pipeline_poll', 'ajax_review_poll']:
    m = re.search(r'function ' + method + r'\(\)\s*\{(.*?)\n\t\}', tw, re.S)
    check(f'R2 {method} calls check_ajax()', bool(m) and 'check_ajax()' in m.group(1))

# ── R3: no new table in 10.11 ───────────────────────────────
line_php = rd(P + '/includes/golden-scan/class-gs-line.php')
check('R3 STI_GS_Line uses wp_options only (no schema change)',
      'wp_options' in line_php and 'CREATE TABLE' not in line_php and 'dbDelta' not in line_php)

# ── R4: stage-transition invariants (mirror of the PHP map) ─
stage_php = rd(P + '/includes/golden-scan/class-gs-stage.php')
MAP = {}
for m in re.finditer(r"'([A-Z_]+)'\s*=>\s*array\(\s*self::(\w+)\s*,\s*self::(\w+)\s*\)", stage_php):
    MAP[m.group(1)] = (m.group(2), m.group(3))
FINAL = {}
fm = re.search(r'const FINAL_MAP = array\((.*?)\);', stage_php, re.S)
for m in re.finditer(r"'([A-Z_]+)'\s*=>\s*self::(\w+)", fm.group(1)):
    FINAL[m.group(1)] = m.group(2)

STAGE_ORDER = ['DISCOVER', 'BOT', 'MATCH', 'DOWNLOAD', 'MEDIA', 'PRODUCT', 'PUBLISH']
STATUSES = {'PENDING', 'RUNNING', 'WAITING', 'FAILED', 'COMPLETED'}
FINALS = {'FINAL_PUBLISHED', 'FINAL_REVIEW', 'FINAL_CANCELLED'}  # constant names

ok_map = all(s in STAGE_ORDER and st in STATUSES for s, st in MAP.values())
ok_finals = all(f in FINALS for f in FINAL.values())
check('R4a MAP covers only known stages/statuses', ok_map, str([k for k, v in MAP.items() if v[0] not in STAGE_ORDER or v[1] not in STATUSES]))
check('R4b FINAL_MAP values are the 3 allowed finals', ok_finals)

def valid_transition(frm, to):
    if frm == to:
        return True
    ff, tf = FINAL.get(frm), FINAL.get(to)
    if ff and tf:
        return tf == 'FINAL_PUBLISHED'
    if ff:
        return False
    if tf:
        if tf == 'FINAL_PUBLISHED':
            return frm in ('PRODUCT_READY', 'REVIEW_READY')
        return True
    fs, ts = MAP.get(frm, (None,))[0], MAP.get(to, (None,))[0]
    if fs is None or ts is None:
        return False
    return 0 <= STAGE_ORDER.index(ts) - STAGE_ORDER.index(fs) <= 1

# invariants
inv1 = all(not valid_transition(f, to) for f in FINAL for to in list(MAP) + list(FINAL) if to != f and FINAL.get(to) != 'FINAL_PUBLISHED')
# PUBLISHED is the only final that is restricted: only PRODUCT_READY/REVIEW_READY may enter it.
# REVIEW/CANCELLED finals are entered by design (REVIEW gate / user cancel) — that is the 10.10 contract.
inv2 = all(not valid_transition(frm, 'PUBLISHED') for frm in MAP if frm not in ('PRODUCT_READY', 'REVIEW_READY'))
inv3 = valid_transition('PRODUCT_READY', 'PUBLISHED') and valid_transition('REVIEW_READY', 'PUBLISHED')
inv4 = not valid_transition('SCANNED', 'DOWNLOADED')      # jump DISCOVER->MEDIA
inv5 = not valid_transition('DOWNLOADING', 'BUTTON_FOUND')  # backwards jump
check('R4c final states never transition (except to PUBLISHED rule)', inv1)
check('R4d non-final never jumps into PUBLISHED except PRODUCT_READY/REVIEW_READY', inv2)
check('R4d2 REVIEW gate: non-final -> REVIEW finals allowed by design (e.g. DOWNLOADING->NEEDS_REVIEW)',
      valid_transition('DOWNLOADING', 'NEEDS_REVIEW') and not valid_transition('NEEDS_REVIEW', 'DOWNLOADING'))
check('R4e PRODUCT_READY/REVIEW_READY -> PUBLISHED allowed', inv3)
check('R4f no stage jumps (SCANNED->DOWNLOADED) or backwards (DOWNLOADING->BUTTON_FOUND)', inv4 and inv5)
# REVIEW_READY must be PUBLISH/WAITING (queue-waiting, not final)
check('R4g REVIEW_READY mapped to PUBLISH/WAITING (not final)', MAP.get('REVIEW_READY') == ('PUBLISH', 'WAITING'))
check('R4h FILE_MATCHED is DOWNLOAD stage (10.10 decision)', MAP.get('FILE_MATCHED') == ('DOWNLOAD', 'PENDING'))

# ── R5: retry regression ────────────────────────────────────
worker = rd(P + '/includes/golden-scan/class-gs-auto-worker.php')
check('R5a retry_limit() from Automation settings', "STI_GS_Automation::get( 'session_retry_limit' )" in worker)
check('R5b give-up backoff (6h) kept', 'RETRY_AFTER_GIVEUP = 6 * HOUR_IN_SECONDS' in worker)
check('R5c configurable backoff base (10.11)', "STI_GS_Automation::get( 'backoff_base_minutes' )" in worker)
check('R5d standard failure event at every failure exit (4 exits + def)', worker.count('self::log_failure(') >= 4)
check('R5e log_failure carries attempts+delay+identity',
      all(k in worker for k in ["$attempts", "self::format_delay", "file_code"]))

# ── R6: IPC recovery regression ─────────────────────────────
recovery = rd(P + '/includes/golden-scan/class-gs-recovery.php')
check('R6a is_ipc_fault + record_ipc_fault intact', 'function is_ipc_fault' in recovery and 'function record_ipc_fault' in recovery)
check('R6b worker records IPC faults on failure', 'STI_GS_Recovery::record_ipc_fault()' in worker)
check('R6c governor consumes fault window', 'STI_GS_Recovery::ipc_faults_recent()' in rd(P + '/includes/golden-scan/class-gs-governor.php'))

# ── R7/R8: duplicate protection + publish handoff ───────────
pq = rd(P + '/includes/golden-scan/class-gs-publish-queue.php')
check('R7a enqueue idempotent (queued/published → true)',
      ("'queued' === $session['queue_status']" in pq) and ("'published' === $session['queue_status']" in pq))
check('R7b atomic claim in publish tick', 'STI_GS_Session::claim(' in pq)
check('R8a publish re-reads post status (verification)',
      "'publish' !== get_post_status( $product_id )" in pq)
check('R8b PUBLISHED only after verified publish',
      "'state'        => 'PUBLISHED'" in pq)

# ── R9/R10: graceful STOP / START-after-STOP ────────────────
check('R9a worker gates on STOPPED', "STI_GS_Line::STOPPED === $line" in worker)
check('R9b worker gates on PAUSING + finalize', "STI_GS_Line::PAUSING === $line" in worker and 'finalize_pause()' in worker)
stop_fn = re.search(r'public static function stop\(\)\s*\{(.*?)\n\t\}', line_php, re.S).group(1)
check('R9c stop() kills nothing (no pkill/kill/ipc_heal in stop path)',
      not re.search(r'pkill|posix_kill|ipc_heal|exec\s*\(', stop_fn))
check('R9d stop() does not delete queue data', 'DELETE' not in stop_fn)
start_fn = re.search(r'public static function start\(\)\s*\{(.*?)\n\t\}', line_php, re.S).group(1)
check('R10a start() enables worker', 'set_enabled( true )' in start_fn)
check('R10b start() ensures cron (reschedule if missing)', "wp_next_scheduled( STI_GS_Auto_Worker::HOOK )" in start_fn and 'wp_schedule_event' in start_fn)
check('R10c start() → RUNNING', "set_state( self::RUNNING" in start_fn)
check('R10d tick() exception-safe: ERROR state on \Throwable', 'catch ( \\Throwable $e )' in worker and 'STI_GS_Line::mark_error()' in worker)
check('R10e tick() self-heals ERROR on success', 'STI_GS_Line::clear_error()' in worker)

# ── R11: worker-leak protection ─────────────────────────────
check('R11 reap_ipc_orphans wired in watchdog tick',
      'self::reap_ipc_orphans()' in recovery and 'function reap_ipc_orphans' in recovery)

# ── R12: no output leak in GS classes ───────────────────────
leak = []
for root, _d, files in os.walk(os.path.join(ROOT, P, 'includes/golden-scan')):
    for f in files:
        if f.endswith('.php'):
            c = rd(os.path.relpath(os.path.join(root, f), ROOT))
            if re.search(r'(?<![\w>])(print_r|var_dump)\s*\(', c) or 'display_errors' in c:
                leak.append(f)
check('R12 no print_r/var_dump/display_errors in GS classes', not leak, str(leak))

# ── R13: dashboards never fatal ─────────────────────────────
gov = rd(P + '/includes/golden-scan/class-gs-governor.php')
check('R13a governor evaluate() exception-safe',
      re.search(r'public static function evaluate\s*\(\s*\)\s*{\s*try', gov) is not None)
check('R13b line monitor() exception-safe', 'catch ( \\Throwable $e )' in line_php)

# ── R14: require in main file ───────────────────────────────
main_php = rd(P + '/sanil-telegram-importer.php')
check('R14 class-gs-line.php required by main plugin file', "require_once STI_PATH . 'includes/golden-scan/class-gs-line.php';" in main_php)

print()
if failures:
    print('10.11 REGRESSION SUITE: FAILED ->', failures)
    sys.exit(1)
print('10.11 REGRESSION SUITE: ALL PASS')
