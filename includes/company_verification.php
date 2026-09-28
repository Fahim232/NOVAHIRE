<?php
/**
 * NovaHire — Company Verification & Evidence Service Layer
 * 
 * Provides verification state management, document submission,
 * anti-IDOR file serving checks, admin reviews, notifications, and access gating.
 */

if (defined('COMPANY_VERIFICATION_LOADED')) return;
define('COMPANY_VERIFICATION_LOADED', true);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/functions.php';

/* ── 1. Constants & Document Types ────────────────────────────────────────── */
define('VERIF_STATUS_PENDING', 'pending');
define('VERIF_STATUS_UNDER_REVIEW', 'under_review');
define('VERIF_STATUS_VERIFIED', 'verified');
define('VERIF_STATUS_REJECTED', 'rejected');
define('VERIF_STATUS_RESUBMISSION', 'resubmission_required');

/**
 * Return array of accepted verification document types
 */
function nh_verification_document_types() {
    return [
        'trade_license' => [
            'label' => 'Trade License',
            'desc'  => 'Valid municipal or city corporation trade license',
            'icon'  => 'fa-id-card'
        ],
        'incorporation_certificate' => [
            'label' => 'Certificate of Incorporation',
            'desc'  => 'RJSC / official corporate registration certificate',
            'icon'  => 'fa-building'
        ],
        'business_registration' => [
            'label' => 'Business Registration Certificate',
            'desc'  => 'Official government business registration document',
            'icon'  => 'fa-file-contract'
        ],
        'tax_certificate' => [
            'label' => 'TIN / VAT / Tax Certificate',
            'desc'  => 'Official tax identification / business TIN document',
            'icon'  => 'fa-file-invoice-dollar'
        ],
        'other' => [
            'label' => 'Other Legitimate Business Evidence',
            'desc'  => 'Other certified documents establishing legal entity status',
            'icon'  => 'fa-file-shield'
        ],
    ];
}

function nh_verification_document_type_label($type) {
    $types = nh_verification_document_types();
    return $types[$type]['label'] ?? ucwords(str_replace('_', ' ', $type));
}

/**
 * Get human-readable verification status details
 */
function nh_verification_status_info($status) {
    switch ($status) {
        case VERIF_STATUS_VERIFIED:
            return [
                'status' => VERIF_STATUS_VERIFIED,
                'label'  => 'Verified',
                'badge'  => 'badge-success',
                'color'  => '#059669',
                'bg'     => 'rgba(5, 150, 105, 0.1)',
                'icon'   => 'fa-circle-check',
                'title'  => 'Company Verified',
                'desc'   => 'Your company is fully verified. All recruitment and hiring features are unlocked.'
            ];
        case VERIF_STATUS_UNDER_REVIEW:
            return [
                'status' => VERIF_STATUS_UNDER_REVIEW,
                'label'  => 'Under Review',
                'badge'  => 'badge-warning',
                'color'  => '#d97706',
                'bg'     => 'rgba(217, 119, 6, 0.1)',
                'icon'   => 'fa-clock',
                'title'  => 'Verification Under Review',
                'desc'   => 'Your submitted evidence is currently under review by our administrator team.'
            ];
        case VERIF_STATUS_REJECTED:
            return [
                'status' => VERIF_STATUS_REJECTED,
                'label'  => 'Verification Rejected',
                'badge'  => 'badge-danger',
                'color'  => '#dc2626',
                'bg'     => 'rgba(220, 38, 38, 0.1)',
                'icon'   => 'fa-circle-xmark',
                'title'  => 'Verification Rejected',
                'desc'   => 'Your verification request was rejected. Please review the admin feedback and submit corrected evidence.'
            ];
        case VERIF_STATUS_RESUBMISSION:
            return [
                'status' => VERIF_STATUS_RESUBMISSION,
                'label'  => 'Action Required',
                'badge'  => 'badge-info',
                'color'  => '#0284c7',
                'bg'     => 'rgba(2, 132, 199, 0.1)',
                'icon'   => 'fa-circle-exclamation',
                'title'  => 'Additional Evidence Required',
                'desc'   => 'The administrator requested corrections or additional documentation.'
            ];
        case VERIF_STATUS_PENDING:
        default:
            return [
                'status' => VERIF_STATUS_PENDING,
                'label'  => 'Verification Required',
                'badge'  => 'badge-secondary',
                'color'  => '#ca8a04',
                'bg'     => 'rgba(202, 138, 4, 0.1)',
                'icon'   => 'fa-triangle-exclamation',
                'title'  => 'Verification Required',
                'desc'   => 'Please submit valid business registration documentation to unlock job posting and hiring.'
            ];
    }
}

/* ── 2. Company Verification State Fetchers ────────────────────────────────── */

/**
 * Fetch verification state row for a given company
 */
function nh_get_company_verification($con, $company_id) {
    $company_id = intval($company_id);
    if ($company_id <= 0) return null;

    $stmt = mysqli_prepare($con, "SELECT id, company_name, company_email, is_verified, verification_status, verified_at, verified_by, verification_rejected_at, verification_rejection_reason FROM companies WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "i", $company_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($row) {
        if (empty($row['verification_status'])) {
            $row['verification_status'] = !empty($row['is_verified']) ? VERIF_STATUS_VERIFIED : VERIF_STATUS_PENDING;
        }
    }
    return $row;
}

/**
 * Check if a company is verified
 */
function is_company_verified($con, $company_id) {
    $data = nh_get_company_verification($con, $company_id);
    if (!$data) return false;
    return ($data['verification_status'] === VERIF_STATUS_VERIFIED && (int)$data['is_verified'] === 1);
}

/**
 * Core Authorization Middleware / Guard: require_company_verified()
 * Blocks unverified companies from accessing protected endpoints.
 */
function require_company_verified($con = null, $redirect = true) {
    if (!$con) {
        global $con;
    }
    
    // Ensure company is logged in first
    require_company_login();

    $company_id = intval($_SESSION['company_id'] ?? 0);
    if ($company_id <= 0) {
        header('Location: ' . BASE_URL . '/auth/login.php?role=recruiter');
        exit;
    }

    if (!is_company_verified($con, $company_id)) {
        // Handle AJAX / JSON request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' ||
            (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'error' => 'company_unverified',
                'msg' => 'Company Verification Required. You need to verify your company with valid business documentation before posting jobs or performing recruitment actions.',
                'verification_url' => BASE_URL . '/company/verification.php'
            ]);
            exit;
        }

        // Regular browser request: redirect to verification page with notice
        if ($redirect) {
            header('Location: ' . BASE_URL . '/company/verification.php?notice=verification_required');
            exit;
        }
        return false;
    }
    return true;
}

/* ── 3. Document Management & Secure Storage ──────────────────────────────── */

/**
 * Get directory path for verification documents
 */
function nh_verification_docs_dir() {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'verification_documents';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        // Ensure .htaccess exists
        $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
        }
    }
    return $dir;
}

/**
 * Submit / Upload a company verification document with rigorous security validation
 */
function nh_submit_verification_document($con, $company_id, $file, $meta = []) {
    $company_id = intval($company_id);
    if ($company_id <= 0) {
        return ['ok' => false, 'error' => 'Invalid company identifier.'];
    }

    // 1. Validate file payload
    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please select a document file to upload.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'File upload error code: ' . $file['error']];
    }

    // 2. Validate file size (10 MB limit)
    $max_size = 10 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return ['ok' => false, 'error' => 'File size exceeds maximum limit of 10MB.'];
    }
    if ($file['size'] < 16) {
        return ['ok' => false, 'error' => 'Uploaded file is too small or empty.'];
    }

    // 3. Validate file extension
    $orig_name = basename($file['name']);
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed_exts, true)) {
        return ['ok' => false, 'error' => 'Only PDF and image files (JPG, PNG, WEBP) are allowed.'];
    }

    // 4. Validate MIME type and deep content inspection
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = [
        'pdf'  => ['application/pdf', 'application/x-pdf'],
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
    ];

    $valid_mime = false;
    foreach ($allowed_mimes[$ext] ?? [] as $m) {
        if (strcasecmp($m, $mime_type) === 0) {
            $valid_mime = true;
            break;
        }
    }
    if (!$valid_mime) {
        return ['ok' => false, 'error' => 'File content does not match allowed extension (Detected MIME: ' . htmlspecialchars($mime_type) . ').'];
    }

    // Additional deep format validation
    if ($ext === 'pdf') {
        $handle = @fopen($file['tmp_name'], 'rb');
        $header = $handle ? fread($handle, 5) : '';
        if ($handle) fclose($handle);
        if (strpos($header, '%PDF-') !== 0) {
            return ['ok' => false, 'error' => 'Uploaded file is not a valid PDF document.'];
        }
    } else {
        $img_info = @getimagesize($file['tmp_name']);
        if ($img_info === false) {
            return ['ok' => false, 'error' => 'Uploaded file is not a valid image.'];
        }
    }

    // 5. Validate metadata
    $supported_types = array_keys(nh_verification_document_types());
    $doc_type = trim($meta['document_type'] ?? 'other');
    if (!in_array($doc_type, $supported_types, true)) {
        $doc_type = 'other';
    }

    $doc_title = trim($meta['document_title'] ?? '');
    if (empty($doc_title)) {
        $doc_title = nh_verification_document_type_label($doc_type);
    }
    $doc_title = substr($doc_title, 0, 255);

    $doc_number = !empty($meta['document_number']) ? substr(trim($meta['document_number']), 0, 100) : null;
    $issuing_auth = !empty($meta['issuing_authority']) ? substr(trim($meta['issuing_authority']), 0, 255) : null;
    $issue_date = !empty($meta['issue_date']) ? trim($meta['issue_date']) : null;
    $expiry_date = !empty($meta['expiry_date']) ? trim($meta['expiry_date']) : null;

    // Validate dates if provided
    if ($issue_date) {
        $dt = DateTime::createFromFormat('Y-m-d', $issue_date);
        if (!$dt || $dt->format('Y-m-d') !== $issue_date) $issue_date = null;
    }
    if ($expiry_date) {
        $dt = DateTime::createFromFormat('Y-m-d', $expiry_date);
        if (!$dt || $dt->format('Y-m-d') !== $expiry_date) $expiry_date = null;
    }

    // 6. Generate cryptographically safe stored filename
    $target_dir = nh_verification_docs_dir();
    $safe_name = 'vdoc_' . $company_id . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest_path = $target_dir . DIRECTORY_SEPARATOR . $safe_name;

    $stored = false;
    if (is_uploaded_file($file['tmp_name'])) {
        $stored = @move_uploaded_file($file['tmp_name'], $dest_path);
    } else {
        $stored = @rename($file['tmp_name'], $dest_path);
        if (!$stored) {
            $stored = @copy($file['tmp_name'], $dest_path);
            if ($stored) @unlink($file['tmp_name']);
        }
    }

    if (!$stored) {
        return ['ok' => false, 'error' => 'Failed to store uploaded document securely on server.'];
    }

    // 7. Insert document record
    $file_size = (int)$file['size'];
    $ins_stmt = mysqli_prepare($con, "INSERT INTO company_verification_documents 
        (company_id, document_type, document_title, document_number, issuing_authority, issue_date, expiry_date, file_path, original_filename, mime_type, file_size, verification_status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'under_review', NOW())");
    
    if (!$ins_stmt) {
        @unlink($dest_path);
        return ['ok' => false, 'error' => 'Database error preparing document record: ' . mysqli_error($con)];
    }

    mysqli_stmt_bind_param($ins_stmt, "isssssssssi", 
        $company_id, $doc_type, $doc_title, $doc_number, $issuing_auth, $issue_date, $expiry_date, $safe_name, $orig_name, $mime_type, $file_size
    );
    
    $exec = mysqli_stmt_execute($ins_stmt);
    $doc_id = $exec ? mysqli_insert_id($con) : 0;
    mysqli_stmt_close($ins_stmt);

    if (!$exec || $doc_id <= 0) {
        @unlink($dest_path);
        return ['ok' => false, 'error' => 'Failed to insert document record into database.'];
    }

    // 8. Update company verification state to 'under_review' if not already verified
    $comp = nh_get_company_verification($con, $company_id);
    $prev_status = $comp['verification_status'] ?? VERIF_STATUS_PENDING;

    if ($prev_status !== VERIF_STATUS_VERIFIED) {
        $up_stmt = mysqli_prepare($con, "UPDATE companies SET verification_status = 'under_review' WHERE id = ?");
        mysqli_stmt_bind_param($up_stmt, "i", $company_id);
        mysqli_stmt_execute($up_stmt);
        mysqli_stmt_close($up_stmt);
        $new_status = VERIF_STATUS_UNDER_REVIEW;
    } else {
        $new_status = VERIF_STATUS_VERIFIED;
    }

    // 9. Record audit history
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rev_stmt = mysqli_prepare($con, "INSERT INTO company_verification_reviews (company_id, action, previous_status, new_status, reason, ip_address, created_at) VALUES (?, 'document_uploaded', ?, ?, ?, ?, NOW())");
    $reason_txt = "Uploaded document: {$doc_title} ({$orig_name})";
    mysqli_stmt_bind_param($rev_stmt, "issss", $company_id, $prev_status, $new_status, $reason_txt, $ip);
    mysqli_stmt_execute($rev_stmt);
    mysqli_stmt_close($rev_stmt);

    // 10. Notifications
    create_notification(
        $con,
        'company',
        $company_id,
        'system',
        null,
        'Verification Evidence Submitted 📄',
        'Your document <strong>' . htmlspecialchars($doc_title) . '</strong> has been submitted. Our administrators will review your evidence shortly.',
        'system',
        'company_verification',
        $doc_id
    );

    // Notify admins
    $admin_q = mysqli_query($con, "SELECT id FROM admin_login LIMIT 5");
    if ($admin_q) {
        $cname = $comp['company_name'] ?? ('Company #' . $company_id);
        while ($arow = mysqli_fetch_assoc($admin_q)) {
            create_notification(
                $con,
                'admin',
                (int)$arow['id'],
                'company',
                $company_id,
                'New Verification Request 🏢',
                '<strong>' . htmlspecialchars($cname) . '</strong> submitted verification evidence (' . htmlspecialchars($doc_title) . ') awaiting review.',
                'system',
                'company_verification',
                $company_id
            );
        }
    }

    return [
        'ok' => true,
        'doc_id' => $doc_id,
        'filename' => $safe_name,
        'status' => $new_status,
        'msg' => 'Document submitted successfully.'
    ];
}

/**
 * Fetch all documents submitted by a company
 */
function nh_get_company_documents($con, $company_id) {
    $company_id = intval($company_id);
    if ($company_id <= 0) return [];

    $stmt = mysqli_prepare($con, "SELECT * FROM company_verification_documents WHERE company_id = ? ORDER BY created_at DESC");
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, "i", $company_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $docs = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $docs[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $docs;
}

/**
 * Fetch a single verification document by ID
 */
function nh_get_verification_document($con, $doc_id) {
    $doc_id = intval($doc_id);
    if ($doc_id <= 0) return null;

    $stmt = mysqli_prepare($con, "SELECT cvd.*, c.company_name, c.company_email, c.verification_status as company_status 
                                  FROM company_verification_documents cvd
                                  JOIN companies c ON cvd.company_id = c.id
                                  WHERE cvd.id = ? LIMIT 1");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "i", $doc_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Strict Anti-IDOR Authorization Check for viewing/downloading verification documents
 */
function nh_can_access_verification_document($con, $actor_type, $actor_id, $doc_id) {
    $doc = nh_get_verification_document($con, $doc_id);
    if (!$doc) {
        return ['allowed' => false, 'error' => 'Verification document not found.'];
    }

    // Administrators have full platform access to review documents
    if ($actor_type === 'admin') {
        return ['allowed' => true, 'document' => $doc];
    }

    // Company can ONLY access their own submitted documents
    if ($actor_type === 'company' && (int)$doc['company_id'] === (int)$actor_id) {
        return ['allowed' => true, 'document' => $doc];
    }

    return ['allowed' => false, 'error' => 'Access denied. You do not have permission to view this document.'];
}

/**
 * Delete a verification document
 */
function nh_delete_verification_document($con, $company_id, $doc_id) {
    $company_id = intval($company_id);
    $doc_id = intval($doc_id);

    $doc = nh_get_verification_document($con, $doc_id);
    if (!$doc || (int)$doc['company_id'] !== $company_id) {
        return ['ok' => false, 'error' => 'Document not found or unauthorized.'];
    }

    // Unlink physical file
    $storage_dir = nh_verification_docs_dir();
    $file_path = $storage_dir . DIRECTORY_SEPARATOR . basename($doc['file_path']);
    if (is_file($file_path)) {
        @unlink($file_path);
    }

    $del_stmt = mysqli_prepare($con, "DELETE FROM company_verification_documents WHERE id = ? AND company_id = ?");
    mysqli_stmt_bind_param($del_stmt, "ii", $doc_id, $company_id);
    $ok = mysqli_stmt_execute($del_stmt);
    mysqli_stmt_close($del_stmt);

    // Audit log
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rev_stmt = mysqli_prepare($con, "INSERT INTO company_verification_reviews (company_id, action, previous_status, new_status, reason, ip_address, created_at) VALUES (?, 'document_deleted', ?, ?, ?, ?, NOW())");
    $comp = nh_get_company_verification($con, $company_id);
    $c_status = $comp['verification_status'] ?? VERIF_STATUS_PENDING;
    $reason_txt = "Deleted document: {$doc['document_title']}";
    mysqli_stmt_bind_param($rev_stmt, "issss", $company_id, $c_status, $c_status, $reason_txt, $ip);
    mysqli_stmt_execute($rev_stmt);
    mysqli_stmt_close($rev_stmt);

    return ['ok' => $ok];
}

/* ── 4. Admin Review Engine ───────────────────────────────────────────────── */

/**
 * Process administrator verification review decision (Approve, Reject, or Request Resubmission)
 */
function nh_admin_review_company($con, $admin_id, $company_id, $decision, $reason = '', $notes = '') {
    $company_id = intval($company_id);
    $admin_id   = intval($admin_id);

    $comp = nh_get_company_verification($con, $company_id);
    if (!$comp) {
        return ['ok' => false, 'error' => 'Target company not found.'];
    }
    $prev_status = $comp['verification_status'] ?? VERIF_STATUS_PENDING;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    switch ($decision) {
        case 'approve':
            $stmt = mysqli_prepare($con, "UPDATE companies SET 
                verification_status = 'verified', 
                is_verified = 1, 
                verified_at = NOW(), 
                verified_by = ?,
                verification_rejected_at = NULL,
                verification_rejection_reason = NULL
                WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $admin_id, $company_id);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Approve all pending/under_review documents for this company
            $doc_stmt = mysqli_prepare($con, "UPDATE company_verification_documents SET 
                verification_status = 'approved',
                reviewed_by = ?,
                reviewed_at = NOW(),
                admin_note = ?
                WHERE company_id = ? AND verification_status IN ('pending', 'under_review')");
            $doc_note = !empty($notes) ? $notes : 'Approved by administrator';
            mysqli_stmt_bind_param($doc_stmt, "isi", $admin_id, $doc_note, $company_id);
            mysqli_stmt_execute($doc_stmt);
            mysqli_stmt_close($doc_stmt);

            // Audit Trail
            $aud_stmt = mysqli_prepare($con, "INSERT INTO company_verification_reviews (company_id, admin_id, action, previous_status, new_status, reason, ip_address, created_at) VALUES (?, ?, 'approved', ?, 'verified', ?, ?, NOW())");
            $aud_reason = !empty($notes) ? $notes : 'Company evidence verified and approved.';
            mysqli_stmt_bind_param($aud_stmt, "iisss", $company_id, $admin_id, $prev_status, $aud_reason, $ip);
            mysqli_stmt_execute($aud_stmt);
            mysqli_stmt_close($aud_stmt);

            // Notification to company
            create_notification(
                $con,
                'company',
                $company_id,
                'admin',
                $admin_id,
                'Company Verified 🎉',
                'Your company has been successfully verified! All company recruitment and job posting features are now fully unlocked.',
                'system',
                'company_verification',
                $company_id
            );

            return ['ok' => true, 'new_status' => VERIF_STATUS_VERIFIED, 'msg' => 'Company verified successfully.'];

        case 'reject':
            $reason = trim($reason);
            if (empty($reason)) {
                return ['ok' => false, 'error' => 'A specific rejection reason is required so the company understands why evidence was declined.'];
            }

            $stmt = mysqli_prepare($con, "UPDATE companies SET 
                verification_status = 'rejected', 
                is_verified = 0, 
                verification_rejected_at = NOW(),
                verification_rejection_reason = ?
                WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $reason, $company_id);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Update documents
            $doc_stmt = mysqli_prepare($con, "UPDATE company_verification_documents SET 
                verification_status = 'rejected',
                reviewed_by = ?,
                reviewed_at = NOW(),
                admin_note = ?
                WHERE company_id = ? AND verification_status IN ('pending', 'under_review')");
            mysqli_stmt_bind_param($doc_stmt, "isi", $admin_id, $reason, $company_id);
            mysqli_stmt_execute($doc_stmt);
            mysqli_stmt_close($doc_stmt);

            // Audit Trail
            $aud_stmt = mysqli_prepare($con, "INSERT INTO company_verification_reviews (company_id, admin_id, action, previous_status, new_status, reason, ip_address, created_at) VALUES (?, ?, 'rejected', ?, 'rejected', ?, ?, NOW())");
            mysqli_stmt_bind_param($aud_stmt, "iisss", $company_id, $admin_id, $prev_status, $reason, $ip);
            mysqli_stmt_execute($aud_stmt);
            mysqli_stmt_close($aud_stmt);

            // Notification to company
            create_notification(
                $con,
                'company',
                $company_id,
                'admin',
                $admin_id,
                'Verification Request Rejected ⚠️',
                'Your company verification request was rejected. Reason: <em>' . htmlspecialchars($reason) . '</em>. Please submit corrected business evidence to continue.',
                'system',
                'company_verification',
                $company_id
            );

            return ['ok' => true, 'new_status' => VERIF_STATUS_REJECTED, 'msg' => 'Company verification rejected.'];

        case 'request_resubmission':
            $reason = trim($reason);
            if (empty($reason)) {
                return ['ok' => false, 'error' => 'Please provide specific instructions detailing what additional evidence or correction is required.'];
            }

            $stmt = mysqli_prepare($con, "UPDATE companies SET 
                verification_status = 'resubmission_required', 
                is_verified = 0, 
                verification_rejection_reason = ?
                WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $reason, $company_id);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Update documents
            $doc_stmt = mysqli_prepare($con, "UPDATE company_verification_documents SET 
                reviewed_by = ?,
                reviewed_at = NOW(),
                admin_note = ?
                WHERE company_id = ? AND verification_status IN ('pending', 'under_review')");
            mysqli_stmt_bind_param($doc_stmt, "isi", $admin_id, $reason, $company_id);
            mysqli_stmt_execute($doc_stmt);
            mysqli_stmt_close($doc_stmt);

            // Audit Trail
            $aud_stmt = mysqli_prepare($con, "INSERT INTO company_verification_reviews (company_id, admin_id, action, previous_status, new_status, reason, ip_address, created_at) VALUES (?, ?, 'resubmission_requested', ?, 'resubmission_required', ?, ?, NOW())");
            mysqli_stmt_bind_param($aud_stmt, "iisss", $company_id, $admin_id, $prev_status, $reason, $ip);
            mysqli_stmt_execute($aud_stmt);
            mysqli_stmt_close($aud_stmt);

            // Notification to company
            create_notification(
                $con,
                'company',
                $company_id,
                'admin',
                $admin_id,
                'Additional Information Required 📄',
                'Additional verification information is required. Administrator note: <em>' . htmlspecialchars($reason) . '</em>. Please review and resubmit your documents.',
                'system',
                'company_verification',
                $company_id
            );

            return ['ok' => true, 'new_status' => VERIF_STATUS_RESUBMISSION, 'msg' => 'Resubmission request sent to company.'];

        default:
            return ['ok' => false, 'error' => 'Unsupported verification review action.'];
    }
}

/**
 * Fetch verification history / audit logs for a company
 */
function nh_get_verification_history($con, $company_id) {
    $company_id = intval($company_id);
    if ($company_id <= 0) return [];

    $stmt = mysqli_prepare($con, "SELECT cvr.*, al.admin_user_name 
                                  FROM company_verification_reviews cvr
                                  LEFT JOIN admin_login al ON cvr.admin_id = al.id
                                  WHERE cvr.company_id = ?
                                  ORDER BY cvr.created_at DESC");
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, "i", $company_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $history = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $history[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $history;
}

/**
 * Admin: Get verification requests summary and list
 */
function nh_admin_get_verification_requests($con, $status_filter = 'all', $search = '') {
    $where = ["1=1"];
    $params = [];
    $types = '';

    if (!empty($status_filter) && $status_filter !== 'all') {
        $where[] = "c.verification_status = ?";
        $params[] = $status_filter;
        $types .= 's';
    }

    if (!empty($search)) {
        $where[] = "(c.company_name LIKE ? OR c.company_email LIKE ? OR c.industry LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }

    $sql = "SELECT c.id, c.company_name, c.company_email, c.company_phone, c.industry, c.logo, 
                   c.is_verified, c.verification_status, c.verified_at, c.verified_by, c.verification_rejected_at,
                   c.verification_rejection_reason, c.registration_date,
                   (SELECT COUNT(*) FROM company_verification_documents WHERE company_id = c.id) as doc_count,
                   (SELECT MAX(created_at) FROM company_verification_documents WHERE company_id = c.id) as latest_doc_date
            FROM companies c
            WHERE " . implode(' AND ', $where) . "
            ORDER BY 
                CASE c.verification_status
                    WHEN 'under_review' THEN 1
                    WHEN 'resubmission_required' THEN 2
                    WHEN 'pending' THEN 3
                    WHEN 'rejected' THEN 4
                    WHEN 'verified' THEN 5
                    ELSE 6
                END,
                c.registration_date DESC";

    $stmt = mysqli_prepare($con, $sql);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $list = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $list[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $list;
}

/**
 * Admin: Get verification statistics count
 */
function nh_admin_get_verification_stats($con) {
    $stats = [
        'under_review' => 0,
        'pending'      => 0,
        'verified'     => 0,
        'rejected'     => 0,
        'resubmission' => 0,
        'total_docs'   => 0
    ];

    $q = mysqli_query($con, "SELECT verification_status, COUNT(*) as c FROM companies GROUP BY verification_status");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $st = $row['verification_status'];
            if ($st === 'under_review') $stats['under_review'] = (int)$row['c'];
            elseif ($st === 'pending') $stats['pending'] = (int)$row['c'];
            elseif ($st === 'verified') $stats['verified'] = (int)$row['c'];
            elseif ($st === 'rejected') $stats['rejected'] = (int)$row['c'];
            elseif ($st === 'resubmission_required') $stats['resubmission'] = (int)$row['c'];
        }
    }

    $dq = mysqli_query($con, "SELECT COUNT(*) as c FROM company_verification_documents");
    if ($dq && $row = mysqli_fetch_assoc($dq)) {
        $stats['total_docs'] = (int)$row['c'];
    }

    return $stats;
}
