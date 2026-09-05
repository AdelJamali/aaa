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
check('D3a header version', 'Version:           10.12.12' in main)
check('D3b STI_VERSION', "define( 'STI_VERSION', '10.12.12' )" in main)

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

print()
if FAILS:
    print(f'{len(FAILS)} FAILED'); sys.exit(1)
print('ALL PASS')
