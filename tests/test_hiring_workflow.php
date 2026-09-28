<?php
/**
 * NovaHire — Automated Test Suite for Post-Interview Hiring Workflow
 *
 * Runs unit, validation, logic, and security tests across all workflow components.
 */

require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../includes/hiring_workflow.php';

$total_tests = 0;
$passed_tests = 0;
$failed_tests = [];

function assert_test($description, $condition, $failure_detail = '') {
    global $total_tests, $passed_tests, $failed_tests;
    $total_tests++;
    if ($condition) {
        $passed_tests++;
        echo "  [PASS] $description\n";
    } else {
        $failed_tests[] = "$description: $failure_detail";
        echo "  [FAIL] $description: $failure_detail\n";
    }
}

echo "=========================================================\n";
echo "   NOVAHIRE POST-INTERVIEW HIRING WORKFLOW TEST SUITE   \n";
echo "=========================================================\n\n";

// ── TEST GROUP 1: Staff Employment Feedback Validation ──
echo "1. STAFF EMPLOYMENT FEEDBACK VALIDATION TESTS:\n";

// 1.1 YES with valid expected leaving date
$r = validate_employment_feedback('YES', '2026-10-15');
assert_test("YES with valid date is accepted", $r['valid'] === true && $r['leaving_date'] === '2026-10-15');

// 1.2 YES with CURRENTLY_WORKING alias
$r = validate_employment_feedback('CURRENTLY_WORKING', '2026-11-01');
assert_test("CURRENTLY_WORKING with valid date is accepted", $r['valid'] === true && $r['leaving_date'] === '2026-11-01');

// 1.3 YES with empty date
$r = validate_employment_feedback('YES', '');
assert_test("YES with empty leaving date is rejected", $r['valid'] === false && !empty($r['error']));

// 1.4 YES with invalid date format
$r = validate_employment_feedback('YES', '15/10/2026');
assert_test("YES with invalid date format (15/10/2026) is rejected", $r['valid'] === false);

// 1.5 YES with non-existent calendar date
$r = validate_employment_feedback('YES', '2026-02-31');
assert_test("YES with non-existent date (2026-02-31) is rejected", $r['valid'] === false);

// 1.6 NO clears leaving date
$r = validate_employment_feedback('NO', '2026-10-15');
assert_test("NO clears leaving date to NULL", $r['valid'] === true && $r['leaving_date'] === null && $r['status'] === 'NOT_CURRENTLY_WORKING');

// 1.7 NO with empty date
$r = validate_employment_feedback('NO', '');
assert_test("NO with empty date is valid with null leaving date", $r['valid'] === true && $r['leaving_date'] === null);

// 1.8 Invalid employment status choice
$r = validate_employment_feedback('MAYBE', '2026-10-15');
assert_test("Invalid employment status ('MAYBE') is rejected", $r['valid'] === false);


// ── TEST GROUP 2: Suggested Joining Date Calculation ──
echo "\n2. JOINING DATE CALCULATION TESTS:\n";

// 2.1 Leaving 2026-10-15 -> Suggested 2026-10-16 (+1 day)
$sug = calculate_suggested_joining_date('CURRENTLY_WORKING', '2026-10-15');
assert_test("Suggested joining date = expected leaving date + 1 day (2026-10-15 -> 2026-10-16)", $sug === '2026-10-16', "Got: $sug");

// 2.2 Leaving on month-end 2026-10-31 -> 2026-11-01
$sug = calculate_suggested_joining_date('CURRENTLY_WORKING', '2026-10-31');
assert_test("Suggested joining date handles month rollover (2026-10-31 -> 2026-11-01)", $sug === '2026-11-01', "Got: $sug");

// 2.3 NOT_CURRENTLY_WORKING produces null suggested date
$sug = calculate_suggested_joining_date('NOT_CURRENTLY_WORKING', null);
assert_test("NOT_CURRENTLY_WORKING returns NULL suggested joining date", $sug === null);


// ── TEST GROUP 3: Final Joining Date Validation ──
echo "\n3. FINAL JOINING DATE VALIDATION TESTS:\n";

// 3.1 Joining date after leaving date is valid
$v = validate_final_joining_date('2026-10-16', 'CURRENTLY_WORKING', '2026-10-15');
assert_test("Final joining date after leaving date is valid", $v['valid'] === true);

// 3.2 Joining date several days after leaving date is valid
$v = validate_final_joining_date('2026-11-01', 'CURRENTLY_WORKING', '2026-10-15');
assert_test("Final joining date well after leaving date is valid", $v['valid'] === true);

// 3.3 Joining date earlier than leaving date is rejected
$v = validate_final_joining_date('2026-10-14', 'CURRENTLY_WORKING', '2026-10-15');
assert_test("Final joining date before leaving date (2026-10-14 vs 2026-10-15) is rejected", $v['valid'] === false && !empty($v['error']));

// 3.4 Same-day joining date is rejected
$v = validate_final_joining_date('2026-10-15', 'CURRENTLY_WORKING', '2026-10-15');
assert_test("Final joining date equal to leaving date is rejected (must be after)", $v['valid'] === false);

// 3.5 Empty joining date is rejected
$v = validate_final_joining_date('', 'NOT_CURRENTLY_WORKING', null);
assert_test("Empty final joining date is rejected", $v['valid'] === false);


// ── TEST GROUP 4: Database Relationships & Multi-Staff Discrepancy ──
echo "\n4. MULTI-STAFF FEEDBACK & DISCREPANCY DETECTION TESTS:\n";

// Create temporary mock interview and staff for testing
mysqli_begin_transaction($con);
try {
    // 1. Get or create test company
    $comp_res = mysqli_query($con, "SELECT id FROM companies LIMIT 1");
    $test_comp_id = ($comp_res && $r = mysqli_fetch_assoc($comp_res)) ? intval($r['id']) : 1;

    // 2. Get or create test user
    $usr_res = mysqli_query($con, "SELECT id FROM user_info LIMIT 1");
    $test_user_id = ($usr_res && $r = mysqli_fetch_assoc($usr_res)) ? intval($r['id']) : 1;

    // 3. Get or create test job
    $job_res = mysqli_query($con, "SELECT id FROM company_jobs WHERE company_id = $test_comp_id LIMIT 1");
    $test_job_id = ($job_res && $r = mysqli_fetch_assoc($job_res)) ? intval($r['id']) : 1;

    // 4. Create mock application
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, application_status, pipeline_stage, applied_date) 
                        VALUES ($test_job_id, $test_user_id, $test_comp_id, 'under_final_review', 'interview', NOW())");
    $mock_app_id = mysqli_insert_id($con);

    // 5. Create mock interview
    mysqli_query($con, "INSERT INTO interviews (application_id, company_id, user_id, job_id, title, interview_date, interview_time, status)
                        VALUES ($mock_app_id, $test_comp_id, $test_user_id, $test_job_id, 'Staff Panel Mock Interview', '2026-10-10', '10:00:00', 'completed')");
    $mock_int_id = mysqli_insert_id($con);

    // 6. Get two staff members
    $st_res = mysqli_query($con, "SELECT id, full_name FROM company_staff WHERE company_id = $test_comp_id LIMIT 2");
    $staff1 = mysqli_fetch_assoc($st_res);
    $staff2 = mysqli_fetch_assoc($st_res);

    if ($staff1 && $staff2) {
        $s1_id = intval($staff1['id']);
        $s2_id = intval($staff2['id']);

        // Insert staff 1 feedback: YES (Leaving 2026-10-15)
        mysqli_query($con, "INSERT INTO interview_feedback (interview_id, staff_id, technical_score, communication_score, problem_solving_score, teamwork_score, professionalism_score, total_score, comments, recommendation, employment_status, expected_leaving_date, status)
                            VALUES ($mock_int_id, $s1_id, 8, 8, 8, 8, 8, 40, 'Excellent technical background', 'STRONGLY_RECOMMEND', 'CURRENTLY_WORKING', '2026-10-15', 'SUBMITTED')");

        // Insert staff 2 feedback: NO
        mysqli_query($con, "INSERT INTO interview_feedback (interview_id, staff_id, technical_score, communication_score, problem_solving_score, teamwork_score, professionalism_score, total_score, comments, recommendation, employment_status, expected_leaving_date, status)
                            VALUES ($mock_int_id, $s2_id, 7, 7, 7, 7, 7, 35, 'Good communicator, available immediately', 'RECOMMEND', 'NOT_CURRENTLY_WORKING', NULL, 'SUBMITTED')");

        $cons = get_interview_feedback_consolidated($con, $mock_int_id);
        assert_test("Both staff feedbacks loaded", $cons['count'] === 2);
        assert_test("All feedbacks are marked SUBMITTED", $cons['all_submitted'] === true);
        assert_test("Discrepancy detected when Staff 1 says YES and Staff 2 says NO", $cons['has_discrepancy'] === true);
        assert_test("Discrepancy message mentions different interviewer responses", strpos($cons['discrepancy_message'], 'Different interviewer responses') !== false);
    } else {
        echo "  [SKIP] Need at least 2 staff members in company_staff for multi-staff test.\n";
    }

    // ── TEST GROUP 5: Hiring Decision & Joining Date Persistence ──
    echo "\n5. HIRING DECISION & JOINING DATE PERSISTENCE TESTS:\n";

    // 5.1 Save decision 'selected' (HIRE)
    $res = save_hiring_decision($con, $test_comp_id, $mock_app_id, $test_user_id, $test_comp_id, 'selected', 'Candidate performed exceptionally well', '2026-10-16', '2026-10-20', 'CURRENTLY_WORKING', '2026-10-15');
    assert_test("Save hiring decision HIRE with valid joining date succeeds", $res['success'] === true, $res['error'] ?? '');

    // Check application status was updated to selected and offered
    $app_check = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status, pipeline_stage FROM job_applications WHERE id = $mock_app_id"));
    assert_test("Application status updated to 'selected'", $app_check['application_status'] === 'selected');
    assert_test("Pipeline stage updated to 'offered'", $app_check['pipeline_stage'] === 'offered');

    // Check hiring_decisions record
    $hd_check = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM hiring_decisions WHERE application_id = $mock_app_id"));
    assert_test("Final joining date saved correctly in hiring_decisions", $hd_check['final_joining_date'] === '2026-10-20');
    assert_test("Suggested joining date saved correctly in hiring_decisions", $hd_check['suggested_joining_date'] === '2026-10-16');

    // 5.2 Invalid joining date rejection during hiring decision
    $bad_res = save_hiring_decision($con, $test_comp_id, $mock_app_id, $test_user_id, $test_comp_id, 'selected', 'Attempting bad joining date', '2026-10-16', '2026-10-14', 'CURRENTLY_WORKING', '2026-10-15');
    assert_test("Hiring decision fails when confirmed joining date is before leaving date", $bad_res['success'] === false);


    // ── TEST GROUP 6: Document Requirements & Verification ──
    echo "\n6. DOCUMENT REQUIREMENTS & VERIFICATION TESTS:\n";

    $reqs = [
        ['document_type' => 'NID', 'title' => 'National Identity Card (NID)', 'description' => 'Scanned copy of both sides'],
        ['document_type' => 'Educational Certificate', 'title' => 'BSc Certificate', 'description' => 'Original certificate or provisional']
    ];
    $doc_req_res = create_document_requirements($con, $test_comp_id, $mock_app_id, $test_user_id, $reqs, $test_comp_id);
    assert_test("Create document requirements succeeds", $doc_req_res['success'] === true && $doc_req_res['count'] === 2);

    // Fetch created requirements
    $dr_res = mysqli_query($con, "SELECT id FROM hiring_document_requirements WHERE application_id = $mock_app_id LIMIT 1");
    $dr_row = mysqli_fetch_assoc($dr_res);
    $req_id = intval($dr_row['id']);

    // Mock document upload
    mysqli_query($con, "INSERT INTO candidate_documents (requirement_id, application_id, company_id, candidate_id, file_path, original_filename, file_size, mime_type, status)
                        VALUES ($req_id, $mock_app_id, $test_comp_id, $test_user_id, 'doc_test_123.pdf', 'my_nid.pdf', 102400, 'application/pdf', 'UPLOADED')");
    $mock_doc_id = mysqli_insert_id($con);

    // Verify document access by candidate
    $acc_cand = verify_document_access($con, 'user', $test_user_id, $mock_doc_id);
    assert_test("Candidate can access own document", $acc_cand['allowed'] === true);

    // Verify document access by company
    $acc_comp = verify_document_access($con, 'company', $test_comp_id, $mock_doc_id);
    assert_test("Company can access applicant document", $acc_comp['allowed'] === true);

    // IDOR test: unauthorized other candidate
    $acc_bad_user = verify_document_access($con, 'user', 999999, $mock_doc_id);
    assert_test("IDOR blocked: Unauthorized seeker (ID 999999) cannot access document", $acc_bad_user['allowed'] === false);

    // IDOR test: unauthorized other company
    $acc_bad_comp = verify_document_access($con, 'company', 999999, $mock_doc_id);
    assert_test("IDOR blocked: Unauthorized company (ID 999999) cannot access document", $acc_bad_comp['allowed'] === false);

    // Verify document rejection requires reason
    $rej_no_reason = update_document_verification($con, $mock_doc_id, $test_comp_id, 'REJECTED', '', $test_comp_id);
    assert_test("Document rejection without reason is rejected", $rej_no_reason['success'] === false);

    // Verify document rejection with reason succeeds
    $rej_ok = update_document_verification($con, $mock_doc_id, $test_comp_id, 'REJECTED', 'Image blurry, please upload clear PDF', $test_comp_id);
    assert_test("Document rejection with reason succeeds", $rej_ok['success'] === true);

    // Check requirement status updated to REJECTED
    $req_stat = mysqli_fetch_assoc(mysqli_query($con, "SELECT status FROM hiring_document_requirements WHERE id = $req_id"))['status'];
    assert_test("Requirement status updated to REJECTED", $req_stat === 'REJECTED');

    // Verify document acceptance succeeds
    $ver_ok = update_document_verification($con, $mock_doc_id, $test_comp_id, 'VERIFIED', null, $test_comp_id);
    assert_test("Document verification succeeds", $ver_ok['success'] === true);
    $req_stat_v = mysqli_fetch_assoc(mysqli_query($con, "SELECT status FROM hiring_document_requirements WHERE id = $req_id"))['status'];
    assert_test("Requirement status updated to VERIFIED", $req_stat_v === 'VERIFIED');


    // ── TEST GROUP 7: Appointment Letter Versioning & Sending ──
    echo "\n7. APPOINTMENT LETTER VERSIONING & SENDING TESTS:\n";

    // 7.1 Save Version 1
    $v1 = save_appointment_letter_version($con, $test_comp_id, $test_user_id, $mock_app_id, $test_job_id, 'Dear Candidate, Welcome to our team.', 'Appointment Letter v1', 'AI_GENERATED', '2026-10-20', '$80,000/year', 'Software Engineer', 'Dhaka, Bangladesh', $test_comp_id);
    assert_test("Save appointment letter version 1 succeeds", $v1['success'] === true && $v1['version_number'] === 1);
    $letter_id = $v1['letter_id'];

    // 7.2 Save Version 2 (Manual edit)
    $v2 = save_appointment_letter_version($con, $test_comp_id, $test_user_id, $mock_app_id, $test_job_id, 'Dear Candidate, Welcome to our team! Reporting time 9:30 AM.', 'Appointment Letter v2', 'MANUAL_EDIT', '2026-10-20', '$85,000/year', 'Senior Software Engineer', 'Dhaka, Bangladesh', $test_comp_id);
    assert_test("Save appointment letter version 2 preserves version history", $v2['success'] === true && $v2['version_number'] === 2);

    // Verify both versions exist in database
    $cnt_v = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as cnt FROM appointment_letter_versions WHERE hiring_letter_id = $letter_id"))['cnt'];
    assert_test("Both version records are preserved in database", intval($cnt_v) === 2);

    // 7.3 Send appointment letter
    $send_res = send_appointment_letter($con, $letter_id, $test_comp_id, $test_comp_id);
    assert_test("Send appointment letter succeeds", $send_res['success'] === true, $send_res['error'] ?? '');

    // Verify hiring_letter status is SENT
    $hl_stat = mysqli_fetch_assoc(mysqli_query($con, "SELECT status, sent_at FROM hiring_letters WHERE id = $letter_id"));
    assert_test("Hiring letter status is SENT with sent_at timestamp", $hl_stat['status'] === 'SENT' && !empty($hl_stat['sent_at']));

    // Verify pipeline_stage is hired
    $app_final = mysqli_fetch_assoc(mysqli_query($con, "SELECT pipeline_stage FROM job_applications WHERE id = $mock_app_id"));
    assert_test("Job application pipeline stage updated to 'hired'", $app_final['pipeline_stage'] === 'hired');


    // ── TEST GROUP 8: Selection, Hold, and Reject Decisions ──
    echo "\n8. COMPANY DECISION (ACCEPT / HOLD / REJECT) WORKFLOW TESTS:\n";

    // 8.1 ACCEPT candidate without providing final joining date immediately
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, application_status, pipeline_stage, applied_date) 
                        VALUES ($test_job_id, $test_user_id, $test_comp_id, 'under_final_review', 'interview', NOW())");
    $app_accept_id = mysqli_insert_id($con);
    $accept_res = save_hiring_decision($con, $test_comp_id, $app_accept_id, $test_user_id, $test_comp_id, 'selected', 'Promising candidate', null, null, null, null);
    assert_test("Company can choose ACCEPT without immediately providing final joining date", $accept_res['success'] === true, $accept_res['error'] ?? '');

    $app_acc_row = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status, pipeline_stage FROM job_applications WHERE id = $app_accept_id"));
    assert_test("Accepted candidate application status is 'selected'", $app_acc_row['application_status'] === 'selected');
    assert_test("Accepted candidate pipeline stage is 'offered'", $app_acc_row['pipeline_stage'] === 'offered');

    $hd_acc_row = mysqli_fetch_assoc(mysqli_query($con, "SELECT final_joining_date, candidate_response_status FROM hiring_decisions WHERE application_id = $app_accept_id"));
    assert_test("Candidate response status initialized to 'pending'", $hd_acc_row['candidate_response_status'] === 'pending');
    assert_test("Final joining date is NULL prior to candidate response", $hd_acc_row['final_joining_date'] === null);

    // Selected candidate receives notification
    $notif_acc = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM notifications WHERE recipient_type = 'user' AND recipient_id = $test_user_id ORDER BY id DESC LIMIT 1"));
    assert_test("Selected candidate receives selection notification", !empty($notif_acc) && strpos($notif_acc['message'], 'selected') !== false);

    // 8.2 HOLD candidate
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, application_status, pipeline_stage, applied_date) 
                        VALUES ($test_job_id, $test_user_id, $test_comp_id, 'under_final_review', 'interview', NOW())");
    $app_hold_id = mysqli_insert_id($con);
    $hold_res = save_hiring_decision($con, $test_comp_id, $app_hold_id, $test_user_id, $test_comp_id, 'under_final_review', 'Holding for further comparison', null, null, null, null);
    assert_test("Company can choose HOLD", $hold_res['success'] === true, $hold_res['error'] ?? '');

    $app_hold_row = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status FROM job_applications WHERE id = $app_hold_id"));
    assert_test("Held application status remains 'under_final_review'", $app_hold_row['application_status'] === 'under_final_review');

    $notif_hold = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM notifications WHERE recipient_type = 'user' AND recipient_id = $test_user_id ORDER BY id DESC LIMIT 1"));
    assert_test("Held candidate receives final review notification", !empty($notif_hold) && strpos($notif_hold['message'], 'under final review') !== false);

    // 8.3 REJECT candidate
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, application_status, pipeline_stage, applied_date) 
                        VALUES ($test_job_id, $test_user_id, $test_comp_id, 'under_final_review', 'interview', NOW())");
    $app_rej_id = mysqli_insert_id($con);
    $rej_res = save_hiring_decision($con, $test_comp_id, $app_rej_id, $test_user_id, $test_comp_id, 'rejected', 'Did not meet technical bar', null, null, null, null);
    assert_test("Company can choose REJECT", $rej_res['success'] === true, $rej_res['error'] ?? '');

    $app_rej_row = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status, pipeline_stage FROM job_applications WHERE id = $app_rej_id"));
    assert_test("Rejected application status is 'rejected'", $app_rej_row['application_status'] === 'rejected');
    assert_test("Rejected pipeline stage is 'rejected'", $app_rej_row['pipeline_stage'] === 'rejected');

    $notif_rej = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM notifications WHERE recipient_type = 'user' AND recipient_id = $test_user_id ORDER BY id DESC LIMIT 1"));
    assert_test("Rejected candidate receives rejection notification", !empty($notif_rej) && strpos($notif_rej['message'], 'not to move forward') !== false);


    // ── TEST GROUP 9: Candidate Selection Response & Availability ──
    echo "\n9. CANDIDATE SELECTION RESPONSE & AVAILABILITY TESTS:\n";

    // 9.1 Selected candidate confirms readiness (working candidate)
    $resp_data_working = [
        'ready_to_join' => 'yes',
        'currently_working' => 'yes',
        'current_company_name' => 'Alpha Technologies',
        'expected_leaving_date' => '2026-10-20',
        'notice_period_days' => 30,
        'approximate_joining_date' => '2026-10-25',
        'candidate_notes' => 'Excited to join the team!'
    ];
    $save_resp_res = save_candidate_selection_response($con, $app_accept_id, $test_user_id, $resp_data_working);
    assert_test("Selected candidate can confirm readiness", $save_resp_res['success'] === true, $save_resp_res['error'] ?? '');

    // Check candidate_employment_availability record
    $cea_row = get_candidate_selection_response($con, $app_accept_id);
    assert_test("Candidate response becomes available to company", !empty($cea_row));
    assert_test("Candidate can provide approximate joining date", $cea_row['approximate_joining_date'] === '2026-10-25');
    assert_test("Currently-working candidate can provide expected leaving date", $cea_row['currently_working'] === 'yes' && $cea_row['expected_leaving_date'] === '2026-10-20');

    // Verify hiring_decisions status updated to accepted
    $hd_resp_check = mysqli_fetch_assoc(mysqli_query($con, "SELECT candidate_response_status FROM hiring_decisions WHERE application_id = $app_accept_id"));
    assert_test("Hiring decision response status updated to 'accepted'", $hd_resp_check['candidate_response_status'] === 'accepted');

    // 9.2 Validation: approximate joining date before expected leaving date is rejected
    $bad_date_data = [
        'ready_to_join' => 'yes',
        'currently_working' => 'yes',
        'expected_leaving_date' => '2026-10-20',
        'approximate_joining_date' => '2026-10-18'
    ];
    $bad_date_res = save_candidate_selection_response($con, $app_accept_id, $test_user_id, $bad_date_data);
    assert_test("Approximate joining date before leaving date is rejected", $bad_date_res['success'] === false);

    // 9.3 Non-working candidate is not forced to provide leaving date
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, application_status, pipeline_stage, applied_date) 
                        VALUES ($test_job_id, $test_user_id, $test_comp_id, 'under_final_review', 'interview', NOW())");
    $app_non_working_id = mysqli_insert_id($con);
    save_hiring_decision($con, $test_comp_id, $app_non_working_id, $test_user_id, $test_comp_id, 'selected', 'Selected fresh graduate', null, null, null, null);

    $resp_data_not_working = [
        'ready_to_join' => 'yes',
        'currently_working' => 'no',
        'available_immediately' => 'yes',
        'approximate_joining_date' => '2026-10-15',
        'candidate_notes' => 'Available immediately.'
    ];
    $save_nw_res = save_candidate_selection_response($con, $app_non_working_id, $test_user_id, $resp_data_not_working);
    assert_test("Non-working candidate is not forced to provide leaving date", $save_nw_res['success'] === true, $save_nw_res['error'] ?? '');

    $cea_nw = get_candidate_selection_response($con, $app_non_working_id);
    assert_test("Non-working candidate response has NULL leaving date", $cea_nw['currently_working'] === 'no' && empty($cea_nw['expected_leaving_date']));

    // 9.4 Candidate declines selection
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, application_status, pipeline_stage, applied_date) 
                        VALUES ($test_job_id, $test_user_id, $test_comp_id, 'under_final_review', 'interview', NOW())");
    $app_decline_id = mysqli_insert_id($con);
    save_hiring_decision($con, $test_comp_id, $app_decline_id, $test_user_id, $test_comp_id, 'selected', 'Selected', null, null, null, null);

    // Decline without reason is rejected
    $decl_no_reason = save_candidate_selection_response($con, $app_decline_id, $test_user_id, ['ready_to_join' => 'no', 'decline_reason' => '']);
    assert_test("Declining without reason is rejected", $decl_no_reason['success'] === false);

    // Decline with reason succeeds
    $decl_res = save_candidate_selection_response($con, $app_decline_id, $test_user_id, ['ready_to_join' => 'no', 'decline_reason' => 'Accepted another offer with higher salary']);
    assert_test("Declining with reason succeeds", $decl_res['success'] === true);

    $hd_decl_check = mysqli_fetch_assoc(mysqli_query($con, "SELECT candidate_response_status FROM hiring_decisions WHERE application_id = $app_decline_id"));
    assert_test("Hiring decision response status updated to 'declined'", $hd_decl_check['candidate_response_status'] === 'declined');

    $app_decl_check = mysqli_fetch_assoc(mysqli_query($con, "SELECT pipeline_stage FROM job_applications WHERE id = $app_decline_id"));
    assert_test("Declined application pipeline stage updated to 'rejected'", $app_decl_check['pipeline_stage'] === 'rejected');

} finally {
    // Rollback test data to keep the database clean
    mysqli_rollback($con);
    echo "\n✓ Test transaction rolled back cleanly. Production database state preserved.\n";
}

// ── Summary ──
echo "\n=========================================================\n";
echo "TEST RESULTS: $passed_tests / $total_tests PASSED\n";
if (!empty($failed_tests)) {
    echo "FAILED TESTS (" . count($failed_tests) . "):\n";
    foreach ($failed_tests as $f) {
        echo "  - $f\n";
    }
    exit(1);
} else {
    echo "ALL TESTS PASSED SUCCESSFULLY! (100%)\n";
    echo "=========================================================\n";
    exit(0);
}
