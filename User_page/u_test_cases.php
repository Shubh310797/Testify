<?php
// ── user_page/u_test_cases.php ─────────────────────────────
// USER VERSION: Sare clients dikhe, lekin sirf assigned projects dropdown mein
// Test Cases sirf user ke assigned projects ke liye dikhenge
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

$db_path = '../config/db.php';
if (!file_exists($db_path)) {
    die("<div style='background:#fee;padding:10px;border:1px solid red;'>DB config not found: $db_path</div>");
}
include $db_path;

if (!isset($conn) || $conn->connect_error) {
    $err = isset($conn) ? $conn->connect_error : 'Connection variable not set';
    die("<div style='background:#fee;padding:10px;border:1px solid red;'>DB Error: $err</div>");
}

// DB fallback for user ID
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

if (!$current_user_id) {
    header('Location: ../login.php');
    exit;
}

// ════════════════════════════════════════════════════════
//  FETCH USER'S ASSIGNED PROJECTS
// ════════════════════════════════════════════════════════
$user_projects = [];
$up_stmt = $conn->prepare(
    "SELECT DISTINCT p.id, p.client_id, p.name
     FROM projects p
     LEFT JOIN project_frontend_devs pfd ON p.id = pfd.project_id
     LEFT JOIN project_backend_devs pbd ON p.id = pbd.project_id
     LEFT JOIN project_qa_team pqt ON p.id = pqt.project_id
     WHERE p.project_lead_id = ?
        OR p.qa_lead_id = ?
        OR pfd.user_id = ?
        OR pbd.user_id = ?
        OR pqt.user_id = ?
     ORDER BY p.name ASC"
);
$up_stmt->bind_param('iiiii', $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id);
$up_stmt->execute();
$up_res = $up_stmt->get_result();
while ($r = $up_res->fetch_assoc()) $user_projects[] = $r;
$up_stmt->close();

$user_project_ids = array_column($user_projects, 'id');
$user_project_ids_list = !empty($user_project_ids) ? implode(',', array_map('intval', $user_project_ids)) : '0';

// ── AJAX: Requirements ──────────────────────────────────────
if (isset($_GET['ajax_req']) && isset($_GET['project_id'])) {
    $pid = (int)$_GET['project_id'];
    // Only allow if project is in user's assigned projects
    if (!in_array($pid, $user_project_ids)) {
        sendJson([]);
    }
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

// ════════════════════════════════════════════
//  EXPORT CSV HANDLER
// ════════════════════════════════════════════
if(isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['project_id'])) {
    $pid = (int)$_GET['project_id'];
    if (!in_array($pid, $user_project_ids)) { die('Unauthorized'); }

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
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="test_cases_export_'.date('Y-m-d').'.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['TC ID', 'Title', 'Page', 'Category', 'Requirement', 'Description', 'Pre-conditions', 'Expected Result', 'Actual Result', 'Test Actions', 'Status', 'Bug Status', 'Executed On', 'Comments']);
    
    while ($row = $res->fetch_assoc()) {
        fputcsv($output, $row);
    }
    fclose($output);
    $stmt->close();
    $conn->close();
    exit;
}

// ════════════════════════════════════════════
//  AJAX HANDLER
// ════════════════════════════════════════════
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        $action = trim($_POST['ajax_action']);

        // ── update_status ──
        if ($action === 'update_status') {
            $id = (int)$_POST['id'];
            $status = trim($_POST['status']);
            if ($id <= 0) sendJson(['success' => false, 'msg' => 'Invalid ID.']);
            if (!in_array($status, ['Not tested','Pass','Fail'])) sendJson(['success' => false, 'msg' => 'Invalid status.']);

            // Verify test case belongs to user's project
            $vfy = $conn->prepare("SELECT project_id FROM test_cases WHERE id=?");
            $vfy->bind_param('i', $id); $vfy->execute(); $vfy->bind_result($vpid); $vfy->fetch(); $vfy->close();
            if (!in_array($vpid, $user_project_ids)) sendJson(['success' => false, 'msg' => 'Unauthorized.']);
            
            if ($status === 'Not tested' || $status === 'Pass' || $status === 'Fail') {
                $executed_by = $current_user_id;
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

        // ── update_bug_status ──
        if ($action === 'update_bug_status') {
            $id = (int)$_POST['id'];
            $bug_status = trim($_POST['bug_status']);
            if ($id <= 0) sendJson(['success' => false, 'msg' => 'Invalid ID.']);
            if (!in_array($bug_status, ['Open','In Progress','Resolved','Closed','Reopen'])) sendJson(['success' => false, 'msg' => 'Invalid bug status.']);
            
            // Verify
            $vfy = $conn->prepare("SELECT project_id FROM test_cases WHERE id=?");
            $vfy->bind_param('i', $id); $vfy->execute(); $vfy->bind_result($vpid); $vfy->fetch(); $vfy->close();
            if (!in_array($vpid, $user_project_ids)) sendJson(['success' => false, 'msg' => 'Unauthorized.']);
            
            $updated_by = $current_user_id;
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

        // ── IMPORT CSV ──
        if ($action === 'import_csv') {
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                sendJson(['success' => false, 'msg' => 'File upload error.']);
            }
            $project_id = (int)$_POST['project_id'];
            if (!in_array($project_id, $user_project_ids)) sendJson(['success' => false, 'msg' => 'Unauthorized project.']);
            
            $created_by = $current_user_id;
            
            if (($handle = fopen($_FILES['import_file']['tmp_name'], "r")) !== FALSE) {
                fgetcsv($handle); 
                $imported = 0;
                $stmt = $conn->prepare("INSERT INTO test_cases (project_id, created_by, title, page_name, category, requirement, description, pre_conditions, expected_result, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $title = isset($data[1]) ? trim($data[1]) : 'Untitled';
                    $page = isset($data[2]) ? trim($data[2]) : '';
                    $cat = isset($data[3]) ? trim($data[3]) : 'Functional';
                    $req = isset($data[4]) ? trim($data[4]) : '';
                    $desc = isset($data[5]) ? trim($data[5]) : '';
                    $pre = isset($data[6]) ? trim($data[6]) : '';
                    $exp = isset($data[7]) ? trim($data[7]) : '';
                    $status = isset($data[10]) && in_array(trim($data[10]), ['Pass','Fail']) ? trim($data[10]) : 'Not tested';
                    
                    $stmt->bind_param('iissssssss', $project_id, $created_by, $title, $page, $cat, $req, $desc, $pre, $exp, $status);
                    if($stmt->execute()) $imported++;
                }
                fclose($handle);
                $stmt->close();
                sendJson(['success' => true, 'msg' => "Imported $imported test cases successfully."]);
            }
            sendJson(['success' => false, 'msg' => 'Failed to parse CSV.']);
        }

        // ── ADD ──
        if ($action === 'add') {
            if (empty($_POST['project_id'])) sendJson(['success' => false, 'msg' => 'Project is required.']);
            $project_id = (int)$_POST['project_id'];
            if (!in_array($project_id, $user_project_ids)) sendJson(['success' => false, 'msg' => 'Unauthorized project.']);

            $target_dir = "../../uploads/bugs/";
            $video_dir  = "../../uploads/bug_videos/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            if (!is_dir($video_dir))  mkdir($video_dir, 0777, true);

            $created_by   = $current_user_id;
            
            $tc_custom_id = trim($_POST['tc_custom_id'] ?? '');
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
            
            if ($is_executed && empty($date_part)) {
                $executed_on = date('Y-m-d H:i:s');
            } elseif (!empty($date_part)) {
                $time_part = !empty($time_part) ? $time_part : '00:00:00';
                $executed_on = "$date_part $time_part";
            } else {
                $executed_on = null;
            }

            $status       = trim($_POST['status'] ?? 'Not tested');
            $bug_status   = trim($_POST['bug_status'] ?? 'Open');
            $assigned_to  = (!empty($_POST['assigned_to']) && (int)$_POST['assigned_to'] > 0) ? (int)$_POST['assigned_to'] : null;
            $comments     = trim($_POST['comments'] ?? '');
            $bug_raised   = isset($_POST['bug_raised'])   ? 1 : 0;
            $is_automated = isset($_POST['is_automated']) ? 1 : 0;

            $executed_by_id = $is_executed ? $current_user_id : null;

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
            $vid_json = json_encode($vids);

            $sql = "INSERT INTO test_cases (
                project_id, created_by, tc_custom_id, title, page_name, category, requirement, description,
                pre_conditions, expected_result, actual_result, is_executed,
                test_actions, executed_on, status, bug_raised, bug_screenshots, bug_videos,
                bug_status, assigned_to, executed_by_id, is_automated, comments
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Prepare Error: " . $conn->error);

            $stmt->bind_param('ssssssssissiisssiiisi',
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

        // ── EDIT ──
        elseif ($action === 'edit') {
            $edit_id = (int)$_POST['edit_id'];
            if ($edit_id <= 0) sendJson(['success' => false, 'msg' => 'Invalid ID.']);

            // Verify ownership
            $vfy = $conn->prepare("SELECT project_id FROM test_cases WHERE id=?");
            $vfy->bind_param('i', $edit_id); $vfy->execute(); $vfy->bind_result($vpid); $vfy->fetch(); $vfy->close();
            if (!in_array($vpid, $user_project_ids)) sendJson(['success' => false, 'msg' => 'Unauthorized.']);
            
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
            
            $is_executed  = isset($_POST['is_executed'])  ? 1 : 0;
            $status       = trim($_POST['status'] ?? 'Not tested');
            $bug_status   = trim($_POST['bug_status'] ?? 'Open');
            $assigned_to  = (!empty($_POST['assigned_to']) && (int)$_POST['assigned_to'] > 0) ? (int)$_POST['assigned_to'] : null;
            $comments     = trim($_POST['comments'] ?? '');
            $bug_raised   = isset($_POST['bug_raised'])   ? 1 : 0;
            $is_automated = isset($_POST['is_automated']) ? 1 : 0;

            $executed_by_id = $current_user_id;
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

            $stmt->bind_param('ssssssssisssissssiisi',
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

        // ── DELETE ──
        elseif ($action === 'delete') {
            $del_id = (int)$_POST['del_id'];
            if ($del_id <= 0) sendJson(['success' => false, 'msg' => 'Invalid ID.']);
            // Verify
            $vfy = $conn->prepare("SELECT project_id FROM test_cases WHERE id=?");
            $vfy->bind_param('i', $del_id); $vfy->execute(); $vfy->bind_result($vpid); $vfy->fetch(); $vfy->close();
            if (!in_array($vpid, $user_project_ids)) sendJson(['success' => false, 'msg' => 'Unauthorized.']);
            
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
// All clients — user can see all
$clients_list = [];
$c_res = $conn->query("SELECT id, name FROM clients WHERE status='active' ORDER BY name");
if ($c_res) while ($r = $c_res->fetch_assoc()) $clients_list[] = $r;

// Only user's assigned projects
$projects_list = $user_projects;

// Users for dropdown (developers)
$users_list = [];
$u_res = $conn->query("SELECT id, name FROM users WHERE role='Developer' ORDER BY name");
if ($u_res) while ($r = $u_res->fetch_assoc()) $users_list[] = $r;

$requirements_list  = [];
$filter_client      = (int)($_GET['filter_client']      ?? 0);
$filter_project     = (int)($_GET['filter_project']     ?? 0);
$filter_requirement = trim($_GET['filter_requirement']  ?? '');
$filter_category    = trim($_GET['filter_category']     ?? '');
$filter_status      = trim($_GET['filter_status']       ?? '');

if ($filter_project && in_array($filter_project, $user_project_ids)) {
    $rs = $conn->prepare("SELECT id, title FROM requirements WHERE project_id=? ORDER BY title ASC");
    $rs->bind_param('i', $filter_project); $rs->execute();
    $rr = $rs->get_result();
    while ($r = $rr->fetch_assoc()) $requirements_list[] = $r;
    $rs->close();
}

// Fetch test cases — ONLY from user's assigned projects
$test_cases=[]; $total=0; $per_page=10; $page=1; $total_pages=1;
if ($filter_project && in_array($filter_project, $user_project_ids)) {
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
//  ROW HELPER (same as admin version)
// ════════════════════════════════════════════
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
        $scBtn = "<button class='btn-sc' onclick=\"openScr($scrJson)\">
            <svg width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' style='margin-right:4px'><rect x='3' y='3' width='18' height='18' rx='2'/><circle cx='8.5' cy='8.5' r='1.5'/><polyline points='21 15 16 10 5 21'/></svg>
            $scrCount</button>";
    } else {
        $scBtn = '<span class="dash-val">—</span>';
    }

    if (count($vids) > 0) {
        $vidCount = count($vids);
        $vBtn = "<button class='btn-sc vbtn' onclick=\"openVid($vidJson)\">
            <svg width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' style='margin-right:4px'><polygon points='5 3 19 12 5 21 5 3'/></svg>
            $vidCount</button>";
    } else {
        $vBtn = '<span class="dash-val">—</span>';
    }

    $assignedName = htmlspecialchars($d['assigned_name'] ?? '');
    $assignedCell = $assignedName
        ? "<div class='info-cell' style='align-items:center'><span class='ic-name'>&#128100; $assignedName</span></div>"
        : '<span class="dash-val">—</span>';

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

    return "
    <tr data-id='$id'>
        <td>$sn</td>
        <td class='tc-id'>$tcId</td>
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
            <svg fill='none' stroke='currentColor' stroke-width='2' width='14' height='14' viewBox='0 0 24 24'><path d='M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7'/><path d='M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z'/></svg>
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
    return "<div class='pw'><span class='sp $cls' onclick='tpd(event,this)'>".htmlspecialchars($cur)."<svg width='9' height='9' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='3'><polyline points='6 9 12 15 18 9'/></svg></span><div class='pd'>$items</div></div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>TestiFy — My Test Cases</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=Poppins:wght@700;800&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --b1:#3a7bd5;--b2:#4a90d9;--b3:#4facfe;
  --tx:#2d3a5e;--mu:#6b7fa3;--br:#dde4f0;
  --wh:#fff;--bg:#f4f7fb;--rd:#e74c3c;--gn:#27ae60;
  --dg:#f0f2f5;--dt:#aab2c8;
  --sb-w:240px;--nb-h:60px;
}
body{min-height:100vh;background:var(--bg);font-family:'Nunito',sans-serif;color:var(--tx);overflow-x:hidden}

.navbar{background:var(--wh);border-bottom:1.5px solid var(--br);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:var(--nb-h);position:fixed;top:0;left:0;right:0;z-index:300;box-shadow:0 2px 16px rgba(74,144,217,.09)}
.nblogo{font-family:'Poppins',sans-serif;font-weight:800;font-size:20px;background:linear-gradient(90deg,var(--b1),var(--b3));-webkit-background-clip:text;-webkit-text-fill-color:transparent;text-decoration:none;white-space:nowrap}
.nb-right{display:flex;align-items:center;gap:10px;flex-shrink:0}
.ham{display:none;flex-direction:column;justify-content:center;gap:5px;width:40px;height:40px;border:none;background:transparent;cursor:pointer;padding:8px;border-radius:8px;transition:.2s}
.ham:hover{background:#eef4fd}
.ham span{display:block;height:2px;border-radius:2px;background:var(--tx);transition:.3s}
.ham.open span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.ham.open span:nth-child(2){opacity:0;transform:scaleX(0)}
.ham.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}
.nb-user{font-size:13px;color:var(--mu);font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:8px}
.nb-role{font-size:11px;font-weight:700;color:#1565c0;background:#e3f2fd;padding:2px 8px;border-radius:6px;text-transform:capitalize;letter-spacing:.5px;white-space:nowrap}
.blgout{padding:7px 14px;border-radius:8px;border:1.5px solid var(--b2);color:var(--b2);text-decoration:none;font-weight:700;font-size:13px;white-space:nowrap;transition:.2s}
.blgout:hover{background:var(--b2);color:#fff}

.sidebar{position:fixed;top:var(--nb-h);left:0;bottom:0;width:var(--sb-w);background:var(--wh);border-right:1.5px solid var(--br);z-index:250;overflow-y:auto;overflow-x:hidden;transition:transform .28s cubic-bezier(.4,0,.2,1);box-shadow:2px 0 20px rgba(74,144,217,.06);padding-bottom:24px}
.sidebar-overlay{display:none;position:fixed;inset:0;top:var(--nb-h);background:rgba(30,45,80,.4);z-index:240;backdrop-filter:blur(2px)}
.sidebar-overlay.open{display:block}
.sb-section{padding:18px 14px 6px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--mu)}
.sb-link{display:flex;align-items:center;gap:10px;padding:9px 16px;margin:1px 8px;border-radius:10px;text-decoration:none;color:var(--tx);font-size:13.5px;font-weight:600;transition:.15s;position:relative}
.sb-link:hover{background:#eef4fd;color:var(--b1)}
.sb-link.active{background:linear-gradient(90deg,#e8f0fd,#f0f7ff);color:var(--b1);font-weight:700}
.sb-link.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;border-radius:0 3px 3px 0;background:var(--b1);margin-left:-8px}
.sb-link svg{width:16px;height:16px;flex-shrink:0;opacity:.7}
.sb-link.active svg{opacity:1}
.sb-home{display:flex;align-items:center;gap:10px;padding:12px 16px;margin:8px 8px 2px;border-radius:10px;text-decoration:none;color:var(--tx);font-size:13.5px;font-weight:700;transition:.15s;background:#f8faff;border:1px solid var(--br)}
.sb-home:hover{background:#eef4fd;color:var(--b1)}
.sb-home svg{width:16px;height:16px;opacity:.7}

.page-wrap{margin-left:var(--sb-w);margin-top:var(--nb-h);min-height:calc(100vh - var(--nb-h));transition:margin-left .28s cubic-bezier(.4,0,.2,1)}
.main{max-width:1400px;margin:0 auto;padding:24px 20px 80px}

.ptitle{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.ptitle h1{font-family:'Poppins',sans-serif;font-weight:800;font-size:24px}
.pbadge{background:linear-gradient(90deg,var(--b1),var(--b3));color:#fff;font-family:'Poppins',sans-serif;font-weight:700;font-size:10px;letter-spacing:2px;text-transform:uppercase;padding:5px 12px;border-radius:6px}

.toolbar{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap}
.tleft{display:flex;align-items:center;gap:6px;flex-wrap:wrap;flex:1}
.filter-row{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.fs{height:36px;padding:0 10px;border-radius:8px;border:1.5px solid var(--br);background:var(--wh);font-family:'Nunito',sans-serif;font-size:13px;color:var(--tx);outline:none;cursor:pointer;min-width:130px;max-width:180px}
.fs:disabled{background:var(--dg);color:var(--dt);cursor:not-allowed;opacity:.6}
.sarr{color:var(--mu);font-size:16px;line-height:36px;flex-shrink:0}
.btnadd{height:36px;padding:0 18px;border-radius:8px;border:none;background:linear-gradient(90deg,var(--b1),var(--b3));color:#fff;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:.2s;white-space:nowrap;flex-shrink:0}
.btnadd:disabled{opacity:.35;cursor:not-allowed}
.btnadd:hover:not(:disabled){opacity:.9}
.btn-imp{background:#6c757d;border-color:#5a6268}
.btn-imp:hover{background:#5a6268}

.tcard{background:var(--wh);border-radius:16px;border:1.5px solid var(--br);box-shadow:0 4px 20px rgba(74,144,217,.07);overflow:hidden}
.tscroll{overflow:auto;max-height:65vh}
table{width:100%;border-collapse:collapse;min-width:1400px}
thead th{background:#f0f5fd;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:var(--mu);text-transform:uppercase;border-bottom:1.5px solid var(--br);white-space:nowrap;letter-spacing:.4px;position:sticky;top:0;z-index:10}
tbody tr{border-bottom:1px solid var(--br);transition:background .15s}
tbody tr:hover{background:#f5f8fe}
tbody td{padding:9px 12px;font-size:13px;vertical-align:middle}
.tc-id{font-family:monospace;font-weight:700;color:var(--b1);white-space:nowrap}
.tc-title{font-weight:700;color:var(--b1);cursor:pointer;max-width:200px;display:block;text-decoration:underline;text-decoration-color:rgba(74,144,217,.25);transition:.15s}
.tc-title:hover{color:var(--b2);text-decoration-color:var(--b2)}
.cc{text-align:center}
.yn{font-size:15px;font-weight:900}
.yn.yes{color:var(--gn)}
.yn.no{color:var(--rd)}
.dash-val{color:var(--mu);font-size:12px}
.btn-sc{background:#17a2b8;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center}
.btn-sc.vbtn{background:#6c757d}
.btn-sc:hover{opacity:.85}
.btn-icon{width:30px;height:30px;border-radius:8px;border:1.5px solid var(--br);background:var(--wh);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:.15s;color:var(--b2)}
.btn-icon:hover{background:#f5f8fe}
.tfoot{padding:12px 16px;border-top:1px solid var(--br);display:flex;align-items:center;justify-content:space-between;color:var(--mu);font-size:12px;flex-wrap:wrap;gap:8px}
.pgn{display:flex;gap:4px;flex-wrap:wrap}
.pgb{min-width:30px;height:30px;padding:0 8px;border-radius:7px;border:1.5px solid var(--br);background:var(--wh);text-decoration:none;display:inline-flex;align-items:center;justify-content:center;color:var(--mu);font-weight:700;font-size:12px}
.pgb.active{background:var(--b1);color:#fff;border-color:var(--b1)}
.est{text-align:center;padding:50px 20px;color:var(--mu)}

.pw{position:relative;display:inline-block}
.sp{display:inline-flex;align-items:center;gap:5px;padding:4px 9px 4px 11px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer;user-select:none;white-space:nowrap;border:1.5px solid transparent;transition:.12s}
.sp:hover{filter:brightness(.94)}
.sp svg{opacity:.65;flex-shrink:0}
.pill-grey  {background:#e8e8ee;color:#444;border-color:#d0d0d8}
.pill-green {background:#d5f5e3;color:#1a7a3c;border-color:#a9dfbf}
.pill-red   {background:#fadbd8;color:#a93226;border-color:#f1948a}
.pill-orange{background:#fdebd0;color:#b7520a;border-color:#f0b27a}
.pill-blue  {background:#d6eaf8;color:#1a5e8a;border-color:#85c1e9}
.pill-teal  {background:#d1f2eb;color:#0e7863;border-color:#76d7c4}
.pill-dark  {background:#e4e4e4;color:#333;border-color:#ccc}
.pd{position:fixed;background:var(--wh);border:1.5px solid var(--br);border-radius:12px;box-shadow:0 8px 28px rgba(30,45,80,.14);z-index:10000;min-width:155px;padding:5px 0;display:none}
.pd.open{display:block;animation:dIn .13s ease}
@keyframes dIn{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:translateY(0)}}
.pdi{display:flex;align-items:center;gap:9px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;transition:background .1s}
.pdi:hover{background:#f0f5fd}
.pdd{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.pchk{margin-left:auto;font-size:12px;color:var(--b1);font-weight:800}

.mo{display:none;position:fixed;inset:0;background:rgba(30,45,80,.5);backdrop-filter:blur(3px);z-index:500;overflow-y:auto;padding:20px 12px}
.mo.open{display:flex;align-items:center;justify-content:center}
.modal{background:var(--wh);border-radius:16px;width:100%;max-width:820px;margin:auto;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(30,45,80,.22)}
.modal form{display:flex;flex-direction:column;flex:1;min-height:0}
.mhdr{padding:18px 20px;border-bottom:1.5px solid var(--br);display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.mhdr h2{font-size:17px;font-weight:800;color:var(--tx)}
.mcls{width:32px;height:32px;border-radius:8px;border:none;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--mu);font-size:20px}
.mcls:hover{background:#f0f0f0}
.mbdy{padding:20px;flex:1;min-height:0;overflow-y:auto}
.mftr{padding:14px 20px;border-top:1.5px solid var(--br);display:flex;justify-content:flex-end;align-items:center;gap:10px;flex-shrink:0}

.dgrid{display:grid;grid-template-columns:1fr 270px;gap:14px;align-items:start}
@media(max-width:700px){.dgrid{grid-template-columns:1fr}}
.dcard{background:#f8faff;border:1px solid var(--br);border-radius:12px;padding:14px;margin-bottom:10px}
.dcard:last-child{margin-bottom:0}
.dct{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--mu);margin-bottom:10px}
.dr{margin-bottom:10px}
.dr:last-child{margin-bottom:0}
.dlbl{font-size:11px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px}
.dval{font-size:13px;color:var(--tx);line-height:1.55}
.dval.hi{background:var(--wh);border:1px solid var(--br);border-radius:8px;padding:8px 12px}
.dval.fb{background:#fff5f5;border:1px solid #fdd;border-radius:8px;padding:8px 12px}
.ipair{display:flex;justify-content:space-between;align-items:flex-start;padding:7px 0;border-bottom:1px solid #eef}
.ipair:last-child{border-bottom:none}
.ipk{font-size:12px;font-weight:700;color:var(--mu)}
.ipv{font-size:12px;font-weight:700;color:var(--tx);text-align:right}
.ynb{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800}
.ynb.y{background:#d5f5e3;color:#1e8449}
.ynb.n{background:#fadbd8;color:#c0392b}
.dbanner{background:linear-gradient(135deg,#eef4fd,#f0f8ff);border:1.5px solid #c8dff8;border-radius:12px;padding:14px 18px;margin-bottom:16px}
.db-id{font-family:monospace;font-weight:700;font-size:14px;color:var(--b1);margin-bottom:3px}
.db-title{font-family:'Poppins',sans-serif;font-weight:800;font-size:16px;color:var(--tx);margin-bottom:7px}
.db-meta{display:flex;gap:10px;flex-wrap:wrap}
.db-chip{font-size:12px;color:var(--mu);font-weight:600;display:flex;align-items:center;gap:4px}
.sl{list-style:none;padding:0;counter-reset:s}
.sl li{counter-increment:s;padding:7px 0 7px 33px;position:relative;font-size:13px;border-bottom:1px solid #eef}
.sl li:last-child{border-bottom:none}
.sl li::before{content:counter(s);position:absolute;left:0;top:7px;width:21px;height:21px;border-radius:50%;background:linear-gradient(135deg,var(--b1),var(--b3));color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif}
.bl{list-style:none;padding:0}
.bl li{padding:5px 0 5px 16px;position:relative;font-size:13px;border-bottom:1px solid #eef}
.bl li:last-child{border-bottom:none}
.bl li::before{content:'\2022';position:absolute;left:2px;color:var(--b2);font-size:17px;line-height:1.1}
.sthumb{display:grid;grid-template-columns:repeat(auto-fill,minmax(70px,1fr));gap:6px}
.sthumb img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:6px;border:1px solid var(--br);cursor:pointer;transition:transform .2s}
.sthumb img:hover{transform:scale(1.06)}

.pbanner{display:flex;align-items:center;gap:16px;background:#eef4fd;border:1.5px solid #c5d9f5;border-radius:10px;padding:12px 16px;margin-bottom:20px;flex-wrap:wrap}
.pb-lbl{font-size:11px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.6px;margin-bottom:2px}
.pb-val{font-size:13px;font-weight:700;color:var(--b1)}
.pb-sep{width:1px;height:28px;background:var(--br);flex-shrink:0}
.fg{margin-bottom:16px}
.fg label{display:block;font-size:13px;font-weight:700;margin-bottom:6px;color:var(--tx)}
.req{color:var(--rd)}
.fc{width:100%;padding:9px 12px;border-radius:8px;border:1.5px solid var(--br);font-family:'Nunito',sans-serif;font-size:13px;color:var(--tx);outline:none;background:#fff;transition:.2s}
.fc:focus{border-color:var(--b2);box-shadow:0 0 0 3px rgba(74,144,217,.1)}
.fc:disabled{background:var(--dg);color:var(--dt);cursor:not-allowed}
textarea.fc{resize:vertical;min-height:78px}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.divider{border:none;border-top:1px solid var(--br);margin:16px 0}
.fb2{border:1px solid var(--br);border-radius:10px;padding:14px;margin-bottom:14px;display:none;background:#fafbff}
.fb2.active{display:block;animation:sd .25s ease}
@keyframes sd{from{opacity:0;transform:translateY(-7px)}to{opacity:1;transform:translateY(0)}}
.crow{display:flex;align-items:center;gap:9px;margin-bottom:12px}
.crow input[type=checkbox]{width:17px;height:17px;accent-color:var(--b1);cursor:pointer;flex-shrink:0}
.crow label{font-size:14px;font-weight:700;color:var(--tx);cursor:pointer;margin:0}
.fbox{border:1.5px dashed var(--br);border-radius:8px;padding:10px;background:#fcfcfc}
.fbox:hover{border-color:var(--b2);background:#f0f7ff}
.fbox input[type=file]{font-size:13px;font-family:'Nunito',sans-serif;width:100%}
.fhint{font-size:11px;color:var(--mu);margin-top:5px}
.dtrow{display:flex;gap:10px}
.bsave{padding:9px 28px;border-radius:9px;border:none;background:linear-gradient(90deg,var(--b1),var(--b3));color:#fff;font-weight:700;font-size:14px;cursor:pointer}
.bsave:disabled{opacity:.6;cursor:not-allowed}
.bcancel{padding:9px 22px;border-radius:9px;border:1.5px solid var(--br);background:#fff;color:var(--mu);font-weight:700;font-size:14px;cursor:pointer}

.ex-wrap{background:#f5f8fe;border:1.5px solid var(--br);border-radius:10px;padding:12px 14px;margin-bottom:10px}
.ex-lbl{font-size:11px;font-weight:800;color:var(--mu);text-transform:uppercase;letter-spacing:.6px;margin-bottom:9px;display:flex;align-items:center;gap:6px}
.ex-lbl::before{content:'';display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--gn)}
.ex-files{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start}
.ex-file-item{position:relative;display:inline-block}
.ex-scr-thumb{width:72px;height:54px;object-fit:cover;border-radius:7px;border:2px solid var(--br);cursor:pointer;display:block;transition:transform .2s,border-color .2s}
.ex-scr-thumb:hover{transform:scale(1.07);border-color:var(--b2)}
.ex-vid-chip{background:#6c757d;color:#fff;border:none;border-radius:7px;padding:6px 10px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;font-family:'Nunito',sans-serif;max-width:140px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.ex-vid-chip:hover{background:#5a6268}
.ex-file-del{position:absolute;top:-5px;right:-5px;width:17px;height:17px;border-radius:50%;background:var(--rd);color:#fff;border:2px solid #fff;font-size:9px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-weight:900;box-shadow:0 2px 6px rgba(231,76,60,.4);transition:background .15s;z-index:2;line-height:1;padding:0}
.ex-file-del:hover{background:#c0392b}
.ex-count{background:var(--b1);color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:800;margin-left:auto}

.toast{position:fixed;bottom:24px;right:20px;z-index:9999;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:700;box-shadow:0 8px 28px rgba(30,45,80,.18);display:flex;align-items:center;gap:10px;animation:su .3s ease;max-width:320px}
.toast.success{background:#e8f8f0;color:#1e8449;border-left:5px solid #a9dfbf}
.toast.error{background:#fde8e8;color:#c0392b;border-left:5px solid #f1948a}
@keyframes su{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
@keyframes rf{0%{background:#fff9c4}100%{background:transparent}}
.rnew{animation:rf 1.8s ease forwards}

body.lb-open{overflow:hidden}
.lbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:9000;align-items:center;justify-content:center;padding:20px}
.lbox.open{display:flex}
.lbox img,.lbox video{max-width:88vw;max-height:80vh;border-radius:10px;object-fit:contain;display:block}
.lbcls{position:fixed;top:16px;right:18px;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:24px;cursor:pointer;border-radius:50%;width:42px;height:42px;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);transition:.2s;z-index:9010}
.lbcls:hover{background:rgba(255,255,255,.28)}
.lb-nav{position:fixed;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:28px;cursor:pointer;border-radius:50%;width:48px;height:48px;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);transition:.2s;z-index:9010;line-height:1}
.lb-nav:hover{background:rgba(255,255,255,.30)}
.lb-nav.prev{left:14px}
.lb-nav.next{right:14px}
.lb-counter{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);color:#fff;font-size:13px;font-weight:700;background:rgba(0,0,0,.5);padding:4px 14px;border-radius:20px;z-index:9010;white-space:nowrap;font-family:'Nunito',sans-serif}
.lb-dots{position:fixed;bottom:52px;left:50%;transform:translateX(-50%);display:flex;gap:6px;z-index:9010}
.lb-dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.35);border:none;cursor:pointer;padding:0;transition:.2s}
.lb-dot.active{background:#fff;transform:scale(1.25)}
#lbCont{position:relative;display:flex;align-items:center;justify-content:center}

.info-cell{display:flex;flex-direction:column;gap:3px;min-width:120px}
.ic-name{font-size:12px;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px}
.ic-date{font-size:11px;color:var(--b1);font-weight:600;white-space:nowrap}
.ic-time{font-size:11px;color:var(--mu);white-space:nowrap}

.hide-mobile{display:table-cell}

@media(max-width:900px){
  :root{--sb-w:240px}
  .ham{display:flex}
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .page-wrap{margin-left:0}
  .nb-role{font-size:10px;padding:2px 6px}
}
@media(max-width:768px){
  :root{--sb-w:280px;--nb-h:56px}
  .navbar{padding:0 12px}
  .nblogo{font-size:18px}
  .nb-user span:not(.nb-role){display:none}
  .nb-role{display:none}
  .blgout{padding:6px 10px;font-size:12px}
  .sidebar{width:var(--sb-w);transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay{display:none}
  .sidebar-overlay.open{display:block}
  .page-wrap{margin-left:0}
  .main{padding:14px 10px 80px}
  .ptitle h1{font-size:19px}
  .pbadge{font-size:9px;padding:4px 9px}
  .toolbar{flex-direction:column;align-items:stretch;gap:8px}
  .tleft{flex-direction:column;align-items:stretch}
  .filter-row{flex-direction:column;align-items:stretch;gap:6px}
  .fs{min-width:0;max-width:100%;width:100%;height:40px;font-size:13px}
  .sarr{display:none}
  .toolbar>div:last-child{display:flex;flex-wrap:wrap;gap:6px}
  .btnadd{flex:1;min-width:calc(50% - 4px);justify-content:center;height:38px}
  .tcard{border-radius:12px}
  .tscroll{overflow-x:auto;max-height:60vh;-webkit-overflow-scrolling:touch}
  table{min-width:900px}
  .hide-mobile{display:none!important}
  thead th{padding:8px 10px;font-size:10px}
  tbody td{padding:8px 10px;font-size:12px}
  .sp{padding:3px 7px 3px 9px;font-size:11px}
  .tfoot{flex-direction:column;align-items:flex-start;gap:8px;padding:10px 12px}
  .pgn{flex-wrap:wrap;gap:3px}
  .pgb{min-width:28px;height:28px;font-size:11px}
  .mo{padding:0;align-items:flex-end;padding-top:60px}
  .modal{border-radius:20px 20px 0 0;max-width:100%;width:100%;max-height:92vh;animation:slideUpMo .3s cubic-bezier(.4,0,.2,1)}
  @keyframes slideUpMo{from{transform:translateY(100%);opacity:.6}to{transform:translateY(0);opacity:1}}
  .mhdr{padding:12px 16px 10px;position:sticky;top:0;z-index:10;border-radius:20px 20px 0 0}
  .mhdr h2{font-size:16px}
  .mbdy{flex:1;padding:14px 16px;overflow-y:auto}
  .mftr{padding:10px 14px;position:sticky;bottom:0}
  .frow{grid-template-columns:1fr}
  .dtrow{flex-direction:column;gap:8px}
  .dgrid{grid-template-columns:1fr}
  .fc{padding:10px 12px;font-size:14px}
  .fg label{font-size:13px}
  .fg{margin-bottom:14px}
  .pbanner{gap:8px;padding:10px 12px}
  .pb-sep{display:none}
  .mftr{gap:8px}
  .bsave{flex:1;text-align:center;padding:11px 16px;font-size:14px}
  .bcancel{flex:1;text-align:center;padding:11px 16px;font-size:14px}
  .toast{bottom:12px;right:10px;left:10px;max-width:none;font-size:13px}
  .lbox{padding:12px}
  .lbox img,.lbox video{max-width:95vw;max-height:75vh}
  .lb-nav.prev{left:4px}
  .lb-nav.next{right:4px}
  .lb-nav{width:38px;height:38px;font-size:22px}
  .lbcls{top:10px;right:10px;width:36px;height:36px}
  .btn-icon{width:34px;height:34px}
  .btn-sc{padding:5px 10px}
  .dbanner{padding:12px 14px}
  .db-title{font-size:14px}
  .ic-name{max-width:120px}
  .ex-wrap{padding:10px 12px}
  .ex-scr-thumb{width:62px;height:46px}
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

<div class="sidebar-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR — User Pages -->
<aside class="sidebar" id="sidebar">
  <a href="../dash/user_dash.php" class="sb-home">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    Home
  </a>
  <div class="sb-section">Pages</div>
  <a href="../user_page/u_project.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
    My Projects
  </a>
  <a href="../user_page/u_requirement.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    Requirements
  </a>
  <a href="../user_page/u_test_plans.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    Test Plans
  </a>
  <a href="../user_page/u_test_cases.php" class="sb-link active">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    Test Cases
  </a>
  <a href="../user_page/u_project_assignment.php" class="sb-link">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
    Assignment Requests
  </a>
</aside>

<!-- PAGE WRAP -->
<div class="page-wrap" id="pageWrap">
<div class="main">

  <div class="ptitle">
    <h1>My Test Cases</h1>
    <span class="pbadge">Execution</span>
    <?php if($filter_status !== ''): ?>
      <span style="font-size:12px;font-weight:700;color:var(--b1);background:#eef4fd;border:1.5px solid #c5d9f5;padding:4px 12px;border-radius:20px;display:flex;align-items:center;gap:6px;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
        Status: <?= htmlspecialchars($filter_status) ?>
        <a href="?<?= http_build_query(array_filter(['filter_client'=>$filter_client,'filter_project'=>$filter_project,'filter_requirement'=>$filter_requirement,'filter_category'=>$filter_category],fn($v)=>$v!==''&&$v!==0)) ?>" style="color:var(--rd);text-decoration:none;font-size:14px;line-height:1" title="Clear status filter">&#10005;</a>
      </span>
    <?php endif; ?>
  </div>

  <div class="toolbar">
    <div class="tleft">
      <div class="filter-row">
        <!-- ALL CLIENTS — user can see and select any client -->
        <select class="fs" id="tbClient" onchange="chgClient(this.value)">
          <option value="">Select Client</option>
          <?php foreach($clients_list as $c): ?><option value="<?=$c['id']?>" <?=$filter_client==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?>
        </select>
        <span class="sarr">&#8250;</span>
        <!-- ONLY ASSIGNED PROJECTS — filtered by selected client -->
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
    <div style="display:flex;gap:8px">
      <?php if($filter_project): ?>
      <button class="btnadd btn-imp" onclick="openImportModal()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Import
      </button>
      <button class="btnadd btn-imp" onclick="window.location.href='?export=csv&<?=http_build_query($_GET) ?>'">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Export
      </button>
      <?php endif; ?>
      <button class="btnadd" id="btnAdd" onclick="openAdd()" disabled title="Select Client → Project → Requirement first">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Test Case
      </button>
    </div>
  </div>

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
    <div class="tfoot">
      <span>Showing <?=$total>0?($page-1)*$per_page+1:0?>&ndash;<?=min($page*$per_page,$total)?> of <span id="totC"><?=$total?></span></span>
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
        <?php if($page>1): ?><a href="?page=<?=$page-1?><?=$qs?>" class="pgb">&lsaquo;</a><?php endif; ?>
        <?php for($i=1;$i<=$total_pages;$i++): ?><a href="?page=<?=$i?><?=$qs?>" class="pgb <?=$i==$page?'active':''?>"><?=$i?></a><?php endfor; ?>
        <?php if($page<$total_pages): ?><a href="?page=<?=$page+1?><?=$qs?>" class="pgb">&rsaquo;</a><?php endif; ?>
      </div>
    </div>
  </div>

</div>
</div>

<!-- Detail Modal -->
<div class="mo" id="detMo">
  <div class="modal" style="max-width:900px">
    <div class="mhdr"><h2 id="detTitle">Test Case Details</h2><button class="mcls" onclick="cDet()">&#10005;</button></div>
    <div class="mbdy" id="detBody"></div>
    <div class="mftr"><button class="bcancel" onclick="cDet()">Close</button></div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="mo" id="frmMo">
  <div class="modal">
    <div class="mhdr"><h2 id="frmTitle">Add Test Case</h2><button class="mcls" onclick="cFrm()">&#10005;</button></div>
    <form id="tcForm" enctype="multipart/form-data">
      <input type="hidden" name="ajax_action" id="fAction" value="add"/>
      <input type="hidden" name="edit_id" id="fId"/>
      <input type="hidden" name="project_id" id="fPid"/>
      <input type="hidden" name="requirement_hidden" id="fReqHidden" value=""/>
      <div class="mbdy" id="frmMbdy">
        <div class="pbanner">
          <div><div class="pb-lbl">Client</div><div class="pb-val" id="pbCli">&mdash;</div></div>
          <div class="pb-sep"></div>
          <div><div class="pb-lbl">Project</div><div class="pb-val" id="pbProj">&mdash;</div></div>
        </div>
        <div class="frow">
          <div class="fg"><label>TC ID</label><input type="text" name="tc_custom_id" id="fTcId" class="fc" placeholder="TC-036"/></div>
          <div class="fg"><label>Page Name <span class="req">*</span></label><input type="text" name="page_name" id="fPage" class="fc" required placeholder="Login, Dashboard..."/></div>
        </div>
        <div class="fg"><label>Title <span class="req">*</span></label><input type="text" name="title" id="fTitle" class="fc" required placeholder="Enter test case title"/></div>
        <div class="frow">
          <div class="fg"><label>Category</label><select name="category" id="fCat" class="fc"><?php foreach(['Functional','Non-Functional','UI','Security','Performance','Regression'] as $c): ?><option><?=$c?></option><?php endforeach; ?></select></div>
          <div class="fg"><label>Requirement</label><select name="requirement" id="fReq" class="fc"><option value="">— None —</option></select></div>
        </div>
        <div class="fg"><label>Description</label><textarea name="description" id="fDesc" class="fc" placeholder="Describe test scenario"></textarea></div>
        <div class="fg"><label>Pre Conditions</label><textarea name="pre_conditions" id="fPre" class="fc" placeholder="Prerequisites"></textarea></div>
        <hr class="divider"/>
        <div class="fg"><label>Expected Result <span class="req">*</span></label><textarea name="expected_result" id="fExp" class="fc" required placeholder="Expected outcome"></textarea></div>
        <div class="fg"><label>Actual Result</label><textarea name="actual_result" id="fAct" class="fc" placeholder="What actually happened?"></textarea></div>
        <hr class="divider"/>
        <div class="crow"><input type="checkbox" name="is_executed" id="fExec" onchange="togBlk('execBlk',this)"/><label for="fExec">Is Executed</label></div>
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
          <div class="fg"><label>Assigned To <small style="color:var(--mu);font-weight:400">(Developer)</small></label><select name="assigned_to" id="fAssign" class="fc"><option value="">— Select —</option><?php foreach($users_list as $u): ?><option value="<?=$u['id']?>"><?=htmlspecialchars($u['name'])?></option><?php endforeach; ?></select></div>
          <div class="fg" style="padding-top:28px"><div class="crow"><input type="checkbox" name="is_automated" id="fAuto"/><label for="fAuto">Is Automated</label></div></div>
        </div>
        <div class="fg"><label>Comments</label><textarea name="comments" id="fCmt" class="fc" placeholder="Additional notes"></textarea></div>
      </div>
      <div class="mftr"><button type="submit" class="bsave" id="btnSave">Save</button><button type="button" class="bcancel" onclick="cFrm()">Cancel</button></div>
    </form>
  </div>
</div>

<!-- Import Modal -->
<div class="mo" id="impMo">
  <div class="modal" style="max-width:500px">
    <div class="mhdr"><h2>Import CSV</h2><button class="mcls" onclick="cImp()">&#10005;</button></div>
    <form id="impForm">
      <input type="hidden" name="ajax_action" value="import_csv"/>
      <input type="hidden" name="project_id" id="impPid"/>
      <div class="mbdy">
        <div class="fg">
          <label>Upload CSV File</label>
          <div class="fbox"><input type="file" name="import_file" accept=".csv" required/></div>
          <p class="fhint">File must contain header: TC ID, Title, Page, Category, Requirement, Description, Pre-conditions, Expected Result, Actual Result, Test Actions, Status, Bug Status, Executed On, Comments</p>
        </div>
      </div>
      <div class="mftr"><button type="submit" class="bsave" id="btnImp">Import</button><button type="button" class="bcancel" onclick="cImp()">Cancel</button></div>
    </form>
  </div>
</div>

<!-- Lightbox -->
<div class="lbox" id="lbox" onclick="cLboxBg(event)">
  <button class="lbcls" onclick="cLbox()">&#10005;</button>
  <div id="lbCont"></div>
  <button class="lb-nav prev" id="lbPrev" onclick="carouselNav(-1)" style="display:none">&#8249;</button>
  <button class="lb-nav next" id="lbNext" onclick="carouselNav(1)" style="display:none">&#8250;</button>
  <div class="lb-counter" id="lbCounter" style="display:none"></div>
  <div class="lb-dots" id="lbDots"></div>
</div>

<script>
// ── User's assigned projects (only these in project dropdown) ──
const PD=<?=json_encode($projects_list,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
// ── ALL clients (user can see all) ──
const CD=<?=json_encode($clients_list,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const CP='<?=htmlspecialchars($_SERVER['PHP_SELF'],ENT_QUOTES)?>';

// ══ SIDEBAR ══════════════════════════════════
function toggleSidebar(){
  const sb=document.getElementById('sidebar'),ov=document.getElementById('sbOverlay'),hm=document.getElementById('hamBtn');
  const isOpen=sb.classList.toggle('open');ov.classList.toggle('open',isOpen);hm.classList.toggle('open',isOpen);
  document.body.style.overflow=isOpen&&window.innerWidth<=768?'hidden':'';
}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sbOverlay').classList.remove('open');document.getElementById('hamBtn').classList.remove('open');document.body.style.overflow='';}
window.addEventListener('resize',()=>{if(window.innerWidth>768)closeSidebar();});

// ══ FILTER HELPERS ════════════════════════════
function bUrl(c,p,r,cat,st){
  const u=new URL(location.href);u.search='';
  if(c)u.searchParams.set('filter_client',c);if(p)u.searchParams.set('filter_project',p);
  if(r)u.searchParams.set('filter_requirement',r);if(cat)u.searchParams.set('filter_category',cat);
  if(st)u.searchParams.set('filter_status',st);return u.toString();
}
function eh(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── Client change: populate ONLY user's assigned projects for that client ──
function chgClient(cid){
  const ps=document.getElementById('tbProject');
  ps.innerHTML='<option value="">Select Project</option>';
  document.getElementById('tbReq').innerHTML='<option value="">All Requirements</option>';
  ['tbProject','tbReq','tbCat','tbStatus'].forEach(id=>document.getElementById(id).disabled=true);
  setAdd('');
  // PD already contains ONLY user's assigned projects
  if(cid){const f=PD.filter(p=>p.client_id==cid);f.forEach(p=>ps.innerHTML+=`<option value="${p.id}">${eh(p.name)}</option>`);ps.disabled=f.length===0;}
  location.href=bUrl(cid,'','','','');
}
function chgProject(pid){
  const rs=document.getElementById('tbReq'),cid=document.getElementById('tbClient').value;
  rs.innerHTML='<option value="">All Requirements</option>';rs.disabled=true;
  document.getElementById('tbCat').disabled=true;document.getElementById('tbStatus').disabled=true;
  setAdd('');
  if(!pid){location.href=bUrl(cid,'','','','');return;}
  fetch(`${CP}?ajax_req=1&project_id=${pid}`)
    .then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
    .then(list=>{
      if(list&&list.length){list.forEach(r=>rs.innerHTML+=`<option value="${eh(r.title)}">${eh(r.title)}</option>`);rs.disabled=false;}
      document.getElementById('tbCat').disabled=false;document.getElementById('tbStatus').disabled=false;
      setAdd(pid);
    }).catch(e=>{console.error(e);location.href=bUrl(cid,pid,'','','');});
}
function chgReq(req){
  const cid=document.getElementById('tbClient').value,pid=document.getElementById('tbProject').value;
  const cat=document.getElementById('tbCat').value,st=document.getElementById('tbStatus').value;
  setAdd(pid);location.href=bUrl(cid,pid,req,cat,st);
}
function applyF(){
  const cid=document.getElementById('tbClient').value,pid=document.getElementById('tbProject').value;
  const req=document.getElementById('tbReq').value,cat=document.getElementById('tbCat').value,st=document.getElementById('tbStatus').value;
  setAdd(pid);location.href=bUrl(cid,pid,req,cat,st);
}

function setAdd(pid){
  const b=document.getElementById('btnAdd'),reqVal=document.getElementById('tbReq').value;
  b.disabled=!(pid&&reqVal);
  b.title=b.disabled?(!pid?'Select Client → Project first':'Select a Requirement to enable Add'):'Add test case';
}

(function(){
  const cv=document.getElementById('tbClient').value,pv=document.getElementById('tbProject').value;
  if(!cv){['tbProject','tbReq','tbCat','tbStatus'].forEach(id=>document.getElementById(id).disabled=true);setAdd('');return;}
  document.getElementById('tbProject').disabled=false;
  if(!pv){['tbReq','tbCat','tbStatus'].forEach(id=>document.getElementById(id).disabled=true);setAdd('');return;}
  document.getElementById('tbCat').disabled=false;document.getElementById('tbStatus').disabled=false;
  setAdd(pv);
  const rs=document.getElementById('tbReq'),rv='<?=htmlspecialchars($filter_requirement,ENT_QUOTES)?>';
  if(rs.options.length>1){rs.disabled=false;return;}
  fetch(`${CP}?ajax_req=1&project_id=${pv}`).then(r=>r.json()).then(list=>{
    rs.innerHTML='<option value="">All Requirements</option>';
    if(list&&list.length){list.forEach(r=>{const s=r.title===rv?'selected':'';rs.innerHTML+=`<option value="${eh(r.title)}" ${s}>${eh(r.title)}</option>`;});rs.disabled=false;}
  }).catch(()=>{});
})();

// ══ PILL STATUS ═══════════════════════════════
function tpd(event,pill){
  event.stopPropagation();const dd=pill.nextElementSibling;
  document.querySelectorAll('.pd.open').forEach(d=>{if(d!==dd){d.classList.remove('open');d.style.top='';d.style.left='';}});
  if(dd.classList.contains('open')){dd.classList.remove('open');dd.style.top='';dd.style.left='';return;}
  const rect=pill.getBoundingClientRect();dd.style.top=(rect.bottom+5)+'px';dd.style.left=rect.left+'px';dd.classList.add('open');
  requestAnimationFrame(()=>{const dRect=dd.getBoundingClientRect();if(dRect.right>window.innerWidth-10)dd.style.left=Math.max(5,window.innerWidth-dRect.width-10)+'px';if(dRect.bottom>window.innerHeight-10)dd.style.top=Math.max(5,rect.top-dRect.height-5)+'px';});
}
document.addEventListener('click',function(e){if(!e.target.closest('.pw')&&!e.target.closest('.pd'))document.querySelectorAll('.pd.open').forEach(d=>{d.classList.remove('open');d.style.top='';d.style.left='';});});
document.addEventListener('scroll',()=>document.querySelectorAll('.pd.open').forEach(d=>{d.classList.remove('open');d.style.top='';d.style.left='';}),true);
window.addEventListener('resize',()=>document.querySelectorAll('.pd.open').forEach(d=>{d.classList.remove('open');d.style.top='';d.style.left='';}));

function chgStatus(item,id,val){
  const pw=item.closest('.pw'),dd=pw.querySelector('.pd'),pill=pw.querySelector('.sp');
  dd.classList.remove('open');dd.style.top='';dd.style.left='';
  const fd=new FormData();fd.append('ajax_action','update_status');fd.append('id',id);fd.append('status',val);
  fetch(CP,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
    if(res.success){toast('Status → '+val,'success');if(res.row_html){const ex=document.querySelector(`tr[data-id="${id}"]`);if(ex){const oldSn=ex.querySelector('td:first-child')?.textContent||'-';const t=document.createElement('tbody');t.innerHTML=res.row_html;const nr=t.firstElementChild;if(nr){const nc=nr.querySelector('td:first-child');if(nc)nc.textContent=oldSn;nr.classList.add('rnew');ex.replaceWith(nr);}}}}else toast(res.msg||'Failed','error');
  }).catch(()=>toast('Network error','error'));
}

function chgBug(item,id,val){
  const pw=item.closest('.pw'),dd=pw.querySelector('.pd'),pill=pw.querySelector('.sp');
  dd.classList.remove('open');dd.style.top='';dd.style.left='';
  const fd=new FormData();fd.append('ajax_action','update_bug_status');fd.append('id',id);fd.append('bug_status',val);
  fetch(CP,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
    if(res.success){toast('Bug → '+val,'success');if(res.row_html){const ex=document.querySelector(`tr[data-id="${id}"]`);if(ex){const oldSn=ex.querySelector('td:first-child')?.textContent||'-';const t=document.createElement('tbody');t.innerHTML=res.row_html;const nr=t.firstElementChild;if(nr){const nc=nr.querySelector('td:first-child');if(nc)nc.textContent=oldSn;nr.classList.add('rnew');ex.replaceWith(nr);}}}}else toast(res.msg||'Failed','error');
  }).catch(()=>toast('Network error','error'));
}

// ══ DETAIL MODAL ══════════════════════════════
let _curD=null;
function openDetailModal(d){
  _curD=d;
  const proj=PD.find(p=>p.id==d.project_id),cli=CD.find(c=>c.id==(proj?proj.client_id:0));
  const e=s=>s?String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'):'\u2014';
  const yn=v=>`<span class="ynb ${v?'y':'n'}">${v?'\u2713 Yes':'\u2717 No'}</span>`;
  const ft=s=>s&&s!=='0000-00-00 00:00:00'?s:'\u2014';
  const SM={'Not tested':'pill-grey','Pass':'pill-green','Fail':'pill-red','Open':'pill-orange','In Progress':'pill-blue','Resolved':'pill-teal','Closed':'pill-dark','Reopen':'pill-red'};
  const spill=(v)=>`<span class="sp ${SM[v]||'pill-grey'}" style="cursor:default">${e(v)}</span>`;
  const pl=(d.pre_conditions||'').split('\n').map(l=>l.replace(/^\d+\.\s*/,'')).filter(l=>l.trim());
  const preH=pl.length>1?'<ul class="bl">'+pl.map(l=>`<li>${e(l)}</li>`).join('')+'</ul>':`<div class="dval">${e(d.pre_conditions)}</div>`;
  const al=(d.test_actions||'').split('\n').map(l=>l.replace(/^\d+\.\s*/,'')).filter(l=>l.trim());
  const actH=al.length>1?'<ol class="sl">'+al.map(l=>`<li>${e(l)}</li>`).join('')+'</ol>':`<div class="dval">${e(d.test_actions||'\u2014')}</div>`;
  const sc=sjson(d.bug_screenshots),vd=sjson(d.bug_videos);
  const scH=sc.length?`<div class="sthumb">${sc.map((f,i)=>`<img src="../../uploads/bugs/${f}" onclick="openScrAt(${JSON.stringify(sc)},${i})" loading="lazy"/>`).join('')}</div>`:'<span class="dash-val">\u2014</span>';
  const vdH=vd.length?`<div style="display:flex;gap:8px;flex-wrap:wrap">${vd.map((f,i)=>`<button class="btn-sc vbtn" onclick="openVidAt(${JSON.stringify(vd)},${i})"><svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" style="margin-right:5px"><polygon points="5 3 19 12 5 21 5 3"/></svg>Video ${i+1}</button>`).join('')}</div>`:'<span class="dash-val">\u2014</span>';
  document.getElementById('detTitle').textContent=(d.tc_custom_id||'TC-'+d.id);
  document.getElementById('detBody').innerHTML=`
  <div class="dbanner"><div class="db-id">${e(d.tc_custom_id||'TC-'+d.id)}</div><div class="db-title">${e(d.title)}</div><div class="db-meta">${d.page_name?`<span class="db-chip">${e(d.page_name)}</span>`:''}<span class="db-chip">${e(cli?.name||'\u2014')}</span><span class="db-chip">${e(proj?.name||d.project_name||'\u2014')}</span></div></div>
  <div class="dgrid"><div>
    <div class="dcard"><div class="dct">Test Case Info</div>${d.description?`<div class="dr"><div class="dlbl">Description</div><div class="dval">${e(d.description)}</div></div>`:''}${d.pre_conditions?`<div class="dr"><div class="dlbl">Pre Conditions</div>${preH}</div>`:''}${d.test_actions?`<div class="dr"><div class="dlbl">Test Actions</div>${actH}</div>`:''}<div class="dr"><div class="dlbl">Expected Result</div><div class="dval hi">${e(d.expected_result)}</div></div>${d.actual_result?`<div class="dr"><div class="dlbl">Actual Result</div><div class="dval fb">${e(d.actual_result)}</div></div>`:''}</div>
    <div class="dcard"><div class="dct">Additional Info</div><div style="display:grid;grid-template-columns:repeat(3,1fr);text-align:center;gap:10px"><div><div class="dlbl" style="margin-bottom:6px">Is Automated</div>${yn(d.is_automated)}</div><div><div class="dlbl" style="margin-bottom:6px">Is Executed</div>${yn(d.is_executed)}</div><div><div class="dlbl" style="margin-bottom:6px">Bug Raised</div>${yn(d.bug_raised)}</div></div>${d.comments?`<div class="dr" style="margin-top:12px"><div class="dlbl">Comments</div><div class="dval">${e(d.comments)}</div></div>`:''}</div>
    <div class="dcard"><div class="dct">Project Info</div><div class="ipair"><span class="ipk">Project</span><span class="ipv">${e(proj?.name||d.project_name||'\u2014')}</span></div><div class="ipair"><span class="ipk">Category</span><span class="ipv">${e(d.category||'\u2014')}</span></div><div class="ipair"><span class="ipk">Requirement</span><span class="ipv">${e(d.requirement||'\u2014')}</span></div></div>
    ${sc.length||vd.length?`<div class="dcard"><div class="dct">Bug Attachments</div>${sc.length?`<div class="dr"><div class="dlbl">Screenshots (${sc.length})</div>${scH}</div>`:''}${vd.length?`<div class="dr"><div class="dlbl">Videos (${vd.length})</div>${vdH}</div>`:''}</div>`:''}</div>
  <div>
    <div class="dcard"><div class="dct">Execution</div><div class="ipair"><span class="ipk">Status</span><span class="ipv">${spill(d.status||'Not tested')}</span></div><div class="ipair"><span class="ipk">Bug Status</span><span class="ipv">${d.bug_raised?spill(d.bug_status||'Open'):'<span class="dash-val">\u2014</span>'}</span></div><div class="ipair"><span class="ipk">Assigned To</span><span class="ipv">${e(d.assigned_name||'\u2014')}</span></div>${d.executed_name?`<div class="ipair"><span class="ipk">Executed By</span><span class="ipv">${e(d.executed_name)}</span></div>`:''}${ft(d.executed_on)!=='\u2014'?`<div class="ipair"><span class="ipk">Executed On</span><span class="ipv" style="font-size:11px">${ft(d.executed_on)}</span></div>`:''}</div>
    <div class="dcard"><div class="dct">Meta Info</div>${d.created_name?`<div class="ipair"><span class="ipk">Created By</span><span class="ipv">${e(d.created_name)}</span></div>`:''}${ft(d.created_at)!=='\u2014'?`<div class="ipair"><span class="ipk">Created At</span><span class="ipv" style="font-size:11px">${ft(d.created_at)}</span></div>`:''}</div>
    <button class="bsave" style="width:100%;margin-top:8px" onclick="cDet();openEdit(_curD)">Edit This Case</button>
  </div></div>`;
  document.getElementById('detMo').classList.add('open');document.body.style.overflow='hidden';
  const _detBody=document.getElementById('detBody');if(_detBody)_detBody.scrollTop=0;
  document.getElementById('detMo').scrollTop=0;
}
function cDet(){document.getElementById('detMo').classList.remove('open');document.body.style.overflow='';}
function sjson(s){try{const r=JSON.parse(s);return Array.isArray(r)?r:[];}catch{return[];}}

// ══ ADD / EDIT MODAL ══════════════════════════
function scrollModalToTop(){const mo=document.getElementById('frmMo');if(mo)mo.scrollTop=0;const mbdy=document.getElementById('frmMbdy');if(mbdy)mbdy.scrollTop=0;}

function openAdd(){
  const pid=document.getElementById('tbProject').value,cid=document.getElementById('tbClient').value;
  const preReq='<?= addslashes($filter_requirement) ?>';
  if(!pid){toast('Select a project first','error');return;}
  const proj=PD.find(p=>p.id==pid),cli=CD.find(c=>c.id==cid)||CD.find(c=>c.id==(proj?proj.client_id:0));
  document.getElementById('frmTitle').innerText='Add Test Case';
  document.getElementById('tcForm').reset();
  document.getElementById('fAction').value='add';document.getElementById('fId').value='';document.getElementById('fPid').value=pid;
  document.getElementById('pbCli').innerText=cli?cli.name:'\u2014';document.getElementById('pbProj').innerText=proj?proj.name:'\u2014';
  document.getElementById('fDate').value='';document.getElementById('fTime').value='';
  ['execBlk','bugBlk'].forEach(id=>document.getElementById(id).classList.remove('active'));
  document.getElementById('exScrWrap').style.display='none';document.getElementById('exVidWrap').style.display='none';
  document.getElementById('fKeepScr').value='[]';document.getElementById('fKeepVid').value='[]';document.getElementById('fReqHidden').value='';
  loadReqsForForm(pid,preReq,true);
  oFrm();setTimeout(scrollModalToTop,50);
}

function openEdit(d){
  _curD=d;
  const proj=PD.find(p=>p.id==d.project_id),cli=CD.find(c=>c.id==(proj?proj.client_id:0));
  document.getElementById('frmTitle').innerText='Edit Test Case';
  document.getElementById('fAction').value='edit';document.getElementById('fId').value=d.id;document.getElementById('fPid').value=d.project_id||'';
  document.getElementById('pbCli').innerText=cli?cli.name:'\u2014';document.getElementById('pbProj').innerText=proj?proj.name:(d.project_name||'\u2014');
  document.getElementById('fTcId').value=d.tc_custom_id||'';document.getElementById('fTitle').value=d.title||'';
  document.getElementById('fPage').value=d.page_name||'';document.getElementById('fCat').value=d.category||'Functional';
  document.getElementById('fDesc').value=d.description||'';document.getElementById('fPre').value=d.pre_conditions||'';
  document.getElementById('fExp').value=d.expected_result||'';document.getElementById('fAct').value=d.actual_result||'';
  document.getElementById('fAssign').value=d.assigned_to||'';document.getElementById('fAuto').checked=!!parseInt(d.is_automated);
  document.getElementById('fCmt').value=d.comments||'';
  const ie=!!parseInt(d.is_executed);document.getElementById('fExec').checked=ie;document.getElementById('fActs').value=d.test_actions||'';
  if(d.executed_on&&d.executed_on!=='0000-00-00 00:00:00'){const p=d.executed_on.split(' ');document.getElementById('fDate').value=p[0]||'';document.getElementById('fTime').value=p[1]||'';}else{document.getElementById('fDate').value='';document.getElementById('fTime').value='';}
  document.getElementById('fStatus').value=d.status||'Not tested';document.getElementById('execBlk').classList.toggle('active',ie);
  const ib=!!parseInt(d.bug_raised);document.getElementById('fBug').checked=ib;document.getElementById('fBugSt').value=d.bug_status||'Open';document.getElementById('bugBlk').classList.toggle('active',ib);
  document.getElementById('fReqHidden').value=d.requirement||'';

  const scrWrap=document.getElementById('exScrWrap'),scrList=document.getElementById('exScrList'),exScr=sjson(d.bug_screenshots);
  document.getElementById('fKeepScr').value=JSON.stringify(exScr);
  if(exScr.length&&ib){document.getElementById('exScrCount').textContent=exScr.length;scrList.innerHTML=exScr.map((f,i)=>`<div class="ex-file-item" id="esci_${i}_${d.id}"><img src="../../uploads/bugs/${f}" class="ex-scr-thumb" onclick="oLImg('../../uploads/bugs/${f}')" title="${f}" loading="lazy"/><button type="button" class="ex-file-del" onclick="delExFile('scr','${f}',${i},${d.id})" title="Remove">&#10005;</button></div>`).join('');scrWrap.style.display='block';}else{scrWrap.style.display='none';scrList.innerHTML='';document.getElementById('fKeepScr').value='[]';}

  const vidWrap=document.getElementById('exVidWrap'),vidList=document.getElementById('exVidList'),exVid=sjson(d.bug_videos);
  document.getElementById('fKeepVid').value=JSON.stringify(exVid);
  if(exVid.length&&ib){document.getElementById('exVidCount').textContent=exVid.length;vidList.innerHTML=exVid.map((f,i)=>{const sn=f.length>22?f.substring(0,22)+'\u2026':f;return `<div class="ex-file-item" id="evidi_${i}_${d.id}"><button type="button" class="ex-vid-chip" onclick="oLVid('../../uploads/bug_videos/${f}')" title="Play: ${f}"><svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>${sn}</button><button type="button" class="ex-file-del" onclick="delExFile('vid','${f}',${i},${d.id})" title="Remove">&#10005;</button></div>`;}).join('');vidWrap.style.display='block';}else{vidWrap.style.display='none';vidList.innerHTML='';document.getElementById('fKeepVid').value='[]';}

  document.getElementById('fScrFile').value='';document.getElementById('fVidFile').value='';
  loadReqsForForm(d.project_id,d.requirement,true);
  oFrm();setTimeout(scrollModalToTop,50);
}
window.openEditModal=openEdit;

function loadReqsForForm(pid,preSelect,lockIt){
  const s=document.getElementById('fReq'),hi=document.getElementById('fReqHidden');
  s.innerHTML='<option value="">— Loading… —</option>';s.disabled=true;s.style.cursor='wait';s.title='';
  if(!pid){s.innerHTML='<option value="">— Select Project First —</option>';s.disabled=true;return;}
  fetch(`${CP}?ajax_req=1&project_id=${pid}`).then(r=>r.json()).then(list=>{
    s.innerHTML='<option value="">— None —</option>';
    if(list&&list.length){list.forEach(r=>{const o=document.createElement('option');o.value=r.title;o.textContent=r.title;if(preSelect&&r.title===preSelect)o.selected=true;s.appendChild(o);});if(lockIt){s.disabled=true;s.style.cursor='not-allowed';s.title="Requirement locked";hi.value=s.value;}else{s.disabled=false;s.style.cursor='pointer';}}else{s.innerHTML='<option value="">No Requirements</option>';s.disabled=true;}
  }).catch(()=>{s.innerHTML='<option value="">Error loading</option>';s.disabled=true;});
}
document.getElementById('fReq').addEventListener('change',function(){document.getElementById('fReqHidden').value=this.value;});

function delExFile(type,fname,idx,tcId){
  if(type==='scr'){const item=document.getElementById(`esci_${idx}_${tcId}`);if(item)item.remove();const hi=document.getElementById('fKeepScr');let arr=JSON.parse(hi.value||'[]');arr=arr.filter(f=>f!==fname);hi.value=JSON.stringify(arr);const rem=document.getElementById('exScrList').children.length;document.getElementById('exScrCount').textContent=rem;if(!rem)document.getElementById('exScrWrap').style.display='none';}
  else{const item=document.getElementById(`evidi_${idx}_${tcId}`);if(item)item.remove();const hi=document.getElementById('fKeepVid');let arr=JSON.parse(hi.value||'[]');arr=arr.filter(f=>f!==fname);hi.value=JSON.stringify(arr);const rem=document.getElementById('exVidList').children.length;document.getElementById('exVidCount').textContent=rem;if(!rem)document.getElementById('exVidWrap').style.display='none';}
  toast('File removed — save to confirm','success');
}

function oFrm(){document.getElementById('frmMo').classList.add('open');document.body.style.overflow='hidden';}
function cFrm(){document.getElementById('frmMo').classList.remove('open');document.body.style.overflow='';}

function openImportModal(){const pid=document.getElementById('tbProject').value;if(!pid){toast('Select a project first','error');return;}document.getElementById('impPid').value=pid;document.getElementById('impForm').reset();document.getElementById('impMo').classList.add('open');document.body.style.overflow='hidden';}
function cImp(){document.getElementById('impMo').classList.remove('open');document.body.style.overflow='';}

function getISTDateTime(){const now=new Date();const istOffset=5.5*60*60*1000;const istTime=new Date(now.getTime()+istOffset);const y=istTime.getUTCFullYear(),m=String(istTime.getUTCMonth()+1).padStart(2,'0'),d=String(istTime.getUTCDate()).padStart(2,'0');const hh=String(istTime.getUTCHours()).padStart(2,'0'),mm=String(istTime.getUTCMinutes()).padStart(2,'0'),ss=String(istTime.getUTCSeconds()).padStart(2,'0');return{date:`${y}-${m}-${d}`,time:`${hh}:${mm}:${ss}`};}

function togBlk(id,cb){
  document.getElementById(id).classList.toggle('active',cb.checked);
  if(cb.checked&&id==='execBlk'){const ist=getISTDateTime();if(!document.getElementById('fDate').value)document.getElementById('fDate').value=ist.date;if(!document.getElementById('fTime').value)document.getElementById('fTime').value=ist.time;}
  if(id==='bugBlk'){const editId=document.getElementById('fId').value;if(editId&&cb.checked){const keepScr=JSON.parse(document.getElementById('fKeepScr').value||'[]'),keepVid=JSON.parse(document.getElementById('fKeepVid').value||'[]');if(keepScr.length)document.getElementById('exScrWrap').style.display='block';if(keepVid.length)document.getElementById('exVidWrap').style.display='block';}else if(!cb.checked){document.getElementById('exScrWrap').style.display='none';document.getElementById('exVidWrap').style.display='none';}}
}

// ══ FORM SUBMIT ══════════════════════════════
function renumberRows(){document.querySelectorAll('#tcTbody tr[data-id]').forEach((row,idx)=>{const c=row.querySelector('td:first-child');if(c)c.textContent=idx+1;});}

document.getElementById('tcForm').addEventListener('submit',function(e){
  e.preventDefault();document.getElementById('fReqHidden').value=document.getElementById('fReq').value;
  const btn=document.getElementById('btnSave');btn.disabled=true;btn.textContent='Saving…';
  fetch(CP,{method:'POST',body:new FormData(this)}).then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.text();})
  .then(text=>{let res;try{const s=text.indexOf('{'),en=text.lastIndexOf('}');if(s===-1||en===-1)throw new Error('No JSON');res=JSON.parse(text.substring(s,en+1));}catch(pe){console.error('Raw:',text);throw new Error('Bad response: '+pe.message);}
  btn.disabled=false;btn.textContent='Save';if(!res.success){toast(res.msg||'Error','error');return;}cFrm();toast(res.msg,'success');
  const tb=document.getElementById('tcTbody');
  if(res.action==='add'){document.getElementById('emptyRow')?.remove();const t=document.createElement('tbody');t.innerHTML=res.row_html;const nr=t.firstElementChild;if(nr){nr.classList.add('rnew');tb.insertBefore(nr,tb.firstChild);}const tc=document.getElementById('totC');if(tc)tc.textContent=parseInt(tc.textContent||0)+1;renumberRows();}
  else if(res.action==='edit'){const ex=tb.querySelector(`tr[data-id="${res.edit_id}"]`);if(ex){const oldSn=ex.querySelector('td:first-child')?.textContent||'-';const t=document.createElement('tbody');t.innerHTML=res.row_html;const nr=t.firstElementChild;if(nr){const nc=nr.querySelector('td:first-child');if(nc)nc.textContent=oldSn;nr.classList.add('rnew');ex.replaceWith(nr);setTimeout(()=>{const updatedRow=document.querySelector(`tr[data-id="${res.edit_id}"]`);if(updatedRow)updatedRow.scrollIntoView({behavior:'smooth',block:'nearest'});},200);}}}
  }).catch(err=>{btn.disabled=false;btn.textContent='Save';toast('Error: '+err.message,'error');});
});

document.getElementById('impForm').addEventListener('submit',function(e){
  e.preventDefault();const btn=document.getElementById('btnImp');btn.disabled=true;btn.textContent='Importing…';
  fetch(CP,{method:'POST',body:new FormData(this)}).then(r=>r.json()).then(res=>{btn.disabled=false;btn.textContent='Import';if(res.success){toast(res.msg,'success');cImp();setTimeout(()=>location.reload(),1000);}else{toast(res.msg,'error');}}).catch(err=>{btn.disabled=false;btn.textContent='Import';toast('Error: '+err.message,'error');});
});

// ══ LIGHTBOX CAROUSEL ════════════════════════
let _carouselFiles=[],_carouselIdx=0,_carouselType='img';
function openScr(files){if(!files||!files.length)return;_carouselFiles=files.map(f=>'../../uploads/bugs/'+f);_carouselIdx=0;_carouselType='img';_openCarousel();}
function openVid(files){if(!files||!files.length)return;_carouselFiles=files.map(f=>'../../uploads/bug_videos/'+f);_carouselIdx=0;_carouselType='vid';_openCarousel();}
function openScrAt(files,idx){_carouselFiles=files.map(f=>'../../uploads/bugs/'+f);_carouselIdx=idx||0;_carouselType='img';_openCarousel();}
function openVidAt(files,idx){_carouselFiles=files.map(f=>'../../uploads/bug_videos/'+f);_carouselIdx=idx||0;_carouselType='vid';_openCarousel();}
function oLImg(src){_carouselFiles=[src];_carouselIdx=0;_carouselType='img';_openCarousel();}
function oLVid(src){_carouselFiles=[src];_carouselIdx=0;_carouselType='vid';_openCarousel();}
function _openCarousel(){_renderCarouselFrame();document.getElementById('lbox').classList.add('open');document.body.classList.add('lb-open');}
function _renderCarouselFrame(){const total=_carouselFiles.length,f=_carouselFiles[_carouselIdx],cont=document.getElementById('lbCont');const ov=cont.querySelector('video');if(ov){ov.pause();ov.src='';}if(_carouselType==='img')cont.innerHTML=`<img src="${f}" style="max-width:88vw;max-height:82vh;border-radius:10px;object-fit:contain;display:block"/>`;else cont.innerHTML=`<video src="${f}" controls autoplay style="max-width:88vw;max-height:82vh;border-radius:10px;display:block"></video>`;const prev=document.getElementById('lbPrev'),next=document.getElementById('lbNext'),counter=document.getElementById('lbCounter'),dots=document.getElementById('lbDots');if(total>1){prev.style.display='flex';next.style.display='flex';counter.style.display='block';counter.textContent=`${_carouselIdx+1} / ${total}`;dots.innerHTML=total<=10?Array.from({length:total},(_,i)=>`<button class="lb-dot ${i===_carouselIdx?'active':''}" onclick="carouselGoTo(${i})"></button>`).join(''):'';}else{prev.style.display='none';next.style.display='none';counter.style.display='none';dots.innerHTML='';}}
function carouselNav(dir){const t=_carouselFiles.length;if(t<=1)return;_carouselIdx=(_carouselIdx+dir+t)%t;_renderCarouselFrame();}
function carouselGoTo(idx){_carouselIdx=idx;_renderCarouselFrame();}
function cLbox(){const cont=document.getElementById('lbCont');const v=cont.querySelector('video');if(v){v.pause();v.src='';}cont.innerHTML='';document.getElementById('lbox').classList.remove('open');document.body.classList.remove('lb-open');document.getElementById('lbDots').innerHTML='';_carouselFiles=[];}
function cLboxBg(e){if(e.target.id==='lbox')cLbox();}
(function(){let tsx=null;const lb=document.getElementById('lbox');lb.addEventListener('touchstart',e=>{tsx=e.touches[0].clientX;},{passive:true});lb.addEventListener('touchend',e=>{if(tsx===null)return;const dx=e.changedTouches[0].clientX-tsx;if(Math.abs(dx)>50)carouselNav(dx<0?1:-1);tsx=null;},{passive:true});})();

function toast(msg,type='success'){document.querySelector('.toast')?.remove();const t=document.createElement('div');t.className=`toast ${type}`;t.textContent=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),4000);}

document.getElementById('frmMo').addEventListener('click',function(e){if(e.target===this)cFrm();});
document.getElementById('detMo').addEventListener('click',function(e){if(e.target===this)cDet();});
document.getElementById('impMo').addEventListener('click',function(e){if(e.target===this)cImp();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){cLbox();cDet();cFrm();cImp();}if(document.getElementById('lbox').classList.contains('open')){if(e.key==='ArrowLeft')carouselNav(-1);if(e.key==='ArrowRight')carouselNav(1);}});
</script>
</body>
</html>
