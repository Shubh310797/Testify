<?php
// ── user_page/u_project_reports.php ─────────────────────────────
// USER VERSION: Only shows projects the user is assigned to
// Reports only available for assigned projects
date_default_timezone_set('Asia/Kolkata');

session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();

function sendJson($data) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════
//  SMART SESSION DETECTION
// ══════════════════════════════════════════════════════
$current_user_id = null;
$current_user_name = $_SESSION['name'] ?? '';
$current_user_role = $_SESSION['role'] ?? '';

$session_keys = ['user_id', 'id', 'user.id', 'uid', 'userId'];
foreach ($session_keys as $key) {
    if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
        $current_user_id = (int)$_SESSION[$key];
        break;
    }
}

$db_path = '../config/db.php';
if (!file_exists($db_path)) {
    die("<div style='background:#fee;padding:10px;border:1px solid red;'>DB config not found: $db_path</div>");
}
include $db_path;

if (!isset($conn) || $conn->connect_error) {
    $err = isset($conn) ? $conn->connect_error : 'Connection variable not set';
    die("<div style='background:#fee;padding:10px;border:1px solid red;'>DB Error: $err</div>");
}

// DB fallback for user ID
if (!$current_user_id && !empty($current_user_name)) {
    $lk = $conn->prepare("SELECT id, role FROM users WHERE name = ? OR username = ? LIMIT 1");
    $lk->bind_param('ss', $current_user_name, $current_user_name);
    $lk->execute();
    $lr = $lk->get_result()->fetch_assoc();
    $lk->close();
    if ($lr) {
        $current_user_id = (int)$lr['id'];
        if (empty($current_user_role)) $current_user_role = $lr['role'];
    }
}

if (!$current_user_id) {
    header('Location: ../login.php');
    exit;
}

// ══════════════════════════════════════════════════════
//  FETCH USER'S ASSIGNED PROJECTS
// ══════════════════════════════════════════════════════
$user_projects = [];
$up_stmt = $conn->prepare(
    "SELECT DISTINCT p.id, p.client_id, p.name
     FROM projects p
     LEFT JOIN project_frontend_devs pfd ON p.id = pfd.project_id
     LEFT JOIN project_backend_devs pbd ON p.id = pbd.project_id
     LEFT JOIN project_qa_team pqt ON p.id = pqt.project_id
     WHERE p.project_lead_id = ?
        OR p.qa_lead_id = ?
        OR pfd.user_id = ?
        OR pbd.user_id = ?
        OR pqt.user_id = ?
     ORDER BY p.name ASC"
);
$up_stmt->bind_param('iiiii', $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id);
$up_stmt->execute();
$up_res = $up_stmt->get_result();
while ($r = $up_res->fetch_assoc()) $user_projects[] = $r;
$up_stmt->close();

$user_project_ids = array_column($user_projects, 'id');
$user_project_ids_list = !empty($user_project_ids) ? implode(',', array_map('intval', $user_project_ids)) : '0';

// ══════════════════════════════════════════════════════
//  HELPER FUNCTIONS (Schema Scanner)
// ══════════════════════════════════════════════════════
function get_cols(mysqli $db, string $table): array {
    $cols = [];
    $r = $db->query("DESCRIBE `$table`");
    if ($r) while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];
    return $cols;
}

function pick(array $cols, array $candidates, ?string $fallback = null): ?string {
    foreach ($candidates as $c) if (in_array($c, $cols)) return $c;
    return $fallback;
}

// ══════════════════════════════════════════════════════
//  SHARED: Build Summary Data for a given project_id
// ══════════════════════════════════════════════════════
function buildSummaryData(mysqli $conn, int $project_id): ?array {

    $proj_cols = get_cols($conn, 'projects');
    $req_cols  = get_cols($conn, 'requirements');
    $tc_cols   = get_cols($conn, 'test_cases');

    $proj_lead_col    = pick($proj_cols, ['project_lead_id', 'lead_id', 'manager_id']);
    $proj_qa_lead_col = pick($proj_cols, ['qa_lead_id', 'qa_lead', 'qa_manager_id', 'tester_lead_id']);
    $req_name_col     = pick($req_cols,  ['title', 'name', 'requirement_name', 'req_name', 'module_name', 'module'], 'id');
    $req_proj_fk      = pick($req_cols,  ['project_id', 'proj_id', 'project']);
    $tc_req_fk        = pick($tc_cols,   ['requirement_id', 'req_id', 'requirements_id', 'module_id', 'feature_id']);
    $tc_exec_col      = pick($tc_cols,   ['executed_by', 'executed_by_id', 'tester_id', 'tester', 'run_by']);
    $tc_assign_col    = pick($tc_cols,   ['assigned_to', 'assigned_to_id', 'assignee_id', 'assignee']);
    $tc_exec_on_col   = pick($tc_cols,   ['executed_on', 'execution_date', 'run_date', 'tested_on', 'date_executed']);
    $tc_is_exec_col   = pick($tc_cols,   ['is_executed', 'executed', 'is_run', 'run']);
    $tc_bug_raised    = pick($tc_cols,   ['bug_raised', 'has_bug', 'bug_found', 'is_bug']);
    $tc_bug_status    = pick($tc_cols,   ['bug_status', 'bug_state', 'defect_status']);
    $tc_is_auto_col   = pick($tc_cols,   ['is_automated', 'automated', 'is_auto', 'auto']);
    $tc_status_col    = pick($tc_cols,   ['status', 'result', 'test_result', 'test_status'], 'status');
    $tc_title_col     = pick($tc_cols,   ['title', 'test_case', 'test_name', 'name', 'description', 'test_description', 'scenario']);

    $sql = "SELECT p.*, c.name as client_name";
    if ($proj_lead_col)    $sql .= ", u1.name as lead_name";
    if ($proj_qa_lead_col) $sql .= ", u2.name as qa_lead_name";
    $sql .= " FROM projects p LEFT JOIN clients c ON p.client_id = c.id ";
    if ($proj_lead_col)    $sql .= "LEFT JOIN users u1 ON p.$proj_lead_col = u1.id ";
    if ($proj_qa_lead_col) $sql .= "LEFT JOIN users u2 ON p.$proj_qa_lead_col = u2.id ";
    $sql .= "WHERE p.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$project) return null;

    $req_stats = ['Total' => 0, 'Developed' => 0, 'Tested' => 0, 'Delivered' => 0];
    $req_prio  = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0];
    if ($req_proj_fk) {
        $r_res = $conn->query("SELECT status, priority FROM requirements WHERE `$req_proj_fk` = $project_id");
        if ($r_res) while ($r = $r_res->fetch_assoc()) {
            $req_stats['Total']++;
            if (isset($req_stats[$r['status']])) $req_stats[$r['status']]++;
            if (isset($req_prio[$r['priority']]))  $req_prio[$r['priority']]++;
        }
    }

    $tc_stats = ['Total' => 0, 'Pass' => 0, 'Fail' => 0, 'Not tested' => 0, 'Automated' => 0, 'Manual' => 0];
    $auto_sel = $tc_is_auto_col ? "`$tc_is_auto_col`" : '0';
    $tc_res = $conn->query("SELECT tc.`$tc_status_col` AS status, $auto_sel AS is_auto FROM test_cases tc WHERE tc.project_id = $project_id");
    if ($tc_res) while ($t = $tc_res->fetch_assoc()) {
        $tc_stats['Total']++;
        if (isset($tc_stats[$t['status']])) $tc_stats[$t['status']]++;
        if ($t['is_auto']) $tc_stats['Automated']++; else $tc_stats['Manual']++;
    }

    $bug_stats = ['Open' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Closed' => 0];
    if ($tc_bug_raised && $tc_bug_status) {
        $b_res = $conn->query("SELECT tc.`$tc_bug_status` AS bug_status FROM test_cases tc WHERE tc.project_id = $project_id AND tc.`$tc_bug_raised` = 1");
        if ($b_res) while ($b = $b_res->fetch_assoc())
            if (isset($bug_stats[$b['bug_status']])) $bug_stats[$b['bug_status']]++;
    }
    $total_bugs      = array_sum($bug_stats);
    $resolution_rate = $total_bugs > 0 ? round(($bug_stats['Resolved'] + $bug_stats['Closed']) / $total_bugs * 100, 1) : 0;
    $req_completion  = $req_stats['Total'] > 0 ? round($req_stats['Developed'] / $req_stats['Total'] * 100) : 0;
    $coverage        = $req_stats['Total'] > 0 ? round($req_stats['Tested'] / $req_stats['Total'] * 100) : 0;

    $detailed_cases = [];
    $req_join = ''; $req_sel = "NULL AS req_name, NULL AS req_priority,";
    if ($tc_req_fk) {
        $req_join = "LEFT JOIN requirements r ON tc.`$tc_req_fk` = r.id";
        $req_sel  = "r.`$req_name_col` AS req_name, r.priority AS req_priority,";
    }
    $exec_join = $tc_exec_col   ? "LEFT JOIN users u_exec   ON tc.`$tc_exec_col`   = u_exec.id"   : '';
    $asgn_join = $tc_assign_col ? "LEFT JOIN users u_assign ON tc.`$tc_assign_col` = u_assign.id" : '';
    $exec_sel  = $tc_exec_col   ? 'u_exec.name   AS executed_name,' : "'' AS executed_name,";
    $asgn_sel  = $tc_assign_col ? 'u_assign.name AS assigned_name'  : "'' AS assigned_name";
    $title_sel = $tc_title_col  ? "tc.`$tc_title_col` AS tc_title," : "'' AS tc_title,";
    $exec_on   = $tc_exec_on_col ? "tc.`$tc_exec_on_col` AS executed_on," : "NULL AS executed_on,";
    $bug_r_sel = $tc_bug_raised  ? "tc.`$tc_bug_raised`  AS bug_raised,"  : "0 AS bug_raised,";
    $bug_s_sel = $tc_bug_status  ? "tc.`$tc_bug_status`  AS bug_status,"  : "'' AS bug_status,";
    $auto_s    = $tc_is_auto_col ? "tc.`$tc_is_auto_col` AS is_automated," : "0 AS is_automated,";
    $order_by  = $tc_req_fk     ? "r.`$req_name_col` ASC, tc.id ASC" : "tc.id ASC";

    $d_sql = "SELECT tc.id, tc.project_id, tc.`$tc_status_col` AS status,
                     $title_sel $exec_on $bug_r_sel $bug_s_sel $auto_s $req_sel $exec_sel $asgn_sel
              FROM test_cases tc $req_join $exec_join $asgn_join
              WHERE tc.project_id = $project_id ORDER BY $order_by";
    $d_res = $conn->query($d_sql);
    if ($d_res) while ($row = $d_res->fetch_assoc()) {
        $grp = ($row['req_name'] ?? '') ?: 'General';
        if (!isset($detailed_cases[$grp]))
            $detailed_cases[$grp] = ['name' => $grp, 'priority' => $row['req_priority'] ?? 'High', 'total' => 0, 'pass' => 0, 'fail' => 0, 'not_tested' => 0, 'cases' => []];
        $detailed_cases[$grp]['total']++;
        $s = $row['status'];
        if ($s === 'Pass') $detailed_cases[$grp]['pass']++;
        elseif ($s === 'Fail') $detailed_cases[$grp]['fail']++;
        else $detailed_cases[$grp]['not_tested']++;
        $detailed_cases[$grp]['cases'][] = $row;
    }

    return compact(
        'project', 'req_stats', 'req_prio', 'tc_stats', 'bug_stats',
        'total_bugs', 'resolution_rate', 'req_completion', 'coverage',
        'detailed_cases'
    );
}

// ══════════════════════════════════════════════════════
//  HELPER: Build lightweight summary for user's projects only
// ══════════════════════════════════════════════════════
function buildUserProjectsSummary(mysqli $conn, array $user_project_ids): array {
    if (empty($user_project_ids)) return [];

    $proj_cols = get_cols($conn, 'projects');
    $req_cols  = get_cols($conn, 'requirements');
    $tc_cols   = get_cols($conn, 'test_cases');

    $proj_lead_col    = pick($proj_cols, ['project_lead_id', 'lead_id', 'manager_id']);
    $proj_qa_lead_col = pick($proj_cols, ['qa_lead_id', 'qa_lead', 'qa_manager_id', 'tester_lead_id']);
    $active_col       = pick($proj_cols, ['action', 'is_active', 'active'], 'action');
    $req_proj_fk      = pick($req_cols,  ['project_id', 'proj_id', 'project']);
    $tc_status_col    = pick($tc_cols,   ['status', 'result', 'test_result', 'test_status'], 'status');
    $tc_bug_raised    = pick($tc_cols,   ['bug_raised', 'has_bug', 'bug_found', 'is_bug']);
    $tc_bug_status    = pick($tc_cols,   ['bug_status', 'bug_state', 'defect_status']);
    $tc_is_auto_col   = pick($tc_cols,   ['is_automated', 'automated', 'is_auto', 'auto']);

    $ids_list = implode(',', array_map('intval', $user_project_ids));

    $sql = "SELECT p.id, p.name, p.status, p.client_id, c.name as client_name";
    if ($proj_lead_col)    $sql .= ", u1.name as lead_name";
    if ($proj_qa_lead_col) $sql .= ", u2.name as qa_lead_name";
    if ($active_col)       $sql .= ", p.`$active_col` as is_active";
    $sql .= " FROM projects p LEFT JOIN clients c ON p.client_id = c.id";
    if ($proj_lead_col)    $sql .= " LEFT JOIN users u1 ON p.$proj_lead_col = u1.id";
    if ($proj_qa_lead_col) $sql .= " LEFT JOIN users u2 ON p.$proj_qa_lead_col = u2.id";
    $sql .= " WHERE p.id IN ($ids_list) ORDER BY p.name ASC";

    $res = $conn->query($sql);
    $projects = [];
    if ($res) while ($row = $res->fetch_assoc()) $projects[] = $row;

    $summary = [];
    foreach ($projects as $p) {
        $pid = $p['id'];

        $req_total = 0; $req_dev = 0; $req_tested = 0;
        if ($req_proj_fk) {
            $r_res = $conn->query("SELECT status FROM requirements WHERE `$req_proj_fk` = $pid");
            if ($r_res) while ($r = $r_res->fetch_assoc()) {
                $req_total++;
                if ($r['status'] === 'Developed') $req_dev++;
                if ($r['status'] === 'Tested') $req_tested++;
            }
        }

        $tc_total = 0; $tc_pass = 0; $tc_fail = 0; $tc_nt = 0;
        $tc_res = $conn->query("SELECT `$tc_status_col` AS status FROM test_cases WHERE project_id = $pid");
        if ($tc_res) while ($t = $tc_res->fetch_assoc()) {
            $tc_total++;
            if ($t['status'] === 'Pass') $tc_pass++;
            elseif ($t['status'] === 'Fail') $tc_fail++;
            else $tc_nt++;
        }

        $bug_open = 0; $bug_inprog = 0; $bug_resolved = 0; $bug_closed = 0;
        if ($tc_bug_raised && $tc_bug_status) {
            $b_res = $conn->query("SELECT `$tc_bug_status` AS bs FROM test_cases WHERE project_id = $pid AND `$tc_bug_raised` = 1");
            if ($b_res) while ($b = $b_res->fetch_assoc()) {
                $bs = $b['bs'];
                if ($bs === 'Open') $bug_open++;
                elseif ($bs === 'In Progress') $bug_inprog++;
                elseif ($bs === 'Resolved') $bug_resolved++;
                elseif ($bs === 'Closed') $bug_closed++;
            }
        }

        $total_bugs = $bug_open + $bug_inprog + $bug_resolved + $bug_closed;
        $resolution_rate = $total_bugs > 0 ? round(($bug_resolved + $bug_closed) / $total_bugs * 100, 1) : 0;
        $coverage = $req_total > 0 ? round($req_tested / $req_total * 100) : 0;
        $pass_rate = $tc_total > 0 ? round($tc_pass / $tc_total * 100, 1) : 0;

        $is_active = true;
        if (isset($p['is_active'])) {
            $is_active = ($p['is_active'] == 1 || $p['is_active'] === 'active' || $p['is_active'] === '1');
        }

        $summary[] = [
            'id'              => $pid,
            'name'            => $p['name'],
            'client_id'       => $p['client_id'] ?? 0,
            'client_name'     => $p['client_name'] ?? 'N/A',
            'status'          => $p['status'] ?? 'N/A',
            'is_active'       => $is_active,
            'lead_name'       => $p['lead_name'] ?? 'N/A',
            'qa_lead_name'    => $p['qa_lead_name'] ?? 'N/A',
            'req_total'       => $req_total,
            'req_dev'         => $req_dev,
            'req_tested'      => $req_tested,
            'tc_total'        => $tc_total,
            'tc_pass'         => $tc_pass,
            'tc_fail'         => $tc_fail,
            'tc_not_tested'   => $tc_nt,
            'bug_open'        => $bug_open,
            'bug_inprog'      => $bug_inprog,
            'bug_resolved'    => $bug_resolved,
            'bug_closed'      => $bug_closed,
            'total_bugs'      => $total_bugs,
            'resolution_rate' => $resolution_rate,
            'coverage'        => $coverage,
            'pass_rate'       => $pass_rate,
        ];
    }
    return $summary;
}

// ══════════════════════════════════════════════════════
//  EXPORT EXCEL (single project)
// ══════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
    $project_id = (int)($_GET['project_id'] ?? 0);
    $type = $_GET['type'] ?? 'summary';

    if (!$project_id) die("Error: No Project ID selected.");
    // Verify project belongs to user
    if (!in_array($project_id, $user_project_ids)) die("Unauthorized: You don't have access to this project.");

    $data = buildSummaryData($conn, $project_id);
    if (!$data) die("Project not found.");
    extract($data);
    $pname = htmlspecialchars($project['name']);

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=ProjectReport_{$project_id}.xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    $hs  = "background:#3a7bd5; color:#fff; font-weight:bold; text-align:center; font-size:14px; padding:8px 10px;";
    $hs2 = "background:#4a90d9; color:#fff; font-weight:bold; font-size:11px; padding:6px 8px;";
    $cs  = "padding:5px 8px; border:1px solid #ccc; font-size:12px; vertical-align:top;";
    $csc = "padding:5px 8px; border:1px solid #ccc; font-size:12px; text-align:center; vertical-align:top;";
    $lbl = "background:#f0f5fd; font-weight:bold; font-size:12px; padding:5px 8px; border:1px solid #ccc; width:30%;";
    $sectionTitle = "background:#eef4fd; font-weight:bold; font-size:13px; padding:7px 10px; border:1px solid #ccc;";

    echo "<table style='border-collapse:collapse; width:100%;'>";
    echo "<tr><td colspan='7' style='$hs'>Project Report — $pname</td></tr>";
    echo "<tr><td colspan='7' style='background:#f8faff; font-size:11px; padding:4px 8px; border:1px solid #ccc;'>Generated: " . date('d F Y') . " &nbsp;|&nbsp; Testify QA Management</td></tr>";
    echo "<tr><td colspan='7' style='$sectionTitle'>Project Information</td></tr>";
    echo "<tr><td style='$lbl'>Project Name</td><td colspan='2' style='$cs'>$pname</td><td style='$lbl'>Client</td><td colspan='2' style='$cs'>" . htmlspecialchars($project['client_name'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td style='$lbl'>Status</td><td colspan='2' style='$cs'>" . htmlspecialchars($project['status'] ?? 'N/A') . "</td><td style='$lbl'>Project Lead</td><td colspan='2' style='$cs'>" . htmlspecialchars($project['lead_name'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td style='$lbl'>QA Lead</td><td colspan='2' style='$cs'>" . htmlspecialchars($project['qa_lead_name'] ?? 'N/A') . "</td><td style='$lbl'>Total Test Cases</td><td colspan='2' style='$csc'>" . $tc_stats['Total'] . "</td></tr>";

    if ($type == 'summary') {
        echo "<tr><td colspan='7' style='$sectionTitle'>Summary Overview</td></tr>";
        echo "<tr>";
        echo "<td style='$csc'><b style='font-size:20px;color:#3a7bd5;'>{$req_stats['Total']}</b><br/><span style='font-size:10px;color:#6b7fa3;'>TOTAL REQUIREMENTS</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#3a7bd5;'>{$tc_stats['Total']}</b><br/><span style='font-size:10px;color:#6b7fa3;'>TOTAL TEST CASES</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#3a7bd5;'>{$coverage}%</b><br/><span style='font-size:10px;color:#6b7fa3;'>TEST COVERAGE</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#27ae60;'>{$resolution_rate}%</b><br/><span style='font-size:10px;color:#6b7fa3;'>BUG RESOLUTION</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#3a7bd5;'>{$req_completion}%</b><br/><span style='font-size:10px;color:#6b7fa3;'>REQ COMPLETION</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#e67e22;'>{$total_bugs}</b><br/><span style='font-size:10px;color:#6b7fa3;'>TOTAL BUGS</span></td>";
        echo "</tr>";
        echo "<tr><td colspan='7' style='$sectionTitle'>Requirements Breakdown</td></tr>";
        echo "<tr>";
        echo "<td style='$csc'><b style='font-size:16px;color:#3a7bd5;'>{$req_stats['Developed']}/{$req_stats['Total']}</b><br/><span style='font-size:10px;'>Developed</span></td>";
        echo "<td style='$csc'><b style='font-size:16px;color:#3a7bd5;'>{$req_stats['Tested']}/{$req_stats['Total']}</b><br/><span style='font-size:10px;'>Tested</span></td>";
        echo "<td style='$csc'><b style='font-size:16px;color:#3a7bd5;'>{$req_stats['Delivered']}/{$req_stats['Total']}</b><br/><span style='font-size:10px;'>Delivered</span></td>";
        echo "<td style='$csc'><b style='font-size:16px;color:#c0392b;'>{$req_prio['Critical']}</b><br/><span style='font-size:10px;'>Critical</span></td>";
        echo "<td style='$csc'><b style='font-size:16px;color:#d35400;'>{$req_prio['High']}</b><br/><span style='font-size:10px;'>High</span></td>";
        echo "<td style='$csc'><b style='font-size:16px;color:#b7950b;'>{$req_prio['Medium']}</b><br/><span style='font-size:10px;'>Medium</span></td>";
        echo "<td style='$csc'><b style='font-size:16px;color:#1e8449;'>{$req_prio['Low']}</b><br/><span style='font-size:10px;'>Low</span></td>";
        echo "</tr>";
        echo "<tr><td colspan='7' style='$sectionTitle'>Test Case Statistics</td></tr>";
        echo "<tr>";
        echo "<td style='$csc'><b style='font-size:20px;color:#27ae60;'>{$tc_stats['Pass']}</b><br/><span style='font-size:10px;'>PASSED</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#e74c3c;'>{$tc_stats['Fail']}</b><br/><span style='font-size:10px;'>FAILED</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#6b7fa3;'>{$tc_stats['Not tested']}</b><br/><span style='font-size:10px;'>NOT TESTED</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#3a7bd5;'>{$tc_stats['Automated']}</b><br/><span style='font-size:10px;'>AUTOMATED</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#3a7bd5;'>{$tc_stats['Manual']}</b><br/><span style='font-size:10px;'>MANUAL</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#3a7bd5;'>" . round($tc_stats['Total'] > 0 ? ($tc_stats['Pass'] / $tc_stats['Total'] * 100) : 0, 1) . "%</b><br/><span style='font-size:10px;'>PASS RATE</span></td>";
        echo "</tr>";
        echo "<tr><td colspan='7' style='$sectionTitle'>Bug Statistics</td></tr>";
        echo "<tr>";
        echo "<td style='background:#fde8e8;$csc'><b style='font-size:20px;color:#c0392b;'>{$bug_stats['Open']}</b><br/><span style='font-size:10px;'>OPEN</span></td>";
        echo "<td style='background:#fef5e7;$csc'><b style='font-size:20px;color:#d35400;'>{$bug_stats['In Progress']}</b><br/><span style='font-size:10px;'>IN PROGRESS</span></td>";
        echo "<td style='background:#d1f2eb;$csc'><b style='font-size:20px;color:#148f77;'>{$bug_stats['Resolved']}</b><br/><span style='font-size:10px;'>RESOLVED</span></td>";
        echo "<td style='background:#e8f8f0;$csc'><b style='font-size:20px;color:#1e8449;'>{$bug_stats['Closed']}</b><br/><span style='font-size:10px;'>CLOSED</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#e74c3c;'>{$total_bugs}</b><br/><span style='font-size:10px;'>TOTAL BUGS</span></td>";
        echo "<td style='$csc'><b style='font-size:20px;color:#27ae60;'>{$resolution_rate}%</b><br/><span style='font-size:10px;'>RESOLUTION RATE</span></td>";
        echo "</tr>";
    }

    if ($type == 'detailed') {
        echo "<tr><td colspan='7' style='$sectionTitle'>Detailed Test Cases</td></tr>";
        if (empty($detailed_cases)) {
            echo "<tr><td colspan='7' style='$csc'>No test cases found for this project.</td></tr>";
        } else {
            foreach ($detailed_cases as $grp) {
                echo "<tr><td colspan='7' style='background:#eef4fd; font-weight:bold; padding:6px 8px; border:1px solid #ccc;'>";
                echo htmlspecialchars($grp['name']);
                echo " &nbsp; <span style='font-size:10px;color:#6b7fa3;'>[Total: {$grp['total']} | Pass: {$grp['pass']} | Fail: {$grp['fail']} | Not Tested: {$grp['not_tested']}]</span>";
                echo "</td></tr>";
                echo "<tr><th style='$hs2'>#</th><th style='$hs2'>Test Case</th><th style='$hs2'>Status</th><th style='$hs2'>Bug Status</th><th style='$hs2'>Automated</th><th style='$hs2'>Executed By</th></tr>";
                $ci = 0;
                foreach ($grp['cases'] as $tc) {
                    $ci++;
                    $s  = $tc['status'];
                    $sc = ($s === 'Pass') ? 'color:#27ae60;font-weight:bold;' : (($s === 'Fail') ? 'color:#e74c3c;font-weight:bold;' : 'color:#6b7fa3;');
                    $bs = trim($tc['bug_status'] ?? '');
                    $bColor = 'color:#6b7fa3;';
                    if ($bs === 'Resolved') $bColor = 'color:#148f77;font-weight:bold;';
                    elseif ($bs === 'Closed') $bColor = 'color:#1e8449;font-weight:bold;';
                    elseif ($bs === 'In Progress') $bColor = 'color:#d35400;font-weight:bold;';
                    elseif ($bs) $bColor = 'color:#c0392b;font-weight:bold;';
                    echo "<tr><td style='$csc'>$ci</td><td style='$cs'>" . htmlspecialchars($tc['tc_title'] ?? '—') . "</td><td style='$sc'>" . htmlspecialchars($s) . "</td>";
                    echo "<td style='$cs'>";
                    if ($tc['bug_raised']) echo "<span style='$bColor'>" . htmlspecialchars($bs ?: 'Open') . "</span>";
                    else echo "—";
                    echo "</td><td style='$csc'>" . ($tc['is_automated'] ? 'Yes' : 'No') . "</td><td style='$cs'>" . htmlspecialchars($tc['executed_name'] ?? '—') . "</td></tr>";
                }
            }
        }
    }

    echo "<tr><td colspan='7' style='background:#f8faff; font-size:10px; padding:5px 8px; border:1px solid #ccc; color:#6b7fa3;'>Testify — QA Management System &nbsp;|&nbsp; $pname &nbsp;|&nbsp; " . date('d F Y') . "</td></tr>";
    echo "</table>";
    exit;
}

// ══════════════════════════════════════════════════════
//  EXPORT ALL PROJECTS EXCEL (user's assigned only)
// ══════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] == 'export_all_excel') {
    $all_summary = buildUserProjectsSummary($conn, $user_project_ids);

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=MyProjectsSummary_" . date('Ymd') . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    $hs  = "background:#3a7bd5; color:#fff; font-weight:bold; text-align:center; font-size:14px; padding:8px 10px;";
    $hs2 = "background:#4a90d9; color:#fff; font-weight:bold; font-size:11px; padding:6px 8px;";
    $cs  = "padding:5px 8px; border:1px solid #ccc; font-size:12px; vertical-align:top;";
    $csc = "padding:5px 8px; border:1px solid #ccc; font-size:12px; text-align:center; vertical-align:top;";
    $sectionTitle = "background:#eef4fd; font-weight:bold; font-size:13px; padding:7px 10px; border:1px solid #ccc;";

    echo "<table style='border-collapse:collapse; width:100%;'>";
    echo "<tr><td colspan='12' style='$hs'>My Projects Summary Report</td></tr>";
    echo "<tr><td colspan='12' style='background:#f8faff; font-size:11px; padding:4px 8px; border:1px solid #ccc;'>Generated: " . date('d F Y') . " &nbsp;|&nbsp; Testify QA Management &nbsp;|&nbsp; Total Projects: " . count($all_summary) . "</td></tr>";

    $tReq = 0; $tTc = 0; $tPass = 0; $tFail = 0; $tBugs = 0; $tActive = 0;
    foreach ($all_summary as $s) {
        $tReq += $s['req_total']; $tTc += $s['tc_total']; $tPass += $s['tc_pass'];
        $tFail += $s['tc_fail']; $tBugs += $s['total_bugs'];
        if ($s['is_active']) $tActive++;
    }
    $tInactive = count($all_summary) - $tActive;
    $tPassRate = $tTc > 0 ? round($tPass / $tTc * 100, 1) : 0;

    echo "<tr><td colspan='12' style='$sectionTitle'>Overview</td></tr>";
    echo "<tr>";
    echo "<td style='$csc'><b style='font-size:18px;color:#3a7bd5;'>" . count($all_summary) . "</b><br/><span style='font-size:10px;'>TOTAL</span></td>";
    echo "<td style='$csc'><b style='font-size:18px;color:#27ae60;'>$tActive</b><br/><span style='font-size:10px;'>ACTIVE</span></td>";
    echo "<td style='$csc'><b style='font-size:18px;color:#6b7fa3;'>$tInactive</b><br/><span style='font-size:10px;'>INACTIVE</span></td>";
    echo "<td style='$csc'><b style='font-size:18px;color:#3a7bd5;'>$tReq</b><br/><span style='font-size:10px;'>REQS</span></td>";
    echo "<td style='$csc'><b style='font-size:18px;color:#3a7bd5;'>$tTc</b><br/><span style='font-size:10px;'>TEST CASES</span></td>";
    echo "<td style='$csc'><b style='font-size:18px;color:#27ae60;'>$tPass</b><br/><span style='font-size:10px;'>PASSED</span></td>";
    echo "<td style='$csc'><b style='font-size:18px;color:#e74c3c;'>$tFail</b><br/><span style='font-size:10px;'>FAILED</span></td>";
    echo "<td style='$csc'><b style='font-size:18px;color:#3a7bd5;'>$tPassRate%</b><br/><span style='font-size:10px;'>PASS RATE</span></td>";
    echo "<td style='$csc'><b style='font-size:18px;color:#e67e22;'>$tBugs</b><br/><span style='font-size:10px;'>BUGS</span></td>";
    echo "</tr>";

    echo "<tr><td colspan='12' style='$sectionTitle'>Project Details</td></tr>";
    echo "<tr>";
    echo "<th style='$hs2'>S.NO.</th><th style='$hs2'>Project</th><th style='$hs2'>Client</th><th style='$hs2'>Status</th><th style='$hs2'>Active</th>";
    echo "<th style='$hs2'>Reqs</th><th style='$hs2'>TC Total</th><th style='$hs2'>Pass</th><th style='$hs2'>Fail</th>";
    echo "<th style='$hs2'>Pass Rate</th><th style='$hs2'>Coverage</th><th style='$hs2'>Bugs</th>";
    echo "</tr>";

    $i = 0;
    foreach ($all_summary as $s) {
        $i++;
        $actStyle = $s['is_active'] ? 'color:#27ae60;font-weight:bold;' : 'color:#6b7fa3;';
        echo "<tr>";
        echo "<td style='$csc'>$i</td>";
        echo "<td style='$cs'>" . htmlspecialchars($s['name']) . "</td>";
        echo "<td style='$cs'>" . htmlspecialchars($s['client_name']) . "</td>";
        echo "<td style='$cs'>" . htmlspecialchars($s['status']) . "</td>";
        echo "<td style='$csc;$actStyle'>" . ($s['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "<td style='$csc'>{$s['req_total']}</td>";
        echo "<td style='$csc'>{$s['tc_total']}</td>";
        echo "<td style='$csc;color:#27ae60;font-weight:bold;'>{$s['tc_pass']}</td>";
        echo "<td style='$csc;color:#e74c3c;font-weight:bold;'>{$s['tc_fail']}</td>";
        echo "<td style='$csc'>{$s['pass_rate']}%</td>";
        echo "<td style='$csc'>{$s['coverage']}%</td>";
        echo "<td style='$csc'>{$s['total_bugs']}</td>";
        echo "</tr>";
    }

    echo "<tr><td colspan='12' style='background:#f8faff; font-size:10px; padding:5px 8px; border:1px solid #ccc; color:#6b7fa3;'>Testify — QA Management System &nbsp;|&nbsp; My Projects Summary &nbsp;|&nbsp; " . date('d F Y') . "</td></tr>";
    echo "</table>";
    exit;
}

// ══════════════════════════════════════════════════════
//  EXPORT PDF (single project)
// ══════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] == 'export_pdf') {
    $project_id = (int)($_GET['project_id'] ?? 0);
    $type = $_GET['type'] ?? 'summary';

    if (!$project_id) die("Error: No Project ID selected.");
    if (!in_array($project_id, $user_project_ids)) die("Unauthorized: You don't have access to this project.");

    $data = buildSummaryData($conn, $project_id);
    if (!$data) die("Project not found.");
    extract($data);

    $pname = htmlspecialchars($project['name']);
    $today = date('d F Y');

    $grouped = [];
    foreach ($detailed_cases as $grp) {
        $grouped[$grp['name']] = $grp;
    }

    header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<title>Project Report — <?= $pname ?></title>
<style>
  @page { size: A4 landscape; margin: 5mm; }
  @media print {
    .no-print { display: none !important; }
    body { margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-size: 7pt !important; }
    tr, th, td { page-break-inside: avoid; page-break-after: auto; }
    .mod-head { page-break-after: auto; page-break-inside: avoid; }
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 8pt; color: #1a2340; background: #fff; }
  .no-print { position: fixed; top: 10px; right: 10px; display: flex; gap: 5px; z-index: 999; }
  .btn-print { background: #3a7bd5; color: #fff; border: none; padding: 8px 15px; border-radius: 4px; font-size: 10pt; font-weight: 700; cursor: pointer; }
  .btn-close  { background: #e74c3c; color: #fff; border: none; padding: 8px 12px; border-radius: 4px; font-size: 10pt; font-weight: 700; cursor: pointer; }
  .header { background: linear-gradient(135deg, #3a7bd5 0%, #4facfe 100%); color: #fff; padding: 6px 10px; border-radius: 6px; margin-bottom: 6px; }
  .header h1 { font-size: 14pt; font-weight: 800; margin:0; line-height:1.2; }
  .header .sub { font-size: 9pt; opacity: .9; margin-top: 2px; }
  .header .gen { font-size: 8pt; opacity: .8; margin-top: 2px; }
  .info-bar { display: flex; border: 1px solid #dde4f0; border-radius: 4px; overflow: hidden; margin-bottom: 6px; }
  .info-cell { flex: 1; padding: 4px 8px; border-right: 1px solid #dde4f0; background: #f8faff; }
  .info-cell:last-child { border-right: none; }
  .ic-label { font-size: 7pt; font-weight: 700; text-transform: uppercase; color: #6b7fa3; display:block; }
  .ic-val { font-size: 9pt; font-weight: 700; color: #1a2340; }
  .sec-title { font-size: 9pt; font-weight: 800; color: #3a7bd5; text-transform: uppercase; border-left: 2px solid #3a7bd5; padding-left: 5px; margin: 6px 0 4px; }
  .stat-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 4px; margin-bottom: 6px; }
  .stat-box { border: 1px solid #dde4f0; border-radius: 4px; padding: 4px 4px; background: #f8faff; text-align: center; }
  .stat-box.blue { background: linear-gradient(135deg,#3a7bd5,#4facfe); border: none; }
  .stat-box .sv { font-size: 12pt; font-weight: 800; line-height: 1; color: #3a7bd5; }
  .stat-box.blue .sv { color: #fff; }
  .stat-box .sl { font-size: 6pt; font-weight: 700; text-transform: uppercase; color: #6b7fa3; margin-top: 1px; display:block; }
  .stat-box.blue .sl { color: rgba(255,255,255,.85); }
  .prog-row { margin-bottom: 3px; }
  .prog-top { display: flex; justify-content: space-between; font-size: 8pt; margin-bottom: 1px; }
  .prog-bg { width: 100%; height: 4px; background: #e9ecf5; border-radius: 10px; overflow: hidden; }
  .prog-fill { height: 100%; border-radius: 10px; }
  .req-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; margin-bottom: 4px; }
  .req-box { border: 1px solid #dde4f0; border-radius: 4px; padding: 2px; background: #f8faff; text-align: center; }
  .req-box .rv { font-size: 9pt; font-weight: 800; color: #3a7bd5; }
  .req-box .rl { font-size: 6pt; color: #6b7fa3; font-weight: 700; display:block; }
  .tc-stat-grid { display: grid; grid-template-columns: repeat(5,1fr); gap: 4px; margin-bottom: 4px; }
  .tc-stat-box  { border: 1px solid #dde4f0; border-radius: 4px; padding: 2px; text-align: center; background: #f8faff; }
  .tc-stat-box .tv { font-size: 10pt; font-weight: 800; color: #3a7bd5; }
  .tc-stat-box .tl { font-size: 6pt; font-weight: 700; color: #6b7fa3; display:block; margin-top:1px;}
  .bug-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 4px; margin-bottom: 6px; }
  .bug-box  { border-radius: 4px; padding: 4px; text-align: center; color: #fff; }
  .bug-box .bv { font-size: 12pt; font-weight: 800; line-height: 1; }
  .bug-box .bl { font-size: 6pt; font-weight: 700; display:block; margin-top:1px; opacity:.9; }
  .mod-head { background: #eef4fd; border-left: 2px solid #3a7bd5; padding: 4px 6px; font-size: 8pt; font-weight: 800; color: #2c4a80; margin-top: 6px; display: flex; justify-content: space-between; align-items: center; }
  .mod-stats { display: flex; gap: 3px; }
  table.detailed-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 7pt; }
  thead th { background: #3a7bd5; color: #fff; padding: 3px 6px; text-align: left; font-size: 6.5pt; font-weight: 700; text-transform: uppercase; border: 1px solid #2a5d9f; white-space: nowrap; }
  tbody td { padding: 2px 6px; border: 1px solid #eef2f9; vertical-align: top; }
  tbody tr:nth-child(even) td { background: #f8faff; }
  .badge { display: inline-block; padding: 1px 4px; border-radius: 10px; font-size: 6.5pt; font-weight: 700; }
  .footer { margin-top: 6px; padding-top: 4px; border-top: 1px solid #dde4f0; display: flex; justify-content: space-between; font-size: 6pt; color: #6b7fa3; }
  .tv.green { color: #27ae60; } .tv.red { color: #e74c3c; } .tv.muted { color: #6b7fa3; }
  .bug-box.open { background: #e74c3c; } .bug-box.inprog { background: #e67e22; }
  .bug-box.resolved { background: #1abc9c; } .bug-box.closed { background: #27ae60; }
  .b-pass { background: #e8f8f0; color: #1e8449; } .b-fail { background: #fde8e8; color: #c0392b; }
  .b-nt { background: #f0f5fd; color: #6b7fa3; } .b-open { background: #fde8e8; color: #c0392b; }
  .b-inprog { background: #fef5e7; color: #d35400; } .b-resolved { background: #d1f2eb; color: #148f77; }
  .b-closed { background: #e8f8f0; color: #1e8449; } .b-yes { background: #e8f8f0; color: #1e8449; }
  .b-no { background: #f0f5fd; color: #6b7fa3; }
  .ptag { padding: 2px 6px; border-radius: 10px; font-size: 6.5pt; font-weight: 700; }
  .ptag.critical { background: #fde8e8; color: #c0392b; } .ptag.high { background: #fdebd0; color: #d35400; }
  .ptag.medium { background: #fef9e7; color: #b7950b; } .ptag.low { background: #e8f8f0; color: #1e8449; }
</style>
</head>
<body>
<div class="no-print">
  <button class="btn-print" onclick="window.print()">Print / Save as PDF</button>
  <button class="btn-close" onclick="window.close()">Close</button>
</div>
<div class="header">
  <h1>Project Report — <?= $pname ?></h1>
  <div class="sub">Client: <?= htmlspecialchars($project['client_name'] ?? 'N/A') ?></div>
  <div class="gen">Generated: <?= $today ?> | Testify QA Management</div>
</div>
<div class="info-bar">
  <div class="info-cell"><span class="ic-label">Status</span><span class="ic-val"><?= htmlspecialchars($project['status'] ?? 'N/A') ?></span></div>
  <div class="info-cell"><span class="ic-label">Project Lead</span><span class="ic-val"><?= htmlspecialchars($project['lead_name'] ?? 'N/A') ?></span></div>
  <div class="info-cell"><span class="ic-label">QA Lead</span><span class="ic-val"><?= htmlspecialchars($project['qa_lead_name'] ?? 'N/A') ?></span></div>
  <div class="info-cell"><span class="ic-label">Total Test Cases</span><span class="ic-val"><?= $tc_stats['Total'] ?></span></div>
  <div class="info-cell"><span class="ic-label">Test Coverage</span><span class="ic-val"><?= $coverage ?>%</span></div>
</div>

<?php if ($type == 'summary'): ?>
<div class="sec-title">Summary Overview</div>
<div class="stat-grid">
  <div class="stat-box blue"><div class="sv"><?= $req_stats['Total'] ?></div><span class="sl">Reqs</span></div>
  <div class="stat-box blue"><div class="sv"><?= $tc_stats['Total'] ?></div><span class="sl">Test Cases</span></div>
  <div class="stat-box"><div class="sv"><?= $coverage ?>%</div><span class="sl">Coverage</span></div>
  <div class="stat-box"><div class="sv" style="color:#27ae60"><?= $resolution_rate ?>%</div><span class="sl">Bug Res</span></div>
  <div class="stat-box"><div class="sv"><?= $req_completion ?>%</div><span class="sl">Req Comp</span></div>
</div>
<div class="prog-row">
  <div class="prog-top"><span>Req Completion: <?= $req_completion ?>%</span></div>
  <div class="prog-bg"><div class="prog-fill" style="width:<?= $req_completion ?>%; background: #3a7bd5;"></div></div>
</div>
<div class="prog-row">
  <div class="prog-top"><span>Test Coverage: <?= $coverage ?>%</span></div>
  <div class="prog-bg"><div class="prog-fill" style="width:<?= $coverage ?>%; background: #f1c40f;"></div></div>
</div>

<div style="display:flex; gap:4px; margin-bottom:6px; margin-top:8px;">
  <div style="flex:1; border:1px solid #dde4f0; border-radius:4px; padding:4px;">
    <div class="sec-title">Requirements</div>
    <div class="req-grid" style="margin-bottom:0;">
      <div class="req-box"><div class="rv"><?= $req_stats['Developed'] ?>/<?= $req_stats['Total'] ?></div><span class="rl">Dev</span></div>
      <div class="req-box"><div class="rv"><?= $req_stats['Tested'] ?>/<?= $req_stats['Total'] ?></div><span class="rl">Test</span></div>
      <div class="req-box"><div class="rv"><?= $req_stats['Delivered'] ?>/<?= $req_stats['Total'] ?></div><span class="rl">Del</span></div>
    </div>
    <div style="display:flex; gap:3px; margin-top:2px;">
      <span class="ptag critical">Crit: <?= $req_prio['Critical'] ?></span>
      <span class="ptag high">High: <?= $req_prio['High'] ?></span>
      <span class="ptag medium">Med: <?= $req_prio['Medium'] ?></span>
      <span class="ptag low">Low: <?= $req_prio['Low'] ?></span>
    </div>
  </div>
  <div style="flex:1.5; border:1px solid #dde4f0; border-radius:4px; padding:4px;">
    <div class="sec-title">Test Cases</div>
    <div class="tc-stat-grid" style="margin-bottom:4px;">
      <div class="tc-stat-box"><div class="tv green"><?= $tc_stats['Pass'] ?></div><span class="tl">Pass</span></div>
      <div class="tc-stat-box"><div class="tv red"><?= $tc_stats['Fail'] ?></div><span class="tl">Fail</span></div>
      <div class="tc-stat-box"><div class="tv muted"><?= $tc_stats['Not tested'] ?></div><span class="tl">NT</span></div>
      <div class="tc-stat-box"><div class="tv"><?= $tc_stats['Automated'] ?></div><span class="tl">Auto</span></div>
      <div class="tc-stat-box"><div class="tv"><?= $tc_stats['Manual'] ?></div><span class="tl">Man</span></div>
    </div>
    <div class="bug-grid">
      <div class="bug-box open"><div class="bv"><?= $bug_stats['Open'] ?></div><span class="bl">Open</span></div>
      <div class="bug-box inprog"><div class="bv"><?= $bug_stats['In Progress'] ?></div><span class="bl">In Prog</span></div>
      <div class="bug-box resolved"><div class="bv"><?= $bug_stats['Resolved'] ?></div><span class="bl">Res</span></div>
      <div class="bug-box closed"><div class="bv"><?= $bug_stats['Closed'] ?></div><span class="bl">Closed</span></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($type == 'detailed'): ?>
<div class="sec-title">Detailed Test Cases</div>
<?php if (empty($grouped)): ?>
  <p style="color:#6b7fa3;text-align:center;padding:10px;">No test cases found for this project.</p>
<?php else: foreach ($grouped as $module => $grp):
  $mp = $grp['pass']; $mf = $grp['fail']; $mn = $grp['not_tested'];
?>
<div class="mod-head">
  <span><?= htmlspecialchars($module) ?></span>
  <div class="mod-stats">
    <span class="badge b-pass">P: <?= $mp ?></span>
    <span class="badge b-fail">F: <?= $mf ?></span>
    <span class="badge b-nt">NT: <?= $mn ?></span>
  </div>
</div>
<table class="detailed-table">
  <thead>
    <tr>
      <th style="width:4%">S.NO.</th>
      <th style="width:35%">Test Case</th>
      <th style="width:8%">Status</th>
      <th style="width:10%">Bug Status</th>
      <th style="width:6%">Auto</th>
      <th style="width:12%">Executed By</th>
      <th style="width:12%">Assigned To</th>
      <th style="width:10%">Executed On</th>
    </tr>
  </thead>
  <tbody>
    <?php $ci = 0; foreach ($grp['cases'] as $tc): $ci++;
      $s  = $tc['status'];
      $sc = $s==='Pass'?'b-pass':($s==='Fail'?'b-fail':'b-nt');
      $bs = trim($tc['bug_status'] ?? '');
      $bc = $bs==='Resolved'?'b-resolved':($bs==='Closed'?'b-closed':($bs==='In Progress'?'b-inprog':'b-open'));
      $eo = $tc['executed_on'] ?? null;
    ?>
    <tr>
      <td style="color:#6b7fa3"><?= $ci ?></td>
      <td><?= htmlspecialchars($tc['tc_title'] ?? '—') ?></td>
      <td><span class="badge <?= $sc ?>"><?= htmlspecialchars($s) ?></span></td>
      <td><?php if ($tc['bug_raised']): ?><span class="badge <?= $bc ?>"><?= htmlspecialchars($bs ?: 'Open') ?></span><?php else: ?>—<?php endif; ?></td>
      <td><span class="badge <?= $tc['is_automated'] ? 'b-yes' : 'b-no' ?>"><?= $tc['is_automated'] ? 'Y' : 'N' ?></span></td>
      <td><?= htmlspecialchars($tc['executed_name'] ?? '—') ?></td>
      <td><?= htmlspecialchars($tc['assigned_name'] ?? '—') ?></td>
      <td style="color:#6b7fa3"><?= $eo ? date('d M y', strtotime($eo)) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; endif; ?>
<?php endif; ?>

<div class="footer">
  <span>Testify — QA Management System</span>
  <span><?= $pname ?> | <?= $today ?></span>
</div>
</body>
</html>
<?php
    exit;
}

// ══════════════════════════════════════════════════════
//  EXPORT ALL PROJECTS PDF (user's assigned only)
// ══════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] == 'export_all_pdf') {
    $all_summary = buildUserProjectsSummary($conn, $user_project_ids);
    $today = date('d F Y');

    $tReq = 0; $tTc = 0; $tPass = 0; $tFail = 0; $tBugs = 0; $tActive = 0;
    foreach ($all_summary as $s) {
        $tReq += $s['req_total']; $tTc += $s['tc_total']; $tPass += $s['tc_pass'];
        $tFail += $s['tc_fail']; $tBugs += $s['total_bugs'];
        if ($s['is_active']) $tActive++;
    }
    $tInactive = count($all_summary) - $tActive;
    $tPassRate = $tTc > 0 ? round($tPass / $tTc * 100, 1) : 0;

    header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<title>My Projects Summary Report</title>
<style>
  @page { size: A4 landscape; margin: 5mm; }
  @media print {
    .no-print { display: none !important; }
    body { margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-size: 7pt !important; }
    tr, th, td { page-break-inside: avoid; page-break-after: auto; }
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 8pt; color: #1a2340; background: #fff; }
  .no-print { position: fixed; top: 10px; right: 10px; display: flex; gap: 5px; z-index: 999; }
  .btn-print { background: #3a7bd5; color: #fff; border: none; padding: 8px 15px; border-radius: 4px; font-size: 10pt; font-weight: 700; cursor: pointer; }
  .btn-close  { background: #e74c3c; color: #fff; border: none; padding: 8px 12px; border-radius: 4px; font-size: 10pt; font-weight: 700; cursor: pointer; }
  .header { background: linear-gradient(135deg, #3a7bd5 0%, #4facfe 100%); color: #fff; padding: 8px 12px; border-radius: 6px; margin-bottom: 6px; }
  .header h1 { font-size: 14pt; font-weight: 800; margin:0; line-height:1.2; }
  .header .sub { font-size: 9pt; opacity: .9; margin-top: 2px; }
  .sec-title { font-size: 9pt; font-weight: 800; color: #3a7bd5; text-transform: uppercase; border-left: 2px solid #3a7bd5; padding-left: 5px; margin: 8px 0 4px; }
  .overview-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 4px; margin-bottom: 8px; }
  .ov-box { border: 1px solid #dde4f0; border-radius: 4px; padding: 6px; background: #f8faff; text-align: center; }
  .ov-box.blue { background: linear-gradient(135deg,#3a7bd5,#4facfe); border: none; }
  .ov-box .ov-val { font-size: 14pt; font-weight: 800; color: #3a7bd5; }
  .ov-box.blue .ov-val { color: #fff; }
  .ov-box .ov-lbl { font-size: 6pt; font-weight: 700; text-transform: uppercase; color: #6b7fa3; margin-top: 1px; display: block; }
  .ov-box.blue .ov-lbl { color: rgba(255,255,255,.85); }
  table { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
  thead th { background: #3a7bd5; color: #fff; padding: 4px 6px; text-align: left; font-size: 7pt; font-weight: 700; text-transform: uppercase; border: 1px solid #2a5d9f; white-space: nowrap; }
  tbody td { padding: 3px 6px; border: 1px solid #eef2f9; vertical-align: top; }
  tbody tr:nth-child(even) td { background: #f8faff; }
  .badge { display: inline-block; padding: 1px 5px; border-radius: 10px; font-size: 6.5pt; font-weight: 700; }
  .b-yes { background: #e8f8f0; color: #1e8449; } .b-no { background: #f0f5fd; color: #6b7fa3; }
  .b-pass { background: #e8f8f0; color: #1e8449; } .b-fail { background: #fde8e8; color: #c0392b; }
  .footer { margin-top: 8px; padding-top: 4px; border-top: 1px solid #dde4f0; display: flex; justify-content: space-between; font-size: 6pt; color: #6b7fa3; }
</style>
</head>
<body>
<div class="no-print">
  <button class="btn-print" onclick="window.print()">Print / Save as PDF</button>
  <button class="btn-close" onclick="window.close()">Close</button>
</div>
<div class="header">
  <h1>My Projects Summary Report</h1>
  <div class="sub">Total: <?= count($all_summary) ?> Projects | Active: <?= $tActive ?> | Inactive: <?= $tInactive ?></div>
  <div style="font-size:8pt;opacity:.8;margin-top:2px;">Generated: <?= $today ?> | Testify QA Management</div>
</div>

<div class="sec-title">Overview</div>
<div class="overview-grid">
  <div class="ov-box blue"><div class="ov-val"><?= count($all_summary) ?></div><span class="ov-lbl">Total Projects</span></div>
  <div class="ov-box"><div class="ov-val" style="color:#27ae60"><?= $tActive ?></div><span class="ov-lbl">Active</span></div>
  <div class="ov-box"><div class="ov-val" style="color:#6b7fa3"><?= $tInactive ?></div><span class="ov-lbl">Inactive</span></div>
  <div class="ov-box"><div class="ov-val"><?= $tReq ?></div><span class="ov-lbl">Total Reqs</span></div>
  <div class="ov-box"><div class="ov-val"><?= $tTc ?></div><span class="ov-lbl">Total Test Cases</span></div>
</div>
<div class="overview-grid">
  <div class="ov-box"><div class="ov-val" style="color:#27ae60"><?= $tPass ?></div><span class="ov-lbl">Passed</span></div>
  <div class="ov-box"><div class="ov-val" style="color:#e74c3c"><?= $tFail ?></div><span class="ov-lbl">Failed</span></div>
  <div class="ov-box"><div class="ov-val"><?= $tPassRate ?>%</div><span class="ov-lbl">Pass Rate</span></div>
  <div class="ov-box"><div class="ov-val" style="color:#e67e22"><?= $tBugs ?></div><span class="ov-lbl">Total Bugs</span></div>
  <div class="ov-box"><div class="ov-val"><?= $tReq ?></div><span class="ov-lbl">Requirements</span></div>
</div>

<div class="sec-title">Project Details</div>
<table>
  <thead>
    <tr>
      <th>S.NO.</th><th>Project</th><th>Client</th><th>Status</th><th>Active</th>
      <th>Reqs</th><th>TC Total</th><th>Pass</th><th>Fail</th>
      <th>Pass Rate</th><th>Coverage</th><th>Bugs</th><th>Bug Res %</th>
    </tr>
  </thead>
  <tbody>
    <?php $i = 0; foreach ($all_summary as $s): $i++; ?>
    <tr>
      <td style="color:#6b7fa3"><?= $i ?></td>
      <td><b><?= htmlspecialchars($s['name']) ?></b></td>
      <td><?= htmlspecialchars($s['client_name']) ?></td>
      <td><?= htmlspecialchars($s['status']) ?></td>
      <td><span class="badge <?= $s['is_active'] ? 'b-yes' : 'b-no' ?>"><?= $s['is_active'] ? 'Yes' : 'No' ?></span></td>
      <td style="text-align:center"><?= $s['req_total'] ?></td>
      <td style="text-align:center"><?= $s['tc_total'] ?></td>
      <td style="text-align:center;color:#27ae60;font-weight:bold"><?= $s['tc_pass'] ?></td>
      <td style="text-align:center;color:#e74c3c;font-weight:bold"><?= $s['tc_fail'] ?></td>
      <td style="text-align:center"><?= $s['pass_rate'] ?>%</td>
      <td style="text-align:center"><?= $s['coverage'] ?>%</td>
      <td style="text-align:center"><?= $s['total_bugs'] ?></td>
      <td style="text-align:center"><?= $s['resolution_rate'] ?>%</td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="footer">
  <span>Testify — QA Management System</span>
  <span>My Projects Summary | <?= $today ?></span>
</div>
</body>
</html>
<?php
    exit;
}

// ══════════════════════════════════════════════════════
//  MAIN PAGE LOGIC
// ══════════════════════════════════════════════════════
$selected_project = (int)($_GET['filter_project'] ?? 0);

// Verify selected project belongs to user
if ($selected_project > 0 && !in_array($selected_project, $user_project_ids)) {
    $selected_project = 0;
}

$report_data = null;

// GLOBAL OVERVIEW STATS — only for user's assigned projects
$proj_cols_check = get_cols($conn, 'projects');
$active_col      = pick($proj_cols_check, ['action', 'is_active', 'active'], 'action');

$global_stats = ['Active' => 0, 'Inactive' => 0, 'Total' => 0];
if (!empty($user_project_ids)) {
    $ids_list = implode(',', array_map('intval', $user_project_ids));
    if ($active_col) {
        $sql_scope = "SELECT `$active_col` as is_active FROM projects WHERE id IN ($ids_list)";
    } else {
        $sql_scope = "SELECT status FROM projects WHERE id IN ($ids_list)";
    }
    $res_scope = $conn->query($sql_scope);
    if ($res_scope) {
        while ($row = $res_scope->fetch_assoc()) {
            $global_stats['Total']++;
            if ($active_col) {
                $val = $row['is_active'];
                if ($val == 1 || $val === 'active' || $val === '1') {
                    $global_stats['Active']++;
                } else {
                    $global_stats['Inactive']++;
                }
            } else {
                if (strtolower($row['status']) == 'active') {
                    $global_stats['Active']++;
                } else {
                    $global_stats['Inactive']++;
                }
            }
        }
    }
}

// Build user's projects summary data
$all_projects_summary = buildUserProjectsSummary($conn, $user_project_ids);

if ($selected_project > 0) {
    $raw = buildSummaryData($conn, $selected_project);
    if ($raw) {
        $project_details = $raw['project'];
        $project_details['lead_name']    = $raw['project']['lead_name'] ?? 'N/A';
        $project_details['qa_lead_name'] = $raw['project']['qa_lead_name'] ?? 'N/A';

        $report_data = [
            'project_details' => $project_details,
            'req_stats'       => $raw['req_stats'],
            'req_prio'        => $raw['req_prio'],
            'tc_stats'        => $raw['tc_stats'],
            'bug_stats'       => $raw['bug_stats'],
            'resolution_rate' => $raw['resolution_rate'],
            'req_completion'  => $raw['req_completion'],
            'coverage'        => $raw['coverage'],
            'detailed_cases'  => $raw['detailed_cases'],
        ];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TestiFy — My Project Reports</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<style>
/* ═══════════════════════════════════════════════════════
   BASE
   ═══════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --blue-dark:  #3a7bd5;
  --blue-mid:   #4a90d9;
  --blue-light: #4facfe;
  --text-main:  #2d3a5e;
  --text-muted: #6b7fa3;
  --border:     #dde4f0;
  --white:      #ffffff;
  --bg:         #f8faff;
  --red:        #e74c3c;
  --green:      #27ae60;
  --orange:     #e67e22;
  --sb-w:       240px;
  --nb-h:       60px;
}
body { min-height:100vh; background:var(--bg); font-family:'Nunito',sans-serif; color:var(--text-main); overflow-x:hidden; }

/* NAVBAR */
.navbar {
  background:var(--white); border-bottom:1.5px solid var(--border);
  display:flex; align-items:center; justify-content:space-between;
  padding:0 20px; height:var(--nb-h);
  position:fixed; top:0; left:0; right:0; z-index:300;
  box-shadow:0 2px 16px rgba(74,144,217,.09);
}
.nblogo {
  font-family:'Poppins',sans-serif; font-weight:800; font-size:20px;
  background:linear-gradient(90deg,var(--blue-dark),var(--blue-light));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
  text-decoration:none; white-space:nowrap;
  display:flex; align-items:center; gap:10px;
}
.ham {
  display:none; flex-direction:column; justify-content:center; gap:5px;
  width:40px; height:40px; border:none; background:transparent;
  cursor:pointer; padding:8px; border-radius:8px; transition:.2s;
}
.ham:hover { background:#eef4fd; }
.ham span { display:block; height:2px; border-radius:2px; background:var(--text-main); transition:.3s; }
.ham.open span:nth-child(1) { transform:translateY(7px) rotate(45deg); }
.ham.open span:nth-child(2) { opacity:0; transform:scaleX(0); }
.ham.open span:nth-child(3) { transform:translateY(-7px) rotate(-45deg); }
.nb-right { display:flex; align-items:center; gap:10px; flex-shrink:0; }
.nb-user  { font-size:20px; color:var(--text-muted); font-weight:600; white-space:nowrap; display:flex; align-items:center; gap:8px; }
.nb-role  { font-size:15px; font-weight:700; color:#1565c0; background:#e3f2fd; padding:2px 8px; border-radius:6px; text-transform:capitalize; letter-spacing:.5px; white-space:nowrap; }
.blgout   { padding:7px 14px; border-radius:8px; border:1.5px solid var(--blue-mid); color:var(--blue-mid); text-decoration:none; font-weight:700; font-size:13px; white-space:nowrap; transition:.2s; }
.blgout:hover { background:var(--blue-mid); color:#fff; }

/* SIDEBAR */
.sidebar {
  position:fixed; top:var(--nb-h); left:0; bottom:0; width:var(--sb-w);
  background:var(--white); border-right:1.5px solid var(--border);
  z-index:250; overflow-y:auto; overflow-x:hidden;
  transition:transform .28s cubic-bezier(.4,0,.2,1);
  box-shadow:2px 0 20px rgba(74,144,217,.06);
  padding-bottom:24px;
}
.sidebar-overlay {
  display:none; position:fixed; inset:0; top:var(--nb-h);
  background:rgba(30,45,80,.4); z-index:240; backdrop-filter:blur(2px);
}
.sidebar-overlay.open { display:block; }
.sb-section { padding:18px 14px 6px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); }
.sb-link {
  display:flex; align-items:center; gap:10px;
  padding:9px 16px; margin:1px 8px; border-radius:10px;
  text-decoration:none; color:var(--text-main);
  font-size:13.5px; font-weight:600; transition:.15s; position:relative;
}
.sb-link:hover { background:#eef4fd; color:var(--blue-dark); }
.sb-link.active { background:linear-gradient(90deg,#e8f0fd,#f0f7ff); color:var(--blue-dark); font-weight:700; }
.sb-link.active::before {
  content:''; position:absolute; left:0; top:20%; bottom:20%;
  width:3px; border-radius:0 3px 3px 0;
  background:var(--blue-dark); margin-left:-8px;
}
.sb-link svg { width:16px; height:16px; flex-shrink:0; opacity:.7; }
.sb-link.active svg { opacity:1; }
.sb-home {
  display:flex; align-items:center; gap:10px;
  padding:12px 16px; margin:8px 8px 2px; border-radius:10px;
  text-decoration:none; color:var(--text-main);
  font-size:13.5px; font-weight:700; transition:.15s;
  background:#f8faff; border:1px solid var(--border);
}
.sb-home:hover { background:#eef4fd; color:var(--blue-dark); }
.sb-home svg { width:16px; height:16px; opacity:.7; }

/* LAYOUT */
.page-wrap {
  margin-left:var(--sb-w);
  margin-top:var(--nb-h);
  min-height:calc(100vh - var(--nb-h));
  transition:margin-left .28s cubic-bezier(.4,0,.2,1);
}
.main { max-width:1200px; margin:0 auto; padding:28px 24px 60px; }
.page-title { display:flex; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
.page-title h1 { font-family:'Poppins',sans-serif; font-weight:800; font-size:26px; color:var(--text-main); letter-spacing:-.5px; }
.badge-page { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; font-family:'Poppins',sans-serif; font-weight:700; font-size:10px; letter-spacing:2px; text-transform:uppercase; padding:5px 12px; border-radius:6px; }

/* TOOLBAR */
.toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.toolbar-right { display:flex; gap:8px; flex-shrink:0; flex-wrap:wrap; }
.btn-export {
  height:38px; padding:0 16px; border-radius:10px; border:none;
  font-family:'Nunito',sans-serif; font-weight:700; font-size:13px;
  cursor:pointer; display:inline-flex; align-items:center; gap:6px;
  transition:opacity .2s; white-space:nowrap;
}
.btn-export.pdf   { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; box-shadow:0 4px 14px rgba(58,123,213,.3); }
.btn-export.excel { background:linear-gradient(90deg,#27ae60,#2ecc71); color:#fff; box-shadow:0 4px 14px rgba(39,174,96,.3); }
.btn-export.all-pdf   { background:linear-gradient(90deg,#8e44ad,#9b59b6); color:#fff; box-shadow:0 4px 14px rgba(142,68,173,.3); }
.btn-export.all-excel { background:linear-gradient(90deg,#16a085,#1abc9c); color:#fff; box-shadow:0 4px 14px rgba(22,160,133,.3); }
.btn-export:hover { opacity:.88; transform:translateY(-1px); }
.btn-refresh {
  height:38px; padding:0 18px; border-radius:10px; border:1.5px solid var(--border);
  background:var(--white); font-family:'Nunito',sans-serif; font-weight:700; font-size:13px;
  color:var(--text-main); cursor:pointer; display:inline-flex; align-items:center; gap:7px;
  transition:.18s; white-space:nowrap;
}
.btn-refresh:hover { background:#eef4fd; border-color:var(--blue-mid); color:var(--blue-dark); }
.btn-refresh svg { transition:transform .3s; }
.btn-refresh:hover svg { transform:rotate(180deg); }

/* TABLE CARD */
.table-card { background:var(--white); border-radius:18px; border:1.5px solid var(--border); box-shadow:0 4px 20px rgba(74,144,217,.07); overflow:hidden; margin-bottom:18px; }
.tc-header { padding:16px 20px 14px; border-bottom:1px solid var(--border); font-family:'Poppins',sans-serif; font-weight:700; font-size:15px; color:var(--text-main); }
.table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
table { width:100%; border-collapse:collapse; }
thead th { background:#f0f5fd; padding:12px 14px; text-align:left; font-size:11.5px; font-weight:700; color:var(--text-muted); letter-spacing:.8px; text-transform:uppercase; border-bottom:1.5px solid var(--border); white-space:nowrap; }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:hover { background:#f5f8fe; }
tbody td { padding:12px 14px; font-size:13.5px; vertical-align:middle; }

/* BADGES */
.badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:700; white-space:nowrap; }
.badge-pass     { background:#e8f8f0; color:#1e8449; }
.badge-fail     { background:#fde8e8; color:#c0392b; }
.badge-nt       { background:#f0f5fd; color:var(--text-muted); }
.badge-resolved { background:#d1f2eb; color:#148f77; }
.badge-open     { background:#fde8e8; color:#c0392b; }
.badge-closed   { background:#e8f8f0; color:#1e8449; }
.badge-inprog   { background:#fef5e7; color:#d35400; }
.badge-active   { background:#e8f8f0; color:#1e8449; }
.badge-inactive { background:#f0f5fd; color:var(--text-muted); }
.prio-badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:700; }
.prio-badge.High     { background:#fdebd0; color:#d35400; }
.prio-badge.Critical { background:#fde8e8; color:#c0392b; }
.prio-badge.Medium   { background:#fef9e7; color:#b7950b; }
.prio-badge.Low      { background:#e8f8f0; color:#1e8449; }

/* EMPTY STATE */
.empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); }
.empty-state svg { width:50px; height:50px; margin-bottom:12px; color:#c5d5e8; }
.empty-state h3 { font-family:'Poppins',sans-serif; font-weight:800; font-size:17px; margin-bottom:6px; color:var(--text-main); }

/* GLOBAL OVERVIEW */
.global-overview {
  display:grid; grid-template-columns: repeat(3, 1fr); gap:18px; margin-bottom:24px;
}
.overview-card {
  background:var(--white); border:1.5px solid var(--border); border-radius:18px;
  padding:24px 20px; text-align:center;
  box-shadow:0 4px 20px rgba(74,144,217,.06);
  transition:transform .2s;
}
.overview-card:hover { transform:translateY(-4px); }
.oc-count { font-family:'Poppins',sans-serif; font-weight:800; font-size:36px; color:var(--text-main); line-height:1; display:block; }
.oc-label { font-size:13px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px; }
.oc-green .oc-count { color:var(--green); }
.oc-grey .oc-count { color:var(--text-muted); }

/* ALL PROJECTS SUMMARY TABLE */
.all-summary-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; }
.all-summary-header h2 { font-family:'Poppins',sans-serif; font-weight:800; font-size:18px; color:var(--text-main); }
.summary-stat-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:18px; }
.summary-stat-card { background:var(--white); border:1.5px solid var(--border); border-radius:14px; padding:14px 12px; text-align:center; box-shadow:0 2px 10px rgba(74,144,217,.06); }
.summary-stat-card.blue-grad { background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue-light) 100%); border:none; }
.summary-stat-card .ssc-val { font-family:'Poppins',sans-serif; font-weight:800; font-size:24px; color:var(--text-main); line-height:1; }
.summary-stat-card.blue-grad .ssc-val { color:#fff; }
.summary-stat-card .ssc-lbl { font-size:10px; font-weight:700; color:var(--text-muted); margin-top:4px; text-transform:uppercase; letter-spacing:.5px; }
.summary-stat-card.blue-grad .ssc-lbl { color:rgba(255,255,255,.8); }

/* PROJECT HEADER & INFO BAR */
.proj-title { background:var(--white); border-radius:18px; border:1.5px solid var(--border); padding:20px 24px; margin-bottom:14px; box-shadow:0 4px 20px rgba(74,144,217,.07); }
.proj-title h2 { font-family:'Poppins',sans-serif; font-weight:800; font-size:19px; margin-bottom:3px; }
.proj-title .gen { font-size:12.5px; color:var(--text-muted); }
.proj-info { display:flex; border:1.5px solid var(--border); border-radius:12px; overflow:hidden; margin-bottom:18px; background:var(--white); box-shadow:0 2px 8px rgba(74,144,217,.05); }
.proj-info-cell { flex:1; padding:14px 20px; border-right:1px solid var(--border); }
.proj-info-cell:last-child { border-right:none; }
.pi-label { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.9px; color:var(--text-muted); margin-bottom:5px; }
.pi-val   { font-size:14px; font-weight:700; color:var(--text-main); }

/* TABS */
.tabs { display:flex; gap:8px; margin-bottom:20px; overflow-x:auto; -webkit-overflow-scrolling:touch; padding-bottom:2px; }
.tab-btn { height:38px; padding:0 22px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; color:var(--text-muted); cursor:pointer; transition:.18s; white-space:nowrap; flex-shrink:0; }
.tab-btn.active { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; border-color:transparent; box-shadow:0 4px 14px rgba(74,144,217,.3); }
.tab-btn:not(.active):hover { background:#eef4fd; color:var(--blue-dark); }

/* STAT CARDS */
.stat-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:18px; }
.stat-card { background:var(--white); border:1.5px solid var(--border); border-radius:14px; padding:18px 16px; box-shadow:0 2px 10px rgba(74,144,217,.06); }
.stat-card.blue-grad { background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue-light) 100%); border:none; }
.stat-card .sc-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-muted); margin-bottom:8px; }
.stat-card.blue-grad .sc-label { color:rgba(255,255,255,.8); }
.stat-card .sc-val   { font-family:'Poppins',sans-serif; font-weight:800; font-size:32px; color:var(--text-main); line-height:1; }
.stat-card.blue-grad .sc-val { color:#fff; }
.stat-card .sc-sub   { font-size:11px; color:var(--text-muted); margin-top:5px; }
.stat-card.blue-grad .sc-sub { color:rgba(255,255,255,.75); }

/* PROGRESS BARS */
.prog-wrap { margin-bottom:13px; }
.prog-lrow { display:flex; justify-content:space-between; margin-bottom:5px; font-size:13px; }
.prog-lrow .pl-name { font-weight:600; color:var(--text-muted); }
.prog-lrow .pl-pct  { font-weight:700; color:var(--text-main); }
.prog-bg { width:100%; height:12px; background:#e9ecf5; border-radius:20px; overflow:hidden; }
.prog-fill { height:100%; border-radius:20px; }
.prog-fill.blue   { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); }
.prog-fill.yellow { background:linear-gradient(90deg,#f39c12,#f1c40f); }

/* REQ BREAKDOWN */
.req-breakdown { background:var(--white); border:1.5px solid var(--border); border-radius:14px; padding:18px 20px; margin-bottom:18px; box-shadow:0 2px 8px rgba(74,144,217,.05); }
.sec-title { font-family:'Poppins',sans-serif; font-weight:700; font-size:14px; color:var(--text-main); margin-bottom:14px; }
.req-cols { display:flex; border:1px solid var(--border); border-radius:10px; overflow:hidden; margin-bottom:15px; }
.req-col  { flex:1; padding:12px 16px; border-right:1px solid var(--border); }
.req-col:last-child { border-right:none; }
.req-col .rc-lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-muted); margin-bottom:4px; }
.req-col .rc-val { font-size:15px; font-weight:700; color:var(--text-main); }
.prio-tags { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
.prio-tag { padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700; }
.prio-tag.critical { background:#fde8e8; color:#c0392b; }
.prio-tag.high     { background:#fdebd0; color:#d35400; }
.prio-tag.medium   { background:#fef9e7; color:#b7950b; }
.prio-tag.low      { background:#e8f8f0; color:#1e8449; }

/* TC & BUG GRIDS */
.tc-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:18px; }
.tc-box  { background:var(--white); border:1.5px solid var(--border); border-radius:12px; padding:16px 10px; text-align:center; box-shadow:0 2px 6px rgba(74,144,217,.05); }
.tc-box .tb-val { font-family:'Poppins',sans-serif; font-weight:800; font-size:26px; color:var(--blue-dark); }
.tc-box .tb-lbl { font-size:12px; color:var(--text-muted); font-weight:600; margin-top:4px; }
.bug-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:18px; }
.bug-card { border-radius:12px; padding:18px 12px; text-align:center; color:#fff; }
.bug-card.c-open     { background:var(--red); }
.bug-card.c-inprog   { background:var(--orange); }
.bug-card.c-resolved { background:#1abc9c; }
.bug-card.c-closed   { background:var(--green); }
.bug-card .bv { font-family:'Poppins',sans-serif; font-weight:800; font-size:28px; }
.bug-card .bl { font-size:12.5px; font-weight:600; margin-top:3px; opacity:.9; }

/* ACCORDION */
.acc-item { background:var(--white); border:1.5px solid var(--border); border-radius:12px; margin-bottom:10px; overflow:hidden; box-shadow:0 2px 8px rgba(74,144,217,.05); }
.acc-hdr  { display:flex; align-items:center; justify-content:space-between; padding:13px 18px; cursor:pointer; user-select:none; transition:background .15s; flex-wrap:wrap; gap:8px; }
.acc-hdr:hover { background:#f5f8fe; }
.acc-left  { display:flex; align-items:center; gap:10px; font-weight:700; font-size:14px; min-width:0; flex:1; }
.acc-right { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.acc-total { color:var(--text-muted); font-size:12px; font-weight:600; }
.acc-tog   { font-size:20px; color:var(--text-muted); width:24px; height:24px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.acc-body  { display:none; border-top:1px solid var(--border); }
.acc-body.open { display:block; }

/* RESPONSIVE — TABLET */
@media (max-width: 768px) {
  :root { --sb-w: 260px; }
  .ham { display: flex; }
  .sidebar { transform: translateX(-100%); }
  .sidebar.open { transform: translateX(0); }
  .sidebar-overlay.open { display: block; }
  .page-wrap { margin-left: 0; }
  .nb-user span:not(.nb-role) { display: none; }
  .nb-role { display: none; }
  .main { padding: 16px 14px 48px; }
  .page-title h1 { font-size: 20px; }
  .toolbar { flex-direction: column; align-items: stretch; }
  .toolbar-right { display: flex; gap: 8px; flex-wrap: wrap; }
  .toolbar-right .btn-export, .toolbar-right .btn-refresh { flex: 1; justify-content: center; }
  .proj-info { flex-direction: column; }
  .proj-info-cell { border-right: none; border-bottom: 1px solid var(--border); }
  .proj-info-cell:last-child { border-bottom: none; }
  .stat-row { grid-template-columns: repeat(2, 1fr); gap: 10px; }
  .stat-card .sc-val { font-size: 28px; }
  .tc-grid { grid-template-columns: repeat(3, 1fr); gap: 8px; }
  .tc-box .tb-val { font-size: 22px; }
  .bug-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .bug-card .bv { font-size: 24px; }
  .req-cols { flex-direction: column; }
  .req-col  { border-right: none; border-bottom: 1px solid var(--border); padding: 10px 16px; }
  .req-col:last-child { border-bottom: none; }
  .acc-right .badge { font-size: 10px; padding: 2px 7px; }
  .global-overview { grid-template-columns: 1fr 1fr; gap: 12px; }
  .summary-stat-row { grid-template-columns: repeat(2, 1fr); }
}

/* RESPONSIVE — PHONE */
@media (max-width: 520px) {
  .navbar { padding: 0 10px; }
  .nblogo { font-size: 17px; }
  .blgout { padding: 6px 10px; font-size: 12px; }
  .main { padding: 14px 10px 40px; }
  .page-title h1 { font-size: 18px; }
  .badge-page { font-size: 9px; padding: 4px 8px; }
  .stat-row { grid-template-columns: 1fr 1fr; gap: 8px; }
  .stat-card { padding: 14px 12px; border-radius: 12px; }
  .stat-card .sc-val { font-size: 26px; }
  .stat-card .sc-label { font-size: 10px; }
  .stat-card .sc-sub { font-size: 10px; }
  .tc-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
  .tc-box { padding: 14px 8px; }
  .tc-box .tb-val { font-size: 22px; }
  .tc-box .tb-lbl { font-size: 11px; }
  .bug-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
  .bug-card { padding: 14px 10px; border-radius: 10px; }
  .bug-card .bv { font-size: 22px; }
  .bug-card .bl { font-size: 11px; }
  .proj-title { padding: 16px; border-radius: 14px; }
  .proj-title h2 { font-size: 16px; }
  .tab-btn { height: 36px; padding: 0 14px; font-size: 12.5px; }
  .prog-bg { height: 10px; }
  .acc-hdr { padding: 10px 14px; }
  .acc-left { font-size: 13px; }
  .acc-right .badge { font-size: 9px; padding: 2px 6px; }
  .prio-tag { font-size: 11px; padding: 3px 10px; }
  .overview-card { padding: 20px; }
  .oc-count { font-size: 28px; }
  .summary-stat-row { grid-template-columns: 1fr 1fr; }
  .summary-stat-card .ssc-val { font-size: 20px; }
}

/* RESPONSIVE — VERY SMALL */
@media (max-width: 380px) {
  .nblogo { font-size: 15px; }
  .page-title h1 { font-size: 16px; }
  .stat-row { grid-template-columns: 1fr 1fr; }
  .stat-card .sc-val { font-size: 22px; }
  .tc-grid { grid-template-columns: 1fr 1fr; }
  .bug-grid { grid-template-columns: 1fr 1fr; }
  .sidebar { width: 230px; }
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
      <span><?= htmlspecialchars($current_user_name ?: 'Guest') ?></span>
      <?php if($current_user_role): ?>
        <span class="nb-role"><?= htmlspecialchars(ucfirst($current_user_role)) ?></span>
      <?php endif; ?>
    </div>
    <a href="../logout.php" class="blgout">Logout</a>
  </div>
</nav>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR — User Pages -->
<aside class="sidebar" id="sidebar">
  <a href="../dash/user_dash.php" class="sb-home">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    Home
  </a>
  <div class="sb-section">Pages</div>
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
  <a href="../user_page/u_project_reports.php" class="sb-link active">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
  </a>
</aside>

<!-- MAIN WRAP -->
<div class="page-wrap" id="pageWrap">
<div class="main">

  <div class="page-title">
    <h1>My Project Reports</h1>
    <span class="badge-page">Analytics</span>
  </div>

  <!-- TOOLBAR -->
  <div class="toolbar">
    <input type="hidden" id="currentProjectId" value="<?= $selected_project ?>"/>
    <div class="toolbar-right">
      <button class="btn-refresh" onclick="clearAllData()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="16" height="16"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        Clear / Refresh
      </button>
      <?php if (!$selected_project): ?>
      <button class="btn-export all-pdf" onclick="downloadAllPDF()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Print All PDF
      </button>
      <button class="btn-export all-excel" onclick="downloadAllExcel()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14" height="14"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
        Export All Excel
      </button>
      <?php endif; ?>
      <?php if ($selected_project > 0): ?>
      <button class="btn-export pdf" onclick="downloadPDF()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Export PDF
      </button>
      <button class="btn-export excel" onclick="downloadExcel()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14" height="14"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
        Export Excel
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- GLOBAL OVERVIEW -->
  <div class="global-overview">
    <div class="overview-card oc-green">
      <span class="oc-count"><?= $global_stats['Active'] ?></span>
      <span class="oc-label">Active Projects</span>
    </div>
    <div class="overview-card oc-grey">
      <span class="oc-count"><?= $global_stats['Inactive'] ?></span>
      <span class="oc-label">Inactive Projects</span>
    </div>
    <div class="overview-card">
      <span class="oc-count"><?= $global_stats['Total'] ?></span>
      <span class="oc-label">My Projects</span>
    </div>
  </div>

  <?php if (!$report_data): ?>

  <!-- ═══════════════════════════════════════════════════
       MY PROJECTS SUMMARY TABLE (No project selected)
       ═══════════════════════════════════════════════════ -->
  <?php
    $tReq = 0; $tTc = 0; $tPass = 0; $tFail = 0; $tBugs = 0;
    foreach ($all_projects_summary as $s) {
        $tReq += $s['req_total']; $tTc += $s['tc_total'];
        $tPass += $s['tc_pass']; $tFail += $s['tc_fail'];
        $tBugs += $s['total_bugs'];
    }
    $tPassRate = $tTc > 0 ? round($tPass / $tTc * 100, 1) : 0;
  ?>

  <div class="all-summary-header">
    <h2>My Projects Summary</h2>
  </div>

  <div class="summary-stat-row">
    <div class="summary-stat-card blue-grad">
      <div class="ssc-val"><?= count($all_projects_summary) ?></div>
      <div class="ssc-lbl">My Projects</div>
    </div>
    <div class="summary-stat-card">
      <div class="ssc-val" style="color:var(--green)"><?= $global_stats['Active'] ?></div>
      <div class="ssc-lbl">Active</div>
    </div>
    <div class="summary-stat-card">
      <div class="ssc-val" style="color:var(--text-muted)"><?= $global_stats['Inactive'] ?></div>
      <div class="ssc-lbl">Inactive</div>
    </div>
    <div class="summary-stat-card">
      <div class="ssc-val"><?= $tReq ?></div>
      <div class="ssc-lbl">Total Reqs</div>
    </div>
    <div class="summary-stat-card">
      <div class="ssc-val"><?= $tTc ?></div>
      <div class="ssc-lbl">Test Cases</div>
    </div>
    <div class="summary-stat-card">
      <div class="ssc-val" style="color:var(--green)"><?= $tPass ?></div>
      <div class="ssc-lbl">Passed</div>
    </div>
    <div class="summary-stat-card">
      <div class="ssc-val" style="color:var(--red)"><?= $tFail ?></div>
      <div class="ssc-lbl">Failed</div>
    </div>
    <div class="summary-stat-card">
      <div class="ssc-val"><?= $tPassRate ?>%</div>
      <div class="ssc-lbl">Pass Rate</div>
    </div>
    <div class="summary-stat-card">
      <div class="ssc-val" style="color:var(--orange)"><?= $tBugs ?></div>
      <div class="ssc-lbl">Total Bugs</div>
    </div>
  </div>

  <div class="table-card">
    <div class="tc-header">My Projects Summary Table</div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>S.NO.</th>
            <th>Project</th>
            <th>Client</th>
            <th>Status</th>
            <th>Active</th>
            <th>Reqs</th>
            <th>TC Total</th>
            <th>Pass</th>
            <th>Fail</th>
            <th>Pass Rate</th>
            <th>Coverage</th>
            <th>Bugs</th>
            <th>Bug Res %</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($all_projects_summary)): ?>
            <tr><td colspan="13" style="text-align:center;padding:30px;color:var(--text-muted);">No projects assigned to you yet.</td></tr>
          <?php else: $i=0; foreach ($all_projects_summary as $s): $i++; ?>
          <tr style="cursor:pointer;" onclick="window.location.href='?filter_project=<?= $s['id'] ?>'">
            <td style="color:var(--text-muted)"><?= $i ?></td>
            <td><b><?= htmlspecialchars($s['name']) ?></b></td>
            <td><?= htmlspecialchars($s['client_name']) ?></td>
            <td><?= htmlspecialchars($s['status']) ?></td>
            <td><span class="badge <?= $s['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $s['is_active'] ? 'Yes' : 'No' ?></span></td>
            <td style="text-align:center"><?= $s['req_total'] ?></td>
            <td style="text-align:center"><?= $s['tc_total'] ?></td>
            <td style="text-align:center;color:var(--green);font-weight:700"><?= $s['tc_pass'] ?></td>
            <td style="text-align:center;color:var(--red);font-weight:700"><?= $s['tc_fail'] ?></td>
            <td style="text-align:center"><?= $s['pass_rate'] ?>%</td>
            <td style="text-align:center"><?= $s['coverage'] ?>%</td>
            <td style="text-align:center"><?= $s['total_bugs'] ?></td>
            <td style="text-align:center"><?= $s['resolution_rate'] ?>%</td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="empty-state" style="padding:20px;margin-top:10px;">
    <p style="color:var(--text-muted);font-size:13px;">Click on any project row above to view its detailed report &amp; summary.</p>
  </div>

  <?php else: extract($report_data); $proj = $project_details; ?>

  <!-- Project Title -->
  <div class="proj-title">
    <h2><?= htmlspecialchars($proj['name']) ?> — Project Report</h2>
    <div class="gen">Updated: <?= date('d F Y') ?> | TestiFy QA Management</div>
  </div>

  <!-- Project Info Bar -->
  <div class="proj-info">
    <div class="proj-info-cell">
      <div class="pi-label">Status</div>
      <div class="pi-val"><?= htmlspecialchars($proj['status'] ?? 'N/A') ?></div>
    </div>
    <div class="proj-info-cell">
      <div class="pi-label">Project Lead</div>
      <div class="pi-val"><?= htmlspecialchars($proj['lead_name']) ?></div>
    </div>
    <div class="proj-info-cell">
      <div class="pi-label">QA Lead</div>
      <div class="pi-val"><?= htmlspecialchars($proj['qa_lead_name']) ?></div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="tabs">
    <button class="tab-btn active" id="tabSummary"  onclick="showTab('summary')">Summary Report</button>
    <button class="tab-btn"        id="tabDetailed" onclick="showTab('detailed')">Detailed Test Cases</button>
  </div>

  <!-- SUMMARY PANE -->
  <div id="paneSummary">

    <div class="stat-row">
      <div class="stat-card blue-grad">
        <div class="sc-label">Total Requirements</div>
        <div class="sc-val"><?= $req_stats['Total'] ?></div>
        <div class="sc-sub">Developed: <?= $req_stats['Developed'] ?> · Tested: <?= $req_stats['Tested'] ?></div>
      </div>
      <div class="stat-card blue-grad">
        <div class="sc-label">Total Test Cases</div>
        <div class="sc-val"><?= $tc_stats['Total'] ?></div>
        <div class="sc-sub">Pass: <?= $tc_stats['Pass'] ?> · Fail: <?= $tc_stats['Fail'] ?></div>
      </div>
      <div class="stat-card">
        <div class="sc-label">Test Coverage</div>
        <div class="sc-val" style="color:var(--blue-dark)"><?= number_format($coverage,0) ?>%</div>
        <div class="sc-sub">Requirements tested</div>
      </div>
      <div class="stat-card">
        <div class="sc-label">Bug Resolution Rate</div>
        <div class="sc-val" style="color:var(--green)"><?= number_format($resolution_rate,1) ?>%</div>
        <div class="sc-sub">Resolved + Closed</div>
      </div>
    </div>

    <div class="table-card">
      <div class="tc-header">Project Progress</div>
      <div style="padding:18px 20px 4px;">
        <div class="prog-wrap">
          <div class="prog-lrow"><span class="pl-name">Requirement Completion</span><span class="pl-pct"><?= number_format($req_completion,0) ?>%</span></div>
          <div class="prog-bg"><div class="prog-fill blue" style="width:<?= number_format($req_completion,0) ?>%"></div></div>
        </div>
        <div class="prog-wrap">
          <div class="prog-lrow"><span class="pl-name">Test Coverage</span><span class="pl-pct"><?= number_format($coverage,0) ?>%</span></div>
          <div class="prog-bg"><div class="prog-fill yellow" style="width:<?= number_format($coverage,0) ?>%"></div></div>
        </div>
      </div>
    </div>

    <div class="req-breakdown">
      <div class="sec-title">Requirements Breakdown</div>
      <div class="req-cols">
        <div class="req-col"><div class="rc-lbl">Developed</div><div class="rc-val"><?= $req_stats['Developed'] ?> / <?= $req_stats['Total'] ?></div></div>
        <div class="req-col"><div class="rc-lbl">Tested</div><div class="rc-val"><?= $req_stats['Tested'] ?> / <?= $req_stats['Total'] ?></div></div>
        <div class="req-col"><div class="rc-lbl">Delivered</div><div class="rc-val"><?= $req_stats['Delivered'] ?> / <?= $req_stats['Total'] ?></div></div>
      </div>
      <div class="sec-title" style="font-size:13px;margin-bottom:8px;">Priority Distribution</div>
      <div class="prio-tags">
        <span class="prio-tag critical">Critical: <?= $req_prio['Critical'] ?></span>
        <span class="prio-tag high">High: <?= $req_prio['High'] ?></span>
        <span class="prio-tag medium">Medium: <?= $req_prio['Medium'] ?></span>
        <span class="prio-tag low">Low: <?= $req_prio['Low'] ?></span>
      </div>
    </div>

    <div class="table-card">
      <div class="tc-header">Test Case Statistics</div>
      <div style="padding:18px 20px;">
        <div class="tc-grid">
          <div class="tc-box"><div class="tb-val"><?= $tc_stats['Pass'] ?></div><div class="tb-lbl">Passed</div></div>
          <div class="tc-box"><div class="tb-val" style="color:var(--red)"><?= $tc_stats['Fail'] ?></div><div class="tb-lbl">Failed</div></div>
          <div class="tc-box"><div class="tb-val" style="color:var(--text-muted)"><?= $tc_stats['Not tested'] ?></div><div class="tb-lbl">Not Tested</div></div>
          <div class="tc-box"><div class="tb-val"><?= $tc_stats['Automated'] ?></div><div class="tb-lbl">Automated</div></div>
          <div class="tc-box"><div class="tb-val"><?= $tc_stats['Manual'] ?></div><div class="tb-lbl">Manual</div></div>
        </div>
      </div>
    </div>

    <div class="table-card">
      <div class="tc-header">Bug Statistics</div>
      <div style="padding:18px 20px;">
        <div class="bug-grid">
          <div class="bug-card c-open">    <div class="bv"><?= $bug_stats['Open'] ?></div>        <div class="bl">Open</div></div>
          <div class="bug-card c-inprog">  <div class="bv"><?= $bug_stats['In Progress'] ?></div> <div class="bl">In Progress</div></div>
          <div class="bug-card c-resolved"><div class="bv"><?= $bug_stats['Resolved'] ?></div>    <div class="bl">Resolved</div></div>
          <div class="bug-card c-closed">  <div class="bv"><?= $bug_stats['Closed'] ?></div>      <div class="bl">Closed</div></div>
        </div>
      </div>
    </div>

  </div><!-- /paneSummary -->

  <!-- DETAILED PANE -->
  <div id="paneDetailed" style="display:none">
    <div class="table-card">
      <div class="tc-header">Detailed Test Cases</div>
      <div style="padding:14px 16px;">
        <?php if (empty($detailed_cases)): ?>
          <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:14px;">No test cases found for this project.</div>
        <?php else: $gi=0; foreach($detailed_cases as $grp): $gi++; ?>

        <div class="acc-item">
          <div class="acc-hdr" onclick="toggleAcc(<?= $gi ?>)">
            <div class="acc-left">
              <span><?= htmlspecialchars($grp['name']) ?></span>
              <span class="prio-badge <?= htmlspecialchars($grp['priority'] ?? 'High') ?>"><?= htmlspecialchars($grp['priority'] ?? 'High') ?></span>
            </div>
            <div class="acc-right">
              <span class="acc-total">Total: <?= $grp['total'] ?></span>
              <span class="badge badge-pass">P: <?= $grp['pass'] ?></span>
              <span class="badge badge-fail">F: <?= $grp['fail'] ?></span>
              <span class="badge badge-nt">NT: <?= $grp['not_tested'] ?></span>
              <span class="acc-tog" id="acc-tog-<?= $gi ?>">+</span>
            </div>
          </div>
          <div class="acc-body" id="acc-body-<?= $gi ?>">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>S.NO.</th>
                    <th>Test Case</th>
                    <th>Status</th>
                    <th>Bug Status</th>
                    <th>Auto</th>
                    <th>Executed By</th>
                    <th>Assigned To</th>
                    <th>Executed On</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $ci=0; foreach($grp['cases'] as $tc): $ci++; ?>
                  <tr>
                    <td style="color:var(--text-muted);font-size:12px;"><?= $ci ?></td>
                    <td style="max-width:320px;"><?= htmlspecialchars($tc['tc_title'] ?? '—') ?></td>
                    <td>
                      <?php $s = $tc['status']; ?>
                      <span class="badge <?= $s==='Pass'?'badge-pass':($s==='Fail'?'badge-fail':'badge-nt') ?>"><?= htmlspecialchars($s) ?></span>
                    </td>
                    <td>
                      <?php if ($tc['bug_raised']): $bs = trim($tc['bug_status'] ?? ''); ?>
                        <span class="badge <?= $bs==='Resolved'?'badge-resolved':($bs==='Closed'?'badge-closed':($bs==='In Progress'?'badge-inprog':'badge-open')) ?>">
                          <?= htmlspecialchars($bs ?: 'Open') ?>
                        </span>
                      <?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
                    </td>
                    <td>
                      <?php if ($tc['is_automated']): ?>
                        <span class="badge badge-pass">Yes</span>
                      <?php else: ?>
                        <span class="badge badge-nt">No</span>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($tc['executed_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($tc['assigned_name'] ?? '—') ?></td>
                    <td style="font-size:12px;color:var(--text-muted);">
                      <?php $eo = $tc['executed_on'] ?? null; echo $eo ? date('d M y, g:i A', strtotime($eo)) : '—'; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <?php endforeach; endif; ?>
      </div>
    </div>
  </div><!-- /paneDetailed -->

  <?php endif; ?>
</div>
</div><!-- end .page-wrap -->

<script>
// ── SIDEBAR ──────────────────────────────────────────
function toggleSidebar(){
  var sb = document.getElementById('sidebar');
  var ov = document.getElementById('sbOverlay');
  var hm = document.getElementById('hamBtn');
  var isOpen = sb.classList.toggle('open');
  ov.classList.toggle('open', isOpen);
  hm.classList.toggle('open', isOpen);
  document.body.style.overflow = (isOpen && window.innerWidth <= 768) ? 'hidden' : '';
}
function closeSidebar(){
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sbOverlay').classList.remove('open');
  document.getElementById('hamBtn').classList.remove('open');
  document.body.style.overflow = '';
}
window.addEventListener('resize', function(){ if(window.innerWidth > 768) closeSidebar(); });

// ── CLEAR / REFRESH ────────────────────────────────
function clearAllData(){
  window.location.href = window.location.pathname;
}

// ── TABS ─────────────────────────────────────────────
function showTab(tab){
  document.getElementById('paneSummary').style.display  = tab==='summary'  ? '' : 'none';
  document.getElementById('paneDetailed').style.display = tab==='detailed' ? '' : 'none';
  document.getElementById('tabSummary').classList.toggle('active',  tab==='summary');
  document.getElementById('tabDetailed').classList.toggle('active', tab==='detailed');
}

// ── ACCORDION ────────────────────────────────────────
function toggleAcc(id){
  var body = document.getElementById('acc-body-'+id);
  var tog  = document.getElementById('acc-tog-'+id);
  var open = body.classList.toggle('open');
  tog.textContent = open ? '\u2212' : '+';
}

// ── EXPORT ───────────────────────────────────────────
function getActiveTabType() {
    if(document.getElementById('tabSummary').classList.contains('active')) return 'summary';
    if(document.getElementById('tabDetailed').classList.contains('active')) return 'detailed';
    return 'summary';
}

function downloadPDF(){
  var pId = document.getElementById('currentProjectId').value;
  if (!pId){ alert('No project selected.'); return; }
  var type = getActiveTabType();
  window.open('?action=export_pdf&project_id='+pId+'&type='+type, '_blank');
}
function downloadExcel(){
  var pId = document.getElementById('currentProjectId').value;
  if (!pId){ alert('No project selected.'); return; }
  var type = getActiveTabType();
  window.location.href = '?action=export_excel&project_id='+pId+'&type='+type;
}

// ── ALL PROJECTS EXPORT ──────────────────────────────
function downloadAllPDF(){
  window.open('?action=export_all_pdf', '_blank');
}
function downloadAllExcel(){
  window.location.href = '?action=export_all_excel';
}
</script>
</body>
</html>
