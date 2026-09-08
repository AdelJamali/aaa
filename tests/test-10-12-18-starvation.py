#!/usr/bin/env python3
"""10.12.18 — WORKER STARVATION fix. Static verification.

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

FIX: on outcome waiting|skipped, write a short next_retry_at.
     attempts untouched, state untouched, FLOOD_WAIT never overwritten.
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
check('V1 header 10.12.18', 'Version:           10.12.18' in main)
check('V2 STI_VERSION 10.12.18', "define( 'STI_VERSION', '10.12.18' )" in main)

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

# ---------- B: backoff table ----------
check('B1 WAITING_BACKOFF const exists', 'const WAITING_BACKOFF = array(' in worker)
for state, secs in (('CHAIN_WAITING', 60), ('WAITING_BOT', 120), ('ERROR_BOT_TIMEOUT', 300)):
    check('B2 %s => %d' % (state, secs),
          re.search(r"'%s'\s*=>\s*%d," % (state, secs), worker) is not None)
check('B3 default backoff const', 'const WAITING_BACKOFF_DEFAULT = 90;' in worker)

# every WAITING state must have a backoff entry
m = re.search(r"const WAITING = array\(([^)]*)\)", worker)
waiting_states = re.findall(r"'([A-Z_]+)'", m.group(1)) if m else []
check('B4 all three WAITING states covered',
      len(waiting_states) == 3 and all(
          re.search(r"'%s'\s*=>\s*\d+," % s, worker) for s in waiting_states))

# ---------- D: defer_waiting implementation ----------
check('D1 defer_waiting() defined',
      'protected static function defer_waiting( $session_id, $state )' in worker)

start = worker.find('protected static function defer_waiting(')
end = worker.find('protected static function pick(', start)
body = worker[start:end]
check('D1a defer_waiting body isolated', start > 0 and end > start)

check('D2 writes next_retry_at', "'next_retry_at' => self::mysql_time( time() + $delay )" in body)
check('D3 does NOT touch attempts', 'attempts' not in body)
check('D4 does NOT touch state column', "'state' =>" not in body)
check('D5 respects existing future next_retry_at',
      'SELECT next_retry_at FROM' in body and 'strtotime( (string) $existing ) > time()' in body)
check('D6 filter hook present', "apply_filters( 'sti_gs_waiting_backoff'" in body)
check('D7 delay clamped', 'max( 10, min( HOUR_IN_SECONDS, $delay ) )' in body)
check('D8 guards bad id', '$session_id <= 0' in body)
check('D9 logs the deferral', 'AUTO_WORKER_DEFER' in body)

# ---------- C: call site ----------
check('C1 called on waiting OR skipped',
      "if ( 'waiting' === $outcome || 'skipped' === $outcome ) {" in worker)
check('C2 call site invokes defer_waiting', 'self::defer_waiting( (int) $session[\'id\']' in worker)

# call site must sit inside tick_inner's foreach, before the report increment
ti = worker.find('protected static function tick_inner()')
call = worker.find("if ( 'waiting' === $outcome || 'skipped' === $outcome ) {", ti)
rep = worker.find("if ( isset( $report[ $outcome ] ) ) {", ti)
check('C3 call site is inside tick_inner before report increment',
      ti > 0 and ti < call < rep)

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

print()
if fails:
    print('10.12.18 STARVATION SUITE: %d FAILED' % len(fails))
    for f in fails:
        print('  -', f)
    sys.exit(1)
print('10.12.18 STARVATION SUITE: ALL PASS')
