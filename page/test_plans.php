<?php
// ── page/client/test_plans.php ─────────────────────────────
session_start();
include '../config/db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

 $msg      = '';
 $msg_type = '';

// PRG Messages
if (isset($_GET['added']))        { $msg = 'Test Plan added successfully!';   $msg_type = 'success'; }
elseif (isset($_GET['updated']))  { $msg = 'Test Plan updated!';              $msg_type = 'success'; }
elseif (isset($_GET['deleted']))  { $msg = 'Test Plan deleted.';              $msg_type = 'success'; }
elseif (isset($_GET['add_err']))  { $msg = 'Project, Title, Objective aur Scope required hain.'; $msg_type = 'error'; }
elseif (isset($_GET['edit_err'])) { $msg = 'Project, Title aur Scope required hain.';              $msg_type = 'error'; }
elseif (isset($_GET['import_err'])){ $msg = 'Project select karein aur CSV file upload karein.';   $msg_type = 'error'; }
elseif (isset($_GET['imported'])) {
    $imp  = (int)$_GET['imported'];
    $errs = (int)($_GET['imp_err'] ?? 0);
    $msg  = "CSV se $imp test plan(s) import ho gaye!";
    if ($errs) $msg .= " ($errs rows skip ho gaye).";
    $msg_type = 'success';
}

// ════════════════════════════════════════════════════════════
//  IMPORT CSV
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    $import_project_id = (int)($_POST['import_project_id'] ?? 0);
    if (!$import_project_id) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?import_err=1');
        exit;
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?import_err=1');
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

            $title         = trim($data['title'] ?? '');
            $objective     = trim($data['objective'] ?? '');
            $scope         = trim($data['scope'] ?? '');
            $roles         = trim($data['roles_covered'] ?? '');
            $types_csv     = trim($data['testing_types'] ?? '');
            $testing_types = !empty($types_csv) ? array_map('trim', explode(',', $types_csv)) : [];
            $tech_csv      = trim($data['technologies'] ?? '');
            $technologies  = !empty($tech_csv) ? array_map('trim', explode(',', $tech_csv)) : [];
            $lead_id       = (int)($data['project_lead_id'] ?? 0);
            $test_lead_id  = (int)($data['test_lead_id']    ?? 0);

            if (!$title) { $errors++; continue; }

            $stmt = $conn->prepare(
                "INSERT INTO test_plans (project_id, title, objective, scope, testing_types, technologies, project_lead_id, test_lead_id, roles_covered)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('isssssiis',
                $pid_to_use, $title, $objective, $scope,
                json_encode($testing_types), json_encode($technologies),
                $lead_id, $test_lead_id, $roles
            );

            if ($stmt->execute()) $imported++; else $errors++;
            $stmt->close();
        }
        fclose($handle);

        $redirect = strtok($_SERVER['REQUEST_URI'], '?') . "?imported=$imported";
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
    $stmt = $conn->prepare("DELETE FROM test_plans WHERE id = ?");
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    $stmt->close();
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?deleted=1');
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
    $lead_id      = (int)($_POST['project_lead_id']  ?? 0);
    $test_lead_id = (int)($_POST['test_lead_id']     ?? 0);
    $roles        = trim($_POST['roles_covered']     ?? '');

    if (!$project_id || !$title || !$objective || !$scope) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?add_err=1');
        exit;
    }
    $stmt = $conn->prepare(
        "INSERT INTO test_plans (project_id, title, objective, scope, testing_types, technologies, project_lead_id, test_lead_id, roles_covered)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('isssssiis',
        $project_id, $title, $objective, $scope,
        json_encode($post_types), json_encode($techs),
        $lead_id, $test_lead_id, $roles
    );
    if ($stmt->execute()) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?added=1'); exit;
    } else {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?add_err=1'); exit;
    }
    $stmt->close();
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
    $lead_id      = (int)($_POST['project_lead_id']   ?? 0);
    $test_lead_id = (int)($_POST['test_lead_id']      ?? 0);
    $roles        = trim($_POST['roles_covered']      ?? '');

    if (!$project_id || !$title || !$scope) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?edit_err=1');
        exit;
    }
    $stmt = $conn->prepare(
        "UPDATE test_plans SET project_id=?, title=?, objective=?, scope=?, testing_types=?, technologies=?, project_lead_id=?, test_lead_id=?, roles_covered=? WHERE id=?"
    );
    $stmt->bind_param('isssssiisi',
        $project_id, $title, $objective, $scope,
        json_encode($post_types), json_encode($techs),
        $lead_id, $test_lead_id, $roles, $edit_id
    );
    if ($stmt->execute()) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?updated=1'); exit;
    } else {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?edit_err=1'); exit;
    }
    $stmt->close();
}

// ════════════════════════════════════════════════════════════
//  FETCH DATA
// ════════════════════════════════════════════════════════════
 $clients_list = [];
 $c_res = $conn->query("SELECT id, name FROM clients WHERE status='active' ORDER BY name");
if ($c_res) while ($r = $c_res->fetch_assoc()) $clients_list[] = $r;

 $projects_list = [];
 $p_res = $conn->query("SELECT id, client_id, name FROM projects ORDER BY name ASC");
if ($p_res) while ($r = $p_res->fetch_assoc()) $projects_list[] = $r;

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

if ($filters_applied) {
    $where        = [];
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

    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

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
        "SELECT tp.*, p.name AS project_name, pl.name AS project_lead_name, tl.name AS test_lead_name
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover"/>
<title>TestiFy — Test Plans</title>
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
.empty-state small { font-size:12.5px; }

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

  /* Navbar */
  .navbar { padding:0 12px; }
  .ham { display:flex; }
  .nblogo { font-size:19px; }
  .nb-user span:not(.nb-role) { display:none; }
  .nb-role { display:none; }
  .blgout { padding:6px 12px; font-size:12px; border-radius:8px; }

  /* Sidebar — hidden by default, slides in */
  .sidebar { transform:translateX(-100%); width:var(--sb-w); }
  .sidebar.open { transform:translateX(0); }
  .sidebar-overlay.open { display:block; }
  .page-wrap { margin-left:0; }

  /* Main */
  .main { padding:16px 12px 48px; }
  .page-title h1 { font-size:20px; }
  .badge-page { font-size:9px; padding:4px 10px; }

  /* Toolbar — stack vertically */
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

  /* Active filter bar */
  .active-filter-bar { padding:8px 12px; gap:6px; font-size:12px; }
  .filter-tag { font-size:11px; padding:2px 8px; }
  .btn-clear-active { padding:4px 12px; font-size:11px; }

  /* Table */
  .table-card { border-radius:14px; }
  table { min-width:900px; }
  thead th { padding:10px 10px; font-size:11px; }
  tbody td { padding:10px 10px; font-size:12.5px; }

  /* Badges compact */
  .badge { font-size:10.5px; padding:2px 8px; }

  /* Action buttons */
  .btn-icon { width:32px; height:32px; border-radius:8px; }
  .btn-icon svg { width:12px; height:12px; }

  /* Form rows */
  .form-row { grid-template-columns:1fr; gap:0; }
  .check-grid { grid-template-columns:1fr 1fr; gap:8px; }

  /* Modal — centered */
  .modal { max-height:85vh; width:95%; }
  .modal-header { padding:18px 18px 14px; }
  .modal-header h2 { font-size:16px; }
  .modal-body { max-height:60vh; padding:16px 18px; }
  .modal-footer { padding:14px 18px 16px; flex-wrap:wrap; gap:8px; }
  .btn-save, .btn-cancel { flex:1; text-align:center; justify-content:center; }

  /* Confirm */
  .confirm-box { padding:22px 18px 18px; }
  .confirm-box h3 { font-size:15px; }
  .confirm-btns { flex-direction:column; }
  .btn-confirm-del, .btn-confirm-cancel { width:100%; text-align:center; }

  /* Toast */
  .toast { right:12px; bottom:12px; left:12px; min-width:0; font-size:12.5px; padding:10px 14px; }

  /* Pagination */
  .table-footer { flex-direction:column; text-align:center; padding:10px 14px; gap:8px; }
  .pg-nav { justify-content:center; }
  .pg-btn { min-width:30px; height:30px; font-size:12px; }

  /* Empty state */
  .empty-state { padding:30px 16px; }
  .empty-state p { font-size:13px; }
  .empty-state small { font-size:11px; }
}

/* ═══════════════════════════════════════════════════════
   RESPONSIVE — Small Mobile (max 480px)
   ═══════════════════════════════════════════════════════ */
@media(max-width:480px){
  :root { --nb-h: 52px; }

  /* Navbar */
  .navbar { padding:0 10px; }
  .nblogo { font-size:17px; }
  .blgout { padding:5px 10px; font-size:11px; border-radius:8px; }

  /* Main */
  .main { padding:12px 8px 40px; }
  .page-title { gap:8px; margin-bottom:16px; }
  .page-title h1 { font-size:17px; }
  .badge-page { font-size:8px; padding:3px 8px; letter-spacing:1px; }

  /* Search */
  .search-input { font-size:16px; padding:10px 14px 10px 36px; } /* 16px prevents iOS zoom */

  /* Filter selects */
  .filter-select { font-size:16px; height:42px; } /* 16px prevents iOS zoom */

  /* Buttons */
  .btn-filter, .btn-template, .btn-import, .btn-add { font-size:12px; height:40px; border-radius:8px; }
  .btn-filter svg, .btn-template svg, .btn-import svg, .btn-add svg { width:14px; height:14px; }

  /* Table */
  table { min-width:780px; }
  thead th { padding:8px 8px; font-size:10px; letter-spacing:.5px; }
  tbody td { padding:8px 8px; font-size:12px; }
  .req-title { font-size:12.5px; }
  .obj-desc { font-size:10px; max-width:160px; }

  /* Badges compact */
  .badge { font-size:10px; padding:2px 6px; border-radius:14px; }

  /* Action buttons */
  .btn-icon { width:30px; height:30px; border-radius:8px; }
  .btn-icon svg { width:12px; height:12px; }

  /* Modal */
  .modal { max-width:100%; max-height:90vh; border-radius:16px; }
  .modal-header { padding:16px 14px 12px; }
  .modal-header h2 { font-size:15px; }
  .modal-body { padding:12px 14px; max-height:55vh; }
  .modal-footer { padding:12px 14px 14px; gap:6px; }
  .btn-save, .btn-cancel { font-size:13px; padding:10px 18px; border-radius:8px; }

  /* Check grid single column on small mobile */
  .check-grid { grid-template-columns:1fr; gap:6px; }
  .check-section { padding:12px; }
  .check-item span { font-size:12px; }
  .check-item input[type="checkbox"] { width:18px; height:18px; }

  /* Form controls */
  .form-control { padding:10px 12px; font-size:16px; border-radius:8px; } /* 16px prevents iOS zoom */
  textarea.form-control { min-height:70px; }

  /* Confirm */
  .confirm-box { padding:18px 14px 16px; border-radius:14px; }
  .confirm-box h3 { font-size:14px; }

  /* Toast */
  .toast { font-size:12px; padding:8px 12px; border-radius:10px; gap:8px; left:8px; right:8px; bottom:8px; }

  /* Pagination */
  .pg-btn { min-width:28px; height:28px; font-size:11px; border-radius:50%; }

  /* Table footer */
  .table-footer { padding:8px 10px; font-size:11px; }
  .footer-count { font-size:11px; }

  /* Empty state */
  .empty-state { padding:24px 12px; }
  .empty-state svg { width:36px; height:36px; }
  .empty-state p { font-size:12px; }
  .empty-state small { font-size:10.5px; }

  /* Active filter bar */
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
      <span><?= htmlspecialchars($_SESSION['name'] ?? 'Guest') ?></span>
      <?php $user_role = $_SESSION['role'] ?? ''; if($user_role): ?>
        <span class="nb-role"><?= htmlspecialchars(ucfirst($user_role)) ?></span>
      <?php endif; ?>
    </div>
    <a href="../logout.php" class="blgout">Logout</a>
  </div>
</nav>

<!-- ══════════ SIDEBAR OVERLAY ══════════ -->
<div class="sidebar-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<!-- ══════════ SIDEBAR ══════════ -->
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
  <a href="../page/test_plans.php" class="sb-link active">
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
  <a href="../page/project_reports.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
  </a>
</aside>

<!-- ══════════ PAGE WRAP ══════════ -->
<div class="page-wrap" id="pageWrap">
<div class="main">
  <div class="page-title">
    <h1>Test Plans</h1>
    <span class="badge-page">Management</span>
  </div>

  <!-- TOOLBAR -->
  <div class="toolbar">
    <div class="toolbar-left">
      <?php if (!$filters_applied): ?>
      <!-- Filter form — only shown when no filter is active -->
      <form method="GET" action="" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
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
        </select>

        <button type="submit" class="btn-filter">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
          Apply Filter
        </button>
      </form>

      <?php else: ?>
      <!-- Active filter summary — shown when filter is applied -->
      <div class="active-filter-bar">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
        <span>Filters active:</span>
        <?php
          if ($filter_client) {
              foreach ($clients_list as $c) {
                  if ($c['id'] == $filter_client) {
                      echo '<span class="filter-tag">Client: ' . htmlspecialchars($c['name']) . '</span>';
                  }
              }
          }
          if ($filter_project) {
              foreach ($projects_list as $p) {
                  if ($p['id'] == $filter_project) {
                      echo '<span class="filter-tag">Project: ' . htmlspecialchars($p['name']) . '</span>';
                  }
              }
          }
          if ($search) {
              echo '<span class="filter-tag">Search: "' . htmlspecialchars($search) . '"</span>';
          }
        ?>
        <a href="?" class="btn-clear-active">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Clear
        </a>
      </div>
      <?php endif; ?>

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

  <!-- TABLE -->
  <div class="table-card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>S.No.</th>
            <th>Test Plan ID</th>
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
          <tr><td colspan="10">
            <div class="empty-state">
              <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
              <p>No Test Plans found. Apply filters or add one.</p>
            </div>
          </td></tr>
          <?php else: ?>
            <?php $sn = ($page - 1) * 10 + 1; foreach ($test_plans as $tp): ?>
            <tr>
              <td><?= $sn++ ?></td>
              <td><strong>TP-00<?= $tp['id'] ?></strong></td>
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
                  <button class="btn-icon edit" onclick='openEditModal(<?= json_encode($tp) ?>)'>
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <button class="btn-icon del" onclick="confirmDelete(<?= $tp['id'] ?>)">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                  </button>
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
</div><!-- end .page-wrap -->

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
  <div class="modal" style="max-width:500px;">
    <div class="modal-header">
      <h2>Import Test Plans</h2>
      <button class="modal-close" onclick="closeImportModal()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12" height="12"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" enctype="multipart/form-data" style="display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden;">
      <input type="hidden" name="action" value="import_csv"/>
      <div class="modal-body">
        <div class="form-group">
          <label>Default Project <span>*</span></label>
          <select name="import_project_id" class="form-control" required>
            <option value="">Select Project</option>
            <?php foreach ($projects_list as $proj): ?>
              <option value="<?= $proj['id'] ?>"><?= htmlspecialchars($proj['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:5px;">If CSV doesn't contain a project name, this project will be used.</div>
        </div>
        <div class="form-group">
          <label>Upload CSV <span>*</span></label>
          <div id="dropZone" onclick="document.getElementById('csvFileInput').click()"
               style="border:2px dashed var(--border);border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:#fafcff;">
            <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="width:36px;height:36px;color:#b0bdd6;margin-bottom:10px;">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <div id="dropLabel" style="font-size:13.5px;font-weight:700;color:var(--text-muted);">Click karein ya CSV file drag karein</div>
            <div style="font-size:11.5px;color:#b0bdd6;margin-top:4px;">Sirf .csv files allowed hain</div>
          </div>
          <input type="file" name="csv_file" id="csvFileInput" accept=".csv" style="display:none;" onchange="onFileSelect(this)"/>
        </div>
        <div style="background:linear-gradient(135deg,#f8faff,#f0f4ff);border:1.5px solid var(--border);border-radius:10px;padding:14px 16px;position:relative;">
          <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid));border-radius:10px 10px 0 0;"></div>
          <div style="font-size:11.5px;font-weight:700;color:var(--text-muted);letter-spacing:.8px;text-transform:uppercase;margin-bottom:8px;">CSV Format Guide</div>
          <div style="font-size:12px;color:var(--text-main);line-height:1.8;">
            <strong>Required:</strong> project_name, title<br>
            <strong>Optional:</strong> objective, scope, testing_types, technologies, project_lead_id, test_lead_id, roles_covered<br><br>
            <strong>Testing Types:</strong> comma separated (e.g. "Manual testing,Functional testing")<br>
            <strong>Technologies:</strong> comma separated (e.g. "React,MySQL")
          </div>
          <button type="button" onclick="downloadTemplate()"
                  style="margin-top:10px;padding:6px 14px;border-radius:8px;border:1.5px solid var(--blue-mid);background:transparent;color:var(--blue-mid);font-family:'Nunito',sans-serif;font-weight:700;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:background .15s,color .15s;">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:13px;height:13px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Sample Template Download Karein
          </button>
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
    <p id="confirmMsg">Are you sure? This cannot be undone.</p>
    <div class="confirm-btns">
      <button class="btn-confirm-del" onclick="doDelete()">Delete</button>
      <button class="btn-confirm-cancel" onclick="closeConfirm()">Cancel</button>
    </div>
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
<script>
  setTimeout(function(){
    var t = document.getElementById('toast');
    if(t){ t.classList.add('toast-hide'); setTimeout(function(){ t.remove(); },300); }
  }, 4000);
</script>
<?php endif; ?>

<script>
// ── Safety: Clear any stuck overlays on page load ──
document.addEventListener('DOMContentLoaded', function(){
  document.body.style.overflow = '';
  document.querySelectorAll('.modal-overlay.open, .confirm-overlay.open, .sidebar-overlay.open').forEach(function(el){
    el.classList.remove('open');
  });
  document.querySelectorAll('.sidebar.open, .ham.open').forEach(function(el){
    el.classList.remove('open');
  });

  // Auto-open add modal if add error
  var params = new URLSearchParams(window.location.search);
  if (params.has('add_err')) {
    openAddModal();
  }

  // Clean URL: remove flash-message params so refresh doesn't re-trigger
  var cleanParams = ['added','updated','deleted','add_err','edit_err','import_err','imported','imp_err'];
  var url = new URL(window.location.href);
  var changed = false;
  cleanParams.forEach(function(k){ if(url.searchParams.has(k)){ url.searchParams.delete(k); changed = true; } });
  if (changed) {
    window.history.replaceState({}, '', url.pathname + (url.search ? url.search : ''));
  }
});

// ── Sidebar ──
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

// ── Page Logic ──────────────────────────────────────────────
const projectsData = <?php echo json_encode($projects_list); ?>;
const pSelect      = document.getElementById('filterProject');
const cSelect      = document.getElementById('filterClient');
const btnAdd       = document.getElementById('btnAddTestPlan');

function updateProjectDropdown(clientId) {
    if (pSelect) {
        pSelect.innerHTML = '<option value="">All Projects</option>';
        const filtered = clientId
            ? projectsData.filter(p => p.client_id == clientId)
            : projectsData;
        filtered.forEach(p => {
            pSelect.innerHTML += `<option value="${p.id}">${p.name}</option>`;
        });
    }
}

function checkFormState() {
    if (btnAdd) btnAdd.disabled = false;
}

if (!<?= $filters_applied ? 'true' : 'false' ?>) {
    if (cSelect && cSelect.value) {
        updateProjectDropdown(cSelect.value);
        const currentFilterProject = "<?= $filter_project ?>";
        if (currentFilterProject && pSelect) pSelect.value = currentFilterProject;
    } else {
        updateProjectDropdown('');
    }
}
checkFormState();

// ── Project field helpers ────────────────────────────────────
function lockProject(id, name) {
    document.getElementById('projectIdHidden').value = id;
    const sel = document.getElementById('pProjectSelect');
    sel.style.display = 'none';
    sel.value = '';
    document.getElementById('projectLockedDisplay').style.display = 'block';
    document.getElementById('projectLockedName').textContent = name;
}

function unlockProject() {
    document.getElementById('projectIdHidden').value = '';
    const sel = document.getElementById('pProjectSelect');
    sel.style.display = '';
    sel.innerHTML = '<option value="">Select Project</option>';
    projectsData.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.name;
        sel.appendChild(opt);
    });
    document.getElementById('projectLockedDisplay').style.display = 'none';
    sel.onchange = function() {
        document.getElementById('projectIdHidden').value = this.value;
    };
}

// ── Modal helpers ────────────────────────────────────────────
function openAddModal() {
    document.getElementById('modalTitle').innerText  = 'Add Test Plan';
    document.getElementById('planAction').value      = 'add';
    document.getElementById('planForm').reset();
    document.getElementById('eId').value             = '';
    document.getElementById('customTechList').innerHTML = '';

    const activeProjectId   = "<?= $filter_project ?>";
    const activeProjectName = <?php
        $apName = '';
        if ($filter_project) {
            foreach ($projects_list as $p) {
                if ($p['id'] == $filter_project) { $apName = $p['name']; break; }
            }
        }
        echo json_encode($apName);
    ?>;

    if (activeProjectId && activeProjectName) {
        lockProject(activeProjectId, activeProjectName);
    } else {
        unlockProject();
    }

    document.getElementById('modal-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    const mb = document.querySelector('#modal-overlay .modal-body');
    if (mb) mb.scrollTop = 0;
}

function openEditModal(data) {
    document.getElementById('modalTitle').innerText = 'Edit Test Plan';
    document.getElementById('planAction').value     = 'edit';
    document.getElementById('eId').value            = data.id;
    document.getElementById('eTitle').value         = data.title         || '';
    document.getElementById('eObj').value           = data.objective     || '';
    document.getElementById('eScope').value         = data.scope         || '';
    document.getElementById('eRoles').value         = data.roles_covered || '';
    document.getElementById('ePLead').value         = data.project_lead_id || '';
    document.getElementById('eTLead').value         = data.test_lead_id    || '';

    const proj     = projectsData.find(p => p.id == data.project_id);
    const projName = proj ? proj.name : ('Project #' + data.project_id);
    lockProject(data.project_id, projName);

    const tTypes = data.testing_types || [];
    document.querySelectorAll('input[name="testing_types[]"]').forEach(el => {
        el.checked = tTypes.includes(el.value);
    });

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

    document.getElementById('modal-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    const mb = document.querySelector('#modal-overlay .modal-body');
    if (mb) mb.scrollTop = 0;
}

function closeModal() {
    document.getElementById('modal-overlay').classList.remove('open');
    document.body.style.overflow = '';
}

function addCustomTechElement(val) {
    if (!val) return;
    const existing = document.querySelectorAll('#customTechList input[type="hidden"]');
    for (const inp of existing) {
        if (inp.value === val) return;
    }
    const list = document.getElementById('customTechList');
    const div  = document.createElement('div');
    div.style.cssText = 'background:linear-gradient(135deg,#eef4fd,#d4e4f7); padding:4px 10px; border-radius:8px; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:4px; border:1px solid #bdd4f0; color:#1565c0;';
    div.innerHTML = `${val}<span style="color:var(--red);cursor:pointer;margin-left:4px;" onclick="this.parentElement.remove()">×</span><input type="hidden" name="technologies[]" value="${val}">`;
    list.appendChild(div);
}

document.getElementById('techSelect').addEventListener('change', function () {
    if (this.value) {
        addCustomTechElement(this.value);
        this.value = '';
    }
});

// ── Delete confirm ──────────────────────────────────────────
let delId = null;
function confirmDelete(id) {
    delId = id;
    document.getElementById('confirmOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeConfirm() {
    document.getElementById('confirmOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
function doDelete() {
    if (delId) {
      fetch('?delete='+delId+'&ajax=1',{method:'GET'})
        .then(function(r){ return r.json(); })
        .then(function(d){
          if(d.success){
            var row = document.querySelector('tr:has(button[onclick*="'+delId+'"])');
            if(row) row.remove();
            closeConfirm();
            showToast('Test Plan delete ho gaya.','success');
          } else { showToast('Delete nahi hua.','error'); }
        }).catch(function(){ showToast('Network error.','error'); });
    }
}
document.getElementById('confirmOverlay').addEventListener('click',function(e){ if(e.target===this) closeConfirm(); });

// ── Import modal ────────────────────────────────────────────
function openImportModal() {
    document.getElementById('importModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeImportModal() {
    document.getElementById('importModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('importModal').addEventListener('click',function(e){ if(e.target===this) closeImportModal(); });
document.getElementById('modal-overlay').addEventListener('click',function(e){ if(e.target===this) closeModal(); });

// ── CSV drag & drop ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
  var zone  = document.getElementById('dropZone');
  var input = document.getElementById('csvFileInput');
  if(!zone) return;
  zone.addEventListener('dragover', function(e){
    e.preventDefault();
    zone.style.borderColor = 'var(--purple-mid)';
    zone.style.background  = '#f3e8ff';
  });
  zone.addEventListener('dragleave', function(){
    zone.style.borderColor = 'var(--border)';
    zone.style.background  = '#fafcff';
  });
  zone.addEventListener('drop', function(e){
    e.preventDefault();
    var file = e.dataTransfer.files[0];
    if(file && file.name.endsWith('.csv')){
      var dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
      onFileSelect(input);
    } else {
      showToast('Sirf .csv file allowed hai.', 'error');
      zone.style.borderColor = 'var(--border)';
      zone.style.background  = '#fafcff';
    }
  });
});
function onFileSelect(input){
  var file  = input.files[0];
  var label = document.getElementById('dropLabel');
  var zone  = document.getElementById('dropZone');
  if(file){
    label.textContent = '✓ ' + file.name;
    label.style.color = 'var(--green)';
    zone.style.borderColor = 'var(--green)';
    zone.style.background  = '#f0fdf4';
  } else {
    label.textContent = 'Click karein ya CSV file drag karein';
    label.style.color = 'var(--text-muted)';
    zone.style.borderColor = 'var(--border)';
    zone.style.background  = '#fafcff';
  }
}

// ── CSV Template ────────────────────────────────────────────
function downloadTemplate() {
    const csv  = 'project_name,title,objective,scope,testing_types,technologies,project_lead_id,test_lead_id,roles_covered\nSample Project,Sample Plan,Testing objective,In scope,"Manual testing,Functional testing","React,MySQL",0,0,Admin';
    const blob = new Blob([csv], { type: 'text/csv' });
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = 'test_plans_template.csv';
    a.click();
}

// ── Toast helper ────────────────────────────────────────────
function showToast(msg, type){
  var old = document.getElementById('liveToast');
  if(old) old.remove();
  var t = document.createElement('div');
  t.className = 'toast '+type; t.id = 'liveToast';
  var icon = type === 'success'
    ? '<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>'
    : '<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
  t.innerHTML = icon + msg;
  document.body.appendChild(t);
  setTimeout(function(){ t.classList.add('toast-hide'); setTimeout(function(){ t.remove(); },300); },4000);
}
</script>
</body>
</html>
