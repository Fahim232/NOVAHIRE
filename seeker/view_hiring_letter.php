<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../admin/dbcon.php';

$user_id = intval($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$company_id = intval($_SESSION['company_id'] ?? 0);
$is_admin = !empty($_SESSION['admin_id']);

if ($user_id <= 0 && $company_id <= 0 && !$is_admin) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

$letter_id = intval($_GET['id'] ?? 0);
$app_id = intval($_GET['application_id'] ?? 0);

if ($letter_id <= 0 && $app_id <= 0) {
    header('Location: my_application.php');
    exit;
}

// Fetch letter
$letter = null;
if ($letter_id > 0) {
    $query = "SELECT hl.*, c.company_name, c.logo AS company_logo, cj.job_title 
              FROM hiring_letters hl
              JOIN companies c ON hl.company_id = c.id
              JOIN company_jobs cj ON hl.job_id = cj.id
              WHERE hl.id = ?";
    $stmt = mysqli_prepare($con, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $letter_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $letter = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }
} elseif ($app_id > 0) {
    $query = "SELECT hl.*, c.company_name, c.logo AS company_logo, cj.job_title 
              FROM hiring_letters hl
              JOIN companies c ON hl.company_id = c.id
              JOIN company_jobs cj ON hl.job_id = cj.id
              WHERE hl.application_id = ?
              ORDER BY hl.id DESC LIMIT 1";
    $stmt = mysqli_prepare($con, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $app_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $letter = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }
}

if (!$letter) {
    die("Hiring letter not found or unavailable.");
}

// Access control:
// 1. Admin can view
// 2. Issuing company can view
// 3. Candidate can view if candidate_id matches and letter status is SENT or APPROVED
$has_access = false;
if ($is_admin) {
    $has_access = true;
} elseif ($company_id > 0 && (int)$letter['company_id'] === $company_id) {
    $has_access = true;
} elseif ($user_id > 0 && (int)$letter['candidate_id'] === $user_id && in_array($letter['status'], ['SENT', 'APPROVED'])) {
    $has_access = true;
}

if (!$has_access) {
    die("Hiring letter not found or unavailable.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Hiring Letter | <?php echo htmlspecialchars($letter['company_name']); ?></title>
    <?php include '../includes/links.php'; ?>
    <style>
        :root {
            --bg-color: #f1f5f9;
            --paper: #ffffff;
            --text-main: #1e293b;
            --border-color: #e2e8f0;
        }
        body { background: var(--bg-color); color: var(--text-main); font-family: 'Inter', serif; }
        .letter-wrap {
            max-width: 800px;
            margin: 40px auto;
        }
        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .btn-print {
            background: #10b981;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn-print:hover { opacity: 0.9; }
        
        .letter-paper {
            background: var(--paper);
            padding: 60px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border-radius: 8px;
            line-height: 1.6;
            font-size: 1.05rem;
            color: #0f172a;
        }
        .letter-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 20px;
            margin-bottom: 40px;
        }
        .company-info h1 {
            margin: 0 0 5px;
            font-size: 1.8rem;
            color: #1e293b;
        }
        .company-info p { margin: 0; color: #64748b; font-size: 0.9rem; }
        
        .letter-date { text-align: right; color: #475569; font-weight: 500; margin-bottom: 30px; }
        
        .letter-subject { font-weight: 700; margin-bottom: 25px; text-transform: uppercase; font-size: 1.1rem; }
        
        .letter-body { white-space: pre-wrap; font-family: 'Inter', serif; margin-bottom: 50px; }
        
        .letter-footer {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }
        .signature-block { width: 45%; }
        .signature-line {
            border-bottom: 1px solid #94a3b8;
            height: 40px;
            margin-bottom: 10px;
        }
        
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .controls, header, nav, footer, .navbar { display: none !important; }
            .letter-wrap { margin: 0; width: 100%; max-width: 100%; }
            .letter-paper {
                box-shadow: none;
                padding: 0;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="letter-wrap">
        <div class="controls">
            <div style="display: flex; gap: 10px;">
                <a href="my_application.php" class="btn btn-secondary" style="padding: 10px 18px; border-radius: 8px; text-decoration: none; background: #64748b; color: white;"><i class="fas fa-arrow-left"></i> Back</a>
                <a href="hiring_onboarding.php?application_id=<?php echo $letter['application_id']; ?>" class="btn" style="padding: 10px 18px; border-radius: 8px; text-decoration: none; background: #6366f1; color: white; font-weight: 600;">
                    <i class="fas fa-folder-open"></i> Onboarding Documents
                </a>
            </div>
            <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
        </div>

        <div class="letter-paper">
            <div class="letter-header">
                <div class="company-info">
                    <h1><?php echo htmlspecialchars($letter['company_name']); ?></h1>
                    <p>Official Hiring Letter</p>
                </div>
            </div>

            <div class="letter-date">
                Date: <?php echo date('F j, Y', strtotime($letter['updated_at'])); ?>
            </div>

            <div class="letter-subject">
                Subject: <?php echo htmlspecialchars($letter['subject']); ?>
            </div>

            <div class="letter-body" style="white-space: pre-wrap;"><?php echo htmlspecialchars($letter['content']); ?></div>
            
            <div class="letter-footer">
                <div class="signature-block">
                    <p>For <?php echo htmlspecialchars($letter['company_name']); ?></p>
                    <div class="signature-line"></div>
                    <p>Authorized Signatory</p>
                </div>
                <div class="signature-block">
                    <p>Accepted By</p>
                    <div class="signature-line"></div>
                    <p>Candidate Signature</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
