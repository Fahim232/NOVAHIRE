<?php
/**
 * API Endpoint: Mark Single Notification Read
 * 
 * Validates ownership and marks a specific notification record as read.
 */

// Initialize session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON output header
header('Content-Type: application/json');

// Validate authentication
$user_id = intval($_SESSION['id'] ?? 0);
$company_id = intval($_SESSION['company_id'] ?? 0);
$admin_id = intval($_SESSION['admin_id'] ?? 0);

if ($user_id > 0) {
    $recipient_type = 'user';
    $recipient_id = $user_id;
} elseif ($company_id > 0) {
    $recipient_type = 'company';
    $recipient_id = $company_id;
} elseif ($admin_id > 0) {
    $recipient_type = 'admin';
    $recipient_id = $admin_id;
} else {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../includes/functions.php';

$notif_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($notif_id > 0) {
    // Verify notification ownership using prepared statement
    $check = mysqli_prepare($con, "SELECT id FROM notifications WHERE id = ? AND recipient_type = ? AND recipient_id = ?");
    mysqli_stmt_bind_param($check, "isi", $notif_id, $recipient_type, $recipient_id);
    mysqli_stmt_execute($check);
    $res = mysqli_stmt_get_result($check);
    $owned = mysqli_num_rows($res) > 0;
    mysqli_stmt_close($check);

    if ($owned) {
        $result = mark_read($con, $notif_id);
    } else {
        $result = false;
    }
    echo json_encode(['success' => $result]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
}
?>
