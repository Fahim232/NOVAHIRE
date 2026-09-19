<?php
/**
 * NovaHire — Secure Assessment Engine
 * seeker/company_job_quiz.php
 *
 * Server-controlled, one-question-at-a-time assessment with:
 *   - Randomized question order
 *   - Server-side time enforcement
 *   - Anti-cheating event tracking (tab switch, fullscreen exit, copy/paste)
 *   - MCQ + Short Answer question types
 *   - AI grading for short answers (via api/assessment_submit.php)
 *
 * The old bulk-form-POST flow is replaced by AJAX calls to:
 *   - api/assessment_submit.php  (answer submission + completion)
 *   - api/assessment_track.php   (anti-cheating events)
 */

// Core setup: session, DB, BASE_URL, helpers
require_once __DIR__ . '/../includes/bootstrap.php';
if (!isset($_SESSION['id'])) {
    header('location: ' . BASE_URL . '/auth/login.php');
    exit();
}
require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_GET['job_id'])) {
    header('location: browse_jobs.php');
    exit();
}

$job_id = intval($_GET['job_id']);
$user_id = intval($_SESSION['id']);

require_once __DIR__ . '/../includes/premium.php';
$quiz_access = nh_check_access($con, $user_id, 'job_apply');
if (!$quiz_access['allowed']) {
    require_once __DIR__ . '/../includes/header.php';
    nh_render_pro_gate('job_apply');
    exit;
}

// Fetch job data
$job_stmt = mysqli_prepare($con, "SELECT cj.*, c.company_name, c.industry
              FROM company_jobs cj
              JOIN companies c ON cj.company_id = c.id
              WHERE cj.id = ? AND cj.status = 'active'");
mysqli_stmt_bind_param($job_stmt, "i", $job_id);
mysqli_stmt_execute($job_stmt);
$job_result = mysqli_stmt_get_result($job_stmt);

if (mysqli_num_rows($job_result) == 0) {
    echo "<script>alert('Job not found'); window.location.href='browse_jobs.php';</script>";
    exit();
}

$job = mysqli_fetch_assoc($job_result);

$quiz_timer = 300;
if (isset($job['quiz_timer']) && intval($job['quiz_timer']) > 0) {
    $quiz_timer = max(60, intval($job['quiz_timer']));
}

// ── SESSION-LEVEL LOCK: prevent retake ──
$quiz_session_key = 'quiz_taken_' . $job_id;

// Check grooming status (allows retake)
$grooming_completed = false;
$grm_stmt = mysqli_prepare($con, "SELECT grooming_completed FROM user_quiz_status WHERE user_id=? AND category=?");
$job_cat = $job['job_category'];
mysqli_stmt_bind_param($grm_stmt, "is", $user_id, $job_cat);
mysqli_stmt_execute($grm_stmt);
$grm_result = mysqli_stmt_get_result($grm_stmt);
if (mysqli_num_rows($grm_result) > 0) {
    $grm_row = mysqli_fetch_assoc($grm_result);
    $grooming_completed = ($grm_row['grooming_completed'] == 1);
}

// Count total quiz attempts
$att_stmt = mysqli_prepare($con, "SELECT COUNT(*) as cnt FROM job_quiz_attempts WHERE user_id=? AND job_id=?");
mysqli_stmt_bind_param($att_stmt, "ii", $user_id, $job_id);
mysqli_stmt_execute($att_stmt);
$att_result = mysqli_stmt_get_result($att_stmt);
$attempt_count = intval(mysqli_fetch_assoc($att_result)['cnt']);

// Also count assessment_sessions (new engine)
$as_stmt = mysqli_prepare($con, "SELECT COUNT(*) as cnt FROM assessment_sessions WHERE user_id=? AND job_id=? AND status IN ('completed','timed_out','terminated')");
mysqli_stmt_bind_param($as_stmt, "ii", $user_id, $job_id);
mysqli_stmt_execute($as_stmt);
$as_result = mysqli_stmt_get_result($as_stmt);
$attempt_count += intval(mysqli_fetch_assoc($as_result)['cnt']);

// EXHAUSTED: user has 2+ attempts
if ($attempt_count >= 2 && !$grooming_completed) {
    echo "<script>alert('You have exhausted all assessment attempts for this position.'); window.location.href='job_details.php?id=$job_id';</script>";
    exit();
}

// If grooming was just completed, clear session lock
if ($grooming_completed && isset($_SESSION[$quiz_session_key])) {
    unset($_SESSION[$quiz_session_key]);
}

if (isset($_SESSION[$quiz_session_key]) && $_SESSION[$quiz_session_key] === true && !$grooming_completed) {
    echo "<script>alert('You have already attempted this quiz. Complete the grooming session to retake.'); window.location.href='grooming.php?category=" . urlencode($job['job_category']) . "&job_id=$job_id';</script>";
    exit();
}

// DB-level check — allow retake if grooming completed
$prev_stmt = mysqli_prepare($con, "SELECT score_final, status FROM assessment_sessions WHERE user_id=? AND job_id=? AND status IN ('completed','timed_out','terminated') ORDER BY started_at DESC LIMIT 1");
mysqli_stmt_bind_param($prev_stmt, "ii", $user_id, $job_id);
mysqli_stmt_execute($prev_stmt);
$prev_result = mysqli_stmt_get_result($prev_stmt);

if (mysqli_num_rows($prev_result) > 0) {
    $prev = mysqli_fetch_assoc($prev_result);
    $_SESSION[$quiz_session_key] = true;

    if ($prev['score_final'] >= 60) {
        echo "<script>alert('You have already passed this quiz. Redirecting to application.'); window.location.href='company_job_application.php?job_id=$job_id';</script>";
        exit();
    } elseif (!$grooming_completed) {
        echo "<script>alert('You have already attempted this quiz. Please complete the grooming session to retake.'); window.location.href='grooming.php?category=" . urlencode($job['job_category']) . "&job_id=$job_id';</script>";
        exit();
    }
    unset($_SESSION[$quiz_session_key]);
} else {
    // Also check legacy job_quiz_attempts table
    $legacy_stmt = mysqli_prepare($con, "SELECT score_percentage FROM job_quiz_attempts WHERE user_id=? AND job_id=? ORDER BY attempt_date DESC LIMIT 1");
    mysqli_stmt_bind_param($legacy_stmt, "ii", $user_id, $job_id);
    mysqli_stmt_execute($legacy_stmt);
    $legacy_result = mysqli_stmt_get_result($legacy_stmt);
    if (mysqli_num_rows($legacy_result) > 0) {
        $legacy = mysqli_fetch_assoc($legacy_result);
        $_SESSION[$quiz_session_key] = true;
        if ($legacy['score_percentage'] >= 60) {
            echo "<script>alert('You have already passed this quiz.'); window.location.href='company_job_application.php?job_id=$job_id';</script>";
            exit();
        } elseif (!$grooming_completed) {
            echo "<script>alert('You have already attempted this quiz. Please complete the grooming session to retake.'); window.location.href='grooming.php?category=" . urlencode($job['job_category']) . "&job_id=$job_id';</script>";
            exit();
        }
        unset($_SESSION[$quiz_session_key]);
    }
}

// ── Check for an existing in-progress session (resume support) ──
$resume_stmt = mysqli_prepare($con, "SELECT *, UNIX_TIMESTAMP(started_at) as session_started_ts, UNIX_TIMESTAMP(current_question_started_at) as question_started_ts FROM assessment_sessions WHERE user_id=? AND job_id=? AND status='in_progress' ORDER BY started_at DESC LIMIT 1");
mysqli_stmt_bind_param($resume_stmt, "ii", $user_id, $job_id);
mysqli_stmt_execute($resume_stmt);
$resume_result = mysqli_stmt_get_result($resume_stmt);
$existing_session = null;

if (mysqli_num_rows($resume_result) > 0) {
    $existing_session = mysqli_fetch_assoc($resume_result);
    // Check if it's still within the time limit
    $started_ts = !empty($existing_session['session_started_ts']) ? intval($existing_session['session_started_ts']) : strtotime($existing_session['started_at']);
    $time_limit = intval($existing_session['time_limit']);

    // Determine actual total quiz time
    $q_order_arr = json_decode($existing_session['question_order'], true);
    $total_quiz_sec = 0;
    if (!empty($q_order_arr) && is_array($q_order_arr)) {
        $q_ids_check = implode(',', array_map('intval', $q_order_arr));
        $sum_check = mysqli_query($con, "SELECT SUM(time_limit) as total_limit FROM company_job_questions WHERE id IN ($q_ids_check)");
        if ($sum_check && $sc_row = mysqli_fetch_assoc($sum_check)) {
            $total_quiz_sec = intval($sc_row['total_limit']);
        }
    }
    if ($total_quiz_sec <= 0) {
        $total_quiz_sec = max(60, $time_limit);
    }

    if ((time() - $started_ts) > ($total_quiz_sec + 20)) {
        // Expired — mark as timed_out
        mysqli_query($con, "UPDATE assessment_sessions SET status='timed_out', completed_at=NOW() WHERE id=" . intval($existing_session['id']));
        $existing_session = null;
    }
}

// ── Create a new session if none exists ──
if (!$existing_session) {
    // Fetch questions
    $q_stmt = mysqli_prepare($con, "SELECT id FROM company_job_questions WHERE job_id = ?");
    mysqli_stmt_bind_param($q_stmt, "i", $job_id);
    mysqli_stmt_execute($q_stmt);
    $q_result = mysqli_stmt_get_result($q_stmt);

    if (mysqli_num_rows($q_result) == 0) {
        echo "<script>alert('No quiz questions available for this job'); window.location.href='browse_jobs.php';</script>";
        exit();
    }

    $question_ids = [];
    while ($qr = mysqli_fetch_assoc($q_result)) {
        $question_ids[] = intval($qr['id']);
    }

    // Shuffle for randomization
    shuffle($question_ids);
    $question_order_json = json_encode($question_ids);
    $total_questions = count($question_ids);

    // Ensure application exists
    $app_stmt = mysqli_prepare($con, "SELECT id FROM job_applications WHERE user_id=? AND job_id=? ORDER BY applied_date DESC LIMIT 1");
    mysqli_stmt_bind_param($app_stmt, "ii", $user_id, $job_id);
    mysqli_stmt_execute($app_stmt);
    $app_result = mysqli_stmt_get_result($app_stmt);
    $application_id = null;

    if (mysqli_num_rows($app_result) > 0) {
        $application_id = intval(mysqli_fetch_assoc($app_result)['id']);
    } else {
        $company_id = intval($job['company_id']);
        $ins_app = mysqli_prepare($con, "INSERT INTO job_applications (user_id, job_id, company_id, application_status, quiz_status, applied_date) VALUES (?, ?, ?, 'pending', 'not_taken', NOW())");
        mysqli_stmt_bind_param($ins_app, "iii", $user_id, $job_id, $company_id);
        mysqli_stmt_execute($ins_app);
        $application_id = mysqli_insert_id($con);
    }

    // Create the session
    $safe_order = mysqli_real_escape_string($con, $question_order_json);
    $ins_sess = mysqli_prepare($con,
        "INSERT INTO assessment_sessions (user_id, job_id, application_id, total_questions, current_index, time_limit, status, question_order, started_at, current_question_started_at)
         VALUES (?, ?, ?, ?, 0, ?, 'in_progress', ?, NOW(), NOW())"
    );
    mysqli_stmt_bind_param($ins_sess, "iiiiss", $user_id, $job_id, $application_id, $total_questions, $quiz_timer, $safe_order);
    mysqli_stmt_execute($ins_sess);
    $session_id = mysqli_insert_id($con);

    // Pre-populate response rows
    foreach ($question_ids as $idx => $qid) {
        $ins_resp = mysqli_prepare($con, "INSERT INTO assessment_responses (session_id, question_id, question_index) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($ins_resp, "iii", $session_id, $qid, $idx);
        mysqli_stmt_execute($ins_resp);
    }

    $now_epoch = time();
    $existing_session = [
        'id' => $session_id,
        'current_index' => 0,
        'total_questions' => $total_questions,
        'time_limit' => $quiz_timer,
        'started_at' => date('Y-m-d H:i:s'),
        'current_question_started_at' => date('Y-m-d H:i:s'),
        'session_started_ts' => $now_epoch,
        'question_started_ts' => $now_epoch,
        'question_order' => $question_order_json,
        'risk_score' => 0,
    ];
}

// ── Session data for the frontend ──
$session_id      = intval($existing_session['id']);
$current_index   = intval($existing_session['current_index']);
$total_questions = intval($existing_session['total_questions']);
$time_limit      = intval($existing_session['time_limit']);
$question_order  = json_decode($existing_session['question_order'], true);

// ── Total Question Time for the entire assessment (Left Timer) ──
$total_quiz_seconds = 0;
if (!empty($question_order) && is_array($question_order)) {
    $safe_qids = implode(',', array_map('intval', $question_order));
    $sum_q = mysqli_query($con, "SELECT SUM(time_limit) as total_limit FROM company_job_questions WHERE id IN ($safe_qids)");
    if ($sum_q && $srow = mysqli_fetch_assoc($sum_q)) {
        $total_quiz_seconds = intval($srow['total_limit']);
    }
}
if ($total_quiz_seconds <= 0) {
    if ($time_limit > 0) {
        $total_quiz_seconds = $time_limit;
    } else {
        $total_quiz_seconds = $total_questions * 60;
    }
}

$overall_session_started = !empty($existing_session['session_started_ts']) 
    ? intval($existing_session['session_started_ts']) 
    : strtotime($existing_session['started_at']);
$overall_elapsed_seconds = max(0, time() - $overall_session_started);
$total_time_remaining    = max(0, $total_quiz_seconds - $overall_elapsed_seconds);

// ── Current Question Time remaining (Right Timer) ──
$q_started_ts = !empty($existing_session['question_started_ts'])
    ? intval($existing_session['question_started_ts'])
    : (!empty($existing_session['current_question_started_at']) ? strtotime($existing_session['current_question_started_at']) : $overall_session_started);
$q_elapsed = max(0, time() - $q_started_ts);
$time_remaining = 0;

// Fetch the first unanswered question
$first_question = null;
if ($current_index < $total_questions) {
    $first_qid = intval($question_order[$current_index]);
    $fq_stmt = mysqli_prepare($con, "SELECT id, question_type, question, option1, option2, option3, option4, time_limit, marks FROM company_job_questions WHERE id = ?");
    mysqli_stmt_bind_param($fq_stmt, "i", $first_qid);
    mysqli_stmt_execute($fq_stmt);
    $fq_result = mysqli_stmt_get_result($fq_stmt);
    if ($fq_result && mysqli_num_rows($fq_result) > 0) {
        $fq_data = mysqli_fetch_assoc($fq_result);
        $q_time_limit = intval($fq_data['time_limit']);
        $first_question = [
            'id'            => intval($fq_data['id']),
            'question_type' => $fq_data['question_type'],
            'question'      => $fq_data['question'],
            'question_number' => $current_index + 1,
            'time_limit'    => $q_time_limit,
            'marks'         => intval($fq_data['marks']),
        ];
        
        $time_remaining = max(0, $q_time_limit - $q_elapsed);

        if ($fq_data['question_type'] === 'mcq') {
            $opts = [$fq_data['option1'], $fq_data['option2'], $fq_data['option3'], $fq_data['option4']];
            shuffle($opts);
            $first_question['options'] = $opts;
        }
    }
}

$first_question_json = json_encode($first_question);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment - <?php echo htmlspecialchars($job['job_title']); ?></title>
    <?php require_once __DIR__ . '/../includes/links.php'; ?>
    <style>
        .quiz-page-body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .quiz-wrap {
            max-width: 900px;
            margin: 0 auto 60px;
            padding: 0 20px;
        }

        /* ── Floating Sticky Dual-Timer Bar ── */
        .timer-bar {
            position: sticky;
            top: 16px;
            z-index: 999;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 22px;
            padding: 14px 22px;
            box-shadow: 0 16px 36px -8px rgba(15, 23, 42, 0.16), 0 4px 12px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(226, 232, 240, 0.9);
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }

        .timer-bar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            width: 100%;
        }

        /* ── Individual Floating Timer Cards (Left: Total, Right: Question) ── */
        .timer-card {
            flex: 1;
            max-width: 320px;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 16px;
            padding: 12px 18px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
        }

        .timer-card-left {
            border-left: 4px solid #4f46e5;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .timer-card-right {
            border-right: 4px solid #7c3aed;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .timer-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .timer-icon-badge {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .timer-icon-badge.total-badge {
            background: rgba(79, 70, 229, 0.12);
            color: #4f46e5;
        }

        .timer-icon-badge.question-badge {
            background: rgba(124, 58, 237, 0.12);
            color: #7c3aed;
        }

        .timer-meta-info {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .timer-badge-label {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #475569;
        }

        .timer-sublabel {
            font-size: 10px;
            color: #94a3b8;
            font-weight: 600;
        }

        .timer-card-body {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .timer-time {
            font-size: 34px;
            font-weight: 900;
            font-family: 'Courier New', Courier, monospace;
            letter-spacing: 2px;
            transition: color 0.4s ease;
            line-height: 1;
        }

        .timer-time.color-green { color: #059669; }
        .timer-time.color-yellow { color: #d97706; }
        .timer-time.color-red { color: #dc2626; }

        .timer-progress {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }

        .timer-progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 1s linear, background 0.4s ease;
        }

        .timer-progress-fill.fill-green { background: linear-gradient(90deg, #059669, #34d399); }
        .timer-progress-fill.fill-yellow { background: linear-gradient(90deg, #d97706, #fbbf24); }
        .timer-progress-fill.fill-red { background: linear-gradient(90deg, #dc2626, #f87171); }

        /* ── Center Status & Alerts ── */
        .timer-center-status {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-align: center;
            flex-shrink: 0;
            min-width: 140px;
        }

        .timer-session-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: rgba(99, 102, 241, 0.08);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            color: #4f46e5;
            letter-spacing: 0.5px;
        }

        .pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse-green 1.8s infinite;
        }

        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .timer-timeouts-msg {
            display: none;
            font-size: 12px;
            color: #dc2626;
            font-weight: 700;
            animation: timer-pulse 1s infinite;
        }

        @keyframes timer-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.06); }
        }
        .timer-time.pulsing {
            animation: timer-pulse 1s ease-in-out infinite;
        }

        @keyframes timer-glow {
            0%, 100% { box-shadow: 0 16px 36px -8px rgba(15, 23, 42, 0.16), 0 0 0 0 rgba(239, 68, 68, 0); }
            50% { box-shadow: 0 16px 36px -8px rgba(15, 23, 42, 0.16), 0 0 24px 4px rgba(239, 68, 68, 0.35); }
        }
        .timer-bar.urgent {
            border-color: rgba(239, 68, 68, 0.6);
            animation: timer-glow 1.5s ease-in-out infinite;
        }

        /* ── Risk Indicator ── */
        .risk-indicator {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 3px 12px;
            border-radius: 20px;
            letter-spacing: 0.5px;
        }
        .risk-low      { background: #d1fae5; color: #065f46; }
        .risk-medium   { background: #fef3c7; color: #92400e; }
        .risk-high     { background: #fee2e2; color: #991b1b; }
        .risk-critical { background: #dc2626; color: white; }

        /* ── Quiz Header Card ── */
        .quiz-header-card {
            background: white;
            border-radius: 24px;
            padding: 40px 36px 32px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            text-align: center;
            margin-top: 24px;
        }

        .quiz-header-card h1 {
            font-size: 26px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .quiz-header-card .company-name {
            font-size: 16px;
            color: #64748b;
            margin-bottom: 0;
        }

        .quiz-meta {
            display: flex;
            justify-content: center;
            gap: 32px;
            flex-wrap: wrap;
            margin-top: 24px;
        }

        .quiz-meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #475569;
            font-size: 15px;
        }

        .quiz-meta-item i {
            font-size: 22px;
            color: #667eea;
            width: 24px;
            text-align: center;
        }

        .quiz-meta-item strong { color: #1e293b; }

        /* ── Warning Box ── */
        .quiz-warning {
            background: #fffbeb;
            border: 2px solid #d97706;
            border-radius: 16px;
            padding: 18px 24px;
            margin-bottom: 28px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .quiz-warning i {
            color: #d97706;
            font-size: 22px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .quiz-warning-text {
            font-size: 14px;
            color: #92400e;
            line-height: 1.5;
        }

        .quiz-warning-text strong { color: #78350f; }

        /* ── Progress Tracker ── */
        .progress-tracker {
            background: white;
            border-radius: 16px;
            padding: 18px 24px;
            margin-bottom: 28px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }

        .progress-bar-track {
            height: 8px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 10px;
            transition: width 0.4s ease;
        }

        .progress-text {
            text-align: center;
            margin-top: 10px;
            font-size: 14px;
            color: #64748b;
            font-weight: 600;
        }

        .progress-text span { color: #667eea; font-weight: 800; }

        /* ── Question Card ── */
        .question-card {
            background: white;
            border-radius: 24px;
            padding: 36px 40px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 24px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .q-number {
            display: inline-block;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 6px 18px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 16px;
            letter-spacing: 0.5px;
        }

        .qtype-badge-quiz {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 8px;
        }
        .qtype-badge-quiz.mcq { background: rgba(102, 126, 234, 0.12); color: #667eea; }
        .qtype-badge-quiz.short_answer { background: rgba(234, 88, 12, 0.12); color: #ea580c; }

        .q-text {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .options-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .opt-wrap { position: relative; }

        .opt-input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .opt-label {
            display: flex;
            align-items: center;
            padding: 16px 22px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.25s ease;
            font-size: 15px;
            color: #334155;
            font-weight: 500;
        }

        .opt-label:hover {
            background: #f1f5f9;
            border-color: #667eea;
        }

        .opt-input:checked + .opt-label {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 16px rgba(102, 126, 234, 0.3);
        }

        .opt-letter {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            background: white;
            border-radius: 50%;
            font-weight: 800;
            font-size: 14px;
            color: #667eea;
            margin-right: 14px;
            flex-shrink: 0;
            transition: all 0.25s ease;
        }

        .opt-input:checked + .opt-label .opt-letter {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Short answer textarea */
        .short-answer-area {
            width: 100%;
            min-height: 120px;
            padding: 16px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            font-size: 15px;
            font-family: inherit;
            resize: vertical;
            transition: border-color 0.3s;
        }
        .short-answer-area:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.15);
        }

        /* ── Submit Button ── */
        .btn-next-question {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 16px 50px;
            border-radius: 50px;
            border: none;
            font-size: 17px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.35);
            display: block;
            margin: 0 auto;
        }

        .btn-next-question:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(102, 126, 234, 0.45);
        }

        .btn-next-question:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-next-question.finish-btn {
            background: linear-gradient(135deg, #059669, #059669);
            box-shadow: 0 8px 30px rgba(16, 185, 129, 0.35);
        }

        /* ── Results Section ── */
        .results-card {
            background: white;
            border-radius: 24px;
            padding: 50px 40px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            text-align: center;
            margin-top: 24px;
        }

        .results-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 48px;
        }

        .results-icon.passed { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
        .results-icon.failed { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #dc2626; }

        .results-score-circle {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            margin: 0 auto 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .results-score-circle::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            padding: 8px;
            background: conic-gradient(var(--ring-color, #667eea) var(--ring-pct, 0%), #e2e8f0 var(--ring-pct, 0%));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        }

        .results-score-circle .score-val { font-size: 52px; font-weight: 900; line-height: 1; }
        .results-score-circle .score-pct { font-size: 18px; font-weight: 700; color: #64748b; }

        .results-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 32px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .results-status-badge.passed { background: #d1fae5; color: #065f46; }
        .results-status-badge.failed { background: #fee2e2; color: #991b1b; }

        .results-stats {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin: 28px 0;
            flex-wrap: wrap;
        }

        .results-stat { text-align: center; }
        .results-stat .stat-val { font-size: 24px; font-weight: 800; color: #1e293b; }
        .results-stat .stat-label { font-size: 13px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        .btn-go-now {
            display: inline-block;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 14px 40px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.35);
        }

        .btn-go-now:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.45);
            color: white;
            text-decoration: none;
        }

        /* ── Footer ── */
        .quiz-footer {
            text-align: center;
            padding: 24px;
            margin-top: 40px;
            color: rgba(255,255,255,0.7);
            font-size: 14px;
        }

        /* Loading spinner */
        .question-loading {
            text-align: center;
            padding: 60px 20px;
            color: white;
        }
        .question-loading i { font-size: 40px; margin-bottom: 16px; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .timer-bar { top: 10px; padding: 10px 14px; border-radius: 16px; }
            .timer-bar-inner { gap: 10px; }
            .timer-card { padding: 8px 12px; }
            .timer-time { font-size: 22px; letter-spacing: 1px; }
            .timer-sublabel { display: none; }
            .timer-badge-label { font-size: 10px; }
            .timer-icon-badge { width: 26px; height: 26px; font-size: 11px; }
            .timer-center-status { min-width: auto; }
            .timer-session-pill { display: none; }
            .quiz-header-card { padding: 28px 20px 24px; }
            .quiz-header-card h1 { font-size: 20px; }
            .quiz-meta { flex-direction: column; gap: 12px; align-items: center; }
            .question-card { padding: 24px 20px; }
            .q-text { font-size: 16px; }
            .opt-label { padding: 14px 16px; font-size: 14px; }
            .results-card { padding: 36px 20px; }
            .results-score-circle { width: 150px; height: 150px; }
            .results-score-circle .score-val { font-size: 42px; }
            .results-stats { gap: 24px; }
        }

        @media (max-width: 480px) {
            .timer-bar { top: 6px; padding: 8px 10px; }
            .timer-bar-inner { flex-wrap: wrap; gap: 8px; }
            .timer-card { max-width: 100%; flex: 1 1 46%; padding: 6px 10px; }
            .timer-time { font-size: 18px; letter-spacing: 1px; }
            .timer-badge-label { font-size: 9px; }
            .timer-icon-badge { display: none; }
            .timer-center-status { width: 100%; order: 3; }
            .quiz-wrap { padding: 0 12px; }
            .quiz-warning { padding: 14px 16px; }
        }
    </style>
</head>
<body class="quiz-page-body">

<div class="quiz-wrap">

    <!-- Floating Sticky Dual-Timer Bar -->
    <div class="timer-bar" id="timerBar">
        <div class="timer-bar-inner">
            
            <!-- Left: Total Assessment Timer -->
            <div class="timer-card timer-card-left" id="totalTimerCard">
                <div class="timer-card-header">
                    <div class="timer-icon-badge total-badge">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <div class="timer-meta-info">
                        <span class="timer-badge-label">Total Time</span>
                        <span class="timer-sublabel">Full Assessment</span>
                    </div>
                </div>
                <div class="timer-card-body">
                    <span class="timer-time color-green" id="totalTimerDisplay">--:--</span>
                </div>
                <div class="timer-progress">
                    <div class="timer-progress-fill fill-green" id="totalTimerProgressFill" style="width: 100%;"></div>
                </div>
            </div>

            <!-- Center: Status, Risk Badge & Alerts -->
            <div class="timer-center-status">
                <div class="timer-session-pill">
                    <span class="pulse-dot"></span>
                    <span>ACTIVE</span>
                </div>
                <span class="risk-indicator risk-low" id="riskBadge" style="display:none">
                    <i class="fas fa-shield-alt mr-1"></i><span id="riskText">LOW</span>
                </span>
                <span class="timer-timeouts-msg" id="timeoutMsg">
                    <i class="fas fa-exclamation-triangle mr-1"></i> Time expired &mdash; submitting...
                </span>
            </div>

            <!-- Right: Per-Question Timer -->
            <div class="timer-card timer-card-right" id="questionTimerCard">
                <div class="timer-card-header">
                    <div class="timer-icon-badge question-badge">
                        <i class="fas fa-stopwatch"></i>
                    </div>
                    <div class="timer-meta-info">
                        <span class="timer-badge-label">Question Time</span>
                        <span class="timer-sublabel">This Question</span>
                    </div>
                </div>
                <div class="timer-card-body">
                    <span class="timer-time color-green" id="timerDisplay">--:--</span>
                </div>
                <div class="timer-progress">
                    <div class="timer-progress-fill fill-green" id="timerProgressFill" style="width: 100%;"></div>
                </div>
            </div>

        </div>
    </div>

    <!-- Quiz Header -->
    <div class="quiz-header-card">
        <h1><i class="fas fa-clipboard-list mr-2" style="color: #667eea;"></i><?php echo htmlspecialchars($job['job_title']); ?> Assessment</h1>
        <p class="company-name"><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($job['company_name']); ?></p>

        <div class="quiz-meta">
            <div class="quiz-meta-item">
                <i class="fas fa-question-circle"></i>
                <span><strong><?php echo $total_questions; ?></strong> Questions</span>
            </div>
            <div class="quiz-meta-item">
                <i class="fas fa-check-circle"></i>
                <span>Passing Score: <strong>60%</strong></span>
            </div>
            <div class="quiz-meta-item">
                <i class="fas fa-clock"></i>
                <span>Variable Time Limits (per question)</span>
            </div>
            <div class="quiz-meta-item">
                <i class="fas fa-shield-alt"></i>
                <span>Proctored Assessment</span>
            </div>
        </div>
    </div>

    <!-- Warning -->
    <div class="quiz-warning">
        <i class="fas fa-exclamation-triangle"></i>
        <div class="quiz-warning-text">
            <strong>Important:</strong> This assessment is proctored. Switching tabs, exiting fullscreen, copying/pasting, or using developer tools will be logged and may result in automatic termination. Answer each question before proceeding.
        </div>
    </div>

    <!-- Progress Tracker -->
    <div class="progress-tracker">
        <div class="progress-bar-track">
            <div class="progress-bar-fill" id="progressBarFill" style="width: <?php echo $total_questions > 0 ? round(($current_index / $total_questions) * 100) : 0; ?>%;"></div>
        </div>
        <div class="progress-text">
            Question <span id="currentQNum"><?php echo $current_index + 1; ?></span> of <strong><?php echo $total_questions; ?></strong>
        </div>
    </div>

    <!-- Question Container (populated via JS) -->
    <div id="questionContainer"></div>

    <!-- Results Container (hidden, shown after completion) -->
    <div id="resultsContainer" style="display:none;"></div>

    <div class="quiz-footer">
        <p class="mb-0">&copy; <?php echo date('Y'); ?> NovaHire. All rights reserved.</p>
    </div>
</div>

<!-- Anti-Cheat Overlay -->
<div id="antiCheatOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:9999; color:white; align-items:center; justify-content:center; flex-direction:column; text-align:center;">
    <i class="fas fa-exclamation-triangle" style="font-size: 4rem; color: #d97706; margin-bottom: 20px;"></i>
    <h2 style="font-weight: bold; margin-bottom: 10px;">Warning!</h2>
    <p id="antiCheatMsg" style="font-size: 1.2rem; max-width: 600px;">You are not allowed to switch tabs or exit fullscreen mode during the assessment.</p>
    <p style="font-size: 1rem; color: #cbd5e1; margin-top: 10px;">This event has been recorded and reported.</p>
    <button id="resumeQuizBtn" style="margin-top: 30px; padding: 12px 30px; background: #3b82f6; border: none; border-radius: 8px; color: white; font-weight: bold; font-size: 1.1rem; cursor: pointer;">Resume Assessment</button>
</div>

<!-- Terminated Overlay -->
<div id="terminatedOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.95); z-index:10000; color:white; align-items:center; justify-content:center; flex-direction:column; text-align:center;">
    <i class="fas fa-ban" style="font-size: 5rem; color: #dc2626; margin-bottom: 20px;"></i>
    <h2 style="font-weight: bold; margin-bottom: 10px; color: #dc2626;">Assessment Terminated</h2>
    <p style="font-size: 1.2rem; max-width: 600px; color: #94a3b8;">Your assessment has been terminated due to multiple integrity violations. This has been reported to the employer.</p>
    <a href="job_details.php?id=<?php echo $job_id; ?>" style="margin-top: 30px; padding: 14px 40px; background: #475569; border: none; border-radius: 50px; color: white; font-weight: 700; font-size: 16px; text-decoration: none;">Back to Job Details</a>
</div>

<script>
(function() {
    const BASE = '<?php echo BASE_URL; ?>';
    const SESSION_ID = <?php echo $session_id; ?>;
    const TOTAL_Q    = <?php echo $total_questions; ?>;
    const JOB_ID     = <?php echo $job_id; ?>;
    const JOB_CATEGORY = '<?php echo addslashes($job['job_category']); ?>';

    let currentQuestion = <?php echo $first_question_json; ?>;
    let TIME_LIMIT   = currentQuestion ? currentQuestion.time_limit : 60;

    let timeLeft       = <?php echo $time_remaining; ?>;
    let totalTimeLimit = <?php echo (int)$total_quiz_seconds; ?>;
    let totalTimeLeft  = <?php echo (int)$total_time_remaining; ?>;
    let currentIndex   = <?php echo $current_index; ?>;
    let submitted      = false;
    let selectedAnswer = '';

    const TIMER_EL         = document.getElementById('timerDisplay');
    const TIMER_BAR        = document.getElementById('timerBar');
    const TOTAL_TIMER_EL   = document.getElementById('totalTimerDisplay');
    const TOTAL_FILL_TIMER = document.getElementById('totalTimerProgressFill');
    const PROGRESS_FILL    = document.getElementById('progressBarFill');
    const CURRENT_Q_NUM    = document.getElementById('currentQNum');
    const TIMEOUT_MSG      = document.getElementById('timeoutMsg');
    const FILL_TIMER       = document.getElementById('timerProgressFill');
    const Q_CONTAINER      = document.getElementById('questionContainer');
    const RESULTS          = document.getElementById('resultsContainer');
    const RISK_BADGE       = document.getElementById('riskBadge');
    const RISK_TEXT        = document.getElementById('riskText');
    const overlay          = document.getElementById('antiCheatOverlay');
    const resumeBtn        = document.getElementById('resumeQuizBtn');
    const terminatedOvl    = document.getElementById('terminatedOverlay');

    /* ══════════════════════════════════════════
       TIMER
       ══════════════════════════════════════════ */
    function tickTimer() {
        if (submitted) return;

        // ── 1. Per-Question Timer (Right Side) ──
        const mins = Math.floor(timeLeft / 60);
        const secs = timeLeft % 60;
        TIMER_EL.textContent = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');

        const pct = TIME_LIMIT > 0 ? (timeLeft / TIME_LIMIT) * 100 : 0;
        FILL_TIMER.style.width = Math.max(0, Math.min(100, pct)) + '%';

        TIMER_EL.classList.remove('color-green', 'color-yellow', 'color-red', 'pulsing');
        FILL_TIMER.classList.remove('fill-green', 'fill-yellow', 'fill-red');
        TIMER_BAR.classList.remove('urgent');
        TIMEOUT_MSG.style.display = 'none';

        if (pct > 50) {
            TIMER_EL.classList.add('color-green');
            FILL_TIMER.classList.add('fill-green');
        } else if (pct > 20) {
            TIMER_EL.classList.add('color-yellow');
            FILL_TIMER.classList.add('fill-yellow');
        } else {
            TIMER_EL.classList.add('color-red', 'pulsing');
            FILL_TIMER.classList.add('fill-red');
            TIMER_BAR.classList.add('urgent');
            if (pct <= 10) TIMEOUT_MSG.style.display = 'inline-flex';
        }

        // ── 2. Total Assessment Timer (Left Side) ──
        if (TOTAL_TIMER_EL && TOTAL_FILL_TIMER) {
            const tMins = Math.floor(totalTimeLeft / 60);
            const tSecs = totalTimeLeft % 60;
            TOTAL_TIMER_EL.textContent = String(tMins).padStart(2, '0') + ':' + String(tSecs).padStart(2, '0');

            const totalPct = totalTimeLimit > 0 ? (totalTimeLeft / totalTimeLimit) * 100 : 0;
            TOTAL_FILL_TIMER.style.width = Math.max(0, Math.min(100, totalPct)) + '%';

            TOTAL_TIMER_EL.classList.remove('color-green', 'color-yellow', 'color-red', 'pulsing');
            TOTAL_FILL_TIMER.classList.remove('fill-green', 'fill-yellow', 'fill-red');

            if (totalPct > 50) {
                TOTAL_TIMER_EL.classList.add('color-green');
                TOTAL_FILL_TIMER.classList.add('fill-green');
            } else if (totalPct > 20) {
                TOTAL_TIMER_EL.classList.add('color-yellow');
                TOTAL_FILL_TIMER.classList.add('fill-yellow');
            } else {
                TOTAL_TIMER_EL.classList.add('color-red', 'pulsing');
                TOTAL_FILL_TIMER.classList.add('fill-red');
            }
        }

        // Check if current question timed out
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            TIMER_EL.textContent = '00:00';
            TIMEOUT_MSG.innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i> Time\'s up for this question!';
            TIMEOUT_MSG.style.display = 'inline-flex';
            submitted = true;
            // Automatically submit and proceed to next question
            submitAnswer(true);
            return;
        }

        // Check if overall total time timed out
        if (totalTimeLeft <= 0) {
            clearInterval(timerInterval);
            if (TOTAL_TIMER_EL) TOTAL_TIMER_EL.textContent = '00:00';
            TIMEOUT_MSG.innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i> Total time expired!';
            TIMEOUT_MSG.style.display = 'inline-flex';
            submitted = true;
            submitAnswer(true);
            return;
        }

        timeLeft--;
        if (totalTimeLeft > 0) totalTimeLeft--;
    }

    let timerInterval = setInterval(tickTimer, 1000);
    tickTimer();

    /* ══════════════════════════════════════════
       RENDER QUESTION
       ══════════════════════════════════════════ */
    function renderQuestion(q) {
        if (!q) {
            Q_CONTAINER.innerHTML = '<div class="question-loading"><i class="fas fa-spinner fa-spin"></i><p>Loading...</p></div>';
            return;
        }

        selectedAnswer = '';
        currentQuestion = q;
        const isLast = (q.question_number >= TOTAL_Q);

        let optionsHTML = '';
        if (q.question_type === 'mcq' && q.options) {
            const letters = ['A', 'B', 'C', 'D'];
            q.options.forEach((opt, i) => {
                optionsHTML += `
                <div class="opt-wrap">
                    <input type="radio" class="opt-input" name="answer" id="opt_${i}" value="${escapeHtml(opt)}" onchange="window._selectAnswer(this.value)">
                    <label class="opt-label" for="opt_${i}">
                        <span class="opt-letter">${letters[i]}</span>
                        ${escapeHtml(opt)}
                    </label>
                </div>`;
            });
        } else {
            optionsHTML = `<textarea class="short-answer-area" id="shortAnswerField" placeholder="Type your answer here..." oninput="window._selectAnswer(this.value)"></textarea>`;
        }

        const typeBadge = q.question_type === 'short_answer'
            ? '<span class="qtype-badge-quiz short_answer">Short Answer</span>'
            : '<span class="qtype-badge-quiz mcq">MCQ</span>';

        Q_CONTAINER.innerHTML = `
            <div class="question-card">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span class="q-number">Question ${q.question_number}</span>
                        ${typeBadge}
                    </div>
                    <div style="font-weight: 600; color: #64748b; font-size: 14px;">
                        Marks: ${q.marks} | Time: ${q.time_limit}s
                    </div>
                </div>
                <div class="q-text">${escapeHtml(q.question)}</div>
                <div class="options-list">${optionsHTML}</div>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <button class="btn-next-question ${isLast ? 'finish-btn' : ''}" id="nextBtn" onclick="window._submitCurrent()" disabled>
                    <i class="fas fa-${isLast ? 'flag-checkered' : 'arrow-right'} mr-2"></i>${isLast ? 'Finish Assessment' : 'Next Question'}
                </button>
            </div>`;

        // Update progress
        CURRENT_Q_NUM.textContent = q.question_number;
        PROGRESS_FILL.style.width = ((q.question_number - 1) / TOTAL_Q * 100) + '%';
    }

    window._selectAnswer = function(val) {
        selectedAnswer = val;
        const btn = document.getElementById('nextBtn');
        if (btn) btn.disabled = (!val || val.trim() === '');
    };

    /* ══════════════════════════════════════════
       SUBMIT ANSWER
       ══════════════════════════════════════════ */
    window._submitCurrent = function() {
        submitAnswer(false);
    };

    function submitAnswer(isTimeout) {
        const btn = document.getElementById('nextBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting...';
        }

        // Before submitting, we can set submitted to true so timer stops
        submitted = true;
        clearInterval(timerInterval);

        const formData = new FormData();
        formData.append('session_id', SESSION_ID);
        if (currentQuestion && !isTimeout) {
            formData.append('question_id', currentQuestion.id);
            formData.append('answer', selectedAnswer);
        } else if (currentQuestion && isTimeout) {
            // Submit what they have
            formData.append('question_id', currentQuestion.id);
            formData.append('answer', selectedAnswer || '');
        }

        fetch(BASE + '/api/assessment_submit.php', {
            method: 'POST',
            body: formData,
        })
        .then(res => res.json())
        .then(data => {
            if (!data.ok) {
                if (data.timed_out || data.terminated) {
                    showTimedOut();
                }
                return;
            }

            timeLeft = data.time_remaining || timeLeft;

            if (data.completed) {
                clearInterval(timerInterval);
                submitted = true;
                showResults(data.score);
            } else if (data.next_question) {
                currentIndex = data.progress.current;
                
                // Set the new time limit based on the next question
                TIME_LIMIT = data.next_question.time_limit;
                timeLeft = TIME_LIMIT; 
                
                submitted = false; // Resume logic for new question
                
                renderQuestion(data.next_question);
                
                clearInterval(timerInterval);
                timerInterval = setInterval(tickTimer, 1000);
                tickTimer(); // Refresh UI instantly
            }
        })
        .catch(err => {
            console.error('Submit error:', err);
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-arrow-right mr-2"></i>Retry';
            }
            // Allow retry
            submitted = false;
            timerInterval = setInterval(tickTimer, 1000);
        });
    }

    function showTimedOut() {
        clearInterval(timerInterval);
        submitted = true;
        if (TIMER_BAR) TIMER_BAR.style.display = 'none';
        Q_CONTAINER.innerHTML = '';
        RESULTS.style.display = 'block';
        RESULTS.innerHTML = `
            <div class="results-card">
                <div class="results-icon failed"><i class="fas fa-clock"></i></div>
                <h2 style="font-size: 28px; font-weight: 800; color: #1e293b; margin-bottom: 6px;">Time's Up!</h2>
                <p style="color: #64748b; font-size: 16px; margin-bottom: 30px;">Your assessment has ended because the time limit was reached.</p>
                <a href="grooming.php?category=${encodeURIComponent(JOB_CATEGORY)}&job_id=${JOB_ID}" class="btn-go-now">
                    Go to Grooming Session <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>`;
    }

    function showResults(score) {
        if (!score) return showTimedOut();

        const isPassed = score.status === 'passed';
        const passColor = isPassed ? '#059669' : '#dc2626';
        const finalScore = Math.round(score.final);

        let redirectUrl = isPassed
            ? `company_job_application.php?job_id=${JOB_ID}&quiz=passed`
            : `grooming.php?category=${encodeURIComponent(JOB_CATEGORY)}&job_id=${JOB_ID}`;

        if (TIMER_BAR) TIMER_BAR.style.display = 'none';
        Q_CONTAINER.innerHTML = '';
        RESULTS.style.display = 'block';
        PROGRESS_FILL.style.width = '100%';

        RESULTS.innerHTML = `
            <div class="results-card">
                <div class="results-icon ${isPassed ? 'passed' : 'failed'}">
                    <i class="fas fa-${isPassed ? 'check' : 'times'}"></i>
                </div>
                <h2 style="font-size: 28px; font-weight: 800; color: #1e293b; margin-bottom: 6px;">Assessment Complete!</h2>
                <p style="color: #64748b; font-size: 16px; margin-bottom: 30px;">
                    ${escapeHtml('<?php echo htmlspecialchars($job['job_title']); ?>')} &mdash; ${escapeHtml('<?php echo htmlspecialchars($job['company_name']); ?>')}
                </p>

                <div class="results-score-circle" style="--ring-color: ${passColor}; --ring-pct: ${finalScore}%;">
                    <div class="score-val" style="color: ${passColor};">${finalScore}%</div>
                    <div class="score-pct">Final Score</div>
                </div>

                <div class="results-status-badge ${isPassed ? 'passed' : 'failed'}">
                    <i class="fas fa-${isPassed ? 'trophy' : 'exclamation-circle'}"></i>
                    ${isPassed ? 'PASSED — Great job!' : 'FAILED — Minimum 60% required'}
                </div>

                <div class="results-stats">
                    ${score.mcq !== null ? `<div class="results-stat"><div class="stat-val">${Math.round(score.mcq)}%</div><div class="stat-label">MCQ Score</div></div>` : ''}
                    ${score.short !== null ? `<div class="results-stat"><div class="stat-val">${Math.round(score.short)}%</div><div class="stat-label">Short Answer Score</div></div>` : ''}
                    <div class="results-stat"><div class="stat-val">${finalScore}%</div><div class="stat-label">Overall</div></div>
                </div>

                <div style="margin-top: 30px; padding-top: 24px; border-top: 1px solid #e2e8f0;">
                    <p style="color: #64748b; margin-bottom: 16px;">Redirecting in <span class="countdown-num" id="redirectCountdown" style="font-weight:800; color:#667eea;">5</span> seconds...</p>
                    <a href="${redirectUrl}" class="btn-go-now">
                        ${isPassed ? 'Continue to Application' : 'Go to Grooming Session'}
                        <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            </div>`;

        // Redirect countdown
        let rdSec = 5;
        const rdEl = document.getElementById('redirectCountdown');
        const rdInt = setInterval(() => {
            rdSec--;
            if (rdSec <= 0) {
                clearInterval(rdInt);
                window.location.href = redirectUrl;
            } else {
                rdEl.textContent = rdSec;
            }
        }, 1000);
    }

    /* ══════════════════════════════════════════
       ANTI-CHEAT SYSTEM
       ══════════════════════════════════════════ */
    let isAntiCheatActive = false;

    function trackEvent(eventType, data) {
        if (submitted) return;
        const fd = new FormData();
        fd.append('session_id', SESSION_ID);
        fd.append('event_type', eventType);
        fd.append('event_data', data || '');

        fetch(BASE + '/api/assessment_track.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(resp => {
            if (resp.ok) {
                updateRisk(resp.risk_level, resp.risk_score);
                if (resp.terminated) {
                    submitted = true;
                    clearInterval(timerInterval);
                    terminatedOvl.style.display = 'flex';
                }
            }
        }).catch(() => {});
    }

    function updateRisk(level, score) {
        RISK_BADGE.style.display = 'inline-flex';
        RISK_BADGE.className = 'risk-indicator risk-' + level;
        RISK_TEXT.textContent = level.toUpperCase();
    }

    function showWarningOverlay(reason) {
        if (submitted) return;
        document.getElementById('antiCheatMsg').innerText = 'Warning: ' + reason + ' is not allowed during the assessment. This event has been recorded.';
        overlay.style.display = 'flex';
    }

    resumeBtn.addEventListener('click', () => {
        overlay.style.display = 'none';
        if (document.documentElement.requestFullscreen) {
            document.documentElement.requestFullscreen().catch(() => {});
        }
    });

    // Disable right click
    document.addEventListener('contextmenu', e => {
        e.preventDefault();
        trackEvent('RIGHT_CLICK');
    });

    // Copy/paste
    document.addEventListener('copy', e => {
        e.preventDefault();
        trackEvent('COPY');
        showWarningOverlay('Copying text');
    });
    document.addEventListener('paste', e => {
        e.preventDefault();
        trackEvent('PASTE');
        showWarningOverlay('Pasting text');
    });

    // Tab switch
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && !submitted) {
            trackEvent('TAB_SWITCH');
            showWarningOverlay('Switching tabs or minimizing the browser');
        }
    });

    // Window blur
    window.addEventListener('blur', () => {
        if (!submitted) trackEvent('BLUR');
    });

    // Fullscreen exit
    document.addEventListener('fullscreenchange', () => {
        if (!document.fullscreenElement && isAntiCheatActive && !submitted) {
            trackEvent('FULLSCREEN_EXIT');
            showWarningOverlay('Exiting fullscreen mode');
        }
    });

    // Request fullscreen on first interaction
    window.addEventListener('load', () => {
        document.body.addEventListener('click', function enableFS() {
            if (!isAntiCheatActive) {
                if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen().catch(() => {});
                }
                isAntiCheatActive = true;
                document.body.removeEventListener('click', enableFS);
            }
        });
    });

    // Prevent accidental leave
    window.addEventListener('beforeunload', function(e) {
        if (!submitted) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    /* ══════════════════════════════════════════
       HELPERS
       ══════════════════════════════════════════ */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    /* ── Initial render ── */
    if (currentQuestion) {
        renderQuestion(currentQuestion);
    } else {
        Q_CONTAINER.innerHTML = '<div class="question-loading"><i class="fas fa-spinner fa-spin"></i><p>Loading question...</p></div>';
    }
})();
</script>
</body>
</html>
