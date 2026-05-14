<?php
// ── user_page/u_test_plans.php ─────────────────────────────
// USER VERSION: Test Plan edit request admin ko jayega
// Admin accept kare → plan update hoga | Reject → user ko notify + re-request option
session_start();
include '../config/db.php';

$msg      = '';
$msg_type = '';

// ════════════════════════════════════════════════════════
//  SMART SESSION DETECTION
// ════════════════════════════════════════════════════════
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
//  AUTO-CREATE test_plan_edit_requests TABLE
// ════════════════════════════════════════════════════════
$conn->query("CREATE TABLE IF NOT EXISTS test_plan_edit_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_plan_id INT NOT NULL,
    project_id INT NOT NULL,
    requested_by INT NOT NULL,
    title VARCHAR(255),
    objective TEXT,
    scope TEXT,
    testing_types TEXT COMMENT 'JSON array',
    technologies TEXT COMMENT 'JSON array',
    project_lead_id INT DEFAULT NULL,
    test_lead_id INT DEFAULT NULL,
    roles_covered TEXT,
    status ENUM('pending','accepted','rejected') DEFAULT 'pending',
    admin_reply TEXT,
    reviewed_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL
)");

// PRG Messages
if (isset($_GET['requested'])) {
    $msg      = 'Test Plan edit request admin ko bhej di gayi! Admin accept kare to update hoga.';
    $msg_type = 'success';
}
if (isset($_GET['cancelled'])) {
    $msg      = 'Request cancel ho gayi.';
    $msg_type = 'success';
}

// ════════════════════════════════════════════════════════
//  SUBMIT TEST PLAN EDIT REQUEST (NOT direct update!)
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_form'] ?? '') === 'send_edit_request') {
    $tp_id         = (int)($_POST['test_plan_id']   ?? 0);
    $project_id    = (int)($_POST['project_id']      ?? 0);
    $title         = trim($_POST['title']            ?? '');
    $objective     = trim($_POST['objective']        ?? '');
    $scope         = trim($_POST['scope']            ?? '');
    $testing_types = json_encode($_POST['testing_types'] ?? []);
    $technologies  = json_encode($_POST['technologies']  ?? []);
    $project_lead_id = (int)($_POST['project_lead_id'] ?? 0) ?: null;
    $test_lead_id    = (int)($_POST['test_lead_id']    ?? 0) ?: null;
    $roles_covered   = trim($_POST['roles_covered']     ?? '');

    if (!$tp_id) {
        $msg = 'Test Plan ID missing.'; $msg_type = 'error';
    } elseif (!$current_user_id) {
        $msg = 'User ID nahi mili. Login karein.'; $msg_type = 'error';
    } else {
        // Check: already pending request for this test plan by this user?
        $chk = $conn->prepare("SELECT id FROM test_plan_edit_requests WHERE test_plan_id = ? AND requested_by = ? AND status = 'pending'");
        $chk->bind_param('ii', $tp_id, $current_user_id);
        $chk->execute();
        $chk->store_result();
        $dup_count = $chk->num_rows;
        $chk->close();

        if ($dup_count > 0) {
            $msg = 'Is Test Plan ke liye already ek pending request hai! Admin ka wait karein ya purani request cancel karein.';
            $msg_type = 'error';
        } else {
            // Store edit data — admin accept kare to actual update hoga
            $stmt = $conn->prepare(
                "INSERT INTO test_plan_edit_requests
                 (test_plan_id, project_id, requested_by, title, objective, scope, testing_types, technologies, project_lead_id, test_lead_id, roles_covered, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->bind_param('iiissssiiis',
                $tp_id, $project_id, $current_user_id,
                $title, $objective, $scope,
                $testing_types, $technologies,
                $project_lead_id, $test_lead_id,
                $roles_covered
            );

            if ($stmt->execute()) {
                $stmt->close();
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Request admin ko bhej di gayi!']);
                    exit;
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?requested=1');
                exit;
            } else {
                $msg = 'Error: ' . htmlspecialchars($stmt->error); $msg_type = 'error';
                $stmt->close();
            }
        }
    }

    if (isset($_POST['ajax']) && $msg_type === 'error') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }
}

// ════════════════════════════════════════════════════════
//  CANCEL REQUEST (user can cancel own pending request)
// ════════════════════════════════════════════════════════
if (isset($_GET['cancel_request'])) {
    $cancel_id = (int)$_GET['cancel_request'];
    $del = $conn->prepare("DELETE FROM test_plan_edit_requests WHERE id = ? AND requested_by = ? AND status = 'pending'");
    $del->bind_param('ii', $cancel_id, $current_user_id);
    $del->execute();
    $del->close();
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?cancelled=1');
    exit;
}

// ════════════════════════════════════════════════════════
//  AJAX: get_test_plan_data (for edit modal)
// ════════════════════════════════════════════════════════
if (isset($_GET['get_test_plan_data'])) {
    $tpid = (int)$_GET['get_test_plan_data'];

    $stmt = $conn->prepare(
        "SELECT tp.*, p.name AS project_name
         FROM test_plans tp
         LEFT JOIN projects p ON p.id = tp.project_id
         WHERE tp.id = ?"
    );
    $stmt->bind_param('i', $tpid);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$plan) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Test Plan not found']);
        exit;
    }

    // Check if user has pending request for this test plan
    $has_pending    = false;
    $pending_req_id = null;
    $chk = $conn->prepare("SELECT id FROM test_plan_edit_requests WHERE test_plan_id = ? AND requested_by = ? AND status = 'pending' LIMIT 1");
    $chk->bind_param('ii', $tpid, $current_user_id);
    $chk->execute();
    $chkRes = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($chkRes) {
        $has_pending    = true;
        $pending_req_id = (int)$chkRes['id'];
    }

    $out = [
        'success'         => true,
        'id'              => (int)$plan['id'],
        'project_id'      => (int)$plan['project_id'],
        'project_name'    => $plan['project_name'] ?? '',
        'title'           => $plan['title'],
        'objective'       => $plan['objective'],
        'scope'           => $plan['scope'],
        'testing_types'   => json_decode($plan['testing_types'] ?? '[]', true),
        'technologies'    => json_decode($plan['technologies'] ?? '[]', true),
        'project_lead_id' => $plan['project_lead_id'] ? (int)$plan['project_lead_id'] : '',
        'test_lead_id'    => $plan['test_lead_id'] ? (int)$plan['test_lead_id'] : '',
        'roles_covered'   => $plan['roles_covered'] ?? '',
        'has_pending'     => $has_pending,
        'pending_req_id'  => $pending_req_id,
    ];

    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

// ════════════════════════════════════════════════════════
//  FETCH USER'S ASSIGNED PROJECTS
// ════════════════════════════════════════════════════════
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

// Fetch clients for user's projects
$clients_list = [];
if (!empty($user_project_ids)) {
    $client_ids = array_unique(array_filter(array_column($user_projects, 'client_id')));
    if (!empty($client_ids)) {
        $cid_list = implode(',', array_map('intval', $client_ids));
        $c_res = $conn->query("SELECT id, name FROM clients WHERE id IN ($cid_list) AND status='active' ORDER BY name");
        if ($c_res) while ($r = $c_res->fetch_assoc()) $clients_list[] = $r;
    }
}

// Fetch users list (for modal dropdowns)
$users_list = [];
$u_res = $conn->query("SELECT id, name, username FROM users ORDER BY name");
if ($u_res) while ($r = $u_res->fetch_assoc()) $users_list[] = $r;

// ════════════════════════════════════════════════════════
//  FETCH MY EDIT REQUESTS
// ════════════════════════════════════════════════════════
$my_requests = [];
$mr = $conn->prepare(
    "SELECT r.*, tp.title AS plan_title, p.name AS project_name
     FROM test_plan_edit_requests r
     LEFT JOIN test_plans tp ON tp.id = r.test_plan_id
     LEFT JOIN projects p ON p.id = r.project_id
     WHERE r.requested_by = ?
     ORDER BY
        CASE WHEN r.status = 'pending' THEN 0
             WHEN r.status = 'rejected' THEN 1
             ELSE 2 END,
        r.created_at DESC
     LIMIT 50"
);
$mr->bind_param('i', $current_user_id);
$mr->execute();
$mrRes = $mr->get_result();
while ($r = $mrRes->fetch_assoc()) $my_requests[] = $r;
$mr->close();

// Build request lookup: test_plan_id => latest request
$request_lookup = [];
foreach ($my_requests as $req) {
    $tpid = $req['test_plan_id'];
    if (!isset($request_lookup[$tpid]) || $req['created_at'] > $request_lookup[$tpid]['created_at']) {
        $request_lookup[$tpid] = $req;
    }
}

// ════════════════════════════════════════════════════════
//  FETCH TEST PLANS (only for user's assigned projects)
// ════════════════════════════════════════════════════════
$filter_client  = (int)($_GET['filter_client']  ?? 0);
$filter_project = (int)($_GET['filter_project'] ?? 0);
$search         = trim($_GET['search']          ?? '');
$filters_applied = $filter_client || $filter_project || $search;

$test_plans  = [];
$total       = 0;
$per_page    = 10;
$page        = 1;
$total_pages = 1;

if (!empty($user_project_ids)) {
    $pid_list = implode(',', array_map('intval', $user_project_ids));
    $where        = ["tp.project_id IN ($pid_list)"];
    $bind_params  = [];
    $bind_types   = '';

    if ($filter_client) {
        $where[]       = 'p.client_id = ?';
        $bind_params[] = $filter_client;
        $bind_types   .= 'i';
    }
    if ($filter_project) {
        $where[]       = 'tp.project_id = ?';
        $bind_params[] = $filter_project;
        $bind_types   .= 'i';
    }
    if ($search) {
        $like          = "%$search%";
        $where[]       = '(tp.title LIKE ? OR tp.objective LIKE ?)';
        $bind_params[] = $like;
        $bind_params[] = $like;
        $bind_types   .= 'ss';
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);

    // Count
    $csql = "SELECT COUNT(*) FROM test_plans tp LEFT JOIN projects p ON p.id=tp.project_id $whereStr";
    $cstmt = $conn->prepare($csql);
    if ($bind_types !== '') $cstmt->bind_param($bind_types, ...$bind_params);
    $cstmt->execute();
    $cstmt->bind_result($total);
    $cstmt->fetch();
    $cstmt->close();

    $total_pages = max(1, ceil($total / $per_page));
    $page        = max(1, min((int)($_GET['page'] ?? 1), $total_pages));
    $offset      = ($page - 1) * $per_page;

    // Fetch
    $qparams   = $bind_params;
    $qtypes    = $bind_types . 'ii';
    $qparams[] = $per_page;
    $qparams[] = $offset;

    $sql = "SELECT tp.*, p.name AS project_name, pl.name AS project_lead_name, tl.name AS test_lead_name
            FROM test_plans tp
            LEFT JOIN projects p ON p.id = tp.project_id
            LEFT JOIN users pl ON pl.id = tp.project_lead_id
            LEFT JOIN users tl ON tl.id = tp.test_lead_id
            $whereStr
            ORDER BY tp.created_at DESC LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($qtypes, ...$qparams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['testing_types'] = json_decode($row['testing_types'] ?? '[]', true);
        $row['technologies']  = json_decode($row['technologies']  ?? '[]', true);

        // Check request status for this plan
        $tpid_key = (int)$row['id'];
        $row['request_status'] = null;
        $row['request_id']     = null;
        $row['admin_reply']    = null;
        if (isset($request_lookup[$tpid_key])) {
            $row['request_status'] = $request_lookup[$tpid_key]['status'];
            $row['request_id']     = $request_lookup[$tpid_key]['id'];
            $row['admin_reply']    = $request_lookup[$tpid_key]['admin_reply'];
        }

        $test_plans[] = $row;
    }
    $stmt->close();
}

$conn->close();

function fmt_date($d) { return $d ? date('d M Y', strtotime($d)) : '—'; }
function req_status_badge(string $s): string {
    return match($s) {
        'pending'  => '<span class="badge badge-pending">Pending</span>',
        'accepted' => '<span class="badge badge-accepted">Accepted</span>',
        'rejected' => '<span class="badge badge-rejected">Rejected</span>',
        default    => htmlspecialchars($s),
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TestiFy — My Test Plans</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<style>
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
  --orange:     #f39c12;
  --purple:     #8e44ad;
  --sb-w:       240px;
  --nb-h:       60px;
}
body { min-height:100vh; background:var(--bg); font-family:'Nunito',sans-serif; color:var(--text-main); overflow-x:hidden; }

/* NAVBAR */
.navbar { background:var(--white); border-bottom:1.5px solid var(--border); display:flex; align-items:center; justify-content:space-between; padding:0 20px; height:var(--nb-h); position:fixed; top:0; left:0; right:0; z-index:300; box-shadow:0 2px 16px rgba(74,144,217,.09); }
.nblogo { font-family:'Poppins',sans-serif; font-weight:800; font-size:20px; background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; text-decoration:none; white-space:nowrap; display:flex; align-items:center; gap:10px; }
.ham { display:none; flex-direction:column; justify-content:center; gap:5px; width:40px; height:40px; border:none; background:transparent; cursor:pointer; padding:8px; border-radius:8px; transition:.2s; }
.ham:hover { background:#eef4fd; }
.ham span { display:block; height:2px; border-radius:2px; background:var(--text-main); transition:.3s; }
.ham.open span:nth-child(1){ transform:translateY(7px) rotate(45deg); }
.ham.open span:nth-child(2){ opacity:0; transform:scaleX(0); }
.ham.open span:nth-child(3){ transform:translateY(-7px) rotate(-45deg); }
.nb-right { display:flex; align-items:center; gap:10px; flex-shrink:0; }
.nb-user { font-size:13px; color:var(--text-muted); font-weight:600; white-space:nowrap; display:flex; align-items:center; gap:8px; }
.nb-role { font-size:11px; font-weight:700; color:#1565c0; background:#e3f2fd; padding:2px 8px; border-radius:6px; text-transform:capitalize; letter-spacing:.5px; white-space:nowrap; }
.blgout { padding:7px 14px; border-radius:8px; border:1.5px solid var(--blue-mid); color:var(--blue-mid); text-decoration:none; font-weight:700; font-size:13px; white-space:nowrap; transition:.2s; }
.blgout:hover { background:var(--blue-mid); color:#fff; }

/* SIDEBAR */
.sidebar { position:fixed; top:var(--nb-h); left:0; bottom:0; width:var(--sb-w); background:var(--white); border-right:1.5px solid var(--border); z-index:250; overflow-y:auto; overflow-x:hidden; transition:transform .28s cubic-bezier(.4,0,.2,1); box-shadow:2px 0 20px rgba(74,144,217,.06); padding-bottom:24px; }
.sidebar-overlay { display:none; position:fixed; inset:0; top:var(--nb-h); background:rgba(30,45,80,.4); z-index:240; backdrop-filter:blur(2px); }
.sidebar-overlay.open { display:block; }
.sb-section { padding:18px 14px 6px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); }
.sb-link { display:flex; align-items:center; gap:10px; padding:9px 16px; margin:1px 8px; border-radius:10px; text-decoration:none; color:var(--text-main); font-size:13.5px; font-weight:600; transition:.15s; position:relative; }
.sb-link:hover { background:#eef4fd; color:var(--blue-dark); }
.sb-link.active { background:linear-gradient(90deg,#e8f0fd,#f0f7ff); color:var(--blue-dark); font-weight:700; }
.sb-link.active::before { content:''; position:absolute; left:0; top:20%; bottom:20%; width:3px; border-radius:0 3px 3px 0; background:var(--blue-dark); margin-left:-8px; }
.sb-link svg { width:16px; height:16px; flex-shrink:0; opacity:.7; }
.sb-link.active svg { opacity:1; }
.sb-home { display:flex; align-items:center; gap:10px; padding:12px 16px; margin:8px 8px 2px; border-radius:10px; text-decoration:none; color:var(--text-main); font-size:13.5px; font-weight:700; transition:.15s; background:#f8faff; border:1px solid var(--border); }
.sb-home:hover { background:#eef4fd; color:var(--blue-dark); }
.sb-home svg { width:16px; height:16px; opacity:.7; }

/* LAYOUT */
.page-wrap { margin-left:var(--sb-w); margin-top:var(--nb-h); min-height:calc(100vh - var(--nb-h)); transition:margin-left .28s cubic-bezier(.4,0,.2,1); }
.main { max-width:1400px; margin:0 auto; padding:28px 24px 60px; }
.page-title { display:flex; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
.page-title h1 { font-family:'Poppins',sans-serif; font-weight:800; font-size:26px; color:var(--text-main); letter-spacing:-.5px; }
.badge-page { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; font-family:'Poppins',sans-serif; font-weight:700; font-size:10px; letter-spacing:2px; text-transform:uppercase; padding:5px 12px; border-radius:6px; }

/* TABS */
.tab-bar { display:flex; gap:4px; margin-bottom:22px; background:var(--white); border-radius:12px; padding:5px; border:1.5px solid var(--border); box-shadow:0 2px 10px rgba(74,144,217,.05); flex-wrap:wrap; }
.tab-btn { padding:10px 20px; border-radius:10px; border:none; background:transparent; font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; color:var(--text-muted); cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:8px; white-space:nowrap; }
.tab-btn:hover { background:#f0f5fd; color:var(--blue-dark); }
.tab-btn.active { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; box-shadow:0 4px 14px rgba(74,144,217,.3); }
.tab-btn .tab-count { background:rgba(255,255,255,.25); padding:2px 8px; border-radius:8px; font-size:11px; font-weight:800; }
.tab-btn.active .tab-count { background:rgba(255,255,255,.3); }
.tab-btn:not(.active) .tab-count { background:#eef4fd; color:var(--blue-dark); }

/* TOOLBAR */
.toolbar { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; flex-wrap:wrap; }
.toolbar-left { display:flex; align-items:center; gap:8px; flex-wrap:wrap; flex:1; }
.search-wrap { position:relative; flex:0 0 220px; max-width:220px; }
.search-input { width:100%; padding:9px 12px 9px 34px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; height:38px; }
.search-input:focus { border-color:var(--blue-mid); box-shadow:0 0 0 3px rgba(74,144,217,.12); }
.search-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:var(--text-muted); pointer-events:none; }
.filter-select { height:38px; padding:0 10px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13px; color:var(--text-main); outline:none; cursor:pointer; }
.btn-filter { height:38px; padding:0 16px; border-radius:10px; border:none; background:linear-gradient(90deg,var(--purple),#c0392b); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; flex-shrink:0; }
.btn-clear { padding:0 12px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); text-decoration:none; color:var(--text-muted); font-weight:700; font-size:13px; height:38px; display:inline-flex; align-items:center; }

/* TABLE */
.table-card { background:var(--white); border-radius:18px; border:1.5px solid var(--border); box-shadow:0 4px 20px rgba(74,144,217,.07); overflow:hidden; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:1100px; }
thead th { background:#f0f5fd; padding:13px 14px; text-align:left; font-size:11.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase; border-bottom:1.5px solid var(--border); white-space:nowrap; }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:#f5f8fe; }
tbody td { padding:12px 14px; font-size:13px; vertical-align:middle; }
.req-title { font-weight:700; color:var(--text-main); }
.obj-desc { font-size:11.5px; color:var(--text-muted); max-width:200px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }

/* Badges */
.badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:10.5px; font-weight:700; gap:4px; margin-right:4px; margin-bottom:4px; }
.badge::before { content:''; width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.badge-pending  { background:#fff3e0; color:#e65100; }
.badge-pending::before { background:#e65100; }
.badge-accepted { background:#e8f5e9; color:#2e7d32; }
.badge-accepted::before { background:#2e7d32; }
.badge-rejected { background:#fde8e8; color:#c0392b; }
.badge-rejected::before { background:#c0392b; }
.badge-tech { background:#fef3e7; color:#b7590d; }

/* Action Buttons */
.action-btns { display:flex; gap:6px; flex-wrap:nowrap; align-items:center; }
.btn-icon { width:32px; height:32px; border-radius:8px; border:1.5px solid var(--border); background:var(--white); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .15s,border-color .15s; flex-shrink:0; }
.btn-icon svg { width:14px; height:14px; }
.btn-icon.edit { color:var(--blue-mid); }
.btn-icon.edit:hover { background:#eef4fd; border-color:var(--blue-mid); }
.btn-icon.cancel { color:var(--red); }
.btn-icon.cancel:hover { background:#fde8e8; border-color:var(--red); }
.btn-icon.view { color:var(--green); }
.btn-icon.view:hover { background:#e8f5e9; border-color:var(--green); }

/* Pending Tag */
.btn-pending-tag { padding:4px 12px; border-radius:8px; border:1.5px solid #ffe0b2; background:#fff3e0; color:#e65100; font-family:'Nunito',sans-serif; font-weight:700; font-size:11px; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; cursor:default; }
.btn-pending-tag svg { width:12px; height:12px; }

/* Empty State */
.empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
.empty-state p { font-size:14px; font-weight:600; }

/* Active Filter Bar */
.active-filter-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; background:#eef4fd; border:1.5px solid #c5d8f7; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:600; color:var(--blue-dark); }
.filter-tag { background:#fff; border:1.5px solid #c5d8f7; border-radius:20px; padding:3px 10px; font-size:12px; font-weight:700; color:var(--text-main); }
.btn-clear-active { display:inline-flex; align-items:center; gap:5px; margin-left:4px; padding:5px 12px; border-radius:8px; background:var(--red); color:#fff; font-size:12px; font-weight:700; text-decoration:none; transition:.2s; }
.btn-clear-active:hover { opacity:.85; }

/* Pagination */
.table-footer { padding:13px 20px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.pagination { display:flex; gap:4px; }
.pg-btn { min-width:32px; height:32px; padding:0 8px; border-radius:8px; border:1.5px solid var(--border); background:var(--white); text-decoration:none; display:inline-flex; align-items:center; justify-content:center; color:var(--text-muted); font-weight:700; }
.pg-btn.active { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; border-color:transparent; }

/* REQUESTS SECTION */
.req-card { background:var(--white); border-radius:18px; border:1.5px solid var(--border); box-shadow:0 4px 20px rgba(74,144,217,.07); overflow:hidden; }
.req-card-header { padding:18px 20px; border-bottom:1.5px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.req-card-header h3 { font-family:'Poppins',sans-serif; font-weight:700; font-size:16px; }
.req-count { background:#fff3e0; color:#e65100; font-weight:800; font-size:11px; padding:3px 10px; border-radius:8px; }
.req-empty { text-align:center; padding:40px 20px; color:var(--text-muted); font-size:13px; font-weight:600; }
.req-admin-reply { background:#f0f5fd; border:1px solid #d6e4f7; border-radius:8px; padding:8px 12px; margin-top:6px; font-size:12px; color:var(--text-main); }
.req-admin-reply strong { color:var(--blue-dark); }
.btn-re-request { padding:6px 14px; border-radius:8px; border:none; background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:5px; transition:opacity .2s; box-shadow:0 3px 10px rgba(74,144,217,.2); }
.btn-re-request:hover { opacity:.9; }
.btn-cancel-req { padding:6px 14px; border-radius:8px; border:1.5px solid var(--border); background:var(--white); color:var(--red); font-family:'Nunito',sans-serif; font-weight:700; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:5px; transition:background .15s; }
.btn-cancel-req:hover { background:#fde8e8; border-color:var(--red); }

/* MODALS */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.45); backdrop-filter:blur(3px); z-index:500; align-items:flex-start; justify-content:center; padding:20px; overflow-y:auto; }
.modal-overlay.open { display:flex; }
.modal { background:var(--white); border-radius:20px; width:100%; max-width:650px; box-shadow:0 24px 64px rgba(30,45,80,.22); max-height:calc(100vh - 40px); display:flex; flex-direction:column; margin:auto; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1.5px solid var(--border); flex-shrink:0; }
.modal-header h2 { font-family:'Poppins',sans-serif; font-weight:800; font-size:19px; }
.modal-close { width:32px; height:32px; border-radius:50%; border:none; background:#f1f3f5; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-muted); font-size:20px; transition:.2s; flex-shrink:0; }
.modal-close:hover { background:#ffe3e3; color:var(--red); }
.modal form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }
.modal-body { padding:22px 24px; overflow-y:auto; flex:1; min-height:0; }
.modal-footer { padding:16px 24px; border-top:1.5px solid var(--border); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; }

/* Form */
.form-group { margin-bottom:18px; }
.form-group label { display:block; font-size:13px; font-weight:700; margin-bottom:8px; }
.form-group label span { color:var(--red); }
.form-control { width:100%; padding:12px 14px; border-radius:10px; border:1.5px solid var(--border); font-family:'Nunito',sans-serif; font-size:14px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; background:var(--white); }
.form-control:focus { border-color:var(--blue-mid); box-shadow:0 0 0 3px rgba(74,144,217,.12); }
.form-control::placeholder { color:#b0bdd6; }
.form-control:disabled, .form-control[readonly] { background:#f8faff; color:var(--text-muted); cursor:not-allowed; border-color:#e8edf5; opacity:.85; }
.form-control:disabled:focus, .form-control[readonly]:focus { border-color:#e8edf5; box-shadow:none; }
select.form-control:disabled { appearance:none; -webkit-appearance:none; -moz-appearance:none; background-image:none; padding-right:14px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }

.check-section { background:#f8faff; border:1.5px solid var(--border); border-radius:12px; padding:16px; margin-bottom:18px; }
.check-section-title { font-size:11px; font-weight:700; color:var(--text-muted); letter-spacing:.8px; text-transform:uppercase; margin-bottom:12px; }
.check-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
.check-item { display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; }
.check-item input[type="checkbox"] { width:16px; height:16px; accent-color:var(--blue-dark); border-radius:4px; cursor:pointer; }

.project-locked-box { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:10px; border:1.5px solid #c5d8f7; background:#f0f6ff; color:var(--text-main); font-size:14px; font-weight:700; }
.locked-badge { margin-left:auto; background:var(--blue-dark); color:#fff; font-size:10px; font-weight:700; padding:3px 8px; border-radius:20px; letter-spacing:.5px; text-transform:uppercase; }

.section-label { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:var(--blue-dark); margin:18px 0 10px; padding-bottom:6px; border-bottom:2px solid #e8f0fd; }
.section-label:first-child { margin-top:0; }
.section-label.locked { color:var(--text-muted); border-bottom-color:#dde4f0; }
.section-label.locked::after { content:'  (Read Only)'; font-size:10px; font-weight:600; color:var(--red); letter-spacing:0; }

.btn-send-request { padding:10px 26px; border-radius:10px; border:none; background:linear-gradient(90deg,#27ae60,#2ecc71); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; box-shadow:0 4px 14px rgba(39,174,96,.28); transition:opacity .2s; display:inline-flex; align-items:center; gap:8px; }
.btn-send-request:hover { opacity:.92; }
.btn-send-request svg { width:16px; height:16px; }
.btn-send-request:disabled { opacity:.5; cursor:not-allowed; }
.btn-cancel { padding:10px 24px; border-radius:10px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:background .15s,color .15s; }
.btn-cancel:hover { background:#f0f5fd; color:var(--text-main); }

.lock-icon { display:inline-flex; align-items:center; margin-left:6px; color:var(--orange); }
.lock-icon svg { width:13px; height:13px; }

/* Pending notice in modal */
.pending-notice { background:#fff3e0; border:1.5px solid #ffe0b2; border-radius:12px; padding:14px 16px; margin-bottom:16px; display:flex; align-items:center; gap:10px; }
.pending-notice svg { width:20px; height:20px; color:#e65100; flex-shrink:0; }
.pending-notice p { font-size:13px; font-weight:600; color:#e65100; }

/* Toast */
.toast { position:fixed; bottom:28px; right:28px; z-index:999; display:flex; align-items:flex-start; gap:10px; padding:14px 18px; border-radius:12px; font-size:13px; font-weight:700; box-shadow:0 8px 28px rgba(30,45,80,.18); max-width:380px; line-height:1.5; }
.toast.success { background:#e8f8f0; color:#1e8449; border:1.5px solid #a9dfbf; }
.toast.error   { background:#fde8e8; color:#c0392b; border:1.5px solid #f1948a; }

/* VIEW MODAL */
.view-section { margin-bottom:14px; }
.view-label { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:var(--text-muted); margin-bottom:4px; }
.view-value { font-size:14px; color:var(--text-main); font-weight:600; }

/* Responsive */
@media(max-width:768px){
  :root { --sb-w: 260px; }
  .ham { display:flex; }
  .sidebar { transform:translateX(-100%); }
  .sidebar.open { transform:translateX(0); }
  .sidebar-overlay.open { display:block; }
  .page-wrap { margin-left:0; }
  .nb-user span:not(.nb-role) { display:none; }
  .nb-role { display:none; }
  .main { padding:16px 12px 60px; }
  .page-title h1 { font-size:20px; }
  .form-row { grid-template-columns:1fr; }
  .check-grid { grid-template-columns:1fr 1fr; }
  .search-wrap { max-width:100%; flex:1; }
  .toolbar { flex-direction:column; align-items:stretch; }
  .toolbar-left { flex-wrap:wrap; }
  .toast { right:14px; bottom:14px; max-width:calc(100vw - 28px); font-size:12px; }
  .tab-btn { padding:8px 14px; font-size:12.5px; }
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

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <a href="../dash/user_dash.php" class="sb-home">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    Home
  </a>
  <div class="sb-section">Pages</div>
  <a href="../user_page/u_project.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
    My Projects
  </a>
  <a href="../user_page/u_requirement.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    Requirements
  </a>
  <a href="../user_page/u_test_plans.php" class="sb-link active">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    Test Plans
  </a>
  <a href="../user_page/u_project_assignment.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
    Assignment Requests
  </a>
</aside>

<!-- MAIN CONTENT -->
<div class="page-wrap" id="pageWrap">
  <div class="main">
    <div class="page-title">
      <h1>My Test Plans</h1>
      <span class="badge-page">View Only</span>
    </div>

    <!-- TAB BAR -->
    <div class="tab-bar">
      <button class="tab-btn active" onclick="switchTab('plans')">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Test Plans
        <span class="tab-count"><?= count($test_plans) ?></span>
      </button>
      <button class="tab-btn" onclick="switchTab('requests')">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        My Requests
        <span class="tab-count"><?= count($my_requests) ?></span>
      </button>
    </div>

    <!-- ═══════════ TAB 1: TEST PLANS ═══════════ -->
    <div id="tabPlans">
      <div class="toolbar">
        <div class="toolbar-left">
          <?php if (!$filters_applied): ?>
          <form method="GET" action="" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <div class="search-wrap">
              <svg class="search-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" name="search" class="search-input" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="filter_client" class="filter-select" id="filterClient" onchange="updateProjectDropdown(this.value)">
              <option value="">All Clients</option>
              <?php foreach ($clients_list as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filter_client == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="filter_project" class="filter-select" id="filterProject">
              <option value="">All Projects</option>
              <?php foreach ($user_projects as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $filter_project == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-filter">Apply Filter</button>
          </form>
          <?php else: ?>
          <div class="active-filter-bar">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
            <span>Filters active:</span>
            <?php
              if ($filter_client) {
                  foreach ($clients_list as $c) {
                      if ($c['id'] == $filter_client) echo '<span class="filter-tag">Client: ' . htmlspecialchars($c['name']) . '</span>';
                  }
              }
              if ($filter_project) {
                  foreach ($user_projects as $p) {
                      if ($p['id'] == $filter_project) echo '<span class="filter-tag">Project: ' . htmlspecialchars($p['name']) . '</span>';
                  }
              }
              if ($search) echo '<span class="filter-tag">Search: "' . htmlspecialchars($search) . '"</span>';
            ?>
            <a href="?" class="btn-clear-active">
              <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              Clear Filters
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="table-card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>S.No.</th>
                <th>Plan ID</th>
                <th>Title</th>
                <th>Objective</th>
                <th>Testing Types</th>
                <th>Technologies</th>
                <th>Test Lead</th>
                <th>Project Lead</th>
                <th>Request Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($test_plans)): ?>
              <tr><td colspan="10">
                <div class="empty-state">
                  <p>Koi Test Plan nahi mila. Aapke assigned projects mein koi test plan nahi hai.</p>
                </div>
              </td></tr>
              <?php else: ?>
                <?php $sn = ($page - 1) * 10 + 1; foreach ($test_plans as $tp): ?>
                <tr>
                  <td><?= $sn++ ?></td>
                  <td><strong>TP-00<?= $tp['id'] ?></strong></td>
                  <td><div class="req-title"><?= htmlspecialchars($tp['title']) ?></div></td>
                  <td><div class="obj-desc"><?= htmlspecialchars(substr($tp['objective'] ?? '', 0, 100)) ?><?= strlen($tp['objective'] ?? '') > 100 ? '...' : '' ?></div></td>
                  <td>
                    <?php foreach ($tp['testing_types'] as $t): ?>
                      <span class="badge"><?= htmlspecialchars($t) ?></span>
                    <?php endforeach; ?>
                  </td>
                  <td>
                    <?php foreach ($tp['technologies'] as $t): ?>
                      <span class="badge badge-tech"><?= htmlspecialchars($t) ?></span>
                    <?php endforeach; ?>
                  </td>
                  <td><?= htmlspecialchars($tp['test_lead_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($tp['project_lead_name'] ?? '—') ?></td>
                  <td>
                    <?php if ($tp['request_status'] === 'pending'): ?>
                      <span class="badge badge-pending">Pending</span>
                    <?php elseif ($tp['request_status'] === 'rejected'): ?>
                      <span class="badge badge-rejected">Rejected</span>
                    <?php elseif ($tp['request_status'] === 'accepted'): ?>
                      <span class="badge badge-accepted">Accepted</span>
                    <?php else: ?>
                      <span style="color:var(--text-muted); font-size:12px;">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="action-btns">
                      <button class="btn-icon view" title="View Details" onclick='openViewModal(<?= json_encode($tp) ?>)'>
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                      <?php if ($tp['request_status'] === 'pending'): ?>
                        <span class="btn-pending-tag" title="Pending request - cancel to re-edit">
                          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                          Pending
                        </span>
                        <button class="btn-icon cancel" title="Cancel Request" onclick="cancelRequest(<?= $tp['request_id'] ?>)">
                          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                      <?php elseif ($tp['request_status'] === 'rejected'): ?>
                        <button class="btn-icon edit" title="Re-request Edit" onclick="openEditModal(<?= $tp['id'] ?>)">
                          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                      <?php else: ?>
                        <button class="btn-icon edit" title="Request Edit" onclick="openEditModal(<?= $tp['id'] ?>)">
                          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($filters_applied && $total_pages > 1): ?>
        <div class="table-footer">
          <span style="font-size:13px; color:var(--text-muted);">Showing <?= count($test_plans) ?> of <?= $total ?></span>
          <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
              <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="pg-btn <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ═══════════ TAB 2: MY REQUESTS ═══════════ -->
    <div id="tabRequests" style="display:none;">
      <div class="req-card">
        <div class="req-card-header">
          <h3>My Edit Requests</h3>
          <span class="req-count"><?= count($my_requests) ?> total</span>
        </div>
        <?php if (empty($my_requests)): ?>
          <div class="req-empty">Koi request nahi hai. Test Plan edit karein to request yahan dikhegi.</div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table style="min-width:800px;">
              <thead>
                <tr>
                  <th>Test Plan</th>
                  <th>Project</th>
                  <th>Status</th>
                  <th>Requested On</th>
                  <th>Admin Reply</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($my_requests as $req): ?>
                <tr>
                  <td><strong>TP-00<?= $req['test_plan_id'] ?></strong> — <?= htmlspecialchars($req['plan_title'] ?? 'Deleted') ?></td>
                  <td><?= htmlspecialchars($req['project_name'] ?? '—') ?></td>
                  <td><?= req_status_badge($req['status']) ?></td>
                  <td style="font-size:12px; white-space:nowrap;"><?= fmt_date($req['created_at']) ?></td>
                  <td>
                    <?php if (!empty($req['admin_reply'])): ?>
                      <div class="req-admin-reply"><strong>Admin:</strong> <?= htmlspecialchars($req['admin_reply']) ?></div>
                    <?php else: ?>
                      <span style="color:var(--text-muted); font-size:12px;">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($req['status'] === 'pending'): ?>
                      <button class="btn-cancel-req" onclick="cancelRequest(<?= $req['id'] ?>)">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        Cancel
                      </button>
                    <?php elseif ($req['status'] === 'rejected'): ?>
                      <button class="btn-re-request" onclick="openEditModal(<?= $req['test_plan_id'] ?>)">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Re-request
                      </button>
                    <?php else: ?>
                      <span style="color:var(--green); font-size:12px; font-weight:700;">Approved</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- VIEW MODAL -->
<div class="modal-overlay" id="viewModal">
  <div class="modal" style="max-width:550px;">
    <div class="modal-header">
      <h2>Test Plan Details</h2>
      <button class="modal-close" onclick="closeViewModal()">&times;</button>
    </div>
    <div class="modal-body" id="viewModalBody">
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeViewModal()">Close</button>
    </div>
  </div>
</div>

<!-- EDIT / SEND REQUEST MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h2 id="editModalTitle">Request Edit</h2>
      <button class="modal-close" onclick="closeEditModal()">&times;</button>
    </div>
    <form method="POST" action="" id="editForm">
      <input type="hidden" name="action_form" value="send_edit_request"/>
      <input type="hidden" name="test_plan_id" id="editTpId"/>
      <input type="hidden" name="project_id" id="editProjectId"/>
      <div class="modal-body">

        <!-- Pending Notice -->
        <div id="pendingNotice" style="display:none;" class="pending-notice">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <p id="pendingNoticeText">Is Test Plan ke liye already ek pending request hai!</p>
        </div>

        <!-- LOCKED: Project -->
        <div class="section-label locked">Project Info</div>
        <div class="form-group">
          <label>Project <span class="lock-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span></label>
          <div id="editProjectLocked" class="project-locked-box" style="display:none;">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span id="editProjectName"></span>
            <span class="locked-badge">Locked</span>
          </div>
        </div>

        <!-- LOCKED: Title -->
        <div class="form-group">
          <label>Title <span class="lock-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span></label>
          <input type="text" name="title" id="editTitle" class="form-control" readonly/>
        </div>

        <!-- LOCKED: Leads -->
        <div class="form-row">
          <div class="form-group">
            <label>Project Lead <span class="lock-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span></label>
            <select name="project_lead_id" id="editPLead" class="form-control" disabled>
              <option value="">Select Lead</option>
              <?php foreach ($users_list as $u): ?>
                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Test Lead <span class="lock-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span></label>
            <select name="test_lead_id" id="editTLead" class="form-control" disabled>
              <option value="">Select Lead</option>
              <?php foreach ($users_list as $u): ?>
                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- EDITABLE FIELDS -->
        <div class="section-label">Editable Fields</div>

        <div class="form-group">
          <label>Objective</label>
          <textarea name="objective" id="editObj" class="form-control" style="height:80px; resize:vertical;" placeholder="What is the objective?"></textarea>
        </div>

        <div class="form-group">
          <label>Scope</label>
          <textarea name="scope" id="editScope" class="form-control" style="height:80px; resize:vertical;" placeholder="Define the scope"></textarea>
        </div>

        <div class="form-group">
          <label>Roles Covered</label>
          <input type="text" name="roles_covered" id="editRoles" class="form-control" placeholder="e.g. Admin, User, Guest"/>
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
          <div style="margin-top:15px; border-top:1px solid #eee; padding-top:10px;">
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
        <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
        <button type="submit" id="btnSendRequest" class="btn-send-request">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Send Request
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($msg): ?>
<div class="toast <?= $msg_type ?>">
  <?= htmlspecialchars($msg) ?>
</div>
<script>setTimeout(() => { const t = document.querySelector('.toast'); if (t) t.remove(); }, 4000);</script>
<?php endif; ?>

<script>
// ── Sidebar Logic ──
function toggleSidebar(){
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('sbOverlay');
    const hm = document.getElementById('hamBtn');
    const isOpen = sb.classList.toggle('open');
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
window.addEventListener('resize', () => { if(window.innerWidth > 768) closeSidebar(); });

// ── Tab Switching ──
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach((b, i) => {
        b.classList.toggle('active', (tab === 'plans' && i === 0) || (tab === 'requests' && i === 1));
    });
    document.getElementById('tabPlans').style.display    = tab === 'plans' ? 'block' : 'none';
    document.getElementById('tabRequests').style.display = tab === 'requests' ? 'block' : 'none';
}

// ── Filter Dropdown ──
const userProjects = <?= json_encode($user_projects) ?>;
const filterProj   = document.getElementById('filterProject');
const filterCli    = document.getElementById('filterClient');

function updateProjectDropdown(clientId) {
    if (!filterProj) return;
    filterProj.innerHTML = '<option value="">All Projects</option>';
    const filtered = clientId
        ? userProjects.filter(p => p.client_id == clientId)
        : userProjects;
    filtered.forEach(p => {
        filterProj.innerHTML += `<option value="${p.id}">${p.name}</option>`;
    });
}

// ── View Modal ──
function openViewModal(data) {
    const body = document.getElementById('viewModalBody');
    let html = `
        <div class="view-section"><div class="view-label">Plan ID</div><div class="view-value">TP-00${data.id}</div></div>
        <div class="view-section"><div class="view-label">Project</div><div class="view-value">${data.project_name || '—'}</div></div>
        <div class="view-section"><div class="view-label">Title</div><div class="view-value">${data.title || '—'}</div></div>
        <div class="view-section"><div class="view-label">Objective</div><div class="view-value">${data.objective || '—'}</div></div>
        <div class="view-section"><div class="view-label">Scope</div><div class="view-value">${data.scope || '—'}</div></div>
        <div class="view-section"><div class="view-label">Testing Types</div><div class="view-value">${(data.testing_types || []).map(t => '<span class="badge">' + t + '</span>').join(' ') || '—'}</div></div>
        <div class="view-section"><div class="view-label">Technologies</div><div class="view-value">${(data.technologies || []).map(t => '<span class="badge badge-tech">' + t + '</span>').join(' ') || '—'}</div></div>
        <div class="view-section"><div class="view-label">Project Lead</div><div class="view-value">${data.project_lead_name || '—'}</div></div>
        <div class="view-section"><div class="view-label">Test Lead</div><div class="view-value">${data.test_lead_name || '—'}</div></div>
        <div class="view-section"><div class="view-label">Roles Covered</div><div class="view-value">${data.roles_covered || '—'}</div></div>
        <div class="view-section"><div class="view-label">Created At</div><div class="view-value">${data.created_at ? new Date(data.created_at).toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }) : '—'}</div></div>
    `;
    body.innerHTML = html;
    document.getElementById('viewModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeViewModal() {
    document.getElementById('viewModal').classList.remove('open');
    document.body.style.overflow = 'auto';
}

// ── Edit Modal (via AJAX to get fresh data) ──
function openEditModal(tpId) {
    // Fetch fresh data via AJAX
    fetch('?get_test_plan_data=' + tpId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + (data.error || 'Failed to load data'));
                return;
            }

            document.getElementById('editTpId').value      = data.id;
            document.getElementById('editProjectId').value  = data.project_id;
            document.getElementById('editTitle').value      = data.title || '';
            document.getElementById('editObj').value        = data.objective || '';
            document.getElementById('editScope').value      = data.scope || '';
            document.getElementById('editRoles').value      = data.roles_covered || '';
            document.getElementById('editPLead').value      = data.project_lead_id || '';
            document.getElementById('editTLead').value      = data.test_lead_id || '';

            // Project locked
            document.getElementById('editProjectLocked').style.display = 'flex';
            document.getElementById('editProjectName').textContent = data.project_name || ('Project #' + data.project_id);

            // Testing types checkboxes
            const tTypes = data.testing_types || [];
            document.querySelectorAll('input[name="testing_types[]"]').forEach(el => {
                el.checked = tTypes.includes(el.value);
            });

            // Technologies checkboxes
            document.querySelectorAll('input[name="technologies[]"]').forEach(el => el.checked = false);
            document.getElementById('customTechList').innerHTML = '';
            const techs = data.technologies || [];
            techs.forEach(tech => {
                const staticCheck = document.querySelector(`input[name="technologies[]"][value="${tech}"]`);
                if (staticCheck) {
                    staticCheck.checked = true;
                } else {
                    addCustomTechElement(tech);
                }
            });

            // Pending check
            if (data.has_pending) {
                document.getElementById('pendingNotice').style.display = 'flex';
                document.getElementById('pendingNoticeText').textContent = 'Is Test Plan ke liye already ek pending request hai! Cancel karein ya wait karein.';
                document.getElementById('btnSendRequest').disabled = true;
            } else {
                document.getElementById('pendingNotice').style.display = 'none';
                document.getElementById('btnSendRequest').disabled = false;
            }

            document.getElementById('editModal').classList.add('open');
            document.body.style.overflow = 'hidden';
            const mb = document.querySelector('#editModal .modal-body');
            if (mb) mb.scrollTop = 0;
        })
        .catch(err => {
            alert('Error loading test plan data: ' + err);
        });
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
    document.body.style.overflow = 'auto';
}

// ── Custom Tech Elements ──
function addCustomTechElement(val) {
    if (!val) return;
    const existing = document.querySelectorAll('#customTechList input[type="hidden"]');
    for (const inp of existing) {
        if (inp.value === val) return;
    }
    const list = document.getElementById('customTechList');
    const div = document.createElement('div');
    div.style.cssText = 'background:#eef4fd; padding:4px 8px; border-radius:5px; font-size:12px; display:inline-flex; align-items:center; gap:4px;';
    div.innerHTML = `${val}<span style="color:red;cursor:pointer;" onclick="this.parentElement.remove()">×</span><input type="hidden" name="technologies[]" value="${val}">`;
    list.appendChild(div);
}

document.getElementById('techSelect').addEventListener('change', function () {
    if (this.value) {
        addCustomTechElement(this.value);
        this.value = '';
    }
});

// ── Cancel Request ──
function cancelRequest(reqId) {
    if (confirm('Kya aap is request ko cancel karna chahte hain?')) {
        window.location.href = '?cancel_request=' + reqId;
    }
}
</script>
</body>
</html>
