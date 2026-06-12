<?php
session_start();
include '../config/db.php';

// ─────────────────────────────────────────────────────────────
// SET INDIAN TIME ZONE (IST - Asia/Kolkata, UTC+5:30)
// ─────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Kolkata');

// ─────────────────────────────────────────────────────────────
// AUTH CHECK
// ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

// ─────────────────────────────────────────────────────────────
// SMART SESSION DETECTION
// ─────────────────────────────────────────────────────────────
$user_id   = 0;
$user_name = 'User';
$user_role = '';

if (isset($_SESSION['user_id']))       { $user_id = (int)$_SESSION['user_id']; }
elseif (isset($_SESSION['id']))        { $user_id = (int)$_SESSION['id']; }
elseif (isset($_SESSION['user']['id'])){ $user_id = (int)$_SESSION['user']['id']; }
elseif (isset($_SESSION['uid']))      { $user_id = (int)$_SESSION['uid']; }
elseif (isset($_SESSION['userId']))   { $user_id = (int)$_SESSION['userId']; }

if ($user_id === 0 && isset($_SESSION['name'])) {
    $sess_name = $conn->real_escape_string($_SESSION['name']);
    $q_find = $conn->query("SELECT id, role FROM users WHERE name = '$sess_name' LIMIT 1");
    if ($q_find && $q_find->num_rows > 0) {
        $found = $q_find->fetch_assoc();
        $user_id = (int)$found['id'];
        $user_role = $found['role'];
    }
}
if ($user_id === 0 && isset($_SESSION['username'])) {
    $sess_user = $conn->real_escape_string($_SESSION['username']);
    $q_find2 = $conn->query("SELECT id, name, role FROM users WHERE username = '$sess_user' LIMIT 1");
    if ($q_find2 && $q_find2->num_rows > 0) {
        $found2 = $q_find2->fetch_assoc();
        $user_id = (int)$found2['id'];
        $user_name = htmlspecialchars($found2['name']);
        $user_role = $found2['role'];
    }
}

if ($user_name === 'User') $user_name = htmlspecialchars($_SESSION['name'] ?? 'User');
if ($user_role === '')     $user_role = $_SESSION['role'] ?? '';

// ─────────────────────────────────────────────────────────────
// SCHEMA DETECTION — Find actual column names dynamically
// ─────────────────────────────────────────────────────────────
function ud_get_cols(mysqli $db, string $table): array {
    $cols = [];
    $r = $db->query("DESCRIBE `$table`");
    if ($r) while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];
    return $cols;
}

function ud_pick(array $cols, array $candidates, ?string $fallback = null): ?string {
    $cols_lower = array_map('strtolower', $cols);
    foreach ($candidates as $c) {
        $idx = array_search(strtolower($c), $cols_lower, true);
        if ($idx !== false) return $cols[$idx];
    }
    return $fallback;
}

function ud_isTruthy($val): bool {
    if ($val === null || $val === false) return false;
    if (is_int($val) || is_float($val)) return $val != 0;
    if (is_string($val)) {
        $v = strtolower(trim($val));
        return !in_array($v, ['', '0', 'no', 'false', 'off', 'null'], true);
    }
    return !empty($val);
}

// ─── Detect projects table columns ───
$proj_cols = ud_get_cols($conn, 'projects');
$lead_col  = ud_pick($proj_cols, ['project_lead_id', 'lead_id', 'manager_id'], 'project_lead_id');
$qa_col    = ud_pick($proj_cols, ['qa_id', 'qa_lead_id', 'qa_lead', 'qa_manager_id', 'tester_lead_id'], 'qa_lead_id');
$proj_action = ud_pick($proj_cols, ['action', 'is_active', 'active'], 'action');
$proj_status = ud_pick($proj_cols, ['status'], 'status');
$proj_deadline = ud_pick($proj_cols, ['deadline_date', 'deadline', 'end_date', 'due_date'], 'deadline_date');

// ─── Detect test_cases table columns ───
$tc_cols      = ud_get_cols($conn, 'test_cases');
$tc_status_col = ud_pick($tc_cols, ['status', 'result', 'test_result', 'test_status'], 'status');
$tc_bug_raised = ud_pick($tc_cols, ['bug_raised', 'has_bug', 'bug_found', 'is_bug']);
$tc_bug_status = ud_pick($tc_cols, ['bug_status', 'bug_state', 'defect_status']);
$tc_is_auto    = ud_pick($tc_cols, ['is_automated', 'automated', 'is_auto', 'auto']);
$tc_title_col  = ud_pick($tc_cols, ['title', 'test_case', 'test_name', 'name', 'description', 'scenario']);
$tc_created_by = ud_pick($tc_cols, ['created_by', 'created_by_id', 'author_id', 'added_by']);
$tc_created_at = ud_pick($tc_cols, ['created_at', 'date_created', 'created_date', 'added_on']);
$tc_exec_by    = ud_pick($tc_cols, ['executed_by', 'executed_by_id', 'tester_id', 'tester']);
$tc_exec_on    = ud_pick($tc_cols, ['executed_on', 'execution_date', 'run_date', 'tested_on'], 'executed_on');

// ─── Detect requirements table columns ───
$req_cols     = ud_get_cols($conn, 'requirements');
$req_proj_fk  = ud_pick($req_cols, ['project_id', 'proj_id', 'project'], 'project_id');
$req_name_col = ud_pick($req_cols, ['title', 'name', 'requirement_name', 'req_name', 'module_name', 'module']);
$req_priority = ud_pick($req_cols, ['priority']);
$req_is_dev   = ud_pick($req_cols, ['is_developed', 'developed']);
$req_is_test  = ud_pick($req_cols, ['is_tested', 'tested']);
$req_is_del   = ud_pick($req_cols, ['is_delivered', 'delivered']);
$req_created_by = ud_pick($req_cols, ['created_by', 'created_by_id', 'author_id', 'added_by']);
$req_created_at = ud_pick($req_cols, ['created_at', 'date_created', 'created_date', 'added_on']);

// ─────────────────────────────────────────────────────────────
// MULTI-STRATEGY: Find ALL projects where user is assigned
// ─────────────────────────────────────────────────────────────
$myProjIdSet = [];

// STRATEGY 0: Projects table — user is project_lead or qa_lead
$sp0 = $conn->query("SELECT DISTINCT id FROM projects WHERE `$lead_col` = $user_id OR `$qa_col` = $user_id");
if ($sp0) while ($r = $sp0->fetch_assoc()) $myProjIdSet[(int)$r['id']] = true;

// STRATEGY 1: Known junction tables
$knownJunctions = [
    ['table' => 'project_frontend_devs', 'user_col' => 'user_id', 'proj_col' => 'project_id'],
    ['table' => 'project_backend_devs',  'user_col' => 'user_id', 'proj_col' => 'project_id'],
    ['table' => 'project_qa_team',       'user_col' => 'user_id', 'proj_col' => 'project_id'],
];
foreach ($knownJunctions as $jt) {
    $chk = $conn->query("SHOW TABLES LIKE '{$jt['table']}'");
    if (!$chk || $chk->num_rows == 0) continue;
    $jRes = $conn->query("SELECT DISTINCT `{$jt['proj_col']}` AS pid FROM `{$jt['table']}` WHERE `{$jt['user_col']}` = $user_id");
    if ($jRes) while ($r = $jRes->fetch_assoc()) $myProjIdSet[(int)$r['pid']] = true;
}

// STRATEGY 2: Generic junction tables
$genericJunctions = ['project_members', 'project_users', 'project_team', 'team_members', 'project_assignments', 'project_developers', 'project_qa'];
foreach ($genericJunctions as $jtName) {
    $chk = $conn->query("SHOW TABLES LIKE '$jtName'");
    if (!$chk || $chk->num_rows == 0) continue;
    $jtCols = ud_get_cols($conn, $jtName);
    $jtProj = ud_pick($jtCols, ['project_id', 'proj_id', 'project'], 'project_id');
    $jtUser = ud_pick($jtCols, ['user_id', 'member_id', 'users_id', 'uid'], 'user_id');
    if (!$jtProj || !$jtUser) continue;
    $jRes = $conn->query("SELECT DISTINCT `$jtProj` AS pid FROM `$jtName` WHERE `$jtUser` = $user_id");
    if ($jRes) while ($r = $jRes->fetch_assoc()) $myProjIdSet[(int)$r['pid']] = true;
}

// STRATEGY 3: Dynamic junction table scan
$allTablesRes = $conn->query("SHOW TABLES");
$alreadyChecked = array_flip(array_merge(['project_frontend_devs','project_backend_devs','project_qa_team'], $genericJunctions));
if ($allTablesRes) while ($tRow = $allTablesRes->fetch_array()) {
    $tName = $tRow[0];
    if (isset($alreadyChecked[$tName])) continue;
    if (in_array($tName, ['projects', 'users', 'clients', 'requirements', 'test_cases', 'test_plans', 'technologies', 'testing_types', 'migrations', 'sessions', 'team_edit_requests'])) continue;
    $tCols = ud_get_cols($conn, $tName);
    $tProj = ud_pick($tCols, ['project_id', 'proj_id', 'project']);
    $tUser = ud_pick($tCols, ['user_id', 'member_id', 'users_id', 'uid']);
    if (!$tProj || !$tUser) continue;
    $jRes = $conn->query("SELECT DISTINCT `$tProj` AS pid FROM `$tName` WHERE `$tUser` = $user_id");
    if ($jRes) while ($r = $jRes->fetch_assoc()) $myProjIdSet[(int)$r['pid']] = true;
}

// Build final comma-separated ID list and WHERE clause
$myProjIds = !empty($myProjIdSet) ? implode(',', array_keys($myProjIdSet)) : '0';
$myProjWhere  = "id IN ($myProjIds)";
$myProjWhereP = "p.id IN ($myProjIds)";
$myProjInIds  = "IN ($myProjIds)";

// ─────────────────────────────────────────────────────────────
// 1. MY PROJECTS — Active / Inactive counts (same as admin)
// ─────────────────────────────────────────────────────────────
$q_proj = $conn->query("
    SELECT
       COUNT(id)                                  AS total_count,
       SUM(`$proj_action` = 'active')             AS active_count,
       SUM(`$proj_action` != 'active')            AS inactive_count
    FROM projects
    WHERE $myProjWhere
");
$proj = $q_proj ? $q_proj->fetch_assoc() : ['total_count'=>0,'active_count'=>0,'inactive_count'=>0];

$total_my_projects = (int)$proj['total_count'];
$active_projects   = (int)($proj['active_count'] ?? 0);
$inactive_projects = (int)($proj['inactive_count'] ?? 0);

// ─────────────────────────────────────────────────────────────
// 2. MY CLIENTS
// ─────────────────────────────────────────────────────────────
$q_cli = $conn->query("
    SELECT COUNT(DISTINCT client_id) AS client_count
    FROM projects
    WHERE $myProjWhere
");
$cli = $q_cli ? $q_cli->fetch_assoc() : ['client_count'=>0];
$my_clients = (int)$cli['client_count'];

// ─────────────────────────────────────────────────────────────
// 3. UPCOMING DEADLINES LIST
// ─────────────────────────────────────────────────────────────
$deadlines = [];
if ($proj_deadline) {
    $q_dead = $conn->query("
       SELECT p.name AS project_name, c.name AS client_name, p.`$proj_deadline` AS deadline_date,
              p.`$proj_status` AS project_status
       FROM projects p
       JOIN clients c ON c.id = p.client_id
       WHERE $myProjWhereP AND p.`$proj_action` = 'active' AND p.`$proj_status` != 'Completed'
         AND p.`$proj_deadline` >= CURDATE() AND p.`$proj_deadline` IS NOT NULL
       ORDER BY p.`$proj_deadline` ASC LIMIT 5
    ");
    if ($q_dead) while ($row = $q_dead->fetch_assoc()) $deadlines[] = $row;
}

// ─────────────────────────────────────────────────────────────
// 4. OVERDUE PROJECTS LIST
// ─────────────────────────────────────────────────────────────
$overdue = [];
if ($proj_deadline) {
    $q_over_list = $conn->query("
       SELECT p.name AS project_name, c.name AS client_name, p.`$proj_deadline` AS deadline_date,
              p.`$proj_status` AS project_status,
              DATEDIFF(CURDATE(), p.`$proj_deadline`) AS days_overdue
       FROM projects p
       JOIN clients c ON c.id = p.client_id
       WHERE $myProjWhereP AND p.`$proj_action` = 'active' AND p.`$proj_status` != 'Completed'
         AND p.`$proj_deadline` < CURDATE() AND p.`$proj_deadline` IS NOT NULL
       ORDER BY days_overdue DESC
    ");
    if ($q_over_list) while ($row = $q_over_list->fetch_assoc()) $overdue[] = $row;
}

// ─────────────────────────────────────────────────────────────
// 5. RECENT ACTIVITY — Only the LOGGED-IN user's activity
//    AUTO-CLEAR: Only show activity from current week (since last Monday)
// ─────────────────────────────────────────────────────────────
$recent_activity = [];

// Calculate last Monday 00:00:00 IST — activity older than this is hidden
$monday_sql = "DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)";

// ── 5a. Test cases CREATED or EXECUTED by this user ──
if ($total_my_projects > 0) {
    $tc_act_sel = "tc.id, tc.project_id, tc.`$tc_status_col` AS status, p.name AS project_name";
    if ($tc_title_col)  $tc_act_sel .= ", tc.`$tc_title_col` AS tc_title";
    else                $tc_act_sel .= ", '' AS tc_title";
    // Pick the "activity date" column and also create a separate alias for WHERE filter
    $tc_date_col_for_where = null;
    if ($tc_exec_on) {
        $tc_act_sel .= ", tc.`$tc_exec_on` AS activity_date";
        $tc_date_col_for_where = "tc.`$tc_exec_on`";
    } elseif ($tc_created_at) {
        $tc_act_sel .= ", tc.`$tc_created_at` AS activity_date";
        $tc_date_col_for_where = "tc.`$tc_created_at`";
    } else {
        $tc_act_sel .= ", NULL AS activity_date";
        $tc_date_col_for_where = null;
    }

    // Build WHERE for user's own test cases (created_by OR executed_by = this user)
    $tcUserWhereParts = [];
    if ($tc_created_by) $tcUserWhereParts[] = "tc.`$tc_created_by` = $user_id";
    if ($tc_exec_by)    $tcUserWhereParts[] = "tc.`$tc_exec_by` = $user_id";
    $tcUserWhere = !empty($tcUserWhereParts) ? '(' . implode(' OR ', $tcUserWhereParts) . ')' : '1=0';

    // AUTO-CLEAR: Only fetch activity from this week (since last Monday)
    $tcWeekFilter = $tc_date_col_for_where
        ? "AND DATE($tc_date_col_for_where) >= $monday_sql"
        : '';

    $q_tc_act = $conn->query("
        SELECT $tc_act_sel
        FROM test_cases tc
        JOIN projects p ON tc.project_id = p.id
        WHERE tc.project_id $myProjInIds
          AND p.`$proj_action` = 'active'
          AND $tcUserWhere
          $tcWeekFilter
        ORDER BY " . ($tc_exec_on ? "tc.`$tc_exec_on`" : ($tc_created_at ? "tc.`$tc_created_at`" : "tc.id")) . " DESC
        LIMIT 10
    ");
    if ($q_tc_act) while ($row = $q_tc_act->fetch_assoc()) {
        $act_date = $row['activity_date'] ?? null;
        $status   = $row['status'];
        $title    = $row['tc_title'] ?: ('TC-' . $row['id']);
        $project  = $row['project_name'];

        if ($status === 'Pass') {
            $desc = "Test case \"$title\" passed in $project";
            $icon_type = 'pass';
            $act_title = 'Test Passed';
        } elseif ($status === 'Fail') {
            $desc = "Test case \"$title\" failed in $project";
            $icon_type = 'fail';
            $act_title = 'Test Failed';
        } else {
            $desc = "Test case \"$title\" pending in $project";
            $icon_type = 'nt';
            $act_title = 'Test Pending';
        }

        $recent_activity[] = [
            'type'      => 'test_case',
            'icon_type' => $icon_type,
            'title'     => $act_title,
            'desc'      => $desc,
            'date'      => $act_date,
            'sort_key'  => $act_date ?: '1970-01-01',
        ];
    }

    // ── 5b. Requirements CREATED by this user ──
    if ($req_proj_fk && $req_name_col) {
        $req_act_sel = "r.id, r.`$req_name_col` AS req_title";
        if ($req_priority)  $req_act_sel .= ", r.`$req_priority` AS priority";
        else                $req_act_sel .= ", '' AS priority";
        $req_act_sel .= ", p.name AS project_name";
        // Pick the "activity date" column and also create a separate alias for WHERE filter
        $req_date_col_for_where = null;
        if ($req_created_at) {
            $req_act_sel .= ", r.`$req_created_at` AS activity_date";
            $req_date_col_for_where = "r.`$req_created_at`";
        } else {
            $req_act_sel .= ", NULL AS activity_date";
            $req_date_col_for_where = null;
        }

        $reqUserWhere = $req_created_by ? "r.`$req_created_by` = $user_id" : '1=0';

        // AUTO-CLEAR: Only fetch activity from this week (since last Monday)
        $reqWeekFilter = $req_date_col_for_where
            ? "AND DATE($req_date_col_for_where) >= $monday_sql"
            : '';

        $q_req_act = $conn->query("
            SELECT $req_act_sel
            FROM requirements r
            JOIN projects p ON r.`$req_proj_fk` = p.id
            WHERE r.`$req_proj_fk` $myProjInIds
              AND $reqUserWhere
              $reqWeekFilter
            ORDER BY " . ($req_created_at ? "r.`$req_created_at`" : "r.id") . " DESC
            LIMIT 8
        ");
        if ($q_req_act) while ($row = $q_req_act->fetch_assoc()) {
            $title   = $row['req_title'] ?: ('Req-' . $row['id']);
            $project = $row['project_name'];
            $priority = $row['priority'] ?? '';
            $desc = "Requirement \"$title\" added to $project";
            if ($priority) $desc .= " ($priority priority)";

            $recent_activity[] = [
                'type'      => 'requirement',
                'icon_type' => 'requirement',
                'title'     => 'Requirement Added',
                'desc'      => $desc,
                'date'      => $row['activity_date'],
                'sort_key'  => $row['activity_date'] ?: '1970-01-01',
            ];
        }
    }
}

// Sort all activity by date DESC
usort($recent_activity, function($a, $b) {
    return strcmp($b['sort_key'], $a['sort_key']);
});
$recent_activity = array_slice(array_values($recent_activity), 0, 10);

$conn->close();

// ─────────────────────────────────────────────────────────────
// HELPER FUNCTIONS (same as admin_dash.php)
// ─────────────────────────────────────────────────────────────
function fmt_date(string $date): string {
    $tz = new DateTimeZone('Asia/Kolkata');
    return (new DateTime($date, $tz))->format('M d, Y');
}
function overdue_label(int $days): string {
    if ($days >= 365) { $yrs = floor($days / 365); return $yrs . ' yr' . ($yrs > 1 ? 's' : ''); }
    if ($days >= 30) { $months = floor($days / 30); $rem = $days % 30; $label = $months . ' mo'; if ($rem) $label .= ' ' . $rem . 'd'; return $label; }
    return $days . ' day' . ($days !== 1 ? 's' : '');
}
function overdue_severity(int $days): string {
    if ($days >= 90) return 'sev-critical';
    if ($days >= 30) return 'sev-high';
    if ($days >= 7)  return 'sev-medium';
    return 'sev-low';
}
function overdue_label_text(int $days): string {
    if ($days >= 90) return 'Critical';
    if ($days >= 30) return 'High';
    if ($days >= 7)  return 'Medium';
    return 'Low';
}
function deadline_class(string $date): string {
    $tz = new DateTimeZone('Asia/Kolkata');
    $diff = (new DateTime($date, $tz))->diff(new DateTime('now', $tz))->days;
    if ($diff <= 7)  return 'date-red';
    if ($diff <= 30) return 'date-blue';
    return 'date-green';
}
function time_ago($datetime): string {
    if (!$datetime) return '';
    $tz  = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $ago = new DateTime($datetime, $tz);
    $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . ' yr' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' mo' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hr' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}
function fmt_datetime_ist($datetime): string {
    if (!$datetime) return '';
    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = new DateTime($datetime, $tz);
    return $dt->format('d M Y, h:i A');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TestiFy - Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<link rel="icon" type="image/jpg" href="../icon/testify.jpg" />
<link rel="stylesheet" href="../css/ad_dash.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  /* ═══ User-specific overrides ═══ */
  .nb-user  { font-size:20px; color:var(--text-muted); font-weight:600; white-space:nowrap; display:flex; align-items:center; gap:8px; }
  .nb-role  { font-size:15px; font-weight:700; color:#1565c0; background:#e3f2fd; padding:2px 8px; border-radius:6px; text-transform:capitalize; letter-spacing:.5px; white-space:nowrap; }

  .sidebar .sb-link.active, .sidebar .sb-home.active {
      background: rgba(58, 123, 213, 0.12); color: #3a7bd5; font-weight: 700;
  }
  .sidebar .sb-link.active svg, .sidebar .sb-home.active svg { color: #3a7bd5; }

  /* ═══ Deadline date color classes ═══ */
  .date-red   { color: #e74c3c !important; }
  .date-blue  { color: #3a7bd5 !important; }
  .date-green { color: #00b894 !important; }

  /* ═══ Overdue severity colors ═══ */
  .sev-low-clr     { color: #00b894; }
  .sev-medium-clr  { color: #f39c12; }
  .sev-high-clr    { color: #e74c3c; }
  .sev-critical-clr{ color: #c0392b; font-weight:700; }
  .sev-pill { display:inline-block; font-size:11px; font-weight:600; padding:2px 10px; border-radius:12px; text-transform:uppercase; letter-spacing:.3px; }
  .sev-low      { background:#e8f8f5; color:#00b894; }
  .sev-medium   { background:#fef9e7; color:#f39c12; }
  .sev-high     { background:#fdedec; color:#e74c3c; }
  .sev-critical { background:#f5b7b1; color:#922b21; }
  .bar-low      { background:#00b894; }
  .bar-medium   { background:#f39c12; }
  .bar-high     { background:#e74c3c; }
  .bar-critical { background:#922b21; }

  .ov-badge { background: #ff7675; color: white; font-size: 12px; padding: 2px 8px; border-radius: 10px; }
  .overdue-days-label { font-size: 11px; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.5px; }

  /* ═══ Activity feed ═══ */
  .activity-item {
      display: flex; gap: 12px; margin-bottom: 14px; font-size: 14px; align-items: flex-start;
  }
  .activity-icon {
      width: 34px; height: 34px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      color: #fff; flex-shrink: 0; font-size: 14px;
  }
  .act-pass        { background: #548235; }
  .act-fail        { background: #C00000; }
  .act-nt          { background: #A5A5A5; }
  .act-requirement { background: #4472C4; }
  .activity-content h4 { margin: 0; font-size: 13px; color: #2d3436; }
  .activity-content p  { margin: 2px 0 0; font-size: 12px; color: #636e72; }
  .activity-time  { font-size: 11px; color: #b2bec3; margin-top: 2px; }
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
      <?php if($user_role): ?>
        <span class="nb-role"><?= htmlspecialchars(ucfirst($user_role)) ?></span>
      <?php endif; ?>
    </div>
    <a href="../logout.php" class="blgout">Logout</a>
  </div>
</nav>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR (same structure as admin) -->
<aside class="sidebar" id="sidebar">
  <a href="../dash/user_dash.php" class="sb-home active">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    Home
  </a>
  <div class="sb-section">Pages</div>
  <a href="../user_page/u_client.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
    Clients
  </a>
  <a href="../user_page/u_project.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    Projects
  </a>
  <a href="../user_page/u_requirement.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
    Requirements
  </a>
  <a href="../user_page/u_test_plans.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
    Test Plans
  </a>
  <a href="../user_page/u_test_cases.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
    Test Cases
  </a>
  <a href="../user_page/u_project_reports.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
  </a>
</aside>

<!-- MAIN CONTENT -->
<div class="page-wrap" id="pageWrap">
  <div class="main">
    <div class="page-title">
      <h1>Dashboard</h1>
      <span class="badge-overview">Overview</span>
    </div>

    <!-- ═══ Metric Cards (same as admin: 3 cards) ═══ -->
    <div class="metric-grid">
      <div class="metric-card mc-blue">
        <div class="metric-info">
          <label>Active Projects</label>
          <div class="metric-value"><?= $active_projects ?></div>
          <div class="metric-sub"><strong>Active <?= $active_projects ?></strong>, Inactive <?= $inactive_projects ?></div>
          <div class="progress-bar-wrap"><div class="progress-bar" style="width:<?= $total_my_projects ? round($active_projects / $total_my_projects * 100) : 0 ?>%;"></div></div>
        </div>
        <div class="metric-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></div>
      </div>
      <div class="metric-card mc-green">
        <div class="metric-info">
          <label>My Clients</label>
          <div class="metric-value"><?= $my_clients ?></div>
          <div class="metric-sub"><strong>Active <?= $my_clients ?></strong></div>
          <div class="progress-bar-wrap"><div class="progress-bar" style="width:100%;"></div></div>
        </div>
        <div class="metric-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></div>
      </div>
      <div class="metric-card mc-purple">
        <div class="metric-info">
          <label>My Projects</label>
          <div class="metric-value"><?= $total_my_projects ?></div>
          <div class="metric-sub"><strong>Total <?= $total_my_projects ?></strong></div>
          <div class="progress-bar-wrap"><div class="progress-bar" style="width:<?= $total_my_projects ? round($active_projects / $total_my_projects * 100) : 0 ?>%;"></div></div>
        </div>
        <div class="metric-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
      </div>
    </div>

    <!-- ═══ Chart + Deadlines (same as admin: 2-col grid) ═══ -->
    <div class="bottom-grid">
      <div class="panel panel-blue">
        <h2>Project Status Overview</h2>
        <div class="chart-wrap">
          <div class="chart-canvas-wrap"><canvas id="statusChart"></canvas></div>
          <div class="chart-legend">
            <span><span class="legend-dot" style="background:#3a7bd5;"></span>Active (<?= $active_projects ?>)</span>
            <span><span class="legend-dot" style="background:#b2bec3;"></span>Inactive (<?= $inactive_projects ?>)</span>
          </div>
        </div>
      </div>
      <div class="panel panel-green">
        <h2>Upcoming Deadlines</h2>
        <div class="deadline-list">
          <?php if (empty($deadlines)): ?>
            <div style="color:var(--text-muted);padding:10px;text-align:center;font-size:14px;">No upcoming deadlines.</div>
          <?php else: ?>
            <?php foreach ($deadlines as $d): ?>
            <div class="deadline-item">
              <div>
                <div class="deadline-project"><?= htmlspecialchars($d['project_name']) ?></div>
                <div class="deadline-client">Client: <?= htmlspecialchars($d['client_name']) ?></div>
              </div>
              <div class="deadline-date <?= deadline_class($d['deadline_date']) ?>"><?= fmt_date($d['deadline_date']) ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ═══ Overdue + Activity — Side by Side ═══ -->
    <div class="bottom-grid">
      <!-- Overdue Projects -->
      <div class="panel panel-red">
        <h2 style="display:flex;align-items:center;gap:8px;">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;color:#e74c3c;flex-shrink:0;">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          Overdue Projects
          <?php if (!empty($overdue)): ?>
            <span class="ov-badge"><?= count($overdue) ?> overdue</span>
          <?php endif; ?>
        </h2>
        <?php if (empty($overdue)): ?>
          <div class="overdue-empty">
            <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            No overdue projects! All deadlines are on track.
          </div>
        <?php else: ?>
          <div class="overdue-list">
            <?php foreach ($overdue as $ov):
              $days   = (int)$ov['days_overdue'];
              $sevCls = overdue_severity($days);
              $sevTxt = overdue_label_text($days);
              $timeStr= overdue_label($days);
              $clrMap = ['sev-low'=>'sev-low-clr','sev-medium'=>'sev-medium-clr','sev-high'=>'sev-high-clr','sev-critical'=>'sev-critical-clr'];
              $dayClr = $clrMap[$sevCls] ?? 'sev-high-clr';
              $barPct = min(100, round($days / 180 * 100));
              $barCls = str_replace('sev-','bar-',$sevCls);
            ?>
            <div class="overdue-item">
              <div class="overdue-left">
                <div class="overdue-project"><?= htmlspecialchars($ov['project_name']) ?></div>
                <div class="overdue-meta">
                  <span class="overdue-client">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:11px;height:11px;display:inline;vertical-align:middle;margin-right:2px;"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                    <?= htmlspecialchars($ov['client_name']) ?>
                  </span>
                  <span class="overdue-deadline">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:11px;height:11px;display:inline;vertical-align:middle;margin-right:2px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Deadline: <?= fmt_date($ov['deadline_date']) ?>
                  </span>
                </div>
                <div class="overdue-bar-wrap">
                  <div class="overdue-bar <?= $barCls ?>" style="width:<?= $barPct ?>%;"></div>
                </div>
              </div>
              <div class="overdue-right">
                <div class="overdue-timer">
                  <div class="overdue-days <?= $dayClr ?>">+<?= $timeStr ?></div>
                  <div class="overdue-days-label">overdue</div>
                </div>
                <span class="sev-pill <?= $sevCls ?>"><?= $sevTxt ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Recent Activity -->
      <div class="panel">
        <h2 style="display:flex;align-items:center;gap:8px;">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;color:#3a7bd5;flex-shrink:0;">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
          </svg>
          Recent Activity
          <span style="font-size:12px;font-weight:400;color:#636e72;margin-left:auto;">This week &middot; Auto-clears every Monday</span>
        </h2>
        <div style="padding:16px 20px;">
          <?php if (empty($recent_activity)): ?>
            <div style="text-align:center;padding:30px;color:#636e72;font-size:14px;">No recent activity found.</div>
          <?php else: ?>
            <?php foreach ($recent_activity as $act): ?>
            <div class="activity-item">
              <div class="activity-icon act-<?= $act['icon_type'] ?>">
                <?php if ($act['icon_type'] === 'pass'): ?>
                  <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <?php elseif ($act['icon_type'] === 'fail'): ?>
                  <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <?php elseif ($act['icon_type'] === 'nt'): ?>
                  <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php elseif ($act['icon_type'] === 'requirement'): ?>
                  <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <?php endif; ?>
              </div>
              <div class="activity-content" style="flex:1;">
                <h4><?= htmlspecialchars($act['title']) ?></h4>
                <p><?= htmlspecialchars($act['desc']) ?></p>
                <?php if ($act['date']): ?>
                  <div class="activity-time"><?= time_ago($act['date']) ?> &middot; <?= fmt_datetime_ist($act['date']) ?> IST</div>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div><!-- end .main -->
</div>

<script>
  // ── Auto-scroll to top on page load ──
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  window.scrollTo(0, 0);

  function toggleSidebar(){
    const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay'), hm = document.getElementById('hamBtn');
    const isOpen = sb.classList.toggle('open');
    ov.classList.toggle('open', isOpen); hm.classList.toggle('open', isOpen);
    document.body.style.overflow = (isOpen && window.innerWidth <= 768) ? 'hidden' : '';
  }
  function closeSidebar(){
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sbOverlay').classList.remove('open');
    document.getElementById('hamBtn').classList.remove('open');
    document.body.style.overflow = '';
  }
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

  // ── Doughnut Chart (same as admin: Active vs Inactive) ──
  const ctx = document.getElementById('statusChart').getContext('2d');
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: ['Active', 'Inactive'],
      datasets: [{
        data: [<?= $active_projects ?>, <?= $inactive_projects ?>],
        backgroundColor: ['#3a7bd5', '#b2bec3'],
        borderWidth: 0,
        hoverOffset: 6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      cutout: '65%',
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#2d3436',
          titleFont: { family: 'Nunito' },
          bodyFont: { family: 'Nunito' },
          padding: 10,
          cornerRadius: 8
        }
      }
    }
  });
</script>
</body>
</html>
