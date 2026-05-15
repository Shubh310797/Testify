<?php
// ── page/client/project_reports.php ─────────────────────────────
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    include '../config/db.php';
    $conn->query("SELECT 1");
} catch (Exception $e) {
    die("<div style='padding:20px;color:red;font-family:sans-serif;'><h3>DB Error</h3><p>" . $e->getMessage() . "</p></div>");
}

// ══════════════════════════════════════════════════════
//  HELPER FUNCTIONS (Schema Scanner)
// ══════════════════════════════════════════════════════
function get_cols(mysqli $db, string $table): array {
    $cols = [];
    $r = $db->query("DESCRIBE `$table`");
    if ($r) while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];
    return $cols;
}

// CASE-INSENSITIVE pick — matches column names regardless of camelCase / snake_case
function pick(array $cols, array $candidates, ?string $fallback = null): ?string {
    $cols_lower = array_map('strtolower', $cols);
    foreach ($candidates as $c) {
        $idx = array_search(strtolower($c), $cols_lower, true);
        if ($idx !== false) return $cols[$idx];
    }
    return $fallback;
}

// Robust MySQL boolean check — handles 0/1, '0'/'1', true/false, NULL
function isTruthy($val): bool {
    if ($val === null || $val === false) return false;
    if (is_int($val) || is_float($val)) return $val != 0;
    if (is_string($val)) {
        $v = strtolower(trim($val));
        return !in_array($v, ['', '0', 'no', 'false', 'off', 'null'], true);
    }
    return !empty($val);
}

// ══════════════════════════════════════════════════════
//  HELPER: Classify a user role as 'dev', 'qa', or 'admin'
//  ── Returns 'admin' so we can EXCLUDE admins from lists
// ══════════════════════════════════════════════════════
function classifyRole(string $role): ?string {
    $r = strtolower(trim($role));
    // Admin roles — these will be EXCLUDED from Developers & QA Members
    if (in_array($r, ['admin', 'administrator', 'superadmin', 'super admin',
                       'super_admin', 'sysadmin', 'system admin'])) {
        return 'admin';
    }
    if (in_array($r, ['qa', 'tester', 'quality', 'qa engineer', 'test engineer',
                       'quality assurance', 'qa tester', 'qa lead', 'test lead',
                       'testing', 'test analyst', 'qa analyst',
                       'senior qa', 'sr. qa', 'qa manager',
                       'lead qa', 'qa team lead'])) {
        return 'qa';
    }
    if (in_array($r, ['developer', 'dev', 'developers', 'development',
                       'software engineer', 'programmer', 'full stack developer',
                       'frontend developer', 'backend developer', 'sr. developer',
                       'jr. developer', 'lead developer',
                       'tech lead', 'project lead',
                       'dev lead', 'development lead',
                       'technical lead', 'tech lead / developer'])) {
        return 'dev';
    }
    // NOTE: 'lead', 'manager' → pure management roles — excluded from Developers
    if (in_array($r, ['lead', 'manager'])) {
        return 'lead';
    }
    return null; // unknown role — skip
}

// ══════════════════════════════════════════════════════
//  HELPER: Add user to developers/qa arrays with filtering
//  ── Skips admin, lead (pure manager) roles
//  ── 'project lead', 'tech lead' → classified as 'dev' (they also develop)
//  ── 'qa lead', 'test lead' → classified as 'qa' (they also test)
// ══════════════════════════════════════════════════════
function addTeamMember(array &$developers, array &$qa_members, string $uname, string $urole): void {
    $classified = classifyRole($urole);
    // Skip admins and pure management roles (lead/manager only)
    if ($classified === 'admin' || $classified === 'lead') return;
    if ($classified === 'qa') {
        $qa_members[$uname] = true;
    } elseif ($classified === 'dev') {
        $developers[$uname] = true;
    }
    // null = unknown role → skip
}

// ══════════════════════════════════════════════════════
//  HELPER: Get Project Team (ALL Developers & QA Members)
//  ── Combines ALL strategies to find every team member
// ══════════════════════════════════════════════════════
function getProjectTeam(mysqli $conn, int $project_id): array {
    $developers = [];  // name => true  (using keys for dedup)
    $qa_members = [];  // name => true

    // Track QA Lead & Project Lead IDs to EXCLUDE from team lists
    // (they already appear in QA Lead / Project Lead columns)
    $qa_lead_uid    = null;
    $proj_lead_uid  = null;
    $exclude_names  = [];  // names to exclude from final lists

    $user_cols = get_cols($conn, 'users');
    $role_col  = pick($user_cols, ['role', 'user_role', 'user_type', 'type'], 'role');
    $name_col  = pick($user_cols, ['name', 'full_name', 'username', 'display_name'], 'name');

    if (!$name_col || !$role_col) {
        return ['developers' => 'N/A', 'qa_members' => 'N/A'];
    }

    // ══════════════════════════════════════════════════
    //  STRATEGY 0: Projects table (qa_id, project_lead_id, created_by)
    // ══════════════════════════════════════════════════
    $proj_cols = get_cols($conn, 'projects');
    $proj_qa_col     = pick($proj_cols, ['qa_id', 'qa_lead_id', 'qa_lead', 'qa_manager_id', 'tester_lead_id']);
    $proj_lead_col   = pick($proj_cols, ['project_lead_id', 'lead_id', 'manager_id']);
    $proj_created_by = pick($proj_cols, ['created_by', 'created_by_id', 'author_id']);

    if ($proj_qa_col || $proj_lead_col) {
        $proj_sel_parts = [];
        if ($proj_qa_col)     $proj_sel_parts[] = "`$proj_qa_col` AS qa_user_id";
        if ($proj_lead_col)   $proj_sel_parts[] = "`$proj_lead_col` AS lead_user_id";
        if ($proj_created_by) $proj_sel_parts[] = "`$proj_created_by` AS created_user_id";
        $proj_sel_sql = implode(', ', $proj_sel_parts);
        $p_res = $conn->query("SELECT $proj_sel_sql FROM projects WHERE id = $project_id");
        if ($p_res && ($p_row = $p_res->fetch_assoc())) {
            $user_ids = [];
            $qa_uid     = $p_row['qa_user_id'] ?? null;
            $lead_uid   = $p_row['lead_user_id'] ?? null;
            $created_uid = $p_row['created_user_id'] ?? null;

            // Track QA Lead & Project Lead user IDs for exclusion from lists
            if ($qa_uid && $qa_uid != 0)       { $qa_lead_uid   = $qa_uid; }
            if ($lead_uid && $lead_uid != 0)    { $proj_lead_uid = $lead_uid; }

            // qa_id → skip from lists (shown in QA Lead column)
            // project_lead_id → skip from lists (shown in Project Lead column)
            // created_by → add as dev (if not qa_lead or proj_lead)
            if ($created_uid && $created_uid != 0
                && $created_uid != $qa_uid && $created_uid != $lead_uid) {
                $user_ids[$created_uid] = 'dev';
            }

            if (!empty($user_ids)) {
                $uid_list = implode(',', array_keys($user_ids));
                $u_res = $conn->query("SELECT id, `$name_col` AS uname, `$role_col` AS urole FROM users WHERE id IN ($uid_list)");
                if ($u_res) while ($u = $u_res->fetch_assoc()) {
                    // Skip QA Lead & Project Lead (already in their own columns)
                    if ($u['id'] == $qa_lead_uid || $u['id'] == $proj_lead_uid) continue;
                    // Use addTeamMember which handles admin/qa_lead/lead filtering
                    addTeamMember($developers, $qa_members, $u['uname'], $u['urole'] ?? '');
                }
            }
        }
    }

    // ══════════════════════════════════════════════════
    //  STRATEGY 1: Junction tables (project_members, etc.)
    //  ── Check BOTH junction table's role col AND users table role
    // ══════════════════════════════════════════════════
    $junction_tables = ['project_members', 'project_users', 'project_team',
                        'team_members', 'project_assignments',
                        'project_developers', 'project_qa'];

    foreach ($junction_tables as $jt) {
        $check = $conn->query("SHOW TABLES LIKE '$jt'");
        if (!$check || $check->num_rows == 0) continue;

        $jt_cols = get_cols($conn, $jt);
        $jp = pick($jt_cols, ['project_id', 'proj_id', 'project'], 'project_id');
        $ju = pick($jt_cols, ['user_id', 'member_id', 'users_id', 'uid'], 'user_id');
        if (!$jp || !$ju) continue;

        // Check if junction table has its own role/type column
        $jt_role_col = pick($jt_cols, ['role', 'member_role', 'user_role', 'type', 'member_type', 'assignment_type']);

        if ($jt_role_col) {
            // ── Junction table has role column — use it directly ──
            $sql = "SELECT DISTINCT u.`$name_col` AS uname, u.`$role_col` AS urole, pm.`$jt_role_col` AS jt_role
                    FROM `$jt` pm
                    JOIN users u ON pm.`$ju` = u.id
                    WHERE pm.`$jp` = $project_id";
            $res = $conn->query($sql);
            if ($res) while ($row = $res->fetch_assoc()) {
                // First try junction table role, then users table role
                $jt_classified = classifyRole($row['jt_role'] ?? '');
                $u_classified  = classifyRole($row['urole'] ?? '');
                $use_role = ($jt_classified !== null) ? $row['jt_role'] : $row['urole'];
                addTeamMember($developers, $qa_members, $row['uname'], $use_role ?? '');
            }
        } else {
            // ── No role column in junction — rely on users table role ──
            $sql = "SELECT DISTINCT u.`$name_col` AS uname, u.`$role_col` AS urole
                    FROM `$jt` pm
                    JOIN users u ON pm.`$ju` = u.id
                    WHERE pm.`$jp` = $project_id";
            $res = $conn->query($sql);
            if ($res) while ($row = $res->fetch_assoc()) {
                addTeamMember($developers, $qa_members, $row['uname'], $row['urole'] ?? '');
            }
        }
    }

    // ══════════════════════════════════════════════════
    //  STRATEGY 2: Users table with project_id directly
    //  ── Some schemas store project_id in users table
    // ══════════════════════════════════════════════════
    $user_proj_col = pick($user_cols, ['project_id', 'proj_id', 'project']);
    if ($user_proj_col) {
        $sql = "SELECT `$name_col` AS uname, `$role_col` AS urole FROM users WHERE `$user_proj_col` = $project_id";
        $res = $conn->query($sql);
        if ($res) while ($row = $res->fetch_assoc()) {
            addTeamMember($developers, $qa_members, $row['uname'], $row['urole'] ?? '');
        }
    }

    // ══════════════════════════════════════════════════
    //  STRATEGY 3: From test_cases — users who created/executed/assigned
    // ══════════════════════════════════════════════════
    $tc_cols = get_cols($conn, 'test_cases');
    $tc_user_cols = [];
    $c1 = pick($tc_cols, ['created_by', 'created_by_id', 'author_id', 'added_by']);
    $c2 = pick($tc_cols, ['executed_by', 'executed_by_id', 'tester_id', 'tester']);
    $c3 = pick($tc_cols, ['assigned_to', 'assigned_to_id', 'assignee_id', 'assignee']);
    if ($c1) $tc_user_cols[] = $c1;
    if ($c2) $tc_user_cols[] = $c2;
    if ($c3) $tc_user_cols[] = $c3;

    if (!empty($tc_user_cols)) {
        $user_id_selects = [];
        foreach ($tc_user_cols as $uc) {
            $user_id_selects[] = "SELECT DISTINCT `$uc` AS uid FROM test_cases WHERE project_id = $project_id AND `$uc` IS NOT NULL AND `$uc` != 0";
        }
        $union_sql = implode(' UNION ', $user_id_selects);
        $sql = "SELECT u.`$name_col` AS uname, u.`$role_col` AS urole
                FROM users u WHERE u.id IN ($union_sql)";
        $res = $conn->query($sql);
        if ($res) while ($row = $res->fetch_assoc()) {
            addTeamMember($developers, $qa_members, $row['uname'], $row['urole'] ?? '');
        }
    }

    // ══════════════════════════════════════════════════
    //  STRATEGY 4: From requirements — users who created them
    // ══════════════════════════════════════════════════
    $req_cols      = get_cols($conn, 'requirements');
    $req_proj_fk   = pick($req_cols, ['project_id', 'proj_id', 'project']);
    $req_user_col  = pick($req_cols, ['created_by', 'created_by_id', 'author_id', 'added_by', 'developer_id']);
    if ($req_proj_fk && $req_user_col) {
        $sql = "SELECT u.`$name_col` AS uname, u.`$role_col` AS urole
                FROM users u WHERE u.id IN (
                    SELECT DISTINCT `$req_user_col` FROM requirements
                    WHERE `$req_proj_fk` = $project_id AND `$req_user_col` IS NOT NULL AND `$req_user_col` != 0
                )";
        $res = $conn->query($sql);
        if ($res) while ($row = $res->fetch_assoc()) {
            addTeamMember($developers, $qa_members, $row['uname'], $row['urole'] ?? '');
        }
    }

    // ══════════════════════════════════════════════════
    //  STRATEGY 5: Dynamic junction table scan
    //  ── Find ANY table that has both project_id and user_id columns
    // ══════════════════════════════════════════════════
    $all_tables_res = $conn->query("SHOW TABLES");
    $already_checked = array_flip($junction_tables);
    if ($all_tables_res) while ($t_row = $all_tables_res->fetch_array()) {
        $tname = $t_row[0];
        // Skip already-checked tables and system tables
        if (isset($already_checked[$tname])) continue;
        if (in_array($tname, ['projects', 'users', 'clients', 'requirements',
                               'test_cases', 'test_plans', 'technologies',
                               'testing_types', 'migrations', 'sessions'])) continue;

        $t_cols = get_cols($conn, $tname);
        $t_proj = pick($t_cols, ['project_id', 'proj_id', 'project']);
        $t_user = pick($t_cols, ['user_id', 'member_id', 'users_id', 'uid']);
        if (!$t_proj || !$t_user) continue;

        // Found a junction-like table — use it
        $t_role = pick($t_cols, ['role', 'member_role', 'user_role', 'type', 'member_type']);

        if ($t_role) {
            $sql = "SELECT DISTINCT u.`$name_col` AS uname, u.`$role_col` AS urole, jt.`$t_role` AS jt_role
                    FROM `$tname` jt
                    JOIN users u ON jt.`$t_user` = u.id
                    WHERE jt.`$t_proj` = $project_id";
        } else {
            $sql = "SELECT DISTINCT u.`$name_col` AS uname, u.`$role_col` AS urole
                    FROM `$tname` jt
                    JOIN users u ON jt.`$t_user` = u.id
                    WHERE jt.`$t_proj` = $project_id";
        }
        $res = $conn->query($sql);
        if ($res) while ($row = $res->fetch_assoc()) {
            $jt_classified = isset($row['jt_role']) ? classifyRole($row['jt_role']) : null;
            $use_role = ($jt_classified !== null) ? $row['jt_role'] : $row['urole'];
            addTeamMember($developers, $qa_members, $row['uname'], $use_role ?? '');
        }
    }

    // ══════════════════════════════════════════════════
    //  FINAL FILTER: Smart dual-role handling
    //  ── QA Lead: if role is 'qa' → show in QA Members too
    //  ── Project Lead: if role is 'dev' → show in Developers too
    // ══════════════════════════════════════════════════
    if ($qa_lead_uid) {
        $qa_lead_res = $conn->query("SELECT `$name_col` AS uname, `$role_col` AS urole FROM users WHERE id = $qa_lead_uid");
        if ($qa_lead_res && ($qrow = $qa_lead_res->fetch_assoc())) {
            $qa_lead_classified = classifyRole($qrow['urole'] ?? '');
            if ($qa_lead_classified === 'qa') {
                // QA Lead IS also a QA member → ADD to QA Members
                $qa_members[$qrow['uname']] = true;
            } elseif (isset($qa_members[$qrow['uname']])) {
                // Already in QA Members via junction table / strategy → KEEP them
            } else {
                // QA Lead is NOT a QA member → exclude from QA Members
                $exclude_names[$qrow['uname']] = true;
                unset($qa_members[$qrow['uname']]);
            }
        }
    }
    if ($proj_lead_uid) {
        $lead_res = $conn->query("SELECT `$name_col` AS uname, `$role_col` AS urole FROM users WHERE id = $proj_lead_uid");
        if ($lead_res && ($lrow = $lead_res->fetch_assoc())) {
            $lead_classified = classifyRole($lrow['urole'] ?? '');
            if ($lead_classified === 'dev') {
                // Project Lead IS also a developer → ADD to Developers
                $developers[$lrow['uname']] = true;
            } elseif (isset($developers[$lrow['uname']])) {
                // Already in Developers via junction table / strategy → KEEP them
            } else {
                // Project Lead is NOT a developer → exclude from Developers
                $exclude_names[$lrow['uname']] = true;
                unset($developers[$lrow['uname']]);
            }
        }
    }

    return [
        'developers' => implode(', ', array_keys($developers)) ?: 'N/A',
        'qa_members' => implode(', ', array_keys($qa_members)) ?: 'N/A'
    ];
}

// ══════════════════════════════════════════════════════
//  SHARED: Build Summary Data for a given project_id
// ══════════════════════════════════════════════════════
function buildSummaryData(mysqli $conn, int $project_id): ?array {

    $proj_cols = get_cols($conn, 'projects');
    $req_cols  = get_cols($conn, 'requirements');
    $tc_cols   = get_cols($conn, 'test_cases');

    $proj_lead_col    = pick($proj_cols, ['project_lead_id', 'lead_id', 'manager_id']);
    $proj_qa_lead_col = pick($proj_cols, ['qa_id', 'qa_lead_id', 'qa_lead', 'qa_manager_id', 'tester_lead_id']);
    $req_name_col     = pick($req_cols,  ['title', 'name', 'requirement_name', 'req_name', 'module_name', 'module'], 'id');
    $req_proj_fk      = pick($req_cols,  ['project_id', 'proj_id', 'project']);
    $req_is_dev_col   = pick($req_cols,  ['is_developed', 'developed']);
    $req_is_test_col  = pick($req_cols,  ['is_tested', 'tested']);
    $req_is_del_col   = pick($req_cols,  ['is_delivered', 'delivered']);
    $req_uat_col      = pick($req_cols,  ['uat_done', 'is_uat', 'uat']);
    $req_bug_fixed_col= pick($req_cols,  ['bug_fixed', 'is_bug_fixed']);
    $req_bau_col      = pick($req_cols,  ['bug_after_uat', 'bug_after_uat_done', 'uat_bug']);
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
    $tc_custom_id_col = pick($tc_cols,   ['tc_costom_id', 'tc_custom_id', 'custom_id', 'tc_id', 'test_case_id', 'case_id']);
    $tc_created_by_col= pick($tc_cols,   ['created_by', 'created_by_id', 'author_id', 'added_by', 'created_user_id']);
    $tc_created_at_col= pick($tc_cols,   ['created_at', 'date_created', 'created_date', 'added_on', 'date_added']);

    $sql = "SELECT p.*, c.name as client_name";
    if ($proj_lead_col)    $sql .= ", u1.name as lead_name";
    if ($proj_qa_lead_col) $sql .= ", u2.name as qa_lead_name";
    $sql .= " FROM projects p LEFT JOIN clients c ON p.client_id = c.id ";
    if ($proj_lead_col)    $sql .= "LEFT JOIN users u1 ON p.`$proj_lead_col` = u1.id ";
    if ($proj_qa_lead_col) $sql .= "LEFT JOIN users u2 ON p.`$proj_qa_lead_col` = u2.id ";
    $sql .= "WHERE p.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$project) return null;

    $req_stats = ['Total' => 0, 'Developed' => 0, 'Tested' => 0, 'Delivered' => 0, 'UAT' => 0, 'Bug Fixed' => 0, 'Bug After UAT' => 0];
    $req_prio  = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0];
    if ($req_proj_fk) {
        $r_sel = ["priority"];
        if ($req_is_dev_col)    $r_sel[] = "`$req_is_dev_col` AS is_developed";
        if ($req_is_test_col)   $r_sel[] = "`$req_is_test_col` AS is_tested";
        if ($req_is_del_col)    $r_sel[] = "`$req_is_del_col` AS is_delivered";
        if ($req_uat_col)       $r_sel[] = "`$req_uat_col` AS uat_done";
        if ($req_bug_fixed_col) $r_sel[] = "`$req_bug_fixed_col` AS bug_fixed";
        if ($req_bau_col)       $r_sel[] = "`$req_bau_col` AS bug_after_uat";
        $r_sel_sql = implode(', ', $r_sel);
        $r_res = $conn->query("SELECT $r_sel_sql FROM requirements WHERE `$req_proj_fk` = $project_id");
        if ($r_res) while ($r = $r_res->fetch_assoc()) {
            $req_stats['Total']++;
            if (isTruthy($r['is_developed'] ?? null))   $req_stats['Developed']++;
            if (isTruthy($r['is_tested'] ?? null))      $req_stats['Tested']++;
            if (isTruthy($r['is_delivered'] ?? null))   $req_stats['Delivered']++;
            if (isTruthy($r['uat_done'] ?? null))       $req_stats['UAT']++;
            if (isTruthy($r['bug_fixed'] ?? null))      $req_stats['Bug Fixed']++;
            if (isTruthy($r['bug_after_uat'] ?? null))  $req_stats['Bug After UAT']++;
            $prio_key = ucfirst(strtolower($r['priority'] ?? ''));
            if (isset($req_prio[$prio_key]))  $req_prio[$prio_key]++;
        }
    }

    $tc_stats = ['Total' => 0, 'Pass' => 0, 'Fail' => 0, 'Not tested' => 0, 'Automated' => 0, 'Manual' => 0];
    $auto_sel = $tc_is_auto_col ? "`$tc_is_auto_col`" : '0';
    $tc_res = $conn->query("SELECT tc.`$tc_status_col` AS status, $auto_sel AS is_auto FROM test_cases tc WHERE tc.project_id = $project_id");
    if ($tc_res) while ($t = $tc_res->fetch_assoc()) {
        $tc_stats['Total']++;
        if (isset($tc_stats[$t['status']])) $tc_stats[$t['status']]++;
        if (isTruthy($t['is_auto'])) $tc_stats['Automated']++; else $tc_stats['Manual']++;
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

    // Get project team info
    $team = getProjectTeam($conn, $project_id);

    $detailed_cases = [];
    $req_join = ''; $req_sel = "NULL AS req_name, NULL AS req_priority,";
    if ($tc_req_fk) {
        $req_join = "LEFT JOIN requirements r ON tc.`$tc_req_fk` = r.id";
        $req_sel  = "r.`$req_name_col` AS req_name, r.priority AS req_priority,";
    }
    $exec_join = $tc_exec_col   ? "LEFT JOIN users u_exec   ON tc.`$tc_exec_col`   = u_exec.id"   : '';
    $asgn_join = $tc_assign_col ? "LEFT JOIN users u_assign ON tc.`$tc_assign_col` = u_assign.id" : '';
    $crt_join  = $tc_created_by_col ? "LEFT JOIN users u_creater ON tc.`$tc_created_by_col` = u_creater.id" : '';
    $exec_sel  = $tc_exec_col   ? 'u_exec.name   AS executed_name,' : "'' AS executed_name,";
    $asgn_sel  = $tc_assign_col ? 'u_assign.name AS assigned_name'  : "'' AS assigned_name";
    $crt_sel   = $tc_created_by_col ? 'u_creater.name AS created_by_name,' : "'' AS created_by_name,";
    $crt_at_sel = $tc_created_at_col ? "tc.`$tc_created_at_col` AS created_at," : "NULL AS created_at,";
    $title_sel    = $tc_title_col     ? "tc.`$tc_title_col` AS tc_title," : "'' AS tc_title,";
    $custom_id_sel = $tc_custom_id_col ? "tc.`$tc_custom_id_col` AS tc_custom_id," : "'' AS tc_custom_id,";
    $exec_on   = $tc_exec_on_col ? "tc.`$tc_exec_on_col` AS executed_on," : "NULL AS executed_on,";
    $bug_r_sel = $tc_bug_raised  ? "tc.`$tc_bug_raised`  AS bug_raised,"  : "0 AS bug_raised,";
    $bug_s_sel = $tc_bug_status  ? "tc.`$tc_bug_status`  AS bug_status,"  : "'' AS bug_status,";
    $auto_s    = $tc_is_auto_col ? "tc.`$tc_is_auto_col` AS is_automated," : "0 AS is_automated,";
    $order_by  = $tc_req_fk     ? "r.`$req_name_col` ASC, tc.id ASC" : "tc.id ASC";

    $d_sql = "SELECT tc.id, tc.project_id, tc.`$tc_status_col` AS status,
                     $custom_id_sel $title_sel $crt_at_sel $crt_sel $exec_on $bug_r_sel $bug_s_sel $auto_s $req_sel $exec_sel $asgn_sel
              FROM test_cases tc $req_join $exec_join $asgn_join $crt_join
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

    return [
        'project' => $project, 'req_stats' => $req_stats, 'req_prio' => $req_prio,
        'tc_stats' => $tc_stats, 'bug_stats' => $bug_stats,
        'total_bugs' => $total_bugs, 'resolution_rate' => $resolution_rate,
        'req_completion' => $req_completion, 'coverage' => $coverage,
        'detailed_cases' => $detailed_cases,
        'developers' => $team['developers'], 'qa_members' => $team['qa_members'],
    ];
}

// ══════════════════════════════════════════════════════
//  HELPER: Build lightweight summary for all projects
// ══════════════════════════════════════════════════════
function buildAllProjectsSummary(mysqli $conn): array {
    $proj_cols = get_cols($conn, 'projects');
    $req_cols  = get_cols($conn, 'requirements');
    $tc_cols   = get_cols($conn, 'test_cases');

    $proj_lead_col    = pick($proj_cols, ['project_lead_id', 'lead_id', 'manager_id']);
    $proj_qa_lead_col = pick($proj_cols, ['qa_id', 'qa_lead_id', 'qa_lead', 'qa_manager_id', 'tester_lead_id']);
    $active_col       = pick($proj_cols, ['action', 'is_active', 'active'], 'action');
    $req_proj_fk      = pick($req_cols,  ['project_id', 'proj_id', 'project']);
    $req_is_dev_col   = pick($req_cols,  ['is_developed', 'developed']);
    $req_is_test_col  = pick($req_cols,  ['is_tested', 'tested']);
    $req_is_del_col   = pick($req_cols,  ['is_delivered', 'delivered']);
    $req_uat_col      = pick($req_cols,  ['uat_done', 'is_uat', 'uat']);
    $req_bug_fixed_col= pick($req_cols,  ['bug_fixed', 'is_bug_fixed']);
    $req_bau_col      = pick($req_cols,  ['bug_after_uat', 'bug_after_uat_done', 'uat_bug']);
    $tc_status_col    = pick($tc_cols,   ['status', 'result', 'test_result', 'test_status'], 'status');
    $tc_bug_raised    = pick($tc_cols,   ['bug_raised', 'has_bug', 'bug_found', 'is_bug']);
    $tc_bug_status    = pick($tc_cols,   ['bug_status', 'bug_state', 'defect_status']);
    $tc_is_auto_col   = pick($tc_cols,   ['is_automated', 'automated', 'is_auto', 'auto']);

    $sql = "SELECT p.id, p.name, p.status, p.client_id, c.name as client_name";
    if ($proj_lead_col)    $sql .= ", u1.name as lead_name";
    if ($proj_qa_lead_col) $sql .= ", u2.name as qa_lead_name";
    if ($active_col)       $sql .= ", p.`$active_col` as is_active";
    $sql .= " FROM projects p LEFT JOIN clients c ON p.client_id = c.id";
    if ($proj_lead_col)    $sql .= " LEFT JOIN users u1 ON p.$proj_lead_col = u1.id";
    if ($proj_qa_lead_col) $sql .= " LEFT JOIN users u2 ON p.`$proj_qa_lead_col` = u2.id";
    $sql .= " ORDER BY p.name ASC";

    $res = $conn->query($sql);
    $projects = [];
    if ($res) while ($row = $res->fetch_assoc()) $projects[] = $row;

    $summary = [];
    foreach ($projects as $p) {
        $pid = $p['id'];
        $req_total = 0; $req_dev = 0; $req_tested = 0; $req_del = 0; $req_uat = 0; $req_bf = 0; $req_bau = 0;
        if ($req_proj_fk) {
            $r_sel = [];
            if ($req_is_dev_col)    $r_sel[] = "`$req_is_dev_col` AS is_developed";
            if ($req_is_test_col)   $r_sel[] = "`$req_is_test_col` AS is_tested";
            if ($req_is_del_col)    $r_sel[] = "`$req_is_del_col` AS is_delivered";
            if ($req_uat_col)       $r_sel[] = "`$req_uat_col` AS uat_done";
            if ($req_bug_fixed_col) $r_sel[] = "`$req_bug_fixed_col` AS bug_fixed";
            if ($req_bau_col)       $r_sel[] = "`$req_bau_col` AS bug_after_uat";
            $r_sel_sql = $r_sel ? implode(', ', $r_sel) : '1';
            $r_res = $conn->query("SELECT $r_sel_sql FROM requirements WHERE `$req_proj_fk` = $pid");
            if ($r_res) while ($r = $r_res->fetch_assoc()) {
                $req_total++;
                if (isTruthy($r['is_developed'] ?? null))  $req_dev++;
                if (isTruthy($r['is_tested'] ?? null))     $req_tested++;
                if (isTruthy($r['is_delivered'] ?? null))  $req_del++;
                if (isTruthy($r['uat_done'] ?? null))      $req_uat++;
                if (isTruthy($r['bug_fixed'] ?? null))     $req_bf++;
                if (isTruthy($r['bug_after_uat'] ?? null)) $req_bau++;
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

        // Get Project Team (ALL Developers & QA Members)
        $team = getProjectTeam($conn, $pid);

        $summary[] = [
            'id' => $pid, 'name' => $p['name'], 'client_id' => $p['client_id'] ?? 0,
            'client_name' => $p['client_name'] ?? 'N/A', 'status' => $p['status'] ?? 'N/A',
            'is_active' => $is_active, 'lead_name' => $p['lead_name'] ?? 'N/A',
            'qa_lead_name' => $p['qa_lead_name'] ?? 'N/A',
            'developers' => $team['developers'],
            'qa_members' => $team['qa_members'],
            'req_total' => $req_total, 'req_dev' => $req_dev, 'req_tested' => $req_tested, 'req_del' => $req_del, 'req_uat' => $req_uat, 'req_bf' => $req_bf, 'req_bau' => $req_bau,
            'tc_total' => $tc_total, 'tc_pass' => $tc_pass, 'tc_fail' => $tc_fail, 'tc_not_tested' => $tc_nt,
            'bug_open' => $bug_open, 'bug_inprog' => $bug_inprog, 'bug_resolved' => $bug_resolved,
            'bug_closed' => $bug_closed, 'total_bugs' => $total_bugs,
            'resolution_rate' => $resolution_rate, 'coverage' => $coverage, 'pass_rate' => $pass_rate,
        ];
    }
    return $summary;
}

// ══════════════════════════════════════════════════════
//  EXPORT EXCEL  (Colorful Professional Styling)
// ══════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
    $project_id = (int)($_GET['project_id'] ?? 0);
    $type = $_GET['type'] ?? 'summary';
    if (!$project_id) die("Error: No Project ID selected.");
    $data = buildSummaryData($conn, $project_id);
    if (!$data) die("Project not found.");
    extract($data);
    $pname = htmlspecialchars($project['name']);
    $today = date('d F Y');
    $passRate = round($tc_stats['Total'] > 0 ? ($tc_stats['Pass'] / $tc_stats['Total'] * 100) : 0, 1);

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=ProjectReport_{$project_id}.xls");
    header("Pragma: no-cache"); header("Expires: 0");

    $font  = "font-family:Calibri,Arial,sans-serif;";
    $bdr   = "border:1px solid #8faadc;";
    $bdrK  = "border:2px solid #2E75B6;";
    $bdrM  = "border:1px solid #8faadc;";

    $titleBar  = "background:#2E75B6; color:#ffffff; font-size:16pt; font-weight:bold; text-align:center; padding:16px 14px; $font $bdrK";
    $subBar    = "background:#D6E4F0; color:#4472C4; font-size:9pt; padding:8px 14px; $font $bdr text-align:left;";
    $secTitle  = "background:#4472C4; color:#ffffff; font-size:10pt; font-weight:bold; padding:8px 14px; $font $bdrK";
    $infoLbl   = "background:#E2EFDA; color:#375623; font-size:10pt; font-weight:bold; padding:8px 12px; $font $bdr text-align:left; white-space:nowrap;";
    $infoVal   = "background:#ffffff; color:#1a2340; font-size:10pt; padding:8px 12px; $font $bdr text-align:left;";
    $tblHead   = "background:#2E75B6; color:#ffffff; font-size:10pt; font-weight:bold; padding:10px 8px; $font $bdr text-align:center; white-space:nowrap;";
    $cell      = "padding:7px 10px; $font $bdrM font-size:10pt; vertical-align:middle; text-align:left;";
    $cellC     = "padding:7px 10px; $font $bdrM font-size:10pt; text-align:center; vertical-align:middle;";
    $modHead   = "background:#D6E4F0; color:#2E75B6; font-size:10pt; font-weight:bold; padding:10px 14px; $font $bdr;";
    $spacer    = "height:8px;background:#ffffff;$bdrM";

    if ($type == 'detailed') {
        $numCols = 10;
        echo "<table style='border-collapse:collapse;table-layout:auto; $font'>";
        echo "<col width='200'><col width='160'><col width='350'><col width='150'><col width='160'><col width='180'><col width='180'><col width='150'><col width='140'><col width='150'>";

        echo "<tr><td colspan='$numCols' style='$titleBar'>Project Report - $pname</td></tr>";
        echo "<tr><td colspan='$numCols' style='$subBar'>Generated: $today &nbsp;&nbsp;|&nbsp;&nbsp; Testify QA Management System</td></tr>";
        echo "<tr><td colspan='$numCols' style='$spacer'></td></tr>";

        echo "<tr><td colspan='$numCols' style='$secTitle'>- PROJECT INFORMATION</td></tr>";
        echo "<tr>"
            . "<td colspan='1' style='$infoLbl'>Project Name</td>"
            . "<td colspan='2' style='$infoVal;font-weight:bold;color:#2E75B6;'>$pname</td>"
            . "<td colspan='1' style='$infoLbl'>Client</td>"
            . "<td colspan='2' style='$infoVal'>" . htmlspecialchars($project['client_name'] ?? 'N/A') . "</td>"
            . "<td colspan='1' style='$infoLbl'>Status</td>"
            . "<td colspan='2' style='$infoVal'>" . htmlspecialchars($project['status'] ?? 'N/A') . "</td>"
            . "</tr>";
        echo "<tr>"
            . "<td colspan='1' style='$infoLbl'>Project Lead</td>"
            . "<td colspan='2' style='$infoVal'>" . htmlspecialchars($project['lead_name'] ?? 'N/A') . "</td>"
            . "<td colspan='1' style='$infoLbl'>QA Lead</td>"
            . "<td colspan='2' style='$infoVal'>" . htmlspecialchars($project['qa_lead_name'] ?? 'N/A') . "</td>"
            . "<td colspan='1' style='$infoLbl'>Total TC</td>"
            . "<td colspan='2' style='$infoVal;text-align:center;font-weight:bold;color:#2E75B6;font-size:16pt;'>" . $tc_stats['Total'] . "</td>"
            . "</tr>";
        echo "<tr>"
            . "<td colspan='1' style='$infoLbl'>Developers</td>"
            . "<td colspan='4' style='$infoVal;color:#1a73e8;font-weight:600;'>" . htmlspecialchars($developers ?? 'N/A') . "</td>"
            . "<td colspan='1' style='$infoLbl'>QA Members</td>"
            . "<td colspan='4' style='$infoVal;color:#137333;font-weight:600;'>" . htmlspecialchars($qa_members ?? 'N/A') . "</td>"
            . "</tr>";

        echo "<tr><td colspan='$numCols' style='$spacer'></td></tr>";

        echo "<tr><td colspan='$numCols' style='$secTitle'>- SUMMARY OVERVIEW</td></tr>";
        echo "<tr>";
        $kpiStyles = [
            ['val' => $req_stats['Total'], 'lbl' => 'REQS',          'bg' => '#4472C4', 'fg' => '#ffffff'],
            ['val' => $tc_stats['Total'],  'lbl' => 'TOTAL TC',      'bg' => '#2E75B6', 'fg' => '#ffffff'],
            ['val' => $tc_stats['Pass'],   'lbl' => 'PASS',          'bg' => '#548235', 'fg' => '#ffffff'],
            ['val' => $tc_stats['Fail'],   'lbl' => 'FAIL',          'bg' => '#C00000', 'fg' => '#ffffff'],
            ['val' => $tc_stats['Not tested'], 'lbl' => 'NOT TESTED','bg' => '#A5A5A5', 'fg' => '#ffffff'],
            ['val' => $total_bugs,         'lbl' => 'BUGS',          'bg' => '#ED7D31', 'fg' => '#ffffff'],
            ['val' => $coverage.'%',       'lbl' => 'COVERAGE',      'bg' => '#4472C4', 'fg' => '#ffffff'],
            ['val' => $req_completion.'%', 'lbl' => 'REQ COMP',      'bg' => '#4472C4', 'fg' => '#ffffff'],
            ['val' => $resolution_rate.'%','lbl' => 'BUG RES',       'bg' => '#548235', 'fg' => '#ffffff'],
            ['val' => $passRate.'%',       'lbl' => 'PASS RATE',     'bg' => '#548235', 'fg' => '#ffffff'],
        ];
        foreach ($kpiStyles as $kpi) {
            echo "<td style='background:{$kpi['bg']};color:{$kpi['fg']};text-align:center;padding:14px 6px;$font border:2px solid #ffffff;vertical-align:middle;mso-number-format:\\@;'><b style='font-size:16pt;mso-number-format:\\@;'>{$kpi['val']}</b><br/><span style='font-size:7pt;font-weight:bold;letter-spacing:1px;'>{$kpi['lbl']}</span></td>";
        }
        echo "</tr>";

        echo "<tr><td colspan='$numCols' style='$spacer'></td></tr>";

        echo "<tr><td colspan='$numCols' style='$secTitle'>- DETAILED TEST CASES</td></tr>";
        if (empty($detailed_cases)) {
            echo "<tr><td colspan='$numCols' style='$cellC;color:#A5A5A5;font-size:10pt;padding:20px;'>No test cases found.</td></tr>";
        } else {
            foreach ($detailed_cases as $grp) {
                echo "<tr><td colspan='$numCols' style='$modHead'>" . htmlspecialchars($grp['name']) .
                     " &nbsp;&nbsp; <span style='font-size:8pt;color:#5B9BD5;font-weight:normal;'>[Total: {$grp['total']} | Pass: {$grp['pass']} | Fail: {$grp['fail']} | Not Tested: {$grp['not_tested']}]</span></td></tr>";

                echo "<tr>"
                    . "<th style='$tblHead'>S.NO.</th>"
                    . "<th style='$tblHead'>TC-ID</th>"
                    . "<th style='$tblHead'>Test Case Title</th>"
                    . "<th style='$tblHead'>Status</th>"
                    . "<th style='$tblHead'>Bug Status</th>"
                    . "<th style='$tblHead'>Auto</th>"
                    . "<th style='$tblHead'>Created By</th>"
                    . "<th style='$tblHead'>Created At</th>"
                    . "<th style='$tblHead'>Executed By</th>"
                    . "<th style='$tblHead'>Executed On</th>"
                    . "</tr>";

                $ci = 0;
                foreach ($grp['cases'] as $tc) {
                    $ci++;
                    $rowBg = ($ci % 2 == 0) ? '#F2F7FB' : '#ffffff';

                    $s = $tc['status'];
                    if ($s === 'Pass') { $sStyle = "color:#ffffff;font-weight:bold;"; $sBg = "#548235"; }
                    elseif ($s === 'Fail') { $sStyle = "color:#ffffff;font-weight:bold;"; $sBg = "#C00000"; }
                    else { $sStyle = "color:#ffffff;font-weight:bold;"; $sBg = "#A5A5A5"; }

                    $bs = trim($tc['bug_status'] ?? '');
                    if ($bs === 'Resolved') { $bugBg = '#548235'; $bugFg = '#ffffff'; }
                    elseif ($bs === 'Closed') { $bugBg = '#375623'; $bugFg = '#ffffff'; }
                    elseif ($bs === 'In Progress') { $bugBg = '#ED7D31'; $bugFg = '#ffffff'; }
                    elseif ($bs) { $bugBg = '#C00000'; $bugFg = '#ffffff'; }
                    else { $bugBg = $rowBg; $bugFg = '#A5A5A5'; }

                    $crtAt = $tc['created_at'] ?? null;
                    $crtAtStr = $crtAt ? date('d M Y, g:i A', strtotime($crtAt)) : '-';
                    $execOn = $tc['executed_on'] ?? null;
                    $execOnStr = $execOn ? date('d M Y, g:i A', strtotime($execOn)) : '-';
                    $bugDisplay = $tc['bug_raised'] ? htmlspecialchars($bs ?: 'Open') : '-';

                    $autoBg = $tc['is_automated'] ? '#548235' : '#D9E2F3';
                    $autoFg = $tc['is_automated'] ? '#ffffff' : '#4472C4';
                    $autoTxt = $tc['is_automated'] ? 'Yes' : 'No';

                    echo "<tr>";
                    echo "<td style='background:$rowBg; $cellC color:#7F7F7F;'>$ci</td>";
                    echo "<td style='background:$rowBg; $cellC color:#2E75B6;font-weight:bold;'>" . htmlspecialchars($tc['tc_custom_id'] ?? $tc['id'] ?? '-') . "</td>";
                    echo "<td style='background:$rowBg; $cell'>" . htmlspecialchars($tc['tc_title'] ?? '-') . "</td>";
                    echo "<td style='background:$sBg; $cellC $sStyle'>" . htmlspecialchars($s) . "</td>";
                    echo "<td style='background:$bugBg; $cellC color:$bugFg; font-weight:bold;'>$bugDisplay</td>";
                    echo "<td style='background:$autoBg; $cellC color:$autoFg; font-weight:bold;'>$autoTxt</td>";
                    echo "<td style='background:$rowBg; $cell'>" . htmlspecialchars($tc['created_by_name'] ?? '-') . "</td>";
                    echo "<td style='background:$rowBg; $cellC color:#7F7F7F;white-space:nowrap;'>$crtAtStr</td>";
                    echo "<td style='background:$rowBg; $cell'>" . htmlspecialchars($tc['executed_name'] ?? '-') . "</td>";
                    echo "<td style='background:$rowBg; $cellC color:#7F7F7F;white-space:nowrap;'>$execOnStr</td>";
                    echo "</tr>";
                }
            }
        }

    } else {
        // ── SUMMARY EXCEL: Professional layout with 10-column grid ──
        $numCols = 10;
        $colW = 110; // uniform column width
        echo "<table style='border-collapse:collapse;table-layout:fixed; $font'>";
        for ($c = 0; $c < $numCols; $c++) echo "<col width='$colW'>";

        // Helper: KPI row with balanced colspan distribution
        $kpiWide   = "text-align:center;padding:16px 8px;$font border:2px solid #ffffff;vertical-align:middle;mso-number-format:\\@;";
        $kpiNarrow = "text-align:center;padding:16px 6px;$font border:2px solid #ffffff;vertical-align:middle;mso-number-format:\\@;";
        $kpiValBig = "font-size:20pt;mso-number-format:\\@;";
        $kpiValSm  = "font-size:16pt;mso-number-format:\\@;";
        $kpiLblBig = "font-size:7pt;font-weight:bold;letter-spacing:1px;";
        $kpiLblSm  = "font-size:6.5pt;font-weight:bold;letter-spacing:0.5px;";

        // ══════════════════════════════════════════════════
        //  TITLE BAR
        // ══════════════════════════════════════════════════
        echo "<tr><td colspan='$numCols' style='$titleBar'>Project Report - $pname</td></tr>";
        echo "<tr><td colspan='$numCols' style='$subBar'>Generated: $today &nbsp;&nbsp;|&nbsp;&nbsp; Testify QA Management System</td></tr>";
        echo "<tr><td colspan='$numCols' style='$spacer'></td></tr>";

        // ══════════════════════════════════════════════════
        //  PROJECT INFORMATION
        // ══════════════════════════════════════════════════
        echo "<tr><td colspan='$numCols' style='$secTitle'>- PROJECT INFORMATION</td></tr>";
        // Row 1: Project Name(1+2) + Client(1+2) + Status(1+3) = 10
        echo "<tr>"
            . "<td style='$infoLbl'>Project Name</td>"
            . "<td colspan='2' style='$infoVal;font-weight:bold;color:#2E75B6;'>$pname</td>"
            . "<td style='$infoLbl'>Client</td>"
            . "<td colspan='2' style='$infoVal'>" . htmlspecialchars($project['client_name'] ?? 'N/A') . "</td>"
            . "<td style='$infoLbl'>Status</td>"
            . "<td colspan='3' style='$infoVal'>" . htmlspecialchars($project['status'] ?? 'N/A') . "</td>"
            . "</tr>";
        // Row 2: Project Lead(1+2) + QA Lead(1+2) + Total TC(1+3) = 10
        echo "<tr>"
            . "<td style='$infoLbl'>Project Lead</td>"
            . "<td colspan='2' style='$infoVal'>" . htmlspecialchars($project['lead_name'] ?? 'N/A') . "</td>"
            . "<td style='$infoLbl'>QA Lead</td>"
            . "<td colspan='2' style='$infoVal'>" . htmlspecialchars($project['qa_lead_name'] ?? 'N/A') . "</td>"
            . "<td style='$infoLbl'>Total TC</td>"
            . "<td colspan='3' style='$infoVal;text-align:center;font-weight:bold;color:#2E75B6;font-size:16pt;'>" . $tc_stats['Total'] . "</td>"
            . "</tr>";
        // Row 3: Developers(1+4) + QA Members(1+4) = 10
        echo "<tr>"
            . "<td style='$infoLbl'>Developers</td>"
            . "<td colspan='4' style='$infoVal;color:#1a73e8;font-weight:600;'>" . htmlspecialchars($developers ?? 'N/A') . "</td>"
            . "<td style='$infoLbl'>QA Members</td>"
            . "<td colspan='4' style='$infoVal;color:#137333;font-weight:600;'>" . htmlspecialchars($qa_members ?? 'N/A') . "</td>"
            . "</tr>";

        echo "<tr><td colspan='$numCols' style='$spacer'></td></tr>";

        // ══════════════════════════════════════════════════
        //  SUMMARY OVERVIEW (7 KPIs → 3 wide + 4 narrow = 10)
        // ══════════════════════════════════════════════════
        echo "<tr><td colspan='$numCols' style='$secTitle'>- SUMMARY OVERVIEW</td></tr>";
        $sumKpis = [
            ['val' => $req_stats['Total'], 'lbl' => 'TOTAL REQS',    'bg' => '#4472C4', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $tc_stats['Total'],  'lbl' => 'TOTAL TC',      'bg' => '#2E75B6', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $coverage.'%',       'lbl' => 'COVERAGE',      'bg' => '#5B9BD5', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $resolution_rate.'%','lbl' => 'BUG RES',       'bg' => '#548235', 'fg' => '#ffffff', 'cs' => 1],
            ['val' => $req_completion.'%', 'lbl' => 'REQ COMP',      'bg' => '#4472C4', 'fg' => '#ffffff', 'cs' => 1],
            ['val' => $total_bugs,         'lbl' => 'BUGS',          'bg' => '#C00000', 'fg' => '#ffffff', 'cs' => 1],
            ['val' => $passRate.'%',       'lbl' => 'PASS RATE',     'bg' => '#548235', 'fg' => '#ffffff', 'cs' => 1],
        ];
        echo "<tr>";
        foreach ($sumKpis as $kpi) {
            $cs = $kpi['cs'];
            $vs = ($cs >= 2) ? $kpiValBig : $kpiValSm;
            $ls = ($cs >= 2) ? $kpiLblBig : $kpiLblSm;
            $ps = ($cs >= 2) ? 'padding:18px 10px;' : 'padding:16px 6px;';
            echo "<td colspan='$cs' style='background:{$kpi['bg']};color:{$kpi['fg']};text-align:center;{$ps}$font border:2px solid #ffffff;vertical-align:middle;mso-number-format:\\@;'><b style='$vs mso-number-format:\\@;'>{$kpi['val']}</b><br/><span style='$ls'>{$kpi['lbl']}</span></td>";
        }
        echo "</tr>";

        echo "<tr><td colspan='$numCols' style='$spacer'></td></tr>";

        // ══════════════════════════════════════════════════
        //  REQUIREMENTS BREAKDOWN (10 KPIs → 2 rows of 5)
        // ══════════════════════════════════════════════════
        echo "<tr><td colspan='$numCols' style='$secTitle'>- REQUIREMENTS BREAKDOWN</td></tr>";
        // Row 1: Status metrics (5 items, colspan=2 each = 10)
        $reqKpis1 = [
            ['val' => "{$req_stats['Developed']}/{$req_stats['Total']}",  'lbl' => 'DEVELOPED',   'bg' => '#4472C4', 'fg' => '#ffffff'],
            ['val' => "{$req_stats['Tested']}/{$req_stats['Total']}",     'lbl' => 'TESTED',      'bg' => '#5B9BD5', 'fg' => '#ffffff'],
            ['val' => "{$req_stats['Delivered']}/{$req_stats['Total']}",  'lbl' => 'DELIVERED',   'bg' => '#2E75B6', 'fg' => '#ffffff'],
            ['val' => "{$req_stats['UAT']}/{$req_stats['Total']}",        'lbl' => 'UAT',         'bg' => '#ED7D31', 'fg' => '#ffffff'],
            ['val' => "{$req_stats['Bug Fixed']}/{$req_stats['Total']}",  'lbl' => 'BUG FIXED',   'bg' => '#548235', 'fg' => '#ffffff'],
        ];
        echo "<tr>";
        foreach ($reqKpis1 as $kpi) {
            echo "<td colspan='2' style='background:{$kpi['bg']};color:{$kpi['fg']};$kpiWide'><b style='$kpiValSm'>{$kpi['val']}</b><br/><span style='$kpiLblBig'>{$kpi['lbl']}</span></td>";
        }
        echo "</tr>";
        // Row 2: Bug After UAT (2 cols) + Priority distribution (4 items × 2 cols = 8) = 10
        $reqKpis2 = [
            ['val' => "{$req_stats['Bug After UAT']}/{$req_stats['Total']}", 'lbl' => 'BUG AFTER UAT', 'bg' => '#C00000', 'fg' => '#ffffff'],
            ['val' => $req_prio['Critical'],  'lbl' => 'CRITICAL',   'bg' => '#C00000', 'fg' => '#ffffff'],
            ['val' => $req_prio['High'],      'lbl' => 'HIGH',       'bg' => '#ED7D31', 'fg' => '#ffffff'],
            ['val' => $req_prio['Medium'],    'lbl' => 'MEDIUM',     'bg' => '#FFC000', 'fg' => '#1a2340'],
            ['val' => $req_prio['Low'],       'lbl' => 'LOW',        'bg' => '#5B9BD5', 'fg' => '#ffffff'],
        ];
        echo "<tr>";
        foreach ($reqKpis2 as $kpi) {
            echo "<td colspan='2' style='background:{$kpi['bg']};color:{$kpi['fg']};$kpiWide'><b style='$kpiValSm'>{$kpi['val']}</b><br/><span style='$kpiLblBig'>{$kpi['lbl']}</span></td>";
        }
        echo "</tr>";

        echo "<tr><td colspan='$numCols' style='$spacer'></td></tr>";

        // ══════════════════════════════════════════════════
        //  TEST CASE STATISTICS (6 KPIs → 4 wide + 2 narrow = 10)
        // ══════════════════════════════════════════════════
        echo "<tr><td colspan='$numCols' style='$secTitle'>- TEST CASE STATISTICS</td></tr>";
        $tcKpis = [
            ['val' => $tc_stats['Pass'],       'lbl' => 'PASSED',      'bg' => '#548235', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $tc_stats['Fail'],        'lbl' => 'FAILED',      'bg' => '#C00000', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $tc_stats['Not tested'],  'lbl' => 'NOT TESTED',  'bg' => '#A5A5A5', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $tc_stats['Automated'],   'lbl' => 'AUTOMATED',   'bg' => '#4472C4', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $tc_stats['Manual'],      'lbl' => 'MANUAL',      'bg' => '#5B9BD5', 'fg' => '#ffffff', 'cs' => 1],
            ['val' => $passRate.'%',            'lbl' => 'PASS RATE',   'bg' => '#548235', 'fg' => '#ffffff', 'cs' => 1],
        ];
        echo "<tr>";
        foreach ($tcKpis as $kpi) {
            $cs = $kpi['cs'];
            $vs = ($cs >= 2) ? $kpiValBig : $kpiValSm;
            $ls = ($cs >= 2) ? $kpiLblBig : $kpiLblSm;
            $ps = ($cs >= 2) ? 'padding:18px 10px;' : 'padding:16px 6px;';
            echo "<td colspan='$cs' style='background:{$kpi['bg']};color:{$kpi['fg']};text-align:center;{$ps}$font border:2px solid #ffffff;vertical-align:middle;mso-number-format:\\@;'><b style='$vs mso-number-format:\\@;'>{$kpi['val']}</b><br/><span style='$ls'>{$kpi['lbl']}</span></td>";
        }
        echo "</tr>";

        echo "<tr><td colspan='$numCols' style='$spacer'></td></tr>";

        // ══════════════════════════════════════════════════
        //  BUG STATISTICS (6 KPIs → 4 wide + 2 narrow = 10)
        // ══════════════════════════════════════════════════
        echo "<tr><td colspan='$numCols' style='$secTitle'>- BUG STATISTICS</td></tr>";
        $bugKpis = [
            ['val' => $bug_stats['Open'],       'lbl' => 'OPEN',           'bg' => '#C00000', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $bug_stats['In Progress'],'lbl' => 'IN PROGRESS',    'bg' => '#ED7D31', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $bug_stats['Resolved'],   'lbl' => 'RESOLVED',       'bg' => '#548235', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $bug_stats['Closed'],     'lbl' => 'CLOSED',         'bg' => '#375623', 'fg' => '#ffffff', 'cs' => 2],
            ['val' => $total_bugs,              'lbl' => 'TOTAL BUGS',     'bg' => '#C00000', 'fg' => '#ffffff', 'cs' => 1],
            ['val' => $resolution_rate.'%',     'lbl' => 'RES RATE',       'bg' => '#548235', 'fg' => '#ffffff', 'cs' => 1],
        ];
        echo "<tr>";
        foreach ($bugKpis as $kpi) {
            $cs = $kpi['cs'];
            $vs = ($cs >= 2) ? $kpiValBig : $kpiValSm;
            $ls = ($cs >= 2) ? $kpiLblBig : $kpiLblSm;
            $ps = ($cs >= 2) ? 'padding:18px 10px;' : 'padding:16px 6px;';
            echo "<td colspan='$cs' style='background:{$kpi['bg']};color:{$kpi['fg']};text-align:center;{$ps}$font border:2px solid #ffffff;vertical-align:middle;mso-number-format:\\@;'><b style='$vs mso-number-format:\\@;'>{$kpi['val']}</b><br/><span style='$ls'>{$kpi['lbl']}</span></td>";
        }
        echo "</tr>";
    }

    exit;
}

// ══════════════════════════════════════════════════════
//  EXPORT ALL PROJECTS EXCEL  (Colorful Professional)
// ══════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] == 'export_all_excel') {
    $all_summary = buildAllProjectsSummary($conn);
    $today = date('d F Y');
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=AllProjectsSummary_" . date('Ymd') . ".xls");
    header("Pragma: no-cache"); header("Expires: 0");

    $font  = "font-family:Calibri,Arial,sans-serif;";
    $bdr   = "border:1px solid #8faadc;";
    $bdrK  = "border:2px solid #2E75B6;";
    $bdrM  = "border:1px solid #8faadc;";

    $titleBar = "background:#2E75B6; color:#ffffff; font-size:18pt; font-weight:bold; text-align:center; padding:20px 14px; $font $bdrK";
    $subBar   = "background:#D6E4F0; color:#4472C4; font-size:10pt; padding:10px 14px; $font $bdr text-align:left;";
    $secTitle = "background:#4472C4; color:#ffffff; font-size:11pt; font-weight:bold; padding:10px 14px; $font $bdrK";
    $tblHead  = "background:#2E75B6; color:#ffffff; font-size:9.5pt; font-weight:bold; padding:10px 6px; $font $bdr text-align:center; white-space:nowrap;";
    $cell     = "padding:8px 10px; $font $bdrM font-size:9.5pt; vertical-align:middle; text-align:left;";
    $cellC    = "padding:8px 10px; $font $bdrM font-size:9.5pt; text-align:center; vertical-align:middle;";
    $spacer   = "height:10px;background:#ffffff;$bdrM";

    $numCols = 18;

    echo "<table style='border-collapse:collapse;table-layout:auto; $font'>";
    echo "<col width='50'><col width='200'><col width='110'><col width='110'><col width='110'><col width='200'><col width='200'><col width='80'><col width='50'><col width='50'><col width='60'><col width='50'><col width='50'><col width='50'><col width='70'><col width='70'><col width='50'><col width='70'>";

    echo "<tr><td colspan='$numCols' style='$titleBar'>All Projects Summary Report</td></tr>";
    echo "<tr><td colspan='$numCols' style='$subBar'>Generated: $today &nbsp;&nbsp;|&nbsp;&nbsp; Testify QA Management System &nbsp;&nbsp;|&nbsp;&nbsp; Total Projects: " . count($all_summary) . "</td></tr>";
    echo "<tr><td colspan='$numCols' style='$spacer'></td></tr>";

    $tReq=0; $tTc=0; $tPass=0; $tFail=0; $tBugs=0; $tActive=0; $tBugRes=0; $tBugResW=0;
    foreach ($all_summary as $s) {
        $tReq+=$s['req_total']; $tTc+=$s['tc_total']; $tPass+=$s['tc_pass']; $tFail+=$s['tc_fail']; $tBugs+=$s['total_bugs']; if($s['is_active'])$tActive++;
        if($s['total_bugs']>0){ $tBugRes += $s['total_bugs'] * $s['resolution_rate']; $tBugResW += $s['total_bugs']; }
    }
    $tInactive=count($all_summary)-$tActive; $tPassRate=$tTc>0?round($tPass/$tTc*100,1):0;
    $tOverallBugRes = $tBugResW > 0 ? round($tBugRes / $tBugResW, 1) : 0;

    echo "<tr><td colspan='$numCols' style='$secTitle'>- OVERVIEW</td></tr>";
    $ovRow1 = [
        ['val' => count($all_summary), 'lbl' => 'TOTAL PROJECTS', 'bg' => '#4472C4', 'fg' => '#ffffff'],
        ['val' => $tActive,            'lbl' => 'ACTIVE',         'bg' => '#548235', 'fg' => '#ffffff'],
        ['val' => $tInactive,          'lbl' => 'INACTIVE',       'bg' => '#A5A5A5', 'fg' => '#ffffff'],
        ['val' => $tReq,               'lbl' => 'TOTAL REQS',     'bg' => '#2E75B6', 'fg' => '#ffffff'],
        ['val' => $tTc,                'lbl' => 'TOTAL TC',       'bg' => '#5B9BD5', 'fg' => '#ffffff'],
        ['val' => $tPass,              'lbl' => 'PASSED',         'bg' => '#548235', 'fg' => '#ffffff'],
    ];
    echo "<tr>";
    foreach ($ovRow1 as $kpi) {
        echo "<td colspan='3' style='background:{$kpi['bg']};color:{$kpi['fg']};text-align:center;padding:18px 10px;$font border:2px solid #ffffff;vertical-align:middle;mso-number-format:\\@;'><b style='font-size:22pt;mso-number-format:\\@;'>{$kpi['val']}</b><br/><span style='font-size:7pt;font-weight:bold;letter-spacing:1px;'>{$kpi['lbl']}</span></td>";
    }
    echo "</tr>";

    $ovRow2 = [
        ['val' => $tFail,              'lbl' => 'FAILED',         'bg' => '#C00000', 'fg' => '#ffffff'],
        ['val' => $tPassRate.'%',      'lbl' => 'PASS RATE',      'bg' => '#4472C4', 'fg' => '#ffffff'],
        ['val' => $tBugs,              'lbl' => 'TOTAL BUGS',     'bg' => '#ED7D31', 'fg' => '#ffffff'],
        ['val' => $tOverallBugRes.'%', 'lbl' => 'BUG RES %',     'bg' => '#548235', 'fg' => '#ffffff'],
        ['val' => ($tReq>0?round($tPass/$tReq*100,1):0).'%','lbl' => 'COVERAGE','bg' => '#5B9BD5', 'fg' => '#ffffff'],
        ['val' => ($tTc-$tPass-$tFail),'lbl' => 'NOT TESTED',    'bg' => '#8E99B8', 'fg' => '#ffffff'],
    ];
    echo "<tr>";
    foreach ($ovRow2 as $kpi) {
        echo "<td colspan='3' style='background:{$kpi['bg']};color:{$kpi['fg']};text-align:center;padding:18px 10px;$font border:2px solid #ffffff;vertical-align:middle;mso-number-format:\\@;'><b style='font-size:22pt;mso-number-format:\\@;'>{$kpi['val']}</b><br/><span style='font-size:7pt;font-weight:bold;letter-spacing:1px;'>{$kpi['lbl']}</span></td>";
    }
    echo "</tr>";

    echo "<tr><td colspan='$numCols' style='$spacer'></td></tr>";

    echo "<tr><td colspan='$numCols' style='$secTitle'>- PROJECT DETAILS</td></tr>";
    echo "<tr>"
        . "<th style='$tblHead'>S.NO.</th>"
        . "<th style='$tblHead'>Project</th>"
        . "<th style='$tblHead'>Client</th>"
        . "<th style='$tblHead'>QA Lead</th>"
        . "<th style='$tblHead'>Project Lead</th>"
        . "<th style='background:#4472C4; color:#ffffff; font-size:9.5pt; font-weight:bold; padding:10px 6px; $font $bdr text-align:center; white-space:nowrap;'>Developers</th>"
        . "<th style='background:#548235; color:#ffffff; font-size:9.5pt; font-weight:bold; padding:10px 6px; $font $bdr text-align:center; white-space:nowrap;'>QA Members</th>"
        . "<th style='$tblHead'>Status</th>"
        . "<th style='$tblHead'>Active</th>"
        . "<th style='$tblHead'>Reqs</th>"
        . "<th style='$tblHead'>TC Total</th>"
        . "<th style='$tblHead'>Pass</th>"
        . "<th style='$tblHead'>Fail</th>"
        . "<th style='$tblHead'>NT</th>"
        . "<th style='$tblHead'>Pass Rate</th>"
        . "<th style='$tblHead'>Coverage</th>"
        . "<th style='$tblHead'>Bugs</th>"
        . "<th style='$tblHead'>Bug Res %</th>"
        . "</tr>";

    $i=0;
    foreach($all_summary as $s) {
        $i++;
        $rowBg = ($i % 2 == 0) ? '#F2F7FB' : '#ffffff';
        $devBg = ($i % 2 == 0) ? '#DCE8FC' : '#E8F0FE';
        $qaBg  = ($i % 2 == 0) ? '#D6ECD8' : '#E6F4EA';

        $actBg = $s['is_active'] ? '#548235' : '#A5A5A5';
        $actFg = '#ffffff';
        $actTxt = $s['is_active'] ? 'Yes' : 'No';

        $st = strtolower($s['status']);
        if ($st === 'active' || $st === 'in progress') { $stBg = '#548235'; $stFg = '#ffffff'; }
        elseif ($st === 'completed' || $st === 'closed') { $stBg = '#2E75B6'; $stFg = '#ffffff'; }
        elseif ($st === 'on hold') { $stBg = '#ED7D31'; $stFg = '#ffffff'; }
        else { $stBg = $rowBg; $stFg = '#1a2340'; }

        $pr = $s['pass_rate'];
        if ($pr >= 80) { $prBg = '#548235'; $prFg = '#ffffff'; }
        elseif ($pr >= 50) { $prBg = '#ED7D31'; $prFg = '#ffffff'; }
        elseif ($pr > 0) { $prBg = '#C00000'; $prFg = '#ffffff'; }
        else { $prBg = $rowBg; $prFg = '#7F7F7F'; }

        $cv = $s['coverage'];
        if ($cv >= 80) { $cvBg = '#548235'; $cvFg = '#ffffff'; }
        elseif ($cv >= 50) { $cvBg = '#ED7D31'; $cvFg = '#ffffff'; }
        elseif ($cv > 0) { $cvBg = '#C00000'; $cvFg = '#ffffff'; }
        else { $cvBg = $rowBg; $cvFg = '#7F7F7F'; }

        $br = $s['resolution_rate'];
        if ($br >= 80) { $brBg = '#548235'; $brFg = '#ffffff'; }
        elseif ($br >= 50) { $brBg = '#ED7D31'; $brFg = '#ffffff'; }
        elseif ($br > 0) { $brBg = '#C00000'; $brFg = '#ffffff'; }
        else { $brBg = $rowBg; $brFg = '#7F7F7F'; }

        echo "<tr>";
        echo "<td style='background:$rowBg; $cellC color:#7F7F7F;'>$i</td>";
        echo "<td style='background:$rowBg; $cell color:#2E75B6;font-weight:bold;'>" . htmlspecialchars($s['name']) . "</td>";
        echo "<td style='background:$rowBg; $cell'>" . htmlspecialchars($s['client_name']) . "</td>";
        echo "<td style='background:$rowBg; $cell color:#7C3AED;'>" . htmlspecialchars($s['qa_lead_name']) . "</td>";
        echo "<td style='background:$rowBg; $cell color:#3A7BD5;'>" . htmlspecialchars($s['lead_name']) . "</td>";
        echo "<td style='background:$devBg; $cell color:#1a73e8;font-weight:600;'>" . htmlspecialchars($s['developers']) . "</td>";
        echo "<td style='background:$qaBg; $cell color:#137333;font-weight:600;'>" . htmlspecialchars($s['qa_members']) . "</td>";
        echo "<td style='background:$stBg; $cellC color:$stFg; font-weight:bold;'>" . htmlspecialchars($s['status']) . "</td>";
        echo "<td style='background:$actBg; $cellC color:$actFg; font-weight:bold;'>$actTxt</td>";
        echo "<td style='background:$rowBg; $cellC font-weight:bold;'>{$s['req_total']}</td>";
        echo "<td style='background:$rowBg; $cellC font-weight:bold;color:#2E75B6;'>{$s['tc_total']}</td>";
        echo "<td style='background:$rowBg; $cellC color:#548235;font-weight:bold;'>{$s['tc_pass']}</td>";
        echo "<td style='background:$rowBg; $cellC color:#C00000;font-weight:bold;'>{$s['tc_fail']}</td>";
        echo "<td style='background:$rowBg; $cellC color:#7F7F7F;font-weight:bold;'>{$s['tc_not_tested']}</td>";
        echo "<td style='background:$prBg; $cellC color:$prFg; font-weight:bold;'>{$s['pass_rate']}%</td>";
        echo "<td style='background:$cvBg; $cellC color:$cvFg; font-weight:bold;'>{$s['coverage']}%</td>";
        echo "<td style='background:$rowBg; $cellC color:#ED7D31;font-weight:bold;'>{$s['total_bugs']}</td>";
        echo "<td style='background:$brBg; $cellC color:$brFg; font-weight:bold;'>{$s['resolution_rate']}%</td>";
        echo "</tr>";
    }

    exit;
}

// ══════════════════════════════════════════════════════
//  EXPORT ALL PROJECTS PDF  (Print-to-PDF)
// ══════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] == 'export_all_pdf') {
    $all_summary = buildAllProjectsSummary($conn);
    $today = date('d F Y');

    $tReq=0; $tTc=0; $tPass=0; $tFail=0; $tBugs=0; $tActive=0; $tBugRes=0; $tBugResW=0;
    foreach ($all_summary as $s) {
        $tReq+=$s['req_total']; $tTc+=$s['tc_total']; $tPass+=$s['tc_pass']; $tFail+=$s['tc_fail']; $tBugs+=$s['total_bugs']; if($s['is_active'])$tActive++;
        if($s['total_bugs']>0){ $tBugRes += $s['total_bugs'] * $s['resolution_rate']; $tBugResW += $s['total_bugs']; }
    }
    $tInactive=count($all_summary)-$tActive; $tPassRate=$tTc>0?round($tPass/$tTc*100,1):0;
    $tOverallBugRes = $tBugResW > 0 ? round($tBugRes / $tBugResW, 1) : 0;

    header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"/>
<title>All Projects Summary Report</title>
<style>
  @page { size: A4 landscape; margin: 5mm; }
  @media print {
    .no-print { display:none!important; }
    body { margin:0; -webkit-print-color-adjust:exact; print-color-adjust:exact; font-size:7pt!important; }
    tr,th,td { page-break-inside:avoid; page-break-after:auto; }
  }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:'Segoe UI',Arial,sans-serif; font-size:8pt; color:#1a2340; background:#fff; }
  .no-print { position:fixed; top:10px; right:10px; display:flex; gap:5px; z-index:999; }
  .btn-print { background:#3a7bd5; color:#fff; border:none; padding:8px 15px; border-radius:4px; font-size:10pt; font-weight:700; cursor:pointer; }
  .btn-close { background:#e74c3c; color:#fff; border:none; padding:8px 12px; border-radius:4px; font-size:10pt; font-weight:700; cursor:pointer; }
  .header { background:linear-gradient(135deg,#2E75B6 0%,#4472C4 50%,#3a7bd5 100%); color:#fff; padding:10px 14px; border-radius:6px; margin-bottom:6px; }
  .header h1 { font-size:14pt; font-weight:800; margin:0; line-height:1.2; }
  .header .sub { font-size:9pt; opacity:.9; margin-top:2px; }
  .header .gen { font-size:8pt; opacity:.8; margin-top:2px; }
  .sec-title { font-size:9pt; font-weight:800; color:#3a7bd5; text-transform:uppercase; border-left:2px solid #3a7bd5; padding-left:5px; margin:8px 0 4px; }
  .kpi-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:4px; margin-bottom:6px; }
  .kpi-box { border-radius:4px; padding:8px 6px; text-align:center; color:#fff; }
  .kpi-box .kv { font-size:16pt; font-weight:800; line-height:1; }
  .kpi-box .kl { font-size:6pt; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-top:2px; display:block; opacity:.9; }
  .kpi-blue { background:#4472C4; }
  .kpi-green { background:#548235; }
  .kpi-red { background:#C00000; }
  .kpi-orange { background:#ED7D31; }
  .kpi-grey { background:#8E99B8; }
  .kpi-teal { background:#2E75B6; }
  .kpi-light { background:#5B9BD5; }
  table.sum-table { width:100%; border-collapse:collapse; font-size:6.5pt; margin-top:4px; }
  table.sum-table thead th { padding:5px 4px; text-align:center; font-weight:700; color:#fff; white-space:nowrap; border:1px solid #1F4E79; }
  table.sum-table tbody td { padding:4px 4px; border:1px solid #dde4f0; vertical-align:middle; }
  table.sum-table tbody tr:nth-child(even) td { background:#f8faff; }
  .th-main { background:#2E75B6; }
  .th-dev { background:#4472C4; }
  .th-qa { background:#548235; }
  .badge-yes { background:#548235; color:#fff; padding:1px 6px; border-radius:8px; font-weight:700; display:inline-block; }
  .badge-no { background:#A5A5A5; color:#fff; padding:1px 6px; border-radius:8px; font-weight:700; display:inline-block; }
  .dev-cell { background:#E8F0FE; color:#1a73e8; font-weight:600; }
  .qa-cell { background:#E6F4EA; color:#137333; font-weight:600; }
  .rate-good { background:#548235; color:#fff; font-weight:700; }
  .rate-mid { background:#ED7D31; color:#fff; font-weight:700; }
  .rate-bad { background:#C00000; color:#fff; font-weight:700; }
  .rate-none { background:#f0f5fd; color:#6b7fa3; font-weight:600; }
</style>
</head>
<body>
<div class="no-print"><button class="btn-print" onclick="window.print()">Print / Save as PDF</button><button class="btn-close" onclick="window.close()">Close</button></div>
<div class="header">
  <h1>All Projects Summary Report</h1>
  <div class="sub">Testify QA Management System</div>
  <div class="gen">Generated: <?= $today ?> | Total Projects: <?= count($all_summary) ?></div>
</div>

<div class="sec-title">Overview</div>
<div class="kpi-grid">
  <div class="kpi-box kpi-blue"><div class="kv"><?= count($all_summary) ?></div><span class="kl">Total Projects</span></div>
  <div class="kpi-box kpi-green"><div class="kv"><?= $tActive ?></div><span class="kl">Active</span></div>
  <div class="kpi-box kpi-grey"><div class="kv"><?= $tInactive ?></div><span class="kl">Inactive</span></div>
  <div class="kpi-box kpi-teal"><div class="kv"><?= $tReq ?></div><span class="kl">Total Reqs</span></div>
  <div class="kpi-box kpi-light"><div class="kv"><?= $tTc ?></div><span class="kl">Total TC</span></div>
  <div class="kpi-box kpi-green"><div class="kv"><?= $tPass ?></div><span class="kl">Passed</span></div>
  <div class="kpi-box kpi-red"><div class="kv"><?= $tFail ?></div><span class="kl">Failed</span></div>
  <div class="kpi-box kpi-blue"><div class="kv"><?= $tPassRate ?>%</div><span class="kl">Pass Rate</span></div>
  <div class="kpi-box kpi-orange"><div class="kv"><?= $tBugs ?></div><span class="kl">Total Bugs</span></div>
  <div class="kpi-box kpi-green"><div class="kv"><?= $tOverallBugRes ?>%</div><span class="kl">Bug Res %</span></div>
  <div class="kpi-box kpi-light"><div class="kv"><?= ($tReq>0?round($tPass/$tReq*100,1):0) ?>%</div><span class="kl">Coverage</span></div>
  <div class="kpi-box kpi-grey"><div class="kv"><?= ($tTc-$tPass-$tFail) ?></div><span class="kl">Not Tested</span></div>
</div>

<div class="sec-title">Project Details</div>
<table class="sum-table">
  <thead><tr>
    <th class="th-main">S.NO.</th>
    <th class="th-main">Project</th>
    <th class="th-main">Client</th>
    <th class="th-main">QA Lead</th>
    <th class="th-main">Project Lead</th>
    <th class="th-dev">Developers</th>
    <th class="th-qa">QA Members</th>
    <th class="th-main">Status</th>
    <th class="th-main">Active</th>
    <th class="th-main">Reqs</th>
    <th class="th-main">TC Total</th>
    <th class="th-main">Pass</th>
    <th class="th-main">Fail</th>
    <th class="th-main">NT</th>
    <th class="th-main">Pass Rate</th>
    <th class="th-main">Coverage</th>
    <th class="th-main">Bugs</th>
    <th class="th-main">Bug Res %</th>
  </tr></thead>
  <tbody>
  <?php $i=0; foreach($all_summary as $s): $i++;
      $prClass = $s['pass_rate']>=80?'rate-good':($s['pass_rate']>=50?'rate-mid':($s['pass_rate']>0?'rate-bad':'rate-none'));
      $cvClass = $s['coverage']>=80?'rate-good':($s['coverage']>=50?'rate-mid':($s['coverage']>0?'rate-bad':'rate-none'));
      $brClass = $s['resolution_rate']>=80?'rate-good':($s['resolution_rate']>=50?'rate-mid':($s['resolution_rate']>0?'rate-bad':'rate-none'));
  ?>
    <tr>
      <td style="text-align:center;color:#6b7fa3"><?= $i ?></td>
      <td style="font-weight:700;color:#2E75B6"><?= htmlspecialchars($s['name']) ?></td>
      <td><?= htmlspecialchars($s['client_name']) ?></td>
      <td style="color:#7C3AED"><?= htmlspecialchars($s['qa_lead_name']) ?></td>
      <td style="color:#3A7BD5"><?= htmlspecialchars($s['lead_name']) ?></td>
      <td class="dev-cell"><?= htmlspecialchars($s['developers']) ?></td>
      <td class="qa-cell"><?= htmlspecialchars($s['qa_members']) ?></td>
      <td style="text-align:center"><?= htmlspecialchars($s['status']) ?></td>
      <td style="text-align:center"><?php if($s['is_active']): ?><span class="badge-yes">Yes</span><?php else: ?><span class="badge-no">No</span><?php endif; ?></td>
      <td style="text-align:center"><?= $s['req_total'] ?></td>
      <td style="text-align:center;font-weight:700;color:#2E75B6"><?= $s['tc_total'] ?></td>
      <td style="text-align:center;color:#548235;font-weight:700"><?= $s['tc_pass'] ?></td>
      <td style="text-align:center;color:#C00000;font-weight:700"><?= $s['tc_fail'] ?></td>
      <td style="text-align:center;color:#6b7fa3;font-weight:700"><?= $s['tc_not_tested'] ?></td>
      <td class="<?= $prClass ?>" style="text-align:center"><?= $s['pass_rate'] ?>%</td>
      <td class="<?= $cvClass ?>" style="text-align:center"><?= $s['coverage'] ?>%</td>
      <td style="text-align:center;color:#ED7D31;font-weight:700"><?= $s['total_bugs'] ?></td>
      <td class="<?= $brClass ?>" style="text-align:center"><?= $s['resolution_rate'] ?>%</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</body></html>
<?php exit; }

// ══════════════════════════════════════════════════════
//  EXPORT PDF (Compact One-Pager)
// ══════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] == 'export_pdf') {
    $project_id = (int)($_GET['project_id'] ?? 0); $type = $_GET['type'] ?? 'summary';
    if (!$project_id) die("Error: No Project ID selected.");
    $data = buildSummaryData($conn, $project_id); if (!$data) die("Project not found."); extract($data);
    $pname = htmlspecialchars($project['name']); $today = date('d F Y');
    $grouped = []; foreach ($detailed_cases as $grp) { $grouped[$grp['name']] = $grp; }
    header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"/>
<title>Project Report - <?= $pname ?></title>
<link rel="stylesheet" href="../css/pr.css">
</head>
<body>
<div class="no-print"><button class="btn-print" onclick="window.print()">Print / Save as PDF</button><button class="btn-close" onclick="window.close()">Close</button></div>
<div class="header"><h1>Project Report - <?= $pname ?></h1><div class="sub">Client: <?= htmlspecialchars($project['client_name'] ?? 'N/A') ?></div><div class="gen">Generated: <?= $today ?> | Testify QA Management</div></div>
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
<div class="prog-row"><div class="prog-top"><span>Req Completion: <?= $req_completion ?>%</span></div><div class="prog-bg"><div class="prog-fill" style="width:<?= $req_completion ?>%;background:#3a7bd5;"></div></div></div>
<div class="prog-row"><div class="prog-top"><span>Test Coverage: <?= $coverage ?>%</span></div><div class="prog-bg"><div class="prog-fill" style="width:<?= $coverage ?>%;background:#f1c40f;"></div></div></div>
<div style="display:flex;gap:4px;margin-bottom:6px;margin-top:8px;">
  <div style="flex:1;border:1px solid #dde4f0;border-radius:4px;padding:6px;background:#E8F0FE;">
    <div style="font-size:9pt;font-weight:700;color:#1a73e8;margin-bottom:2px;">Developers</div>
    <div style="font-size:10pt;color:#1a2340;font-weight:600;"><?= htmlspecialchars($developers ?? 'N/A') ?></div>
  </div>
  <div style="flex:1;border:1px solid #dde4f0;border-radius:4px;padding:6px;background:#E6F4EA;">
    <div style="font-size:9pt;font-weight:700;color:#137333;margin-bottom:2px;">QA Members</div>
    <div style="font-size:10pt;color:#1a2340;font-weight:600;"><?= htmlspecialchars($qa_members ?? 'N/A') ?></div>
  </div>
</div>
<div style="display:flex;gap:4px;margin-bottom:6px;margin-top:8px;">
  <div style="flex:1;border:1px solid #dde4f0;border-radius:4px;padding:4px;">
    <div class="sec-title">Requirements</div>
    <div class="req-grid" style="margin-bottom:0;">
      <div class="req-box"><div class="rv"><?= $req_stats['Developed'] ?>/<?= $req_stats['Total'] ?></div><span class="rl">Dev</span></div>
      <div class="req-box"><div class="rv"><?= $req_stats['Tested'] ?>/<?= $req_stats['Total'] ?></div><span class="rl">Test</span></div>
      <div class="req-box"><div class="rv"><?= $req_stats['Delivered'] ?>/<?= $req_stats['Total'] ?></div><span class="rl">Del</span></div>
      <div class="req-box"><div class="rv"><?= $req_stats['UAT'] ?>/<?= $req_stats['Total'] ?></div><span class="rl">UAT</span></div>
      <div class="req-box"><div class="rv"><?= $req_stats['Bug Fixed'] ?>/<?= $req_stats['Total'] ?></div><span class="rl">Bug Fix</span></div>
      <div class="req-box"><div class="rv"><?= $req_stats['Bug After UAT'] ?>/<?= $req_stats['Total'] ?></div><span class="rl">Bug After UAT</span></div>
    </div>
    <div style="display:flex;gap:3px;margin-top:2px;">
      <span class="ptag critical">Crit: <?= $req_prio['Critical'] ?></span><span class="ptag high">High: <?= $req_prio['High'] ?></span><span class="ptag medium">Med: <?= $req_prio['Medium'] ?></span><span class="ptag low" style="background:#5B9BD5;color:#fff;padding:2px 8px;border-radius:10px;font-size:8pt;font-weight:700;">Low: <?= $req_prio['Low'] ?></span>
    </div>
  </div>
  <div style="flex:1.5;border:1px solid #dde4f0;border-radius:4px;padding:4px;">
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
      <div class="bug-box nt"><div class="bv"><?= $tc_stats['Not tested'] ?></div><span class="bl">Not Tested</span></div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($type == 'detailed'): ?>
<div class="sec-title">Detailed Test Cases</div>
<?php if (empty($grouped)): ?><p style="color:#6b7fa3;text-align:center;padding:10px;">No test cases found.</p>
<?php else: foreach ($grouped as $module => $grp): ?>
<div class="mod-head"><span><?= htmlspecialchars($module) ?></span><div class="mod-stats"><span class="badge b-pass">P: <?= $grp['pass'] ?></span><span class="badge b-fail">F: <?= $grp['fail'] ?></span><span class="badge b-nt">NT: <?= $grp['not_tested'] ?></span></div></div>
<table class="detailed-table"><thead><tr><th style="width:4%">S.NO.</th><th style="width:6%">TC-ID</th><th style="width:25%">Test Case Title</th><th style="width:7%">Status</th><th style="width:9%">Bug Status</th><th style="width:5%">Auto</th><th style="width:9%">Created By</th><th style="width:9%">Created At</th><th style="width:9%">Executed By</th><th style="width:9%">Assigned To</th><th style="width:8%">Executed On</th></tr></thead><tbody>
<?php $ci=0; foreach($grp['cases'] as $tc): $ci++; $s=$tc['status']; $sc=$s==='Pass'?'b-pass':($s==='Fail'?'b-fail':'b-nt'); $bs=trim($tc['bug_status']??''); $bc=$bs==='Resolved'?'b-resolved':($bs==='Closed'?'b-closed':($bs==='In Progress'?'b-inprog':'b-open')); $eo=$tc['executed_on']??null; $ca=$tc['created_at']??null; ?>
<tr><td style="color:#6b7fa3"><?= $ci ?></td><td style="color:#3a7bd5;font-weight:700"><?= htmlspecialchars($tc['tc_custom_id'] ?? $tc['id'] ?? '—') ?></td><td><?= htmlspecialchars($tc['tc_title']??'—') ?></td><td><span class="badge <?= $sc ?>"><?= htmlspecialchars($s) ?></span></td><td><?php if($tc['bug_raised']): ?><span class="badge <?= $bc ?>"><?= htmlspecialchars($bs?:'Open') ?></span><?php else: ?>—<?php endif; ?></td><td><span class="badge <?= $tc['is_automated']?'b-yes':'b-no' ?>"><?= $tc['is_automated']?'Y':'N' ?></span></td><td><?= htmlspecialchars($tc['created_by_name']??'—') ?></td><td style="color:#6b7fa3"><?= $ca?date('d M y',strtotime($ca)):'—' ?></td><td><?= htmlspecialchars($tc['executed_name']??'—') ?></td><td><?= htmlspecialchars($tc['assigned_name']??'—') ?></td><td style="color:#6b7fa3"><?= $eo?date('d M y',strtotime($eo)):'—' ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php endforeach; endif; endif; ?>
</body></html>
<?php exit; }


// ══════════════════════════════════════════════════════
//  MAIN PAGE LOGIC
// ══════════════════════════════════════════════════════
 $selected_client  = (int)($_GET['filter_client']  ?? 0);
 $selected_project = (int)($_GET['filter_project'] ?? 0);
 $report_data      = null;

 $proj_cols_check = get_cols($conn, 'projects');
 $active_col      = pick($proj_cols_check, ['action', 'is_active', 'active'], 'action');
 $global_stats = ['Active' => 0, 'Inactive' => 0, 'Total' => 0];
 if ($active_col) {
     $sql_scope = "SELECT `$active_col` as is_active FROM projects";
     if ($selected_client > 0) $sql_scope = "SELECT `$active_col` as is_active FROM projects WHERE client_id = $selected_client";
 } else {
     $sql_scope = "SELECT status FROM projects";
     if ($selected_client > 0) $sql_scope = "SELECT status FROM projects WHERE client_id = $selected_client";
 }
 $res_scope = $conn->query($sql_scope);
 if ($res_scope) { while($row = $res_scope->fetch_assoc()) { $global_stats['Total']++;
     if ($active_col) { $val=$row['is_active']; if($val==1||$val==='active'||$val==='1')$global_stats['Active']++; else $global_stats['Inactive']++; }
     else { if(strtolower($row['status'])=='active')$global_stats['Active']++; else $global_stats['Inactive']++; }
 } }

 $all_projects_summary = buildAllProjectsSummary($conn);

if ($selected_project > 0) {
    $raw = buildSummaryData($conn, $selected_project);
    if ($raw) {
        $project_details = $raw['project'];
        $project_details['lead_name']    = $raw['project']['lead_name'] ?? 'N/A';
        $project_details['qa_lead_name'] = $raw['project']['qa_lead_name'] ?? 'N/A';
        $report_data = [
            'project_details' => $project_details, 'req_stats' => $raw['req_stats'],
            'req_prio' => $raw['req_prio'], 'tc_stats' => $raw['tc_stats'],
            'bug_stats' => $raw['bug_stats'], 'resolution_rate' => $raw['resolution_rate'],
            'req_completion' => $raw['req_completion'], 'coverage' => $raw['coverage'],
            'detailed_cases' => $raw['detailed_cases'],
            'developers' => $raw['developers'], 'qa_members' => $raw['qa_members'],
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
<title>TestiFy - Project Reports</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../css/pr1.css"/>

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
  <a href="../page/user.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    Users
  </a>
  <a href="../page/client.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
    Clients
  </a>
  <a href="../page/project.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    Projects
  </a>
  <a href="../page/requirement.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
    Requirements
  </a>
  <a href="../page/test_plans.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
    Test Plans
  </a>
  <a href="../page/test_cases.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
    Test Cases
  </a>
  <a href="../page/technology.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    Technologies
  </a>
  <a href="../page/test_types.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
    Testing Types
  </a>
  <a href="../page/project_reports.php" class="sb-link active">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
  </a>
</aside>

<!-- MAIN WRAP -->
<div class="page-wrap" id="pageWrap">
<div class="main">

  <div class="page-title">
    <h1>Project Reports</h1>
    <span class="badge-page">Analytics</span>
  </div>

  <!-- TOOLBAR -->
  <div class="toolbar">
    <input type="hidden" id="currentProjectId" value="<?= $selected_project ?>"/>
    <input type="hidden" id="currentClientId" value="<?= $selected_client ?>"/>
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
      <span class="oc-label">Total Projects</span>
    </div>
  </div>

  <?php if (!$report_data): ?>

  <?php
    $tReq=0;$tTc=0;$tPass=0;$tFail=0;$tNt=0;$tBugs=0;
    foreach($all_projects_summary as $s){$tReq+=$s['req_total'];$tTc+=$s['tc_total'];$tPass+=$s['tc_pass'];$tFail+=$s['tc_fail'];$tNt+=$s['tc_not_tested'];$tBugs+=$s['total_bugs'];}
    $tPassRate=$tTc>0?round($tPass/$tTc*100,1):0;
  ?>

  <div class="all-summary-header">
    <h2>All Projects Summary</h2>
  </div>

  <div class="summary-stat-row">
    <div class="summary-stat-card blue-grad"><div class="ssc-val"><?= count($all_projects_summary) ?></div><div class="ssc-lbl">Total Projects</div></div>
    <div class="summary-stat-card"><div class="ssc-val" style="color:var(--green)"><?= $global_stats['Active'] ?></div><div class="ssc-lbl">Active</div></div>
    <div class="summary-stat-card"><div class="ssc-val" style="color:var(--text-muted)"><?= $global_stats['Inactive'] ?></div><div class="ssc-lbl">Inactive</div></div>
    <div class="summary-stat-card"><div class="ssc-val"><?= $tReq ?></div><div class="ssc-lbl">Total Reqs</div></div>
    <div class="summary-stat-card"><div class="ssc-val"><?= $tTc ?></div><div class="ssc-lbl">Test Cases</div></div>
    <div class="summary-stat-card"><div class="ssc-val" style="color:var(--green)"><?= $tPass ?></div><div class="ssc-lbl">Passed</div></div>
    <div class="summary-stat-card"><div class="ssc-val" style="color:var(--red)"><?= $tFail ?></div><div class="ssc-lbl">Failed</div></div>
    <div class="summary-stat-card"><div class="ssc-val" style="color:var(--text-muted)"><?= $tNt ?></div><div class="ssc-lbl">Not Tested</div></div>
    <div class="summary-stat-card"><div class="ssc-val"><?= $tPassRate ?>%</div><div class="ssc-lbl">Pass Rate</div></div>
    <div class="summary-stat-card"><div class="ssc-val" style="color:var(--orange)"><?= $tBugs ?></div><div class="ssc-lbl">Total Bugs</div></div>
  </div>

  <div class="table-card">
    <div class="tc-header">All Projects Summary Table</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>S.NO.</th><th>Project</th><th>Client</th><th>QA Lead</th><th>Project Lead</th><th>Developers</th><th>QA Members</th><th>Status</th><th>Active</th><th>Reqs</th><th>TC Total</th><th>Pass</th><th>Fail</th><th>Not Tested</th><th>Pass Rate</th><th>Coverage</th><th>Bugs</th><th>Bug Res %</th></tr></thead>
        <tbody>
          <?php if (empty($all_projects_summary)): ?>
            <tr><td colspan="18" style="text-align:center;padding:30px;color:var(--text-muted);">No projects found.</td></tr>
          <?php else: $i=0; foreach($all_projects_summary as $s): $i++; ?>
          <tr style="cursor:pointer;" onclick="window.location.href='?filter_client=<?= $s['client_id']??'' ?>&filter_project=<?= $s['id'] ?>'">
            <td style="color:var(--text-muted)"><?= $i ?></td>
            <td><b><?= htmlspecialchars($s['name']) ?></b></td>
            <td><?= htmlspecialchars($s['client_name']) ?></td>
            <td><span class="badge badge-qa"><?= htmlspecialchars($s['qa_lead_name']) ?></span></td>
            <td><span class="badge badge-lead"><?= htmlspecialchars($s['lead_name']) ?></span></td>
            <td><span class="badge badge-dev" style="background:#E8F0FE;color:#1a73e8;font-size:11px;padding:3px 8px;border-radius:12px;white-space:nowrap;"><?= htmlspecialchars($s['developers']) ?></span></td>
            <td><span class="badge badge-qamem" style="background:#E6F4EA;color:#137333;font-size:11px;padding:3px 8px;border-radius:12px;white-space:nowrap;"><?= htmlspecialchars($s['qa_members']) ?></span></td>
            <td><?= htmlspecialchars($s['status']) ?></td>
            <td><span class="badge <?= $s['is_active']?'badge-active':'badge-inactive' ?>"><?= $s['is_active']?'Yes':'No' ?></span></td>
            <td style="text-align:center"><?= $s['req_total'] ?></td>
            <td style="text-align:center"><?= $s['tc_total'] ?></td>
            <td style="text-align:center;color:var(--green);font-weight:700"><?= $s['tc_pass'] ?></td>
            <td style="text-align:center;color:var(--red);font-weight:700"><?= $s['tc_fail'] ?></td>
            <td style="text-align:center;color:var(--text-muted);font-weight:700"><?= $s['tc_not_tested'] ?></td>
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
    <p style="color:var(--text-muted);font-size:13px;">Click on any project row above to view its detailed report & summary.</p>
  </div>

  <?php else: extract($report_data); $proj = $project_details; ?>

  <div class="proj-title">
    <h2><?= htmlspecialchars($proj['name']) ?> - Project Report</h2>
    <div class="gen">Created (Updated: <?= date('d F Y') ?>) | TestiFy QA Management</div>
  </div>

  <div class="proj-info">
    <div class="proj-info-cell"><div class="pi-label">Status</div><div class="pi-val"><?= htmlspecialchars($proj['status'] ?? 'N/A') ?></div></div>
    <div class="proj-info-cell"><div class="pi-label">Project Lead</div><div class="pi-val"><?= htmlspecialchars($proj['lead_name']) ?></div></div>
    <div class="proj-info-cell"><div class="pi-label">QA Lead</div><div class="pi-val"><?= htmlspecialchars($proj['qa_lead_name']) ?></div></div>
    <div class="proj-info-cell"><div class="pi-label">Developers</div><div class="pi-val" style="color:#1a73e8;font-weight:600;"><?= htmlspecialchars($developers ?? 'N/A') ?></div></div>
    <div class="proj-info-cell"><div class="pi-label">QA Members</div><div class="pi-val" style="color:#137333;font-weight:600;"><?= htmlspecialchars($qa_members ?? 'N/A') ?></div></div>
  </div>

  <div class="tabs">
    <button class="tab-btn active" id="tabSummary" onclick="showTab('summary')">Summary Report</button>
    <button class="tab-btn" id="tabDetailed" onclick="showTab('detailed')">Detailed Test Cases</button>
  </div>

  <div id="paneSummary">
    <div class="stat-row">
      <div class="stat-card blue-grad"><div class="sc-label">Total Requirements</div><div class="sc-val"><?= $req_stats['Total'] ?></div><div class="sc-sub">Developed: <?= $req_stats['Developed'] ?> · Tested: <?= $req_stats['Tested'] ?></div></div>
      <div class="stat-card blue-grad"><div class="sc-label">Total Test Cases</div><div class="sc-val"><?= $tc_stats['Total'] ?></div><div class="sc-sub">Pass: <?= $tc_stats['Pass'] ?> · Fail: <?= $tc_stats['Fail'] ?></div></div>
      <div class="stat-card"><div class="sc-label">Test Coverage</div><div class="sc-val" style="color:var(--blue-dark)"><?= number_format($coverage,0) ?>%</div><div class="sc-sub">Requirements tested</div></div>
      <div class="stat-card"><div class="sc-label">Bug Resolution Rate</div><div class="sc-val" style="color:var(--green)"><?= number_format($resolution_rate,1) ?>%</div><div class="sc-sub">Resolved + Closed</div></div>
    </div>

    <div class="table-card">
      <div class="tc-header">Project Progress</div>
      <div style="padding:18px 20px 4px;">
        <div class="prog-wrap"><div class="prog-lrow"><span class="pl-name">Requirement Completion</span><span class="pl-pct"><?= number_format($req_completion,0) ?>%</span></div><div class="prog-bg"><div class="prog-fill blue" style="width:<?= number_format($req_completion,0) ?>%"></div></div></div>
        <div class="prog-wrap"><div class="prog-lrow"><span class="pl-name">Test Coverage</span><span class="pl-pct"><?= number_format($coverage,0) ?>%</span></div><div class="prog-bg"><div class="prog-fill yellow" style="width:<?= number_format($coverage,0) ?>%"></div></div></div>
      </div>
    </div>

    <div class="req-breakdown">
      <div class="sec-title">Requirements Breakdown</div>
      <div class="req-cols">
        <div class="req-col"><div class="rc-lbl">Dev</div><div class="rc-val"><?= $req_stats['Developed'] ?>/<?= $req_stats['Total'] ?></div></div>
        <div class="req-col"><div class="rc-lbl">Test</div><div class="rc-val"><?= $req_stats['Tested'] ?>/<?= $req_stats['Total'] ?></div></div>
        <div class="req-col"><div class="rc-lbl">Del</div><div class="rc-val"><?= $req_stats['Delivered'] ?>/<?= $req_stats['Total'] ?></div></div>
        <div class="req-col"><div class="rc-lbl">UAT</div><div class="rc-val"><?= $req_stats['UAT'] ?>/<?= $req_stats['Total'] ?></div></div>
        <div class="req-col"><div class="rc-lbl">Bug Fixed</div><div class="rc-val"><?= $req_stats['Bug Fixed'] ?>/<?= $req_stats['Total'] ?></div></div>
        <div class="req-col"><div class="rc-lbl">Bug After UAT</div><div class="rc-val"><?= $req_stats['Bug After UAT'] ?>/<?= $req_stats['Total'] ?></div></div>
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
      <div class="tc-header">Bug &amp; Test Status</div>
      <div style="padding:18px 20px;">
        <div class="bug-grid">
          <div class="bug-card c-open"><div class="bv"><?= $bug_stats['Open'] ?></div><div class="bl">Open</div></div>
          <div class="bug-card c-inprog"><div class="bv"><?= $bug_stats['In Progress'] ?></div><div class="bl">In Progress</div></div>
          <div class="bug-card c-resolved"><div class="bv"><?= $bug_stats['Resolved'] ?></div><div class="bl">Resolved</div></div>
          <div class="bug-card c-closed"><div class="bv"><?= $bug_stats['Closed'] ?></div><div class="bl">Closed</div></div>
          <div class="bug-card c-nt"><div class="bv"><?= $tc_stats['Not tested'] ?></div><div class="bl">Not Tested</div></div>
        </div>
      </div>
    </div>
  </div>

  <div id="paneDetailed" style="display:none">
    <div class="table-card">
      <div class="tc-header">Detailed Test Cases</div>
      <div style="padding:14px 16px;">
        <?php if (empty($detailed_cases)): ?>
          <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:14px;">No test cases found for this project.</div>
        <?php else: $gi=0; foreach($detailed_cases as $grp): $gi++; ?>
        <div class="acc-item">
          <div class="acc-hdr" onclick="toggleAcc(<?= $gi ?>)">
            <div class="acc-left"><span><?= htmlspecialchars($grp['name']) ?></span><span class="prio-badge <?= htmlspecialchars($grp['priority'] ?? 'High') ?>"><?= htmlspecialchars($grp['priority'] ?? 'High') ?></span></div>
            <div class="acc-right"><span class="acc-total">Total: <?= $grp['total'] ?></span><span class="badge badge-pass">P: <?= $grp['pass'] ?></span><span class="badge badge-fail">F: <?= $grp['fail'] ?></span><span class="badge badge-nt">NT: <?= $grp['not_tested'] ?></span><span class="acc-tog" id="acc-tog-<?= $gi ?>">+</span></div>
          </div>
          <div class="acc-body" id="acc-body-<?= $gi ?>">
            <div class="table-wrap">
              <table><thead><tr><th>S.NO.</th><th>TC-ID</th><th>Test Case Title</th><th>Status</th><th>Bug Status</th><th>Auto</th><th>Created By</th><th>Created At</th><th>Executed By</th><th>Assigned To</th><th>Executed On</th></tr></thead>
              <tbody>
                <?php $ci=0; foreach($grp['cases'] as $tc): $ci++; ?>
                <tr>
                  <td style="color:var(--text-muted);font-size:12px;"><?= $ci ?></td>
                  <td style="color:var(--blue-dark);font-weight:700;font-size:12px;"><?= htmlspecialchars($tc['tc_custom_id'] ?? $tc['id'] ?? '—') ?></td>
                  <td style="max-width:320px;"><?= htmlspecialchars($tc['tc_title'] ?? '—') ?></td>
                  <td><?php $s=$tc['status']; ?><span class="badge <?= $s==='Pass'?'badge-pass':($s==='Fail'?'badge-fail':'badge-nt') ?>"><?= htmlspecialchars($s) ?></span></td>
                  <td><?php if($tc['bug_raised']): $bs=trim($tc['bug_status']??''); ?><span class="badge <?= $bs==='Resolved'?'badge-resolved':($bs==='Closed'?'badge-closed':($bs==='In Progress'?'badge-inprog':'badge-open')) ?>"><?= htmlspecialchars($bs?:'Open') ?></span><?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?></td>
                  <td><?php if($tc['is_automated']): ?><span class="badge badge-pass">Yes</span><?php else: ?><span class="badge badge-nt">No</span><?php endif; ?></td>
                  <td><?= htmlspecialchars($tc['created_by_name']??'—') ?></td>
                  <td style="font-size:12px;color:var(--text-muted);"><?php $ca=$tc['created_at']??null; echo $ca?date('d M y, g:i A',strtotime($ca)):'—'; ?></td>
                  <td><?= htmlspecialchars($tc['executed_name']??'—') ?></td>
                  <td><?= htmlspecialchars($tc['assigned_name']??'—') ?></td>
                  <td style="font-size:12px;color:var(--text-muted);"><?php $eo=$tc['executed_on']??null; echo $eo?date('d M y, g:i A',strtotime($eo)):'—'; ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody></table>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <?php endif; ?>
</div>
</div>

<script>
function toggleSidebar(){
  var sb=document.getElementById('sidebar'),ov=document.getElementById('sbOverlay'),hm=document.getElementById('hamBtn');
  var isOpen=sb.classList.toggle('open');ov.classList.toggle('open',isOpen);hm.classList.toggle('open',isOpen);
  document.body.style.overflow=(isOpen&&window.innerWidth<=768)?'hidden':'';
}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sbOverlay').classList.remove('open');document.getElementById('hamBtn').classList.remove('open');document.body.style.overflow='';}
window.addEventListener('resize',function(){if(window.innerWidth>768)closeSidebar();});

function clearAllData(){window.location.href=window.location.pathname;}
function showTab(tab){document.getElementById('paneSummary').style.display=tab==='summary'?'':'none';document.getElementById('paneDetailed').style.display=tab==='detailed'?'':'none';document.getElementById('tabSummary').classList.toggle('active',tab==='summary');document.getElementById('tabDetailed').classList.toggle('active',tab==='detailed');}
function toggleAcc(id){var body=document.getElementById('acc-body-'+id),tog=document.getElementById('acc-tog-'+id),open=body.classList.toggle('open');tog.textContent=open?'\u2212':'+';}
function getActiveTabType(){if(document.getElementById('tabSummary').classList.contains('active'))return'summary';if(document.getElementById('tabDetailed').classList.contains('active'))return'detailed';return'summary';}
function downloadPDF(){var pId=document.getElementById('currentProjectId').value;if(!pId){alert('No project selected.');return;}window.open('?action=export_pdf&project_id='+pId+'&type='+getActiveTabType(),'_blank');}
function downloadExcel(){var pId=document.getElementById('currentProjectId').value;if(!pId){alert('No project selected.');return;}window.location.href='?action=export_excel&project_id='+pId+'&type='+getActiveTabType();}
function downloadAllPDF(){window.open('?action=export_all_pdf','_blank');}
function downloadAllExcel(){window.location.href='?action=export_all_excel';}
</script>
</body>
</html>
