<?php
session_start();
include '../config/db.php';

// ─────────────────────────────────────────────────────────────
// AUTH CHECK
// ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

// ─────────────────────────────────────────────────────────────
// SMART SESSION DETECTION
// Login codes use different key names. We try all common ones.
// ─────────────────────────────────────────────────────────────
$user_id   = 0;
$user_name = 'User';
$user_role = '';

// Try all common session key patterns
if (isset($_SESSION['user_id']))       { $user_id = (int)$_SESSION['user_id']; }
elseif (isset($_SESSION['id']))        { $user_id = (int)$_SESSION['id']; }
elseif (isset($_SESSION['user']['id'])){ $user_id = (int)$_SESSION['user']['id']; }
elseif (isset($_SESSION['uid']))      { $user_id = (int)$_SESSION['uid']; }
elseif (isset($_SESSION['userId']))   { $user_id = (int)$_SESSION['userId']; }

// If we still don't have user_id, try to find it from DB using session name/email
if ($user_id === 0 && isset($_SESSION['name'])) {
    $sess_name = $conn->real_escape_string($_SESSION['name']);
    $q_find = $conn->query("SELECT id, role FROM users WHERE name = '$sess_name' LIMIT 1");
    if ($q_find && $q_find->num_rows > 0) {
        $found = $q_find->fetch_assoc();
        $user_id = (int)$found['id'];
        $user_role = $found['role'];
    }
}
// If still 0, try with username/email stored in session
if ($user_id === 0 && isset($_SESSION['username'])) {
    $sess_user = $conn->real_escape_string($_SESSION['username']);
    $q_find2 = $conn->query("SELECT id, name, role FROM users WHERE username = '$sess_user' LIMIT 1");
    if ($q_find2 && $q_find2->num_rows > 0) {
        $found2 = $q_find2->fetch_assoc();
        $user_id = (int)$found2['id'];
        $user_name = htmlspecialchars($found2['name']);
        $user_role = $found2['role'];
    }
}

// Get name & role from session (prefer session data, fallback to DB result)
if ($user_name === 'User') $user_name = htmlspecialchars($_SESSION['name'] ?? 'User');
if ($user_role === '')     $user_role = $_SESSION['role'] ?? '';

// ─── DEBUG: Set to true to see session + query info on page ───
$DEBUG_MODE = false;
if (isset($_GET['debug'])) $DEBUG_MODE = true;

$debug_info = [];
$debug_info['session_keys']  = array_keys($_SESSION);
$debug_info['session_data']  = $_SESSION;
$debug_info['detected_user_id'] = $user_id;

// ─────────────────────────────────────────────────────────────
// CONFIGURATION — Based on actual database schema from screenshots
// ─────────────────────────────────────────────────────────────
// 'projects' table has: project_lead_id (int, YES, NULL) and qa_lead_id (int, YES, NULL)
// We match the user to projects where they are either the project_lead or qa_lead
$lead_col = 'project_lead_id';
$qa_col   = 'qa_lead_id';

// 'projects' table columns:
//   status  -> enum('Not Started','In Progress','Completed')
//   action  -> enum('active','inactive')

// ─────────────────────────────────────────────────────────────
// 1. MY PROJECTS DATA — Detailed status breakdown
// ─────────────────────────────────────────────────────────────
$q_proj = $conn->query("
    SELECT 
       COUNT(id)                                  AS total_count,
       SUM(action = 'active')                     AS active_count,
       SUM(status = 'Not Started')                AS not_started_count,
       SUM(status = 'In Progress')                AS in_progress_count,
       SUM(status = 'Completed')                  AS completed_count
    FROM projects 
    WHERE $lead_col = $user_id OR $qa_col = $user_id
");
$proj = $q_proj ? $q_proj->fetch_assoc() : [
    'total_count'=>0,'active_count'=>0,
    'not_started_count'=>0,'in_progress_count'=>0,'completed_count'=>0
];

$debug_info['query1_sql'] = "SELECT COUNT(id) AS total_count, SUM(action='active') AS active_count, SUM(status='Not Started') AS not_started_count, SUM(status='In Progress') AS in_progress_count, SUM(status='Completed') AS completed_count FROM projects WHERE $lead_col = $user_id OR $qa_col = $user_id";
$debug_info['query1_result'] = $proj;

$total_my_projects  = (int)$proj['total_count'];
$active_projects    = (int)($proj['active_count'] ?? 0);
$not_started_count  = (int)($proj['not_started_count'] ?? 0);
$in_progress_count  = (int)($proj['in_progress_count'] ?? 0);
$completed_count    = (int)($proj['completed_count'] ?? 0);

// ─────────────────────────────────────────────────────────────
// FALLBACK: If no projects found via lead/qa columns,
// also check if there's a simpler column like 'assigned_to' or 'created_by'
// ─────────────────────────────────────────────────────────────
if ($total_my_projects === 0) {
    // Check all columns in projects table that might reference a user
    $q_cols = $conn->query("SHOW COLUMNS FROM projects");
    $debug_info['projects_columns'] = [];
    if ($q_cols) {
        while ($col = $q_cols->fetch_assoc()) {
            $debug_info['projects_columns'][] = $col['Field'] . ' (' . $col['Type'] . ')';
        }
    }
}

// ─────────────────────────────────────────────────────────────
// 2. MY CLIENTS DATA (Based on the user's projects)
// ─────────────────────────────────────────────────────────────
// 'projects' table has client_id column; 'clients' table has id, name, type, etc.
$q_cli = $conn->query("
    SELECT COUNT(DISTINCT client_id) AS client_count
    FROM projects 
    WHERE ($lead_col = $user_id OR $qa_col = $user_id)
");
$cli = $q_cli ? $q_cli->fetch_assoc() : ['client_count'=>0];
$my_clients = (int)$cli['client_count'];

// ─────────────────────────────────────────────────────────────
// 3. TASKS DUE (Overdue vs Upcoming)
// ─────────────────────────────────────────────────────────────
// Overdue: active projects with past deadline that are NOT completed
$q_over = $conn->query("
    SELECT COUNT(*) AS cnt
    FROM projects 
    WHERE ($lead_col = $user_id OR $qa_col = $user_id)
      AND action = 'active'
      AND status != 'Completed'
      AND deadline_date < CURDATE()
      AND deadline_date IS NOT NULL
");
$overdue_count = $q_over ? (int)$q_over->fetch_assoc()['cnt'] : 0;

// Upcoming: active projects with future deadline that are NOT completed
$q_upc = $conn->query("
    SELECT COUNT(*) AS cnt
    FROM projects 
    WHERE ($lead_col = $user_id OR $qa_col = $user_id)
      AND action = 'active'
      AND status != 'Completed'
      AND deadline_date >= CURDATE()
      AND deadline_date IS NOT NULL
");
$upcoming_count = $q_upc ? (int)$q_upc->fetch_assoc()['cnt'] : 0;

// ─────────────────────────────────────────────────────────────
// 4. UPCOMING DEADLINES LIST
// ─────────────────────────────────────────────────────────────
$q_dead_list = $conn->query("
   SELECT p.name AS project_name, c.name AS client_name, p.deadline_date,
          p.status AS project_status
   FROM   projects p
   JOIN   clients  c ON c.id = p.client_id
   WHERE  (p.$lead_col = $user_id OR p.$qa_col = $user_id)
     AND  p.action = 'active'
     AND  p.status != 'Completed'
     AND  p.deadline_date >= CURDATE()
     AND  p.deadline_date IS NOT NULL
   ORDER  BY p.deadline_date ASC
   LIMIT  5
");
$deadlines = [];
if ($q_dead_list) {
    while ($row = $q_dead_list->fetch_assoc()) $deadlines[] = $row;
}

// ─────────────────────────────────────────────────────────────
// 5. OVERDUE PROJECTS LIST
// ─────────────────────────────────────────────────────────────
$q_over_list = $conn->query("
   SELECT p.name AS project_name, c.name AS client_name, p.deadline_date,
          p.status AS project_status,
          DATEDIFF(CURDATE(), p.deadline_date) AS days_overdue
   FROM   projects p
   JOIN   clients  c ON c.id = p.client_id
   WHERE  (p.$lead_col = $user_id OR p.$qa_col = $user_id)
     AND  p.action = 'active'
     AND  p.status != 'Completed'
     AND  p.deadline_date < CURDATE()
     AND  p.deadline_date IS NOT NULL
   ORDER  BY days_overdue DESC
   LIMIT  5
");
$overdue = [];
if ($q_over_list) {
    while ($row = $q_over_list->fetch_assoc()) $overdue[] = $row;
}

// ─────────────────────────────────────────────────────────────
// 6. PROJECT STATUS BREAKDOWN (for chart)
// ─────────────────────────────────────────────────────────────
$q_status = $conn->query("
    SELECT 
       SUM(status = 'Not Started')   AS not_started,
       SUM(status = 'In Progress')   AS in_progress,
       SUM(status = 'Completed')     AS completed
    FROM projects 
    WHERE ($lead_col = $user_id OR $qa_col = $user_id)
      AND action = 'active'
");
$statusRow     = $q_status ? $q_status->fetch_assoc() : ['not_started'=>0,'in_progress'=>0,'completed'=>0];
// Chart uses the same counts from proj query for consistency
// (These are already set: $not_started_count, $in_progress_count, $completed_count)
// Chart specific: active-only status counts
$chart_not_started = (int)$statusRow['not_started'];
$chart_in_progress = (int)$statusRow['in_progress'];
$chart_completed   = (int)$statusRow['completed'];

$conn->close();

$debug_info['total_my_projects'] = $total_my_projects;
$debug_info['active_projects']   = $active_projects;
$debug_info['not_started_count'] = $not_started_count;
$debug_info['in_progress_count'] = $in_progress_count;
$debug_info['completed_count']   = $completed_count;

// ─────────────────────────────────────────────────────────────
// HELPER FUNCTIONS
// ─────────────────────────────────────────────────────────────
function fmt_date(string $date): string {
    return (new DateTime($date))->format('M d, Y');
}
function overdue_label(int $days): string {
    if ($days >= 365) {
        $yrs = floor($days / 365);
        return $yrs . ' yr' . ($yrs > 1 ? 's' : '');
    }
    if ($days >= 30) {
        $months = floor($days / 30);
        $rem    = $days % 30;
        $label  = $months . ' mo';
        if ($rem) $label .= ' ' . $rem . 'd';
        return $label;
    }
    return $days . ' day' . ($days !== 1 ? 's' : '');
}
function overdue_severity(int $days): string {
    if ($days >= 90) return 'sev-critical';
    if ($days >= 30) return 'sev-high';
    if ($days >= 7)  return 'sev-medium';
    return 'sev-low';
}
function overdue_label_text(int $days): string {
    if ($days >= 90) return 'Critical';
    if ($days >= 30) return 'High';
    if ($days >= 7)  return 'Medium';
    return 'Low';
}
function status_badge_class(string $status): string {
    switch ($status) {
        case 'Not Started':  return 'status-not-started';
        case 'In Progress':  return 'status-in-progress';
        case 'Completed':    return 'status-completed';
        default:             return '';
    }
}

// ─── Current page detection for active sidebar link ───
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TestiFy — User Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link rel="stylesheet" href="../css/ad_dash.css">
<style>
  .nb-user  { font-size:20px; color:var(--text-muted); font-weight:600; white-space:nowrap; display:flex; align-items:center; gap:8px; }
.nb-role  { font-size:15px; font-weight:700; color:#1565c0; background:#e3f2fd; padding:2px 8px; border-radius:6px; text-transform:capitalize; letter-spacing:.5px; white-space:nowrap; }
  /* ─── Specific overrides for User Dashboard ─── */
  .metric-card {
    border-left: 4px solid transparent;
    transition: transform 0.2s, box-shadow 0.2s;
  }
  .metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
  }
  .metric-card:nth-child(1) { border-left-color: #6c5ce7; }
  .metric-card:nth-child(2) { border-left-color: #00b894; }
  .metric-card:nth-child(3) { border-left-color: #0984e3; }
  
  .ov-badge {
      background: #ff7675; color: white; font-size: 12px; padding: 2px 8px; border-radius: 10px;
  }
  
  .overdue-days-label { font-size: 11px; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.5px; }
  .deadline-date { font-weight: 700; font-size: 14px; }

  /* Status badge styles for project status column */
  .status-badge {
      display: inline-block;
      font-size: 11px;
      font-weight: 600;
      padding: 2px 10px;
      border-radius: 12px;
      text-transform: uppercase;
      letter-spacing: 0.3px;
  }
  .status-not-started  { background: #dfe6e9; color: #636e72; }
  .status-in-progress  { background: #ffeaa7; color: #d68910; }
  .status-completed    { background: #55efc4; color: #00b894; }

  /* Activity Log Styles */
  .activity-item {
      display: flex; gap: 12px; margin-bottom: 16px;
      font-size: 14px; align-items: flex-start;
  }
  .activity-icon {
      width: 32px; height: 32px; background: #dfe6e9; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      color: #636e72; flex-shrink: 0;
  }
  .activity-content h4 { margin: 0; font-size: 14px; color: #2d3436; }
  .activity-content p  { margin: 2px 0 0; font-size: 12px; color: #636e72; }

  /* Chart legend update for 3 items */
  .chart-legend {
      display: flex; gap: 16px; flex-wrap: wrap; justify-content: center;
      margin-top: 10px; font-size: 13px;
  }
  .chart-legend span {
      display: flex; align-items: center; gap: 6px;
  }
  .legend-dot {
      width: 10px; height: 10px; border-radius: 50%; display: inline-block;
  }

  /* ─── My Projects Card — Detailed Breakdown ─── */
  .proj-breakdown {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 10px;
  }
  .proj-breakdown-row {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
  }
  .proj-breakdown-dot {
      width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
  }
  .proj-breakdown-label {
      color: #636e72; flex: 1; white-space: nowrap;
  }
  .proj-breakdown-val {
      font-weight: 700; font-size: 14px; min-width: 20px; text-align: right;
  }
  .proj-breakdown-bar {
      flex: 2; height: 6px; background: #f0f0f0; border-radius: 3px; overflow: hidden;
  }
  .proj-breakdown-bar-fill {
      height: 100%; border-radius: 3px;
      transition: width 0.5s ease;
  }
  .dot-ns  { background: #6c5ce7; }
  .dot-ip  { background: #fdcb6e; }
  .dot-cmp { background: #00b894; }
  .dot-act { background: #0984e3; }
  .bar-ns  { background: #6c5ce7; }
  .bar-ip  { background: #fdcb6e; }
  .bar-cmp { background: #00b894; }
  .bar-act { background: #0984e3; }
  .val-ns  { color: #6c5ce7; }
  .val-ip  { color: #d68910; }
  .val-cmp { color: #00b894; }
  .val-act { color: #0984e3; }

  /* My Projects card wider layout */
  .metric-card.proj-card {
      grid-column: 1 / -1;  /* span full width on mobile if needed */
  }
  @media (min-width: 900px) {
      .metric-card.proj-card {
          grid-column: auto;
      }
  }

  /* ─── Debug Panel ─── */
  .debug-panel {
      background: #1e272e; color: #d2dae2; font-family: monospace;
      font-size: 12px; padding: 16px; margin: 20px 0;
      border-radius: 8px; border: 2px solid #ff9f43;
      overflow-x: auto;
  }
  .debug-panel h3 { color: #ff9f43; margin: 0 0 10px; font-size: 14px; }
  .debug-panel pre { white-space: pre-wrap; word-break: break-all; margin: 4px 0; }
  .debug-panel .dbg-key { color: #0abde3; }
  .debug-panel .dbg-val { color: #10ac84; }
  .debug-panel .dbg-err { color: #ee5a24; }

  /* Deadline item status */
  .deadline-item {
      display: flex; justify-content: space-between; align-items: center;
      padding: 10px 0; border-bottom: 1px solid #f0f0f0;
  }
  .deadline-item:last-child { border-bottom: none; }
  .deadline-project { font-weight: 600; font-size: 14px; color: #2d3436; }
  .deadline-client  { font-size: 12px; color: #636e72; margin-top: 2px; }
  .deadline-right   { text-align: right; }
  .deadline-status  { margin-top: 4px; }

  /* ─── Sidebar Active Link ─── */
  .sidebar .sb-link.active,
  .sidebar .sb-home.active {
      background: rgba(58, 123, 213, 0.12);
      color: #3a7bd5;
      font-weight: 700;
  }
  .sidebar .sb-link.active svg,
  .sidebar .sb-home.active svg {
      color: #3a7bd5;
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
      <span><?= htmlspecialchars($_SESSION['name'] ?? 'Guest') ?></span>
      <?php if($user_role): ?>
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
  <a href="../user_page/u_project_reports.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
  </a>
</aside>

<!-- MAIN CONTENT -->
<div class="page-wrap" id="pageWrap">
  <div class="main">
    <div class="page-title">
      <h1>Dashboard</h1>
      <span class="badge-overview">My Work</span>
    </div>

    <!-- ══════════ Metric Cards ══════════ -->
    <div class="metric-grid">
      <!-- My Projects — Detailed Breakdown -->
      <div class="metric-card proj-card">
        <div class="metric-info">
          <label>My Projects</label>
          <div class="metric-value"><?= $total_my_projects ?></div>
          <div class="metric-sub" style="margin-bottom:2px;"><strong>Active <?= $active_projects ?></strong></div>
          <!-- Status Breakdown -->
          <div class="proj-breakdown">
            <div class="proj-breakdown-row">
              <span class="proj-breakdown-dot dot-ns"></span>
              <span class="proj-breakdown-label">Not Started</span>
              <div class="proj-breakdown-bar">
                <div class="proj-breakdown-bar-fill bar-ns" style="width:<?= ($total_my_projects ? ($not_started_count/$total_my_projects)*100 : 0) ?>%;"></div>
              </div>
              <span class="proj-breakdown-val val-ns"><?= $not_started_count ?></span>
            </div>
            <div class="proj-breakdown-row">
              <span class="proj-breakdown-dot dot-ip"></span>
              <span class="proj-breakdown-label">In Progress</span>
              <div class="proj-breakdown-bar">
                <div class="proj-breakdown-bar-fill bar-ip" style="width:<?= ($total_my_projects ? ($in_progress_count/$total_my_projects)*100 : 0) ?>%;"></div>
              </div>
              <span class="proj-breakdown-val val-ip"><?= $in_progress_count ?></span>
            </div>
            <div class="proj-breakdown-row">
              <span class="proj-breakdown-dot dot-cmp"></span>
              <span class="proj-breakdown-label">Completed</span>
              <div class="proj-breakdown-bar">
                <div class="proj-breakdown-bar-fill bar-cmp" style="width:<?= ($total_my_projects ? ($completed_count/$total_my_projects)*100 : 0) ?>%;"></div>
              </div>
              <span class="proj-breakdown-val val-cmp"><?= $completed_count ?></span>
            </div>
            <div class="proj-breakdown-row">
              <span class="proj-breakdown-dot dot-act"></span>
              <span class="proj-breakdown-label">Active</span>
              <div class="proj-breakdown-bar">
                <div class="proj-breakdown-bar-fill bar-act" style="width:<?= ($total_my_projects ? ($active_projects/$total_my_projects)*100 : 0) ?>%;"></div>
              </div>
              <span class="proj-breakdown-val val-act"><?= $active_projects ?></span>
            </div>
          </div>
        </div>
        <div class="metric-icon">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        </div>
      </div>

      <!-- My Clients -->
      <div class="metric-card">
        <div class="metric-info">
          <label>My Clients</label>
          <div class="metric-value"><?= $my_clients ?></div>
          <div class="metric-sub">Active Associations</div>
        </div>
        <div class="metric-icon">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
        </div>
      </div>

      <!-- Tasks Due -->
      <div class="metric-card">
        <div class="metric-info">
          <label>Tasks Due</label>
          <div class="metric-value"><?= $upcoming_count + $overdue_count ?></div>
          <div class="metric-sub">
            <span style="color:#e74c3c">Overdue <?= $overdue_count ?></span>, 
            Upcoming <?= $upcoming_count ?>
          </div>
        </div>
        <div class="metric-icon">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        </div>
      </div>
    </div>

    <!-- ══════════ Bottom Grid: Chart + Deadlines ══════════ -->
    <div class="bottom-grid">
      <!-- Donut Chart — Now shows 3 statuses -->
      <div class="panel">
        <h2>My Work Overview</h2>
        <div class="chart-wrap">
          <div class="chart-canvas-wrap"><canvas id="pieChart"></canvas></div>
          <div class="chart-legend">
            <span><span class="legend-dot" style="background:#6c5ce7;"></span>Not Started (<?= $chart_not_started ?>)</span>
            <span><span class="legend-dot" style="background:#fdcb6e;"></span>In Progress (<?= $chart_in_progress ?>)</span>
            <span><span class="legend-dot" style="background:#00b894;"></span>Completed (<?= $chart_completed ?>)</span>
          </div>
        </div>
      </div>

      <!-- Upcoming Deadlines -->
      <div class="panel">
        <h2>Upcoming Deadlines</h2>
        <div class="deadline-list">
          <?php if (empty($deadlines)): ?>
            <div style="color:var(--text-muted);padding:10px;text-align:center;font-size:14px;">No upcoming deadlines.</div>
          <?php else: ?>
            <?php foreach ($deadlines as $d): ?>
            <div class="deadline-item">
              <div>
                <div class="deadline-project"><?= htmlspecialchars($d['project_name']) ?></div>
                <div class="deadline-client">Client: <?= htmlspecialchars($d['client_name']) ?></div>
              </div>
              <div class="deadline-right">
                <div class="deadline-date" style="background:#eef2f7; color:#2d3436;">
                  <?= fmt_date($d['deadline_date']) ?>
                </div>
                <div class="deadline-status">
                  <span class="status-badge <?= status_badge_class($d['project_status']) ?>">
                    <?= htmlspecialchars($d['project_status']) ?>
                  </span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════════ OVERDUE PROJECTS ══════════ -->
    <div class="overdue-panel">
      <h2>
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:18px;height:18px;color:#e74c3c;flex-shrink:0;">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        Overdue Projects
        <?php if (!empty($overdue)): ?>
          <span class="ov-badge"><?= count($overdue) ?> overdue</span>
        <?php endif; ?>
      </h2>

      <?php if (empty($overdue)): ?>
        <div class="overdue-empty" style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding: 30px; color: #00b894; background: #e6fffa; border-radius: 8px;">
          <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="width:48px; height:48px; margin-bottom:10px;">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Great work! No overdue projects.
        </div>
      <?php else: ?>
        <div class="overdue-list">
          <?php foreach ($overdue as $ov):
            $days    = (int)$ov['days_overdue'];
            $sevCls  = overdue_severity($days);
            $sevTxt  = overdue_label_text($days);
            $timeStr = overdue_label($days);

            $clrMap  = ['sev-low'=>'sev-low-clr','sev-medium'=>'sev-medium-clr','sev-high'=>'sev-high-clr','sev-critical'=>'sev-critical-clr'];
            $dayClr  = $clrMap[$sevCls] ?? 'sev-high-clr';

            $barPct  = min(100, round($days / 180 * 100));
            $barCls  = str_replace('sev-', 'bar-', $sevCls);
          ?>
          <div class="overdue-item">
            <div class="overdue-left">
              <div class="overdue-project"><?= htmlspecialchars($ov['project_name']) ?></div>
              <div class="overdue-meta">
                <span class="overdue-client">
                  Client: <?= htmlspecialchars($ov['client_name']) ?>
                </span>
                <span class="overdue-deadline">
                  Deadline: <?= fmt_date($ov['deadline_date']) ?>
                </span>
                <span class="status-badge <?= status_badge_class($ov['project_status']) ?>" style="margin-left:8px;">
                  <?= htmlspecialchars($ov['project_status']) ?>
                </span>
              </div>
              <div class="overdue-bar-wrap">
                <div class="overdue-bar <?= $barCls ?>" style="width:<?= $barPct ?>%;"></div>
              </div>
            </div>
            <div class="overdue-right">
              <div class="overdue-timer">
                <div class="overdue-days <?= $dayClr ?>">+<?= $timeStr ?></div>
                <div class="overdue-days-label">overdue</div>
              </div>
              <span class="sev-pill <?= $sevCls ?>"><?= $sevTxt ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ══════════ RECENT ACTIVITY ══════════ -->
    <div class="panel" style="margin-top: 20px;">
        <h2>Recent Activity</h2>
        <div style="padding: 10px;">
            <div class="activity-item">
                <div class="activity-icon">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                </div>
                <div class="activity-content">
                    <h4>Test Case Updated</h4>
                    <p>You updated the status of "Login Module" to Passed.</p>
                </div>
            </div>
            <div class="activity-item">
                <div class="activity-icon">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div class="activity-content">
                    <h4>Comment Added</h4>
                    <p>You commented on "CCH247" project dashboard.</p>
                </div>
            </div>
            <div class="activity-item">
                <div class="activity-icon">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div class="activity-content">
                    <h4>Deadline Reminder</h4>
                    <p>System notified you about "Qualitrack" deadline.</p>
                </div>
            </div>
        </div>
    </div>

  </div>
</div><!-- end .page-wrap -->

<?php if ($DEBUG_MODE): ?>
<!-- ══════════ DEBUG PANEL ══════════ -->
<div class="debug-panel">
  <h3>🔍 DEBUG MODE — Session & Query Info</h3>
  <p style="color:#ff9f43;">Add <code>?debug</code> to URL to see this panel.</p>
  <?php foreach ($debug_info as $key => $val): ?>
    <div style="margin-bottom:8px;">
      <span class="dbg-key"><?= htmlspecialchars($key) ?>:</span>
      <pre class="dbg-val"><?= htmlspecialchars(print_r($val, true)) ?></pre>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
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

  // Chart — Updated to show 3 project statuses matching the actual schema
  const notStarted  = <?= $chart_not_started ?>;
  const inProgress  = <?= $chart_in_progress ?>;
  const completed   = <?= $chart_completed ?>;

  // Only show slices that have data; if all zero, show a placeholder
  const hasData = (notStarted + inProgress + completed) > 0;

  new Chart(document.getElementById('pieChart').getContext('2d'), {
    type: 'doughnut',
    data: {
      labels: hasData
        ? ['Not Started', 'In Progress', 'Completed'].filter((_, i) => [notStarted, inProgress, completed][i] > 0)
        : ['No Projects'],
      datasets: [{
        data: hasData
          ? [notStarted, inProgress, completed].filter(v => v > 0)
          : [1],
        backgroundColor: hasData
          ? ['#6c5ce7', '#fdcb6e', '#00b894'].filter((_, i) => [notStarted, inProgress, completed][i] > 0)
          : ['#dfe6e9'],
        borderWidth: 0, hoverOffset: 8,
      }]
    },
    options: {
      cutout: '65%',
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: c => ` ${c.label}: ${c.parsed}` } }
      },
      animation: false
    }
  });
</script>
</body>
</html>