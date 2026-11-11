<?php
session_start();
require_once 'db.php'; // Runs auto-setup

// --- READ SINGLE-USE FLASH SESSIONS (WIPED IMMEDIATELY AFTER LOAD) ---
$error_message = $_SESSION['flash_error'] ?? '';
$active_role   = $_SESSION['flash_role'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_role']);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $role     = strtolower(trim($_POST['role'] ?? ''));
    $username = trim($_POST['username'] ?? '');
    $passcode = trim($_POST['passcode'] ?? '');

    if (empty($username) || empty($passcode) || empty($role)) {
        $_SESSION['flash_error'] = "Please fill in all fields.";
        $_SESSION['flash_role']  = $role;
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(:username) AND role = :role LIMIT 1");
            $stmt->execute([
                'username' => $username,
                'role'     => $role
            ]);
            $user = $stmt->fetch();

            if (!$user) {
                $_SESSION['flash_error'] = "No account found for '<strong>" . htmlspecialchars($username) . "</strong>' under the " . ucfirst($role) . " portal.";
                $_SESSION['flash_role']  = $role;
            } elseif (!empty($user['status']) && $user['status'] === 'pending_approval') {
                $_SESSION['flash_error'] = "Your registration is currently <strong>pending administrator approval</strong>. Please wait for verification.";
                $_SESSION['flash_role']  = $role;
            } elseif (!empty($user['is_archived']) && $user['is_archived'] == 1) {
                $_SESSION['flash_error'] = "This account has been <strong>archived</strong>. Please contact the administrator.";
                $_SESSION['flash_role']  = $role;
            } elseif (!password_verify($passcode, $user['password'])) {
                $_SESSION['flash_error'] = "Incorrect passcode. Please try again.";
                $_SESSION['flash_role']  = $role;
            } else {
                $_SESSION['authenticated'] = true;
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['username']      = $user['username'];
                $_SESSION['fullname']      = $user['fullname'];
                $_SESSION['role']          = $user['role'];

                if ($user['role'] === 'admin') {
                    header("Location: Admin/admin.php");
                    exit;
                } elseif ($user['role'] === 'academics') {
                    header("Location: Academics/academics.php");
                    exit;
                } elseif ($user['role'] === 'registrar') {
                    header("Location: Registrar/registrar.php");
                    exit;
                }
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "System Error: " . $e->getMessage();
            $_SESSION['flash_role']  = $role;
        }
    }

    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BSBT College | Portal Authentication Gate</title>
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
            --card-surface: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-subtle: #e2e8f0;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            --ease-fluid: cubic-bezier(0.16, 1, 0.3, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        html {
            height: 100%;
            height: 100dvh;
            background-color: #07152b; /* Dark fallback */
        }

        /* Body is transparent so the video is never hidden */
        body {
            height: 100%;
            height: 100dvh;
            overflow: hidden;
            background-color: transparent !important;
            color: var(--text-dark);
            display: flex;
            flex-direction: column;
            position: relative;
        }

        /* ==================================================
           FULLSCREEN VIDEO (CORRECTED LAYERING)
           ================================================== */
        .bg-video-wrapper {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            z-index: 0; /* Above body background */
            pointer-events: none;
        }

        .bg-video-wrapper video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        /* Cinematic Dark Navy Overlay */
        .video-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: linear-gradient(135deg, rgba(7, 21, 43, 0.82) 0%, rgba(9, 26, 54, 0.72) 100%);
            z-index: 1; /* Above video */
            pointer-events: none;
        }

        /* All Content is stacked above the video and overlay */
        header, main, .modal-overlay, .toast-notification {
            position: relative;
            z-index: 10;
        }

        /* ==================================================
           FROSTED GLASS NAVBAR
           ================================================== */
        header {
            position: sticky;
            top: 0;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            padding: 0.75rem 2.5rem;
            flex-shrink: 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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
            white-space: nowrap;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

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
            white-space: nowrap;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            background-color: #22c55e;
            border-radius: 50%;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
        }

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
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .nav-help-link:hover {
            color: var(--college-navy);
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        /* ==================================================
           MAIN CONTENT
           ================================================== */
        main {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
            min-height: 0;
        }

        .portal-view {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .portal-view.hidden {
            display: none !important;
        }

        @keyframes loginCardPopIn {
            0% { opacity: 0; transform: translateY(22px) scale(0.95); filter: blur(5px); }
            100% { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); }
        }

        @keyframes departmentViewFadeIn {
            0% { opacity: 0; transform: translateY(-14px) scale(0.98); filter: blur(3px); }
            100% { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); }
        }

        .animate-login { animation: loginCardPopIn 0.36s var(--ease-fluid) forwards; }
        .animate-departments { animation: departmentViewFadeIn 0.3s var(--ease-fluid) forwards; }

        .section-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .academic-pill {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #facc15;
            background: rgba(234, 179, 8, 0.15);
            border: 1px solid rgba(234, 179, 8, 0.3);
            padding: 0.3rem 0.85rem;
            border-radius: 20px;
            margin-bottom: 0.5rem;
        }

        .section-header h2 {
            font-size: 2rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.4px;
            margin-bottom: 0.35rem;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
        }

        .section-header p {
            font-size: 0.92rem;
            color: #cbd5e1;
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
        }

        #cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            width: 100%;
            max-width: 1020px;
        }

        .role-card {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: var(--radius-lg);
            padding: 2.2rem 1.75rem;
            text-align: center;
            cursor: pointer;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
            transition: transform 0.25s var(--ease-fluid), box-shadow 0.25s var(--ease-fluid), border-color 0.25s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .role-card::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; height: 4px;
            background: var(--college-navy);
            opacity: 0.25;
            transition: opacity 0.2s, background 0.2s;
        }

        .icon-circle {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
            transition: transform 0.25s var(--ease-fluid), background-color 0.25s ease;
        }

        .icon-circle svg { width: 30px; height: 30px; stroke: var(--college-navy); fill: none; stroke-width: 1.8; }

        .role-card h1 { font-size: 1.25rem; font-weight: 800; color: var(--college-navy); margin-bottom: 0.45rem; }
        .role-card p { font-size: 0.82rem; color: var(--text-muted); line-height: 1.45; margin-bottom: 1.5rem; }

        .card-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--college-navy);
            transition: gap 0.2s ease, color 0.2s ease;
        }

        .card-action svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2.2; fill: none; }

        .role-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
            border-color: #ffffff;
        }

        .role-card:hover::before { background: var(--college-gold); opacity: 1; }
        .role-card:hover .icon-circle { background-color: var(--college-navy); transform: scale(1.06); }
        .role-card:hover .icon-circle svg { stroke: #ffffff; }
        .role-card:hover .card-action { color: var(--college-gold); gap: 10px; }

        /* Login Card */
        #login-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(12px);
            width: 100%;
            max-width: 440px;
            padding: 1.75rem 2.25rem 1.6rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-top: 4.5px solid var(--college-navy);
            display: flex;
            flex-direction: column;
            will-change: transform, opacity;
        }

        .card-top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            border: 1px solid var(--border-subtle);
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .back-btn:hover { color: var(--college-navy); background: #eef2f6; border-color: #cbd5e1; }

        .card-brand-header { text-align: center; margin-bottom: 1.3rem; }
        .login-crest { height: 70px; width: auto; object-fit: contain; margin: 0 auto 0.75rem; display: block; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.06)); }
        #login-title { font-size: 1.35rem; font-weight: 800; color: var(--college-navy); letter-spacing: -0.3px; margin-bottom: 0.25rem; }
        #login-desc { font-size: 0.82rem; color: var(--text-muted); line-height: 1.4; }

        .card-alert-banner {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-left: 4px solid #dc2626;
            padding: 0.75rem 0.85rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.15rem;
            text-align: left;
            animation: alertSlideDown 0.25s ease-out forwards;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        @keyframes alertSlideDown {
            0% { opacity: 0; transform: translateY(-6px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        .card-alert-banner .alert-icon { width: 17px; height: 17px; stroke: #dc2626; flex-shrink: 0; margin-top: 1px; }
        .card-alert-banner .alert-text { font-size: 0.8rem; color: #991b1b; line-height: 1.35; flex: 1; }
        .card-alert-banner .alert-close-btn { background: none; border: none; font-size: 1.1rem; color: #f87171; cursor: pointer; line-height: 1; padding: 0 2px; }

        #login-card form { display: flex; flex-direction: column; gap: 1.05rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.35rem; text-align: left; }
        label { font-size: 0.75rem; font-weight: 600; color: var(--text-dark); text-transform: uppercase; letter-spacing: 0.4px; }

        .input-container { position: relative; display: flex; align-items: center; }
        .input-container svg { position: absolute; left: 12px; width: 16px; height: 16px; stroke: var(--text-muted); fill: none; transition: stroke 0.2s; pointer-events: none; }

        input[type="text"], input[type="password"] {
            width: 100%; height: 44px; padding: 0 0.85rem 0 2.45rem; border: 1.5px solid var(--border-subtle);
            border-radius: var(--radius-md); font-size: 0.92rem; color: var(--text-dark); background-color: #fafbfc;
            outline: none; transition: all 0.2s ease;
        }
        input:focus { background-color: #ffffff; border-color: var(--college-navy); box-shadow: 0 0 0 3px rgba(9, 26, 54, 0.08); }
        input:focus + svg, .input-container:focus-within svg { stroke: var(--college-navy); }

        button.btn-login {
            margin-top: 0.3rem; background: linear-gradient(180deg, var(--college-navy) 0%, #07152b 100%);
            color: #ffffff; border: none; border-radius: var(--radius-md); height: 46px; font-size: 0.95rem; font-weight: 600;
            cursor: pointer; box-shadow: 0 4px 10px rgba(13, 35, 70, 0.15); transition: transform 0.05s ease, box-shadow 0.2s ease;
        }
        button.btn-login:hover { box-shadow: 0 6px 14px rgba(13, 35, 70, 0.25); }
        button.btn-login:active { transform: scale(0.99); }

        .card-footer { margin-top: 1.2rem; padding-top: 0.85rem; border-top: 1px solid #f1f5f9; text-align: center; font-size: 0.76rem; color: var(--text-muted); }
        .card-footer a { color: var(--college-navy); text-decoration: none; font-weight: 600; cursor: pointer; }
        .card-footer a:hover { text-decoration: underline; }

        /* Modal */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(9, 26, 54, 0.6); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 100; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 480px; padding: 2rem; box-shadow: 0 25px 50px -12px rgba(9, 26, 54, 0.25); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; padding-bottom: 0.85rem; border-bottom: 1px solid var(--border-subtle); }
        .modal-header h3 { font-size: 1.2rem; font-weight: 800; color: var(--college-navy); }
        .modal-close { background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 8px; font-size: 1.25rem; cursor: pointer; color: var(--text-muted); display: flex; align-items: center; justify-content: center; }

        .help-item { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .help-item:last-child { border-bottom: none; }
        .help-icon { width: 36px; height: 36px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: var(--college-navy); flex-shrink: 0; }
        .help-info strong { display: block; font-size: 0.85rem; color: var(--college-navy); margin-bottom: 2px; }
        .help-info p { font-size: 0.8rem; color: var(--text-muted); line-height: 1.4; }

        @media (max-width: 768px) {
            header { padding: 0.65rem 1.25rem; }
            .terminal-pill { display: none; }
            .sys-status span:last-child { display: none; }
            .sys-status { padding: 0.35rem 0.55rem; }
            #cards-grid { grid-template-columns: 1fr; max-width: 380px; }
            main { padding: 2rem 1.25rem; }
        }
    </style>
</head>
<body>

    <!-- 1. FULLSCREEN BACKGROUND VIDEO WITH CORRECT ROOT PATH -->
    <div class="bg-video-wrapper">
        <video autoplay muted loop playsinline id="bgVideo" preload="auto">
            <source src="videos/index_bg.mp4" type="video/mp4">
        </video>
    </div>

    <!-- 2. DARK NAVY OVERLAY -->
    <div class="video-overlay"></div>

    <!-- 3. TOP NAVBAR -->
    <header>
        <nav>
            <div class="nav-left">
                <a href="index.php" id="logo">
                    <img src="images/logo.png" alt="BSBT Logo">
                    <div class="brand-text">
                        <h2>BSBT College</h2>
                        <span>Tracking Portal</span>
                    </div>
                </a>
                <span class="terminal-pill">Official Academic Gate</span>
            </div>

            <div class="nav-actions">
                <div class="sys-status" title="All campus systems operational">
                    <span class="status-dot"></span>
                    <span>Portal Online</span>
                </div>

                <button type="button" class="nav-help-link" onclick="openModal('helpdeskModal')" title="Contact Technical Support">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="10" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    <span>Helpdesk</span>
                </button>
            </div>
        </nav>
    </header>

    <main>
        
        <!-- VIEW 1: DEPARTMENT SELECTION -->
        <div id="department-view" class="portal-view <?php echo !empty($active_role) ? 'hidden' : ''; ?>">
            <div class="section-header">
                <span class="academic-pill">Authorized Personnel Only</span>
                <h2>Select Your Department</h2>
                <p>Choose your designated terminal to access records</p>
            </div>

            <div id="cards-grid">
                <!-- Admin Card -->
                <div class="role-card" onclick="openLogin('admin')">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        </svg>
                    </div>
                    <h1>Admin</h1>
                    <p>System configuration, security logs, and institutional oversight.</p>
                    <div class="card-action">
                        Log In 
                        <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </div>
                </div>

                <!-- Academics Card -->
                <div class="role-card" onclick="openLogin('academics')">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                            <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                        </svg>
                    </div>
                    <h1>Academics</h1>
                    <p>Curriculum verification, instructional progress, and faculty tracking.</p>
                    <div class="card-action">
                        Log In 
                        <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </div>
                </div>

                <!-- Registrar Card -->
                <div class="role-card" onclick="openLogin('registrar')">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                    </div>
                    <h1>Registrar</h1>
                    <p>Official clearance records, credential issuance, and transcripts.</p>
                    <div class="card-action">
                        Log In 
                        <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- VIEW 2: LOGIN CARD -->
        <div id="login-view" class="portal-view <?php echo empty($active_role) ? 'hidden' : ''; ?>">
            <div id="login-card">
                
                <div class="card-top-bar">
                    <button type="button" class="back-btn" onclick="showDepartments()">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        Back
                    </button>
                    <span id="login-terminal-tag" class="terminal-pill">Terminal</span>
                </div>

                <div class="card-brand-header">
                    <img src="images/logo.png" alt="BSBT Seal" class="login-crest">
                    <h2 id="login-title">Portal Login</h2>
                    <p id="login-desc">Sign in with authorized institutional credentials</p>
                </div>

                <!-- Card-Attached Alert Banner -->
                <?php if (!empty($error_message)): ?>
                    <div class="card-alert-banner" id="cardAlertBanner">
                        <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <div class="alert-text">
                            <?php echo $error_message; ?>
                        </div>
                        <button type="button" class="alert-close-btn" onclick="dismissCardAlert()">&times;</button>
                    </div>
                <?php endif; ?>

                <form action="index.php" method="POST">
                    <input type="hidden" id="selected-role" name="role" value="<?php echo htmlspecialchars($active_role); ?>">

                    <div class="form-group">
                        <label for="username">Username / ID</label>
                        <div class="input-container">
                            <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <input type="text" id="username" name="username" placeholder="Enter your ID" required autocomplete="username" oninput="dismissCardAlert()">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="passcode">Passcode</label>
                        <div class="input-container">
                            <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                            <input type="password" id="passcode" name="passcode" placeholder="••••••••" required autocomplete="current-password" oninput="dismissCardAlert()">
                        </div>
                    </div>

                    <button type="submit" class="btn-login">Sign In</button>
                </form>

                <div class="card-footer">
                    Having trouble? <a onclick="openModal('helpdeskModal')">Contact College Helpdesk</a>
                </div>
            </div>
        </div>

    </main>

    <!-- Helpdesk Support Modal -->
    <div class="modal-overlay" id="helpdeskModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Campus IT & Registrar Support</h3>
                <button type="button" class="modal-close" onclick="closeModal('helpdeskModal')">&times;</button>
            </div>
            
            <div class="help-item">
                <div class="help-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                </div>
                <div class="help-info">
                    <strong>Email Support</strong>
                    <p>it-helpdesk@bsbt.edu.ph<br>registrar@bsbt.edu.ph</p>
                </div>
            </div>

            <div class="help-item">
                <div class="help-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                </div>
                <div class="help-info">
                    <strong>Direct Hotline</strong>
                    <p>Local 104 (MIS Office) • Local 102 (Registrar)</p>
                </div>
            </div>

            <div class="help-item">
                <div class="help-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div class="help-info">
                    <strong>Office Operating Hours</strong>
                    <p>Monday – Friday: 8:00 AM – 5:00 PM<br>Ground Floor, Administration Building</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Force video play on page load (Bypasses browser autoplay restrictions)
        window.addEventListener('DOMContentLoaded', () => {
            const video = document.getElementById('bgVideo');
            if (video) {
                video.muted = true;
                const playPromise = video.play();
                if (playPromise !== undefined) {
                    playPromise.catch(error => {
                        console.log("Autoplay was prevented, retrying muted:", error);
                        video.muted = true;
                        video.play();
                    });
                }
            }
        });

        const cardAlert = document.getElementById('cardAlertBanner');
        if (cardAlert) {
            setTimeout(() => { dismissCardAlert(); }, 4000);
        }

        function dismissCardAlert() {
            const banner = document.getElementById('cardAlertBanner');
            if (banner) {
                banner.style.opacity = '0';
                banner.style.transform = 'translateY(-6px)';
                setTimeout(() => banner.remove(), 250);
            }
        }

        const departments = {
            admin: {
                tag: "Admin Terminal",
                title: "Admin Portal",
                desc: "Sign in with institutional administrator credentials"
            },
            academics: {
                tag: "Academics Terminal",
                title: "Academics Portal",
                desc: "Sign in with academic supervisor or faculty credentials"
            },
            registrar: {
                tag: "Registrar Terminal",
                title: "Registrar Portal",
                desc: "Sign in with authorized registrar credentials"
            }
        };

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function openLogin(role) {
            const data = departments[role];
            if (!data) return;

            document.getElementById('login-terminal-tag').innerText = data.tag;
            document.getElementById('login-title').innerText = data.title;
            document.getElementById('login-desc').innerText = data.desc;
            document.getElementById('selected-role').value = role;

            const deptView = document.getElementById('department-view');
            const loginView = document.getElementById('login-view');
            const loginCard = document.getElementById('login-card');

            deptView.classList.add('hidden');
            loginView.classList.remove('hidden');

            loginCard.classList.remove('animate-login');
            void loginCard.offsetWidth;
            loginCard.classList.add('animate-login');

            setTimeout(() => {
                document.getElementById('username').focus();
            }, 150);
        }

        function showDepartments() {
            document.getElementById('username').value = '';
            document.getElementById('passcode').value = '';

            const deptView = document.getElementById('department-view');
            const loginView = document.getElementById('login-view');

            loginView.classList.add('hidden');
            deptView.classList.remove('hidden');

            deptView.classList.remove('animate-departments');
            void deptView.offsetWidth;
            deptView.classList.add('animate-departments');
        }

        <?php if (!empty($active_role)): ?>
            openLogin('<?php echo $active_role; ?>');
        <?php endif; ?>
    </script>

</body>
</html>