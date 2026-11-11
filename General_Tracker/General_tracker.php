<?php
session_start();

if (!isset($_SESSION['authenticated']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../index.php");
    exit;
}

require_once '../tracker_db.php';

// Safe Auto-Migration: Ensure attachment columns support multiple files and audit attachments
try {
    $tracker_pdo->exec("ALTER TABLE documents ADD COLUMN attachments TEXT NULL");
} catch (Exception $e) {}
try {
    $tracker_pdo->exec("ALTER TABLE audit_logs ADD COLUMN attachments TEXT NULL");
} catch (Exception $e) {}

// Auto-update overdue status
$tracker_pdo->exec("UPDATE documents SET status = 'overdue' WHERE deadline < CURDATE() AND status != 'completed'");

$current_user = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Administrator';

// Helper to handle multiple file uploads
function uploadMultipleFiles($file_post_key) {
    $uploaded_names = [];
    if (!empty($_FILES[$file_post_key]['name'][0])) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $count = count($_FILES[$file_post_key]['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES[$file_post_key]['error'][$i] === UPLOAD_ERR_OK) {
                $orig_name = basename($_FILES[$file_post_key]['name'][$i]);
                $clean_name = time() . '_' . mt_rand(100, 999) . '_' . preg_replace("/[^a-zA-Z0-9._-]/", "", $orig_name);
                if (move_uploaded_file($_FILES[$file_post_key]['tmp_name'][$i], $upload_dir . $clean_name)) {
                    $uploaded_names[] = $clean_name;
                }
            }
        }
    }
    return $uploaded_names;
}

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Create Category Card
    if ($action === 'create_category') {
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        if (!empty($title)) {
            $stmt = $tracker_pdo->prepare("INSERT INTO categories (title, description) VALUES (?, ?)");
            $stmt->execute([$title, $desc]);
        }
        header("Location: General_tracker.php");
        exit;
    }

    // 2. Delete Category Card
    if ($action === 'delete_category') {
        $cat_id = (int)($_POST['category_id'] ?? 0);
        if ($cat_id > 0) {
            $stmt = $tracker_pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$cat_id]);
        }
        header("Location: General_tracker.php");
        exit;
    }

    // 3. Create Document (With Local Hold & Multiple Files)
    if ($action === 'create_document') {
        $category_id   = (int)($_POST['category_id'] ?? 0);
        $title         = trim($_POST['title'] ?? '');
        $flow          = $_POST['flow'] ?? 'incoming';
        $origin_dept   = $_POST['origin_department'] ?? 'Administrative';
        $is_local_hold = isset($_POST['is_local_hold']) && $_POST['is_local_hold'] == '1';
        $holder_name   = $current_user; // Locked to current administrator
        $deadline      = $_POST['deadline'] ?? date('Y-m-d');
        
        // Handle Target Dept & Custody Route
        if ($is_local_hold) {
            $target_dept = $origin_dept;
            $route       = $origin_dept . ' (Stored on Local Hold)';
            $audit_desc  = "Document created and stored on local hold in $origin_dept by $holder_name";
        } else {
            $target_dept = $_POST['target_department'] ?? 'Academe';
            $route       = $origin_dept . ' → ' . $target_dept;
            $audit_desc  = "Dispatched from $origin_dept to $target_dept. Recipient: $holder_name";
        }
        
        if ($category_id > 0 && !empty($title)) {
            $ref_no = 'DOC-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Multiple Uploads
            $uploaded_files = uploadMultipleFiles('attachments');
            $files_json = json_encode($uploaded_files);

            $stmt = $tracker_pdo->prepare("INSERT INTO documents (category_id, ref_no, title, flow, origin_department, current_department, current_holder, custody_route, deadline, status, attachments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([$category_id, $ref_no, $title, $flow, $origin_dept, $target_dept, $holder_name, $route, $deadline, $files_json]);

            $doc_id = $tracker_pdo->lastInsertId();
            $audit = $tracker_pdo->prepare("INSERT INTO audit_logs (document_id, action_type, department, acted_by, action_title, remarks, attachments) VALUES (?, 'created', ?, ?, 'Communication Initiated', ?, ?)");
            $audit->execute([$doc_id, $origin_dept, $current_user, $audit_desc, $files_json]);
        }

        header("Location: General_tracker.php?open_cat=" . $category_id);
        exit;
    }

    // 4. Update Custody Handoff (With New Revisions/Uploads)
    if ($action === 'update_custody') {
        $doc_id      = (int)($_POST['doc_id'] ?? 0);
        $cat_id      = (int)($_POST['category_id'] ?? 0);
        $workflow_op = $_POST['workflow_operation'] ?? 'forward';
        $next_dept   = $_POST['next_department'] ?? 'Administrative';
        $holder_name = trim($_POST['next_holder'] ?? $current_user);
        $remarks     = trim($_POST['action_remarks'] ?? '');

        $currDoc = $tracker_pdo->prepare("SELECT * FROM documents WHERE id = ?");
        $currDoc->execute([$doc_id]);
        $doc = $currDoc->fetch();

        if ($doc) {
            // Upload new/revision files
            $new_uploaded_files = uploadMultipleFiles('update_attachments');
            $existing_files = json_decode($doc['attachments'] ?? '[]', true) ?: [];
            $all_files = array_merge($existing_files, $new_uploaded_files);
            $all_files_json = json_encode($all_files);
            $step_files_json = json_encode($new_uploaded_files);

            if ($workflow_op === 'forward') {
                $raw_route = $doc['custody_route'] ?? $doc['origin_department'];
                // Clean up any "(Stored on Local Hold)" notice on first forward
                $clean_route = str_replace(' (Stored on Local Hold)', '', $raw_route);
                $new_route   = $clean_route . ' → ' . $next_dept;

                $stmt = $tracker_pdo->prepare("UPDATE documents SET current_department = ?, current_holder = ?, custody_route = ?, attachments = ? WHERE id = ?");
                $stmt->execute([$next_dept, $holder_name, $new_route, $all_files_json, $doc_id]);

                $log = $tracker_pdo->prepare("INSERT INTO audit_logs (document_id, action_type, department, acted_by, action_title, remarks, attachments) VALUES (?, 'forwarded', ?, ?, ?, ?, ?)");
                $log->execute([$doc_id, $doc['current_department'], $current_user, "Forwarded to $next_dept ($holder_name)", $remarks, $step_files_json]);

            } elseif ($workflow_op === 'action_taken') {
                $stmt = $tracker_pdo->prepare("UPDATE documents SET attachments = ? WHERE id = ?");
                $stmt->execute([$all_files_json, $doc_id]);

                $log = $tracker_pdo->prepare("INSERT INTO audit_logs (document_id, action_type, department, acted_by, action_title, remarks, attachments) VALUES (?, 'action_taken', ?, ?, 'Action / Endorsement Logged', ?, ?)");
                $log->execute([$doc_id, $doc['current_department'], $current_user, $remarks, $step_files_json]);

            } elseif ($workflow_op === 'complete') {
                $stmt = $tracker_pdo->prepare("UPDATE documents SET status = 'completed', attachments = ? WHERE id = ?");
                $stmt->execute([$all_files_json, $doc_id]);

                $log = $tracker_pdo->prepare("INSERT INTO audit_logs (document_id, action_type, department, acted_by, action_title, remarks, attachments) VALUES (?, 'completed', ?, ?, 'Workflow Completed & Concluded', ?, ?)");
                $log->execute([$doc_id, $doc['current_department'], $current_user, $remarks ?: 'Final sign-off achieved.', $step_files_json]);
            }
        }

        header("Location: General_tracker.php?open_cat=" . $cat_id);
        exit;
    }
}

// --- METRICS ---
$totalCount     = (int)$tracker_pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
$pendingCount   = (int)$tracker_pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'pending'")->fetchColumn();
$overdueCount   = (int)$tracker_pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'overdue'")->fetchColumn();
$completedCount = (int)$tracker_pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'completed'")->fetchColumn();

// --- CATEGORIES ---
$categories = $tracker_pdo->query("
    SELECT c.*, 
        COUNT(d.id) as total_docs,
        SUM(CASE WHEN d.status = 'pending' THEN 1 ELSE 0 END) as pending_docs,
        SUM(CASE WHEN d.status = 'overdue' THEN 1 ELSE 0 END) as overdue_docs,
        SUM(CASE WHEN d.status = 'completed' THEN 1 ELSE 0 END) as completed_docs
    FROM categories c
    LEFT JOIN documents d ON c.id = d.category_id
    GROUP BY c.id
    ORDER BY c.id DESC
")->fetchAll();

// --- DOCUMENTS ---
$documents = $tracker_pdo->query("
    SELECT d.*, c.title as category_title 
    FROM documents d
    JOIN categories c ON d.category_id = c.id
    ORDER BY d.id DESC
")->fetchAll();

// --- AUDIT TRAIL LOGS ---
$audit_logs = $tracker_pdo->query("SELECT * FROM audit_logs ORDER BY logged_at DESC")->fetchAll();
$grouped_logs = [];
foreach ($audit_logs as $log) {
    $grouped_logs[$log['document_id']][] = $log;
}

$auto_open_cat = (int)($_GET['open_cat'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BSBT College | Custody Routing & General Tracker</title>
    <!-- Modern Font: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --college-navy: #091a36;
            --college-navy-light: #132d59;
            --college-blue: #1d4ed8;
            --college-gold: #c59b27;
            --bg-canvas: #f8fafc;
            --card-surface: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-subtle: #e2e8f0;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-sm: 0 2px 4px rgba(9, 26, 54, 0.04);
            --shadow-md: 0 10px 25px -5px rgba(9, 26, 54, 0.08);
            
            --status-pending: #d97706;
            --status-pending-bg: #fef3c7;
            --status-completed: #16a34a;
            --status-completed-bg: #dcfce7;
            --status-overdue: #dc2626;
            --status-overdue-bg: #fee2e2;
            --badge-in: #2563eb;
            --badge-in-bg: #dbeafe;
            --badge-out: #7c3aed;
            --badge-out-bg: #ede9fe;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        body {
            background-color: var(--bg-canvas);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: #ffffff;
            border-bottom: 1px solid var(--border-subtle);
            padding: 0.75rem 2.5rem;
            flex-shrink: 0;
        }

        nav {
            max-width: 1440px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        #logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        #logo img {
            height: 36px;
            width: auto;
            object-fit: contain;
        }

        .brand-text h2 {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--college-navy);
            line-height: 1.1;
        }

        .brand-text span {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .terminal-pill {
            font-size: 0.72rem;
            font-weight: 700;
            background: rgba(197, 155, 39, 0.12);
            color: #99720d;
            border: 1px solid rgba(197, 155, 39, 0.3);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Portal Online Status Indicator */
        .sys-status {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #15803d;
            background: #f0fdf4;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            border: 1px solid #bbf7d0;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            background-color: #22c55e;
            border-radius: 50%;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
        }

        /* Helpdesk Link */
        .nav-help-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid var(--border-subtle);
            transition: all 0.2s ease;
        }

        .nav-help-link:hover {
            color: var(--college-navy);
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .user-divider {
            width: 1px;
            height: 24px;
            background-color: var(--border-subtle);
            margin: 0 2px;
        }

        /* Icon Buttons */
        .nav-icon-btn {
            position: relative;
            background: #f1f5f9;
            border: 1px solid var(--border-subtle);
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--college-navy);
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .nav-icon-btn:hover {
            background: #e2e8f0;
            color: var(--college-blue);
        }

        .nav-icon-btn svg {
            width: 18px;
            height: 18px;
            stroke-width: 2;
            stroke: currentColor;
            fill: none;
        }

        .badge-count {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: #ffffff;
            font-size: 0.62rem;
            font-weight: 700;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
        }

        /* Logout */
        .btn-logout {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #fff1f2;
            color: #e11d48;
            border: 1px solid #ffe4e6;
            padding: 0.5rem 0.9rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-logout svg {
            width: 15px;
            height: 15px;
            stroke-width: 2;
            stroke: currentColor;
            fill: none;
        }

        .btn-logout:hover {
            background: #e11d48;
            color: #ffffff;
            border-color: #e11d48;
        }

        main {
            flex: 1;
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
            padding: 1.5rem 2.5rem 2.5rem;
        }

        #top-back-bar { display: flex; gap: 8px; margin-bottom: 0.85rem; }

        .btn-back-top {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ffffff;
            color: var(--college-navy);
            border: 1.5px solid var(--border-subtle);
            padding: 0.45rem 0.85rem;
            border-radius: var(--radius-md);
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
            width: fit-content;
        }

        .btn-back-top:hover { background: #f1f5f9; transform: translateX(-2px); color: var(--college-navy); }

        .page-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.85rem;
            border-bottom: 1px solid var(--border-subtle);
        }

        .page-toolbar-left h1 {
            font-size: 1.55rem;
            font-weight: 800;
            color: var(--college-navy);
            letter-spacing: -0.3px;
        }

        .page-toolbar-left p { font-size: 0.85rem; color: var(--text-muted); }
        .page-toolbar-actions { display: flex; align-items: center; gap: 10px; }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--college-navy);
            color: #ffffff;
            border: none;
            padding: 0.55rem 0.95rem;
            border-radius: var(--radius-md);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary:hover { background: var(--college-navy-light); }

        .btn-outline {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ffffff;
            color: var(--college-navy);
            border: 1px solid var(--border-subtle);
            padding: 0.55rem 0.95rem;
            border-radius: var(--radius-md);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-outline:hover { background: #f1f5f9; }

        /* General Metric Counters */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }

        .stat-card {
            background: var(--card-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1.4rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-card-left span.stat-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            display: block;
            margin-bottom: 0.25rem;
        }

        .stat-card-left h2.stat-number {
            font-size: 1.85rem;
            font-weight: 800;
            line-height: 1;
            color: var(--college-navy);
        }

        .stat-card-left small.stat-subtext {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.35rem;
            display: block;
        }

        .stat-card-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-card-icon svg { width: 22px; height: 22px; stroke-width: 2.2; fill: none; }
        .card-total .stat-card-icon { background: #f1f5f9; color: var(--college-navy); }
        .card-pending { border-left: 4px solid var(--status-pending); }
        .card-pending .stat-card-icon { background: #fef3c7; color: #d97706; }
        .card-pending .stat-number { color: var(--status-pending); }
        .card-overdue { border-left: 4px solid var(--status-overdue); }
        .card-overdue .stat-card-icon { background: #fee2e2; color: #dc2626; }
        .card-overdue .stat-number { color: var(--status-overdue); }
        .card-completed { border-left: 4px solid var(--status-completed); }
        .card-completed .stat-card-icon { background: #dcfce7; color: #16a34a; }
        .card-completed .stat-number { color: var(--status-completed); }

        /* Toolbar */
        .category-main-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }

        .category-search-box { position: relative; flex: 1; min-width: 280px; display: flex; align-items: center; }
        .category-search-box svg { position: absolute; left: 12px; width: 16px; height: 16px; stroke: var(--text-muted); pointer-events: none; }
        .category-search-box input { width: 100%; height: 40px; padding: 0 1rem 0 2.3rem; border: 1.5px solid var(--border-subtle); border-radius: var(--radius-md); font-size: 0.88rem; background: #ffffff; outline: none; transition: all 0.2s; }
        .category-search-box input:focus { border-color: var(--college-navy); box-shadow: 0 0 0 3px rgba(9, 26, 54, 0.08); }

        .category-toolbar-right { display: flex; align-items: center; gap: 10px; }
        .cat-select-filter { height: 40px; padding: 0 0.85rem; border: 1.5px solid var(--border-subtle); border-radius: var(--radius-md); font-size: 0.82rem; background: #ffffff; color: var(--text-dark); outline: none; cursor: pointer; }

        .view-mode-toggle { display: inline-flex; background: #f1f5f9; border: 1.5px solid var(--border-subtle); padding: 3px; border-radius: var(--radius-md); gap: 2px; }
        .view-toggle-btn { background: transparent; border: none; width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted); transition: all 0.2s ease; }
        .view-toggle-btn svg { width: 16px; height: 16px; stroke-width: 2.2; }
        .view-toggle-btn.active { background: #ffffff; color: var(--college-navy); box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

        /* Categories Container (Grid / List) */
        .categories-container.mode-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
        .categories-container.mode-grid .category-card {
            background: #ffffff; border: 1px solid var(--border-subtle); border-top: 3.5px solid var(--college-navy);
            border-radius: var(--radius-lg); padding: 1.4rem 1.4rem 1.15rem; box-shadow: 0 4px 6px -1px rgba(9, 26, 54, 0.03);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1); display: flex; flex-direction: column; justify-content: space-between; min-height: 190px; cursor: pointer;
        }
        .categories-container.mode-grid .category-card:hover { transform: translateY(-4px); box-shadow: 0 16px 28px -6px rgba(9, 26, 54, 0.1); border-color: #cbd5e1; border-top-color: var(--college-gold); }

        .card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; }
        .card-icon-wrap { width: 42px; height: 42px; border-radius: 10px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: var(--college-navy); }
        .category-card:hover .card-icon-wrap { background: var(--college-navy); color: #ffffff; }
        .card-icon-wrap svg { width: 20px; height: 20px; stroke-width: 2; }
        .btn-card-del { background: transparent; border: none; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #94a3b8; }
        .btn-card-del:hover { background: #fee2e2; color: #dc2626; }

        .card-body-content h3 { font-size: 1.1rem; font-weight: 800; color: var(--college-navy); letter-spacing: -0.2px; margin-bottom: 0.3rem; }
        .card-body-content p { font-size: 0.82rem; color: var(--text-muted); line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        .card-footer-metrics { display: flex; align-items: center; gap: 6px; padding-top: 0.85rem; border-top: 1px solid #f1f5f9; flex-wrap: wrap; }
        .metric-pill { display: inline-flex; align-items: center; gap: 5px; font-size: 0.72rem; font-weight: 700; padding: 3px 7px; border-radius: 6px; border: 1px solid transparent; }
        .metric-pill .m-dot { width: 6px; height: 6px; border-radius: 50%; }
        .pill-pending { background: #fffbeb; color: #b45309; border-color: #fef3c7; }
        .pill-pending .m-dot { background: #f59e0b; }
        .pill-overdue { background: #fef2f2; color: #b91c1c; border-color: #fee2e2; }
        .pill-overdue .m-dot { background: #ef4444; }
        .pill-completed { background: #ecfdf5; color: #047857; border-color: #d1fae5; }
        .pill-completed .m-dot { background: #10b981; }

        /* List Mode */
        .categories-container.mode-list { display: flex; flex-direction: column; gap: 0.75rem; }
        .categories-container.mode-list .category-card {
            background: #ffffff; border: 1px solid var(--border-subtle); border-left: 4px solid var(--college-navy);
            border-radius: var(--radius-md); padding: 1rem 1.4rem; box-shadow: 0 2px 4px rgba(9, 26, 54, 0.03);
            display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; cursor: pointer;
        }
        .categories-container.mode-list .category-card:hover { border-left-color: var(--college-gold); transform: translateX(4px); }
        .categories-container.mode-list .card-head { margin-bottom: 0; gap: 12px; display: flex; align-items: center; }
        .categories-container.mode-list .card-body-content { margin-bottom: 0; flex: 1; }
        .categories-container.mode-list .card-body-content h3 { margin-bottom: 2px; }
        .categories-container.mode-list .card-footer-metrics { padding-top: 0; border-top: none; gap: 8px; }

        /* Add Starter Card */
        .add-card-starter { background: #ffffff; border: 2px dashed #cbd5e1; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .add-card-starter:hover { border-color: var(--college-navy); background: #f8fafc; transform: translateY(-3px); }
        .categories-container.mode-grid .add-card-starter { padding: 2rem 1.5rem; flex-direction: column; text-align: center; min-height: 190px; }
        .add-card-starter .add-icon-circle { width: 48px; height: 48px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin-bottom: 0.75rem; color: var(--college-navy); }
        .add-card-starter .add-icon-circle svg { width: 24px; height: 24px; stroke-width: 2.2; }
        .add-card-starter:hover .add-icon-circle { background: var(--college-navy); color: #ffffff; }
        .add-card-starter h3 { font-size: 1.05rem; font-weight: 800; color: var(--college-navy); margin-bottom: 0.25rem; }
        .add-card-starter p { font-size: 0.8rem; color: var(--text-muted); }
        .categories-container.mode-list .add-card-starter { padding: 1rem 1.4rem; border-radius: var(--radius-md); flex-direction: row; justify-content: flex-start; gap: 12px; }

        /* Table View */
        #table-view { display: none; }
        .table-filter-toolbar {
            background: var(--card-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-md);
            padding: 0.9rem 1.1rem; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: var(--shadow-sm);
        }
        .toolbar-group-left { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 320px; }
        .search-by-select { height: 38px; padding: 0 0.75rem; border: 1.5px solid var(--border-subtle); border-radius: 6px; font-size: 0.82rem; font-weight: 600; color: var(--college-navy); background: #f8fafc; outline: none; cursor: pointer; flex-shrink: 0; }
        .search-input-wrap { position: relative; flex: 1; display: flex; align-items: center; }
        .search-input-wrap svg { position: absolute; left: 10px; width: 15px; height: 15px; stroke: var(--text-muted); }
        .search-input-wrap input { width: 100%; height: 38px; padding: 0 0.85rem 0 2.15rem; border: 1.5px solid var(--border-subtle); border-radius: 6px; font-size: 0.85rem; background: #ffffff; outline: none; }
        .search-input-wrap input:focus { border-color: var(--college-navy); box-shadow: 0 0 0 3px rgba(9, 26, 54, 0.08); }
        .toolbar-group-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .filter-select-box { height: 38px; padding: 0 0.75rem; border: 1.5px solid var(--border-subtle); border-radius: 6px; font-size: 0.82rem; color: var(--text-dark); background: #ffffff; outline: none; cursor: pointer; }
        .btn-reset-filters { height: 38px; padding: 0 0.75rem; background: #f1f5f9; border: 1px solid var(--border-subtle); border-radius: 6px; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }

        .table-container { background: var(--card-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm); }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem; }
        thead { background: #f8fafc; border-bottom: 1px solid var(--border-subtle); }
        th { padding: 0.85rem 1.25rem; font-weight: 700; color: var(--college-navy); text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.5px; white-space: nowrap; }
        td { padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        tbody tr:hover { background-color: #fafbfd; }
        th:first-child, td:first-child { width: 145px; min-width: 140px; white-space: nowrap; }

        .ref-pill { font-family: monospace; font-weight: 700; color: var(--college-navy); background: #f1f5f9; padding: 0.35rem 0.65rem; border-radius: 6px; font-size: 0.82rem; border: 1px solid #e2e8f0; white-space: nowrap; display: inline-block; }
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 0.25rem 0.65rem; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
        .badge-incoming { background: var(--badge-in-bg); color: var(--badge-in); }
        .badge-outgoing { background: var(--badge-out-bg); color: var(--badge-out); }
        .badge-pending { background: var(--status-pending-bg); color: var(--status-pending); }
        .badge-completed { background: var(--status-completed-bg); color: var(--status-completed); }
        .badge-overdue { background: var(--status-overdue-bg); color: var(--status-overdue); }

        .custody-current-box { display: flex; flex-direction: column; gap: 3px; }
        .dept-current-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: #f8fafc; border: 1px solid #cbd5e1; border-left: 3.5px solid var(--college-gold); border-radius: 6px; font-size: 0.82rem; font-weight: 700; color: var(--college-navy); width: fit-content; }
        .custodian-name-sub { font-size: 0.72rem; color: var(--text-muted); }

        /* Multiple Attachment Badges */
        .attachment-chips-wrap { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 5px; }
        .btn-attachment-preview {
            display: inline-flex; align-items: center; gap: 4px; background: #f0f7ff; border: 1px solid #bae6fd;
            color: var(--college-blue); padding: 0.2rem 0.55rem; border-radius: 6px; font-size: 0.72rem; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap;
        }
        .btn-attachment-preview:hover { background: var(--college-blue); color: #ffffff; }

        .btn-route-action { display: inline-flex; align-items: center; gap: 4px; background: var(--college-navy); color: #ffffff; border: none; padding: 0.38rem 0.75rem; border-radius: 6px; font-size: 0.76rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; white-space: nowrap; }
        .btn-route-action:hover { background: var(--college-navy-light); }

        /* Modals */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(9, 26, 54, 0.5); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 100; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 580px; padding: 2.25rem; box-shadow: 0 25px 50px -12px rgba(9, 26, 54, 0.25); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 0.85rem; border-bottom: 1px solid var(--border-subtle); }
        .modal-header h3 { font-size: 1.3rem; font-weight: 800; color: var(--college-navy); letter-spacing: -0.3px; }
        .modal-close { background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 8px; font-size: 1.25rem; cursor: pointer; color: var(--text-muted); display: flex; align-items: center; justify-content: center; }

        .modal-form { display: flex; flex-direction: column; gap: 1.2rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; align-items: flex-start; }
        .form-group { display: flex; flex-direction: column; gap: 0.45rem; }
        .form-group label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--college-navy); letter-spacing: 0.5px; }

        .form-group input[type="text"], 
        .form-group input[type="date"], 
        .form-group select,
        .locked-card-display,
        .custom-file-upload {
            height: 44px; padding: 0 0.85rem; border: 1.5px solid var(--border-subtle); border-radius: var(--radius-md); font-size: 0.9rem; outline: none; background: #fafbfc; color: var(--text-dark); width: 100%; display: flex; align-items: center;
        }

        .form-group textarea { padding: 0.75rem 0.85rem; border: 1.5px solid var(--border-subtle); border-radius: var(--radius-md); font-size: 0.9rem; outline: none; background: #fafbfc; color: var(--text-dark); width: 100%; resize: vertical; }

        .locked-card-display { background: #eef2f8; border-color: #d8e2f0; color: var(--college-navy); font-weight: 700; gap: 8px; }

        /* Local Hold Checkbox Styling */
        .checkbox-container-box {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.75rem 0.95rem;
            background: #fffbeb;
            border: 1.5px solid #fef3c7;
            border-radius: var(--radius-md);
            cursor: pointer;
        }
        .checkbox-container-box input[type="checkbox"] { width: 17px; height: 17px; accent-color: var(--college-navy); cursor: pointer; }
        .checkbox-container-box span { font-size: 0.82rem; font-weight: 700; color: #92400e; }

        .file-upload-container { position: relative; width: 100%; }
        .file-upload-container input[type="file"] { position: absolute; left: 0; top: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; z-index: 2; }
        .custom-file-upload { gap: 8px; cursor: pointer; overflow: hidden; background: #f8fafc; border: 1.5px dashed #cbd5e1; }
        .custom-file-upload svg { width: 16px; height: 16px; stroke: var(--college-navy); flex-shrink: 0; }
        .custom-file-upload span { font-size: 0.82rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .btn-modal-submit {
            margin-top: 0.5rem; background: linear-gradient(180deg, var(--college-navy) 0%, #07152b 100%);
            color: #ffffff; border: none; border-radius: var(--radius-md); height: 46px; font-size: 0.95rem; font-weight: 700; cursor: pointer; box-shadow: 0 4px 12px rgba(9, 26, 54, 0.2); transition: all 0.2s ease;
        }

        /* Journey Progress Box */
        .route-journey-banner { background: #f8fafc; border: 1.5px solid var(--border-subtle); border-radius: var(--radius-md); padding: 1rem 1.25rem; margin-bottom: 1.5rem; }
        .route-journey-banner span.journey-title { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); display: block; margin-bottom: 6px; }
        .journey-chain-display { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .journey-step-pill { font-size: 0.78rem; font-weight: 700; background: #ffffff; border: 1px solid #cbd5e1; padding: 3px 8px; border-radius: 6px; color: var(--college-navy); }
        .journey-step-pill.current { background: rgba(197, 155, 39, 0.15); color: #99720d; border-color: var(--college-gold); }

        /* Timeline in Audit Trail */
        .timeline { display: flex; flex-direction: column; gap: 1.4rem; position: relative; padding-left: 24px; max-height: 380px; overflow-y: auto; }
        .timeline::before { content: ""; position: absolute; left: 7px; top: 6px; bottom: 6px; width: 2px; background: var(--border-subtle); }
        .timeline-step { position: relative; }
        .timeline-step::before { content: ""; position: absolute; left: -24px; top: 4px; width: 12px; height: 12px; background: var(--college-gold); border-radius: 50%; border: 2px solid #ffffff; box-shadow: 0 0 0 2px rgba(197, 155, 39, 0.3); }
        .timeline-step .log-header { display: flex; align-items: center; gap: 8px; margin-bottom: 2px; }
        .timeline-step strong { font-size: 0.88rem; color: var(--college-navy); }
        .timeline-step .log-dept { font-size: 0.68rem; font-weight: 700; background: #eef2f8; color: var(--college-blue); padding: 2px 6px; border-radius: 4px; }
        .timeline-step span.log-time { font-size: 0.72rem; color: var(--text-muted); display: block; }
        .timeline-step p.log-remarks { margin-top: 6px; font-size: 0.82rem; color: var(--text-dark); background: #f8fafc; border: 1px solid var(--border-subtle); padding: 8px 12px; border-radius: 6px; line-height: 1.4; }
        
        .timeline-files-list {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
    </style>
</head>
<body>

    <!-- Unified Master Navbar -->
    <header>
        <nav>
            <div class="nav-left">
                <a href="../Admin/admin.php" id="logo">
                    <img src="../images/logo.png" alt="BSBT Logo">
                    <div class="brand-text">
                        <h2>BSBT College</h2>
                        <span>Tracking Portal</span>
                    </div>
                </a>
                <span class="terminal-pill">Admin Console</span>
            </div>

            <div class="nav-actions">
                <!-- Portal Online Indicator (From index.php) -->
                <div class="sys-status">
                    <span class="status-dot"></span>
                    <span>Portal Online</span>
                </div>

                <!-- Helpdesk Link (From index.php) -->
                <a href="#" class="nav-help-link" title="Contact Technical Support">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="10" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    Helpdesk
                </a>

                <div class="user-divider"></div>

                <!-- Messages -->
                <a href="#" class="nav-icon-btn" title="Messages">
                    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                    <span class="badge-count">3</span>
                </a>

                <!-- Notifications -->
                <a href="#" class="nav-icon-btn" title="Notifications">
                    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <span class="badge-count">5</span>
                </a>

                <!-- Logout -->
                <a href="../logout.php" class="btn-logout" title="Log Out">
                    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    <span>Log Out</span>
                </a>
            </div>
        </nav>
    </header>

    <main>
        
        <!-- Contextual Back Button -->
        <div id="top-back-bar">
            <a href="../Admin/admin.php" class="btn-back-top" id="btnBackToDashboard">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                Back to Dashboard
            </a>
            <button type="button" class="btn-back-top" id="btnBackToCategories" style="display: none;" onclick="backToCategories()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                Back to Category Cards
            </button>
        </div>

        <div class="page-toolbar">
            <div class="page-toolbar-left">
                <h1 id="page-heading">General Document Categories</h1>
                <p id="page-subheading">Monitor inter-department routing across Administrative, Academe, and Registrars.</p>
            </div>

            <div class="page-toolbar-actions">
                <button type="button" class="btn-outline" onclick="openModal('categoryModal')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    New Category Card
                </button>
                <button type="button" class="btn-primary" onclick="openDocModal()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Track New Document
                </button>
            </div>
        </div>

        <!-- Metric Counters -->
        <div class="stats-overview">
            <div class="stat-card card-total">
                <div class="stat-card-left">
                    <span class="stat-label">Total Monitored</span>
                    <h2 class="stat-number"><?php echo $totalCount; ?></h2>
                    <small class="stat-subtext">Across all categories</small>
                </div>
                <div class="stat-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                </div>
            </div>

            <div class="stat-card card-pending">
                <div class="stat-card-left">
                    <span class="stat-label">In Routing / Pending</span>
                    <h2 class="stat-number"><?php echo $pendingCount; ?></h2>
                    <small class="stat-subtext">Active department custody</small>
                </div>
                <div class="stat-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                </div>
            </div>

            <div class="stat-card card-overdue">
                <div class="stat-card-left">
                    <span class="stat-label">Overdue SLA</span>
                    <h2 class="stat-number"><?php echo $overdueCount; ?></h2>
                    <small class="stat-subtext" style="color: var(--status-overdue); font-weight: 600;">Deadline exceeded</small>
                </div>
                <div class="stat-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                </div>
            </div>

            <div class="stat-card card-completed">
                <div class="stat-card-left">
                    <span class="stat-label">Completed & Cleared</span>
                    <h2 class="stat-number"><?php echo $completedCount; ?></h2>
                    <small class="stat-subtext">Archived communications</small>
                </div>
                <div class="stat-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </div>
            </div>
        </div>

        <!-- CATEGORIES VIEW -->
        <section id="categories-view">
            
            <div class="category-main-toolbar">
                <div class="category-search-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" id="categorySearchInput" placeholder="Search category cards by title or description..." oninput="applyCategoryFilters()">
                </div>

                <div class="category-toolbar-right">
                    <select id="categoryFilterSelect" class="cat-select-filter" onchange="applyCategoryFilters()">
                        <option value="all">All Categories</option>
                        <option value="has_pending">Has Pending Documents</option>
                        <option value="has_overdue">Has Overdue Documents</option>
                        <option value="has_docs">Has Active Documents</option>
                    </select>

                    <select id="categorySortSelect" class="cat-select-filter" onchange="applyCategoryFilters()">
                        <option value="newest">Sort: Recently Added</option>
                        <option value="title_asc">Sort: Title (A → Z)</option>
                        <option value="docs_desc">Sort: Most Documents</option>
                    </select>

                    <div class="view-mode-toggle">
                        <button type="button" class="view-toggle-btn active" id="btnViewGrid" onclick="setCategoryViewMode('grid')" title="Grid View">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect></svg>
                        </button>
                        <button type="button" class="view-toggle-btn" id="btnViewList" onclick="setCategoryViewMode('list')" title="List View">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        </button>
                    </div>
                </div>
            </div>

            <div class="categories-container mode-grid" id="categoriesContainer">
                <?php foreach ($categories as $cat): ?>
                    <div class="category-card" 
                        data-title="<?php echo strtolower(htmlspecialchars($cat['title'])); ?>"
                        data-desc="<?php echo strtolower(htmlspecialchars($cat['description'])); ?>"
                        data-pending="<?php echo (int)$cat['pending_docs']; ?>"
                        data-overdue="<?php echo (int)$cat['overdue_docs']; ?>"
                        data-total-docs="<?php echo (int)$cat['total_docs']; ?>"
                        data-id="<?php echo $cat['id']; ?>"
                        onclick="openCategoryTable('<?php echo htmlspecialchars($cat['title'], ENT_QUOTES); ?>', <?php echo $cat['id']; ?>)">
                        
                        <div class="card-head">
                            <div class="card-icon-wrap">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                            </div>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this category card and all its documents?');">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                <button type="submit" class="btn-card-del" title="Delete Category Card" onclick="event.stopPropagation();">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </form>
                        </div>

                        <div class="card-body-content">
                            <h3><?php echo htmlspecialchars($cat['title']); ?></h3>
                            <p><?php echo htmlspecialchars($cat['description']); ?></p>
                        </div>

                        <div class="card-footer-metrics">
                            <span class="metric-pill pill-pending"><span class="m-dot"></span><?php echo (int)$cat['pending_docs']; ?> Pending</span>
                            <span class="metric-pill pill-overdue"><span class="m-dot"></span><?php echo (int)$cat['overdue_docs']; ?> Overdue</span>
                            <span class="metric-pill pill-completed"><span class="m-dot"></span><?php echo (int)$cat['completed_docs']; ?> Completed</span>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="add-card-starter" id="addCardStarter" onclick="openModal('categoryModal')">
                    <div class="add-icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    </div>
                    <div>
                        <h3>Add Card to Begin</h3>
                        <p>Click here to create a new category card</p>
                    </div>
                </div>

                <div id="noCatMatchMsg" style="display: none; grid-column: 1 / -1; background: #fff; padding: 3rem; text-align: center; border-radius: 12px; border: 1.5px dashed #cbd5e1; color: var(--text-muted);">
                    <p style="font-size: 1rem; font-weight: 600; color: var(--college-navy);">No category cards found matching your filter.</p>
                    <small>Try clearing your search query or reset filters.</small>
                </div>
            </div>
        </section>

        <!-- TABLE VIEW -->
        <section id="table-view">
            
            <div class="table-filter-toolbar">
                <div class="toolbar-group-left">
                    <select id="searchByField" class="search-by-select" onchange="applyTableFilters()">
                        <option value="all">Search By: All Fields</option>
                        <option value="ref">Reference #</option>
                        <option value="title">Document Title</option>
                        <option value="custodian">Custodian Officer</option>
                        <option value="department">Current Department</option>
                    </select>

                    <div class="search-input-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" id="tableSearchInput" placeholder="Type keyword to search..." oninput="applyTableFilters()">
                    </div>
                </div>

                <div class="toolbar-group-right">
                    <select id="filterFlow" class="filter-select-box" onchange="applyTableFilters()">
                        <option value="all">All Flow</option>
                        <option value="incoming">Incoming</option>
                        <option value="outgoing">Outgoing</option>
                    </select>

                    <select id="filterStatus" class="filter-select-box" onchange="applyTableFilters()">
                        <option value="all">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="overdue">Overdue</option>
                        <option value="completed">Completed</option>
                    </select>

                    <select id="filterDept" class="filter-select-box" onchange="applyTableFilters()">
                        <option value="all">All Departments</option>
                        <option value="administrative">Administrative</option>
                        <option value="academe">Academe</option>
                        <option value="registrars">Registrars</option>
                    </select>

                    <select id="sortBy" class="filter-select-box" onchange="applyTableFilters()">
                        <option value="newest">Sort: Date Added (Newest)</option>
                        <option value="deadline_asc">Sort: Deadline (Nearest First)</option>
                        <option value="deadline_desc">Sort: Deadline (Furthest First)</option>
                        <option value="title_asc">Sort: Title (A → Z)</option>
                        <option value="ref_asc">Sort: Reference # (A → Z)</option>
                    </select>

                    <button type="button" class="btn-reset-filters" onclick="resetFilters()" title="Reset all filters">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                        Reset
                    </button>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Reference #</th>
                            <th>Document Title & Files</th>
                            <th>Flow</th>
                            <th>Current Custody Location</th>
                            <th>Deadline</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="documentTableBody">
                        <?php if (empty($documents)): ?>
                            <tr id="emptyTableRow">
                                <td colspan="7" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                                    No documents tracked in this category yet. Click <strong>"Track New Document"</strong> above.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($documents as $doc): 
                                // Decode attachments
                                $doc_files = json_decode($doc['attachments'] ?? '[]', true) ?: [];
                                if (empty($doc_files) && !empty($doc['attachment'])) {
                                    $doc_files = [$doc['attachment']];
                                }
                            ?>
                                <tr class="doc-row" 
                                    data-cat-id="<?php echo $doc['category_id']; ?>"
                                    data-ref="<?php echo strtolower($doc['ref_no']); ?>"
                                    data-title="<?php echo strtolower(htmlspecialchars($doc['title'])); ?>"
                                    data-custodian="<?php echo strtolower(htmlspecialchars($doc['current_holder'] ?? '')); ?>"
                                    data-current-dept="<?php echo strtolower($doc['current_department'] ?? 'administrative'); ?>"
                                    data-flow="<?php echo strtolower($doc['flow'] ?? 'incoming'); ?>"
                                    data-status="<?php echo strtolower($doc['status'] ?? 'pending'); ?>"
                                    data-deadline="<?php echo strtotime($doc['deadline']); ?>"
                                    data-created="<?php echo strtotime($doc['created_at']); ?>">
                                    
                                    <td><span class="ref-pill"><?php echo htmlspecialchars($doc['ref_no']); ?></span></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($doc['title']); ?></strong>
                                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                                            Origin: <?php echo htmlspecialchars($doc['origin_department'] ?? 'Administrative'); ?>
                                        </div>
                                        
                                        <!-- Multiple Attached Files Preview Badges -->
                                        <?php if (!empty($doc_files)): ?>
                                            <div class="attachment-chips-wrap">
                                                <?php foreach ($doc_files as $fidx => $file_item): ?>
                                                    <button type="button" class="btn-attachment-preview" onclick="previewFile('../uploads/<?php echo urlencode($file_item); ?>', '<?php echo htmlspecialchars($file_item, ENT_QUOTES); ?>')">
                                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                                                        File <?php echo ($fidx + 1); ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo ($doc['flow'] ?? 'incoming') === 'incoming' ? 'badge-incoming' : 'badge-outgoing'; ?>">
                                            <?php echo ucfirst($doc['flow'] ?? 'incoming'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="custody-current-box">
                                            <span class="dept-current-badge">
                                                <?php echo htmlspecialchars($doc['current_department'] ?? 'Administrative'); ?>
                                            </span>
                                            <span class="custodian-name-sub">
                                                Custodian: <strong><?php echo htmlspecialchars($doc['current_holder'] ?? 'Staff'); ?></strong>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($doc['deadline'])); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $doc['status']; ?>">
                                            <?php echo ucfirst($doc['status']); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display:inline-flex; align-items:center; gap:6px;">
                                            <?php if ($doc['status'] !== 'completed'): ?>
                                                <button type="button" class="btn-route-action" onclick="openCustodyUpdateModal(<?php echo htmlspecialchars(json_encode($doc), ENT_QUOTES); ?>)">
                                                    Update Custody
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn-outline" style="padding: 0.35rem 0.65rem;" onclick="viewHistory('<?php echo $doc['ref_no']; ?>', <?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['custody_route'] ?? $doc['current_department'], ENT_QUOTES); ?>', '<?php echo $doc['status']; ?>')">
                                                Audit Trail
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr id="noMatchRow" style="display: none;">
                                <td colspan="7" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                                    No documents match your filter or search criteria.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>

    <!-- MODAL 1: CREATE CATEGORY -->
    <div class="modal-overlay" id="categoryModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Category Card</h3>
                <button class="modal-close" onclick="closeModal('categoryModal')">&times;</button>
            </div>
            <form class="modal-form" method="POST">
                <input type="hidden" name="action" value="create_category">
                <div class="form-group">
                    <label>Category Title</label>
                    <input type="text" name="title" placeholder="e.g. Student Clearance Records" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Scope of documents in this category..." required></textarea>
                </div>
                <button type="submit" class="btn-modal-submit">Create Category Card</button>
            </form>
        </div>
    </div>

    <!-- MODAL 2: INITIATE DOCUMENT WORKFLOW (WITH DRAFT / LOCAL HOLD & LOCKED STAFF & MULTI-UPLOAD) -->
    <div class="modal-overlay" id="docModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Track New Communication</h3>
                <button class="modal-close" onclick="closeModal('docModal')">&times;</button>
            </div>
            <form class="modal-form" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_document">
                <input type="hidden" name="category_id" id="docModalCategoryId" value="">

                <div class="form-row">
                    <div class="form-group">
                        <label>Category Card</label>
                        <div id="lockedCardDisplay" class="locked-card-display">
                            <span id="lockedCardTitle">Active Category</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Flow Type</label>
                        <select name="flow">
                            <option value="incoming">Incoming Document</option>
                            <option value="outgoing">Outgoing Document</option>
                        </select>
                    </div>
                </div>

                <!-- DRAFT / LOCAL HOLD OPTION -->
                <label class="checkbox-container-box">
                    <input type="checkbox" id="holdLocallyChk" name="is_local_hold" value="1" onchange="toggleLocalHold(this.checked)">
                    <span>Save document locally first (Do not route yet)</span>
                </label>

                <div class="form-group">
                    <label>Document Title / Purpose</label>
                    <input type="text" name="title" placeholder="e.g. Clearance Verification Request" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Origin Department</label>
                        <select name="origin_department" id="docOriginDept">
                            <option value="Administrative">Administrative</option>
                            <option value="Academe">Academe</option>
                            <option value="Registrars">Registrars</option>
                        </select>
                    </div>
                    
                    <!-- Send/Forward To (Disabled when Local Hold is checked) -->
                    <div class="form-group" id="targetDeptGroup">
                        <label>Send / Forward To</label>
                        <select name="target_department" id="docTargetDept">
                            <option value="Academe">Academe</option>
                            <option value="Registrars">Registrars</option>
                            <option value="Administrative">Administrative</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <!-- LOCKED: Designated Staff is automatically the current admin -->
                    <div class="form-group">
                        <label>Designated Initiator / Staff</label>
                        <input type="text" name="holder_name" value="<?php echo htmlspecialchars($current_user); ?>" readonly style="background:#eef2f8; font-weight:700; color:var(--college-navy); cursor:not-allowed;" title="Locked to current logged-in user">
                    </div>
                    <div class="form-group">
                        <label>SLA Deadline</label>
                        <input type="date" name="deadline" required>
                    </div>
                </div>

                <!-- MULTIPLE FILE UPLOAD -->
                <div class="form-group">
                    <label>Attach Scan(s) — Multiple Files Supported</label>
                    <div class="file-upload-container">
                        <input type="file" name="attachments[]" id="docFileInput" multiple accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" onchange="displaySelectedFiles(this, 'fileNameLabel')">
                        <div class="custom-file-upload">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                            <span id="fileNameLabel">Choose File(s) (PDF, Images, Docs)</span>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-modal-submit">Register & Save Document</button>
            </form>
        </div>
    </div>

    <!-- MODAL 3: UPDATE CUSTODY HANDOFF (WITH REVISION UPLOADS) -->
    <div class="modal-overlay" id="custodyModal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 id="updateModalRefNo">Update Custody & Route</h3>
                    <p style="font-size:0.78rem; color:var(--text-muted);" id="updateModalDocTitle"></p>
                </div>
                <button class="modal-close" onclick="closeModal('custodyModal')">&times;</button>
            </div>
            <form class="modal-form" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_custody">
                <input type="hidden" name="doc_id" id="updateModalDocId">
                <input type="hidden" name="category_id" id="updateModalCatId">

                <div class="form-group">
                    <label>Select Action to Perform</label>
                    <select name="workflow_operation" id="workflowOperationSelect" onchange="handleWorkflowOperationChange(this.value)">
                        <option value="forward">Forward to Another Department</option>
                        <option value="action_taken">Record Action / Note (Keep in Current Department)</option>
                        <option value="complete">Final Institutional Sign-off (Mark Completed)</option>
                    </select>
                </div>

                <div id="forwardDepartmentFields">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Next Department</label>
                            <select name="next_department">
                                <option value="Administrative">Administrative</option>
                                <option value="Academe">Academe</option>
                                <option value="Registrars">Registrars</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Next Custodian / Office</label>
                            <input type="text" name="next_holder" placeholder="e.g. Dean Cruz / Staff">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Action Remarks / Endorsement Details</label>
                    <textarea name="action_remarks" rows="3" placeholder="Enter review findings, approval notes, or routing directives..." required></textarea>
                </div>

                <!-- ATTACH UPDATED / REVISION DOCUMENTS IN UPDATE CUSTODY -->
                <div class="form-group">
                    <label>Attach Updated / Revision Files (Optional)</label>
                    <div class="file-upload-container">
                        <input type="file" name="update_attachments[]" id="updateFileInput" multiple accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" onchange="displaySelectedFiles(this, 'updateFileNameLabel')">
                        <div class="custom-file-upload">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                            <span id="updateFileNameLabel">Upload Revision / Supporting Files...</span>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-modal-submit" id="custodySubmitBtn">Forward to Next Department</button>
            </form>
        </div>
    </div>

    <!-- MODAL 4: AUDIT TRAIL TIMELINE WITH VERSION ATTACHMENTS -->
    <div class="modal-overlay" id="historyModal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 id="historyDocRef">Audit Trail</h3>
                    <p style="font-size: 0.78rem; color: var(--text-muted);">Complete Custody Timeline & Remarks History</p>
                </div>
                <button class="modal-close" onclick="closeModal('historyModal')">&times;</button>
            </div>

            <div class="route-journey-banner">
                <span class="journey-title">Document Journey History</span>
                <div class="journey-chain-display" id="journeyChainDisplay"></div>
            </div>

            <div class="timeline" id="timelineContainer"></div>
        </div>
    </div>

    <!-- MODAL 5: ATTACHMENT IN-PAGE PREVIEW POPUP -->
    <div class="modal-overlay" id="previewModal">
        <div class="modal-content" style="max-width: 780px;">
            <div class="modal-header">
                <div>
                    <h3 id="previewModalTitle">Document Preview</h3>
                    <p style="font-size: 0.78rem; color: var(--text-muted);">In-Page Attachment Viewer</p>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <a href="#" id="previewDownloadLink" download class="btn-outline" style="padding:0.35rem 0.75rem; text-decoration:none;">
                        Download File
                    </a>
                    <button class="modal-close" onclick="closeModal('previewModal')">&times;</button>
                </div>
            </div>
            <div id="previewModalBody" style="min-height: 250px;"></div>
        </div>
    </div>

    <script>
        const hasCategories = <?php echo count($categories); ?> > 0;
        const auditData = <?php echo json_encode($grouped_logs); ?>;

        let activeCategoryId = null;
        let activeCategoryName = "";

        // Toggle Local Hold on Creation
        function toggleLocalHold(isHold) {
            const targetSelect = document.getElementById('docTargetDept');
            const targetGroup = document.getElementById('targetDeptGroup');
            if (isHold) {
                targetSelect.disabled = true;
                targetGroup.style.opacity = '0.4';
            } else {
                targetSelect.disabled = false;
                targetGroup.style.opacity = '1';
            }
        }

        // Multiple File Name Display
        function displaySelectedFiles(input, labelId) {
            const label = document.getElementById(labelId);
            if (input.files && input.files.length > 0) {
                if (input.files.length === 1) {
                    label.innerText = input.files[0].name;
                } else {
                    label.innerText = `${input.files.length} files selected (${Array.from(input.files).map(f => f.name).slice(0, 2).join(', ')}...)`;
                }
                label.style.color = "var(--college-navy)";
                label.style.fontWeight = "600";
            } else {
                label.innerText = "Choose File(s) (PDF, Images, Docs)";
                label.style.color = "var(--text-muted)";
                label.style.fontWeight = "normal";
            }
        }

        function setCategoryViewMode(mode) {
            const container = document.getElementById('categoriesContainer');
            const btnGrid = document.getElementById('btnViewGrid');
            const btnList = document.getElementById('btnViewList');

            if (mode === 'list') {
                container.classList.remove('mode-grid');
                container.classList.add('mode-list');
                btnList.classList.add('active');
                btnGrid.classList.remove('active');
                localStorage.setItem('bsbt_category_view_mode', 'list');
            } else {
                container.classList.remove('mode-list');
                container.classList.add('mode-grid');
                btnGrid.classList.add('active');
                btnList.classList.remove('active');
                localStorage.setItem('bsbt_category_view_mode', 'grid');
            }
        }

        const savedViewMode = localStorage.getItem('bsbt_category_view_mode') || 'grid';
        setCategoryViewMode(savedViewMode);

        function applyCategoryFilters() {
            const searchVal   = document.getElementById('categorySearchInput').value.toLowerCase().trim();
            const filterVal   = document.getElementById('categoryFilterSelect').value;
            const sortVal     = document.getElementById('categorySortSelect').value;

            const container   = document.getElementById('categoriesContainer');
            const cards       = Array.from(document.querySelectorAll('.category-card'));
            const starterCard = document.getElementById('addCardStarter');
            const noMatchMsg  = document.getElementById('noCatMatchMsg');
            let visibleCount  = 0;

            cards.forEach(card => {
                const title    = card.getAttribute('data-title');
                const desc     = card.getAttribute('data-desc');
                const pending  = Number(card.getAttribute('data-pending'));
                const overdue  = Number(card.getAttribute('data-overdue'));
                const total    = Number(card.getAttribute('data-total-docs'));

                let match = (searchVal === '' || title.includes(searchVal) || desc.includes(searchVal));
                if (match && filterVal === 'has_pending') match = (pending > 0);
                if (match && filterVal === 'has_overdue') match = (overdue > 0);
                if (match && filterVal === 'has_docs') match = (total > 0);

                if (match) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            const visibleCards = cards.filter(c => c.style.display !== 'none');
            visibleCards.sort((a, b) => {
                if (sortVal === 'newest') {
                    return Number(b.getAttribute('data-id')) - Number(a.getAttribute('data-id'));
                } else if (sortVal === 'title_asc') {
                    return a.getAttribute('data-title').localeCompare(b.getAttribute('data-title'));
                } else if (sortVal === 'docs_desc') {
                    return Number(b.getAttribute('data-total-docs')) - Number(a.getAttribute('data-total-docs'));
                }
                return 0;
            });

            visibleCards.forEach(card => container.appendChild(card));
            if (starterCard) container.appendChild(starterCard);

            if (noMatchMsg) {
                noMatchMsg.style.display = (visibleCount === 0 && searchVal !== '') ? 'block' : 'none';
            }
        }

        function applyTableFilters() {
            if (activeCategoryId === null) return;

            const searchField = document.getElementById('searchByField').value;
            const searchVal   = document.getElementById('tableSearchInput').value.toLowerCase().trim();
            const flowVal     = document.getElementById('filterFlow').value;
            const statusVal   = document.getElementById('filterStatus').value;
            const deptVal     = document.getElementById('filterDept').value;
            const sortVal     = document.getElementById('sortBy').value;

            const tbody = document.getElementById('documentTableBody');
            const rows  = Array.from(document.querySelectorAll('.doc-row'));
            let visibleRows = [];

            rows.forEach(row => {
                const rowCatId    = row.getAttribute('data-cat-id');
                const rowRef      = row.getAttribute('data-ref');
                const rowTitle    = row.getAttribute('data-title');
                const rowCust     = row.getAttribute('data-custodian');
                const rowDept     = row.getAttribute('data-current-dept');
                const rowFlow     = row.getAttribute('data-flow');
                const rowStatus   = row.getAttribute('data-status');

                let match = (rowCatId == activeCategoryId);

                if (match && flowVal !== 'all') match = (rowFlow === flowVal);
                if (match && statusVal !== 'all') match = (rowStatus === statusVal);
                if (match && deptVal !== 'all') match = (rowDept === deptVal);

                if (match && searchVal !== '') {
                    if (searchField === 'all') {
                        match = rowRef.includes(searchVal) || rowTitle.includes(searchVal) || rowCust.includes(searchVal) || rowDept.includes(searchVal);
                    } else if (searchField === 'ref') {
                        match = rowRef.includes(searchVal);
                    } else if (searchField === 'title') {
                        match = rowTitle.includes(searchVal);
                    } else if (searchField === 'custodian') {
                        match = rowCust.includes(searchVal);
                    } else if (searchField === 'department') {
                        match = rowDept.includes(searchVal);
                    }
                }

                if (match) {
                    row.style.display = '';
                    visibleRows.push(row);
                } else {
                    row.style.display = 'none';
                }
            });

            visibleRows.sort((a, b) => {
                if (sortVal === 'newest') {
                    return Number(b.getAttribute('data-created')) - Number(a.getAttribute('data-created'));
                } else if (sortVal === 'deadline_asc') {
                    return Number(a.getAttribute('data-deadline')) - Number(b.getAttribute('data-deadline'));
                } else if (sortVal === 'deadline_desc') {
                    return Number(b.getAttribute('data-deadline')) - Number(a.getAttribute('data-deadline'));
                } else if (sortVal === 'title_asc') {
                    return a.getAttribute('data-title').localeCompare(b.getAttribute('data-title'));
                } else if (sortVal === 'ref_asc') {
                    return a.getAttribute('data-ref').localeCompare(b.getAttribute('data-ref'));
                }
                return 0;
            });

            visibleRows.forEach(row => tbody.appendChild(row));

            const noMatchRow = document.getElementById('noMatchRow');
            const emptyTableRow = document.getElementById('emptyTableRow');
            if (noMatchRow) {
                noMatchRow.style.display = (visibleRows.length === 0 && !emptyTableRow) ? '' : 'none';
                if (visibleRows.length === 0 && noMatchRow) tbody.appendChild(noMatchRow);
            }
        }

        function resetFilters() {
            document.getElementById('searchByField').value = 'all';
            document.getElementById('tableSearchInput').value = '';
            document.getElementById('filterFlow').value = 'all';
            document.getElementById('filterStatus').value = 'all';
            document.getElementById('filterDept').value = 'all';
            document.getElementById('sortBy').value = 'newest';
            applyTableFilters();
        }

        function openDocModal() {
            if (!hasCategories) {
                alert("Please click 'Add Card to Begin' to create a Category Card first!");
                openModal('categoryModal');
                return;
            }

            if (activeCategoryId === null) {
                alert("Please click into a Category Card first to track documents inside it.");
                return;
            }

            document.getElementById('docModalCategoryId').value = activeCategoryId;
            document.getElementById('lockedCardTitle').innerText = activeCategoryName;
            
            // Reset local hold checkbox
            const chk = document.getElementById('holdLocallyChk');
            chk.checked = false;
            toggleLocalHold(false);

            openModal('docModal');
        }

        function openCategoryTable(categoryName, categoryId) {
            activeCategoryId = categoryId;
            activeCategoryName = categoryName;

            document.getElementById('page-heading').innerText = categoryName;
            document.getElementById('btnBackToDashboard').style.display = 'none';
            document.getElementById('btnBackToCategories').style.display = 'inline-flex';

            document.getElementById('categories-view').style.display = 'none';
            document.getElementById('table-view').style.display = 'block';

            applyTableFilters();
        }

        function backToCategories() {
            activeCategoryId = null;
            activeCategoryName = "";

            document.getElementById('page-heading').innerText = 'General Document Categories';
            document.getElementById('btnBackToDashboard').style.display = 'inline-flex';
            document.getElementById('btnBackToCategories').style.display = 'none';

            document.getElementById('table-view').style.display = 'none';
            document.getElementById('categories-view').style.display = 'block';
        }

        function openCustodyUpdateModal(doc) {
            document.getElementById('updateModalDocId').value = doc.id;
            document.getElementById('updateModalCatId').value = doc.category_id;
            document.getElementById('updateModalRefNo').innerText = `Update Custody: ${doc.ref_no}`;
            document.getElementById('updateModalDocTitle').innerText = doc.title;
            
            document.getElementById('workflowOperationSelect').value = 'forward';
            handleWorkflowOperationChange('forward');

            // Reset update file label
            document.getElementById('updateFileNameLabel').innerText = "Upload Revision / Supporting Files...";
            document.getElementById('updateFileInput').value = '';
            
            openModal('custodyModal');
        }

        function handleWorkflowOperationChange(val) {
            const forwardFields = document.getElementById('forwardDepartmentFields');
            const submitBtn = document.getElementById('custodySubmitBtn');
            if (val === 'forward') {
                forwardFields.style.display = 'block';
                submitBtn.innerText = 'Forward to Next Department';
            } else if (val === 'action_taken') {
                forwardFields.style.display = 'none';
                submitBtn.innerText = 'Log Action & Keep Here';
            } else if (val === 'complete') {
                forwardFields.style.display = 'none';
                submitBtn.innerText = 'Sign-off & Mark Completed';
            }
        }

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        // Renders Audit Trail with Attached Files for Each History Step
        function viewHistory(refNo, docId, fullRoute, status) {
            document.getElementById('historyDocRef').innerText = `Audit Trail: ${refNo}`;

            const journeyBox = document.getElementById('journeyChainDisplay');
            journeyBox.innerHTML = '';
            const steps = fullRoute.split('→');
            steps.forEach((step, idx) => {
                const pill = document.createElement('span');
                const isCurrent = (idx === steps.length - 1 && status !== 'completed');
                pill.className = `journey-step-pill ${isCurrent ? 'current' : ''}`;
                pill.innerText = step.trim() + (isCurrent ? ' (Current)' : '');
                journeyBox.appendChild(pill);

                if (idx < steps.length - 1) {
                    const arrow = document.createElement('span');
                    arrow.style.color = 'var(--college-gold)';
                    arrow.style.fontWeight = 'bold';
                    arrow.innerText = '→';
                    journeyBox.appendChild(arrow);
                }
            });

            const container = document.getElementById('timelineContainer');
            container.innerHTML = '';
            const logs = auditData[docId] || [];
            if (logs.length === 0) {
                container.innerHTML = '<p style="color:var(--text-muted);font-size:0.8rem;">No activity logs recorded yet.</p>';
            } else {
                logs.forEach(log => {
                    const step = document.createElement('div');
                    step.className = 'timeline-step';

                    // Parse attachments uploaded during this step
                    let stepFilesHTML = '';
                    if (log.attachments) {
                        try {
                            const files = JSON.parse(log.attachments);
                            if (Array.isArray(files) && files.length > 0) {
                                stepFilesHTML = `<div class="timeline-files-list">`;
                                files.forEach((f, idx) => {
                                    stepFilesHTML += `
                                        <button type="button" class="btn-attachment-preview" onclick="previewFile('../uploads/${encodeURIComponent(f)}', '${f}')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                                            Revision File ${idx + 1}
                                        </button>`;
                                });
                                stepFilesHTML += `</div>`;
                            }
                        } catch(e) {}
                    }

                    step.innerHTML = `
                        <div class="log-header">
                            <strong>${log.action_title}</strong>
                            <span class="log-dept">${log.department}</span>
                        </div>
                        <span class="log-time">${log.acted_by} • ${log.logged_at}</span>
                        ${log.remarks ? `<p class="log-remarks">${log.remarks}</p>` : ''}
                        ${stepFilesHTML}
                    `;
                    container.appendChild(step);
                });
            }
            openModal('historyModal');
        }

        function previewFile(fileUrl, fileName) {
            document.getElementById('previewModalTitle').innerText = fileName;
            document.getElementById('previewDownloadLink').href = fileUrl;
            const body = document.getElementById('previewModalBody');
            body.innerHTML = '';

            const ext = fileName.split('.').pop().toLowerCase();
            if (ext === 'pdf') {
                body.innerHTML = `<iframe src="${fileUrl}" style="width:100%; height:520px; border:1px solid var(--border-subtle); border-radius:8px;"></iframe>`;
            } else if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext)) {
                body.innerHTML = `<div style="text-align:center; padding:1rem;"><img src="${fileUrl}" style="max-width:100%; max-height:480px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1);"></div>`;
            } else {
                body.innerHTML = `
                    <div style="text-align:center; padding:3rem 1rem; color:var(--text-muted);">
                        <p style="font-size:0.95rem; margin-bottom:1rem;">Inline preview is not supported for <strong>.${ext}</strong> files.</p>
                        <a href="${fileUrl}" download class="btn-primary" style="display:inline-flex;">Download ${fileName}</a>
                    </div>`;
            }
            openModal('previewModal');
        }

        <?php if ($auto_open_cat > 0): ?>
            <?php foreach ($categories as $cat): ?>
                if (<?php echo $cat['id']; ?> === <?php echo $auto_open_cat; ?>) {
                    openCategoryTable('<?php echo htmlspecialchars($cat['title'], ENT_QUOTES); ?>', <?php echo $cat['id']; ?>);
                }
            <?php endforeach; ?>
        <?php endif; ?>
    </script>
</body>
</html>