<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

if (!isset($_SESSION['id']) && !isset($_SESSION['company_id'])) {
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit();
}

include __DIR__ . '/../admin/dbcon.php';
include __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$is_company = isset($_SESSION['company_id']);
$is_user = isset($_SESSION['id']);

if ($is_company && $is_user) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referer, '/company/') !== false) {
        $is_user = false;
    } else {
        $is_company = false;
    }
}

$current_id = $is_company ? intval($_SESSION['company_id']) : intval($_SESSION['id']);
$current_type = $is_company ? 'company' : 'user';

$receiver_type = trim($_POST['receiver_type'] ?? '');
$receiver_id   = intval($_POST['receiver_id'] ?? 0);
$message       = trim($_POST['message'] ?? '');

if (empty($receiver_type) || $receiver_id <= 0 || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Invalid message data']);
    exit();
}

$stmt = mysqli_prepare($con, "INSERT INTO live_chats (sender_type, sender_id, receiver_type, receiver_id, message) VALUES (?, ?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt, "sisis", $current_type, $current_id, $receiver_type, $receiver_id, $message);
$result = mysqli_stmt_execute($stmt);
$msg_id = mysqli_insert_id($con);
mysqli_stmt_close($stmt);

if ($result) {
    $excerpt = mb_substr($message, 0, 150) . (mb_strlen($message) > 150 ? '...' : '');
    
    if ($current_type === 'user') {
        $sender_name = trim($_SESSION['username'] ?? '');
        if ($sender_name === '') {
            $un = mysqli_prepare($con, "SELECT username FROM user_info WHERE id = ?");
            mysqli_stmt_bind_param($un, "i", $current_id);
            mysqli_stmt_execute($un);
            $ur = mysqli_stmt_get_result($un);
            if ($ur && $urow = mysqli_fetch_assoc($ur)) {
                $sender_name = $urow['username'];
            } else {
                $sender_name = 'Job Seeker';
            }
            mysqli_stmt_close($un);
        }
        create_notification($con, 'company', $receiver_id, 'user', $current_id,
            'New live chat message from ' . $sender_name, $excerpt, 'message', 'live_chats', $msg_id);
    } else {
        $sender_name = trim($_SESSION['company_name'] ?? '');
        if ($sender_name === '') {
            $un = mysqli_prepare($con, "SELECT company_name FROM companies WHERE id = ?");
            mysqli_stmt_bind_param($un, "i", $current_id);
            mysqli_stmt_execute($un);
            $ur = mysqli_stmt_get_result($un);
            if ($ur && $urow = mysqli_fetch_assoc($ur)) {
                $sender_name = $urow['company_name'];
            } else {
                $sender_name = 'Company';
            }
            mysqli_stmt_close($un);
        }
        create_notification($con, 'user', $receiver_id, 'company', $current_id,
            'New live chat message from ' . $sender_name, $excerpt, 'message', 'live_chats', $msg_id);
    }

    echo json_encode(['success' => true, 'id' => $msg_id]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send message']);
}
