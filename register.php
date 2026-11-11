<?php
session_start();
require_once 'db.php';

$success_state = false;
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role     = strtolower(trim($_POST['role'] ?? 'academics'));
    $passcode = trim($_POST['passcode'] ?? '');
    $confirm  = trim($_POST['confirm_passcode'] ?? '');

    // Security: Only allow Academe or Registrar for public registration
    if (!in_array($role, ['academics', 'registrar'])) {
        $role = 'academics';
    }

    if (empty($fullname) || empty($username) || empty($passcode)) {
        $error_message = "Please complete all required fields.";
    } elseif ($passcode !== $confirm) {
        $error_message = "Passcodes do not match. Please verify.";
    } elseif (strlen($passcode) < 6) {
        $error_message = "Passcode must be at least 6 characters long.";
    } else {
        // Check if username already exists
        $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $check->execute([$username]);
        if ($check->fetch()) {
            $error_message = "The Staff ID / Username '<strong>" . htmlspecialchars($username) . "</strong>' is already registered.";
        } else {
            $hashed = password_hash($passcode, PASSWORD_DEFAULT);
            // Insert with status = 'pending_approval'
            $stmt = $pdo->prepare("INSERT INTO users (fullname, username, password, role, status, is_archived) VALUES (?, ?, ?, ?, 'pending_approval', 0)");
            $stmt->execute([$fullname, $username, $hashed, $role]);
            $success_state = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BSBT College | Staff Portal Registration</title>
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
            --shadow: 0 20px 40px -15px rgba(9, 26, 54, 0.08), 0 0 1px 1px rgba(9, 26, 54, 0.05);
            --ease-fluid: cubic-bezier(0.16, 1, 0.3, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', system-ui, sans-serif; }

        body {
            background-color: var(--bg-canvas);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .watermark-bg {
            position: fixed;
            top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: min(65vmin, 550px); height: min(65vmin, 550px);
            background-image: url('images/logo.png'); background-repeat: no-repeat;
            background-position: center; background-size: contain;
            opacity: 0.04; mix-blend-mode: multiply; pointer-events: none; z-index: 0;
        }

        header {
            background: #ffffff; border-bottom: 1px solid var(--border-subtle);
            padding: 0.75rem 2.5rem; position: sticky; top: 0; z-index: 20;
        }

        nav { max-width: 1440px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; }
        .nav-left { display: flex; align-items: center; gap: 16px; }
        #logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        #logo img { height: 36px; width: auto; object-fit: contain; }
        .brand-text h2 { font-size: 0.95rem; font-weight: 800; color: var(--college-navy); line-height: 1.1; }
        .brand-text span { font-size: 0.7rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .terminal-pill { font-size: 0.72rem; font-weight: 700; background: rgba(197, 155, 39, 0.12); color: #99720d; border: 1px solid rgba(197, 155, 39, 0.3); padding: 0.25rem 0.75rem; border-radius: 20px; text-transform: uppercase; }

        .btn-login-nav {
            font-size: 0.82rem; font-weight: 600; color: var(--college-navy); text-decoration: none;
            padding: 0.4rem 0.9rem; border: 1.5px solid var(--border-subtle); border-radius: 8px; background: #ffffff;
            transition: all 0.2s;
        }
        .btn-login-nav:hover { background: #f1f5f9; }

        main {
            position: relative; z-index: 2; flex: 1; display: flex; justify-content: center;
            align-items: center; padding: 2.5rem 1.5rem;
        }

        .reg-card {
            background: var(--card-surface); width: 100%; max-width: 520px;
            padding: 2.5rem 2.25rem; border-radius: var(--radius-lg); box-shadow: var(--shadow);
            border: 1px solid var(--border-subtle); border-top: 4px solid var(--college-navy);
            animation: popIn 0.35s var(--ease-fluid) forwards;
        }

        @keyframes popIn {
            0% { opacity: 0; transform: translateY(18px) scale(0.96); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        .card-brand-header { text-align: center; margin-bottom: 1.75rem; }
        .login-crest { height: 68px; width: auto; object-fit: contain; margin: 0 auto 0.75rem; display: block; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.06)); }
        .card-brand-header h1 { font-size: 1.35rem; font-weight: 800; color: var(--college-navy); letter-spacing: -0.3px; margin-bottom: 0.25rem; }
        .card-brand-header p { font-size: 0.82rem; color: var(--text-muted); line-height: 1.4; }

        .alert-error {
            background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; padding: 0.75rem 0.9rem;
            border-radius: var(--radius-md); font-size: 0.82rem; margin-bottom: 1.25rem; font-weight: 500;
        }

        form { display: flex; flex-direction: column; gap: 1.2rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.45rem; text-align: left; }
        label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--college-navy); letter-spacing: 0.5px; }

        input, select {
            height: 44px; padding: 0 0.85rem; border: 1.5px solid var(--border-subtle);
            border-radius: var(--radius-md); font-size: 0.88rem; outline: none; background: #fafbfc;
            color: var(--text-dark); width: 100%; transition: all 0.2s ease;
        }
        input:focus, select:focus { background: #ffffff; border-color: var(--college-navy); box-shadow: 0 0 0 3px rgba(9, 26, 54, 0.08); }

        select {
            padding-right: 2.2rem;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 14px center; appearance: none; -webkit-appearance: none; cursor: pointer;
        }

        .btn-submit {
            margin-top: 0.5rem; background: linear-gradient(180deg, var(--college-navy) 0%, #07152b 100%);
            color: #ffffff; border: none; border-radius: var(--radius-md); height: 46px; font-size: 0.95rem; font-weight: 700; cursor: pointer; box-shadow: 0 4px 12px rgba(9, 26, 54, 0.2); transition: all 0.2s ease;
        }
        .btn-submit:hover { box-shadow: 0 6px 16px rgba(9, 26, 54, 0.3); transform: translateY(-1px); }

        /* Success State */
        .success-box {
            text-align: center; padding: 1.5rem 0;
        }
        .success-icon-wrap {
            width: 60px; height: 60px; border-radius: 50%; background: #ecfdf5; color: #10b981;
            display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1.25rem;
        }
        .success-icon-wrap svg { width: 30px; height: 30px; stroke-width: 2.5; }
        .success-box h2 { font-size: 1.35rem; color: var(--college-navy); font-weight: 800; margin-bottom: 0.5rem; }
        .success-box p { font-size: 0.88rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 1.75rem; }
    </style>
</head>
<body>

    <div class="watermark-bg"></div>

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
                <span class="terminal-pill">Personnel Registration</span>
            </div>

            <div>
                <a href="index.php" class="btn-login-nav">Existing User? Sign In</a>
            </div>
        </nav>
    </header>

    <main>
        <div class="reg-card">
            <?php if ($success_state): ?>
                <!-- Application Submitted Successfully Screen -->
                <div class="success-box">
                    <div class="success-icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </div>
                    <h2>Application Submitted!</h2>
                    <p>
                        Your registration request has been submitted to the <strong>Office of the Administrator</strong> for verification. 
                        <br><br>
                        Once your credentials are approved, your account will be activated and you will be able to log in to your designated department portal.
                    </p>
                    <a href="index.php" class="btn-submit" style="display:inline-flex; align-items:center; justify-content:center; width:100%; text-decoration:none;">
                        Return to Portal Login
                    </a>
                </div>
            <?php else: ?>
                <!-- Registration Form -->
                <div class="card-brand-header">
                    <img src="images/logo.png" alt="BSBT Seal" class="login-crest">
                    <h1>Staff Portal Registration</h1>
                    <p>Submit your personnel details for verification & credential issuance</p>
                </div>

                <?php if (!empty($error_message)): ?>
                    <div class="alert-error"><?php echo $error_message; ?></div>
                <?php endif; ?>

                <form action="register.php" method="POST">
                    <div class="form-group">
                        <label>Full Name / Title</label>
                        <input type="text" name="fullname" placeholder="e.g. Maria L. Santos" required autocomplete="off">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Employee / Staff ID</label>
                            <input type="text" name="username" placeholder="e.g. 2026-0042" required autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>Department</label>
                            <select name="role" required>
                                <option value="academics">Academe (Faculty / Supervisors)</option>
                                <option value="registrar">Registrars (Clearance / Records)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Create Passcode</label>
                            <input type="password" name="passcode" placeholder="••••••••" required autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label>Confirm Passcode</label>
                            <input type="password" name="confirm_passcode" placeholder="••••••••" required autocomplete="new-password">
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">Submit Registration for Approval</button>
                </form>
            <?php endif; ?>
        </div>
    </main>

</body>
</html>