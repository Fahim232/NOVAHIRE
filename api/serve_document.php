<?php
/**
 * Secure Document Serving Endpoint
 * Provides authenticated, IDOR-protected, streaming access to sensitive candidate documents.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/hiring_workflow.php';
global $con;

// Determine authenticated actor
$actor_type = null;
$actor_id = null;

if (isset($_SESSION['company_id'])) {
    $actor_type = 'company';
    $actor_id = intval($_SESSION['company_id']);
} elseif (isset($_SESSION['user_id']) || isset($_SESSION['id'])) {
    $actor_type = 'user';
    $actor_id = intval($_SESSION['user_id'] ?? $_SESSION['id']);
} elseif (isset($_SESSION['staff_id'])) {
    $actor_type = 'staff';
    $actor_id = intval($_SESSION['staff_id']);
} else {
    http_response_code(401);
    die("Access denied. Please log in to view documents.");
}

$doc_id = intval($_GET['id'] ?? 0);
if ($doc_id <= 0) {
    http_response_code(400);
    die("Invalid document identifier.");
}

// Anti-IDOR Authorization Check
$auth_res = verify_document_access($con, $actor_type, $actor_id, $doc_id);
if (!$auth_res['allowed']) {
    http_response_code(403);
    die(htmlspecialchars($auth_res['error'] ?? "Access forbidden."));
}

$doc = $auth_res['document'];
$filename = basename($doc['file_path'] ?? $doc['file_name'] ?? '');
$storage_dir = realpath(__DIR__ . '/../uploads/hiring_documents');
$file_path = $storage_dir . DIRECTORY_SEPARATOR . $filename;

// Ensure path is strictly within the private documents folder
$real_file_path = realpath($file_path);
if (!$real_file_path || strpos($real_file_path, $storage_dir) !== 0 || !is_file($real_file_path)) {
    http_response_code(404);
    die("The requested document could not be found on disk.");
}

// Clean output buffer before sending file
if (ob_get_level()) {
    ob_end_clean();
}

$mime = ($doc['mime_type'] ?? $doc['file_type'] ?? '') ?: 'application/octet-stream';
$download = isset($_GET['download']) && $_GET['download'] == '1';
$disposition = $download ? 'attachment' : 'inline';
$display_name = !empty($doc['original_filename']) ? basename($doc['original_filename']) : (!empty($doc['original_name']) ? basename($doc['original_name']) : $filename);

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($display_name) . '"');
header('Content-Length: ' . filesize($real_file_path));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

readfile($real_file_path);
exit;
