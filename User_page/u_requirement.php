<?php
// ── page/client/requirements.php ─────────────────────────────
session_start();

include '../config/db.php';

 $msg      = '';
 $msg_type = '';

if (isset($_GET['added']))   { $msg = 'Requirement added successfully!';   $msg_type = 'success'; }
elseif (isset($_GET['updated'])) { $msg = 'Requirement updated!';          $msg_type = 'success'; }
elseif (isset($_GET['deleted']))  { $msg = 'Requirement deleted.';          $msg_type = 'success'; }
elseif (isset($_GET['imported'])) {
    $imp  = (int)$_GET['imported'];
    $errs = (int)($_GET['imp_err'] ?? 0);
    $msg  = "CSV se $imp requirement(s) import ho gaye!";
    if ($errs) $msg .= " ($errs rows skip ho gaye — title missing ya project not found tha.)";
    $msg_type = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    $import_project_id = (int)($_POST['import_project_id'] ?? 0);

    if (!$import_project_id) {
        $msg = 'Import ke liye pehle project select karein.'; $msg_type = 'error';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'CSV file upload nahi hui. Dobara try karein.'; $msg_type = 'error';
    } else {
        $file     = $_FILES['csv_file']['tmp_name'];
        $handle   = fopen($file, 'r');
        $header   = fgetcsv($handle);
        $imported = 0;
        $errors   = 0;
        $header = array_map(fn($h) => strtolower(trim($h)), $header);
        $allowed_priority = ['low','medium','high','critical'];
        $allowed_status   = ['open','in_progress','completed','closed'];
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
                } else {
                    $errors++; continue;
                }
            }
            $title       = trim($data['title']              ?? '');
            $description = trim($data['description']        ?? '');
            $priority    = strtolower(trim($data['priority'] ?? 'medium'));
            $status      = strtolower(trim($data['status']   ?? 'open'));
            $rep_date    = trim($data['reported_date']       ?? date('Y-m-d'));
            $exp_del     = trim($data['expected_delivery']   ?? '') ?: null;
            $act_del     = trim($data['actual_delivery']     ?? '') ?: null;
            $is_dev      = (int)($data['is_developed']  ?? 0);
            $is_test     = (int)($data['is_tested']      ?? 0);
            $is_del      = (int)($data['is_delivered']   ?? 0);
            $uat         = (int)($data['uat_done']        ?? 0);
            $bug_uat     = (int)($data['bug_after_uat']   ?? 0);
            $bug_fixed   = (int)($data['bug_fixed']       ?? 0);
            if (!$title) { $errors++; continue; }
            if (!in_array($priority, $allowed_priority)) $priority = 'medium';
            if (!in_array($status,   $allowed_status))   $status   = 'open';
            foreach ([&$rep_date, &$exp_del, &$act_del] as &$d) {
                if ($d && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $ts = strtotime($d);
                    $d  = $ts ? date('Y-m-d', $ts) : null;
                }
            }
            unset($d);
            $stmt = $conn->prepare(
                "INSERT INTO requirements
                 (project_id, title, description, priority, status, reported_date,
                  expected_delivery, actual_delivery,
                  is_developed, is_tested, is_delivered, uat_done, bug_after_uat, bug_fixed)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('isssssssiiiii' . 'i',
                $pid_to_use, $title, $description, $priority, $status,
                $rep_date, $exp_del, $act_del,
                $is_dev, $is_test, $is_del, $uat, $bug_uat, $bug_fixed
            );
            if ($stmt->execute()) $imported++;
            else $errors++;
            $stmt->close();
        }
        fclose($handle);
        $redirect = strtok($_SERVER['REQUEST_URI'], '?') . "?imported=$imported";
        if ($errors) $redirect .= "&imp_err=$errors";
        header("Location: $redirect");
        exit;
    }
}

 $conn->query("
    CREATE TABLE IF NOT EXISTS requirements (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        project_id      INT NOT NULL,
        title           VARCHAR(255) NOT NULL,
        description     TEXT,
        priority        ENUM('low','medium','high','critical') DEFAULT 'medium',
        reported_date   DATE,
        expected_delivery DATE,
        actual_delivery   DATE,
        is_developed    TINYINT(1) DEFAULT 0,
        is_tested       TINYINT(1) DEFAULT 0,
        is_delivered    TINYINT(1) DEFAULT 0,
        uat_done        TINYINT(1) DEFAULT 0,
        bug_after_uat   TINYINT(1) DEFAULT 0,
        bug_fixed       TINYINT(1) DEFAULT 0,
        status          ENUM('open','in_progress','completed','closed') DEFAULT 'open',
        createdAt       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM requirements WHERE id = ?");
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $project_id  = (int)($_POST['project_id'] ?? 0);
    $title       = trim($_POST['title']              ?? '');
    $description = trim($_POST['description']        ?? '');
    $priority    = trim($_POST['priority']           ?? 'medium');
    $rep_date    = $_POST['reported_date']           ?? date('Y-m-d');
    $exp_del     = $_POST['expected_delivery']       ?? null;
    $act_del     = $_POST['actual_delivery']         ?? null;
    $is_dev      = isset($_POST['is_developed'])  ? 1 : 0;
    $is_tested   = isset($_POST['is_tested'])     ? 1 : 0;
    $is_del      = isset($_POST['is_delivered'])  ? 1 : 0;
    $uat         = isset($_POST['uat_done'])       ? 1 : 0;
    $bug_uat     = isset($_POST['bug_after_uat'])  ? 1 : 0;
    $bug_fixed   = isset($_POST['bug_fixed'])      ? 1 : 0;
    $exp_del = $exp_del ?: null;
    $act_del = $act_del ?: null;
    if (!$project_id || !$title || !$description || !$priority) {
        $msg = 'Project, Title, Description aur Priority required hain.'; $msg_type = 'error';
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO requirements
             (project_id, title, description, priority, reported_date, expected_delivery, actual_delivery,
              is_developed, is_tested, is_delivered, uat_done, bug_after_uat, bug_fixed)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('issssssiiiiii',
            $project_id, $title, $description, $priority,
            $rep_date, $exp_del, $act_del,
            $is_dev, $is_tested, $is_del, $uat, $bug_uat, $bug_fixed
        );
        if ($stmt->execute()) {
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?added=1');
            exit;
        } else {
            $msg = 'Error: ' . htmlspecialchars($stmt->error); $msg_type = 'error';
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $edit_id     = (int)($_POST['edit_id']           ?? 0);
    $project_id  = (int)($_POST['project_id']        ?? 0);
    $title       = trim($_POST['title']              ?? '');
    $description = trim($_POST['description']        ?? '');
    $priority    = trim($_POST['priority']           ?? 'medium');
    $status      = trim($_POST['status']             ?? 'open');
    $rep_date    = $_POST['reported_date']           ?? date('Y-m-d');
    $exp_del     = $_POST['expected_delivery']       ?? null;
    $act_del     = $_POST['actual_delivery']         ?? null;
    $is_dev      = isset($_POST['is_developed'])  ? 1 : 0;
    $is_tested   = isset($_POST['is_tested'])     ? 1 : 0;
    $is_del      = isset($_POST['is_delivered'])  ? 1 : 0;
    $uat         = isset($_POST['uat_done'])       ? 1 : 0;
    $bug_uat     = isset($_POST['bug_after_uat'])  ? 1 : 0;
    $bug_fixed   = isset($_POST['bug_fixed'])      ? 1 : 0;
    $exp_del = $exp_del ?: null;
    $act_del = $act_del ?: null;
    if (!$project_id || !$title || !$priority) {
        $msg = 'Project, Title aur Priority required hain.'; $msg_type = 'error';
    } else {
        $stmt = $conn->prepare(
            "UPDATE requirements SET
             project_id=?, title=?, description=?, priority=?, status=?,
             reported_date=?, expected_delivery=?, actual_delivery=?,
             is_developed=?, is_tested=?, is_delivered=?, uat_done=?, bug_after_uat=?, bug_fixed=?
             WHERE id=?"
        );
        $stmt->bind_param('isssssssiiiiiii',
            $project_id, $title, $description, $priority, $status,
            $rep_date, $exp_del, $act_del,
            $is_dev, $is_tested, $is_del, $uat, $bug_uat, $bug_fixed,
            $edit_id
        );
        if ($stmt->execute()) {
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?updated=1');
            exit;
        } else {
            $msg = 'Error: ' . htmlspecialchars($stmt->error); $msg_type = 'error';
        }
        $stmt->close();
    }
}

 $projects = [];
 $pr = $conn->query("SELECT id, name FROM projects ORDER BY name ASC");
if ($pr) while ($row = $pr->fetch_assoc()) $projects[] = $row;

 $filter_project  = (int)($_GET['filter_project']  ?? 0);
 $filter_priority = trim($_GET['filter_priority']  ?? '');
 $filter_status   = trim($_GET['filter_status']    ?? '');
 $search          = trim($_GET['search']            ?? '');
 $filters_applied = $filter_project || $filter_priority || $filter_status || $search;

 $requirements  = [];
 $total_reqs    = 0;
 $per_page      = 10;
 $total_pages   = 1;
 $page          = 1;

if ($filters_applied) {
    $where   = [];
    $params  = [];
    $types   = '';
    if ($filter_project) { $where[] = 'r.project_id = ?'; $params[] = $filter_project; $types .= 'i'; }
    if ($filter_priority){ $where[] = 'r.priority = ?';   $params[] = $filter_priority; $types .= 's'; }
    if ($filter_status)  { $where[] = 'r.status = ?';     $params[] = $filter_status;  $types .= 's'; }
    if ($search) {
        $like = "%$search%";
        $where[] = '(r.title LIKE ? OR r.description LIKE ?)';
        $params[] = $like; $params[] = $like; $types .= 'ss';
    }
    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $cstmt = $conn->prepare("SELECT COUNT(*) FROM requirements r $whereStr");
    if ($types) { $cstmt->bind_param($types, ...$params); }
    $cstmt->execute();
    $cstmt->bind_result($total_reqs);
    $cstmt->fetch();
    $cstmt->close();
    $total_pages = max(1, ceil($total_reqs / $per_page));
    $page        = max(1, min((int)($_GET['page'] ?? 1), $total_pages));
    $offset      = ($page - 1) * $per_page;
    $qparams = $params;
    $qtypes  = $types . 'ii';
    $qparams[] = $per_page;
    $qparams[] = $offset;
    $stmt = $conn->prepare(
        "SELECT r.*, p.name AS project_name
         FROM requirements r
         LEFT JOIN projects p ON p.id = r.project_id
         $whereStr
         ORDER BY r.createdAt DESC LIMIT ? OFFSET ?"
    );
    $stmt->bind_param($qtypes, ...$qparams);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    while ($row = $result->fetch_assoc()) $requirements[] = $row;
}

 $edit_req = null;
if (isset($_GET['edit'])) {
    $eid  = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM requirements WHERE id = ?");
    $stmt->bind_param('i', $eid); $stmt->execute();
    $edit_req = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

 $conn->close();

function priority_class(string $p): string {
    return match($p) {
        'low'      => 'badge-low',
        'medium'   => 'badge-medium',
        'high'     => 'badge-high',
        'critical' => 'badge-critical',
        default    => 'badge-medium',
    };
}
function status_class(string $s): string {
    return match($s) {
        'open'        => 'badge-open',
        'in_progress' => 'badge-inprog',
        'completed'   => 'badge-done',
        'closed'      => 'badge-closed',
        default       => 'badge-open',
    };
}
function status_label(string $s): string {
    return match($s) {
        'in_progress' => 'In Progress',
        default       => ucfirst(str_replace('_',' ',$s)),
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TestiFy — Requirements</title>
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
  --orange:     #e67e22;
  --purple:     #8e44ad;
  --sb-w:       240px;
  --nb-h:       60px;
}
body { min-height:100vh; background:var(--bg); font-family:'Nunito',sans-serif; color:var(--text-main); overflow-x:hidden; }

/* ── NAVBAR ── */
.navbar {
  background:var(--white);
  border-bottom:1.5px solid var(--border);
  display:flex; align-items:center; justify-content:space-between;
  padding:0 20px;
  height:var(--nb-h);
  position:fixed; top:0; left:0; right:0;
  z-index:300;
  box-shadow:0 2px 16px rgba(74,144,217,.09);
}
.navbar-logo {
  font-family:'Poppins',sans-serif; font-weight:800; font-size:20px;
  background:linear-gradient(90deg,var(--blue-dark),var(--blue-light));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
  letter-spacing:.5px; cursor:default; flex-shrink:0; text-decoration:none;
  display:flex; align-items:center; gap:10px;
}

/* Hamburger */
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

.navbar-right { display:flex; align-items:center; gap:10px; flex-shrink:0; }
.navbar-username { font-size:13px; font-weight:600; color:var(--text-muted); white-space:nowrap; display:flex; align-items:center; gap:8px; }
.nb-role { font-size:11px; font-weight:700; color:#1565c0; background:#e3f2fd; padding:2px 8px; border-radius:6px; text-transform:capitalize; letter-spacing:.5px; white-space:nowrap; }
.btn-logout { padding:7px 14px; border-radius:8px; border:1.5px solid var(--blue-mid); background:transparent; color:var(--blue-mid); font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; transition:.2s; white-space:nowrap; text-decoration:none; display:inline-flex; align-items:center; }
.btn-logout:hover { background:var(--blue-mid); color:#fff; }

/* ── SIDEBAR ── */
.sidebar {
  position:fixed; top:var(--nb-h); left:0; bottom:0;
  width:var(--sb-w); background:var(--white);
  border-right:1.5px solid var(--border);
  z-index:250; overflow-y:auto; overflow-x:hidden;
  transition:transform .28s cubic-bezier(.4,0,.2,1);
  box-shadow:2px 0 20px rgba(74,144,217,.06);
  padding-bottom:24px;
}
.sidebar-overlay {
  display:none; position:fixed; inset:0; top:var(--nb-h);
  background:rgba(30,45,80,.4); z-index:240; backdrop-filter:blur(2px);
}
.sb-home {
  display:flex; align-items:center; gap:10px;
  padding:12px 16px; margin:8px 8px 2px;
  border-radius:10px; text-decoration:none;
  color:var(--text-main); font-size:13.5px; font-weight:700;
  transition:.15s; background:#f8faff; border:1px solid var(--border);
}
.sb-home:hover { background:#eef4fd; color:var(--blue-dark); }
.sb-home svg { width:16px; height:16px; opacity:.7; }
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

/* ── PAGE WRAP ── */
.page-wrap {
  margin-left:var(--sb-w);
  margin-top:var(--nb-h);
  min-height:calc(100vh - var(--nb-h));
  transition:margin-left .28s cubic-bezier(.4,0,.2,1);
}

/* ── MAIN ── */
.main { max-width:1300px; margin:0 auto; padding:28px 24px 60px; }
.page-title { display:flex; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
.page-title h1 { font-family:'Poppins',sans-serif; font-weight:800; font-size:26px; color:var(--text-main); letter-spacing:-.5px; }
.badge-page { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; font-family:'Poppins',sans-serif; font-weight:700; font-size:10px; letter-spacing:2px; text-transform:uppercase; padding:5px 12px; border-radius:6px; }

/* ── TOOLBAR ── */
.toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; flex-wrap:wrap; }
.toolbar-left { display:flex; align-items:center; gap:8px; flex-wrap:wrap; flex:1; min-width:0; }
.search-wrap { position:relative; flex:1; max-width:320px; }
.search-wrap svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:var(--text-muted); pointer-events:none; }
.search-input { width:100%; padding:9px 12px 9px 34px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; height:38px; }
.search-input:focus { border-color:var(--blue-mid); box-shadow:0 0 0 3px rgba(74,144,217,.12); }
.btn-filter { height:38px; padding:0 16px; border-radius:10px; border:none; background:linear-gradient(90deg,#8e44ad,#c0392b); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; flex-shrink:0; transition:opacity .2s; }
.btn-filter:hover { opacity:.9; }
.btn-clear { display:inline-flex; align-items:center; gap:4px; padding:0 12px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13px; font-weight:700; color:var(--text-muted); text-decoration:none; height:38px; flex-shrink:0; transition:background .15s; }
.btn-clear:hover { background:#f0f5fd; }
.toolbar-right { display:flex; align-items:center; gap:8px; flex-shrink:0; }
.btn-template { height:36px; padding:0 14px; border-radius:10px; border:none; background:var(--green); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:opacity .2s; }
.btn-template:hover { opacity:.9; }
.btn-import { height:36px; padding:0 14px; border-radius:10px; border:none; background:var(--orange); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:opacity .2s; }
.btn-import:hover { opacity:.9; }
.btn-add { height:36px; padding:0 14px; border-radius:10px; border:none; background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:opacity .2s; box-shadow:0 4px 14px rgba(74,144,217,.3); }
.btn-add:hover { opacity:.92; }

/* TABLE */
.table-card { background:var(--white); border-radius:18px; border:1.5px solid var(--border); box-shadow:0 4px 20px rgba(74,144,217,.07); overflow:hidden; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:1100px; }
thead th { background:#f0f5fd; padding:13px 14px; text-align:left; font-size:11.5px; font-weight:700; color:var(--text-muted); letter-spacing:.8px; text-transform:uppercase; border-bottom:1.5px solid var(--border); white-space:nowrap; }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:#f5f8fe; }
tbody td { padding:12px 14px; font-size:13px; vertical-align:middle; }
.req-title { font-weight:700; color:var(--text-main); }
.req-desc { font-size:11.5px; color:var(--text-muted); margin-top:2px; max-width:220px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }

/* BADGES */
.badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:700; white-space:nowrap; }
.badge-low      { background:#e8f0fe; color:#3a7bd5; }
.badge-medium   { background:#fef9e7; color:#b7770d; }
.badge-high     { background:#fce8e8; color:#c0392b; }
.badge-critical { background:#f5e6ff; color:#7d3c98; }
.badge-open     { background:#e8f0fe; color:#1a5276; }
.badge-inprog   { background:#fef5ec; color:#ca6f1e; }
.badge-done     { background:#e8f8f0; color:#1e8449; }
.badge-closed   { background:#f0f0f0; color:#5d6d7e; }

/* FLAGS */
.flags { display:flex; flex-wrap:wrap; gap:4px; }
.flag { display:inline-flex; align-items:center; gap:3px; font-size:10.5px; font-weight:700; padding:2px 7px; border-radius:5px; white-space:nowrap; }
.flag.yes { background:#e8f8f0; color:#1e8449; }
.flag.no  { background:#f0f0f0; color:#95a5a6; }

.action-btns { display:flex; gap:6px; }
.btn-icon { width:32px; height:32px; border-radius:8px; border:1.5px solid var(--border); background:var(--white); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .15s,border-color .15s; text-decoration:none; }
.btn-icon svg { width:14px; height:14px; }
.btn-icon.edit { color:var(--blue-mid); }
.btn-icon.edit:hover { background:#eef4fd; border-color:var(--blue-mid); }
.btn-icon.del { color:var(--red); }
.btn-icon.del:hover { background:#fde8e8; border-color:var(--red); }

/* EMPTY */
.empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); }
.empty-state svg { width:52px; height:52px; margin-bottom:14px; color:#c5d5e8; }
.empty-state p { font-size:14px; font-weight:700; margin-bottom:4px; }
.empty-state small { font-size:12.5px; }

/* PAGINATION */
.table-footer { padding:13px 20px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.footer-count { font-size:12.5px; color:var(--text-muted); font-weight:600; }
.pagination { display:flex; align-items:center; gap:4px; }
.pg-btn { min-width:32px; height:32px; padding:0 8px; border-radius:8px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13px; font-weight:700; color:var(--text-muted); cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; transition:background .15s,color .15s,border-color .15s; }
.pg-btn:hover { background:#eef4fd; color:var(--blue-dark); border-color:var(--blue-mid); }
.pg-btn.active { background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; border-color:transparent; }
.pg-btn.disabled { opacity:.4; pointer-events:none; }

/* MODALS */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.45); backdrop-filter:blur(3px); z-index:500; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.open { display:flex; }
.modal { background:var(--white); border-radius:20px; width:100%; max-width:580px; box-shadow:0 24px 64px rgba(30,45,80,.22); max-height:90vh; display:flex; flex-direction:column; overflow:hidden; }
.modal > form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:22px 24px 18px; border-bottom:1.5px solid var(--border); flex-shrink:0; }
.modal-header h2 { font-family:'Poppins',sans-serif; font-weight:800; font-size:18px; }
.modal-close { width:32px; height:32px; border-radius:8px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-muted); transition:background .15s,color .15s; }
.modal-close:hover { background:#fde8e8; color:var(--red); }
.modal-body { padding:22px 24px; overflow-y:auto; flex:1; min-height:0; }
.modal-footer { padding:16px 24px 20px; border-top:1.5px solid var(--border); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; background:var(--white); }
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:700; margin-bottom:6px; }
.form-group label span { color:var(--red); }
.form-control { width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; background:var(--white); }
.form-control:focus { border-color:var(--blue-mid); box-shadow:0 0 0 3px rgba(74,144,217,.12); }
.form-control::placeholder { color:#b0bdd6; }
textarea.form-control { resize:vertical; min-height:90px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.check-section { background:#f8faff; border:1.5px solid var(--border); border-radius:12px; padding:16px; margin-bottom:16px; }
.check-section-title { font-size:12px; font-weight:700; color:var(--text-muted); letter-spacing:.8px; text-transform:uppercase; margin-bottom:12px; }
.check-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.check-item { display:flex; align-items:center; gap:8px; cursor:pointer; }
.check-item input[type="checkbox"] { width:16px; height:16px; accent-color:var(--blue-dark); cursor:pointer; border-radius:4px; flex-shrink:0; }
.check-item span { font-size:13px; font-weight:600; color:var(--text-main); }
.btn-save { padding:10px 26px; border-radius:10px; border:none; background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; box-shadow:0 4px 14px rgba(74,144,217,.28); transition:opacity .2s; }
.btn-save:hover { opacity:.92; }
.btn-cancel { padding:10px 22px; border-radius:10px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:background .15s; }
.btn-cancel:hover { background:#f0f5fd; }

/* CONFIRM */
.confirm-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.4); backdrop-filter:blur(2px); z-index:600; align-items:center; justify-content:center; padding:16px; }
.confirm-overlay.open { display:flex; }
.confirm-box { background:var(--white); border-radius:16px; padding:28px; max-width:360px; width:100%; box-shadow:0 20px 56px rgba(30,45,80,.2); text-align:center; }
.confirm-box h3 { font-family:'Poppins',sans-serif; font-weight:800; font-size:16px; margin-bottom:8px; }
.confirm-box p { font-size:13.5px; color:var(--text-muted); margin-bottom:20px; line-height:1.6; }
.confirm-btns { display:flex; gap:10px; justify-content:center; }
.btn-confirm-del { padding:9px 22px; border-radius:9px; border:none; background:var(--red); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; transition:opacity .2s; }
.btn-confirm-del:hover { opacity:.88; }
.btn-confirm-cancel { padding:9px 22px; border-radius:9px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; transition:background .15s; }
.btn-confirm-cancel:hover { background:#f0f5fd; }

/* TOAST */
.toast { position:fixed; bottom:28px; right:28px; z-index:999; display:flex; align-items:flex-start; gap:10px; padding:14px 18px; border-radius:12px; font-size:13px; font-weight:700; box-shadow:0 8px 28px rgba(30,45,80,.18); max-width:380px; line-height:1.5; }
.toast.success { background:#e8f8f0; color:#1e8449; border:1.5px solid #a9dfbf; }
.toast.error   { background:#fde8e8; color:#c0392b; border:1.5px solid #f1948a; }
.toast svg { width:17px; height:17px; flex-shrink:0; margin-top:1px; }
.toast-hide { opacity:0; transition:opacity .3s; }

/* FILTER PANEL */
.filter-panel { background:var(--white); border:1.5px solid var(--border); border-radius:14px; padding:18px 20px; margin-bottom:16px; display:none; }
.filter-panel.open { display:block; }
.filter-panel-inner { display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; }
.filter-panel .form-group { margin-bottom:0; flex:1; min-width:160px; }
.filter-panel label { font-size:12px; }
.filter-panel .form-control { height:38px; padding:0 12px; }
.btn-apply { height:38px; padding:0 20px; border-radius:10px; border:none; background:linear-gradient(90deg,#8e44ad,#c0392b); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; flex-shrink:0; }

/* ── RESPONSIVE ── */
@media(max-width:768px){
  :root { --sb-w:260px; }
  .ham { display:flex; }
  .sidebar { transform:translateX(-100%); }
  .sidebar.open { transform:translateX(0); }
  .sidebar-overlay.open { display:block; }
  .page-wrap { margin-left:0; }
  .navbar-username span:not(.nb-role) { display:none; }
  .nb-role { display:none; }
  .main { padding:16px 14px 48px; }
  .page-title h1 { font-size:20px; }
  .form-row, .check-grid { grid-template-columns:1fr; }
  .toolbar { flex-direction:column; align-items:stretch; gap:12px; }
  .toolbar-left { width:100%; display:flex; align-items:center; gap:8px; }
  .search-wrap { flex:1; max-width:none; min-width:0; }
  .btn-filter { flex-shrink:0; }
  .btn-clear { display:none; }
  .toolbar-right { width:100%; display:flex; justify-content:space-between; align-items:center; gap:8px; }
  .btn-add, .btn-import, .btn-template { flex:1; justify-content:center; }
  .toast { right:14px; bottom:14px; max-width:calc(100vw - 28px); }
}
@media(max-width:480px){
  .navbar { padding:0 14px; height:54px; }
  :root { --nb-h:54px; }
  .main { padding:14px 12px 40px; }
  .page-title h1 { font-size:18px; }
}
</style>
</head>
<body>

<!-- ══════════ NAVBAR ══════════ -->
<nav class="navbar">
  <div style="display:flex;align-items:center;gap:10px;">
    <button class="ham" id="hamBtn" onclick="toggleSidebar()" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
     <span class="navbar-logo">TestiFy</span>
  </div>
  <div class="navbar-right">
    <div class="navbar-username">
      <span><?php
        $n = htmlspecialchars($_SESSION['name'] ?? $_SESSION['user'] ?? 'Guest');
        $r = htmlspecialchars(ucfirst($_SESSION['role'] ?? ''));
        echo $n;
      ?></span>
      <?php if ($r): ?><span class="nb-role"><?= $r ?></span><?php endif; ?>
    </div>
    <a href="../logout.php" class="btn-logout">Logout</a>
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
  <a href="../page/project.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    Projects
  </a>
  <a href="../page/requirement.php" class="sb-link active">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    Requirements
  </a>
  <a href="../page/test_plans.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    Test Plans
  </a>
  <a href="../page/test_cases.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    Test Cases
  <a href="../page/project_reports.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
  </a>
</aside>

<!-- ══════════ PAGE WRAP ══════════ -->
<div class="page-wrap" id="pageWrap">
<div class="main">
  <div class="page-title">
    <h1>Project Requirements</h1>
    <span class="badge-page">Management</span>
  </div>

  <!-- TOOLBAR -->
  <div class="toolbar">
    <div class="toolbar-left">
      <form method="GET" action="" style="display:contents;" id="searchForm">
        <?php if ($filter_project):  ?><input type="hidden" name="filter_project"  value="<?= $filter_project ?>"/><?php endif; ?>
        <?php if ($filter_priority): ?><input type="hidden" name="filter_priority" value="<?= htmlspecialchars($filter_priority) ?>"/><?php endif; ?>
        <?php if ($filter_status):   ?><input type="hidden" name="filter_status"   value="<?= htmlspecialchars($filter_status) ?>"/><?php endif; ?>
        <div class="search-wrap">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="search" class="search-input"
                 placeholder="Title, Description"
                 value="<?= htmlspecialchars($search) ?>"
                 oninput="autoSearch(this)" autocomplete="off"/>
        </div>
      </form>
      <button class="btn-filter" onclick="toggleFilter()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
        Filter
      </button>
      <?php if ($filters_applied): ?>
        <a href="?" class="btn-clear">
          <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Clear
        </a>
      <?php endif; ?>
    </div>
    <div class="toolbar-right">
      <button class="btn-template" onclick="downloadTemplate()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Template
      </button>
      <button class="btn-import" onclick="openImportModal()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Import
      </button>
      <button class="btn-add" onclick="openAddModal()">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Requirement
      </button>
    </div>
  </div>

  <!-- FILTER PANEL -->
  <div class="filter-panel <?= $filters_applied ? 'open' : '' ?>" id="filterPanel">
    <form method="GET" action="">
      <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"/><?php endif; ?>
      <div class="filter-panel-inner">
        <div class="form-group">
          <label>Project</label>
          <select name="filter_project" class="form-control">
            <option value="">All Projects</option>
            <?php foreach ($projects as $proj): ?>
              <option value="<?= $proj['id'] ?>" <?= $filter_project == $proj['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($proj['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Priority</label>
          <select name="filter_priority" class="form-control">
            <option value="">All Priorities</option>
            <option value="low"      <?= $filter_priority === 'low'      ? 'selected' : '' ?>>Low</option>
            <option value="medium"   <?= $filter_priority === 'medium'   ? 'selected' : '' ?>>Medium</option>
            <option value="high"     <?= $filter_priority === 'high'     ? 'selected' : '' ?>>High</option>
            <option value="critical" <?= $filter_priority === 'critical' ? 'selected' : '' ?>>Critical</option>
          </select>
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="filter_status" class="form-control">
            <option value="">All Statuses</option>
            <option value="open"        <?= $filter_status === 'open'        ? 'selected' : '' ?>>Open</option>
            <option value="in_progress" <?= $filter_status === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
            <option value="completed"   <?= $filter_status === 'completed'   ? 'selected' : '' ?>>Completed</option>
            <option value="closed"      <?= $filter_status === 'closed'      ? 'selected' : '' ?>>Closed</option>
          </select>
        </div>
        <button type="submit" class="btn-apply">Apply Filters</button>
      </div>
    </form>
  </div>

  <!-- TABLE -->
  <div class="table-card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>S.No.</th>
            <th>Project</th>
            <th>Title / Description</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Reported</th>
            <th>Exp. Delivery</th>
            <th>Act. Delivery</th>
            <th>Flags</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="reqTbody">
          <?php if (!$filters_applied): ?>
          <tr>
            <td colspan="10">
              <div class="empty-state">
                <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                <p>Apply Filters to View Requirements</p>
                <small>Click "Filter" button to apply filters and display project requirements.</small>
              </div>
            </td>
          </tr>
          <?php elseif (empty($requirements)): ?>
          <tr>
            <td colspan="10">
              <div class="empty-state">
                <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                <p>No Requirements Found</p>
                <small>Try changing your filters.</small>
              </div>
            </td>
          </tr>
          <?php else: ?>
            <?php
            $start_num = ($page - 1) * $per_page + 1;
            foreach ($requirements as $i => $r):
            ?>
            <tr id="row-<?= $r['id'] ?>">
              <td><?= $start_num + $i ?></td>
              <td style="font-weight:700;white-space:nowrap;"><?= htmlspecialchars($r['project_name'] ?? '—') ?></td>
              <td>
                <div class="req-title"><?= htmlspecialchars($r['title']) ?></div>
                <?php if (!empty($r['description'])): ?>
                  <div class="req-desc"><?= htmlspecialchars($r['description']) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= priority_class($r['priority']) ?>"><?= ucfirst($r['priority']) ?></span></td>
              <td><span class="badge <?= status_class($r['status']) ?>"><?= status_label($r['status']) ?></span></td>
              <td style="font-size:12px;white-space:nowrap;"><?= $r['reported_date'] ? date('d M Y', strtotime($r['reported_date'])) : '—' ?></td>
              <td style="font-size:12px;white-space:nowrap;"><?= $r['expected_delivery'] ? date('d M Y', strtotime($r['expected_delivery'])) : '—' ?></td>
              <td style="font-size:12px;white-space:nowrap;"><?= $r['actual_delivery']   ? date('d M Y', strtotime($r['actual_delivery']))   : '—' ?></td>
              <td>
                <div class="flags">
                  <span class="flag <?= $r['is_developed']  ? 'yes' : 'no' ?>">Dev</span>
                  <span class="flag <?= $r['is_tested']     ? 'yes' : 'no' ?>">Test</span>
                  <span class="flag <?= $r['is_delivered']  ? 'yes' : 'no' ?>">Del</span>
                  <span class="flag <?= $r['uat_done']      ? 'yes' : 'no' ?>">UAT</span>
                  <span class="flag <?= $r['bug_after_uat'] ? 'yes' : 'no' ?>">Bug</span>
                  <span class="flag <?= $r['bug_fixed']     ? 'yes' : 'no' ?>">Fixed</span>
                </div>
              </td>
              <td>
                <div class="action-btns">
                  <button class="btn-icon edit" title="Edit"
                    onclick='openEditModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'>
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <button class="btn-icon del" title="Delete"
                    onclick="openConfirm(<?= $r['id'] ?>, '<?= addslashes(htmlspecialchars($r['title'])) ?>')">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="table-footer">
      <span class="footer-count">
        <?php if ($filters_applied): ?>
          Total <strong><?= $total_reqs ?></strong> requirement<?= $total_reqs !== 1 ? 's' : '' ?>
          <?php if ($total_pages > 1): ?>&nbsp;·&nbsp; Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong><?php endif; ?>
        <?php else: ?>
          &mdash;
        <?php endif; ?>
      </span>
      <?php if ($filters_applied && $total_pages > 1):
        $qs = http_build_query(array_filter([
          'filter_project'  => $filter_project  ?: '',
          'filter_priority' => $filter_priority,
          'filter_status'   => $filter_status,
          'search'          => $search,
        ]));
      ?>
      <div class="pagination">
        <a href="?page=<?= max(1,$page-1) ?>&<?= $qs ?>" class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>">
          <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="width:13px;height:13px;"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
          <?php if ($total_pages > 7 && abs($p - $page) > 2 && $p !== 1 && $p !== $total_pages): ?>
            <?php if ($p === 2 || $p === $total_pages - 1): ?><span style="padding:0 4px;color:var(--text-muted);">…</span><?php endif; ?>
          <?php else: ?>
            <a href="?page=<?= $p ?>&<?= $qs ?>" class="pg-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <a href="?page=<?= min($total_pages,$page+1) ?>&<?= $qs ?>" class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
          <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="width:13px;height:13px;"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</div><!-- end .page-wrap -->

<!-- ═══════════════ ADD MODAL ═══════════════ -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Add Requirement</h2>
      <button class="modal-close" onclick="closeModal('addModal')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" id="addForm" onsubmit="return validateAddForm()">
      <input type="hidden" name="action" value="add"/>
      <div class="modal-body">
        <div class="form-group">
          <label>Project <span>*</span></label>
          <select name="project_id" class="form-control" required>
            <option value="">Select Project</option>
            <?php foreach ($projects as $proj): ?>
              <option value="<?= $proj['id'] ?>"><?= htmlspecialchars($proj['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Requirement Title <span>*</span></label>
          <input type="text" name="title" class="form-control" placeholder="Enter requirement title" required/>
        </div>
        <div class="form-group">
          <label>Requirement Description <span>*</span></label>
          <textarea name="description" id="addDesc" class="form-control" placeholder="Describe the requirement..."></textarea>
          <div id="descError" style="color:var(--red);font-size:12px;margin-top:4px;display:none;">Description required hai.</div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Priority <span>*</span></label>
            <select name="priority" class="form-control" required>
              <option value="">Select Priority</option>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>
          <div class="form-group">
            <label>Reported Date <span>*</span></label>
            <input type="date" name="reported_date" class="form-control" value="<?= date('Y-m-d') ?>" required/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Expected Delivery</label>
            <input type="date" name="expected_delivery" class="form-control"/>
          </div>
          <div class="form-group">
            <label>Actual Delivery</label>
            <input type="date" name="actual_delivery" class="form-control"/>
          </div>
        </div>
        <div class="check-section">
          <div class="check-section-title">Progress Flags</div>
          <div class="check-grid">
            <label class="check-item"><input type="checkbox" name="is_developed"/><span>Is Developed</span></label>
            <label class="check-item"><input type="checkbox" name="is_tested"/><span>Is Tested</span></label>
            <label class="check-item"><input type="checkbox" name="is_delivered"/><span>Is Delivered</span></label>
            <label class="check-item"><input type="checkbox" name="uat_done"/><span>UAT Testing Done</span></label>
            <label class="check-item"><input type="checkbox" name="bug_after_uat"/><span>Bug Raised After UAT</span></label>
            <label class="check-item"><input type="checkbox" name="bug_fixed"/><span>Bug Fixed</span></label>
          </div>
        </div>
      </div>
    </form>
    <div class="modal-footer">
      <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
      <button type="submit" form="addForm" class="btn-save">Save</button>
    </div>
  </div>
</div>

<!-- ═══════════════ EDIT MODAL ═══════════════ -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Edit Requirement</h2>
      <button class="modal-close" onclick="closeModal('editModal')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" id="editForm">
      <input type="hidden" name="action" value="edit"/>
      <input type="hidden" name="edit_id" id="eId"/>
      <div class="modal-body">
        <div class="form-group">
          <label>Project <span>*</span></label>
          <select name="project_id" id="eProjId" class="form-control" required>
            <option value="">Select Project</option>
            <?php foreach ($projects as $proj): ?>
              <option value="<?= $proj['id'] ?>"><?= htmlspecialchars($proj['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Requirement Title <span>*</span></label>
          <input type="text" name="title" id="eTitle" class="form-control" required/>
        </div>
        <div class="form-group">
          <label>Requirement Description</label>
          <textarea name="description" id="eDesc" class="form-control"></textarea>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Priority <span>*</span></label>
            <select name="priority" id="ePriority" class="form-control" required>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status" id="eStatus" class="form-control">
              <option value="open">Open</option>
              <option value="in_progress">In Progress</option>
              <option value="completed">Completed</option>
              <option value="closed">Closed</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Reported Date <span>*</span></label>
            <input type="date" name="reported_date" id="eRepDate" class="form-control" required/>
          </div>
          <div class="form-group">
            <label>Expected Delivery</label>
            <input type="date" name="expected_delivery" id="eExpDel" class="form-control"/>
          </div>
        </div>
        <div class="form-group">
          <label>Actual Delivery</label>
          <input type="date" name="actual_delivery" id="eActDel" class="form-control"/>
        </div>
        <div class="check-section">
          <div class="check-section-title">Progress Flags</div>
          <div class="check-grid">
            <label class="check-item"><input type="checkbox" name="is_developed"  id="eDev"/><span>Is Developed</span></label>
            <label class="check-item"><input type="checkbox" name="is_tested"     id="eTest"/><span>Is Tested</span></label>
            <label class="check-item"><input type="checkbox" name="is_delivered"  id="eDel"/><span>Is Delivered</span></label>
            <label class="check-item"><input type="checkbox" name="uat_done"      id="eUat"/><span>UAT Testing Done</span></label>
            <label class="check-item"><input type="checkbox" name="bug_after_uat" id="eBugUat"/><span>Bug Raised After UAT</span></label>
            <label class="check-item"><input type="checkbox" name="bug_fixed"     id="eBugFixed"/><span>Bug Fixed</span></label>
          </div>
        </div>
      </div>
    </form>
    <div class="modal-footer">
      <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
      <button type="submit" form="editForm" class="btn-save">Update</button>
    </div>
  </div>
</div>

<!-- ═══════════════ IMPORT MODAL ═══════════════ -->
<div class="modal-overlay" id="importModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <h2>Import Requirements</h2>
      <button class="modal-close" onclick="closeModal('importModal')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" enctype="multipart/form-data" id="importForm">
      <input type="hidden" name="action" value="import_csv"/>
      <div class="modal-body">
        <div class="form-group">
          <label>Default Project (Fallback) <span>*</span></label>
          <select name="import_project_id" class="form-control" required>
            <option value="">-- Select Project --</option>
            <?php foreach ($projects as $proj): ?>
              <option value="<?= $proj['id'] ?>"><?= htmlspecialchars($proj['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:5px;">If CSV doesn't contain a project name, this project will be used.</div>
        </div>
        <div class="form-group">
          <label>CSV File Upload Karein <span>*</span></label>
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
        <div style="background:#f8faff;border:1.5px solid var(--border);border-radius:10px;padding:14px 16px;">
          <div style="font-size:11.5px;font-weight:700;color:var(--text-muted);letter-spacing:.8px;text-transform:uppercase;margin-bottom:8px;">CSV Format Guide</div>
          <div style="font-size:12px;color:var(--text-main);line-height:1.8;">
            <strong>Required columns:</strong> project_name, title<br>
            <strong>Optional:</strong> description, priority, status, reported_date, expected_delivery, actual_delivery, is_developed, is_tested, is_delivered, uat_done, bug_after_uat, bug_fixed<br><br>
            <strong>Priority values:</strong> low, medium, high, critical<br>
            <strong>Status values:</strong> open, in_progress, completed, closed<br>
            <strong>Date format:</strong> YYYY-MM-DD &nbsp;|&nbsp; <strong>Flag values:</strong> 0 or 1
          </div>
          <button type="button" onclick="downloadTemplate()"
                  style="margin-top:10px;padding:6px 14px;border-radius:8px;border:1.5px solid var(--blue-mid);background:transparent;color:var(--blue-mid);font-family:'Nunito',sans-serif;font-weight:700;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:13px;height:13px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Sample Template Download Karein
          </button>
        </div>
      </div>
    </form>
    <div class="modal-footer">
      <button type="button" class="btn-cancel" onclick="closeModal('importModal')">Cancel</button>
      <button type="submit" form="importForm" class="btn-save">Import Karein</button>
    </div>
  </div>
</div>

<!-- CONFIRM DELETE -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <h3>Requirement Delete Karein?</h3>
    <p id="confirmMsg">Are you sure?</p>
    <div class="confirm-btns">
      <button class="btn-confirm-del" onclick="confirmDelete()">Haan, Delete Karo</button>
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

<?php if ($edit_req): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ openEditModal(<?= json_encode($edit_req) ?>); });</script>
<?php endif; ?>

<script>
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

// ── Form Validation ──
function validateAddForm(){
  var proj  = document.querySelector('#addModal [name="project_id"]').value;
  var title = document.querySelector('#addModal [name="title"]').value.trim();
  var desc  = document.getElementById('addDesc').value.trim();
  var pri   = document.querySelector('#addModal [name="priority"]').value;
  var err   = document.getElementById('descError');
  if(!proj){   alert('Project select karo.');   return false; }
  if(!title){  alert('Title enter karo.');       return false; }
  if(!desc){
    err.style.display = 'block';
    document.getElementById('addDesc').focus();
    return false;
  } else { err.style.display = 'none'; }
  if(!pri){    alert('Priority select karo.');   return false; }
  return true;
}

// ── Filter Panel ──
function toggleFilter(){ document.getElementById('filterPanel').classList.toggle('open'); }

// ── Auto Search ──
var _st = null;
function autoSearch(inp){
  clearTimeout(_st);
  _st = setTimeout(function(){ document.getElementById('searchForm').submit(); }, 800);
}

// ── Modals ──
function openAddModal(){ document.getElementById('addModal').classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id){ document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
['addModal','editModal','importModal'].forEach(function(id){
  document.getElementById(id).addEventListener('click',function(e){ if(e.target===this) closeModal(id); });
});

function openEditModal(r){
  document.getElementById('eId').value       = r.id;
  document.getElementById('eProjId').value   = r.project_id  || '';
  document.getElementById('eTitle').value    = r.title       || '';
  document.getElementById('eDesc').value     = r.description || '';
  document.getElementById('ePriority').value = r.priority    || 'medium';
  document.getElementById('eStatus').value   = r.status      || 'open';
  document.getElementById('eRepDate').value  = r.reported_date       || '';
  document.getElementById('eExpDel').value   = r.expected_delivery   || '';
  document.getElementById('eActDel').value   = r.actual_delivery     || '';
  document.getElementById('eDev').checked      = r.is_developed  == 1;
  document.getElementById('eTest').checked     = r.is_tested     == 1;
  document.getElementById('eDel').checked      = r.is_delivered  == 1;
  document.getElementById('eUat').checked      = r.uat_done      == 1;
  document.getElementById('eBugUat').checked   = r.bug_after_uat == 1;
  document.getElementById('eBugFixed').checked = r.bug_fixed     == 1;
  document.getElementById('editModal').classList.add('open');
  document.body.style.overflow='hidden';
}

// ── Confirm Delete ──
var _delId = null;
function openConfirm(id, title){
  _delId = id;
  document.getElementById('confirmMsg').textContent = '"' + title + '" ko permanently delete karna chahte hain?';
  document.getElementById('confirmOverlay').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeConfirm(){ document.getElementById('confirmOverlay').classList.remove('open'); document.body.style.overflow=''; _delId=null; }
function confirmDelete(){
  if(!_delId) return;
  fetch('?delete='+_delId+'&ajax=1',{method:'GET'})
    .then(function(r){ return r.json(); })
    .then(function(d){
      if(d.success){
        var row = document.getElementById('row-'+_delId);
        if(row) row.remove();
        closeConfirm();
        showToast('Requirement delete ho gaya.','success');
      } else { showToast('Delete nahi hua.','error'); }
    }).catch(function(){ showToast('Network error.','error'); });
}
document.getElementById('confirmOverlay').addEventListener('click',function(e){ if(e.target===this) closeConfirm(); });

// ── Toast ──
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

// ── Download Template ──
function downloadTemplate(){
  var csv = 'project_name,title,description,priority,reported_date,expected_delivery,actual_delivery,is_developed,is_tested,is_delivered,uat_done,bug_after_uat,bug_fixed\n';
  csv += 'Sample Project Name,Sample Requirement,This is a sample description,medium,<?= date('Y-m-d') ?>,,,0,0,0,0,0,0\n';
  var blob = new Blob([csv], {type:'text/csv'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'requirements_template.csv';
  a.click();
}

// ── Import Modal ──
function openImportModal(){
  document.getElementById('importModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
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

// ── Drag & Drop ──
document.addEventListener('DOMContentLoaded', function(){
  var zone  = document.getElementById('dropZone');
  var input = document.getElementById('csvFileInput');
  if(!zone) return;
  zone.addEventListener('dragover', function(e){
    e.preventDefault();
    zone.style.borderColor = 'var(--blue-mid)';
    zone.style.background  = '#eef4fd';
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
</script>
</body>
</html>
