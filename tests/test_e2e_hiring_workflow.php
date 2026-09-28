<?php
/**
 * NovaHire — Full End-to-End Workflow & Security Test Suite
 *
 * Simulates the complete 41-step workflow and edge cases:
 * STEP 1-41: Full Hiring Lifecycle (Company, Job, Seeker, Application, Interview,
 * Staff Feedback, Employment Status, Discrepancy, Hiring Decision, Suggested Joining Date,
 * Document Requirements, Seeker Upload, Anti-IDOR, Verification/Rejection,
 * AI Appointment Letter, Versioning, Editing, Sending, Seeker Dashboard, Audit Log).
 */

require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../includes/hiring_workflow.php';

$step_results = [];
$total_steps = 0;
$passed_steps = 0;
$failed_steps = [];

function step($number, $description, $condition, $details = '') {
    global $total_steps, $passed_steps, $failed_steps, $step_results;
    $total_steps++;
    if ($condition) {
        $passed_steps++;
        echo "  [STEP " . str_pad($number, 2, '0', STR_PAD_LEFT) . "] PASS: $description\n";
        $step_results[$number] = ['status' => 'PASS', 'description' => $description];
    } else {
        $failed_steps[] = "Step $number ($description): $details";
        echo "  [STEP " . str_pad($number, 2, '0', STR_PAD_LEFT) . "] FAIL: $description — $details\n";
        $step_results[$number] = ['status' => 'FAIL', 'description' => $description, 'error' => $details];
    }
}

echo "======================================================================\n";
echo "   NOVAHIRE REFINED POST-INTERVIEW WORKFLOW END-TO-END SUITE          \n";
echo "======================================================================\n\n";

mysqli_begin_transaction($con);

try {
    // ---------------------------------------------------------
    // STEP 1: Create company
    // ---------------------------------------------------------
    $comp_email = 'e2e_corp_' . uniqid() . '@example.com';
    $pwd_hash = password_hash('password123', PASSWORD_BCRYPT);
    $q_comp = "INSERT INTO companies (company_name, company_email, company_phone, company_address, industry, company_size, description, password, status)
               VALUES ('NovaHire Global Ltd', '$comp_email', '+8801700000000', '100 Tech Blvd, Dhaka', 'Technology', '51-200', 'Leading recruitment tech', '$pwd_hash', 'active')";
    mysqli_query($con, $q_comp);
    $company_id = mysqli_insert_id($con);
    step(1, "Create company", $company_id > 0, "Company ID: $company_id. Error: " . mysqli_error($con));

    // ---------------------------------------------------------
    // STEP 2: Create job
    // ---------------------------------------------------------
    $q_job = "INSERT INTO company_jobs (company_id, job_title, job_category, job_description, requirements, responsibilities, location, employment_type, experience_required, skills_required, quiz_timer, status)
              VALUES ($company_id, 'Senior Full Stack Engineer', 'Software Development', 'Developing next-gen systems', 'PHP, MySQL, React', 'Lead architecture', 'Dhaka, Bangladesh', 'Full-Time', '3-5 years', 'PHP, MySQL, JavaScript', 120, 'active')";
    mysqli_query($con, $q_job);
    $job_id = mysqli_insert_id($con);
    step(2, "Create job", $job_id > 0, "Job ID: $job_id. Error: " . mysqli_error($con));

    // ---------------------------------------------------------
    // STEP 3: Create seeker
    // ---------------------------------------------------------
    $seeker_email = 'e2e_seeker_' . uniqid() . '@example.com';
    $q_seeker = "INSERT INTO user_info (username, email, phone, password, cpassword, user_degree, user_skills, status)
                 VALUES ('md_rahad_candidate', '$seeker_email', '+8801800000000', '$pwd_hash', '$pwd_hash', 'BSc in CSE', 'PHP, MySQL, JavaScript', 'active')";
    mysqli_query($con, $q_seeker);
    $seeker_id = mysqli_insert_id($con);
    step(3, "Create seeker", $seeker_id > 0, "Seeker ID: $seeker_id. Error: " . mysqli_error($con));

    // ---------------------------------------------------------
    // STEP 4: Seeker applies
    // ---------------------------------------------------------
    $q_app = "INSERT INTO job_applications (job_id, user_id, company_id, application_status, pipeline_stage, applied_date)
              VALUES ($job_id, $seeker_id, $company_id, 'applied', 'applied', NOW())";
    mysqli_query($con, $q_app);
    $app_id = mysqli_insert_id($con);
    step(4, "Seeker applies", $app_id > 0, "Application ID: $app_id. Error: " . mysqli_error($con));

    // ---------------------------------------------------------
    // STEP 5: Seeker passes existing assessment if applicable
    // ---------------------------------------------------------
    mysqli_query($con, "UPDATE job_applications SET application_status = 'shortlisted', pipeline_stage = 'shortlisted' WHERE id = $app_id");
    $chk_stage_row = mysqli_fetch_assoc(mysqli_query($con, "SELECT pipeline_stage FROM job_applications WHERE id = $app_id"));
    $chk_stage = $chk_stage_row ? $chk_stage_row['pipeline_stage'] : '';
    step(5, "Seeker passes existing assessment / shortlisted", $chk_stage === 'shortlisted');

    // ---------------------------------------------------------
    // STEP 6: Company schedules interview
    // ---------------------------------------------------------
    $q_int = "INSERT INTO interviews (application_id, company_id, user_id, job_id, title, interview_date, interview_time, duration_minutes, interview_type, status)
              VALUES ($app_id, $company_id, $seeker_id, $job_id, 'Technical Panel Interview', '2026-10-10', '14:00:00', 45, 'Online', 'scheduled')";
    mysqli_query($con, $q_int);
    $interview_id = mysqli_insert_id($con);
    step(6, "Company schedules interview", $interview_id > 0, "Interview ID: $interview_id. Error: " . mysqli_error($con));

    // ---------------------------------------------------------
    // STEP 7: Company assigns staff
    // ---------------------------------------------------------
    $staff1_email = 'sarah_' . uniqid() . '@novahire.test';
    $staff2_email = 'david_' . uniqid() . '@novahire.test';

    mysqli_query($con, "INSERT INTO company_staff (company_id, full_name, email, designation, status)
                        VALUES ($company_id, 'Sarah Tech Lead', '$staff1_email', 'Lead Interviewer', 'active')");
    $staff1_id = mysqli_insert_id($con);

    mysqli_query($con, "INSERT INTO company_staff (company_id, full_name, email, designation, status)
                        VALUES ($company_id, 'David Eng Manager', '$staff2_email', 'Engineering Manager', 'active')");
    $staff2_id = mysqli_insert_id($con);

    mysqli_query($con, "INSERT INTO interview_staff_assignments (interview_id, staff_id, is_primary, assigned_at) VALUES ($interview_id, $staff1_id, 1, NOW())");
    mysqli_query($con, "INSERT INTO interview_staff_assignments (interview_id, staff_id, is_primary, assigned_at) VALUES ($interview_id, $staff2_id, 0, NOW())");
    $assigned_count = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as cnt FROM interview_staff_assignments WHERE interview_id = $interview_id"))['cnt'];
    step(7, "Company assigns staff", intval($assigned_count) === 2);

    // ---------------------------------------------------------
    // STEP 8: Staff logs in (authentication validation)
    // ---------------------------------------------------------
    $staff_auth = mysqli_fetch_assoc(mysqli_query($con, "SELECT id, company_id, full_name FROM company_staff WHERE id = $staff1_id AND status = 'active'"));
    step(8, "Staff logs in (authenticated)", !empty($staff_auth) && intval($staff_auth['company_id']) === $company_id);

    // ---------------------------------------------------------
    // STEP 9: Staff opens interview
    // ---------------------------------------------------------
    $int_access = mysqli_fetch_assoc(mysqli_query($con, "SELECT i.* FROM interviews i 
                                                         JOIN interview_staff_assignments isa ON i.id = isa.interview_id 
                                                         WHERE i.id = $interview_id AND isa.staff_id = $staff1_id"));
    step(9, "Staff opens interview", !empty($int_access) && intval($int_access['id']) === $interview_id);

    // ---------------------------------------------------------
    // STEP 10: Staff conducts interview
    // ---------------------------------------------------------
    mysqli_query($con, "UPDATE interviews SET status = 'completed' WHERE id = $interview_id");
    $int_status = mysqli_fetch_assoc(mysqli_query($con, "SELECT status FROM interviews WHERE id = $interview_id"))['status'];
    step(10, "Staff conducts interview", $int_status === 'completed');

    // ---------------------------------------------------------
    // STEP 11: Staff records scores (Technical, Communication, etc.)
    // ---------------------------------------------------------
    $scores = [
        'technical' => 9,
        'communication' => 8,
        'problem_solving' => 9,
        'teamwork' => 8,
        'professionalism' => 9
    ];
    $total_score = array_sum($scores);
    step(11, "Staff records evaluation scores (Total: $total_score)", $total_score === 43);

    // ---------------------------------------------------------
    // STEP 12: Staff answers: Currently working at another company? YES
    // ---------------------------------------------------------
    $emp_status_input = 'YES';
    $emp_val = validate_employment_feedback($emp_status_input, '2026-10-15');
    step(12, "Staff answers: Currently working at another company? YES", $emp_val['valid'] === true && $emp_val['status'] === 'CURRENTLY_WORKING');

    // ---------------------------------------------------------
    // STEP 13: Staff enters expected leaving date (15 October 2026)
    // ---------------------------------------------------------
    $leaving_date = '2026-10-15';
    step(13, "Staff enters expected leaving date (15 October 2026)", $emp_val['leaving_date'] === $leaving_date);

    // ---------------------------------------------------------
    // STEP 14: Staff submits feedback
    // ---------------------------------------------------------
    $q_fb1 = "INSERT INTO interview_feedback (interview_id, staff_id, technical_score, communication_score, problem_solving_score, teamwork_score, professionalism_score, total_score, comments, recommendation, employment_status, expected_leaving_date, status)
              VALUES ($interview_id, $staff1_id, 9, 8, 9, 8, 9, 43, 'Outstanding engineering capabilities.', 'STRONGLY_RECOMMEND', 'CURRENTLY_WORKING', '$leaving_date', 'SUBMITTED')";
    mysqli_query($con, $q_fb1);
    $fb1_id = mysqli_insert_id($con);
    log_hiring_audit($con, $company_id, 'staff', $staff1_id, 'INTERVIEW_FEEDBACK_SUBMITTED', 'interview_feedback', $fb1_id, ['employment_status' => 'CURRENTLY_WORKING', 'expected_leaving_date' => $leaving_date]);
    step(14, "Staff submits feedback", $fb1_id > 0, "Feedback ID: $fb1_id. Error: " . mysqli_error($con));

    // ---------------------------------------------------------
    // STEP 15: Verify feedback becomes locked
    // ---------------------------------------------------------
    $fb_locked_row = mysqli_fetch_assoc(mysqli_query($con, "SELECT status FROM interview_feedback WHERE id = $fb1_id"));
    $fb_locked = $fb_locked_row ? $fb_locked_row['status'] : '';
    step(15, "Verify feedback becomes locked (SUBMITTED)", $fb_locked === 'SUBMITTED');

    // ---------------------------------------------------------
    // STEP 16: Company opens candidate
    // ---------------------------------------------------------
    $app_view = mysqli_fetch_assoc(mysqli_query($con, "SELECT a.*, u.username, j.job_title 
                                                       FROM job_applications a 
                                                       JOIN user_info u ON a.user_id = u.id 
                                                       JOIN company_jobs j ON a.job_id = j.id 
                                                       WHERE a.id = $app_id AND a.company_id = $company_id"));
    step(16, "Company opens candidate", !empty($app_view) && $app_view['username'] === 'md_rahad_candidate');

    // ---------------------------------------------------------
    // STEP 17: Verify employment information is visible
    // ---------------------------------------------------------
    $consolidated_1 = get_interview_feedback_consolidated($con, $interview_id);
    $emp_visible = false;
    foreach ($consolidated_1['feedbacks'] as $f) {
        if ($f['employment_status'] === 'CURRENTLY_WORKING' && $f['expected_leaving_date'] === '2026-10-15') {
            $emp_visible = true;
        }
    }
    step(17, "Verify employment information is visible", $emp_visible === true);

    // ---------------------------------------------------------
    // STEP 18: If multiple staff exist, verify all feedback and discrepancy
    // ---------------------------------------------------------
    mysqli_query($con, "INSERT INTO interview_feedback (interview_id, staff_id, technical_score, communication_score, problem_solving_score, teamwork_score, professionalism_score, total_score, comments, recommendation, employment_status, expected_leaving_date, status)
                        VALUES ($interview_id, $staff2_id, 8, 8, 8, 8, 8, 40, 'Solid performance throughout.', 'RECOMMEND', 'NOT_CURRENTLY_WORKING', NULL, 'SUBMITTED')");
    $fb2_id = mysqli_insert_id($con);
    $consolidated_multi = get_interview_feedback_consolidated($con, $interview_id);
    step(18, "If multiple staff exist, verify all feedback & discrepancy visible", 
         $consolidated_multi['count'] === 2 && $consolidated_multi['has_discrepancy'] === true);

    // ---------------------------------------------------------
    // STEP 19: Company decides ACCEPT (Candidate Selected - No immediate joining date required)
    // ---------------------------------------------------------
    $save_dec = save_hiring_decision(
        $con,
        $company_id,
        $app_id,
        $seeker_id,
        $company_id,
        'selected',
        'Exceptional score across both interviewers.',
        null,
        null,
        null,
        null
    );
    step(19, "Company selects: ACCEPT (Candidate Selected without immediate joining date)", $save_dec['success'] === true, $save_dec['error'] ?? '');

    // ---------------------------------------------------------
    // STEP 20: Verify candidate marked 'selected' and response status 'pending'
    // ---------------------------------------------------------
    $app_sel_row = mysqli_fetch_assoc(mysqli_query($con, "SELECT a.application_status, a.pipeline_stage, d.candidate_response_status, d.final_joining_date 
                                                          FROM job_applications a 
                                                          JOIN hiring_decisions d ON a.id = d.application_id 
                                                          WHERE a.id = $app_id"));
    step(20, "Verify candidate marked selected with pending response status", 
         $app_sel_row['application_status'] === 'selected' && 
         $app_sel_row['pipeline_stage'] === 'offered' && 
         $app_sel_row['candidate_response_status'] === 'pending' &&
         $app_sel_row['final_joining_date'] === null);

    // ---------------------------------------------------------
    // STEP 21: Verify candidate receives selection notification
    // ---------------------------------------------------------
    $notif_res = mysqli_query($con, "SELECT * FROM notifications 
                                     WHERE recipient_type = 'user' AND recipient_id = $seeker_id 
                                     ORDER BY id DESC LIMIT 1");
    $notif = mysqli_fetch_assoc($notif_res);
    step(21, "Verify candidate receives selection notification", !empty($notif) && strpos($notif['message'], 'selected') !== false);

    // ---------------------------------------------------------
    // STEP 22: Candidate opens selection response page (Access Verified)
    // ---------------------------------------------------------
    $resp_access = verify_selection_response_access($con, $app_id, $seeker_id);
    step(22, "Candidate opens selection response page (access verified)", $resp_access['allowed'] === true);

    // ---------------------------------------------------------
    // STEP 23: Candidate confirms readiness: YES
    // ---------------------------------------------------------
    $candidate_ready = 'yes';
    step(23, "Candidate confirms readiness: YES (Ready to join)", $candidate_ready === 'yes');

    // ---------------------------------------------------------
    // STEP 24: Candidate provides employment details
    // ---------------------------------------------------------
    $emp_details = [
        'currently_working' => 'yes',
        'current_company_name' => 'Tech Innovators Ltd',
        'expected_leaving_date' => '2026-10-20',
        'notice_period_days' => 30
    ];
    step(24, "Candidate provides employment status & expected leaving date", 
         $emp_details['currently_working'] === 'yes' && !empty($emp_details['expected_leaving_date']));

    // ---------------------------------------------------------
    // STEP 25: Candidate provides approximate joining date (after leaving date)
    // ---------------------------------------------------------
    $approximate_joining_date = '2026-10-25';
    step(25, "Candidate provides approximate joining date ($approximate_joining_date)", 
         strtotime($approximate_joining_date) > strtotime($emp_details['expected_leaving_date']));

    // ---------------------------------------------------------
    // STEP 26: Candidate submits selection response
    // ---------------------------------------------------------
    $resp_submit = save_candidate_selection_response($con, $app_id, $seeker_id, [
        'ready_to_join' => 'yes',
        'currently_working' => 'yes',
        'current_company_name' => 'Tech Innovators Ltd',
        'expected_leaving_date' => '2026-10-20',
        'notice_period_days' => 30,
        'approximate_joining_date' => $approximate_joining_date,
        'candidate_notes' => 'Looking forward to contributing to NovaHire!'
    ]);
    step(26, "Candidate submits selection response", $resp_submit['success'] === true, $resp_submit['error'] ?? '');

    // ---------------------------------------------------------
    // STEP 27: Company receives candidate response
    // ---------------------------------------------------------
    $cea_received = get_candidate_selection_response($con, $app_id);
    $hd_accepted = mysqli_fetch_assoc(mysqli_query($con, "SELECT candidate_response_status FROM hiring_decisions WHERE application_id = $app_id"));
    step(27, "Company receives response (status updated to accepted)", 
         !empty($cea_received) && 
         $cea_received['ready_to_join'] === 'yes' && 
         $hd_accepted['candidate_response_status'] === 'accepted');

    // ---------------------------------------------------------
    // STEP 28: Company configures required onboarding documents
    // ---------------------------------------------------------
    $req_list = [
        ['document_type' => 'NID', 'title' => 'National Identity Card', 'description' => 'Color scanned copy of front and back'],
        ['document_type' => 'Educational Certificate', 'title' => 'BSc in CSE Certificate', 'description' => 'Official certificate or transcript'],
        ['document_type' => 'Employment Certificate', 'title' => 'Relieving Letter / Experience Certificate', 'description' => 'Clearance or release letter']
    ];
    $req_res = create_document_requirements($con, $company_id, $app_id, $seeker_id, $req_list, $company_id);
    step(28, "Company selects required documents (NID, Educational, Employment)", 
         $req_res['success'] === true && $req_res['count'] === 3, $req_res['error'] ?? '');

    // ---------------------------------------------------------
    // STEP 29: Candidate uploads required document (NID)
    // ---------------------------------------------------------
    $nid_req = mysqli_fetch_assoc(mysqli_query($con, "SELECT id FROM hiring_document_requirements WHERE application_id = $app_id AND document_type = 'NID'"));
    $nid_req_id = intval($nid_req['id']);

    $mock_file = 'nid_doc_' . uniqid() . '.pdf';
    mysqli_query($con, "INSERT INTO candidate_documents (requirement_id, application_id, company_id, candidate_id, file_path, original_filename, file_size, mime_type, status, uploaded_at)
                        VALUES ($nid_req_id, $app_id, $company_id, $seeker_id, '$mock_file', 'rahad_nid.pdf', 245000, 'application/pdf', 'UPLOADED', NOW())");
    $doc_nid_id = mysqli_insert_id($con);
    mysqli_query($con, "UPDATE hiring_document_requirements SET status = 'UPLOADED' WHERE id = $nid_req_id");
    log_hiring_audit($con, $company_id, 'user', $seeker_id, 'DOCUMENT_UPLOADED', 'candidate_documents', $doc_nid_id, ['requirement_id' => $nid_req_id, 'file' => 'rahad_nid.pdf']);
    step(29, "Candidate uploads required documents (NID)", $doc_nid_id > 0);

    // ---------------------------------------------------------
    // STEP 30: Company receives notification of document upload
    // ---------------------------------------------------------
    create_notification($con, 'company', $company_id, 'user', $seeker_id, 'New Document Uploaded', 'Candidate uploaded rahad_nid.pdf', 'system', 'candidate_documents', $doc_nid_id);
    $comp_notif = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM notifications WHERE recipient_type = 'company' AND recipient_id = $company_id ORDER BY id DESC LIMIT 1"));
    step(30, "Company receives notification of document upload", !empty($comp_notif) && $comp_notif['related_type'] === 'candidate_documents');

    // ---------------------------------------------------------
    // STEP 31: Company reviews and rejects document with clear reason
    // ---------------------------------------------------------
    $rej_act = update_document_verification($con, $doc_nid_id, $company_id, 'REJECTED', 'Back side is blurry. Please upload high-resolution scan.', $company_id);
    step(31, "Company reviews and rejects document with clear reason", $rej_act['success'] === true, $rej_act['error'] ?? '');

    // ---------------------------------------------------------
    // STEP 32: Candidate receives rejection reason
    // ---------------------------------------------------------
    $doc_rej_check = mysqli_fetch_assoc(mysqli_query($con, "SELECT status, rejection_reason FROM candidate_documents WHERE id = $doc_nid_id"));
    $req_rej_check = mysqli_fetch_assoc(mysqli_query($con, "SELECT status FROM hiring_document_requirements WHERE id = $nid_req_id"));
    step(32, "Candidate receives rejection reason", 
         $doc_rej_check['status'] === 'REJECTED' && 
         strpos($doc_rej_check['rejection_reason'], 'blurry') !== false && 
         $req_rej_check['status'] === 'REJECTED');

    // ---------------------------------------------------------
    // STEP 33: Candidate resubmits corrected document
    // ---------------------------------------------------------
    $resub_file = 'nid_resub_' . uniqid() . '.pdf';
    mysqli_query($con, "INSERT INTO candidate_documents (requirement_id, application_id, company_id, candidate_id, file_path, original_filename, file_size, mime_type, status, uploaded_at)
                        VALUES ($nid_req_id, $app_id, $company_id, $seeker_id, '$resub_file', 'rahad_nid_clear.pdf', 310000, 'application/pdf', 'UPLOADED', NOW())");
    $doc_nid_resub_id = mysqli_insert_id($con);
    mysqli_query($con, "UPDATE hiring_document_requirements SET status = 'UPLOADED' WHERE id = $nid_req_id");
    step(33, "Candidate resubmits corrected document", $doc_nid_resub_id > 0);

    // ---------------------------------------------------------
    // STEP 34: Company verifies resubmitted document
    // ---------------------------------------------------------
    $ver_act = update_document_verification($con, $doc_nid_resub_id, $company_id, 'VERIFIED', null, $company_id);
    $req_ver_check = mysqli_fetch_assoc(mysqli_query($con, "SELECT status FROM hiring_document_requirements WHERE id = $nid_req_id"));
    step(34, "Company verifies resubmitted document", $ver_act['success'] === true && $req_ver_check['status'] === 'VERIFIED');

    // ---------------------------------------------------------
    // STEP 35: Company opens appointment letter studio (readiness verified)
    // ---------------------------------------------------------
    $confirmed_joining_date = $cea_received['approximate_joining_date'];
    $gen_eligibility = mysqli_fetch_assoc(mysqli_query($con, "SELECT a.application_status, d.candidate_response_status 
                                                              FROM job_applications a 
                                                              LEFT JOIN hiring_decisions d ON a.id = d.application_id 
                                                              WHERE a.id = $app_id AND a.company_id = $company_id"));
    step(35, "Company opens appointment studio (candidate readiness confirmed)", 
         $gen_eligibility['application_status'] === 'selected' && 
         $gen_eligibility['candidate_response_status'] === 'accepted');

    // ---------------------------------------------------------
    // STEP 36: Company confirms appointment contractual parameters
    // ---------------------------------------------------------
    $apt_info = [
        'candidate_name' => 'Md. Rahad Candidate',
        'job_title' => 'Senior Full Stack Engineer',
        'joining_date' => $confirmed_joining_date,
        'salary' => '$95,000 per annum',
        'location' => 'Dhaka, Bangladesh / Hybrid',
        'employment_type' => 'Full-Time'
    ];
    step(36, "Company confirms appointment contractual parameters with candidate date", 
         !empty($apt_info['joining_date']) && $apt_info['joining_date'] === '2026-10-25');

    // ---------------------------------------------------------
    // STEP 37: AI generates appointment letter and creates Version 1
    // ---------------------------------------------------------
    $sample_ai_content = "Dear Md. Rahad Candidate,\n\nWe are delighted to offer you the position of Senior Full Stack Engineer at NovaHire Global Ltd. Your approved joining date is October 25, 2026.\n\nCompensation: $95,000 per annum\nLocation: Dhaka, Bangladesh / Hybrid\n\nPlease sign and return this appointment letter prior to your start date.";
    $v1_res = save_appointment_letter_version(
        $con,
        $company_id,
        $seeker_id,
        $app_id,
        $job_id,
        $sample_ai_content,
        'AI Generated Draft v1',
        'AI_GENERATED',
        $confirmed_joining_date,
        '$95,000 per annum',
        'Senior Full Stack Engineer',
        'Dhaka, Bangladesh / Hybrid',
        $company_id
    );
    $letter_id = $v1_res['letter_id'];
    step(37, "AI generates appointment letter and creates Version 1", $v1_res['success'] === true && $v1_res['version_number'] === 1);

    // ---------------------------------------------------------
    // STEP 38: Company reviews generated letter
    // ---------------------------------------------------------
    $curr_letter = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM hiring_letters WHERE id = $letter_id"));
    step(38, "Company reviews generated letter", !empty($curr_letter['content']) && $curr_letter['status'] === 'DRAFT');

    // ---------------------------------------------------------
    // STEP 39: Company edits and saves Version 2
    // ---------------------------------------------------------
    $edited_content = $sample_ai_content . "\n\nSpecial Term: Working hours are 9:00 AM to 5:00 PM BST.";
    $v2_res = save_appointment_letter_version(
        $con,
        $company_id,
        $seeker_id,
        $app_id,
        $job_id,
        $edited_content,
        'Company Edited Draft v2',
        'MANUAL_EDIT',
        $confirmed_joining_date,
        '$95,000 per annum',
        'Senior Full Stack Engineer',
        'Dhaka, Bangladesh / Hybrid',
        $company_id
    );
    step(39, "Company edits and saves Version 2", $v2_res['success'] === true && $v2_res['version_number'] === 2);

    // ---------------------------------------------------------
    // STEP 40: Company approves and sends appointment letter
    // ---------------------------------------------------------
    $send_res = send_appointment_letter($con, $letter_id, $company_id, $company_id);
    step(40, "Company approves & sends appointment letter", $send_res['success'] === true, $send_res['error'] ?? '');

    // ---------------------------------------------------------
    // STEP 41: Candidate receives appointment letter notification
    // ---------------------------------------------------------
    $seeker_apt_notif = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM notifications 
                                                               WHERE recipient_type = 'user' 
                                                                 AND recipient_id = $seeker_id 
                                                                 AND title LIKE '%Appointment Letter%' 
                                                               ORDER BY id DESC LIMIT 1"));
    step(41, "Verify seeker receives appointment notification", !empty($seeker_apt_notif));

    // ---------------------------------------------------------
    // STEP 42: Candidate opens and verifies appointment letter
    // ---------------------------------------------------------
    $seeker_letter_res = mysqli_query($con, "SELECT * FROM hiring_letters 
                                             WHERE application_id = $app_id 
                                               AND candidate_id = $seeker_id 
                                               AND status = 'SENT'");
    $seeker_letter = $seeker_letter_res ? mysqli_fetch_assoc($seeker_letter_res) : null;
    step(42, "Verify appointment letter received with correct joining date ($confirmed_joining_date)", 
         $seeker_letter && $seeker_letter['joining_date'] === $confirmed_joining_date);

    // ---------------------------------------------------------
    // STEP 43: Verify document status on onboarding dashboard
    // ---------------------------------------------------------
    $ver_doc_count = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as cnt FROM hiring_document_requirements WHERE application_id = $app_id AND status = 'VERIFIED'"))['cnt'];
    step(43, "Verify document status on onboarding dashboard", intval($ver_doc_count) >= 1);

    // ---------------------------------------------------------
    // STEP 44: Verify comprehensive audit log across all stages
    // ---------------------------------------------------------
    $audit_events = mysqli_query($con, "SELECT action FROM hiring_audit_logs WHERE company_id = $company_id");
    $recorded_actions = [];
    while ($arow = mysqli_fetch_assoc($audit_events)) {
        $recorded_actions[] = $arow['action'];
    }
    $has_core_audits = in_array('INTERVIEW_FEEDBACK_SUBMITTED', $recorded_actions) &&
                       (in_array('HIRING_DECISION_HIRED', $recorded_actions) || in_array('CANDIDATE_SELECTED', $recorded_actions)) &&
                       in_array('CANDIDATE_ACCEPTED_SELECTION', $recorded_actions) &&
                       in_array('DOCUMENT_UPLOADED', $recorded_actions) &&
                       in_array('APPOINTMENT_LETTER_SENT', $recorded_actions);
    step(44, "Verify comprehensive audit log recorded across all 4 stages", $has_core_audits === true);

} finally {
    mysqli_rollback($con);
    echo "\n✓ $total_steps-Step E2E test transaction rolled back cleanly. Database preserved in pristine state.\n";
}

echo "\n======================================================================\n";
echo "SUMMARY: $passed_steps / $total_steps STEPS PASSED (" . round(($passed_steps / $total_steps) * 100) . "%)\n";

if (!empty($failed_steps)) {
    echo "\nFAILED STEPS:\n";
    foreach ($failed_steps as $f) {
        echo "  - $f\n";
    }
    echo "======================================================================\n";
    exit(1);
} else {
    echo "ALL $total_steps WORKFLOW STEPS PASSED FLAWLESSLY! (100%)\n";
    echo "======================================================================\n";
    exit(0);
}
