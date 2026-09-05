#!/usr/bin/env python3
"""
10.12.11 — MTProto IPC worker lifecycle (Fiber mmap / ERROR_CLICK root-cause fix).

Static verification (sandbox has no PHP; host runtime proof via runbook):
  M1  ipc_worker_pids() matches BOTH cmdline patterns (pre/post rename)
  M2  ipc_worker_count() derived from the shared pid helper (no old single pattern)
  M3  ipc_heal() kills BEFORE deleting files (kill-then-verify-then-clean)
  M4  ipc_preflight() heals when >1 live worker for one session (orphan cleanup)
  M5  client() memory-failure fuse: detects mmap/ENOMEM, heals once, retries once
  M6  ipc_diagnostic() exposes worker_pids + orphan flags (read-only)
  M7  no global pkill anywhere; kills are PID-scoped to the session dir
  M8  PHP/JS balance of all touched files
"""
import re
import sys

ROOT = 'plugin/sanil-telegram-importer'
MT = f'{ROOT}/includes/class-sti-mtproto.php'

src = open(MT, encoding='utf-8').read()
fails = 0


def check(name, cond):
    global fails
    print(('PASS ' if cond else 'FAIL ') + name)
    if not cond:
        fails += 1


def strip_code(text):
    out = []
    i, n = 0, len(text)
    while i < n:
        c = text[i]
        if c == '/' and i + 1 < n and text[i + 1] == '/':
            j = text.find('\n', i)
            i = n if j < 0 else j
        elif c == '/' and i + 1 < n and text[i + 1] == '*':
            j = text.find('*/', i + 2)
            i = n if j < 0 else j + 2
        elif c in ('"', "'", '`'):
            q = c
            i += 1
            while i < n:
                if text[i] == '\\':
                    i += 2
                    continue
                if text[i] == q:
                    break
                i += 1
            i += 1
        else:
            out.append(c)
            i += 1
    return ''.join(out)


# M1 — both cmdline patterns (pre/post cli_set_process_title rename)
check('M1a ipc_worker_pids() exists', 'function ipc_worker_pids()' in src)
check('M1b matches original cmdline (madeline-ipc <dir>)', "'madeline-ipc ' . $escaped" in src)
check('M1c matches renamed title (MadelineProto worker <dir>)', "'MadelineProto worker ' . $escaped" in src)
check('M1d regex-escaped session path (preg_quote)', 'preg_quote( self::session_path(), \'/\' )' in src)

# M2 — count derived from helper; old single-pattern count gone
check('M2a ipc_worker_count() uses ipc_worker_pids()',
      "return count( self::ipc_worker_pids() );" in src)
old_count = "@exec( 'pgrep -f ' . escapeshellarg( $pattern ) . ' 2>/dev/null | wc -l', $out );"
check('M2b old single-pattern wc -l count removed', old_count not in src)

# M3 — ipc_heal: kill before file deletion (region = heal → next function)
heal = src[src.find('public static function ipc_heal('):]
heal = heal[:heal.find('public static function ipc_diagnostic(')]
kill_pos = heal.find("kill ' . (int) $pid")
unlink_pos = heal.find("foreach ( array( 'ipc', 'callback.ipc', 'ipcState.php', 'lock' )")
check('M3a heal kills workers first', kill_pos > -1)
check('M3b heal deletes files second (kill < unlink)', -1 < kill_pos < unlink_pos)
check('M3c heal verifies death before SIGKILL (second pid sweep)', heal.count('ipc_worker_pids()') >= 2)
check('M3d heal reports pids in log', 'pids=%s' in src)

# M4 — preflight multi-worker branch
pre = src[src.find('protected static function ipc_preflight('):]
pre = pre[:pre.find('public static function ipc_heal(')]
check('M4a preflight heals when count > 1',
      '$count > 1' in pre and 'orphan cleanup' in pre)
check('M4b preflight still keeps stale-state rule (>30min + no worker)',
      '30 * MINUTE_IN_SECONDS' in pre and 'بدون worker' in pre)

# M5 — client() memory fuse
cli = src[src.find('public function client() {'):]
cli = cli[:cli.find('protected function build_settings_candidates(')]
check('M5a detects "cannot allocate memory"', "'cannot allocate memory'" in cli)
check('M5b detects "fiber stack allocate failed"', "'fiber stack allocate failed'" in cli)
check('M5c once-per-request fuse (static)', 'static $mem_healed = false;' in cli)
check('M5d heals via ipc_heal on memory failure', 'خطای تخصیص حافظه' in cli and 'ipc_heal' in cli)
check('M5e bounded retry (2 attempts per candidate)', '$attempts = 2' in cli)
check('M5f P4 memory instrumentation around client creation',
      '$mem_before = function_exists( \'memory_get_usage\' ) ? memory_get_usage( true ) : 0;' in cli
      and 'mem_before=%d mem_after=%d mem_peak=%d' in cli
      and 'mem_before=%d mem_now=%d mem_peak=%d' in cli)

# M6 — diagnostic orphan flags (read-only)
check('M6a diagnostic exposes worker_pids', "'worker_pids'             => array_map( 'intval', $pids )" in src)
check('M6b multi_worker flag', "'multi_worker'            => count( $pids ) > 1" in src)
check('M6c stale_state_live_worker flag', "'stale_state_live_worker' => ( null !== $state_s" in src)

# M7 — no global pkill; kills are pid-scoped (comments stripped — pkill is mentioned in history docblock)
_code_only = strip_code(src)
check('M7a no pkill in plugin code', 'pkill' not in _code_only)
check('M7b kill uses scoped PIDs from ipc_worker_pids', "kill -9 ' . (int) $pid" in src)

# M8 — balance of all touched PHP files
for f in [
    f'{ROOT}/includes/class-sti-mtproto.php',
    f'{ROOT}/sanil-telegram-importer.php',
    f'{ROOT}/includes/golden-scan/class-gs-auto-worker.php',
]:
    code = strip_code(open(f, encoding='utf-8').read())
    ok = all(code.count(a) == code.count(b) for a, b in [('{', '}'), ('(', ')'), ('[', ']')])
    check(f'M8 balance {f.split("/")[-1]}', ok)

print()
if fails:
    print(f'10.12.11 MTProto IPC SUITE: {fails} FAILED')
    sys.exit(1)
print('10.12.11 MTProto IPC SUITE: ALL PASS')
