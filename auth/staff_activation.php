<?php
require_once __DIR__ . '/../includes/bootstrap.php';
global $con;

$token = $_GET['token'] ?? '';
$error = '';
$staff = null;

if (empty($token)) {
    $error = "Invalid or missing activation token.";
} else {
    // Check token validity
    $stmt = mysqli_prepare($con, "SELECT s.id, s.full_name, s.email, s.designation, s.is_activated, s.activation_expires_at, c.company_name FROM company_staff s JOIN companies c ON s.company_id = c.id WHERE s.activation_token = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $token);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        if ($row['is_activated']) {
            $error = "This account has already been activated. Please proceed to login.";
        } elseif (strtotime($row['activation_expires_at']) < time()) {
            $error = "This activation link has expired. Please ask your company administrator to resend the invitation.";
        } else {
            $staff = $row;
        }
    } else {
        $error = "Invalid or revoked activation link.";
    }
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && $staff) {
    require_csrf();
    $pass1 = $_POST['password'] ?? '';
    $pass2 = $_POST['confirm_password'] ?? '';
    
    if (strlen($pass1) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($pass1 !== $pass2) {
        $error = "Passwords do not match.";
    } else {
        $hash = password_hash($pass1, PASSWORD_BCRYPT);
        $update = mysqli_prepare($con, "UPDATE company_staff SET password_hash = ?, is_activated = 1, activation_token = NULL, activated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($update, "si", $hash, $staff['id']);
        if (mysqli_stmt_execute($update)) {
            $_SESSION['flash_success'] = "Account activated successfully! You can now log in.";
            header("Location: staff_login.php");
            exit;
        } else {
            $error = "Error activating account. Please try again.";
        }
        mysqli_stmt_close($update);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Staff Account Activation | NovaHire</title>
    <?php include '../includes/links.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: auto;
            padding: 40px 16px;
            font-family: 'Manrope', 'Inter', sans-serif;
            background: #f0f4f8;
            transition: background .4s ease;
        }
        [data-theme="dark"] body { background: #0b1120; }
        
        .lg-wrap { width: 100%; max-width: 580px; }
        .lg-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            box-shadow: 0 20px 60px -20px rgba(15, 23, 42, 0.15);
            padding: 40px 48px;
        }
        [data-theme="dark"] .lg-card {
            background: #162032;
            border-color: #1e3a5f;
            box-shadow: 0 20px 60px -20px rgba(0, 0, 0, 0.5);
        }
        
        .lg-title { font-family: 'Sora', sans-serif; font-weight: 800; font-size: 1.8rem; color: #0f172a; margin: 0 0 8px; letter-spacing: -0.03em; text-align: center; }
        [data-theme="dark"] .lg-title { color: #f1f5f9; }
        .lg-sub { color: #64748b; font-size: .95rem; margin: 0 0 26px; text-align: center; }
        [data-theme="dark"] .lg-sub { color: #94a3b8; }
        
        .staff-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 24px;
        }
        [data-theme="dark"] .staff-info { background: #0f172a; border-color: #1e293b; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem; }
        .info-row:last-child { margin-bottom: 0; }
        .info-label { color: #64748b; font-weight: 600; }
        .info-val { color: #0f172a; font-weight: 700; }
        [data-theme="dark"] .info-label { color: #94a3b8; }
        [data-theme="dark"] .info-val { color: #f1f5f9; }
        
        .lg-field { position: relative; margin-bottom: 18px; }
        .lg-field > i {
            position: absolute;
            left: 16px; top: 50%; transform: translateY(-50%);
            color: #94a3b8; font-size: .9rem; z-index: 2;
        }
        .lg-input {
            width: 100%;
            padding: 13px 46px 13px 44px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            background: #ffffff;
            color: #0f172a;
            font-size: .92rem; font-weight: 600;
            outline: none;
        }
        [data-theme="dark"] .lg-input {
            border-color: #1e3a5f;
            background: #1a2744;
            color: #f1f5f9;
        }
        .lg-input:focus { border-color: #1a56db; box-shadow: 0 0 0 4px rgba(26,86,219,0.1); }
        [data-theme="dark"] .lg-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59,130,246,0.15); }
        
        .lg-btn {
            width: 100%; border: 0; padding: 15px 20px; border-radius: 14px;
            font-family: 'Sora', sans-serif; font-weight: 600; font-size: .98rem;
            color: #ffffff; background: linear-gradient(135deg, #1a56db, #1e40af);
            box-shadow: 0 8px 20px -6px rgba(26,86,219,0.5);
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            cursor: pointer; transition: transform .25s, box-shadow .3s;
        }
        .lg-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 28px -8px rgba(26,86,219,0.6); color: #ffffff; }
        
        .lg-alert {
            display: flex; align-items: center; gap: 11px;
            padding: 13px 16px; border-radius: 13px;
            font-weight: 600; font-size: .88rem; margin-bottom: 20px;
        }
        .lg-alert.danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        [data-theme="dark"] .lg-alert.danger { color: #fca5a5; background: rgba(239,68,68,0.16); border-color: rgba(239,68,68,0.25); }
    </style>
</head>
<body>
    <div class="lg-wrap">
        <div class="lg-card">
            <h1 class="lg-title">Activate Account</h1>
            <p class="lg-sub">Set your password to activate your staff account.</p>

            <?php if (!empty($error)): ?>
                <div class="lg-alert danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php if (strpos($error, 'already') !== false): ?>
                    <a href="staff_login.php" class="lg-btn mt-3"><i class="fas fa-sign-in-alt"></i> Go to Login</a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($staff && empty($error)): ?>
                <div class="staff-info">
                    <div class="info-row">
                        <span class="info-label">Name</span>
                        <span class="info-val"><?php echo htmlspecialchars($staff['full_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email</span>
                        <span class="info-val"><?php echo htmlspecialchars($staff['email']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Company</span>
                        <span class="info-val"><?php echo htmlspecialchars($staff['company_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Role</span>
                        <span class="info-val"><?php echo htmlspecialchars($staff['designation']); ?></span>
                    </div>
                </div>

                <form method="POST">
                    <?php echo csrf_input(); ?>
                    
                    <div class="lg-field">
                        <i class="fa fa-lock"></i>
                        <input name="password" type="password" placeholder="New Password (min. 8 characters)" class="lg-input" required minlength="8">
                    </div>
                    
                    <div class="lg-field">
                        <i class="fa fa-check-circle"></i>
                        <input name="confirm_password" type="password" placeholder="Confirm Password" class="lg-input" required minlength="8">
                    </div>
                    
                    <button type="submit" class="lg-btn mt-3">
                        <i class="fas fa-user-check"></i> Activate Account
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
