#!/usr/bin/env python3
"""10.12.14 — root-cause fixes, static checks.
Each check references the exact proven failure it guards:
  FIX #1: «Call to undefined function escapeshellarg()» (madeline.log, ProcessRunner, pre-worker)
  FIX #2: «ترمیم IPC ... (pids=-)، N فایل فرسوده پاک شد» (blind unlink with unknown worker state)
  TASK D: lifecycle logging (pid, ipc_path, runner fingerprint leftovers)
  RULES : no retry/recycle/state-machine change
"""
import re, sys, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent / 'plugin' / 'sanil-telegram-importer'
FAILS = []

def check(name, cond, detail=''):
    print(('PASS' if cond else 'FAIL'), '-', name, ('| ' + detail if detail and not cond else ''))
    if not cond:
        FAILS.append(name)

def strip_code(src):
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    src = re.sub(r'//.*', '', src)
    return src

main   = (ROOT / 'sanil-telegram-importer.php').read_text(encoding='utf-8')
mt     = (ROOT / 'includes' / 'class-sti-mtproto.php').read_text(encoding='utf-8')
mtcode = strip_code(mt)

# version
check('V1 header 10.12.14', 'Version:           10.12.20' in main)
check('V2 STI_VERSION 10.12.14', "define( 'STI_VERSION', '10.12.20' )" in main)

# FIX #1 — escapeshellarg polyfill (failure: Call to undefined function escapeshellarg)
check('F1a polyfill present', "if ( ! function_exists( 'escapeshellarg' ) )" in mtcode)
check('F1b POSIX implementation', "str_replace( \"'\", \"'\\\\''\", (string) $string )" in mt)
check('F1c polyfill at file scope (before class)', mt.index("function escapeshellarg") < mt.index('class STI_MTProto'))
check('F1d polyfill guarded (no override on normal hosts)', mtcode.count("function escapeshellarg") == 1)

# FIX #2 — ipc_heal unknown-state guard (failure: blind unlink with pids=-)
heal_start = mtcode.index('public static function ipc_heal')
heal_end   = mtcode.index('public static function ipc_diagnostic')
heal = mtcode[heal_start:heal_end]
guard = "worker_state_unknown" in heal
check('F2a guard present in ipc_heal', guard)
check('F2b guard skips unlink (no file touched when exec unavailable)',
      "no unlink, no cleanup" in heal)
# guard must come BEFORE the kill block and the unlink loop
gi  = heal.find('worker_state_unknown')
ki  = heal.find("kill ' . (int) $pid")
ui  = heal.find("@unlink( $p )")  # the real unlink loop (guard loop above does no unlink)
check('F2c guard positioned before kill+unlink', gi > -1 and gi < ki and gi < ui, f'guard@{gi} kill@{ki} unlink@{ui}')
check('F2d diagnostic emitted (log with files present + pid)', 'هیچ فایلی حذف نشد' in heal and 'pid=%d' in heal)
# exec-available path unchanged: kill-verify-kill9-then-unlink sequence intact
check('F2e kill path intact', "kill -9 ' . (int) $pid" in heal)
check('F2f unlink list intact (exec-available behaviour unchanged)',
      "array( 'ipc', 'callback.ipc', 'ipcState.php', 'lock' )" in heal)
check('F2g heal still returns report fields', "$report['stale_files'] = $gone;" in heal)

# TASK D — lifecycle logging
check('D1 ipc_diagnostic: pid', "'pid'               => function_exists( 'getmypid' )" in mtcode)
check('D2 ipc_diagnostic: ipc_path', "'ipc_path'          => $dir," in mtcode)
check('D3 ipc_diagnostic: proc_open visibility', "'proc_open_available'" in mtcode)
check('D4 runner fingerprint leftovers (webroot+tmpdir)',
      "'webroot' => 0, 'tmpdir' => 0" in mtcode and "glob( $ldir . '/madeline-ipc-*.php' )" in mtcode)
check('D5 client() success log has pid', 'MTProto client: ساخته شد — pid=%d' in mtcode)
check('D6 client() failure log has pid', "' | pid=%d mem_before=%d mem_now=%d mem_peak=%d limit=%s'" in mtcode)
check('D7 ENV_DIAG still present (context logging)', "STI_Logger::info( 'ENV_DIAG '" in mtcode)

# RULES — no retry/recycle/state-machine change
check('R1 MAX_IPC_RECYCLES unchanged (=2)', 'const MAX_IPC_RECYCLES = 2;' in mtcode)
check('R2 client() attempts unchanged (=2)', '$attempts = 2;' in mtcode)
check('R3 no new while-loop in ipc_heal', len(re.findall(r'\bwhile\s*\(', heal)) == 0)
check('R4 preflight logic untouched (count>1 / count>0 / -1 branches)',
      'self::ipc_heal( \'preflight: \' . $count . \' worker' in mtcode
      and 'if ( -1 === $count )' in mtcode)
check('R5 no state-machine writes added to heal', heal.count("STI_GS_Session::update") == 0)

# PHP8 nested-ternary guard (re-run detector over changed file)
def nested_ternary(line):
    code = re.sub(r'//.*', '', line)
    code = code.replace('<?php', ' ').replace('?>', ' ').replace('::', ' ')
    depth = 0; i = 0; n = len(code); in_str = None
    events = []
    while i < n:
        c = code[i]
        if in_str:
            if c == in_str and code[i-1] != '\\': in_str = None
        else:
            if c in ('"', "'"): in_str = c
            elif c in '([{': depth += 1
            elif c in ')]}': depth -= 1
            elif depth == 0:
                if c == '?': events.append('?')
                elif c == ':' and (i == 0 or code[i-1] != '='): events.append(':')
                elif c == ',': events.append(',')
        i += 1
    for k, ch in enumerate(events):
        if ch != ':': continue
        for ch2 in events[k+1:]:
            if ch2 == '?': return True
            if ch2 in (':', ','): break
    return False
bad = [(ln, l.strip()[:80]) for ln, l in enumerate(mt.splitlines(), 1) if nested_ternary(l)]
check('S1 no unparenthesized nested ternary in mtproto file', len(bad) == 0, str(bad))

print()
if FAILS:
    print(f'{len(FAILS)} FAILED'); sys.exit(1)
print('10.12.14 FIX SUITE: ALL PASS')
