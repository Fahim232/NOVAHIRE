<?php
/**
 * NovaHire — Placement & Recommendation Engine
 * Handles smart job matching, applicant pipeline tracking, and placement fees.
 */

if (defined('NOVAHIRE_PLACEMENT_LOADED')) return;
define('NOVAHIRE_PLACEMENT_LOADED', true);

if (!isset($con)) {
    require_once __DIR__ . '/bootstrap.php';
}
if (!function_exists('is_user_pro')) {
    require_once __DIR__ . '/monetization.php';
}

/**
 * Check if a job posting has active featured / boost status
 */
function nh_is_job_featured($job) {
    if (empty($job) || empty($job['is_featured'])) return false;
    if (!empty($job['featured_until'])) {
        return strtotime($job['featured_until']) > time();
    }
    return true;
}

/**
 * List of pipeline stages
 */
function nh_pipeline_stages() {
    return ['applied', 'reviewed', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'];
}

/**
 * Human readable label for pipeline stages
 */
function nh_stage_label($stage) {
    $labels = [
        'applied'     => 'Applied',
        'reviewed'    => 'Reviewed',
        'shortlisted' => 'Shortlisted',
        'interview'   => 'Interviewing',
        'offered'     => 'Offer Extended',
        'hired'       => 'Hired',
        'rejected'    => 'Rejected',
    ];
    return $labels[$stage] ?? ucfirst($stage);
}

/**
 * Badge color for pipeline stages
 */
function nh_stage_color($stage) {
    $colors = [
        'applied'     => '#64748b',
        'reviewed'    => '#0284c7',
        'shortlisted' => '#7c3aed',
        'interview'   => '#d97706',
        'offered'     => '#059669',
        'hired'       => '#10b981',
        'rejected'    => '#dc2626',
    ];
    return $colors[$stage] ?? '#64748b';
}

/**
 * Advance or set an applicant's pipeline stage
 */
function nh_set_pipeline_stage($con, $application_id, $stage) {
    if (!$con || !$application_id) return false;
    $valid_stages = nh_pipeline_stages();
    if (!in_array($stage, $valid_stages)) return false;

    $stmt = mysqli_prepare($con, "UPDATE job_applications SET pipeline_stage = ?, stage_updated_at = NOW() WHERE id = ?");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, "si", $stage, $application_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // If candidate is hired, record placement in placements table
    if ($ok && $stage === 'hired' && nh_table_exists($con, 'placements')) {
        $chk = mysqli_prepare($con, "SELECT id FROM placements WHERE application_id = ? LIMIT 1");
        if ($chk) {
            mysqli_stmt_bind_param($chk, "i", $application_id);
            mysqli_stmt_execute($chk);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
            mysqli_stmt_close($chk);

            if (!$existing) {
                // Fetch application details to create placement record
                $app_stmt = mysqli_prepare($con, "SELECT ja.job_id, ja.user_id, ja.company_id, cj.salary_range FROM job_applications ja JOIN company_jobs cj ON ja.job_id = cj.id WHERE ja.id = ?");
                if ($app_stmt) {
                    mysqli_stmt_bind_param($app_stmt, "i", $application_id);
                    mysqli_stmt_execute($app_stmt);
                    $app_data = mysqli_fetch_assoc(mysqli_stmt_get_result($app_stmt));
                    mysqli_stmt_close($app_stmt);

                    if ($app_data) {
                        $fee = 5000.00; // Standard placement commission
                        $salary = 50000.00;
                        if (!empty($app_data['salary_range']) && preg_match('/(\d+)/', str_replace(',', '', $app_data['salary_range']), $m)) {
                            $salary = (float)$m[1];
                        }
                        $pins = mysqli_prepare($con, "INSERT INTO placements (application_id, job_id, user_id, company_id, salary_amount, placement_fee, fee_status, hired_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', CURDATE())");
                        if ($pins) {
                            mysqli_stmt_bind_param($pins, "iiiidd", $application_id, $app_data['job_id'], $app_data['user_id'], $app_data['company_id'], $salary, $fee);
                            mysqli_stmt_execute($pins);
                            mysqli_stmt_close($pins);
                        }
                    }
                }
            }
        }
    }

    return $ok;
}

/**
 * Placement statistics for admin revenue or company dashboard
 */
function nh_placement_stats($con, $company_id = null) {
    if (!$con || !nh_table_exists($con, 'placements')) {
        return ['total' => 0, 'fee_pending' => 0, 'fee_paid' => 0, 'revenue' => 0.0];
    }

    $where = $company_id ? "WHERE company_id = " . (int)$company_id : "";
    $sql = "SELECT COUNT(*) as total, 
                   SUM(CASE WHEN fee_status = 'pending' THEN 1 ELSE 0 END) as fee_pending, 
                   SUM(CASE WHEN fee_status = 'paid' THEN 1 ELSE 0 END) as fee_paid, 
                   COALESCE(SUM(CASE WHEN fee_status = 'paid' THEN placement_fee ELSE 0 END), 0) as revenue 
            FROM placements $where";
    $r = @mysqli_query($con, $sql);
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        return [
            'total'       => (int)($row['total'] ?? 0),
            'fee_pending' => (int)($row['fee_pending'] ?? 0),
            'fee_paid'    => (int)($row['fee_paid'] ?? 0),
            'revenue'     => (float)($row['revenue'] ?? 0.0)
        ];
    }

    return ['total' => 0, 'fee_pending' => 0, 'fee_paid' => 0, 'revenue' => 0.0];
}

/**
 * Retrieve and rank candidate talent pool for a company
 */
function nh_company_talent_pool($con, $company_id) {
    if (!$con || !$company_id) return [];
    
    $query = "SELECT ja.id as application_id, ja.job_id, ja.user_id, ja.quiz_score, ja.pipeline_stage, ja.applied_date,
                     cj.job_title, cj.skills_required,
                     ui.username, ui.user_skills, ui.profile
              FROM job_applications ja
              JOIN company_jobs cj ON ja.job_id = cj.id
              JOIN user_info ui ON ja.user_id = ui.id
              WHERE ja.company_id = ?
              ORDER BY ja.id DESC";

    $stmt = mysqli_prepare($con, $query);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, "i", $company_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $pool = [];

    while ($row = mysqli_fetch_assoc($res)) {
        // Skill matching score calculation
        $u_skills = array_filter(array_map('trim', explode(',', strtolower((string)$row['user_skills']))));
        $j_skills = array_filter(array_map('trim', explode(',', strtolower((string)$row['skills_required']))));
        
        $match_score = 70; // baseline
        if (!empty($j_skills)) {
            $matched = array_intersect($u_skills, $j_skills);
            $match_score = round((count($matched) / max(1, count($j_skills))) * 100);
        }
        if ($row['quiz_score'] !== null) {
            $match_score = round(($match_score * 0.6) + ((int)$row['quiz_score'] * 0.4));
        }

        $row['match_score'] = $match_score;
        $row['is_pro'] = is_user_pro($con, $row['user_id']);
        $row['grooming_passed'] = 1; // placeholder for verified grooming
        $pool[] = $row;
    }
    mysqli_stmt_close($stmt);

    // Sort by match_score descending, pro users given priority boost
    usort($pool, function($a, $b) {
        $scoreA = $a['match_score'] + ($a['is_pro'] ? 5 : 0);
        $scoreB = $b['match_score'] + ($b['is_pro'] ? 5 : 0);
        return $scoreB <=> $scoreA;
    });

    return $pool;
}

/**
 * AI-powered Job Recommendations based on candidate skills
 */
function nh_get_recommendations($con, $user, $limit = 10) {
    if (!$con) return [];

    $user_skills_raw = (string)($user['user_skills'] ?? '');
    $user_skills = array_filter(array_map('trim', explode(',', strtolower($user_skills_raw))));
    if (empty($user_skills)) return [];

    $sql = "SELECT cj.*, c.company_name, c.logo as company_logo, c.company_address as company_location
            FROM company_jobs cj
            LEFT JOIN companies c ON cj.company_id = c.id
            WHERE cj.status = 'active'
            ORDER BY cj.is_featured DESC, cj.id DESC LIMIT 50";

    $res = @mysqli_query($con, $sql);
    if (!$res) return [];

    // Load AI matching engine
    require_once __DIR__ . '/../ai/matching.php';

    $scored_jobs = [];
    while ($job = mysqli_fetch_assoc($res)) {
        $ai_data = ai_match_profile_job($user, $job);

        // Boost score if featured
        if (!empty($job['is_featured'])) {
            $ai_data['score'] = min(100, $ai_data['score'] + 5);
        }

        $job['ai'] = $ai_data;
        $scored_jobs[] = $job;
    }

    // Sort by AI score descending
    usort($scored_jobs, fn($a, $b) => $b['ai']['score'] <=> $a['ai']['score']);

    return array_slice($scored_jobs, 0, $limit);
}
