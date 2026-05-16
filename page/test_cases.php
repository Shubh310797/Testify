<?php
// ════════════════════════════════════════════
// IST Timezone set karo
// ════════════════════════════════════════════
date_default_timezone_set('Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

function sendJson($data) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();

// DB Config
$db_path = '../config/db.php';
if (!file_exists($db_path)) {
    if (isset($_POST['ajax_action']) || isset($_GET['ajax_req']) || isset($_GET['ajax_devs'])) {
        sendJson(['success' => false, 'msg' => "DB config not found: $db_path"]);
    } else {
        die("<div style='background:#fee;padding:10px;border:1px solid red;'>DB config not found: $db_path</div>");
    }
}
include $db_path;

if (!isset($conn) || $conn->connect_error) {
    $err = isset($conn) ? $conn->connect_error : 'Connection variable not set';
    if (isset($_POST['ajax_action']) || isset($_GET['ajax_req']) || isset($_GET['ajax_devs'])) {
        sendJson(['success' => false, 'msg' => "DB Error: $err"]);
    } else {
        die("<div style='background:#fee;padding:10px;border:1px solid red;'>DB Error: $err</div>");
    }
}

// ── AJAX: Requirements ──────────────────────────────────────
if (isset($_GET['ajax_req']) && !empty($_GET['project_id'])) {
    $pid = (int)$_GET['project_id'];
    $reqs = [];
    $stmt = $conn->prepare("SELECT id, title FROM requirements WHERE project_id = ? ORDER BY title ASC");
    if ($stmt) {
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $reqs[] = $row;
        $stmt->close();
    }
    sendJson($reqs);
}

// ── AJAX: Developers for a project (PL first, then FE + BE devs) ──
if (isset($_GET['ajax_devs']) && !empty($_GET['project_id'])) {
    $pid = (int)$_GET['project_id'];
    $devs = [];
    $seen = [];

    // ── 1. Query Project Lead from projects table (shown first) ──
    $proj_cols = [];
    $pres = $conn->query("DESCRIBE projects");
    if ($pres) while ($pr = $pres->fetch_assoc()) $proj_cols[] = $pr['Field'];
    $lead_col = null;
    foreach (['project_lead', 'lead_id', 'lead_user_id', 'project_lead_id'] as $lc) {
        if (in_array($lc, $proj_cols)) { $lead_col = $lc; break; }
    }
    if ($lead_col) {
        $stmt = $conn->prepare("SELECT u.id, u.name, 'PL' AS dev_type FROM projects p JOIN users u ON u.id = p.$lead_col WHERE p.id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $pid);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (!isset($seen[$row['id']])) { $devs[] = $row; $seen[$row['id']] = true; }
            }
            $stmt->close();
        }
    }

    // ── 2. Query project_frontend_devs table ──
    $fe_table = null;
    foreach (['project_frontend_devs', 'project_fe_devs', 'frontend_devs', 'fe_devs'] as $t) {
        $chk = $conn->query("SHOW TABLES LIKE '$t'");
        if ($chk && $chk->num_rows > 0) { $fe_table = $t; break; }
    }
    if ($fe_table) {
        $stmt = $conn->prepare("SELECT u.id, u.name, 'FE' AS dev_type FROM $fe_table pfd JOIN users u ON u.id = pfd.user_id WHERE pfd.project_id = ? ORDER BY u.name");
        if ($stmt) {
            $stmt->bind_param('i', $pid);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (!isset($seen[$row['id']])) { $devs[] = $row; $seen[$row['id']] = true; }
            }
            $stmt->close();
        }
    }

    // ── 3. Query project_backend_devs table ──
    $be_table = null;
    foreach (['project_backend_devs', 'project_be_devs', 'backend_devs', 'be_devs'] as $t) {
        $chk = $conn->query("SHOW TABLES LIKE '$t'");
        if ($chk && $chk->num_rows > 0) { $be_table = $t; break; }
    }
    if ($be_table) {
        $stmt = $conn->prepare("SELECT u.id, u.name, 'BE' AS dev_type FROM $be_table pbd JOIN users u ON u.id = pbd.user_id WHERE pbd.project_id = ? ORDER BY u.name");
        if ($stmt) {
            $stmt->bind_param('i', $pid);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (!isset($seen[$row['id']])) { $devs[] = $row; $seen[$row['id']] = true; }
            }
            $stmt->close();
        }
    }

    // ── 4. Fallback: if no project-specific devs found, get all developers ──
    if (empty($devs)) {
        $user_cols = [];
        $ures = $conn->query("DESCRIBE users");
        if ($ures) while ($ur = $ures->fetch_assoc()) $user_cols[] = $ur['Field'];
        $role_col = null;
        foreach (['role', 'user_role', 'type', 'user_type'] as $rc) {
            if (in_array($rc, $user_cols)) { $role_col = $rc; break; }
        }
        if ($role_col) {
            $res = $conn->query("SELECT id, name FROM users WHERE $role_col = 'Developer' ORDER BY name");
        } else {
            $res = $conn->query("SELECT id, name FROM users ORDER BY name");
        }
        if ($res) while ($r = $res->fetch_assoc()) { $r['dev_type'] = 'Dev'; $devs[] = $r; }
    }

    sendJson($devs);
}

// ════════════════════════════════════════════
//  DOWNLOAD TEMPLATE — CSV FORMAT
// ════════════════════════════════════════════
if(isset($_GET['action']) && $_GET['action'] === 'template') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $filename = 'test_cases_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store');
    $headers = ['TC ID', 'Title', 'Page', 'Category', 'Requirement', 'Description',
                'Pre-conditions', 'Expected Result', 'Actual Result', 'Test Actions',
                'Status', 'Bug Status', 'Executed On', 'Comments'];
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, $headers);
    fclose($output);
    exit;
}

// ════════════════════════════════════════════
//  EXPORT CSV HANDLER
// ════════════════════════════════════════════
if(isset($_GET['export']) && $_GET['export'] === 'csv' && !empty($_GET['filter_project'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $pid = (int)$_GET['filter_project'];
    $where = ['tc.project_id=?'];
    $params = [$pid];
    $types = 'i';
    if(!empty($_GET['filter_requirement'])){ $where[]='tc.requirement=?'; $params[]=$_GET['filter_requirement']; $types.='s'; }
    if(!empty($_GET['filter_category'])){ $where[]='tc.category=?'; $params[]=$_GET['filter_category']; $types.='s'; }
    if(!empty($_GET['filter_status'])){ $where[]='tc.status=?'; $params[]=$_GET['filter_status']; $types.='s'; }
    $whereStr = 'WHERE ' . implode(' AND ', $where);
    $sql = "SELECT tc.tc_custom_id, tc.title, tc.page_name, tc.category, tc.requirement, tc.description,
            tc.pre_conditions, tc.expected_result, tc.actual_result, tc.test_actions,
            tc.status, tc.bug_status, tc.executed_on, tc.comments
            FROM test_cases tc $whereStr ORDER BY tc.id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    $filename = 'test_cases_export_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    $headers = ['TC ID', 'Title', 'Page', 'Category', 'Requirement', 'Description',
                'Pre-conditions', 'Expected Result', 'Actual Result', 'Test Actions',
                'Status', 'Bug Status', 'Executed On', 'Comments'];
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['tc_custom_id'] ?? '', $row['title'] ?? '', $row['page_name'] ?? '',
            $row['category'] ?? '', $row['requirement'] ?? '', $row['description'] ?? '',
            $row['pre_conditions'] ?? '', $row['expected_result'] ?? '', $row['actual_result'] ?? '',
            $row['test_actions'] ?? '', $row['status'] ?? '', $row['bug_status'] ?? '',
            $row['executed_on'] ?? '', $row['comments'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

// ════════════════════════════════════════════
//  AJAX HANDLER
// ════════════════════════════════════════════
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        $action = trim($_POST['ajax_action']);

        // ════════════════════════════════════════
        //  update_status
        // ════════════════════════════════════════
        if ($action === 'update_status') {
            $id = (int)$_POST['id'];
            $status = trim($_POST['status']);
            if ($id <= 0) sendJson(['success' => false, 'msg' => 'Invalid ID.']);
            if (!in_array($status, ['Not tested','Pass','Fail'])) sendJson(['success' => false, 'msg' => 'Invalid status.']);
            if ($status === 'Not tested' || $status === 'Pass' || $status === 'Fail') {
                $executed_by = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 1;
                $executed_on = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("UPDATE test_cases SET status=?, is_executed=1, executed_on=?, executed_by_id=? WHERE id=?");
                $stmt->bind_param('ssii', $status, $executed_on, $executed_by, $id);
            } else {
                $stmt = $conn->prepare("UPDATE test_cases SET status=?, is_executed=0, executed_by_id=NULL WHERE id=?");
                $stmt->bind_param('si', $status, $id);
            }
            $ok = $stmt->execute(); $stmt->close();
            $q = $conn->prepare("SELECT tc.*, p.name AS project_name,
                                 u_assign.name AS assigned_name,
                                 u_exec.name AS executed_name,
                                 u_create.name AS created_name
                                 FROM test_cases tc
                                 LEFT JOIN projects p ON p.id=tc.project_id
                                 LEFT JOIN users u_assign ON u_assign.id=tc.assigned_to
                                 LEFT JOIN users u_exec ON u_exec.id=tc.executed_by_id
                                 LEFT JOIN users u_create ON u_create.id=tc.created_by
                                 WHERE tc.id=?");
            $q->bind_param('i', $id); $q->execute();
            $row = $q->get_result()->fetch_assoc(); $q->close();
            sendJson(['success' => $ok, 'action' => 'update_status', 'edit_id' => $id, 'row_html' => generate_row_html($row ?: [], 0)]);
        }

        // ════════════════════════════════════════
        //  update_bug_status
        // ════════════════════════════════════════
        if ($action === 'update_bug_status') {
            $id = (int)$_POST['id'];
            $bug_status = trim($_POST['bug_status']);
            if ($id <= 0) sendJson(['success' => false, 'msg' => 'Invalid ID.']);
            if (!in_array($bug_status, ['Open','In Progress','Resolved','Closed','Reopen'])) sendJson(['success' => false, 'msg' => 'Invalid bug status.']);
            $updated_by = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 1;
            $updated_on  = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE test_cases SET bug_status=?, executed_by_id=?, executed_on=?, is_executed=1 WHERE id=?");
            $stmt->bind_param('sisi', $bug_status, $updated_by, $updated_on, $id);
            $ok = $stmt->execute(); $stmt->close();
            $q = $conn->prepare("SELECT tc.*, p.name AS project_name,
                                 u_assign.name AS assigned_name,
                                 u_exec.name AS executed_name,
                                 u_create.name AS created_name
                                 FROM test_cases tc
                                 LEFT JOIN projects p ON p.id=tc.project_id
                                 LEFT JOIN users u_assign ON u_assign.id=tc.assigned_to
                                 LEFT JOIN users u_exec ON u_exec.id=tc.executed_by_id
                                 LEFT JOIN users u_create ON u_create.id=tc.created_by
                                 WHERE tc.id=?");
            $q->bind_param('i', $id); $q->execute();
            $row = $q->get_result()->fetch_assoc(); $q->close();
            sendJson(['success' => $ok, 'action' => 'update_bug_status', 'edit_id' => $id, 'row_html' => generate_row_html($row ?: [], 0)]);
        }

        // ════════════════════════════════════════
        //  ADD TEST CASE
        // ════════════════════════════════════════
        if ($action === 'add') {
            if (empty($_POST['project_id'])) sendJson(['success' => false, 'msg' => 'Project is required.']);
            $target_dir = "../../uploads/bugs/";
            $video_dir  = "../../uploads/bug_videos/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            if (!is_dir($video_dir))  mkdir($video_dir, 0777, true);
            $project_id   = (int)$_POST['project_id'];
            $created_by   = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 1;
            $tc_custom_id = trim($_POST['tc_custom_id'] ?? '');
            if (empty($tc_custom_id)) { $tc_custom_id = getNextTcId($conn, $project_id); }
            $title        = trim($_POST['title'] ?? '');
            $page_name    = trim($_POST['page_name'] ?? '');
            $category     = trim($_POST['category'] ?? 'Functional');
            $requirement  = trim($_POST['requirement_hidden'] ?? '') ?: trim($_POST['requirement'] ?? '');
            $description  = trim($_POST['description'] ?? '');
            $pre_cond     = trim($_POST['pre_conditions'] ?? '');
            $exp_result   = trim($_POST['expected_result'] ?? '');
            $act_result   = trim($_POST['actual_result'] ?? '');
            $test_actions = trim($_POST['test_actions'] ?? '');
            $date_part    = !empty($_POST['executed_on_date']) ? trim($_POST['executed_on_date']) : '';
            $time_part    = !empty($_POST['executed_on_time']) ? trim($_POST['executed_on_time']) : '';
            $is_executed  = isset($_POST['is_executed'])  ? 1 : 0;
            if ($is_executed && empty($date_part)) { $executed_on = date('Y-m-d H:i:s'); }
            elseif (!empty($date_part)) { $time_part = !empty($time_part) ? $time_part : '00:00:00'; $executed_on = "$date_part $time_part"; }
            else { $executed_on = null; }
            $status       = trim($_POST['status'] ?? 'Not tested');
            $bug_status   = trim($_POST['bug_status'] ?? 'Open');
            $assigned_to  = (!empty($_POST['assigned_to']) && (int)$_POST['assigned_to'] > 0) ? (int)$_POST['assigned_to'] : null;
            $comments     = trim($_POST['comments'] ?? '');
            $bug_raised   = isset($_POST['bug_raised'])   ? 1 : 0;
            $is_automated = isset($_POST['is_automated']) ? 1 : 0;
            $executed_by_id = $is_executed ? (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 1) : null;
            $scr = [];
            if (isset($_FILES['bug_screenshots']) && !empty($_FILES['bug_screenshots']['name'][0])) {
                foreach ($_FILES['bug_screenshots']['name'] as $k => $name) {
                    if ($_FILES['bug_screenshots']['error'][$k] !== UPLOAD_ERR_OK) continue;
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['png','jpg','jpeg','gif','webp'])) continue;
                    $nn = uniqid('scr_', true) . '.' . $ext;
                    if (move_uploaded_file($_FILES['bug_screenshots']['tmp_name'][$k], $target_dir . $nn)) $scr[] = $nn;
                }
            }
            $scr_json = json_encode($scr);
            $vids = [];
            if (isset($_FILES['bug_videos']) && !empty($_FILES['bug_videos']['name'][0])) {
                foreach ($_FILES['bug_videos']['name'] as $k => $name) {
                    if ($_FILES['bug_videos']['error'][$k] !== UPLOAD_ERR_OK) continue;
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['mp4','mov','avi','mkv','webm'])) continue;
                    $nn = uniqid('vid_', true) . '.' . $ext;
                    if (move_uploaded_file($_FILES['bug_videos']['tmp_name'][$k], $video_dir . $nn)) $vids[] = $nn;
                }
            }
            // TC ID duplicate check
            if (!empty($tc_custom_id)) {
                $chk = $conn->prepare("SELECT id FROM test_cases WHERE tc_custom_id = ? AND project_id = ?");
                $chk->bind_param('si', $tc_custom_id, $project_id);
                $chk->execute(); $chk->store_result();
                if ($chk->num_rows > 0) { $tc_custom_id = getNextTcId($conn, $project_id); }
                $chk->close();
            }
            $vid_json = json_encode($vids);
            $sql = "INSERT INTO test_cases (
                project_id, created_by, tc_custom_id, title, page_name, category, requirement, description,
                pre_conditions, expected_result, actual_result, is_executed,
                test_actions, executed_on, status, bug_raised, bug_screenshots, bug_videos,
                bug_status, assigned_to, executed_by_id, is_automated, comments
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $conn->prepare($sql);
if (!$stmt) throw new Exception("Prepare Error: " . $conn->error);
$stmt->bind_param('iisssssssssisssisssiiis',
    $project_id, $created_by, $tc_custom_id, $title, $page_name, $category,
    $requirement, $description, $pre_cond, $exp_result, $act_result,
    $is_executed, $test_actions, $executed_on, $status,
    $bug_raised, $scr_json, $vid_json, $bug_status,
    $assigned_to, $executed_by_id, $is_automated, $comments
);
if (!$stmt->execute()) throw new Exception("Execute Error: " . $stmt->error);
            $new_id = $conn->insert_id;
            $stmt->close();
            $q = $conn->prepare("SELECT tc.*, p.name AS project_name,
                                 u_assign.name AS assigned_name,
                                 u_exec.name AS executed_name,
                                 u_create.name AS created_name
                                 FROM test_cases tc
                                 LEFT JOIN projects p ON p.id=tc.project_id
                                 LEFT JOIN users u_assign ON u_assign.id=tc.assigned_to
                                 LEFT JOIN users u_exec ON u_exec.id=tc.executed_by_id
                                 LEFT JOIN users u_create ON u_create.id=tc.created_by
                                 WHERE tc.id=?");
            $q->bind_param('i', $new_id); $q->execute();
            $row = $q->get_result()->fetch_assoc(); $q->close();
            $row_html = generate_row_html($row ?: [], 1);
            sendJson(['success'=>true,'msg'=>'Test Case added!','action'=>'add','row_html'=>$row_html]);
        }

        // ════════════════════════════════════════
        //  EDIT TEST CASE
        // ════════════════════════════════════════
        elseif ($action === 'edit') {
            $edit_id = (int)$_POST['edit_id'];
            if ($edit_id <= 0) sendJson(['success' => false, 'msg' => 'Invalid ID.']);
            $target_dir = "../../uploads/bugs/";
            $video_dir  = "../../uploads/bug_videos/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            if (!is_dir($video_dir))  mkdir($video_dir, 0777, true);
            $title        = trim($_POST['title'] ?? '');
            $page_name    = trim($_POST['page_name'] ?? '');
            $category     = trim($_POST['category'] ?? 'Functional');
            $requirement  = trim($_POST['requirement_hidden'] ?? '') ?: trim($_POST['requirement'] ?? '');
            $description  = trim($_POST['description'] ?? '');
            $pre_cond     = trim($_POST['pre_conditions'] ?? '');
            $exp_result   = trim($_POST['expected_result'] ?? '');
            $act_result   = trim($_POST['actual_result'] ?? '');
            $test_actions = trim($_POST['test_actions'] ?? '');
            $date_part    = !empty($_POST['executed_on_date']) ? trim($_POST['executed_on_date']) : '';
            $time_part    = !empty($_POST['executed_on_time']) ? trim($_POST['executed_on_time']) : '';
            $is_executed  = isset($_POST['is_executed'])  ? 1 : 0;
            $status       = trim($_POST['status'] ?? 'Not tested');
            $bug_status   = trim($_POST['bug_status'] ?? 'Open');
            $assigned_to  = (!empty($_POST['assigned_to']) && (int)$_POST['assigned_to'] > 0) ? (int)$_POST['assigned_to'] : null;
            $comments     = trim($_POST['comments'] ?? '');
            $bug_raised   = isset($_POST['bug_raised'])   ? 1 : 0;
            $is_automated = isset($_POST['is_automated']) ? 1 : 0;
            $executed_by_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 1;
            $executed_on    = date('Y-m-d H:i:s');
            $go = $conn->prepare("SELECT bug_screenshots, bug_videos FROM test_cases WHERE id=?");
            $go->bind_param('i', $edit_id); $go->execute();
            $old = $go->get_result()->fetch_assoc(); $go->close();
            $old_scr  = json_decode($old['bug_screenshots'] ?? '[]', true) ?: [];
            $old_vids = json_decode($old['bug_videos']      ?? '[]', true) ?: [];
            if (isset($_FILES['bug_screenshots']) && !empty($_FILES['bug_screenshots']['name'][0])) {
                $nf = [];
                foreach ($_FILES['bug_screenshots']['name'] as $k => $name) {
                    if ($_FILES['bug_screenshots']['error'][$k] !== UPLOAD_ERR_OK) continue;
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['png','jpg','jpeg','gif','webp'])) continue;
                    $nn = uniqid('scr_', true) . '.' . $ext;
                    if (move_uploaded_file($_FILES['bug_screenshots']['tmp_name'][$k], $target_dir . $nn)) $nf[] = $nn;
                }
                $kept_scr = json_decode($_POST['keep_screenshots'] ?? '[]', true) ?: [];
                $scr_json = json_encode(array_merge($kept_scr, $nf));
            } else {
                $kept_scr = json_decode($_POST['keep_screenshots'] ?? '[]', true);
                $scr_json = json_encode(is_array($kept_scr) ? $kept_scr : $old_scr);
            }
            if (isset($_FILES['bug_videos']) && !empty($_FILES['bug_videos']['name'][0])) {
                $nv = [];
                foreach ($_FILES['bug_videos']['name'] as $k => $name) {
                    if ($_FILES['bug_videos']['error'][$k] !== UPLOAD_ERR_OK) continue;
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['mp4','mov','avi','mkv','webm'])) continue;
                    $nn = uniqid('vid_', true) . '.' . $ext;
                    if (move_uploaded_file($_FILES['bug_videos']['tmp_name'][$k], $video_dir . $nn)) $nv[] = $nn;
                }
                $kept_vid = json_decode($_POST['keep_videos'] ?? '[]', true) ?: [];
                $vid_json = json_encode(array_merge($kept_vid, $nv));
            } else {
                $kept_vid = json_decode($_POST['keep_videos'] ?? '[]', true);
                $vid_json = json_encode(is_array($kept_vid) ? $kept_vid : $old_vids);
            }
            $sql = "UPDATE test_cases SET
                        title=?, page_name=?, category=?, requirement=?, description=?,
                        pre_conditions=?, expected_result=?, actual_result=?,
                        is_executed=?, test_actions=?, executed_on=?, status=?,
                        bug_raised=?, bug_screenshots=?, bug_videos=?, bug_status=?,
                        assigned_to=?, executed_by_id=?, is_automated=?, comments=?
                    WHERE id=?";
           $stmt = $conn->prepare($sql);
           if (!$stmt) throw new Exception("Prepare Error: " . $conn->error);
 $stmt->bind_param('ssssssssisssisssiiisi',
    $title, $page_name, $category, $requirement, $description,
    $pre_cond, $exp_result, $act_result,
    $is_executed, $test_actions, $executed_on, $status,
    $bug_raised, $scr_json, $vid_json, $bug_status,
    $assigned_to, $executed_by_id, $is_automated, $comments,
    $edit_id
);
if (!$stmt->execute()) throw new Exception("Execute Error: " . $stmt->error);
            $stmt->close();
            $q = $conn->prepare("SELECT tc.*, p.name AS project_name,
                                 u_assign.name AS assigned_name,
                                 u_exec.name AS executed_name,
                                 u_create.name AS created_name
                                 FROM test_cases tc
                                 LEFT JOIN projects p ON p.id=tc.project_id
                                 LEFT JOIN users u_assign ON u_assign.id=tc.assigned_to
                                 LEFT JOIN users u_exec ON u_exec.id=tc.executed_by_id
                                 LEFT JOIN users u_create ON u_create.id=tc.created_by
                                 WHERE tc.id=?");
            $q->bind_param('i', $edit_id); $q->execute();
            $row = $q->get_result()->fetch_assoc(); $q->close();
            sendJson(['success'=>true,'msg'=>'Updated!','action'=>'edit','edit_id'=>$edit_id,'row_html'=>generate_row_html($row ?: [], 0)]);
        }

        // ════════════════════════════════════════
        //  DELETE TEST CASE
        // ════════════════════════════════════════
        elseif ($action === 'delete') {
            $del_id = (int)$_POST['del_id'];
            if ($del_id <= 0) sendJson(['success' => false, 'msg' => 'Invalid ID.']);
            $stmt = $conn->prepare("DELETE FROM test_cases WHERE id=?");
            if (!$stmt) throw new Exception("Prepare Error: " . $conn->error);
            $stmt->bind_param('i', $del_id);
            if (!$stmt->execute()) throw new Exception("Delete failed: " . $stmt->error);
            $stmt->close();
            sendJson(['success'=>true,'msg'=>'Deleted.','del_id'=>$del_id]);
        }

        sendJson(['success' => false, 'msg' => 'Unknown action.']);
    }
} catch (Exception $e) {
    sendJson(['success' => false, 'msg' => $e->getMessage()]);
}

// ════════════════════════════════════════════
//  DATA LOADING
// ════════════════════════════════════════════
$clients_list = [];
$c_res = $conn->query("SELECT id, name FROM clients WHERE status='active' ORDER BY name");
if ($c_res) while ($r = $c_res->fetch_assoc()) $clients_list[] = $r;

// ════════════════════════════════════════════
//  ONLY ACTIVE PROJECTS — Inactive projects
//  are hidden from Test Cases dropdown
// ════════════════════════════════════════════
$projects_list = [];
$p_res = $conn->query("SELECT id, client_id, name FROM projects WHERE action = 'active' ORDER BY name ASC");
if ($p_res) while ($r = $p_res->fetch_assoc()) $projects_list[] = $r;

$requirements_list  = [];
$filter_client      = (int)($_GET['filter_client']      ?? 0);
$filter_project     = (int)($_GET['filter_project']     ?? 0);
$filter_requirement = trim($_GET['filter_requirement']  ?? '');
$filter_category    = trim($_GET['filter_category']     ?? '');
$filter_status      = trim($_GET['filter_status']       ?? '');

if ($filter_project) {
    $rs = $conn->prepare("SELECT id, title FROM requirements WHERE project_id=? ORDER BY title ASC");
    $rs->bind_param('i', $filter_project); $rs->execute();
    $rr = $rs->get_result();
    while ($r = $rr->fetch_assoc()) $requirements_list[] = $r;
    $rs->close();
}

$test_cases=[]; $total=0; $per_page=10; $page=1; $total_pages=1;
if ($filter_project) {
    $where=[]; $params=[]; $types='';
    $where[]='tc.project_id=?'; $params[]=$filter_project; $types.='i';
    if ($filter_requirement!=='') { $where[]='tc.requirement=?'; $params[]=$filter_requirement; $types.='s'; }
    if ($filter_category!=='')    { $where[]='tc.category=?';    $params[]=$filter_category;    $types.='s'; }
    if ($filter_status!=='')      { $where[]='tc.status=?';      $params[]=$filter_status;      $types.='s'; }
    $whereStr = 'WHERE '.implode(' AND ', $where);
    $cs = $conn->prepare("SELECT COUNT(*) FROM test_cases tc $whereStr");
    $cs->bind_param($types, ...$params); $cs->execute(); $cs->bind_result($total); $cs->fetch(); $cs->close();
    $total_pages = max(1, ceil($total/$per_page));
    $page        = max(1, min((int)($_GET['page']??1), $total_pages));
    $offset      = ($page-1)*$per_page;
    $st = $conn->prepare("SELECT tc.*, p.name AS project_name,
                          u_assign.name AS assigned_name,
                          u_exec.name AS executed_name,
                          u_create.name AS created_name
                          FROM test_cases tc
                          LEFT JOIN projects p ON p.id=tc.project_id
                          LEFT JOIN users u_assign ON u_assign.id=tc.assigned_to
                          LEFT JOIN users u_exec ON u_exec.id=tc.executed_by_id
                          LEFT JOIN users u_create ON u_create.id=tc.created_by
                          $whereStr ORDER BY tc.created_at DESC LIMIT ? OFFSET ?");
    $qp=$params; $qp[]=$per_page; $qp[]=$offset;
    $st->bind_param($types.'ii',...$qp); $st->execute();
    $res=$st->get_result();
    while($row=$res->fetch_assoc()) $test_cases[]=$row;
    $st->close();
}
$conn->close();

// ════════════════════════════════════════════
//  HELPER FUNCTIONS
// ════════════════════════════════════════════
function getNextTcId($conn, $project_id) {
    $stmt = $conn->prepare("
        SELECT tc_custom_id FROM test_cases
        WHERE project_id = ? AND tc_custom_id REGEXP '^TC-[0-9]+$'
        ORDER BY CAST(SUBSTRING(tc_custom_id, 4) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $stmt->bind_result($max_id);
    $has_row = $stmt->fetch();
    $stmt->close();
    if ($has_row && $max_id) {
        $num = (int)substr($max_id, 3) + 1;
    } else {
        $num = 1;
    }
    return 'TC-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

function generate_row_html($d, $sn='-') {
    $id        = (int)($d['id']??0);
    $title_esc = htmlspecialchars($d['title']??'');
    $page_name = htmlspecialchars($d['page_name']??'');
    $category  = htmlspecialchars($d['category']??'—');
    $req       = htmlspecialchars($d['requirement']??'—');
    $tcId      = htmlspecialchars($d['tc_custom_id']??'TC-'.str_pad($id,4,'0',STR_PAD_LEFT));
    $dj        = json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT);

    $statusPill = spill($d['status']??'Not tested', $id, 'status', ['Not tested','Pass','Fail']);
    $bugPill    = !empty($d['bug_raised'])
        ? spill($d['bug_status']??'Open', $id, 'bug_status', ['Open','In Progress','Resolved','Closed','Reopen'])
        : '<span class="dash-val">—</span>';

    $autoI = !empty($d['is_automated']) ? '<span class="yn yes">&#10003;</span>' : '<span class="yn no">&#10007;</span>';
    $execI = !empty($d['is_executed'])  ? '<span class="yn yes">&#10003;</span>' : '<span class="yn no">&#10007;</span>';
    $bugI  = !empty($d['bug_raised'])   ? '<span class="yn yes">&#10003;</span>' : '<span class="yn no">&#10007;</span>';

    $scr   = json_decode($d['bug_screenshots']??'[]', true) ?: [];
    $vids  = json_decode($d['bug_videos']??'[]', true) ?: [];
    $scrJson = htmlspecialchars(json_encode($scr), ENT_QUOTES);
    $vidJson = htmlspecialchars(json_encode($vids), ENT_QUOTES);

    if (count($scr) > 0) {
        $scrCount = count($scr);
        $scrSvg = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
        $scBtn = "<button class='btn-sc' onclick=\"openScr($scrJson)\">{$scrSvg}$scrCount</button>";
    } else {
        $scBtn = '<span class="dash-val">—</span>';
    }

    if (count($vids) > 0) {
        $vidCount = count($vids);
        $vidSvg = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
        $vBtn = "<button class='btn-sc vbtn' onclick=\"openVid($vidJson)\">{$vidSvg}$vidCount</button>";
    } else {
        $vBtn = '<span class="dash-val">—</span>';
    }

    $assignedName = htmlspecialchars($d['assigned_name'] ?? '');
    if ($assignedName) {
        $assignedCell = "<div class='info-cell' style='align-items:center'>
            <span class='ic-name'>&#128100; $assignedName</span>
        </div>";
    } else {
        $assignedCell = '<span class="dash-val">—</span>';
    }

    $executedName = htmlspecialchars($d['executed_name'] ?? '');
    $execOn = (!empty($d['executed_on']) && $d['executed_on'] !== '0000-00-00 00:00:00') ? $d['executed_on'] : '';

    if (!empty($d['is_executed']) && ($executedName || $execOn)) {
        $datePart = $execOn ? date('d M Y', strtotime($execOn)) : '';
        $timePart = $execOn ? date('h:i A', strtotime($execOn)) : '';
        $nameHtml = $executedName ? "<span class='ic-name'>&#128100; $executedName</span>" : '';
        $dateHtml = $datePart    ? "<span class='ic-date'>&#128197; $datePart</span>" : '';
        $timeHtml = $timePart    ? "<span class='ic-time'>&#128336; $timePart</span>" : '';
        $executedCell = "<div class='info-cell'>$nameHtml$dateHtml$timeHtml</div>";
    } elseif (!empty($d['is_executed'])) {
        $executedCell = '<span class="yn yes" style="font-size:13px">Executed</span>';
    } else {
        $executedCell = '<span class="dash-val">—</span>';
    }

    $createdName = htmlspecialchars($d['created_name'] ?? '');
    $createdAt   = (!empty($d['created_at']) && $d['created_at'] !== '0000-00-00 00:00:00') ? $d['created_at'] : '';
    $creatorDisplay = $createdName ? $createdName : (isset($d['created_by']) ? 'ID '.$d['created_by'] : '—');

    if ($creatorDisplay !== '—' || $createdAt) {
        $createdCell = "<div class='info-cell'>";
        $createdCell .= "<span class='ic-name'>&#128100; $creatorDisplay</span>";
        if ($createdAt) {
            $cDatePart = date('d M Y', strtotime($createdAt));
            $cTimePart = date('h:i A', strtotime($createdAt));
            $createdCell .= "<span class='ic-date'>&#128197; $cDatePart</span>";
            $createdCell .= "<span class='ic-time'>&#128336; $cTimePart</span>";
        }
        $createdCell .= "</div>";
    } else {
        $createdCell = '<span class="dash-val">—</span>';
    }

    $tcIconSvg = '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>';
    $editIconSvg = '<svg fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';

    return "
    <tr data-id='$id'>
        <td>$sn</td>
        <td class='tc-id'><span class='tc-id-badge'>{$tcIconSvg}$tcId</span></td>
        <td><span class='tc-title' onclick='openDetailModal($dj)'>$title_esc</span></td>
        <td class='hide-mobile'>$page_name</td>
        <td class='hide-mobile'>$category</td>
        <td>$req</td>
        <td>$statusPill</td><td>$bugPill</td>
        <td class='cc'>$autoI</td><td class='cc'>$execI</td><td class='cc'>$bugI</td>
        <td>$assignedCell</td>
        <td>$executedCell</td>
        <td class='hide-mobile'>$createdCell</td>
        <td>$scBtn</td><td>$vBtn</td>
        <td><button class='btn-icon' onclick='openEditModal($dj)' title='Edit'>
            {$editIconSvg}
        </button></td>
    </tr>";
}

function spill($cur, $id, $type, $opts) {
    $cm=['Not tested'=>'pill-grey','Pass'=>'pill-green','Fail'=>'pill-red','Open'=>'pill-orange','In Progress'=>'pill-blue','Resolved'=>'pill-teal','Closed'=>'pill-dark','Reopen'=>'pill-red'];
    $cls = $cm[$cur] ?? 'pill-grey';
    $fn  = $type==='bug_status' ? 'chgBug' : 'chgStatus';
    $items='';
    foreach($opts as $o){
        $dc  = $cm[$o] ?? 'pill-grey';
        $chk = ($o===$cur) ? '<span class="pchk">&#10003;</span>' : '';
        $items.="<div class='pdi' onclick=\"{$fn}(this,{$id},'{$o}')\"><span class='pdd {$dc}'></span>".htmlspecialchars($o)."$chk</div>";
    }
    $chevronSvg = '<svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>';
    return "<div class='pw'><span class='sp $cls' onclick='tpd(event,this)'>".htmlspecialchars($cur).$chevronSvg."</span><div class='pd'>$items</div></div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>TestiFy — Test Cases</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../css/a_test_case.css"/>
<link rel="icon" type="image/jpg" href="../icon/testify.jpg" />
<style>
/* ══ TC ID BADGE — Same design as TP ID badge ══ */
.tc-id-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: linear-gradient(135deg, #e8f0fd, #d4e4f7);
  color: #3a7bd5;
  font-family: 'Nunito', sans-serif;
  font-weight: 800;
  font-size: 12px;
  padding: 4px 10px;
  border-radius: 8px;
  border: 1px solid #bdd4f0;
  letter-spacing: .5px;
  white-space: nowrap;
}
.tc-id-badge svg {
  width: 12px;
  height: 12px;
  opacity: .6;
}
/* Detail modal TC ID badge (larger) */
.db-id-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: linear-gradient(135deg, #e8f0fd, #d4e4f7);
  color: #3a7bd5;
  font-family: 'Nunito', sans-serif;
  font-weight: 800;
  font-size: 14px;
  padding: 5px 14px;
  border-radius: 10px;
  border: 1px solid #bdd4f0;
  letter-spacing: .5px;
  white-space: nowrap;
}
.db-id-badge svg {
  width: 14px;
  height: 14px;
  opacity: .6;
}
@media (max-width: 768px) {
  .tc-id-badge { font-size: 11px; padding: 3px 8px; }
}
@media (max-width: 480px) {
  .tc-id-badge { font-size: 10px; padding: 2px 6px; }
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
  <a href="../page/test_cases.php" class="sb-link active">
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

  <!-- Page Title -->
  <div class="ptitle">
    <h1>Test Cases</h1>
    <span class="pbadge">Execution</span>
    <?php if($filter_status !== ''): ?>
      <span style="font-size:12px;font-weight:700;color:var(--blue-dark);background:linear-gradient(135deg,#eef4fd,#f3e8ff);border:1.5px solid #d4c5f9;padding:4px 12px;border-radius:20px;display:inline-flex;align-items:center;gap:6px;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
        Status: <?= htmlspecialchars($filter_status) ?>
        <a href="?<?= http_build_query(array_filter(['filter_client'=>$filter_client,'filter_project'=>$filter_project,'filter_requirement'=>$filter_requirement,'filter_category'=>$filter_category],fn($v)=>$v!==''&&$v!==0)) ?>" style="color:var(--red);text-decoration:none;font-size:14px;line-height:1" title="Clear status filter">&#10005;</a>
      </span>
    <?php endif; ?>
  </div>

  <!-- Toolbar -->
  <div class="toolbar">
    <div class="tleft">
      <div class="filter-row">
        <select class="fs" id="tbClient" onchange="chgClient(this.value)">
          <option value="">Select Client</option>
          <?php foreach($clients_list as $c): ?><option value="<?=$c['id']?>" <?=$filter_client==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?>
        </select>
        <span class="sarr">&#8250;</span>
        <select class="fs" id="tbProject" onchange="chgProject(this.value)" disabled>
          <option value="">Select Project</option>
          <?php if($filter_client): foreach($projects_list as $p): if($p['client_id']==$filter_client): ?>
            <option value="<?=$p['id']?>" <?=$filter_project==$p['id']?'selected':''?>><?=htmlspecialchars($p['name'])?></option>
          <?php endif; endforeach; endif; ?>
        </select>
        <span class="sarr">&#8250;</span>
        <select class="fs" id="tbReq" onchange="chgReq(this.value)" disabled>
          <option value="">All Requirements</option>
          <?php foreach($requirements_list as $r): ?><option value="<?=htmlspecialchars($r['title'])?>" <?=$filter_requirement===$r['title']?'selected':''?>><?=htmlspecialchars($r['title'])?></option><?php endforeach; ?>
        </select>
        <span class="sarr">&#8250;</span>
        <select class="fs" id="tbCat" onchange="applyF()" disabled>
          <option value="">All Categories</option>
          <?php foreach(['Functional','Non-Functional','UI','Security','Performance','Regression'] as $c): ?><option value="<?=$c?>" <?=$filter_category===$c?'selected':''?>><?=$c?></option><?php endforeach; ?>
        </select>
        <span class="sarr">&#8250;</span>
        <select class="fs" id="tbStatus" onchange="applyF()" <?= !$filter_project ? 'disabled' : '' ?>>
          <option value="">All Statuses</option>
          <?php foreach(['Not tested','Pass','Fail'] as $s): ?>
            <option value="<?=$s?>" <?=$filter_status===$s?'selected':''?>><?=$s?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="action-btns-wrap">
      <?php if($filter_project): ?>
      <?php
        $exportParams = array_filter([
          'filter_client'      => $filter_client,
          'filter_project'     => $filter_project,
          'filter_requirement' => $filter_requirement,
          'filter_category'    => $filter_category,
          'filter_status'      => $filter_status,
        ], fn($v) => $v !== '' && $v !== 0);
        $exportParams['export'] = 'csv';
      ?>
      <button class="btnadd btn-export" onclick="window.location.href='?<?= http_build_query($exportParams) ?>'" title="Export CSV">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <span>Export</span>
      </button>
      <?php endif; ?>
      <button class="btnadd btn-template" onclick="downloadTemplate()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <span>Template</span>
      </button>
      <button class="btnadd" id="btnAdd" onclick="openAdd()" disabled title="Select Client → Project → Requirement first">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span>Add Test Case</span>
      </button>
    </div>
  </div>

  <!-- Active Filters -->
  <?php
  $activeFilters = [];
  if($filter_requirement) $activeFilters[] = 'Req: <strong>'.htmlspecialchars($filter_requirement).'</strong>';
  if($filter_category)    $activeFilters[] = 'Category: <strong>'.htmlspecialchars($filter_category).'</strong>';
  if($filter_status)      $activeFilters[] = 'Status: <strong>'.htmlspecialchars($filter_status).'</strong>';
  if(!empty($activeFilters) && $filter_project):
  ?>
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;font-size:12px;color:var(--text-muted)">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
    Active filters:
    <?php foreach($activeFilters as $f): ?>
      <span style="background:linear-gradient(135deg,#eef4fd,#f3e8ff);border:1px solid #d4c5f9;border-radius:20px;padding:2px 10px;color:var(--blue-dark)"><?=$f?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- TABLE CARD -->
  <div class="tcard">
    <div class="tscroll">
      <table>
        <thead><tr>
          <th>S.No</th><th>TC ID</th><th>Title</th><th class="hide-mobile">Page</th><th class="hide-mobile">Category</th><th>Requirement</th>
          <th>Status</th><th>Bug Status</th><th class="cc">Auto</th><th class="cc">Exec</th><th class="cc">Bug</th>
          <th>Assigned To</th><th>Executed By / On</th>
          <th class="hide-mobile">Created By / At</th>
          <th>Screenshots</th><th>Videos</th><th>Action</th>
        </tr></thead>
        <tbody id="tcTbody">
          <?php if(empty($test_cases)): ?>
          <tr id="emptyRow"><td colspan="17"><div class="est">
            <?php if($filter_project && ($filter_status||$filter_category||$filter_requirement)): ?>
              <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:10px;opacity:.4"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
              <p style="font-weight:700;margin-bottom:6px">No test cases match current filters.</p>
              <p style="font-size:12px">Try clearing some filters to see more results.</p>
            <?php else: ?>
              <p>Select Client &rarr; Project &rarr; Requirement to Add Test Cases.</p>
            <?php endif; ?>
          </div></td></tr>
          <?php else: $sn=($page-1)*$per_page+1; foreach($test_cases as $tc): echo generate_row_html($tc,$sn++); endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <!-- Pagination -->
    <div class="tfoot">
      <span>Showing <?=$total>0?($page-1)*$per_page+1:0?>&ndash;<?=min($page*$per_page,$total)?> of <span id="totC"><?=$total?></span>
        <?php if($filter_status): ?><span style="color:var(--blue-dark);font-size:11px;margin-left:4px">(filtered by: <?=htmlspecialchars($filter_status)?>)</span><?php endif; ?>
      </span>
      <div class="pgn">
        <?php
        $cp = array_filter([
            'filter_client'      => $filter_client,
            'filter_project'     => $filter_project,
            'filter_requirement' => $filter_requirement,
            'filter_category'    => $filter_category,
            'filter_status'      => $filter_status,
        ], fn($v) => $v !== '' && $v !== 0);
        $qs = $cp ? '&' . http_build_query($cp) : '';
        ?>
        <?php if($page>1): ?><a href="?page=<?=$page-1?><?=$qs?>" class="pgb">&lsaquo;</a><?php else: ?><span class="pgb" style="opacity:.4">&lsaquo;</span><?php endif; ?>
        <?php for($i=1;$i<=$total_pages;$i++): ?><a href="?page=<?=$i?><?=$qs?>" class="pgb <?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?>
        <?php if($page<$total_pages): ?><a href="?page=<?=$page+1?><?=$qs?>" class="pgb">&rsaquo;</a><?php else: ?><span class="pgb" style="opacity:.4">&rsaquo;</span><?php endif; ?>
      </div>
    </div>
  </div>

</div>
</div>

<!-- DETAIL MODAL -->
<div class="mo" id="detMo">
  <div class="modal" style="max-width:900px">
    <div class="mhdr"><h2 id="detTitle">Test Case Details</h2><button class="mcls" onclick="cDet()">&#10005;</button></div>
    <div class="mbdy" id="detBody"></div>
    <div class="mftr"><button class="bcancel" onclick="cDet()">Close</button></div>
  </div>
</div>

<!-- ADD / EDIT MODAL -->
<div class="mo" id="frmMo">
  <div class="modal">
    <div class="mhdr"><h2 id="frmTitle">Add Test Case</h2><button class="mcls" onclick="cFrm()">&#10005;</button></div>
    <form id="tcForm" enctype="multipart/form-data">
      <input type="hidden" name="ajax_action" id="fAction" value="add"/>
      <input type="hidden" name="edit_id" id="fId"/>
      <input type="hidden" name="project_id" id="fPid"/>
      <input type="hidden" name="requirement_hidden" id="fReqHidden" value=""/>
      <div class="mbdy" id="frmMbdy">
        <!-- Project Banner -->
        <div class="pbanner">
          <div><div class="pb-lbl">Client</div><div class="pb-val" id="pbCli">&mdash;</div></div>
          <div class="pb-sep"></div>
          <div><div class="pb-lbl">Project</div><div class="pb-val" id="pbProj">&mdash;</div></div>
        </div>
        <div class="frow">
          <div class="fg"><label>Title <span class="req">*</span></label><input type="text" name="title" id="fTitle" class="fc" required placeholder="Enter test case title"/></div>
          <div class="fg"><label>Page Name <span class="req">*</span></label><input type="text" name="page_name" id="fPage" class="fc" required placeholder="Login, Dashboard..."/></div>
        </div>
        <div class="frow">
          <div class="fg"><label>Category</label><select name="category" id="fCat" class="fc"><?php foreach(['Functional','Non-Functional','UI','Security','Performance','Regression'] as $c): ?><option><?=$c?></option><?php endforeach; ?></select></div>
          <div class="fg">
            <label>Requirement</label>
            <select name="requirement" id="fReq" class="fc"><option value="">— None —</option></select>
          </div>
        </div>
        <div class="fg"><label>Description</label><textarea name="description" id="fDesc" class="fc" placeholder="Describe test scenario"></textarea></div>
        <div class="fg"><label>Pre Conditions</label><textarea name="pre_conditions" id="fPre" class="fc" placeholder="Prerequisites"></textarea></div>
        <hr class="divider"/>
        <div class="fg"><label>Expected Result <span class="req">*</span></label><textarea name="expected_result" id="fExp" class="fc" required placeholder="Expected outcome"></textarea></div>
        <div class="fg"><label>Actual Result</label><textarea name="actual_result" id="fAct" class="fc" placeholder="What actually happened?"></textarea></div>
        <hr class="divider"/>
        <div class="crow"><input type="checkbox" name="is_executed" id="fExec" onchange="togBlk('execBlk',this)"/><label for="fExec">Is Executed <span class="req">*</span></label></div>
        <div class="fb2" id="execBlk">
          <div class="fg"><label>Test Actions</label><textarea name="test_actions" id="fActs" class="fc" placeholder="Step-by-step actions"></textarea></div>
          <div class="fg"><label>Executed On</label><div class="dtrow"><input type="date" name="executed_on_date" id="fDate" class="fc"/><input type="time" name="executed_on_time" id="fTime" class="fc" step="1"/></div></div>
          <div class="fg" style="margin-bottom:0"><label>Status</label><select name="status" id="fStatus" class="fc"><option value="Not tested">Not tested</option><option value="Pass">Pass</option><option value="Fail">Fail</option></select></div>
        </div>
        <hr class="divider"/>
        <div class="crow"><input type="checkbox" name="bug_raised" id="fBug" onchange="togBlk('bugBlk',this)"/><label for="fBug">Bug Raised</label></div>
        <div class="fb2" id="bugBlk">
          <div class="fg">
            <label>Bug Screenshots</label>
            <div id="exScrWrap" class="ex-wrap" style="display:none">
              <div class="ex-lbl">Current Screenshots<span class="ex-count" id="exScrCount">0</span></div>
              <div class="ex-files" id="exScrList"></div>
            </div>
            <input type="hidden" name="keep_screenshots" id="fKeepScr" value="[]"/>
            <div class="fbox"><input type="file" name="bug_screenshots[]" id="fScrFile" accept=".png,.jpg,.jpeg,.gif,.webp" multiple/></div>
            <p class="fhint">New uploads will be added to existing ones &bull; PNG, JPEG, GIF, WEBP</p>
          </div>
          <div class="fg">
            <label>Bug Videos</label>
            <div id="exVidWrap" class="ex-wrap" style="display:none">
              <div class="ex-lbl">Current Videos<span class="ex-count" id="exVidCount">0</span></div>
              <div class="ex-files" id="exVidList"></div>
            </div>
            <input type="hidden" name="keep_videos" id="fKeepVid" value="[]"/>
            <div class="fbox"><input type="file" name="bug_videos[]" id="fVidFile" accept=".mp4,.mov,.avi,.mkv,.webm" multiple/></div>
            <p class="fhint">New uploads will be added to existing ones &bull; MP4, MOV, AVI, MKV, WEBM</p>
          </div>
          <div class="fg" style="margin-bottom:0"><label>Bug Status</label><select name="bug_status" id="fBugSt" class="fc"><option>Open</option><option>In Progress</option><option>Resolved</option><option>Closed</option><option>Reopen</option></select></div>
        </div>
        <hr class="divider"/>
        <div class="frow">
          <div class="fg"><label>Assigned To</label><select name="assigned_to" id="fAssign" class="fc" disabled><option value="">— Select Project First —</option></select></div>
          <div class="fg" style="padding-top:28px"><div class="crow"><input type="checkbox" name="is_automated" id="fAuto"/><label for="fAuto">Is Automated</label></div></div>
        </div>
        <div class="fg"><label>Comments</label><textarea name="comments" id="fCmt" class="fc" placeholder="Additional notes"></textarea></div>
      </div>
      <div class="mftr"><button type="submit" class="bsave" id="btnSave">Save</button><button type="button" class="bcancel" onclick="cFrm()">Cancel</button></div>
    </form>
  </div>
</div>

<!-- LIGHTBOX CAROUSEL -->
<div class="lbox" id="lbox" onclick="cLboxBg(event)">
  <button class="lbcls" onclick="cLbox()">&#10005;</button>
  <div id="lbCont"></div>
  <button class="lb-nav prev" id="lbPrev" onclick="carouselNav(-1)" style="display:none">&#8249;</button>
  <button class="lb-nav next" id="lbNext" onclick="carouselNav(1)" style="display:none">&#8250;</button>
  <div class="lb-counter" id="lbCounter" style="display:none"></div>
  <div class="lb-dots" id="lbDots"></div>
</div>

<!-- JAVASCRIPT -->
<script>
const PD=<?=json_encode($projects_list,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const CD=<?=json_encode($clients_list,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const CP='<?=htmlspecialchars($_SERVER['PHP_SELF'],ENT_QUOTES)?>';

/* SIDEBAR */
function toggleSidebar(){
  const sb=document.getElementById('sidebar');
  const ov=document.getElementById('sbOverlay');
  const hm=document.getElementById('hamBtn');
  const isOpen=sb.classList.toggle('open');
  ov.classList.toggle('open',isOpen);
  hm.classList.toggle('open',isOpen);
  document.body.style.overflow=isOpen&&window.innerWidth<=768?'hidden':'';
}
function closeSidebar(){
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sbOverlay').classList.remove('open');
  document.getElementById('hamBtn').classList.remove('open');
  document.body.style.overflow='';
}
window.addEventListener('resize',()=>{if(window.innerWidth>768)closeSidebar();});

/* FILTER HELPERS */
function bUrl(c,p,r,cat,st){
  const u=new URL(location.href);u.search='';
  if(c)u.searchParams.set('filter_client',c);
  if(p)u.searchParams.set('filter_project',p);
  if(r)u.searchParams.set('filter_requirement',r);
  if(cat)u.searchParams.set('filter_category',cat);
  if(st)u.searchParams.set('filter_status',st);
  return u.toString();
}
function eh(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function chgClient(cid){
  const ps=document.getElementById('tbProject');
  ps.innerHTML='<option value="">Select Project</option>';
  document.getElementById('tbReq').innerHTML='<option value="">All Requirements</option>';
  ['tbProject','tbReq','tbCat','tbStatus'].forEach(id=>document.getElementById(id).disabled=true);
  setAdd('');
  if(cid){const f=PD.filter(p=>p.client_id==cid);f.forEach(p=>ps.innerHTML+=`<option value="${p.id}">${eh(p.name)}</option>`);ps.disabled=f.length===0;}
  location.href=bUrl(cid,'','','','');
}
function chgProject(pid){
  const rs=document.getElementById('tbReq'),cid=document.getElementById('tbClient').value;
  rs.innerHTML='<option value="">All Requirements</option>';
  rs.disabled=true;
  document.getElementById('tbCat').disabled=true;
  document.getElementById('tbStatus').disabled=true;
  setAdd('');
  if(!pid){location.href=bUrl(cid,'','','','');return;}
  location.href=bUrl(cid,pid,'','','');
}
function chgReq(req){
  const cid=document.getElementById('tbClient').value,
        pid=document.getElementById('tbProject').value,
        cat=document.getElementById('tbCat').value,
        st=document.getElementById('tbStatus').value;
  setAdd(pid);
  location.href=bUrl(cid,pid,req,cat,st);
}
function applyF(){
  const cid=document.getElementById('tbClient').value,
        pid=document.getElementById('tbProject').value,
        req=document.getElementById('tbReq').value,
        cat=document.getElementById('tbCat').value,
        st=document.getElementById('tbStatus').value;
  setAdd(pid);
  location.href=bUrl(cid,pid,req,cat,st);
}

function setAdd(pid){
  const b=document.getElementById('btnAdd');
  const reqVal=document.getElementById('tbReq').value;
  const isEnabled=pid&&reqVal;
  b.disabled=!isEnabled;
  if(isEnabled){b.title='Add test case';}
  else if(!pid){b.title='Select Client → Project first';}
  else{b.title='Select a Requirement to enable Add';}
}

(function(){
  const cv=document.getElementById('tbClient').value,
        pv=document.getElementById('tbProject').value,
        rv=document.getElementById('tbReq').value;
  if(!cv){['tbProject','tbReq','tbCat','tbStatus'].forEach(id=>document.getElementById(id).disabled=true);setAdd('');return;}
  document.getElementById('tbProject').disabled=false;
  if(!pv){['tbReq','tbCat','tbStatus'].forEach(id=>document.getElementById(id).disabled=true);setAdd('');return;}
  document.getElementById('tbCat').disabled=false;
  document.getElementById('tbStatus').disabled=false;
  setAdd(pv);
  const rs=document.getElementById('tbReq');
  if(rs.options.length>1){rs.disabled=false;return;}
  fetch(`${CP}?ajax_req=1&project_id=${pv}`).then(r=>r.json()).then(list=>{
    rs.innerHTML='<option value="">All Requirements</option>';
    if(list&&list.length){list.forEach(r=>{const s=r.title===rv?'selected':'';rs.innerHTML+=`<option value="${eh(r.title)}" ${s}>${eh(r.title)}</option>`;});rs.disabled=false;}
  }).catch(()=>{});
})();

/* PILL STATUS */
const PCLS={'Not tested':'pill-grey','Pass':'pill-green','Fail':'pill-red','Open':'pill-orange','In Progress':'pill-blue','Resolved':'pill-teal','Closed':'pill-dark','Reopen':'pill-red'};

function tpd(event,pill){
  event.stopPropagation();
  const dd=pill.nextElementSibling;
  document.querySelectorAll('.pd.open').forEach(d=>{if(d!==dd){d.classList.remove('open');d.style.top='';d.style.left='';}});
  if(dd.classList.contains('open')){dd.classList.remove('open');dd.style.top='';dd.style.left='';return;}
  const rect=pill.getBoundingClientRect();
  dd.style.top=(rect.bottom+5)+'px';
  dd.style.left=rect.left+'px';
  dd.classList.add('open');
  requestAnimationFrame(()=>{
    const dRect=dd.getBoundingClientRect();
    if(dRect.right>window.innerWidth-10) dd.style.left=Math.max(5,window.innerWidth-dRect.width-10)+'px';
    if(dRect.bottom>window.innerHeight-10) dd.style.top=Math.max(5,rect.top-dRect.height-5)+'px';
  });
}
document.addEventListener('click',function(e){
  if(!e.target.closest('.pw')&&!e.target.closest('.pd')) document.querySelectorAll('.pd.open').forEach(d=>{d.classList.remove('open');d.style.top='';d.style.left='';});
  if(!e.target.closest('.sidebar')&&!e.target.closest('#hamBtn')&&window.innerWidth<=768&&document.getElementById('sidebar').classList.contains('open')) closeSidebar();
});
document.addEventListener('scroll',()=>document.querySelectorAll('.pd.open').forEach(d=>{d.classList.remove('open');d.style.top='';d.style.left='';}),true);
window.addEventListener('resize',()=>document.querySelectorAll('.pd.open').forEach(d=>{d.classList.remove('open');d.style.top='';d.style.left='';}));

function chgStatus(item,id,val){
  const pw=item.closest('.pw'),dd=pw.querySelector('.pd'),pill=pw.querySelector('.sp');
  dd.classList.remove('open');dd.style.top='';dd.style.left='';
  const fd=new FormData();fd.append('ajax_action','update_status');fd.append('id',id);fd.append('status',val);
  fetch(CP,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
    if(res.success){
      toast('Status → '+val,'success');
      if(res.row_html){
        const ex=document.querySelector(`tr[data-id="${id}"]`);
        if(ex){
          const oldSn=ex.querySelector('td:first-child')?.textContent||'-';
          const t=document.createElement('tbody');t.innerHTML=res.row_html;const nr=t.firstElementChild;
          if(nr){const nc=nr.querySelector('td:first-child');if(nc)nc.textContent=oldSn;nr.classList.add('rnew');ex.replaceWith(nr);}
        }
      }
    } else toast(res.msg||'Failed','error');
  }).catch(()=>toast('Network error','error'));
}

function chgBug(item,id,val){
  const pw=item.closest('.pw'),dd=pw.querySelector('.pd'),pill=pw.querySelector('.sp');
  dd.classList.remove('open');dd.style.top='';dd.style.left='';
  const fd=new FormData();fd.append('ajax_action','update_bug_status');fd.append('id',id);fd.append('bug_status',val);
  fetch(CP,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
    if(res.success){
      toast('Bug → '+val,'success');
      if(res.row_html){
        const ex=document.querySelector(`tr[data-id="${id}"]`);
        if(ex){
          const oldSn=ex.querySelector('td:first-child')?.textContent||'-';
          const t=document.createElement('tbody');t.innerHTML=res.row_html;const nr=t.firstElementChild;
          if(nr){const nc=nr.querySelector('td:first-child');if(nc)nc.textContent=oldSn;nr.classList.add('rnew');ex.replaceWith(nr);}
        }
      }
    } else toast(res.msg||'Failed','error');
  }).catch(()=>toast('Network error','error'));
}

/* DETAIL MODAL */
let _curD=null;
function openDetailModal(d){
  _curD=d;
  const proj=PD.find(p=>p.id==d.project_id);
  const cli=CD.find(c=>c.id==(proj?proj.client_id:0));
  const e=s=>s?String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'):'\u2014';
  const yn=v=>`<span class="ynb ${v?'y':'n'}">${v?'\u2713 Yes':'\u2717 No'}</span>`;
  const ft=s=>s&&s!=='0000-00-00 00:00:00'?s:'\u2014';
  const SM={'Not tested':'pill-grey','Pass':'pill-green','Fail':'pill-red','Open':'pill-orange','In Progress':'pill-blue','Resolved':'pill-teal','Closed':'pill-dark','Reopen':'pill-red'};
  const spill=(v)=>`<span class="sp ${SM[v]||'pill-grey'}" style="cursor:default">${e(v)}</span>`;
  const pl=(d.pre_conditions||'').split('\n').map(l=>l.replace(/^\d+\.\s*/,'')).filter(l=>l.trim());
  const preH=pl.length>1?'<ul class="bl">'+pl.map(l=>`<li>${e(l)}</li>`).join('')+'</ul>':`<div class="dval">${e(d.pre_conditions)}</div>`;
  const al=(d.test_actions||'').split('\n').map(l=>l.replace(/^\d+\.\s*/,'')).filter(l=>l.trim());
  const actH=al.length>1?'<ol class="sl">'+al.map(l=>`<li>${e(l)}</li>`).join('')+'</ol>':`<div class="dval">${e(d.test_actions||'\u2014')}</div>`;
  const sc=sjson(d.bug_screenshots);
  const scJ=JSON.stringify(sc).replace(/"/g,'&quot;');
  const scH=sc.length?`<div class="sthumb">${sc.map((f,i)=>`<img src="../../uploads/bugs/${f}" onclick="openScrAt(${scJ},${i})" loading="lazy"/>`).join('')}</div>`:'<span class="dash-val">\u2014</span>';
  const vd=sjson(d.bug_videos);
  const vdJ=JSON.stringify(vd).replace(/"/g,'&quot;');
  const vdH=vd.length?`<div style="display:flex;gap:8px;flex-wrap:wrap">${vd.map((f,i)=>`<button class="btn-sc vbtn" onclick="openVidAt(${vdJ},${i})"><svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" style="margin-right:5px"><polygon points="5 3 19 12 5 21 5 3"/></svg>Video ${i+1}</button>`).join('')}</div>`:'<span class="dash-val">\u2014</span>';
  document.getElementById('detTitle').textContent=(d.tc_custom_id||'TC-'+d.id);
  document.getElementById('detBody').innerHTML=`
  <div class="dbanner">
    <div class="db-id-badge"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;opacity:.6"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>${e(d.tc_custom_id||'TC-'+d.id)}</div>
    <div class="db-title">${e(d.title)}</div>
    <div class="db-meta">
      ${d.page_name?`<span class="db-chip">${e(d.page_name)}</span>`:''}
      <span class="db-chip">${e(cli?.name||'\u2014')}</span>
      <span class="db-chip">${e(proj?.name||d.project_name||'\u2014')}</span>
      ${d.created_at?`<span class="db-chip">${ft(d.created_at)}</span>`:''}
    </div>
  </div>
  <div class="dgrid">
    <div>
      <div class="dcard"><div class="dct">Test Case Info</div>
        ${d.description?`<div class="dr"><div class="dlbl">Description</div><div class="dval">${e(d.description)}</div></div>`:''}
        ${d.pre_conditions?`<div class="dr"><div class="dlbl">Pre Conditions</div>${preH}</div>`:''}
        ${d.test_actions?`<div class="dr"><div class="dlbl">Test Actions</div>${actH}</div>`:''}
        <div class="dr"><div class="dlbl">Expected Result</div><div class="dval hi">${e(d.expected_result)}</div></div>
        ${d.actual_result?`<div class="dr"><div class="dlbl">Actual Result</div><div class="dval fb">${e(d.actual_result)}</div></div>`:''}
      </div>
      <div class="dcard"><div class="dct">Additional Info</div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);text-align:center;gap:10px">
          <div><div class="dlbl" style="margin-bottom:6px">Is Automated</div>${yn(d.is_automated)}</div>
          <div><div class="dlbl" style="margin-bottom:6px">Is Executed</div>${yn(d.is_executed)}</div>
          <div><div class="dlbl" style="margin-bottom:6px">Bug Raised</div>${yn(d.bug_raised)}</div>
        </div>
        ${d.comments?`<div class="dr" style="margin-top:12px"><div class="dlbl">Comments</div><div class="dval">${e(d.comments)}</div></div>`:''}
      </div>
      <div class="dcard"><div class="dct">Project Info</div>
        <div class="ipair"><span class="ipk">Project</span><span class="ipv">${e(proj?.name||d.project_name||'\u2014')}</span></div>
        <div class="ipair"><span class="ipk">Category</span><span class="ipv">${e(d.category||'\u2014')}</span></div>
        <div class="ipair"><span class="ipk">Requirement</span><span class="ipv">${e(d.requirement||'\u2014')}</span></div>
      </div>
      ${sc.length||vd.length?`<div class="dcard"><div class="dct">Bug Attachments</div>
        ${sc.length?`<div class="dr"><div class="dlbl">Screenshots (${sc.length})</div>${scH}</div>`:''}
        ${vd.length?`<div class="dr"><div class="dlbl">Videos (${vd.length})</div>${vdH}</div>`:''}
      </div>`:''}
    </div>
    <div>
      <div class="dcard"><div class="dct">Execution</div>
        <div class="ipair"><span class="ipk">Status</span><span class="ipv">${spill(d.status||'Not tested')}</span></div>
        <div class="ipair"><span class="ipk">Bug Status</span><span class="ipv">${d.bug_raised?spill(d.bug_status||'Open'):'<span class="dash-val">\u2014</span>'}</span></div>
        <div class="ipair"><span class="ipk">Assigned To</span><span class="ipv">${e(d.assigned_name||'\u2014')}</span></div>
        ${d.executed_name?`<div class="ipair"><span class="ipk">Executed By</span><span class="ipv">${e(d.executed_name)}</span></div>`:''}
        ${ft(d.executed_on)!=='\u2014'?`<div class="ipair"><span class="ipk">Executed On</span><span class="ipv" style="font-size:11px">${ft(d.executed_on)}</span></div>`:''}
      </div>
      <div class="dcard"><div class="dct">Meta Info</div>
        ${d.created_name?`<div class="ipair"><span class="ipk">Created By</span><span class="ipv">${e(d.created_name)}</span></div>`:''}
        ${ft(d.created_at)!=='\u2014'?`<div class="ipair"><span class="ipk">Created At</span><span class="ipv" style="font-size:11px">${ft(d.created_at)}</span></div>`:''}
        ${ft(d.updated_at)!=='\u2014'?`<div class="ipair"><span class="ipk">Updated At</span><span class="ipv" style="font-size:11px">${ft(d.updated_at)}</span></div>`:''}
      </div>
      <button class="bsave" style="width:100%;margin-top:8px" onclick="cDet();openEdit(_curD)">Edit This Case</button>
    </div>
  </div>`;
  const _detMo=document.getElementById('detMo');
  const _detBody=document.getElementById('detBody');
  _detMo.classList.add('open');
  document.body.style.overflow='hidden';
  if(_detBody) _detBody.scrollTop=0;
  _detMo.scrollTop=0;
  requestAnimationFrame(()=>{if(_detBody)_detBody.scrollTop=0;_detMo.scrollTop=0;});
}
function cDet(){document.getElementById('detMo').classList.remove('open');document.body.style.overflow='';}
function sjson(s){try{const r=JSON.parse(s);return Array.isArray(r)?r:[];}catch{return[];}}

/* ── LOAD DEVELOPERS FOR A PROJECT (from project mapping tables) ── */
function loadDevsForForm(pid, preSelect){
  const s=document.getElementById('fAssign');
  s.innerHTML='<option value="">— Loading… —</option>';
  s.disabled=true;
  if(!pid){s.innerHTML='<option value="">— Select Project First —</option>';s.disabled=true;return;}
  fetch(`${CP}?ajax_devs=1&project_id=${pid}`).then(r=>r.json()).then(data=>{
    s.innerHTML='<option value="">— Select —</option>';
    const list = Array.isArray(data) ? data : [];
    if(list.length){
      list.forEach(d=>{
        const o=document.createElement('option');
        o.value=d.id; o.textContent=d.name;
        if(preSelect&&d.id==preSelect) o.selected=true;
        s.appendChild(o);
      });
      s.disabled=false;
    }else{
      s.innerHTML='<option value="">No developers found for this project</option>'; s.disabled=true;
    }
  }).catch(()=>{s.innerHTML='<option value="">Error loading</option>';s.disabled=true;});
}

/* ADD / EDIT MODAL */
function scrollModalToTop(){const mo=document.getElementById('frmMo');if(mo)mo.scrollTop=0;const mbdy=document.getElementById('frmMbdy');if(mbdy)mbdy.scrollTop=0;}

function openAdd(){
  const pid=document.getElementById('tbProject').value,cid=document.getElementById('tbClient').value;
  const preReq='<?= addslashes($filter_requirement) ?>';
  if(!pid){toast('Select a project first','error');return;}
  const proj=PD.find(p=>p.id==pid),cli=CD.find(c=>c.id==cid)||CD.find(c=>c.id==(proj?proj.client_id:0));
  document.getElementById('frmTitle').innerText='Add Test Case';
  document.getElementById('tcForm').reset();
  document.getElementById('fAction').value='add';
  document.getElementById('fId').value='';
  document.getElementById('fPid').value=pid;
  document.getElementById('pbCli').innerText=cli?cli.name:'\u2014';
  document.getElementById('pbProj').innerText=proj?proj.name:'\u2014';
  document.getElementById('fDate').value='';
  document.getElementById('fTime').value='';
  ['execBlk','bugBlk'].forEach(id=>document.getElementById(id).classList.remove('active'));
  document.getElementById('exScrWrap').style.display='none';
  document.getElementById('exVidWrap').style.display='none';
  document.getElementById('fKeepScr').value='[]';
  document.getElementById('fKeepVid').value='[]';
  document.getElementById('fReqHidden').value='';
  loadReqsForForm(pid,preReq,true);
  loadDevsForForm(pid);
  oFrm();
  setTimeout(scrollModalToTop,50);
}

function openEdit(d){
  _curD=d;
  const proj=PD.find(p=>p.id==d.project_id),cli=CD.find(c=>c.id==(proj?proj.client_id:0));
  document.getElementById('frmTitle').innerText='Edit Test Case';
  document.getElementById('fAction').value='edit';
  document.getElementById('fId').value=d.id;
  document.getElementById('fPid').value=d.project_id||'';
  document.getElementById('pbCli').innerText=cli?cli.name:'\u2014';
  document.getElementById('pbProj').innerText=proj?proj.name:(d.project_name||'\u2014');
  document.getElementById('fTitle').value=d.title||'';
  document.getElementById('fPage').value=d.page_name||'';
  document.getElementById('fCat').value=d.category||'Functional';
  document.getElementById('fDesc').value=d.description||'';
  document.getElementById('fPre').value=d.pre_conditions||'';
  document.getElementById('fExp').value=d.expected_result||'';
  document.getElementById('fAct').value=d.actual_result||'';
  document.getElementById('fAssign').value=d.assigned_to||'';
  document.getElementById('fAuto').checked=!!parseInt(d.is_automated);
  document.getElementById('fCmt').value=d.comments||'';
  const ie=!!parseInt(d.is_executed);
  document.getElementById('fExec').checked=ie;
  document.getElementById('fActs').value=d.test_actions||'';
  if(d.executed_on&&d.executed_on!=='0000-00-00 00:00:00'){const p=d.executed_on.split(' ');document.getElementById('fDate').value=p[0]||'';document.getElementById('fTime').value=p[1]||'';}
  else{document.getElementById('fDate').value='';document.getElementById('fTime').value='';}
  document.getElementById('fStatus').value=d.status||'Not tested';
  document.getElementById('execBlk').classList.toggle('active',ie);
  const ib=!!parseInt(d.bug_raised);
  document.getElementById('fBug').checked=ib;
  document.getElementById('fBugSt').value=d.bug_status||'Open';
  document.getElementById('bugBlk').classList.toggle('active',ib);
  document.getElementById('fReqHidden').value=d.requirement||'';
  const scrWrap=document.getElementById('exScrWrap');
  const scrList=document.getElementById('exScrList');
  const exScr=sjson(d.bug_screenshots);
  document.getElementById('fKeepScr').value=JSON.stringify(exScr);
  if(exScr.length&&ib){
    document.getElementById('exScrCount').textContent=exScr.length;
    scrList.innerHTML=exScr.map((f,i)=>`
      <div class="ex-file-item" id="esci_${i}_${d.id}">
        <img src="../../uploads/bugs/${f}" class="ex-scr-thumb" onclick="oLImg('../../uploads/bugs/${f}')" title="${f}" loading="lazy"/>
        <button type="button" class="ex-file-del" onclick="delExFile('scr','${f}',${i},${d.id})" title="Remove">&#10005;</button>
      </div>`).join('');
    scrWrap.style.display='block';
  }else{scrWrap.style.display='none';scrList.innerHTML='';document.getElementById('fKeepScr').value='[]';}
  const vidWrap=document.getElementById('exVidWrap');
  const vidList=document.getElementById('exVidList');
  const exVid=sjson(d.bug_videos);
  document.getElementById('fKeepVid').value=JSON.stringify(exVid);
  if(exVid.length&&ib){
    document.getElementById('exVidCount').textContent=exVid.length;
    vidList.innerHTML=exVid.map((f,i)=>{
      const sn=f.length>22?f.substring(0,22)+'\u2026':f;
      return `<div class="ex-file-item" id="evidi_${i}_${d.id}">
        <button type="button" class="ex-vid-chip" onclick="oLVid('../../uploads/bug_videos/${f}')" title="Play: ${f}">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>${sn}
        </button>
        <button type="button" class="ex-file-del" onclick="delExFile('vid','${f}',${i},${d.id})" title="Remove">&#10005;</button>
      </div>`;
    }).join('');
    vidWrap.style.display='block';
  }else{vidWrap.style.display='none';vidList.innerHTML='';document.getElementById('fKeepVid').value='[]';}
  document.getElementById('fScrFile').value='';
  document.getElementById('fVidFile').value='';
  loadReqsForForm(d.project_id,d.requirement,true);
  loadDevsForForm(d.project_id, d.assigned_to);
  oFrm();
  setTimeout(scrollModalToTop,50);
}
window.openEditModal=openEdit;

function loadReqsForForm(pid,preSelect,lockIt){
  const s=document.getElementById('fReq');
  const hi=document.getElementById('fReqHidden');
  s.innerHTML='<option value="">— Loading… —</option>';
  s.disabled=true;
  s.style.cursor='wait';
  s.title='';
  if(!pid){s.innerHTML='<option value="">— Select Project First —</option>';s.disabled=true;return;}
  fetch(`${CP}?ajax_req=1&project_id=${pid}`).then(r=>r.json()).then(list=>{
    s.innerHTML='<option value="">— None —</option>';
    if(list&&list.length){
      list.forEach(r=>{
        const o=document.createElement('option');
        o.value=r.title;o.textContent=r.title;
        if(preSelect&&r.title===preSelect) o.selected=true;
        s.appendChild(o);
      });
      if(lockIt){s.disabled=true;s.style.cursor='not-allowed';s.title="Requirement locked based on filter selection";hi.value=s.value;}
      else{s.disabled=false;s.style.cursor='pointer';s.title="Select a requirement";}
    }else{s.innerHTML='<option value="">No Requirements</option>';s.disabled=true;}
  }).catch(()=>{s.innerHTML='<option value="">Error loading</option>';s.disabled=true;});
}

document.getElementById('fReq').addEventListener('change',function(e){document.getElementById('fReqHidden').value=this.value;});

function delExFile(type,fname,idx,tcId){
  if(type==='scr'){
    const item=document.getElementById(`esci_${idx}_${tcId}`);if(item)item.remove();
    const hi=document.getElementById('fKeepScr');let arr=JSON.parse(hi.value||'[]');arr=arr.filter(f=>f!==fname);hi.value=JSON.stringify(arr);
    const rem=document.getElementById('exScrList').children.length;document.getElementById('exScrCount').textContent=rem;
    if(!rem)document.getElementById('exScrWrap').style.display='none';
  }else{
    const item=document.getElementById(`evidi_${idx}_${tcId}`);if(item)item.remove();
    const hi=document.getElementById('fKeepVid');let arr=JSON.parse(hi.value||'[]');arr=arr.filter(f=>f!==fname);hi.value=JSON.stringify(arr);
    const rem=document.getElementById('exVidList').children.length;document.getElementById('exVidCount').textContent=rem;
    if(!rem)document.getElementById('exVidWrap').style.display='none';
  }
  toast('File removed — save to confirm','success');
}

function oFrm(){document.getElementById('frmMo').classList.add('open');document.body.style.overflow='hidden';}
function cFrm(){document.getElementById('frmMo').classList.remove('open');document.body.style.overflow='';}

/* IST TIME HELPERS */
function getISTDateTime(){
  const now=new Date();
  const istOffset=5.5*60*60*1000;
  const istTime=new Date(now.getTime()+istOffset);
  const y=istTime.getUTCFullYear();
  const m=String(istTime.getUTCMonth()+1).padStart(2,'0');
  const d=String(istTime.getUTCDate()).padStart(2,'0');
  const hh=String(istTime.getUTCHours()).padStart(2,'0');
  const mm=String(istTime.getUTCMinutes()).padStart(2,'0');
  const ss=String(istTime.getUTCSeconds()).padStart(2,'0');
  return{date:`${y}-${m}-${d}`,time:`${hh}:${mm}:${ss}`};
}

function togBlk(id,cb){
  document.getElementById(id).classList.toggle('active',cb.checked);
  if(cb.checked&&id==='execBlk'){
    const ist=getISTDateTime();
    if(!document.getElementById('fDate').value) document.getElementById('fDate').value=ist.date;
    if(!document.getElementById('fTime').value) document.getElementById('fTime').value=ist.time;
  }
  if(id==='bugBlk'){
    const editId=document.getElementById('fId').value;
    if(editId&&cb.checked){
      const keepScr=JSON.parse(document.getElementById('fKeepScr').value||'[]');
      const keepVid=JSON.parse(document.getElementById('fKeepVid').value||'[]');
      if(keepScr.length)document.getElementById('exScrWrap').style.display='block';
      if(keepVid.length)document.getElementById('exVidWrap').style.display='block';
    }else if(!cb.checked){
      document.getElementById('exScrWrap').style.display='none';
      document.getElementById('exVidWrap').style.display='none';
    }
  }
}

/* FORM SUBMIT */
function renumberRows(){
  const rows=document.querySelectorAll('#tcTbody tr[data-id]');
  rows.forEach((row,idx)=>{const c=row.querySelector('td:first-child');if(c)c.textContent=idx+1;});
}

document.getElementById('tcForm').addEventListener('submit',function(e){
  e.preventDefault();
  const isExecCb=document.getElementById('fExec');
  if(!isExecCb.checked){
    const crow=isExecCb.closest('.crow');
    crow.style.background='#fff5f5';
    crow.style.border='1.5px solid #f1948a';
    crow.style.borderRadius='8px';
    crow.style.padding='8px 12px';
    crow.style.transition='all .3s';
    isExecCb.focus();
    toast('"Is Executed" field is required — please check it','error');
    setTimeout(()=>{crow.style.background='';crow.style.border='';crow.style.padding='';},3000);
    return;
  }
  const reqSelect=document.getElementById('fReq');
  const reqHidden=document.getElementById('fReqHidden');
  reqHidden.value=reqSelect.value;
  const btn=document.getElementById('btnSave');btn.disabled=true;btn.textContent='Saving…';
  fetch(CP,{method:'POST',body:new FormData(this)}).then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.text();})
  .then(text=>{
    let res;try{const s=text.indexOf('{'),en=text.lastIndexOf('}');if(s===-1||en===-1)throw new Error('No JSON');res=JSON.parse(text.substring(s,en+1));}catch(pe){console.error('Raw:',text);throw new Error('Bad response: '+pe.message);}
    btn.disabled=false;btn.textContent='Save';
    if(!res.success){toast(res.msg||'Error','error');return;}
    cFrm();toast(res.msg,'success');
    const tb=document.getElementById('tcTbody');
    if(res.action==='add'){
      document.getElementById('emptyRow')?.remove();
      const t=document.createElement('tbody');t.innerHTML=res.row_html;const nr=t.firstElementChild;
      if(nr){nr.classList.add('rnew');tb.insertBefore(nr,tb.firstChild);}
      const tc=document.getElementById('totC');if(tc)tc.textContent=parseInt(tc.textContent||0)+1;
      renumberRows();
    }else if(res.action==='edit'){
      const ex=tb.querySelector(`tr[data-id="${res.edit_id}"]`);
      if(ex){
        const oldSn=ex.querySelector('td:first-child')?.textContent||'-';
        const t=document.createElement('tbody');t.innerHTML=res.row_html;const nr=t.firstElementChild;
        if(nr){
          const nc=nr.querySelector('td:first-child');if(nc)nc.textContent=oldSn;
          nr.classList.add('rnew');
          ex.replaceWith(nr);
          setTimeout(()=>{const updatedRow=document.querySelector(`tr[data-id="${res.edit_id}"]`);if(updatedRow)updatedRow.scrollIntoView({behavior:'smooth',block:'nearest'});},200);
        }
      }
    }
  }).catch(err=>{btn.disabled=false;btn.textContent='Save';toast('Error: '+err.message,'error');});
});

/* LIGHTBOX CAROUSEL */
let _carouselFiles=[],_carouselIdx=0,_carouselType='img';
function openScr(files){if(!files||!files.length)return;_carouselFiles=files.map(f=>'../../uploads/bugs/'+f);_carouselIdx=0;_carouselType='img';_openCarousel();}
function openVid(files){if(!files||!files.length)return;_carouselFiles=files.map(f=>'../../uploads/bug_videos/'+f);_carouselIdx=0;_carouselType='vid';_openCarousel();}
function openScrAt(files,idx){_carouselFiles=files.map(f=>'../../uploads/bugs/'+f);_carouselIdx=idx||0;_carouselType='img';_openCarousel();}
function openVidAt(files,idx){_carouselFiles=files.map(f=>'../../uploads/bug_videos/'+f);_carouselIdx=idx||0;_carouselType='vid';_openCarousel();}
function oLImg(src){_carouselFiles=[src];_carouselIdx=0;_carouselType='img';_openCarousel();}
function oLVid(src){_carouselFiles=[src];_carouselIdx=0;_carouselType='vid';_openCarousel();}
function _openCarousel(){_renderCarouselFrame();document.getElementById('lbox').classList.add('open');document.body.classList.add('lb-open');}
function _renderCarouselFrame(){
  const total=_carouselFiles.length,f=_carouselFiles[_carouselIdx],cont=document.getElementById('lbCont');
  const ov=cont.querySelector('video');if(ov){ov.pause();ov.src='';}
  if(_carouselType==='img')cont.innerHTML=`<img src="${f}" style="max-width:88vw;max-height:82vh;border-radius:10px;object-fit:contain;display:block"/>`;
  else cont.innerHTML=`<video src="${f}" controls autoplay style="max-width:88vw;max-height:82vh;border-radius:10px;display:block"></video>`;
  const prev=document.getElementById('lbPrev'),next=document.getElementById('lbNext'),counter=document.getElementById('lbCounter'),dots=document.getElementById('lbDots');
  if(total>1){
    prev.style.display='flex';next.style.display='flex';counter.style.display='block';counter.textContent=`${_carouselIdx+1} / ${total}`;
    if(total<=10)dots.innerHTML=Array.from({length:total},(_,i)=>`<button class="lb-dot ${i===_carouselIdx?'active':''}" onclick="carouselGoTo(${i})"></button>`).join('');
    else dots.innerHTML='';
  }else{prev.style.display='none';next.style.display='none';counter.style.display='none';dots.innerHTML='';}
}
function carouselNav(dir){const t=_carouselFiles.length;if(t<=1)return;_carouselIdx=(_carouselIdx+dir+t)%t;_renderCarouselFrame();}
function carouselGoTo(idx){_carouselIdx=idx;_renderCarouselFrame();}
function cLbox(){
  const cont=document.getElementById('lbCont');const v=cont.querySelector('video');if(v){v.pause();v.src='';}
  cont.innerHTML='';document.getElementById('lbox').classList.remove('open');document.body.classList.remove('lb-open');
  if(document.getElementById('detMo').classList.contains('open')){document.body.style.overflow='hidden';}
  document.getElementById('lbDots').innerHTML='';_carouselFiles=[];
}
function cLboxBg(e){if(e.target.id==='lbox')cLbox();}
(function(){let tsx=null;const lb=document.getElementById('lbox');lb.addEventListener('touchstart',e=>{tsx=e.touches[0].clientX;},{passive:true});lb.addEventListener('touchend',e=>{if(tsx===null)return;const dx=e.changedTouches[0].clientX-tsx;if(Math.abs(dx)>50)carouselNav(dx<0?1:-1);tsx=null;},{passive:true});})();

/* TOAST */
function toast(msg,type='success'){document.querySelector('.toast')?.remove();const t=document.createElement('div');t.className=`toast ${type}`;t.textContent=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),4000);}

/* MODAL CLOSE */
document.getElementById('frmMo').addEventListener('click',function(e){if(e.target===this)cFrm();});
document.getElementById('detMo').addEventListener('click',function(e){if(e.target===this)cDet();});
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){cLbox();cDet();cFrm();}
  if(document.getElementById('lbox').classList.contains('open')){if(e.key==='ArrowLeft')carouselNav(-1);if(e.key==='ArrowRight')carouselNav(1);}
});

/* DOWNLOAD TEMPLATE */
function downloadTemplate(){
  const url=CP+'?action=template';
  const a=document.createElement('a');a.href=url;a.download='test_cases_template.csv';
  document.body.appendChild(a);a.click();document.body.removeChild(a);
  toast('Template downloading...','success');
}
</script>
</body>
</html>