<?php
session_start();

// Security Guard: Ensure user is authenticated
if (!isset($_SESSION['authenticated']) || !isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

require_once '../db.php';

$current_user_id   = (int)$_SESSION['user_id'];
$current_user_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User';

// Safe Auto-Migration
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_files` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `file_name` VARCHAR(255) NOT NULL,
        `file_path` VARCHAR(255) NOT NULL,
        `file_size` VARCHAR(50) NOT NULL,
        `category` VARCHAR(100) DEFAULT 'General',
        `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `file_transfers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `file_id` INT NOT NULL,
        `sender_id` INT NOT NULL,
        `recipient_id` INT NOT NULL,
        `status` ENUM('delivered', 'viewed', 'downloaded') DEFAULT 'delivered',
        `transferred_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");
} catch (Exception $e) {}

// Single-use Flash Toast Message
$feedback_message = $_SESSION['flash_message'] ?? '';
$feedback_type    = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

function formatBytes($bytes, $precision = 1) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Upload Documents to My Files
    if ($action === 'upload_files') {
        $category = trim($_POST['category'] ?? 'Institutional Records');

        if (!empty($_FILES['documents']['name'][0])) {
            $upload_dir = '../uploads/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

            $count = count($_FILES['documents']['name']);
            $uploaded_count = 0;

            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_OK) {
                    $orig_name  = basename($_FILES['documents']['name'][$i]);
                    $clean_file = time() . '_' . mt_rand(100, 999) . '_' . preg_replace("/[^a-zA-Z0-9._-]/", "", $orig_name);
                    $file_size  = formatBytes($_FILES['documents']['size'][$i]);

                    if (move_uploaded_file($_FILES['documents']['tmp_name'][$i], $upload_dir . $clean_file)) {
                        $stmt = $pdo->prepare("INSERT INTO user_files (user_id, file_name, file_path, file_size, category) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$current_user_id, $orig_name, $clean_file, $file_size, $category]);
                        $uploaded_count++;
                    }
                }
            }

            $_SESSION['flash_message'] = "$uploaded_count file(s) saved to My Files.";
            $_SESSION['flash_type']    = "success";
        }
        header("Location: File_Transfer.php?tab=myFiles");
        exit;
    }

    // 2. Share or Forward Files (With Authentication)
    if ($action === 'share_files') {
        $file_ids   = json_decode($_POST['selected_file_ids'] ?? '[]', true) ?: [];
        $recipients = $_POST['recipients'] ?? [];
        $passcode   = trim($_POST['auth_passcode'] ?? '');
        $source_tab = $_POST['source_tab'] ?? 'myFiles';

        if (empty($file_ids) || empty($recipients)) {
            $_SESSION['flash_message'] = "Please select files and at least one recipient.";
            $_SESSION['flash_type']    = "error";
            header("Location: File_Transfer.php?tab=" . $source_tab);
            exit;
        }

        $auth_stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $auth_stmt->execute([$current_user_id]);
        $user_auth = $auth_stmt->fetch();

        if (!$user_auth || !password_verify($passcode, $user_auth['password'])) {
            $_SESSION['flash_message'] = "Authentication Failed: Incorrect administrator passcode.";
            $_SESSION['flash_type']    = "error";
            header("Location: File_Transfer.php?tab=" . $source_tab);
            exit;
        }

        $transfer_stmt = $pdo->prepare("INSERT INTO file_transfers (file_id, sender_id, recipient_id, status) VALUES (?, ?, ?, 'delivered')");
        foreach ($file_ids as $fid) {
            foreach ($recipients as $rid) {
                // Avoid duplicate transfer entries
                $chk = $pdo->prepare("SELECT id FROM file_transfers WHERE file_id = ? AND recipient_id = ?");
                $chk->execute([(int)$fid, (int)$rid]);
                if (!$chk->fetch()) {
                    $transfer_stmt->execute([(int)$fid, $current_user_id, (int)$rid]);
                }
            }
        }

        $_SESSION['flash_message'] = "✓ Transfer Authorized! Routed securely to " . count($recipients) . " recipient(s).";
        $_SESSION['flash_type']    = "success";
        header("Location: File_Transfer.php?tab=" . $source_tab);
        exit;
    }

    // 3. UNSHARE FILE (Revoke Access Across All Recipients)
    if ($action === 'unshare_file') {
        $file_id = (int)($_POST['file_id'] ?? 0);

        // Verify ownership
        $stmt = $pdo->prepare("SELECT id, file_name FROM user_files WHERE id = ? AND user_id = ?");
        $stmt->execute([$file_id, $current_user_id]);
        $file = $stmt->fetch();

        if ($file) {
            // Delete all transfer records for this file
            $del = $pdo->prepare("DELETE FROM file_transfers WHERE file_id = ?");
            $del->execute([$file_id]);

            $_SESSION['flash_message'] = "File '<strong>" . htmlspecialchars($file['file_name']) . "</strong>' has been unshared and revoked from all recipients.";
            $_SESSION['flash_type']    = "success";
        }
        header("Location: File_Transfer.php?tab=myFiles");
        exit;
    }

    // 4. DELETE / REMOVE FILE FROM MY FILES (Single or Bulk)
    if ($action === 'delete_files') {
        $file_ids = json_decode($_POST['file_ids'] ?? '[]', true) ?: [];
        $deleted_count = 0;

        foreach ($file_ids as $fid) {
            $stmt = $pdo->prepare("SELECT file_path FROM user_files WHERE id = ? AND user_id = ?");
            $stmt->execute([(int)$fid, $current_user_id]);
            $f = $stmt->fetch();

            if ($f) {
                // Delete physical file on disk
                $filepath = '../uploads/' . $f['file_path'];
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
                // Delete active transfers
                $pdo->prepare("DELETE FROM file_transfers WHERE file_id = ?")->execute([(int)$fid]);
                // Delete record
                $pdo->prepare("DELETE FROM user_files WHERE id = ? AND user_id = ?")->execute([(int)$fid, $current_user_id]);
                $deleted_count++;
            }
        }

        $_SESSION['flash_message'] = "$deleted_count file(s) permanently deleted.";
        $_SESSION['flash_type']    = "success";
        header("Location: File_Transfer.php?tab=myFiles");
        exit;
    }

    // 5. REMOVE FILE FROM RECEIVED INBOX (Recipient Action)
    if ($action === 'dismiss_received_file') {
        $transfer_id = (int)($_POST['transfer_id'] ?? 0);

        $stmt = $pdo->prepare("DELETE FROM file_transfers WHERE id = ? AND recipient_id = ?");
        $stmt->execute([$transfer_id, $current_user_id]);

        $_SESSION['flash_message'] = "Document removed from your received inbox.";
        $_SESSION['flash_type']    = "success";
        header("Location: File_Transfer.php?tab=sharedFiles");
        exit;
    }
}

// --- FETCH DATA ---
$my_files_stmt = $pdo->prepare("
    SELECT f.*, 
        (SELECT COUNT(DISTINCT recipient_id) FROM file_transfers WHERE file_id = f.id) as shared_count 
    FROM user_files f 
    WHERE f.user_id = ? 
    ORDER BY f.id DESC
");
$my_files_stmt->execute([$current_user_id]);
$my_files = $my_files_stmt->fetchAll();

$shared_files_stmt = $pdo->prepare("
    SELECT ft.id as transfer_id, ft.file_id, ft.transferred_at, ft.status as access_status,
           uf.file_name, uf.file_path, uf.file_size, uf.category,
           u.fullname as sender_name, u.role as sender_role
    FROM file_transfers ft
    JOIN user_files uf ON ft.file_id = uf.id
    JOIN users u ON ft.sender_id = u.id
    WHERE ft.recipient_id = ?
    ORDER BY ft.id DESC
");
$shared_files_stmt->execute([$current_user_id]);
$shared_files = $shared_files_stmt->fetchAll();

$colleagues_stmt = $pdo->prepare("
    SELECT id, fullname, username, role 
    FROM users 
    WHERE id != ? AND is_archived = 0 AND status = 'active' 
    ORDER BY role ASC, fullname ASC
");
$colleagues_stmt->execute([$current_user_id]);
$colleagues = $colleagues_stmt->fetchAll();

$active_tab = $_GET['tab'] ?? 'myFiles';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BSBT College | Secure File Transferring</title>
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
            
            --badge-in-bg: #dbeafe;
            --badge-in: #2563eb;
            --badge-shared-bg: #fef3c7;
            --badge-shared: #d97706;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', system-ui, sans-serif; }

        body {
            background-color: var(--bg-canvas);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            position: sticky; top: 0; z-index: 50; background: #ffffff;
            border-bottom: 1px solid var(--border-subtle); padding: 0.75rem 2.5rem; flex-shrink: 0;
        }

        nav { max-width: 1440px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; }
        .nav-left { display: flex; align-items: center; gap: 16px; }
        #logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        #logo img { height: 36px; width: auto; object-fit: contain; }
        .brand-text h2 { font-size: 0.95rem; font-weight: 800; color: var(--college-navy); line-height: 1.1; }
        .brand-text span { font-size: 0.7rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .terminal-pill { font-size: 0.72rem; font-weight: 700; background: rgba(197, 155, 39, 0.12); color: #99720d; border: 1px solid rgba(197, 155, 39, 0.3); padding: 0.25rem 0.75rem; border-radius: 20px; text-transform: uppercase; }

        .nav-actions { display: flex; align-items: center; gap: 12px; }
        .sys-status { display: flex; align-items: center; gap: 7px; font-size: 0.75rem; font-weight: 600; color: #15803d; background: #f0fdf4; padding: 0.3rem 0.8rem; border-radius: 20px; border: 1px solid #bbf7d0; }
        .status-dot { width: 7px; height: 7px; background-color: #22c55e; border-radius: 50%; box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2); }
        .nav-help-link { display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 600; color: var(--text-muted); text-decoration: none; padding: 0.35rem 0.75rem; border-radius: 8px; background: #f8fafc; border: 1px solid var(--border-subtle); }
        .btn-logout { display: flex; align-items: center; gap: 6px; background: #fff1f2; color: #e11d48; border: 1px solid #ffe4e6; padding: 0.5rem 0.9rem; border-radius: 8px; font-size: 0.82rem; font-weight: 600; cursor: pointer; text-decoration: none; }

        main { flex: 1; max-width: 1440px; width: 100%; margin: 0 auto; padding: 1.5rem 2.5rem 2.5rem; }

        .btn-back-top {
            display: inline-flex; align-items: center; gap: 6px; background: #ffffff; color: var(--college-navy);
            border: 1.5px solid var(--border-subtle); padding: 0.45rem 0.85rem; border-radius: var(--radius-md);
            font-size: 0.8rem; font-weight: 700; cursor: pointer; text-decoration: none; box-shadow: var(--shadow-sm);
            transition: all 0.2s ease; width: fit-content; margin-bottom: 0.85rem;
        }
        .btn-back-top:hover { background: #f1f5f9; transform: translateX(-2px); }

        .page-toolbar {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;
            padding-bottom: 0.85rem; border-bottom: 1px solid var(--border-subtle);
        }
        .page-toolbar-left h1 { font-size: 1.55rem; font-weight: 800; color: var(--college-navy); letter-spacing: -0.3px; }
        .page-toolbar-left p { font-size: 0.85rem; color: var(--text-muted); }

        .btn-primary {
            display: inline-flex; align-items: center; gap: 6px; background: var(--college-navy); color: #ffffff;
            border: none; padding: 0.55rem 0.95rem; border-radius: var(--radius-md); font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease;
        }
        .btn-primary:hover { background: var(--college-navy-light); }
        .btn-share { background: var(--college-blue); }
        .btn-share:hover { background: #1e40af; }
        
        .btn-danger-outline {
            background: #ffffff; border: 1.5px solid #fecaca; color: #dc2626;
            padding: 0.55rem 0.95rem; border-radius: var(--radius-md); font-size: 0.82rem; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
        }
        .btn-danger-outline:hover { background: #fef2f2; border-color: #dc2626; }

        /* Toast Notification */
        .toast-notification {
            position: fixed; top: 24px; right: 24px; z-index: 9999; display: flex; align-items: center;
            gap: 12px; padding: 0.95rem 1.25rem; border-radius: var(--radius-md);
            box-shadow: 0 15px 30px -5px rgba(9, 26, 54, 0.2); background: #ffffff; border: 1px solid var(--border-subtle);
            font-size: 0.88rem; max-width: 440px; animation: toastSlideIn 0.35s ease forwards;
        }
        @keyframes toastSlideIn { 0% { opacity: 0; transform: translateY(-16px); } 100% { opacity: 1; transform: translateY(0); } }
        .toast-notification.success { border-left: 4.5px solid #10b981; color: #065f46; }
        .toast-notification.error { border-left: 4.5px solid #ef4444; color: #991b1b; }
        .toast-close-btn { background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 1.2rem; }

        /* Tab Switcher */
        .tab-switcher {
            display: inline-flex; background: #f1f5f9; padding: 4px; border-radius: 10px;
            border: 1.5px solid var(--border-subtle); margin-bottom: 1.25rem;
        }
        .tab-btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 0.55rem 1.25rem;
            border: none; background: transparent; font-size: 0.85rem; font-weight: 700;
            color: var(--text-muted); border-radius: 8px; cursor: pointer; text-decoration: none;
        }
        .tab-btn.active { background: #ffffff; color: var(--college-navy); box-shadow: 0 2px 5px rgba(0,0,0,0.06); }
        .tab-pill { font-size: 0.72rem; background: #e2e8f0; color: var(--text-dark); padding: 0.15rem 0.45rem; border-radius: 12px; }
        .tab-btn.active .tab-pill { background: var(--college-navy); color: #ffffff; }

        /* Action Toolbar */
        .action-bar {
            background: #ffffff; border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
            padding: 0.85rem 1.25rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; box-shadow: var(--shadow-sm);
        }
        .selection-indicator { font-size: 0.82rem; font-weight: 700; color: var(--college-navy); }

        /* Tables */
        .table-container {
            background: var(--card-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
            overflow: hidden; box-shadow: var(--shadow-sm);
        }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem; }
        thead { background: #f8fafc; border-bottom: 1px solid var(--border-subtle); }
        th { padding: 0.85rem 1.25rem; font-weight: 700; color: var(--college-navy); text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.5px; }
        td { padding: 0.95rem 1.25rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        tbody tr:hover { background-color: #fafbfd; }

        input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--college-navy); }

        .file-name-cell { display: flex; align-items: center; gap: 12px; }
        .file-icon-box {
            width: 36px; height: 36px; border-radius: 8px; background: #f1f5f9;
            display: flex; align-items: center; justify-content: center; color: var(--college-navy); flex-shrink: 0;
        }
        .file-meta strong { display: block; color: var(--text-dark); font-size: 0.88rem; }
        .file-meta span { font-size: 0.72rem; color: var(--text-muted); }

        .badge { display: inline-flex; align-items: center; padding: 0.25rem 0.65rem; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
        .badge-shared { background: var(--badge-shared-bg); color: var(--badge-shared); }
        .badge-received { background: var(--badge-in-bg); color: var(--badge-in); }

        .btn-table-action {
            display: inline-flex; align-items: center; gap: 4px; padding: 0.35rem 0.7rem;
            border-radius: 6px; font-size: 0.75rem; font-weight: 600; text-decoration: none;
            border: 1px solid var(--border-subtle); background: #ffffff; color: var(--college-navy);
            cursor: pointer; transition: all 0.2s;
        }
        .btn-table-action:hover { background: #f1f5f9; border-color: #cbd5e1; }

        /* Action Button Variants */
        .btn-action-unshare {
            color: #b45309; background: #fffbeb; border-color: #fde68a;
        }
        .btn-action-unshare:hover { background: #fef3c7; border-color: #f59e0b; color: #92400e; }

        .btn-action-delete {
            color: #dc2626; background: #ffffff; border-color: #fecaca;
        }
        .btn-action-delete:hover { background: #fee2e2; border-color: #ef4444; }

        /* Modals */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(9, 26, 54, 0.5); backdrop-filter: blur(5px);
            display: none; align-items: center; justify-content: center; z-index: 100;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: #ffffff; border-radius: var(--radius-lg); width: 100%;
            max-width: 500px; padding: 2.25rem; box-shadow: 0 25px 50px -12px rgba(9, 26, 54, 0.25);
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; padding-bottom: 0.85rem; border-bottom: 1px solid var(--border-subtle); }
        .modal-header h3 { font-size: 1.25rem; font-weight: 800; color: var(--college-navy); }
        .modal-close { background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 8px; font-size: 1.25rem; cursor: pointer; color: var(--text-muted); display: flex; align-items: center; justify-content: center; }

        .user-picker-list {
            max-height: 220px; overflow-y: auto; border: 1.5px solid var(--border-subtle);
            border-radius: var(--radius-md); padding: 8px; display: flex; flex-direction: column;
            gap: 6px; margin: 1rem 0;
        }
        .user-picker-item {
            display: flex; align-items: center; gap: 12px; padding: 8px 10px;
            border-radius: 6px; cursor: pointer; transition: background 0.15s;
        }
        .user-picker-item:hover { background: #f1f5f9; }
        .user-info strong { font-size: 0.84rem; color: var(--text-dark); display: block; }
        .user-info span { font-size: 0.72rem; color: var(--text-muted); }

        .pin-input-box {
            width: 180px; height: 48px; text-align: center; font-size: 1.4rem;
            letter-spacing: 10px; border: 2px solid var(--border-subtle);
            border-radius: var(--radius-md); margin: 1.25rem auto; display: block; outline: none;
        }
        .pin-input-box:focus { border-color: var(--college-navy); }

        .btn-modal-submit {
            background: linear-gradient(180deg, var(--college-navy) 0%, #07152b 100%);
            color: #ffffff; border: none; border-radius: var(--radius-md); height: 46px;
            font-size: 0.95rem; font-weight: 700; cursor: pointer; width: 100%; margin-top: 0.5rem;
        }
    </style>
</head>
<body>

    <!-- Toast Notification -->
    <?php if (!empty($feedback_message)): ?>
        <div class="toast-notification <?php echo $feedback_type; ?>" id="feedbackToast">
            <?php if ($feedback_type === 'success'): ?>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <?php else: ?>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <?php endif; ?>
            <span><?php echo $feedback_message; ?></span>
            <button type="button" class="toast-close-btn" onclick="dismissToast()">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Master Navbar -->
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
                <div class="sys-status">
                    <span class="status-dot"></span>
                    <span>Portal Online</span>
                </div>

                <a href="#" class="nav-help-link" title="Helpdesk">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="10" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    Helpdesk
                </a>

                <a href="../logout.php" class="btn-logout" title="Log Out">
                    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2">
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

        <a href="../Admin/admin.php" class="btn-back-top">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
            Back to Dashboard
        </a>

        <div class="page-toolbar">
            <div class="page-toolbar-left">
                <h1>Institutional Document Transfer</h1>
                <p>Upload personal documents to My Files, share with colleagues, and forward received files.</p>
            </div>
            
            <div>
                <button type="button" class="btn-primary" onclick="openModal('uploadModal')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    Upload to My Files
                </button>
            </div>
        </div>

        <!-- Tab Controls -->
        <div class="tab-switcher">
            <a href="File_Transfer.php?tab=myFiles" class="tab-btn <?php echo $active_tab !== 'sharedFiles' ? 'active' : ''; ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                My Files <span class="tab-pill"><?php echo count($my_files); ?></span>
            </a>
            <a href="File_Transfer.php?tab=sharedFiles" class="tab-btn <?php echo $active_tab === 'sharedFiles' ? 'active' : ''; ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                Received / Shared Files <span class="tab-pill"><?php echo count($shared_files); ?></span>
            </a>
        </div>

        <?php if ($active_tab !== 'sharedFiles'): ?>
            <!-- ==============================================
                 CONTAINER 1: MY FILES
                 ============================================== -->
            <section id="sectionMyFiles">
                <div class="action-bar">
                    <span class="selection-indicator" id="mySelectionText">0 files selected</span>
                    <div style="display: flex; gap: 8px;">
                        <!-- Bulk Delete Button -->
                        <button type="button" class="btn-danger-outline" id="btnDeleteSelected" onclick="deleteSelectedFiles()" disabled style="opacity: 0.5;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            Delete Selected
                        </button>

                        <!-- Share Selected Button -->
                        <button type="button" class="btn-primary btn-share" id="btnShareMyFiles" onclick="openShareModal('myFiles')" disabled style="opacity: 0.5;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
                            Share Selected
                        </button>
                    </div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="masterMyFiles" onchange="toggleSelectAll('myFiles')"></th>
                                <th>Document Name</th>
                                <th>Category</th>
                                <th>Date Uploaded</th>
                                <th>File Size</th>
                                <th>Status</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($my_files)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; padding:3.5rem 1rem; color:var(--text-muted);">
                                        No files stored yet. Click <strong>"Upload to My Files"</strong> above to add documents.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($my_files as $f): ?>
                                    <tr>
                                        <td><input type="checkbox" class="file-chk-my" value="<?php echo $f['id']; ?>" onchange="updateSelection('myFiles')"></td>
                                        <td>
                                            <div class="file-name-cell">
                                                <div class="file-icon-box">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                                </div>
                                                <div class="file-meta">
                                                    <strong><?php echo htmlspecialchars($f['file_name']); ?></strong>
                                                    <span>Uploaded by Me</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($f['category']); ?></td>
                                        <td><?php echo date('M d, Y • h:i A', strtotime($f['uploaded_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($f['file_size']); ?></td>
                                        <td>
                                            <?php if ($f['shared_count'] > 0): ?>
                                                <span class="badge badge-shared">Shared (<?php echo $f['shared_count']; ?> Recipients)</span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#f1f5f9; color:var(--text-dark);">Stored Locally</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display:inline-flex; align-items:center; gap:6px;">
                                                <a href="../uploads/<?php echo urlencode($f['file_path']); ?>" download="<?php echo htmlspecialchars($f['file_name']); ?>" class="btn-table-action" title="Download File">
                                                    Download
                                                </a>
                                                
                                                <button type="button" class="btn-table-action" onclick="shareSingleFile(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars($f['file_name'], ENT_QUOTES); ?>', 'myFiles')">
                                                    Share
                                                </button>

                                                <!-- UNSHARE BUTTON (Appears when file is currently shared) -->
                                                <?php if ($f['shared_count'] > 0): ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Revoke shared access? This document will be unshared and removed from all recipient accounts.');">
                                                        <input type="hidden" name="action" value="unshare_file">
                                                        <input type="hidden" name="file_id" value="<?php echo $f['id']; ?>">
                                                        <button type="submit" class="btn-table-action btn-action-unshare" title="Unshare document from all accounts">
                                                            Unshare
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <!-- SINGLE REMOVE / DELETE BUTTON -->
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this file? It will be removed from your files and any shared recipients.');">
                                                    <input type="hidden" name="action" value="delete_files">
                                                    <input type="hidden" name="file_ids" value="<?php echo htmlspecialchars(json_encode([$f['id']])); ?>">
                                                    <button type="submit" class="btn-table-action btn-action-delete" title="Delete file permanently">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php else: ?>
            <!-- ==============================================
                 CONTAINER 2: RECEIVED / SHARED FILES
                 ============================================== -->
            <section id="sectionSharedFiles">
                <div class="action-bar">
                    <span class="selection-indicator" id="sharedSelectionText">0 files selected</span>
                    <button type="button" class="btn-primary btn-share" id="btnForwardShared" onclick="openShareModal('sharedFiles')" disabled style="opacity: 0.5;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                        Forward Selected
                    </button>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="masterShared" onchange="toggleSelectAll('sharedFiles')"></th>
                                <th>Document Name</th>
                                <th>Sender Account</th>
                                <th>Received Date</th>
                                <th>File Size</th>
                                <th>Status</th>
                                <th style="text-align: right;">Options</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($shared_files)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; padding:3.5rem 1rem; color:var(--text-muted);">
                                        No shared documents received yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($shared_files as $sf): ?>
                                    <tr>
                                        <td><input type="checkbox" class="file-chk-shared" value="<?php echo $sf['file_id']; ?>" onchange="updateSelection('sharedFiles')"></td>
                                        <td>
                                            <div class="file-name-cell">
                                                <div class="file-icon-box">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                                </div>
                                                <div class="file-meta">
                                                    <strong><?php echo htmlspecialchars($sf['file_name']); ?></strong>
                                                    <span>Category: <?php echo htmlspecialchars($sf['category']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($sf['sender_name']); ?></strong>
                                            <span style="font-size:0.75rem; color:var(--text-muted); display:block;"><?php echo ucfirst($sf['sender_role']); ?> Dept</span>
                                        </td>
                                        <td><?php echo date('M d, Y • h:i A', strtotime($sf['transferred_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($sf['file_size']); ?></td>
                                        <td><span class="badge badge-received">Ready to Forward</span></td>
                                        <td style="text-align: right;">
                                            <div style="display:inline-flex; align-items:center; gap:6px;">
                                                <a href="../uploads/<?php echo urlencode($sf['file_path']); ?>" download="<?php echo htmlspecialchars($sf['file_name']); ?>" class="btn-table-action" title="Download">
                                                    Download
                                                </a>
                                                
                                                <button type="button" class="btn-table-action" onclick="shareSingleFile(<?php echo $sf['file_id']; ?>, '<?php echo htmlspecialchars($sf['file_name'], ENT_QUOTES); ?>', 'sharedFiles')">
                                                    Forward
                                                </button>

                                                <!-- DISMISS FROM RECIPIENT INBOX -->
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this document from your received inbox?');">
                                                    <input type="hidden" name="action" value="dismiss_received_file">
                                                    <input type="hidden" name="transfer_id" value="<?php echo $sf['transfer_id']; ?>">
                                                    <button type="submit" class="btn-table-action btn-action-delete" title="Remove from my inbox">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <!-- FORM FOR BULK DELETE SUBMISSION -->
    <form id="bulkDeleteForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete_files">
        <input type="hidden" name="file_ids" id="bulkDeleteIdsInput">
    </form>

    <!-- MODAL 1: UPLOAD DOCUMENTS -->
    <div class="modal-overlay" id="uploadModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload to My Files</h3>
                <button type="button" class="modal-close" onclick="closeModal('uploadModal')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_files">
                
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div>
                        <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--college-navy);">Select File(s)</label>
                        <input type="file" name="documents[]" multiple required style="width:100%; padding:0.65rem; border:1.5px solid var(--border-subtle); border-radius:8px; margin-top:4px;">
                        <small style="font-size:0.72rem; color:var(--text-muted);">Hold Ctrl / Shift to pick multiple files.</small>
                    </div>

                    <div>
                        <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--college-navy);">Document Category</label>
                        <select name="category" style="width:100%; height:44px; padding:0 0.85rem; border:1.5px solid var(--border-subtle); border-radius:8px; margin-top:4px;">
                            <option value="Institutional Records">Institutional Records</option>
                            <option value="Student Clearances">Student Clearances</option>
                            <option value="Curriculum / Academic Reports">Curriculum / Academic Reports</option>
                            <option value="Administrative Orders">Administrative Orders</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn-modal-submit" style="margin-top:1.5rem;">Save to My Files</button>
            </form>
        </div>
    </div>

    <!-- MODAL 2 & 3: RECIPIENT PICKER & AUTHENTICATION -->
    <div class="modal-overlay" id="shareFlowModal">
        <div class="modal-content">
            
            <div id="stepRecipients">
                <div class="modal-header">
                    <h3 id="shareFlowModalTitle">Share Document</h3>
                    <button type="button" class="modal-close" onclick="closeModal('shareFlowModal')">&times;</button>
                </div>
                <p style="font-size:0.82rem; color:var(--text-muted);">Select one or more colleagues to receive the file(s):</p>

                <div class="user-picker-list">
                    <?php if (empty($colleagues)): ?>
                        <p style="font-size:0.8rem; color:var(--text-muted); padding:1rem; text-align:center;">No other active colleagues found in database.</p>
                    <?php else: ?>
                        <?php foreach ($colleagues as $c): ?>
                            <label class="user-picker-item">
                                <input type="checkbox" name="selected_recipients_chk" value="<?php echo $c['id']; ?>">
                                <div class="user-info">
                                    <strong><?php echo htmlspecialchars($c['fullname']); ?></strong>
                                    <span><?php echo ucfirst($c['role']); ?> Dept • <?php echo htmlspecialchars($c['username']); ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button type="button" class="btn-primary btn-share" style="width:100%; justify-content:center; height:46px;" onclick="goToAuthStep()">
                    Proceed to Authorization
                </button>
            </div>

            <form id="stepAuthForm" method="POST" style="display:none; text-align:center;">
                <input type="hidden" name="action" value="share_files">
                <input type="hidden" name="selected_file_ids" id="authFileIds">
                <input type="hidden" name="source_tab" id="authSourceTab" value="myFiles">
                <div id="authRecipientsContainer"></div>

                <div class="modal-header" style="justify-content:center; position:relative;">
                    <h3>Security Authorization</h3>
                    <button type="button" class="modal-close" style="position:absolute; right:0;" onclick="closeModal('shareFlowModal')">&times;</button>
                </div>

                <p style="font-size:0.82rem; color:var(--text-muted); line-height:1.4;">
                    Clearance required. Please enter your administrator passcode to confirm this file dispatch.
                </p>

                <input type="password" name="auth_passcode" id="authPassInput" class="pin-input-box" placeholder="••••" required autocomplete="current-password">

                <button type="submit" class="btn-modal-submit">Verify & Dispatch Files</button>
            </form>

        </div>
    </div>

    <script>
        const toast = document.getElementById('feedbackToast');
        if (toast) { setTimeout(() => dismissToast(), 3500); }

        function dismissToast() {
            const toastElem = document.getElementById('feedbackToast');
            if (toastElem) {
                toastElem.style.opacity = '0';
                toastElem.style.transform = 'translateY(-12px)';
                setTimeout(() => toastElem.remove(), 300);
            }
        }

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        let currentTransferFiles = [];
        let currentSourceTab = 'myFiles';

        function toggleSelectAll(type) {
            const master = type === 'myFiles' ? document.getElementById('masterMyFiles') : document.getElementById('masterShared');
            const checkboxes = type === 'myFiles' ? document.querySelectorAll('.file-chk-my') : document.querySelectorAll('.file-chk-shared');
            checkboxes.forEach(chk => chk.checked = master.checked);
            updateSelection(type);
        }

        function updateSelection(type) {
            const checkboxes = type === 'myFiles' ? document.querySelectorAll('.file-chk-my:checked') : document.querySelectorAll('.file-chk-shared:checked');
            const count = checkboxes.length;
            const textElem = type === 'myFiles' ? document.getElementById('mySelectionText') : document.getElementById('sharedSelectionText');
            const btnShare = type === 'myFiles' ? document.getElementById('btnShareMyFiles') : document.getElementById('btnForwardShared');
            const btnDelete = document.getElementById('btnDeleteSelected');

            if (textElem) textElem.innerText = `${count} file${count === 1 ? '' : 's'} selected`;
            
            if (btnShare) {
                btnShare.disabled = (count === 0);
                btnShare.style.opacity = (count > 0) ? '1' : '0.5';
            }

            if (btnDelete && type === 'myFiles') {
                btnDelete.disabled = (count === 0);
                btnDelete.style.opacity = (count > 0) ? '1' : '0.5';
            }
        }

        // Bulk Delete Selected Files
        function deleteSelectedFiles() {
            const checkboxes = document.querySelectorAll('.file-chk-my:checked');
            const ids = Array.from(checkboxes).map(c => c.value);

            if (ids.length === 0) return;

            if (confirm(`Permanently delete the selected ${ids.length} file(s)? They will also be revoked from any shared accounts.`)) {
                document.getElementById('bulkDeleteIdsInput').value = JSON.stringify(ids);
                document.getElementById('bulkDeleteForm').submit();
            }
        }

        function shareSingleFile(fileId, fileName, sourceTab) {
            currentTransferFiles = [fileId];
            currentSourceTab = sourceTab;
            document.getElementById('shareFlowModalTitle').innerText = (sourceTab === 'myFiles' ? 'Share: ' : 'Forward: ') + fileName;
            resetShareFlowModal();
            openModal('shareFlowModal');
        }

        function openShareModal(sourceTab) {
            const checkboxes = sourceTab === 'myFiles' 
                ? document.querySelectorAll('.file-chk-my:checked') 
                : document.querySelectorAll('.file-chk-shared:checked');
            
            currentTransferFiles = Array.from(checkboxes).map(c => c.value);
            currentSourceTab = sourceTab;
            document.getElementById('shareFlowModalTitle').innerText = (sourceTab === 'myFiles' ? 'Share (' : 'Forward (') + currentTransferFiles.length + ') Files';
            resetShareFlowModal();
            openModal('shareFlowModal');
        }

        function resetShareFlowModal() {
            document.getElementById('stepRecipients').style.display = 'block';
            document.getElementById('stepAuthForm').style.display = 'none';
            document.querySelectorAll('input[name="selected_recipients_chk"]').forEach(c => c.checked = false);
            document.getElementById('authPassInput').value = '';
        }

        function goToAuthStep() {
            const checkedRecipients = document.querySelectorAll('input[name="selected_recipients_chk"]:checked');
            if (checkedRecipients.length === 0) {
                alert("Please select at least one recipient colleague or department.");
                return;
            }

            document.getElementById('authFileIds').value = JSON.stringify(currentTransferFiles);
            document.getElementById('authSourceTab').value = currentSourceTab;

            const container = document.getElementById('authRecipientsContainer');
            container.innerHTML = '';
            checkedRecipients.forEach(r => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'recipients[]';
                hiddenInput.value = r.value;
                container.appendChild(hiddenInput);
            });

            document.getElementById('stepRecipients').style.display = 'none';
            document.getElementById('stepAuthForm').style.display = 'block';
            setTimeout(() => document.getElementById('authPassInput').focus(), 150);
        }
    </script>
</body>
</html>