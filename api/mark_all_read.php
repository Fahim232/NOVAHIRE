<?php
/**
 * API Endpoint: Mark All Notifications Read
 * 
 * Marks all pending unread notifications as read for the logged-in job seeker.
 */

// Initialize session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Return JSON header
header('Content-Type: application/json');

// Session authentication check
$user_id = intval($_SESSION['id'] ?? 0);
$company_id = intval($_SESSION['company_id'] ?? 0);
$admin_id = intval($_SESSION['admin_id'] ?? 0);

require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../includes/functions.php';

if ($user_id > 0) {
    $result = mark_all_read($con, 'user', $user_id);
} elseif ($company_id > 0) {
    $result = mark_all_read($con, 'company', $company_id);
} elseif ($admin_id > 0) {
    $result = mark_all_read($con, 'admin', $admin_id);
} else {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

echo json_encode(['success' => $result]);
?>
