#!/usr/bin/env python3
"""
10.12 Workflow — static checks for the backend wiring (B1..B3).

No PHP runtime in this environment, so like the 10.11 suite these are
contract-level static checks against the source.
"""
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(__file__), '..', 'plugin', 'sanil-telegram-importer')
WATCHER = os.path.join(ROOT, 'includes', 'golden-scan', 'class-gs-channel-watcher.php')
PQUEUE  = os.path.join(ROOT, 'includes', 'golden-scan', 'class-gs-publish-queue.php')
WIZARD  = os.path.join(ROOT, 'includes', 'golden-scan', 'class-gs-test-wizard.php')

PASS = 0
FAIL = 0


def check(name, cond, detail=''):
    global PASS, FAIL
    if cond:
        PASS += 1
        print('PASS ' + name)
    else:
        FAIL += 1
        print('FAIL ' + name + (' — ' + detail if detail else ''))


def read(p):
    with open(p, encoding='utf-8') as f:
        return f.read()


watcher = read(WATCHER)
pqueue  = read(PQUEUE)
wizard  = read(WIZARD)

# ── B1: category filter + priority order in create_sessions ──────────────
check('B1a create_sessions signature (3 optional params)',
      re.search(r'function create_sessions\(\s*\$room,\s*\$wc_term_id\s*=\s*null,\s*\$priority_order\s*=\s*false,\s*&\$created_ids\s*=\s*null\s*\)', watcher) is not None)

check('B1b optional WC-term filter in SQL',
      'AND p.default_category_id = %d' in watcher
      and re.search(r"\$cat_filter\s*=\s*'';\s*\n\s*\$params\s*=\s*array\(\s*'available'\s*\);", watcher) is not None)

check('B1c priority order (score DESC, id ASC) + legacy default intact',
      'ORDER BY pi.score DESC, pi.id ASC' in watcher
      and "ORDER BY pi.id ASC" in watcher
      and re.search(r"\$order\s*=\s*\$priority_order\s*\?", watcher) is not None)

check('B1d start_pipeline passes params through and returns ids',
      re.search(r'function start_pipeline\(\s*\$count,\s*\$wc_term_id\s*=\s*null,\s*\$priority_order\s*=\s*false\s*\)', watcher) is not None
      and re.search(r'self::create_sessions\(\s*\$count,\s*\$wc_term_id,\s*\$priority_order,\s*\$ids\s*\)', watcher) is not None
      and "'ids'       => array_values( $ids )," in watcher)

check('B1e legacy call site (run()) untouched — 1-arg create_sessions',
      re.search(r'self::create_sessions\(\s*\$room\s*\)', watcher) is not None)

# ── B2: enqueue respects pre-set future scheduled_at ─────────────────────
check('B2a enqueue reads existing scheduled_at',
      re.search(r"\$existing\s*=\s*!\s*empty\(\s*\$session\['scheduled_at'\]\s*\)\s*\?\s*\(int\)\s*strtotime\(\s*\$session\['scheduled_at'\]\s*\)\s*:\s*0;", pqueue) is not None)

check('B2b max triple in enqueue',
      re.search(r"\$next\s*=\s*max\(\s*time\(\)\s*\+\s*60,\s*\$last\s*\+\s*\$interval,\s*\$existing\s*\);", pqueue) is not None)

check('B2c repair_missing_schedule unchanged (2-arg max still there, no $existing)',
      pqueue.count('max( time() + 60, $last + $interval, $existing )') == 1
      and pqueue.count('max( time() + 60, $last + $interval )') == 1)

# ── B3: sti_gs_publish_queue_create action ───────────────────────────────
check('B3a action registered',
      "add_action( 'wp_ajax_sti_gs_publish_queue_create', array( $this, 'ajax_publish_queue_create' ) );" in wizard)

check('B3b handler validates items (1..20 categories, 1..1000 each, total<=1000)',
      re.search(r'function ajax_publish_queue_create\(\)\s*\{\s*\n\s*\$this->check_ajax\(\);', wizard) is not None
      and "count( $raw_items ) < 1 || count( $raw_items ) > 20" in wizard
      and '$cat < 1 || $cnt < 1 || $cnt > 1000' in wizard
      and '$total > 1000' in wizard)

check('B3c creation uses priority order (start_pipeline with true)',
      re.search(r"STI_GS_Channel_Watcher::start_pipeline\(\s*\$it\['count'\],\s*\$it\['wc_term_id'\],\s*true\s*\)", wizard) is not None)

check('B3d interval mode: sets interval + writes scheduled_at sequence, skips finals',
      "STI_GS_Publish_Queue::set_interval_minutes( $interval_minutes )" in wizard
      and re.search(r"STI_GS_Session::update\(\s*\$sid,\s*array\(\s*'scheduled_at'\s*=>\s*\$dt\s*\)\s*\);", wizard) is not None
      and "in_array( $st, array( 'PUBLISHED', 'REVIEW', 'CANCELLED' ), true )" in wizard
      and "'published' === $qs" in wizard)

check('B3e immediate mode never writes scheduled_at (guarded by mode check)',
      re.search(r"if \(\s*'interval'\s*===\s*\$mode\s*&&\s*\$all_ids\s*&&\s*\$int_sec\s*>\s*0\s*\)", wizard) is not None)

# ── Data safety / contract integrity ─────────────────────────────────────
check('D1 no DELETE FROM introduced in the three changed classes',
      'DELETE FROM' not in watcher and 'DELETE FROM' not in pqueue and 'DELETE FROM' not in wizard)

check('D2 legacy actions still registered (no contract removal)',
      all(s in wizard for s in (
          "wp_ajax_sti_gs_pipeline_start",
          "wp_ajax_sti_gs_start_diagnostic",
          "wp_ajax_sti_gs_line_start",
          "wp_ajax_sti_gs_line_stop",
          "wp_ajax_sti_gs_review_fix",
          "wp_ajax_sti_gs_insight_batch",
      )))

check('D3 nonce/capability unchanged (check_ajax same as before)',
      wizard.count("check_ajax_referer( 'sti_admin_nonce', 'nonce' )") == 1
      and wizard.count("current_user_can( 'manage_woocommerce' )") >= 1)

check('D4 start_pipeline return keeps legacy keys (created/ready/worker_on/line)',
      all(k in watcher for k in ("'created'   => $created", "'ready'     => $ready", "'worker_on' => (bool) $worker_on", "'line'      =>")))

print()
total = PASS + FAIL
print('10.12 WORKFLOW STATIC SUITE: %d/%d PASS' % (PASS, total))
sys.exit(1 if FAIL else 0)
