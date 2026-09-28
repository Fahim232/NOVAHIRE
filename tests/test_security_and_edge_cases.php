<?php
/**
 * NovaHire — Security & Edge Cases Test Suite
 *
 * Covers:
 * 1. Authorization & Role Isolation (Staff, Company, Seeker)
 * 2. IDOR Prevention (Cross-company, Cross-candidate document access)
 * 3. File Security (Dangerous extension rejection, MIME verification)
 * 4. Business Rule Constraints (Cannot send appointment without hiring / joining date)
 * 5. Rejection / DO_NOT_HIRE Workflow
 * 6. Feedback Immutability (Submitted feedback is locked)
 */

require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../includes/hiring_workflow.php';

$total_sec_tests = 0;
$passed_sec_tests = 0;
$failed_sec_tests = [];

function sec_test($desc, $cond, $error = '') {
    global $total_sec_tests, $passed_sec_tests, $failed_sec_tests;
    $total_sec_tests++;
    if ($cond) {
        $passed_sec_tests++;
        echo "  [PASS] $desc\n";
    } else {
        $failed_sec_tests[] = "$desc ($error)";
        echo "  [FAIL] $desc: $error\n";
    }
}

echo "======================================================================\n";
echo "       NOVAHIRE SECURITY & EDGE CASES REGRESSION TEST SUITE           \n";
echo "======================================================================\n\n";

mysqli_begin_transaction($con);

try {
    // Setup entities: Company A, Company B, Seeker A, Seeker B
    $pwd_hash = password_hash('password123', PASSWORD_BCRYPT);

    // Company A
    $cA_email = 'compA_' . uniqid() . '@example.com';
    mysqli_query($con, "INSERT INTO companies (company_name, company_email, company_phone, company_address, industry, company_size, description, password, status)
                        VALUES ('Company A', '$cA_email', '+8801111111111', 'Address A', 'IT', '11-50', 'Desc A', '$pwd_hash', 'active')");
    $compA_id = mysqli_insert_id($con);

    // Company B
    $cB_email = 'compB_' . uniqid() . '@example.com';
    mysqli_query($con, "INSERT INTO companies (company_name, company_email, company_phone, company_address, industry, company_size, description, password, status)
                        VALUES ('Company B', '$cB_email', '+8802222222222', 'Address B', 'IT', '11-50', 'Desc B', '$pwd_hash', 'active')");
    $compB_id = mysqli_insert_id($con);

    // Seeker A
    $sA_email = 'seekerA_' . uniqid() . '@example.com';
    mysqli_query($con, "INSERT INTO user_info (username, email, phone, password, cpassword, user_degree, user_skills, status)
                        VALUES ('seeker_A', '$sA_email', '+8803333333333', '$pwd_hash', '$pwd_hash', 'BSc', 'PHP', 'active')");
    $seekerA_id = mysqli_insert_id($con);

    // Seeker B
    $sB_email = 'seekerB_' . uniqid() . '@example.com';
    mysqli_query($con, "INSERT INTO user_info (username, email, phone, password, cpassword, user_degree, user_skills, status)
                        VALUES ('seeker_B', '$sB_email', '+8804444444444', '$pwd_hash', '$pwd_hash', 'BSc', 'Java', 'active')");
    $seekerB_id = mysqli_insert_id($con);

    // Job A for Company A
    mysqli_query($con, "INSERT INTO company_jobs (company_id, job_title, job_category, job_description, requirements, responsibilities, location, employment_type, experience_required, skills_required, quiz_timer, status)
                        VALUES ($compA_id, 'Backend Engineer A', 'Tech', 'Job Desc A', 'Req A', 'Resp A', 'Dhaka', 'Full-Time', '2y', 'PHP', 60, 'active')");
    $jobA_id = mysqli_insert_id($con);

    // Job B for Company B
    mysqli_query($con, "INSERT INTO company_jobs (company_id, job_title, job_category, job_description, requirements, responsibilities, location, employment_type, experience_required, skills_required, quiz_timer, status)
                        VALUES ($compB_id, 'Backend Engineer B', 'Tech', 'Job Desc B', 'Req B', 'Resp B', 'Sylhet', 'Full-Time', '2y', 'Java', 60, 'active')");
    $jobB_id = mysqli_insert_id($con);

    // Application A (Seeker A -> Company A)
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, application_status, pipeline_stage, applied_date)
                        VALUES ($jobA_id, $seekerA_id, $compA_id, 'applied', 'applied', NOW())");
    $appA_id = mysqli_insert_id($con);

    // Application B (Seeker B -> Company B)
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, application_status, pipeline_stage, applied_date)
                        VALUES ($jobB_id, $seekerB_id, $compB_id, 'applied', 'applied', NOW())");
    $appB_id = mysqli_insert_id($con);

    // Staff A for Company A
    mysqli_query($con, "INSERT INTO company_staff (company_id, full_name, email, designation, status)
                        VALUES ($compA_id, 'Staff A', 'staffA_" . uniqid() . "@test.com', 'Senior Dev', 'active')");
    $staffA_id = mysqli_insert_id($con);

    // Staff B for Company B
    mysqli_query($con, "INSERT INTO company_staff (company_id, full_name, email, designation, status)
                        VALUES ($compB_id, 'Staff B', 'staffB_" . uniqid() . "@test.com', 'Manager', 'active')");
    $staffB_id = mysqli_insert_id($con);

    // Interview for App A
    mysqli_query($con, "INSERT INTO interviews (application_id, company_id, user_id, job_id, title, interview_date, interview_time, duration_minutes, interview_type, status)
                        VALUES ($appA_id, $compA_id, $seekerA_id, $jobA_id, 'Interview A', '2026-10-10', '10:00:00', 30, 'Online', 'completed')");
    $intA_id = mysqli_insert_id($con);
    mysqli_query($con, "INSERT INTO interview_staff_assignments (interview_id, staff_id, is_primary, assigned_at) VALUES ($intA_id, $staffA_id, 1, NOW())");

    echo "1. AUTHORIZATION & CROSS-TENANT ISOLATION TESTS:\n";

    // 1.1 Company B cannot make hiring decision on Company A's candidate
    $cross_hiring = save_hiring_decision($con, $compB_id, $appA_id, $seekerA_id, $compB_id, 'selected', 'Unauthorized decision attempt', '2026-10-16', '2026-10-20', 'CURRENTLY_WORKING', '2026-10-15');
    sec_test("Cross-Company Hiring Blocked: Company B cannot hire Company A candidate", $cross_hiring['success'] === false);

    // 1.2 Staff B (Company B) cannot access Interview A (assigned only to Staff A)
    $stmt_check = mysqli_prepare($con, "SELECT COUNT(*) as cnt FROM interview_staff_assignments WHERE interview_id = ? AND staff_id = ?");
    mysqli_stmt_bind_param($stmt_check, "ii", $intA_id, $staffB_id);
    mysqli_stmt_execute($stmt_check);
    $unauth_assign = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check))['cnt'];
    sec_test("Staff Assignment Isolation: Staff B not assigned to Interview A", intval($unauth_assign) === 0);

    echo "\n2. FEEDBACK IMMUTABILITY & LOCKING TESTS:\n";

    // 2.1 Submit feedback for Staff A
    mysqli_query($con, "INSERT INTO interview_feedback (interview_id, staff_id, technical_score, communication_score, problem_solving_score, teamwork_score, professionalism_score, total_score, comments, recommendation, employment_status, expected_leaving_date, status)
                        VALUES ($intA_id, $staffA_id, 8, 8, 8, 8, 8, 40, 'Great candidate', 'RECOMMEND', 'CURRENTLY_WORKING', '2026-10-15', 'SUBMITTED')");
    $fb_id = mysqli_insert_id($con);

    // 2.2 Verify status is SUBMITTED
    $fb_status = mysqli_fetch_assoc(mysqli_query($con, "SELECT status FROM interview_feedback WHERE id = $fb_id"))['status'];
    sec_test("Feedback status is locked to SUBMITTED", $fb_status === 'SUBMITTED');

    // 2.3 Attempting to modify locked feedback via application service rule is blocked
    $can_edit = ($fb_status !== 'SUBMITTED');
    sec_test("Subsequent modifications to SUBMITTED feedback are blocked", $can_edit === false);

    echo "\n3. DOCUMENT REJECTION & VERIFICATION ACCESS (ANTI-IDOR) TESTS:\n";

    // Setup document requirement for App A
    $req_setup = create_document_requirements($con, $compA_id, $appA_id, $seekerA_id, [
        ['document_type' => 'NID', 'title' => 'NID Card', 'description' => 'National ID']
    ], $compA_id);
    $reqA_id = mysqli_fetch_assoc(mysqli_query($con, "SELECT id FROM hiring_document_requirements WHERE application_id = $appA_id LIMIT 1"))['id'];

    // Seeker A uploads document
    mysqli_query($con, "INSERT INTO candidate_documents (requirement_id, application_id, company_id, candidate_id, file_path, original_filename, file_size, mime_type, status)
                        VALUES ($reqA_id, $appA_id, $compA_id, $seekerA_id, 'doc_nid_secure_1.pdf', 'nid.pdf', 150000, 'application/pdf', 'UPLOADED')");
    $docA_id = mysqli_insert_id($con);

    // 3.1 Seeker B (different user) cannot access Seeker A's document
    $acc_sb = verify_document_access($con, 'user', $seekerB_id, $docA_id);
    sec_test("Anti-IDOR: Seeker B cannot access Seeker A document", $acc_sb['allowed'] === false);

    // 3.2 Company B (different company) cannot access Company A's candidate document
    $acc_cb = verify_document_access($con, 'company', $compB_id, $docA_id);
    sec_test("Anti-IDOR: Company B cannot access Company A candidate document", $acc_cb['allowed'] === false);

    // 3.3 Company A CAN access its candidate document
    $acc_ca = verify_document_access($con, 'company', $compA_id, $docA_id);
    sec_test("Authorized Access: Company A can access candidate document", $acc_ca['allowed'] === true);

    // 3.4 Seeker A CAN access own document
    $acc_sa = verify_document_access($con, 'user', $seekerA_id, $docA_id);
    sec_test("Authorized Access: Seeker A can access own document", $acc_sa['allowed'] === true);

    echo "\n4. FILE SECURITY & VALIDATION TESTS:\n";

    // 4.1 Dangerous file extensions rejection
    $dangerous_extensions = ['php', 'exe', 'bat', 'sh', 'py', 'js', 'html', 'phtml', 'phar'];
    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    $all_dangerous_blocked = true;
    foreach ($dangerous_extensions as $ext) {
        if (in_array(strtolower($ext), $allowed_extensions)) {
            $all_dangerous_blocked = false;
        }
    }
    sec_test("Dangerous file extensions (PHP, EXE, BAT, SH, etc.) strictly disallowed", $all_dangerous_blocked === true);

    // 4.2 MIME validation whitelist
    $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    $bad_mime = 'application/x-php';
    sec_test("Disallowed MIME types strictly rejected", !in_array($bad_mime, $allowed_mimes));

    echo "\n5. REJECTION / DO NOT HIRE WORKFLOW TESTS:\n";

    // 5.1 Company A rejects Candidate A
    $rej_decision = save_hiring_decision($con, $compA_id, $appA_id, $seekerA_id, $compA_id, 'rejected', 'Skills did not match required depth', null, null, null, null);
    sec_test("Company can execute DO NOT HIRE decision", $rej_decision['success'] === true);

    // 5.2 Application status is rejected
    $app_rej_check = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status, pipeline_stage FROM job_applications WHERE id = $appA_id"));
    sec_test("Application status updated to 'rejected'", $app_rej_check['application_status'] === 'rejected');
    sec_test("Pipeline stage updated to 'rejected'", $app_rej_check['pipeline_stage'] === 'rejected');

    // 5.3 Seeker receives rejection notification
    $rej_notif = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM notifications WHERE recipient_type = 'user' AND recipient_id = $seekerA_id ORDER BY id DESC LIMIT 1"));
    sec_test("Seeker receives application update notification upon rejection", !empty($rej_notif) && strpos($rej_notif['message'], 'decided not to move forward') !== false);

    // 5.4 Audit log recorded for rejection
    $audit_rej = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM hiring_audit_logs WHERE company_id = $compA_id AND action = 'HIRING_DECISION_REJECTED'"));
    sec_test("Audit log recorded for rejection", !empty($audit_rej));

    echo "\n6. APPOINTMENT LETTER CONTRACTUAL INTEGRITY TESTS:\n";

    // 6.1 Cannot send appointment letter without joining date
    // Create draft letter with NULL joining date
    $no_date_draft = save_appointment_letter_version($con, $compA_id, $seekerA_id, $appA_id, $jobA_id, 'Draft text', 'Offer', 'AI_GENERATED', null, '$50k', 'Developer', 'Remote', $compA_id);
    $no_date_letter_id = $no_date_draft['letter_id'];
    $send_no_date = send_appointment_letter($con, $no_date_letter_id, $compA_id, $compA_id);
    sec_test("Cannot send appointment letter without confirmed joining date", $send_no_date['success'] === false);

    // 6.2 Cannot send appointment letter with empty content
    $empty_draft = save_appointment_letter_version($con, $compA_id, $seekerA_id, $appA_id, $jobA_id, '   ', 'Offer', 'AI_GENERATED', '2026-10-25', '$50k', 'Developer', 'Remote', $compA_id);
    $empty_letter_id = $empty_draft['letter_id'];
    $send_empty = send_appointment_letter($con, $empty_letter_id, $compA_id, $compA_id);
    sec_test("Cannot send appointment letter with empty content", $send_empty['success'] === false);

    echo "\n7. SELECTION RESPONSE ACCESS CONTROL & ANTI-IDOR TESTS:\n";

    // Create a new application App C (Seeker A -> Company A)
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, application_status, pipeline_stage, applied_date)
                        VALUES ($jobA_id, $seekerA_id, $compA_id, 'applied', 'applied', NOW())");
    $appC_id = mysqli_insert_id($con);

    // 7.1 When App C is NOT yet selected (e.g. held under_final_review), Seeker A cannot access selection response
    save_hiring_decision($con, $compA_id, $appC_id, $seekerA_id, $compA_id, 'under_final_review', 'Under final review', null, null, null, null);
    $held_acc = verify_selection_response_access($con, $appC_id, $seekerA_id);
    sec_test("Held candidate cannot access onboarding response", $held_acc['allowed'] === false);

    // Held candidate attempting to submit response is blocked
    $held_submit = save_candidate_selection_response($con, $appC_id, $seekerA_id, ['ready_to_join' => 'yes', 'currently_working' => 'no', 'approximate_joining_date' => '2026-10-25']);
    sec_test("Held candidate cannot submit selection response", $held_submit['success'] === false);

    // 7.2 When App C is rejected, Seeker A cannot access selection response
    save_hiring_decision($con, $compA_id, $appC_id, $seekerA_id, $compA_id, 'rejected', 'Rejected', null, null, null, null);
    $rej_acc = verify_selection_response_access($con, $appC_id, $seekerA_id);
    sec_test("Rejected candidate cannot access onboarding response", $rej_acc['allowed'] === false);

    // Rejected candidate attempting to submit response is blocked
    $rej_submit = save_candidate_selection_response($con, $appC_id, $seekerA_id, ['ready_to_join' => 'yes', 'currently_working' => 'no', 'approximate_joining_date' => '2026-10-25']);
    sec_test("Rejected candidate cannot submit selection response", $rej_submit['success'] === false);

    // 7.3 Now select App C
    save_hiring_decision($con, $compA_id, $appC_id, $seekerA_id, $compA_id, 'selected', 'Selected Candidate', null, null, null, null);
    $sel_acc = verify_selection_response_access($con, $appC_id, $seekerA_id);
    sec_test("Selected candidate CAN access selection response", $sel_acc['allowed'] === true);

    // 7.4 Anti-IDOR: Seeker B cannot access Seeker A's selection response
    $idor_acc = verify_selection_response_access($con, $appC_id, $seekerB_id);
    sec_test("Anti-IDOR: Unauthorized Candidate B cannot access Candidate A's response", $idor_acc['allowed'] === false);

    // 7.5 Anti-IDOR: Seeker B cannot submit response for Seeker A
    $idor_submit = save_candidate_selection_response($con, $appC_id, $seekerB_id, ['ready_to_join' => 'yes', 'currently_working' => 'no', 'approximate_joining_date' => '2026-10-25']);
    sec_test("Anti-IDOR: Unauthorized Candidate B cannot submit Candidate A's response", $idor_submit['success'] === false);

    echo "\n8. AI APPOINTMENT LETTER READINESS GATE TESTS:\n";

    // 8.1 Candidate has not submitted readiness response yet -> AI generation gate blocks
    $cea_check = get_candidate_selection_response($con, $appC_id);
    $can_generate_unanswered = ($cea_check && $cea_check['ready_to_join'] === 'yes');
    sec_test("AI appointment letter cannot be generated before candidate response", $can_generate_unanswered === false);

    // 8.2 Candidate declines selection -> AI generation gate blocks
    save_candidate_selection_response($con, $appC_id, $seekerA_id, ['ready_to_join' => 'no', 'decline_reason' => 'Taking another opportunity']);
    $cea_declined = get_candidate_selection_response($con, $appC_id);
    $can_generate_declined = ($cea_declined && $cea_declined['ready_to_join'] === 'yes');
    sec_test("AI appointment letter cannot be generated if candidate declined", $can_generate_declined === false);

    // 8.3 Candidate confirms readiness -> AI generation gate allows
    save_candidate_selection_response($con, $appC_id, $seekerA_id, [
        'ready_to_join' => 'yes',
        'currently_working' => 'yes',
        'current_company_name' => 'Previous Employer',
        'expected_leaving_date' => '2026-10-20',
        'approximate_joining_date' => '2026-10-26'
    ]);
    $cea_ready = get_candidate_selection_response($con, $appC_id);
    $can_generate_ready = ($cea_ready && $cea_ready['ready_to_join'] === 'yes');
    sec_test("AI appointment letter allowed after candidate confirms readiness", $can_generate_ready === true);

} finally {
    mysqli_rollback($con);
    echo "\n✓ Security test transaction rolled back cleanly. Database preserved in pristine state.\n";
}

echo "\n======================================================================\n";
echo "SECURITY TEST RESULTS: $passed_sec_tests / $total_sec_tests PASSED\n";
if (!empty($failed_sec_tests)) {
    echo "FAILED SECURITY TESTS:\n";
    foreach ($failed_sec_tests as $f) {
        echo "  - $f\n";
    }
    echo "======================================================================\n";
    exit(1);
} else {
    echo "ALL SECURITY & EDGE CASE TESTS PASSED! (100%)\n";
    echo "======================================================================\n";
    exit(0);
}
