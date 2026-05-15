<?php
session_start();
include '../config/db.php';

// ════════════════════════════════════════════════════════
//  FLASH MESSAGE — Read from session, then UNSET
// ════════════════════════════════════════════════════════
$msg = '';
$msg_type = '';
if (isset($_SESSION['flash_msg'])) {
    $msg      = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Helper: set flash message and redirect
function flashRedirect(string $message, string $type = 'success'): void {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: user.php');
    exit;
}

// ════════════════════════════════════════════════════════
//  AJAX: Check duplicate email (returns JSON)
// ════════════════════════════════════════════════════════
if (isset($_GET['check_email'])) {
    header('Content-Type: application/json');
    $email = trim($_GET['check_email']);
    $exclude_id = (int)($_GET['exclude_id'] ?? 0);
    if ($exclude_id > 0) {
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $chk->bind_param('si', $email, $exclude_id);
    } else {
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $chk->bind_param('s', $email);
    }
    $chk->execute();
    $chk->store_result();
    echo json_encode(['exists' => $chk->num_rows > 0]);
    $chk->close();
    exit;
}

// ════════════════════════════════════════════════════════
//  ACTIONS  (Add / Edit / Delete / Toggle)
// ════════════════════════════════════════════════════════

// ── DELETE ──────────────────────────────────
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    $stmt->close();
    flashRedirect('User deleted successfully.');
}

// ── TOGGLE STATUS ────────────────────────────
if (isset($_GET['toggle'])) {
    $tog_id = (int)$_GET['toggle'];
    $conn->query("UPDATE users SET status = IF(status='active','inactive','active') WHERE id=$tog_id");
    flashRedirect('User status updated successfully.');
}

// ── ADD USER ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $name     = trim($_POST['name']     ?? '');
    $uname    = trim($_POST['username'] ?? '');
    
    // FIX: Remove spaces from phone for DB storage
    $phone    = preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
    
    $role     = $_POST['role']          ?? 'developer';
    $pwd      = $_POST['password']      ?? '';
    $cpwd     = $_POST['confirm_password'] ?? '';
    $status   = 'active';

    if (!$name || !$uname || !$phone || !$pwd) {
    flashRedirect('Please fill in all required fields.', 'error');
    } 
    elseif (!filter_var($uname, FILTER_VALIDATE_EMAIL)) {
      flashRedirect('Please enter a valid email address for username.', 'error');
    }
     elseif ($pwd !== $cpwd) {
        flashRedirect('Passwords do not match.', 'error');
    } elseif (!preg_match('/^[6-9][0-9]{9}$/', $phone)) {
        flashRedirect('Phone number must be 10 digits and start with 6-9.', 'error');
        } else {
        // CHECK: Username (email) already exists?
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param('s', $uname);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $check->close();
            flashRedirect('This email is already registered. Please use a different email.', 'error');
        }
        $check->close();

        $hash = md5($pwd); 
        $stmt = $conn->prepare("INSERT INTO users (name, username, phone, password, role, status) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('ssssss', $name, $uname, $phone, $hash, $role, $status);
        if ($stmt->execute()) {
            flashRedirect('User added successfully!');
        } else {
            flashRedirect('Error: ' . $stmt->error, 'error');
        }
        $stmt->close();
    }
}

// ── EDIT USER ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $edit_id  = (int)$_POST['edit_id'];
    $name     = trim($_POST['name']     ?? '');
    $uname    = trim($_POST['username'] ?? '');
    
    // FIX: Remove spaces from phone for DB storage
    $phone    = preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
    
    $role     = $_POST['role']          ?? 'developer';
    $status   = $_POST['status']        ?? 'active';
    $pwd      = $_POST['password']      ?? '';
    $cpwd     = $_POST['confirm_password'] ?? '';

    if (!$name || !$uname || !$phone) {
      flashRedirect('Please fill in all required fields.', 'error');
      }
       elseif (!filter_var($uname, FILTER_VALIDATE_EMAIL)) {
        flashRedirect('Please enter a valid email address for username.', 'error');
        }
     elseif ($pwd && $pwd !== $cpwd) {
        flashRedirect('Passwords do not match.', 'error');
    } elseif (!preg_match('/^[6-9][0-9]{9}$/', $phone)) {
        flashRedirect('Phone number must be 10 digits and start with 6-9.', 'error');
        } else {
        // CHECK: Username (email) already used by another user?
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->bind_param('si', $uname, $edit_id);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $check->close();
            flashRedirect('This email is already registered by another user. Please use a different email.', 'error');
        }
        $check->close();

        if ($pwd) {
            $hash = md5($pwd); 
            $stmt = $conn->prepare("UPDATE users SET name=?, username=?, phone=?, password=?, role=?, status=? WHERE id=?");
            $stmt->bind_param('ssssssi', $name, $uname, $phone, $hash, $role, $status, $edit_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name=?, username=?, phone=?, role=?, status=? WHERE id=?");
            $stmt->bind_param('sssssi', $name, $uname, $phone, $role, $status, $edit_id);
        }
        if ($stmt->execute()) {
            flashRedirect('User updated successfully!');
        } else {
            flashRedirect('Error: ' . $stmt->error, 'error');
        }
        $stmt->close();
    }
}

// ── FETCH ALL USERS ──────────────────────────
 $search = trim($_GET['search'] ?? '');
 $per_page = 10;

// Count total users
if ($search) {
    $like = "%$search%";
    $count_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE name LIKE ? OR username LIKE ? OR role LIKE ?");
    $count_stmt->bind_param('sss', $like, $like, $like);
    $count_stmt->execute();
    $count_stmt->bind_result($total_count);
    $count_stmt->fetch();
    $count_stmt->close();
} else {
    $cr = $conn->query("SELECT COUNT(*) FROM users");
    $total_count = $cr->fetch_row()[0];
}

 $total_pages = max(1, ceil($total_count / $per_page));
 $page        = max(1, min((int)($_GET['page'] ?? 1), $total_pages));
 $offset      = ($page - 1) * $per_page;

// Fetch users with LIMIT
if ($search) {
    $like = "%$search%";
    $stmt = $conn->prepare("SELECT * FROM users WHERE name LIKE ? OR username LIKE ? OR role LIKE ? ORDER BY createdAt DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('sssii', $like, $like, $like, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT * FROM users ORDER BY createdAt DESC LIMIT $per_page OFFSET $offset");
}
 $users = [];
while ($row = $result->fetch_assoc()) $users[] = $row;

// Edit prefill
 $edit_user = null;
if (isset($_GET['edit'])) {
    $eid  = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
    $stmt->bind_param('i', $eid);
    $stmt->execute();
    $edit_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

 $conn->close();

// ── Role badge color ─────────────────────────
function role_class(string $r): string {
    return match($r) {
        'admin'     => 'badge-admin',
        'developer' => 'badge-dev',
        'qa'        => 'badge-tester',
        'client'    => 'badge-client',
        default     => 'badge-dev',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TestiFy — Users</title>
<link rel="icon" type="image/jpg" href="../icon/testify.jpg" />
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<style>
/* ═══════════════════════════════════════════════════════════════
   COLORFUL THEME — Matches Admin Dashboard Style
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

/* Hamburger */
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
.main { max-width:1200px; margin:0 auto; padding:28px 24px 60px; }
.page-title { display:flex; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
.page-title h1 { font-family:'Poppins',sans-serif; font-weight:800; font-size:26px; background:linear-gradient(135deg,var(--blue-dark),var(--purple)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; letter-spacing:-.5px; }
.badge-page { background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; font-family:'Poppins',sans-serif; font-weight:700; font-size:10px; letter-spacing:2px; text-transform:uppercase; padding:5px 14px; border-radius:8px; box-shadow:0 2px 10px rgba(142,68,173,.25); }

/* ══ TOOLBAR ══ */
.toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
.search-wrap { position:relative; flex:1; min-width:200px; max-width:340px; }
.search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--text-muted); pointer-events:none; }
.search-input { width:100%; padding:9px 14px 9px 36px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; }
.search-input:focus { border-color:var(--purple-mid); box-shadow:0 0 0 3px rgba(155,89,182,.12); }
.btn-add { padding:9px 20px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--green),var(--green-mid)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; display:flex; align-items:center; gap:6px; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(39,174,96,.3); white-space:nowrap; }
.btn-add:hover { opacity:.92; transform:translateY(-2px); box-shadow:0 8px 22px rgba(39,174,96,.38); }
.btn-add svg { width:15px; height:15px; }

/* ══ TABLE CARD ══ */
.table-card { background:var(--white); border-radius:18px; border:1.5px solid var(--border); box-shadow:0 4px 20px rgba(74,144,217,.07); overflow:hidden; position:relative; }
.table-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid),var(--orange)); border-radius:18px 18px 0 0; z-index:1; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:680px; }
thead th { background:linear-gradient(90deg,#f0f5fd,#eef4fd); padding:13px 16px; text-align:left; font-size:12px; font-weight:700; color:var(--text-muted); letter-spacing:.8px; text-transform:uppercase; border-bottom:1.5px solid var(--border); white-space:nowrap; }
thead th:first-child { border-radius:0; }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:linear-gradient(90deg,#f5f8fe,#f8faff); box-shadow:inset 3px 0 0 var(--blue-mid); }
tbody td { padding:13px 16px; font-size:13.5px; vertical-align:middle; }
.user-name { font-weight:700; color:var(--text-main); }
.user-username { font-size:12px; color:var(--text-muted); font-weight:600; }
.user-phone { font-size:13px; color:var(--text-muted); }

/* badges */
.badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:700; letter-spacing:.3px; }
.badge-admin   { background:linear-gradient(135deg,#fde8e8,#f9cccc); color:#c0392b; border:1px solid #f5b7b1; }
.badge-dev     { background:linear-gradient(135deg,#e8f0fe,#d4e4f7); color:#3a7bd5; border:1px solid #bdd4f0; }
.badge-tester  { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); color:#1e8449; border:1px solid #a9dfbf; }
.badge-client  { background:linear-gradient(135deg,#fef9e7,#fef3cd); color:#b7770d; border:1px solid #f9e79f; }
.badge-active  { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); color:#1e8449; border:1px solid #a9dfbf; }
.badge-inactive{ background:linear-gradient(135deg,#fde8e8,#f9cccc); color:#c0392b; border:1px solid #f5b7b1; }

/* action buttons */
.action-btns { display:flex; gap:6px; flex-wrap:nowrap; }
.btn-icon { width:34px; height:34px; border-radius:10px; border:1.5px solid var(--border); background:var(--white); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; flex-shrink:0; text-decoration:none; }
.btn-icon svg { width:14px; height:14px; }
.btn-icon.edit   { color:var(--blue-mid); }
.btn-icon.edit:hover   { background:linear-gradient(135deg,#eef4fd,#d4e4f7); border-color:var(--blue-mid); transform:translateY(-2px); box-shadow:0 3px 10px rgba(58,123,213,.2); }
.btn-icon.toggle { color:var(--green); }
.btn-icon.toggle:hover { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); border-color:var(--green); transform:translateY(-2px); box-shadow:0 3px 10px rgba(39,174,96,.2); }
.btn-icon.toggle.off { color:var(--text-muted); }
.btn-icon.del   { color:var(--red); }
.btn-icon.del:hover   { background:linear-gradient(135deg,#fde8e8,#f9cccc); border-color:var(--red); transform:translateY(-2px); box-shadow:0 3px 10px rgba(231,76,60,.2); }

/* empty state */
.empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
.empty-state svg { width:48px; height:48px; margin-bottom:12px; color:#c5d5e8; }
.empty-state p { font-size:14px; font-weight:600; }

/* table footer & pagination */
.table-footer { padding:13px 20px; border-top:1px solid var(--border); font-size:12.5px; color:var(--text-muted); font-weight:600; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.pg-nav { display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
.pg-btn { min-width:34px; height:34px; padding:0 6px; border-radius:50%; border:1.5px solid var(--border); background:var(--white); display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; cursor:pointer; transition:all .15s; text-decoration:none; color:var(--text-muted); }
.pg-btn:hover:not(.active):not(.disabled) { background:linear-gradient(135deg,#f0f5fd,#eef4fd); border-color:var(--blue-mid); transform:translateY(-2px); color:var(--blue-dark); box-shadow:0 3px 10px rgba(74,144,217,.15); }
.pg-btn.active { background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; border-color:transparent; box-shadow:0 3px 12px rgba(58,123,213,.3); }
.pg-btn.disabled { opacity:.4; cursor:default; pointer-events:none; }
.pg-btn svg { width:14px; height:14px; }

/* ══ MODAL (SCROLL FIX) ══ */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.45); backdrop-filter:blur(3px); z-index:500; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.open { display:flex; }
.modal { background:var(--white); border-radius:20px; width:100%; max-width:480px; box-shadow:0 24px 64px rgba(30,45,80,.22); overflow:hidden; display:flex; flex-direction:column; position:relative; }
.modal::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--blue-dark),var(--purple-mid),var(--green-mid)); border-radius:20px 20px 0 0; z-index:1; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:22px 24px 18px; border-bottom:1.5px solid var(--border); flex-shrink:0; }
.modal-header h2 { font-family:'Poppins',sans-serif; font-weight:800; font-size:18px; background:linear-gradient(135deg,var(--blue-dark),var(--purple)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.modal-close { width:32px; height:32px; border-radius:8px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-muted); transition:background .15s,color .15s; }
.modal-close:hover { background:linear-gradient(135deg,#fde8e8,#f9cccc); color:var(--red); }
.modal-close svg { width:16px; height:16px; }

.modal-body { 
  padding:22px 24px; 
  overflow-y:auto; 
  flex:1; 
  max-height: 75vh; 
}

.modal-footer { padding:16px 24px 20px; border-top:1.5px solid var(--border); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; }

/* form */
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:700; color:var(--text-main); margin-bottom:6px; }
.form-group label span { color:var(--red); }
.form-control { width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid var(--border); font-family:'Nunito',sans-serif; font-size:13.5px; color:var(--text-main); outline:none; transition:border-color .2s,box-shadow .2s; background:var(--white); }
.form-control:focus { border-color:var(--purple-mid); box-shadow:0 0 0 3px rgba(155,89,182,.12); }
.form-control::placeholder { color:#b0bdd6; }

.phone-hint { font-size:11px; color:var(--text-muted); margin-top:5px; font-weight:600; }

.pw-wrap { position:relative; }
.pw-wrap .form-control { padding-right:42px; }
.pw-toggle { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:var(--text-muted); padding:2px; display:flex; align-items:center; }
.pw-toggle:hover { color:var(--purple-mid); }
.pw-toggle svg { width:18px; height:18px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.btn-save { padding:10px 26px; border-radius:10px; border:none; background:linear-gradient(135deg,var(--blue-dark),var(--purple-mid)); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:opacity .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 14px rgba(58,123,213,.28); }
.btn-save:hover { opacity:.92; transform:translateY(-1px); box-shadow:0 6px 20px rgba(58,123,213,.35); }
.btn-save:disabled { opacity:.55; cursor:not-allowed; transform:none; }
.btn-cancel { padding:10px 22px; border-radius:10px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; cursor:pointer; transition:background .15s,color .15s; }
.btn-cancel:hover { background:linear-gradient(135deg,#f0f5fd,#eef4fd); color:var(--text-main); }

/* ══ INLINE FORM ERROR ══ */
.form-error { background:linear-gradient(135deg,#fde8e8,#fce4e4); color:#c0392b; padding:10px 14px; border-radius:10px; font-size:13px; font-weight:700; margin-bottom:16px; display:none; align-items:center; gap:8px; border:1.5px solid #f5c6cb; }
.form-error.show { display:flex; }
.form-error svg { width:16px; height:16px; flex-shrink:0; }

/* ══ TOAST ══ */
.toast { position:fixed; bottom:28px; right:28px; z-index:999; display:flex; align-items:center; gap:10px; padding:13px 20px; border-radius:12px; font-size:13.5px; font-weight:700; box-shadow:0 8px 28px rgba(30,45,80,.18); min-width:240px; }
.toast.success { background:linear-gradient(135deg,#e8f8f0,#c6f0d6); color:#1e8449; border:1.5px solid #a9dfbf; }
.toast.error   { background:linear-gradient(135deg,#fde8e8,#f9cccc); color:#c0392b; border:1.5px solid #f5b7b1; }
.toast svg { width:17px; height:17px; flex-shrink:0; }

/* confirm dialog */
.confirm-overlay { display:none; position:fixed; inset:0; background:rgba(30,45,80,.4); backdrop-filter:blur(2px); z-index:600; align-items:center; justify-content:center; padding:16px; }
.confirm-overlay.open { display:flex; }
.confirm-box { background:var(--white); border-radius:16px; padding:28px 28px 22px; max-width:360px; width:100%; box-shadow:0 20px 56px rgba(30,45,80,.2); text-align:center; position:relative; overflow:hidden; }
.confirm-box::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg,var(--red),var(--orange)); }
.confirm-box h3 { font-family:'Poppins',sans-serif; font-weight:800; font-size:16px; color:var(--text-main); margin-bottom:8px; }
.confirm-box p { font-size:13.5px; color:var(--text-muted); margin-bottom:22px; line-height:1.6; }
.confirm-btns { display:flex; gap:10px; justify-content:center; }
.btn-confirm-del { padding:9px 22px; border-radius:9px; border:none; background:linear-gradient(135deg,var(--red),#c0392b); color:#fff; font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; transition:opacity .2s,box-shadow .2s; box-shadow:0 3px 10px rgba(231,76,60,.25); }
.btn-confirm-del:hover { opacity:.88; box-shadow:0 5px 16px rgba(231,76,60,.35); }
.btn-confirm-cancel { padding:9px 22px; border-radius:9px; border:1.5px solid var(--border); background:transparent; color:var(--text-muted); font-family:'Nunito',sans-serif; font-weight:700; font-size:13.5px; cursor:pointer; transition:background .15s; }
.btn-confirm-cancel:hover { background:linear-gradient(135deg,#f0f5fd,#eef4fd); }

/* ══ RESPONSIVE FIX (UPDATED) ══ */
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
  .form-row { grid-template-columns:1fr; }
  .toast { right:14px; bottom:14px; min-width:200px; }

  /* --- FIX START --- */
  
  /* Make toolbar stack vertically */
  .toolbar {
    flex-direction: column; /* Stack elements */
    align-items: stretch;   /* Stretch elements to full width */
  }

  /* Form inside toolbar (contains search) */
  .toolbar form {
    display: block;
    width: 100%;
  }

  /* Remove max-width and flex limitations for mobile */
  .search-wrap {
    width: 100%;
    max-width: 100%; /* Override previous max-width */
    flex: none;      /* Disable flex-grow */
    margin-bottom: 12px;
  }

  /* Add User Button: Full width */
  .btn-add {
    width: 100%;
    justify-content: center;
    padding: 12px 20px; /* Slightly bigger touch area */
  }

  /* --- FIX END --- */

  /* MODAL FIX FOR SCROLL */
  .modal { max-height: 85vh; width: 95%; }
  .modal-body { max-height: 60vh; padding: 16px; }

  /* PAGINATION FIX */
  .table-footer { flex-direction:column; text-align:center; }
  .pg-nav { justify-content:center; }
}
</style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
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

<!-- ══ SIDEBAR OVERLAY ══ -->
<div class="sidebar-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
  <a href="../dash/admin_dash.php" class="sb-home">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    Home 
  </a>
  <div class="sb-section">Pages</div>
  <a href="../page/user.php" class="sb-link active">
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

<!-- ══ MAIN WRAP ══ -->
<div class="page-wrap" id="pageWrap">
  <div class="main">
    <div class="page-title">
      <h1>Users</h1>
      <span class="badge-page">People Desk</span>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <form method="GET" action="" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;align-items:center;">
        <div class="search-wrap">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="search" class="search-input" placeholder="Search by name, username, or role…" value="<?= htmlspecialchars($search) ?>"/>
        </div>
        <?php if($search): ?>
          <a href="user.php" style="font-size:13px;font-weight:700;color:var(--text-muted);text-decoration:none;padding:8px 12px;border-radius:8px;border:1.5px solid var(--border);background:var(--white);display:inline-flex;align-items:center;gap:5px;transition:background .15s;" onmouseover="this.style.background='#f0f5fd'" onmouseout="this.style.background='var(--white)'">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:13px;height:13px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Clear
          </a>
        <?php endif; ?>
      </form>
      <button class="btn-add" onclick="openAddModal()">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add User
      </button>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>S.NO.</th>
              <th>Name</th>
              <th>Username</th>
              <th>Phone</th>
              <th>Role</th>
              <th>Status</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($users)): ?>
            <tr>
              <td colspan="8">
                <div class="empty-state">
                  <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                  <p>No users found<?= $search ? " for \"$search\"" : '' ?>.</p>
                </div>
              </td>
            </tr>
            <?php else: ?>
           <?php $start_num = ($page - 1) * $per_page + 1; foreach ($users as $i => $u): ?>
            <tr>
              <td style="color:var(--text-muted);font-size:12.5px;font-weight:700;"><?= $start_num + $i ?></td>
              <td>
                <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
              </td>
              <td>
                <div class="user-username"><?= htmlspecialchars($u['username'] ?? '—') ?></div>
              </td>
              <td><span class="user-phone"><?= htmlspecialchars($u['phone']) ?></span></td>
              <td><span class="badge <?= role_class($u['role']) ?>"><?= ucfirst(htmlspecialchars($u['role'])) ?></span></td>
              <td><span class="badge <?= $u['status'] === 'active' ? 'badge-active' : 'badge-inactive' ?>"><?= ucfirst($u['status']) ?></span></td>
              <td style="font-size:12px;color:var(--text-muted);"><?= date('d M Y', strtotime($u['createdAt'])) ?></td>
              <td>
                <div class="action-btns">
                  <!-- Edit -->
                  <button class="btn-icon edit" title="Edit"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>)">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <!-- Toggle Status -->
                  <a class="btn-icon toggle <?= $u['status'] === 'inactive' ? 'off' : '' ?>" href="?toggle=<?= $u['id'] ?>" title="Status Toggle" onclick="return confirm('Confirm status change for <?= addslashes(htmlspecialchars($u['name'])) ?>?')">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18.36 6.64A9 9 0 1 1 5.64 5.64"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                  </a>
                  <!-- Delete -->
                  <!-- <button class="btn-icon del" title="Delete" onclick="openConfirm(<?= $u['id'] ?>, '<?= addslashes(htmlspecialchars($u['name'])) ?>')">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                  </button> -->
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <!-- ══ PAGINATION FOOTER ══ -->
      <div class="table-footer">
        <span>
          Showing <?= $total_count > 0 ? ($page - 1) * $per_page + 1 : 0 ?>–<?= min($page * $per_page, $total_count) ?> of <strong><?= $total_count ?></strong> user<?= $total_count !== 1 ? 's' : '' ?>
          <?php if ($search): ?> <span style="color:var(--blue-mid);font-size:11px;margin-left:4px">(search: "<?= htmlspecialchars($search) ?>")</span><?php endif; ?>
          <?php if ($total_pages > 1): ?> &nbsp;&middot;&nbsp; Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong><?php endif; ?>
        </span>
        <?php if ($total_pages > 1): ?>
        <div class="pg-nav">
          <?php
          $qp = $search ? '&search=' . urlencode($search) : '';
          ?>
          <!-- Previous Button -->
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?><?= $qp ?>" class="pg-btn" title="Previous">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
          <?php else: ?>
            <span class="pg-btn disabled" title="Previous">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            </span>
          <?php endif; ?>

          <!-- Page Number Buttons -->
          <?php for ($pg = 1; $pg <= $total_pages; $pg++): ?>
            <?php if ($total_pages > 7 && abs($pg - $page) > 2 && $pg !== 1 && $pg !== $total_pages): ?>
              <?php if ($pg === 2 || $pg === $total_pages - 1): ?><span style="padding:0 4px;color:var(--text-muted);">&hellip;</span><?php endif; ?>
            <?php else: ?>
              <a href="?page=<?= $pg ?><?= $qp ?>" class="pg-btn <?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <!-- Next Button -->
          <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page + 1 ?><?= $qp ?>" class="pg-btn" title="Next">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          <?php else: ?>
            <span class="pg-btn disabled" title="Next">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ══ ADD MODAL ══ -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Add User</h2>
      <button class="modal-close" onclick="closeModal('addModal')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" id="addForm" autocomplete="off">
      <input type="hidden" name="action" value="add"/>
      <div class="modal-body">
        <!-- Inline Error Message -->
        <div class="form-error" id="addError">
          <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span id="addErrorText"></span>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Name <span>*</span></label>
            <input type="text" name="name" class="form-control" placeholder="Full name" required/>
          </div>
          <div class="form-group">
            <label>Username <span>*</span></label>
            <input type="email" name="username" class="form-control" placeholder="Enter email address" required autocomplete="off"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Phone <span>*</span></label>
            <input type="tel" name="phone" id="addPhone" class="form-control"
                   placeholder="6XXXXXXXXX" maxlength="10" inputmode="numeric"
                   pattern="[6-9][0-9]{9}"
                   oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,10)" required/>
            <div class="phone-hint">10 digits · starts with 6/7/8/9</div>
          </div>
          <div class="form-group">
            <label>Role <span>*</span></label>
            <select name="role" class="form-control" required>
              <option value="developer" selected>Developer</option>
              <option value="qa">QA</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Password <span>*</span></label>
          <div class="pw-wrap">
            <input type="password" name="password" id="addPwd" class="form-control" placeholder="Enter password" required autocomplete="new-password"/>
            <button type="button" class="pw-toggle" onclick="togglePw('addPwd',this)">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <div class="form-group">
          <label>Confirm Password <span>*</span></label>
          <div class="pw-wrap">
            <input type="password" name="confirm_password" id="addCpwd" class="form-control" placeholder="Confirm password" required autocomplete="new-password"/>
            <button type="button" class="pw-toggle" onclick="togglePw('addCpwd',this)">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn-save">Save User</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ EDIT MODAL ══ -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Edit User</h2>
      <button class="modal-close" onclick="closeModal('editModal')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="" id="editForm" autocomplete="off">
      <input type="hidden" name="action" value="edit"/>
      <input type="hidden" name="edit_id" id="editId"/>
      <div class="modal-body">
        <!-- Inline Error Message -->
        <div class="form-error" id="editError">
          <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span id="editErrorText"></span>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Name <span>*</span></label>
            <input type="text" name="name" id="editName" class="form-control" placeholder="Full name" required/>
          </div>
          <div class="form-group">
            <label>Username <span>*</span></label>
           <input type="email" name="username" id="editUsername" class="form-control" placeholder="Enter email address" required autocomplete="off"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Phone <span>*</span></label>
            <input type="tel" name="phone" id="editPhone" class="form-control"
                   placeholder="6XXXXXXXXX" maxlength="10" inputmode="numeric"
                   pattern="[6-9][0-9]{9}"
                   oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,10)" required/>
            <div class="phone-hint">10 digits · starts with 6/7/8/9</div>
          </div>
          <div class="form-group">
            <label>Role <span>*</span></label>
            <select name="role" id="editRole" class="form-control">
              <option value="admin">Admin</option>
              <option value="developer">Developer</option>
              <option value="qa">QA</option>
              <option value="client">Client</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="status" id="editStatus" class="form-control">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <div class="form-group">
          <label>New Password <small style="font-weight:600;color:var(--text-muted)">(leave empty if you don't want to change)</small></label>
          <div class="pw-wrap">
            <input type="password" name="password" id="editPwd" class="form-control" placeholder="New password" autocomplete="new-password"/>
            <button type="button" class="pw-toggle" onclick="togglePw('editPwd',this)">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <div class="form-group">
          <label>Confirm Password</label>
          <div class="pw-wrap">
            <input type="password" name="confirm_password" id="editCpwd" class="form-control" placeholder="Confirm new password" autocomplete="new-password"/>
            <button type="button" class="pw-toggle" onclick="togglePw('editCpwd',this)">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn-save">Update User</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ CONFIRM DELETE ══ -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <h3>Delete User?</h3>
    <p id="confirmMsg">Are you sure? This action cannot be undone.</p>
    <div class="confirm-btns">
      <a id="confirmDelBtn" href="#" class="btn-confirm-del">Yes, Delete</a>
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
  setTimeout(()=>{
    const t=document.getElementById('toast');
    if(t){ t.style.opacity='0'; t.style.transition='opacity 0.5s'; setTimeout(()=>t.remove(),500); }
  }, 3500);
</script>
<?php endif; ?>

<?php if ($edit_user): ?>
<script>
  document.addEventListener('DOMContentLoaded',function(){
    openEditModal(<?= json_encode($edit_user) ?>);
  });
</script>
<?php endif; ?>

<script>
/* ── Sidebar Logic ── */
function toggleSidebar(){
  const sb=document.getElementById('sidebar');
  const ov=document.getElementById('sbOverlay');
  const hm=document.getElementById('hamBtn');
  const isOpen=sb.classList.toggle('open');
  ov.classList.toggle('open',isOpen);
  hm.classList.toggle('open',isOpen);
  document.body.style.overflow=(isOpen&&window.innerWidth<=768)?'hidden':'';
}
function closeSidebar(){
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sbOverlay').classList.remove('open');
  document.getElementById('hamBtn').classList.remove('open');
  document.body.style.overflow='';
}
window.addEventListener('resize',()=>{if(window.innerWidth>768)closeSidebar();});

/* ── Password toggle ── */
function togglePw(id,btn){
  const inp=document.getElementById(id);
  if(!inp) return;
  inp.type = inp.type==='password' ? 'text' : 'password';
  btn.style.color = inp.type==='text' ? 'var(--blue-mid)' : 'var(--text-muted)';
}

/* ── Inline Error Helpers ── */
function showFormError(boxId, textId, msg){
  document.getElementById(boxId).classList.add('show');
  document.getElementById(textId).textContent = msg;
}
function hideFormError(boxId){
  document.getElementById(boxId).classList.remove('show');
}

/* ── AJAX: Check if email already exists ── */
async function checkEmailExists(email, excludeId){
  var url = '?check_email=' + encodeURIComponent(email);
  if (excludeId) url += '&exclude_id=' + excludeId;
  try {
    var resp = await fetch(url);
    return await resp.json();
  } catch(e) {
    return { exists: false };
  }
}

/* ── ADD FORM: Async Validation ── */
document.getElementById('addForm').addEventListener('submit', async function(e){
  e.preventDefault();
  hideFormError('addError');

  var phone = document.getElementById('addPhone').value;
  var pwd   = document.getElementById('addPwd').value;
  var cpwd  = document.getElementById('addCpwd').value;
  var email = document.querySelector('#addForm input[name="username"]').value;

  // 1. Email format validation
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    showFormError('addError', 'addErrorText', 'Please enter a valid email address for username.');
    document.querySelector('#addForm input[name="username"]').focus();
    return;
  }

  // 2. Duplicate email check via AJAX
  if (email) {
    var saveBtn = this.querySelector('.btn-save');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Checking...';
    var result = await checkEmailExists(email, 0);
    saveBtn.disabled = false;
    saveBtn.textContent = 'Save User';
    if (result.exists) {
      showFormError('addError', 'addErrorText', 'This email is already registered. Please use a different email.');
      document.querySelector('#addForm input[name="username"]').focus();
      return;
    }
  }

  // 3. Password match
  if (pwd !== cpwd) {
    showFormError('addError', 'addErrorText', 'Passwords do not match. Please re-enter.');
    document.getElementById('addCpwd').focus();
    return;
  }

  // 4. Phone validation
  if (!/^[6-9][0-9]{9}$/.test(phone)) {
    showFormError('addError', 'addErrorText', 'Phone number must be 10 digits and start with 6, 7, 8, or 9.');
    document.getElementById('addPhone').focus();
    return;
  }

  // All validation passed — submit the form
  this.submit();
});

/* ── EDIT FORM: Async Validation ── */
document.getElementById('editForm').addEventListener('submit', async function(e){
  e.preventDefault();
  hideFormError('editError');

  var phone = document.getElementById('editPhone').value;
  var pwd   = document.getElementById('editPwd').value;
  var cpwd  = document.getElementById('editCpwd').value;
  var email = document.querySelector('#editForm input[name="username"]').value;
  var editId = document.getElementById('editId').value;

  // 1. Email format validation
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    showFormError('editError', 'editErrorText', 'Please enter a valid email address for username.');
    document.querySelector('#editForm input[name="username"]').focus();
    return;
  }

  // 2. Duplicate email check via AJAX (exclude current user)
  if (email) {
    var saveBtn = this.querySelector('.btn-save');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Checking...';
    var result = await checkEmailExists(email, editId);
    saveBtn.disabled = false;
    saveBtn.textContent = 'Update User';
    if (result.exists) {
      showFormError('editError', 'editErrorText', 'This email is already registered by another user. Please use a different email.');
      document.querySelector('#editForm input[name="username"]').focus();
      return;
    }
  }

  // 3. Password match (only if new password entered)
  if (pwd && pwd !== cpwd) {
    showFormError('editError', 'editErrorText', 'Passwords do not match. Please re-enter.');
    document.getElementById('editCpwd').focus();
    return;
  }

  // 4. Phone validation
  if (!/^[6-9][0-9]{9}$/.test(phone)) {
    showFormError('editError', 'editErrorText', 'Phone number must be 10 digits and start with 6, 7, 8, or 9.');
    document.getElementById('editPhone').focus();
    return;
  }

  // All validation passed — submit the form
  this.submit();
});

/* ── Modal helpers ── */
function openAddModal(){
  hideFormError('addError');
  document.getElementById('addForm').reset();
  document.getElementById('addModal').classList.add('open');
  document.body.style.overflow='hidden';
}
function openEditModal(user){
  hideFormError('editError');
  document.getElementById('editId').value       = user.id;
  document.getElementById('editName').value     = user.name || '';
  document.getElementById('editUsername').value = user.username || '';
  document.getElementById('editPhone').value    = user.phone || '';
  document.getElementById('editRole').value     = user.role || 'developer';
  document.getElementById('editStatus').value   = user.status || 'active';
  document.getElementById('editPwd').value      = '';
  document.getElementById('editCpwd').value     = '';
  document.getElementById('editModal').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeModal(id){
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow='';
}

/* ── Confirm delete ── */
function openConfirm(id, name){
  document.getElementById('confirmMsg').textContent = 'Are you sure you want to permanently delete "' + name + '"?';
  document.getElementById('confirmDelBtn').href = '?delete=' + id;
  document.getElementById('confirmOverlay').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeConfirm(){
  document.getElementById('confirmOverlay').classList.remove('open');
  document.body.style.overflow='';
}

/* Close overlay on backdrop click */
['addModal','editModal'].forEach(id=>{
  document.getElementById(id).addEventListener('click',function(e){
    if(e.target===this) closeModal(id);
  });
});
document.getElementById('confirmOverlay').addEventListener('click',function(e){
  if(e.target===this) closeConfirm();
});
</script>
</body>
</html>