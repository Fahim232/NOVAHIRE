<?php
/**
 * AJAX endpoint for generating a cover letter using the existing AI engine.
 * Called from company_job_application.php via fetch().
 * 
 * POST params: job_id, tone (optional)
 * Returns JSON: {success, content, title, mode}
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../ai/cover_letter.php';

// Auth check
if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$user_id = intval($_SESSION['id']);
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

$job_id  = intval($input['job_id'] ?? 0);
$tone    = in_array($input['tone'] ?? '', ['professional','formal','friendly','confident']) 
           ? $input['tone'] : 'professional';

if ($job_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid job ID']);
    exit;
}

// Fetch user
$user_q = mysqli_query($con, "SELECT * FROM user_info WHERE id = '$user_id'");
$user = mysqli_fetch_assoc($user_q);
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// Fetch job with company info
$job_q = mysqli_query($con, "SELECT cj.*, c.company_name FROM company_jobs cj 
                             JOIN companies c ON cj.company_id = c.id 
                             WHERE cj.id = '$job_id'");
$job = mysqli_fetch_assoc($job_q);
if (!$job) {
    echo json_encode(['success' => false, 'error' => 'Job not found']);
    exit;
}

// Generate using existing engine
$letter = ai_generate_cover_letter($user, $job, $tone);

if (empty($letter['content'])) {
    echo json_encode(['success' => false, 'error' => 'Failed to generate cover letter']);
    exit;
}

// Save to ai_cover_letters table (existing table)
$letter_id = 0;
$stmt = mysqli_prepare($con, "INSERT INTO ai_cover_letters (user_id, job_id, title, content, mode) VALUES (?, ?, ?, ?, ?)");
if ($stmt) {
    $title   = $letter['title'];
    $content = $letter['content'];
    $mode    = $letter['mode'];
    mysqli_stmt_bind_param($stmt, "iisss", $user_id, $job_id, $title, $content, $mode);
    mysqli_stmt_execute($stmt);
    $letter_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);
}

echo json_encode([
    'success'      => true,
    'content'      => $letter['content'],
    'cover_letter' => $letter['content'],
    'title'        => $letter['title'],
    'mode'         => $letter['mode'],
    'letter_id'    => $letter_id,
    'score'        => $letter['score'] ?? 0,
]);
