<?php
/* ═══════════════════════════════════════════════════════════════
   TestiFy — Save Activity Snapshot (runs daily via cron)
   Saves TODAY's data to activity_history table.
   
   ★ RULES (same as activity_history.php):
   - Activity metrics (projects/clients/TC added, TC executed, bugs filed): TODAY only
   - Bug status metrics (Open/In Progress/Resolved/Closed/Reopened): CURRENT STATE
   - Bugs Filed   = bug_raised=1 on today (by executed_by_id)
   - Bugs Open    = bug_status='Open' ONLY (executed_by_id)
   - Bugs In Prog = bug_status='In Progress' ONLY (assigned_to)
   - Bugs Resolved= bug_status='Resolved' ONLY (assigned_to)
   - Bugs Closed  = bug_status='Closed' ONLY (executed_by_id)
   - Bugs Reopened= bug_status='Reopened' ONLY (executed_by_id)
   
   Set up cron job:
   0 23 * * * php /path/to/ProjectBug/dash/save_activity_snapshot.php
   
   Or call manually:
   http://localhost/ProjectBug/dash/save_activity_snapshot.php?key=YOUR_SECRET_KEY
   ═══════════════════════════════════════════════════════════════ */

date_default_timezone_set('Asia/Kolkata');

$secret_key = 'testify_snapshot_2024'; // Change this!
if (php_sapi_name() !== 'cli' && !defined('ALLOW_SNAPSHOT')) {
    if (!isset($_GET['key']) || $_GET['key'] !== $secret_key) {
        http_response_code(403);
        die('Access denied.');
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    include '../config/db.php';
}

// Set MySQL session timezone to IST
$conn->query("SET time_zone = '+05:30'");

$today = date('Y-m-d');

// ═══════════════════════════════════════════════════════════════
// STEP 1: Ensure activity_history table has ALL required columns
// ═══════════════════════════════════════════════════════════════
$required_columns = [
    'snapshot_date'       => "VARCHAR(10) NOT NULL",
    'user_id'             => "INT NOT NULL",
    'user_name'           => "VARCHAR(100) NOT NULL",
    'user_role'           => "VARCHAR(50) NOT NULL",
    'projects_added'      => "INT DEFAULT 0",
    'clients_added'       => "INT DEFAULT 0",
    'test_cases_executed' => "INT DEFAULT 0",
    'bugs_reported'       => "INT DEFAULT 0",
    'bugs_resolved'       => "INT DEFAULT 0",
    'test_cases_added'    => "INT DEFAULT 0",
    'bugs_open'           => "INT DEFAULT 0",
    'bugs_in_progress'    => "INT DEFAULT 0",
    'bugs_closed'         => "INT DEFAULT 0",
    'bugs_reopened'       => "INT DEFAULT 0",
];

// Check if table exists
$table_check = $conn->query("SHOW TABLES LIKE 'activity_history'");
if (!$table_check || $table_check->num_rows === 0) {
    $createSQL = "CREATE TABLE activity_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_date VARCHAR(10) NOT NULL,
        user_id INT NOT NULL,
        user_name VARCHAR(100) NOT NULL,
        user_role VARCHAR(50) NOT NULL,
        projects_added INT DEFAULT 0,
        clients_added INT DEFAULT 0,
        test_cases_executed INT DEFAULT 0,
        bugs_reported INT DEFAULT 0,
        bugs_resolved INT DEFAULT 0,
        test_cases_added INT DEFAULT 0,
        bugs_open INT DEFAULT 0,
        bugs_in_progress INT DEFAULT 0,
        bugs_closed INT DEFAULT 0,
        bugs_reopened INT DEFAULT 0,
        UNIQUE KEY uniq_snapshot_user (snapshot_date, user_id),
        KEY idx_snapshot_date (snapshot_date),
        KEY idx_user_role (user_role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($createSQL);
} else {
    // Add missing columns
    $existing_cols = [];
    $col_result = $conn->query("SHOW COLUMNS FROM activity_history");
    if ($col_result) {
        while ($cr = $col_result->fetch_assoc()) {
            $existing_cols[$cr['Field']] = true;
        }
    }
    foreach ($required_columns as $col_name => $col_def) {
        if (!isset($existing_cols[$col_name])) {
            $conn->query("ALTER TABLE activity_history ADD COLUMN `$col_name` $col_def");
        }
    }
}

// ── Helper functions ──
function snap_detect_columns($conn, $table) {
    $cols = [];
    try {
        $result = $conn->query("SHOW COLUMNS FROM `$table`");
        if ($result) {
            while ($row = $result->fetch_assoc()) $cols[$row['Field']] = true;
        }
    } catch (Exception $e) {}
    return $cols;
}

function snap_detect_ts_col($cols) {
    if (isset($cols['created_at']))  return 'created_at';
    if (isset($cols['createdAt']))   return 'createdAt';
    return null;
}

function snap_detect_exec_ts_col($cols) {
    if (isset($cols['executed_on']))  return 'executed_on';
    if (isset($cols['executed_at']))  return 'executed_at';
    if (isset($cols['updated_at']))  return 'updated_at';
    return null;
}

function snap_today_filter($ts_col) {
    if ($ts_col === null) return "";
    return "AND DATE(`$ts_col`) = CURDATE()";
}

// ═══════════════════════════════════════════════════════════════
// DETECT COLUMNS
// ═══════════════════════════════════════════════════════════════
$tc_cols   = snap_detect_columns($conn, 'test_cases');
$bug_cols  = [];
try { $bug_cols = snap_detect_columns($conn, 'bugs'); } catch (Exception $e) {}
$proj_cols = snap_detect_columns($conn, 'projects');
$cli_cols  = snap_detect_columns($conn, 'clients');

$tc_has_bug_status  = isset($tc_cols['bug_status']);
$tc_has_bug_raised  = isset($tc_cols['bug_raised']);
$bugs_in_test_cases = ($tc_has_bug_status && $tc_has_bug_raised);
$has_bugs_table     = !empty($bug_cols);

// Column detection — Projects & Clients
$proj_by_col = isset($proj_cols['created_by']) ? 'created_by' : (isset($proj_cols['created_by_id']) ? 'created_by_id' : null);
$cli_by_col  = isset($cli_cols['created_by']) ? 'created_by' : (isset($cli_cols['created_by_id']) ? 'created_by_id' : null);

// Column detection — Test Cases
$tc_created  = isset($tc_cols['created_by']) ? 'created_by' : (isset($tc_cols['created_by_id']) ? 'created_by_id' : null);
$tc_executed = null;
if (isset($tc_cols['executed_by_id']))      $tc_executed = 'executed_by_id';
elseif (isset($tc_cols['executed_by']))      $tc_executed = 'executed_by';
elseif (isset($tc_cols['run_by']))           $tc_executed = 'run_by';

$tc_is_exec    = isset($tc_cols['is_executed']);
$tc_has_status = isset($tc_cols['status']);

// Timestamp columns
$proj_ts      = snap_detect_ts_col($proj_cols);
$cli_ts       = snap_detect_ts_col($cli_cols);
$tc_ts        = snap_detect_ts_col($tc_cols);
$tc_exec_ts   = snap_detect_exec_ts_col($tc_cols);

// Today filter clauses — ONLY for activity metrics
$proj_sf     = snap_today_filter($proj_ts);
$cli_sf      = snap_today_filter($cli_ts);
$tc_add_sf   = snap_today_filter($tc_ts);
$tc_exec_sf  = snap_today_filter($tc_exec_ts ?: $tc_ts);

// ★★★ BUG STATUS: NO DATE FILTER ★★★
// Bug status is CURRENT STATE — shows how many bugs are in each status RIGHT NOW.
// We do NOT filter by date because:
//   - executed_on = when TC was executed, NOT when bug_status changed
//   - created_at = when bug was reported, NOT when status changed
//   - There is no "status_changed_at" column
// So bug counts = current state snapshot.

// Bugs table column detection (if separate bugs table exists)
$bug_rep_col   = null;
$bug_res_col   = null;
$bug_close_col = null;

if ($has_bugs_table) {
    $bug_rep_col   = isset($bug_cols['reported_by']) ? 'reported_by' : (isset($bug_cols['reported_by_id']) ? 'reported_by_id' : (isset($bug_cols['created_by']) ? 'created_by' : null));
    $bug_res_col   = isset($bug_cols['resolved_by']) ? 'resolved_by' : (isset($bug_cols['resolved_by_id']) ? 'resolved_by_id' : (isset($bug_cols['assigned_to']) ? 'assigned_to' : null));
    $bug_close_col = isset($bug_cols['closed_by']) ? 'closed_by' : (isset($bug_cols['closed_by_id']) ? 'closed_by_id' : $bug_res_col);
}

// ═══════════════════════════════════════════════════════════════
// GET ACTIVE USERS
// ═══════════════════════════════════════════════════════════════
$users = [];
$q_users = $conn->query("SELECT id, name, role FROM users WHERE status = 'active' ORDER BY role, name");
if ($q_users) {
    while ($u = $q_users->fetch_assoc()) $users[] = $u;
}

// ═══════════════════════════════════════════════════════════════
// DELETE EXISTING SNAPSHOT FOR TODAY (allow re-save)
// ═══════════════════════════════════════════════════════════════
$safe_today = $conn->real_escape_string($today);
$conn->query("DELETE FROM activity_history WHERE snapshot_date = '$safe_today'");

// ═══════════════════════════════════════════════════════════════
// COLLECT AND INSERT DATA FOR EACH USER
// ═══════════════════════════════════════════════════════════════
$insert_sql = "INSERT INTO activity_history 
    (snapshot_date, user_id, user_name, user_role, 
     projects_added, clients_added, test_cases_executed, bugs_reported, bugs_resolved, test_cases_added,
     bugs_open, bugs_in_progress, bugs_closed, bugs_reopened) 
    VALUES ";

$values = [];

foreach ($users as $u) {
    $uid = (int)$u['id'];

    $projects_added      = 0;
    $clients_added       = 0;
    $test_cases_executed = 0;
    $bugs_reported       = 0;
    $bugs_resolved       = 0;
    $test_cases_added    = 0;
    $bugs_open           = 0;
    $bugs_in_progress    = 0;
    $bugs_closed         = 0;
    $bugs_reopened       = 0;

    // ── PROJECTS ADDED (today only) ──
    if ($proj_by_col) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM projects WHERE `$proj_by_col` = $uid $proj_sf");
        if ($r) $projects_added = (int)$r->fetch_assoc()['c'];
    }

    // ── CLIENTS ADDED (today only) ──
    if ($cli_by_col) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM clients WHERE `$cli_by_col` = $uid $cli_sf");
        if ($r) $clients_added = (int)$r->fetch_assoc()['c'];
    }

    // ── TEST CASES ADDED (today only) ──
    if ($tc_created) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$tc_created` = $uid $tc_add_sf");
        if ($r) $test_cases_added = (int)$r->fetch_assoc()['c'];
    }

    // ── TEST CASES EXECUTED (today only) ──
    if ($tc_is_exec && $tc_executed) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$tc_executed` = $uid AND is_executed = 1 $tc_exec_sf");
        if ($r) $test_cases_executed = (int)$r->fetch_assoc()['c'];
    } elseif ($tc_is_exec && isset($tc_cols['assigned_to'])) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE assigned_to = $uid AND is_executed = 1 $tc_exec_sf");
        if ($r) $test_cases_executed = (int)$r->fetch_assoc()['c'];
    } elseif ($tc_has_status && $tc_executed) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$tc_executed` = $uid AND status IN ('Pass','Fail') $tc_exec_sf");
        if ($r) $test_cases_executed = (int)$r->fetch_assoc()['c'];
    }

    // ── BUGS REPORTED / FILED (today only) ──
    if ($bugs_in_test_cases && $tc_has_bug_raised && $tc_executed) {
        $exec_col = $tc_executed ?: 'executed_by_id';
        $bug_ts_col = $tc_exec_ts ?: $tc_ts;
        $bug_date_sf = "";
        if ($bug_ts_col) {
            $bug_date_sf = snap_today_filter($bug_ts_col);
        }
        $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE bug_raised = 1 AND `$exec_col` = $uid $bug_date_sf");
        if ($r) $bugs_reported = (int)$r->fetch_assoc()['c'];
    } elseif ($has_bugs_table && $bug_rep_col) {
        $bug_ts = snap_detect_ts_col($bug_cols);
        $bug_date_sf = "";
        if ($bug_ts) {
            $bug_date_sf = snap_today_filter($bug_ts);
        }
        $r = $conn->query("SELECT COUNT(*) AS c FROM bugs WHERE `$bug_rep_col` = $uid $bug_date_sf");
        if ($r) $bugs_reported = (int)$r->fetch_assoc()['c'];
    }

    // ═══════════════════════════════════════════════════════════
    // BUG STATUS COUNTS — CURRENT STATE (NO date filter!)
    // ═══════════════════════════════════════════════════════════

    $exec_col = $tc_executed ?: 'executed_by_id';

    // ── BUGS OPEN ──
    if ($bugs_in_test_cases) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE bug_raised = 1 AND bug_status = 'Open' AND `$exec_col` = $uid");
        if ($r) $bugs_open = (int)$r->fetch_assoc()['c'];
    } elseif ($has_bugs_table && $bug_rep_col) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM bugs WHERE `$bug_rep_col` = $uid AND status = 'Open'");
        if ($r) $bugs_open = (int)$r->fetch_assoc()['c'];
    }

    // ── BUGS IN PROGRESS ──
    if ($bugs_in_test_cases && isset($tc_cols['assigned_to'])) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE assigned_to = $uid AND bug_status = 'In Progress'");
        if ($r) $bugs_in_progress = (int)$r->fetch_assoc()['c'];
    } elseif ($has_bugs_table && $bug_res_col) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM bugs WHERE `$bug_res_col` = $uid AND status = 'In Progress'");
        if ($r) $bugs_in_progress = (int)$r->fetch_assoc()['c'];
    }

    // ── BUGS RESOLVED (Resolved ONLY, NOT Closed) ──
    if ($bugs_in_test_cases && isset($tc_cols['assigned_to'])) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE assigned_to = $uid AND bug_status = 'Resolved'");
        if ($r) $bugs_resolved = (int)$r->fetch_assoc()['c'];
    } elseif ($has_bugs_table && $bug_res_col) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM bugs WHERE `$bug_res_col` = $uid AND status = 'Resolved'");
        if ($r) $bugs_resolved = (int)$r->fetch_assoc()['c'];
    }

    // ── BUGS CLOSED ──
    if ($bugs_in_test_cases) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$exec_col` = $uid AND bug_status = 'Closed'");
        if ($r) $bugs_closed = (int)$r->fetch_assoc()['c'];
    } elseif ($has_bugs_table && $bug_close_col) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM bugs WHERE `$bug_close_col` = $uid AND status = 'Closed'");
        if ($r) $bugs_closed = (int)$r->fetch_assoc()['c'];
    }

    // ── BUGS REOPENED ──
    if ($bugs_in_test_cases) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM test_cases WHERE `$exec_col` = $uid AND bug_status = 'Reopened'");
        if ($r) $bugs_reopened = (int)$r->fetch_assoc()['c'];
    } elseif ($has_bugs_table && $bug_rep_col) {
        $r = $conn->query("SELECT COUNT(*) AS c FROM bugs WHERE `$bug_rep_col` = $uid AND status = 'Reopened'");
        if ($r) $bugs_reopened = (int)$r->fetch_assoc()['c'];
    }

    // Build VALUES clause
    $safe_name = $conn->real_escape_string($u['name']);
    $safe_role = $conn->real_escape_string($u['role']);
    $values[] = "('$safe_today', $uid, '$safe_name', '$safe_role', 
        $projects_added, $clients_added, $test_cases_executed, $bugs_reported, $bugs_resolved, $test_cases_added,
        $bugs_open, $bugs_in_progress, $bugs_closed, $bugs_reopened)";
}

// ═══════════════════════════════════════════════════════════════
// INSERT ALL DATA
// ═══════════════════════════════════════════════════════════════
if (!empty($values)) {
    $full_sql = $insert_sql . implode(", ", $values);
    $conn->query($full_sql);
}

// Output result
$inserted = count($values);
if (php_sapi_name() === 'cli') {
    echo "Snapshot saved for $today — $inserted users.\n";
} else {
    echo "Snapshot saved for $today — $inserted users.";
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
