<?php
/**
 * NovaHire — AI CV Screening & Candidate Screening Service Layer
 *
 * Implements business operations for:
 * - Anti-IDOR application authorization
 * - Caching and version-safe AI CV analysis
 * - CV / Job requirements outdated detection
 * - Persistence in `ai_cv_analyses`
 * - Audit logging
 * - Company quick decision workflow (Review, Shortlist, Reject)
 * - Safe error handling (AI failure never alters candidate/application status)
 */

if (defined('NH_AI_CV_SCREENER_LOADED')) return;
define('NH_AI_CV_SCREENER_LOADED', true);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../ai/cv_screener.php';

/**
 * 1. Verify Company Access to Application (Anti-IDOR)
 *
 * @param mysqli $con
 * @param int $company_id
 * @param int $application_id
 * @return array{allowed: bool, application: ?array, error: ?string}
 */
function nh_verify_company_application_access($con, $company_id, $application_id) {
    $company_id = intval($company_id);
    $application_id = intval($application_id);

    if ($company_id <= 0 || $application_id <= 0) {
        return ['allowed' => false, 'application' => null, 'error' => 'Invalid parameters.'];
    }

    $sql = "SELECT ja.*, cj.company_id AS job_company_id, cj.job_title, cj.job_category,
                   ui.username AS candidate_name, ui.email AS candidate_email
            FROM job_applications ja
            JOIN company_jobs cj ON ja.job_id = cj.id
            JOIN user_info ui ON ja.user_id = ui.id
            WHERE ja.id = $application_id";
    $res = mysqli_query($con, $sql);
    if (!$res || mysqli_num_rows($res) === 0) {
        return ['allowed' => false, 'application' => null, 'error' => 'Application not found.'];
    }

    $app = mysqli_fetch_assoc($res);

    // Multi-tenant isolation: application must belong to company, and job must belong to company
    if (intval($app['company_id']) !== $company_id || intval($app['job_company_id']) !== $company_id) {
        return ['allowed' => false, 'application' => null, 'error' => 'Unauthorized access: company mismatch.'];
    }

    return ['allowed' => true, 'application' => $app, 'error' => null];
}

/**
 * 2. Get Application Analysis & Version Status
 *
 * @param mysqli $con
 * @param int $company_id
 * @param int $application_id
 * @return array{
 *     status: string,
 *     is_outdated: bool,
 *     outdated_reason: ?string,
 *     analysis: ?array
 * }
 */
function nh_get_application_analysis_status($con, $company_id, $application_id) {
    $auth = nh_verify_company_application_access($con, $company_id, $application_id);
    if (!$auth['allowed']) {
        return ['status' => 'UNAUTHORIZED', 'is_outdated' => false, 'outdated_reason' => null, 'analysis' => null];
    }

    $application_id = intval($application_id);
    $res = mysqli_query($con, "SELECT * FROM ai_cv_analyses WHERE application_id = $application_id");
    if (!$res || mysqli_num_rows($res) === 0) {
        return ['status' => 'NOT_ANALYZED', 'is_outdated' => false, 'outdated_reason' => null, 'analysis' => null];
    }

    $row = mysqli_fetch_assoc($res);
    $analysis_status = $row['status'];

    if ($analysis_status !== 'COMPLETED') {
        return ['status' => $analysis_status, 'is_outdated' => false, 'outdated_reason' => null, 'analysis' => $row];
    }

    // Check version hashes
    $job_id = intval($row['job_id']);
    $cv_data = nh_extract_application_cv_data($con, $application_id);
    $job_reqs = nh_extract_job_screening_requirements($con, $job_id);

    $is_outdated = false;
    $outdated_reason = null;

    if ($cv_data['hash'] !== $row['cv_hash']) {
        $is_outdated = true;
        $outdated_reason = 'cv_updated';
    } elseif ($job_reqs['hash'] !== $row['job_req_hash']) {
        $is_outdated = true;
        $outdated_reason = 'job_requirements_changed';
    }

    // Decode JSON fields for consumption
    $row['required_matches']  = json_decode($row['required_matches'] ?? '[]', true) ?: [];
    $row['required_missing']  = json_decode($row['required_missing'] ?? '[]', true) ?: [];
    $row['required_unclear']  = json_decode($row['required_unclear'] ?? '[]', true) ?: [];
    $row['preferred_matches'] = json_decode($row['preferred_matches'] ?? '[]', true) ?: [];
    $row['preferred_missing'] = json_decode($row['preferred_missing'] ?? '[]', true) ?: [];
    $row['preferred_unclear'] = json_decode($row['preferred_unclear'] ?? '[]', true) ?: [];
    $row['strengths']         = json_decode($row['strengths'] ?? '[]', true) ?: [];
    $row['gaps']              = json_decode($row['gaps'] ?? '[]', true) ?: [];
    $row['evidence']          = json_decode($row['evidence'] ?? '[]', true) ?: [];

    $row['required_requirements'] = [
        'matched' => $row['required_matches'],
        'missing' => $row['required_missing'],
        'unclear' => $row['required_unclear']
    ];
    $row['preferred_requirements'] = [
        'matched' => $row['preferred_matches'],
        'missing' => $row['preferred_missing'],
        'unclear' => $row['preferred_unclear']
    ];

    return [
        'status'          => $is_outdated ? 'OUTDATED' : 'COMPLETED',
        'is_outdated'     => $is_outdated,
        'outdated_reason' => $outdated_reason,
        'analysis'        => $row
    ];
}

/**
 * 3. Analyze Application CV (Cached if up-to-date, or freshly generated)
 *
 * @param mysqli $con
 * @param int $company_id
 * @param int $application_id
 * @param bool $force Force re-analysis even if current
 * @return array{success: bool, analysis: ?array, error: ?string}
 */
function nh_analyze_application_cv($con, $company_id, $application_id, $force = false) {
    $auth = nh_verify_company_application_access($con, $company_id, $application_id);
    if (!$auth['allowed']) {
        return ['success' => false, 'analysis' => null, 'error' => $auth['error']];
    }

    $app = $auth['application'];
    $job_id = intval($app['job_id']);
    $seeker_id = intval($app['user_id']);

    // Check if valid completed analysis exists and is up to date
    if (!$force) {
        $curr = nh_get_application_analysis_status($con, $company_id, $application_id);
        if ($curr['status'] === 'COMPLETED' && !$curr['is_outdated'] && !empty($curr['analysis'])) {
            return ['success' => true, 'analysis' => $curr['analysis'], 'error' => null];
        }
    }

    // Extract CV and Job data
    $cv_data = nh_extract_application_cv_data($con, $application_id);
    $job_reqs = nh_extract_job_screening_requirements($con, $job_id);

    // Audit log start
    log_hiring_audit($con, $company_id, 'company', $company_id, 
        $force ? 'AI_CV_ANALYSIS_REANALYZED' : 'AI_CV_ANALYSIS_STARTED', 
        'job_applications', $application_id, 
        "AI CV screening initiated for application #$application_id");

    try {
        // Run screening engine
        $eval = nh_evaluate_cv_screening($cv_data, $job_reqs);

        // Validate structure
        $val = nh_validate_ai_cv_analysis_payload($eval);
        if (!$val['valid']) {
            nh_record_ai_analysis_failure($con, $company_id, $application_id, "Malformed AI response: " . $val['error']);
            return ['success' => false, 'analysis' => null, 'error' => "Malformed AI response: " . $val['error']];
        }

        // Persist to database
        $overall_score = intval($eval['overall_match_score']);
        $skills_score = intval($eval['skills_match_score']);
        $exp_score = intval($eval['experience_match_score']);
        $edu_score = intval($eval['education_match_score']);
        $proj_score = intval($eval['project_relevance_score']);

        $req_matches_json = mysqli_real_escape_string($con, json_encode($eval['required_requirements']['matched'] ?? []));
        $req_missing_json = mysqli_real_escape_string($con, json_encode($eval['required_requirements']['missing'] ?? []));
        $req_unclear_json = mysqli_real_escape_string($con, json_encode($eval['required_requirements']['unclear'] ?? []));

        $pref_matches_json = mysqli_real_escape_string($con, json_encode($eval['preferred_requirements']['matched'] ?? []));
        $pref_missing_json = mysqli_real_escape_string($con, json_encode($eval['preferred_requirements']['missing'] ?? []));
        $pref_unclear_json = mysqli_real_escape_string($con, json_encode($eval['preferred_requirements']['unclear'] ?? []));

        $strengths_json = mysqli_real_escape_string($con, json_encode($eval['strengths'] ?? []));
        $gaps_json = mysqli_real_escape_string($con, json_encode($eval['gaps'] ?? []));
        $evidence_json = mysqli_real_escape_string($con, json_encode($eval['evidence'] ?? []));
        $summary_esc = mysqli_real_escape_string($con, $eval['summary'] ?? '');
        $model_esc = mysqli_real_escape_string($con, $eval['model'] ?? 'NovaHire Screening Engine');

        $cv_type_esc = mysqli_real_escape_string($con, $cv_data['cv_type']);
        $cv_ref_esc = mysqli_real_escape_string($con, $cv_data['cv_reference']);
        $cv_hash_esc = mysqli_real_escape_string($con, $cv_data['hash']);
        $job_hash_esc = mysqli_real_escape_string($con, $job_reqs['hash']);

        // Check if row already exists
        $chk = mysqli_query($con, "SELECT id FROM ai_cv_analyses WHERE application_id = $application_id");
        if (mysqli_num_rows($chk) > 0) {
            $sql = "UPDATE ai_cv_analyses SET
                        job_id = $job_id,
                        seeker_id = $seeker_id,
                        company_id = $company_id,
                        cv_type = '$cv_type_esc',
                        cv_reference = '$cv_ref_esc',
                        cv_hash = '$cv_hash_esc',
                        job_req_hash = '$job_hash_esc',
                        overall_match_score = $overall_score,
                        skills_match_score = $skills_score,
                        experience_match_score = $exp_score,
                        education_match_score = $edu_score,
                        project_relevance_score = $proj_score,
                        required_matches = '$req_matches_json',
                        required_missing = '$req_missing_json',
                        required_unclear = '$req_unclear_json',
                        preferred_matches = '$pref_matches_json',
                        preferred_missing = '$pref_missing_json',
                        preferred_unclear = '$pref_unclear_json',
                        strengths = '$strengths_json',
                        gaps = '$gaps_json',
                        evidence = '$evidence_json',
                        summary = '$summary_esc',
                        status = 'COMPLETED',
                        model = '$model_esc',
                        error_message = NULL,
                        analyzed_at = NOW()
                    WHERE application_id = $application_id";
        } else {
            $sql = "INSERT INTO ai_cv_analyses
                    (application_id, job_id, seeker_id, company_id, cv_type, cv_reference, cv_hash, job_req_hash,
                     overall_match_score, skills_match_score, experience_match_score, education_match_score, project_relevance_score,
                     required_matches, required_missing, required_unclear, preferred_matches, preferred_missing, preferred_unclear,
                     strengths, gaps, evidence, summary, status, model, analyzed_at)
                    VALUES
                    ($application_id, $job_id, $seeker_id, $company_id, '$cv_type_esc', '$cv_ref_esc', '$cv_hash_esc', '$job_hash_esc',
                     $overall_score, $skills_score, $exp_score, $edu_score, $proj_score,
                     '$req_matches_json', '$req_missing_json', '$req_unclear_json', '$pref_matches_json', '$pref_missing_json', '$pref_unclear_json',
                     '$strengths_json', '$gaps_json', '$evidence_json', '$summary_esc', 'COMPLETED', '$model_esc', NOW())";
        }

        if (!mysqli_query($con, $sql)) {
            $db_err = mysqli_error($con);
            nh_record_ai_analysis_failure($con, $company_id, $application_id, "Database error: $db_err");
            return ['success' => false, 'analysis' => null, 'error' => "Database error: $db_err"];
        }

        // Audit log success
        log_hiring_audit($con, $company_id, 'company', $company_id, 'AI_CV_ANALYSIS_COMPLETED', 'job_applications', $application_id, [
            'score' => $overall_score,
            'model' => $eval['model'] ?? 'NovaHire'
        ]);

        $eval['status'] = 'COMPLETED';
        $eval['application_id'] = $application_id;
        $eval['cv_hash'] = $cv_data['hash'];
        $eval['job_req_hash'] = $job_reqs['hash'];
        $eval['analyzed_at'] = date('Y-m-d H:i:s');
        $eval['required_matches']  = $eval['required_requirements']['matched'] ?? [];
        $eval['required_missing']  = $eval['required_requirements']['missing'] ?? [];
        $eval['required_unclear']  = $eval['required_requirements']['unclear'] ?? [];
        $eval['preferred_matches'] = $eval['preferred_requirements']['matched'] ?? [];
        $eval['preferred_missing'] = $eval['preferred_requirements']['missing'] ?? [];
        $eval['preferred_unclear'] = $eval['preferred_requirements']['unclear'] ?? [];

        return ['success' => true, 'analysis' => $eval, 'error' => null];

    } catch (Throwable $e) {
        nh_record_ai_analysis_failure($con, $company_id, $application_id, $e->getMessage());
        return ['success' => false, 'analysis' => null, 'error' => $e->getMessage()];
    }
}

/**
 * 4. Re-analyze Application CV (Bypasses cache)
 *
 * @param mysqli $con
 * @param int $company_id
 * @param int $application_id
 * @return array
 */
function nh_reanalyze_application_cv($con, $company_id, $application_id) {
    return nh_analyze_application_cv($con, $company_id, $application_id, true);
}

/**
 * 5. Record Analysis Failure Safely (Non-Destructive)
 *
 * @param mysqli $con
 * @param int $company_id
 * @param int $application_id
 * @param string $error_message
 */
function nh_record_ai_analysis_failure($con, $company_id, $application_id, $error_message) {
    $application_id = intval($application_id);
    $company_id = intval($company_id);
    $err_esc = mysqli_real_escape_string($con, $error_message);

    $app_q = mysqli_query($con, "SELECT job_id, user_id FROM job_applications WHERE id = $application_id");
    if ($app_q && mysqli_num_rows($app_q) > 0) {
        $app = mysqli_fetch_assoc($app_q);
        $job_id = intval($app['job_id']);
        $seeker_id = intval($app['user_id']);

        $chk = mysqli_query($con, "SELECT id FROM ai_cv_analyses WHERE application_id = $application_id");
        if (mysqli_num_rows($chk) > 0) {
            mysqli_query($con, "UPDATE ai_cv_analyses SET status = 'FAILED', error_message = '$err_esc' WHERE application_id = $application_id");
        } else {
            mysqli_query($con, "INSERT INTO ai_cv_analyses (application_id, job_id, seeker_id, company_id, cv_hash, job_req_hash, status, error_message)
                                VALUES ($application_id, $job_id, $seeker_id, $company_id, '', '', 'FAILED', '$err_esc')");
        }
    }

    log_hiring_audit($con, $company_id, 'company', $company_id, 'AI_CV_ANALYSIS_FAILED', 'job_applications', $application_id, $error_message);
}

/**
 * 6. Quick Company Application Actions (Review, Shortlist, Reject)
 *
 * @param mysqli $con
 * @param int $company_id
 * @param int $application_id
 * @param string $new_status 'reviewed' | 'shortlisted' | 'rejected' | 'pending'
 * @return array{success: bool, error: ?string}
 */
function nh_update_screening_status($con, $company_id, $application_id, $new_status) {
    $auth = nh_verify_company_application_access($con, $company_id, $application_id);
    if (!$auth['allowed']) {
        return ['success' => false, 'error' => $auth['error']];
    }

    $allowed = ['pending', 'reviewed', 'shortlisted', 'rejected'];
    if (!in_array($new_status, $allowed, true)) {
        return ['success' => false, 'error' => 'Invalid screening status.'];
    }

    $application_id = intval($application_id);
    $company_id = intval($company_id);
    $app = $auth['application'];
    $candidate_id = intval($app['user_id']);
    $job_title = $app['job_title'];

    // Map pipeline stage
    $pipeline_stage = 'applied';
    if ($new_status === 'reviewed') $pipeline_stage = 'reviewed';
    if ($new_status === 'shortlisted') $pipeline_stage = 'shortlisted';
    if ($new_status === 'rejected') $pipeline_stage = 'rejected';

    $sql = "UPDATE job_applications SET application_status = '$new_status', pipeline_stage = '$pipeline_stage', stage_updated_at = NOW() WHERE id = $application_id";
    if (!mysqli_query($con, $sql)) {
        return ['success' => false, 'error' => mysqli_error($con)];
    }

    // Audit log
    $action_map = [
        'reviewed'    => 'APPLICATION_REVIEWED',
        'shortlisted' => 'APPLICATION_SHORTLISTED',
        'rejected'    => 'APPLICATION_REJECTED',
        'pending'     => 'APPLICATION_RESET_NEW'
    ];
    log_hiring_audit($con, $company_id, 'company', $company_id, $action_map[$new_status], 'job_applications', $application_id, "Application status set to $new_status");

    // Notification to seeker
    if ($new_status !== 'pending') {
        $comp_name_q = mysqli_query($con, "SELECT company_name FROM companies WHERE id = $company_id");
        $company_name = ($comp_name_q && mysqli_num_rows($comp_name_q) > 0) ? mysqli_fetch_assoc($comp_name_q)['company_name'] : 'Company';

        notify_application_status($con, $candidate_id, $company_id, $job_title, $company_name, $new_status);
    }

    return ['success' => true, 'error' => null];
}
