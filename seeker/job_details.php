<?php
// Core setup: session, DB, BASE_URL, helpers
require_once __DIR__ . '/../includes/bootstrap.php';


require_once __DIR__ . '/../admin/dbcon.php';

// Get job ID
if (!isset($_GET['id'])) {
    header('location: browse_jobs.php');
    exit();
}

$job_id = intval($_GET['id']);
if ($job_id <= 0) {
    header('location: browse_jobs.php');
    exit();
}

// Check if user is logged in
$is_logged_in = isset($_SESSION['id']);
$user_id = $is_logged_in ? (int)$_SESSION['id'] : 0;
$has_applied = false;
$application_status = null;
$quiz_status = null;

// Fetch job details using prepared statement
$job_stmt = mysqli_prepare($con, "SELECT cj.*, c.company_name, c.industry, c.company_size, c.company_website AS website, c.description as company_desc, c.logo, c.is_verified, c.verification_status,
                   (SELECT COUNT(*) FROM company_job_questions WHERE job_id = cj.id) as quiz_count,
                   (SELECT SUM(time_limit) FROM company_job_questions WHERE job_id = cj.id) as quiz_total_time,
                   (SELECT MIN(time_limit) FROM company_job_questions WHERE job_id = cj.id) as min_q_time,
                   (SELECT MAX(time_limit) FROM company_job_questions WHERE job_id = cj.id) as max_q_time,
                   (SELECT COUNT(*) FROM job_applications WHERE job_id = cj.id) as applicant_count
                   FROM company_jobs cj
                   JOIN companies c ON cj.company_id = c.id
                   WHERE cj.id = ? AND cj.status = 'active'");
mysqli_stmt_bind_param($job_stmt, "i", $job_id);
mysqli_stmt_execute($job_stmt);
$job_result = mysqli_stmt_get_result($job_stmt);
mysqli_stmt_close($job_stmt);

if (mysqli_num_rows($job_result) == 0) {
    echo "<script>alert('Job not found or no longer available'); window.location.href='browse_jobs.php';</script>";
    exit();
}

$job = mysqli_fetch_assoc($job_result);

// AI match score for logged-in users
require_once __DIR__ . '/../ai/matching.php';
require_once __DIR__ . '/../ai/helpers.php';
$ai_match = null;
if ($is_logged_in) {
    $profile_stmt = mysqli_prepare($con, "SELECT * FROM user_info WHERE id = ?");
    mysqli_stmt_bind_param($profile_stmt, "i", $user_id);
    mysqli_stmt_execute($profile_stmt);
    $profile_res = mysqli_stmt_get_result($profile_stmt);
    if ($profile_res && mysqli_num_rows($profile_res) > 0) {
        $ai_match = ai_match_profile_job(mysqli_fetch_assoc($profile_res), $job);
    }
    mysqli_stmt_close($profile_stmt);
}

$exhausted = false;
if ($is_logged_in) {
    // Application status
    $check_stmt = mysqli_prepare($con, "SELECT * FROM job_applications WHERE user_id = ? AND job_id = ?");
    mysqli_stmt_bind_param($check_stmt, "ii", $user_id, $job_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    if (mysqli_num_rows($check_result) > 0) {
        $has_applied = true;
        $app_data = mysqli_fetch_assoc($check_result);
        $application_status = $app_data['application_status'];
    }
    mysqli_stmt_close($check_stmt);

    // Always check actual quiz score from job_quiz_attempts
    $quiz_stmt = mysqli_prepare($con, "SELECT score_percentage FROM job_quiz_attempts WHERE user_id = ? AND job_id = ? ORDER BY attempt_date DESC LIMIT 1");
    mysqli_stmt_bind_param($quiz_stmt, "ii", $user_id, $job_id);
    mysqli_stmt_execute($quiz_stmt);
    $quiz_result = mysqli_stmt_get_result($quiz_stmt);
    if (mysqli_num_rows($quiz_result) > 0) {
        $quiz_row = mysqli_fetch_assoc($quiz_result);
        $quiz_status = ($quiz_row['score_percentage'] >= 60) ? 'passed' : 'failed';
    }
    mysqli_stmt_close($quiz_stmt);

    // Check if user has exhausted all attempts (2+ attempts, all failed)
    if ($quiz_status === 'failed') {
        $attempts_stmt = mysqli_prepare($con, "SELECT COUNT(*) as cnt FROM job_quiz_attempts WHERE user_id = ? AND job_id = ?");
        mysqli_stmt_bind_param($attempts_stmt, "ii", $user_id, $job_id);
        mysqli_stmt_execute($attempts_stmt);
        $attempt_count_row = mysqli_fetch_assoc(mysqli_stmt_get_result($attempts_stmt));
        $exhausted = (intval($attempt_count_row['cnt']) >= 2);
        mysqli_stmt_close($attempts_stmt);
    }
}

// Check if deadline has passed
$deadline_passed = strtotime($job['deadline']) < time();

// Quiz gating: force quiz if job has quiz and user hasn't passed it
$quiz_gating_active = false;
if ($is_logged_in && $job['quiz_count'] > 0 && $quiz_status !== 'passed') {
    $quiz_gating_active = true;
}

// Check if job is saved by user
$is_saved = false;
if ($is_logged_in) {
    $save_check = mysqli_query($con, "SELECT id FROM saved_jobs WHERE user_id = '$user_id' AND job_id = '$job_id'");
    $is_saved = mysqli_num_rows($save_check) > 0;
}
$save_count = 0;
$save_count_result = mysqli_query($con, "SELECT COUNT(*) as cnt FROM saved_jobs WHERE job_id = '$job_id'");
if ($save_count_result) {
    $save_count = mysqli_fetch_assoc($save_count_result)['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title><?php echo htmlspecialchars($job['job_title']); ?> | NovaHire</title>
    <?php require_once __DIR__ . '/../includes/links.php'; ?>
    <?php if ($quiz_gating_active): ?>
        <script>
            (function() {
                var saved = localStorage.getItem('theme') || localStorage.getItem('company-theme');
                if (!saved && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    saved = 'dark';
                }
                if (saved) {
                    document.documentElement.setAttribute('data-theme', saved);
                    if (document.body) {
                        document.body.setAttribute('data-theme', saved);
                        if (saved === 'dark') document.body.classList.add('dark-theme');
                    }
                }
            })();
        </script>
        <style>
            html,
            body {
                height: 100%;
                height: 100dvh;
                margin: 0;
                padding: 0;
                overflow: hidden;
                width: 100%;
            }

            body {
                min-height: 100%;
                height: 100vh;
                height: 100dvh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: radial-gradient(circle at 15% 15%, rgba(124, 58, 237, 0.28) 0%, transparent 45%),
                    radial-gradient(circle at 85% 85%, rgba(59, 130, 246, 0.24) 0%, transparent 45%),
                    linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #6366f1 100%);
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                color: #1e293b;
                padding: 0;
                margin: 0;
                box-sizing: border-box;
                overflow: hidden;
            }

            /* Dark Mode Background */
            [data-theme="dark"] body,
            body.dark-theme,
            body[data-theme="dark"] {
                background: radial-gradient(circle at 15% 15%, rgba(99, 102, 241, 0.18) 0%, transparent 50%),
                    radial-gradient(circle at 85% 85%, rgba(14, 165, 233, 0.14) 0%, transparent 50%),
                    linear-gradient(135deg, #090d16 0%, #0f172a 50%, #0a0f1d 100%) !important;
                color: #f8fafc;
            }

            /* Glassmorphic Fullscreen Card - Max 600px Width & 90% Height */
            .quiz-gate-card {
                width: 100%;
                max-width: 600px;
                height: 90%;
                height: 90dvh;
                max-height: 90dvh;
                background: rgba(255, 255, 255, 0.85);
                backdrop-filter: blur(24px) saturate(180%);
                -webkit-backdrop-filter: blur(24px) saturate(180%);
                border: 1.5px solid rgba(255, 255, 255, 0.8);
                border-radius: 28px;
                padding: clamp(16px, 2.5vh, 32px) clamp(18px, 3.5vw, 36px);
                box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.6) inset;
                text-align: center;
                display: flex;
                flex-direction: column;
                +
                align-items: center;
                justify-content: space-evenly;
                box-sizing: border-box;
                transition: all 0.3s ease;
                overflow-y: auto;
                scrollbar-width: none;
            }

            .quiz-gate-card::-webkit-scrollbar {
                display: none;
            }

            [data-theme="dark"] .quiz-gate-card,
            .dark-theme .quiz-gate-card {
                background: rgba(15, 23, 42, 0.82) !important;
                border-color: rgba(255, 255, 255, 0.12) !important;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7), 0 0 35px rgba(99, 102, 241, 0.14), inset 0 1px 1px rgba(255, 255, 255, 0.08) !important;
                color: #f8fafc !important;
            }

            .gate-header {
                display: flex;
                flex-direction: column;
                align-items: center;
                width: 100%;
            }

            .gate-body {
                display: flex;
                flex-direction: column;
                align-items: center;
                width: 100%;
            }

            .gate-footer {
                display: flex;
                flex-direction: column;
                align-items: center;
                width: 100%;
            }

            /* Icon Badge */
            .icon-lock-badge {
                width: clamp(54px, 7.5vh, 70px);
                height: clamp(54px, 7.5vh, 70px);
                border-radius: 50%;
                background: linear-gradient(135deg, rgba(245, 158, 11, 0.18), rgba(245, 158, 11, 0.08));
                border: 1.5px solid rgba(245, 158, 11, 0.35);
                color: #d97706;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: clamp(26px, 3.4vh, 32px);
                margin-bottom: clamp(6px, 1vh, 10px);
                box-shadow: 0 8px 20px -4px rgba(245, 158, 11, 0.25);
                animation: badge-pulse 2.5s infinite;
            }

            .icon-lock-badge.danger {
                background: linear-gradient(135deg, rgba(239, 68, 68, 0.18), rgba(239, 68, 68, 0.08));
                border-color: rgba(239, 68, 68, 0.35);
                color: #ef4444;
                box-shadow: 0 8px 20px -4px rgba(239, 68, 68, 0.25);
                animation: none;
            }

            @keyframes badge-pulse {

                0%,
                100% {
                    transform: scale(1);
                    box-shadow: 0 8px 20px -4px rgba(245, 158, 11, 0.25);
                }

                50% {
                    transform: scale(1.05);
                    box-shadow: 0 12px 26px 0 rgba(245, 158, 11, 0.45);
                }
            }

            /* Titles and Text */
            .quiz-gate-card .job-title {
                font-size: clamp(22px, 3.2vh, 30px);
                font-weight: 800;
                color: #0f172a;
                margin-bottom: 5px;
                line-height: 1.25;
                letter-spacing: -0.4px;
            }

            [data-theme="dark"] .quiz-gate-card .job-title,
            .dark-theme .quiz-gate-card .job-title {
                color: #f8fafc !important;
            }

            .quiz-gate-card .company-name {
                color: #6366f1;
                font-weight: 600;
                font-size: clamp(14.5px, 2vh, 17px);
                margin-bottom: 0;
                display: inline-flex;
                align-items: center;
                gap: 7px;
            }

            [data-theme="dark"] .quiz-gate-card .company-name,
            .dark-theme .quiz-gate-card .company-name {
                color: #a5b4fc !important;
            }

            .quiz-gate-card h3 {
                font-size: clamp(19px, 2.5vh, 23px);
                font-weight: 700;
                color: #1e293b;
                margin-bottom: 5px;
            }

            [data-theme="dark"] .quiz-gate-card h3,
            .dark-theme .quiz-gate-card h3 {
                color: #f1f5f9 !important;
            }

            .quiz-gate-card p.gate-subtitle {
                color: #64748b;
                font-size: clamp(13px, 1.65vh, 15px);
                line-height: 1.5;
                margin-bottom: clamp(6px, 1vh, 12px);
                max-width: 520px;
            }

            [data-theme="dark"] .quiz-gate-card p.gate-subtitle,
            .dark-theme .quiz-gate-card p.gate-subtitle {
                color: #94a3b8 !important;
            }

            /* Compact Info Box (Steps) */
            .quiz-gate-card .info-box {
                background: rgba(254, 243, 199, 0.45);
                border: 1px solid rgba(245, 158, 11, 0.35);
                border-radius: 20px;
                padding: clamp(12px, 1.8vh, 18px) clamp(16px, 2.5vw, 22px);
                margin-bottom: 10px;
                text-align: left;
                width: 100%;
                box-sizing: border-box;
                backdrop-filter: blur(8px);
            }

            [data-theme="dark"] .quiz-gate-card .info-box,
            .dark-theme .quiz-gate-card .info-box {
                background: rgba(245, 158, 11, 0.08) !important;
                border-color: rgba(245, 158, 11, 0.25) !important;
            }

            .info-box-header {
                font-size: clamp(12.5px, 1.6vh, 14px);
                font-weight: 800;
                color: #b45309;
                margin-bottom: clamp(8px, 1.2vh, 12px);
                display: flex;
                align-items: center;
                gap: 7px;
                text-transform: uppercase;
                letter-spacing: 0.6px;
            }

            [data-theme="dark"] .info-box-header,
            .dark-theme .info-box-header {
                color: #fcd34d !important;
            }

            .step-list {
                display: flex;
                flex-direction: column;
                gap: clamp(6px, 1vh, 9px);
                margin: 0;
                padding: 0;
                list-style: none;
            }

            .step-item {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: clamp(13px, 1.6vh, 14.5px);
                color: #78350f;
                line-height: 1.35;
            }

            [data-theme="dark"] .step-item,
            .dark-theme .step-item {
                color: #fde68a !important;
            }

            .step-num {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 22px;
                height: 22px;
                border-radius: 50%;
                background: rgba(245, 158, 11, 0.25);
                color: #92400e;
                font-weight: 800;
                font-size: 11.5px;
                flex-shrink: 0;
            }

            [data-theme="dark"] .step-num,
            .dark-theme .step-num {
                background: rgba(245, 158, 11, 0.3) !important;
                color: #fef3c7 !important;
            }

            /* Action Buttons */
            .gate-actions {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                margin: clamp(4px, 1vh, 10px) 0;
                width: 100%;
                flex-wrap: wrap;
            }

            .btn-quiz {
                background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
                color: white !important;
                padding: clamp(11px, 1.6vh, 14px) clamp(22px, 3vw, 30px);
                border-radius: 50px;
                border: none;
                font-size: clamp(14px, 1.75vh, 15.5px);
                font-weight: 700;
                cursor: pointer;
                transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: 0 8px 24px -4px rgba(99, 102, 241, 0.45);
                text-decoration: none !important;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                margin: 4px 0;
            }

            .btn-quiz:hover {
                transform: translateY(-2px);
                box-shadow: 0 12px 28px -4px rgba(99, 102, 241, 0.6);
            }

            .btn-outline {
                background: rgba(255, 255, 255, 0.7);
                color: #4f46e5 !important;
                padding: clamp(11px, 1.6vh, 14px) clamp(22px, 3vw, 30px);
                border-radius: 50px;
                border: 1.5px solid rgba(99, 102, 241, 0.35);
                font-size: clamp(14px, 1.75vh, 15.5px);
                font-weight: 600;
                cursor: pointer;
                transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
                text-decoration: none !important;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                backdrop-filter: blur(8px);
                margin: 4px 0;
            }

            .btn-outline:hover {
                background: rgba(99, 102, 241, 0.1);
                border-color: #4f46e5;
                transform: translateY(-2px);
            }

            [data-theme="dark"] .btn-outline,
            .dark-theme .btn-outline {
                background: rgba(30, 41, 59, 0.6) !important;
                color: #a5b4fc !important;
                border-color: rgba(165, 180, 252, 0.3) !important;
            }

            [data-theme="dark"] .btn-outline:hover,
            .dark-theme .btn-outline:hover {
                background: rgba(99, 102, 241, 0.2) !important;
                border-color: #818cf8 !important;
                color: #ffffff !important;
            }

            /* Time Limit Badge */
            .time-limit-badge {
                margin-top: clamp(6px, 1vh, 10px);
                margin-bottom: 0;
                display: inline-flex;
                align-items: center;
                gap: 7px;
                font-size: clamp(11.5px, 1.45vh, 13px);
                font-weight: 600;
                color: #64748b;
                padding: 4px 16px;
                border-radius: 20px;
                background: rgba(100, 116, 139, 0.08);
                border: 1px solid rgba(100, 116, 139, 0.15);
            }

            [data-theme="dark"] .time-limit-badge,
            .dark-theme .time-limit-badge {
                color: #94a3b8 !important;
                background: rgba(255, 255, 255, 0.05) !important;
                border-color: rgba(255, 255, 255, 0.1) !important;
            }
        </style>
</head>

<body>
    <div class="quiz-gate-card">
        <?php if ($is_logged_in && $quiz_status === 'failed' && $exhausted): ?>
            <div class="gate-header">
                <div class="icon-lock-badge danger">
                    <i class="fas fa-ban"></i>
                </div>
                <div class="job-title"><?php echo htmlspecialchars($job['job_title']); ?></div>
                <div class="company-name">
                    <i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($job['company_name']); ?>
                    <?php if (!empty($job['is_verified']) || ($job['verification_status'] ?? '') === 'verified'): ?>
                        <span class="badge badge-success ml-1" style="background:#059669;font-size:0.75rem;"><i class="fas fa-check-circle"></i> Verified</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gate-body">
                <h3 style="color: #ef4444;">Assessment Attempts Exhausted</h3>
                <p class="gate-subtitle">You have used all available assessment attempts for this position. You can no longer apply for this job.</p>
            </div>

            <div class="gate-footer">
                <div class="gate-actions">
                    <a href="browse_jobs.php" class="btn-quiz" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); box-shadow: 0 8px 24px -4px rgba(239, 68, 68, 0.45);">
                        <i class="fas fa-search mr-2"></i>Browse Other Jobs
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="gate-header">
                <div class="icon-lock-badge">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="job-title"><?php echo htmlspecialchars($job['job_title']); ?></div>
                <div class="company-name">
                    <i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($job['company_name']); ?>
                    <?php if (!empty($job['is_verified']) || ($job['verification_status'] ?? '') === 'verified'): ?>
                        <span class="badge badge-success ml-1" style="background:#059669;font-size:0.75rem;"><i class="fas fa-check-circle"></i> Verified</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gate-body">
                <h3>Assessment Required</h3>
                <p class="gate-subtitle">This position requires passing a timed assessment before you can view full job details and apply.</p>

                <div class="info-box">
                    <div class="info-box-header">
                        <i class="fas fa-bolt"></i> Assessment Flow
                    </div>
                    <ul class="step-list">
                        <li class="step-item">
                            <span class="step-num">1</span>
                            <span>Complete timed quiz (<?php echo intval($job['quiz_count']); ?> questions)</span>
                        </li>
                        <li class="step-item">
                            <span class="step-num">2</span>
                            <span>Score <strong>60% or higher</strong> to pass</span>
                        </li>
                        <li class="step-item">
                            <span class="step-num">3</span>
                            <span>If you fail, complete video grooming session</span>
                        </li>
                        <li class="step-item">
                            <span class="step-num">4</span>
                            <span>You get <strong>ONE final retake</strong> after grooming</span>
                        </li>
                        <li class="step-item">
                            <span class="step-num">5</span>
                            <span>Once passed, instantly apply with your CV</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="gate-footer">
                <div class="gate-actions">
                    <a href="company_job_quiz.php?job_id=<?php echo $job_id; ?>" class="btn-quiz">
                        <i class="fas fa-play mr-2"></i>Start Quiz
                    </a>
                    <a href="browse_jobs.php" class="btn-outline">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Jobs
                    </a>
                </div>

                <?php
                $quiz_time_limit_str = 'No limit';
                $quiz_total_sec = !empty($job['quiz_total_time']) ? intval($job['quiz_total_time']) : 0;
                $min_q_sec = !empty($job['min_q_time']) ? intval($job['min_q_time']) : 0;
                $max_q_sec = !empty($job['max_q_time']) ? intval($job['max_q_time']) : 0;

                if ($quiz_total_sec > 0) {
                    $mins = floor($quiz_total_sec / 60);
                    $secs = $quiz_total_sec % 60;
                    $total_str = ($mins > 0 && $secs > 0) ? "$mins min $secs sec" : (($mins > 0) ? $mins . ' ' . ($mins == 1 ? 'minute' : 'minutes') : "$secs seconds");

                    if ($min_q_sec > 0 && $min_q_sec === $max_q_sec) {
                        $q_mins = floor($min_q_sec / 60);
                        $q_secs = $min_q_sec % 60;
                        $q_str = ($q_mins > 0 && $q_secs > 0) ? "$q_mins min $q_secs sec" : (($q_mins > 0) ? "$q_mins " . ($q_mins == 1 ? 'min' : 'mins') : "$q_secs sec");
                        $quiz_time_limit_str = $total_str . " ($q_str per question)";
                    } elseif ($min_q_sec > 0 && $max_q_sec > 0) {
                        $quiz_time_limit_str = $total_str . " (variable per question)";
                    } else {
                        $quiz_time_limit_str = $total_str;
                    }
                } elseif (isset($job['quiz_timer']) && intval($job['quiz_timer']) > 0) {
                    $timer_val = intval($job['quiz_timer']);
                    $mins = max(1, round($timer_val / 60));
                    $quiz_time_limit_str = $mins . ' ' . ($mins == 1 ? 'minute' : 'minutes');
                }
                ?>
                <div class="time-limit-badge">
                    <i class="fas fa-clock"></i> Time limit: <?php echo htmlspecialchars($quiz_time_limit_str); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>
<?php exit();
    endif; ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title><?php echo htmlspecialchars($job['job_title']); ?> | NovaHire</title>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .job-details-container {
            max-width: 1200px;
            margin: 100px auto 50px;
            padding: 0 20px;
        }

        .job-header {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .job-title {
            font-size: 32px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .company-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 20px 0;
        }

        .company-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            font-weight: 600;
        }

        .job-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: #f7fafc;
            border-radius: 12px;
        }

        .meta-item i {
            color: #667eea;
            font-size: 20px;
        }

        .meta-label {
            font-size: 12px;
            color: #718096;
            display: block;
        }

        .meta-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            display: block;
        }

        .content-section {
            background: #8180a9;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .ai-match-panel {
            background: linear-gradient(135deg, #f8f7ff 0%, #eef2ff 100%);
            border: 1px solid #e0e7ff;
            border-radius: 20px;
            padding: 28px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(14, 165, 233, 0.08);
        }

        .ai-match-label {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0ea5e9;
        }

        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #667eea;
        }

        .job-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0;
        }

        .job-tag {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 18px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
        }

        .requirements-list {
            list-style: none;
            padding: 0;
        }

        .requirements-list li {
            padding: 12px 0;
            padding-left: 35px;
            position: relative;
            border-bottom: 1px solid #e2e8f0;
        }

        .requirements-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            top: 12px;
            width: 25px;
            height: 25px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .apply-section {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .apply-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 18px 50px;
            border-radius: 50px;
            border: none;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            text-decoration: none;
            display: inline-block;
        }

        .apply-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .apply-btn:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            box-shadow: none;
        }

        .apply-btn:disabled:hover {
            transform: none;
        }

        .quiz-info {
            background: #fff5f5;
            border: 2px dashed #fc8181;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }

        .quiz-info i {
            font-size: 40px;
            color: #fc8181;
            margin-bottom: 10px;
        }

        .status-badge {
            display: inline-block;
            padding: 10px 25px;
            border-radius: 25px;
            font-weight: 600;
            margin: 20px 0;
        }

        .status-pending {
            background: #fef5e7;
            color: #f39c12;
        }

        .status-reviewed {
            background: #ebf5fb;
            color: #3498db;
        }

        .status-shortlisted {
            background: #e8f8f5;
            color: #1abc9c;
        }

        .status-rejected {
            background: #fadbd8;
            color: #e74c3c;
        }

        .company-section {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
            align-items: start;
        }

        .company-card {
            background: #f7fafc;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
        }

        .company-card h3 {
            color: #2d3748;
            margin: 15px 0 10px;
        }

        .company-details p {
            margin: 10px 0;
            color: #4a5568;
        }

        @media (max-width: 768px) {
            .job-meta-grid {
                grid-template-columns: 1fr;
            }

            .company-section {
                grid-template-columns: 1fr;
            }
        }

        .save-btn {
            background: none;
            border: 2px solid #cbd5e0;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: #cbd5e0;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .save-btn:hover {
            border-color: #e53e3e;
            color: #e53e3e;
            transform: scale(1.1);
        }

        .save-btn.saved {
            border-color: #e53e3e;
            background: #e53e3e;
            color: white;
        }

        .save-btn.saved:hover {
            background: #c53030;
            border-color: #c53030;
        }

        .save-count {
            position: absolute;
            top: -6px;
            right: -6px;
            background: #667eea;
            color: white;
            font-size: 0.65rem;
            font-weight: 700;
            min-width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }
    </style>
</head>

<body>
    <nav style="position:sticky;top:0;z-index:1030;background:#fff;border-bottom:1px solid #e0e0e0;padding:0 20px">
        <div style="max-width:1080px;margin:0 auto;display:flex;align-items:center;height:56px;gap:16px">
            <a href="browse_jobs.php" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;color:#1e293b;font-weight:700;font-size:.9rem">
                <i class="fas fa-arrow-left"></i> Browse Jobs
            </a>
            <span style="color:#94a3b8;font-size:.8rem"><i class="fas fa-chevron-right"></i></span>
            <span style="font-weight:600;color:#1e293b;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:500px"><?php echo htmlspecialchars($job['job_title']); ?></span>
        </div>
    </nav>
    <div class="job-details-container">
        <!-- Job Header -->
        <div class="job-header">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <?php if (!empty($job['logo']) && file_exists($job['logo'])): ?>
                        <div class="mb-3">
                            <img src="<?php echo $job['logo']; ?>" alt="<?php echo $job['company_name']; ?>"
                                style="max-width: 150px; max-height: 60px; object-fit: contain; border-radius: 8px; border: 1px solid #e0e0e0; padding: 8px; background: white;">
                        </div>
                    <?php endif; ?>
                    <h1 class="job-title"><?php echo htmlspecialchars($job['job_title']); ?></h1>
                    <div class="company-info">
                        <span class="company-badge"><?php echo htmlspecialchars($job['company_name']); ?></span>
                        <?php if (!empty($job['is_verified']) || ($job['verification_status'] ?? '') === 'verified'): ?>
                            <span class="badge" style="background:rgba(5,150,105,0.12);color:#059669;font-weight:700;font-size:0.84rem;padding:6px 14px;border-radius:999px;display:inline-flex;align-items:center;gap:5px;border:1px solid rgba(5,150,105,0.25);" title="Verified Business Entity">
                                <i class="fas fa-certificate text-warning"></i> Verified Company
                            </span>
                        <?php endif; ?>
                        <span class="badge badge-secondary"><?php echo htmlspecialchars($job['industry']); ?></span>
                        <span class="badge badge-info"><?php echo htmlspecialchars($job['job_category']); ?></span>
                    </div>
                </div>
                <div>
                    <?php if ($has_applied): ?>
                        <span class="status-badge status-<?php echo $application_status; ?>">
                            <i class="fas fa-check-circle mr-2"></i>Applied - <?php echo ucfirst($application_status); ?>
                        </span>
                    <?php elseif ($deadline_passed): ?>
                        <span class="status-badge status-rejected">
                            <i class="fas fa-times-circle mr-2"></i>Deadline Passed
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="job-meta-grid">
                <div class="meta-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <div>
                        <span class="meta-label">Location</span>
                        <span class="meta-value"><?php echo htmlspecialchars($job['location']); ?></span>
                    </div>
                </div>

                <div class="meta-item">
                    <i class="fas fa-briefcase"></i>
                    <div>
                        <span class="meta-label">Job Type</span>
                        <span class="meta-value"><?php echo htmlspecialchars($job['employment_type']); ?></span>
                    </div>
                </div>

                <div class="meta-item">
                    <i class="fas fa-clock"></i>
                    <div>
                        <span class="meta-label">Experience</span>
                        <span class="meta-value"><?php echo htmlspecialchars($job['experience_required']); ?></span>
                    </div>
                </div>

                <?php if ($job['salary_range']): ?>
                    <div class="meta-item">
                        <i class="fas fa-dollar-sign"></i>
                        <div>
                            <span class="meta-label">Salary</span>
                            <span class="meta-value"><?php echo htmlspecialchars($job['salary_range']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="meta-item">
                    <i class="fas fa-calendar"></i>
                    <div>
                        <span class="meta-label">Posted On</span>
                        <span class="meta-value"><?php echo date('M d, Y', strtotime($job['posted_date'])); ?></span>
                    </div>
                </div>

                <div class="meta-item">
                    <i class="fas fa-calendar-times"></i>
                    <div>
                        <span class="meta-label">Deadline</span>
                        <span class="meta-value"><?php echo date('M d, Y', strtotime($job['deadline'])); ?></span>
                    </div>
                </div>

                <div class="meta-item">
                    <i class="fas fa-users"></i>
                    <div>
                        <span class="meta-label">Applicants</span>
                        <span class="meta-value"><?php echo $job['applicant_count']; ?></span>
                    </div>
                </div>

                <div class="meta-item">
                    <i class="fas fa-users-cog"></i>
                    <div>
                        <span class="meta-label">Vacancies</span>
                        <span class="meta-value"><?php echo $job['vacancy_count']; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($ai_match): ?>
            <!-- AI Match Panel -->
            <div class="ai-match-panel">
                <div class="d-flex align-items-center justify-content-between flex-wrap">
                    <div class="d-flex align-items-center">
                        <?php ai_score_ring($ai_match['score'], 'AI Match', 64); ?>
                        <div class="ml-3">
                            <div class="ai-match-label">AI Compatibility</div>
                            <div style="font-weight:600; color:#1e293b; font-size:0.92rem;"><?php echo $ai_match['label']; ?></div>
                            <div style="font-size:0.78rem; color:#64748b;">Based on your profile skills, experience & education</div>
                        </div>
                    </div>
                    <a href="ai_resume_analyzer.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-file-lines mr-1"></i>Improve Score
                    </a>
                </div>

                <?php if (!empty($ai_match['explanation_points'])): ?>
                    <div class="mt-3">
                        <div style="font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; margin-bottom:6px;">Analysis</div>
                        <ul style="font-size:0.85rem; color:#475569; padding-left:20px;">
                            <?php foreach ($ai_match['explanation_points'] as $pt): ?>
                                <li><?php echo htmlspecialchars($pt); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($ai_match['matched_skills'])): ?>
                    <div class="mt-3">
                        <div style="font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; margin-bottom:6px;">Skills You Match</div>
                        <div class="job-tags">
                            <?php foreach ($ai_match['matched_skills'] as $ms): ?>
                                <span class="badge badge-success" style="background:#ecfdf5; color:#059669; border:1px solid #d1fae5;"><?php echo htmlspecialchars($ms); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($ai_match['missing_required']) || !empty($ai_match['missing_preferred'])): ?>
                    <div class="mt-2">
                        <div style="font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; margin-bottom:6px;">Skills To Add</div>
                        <div class="job-tags">
                            <?php if (!empty($ai_match['missing_required'])): ?>
                                <?php foreach (array_slice($ai_match['missing_required'], 0, 6) as $ms): ?>
                                    <span class="badge badge-danger" style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;" title="Required Skill"><?php echo htmlspecialchars($ms); ?> <i class="fas fa-star" style="font-size: 0.6rem;"></i></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if (!empty($ai_match['missing_preferred'])): ?>
                                <?php foreach (array_slice($ai_match['missing_preferred'], 0, 6) as $ms): ?>
                                    <span class="badge badge-warning" style="background:#fffbeb; color:#b45309; border:1px solid #fde68a;" title="Preferred Skill"><?php echo htmlspecialchars($ms); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        if ($is_logged_in) {
            $req_sql = "SELECT s.id, s.name FROM job_required_skills jrs JOIN skills s ON jrs.skill_id = s.id WHERE jrs.job_id = ?";
            $req_stmt = mysqli_prepare($con, $req_sql);
            mysqli_stmt_bind_param($req_stmt, "i", $job_id);
            mysqli_stmt_execute($req_stmt);
            $req_res = mysqli_stmt_get_result($req_stmt);
            $missing_vs = [];
            while ($req_skill = mysqli_fetch_assoc($req_res)) {
                $vs_check_sql = "SELECT id FROM verified_skills WHERE user_id = ? AND skill_id = ?";
                $vs_check_stmt = mysqli_prepare($con, $vs_check_sql);
                mysqli_stmt_bind_param($vs_check_stmt, "ii", $user_id, $req_skill['id']);
                mysqli_stmt_execute($vs_check_stmt);
                if (mysqli_num_rows(mysqli_stmt_get_result($vs_check_stmt)) == 0) {
                    $missing_vs[] = $req_skill;
                }
            }
            if (!empty($missing_vs)):
        ?>
                <div class="content-section" style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 20px; margin-top: 20px;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap">
                        <div>
                            <h4 style="color: #1e3a8a; margin-bottom: 5px;"><i class="fas fa-medal"></i> Boost Your Chances!</h4>
                            <p style="color: #1e40af; font-size: 0.9rem; margin-bottom: 0;">This job requires skills you haven't verified. Take an assessment to earn a badge and get priority on the employer's shortlist.</p>
                            <div class="mt-2">
                                <?php foreach ($missing_vs as $vs): ?>
                                    <span class="badge badge-primary" style="background: #3b82f6;"><?php echo htmlspecialchars($vs['name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <a href="skills.php" class="btn btn-primary mt-2 mt-md-0">Get Verified</a>
                    </div>
                </div>
        <?php
            endif;
        }
        ?>

        <!-- Job Description -->
        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-file-alt mr-2"></i>Job Description</h2>
            <p style="line-height: 1.8; color: #4a5568;"><?php echo nl2br(htmlspecialchars($job['job_description'])); ?></p>
        </div>

        <!-- Responsibilities -->
        <?php if ($job['responsibilities']): ?>
            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-tasks mr-2"></i>Key Responsibilities</h2>
                <ul class="requirements-list">
                    <?php
                    $responsibilities = explode("\n", $job['responsibilities']);
                    foreach ($responsibilities as $resp) {
                        if (trim($resp)) {
                            echo '<li>' . htmlspecialchars(trim($resp)) . '</li>';
                        }
                    }
                    ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Requirements -->
        <?php if ($job['requirements']): ?>
            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-check-circle mr-2"></i>Requirements</h2>
                <ul class="requirements-list">
                    <?php
                    $requirements = explode("\n", $job['requirements']);
                    foreach ($requirements as $req) {
                        if (trim($req)) {
                            echo '<li>' . htmlspecialchars(trim($req)) . '</li>';
                        }
                    }
                    ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Skills Required -->
        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-code mr-2"></i>Required Skills</h2>
            <div class="job-tags">
                <?php
                $skills = explode(',', $job['skills_required']);
                foreach ($skills as $skill) {
                    echo '<span class="job-tag">' . htmlspecialchars(trim($skill)) . '</span>';
                }
                ?>
            </div>
        </div>

        <!-- Company Information -->
        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-building mr-2"></i>About <?php echo htmlspecialchars($job['company_name']); ?></h2>
            <div class="company-section">
                <div class="company-card">
                    <?php if (!empty($job['logo']) && file_exists($job['logo'])): ?>
                        <img src="<?php echo $job['logo']; ?>" alt="<?php echo $job['company_name']; ?>"
                            style="max-width: 120px; max-height: 80px; object-fit: contain; margin-bottom: 15px; border-radius: 8px; border: 1px solid #e0e0e0; padding: 10px; background: white;">
                    <?php else: ?>
                        <i class="fas fa-building" style="font-size: 50px; color: #667eea;"></i>
                    <?php endif; ?>
                    <h3>
                        <?php echo htmlspecialchars($job['company_name']); ?>
                        <?php if (!empty($job['is_verified']) || ($job['verification_status'] ?? '') === 'verified'): ?>
                            <i class="fas fa-certificate text-warning" title="Verified Business" style="font-size:1.1rem;margin-left:6px;"></i>
                        <?php endif; ?>
                    </h3>
                    <p><i class="fas fa-industry mr-2"></i><?php echo htmlspecialchars($job['industry']); ?></p>
                    <p><i class="fas fa-users mr-2"></i><?php echo htmlspecialchars($job['company_size']); ?> Employees</p>
                    <?php if ($job['website']): ?>
                        <a href="<?php echo htmlspecialchars($job['website']); ?>" target="_blank" class="btn btn-sm btn-outline-primary mt-2">
                            <i class="fas fa-globe mr-2"></i>Visit Website
                        </a>
                    <?php endif; ?>
                </div>
                <div class="company-details">
                    <p style="line-height: 1.8; color: #4a5568;"><?php echo nl2br(htmlspecialchars($job['company_desc'])); ?></p>
                </div>
            </div>
        </div>

        <!-- Apply Section -->
        <div class="apply-section">
            <?php if ($is_logged_in): ?>
                <div class="mb-3">
                    <button class="save-btn <?php echo $is_saved ? 'saved' : ''; ?>" id="saveJobBtn" onclick="toggleSaveJob()" title="<?php echo $is_saved ? 'Unsave job' : 'Save job'; ?>">
                        <i class="fas fa-heart" id="saveIcon"></i>
                        <span class="save-count" id="saveCount"><?php echo $save_count; ?></span>
                    </button>
                    <p class="mt-2 text-muted" style="font-size: 0.85rem;" id="saveLabel"><?php echo $is_saved ? 'Saved' : 'Save this job'; ?></p>
                </div>
            <?php endif; ?>
            <?php if (!$is_logged_in): ?>
                <i class="fas fa-user-lock" style="font-size: 50px; color: #cbd5e0; margin-bottom: 20px;"></i>
                <h3 style="color: #2d3748; margin-bottom: 15px;">Login Required</h3>
                <p style="color: #718096; margin-bottom: 30px;">You need to login to continue</p>
                <a href="<?php echo BASE_URL; ?>/auth/login.php?redirect=<?php echo BASE_URL; ?>/seeker/job_details.php?id=<?php echo $job_id; ?>" class="apply-btn">
                    <i class="fas fa-sign-in-alt mr-2"></i>Login
                </a>
                <p class="mt-3">
                    Don't have an account? <a href="<?php echo BASE_URL; ?>/auth/registration.php" style="color: #667eea; font-weight: 600;">Register here</a>
                </p>
            <?php elseif ($has_applied): ?>
                <i class="fas fa-check-circle" style="font-size: 50px; color: #48bb78; margin-bottom: 20px;"></i>
                <h3 style="color: #2d3748; margin-bottom: 15px;">Application Submitted</h3>
                <p style="color: #718096; margin-bottom: 20px;">You have already applied for this position</p>
                <p style="color: #4a5568;"><strong>Status:</strong> <span class="status-badge status-<?php echo $application_status; ?>"><?php echo ucfirst($application_status); ?></span></p>
                <a href="my_application.php" class="btn btn-outline-primary mt-3">
                    <i class="fas fa-list mr-2"></i>View My Applications
                </a>
            <?php elseif ($deadline_passed): ?>
                <i class="fas fa-exclamation-circle" style="font-size: 50px; color: #f56565; margin-bottom: 20px;"></i>
                <h3 style="color: #2d3748; margin-bottom: 15px;">Application Deadline Passed</h3>
                <p style="color: #718096; margin-bottom: 30px;">Applications for this position are no longer being accepted</p>
                <a href="browse_jobs.php" class="btn btn-outline-primary">
                    <i class="fas fa-search mr-2"></i>Browse Other Jobs
                </a>
            <?php else: ?>
                <?php if ($job['quiz_count'] > 0): ?>
                    <div class="quiz-info">
                        <i class="fas fa-question-circle"></i>
                        <h4 style="color: #c53030; margin: 10px 0;">Assessment Required</h4>
                        <p style="color: #742a2a; margin: 0;">You must pass the assessment before applying and uploading your CV.</p>
                    </div>

                    <?php if ($quiz_status === 'passed'): ?>
                        <i class="fas fa-paper-plane" style="font-size: 50px; color: #667eea; margin-bottom: 20px;"></i>
                        <h3 style="color: #2d3748; margin-bottom: 15px;">Great! You passed.</h3>
                        <p style="color: #718096; margin-bottom: 30px;">You can now submit your application and upload your CV.</p>
                        <a href="company_job_application.php?job_id=<?php echo $job_id; ?>" class="apply-btn">
                            <i class="fas fa-paper-plane mr-2"></i>Apply Now
                        </a>
                    <?php elseif ($quiz_status === 'failed' && $exhausted): ?>
                        <i class="fas fa-ban" style="font-size: 50px; color: #e53e3e; margin-bottom: 20px;"></i>
                        <h3 style="color: #c53030; margin-bottom: 15px;">Assessment Attempts Exhausted</h3>
                        <p style="color: #742a2a; margin-bottom: 30px;">You have used all available assessment attempts for this position. You can no longer apply for this job.</p>
                        <a href="browse_jobs.php" class="btn btn-outline-primary">
                            <i class="fas fa-search mr-2"></i>Browse Other Jobs
                        </a>
                    <?php elseif ($quiz_status === 'failed'): ?>
                        <i class="fas fa-exclamation-triangle" style="font-size: 50px; color: #f6ad55; margin-bottom: 20px;"></i>
                        <h3 style="color: #c05621; margin-bottom: 15px;">Quiz not passed</h3>
                        <p style="color: #744210; margin-bottom: 30px;">Please complete the grooming session to unlock your final retake attempt.</p>
                        <a href="grooming.php?category=<?php echo urlencode($job['job_category']); ?>&job_id=<?php echo $job_id; ?>" class="btn btn-outline-light" style="border-color: #f6ad55; color: #f6ad55;">
                            <i class="fas fa-chalkboard-teacher mr-2"></i>Go to Grooming
                        </a>
                    <?php else: ?>
                        <i class="fas fa-clipboard-check" style="font-size: 50px; color: #4299e1; margin-bottom: 20px;"></i>
                        <h3 style="color: #2b6cb0; margin-bottom: 15px;">Take the Assessment</h3>
                        <p style="color: #2c5282; margin-bottom: 30px;">Complete the quiz to unlock the application form.</p>
                        <a href="company_job_quiz.php?job_id=<?php echo $job_id; ?>" class="apply-btn">
                            <i class="fas fa-play mr-2"></i>Start Quiz
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <i class="fas fa-paper-plane" style="font-size: 50px; color: #667eea; margin-bottom: 20px;"></i>
                    <h3 style="color: #2d3748; margin-bottom: 15px;">Ready to Apply?</h3>
                    <p style="color: #718096; margin-bottom: 30px;">Submit your application now and take the first step towards your new career</p>
                    <a href="company_job_application.php?job_id=<?php echo $job_id; ?>" class="apply-btn">
                        <i class="fas fa-paper-plane mr-2"></i>Apply Now
                    </a>
                    <p class="mt-3" style="color: #718096; font-size: 14px;">
                        <i class="fas fa-info-circle mr-2"></i>You will need to upload your CV.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <footer class="text-center py-4 mt-5" style="background: rgba(255,255,255,0.1); color: white;">
        <p class="mb-0">&copy; 2026 NovaHire. All rights reserved.</p>
    </footer>

    <script>
        function toggleSaveJob() {
            var btn = document.getElementById('saveJobBtn');
            var icon = document.getElementById('saveIcon');
            var countEl = document.getElementById('saveCount');
            var label = document.getElementById('saveLabel');

            fetch('api/toggle_save_job.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'job_id=<?php echo $job_id; ?>'
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        if (data.saved) {
                            btn.classList.add('saved');
                            label.textContent = 'Saved';
                            btn.title = 'Unsave job';
                        } else {
                            btn.classList.remove('saved');
                            label.textContent = 'Save this job';
                            btn.title = 'Save job';
                        }
                        countEl.textContent = data.count;

                        btn.style.transform = 'scale(1.3)';
                        setTimeout(() => {
                            btn.style.transform = 'scale(1)';
                        }, 200);

                        if (typeof showToast === 'function') {
                            showToast(data.saved ? 'success' : 'info', data.saved ? 'Job Saved' : 'Job Unsaved', data.saved ? 'Added to your saved jobs' : 'Removed from saved jobs');
                        }
                    }
                })
                .catch(err => {
                    console.error(err);
                    if (typeof showToast === 'function') {
                        showToast('error', 'Error', 'Could not update saved jobs');
                    }
                });
        }
    </script>
</body>

</html>