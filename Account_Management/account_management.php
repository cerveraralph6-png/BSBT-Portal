<?php
session_start();

if (!isset($_SESSION['authenticated']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../index.php");
    exit;
}

require_once '../db.php';

// Safe Auto-Migration: Ensure status supports pending_approval
try {
    $pdo->exec("ALTER TABLE users MODIFY COLUMN status ENUM('active', 'suspended', 'archived', 'pending_approval') DEFAULT 'active'");
} catch (Exception $e) {}

$current_admin_id = $_SESSION['user_id'] ?? 0;

$feedback_message = $_SESSION['flash_message'] ?? '';
$feedback_type    = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Approve External Registration Request
    if ($action === 'approve_request') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending_approval'");
            $stmt->execute([$user_id]);
            $_SESSION['flash_message'] = "Registration request approved! Account is now active and ready to log in.";
            $_SESSION['flash_type'] = "success";
        }
        header("Location: account_management.php?tab=requests");
        exit;
    }

    // 2. Reject External Registration Request
    if ($action === 'reject_request') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND status = 'pending_approval'");
            $stmt->execute([$user_id]);
            $_SESSION['flash_message'] = "Registration request declined and removed.";
            $_SESSION['flash_type'] = "success";
        }
        header("Location: account_management.php?tab=requests");
        exit;
    }

    // 3. Create Account (Manual by Admin)
    if ($action === 'create_user') {
        $fullname = trim($_POST['fullname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $passcode = trim($_POST['passcode'] ?? '');
        $role     = $_POST['role'] ?? 'academics';

        if (!empty($fullname) && !empty($username) && !empty($passcode)) {
            $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
            $check->execute([$username]);
            if ($check->fetch()) {
                $_SESSION['flash_message'] = "The username '<strong>" . htmlspecialchars($username) . "</strong>' is already registered.";
                $_SESSION['flash_type'] = "error";
            } else {
                $hashed_pass = password_hash($passcode, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (fullname, username, password, role, status, is_archived) VALUES (?, ?, ?, ?, 'active', 0)");
                $stmt->execute([$fullname, $username, $hashed_pass, $role]);
                $_SESSION['flash_message'] = "Account for <strong>" . htmlspecialchars($fullname) . "</strong> created successfully!";
                $_SESSION['flash_type'] = "success";
            }
        }
        header("Location: account_management.php");
        exit;
    }

    // 4. Edit User
    if ($action === 'edit_user') {
        $user_id  = (int)($_POST['user_id'] ?? 0);
        $fullname = trim($_POST['fullname'] ?? '');
        $role     = $_POST['role'] ?? 'academics';
        $status   = $_POST['status'] ?? 'active';

        if ($user_id > 0 && !empty($fullname)) {
            if ($user_id === 1 || $user_id === $current_admin_id) {
                $status = 'active';
                $role = 'admin';
            }
            $stmt = $pdo->prepare("UPDATE users SET fullname = ?, role = ?, status = ? WHERE id = ?");
            $stmt->execute([$fullname, $role, $status, $user_id]);
            $_SESSION['flash_message'] = "User details updated successfully.";
            $_SESSION['flash_type'] = "success";
        }
        header("Location: account_management.php");
        exit;
    }

    // 5. Reset Passcode
    if ($action === 'reset_passcode') {
        $user_id      = (int)($_POST['user_id'] ?? 0);
        $new_passcode = trim($_POST['new_passcode'] ?? '');
        if ($user_id > 0 && !empty($new_passcode)) {
            $hashed = password_hash($new_passcode, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $user_id]);
            $_SESSION['flash_message'] = "Passcode reset successfully.";
            $_SESSION['flash_type'] = "success";
        }
        header("Location: account_management.php");
        exit;
    }

    // 6. Archive User
    if ($action === 'archive_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            if ($user_id === 1 || $user_id === $current_admin_id) {
                $_SESSION['flash_message'] = "Cannot archive the active master administrator account.";
                $_SESSION['flash_type'] = "error";
            } else {
                $stmt = $pdo->prepare("UPDATE users SET is_archived = 1, status = 'archived' WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['flash_message'] = "Account safely archived. Historical records preserved.";
                $_SESSION['flash_type'] = "success";
            }
        }
        header("Location: account_management.php");
        exit;
    }

    // 7. Restore User
    if ($action === 'restore_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            $stmt = $pdo->prepare("UPDATE users SET is_archived = 0, status = 'active' WHERE id = ?");
            $stmt->execute([$user_id]);
            $_SESSION['flash_message'] = "Account restored and re-activated successfully.";
            $_SESSION['flash_type'] = "success";
        }
        header("Location: account_management.php");
        exit;
    }
}

// --- METRIC COUNTERS ---
$totalUsers       = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_archived = 0 AND status != 'pending_approval'")->fetchColumn();
$pendingRequests  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending_approval'")->fetchColumn();
$adminUsers       = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_archived = 0 AND status != 'pending_approval'")->fetchColumn();
$academicsUsers   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'academics' AND is_archived = 0 AND status != 'pending_approval'")->fetchColumn();
$registrarUsers   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'registrar' AND is_archived = 0 AND status != 'pending_approval'")->fetchColumn();

// --- FETCH ACTIVE USERS & PENDING REQUESTS ---
$users = $pdo->query("SELECT id, fullname, username, role, status, is_archived, created_at FROM users WHERE status != 'pending_approval' ORDER BY is_archived ASC, id DESC")->fetchAll();
$requests = $pdo->query("SELECT id, fullname, username, role, created_at FROM users WHERE status = 'pending_approval' ORDER BY id DESC")->fetchAll();

// Construct base URL for external registration link
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$registration_link = $protocol . "://" . $host . "/bsbt-portal/register.php";

$active_tab = $_GET['tab'] ?? 'roster';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BSBT College | Account Management</title>
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
            
            --role-admin-bg: #e0e7ff;
            --role-admin-text: #3730a3;
            --role-acad-bg: #dbeafe;
            --role-acad-text: #1e40af;
            --role-reg-bg: #fef3c7;
            --role-reg-text: #92400e;
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
        .page-toolbar-actions { display: flex; align-items: center; gap: 10px; }

        .btn-primary {
            display: inline-flex; align-items: center; gap: 6px; background: var(--college-navy); color: #ffffff;
            border: none; padding: 0.55rem 0.95rem; border-radius: var(--radius-md); font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease;
        }
        .btn-primary:hover { background: var(--college-navy-light); }

        .btn-outline {
            display: inline-flex; align-items: center; gap: 6px; background: #ffffff; color: var(--college-navy);
            border: 1.5px solid var(--border-subtle); padding: 0.4rem 0.75rem; border-radius: var(--radius-md);
            font-size: 0.76rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease;
        }
        .btn-outline:hover { background: #f1f5f9; }

        /* Floating Toast */
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

        /* Metric Counters */
        .stats-overview {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.25rem; margin-bottom: 1.75rem;
        }
        .stat-card {
            background: var(--card-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
            padding: 1.25rem 1.4rem; box-shadow: var(--shadow-sm); display: flex; align-items: center; justify-content: space-between;
        }
        .stat-card-left span.stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); display: block; margin-bottom: 0.25rem; }
        .stat-card-left h2.stat-number { font-size: 1.85rem; font-weight: 800; line-height: 1; color: var(--college-navy); }
        .stat-card-left small.stat-subtext { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.35rem; display: block; }
        .stat-card-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .stat-card-icon svg { width: 22px; height: 22px; stroke-width: 2.2; fill: none; }
        .card-total .stat-card-icon { background: #f1f5f9; color: var(--college-navy); }
        .card-admin .stat-card-icon { background: var(--role-admin-bg); color: var(--role-admin-text); }
        .card-acad .stat-card-icon { background: var(--role-acad-bg); color: var(--role-acad-text); }
        .card-reg .stat-card-icon { background: var(--role-reg-bg); color: var(--role-reg-text); }

        /* View Tabs (Roster vs Requests) */
        .section-nav-tabs {
            display: flex; gap: 8px; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-subtle); padding-bottom: 2px;
        }
        .tab-link {
            padding: 0.6rem 1.25rem; font-size: 0.85rem; font-weight: 700; color: var(--text-muted);
            text-decoration: none; border-bottom: 3px solid transparent; display: inline-flex; align-items: center; gap: 8px;
            transition: all 0.2s;
        }
        .tab-link.active { color: var(--college-navy); border-bottom-color: var(--college-navy); }
        .badge-request-count {
            background: #ef4444; color: #ffffff; font-size: 0.68rem; padding: 2px 7px; border-radius: 12px;
        }

        /* Search & Filter Toolbar */
        .table-filter-toolbar {
            background: var(--card-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-md);
            padding: 0.9rem 1.1rem; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: var(--shadow-sm);
        }
        .search-input-wrap { position: relative; flex: 1; min-width: 280px; display: flex; align-items: center; }
        .search-input-wrap svg { position: absolute; left: 10px; width: 15px; height: 15px; stroke: var(--text-muted); }
        .search-input-wrap input { width: 100%; height: 38px; padding: 0 0.85rem 0 2.15rem; border: 1.5px solid var(--border-subtle); border-radius: 6px; font-size: 0.85rem; background: #ffffff; outline: none; }
        .toolbar-group-right { display: flex; align-items: center; gap: 8px; }
        .filter-select-box { height: 38px; padding: 0 0.75rem; border: 1.5px solid var(--border-subtle); border-radius: 6px; font-size: 0.82rem; color: var(--text-dark); background: #ffffff; outline: none; cursor: pointer; }

        /* Tables */
        .table-container {
            background: var(--card-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
            overflow: hidden; box-shadow: var(--shadow-sm);
        }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem; }
        thead { background: #f8fafc; border-bottom: 1px solid var(--border-subtle); }
        th { padding: 0.85rem 1.25rem; font-weight: 700; color: var(--college-navy); text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.5px; }
        td { padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        tbody tr:hover { background-color: #fafbfd; }
        .user-row.row-archived { background-color: #fcfcfd; opacity: 0.75; }

        .user-avatar-wrap { display: flex; align-items: center; gap: 10px; }
        .avatar-initial { width: 36px; height: 36px; border-radius: 50%; background: #eef2f8; color: var(--college-navy); font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 0.82rem; border: 1.5px solid #d8e2f0; }

        .role-badge { display: inline-flex; align-items: center; padding: 3px 8px; border-radius: 6px; font-size: 0.74rem; font-weight: 700; text-transform: uppercase; }
        .role-admin { background: var(--role-admin-bg); color: var(--role-admin-text); }
        .role-academics { background: var(--role-acad-bg); color: var(--role-acad-text); }
        .role-registrar { background: var(--role-reg-bg); color: var(--role-reg-text); }

        .status-pill { display: inline-flex; align-items: center; gap: 5px; font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 20px; }
        .status-active { background: #ecfdf5; color: #047857; }
        .status-active .dot { width: 5px; height: 5px; border-radius: 50%; background: #10b981; }
        .status-suspended { background: #fef2f2; color: #b91c1c; }
        .status-suspended .dot { width: 5px; height: 5px; border-radius: 50%; background: #ef4444; }
        .status-archived { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
        .status-archived .dot { width: 5px; height: 5px; border-radius: 50%; background: #94a3b8; }

        /* Modals */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(9, 26, 54, 0.5); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 100; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 580px; padding: 2.25rem; box-shadow: 0 25px 50px -12px rgba(9, 26, 54, 0.25); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 0.85rem; border-bottom: 1px solid var(--border-subtle); }
        .modal-header h3 { font-size: 1.3rem; font-weight: 800; color: var(--college-navy); }
        .modal-close { background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 8px; font-size: 1.25rem; cursor: pointer; color: var(--text-muted); display: flex; align-items: center; justify-content: center; }

        .modal-form { display: flex; flex-direction: column; gap: 1.25rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.45rem; }
        .form-group label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--college-navy); letter-spacing: 0.5px; }
        .form-group input, .form-group select { height: 44px; padding: 0 0.85rem; border: 1.5px solid var(--border-subtle); border-radius: var(--radius-md); font-size: 0.88rem; outline: none; background: #fafbfc; color: var(--text-dark); width: 100%; }
        .form-group select { padding-right: 2.2rem; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; appearance: none; -webkit-appearance: none; cursor: pointer; }
        .btn-modal-submit { margin-top: 0.5rem; background: linear-gradient(180deg, var(--college-navy) 0%, #07152b 100%); color: #ffffff; border: none; border-radius: var(--radius-md); height: 46px; font-size: 0.95rem; font-weight: 700; cursor: pointer; box-shadow: 0 4px 12px rgba(9, 26, 54, 0.2); }

        /* Copy Link Box */
        .link-copy-box {
            display: flex; gap: 8px; align-items: center; background: #f8fafc; border: 1.5px solid var(--border-subtle);
            border-radius: var(--radius-md); padding: 8px 12px; margin: 1rem 0;
        }
        .link-copy-box input { border: none; background: transparent; height: auto; font-family: monospace; font-size: 0.82rem; color: var(--college-navy); flex: 1; outline: none; }
    </style>
</head>
<body>

    <!-- Toast Notification -->
    <?php if (!empty($feedback_message)): ?>
        <div class="toast-notification <?php echo $feedback_type; ?>" id="feedbackToast">
            <?php if ($feedback_type === 'success'): ?>
                <svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <?php else: ?>
                <svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
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

                <a href="#" class="nav-help-link" title="Contact Technical Support">
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
                <h1>Account & Access Management</h1>
                <p>Configure institutional user credentials, verify external registrations, and manage security.</p>
            </div>

            <div class="page-toolbar-actions">
                <!-- GENERATE REGISTRATION LINK BUTTON -->
                <button type="button" class="btn-outline" onclick="openModal('linkGenModal')" style="border-color: var(--college-blue); color: var(--college-blue);">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                    Generate Registration Link
                </button>
                
                <button type="button" class="btn-primary" onclick="openModal('createUserModal')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create User Account
                </button>
            </div>
        </div>

        <!-- Metric Counters -->
        <div class="stats-overview">
            <div class="stat-card card-total">
                <div class="stat-card-left">
                    <span class="stat-label">Active Accounts</span>
                    <h2 class="stat-number"><?php echo $totalUsers; ?></h2>
                    <small class="stat-subtext">Verified personnel</small>
                </div>
                <div class="stat-card-icon">
                    <svg viewBox="0 0 24 24" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </div>
            </div>

            <div class="stat-card card-admin">
                <div class="stat-card-left">
                    <span class="stat-label">Administrators</span>
                    <h2 class="stat-number"><?php echo $adminUsers; ?></h2>
                    <small class="stat-subtext">Console & Overseer access</small>
                </div>
                <div class="stat-card-icon">
                    <svg viewBox="0 0 24 24" stroke="currentColor"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                </div>
            </div>

            <div class="stat-card card-acad">
                <div class="stat-card-left">
                    <span class="stat-label">Academe Personnel</span>
                    <h2 class="stat-number"><?php echo $academicsUsers; ?></h2>
                    <small class="stat-subtext">Faculty & Supervisors</small>
                </div>
                <div class="stat-card-icon">
                    <svg viewBox="0 0 24 24" stroke="currentColor"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
                </div>
            </div>

            <div class="stat-card card-reg">
                <div class="stat-card-left">
                    <span class="stat-label">Registrars</span>
                    <h2 class="stat-number"><?php echo $registrarUsers; ?></h2>
                    <small class="stat-subtext">Records & Clearance officers</small>
                </div>
                <div class="stat-card-icon">
                    <svg viewBox="0 0 24 24" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                </div>
            </div>
        </div>

        <!-- Section Navigation: Active Roster vs Pending Requests Queue -->
        <div class="section-nav-tabs">
            <a href="account_management.php?tab=roster" class="tab-link <?php echo $active_tab !== 'requests' ? 'active' : ''; ?>">
                Verified Personnel Roster
            </a>
            <a href="account_management.php?tab=requests" class="tab-link <?php echo $active_tab === 'requests' ? 'active' : ''; ?>">
                Pending Registration Requests
                <?php if ($pendingRequests > 0): ?>
                    <span class="badge-request-count"><?php echo $pendingRequests; ?></span>
                <?php endif; ?>
            </a>
        </div>

        <?php if ($active_tab === 'requests'): ?>
            <!-- ==============================================
                 VIEW: PENDING REGISTRATION APPROVAL QUEUE
                 ============================================== -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Applicant Full Name</th>
                            <th>Requested Staff ID</th>
                            <th>Target Department</th>
                            <th>Submission Date</th>
                            <th style="text-align: right;">Administrator Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:3.5rem 1rem; color:var(--text-muted);">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="margin-bottom:0.75rem; color:#94a3b8;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                                    <p style="font-size:0.95rem; font-weight:700; color:var(--college-navy);">No Pending Registration Requests</p>
                                    <small>Share your registration link with faculty or staff to receive applications.</small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($requests as $req): ?>
                                <tr>
                                    <td>
                                        <div class="user-avatar-wrap">
                                            <div class="avatar-initial" style="background:#fffbeb; color:#92400e; border-color:#fde68a;">
                                                <?php echo strtoupper(substr($req['fullname'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($req['fullname']); ?></strong>
                                            </div>
                                        </div>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($req['username']); ?></code></td>
                                    <td>
                                        <span class="role-badge role-<?php echo $req['role']; ?>">
                                            <?php echo ucfirst($req['role']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y • h:i A', strtotime($req['created_at'])); ?></td>
                                    <td style="text-align: right;">
                                        <div style="display:inline-flex; align-items:center; gap:8px;">
                                            <!-- Approve Form -->
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="approve_request">
                                                <input type="hidden" name="user_id" value="<?php echo $req['id']; ?>">
                                                <button type="submit" class="btn-primary" style="background:#10b981; padding:0.4rem 0.85rem;" title="Approve and Activate Account">
                                                    ✓ Approve Account
                                                </button>
                                            </form>

                                            <!-- Reject Form -->
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Decline and remove this registration request?');">
                                                <input type="hidden" name="action" value="reject_request">
                                                <input type="hidden" name="user_id" value="<?php echo $req['id']; ?>">
                                                <button type="submit" class="btn-outline" style="color:#ef4444; border-color:#fecaca;" title="Decline Request">
                                                    ✕ Decline
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

        <?php else: ?>
            <!-- ==============================================
                 VIEW: ACTIVE & ARCHIVED PERSONNEL ROSTER
                 ============================================== -->
            <div class="table-filter-toolbar">
                <div class="search-input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" id="userSearchInput" placeholder="Search accounts by name or username ID..." oninput="filterUserTable()">
                </div>

                <div class="toolbar-group-right">
                    <select id="roleFilterSelect" class="filter-select-box" onchange="filterUserTable()">
                        <option value="all">All Departments / Roles</option>
                        <option value="admin">Administrators Only</option>
                        <option value="academics">Academe Only</option>
                        <option value="registrar">Registrars Only</option>
                    </select>

                    <select id="statusFilterSelect" class="filter-select-box" onchange="filterUserTable()">
                        <option value="all">All Accounts (Active & Archived)</option>
                        <option value="active">Active Only</option>
                        <option value="suspended">Suspended Only</option>
                        <option value="archived">Archived Only</option>
                    </select>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Personnel / Full Name</th>
                            <th>Username / Staff ID</th>
                            <th>Department Role</th>
                            <th>Access Status</th>
                            <th>Registration Date</th>
                            <th style="text-align: right;">Security Actions</th>
                        </tr>
                    </thead>
                    <tbody id="userTableBody">
                        <?php foreach ($users as $u): 
                            $initial = strtoupper(substr($u['fullname'], 0, 1));
                            $isArchived = (int)($u['is_archived'] ?? 0) === 1;
                            $displayStatus = $isArchived ? 'archived' : ($u['status'] ?? 'active');
                        ?>
                            <tr class="user-row <?php echo $isArchived ? 'row-archived' : ''; ?>" 
                                data-name="<?php echo strtolower(htmlspecialchars($u['fullname'])); ?>" 
                                data-username="<?php echo strtolower(htmlspecialchars($u['username'])); ?>" 
                                data-role="<?php echo strtolower($u['role']); ?>"
                                data-status="<?php echo $displayStatus; ?>">
                                
                                <td>
                                    <div class="user-avatar-wrap">
                                        <div class="avatar-initial"><?php echo $initial; ?></div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($u['fullname']); ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td><code><?php echo htmlspecialchars($u['username']); ?></code></td>
                                <td>
                                    <span class="role-badge role-<?php echo $u['role']; ?>">
                                        <?php echo ucfirst($u['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-pill status-<?php echo $displayStatus; ?>">
                                        <span class="dot"></span>
                                        <?php echo ucfirst($displayStatus); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                                <td style="text-align: right;">
                                    <div style="display:inline-flex; align-items:center; gap:6px;">
                                        <?php if (!$isArchived): ?>
                                            <button type="button" class="btn-outline" onclick="openResetModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>')">
                                                Reset Passcode
                                            </button>
                                            
                                            <button type="button" class="btn-outline" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES); ?>)">
                                                Edit
                                            </button>

                                            <?php if ($u['id'] != 1 && $u['id'] != $current_admin_id): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this user account? Historical data will be preserved.');">
                                                    <input type="hidden" name="action" value="archive_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <button type="submit" class="btn-outline" style="color:#d97706; border-color:#fde68a;" title="Archive Account">
                                                        Archive
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Restore and re-activate this user account?');">
                                                <input type="hidden" name="action" value="restore_user">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <button type="submit" class="btn-outline" style="color:#059669; border-color:#a7f3d0;" title="Restore Account">
                                                    Restore Account
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </main>

    <!-- MODAL: GENERATE REGISTRATION LINK -->
    <div class="modal-overlay" id="linkGenModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Shareable Registration Link</h3>
                <button type="button" class="modal-close" onclick="closeModal('linkGenModal')">&times;</button>
            </div>
            <p style="font-size:0.85rem; color:var(--text-muted); line-height:1.5;">
                Share this link with faculty or staff. Once they submit their registration form, their account will be held in your <strong>Pending Requests</strong> queue until you review and approve it.
            </p>

            <div class="link-copy-box">
                <input type="text" id="shareLinkInput" value="<?php echo htmlspecialchars($registration_link); ?>" readonly>
                <button type="button" class="btn-primary" id="btnCopyLink" onclick="copyShareableLink()">Copy Link</button>
            </div>

            <div style="background:#eef2f8; border:1px solid #d8e2f0; padding:12px; border-radius:8px; font-size:0.78rem; color:var(--college-navy);">
                <strong>🔒 Security Enforcement:</strong> External applicants can only request <em>Academe</em> or <em>Registrar</em> department access. All accounts require your manual authorization.
            </div>
        </div>
    </div>

    <!-- MODAL 1: CREATE USER (MANUAL BY ADMIN) -->
    <div class="modal-overlay" id="createUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New User Account</h3>
                <button type="button" class="modal-close" onclick="closeModal('createUserModal')">&times;</button>
            </div>
            <form class="modal-form" method="POST">
                <input type="hidden" name="action" value="create_user">

                <div class="form-group">
                    <label>Full Name / Title</label>
                    <input type="text" name="fullname" placeholder="e.g. Dean Arthur Cruz" required autocomplete="off">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Username / Staff ID</label>
                        <input type="text" name="username" placeholder="e.g. acruz_acad" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Department Role</label>
                        <select name="role" required>
                            <option value="academics">Academe (Faculty)</option>
                            <option value="registrar">Registrars (Records)</option>
                            <option value="admin">Administrative</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Initial Passcode</label>
                    <input type="password" name="passcode" placeholder="••••••••" required autocomplete="new-password">
                </div>

                <button type="submit" class="btn-modal-submit">Create & Authorize Account</button>
            </form>
        </div>
    </div>

    <!-- MODAL 2: EDIT USER -->
    <div class="modal-overlay" id="editUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 id="editModalHeaderTitle">Edit User Account</h3>
                    <p style="font-size:0.75rem; color:var(--text-muted);" id="editModalUsernameSubtitle"></p>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <form class="modal-form" method="POST">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="editUserId">

                <div class="form-group">
                    <label>Personnel Full Name</label>
                    <input type="text" name="fullname" id="editFullname" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Department Role</label>
                        <select name="role" id="editRole">
                            <option value="academics">Academe</option>
                            <option value="registrar">Registrars</option>
                            <option value="admin">Administrative</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Account Access Status</label>
                        <select name="status" id="editStatus">
                            <option value="active">Active (Access Allowed)</option>
                            <option value="suspended">Suspended (Access Blocked)</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn-modal-submit">Save Account Changes</button>
            </form>
        </div>
    </div>

    <!-- MODAL 3: RESET PASSCODE -->
    <div class="modal-overlay" id="resetPasscodeModal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3>Reset Passcode</h3>
                    <p style="font-size:0.75rem; color:var(--text-muted);" id="resetModalUsernameSubtitle"></p>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('resetPasscodeModal')">&times;</button>
            </div>
            <form class="modal-form" method="POST">
                <input type="hidden" name="action" value="reset_passcode">
                <input type="hidden" name="user_id" id="resetUserId">

                <div class="form-group">
                    <label>New Passcode</label>
                    <input type="password" name="new_passcode" placeholder="Enter new passcode..." required autocomplete="new-password">
                </div>

                <button type="submit" class="btn-modal-submit">Update Passcode</button>
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

        function copyShareableLink() {
            const input = document.getElementById('shareLinkInput');
            input.select();
            input.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(input.value).then(() => {
                const btn = document.getElementById('btnCopyLink');
                btn.innerText = "✓ Copied!";
                setTimeout(() => { btn.innerText = "Copy Link"; }, 2000);
            });
        }

        function openEditModal(user) {
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editFullname').value = user.fullname;
            document.getElementById('editRole').value = user.role;
            document.getElementById('editStatus').value = user.status || 'active';
            document.getElementById('editModalHeaderTitle').innerText = `Edit: ${user.fullname}`;
            document.getElementById('editModalUsernameSubtitle').innerText = `Username ID: ${user.username}`;
            openModal('editUserModal');
        }

        function openResetModal(userId, username) {
            document.getElementById('resetUserId').value = userId;
            document.getElementById('resetModalUsernameSubtitle').innerText = `Account: ${username}`;
            openModal('resetPasscodeModal');
        }

        function filterUserTable() {
            const searchVal = document.getElementById('userSearchInput').value.toLowerCase().trim();
            const roleVal   = document.getElementById('roleFilterSelect').value;
            const statusVal = document.getElementById('statusFilterSelect').value;

            document.querySelectorAll('.user-row').forEach(row => {
                const name     = row.getAttribute('data-name');
                const username = row.getAttribute('data-username');
                const role     = row.getAttribute('data-role');
                const status   = row.getAttribute('data-status');

                let match = (searchVal === '' || name.includes(searchVal) || username.includes(searchVal));
                if (match && roleVal !== 'all') match = (role === roleVal);
                if (match && statusVal !== 'all') match = (status === statusVal);

                row.style.display = match ? '' : 'none';
            });
        }
    </script>
</body>
</html>