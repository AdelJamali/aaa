#!/usr/bin/env python3
"""10.12.12-diag — static checks: read-only env diagnostic (no behavior change)."""
import re, sys, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent / 'plugin' / 'sanil-telegram-importer'
FAILS = []

def check(name, cond, detail=''):
    print(('PASS' if cond else 'FAIL'), '-', name, ('| ' + detail if detail and not cond else ''))
    if not cond:
        FAILS.append(name)

def strip_code(src: str) -> str:
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    src = re.sub(r'//.*', '', src)
    src = re.sub(r'(?m)^[ \t]*\*.*$', '', src)
    return src

main = (ROOT / 'sanil-telegram-importer.php').read_text(encoding='utf-8')
mt = (ROOT / 'includes' / 'class-sti-mtproto.php').read_text(encoding='utf-8')
tw = (ROOT / 'includes' / 'golden-scan' / 'class-gs-test-wizard.php').read_text(encoding='utf-8')
envp = (ROOT / 'admin' / 'views' / 'golden-scan' / 'environment.php').read_text(encoding='utf-8')
diag_path = ROOT / 'includes' / 'golden-scan' / 'class-gs-env-diag.php'
diag = diag_path.read_text(encoding='utf-8') if diag_path.exists() else ''

# D1: new file + class
check('D1 file exists + class', 'class STI_GS_Env_Diag' in diag)

# D2: never CALLS shell functions (only function_exists/is_callable); FUNCS is data
code = strip_code(diag)
calls = re.findall(r'\b(exec|proc_open|shell_exec|system|passthru|popen|escapeshellarg)\s*\(', code)
check('D2 no shell function CALLS in diag code', len(calls) == 0, str(calls))

# D3: version consistency 10.12.12
check('D3a header version', 'Version:           10.12.17' in main)
check('D3b STI_VERSION', "define( 'STI_VERSION', '10.12.17' )" in main)

# D4: loader + ajax registration + handler
check('D4a require_once env-diag', "require_once STI_PATH . 'includes/golden-scan/class-gs-env-diag.php';" in main)
check('D4b ajax registration', "wp_ajax_sti_gs_env_diag" in tw)
check('D4c handler exists', 'function ajax_env_diag' in tw)

# D5: ENV_DIAG log in client() (MTProto execution context) + property
check('D5a client() logs ENV_DIAG', "STI_Logger::info( 'ENV_DIAG ' . wp_json_encode( STI_GS_Env_Diag::snapshot() ) );" in mt)
check('D5b property declared', '$env_diag_logged = false;' in mt)
check('D5c once-per-request guard', 'self::$env_diag_logged' in mt)

# D6: environment page renders snapshot (FPM context), read-only
check('D6a env page snapshot', 'STI_GS_Env_Diag::snapshot()' in envp)
check('D6b env page points to ENV_DIAG log', 'ENV_DIAG' in envp)

# D7: strictly read-only — no file mutation / no process control anywhere in diag
forbidden = ['unlink(', 'file_put_contents(', 'fwrite(', 'fputs(', 'rmdir(', 'mkdir(', 'exec(', 'proc_open(', 'shell_exec(', 'system(', 'passthru(', 'popen(']
hits = [f for f in forbidden if f in code]
check('D7 no mutation/process calls', len(hits) == 0, str(hits))

# D8: FUNCS list covers the required probes
for fn in ['escapeshellarg', 'exec', 'proc_open', 'shell_exec', 'popen', 'system', 'passthru']:
    check(f'D8 FUNCS has {fn}', f"'{fn}'" in diag)

# D10: heap usage/peak fields (read-only) in snapshot + env page
check('D10a snapshot mem_usage', "memory_get_usage( true )" in diag)
check('D10b snapshot mem_peak', "memory_get_peak_usage( true )" in diag)
check('D10c env page shows usage', "memory_get_usage(true)" in envp)
check('D10d env page shows peak', "memory_get_peak_usage(true)" in envp)

# D9: proc_status read-only (fopen rb only)
check('D9 /proc read-only', "fopen( '/proc/self/status', 'rb' )" in diag and 'fwrite' not in code)


# D11: PHP8 syntax guard — no unparenthesized nested ternary (a?b:c?d:e)
def _nested_ternary(line):
    import re as _re2
    code = _re2.sub(r'//.*', '', line)
    code = code.replace('<?php', ' ').replace('?>', ' ').replace('::', ' ')
    if ') :' in code or ') :' in code:  # alt-syntax if/foreach — not ternary
        return False
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

_BUGGY_REGRESSION = "'k' => ( A ) ? 'x' : ( B ) ? 'y' : 'z'"
_FIXED_OK = "'k' => ( A ) ? 'x' : ( ( B ) ? 'y' : 'z' )"
check('D11a detector catches the original bug', _nested_ternary(_BUGGY_REGRESSION) is True)
check('D11b detector passes the fixed form', _nested_ternary(_FIXED_OK) is False)
_bad_lines = [(ln, l.strip()[:80]) for ln, l in enumerate(diag.splitlines(), 1) if _nested_ternary(l)]
check('D11c diag file clean', len(_bad_lines) == 0, str(_bad_lines))
_other = {}
for _f, _lbl in ((main, 'main'), (mt, 'mtproto'), (tw, 'test-wizard'), (envp, 'environment')):
    _h = [ln for ln, l in enumerate(_f.splitlines(), 1) if _nested_ternary(l)]
    if _h: _other[_lbl] = _h
check('D11d changed files clean', len(_other) == 0, str(_other))

print()
if FAILS:
    print(f'{len(FAILS)} FAILED'); sys.exit(1)
print('ALL PASS')
