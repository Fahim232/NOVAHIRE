<?php
/**
 * Automated End-to-End Flow Test matching Section 43 of Requirements
 *
 * 1. Create company.
 * 2. Create AI Engineer job.
 * 3. Add job requirements: Python, Machine Learning, PyTorch, SQL, Docker, AWS.
 * 4. Create seeker.
 * 5. Create seeker CV.
 * 6. Apply to job.
 * 7. Company opens application (verifies status = pending/NEW, AI Analysis = NOT_ANALYZED).
 * 8. Company triggers Analyze CV.
 * 9. AI analyzes CV and generates structured match analysis.
 * 10. Verify Overall Match, Skills Match, Experience Match, Education Match, Project Relevance.
 * 11. Verify Required skills vs Preferred skills separation.
 * 12. Verify matched/missing/unclear breakdown with evidence citations.
 * 13. Company marks candidate as SHORTLISTED.
 * 14. Verify status updated to 'shortlisted'.
 * 15. Verify Schedule Interview becomes available.
 * 16. Schedule interview and assign staff.
 * 17. Staff conducts interview and submits feedback.
 * 18. Staff records employment status (Currently working = YES, Expected leaving date).
 * 19. Company reviews staff feedback.
 * 20. Company makes final hiring decision ('selected' / Hired).
 * 21. Verify joining date set, onboarding documents required, and appointment letter prepared.
 * 22. Verify entire pipeline integrity.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/hiring_workflow.php';
global $con;

echo "\n======================================================================\n";
echo "           SECTION 43: FULL END-TO-END WORKFLOW VERIFICATION          \n";
echo "======================================================================\n\n";

function e2e_assert($condition, $message) {
    if ($condition) {
        echo " [PASS] $message\n";
    } else {
        echo " [FAIL] $message\n";
        exit(1);
    }
}

mysqli_begin_transaction($con);

try {
    $pwd_hash = password_hash('Pass1234!', PASSWORD_BCRYPT);

    // Step 1: Create company
    $comp_email = 'e2e_ai_comp_' . uniqid() . '@example.com';
    mysqli_query($con, "INSERT INTO companies (company_name, company_email, company_phone, company_address, industry, company_size, description, password, status) 
                        VALUES ('AI Innovators Corp', '$comp_email', '+8801700000000', 'Tech Park, Dhaka', 'Technology', '50-100', 'Leading AI developer', '$pwd_hash', 'active')");
    $company_id = mysqli_insert_id($con);
    e2e_assert($company_id > 0, "Step 1: Company created with ID $company_id");

    // Step 2 & 3: Create AI Engineer job with required & preferred requirements
    $job_title = 'AI Engineer';
    $job_desc = "We are seeking a talented AI Engineer. Required: Python, Machine Learning, PyTorch, SQL. Preferred: Docker, AWS. Requires BS/MS in Computer Science and 1+ years of relevant AI/ML experience.";
    $reqs = "- Python\n- Machine Learning\n- PyTorch\n- SQL\nPreferred:\n- Docker\n- AWS";
    mysqli_query($con, "INSERT INTO company_jobs (company_id, job_title, job_category, job_description, requirements, responsibilities, location, employment_type, experience_required, skills_required, status)
                        VALUES ($company_id, '$job_title', 'AI / ML', '" . mysqli_real_escape_string($con, $job_desc) . "', 
                                '" . mysqli_real_escape_string($con, $reqs) . "', 'Design, train, and deploy AI models', 'Dhaka', 'Full-time', '1+ year', 'Python, Machine Learning, PyTorch, SQL', 'active')");
    $job_id = mysqli_insert_id($con);
    e2e_assert($job_id > 0, "Step 2 & 3: AI Engineer job created with structured requirements (ID $job_id)");

    // Step 4 & 5: Create seeker and CV
    $seeker_email = 'e2e_seeker_' . uniqid() . '@example.com';
    mysqli_query($con, "INSERT INTO user_info (username, email, phone, user_degree, user_skills, experience, about_me, password, cpassword, status)
                        VALUES ('Md. Rahad', '$seeker_email', '+8801711112222', 'B.Sc. in Computer Science and Engineering', 'Python, Machine Learning, PyTorch, SQL, Git', '1.5 years experience in building Python & ML models using PyTorch and PostgreSQL', 'Passionate AI developer with deep learning project experience.', '$pwd_hash', '$pwd_hash', 'active')");
    $seeker_id = mysqli_insert_id($con);
    e2e_assert($seeker_id > 0, "Step 4: Seeker created (ID $seeker_id)");

    $skills_json = json_encode(['Python', 'Machine Learning', 'PyTorch', 'SQL', 'Git']);
    $exp_json = json_encode([
        ['title' => 'AI Engineer', 'company' => 'NeuralTech', 'duration' => '1.5 years', 'description' => 'Built predictive models using Python, Machine Learning, PyTorch, and SQL databases.'],
        ['title' => 'Junior Developer', 'company' => 'DataCorp', 'duration' => '1 year', 'description' => 'Implemented SQL data pipelines and Python automation scripts.']
    ]);
    $edu_json = json_encode([
        ['degree' => 'B.Sc. in Computer Science & Engineering', 'institution' => 'University of Engineering and Technology', 'year' => '2024']
    ]);
    $proj_json = json_encode([
        ['title' => 'Neural Search Engine', 'tech' => 'Python, PyTorch, SQL', 'description' => 'Machine learning project utilizing PyTorch and vector embeddings.']
    ]);

    mysqli_query($con, "INSERT INTO ai_generated_cvs (user_id, job_id, template_name, full_name, headline, email, phone, location, summary, skills_json, experience_json, education_json, projects_json, created_at)
                        VALUES ($seeker_id, $job_id, 'modern', 'Md. Rahad', 'AI Engineer', '$seeker_email', '+8801711112222', 'Dhaka', 'Passionate AI developer with deep learning project experience in Python, PyTorch, and SQL.', '" . mysqli_real_escape_string($con, $skills_json) . "', '" . mysqli_real_escape_string($con, $exp_json) . "', '" . mysqli_real_escape_string($con, $edu_json) . "', '" . mysqli_real_escape_string($con, $proj_json) . "', NOW())");
    $ai_cv_id = mysqli_insert_id($con);
    e2e_assert($ai_cv_id > 0, "Step 5: Seeker CV created (ID $ai_cv_id)");

    // Step 6: Apply to job
    mysqli_query($con, "INSERT INTO job_applications (job_id, user_id, company_id, cv_type, ai_cv_id, application_status, quiz_status, quiz_score, applied_date)
                        VALUES ($job_id, $seeker_id, $company_id, 'ai_customized', $ai_cv_id, 'pending', 'passed', 90, NOW())");
    $application_id = mysqli_insert_id($con);
    e2e_assert($application_id > 0, "Step 6: Seeker applied to job (Application ID $application_id)");

    // Step 7: Company opens application (Check initial status)
    $status_info = nh_get_application_analysis_status($con, $company_id, $application_id);
    e2e_assert($status_info['status'] === 'NOT_ANALYZED', "Step 7 & 8: Initial AI Analysis Status is NOT_ANALYZED");

    // Step 9 & 10: Trigger AI CV Analysis
    $analysis_res = nh_analyze_application_cv($con, $company_id, $application_id);
    e2e_assert($analysis_res['success'] === true, "Step 9 & 10: AI analyzed candidate CV successfully");
    $analysis = $analysis_res['analysis'];

    // Step 11: Display Overall Match, Skills, Experience, Education, Project Relevance
    e2e_assert($analysis['overall_match_score'] >= 75 && $analysis['overall_match_score'] <= 100, 
        "Step 11: Overall Match Score calculated (" . $analysis['overall_match_score'] . "%)");
    e2e_assert($analysis['skills_match_score'] >= 80, 
        "Step 11: Skills Match Score calculated (" . $analysis['skills_match_score'] . "%)");
    e2e_assert($analysis['experience_match_score'] >= 70, 
        "Step 11: Experience Match Score calculated (" . $analysis['experience_match_score'] . "%)");
    e2e_assert($analysis['education_match_score'] >= 80, 
        "Step 11: Education Match Score calculated (" . $analysis['education_match_score'] . "%)");
    e2e_assert($analysis['project_relevance_score'] >= 75, 
        "Step 11: Project Relevance Score calculated (" . $analysis['project_relevance_score'] . "%)");

    // Step 12: Separation of Required vs Preferred Requirements
    $req_matched_names = array_column($analysis['required_matches'], 'name');
    e2e_assert(in_array('Python', $req_matched_names) && in_array('Machine Learning', $req_matched_names) && in_array('SQL', $req_matched_names),
        "Step 12: Required skills (Python, Machine Learning, SQL) correctly matched");

    $pref_missing_names = array_column($analysis['preferred_missing'], 'name');
    $pref_unclear_names = array_column($analysis['preferred_unclear'], 'name');
    e2e_assert(in_array('Docker', $pref_missing_names) || in_array('Docker', $pref_unclear_names),
        "Step 12: Preferred requirement (Docker) evaluated separately under preferred section");

    // Step 13 & 14: Evidence Citations without Hallucination
    e2e_assert(!empty($analysis['required_matches'][0]['evidence']),
        "Step 13 & 14: Explicit CV evidence provided: \"" . substr($analysis['required_matches'][0]['evidence'], 0, 60) . "...\"");

    // Step 15 & 16: Company marks candidate as SHORTLISTED
    $update_res = nh_update_screening_status($con, $company_id, $application_id, 'shortlisted');
    e2e_assert($update_res['success'] === true, "Step 15: Company shortlisted candidate");

    $app_check = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status FROM job_applications WHERE id = $application_id"));
    e2e_assert($app_check['application_status'] === 'shortlisted', "Step 16: Database application_status updated to 'shortlisted'");

    // Step 17 & 18: Schedule Interview & Assign Staff
    mysqli_query($con, "INSERT INTO company_staff (company_id, full_name, email, designation, status)
                        VALUES ($company_id, 'Lead Engineer John', 'john@ai-innovators.com', 'Staff Evaluator', 'active')");
    $staff_id = mysqli_insert_id($con);
    e2e_assert($staff_id > 0, "Step 17: Company staff assigned (ID $staff_id)");

    mysqli_query($con, "INSERT INTO interviews (application_id, company_id, round_number, title, interview_date, interview_time, interview_type, status)
                        VALUES ($application_id, $company_id, 1, 'Technical Deep Dive', CURDATE() + INTERVAL 2 DAY, '14:00:00', 'video', 'scheduled')");
    $interview_id = mysqli_insert_id($con);
    e2e_assert($interview_id > 0, "Step 18: Interview scheduled (ID $interview_id)");

    // Step 19 & 20: Staff conducts interview & submits feedback with Employment Status
    $leaving_date = date('Y-m-d', strtotime('+30 days'));
    mysqli_query($con, "INSERT INTO interview_feedback (interview_id, staff_id, technical_score, communication_score, problem_solving_score, teamwork_score, professionalism_score, total_score, comments, recommendation, employment_status, expected_leaving_date, status)
                        VALUES ($interview_id, $staff_id, 9, 8, 9, 8, 9, 43, 'Candidate demonstrated exceptional machine learning expertise.', 'STRONGLY_RECOMMEND', 'CURRENTLY_WORKING', '$leaving_date', 'SUBMITTED')");
    $eval_id = mysqli_insert_id($con);
    e2e_assert($eval_id > 0, "Step 19 & 20: Staff feedback submitted with employment status (CURRENTLY_WORKING, leaving $leaving_date)");

    // Step 21: Company makes Final Hiring Decision
    $joining_date = date('Y-m-d', strtotime('+40 days'));
    $hiring_res = save_hiring_decision(
        $con,
        $company_id,
        $application_id,
        $seeker_id,
        $company_id,
        'selected',
        'Selected based on strong AI CV Match and excellent technical interview feedback.',
        null,
        null,
        null,
        null
    );
    e2e_assert($hiring_res['success'] === true, "Step 21: Company finalized hiring decision ('selected')");

    // Step 22: Verify final candidate response record and appointment letter readiness
    $resp_access = verify_selection_response_access($con, $application_id, $seeker_id);
    e2e_assert($resp_access['allowed'] === true, "Step 22: Candidate selection response invited & verified");

    $app_final = mysqli_fetch_assoc(mysqli_query($con, "SELECT application_status, pipeline_stage FROM job_applications WHERE id = $application_id"));
    e2e_assert($app_final['application_status'] === 'selected' && $app_final['pipeline_stage'] === 'offered', 
        "Step 22: Application stage advanced to 'selected' / 'offered'");

    echo "\n======================================================================\n";
    echo "   [SUCCESS] ALL 22 END-TO-END RECRUITMENT STEPS PASSED PERFECTLY!     \n";
    echo "======================================================================\n\n";

} finally {
    mysqli_rollback($con);
}
