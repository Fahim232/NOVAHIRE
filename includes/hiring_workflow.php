<?php
/**
 * NovaHire — Post-Interview Hiring Workflow Core Service Layer
 *
 * Implements business logic for:
 * - Staff interview feedback employment status validation
 * - Consolidated multi-staff feedback & discrepancy detection
 * - Joining date calculation and validation
 * - Hiring decisions persistence
 * - Document requirement management & security checks
 * - Appointment letter versioning & sending
 */

if (defined('HIRING_WORKFLOW_LOADED')) return;
define('HIRING_WORKFLOW_LOADED', true);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/functions.php';

/**
 * 1. Validate Staff Employment Status Feedback
 *
 * @param string|null $employment_status 'CURRENTLY_WORKING' or 'NOT_CURRENTLY_WORKING'
 * @param string|null $expected_leaving_date YYYY-MM-DD
 * @return array{valid: bool, status: ?string, leaving_date: ?string, error: ?string}
 */
function validate_employment_feedback($employment_status, $expected_leaving_date) {
    if ($employment_status === 'YES') $employment_status = 'CURRENTLY_WORKING';
    if ($employment_status === 'NO')  $employment_status = 'NOT_CURRENTLY_WORKING';

    if (!in_array($employment_status, ['CURRENTLY_WORKING', 'NOT_CURRENTLY_WORKING'], true)) {
        return [
            'valid' => false,
            'status' => null,
            'leaving_date' => null,
            'error' => 'Employment status selection is required (YES or NO).'
        ];
    }

    if ($employment_status === 'CURRENTLY_WORKING') {
        $date = trim((string)$expected_leaving_date);
        if ($date === '') {
            return [
                'valid' => false,
                'status' => 'CURRENTLY_WORKING',
                'leaving_date' => null,
                'error' => 'Expected leaving date is required when the candidate is currently working at another company.'
            ];
        }

        // Validate date format YYYY-MM-DD
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            return [
                'valid' => false,
                'status' => 'CURRENTLY_WORKING',
                'leaving_date' => null,
                'error' => 'Invalid expected leaving date format. Must be YYYY-MM-DD.'
            ];
        }

        return [
            'valid' => true,
            'status' => 'CURRENTLY_WORKING',
            'leaving_date' => $date,
            'error' => null
        ];
    }

    // NOT_CURRENTLY_WORKING must clear leaving date to NULL
    return [
        'valid' => true,
        'status' => 'NOT_CURRENTLY_WORKING',
        'leaving_date' => null,
        'error' => null
    ];
}

/**
 * 2. Get Consolidated Feedback & Discrepancies for an Interview / Application
 *
 * @param mysqli $con
 * @param int $interview_id
 * @return array
 */
function get_interview_feedback_consolidated($con, $interview_id) {
    $interview_id = intval($interview_id);
    $query = "SELECT ifb.*, cs.full_name AS staff_name, cs.designation AS staff_designation,
                     isa.is_primary
              FROM interview_feedback ifb
              JOIN company_staff cs ON ifb.staff_id = cs.id
              LEFT JOIN interview_staff_assignments isa ON (ifb.interview_id = isa.interview_id AND ifb.staff_id = isa.staff_id)
              WHERE ifb.interview_id = ?
              ORDER BY isa.is_primary DESC, ifb.created_at ASC";
    
    $feedbacks = [];
    $stmt = mysqli_prepare($con, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $interview_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $feedbacks[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    // Analyze discrepancies across staff feedback
    $emp_statuses = [];
    $leaving_dates = [];
    $all_submitted = true;
    $total_score_sum = 0;
    $count = count($feedbacks);

    foreach ($feedbacks as $fb) {
        if ($fb['status'] !== 'SUBMITTED') {
            $all_submitted = false;
        }
        $total_score_sum += intval($fb['total_score']);
        if (!empty($fb['employment_status'])) {
            $emp_statuses[$fb['staff_id']] = [
                'staff_name'   => $fb['staff_name'],
                'status'       => $fb['employment_status'],
                'leaving_date' => $fb['expected_leaving_date']
            ];
            if ($fb['expected_leaving_date']) {
                $leaving_dates[] = $fb['expected_leaving_date'];
            }
        }
    }

    // Check if conflicting answers exist
    $has_discrepancy = false;
    $discrepancy_msg = '';
    $unique_statuses = array_unique(array_column($emp_statuses, 'status'));
    $unique_dates = array_unique($leaving_dates);

    if (count($unique_statuses) > 1 || count($unique_dates) > 1) {
        $has_discrepancy = true;
        $parts = [];
        foreach ($emp_statuses as $st) {
            $lbl = ($st['status'] === 'CURRENTLY_WORKING') ? 'YES (Leaving: ' . date('d M Y', strtotime($st['leaving_date'])) . ')' : 'NO';
            $parts[] = htmlspecialchars($st['staff_name']) . ': ' . $lbl;
        }
        $discrepancy_msg = "Different interviewer responses: " . implode(' | ', $parts);
    }

    // Primary or consensus employment status
    $consensus_status = null;
    $consensus_leaving_date = null;
    if (!empty($emp_statuses)) {
        // Look for primary staff or first staff
        $first = reset($emp_statuses);
        $consensus_status = $first['status'];
        $consensus_leaving_date = $first['leaving_date'];
    }

    return [
        'feedbacks'              => $feedbacks,
        'count'                  => $count,
        'all_submitted'          => ($count > 0 && $all_submitted),
        'avg_score'              => $count > 0 ? round($total_score_sum / $count, 1) : 0,
        'has_discrepancy'        => $has_discrepancy,
        'discrepancy_message'    => $discrepancy_msg,
        'consensus_status'       => $consensus_status,
        'consensus_leaving_date' => $consensus_leaving_date
    ];
}

/**
 * 3. Calculate Suggested Joining Date
 *
 * @param string|null $employment_status
 * @param string|null $expected_leaving_date
 * @return string|null Suggested joining date (YYYY-MM-DD)
 */
function calculate_suggested_joining_date($employment_status, $expected_leaving_date) {
    if ($employment_status === 'CURRENTLY_WORKING' && !empty($expected_leaving_date)) {
        $ts = strtotime($expected_leaving_date);
        if ($ts !== false) {
            return date('Y-m-d', strtotime('+1 day', $ts));
        }
    }
    return null;
}

/**
 * 4. Validate Final Joining Date
 *
 * @param string $final_joining_date
 * @param string|null $employment_status
 * @param string|null $expected_leaving_date
 * @return array{valid: bool, error: ?string}
 */
function validate_final_joining_date($final_joining_date, $employment_status = null, $expected_leaving_date = null) {
    $date = trim((string)$final_joining_date);
    if ($date === '') {
        return ['valid' => false, 'error' => 'Final joining date is required.'];
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return ['valid' => false, 'error' => 'Invalid joining date format. Must be YYYY-MM-DD.'];
    }

    if ($employment_status === 'CURRENTLY_WORKING' && !empty($expected_leaving_date)) {
        $leaving_ts = strtotime($expected_leaving_date);
        $joining_ts = strtotime($date);
        if ($joining_ts <= $leaving_ts) {
            return [
                'valid' => false,
                'error' => 'Joining date (' . date('M d, Y', $joining_ts) . ') must be after the expected leaving date (' . date('M d, Y', $leaving_ts) . ').'
            ];
        }
    }

    return ['valid' => true, 'error' => null];
}

/**
 * 5. Save Company Final Hiring Decision
 *
 * @param mysqli $con
 * @param int $company_id
 * @param int $application_id
 * @param int $candidate_id
 * @param int $decided_by
 * @param string $decision 'selected' or 'rejected'
 * @param string $final_notes
 * @param string|null $suggested_joining_date
 * @param string|null $final_joining_date
 * @param string|null $emp_status
 * @param string|null $leaving_date
 * @return array{success: bool, error: ?string}
 */
function save_hiring_decision($con, $company_id, $application_id, $candidate_id, $decided_by, $decision, $final_notes = '', $suggested_joining_date = null, $final_joining_date = null, $emp_status = null, $leaving_date = null) {
    $company_id = intval($company_id);
    $application_id = intval($application_id);
    $candidate_id = intval($candidate_id);
    $decided_by = intval($decided_by);

    if (!in_array($decision, ['selected', 'rejected', 'under_final_review'], true)) {
        return ['success' => false, 'error' => 'Invalid decision value. Must be selected or rejected.'];
    }

    // Verify application belongs to company
    $app_check = mysqli_query($con, "SELECT ja.*, cj.job_title, c.company_name FROM job_applications ja JOIN company_jobs cj ON ja.job_id=cj.id JOIN companies c ON ja.company_id=c.id WHERE ja.id=$application_id AND ja.company_id=$company_id");
    if (!$app_check || mysqli_num_rows($app_check) === 0) {
        return ['success' => false, 'error' => 'Application not found or unauthorized.'];
    }
    $app = mysqli_fetch_assoc($app_check);

    // If decision is selected (HIRE), validate joining date if provided
    if ($decision === 'selected' && !empty($final_joining_date)) {
        $val_res = validate_final_joining_date($final_joining_date, $emp_status, $leaving_date);
        if (!$val_res['valid']) {
            return ['success' => false, 'error' => $val_res['error']];
        }
    }

    mysqli_begin_transaction($con);
    try {
        // 1. Insert or update hiring_decisions
        $chk_hd = mysqli_query($con, "SELECT id FROM hiring_decisions WHERE application_id = $application_id AND company_id = $company_id");
        $notes_esc = mysqli_real_escape_string($con, $final_notes);
        $sug_esc = $suggested_joining_date ? "'" . mysqli_real_escape_string($con, $suggested_joining_date) . "'" : "NULL";
        $final_esc = $final_joining_date ? "'" . mysqli_real_escape_string($con, $final_joining_date) . "'" : "NULL";
        $emp_esc = $emp_status ? "'" . mysqli_real_escape_string($con, $emp_status) . "'" : "NULL";
        $leave_esc = $leaving_date ? "'" . mysqli_real_escape_string($con, $leaving_date) . "'" : "NULL";
        $conf_by_esc = $final_joining_date ? $decided_by : "NULL";
        $conf_at_esc = $final_joining_date ? "NOW()" : "NULL";

        if (mysqli_num_rows($chk_hd) > 0) {
            $hd_id = mysqli_fetch_assoc($chk_hd)['id'];
            $hd_sql = "UPDATE hiring_decisions SET 
                        decision = '$decision', 
                        final_notes = '$notes_esc', 
                        decided_by = $decided_by,
                        suggested_joining_date = $sug_esc,
                        final_joining_date = $final_esc,
                        joining_confirmed_by = $conf_by_esc,
                        joining_confirmed_at = $conf_at_esc,
                        employment_status_noted = $emp_esc,
                        expected_leaving_date_noted = $leave_esc,
                        updated_at = NOW()
                       WHERE id = $hd_id";
        } else {
            $hd_sql = "INSERT INTO hiring_decisions 
                        (application_id, company_id, candidate_id, decided_by, decision, final_notes, suggested_joining_date, final_joining_date, joining_confirmed_by, joining_confirmed_at, employment_status_noted, expected_leaving_date_noted, created_at, updated_at)
                       VALUES 
                        ($application_id, $company_id, $candidate_id, $decided_by, '$decision', '$notes_esc', $sug_esc, $final_esc, $conf_by_esc, $conf_at_esc, $emp_esc, $leave_esc, NOW(), NOW())";
        }
        mysqli_query($con, $hd_sql);

        // 2. Update job_applications application_status and pipeline_stage
        $pipe_stage = ($decision === 'selected') ? 'offered' : (($decision === 'rejected') ? 'rejected' : 'interview');
        $upd_app = "UPDATE job_applications SET application_status = '$decision', pipeline_stage = '$pipe_stage', stage_updated_at = NOW() WHERE id = $application_id";
        mysqli_query($con, $upd_app);

        // 3. Log audit event
        $action_name = ($decision === 'selected') ? 'HIRING_DECISION_HIRED' : (($decision === 'under_final_review') ? 'HIRING_DECISION_HOLD' : 'HIRING_DECISION_REJECTED');
        $details = "Candidate " . ($decision === 'selected' ? 'Selected for Hire' : (($decision === 'under_final_review') ? 'Placed on Final Review Hold' : 'Rejected'));
        if ($final_joining_date) {
            $details .= ". Confirmed joining date: $final_joining_date";
        }
        log_hiring_audit($con, $company_id, 'company', $decided_by, $action_name, 'job_applications', $application_id, $details);
        if ($decision === 'selected') {
            log_hiring_audit($con, $company_id, 'company', $decided_by, 'CANDIDATE_SELECTED', 'job_applications', $application_id, "Candidate formally selected by company");
        }

        // 4. Send notification to seeker
        if ($decision === 'selected') {
            $notif_title = "Congratulations! You have been selected";
            $notif_msg = "Congratulations! You have been selected for the position of <strong>{$app['job_title']}</strong> at <strong>{$app['company_name']}</strong>. Please complete your appointment response to confirm your readiness and provide required details.";
        } elseif ($decision === 'under_final_review') {
            $notif_title = "Application Under Final Review";
            $notif_msg = "Your application for the position of <strong>{$app['job_title']}</strong> at <strong>{$app['company_name']}</strong> is currently under final review. We will update you once a decision has been made.";
        } else {
            $notif_title = "Update on your application";
            $notif_msg = "Thank you for your interest in the position of {$app['job_title']} at {$app['company_name']}. Unfortunately, the company has decided not to move forward at this time.";
        }
        create_notification($con, 'user', $candidate_id, 'company', $company_id, $notif_title, $notif_msg, 'application_status', 'job_applications', $application_id);

        mysqli_commit($con);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        mysqli_rollback($con);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 6. Create Document Requirements Checklist
 *
 * @param mysqli $con
 * @param int $company_id
 * @param int $application_id
 * @param int $candidate_id
 * @param array $requirements List of ['document_type' => ..., 'title' => ..., 'description' => ...]
 * @param int $created_by
 * @return array{success: bool, count: int, error: ?string}
 */
function create_document_requirements($con, $company_id, $application_id, $candidate_id, array $requirements, $created_by) {
    if (empty($requirements)) {
        return ['success' => false, 'count' => 0, 'error' => 'No requirements provided.'];
    }

    $company_id = intval($company_id);
    $application_id = intval($application_id);
    $candidate_id = intval($candidate_id);
    $created_by = intval($created_by);
    $count = 0;

    foreach ($requirements as $req) {
        $type = mysqli_real_escape_string($con, trim($req['document_type'] ?? 'Other Document'));
        $title = mysqli_real_escape_string($con, trim($req['title'] ?? $type));
        $desc = mysqli_real_escape_string($con, trim($req['description'] ?? ''));

        // Check if already requested
        $chk = mysqli_query($con, "SELECT id FROM hiring_document_requirements WHERE application_id = $application_id AND document_type = '$type'");
        if (mysqli_num_rows($chk) === 0) {
            $sql = "INSERT INTO hiring_document_requirements (company_id, application_id, candidate_id, document_type, title, description, is_required, status, created_by)
                    VALUES ($company_id, $application_id, $candidate_id, '$type', '$title', '$desc', 1, 'REQUESTED', $created_by)";
            if (mysqli_query($con, $sql)) {
                $count++;
            }
        }
    }

    if ($count > 0) {
        log_hiring_audit($con, $company_id, 'company', $created_by, 'DOCUMENT_REQUIREMENTS_REQUESTED', 'job_applications', $application_id, "$count document(s) requested");
        create_notification($con, 'user', $candidate_id, 'company', $company_id, "Required Hiring Documents Requested", "Your employer has requested onboarding documents. Please submit them through your hiring dashboard.", 'system', 'hiring_document_requirements', $application_id);
    }

    return ['success' => true, 'count' => $count, 'error' => null];
}

/**
 * 7. Verify Secure Document Access (Anti-IDOR)
 *
 * @param mysqli $con
 * @param string $user_type 'user' or 'company' or 'staff'
 * @param int $user_id
 * @param int $document_id
 * @return array{allowed: bool, document: ?array, error: ?string}
 */
function verify_document_access($con, $user_type, $user_id, $document_id) {
    $document_id = intval($document_id);
    $user_id = intval($user_id);

    $sql = "SELECT cd.*, hdr.title AS requirement_title, hdr.document_type, ja.company_id, ja.user_id AS applicant_id
            FROM candidate_documents cd
            JOIN hiring_document_requirements hdr ON cd.requirement_id = hdr.id
            JOIN job_applications ja ON cd.application_id = ja.id
            WHERE cd.id = $document_id";
    $res = mysqli_query($con, $sql);
    if (!$res || mysqli_num_rows($res) === 0) {
        return ['allowed' => false, 'document' => null, 'error' => 'Document not found.'];
    }
    $doc = mysqli_fetch_assoc($res);

    if ($user_type === 'user') {
        if (intval($doc['candidate_id']) !== $user_id) {
            return ['allowed' => false, 'document' => null, 'error' => 'Unauthorized access to document.'];
        }
        return ['allowed' => true, 'document' => $doc, 'error' => null];
    }

    if ($user_type === 'company') {
        if (intval($doc['company_id']) !== $user_id) {
            return ['allowed' => false, 'document' => null, 'error' => 'Unauthorized access: company mismatch.'];
        }
        return ['allowed' => true, 'document' => $doc, 'error' => null];
    }

    if ($user_type === 'staff') {
        // Verify staff belongs to the company owning this document
        $st_chk = mysqli_query($con, "SELECT company_id FROM company_staff WHERE id = $user_id AND status = 'active'");
        if ($st_chk && mysqli_num_rows($st_chk) > 0) {
            $st_comp = intval(mysqli_fetch_assoc($st_chk)['company_id']);
            if ($st_comp === intval($doc['company_id'])) {
                return ['allowed' => true, 'document' => $doc, 'error' => null];
            }
        }
        return ['allowed' => false, 'document' => null, 'error' => 'Unauthorized staff access.'];
    }

    return ['allowed' => false, 'document' => null, 'error' => 'Invalid user role.'];
}

/**
 * 8. Update Document Verification Status (Verify or Reject)
 *
 * @param mysqli $con
 * @param int $document_id
 * @param int $company_id
 * @param string $status 'VERIFIED' or 'REJECTED'
 * @param string|null $rejection_reason
 * @param int $verified_by
 * @return array{success: bool, error: ?string}
 */
function update_document_verification($con, $document_id, $company_id, $status, $rejection_reason = null, $verified_by = 0) {
    $document_id = intval($document_id);
    $company_id = intval($company_id);
    $verified_by = intval($verified_by);

    if (!in_array($status, ['VERIFIED', 'REJECTED'], true)) {
        return ['success' => false, 'error' => 'Invalid verification status.'];
    }

    if ($status === 'REJECTED' && empty(trim((string)$rejection_reason))) {
        return ['success' => false, 'error' => 'A rejection reason is required to reject a document.'];
    }

    $chk = verify_document_access($con, 'company', $company_id, $document_id);
    if (!$chk['allowed']) {
        return ['success' => false, 'error' => $chk['error']];
    }
    $doc = $chk['document'];

    $reason_esc = $rejection_reason ? "'" . mysqli_real_escape_string($con, $rejection_reason) . "'" : "NULL";

    mysqli_begin_transaction($con);
    try {
        // Update candidate_documents
        $sql = "UPDATE candidate_documents SET 
                status = '$status', 
                rejection_reason = $reason_esc, 
                verified_by = $verified_by, 
                verified_at = NOW() 
                WHERE id = $document_id";
        mysqli_query($con, $sql);

        // Update hiring_document_requirements status
        $req_sql = "UPDATE hiring_document_requirements SET status = '$status' WHERE id = " . intval($doc['requirement_id']);
        mysqli_query($con, $req_sql);

        // Audit log
        $action = ($status === 'VERIFIED') ? 'DOCUMENT_VERIFIED' : 'DOCUMENT_REJECTED';
        $details = "Document: {$doc['requirement_title']} (" . ($status === 'VERIFIED' ? 'Verified' : "Rejected: $rejection_reason") . ")";
        log_hiring_audit($con, $company_id, 'company', $verified_by, $action, 'candidate_documents', $document_id, $details);

        // Notify seeker
        $notif_title = ($status === 'VERIFIED') 
            ? "Document Verified: {$doc['requirement_title']}"
            : "Action Required: {$doc['requirement_title']} was rejected";
        $notif_msg = ($status === 'VERIFIED')
            ? "Your document '{$doc['requirement_title']}' has been reviewed and verified by the company."
            : "Your document '{$doc['requirement_title']}' was rejected: " . htmlspecialchars($rejection_reason) . ". Please re-upload a clear copy.";
        create_notification($con, 'user', $doc['candidate_id'], 'company', $company_id, $notif_title, $notif_msg, 'system', 'candidate_documents', $document_id);

        mysqli_commit($con);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        mysqli_rollback($con);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 9. Save Appointment Letter Version
 *
 * @param mysqli $con
 * @param int $company_id
 * @param int $candidate_id
 * @param int $application_id
 * @param int $job_id
 * @param string $content
 * @param string $subject
 * @param string $generation_method 'AI_GENERATED', 'MANUAL_EDIT', 'TEMPLATE'
 * @param string|null $joining_date
 * @param string|null $salary
 * @param string|null $designation
 * @param string|null $work_location
 * @param int $created_by
 * @return array{success: bool, letter_id: int, version_id: int, version_number: int, error: ?string}
 */
function save_appointment_letter_version($con, $company_id, $candidate_id, $application_id, $job_id, $content, $subject, $generation_method = 'AI_GENERATED', $joining_date = null, $salary = null, $designation = null, $work_location = null, $created_by = 0) {
    $company_id = intval($company_id);
    $candidate_id = intval($candidate_id);
    $application_id = intval($application_id);
    $job_id = intval($job_id);
    $created_by = intval($created_by);

    $content_esc = mysqli_real_escape_string($con, $content);
    $subj_esc = mysqli_real_escape_string($con, $subject);
    $join_esc = $joining_date ? "'" . mysqli_real_escape_string($con, $joining_date) . "'" : "NULL";
    $sal_esc = $salary ? "'" . mysqli_real_escape_string($con, $salary) . "'" : "NULL";
    $des_esc = $designation ? "'" . mysqli_real_escape_string($con, $designation) . "'" : "NULL";
    $loc_esc = $work_location ? "'" . mysqli_real_escape_string($con, $work_location) . "'" : "NULL";

    mysqli_begin_transaction($con);
    try {
        // Find or create hiring_letter parent
        $hl_chk = mysqli_query($con, "SELECT id FROM hiring_letters WHERE application_id = $application_id AND company_id = $company_id LIMIT 1");
        if ($hl_chk && mysqli_num_rows($hl_chk) > 0) {
            $letter_id = intval(mysqli_fetch_assoc($hl_chk)['id']);
            $upd_hl = "UPDATE hiring_letters SET 
                        subject = '$subj_esc', 
                        content = '$content_esc', 
                        joining_date = $join_esc,
                        salary = $sal_esc,
                        designation = $des_esc,
                        work_location = $loc_esc,
                        updated_at = NOW() 
                       WHERE id = $letter_id";
            mysqli_query($con, $upd_hl);
        } else {
            $ins_hl = "INSERT INTO hiring_letters (company_id, candidate_id, application_id, job_id, status, letter_type, subject, content, joining_date, salary, designation, work_location, created_by, created_at, updated_at)
                       VALUES ($company_id, $candidate_id, $application_id, $job_id, 'DRAFT', 'Appointment Letter', '$subj_esc', '$content_esc', $join_esc, $sal_esc, $des_esc, $loc_esc, $created_by, NOW(), NOW())";
            mysqli_query($con, $ins_hl);
            $letter_id = mysqli_insert_id($con);
        }

        // Determine next version number
        $v_res = mysqli_query($con, "SELECT MAX(version_number) AS max_v FROM appointment_letter_versions WHERE hiring_letter_id = $letter_id");
        $next_v = 1;
        if ($v_res && $row = mysqli_fetch_assoc($v_res)) {
            $next_v = intval($row['max_v']) + 1;
        }

        // Insert new version
        $ins_v = "INSERT INTO appointment_letter_versions (hiring_letter_id, application_id, version_number, subject, content, generation_method, status, created_by, created_at)
                  VALUES ($letter_id, $application_id, $next_v, '$subj_esc', '$content_esc', '$generation_method', 'DRAFT', $created_by, NOW())";
        mysqli_query($con, $ins_v);
        $version_id = mysqli_insert_id($con);

        // Update current_version_id in hiring_letters
        mysqli_query($con, "UPDATE hiring_letters SET current_version_id = $version_id WHERE id = $letter_id");

        mysqli_commit($con);
        return [
            'success' => true,
            'letter_id' => $letter_id,
            'version_id' => $version_id,
            'version_number' => $next_v,
            'error' => null
        ];
    } catch (Exception $e) {
        mysqli_rollback($con);
        return ['success' => false, 'letter_id' => 0, 'version_id' => 0, 'version_number' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * 10. Send Official Appointment Letter
 *
 * @param mysqli $con
 * @param int $letter_id
 * @param int $company_id
 * @param int $sent_by
 * @return array{success: bool, error: ?string}
 */
function send_appointment_letter($con, $letter_id, $company_id, $sent_by = 0) {
    $letter_id = intval($letter_id);
    $company_id = intval($company_id);

    $sql = "SELECT hl.*, cj.job_title, c.company_name, ui.username, ui.email
            FROM hiring_letters hl
            JOIN company_jobs cj ON hl.job_id = cj.id
            JOIN companies c ON hl.company_id = c.id
            JOIN user_info ui ON hl.candidate_id = ui.id
            WHERE hl.id = $letter_id AND hl.company_id = $company_id";
    $res = mysqli_query($con, $sql);
    if (!$res || mysqli_num_rows($res) === 0) {
        return ['success' => false, 'error' => 'Appointment letter not found or unauthorized.'];
    }
    $letter = mysqli_fetch_assoc($res);

    // Verify joining date is present
    if (empty($letter['joining_date'])) {
        return ['success' => false, 'error' => 'Cannot send appointment letter without a confirmed joining date.'];
    }

    if (empty(trim($letter['content']))) {
        return ['success' => false, 'error' => 'Appointment letter content cannot be empty.'];
    }

    mysqli_begin_transaction($con);
    try {
        // Update hiring_letters to SENT
        mysqli_query($con, "UPDATE hiring_letters SET status = 'SENT', approved_by = $sent_by, approved_at = NOW(), sent_at = NOW(), updated_at = NOW() WHERE id = $letter_id");

        // Update current version to SENT
        if (!empty($letter['current_version_id'])) {
            mysqli_query($con, "UPDATE appointment_letter_versions SET status = 'SENT' WHERE id = " . intval($letter['current_version_id']));
        }

        // Update job_applications pipeline_stage to 'hired'
        mysqli_query($con, "UPDATE job_applications SET pipeline_stage = 'hired', stage_updated_at = NOW() WHERE id = " . intval($letter['application_id']));

        // Log audit event
        log_hiring_audit($con, $company_id, 'company', $sent_by, 'APPOINTMENT_LETTER_SENT', 'hiring_letters', $letter_id, "Official appointment sent to {$letter['username']} with joining date: {$letter['joining_date']}");

        // Notify seeker
        $title = "Official Appointment Letter Received!";
        $message = "Congratulations! <strong>{$letter['company_name']}</strong> has issued your official Appointment Letter for the position of <strong>{$letter['job_title']}</strong>. Joining Date: <strong>" . date('d M Y', strtotime($letter['joining_date'])) . "</strong>.";
        create_notification($con, 'user', $letter['candidate_id'], 'company', $company_id, $title, $message, 'application_status', 'hiring_letters', $letter_id);

        send_message($con, 'company', $company_id, 'user', $letter['candidate_id'],
            "Official Appointment Letter: {$letter['job_title']}",
            $message . "\n\nPlease view your appointment letter and submit any pending onboarding documents.",
            $letter['job_id']
        );

        mysqli_commit($con);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        mysqli_rollback($con);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 11. Save Candidate Selection Response
 *
 * After a company selects (ACCEPT) a candidate, the candidate submits:
 * - Whether they are ready to join (yes/no)
 * - If declining, a reason
 * - Employment details (currently working, company, leaving date, notice period)
 * - Approximate joining/availability date
 * - Any additional notes
 *
 * @param mysqli $con
 * @param int $application_id
 * @param int $candidate_id
 * @param array $data Associative array of form data
 * @return array{success: bool, error: ?string}
 */
function save_candidate_selection_response($con, $application_id, $candidate_id, array $data) {
    $application_id = intval($application_id);
    $candidate_id = intval($candidate_id);

    // Verify application belongs to candidate and decision is 'selected'
    $app_check = mysqli_prepare($con, "
        SELECT ja.*, hd.decision, hd.id AS hd_id, cj.job_title, c.company_name, c.id AS comp_id, ui.username
        FROM job_applications ja
        JOIN hiring_decisions hd ON ja.id = hd.application_id
        JOIN company_jobs cj ON ja.job_id = cj.id
        JOIN companies c ON ja.company_id = c.id
        JOIN user_info ui ON ja.user_id = ui.id
        WHERE ja.id = ? AND ja.user_id = ? AND hd.decision = 'selected'
    ");
    if (!$app_check) {
        return ['success' => false, 'error' => 'Database error.'];
    }
    mysqli_stmt_bind_param($app_check, "ii", $application_id, $candidate_id);
    mysqli_stmt_execute($app_check);
    $app_res = mysqli_stmt_get_result($app_check);
    $app = mysqli_fetch_assoc($app_res);
    mysqli_stmt_close($app_check);

    if (!$app) {
        return ['success' => false, 'error' => 'Application not found, unauthorized, or not yet selected.'];
    }

    $ready_to_join = in_array($data['ready_to_join'] ?? '', ['yes', 'no'], true) ? $data['ready_to_join'] : null;
    if (!$ready_to_join) {
        return ['success' => false, 'error' => 'Please indicate whether you are ready to join.'];
    }

    $decline_reason = null;
    if ($ready_to_join === 'no') {
        $decline_reason = trim($data['decline_reason'] ?? '');
        if (empty($decline_reason)) {
            return ['success' => false, 'error' => 'Please provide a reason for declining.'];
        }
    }

    // Validate employment fields if accepting
    $currently_working = null;
    $current_company_name = null;
    $expected_leaving_date = null;
    $notice_period_days = null;
    $available_immediately = null;
    $approximate_joining_date = null;
    $candidate_notes = trim($data['candidate_notes'] ?? '');

    if ($ready_to_join === 'yes') {
        $currently_working = in_array($data['currently_working'] ?? '', ['yes', 'no'], true) ? $data['currently_working'] : null;
        if (!$currently_working) {
            return ['success' => false, 'error' => 'Please indicate whether you are currently working.'];
        }

        if ($currently_working === 'yes') {
            $current_company_name = trim($data['current_company_name'] ?? '');
            $expected_leaving_date = trim($data['expected_leaving_date'] ?? '');
            $notice_period_days = intval($data['notice_period_days'] ?? 0);
            $available_immediately = 'no';

            if (empty($expected_leaving_date)) {
                return ['success' => false, 'error' => 'Expected leaving date is required when currently working.'];
            }
            $dt = DateTime::createFromFormat('Y-m-d', $expected_leaving_date);
            if (!$dt || $dt->format('Y-m-d') !== $expected_leaving_date) {
                return ['success' => false, 'error' => 'Invalid expected leaving date format. Use YYYY-MM-DD.'];
            }
        } else {
            $available_immediately = in_array($data['available_immediately'] ?? '', ['yes', 'no'], true) ? $data['available_immediately'] : 'yes';
        }

        $approximate_joining_date = trim($data['approximate_joining_date'] ?? '');
        if (empty($approximate_joining_date)) {
            return ['success' => false, 'error' => 'Approximate joining/availability date is required.'];
        }
        $dt_join = DateTime::createFromFormat('Y-m-d', $approximate_joining_date);
        if (!$dt_join || $dt_join->format('Y-m-d') !== $approximate_joining_date) {
            return ['success' => false, 'error' => 'Invalid joining date format. Use YYYY-MM-DD.'];
        }

        // If currently working, joining date must be after leaving date
        if ($currently_working === 'yes' && !empty($expected_leaving_date)) {
            if (strtotime($approximate_joining_date) <= strtotime($expected_leaving_date)) {
                return ['success' => false, 'error' => 'Approximate joining date must be after your expected leaving date.'];
            }
        }
    }

    $company_id = intval($app['comp_id']);
    $hd_id = intval($app['hd_id']);

    mysqli_begin_transaction($con);
    try {
        // Check if response already exists (update) or insert new
        $existing = mysqli_prepare($con, "SELECT id FROM candidate_employment_availability WHERE application_id = ? AND candidate_id = ?");
        mysqli_stmt_bind_param($existing, "ii", $application_id, $candidate_id);
        mysqli_stmt_execute($existing);
        $ex_res = mysqli_stmt_get_result($existing);
        $ex_row = mysqli_fetch_assoc($ex_res);
        mysqli_stmt_close($existing);

        $ready_esc = mysqli_real_escape_string($con, $ready_to_join);
        $decline_esc = $decline_reason !== null ? "'" . mysqli_real_escape_string($con, $decline_reason) . "'" : "NULL";
        $cw_esc = $currently_working ? "'" . mysqli_real_escape_string($con, $currently_working) . "'" : "NULL";
        $ccn_esc = !empty($current_company_name) ? "'" . mysqli_real_escape_string($con, $current_company_name) . "'" : "NULL";
        $eld_esc = !empty($expected_leaving_date) ? "'" . mysqli_real_escape_string($con, $expected_leaving_date) . "'" : "NULL";
        $npd_esc = $notice_period_days > 0 ? intval($notice_period_days) : "NULL";
        $ai_esc = $available_immediately ? "'" . mysqli_real_escape_string($con, $available_immediately) . "'" : "NULL";
        $ajd_esc = !empty($approximate_joining_date) ? "'" . mysqli_real_escape_string($con, $approximate_joining_date) . "'" : "NULL";
        $cn_esc = !empty($candidate_notes) ? "'" . mysqli_real_escape_string($con, $candidate_notes) . "'" : "NULL";

        if ($ex_row) {
            $cea_id = intval($ex_row['id']);
            $sql = "UPDATE candidate_employment_availability SET
                        ready_to_join = '$ready_esc',
                        decline_reason = $decline_esc,
                        currently_working = $cw_esc,
                        current_company_name = $ccn_esc,
                        expected_leaving_date = $eld_esc,
                        notice_period_days = $npd_esc,
                        available_immediately = $ai_esc,
                        approximate_joining_date = $ajd_esc,
                        candidate_notes = $cn_esc,
                        updated_at = NOW()
                    WHERE id = $cea_id";
            mysqli_query($con, $sql);
        } else {
            $sql = "INSERT INTO candidate_employment_availability
                        (application_id, candidate_id, ready_to_join, decline_reason, currently_working, current_company_name, expected_leaving_date, notice_period_days, available_immediately, approximate_joining_date, candidate_notes, submitted_at, updated_at)
                    VALUES
                        ($application_id, $candidate_id, '$ready_esc', $decline_esc, $cw_esc, $ccn_esc, $eld_esc, $npd_esc, $ai_esc, $ajd_esc, $cn_esc, NOW(), NOW())";
            mysqli_query($con, $sql);
        }

        // Update hiring_decisions.candidate_response_status
        $response_status = ($ready_to_join === 'yes') ? 'accepted' : 'declined';
        mysqli_query($con, "UPDATE hiring_decisions SET candidate_response_status = '$response_status' WHERE id = $hd_id");

        // If candidate declines, update application pipeline_stage
        if ($ready_to_join === 'no') {
            mysqli_query($con, "UPDATE job_applications SET application_status = 'declined_by_candidate', pipeline_stage = 'rejected', stage_updated_at = NOW() WHERE id = $application_id");
        }

        // Audit log
        $action = ($ready_to_join === 'yes') ? 'CANDIDATE_ACCEPTED_SELECTION' : 'CANDIDATE_DECLINED_SELECTION';
        $audit_details = ($ready_to_join === 'yes')
            ? "Candidate confirmed readiness. Approximate joining: $approximate_joining_date"
            : "Candidate declined. Reason: $decline_reason";
        log_hiring_audit($con, $company_id, 'user', $candidate_id, $action, 'job_applications', $application_id, $audit_details);

        // Notify company
        if ($ready_to_join === 'yes') {
            $notif_title = "Candidate Ready to Join: {$app['username']}";
            $notif_msg = "<strong>{$app['username']}</strong> has confirmed readiness for the position of <strong>{$app['job_title']}</strong>. Approximate joining date: <strong>" . date('M d, Y', strtotime($approximate_joining_date)) . "</strong>. Please review their submission and proceed with the appointment letter.";
        } else {
            $notif_title = "Candidate Declined: {$app['username']}";
            $notif_msg = "<strong>{$app['username']}</strong> has declined the offer for the position of <strong>{$app['job_title']}</strong>. Reason: " . htmlspecialchars($decline_reason);
        }
        create_notification($con, 'company', $company_id, 'user', $candidate_id, $notif_title, $notif_msg, 'application_status', 'job_applications', $application_id);

        mysqli_commit($con);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        mysqli_rollback($con);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 12. Get Candidate Selection Response
 *
 * @param mysqli $con
 * @param int $application_id
 * @return array|null
 */
function get_candidate_selection_response($con, $application_id) {
    $application_id = intval($application_id);
    $stmt = mysqli_prepare($con, "SELECT * FROM candidate_employment_availability WHERE application_id = ? ORDER BY id DESC LIMIT 1");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "i", $application_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * 13. Verify Selection Response Access (Anti-IDOR)
 *
 * @param mysqli $con
 * @param int $application_id
 * @param int $candidate_id
 * @return array{allowed: bool, application: ?array, error: ?string}
 */
function verify_selection_response_access($con, $application_id, $candidate_id) {
    $application_id = intval($application_id);
    $candidate_id = intval($candidate_id);

    $stmt = mysqli_prepare($con, "
        SELECT ja.*, hd.decision, hd.candidate_response_status, cj.job_title, c.company_name, c.id AS comp_id
        FROM job_applications ja
        LEFT JOIN hiring_decisions hd ON ja.id = hd.application_id
        JOIN company_jobs cj ON ja.job_id = cj.id
        JOIN companies c ON ja.company_id = c.id
        WHERE ja.id = ? AND ja.user_id = ?
    ");
    if (!$stmt) {
        return ['allowed' => false, 'application' => null, 'error' => 'Database error.'];
    }
    mysqli_stmt_bind_param($stmt, "ii", $application_id, $candidate_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $app = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$app) {
        return ['allowed' => false, 'application' => null, 'error' => 'Application not found or unauthorized.'];
    }

    if (($app['decision'] ?? '') !== 'selected') {
        return ['allowed' => false, 'application' => $app, 'error' => 'This application has not been selected yet.'];
    }

    return ['allowed' => true, 'application' => $app, 'error' => null];
}
