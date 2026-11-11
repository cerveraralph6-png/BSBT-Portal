<?php
session_start();

// --- SECURITY GUARD: Ensure only authenticated admins can access ---
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$admin_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Administrator';
$current_month_year = date('F Y'); // e.g. "November 2026"
$current_day = (int)date('j');      // e.g. 8
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BSBT College | Administrator Dashboard</title>
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
            --radius-lg: 16px;
            --shadow-card: 0 10px 25px -5px rgba(9, 26, 54, 0.05), 0 4px 6px -2px rgba(9, 26, 54, 0.02);
            --shadow-hover: 0 18px 30px -5px rgba(9, 26, 54, 0.1), 0 8px 10px -4px rgba(9, 26, 54, 0.03);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        html, body {
            height: 100%;
            height: 100dvh;
            background-color: var(--bg-canvas);
            color: var(--text-dark);
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* Watermark */
        .watermark-bg {
            position: fixed;
            bottom: -10%;
            left: -5%;
            width: 50vmin;
            height: 50vmin;
            background-image: url('../images/logo.png');
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.035;
            mix-blend-mode: multiply;
            pointer-events: none;
            z-index: 0;
        }

        /* Header Navigation */
        /* ==================================================
           CONSISTENT GLOBAL NAVBAR (MATCHES INDEX.PHP)
           ================================================== */
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

        /* Main 2-Column Layout */
        main {
            position: relative;
            z-index: 2;
            flex: 1;
            padding: 1.5rem 2.5rem;
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 1.5rem;
            align-items: stretch;
            min-height: 0;
        }

        .dash-left {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .dash-banner {
            margin-bottom: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-subtle);
        }

        .dash-banner h1 {
            font-size: 1.55rem;
            font-weight: 800;
            color: var(--college-navy);
            letter-spacing: -0.4px;
        }

        .dash-banner p {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.15rem;
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
        }

        .status-dot {
            width: 7px;
            height: 7px;
            background-color: #22c55e;
            border-radius: 50%;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
        }

        .cards-grid-2x2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
            flex: 1;
        }

        .dash-card {
            background: var(--card-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 1.4rem 1.35rem;
            text-decoration: none;
            color: inherit;
            box-shadow: var(--shadow-card);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }

        .dash-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--college-navy);
            opacity: 0.12;
            transition: all 0.25s ease;
        }

        .card-header-icon {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.85rem;
        }

        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s ease;
        }

        .card-icon svg {
            width: 24px;
            height: 24px;
            stroke: var(--college-navy);
            stroke-width: 1.8;
            fill: none;
            transition: stroke 0.2s ease;
        }

        .card-badge {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--text-muted);
            background: #f8fafc;
            border: 1px solid var(--border-subtle);
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .card-content h3 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--college-navy);
            margin-bottom: 0.35rem;
            letter-spacing: -0.2px;
        }

        .card-content p {
            font-size: 0.8rem;
            color: var(--text-muted);
            line-height: 1.45;
            margin-bottom: 1.1rem;
        }

        .card-footer-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 0.75rem;
            border-top: 1px solid #f1f5f9;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--college-navy);
            transition: color 0.2s ease;
        }

        .card-footer-link svg {
            width: 14px;
            height: 14px;
            stroke-width: 2.2;
            stroke: currentColor;
            fill: none;
            transition: transform 0.2s ease;
        }

        .dash-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
            border-color: #cbd5e1;
        }

        .dash-card:hover::before {
            background: var(--college-gold);
            opacity: 1;
        }

        .dash-card:hover .card-icon {
            background-color: var(--college-navy);
            transform: scale(1.04);
        }

        .dash-card:hover .card-icon svg {
            stroke: #ffffff;
        }

        .dash-card:hover .card-footer-link {
            color: var(--college-gold);
        }

        .dash-card:hover .card-footer-link svg {
            transform: translateX(4px);
        }

        /* Right Column: Mini Calendar */
        .dash-right {
            background: var(--card-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.85rem;
        }

        .calendar-header h3 {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--college-navy);
        }

        .cal-nav-btn {
            background: #f1f5f9;
            border: 1px solid var(--border-subtle);
            width: 26px;
            height: 26px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--college-navy);
            transition: background 0.2s;
        }

        .cal-nav-btn:hover {
            background: #e2e8f0;
        }

        .cal-nav-btn svg {
            width: 12px;
            height: 12px;
            stroke-width: 2.2;
            stroke: currentColor;
            fill: none;
        }

        .calendar-days-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 0.35rem;
            text-transform: uppercase;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            text-align: center;
        }

        .cal-date {
            width: 32px;
            height: 30px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            color: var(--text-dark);
            position: relative;
            transition: background 0.15s ease;
        }

        .cal-date:hover {
            background: #f1f5f9;
        }

        .cal-date.dimmed {
            color: #cbd5e1;
            cursor: default;
        }

        .cal-date.active {
            background: var(--college-navy);
            color: #ffffff;
            font-weight: 700;
        }

        .cal-date.has-event::after {
            content: "";
            width: 4px;
            height: 4px;
            background: var(--college-gold);
            border-radius: 50%;
            position: absolute;
            bottom: 2px;
        }

        .cal-date.active.has-event::after {
            background: #ffffff;
        }

        .upcoming-section {
            margin-top: 0.85rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-subtle);
        }

        .upcoming-section h4 {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--college-navy);
            margin-bottom: 0.65rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .upcoming-badge {
            background: #eef2f8;
            color: var(--college-blue);
            font-size: 0.65rem;
            padding: 0.15rem 0.5rem;
            border-radius: 10px;
        }

        .event-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .event-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.45rem 0.6rem;
            background: #f8fafc;
            border: 1px solid var(--border-subtle);
            border-radius: 6px;
            transition: background 0.2s;
        }

        .event-item:hover {
            background: #f1f5f9;
        }

        .event-date-badge {
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            padding: 0.15rem 0.35rem;
            border-radius: 4px;
            text-align: center;
            min-width: 36px;
        }

        .event-date-badge .day {
            font-size: 0.78rem;
            font-weight: 800;
            color: var(--college-navy);
            line-height: 1;
        }

        .event-date-badge .month {
            font-size: 0.58rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--college-gold);
        }

        .event-details {
            flex: 1;
            overflow: hidden;
        }

        .event-details h5 {
            font-size: 0.76rem;
            font-weight: 700;
            color: var(--text-dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .event-details span {
            font-size: 0.68rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        @media (max-width: 1050px) {
            main {
                grid-template-columns: 1fr;
            }
            .dash-right {
                max-width: 400px;
                margin: 0 auto;
                width: 100%;
            }
            html, body {
                height: auto;
                overflow-y: auto;
            }
        }

        @media (max-width: 650px) {
            header {
                padding: 0.75rem 1.25rem;
            }
            main {
                padding: 1.25rem;
            }
            .cards-grid-2x2 {
                grid-template-columns: 1fr;
            }
            .dash-banner {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }
    </style>
</head>
<body>

    <div class="watermark-bg"></div>

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
        
        <!-- LEFT: Cards -->
        <section class="dash-left">
            <div class="dash-banner">
                <div>
                    <h1>Administrator Overview</h1>
                    <!-- Dynamic Admin Name -->
                    <p>Welcome back, <strong><?php echo htmlspecialchars($admin_name); ?></strong>. Select a management terminal.</p>
                </div>
                <div class="sys-status">
                    <span class="status-dot"></span>
                    <span>All Systems Operational</span>
                </div>
            </div>

            <!-- 2x2 Grid -->
            <div class="cards-grid-2x2">
                
                <!-- 1. General Tracker -->
                <a href="../General_Tracker/General_tracker.php" class="dash-card">
                    <div>
                        <div class="card-header-icon">
                            <div class="card-icon">
                                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>
                                </svg>
                            </div>
                            <span class="card-badge">System-Wide</span>
                        </div>
                        <div class="card-content">
                            <h3>General Tracker</h3>
                            <p>Centralized monitoring of all active documents, clearance requests, and institutional logs.</p>
                        </div>
                    </div>
                    <div class="card-footer-link">
                        <span>Open Tracker</span>
                        <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </div>
                </a>

                <!-- 2. Files Transferring -->
                <!-- Points to File_Transfer.php -->
                <a href="../File_Transfer/File_Transfer.php" class="dash-card">
                    <div>
                        <div class="card-header-icon">
                            <div class="card-icon">
                                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="17 1 21 5 17 9"></polyline>
                                    <path d="M3 11V9a4 4 0 0 1 4-4h14"></path>
                                    <polyline points="7 23 3 19 7 15"></polyline>
                                    <path d="M21 13v2a4 4 0 0 1-4 4H3"></path>
                                </svg>
                            </div>
                            <span class="card-badge">Transit</span>
                        </div>
                        <div class="card-content">
                            <h3>Files Transferring</h3>
                            <p>Routing documents between Academics, Registrar, and Administration departments.</p>
                        </div>
                    </div>
                    <div class="card-footer-link">
                        <span>View Transfers</span>
                        <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </div>
                </a>

                <!-- 3. Account Management -->
                <!-- 3. Account Management -->
                <a href="../Account_Management/account_management.php" class="dash-card">
                    <div>
                        <div class="card-header-icon">
                            <div class="card-icon">
                                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="8.5" cy="7" r="4"></circle>
                                    <line x1="20" y1="8" x2="20" y2="14"></line>
                                    <line x1="23" y1="11" x2="17" y2="11"></line>
                                </svg>
                            </div>
                            <span class="card-badge">Security</span>
                        </div>
                        <div class="card-content">
                            <h3>Account Management</h3>
                            <p>Configure staff credentials, user role assignments, passwords, and security authorizations.</p>
                        </div>
                    </div>
                    <div class="card-footer-link">
                        <span>Manage Accounts</span>
                        <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </div>
                </a>

                <!-- 4. Employee Tracker -->
                <a href="#" class="dash-card">
                    <div>
                        <div class="card-header-icon">
                            <div class="card-icon">
                                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                            </div>
                            <span class="card-badge">Personnel</span>
                        </div>
                        <div class="card-content">
                            <h3>Employee Tracker</h3>
                            <p>Real-time oversight of personnel duty logs, document action sign-offs, and daily presence.</p>
                        </div>
                    </div>
                    <div class="card-footer-link">
                        <span>Track Personnel</span>
                        <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </div>
                </a>

            </div>
        </section>

        <!-- RIGHT: Compact Dynamic Calendar -->
        <aside class="dash-right">
            <div>
                <div class="calendar-header">
                    <!-- Dynamic Month & Year -->
                    <h3><?php echo $current_month_year; ?></h3>
                    <div style="display: flex; gap: 4px;">
                        <button type="button" class="cal-nav-btn" title="Previous Month">
                            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        </button>
                        <button type="button" class="cal-nav-btn" title="Next Month">
                            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </button>
                    </div>
                </div>

                <div class="calendar-days-header">
                    <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                </div>

                <div class="calendar-grid">
                    <span class="cal-date dimmed">28</span>
                    <span class="cal-date dimmed">29</span>
                    <span class="cal-date dimmed">30</span>
                    <span class="cal-date">1</span>
                    <span class="cal-date">2</span>
                    <span class="cal-date">3</span>
                    <span class="cal-date">4</span>
                    
                    <span class="cal-date">5</span>
                    <span class="cal-date">6</span>
                    <span class="cal-date">7</span>
                    <span class="cal-date <?php echo ($current_day === 8) ? 'active' : ''; ?>">8</span>
                    <span class="cal-date">9</span>
                    <span class="cal-date has-event">10</span>
                    <span class="cal-date">11</span>

                    <span class="cal-date">12</span>
                    <span class="cal-date">13</span>
                    <span class="cal-date">14</span>
                    <span class="cal-date <?php echo ($current_day === 15) ? 'active' : ''; ?>">15</span>
                    <span class="cal-date">16</span>
                    <span class="cal-date">17</span>
                    <span class="cal-date">18</span>

                    <span class="cal-date">19</span>
                    <span class="cal-date">20</span>
                    <span class="cal-date">21</span>
                    <span class="cal-date">22</span>
                    <span class="cal-date">23</span>
                    <span class="cal-date has-event">24</span>
                    <span class="cal-date">25</span>

                    <span class="cal-date">26</span>
                    <span class="cal-date">27</span>
                    <span class="cal-date has-event">28</span>
                    <span class="cal-date">29</span>
                    <span class="cal-date">30</span>
                    <span class="cal-date">31</span>
                    <span class="cal-date dimmed">1</span>
                </div>
            </div>

            <!-- Deadlines -->
            <div class="upcoming-section">
                <h4>
                    <span>College Deadlines</span>
                    <span class="upcoming-badge">3 Upcoming</span>
                </h4>

                <div class="event-list">
                    <div class="event-item">
                        <div class="event-date-badge">
                            <div class="day">24</div>
                            <div class="month">Oct</div>
                        </div>
                        <div class="event-details">
                            <h5>Registrar Clearance</h5>
                            <span>Semester Submissions</span>
                        </div>
                    </div>

                    <div class="event-item">
                        <div class="event-date-badge">
                            <div class="day">28</div>
                            <div class="month">Oct</div>
                        </div>
                        <div class="event-details">
                            <h5>Academics Audit</h5>
                            <span>Quarterly Verification</span>
                        </div>
                    </div>

                    <div class="event-item">
                        <div class="event-date-badge">
                            <div class="day">02</div>
                            <div class="month">Nov</div>
                        </div>
                        <div class="event-details">
                            <h5>System Backup</h5>
                            <span>Server Maintenance</span>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

    </main>

</body>
</html>