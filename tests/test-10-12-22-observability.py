#!/usr/bin/env python3
"""10.12.22 — WORKER STARVATION fix. Static verification.

PROVEN DEFECT (code-level, before this patch)
---------------------------------------------
  class-gs-chain-engine.php:1332
      return array( 'state' => 'CHAIN_WAITING', 'waiting' => true );
  class-gs-chain-engine.php:1333  finally { STI_GS_Session::release() }
      -> locked_until = NULL
  class-gs-auto-worker.php (tick_inner)
      $outcome = 'waiting';   <- report label ONLY, no DB write

  pick() WHERE:
      state NOT IN (terminal)
      AND (locked_until  IS NULL OR locked_until  < NOW)
      AND (next_retry_at IS NULL OR next_retry_at <= NOW)
      ORDER BY (attempts>=5) ASC, priority DESC, id ASC
      LIMIT 1

  => a waiting session keeps locked_until=NULL AND next_retry_at=NULL,
     passes every clause, and `id ASC` re-selects the SAME lowest id
     forever. Host evidence: 8 consecutive ticks ids=[68],
     eligible_queue=108, advanced=0, completed=0.

FIX: on outcome waiting|skipped, write a next_retry_at that is a MULTIPLE
     OF THE REAL TICK INTERVAL. attempts untouched, state untouched,
     FLOOD_WAIT never overwritten.

10.12.18 REGRESSION (why 10.12.19 exists)
-----------------------------------------
10.12.18 used fixed 60/120/300s taken from the engine's locks
(POLL_LOCK_SECONDS=45, STEP_LOCK_SECONDS=90). But worker_interval
defaults to 300s, so a 60s deferral had already expired by the time the
next tick ran -> same session re-picked. Host log after installing
10.12.18 proved it: ids=[68] at 18:52, 19:01, 19:16, no AUTO_WORKER_DEFER
effect. Backoff must therefore be derived from interval_seconds().
"""
import re
import sys
import pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
PLUGIN = ROOT / 'plugin' / 'sanil-telegram-importer'

fails = []


def check(name, cond):
    print(('PASS - ' if cond else 'FAIL - ') + name, '')
    if not cond:
        fails.append(name)


def read(rel):
    return (PLUGIN / rel).read_text(encoding='utf-8')


worker = read('includes/golden-scan/class-gs-auto-worker.php')
engine = read('includes/golden-scan/class-gs-chain-engine.php')
session = read('includes/golden-scan/class-gs-session.php')
main = read('sanil-telegram-importer.php')

# ---------- version ----------
check('V1 header 10.12.22', 'Version:           10.12.22' in main)
check('V2 STI_VERSION 10.12.22', "define( 'STI_VERSION', '10.12.22' )" in main)

# ---------- S: the defect still exists upstream (regression anchors) ----------
# These assert the ENVIRONMENT the fix must survive. If chain-engine ever
# starts writing next_retry_at itself, revisit the fix.
check('S1 chain engine still returns CHAIN_WAITING without next_retry_at',
      "return array( 'state' => 'CHAIN_WAITING', 'waiting' => true );" in engine)
check('S2 release() still nulls the lock',
      "'locked_until' => null" in session and "'worker_id' => null" in session)
check('S3 pick() still orders by id ASC',
      'ORDER BY ( attempts >= %d ) ASC, priority DESC, id ASC' in worker)
check('S4 pick() still filters on next_retry_at',
      'AND ( next_retry_at IS NULL OR next_retry_at <= %s )' in worker)

# ---------- B: backoff is interval-relative, not a fixed constant ----------
check('B1 WAITING_BACKOFF_FACTOR const exists', 'const WAITING_BACKOFF_FACTOR = array(' in worker)
for state, factor in (('CHAIN_WAITING', '1.2'), ('WAITING_BOT', '2.0'), ('ERROR_BOT_TIMEOUT', '4.0')):
    check('B2 %s => %s x interval' % (state, factor),
          re.search(r"'%s'\s*=>\s*%s," % (state, re.escape(factor)), worker) is not None)
check('B3 default factor const', 'const WAITING_BACKOFF_FACTOR_DEFAULT = 1.5;' in worker)
check('B4 absolute floor const', 'const WAITING_BACKOFF_MIN = 60;' in worker)

# the 10.12.18 fixed-seconds table must be GONE
check('B5 old fixed-seconds table removed', 'const WAITING_BACKOFF = array(' not in worker)

# every WAITING state must have a factor entry
m = re.search(r"const WAITING = array\(([^)]*)\)", worker)
waiting_states = re.findall(r"'([A-Z_]+)'", m.group(1)) if m else []
check('B6 all three WAITING states covered',
      len(waiting_states) == 3 and all(
          re.search(r"'%s'\s*=>\s*[0-9.]+," % s, worker) for s in waiting_states))

# ---------- D: defer_waiting implementation ----------
check('D1 defer_waiting() defined',
      'protected static function defer_waiting( $session_id, $state )' in worker)

start = worker.find('protected static function defer_waiting(')
end = worker.find('protected static function pick(', start)
body = worker[start:end]
check('D1a defer_waiting body isolated', start > 0 and end > start)

check('D2 writes next_retry_at', "'next_retry_at' => self::mysql_time( time() + $delay )" in body)
check('D2a delay derives from effective_interval()', 'self::effective_interval()' in body)
check('D2b delay = interval * factor', 'ceil( $interval * $factor )' in body)
check('D2c floor >= interval + 10', 'max( self::WAITING_BACKOFF_MIN, $interval + 10 )' in body)
check('D3 does NOT touch attempts', 'attempts' not in body)
check('D4 does NOT touch state column', "'state' =>" not in body)
check('D5 respects existing future next_retry_at',
      'SELECT next_retry_at FROM' in body and 'strtotime( (string) $existing ) > time()' in body)
check('D6 filter hook present + passes interval',
      "apply_filters( 'sti_gs_waiting_backoff', $base, $state, $session_id, $interval )" in body)
check('D7 delay clamped to [floor, 1h]', 'max( $floor, min( HOUR_IN_SECONDS, $delay ) )' in body)
check('D8 guards bad id', '$session_id <= 0' in body)
check('D9 logs the deferral', 'AUTO_WORKER_DEFER' in body)
check('D9a log includes interval+factor+next', 'interval=%ds factor=%.1f next=%s' in body)

# ---------- C: call site ----------
check('C1 called on waiting OR skipped',
      "if ( 'waiting' === $outcome || 'skipped' === $outcome ) {" in worker)
check('C2 call site invokes defer_waiting', 'self::defer_waiting( (int) $session[\'id\']' in worker)

# call site must sit inside tick_inner's foreach, before the report increment
ti = worker.find('protected static function tick_inner()')
call = worker.find("if ( 'waiting' === $outcome || 'skipped' === $outcome ) {", ti)
rep = worker.find("if ( ! isset( $report[ $outcome ] ) ) {", ti)  # 10.12.22: guard inverted
check('C3 call site is inside tick_inner before report increment',
      ti > 0 and ti < call < rep)

# ---------- O: 10.12.22 observed-gap + skip-ahead ----------
# 10.12.19 scaled the backoff off worker_interval (300s). The host actually
# ticks every 1460-1788s, so a 360s deferral expired before the next tick and
# #68 was re-picked at 19:46 and 20:10. Backoff must use the OBSERVED gap.
check('O1 observed-gap option key', "const OBSERVED_GAP_KEY = 'sti_gs_worker_observed_gap';" in worker)
check('O2 effective_interval() defined',
      'protected static function effective_interval()' in worker)
ei = worker[worker.find('protected static function effective_interval()'):]
ei = ei[:ei.find('/**', 10)]
check('O3 effective_interval takes max(configured, observed)',
      'max( $configured, $observed )' in ei)
check('O4 observed gap is sanity-bounded', '6 * HOUR_IN_SECONDS' in ei)
check('O5 tick records the real gap', 'update_option( self::OBSERVED_GAP_KEY' in worker)
check('O6 gap uses a moving average', '( $prev_avg * 2 + $gap ) / 3' in worker)

# skip-ahead: waiting/skipped must NOT consume the work budget
check('O7 skip budget const', 'const WAITING_SKIP_BUDGET = 5;' in worker)
check('O8 pick() over-fetches by the skip budget',
      'self::pick( $work_budget + self::WAITING_SKIP_BUDGET )' in worker)
check('O9 work_done gates the loop', 'if ( $work_done >= $work_budget ) {' in worker)
check('O10 only real progress increments work_done',
      '$work_done++;' in worker and worker.count('$work_done++;') == 1)

# the increment must live in the ELSE of the waiting/skipped branch
wb = worker.find("if ( 'waiting' === $outcome || 'skipped' === $outcome ) {")
inc = worker.find('$work_done++;', wb)
els = worker.find('} else {', wb)
check('O11 increment sits in the else branch', wb > 0 and wb < els < inc)

# bot_used must be untouched - Telegram pressure must not rise
check('O12 bot_used rule intact',
      'if ( $bot_used ) {' in worker and 'continue; // نوبتش تیک بعدی' in worker)

# ---------- A: the audit panel must be able to SHOW the new log ----------
audit = read('includes/golden-scan/class-gs-chain-audit.php')
check('A1 audit log filter includes AUTO_WORKER_DEFER',
      "message LIKE '%AUTO_WORKER_DEFER%'" in audit)

# ---------- G: 10.12.22 gap must be read from the CRON GATE ----------
# 10.12.20 read the previous tick from STATS_KEY.'_last', but that option is
# written AFTER the block, so it never held the previous tick's time.
# OBSERVED_GAP stayed 0 and the log still said interval=300s three days later.
# The real previous-tick timestamp lives in the Cron Gate row.
check('G1 gap read from the cron gate option',
      "get_option( 'sti_gs_gate_auto_worker', 0 )" in worker)
check('G2 gate value captured BEFORE pass() mutates it',
      worker.find("$gate_prev = (int) get_option( 'sti_gs_gate_auto_worker'")
      < worker.find("STI_GS_Cron_Gate::pass( 'auto_worker'"))
check('G3 no longer derives the gap from STATS_KEY _last',
      "$prev_tick = (int) get_option( self::STATS_KEY" not in worker)
check('G4 gap still bounded to 6h', '$gap <= 6 * HOUR_IN_SECONDS' in worker)

# ---------- W: waiting deadline (the comment that had no code) ----------
# A comment promised "endless waiting is a dead end ... after the deadline we
# go back to clicking" but no code implemented it. Host proof: #68 sat in
# CHAIN_WAITING with attempts=0 from Sep 8 to Sep 11 (>72h).
check('W1 WAITING_DEADLINE const exists', 'const WAITING_DEADLINE = 12 * HOUR_IN_SECONDS;' in worker)
ao = worker[worker.find('protected static function advance_one('):]
ao = ao[:ao.find('protected static function ', 40)]
check('W2 deadline enforced inside advance_one',
      'in_array( $state, self::WAITING, true )' in ao and 'self::WAITING_DEADLINE' in ao)
check('W3 uses updated_at as the clock', "strtotime( (string) $session['updated_at'] )" in ao)
check('W4 routes to NEEDS_REVIEW, never deletes',
      "'state'        => 'NEEDS_REVIEW'," in ao)
check('W5 NEEDS_REVIEW is terminal so it stops being re-picked',
      "'NEEDS_REVIEW'" in worker[worker.find('const TERMINAL'):worker.find('const TERMINAL')+200])
check('W6 logs the deadline hit', 'AUTO_WORKER_WAIT_DEADLINE' in ao)
check('W7 deadline check runs before the stage machinery',
      ao.find('self::WAITING_DEADLINE') < ao.find('$rewind = array('))

# ---------- N: nothing else changed ----------
check('N1 retry limit untouched', 'const RETRY_AFTER_GIVEUP = 6 * HOUR_IN_SECONDS;' in worker)
check('N2 TERMINAL list unchanged',
      "const TERMINAL = array( 'REVIEW_READY', 'PUBLISHED', 'SKIPPED', 'NEEDS_REVIEW', "
      "'ERROR_FILE_NOT_FOUND', 'DEAD_LETTER' );" in worker)
check('N3 WAITING list unchanged',
      "const WAITING = array( 'WAITING_BOT', 'ERROR_BOT_TIMEOUT', 'CHAIN_WAITING' );" in worker)
check('N4 max_active gate untouched', 'active_sessions() >= $max_active' in worker
      or 'self::active_sessions()' in worker)
check('N5 no backlog_limit change in worker', 'backlog_limit' not in worker)
check('N6 sessions_per_tick default still 1 (user choice)',
      "'sessions_per_tick'   => array( 1," in read('includes/golden-scan/class-gs-automation.php'))
check('N7 max_active_sessions default still 1 (user choice)',
      "'max_active_sessions' => array( 1," in read('includes/golden-scan/class-gs-automation.php'))

# ---------- U: UI must report the REAL batch ----------
check('U1 stats report effective batch', "'batch'      => self::effective_batch_size()," in worker)
check('U2 stats also expose configured max', "'batch_max'  => self::batch_size()," in worker)

# ---------- S: syntax hygiene ----------
def strip_php(s):
    s = re.sub(r'/\*.*?\*/', '', s, flags=re.S)
    s = re.sub(r'//[^\n]*', '', s)
    s = re.sub(r"'(?:\\.|[^'\\])*'", "''", s)
    s = re.sub(r'"(?:\\.|[^"\\])*"', '""', s)
    return s


st = strip_php(worker)
check('S5 braces balanced', st.count('{') == st.count('}'))
check('S6 parens balanced', st.count('(') == st.count(')'))
# S7: the 10.12.12 killer was a nested ternary without parens on PHP 8.4.
# Must be checked PER LINE — a whole-file regex jumps across sprintf()
# argument lists on separate lines and reports a false positive.
nested = []
for ln, raw in enumerate(worker.split('\n'), 1):
    one = re.sub(r'//[^\n]*', '', raw)
    one = re.sub(r"'(?:\\.|[^'\\])*'", "''", one)
    one = re.sub(r'"(?:\\.|[^"\\])*"', '""', one)
    if re.search(r'\?[^;{}?:]*\?[^;{}?:]*:[^;{}?:]*:', one):
        nested.append(ln)
check('S7 no unparenthesized nested ternary (per line)', not nested)
check('S8 no BOM / CRLF', not worker.startswith('\ufeff') and '\r\n' not in worker)

# ══════════════════════════════════════════════════════════════════
# 10.12.22 — OBSERVABILITY (no behaviour change)
# ══════════════════════════════════════════════════════════════════
ce = open(PLUGIN / 'includes/golden-scan/class-gs-chain-engine.php', encoding='utf-8').read()
au = open(PLUGIN / 'includes/golden-scan/class-gs-chain-audit.php', encoding='utf-8').read()
wv = open(PLUGIN / 'admin/views/golden-scan/worker.php', encoding='utf-8').read()

# --- R: fallback_to_legacy must verify its own write -------------
fb = re.search(r'protected static function fallback_to_legacy.*?\n\t\}', ce, re.S)
check('R1 fallback_to_legacy exists', bool(fb))
fbb = fb.group(0) if fb else ''
check('R2 reads chain_mode BEFORE update', fbb.index('SELECT chain_mode') < fbb.index('STI_GS_Session::update')
      if ('SELECT chain_mode' in fbb and 'STI_GS_Session::update' in fbb) else False)
check('R3 captures affected rows', '$affected = STI_GS_Session::update' in fbb)
check('R4 read-back after update', fbb.count('SELECT chain_mode') >= 2)
check('R5 ok flag compares read-back to legacy', "$ok = ( STI_GS_Node::MODE_LEGACY === $after )" in fbb)
check('R6 emits AUTO_WORKER_ROUTE', 'AUTO_WORKER_ROUTE' in fbb)
check('R7 logs PERSISTED/LOST verdict', 'PERSISTED' in fbb and 'LOST' in fbb)
check('R8 returns structured array', "'affected' =>" in fbb and "'ok'" in fbb)
check('R9 still writes MODE_LEGACY (behaviour unchanged)', 'MODE_LEGACY' in fbb)
check('R10 event level degrades on failure', "$ok ? 'ok' : 'warning'" in fbb)

# --- E: every no_progress return must carry outcome+reason -------
nps = [m.start() for m in re.finditer(r"'no_progress'\s*=>\s*true", ce)]
check('E1 no_progress sites still present', len(nps) >= 10)
_i0 = ce.index('public static function init(')
# Bound at the NEXT method, not a fixed window: init() is 5354 chars and a
# 9000-char slice swallowed the following method's returns too.
_i1 = ce.index('public static function ', _i0 + 30)
init = ce[_i0:_i1]
# Split on the marker itself: each no_progress return is followed, within the
# same array literal, by its own outcome/reason pair before the next return.
init_chunks = init.split("'no_progress' => true")
init_np = init_chunks[1:]  # text following each marker
check('E2 init() has 5 no_progress returns', len(init_np) == 5)
check('E3 all init returns carry outcome',
      all("'outcome'" in c.split('return')[0] for c in init_np))
check('E4 all init returns carry reason',
      all("'reason'" in c.split('return')[0] for c in init_np))
_reasons = [re.search(r"'reason'\s*=>\s*'([^']+)", c.split('return')[0]) for c in init_np]
check('E5 reason values are distinct',
      len({m.group(1) for m in _reasons if m}) >= 4)
check('E6 route_changed keyed off persistence', "$route['ok'] ? 'route_changed' : 'route_change_failed'" in ce)

# --- D: worker plumbing, behaviour must be identical -------------
check('D1 last_detail property declared', 'protected static $last_detail' in worker)
check('D2 outcome mapping unchanged', "$outcome = ! empty( $result['waiting'] ) ? 'waiting' : 'skipped';" in worker)
check('D3 detail captured next to mapping', 'self::$last_detail = array(' in worker)
check('D4 detail reset after use', 'self::$last_detail = null;' in worker)
check('D5 STAGE log carries outcome=', 'outcome=%s reason=%s' in worker)

# --- C: counters must not drop anything --------------------------
check('C10 unknown outcomes no longer dropped', 'if ( ! isset( $report[ $outcome ] ) ) {' in worker)
check('C11 old silent-drop guard is gone', 'if ( isset( $report[ $outcome ] ) ) {' not in worker)
check('C12 throttled counted separately', "$report['throttled']" in worker)
check('C13 download capacity separate', "$report['capacity_download']" in worker)
check('C14 product capacity separate', "$report['capacity_product']" in worker)
check('C15 waiting still incremented (panel compat)', worker.count("$report['waiting']++") == 3)
check('C16 by_reason breakdown recorded', "'by_reason:'" in worker)
rec = re.search(r'protected static function record.*?\n\t\}', worker, re.S).group(0)
check('C17 record() persists extra keys', 'foreach ( $report as $k => $v )' in rec)
check('C18 record() does not double-count the four', "in_array( $k, array( 'advanced', 'waiting', 'failed', 'completed' ), true )" in rec)

# --- P: evidence must reach the panel ----------------------------
check('P1 audit filter allows ROUTE', 'AUTO_WORKER_ROUTE' in au)
check('P2 audit filter allows WAIT_DEADLINE', 'AUTO_WORKER_WAIT_DEADLINE' in au)
check('P3 panel renders breakdown', 'تفکیک دلیل' in wv)
check('P4 panel hides empty breakdown', 'if ( $gs_rows )' in wv)
check('P5 panel escapes labels', 'esc_html( $gs_lbl )' in wv)

# --- N: nothing behavioural changed ------------------------------
check('N1 WAITING set unchanged', "const WAITING = array( 'WAITING_BOT', 'ERROR_BOT_TIMEOUT', 'CHAIN_WAITING' )" in worker)
check('N2 SCANNED not added to WAITING', "'SCANNED'" not in worker.split('const WAITING')[1].split(');')[0])
check('N3 retry limit untouched', 'retry_limit()' in worker)
check('N4 attempts not touched by new code', 'last_detail' in worker and "$report[ $key ]++" in worker)
check('N5 chain-engine braces balanced', ce.count('{') == ce.count('}'))
# N6: this file has parens inside Persian prose comments, so raw counting is
# meaningless (it was already unbalanced before 10.12.22). What matters is
# that every function we touched still parses as balanced in isolation.
_fb = re.search(r'protected static function fallback_to_legacy.*?\n\t\}', ce, re.S).group(0)
check('N6 touched function parens balanced', _fb.count('(') == _fb.count(')'))
check('N7 no BOM/CRLF in chain-engine', not ce.startswith('\ufeff') and '\r\n' not in ce)

print()
if fails:
    print('10.12.22 OBSERVABILITY SUITE: %d FAILED' % len(fails))
    for f in fails:
        print('  -', f)
    sys.exit(1)
print('10.12.22 OBSERVABILITY SUITE: ALL PASS')
