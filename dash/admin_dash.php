<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

// ─────────────────────────────────────────────────────────────
// 1. PROJECTS DATA
// ─────────────────────────────────────────────────────────────
$q_proj = $conn->query("SELECT
   SUM(action = 'active')   AS active_count,
   SUM(action = 'inactive') AS inactive_count
FROM projects");
$proj = $q_proj ? $q_proj->fetch_assoc() : ['active_count'=>0,'inactive_count'=>0];
$active_projects   = (int)$proj['active_count'];
$inactive_projects = (int)$proj['inactive_count'];
$total_projects    = $active_projects + $inactive_projects;
$proj_pct          = $total_projects ? round($active_projects / $total_projects * 100) : 0;

// ─────────────────────────────────────────────────────────────
// 2. EMPLOYEES DATA
// ─────────────────────────────────────────────────────────────
$q_emp = $conn->query("SELECT
   SUM(status = 'active')   AS active_count,
   SUM(status = 'inactive') AS inactive_count
FROM users WHERE role IN ('qa', 'developer')");
$emp = $q_emp ? $q_emp->fetch_assoc() : ['active_count'=>0,'inactive_count'=>0];
$active_emp   = (int)$emp['active_count'];
$inactive_emp = (int)$emp['inactive_count'];
$total_emp    = $active_emp + $inactive_emp;
$emp_pct      = $total_emp ? round($active_emp / $total_emp * 100) : 100;

// ─────────────────────────────────────────────────────────────
// 3. CLIENTS DATA
// ─────────────────────────────────────────────────────────────
$q_cli = $conn->query("SELECT
   SUM(status = 'active')   AS active_count,
   SUM(status = 'inactive') AS inactive_count
FROM clients");
$cli = $q_cli ? $q_cli->fetch_assoc() : ['active_count'=>0,'inactive_count'=>0];
$active_cli   = (int)$cli['active_count'];
$inactive_cli = (int)$cli['inactive_count'];
$total_cli    = $active_cli + $inactive_cli;
$cli_pct      = $total_cli ? round($active_cli / $total_cli * 100) : 0;

// ─────────────────────────────────────────────────────────────
// 4. UPCOMING DEADLINES
// ─────────────────────────────────────────────────────────────
$q_dead = $conn->query("
   SELECT p.name AS project_name, c.name AS client_name, p.deadline_date
   FROM   projects p
   JOIN   clients  c ON c.id = p.client_id
   WHERE  p.action = 'active'
     AND  p.deadline_date >= CURDATE()
     AND  p.deadline_date IS NOT NULL
   ORDER  BY p.deadline_date ASC
   LIMIT  4
");
$deadlines = [];
if ($q_dead) {
    while ($row = $q_dead->fetch_assoc()) $deadlines[] = $row;
}

// ─────────────────────────────────────────────────────────────
// 5. OVERDUE PROJECTS
// ─────────────────────────────────────────────────────────────
$q_over = $conn->query("
   SELECT p.name AS project_name, c.name AS client_name, p.deadline_date,
          DATEDIFF(CURDATE(), p.deadline_date) AS days_overdue
   FROM   projects p
   JOIN   clients  c ON c.id = p.client_id
   WHERE  p.action = 'active'
     AND  p.deadline_date < CURDATE()
     AND  p.deadline_date IS NOT NULL
   ORDER  BY days_overdue DESC
   LIMIT  5
");
$overdue = [];
if ($q_over) {
    while ($row = $q_over->fetch_assoc()) $overdue[] = $row;
}

$conn->close();

// ─────────────────────────────────────────────────────────────
// HELPER FUNCTIONS
// ─────────────────────────────────────────────────────────────
function deadline_class(string $date): string {
    $diff = (new DateTime($date))->diff(new DateTime())->days;
    if ($diff <= 7)  return 'date-red';
    if ($diff <= 30) return 'date-blue';
    return 'date-green';
}
function fmt_date(string $date): string {
    return (new DateTime($date))->format('M d, Y');
}
function overdue_label(int $days): string {
    if ($days >= 365) {
        $yrs = floor($days / 365); $rem = $days % 365; $months = floor($rem / 30);
        $label = $yrs . ' yr' . ($yrs > 1 ? 's' : '');
        if ($months) $label .= ' ' . $months . ' mo';
        return $label;
    }
    if ($days >= 30) {
        $months = floor($days / 30); $rem = $days % 30;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TestiFy — Dashboard</title>
<link rel="icon" type="image/jpg" href="../icon/testify.jpg" />
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link rel="stylesheet" href="../css/ad_dash.css">
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
  <a href="../page/project_reports.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
  </a>
</aside>

<!-- MAIN CONTENT -->
<div class="page-wrap" id="pageWrap">
  <div class="main">
    <div class="page-title">
      <h1>Dashboard</h1>
      <span class="badge-overview">Overview</span>
    </div>

    <!-- Metric Cards -->
    <div class="metric-grid">
      <div class="metric-card mc-blue">
        <div class="metric-info">
          <label>Active Projects</label>
          <div class="metric-value"><?= $active_projects ?></div>
          <div class="metric-sub"><strong>Active <?= $active_projects ?></strong>, Inactive <?= $inactive_projects ?></div>
          <div class="progress-bar-wrap"><div class="progress-bar" style="width:<?= $proj_pct ?>%;"></div></div>
        </div>
        <div class="metric-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></div>
      </div>
      <div class="metric-card mc-green">
        <div class="metric-info">
          <label>Employees</label>
          <div class="metric-value"><?= $active_emp ?></div>
          <div class="metric-sub"><strong>Active <?= $active_emp ?></strong>, Inactive <?= $inactive_emp ?></div>
          <div class="progress-bar-wrap"><div class="progress-bar" style="width:<?= $emp_pct ?>%;"></div></div>
        </div>
        <div class="metric-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
      </div>
      <div class="metric-card mc-purple">
        <div class="metric-info">
          <label>Clients</label>
          <div class="metric-value"><?= $active_cli ?></div>
          <div class="metric-sub"><strong>Active <?= $active_cli ?></strong>, Inactive <?= $inactive_cli ?></div>
          <div class="progress-bar-wrap"><div class="progress-bar" style="width:<?= $cli_pct ?>%;"></div></div>
        </div>
        <div class="metric-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></div>
      </div>
    </div>

    <!-- Chart + Deadlines -->
    <div class="bottom-grid">
      <div class="panel panel-blue">
        <h2>Project Status Overview</h2>
        <div class="chart-wrap">
          <div class="chart-canvas-wrap"><canvas id="pieChart"></canvas></div>
          <div class="chart-legend">
            <span><span class="legend-dot" style="background:#3a7bd5;"></span>Active</span>
            <span><span class="legend-dot" style="background:#c8b6f0;"></span>Inactive</span>
          </div>
        </div>
      </div>
      <div class="panel panel-green">
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
              <div class="deadline-date <?= deadline_class($d['deadline_date']) ?>"><?= fmt_date($d['deadline_date']) ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Overdue Projects — Full Width -->
    <div class="panel panel-red full-width-panel">
      <h2 style="display:flex;align-items:center;gap:8px;">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;color:#e74c3c;flex-shrink:0;">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        Overdue Projects
        <?php if (!empty($overdue)): ?>
          <span class="ov-badge"><?= count($overdue) ?> overdue</span>
        <?php endif; ?>
      </h2>
      <?php if (empty($overdue)): ?>
        <div class="overdue-empty">
          <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          No overdue projects! All deadlines are on track.
        </div>
      <?php else: ?>
        <div class="overdue-list">
          <?php foreach ($overdue as $ov):
            $days   = (int)$ov['days_overdue'];
            $sevCls = overdue_severity($days);
            $sevTxt = overdue_label_text($days);
            $timeStr= overdue_label($days);
            $clrMap = ['sev-low'=>'sev-low-clr','sev-medium'=>'sev-medium-clr','sev-high'=>'sev-high-clr','sev-critical'=>'sev-critical-clr'];
            $dayClr = $clrMap[$sevCls] ?? 'sev-high-clr';
            $barPct = min(100, round($days / 180 * 100));
            $barCls = str_replace('sev-','bar-',$sevCls);
          ?>
          <div class="overdue-item">
            <div class="overdue-left">
              <div class="overdue-project"><?= htmlspecialchars($ov['project_name']) ?></div>
              <div class="overdue-meta">
                <span class="overdue-client">
                  <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:11px;height:11px;display:inline;vertical-align:middle;margin-right:2px;"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                  <?= htmlspecialchars($ov['client_name']) ?>
                </span>
                <span class="overdue-deadline">
                  <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:11px;height:11px;display:inline;vertical-align:middle;margin-right:2px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                  Deadline: <?= fmt_date($ov['deadline_date']) ?>
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

  </div><!-- end .main -->
</div>

<script>
  /* Sidebar toggle */
  function toggleSidebar(){
    const sb=document.getElementById('sidebar'),ov=document.getElementById('sbOverlay'),hm=document.getElementById('hamBtn');
    const isOpen=sb.classList.toggle('open');
    ov.classList.toggle('open',isOpen);hm.classList.toggle('open',isOpen);
    document.body.style.overflow=(isOpen&&window.innerWidth<=768)?'hidden':'';
  }
  function closeSidebar(){
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sbOverlay').classList.remove('open');
    document.getElementById('hamBtn').classList.remove('open');
    document.body.style.overflow='';
  }
  window.addEventListener('resize',()=>{if(window.innerWidth>768)closeSidebar();});

  /* Donut chart */
  new Chart(document.getElementById('pieChart').getContext('2d'),{
    type:'doughnut',
    data:{
      labels:['Active Projects','Inactive Projects'],
      datasets:[{data:[<?= $active_projects ?>,<?= $inactive_projects ?>],backgroundColor:['#3a7bd5','#c8b6f0'],borderWidth:0,hoverOffset:8}]
    },
    options:{cutout:'65%',plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>` ${c.label}: ${c.parsed}`}}},animation:false}
  });
</script>
</body>
</html>
