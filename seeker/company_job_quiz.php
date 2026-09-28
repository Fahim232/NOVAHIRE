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
        .quiz-page-body {
            background: radial-gradient(circle at 15% 15%, rgba(124, 58, 237, 0.25) 0%, transparent 45%),
                        radial-gradient(circle at 85% 85%, rgba(59, 130, 246, 0.22) 0%, transparent 45%),
                        linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #6366f1 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1e293b;
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* ── Dark Mode Page Background ── */
        [data-theme="dark"] .quiz-page-body,
        .dark-theme.quiz-page-body,
        body[data-theme="dark"].quiz-page-body {
            background: radial-gradient(circle at 15% 15%, rgba(99, 102, 241, 0.18) 0%, transparent 50%),
                        radial-gradient(circle at 85% 85%, rgba(14, 165, 233, 0.14) 0%, transparent 50%),
                        linear-gradient(135deg, #090d16 0%, #0f172a 50%, #0a0f1d 100%) !important;
            color: #f8fafc !important;
        }

        .quiz-wrap {
            max-width: 900px;
            margin: 0 auto 60px;
            padding: 0 20px;
        }

        /* ── Floating Sticky Timer Bar (Glassmorphic Container) ── */
        .timer-bar {
            position: sticky;
            top: 20px;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1.5px solid rgba(255, 255, 255, 0.75);
            border-radius: 22px;
            padding: 12px 24px;
            box-shadow: 0 16px 36px -8px rgba(15, 23, 42, 0.16), 0 4px 12px rgba(0, 0, 0, 0.04), inset 0 1px 1px rgba(255, 255, 255, 0.9);
            margin-bottom: 24px;
            transition: all 0.3s ease;
        }

        [data-theme="dark"] .timer-bar,
        .dark-theme .timer-bar {
            background: rgba(15, 23, 42, 0.85) !important;
            border-color: rgba(255, 255, 255, 0.12) !important;
            box-shadow: 0 16px 36px -8px rgba(0, 0, 0, 0.6), inset 0 1px 1px rgba(255, 255, 255, 0.08) !important;
        }

        .timer-bar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        /* ── Status Group ── */
        .timer-status-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ── Glassmorphism Timer Card ── */
        .timer-card-glass {
            min-width: 220px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.82) 0%, rgba(248, 250, 252, 0.55) 100%);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1.5px solid rgba(255, 255, 255, 0.9);
            border-radius: 16px;
            padding: 10px 18px;
            box-shadow: 0 8px 24px -4px rgba(124, 58, 237, 0.14), inset 0 1px 2px rgba(255, 255, 255, 0.95);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        [data-theme="dark"] .timer-card-glass,
        .dark-theme .timer-card-glass {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.8) 0%, rgba(15, 23, 42, 0.6) 100%) !important;
            border-color: rgba(255, 255, 255, 0.15) !important;
            box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.45), inset 0 1px 2px rgba(255, 255, 255, 0.1) !important;
        }

        .timer-card-glass:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px -4px rgba(124, 58, 237, 0.18), inset 0 1px 2px rgba(255, 255, 255, 1);
            border-color: rgba(255, 255, 255, 0.95);
        }

        [data-theme="dark"] .timer-card-glass:hover,
        .dark-theme .timer-card-glass:hover {
            border-color: rgba(129, 140, 248, 0.5) !important;
            box-shadow: 0 12px 28px -4px rgba(0, 0, 0, 0.6), inset 0 1px 2px rgba(255, 255, 255, 0.15) !important;
        }

        .timer-card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .timer-icon-badge {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }

        .timer-icon-badge.question-badge {
            background: rgba(124, 58, 237, 0.12);
            color: #7c3aed;
            box-shadow: 0 2px 6px rgba(124, 58, 237, 0.1);
        }

        [data-theme="dark"] .timer-icon-badge.question-badge,
        .dark-theme .timer-icon-badge.question-badge {
            background: rgba(129, 140, 248, 0.2) !important;
            color: #a5b4fc !important;
            box-shadow: 0 2px 6px rgba(99, 102, 241, 0.25) !important;
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

        [data-theme="dark"] .timer-badge-label,
        .dark-theme .timer-badge-label {
            color: #e2e8f0 !important;
        }

        .timer-sublabel {
            font-size: 9px;
            color: #94a3b8;
            font-weight: 600;
        }

        [data-theme="dark"] .timer-sublabel,
        .dark-theme .timer-sublabel {
            color: #64748b !important;
        }

        .timer-card-body {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .timer-time {
            font-size: 26px;
            font-weight: 900;
            font-family: 'Courier New', Courier, monospace;
            letter-spacing: 1.5px;
            transition: color 0.4s ease;
            line-height: 1;
        }

        .timer-time.color-green { color: #059669; }
        .timer-time.color-yellow { color: #d97706; }
        .timer-time.color-red { color: #dc2626; }

        [data-theme="dark"] .timer-time.color-green,
        .dark-theme .timer-time.color-green { color: #34d399 !important; text-shadow: 0 0 10px rgba(52, 211, 153, 0.3); }
        [data-theme="dark"] .timer-time.color-yellow,
        .dark-theme .timer-time.color-yellow { color: #fbbf24 !important; text-shadow: 0 0 10px rgba(251, 191, 36, 0.3); }
        [data-theme="dark"] .timer-time.color-red,
        .dark-theme .timer-time.color-red { color: #f87171 !important; text-shadow: 0 0 10px rgba(248, 113, 113, 0.3); }

        .timer-progress {
            width: 100%;
            height: 5px;
            background: rgba(226, 232, 240, 0.8);
            border-radius: 10px;
            overflow: hidden;
        }

        [data-theme="dark"] .timer-progress,
        .dark-theme .timer-progress {
            background: rgba(51, 65, 85, 0.6) !important;
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
            flex: 1;
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

        [data-theme="dark"] .timer-session-pill,
        .dark-theme .timer-session-pill {
            background: rgba(99, 102, 241, 0.2) !important;
            border-color: rgba(99, 102, 241, 0.4) !important;
            color: #a5b4fc !important;
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
        .timer-bar.urgent .timer-card-glass {
            border-color: rgba(239, 68, 68, 0.5);
            background: linear-gradient(135deg, rgba(254, 242, 242, 0.85) 0%, rgba(254, 226, 226, 0.6) 100%);
        }
        [data-theme="dark"] .timer-bar.urgent .timer-card-glass,
        .dark-theme .timer-bar.urgent .timer-card-glass {
            border-color: rgba(239, 68, 68, 0.6) !important;
            background: linear-gradient(135deg, rgba(69, 10, 10, 0.8) 0%, rgba(30, 10, 10, 0.6) 100%) !important;
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

        [data-theme="dark"] .risk-low, .dark-theme .risk-low { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        [data-theme="dark"] .risk-medium, .dark-theme .risk-medium { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        [data-theme="dark"] .risk-high, .dark-theme .risk-high { background: rgba(239, 68, 68, 0.2); color: #f87171; }

        /* ── Quiz Header Card (Glassmorphic) ── */
        .quiz-header-card {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border: 1.5px solid rgba(255, 255, 255, 0.8);
            border-radius: 24px;
            padding: 36px 36px 30px;
            box-shadow: 0 16px 40px -10px rgba(15, 23, 42, 0.15), inset 0 1px 1px rgba(255, 255, 255, 0.9);
            margin-bottom: 26px;
            text-align: center;
            margin-top: 24px;
            transition: all 0.3s ease;
        }

        [data-theme="dark"] .quiz-header-card,
        .dark-theme .quiz-header-card {
            background: rgba(17, 24, 39, 0.82) !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.55), inset 0 1px 1px rgba(255, 255, 255, 0.06) !important;
        }

        .quiz-header-card h1 {
            font-size: 26px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 8px;
            letter-spacing: -0.3px;
        }

        [data-theme="dark"] .quiz-header-card h1,
        .dark-theme .quiz-header-card h1 {
            color: #f8fafc !important;
        }

        .quiz-header-card .company-name {
            font-size: 16px;
            color: #64748b;
            margin-bottom: 0;
            font-weight: 600;
        }

        [data-theme="dark"] .quiz-header-card .company-name,
        .dark-theme .quiz-header-card .company-name {
            color: #94a3b8 !important;
        }

        .quiz-meta {
            display: flex;
            justify-content: center;
            gap: 28px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .quiz-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #475569;
            font-size: 14.5px;
            font-weight: 500;
        }

        [data-theme="dark"] .quiz-meta-item,
        .dark-theme .quiz-meta-item {
            color: #cbd5e1 !important;
        }

        .quiz-meta-item i {
            font-size: 20px;
            color: #6366f1;
            width: 22px;
            text-align: center;
        }

        [data-theme="dark"] .quiz-meta-item i,
        .dark-theme .quiz-meta-item i {
            color: #818cf8 !important;
        }

        .quiz-meta-item strong { color: #1e293b; font-weight: 700; }
        [data-theme="dark"] .quiz-meta-item strong,
        .dark-theme .quiz-meta-item strong { color: #f8fafc !important; }

        /* ── Warning Box (Glassmorphic) ── */
        .quiz-warning {
            background: rgba(254, 243, 199, 0.65);
            border: 1.5px solid rgba(217, 119, 6, 0.45);
            border-radius: 16px;
            padding: 16px 22px;
            margin-bottom: 26px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        [data-theme="dark"] .quiz-warning,
        .dark-theme .quiz-warning {
            background: rgba(245, 158, 11, 0.1) !important;
            border-color: rgba(245, 158, 11, 0.35) !important;
        }

        .quiz-warning i {
            color: #d97706;
            font-size: 20px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        [data-theme="dark"] .quiz-warning i,
        .dark-theme .quiz-warning i {
            color: #fbbf24 !important;
        }

        .quiz-warning-text {
            font-size: 13.5px;
            color: #92400e;
            line-height: 1.5;
        }

        [data-theme="dark"] .quiz-warning-text,
        .dark-theme .quiz-warning-text {
            color: #fde68a !important;
        }

        .quiz-warning-text strong { color: #78350f; font-weight: 700; }
        [data-theme="dark"] .quiz-warning-text strong,
        .dark-theme .quiz-warning-text strong { color: #fef3c7 !important; }

        /* ── Progress Tracker (Glassmorphic) ── */
        .progress-tracker {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1.5px solid rgba(255, 255, 255, 0.8);
            border-radius: 16px;
            padding: 18px 24px;
            margin-bottom: 26px;
            box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.08);
            transition: all 0.3s ease;
        }

        [data-theme="dark"] .progress-tracker,
        .dark-theme .progress-tracker {
            background: rgba(17, 24, 39, 0.8) !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4) !important;
        }

        .progress-bar-track {
            height: 8px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }

        [data-theme="dark"] .progress-bar-track,
        .dark-theme .progress-bar-track {
            background: rgba(51, 65, 85, 0.6) !important;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
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

        [data-theme="dark"] .progress-text,
        .dark-theme .progress-text {
            color: #94a3b8 !important;
        }

        .progress-text span { color: #6366f1; font-weight: 800; }
        [data-theme="dark"] .progress-text span,
        .dark-theme .progress-text span { color: #818cf8 !important; }
        [data-theme="dark"] .progress-text strong,
        .dark-theme .progress-text strong { color: #f8fafc !important; }

        /* ── Question Card (Glassmorphic) ── */
        .question-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border: 1.5px solid rgba(255, 255, 255, 0.85);
            border-radius: 24px;
            padding: 36px 40px;
            box-shadow: 0 16px 40px -10px rgba(15, 23, 42, 0.15), inset 0 1px 1px rgba(255, 255, 255, 0.9);
            margin-bottom: 24px;
            transition: all 0.3s ease;
        }

        [data-theme="dark"] .question-card,
        .dark-theme .question-card {
            background: rgba(17, 24, 39, 0.85) !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.55), inset 0 1px 1px rgba(255, 255, 255, 0.06) !important;
        }

        .q-number {
            display: inline-block;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            padding: 6px 18px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
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
        .qtype-badge-quiz.mcq { background: rgba(99, 102, 241, 0.12); color: #4f46e5; }
        .qtype-badge-quiz.short_answer { background: rgba(234, 88, 12, 0.12); color: #ea580c; }

        [data-theme="dark"] .qtype-badge-quiz.mcq,
        .dark-theme .qtype-badge-quiz.mcq {
            background: rgba(99, 102, 241, 0.22) !important;
            color: #a5b4fc !important;
        }
        [data-theme="dark"] .qtype-badge-quiz.short_answer,
        .dark-theme .qtype-badge-quiz.short_answer {
            background: rgba(249, 115, 22, 0.22) !important;
            color: #fdba74 !important;
        }

        .q-meta-info {
            font-weight: 600;
            color: #64748b;
            font-size: 14px;
        }

        [data-theme="dark"] .q-meta-info,
        .dark-theme .q-meta-info,
        [data-theme="dark"] .question-card div[style*="color: #64748b"],
        .dark-theme .question-card div[style*="color: #64748b"] {
            color: #94a3b8 !important;
        }

        .q-text {
            font-size: 18.5px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        [data-theme="dark"] .q-text,
        .dark-theme .q-text {
            color: #f8fafc !important;
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
            background: rgba(248, 250, 252, 0.75);
            border: 2px solid rgba(226, 232, 240, 0.9);
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 15px;
            color: #334155;
            font-weight: 500;
            backdrop-filter: blur(8px);
        }

        .opt-label:hover {
            background: rgba(241, 245, 249, 0.9);
            border-color: #6366f1;
            transform: translateX(3px);
        }

        [data-theme="dark"] .opt-label,
        .dark-theme .opt-label {
            background: rgba(30, 41, 59, 0.6) !important;
            border: 2px solid rgba(51, 65, 85, 0.8) !important;
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .opt-label:hover,
        .dark-theme .opt-label:hover {
            background: rgba(51, 65, 85, 0.7) !important;
            border-color: #818cf8 !important;
            color: #ffffff !important;
            transform: translateX(3px);
        }

        .opt-input:checked + .opt-label {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%) !important;
            color: white !important;
            border-color: transparent !important;
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.45) !important;
            transform: translateX(4px);
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
            color: #6366f1;
            margin-right: 14px;
            flex-shrink: 0;
            transition: all 0.25s ease;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        [data-theme="dark"] .opt-letter,
        .dark-theme .opt-letter {
            background: rgba(15, 23, 42, 0.85) !important;
            color: #a5b4fc !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            box-shadow: none;
        }

        .opt-input:checked + .opt-label .opt-letter {
            background: rgba(255, 255, 255, 0.25) !important;
            color: white !important;
            border-color: transparent !important;
        }

        /* Short answer textarea */
        .short-answer-area {
            width: 100%;
            min-height: 120px;
            padding: 16px 20px;
            background: rgba(248, 250, 252, 0.8);
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            font-size: 15px;
            font-family: inherit;
            color: #1e293b;
            resize: vertical;
            transition: all 0.3s ease;
        }

        .short-answer-area:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            background: #ffffff;
        }

        [data-theme="dark"] .short-answer-area,
        .dark-theme .short-answer-area {
            background: rgba(30, 41, 59, 0.6) !important;
            border: 2px solid rgba(51, 65, 85, 0.8) !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .short-answer-area:focus,
        .dark-theme .short-answer-area:focus {
            border-color: #818cf8 !important;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.25) !important;
            background: rgba(30, 41, 59, 0.85) !important;
        }

        /* ── Submit Button ── */
        .btn-next-question {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            padding: 15px 48px;
            border-radius: 50px;
            border: none;
            font-size: 16.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 0 auto;
        }

        .btn-next-question:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(99, 102, 241, 0.55);
        }

        .btn-next-question:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-next-question.finish-btn {
            background: linear-gradient(135deg, #059669, #10b981);
            box-shadow: 0 8px 30px rgba(16, 185, 129, 0.4);
        }

        .btn-next-question.finish-btn:hover {
            box-shadow: 0 12px 35px rgba(16, 185, 129, 0.55);
        }

        /* ── Results Section (Glassmorphic) ── */
        .results-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border: 1.5px solid rgba(255, 255, 255, 0.85);
            border-radius: 24px;
            padding: 48px 40px;
            box-shadow: 0 16px 40px -10px rgba(15, 23, 42, 0.15), inset 0 1px 1px rgba(255, 255, 255, 0.9);
            text-align: center;
            margin-top: 24px;
            transition: all 0.3s ease;
        }

        [data-theme="dark"] .results-card,
        .dark-theme .results-card {
            background: rgba(17, 24, 39, 0.88) !important;
            border-color: rgba(255, 255, 255, 0.12) !important;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.65), inset 0 1px 1px rgba(255, 255, 255, 0.06) !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .results-card h2,
        .dark-theme .results-card h2 {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .results-card p,
        .dark-theme .results-card p {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .results-card div,
        .dark-theme .results-card div {
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        .results-icon {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 42px;
        }

        .results-icon.passed {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(16, 185, 129, 0.1));
            color: #059669;
            border: 1.5px solid rgba(16, 185, 129, 0.35);
        }
        .results-icon.failed {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1));
            color: #dc2626;
            border: 1.5px solid rgba(239, 68, 68, 0.35);
        }

        [data-theme="dark"] .results-icon.passed,
        .dark-theme .results-icon.passed {
            color: #34d399 !important;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.25);
        }
        [data-theme="dark"] .results-icon.failed,
        .dark-theme .results-icon.failed {
            color: #f87171 !important;
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.25);
        }

        .results-score-circle {
            width: 170px;
            height: 170px;
            border-radius: 50%;
            margin: 0 auto 24px;
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
            background: conic-gradient(var(--ring-color, #4f46e5) var(--ring-pct, 0%), #e2e8f0 var(--ring-pct, 0%));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        }

        [data-theme="dark"] .results-score-circle::before,
        .dark-theme .results-score-circle::before {
            background: conic-gradient(var(--ring-color, #818cf8) var(--ring-pct, 0%), rgba(51, 65, 85, 0.5) var(--ring-pct, 0%)) !important;
        }

        .results-score-circle .score-val { font-size: 48px; font-weight: 900; line-height: 1; }
        .results-score-circle .score-pct { font-size: 16px; font-weight: 700; color: #64748b; }

        [data-theme="dark"] .results-score-circle .score-pct,
        .dark-theme .results-score-circle .score-pct {
            color: #94a3b8 !important;
        }

        .results-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 28px;
            border-radius: 50px;
            font-size: 16.5px;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .results-status-badge.passed { background: #d1fae5; color: #065f46; border: 1px solid rgba(16, 185, 129, 0.3); }
        .results-status-badge.failed { background: #fee2e2; color: #991b1b; border: 1px solid rgba(239, 68, 68, 0.3); }

        [data-theme="dark"] .results-status-badge.passed,
        .dark-theme .results-status-badge.passed {
            background: rgba(16, 185, 129, 0.2) !important;
            color: #34d399 !important;
            border-color: rgba(16, 185, 129, 0.4) !important;
        }

        [data-theme="dark"] .results-status-badge.failed,
        .dark-theme .results-status-badge.failed {
            background: rgba(239, 68, 68, 0.2) !important;
            color: #f87171 !important;
            border-color: rgba(239, 68, 68, 0.4) !important;
        }

        .results-stats {
            display: flex;
            justify-content: center;
            gap: 36px;
            margin: 24px 0;
            flex-wrap: wrap;
        }

        .results-stat { text-align: center; }
        .results-stat .stat-val { font-size: 24px; font-weight: 800; color: #1e293b; }
        .results-stat .stat-label { font-size: 13px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

        [data-theme="dark"] .results-stat .stat-val,
        .dark-theme .results-stat .stat-val {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .results-stat .stat-label,
        .dark-theme .results-stat .stat-label {
            color: #94a3b8 !important;
        }

        .countdown-num { font-weight: 800; color: #4f46e5; }
        [data-theme="dark"] .countdown-num,
        .dark-theme .countdown-num {
            color: #818cf8 !important;
        }

        .btn-go-now {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white !important;
            padding: 13px 36px;
            border-radius: 50px;
            text-decoration: none !important;
            font-weight: 700;
            font-size: 15.5px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.4);
        }

        .btn-go-now:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(99, 102, 241, 0.55);
            color: white !important;
            text-decoration: none !important;
        }

        /* ── Footer ── */
        .quiz-footer {
            text-align: center;
            padding: 24px;
            margin-top: 40px;
            color: rgba(255, 255, 255, 0.75);
            font-size: 14px;
        }

        [data-theme="dark"] .quiz-footer,
        .dark-theme .quiz-footer {
            color: rgba(255, 255, 255, 0.45) !important;
        }

        /* Loading spinner */
        .question-loading {
            text-align: center;
            padding: 60px 20px;
            color: white;
        }
        .question-loading i { font-size: 40px; margin-bottom: 16px; }

        /* ── Anti-Cheat Overlays ── */
        #antiCheatOverlay, #terminatedOverlay {
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            background: rgba(8, 12, 22, 0.9) !important;
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .timer-bar { top: 10px; padding: 10px 16px; border-radius: 18px; }
            .timer-bar-inner { gap: 12px; }
            .timer-card-glass { min-width: 170px; padding: 8px 12px; border-radius: 14px; }
            .timer-time { font-size: 22px; letter-spacing: 1px; }
            .timer-sublabel { display: none; }
            .timer-badge-label { font-size: 10px; }
            .timer-icon-badge { width: 26px; height: 26px; font-size: 11px; }
            .timer-session-pill { font-size: 10px; padding: 4px 10px; }
            .quiz-header-card { padding: 28px 20px 24px; }
            .quiz-header-card h1 { font-size: 20px; }
            .quiz-meta { flex-direction: column; gap: 12px; align-items: center; }
            .question-card { padding: 24px 20px; }
            .q-text { font-size: 16px; }
            .opt-label { padding: 14px 16px; font-size: 14px; }
            .results-card { padding: 36px 20px; }
            .results-score-circle { width: 140px; height: 140px; }
            .results-score-circle .score-val { font-size: 40px; }
            .results-stats { gap: 20px; }
        }

        @media (max-width: 520px) {
            .timer-bar { top: 6px; padding: 8px 12px; }
            .timer-bar-inner { flex-wrap: wrap; justify-content: center; gap: 8px; }
            .timer-status-group { width: 100%; justify-content: space-between; order: 2; }
            .timer-card-glass { width: 100%; min-width: 100%; order: 1; padding: 8px 12px; }
            .timer-center-status { width: 100%; order: 3; text-align: center; }
            .timer-time { font-size: 22px; }
            .quiz-wrap { padding: 0 12px; }
            .quiz-warning { padding: 14px 16px; }
        }
    </style>
</head>
<body class="quiz-page-body">

<div class="quiz-wrap">

    <!-- Floating Sticky Timer Bar (Glassmorphic) -->
    <div class="timer-bar" id="timerBar">
        <div class="timer-bar-inner">
            
            <!-- Left: Status & Risk Badge -->
            <div class="timer-status-group">
                <div class="timer-session-pill">
                    <span class="pulse-dot"></span>
                    <span>ACTIVE</span>
                </div>
                <span class="risk-indicator risk-low" id="riskBadge" style="display:none">
                    <i class="fas fa-shield-alt mr-1"></i><span id="riskText">LOW</span>
                </span>
            </div>

            <!-- Center: Status & Timeout Alerts -->
            <div class="timer-center-status">
                <span class="timer-timeouts-msg" id="timeoutMsg">
                    <i class="fas fa-exclamation-triangle mr-1"></i> Time expired &mdash; submitting...
                </span>
            </div>

            <!-- Right: Glassmorphic Per-Quiz/Question Timer Card -->
            <div class="timer-card-glass" id="questionTimerCard">
                <div class="timer-card-header">
                    <div class="timer-icon-badge question-badge">
                        <i class="fas fa-stopwatch"></i>
                    </div>
                    <div class="timer-meta-info">
                        <span class="timer-badge-label">Question Timer</span>
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
                    <div class="q-meta-info" style="font-weight: 600; font-size: 14px;">
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

            if (data.completed) {
                clearInterval(timerInterval);
                submitted = true;
                showResults(data.score);
            } else if (data.next_question) {
                currentIndex = data.progress.current;
                
                // Set the new time limit based on the next question
                TIME_LIMIT = data.next_question.time_limit || 60;
                timeLeft = data.time_remaining !== undefined && data.time_remaining > 0 ? data.time_remaining : TIME_LIMIT;
                
                submitted = false; // Resume logic for new question
                
                renderQuestion(data.next_question);
                
                clearInterval(timerInterval);
                
                // Update UI once before setInterval to prevent visual delay/jump
                tickTimer(); 
                timerInterval = setInterval(tickTimer, 1000);
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
    let lastEventTimes = {};
    let assessmentReady = false;

    // Grace period of 3 seconds after page load before recording anti-cheat events
    setTimeout(() => {
        assessmentReady = true;
    }, 3000);

    function trackEvent(eventType, data) {
        if (submitted || !assessmentReady) return;

        // Throttle rapid triggers of the same event type (minimum 3s gap)
        const now = Date.now();
        if (lastEventTimes[eventType] && (now - lastEventTimes[eventType] < 3000)) {
            return;
        }
        lastEventTimes[eventType] = now;

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
            document.documentElement.requestFullscreen().then(() => {
                isAntiCheatActive = true;
            }).catch(() => {});
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

    // Window blur (only when document is hidden to avoid false triggers from browser UI)
    window.addEventListener('blur', () => {
        if (!submitted && document.hidden) trackEvent('BLUR');
    });

    // Fullscreen exit
    document.addEventListener('fullscreenchange', () => {
        if (!document.fullscreenElement) {
            if (isAntiCheatActive && !submitted && assessmentReady) {
                trackEvent('FULLSCREEN_EXIT');
                showWarningOverlay('Exiting fullscreen mode');
            }
            isAntiCheatActive = false;
        } else {
            isAntiCheatActive = true;
        }
    });

    // Request fullscreen on first interaction
    window.addEventListener('load', () => {
        document.body.addEventListener('click', function enableFS() {
            if (!isAntiCheatActive && document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().then(() => {
                    isAntiCheatActive = true;
                }).catch(() => {});
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
