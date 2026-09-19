<?php
/**
 * NovaHire — Job Alerts Helper
 */

if (defined('NOVAHIRE_JOB_ALERTS_LOADED')) return;
define('NOVAHIRE_JOB_ALERTS_LOADED', true);

/**
 * Generate notifications for newly created or updated job alerts matching active jobs
 */
function create_job_alert_notifications($con, $user_id) {
    if (!$con || !$user_id) return;

    // Check if job_alerts table exists
    $tchk = @mysqli_query($con, "SHOW TABLES LIKE 'job_alerts'");
    if (!$tchk || mysqli_num_rows($tchk) === 0) return;

    // Fetch user active alerts
    $stmt = mysqli_prepare($con, "SELECT * FROM job_alerts WHERE user_id = ? AND is_active = 1");
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($alert = mysqli_fetch_assoc($res)) {
        $kw = trim($alert['keyword'] ?? '');
        $cat = trim($alert['category'] ?? '');
        
        $where = ["status = 'active'"];
        if (!empty($kw)) {
            $safe_kw = mysqli_real_escape_string($con, $kw);
            $where[] = "(job_title LIKE '%$safe_kw%' OR job_description LIKE '%$safe_kw%')";
        }
        if (!empty($cat)) {
            $safe_cat = mysqli_real_escape_string($con, $cat);
            $where[] = "job_category = '$safe_cat'";
        }

        $wsql = implode(' AND ', $where);
        $jq = @mysqli_query($con, "SELECT id, job_title FROM company_jobs WHERE $wsql ORDER BY id DESC LIMIT 3");
        if ($jq) {
            while ($job = mysqli_fetch_assoc($jq)) {
                if (function_exists('create_notification')) {
                    create_notification(
                        $con,
                        'user',
                        $user_id,
                        'system',
                        0,
                        'Job Alert Match',
                        'New job matching your alert: ' . $job['job_title'],
                        'job_alert',
                        'job',
                        $job['id']
                    );
                }
            }
        }
    }
    mysqli_stmt_close($stmt);
}

/**
 * Send notifications to users whose job alerts match a newly posted job
 */
function send_job_alerts_to_subscribers($con, $job_id) {
    if (!$con || !$job_id) return;

    // Check if job_alerts table exists
    $tchk = @mysqli_query($con, "SHOW TABLES LIKE 'job_alerts'");
    if (!$tchk || mysqli_num_rows($tchk) === 0) return;

    $jstmt = mysqli_prepare($con, "SELECT id, job_title, job_category, job_description FROM company_jobs WHERE id = ?");
    if (!$jstmt) return;
    mysqli_stmt_bind_param($jstmt, "i", $job_id);
    mysqli_stmt_execute($jstmt);
    $job_res = mysqli_stmt_get_result($jstmt);
    $job = mysqli_fetch_assoc($job_res);
    mysqli_stmt_close($jstmt);
    if (!$job) return;

    $alerts_res = @mysqli_query($con, "SELECT ja.user_id, ja.keyword, ja.category FROM job_alerts ja WHERE ja.is_active = 1");
    if (!$alerts_res) return;

    $notified = [];
    while ($alert = mysqli_fetch_assoc($alerts_res)) {
        $uid = (int)$alert['user_id'];
        if (isset($notified[$uid])) continue;

        $match = true;
        if (!empty($alert['category']) && strtolower($alert['category']) !== 'all') {
            if (strcasecmp($alert['category'], $job['job_category']) !== 0) {
                $match = false;
            }
        }
        if ($match && !empty($alert['keyword'])) {
            $kw = mb_strtolower($alert['keyword']);
            $title = mb_strtolower($job['job_title']);
            $desc = mb_strtolower($job['job_description']);
            if (strpos($title, $kw) === false && strpos($desc, $kw) === false) {
                $match = false;
            }
        }

        if ($match) {
            $notified[$uid] = true;
            if (function_exists('create_notification')) {
                create_notification(
                    $con,
                    'user',
                    $uid,
                    'system',
                    0,
                    'New Job Alert Match',
                    'A new job matching your alert was posted: ' . $job['job_title'],
                    'job_alert',
                    'job',
                    $job['id']
                );
            }
        }
    }
}
