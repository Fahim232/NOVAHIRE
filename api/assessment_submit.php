<?php
/**
 * NovaHire — Assessment Answer Submission API
 * POST endpoint: submit a single answer, get the next question or completion.
 *
 * Receives:  session_id, question_id, answer
 * Returns:   JSON {ok, next_question: {...}|null, completed, progress, time_remaining}
 *
 * Security:
 *   - Server-side time validation (rejects if session has expired)
 *   - IDOR protection (session must belong to the logged-in user)
 *   - MCQ answers graded instantly; short answers queued for AI grading at completion
 *   - Correct answers are NEVER returned to the client
 */
session_start();
header('Content-Type: application/json');

// Ensure PHP timezone matches MySQL server (+06:00 Asia/Dhaka)
date_default_timezone_set('Asia/Dhaka');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../admin/dbcon.php';

$user_id     = intval($_SESSION['id']);
$session_id  = isset($_POST['session_id'])  ? intval($_POST['session_id'])  : 0;
$question_id = isset($_POST['question_id']) ? intval($_POST['question_id']) : 0;
$answer      = isset($_POST['answer'])      ? trim($_POST['answer'])        : '';

if ($session_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing session_id']);
    exit;
}

// ── 1. Validate session ownership + active status ──
$stmt = mysqli_prepare($con, "SELECT *, UNIX_TIMESTAMP(started_at) as session_started_ts, UNIX_TIMESTAMP(current_question_started_at) as question_started_ts FROM assessment_sessions WHERE id = ? AND user_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $session_id, $user_id);
mysqli_stmt_execute($stmt);
$sess_result = mysqli_stmt_get_result($stmt);

if (!$sess_result || mysqli_num_rows($sess_result) === 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}

$session = mysqli_fetch_assoc($sess_result);

if ($session['status'] !== 'in_progress') {
    echo json_encode(['ok' => false, 'error' => 'Session is no longer active', 'terminated' => true]);
    exit;
}

// ── 2. Server-side time enforcement (per question) ──
$started_ts     = !empty($session['question_started_ts']) ? intval($session['question_started_ts']) : time();
$elapsed        = max(0, time() - $started_ts);
$time_remaining = 0;

// ── 3. Record the answer (if a question_id was provided) ──
if ($question_id > 0) {
    // Verify this question belongs to the session's response set
    $check_stmt = mysqli_prepare($con, "SELECT ar.id, cjq.question_type, cjq.correct_answer, cjq.ideal_answer, cjq.question, cjq.time_limit, cjq.marks
                                         FROM assessment_responses ar
                                         JOIN company_job_questions cjq ON cjq.id = ar.question_id
                                         WHERE ar.session_id = ? AND ar.question_id = ?");
    mysqli_stmt_bind_param($check_stmt, "ii", $session_id, $question_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if ($check_result && mysqli_num_rows($check_result) > 0) {
        $resp_row = mysqli_fetch_assoc($check_result);
        $q_type = $resp_row['question_type'];
        $q_time_limit = intval($resp_row['time_limit']);
        
        // Server-side enforcement: if elapsed time > time_limit + grace period (e.g., 5s), mark answer empty
        if ($elapsed > $q_time_limit + 5) {
            $answer = ''; // Reject their answer, they took too long
        }

        $is_correct = null;
        $ai_score   = null;

        if ($q_type === 'mcq') {
            // Normalize correct answer
            $correct_val = $resp_row['correct_answer'];
            // Grade MCQ immediately — compare text values
            $is_correct = ($answer === $correct_val) ? 1 : 0;
        }
        // Short answers will be graded at session completion

        // Update the response
        $safe_answer = mysqli_real_escape_string($con, $answer);
        $upd = mysqli_prepare($con,
            "UPDATE assessment_responses SET user_answer = ?, is_correct = ?, answered_at = NOW() WHERE session_id = ? AND question_id = ?"
        );
        mysqli_stmt_bind_param($upd, "siii", $safe_answer, $is_correct, $session_id, $question_id);
        mysqli_stmt_execute($upd);
    }

    // Advance the current_index
    mysqli_query($con, "UPDATE assessment_sessions SET current_index = current_index + 1 WHERE id = $session_id");
}

// ── 4. Determine the next question ──
$order_json = $session['question_order'];
$question_order = json_decode($order_json, true);

if (!is_array($question_order)) {
    echo json_encode(['ok' => false, 'error' => 'Corrupted question order']);
    exit;
}

// Re-fetch current_index after increment
$idx_result = mysqli_query($con, "SELECT current_index FROM assessment_sessions WHERE id = $session_id");
$idx_row = mysqli_fetch_assoc($idx_result);
$current_index = intval($idx_row['current_index']);

$total = count($question_order);
$completed = ($current_index >= $total);

$next_question = null;
if (!$completed) {
    $next_qid = intval($question_order[$current_index]);
    $q_stmt = mysqli_prepare($con, "SELECT id, question_type, question, option1, option2, option3, option4, time_limit, marks FROM company_job_questions WHERE id = ?");
    mysqli_stmt_bind_param($q_stmt, "i", $next_qid);
    mysqli_stmt_execute($q_stmt);
    $q_result = mysqli_stmt_get_result($q_stmt);

    if ($q_result && mysqli_num_rows($q_result) > 0) {
        $q_data = mysqli_fetch_assoc($q_result);

        $next_question = [
            'id'            => intval($q_data['id']),
            'question_type' => $q_data['question_type'],
            'question'      => $q_data['question'],
            'question_number' => $current_index + 1,
            'time_limit'    => intval($q_data['time_limit']),
            'marks'         => intval($q_data['marks']),
        ];

        // Update the session's current_question_started_at to NOW() since we are serving a new question
        mysqli_query($con, "UPDATE assessment_sessions SET current_question_started_at = NOW() WHERE id = $session_id");

        // Only include options for MCQ — NEVER include correct_answer
        if ($q_data['question_type'] === 'mcq') {
            $options = [$q_data['option1'], $q_data['option2'], $q_data['option3'], $q_data['option4']];
            // Shuffle options for extra randomness
            shuffle($options);
            $next_question['options'] = $options;
        }
    }
}

// ── 5. If completed, finalize the session ──
if ($completed) {
    // Grade short answers with AI
    require_once __DIR__ . '/../ai/assessment_grader.php';

    $short_stmt = mysqli_prepare($con,
        "SELECT ar.id, ar.question_id, ar.user_answer, cjq.ideal_answer, cjq.question
         FROM assessment_responses ar
         JOIN company_job_questions cjq ON cjq.id = ar.question_id
         WHERE ar.session_id = ? AND cjq.question_type = 'short_answer'"
    );
    mysqli_stmt_bind_param($short_stmt, "i", $session_id);
    mysqli_stmt_execute($short_stmt);
    $short_result = mysqli_stmt_get_result($short_stmt);

    while ($sa = mysqli_fetch_assoc($short_result)) {
        $grade = ai_grade_short_answer(
            $sa['user_answer'],
            $sa['ideal_answer'],
            $sa['question']
        );

        $ai_score    = $grade['score'] !== null ? intval($grade['score']) : null;
        $ai_feedback = mysqli_real_escape_string($con, $grade['feedback']);
        $is_correct_sa = ($ai_score !== null && $ai_score >= 50) ? 1 : 0;

        $upd_sa = "UPDATE assessment_responses SET ai_score = " . ($ai_score !== null ? $ai_score : "NULL") .
                  ", ai_feedback = '$ai_feedback', is_correct = $is_correct_sa WHERE id = " . intval($sa['id']);
        mysqli_query($con, $upd_sa);
    }

    // Calculate scores based on marks
    // MCQ Score (Sum of marks for correct MCQs)
    $mcq_q = mysqli_query($con,
        "SELECT SUM(cjq.marks) as total_marks, SUM(CASE WHEN ar.is_correct = 1 THEN cjq.marks ELSE 0 END) as earned_marks
         FROM assessment_responses ar
         JOIN company_job_questions cjq ON cjq.id = ar.question_id
         WHERE ar.session_id = $session_id AND cjq.question_type = 'mcq'"
    );
    $mcq_row = mysqli_fetch_assoc($mcq_q);
    $mcq_total_marks = intval($mcq_row['total_marks']);
    $mcq_earned_marks = intval($mcq_row['earned_marks']);
    $score_mcq = $mcq_total_marks > 0 ? round(($mcq_earned_marks / $mcq_total_marks) * 100, 2) : null;

    // Short answer Score (Sum of marks based on AI percentage)
    $sa_q = mysqli_query($con,
        "SELECT SUM(cjq.marks) as total_marks, SUM(cjq.marks * (ar.ai_score / 100)) as earned_marks
         FROM assessment_responses ar
         JOIN company_job_questions cjq ON cjq.id = ar.question_id
         WHERE ar.session_id = $session_id AND cjq.question_type = 'short_answer' AND ar.ai_score IS NOT NULL"
    );
    $sa_row = mysqli_fetch_assoc($sa_q);
    $sa_total_marks = intval($sa_row['total_marks']);
    $sa_earned_marks = round(floatval($sa_row['earned_marks']), 2);
    $score_short = $sa_total_marks > 0 ? round(($sa_earned_marks / $sa_total_marks) * 100, 2) : null;

    // Final composite based on actual total marks
    $total_possible_marks = $mcq_total_marks + $sa_total_marks;
    $total_earned_marks = $mcq_earned_marks + $sa_earned_marks;
    
    if ($total_possible_marks > 0) {
        $score_final = round(($total_earned_marks / $total_possible_marks) * 100, 2);
    } else {
        $score_final = 0;
    }

    // Update session
    $upd_sess = "UPDATE assessment_sessions SET
                    status = 'completed',
                    completed_at = NOW(),
                    score_mcq = " . ($score_mcq !== null ? $score_mcq : "NULL") . ",
                    score_short = " . ($score_short !== null ? $score_short : "NULL") . ",
                    score_final = $score_final
                 WHERE id = $session_id";
    mysqli_query($con, $upd_sess);

    // Sync back to legacy tables (job_quiz_attempts + job_applications)
    $job_id = intval($session['job_id']);
    $application_id = intval($session['application_id']);
    $quiz_status = ($score_final >= 60) ? 'passed' : 'failed';
    $time_taken = time() - $started_ts;

    // Total questions answered
    $total_answered_q = mysqli_query($con, "SELECT COUNT(*) as c FROM assessment_responses WHERE session_id = $session_id AND user_answer IS NOT NULL");
    $total_answered = intval(mysqli_fetch_assoc($total_answered_q)['c']);

    // Total correct (MCQ correct + short answers with ai_score >= 50)
    $total_correct_q = mysqli_query($con, "SELECT COUNT(*) as c FROM assessment_responses WHERE session_id = $session_id AND is_correct = 1");
    $total_correct = intval(mysqli_fetch_assoc($total_correct_q)['c']);

    if ($application_id > 0) {
        // Update application
        $upd_app = "UPDATE job_applications SET quiz_score = '$score_final', quiz_status = '$quiz_status' WHERE id = $application_id";
        mysqli_query($con, $upd_app);

        // Insert quiz attempt
        $ins_attempt = "INSERT INTO job_quiz_attempts (application_id, job_id, user_id, total_questions, correct_answers, score_percentage, time_taken, attempt_date)
                        VALUES ($application_id, $job_id, $user_id, $total, $total_correct, $score_final, $time_taken, NOW())";
        mysqli_query($con, $ins_attempt);
    }

    // Update user_quiz_status for grooming flow compatibility
    $job_cat_q = mysqli_query($con, "SELECT job_category FROM company_jobs WHERE id = $job_id");
    if ($job_cat_q && mysqli_num_rows($job_cat_q) > 0) {
        $category = mysqli_real_escape_string($con, mysqli_fetch_assoc($job_cat_q)['job_category']);
        $check_uqs = mysqli_query($con, "SELECT id FROM user_quiz_status WHERE user_id = $user_id AND category = '$category'");
        if (mysqli_num_rows($check_uqs) > 0) {
            mysqli_query($con, "UPDATE user_quiz_status SET status = '$quiz_status', last_attempt = NOW() WHERE user_id = $user_id AND category = '$category'");
        } else {
            mysqli_query($con, "INSERT INTO user_quiz_status (user_id, category, status, grooming_completed, last_attempt) VALUES ($user_id, '$category', '$quiz_status', 0, NOW())");
        }
    }

    // Set session locks
    $_SESSION['quiz_taken_' . $job_id] = true;
    $_SESSION['quiz_submitted_' . $job_id] = true;
}

// ── 6. Response ──
echo json_encode([
    'ok'             => true,
    'completed'      => $completed,
    'progress'       => [
        'current'    => $current_index,
        'total'      => $total,
    ],
    'time_remaining' => $time_remaining,
    'next_question'  => $next_question,
    'score'          => $completed ? [
        'final'      => $score_final ?? null,
        'mcq'        => $score_mcq ?? null,
        'short'      => $score_short ?? null,
        'status'     => $quiz_status ?? null,
    ] : null,
]);
