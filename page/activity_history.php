<?php
/* ═══════════════════════════════════════════════════════════════
   TestiFy — Activity History (Datewise + User Detail)
   
   ★ TWO VIEWS:
   1. Date Summary (default)  — ALL dates with total activity
   2. User Detail (on click)  — per-user breakdown for a specific date
   
   ★ DATA SOURCE:
   - Activity metrics (projects/clients/TC added, TC executed, bugs filed):
     Reconstructed from source tables using timestamp columns for ANY date.
   - Bug status (Open/In Progress/Resolved/Closed/Reopened):
     CURRENT STATE — no date filter (no status_changed_at column exists).
     For past dates: shown from snapshot if available; otherwise current live state.
   
   ★ Bug tracking rules (ALL date-specific now!):
   - Bugs Filed    = bug_raised=1 on that date (by executed_by_id)
   - Bugs Open     = bug_opened_at on that date (by executed_by_id)
   - Bugs In Prog  = bug_in_progress_at on that date (by assigned_to)
   - Bugs Resolved = bug_resolved_at on that date (by assigned_to)
   - Bugs Closed   = bug_closed_at on that date (by executed_by_id)
   - Bugs Reopened = bug_reopened_at on that date (by executed_by_id)
   - Not Tested    = global count, status='Not tested' (summary card only)
   
   ★ Required DB columns (auto-added by ALTER TABLE):
   bug_opened_at, bug_in_progress_at, bug_resolved_at, bug_closed_at, bug_reopened_at
   
   ★ IMPORTANT: Your test_cases.php MUST set these columns when updating bug_status!
   Example: UPDATE test_cases SET bug_status='Resolved', bug_resolved_at=NOW() WHERE id=X
   ═══════════════════════════════════════════════════════════════ */

date_default_timezone_set('Asia/Kolkata');

session_start();
include '../config/db.php';

// Set MySQL session timezone to IST
$conn->query("SET time_zone = '+05:30'");

if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

// ─────────────────────────────────────────────────────────────
// HELPER FUNCTIONS
// ─────────────────────────────────────────────────────────────
function ah_detect_columns($conn, $table) {
    $cols = [];
    try {
        $result = $conn->query("SHOW COLUMNS FROM `$table`");
        if ($result) {
            while ($row = $result->fetch_assoc()) $cols[$row['Field']] = true;
        }
    } catch (Exception $e) {}
    return $cols;
}

function ah_detect_ts_col($cols) {
    if (isset($cols['created_at']))  return 'created_at';
    if (isset($cols['createdAt']))   return 'createdAt';
    return null;
}

function ah_detect_exec_ts_col($cols) {
    if (isset($cols['executed_on']))  return 'executed_on';
    if (isset($cols['executed_at']))  return 'executed_at';
    if (isset($cols['updated_at']))  return 'updated_at';
    return null;
}

function ah_today_filter($ts_col) {
    if ($ts_col === null) return "";
    return "AND DATE(`$ts_col`) = CURDATE()";
}

/** Date-specific filter — replaces CURDATE() with a specific date */
function ah_date_filter($ts_col, $target_date) {
    if ($ts_col === null) return "";
    return "AND DATE(`$ts_col`) = '$target_date'";
}

// ═══════════════════════════════════════════════════════════════
// COLUMN DETECTION (done once, shared across functions)
// ═══════════════════════════════════════════════════════════════
$ah_tc_cols   = ah_detect_columns($conn, 'test_cases');
$ah_bug_cols  = [];
try { $ah_bug_cols = ah_detect_columns($conn, 'bugs'); } catch (Exception $e) {}
$ah_proj_cols = ah_detect_columns($conn, 'projects');
$ah_cli_cols  = ah_detect_columns($conn, 'clients');

$ah_tc_has_bug_status  = isset($ah_tc_cols['bug_status']);
$ah_tc_has_bug_raised  = isset($ah_tc_cols['bug_raised']);
$ah_bugs_in_test_cases = ($ah_tc_has_bug_status && $ah_tc_has_bug_raised);
$ah_has_bugs_table     = !empty($ah_bug_cols);

$ah_proj_by_col = isset($ah_proj_cols['created_by']) ? 'created_by' : (isset($ah_proj_cols['created_by_id']) ? 'created_by_id' : null);
$ah_cli_by_col  = isset($ah_cli_cols['created_by']) ? 'created_by' : (isset($ah_cli_cols['created_by_id']) ? 'created_by_id' : null);

$ah_tc_created  = isset($ah_tc_cols['created_by']) ? 'created_by' : (isset($ah_tc_cols['created_by_id']) ? 'created_by_id' : null);
$ah_tc_executed = null;
if (isset($ah_tc_cols['executed_by_id']))      $ah_tc_executed = 'executed_by_id';
elseif (isset($ah_tc_cols['executed_by']))      $ah_tc_executed = 'executed_by';
elseif (isset($ah_tc_cols['run_by']))           $ah_tc_executed = 'run_by';

$ah_tc_is_exec    = isset($ah_tc_cols['is_executed']);
$ah_tc_has_status = isset($ah_tc_cols['status']);

$ah_proj_ts    = ah_detect_ts_col($ah_proj_cols);
$ah_cli_ts     = ah_detect_ts_col($ah_cli_cols);
$ah_tc_ts      = ah_detect_ts_col($ah_tc_cols);
$ah_tc_exec_ts = ah_detect_exec_ts_col($ah_tc_cols);

$ah_bug_rep_col   = null;
$ah_bug_res_col   = null;
$ah_bug_close_col = null;
if ($ah_has_bugs_table) {
    $ah_bug_rep_col   = isset($ah_bug_cols['reported_by']) ? 'reported_by' : (isset($ah_bug_cols['reported_by_id']) ? 'reported_by_id' : (isset($ah_bug_cols['created_by']) ? 'created_by' : null));
    $ah_bug_res_col   = isset($ah_bug_cols['resolved_by']) ? 'resolved_by' : (isset($ah_bug_cols['resolved_by_id']) ? 'resolved_by_id' : (isset($ah_bug_cols['assigned_to']) ? 'assigned_to' : null));
    $ah_bug_close_col = isset($ah_bug_cols['closed_by']) ? 'closed_by' : (isset($ah_bug_cols['closed_by_id']) ? 'closed_by_id' : $ah_bug_res_col);
}

// ═══════════════════════════════════════════════════════════════
// AUTO-ADD bug status timestamp columns to test_cases (if missing)
// These track WHEN each bug_status change happened so we can
// show In Progress / Resolved on the CORRECT date only!
// ═══════════════════════════════════════════════════════════════
$ah_bug_ts_columns = [
    'bug_opened_at'       => 'TIMESTAMP NULL DEFAULT NULL',
    'bug_in_progress_at'  => 'TIMESTAMP NULL DEFAULT NULL',
    'bug_resolved_at'     => 'TIMESTAMP NULL DEFAULT NULL',
    'bug_closed_at'       => 'TIMESTAMP NULL DEFAULT NULL',
    'bug_reopened_at'     => 'TIMESTAMP NULL DEFAULT NULL',
];
foreach ($ah_bug_ts_columns as $col => $def) {
    if (!isset($ah_tc_cols[$col])) {
        @$conn->query("ALTER TABLE test_cases ADD COLUMN `$col` $def");
        $ah_tc_cols[$col] = true; // update local cache
    }
}
// Re-detect after ALTER
$ah_has_bug_opened_at      = isset($ah_tc_cols['bug_opened_at']);
$ah_has_bug_in_progress_at = isset($ah_tc_cols['bug_in_progress_at']);
$ah_has_bug_resolved_at    = isset($ah_tc_cols['bug_resolved_at']);
$ah_has_bug_closed_at      = isset($ah_tc_cols['bug_closed_at']);
$ah_has_bug_reopened_at    = isset($ah_tc_cols['bug_reopened_at']);

// ═══════════════════════════════════════════════════════════════
// FETCH PER-USER DATA FOR A SPECIFIC DATE (works for ANY date!)
// ═══════════════════════════════════════════════════════════════
function ah_fetch_data_for_date($conn, $target_date, $filter_role = '') {
    global $ah_tc_cols, $ah_bug_cols, $ah_proj_cols, $ah_cli_cols,
           $ah_bugs_in_test_cases, $ah_has_bugs_table,
           $ah_proj_by_col, $ah_cli_by_col, $ah_tc_created, $ah_tc_executed,
           $ah_tc_is_exec, $ah_tc_has_status,
           $ah_proj_ts, $ah_cli_ts, $ah_tc_ts, $ah_tc_exec_ts,
           $ah_bug_rep_col, $ah_bug_res_col, $ah_bug_close_col,
           $ah_tc_has_bug_raised,
           $ah_has_bug_opened_at, $ah_has_bug_in_progress_at,
           $ah_has_bug_resolved_at, $ah_has_bug_closed_at, $ah_has_bug_reopened_at;

    $today_max = date('Y-m-d');
    $is_today  = ($target_date === $today_max);

    // ── Date filter clauses for ACTIVITY metrics ──
    $proj_sf    = $is_today ? ah_today_filter($ah_proj_ts)    : ah_date_filter($ah_proj_ts, $target_date);
    $cli_sf     = $is_today ? ah_today_filter($ah_cli_ts)    : ah_date_filter($ah_cli_ts, $target_date);
    $tc_add_sf  = $is_today ? ah_today_filter($ah_tc_ts)     : ah_date_filter($ah_tc_ts, $target_date);
    $tc_exec_sf = $is_today ? ah_today_filter($ah_tc_exec_ts ?: $ah_tc_ts) : ah_date_filter($ah_tc_exec_ts ?: $ah_tc_ts, $target_date);

    // ── Get active users ──
    $role_filter_sql = "";
    if ($filter_role) {
        $safe_role = $conn->real_escape_string($filter_role);
        $role_filter_sql = " AND role = '$safe_role'";
    }
    $users = [];
    $q_users = $conn->query("SELECT id, name, role FROM users WHERE status = 'active' $role_filter_sql");
    if ($q_users) { while ($u = $q_users->fetch_assoc()) $users[] = $u; }

    $history = [];

    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $projects_added = $clients_added = $test_cases_executed = $bugs_resolved = 0;
        $test_cases_added = $bugs_open = $bugs_in_progress = $bugs_closed = $bugs_reopened = 0;
        $bugs_reported = 0;

        // ═══════════════════════════════════════════════════════
        // ACTIVITY METRICS (filtered by target date)
        // ═══════════════════════════════════════════════════════

        // Projects added on target date
        if ($ah_proj_by_col) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM projects WHERE `$ah_proj_by_col` = $uid $proj_sf");
            if ($r) $projects_added = (int)$r->fetch_assoc()['c'];
        }

        // Clients added on target date
        if ($ah_cli_by_col) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM clients WHERE `$ah_cli_by_col` = $uid $cli_sf");
            if ($r) $clients_added = (int)$r->fetch_assoc()['c'];
        }

        // Test cases added on target date
        if ($ah_tc_created) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$ah_tc_created` = $uid $tc_add_sf");
            if ($r) $test_cases_added = (int)$r->fetch_assoc()['c'];
        }

        // Test cases executed on target date
        if ($ah_tc_is_exec && $ah_tc_executed) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$ah_tc_executed` = $uid AND is_executed = 1 $tc_exec_sf");
            if ($r) $test_cases_executed = (int)$r->fetch_assoc()['c'];
        } elseif ($ah_tc_is_exec && isset($ah_tc_cols['assigned_to'])) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE assigned_to = $uid AND is_executed = 1 $tc_exec_sf");
            if ($r) $test_cases_executed = (int)$r->fetch_assoc()['c'];
        } elseif ($ah_tc_has_status && $ah_tc_executed) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$ah_tc_executed` = $uid AND status IN ('Pass','Fail') $tc_exec_sf");
            if ($r) $test_cases_executed = (int)$r->fetch_assoc()['c'];
        }

        // Bugs reported (filed) on target date
        if ($ah_bugs_in_test_cases && $ah_tc_has_bug_raised && $ah_tc_executed) {
            $exec_col = $ah_tc_executed ?: 'executed_by_id';
            $bug_ts_col = $ah_tc_exec_ts ?: $ah_tc_ts;
            $bug_date_sf = "";
            if ($bug_ts_col) {
                $bug_date_sf = $is_today ? ah_today_filter($bug_ts_col) : ah_date_filter($bug_ts_col, $target_date);
            }
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE bug_raised = 1 AND `$exec_col` = $uid $bug_date_sf");
            if ($r) $bugs_reported = (int)$r->fetch_assoc()['c'];
        } elseif ($ah_has_bugs_table && $ah_bug_rep_col) {
            $bug_ts = ah_detect_ts_col($ah_bug_cols);
            $bug_date_sf = "";
            if ($bug_ts) {
                $bug_date_sf = $is_today ? ah_today_filter($bug_ts) : ah_date_filter($bug_ts, $target_date);
            }
            $r = $conn->query("SELECT COUNT(*) AS c FROM bugs WHERE `$ah_bug_rep_col` = $uid $bug_date_sf");
            if ($r) $bugs_reported = (int)$r->fetch_assoc()['c'];
        }

        // ═══════════════════════════════════════════════════════
        // BUG STATUS — DATE-SPECIFIC (using bug_*_at columns)
        // Shows bugs that CHANGED to that status ON this date only!
        // ═══════════════════════════════════════════════════════

        $exec_col = $ah_tc_executed ?: 'executed_by_id';

        // Bugs Opened on target date (bug_opened_at = this date, by executed_by_id)
        if ($ah_bugs_in_test_cases && $ah_has_bug_opened_at) {
            $sf = $is_today ? ah_today_filter('bug_opened_at') : ah_date_filter('bug_opened_at', $target_date);
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$exec_col` = $uid $sf");
            if ($r) $bugs_open = (int)$r->fetch_assoc()['c'];
        } elseif ($ah_bugs_in_test_cases) {
            // Fallback: no bug_opened_at column, use old current-state method
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE bug_raised = 1 AND bug_status = 'Open' AND `$exec_col` = $uid");
            if ($r) $bugs_open = (int)$r->fetch_assoc()['c'];
        }

        // Bugs In Progress on target date (bug_in_progress_at = this date, by assigned_to)
        if ($ah_bugs_in_test_cases && $ah_has_bug_in_progress_at && isset($ah_tc_cols['assigned_to'])) {
            $sf = $is_today ? ah_today_filter('bug_in_progress_at') : ah_date_filter('bug_in_progress_at', $target_date);
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE assigned_to = $uid $sf");
            if ($r) $bugs_in_progress = (int)$r->fetch_assoc()['c'];
        } elseif ($ah_bugs_in_test_cases && isset($ah_tc_cols['assigned_to'])) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE assigned_to = $uid AND bug_status = 'In Progress'");
            if ($r) $bugs_in_progress = (int)$r->fetch_assoc()['c'];
        }

        // Bugs Resolved on target date (bug_resolved_at = this date, by assigned_to)
        if ($ah_bugs_in_test_cases && $ah_has_bug_resolved_at && isset($ah_tc_cols['assigned_to'])) {
            $sf = $is_today ? ah_today_filter('bug_resolved_at') : ah_date_filter('bug_resolved_at', $target_date);
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE assigned_to = $uid $sf");
            if ($r) $bugs_resolved = (int)$r->fetch_assoc()['c'];
        } elseif ($ah_bugs_in_test_cases && isset($ah_tc_cols['assigned_to'])) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE assigned_to = $uid AND bug_status = 'Resolved'");
            if ($r) $bugs_resolved = (int)$r->fetch_assoc()['c'];
        }

        // Bugs Closed on target date (bug_closed_at = this date, by executed_by_id)
        if ($ah_bugs_in_test_cases && $ah_has_bug_closed_at) {
            $sf = $is_today ? ah_today_filter('bug_closed_at') : ah_date_filter('bug_closed_at', $target_date);
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$exec_col` = $uid $sf");
            if ($r) $bugs_closed = (int)$r->fetch_assoc()['c'];
        } elseif ($ah_bugs_in_test_cases) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$exec_col` = $uid AND bug_status = 'Closed'");
            if ($r) $bugs_closed = (int)$r->fetch_assoc()['c'];
        }

        // Bugs Reopened on target date (bug_reopened_at = this date, by executed_by_id)
        if ($ah_bugs_in_test_cases && $ah_has_bug_reopened_at) {
            $sf = $is_today ? ah_today_filter('bug_reopened_at') : ah_date_filter('bug_reopened_at', $target_date);
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$exec_col` = $uid $sf");
            if ($r) $bugs_reopened = (int)$r->fetch_assoc()['c'];
        } elseif ($ah_bugs_in_test_cases) {
            $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$exec_col` = $uid AND bug_status = 'Reopened'");
            if ($r) $bugs_reopened = (int)$r->fetch_assoc()['c'];
        }

        $history[] = [
            'snapshot_date'       => $target_date,
            'data_source'         => 'date_activity',
            'user_id'             => $uid,
            'user_name'           => $u['name'],
            'user_role'           => $u['role'],
            'projects_added'      => $projects_added,
            'clients_added'       => $clients_added,
            'test_cases_executed' => $test_cases_executed,
            'bugs_reported'       => $bugs_reported,
            'bugs_resolved'       => $bugs_resolved,
            'test_cases_added'    => $test_cases_added,
            'bugs_open'           => $bugs_open,
            'bugs_in_progress'    => $bugs_in_progress,
            'bugs_closed'         => $bugs_closed,
            'bugs_reopened'       => $bugs_reopened,
        ];
    }

    usort($history, function($a, $b) {
        $roleOrder = ['admin' => 0, 'developer' => 1, 'qa' => 2];
        $r = ($roleOrder[$a['user_role']] ?? 3) - ($roleOrder[$b['user_role']] ?? 3);
        if ($r !== 0) return $r;
        return strcmp($a['user_name'], $b['user_name']);
    });

    return $history;
}

// ═══════════════════════════════════════════════════════════════
// FETCH DATE-WISE SUMMARY (ALL dates using GROUP BY)
// ═══════════════════════════════════════════════════════════════
function ah_fetch_date_summary($conn, $filter_role = '') {
    global $ah_proj_by_col, $ah_cli_by_col, $ah_tc_created, $ah_tc_executed,
           $ah_tc_is_exec, $ah_tc_has_status, $ah_proj_ts, $ah_cli_ts, $ah_tc_ts, $ah_tc_exec_ts,
           $ah_bugs_in_test_cases, $ah_tc_has_bug_raised, $ah_has_bugs_table,
           $ah_bug_rep_col, $ah_tc_cols,
           $ah_has_bug_opened_at, $ah_has_bug_in_progress_at,
           $ah_has_bug_resolved_at, $ah_has_bug_closed_at, $ah_has_bug_reopened_at;

    $role_join = "";
    $role_where = "";
    if ($filter_role) {
        $safe_role = $conn->real_escape_string($filter_role);
        $role_join = " INNER JOIN users u ON u.id = t.`$ah_tc_created` AND u.status = 'active' AND u.role = '$safe_role'";
    }

    $dates = []; // date => [metrics]

    // ── Projects added per date (ONLY by active users) ──
    if ($ah_proj_by_col && $ah_proj_ts) {
        $active_join_proj = " INNER JOIN users u ON u.id = p.`$ah_proj_by_col` AND u.status='active'";
        if ($filter_role) {
            $safe_role = $conn->real_escape_string($filter_role);
            $active_join_proj .= " AND u.role='$safe_role'";
        }
        $q = $conn->query("SELECT DATE(p.`$ah_proj_ts`) AS d, COUNT(*) AS c FROM projects p $active_join_proj GROUP BY d ORDER BY d DESC");
        if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['projects_added'] = (int)$r['c']; } }
    }

    // ── Clients added per date (ONLY by active users) ──
    if ($ah_cli_by_col && $ah_cli_ts) {
        $active_join_cli = " INNER JOIN users u ON u.id = cl.`$ah_cli_by_col` AND u.status='active'";
        if ($filter_role) {
            $safe_role = $conn->real_escape_string($filter_role);
            $active_join_cli .= " AND u.role='$safe_role'";
        }
        $q = $conn->query("SELECT DATE(cl.`$ah_cli_ts`) AS d, COUNT(*) AS c FROM clients cl $active_join_cli GROUP BY d ORDER BY d DESC");
        if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['clients_added'] = (int)$r['c']; } }
    }

    // ── Test cases added per date (ONLY by active users) ──
    if ($ah_tc_created && $ah_tc_ts) {
        $active_join_tc = " INNER JOIN users u ON u.id = tc.`$ah_tc_created` AND u.status='active'";
        if ($filter_role) {
            $safe_role = $conn->real_escape_string($filter_role);
            $active_join_tc .= " AND u.role='$safe_role'";
        }
        $q = $conn->query("SELECT DATE(tc.`$ah_tc_ts`) AS d, COUNT(*) AS c FROM test_cases tc $active_join_tc GROUP BY d ORDER BY d DESC");
        if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['test_cases_added'] = (int)$r['c']; } }
    }

    // ── Test cases executed per date (ONLY by active users) ──
    $tc_exec_ts_col = $ah_tc_exec_ts ?: $ah_tc_ts;
    if ($ah_tc_executed && $tc_exec_ts_col) {
        $exec_where = "tc.is_executed = 1";
        if ($ah_tc_has_status) $exec_where = "tc.status IN ('Pass','Fail')";

        $active_join_exec = " INNER JOIN users u ON u.id = tc.`$ah_tc_executed` AND u.status='active'";
        if ($filter_role) {
            $safe_role = $conn->real_escape_string($filter_role);
            $active_join_exec .= " AND u.role='$safe_role'";
        }
        $q = $conn->query("SELECT DATE(tc.`$tc_exec_ts_col`) AS d, COUNT(*) AS c FROM test_cases tc $active_join_exec WHERE $exec_where GROUP BY d ORDER BY d DESC");
        if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['test_cases_executed'] = (int)$r['c']; } }
    }

    // ── Bugs filed/reported per date (ONLY by active users) ──
    if ($ah_bugs_in_test_cases && $ah_tc_has_bug_raised && $ah_tc_ts) {
        $bug_exec_col = $ah_tc_executed ?: 'executed_by_id';
        $active_join_bug = " INNER JOIN users u ON u.id = tc.`$bug_exec_col` AND u.status='active'";
        if ($filter_role) {
            $safe_role = $conn->real_escape_string($filter_role);
            $active_join_bug .= " AND u.role='$safe_role'";
        }
        $q = $conn->query("SELECT DATE(tc.`$ah_tc_ts`) AS d, COUNT(*) AS c FROM test_cases tc $active_join_bug WHERE tc.bug_raised = 1 GROUP BY d ORDER BY d DESC");
        if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['bugs_reported'] = (int)$r['c']; } }
    } elseif ($ah_has_bugs_table && $ah_bug_rep_col) {
        $bug_ts_col = ah_detect_ts_col(ah_detect_columns($conn, 'bugs'));
        if ($bug_ts_col) {
            $active_join_bug = " INNER JOIN users u ON u.id = b.`$ah_bug_rep_col` AND u.status='active'";
            if ($filter_role) {
                $safe_role = $conn->real_escape_string($filter_role);
                $active_join_bug .= " AND u.role='$safe_role'";
            }
            $q = $conn->query("SELECT DATE(b.`$bug_ts_col`) AS d, COUNT(*) AS c FROM bugs b $active_join_bug GROUP BY d ORDER BY d DESC");
            if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['bugs_reported'] = (int)$r['c']; } }
        }
    }

    // ── Bugs Opened per date (by executed_by_id, using bug_opened_at) ──
    if ($ah_bugs_in_test_cases && $ah_has_bug_opened_at) {
        $exec_col = $ah_tc_executed ?: 'executed_by_id';
        $active_join_bo = " INNER JOIN users u ON u.id = tc.`$exec_col` AND u.status='active'";
        if ($filter_role) {
            $safe_role = $conn->real_escape_string($filter_role);
            $active_join_bo .= " AND u.role='$safe_role'";
        }
        $q = $conn->query("SELECT DATE(tc.bug_opened_at) AS d, COUNT(*) AS c FROM test_cases tc $active_join_bo WHERE tc.bug_opened_at IS NOT NULL GROUP BY d ORDER BY d DESC");
        if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['bugs_open'] = (int)$r['c']; } }
    }

    // ── Bugs In Progress per date (by assigned_to, using bug_in_progress_at) ──
    if ($ah_bugs_in_test_cases && $ah_has_bug_in_progress_at && isset($ah_tc_cols['assigned_to'])) {
        $active_join_bip = " INNER JOIN users u ON u.id = tc.assigned_to AND u.status='active'";
        if ($filter_role) {
            $safe_role = $conn->real_escape_string($filter_role);
            $active_join_bip .= " AND u.role='$safe_role'";
        }
        $q = $conn->query("SELECT DATE(tc.bug_in_progress_at) AS d, COUNT(*) AS c FROM test_cases tc $active_join_bip WHERE tc.bug_in_progress_at IS NOT NULL GROUP BY d ORDER BY d DESC");
        if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['bugs_in_progress'] = (int)$r['c']; } }
    }

    // ── Bugs Resolved per date (by assigned_to, using bug_resolved_at) ──
    if ($ah_bugs_in_test_cases && $ah_has_bug_resolved_at && isset($ah_tc_cols['assigned_to'])) {
        $active_join_br = " INNER JOIN users u ON u.id = tc.assigned_to AND u.status='active'";
        if ($filter_role) {
            $safe_role = $conn->real_escape_string($filter_role);
            $active_join_br .= " AND u.role='$safe_role'";
        }
        $q = $conn->query("SELECT DATE(tc.bug_resolved_at) AS d, COUNT(*) AS c FROM test_cases tc $active_join_br WHERE tc.bug_resolved_at IS NOT NULL GROUP BY d ORDER BY d DESC");
        if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['bugs_resolved'] = (int)$r['c']; } }
    }

    // ── Bugs Closed per date (by executed_by_id, using bug_closed_at) ──
    if ($ah_bugs_in_test_cases && $ah_has_bug_closed_at) {
        $exec_col = $ah_tc_executed ?: 'executed_by_id';
        $active_join_bc = " INNER JOIN users u ON u.id = tc.`$exec_col` AND u.status='active'";
        if ($filter_role) {
            $safe_role = $conn->real_escape_string($filter_role);
            $active_join_bc .= " AND u.role='$safe_role'";
        }
        $q = $conn->query("SELECT DATE(tc.bug_closed_at) AS d, COUNT(*) AS c FROM test_cases tc $active_join_bc WHERE tc.bug_closed_at IS NOT NULL GROUP BY d ORDER BY d DESC");
        if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['bugs_closed'] = (int)$r['c']; } }
    }

    // ── Bugs Reopened per date (by executed_by_id, using bug_reopened_at) ──
    if ($ah_bugs_in_test_cases && $ah_has_bug_reopened_at) {
        $exec_col = $ah_tc_executed ?: 'executed_by_id';
        $active_join_bre = " INNER JOIN users u ON u.id = tc.`$exec_col` AND u.status='active'";
        if ($filter_role) {
            $safe_role = $conn->real_escape_string($filter_role);
            $active_join_bre .= " AND u.role='$safe_role'";
        }
        $q = $conn->query("SELECT DATE(tc.bug_reopened_at) AS d, COUNT(*) AS c FROM test_cases tc $active_join_bre WHERE tc.bug_reopened_at IS NOT NULL GROUP BY d ORDER BY d DESC");
        if ($q) { while ($r = $q->fetch_assoc()) { $dates[$r['d']]['bugs_reopened'] = (int)$r['c']; } }
    }

    // ── Build summary array (no more snapshot/live split for bug status!) ──
    $summary = [];
    foreach ($dates as $d => $m) {
        $summary[] = [
            'date'                => $d,
            'projects_added'      => (int)($m['projects_added'] ?? 0),
            'clients_added'       => (int)($m['clients_added'] ?? 0),
            'test_cases_added'    => (int)($m['test_cases_added'] ?? 0),
            'test_cases_executed' => (int)($m['test_cases_executed'] ?? 0),
            'bugs_reported'       => (int)($m['bugs_reported'] ?? 0),
            'bugs_open'           => (int)($m['bugs_open'] ?? 0),
            'bugs_in_progress'    => (int)($m['bugs_in_progress'] ?? 0),
            'bugs_resolved'       => (int)($m['bugs_resolved'] ?? 0),
            'bugs_closed'         => (int)($m['bugs_closed'] ?? 0),
            'bugs_reopened'       => (int)($m['bugs_reopened'] ?? 0),
        ];
    }
    // Remove dates with ALL zeros (no real activity, just empty snapshots)
    $summary = array_filter($summary, function($row) {
        return ($row['projects_added'] + $row['clients_added'] + $row['test_cases_added'] +
                $row['test_cases_executed'] + $row['bugs_reported'] +
                $row['bugs_open'] + $row['bugs_in_progress'] + $row['bugs_resolved'] +
                $row['bugs_closed'] + $row['bugs_reopened']) > 0;
    });
    $summary = array_values($summary);

    // Sort by date DESC
    usort($summary, function($a, $b) { return strcmp($b['date'], $a['date']); });

    return $summary;
}

// ─────────────────────────────────────────────────────────────
// HANDLE CSV DOWNLOAD
// ─────────────────────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    $filter_date = $_GET['date'] ?? '';
    $filter_role = $_GET['role'] ?? '';
    $view_mode   = $_GET['view'] ?? 'summary';

    $today_max = date('Y-m-d');

    if ($view_mode === 'detail' && $filter_date) {
        // Per-user CSV for a specific date
        if ($filter_date > $today_max) $filter_date = $today_max;
        $history = ah_fetch_data_for_date($conn, $filter_date, $filter_role);

        $filename = 'activity_detail_' . $filter_date;
        if ($filter_role) $filename .= '_' . $filter_role;
        $filename .= '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache'); header('Expires: 0');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Date','User Name','Role','Projects Added','Clients Added','TC Executed','Bugs Filed','Bugs Open','In Progress','Resolved','TC Added','Closed','Reopened']);
        foreach ($history as $row) {
            fputcsv($output, [
                $row['snapshot_date'], $row['user_name'], ucfirst($row['user_role']),
                $row['projects_added'], $row['clients_added'], $row['test_cases_executed'],
                $row['bugs_reported'] ?? 0, $row['bugs_open'] ?? 0, $row['bugs_in_progress'] ?? 0,
                $row['bugs_resolved'] ?? 0, $row['test_cases_added'] ?? 0,
                $row['bugs_closed'] ?? 0, $row['bugs_reopened'] ?? 0,
            ]);
        }
        fclose($output);
    } else {
        // Date summary CSV
        $date_summary = ah_fetch_date_summary($conn, $filter_role);
        $filename = 'activity_datewise';
        if ($filter_role) $filename .= '_' . $filter_role;
        $filename .= '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache'); header('Expires: 0');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Date','Projects Added','Clients Added','TC Added','TC Executed','Bugs Filed','Bugs Open','In Progress','Resolved','Closed','Reopened']);
        foreach ($date_summary as $row) {
            fputcsv($output, [
                $row['date'], $row['projects_added'], $row['clients_added'],
                $row['test_cases_added'], $row['test_cases_executed'], $row['bugs_reported'],
                $row['bugs_open'], $row['bugs_in_progress'], $row['bugs_resolved'],
                $row['bugs_closed'], $row['bugs_reopened'],
            ]);
        }
        fclose($output);
    }
    $conn->close();
    exit();
}

// ─────────────────────────────────────────────────────────────
// HANDLE MANUAL SNAPSHOT TRIGGER
// ─────────────────────────────────────────────────────────────
$snapshot_msg = '';
if (isset($_POST['take_snapshot'])) {
    $today = date('Y-m-d');
    define('ALLOW_SNAPSHOT', true);
    include 'save_activity_snapshot.php';
    $snapshot_msg = "Snapshot saved/updated for $today!";
}

// ─────────────────────────────────────────────────────────────
// DETERMINE VIEW MODE
// ─────────────────────────────────────────────────────────────
$view_mode   = $_GET['view'] ?? 'summary';
$filter_date = $_GET['date'] ?? '';
$filter_role = $_GET['role'] ?? '';
$today_max   = date('Y-m-d');

// If a date is specified and view=detail, show user detail view
if ($filter_date && $view_mode === 'detail') {
    if ($filter_date > $today_max) $filter_date = $today_max;
    $is_today = ($filter_date === $today_max);

    // ═══════════════════════════════════════════════════════════════
    // AUTO-SNAPSHOT for today
    // ═══════════════════════════════════════════════════════════════
    if ($is_today) {
        $today_check = $conn->query("SELECT COUNT(*) AS c FROM activity_history WHERE snapshot_date = '$today_max'");
        $today_has_snapshot = false;
        if ($today_check) $today_has_snapshot = ((int)$today_check->fetch_assoc()['c'] > 0);
        if (!$today_has_snapshot) {
            define('ALLOW_SNAPSHOT', true);
            @include 'save_activity_snapshot.php';
        }
    }

    // Fetch per-user data for this date (from source tables — works for ANY date!)
    $history = ah_fetch_data_for_date($conn, $filter_date, $filter_role);
} else {
    // DATE SUMMARY VIEW
    $is_today = false;
    $date_summary = ah_fetch_date_summary($conn, $filter_role);
}

// ─────────────────────────────────────────────────────────────
// SUMMARY STATS (for detail view)
// ─────────────────────────────────────────────────────────────
$summary = [
    'total_projects_added'    => 0,
    'total_clients_added'     => 0,
    'total_tc_executed'       => 0,
    'total_bugs_reported'     => 0,
    'total_bugs_resolved'     => 0,
    'total_tc_added'          => 0,
    'total_bugs_open'         => 0,
    'total_bugs_in_progress'  => 0,
    'total_bugs_closed'       => 0,
    'total_bugs_reopened'     => 0,
    'global_not_tested'       => 0,
    'total_users'             => 0,
];

if (isset($history) && !empty($history)) {
    $summary['total_users'] = count($history);
    foreach ($history as $h) {
        $summary['total_projects_added']   += (int)$h['projects_added'];
        $summary['total_clients_added']    += (int)$h['clients_added'];
        $summary['total_tc_executed']      += (int)$h['test_cases_executed'];
        $summary['total_bugs_reported']    += (int)($h['bugs_reported'] ?? 0);
        $summary['total_bugs_resolved']    += (int)($h['bugs_resolved'] ?? 0);
        $summary['total_tc_added']         += (int)($h['test_cases_added'] ?? 0);
        $summary['total_bugs_open']        += (int)($h['bugs_open'] ?? 0);
        $summary['total_bugs_in_progress'] += (int)($h['bugs_in_progress'] ?? 0);
        $summary['total_bugs_closed']      += (int)($h['bugs_closed'] ?? 0);
        $summary['total_bugs_reopened']    += (int)($h['bugs_reopened'] ?? 0);
    }
}

// Global Not Tested count
if (isset($ah_tc_cols['status'])) {
    $r_nt = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE status = 'Not tested'");
    if ($r_nt) $summary['global_not_tested'] = (int)$r_nt->fetch_assoc()['c'];
} elseif (isset($ah_tc_cols['is_executed'])) {
    $r_nt = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE is_executed = 0");
    if ($r_nt) $summary['global_not_tested'] = (int)$r_nt->fetch_assoc()['c'];
}

// Dynamic role list
$all_roles = [];
$q_roles = $conn->query("SELECT DISTINCT role FROM users WHERE status = 'active' ORDER BY role ASC");
if ($q_roles) { while ($r = $q_roles->fetch_assoc()) { if (!empty($r['role'])) $all_roles[] = $r['role']; } }

// Pagination (for detail view)
$per_page = 10;
$total_records = isset($history) ? count($history) : 0;
$total_pages = max(1, ceil($total_records / $per_page));
$current_page = isset($_GET['page']) ? max(1, min((int)$_GET['page'], $total_pages)) : 1;
$offset = ($current_page - 1) * $per_page;
$history_page = isset($history) ? array_slice($history, $offset, $per_page) : [];

// Pagination for summary view
$summary_per_page = 15;
$summary_total = isset($date_summary) ? count($date_summary) : 0;
$summary_pages = max(1, ceil($summary_total / $summary_per_page));
$summary_page_num = isset($_GET['page']) ? max(1, min((int)$_GET['page'], $summary_pages)) : 1;
$summary_offset = ($summary_page_num - 1) * $summary_per_page;
$date_summary_page = isset($date_summary) ? array_slice($date_summary, $summary_offset, $summary_per_page) : [];

$conn->close();

function role_color(string $role): string {
    switch ($role) {
        case 'admin':     return 'ra-admin';
        case 'developer': return 'ra-dev';
        case 'qa':        return 'ra-qa';
        default:          return 'ra-default';
    }
}

function format_date_short($d) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($d === $today) return 'Today';
    if ($d === $yesterday) return 'Yesterday';
    return date('d M Y', strtotime($d));
}

function format_date_day($d) {
    return date('D', strtotime($d));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TestiFy — Activity History</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../css/ad_dash.css">
<link rel="stylesheet" href="../css/activity_history.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<style>
  .ah-view-tabs { display:flex; gap:4px; margin-bottom:16px; }
  .ah-view-tab {
    display:inline-flex; align-items:center; gap:6px; padding:8px 16px;
    border-radius:10px; font-size:13px; font-weight:600; text-decoration:none;
    transition:all 0.15s; border:1px solid #e2e8f0; background:#f8fafc; color:#475569;
  }
  .ah-view-tab:hover { background:#e2e8f0; color:#1e293b; }
  .ah-view-tab.active {
    background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff;
    border-color:transparent; box-shadow:0 2px 8px rgba(99,102,241,0.3);
  }
  .ah-live-badge {
    display: inline-flex; align-items: center; gap: 4px;
    background: #dcfce7; color: #16a34a; font-size: 11px; font-weight: 700;
    padding: 2px 8px; border-radius: 20px; margin-left: 8px;
    text-transform: uppercase; letter-spacing: 0.5px;
  }
  .ah-live-dot {
    width: 6px; height: 6px; background: #16a34a; border-radius: 50%;
    animation: livePulse 1.5s ease-in-out infinite;
  }
  @keyframes livePulse { 0%,100%{opacity:1} 50%{opacity:0.3} }
  .ah-snapshot-badge {
    display: inline-flex; align-items: center; gap: 4px;
    background: #fef3c7; color: #d97706; font-size: 11px; font-weight: 700;
    padding: 2px 8px; border-radius: 20px; margin-left: 8px;
    text-transform: uppercase; letter-spacing: 0.5px;
  }
  .ah-stat-tip { position:relative; cursor:default; }
  .ah-stat-tip:hover::after {
    content: attr(data-tip); position:absolute; bottom:calc(100% + 6px);
    left:50%; transform:translateX(-50%); background:#1e293b; color:#f1f5f9;
    font-size:11px; padding:4px 10px; border-radius:6px; white-space:nowrap;
    z-index:100; pointer-events:none;
  }
  .ah-stat-tip:hover::before {
    content:''; position:absolute; bottom:calc(100% + 2px); left:50%;
    transform:translateX(-50%); border:4px solid transparent;
    border-top-color:#1e293b; z-index:100; pointer-events:none;
  }
  .ah-summary-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; }
  @media(max-width:1024px){ .ah-summary-grid{grid-template-columns:repeat(3,1fr)} }
  @media(max-width:640px){ .ah-summary-grid{grid-template-columns:repeat(2,1fr)} }
  .ah-pagination {
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:12px; margin-top:16px; padding:12px 16px;
    background:#fff; border:1px solid #e2e8f0; border-radius:10px;
  }
  .ah-pagination-info { font-size:13px; color:#64748b; font-weight:600; }
  .ah-pagination-controls { display:flex; align-items:center; gap:4px; }
  .ah-page-btn {
    display:inline-flex; align-items:center; gap:4px; padding:6px 12px;
    border-radius:8px; font-size:13px; font-weight:600; color:#475569;
    background:#f1f5f9; border:1px solid #e2e8f0; text-decoration:none;
    transition:all 0.15s;
  }
  .ah-page-btn:hover { background:#e2e8f0; color:#1e293b; }
  .ah-page-btn.disabled { opacity:0.4; cursor:not-allowed; pointer-events:none; }
  .ah-page-num {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:34px; height:34px; border-radius:8px; font-size:13px;
    font-weight:600; color:#475569; background:#f8fafc; border:1px solid #e2e8f0;
    text-decoration:none; transition:all 0.15s;
  }
  .ah-page-num:hover { background:#e2e8f0; color:#1e293b; }
  .ah-page-num.active {
    background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff;
    border-color:transparent; box-shadow:0 2px 8px rgba(99,102,241,0.3);
  }
  .ah-date-row { cursor:pointer; transition:background 0.1s; }
  .ah-date-row:hover { background:#f0f4ff !important; }
  .ah-date-cell {
    display:flex; flex-direction:column; gap:2px; min-width:110px;
  }
  .ah-date-main { font-weight:700; font-size:13px; color:#1e293b; }
  .ah-date-sub { font-size:11px; color:#94a3b8; font-weight:600; }
  .ah-date-today { color:#6366f1 !important; }
  .ah-zero { color:#cbd5e1; }
  .ah-date-nav { display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap; }
  .ah-date-chips { display:flex; align-items:center; gap:4px; flex-wrap:wrap; }
  .ah-date-chip {
    display:inline-flex; align-items:center; padding:4px 10px; border-radius:6px;
    font-size:12px; font-weight:600; color:#475569; background:#f1f5f9;
    border:1px solid #e2e8f0; cursor:pointer; text-decoration:none; transition:all 0.15s;
  }
  .ah-date-chip:hover { background:#e2e8f0; color:#1e293b; }
  .ah-date-chip.active {
    background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff;
    border-color:transparent; box-shadow:0 2px 6px rgba(99,102,241,0.25);
  }
  .ah-date-chip-label { font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; margin-right:4px; }
  .ah-back-link {
    display:inline-flex; align-items:center; gap:4px; font-size:13px; font-weight:600;
    color:#6366f1; text-decoration:none; margin-bottom:8px;
  }
  .ah-back-link:hover { text-decoration:underline; }
  .ah-date-header {
    display:flex; align-items:center; gap:12px; margin-bottom:4px;
  }
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <div style="display:flex;align-items:center;gap:10px">
    <button class="ham" id="hamBtn" onclick="toggleSidebar()" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
    <span class="nblogo">TestiFy</span>
  </div>
  <div class="nb-right">
    <div class="nb-user">
      <span><?= htmlspecialchars($_SESSION['name'] ?? 'Guest') ?></span>
      <?php $user_role = $_SESSION['role'] ?? ''; if($user_role): ?>
        <span class="nb-role"><?= htmlspecialchars(ucfirst($user_role)) ?></span>
      <?php endif; ?>
    </div>
    <a href="../logout.php" class="blgout">Logout</a>
  </div>
</nav>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <a href="../dash/admin_dash.php" class="sb-home">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    Home
  </a>
  <div class="sb-section">Pages</div>
  <a href="../page/user.php" class="sb-link"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Users</a>
  <a href="../page/client.php" class="sb-link"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg> Clients</a>
  <a href="../page/project.php" class="sb-link"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg> Projects</a>
  <a href="../page/requirement.php" class="sb-link"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> Requirements</a>
  <a href="../page/test_plans.php" class="sb-link"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg> Test Plans</a>
  <a href="../page/test_cases.php" class="sb-link"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg> Test Cases</a>
  <a href="../page/technology.php" class="sb-link"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> Technologies</a>
  <a href="../page/test_types.php" class="sb-link"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg> Testing Types</a>
  <a href="../page/project_reports.php" class="sb-link"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Reports</a>
  <div class="sb-section">Analytics</div>
  <a href="activity_history.php" class="sb-link sb-active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Activity History</a>
</aside>

<!-- MAIN CONTENT -->
<div class="page-wrap" id="pageWrap">
  <div class="main">
    <div class="page-title">
      <h1>Activity History</h1>
      <span class="badge-overview" style="background:var(--activity-light);color:var(--activity);">
        <?= $view_mode === 'detail' ? 'User Detail' : 'Datewise View' ?>
      </span>
      <?php if ($view_mode === 'detail' && $is_today): ?>
        <span class="ah-live-badge"><span class="ah-live-dot"></span> Live</span>
      <?php elseif ($view_mode === 'detail' && !$is_today): ?>
        <span class="ah-snapshot-badge">Date-Specific Data</span>
      <?php endif; ?>
    </div>

    <?php if ($snapshot_msg): ?>
    <div class="ah-toast" id="snapshotToast"><?= htmlspecialchars($snapshot_msg) ?></div>
    <script>setTimeout(()=>{const t=document.getElementById('snapshotToast');if(t)t.style.opacity='0';},4000);</script>
    <?php endif; ?>

    <!-- View Tabs -->
    <div class="ah-view-tabs">
      <a href="?view=summary&role=<?= urlencode($filter_role) ?>" class="ah-view-tab <?= $view_mode !== 'detail' ? 'active' : '' ?>">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Datewise Summary
      </a>
      <?php if ($view_mode === 'detail' && $filter_date): ?>
      <a href="?view=detail&date=<?= urlencode($filter_date) ?>&role=<?= urlencode($filter_role) ?>" class="ah-view-tab active">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        User Detail — <?= format_date_short($filter_date) ?>
      </a>
      <?php endif; ?>
    </div>

    <!-- Filter Bar -->
    <div class="ah-filter-bar">
      <form method="GET" action="" class="ah-filter-form" id="filterForm">
        <input type="hidden" name="view" value="detail" />
        <div class="ah-filter-group">
          <label for="filterDate"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:15px;height:15px;vertical-align:middle;margin-right:4px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Date</label>
          <input type="date" id="filterDate" name="date" value="<?= htmlspecialchars($filter_date ?: $today_max) ?>" class="ah-input" onchange="document.getElementById('filterForm').submit();" />
        </div>
        <div class="ah-filter-group">
          <label for="filterRole"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:15px;height:15px;vertical-align:middle;margin-right:4px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Role</label>
          <select id="filterRole" name="role" class="ah-input ah-select">
            <option value="">All Roles</option>
            <?php foreach ($all_roles as $ar): ?>
            <option value="<?= htmlspecialchars($ar) ?>" <?= $filter_role === $ar ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($ar)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="ah-btn ah-btn-primary"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Filter</button>
        <?php
          $csv_params = array_filter(['view'=>$view_mode,'date'=>$filter_date,'role'=>$filter_role,'download'=>'csv']);
        ?>
        <a href="?<?= http_build_query($csv_params) ?>" class="ah-btn ah-btn-download"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> CSV</a>
        <?php if ($view_mode === 'detail' && $is_today): ?>
        <button type="button" class="ah-btn ah-btn-snapshot" id="saveSnapshotBtn" onclick="takeSnapshot()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg> Save Snapshot</button>
        <?php endif; ?>
      </form>
    </div>

    <?php if ($view_mode === 'detail' && $filter_date): ?>
    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- USER DETAIL VIEW -->
    <!-- ═══════════════════════════════════════════════════════════ -->

    <a href="?view=summary&role=<?= urlencode($filter_role) ?>" class="ah-back-link">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><polyline points="15 18 9 12 15 6"/></svg>
      Back to Datewise Summary
    </a>

    <!-- Summary Cards for this date -->
    <div class="ah-summary-grid">
      <div class="ah-sum-card ah-sum-blue"><div class="ah-sum-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></div><div class="ah-sum-value"><?= $summary['total_projects_added'] ?></div><div class="ah-sum-label">Projects Added</div></div>
      <div class="ah-sum-card ah-sum-purple"><div class="ah-sum-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></div><div class="ah-sum-value"><?= $summary['total_clients_added'] ?></div><div class="ah-sum-label">Clients Added</div></div>
      <div class="ah-sum-card ah-sum-green"><div class="ah-sum-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg></div><div class="ah-sum-value"><?= $summary['total_tc_executed'] ?></div><div class="ah-sum-label">TC Executed</div></div>
      <div class="ah-sum-card" style="background:linear-gradient(135deg,#fef2f2,#fecaca);border-color:#f87171;"><div class="ah-sum-icon" style="color:#b91c1c;"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div><div class="ah-sum-value" style="color:#b91c1c;"><?= $summary['total_bugs_reported'] ?></div><div class="ah-sum-label" style="color:#b91c1c;">Bugs Filed</div></div>
      <div class="ah-sum-card ah-sum-red"><div class="ah-sum-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div><div class="ah-sum-value"><?= $summary['total_bugs_open'] ?></div><div class="ah-sum-label">Bugs Open</div></div>
      <div class="ah-sum-card" style="background:linear-gradient(135deg,#fef3c7,#fde68a);border-color:#fbbf24;"><div class="ah-sum-icon" style="color:#92400e;"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg></div><div class="ah-sum-value" style="color:#92400e;"><?= $summary['total_bugs_in_progress'] ?></div><div class="ah-sum-label" style="color:#92400e;">In Progress</div></div>
      <div class="ah-sum-card ah-sum-orange"><div class="ah-sum-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg></div><div class="ah-sum-value"><?= $summary['total_bugs_resolved'] ?></div><div class="ah-sum-label">Bugs Resolved</div></div>
      <div class="ah-sum-card ah-sum-teal"><div class="ah-sum-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div><div class="ah-sum-value"><?= $summary['total_tc_added'] ?></div><div class="ah-sum-label">TC Added</div></div>
      <div class="ah-sum-card ah-sum-dark-teal"><div class="ah-sum-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div><div class="ah-sum-value"><?= $summary['total_bugs_closed'] ?></div><div class="ah-sum-label">Bugs Closed</div></div>
      <div class="ah-sum-card" style="background:linear-gradient(135deg,#fff1f2,#fecdd3);border-color:#f43f5e;"><div class="ah-sum-icon" style="color:#be123c;"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg></div><div class="ah-sum-value" style="color:#be123c;"><?= $summary['total_bugs_reopened'] ?></div><div class="ah-sum-label" style="color:#be123c;">Reopened</div></div>
    </div>

    <!-- Per-User Table -->
    <div class="panel ah-panel">
      <h2 style="display:flex;align-items:center;gap:8px;">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;color:var(--activity);flex-shrink:0;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        User Activity — <?= format_date_short($filter_date) ?>
        <?php if (!empty($history)): ?><span class="ah-count-badge"><?= count($history) ?> users</span><?php endif; ?>
      </h2>

      <?php if (empty($history)): ?>
        <div class="ah-empty">
          <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="width:40px;height:40px;color:var(--text-muted);margin-bottom:8px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <div>No activity data for <strong><?= htmlspecialchars($filter_date) ?></strong>.</div>
        </div>
      <?php else: ?>
        <div class="ah-table-wrap">
          <table class="ah-table">
            <thead>
              <tr>
                <th>User</th><th>Role</th><th>Projects</th><th>Clients</th><th>TC Exec</th>
                <th style="background:#fef2f2;">Bugs Filed</th>
                <th style="background:#fef2f2;">Open</th><th style="background:#fffbeb;">In Prog</th>
                <th style="background:#f0fdf4;">Resolved</th><th>TC Add</th>
                <th style="background:#ecfeff;">Closed</th><th style="background:#fff1f2;">Reopened</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($history_page as $h): ?>
              <tr>
                <td><div class="ah-table-user"><span class="ah-table-avatar ah-avatar-<?= $h['user_role'] ?>"><?= strtoupper(substr($h['user_name'], 0, 1)) ?></span><?= htmlspecialchars($h['user_name']) ?></div></td>
                <td><span class="ra-role-pill <?= role_color($h['user_role']) ?>"><?= htmlspecialchars(ucfirst($h['user_role'])) ?></span></td>
                <td><span class="ra-stat ra-stat-blue <?= (int)$h['projects_added']===0?'ah-zero':'' ?>"><?= (int)$h['projects_added'] ?></span></td>
                <td><span class="ra-stat ra-stat-purple <?= (int)$h['clients_added']===0?'ah-zero':'' ?>"><?= (int)$h['clients_added'] ?></span></td>
                <td><span class="ra-stat ra-stat-green <?= (int)$h['test_cases_executed']===0?'ah-zero':'' ?>"><?= (int)$h['test_cases_executed'] ?></span></td>
                <td><span class="ra-stat ah-stat-tip" style="background:#fef2f2;color:#b91c1c;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)($h['bugs_reported']??0)===0?'opacity:0.4':'' ?>" data-tip="bug_raised=1 on this date (executed_by_id)"><?= (int)($h['bugs_reported'] ?? 0) ?></span></td>
                <td><span class="ra-stat ah-stat-tip" style="background:#fef2f2;color:#dc2626;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)($h['bugs_open']??0)===0?'opacity:0.4':'' ?>" data-tip="bug_status='Open' (executed_by_id=user)"><?= (int)($h['bugs_open'] ?? 0) ?></span></td>
                <td><span class="ra-stat ah-stat-tip" style="background:#fffbeb;color:#d97706;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)($h['bugs_in_progress']??0)===0?'opacity:0.4':'' ?>" data-tip="bug_status='In Progress' (assigned_to=user)"><?= (int)($h['bugs_in_progress'] ?? 0) ?></span></td>
                <td><span class="ra-stat ra-stat-orange ah-stat-tip <?= (int)$h['bugs_resolved']===0?'ah-zero':'' ?>" data-tip="bug_status='Resolved' ONLY (assigned_to=user)"><?= (int)$h['bugs_resolved'] ?></span></td>
                <td><span class="ra-stat ra-stat-teal <?= (int)$h['test_cases_added']===0?'ah-zero':'' ?>"><?= (int)$h['test_cases_added'] ?></span></td>
                <td><span class="ra-stat ah-stat-tip" style="background:#ecfeff;color:#0891b2;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)($h['bugs_closed']??0)===0?'opacity:0.4':'' ?>" data-tip="bug_status='Closed' (executed_by_id=user)"><?= (int)($h['bugs_closed'] ?? 0) ?></span></td>
                <td><span class="ra-stat ah-stat-tip" style="background:#fff1f2;color:#b91c1c;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)($h['bugs_reopened']??0)===0?'opacity:0.4':'' ?>" data-tip="bug_status='Reopened' (executed_by_id=user)"><?= (int)($h['bugs_reopened'] ?? 0) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pagination for detail view -->
    <?php if ($total_pages > 1): ?>
    <div class="ah-pagination">
      <div class="ah-pagination-info">Showing <?= ($offset + 1) ?>–<?= min($offset + $per_page, $total_records) ?> of <?= $total_records ?> users</div>
      <div class="ah-pagination-controls">
        <?php $pq = http_build_query(array_filter(['view'=>'detail','date'=>$filter_date,'role'=>$filter_role,'page'=>max(1,$current_page-1)])); ?>
        <?php $nq = http_build_query(array_filter(['view'=>'detail','date'=>$filter_date,'role'=>$filter_role,'page'=>min($total_pages,$current_page+1)])); ?>
        <a href="?<?= $pq ?>" class="ah-page-btn <?= $current_page<=1?'disabled':'' ?>"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><polyline points="15 18 9 12 15 6"/></svg> Prev</a>
        <?php
          $sp = max(1,$current_page-3); $ep = min($total_pages,$sp+6);
          if($ep-$sp<6) $sp = max(1,$ep-6);
          for($p=$sp;$p<=$ep;$p++):
            $pQ = http_build_query(array_filter(['view'=>'detail','date'=>$filter_date,'role'=>$filter_role,'page'=>$p]));
        ?>
          <a href="?<?= $pQ ?>" class="ah-page-num <?= $p===$current_page?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a href="?<?= $nq ?>" class="ah-page-btn <?= $current_page>=$total_pages?'disabled':'' ?>">Next <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><polyline points="9 18 15 12 9 6"/></svg></a>
      </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- DATEWISE SUMMARY VIEW -->
    <!-- ═══════════════════════════════════════════════════════════ -->

    <!-- Quick date chips -->
    <div class="ah-date-nav">
      <div class="ah-date-chips">
        <span class="ah-date-chip-label">Jump:</span>
        <?php
          // Get the 7 most recent dates from summary
          $quick_dates = array_slice($date_summary_page, 0, 7);
        ?>
        <?php foreach ($quick_dates as $qd): ?>
          <a href="?view=detail&date=<?= urlencode($qd['date']) ?>&role=<?= urlencode($filter_role) ?>" class="ah-date-chip"><?= format_date_short($qd['date']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Datewise Summary Table -->
    <div class="panel ah-panel">
      <h2 style="display:flex;align-items:center;gap:8px;">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;color:var(--activity);flex-shrink:0;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Activity by Date
        <?php if (!empty($date_summary)): ?><span class="ah-count-badge"><?= count($date_summary) ?> dates</span><?php endif; ?>
      </h2>

      <?php if (empty($date_summary)): ?>
        <div class="ah-empty">
          <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="width:40px;height:40px;color:var(--text-muted);margin-bottom:8px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <div>No activity data yet. Start using TestiFy to see datewise activity here.</div>
        </div>
      <?php else: ?>
        <div class="ah-table-wrap">
          <table class="ah-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Projects</th><th>Clients</th><th>TC Added</th><th>TC Exec</th>
                <th style="background:#fef2f2;">Bugs Filed</th>
                <th style="background:#fef2f2;">Open</th><th style="background:#fffbeb;">In Prog</th>
                <th style="background:#f0fdf4;">Resolved</th>
                <th style="background:#ecfeff;">Closed</th><th style="background:#fff1f2;">Reopened</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($date_summary_page as $ds): ?>
              <tr class="ah-date-row" onclick="window.location='?view=detail&date=<?= urlencode($ds['date']) ?>&role=<?= urlencode($filter_role) ?>'">
                <td>
                  <div class="ah-date-cell">
                    <span class="ah-date-main <?= $ds['date']===$today_max?'ah-date-today':'' ?>"><?= format_date_short($ds['date']) ?></span>
                    <span class="ah-date-sub"><?= format_date_day($ds['date']) ?>, <?= $ds['date'] ?></span>
                  </div>
                </td>
                <td><span class="ra-stat ra-stat-blue <?= (int)$ds['projects_added']===0?'ah-zero':'' ?>"><?= (int)$ds['projects_added'] ?></span></td>
                <td><span class="ra-stat ra-stat-purple <?= (int)$ds['clients_added']===0?'ah-zero':'' ?>"><?= (int)$ds['clients_added'] ?></span></td>
                <td><span class="ra-stat ra-stat-teal <?= (int)$ds['test_cases_added']===0?'ah-zero':'' ?>"><?= (int)$ds['test_cases_added'] ?></span></td>
                <td><span class="ra-stat ra-stat-green <?= (int)$ds['test_cases_executed']===0?'ah-zero':'' ?>"><?= (int)$ds['test_cases_executed'] ?></span></td>
                <td><span class="ra-stat" style="background:#fef2f2;color:#b91c1c;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)$ds['bugs_reported']===0?'opacity:0.4':'' ?>"><?= (int)$ds['bugs_reported'] ?></span></td>
                <td><span class="ra-stat" style="background:#fef2f2;color:#dc2626;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)$ds['bugs_open']===0?'opacity:0.4':'' ?>"><?= (int)$ds['bugs_open'] ?></span></td>
                <td><span class="ra-stat" style="background:#fffbeb;color:#d97706;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)$ds['bugs_in_progress']===0?'opacity:0.4':'' ?>"><?= (int)$ds['bugs_in_progress'] ?></span></td>
                <td><span class="ra-stat" style="background:#f0fdf4;color:#16a34a;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)$ds['bugs_resolved']===0?'opacity:0.4':'' ?>"><?= (int)$ds['bugs_resolved'] ?></span></td>
                <td><span class="ra-stat" style="background:#ecfeff;color:#0891b2;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)$ds['bugs_closed']===0?'opacity:0.4':'' ?>"><?= (int)$ds['bugs_closed'] ?></span></td>
                <td><span class="ra-stat" style="background:#fff1f2;color:#b91c1c;border-radius:6px;padding:2px 8px;font-weight:700;<?= (int)$ds['bugs_reopened']===0?'opacity:0.4':'' ?>"><?= (int)$ds['bugs_reopened'] ?></span></td>
                <td>
                  <a href="?view=detail&date=<?= urlencode($ds['date']) ?>&role=<?= urlencode($filter_role) ?>" class="ah-btn ah-btn-primary" style="padding:4px 10px;font-size:11px;white-space:nowrap;">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:12px;height:12px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    Users
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pagination for summary view -->
    <?php if ($summary_pages > 1): ?>
    <div class="ah-pagination">
      <div class="ah-pagination-info">Showing <?= ($summary_offset + 1) ?>–<?= min($summary_offset + $summary_per_page, $summary_total) ?> of <?= $summary_total ?> dates</div>
      <div class="ah-pagination-controls">
        <?php $spq = http_build_query(array_filter(['view'=>'summary','role'=>$filter_role,'page'=>max(1,$summary_page_num-1)])); ?>
        <?php $snq = http_build_query(array_filter(['view'=>'summary','role'=>$filter_role,'page'=>min($summary_pages,$summary_page_num+1)])); ?>
        <a href="?<?= $spq ?>" class="ah-page-btn <?= $summary_page_num<=1?'disabled':'' ?>"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><polyline points="15 18 9 12 15 6"/></svg> Prev</a>
        <?php
          $s_sp = max(1,$summary_page_num-3); $s_ep = min($summary_pages,$s_sp+6);
          if($s_ep-$s_sp<6) $s_sp = max(1,$s_ep-6);
          for($p=$s_sp;$p<=$s_ep;$p++):
            $spQ = http_build_query(array_filter(['view'=>'summary','role'=>$filter_role,'page'=>$p]));
        ?>
          <a href="?<?= $spQ ?>" class="ah-page-num <?= $p===$summary_page_num?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a href="?<?= $snq ?>" class="ah-page-btn <?= $summary_page_num>=$summary_pages?'disabled':'' ?>">Next <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><polyline points="9 18 15 12 9 6"/></svg></a>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; /* end view_mode check */ ?>

  </div>
</div>

<form method="POST" action="" id="snapshotForm" style="display:none;"><input type="hidden" name="take_snapshot" value="1" /></form>

<div class="ah-capture-overlay" id="captureOverlay" style="display:none;">
  <div class="ah-capture-box"><div class="ah-capture-spinner"></div><div>Capturing screenshot...</div></div>
</div>

<script>
  function toggleSidebar(){const sb=document.getElementById('sidebar'),ov=document.getElementById('sbOverlay'),hm=document.getElementById('hamBtn');const isOpen=sb.classList.toggle('open');ov.classList.toggle('open',isOpen);hm.classList.toggle('open',isOpen);document.body.style.overflow=(isOpen&&window.innerWidth<=768)?'hidden':'';}
  function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sbOverlay').classList.remove('open');document.getElementById('hamBtn').classList.remove('open');document.body.style.overflow='';}
  window.addEventListener('resize',()=>{if(window.innerWidth>768)closeSidebar();});

  function doCapture(callback){
    const overlay=document.getElementById('captureOverlay');overlay.style.display='flex';
    const target=document.getElementById('pageWrap');
    html2canvas(target,{backgroundColor:'#f0f2f5',scale:2,useCORS:true,logging:false,scrollX:0,scrollY:-window.scrollY,windowWidth:target.scrollWidth,windowHeight:target.scrollHeight}).then(canvas=>{
      overlay.style.display='none';const today='<?= date('Y-m-d') ?>';const link=document.createElement('a');link.download='activity_'+today+'.png';link.href=canvas.toDataURL('image/png');link.click();if(callback)callback();
    }).catch(err=>{overlay.style.display='none';console.error('Capture failed:',err);if(callback)callback();});
  }
  function takeSnapshot(){const btn=document.getElementById('saveSnapshotBtn');btn.disabled=true;btn.style.opacity='0.6';doCapture(function(){document.getElementById('snapshotForm').submit();});}
  function captureScreenshot(){doCapture(null);}
</script>
</body>
</html>
