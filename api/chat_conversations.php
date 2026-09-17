<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

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

if (!$is_company && !$is_user) {
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit();
}

$current_id = $is_company ? intval($_SESSION['company_id']) : intval($_SESSION['id']);
$current_type = $is_company ? 'company' : 'user';
$opposite_type = $is_company ? 'user' : 'company';

$sql = "SELECT 
    CASE 
        WHEN sender_type = ? THEN receiver_id
        ELSE sender_id
    END as opposite_id,
    MAX(created_at) as last_time,
    (SELECT COUNT(*) FROM live_chats lc2 
     WHERE lc2.sender_type = ? 
     AND lc2.sender_id = CASE WHEN lc.sender_type = ? THEN lc.receiver_id ELSE lc.sender_id END
     AND lc2.receiver_type = ? AND lc2.receiver_id = ? 
     AND lc2.is_read = 0) as unread
FROM live_chats lc
WHERE (sender_type = ? AND sender_id = ?)
   OR (receiver_type = ? AND receiver_id = ?)
GROUP BY opposite_id
ORDER BY last_time DESC";

$stmt = mysqli_prepare($con, $sql);
mysqli_stmt_bind_param($stmt, "ssssisisi", 
    $current_type, 
    $opposite_type, 
    $current_type, 
    $current_type, 
    $current_id, 
    $current_type, 
    $current_id, 
    $current_type, 
    $current_id
);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($is_company) {
    $detail_stmt = mysqli_prepare($con, "SELECT id, username, profile FROM user_info WHERE id = ?");
    $last_msg_stmt = mysqli_prepare($con, "SELECT message, sender_type FROM live_chats WHERE (sender_type = 'company' AND sender_id = ? AND receiver_type = 'user' AND receiver_id = ?) OR (sender_type = 'user' AND sender_id = ? AND receiver_type = 'company' AND receiver_id = ?) ORDER BY created_at DESC LIMIT 1");
} else {
    $detail_stmt = mysqli_prepare($con, "SELECT id, company_name, logo FROM companies WHERE id = ?");
}

$conversations = [];
while ($row = mysqli_fetch_assoc($result)) {
    $opp_id = intval($row['opposite_id']);
    if ($opp_id <= 0) continue;
    
    mysqli_stmt_bind_param($detail_stmt, "i", $opp_id);
    mysqli_stmt_execute($detail_stmt);
    $det_res = mysqli_stmt_get_result($detail_stmt);
    $det = mysqli_fetch_assoc($det_res);
    if (!$det) continue;
    
    if ($is_company) {
        mysqli_stmt_bind_param($last_msg_stmt, "iiii", $current_id, $opp_id, $opp_id, $current_id);
        mysqli_stmt_execute($last_msg_stmt);
        $last_res = mysqli_stmt_get_result($last_msg_stmt);
        $last_row = mysqli_fetch_assoc($last_res);
        $last_message = $last_row ? $last_row['message'] : '';
        $last_sender = $last_row ? $last_row['sender_type'] : '';

        $conversations[] = [
            'user_id'      => $opp_id,
            'username'     => $det['username'],
            'profile'      => $det['profile'] ?? '',
            'last_time'    => $row['last_time'],
            'unread'       => intval($row['unread']),
            'last_message' => $last_message,
            'last_sender'  => $last_sender
        ];
    } else {
        $conversations[] = [
            'company_id'   => $opp_id,
            'company_name' => $det['company_name'],
            'logo'         => $det['logo'] ?? '',
            'last_time'    => $row['last_time'],
            'unread'       => intval($row['unread'])
        ];
    }
}

if ($is_company) {
    mysqli_stmt_close($last_msg_stmt);
}
mysqli_stmt_close($detail_stmt);
mysqli_stmt_close($stmt);

echo json_encode(['success' => true, 'conversations' => $conversations]);
