#!/usr/bin/env python3
"""
P0 regression test — sys_getloadavg() fatal (ArgumentCountError).

Background
----------
`sys_getloadavg( array &$loads ): bool` takes EXACTLY ONE argument.
The 10.10 build called it as `sys_getloadavg( $loads, 1 )` (two args,
a leftover from the C signature `getloadavg(loads, nelems)`). On PHP 8
that is an ArgumentCountError — an Error/Exception that the `@`
operator does NOT silence — so the governor fatal'd the worker tick
and the dashboard.

This test (runnable without a PHP binary) asserts the fixed contract:
  T1. No call of sys_getloadavg with 2 arguments remains anywhere.
  T2. The governor calls sys_getloadavg with exactly 1 argument.
  T3. That call is inside a try/catch(\Throwable) — a non-standard
      host signature can never fatal again.
  T4. The /proc/loadavg fallback path still exists.
  T5. No `@sys_getloadavg` (misleading suppression) remains.
  T6. Governor::evaluate() is exception-safe (wraps inner logic in
      try/catch) so it can never stop the pipeline.
"""
import re
import sys

GOV = 'plugin/sanil-telegram-importer/includes/golden-scan/class-gs-governor.php'
WORKER = 'plugin/sanil-telegram-importer/includes/golden-scan/class-gs-auto-worker.php'

def read(p):
    with open(p, encoding='utf-8') as f:
        return f.read()

def strip_comments_strings(src):
    """Remove PHP comments and string literals so we only see real code."""
    out = []
    i, n = 0, len(src)
    state = 'code'
    while i < n:
        c = src[i]
        if state == 'code':
            if c == '/' and i + 1 < n and src[i+1] == '/':
                state = 'lc'; i += 2; continue
            if c == '/' and i + 1 < n and src[i+1] == '*':
                state = 'bc'; i += 2; continue
            if c == '#':
                state = 'lc'; i += 1; continue
            if c == "'":
                state = 'sq'; i += 1; continue
            if c == '"':
                state = 'dq'; i += 1; continue
            out.append(c); i += 1
        elif state == 'lc':
            if c == '\n':
                state = 'code'; out.append(c)
            i += 1
        elif state == 'bc':
            if c == '*' and i + 1 < n and src[i+1] == '/':
                state = 'code'; i += 2; continue
            i += 1
        elif state == 'sq':
            if c == '\\' and i + 1 < n:
                i += 2; continue
            if c == "'":
                state = 'code'
            i += 1
        elif state == 'dq':
            if c == '\\' and i + 1 < n:
                i += 2; continue
            if c == '"':
                state = 'code'
            i += 1
    return ''.join(out)

failures = []

def check(name, cond, detail=''):
    print(('PASS' if cond else 'FAIL'), name, ('— ' + detail if detail and not cond else ''))
    if not cond:
        failures.append(name)

gov_raw = read(GOV)
gov = strip_comments_strings(gov_raw)

# T1 — nowhere in the whole plugin is sys_getloadavg called with 2 args
all_php = []
import os
for root, _dirs, files in os.walk('plugin/sanil-telegram-importer'):
    for f in files:
        if f.endswith('.php'):
            all_php.append(os.path.join(root, f))

two_arg = []
for p in all_php:
    code = strip_comments_strings(read(p))
    for m in re.finditer(r'sys_getloadavg\s*\(', code):
        j = m.end()
        depth = 1
        args = 0
        started = False
        while j < len(code) and depth > 0:
            ch = code[j]
            if ch == '(':
                depth += 1
            elif ch == ')':
                depth -= 1
            elif ch == ',' and depth == 1:
                args += 1
            elif ch not in ' \t\n\r':
                started = True
            j += 1
        if started and args >= 1:
            two_arg.append(p)
check('T1 no sys_getloadavg call with 2 arguments in the whole plugin',
      not two_arg, str(two_arg))

# T2 — governor calls it with exactly one argument
check('T2 governor calls sys_getloadavg($loads) with 1 argument',
      re.search(r'sys_getloadavg\s*\(\s*\$loads\s*\)', gov) is not None)

# T3 — the call is inside a try { ... } catch ( \Throwable
m = re.search(r'sys_getloadavg\s*\(\s*\$loads\s*\)', gov)
inside_try = False
if m:
    head = gov[:m.start()]
    try_pos = head.rfind('try')
    catch_pos = head.rfind('catch')
    # the nearest control keyword before the call must be try (not catch)
    inside_try = (try_pos > catch_pos) and (try_pos != -1)
    catch_ok = re.search(r'catch\s*\(\s*\\?Throwable\s+\$\w+\s*\)', gov) is not None
    inside_try = inside_try and catch_ok
check('T3 sys_getloadavg call is inside try/catch(\\Throwable)', inside_try)

# T4 — /proc/loadavg fallback exists (check raw source: the path is a string literal)
check('T4 /proc/loadavg fallback path exists',
      "file_get_contents( '/proc/loadavg' )" in gov_raw)

# T5 — no @sys_getloadavg anywhere
check('T5 no misleading @sys_getloadavg remains', '@sys_getloadavg' not in gov_raw)

# T6 — evaluate() delegates to evaluate_inner() inside try/catch
eval_ok = re.search(
    r'public static function evaluate\s*\(\s*\)\s*{\s*try\s*{\s*return self::evaluate_inner\(\);',
    gov) is not None
check('T6 Governor::evaluate() is exception-safe (try/catch wrapper)', eval_ok)

print()
if failures:
    print('P0 REGRESSION TEST: FAILED ->', failures)
    sys.exit(1)
print('P0 REGRESSION TEST: ALL PASS')
