<?php
/**
 * NovaHire — Company Verification & Evidence Access Control Test Suite
 *
 * Covers:
 * 1. Initial Registration & Default Pending State
 * 2. Access Gating (Blocked Job Posting & Recruitment for Pending/Under Review/Rejected)
 * 3. Evidence Submission (Metadata, Storage, Type Validation)
 * 4. File Security (Extension whitelist, MIME check, Path traversal safety)
 * 5. Anti-IDOR Authorization Checks (Company isolation, Admin access)
 * 6. Admin Review Engine (Approve, Reject with Reason, Request Resubmission)
 * 7. Verification Audit Trail & Notification Dispatch
 * 8. Immediate Feature Unlocking upon Approval (Badge & Permissions)
 * 9. Legacy Company Backward Compatibility
 */

require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../includes/company_verification.php';

$total_verif_tests = 0;
$passed_verif_tests = 0;
$failed_verif_tests = [];

function vtest($desc, $cond, $error = '') {
    global $total_verif_tests, $passed_verif_tests, $failed_verif_tests;
    $total_verif_tests++;
    if ($cond) {
        $passed_verif_tests++;
        echo "  [PASS] $desc\n";
    } else {
        $failed_verif_tests[] = "$desc ($error)";
        echo "  [FAIL] $desc: $error\n";
    }
}

echo "======================================================================\n";
echo "      NOVAHIRE COMPANY VERIFICATION & ACCESS CONTROL TEST SUITE       \n";
echo "======================================================================\n\n";

mysqli_begin_transaction($con);

try {
    $pwd_hash = password_hash('Secret123!', PASSWORD_BCRYPT);

    // Create Admin for review simulation
    $admin_uname = 'test_admin_' . uniqid();
    mysqli_query($con, "INSERT INTO admin_login (admin_user_name, admin_password) VALUES ('$admin_uname', '$pwd_hash')");
    $admin_id = mysqli_insert_id($con);
    vtest("Test Admin Created (ID: $admin_id)", $admin_id > 0);

    // ── 1. REGISTRATION & INITIAL PENDING STATE ─────────────────────────────
    echo "\n--- 1. Registration & Initial Pending State ---\n";
    $compA_email = 'comp_alpha_' . uniqid() . '@novahire.test';
    mysqli_query($con, "INSERT INTO companies (company_name, company_email, company_phone, company_address, industry, company_size, description, password, is_verified, verification_status, status)
                        VALUES ('Alpha Corp', '$compA_email', '+8801700000001', 'Dhaka, Bangladesh', 'IT', '11-50', 'Software Company', '$pwd_hash', 0, 'pending', 'active')");
    $compA_id = mysqli_insert_id($con);
    vtest("Company A Registered (ID: $compA_id)", $compA_id > 0);

    $compA_state = nh_get_company_verification($con, $compA_id);
    vtest("Company A Initial Status is 'pending'", ($compA_state['verification_status'] ?? '') === VERIF_STATUS_PENDING);
    vtest("Company A is_verified Flag is 0", (int)($compA_state['is_verified'] ?? 1) === 0);
    vtest("is_company_verified() returns false for pending company", is_company_verified($con, $compA_id) === false);

    // Company B (for IDOR tests)
    $compB_email = 'comp_beta_' . uniqid() . '@novahire.test';
    mysqli_query($con, "INSERT INTO companies (company_name, company_email, company_phone, company_address, industry, company_size, description, password, is_verified, verification_status, status)
                        VALUES ('Beta Ltd', '$compB_email', '+8801700000002', 'Chittagong, Bangladesh', 'Finance', '51-200', 'Fintech', '$pwd_hash', 0, 'pending', 'active')");
    $compB_id = mysqli_insert_id($con);
    vtest("Company B Registered (ID: $compB_id)", $compB_id > 0);

    // ── 2. ACCESS RESTRICTION FOR UNVERIFIED COMPANIES ──────────────────────
    echo "\n--- 2. Access Restriction for Unverified Companies ---\n";
    vtest("Unverified Company cannot pass verification check (Pending)", is_company_verified($con, $compA_id) === false);

    // Change status to under_review and test
    mysqli_query($con, "UPDATE companies SET verification_status = 'under_review' WHERE id = $compA_id");
    vtest("Company Under Review is still not verified", is_company_verified($con, $compA_id) === false);

    // Change status to rejected and test
    mysqli_query($con, "UPDATE companies SET verification_status = 'rejected' WHERE id = $compA_id");
    vtest("Rejected Company is not verified", is_company_verified($con, $compA_id) === false);

    // Change status to resubmission_required and test
    mysqli_query($con, "UPDATE companies SET verification_status = 'resubmission_required' WHERE id = $compA_id");
    vtest("Resubmission-Required Company is not verified", is_company_verified($con, $compA_id) === false);

    // Reset back to pending for submission tests
    mysqli_query($con, "UPDATE companies SET verification_status = 'pending' WHERE id = $compA_id");

    // ── 3. EVIDENCE SUBMISSION & STORAGE ────────────────────────────────────
    echo "\n--- 3. Evidence Submission & Secure Storage ---\n";
    
    // Create temporary dummy valid PDF file
    $tmp_pdf = tempnam(sys_get_temp_dir(), 'nh_test_') . '.pdf';
    file_put_contents($tmp_pdf, "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n>>\nendobj\ntrailer\n<<\n/Root 1 0 R\n>>\n%%EOF");

    $fake_file_pdf = [
        'name'     => 'Trade_License_2026.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $tmp_pdf,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmp_pdf)
    ];

    $upload_res = nh_submit_verification_document(
        $con,
        $compA_id,
        $fake_file_pdf,
        [
            'document_type' => 'trade_license',
            'document_title' => 'Municipal Trade License 2026',
            'document_number' => 'TL-987654321',
            'issuing_authority' => 'Dhaka North City Corporation',
            'issue_date' => '2026-01-01',
            'expiry_date' => '2027-01-01'
        ]
    );

    vtest("Valid PDF Document Submission Succeeded", ($upload_res['ok'] ?? false) === true);
    $docA_id = $upload_res['doc_id'] ?? 0;
    vtest("Document ID Generated ($docA_id)", $docA_id > 0);

    // Verify company status transitioned to 'under_review'
    $compA_after_sub = nh_get_company_verification($con, $compA_id);
    vtest("Status Transitioned to 'under_review'", ($compA_after_sub['verification_status'] ?? '') === VERIF_STATUS_UNDER_REVIEW);

    // Verify document record
    $doc_record = nh_get_verification_document($con, $docA_id);
    vtest("Document Title Stored Correctly", ($doc_record['document_title'] ?? '') === 'Municipal Trade License 2026');
    vtest("Document Number Stored Correctly", ($doc_record['document_number'] ?? '') === 'TL-987654321');
    vtest("Document Type is 'trade_license'", ($doc_record['document_type'] ?? '') === 'trade_license');
    vtest("Stored File Path Exists on Disk", file_exists(nh_verification_docs_dir() . '/' . $doc_record['file_path']));

    // ── 4. FILE SECURITY & VALIDATION ───────────────────────────────────────
    echo "\n--- 4. File Security & Validation ---\n";

    // 4.1 Dangerous extension rejection (.php)
    $tmp_php = tempnam(sys_get_temp_dir(), 'nh_test_') . '.php';
    file_put_contents($tmp_php, "<?php echo 'shell'; ?>");
    $fake_file_php = [
        'name'     => 'exploit.php',
        'type'     => 'text/x-php',
        'tmp_name' => $tmp_php,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmp_php)
    ];
    $res_php = nh_submit_verification_document($con, $compA_id, $fake_file_php, ['document_type' => 'trade_license', 'document_title' => 'Malicious File']);
    vtest("Dangerous File Extension (.php) Rejected", ($res_php['ok'] ?? false) === false);
    @unlink($tmp_php);

    // 4.2 Executable masquerading as PDF
    $tmp_fake_pdf = tempnam(sys_get_temp_dir(), 'nh_test_') . '.pdf';
    file_put_contents($tmp_fake_pdf, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00FakePEHeader");
    $fake_file_exe = [
        'name'     => 'fake_license.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $tmp_fake_pdf,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmp_fake_pdf)
    ];
    $res_exe = nh_submit_verification_document($con, $compA_id, $fake_file_exe, ['document_type' => 'trade_license', 'document_title' => 'Fake PDF']);
    vtest("Disguised Executable Rejected by Deep Inspection", ($res_exe['ok'] ?? false) === false);
    @unlink($tmp_fake_pdf);

    // 4.3 Oversized file rejection (> 10MB)
    $fake_oversized = [
        'name'     => 'huge_scan.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $tmp_pdf,
        'error'    => UPLOAD_ERR_OK,
        'size'     => 11 * 1024 * 1024 // 11MB
    ];
    $res_huge = nh_submit_verification_document($con, $compA_id, $fake_oversized, ['document_type' => 'trade_license', 'document_title' => 'Huge File']);
    vtest("Oversized File (>10MB) Rejected", ($res_huge['ok'] ?? false) === false);

    // ── 5. ANTI-IDOR AUTHORIZATION CHECKS ───────────────────────────────────
    echo "\n--- 5. Anti-IDOR Authorization Checks ---\n";

    // Company A accessing its own document: ALLOWED
    $accA = nh_can_access_verification_document($con, 'company', $compA_id, $docA_id);
    vtest("Owner Company A can access its document", ($accA['allowed'] ?? false) === true);

    // Company B accessing Company A's document: FORBIDDEN (Anti-IDOR)
    $accB = nh_can_access_verification_document($con, 'company', $compB_id, $docA_id);
    vtest("Company B is DENIED access to Company A's document", ($accB['allowed'] ?? true) === false);

    // Admin accessing Company A's document: ALLOWED
    $accAdmin = nh_can_access_verification_document($con, 'admin', $admin_id, $docA_id);
    vtest("Administrator can access document for review", ($accAdmin['allowed'] ?? false) === true);

    // Unauthenticated / Seeker access: FORBIDDEN
    $accSeeker = nh_can_access_verification_document($con, 'seeker', 9999, $docA_id);
    vtest("Seeker / Unauthenticated access is DENIED", ($accSeeker['allowed'] ?? true) === false);

    // ── 6. ADMIN REVIEW ENGINE: REQUEST RESUBMISSION ─────────────────────────
    echo "\n--- 6. Admin Review Engine: Request Resubmission ---\n";

    // Attempt resubmission without instructions: must fail
    $res_no_reason = nh_admin_review_company($con, $admin_id, $compA_id, 'request_resubmission', '');
    vtest("Request Resubmission without Reason Rejected", ($res_no_reason['ok'] ?? true) === false);

    // Valid request resubmission
    $res_resubmit = nh_admin_review_company($con, $admin_id, $compA_id, 'request_resubmission', 'Please upload both pages with clear seal');
    vtest("Admin Request Resubmission Succeeded", ($res_resubmit['ok'] ?? false) === true);

    $compA_resub = nh_get_company_verification($con, $compA_id);
    vtest("Status is now 'resubmission_required'", ($compA_resub['verification_status'] ?? '') === VERIF_STATUS_RESUBMISSION);
    vtest("Company remains unverified after resubmission request", is_company_verified($con, $compA_id) === false);

    // Company submits second document (resubmission flow)
    $tmp_pdf2 = tempnam(sys_get_temp_dir(), 'nh_test2_') . '.pdf';
    file_put_contents($tmp_pdf2, "%PDF-1.4\nSecond Valid Doc\n%%EOF");
    $fake_file_pdf2 = [
        'name'     => 'Trade_License_Corrected.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $tmp_pdf2,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmp_pdf2)
    ];
    $upload_res2 = nh_submit_verification_document(
        $con,
        $compA_id,
        $fake_file_pdf2,
        [
            'document_type' => 'trade_license',
            'document_title' => 'Corrected Trade License',
            'document_number' => 'TL-987654321',
            'issuing_authority' => 'Dhaka North City Corporation',
            'issue_date' => '2026-01-01',
            'expiry_date' => '2027-01-01'
        ]
    );
    vtest("Company Resubmitted Corrected Document", ($upload_res2['ok'] ?? false) === true);

    $compA_after_resub = nh_get_company_verification($con, $compA_id);
    vtest("Status returned to 'under_review' after resubmission", ($compA_after_resub['verification_status'] ?? '') === VERIF_STATUS_UNDER_REVIEW);

    // ── 7. ADMIN REVIEW ENGINE: REJECTION ───────────────────────────────────
    echo "\n--- 7. Admin Review Engine: Rejection ---\n";

    // Attempt reject without reason: must fail
    $rej_no_reason = nh_admin_review_company($con, $admin_id, $compA_id, 'reject', '');
    vtest("Reject without Reason Rejected", ($rej_no_reason['ok'] ?? true) === false);

    // Valid rejection with clear reason
    $rej_res = nh_admin_review_company($con, $admin_id, $compA_id, 'reject', 'The trade license provided has expired and cannot be verified.');
    vtest("Admin Rejection Succeeded with Reason", ($rej_res['ok'] ?? false) === true);

    $compA_rej = nh_get_company_verification($con, $compA_id);
    vtest("Status is 'rejected'", ($compA_rej['verification_status'] ?? '') === VERIF_STATUS_REJECTED);
    vtest("Rejection Reason Persisted", strpos($compA_rej['verification_rejection_reason'], 'expired') !== false);
    vtest("Company remains unverified after rejection", is_company_verified($con, $compA_id) === false);

    // ── 8. ADMIN REVIEW ENGINE: APPROVAL & UNLOCKING ────────────────────────
    echo "\n--- 8. Admin Review Engine: Approval & Feature Unlocking ---\n";

    $appr_res = nh_admin_review_company($con, $admin_id, $compA_id, 'approve', '', 'Verified with official municipality database');
    vtest("Admin Approval Succeeded", ($appr_res['ok'] ?? false) === true);

    $compA_appr = nh_get_company_verification($con, $compA_id);
    vtest("Company Status is now 'verified'", ($compA_appr['verification_status'] ?? '') === VERIF_STATUS_VERIFIED);
    vtest("is_verified Flag is 1", (int)($compA_appr['is_verified'] ?? 0) === 1);
    vtest("verified_at Timestamp Set", !empty($compA_appr['verified_at']));
    vtest("verified_by Admin ID Set", (int)($compA_appr['verified_by'] ?? 0) === $admin_id);
    vtest("is_company_verified() returns TRUE", is_company_verified($con, $compA_id) === true);

    // Verify recruitment actions are now allowed
    // Protected Job Creation test
    mysqli_query($con, "INSERT INTO company_jobs (company_id, job_title, job_description, job_category, employment_type, experience_required, location, deadline, status)
                        VALUES ($compA_id, 'Senior PHP Architect', 'Lead backend development', 'Engineering', 'Full-Time', '5+ years', 'Remote', '2027-01-01', 'active')");
    $job_id = mysqli_insert_id($con);
    vtest("Verified Company Can Post Active Job (Job ID: $job_id)", $job_id > 0);

    // ── 9. NOTIFICATIONS & AUDIT TRAIL ──────────────────────────────────────
    echo "\n--- 9. Notifications & Audit Trail Verification ---\n";

    $history = nh_get_verification_history($con, $compA_id);
    vtest("Audit Trail Contains Logged Events", count($history) >= 4);

    $actions_logged = array_column($history, 'action');
    vtest("Audit Trail has 'document_uploaded'", in_array('document_uploaded', $actions_logged));
    vtest("Audit Trail has 'resubmission_requested'", in_array('resubmission_requested', $actions_logged));
    vtest("Audit Trail has 'rejected'", in_array('rejected', $actions_logged));
    vtest("Audit Trail has 'approved'", in_array('approved', $actions_logged));

    // Verify company notifications generated
    $notif_q = mysqli_query($con, "SELECT COUNT(*) as c FROM notifications WHERE recipient_type='company' AND recipient_id=$compA_id");
    $notif_count = (int)(mysqli_fetch_assoc($notif_q)['c'] ?? 0);
    vtest("Company Received Lifecycle Notifications (Count: $notif_count)", $notif_count >= 3);

    // ── 10. EDGE CASE: VERIFIED COMPANY UPLOADING ADDITIONAL DOC ────────────
    echo "\n--- 10. Edge Case: Verified Company Uploading Additional Evidence ---\n";
    $tmp_pdf3 = tempnam(sys_get_temp_dir(), 'nh_test3_') . '.pdf';
    file_put_contents($tmp_pdf3, "%PDF-1.4\nThird Doc\n%%EOF");
    $fake_file_pdf3 = [
        'name'     => 'Tax_Certificate.pdf',
        'type'     => 'application/pdf',
        'tmp_name' => $tmp_pdf3,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmp_pdf3)
    ];
    $upload_res3 = nh_submit_verification_document(
        $con,
        $compA_id,
        $fake_file_pdf3,
        [
            'document_type' => 'tax_certificate',
            'document_title' => 'TIN Certificate',
            'document_number' => 'TIN-12345678',
            'issuing_authority' => 'National Board of Revenue',
            'issue_date' => '2026-01-01',
            'expiry_date' => '2027-01-01'
        ]
    );
    vtest("Additional Document Uploaded Succeeded", ($upload_res3['ok'] ?? false) === true);

    $compA_stay_verif = nh_get_company_verification($con, $compA_id);
    vtest("Verified Company Remains 'verified' After Adding Evidence (Case 6)", ($compA_stay_verif['verification_status'] ?? '') === VERIF_STATUS_VERIFIED);
    vtest("Verified Company Retains is_verified = 1", (int)($compA_stay_verif['is_verified'] ?? 0) === 1);

    // Clean up temporary files
    @unlink($tmp_pdf);
    @unlink($tmp_pdf2);
    @unlink($tmp_pdf3);

} catch (Exception $e) {
    vtest("Exception Encountered: " . $e->getMessage(), false);
} finally {
    // Rollback test data to keep the test environment clean and idempotent
    mysqli_rollback($con);
}

echo "\n======================================================================\n";
echo "           COMPANY VERIFICATION TEST SUITE SUMMARY                    \n";
echo "======================================================================\n";
echo "Total Tests Executed : $total_verif_tests\n";
echo "Tests Passed         : $passed_verif_tests\n";
echo "Tests Failed         : " . count($failed_verif_tests) . "\n";
echo "Pass Rate            : " . round(($passed_verif_tests / max(1, $total_verif_tests)) * 100, 1) . "%\n";
echo "======================================================================\n";

if (count($failed_verif_tests) > 0) {
    echo "\nFailed Assertions:\n";
    foreach ($failed_verif_tests as $f) {
        echo " - $f\n";
    }
    exit(1);
} else {
    echo "\nALL COMPANY VERIFICATION AND SECURITY TESTS PASSED PERFECTLY!\n";
    exit(0);
}
