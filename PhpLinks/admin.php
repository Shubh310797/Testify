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