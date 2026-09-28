<?php
// Core setup: session, DB, BASE_URL, helpers
require_once __DIR__ . '/../includes/bootstrap.php';
if (!isset($_SESSION['id'])) {
    header('location: ' . BASE_URL . '/auth/login.php');
    exit();
}

require_once __DIR__ . '/../admin/dbcon.php';

$user_id = $_SESSION['id'];

if (!isset($_GET['job_id'])) {
    header('location: browse_jobs.php');
    exit();
}

$job_id = mysqli_real_escape_string($con, $_GET['job_id']);

// Always verify quiz passed in DB - never trust URL params
$quiz_check = "SELECT score_percentage FROM job_quiz_attempts WHERE user_id = '$user_id' AND job_id = '$job_id' ORDER BY attempt_date DESC LIMIT 1";
$quiz_res = mysqli_query($con, $quiz_check);
$quiz_passed = false;
if (mysqli_num_rows($quiz_res) > 0) {
    $qr = mysqli_fetch_assoc($quiz_res);
    $quiz_passed = ($qr['score_percentage'] >= 60);
}

if (!$quiz_passed) {
    header('location: company_job_quiz.php?job_id=' . $job_id);
    exit();
}

require_once __DIR__ . '/../includes/premium.php';
$app_access = nh_check_access($con, $user_id, 'job_apply');

$job_query = "SELECT cj.*, c.company_name, c.industry, c.logo,
               (SELECT COUNT(*) FROM company_job_questions WHERE job_id = cj.id) as quiz_count
               FROM company_jobs cj
               JOIN companies c ON cj.company_id = c.id
               WHERE cj.id = '$job_id' AND cj.status = 'active'";
$job_result = mysqli_query($con, $job_query);

if (mysqli_num_rows($job_result) == 0) {
    echo "<script>alert('Job not found'); window.location.href='browse_jobs.php';</script>";
    exit();
}
$job = mysqli_fetch_assoc($job_result);

$quiz_check2 = "SELECT score_percentage FROM job_quiz_attempts WHERE user_id = '$user_id' AND job_id = '$job_id' ORDER BY attempt_date DESC LIMIT 1";
$quiz_res2 = mysqli_query($con, $quiz_check2);
$quiz_score = 0;
if (mysqli_num_rows($quiz_res2) > 0) {
    $qrow = mysqli_fetch_assoc($quiz_res2);
    $quiz_score = round($qrow['score_percentage'], 1);
}

$check_query = "SELECT * FROM job_applications WHERE user_id = '$user_id' AND job_id = '$job_id' AND cover_letter IS NOT NULL AND cover_letter != ''";
$check_result = mysqli_query($con, $check_query);
$already_submitted = mysqli_num_rows($check_result) > 0;

if (strtotime($job['deadline']) < time()) {
    echo "<script>alert('Application deadline has passed.'); window.location.href='job_details.php?id=$job_id';</script>";
    exit();
}

$user_query = "SELECT * FROM user_info WHERE id = '$user_id'";
$user_result = mysqli_query($con, $user_query);
$user_data = mysqli_fetch_assoc($user_result);

$company_id = $job['company_id'];

// --- Load existing AI-customized CVs for this user (for this job or general) ---
$ai_cvs = [];
$ai_cv_q = mysqli_query($con, "SELECT id, template_name, full_name, headline, created_at, job_id FROM ai_generated_cvs WHERE user_id = '$user_id' ORDER BY updated_at DESC LIMIT 10");
if ($ai_cv_q) {
    while ($cv_row = mysqli_fetch_assoc($ai_cv_q)) {
        $ai_cvs[] = $cv_row;
    }
}
$has_ai_cv = !empty($ai_cvs);
// Best match: CV saved for THIS job, otherwise most recent
$best_ai_cv = null;
foreach ($ai_cvs as $acv) {
    if (intval($acv['job_id']) === intval($job_id)) { $best_ai_cv = $acv; break; }
}
if (!$best_ai_cv && $has_ai_cv) $best_ai_cv = $ai_cvs[0];

// --- Load existing AI cover letter for this job ---
$existing_cover_letter = '';
$cl_q = mysqli_query($con, "SELECT content FROM ai_cover_letters WHERE user_id = '$user_id' AND job_id = '$job_id' ORDER BY created_at DESC LIMIT 1");
if ($cl_q && $cl_row = mysqli_fetch_assoc($cl_q)) {
    $existing_cover_letter = $cl_row['content'];
}

// --- Check for previously uploaded CV for this user ---
$uploaded_cv_file = '';
$ucv_q = mysqli_query($con, "SELECT cv_file FROM job_applications WHERE user_id = '$user_id' AND cv_file IS NOT NULL AND cv_file != '' ORDER BY applied_date DESC LIMIT 1");
if ($ucv_q && $ucv_row = mysqli_fetch_assoc($ucv_q)) {
    // Only show if file still exists
    if (file_exists(__DIR__ . '/../uploads/cv_files/' . $ucv_row['cv_file'])) {
        $uploaded_cv_file = $ucv_row['cv_file'];
    }
}

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['submit_application']) || isset($_POST['cover_letter']))) {
    $cover_letter = mysqli_real_escape_string($con, $_POST['cover_letter'] ?? '');
    $cv_type = in_array($_POST['cv_type'] ?? '', ['auto_generated','ai_customized','uploaded']) ? $_POST['cv_type'] : 'auto_generated';
    $cv_file_val = '';
    $ai_cv_id_val = 0;

    // Handle CV file upload
    if ($cv_type === 'uploaded' && !empty($_FILES['cv_upload']['name'])) {
        $allowed_ext = ['pdf', 'doc', 'docx'];
        $allowed_mime = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $max_size = 5 * 1024 * 1024; // 5MB
        $file = $_FILES['cv_upload'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = 'File upload failed. Please try again.';
        } elseif (!in_array($ext, $allowed_ext)) {
            $error_message = 'Invalid file type. Please upload PDF, DOC, or DOCX.';
        } elseif (!in_array($mime, $allowed_mime)) {
            $error_message = 'Invalid file content. Please upload a valid document.';
        } elseif ($file['size'] > $max_size) {
            $error_message = 'File too large. Maximum size is 5MB.';
        } else {
            $safe_name = 'cv_' . $user_id . '_' . time() . '.' . $ext;
            $dest = __DIR__ . '/../uploads/cv_files/' . $safe_name;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $cv_file_val = $safe_name;
            } else {
                $error_message = 'Failed to save uploaded file.';
            }
        }
    } elseif ($cv_type === 'uploaded' && !empty($_POST['existing_cv_file'])) {
        // Use previously uploaded CV
        $existing_file = basename($_POST['existing_cv_file']);
        if (file_exists(__DIR__ . '/../uploads/cv_files/' . $existing_file)) {
            $cv_file_val = $existing_file;
        }
    }

    // Handle AI CV selection
    if ($cv_type === 'ai_customized') {
        $ai_cv_id_val = intval($_POST['ai_cv_id'] ?? 0);
        // Verify ownership
        if ($ai_cv_id_val > 0) {
            $verify_cv = mysqli_query($con, "SELECT id FROM ai_generated_cvs WHERE id = '$ai_cv_id_val' AND user_id = '$user_id'");
            if (!$verify_cv || mysqli_num_rows($verify_cv) == 0) {
                $ai_cv_id_val = 0;
                $cv_type = 'auto_generated';
            }
        }
    }

    $cv_type_esc = mysqli_real_escape_string($con, $cv_type);
    $cv_file_esc = mysqli_real_escape_string($con, $cv_file_val);

    if (empty(trim($_POST['cover_letter'] ?? '')) && empty($error_message)) {
        $error_message = "Please write a cover letter.";
    }

    if (empty($error_message)) {
        $app_exists_query = "SELECT id FROM job_applications WHERE user_id = '$user_id' AND job_id = '$job_id'";
        $app_exists_result = mysqli_query($con, $app_exists_query);

        if (mysqli_num_rows($app_exists_result) > 0) {
            $existing_app = mysqli_fetch_assoc($app_exists_result);
            $quiz_status_val = $quiz_passed ? 'passed' : 'failed';
            $update_query = "UPDATE job_applications 
                            SET cover_letter = '$cover_letter', 
                                quiz_score = '$quiz_score', 
                                quiz_status = '$quiz_status_val', 
                                cv_type = '$cv_type_esc',
                                cv_file = " . ($cv_file_val ? "'$cv_file_esc'" : "NULL") . ",
                                ai_cv_id = " . ($ai_cv_id_val > 0 ? "'$ai_cv_id_val'" : "NULL") . ",
                                application_status = 'pending'
                            WHERE id = '{$existing_app['id']}'";
            $app_id = $existing_app['id'];
            if (!mysqli_query($con, $update_query)) {
                $error_message = "Failed to update application: " . mysqli_error($con);
            }
        } else {
            if (!$app_access['allowed']) {
                $error_message = "You've reached your free application limit (10/month). Upgrade to Pro for unlimited applications.";
            } else {
                $quiz_status_val = $quiz_passed ? 'passed' : 'failed';
                $insert_query = "INSERT INTO job_applications 
                                (user_id, job_id, company_id, cover_letter, cv_type, cv_file, ai_cv_id, quiz_score, quiz_status, applied_date, application_status) 
                                VALUES 
                                ('$user_id', '$job_id', '$company_id', '$cover_letter', '$cv_type_esc', 
                                " . ($cv_file_val ? "'$cv_file_esc'" : "NULL") . ",
                                " . ($ai_cv_id_val > 0 ? "'$ai_cv_id_val'" : "NULL") . ",
                                '$quiz_score', '$quiz_status_val', NOW(), 'pending')";
                if (mysqli_query($con, $insert_query)) {
                    $app_id = mysqli_insert_id($con);
                    nh_record_usage($con, $user_id, 'job_apply');
                } else {
                    $error_message = "Failed to submit application: " . mysqli_error($con);
                }
            }
        }

        if (empty($error_message)) {
            $title = "New Application Received";
            $message = "<strong>" . htmlspecialchars($user_data['username']) . "</strong> has applied for <strong>" . htmlspecialchars($job['job_title']) . "</strong> and passed the assessment with a score of <strong>" . $quiz_score . "%</strong>.";
            create_notification($con, 'company', $company_id, 'user', $user_id, $title, $message, 'new_application', 'job_applications', $app_id);

            $msg_subject = "Application for " . $job['job_title'];
            $msg_body = "Dear " . htmlspecialchars($job['company_name']) . " team,\n\n";
            $msg_body .= "A new application has been submitted for the position of " . htmlspecialchars($job['job_title']) . ".\n\n";
            $msg_body .= "Applicant: " . htmlspecialchars($user_data['username']) . "\n";
            $msg_body .= "Quiz Score: " . $quiz_score . "%\n";
            $msg_body .= "Status: Assessment Passed\n\n";
            $msg_body .= "Please review the application at your earliest convenience.";
            send_message($con, 'user', $user_id, 'company', $company_id, $msg_subject, $msg_body, $job_id);

            // Send email notifications
            require_once __DIR__ . '/../includes/mail.php';
            $seeker_email = get_user_email($user_id, 'user');
            if ($seeker_email && email_pref_enabled($user_id, 'user', 'email_applications')) {
                send_application_confirmation($seeker_email, $user_data['username'], $job['job_title'], $job['company_name']);
            }
            $company_email = get_user_email($company_id, 'company');
            if ($company_email && email_pref_enabled($company_id, 'company', 'email_applications')) {
                send_new_application_alert($company_email, $job['company_name'], $user_data['username'], $job['job_title']);
            }

            $_SESSION['app_success_msg'] = "Your application for <strong>" . htmlspecialchars($job['job_title']) . "</strong> at <strong>" . htmlspecialchars($job['company_name']) . "</strong> has been submitted successfully! The company will review your application and quiz score (" . $quiz_score . "%) and get back to you.";
            $quiz_param = $quiz_passed ? 'passed' : 'failed';
            if (!headers_sent()) {
                header("Location: company_job_application.php?job_id=" . $job_id . "&quiz=" . $quiz_param . "&submitted=1");
                exit();
            } else {
                echo "<script>window.location.href='company_job_application.php?job_id=" . $job_id . "&quiz=" . $quiz_param . "&submitted=1';</script>";
                exit();
            }
        }
    }
}

$show_success_banner = (isset($_GET['submitted']) || $already_submitted);
$banner_message = '';
if (!empty($_SESSION['app_success_msg'])) {
    $banner_message = $_SESSION['app_success_msg'];
    unset($_SESSION['app_success_msg']);
} elseif ($already_submitted) {
    $show_success_banner = true;
    $banner_message = "Your application for <strong>" . htmlspecialchars($job['job_title']) . "</strong> at <strong>" . htmlspecialchars($job['company_name']) . "</strong> has already been submitted. The company will review your application and quiz score (" . $quiz_score . "%).";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Apply for <?php echo htmlspecialchars($job['job_title']); ?> | NovaHire</title>
    <?php require_once __DIR__ . '/../includes/links.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --cfa-grad: linear-gradient(135deg, #2563eb 0%, #3b82f6 45%, #38bdf8 100%);
            --cfa-grad-soft: linear-gradient(135deg, rgba(37,99,235,.12), rgba(56,189,248,.12));
        }
        body { font-family: 'Inter', sans-serif; }
        .cfa-wrap { background: var(--bg); min-height: 70vh; }

        /* ═══ Hero ═══ */
        .cfa-hero {
            position: relative;
            background: var(--cfa-grad);
            margin-top: -16px;
            padding: 60px 0 170px;
            overflow: hidden;
            border-radius: 0 0 38px 38px;
        }
        .cfa-hero::before, .cfa-hero::after {
            content: ''; position: absolute; border-radius: 50%; pointer-events: none;
        }
        .cfa-hero::before { top: -140px; right: -90px; width: 420px; height: 420px; background: radial-gradient(circle, rgba(255,255,255,.18), transparent 70%); }
        .cfa-hero::after { bottom: -180px; left: -70px; width: 380px; height: 380px; background: radial-gradient(circle, rgba(255,255,255,.12), transparent 70%); }
        .cfa-hero-inner { position: relative; z-index: 2; }

        .cfa-breadcrumb {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.22);
            color: #fff; font-size: .76rem; font-weight: 700; letter-spacing: .04em;
            padding: 7px 15px; border-radius: 999px; margin-bottom: 20px;
        }
        .cfa-breadcrumb i { font-size: .7rem; }

        .cfa-hero h1 {
            font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; color: #fff;
            font-size: clamp(1.8rem, 4vw, 2.6rem); line-height: 1.15;
            margin: 0 0 12px; letter-spacing: -0.02em;
        }
        .cfa-hero h1 span {
            background: linear-gradient(90deg, #fde68a, #fbbf24);
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
        }
        .cfa-hero p.lead { color: rgba(255,255,255,.85); font-size: 1rem; font-weight: 500; max-width: 640px; margin: 0; }

        .cfa-hero-meta { display: flex; align-items: center; gap: 18px; margin-top: 26px; flex-wrap: wrap; }
        .cfa-stat {
            display: flex; align-items: center; gap: 12px;
            background: rgba(255,255,255,.13); border: 1px solid rgba(255,255,255,.2);
            backdrop-filter: blur(8px); border-radius: 16px; padding: 11px 18px;
        }
        .cfa-stat .num { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 1.05rem; color: #fff; line-height: 1.15; }
        .cfa-stat .lbl { font-size: .68rem; font-weight: 600; color: rgba(255,255,255,.78); }
        .cfa-stat i { font-size: 1.1rem; color: #fde68a; }

        /* quiz score ring */
        .cfa-score-ring { position: relative; width: 74px; height: 74px; }
        .cfa-score-ring svg { transform: rotate(-90deg); }
        .cfa-score-ring .rbg { stroke: rgba(255,255,255,.25); }
        .cfa-score-ring .rfg { stroke: url(#cfaGradRing); stroke-linecap: round; transition: stroke-dashoffset 1.2s cubic-bezier(.4,0,.2,1); }
        .cfa-score-val {
            position: absolute; inset: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 1rem; color: #fff; line-height: 1;
        }
        .cfa-score-cap { font-size: .5rem; font-weight: 700; color: rgba(255,255,255,.7); letter-spacing: .04em; margin-top: 2px; }

        /* ═══ Floating card ═══ */
        .cfa-card {
            position: relative; z-index: 5;
            max-width: 1080px; margin: -112px auto 0; 
            background: var(--bg-card); border: 1px solid var(--border-light);
            border-radius: 26px; box-shadow: 0 30px 70px -30px rgba(59,130,246,.45);
            overflow: hidden;
        }

        /* success banner */
        .cfa-success {
            display: none; position: relative;
            padding: 64px 40px; text-align: center;
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 60%, #a7f3d0 100%);
            overflow: hidden;
        }
        .cfa-success::before {
            content: ''; position: absolute; top: -60%; right: -15%;
            width: 420px; height: 420px;
            background: radial-gradient(circle, rgba(5,150,105,.16), transparent 70%);
            border-radius: 50%;
        }
        .cfa-success.show { display: block; animation: cfaIn .5s ease-out; }
        @keyframes cfaIn { from { opacity: 0; transform: scale(.96); } to { opacity: 1; transform: scale(1); } }
        @keyframes cfaBounce {
            0% { transform: scale(0); }
            50% { transform: scale(1.18); }
            100% { transform: scale(1); }
        }
        .cfa-success .s-ic {
            width: 92px; height: 92px; border-radius: 50%; margin: 0 auto 24px;
            background: #fff; display: flex; align-items: center; justify-content: center;
            font-size: 40px; color: #059669;
            box-shadow: 0 10px 30px rgba(5,150,105,.22);
            position: relative; z-index: 1;
            animation: cfaBounce .6s cubic-bezier(.34,1.56,.64,1) .2s both;
        }
        .cfa-success h2 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 26px; font-weight: 800; color: #065f46; margin-bottom: 10px; position: relative; z-index: 1; }
        .cfa-success p { color: #047857; font-size: 15px; margin: 0 auto 28px; max-width: 500px; line-height: 1.65; position: relative; z-index: 1; }
        .cfa-success .btn-row { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; position: relative; z-index: 1; }
        .cfa-success .btn-primary-x {
            display: inline-flex; align-items: center; gap: 8px;
            background: linear-gradient(135deg, #059669, #059669); color: #fff;
            padding: 13px 28px; border-radius: 13px; text-decoration: none;
            font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: .9rem;
            box-shadow: 0 10px 24px -10px rgba(5,150,105,.6);
            transition: transform .25s, box-shadow .3s;
        }
        .cfa-success .btn-primary-x:hover { transform: translateY(-2px); box-shadow: 0 16px 30px -12px rgba(5,150,105,.65); color: #fff; text-decoration: none; }
        .cfa-success .btn-ghost-x {
            display: inline-flex; align-items: center; gap: 8px;
            background: #fff; color: #047857;
            padding: 13px 28px; border-radius: 13px; text-decoration: none;
            font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: .9rem;
            border: 2px solid #a7f3d0; transition: all .25s;
        }
        .cfa-success .btn-ghost-x:hover { border-color: #059669; transform: translateY(-2px); color: #047857; text-decoration: none; }

        /* ═══ Content sections ═══ */
        .cfa-body { padding: 34px 44px 44px; }
        .cfa-section { margin-bottom: 34px; }
        .cfa-section:last-child { margin-bottom: 0; }

        .cfa-sec-head { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .cfa-sec-head .ic {
            width: 46px; height: 46px; flex-shrink: 0; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.05rem; background: var(--cfa-grad-soft);
            border: 1px solid rgba(59,130,246,.22); color: var(--primary);
        }
        .cfa-sec-head h3 { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 1.12rem; color: var(--text); margin: 0; }
        .cfa-sec-head p { color: var(--text-muted); font-size: .82rem; margin: 2px 0 0; }

        /* job banner inside card */
        .cfa-jobbar {
            display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
            background: var(--cfa-grad); border-radius: 18px; padding: 22px 26px;
            color: #fff; margin-bottom: 34px;
        }
        .cfa-joblogo {
            flex: 0 0 64px; height: 64px; border-radius: 15px; overflow: hidden;
            background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.3);
            display: flex; align-items: center; justify-content: center; padding: 8px;
        }
        .cfa-joblogo img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .cfa-joblogo .no-img { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 1.4rem; color: #fff; }
        .cfa-jobinfo { flex: 1; min-width: 200px; }
        .cfa-jobinfo h4 { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 1.15rem; margin: 0 0 8px; color: #fff; }
        .cfa-jobinfo .cfa-jmeta { display: flex; flex-wrap: wrap; gap: 8px; }
        .cfa-jmeta .pill {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.25);
            padding: 5px 13px; border-radius: 999px;
            font-size: .76rem; font-weight: 700;
        }
        .cfa-jmeta .pill i { color: #fde68a; font-size: .78rem; }
        .cfa-dl-badge {
            flex-shrink: 0; text-align: right;
            background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.25);
            border-radius: 14px; padding: 10px 16px;
        }
        .cfa-dl-badge .lbl { font-size: .62rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: rgba(255,255,255,.75); }
        .cfa-dl-badge .val { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: .95rem; color: #fff; }

        /* profile fields */
        .cfa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .cfa-field label {
            display: flex; align-items: center; gap: 7px;
            font-size: .74rem; font-weight: 800; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: .05em; margin-bottom: 7px;
        }
        .cfa-field label i { color: var(--primary); font-size: .8rem; }
        .cfa-field .val {
            background: var(--bg-hover); border: 1px solid var(--border-light);
            border-radius: 12px; padding: 13px 16px;
            font-size: .92rem; color: var(--text); font-weight: 600;
            min-height: 46px; display: flex; align-items: center;
        }
        .cfa-field.full { grid-column: 1 / -1; }
        .cfa-skills { display: flex; flex-wrap: wrap; gap: 7px; }
        .cfa-skill {
            display: inline-flex; align-items: center; gap: 6px;
            color: #2563eb; background: rgba(37,99,235,.09);
            border: 1px solid rgba(37,99,235,.16);
            padding: 5px 13px; border-radius: 999px;
            font-size: .78rem; font-weight: 700;
        }
        [data-theme="dark"] .cfa-skill { color: #93c5fd; }
        .cfa-muted-note { margin-top: 14px; font-size: .82rem; font-weight: 600; color: var(--text-muted); }
        .cfa-muted-note a { color: var(--primary); font-weight: 700; }

        /* CV preview */
        .cfa-cv-toggle {
            display: flex; align-items: center; justify-content: space-between;
            padding: 15px 20px; background: var(--bg-hover);
            border: 1px solid var(--border-light); border-radius: 14px 14px 0 0;
            cursor: pointer; transition: background .2s;
        }
        .cfa-cv-toggle:hover { background: var(--border-light); }
        .cfa-cv-toggle span { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: .9rem; color: var(--text); display: flex; align-items: center; gap: 9px; }
        .cfa-cv-toggle span .tic {
            width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
            color: var(--primary); background: var(--cfa-grad-soft); font-size: .9rem;
        }
        .cfa-cv-toggle .chev { color: var(--primary); transition: transform .3s; }
        .cfa-cv-toggle.collapsed .chev { transform: rotate(-90deg); }
        .cfa-cv-wrap {
            overflow: hidden; transition: max-height .45s cubic-bezier(.4,0,.2,1);
        }
        .cfa-cv {
            display: grid; grid-template-columns: 30% 70%; min-height: 380px;
            border: 1px solid var(--border-light); border-top: 0; border-radius: 0 0 14px 14px;
            background: var(--bg-card);
        }
        .cfa-cv-side { background: linear-gradient(160deg, #1a2b4a, #2c3e50); padding: 28px 22px; color: #fff; }
        .cfa-cv-side .cv-avatar {
            width: 82px; height: 82px; border-radius: 50%; border: 3px solid #fff;
            margin: 0 auto 16px; object-fit: cover; display: block;
            background: rgba(255,255,255,.2);
        }
        .cfa-cv-side .cv-stitle {
            font-size: .62rem; text-transform: uppercase; letter-spacing: .12em; font-weight: 700;
            margin: 20px 0 10px; padding-bottom: 6px; border-bottom: 2px solid rgba(255,255,255,.28);
            color: rgba(255,255,255,.9);
        }
        .cfa-cv-side .cv-item {
            display: flex; align-items: flex-start; gap: 8px;
            font-size: .68rem; color: rgba(255,255,255,.85); margin-bottom: 8px; line-height: 1.45;
        }
        .cfa-cv-side .cv-item i { color: #38bdf8; font-size: .7rem; margin-top: 2px; width: 13px; flex-shrink: 0; }
        .cfa-cv-side .cv-tag {
            display: inline-block; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.25);
            color: #fff; padding: 3px 10px; border-radius: 12px; font-size: .62rem; font-weight: 600;
            margin: 0 4px 6px 0;
        }
        .cfa-cv-main { padding: 28px 26px; }
        .cfa-cv-main .cv-name { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 1.35rem; font-weight: 700; color: var(--text); margin: 0 0 3px; }
        .cfa-cv-main .cv-role { font-size: .7rem; text-transform: uppercase; letter-spacing: .12em; color: #2563eb; font-weight: 700; margin: 0 0 14px; }
        .cfa-cv-main .cv-divider { width: 42px; height: 3px; background: var(--cfa-grad); margin-bottom: 20px; border-radius: 3px; }
        .cfa-cv-main .cv-title {
            font-family: 'Plus Jakarta Sans', sans-serif; font-size: .9rem; font-weight: 700; color: var(--text);
            margin: 0 0 12px; padding-bottom: 6px; border-bottom: 2px solid #38bdf8;
            display: flex; align-items: center;
        }
        .cfa-cv-main .cv-title::before {
            content: ''; width: 6px; height: 6px; background: #fbbf24;
            border-radius: 50%; margin-right: 9px;
        }
        .cfa-cv-main .cv-summary {
            font-size: .76rem; line-height: 1.7; color: var(--text-muted);
            background: var(--cfa-grad-soft); border-left: 3px solid #3b82f6;
            padding: 14px; border-radius: 6px; margin-bottom: 20px;
        }
        .cfa-cv-main .cv-edu-title { font-weight: 700; font-size: .82rem; color: var(--text); margin: 0; }
        .cfa-cv-main .cv-edu-sub { color: #3b82f6; font-size: .7rem; font-weight: 700; margin: 3px 0; }
        .cfa-cv-main .cv-edu-desc { font-size: .7rem; color: var(--text-muted); line-height: 1.6; margin: 0; }

        /* cover letter */
        .cfa-textarea {
            width: 100%; min-height: 190px;
            border: 1.5px solid var(--border-light); border-radius: 14px;
            background: var(--bg-hover); color: var(--text);
            padding: 16px 18px; font-size: .92rem; resize: vertical;
            font-family: 'Inter', sans-serif; line-height: 1.7;
            transition: all .2s;
        }
        .cfa-textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(59,130,246,.14); background: var(--bg-card); }
        .cfa-textarea::placeholder { color: var(--text-light); }
        .cfa-count-row { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; flex-wrap: wrap; gap: 8px; }
        .cfa-count {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: .78rem; font-weight: 700; color: var(--text-muted);
            background: var(--bg-hover); border: 1px solid var(--border-light);
            padding: 5px 12px; border-radius: 999px;
        }
        .cfa-count span { color: var(--primary); font-family: 'Plus Jakarta Sans', sans-serif; }
        .cfa-tip { font-size: .78rem; font-weight: 600; color: var(--text-muted); display: inline-flex; align-items: center; gap: 7px; }
        .cfa-tip i { color: #d97706; }

        /* error */
        .cfa-error {
            display: flex; align-items: flex-start; gap: 12px;
            background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.22);
            border-left: 4px solid #dc2626; border-radius: 13px;
            padding: 14px 16px; margin-bottom: 24px;
        }
        .cfa-error .ic { color: #dc2626; font-size: 1.05rem; margin-top: 1px; }
        .cfa-error span { color: var(--text); font-weight: 700; font-size: .86rem; }

        /* submit */
        .cfa-submit-zone { text-align: center; padding-top: 6px; }
        .cfa-submit {
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: .98rem;
            color: #fff; background: var(--cfa-grad); background-size: 150% 150%;
            padding: 16px 62px; border-radius: 15px; border: 0; cursor: pointer;
            box-shadow: 0 14px 30px -12px rgba(56,189,248,.65);
            transition: transform .25s, box-shadow .3s, background-position .4s;
        }
        .cfa-submit:hover { transform: translateY(-3px); background-position: 100% 50%; box-shadow: 0 20px 40px -14px rgba(56,189,248,.75); }
        .cfa-submit:active { transform: translateY(-1px); }
        .cfa-submit:disabled { background: var(--border-light); color: var(--text-light); cursor: not-allowed; box-shadow: none; transform: none; }
        .cfa-note { color: var(--text-muted); font-size: .82rem; font-weight: 600; margin-top: 15px; }
        .cfa-note i { color: var(--primary); }

        .cfa-footer { text-align: center; padding: 30px 0 44px; color: var(--text-light); font-size: .82rem; font-weight: 600; }

        .cfa-fade { opacity: 0; transform: translateY(16px); animation: cfaUp .5s ease forwards; }
        @keyframes cfaUp { to { opacity: 1; transform: none; } }

        /* ═══ CV Selector Cards ═══ */
        .cfa-cv-selector { display: flex; flex-direction: column; gap: 12px; margin-bottom: 18px; }
        .cfa-cv-option {
            display: flex; align-items: center; gap: 14px;
            background: var(--bg-hover); border: 2px solid var(--border-light);
            border-radius: 14px; padding: 16px 18px; cursor: pointer;
            transition: all .25s ease; position: relative;
        }
        .cfa-cv-option:hover { border-color: rgba(59,130,246,.4); background: var(--bg-card); }
        .cfa-cv-option.active { border-color: #3b82f6; background: rgba(59,130,246,.06); box-shadow: 0 0 0 4px rgba(59,130,246,.1); }
        .cfa-cv-option.disabled-opt { opacity: .55; }
        .cfa-cv-opt-radio {
            width: 22px; height: 22px; border-radius: 50%; border: 2px solid var(--border-light);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            transition: all .2s;
        }
        .cfa-cv-option.active .cfa-cv-opt-radio { border-color: #3b82f6; }
        .cfa-cv-opt-radio .dot {
            width: 11px; height: 11px; border-radius: 50%;
            background: transparent; transition: all .2s;
        }
        .cfa-cv-option.active .cfa-cv-opt-radio .dot { background: #3b82f6; }
        .cfa-cv-opt-body { display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0; }
        .cfa-cv-opt-icon {
            width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0; background: rgba(16,185,129,.1); color: #10b981;
        }
        .cfa-cv-opt-icon.ai { background: rgba(139,92,246,.1); color: #8b5cf6; }
        .cfa-cv-opt-icon.upload { background: rgba(59,130,246,.1); color: #3b82f6; }
        .cfa-cv-opt-info { min-width: 0; }
        .cfa-cv-opt-info h4 { margin: 0 0 3px; font-size: .88rem; font-weight: 700; color: var(--text); }
        .cfa-cv-opt-info p { margin: 0 0 5px; font-size: .76rem; color: var(--text-muted); line-height: 1.5; }
        .cfa-cv-opt-badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: .68rem; font-weight: 700; padding: 3px 10px; border-radius: 99px;
        }
        .cfa-cv-opt-badge.ready { background: rgba(16,185,129,.1); color: #10b981; }
        .cfa-cv-opt-badge.pending { background: rgba(245,158,11,.1); color: #f59e0b; }
        .cfa-cv-opt-action {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: .76rem; font-weight: 700; color: #3b82f6; text-decoration: none;
            padding: 6px 14px; border-radius: 10px; background: rgba(59,130,246,.08);
            white-space: nowrap; transition: all .2s; flex-shrink: 0;
        }
        .cfa-cv-opt-action:hover { background: rgba(59,130,246,.15); }

        /* ═══ Upload Zone ═══ */
        .cfa-upload-zone { margin-bottom: 18px; }
        .cfa-dropzone {
            border: 2px dashed var(--border-light); border-radius: 14px;
            padding: 36px 20px; text-align: center; cursor: pointer;
            transition: all .25s; background: var(--bg-hover);
        }
        .cfa-dropzone:hover, .cfa-dropzone.dragover { border-color: #3b82f6; background: rgba(59,130,246,.04); }
        .cfa-dropzone i { font-size: 2rem; color: #3b82f6; margin-bottom: 10px; display: block; }
        .cfa-dropzone p { margin: 0 0 6px; font-size: .88rem; font-weight: 600; color: var(--text); }
        .cfa-dropzone .browse-link { color: #3b82f6; cursor: pointer; text-decoration: underline; }
        .cfa-upload-hint { font-size: .72rem; color: var(--text-muted); font-weight: 600; }
        .cfa-file-preview {
            display: flex; align-items: center; gap: 12px;
            background: rgba(16,185,129,.06); border: 1px solid rgba(16,185,129,.2);
            border-radius: 12px; padding: 12px 16px; margin-top: 10px;
        }
        .cfa-file-preview .file-icon { font-size: 1.4rem; color: #ef4444; }
        .cfa-file-preview .file-info { flex: 1; min-width: 0; }
        .cfa-file-preview .file-name { display: block; font-weight: 700; font-size: .82rem; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cfa-file-preview .file-size { font-size: .72rem; color: var(--text-muted); }
        .cfa-file-preview .file-remove {
            width: 30px; height: 30px; border-radius: 50%; border: none;
            background: rgba(239,68,68,.1); color: #ef4444; cursor: pointer;
            display: flex; align-items: center; justify-content: center; font-size: .82rem;
            transition: all .2s; flex-shrink: 0;
        }
        .cfa-file-preview .file-remove:hover { background: rgba(239,68,68,.2); }

        /* ═══ AI Cover Letter Bar ═══ */
        .cfa-ai-cl-bar {
            display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap;
        }
        .cfa-ai-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 12px; border: 0; cursor: pointer;
            font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: .84rem;
            background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff;
            box-shadow: 0 6px 18px -6px rgba(139,92,246,.5);
            transition: all .25s;
        }
        .cfa-ai-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px -8px rgba(139,92,246,.6); }
        .cfa-ai-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
        .cfa-ai-tone-select select {
            background: var(--bg-hover); border: 1.5px solid var(--border-light);
            border-radius: 10px; padding: 8px 14px; font-size: .82rem; font-weight: 600;
            color: var(--text); font-family: 'Inter', sans-serif; cursor: pointer;
        }
        .cfa-ai-status {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: .82rem; font-weight: 600; color: #8b5cf6;
        }

        /* ═══ Application Summary ═══ */
        .cfa-app-summary {
            background: var(--bg-hover); border: 1.5px solid var(--border-light);
            border-radius: 14px; padding: 18px 22px;
        }
        .cfa-app-summary h4 {
            margin: 0 0 14px; font-size: .9rem; font-weight: 700; color: var(--text);
            display: flex; align-items: center; gap: 8px;
        }
        .cfa-app-summary h4 i { color: #3b82f6; }
        .cfa-summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        .cfa-summary-item {
            background: var(--bg-card); border: 1px solid var(--border-light);
            border-radius: 10px; padding: 12px 14px; text-align: center;
        }
        .cfa-summary-item .label { display: block; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 5px; }
        .cfa-summary-item .value { display: flex; align-items: center; justify-content: center; gap: 6px; font-size: .82rem; font-weight: 700; color: var(--text); }

        /* ═══ Floating Side Panel ═══ */
        .cfa-side-panel {
            position: fixed; right: 24px; top: 50%; transform: translateY(-50%);
            z-index: 1000; display: flex; flex-direction: column; gap: 8px;
        }
        .cfa-side-btn {
            width: 48px; height: 48px; border-radius: 14px; border: 0; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; color: #fff; transition: all .25s;
            box-shadow: 0 4px 16px rgba(0,0,0,.15); position: relative;
        }
        .cfa-side-btn.cv-btn { background: linear-gradient(135deg, #10b981, #059669); }
        .cfa-side-btn.ai-btn { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
        .cfa-side-btn.upload-btn { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .cfa-side-btn.letter-btn { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .cfa-side-btn:hover { transform: scale(1.1); }
        .cfa-side-btn .tooltip {
            position: absolute; right: calc(100% + 10px); top: 50%; transform: translateY(-50%);
            background: var(--bg-card); border: 1px solid var(--border-light);
            color: var(--text); font-size: .72rem; font-weight: 700;
            padding: 5px 12px; border-radius: 8px; white-space: nowrap;
            opacity: 0; pointer-events: none; transition: all .2s;
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
        }
        .cfa-side-btn:hover .tooltip { opacity: 1; }

        /* ═══ Responsive ═══ */
        @media (max-width: 860px) {
            .cfa-hero { padding: 48px 0 150px; border-radius: 0 0 28px 28px; }
            .cfa-card { margin-top: -100px; border-radius: 20px; }
            .cfa-body { padding: 24px 22px 32px; }
            .cfa-grid { grid-template-columns: 1fr; }
            .cfa-cv { grid-template-columns: 1fr; }
            .cfa-dl-badge { text-align: left; width: 100%; }
            .cfa-summary-grid { grid-template-columns: 1fr; }
            .cfa-side-panel { display: none; }
        }
        @media (max-width: 480px) {
            .cfa-body { padding: 20px 16px 26px; }
            .cfa-submit { width: 100%; padding: 15px; }
            .cfa-success { padding: 44px 22px; }
            .cfa-success .btn-primary-x, .cfa-success .btn-ghost-x { width: 100%; justify-content: center; }
            .cfa-cv-option { flex-wrap: wrap; }
            .cfa-cv-opt-action { width: 100%; justify-content: center; margin-top: 6px; }
        }
    </style>
</head>
<body>
<div class="cfa-wrap">

    <!-- Hero -->
    <div class="cfa-hero">
        <div class="container cfa-hero-inner">
            <a href="job_details.php?id=<?php echo $job_id; ?>" class="cfa-breadcrumb">
                <i class="fas fa-arrow-left"></i> Dashboard <i class="fas fa-chevron-right"></i> Apply
            </a>
            <h1>Submit Your <span>Application</span></h1>
            <p class="lead">You're one step away from joining <?php echo htmlspecialchars($job['company_name']); ?>. Your quiz score is shared with the employer.</p>

            <div class="cfa-hero-meta">
                <?php
                $ring_c = 2 * 3.14159 * 30;
                $ring_off = $ring_c - (min($quiz_score, 100) / 100) * $ring_c;
                ?>
                <div class="cfa-score-ring">
                    <svg viewBox="0 0 74 74" width="74" height="74">
                        <defs>
                            <linearGradient id="cfaGradRing" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#fde68a"/>
                                <stop offset="100%" stop-color="#fbbf24"/>
                            </linearGradient>
                        </defs>
                        <circle class="rbg" cx="37" cy="37" r="30" fill="none" stroke-width="6"/>
                        <circle class="rfg" cx="37" cy="37" r="30" fill="none" stroke-width="6"
                            stroke-dasharray="<?php echo $ring_c; ?>"
                            stroke-dashoffset="<?php echo $ring_off; ?>"/>
                    </svg>
                    <div class="cfa-score-val"><?php echo $quiz_score; ?>%<span class="cfa-score-cap">Quiz</span></div>
                </div>
                <div class="cfa-stat"><i class="fas fa-building"></i><div><div class="num"><?php echo htmlspecialchars($job['company_name']); ?></div><div class="lbl">Company</div></div></div>
                <div class="cfa-stat"><i class="fas fa-map-marker-alt"></i><div><div class="num"><?php echo htmlspecialchars($job['location']); ?></div><div class="lbl">Location</div></div></div>
                <div class="cfa-stat"><i class="fas fa-clock"></i><div><div class="num"><?php echo htmlspecialchars($job['employment_type']); ?></div><div class="lbl">Type</div></div></div>
            </div>
        </div>
    </div>

    <!-- Main card -->
    <div class="cfa-card cfa-fade" style="animation-delay:.1s">

        <!-- Success Banner -->
        <div class="cfa-success <?php echo $show_success_banner ? 'show' : ''; ?>" id="successBanner" style="<?php echo $show_success_banner ? 'display:block;' : 'display:none;'; ?>">
            <div class="s-ic"><i class="fas fa-check"></i></div>
            <h2>Application Submitted Successfully!</h2>
            <p><?php echo $banner_message ?: 'Your application has been sent to the company. They will review your profile and quiz results.'; ?></p>
            <div style="margin-bottom: 24px; font-weight: 600; color: #047857; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fas fa-spinner fa-spin"></i> <span>Redirecting to your dashboard in <strong id="redirectCountdown">3</strong> seconds...</span>
            </div>
            <div class="btn-row">
                <a href="seeker_dashboard.php" class="btn-primary-x"><i class="fas fa-home mr-2"></i>Go to Dashboard Now</a>
                <a href="my_application.php" class="btn-ghost-x"><i class="fas fa-list mr-2"></i>View My Applications</a>
            </div>
        </div>

        <form method="POST" id="applicationForm" enctype="multipart/form-data">
            <input type="hidden" name="submit_application" value="1">
            <div class="cfa-body" id="cfaFormBody" style="<?php echo $show_success_banner ? 'display:none;' : ''; ?>">

                <?php if (!empty($error_message)): ?>
                    <div class="cfa-error">
                        <i class="fas fa-exclamation-circle ic"></i>
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!$app_access['allowed']): ?>
                    <div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:14px 18px;border-radius:12px;margin-bottom:18px">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <i class="fas fa-lock"></i>
                            <span style="font-weight:700">You've used all 10 free applications this month.</span>
                            <a href="pro.php" style="margin-left:auto;background:linear-gradient(135deg,#1a56db,#0ea5e9);color:#fff;padding:6px 16px;border-radius:99px;font-weight:700;font-size:.82rem;text-decoration:none;white-space:nowrap">Upgrade to Pro</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php nh_render_usage_bar($con, $user_id, 'job_apply'); ?>
                <?php endif; ?>

                <!-- Job banner -->
                <div class="cfa-jobbar cfa-fade" style="animation-delay:.14s">
                    <div class="cfa-joblogo">
                        <?php if (!empty($job['logo'])): ?>
                            <img src="<?php echo BASE_URL; ?>/uploads/company_logos/<?php echo htmlspecialchars($job['logo']); ?>" alt="<?php echo htmlspecialchars($job['company_name']); ?>">
                        <?php else: ?>
                            <span class="no-img"><?php echo htmlspecialchars(substr($job['company_name'], 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="cfa-jobinfo">
                        <h4><?php echo htmlspecialchars($job['job_title']); ?></h4>
                        <div class="cfa-jmeta">
                            <span class="pill"><i class="fas fa-building"></i><?php echo htmlspecialchars($job['company_name']); ?></span>
                            <span class="pill"><i class="fas fa-map-marker-alt"></i><?php echo htmlspecialchars($job['location']); ?></span>
                            <span class="pill"><i class="fas fa-briefcase"></i><?php echo htmlspecialchars($job['employment_type']); ?></span>
                            <?php if ($job['salary_range']): ?>
                                <span class="pill"><i class="fas fa-dollar-sign"></i><?php echo htmlspecialchars($job['salary_range']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($job['industry'])): ?>
                                <span class="pill"><i class="fas fa-industry"></i><?php echo htmlspecialchars($job['industry']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cfa-dl-badge">
                        <div class="lbl">Deadline</div>
                        <div class="val"><?php echo htmlspecialchars(date('M d, Y', strtotime($job['deadline']))); ?></div>
                    </div>
                </div>

                <!-- Your Profile -->
                <div class="cfa-section cfa-fade" style="animation-delay:.18s">
                    <div class="cfa-sec-head">
                        <div class="ic"><i class="fas fa-user"></i></div>
                        <div>
                            <h3>Your Profile Information</h3>
                            <p>This information will be shared with the employer.</p>
                        </div>
                    </div>
                    <div class="cfa-grid">
                        <div class="cfa-field">
                            <label><i class="fas fa-id-badge"></i> Full Name</label>
                            <div class="val"><?php echo htmlspecialchars($user_data['username']); ?></div>
                        </div>
                        <div class="cfa-field">
                            <label><i class="fas fa-envelope"></i> Email Address</label>
                            <div class="val"><?php echo htmlspecialchars($user_data['email']); ?></div>
                        </div>
                        <div class="cfa-field">
                            <label><i class="fas fa-phone-alt"></i> Phone Number</label>
                            <div class="val"><?php echo htmlspecialchars($user_data['phone'] ?: 'Not provided'); ?></div>
                        </div>
                        <div class="cfa-field">
                            <label><i class="fas fa-graduation-cap"></i> Degree / Education</label>
                            <div class="val"><?php echo htmlspecialchars($user_data['user_degree'] ?: 'Not provided'); ?></div>
                        </div>
                        <div class="cfa-field full">
                            <label><i class="fas fa-tools"></i> Skills</label>
                            <div class="val">
                                <div class="cfa-skills">
                                    <?php
                                    $skills = explode(',', $user_data['user_skills'] ?? '');
                                    $has_skills = false;
                                    foreach ($skills as $s) {
                                        $s = trim($s);
                                        if ($s !== '') {
                                            $has_skills = true;
                                            echo '<span class="cfa-skill">' . htmlspecialchars($s) . '</span>';
                                        }
                                    }
                                    if (!$has_skills) echo '<span class="text-muted">Not provided</span>';
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="cfa-muted-note">
                        <i class="fas fa-info-circle mr-1"></i> To update your profile, visit your <a href="profile.php">profile page</a>.
                    </div>
                </div>

                <!-- ═══ CV Selection ═══ -->
                <div class="cfa-section cfa-fade" style="animation-delay:.22s">
                    <div class="cfa-sec-head">
                        <div class="ic" style="background: rgba(16,185,129,.1); border-color: rgba(16,185,129,.25); color: #10b981;"><i class="fas fa-file-alt"></i></div>
                        <div>
                            <h3>Choose Your CV</h3>
                            <p>Select which CV to submit with this application.</p>
                        </div>
                    </div>

                    <input type="hidden" name="cv_type" id="cvTypeInput" value="auto_generated">
                    <input type="hidden" name="ai_cv_id" id="aiCvIdInput" value="<?php echo $best_ai_cv ? intval($best_ai_cv['id']) : 0; ?>">
                    <input type="hidden" name="existing_cv_file" id="existingCvFileInput" value="">

                    <div class="cfa-cv-selector">
                        <!-- Option 1: Auto-Generated -->
                        <label class="cfa-cv-option active" id="cvOpt1" onclick="selectCvType('auto_generated')">
                            <div class="cfa-cv-opt-radio"><div class="dot"></div></div>
                            <div class="cfa-cv-opt-body">
                                <div class="cfa-cv-opt-icon"><i class="fas fa-magic"></i></div>
                                <div class="cfa-cv-opt-info">
                                    <h4>Auto-Generated CV</h4>
                                    <p>Generated from your profile data — always up to date.</p>
                                    <span class="cfa-cv-opt-badge ready"><i class="fas fa-check-circle"></i> Ready</span>
                                </div>
                            </div>
                        </label>

                        <!-- Option 2: AI Customized -->
                        <label class="cfa-cv-option<?php echo $has_ai_cv ? '' : ' disabled-opt'; ?>" id="cvOpt2" onclick="selectCvType('ai_customized')">
                            <div class="cfa-cv-opt-radio"><div class="dot"></div></div>
                            <div class="cfa-cv-opt-body">
                                <div class="cfa-cv-opt-icon ai"><i class="fas fa-robot"></i></div>
                                <div class="cfa-cv-opt-info">
                                    <h4>AI Customized CV</h4>
                                    <?php if ($has_ai_cv): ?>
                                        <p>
                                            <strong><?php echo htmlspecialchars($best_ai_cv['full_name']); ?></strong>
                                            — <?php echo htmlspecialchars($best_ai_cv['template_name']); ?> template
                                            <?php if (intval($best_ai_cv['job_id']) === intval($job_id)): ?>
                                                <span style="color:#10b981;font-weight:700;">(Tailored for this job!)</span>
                                            <?php endif; ?>
                                        </p>
                                        <span class="cfa-cv-opt-badge ready"><i class="fas fa-check-circle"></i> Available</span>
                                    <?php else: ?>
                                        <p>Create a tailored CV using our AI CV Generator.</p>
                                        <span class="cfa-cv-opt-badge pending"><i class="fas fa-clock"></i> Not Created Yet</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="ai_cv_generator.php?job_id=<?php echo $job_id; ?>&return_to=application" class="cfa-cv-opt-action" onclick="event.stopPropagation();">
                                <i class="fas fa-external-link-alt"></i> <?php echo $has_ai_cv ? 'Edit' : 'Create'; ?>
                            </a>
                        </label>

                        <!-- Option 3: Upload -->
                        <label class="cfa-cv-option" id="cvOpt3" onclick="selectCvType('uploaded')">
                            <div class="cfa-cv-opt-radio"><div class="dot"></div></div>
                            <div class="cfa-cv-opt-body">
                                <div class="cfa-cv-opt-icon upload"><i class="fas fa-cloud-upload-alt"></i></div>
                                <div class="cfa-cv-opt-info">
                                    <h4>Upload Your CV</h4>
                                    <p>Upload a PDF, DOC, or DOCX file (max 5MB).</p>
                                    <?php if ($uploaded_cv_file): ?>
                                        <span class="cfa-cv-opt-badge ready"><i class="fas fa-check-circle"></i> Previous: <?php echo htmlspecialchars($uploaded_cv_file); ?></span>
                                    <?php else: ?>
                                        <span class="cfa-cv-opt-badge pending"><i class="fas fa-upload"></i> No file yet</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                    </div>

                    <!-- Upload zone (hidden by default) -->
                    <div class="cfa-upload-zone" id="uploadZone" style="display:none;">
                        <div class="cfa-dropzone" id="dropzone">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p>Drag & drop your CV here or <span class="browse-link">browse</span></p>
                            <span class="cfa-upload-hint">PDF, DOC, DOCX — Max 5MB</span>
                            <input type="file" name="cv_upload" id="cvFileInput" accept=".pdf,.doc,.docx" style="display:none;">
                        </div>
                        <div class="cfa-file-preview" id="filePreview" style="display:none;">
                            <i class="fas fa-file-pdf file-icon"></i>
                            <div class="file-info">
                                <span class="file-name" id="fileName"></span>
                                <span class="file-size" id="fileSize"></span>
                            </div>
                            <button type="button" class="file-remove" id="fileRemove"><i class="fas fa-times"></i></button>
                        </div>
                    </div>

                    <!-- Collapsible Auto-Generated CV Preview -->
                    <div id="autoGenCvSection">
                        <div class="cfa-cv-toggle" id="cvToggle" onclick="toggleCvPreview()">
                            <span>
                                <span class="tic"><i class="fas fa-eye"></i></span>
                                Preview Auto-Generated CV
                            </span>
                            <i class="fas fa-chevron-down chev" id="cvToggleIcon"></i>
                        </div>
                        <div class="cfa-cv-wrap" id="cvPreviewWrap" style="max-height:0;overflow:hidden;">
                            <div class="cfa-cv">
                                <!-- Sidebar -->
                                <div class="cfa-cv-side">
                                    <?php if (!empty($user_data['profile'])): ?>
                                        <img src="images/<?php echo htmlspecialchars($user_data['profile']); ?>" alt="Profile" class="cv-avatar">
                                    <?php else: ?>
                                        <div class="cv-avatar" style="display: flex; align-items: center; justify-content: center; font-size: 30px; color: rgba(255,255,255,.55);">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div class="cv-stitle">Contact</div>
                                    <div class="cv-item">
                                        <i class="fas fa-envelope"></i>
                                        <span><?php echo htmlspecialchars($user_data['email']); ?></span>
                                    </div>
                                    <div class="cv-item">
                                        <i class="fas fa-phone-alt"></i>
                                        <span><?php echo htmlspecialchars($user_data['phone'] ?: 'N/A'); ?></span>
                                    </div>
                                    <div class="cv-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span>Bangladesh</span>
                                    </div>

                                    <div class="cv-stitle">Skills</div>
                                    <div>
                                        <?php foreach ($skills as $s): ?>
                                            <?php if (trim($s) !== ''): ?>
                                                <span class="cv-tag"><?php echo htmlspecialchars(trim($s)); ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Main -->
                                <div class="cfa-cv-main">
                                    <h2 class="cv-name"><?php echo htmlspecialchars($user_data['username']); ?></h2>
                                    <p class="cv-role"><?php echo htmlspecialchars($user_data['user_degree'] ?: 'Professional'); ?></p>
                                    <div class="cv-divider"></div>

                                    <div>
                                        <h3 class="cv-title">About</h3>
                                        <p class="cv-summary">
                                            Motivated and detail-oriented <?php echo htmlspecialchars($user_data['user_degree'] ?? 'professional'); ?> with a strong foundation in <?php echo htmlspecialchars($user_data['user_skills'] ?? 'relevant technologies'); ?>.
                                            Eager to join the workforce and contribute to projects that require innovative thinking and problem-solving skills.
                                        </p>
                                    </div>

                                    <div>
                                        <h3 class="cv-title">Education</h3>
                                        <p class="cv-edu-title"><?php echo htmlspecialchars($user_data['user_degree'] ?: 'Degree'); ?></p>
                                        <p class="cv-edu-sub">United International University</p>
                                        <p class="cv-edu-desc">Successfully completed degree with focus on core computing principles.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ Cover Letter ═══ -->
                <div class="cfa-section cfa-fade" style="animation-delay:.26s">
                    <div class="cfa-sec-head">
                        <div class="ic" style="background: rgba(59,130,246,.1); border-color: rgba(59,130,246,.25); color: #2563eb;"><i class="fas fa-pen"></i></div>
                        <div>
                            <h3>Cover Letter</h3>
                            <p>Tell the employer why you're the perfect fit.</p>
                        </div>
                    </div>

                    <!-- AI Generate Button -->
                    <div class="cfa-ai-cl-bar" id="aiClBar">
                        <button type="button" class="cfa-ai-btn" id="aiGenBtn" onclick="generateCoverLetter()">
                            <i class="fas fa-wand-magic-sparkles"></i> Generate with AI
                        </button>
                        <div class="cfa-ai-tone-select" id="toneSelect">
                            <select id="toneDropdown">
                                <option value="professional">Professional</option>
                                <option value="confident">Confident</option>
                                <option value="friendly">Friendly</option>
                                <option value="formal">Formal</option>
                            </select>
                        </div>
                        <div class="cfa-ai-status" id="aiStatus" style="display:none;">
                            <i class="fas fa-spinner fa-spin"></i> <span>Generating...</span>
                        </div>
                    </div>

                    <textarea
                        name="cover_letter"
                        id="coverLetter"
                        class="cfa-textarea"
                        required
                        maxlength="5000"
                        placeholder="Tell <?php echo htmlspecialchars($job['company_name']); ?> why you're a great fit for the <?php echo htmlspecialchars($job['job_title']); ?> position. Mention your relevant experience, skills, and what excites you about this opportunity..."
                    ><?php echo htmlspecialchars($existing_cover_letter); ?></textarea>
                    <div class="cfa-count-row">
                        <span class="cfa-tip"><i class="fas fa-lightbulb"></i> Tip: Keep it personal and reference your quiz score (<?php echo $quiz_score; ?>%).</span>
                        <span class="cfa-count"><i class="fas fa-text-width"></i> <span id="clCount"><?php echo strlen($existing_cover_letter); ?></span> / 5000 chars</span>
                    </div>
                </div>

                <!-- ═══ Application Summary ═══ -->
                <div class="cfa-section cfa-fade" style="animation-delay:.28s">
                    <div class="cfa-app-summary" id="appSummary">
                        <h4><i class="fas fa-clipboard-check"></i> Application Summary</h4>
                        <div class="cfa-summary-grid">
                            <div class="cfa-summary-item">
                                <span class="label">CV Type</span>
                                <span class="value" id="summaryCvType"><i class="fas fa-magic"></i> Auto-Generated</span>
                            </div>
                            <div class="cfa-summary-item">
                                <span class="label">Cover Letter</span>
                                <span class="value" id="summaryCoverLetter"><i class="fas fa-times-circle" style="color:#ef4444;"></i> Not written yet</span>
                            </div>
                            <div class="cfa-summary-item">
                                <span class="label">Quiz Score</span>
                                <span class="value"><i class="fas fa-check-circle" style="color:#10b981;"></i> <?php echo $quiz_score; ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="cfa-section cfa-fade" style="animation-delay:.3s">
                    <div class="cfa-submit-zone">
                        <button type="submit" name="submit_application" class="cfa-submit" id="submitBtn">
                            <i class="fas fa-paper-plane"></i>Submit Application
                        </button>
                        <p class="cfa-note">
                            <i class="fas fa-shield-alt mr-1"></i> Your quiz score (<?php echo $quiz_score; ?>%) will be shared with the employer.
                        </p>
                    </div>
                </div>

            </div>
        </form>
    </div>

    <div class="cfa-footer">
        <p class="mb-0">&copy; <?php echo date('Y'); ?> NovaHire. All rights reserved.</p>
    </div>
</div>

<script>
    (function() {
        // Animate quiz ring on load
        var ring = document.querySelector('.cfa-score-ring .rfg');
        if (ring) {
            var target = ring.getAttribute('stroke-dashoffset');
            ring.style.strokeDashoffset = ring.getAttribute('stroke-dasharray');
            requestAnimationFrame(function() {
                setTimeout(function() {
                    ring.style.strokeDashoffset = target;
                }, 150);
            });
        }

        // Cover letter character counter
        var cl = document.getElementById('coverLetter');
        var clCount = document.getElementById('clCount');
        if (cl && clCount) {
            cl.addEventListener('input', function() {
                clCount.textContent = cl.value.length;
                updateClSummary();
            });
        }

        // Animated CV preview collapse
        var wrap = document.getElementById('cvPreviewWrap');
        if (wrap) {
            var openHeight = wrap.scrollHeight;
            wrap.style.maxHeight = openHeight + 'px';
            wrap.style.transition = 'max-height .45s cubic-bezier(.4,0,.2,1)';

            window.toggleCvPreview = function() {
                var icon = document.getElementById('cvToggleIcon');
                var toggle = document.getElementById('cvToggle');
                if (wrap.style.maxHeight !== '0px') {
                    wrap.style.maxHeight = '0px';
                    icon.classList.remove('fa-chevron-up');
                    icon.classList.add('fa-chevron-down');
                    toggle.classList.add('collapsed');
                } else {
                    wrap.style.maxHeight = wrap.scrollHeight + 'px';
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                    toggle.classList.remove('collapsed');
                }
            };
        }

        // ═══ CV Selection Logic ═══
        window.selectCvType = function(type) {
            var cvTypeInput = document.getElementById('cvTypeInput');
            if (cvTypeInput) cvTypeInput.value = type;

            // Reset UI states
            document.querySelectorAll('.cfa-cv-option').forEach(el => el.classList.remove('active'));
            var uploadZone = document.getElementById('uploadZone');
            if (uploadZone) uploadZone.style.display = 'none';

            // Set active class
            var activeOpt = document.querySelector(`.cfa-cv-option[onclick="selectCvType('${type}')"]`);
            if (activeOpt) activeOpt.classList.add('active');

            // Handle Specific UI
            if (type === 'uploaded') {
                if (uploadZone) uploadZone.style.display = 'block';
                updateSummaryCv('Uploaded File', 'fa-file-upload');
            } else if (type === 'ai_customized') {
                updateSummaryCv('AI Customized', 'fa-wand-magic-sparkles');
            } else {
                updateSummaryCv('Auto-Generated', 'fa-magic');
            }
        };

        // Summary Updates
        function updateSummaryCv(text, icon) {
            var sum = document.getElementById('summaryCvType');
            if (sum) sum.innerHTML = `<i class="fas ${icon}"></i> ${text}`;
        }
        function updateClSummary() {
            var cl = document.getElementById('coverLetter');
            var sum = document.getElementById('summaryCoverLetter');
            if (cl && sum) {
                if (cl.value.trim().length > 0) {
                    sum.innerHTML = `<i class="fas fa-check-circle" style="color:#10b981;"></i> Written`;
                } else {
                    sum.innerHTML = `<i class="fas fa-times-circle" style="color:#ef4444;"></i> Not written yet`;
                }
            }
        }
        updateClSummary(); // Run initially

        // ═══ File Upload Logic ═══
        var dropzone = document.getElementById('dropzone');
        var fileInput = document.getElementById('cvFileInput');
        var preview = document.getElementById('filePreview');
        var fileName = document.getElementById('fileName');
        var fileSize = document.getElementById('fileSize');

        if (dropzone && fileInput) {
            dropzone.addEventListener('click', () => fileInput.click());

            dropzone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropzone.classList.add('dragover');
            });
            dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
            dropzone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropzone.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    handleFileSelect();
                }
            });

            fileInput.addEventListener('change', handleFileSelect);
        }

        function handleFileSelect() {
            if (fileInput.files.length > 0) {
                var file = fileInput.files[0];
                var validTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                
                if (!validTypes.includes(file.type)) {
                    alert('Invalid file format. Please upload PDF, DOC, or DOCX.');
                    fileInput.value = '';
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    alert('File is too large. Maximum size is 5MB.');
                    fileInput.value = '';
                    return;
                }

                fileName.textContent = file.name;
                fileSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                dropzone.style.display = 'none';
                preview.style.display = 'flex';
                updateSummaryCv('Uploaded: ' + file.name, 'fa-file');
            }
        }

        window.removeFile = function() {
            if (fileInput) fileInput.value = '';
            if (preview) preview.style.display = 'none';
            if (dropzone) dropzone.style.display = 'block';
            updateSummaryCv('Uploaded File', 'fa-file-upload');
        };
        
        var removeBtn = document.getElementById('fileRemove');
        if (removeBtn) {
            removeBtn.addEventListener('click', window.removeFile);
        }

        // ═══ AI Cover Letter Logic ═══
        window.generateCoverLetter = function() {
            var btn = document.getElementById('aiGenBtn');
            var status = document.getElementById('aiStatus');
            var tone = document.getElementById('toneDropdown').value;
            var clTextarea = document.getElementById('coverLetter');
            var jobId = <?php echo (int)$job_id; ?>;

            btn.disabled = true;
            status.style.display = 'inline-flex';
            
            fetch('../api/generate_cover_letter_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ job_id: jobId, tone: tone })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    var generatedText = data.cover_letter || data.content || '';
                    clTextarea.value = generatedText;
                    var clCount = document.getElementById('clCount');
                    if(clCount) clCount.textContent = clTextarea.value.length;
                    updateClSummary();
                } else {
                    alert('Error generating cover letter: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('A network error occurred while generating the cover letter.');
            })
            .finally(() => {
                btn.disabled = false;
                status.style.display = 'none';
            });
        };

        // Side panel scrolling
        window.scrollToSection = function(id) {
            var el = document.getElementById(id);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        };
    })();

    document.getElementById('applicationForm').addEventListener('submit', function(e) {
        var cvType = document.getElementById('cvTypeInput').value;
        var fileInput = document.getElementById('cvFileInput');
        
        if (cvType === 'uploaded' && (!fileInput || fileInput.files.length === 0)) {
            var existingCv = document.getElementById('existingCvFileInput');
            if (!existingCv || existingCv.value === '') {
                alert('Please select a CV file to upload.');
                e.preventDefault();
                return;
            }
        }

        if (!confirm('Are you sure you want to submit your application?')) {
            e.preventDefault();
            return;
        }
        var btn = document.getElementById('submitBtn');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting...';
            setTimeout(function() {
                btn.disabled = true;
            }, 50);
        }
    });

    <?php if ($show_success_banner): ?>
        var sb = document.getElementById('successBanner');
        if (sb) {
            sb.classList.add('show');
            sb.style.display = 'block';
        }
        var formb = document.getElementById('cfaFormBody');
        if (formb) formb.style.display = 'none';
        
        // Automatically countdown and redirect to dashboard after 3 seconds
        var timeLeft = 3;
        var countdownEl = document.getElementById('redirectCountdown');
        var timer = setInterval(function() {
            timeLeft--;
            if (countdownEl) countdownEl.textContent = timeLeft;
            if (timeLeft <= 0) {
                clearInterval(timer);
                window.location.href = 'seeker_dashboard.php';
            }
        }, 1000);
    <?php endif; ?>
</script>
</body>
</html>
