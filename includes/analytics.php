<?php
/**
 * NovaHire Phase 2 — Career Analytics & Profile Score Engine
 */
if (defined('NOVAHIRE_ANALYTICS')) return;
define('NOVAHIRE_ANALYTICS', true);

function nh_calculate_profile_score($con, $user_id) {
    $stmt = mysqli_prepare($con, "SELECT * FROM user_info WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$user) return ['overall' => 0, 'completeness' => 0, 'skills' => 0, 'experience' => 0, 'activity' => 0, 'recommendations' => []];

    $completeness = 0;
    $fields = [
        'username' => 5, 'email' => 5, 'phone' => 5, 'address' => 4,
        'date_of_birth' => 3, 'gender' => 2, 'user_degree' => 6,
        'user_skills' => 8, 'about_me' => 4, 'experience' => 2,
        'experience_years' => 1, 'profile' => 3,
    ];
    foreach ($fields as $field => $pts) {
        if (!empty($user[$field])) $completeness += $pts;
    }

    $skills_score = 0;
    if (!empty($user['user_skills'])) {
        $skills = array_filter(array_map('trim', explode(',', $user['user_skills'])));
        $skills_score = min(15, count($skills) * 3);
        $in_demand = ['javascript','python','react','node.js','php','mysql','java','css','html','typescript','aws','docker','git','figma','laravel'];
        foreach ($skills as $s) {
            if (in_array(strtolower(trim($s)), $in_demand)) $skills_score += 2;
        }
        $skills_score = min(25, $skills_score);
    }

    $experience_score = 0;
    if (!empty($user['experience_years'])) $experience_score = min(15, (int)$user['experience_years'] * 3);
    if (!empty($user['experience'])) $experience_score = min(20, $experience_score + 5);

    $activity_score = 0;
    $app_stmt = mysqli_prepare($con, "SELECT COUNT(*) as cnt FROM job_applications WHERE user_id = ?");
    mysqli_stmt_bind_param($app_stmt, "i", $user_id);
    mysqli_stmt_execute($app_stmt);
    $apps = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($app_stmt))['cnt'];
    mysqli_stmt_close($app_stmt);
    $activity_score += min(5, $apps);

    if (nh_table_exists($con, 'saved_jobs')) {
        $sv_stmt = mysqli_prepare($con, "SELECT COUNT(*) as cnt FROM saved_jobs WHERE user_id = ?");
        mysqli_stmt_bind_param($sv_stmt, "i", $user_id);
        mysqli_stmt_execute($sv_stmt);
        $activity_score += min(3, (int)mysqli_fetch_assoc(mysqli_stmt_get_result($sv_stmt))['cnt']);
        mysqli_stmt_close($sv_stmt);
    }

    if (nh_table_exists($con, 'user_quiz_status')) {
        $q_stmt = mysqli_prepare($con, "SELECT COUNT(*) as cnt FROM user_quiz_status WHERE user_id = ? AND status = 'passed'");
        mysqli_stmt_bind_param($q_stmt, "i", $user_id);
        mysqli_stmt_execute($q_stmt);
        $activity_score += min(4, (int)mysqli_fetch_assoc(mysqli_stmt_get_result($q_stmt))['cnt'] * 2);
        mysqli_stmt_close($q_stmt);
    }
    $activity_score = min(15, $activity_score);

    $overall = min(100, $completeness + $skills_score + $experience_score + $activity_score);

    $recs = [];
    if ($completeness < 35) {
        if (empty($user['about_me'])) $recs[] = ['text' => 'Add an About Me section', 'icon' => 'fa-user-pen', 'priority' => 'high'];
        if (empty($user['profile'])) $recs[] = ['text' => 'Upload a professional photo', 'icon' => 'fa-camera', 'priority' => 'high'];
        if (empty($user['address'])) $recs[] = ['text' => 'Add your location', 'icon' => 'fa-location-dot', 'priority' => 'medium'];
        if (empty($user['experience_years'])) $recs[] = ['text' => 'Add years of experience', 'icon' => 'fa-briefcase', 'priority' => 'medium'];
    }
    if ($skills_score < 15) $recs[] = ['text' => 'Add more in-demand skills', 'icon' => 'fa-star', 'priority' => 'high'];
    if ($experience_score < 10) $recs[] = ['text' => 'Detail your work experience', 'icon' => 'fa-building', 'priority' => 'medium'];
    if ($apps === 0) $recs[] = ['text' => 'Start applying to jobs', 'icon' => 'fa-paper-plane', 'priority' => 'high'];

    if (nh_table_exists($con, 'profile_scores')) {
        $recs_json = json_encode($recs);
        $check = mysqli_prepare($con, "SELECT id FROM profile_scores WHERE user_id = ?");
        mysqli_stmt_bind_param($check, "i", $user_id);
        mysqli_stmt_execute($check);
        $exists = mysqli_num_rows(mysqli_stmt_get_result($check)) > 0;
        mysqli_stmt_close($check);

        if ($exists) {
            $upd = mysqli_prepare($con, "UPDATE profile_scores SET overall_score=?, completeness_score=?, skills_score=?, experience_score=?, activity_score=?, recommendations=?, calculated_at=NOW() WHERE user_id=?");
            mysqli_stmt_bind_param($upd, "iiiiisi", $overall, $completeness, $skills_score, $experience_score, $activity_score, $recs_json, $user_id);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        } else {
            $ins = mysqli_prepare($con, "INSERT INTO profile_scores (user_id, overall_score, completeness_score, skills_score, experience_score, activity_score, recommendations, calculated_at) VALUES (?,?,?,?,?,?,?,NOW())");
            mysqli_stmt_bind_param($ins, "iiiiisi", $user_id, $overall, $completeness, $skills_score, $experience_score, $activity_score, $recs_json);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
        }
    }

    return ['overall' => $overall, 'completeness' => $completeness, 'skills' => $skills_score, 'experience' => $experience_score, 'activity' => $activity_score, 'recommendations' => $recs];
}

function nh_get_profile_score($con, $user_id, $force = false) {
    if ($force || !nh_table_exists($con, 'profile_scores')) {
        return nh_calculate_profile_score($con, $user_id);
    }
    $stmt = mysqli_prepare($con, "SELECT * FROM profile_scores WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || (time() - strtotime($row['calculated_at'])) > 3600) {
        return nh_calculate_profile_score($con, $user_id);
    }
    return ['overall' => (int)$row['overall_score'], 'completeness' => (int)$row['completeness_score'], 'skills' => (int)$row['skills_score'], 'experience' => (int)$row['experience_score'], 'activity' => (int)$row['activity_score'], 'recommendations' => json_decode($row['recommendations'], true) ?? []];
}

function nh_profile_score_label($score) {
    if ($score >= 90) return ['Excellent', '#059669', 'fa-trophy'];
    if ($score >= 75) return ['Strong', '#1a56db', 'fa-shield-halved'];
    if ($score >= 50) return ['Good', '#d97706', 'fa-chart-simple'];
    if ($score >= 25) return ['Needs Work', '#ea580c', 'fa-triangle-exclamation'];
    return ['Incomplete', '#dc2626', 'fa-circle-exclamation'];
}

/* ── Career Analytics ───────────────────────────────────────────────────── */

function nh_generate_career_analytics($con, $user_id) {
    $analytics = [];

    $app_stmt = mysqli_prepare($con, "SELECT COUNT(*) as total,
        COALESCE(SUM(application_status='pending'),0) as pending,
        COALESCE(SUM(application_status='reviewed'),0) as reviewed,
        COALESCE(SUM(application_status='shortlisted'),0) as shortlisted,
        COALESCE(SUM(application_status='rejected'),0) as rejected,
        COALESCE(AVG(quiz_score),0) as avg_quiz
        FROM job_applications WHERE user_id=?");
    mysqli_stmt_bind_param($app_stmt, "i", $user_id);
    mysqli_stmt_execute($app_stmt);
    $ad = mysqli_fetch_assoc(mysqli_stmt_get_result($app_stmt));
    mysqli_stmt_close($app_stmt);

    $total = (int)$ad['total'];
    $reviewed = (int)$ad['reviewed'] + (int)$ad['shortlisted'] + (int)$ad['rejected'];
    $analytics['applications'] = [
        'total' => $total, 'pending' => (int)$ad['pending'],
        'reviewed' => (int)$ad['reviewed'], 'shortlisted' => (int)$ad['shortlisted'],
        'rejected' => (int)$ad['rejected'], 'avg_quiz' => round((float)$ad['avg_quiz']),
        'response_rate' => $total > 0 ? round(($reviewed / $total) * 100) : 0,
        'interview_rate' => $total > 0 ? round(((int)$ad['shortlisted'] / $total) * 100) : 0,
    ];

    $time_stmt = mysqli_prepare($con, "SELECT DATE_FORMAT(applied_date,'%Y-%m') as month, COUNT(*) as count
        FROM job_applications WHERE user_id=? AND applied_date >= DATE_SUB(NOW(),INTERVAL 6 MONTH)
        GROUP BY month ORDER BY month");
    mysqli_stmt_bind_param($time_stmt, "i", $user_id);
    mysqli_stmt_execute($time_stmt);
    $analytics['monthly_apps'] = [];
    while ($row = mysqli_fetch_assoc(mysqli_stmt_get_result($time_stmt))) $analytics['monthly_apps'][] = $row;
    mysqli_stmt_close($time_stmt);

    $cat_stmt = mysqli_prepare($con, "SELECT cj.job_category, COUNT(*) as count
        FROM job_applications ja JOIN company_jobs cj ON ja.job_id=cj.id
        WHERE ja.user_id=? GROUP BY cj.job_category ORDER BY count DESC");
    mysqli_stmt_bind_param($cat_stmt, "i", $user_id);
    mysqli_stmt_execute($cat_stmt);
    $analytics['categories'] = [];
    while ($row = mysqli_fetch_assoc(mysqli_stmt_get_result($cat_stmt))) $analytics['categories'][] = $row;
    mysqli_stmt_close($cat_stmt);

    $skill_stmt = mysqli_prepare($con, "SELECT cj.skills_required
        FROM job_applications ja JOIN company_jobs cj ON ja.job_id=cj.id WHERE ja.user_id=?");
    mysqli_stmt_bind_param($skill_stmt, "i", $user_id);
    mysqli_stmt_execute($skill_stmt);
    $skill_freq = [];
    while ($row = mysqli_fetch_assoc(mysqli_stmt_get_result($skill_stmt))) {
        foreach (array_map('trim', explode(',', $row['skills_required'])) as $s) {
            $s = strtolower(trim($s));
            if ($s !== '') $skill_freq[$s] = ($skill_freq[$s] ?? 0) + 1;
        }
    }
    mysqli_stmt_close($skill_stmt);
    arsort($skill_freq);
    $analytics['top_skills'] = array_slice($skill_freq, 0, 8, true);

    $int_check = mysqli_query($con, "SHOW TABLES LIKE 'interviews'");
    if ($int_check && mysqli_num_rows($int_check) > 0) {
        $int_stmt = mysqli_prepare($con, "SELECT COUNT(*) as total,
            COALESCE(SUM(status='scheduled'),0) as upcoming,
            COALESCE(SUM(status='completed'),0) as completed
            FROM interviews WHERE user_id=?");
        mysqli_stmt_bind_param($int_stmt, "i", $user_id);
        mysqli_stmt_execute($int_stmt);
        $id = mysqli_fetch_assoc(mysqli_stmt_get_result($int_stmt));
        mysqli_stmt_close($int_stmt);
        $analytics['interviews'] = ['total' => (int)$id['total'], 'upcoming' => (int)$id['upcoming'], 'completed' => (int)$id['completed']];
    } else {
        $analytics['interviews'] = ['total' => 0, 'upcoming' => 0, 'completed' => 0];
    }

    $analytics['profile_views_30d'] = 0;
    if (nh_table_exists($con, 'profile_views')) {
        $pv_stmt = mysqli_prepare($con, "SELECT COUNT(*) as cnt FROM profile_views WHERE user_id=? AND viewed_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)");
        mysqli_stmt_bind_param($pv_stmt, "i", $user_id);
        mysqli_stmt_execute($pv_stmt);
        $analytics['profile_views_30d'] = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($pv_stmt))['cnt'];
        mysqli_stmt_close($pv_stmt);
    }

    return $analytics;
}

function nh_log_activity($con, $user_id, $type, $data = null) {
    if (!nh_table_exists($con, 'user_activity_log')) return false;
    $stmt = mysqli_prepare($con, "INSERT INTO user_activity_log (user_id, activity_type, activity_data) VALUES (?,?,?)");
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $type, $data);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

/* ── Job Match Notifications ────────────────────────────────────────────── */

function nh_check_job_matches($con, $user_id = null) {
    if (!nh_table_exists($con, 'job_match_prefs') || !nh_table_exists($con, 'job_match_notifications')) return false;

    $sql = "SELECT u.id, u.user_skills, u.username, u.email
            FROM user_info u WHERE u.user_skills IS NOT NULL AND u.user_skills != ''";
    $params = [];
    $types = '';
    if ($user_id) { $sql .= " AND u.id=?"; $params[] = $user_id; $types = 'i'; }

    $stmt = mysqli_prepare($con, $sql);
    if ($types) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $users = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($users as $u) {
        $prefs_stmt = mysqli_prepare($con, "SELECT * FROM job_match_prefs WHERE user_id=?");
        mysqli_stmt_bind_param($prefs_stmt, "i", $u['id']);
        mysqli_stmt_execute($prefs_stmt);
        $prefs = mysqli_fetch_assoc(mysqli_stmt_get_result($prefs_stmt));
        mysqli_stmt_close($prefs_stmt);

        $min_score = $prefs ? (int)$prefs['min_match_score'] : 60;
        $user_skills = array_filter(array_map('trim', explode(',', $u['user_skills'])));
        if (empty($user_skills)) continue;

        $job_stmt = mysqli_prepare($con, "SELECT cj.*, c.company_name, c.logo
            FROM company_jobs cj JOIN companies c ON cj.company_id=c.id
            WHERE cj.status='active' AND cj.deadline>=CURDATE()
            AND cj.posted_date >= DATE_SUB(NOW(),INTERVAL 3 DAY)
            ORDER BY cj.posted_date DESC");
        mysqli_stmt_execute($job_stmt);
        $jobs = mysqli_fetch_all(mysqli_stmt_get_result($job_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($job_stmt);

        foreach ($jobs as $job) {
            $job_text = strtolower($job['job_category'].' '.$job['skills_required'].' '.$job['job_title']);
            $match = 0; $matched = [];
            foreach ($user_skills as $s) {
                if (preg_match('/\b'.preg_quote(strtolower($s),'/').'\b/i', $job_text)) { $match++; $matched[] = $s; }
            }
            $score = count($user_skills) > 0 ? round(($match / count($user_skills)) * 100) : 0;
            if ($score < $min_score) continue;

            $chk = mysqli_prepare($con, "SELECT id FROM job_match_notifications WHERE user_id=? AND job_id=?");
            mysqli_stmt_bind_param($chk, "ii", $u['id'], $job['id']);
            mysqli_stmt_execute($chk);
            if (mysqli_num_rows(mysqli_stmt_get_result($chk)) > 0) { mysqli_stmt_close($chk); continue; }
            mysqli_stmt_close($chk);

            $mj = json_encode($matched);
            $ins = mysqli_prepare($con, "INSERT INTO job_match_notifications (user_id, job_id, match_score, matched_skills, sent_via) VALUES (?,?,?,?,'in_app')");
            mysqli_stmt_bind_param($ins, "iisi", $u['id'], $job['id'], $score, $mj);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);

            create_notification($con, 'seeker', $u['id'], 'system', 0,
                'New Job Match!',
                "A new {$job['job_title']} at {$job['company_name']} matches your skills ({$score}% match).",
                'job_match', 'job', $job['id']);
        }

        if ($prefs) {
            $upd = mysqli_prepare($con, "UPDATE job_match_prefs SET last_sent_at=NOW() WHERE user_id=?");
            mysqli_stmt_bind_param($upd, "i", $u['id']);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
    }
    return true;
}
