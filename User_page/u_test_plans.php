<?php
// ── user_page/u_test_plans.php ─────────────────────────────
// USER VERSION: Full CRUD same as admin, with project assignment validation
// When filtering by client only → show all test plans for that client
// TP ID is project-specific: each project starts at TP-001
session_start();
include '../config/db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$msg      = '';
$msg_type = '';
$self     = strtok($_SERVER['REQUEST_URI'], '?'); // base URL for redirects

// ════════════════════════════════════════════════════════
//  SMART SESSION DETECTION
// ════════════════════════════════════════════════════════
$current_user_id   = null;
$current_user_name = $_SESSION['name'] ?? '';
$current_user_role = $_SESSION['role'] ?? '';

$session_keys = ['user_id', 'id', 'user.id', 'uid', 'userId'];
foreach ($session_keys as $key) {
    if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
        $current_user_id = (int)$_SESSION[$key];
        break;
    }
}
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

// ════════════════════════════════════════════════════════
//  FETCH USER'S ASSIGNED PROJECTS
// ════════════════════════════════════════════════════════
$user_assigned_project_ids = [];
$up_stmt = $conn->prepare(
    "SELECT DISTINCT p.id
     FROM projects p
     LEFT JOIN project_frontend_devs pfd ON p.id = pfd.project_id
     LEFT JOIN project_backend_devs pbd ON p.id = pbd.project_id
     LEFT JOIN project_qa_team pqt ON p.id = pqt.project_id
     WHERE p.project_lead_id = ?
        OR p.qa_lead_id = ?
        OR pfd.user_id = ?
        OR pbd.user_id = ?
        OR pqt.user_id = ?"
);
$up_stmt->bind_param('iiiii', $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id);
$up_stmt->execute();
$up_res = $up_stmt->get_result();
while ($r = $up_res->fetch_assoc()) {
    $user_assigned_project_ids[] = (int)$r['id'];
}
$up_stmt->close();

// Helper: check if a project_id is allowed for this user
function isProjectAllowed($pid) {
    global $user_assigned_project_ids;
    return in_array((int)$pid, $user_assigned_project_ids);
}

// PRG Messages
if (isset($_GET['added']))        { $msg = 'Test Plan added successfully!';   $msg_type = 'success'; }
elseif (isset($_GET['updated']))  { $msg = 'Test Plan updated!';              $msg_type = 'success'; }
elseif (isset($_GET['deleted']))  { $msg = 'Test Plan deleted.';              $msg_type = 'success'; }
elseif (isset($_GET['add_err']))  { $msg = 'Project, Title, Objective and Scope are required.'; $msg_type = 'error'; }
elseif (isset($_GET['edit_err'])) { $msg = 'Project, Title and Scope are required.';              $msg_type = 'error'; }
elseif (isset($_GET['import_err'])){ $msg = 'Please select a project and upload a CSV file.';   $msg_type = 'error'; }
elseif (isset($_GET['not_allowed'])){ $msg = 'You are not assigned to this project. You can only add test plans for your assigned projects.'; $msg_type = 'error'; }
elseif (isset($_GET['imported'])) {
    $imp  = (int)$_GET['imported'];
    $errs = (int)($_GET['imp_err'] ?? 0);
    $msg  = "$imp test plan(s) imported from CSV!";
    if ($errs) $msg .= " ($errs rows were skipped).";
    $msg_type = 'success';
}

// ════════════════════════════════════════════════════════════
//  IMPORT CSV
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    $import_project_id = (int)($_POST['import_project_id'] ?? 0);

    // ── VALIDATION: Check project assignment ──
    if (!isProjectAllowed($import_project_id)) {
        header('Location: ' . $self . '?not_allowed=1');
        exit;
    }

    if (!$import_project_id) {
        header('Location: ' . $self . '?import_err=1');
        exit;
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: ' . $self . '?import_err=1');
        exit;
    } else {
        $file   = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        $imported = 0;
        $errors   = 0;

        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $project_map = [];
        $pm_res = $conn->query("SELECT name, id FROM projects");
        if ($pm_res) {
            while ($pm = $pm_res->fetch_assoc()) {
                $project_map[strtolower(trim($pm['name']))] = $pm['id'];
            }
        }

        $header_count = count($header);

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row, fn($v) => trim($v) !== ''))) continue;
            $row = array_slice(array_pad($row, $header_count, ''), 0, $header_count);

            if (count($row) !== $header_count) { $errors++; continue; }
            $data = array_combine($header, $row);

            $project_name_csv = trim($data['project_name'] ?? '');
            $pid_to_use = $import_project_id;
            if (!empty($project_name_csv)) {
                $lookup_key = strtolower($project_name_csv);
                if (isset($project_map[$lookup_key])) {
                    $pid_to_use = $project_map[$lookup_key];
                } else { $errors++; continue; }
            }

            // ── VALIDATION: Check each imported row's project ──
            if (!isProjectAllowed($pid_to_use)) { $errors++; continue; }

            $title         = trim($data['title'] ?? '');
            $objective     = trim($data['objective'] ?? '');
            $scope         = trim($data['scope'] ?? '');
            $roles         = trim($data['roles_covered'] ?? '');
            $types_csv     = trim($data['testing_types'] ?? '');
            $testing_types = !empty($types_csv) ? array_map('trim', explode(',', $types_csv)) : [];
            $tech_csv      = trim($data['technologies'] ?? '');
            $technologies  = !empty($tech_csv) ? array_map('trim', explode(',', $tech_csv)) : [];
            $lead_id       = !empty($data['project_lead_id']) ? (int)$data['project_lead_id'] : null;
            $test_lead_id  = !empty($data['test_lead_id'])    ? (int)$data['test_lead_id']    : null;

            if (!$title) { $errors++; continue; }

            $testing_types_json = json_encode($testing_types);
            $technologies_json  = json_encode($technologies);

            // ── Dynamic INSERT to handle NULL lead IDs properly ──
            $csv_fields = "project_id, title, objective, scope, testing_types, technologies, roles_covered";
            $csv_places = "?,?,?,?,?,?,?";
            $csv_types  = "issssss";
            $csv_vals   = [$pid_to_use, $title, $objective, $scope, $testing_types_json, $technologies_json, $roles];

            if ($lead_id !== null) {
                $csv_fields .= ", project_lead_id";
                $csv_places .= ",?";
                $csv_types  .= "i";
                $csv_vals[]  = $lead_id;
            }
            if ($test_lead_id !== null) {
                $csv_fields .= ", test_lead_id";
                $csv_places .= ",?";
                $csv_types  .= "i";
                $csv_vals[]  = $test_lead_id;
            }

            $stmt = $conn->prepare("INSERT INTO test_plans ($csv_fields) VALUES ($csv_places)");
            $stmt->bind_param($csv_types, ...$csv_vals);

            if ($stmt->execute()) $imported++; else $errors++;
            $stmt->close();
        }
        fclose($handle);

        $redirect = $self . "?imported=$imported";
        if ($errors) $redirect .= "&imp_err=$errors";
        header("Location: $redirect");
        exit;
    }
}

// ════════════════════════════════════════════════════════════
//  DELETE
// ════════════════════════════════════════════════════════════
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];

    // ── VALIDATION: Check project assignment before delete ──
    $chk_stmt = $conn->prepare("SELECT project_id FROM test_plans WHERE id = ?");
    $chk_stmt->bind_param('i', $del_id);
    $chk_stmt->execute();
    $chk_row = $chk_stmt->get_result()->fetch_assoc();
    $chk_stmt->close();
    if (!$chk_row || !in_array((int)$chk_row['project_id'], $user_assigned_project_ids)) {
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Not allowed']);
            exit;
        }
        header('Location: ' . $self . '?not_allowed=1');
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM test_plans WHERE id = ?");
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    $stmt->close();
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    header('Location: ' . $self . '?deleted=1');
    exit;
}

// ════════════════════════════════════════════════════════════
//  ADD TEST PLAN — PRG Pattern
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $project_id   = (int)($_POST['project_id']      ?? 0);
    $title        = trim($_POST['title']             ?? '');
    $objective    = trim($_POST['objective']         ?? '');
    $scope        = trim($_POST['scope']             ?? '');
    $post_types   = $_POST['testing_types']          ?? [];
    $techs        = $_POST['technologies']           ?? [];
    $lead_id      = !empty($_POST['project_lead_id']) ? (int)$_POST['project_lead_id'] : null;
    $test_lead_id = !empty($_POST['test_lead_id'])    ? (int)$_POST['test_lead_id']    : null;
    $roles        = trim($_POST['roles_covered']     ?? '');

    // ── VALIDATION: Check project assignment ──
    if (!isProjectAllowed($project_id)) {
        header('Location: ' . $self . '?not_allowed=1');
        exit;
    }

    if (!$project_id || !$title || !$objective || !$scope) {
        header('Location: ' . $self . '?add_err=1');
        exit;
    }
    $testing_types_json = json_encode($post_types);
    $technologies_json  = json_encode($techs);

    // ── Dynamic INSERT to handle NULL lead IDs properly ──
    $ins_fields = "project_id, title, objective, scope, testing_types, technologies, roles_covered";
    $ins_places = "?,?,?,?,?,?,?";
    $ins_types  = "issssss";
    $ins_vals   = [$project_id, $title, $objective, $scope, $testing_types_json, $technologies_json, $roles];

    if ($lead_id !== null) {
        $ins_fields .= ", project_lead_id";
        $ins_places .= ",?";
        $ins_types  .= "i";
        $ins_vals[]  = $lead_id;
    }
    if ($test_lead_id !== null) {
        $ins_fields .= ", test_lead_id";
        $ins_places .= ",?";
        $ins_types  .= "i";
        $ins_vals[]  = $test_lead_id;
    }

    $stmt = $conn->prepare("INSERT INTO test_plans ($ins_fields) VALUES ($ins_places)");
    $stmt->bind_param($ins_types, ...$ins_vals);
    if ($stmt->execute()) {
        $stmt->close();
        header('Location: ' . $self . '?added=1'); exit;
    } else {
        $stmt->close();
        header('Location: ' . $self . '?add_err=1'); exit;
    }
}

// ════════════════════════════════════════════════════════════
//  EDIT TEST PLAN — PRG Pattern
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $edit_id      = (int)($_POST['edit_id']          ?? 0);
    $project_id   = (int)($_POST['project_id']       ?? 0);
    $title        = trim($_POST['title']              ?? '');
    $objective    = trim($_POST['objective']          ?? '');
    $scope        = trim($_POST['scope']              ?? '');
    $post_types   = $_POST['testing_types']           ?? [];
    $techs        = $_POST['technologies']            ?? [];
    $lead_id      = !empty($_POST['project_lead_id']) ? (int)$_POST['project_lead_id'] : null;
    $test_lead_id = !empty($_POST['test_lead_id'])    ? (int)$_POST['test_lead_id']    : null;
    $roles        = trim($_POST['roles_covered']      ?? '');

    // ── VALIDATION: Check project assignment ──
    if (!isProjectAllowed($project_id)) {
        header('Location: ' . $self . '?not_allowed=1');
        exit;
    }

    if (!$project_id || !$title || !$scope) {
        header('Location: ' . $self . '?edit_err=1');
        exit;
    }
    $testing_types_json = json_encode($post_types);
    $technologies_json  = json_encode($techs);

    // ── Dynamic UPDATE to handle NULL lead IDs properly ──
    $set_parts = "project_id=?, title=?, objective=?, scope=?, testing_types=?, technologies=?, roles_covered=?";
    $upd_types = "issssss";
    $upd_vals  = [$project_id, $title, $objective, $scope, $testing_types_json, $technologies_json, $roles];

    if ($lead_id !== null) {
        $set_parts .= ", project_lead_id=?";
        $upd_types .= "i";
        $upd_vals[] = $lead_id;
    } else {
        $set_parts .= ", project_lead_id=NULL";
    }
    if ($test_lead_id !== null) {
        $set_parts .= ", test_lead_id=?";
        $upd_types .= "i";
        $upd_vals[] = $test_lead_id;
    } else {
        $set_parts .= ", test_lead_id=NULL";
    }

    $set_parts .= " WHERE id=?";
    $upd_types .= "i";
    $upd_vals[] = $edit_id;

    $stmt = $conn->prepare("UPDATE test_plans SET $set_parts");
    $stmt->bind_param($upd_types, ...$upd_vals);
    if ($stmt->execute()) {
        $stmt->close();
        header('Location: ' . $self . '?updated=1'); exit;
    } else {
        $stmt->close();
        header('Location: ' . $self . '?edit_err=1'); exit;
    }
}

// ════════════════════════════════════════════════════════════
//  FETCH DATA
// ════════════════════════════════════════════════════════════
// Fetch assigned projects only (used for filter dropdowns AND modal dropdowns)
$assigned_projects_list = [];
if (!empty($user_assigned_project_ids)) {
    $apids = implode(',', array_map('intval', $user_assigned_project_ids));
    $ap_res = $conn->query("SELECT id, client_id, name FROM projects WHERE id IN ($apids) ORDER BY name ASC");
    if ($ap_res) while ($r = $ap_res->fetch_assoc()) $assigned_projects_list[] = $r;
}

// Fetch ONLY clients that have projects assigned to this user
$clients_list = [];
if (!empty($assigned_projects_list)) {
    $client_ids = array_unique(array_map(fn($p) => (int)$p['client_id'], $assigned_projects_list));
    $cid_list = implode(',', array_map('intval', $client_ids));
    $c_res = $conn->query("SELECT id, name FROM clients WHERE id IN ($cid_list) AND status='active' ORDER BY name");
    if ($c_res) while ($r = $c_res->fetch_assoc()) $clients_list[] = $r;
}

// For filter dropdown: same as assigned projects
$projects_list = $assigned_projects_list;

$dev_list = [];
$d_res = $conn->query("SELECT id, name, username FROM users WHERE role='Developer' ORDER BY name");
if ($d_res) while ($r = $d_res->fetch_assoc()) $dev_list[] = $r;

$qa_list = [];
$q_res = $conn->query("SELECT id, name, username FROM users WHERE role='QA' ORDER BY name");
if ($q_res) while ($r = $q_res->fetch_assoc()) $qa_list[] = $r;

// Filter Logic
$filter_client  = (int)($_GET['filter_client']  ?? 0);
$filter_project = (int)($_GET['filter_project'] ?? 0);
$search         = trim($_GET['search']          ?? '');
$filters_applied = $filter_client || $filter_project || $search;

$test_plans  = [];
$total       = 0;
$per_page    = 10;
$page        = 1;
$total_pages = 1;

// Build base WHERE clause
$base_where    = [];
$bind_params   = [];
$bind_types    = '';

// ── KEY LOGIC: When filtering by client ONLY (no project filter), show ALL test plans for that client.
// When no filters or when project filter is used, restrict to assigned projects. ──
$client_only_filter = ($filter_client && !$filter_project);

if ($client_only_filter) {
    // Client-only filter: show ALL test plans under that client (no project assignment restriction)
} else {
    // No filter or project-specific filter: restrict to assigned projects
    if (!empty($user_assigned_project_ids)) {
        $pid_list = implode(',', array_map('intval', $user_assigned_project_ids));
        $base_where[] = "tp.project_id IN ($pid_list)";
    } else {
        $base_where[] = "1=0";
    }
}

if ($filters_applied) {
    if ($filter_client) {
        $base_where[]   = 'p.client_id = ?';
        $bind_params[]  = $filter_client;
        $bind_types    .= 'i';
    }
    if ($filter_project) {
        $base_where[]   = 'tp.project_id = ?';
        $bind_params[]  = $filter_project;
        $bind_types    .= 'i';
    }
    if ($search) {
        $like           = "%$search%";
        $base_where[]   = '(tp.title LIKE ? OR tp.objective LIKE ?)';
        $bind_params[]  = $like;
        $bind_params[]  = $like;
        $bind_types    .= 'ss';
    }
}

$whereStr = !empty($base_where) ? 'WHERE ' . implode(' AND ', $base_where) : '';

// ── Project-specific TP sequence subquery ──
// This calculates the sequential number of each test plan within its project
// Ordered by created_at ASC + id ASC so the first plan in a project gets TP-001
$tp_seq_subquery = "
    (SELECT COUNT(*) + 1
     FROM test_plans tp2
     WHERE tp2.project_id = tp.project_id
       AND (tp2.created_at < tp.created_at
            OR (tp2.created_at = tp.created_at AND tp2.id < tp.id))
    ) AS tp_seq
";

// ── Only fetch data when filters are applied ──
// No filter = blank table with "Apply filters" message
if ($filters_applied && !empty($whereStr)) {
    $cstmt = $conn->prepare("SELECT COUNT(*) FROM test_plans tp LEFT JOIN projects p ON p.id=tp.project_id $whereStr");
    if ($bind_types !== '') $cstmt->bind_param($bind_types, ...$bind_params);
    $cstmt->execute();
    $cstmt->bind_result($total);
    $cstmt->fetch();
    $cstmt->close();

    $total_pages = max(1, ceil($total / $per_page));
    $page        = max(1, min((int)($_GET['page'] ?? 1), $total_pages));
    $offset      = ($page - 1) * $per_page;

    $qparams   = $bind_params;
    $qtypes    = $bind_types . 'ii';
    $qparams[] = $per_page;
    $qparams[] = $offset;

    $stmt = $conn->prepare(
        "SELECT tp.*, $tp_seq_subquery, p.name AS project_name, pl.name AS project_lead_name, tl.name AS test_lead_name
         FROM test_plans tp
         LEFT JOIN projects p  ON p.id  = tp.project_id
         LEFT JOIN users pl    ON pl.id = tp.project_lead_id
         LEFT JOIN users tl    ON tl.id = tp.test_lead_id
         $whereStr
         ORDER BY tp.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->bind_param($qtypes, ...$qparams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['testing_types'] = json_decode($row['testing_types'] ?? '[]', true);
        $row['technologies']  = json_decode($row['technologies']  ?? '[]', true);
        $test_plans[] = $row;
    }
    $stmt->close();
}

$conn->close();

function fmt_date($d) { return $d ? date('d M Y', strtotime($d)) : '—'; }

// Build the allowed project IDs JSON for frontend validation
$allowed_project_ids_json = json_encode($user_assigned_project_ids);

// Build all projects JSON for filter dropdown cascade
$all_projects_json = json_encode($projects_list);

// Build assigned projects JSON for modal dropdown
$assigned_projects_json = json_encode($assigned_projects_list);

// ── Build project-wise TP ID lookup for JS ──
$tp_id_map = [];
foreach ($test_plans as $tp) {
    $tp_id_map[(int)$tp['id']] = 'TP-' . str_pad($tp['tp_seq'], 3, '0', STR_PAD_LEFT);
}
$tp_id_map_json = json_encode($tp_id_map);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover"/>
<title>TestiFy — My Test Plans</title>
<link rel="icon" type="image/jpg" href="../icon/testify.jpg" />
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<style>
/* ═══════════════════════════════════════════════════════════════
   COLORFUL THEME — Fully Responsive
   ═══════════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --blue-dark:  #3a7bd5;
  --blue-mid:   #4a90d9;
  --blue-light: #4facfe;
  --text-main:  #2d3a5e;
  --text-muted: #6b7fa3;
  --border:     #dde4f0;
  --white:      #ffffff;
  --bg:         #f0f4ff;
  --red:        #e74c3c;
  --green:      #27ae60;
  --green-mid:  #2ecc71;
  --purple:     #8e44ad;
  --purple-mid: #9b59b6;
  --orange:     #e67e22;
  --sb-w:       240px;
  --nb-h:       60px;
}

body { min-height:100vh; background:var(--bg); font-family:'Nunito',sans-serif; color:var(--text-main); overflow-x:hidden; }

/* ══ NAVBAR ══ */
.navbar { background:linear-gradient(90deg,#ffffff,#f8faff); border-bottom:2px solid transparent; border-image:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid),var(--orange)) 1; display:flex; align-items:center; justify-content:space-between; padding:0 20px; height:var(--nb-h); position:fixed; top:0; left:0; right:0; z-index:300; box-shadow:0 2px 20px rgba(74,144,217,.1); }
.nblogo { font-family:'Poppins',sans-serif; font-weight:800; font-size:22px; background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid),var(--orange)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; text-decoration:none; white-space:nowrap; display:flex; align-items:center; gap:10px; }

.ham { display:none; flex-direction:column; justify-content:center; gap:5px; width:40px; height:40px; border:none; background:transparent; cursor:pointer; padding:8px; border-radius:8px; transition:.2s; flex-shrink:0; }
.ham:hover { background:#eef4fd; }
.ham span { display:block; height:2.5px; width:22px; border-radius:2px; background:var(--text-main); transition:.3s; }
.ham.open span:nth-child(1) { transform:translateY(7.5px) rotate(45deg); }
.ham.open span:nth-child(2) { opacity:0; transform:scaleX(0); }
.ham.open span:nth-child(3) { transform:translateY(-7.5px) rotate(-45deg); }

.nb-right { display:flex; align-items:center; gap:10px; flex-shrink:0; }
.nb-user { font-size:16px; color:var(--text-muted); font-weight:600; white-space:nowrap; display:flex; align-items:center; gap:8px; }
.nb-role { font-size:14px; font-weight:700; background:linear-gradient(135deg,#e3f2fd,#f3e8ff); color:#7c3aed; padding:2px 10px; border-radius:8px; text-transform:capitalize; letter-spacing:.5px; white-space:nowrap; border:1px solid #e9d5ff; }
.blgout { padding:7px 16px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--red),#c0392b); color:#fff; text-decoration:none; font-weight:700; font-size:13px; white-space:nowrap; transition:.2s; box-shadow:0 2px 10px rgba(231,76,60,.2); }
.blgout:hover { box-shadow:0 4px 18px rgba(231,76,60,.35); transform:translateY(-1px); }

/* ══ SIDEBAR ══ */
.sidebar { position:fixed; top:var(--nb-h); left:0; bottom:0; width:var(--sb-w); background:linear-gradient(180deg,#ffffff,#f8faff); border-right:1.5px solid var(--border); z-index:250; overflow-y:auto; overflow-x:hidden; transition:transform .28s cubic-bezier(.4,0,.2,1); box-shadow:2px 0 20px rgba(74,144,217,.06); padding-bottom:24px; }
.sidebar-overlay { display:none; position:fixed; inset:0; top:var(--nb-h); background:rgba(30,45,80,.4); z-index:240; backdrop-filter:blur(2px); opacity:0; transition:opacity .25s ease; }
.sidebar-overlay.open { display:block; opacity:1; }

.sb-section { padding:18px 14px 6px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); }
.sb-link { display:flex; align-items:center; gap:10px; padding:9px 16px; margin:1px 8px; border-radius:10px; text-decoration:none; color:var(--text-main); font-size:13.5px; font-weight:600; transition:.15s; position:relative; }
.sb-link:hover { background:linear-gradient(90deg,#eef4fd,#f0f7ff); color:var(--blue-dark); }
.sb-link.active { background:linear-gradient(90deg,#e8f0fd,#f0f7ff); color:var(--blue-dark); font-weight:700; }
.sb-link.active::before { content:''; position:absolute; left:0; top:20%; bottom:20%; width:3px; border-radius:0 3px 3px 0; background:linear-gradient(180deg,var(--blue-dark),var(--purple-mid)); margin-left:-8px; }
.sb-link svg { width:16px; height:16px; flex-shrink:0; opacity:.7; }
.sb-link.active svg { opacity:1; }

.sb-home { display:flex; align-items:center; gap:10px; padding:12px 16px; margin:8px 8px 2px; border-radius:10px; text-decoration:none; color:var(--text-main); font-size:13.5px; font-weight:700; transition:.15s; background:linear-gradient(135deg,#f8faff,#eef4fd); border:1px solid var(--border); }
.sb-home:hover { background:#eef4fd; color:var(--blue-dark); }
.sb-home svg { width:16px; height:16px; opacity:.7; }

/* ══ LAYOUT WRAP ══ */
.page-wrap { margin-left:var(--sb-w); margin-top:var(--nb-h); min-height:calc(100vh - var(--nb-h)); transition:margin-left .28s cubic-bezier(.4,0,.2,1); }

/* ══ MAIN CONTENT ══ */
.main { max-width:1400px; margin:0 auto; padding:28px 24px 60px; }
.page-title { display:flex; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
.page-title h1 { font-family:'Poppins',sans-serif; font-weight:800; font-size:26px; background:linear-gradient(135deg,var(--blue-dark),var(--purple)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; letter-spacing:-.5px; }
.badge-page { background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; font-family:'Poppins',sans-serif; font-weight:700; font-size:10px; letter-spacing:2px; text-transform:uppercase; padding:5px 14px; border-radius:8px; box-shadow:0 2px 10px rgba(142,68,173,.25); }

/* ══ TOOLBAR ══ */
.toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
.toolbar-left { display:flex; align-items:center; gap:12px; flex:1; flex-wrap:wrap; min-width:0; }

.search-wrap { position:relative; flex:1; min-width:200px; max-width:340px; }
.search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--text-muted); pointer-events:none; }
.search-input { width:100%; padding:9px 14px 9px 36px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; }
.search-input:focus { border-color:var(--purple-mid); box-shadow:0 0 0 3px rgba(155,89,182,.12); }

.filter-select { height:38px; padding:0 12px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13px; color:var(--text-main); outline:none; cursor:pointer; transition:border-color .2s; }
.filter-select:focus { border-color:var(--purple-mid); box-shadow:0 0 0 3px rgba(155,89,182,.12); }

.btn-clear-active { display:inline-flex; align-items:center; gap:5px; margin-left:4px; padding:5px 14px; border-radius:10px; background:linear-gradient(135deg,#e74c3c,#c0392b); color:#fff; font-size:12px; font-weight:700; text-decoration:none; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(231,76,60,.25); white-space:nowrap; border:none; }
.btn-clear-active:hover { opacity:.92; transform:translateY(-2px); box-shadow:0 6px 20px rgba(231,76,60,.35); }
.btn-clear-active svg { width:12px; height:12px; }

.btn-filter { height:38px; padding:0 18px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--purple),var(--purple-mid)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; flex-shrink:0; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(142,68,173,.28); white-space:nowrap; }
.btn-filter:hover { opacity:.92; transform:translateY(-2px); box-shadow:0 8px 22px rgba(142,68,173,.38); }
.btn-filter svg { width:14px; height:14px; }

.toolbar-right { display:flex; align-items:center; gap:8px; flex-shrink:0; flex-wrap:wrap; }

.btn-template { height:38px; padding:0 16px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--green),var(--green-mid)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(39,174,96,.3); white-space:nowrap; }
.btn-template:hover { opacity:.92; transform:translateY(-2px); box-shadow:0 8px 22px rgba(39,174,96,.38); }
.btn-template svg { width:14px; height:14px; }

.btn-import { height:38px; padding:0 16px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--orange),#f39c12); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(230,126,34,.3); white-space:nowrap; }
.btn-import:hover { opacity:.92; transform:translateY(-2px); box-shadow:0 8px 22px rgba(230,126,34,.38); }
.btn-import svg { width:14px; height:14px; }

.btn-add { padding:9px 20px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; display:flex; align-items:center; gap:6px; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(58,123,213,.3); white-space:nowrap; flex-shrink:0; }
.btn-add:hover { opacity:.92; transform:translateY(-2px); box-shadow:0 8px 22px rgba(58,123,213,.38); }
.btn-add svg { width:15px; height:15px; }
.btn-add:disabled { opacity:.45; cursor:not-allowed; transform:none !important; }

/* ══ ACTIVE FILTER BAR ══ */
.active-filter-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; background:linear-gradient(135deg,#eef4fd,#f0f7ff); border:1.5px solid #c5d8f7; border-radius:12px; padding:10px 16px; font-size:13px; font-weight:600; color:var(--blue-dark); }
.filter-tag { background:var(--white); border:1.5px solid #c5d8f7; border-radius:20px; padding:3px 10px; font-size:12px; font-weight:700; color:var(--text-main); }

/* ══ TABLE CARD ══ */
.table-card { background:var(--white); border-radius:18px; border:1.5px solid var(--border); box-shadow:0 4px 20px rgba(74,144,217,.07); overflow:hidden; position:relative; }
.table-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid),var(--orange)); border-radius:18px 18px 0 0; z-index:1; }
.table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:thin; }
table { width:100%; border-collapse:collapse; min-width:1200px; }
thead th { background:linear-gradient(90deg,#f0f5fd,#eef4fd); padding:13px 14px; text-align:left; font-size:12px; font-weight:700; color:var(--text-muted); letter-spacing:.8px; text-transform:uppercase; border-bottom:1.5px solid var(--border); white-space:nowrap; }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:linear-gradient(90deg,#f5f8fe,#f8faff); box-shadow:inset 3px 0 0 var(--blue-mid); }
tbody td { padding:13px 14px; font-size:13.5px; vertical-align:middle; }
.req-title { font-weight:700; color:var(--text-main); }
.obj-desc { font-size:11.5px; color:var(--text-muted); max-width:200px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
.proj-name { font-weight:700; color:var(--purple); font-size:13px; }

/* ══ TP ID BADGE ══ */
.tp-id-badge { display:inline-flex; align-items:center; gap:5px; background:linear-gradient(135deg,#e8f0fd,#d4e4f7); color:var(--blue-dark); font-family:'Nunito',sans-serif; font-weight:800; font-size:12px; padding:4px 10px; border-radius:8px; border:1px solid #bdd4f0; letter-spacing:.5px; white-space:nowrap; }
.tp-id-badge svg { width:12px; height:12px; opacity:.6; }

/* ══ BADGES / CHIPS ══ */
.badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; margin-right:4px; margin-bottom:4px; }
.badge-blue   { background:linear-gradient(135deg,#e3f2fd,#d4e4f7); color:#1565c0; border:1px solid #bdd4f0; }
.badge-orange { background:linear-gradient(135deg,#fff3e0,#ffe0b2); color:#e65100; border:1px solid #ffe0b2; }
.badge-green  { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); color:#1e8449; border:1px solid #a9dfbf; }
.badge-purple { background:linear-gradient(135deg,#f5e6ff,#e8d5f5); color:#7d3c98; border:1px solid #d7bde2; }

/* ══ ACTION BUTTONS ══ */
.action-btns { display:flex; gap:6px; flex-wrap:nowrap; }
.btn-icon { width:34px; height:34px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; flex-shrink:0; text-decoration:none; }
.btn-icon svg { width:14px; height:14px; }
.btn-icon.edit { color:var(--blue-mid); }
.btn-icon.edit:hover { background:linear-gradient(135deg,#eef4fd,#d4e4f7); border-color:var(--blue-mid); transform:translateY(-2px); box-shadow:0 3px 10px rgba(58,123,213,.2); }
.btn-icon.del { color:var(--red); }
.btn-icon.del:hover { background:linear-gradient(135deg,#fde8e8,#f9cccc); border-color:var(--red); transform:translateY(-2px); box-shadow:0 3px 10px rgba(231,76,60,.2); }

/* ══ EMPTY STATE ══ */
.empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
.empty-state svg { width:48px; height:48px; margin-bottom:12px; color:#c5d5e8; }
.empty-state p { font-size:14px; font-weight:600; }
.empty-state small { font-size:12.5px; display:block; margin-top:4px; }
.empty-state.nofilter svg { width:56px; height:56px; color:#b0bdd6; }
.empty-state.nofilter p { font-size:16px; font-weight:700; color:var(--text-main); }
.empty-state.nofilter small { font-size:13px; color:var(--text-muted); }

/* ══ PAGINATION ══ */
.table-footer { padding:13px 20px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; font-size:12.5px; color:var(--text-muted); font-weight:600; }
.footer-count { font-size:12.5px; color:var(--text-muted); font-weight:600; }
.pg-nav { display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
.pg-btn { min-width:34px; height:34px; padding:0 6px; border-radius:50%; border:1.5px solid var(--border); background:var(--white); display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; cursor:pointer; transition:all .15s; text-decoration:none; color:var(--text-muted); font-family:'Nunito',sans-serif; }
.pg-btn:hover:not(.active):not(.disabled) { background:linear-gradient(135deg,#f0f5fd,#eef4fd); border-color:var(--blue-mid); transform:translateY(-2px); color:var(--blue-dark); box-shadow:0 3px 10px rgba(74,144,217,.15); }
.pg-btn.active { background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; border-color:transparent; box-shadow:0 3px 12px rgba(58,123,213,.3); }
.pg-btn.disabled { opacity:.4; cursor:default; pointer-events:none; }
.pg-btn svg { width:14px; height:14px; }

/* ══ MODALS ══ */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.45); backdrop-filter:blur(3px); z-index:500; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.open { display:flex; }
.modal { background:var(--white); border-radius:20px; width:100%; max-width:650px; box-shadow:0 24px 64px rgba(30,45,80,.22); overflow:hidden; display:flex; flex-direction:column; position:relative; }
.modal::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid)); border-radius:20px 20px 0 0; z-index:1; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:22px 24px 18px; border-bottom:1.5px solid var(--border); flex-shrink:0; }
.modal-header h2 { font-family:'Poppins',sans-serif; font-weight:800; font-size:18px; background:linear-gradient(135deg,var(--blue-dark),var(--purple)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.modal-close { width:24px; height:24px; border-radius:6px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-muted); transition:background .15s,color .15s; flex-shrink:0; font-size:14px; line-height:1; }
.modal-close:hover { background:linear-gradient(135deg,#fde8e8,#f9cccc); color:var(--red); }
.modal form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }
.modal-body { padding:22px 24px; overflow-y:auto; flex:1; max-height:75vh; -webkit-overflow-scrolling:touch; }
.modal-footer { padding:16px 24px 20px; border-top:1.5px solid var(--border); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; }

/* ══ FORM ══ */
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:700; color:var(--text-main); margin-bottom:6px; }
.form-group label span { color:var(--red); }
.form-control { width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; background:var(--white); }
.form-control:focus { border-color:var(--purple-mid); box-shadow:0 0 0 3px rgba(155,89,182,.12); }
.form-control::placeholder { color:#b0bdd6; }
textarea.form-control { resize:vertical; min-height:80px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }

.check-section { background:linear-gradient(135deg,#f8faff,#f0f4ff); border:1.5px solid var(--border); border-radius:12px; padding:16px; margin-bottom:16px; position:relative; }
.check-section::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid)); border-radius:12px 12px 0 0; }
.check-section-title { font-size:12px; font-weight:700; color:var(--text-muted); letter-spacing:.8px; text-transform:uppercase; margin-bottom:12px; }
.check-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
.check-item { display:flex; align-items:center; gap:8px; cursor:pointer; padding:4px 6px; border-radius:6px; transition:background .15s; font-size:13px; }
.check-item:hover { background:linear-gradient(90deg,#eef4fd,#f0f7ff); }
.check-item input[type="checkbox"] { width:16px; height:16px; accent-color:var(--purple-mid); cursor:pointer; border-radius:4px; flex-shrink:0; }

.project-locked-box { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:10px; border:1.5px solid #c5d8f7; background:linear-gradient(135deg,#f0f6ff,#eef4fd); color:var(--text-main); font-size:14px; font-weight:700; }
.locked-badge { margin-left:auto; background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; font-size:10px; font-weight:700; padding:3px 8px; border-radius:20px; letter-spacing:.5px; text-transform:uppercase; }

.btn-save { padding:10px 26px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(58,123,213,.28); }
.btn-save:hover { opacity:.92; transform:translateY(-1px); box-shadow:0 6px 20px rgba(58,123,213,.35); }
.btn-cancel { padding:10px 22px; border-radius:10px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:background .15s,color .15s; }
.btn-cancel:hover { background:linear-gradient(135deg,#f0f5fd,#eef4fd); color:var(--text-main); }

/* ══ CONFIRM ══ */
.confirm-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.4); backdrop-filter:blur(2px); z-index:600; align-items:center; justify-content:center; padding:16px; }
.confirm-overlay.open { display:flex; }
.confirm-box { background:var(--white); border-radius:16px; padding:28px 28px 22px; max-width:360px; width:100%; box-shadow:0 20px 56px rgba(30,45,80,.2); text-align:center; position:relative; overflow:hidden; }
.confirm-box::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--red),var(--orange)); }
.confirm-box h3 { font-family:'Poppins',sans-serif; font-weight:800; font-size:16px; color:var(--text-main); margin-bottom:8px; }
.confirm-box p { font-size:13.5px; color:var(--text-muted); margin-bottom:22px; line-height:1.6; }
.confirm-btns { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
.btn-confirm-del { padding:9px 22px; border-radius:9px; border:none; background:linear-gradient(135deg,var(--red),#c0392b); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; transition:opacity .2s,box-shadow .2s; box-shadow:0 3px 10px rgba(231,76,60,.25); }
.btn-confirm-del:hover { opacity:.88; box-shadow:0 5px 16px rgba(231,76,60,.35); }
.btn-confirm-cancel { padding:9px 22px; border-radius:9px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; transition:background .15s; }
.btn-confirm-cancel:hover { background:linear-gradient(135deg,#f0f5fd,#eef4fd); }

/* ══ TOAST ══ */
.toast { position:fixed; bottom:28px; right:28px; z-index:999; display:flex; align-items:center; gap:10px; padding:13px 20px; border-radius:12px; font-size:13.5px; font-weight:700; box-shadow:0 8px 28px rgba(30,45,80,.18); min-width:240px; max-width:90vw; }
.toast.success { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); color:#1e8449; border:1.5px solid #a9dfbf; }
.toast.error   { background:linear-gradient(135deg,#fde8e8,#f9cccc); color:#c0392b; border:1.5px solid #f5b7b1; }
.toast svg { width:17px; height:17px; flex-shrink:0; }
.toast-hide { opacity:0; transition:opacity .5s; }

/* ═══════════════════════════════════════════════════════
   RESPONSIVE — Tablet (max 1024px)
   ═══════════════════════════════════════════════════════ */
@media(min-width:769px) and (max-width:1024px){
  .main { padding:24px 18px 48px; }
  .search-wrap { max-width:260px; }
  .toolbar-right { gap:6px; }
  .btn-template span, .btn-import span { display:none; }
  .check-grid { grid-template-columns:1fr 1fr; }
  .modal { border-radius:20px; max-height:85vh; }
}

/* ═══════════════════════════════════════════════════════
   RESPONSIVE — Mobile (max 768px)
   ═══════════════════════════════════════════════════════ */
@media(max-width:768px){
  :root { --sb-w: 260px; --nb-h: 56px; }
  .navbar { padding:0 12px; }
  .ham { display:flex; }
  .nblogo { font-size:19px; }
  .nb-user span:not(.nb-role) { display:none; }
  .nb-role { display:none; }
  .blgout { padding:6px 12px; font-size:12px; border-radius:8px; }
  .sidebar { transform:translateX(-100%); width:var(--sb-w); }
  .sidebar.open { transform:translateX(0); }
  .sidebar-overlay.open { display:block; }
  .page-wrap { margin-left:0; }
  .main { padding:16px 12px 48px; }
  .page-title h1 { font-size:20px; }
  .badge-page { font-size:9px; padding:4px 10px; }
  .toolbar { flex-direction:column; align-items:stretch; gap:10px; }
  .toolbar-left { flex-wrap:wrap; width:100%; gap:8px; }
  .toolbar-left form { flex-wrap:wrap; width:100%; gap:8px; }
  .search-wrap { max-width:100%; min-width:0; width:100%; }
  .filter-select { flex:1; min-width:0; }
  .btn-filter { width:100%; justify-content:center; }
  .toolbar-right { flex-wrap:wrap; gap:6px; width:100%; }
  .btn-template, .btn-import { flex:1; justify-content:center; min-width:0; padding:0 10px; font-size:12px; }
  .btn-template span, .btn-import span { display:inline; }
  .btn-add { width:100%; justify-content:center; padding:12px 20px; margin-top:4px; }
  .active-filter-bar { padding:8px 12px; gap:6px; font-size:12px; }
  .filter-tag { font-size:11px; padding:2px 8px; }
  .btn-clear-active { padding:4px 12px; font-size:11px; }
  .table-card { border-radius:14px; }
  table { min-width:900px; }
  thead th { padding:10px 10px; font-size:11px; }
  tbody td { padding:10px 10px; font-size:12.5px; }
  .badge { font-size:10.5px; padding:2px 8px; }
  .btn-icon { width:32px; height:32px; border-radius:8px; }
  .btn-icon svg { width:12px; height:12px; }
  .tp-id-badge { font-size:11px; padding:3px 8px; }
  .form-row { grid-template-columns:1fr; gap:0; }
  .check-grid { grid-template-columns:1fr 1fr; gap:8px; }
  .modal { max-height:85vh; width:95%; }
  .modal-header { padding:18px 18px 14px; }
  .modal-header h2 { font-size:16px; }
  .modal-body { max-height:60vh; padding:16px 18px; }
  .modal-footer { padding:14px 18px 16px; flex-wrap:wrap; gap:8px; }
  .btn-save, .btn-cancel { flex:1; text-align:center; justify-content:center; }
  .confirm-box { padding:22px 18px 18px; }
  .confirm-box h3 { font-size:15px; }
  .confirm-btns { flex-direction:column; }
  .btn-confirm-del, .btn-confirm-cancel { width:100%; text-align:center; }
  .toast { right:12px; bottom:12px; left:12px; min-width:0; font-size:12.5px; padding:10px 14px; }
  .table-footer { flex-direction:column; text-align:center; padding:10px 14px; gap:8px; }
  .pg-nav { justify-content:center; }
  .pg-btn { min-width:30px; height:30px; font-size:12px; }
  .empty-state { padding:30px 16px; }
  .empty-state p { font-size:13px; }
  .empty-state small { font-size:11px; }
}

/* ═══════════════════════════════════════════════════════
   RESPONSIVE — Small Mobile (max 480px)
   ═══════════════════════════════════════════════════════ */
@media(max-width:480px){
  :root { --nb-h: 52px; }
  .navbar { padding:0 10px; }
  .nblogo { font-size:17px; }
  .blgout { padding:5px 10px; font-size:11px; border-radius:8px; }
  .main { padding:12px 8px 40px; }
  .page-title { gap:8px; margin-bottom:16px; }
  .page-title h1 { font-size:17px; }
  .badge-page { font-size:8px; padding:3px 8px; letter-spacing:1px; }
  .search-input { font-size:16px; padding:10px 14px 10px 36px; }
  .filter-select { font-size:16px; height:42px; }
  .btn-filter, .btn-template, .btn-import, .btn-add { font-size:12px; height:40px; border-radius:8px; }
  .btn-filter svg, .btn-template svg, .btn-import svg, .btn-add svg { width:14px; height:14px; }
  table { min-width:780px; }
  thead th { padding:8px 8px; font-size:10px; letter-spacing:.5px; }
  tbody td { padding:8px 8px; font-size:12px; }
  .req-title { font-size:12.5px; }
  .obj-desc { font-size:10px; max-width:160px; }
  .badge { font-size:10px; padding:2px 6px; border-radius:14px; }
  .btn-icon { width:30px; height:30px; border-radius:8px; }
  .btn-icon svg { width:12px; height:12px; }
  .tp-id-badge { font-size:10px; padding:2px 6px; }
  .modal { max-width:100%; max-height:90vh; border-radius:16px; }
  .modal-header { padding:16px 14px 12px; }
  .modal-header h2 { font-size:15px; }
  .modal-body { padding:12px 14px; max-height:55vh; }
  .modal-footer { padding:12px 14px 14px; gap:6px; }
  .btn-save, .btn-cancel { font-size:13px; padding:10px 18px; border-radius:8px; }
  .check-grid { grid-template-columns:1fr; gap:6px; }
  .check-section { padding:12px; }
  .check-item span { font-size:12px; }
  .check-item input[type="checkbox"] { width:18px; height:18px; }
  .form-control { padding:10px 12px; font-size:16px; border-radius:8px; }
  textarea.form-control { min-height:70px; }
  .confirm-box { padding:18px 14px 16px; border-radius:14px; }
  .confirm-box h3 { font-size:14px; }
  .toast { font-size:12px; padding:8px 12px; border-radius:10px; gap:8px; left:8px; right:8px; bottom:8px; }
  .pg-btn { min-width:28px; height:28px; font-size:11px; border-radius:50%; }
  .table-footer { padding:8px 10px; font-size:11px; }
  .footer-count { font-size:11px; }
  .empty-state { padding:24px 12px; }
  .empty-state svg { width:36px; height:36px; }
  .empty-state p { font-size:12px; }
  .empty-state small { font-size:10.5px; }
  .active-filter-bar { padding:6px 10px; font-size:11px; gap:5px; }
  .filter-tag { font-size:10px; padding:2px 6px; }
}
</style>
</head>
<body>

<!-- ══════════ NAVBAR ══════════ -->
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

<!-- ══════════ SIDEBAR OVERLAY ══════════ -->
<div class="sidebar-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<!-- ══════════ SIDEBAR ══════════ -->
<aside class="sidebar" id="sidebar">
  <a href="../dash/user_dash.php" class="sb-home">
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
    My Projects
  </a>
  <a href="../user_page/u_requirement.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
    Requirements
  </a>
  <a href="../user_page/u_test_plans.php" class="sb-link active">
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

<!-- ══════════ PAGE WRAP ══════════ -->
<div class="page-wrap" id="pageWrap">
<div class="main">
  <div class="page-title">
    <h1>My Test Plans</h1>
    <span class="badge-page">Workspace</span>
  </div>

  <!-- TOOLBAR -->
  <div class="toolbar">
    <div class="toolbar-left">
      <form method="GET" action="" id="filterForm" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <div class="search-wrap">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="search" class="search-input" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <select name="filter_client" class="filter-select" id="filterClient" onchange="updateProjectDropdown(this.value)">
            <option value="">All Clients</option>
            <?php foreach ($clients_list as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filter_client == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="filter_project" class="filter-select" id="filterProject" onchange="checkFormState()">
            <option value="">All Projects</option>
            <?php foreach ($projects_list as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $filter_project == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-filter">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
          Apply Filter
        </button>
        <?php if ($filters_applied): ?>
        <a href="?" class="btn-clear-active">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Clear
        </a>
        <?php endif; ?>
      </form>
    </div>
    <div class="toolbar-right">
      <button type="button" class="btn-template" onclick="downloadTemplate()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span>Template</span>
      </button>
      <button type="button" class="btn-import" onclick="openImportModal()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <span>Import</span>
      </button>
      <button type="button" id="btnAddTestPlan" class="btn-add" onclick="openAddModal()">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Test Plan
      </button>
    </div>
  </div>

  <?php if ($filters_applied): ?>
  <!-- ACTIVE FILTER TAGS -->
  <div class="active-filter-bar" style="margin-bottom:14px;">
    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
    <span>Active Filters:</span>
    <?php
      if ($filter_client) {
          foreach ($clients_list as $c) {
              if ($c['id'] == $filter_client) echo '<span class="filter-tag">Client: ' . htmlspecialchars($c['name']) . '</span>';
          }
      }
      if ($filter_project) {
          foreach ($projects_list as $p) {
              if ($p['id'] == $filter_project) echo '<span class="filter-tag">Project: ' . htmlspecialchars($p['name']) . '</span>';
          }
      }
      if ($search) echo '<span class="filter-tag">Search: "' . htmlspecialchars($search) . '"</span>';
    ?>
    <span style="margin-left:auto; font-size:12px; color:var(--text-muted);"><?= $total ?> result(s)</span>
  </div>
  <?php endif; ?>

  <!-- TABLE -->
  <div class="table-card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>S.No.</th>
            <th>TP ID</th>
            <th>Project Name</th>
            <th>Title</th>
            <th>Objective</th>
            <th>Testing Types</th>
            <th>Technologies</th>
            <th>Test Lead</th>
            <th>Project Lead</th>
            <th>Created At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($test_plans)): ?>
          <tr><td colspan="11">
            <div class="empty-state<?= !$filters_applied ? ' nofilter' : '' ?>">
              <?php if (!$filters_applied): ?>
              <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
              <p>Apply filters to view Test Plans</p>
              <small>Select a Client or Project above and click "Apply Filter"</small>
              <?php else: ?>
              <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
              <p>No Test Plans found matching your filters.</p>
              <small>Try changing the filters or click "Clear" to reset.</small>
              <?php endif; ?>
            </div>
          </td></tr>
          <?php else: ?>
            <?php $sn = ($page - 1) * 10 + 1; foreach ($test_plans as $tp):
              $is_assigned = in_array((int)$tp['project_id'], $user_assigned_project_ids);
              $tp_display_id = 'TP-' . str_pad($tp['tp_seq'], 3, '0', STR_PAD_LEFT);
            ?>
            <tr>
              <td><?= $sn++ ?></td>
              <td>
                <span class="tp-id-badge">
                  <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
                  <?= htmlspecialchars($tp_display_id) ?>
                </span>
              </td>
              <td><div class="proj-name"><?= htmlspecialchars($tp['project_name'] ?? '—') ?></div></td>
              <td><div class="req-title"><?= htmlspecialchars($tp['title']) ?></div></td>
              <td><div class="obj-desc"><?= htmlspecialchars(substr($tp['objective'], 0, 100)) ?>...</div></td>
              <td>
                <?php foreach ($tp['testing_types'] as $t): ?>
                  <span class="badge badge-blue"><?= htmlspecialchars($t) ?></span>
                <?php endforeach; ?>
              </td>
              <td>
                <?php foreach ($tp['technologies'] as $t): ?>
                  <span class="badge badge-orange"><?= htmlspecialchars($t) ?></span>
                <?php endforeach; ?>
              </td>
              <td><?= htmlspecialchars($tp['test_lead_name']    ?? '—') ?></td>
              <td><?= htmlspecialchars($tp['project_lead_name'] ?? '—') ?></td>
              <td style="font-size:12px;white-space:nowrap;"><?= fmt_date($tp['created_at']) ?></td>
              <td>
                <div class="action-btns">
                  <?php if ($is_assigned): ?>
                  <button class="btn-icon edit" onclick='openEditModal(<?= json_encode($tp) ?>)'>
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <button class="btn-icon del" onclick="confirmDelete(<?= $tp['id'] ?>, '<?= htmlspecialchars(addslashes($tp['title']), ENT_QUOTES) ?>')">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                  </button>
                  <?php else: ?>
                  <span class="badge badge-purple" style="font-size:10px;">View Only</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="table-footer">
      <span class="footer-count">Showing <?= count($test_plans) ?> of <?= $total ?></span>
      <div class="pg-nav">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="pg-btn <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
</div>

<!-- ═══════════════ ADD / EDIT MODAL ═══════════════ -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <h2 id="modalTitle">Add Test Plan</h2>
      <button class="modal-close" onclick="closeModal()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12" height="12"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" id="planForm" style="display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden;">
      <input type="hidden" name="action"  id="planAction" value="add"/>
      <input type="hidden" name="edit_id" id="eId"/>
      <div class="modal-body">

        <div class="form-group" id="projectFieldGroup">
          <label>Project <span>*</span></label>
          <input type="hidden" name="project_id" id="projectIdHidden"/>
          <select class="form-control" id="pProjectSelect">
            <option value="">Select Project</option>
          </select>
          <div id="projectLockedDisplay" style="display:none;">
            <div class="project-locked-box">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span id="projectLockedName"></span>
              <span class="locked-badge">Locked</span>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label>Title <span>*</span></label>
          <input type="text" name="title" id="eTitle" class="form-control" required placeholder="Enter test plan title"/>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Objective <span>*</span></label>
            <textarea name="objective" id="eObj" class="form-control" style="height:80px; resize:vertical;" required placeholder="What is the objective?"></textarea>
          </div>
          <div class="form-group">
            <label>Scope <span>*</span></label>
            <textarea name="scope" id="eScope" class="form-control" style="height:80px; resize:vertical;" required placeholder="Define the scope"></textarea>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Project Lead (Developer)</label>
            <select name="project_lead_id" id="ePLead" class="form-control">
              <option value="">Select Developer</option>
              <?php foreach ($dev_list as $u): ?>
                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Test Lead (QA)</label>
            <select name="test_lead_id" id="eTLead" class="form-control">
              <option value="">Select QA</option>
              <?php foreach ($qa_list as $u): ?>
                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>Roles Covered</label>
          <input type="text" name="roles_covered" id="eRoles" class="form-control" placeholder="e.g. Admin, User, Guest"/>
        </div>

        <div class="check-section">
          <div class="check-section-title">Testing Types</div>
          <div class="check-grid">
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Manual testing"> Manual testing</label>
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Automation testing"> Automation testing</label>
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Performance Testing"> Performance Testing</label>
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Security Testing"> Security Testing</label>
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Alpha testing"> Alpha testing</label>
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Beta testing"> Beta testing</label>
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Smoke Testing"> Smoke Testing</label>
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Sanity testing"> Sanity testing</label>
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Functional testing"> Functional testing</label>
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Integration testing"> Integration testing</label>
            <label class="check-item"><input type="checkbox" name="testing_types[]" value="Regression testing"> Regression testing</label>
          </div>
        </div>

        <div class="check-section">
          <div class="check-section-title">Technologies</div>
          <div class="check-grid">
            <label class="check-item"><input type="checkbox" name="technologies[]" value="MySQL"> MySQL</label>
            <label class="check-item"><input type="checkbox" name="technologies[]" value="WordPress"> WordPress</label>
            <label class="check-item"><input type="checkbox" name="technologies[]" value="Codeigniter"> Codeigniter</label>
            <label class="check-item"><input type="checkbox" name="technologies[]" value="Postgre SQL"> Postgre SQL</label>
            <label class="check-item"><input type="checkbox" name="technologies[]" value="React"> React</label>
            <label class="check-item"><input type="checkbox" name="technologies[]" value="NodeJS"> NodeJS</label>
          </div>
          <div style="margin-top:15px; border-top:1px solid var(--border); padding-top:10px;">
            <label style="font-size:12px; font-weight:600; margin-bottom:5px; display:block;">Add Other Tech:</label>
            <div style="display:flex; gap:8px; align-items:flex-start; flex-wrap:wrap;">
              <select id="techSelect" class="form-control" style="flex:0 0 160px;">
                <option value="">Select Tech...</option>
                <option value="Java">Java</option>
                <option value="Python">Python</option>
                <option value="Laravel">Laravel</option>
                <option value="Django">Django</option>
                <option value="Vue">Vue</option>
                <option value="Angular">Angular</option>
              </select>
              <div id="customTechList" style="display:flex; flex-wrap:wrap; gap:5px; align-items:center;"></div>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-save">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════ IMPORT MODAL ═══════════════ -->
<div class="modal-overlay" id="importModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Import Test Plans (CSV)</h2>
      <button class="modal-close" onclick="closeImportModal()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12" height="12"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" enctype="multipart/form-data" id="importForm" style="display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden;">
      <input type="hidden" name="action" value="import_csv"/>
      <div class="modal-body">

        <div class="form-group">
          <label>Default Project <span>*</span></label>
          <select name="import_project_id" id="importProjectSelect" class="form-control" required>
            <option value="">Select Project</option>
          </select>
        </div>

        <div class="form-group">
          <label>CSV File <span>*</span></label>
          <div id="csvDropZone" style="border:2px dashed var(--border); border-radius:12px; padding:30px 16px; text-align:center; cursor:pointer; transition:border-color .2s, background .2s;" onclick="document.getElementById('csvFileInput').click()">
            <svg width="36" height="36" fill="none" stroke="var(--text-muted)" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom:8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <p style="font-size:13px; font-weight:600; color:var(--text-muted);" id="csvDropText">Drag & drop CSV here or click to browse</p>
            <input type="file" id="csvFileInput" name="csv_file" accept=".csv" style="display:none;" required/>
          </div>
        </div>

        <div style="background:linear-gradient(135deg,#f8faff,#f0f4ff); border:1.5px solid var(--border); border-radius:12px; padding:14px 16px; margin-top:10px;">
          <p style="font-size:12px; font-weight:700; color:var(--text-main); margin-bottom:6px;">CSV Format Guide:</p>
          <code style="font-size:11px; color:var(--text-muted); display:block; line-height:1.6; word-break:break-all;">project_name, title, objective, scope, testing_types, technologies, roles_covered, project_lead_id, test_lead_id</code>
          <p style="font-size:11px; color:var(--text-muted); margin-top:6px;"><strong>testing_types / technologies:</strong> comma-separated within the cell (e.g. "Manual testing,Smoke Testing")</p>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeImportModal()">Cancel</button>
        <button type="submit" class="btn-save">Import</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════ CONFIRM DELETE ═══════════════ -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <h3>Delete Test Plan?</h3>
    <p id="confirmMsg">Are you sure you want to delete "<span id="confirmName"></span>"?</p>
    <div class="confirm-btns">
      <button class="btn-confirm-cancel" onclick="closeConfirm()">Cancel</button>
      <button class="btn-confirm-del" id="confirmDelBtn">Delete</button>
    </div>
  </div>
</div>

<!-- ═══════════════ TOAST ═══════════════ -->
<?php if ($msg): ?>
<div class="toast <?= $msg_type ?>" id="toastEl">
  <?php if ($msg_type === 'success'): ?>
  <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
  <?php else: ?>
  <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
  <?php endif; ?>
  <span><?= htmlspecialchars($msg) ?></span>
</div>
<?php endif; ?>

<script>
// ═══════════════════════════════════════════════════════
//  CONFIG
// ═══════════════════════════════════════════════════════
const allowedProjectIds = <?= $allowed_project_ids_json ?>;
const allProjects = <?= $all_projects_json ?>;
const assignedProjects = <?= $assigned_projects_json ?>;
const tpIdMap = <?= $tp_id_map_json ?>;

function isProjectAllowed(pid) {
    return allowedProjectIds.includes(Number(pid));
}

// ═══════════════════════════════════════════════════════
//  DOM READY
// ═══════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
    // Clear stuck overlays
    document.querySelectorAll('.modal-overlay, .confirm-overlay').forEach(function(el) {
        el.classList.remove('open');
    });

    // Auto-open add modal on add_err
    const params = new URLSearchParams(window.location.search);
    if (params.has('add_err') || params.has('not_allowed')) {
        openAddModal();
    }

    // Clean URL params after toast
    if (params.has('added') || params.has('updated') || params.has('deleted') || params.has('imported') || params.has('add_err') || params.has('edit_err') || params.has('import_err') || params.has('not_allowed')) {
        setTimeout(function() {
            const url = new URL(window.location);
            url.searchParams.delete('added');
            url.searchParams.delete('updated');
            url.searchParams.delete('deleted');
            url.searchParams.delete('imported');
            url.searchParams.delete('imp_err');
            url.searchParams.delete('add_err');
            url.searchParams.delete('edit_err');
            url.searchParams.delete('import_err');
            url.searchParams.delete('not_allowed');
            window.history.replaceState({}, '', url);
        }, 100);
    }

    // Project dropdown is already populated by PHP — no need to overwrite on load.
    // Only filter when client changes via onchange="updateProjectDropdown(this.value)"

    // Toast auto-hide
    const toastEl = document.getElementById('toastEl');
    if (toastEl) {
        setTimeout(() => { toastEl.classList.add('toast-hide'); }, 3500);
        setTimeout(() => { toastEl.remove(); }, 4200);
    }

    // Populate import project dropdown with assigned projects only
    populateImportDropdown();
});

// ═══════════════════════════════════════════════════════
//  SIDEBAR
// ═══════════════════════════════════════════════════════
function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('sbOverlay');
    const btn = document.getElementById('hamBtn');
    sb.classList.toggle('open');
    ov.classList.toggle('open');
    btn.classList.toggle('open');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sbOverlay').classList.remove('open');
    document.getElementById('hamBtn').classList.remove('open');
}

// ═══════════════════════════════════════════════════════
//  FILTER DROPDOWNS
// ═══════════════════════════════════════════════════════
function updateProjectDropdown(clientId) {
    const projSel = document.getElementById('filterProject');
    const currentVal = projSel.value;
    projSel.innerHTML = '<option value="">All Projects</option>';

    const filtered = clientId
        ? allProjects.filter(p => p.client_id == clientId)
        : allProjects;

    filtered.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.name;
        if (p.id == currentVal) opt.selected = true;
        projSel.appendChild(opt);
    });
}

function checkFormState() {
    // Just a helper — form submits normally
}

// ═══════════════════════════════════════════════════════
//  MODAL — PROJECT FIELD
// ═══════════════════════════════════════════════════════
let modalProjectLocked = false;

function lockProject(projectId, projectName) {
    modalProjectLocked = true;
    document.getElementById('projectIdHidden').value = projectId;
    document.getElementById('pProjectSelect').style.display = 'none';
    document.getElementById('projectLockedDisplay').style.display = 'block';
    document.getElementById('projectLockedName').textContent = projectName;
}

function unlockProject() {
    modalProjectLocked = false;
    document.getElementById('projectIdHidden').value = '';
    document.getElementById('pProjectSelect').style.display = 'block';
    document.getElementById('projectLockedDisplay').style.display = 'none';
}

function populateModalProjectDropdown(selectedId) {
    const sel = document.getElementById('pProjectSelect');
    sel.innerHTML = '<option value="">Select Project</option>';

    assignedProjects.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.name;
        if (p.id == selectedId) opt.selected = true;
        sel.appendChild(opt);
    });

    sel.onchange = function() {
        const pid = Number(this.value);
        if (!isProjectAllowed(pid)) {
            this.value = '';
            document.getElementById('projectIdHidden').value = '';
            showToast('You can only add test plans for your assigned projects.', 'error');
            return;
        }
        document.getElementById('projectIdHidden').value = this.value;
    };
}

function populateImportDropdown() {
    const sel = document.getElementById('importProjectSelect');
    sel.innerHTML = '<option value="">Select Project</option>';

    assignedProjects.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.name;
        sel.appendChild(opt);
    });
}

// ═══════════════════════════════════════════════════════
//  ADD / EDIT MODAL
// ═══════════════════════════════════════════════════════
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Test Plan';
    document.getElementById('planAction').value = 'add';
    document.getElementById('eId').value = '';
    document.getElementById('planForm').reset();
    document.getElementById('customTechList').innerHTML = '';
    unlockProject();
    populateModalProjectDropdown('');

    document.querySelectorAll('#planForm input[type="checkbox"]').forEach(cb => cb.checked = false);

    document.getElementById('modal-overlay').classList.add('open');
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function openEditModal(tp) {
    document.getElementById('modalTitle').textContent = 'Edit Test Plan';
    document.getElementById('planAction').value = 'edit';
    document.getElementById('eId').value = tp.id;
    document.getElementById('eTitle').value = tp.title || '';
    document.getElementById('eObj').value = tp.objective || '';
    document.getElementById('eScope').value = tp.scope || '';
    document.getElementById('ePLead').value = tp.project_lead_id || '';
    document.getElementById('eTLead').value = tp.test_lead_id || '';
    document.getElementById('eRoles').value = tp.roles_covered || '';
    document.getElementById('customTechList').innerHTML = '';

    if (!isProjectAllowed(tp.project_id)) {
        showToast('You can only edit test plans for your assigned projects.', 'error');
        return;
    }

    lockProject(tp.project_id, tp.project_name || 'Project #' + tp.project_id);

    document.querySelectorAll('#planForm input[name="testing_types[]"]').forEach(cb => {
        cb.checked = Array.isArray(tp.testing_types) && tp.testing_types.includes(cb.value);
    });

    const techVals = Array.isArray(tp.technologies) ? tp.technologies : [];
    const predefinedTechs = ['MySQL','WordPress','Codeigniter','Postgre SQL','React','NodeJS'];
    document.querySelectorAll('#planForm input[name="technologies[]"]').forEach(cb => {
        cb.checked = techVals.includes(cb.value);
    });

    techVals.forEach(t => {
        if (!predefinedTechs.includes(t)) {
            addCustomTechElement(t);
        }
    });

    document.getElementById('modal-overlay').classList.add('open');
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function closeModal() {
    document.getElementById('modal-overlay').classList.remove('open');
}

// ═══════════════════════════════════════════════════════
//  CUSTOM TECH
// ═══════════════════════════════════════════════════════
document.getElementById('techSelect').addEventListener('change', function() {
    if (this.value) {
        addCustomTechElement(this.value);
        this.value = '';
    }
});

function addCustomTechElement(name) {
    const container = document.getElementById('customTechList');
    const existing = container.querySelectorAll('input[type="hidden"]');
    for (let i = 0; i < existing.length; i++) {
        if (existing[i].value === name) return;
    }

    const tag = document.createElement('span');
    tag.className = 'badge badge-orange';
    tag.style.cursor = 'pointer';
    tag.style.display = 'inline-flex';
    tag.style.alignItems = 'center';
    tag.style.gap = '4px';
    tag.innerHTML = name + ' <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'technologies[]';
    hidden.value = name;

    tag.onclick = function() {
        tag.remove();
        hidden.remove();
    };

    container.appendChild(tag);
    container.appendChild(hidden);
}

// ═══════════════════════════════════════════════════════
//  DELETE CONFIRM
// ═══════════════════════════════════════════════════════
let pendingDeleteId = null;

function confirmDelete(id, name) {
    pendingDeleteId = id;
    document.getElementById('confirmName').textContent = name;
    document.getElementById('confirmOverlay').classList.add('open');
}

function closeConfirm() {
    document.getElementById('confirmOverlay').classList.remove('open');
    pendingDeleteId = null;
}

document.getElementById('confirmDelBtn').addEventListener('click', function() {
    if (!pendingDeleteId) return;
    fetch('?delete=' + pendingDeleteId + '&ajax=1', {method: 'GET'})
        .then(r => r.json())
        .then(data => {
            closeConfirm();
            if (data.success) {
                showToast('Test Plan deleted.', 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(data.error || 'Delete failed.', 'error');
            }
        })
        .catch(() => {
            closeConfirm();
            showToast('Delete failed.', 'error');
        });
});

// ═══════════════════════════════════════════════════════
//  IMPORT MODAL
// ═══════════════════════════════════════════════════════
function openImportModal() {
    document.getElementById('importModal').classList.add('open');
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function closeImportModal() {
    document.getElementById('importModal').classList.remove('open');
}

// CSV drag & drop
const dropZone = document.getElementById('csvDropZone');
const csvInput = document.getElementById('csvFileInput');
const csvText = document.getElementById('csvDropText');

['dragenter','dragover'].forEach(evt => {
    dropZone.addEventListener(evt, e => {
        e.preventDefault();
        dropZone.style.borderColor = 'var(--purple-mid)';
        dropZone.style.background = 'linear-gradient(135deg,#f5e6ff,#e8d5f5)';
    });
});
['dragleave','drop'].forEach(evt => {
    dropZone.addEventListener(evt, e => {
        e.preventDefault();
        dropZone.style.borderColor = 'var(--border)';
        dropZone.style.background = '';
    });
});
dropZone.addEventListener('drop', e => {
    const files = e.dataTransfer.files;
    if (files.length) {
        csvInput.files = files;
        csvText.textContent = files[0].name;
    }
});
csvInput.addEventListener('change', function() {
    if (this.files.length) csvText.textContent = this.files[0].name;
});

// ═══════════════════════════════════════════════════════
//  TEMPLATE DOWNLOAD
// ═══════════════════════════════════════════════════════
function downloadTemplate() {
    const csv = 'project_name,title,objective,scope,testing_types,technologies,roles_covered,project_lead_id,test_lead_id\n';
    const blob = new Blob([csv], {type: 'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'test_plan_template.csv';
    a.click();
}

// ═══════════════════════════════════════════════════════
//  TOAST
// ═══════════════════════════════════════════════════════
function showToast(message, type) {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();

    const div = document.createElement('div');
    div.className = 'toast ' + type;
    const icon = type === 'success'
        ? '<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
        : '<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
    div.innerHTML = icon + '<span>' + message + '</span>';
    document.body.appendChild(div);

    setTimeout(() => { div.classList.add('toast-hide'); }, 3500);
    setTimeout(() => { div.remove(); }, 4200);
}
</script>
</body>
</html>
