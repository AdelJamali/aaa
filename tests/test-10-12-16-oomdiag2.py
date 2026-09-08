#!/usr/bin/env python3
"""10.12.16 — diagnostic-only patch. Static verification of each RULE.

RULE 0/2  observability only, no new execution path, no behavior change
RULE 1    forbidden subsystems untouched
RULE 3    original error preserved, never overwritten
RULE 4    every diagnostic path wrapped in try/catch(\\Throwable)
RULE 5    memory-pattern gate NOT removed; its result is recorded
RULE 6    maps_count / max_map_count / rlimit_data / diagnostic_read_errors
RULE 7    Vm* + Mem*/Swap*/Commit* fields present
RULE 8    ipc_heal behavior unchanged; possibility recorded
RULE 9    UI can distinguish CLIENT_CONSTRUCTION_FAILED from not_logged
RULE 10   feature flag oom_diag, default true, disables new diagnostics
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

main  = (ROOT / 'sanil-telegram-importer.php').read_text(encoding='utf-8')
mt    = (ROOT / 'includes' / 'class-sti-mtproto.php').read_text(encoding='utf-8')
mtc   = strip_code(mt)
diag  = (ROOT / 'includes' / 'golden-scan' / 'class-gs-env-diag.php').read_text(encoding='utf-8')
diagc = strip_code(diag)
flags = (ROOT / 'includes' / 'golden-scan' / 'class-gs-flags.php').read_text(encoding='utf-8')
tw    = (ROOT / 'includes' / 'golden-scan' / 'class-gs-test-wizard.php').read_text(encoding='utf-8')
sc    = (ROOT / 'includes' / 'golden-scan' / 'class-gs-system-check.php').read_text(encoding='utf-8')
tel   = (ROOT / 'admin' / 'views' / 'telegram.php').read_text(encoding='utf-8')

check('V1 version 10.12.16',
      "define( 'STI_VERSION', '10.12.16' )" in main and 'Version:           10.12.16' in main)

# ---------- RULE 6: the three missing fields + read errors ----------
check('R6a maps_count from /proc/self/maps',
      "'/proc/self/maps'" in diag and "'maps_count'" in diag and 'substr_count(' in diag)
check('R6b max_map_count from /proc/sys/vm/max_map_count',
      "'/proc/sys/vm/max_map_count'" in diag and "'max_map_count'" in diag)
check('R6c rlimit_data from "Max data size"',
      "'Max data size'" in diag and "'rlimit_data'" in diag)
check('R6d diagnostic_read_errors emitted', "'diagnostic_read_errors'" in diag)
check('R6e unreadable marked, never guessed', diag.count("'unreadable'") >= 3)

# ---------- RULE 7: memory fields ----------
for f in ('VmRSS', 'VmHWM', 'VmSize', 'VmPeak', 'MemAvailable', 'SwapFree', 'CommitLimit', 'Committed_AS'):
    check(f'R7 field {f}', f"'{f}'" in diag)

# ---------- RULE 5: gate preserved AND probed ----------
check('R5a gate probe exists', 'function oom_gate_probe' in diag)
check('R5b probe reports matched/skipped',
      "'result' => 'matched'" in diag and "'result' => 'skipped'" in diag)
check('R5c probe reasons recorded',
      'contains_cannot_allocate_memory' in diag and 'no_pattern_match' in diag)
check('R5d gate result logged in client()',
      "$ctx['oom_gate_result']" in mtc and "$ctx['oom_gate_reason']" in mtc)
# the ORIGINAL retry-loop gate must still be intact (not removed)
check('R5e retry-loop memory gate NOT removed',
      "$mem_fail   = ( false !== strpos( $low, 'cannot allocate memory' )" in mtc
      and "strpos( $low, 'fiber stack allocate failed' )" in mtc)
check('R5f raw error text captured for inspection', "$ctx['error_message_raw']" in mtc)

# ---------- RULE 4: try/catch protection ----------
check('R4a oom_context_safe wrapper exists', 'function oom_context_safe' in diag)
check('R4b wrapper catches Throwable', 'catch ( \\Throwable $diag_error )' in diag)
check('R4c client() diagnostic block catches Throwable',
      'catch ( \\Throwable $diag_error )' in mtc)
check('R4d logger failure also guarded', 'catch ( \\Throwable $ignored )' in mtc)

# ---------- RULE 3: original error preserved ----------
# $last_error must be assigned to client_error BEFORE the diagnostic block,
# and the WP_Error return must still carry it unchanged.
i_err   = mtc.find('$this->client_error = $last_error;')
i_diag  = mtc.find("'OOM_DIAG '")
i_ret   = mtc.find("return new WP_Error( 'sti_mt_client', 'ساخت client ناموفق: ' . $last_error )")
check('R3a client_error set before diagnostics', -1 < i_err < i_diag)
check('R3b original error returned unchanged', -1 < i_diag < i_ret)
check('R3c failure log precedes diagnostics',
      -1 < mtc.find("'MTProto: ساخت client ناموفق — '") < i_diag)
check('R3d no reassignment of $last_error in diagnostic block',
      '$last_error =' not in mtc[i_diag:i_ret])

# ---------- RULE 10: feature flag ----------
check('R10a flag defined', "'oom_diag' => array(" in flags)
_od = flags.find("'oom_diag' => array(")
check('R10b default true',
      _od > -1 and re.search(r"'default'\s*=>\s*1", flags[_od:_od + 400]) is not None)
check('R10c flag gates the new diagnostic', "STI_GS_Flags::on( 'oom_diag' )" in mtc)
check('R10d graceful when Flags class absent', "! class_exists( 'STI_GS_Flags' ) || STI_GS_Flags::on( 'oom_diag' )" in mtc)

# ---------- RULE 8: ipc_heal reported, not changed ----------
check('R8a ipc_heal_possible recorded', "'ipc_heal_possible'" in diag)
check('R8b ipc_heal_reason recorded', "'ipc_heal_reason'" in diag and 'exec_disabled' in diag)
check('R8c ipc_heal guard (10.12.14) untouched',
      'worker_state_unknown — exec unavailable: no unlink, no cleanup' in mtc)
check('R8d ipc_heal not called from diagnostic block', 'ipc_heal' not in mtc[i_diag:i_ret])

# ---------- RULE 9: UI distinguishes construction failure ----------
check('R9a report method exists', 'function auth_state_report' in mt)
check('R9b CLIENT_CONSTRUCTION_FAILED surfaced', 'CLIENT_CONSTRUCTION_FAILED' in mt)
check('R9c auth_state() return values unchanged',
      "return 'not_logged';" in mt and "return 'awaiting_code';" in mt and "return 'logged_in';" in mt)
check('R9d ajax exposes display_state additively',
      "'display_state'" in mt and "'construction_failed'" in mt and "'state'          => $state," in mt)
check('R9e telegram UI shows real reason', 'construction_failed' in tel and 'CLIENT_CONSTRUCTION_FAILED' in tel)
check('R9f system-check shows real reason', 'CLIENT_CONSTRUCTION_FAILED' in sc)

# ---------- RULE 1/2: forbidden subsystems untouched ----------
check('R1a memory_limit logic unchanged',
      "$current_limit = (int) ini_get( 'memory_limit' );" in mtc
      and "@ini_set( 'memory_limit', '512M' );" in mtc)
check('R1b retry attempts unchanged', '$attempts = 2;' in mtc)
check('R1c MAX_IPC_RECYCLES unchanged', 'const MAX_IPC_RECYCLES = 2;' in mtc)
check('R1d no fiber.stack_size tampering', 'fiber.stack_size' not in mtc and 'fiber.stack_size' not in diagc)
check('R1e polyfill (10.12.14) intact', "if ( ! function_exists( 'escapeshellarg' ) )" in mtc)
check('R1f kill path intact', "kill -9 ' . (int) $pid" in mtc)

# RULE 2: diagnostic region must remain read-only (no process/mutation calls)
o_start = diagc.find('public static function oom_context')
o_end   = diagc.find('public static function oom_gate_probe')
region  = diagc[o_start:o_end] if o_start > -1 and o_end > o_start else ''
forbidden = ['proc_open(', 'exec(', 'shell_exec(', 'popen(', 'system(', 'passthru(',
             'unlink(', 'rmdir(', 'file_put_contents(', 'fwrite(', 'fputs(',
             'update_option(', 'delete_option(', '$wpdb', 'ini_set(']
hits = [f for f in forbidden if f in region]
check('R2a oom region has no mutation/process calls', not hits, str(hits))
check('R2b reads are read-only modes',
      region.count("@file_get_contents(") >= 5 and "'wb'" not in region and "'w'" not in region)
check('R2c is_callable used for exec probe, never invoked',
      "is_callable( 'exec' )" in region and 'exec(' not in region.replace("is_callable( 'exec' )", ''))

# ---------- PHP 8.4 nested-ternary detector (10.12.12 fatal class) ----------
def nested_ternary(line):
    code = re.sub(r'//.*', '', line)
    code = code.replace('<?php', ' ').replace('?>', ' ').replace('::', ' ')
    depth = 0; i = 0; n = len(code); in_str = None; events = []
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
       for f, src in (('env-diag', diag), ('mtproto', mt), ('flags', flags),
                      ('test-wizard', tw), ('system-check', sc))}
bad = {k: v for k, v in bad.items() if v}
check('S1 no unparenthesized nested ternary', len(bad) == 0, str(bad))

# Brace-neutrality vs the previous released commit.
# NOTE: a naive counter yields a constant non-zero offset on files containing
# regex quantifiers like {1,10} inside single-quoted strings. Absolute balance
# is therefore meaningless; what matters is that THIS patch did not change the
# offset. We compare each touched file against its committed version.
import subprocess

def brace_delta(src):
    s = re.sub(r"'(?:\\.|[^'\\])*'", "''", strip_code(src))
    s = re.sub(r'"(?:\\.|[^"\\])*"', '""', s)
    return s.count('{') - s.count('}')

REL = 'plugin/sanil-telegram-importer/'
for label, src, rel in (
    ('env-diag',     diag,  REL + 'includes/golden-scan/class-gs-env-diag.php'),
    ('mtproto',      mt,    REL + 'includes/class-sti-mtproto.php'),
    ('flags',        flags, REL + 'includes/golden-scan/class-gs-flags.php'),
    ('test-wizard',  tw,    REL + 'includes/golden-scan/class-gs-test-wizard.php'),
    ('system-check', sc,    REL + 'includes/golden-scan/class-gs-system-check.php'),
):
    prev = subprocess.run(['git', 'show', f'HEAD:{rel}'],
                          capture_output=True, text=True,
                          cwd=str(pathlib.Path(__file__).resolve().parent.parent))
    if prev.returncode != 0:
        check(f'S2 brace-neutral ({label})', False, 'git show failed')
        continue
    before, after = brace_delta(prev.stdout), brace_delta(src)
    check(f'S2 brace-neutral ({label})', before == after, f'was {before}, now {after}')

print()
if FAILS:
    print(f'{len(FAILS)} FAILED'); sys.exit(1)
print('10.12.16 DIAGNOSTIC-PATCH SUITE: ALL PASS')
