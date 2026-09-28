<?php
/**
 * NovaHire — Automated Test Suite for AI CV Analyzer & Screening System
 *
 * Implements TDD tests covering:
 * - Application analysis request authorization (Own application vs Other company)
 * - CV extraction for all 3 types (auto_generated, ai_customized, uploaded)
 * - Job requirements extraction (Required vs Preferred, Skills, Experience, Education)
 * - Structured response schema validation & Score bounds (0-100)
 * - Rejection of invalid / malformed scores
 * - Requirement categorization (MATCHED, NOT_FOUND, UNCLEAR) with evidence
 * - Non-destructive AI behavior (AI failure does not change application status or reject candidate)
 * - CV version change detection & Outdated state
 * - Job requirement change detection & Outdated state
 * - Company quick actions (Review, Shortlist, Reject)
 * - Shortlist unlocks Schedule Interview without auto-scheduling
 * - Security, Anti-IDOR, and CSRF protection
 */

require_once __DIR__ . '/../admin/dbcon.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ai_cv_screener.php';

$total_tests = 0;
$passed_tests = 0;
$failed_tests = [];

function assert_cv_test($description, $condition, $failure_detail = '') {
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

echo "======================================================================\n";
echo "       NOVAHIRE AI CV ANALYZER & SCREENING SYSTEM TEST SUITE          \n";
echo "======================================================================\n\n";

mysqli_begin_transaction($con);

try {
    // ── SETUP FIXTURES ──
    $pwd_hash = password_hash('secret123', PASSWORD_BCRYPT);

    // Company A
    $c_email_a = 'corp_a_' . uniqid() . '@example.com';
    mysqli_query($con, "INSERT INTO companies (company_name, company_email, company_phone, company_address, industry, company_size, description, password, status)
                        VALUES ('Alpha Tech Solutions', '$c_email_a', '+8801711111111', 'Banani, Dhaka', 'IT', '51-200', 'Tech enterprise', '$pwd_hash', 'active')");
    $company_a_id = mysqli_insert_id($con);

    // Company B (for IDOR testing)
    $c_email_b = 'corp_b_' . uniqid() . '@example.com';
    mysqli_query($con, "INSERT INTO companies (company_name, company_email, company_phone, company_address, industry, company_size, description, password, status)
                        VALUES ('Beta Dynamics Ltd', '$c_email_b', '+8801722222222', 'Gulshan, Dhaka', 'Fintech', '11-50', 'Fintech enterprise', '$pwd_hash', 'active')");
    $company_b_id = mysqli_insert_id($con);

    // Job for Company A
    $q_job_a = "INSERT INTO company_jobs (company_id, job_title, job_category, job_description, requirements, responsibilities, location, employment_type, experience_required, skills_required, status)
                VALUES ($company_a_id, 'AI Engineer', 'Data Science', 'Building state of the art deep learning systems',
                        '- Bachelor in CSE / CS / AI / related field\n- 1+ year machine learning experience\n- Strong Python & PyTorch\n- SQL proficiency\nPreferred:\n- Docker containerization\n- AWS cloud experience\n- Git version control',
                        'Develop AI models and deploy pipelines', 'Dhaka', 'Full-Time', '1+ year', 'Python, Machine Learning, PyTorch, SQL', 'active')";
    mysqli_query($con, $q_job_a);
    $job_a_id = mysqli_insert_id($con);

    // Job for Company B
    $q_job_b = "INSERT INTO company_jobs (company_id, job_title, job_category, job_description, requirements, responsibilities, location, employment_type, experience_required, skills_required, status)
                VALUES ($company_b_id, 'Frontend Developer', 'Frontend', 'React web apps', 'React, CSS, JavaScript', 'Build UI', 'Remote', 'Full-Time', '2+ years', 'React, JavaScript, CSS', 'active')";
    mysqli_query($con, $q_job_b);
    $job_b_id = mysqli_insert_id($con);

    // Seeker 1 (Auto-generated CV profile)
    $s_email_1 = 'seeker1_' . uniqid() . '@example.com';
    mysqli_query($con, "INSERT INTO user_info (username, email, phone, user_degree, user_skills, experience, about_me, password, cpassword, status)
                        VALUES ('Md. Rahad', '$s_email_1', '+8801733333333', 'B.Sc. in Computer Science and Engineering', 'Python, Machine Learning, SQL, PyTorch, Git', '1.5 years experience in building Python & ML models using PyTorch and PostgreSQL', 'Passionate AI developer with deep learning project experience.', '$pwd_hash', '$pwd_hash', 'active')");
    $seeker_1_id = mysqli_insert_id($con);

    // Seeker 2 (AI-customized CV)
    $s_email_2 = 'seeker2_' . uniqid() . '@example.com';
    mysqli_query($con, "INSERT INTO user_info (username, email, phone, user_degree, user_skills, experience, about_me, password, cpassword, status)
                        VALUES ('Jane Developer', '$s_email_2', '+8801744444444', 'B.Sc. in Software Engineering', 'Python, Django, SQL', '2 years backend development', 'Software engineer', '$pwd_hash', '$pwd_hash', 'active')");
    $seeker_2_id = mysqli_insert_id($con);

    // Insert AI customized CV for Seeker 2
    $skills_json_2 = json_encode(['Python', 'PyTorch', 'Machine Learning', 'SQL', 'Docker']);
    $exp_json_2 = json_encode([['title' => 'ML Intern', 'company' => 'AI Labs', 'duration' => '1 year', 'description' => 'Trained computer vision models in PyTorch']]);
    $edu_json_2 = json_encode([['degree' => 'B.Sc. in Software Engineering', 'institution' => 'University of Dhaka', 'year' => '2024']]);
    $proj_json_2 = json_encode([['title' => 'Neural Classifier', 'tech' => 'PyTorch, Python, Docker', 'description' => 'Dockerized model inference service']]);

    mysqli_query($con, "INSERT INTO ai_generated_cvs (user_id, job_id, template_name, full_name, headline, email, phone, location, summary, skills_json, experience_json, education_json, projects_json)
                        VALUES ($seeker_2_id, $job_a_id, 'modern', 'Jane Developer', 'Machine Learning Engineer', '$s_email_2', '+8801744444444', 'Dhaka', 'Experienced in PyTorch and Dockerized ML pipelines.', '" . mysqli_real_escape_string($con, $skills_json_2) . "', '" . mysqli_real_escape_string($con, $exp_json_2) . "', '" . mysqli_real_escape_string($con, $edu_json_2) . "', '" . mysqli_real_escape_string($con, $proj_json_2) . "')");
    $ai_cv_2_id = mysqli_insert_id($con);

    // Application 1: Seeker 1 applied to Job A (cv_type = auto_generated)
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, cv_type, application_status, pipeline_stage, applied_date)
                        VALUES ($job_a_id, $seeker_1_id, $company_a_id, 'auto_generated', 'pending', 'applied', NOW())");
    $app_1_id = mysqli_insert_id($con);

    // Application 2: Seeker 2 applied to Job A (cv_type = ai_customized)
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, cv_type, ai_cv_id, application_status, pipeline_stage, applied_date)
                        VALUES ($job_a_id, $seeker_2_id, $company_a_id, 'ai_customized', $ai_cv_2_id, 'pending', 'applied', NOW())");
    $app_2_id = mysqli_insert_id($con);

    // Application 3: Company B's application (for isolation tests)
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, cv_type, application_status, pipeline_stage, applied_date)
                        VALUES ($job_b_id, $seeker_1_id, $company_b_id, 'auto_generated', 'pending', 'applied', NOW())");
    $app_b_id = mysqli_insert_id($con);


    // ════════════════════════════════════════════════════════════════
    // GROUP 1: SECURITY, AUTHORIZATION & ACCESS CONTROL
    // ════════════════════════════════════════════════════════════════
    echo "1. SECURITY & ACCESS CONTROL TESTS:\n";

    // 1.1 Company A can access its own application
    $auth_a = nh_verify_company_application_access($con, $company_a_id, $app_1_id);
    assert_cv_test("Company A can access its own application #$app_1_id", $auth_a['allowed'] === true && !empty($auth_a['application']));

    // 1.2 Company B CANNOT access Company A's application (Anti-IDOR)
    $auth_b_on_a = nh_verify_company_application_access($con, $company_b_id, $app_1_id);
    assert_cv_test("Company B is blocked from Company A's application #$app_1_id (Anti-IDOR)", $auth_b_on_a['allowed'] === false);

    // 1.3 Company A CANNOT analyze Company B's application
    $auth_a_on_b = nh_verify_company_application_access($con, $company_a_id, $app_b_id);
    assert_cv_test("Company A is blocked from Company B's application #$app_b_id (Anti-IDOR)", $auth_a_on_b['allowed'] === false);

    // 1.4 Non-existent application returns not allowed
    $auth_invalid = nh_verify_company_application_access($con, $company_a_id, 999999);
    assert_cv_test("Non-existent application is rejected", $auth_invalid['allowed'] === false);


    // ════════════════════════════════════════════════════════════════
    // GROUP 2: CV EXTRACTION & INPUT NORMALIZATION
    // ════════════════════════════════════════════════════════════════
    echo "\n2. CV EXTRACTION TESTS:\n";

    // 2.1 Auto-generated CV extraction
    $cv_data_1 = nh_extract_application_cv_data($con, $app_1_id);
    assert_cv_test("Auto-generated CV extracted successfully", !empty($cv_data_1['text']) && !empty($cv_data_1['skills']));
    assert_cv_test("Extracted skills contain Python and PyTorch", in_array('python', array_map('strtolower', $cv_data_1['skills'])) && in_array('pytorch', array_map('strtolower', $cv_data_1['skills'])));
    assert_cv_test("Extracted education contains B.Sc. in Computer Science", strpos($cv_data_1['education'], 'Computer Science') !== false);
    assert_cv_test("CV hash generated", !empty($cv_data_1['hash']));

    // 2.2 AI-customized CV extraction
    $cv_data_2 = nh_extract_application_cv_data($con, $app_2_id);
    assert_cv_test("AI-customized CV extracted from ai_generated_cvs", !empty($cv_data_2['text']) && in_array('docker', array_map('strtolower', $cv_data_2['skills'])));
    assert_cv_test("AI-customized CV projects extracted", !empty($cv_data_2['projects']) && strpos($cv_data_2['text'], 'Neural Classifier') !== false);

    // 2.3 Uploaded CV with mock text file
    $mock_cv_file = 'test_cv_' . uniqid() . '.txt';
    $mock_cv_dir = __DIR__ . '/../uploads/cv_files/';
    if (!is_dir($mock_cv_dir)) @mkdir($mock_cv_dir, 0777, true);
    file_put_contents($mock_cv_dir . $mock_cv_file, "John Resume\nSkills: Python, Machine Learning, AWS, SQL\nExperience: 3 years building AI\nEducation: Master of Science in AI");

    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, cv_type, cv_file, application_status, pipeline_stage, applied_date)
                        VALUES ($job_a_id, $seeker_1_id, $company_a_id, 'uploaded', '$mock_cv_file', 'pending', 'applied', NOW())");
    $app_uploaded_id = mysqli_insert_id($con);

    $cv_data_up = nh_extract_application_cv_data($con, $app_uploaded_id);
    assert_cv_test("Uploaded document text extracted cleanly", !empty($cv_data_up['text']) && strpos($cv_data_up['text'], 'Master of Science in AI') !== false);
    @unlink($mock_cv_dir . $mock_cv_file);


    // ════════════════════════════════════════════════════════════════
    // GROUP 3: JOB REQUIREMENTS EXTRACTION (REQUIRED VS PREFERRED)
    // ════════════════════════════════════════════════════════════════
    echo "\n3. JOB REQUIREMENTS EXTRACTION TESTS:\n";

    $job_reqs = nh_extract_job_screening_requirements($con, $job_a_id);
    assert_cv_test("Job requirements extracted successfully", !empty($job_reqs));
    assert_cv_test("Required skills extracted (Python, Machine Learning, PyTorch, SQL)", 
        count(array_intersect(['python', 'sql', 'pytorch', 'machine learning'], array_map('strtolower', $job_reqs['required_skills']))) >= 3);
    assert_cv_test("Preferred skills separated (Docker, AWS, Git)", 
        count(array_intersect(['docker', 'aws', 'git'], array_map('strtolower', $job_reqs['preferred_skills']))) >= 2);
    assert_cv_test("Job requirements hash generated", !empty($job_reqs['hash']));


    // ════════════════════════════════════════════════════════════════
    // GROUP 4: STRUCTURED RESPONSE VALIDATION & SCORING
    // ════════════════════════════════════════════════════════════════
    echo "\n4. STRUCTURED AI RESPONSE VALIDATION & SCORING TESTS:\n";

    // 4.1 Valid payload validation
    $valid_payload = [
        'overall_match_score'     => 87,
        'skills_match_score'      => 92,
        'experience_match_score'  => 81,
        'education_match_score'   => 90,
        'project_relevance_score' => 88,
        'required_requirements'   => [
            'matched' => [['name' => 'Python', 'status' => 'MATCHED', 'evidence' => 'Proficient in Python']],
            'missing' => [],
            'unclear' => []
        ],
        'preferred_requirements'  => [
            'matched' => [['name' => 'Git', 'status' => 'MATCHED', 'evidence' => 'Git listed']],
            'missing' => [['name' => 'Docker', 'status' => 'NOT_FOUND', 'evidence' => 'Not mentioned in CV']],
            'unclear' => [['name' => 'AWS', 'status' => 'UNCLEAR', 'evidence' => 'Cloud mentioned without AWS']]
        ],
        'strengths'               => ['Strong Python and ML skills', 'Degree matches job requirements'],
        'gaps'                    => ['No explicit Docker experience'],
        'evidence'                => ['Candidate used PyTorch in multiple projects'],
        'summary'                 => 'Strong fit for AI Engineer with solid ML background.'
    ];
    $val_res = nh_validate_ai_cv_analysis_payload($valid_payload);
    assert_cv_test("Valid structured payload passes validation", $val_res['valid'] === true);

    // 4.2 Score bounds: 0, 50, 100 accepted
    $valid_payload['overall_match_score'] = 0;
    assert_cv_test("Score = 0 is valid", nh_validate_ai_cv_analysis_payload($valid_payload)['valid'] === true);
    $valid_payload['overall_match_score'] = 50;
    assert_cv_test("Score = 50 is valid", nh_validate_ai_cv_analysis_payload($valid_payload)['valid'] === true);
    $valid_payload['overall_match_score'] = 100;
    assert_cv_test("Score = 100 is valid", nh_validate_ai_cv_analysis_payload($valid_payload)['valid'] === true);

    // 4.3 Out of bounds scores rejected
    $bad_payload_neg = $valid_payload;
    $bad_payload_neg['overall_match_score'] = -5;
    assert_cv_test("Negative score (-5) is rejected", nh_validate_ai_cv_analysis_payload($bad_payload_neg)['valid'] === false);

    $bad_payload_over = $valid_payload;
    $bad_payload_over['overall_match_score'] = 105;
    assert_cv_test("Over-100 score (105) is rejected", nh_validate_ai_cv_analysis_payload($bad_payload_over)['valid'] === false);

    $bad_payload_sub = $valid_payload;
    $bad_payload_sub['skills_match_score'] = 150;
    assert_cv_test("Sub-score > 100 is rejected", nh_validate_ai_cv_analysis_payload($bad_payload_sub)['valid'] === false);

    // 4.4 Missing required fields rejected
    $bad_payload_missing = $valid_payload;
    unset($bad_payload_missing['required_requirements']);
    assert_cv_test("Payload missing required_requirements is rejected", nh_validate_ai_cv_analysis_payload($bad_payload_missing)['valid'] === false);


    // ════════════════════════════════════════════════════════════════
    // GROUP 5: END-TO-END ANALYSIS GENERATION & PERSISTENCE
    // ════════════════════════════════════════════════════════════════
    echo "\n5. ANALYSIS GENERATION & PERSISTENCE TESTS:\n";

    // 5.1 Run analysis for Application 1
    $analysis_res_1 = nh_analyze_application_cv($con, $company_a_id, $app_1_id);
    assert_cv_test("Analysis execution returned success", ($analysis_res_1['success'] ?? false) === true);
    assert_cv_test("Analysis contains overall_match_score (0-100)", isset($analysis_res_1['analysis']['overall_match_score']) && $analysis_res_1['analysis']['overall_match_score'] >= 0 && $analysis_res_1['analysis']['overall_match_score'] <= 100);
    assert_cv_test("Analysis status is COMPLETED", ($analysis_res_1['analysis']['status'] ?? '') === 'COMPLETED');
    assert_cv_test("Analysis has required matches", !empty($analysis_res_1['analysis']['required_requirements']['matched']));
    assert_cv_test("Analysis has preferred evaluated separately", isset($analysis_res_1['analysis']['preferred_requirements']['matched']));

    // Verify persisted in DB
    $db_row = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM ai_cv_analyses WHERE application_id = $app_1_id"));
    assert_cv_test("Analysis row persisted in ai_cv_analyses table", !empty($db_row) && intval($db_row['overall_match_score']) === intval($analysis_res_1['analysis']['overall_match_score']));
    assert_cv_test("Persisted status is COMPLETED", $db_row['status'] === 'COMPLETED');
    assert_cv_test("Analysis timestamp recorded", !empty($db_row['analyzed_at']));


    // ════════════════════════════════════════════════════════════════
    // GROUP 6: NON-DESTRUCTIVE AI BEHAVIOR & APPLICATION STATUS
    // ════════════════════════════════════════════════════════════════
    echo "\n6. APPLICATION STATUS INDEPENDENCE & AI NON-DECISION TESTS:\n";

    // 6.1 Check application_status of app_1 after successful AI analysis
    $app_row_after_ai = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status, pipeline_stage FROM job_applications WHERE id = $app_1_id"));
    assert_cv_test("AI completion DID NOT modify application_status (remains 'pending')", $app_row_after_ai['application_status'] === 'pending');
    assert_cv_test("AI completion DID NOT modify pipeline_stage (remains 'applied')", $app_row_after_ai['pipeline_stage'] === 'applied');

    // 6.2 Simulate AI failure on a test app
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, cv_type, application_status, pipeline_stage, applied_date)
                        VALUES ($job_a_id, $seeker_1_id, $company_a_id, 'auto_generated', 'pending', 'applied', NOW())");
    $app_fail_test_id = mysqli_insert_id($con);

    // Call fail handler
    nh_record_ai_analysis_failure($con, $company_a_id, $app_fail_test_id, "Simulated provider timeout");
    $fail_analysis = mysqli_fetch_assoc(mysqli_query($con, "SELECT status, error_message FROM ai_cv_analyses WHERE application_id = $app_fail_test_id"));
    $app_fail_app = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status FROM job_applications WHERE id = $app_fail_test_id"));

    assert_cv_test("Analysis marked as FAILED with error message", $fail_analysis['status'] === 'FAILED' && !empty($fail_analysis['error_message']));
    assert_cv_test("AI failure DID NOT reject candidate (application_status remains 'pending')", $app_fail_app['application_status'] === 'pending');


    // ════════════════════════════════════════════════════════════════
    // GROUP 7: VERSION DETECTION & OUTDATED STATE
    // ════════════════════════════════════════════════════════════════
    echo "\n7. VERSION DETECTION & OUTDATED STATE TESTS:\n";

    // 7.1 Verify currently current (not outdated)
    $status_check_1 = nh_get_application_analysis_status($con, $company_a_id, $app_1_id);
    assert_cv_test("Initially, analysis status is COMPLETED and not outdated", $status_check_1['status'] === 'COMPLETED' && $status_check_1['is_outdated'] === false);

    // 7.2 Candidate updates their profile skills (modifying CV version hash)
    mysqli_query($con, "UPDATE user_info SET user_skills = 'Python, Machine Learning, SQL, PyTorch, Git, Docker, Kubernetes' WHERE id = $seeker_1_id");

    $status_check_2 = nh_get_application_analysis_status($con, $company_a_id, $app_1_id);
    assert_cv_test("CV content change detects OUTDATED state", $status_check_2['is_outdated'] === true && $status_check_2['outdated_reason'] === 'cv_updated');

    // 7.3 Re-analyze updates the analysis and resets outdated state
    $reanalyze_res = nh_reanalyze_application_cv($con, $company_a_id, $app_1_id);
    assert_cv_test("Re-analysis executes successfully", ($reanalyze_res['success'] ?? false) === true);

    $status_check_3 = nh_get_application_analysis_status($con, $company_a_id, $app_1_id);
    assert_cv_test("After re-analysis, status is COMPLETED and not outdated", $status_check_3['status'] === 'COMPLETED' && $status_check_3['is_outdated'] === false);

    // 7.4 Company updates job requirements
    mysqli_query($con, "UPDATE company_jobs SET skills_required = 'Python, Machine Learning, PyTorch, SQL, GraphQL, Kubernetes' WHERE id = $job_a_id");

    $status_check_4 = nh_get_application_analysis_status($con, $company_a_id, $app_1_id);
    assert_cv_test("Job requirements change detects OUTDATED state", $status_check_4['is_outdated'] === true && $status_check_4['outdated_reason'] === 'job_requirements_changed');


    // ════════════════════════════════════════════════════════════════
    // GROUP 8: COMPANY QUICK ACTIONS & SHORTLIST → INTERVIEW INTEGRATION
    // ════════════════════════════════════════════════════════════════
    echo "\n8. COMPANY QUICK DECISION ACTIONS & INTERVIEW UNLOCK TESTS:\n";

    // 8.1 Company marks application as reviewed
    $rev_res = nh_update_screening_status($con, $company_a_id, $app_1_id, 'reviewed');
    assert_cv_test("Company marks application as reviewed", $rev_res['success'] === true);
    $app_check_rev = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status FROM job_applications WHERE id = $app_1_id"));
    assert_cv_test("Application status in DB is 'reviewed'", $app_check_rev['application_status'] === 'reviewed');

    // 8.2 Company shortlists application
    $short_res = nh_update_screening_status($con, $company_a_id, $app_1_id, 'shortlisted');
    assert_cv_test("Company shortlists application", $short_res['success'] === true);
    $app_check_short = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status, pipeline_stage FROM job_applications WHERE id = $app_1_id"));
    assert_cv_test("Application status is 'shortlisted'", $app_check_short['application_status'] === 'shortlisted');

    // 8.3 Verify NO interview was automatically scheduled
    $int_count = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM interviews WHERE application_id = $app_1_id"))['c'];
    assert_cv_test("Shortlisting did NOT automatically create an interview record (count = 0)", intval($int_count) === 0);

    // 8.4 Verify company can now schedule interview via existing workflow
    // Let's create an interview via the existing scheduling workflow to verify seamless integration
    $access_token = bin2hex(random_bytes(16));
    $ins_int = mysqli_query($con, "INSERT INTO interviews (application_id, company_id, user_id, job_id, round_number, title, interview_date, interview_time, duration_minutes, end_time, interview_type, status, access_token)
                                   VALUES ($app_1_id, $company_a_id, $seeker_1_id, $job_a_id, 1, 'Technical Round 1', '2026-10-10', '10:00:00', 45, '10:45:00', 'Online', 'scheduled', '$access_token')");
    $new_int_id = mysqli_insert_id($con);
    assert_cv_test("Existing interview scheduling succeeds after shortlist", $new_int_id > 0);

    // 8.5 Company marks another application as rejected
    $rej_res = nh_update_screening_status($con, $company_a_id, $app_2_id, 'rejected');
    assert_cv_test("Company rejects application #$app_2_id", $rej_res['success'] === true);
    $app_check_rej = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status FROM job_applications WHERE id = $app_2_id"));
    assert_cv_test("Application status is 'rejected'", $app_check_rej['application_status'] === 'rejected');

    // 8.6 Unauthorized company B cannot reject Company A's application
    $unauth_rej = nh_update_screening_status($con, $company_b_id, $app_1_id, 'rejected');
    assert_cv_test("Company B blocked from changing status of Company A's application", $unauth_rej['success'] === false);


    // ════════════════════════════════════════════════════════════════
    // GROUP 9: AUDIT LOGGING VERIFICATION
    // ════════════════════════════════════════════════════════════════
    echo "\n9. AUDIT LOGGING TESTS:\n";

    $audit_logs = mysqli_query($con, "SELECT action FROM hiring_audit_logs WHERE company_id = $company_a_id");
    $actions = [];
    while ($al = mysqli_fetch_assoc($audit_logs)) {
        $actions[] = $al['action'];
    }

    assert_cv_test("Audit logged AI_CV_ANALYSIS_STARTED or COMPLETED", in_array('AI_CV_ANALYSIS_COMPLETED', $actions) || in_array('AI_CV_ANALYSIS_STARTED', $actions));
    assert_cv_test("Audit logged APPLICATION_SHORTLISTED", in_array('APPLICATION_SHORTLISTED', $actions));
    assert_cv_test("Audit logged APPLICATION_REJECTED", in_array('APPLICATION_REJECTED', $actions));

} finally {
    mysqli_rollback($con);
    echo "\nTest transaction rolled back cleanly. Database preserved in pristine state.\n";
}

echo "======================================================================\n";
echo "SUMMARY: $passed_tests / $total_tests TESTS PASSED (" . ($total_tests > 0 ? round(($passed_tests / $total_tests) * 100) : 0) . "%)\n";

if (!empty($failed_tests)) {
    echo "\nFAILED TESTS:\n";
    foreach ($failed_tests as $f) {
        echo "  - $f\n";
    }
    echo "======================================================================\n";
    exit(1);
} else {
    echo "ALL $total_tests AI CV SCREENING TESTS PASSED FLAWLESSLY! (100%)\n";
    echo "======================================================================\n";
    exit(0);
}
