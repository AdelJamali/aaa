#!/usr/bin/env python3
"""10.12.15 — read-only ENOMEM diagnostic (OOM_DIAG). Static checks.
Scope rules enforced:
  - read-only (no process creation / unlink / kill / state mutation)
  - captures /proc/meminfo, /proc/self/status(+VmHWM), /proc/self/limits, cgroup v2+v1
  - logged in the SAME process as the failure (client()), once per request
  - L78 nested-ternary regression (fixed in 10.12.13) stays fixed
  - no behavior change elsewhere (heal/recycle/preflight untouched)
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
diag   = (ROOT / 'includes' / 'golden-scan' / 'class-gs-env-diag.php').read_text(encoding='utf-8')
diagc  = strip_code(diag)
tw     = (ROOT / 'includes' / 'golden-scan' / 'class-gs-test-wizard.php').read_text(encoding='utf-8')

check('V1 version 10.12.20', "define( 'STI_VERSION', '10.12.20' )" in main and 'Version:           10.12.20' in main)

# OOM context content
check('O1 meminfo keys', all(k in diag for k in
      ('MemTotal', 'MemFree', 'MemAvailable', 'SwapTotal', 'SwapFree', 'CommitLimit', 'Committed_AS')))
check('O2 status keys incl VmHWM', all(k in diag for k in ('VmSize', 'VmPeak', 'VmRSS', 'VmHWM', 'Threads')))
check('O3 RLIMIT read (/proc/self/limits)', '/proc/self/limits' in diag and 'Max address space' in diag and 'Max processes' in diag)
check('O4 cgroup raw', "/proc/self/cgroup" in diag)
check('O5 cgroup v2 files', all(f in diag for f in ('/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory.current', '/sys/fs/cgroup/memory.events')))
check('O6 cgroup v1 fallback', 'memory.limit_in_bytes' in diag and 'memory.usage_in_bytes' in diag and 'memory.failcnt' in diag)
check('O7 unreadable marked, not guessed', "'unreadable'" in diag)

# read-only guarantees
oom_start = diagc.find('public static function oom_context')
proc_kv_start = diagc.find('private static function proc_kv')
forbidden = ['proc_open(', 'exec(', 'shell_exec(', 'popen(', 'system(', 'passthru(',
             'unlink(', 'rmdir(', 'file_put_contents(', 'fwrite(', 'fputs(', 'kill']
region = diagc[oom_start:proc_kv_start + 800] if oom_start > -1 else ''
hits = [f for f in forbidden if f in region]
check('O8 no process/mutation calls in oom region', len(hits) == 0, str(hits))
check('O9 only rb reads (2 fopen total, both rb)', diagc.count('fopen') == 2 and diagc.count(", 'rb' )") == 2)

# OOM_DIAG log wiring in client()
check('O10 OOM_DIAG logged at client() failure (10.12.20: safe wrapper + gate probe)',
      "STI_Logger::error( 'OOM_DIAG ' . wp_json_encode( $ctx ) );" in mtcode
      and 'STI_GS_Env_Diag::oom_context_safe()' in mtcode
      and 'STI_GS_Env_Diag::oom_gate_probe(' in mtcode)
check('O11 once-per-request guard', 'self::$oom_diag_logged = true;' in mtcode and 'protected static $oom_diag_logged = false;' in mt)
check('O12 gated on memory pattern (not on every error)',
      'cannot allocate memory' in mtcode and 'fiber stack allocate failed' in mtcode)
# OOM_DIAG must be positioned in the final-failure path (after the failure log, before WP_Error return)
fi = mtcode.find("'MTProto: ساخت client ناموفق — '")
oi = mtcode.find("'OOM_DIAG '")
ri = mtcode.find("return new WP_Error( 'sti_mt_client'")
check('O13 placement: failure-log < OOM_DIAG < return', fi > -1 and fi < oi < ri, f'{fi} < {oi} < {ri}')

# AJAX surface (additive)
check('O14 ajax returns oom_context (safe variant)',
      "$snap['oom_context'] = STI_GS_Env_Diag::oom_context_safe();" in tw)

# L78 regression (10.12.12 fatal) — parenthesized form must remain
check('O15 L78 still parenthesized', ": ( ( defined( 'INI_USER' )" in diag)

# no behavior change elsewhere
check('B1 heal guard (10.12.14) intact', 'worker_state_unknown — exec unavailable: no unlink, no cleanup' in mtcode)
check('B2 kill path intact', "kill -9 ' . (int) $pid" in mtcode)
check('B3 MAX_IPC_RECYCLES unchanged', 'const MAX_IPC_RECYCLES = 2;' in mtcode)
check('B4 attempts unchanged', '$attempts = 2;' in mtcode)
check('B5 polyfill (10.12.14) intact', "if ( ! function_exists( 'escapeshellarg' ) )" in mtcode)

# PHP8 nested-ternary detector over both changed files
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
bad = {f: [ln for ln, l in enumerate(src.splitlines(), 1) if nested_ternary(l)]
       for f, src in (('diag', diag), ('mtproto', mt), ('test-wizard', tw))}
bad = {k: v for k, v in bad.items() if v}
check('S1 no unparenthesized nested ternary', len(bad) == 0, str(bad))

print()
if FAILS:
    print(f'{len(FAILS)} FAILED'); sys.exit(1)
print('10.12.15 OOM-DIAG SUITE: ALL PASS')
