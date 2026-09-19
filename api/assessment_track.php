<?php
/**
 * NovaHire — Assessment Event Tracking API
 * POST endpoint for anti-cheating event logging.
 *
 * Receives:  session_id, event_type, event_data (optional JSON)
 * Returns:   JSON {ok, risk_score, risk_level, terminated}
 *
 * Risk weights per event type:
 *   TAB_SWITCH       => 15
 *   FULLSCREEN_EXIT  => 20
 *   BLUR             => 10
 *   COPY             => 25
 *   PASTE            => 25
 *   RIGHT_CLICK      => 5
 *   DEVTOOLS         => 30
 *   RESIZE           => 5
 */
session_start();
header('Content-Type: application/json');

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

$user_id    = intval($_SESSION['id']);
$session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
$event_type = isset($_POST['event_type']) ? strtoupper(trim($_POST['event_type'])) : '';
$event_data = isset($_POST['event_data']) ? trim($_POST['event_data']) : '';

if ($session_id <= 0 || $event_type === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing session_id or event_type']);
    exit;
}

// Validate session ownership + active status
$stmt = mysqli_prepare($con, "SELECT id, status, risk_score FROM assessment_sessions WHERE id = ? AND user_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $session_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) === 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}

$session = mysqli_fetch_assoc($result);

if ($session['status'] !== 'in_progress') {
    echo json_encode(['ok' => false, 'error' => 'Session is not active', 'terminated' => true]);
    exit;
}

// Risk weights
$risk_weights = [
    'TAB_SWITCH'      => 15,
    'FULLSCREEN_EXIT' => 20,
    'BLUR'            => 10,
    'COPY'            => 25,
    'PASTE'           => 25,
    'RIGHT_CLICK'     => 5,
    'DEVTOOLS'        => 30,
    'RESIZE'          => 5,
];

$weight = isset($risk_weights[$event_type]) ? $risk_weights[$event_type] : 10;

// Insert event
$safe_event_data = mysqli_real_escape_string($con, $event_data);
$stmt2 = mysqli_prepare($con, "INSERT INTO assessment_events (session_id, event_type, event_data, risk_weight) VALUES (?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt2, "issi", $session_id, $event_type, $safe_event_data, $weight);
mysqli_stmt_execute($stmt2);

// Update cumulative risk score
$new_risk = intval($session['risk_score']) + $weight;

// Determine risk level
if ($new_risk >= 100) {
    $risk_level = 'critical';
} elseif ($new_risk >= 60) {
    $risk_level = 'high';
} elseif ($new_risk >= 30) {
    $risk_level = 'medium';
} else {
    $risk_level = 'low';
}

$terminated = false;

// If critical, terminate the session
if ($risk_level === 'critical') {
    $stmt3 = mysqli_prepare($con, "UPDATE assessment_sessions SET risk_score = ?, risk_level = 'critical', status = 'terminated', completed_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt3, "ii", $new_risk, $session_id);
    mysqli_stmt_execute($stmt3);
    $terminated = true;
} else {
    $stmt3 = mysqli_prepare($con, "UPDATE assessment_sessions SET risk_score = ?, risk_level = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt3, "isi", $new_risk, $risk_level, $session_id);
    mysqli_stmt_execute($stmt3);
}

echo json_encode([
    'ok'          => true,
    'risk_score'  => $new_risk,
    'risk_level'  => $risk_level,
    'terminated'  => $terminated,
]);
