<?php
// ── user_page/u_project.php ─────────────────────────────────────────────
// USER VERSION: Developer directly add/update projects in database
// QA = View only, Developer = Full Add/Edit access
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
//  ROLE CHECK: Only developer can add/update
// ════════════════════════════════════════════════════════
 $is_developer = in_array(strtolower($current_user_role), ['developer', 'lead']);
 $is_qa        = in_array(strtolower($current_user_role), ['tester', 'qa']);

if (isset($_GET['saved'])) {
    $msg      = 'Project successfully saved!';
    $msg_type = 'success';
}
if (isset($_GET['updated'])) {
    $msg      = 'Project successfully updated!';
    $msg_type = 'success';
}

// ════════════════════════════════════════════════════════
//  SAVE PROJECT (Direct DB — Developer Only, With Client-side Validation)
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_form'] ?? '') === 'save_project') {
    if (!$is_developer) {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Access Denied! QA does not have add/edit permission.']);
            exit;
        }
        $msg = 'Access Denied! QA does not have add/edit permission.'; $msg_type = 'error';
    } else {
    $pid         = (int)($_POST['edit_id']       ?? 0);
    $project_name= trim($_POST['project_name']   ?? '');
    $client_id   = (int)($_POST['client_id']     ?? 0) ?: null;
    $req_status  = trim($_POST['status']         ?? 'Not Started');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;
    $action_val  = $is_active ? 'active' : 'inactive';
    $lead_id     = (int)($_POST['project_lead']  ?? 0) ?: null;
    $qa_lead_id  = (int)($_POST['qa_lead']       ?? 0) ?: null;
    $start_date   = $_POST['start_date']        ?? null;
    $deadline_date= $_POST['deadline_date']     ?? null;
    $delivery_date= (!empty($_POST['delivery_date'])) ? $_POST['delivery_date'] : null;

    $fe_devs     = $_POST['fe_devs']             ?? [];
    $be_devs     = $_POST['be_devs']             ?? [];
    $qa_team     = $_POST['qa_team']             ?? [];
    $fe_tech     = $_POST['fe_tech']             ?? [];
    $be_tech     = $_POST['be_tech']             ?? [];
    $other_tech  = $_POST['other_tech']          ?? [];

    // ═══ ADD MODE (pid = 0): INSERT new project ═══
    if (!$pid) {
        $stmt = $conn->prepare(
            "INSERT INTO projects (name, client_id, status, action, project_lead_id, qa_lead_id, start_date, deadline_date, delivery_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('sisssiiss', $project_name, $client_id, $req_status, $action_val, $lead_id, $qa_lead_id, $start_date, $deadline_date, $delivery_date);

        if ($stmt->execute()) {
            $new_pid = $conn->insert_id;
            $stmt->close();

            // Insert junction tables
            foreach ($fe_devs as $uid) {
                $uid = (int)$uid;
                $conn->query("INSERT IGNORE INTO project_frontend_devs (project_id, user_id) VALUES ($new_pid, $uid)");
            }
            foreach ($be_devs as $uid) {
                $uid = (int)$uid;
                $conn->query("INSERT IGNORE INTO project_backend_devs (project_id, user_id) VALUES ($new_pid, $uid)");
            }
            foreach ($qa_team as $uid) {
                $uid = (int)$uid;
                $conn->query("INSERT IGNORE INTO project_qa_team (project_id, user_id) VALUES ($new_pid, $uid)");
            }
            foreach ($fe_tech as $tid) {
                $tid = (int)$tid;
                $conn->query("INSERT IGNORE INTO project_technologies (project_id, tech_id, tech_role) VALUES ($new_pid, $tid, 'frontend')");
            }
            foreach ($be_tech as $tid) {
                $tid = (int)$tid;
                $conn->query("INSERT IGNORE INTO project_technologies (project_id, tech_id, tech_role) VALUES ($new_pid, $tid, 'backend')");
            }
            foreach ($other_tech as $tid) {
                $tid = (int)$tid;
                $conn->query("INSERT IGNORE INTO project_technologies (project_id, tech_id, tech_role) VALUES ($new_pid, $tid, 'other')");
            }

            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Project successfully added!']);
                exit;
            }
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?saved=1');
            exit;
        } else {
            $msg = 'Error: ' . htmlspecialchars($stmt->error); $msg_type = 'error';
            $stmt->close();
        }

    // ═══ EDIT MODE (pid > 0): UPDATE existing project ═══
    } else {
        $stmt = $conn->prepare(
            "UPDATE projects SET name=?, client_id=?, status=?, action=?, project_lead_id=?, qa_lead_id=?, start_date=?, deadline_date=?, delivery_date=? WHERE id=?"
        );
        $stmt->bind_param('sisssiissi', $project_name, $client_id, $req_status, $action_val, $lead_id, $qa_lead_id, $start_date, $deadline_date, $delivery_date, $pid);

        if ($stmt->execute()) {
            $stmt->close();

            // Delete old junction entries and insert new ones
            $conn->query("DELETE FROM project_frontend_devs WHERE project_id = $pid");
            $conn->query("DELETE FROM project_backend_devs WHERE project_id = $pid");
            $conn->query("DELETE FROM project_qa_team WHERE project_id = $pid");
            $conn->query("DELETE FROM project_technologies WHERE project_id = $pid");

            foreach ($fe_devs as $uid) {
                $uid = (int)$uid;
                $conn->query("INSERT IGNORE INTO project_frontend_devs (project_id, user_id) VALUES ($pid, $uid)");
            }
            foreach ($be_devs as $uid) {
                $uid = (int)$uid;
                $conn->query("INSERT IGNORE INTO project_backend_devs (project_id, user_id) VALUES ($pid, $uid)");
            }
            foreach ($qa_team as $uid) {
                $uid = (int)$uid;
                $conn->query("INSERT IGNORE INTO project_qa_team (project_id, user_id) VALUES ($pid, $uid)");
            }
            foreach ($fe_tech as $tid) {
                $tid = (int)$tid;
                $conn->query("INSERT IGNORE INTO project_technologies (project_id, tech_id, tech_role) VALUES ($pid, $tid, 'frontend')");
            }
            foreach ($be_tech as $tid) {
                $tid = (int)$tid;
                $conn->query("INSERT IGNORE INTO project_technologies (project_id, tech_id, tech_role) VALUES ($pid, $tid, 'backend')");
            }
            foreach ($other_tech as $tid) {
                $tid = (int)$tid;
                $conn->query("INSERT IGNORE INTO project_technologies (project_id, tech_id, tech_role) VALUES ($pid, $tid, 'other')");
            }

            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Project successfully updated!']);
                exit;
            }
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?updated=1');
            exit;
        } else {
            $msg = 'Error: ' . htmlspecialchars($stmt->error); $msg_type = 'error';
            $stmt->close();
        }
    }

    if (isset($_POST['ajax']) && $msg_type === 'error') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }
    } // end developer-only block
}

// ════════════════════════════════════════════════════════
//  FETCH LOOKUP DATA (for dropdowns)
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

 $clients_list = [];
 $client_result = $conn->query("SELECT id, name FROM clients ORDER BY name");
if ($client_result) { while ($r = $client_result->fetch_assoc()) $clients_list[] = $r; }

// ════════════════════════════════════════════════════════
//  AJAX: get_project_data (for edit modal)
// ════════════════════════════════════════════════════════
if (isset($_GET['get_project_data'])) {
    if (!$is_developer) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Access Denied! QA does not have edit permission.']);
        exit;
    }
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
        'fe_devs'         => [],
        'be_devs'         => [],
        'qa_team'         => [],
        'fe_tech'         => [],
        'be_tech'         => [],
        'other_tech'      => [],
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
        if ($r['tech_role'] === 'frontend')      { $out['fe_tech'][]    = $tid; }
        elseif ($r['tech_role'] === 'backend')    { $out['be_tech'][]    = $tid; }
        else                                      { $out['other_tech'][] = $tid; }
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
<link rel="icon" type="image/jpg" href="../icon/testify.jpg" />

<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<style>
/* ═══════════════════════════════════════════════════════════════
   COLORFUL THEME — Matches Admin Project Page Style
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

.ham { display:none; flex-direction:column; justify-content:center; gap:5px; width:40px; height:40px; border:none; background:transparent; cursor:pointer; padding:8px; border-radius:8px; transition:.2s; }
.ham:hover { background:#eef4fd; }
.ham span { display:block; height:2px; border-radius:2px; background:var(--text-main); transition:.3s; }
.ham.open span:nth-child(1){ transform:translateY(7px) rotate(45deg); }
.ham.open span:nth-child(2){ opacity:0; transform:scaleX(0); }
.ham.open span:nth-child(3){ transform:translateY(-7px) rotate(-45deg); }

.nb-right { display:flex; align-items:center; gap:10px; flex-shrink:0; }
.nb-user { font-size:20px; color:var(--text-muted); font-weight:600; white-space:nowrap; display:flex; align-items:center; gap:8px; }
.nb-role { font-size:15px; font-weight:700; background:linear-gradient(135deg,#e3f2fd,#f3e8ff); color:#7c3aed; padding:2px 10px; border-radius:8px; text-transform:capitalize; letter-spacing:.5px; white-space:nowrap; border:1px solid #e9d5ff; }
.blgout { padding:7px 16px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--red),#c0392b); color:#fff; text-decoration:none; font-weight:700; font-size:13px; white-space:nowrap; transition:.2s; box-shadow:0 2px 10px rgba(231,76,60,.2); }
.blgout:hover { box-shadow:0 4px 18px rgba(231,76,60,.35); transform:translateY(-1px); }

/* ══ SIDEBAR ══ */
.sidebar { position:fixed; top:var(--nb-h); left:0; bottom:0; width:var(--sb-w); background:linear-gradient(180deg,#ffffff,#f8faff); border-right:1.5px solid var(--border); z-index:250; overflow-y:auto; overflow-x:hidden; transition:transform .28s cubic-bezier(.4,0,.2,1); box-shadow:2px 0 20px rgba(74,144,217,.06); padding-bottom:24px; }
.sidebar-overlay { display:none; position:fixed; inset:0; top:var(--nb-h); background:rgba(30,45,80,.4); z-index:240; backdrop-filter:blur(2px); }
.sidebar-overlay.open { display:block; }

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
.toolbar-left { display:flex; align-items:center; gap:12px; flex:1; flex-wrap:wrap; }

.search-wrap { position:relative; flex:1; min-width:200px; max-width:340px; }
.search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--text-muted); pointer-events:none; }
.search-input { width:100%; padding:9px 14px 9px 36px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; }
.search-input:focus { border-color:var(--purple-mid); box-shadow:0 0 0 3px rgba(155,89,182,.12); }

.btn-clear { display:inline-flex; align-items:center; gap:4px; padding:0 12px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13px; font-weight:700; color:var(--text-muted); text-decoration:none; height:38px; flex-shrink:0; transition:background .15s; }
.btn-clear:hover { background:#f0f5fd; }

/* ══ ADD PROJECT BUTTON ══ */
.btn-add { padding:9px 20px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--green),var(--green-mid)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; display:flex; align-items:center; gap:6px; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(39,174,96,.3); white-space:nowrap; margin-left:auto; flex-shrink:0; }
.btn-add:hover { opacity:.92; transform:translateY(-2px); box-shadow:0 8px 22px rgba(39,174,96,.38); }
.btn-add svg { width:15px; height:15px; }

/* ══ ACTION BUTTONS ══ */
.action-btns { display:flex; gap:6px; flex-wrap:nowrap; }
.btn-icon { width:34px; height:34px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; flex-shrink:0; text-decoration:none; }
.btn-icon svg { width:14px; height:14px; }
.btn-icon.edit { color:var(--blue-mid); }
.btn-icon.edit:hover { background:linear-gradient(135deg,#eef4fd,#d4e4f7); border-color:var(--blue-mid); transform:translateY(-2px); box-shadow:0 3px 10px rgba(58,123,213,.2); }

/* ══ QA ACCESS DENIED BANNER ══ */
.qa-notice {
  background:linear-gradient(135deg,#fff3e0,#ffe0b2);
  border:1.5px solid #ffe0b2;
  border-radius:12px;
  padding:14px 18px;
  margin-bottom:18px;
  display:flex;
  align-items:center;
  gap:12px;
  font-size:13px;
  font-weight:700;
  color:#e65100;
}
.qa-notice svg { width:22px; height:22px; flex-shrink:0; }

/* ══ TABLE CARD ══ */
.table-card { background:var(--white); border-radius:18px; border:1.5px solid var(--border); box-shadow:0 4px 20px rgba(74,144,217,.07); overflow:hidden; position:relative; }
.table-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid),var(--orange)); border-radius:18px 18px 0 0; z-index:1; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:760px; }
thead th { background:linear-gradient(90deg,#f0f5fd,#eef4fd); padding:13px 14px; text-align:left; font-size:12px; font-weight:700; color:var(--text-muted); letter-spacing:.8px; text-transform:uppercase; border-bottom:1.5px solid var(--border); white-space:nowrap; }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:linear-gradient(90deg,#f5f8fe,#f8faff); box-shadow:inset 3px 0 0 var(--blue-mid); }
tbody td { padding:13px 14px; font-size:13.5px; vertical-align:middle; }
.client-name { font-weight:700; color:var(--text-main); }

/* Badges */
.badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:700; letter-spacing:.3px; }
.badge-notstarted { background:linear-gradient(135deg,#f0f0f0,#e8e8e8); color:#666; border:1px solid #ddd; }
.badge-progress   { background:linear-gradient(135deg,#fff3e0,#ffe0b2); color:#e65100; border:1px solid #ffe0b2; }
.badge-done       { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); color:#1e8449; border:1px solid #a9dfbf; }

/* MY ROLE BADGE */
.my-role-badge {
  display:inline-flex; align-items:center; gap:5px;
  padding:4px 10px; border-radius:20px; font-size:11.5px; font-weight:800;
  letter-spacing:.3px; white-space:nowrap;
}
.role-fe    { background:linear-gradient(135deg,#e3f2fd,#d4e4f7); color:#1565c0; border:1.5px solid #bdd4f0; }
.role-be    { background:linear-gradient(135deg,#fff3e0,#ffe0b2); color:#e65100; border:1.5px solid #ffe0b2; }
.role-qa    { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); color:#1e8449; border:1.5px solid #a9dfbf; }
.role-lead  { background:linear-gradient(135deg,#f3e5f5,#e8d5f5); color:#6a1b9a; border:1.5px solid #ce93d8; }
.role-qa-lead { background:linear-gradient(135deg,#fce4ec,#f8c8d8); color:#880e4f; border:1.5px solid #f48fb1; }

/* SUMMARY BANNER */
.summary-banner {
  background: linear-gradient(135deg, #e8f0fe 0%, #f3e8ff 50%, #f0f7ff 100%);
  border: 1.5px solid #c5d8f7;
  border-radius: 14px;
  padding: 16px 20px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
  position: relative;
  overflow: hidden;
}
.summary-banner::before { content:''; position:absolute; bottom:0; left:0; right:0; height:3px; background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid),var(--orange)); }
.summary-banner svg { width:28px; height:28px; color:var(--purple-mid); flex-shrink:0; }
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
.vis-active { background:linear-gradient(135deg,#e8f5e9,#c6f0d6); color:#1e8449; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; border:1px solid #a9dfbf; }
.vis-inactive { background:linear-gradient(135deg,#fde8e8,#f9cccc); color:#c0392b; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; border:1px solid #f5b7b1; }

.chip-list { display:flex; flex-wrap:wrap; gap:4px; }
.chip { display:inline-flex; align-items:center; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700; white-space:nowrap; }
.chip-blue   { background:linear-gradient(135deg,#e3f2fd,#d4e4f7); color:#1565c0; border:1px solid #bdd4f0; }
.chip-orange { background:linear-gradient(135deg,#fff3e0,#ffe0b2); color:#e65100; border:1px solid #ffe0b2; }
.chip-green  { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); color:#1e8449; border:1px solid #a9dfbf; }
.chip-gray   { background:linear-gradient(135deg,#f5f5f5,#eee); color:#616161; border:1px solid #ddd; }

/* Empty State */
.empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
.empty-state svg { width:48px; height:48px; margin-bottom:12px; color:#c5d5e8; }
.empty-state p { font-size:14px; font-weight:600; }
.empty-state .sub { font-size:12px; margin-top:4px; }

/* Pagination */
.table-footer { padding:13px 20px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; font-size:12.5px; color:var(--text-muted); font-weight:600; }
.footer-count { font-size:12.5px; color:var(--text-muted); font-weight:600; }
.pg-nav { display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
.pg-btn { min-width:34px; height:34px; padding:0 6px; border-radius:50%; border:1.5px solid var(--border); background:var(--white); display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; cursor:pointer; transition:all .15s; text-decoration:none; color:var(--text-muted); font-family:'Nunito',sans-serif; }
.pg-btn:hover:not(.active):not(.disabled) { background:linear-gradient(135deg,#f0f5fd,#eef4fd); border-color:var(--blue-mid); transform:translateY(-2px); color:var(--blue-dark); box-shadow:0 3px 10px rgba(74,144,217,.15); }
.pg-btn.active { background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; border-color:transparent; box-shadow:0 3px 12px rgba(58,123,213,.3); }
.pg-btn.disabled { opacity:.4; cursor:default; pointer-events:none; }
.pg-btn svg { width:14px; height:14px; }

/* ═══════════════════════════════════════════════════════
   MODAL & FORMS
   ═══════════════════════════════════════════════════════ */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.45); backdrop-filter:blur(3px); z-index:500; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.open { display:flex; }
.modal { background:var(--white); border-radius:20px; width:100%; max-width:620px; box-shadow:0 24px 64px rgba(30,45,80,.22); overflow:hidden; display:flex; flex-direction:column; position:relative; }
.modal::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid)); border-radius:20px 20px 0 0; z-index:1; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:22px 24px 18px; border-bottom:1.5px solid var(--border); flex-shrink:0; }
.modal-header h2 { font-family:'Poppins',sans-serif; font-weight:800; font-size:18px; background:linear-gradient(135deg,var(--blue-dark),var(--purple)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.modal-close { width:32px; height:32px; border-radius:8px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-muted); transition:background .15s,color .15s; }
.modal-close:hover { background:linear-gradient(135deg,#fde8e8,#f9cccc); color:var(--red); }
.modal-close svg { width:16px; height:16px; }
.modal form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }
.modal-body { padding:22px 24px; overflow-y:auto; flex:1; max-height:75vh; }
.modal-footer { padding:16px 24px 20px; border-top:1.5px solid var(--border); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; }

.section-label { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:1px; background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; margin:18px 0 10px; padding-bottom:6px; border-bottom:2px solid transparent; border-image:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid)) 1; }
.section-label:first-child { margin-top:0; }

.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:700; color:var(--text-main); margin-bottom:6px; }
.form-group label span { color:var(--red); }
.form-control { width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; background:var(--white); }
.form-control:focus { border-color:var(--purple-mid); box-shadow:0 0 0 3px rgba(155,89,182,.12); }
.form-control::placeholder { color:#b0bdd6; }
.form-control.field-error { border-color:var(--red) !important; box-shadow:0 0 0 3px rgba(231,76,60,.15) !important; }
.field-error-msg { color:var(--red); font-size:11px; font-weight:700; margin-top:4px; display:none; }
.field-error-msg.visible { display:block; }

.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }

/* Custom Checkbox List for Devs */
.dev-check-group {
  border:1.5px solid var(--border);
  border-radius:10px;
  padding:12px;
  background:linear-gradient(135deg,#f8faff,#f0f4ff);
  max-height:180px;
  overflow-y:auto;
}
.dev-check-item {
  display:flex;
  align-items:center;
  padding:6px 8px;
  border-radius:8px;
  transition:background .15s;
  cursor:pointer;
}
.dev-check-item:hover { background:linear-gradient(90deg,#eef4fd,#f0f7ff); }
.dev-check-item input[type="checkbox"] {
  width:16px; height:16px;
  margin-right:10px;
  cursor:pointer;
  accent-color:var(--purple-mid);
}
.dev-check-item span { font-size:13px; font-weight:600; color:var(--text-main); }
.dev-check-sub { font-size:11px; color:var(--text-muted); margin-left:4px; font-weight:400; }

/* Save Button */
.btn-save { padding:10px 26px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--green),var(--green-mid)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(39,174,96,.28); display:inline-flex; align-items:center; gap:8px; }
.btn-save:hover { opacity:.92; transform:translateY(-1px); box-shadow:0 6px 20px rgba(39,174,96,.35); }
.btn-save svg { width:16px; height:16px; }
.btn-save:disabled { opacity:.5; cursor:not-allowed; transform:none; }
.btn-cancel { padding:10px 22px; border-radius:10px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:background .15s,color .15s; }
.btn-cancel:hover { background:linear-gradient(135deg,#f0f5fd,#eef4fd); color:var(--text-main); }

/* ══ TOAST ══ */
.toast { position:fixed; bottom:28px; right:28px; z-index:999; display:flex; align-items:center; gap:10px; padding:13px 20px; border-radius:12px; font-size:13.5px; font-weight:700; box-shadow:0 8px 28px rgba(30,45,80,.18); min-width:240px; }
.toast.success { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); color:#1e8449; border:1.5px solid #a9dfbf; }
.toast.error   { background:linear-gradient(135deg,#fde8e8,#f9cccc); color:#c0392b; border:1.5px solid #f5b7b1; }
.toast svg { width:17px; height:17px; flex-shrink:0; }
.toast-hide { opacity:0; transition:opacity .5s; }

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

  .toolbar { flex-direction:column; align-items:stretch; }
  .toolbar-left { flex-direction:column; align-items:stretch; width:100%; }
  .search-wrap { max-width:100%; margin-bottom:12px; }
  .btn-add { width:100%; margin-left:0; margin-top:10px; justify-content:center; padding:12px 20px; }
  .toast { right:14px; bottom:14px; min-width:200px; font-size:12px; }

  .modal { max-height:85vh; width:95%; }
  .modal-body { max-height:60vh; padding:16px; }

  .table-footer { flex-direction:column; text-align:center; }
  .pg-nav { justify-content:center; }
  .summary-banner { flex-direction:column; text-align:center; }
  .summary-stats { justify-content:center; }
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
              <h3>You are Assigned to <?= $total_count ?> Project<?= $total_count !== 1 ? 's' : '' ?></h3>
              <p>Below you can view all your assigned projects and your role in each</p>
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
                  <div class="stat-num" style="color:#1e8449;"><?= $total_as_qa ?></div>
                  <div class="stat-lbl">As QA</div>
              </div>
              <?php endif; ?>
          </div>
      </div>

      <!-- QA ACCESS NOTICE -->
      <?php if ($is_qa): ?>
      <div class="qa-notice">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>You are QA — You can only view projects. Add/Edit access is available only for Developers.</span>
      </div>
      <?php endif; ?>

      <div class="toolbar">
        <div class="toolbar-left">
          <form method="GET" action="" style="display:contents;">
            <div class="search-wrap">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" name="search" id="searchInput" class="search-input"
                     placeholder="Search my projects..."
                     value="<?= htmlspecialchars($search) ?>"
                     oninput="autoSearch(this)" autocomplete="off"/>
            </div>
          </form>
          <?php if ($search): ?>
            <a href="?" class="btn-clear">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="width:13px;height:13px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              Clear
            </a>
          <?php endif; ?>
          <?php if ($is_developer): ?>
          <button class="btn-add" onclick="openEditModal(0)">
            <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Project
          </button>
          <?php endif; ?>
        </div>
      </div>

      <div class="table-card">
        <div class="table-wrap">
          <table id="projectTable">
            <thead>
              <tr>
                <th>S.No.</th>
                <th style="background:linear-gradient(90deg,#e8f0fe,#f3e8ff);color:#7c3aed;">My Role</th>
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
                <?php if ($is_developer): ?><th>Actions</th><?php endif; ?>
              </tr>
            </thead>
            <tbody id="projectTbody">
              <?php if (empty($projects)): ?>
              <tr>
                <td colspan="<?= $is_developer ? 18 : 17 ?>">
                  <div class="empty-state">
                    <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    <p>No Projects Assigned Yet</p>
                    <p class="sub">When admin assigns you to a project, it will appear here</p>
                  </div>
                </td>
              </tr>
              <?php else: ?>
                <?php $start_num = ($page - 1) * $per_page + 1; foreach ($projects as $i => $p): ?>
                <tr id="row-<?= $p['id'] ?>">
                  <td style="color:var(--text-muted);font-size:12.5px;font-weight:700;"><?= $start_num + $i ?></td>
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
                  <td>
                    <div class="chip-list">
                      <?php if(!empty($p['fe_devs_names'])): foreach ($p['fe_devs_names'] as $d): ?>
                        <span class="chip chip-blue"><?= htmlspecialchars($d) ?></span>
                      <?php endforeach; else: echo '---'; endif; ?>
                    </div>
                  </td>
                  <td>
                    <div class="chip-list">
                      <?php if(!empty($p['be_devs_names'])): foreach ($p['be_devs_names'] as $d): ?>
                        <span class="chip chip-orange"><?= htmlspecialchars($d) ?></span>
                      <?php endforeach; else: echo '---'; endif; ?>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($p['lead_name'] ?? '---') ?></td>
                  <td><?= htmlspecialchars($p['qa_lead_name'] ?? '---') ?></td>
                  <td>
                    <div class="chip-list">
                      <?php if(!empty($p['qa_names'])): foreach ($p['qa_names'] as $d): ?>
                        <span class="chip chip-green"><?= htmlspecialchars($d) ?></span>
                      <?php endforeach; else: echo '---'; endif; ?>
                    </div>
                  </td>
                  <td>
                    <div class="chip-list">
                      <?php if(!empty($p['tech_fe'])): foreach ($p['tech_fe'] as $t): ?>
                        <span class="chip chip-blue"><?= htmlspecialchars($t) ?></span>
                      <?php endforeach; else: echo '---'; endif; ?>
                    </div>
                  </td>
                  <td>
                    <div class="chip-list">
                      <?php if(!empty($p['tech_be'])): foreach ($p['tech_be'] as $t): ?>
                        <span class="chip chip-orange"><?= htmlspecialchars($t) ?></span>
                      <?php endforeach; else: echo '---'; endif; ?>
                    </div>
                  </td>
                  <td>
                    <div class="chip-list">
                      <?php if(!empty($p['tech_other'])): foreach ($p['tech_other'] as $t): ?>
                        <span class="chip chip-gray"><?= htmlspecialchars($t) ?></span>
                      <?php endforeach; else: echo '---'; endif; ?>
                    </div>
                  </td>
                  <td style="font-size:12px;white-space:nowrap;color:var(--text-muted);"><?= fmt_date($p['start_date']) ?></td>
                  <td style="font-size:12px;white-space:nowrap;color:var(--text-muted);"><?= fmt_date($p['deadline_date']) ?></td>
                  <td style="font-size:12px;white-space:nowrap;color:var(--text-muted);"><?= fmt_date($p['delivery_date']) ?></td>
                  <?php if ($is_developer): ?>
                  <td>
                    <div class="action-btns">
                      <button class="btn-icon edit" title="Edit Project" onclick="openEditModal(<?= $p['id'] ?>)">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      </button>
                    </div>
                  </td>
                  <?php endif; ?>
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
          <div class="pg-nav">
            <a href="?page=<?= max(1, $page - 1) ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
               class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
            <?php for ($pg = 1; $pg <= $total_pages; $pg++): ?>
              <?php if ($total_pages > 7 && abs($pg - $page) > 2 && $pg !== 1 && $pg !== $total_pages): ?>
                <?php if ($pg === 2 || $pg === $total_pages - 1): ?><span style="padding:0 4px;color:var(--text-muted);">&hellip;</span><?php endif; ?>
              <?php else: ?>
                <a href="?page=<?= $pg ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                   class="pg-btn <?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <a href="?page=<?= min($total_pages, $page + 1) ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
               class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
   MODAL (Add/Edit Project) — DEVELOPERS ONLY — Direct Save
   ═══════════════════════════════════════════════════════ -->
<?php if ($is_developer): ?>
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h2 id="editModalTitle">Add Project</h2>
      <button class="modal-close" onclick="closeModal('editModal')" type="button">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" id="editForm">
      <input type="hidden" name="action_form" value="save_project"/>
      <input type="hidden" name="edit_id" id="eId"/>
      <input type="hidden" name="ajax" value="1"/>
      <div class="modal-body">
        <!-- BASIC INFO -->
        <div class="section-label">Basic Info</div>
        <div class="form-row">
          <div class="form-group"><label>Project Name <span>*</span></label><input type="text" id="eName" name="project_name" class="form-control" placeholder="Enter project name"/><div class="field-error-msg" id="errName">Project Name is required</div></div>
          <div class="form-group">
            <label>Client <span>*</span></label>
            <select id="eClientSelect" name="client_id" class="form-control">
              <option value="">Select Client</option>
              <?php foreach ($clients_list as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="field-error-msg" id="errClient">Client is required</div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Status <span>*</span></label>
            <select id="eStatusSelect" name="status" class="form-control">
              <option value="Not Started">Not Started</option>
              <option value="In Progress">In Progress</option>
              <option value="Completed">Completed</option>
            </select>
            <div class="field-error-msg" id="errStatus">Status is required</div>
          </div>
          <div class="form-group">
            <label>Visibility</label>
            <label style="margin-top:8px;display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="is_active" value="1" checked style="width:18px;height:18px;accent-color:var(--purple-mid);cursor:pointer;"/>
              <span style="font-size:13px;font-weight:600;color:var(--text-main);">Project is Active</span>
            </label>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Start Date <span>*</span></label>
            <input type="date" id="eStartDate" name="start_date" class="form-control"/>
            <div class="field-error-msg" id="errStartDate">Start Date is required</div>
          </div>
          <div class="form-group">
            <label>Deadline Date</label>
            <input type="date" id="eDeadlineDate" name="deadline_date" class="form-control"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Delivery Date</label>
            <input type="date" id="eDeliveryDate" name="delivery_date" class="form-control"/>
          </div>
          <div class="form-group"></div>
        </div>

        <!-- TEAM MEMBERS -->
        <div class="section-label">Team Members</div>
        <div class="form-group">
          <label>Frontend Developers</label>
          <div class="dev-check-group">
            <?php foreach ($developers_list as $u): ?>
              <label class="dev-check-item">
                <input type="checkbox" name="fe_devs[]" value="<?= $u['id'] ?>" id="eFeDev_<?= $u['id'] ?>"/>
                <span><?= htmlspecialchars($u['name']) ?></span>
                <span class="dev-check-sub">(<?= htmlspecialchars($u['username']) ?>)</span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label>Backend Developers</label>
          <div class="dev-check-group">
            <?php foreach ($developers_list as $u): ?>
              <label class="dev-check-item">
                <input type="checkbox" name="be_devs[]" value="<?= $u['id'] ?>" id="eBeDev_<?= $u['id'] ?>"/>
                <span><?= htmlspecialchars($u['name']) ?></span>
                <span class="dev-check-sub">(<?= htmlspecialchars($u['username']) ?>)</span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Project Lead <span>*</span></label>
            <select name="project_lead" id="eLead" class="form-control">
              <option value="">Select Project Lead</option>
              <?php foreach ($leads_list as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
            </select>
            <div class="field-error-msg" id="errLead">Project Lead is required</div>
          </div>
          <div class="form-group">
            <label>QA Lead <span>*</span></label>
            <select name="qa_lead" id="eQaLead" class="form-control">
              <option value="">Select QA Lead</option>
              <?php foreach ($testers_list as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
            </select>
            <div class="field-error-msg" id="errQaLead">QA Lead is required</div>
          </div>
        </div>
        <div class="form-group">
          <label>QA Team</label>
          <div class="dev-check-group">
            <?php foreach ($testers_list as $u): ?>
              <label class="dev-check-item">
                <input type="checkbox" name="qa_team[]" value="<?= $u['id'] ?>" id="eQaDev_<?= $u['id'] ?>"/>
                <span><?= htmlspecialchars($u['name']) ?></span>
                <span class="dev-check-sub">(<?= htmlspecialchars($u['username']) ?>)</span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- TECHNOLOGIES -->
        <div class="section-label">Technologies <span style="color:var(--red);-webkit-text-fill-color:var(--red);font-size:14px;">*</span></div>
        <div class="field-error-msg" id="errTech" style="margin-bottom:8px;">At least one Technology must be selected</div>
        <div class="form-row-3">
          <div class="form-group">
            <label>Frontend Tech</label>
            <div class="dev-check-group" id="feTechGroup">
              <?php foreach ($technologies_list as $t): ?>
                <label class="dev-check-item">
                  <input type="checkbox" name="fe_tech[]" value="<?= $t['id'] ?>" id="eFeTech_<?= $t['id'] ?>"/>
                  <span><?= htmlspecialchars($t['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label>Backend Tech</label>
            <div class="dev-check-group" id="beTechGroup">
              <?php foreach ($technologies_list as $t): ?>
                <label class="dev-check-item">
                  <input type="checkbox" name="be_tech[]" value="<?= $t['id'] ?>" id="eBeTech_<?= $t['id'] ?>"/>
                  <span><?= htmlspecialchars($t['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label>Other Tech</label>
            <div class="dev-check-group" id="otherTechGroup">
              <?php foreach ($technologies_list as $t): ?>
                <label class="dev-check-item">
                  <input type="checkbox" name="other_tech[]" value="<?= $t['id'] ?>" id="eOtherTech_<?= $t['id'] ?>"/>
                  <span><?= htmlspecialchars($t['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn-save" id="btnSave">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save Project
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; /* end is_developer for modal */ ?>

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
<script>setTimeout(function(){var t=document.getElementById('toast');if(t){t.classList.add('toast-hide');setTimeout(function(){t.remove();},500);}},4000);</script>
<?php endif; ?>

<script>
/* ── Sidebar ── */
function toggleSidebar(){var sb=document.getElementById('sidebar'),ov=document.getElementById('sbOverlay'),hm=document.getElementById('hamBtn'),o=sb.classList.toggle('open');ov.classList.toggle('open',o);hm.classList.toggle('open',o);document.body.style.overflow=(o&&window.innerWidth<=768)?'hidden':'';}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sbOverlay').classList.remove('open');document.getElementById('hamBtn').classList.remove('open');document.body.style.overflow='';}
window.addEventListener('resize',function(){if(window.innerWidth>768)closeSidebar();});

/* ── Toast ── */
function showToast(msg,type){var old=document.getElementById('liveToast');if(old)old.remove();var t=document.createElement('div');t.className='toast '+type;t.id='liveToast';var icon=type==='success'?'<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>':'<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';t.innerHTML=icon+msg;document.body.appendChild(t);setTimeout(function(){t.classList.add('toast-hide');setTimeout(function(){t.remove();},500);},4000);}

/* ── Auto Search ── */
var _st=null;function autoSearch(inp){clearTimeout(_st);_st=setTimeout(function(){var val=inp.value.trim();window.location.href=val?'?search='+encodeURIComponent(val):'?';},800);}
</script>

<?php if ($is_developer): ?>
<script>
/* ── Edit Modal ── */
function openEditModal(projectId){
  var modalTitle = document.getElementById('editModalTitle');
  var btnSave = document.getElementById('btnSave');

  // Reset all checkboxes
  document.querySelectorAll('#editModal input[name="fe_devs[]"]').forEach(function(cb){ cb.checked=false; });
  document.querySelectorAll('#editModal input[name="be_devs[]"]').forEach(function(cb){ cb.checked=false; });
  document.querySelectorAll('#editModal input[name="qa_team[]"]').forEach(function(cb){ cb.checked=false; });
  // Reset tech checkboxes
  document.querySelectorAll('#editModal input[name="fe_tech[]"]').forEach(function(cb){ cb.checked=false; });
  document.querySelectorAll('#editModal input[name="be_tech[]"]').forEach(function(cb){ cb.checked=false; });
  document.querySelectorAll('#editModal input[name="other_tech[]"]').forEach(function(cb){ cb.checked=false; });
  // Clear validation errors
  document.querySelectorAll('.field-error').forEach(function(el){ el.classList.remove('field-error'); });
  document.querySelectorAll('.field-error-msg').forEach(function(el){ el.classList.remove('visible'); });

  // ═══ ADD MODE (projectId = 0) ═══
  if(!projectId || projectId === 0){
    modalTitle.textContent = 'Add Project';
    btnSave.innerHTML = '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Project';

    // Clear all fields
    document.getElementById('eId').value = '0';
    document.getElementById('eName').value = '';
    document.getElementById('eName').placeholder = 'Enter project name';
    document.getElementById('eClientSelect').value = '';
    document.getElementById('eStatusSelect').value = 'Not Started';
    document.querySelector('#editModal input[name="is_active"]').checked = true;
    document.getElementById('eStartDate').value = '';
    document.getElementById('eDeadlineDate').value = '';
    document.getElementById('eDeliveryDate').value = '';
    document.getElementById('eLead').value = '';
    document.getElementById('eQaLead').value = '';

    document.getElementById('editModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    // Scroll modal body to top & page to top
    var modalBody = document.querySelector('#editModal .modal-body');
    if(modalBody) modalBody.scrollTop = 0;
    window.scrollTo({top:0,behavior:'smooth'});
    return;
  }

  // ═══ EDIT MODE (existing project) ═══
  modalTitle.textContent = 'Edit Project';
  btnSave.innerHTML = '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Update Project';

  fetch('?get_project_data='+projectId)
    .then(function(r){return r.json();})
    .then(function(data){
      if(!data.success){showToast('Failed to load project data: '+(data.error||'Error'),'error');return;}

      document.getElementById('eId').value = data.id || '';
      document.getElementById('eName').value = data.name || '';
      document.getElementById('eClientSelect').value = data.client_id || '';
      document.getElementById('eStatusSelect').value = data.status || 'Not Started';
      document.querySelector('#editModal input[name="is_active"]').checked = (data.action === 'active');
      document.getElementById('eStartDate').value = data.start_date || '';
      document.getElementById('eDeadlineDate').value = data.deadline_date || '';
      document.getElementById('eDeliveryDate').value = data.delivery_date || '';
      document.getElementById('eLead').value = data.project_lead_id || '';
      document.getElementById('eQaLead').value = data.qa_lead_id || '';

      if(data.fe_devs && data.fe_devs.length>0){
        data.fe_devs.forEach(function(uid){var cb=document.getElementById('eFeDev_'+uid);if(cb)cb.checked=true;});
      }
      if(data.be_devs && data.be_devs.length>0){
        data.be_devs.forEach(function(uid){var cb=document.getElementById('eBeDev_'+uid);if(cb)cb.checked=true;});
      }
      if(data.qa_team && data.qa_team.length>0){
        data.qa_team.forEach(function(uid){var cb=document.getElementById('eQaDev_'+uid);if(cb)cb.checked=true;});
      }

      // Check tech checkboxes
      if(data.fe_tech && data.fe_tech.length>0){
        data.fe_tech.forEach(function(tid){var cb=document.getElementById('eFeTech_'+tid);if(cb)cb.checked=true;});
      }
      if(data.be_tech && data.be_tech.length>0){
        data.be_tech.forEach(function(tid){var cb=document.getElementById('eBeTech_'+tid);if(cb)cb.checked=true;});
      }
      if(data.other_tech && data.other_tech.length>0){
        data.other_tech.forEach(function(tid){var cb=document.getElementById('eOtherTech_'+tid);if(cb)cb.checked=true;});
      }

      document.getElementById('editModal').classList.add('open');
      document.body.style.overflow='hidden';
      // Scroll modal body to top & page to top
      var modalBody = document.querySelector('#editModal .modal-body');
      if(modalBody) modalBody.scrollTop = 0;
      window.scrollTo({top:0,behavior:'smooth'});
    })
    .catch(function(err){console.error('Edit fetch error:',err);showToast('Failed to load data.','error');});
}

function closeModal(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';window.scrollTo({top:0,behavior:'smooth'});}
document.getElementById('editModal').addEventListener('click',function(e){if(e.target===this)closeModal('editModal');});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal('editModal');});

/* ── AJAX Form Submit ── */
document.getElementById('editForm').addEventListener('submit',function(e){
  e.preventDefault();

  // ── VALIDATION ──
  var errors = [];
  function clearErrors(){
    document.querySelectorAll('.field-error').forEach(function(el){ el.classList.remove('field-error'); });
    document.querySelectorAll('.field-error-msg').forEach(function(el){ el.classList.remove('visible'); });
  }
  function markError(el, msgId){
    if(el) el.classList.add('field-error');
    var msg = document.getElementById(msgId);
    if(msg) msg.classList.add('visible');
  }
  clearErrors();

  // 1. Project Name
  var nameVal = document.getElementById('eName').value.trim();
  if(!nameVal){
    errors.push('Project Name is required');
    markError(document.getElementById('eName'), 'errName');
  }

  // 2. Client
  var clientVal = document.getElementById('eClientSelect').value;
  if(!clientVal){
    errors.push('Client is required');
    markError(document.getElementById('eClientSelect'), 'errClient');
  }

  // 3. Status — always has a default value, but still check
  var statusVal = document.getElementById('eStatusSelect').value;
  if(!statusVal){
    errors.push('Status is required');
    markError(document.getElementById('eStatusSelect'), 'errStatus');
  }

  // 4. Start Date
  var startDateVal = document.getElementById('eStartDate').value;
  if(!startDateVal){
    errors.push('Start Date is required');
    markError(document.getElementById('eStartDate'), 'errStartDate');
  }

  // 5. Project Lead
  var leadVal = document.getElementById('eLead').value;
  if(!leadVal){
    errors.push('Project Lead is required');
    markError(document.getElementById('eLead'), 'errLead');
  }

  // 6. QA Lead
  var qaLeadVal = document.getElementById('eQaLead').value;
  if(!qaLeadVal){
    errors.push('QA Lead is required');
    markError(document.getElementById('eQaLead'), 'errQaLead');
  }

  // 7. Technologies (at least one tech checkbox must be checked)
  var techChecked = document.querySelectorAll('#editModal input[name="fe_tech[]"]:checked, #editModal input[name="be_tech[]"]:checked, #editModal input[name="other_tech[]"]:checked');
  if(techChecked.length === 0){
    errors.push('At least one Technology must be selected');
    var errTechMsg = document.getElementById('errTech');
    if(errTechMsg) errTechMsg.classList.add('visible');
    document.querySelectorAll('#feTechGroup, #beTechGroup, #otherTechGroup').forEach(function(g){ g.classList.add('field-error'); });
  }

  // If errors, show toast and stop
  if(errors.length > 0){
    showToast(errors[0], 'error');
    // Scroll to first error field
    var firstErr = document.querySelector('.field-error');
    if(firstErr) firstErr.scrollIntoView({behavior:'smooth', block:'center'});
    return;
  }

  var btnSave=document.getElementById('btnSave');
  if(btnSave.disabled)return;
  var formData=new FormData(this);
  btnSave.disabled=true;
  btnSave.innerHTML='<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Saving...';
  fetch('',{method:'POST',body:formData})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.success){showToast(d.message||'Project saved!','success');closeModal('editModal');setTimeout(function(){window.scrollTo({top:0,behavior:'smooth'});window.location.reload();},1500);}
      else{showToast(d.error||'Error saving project!','error');btnSave.disabled=false;btnSave.innerHTML='<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Project';}
    })
    .catch(function(err){console.error('Submit error:',err);showToast('Error saving project!','error');btnSave.disabled=false;btnSave.innerHTML='<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Project';});
});
</script>
<?php endif; /* end is_developer for JS */ ?>
</body>
</html>
