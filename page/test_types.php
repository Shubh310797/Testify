<?php
// ── page/client/testing_types.php ─────────────────────────────
session_start();
include '../config/db.php';

// Error Reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

 $msg = '';
 $msg_type = '';

// PRG Messages
if (isset($_GET['added']))   { $msg = 'Testing Type added successfully!';   $msg_type = 'success'; }
elseif (isset($_GET['updated'])) { $msg = 'Testing Type updated!';          $msg_type = 'success'; }
elseif (isset($_GET['deleted']))  { $msg = 'Testing Type deleted.';          $msg_type = 'success'; }

// ════════════════════════════════════════════════════════
//  DELETE (AJAX + Normal)
// ════════════════════════════════════════════════════════
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];

    $stmt = $conn->prepare("DELETE FROM testing_types WHERE id = ?");
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

// ════════════════════════════════════════════════════════
//  ADD / EDIT TESTING TYPE
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Validation
    if (empty($name)) {
        $msg = 'Name is required.'; $msg_type = 'error';
    } else {
        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT INTO testing_types (name, description) VALUES (?, ?)");
            $stmt->bind_param('ss', $name, $description);
            
            if ($stmt->execute()) {
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?added=1'); exit;
            } else {
                $msg = 'Error: ' . htmlspecialchars($stmt->error); $msg_type = 'error';
            }
            $stmt->close();

        } elseif ($action === 'edit') {
            $edit_id = (int)$_POST['edit_id'];
            
            $stmt = $conn->prepare("UPDATE testing_types SET name=?, description=? WHERE id=?");
            $stmt->bind_param('ssi', $name, $description, $edit_id);
            
            if ($stmt->execute()) {
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?updated=1'); exit;
            } else {
                $msg = 'Error: ' . htmlspecialchars($stmt->error); $msg_type = 'error';
            }
            $stmt->close();
        }
    }
}

// ════════════════════════════════════════════════════════
//  FETCH DATA
// ════════════════════════════════════════════════════════
 $testing_types = [];
 $res = $conn->query("SELECT * FROM testing_types ORDER BY id ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $testing_types[] = $row;
    }
}

 $conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TestiFy — Testing Types</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<style>
/* ═══════════════════════════════════════════════════════════════
   COLORFUL THEME — Matches Project Page Style
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

/* ══ LAYOUT ══ */
.page-wrap { margin-left:var(--sb-w); margin-top:var(--nb-h); min-height:calc(100vh - var(--nb-h)); transition:margin-left .28s cubic-bezier(.4,0,.2,1); }

/* ══ MAIN CONTENT ══ */
.main { max-width:900px; margin:0 auto; padding:28px 24px 60px; }
.page-title { display:flex; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
.page-title h1 { font-family:'Poppins',sans-serif; font-weight:800; font-size:26px; background:linear-gradient(135deg,var(--blue-dark),var(--purple)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; letter-spacing:-.5px; }
.badge-page { background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; font-family:'Poppins',sans-serif; font-weight:700; font-size:10px; letter-spacing:2px; text-transform:uppercase; padding:5px 14px; border-radius:8px; box-shadow:0 2px 10px rgba(142,68,173,.25); }

/* ══ TOOLBAR ══ */
.toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
.btn-add { padding:9px 20px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--green),var(--green-mid)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; display:flex; align-items:center; gap:6px; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(39,174,96,.3); white-space:nowrap; flex-shrink:0; }
.btn-add:hover { opacity:.92; transform:translateY(-2px); box-shadow:0 8px 22px rgba(39,174,96,.38); }
.btn-add svg { width:15px; height:15px; }

/* ══ TABLE CARD ══ */
.table-card { background:var(--white); border-radius:18px; border:1.5px solid var(--border); box-shadow:0 4px 20px rgba(74,144,217,.07); overflow:hidden; position:relative; }
.table-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid),var(--orange)); border-radius:18px 18px 0 0; z-index:1; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; }
thead th { background:linear-gradient(90deg,#f0f5fd,#eef4fd); padding:13px 16px; text-align:left; font-size:12px; font-weight:700; color:var(--text-muted); letter-spacing:.8px; text-transform:uppercase; border-bottom:1.5px solid var(--border); white-space:nowrap; }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:linear-gradient(90deg,#f5f8fe,#f8faff); box-shadow:inset 3px 0 0 var(--blue-mid); }
tbody td { padding:13px 16px; font-size:13.5px; vertical-align:middle; }
.type-name { font-weight:700; color:var(--text-main); }
.type-desc { color:var(--text-muted); font-size:12.5px; margin-top:4px; line-height:1.5; }

/* Action Buttons */
.action-btns { display:flex; gap:6px; }
.btn-icon { width:34px; height:34px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; flex-shrink:0; }
.btn-icon svg { width:14px; height:14px; }
.btn-icon.edit { color:var(--blue-mid); }
.btn-icon.edit:hover { background:linear-gradient(135deg,#eef4fd,#d4e4f7); border-color:var(--blue-mid); transform:translateY(-2px); box-shadow:0 3px 10px rgba(58,123,213,.2); }
.btn-icon.del { color:var(--red); }
.btn-icon.del:hover { background:linear-gradient(135deg,#fde8e8,#f9cccc); border-color:var(--red); transform:translateY(-2px); box-shadow:0 3px 10px rgba(231,76,60,.2); }

/* Empty State */
.empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
.empty-state svg { width:48px; height:48px; margin-bottom:12px; color:#c5d5e8; }
.empty-state p { font-size:14px; font-weight:600; }

/* Table Footer */
.table-footer { padding:13px 20px; border-top:1px solid var(--border); font-size:12.5px; color:var(--text-muted); font-weight:600; }

/* ══ MODALS ══ */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.45); backdrop-filter:blur(3px); z-index:500; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.open { display:flex; }
.modal { background:var(--white); border-radius:20px; width:100%; max-width:500px; box-shadow:0 24px 64px rgba(30,45,80,.22); overflow:hidden; display:flex; flex-direction:column; position:relative; }
.modal::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid)); border-radius:20px 20px 0 0; z-index:1; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:22px 24px 18px; border-bottom:1.5px solid var(--border); flex-shrink:0; }
.modal-header h2 { font-family:'Poppins',sans-serif; font-weight:800; font-size:18px; background:linear-gradient(135deg,var(--blue-dark),var(--purple)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.modal-close { width:32px; height:32px; border-radius:8px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-muted); transition:background .15s,color .15s; }
.modal-close:hover { background:linear-gradient(135deg,#fde8e8,#f9cccc); color:var(--red); }
.modal-close svg { width:16px; height:16px; }
.modal form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }
.modal-body { padding:22px 24px; overflow-y:auto; flex:1; }
.modal-footer { padding:16px 24px 20px; border-top:1.5px solid var(--border); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; }

.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:700; color:var(--text-main); margin-bottom:6px; }
.form-group label span { color:var(--red); }
.form-control { width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; background:var(--white); }
.form-control:focus { border-color:var(--purple-mid); box-shadow:0 0 0 3px rgba(155,89,182,.12); }
.form-control::placeholder { color:#b0bdd6; }
textarea.form-control { resize:vertical; min-height:100px; }

.btn-save { padding:10px 26px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(58,123,213,.28); }
.btn-save:hover { opacity:.92; transform:translateY(-1px); box-shadow:0 6px 20px rgba(58,123,213,.35); }
.btn-cancel { padding:10px 22px; border-radius:10px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:background .15s,color .15s; }
.btn-cancel:hover { background:linear-gradient(135deg,#f0f5fd,#eef4fd); color:var(--text-main); }

/* ══ TOAST ══ */
.toast { position:fixed; bottom:28px; right:28px; z-index:999; display:flex; align-items:center; gap:10px; padding:13px 20px; border-radius:12px; font-size:13.5px; font-weight:700; box-shadow:0 8px 28px rgba(30,45,80,.18); min-width:240px; }
.toast.success { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); color:#1e8449; border:1.5px solid #a9dfbf; }
.toast.error   { background:linear-gradient(135deg,#fde8e8,#f9cccc); color:#c0392b; border:1.5px solid #f5b7b1; }
.toast svg { width:17px; height:17px; flex-shrink:0; }
.toast-hide { opacity:0; transition:opacity .5s; }

/* Confirm */
.confirm-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.4); backdrop-filter:blur(2px); z-index:600; align-items:center; justify-content:center; padding:16px; }
.confirm-overlay.open { display:flex; }
.confirm-box { background:var(--white); border-radius:16px; padding:28px 28px 22px; max-width:380px; width:100%; box-shadow:0 20px 56px rgba(30,45,80,.2); text-align:center; position:relative; overflow:hidden; }
.confirm-box::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--red),var(--orange)); }
.confirm-box h3 { font-family:'Poppins',sans-serif; font-weight:800; font-size:16px; color:var(--text-main); margin-bottom:8px; }
.confirm-box p { font-size:13.5px; color:var(--text-muted); margin-bottom:22px; line-height:1.6; }
.confirm-btns { display:flex; gap:10px; justify-content:center; }
.btn-confirm-del { padding:9px 22px; border-radius:9px; border:none; background:linear-gradient(135deg,var(--red),#c0392b); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; transition:opacity .2s,box-shadow .2s; box-shadow:0 3px 10px rgba(231,76,60,.25); }
.btn-confirm-del:hover { opacity:.88; box-shadow:0 5px 16px rgba(231,76,60,.35); }
.btn-confirm-cancel { padding:9px 22px; border-radius:9px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; transition:background .15s; }
.btn-confirm-cancel:hover { background:linear-gradient(135deg,#f0f5fd,#eef4fd); }

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
  .main { padding:16px 14px 48px; }
  .page-title h1 { font-size:20px; }
  .toolbar { flex-direction:column; align-items:stretch; }
  .btn-add { width:100%; margin-top:10px; justify-content:center; padding:12px 20px; }
  .toast { right:14px; bottom:14px; min-width:200px; }
  .modal { max-height:85vh; width:95%; }
  .modal-body { max-height:60vh; padding:16px; }
}
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════
     NAVBAR
     ═══════════════════════════════════════════════════════════ -->
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

<!-- ═══════════════════════════════════════════════════════════
     SIDEBAR
     ═══════════════════════════════════════════════════════════ -->
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
  <a href="../page/test_types.php" class="sb-link active">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
    Testing Types
  </a>
  <a href="../page/project_reports.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
  </a>
  <!-- <div class="sb-section">Insights</div>
  <a href="../page/activity_history.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    Activity
  </a> -->
</aside>

<!-- ═══════════════════════════════════════════════════════════
     MAIN CONTENT
     ═══════════════════════════════════════════════════════════ -->
<div class="page-wrap" id="pageWrap">
  <div class="main">
    <div class="page-title">
      <h1>Testing Types</h1>
      <span class="badge-page">Master Data</span>
    </div>

    <!-- TOOLBAR -->
    <div class="toolbar">
      <button class="btn-add" onclick="openAddModal()">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Testing Type
      </button>
    </div>

    <!-- TABLE -->
    <div class="table-card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>S. No.</th>
              <th>Name</th>
              <th>Description</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($testing_types)): ?>
            <tr>
              <td colspan="4">
                <div class="empty-state">
                  <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
                  <p>No Testing Types found.</p>
                </div>
              </td>
            </tr>
            <?php else: ?>
              <?php $sn = 1; foreach($testing_types as $tt): ?>
              <tr id="row-<?= $tt['id'] ?>">
                <td style="color:var(--text-muted);font-size:12.5px;font-weight:700;"><?= $sn++ ?></td>
                <td>
                  <div class="type-name"><?= htmlspecialchars($tt['name']) ?></div>
                </td>
                <td>
                  <div class="type-desc"><?= htmlspecialchars($tt['description'] ?? '—') ?></div>
                </td>
                <td>
                  <div class="action-btns">
                    <button class="btn-icon edit" title="Edit"
                      onclick='openEditModal(<?= htmlspecialchars(json_encode($tt), ENT_QUOTES) ?>)'>
                      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button class="btn-icon del" title="Delete" onclick="openConfirm(<?= $tt['id'] ?>, '<?= addslashes(htmlspecialchars($tt['name'])) ?>')">
                      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($testing_types)): ?>
      <div class="table-footer">
        Total <strong><?= count($testing_types) ?></strong> testing type<?= count($testing_types) !== 1 ? 's' : '' ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ══ ADD/EDIT MODAL ══ -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <h2 id="modalTitle">Add Testing Type</h2>
      <button class="modal-close" onclick="closeModal()">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" id="typeForm">
      <input type="hidden" name="action" value="add"/>
      <input type="hidden" name="edit_id" id="eId"/>
      <div class="modal-body">
        <div class="form-group">
          <label>Name <span>*</span></label>
          <input type="text" name="name" id="eName" class="form-control" required placeholder="e.g. Manual Testing"/>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" id="eDesc" class="form-control" placeholder="Describe this testing type..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-save" id="btnSaveText">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ CONFIRM DELETE ══ -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <h3>Delete Testing Type?</h3>
    <p id="confirmMsg">Kya aap sure hain?</p>
    <div class="confirm-btns">
      <button class="btn-confirm-del" onclick="confirmDelete()">Haan, Delete Karein</button>
      <button class="btn-confirm-cancel" onclick="closeConfirm()">Cancel</button>
    </div>
  </div>
</div>

<!-- ══ TOAST ══ -->
<?php if ($msg): ?>
<div class="toast <?= $msg_type ?>" id="toast">
  <?php if($msg_type==='success'): ?>
    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  <?php else: ?>
    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <?php endif; ?>
  <?= htmlspecialchars($msg) ?>
</div>
<script>
  setTimeout(function(){
    var t=document.getElementById('toast');
    if(t){t.classList.add('toast-hide');setTimeout(function(){t.remove();},300);}
  },4000);
</script>
<?php endif; ?>

<script>
// ── Sidebar Logic ──────────────────────────────────────────────
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

// ── Modal Logic ─────────────────────────────────────────────
function openAddModal(){
    document.getElementById('modalTitle').innerText = 'Add Testing Type';
    document.getElementById('btnSaveText').innerText = 'Save';
    document.getElementById('typeForm').reset();
    document.getElementById('eId').value = '';
    document.querySelector('#typeForm input[name="action"]').value = 'add';
    document.getElementById('modal-overlay').classList.add('open');
    document.body.style.overflow='hidden';
}

function openEditModal(data){
    document.getElementById('modalTitle').innerText = 'Edit Testing Type';
    document.getElementById('btnSaveText').innerText = 'Update';
    document.querySelector('#typeForm input[name="action"]').value = 'edit';
    document.getElementById('eId').value = data.id;
    document.getElementById('eName').value = data.name || '';
    document.getElementById('eDesc').value = data.description || '';
    document.getElementById('modal-overlay').classList.add('open');
    document.body.style.overflow='hidden';
}

function closeModal(){
    document.getElementById('modal-overlay').classList.remove('open');
    document.body.style.overflow='';
}

document.getElementById('modal-overlay').addEventListener('click', function(e){
    if(e.target === this) closeModal();
});

// ── Confirm Delete ──────────────────────────────────────────
var _delId = null;
function openConfirm(id, name){
    _delId = id;
    document.getElementById('confirmMsg').textContent = '"'+name+'" ko delete karna chahte hain?';
    document.getElementById('confirmOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeConfirm(){
    document.getElementById('confirmOverlay').classList.remove('open');
    document.body.style.overflow = '';
    _delId = null;
}
function confirmDelete(){
    if(!_delId) return;
    fetch('?delete='+_delId+'&ajax=1')
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d.success){
          var row = document.getElementById('row-'+_delId);
          if(row) row.remove();
          closeConfirm();
          showToast('Testing Type delete ho gayi.','success');
        } else {
          closeConfirm();
          showToast(d.msg || 'Delete nahi hua.','error');
        }
      })
      .catch(function(){ showToast('Network error.','error'); });
}
document.getElementById('confirmOverlay').addEventListener('click', function(e){ if(e.target===this) closeConfirm(); });

// ── Toast ───────────────────────────────────────────────────
function showToast(msg, type){
    var old = document.getElementById('liveToast'); if(old) old.remove();
    var t = document.createElement('div'); t.className = 'toast '+type; t.id = 'liveToast';
    var icon = type==='success'
      ?'<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>'
      :'<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    t.innerHTML = icon+msg; document.body.appendChild(t);
    setTimeout(function(){ t.classList.add('toast-hide'); setTimeout(function(){ t.remove(); },300); },4000);
}
</script>
</body>
</html>