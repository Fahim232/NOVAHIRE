<?php
/**
 * NovaHire — Secure Verification Document Serving Endpoint
 * 
 * Provides authenticated, IDOR-protected, anti-path-traversal access 
 * to company business verification documents (Trade License, Incorporation Certificates, etc.).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/company_verification.php';
global $con;

// 1. Identify authenticated actor
$actor_type = null;
$actor_id = null;

if (isset($_SESSION['admin_username'])) {
    $actor_type = 'admin';
    $actor_id = intval($_SESSION['admin_id'] ?? 0);
} elseif (isset($_SESSION['company_id'])) {
    $actor_type = 'company';
    $actor_id = intval($_SESSION['company_id']);
} else {
    http_response_code(401);
    die("Access denied. Please log in to view verification documents.");
}

// 2. Validate input parameter
$doc_id = intval($_GET['id'] ?? 0);
if ($doc_id <= 0) {
    http_response_code(400);
    die("Invalid document identifier.");
}

// 3. Anti-IDOR Authorization Check
$auth = nh_can_access_verification_document($con, $actor_type, $actor_id, $doc_id);
if (!$auth['allowed']) {
    http_response_code(403);
    die(htmlspecialchars($auth['error'] ?? "Access forbidden."));
}

$doc = $auth['document'];
$filename = basename($doc['file_path'] ?? '');
if (empty($filename)) {
    http_response_code(404);
    die("Invalid file reference.");
}

$storage_dir = realpath(nh_verification_docs_dir());
$file_path = $storage_dir . DIRECTORY_SEPARATOR . $filename;
$real_file_path = realpath($file_path);

// 4. Strict Path Traversal Check
if (!$real_file_path || strpos($real_file_path, $storage_dir) !== 0 || !is_file($real_file_path)) {
    http_response_code(404);
    die("The requested verification file does not exist on disk.");
}

// 5. Clean output buffer before streaming
if (ob_get_level()) {
    ob_end_clean();
}

$mime = !empty($doc['mime_type']) ? $doc['mime_type'] : 'application/octet-stream';
$download = isset($_GET['download']) && $_GET['download'] == '1';
$disposition = $download ? 'attachment' : 'inline';
$display_name = !empty($doc['original_filename']) ? basename($doc['original_filename']) : $filename;

// 6. Security Headers & Stream
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($display_name) . '"');
header('Content-Length: ' . filesize($real_file_path));
header('Cache-Control: private, no-transform, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

readfile($real_file_path);
exit;
