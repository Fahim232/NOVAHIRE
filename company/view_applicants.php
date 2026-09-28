<?php
    require_once __DIR__ . '/../includes/bootstrap.php';
    global $con;

    // Check if company is logged in
    if (!isset($_SESSION['company_id'])) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }

    $company_id = (int)$_SESSION['company_id'];
    $company_name = $_SESSION['company_name'] ?? 'Company';

    // Filter by job_id if provided
    // Filter by job_id if provided
    $job_filter = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
    $status_filter_raw = $_GET['status'] ?? 'all';
    $app_status_filter_raw = $_GET['app_status'] ?? 'all';
    $ai_score_filter_raw = $_GET['ai_score'] ?? 'all';
    $sort_filter_raw = $_GET['sort'] ?? 'default';
    $verified_skill_filter = isset($_GET['verified_skill_id']) ? (int)$_GET['verified_skill_id'] : 0;
    $verified_level_filter = $_GET['verified_level'] ?? 'Any';

    // Whitelist allowed status values
    $allowed_statuses = ['all', 'passed', 'failed', 'not_taken'];
    $status_filter = in_array($status_filter_raw, $allowed_statuses) ? $status_filter_raw : 'all';

    $allowed_app_statuses = ['all', 'pending', 'reviewed', 'shortlisted', 'rejected'];
    $app_status_filter = in_array($app_status_filter_raw, $allowed_app_statuses) ? $app_status_filter_raw : 'all';

    $allowed_ai_scores = ['all', '80plus', '60to79', 'below60', 'not_analyzed'];
    $ai_score_filter = in_array($ai_score_filter_raw, $allowed_ai_scores) ? $ai_score_filter_raw : 'all';

    $sort_filter = ($sort_filter_raw === 'ai_match') ? 'ai_match' : 'default';

    // Build parameterized query
    $sql = "SELECT ja.*, cj.job_title, cj.job_category, ui.username, ui.email, ui.phone, ui.user_degree, ui.user_skills, ui.profile,
                   aca.overall_match_score, aca.skills_match_score, aca.experience_match_score, aca.education_match_score, aca.project_relevance_score,
                   aca.required_matches, aca.required_missing, aca.required_unclear,
                   aca.preferred_matches, aca.preferred_missing, aca.preferred_unclear,
                   aca.status AS ai_status, aca.cv_hash AS ai_cv_hash, aca.job_requirements_hash AS ai_job_req_hash, aca.analyzed_at AS ai_analyzed_at
            FROM job_applications ja
            JOIN company_jobs cj ON ja.job_id = cj.id
            JOIN user_info ui ON ja.user_id = ui.id
            LEFT JOIN ai_cv_analyses aca ON ja.id = aca.application_id";
                          
    if ($verified_skill_filter > 0) {
        $sql .= " JOIN verified_skills vs ON ui.id = vs.user_id AND vs.skill_id = ?";
    }
    
    $sql .= " WHERE cj.company_id = ?";
    $types = "";
    $params = [];
    
    if ($verified_skill_filter > 0) {
        $types .= "i";
        $params[] = $verified_skill_filter;
    }
    
    $types .= "i";
    $params[] = $company_id;

    if ($job_filter > 0) {
        $sql .= " AND ja.job_id = ?";
        $types .= "i";
        $params[] = $job_filter;
    }

    if ($status_filter !== 'all') {
        $sql .= " AND ja.quiz_status = ?";
        $types .= "s";
        $params[] = $status_filter;
    }

    if ($app_status_filter !== 'all') {
        $sql .= " AND ja.application_status = ?";
        $types .= "s";
        $params[] = $app_status_filter;
    }

    if ($ai_score_filter === '80plus') {
        $sql .= " AND aca.overall_match_score >= 80 AND aca.status = 'COMPLETED'";
    } elseif ($ai_score_filter === '60to79') {
        $sql .= " AND aca.overall_match_score >= 60 AND aca.overall_match_score < 80 AND aca.status = 'COMPLETED'";
    } elseif ($ai_score_filter === 'below60') {
        $sql .= " AND aca.overall_match_score < 60 AND aca.status = 'COMPLETED'";
    } elseif ($ai_score_filter === 'not_analyzed') {
        $sql .= " AND (aca.overall_match_score IS NULL OR aca.status != 'COMPLETED')";
    }
    
    if ($verified_skill_filter > 0 && $verified_level_filter !== 'Any') {
        $sql .= " AND FIELD(vs.skill_level, 'Beginner', 'Intermediate', 'Advanced', 'Expert') >= FIELD(?, 'Beginner', 'Intermediate', 'Advanced', 'Expert')";
        $types .= "s";
        $params[] = $verified_level_filter;
    }

    if ($sort_filter === 'ai_match') {
        $sql .= " ORDER BY (CASE WHEN aca.status = 'COMPLETED' THEN aca.overall_match_score ELSE -1 END) DESC, ja.applied_date DESC";
    } else {
        $sql .= " ORDER BY (SELECT COUNT(*) FROM verified_skills vs2 JOIN job_required_skills jrs ON vs2.skill_id = jrs.skill_id WHERE vs2.user_id = ja.user_id AND jrs.job_id = cj.id) DESC, ja.applied_date DESC";
    }
    $applications_result = false;
    $stmt = mysqli_prepare($con, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $applications_result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    }

    // Fetch jobs for filter dropdown
    $jobs_result = false;
    $jobs_stmt = mysqli_prepare($con, "SELECT id, job_title FROM company_jobs WHERE company_id = ? ORDER BY job_title");
    if ($jobs_stmt) {
        mysqli_stmt_bind_param($jobs_stmt, "i", $company_id);
        mysqli_stmt_execute($jobs_stmt);
        $jobs_result = mysqli_stmt_get_result($jobs_stmt);
        mysqli_stmt_close($jobs_stmt);
    }

    // Fetch skills for filter dropdown
    $skills_result = false;
    $skills_stmt = mysqli_prepare($con, "SELECT id, name FROM skills ORDER BY name");
    if ($skills_stmt) {
        mysqli_stmt_execute($skills_stmt);
        $skills_result = mysqli_stmt_get_result($skills_stmt);
        mysqli_stmt_close($skills_stmt);
    }

    // Count statistics
    $stats_sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN ja.quiz_status = 'passed' THEN 1 ELSE 0 END) as passed,
        SUM(CASE WHEN ja.quiz_status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN ja.quiz_status = 'not_taken' THEN 1 ELSE 0 END) as not_taken
        FROM job_applications ja
        JOIN company_jobs cj ON ja.job_id = cj.id
        WHERE cj.company_id = ?";
    $stats = ['total' => 0, 'passed' => 0, 'failed' => 0, 'not_taken' => 0];
    $stats_stmt = mysqli_prepare($con, $stats_sql);
    if ($stats_stmt) {
        mysqli_stmt_bind_param($stats_stmt, "i", $company_id);
        mysqli_stmt_execute($stats_stmt);
        $stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stats_stmt)) ?: $stats;
        mysqli_stmt_close($stats_stmt);
    }

    $avatar_gradients = [
        ['#3b82f6', '#06b6d4'],
        ['#0ea5e9', '#06b6d4'],
        ['#059669', '#34d399'],
        ['#d97706', '#f97316'],
        ['#ec4899', '#f43f5e'],
        ['#14b8a6', '#0d9488'],
    ];

    function applicant_avatar($username, $gradients) {
        $initial = strtoupper(substr(trim($username), 0, 1) ?: '?');
        $g = $gradients[abs(crc32($username)) % count($gradients)];
        return '<div class="va-avatar" style="background: linear-gradient(135deg, ' . $g[0] . ', ' . $g[1] . ');">' . $initial . '</div>';
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Job Applicants | Company Dashboard</title>
    <?php include '../includes/links.php'; ?>
    <style>
        :root {
            --va-bg: #f4f6fb;
            --va-card: #ffffff;
            --va-border: #e5e9f2;
            --va-text: #1e293b;
            --va-muted: #64748b;
            --va-primary: #1a56db;
            --va-primary-2: #0ea5e9;
            --va-soft: #eef2ff;
            --va-input: #f8fafc;
            --va-shadow: 0 10px 30px rgba(15, 23, 42, 0.07);
        }
        [data-theme="dark"] {
            --va-bg: #0f172a;
            --va-card: #111827;
            --va-border: #28334a;
            --va-text: #e8edff;
            --va-muted: #94a3b8;
            --va-primary: #06b6d4;
            --va-primary-2: #38bdf8;
            --va-soft: #1e293b;
            --va-input: #0d1526;
            --va-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
        }

        body {
            background:
                radial-gradient(circle at 8% 12%, rgba(99, 102, 241, 0.10), transparent 28%),
                radial-gradient(circle at 92% 8%, rgba(217, 70, 239, 0.08), transparent 26%),
                var(--va-bg);
            color: var(--va-text);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .va-wrap { max-width: 1200px; margin: 0 auto; padding: 34px 24px 60px; }

        /* ── Hero ── */
        .va-hero {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #1a56db 0%, #0ea5e9 55%, #38bdf8 100%);
            border-radius: 22px;
            padding: 30px 34px;
            color: #fff;
            box-shadow: 0 20px 40px rgba(79, 70, 229, 0.28);
        }
        .va-hero::before {
            content: '';
            position: absolute;
            right: -80px; top: -80px;
            width: 260px; height: 260px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.10);
        }
        .va-hero::after {
            content: '';
            position: absolute;
            right: 60px; bottom: -110px;
            width: 220px; height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
        }
        .va-hero h1 { font-weight: 800; font-size: 1.75rem; color: #fff; margin: 0 0 6px; }
        .va-hero p { color: rgba(255, 255, 255, 0.85); margin: 0; font-size: 0.95rem; }

        /* ── Stats ── */
        .va-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 22px; }
        .va-stat {
            background: var(--va-card);
            border: 1px solid var(--va-border);
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--va-shadow);
            transition: transform .2s ease, box-shadow .2s ease;
            cursor: pointer;
            text-decoration: none;
        }
        .va-stat:hover { transform: translateY(-4px); box-shadow: 0 18px 38px rgba(79, 70, 229, 0.14); text-decoration: none; }
        .va-stat-ico {
            width: 46px; height: 46px;
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .va-stat b { display: block; font-size: 1.45rem; line-height: 1.1; color: var(--va-text); }
        .va-stat span { font-size: 0.76rem; color: var(--va-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
        .va-stat.on {
            border-color: var(--va-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15), var(--va-shadow);
        }

        /* ── Toolbar ── */
        .va-toolbar {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 14px;
            margin: 30px 0 20px;
        }
        .va-filters { display: flex; gap: 8px; flex-wrap: wrap; }
        .va-fbtn {
            border: 1.5px solid var(--va-border);
            background: var(--va-card);
            color: var(--va-muted);
            font-weight: 600; font-size: 0.83rem;
            padding: 9px 16px;
            border-radius: 12px;
            cursor: pointer;
            transition: all .2s ease;
            text-decoration: none;
        }
        .va-fbtn:hover { border-color: var(--va-primary); color: var(--va-primary); text-decoration: none; }
        .va-fbtn.active {
            background: linear-gradient(135deg, var(--va-primary), var(--va-primary-2));
            color: #fff; border-color: transparent;
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.3);
        }
        .va-fbtn .cnt {
            display: inline-block; margin-left: 6px;
            background: rgba(0, 0, 0, 0.08);
            border-radius: 20px; padding: 1px 8px; font-size: 0.72rem;
        }
        .va-fbtn.active .cnt { background: rgba(255, 255, 255, 0.22); }

        .va-search { position: relative; flex: 1; min-width: 240px; max-width: 380px; }
        .va-search i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--va-muted); font-size: 0.9rem; }
        .va-search input {
            width: 100%;
            background: var(--va-card);
            border: 1.5px solid var(--va-border);
            color: var(--va-text);
            border-radius: 13px;
            padding: 12px 16px 12px 42px;
            font-size: 0.92rem;
            outline: none;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .va-search input:focus { border-color: var(--va-primary); box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15); }
        .va-search input::placeholder { color: var(--va-muted); }

        .va-jobsel {
            background: var(--va-card);
            border: 1.5px solid var(--va-border);
            color: var(--va-text);
            border-radius: 13px;
            padding: 12px 16px;
            font-size: 0.9rem;
            outline: none;
            min-width: 220px;
        }
        .va-jobsel:focus { border-color: var(--va-primary); }

        /* ── Applicant cards ── */
        .va-list { display: flex; flex-direction: column; gap: 16px; }
        .va-card {
            background: var(--va-card);
            border: 1px solid var(--va-border);
            border-radius: 18px;
            padding: 22px 24px;
            display: flex;
            gap: 20px;
            box-shadow: var(--va-shadow);
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
            animation: vaIn .4s ease both;
        }
        .va-card:hover { transform: translateY(-3px); border-color: var(--va-primary); box-shadow: 0 20px 40px rgba(79, 70, 229, 0.16); }
        @keyframes vaIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .va-avatar {
            width: 56px; height: 56px;
            border-radius: 18px;
            color: #fff;
            font-weight: 800;
            font-size: 1.3rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.15);
        }

        .va-main { flex: 1; min-width: 0; }
        .va-name-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .va-name-row h3 { font-size: 1.08rem; font-weight: 700; margin: 0; color: var(--va-text); }
        .va-badge {
            font-size: 0.7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .5px;
            padding: 4px 11px; border-radius: 20px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .va-badge i { font-size: 0.5rem; }
        .va-badge.passed { background: rgba(16, 185, 129, 0.14); color: #059669; }
        .va-badge.failed { background: rgba(239, 68, 68, 0.14); color: #dc2626; }
        .va-badge.not_taken { background: rgba(245, 158, 11, 0.14); color: #d97706; }
        .va-badge.shortlisted { background: rgba(59, 130, 246, 0.14); color: #3b82f6; }
        .va-badge.pending { background: rgba(148, 163, 184, 0.18); color: var(--va-muted); }

        .va-meta { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 8px; }
        .va-meta span { font-size: 0.82rem; color: var(--va-muted); }
        .va-meta i { margin-right: 5px; color: var(--va-primary); opacity: .8; }
        .va-meta b { color: var(--va-text); font-weight: 600; }

        .va-cover {
            margin-top: 12px;
            padding: 12px 16px;
            background: var(--va-soft);
            border: 1px solid var(--va-border);
            border-radius: 12px;
            font-size: 0.84rem;
            color: var(--va-text);
            line-height: 1.55;
            display: flex; gap: 10px; align-items: flex-start;
        }
        .va-cover i { color: var(--va-primary); margin-top: 3px; }

        .va-education { margin-top: 12px; font-size: 0.84rem; color: var(--va-muted); }
        .va-education i { margin-right: 7px; color: var(--va-primary); }

        .va-skills { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 12px; }
        .va-skill {
            background: var(--va-soft);
            border: 1px solid var(--va-border);
            color: var(--va-text);
            font-size: 0.76rem; font-weight: 600;
            padding: 5px 12px; border-radius: 20px;
        }

        .va-actions {
            display: flex; flex-direction: column; gap: 9px;
            flex-shrink: 0;
            justify-content: center;
            min-width: 150px;
        }
        .va-act {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 16px; border-radius: 11px;
            font-size: 0.82rem; font-weight: 600;
            border: 1.5px solid var(--va-border);
            background: var(--va-card);
            color: var(--va-text);
            text-decoration: none;
            transition: all .18s ease;
            white-space: nowrap;
        }
        .va-act:hover { transform: translateY(-2px); text-decoration: none; }
        .va-act-detail { background: rgba(79, 70, 229, 0.10); border-color: rgba(79, 70, 229, 0.35); color: var(--va-primary); }
        .va-act-detail:hover { background: var(--va-primary); color: #fff; }
        .va-act-cv { background: rgba(16, 185, 129, 0.10); border-color: rgba(16, 185, 129, 0.35); color: #059669; }
        .va-act-cv:hover { background: #059669; color: #fff; }
        .va-act-contact { background: rgba(59, 130, 246, 0.10); border-color: rgba(59, 130, 246, 0.35); color: #3b82f6; }
        .va-act-contact:hover { background: #3b82f6; color: #fff; }
        .va-act.disabled { opacity: .5; cursor: not-allowed; }
        .va-act.disabled:hover { transform: none; background: var(--va-card); color: var(--va-muted); }

        /* Score ring */
        .va-score {
            text-align: center; flex-shrink: 0;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding-left: 20px;
            border-left: 1px solid var(--va-border);
            min-width: 86px;
        }
        .va-score-ring {
            width: 58px; height: 58px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 0.95rem;
            position: relative;
        }
        .va-score-ring small { font-size: 0.62rem; font-weight: 600; opacity: .8; }
        .va-score-cap { font-size: 0.66rem; color: var(--va-muted); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: .4px; }

        /* AI CV Screening Card Element */
        .va-ai-box {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(168, 85, 247, 0.03));
            border: 1.5px solid rgba(99, 102, 241, 0.22);
            border-radius: 14px;
            padding: 14px 16px;
            min-width: 260px;
            max-width: 300px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .va-ai-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            border-bottom: 1px solid rgba(99, 102, 241, 0.15);
            padding-bottom: 8px;
        }
        .va-ai-title {
            font-size: 0.76rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6366f1;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .va-ai-score-pill {
            font-size: 1.25rem;
            font-weight: 900;
            line-height: 1;
            display: flex;
            align-items: baseline;
            gap: 2px;
        }
        .va-ai-score-pill small {
            font-size: 0.7rem;
            font-weight: 700;
        }
        .va-ai-subscores {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 8px;
        }
        .va-ai-sub-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--va-text);
        }
        .va-ai-sub-row span {
            color: var(--va-muted);
        }
        .va-ai-sub-row b {
            font-weight: 700;
        }
        .va-ai-req-counts {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            background: var(--va-card);
            border: 1px solid var(--va-border);
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 0.72rem;
            margin-bottom: 10px;
        }
        .va-ai-req-counts div {
            display: flex;
            flex-direction: column;
        }
        .va-ai-req-counts span {
            color: var(--va-muted);
            font-size: 0.65rem;
            text-transform: uppercase;
            font-weight: 700;
        }
        .va-ai-req-counts b {
            color: var(--va-text);
            font-weight: 800;
        }
        .va-ai-empty {
            text-align: center;
            padding: 12px 6px;
            color: var(--va-muted);
            font-size: 0.8rem;
        }
        .va-ai-empty i {
            font-size: 1.6rem;
            color: #6366f1;
            opacity: 0.6;
            margin-bottom: 6px;
            display: block;
        }

        /* Quick Candidate Decision Buttons */
        .va-quick-decisions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px dashed var(--va-border);
            align-items: center;
        }
        .va-q-btn {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .va-q-review { background: #3b82f6; color: #fff; }
        .va-q-review:hover { background: #2563eb; }
        .va-q-shortlist { background: #10b981; color: #fff; }
        .va-q-shortlist:hover { background: #059669; }
        .va-q-reject { background: #ef4444; color: #fff; }
        .va-q-reject:hover { background: #dc2626; }
        .va-q-interview {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }
        .va-q-interview:hover { color: #fff; text-decoration: none; transform: translateY(-1px); }

        .status-badge-pending { background: rgba(245, 158, 11, 0.15); color: #d97706; }
        .status-badge-reviewed { background: rgba(59, 130, 246, 0.15); color: #2563eb; }
        .status-badge-shortlisted { background: rgba(16, 185, 129, 0.15); color: #059669; }
        .status-badge-rejected { background: rgba(239, 68, 68, 0.15); color: #dc2626; }

        /* Empty */
        .va-empty {
            text-align: center;
            padding: 70px 24px;
            background: var(--va-card);
            border: 1.5px dashed var(--va-border);
            border-radius: 18px;
        }
        .va-empty i { font-size: 3.4rem; color: var(--va-primary); opacity: .35; }
        .va-empty h3 { font-weight: 700; color: var(--va-text); margin-top: 16px; }
        .va-empty p { color: var(--va-muted); }

        @media (max-width: 992px) {
            .va-stats { grid-template-columns: repeat(2, 1fr); }
            .va-card { flex-wrap: wrap; }
            .va-score { border-left: none; padding-left: 0; border-top: 1px solid var(--va-border); padding-top: 14px; width: 100%; flex-direction: row; gap: 12px; }
            .va-actions { width: 100%; flex-direction: row; flex-wrap: wrap; }
            .va-actions .va-act { flex: 1; }
        }
        @media (max-width: 576px) {
            .va-stats { grid-template-columns: repeat(2, 1fr); }
            .va-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/company_header.php'; ?>

    <div class="va-wrap">
        <!-- Hero -->
        <div class="va-hero">
            <h1><i class="fas fa-users mr-2"></i>Job Applicants</h1>
            <p>Review candidates, scores, and application details for your job openings.</p>
        </div>

        <!-- Stats (clickable filters) -->
        <div class="va-stats">
            <a class="va-stat <?php echo $status_filter == 'all' ? 'on' : ''; ?>" href="?status=all<?php echo $job_filter ? '&job_id=' . $job_filter : ''; ?>">
                <div class="va-stat-ico" style="background: rgba(59,130,246,.12); color:#3b82f6;"><i class="fas fa-file-signature"></i></div>
                <div><b><?php echo $stats['total']; ?></b><span>Applications</span></div>
            </a>
            <a class="va-stat <?php echo $status_filter == 'passed' ? 'on' : ''; ?>" href="?status=passed<?php echo $job_filter ? '&job_id=' . $job_filter : ''; ?>">
                <div class="va-stat-ico" style="background: rgba(5,150,105,.12); color:#059669;"><i class="fas fa-circle-check"></i></div>
                <div><b><?php echo $stats['passed']; ?></b><span>Passed Quiz</span></div>
            </a>
            <a class="va-stat <?php echo $status_filter == 'failed' ? 'on' : ''; ?>" href="?status=failed<?php echo $job_filter ? '&job_id=' . $job_filter : ''; ?>">
                <div class="va-stat-ico" style="background: rgba(239,68,68,.12); color:#dc2626;"><i class="fas fa-circle-xmark"></i></div>
                <div><b><?php echo $stats['failed']; ?></b><span>Failed Quiz</span></div>
            </a>
            <a class="va-stat <?php echo $status_filter == 'not_taken' ? 'on' : ''; ?>" href="?status=not_taken<?php echo $job_filter ? '&job_id=' . $job_filter : ''; ?>">
                <div class="va-stat-ico" style="background: rgba(217,119,6,.12); color:#d97706;"><i class="fas fa-hourglass-half"></i></div>
                <div><b><?php echo $stats['not_taken']; ?></b><span>Not Taken</span></div>
            </a>
        </div>

        <!-- Toolbar -->
        <div class="va-toolbar">
            <div class="va-search">
                <i class="fas fa-magnifying-glass"></i>
                <input type="text" id="vaSearch" placeholder="Search applicants..." oninput="filterApplicants()">
            </div>
            <div class="va-filters">
                <a class="va-fbtn <?php echo $status_filter == 'all' ? 'active' : ''; ?>" href="?status=all<?php echo $job_filter ? '&job_id=' . $job_filter : ''; ?>">All</a>
                <a class="va-fbtn <?php echo $status_filter == 'passed' ? 'active' : ''; ?>" href="?status=passed<?php echo $job_filter ? '&job_id=' . $job_filter : ''; ?>">Passed</a>
                <a class="va-fbtn <?php echo $status_filter == 'failed' ? 'active' : ''; ?>" href="?status=failed<?php echo $job_filter ? '&job_id=' . $job_filter : ''; ?>">Failed</a>
                <a class="va-fbtn <?php echo $status_filter == 'not_taken' ? 'active' : ''; ?>" href="?status=not_taken<?php echo $job_filter ? '&job_id=' . $job_filter : ''; ?>">Not Taken</a>
            </div>
            <div class="va-filters-dropdowns" style="display:flex; gap:10px; margin-top:10px; flex-wrap:wrap;">
                <select class="va-jobsel" id="filterJob" onchange="applyFilters()">
                    <option value="0">All Jobs</option>
                    <?php
                    mysqli_data_seek($jobs_result, 0);
                    while ($job = mysqli_fetch_assoc($jobs_result)): ?>
                        <option value="<?php echo $job['id']; ?>" <?php echo ($job_filter == $job['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($job['job_title']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <select class="va-jobsel" id="filterAppStatus" onchange="applyFilters()">
                    <option value="all" <?php echo $app_status_filter == 'all' ? 'selected' : ''; ?>>All Screening Statuses</option>
                    <option value="pending" <?php echo $app_status_filter == 'pending' ? 'selected' : ''; ?>>New / Pending</option>
                    <option value="reviewed" <?php echo $app_status_filter == 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                    <option value="shortlisted" <?php echo $app_status_filter == 'shortlisted' ? 'selected' : ''; ?>>Shortlisted</option>
                    <option value="rejected" <?php echo $app_status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>

                <select class="va-jobsel" id="filterAiScore" onchange="applyFilters()">
                    <option value="all" <?php echo $ai_score_filter == 'all' ? 'selected' : ''; ?>>All AI Match Scores</option>
                    <option value="80plus" <?php echo $ai_score_filter == '80plus' ? 'selected' : ''; ?>>AI Match: 80%+</option>
                    <option value="60to79" <?php echo $ai_score_filter == '60to79' ? 'selected' : ''; ?>>AI Match: 60% – 79%</option>
                    <option value="below60" <?php echo $ai_score_filter == 'below60' ? 'selected' : ''; ?>>AI Match: &lt; 60%</option>
                    <option value="not_analyzed" <?php echo $ai_score_filter == 'not_analyzed' ? 'selected' : ''; ?>>Not Analyzed</option>
                </select>

                <select class="va-jobsel" id="filterSort" onchange="applyFilters()">
                    <option value="default" <?php echo $sort_filter == 'default' ? 'selected' : ''; ?>>Sort by: Skill Fit &amp; Date</option>
                    <option value="ai_match" <?php echo $sort_filter == 'ai_match' ? 'selected' : ''; ?>>Sort by: AI CV Match</option>
                </select>

                <select class="va-jobsel" id="filterSkill" onchange="applyFilters()">
                    <option value="0">Any Verified Skill</option>
                    <?php
                    if ($skills_result) {
                        mysqli_data_seek($skills_result, 0);
                        while ($skill = mysqli_fetch_assoc($skills_result)): ?>
                            <option value="<?php echo $skill['id']; ?>" <?php echo ($verified_skill_filter == $skill['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($skill['name']); ?>
                            </option>
                        <?php endwhile;
                    }
                    ?>
                </select>

                <select class="va-jobsel" id="filterLevel" onchange="applyFilters()">
                    <option value="Any" <?php echo $verified_level_filter == 'Any' ? 'selected' : ''; ?>>Any Level</option>
                    <option value="Beginner" <?php echo $verified_level_filter == 'Beginner' ? 'selected' : ''; ?>>Beginner +</option>
                    <option value="Intermediate" <?php echo $verified_level_filter == 'Intermediate' ? 'selected' : ''; ?>>Intermediate +</option>
                    <option value="Advanced" <?php echo $verified_level_filter == 'Advanced' ? 'selected' : ''; ?>>Advanced +</option>
                    <option value="Expert" <?php echo $verified_level_filter == 'Expert' ? 'selected' : ''; ?>>Expert</option>
                </select>
            </div>
        </div>

        <!-- Applicant list -->
        <?php if (mysqli_num_rows($applications_result) > 0): ?>
            <div class="va-list" id="vaList">
                <?php while ($app = mysqli_fetch_assoc($applications_result)):
                    $status = $app['quiz_status'] ?: 'not_taken';
                    $app_status = $app['application_status'] ?: 'pending';
                    $score = intval($app['quiz_score']);
                    $score_color = $score >= 60 ? '#059669' : ($score >= 30 ? '#d97706' : '#dc2626');
                    $data_search = strtolower(htmlspecialchars($app['username'] . ' ' . $app['email'] . ' ' . $app['job_title'] . ' ' . $app['user_degree']));
                    
                    $ai_status = $app['ai_status'] ?? 'NOT_ANALYZED';
                    $ai_score = ($app['overall_match_score'] !== null) ? intval($app['overall_match_score']) : null;
                    $ai_score_color = ($ai_score !== null) ? ($ai_score >= 80 ? '#10b981' : ($ai_score >= 60 ? '#f59e0b' : '#ef4444')) : '#94a3b8';
                    
                    $r_matches = !empty($app['required_matches']) ? json_decode($app['required_matches'], true) : [];
                    $r_missing = !empty($app['required_missing']) ? json_decode($app['required_missing'], true) : [];
                    $r_unclear = !empty($app['required_unclear']) ? json_decode($app['required_unclear'], true) : [];
                    $r_matched_count = count($r_matches ?: []);
                    $r_total_count = $r_matched_count + count($r_missing ?: []) + count($r_unclear ?: []);

                    $p_matches = !empty($app['preferred_matches']) ? json_decode($app['preferred_matches'], true) : [];
                    $p_missing = !empty($app['preferred_missing']) ? json_decode($app['preferred_missing'], true) : [];
                    $p_unclear = !empty($app['preferred_unclear']) ? json_decode($app['preferred_unclear'], true) : [];
                    $p_matched_count = count($p_matches ?: []);
                    $p_total_count = $p_matched_count + count($p_missing ?: []) + count($p_unclear ?: []);

                    $status_labels = [
                        'pending' => 'NEW',
                        'reviewed' => 'REVIEWED',
                        'shortlisted' => 'SHORTLISTED',
                        'rejected' => 'REJECTED'
                    ];
                    $status_label = $status_labels[$app_status] ?? strtoupper($app_status);
                ?>
                    <div class="va-card" id="card_app_<?php echo $app['id']; ?>" data-status="<?php echo $status; ?>" data-appstatus="<?php echo $app_status; ?>" data-search="<?php echo $data_search; ?>">
                        <?php echo applicant_avatar($app['username'], $avatar_gradients); ?>

                        <div class="va-main">
                            <div class="va-name-row">
                                <h3><?php echo htmlspecialchars(trim($app['username'])); ?></h3>
                                <span class="va-badge <?php echo $status; ?>">
                                    <i class="fas fa-circle"></i><?php echo $status == 'passed' ? 'Quiz Passed' : ($status == 'failed' ? 'Quiz Failed' : 'Not Taken'); ?>
                                </span>
                                <span class="va-badge status-badge-<?php echo $app_status; ?>" id="badge_app_<?php echo $app['id']; ?>">
                                    <i class="fas fa-tag mr-1"></i><?php echo $status_label; ?>
                                </span>
                            </div>

                            <div class="va-meta">
                                <span><i class="fas fa-briefcase"></i>Applied for: <b><?php echo htmlspecialchars($app['job_title']); ?></b></span>
                                <span><i class="far fa-calendar-alt"></i>Applied: <?php echo date('M d, Y', strtotime($app['applied_date'])); ?></span>
                                <span><i class="fas fa-envelope"></i><?php echo htmlspecialchars($app['email']); ?></span>
                                <?php if (!empty($app['phone'])): ?>
                                    <span><i class="fas fa-phone"></i><?php echo htmlspecialchars($app['phone']); ?></span>
                                <?php endif; ?>
                            </div>

                            <?php
                                $app_user_id = $app['user_id'];
                                $vs_sql = "SELECT vs.skill_level, s.name FROM verified_skills vs JOIN skills s ON vs.skill_id = s.id WHERE vs.user_id = ?";
                                $vs_stmt = mysqli_prepare($con, $vs_sql);
                                mysqli_stmt_bind_param($vs_stmt, "i", $app_user_id);
                                mysqli_stmt_execute($vs_stmt);
                                $vs_result = mysqli_stmt_get_result($vs_stmt);
                            ?>
                            <?php if (mysqli_num_rows($vs_result) > 0): ?>
                                <div class="va-meta" style="margin-top: 8px;">
                                    <span style="color: #059669; font-weight: 600;"><i class="fas fa-check-circle"></i> Verified Skills:</span>
                                    <?php while($vs = mysqli_fetch_assoc($vs_result)): ?>
                                        <span class="va-badge" style="background: rgba(16, 185, 129, 0.1); color: #059669; margin-left: 4px;">
                                            <?php echo htmlspecialchars($vs['name']); ?> (<?php echo htmlspecialchars($vs['skill_level']); ?>)
                                        </span>
                                    <?php endwhile; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($app['cover_letter'])): ?>
                                <div class="va-cover">
                                    <i class="fas fa-quote-left"></i>
                                    <span><?php echo nl2br(htmlspecialchars(mb_substr($app['cover_letter'], 0, 180))); ?><?php echo mb_strlen($app['cover_letter']) > 180 ? '...' : ''; ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($app['user_degree'])): ?>
                                <div class="va-education"><i class="fas fa-graduation-cap"></i><?php echo htmlspecialchars($app['user_degree']); ?></div>
                            <?php endif; ?>

                            <?php
                            $skills = array_filter(array_map('trim', explode(',', $app['user_skills'])));
                            if (count($skills) > 0): ?>
                                <div class="va-skills">
                                    <?php foreach (array_slice($skills, 0, 6) as $skill): ?>
                                        <span class="va-skill"><?php echo htmlspecialchars($skill); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Quick Candidate Decisions -->
                            <div class="va-quick-decisions" id="decisions_app_<?php echo $app['id']; ?>">
                                <button type="button" class="va-q-btn va-q-review" onclick="quickUpdateStatus(<?php echo $app['id']; ?>, 'reviewed')">
                                    <i class="fas fa-eye"></i> Review
                                </button>
                                <button type="button" class="va-q-btn va-q-shortlist" onclick="quickUpdateStatus(<?php echo $app['id']; ?>, 'shortlisted')">
                                    <i class="fas fa-check"></i> Shortlist
                                </button>
                                <button type="button" class="va-q-btn va-q-reject" onclick="quickReject(<?php echo $app['id']; ?>)">
                                    <i class="fas fa-xmark"></i> Reject
                                </button>
                                <a href="schedule_interview.php?application_id=<?php echo $app['id']; ?>" class="va-q-btn va-q-interview" id="interview_btn_<?php echo $app['id']; ?>" style="<?php echo ($app_status === 'shortlisted') ? '' : 'display:none;'; ?>">
                                    <i class="fas fa-calendar-check"></i> Schedule Interview
                                </a>
                            </div>
                        </div>

                        <!-- AI CV MATCH Card Box -->
                        <div class="va-ai-box" id="ai_box_<?php echo $app['id']; ?>">
                            <div class="va-ai-top">
                                <span class="va-ai-title"><i class="fas fa-microchip"></i> AI CV MATCH</span>
                                <?php if ($ai_status === 'COMPLETED' && $ai_score !== null): ?>
                                    <span class="va-ai-score-pill" style="color: <?php echo $ai_score_color; ?>;">
                                        <?php echo $ai_score; ?><small>%</small>
                                    </span>
                                <?php elseif ($ai_status === 'OUTDATED'): ?>
                                    <span class="badge badge-warning" style="font-size:0.68rem; font-weight:700;">OUTDATED</span>
                                <?php elseif ($ai_status === 'FAILED'): ?>
                                    <span class="badge badge-danger" style="font-size:0.68rem; font-weight:700;">FAILED</span>
                                <?php else: ?>
                                    <span class="badge badge-light" style="font-size:0.68rem; font-weight:700; color:var(--va-muted);">PENDING</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($ai_status === 'COMPLETED' && $ai_score !== null): ?>
                                <div class="va-ai-subscores">
                                    <div class="va-ai-sub-row">
                                        <span>Skills Match</span>
                                        <b><?php echo intval($app['skills_match_score']); ?>%</b>
                                    </div>
                                    <div class="va-ai-sub-row">
                                        <span>Experience</span>
                                        <b><?php echo intval($app['experience_match_score']); ?>%</b>
                                    </div>
                                    <div class="va-ai-sub-row">
                                        <span>Education</span>
                                        <b><?php echo intval($app['education_match_score']); ?>%</b>
                                    </div>
                                </div>

                                <div class="va-ai-req-counts">
                                    <div>
                                        <span>Required</span>
                                        <b><?php echo $r_matched_count; ?> / <?php echo max(1, $r_total_count); ?></b>
                                    </div>
                                    <div style="text-align: right;">
                                        <span>Preferred</span>
                                        <b><?php echo $p_matched_count; ?> / <?php echo max(1, $p_total_count); ?></b>
                                    </div>
                                </div>

                                <div style="display:flex; gap:6px;">
                                    <a href="view_applicant_detail.php?id=<?php echo $app['id']; ?>#aiScreeningPanel" class="btn btn-sm btn-outline-primary" style="flex:1; border-radius:8px; font-weight:700; font-size:0.75rem;">
                                        <i class="fas fa-magnifying-glass-chart mr-1"></i> AI Analysis
                                    </a>
                                </div>
                            <?php elseif ($ai_status === 'OUTDATED'): ?>
                                <div class="va-ai-empty">
                                    <i class="fas fa-clock-rotate-left" style="color:#d97706;"></i>
                                    <div style="font-size:0.78rem; font-weight:700; color:var(--va-text); margin-bottom:6px;">Analysis Outdated</div>
                                    <button type="button" class="btn btn-sm btn-warning" onclick="quickAnalyze(<?php echo $app['id']; ?>, true)" style="border-radius:8px; font-weight:700; font-size:0.75rem; width:100%;">
                                        <i class="fas fa-arrows-rotate mr-1"></i> Re-analyze
                                    </button>
                                </div>
                            <?php elseif ($ai_status === 'FAILED'): ?>
                                <div class="va-ai-empty">
                                    <i class="fas fa-triangle-exclamation" style="color:#dc2626;"></i>
                                    <div style="font-size:0.78rem; font-weight:700; color:var(--va-text); margin-bottom:6px;">Analysis Failed</div>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="quickAnalyze(<?php echo $app['id']; ?>, false)" style="border-radius:8px; font-weight:700; font-size:0.75rem; width:100%;">
                                        <i class="fas fa-rotate-right mr-1"></i> Retry
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="va-ai-empty">
                                    <i class="fas fa-wand-magic-sparkles"></i>
                                    <div style="font-size:0.78rem; font-weight:700; color:var(--va-text); margin-bottom:6px;">Not Analyzed</div>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="quickAnalyze(<?php echo $app['id']; ?>, false)" style="background:linear-gradient(135deg, #6366f1, #8b5cf6); border:none; border-radius:8px; font-weight:700; font-size:0.75rem; width:100%;">
                                        <i class="fas fa-sparkles mr-1"></i> Analyze CV
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($app['quiz_score'] !== null): ?>
                            <div class="va-score">
                                <div class="va-score-ring" style="background: <?php echo $score_color; ?>1a; color: <?php echo $score_color; ?>; border: 3px solid <?php echo $score_color; ?>55;">
                                    <?php echo $score; ?><small>%</small>
                                </div>
                                <div class="va-score-cap">Quiz Score</div>
                            </div>
                        <?php endif; ?>

                        <div class="va-actions">
                            <a class="va-act va-act-detail" href="view_applicant_detail.php?id=<?php echo $app['id']; ?>">
                                <i class="fas fa-eye"></i>View Details
                            </a>
                            <?php
                            $cv_link = '';
                            if (!empty($app['cv_type'])) {
                                if ($app['cv_type'] === 'uploaded' && !empty($app['cv_file'])) {
                                    $cv_link = '../uploads/cv_files/' . htmlspecialchars($app['cv_file']);
                                } elseif ($app['cv_type'] === 'ai_customized' && !empty($app['ai_cv_id'])) {
                                    $cv_link = '../seeker/view_ai_cv.php?id=' . intval($app['ai_cv_id']);
                                } else {
                                    $cv_link = '../seeker/view_cv.php?id=' . intval($app['user_id']);
                                }
                            } elseif (!empty($app['profile'])) {
                                $cv_link = '../files/' . htmlspecialchars($app['profile']);
                            }
                            ?>
                            <?php if (!empty($cv_link)): ?>
                                <a class="va-act va-act-cv" href="<?php echo $cv_link; ?>" target="_blank">
                                    <i class="fas fa-file-pdf"></i>View CV
                                </a>
                            <?php else: ?>
                                <span class="va-act va-act-cv disabled"><i class="fas fa-file-pdf"></i>No CV</span>
                            <?php endif; ?>
                            <a class="va-act" href="view_applicant_detail.php?id=<?php echo $app['id']; ?>#aiScreeningPanel" style="background: rgba(99, 102, 241, 0.1); border-color: rgba(99, 102, 241, 0.3); color: #6366f1;">
                                <i class="fas fa-wand-magic-sparkles"></i>AI Analysis
                            </a>
                            <a class="va-act va-act-contact" href="mailto:<?php echo htmlspecialchars($app['email']); ?>">
                                <i class="fas fa-envelope"></i>Contact
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
            <div class="va-empty" id="vaNoMatch" style="display:none;">
                <i class="fas fa-user-magnifying-glass"></i>
                <h3>No Applicants Found</h3>
                <p>Try a different search term.</p>
            </div>
        <?php else: ?>
            <div class="va-empty">
                <i class="fas fa-inbox"></i>
                <h3>No Applicants Yet</h3>
                <p>When candidates apply for your jobs, they'll appear here.</p>
                <a href="my_jobs.php" class="btn btn-primary rounded-pill px-4 py-2 mt-3"><i class="fas fa-briefcase mr-2"></i>View Your Jobs</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function filterApplicants() {
            const q = (document.getElementById('vaSearch').value || '').toLowerCase();
            const cards = document.querySelectorAll('.va-card');
            let visible = 0;
            cards.forEach(card => {
                const ok = !q || card.dataset.search.includes(q);
                card.style.display = ok ? '' : 'none';
                if (ok) visible++;
            });
            const noMatch = document.getElementById('vaNoMatch');
            if (noMatch) noMatch.style.display = visible === 0 ? '' : 'none';
        }

        function applyFilters() {
            const job = document.getElementById('filterJob') ? document.getElementById('filterJob').value : '0';
            const appStatus = document.getElementById('filterAppStatus') ? document.getElementById('filterAppStatus').value : 'all';
            const aiScore = document.getElementById('filterAiScore') ? document.getElementById('filterAiScore').value : 'all';
            const sort = document.getElementById('filterSort') ? document.getElementById('filterSort').value : 'default';
            const skill = document.getElementById('filterSkill') ? document.getElementById('filterSkill').value : '0';
            const level = document.getElementById('filterLevel') ? document.getElementById('filterLevel').value : 'Any';
            
            let url = '?job_id=' + encodeURIComponent(job) + 
                      '&status=<?php echo $status_filter; ?>' + 
                      '&app_status=' + encodeURIComponent(appStatus) + 
                      '&ai_score=' + encodeURIComponent(aiScore) + 
                      '&sort=' + encodeURIComponent(sort) + 
                      '&verified_skill_id=' + encodeURIComponent(skill) + 
                      '&verified_level=' + encodeURIComponent(level);
            window.location.href = url;
        }

        function quickUpdateStatus(appId, newStatus) {
            fetch('api_ai_cv_screening.php?action=update_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ application_id: appId, status: newStatus })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const badge = document.getElementById('badge_app_' + appId);
                    const intBtn = document.getElementById('interview_btn_' + appId);
                    const card = document.getElementById('card_app_' + appId);
                    
                    const labels = {
                        'pending': 'NEW',
                        'reviewed': 'REVIEWED',
                        'shortlisted': 'SHORTLISTED',
                        'rejected': 'REJECTED'
                    };
                    const colors = {
                        'pending': '#f59e0b',
                        'reviewed': '#2563eb',
                        'shortlisted': '#059669',
                        'rejected': '#dc2626'
                    };

                    if (badge) {
                        badge.innerHTML = '<i class="fas fa-tag mr-1"></i>' + (labels[newStatus] || newStatus.toUpperCase());
                        badge.className = 'va-badge status-badge-' + newStatus;
                    }

                    if (intBtn) {
                        intBtn.style.display = (newStatus === 'shortlisted') ? 'inline-flex' : 'none';
                    }

                    if (card) {
                        card.dataset.appstatus = newStatus;
                    }
                } else {
                    alert(data.message || 'Error updating status');
                }
            })
            .catch(err => {
                alert('Communication error updating status');
            });
        }

        function quickReject(appId) {
            if (confirm('Are you sure you want to mark this candidate as Rejected?')) {
                quickUpdateStatus(appId, 'rejected');
            }
        }

        function quickAnalyze(appId, isReanalyze) {
            const aiBox = document.getElementById('ai_box_' + appId);
            if (aiBox) {
                aiBox.innerHTML = '<div style="text-align:center; padding:25px 10px;"><i class="fas fa-spinner fa-spin" style="font-size:1.8rem; color:#6366f1;"></i><p style="margin-top:8px; font-size:0.75rem; color:var(--va-muted); font-weight:700;">AI analyzing CV...</p></div>';
            }
            const action = isReanalyze ? 'reanalyze' : 'analyze';
            fetch('api_ai_cv_screening.php?action=' + action + '&application_id=' + appId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ application_id: appId })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'AI CV analysis failed.');
                    window.location.reload();
                }
            })
            .catch(err => {
                alert('Error connecting to AI screening service.');
                window.location.reload();
            });
        }
    </script>
</body>
</html>
