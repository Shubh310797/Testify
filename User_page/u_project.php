<?php
// ── user_page/u_project.php ─────────────────────────────────────────────
// USER VERSION: Team edit request admin ko jayega
// Admin accept kare → team update hoga | Reject → user ko notify + re-request option
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

// ════════════════════════════════════════════════════════
//  AUTO-CREATE team_edit_requests TABLE
// ════════════════════════════════════════════════════════
 $conn->query("CREATE TABLE IF NOT EXISTS team_edit_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    requested_by INT NOT NULL,
    project_lead_id INT DEFAULT NULL,
    qa_lead_id INT DEFAULT NULL,
    fe_devs TEXT COMMENT 'JSON array of user IDs',
    be_devs TEXT COMMENT 'JSON array of user IDs',
    qa_team TEXT COMMENT 'JSON array of user IDs',
    status ENUM('pending','accepted','rejected') DEFAULT 'pending',
    admin_reply TEXT,
    reviewed_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL
)");

if (isset($_GET['requested'])) {
    $msg      = 'Team edit request admin ko bhej di gayi! Admin accept kare to update hoga.';
    $msg_type = 'success';
}
if (isset($_GET['cancelled'])) {
    $msg      = 'Request cancel ho gayi.';
    $msg_type = 'success';
}

// ════════════════════════════════════════════════════════
//  SUBMIT TEAM EDIT REQUEST (NOT direct update!)
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_form'] ?? '') === 'edit_team') {
    $pid        = (int)($_POST['edit_id']       ?? 0);
    $fe_devs    = $_POST['fe_devs']             ?? [];
    $be_devs    = $_POST['be_devs']             ?? [];
    $qa_team    = $_POST['qa_team']             ?? [];
    $lead_id    = (int)($_POST['project_lead']  ?? 0) ?: null;
    $qa_lead_id = (int)($_POST['qa_lead']       ?? 0) ?: null;

    if (!$pid) {
        $msg = 'Project ID missing.'; $msg_type = 'error';
    } elseif (!$current_user_id) {
        $msg = 'User ID nahi mili. Login karein.'; $msg_type = 'error';
    } else {
        // Check: already pending request for this project?
        $chk = $conn->prepare("SELECT id FROM team_edit_requests WHERE project_id = ? AND requested_by = ? AND status = 'pending'");
        $chk->bind_param('ii', $pid, $current_user_id);
        $chk->execute();
        $chk->store_result();
        $dup_count = $chk->num_rows;
        $chk->close();

        if ($dup_count > 0) {
            $msg = 'Is project ke liye already ek pending request hai! Admin ka wait karein ya purani request cancel karein.';
            $msg_type = 'error';
        } else {
            // Store team data as JSON — admin accept kare to actual update hoga
            $fe_json = json_encode(array_map('intval', $fe_devs));
            $be_json = json_encode(array_map('intval', $be_devs));
            $qa_json = json_encode(array_map('intval', $qa_team));

            $stmt = $conn->prepare(
                "INSERT INTO team_edit_requests (project_id, requested_by, project_lead_id, qa_lead_id, fe_devs, be_devs, qa_team, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->bind_param('iiiissss', $pid, $current_user_id, $lead_id, $qa_lead_id, $fe_json, $be_json, $qa_json);

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
    $del = $conn->prepare("DELETE FROM team_edit_requests WHERE id = ? AND requested_by = ? AND status = 'pending'");
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
//  FETCH LOOKUP DATA (dropdowns ke liye)
// ════════════════════════════════════════════════════════
 $developers_list = [];
 $dev_result = $conn->query("SELECT id, name, username FROM users WHERE role = 'developer' ORDER BY name");
if ($dev_result) { while ($r = $dev_result->fetch_assoc()) $developers_list[] = $r; }

 $testers_list = [];
 $qa_result = $conn->query("SELECT id, name, username FROM users WHERE role = 'tester' OR role = 'qa' ORDER BY name");
if ($qa_result) { while ($r = $qa_result->fetch_assoc()) $testers_list[] = $r; }

 $leads_list = [];
 $lead_result = $conn->query("SELECT id, name, username FROM users WHERE role = 'developer' OR role = 'lead' ORDER BY name");
if ($lead_result) { while ($r = $lead_result->fetch_assoc()) $leads_list[] = $r; }

 $technologies_list = [];
 $tech_result = $conn->query("SELECT id, name FROM technologies ORDER BY name");
if ($tech_result) { while ($r = $tech_result->fetch_assoc()) $technologies_list[] = $r; }

// ════════════════════════════════════════════════════════
//  AJAX: get_project_data (for edit modal)
// ════════════════════════════════════════════════════════
if (isset($_GET['get_project_data'])) {
    $pid = (int)$_GET['get_project_data'];

    $stmt = $conn->prepare("SELECT p.*, c.name AS client_name FROM projects p LEFT JOIN clients c ON c.id = p.client_id WHERE p.id = ?");
    $stmt->bind_param('i', $pid); $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$project) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Project not found']);
        exit;
    }

    // Check if user has pending request for this project
    $has_pending = false;
    $pending_req_id = null;
    $chk = $conn->prepare("SELECT id FROM team_edit_requests WHERE project_id = ? AND requested_by = ? AND status = 'pending' LIMIT 1");
    $chk->bind_param('ii', $pid, $current_user_id);
    $chk->execute();
    $chkRes = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($chkRes) {
        $has_pending = true;
        $pending_req_id = (int)$chkRes['id'];
    }

    $out = [
        'success'         => true,
        'id'              => (int)$project['id'],
        'name'            => $project['name'],
        'client_id'       => (int)$project['client_id'],
        'client_name'     => $project['client_name'] ?? '',
        'status'          => $project['status'],
        'action'          => $project['action'],
        'project_lead_id' => $project['project_lead_id'] ? (int)$project['project_lead_id'] : '',
        'qa_lead_id'      => $project['qa_lead_id'] ? (int)$project['qa_lead_id'] : '',
        'start_date'      => $project['start_date'],
        'deadline_date'   => $project['deadline_date'],
        'delivery_date'   => $project['delivery_date'],
        'has_pending'     => $has_pending,
        'pending_req_id'  => $pending_req_id,
        'fe_devs'         => [],
        'be_devs'         => [],
        'qa_team'         => [],
        'fe_tech'         => [],
        'be_tech'         => [],
        'other_tech'      => [],
        'fe_tech_names'   => [],
        'be_tech_names'   => [],
        'other_tech_names'=> [],
    ];

    $r1 = $conn->query("SELECT user_id FROM project_frontend_devs WHERE project_id = $pid");
    while ($r = $r1->fetch_assoc()) $out['fe_devs'][] = (int)$r['user_id'];

    $r2 = $conn->query("SELECT user_id FROM project_backend_devs WHERE project_id = $pid");
    while ($r = $r2->fetch_assoc()) $out['be_devs'][] = (int)$r['user_id'];

    $r3 = $conn->query("SELECT user_id FROM project_qa_team WHERE project_id = $pid");
    while ($r = $r3->fetch_assoc()) $out['qa_team'][] = (int)$r['user_id'];

    $r4 = $conn->query("SELECT t.id AS tech_id, t.name AS tech_name, pt.tech_role FROM project_technologies pt JOIN technologies t ON t.id = pt.tech_id WHERE pt.project_id = $pid");
    while ($r = $r4->fetch_assoc()) {
        $tid  = (int)$r['tech_id'];
        $tname = $r['tech_name'];
        if ($r['tech_role'] === 'frontend')      { $out['fe_tech'][]    = $tid; $out['fe_tech_names'][]    = $tname; }
        elseif ($r['tech_role'] === 'backend')    { $out['be_tech'][]    = $tid; $out['be_tech_names'][]    = $tname; }
        else                                      { $out['other_tech'][] = $tid; $out['other_tech_names'][] = $tname; }
    }

    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

// ════════════════════════════════════════════════════════
//  FETCH PROJECTS (only projects where user is assigned)
// ════════════════════════════════════════════════════════
 $search = trim($_GET['search'] ?? '');
 $projects = [];
 $per_page = 10;

if ($current_user_id) {
    if ($search) {
        $like = "%$search%";
        $total_query = $conn->prepare(
            "SELECT COUNT(DISTINCT p.id) FROM projects p
             LEFT JOIN clients c ON c.id = p.client_id
             LEFT JOIN project_frontend_devs pfd ON pfd.project_id = p.id AND pfd.user_id = ?
             LEFT JOIN project_backend_devs pbd ON pbd.project_id = p.id AND pbd.user_id = ?
             LEFT JOIN project_qa_team pqt ON pqt.project_id = p.id AND pqt.user_id = ?
             WHERE (pfd.user_id = ? OR pbd.user_id = ? OR pqt.user_id = ? OR p.project_lead_id = ? OR p.qa_lead_id = ?)
             AND (p.name LIKE ? OR c.name LIKE ?)"
        );
        $total_query->bind_param('iiiiiiiss', $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $like, $like);
        $total_query->execute();
        $total_query->bind_result($total_count);
        $total_query->fetch();
        $total_query->close();
    } else {
        $total_query = $conn->prepare(
            "SELECT COUNT(DISTINCT p.id) FROM projects p
             LEFT JOIN project_frontend_devs pfd ON pfd.project_id = p.id AND pfd.user_id = ?
             LEFT JOIN project_backend_devs pbd ON pbd.project_id = p.id AND pbd.user_id = ?
             LEFT JOIN project_qa_team pqt ON pqt.project_id = p.id AND pqt.user_id = ?
             WHERE pfd.user_id = ? OR pbd.user_id = ? OR pqt.user_id = ? OR p.project_lead_id = ? OR p.qa_lead_id = ?"
        );
        $total_query->bind_param('iiiiiiii', $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id);
        $total_query->execute();
        $total_query->bind_result($total_count);
        $total_query->fetch();
        $total_query->close();
    }
} else {
    $total_count = 0;
}

 $total_pages = max(1, ceil($total_count / $per_page));
 $page        = max(1, min((int)($_GET['page'] ?? 1), $total_pages));
 $offset      = ($page - 1) * $per_page;

if ($current_user_id && $total_count > 0) {
    if ($search) {
        $like = "%$search%";
        $stmt = $conn->prepare(
            "SELECT DISTINCT p.*, c.name AS client_name,
                    ul.name AS lead_name, uq.name AS qa_lead_name
             FROM projects p
             LEFT JOIN clients c ON c.id = p.client_id
             LEFT JOIN users ul  ON ul.id = p.project_lead_id
             LEFT JOIN users uq  ON uq.id = p.qa_lead_id
             LEFT JOIN project_frontend_devs pfd ON pfd.project_id = p.id AND pfd.user_id = ?
             LEFT JOIN project_backend_devs pbd ON pbd.project_id = p.id AND pbd.user_id = ?
             LEFT JOIN project_qa_team pqt ON pqt.project_id = p.id AND pqt.user_id = ?
             WHERE (pfd.user_id = ? OR pbd.user_id = ? OR pqt.user_id = ? OR p.project_lead_id = ? OR p.qa_lead_id = ?)
             AND (p.name LIKE ? OR c.name LIKE ?)
             ORDER BY p.createdAt DESC LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iiiiiiiissii', $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $like, $like, $per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            "SELECT DISTINCT p.*, c.name AS client_name,
                    ul.name AS lead_name, uq.name AS qa_lead_name
             FROM projects p
             LEFT JOIN clients c ON c.id = p.client_id
             LEFT JOIN users ul  ON ul.id = p.project_lead_id
             LEFT JOIN users uq  ON uq.id = p.qa_lead_id
             LEFT JOIN project_frontend_devs pfd ON pfd.project_id = p.id AND pfd.user_id = ?
             LEFT JOIN project_backend_devs pbd ON pbd.project_id = p.id AND pbd.user_id = ?
             LEFT JOIN project_qa_team pqt ON pqt.project_id = p.id AND pqt.user_id = ?
             WHERE pfd.user_id = ? OR pbd.user_id = ? OR pqt.user_id = ? OR p.project_lead_id = ? OR p.qa_lead_id = ?
             ORDER BY p.createdAt DESC LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iiiiiiiiii', $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    }

    while ($row = $result->fetch_assoc()) {
        $pid = (int)$row['id'];

        $row['fe_devs_names'] = [];
        $feRes = $conn->query("SELECT u.name FROM project_frontend_devs pfd JOIN users u ON u.id = pfd.user_id WHERE pfd.project_id = $pid");
        while ($fe = $feRes->fetch_assoc()) $row['fe_devs_names'][] = $fe['name'];

        $row['be_devs_names'] = [];
        $beRes = $conn->query("SELECT u.name FROM project_backend_devs pbd JOIN users u ON u.id = pbd.user_id WHERE pbd.project_id = $pid");
        while ($be = $beRes->fetch_assoc()) $row['be_devs_names'][] = $be['name'];

        $row['qa_names'] = [];
        $qaRes = $conn->query("SELECT u.name FROM project_qa_team pqt JOIN users u ON u.id = pqt.user_id WHERE pqt.project_id = $pid");
        while ($qa = $qaRes->fetch_assoc()) $row['qa_names'][] = $qa['name'];

        $row['tech_fe'] = [];
        $row['tech_be'] = [];
        $row['tech_other'] = [];
        $techRes = $conn->query("SELECT t.name, pt.tech_role FROM project_technologies pt JOIN technologies t ON t.id = pt.tech_id WHERE pt.project_id = $pid");
        while ($t = $techRes->fetch_assoc()) {
            if ($t['tech_role'] === 'frontend')      $row['tech_fe'][]    = $t['name'];
            elseif ($t['tech_role'] === 'backend')    $row['tech_be'][]    = $t['name'];
            else                                      $row['tech_other'][] = $t['name'];
        }

 // Detect current user's role in this project
        $roleCheck = $conn->query("
            SELECT 
              (SELECT COUNT(*) FROM project_frontend_devs WHERE project_id=$pid AND user_id=$current_user_id) as is_fe,
              (SELECT COUNT(*) FROM project_backend_devs  WHERE project_id=$pid AND user_id=$current_user_id) as is_be,
              (SELECT COUNT(*) FROM project_qa_team       WHERE project_id=$pid AND user_id=$current_user_id) as is_qa
        ");
        $rc = $roleCheck->fetch_assoc();
        $my_roles = [];
        if ((int)$row['project_lead_id'] === $current_user_id) $my_roles[] = 'lead';
        if ((int)($row['qa_lead_id'] ?? 0) === $current_user_id) $my_roles[] = 'qa-lead';
        if ((int)($rc['is_fe'] ?? 0) > 0) $my_roles[] = 'fe';
        if ((int)($rc['is_be'] ?? 0) > 0) $my_roles[] = 'be';
        if ((int)($rc['is_qa'] ?? 0) > 0) $my_roles[] = 'qa';
        $row['my_roles'] = $my_roles;

        $projects[] = $row;
    }
}

 $conn->close();

// ── Helpers ──
function status_class(string $s): string {
    return match($s) {
        'In Progress' => 'badge-progress',
        'Completed'   => 'badge-done',
        default       => 'badge-notstarted',
    };
}
function fmt_date($date): string {
    if (empty($date) || $date === null) return '---';
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d ? $d->format('d/m/Y') : '---';
}
function req_status_badge(string $s): string {
    return match($s) {
        'pending'  => '<span class="badge badge-pending">Pending</span>',
        'accepted' => '<span class="badge badge-accepted">Accepted</span>',
        'rejected' => '<span class="badge badge-rejected">Rejected</span>',
        default    => htmlspecialchars($s),
    };
}
function my_role_badges(array $roles): string {
    if (empty($roles)) return '<span style="color:#b0bdd6;font-size:12px;">---</span>';
    $map = [
        'lead'    => ['label'=>'Project Lead','class'=>'role-lead'],
        'qa-lead' => ['label'=>'QA Lead',     'class'=>'role-qa-lead'],
        'fe'      => ['label'=>'FE Dev',       'class'=>'role-fe'],
        'be'      => ['label'=>'BE Dev',       'class'=>'role-be'],
        'qa'      => ['label'=>'QA',           'class'=>'role-qa'],
    ];
    $out = '';
    foreach ($roles as $r) {
        if (isset($map[$r])) {
            $out .= '<span class="my-role-badge '.$map[$r]['class'].'">'.$map[$r]['label'].'</span> ';
        }
    }
    return trim($out) ?: '---';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TestiFy — My Projects</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<style>
/* ═══════════════════════════════════════════════════════
   BASE CSS VARIABLES & RESET (MATCHING REPORTS)
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

/* ═══════════════════════════════════════════════════════
   NAVBAR (Exact Match to Reports Page)
   ═══════════════════════════════════════════════════════ */
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

/* ═══════════════════════════════════════════════════════
   SIDEBAR (Exact Match to Reports Page)
   ═══════════════════════════════════════════════════════ */
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

/* ═══════════════════════════════════════════════════════
   MAIN LAYOUT & TYPOGRAPHY
   ═══════════════════════════════════════════════════════ */
.page-wrap {
  margin-left:var(--sb-w);
  margin-top:var(--nb-h);
  min-height:calc(100vh - var(--nb-h));
  transition:margin-left .28s cubic-bezier(.4,0,.2,1);
}
.main { max-width:1400px; margin:0 auto; padding:28px 24px 60px; }
.page-title { display:flex; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
.page-title h1 { font-family:'Poppins',sans-serif; font-weight:800; font-size:26px; color:var(--text-main); letter-spacing:-.5px; }
.badge-page { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; font-family:'Poppins',sans-serif; font-weight:700; font-size:10px; letter-spacing:2px; text-transform:uppercase; padding:5px 12px; border-radius:6px; }

/* ═══════════════════════════════════════════════════════
   COMPONENTS (Toolbar, Table, Badges)
   ═══════════════════════════════════════════════════════ */
.toolbar { display:flex; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
.toolbar-left { display:flex; flex-direction:row; align-items:center; gap:12px; flex:1; }

.search-wrap { position:relative; flex:1; max-width:320px; width:100%; }
.search-wrap svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:var(--text-muted); pointer-events:none; }
.search-input { width:100%; padding:9px 12px 9px 34px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; height:38px; }
.search-input:focus { border-color:var(--blue-mid); box-shadow:0 0 0 3px rgba(74,144,217,.12); }

.btn-clear { display:inline-flex; align-items:center; gap:4px; padding:0 12px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13px; font-weight:700; color:var(--text-muted); text-decoration:none; height:38px; flex-shrink:0; transition:background .15s; }
.btn-clear:hover { background:#f0f5fd; }

.table-card { background:var(--white); border-radius:18px; border:1.5px solid var(--border); box-shadow:0 4px 20px rgba(74,144,217,.07); overflow:hidden; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:760px; }
thead th { background:#f0f5fd; padding:13px 14px; text-align:left; font-size:11.5px; font-weight:700; color:var(--text-muted); letter-spacing:.8px; text-transform:uppercase; border-bottom:1.5px solid var(--border); white-space:nowrap; }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:#f5f8fe; }
tbody td { padding:12px 14px; font-size:13.5px; vertical-align:middle; }
.client-name { font-weight:700; color:var(--text-main); }

/* Badges */
.badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:700; gap:4px; }
.badge::before { content:''; width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.badge-notstarted { background:#f0f0f0; color:#666; }
.badge-notstarted::before { background:#666; }
.badge-progress   { background:#fff3e0; color:#e65100; }
.badge-progress::before { background:#e65100; }
.badge-done       { background:#e8f5e9; color:#2e7d32; }
.badge-done::before { background:#2e7d32; }
.badge-pending  { background:#fff3e0; color:#e65100; }
.badge-pending::before { background:#e65100; }
.badge-accepted { background:#e8f5e9; color:#2e7d32; }
.badge-accepted::before { background:#2e7d32; }
.badge-rejected { background:#fde8e8; color:#c0392b; }
.badge-rejected::before { background:#c0392b; }
/* MY ROLE BADGE */
.my-role-badge { 
  display:inline-flex; align-items:center; gap:5px;
  padding:4px 10px; border-radius:20px; font-size:11.5px; font-weight:800;
  letter-spacing:.3px; white-space:nowrap;
}
.role-fe    { background:#e3f2fd; color:#1565c0; border:1.5px solid #90caf9; }
.role-be    { background:#fff3e0; color:#e65100; border:1.5px solid #ffcc80; }
.role-qa    { background:#e8f5e9; color:#2e7d32; border:1.5px solid #a5d6a7; }
.role-lead  { background:#f3e5f5; color:#6a1b9a; border:1.5px solid #ce93d8; }
.role-qa-lead { background:#fce4ec; color:#880e4f; border:1.5px solid #f48fb1; }

/* SUMMARY BANNER */
.summary-banner {
  background: linear-gradient(135deg, #e8f0fe 0%, #f0f7ff 100%);
  border: 1.5px solid #c5d8f7;
  border-radius: 14px;
  padding: 16px 20px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}
.summary-banner svg { width:28px; height:28px; color:var(--blue-dark); flex-shrink:0; }
.summary-text { flex:1; }
.summary-text h3 { font-size:15px; font-weight:800; color:var(--text-main); margin-bottom:3px; }
.summary-text p  { font-size:13px; color:var(--text-muted); font-weight:600; }
.summary-stats   { display:flex; gap:16px; flex-wrap:wrap; }
.stat-pill {
  background:var(--white); border:1.5px solid var(--border);
  border-radius:10px; padding:8px 14px; text-align:center;
  box-shadow:0 2px 8px rgba(74,144,217,.08);
}
.stat-pill .stat-num { font-family:'Poppins',sans-serif; font-weight:800; font-size:18px; color:var(--blue-dark); line-height:1; }
.stat-pill .stat-lbl { font-size:10px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; margin-top:2px; }

/* Status Chips */
.vis-active { background:#e8f5e9; color:#2e7d32; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.vis-inactive { background:#fde8e8; color:#c0392b; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }

.chip-list { display:flex; flex-wrap:wrap; gap:4px; }
.chip { display:inline-flex; align-items:center; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700; white-space:nowrap; }
.chip-blue   { background:#e3f2fd; color:#1565c0; }
.chip-orange { background:#fff3e0; color:#e65100; }
.chip-green  { background:#e8f5e9; color:#2e7d32; }
.chip-gray   { background:#f5f5f5; color:#616161; }

/* Empty State */
.empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
.empty-state svg { width:48px; height:48px; margin-bottom:12px; color:#c5d5e8; }
.empty-state p { font-size:14px; font-weight:600; }
.empty-state .sub { font-size:12px; margin-top:4px; }

/* Pagination */
.table-footer { padding:13px 20px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.footer-count { font-size:12.5px; color:var(--text-muted); font-weight:600; }
.pagination { display:flex; align-items:center; gap:4px; }
.pg-btn { min-width:32px; height:32px; padding:0 8px; border-radius:8px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13px; font-weight:700; color:var(--text-muted); cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; transition:background .15s,color .15s,border-color .15s; }
.pg-btn:hover { background:#eef4fd; color:var(--blue-dark); border-color:var(--blue-mid); }
.pg-btn.active { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; border-color:transparent; }
.pg-btn.disabled { opacity:.4; pointer-events:none; }

/* ═══════════════════════════════════════════════════════
   MODAL & FORMS
   ═══════════════════════════════════════════════════════ */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.45); backdrop-filter:blur(3px); z-index:500; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.open { display:flex; }
.modal { background:var(--white); border-radius:20px; width:100%; max-width:560px; box-shadow:0 24px 64px rgba(30,45,80,.22); overflow:hidden; max-height:85vh; display:flex; flex-direction:column; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:22px 24px 18px; border-bottom:1.5px solid var(--border); flex-shrink:0; }
.modal-header h2 { font-family:'Poppins',sans-serif; font-weight:800; font-size:18px; }
.modal-close { width:32px; height:32px; border-radius:8px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-muted); transition:background .15s,color .15s; }
.modal-close:hover { background:#fde8e8; color:var(--red); }
.modal-close svg { width:16px; height:16px; }
.modal form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }
.modal-body { padding:22px 24px; overflow-y:auto; flex:1; min-height:0; }
.modal-footer { padding:16px 24px 20px; border-top:1.5px solid var(--border); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; }

.section-label { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:var(--blue-dark); margin:18px 0 10px; padding-bottom:6px; border-bottom:2px solid #e8f0fd; }
.section-label:first-child { margin-top:0; }
.section-label.locked { color:var(--text-muted); border-bottom-color:#dde4f0; }
.section-label.locked::after { content:'  (Read Only)'; font-size:10px; font-weight:600; color:var(--red); letter-spacing:0; }

.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:700; margin-bottom:6px; }
.form-group label span { color:var(--red); }
.form-control { width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; background:var(--white); }
.form-control:focus { border-color:var(--blue-mid); box-shadow:0 0 0 3px rgba(74,144,217,.12); }
.form-control::placeholder { color:#b0bdd6; }
.form-control:disabled, .form-control[readonly] { background:#f8faff; color:var(--text-muted); cursor:not-allowed; border-color:#e8edf5; opacity:.85; }
.form-control:disabled:focus, .form-control[readonly]:focus { border-color:#e8edf5; box-shadow:none; }
select.form-control:disabled { appearance:none; -webkit-appearance:none; -moz-appearance:none; background-image:none; padding-right:14px; }

.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }

.btn-send-request { padding:10px 26px; border-radius:10px; border:none; background:linear-gradient(90deg,#27ae60,#2ecc71); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; box-shadow:0 4px 14px rgba(39,174,96,.28); transition:opacity .2s; display:inline-flex; align-items:center; gap:8px; }
.btn-send-request:hover { opacity:.92; }
.btn-send-request svg { width:16px; height:16px; }
.btn-send-request:disabled { opacity:.5; cursor:not-allowed; }
.btn-cancel { padding:10px 22px; border-radius:10px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:background .15s,color .15s; }
.btn-cancel:hover { background:#f0f5fd; color:var(--text-main); }

.lock-icon { display:inline-flex; align-items:center; margin-left:6px; color:var(--orange); }
.lock-icon svg { width:13px; height:13px; }

/* Pending notice in modal */
.pending-notice { background:#fff3e0; border:1.5px solid #ffe0b2; border-radius:12px; padding:14px 16px; margin-bottom:16px; display:flex; align-items:center; gap:10px; }
.pending-notice svg { width:20px; height:20px; color:#e65100; flex-shrink:0; }
.pending-notice p { font-size:13px; font-weight:600; color:#e65100; }

/* TOAST */
.toast { position:fixed; bottom:28px; right:28px; z-index:999; display:flex; align-items:flex-start; gap:10px; padding:14px 18px; border-radius:12px; font-size:13px; font-weight:700; box-shadow:0 8px 28px rgba(30,45,80,.18); max-width:380px; line-height:1.5; }
.toast.success { background:#e8f8f0; color:#1e8449; border:1.5px solid #a9dfbf; }
.toast.error   { background:#fde8e8; color:#c0392b; border:1.5px solid #f1948a; }
.toast svg { width:17px; height:17px; flex-shrink:0; margin-top:1px; }
.toast-hide { opacity:0; transition:opacity .3s; }

@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

/* RESPONSIVE */
@media(max-width:768px){
  :root { --sb-w: 260px; }
  .ham { display:flex; }
  .sidebar { transform:translateX(-100%); }
  .sidebar.open { transform:translateX(0); }
  .sidebar-overlay.open { display:block; }
  .page-wrap { margin-left:0; }
  .nb-user span:not(.nb-role) { display:none; }
  .nb-role { display:none; }
  .main { padding:16px 14px 48px; }
  .page-title h1 { font-size:20px; }
  .form-row, .form-row-3 { grid-template-columns:1fr; }
  .search-wrap { max-width:100%; }
  .toast { right:14px; bottom:14px; max-width:calc(100vw - 28px); font-size:12px; }
  .toolbar-left { flex-direction:column; align-items:stretch; width:100%; }
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

<!-- SIDEBAR (Reports UI applied) -->
<aside class="sidebar" id="sidebar">
  <a href="../dash/user_dash.php" class="sb-home">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    Home
  </a>
  <div class="sb-section">Pages</div>
  <a href="../user_page/u_project.php" class="sb-link active">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    My Projects
  </a>
  <a href="../user_page/u_requirement.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
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

<!-- MAIN WRAP -->
<div class="page-wrap" id="pageWrap">
  <div class="main">
    <div class="page-title">
      <h1>My Projects</h1>
      <span class="badge-page">My Projects</span>
    </div>

    <!-- ═══ TAB 1: PROJECTS ═══ -->
    <div id="tabProjects">

      <!-- SUMMARY BANNER -->
      <?php
      $total_as_lead = 0; $total_as_dev = 0; $total_as_qa = 0;
      foreach ($projects as $p) {
          $r = $p['my_roles'] ?? [];
          if (in_array('lead', $r) || in_array('qa-lead', $r)) $total_as_lead++;
          if (in_array('fe', $r) || in_array('be', $r)) $total_as_dev++;
          if (in_array('qa', $r)) $total_as_qa++;
      }
      ?>
      <div class="summary-banner">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
              <circle cx="9" cy="7" r="4"/>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          </svg>
          <div class="summary-text">
              <h3>Aap <?= $total_count ?> Project<?= $total_count !== 1 ? 's' : '' ?> mein Assign Hain</h3>
              <p>Neeche aapke saare assigned projects aur unme aapka role dekh sakte hain</p>
          </div>
          <div class="summary-stats">
              <div class="stat-pill">
                  <div class="stat-num"><?= $total_count ?></div>
                  <div class="stat-lbl">Total</div>
              </div>
              <?php if ($total_as_lead): ?>
              <div class="stat-pill">
                  <div class="stat-num" style="color:#6a1b9a;"><?= $total_as_lead ?></div>
                  <div class="stat-lbl">As Lead</div>
              </div>
              <?php endif; ?>
              <?php if ($total_as_dev): ?>
              <div class="stat-pill">
                  <div class="stat-num" style="color:#1565c0;"><?= $total_as_dev ?></div>
                  <div class="stat-lbl">As Dev</div>
              </div>
              <?php endif; ?>
              <?php if ($total_as_qa): ?>
              <div class="stat-pill">
                  <div class="stat-num" style="color:#2e7d32;"><?= $total_as_qa ?></div>
                  <div class="stat-lbl">As QA</div>
              </div>
              <?php endif; ?>
          </div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left">
          <form method="GET" action="" style="display:contents;">
            <div class="search-wrap">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" name="search" id="searchInput" class="search-input"
                     placeholder="Search my projects..."
                     value="<?= htmlspecialchars($search) ?>"
                     oninput="autoSearch(this)" autocomplete="off"/>
            </div>
          </form>
          <?php if ($search): ?>
            <a href="?" class="btn-clear">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              Clear
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="table-card">
        <div class="table-wrap">
          <table id="projectTable">
            <thead>
              <tr>
                <th>S.No.</th>
                <th style="background:#e8f0fe;color:#1565c0;">My Role</th>
                <th>Project Name</th>
                <th>Client</th>
                <th>Status</th>
                <th>Visibility</th>
                <th>FE Devs</th>
                <th>BE Devs</th>
                <th>Project Lead</th>
                <th>QA Lead</th>
                <th>QA Team</th>
                <th>FE Tech</th>
                <th>BE Tech</th>
                <th>Other Tech</th>
                <th>Start</th>
                <th>Deadline</th>
                <th>Delivery</th>
                <!-- ACTION COLUMN REMOVED -->
              </tr>
            </thead>
            <tbody id="projectTbody">
              <?php if (empty($projects)): ?>
              <tr>
                <td colspan="16">
                  <div class="empty-state">
                    <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    <p>Koi Project Assigned Nahi Hai</p>
                    <p class="sub">Jab admin aapko project assign karenge, yahan dikhenge</p>
                  </div>
                </td>
              </tr>
              <?php else: ?>
                <?php $start_num = ($page - 1) * $per_page + 1; foreach ($projects as $i => $p): ?>
                <tr id="row-<?= $p['id'] ?>">
                  <td><?= $start_num + $i ?></td>
                  <td><?= my_role_badges($p['my_roles'] ?? []) ?></td>
                  <td><div class="client-name"><?= htmlspecialchars($p['name']) ?></div></td>
                  <td><?= htmlspecialchars($p['client_name'] ?? '---') ?></td>
                  <td>
                    <span class="badge badge-<?= status_class($p['status']) ?>">
                      <?= htmlspecialchars($p['status']) ?>
                    </span>
                  </td>
                  <td>
                    <span class="<?= ($p['action'] ?? 'active') === 'active' ? 'vis-active' : 'vis-inactive' ?>">
                      <?= ucfirst($p['action'] ?? 'active') ?>
                    </span>
                  </td>
                  <td><div class="chip-list"><?php if(!empty($p['fe_devs_names'])): foreach ($p['fe_devs_names'] as $d): ?><span class="chip chip-blue"><?= htmlspecialchars($d) ?></span><?php endforeach; else: echo '---'; endif; ?></div></td>
                  <td><div class="chip-list"><?php if(!empty($p['be_devs_names'])): foreach ($p['be_devs_names'] as $d): ?><span class="chip chip-orange"><?= htmlspecialchars($d) ?></span><?php endforeach; else: echo '---'; endif; ?></div></td>
                  <td><?= htmlspecialchars($p['lead_name'] ?? '---') ?></td>
                  <td><?= htmlspecialchars($p['qa_lead_name'] ?? '---') ?></td>
                  <td><div class="chip-list"><?php if(!empty($p['qa_names'])): foreach ($p['qa_names'] as $d): ?><span class="chip chip-green"><?= htmlspecialchars($d) ?></span><?php endforeach; else: echo '---'; endif; ?></div></td>
                  <td><div class="chip-list"><?php if(!empty($p['tech_fe'])): foreach ($p['tech_fe'] as $t): ?><span class="chip chip-blue"><?= htmlspecialchars($t) ?></span><?php endforeach; else: echo '---'; endif; ?></div></td>
                  <td><div class="chip-list"><?php if(!empty($p['tech_be'])): foreach ($p['tech_be'] as $t): ?><span class="chip chip-orange"><?= htmlspecialchars($t) ?></span><?php endforeach; else: echo '---'; endif; ?></div></td>
                  <td><div class="chip-list"><?php if(!empty($p['tech_other'])): foreach ($p['tech_other'] as $t): ?><span class="chip chip-gray"><?= htmlspecialchars($t) ?></span><?php endforeach; else: echo '---'; endif; ?></div></td>
                  <td style="font-size:12px;white-space:nowrap;"><?= fmt_date($p['start_date']) ?></td>
                  <td style="font-size:12px;white-space:nowrap;"><?= fmt_date($p['deadline_date']) ?></td>
                  <td style="font-size:12px;white-space:nowrap;"><?= fmt_date($p['delivery_date']) ?></td>
                  <!-- ACTION TD REMOVED -->
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="table-footer">
          <span class="footer-count">
            Total <strong><?= $total_count ?></strong> project<?= $total_count !== 1 ? 's' : '' ?>
            <?php if ($total_pages > 1): ?>
              &nbsp;&middot;&nbsp; Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong>
            <?php endif; ?>
          </span>
          <?php if ($total_pages > 1): ?>
          <div class="pagination">
            <a href="?page=<?= max(1, $page - 1) ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
            <?php for ($pg = 1; $pg <= $total_pages; $pg++): ?>
              <?php if ($total_pages > 7 && abs($pg - $page) > 2 && $pg !== 1 && $pg !== $total_pages): ?>
                <?php if ($pg === 2 || $pg === $total_pages - 1): ?><span style="padding:0 4px;color:var(--text-muted);">&hellip;</span><?php endif; ?>
              <?php else: ?>
                <a href="?page=<?= $pg ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="pg-btn <?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <a href="?page=<?= min($total_pages, $page + 1) ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
   MODAL (Kept for logical consistency though Action Column is removed)
   ═══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Request Team Edit</h2>
      <button class="modal-close" onclick="closeModal('editModal')" type="button">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" id="editForm">
      <input type="hidden" name="action_form" value="edit_team"/>
      <input type="hidden" name="edit_id" id="eId"/>
      <input type="hidden" name="ajax" value="1"/>
      <div class="modal-body">
        <!-- Pending notice (shown if already pending) -->
        <div class="pending-notice" id="pendingNotice" style="display:none">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <p id="pendingNoticeText">Is project ke liye already ek pending request hai!</p>
        </div>

        <!-- BASIC INFO — LOCKED -->
        <div class="section-label locked">Basic Info <span class="lock-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span></div>
        <div class="form-row">
          <div class="form-group"><label>Project Name</label><input type="text" id="eName" class="form-control" readonly tabindex="-1"/></div>
          <div class="form-group"><label>Client</label><input type="text" id="eClientName" class="form-control" readonly tabindex="-1"/></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Status</label><input type="text" id="eStatusText" class="form-control" readonly tabindex="-1"/></div>
          <div class="form-group"><label>Visibility</label><input type="text" id="eActionText" class="form-control" readonly tabindex="-1"/></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Start Date</label><input type="text" id="eStartText" class="form-control" readonly tabindex="-1"/></div>
          <div class="form-group"><label>Deadline Date</label><input type="text" id="eDeadlineText" class="form-control" readonly tabindex="-1"/></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Delivery Date</label><input type="text" id="eDeliveryText" class="form-control" readonly tabindex="-1"/></div>
          <div class="form-group"></div>
        </div>

        <!-- TEAM MEMBERS — EDITABLE -->
        <div class="section-label">Team Members (Editable)</div>
        <div class="form-row">
          <div class="form-group"><label>Frontend Developers</label>
            <select name="fe_devs[]" id="eFeDevs" class="form-control" multiple size="4">
              <?php foreach ($developers_list as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['username']) ?>)</option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Backend Developers</label>
            <select name="be_devs[]" id="eBeDevs" class="form-control" multiple size="4">
              <?php foreach ($developers_list as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['username']) ?>)</option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Project Lead</label>
            <select name="project_lead" id="eLead" class="form-control">
              <option value="">Select Project Lead</option>
              <?php foreach ($leads_list as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>QA Lead</label>
            <select name="qa_lead" id="eQaLead" class="form-control">
              <option value="">Select QA Lead</option>
              <?php foreach ($testers_list as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group"><label>QA Team</label>
          <select name="qa_team[]" id="eQaTeam" class="form-control" multiple size="4">
            <?php foreach ($testers_list as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['username']) ?>)</option><?php endforeach; ?>
          </select>
        </div>

        <!-- TECHNOLOGIES — LOCKED -->
        <div class="section-label locked">Technologies <span class="lock-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span></div>
        <div class="form-row-3">
          <div class="form-group"><label>Frontend Tech</label><select id="eFeTech" class="form-control" multiple size="4" disabled tabindex="-1"></select></div>
          <div class="form-group"><label>Backend Tech</label><select id="eBeTech" class="form-control" multiple size="4" disabled tabindex="-1"></select></div>
          <div class="form-group"><label>Other Tech</label><select id="eOtherTech" class="form-control" multiple size="4" disabled tabindex="-1"></select></div>
        </div>
        <p style="font-size:11.5px;color:var(--text-muted);margin-top:4px;">Ctrl/Cmd hold karke multiple select karein</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn-send-request" id="btnSendRequest">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Send Request to Admin
        </button>
      </div>
    </form>
  </div>
</div>

<!-- TOAST -->
<?php if ($msg): ?>
<div class="toast <?= $msg_type ?>" id="toast">
  <?php if ($msg_type === 'success'): ?>
    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  <?php else: ?>
    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <?php endif; ?>
  <?= htmlspecialchars($msg) ?>
</div>
<script>setTimeout(function(){var t=document.getElementById('toast');if(t){t.classList.add('toast-hide');setTimeout(function(){t.remove();},300);}},4000);</script>
<?php endif; ?>

<script>
/* ── Sidebar ── */
function toggleSidebar(){var sb=document.getElementById('sidebar'),ov=document.getElementById('sbOverlay'),hm=document.getElementById('hamBtn'),o=sb.classList.toggle('open');ov.classList.toggle('open',o);hm.classList.toggle('open',o);document.body.style.overflow=(o&&window.innerWidth<=768)?'hidden':'';}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sbOverlay').classList.remove('open');document.getElementById('hamBtn').classList.remove('open');document.body.style.overflow='';}
window.addEventListener('resize',function(){if(window.innerWidth>768)closeSidebar();});

/* ── Tab Switch ── */
function switchTab(tab){
  // Simplified as there is only one tab now, but function kept for JS consistency
  if(tab === 'projects'){
     document.getElementById('tabProjects').style.display='';
  }
}

/* ── Toast ── */
function showToast(msg,type){var old=document.getElementById('liveToast');if(old)old.remove();var t=document.createElement('div');t.className='toast '+type;t.id='liveToast';var icon=type==='success'?'<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>':'<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';t.innerHTML=icon+msg;document.body.appendChild(t);setTimeout(function(){t.classList.add('toast-hide');setTimeout(function(){t.remove();},300);},4000);}

/* ── Auto Search ── */
var _st=null;function autoSearch(inp){clearTimeout(_st);_st=setTimeout(function(){var val=inp.value.trim();window.location.href=val?'?search='+encodeURIComponent(val):'?';},800);}

/* ── Format Date ── */
function fmtDate(ds){if(!ds)return'---';var d=new Date(ds);if(isNaN(d.getTime()))return ds;return String(d.getDate()).padStart(2,'0')+'/'+String(d.getMonth()+1).padStart(2,'0')+'/'+d.getFullYear();}

/* ── Cancel Request ── */
function cancelRequest(reqId){if(!confirm('Kya aap is request ko cancel karna chahte hain?'))return;window.location.href='?cancel_request='+reqId;}

/* ── Edit Modal ── */
function openEditModal(projectId){
  fetch('?get_project_data='+projectId)
    .then(function(r){return r.json();})
    .then(function(data){
      if(!data.success){showToast('Project data load nahi hua: '+(data.error||'Error'),'error');return;}

      var noticeEl=document.getElementById('pendingNotice');
      var btnSend=document.getElementById('btnSendRequest');
      if(data.has_pending){
        noticeEl.style.display='flex';
        document.getElementById('pendingNoticeText').textContent='Is project ke liye already ek pending request hai! Cancel karein ya wait karein.';
        btnSend.disabled=true;
      }else{
        noticeEl.style.display='none';
        btnSend.disabled=false;
      }

      document.getElementById('eId').value=data.id||'';
      document.getElementById('eName').value=data.name||'';
      document.getElementById('eClientName').value=data.client_name||'';
      document.getElementById('eStatusText').value=data.status||'Not Started';
      document.getElementById('eActionText').value=(data.action==='active')?'Active':'Inactive';
      document.getElementById('eStartText').value=fmtDate(data.start_date);
      document.getElementById('eDeadlineText').value=fmtDate(data.deadline_date);
      document.getElementById('eDeliveryText').value=fmtDate(data.delivery_date);
      document.getElementById('eLead').value=data.project_lead_id||'';
      document.getElementById('eQaLead').value=data.qa_lead_id||'';
      setSelectedOptions('eFeDevs',data.fe_devs||[]);
      setSelectedOptions('eBeDevs',data.be_devs||[]);
      setSelectedOptions('eQaTeam',data.qa_team||[]);
      fillTechSelectWithNames('eFeTech',data.fe_tech_names||[]);
      fillTechSelectWithNames('eBeTech',data.be_tech_names||[]);
      fillTechSelectWithNames('eOtherTech',data.other_tech_names||[]);

      document.getElementById('editModal').classList.add('open');
      document.body.style.overflow='hidden';
    })
    .catch(function(err){console.error('Edit fetch error:',err);showToast('Data load nahi hua.','error');});
}

function setSelectedOptions(sid,vals){var sel=document.getElementById(sid);if(!sel)return;var va=vals.map(function(v){return String(v);});for(var i=0;i<sel.options.length;i++){sel.options[i].selected=va.indexOf(sel.options[i].value)!==-1;}}

function fillTechSelectWithNames(sid,names){var sel=document.getElementById(sid);if(!sel)return;sel.innerHTML='';if(names.length===0){var o=document.createElement('option');o.textContent='---';sel.appendChild(o);}else{for(var i=0;i<names.length;i++){var o=document.createElement('option');o.textContent=names[i];o.selected=true;sel.appendChild(o);}}}

function closeModal(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';}
document.getElementById('editModal').addEventListener('click',function(e){if(e.target===this)closeModal('editModal');});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal('editModal');});

/* ── AJAX Form Submit ── */
document.getElementById('editForm').addEventListener('submit',function(e){
  e.preventDefault();
  var btnSend=document.getElementById('btnSendRequest');
  if(btnSend.disabled)return;
  var formData=new FormData(this);
  btnSend.disabled=true;
  btnSend.innerHTML='<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Sending...';
  fetch('',{method:'POST',body:formData})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.success){showToast(d.message||'Request admin ko bhej di gayi!','success');closeModal('editModal');setTimeout(function(){window.location.reload();},1500);}
      else{showToast(d.error||'Request bhejne mein error!','error');btnSend.disabled=false;btnSend.innerHTML='<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Request to Admin';}
    })
    .catch(function(err){console.error('Submit error:',err);showToast('Request bhejne mein error!','error');btnSend.disabled=false;btnSend.innerHTML='<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Request to Admin';});
});
</script>
</body>
</html>