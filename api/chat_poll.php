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

$since     = isset($_GET['since']) ? intval($_GET['since']) : 0;
$with_type = isset($_GET['with_type']) ? trim($_GET['with_type']) : '';
$with_id   = isset($_GET['with_id']) ? intval($_GET['with_id']) : 0;

if (empty($with_type) || $with_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid conversation target']);
    exit();
}

$mark_stmt = mysqli_prepare($con, "UPDATE live_chats SET is_read = 1 WHERE sender_type = ? AND sender_id = ? AND receiver_type = ? AND receiver_id = ? AND is_read = 0");
mysqli_stmt_bind_param($mark_stmt, "sisi", $with_type, $with_id, $current_type, $current_id);
mysqli_stmt_execute($mark_stmt);
mysqli_stmt_close($mark_stmt);

if ($since > 0) {
    $sql_stmt = mysqli_prepare($con, "SELECT id, sender_type, sender_id, message, is_read, created_at FROM live_chats WHERE ((sender_type = ? AND sender_id = ? AND receiver_type = ? AND receiver_id = ?) OR (sender_type = ? AND sender_id = ? AND receiver_type = ? AND receiver_id = ?)) AND id > ? ORDER BY created_at ASC LIMIT 100");
    mysqli_stmt_bind_param($sql_stmt, "sisisisii", $current_type, $current_id, $with_type, $with_id, $with_type, $with_id, $current_type, $current_id, $since);
} else {
    $sql_stmt = mysqli_prepare($con, "SELECT id, sender_type, sender_id, message, is_read, created_at FROM live_chats WHERE ((sender_type = ? AND sender_id = ? AND receiver_type = ? AND receiver_id = ?) OR (sender_type = ? AND sender_id = ? AND receiver_type = ? AND receiver_id = ?)) ORDER BY created_at ASC LIMIT 100");
    mysqli_stmt_bind_param($sql_stmt, "sisisisi", $current_type, $current_id, $with_type, $with_id, $with_type, $with_id, $current_type, $current_id);
}

mysqli_stmt_execute($sql_stmt);
$result = mysqli_stmt_get_result($sql_stmt);

$messages = [];
while ($row = mysqli_fetch_assoc($result)) {
    $messages[] = [
        'id'          => intval($row['id']),
        'sender_type' => $row['sender_type'],
        'sender_id'   => intval($row['sender_id']),
        'message'     => $row['message'],
        'is_read'     => intval($row['is_read']),
        'created_at'  => $row['created_at']
    ];
}
mysqli_stmt_close($sql_stmt);

echo json_encode(['success' => true, 'messages' => $messages]);
