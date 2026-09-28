<?php
/**
 * api/skill_assessments.php
 * Handles starting and submitting platform skill assessments.
 */
session_start();
require_once '../admin/dbcon.php';
global $con;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_GET['action'] ?? '';
$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($action === 'start') {
    $assessment_id = isset($_POST['assessment_id']) ? (int)$_POST['assessment_id'] : 0;
    
    // Check if assessment exists and check premium
    $sql = "SELECT is_premium, time_limit, status FROM skill_assessments WHERE id = ?";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "i", $assessment_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $assessment = mysqli_fetch_assoc($result);
    
    if (!$assessment || $assessment['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Assessment not found or inactive']);
        exit;
    }
    
    if ($assessment['is_premium']) {
        // Check if user is PRO
        $sql_pro = "SELECT is_pro FROM user_info WHERE id = ?";
        $stmt_pro = mysqli_prepare($con, $sql_pro);
        mysqli_stmt_bind_param($stmt_pro, "i", $user_id);
        mysqli_stmt_execute($stmt_pro);
        $user_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_pro));
        
        if (!$user_data || $user_data['is_pro'] != 1) {
            echo json_encode(['success' => false, 'message' => 'This assessment requires a PRO subscription.', 'code' => 'REQUIRES_PRO']);
            exit;
        }
    }
    
    // Create attempt
    $sql_attempt = "INSERT INTO skill_assessment_attempts (user_id, assessment_id, status) VALUES (?, ?, 'in_progress')";
    $stmt_attempt = mysqli_prepare($con, $sql_attempt);
    mysqli_stmt_bind_param($stmt_attempt, "ii", $user_id, $assessment_id);
    mysqli_stmt_execute($stmt_attempt);
    $attempt_id = mysqli_insert_id($con);
    
    // Fetch questions
    $sql_q = "SELECT id, question, option1, option2, option3, option4 FROM skill_assessment_questions WHERE assessment_id = ? ORDER BY RAND()";
    $stmt_q = mysqli_prepare($con, $sql_q);
    mysqli_stmt_bind_param($stmt_q, "i", $assessment_id);
    mysqli_stmt_execute($stmt_q);
    $result_q = mysqli_stmt_get_result($stmt_q);
    
    $questions = [];
    while ($row = mysqli_fetch_assoc($result_q)) {
        $questions[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'attempt_id' => $attempt_id,
        'time_limit' => $assessment['time_limit'] > 100 ? round($assessment['time_limit'] / 60) : (int)$assessment['time_limit'],
        'questions' => $questions
    ]);
    exit;
}

if ($action === 'submit') {
    $attempt_id = isset($_POST['attempt_id']) ? (int)$_POST['attempt_id'] : 0;
    $answers_json = isset($_POST['answers']) ? $_POST['answers'] : '{}';
    $answers = json_decode($answers_json, true) ?: [];
    
    // Verify attempt belongs to user and is in_progress
    $sql = "SELECT a.id, a.assessment_id, a.status, s.skill_id, s.passing_score 
            FROM skill_assessment_attempts a
            JOIN skill_assessments s ON a.assessment_id = s.id
            WHERE a.id = ? AND a.user_id = ?";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $attempt_id, $user_id);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    
    if (!$attempt || $attempt['status'] !== 'in_progress') {
        echo json_encode(['success' => false, 'message' => 'Invalid or already completed attempt']);
        exit;
    }
    
    $assessment_id = $attempt['assessment_id'];
    $skill_id = $attempt['skill_id'];
    $passing_score = $attempt['passing_score'];
    
    // Fetch correct answers
    $sql_ans = "SELECT id, correct_answer FROM skill_assessment_questions WHERE assessment_id = ?";
    $stmt_ans = mysqli_prepare($con, $sql_ans);
    mysqli_stmt_bind_param($stmt_ans, "i", $assessment_id);
    mysqli_stmt_execute($stmt_ans);
    $result_ans = mysqli_stmt_get_result($stmt_ans);
    
    $total_questions = 0;
    $correct_count = 0;
    
    while ($row = mysqli_fetch_assoc($result_ans)) {
        $total_questions++;
        $q_id = $row['id'];
        if (isset($answers[$q_id]) && $answers[$q_id] === $row['correct_answer']) {
            $correct_count++;
        }
    }
    
    $score_percentage = $total_questions > 0 ? ($correct_count / $total_questions) * 100 : 0;
    
    // Determine level based on configurable thresholds (for now, hardcoded logic per requirements)
    $level = 'Needs Improvement';
    $passed = false;
    
    if ($score_percentage >= $passing_score) {
        $passed = true;
        if ($score_percentage >= 90) {
            $level = 'Expert';
        } elseif ($score_percentage >= 75) {
            $level = 'Advanced';
        } elseif ($score_percentage >= 60) {
            $level = 'Intermediate';
        } else {
            $level = 'Beginner';
        }
    }
    
    $status = $passed ? 'passed' : 'failed';
    
    // Update attempt
    $sql_upd = "UPDATE skill_assessment_attempts SET score = ?, status = ?, completed_at = NOW() WHERE id = ?";
    $stmt_upd = mysqli_prepare($con, $sql_upd);
    mysqli_stmt_bind_param($stmt_upd, "dsi", $score_percentage, $status, $attempt_id);
    mysqli_stmt_execute($stmt_upd);
    
    // Log progress
    $sql_prog = "INSERT INTO skill_progress (user_id, skill_id, assessment_id, score, skill_level) VALUES (?, ?, ?, ?, ?)";
    $stmt_prog = mysqli_prepare($con, $sql_prog);
    mysqli_stmt_bind_param($stmt_prog, "iiids", $user_id, $skill_id, $assessment_id, $score_percentage, $level);
    mysqli_stmt_execute($stmt_prog);
    
    // If passed, award/update verified skill
    if ($passed) {
        $sql_check_vs = "SELECT id FROM verified_skills WHERE user_id = ? AND skill_id = ?";
        $stmt_check_vs = mysqli_prepare($con, $sql_check_vs);
        mysqli_stmt_bind_param($stmt_check_vs, "ii", $user_id, $skill_id);
        mysqli_stmt_execute($stmt_check_vs);
        $result_vs = mysqli_stmt_get_result($stmt_check_vs);
        
        if (mysqli_num_rows($result_vs) > 0) {
            $vs_id = mysqli_fetch_assoc($result_vs)['id'];
            $sql_upd_vs = "UPDATE verified_skills SET assessment_id = ?, skill_level = ?, score = ? WHERE id = ?";
            $stmt_upd_vs = mysqli_prepare($con, $sql_upd_vs);
            mysqli_stmt_bind_param($stmt_upd_vs, "isdi", $assessment_id, $level, $score_percentage, $vs_id);
            mysqli_stmt_execute($stmt_upd_vs);
        } else {
            $sql_ins_vs = "INSERT INTO verified_skills (user_id, skill_id, assessment_id, skill_level, score) VALUES (?, ?, ?, ?, ?)";
            $stmt_ins_vs = mysqli_prepare($con, $sql_ins_vs);
            mysqli_stmt_bind_param($stmt_ins_vs, "iiisd", $user_id, $skill_id, $assessment_id, $level, $score_percentage);
            mysqli_stmt_execute($stmt_ins_vs);
        }
    }
    
    echo json_encode([
        'success' => true,
        'passed' => $passed,
        'score' => round($score_percentage, 2),
        'level' => $level,
        'message' => $passed ? "Your skill has been verified!" : "Your skill is not verified yet. Keep practicing!"
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;
