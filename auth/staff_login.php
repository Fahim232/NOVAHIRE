<?php
require_once __DIR__ . '/../includes/bootstrap.php';
global $con;

$error_msg = '';

if (isset($_POST['submit'])) {
    require_csrf();
    rate_limit_response('login_staff');
    
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    
    if (is_login_locked($email)) {
        $error_msg = 'Account temporarily locked due to too many failed attempts. Please try again in 15 minutes.';
    } else {
        $stmt = mysqli_prepare($con, "SELECT s.id, s.company_id, s.full_name, s.email, s.password_hash, s.is_activated, s.status, c.company_name FROM company_staff s JOIN companies c ON s.company_id = c.id WHERE s.email = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            if ($row['status'] !== 'active') {
                $error_msg = 'Your account is inactive. Please contact your company administrator.';
            } elseif (!$row['is_activated']) {
                $error_msg = 'Your account is not activated. Please check your email for the activation link.';
            } elseif (password_verify($pass, $row['password_hash'])) {
                track_login_attempt($email, true);
                session_regenerate_id(true);
                
                $_SESSION['staff_id'] = $row['id'];
                $_SESSION['company_id'] = $row['company_id'];
                $_SESSION['staff_name'] = $row['full_name'];
                $_SESSION['company_name'] = $row['company_name'];
                $_SESSION['user_type'] = 'staff';
                
                mysqli_query($con, "UPDATE company_staff SET last_login = NOW() WHERE id = " . (int)$row['id']);
                
                echo "<script>window.location.href='" . BASE_URL . "/staff/index.php';</script>";
                exit();
            } else {
                track_login_attempt($email, false);
                $error_msg = 'Incorrect Password!';
            }
        } else {
            $error_msg = 'Account not found!';
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Staff Log In | NovaHire</title>
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
        [data-theme="dark"] body {
            background: #0b1120;
        }

        @keyframes lg-rise { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }

        .lg-wrap { width: 100%; max-width: 980px; }
        .lg-card {
            display: flex;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            box-shadow: 0 20px 60px -20px rgba(15, 23, 42, 0.15);
            overflow: hidden;
            animation: lg-rise .6s cubic-bezier(.21,1.02,.73,1) both;
        }
        [data-theme="dark"] .lg-card { background: #162032; border-color: #1e3a5f; box-shadow: 0 20px 60px -20px rgba(0, 0, 0, 0.5); }

        .lg-visual {
            position: relative;
            width: 44%;
            padding: 46px 40px;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: linear-gradient(160deg, #0f172a 0%, #1e3a5f 50%, #059669 100%);
            overflow: hidden;
        }
        .lg-visual::before, .lg-visual::after { content: ''; position: absolute; border-radius: 50%; pointer-events: none; }
        .lg-visual::before { top: -100px; right: -70px; width: 300px; height: 300px; background: radial-gradient(circle, rgba(16,185,129,0.2), transparent 70%); }
        .lg-visual::after { bottom: -120px; left: -60px; width: 260px; height: 260px; background: radial-gradient(circle, rgba(52,211,153,0.15), transparent 70%); }
        .lg-visual-inner { position: relative; z-index: 2; }
        
        .lg-logo {
            display: inline-flex; align-items: center; gap: 12px;
            font-family: 'Sora', sans-serif; font-weight: 700; font-size: 1.35rem;
            margin-bottom: 34px;
        }
        .lg-logo-icon {
            width: 46px; height: 46px; border-radius: 14px;
            background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25);
            color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
        }
        
        .lg-visual h2 { font-family: 'Sora', sans-serif; font-size: 2.2rem; font-weight: 800; line-height: 1.15; margin: 0 0 14px; }
        .lg-visual h2 span { color: #6ee7b7; }
        .lg-visual p { color: rgba(255,255,255,0.75); font-size: .94rem; line-height: 1.7; margin: 0; }
        .lg-visual-foot { color: rgba(255,255,255,0.5); font-size: .76rem; margin-top: 30px; position: relative; z-index: 2; }

        .lg-form { flex: 1; padding: 46px 48px; display: flex; flex-direction: column; justify-content: center; }
        .lg-title { font-family: 'Sora', sans-serif; font-weight: 800; font-size: 1.8rem; color: #0f172a; margin: 0 0 4px; }
        [data-theme="dark"] .lg-title { color: #f1f5f9; }
        .lg-sub { color: #64748b; font-size: .9rem; margin: 0 0 26px; }
        [data-theme="dark"] .lg-sub { color: #94a3b8; }

        .lg-field { position: relative; margin-bottom: 18px; }
        .lg-field > i {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: #94a3b8; font-size: .9rem; z-index: 2; transition: color .2s;
        }
        .lg-field.focus > i { color: #059669; }
        .lg-input {
            width: 100%; padding: 13px 46px 13px 44px;
            border: 2px solid #e2e8f0; border-radius: 14px;
            background: #ffffff; color: #0f172a; font-size: .92rem; font-weight: 600;
            outline: none; transition: border-color .2s, box-shadow .2s;
        }
        [data-theme="dark"] .lg-input { border-color: #1e3a5f; background: #1a2744; color: #f1f5f9; }
        .lg-input:focus { border-color: #059669; box-shadow: 0 0 0 4px rgba(5,150,105,0.1); }
        [data-theme="dark"] .lg-input:focus { border-color: #34d399; box-shadow: 0 0 0 4px rgba(52,211,153,0.15); }
        
        .lg-toggle {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            width: 34px; height: 34px; border-radius: 9px;
            border: 0; background: none; color: #94a3b8;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
        }
        .lg-toggle:hover { color: #0f172a; background: #f1f5f9; }

        .lg-row { display: flex; align-items: center; justify-content: space-between; margin: 2px 0 20px; gap: 10px; flex-wrap: wrap; }
        .lg-remember { display: flex; align-items: center; gap: 8px; font-size: .82rem; color: #64748b; font-weight: 600; cursor: pointer; }
        .lg-remember input { accent-color: #059669; width: 15px; height: 15px; }

        .lg-btn {
            width: 100%; border: 0; padding: 15px 20px; border-radius: 14px;
            font-family: 'Sora', sans-serif; font-weight: 600; font-size: .98rem;
            color: #ffffff; background: linear-gradient(135deg, #059669, #047857);
            box-shadow: 0 8px 20px -6px rgba(5,150,105,0.5);
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            cursor: pointer; transition: transform .25s, box-shadow .3s;
        }
        .lg-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 28px -8px rgba(5,150,105,0.6); }
        .lg-btn .spin { display: none; }
        .lg-btn.loading .spin { display: inline-block; }
        .lg-btn.loading .label { visibility: hidden; position: relative; }
        .lg-btn.loading .label::after { content: 'Signing in…'; visibility: visible; position: absolute; left: 50%; transform: translateX(-50%); }

        .lg-alt { text-align: center; margin-top: 22px; font-size: .85rem; color: #64748b; font-weight: 500; }
        .lg-alt a { color: #059669; font-weight: 800; text-decoration: none; }
        .lg-alt a:hover { text-decoration: underline; }

        .lg-alert {
            display: flex; align-items: center; gap: 11px;
            padding: 13px 16px; border-radius: 13px; font-weight: 600; font-size: .88rem; margin-bottom: 20px;
        }
        .lg-alert.danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        
        @media (max-width: 860px) {
            .lg-card { flex-direction: column; max-width: 520px; }
            .lg-visual { width: 100%; padding: 32px 30px; }
            .lg-form { padding: 34px 28px; }
        }
    </style>
</head>

<body>
    <div class="lg-wrap">
        <div class="lg-card">
            <!-- Visual Side -->
            <div class="lg-visual">
                <div class="lg-visual-inner">
                    <div class="lg-logo">
                        <div class="lg-logo-icon"><i class="fas fa-users-viewfinder"></i></div>
                        NovaHire Staff
                    </div>
                    <h2>Welcome to<br><span>Your Dashboard</span></h2>
                    <p>Sign in to manage your upcoming interviews, assess candidates, and collaborate with your hiring team.</p>
                </div>
                <div class="lg-visual-foot"><i class="fas fa-lock"></i>Secure 256-bit encrypted sign-in</div>
            </div>

            <!-- Form Side -->
            <div class="lg-form">
                <h1 class="lg-title">Staff Log In</h1>
                <p class="lg-sub">Enter your email and password to access the portal.</p>

                <?php if (!empty($error_msg)): ?>
                    <div class="lg-alert danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error_msg; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['flash_success'])): ?>
                    <div class="lg-alert" style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0;">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
                    </div>
                <?php endif; ?>

                <form action="staff_login.php" method="POST" id="lgForm">
                    <?php echo csrf_input(); ?>
                    
                    <div class="lg-field">
                        <i class="fa fa-envelope"></i>
                        <input name="email" type="email" placeholder="Staff Email Address" class="lg-input" required autocomplete="email">
                    </div>

                    <div class="lg-field">
                        <i class="fa fa-lock"></i>
                        <input name="password" id="passField" type="password" placeholder="Password" class="lg-input" required autocomplete="current-password">
                        <button type="button" class="lg-toggle" id="passToggle" onclick="togglePass()"><i class="fas fa-eye"></i></button>
                    </div>

                    <div class="lg-row">
                        <label class="lg-remember">
                            <input type="checkbox" id="rememberMe"> Remember me
                        </label>
                    </div>

                    <button type="submit" name="submit" class="lg-btn" id="lgSubmit">
                        <span class="spin"><i class="fas fa-spinner fa-spin"></i></span>
                        <span class="label"><i class="fas fa-sign-in-alt mr-2"></i>Sign In</span>
                    </button>

                    <div class="lg-alt">
                        Not a staff member? <a href="<?php echo BASE_URL; ?>/auth/login.php">Log in here</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function togglePass() {
        var f = document.getElementById('passField');
        var b = document.getElementById('passToggle');
        var show = f.type === 'password';
        f.type = show ? 'text' : 'password';
        b.innerHTML = show ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
    }

    (function () {
        document.querySelectorAll('.lg-field').forEach(function (w) {
            var input = w.querySelector('input');
            if (!input) return;
            input.addEventListener('focus', function () { w.classList.add('focus'); });
            input.addEventListener('blur', function () { w.classList.remove('focus'); });
        });

        var submit = document.getElementById('lgSubmit');
        var submitting = false;
        document.getElementById('lgForm').addEventListener('submit', function () {
            if (submitting) return false;
            submitting = true;
            submit.classList.add('loading');
        });
    })();
    </script>
</body>
</html>
