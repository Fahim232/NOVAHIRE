<?php
    require_once __DIR__ . '/../includes/bootstrap.php';
    require_once __DIR__ . '/../includes/hiring_workflow.php';
    global $con;

    // Check if company is logged in
    if (!isset($_SESSION['company_id'])) {
        header('Location: ../auth/login.php');
        exit;
    }

    $company_id = $_SESSION['company_id'];
    $company_name = $_SESSION['company_name'] ?? 'Company';

    // Get application ID
    if (!isset($_GET['id'])) {
        header('Location: view_applicants.php');
        exit;
    }

    $app_id = intval($_GET['id']);

    // Fetch application details
    $app_query = "SELECT ja.*, cj.job_title, cj.job_category, cj.job_description, 
                  ui.username, ui.email, ui.phone, ui.user_degree, ui.user_skills, ui.profile,
                  jqa.total_questions, jqa.correct_answers, jqa.score_percentage, jqa.time_taken, jqa.attempt_date
                  FROM job_applications ja
                  JOIN company_jobs cj ON ja.job_id = cj.id
                  JOIN user_info ui ON ja.user_id = ui.id
                  LEFT JOIN job_quiz_attempts jqa ON ja.id = jqa.application_id
                  WHERE ja.id = $app_id AND ja.company_id = $company_id";
    $app_result = mysqli_query($con, $app_query);

    if (!$app_result || mysqli_num_rows($app_result) == 0) {
        header('Location: view_applicants.php');
        exit;
    }

    $app = mysqli_fetch_assoc($app_result);

    // Fetch scheduled interviews for this application with consolidated staff feedback
    $app_interviews = [];
    $int_stmt = mysqli_prepare($con, "SELECT i.*, cs.full_name AS interviewer_name, cs.designation AS interviewer_designation
                                       FROM interviews i
                                       LEFT JOIN company_staff cs ON i.interviewer_id = cs.id
                                       WHERE i.application_id = ? AND i.company_id = ?
                                       ORDER BY i.interview_date DESC, i.interview_time DESC");
    if ($int_stmt) {
        mysqli_stmt_bind_param($int_stmt, "ii", $app_id, $company_id);
        mysqli_stmt_execute($int_stmt);
        $int_result = mysqli_stmt_get_result($int_stmt);
        while ($irow = mysqli_fetch_assoc($int_result)) {
            $irow['consolidated'] = get_interview_feedback_consolidated($con, $irow['id']);
            $app_interviews[] = $irow;
        }
        mysqli_stmt_close($int_stmt);
    }

    // Consolidated employment status across all interviews
    $overall_emp_status = 'NOT_CURRENTLY_WORKING';
    $overall_leaving_date = null;
    $has_overall_discrepancy = false;
    $total_feedback_count = 0;

    foreach ($app_interviews as $aint) {
        if (!empty($aint['consolidated']['feedbacks'])) {
            $total_feedback_count += count($aint['consolidated']['feedbacks']);
            if (!empty($aint['consolidated']['has_discrepancy'])) {
                $has_overall_discrepancy = true;
            }
            if (($aint['consolidated']['consensus_status'] ?? '') === 'CURRENTLY_WORKING') {
                $overall_emp_status = 'CURRENTLY_WORKING';
                if (!empty($aint['consolidated']['consensus_leaving_date'])) {
                    $overall_leaving_date = $aint['consolidated']['consensus_leaving_date'];
                }
            }
        }
    }

    $suggested_joining_date = calculate_suggested_joining_date($overall_emp_status, $overall_leaving_date) ?? '';

    // Fetch latest assessment session for this application
    $sess_query = "SELECT * FROM assessment_sessions WHERE application_id = $app_id ORDER BY id DESC LIMIT 1";
    $sess_result = mysqli_query($con, $sess_query);
    $assessment_session = $sess_result && mysqli_num_rows($sess_result) > 0 ? mysqli_fetch_assoc($sess_result) : null;
    
    $responses = [];
    $assessment_events = [];
    if ($assessment_session) {
        $sess_id = $assessment_session['id'];
        $resp_query = "SELECT ar.*, cjq.question, cjq.question_type, cjq.ideal_answer, cjq.correct_answer, cjq.marks 
                       FROM assessment_responses ar
                       JOIN company_job_questions cjq ON ar.question_id = cjq.id
                       WHERE ar.session_id = $sess_id
                       ORDER BY ar.question_index ASC";
        $resp_result = mysqli_query($con, $resp_query);
        if ($resp_result) {
            while ($row = mysqli_fetch_assoc($resp_result)) {
                $responses[] = $row;
            }
        }

        // Fetch proctoring / anti-cheating events
        $ev_query = "SELECT * FROM assessment_events WHERE session_id = $sess_id ORDER BY id ASC";
        $ev_result = mysqli_query($con, $ev_query);
        if ($ev_result) {
            while ($er = mysqli_fetch_assoc($ev_result)) {
                $assessment_events[] = $er;
            }
        }
    }

    // Update application status
    if (isset($_POST['update_status'])) {
        $new_status = mysqli_real_escape_string($con, $_POST['application_status']);
        $update_query = "UPDATE job_applications SET application_status = '$new_status' WHERE id = $app_id";
        if (mysqli_query($con, $update_query)) {
            require_once __DIR__ . '/../includes/functions.php';
            notify_application_status($con, $app['user_id'], $company_id, $app['job_title'], $company_name, $new_status);

            send_message($con, 'company', $company_id, 'user', $app['user_id'],
                "Application Update: " . ucfirst($new_status),
                "Your application for {$app['job_title']} has been updated to: " . ucfirst($new_status),
                $app['job_id']);

            $app['application_status'] = $new_status;
            $status_updated = true;
        } else {
            $status_error = true;
        }
    }
    // Handle final hiring decision
    $hiring_error = '';
    $hiring_success = '';

    if (isset($_POST['submit_final_decision'])) {
        $decision = $_POST['final_decision'] ?? '';
        $notes = trim($_POST['final_notes'] ?? '');
        $candidate_id = $app['user_id'];
        
        $final_joining_date = null;
        $emp_status_noted = $_POST['employment_status_noted'] ?? $overall_emp_status;
        $exp_leaving_date_noted = ($emp_status_noted === 'CURRENTLY_WORKING') ? trim($_POST['expected_leaving_date_noted'] ?? ($overall_leaving_date ?? '')) : null;
        $selected_docs = $_POST['required_documents'] ?? [];

        if ($decision === 'selected') {
            $final_joining_date = trim($_POST['final_joining_date'] ?? '');
            if (!empty($final_joining_date)) {
                $val_jd = validate_final_joining_date($final_joining_date, $emp_status_noted, $exp_leaving_date_noted);
                if (!$val_jd['valid']) {
                    $hiring_error = $val_jd['error'];
                }
            } else {
                $final_joining_date = null;
            }
        }

        if (empty($hiring_error)) {
            $hd_res = save_hiring_decision(
                $con,
                $company_id,
                $app_id,
                $candidate_id,
                $company_id,
                $decision,
                $notes,
                $suggested_joining_date,
                $final_joining_date,
                $emp_status_noted,
                $exp_leaving_date_noted
            );

            if ($hd_res['success']) {
                if ($decision === 'selected') {
                    $hiring_success = "Candidate selected! A notification has been sent inviting the candidate to confirm their readiness, provide availability, and upload required documents.";
                } elseif ($decision === 'under_final_review') {
                    $hiring_success = "Application placed on hold under final review. The candidate has been informed.";
                } else {
                    $hiring_success = "Candidate rejected. The hiring process has been closed for this application.";
                }
                $app['application_status'] = $decision;
                $status_updated = true;

                // Process document requirements if candidate was selected
                if ($decision === 'selected') {
                    $doc_map = [
                        'NID' => ['National Identity Card (NID)', 'Government-issued National ID Card or Smart Card.'],
                        'Educational Certificate' => ['Educational Certificate', 'Official certificate of your highest educational degree.'],
                        'Academic Transcript' => ['Academic Transcript', 'Official university/board academic transcript or mark sheet.'],
                        'Employment Certificate' => ['Relieving Letter / Experience Certificate', 'Release letter or proof of clearance from previous employer.'],
                        'Experience Certificate' => ['Experience Certificate', 'Service or experience certificates from relevant previous roles.'],
                        'Passport Photo' => ['Passport Size Photograph', 'Recent formal passport-sized photograph.'],
                        'Other Document' => ['Other Verified Credentials', 'Additional credential documents as requested.']
                    ];

                    $req_items = [];
                    if (!empty($selected_docs) && is_array($selected_docs)) {
                        foreach ($selected_docs as $d_key) {
                            $d_info = $doc_map[$d_key] ?? [$d_key, 'Required onboarding credential.'];
                            $req_items[] = [
                                'document_type' => $d_key,
                                'title'         => $d_info[0],
                                'description'   => $d_info[1]
                            ];
                        }
                    } else {
                        // Standard default requirements if none specifically chosen
                        $req_items[] = ['document_type' => 'NID', 'title' => 'National Identity Card (NID)', 'description' => 'Government-issued National ID Card or Smart Card.'];
                        $req_items[] = ['document_type' => 'Educational Certificate', 'title' => 'Educational Certificate', 'description' => 'Official certificate of your highest educational degree.'];
                        $req_items[] = ['document_type' => 'Academic Transcript', 'title' => 'Academic Transcript', 'description' => 'Official university/board academic transcript or mark sheet.'];
                        if ($emp_status_noted === 'CURRENTLY_WORKING') {
                            $req_items[] = ['document_type' => 'Employment Certificate', 'title' => 'Relieving Letter / Experience Certificate', 'description' => 'Release letter or proof of clearance from previous employer.'];
                        }
                    }

                    create_document_requirements($con, $company_id, $app_id, $candidate_id, $req_items, $company_id);
                }
            } else {
                $hiring_error = "Error saving decision: " . $hd_res['error'];
            }
        }
    }

    // Fetch existing hiring decision
    $hd_result = mysqli_query($con, "SELECT * FROM hiring_decisions WHERE application_id = $app_id AND company_id = $company_id LIMIT 1");
    $hiring_decision = $hd_result && mysqli_num_rows($hd_result) > 0 ? mysqli_fetch_assoc($hd_result) : null;

    // Fetch hiring letter
    $hl_res = mysqli_query($con, "SELECT * FROM hiring_letters WHERE application_id = $app_id AND company_id = $company_id LIMIT 1");
    $hiring_letter = $hl_res && mysqli_num_rows($hl_res) > 0 ? mysqli_fetch_assoc($hl_res) : null;

    // Fetch hiring document requirements & uploaded count
    $docs_req_res = mysqli_query($con, "SELECT * FROM hiring_document_requirements WHERE application_id = $app_id ORDER BY id ASC");
    $app_doc_requirements = [];
    if ($docs_req_res) {
        while ($dr = mysqli_fetch_assoc($docs_req_res)) {
            $app_doc_requirements[] = $dr;
        }
    }

    // If candidate is hired but no requirements exist yet, initialize standard requirements
    if (empty($app_doc_requirements) && (($hiring_decision['decision'] ?? '') === 'selected' || $app['application_status'] === 'selected')) {
        $default_req_items = [
            ['document_type' => 'NID', 'title' => 'National Identity Card (NID)', 'description' => 'Government-issued National ID Card or Smart Card.'],
            ['document_type' => 'Educational Certificate', 'title' => 'Educational Certificate', 'description' => 'Official certificate of your highest educational degree.'],
            ['document_type' => 'Academic Transcript', 'title' => 'Academic Transcript', 'description' => 'Official university/board academic transcript or mark sheet.']
        ];
        if (($hiring_decision['employment_status_noted'] ?? $overall_emp_status) === 'CURRENTLY_WORKING') {
            $default_req_items[] = ['document_type' => 'Employment Certificate', 'title' => 'Relieving Letter / Experience Certificate', 'description' => 'Release letter or proof of clearance from previous employer.'];
        }
        create_document_requirements($con, $company_id, $app_id, $app['user_id'], $default_req_items, $company_id);

        $docs_req_res = mysqli_query($con, "SELECT * FROM hiring_document_requirements WHERE application_id = $app_id ORDER BY id ASC");
        $app_doc_requirements = [];
        if ($docs_req_res) {
            while ($dr = mysqli_fetch_assoc($docs_req_res)) {
                $app_doc_requirements[] = $dr;
            }
        }
    }

    $docs_uploaded_res = mysqli_query($con, "SELECT * FROM candidate_documents WHERE application_id = $app_id");
    $app_uploaded_count = $docs_uploaded_res ? mysqli_num_rows($docs_uploaded_res) : 0;
    $app_verified_count = 0;
    if ($docs_uploaded_res) {
        while ($cd = mysqli_fetch_assoc($docs_uploaded_res)) {
            if ($cd['status'] === 'VERIFIED') $app_verified_count++;
        }
    }

    $avatar_gradients = [
        ['#3b82f6', '#06b6d4'],
        ['#0ea5e9', '#06b6d4'],
        ['#059669', '#34d399'],
        ['#d97706', '#f97316'],
        ['#ec4899', '#f43f5e'],
        ['#14b8a6', '#0d9488'],
    ];

    $initial = strtoupper(substr(trim($app['username']), 0, 1) ?: '?');
    $g = $avatar_gradients[abs(crc32($app['username'])) % count($avatar_gradients)];

    if ($assessment_session && $assessment_session['score_final'] !== null) {
        $quiz_score = floatval($assessment_session['score_final']);
    } else {
        $quiz_score = isset($app['score_percentage']) ? floatval($app['score_percentage']) : intval($app['quiz_score']);
    }
    $score_pct = round($quiz_score);
    $score_color = $score_pct >= 60 ? '#059669' : ($score_pct >= 30 ? '#d97706' : '#dc2626');

    $status_colors = [
        'pending'             => ['#94a3b8', 'Pending Review'],
        'reviewed'            => ['#06b6d4', 'Reviewed'],
        'shortlisted'         => ['#3b82f6', 'Shortlisted'],
        'under_final_review'  => ['#8b5cf6', 'Under Final Review'],
        'selected'            => ['#10b981', 'Selected'],
        'rejected'            => ['#dc2626', 'Rejected'],
    ];
    $app_status = $app['application_status'] ?: 'pending';
    $app_color = isset($status_colors[$app_status]) ? $status_colors[$app_status][0] : '#94a3b8';
    $app_label = isset($status_colors[$app_status]) ? $status_colors[$app_status][1] : ucfirst($app_status);

    // Load AI CV Screening status & data
    $ai_screening_info = nh_get_application_analysis_status($con, $company_id, $app_id);
    $ai_analysis = $ai_screening_info['analysis'] ?? null;
    $ai_status = $ai_screening_info['status'] ?? 'NOT_ANALYZED';
    $ai_is_outdated = $ai_screening_info['is_outdated'] ?? false;
    $ai_outdated_reason = $ai_screening_info['outdated_reason'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Applicant Details | Company Dashboard</title>
    <?php include '../includes/links.php'; ?>
    <style>
        :root {
            --ad-bg: #f4f6fb;
            --ad-card: #ffffff;
            --ad-border: #e5e9f2;
            --ad-text: #1e293b;
            --ad-muted: #64748b;
            --ad-primary: #1a56db;
            --ad-primary-2: #0ea5e9;
            --ad-soft: #eef2ff;
            --ad-input: #f8fafc;
            --ad-shadow: 0 10px 30px rgba(15, 23, 42, 0.07);
        }
        [data-theme="dark"] {
            --ad-bg: #0f172a;
            --ad-card: #111827;
            --ad-border: #28334a;
            --ad-text: #e8edff;
            --ad-muted: #94a3b8;
            --ad-primary: #06b6d4;
            --ad-primary-2: #38bdf8;
            --ad-soft: #1e293b;
            --ad-input: #0d1526;
            --ad-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
        }

        body {
            background:
                radial-gradient(circle at 8% 12%, rgba(99, 102, 241, 0.10), transparent 28%),
                radial-gradient(circle at 92% 8%, rgba(217, 70, 239, 0.08), transparent 26%),
                var(--ad-bg);
            color: var(--ad-text);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .ad-wrap { max-width: 1120px; margin: 0 auto; padding: 34px 24px 60px; }

        /* ── Hero ── */
        .ad-hero {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #1a56db 0%, #0ea5e9 55%, #38bdf8 100%);
            border-radius: 22px;
            padding: 30px 34px;
            color: #fff;
            box-shadow: 0 20px 40px rgba(79, 70, 229, 0.28);
        }
        .ad-hero::before {
            content: '';
            position: absolute;
            right: -80px; top: -80px;
            width: 260px; height: 260px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.10);
        }
        .ad-hero::after {
            content: '';
            position: absolute;
            right: 60px; bottom: -110px;
            width: 220px; height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
        }
        .ad-hero-in { position: relative; z-index: 1; display: flex; align-items: center; gap: 22px; flex-wrap: wrap; }
        .ad-avatar {
            width: 78px; height: 78px;
            border-radius: 24px;
            color: #fff;
            font-weight: 800;
            font-size: 1.9rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.25);
            border: 3px solid rgba(255, 255, 255, 0.35);
        }
        .ad-hero-info h1 { font-weight: 800; font-size: 1.7rem; color: #fff; margin: 0 0 4px; }
        .ad-hero-info p { color: rgba(255, 255, 255, 0.85); margin: 0; font-size: 0.92rem; }
        .ad-hero-info p i { margin-right: 6px; }
        .ad-hero-badges { display: flex; gap: 9px; margin-top: 12px; flex-wrap: wrap; }
        .ad-hbadge {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 0.76rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .4px;
            padding: 6px 14px; border-radius: 30px;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #fff;
        }
        .ad-back {
            margin-left: auto;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #fff;
            padding: 11px 20px;
            border-radius: 13px;
            font-size: 0.85rem; font-weight: 600;
            transition: all .2s ease;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .ad-back:hover { background: #fff; color: #1a56db; text-decoration: none; }

        /* ── Section cards ── */
        .ad-section {
            background: var(--ad-card);
            border: 1px solid var(--ad-border);
            border-radius: 18px;
            padding: 24px 26px;
            margin-top: 20px;
            box-shadow: var(--ad-shadow);
            animation: adIn .4s ease both;
        }
        @keyframes adIn {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .ad-section-head {
            display: flex; align-items: center; gap: 13px;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--ad-border);
        }
        .ad-section-head .ico {
            width: 42px; height: 42px;
            border-radius: 13px;
            background: linear-gradient(135deg, var(--ad-primary), var(--ad-primary-2));
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.05rem;
            box-shadow: 0 8px 18px rgba(79, 70, 229, 0.3);
        }
        .ad-section-head h3 { font-size: 1.05rem; font-weight: 700; margin: 0; color: var(--ad-text); }
        .ad-section-head p { font-size: 0.8rem; color: var(--ad-muted); margin: 2px 0 0; }

        /* Info grid */
        .ad-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .ad-item {
            background: var(--ad-input);
            border: 1px solid var(--ad-border);
            border-radius: 13px;
            padding: 15px 17px;
            transition: border-color .2s ease;
        }
        .ad-item:hover { border-color: var(--ad-primary); }
        .ad-item small {
            display: block;
            font-size: 0.68rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .6px;
            color: var(--ad-muted);
            margin-bottom: 5px;
        }
        .ad-item span { font-size: 0.92rem; color: var(--ad-text); font-weight: 600; word-break: break-word; }
        .ad-item a { color: var(--ad-primary); text-decoration: none; }
        .ad-item a:hover { text-decoration: underline; }

        /* Skills */
        .ad-skills { display: flex; flex-wrap: wrap; gap: 8px; }
        .ad-skill {
            background: var(--ad-soft);
            border: 1px solid var(--ad-border);
            color: var(--ad-text);
            font-size: 0.8rem; font-weight: 600;
            padding: 7px 15px; border-radius: 22px;
            display: inline-flex; align-items: center; gap: 7px;
        }
        .ad-skill i { color: var(--ad-primary); font-size: 0.75rem; }

        /* Cover letter */
        .ad-cover {
            background: var(--ad-input);
            border: 1px solid var(--ad-border);
            border-left: 4px solid var(--ad-primary);
            border-radius: 13px;
            padding: 18px 20px;
            font-size: 0.9rem;
            line-height: 1.7;
            color: var(--ad-text);
            white-space: pre-line;
        }

        /* Job preview */
        .ad-job {
            background: var(--ad-input);
            border: 1px solid var(--ad-border);
            border-radius: 13px;
            padding: 18px 20px;
        }
        .ad-job h4 { font-size: 1rem; font-weight: 700; color: var(--ad-text); margin: 0 0 8px; }
        .ad-job p { font-size: 0.85rem; color: var(--ad-muted); line-height: 1.65; margin: 0; }
        .ad-job .cat {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 0.72rem; font-weight: 700;
            color: var(--ad-primary);
            background: var(--ad-soft);
            border: 1px solid var(--ad-border);
            padding: 4px 12px; border-radius: 20px;
            margin-bottom: 8px;
        }

        /* Score ring */
        .ad-score {
            display: flex; align-items: center; gap: 22px; flex-wrap: wrap;
        }
        .ad-ring {
            width: 128px; height: 128px;
            border-radius: 50%;
            position: relative;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .ad-ring .inner {
            width: 96px; height: 96px;
            border-radius: 50%;
            background: var(--ad-card);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
        }
        .ad-ring b { font-size: 1.5rem; font-weight: 800; line-height: 1; }
        .ad-ring small { font-size: 0.62rem; font-weight: 600; color: var(--ad-muted); margin-top: 3px; }
        .ad-score-stats { display: flex; gap: 14px; flex-wrap: wrap; flex: 1; }
        .ad-score-stat {
            flex: 1; min-width: 120px;
            background: var(--ad-input);
            border: 1px solid var(--ad-border);
            border-radius: 13px;
            padding: 14px 16px;
            text-align: center;
        }
        .ad-score-stat b { display: block; font-size: 1.15rem; font-weight: 800; color: var(--ad-text); }
        .ad-score-stat span { font-size: 0.7rem; color: var(--ad-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
        .ad-not-taken {
            text-align: center; padding: 30px 20px;
            color: var(--ad-muted);
        }
        .ad-not-taken i { font-size: 3rem; opacity: .35; }
        .ad-not-taken h4 { font-weight: 700; color: var(--ad-text); margin-top: 12px; }

        /* Status selector */
        .ad-status-pills { display: flex; gap: 10px; flex-wrap: wrap; }
        .ad-pill {
            flex: 1; min-width: 130px;
            border: 1.5px solid var(--ad-border);
            background: var(--ad-input);
            color: var(--ad-muted);
            border-radius: 13px;
            padding: 13px 15px;
            cursor: pointer;
            text-align: left;
            transition: all .2s ease;
        }
        .ad-pill b { display: block; font-size: 0.86rem; font-weight: 700; margin-bottom: 2px; }
        .ad-pill span { font-size: 0.72rem; }
        .ad-pill input { display: none; }
        .ad-pill.sel {
            border-color: var(--ad-primary);
            background: var(--ad-soft);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.12);
        }
        .ad-pill.sel b { color: var(--ad-primary); }

        /* Actions */
        .ad-actions { display: flex; flex-wrap: wrap; gap: 11px; }
        .ad-act {
            display: inline-flex; align-items: center; justify-content: center; gap: 9px;
            padding: 12px 22px; border-radius: 13px;
            font-size: 0.85rem; font-weight: 700;
            border: 1.5px solid var(--ad-border);
            background: var(--ad-card);
            color: var(--ad-text);
            text-decoration: none;
            transition: all .18s ease;
        }
        .ad-act:hover { transform: translateY(-2px); text-decoration: none; }
        .ad-act-interview { background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.4); color: #d97706; }
        .ad-act-interview:hover { background: #d97706; color: #fff; }
        .ad-act-cv { background: rgba(16, 185, 129, 0.12); border-color: rgba(16, 185, 129, 0.4); color: #059669; }
        .ad-act-cv:hover { background: #059669; color: #fff; }
        .ad-act-msg { background: rgba(139, 92, 246, 0.12); border-color: rgba(139, 92, 246, 0.4); color: #06b6d4; }
        .ad-act-msg:hover { background: #06b6d4; color: #fff; }
        .ad-act-mail { background: rgba(59, 130, 246, 0.12); border-color: rgba(59, 130, 246, 0.4); color: #3b82f6; }
        .ad-act-mail:hover { background: #3b82f6; color: #fff; }
        .ad-act-call { background: rgba(6, 182, 212, 0.12); border-color: rgba(6, 182, 212, 0.4); color: #06b6d4; }
        .ad-act-call:hover { background: #06b6d4; color: #fff; }

        .ad-save-btn {
            display: inline-flex; align-items: center; gap: 9px;
            background: linear-gradient(135deg, var(--ad-primary), var(--ad-primary-2));
            color: #fff;
            padding: 14px 30px; border-radius: 13px;
            font-size: 0.92rem; font-weight: 700;
            border: none; cursor: pointer;
            box-shadow: 0 10px 22px rgba(79, 70, 229, 0.35);
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .ad-save-btn:hover { transform: translateY(-2px); box-shadow: 0 14px 28px rgba(79, 70, 229, 0.45); }

        /* Toast */
        .ad-toast {
            position: fixed; top: 84px; right: 24px; z-index: 9999;
            background: var(--ad-card);
            border: 1px solid var(--ad-border);
            border-left: 4px solid #059669;
            border-radius: 14px;
            padding: 15px 20px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.18);
            opacity: 0; transform: translateX(30px);
            transition: all .35s ease;
            pointer-events: none;
        }
        .ad-toast.show { opacity: 1; transform: translateX(0); }
        .ad-toast i { color: #059669; font-size: 1.3rem; }
        .ad-toast b { color: var(--ad-text); font-size: 0.9rem; }

        /* ── AI CV Screening Design System ── */
        .ai-panel {
            background: var(--ad-card);
            border: 1.5px solid #6366f1;
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 12px 36px rgba(99, 102, 241, 0.12);
            position: relative;
            overflow: hidden;
        }
        .ai-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--ad-border);
            margin-bottom: 24px;
        }
        .ai-panel-title-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .ai-panel-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            box-shadow: 0 8px 18px rgba(99, 102, 241, 0.35);
        }
        .ai-panel-title-wrap h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--ad-text);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .ai-panel-title-wrap p {
            margin: 4px 0 0;
            font-size: 0.85rem;
            color: var(--ad-muted);
        }
        .ai-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 0.82rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .ai-badge-completed { background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3); }
        .ai-badge-outdated { background: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.3); }
        .ai-badge-failed { background: rgba(239, 68, 68, 0.15); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.3); }
        .ai-badge-not-analyzed { background: rgba(148, 163, 184, 0.15); color: #64748b; border: 1px solid rgba(148, 163, 184, 0.3); }

        /* Score Display Grid */
        .ai-score-overview {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 24px;
            align-items: center;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(168, 85, 247, 0.04));
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 18px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .ai-main-ring-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding-right: 20px;
            border-right: 1px solid rgba(99, 102, 241, 0.15);
        }
        .ai-match-ring {
            width: 130px;
            height: 130px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 6px solid #6366f1;
            background: var(--ad-card);
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.22);
            margin-bottom: 10px;
        }
        .ai-match-ring .num {
            font-size: 2.2rem;
            font-weight: 900;
            color: #6366f1;
            line-height: 1;
        }
        .ai-match-ring .pct {
            font-size: 1rem;
            font-weight: 700;
            color: #8b5cf6;
        }
        .ai-main-ring-box label {
            font-size: 0.88rem;
            font-weight: 800;
            color: var(--ad-text);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .ai-main-ring-box small {
            font-size: 0.72rem;
            color: var(--ad-muted);
            margin-top: 2px;
        }

        /* Subscores */
        .ai-subscores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        .ai-subscore-item {
            background: var(--ad-card);
            border: 1px solid var(--ad-border);
            border-radius: 12px;
            padding: 14px;
        }
        .ai-subscore-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--ad-text);
            margin-bottom: 8px;
        }
        .ai-bar-wrap {
            height: 8px;
            background: rgba(148, 163, 184, 0.2);
            border-radius: 10px;
            overflow: hidden;
        }
        .ai-bar-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.6s ease;
        }

        /* Why this score section */
        .ai-why-card {
            background: var(--ad-input);
            border: 1px solid var(--ad-border);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .ai-why-card h4 {
            margin: 0 0 14px;
            font-size: 0.96rem;
            font-weight: 800;
            color: var(--ad-text);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ai-why-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .ai-why-list li {
            font-size: 0.88rem;
            line-height: 1.5;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .ai-why-list li.strength { color: #059669; }
        .ai-why-list li.gap { color: #d97706; }

        /* Requirements Grid */
        .ai-req-section-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .ai-req-column {
            background: var(--ad-card);
            border: 1px solid var(--ad-border);
            border-radius: 14px;
            padding: 18px;
        }
        .ai-req-col-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--ad-border);
        }
        .ai-req-col-header h4 {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--ad-text);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ai-req-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .ai-req-item {
            background: var(--ad-input);
            border: 1px solid var(--ad-border);
            border-radius: 10px;
            padding: 12px 14px;
            transition: border-color 0.2s;
        }
        .ai-req-item-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            font-size: 0.88rem;
            color: var(--ad-text);
        }
        .ai-tag {
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 6px;
            text-transform: uppercase;
        }
        .ai-tag-matched { background: #dcfce7; color: #166534; }
        .ai-tag-missing { background: #fee2e2; color: #991b1b; }
        .ai-tag-unclear { background: #fef3c7; color: #92400e; }
        .ai-req-evidence {
            font-size: 0.8rem;
            color: var(--ad-muted);
            margin-top: 6px;
            line-height: 1.4;
            padding-left: 4px;
            border-left: 2px solid var(--ad-border);
        }

        /* Summary & Evidence */
        .ai-summary-block {
            background: rgba(99, 102, 241, 0.05);
            border-left: 4px solid #6366f1;
            border-radius: 0 12px 12px 0;
            padding: 16px 20px;
            font-size: 0.9rem;
            line-height: 1.65;
            color: var(--ad-text);
            margin-bottom: 24px;
        }
        .ai-footer-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            padding-top: 18px;
            border-top: 1px solid var(--ad-border);
            font-size: 0.82rem;
            color: var(--ad-muted);
        }

        /* Decision Support Banner */
        .ai-company-actions-bar {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.08), rgba(16, 185, 129, 0.04));
            border: 1.5px solid rgba(79, 70, 229, 0.25);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .ai-action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 14px;
        }
        .ai-act-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 22px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }
        .ai-act-review { background: #3b82f6; color: #fff; }
        .ai-act-review:hover { background: #2563eb; }
        .ai-act-shortlist { background: #10b981; color: #fff; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3); }
        .ai-act-shortlist:hover { background: #059669; transform: translateY(-1px); }
        .ai-act-reject { background: #ef4444; color: #fff; }
        .ai-act-reject:hover { background: #dc2626; }
        .ai-act-interview-btn { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; text-decoration: none; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35); }
        .ai-act-interview-btn:hover { background: #d97706; color: #fff; text-decoration: none; transform: translateY(-1px); }

        /* Modal Explainer */
        .ai-modal-backdrop {
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .ai-modal-box {
            background: var(--ad-card);
            border: 1px solid var(--ad-border);
            border-radius: 20px;
            max-width: 620px;
            width: 100%;
            padding: 30px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3);
            position: relative;
        }

        @media (max-width: 900px) {
            .ai-score-overview { grid-template-columns: 1fr; }
            .ai-main-ring-box { border-right: none; border-bottom: 1px solid rgba(99, 102, 241, 0.15); padding-right: 0; padding-bottom: 18px; }
            .ai-req-section-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/company_header.php'; ?>

    <div class="ad-wrap">
        <!-- Hero -->
        <div class="ad-hero">
            <div class="ad-hero-in">
                <div class="ad-avatar" style="background: linear-gradient(135deg, <?php echo $g[0]; ?>, <?php echo $g[1]; ?>);"><?php echo $initial; ?></div>
                <div class="ad-hero-info">
                    <h1><?php echo htmlspecialchars(trim($app['username'])); ?></h1>
                    <p><i class="fas fa-briefcase"></i>Applied for <strong><?php echo htmlspecialchars($app['job_title']); ?></strong></p>
                    <p><i class="fas fa-calendar"></i>Applied on <?php echo date('M d, Y h:i A', strtotime($app['applied_date'])); ?></p>
                    <div class="ad-hero-badges">
                        <?php if ($app['quiz_status'] == 'passed'): ?>
                            <span class="ad-hbadge"><i class="fas fa-circle-check"></i>Quiz Passed</span>
                        <?php elseif ($app['quiz_status'] == 'failed'): ?>
                            <span class="ad-hbadge"><i class="fas fa-circle-xmark"></i>Quiz Failed</span>
                        <?php else: ?>
                            <span class="ad-hbadge"><i class="fas fa-hourglass-half"></i>Not Taken</span>
                        <?php endif; ?>
                        <span class="ad-hbadge" style="background: <?php echo $app_color; ?>33; border-color: rgba(255,255,255,.4);"><i class="fas fa-tag"></i><?php echo $app_label; ?></span>
                    </div>
                </div>
                <a href="view_applicants.php?job_id=<?php echo $app['job_id']; ?>" class="ad-back">
                    <i class="fas fa-arrow-left"></i>Back to Applicants
                </a>
            </div>
        </div>

        <!-- Interview Status Section -->
        <div class="ad-section">
            <div class="ad-section-head">
                <div class="ico"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <h3>Interview Schedule</h3>
                    <p>Interview status for this applicant.</p>
                </div>
                <a href="schedule_interview.php?application_id=<?php echo $app_id; ?>" style="margin-left:auto; display:inline-flex; align-items:center; gap:7px; padding:9px 18px; border-radius:11px; font-size:0.84rem; font-weight:700; background:linear-gradient(135deg, var(--ad-primary), var(--ad-primary-2)); color:#fff; text-decoration:none; box-shadow:0 8px 18px rgba(79,70,229,0.3); transition:transform .2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                    <i class="fas fa-plus"></i>Schedule Interview
                </a>
            </div>
            <?php if (empty($app_interviews)): ?>
                <div style="text-align:center; padding:30px 20px; background:var(--ad-input); border:1.5px dashed var(--ad-border); border-radius:14px;">
                    <i class="fas fa-calendar-times" style="font-size:2rem; color:var(--ad-primary); opacity:.35;"></i>
                    <p style="color:var(--ad-muted); margin:10px 0 0; font-size:0.88rem;">No interviews scheduled yet for this applicant.</p>
                </div>
            <?php else: ?>
                <?php foreach ($app_interviews as $aint):
                    $int_status_colors = ['scheduled' => '#3b82f6', 'completed' => '#059669', 'cancelled' => '#dc2626'];
                    $int_sc = $int_status_colors[$aint['status']] ?? '#94a3b8';
                ?>
                <div style="background:var(--ad-input); border:1px solid var(--ad-border); border-radius:14px; padding:16px 20px; margin-bottom:10px;">
                    <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:200px;">
                            <div style="font-weight:700; font-size:0.92rem; color:var(--ad-text);">
                                <?php echo htmlspecialchars($aint['title'] ?? 'Interview'); ?>
                                <span style="font-size:0.72rem; font-weight:600; color:#8b5cf6; background:rgba(139,92,246,0.12); padding:2px 8px; border-radius:20px; margin-left:6px;">Round <?php echo intval($aint['round_number'] ?? 1); ?></span>
                            </div>
                            <div style="font-size:0.82rem; color:var(--ad-muted); margin-top:4px;">
                                <i class="fas fa-calendar" style="margin-right:4px;"></i><?php echo date('M d, Y', strtotime($aint['interview_date'])); ?>
                                &middot; <i class="fas fa-clock" style="margin-right:4px;"></i><?php echo date('g:i A', strtotime($aint['interview_time'])); ?><?php echo $aint['end_time'] ? ' – ' . date('g:i A', strtotime($aint['end_time'])) : ''; ?>
                                &middot; <?php echo htmlspecialchars($aint['interview_type']); ?>
                            </div>
                            <?php if (!empty($aint['interviewer_name'])): ?>
                            <div style="font-size:0.78rem; color:var(--ad-muted); margin-top:3px;"><i class="fas fa-user-tie" style="color:#d97706; margin-right:4px;"></i><?php echo htmlspecialchars($aint['interviewer_name']); ?><?php echo $aint['interviewer_designation'] ? ' · ' . htmlspecialchars($aint['interviewer_designation']) : ''; ?></div>
                            <?php endif; ?>
                        </div>
                        <span style="font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:5px 13px; border-radius:20px; background:<?php echo $int_sc; ?>20; color:<?php echo $int_sc; ?>;">
                            <?php echo ucfirst($aint['status']); ?>
                        </span>
                    </div>

                    <?php 
                    $cons = $aint['consolidated'] ?? null;
                    if ($cons && !empty($cons['feedbacks'])): 
                    ?>
                    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px dashed var(--ad-border);">
                        
                        <?php if (!empty($cons['has_discrepancy'])): ?>
                            <div style="background: rgba(239, 68, 68, 0.08); border: 1.5px solid #ef4444; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px;">
                                <div style="font-weight: 700; font-size: 0.88rem; color: #dc2626; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-triangle-exclamation"></i> Discrepancy in Interviewer Responses
                                </div>
                                <p style="margin: 4px 0 0; font-size: 0.82rem; color: var(--ad-text);">
                                    <?php echo htmlspecialchars($cons['discrepancy_message']); ?>
                                </p>
                                <small style="display: block; color: var(--ad-muted); margin-top: 4px;">
                                    Please review each staff evaluation below before finalizing the joining date.
                                </small>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($cons['feedbacks'] as $idx => $fb): 
                            $rec_badges = [
                                'STRONGLY_RECOMMEND' => ['#059669', 'Strongly Recommend'],
                                'RECOMMEND'          => ['#10b981', 'Recommend'],
                                'FURTHER_REVIEW'     => ['#d97706', 'Further Review'],
                                'NOT_RECOMMENDED'    => ['#dc2626', 'Not Recommended']
                            ];
                            $rec_meta = $rec_badges[$fb['recommendation'] ?? ''] ?? ['#64748b', 'Evaluated'];
                        ?>
                            <div style="background: var(--ad-card); border: 1px solid var(--ad-border); border-radius: 12px; padding: 16px; margin-bottom: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                                    <div>
                                        <b style="font-size: 0.9rem; color: var(--ad-text);"><i class="fas fa-user-check" style="color: #6366f1; margin-right: 6px;"></i><?php echo htmlspecialchars($fb['staff_name'] ?? 'Interviewer'); ?></b>
                                        <?php if (!empty($fb['staff_designation'])): ?>
                                            <span style="font-size: 0.78rem; color: var(--ad-muted);">&middot; <?php echo htmlspecialchars($fb['staff_designation']); ?></span>
                                        <?php endif; ?>
                                        <span style="font-size: 0.72rem; padding: 2px 8px; border-radius: 12px; background: <?php echo ($fb['status'] === 'SUBMITTED') ? '#dcfce7' : '#fef3c7'; ?>; color: <?php echo ($fb['status'] === 'SUBMITTED') ? '#166534' : '#b45309'; ?>; font-weight: 700; margin-left: 6px;">
                                            <?php echo htmlspecialchars($fb['status']); ?>
                                        </span>
                                    </div>
                                    <span style="font-size: 0.78rem; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: <?php echo $rec_meta[0]; ?>18; color: <?php echo $rec_meta[0]; ?>;">
                                        <?php echo $rec_meta[1]; ?>
                                    </span>
                                </div>

                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <div style="background: var(--ad-input); border: 1px solid var(--ad-border); border-radius: 8px; padding: 8px; flex: 1; min-width: 80px; text-align: center;">
                                        <span style="display: block; font-size: 0.65rem; color: var(--ad-muted); text-transform: uppercase; font-weight: 700;">Technical</span>
                                        <b style="font-size: 1rem; color: var(--ad-text);"><?php echo $fb['technical_score']; ?>/10</b>
                                    </div>
                                    <div style="background: var(--ad-input); border: 1px solid var(--ad-border); border-radius: 8px; padding: 8px; flex: 1; min-width: 80px; text-align: center;">
                                        <span style="display: block; font-size: 0.65rem; color: var(--ad-muted); text-transform: uppercase; font-weight: 700;">Comm.</span>
                                        <b style="font-size: 1rem; color: var(--ad-text);"><?php echo $fb['communication_score']; ?>/10</b>
                                    </div>
                                    <div style="background: var(--ad-input); border: 1px solid var(--ad-border); border-radius: 8px; padding: 8px; flex: 1; min-width: 80px; text-align: center;">
                                        <span style="display: block; font-size: 0.65rem; color: var(--ad-muted); text-transform: uppercase; font-weight: 700;">Problem</span>
                                        <b style="font-size: 1rem; color: var(--ad-text);"><?php echo $fb['problem_solving_score']; ?>/10</b>
                                    </div>
                                    <div style="background: var(--ad-input); border: 1px solid var(--ad-border); border-radius: 8px; padding: 8px; flex: 1; min-width: 80px; text-align: center;">
                                        <span style="display: block; font-size: 0.65rem; color: var(--ad-muted); text-transform: uppercase; font-weight: 700;">Teamwork</span>
                                        <b style="font-size: 1rem; color: var(--ad-text);"><?php echo $fb['teamwork_score']; ?>/10</b>
                                    </div>
                                    <div style="background: var(--ad-input); border: 1px solid var(--ad-border); border-radius: 8px; padding: 8px; flex: 1; min-width: 80px; text-align: center;">
                                        <span style="display: block; font-size: 0.65rem; color: var(--ad-muted); text-transform: uppercase; font-weight: 700;">Profess.</span>
                                        <b style="font-size: 1rem; color: var(--ad-text);"><?php echo $fb['professionalism_score']; ?>/10</b>
                                    </div>
                                    <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 8px; padding: 8px; flex: 1; min-width: 100px; text-align: center;">
                                        <span style="display: block; font-size: 0.65rem; color: #059669; text-transform: uppercase; font-weight: 700;">Total</span>
                                        <b style="font-size: 1.15rem; color: #059669;"><?php echo $fb['total_score']; ?>/50</b>
                                    </div>
                                </div>

                                <!-- Employment Information Section -->
                                <div style="margin-top: 12px; background: rgba(59, 130, 246, 0.04); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 8px; padding: 10px 14px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                        <div>
                                            <span style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: #3b82f6; letter-spacing: 0.4px;">
                                                <i class="fas fa-briefcase"></i> Reported by interviewer during interview
                                            </span>
                                            <div style="font-size: 0.85rem; font-weight: 600; color: var(--ad-text); margin-top: 2px;">
                                                Currently working at another company:
                                                <?php if ($fb['employment_status'] === 'CURRENTLY_WORKING'): ?>
                                                    <span style="color: #059669; font-weight: 800;">YES</span>
                                                <?php elseif ($fb['employment_status'] === 'NOT_CURRENTLY_WORKING'): ?>
                                                    <span style="color: #64748b; font-weight: 800;">NO</span>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8; font-style: italic;">Not Recorded</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($fb['employment_status'] === 'CURRENTLY_WORKING' && !empty($fb['expected_leaving_date'])): ?>
                                            <div style="text-align: right;">
                                                <span style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: var(--ad-muted); display: block;">Expected Leaving Date</span>
                                                <span style="font-size: 0.92rem; font-weight: 800; color: #d97706;">
                                                    <i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($fb['expected_leaving_date'])); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (!empty($fb['comments'])): ?>
                                    <div style="margin-top: 10px; font-size: 0.84rem; color: var(--ad-text); background: var(--ad-input); padding: 10px 14px; border-radius: 8px; border: 1px solid var(--ad-border);">
                                        <strong>Interviewer Comments:</strong><br>
                                        <?php echo nl2br(htmlspecialchars($fb['comments'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="ad-section">
            <div class="ad-section-head">
                <div class="ico"><i class="fas fa-id-card"></i></div>
                <div>
                    <h3>Contact Information</h3>
                    <p>Candidate's personal and contact details.</p>
                </div>
            </div>
            <div class="ad-grid">
                <div class="ad-item">
                    <small>Full Name</small>
                    <span><i class="fas fa-user mr-1" style="color:var(--ad-primary);"></i><?php echo htmlspecialchars(trim($app['username'])); ?></span>
                </div>
                <div class="ad-item">
                    <small>Email</small>
                    <span><a href="mailto:<?php echo htmlspecialchars($app['email']); ?>"><i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($app['email']); ?></a></span>
                </div>
                <div class="ad-item">
                    <small>Phone</small>
                    <span><a href="tel:<?php echo htmlspecialchars($app['phone']); ?>"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($app['phone']); ?></a></span>
                </div>
                <div class="ad-item">
                    <small>Education</small>
                    <span><i class="fas fa-graduation-cap mr-1" style="color:var(--ad-primary);"></i><?php echo htmlspecialchars($app['user_degree']); ?></span>
                </div>
            </div>
        </div>


        <div class="ad-section">
            <div class="ad-section-head">
                <div class="ico"><i class="fas fa-chart-pie"></i></div>
                <div>
                    <h3>Quiz Performance</h3>
                    <p>Assessment results for this application.</p>
                </div>
            </div>

            <?php if ($assessment_session): ?>
                <div class="ad-score" style="margin-bottom: 2rem;">
                    <div class="ad-ring" style="background: conic-gradient(<?php echo $score_color; ?> <?php echo $score_pct; ?>%, var(--ad-border) 0);">
                        <div class="inner">
                            <b style="color: <?php echo $score_color; ?>;"><?php echo $score_pct; ?>%</b>
                            <small>Overall Score</small>
                        </div>
                    </div>
                    <div class="ad-score-stats">
                        <div class="ad-score-stat">
                            <b><?php echo floatval($assessment_session['score_mcq'] ?? 0); ?>%</b>
                            <span><i class="fas fa-list-ol mr-1" style="color:var(--ad-primary);"></i>MCQ Score</span>
                        </div>
                        <div class="ad-score-stat">
                            <b><?php echo ($assessment_session['score_short'] !== null && $assessment_session['score_short'] !== '') ? floatval($assessment_session['score_short']) . '%' : 'N/A'; ?></b>
                            <span><i class="fas fa-pen-fancy mr-1" style="color:#d97706;"></i>Short Answer</span>
                        </div>
                        <?php 
                            $r_score = intval($assessment_session['risk_score'] ?? 0);
                            $r_level = strtolower($assessment_session['risk_level'] ?? 'low');
                            $r_color = ($r_score >= 60 || $r_level === 'critical' || $r_level === 'high') ? '#dc2626' : (($r_score >= 30 || $r_level === 'medium') ? '#d97706' : '#059669');
                        ?>
                        <div class="ad-score-stat">
                            <b style="color: <?php echo $r_color; ?>;"><?php echo $r_score; ?>/100 <span style="font-size: 0.75rem; text-transform: uppercase;">(<?php echo htmlspecialchars($r_level); ?>)</span></b>
                            <span><i class="fas fa-shield-alt mr-1" style="color:<?php echo $r_color; ?>;"></i>Proctoring Risk</span>
                        </div>
                    </div>
                </div>

                <div class="ad-responses" style="margin-top: 2rem;">
                    <h4 style="margin-bottom: 1rem; border-bottom: 1px solid var(--ad-border); padding-bottom: 0.5rem; font-size: 1.1rem; color: #afb2b7;">Detailed Responses & AI Evaluation</h4>
                    <?php if (empty($responses)): ?>
                        <p style="color: var(--ad-muted);">No responses recorded.</p>
                    <?php else: ?>
                        <?php foreach ($responses as $resp): 
                            $user_ans = trim($resp['user_answer'] ?? '');
                            $q_marks  = intval($resp['marks'] ?? 1);
                            $is_mcq   = ($resp['question_type'] === 'mcq');
                            
                            if ($is_mcq) {
                                $marks_awarded = (!empty($resp['is_correct']) && $resp['is_correct'] == 1) ? $q_marks : 0;
                            } else {
                                $ai_pct = (isset($resp['ai_score']) && $resp['ai_score'] !== null && $resp['ai_score'] !== '') ? floatval($resp['ai_score']) : 0;
                                $marks_awarded = round($q_marks * ($ai_pct / 100), 2);
                            }
                        ?>
                            <div style="background: #f8fafc; border: 1px solid var(--ad-border); border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">
                                    <strong style="font-size: 1.05rem; color: #0f172a;">Q<?php echo $resp['question_index'] + 1; ?>: <?php echo htmlspecialchars($resp['question']); ?></strong>
                                    <span style="background: #e0e7ff; color: #4338ca; font-size: 0.75rem; padding: 3px 8px; border-radius: 12px; white-space: nowrap; font-weight: bold; margin-left: 1rem;"><?php echo $q_marks; ?> Mark<?php echo $q_marks > 1 ? 's' : ''; ?></span>
                                </div>
                                <div style="margin-bottom: 0.75rem; font-size: 0.85rem; color: #64748b; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;">
                                    Type: <?php echo $is_mcq ? 'Multiple Choice' : 'Short Answer'; ?>
                                </div>
                                <div style="margin-bottom: 1rem;">
                                    <strong style="color: #334155; font-size: 0.9rem;">Candidate's Answer:</strong>
                                    <div style="background: #fff; border: 1px solid #cbd5e1; padding: 0.75rem; border-radius: 6px; margin-top: 0.25rem; color: <?php echo !empty($user_ans) ? '#0f172a' : '#94a3b8'; ?>; white-space: pre-wrap; <?php echo empty($user_ans) ? 'font-style: italic;' : ''; ?>">
                                        <?php echo htmlspecialchars(!empty($user_ans) ? $user_ans : 'No answer provided'); ?>
                                    </div>
                                </div>
                                <?php if ($is_mcq): ?>
                                    <div style="margin-bottom: 0.75rem; font-size: 0.9rem;">
                                        <strong style="color: #334155;">Correct Answer:</strong> <span style="color: #059669; font-weight: 600;"><?php echo htmlspecialchars($resp['correct_answer'] ?? ''); ?></span>
                                    </div>
                                    <div style="font-size: 0.95rem;">
                                        <strong style="color: #334155;">Marks Awarded:</strong> 
                                        <span style="color: <?php echo ($marks_awarded == $q_marks && $q_marks > 0) ? '#059669' : '#dc2626'; ?>; font-weight: bold;">
                                            <?php echo $marks_awarded; ?> / <?php echo $q_marks; ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-bottom: 1rem;">
                                        <strong style="color: #334155; font-size: 0.9rem;">Ideal Answer / Rubric:</strong>
                                        <div style="color: #64748b; font-size: 0.9rem; margin-top: 0.25rem; font-style: italic; background: #fff; border: 1px solid #e2e8f0; padding: 0.6rem 0.75rem; border-radius: 6px;">
                                            <?php echo htmlspecialchars($resp['ideal_answer'] ?? ''); ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($resp['ai_feedback'])): ?>
                                        <div style="margin-bottom: 1rem; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.75rem; border-radius: 6px;">
                                            <strong style="color: #166534; font-size: 0.9rem; display: block; margin-bottom: 0.25rem;"><i class="fas fa-robot mr-1"></i>AI Feedback:</strong>
                                            <p style="margin: 0; font-size: 0.9rem; color: #14532d; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($resp['ai_feedback'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <div style="font-size: 0.95rem;">
                                        <strong style="color: #334155;">Marks Awarded (AI):</strong> 
                                        <span style="color: <?php echo $marks_awarded > 0 ? '#059669' : '#dc2626'; ?>; font-weight: bold;">
                                            <?php echo $marks_awarded; ?> / <?php echo $q_marks; ?>
                                            <?php if (isset($resp['ai_score']) && $resp['ai_score'] !== null && $resp['ai_score'] !== ''): ?>
                                                <small style="color: #64748b; font-weight: normal; margin-left: 4px;">(<?php echo intval($resp['ai_score']); ?>%)</small>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($assessment_events)): ?>
                        <div style="margin-top: 1.5rem; padding: 1.25rem; background: #fff5f5; border: 1px solid #fed7d7; border-radius: 8px;">
                            <h5 style="color: #c53030; font-size: 1rem; font-weight: 700; margin-bottom: 0.75rem; display: flex; align-items: center;">
                                <i class="fas fa-shield-alt mr-2"></i> Anti-Cheating & Integrity Log (<?php echo count($assessment_events); ?> incident<?php echo count($assessment_events) > 1 ? 's' : ''; ?>)
                            </h5>
                            <div style="background: white; border: 1px solid #feb2b2; border-radius: 6px; overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                                    <thead>
                                        <tr style="background: #fffaf0; color: #742a2a; border-bottom: 1px solid #fed7d7;">
                                            <th style="padding: 8px 12px; text-align: left;">Violation Type</th>
                                            <th style="padding: 8px 12px; text-align: left;">Risk Added</th>
                                            <th style="padding: 8px 12px; text-align: left;">Logged At</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assessment_events as $ev): ?>
                                            <tr style="border-bottom: 1px solid #edf2f7;">
                                                <td style="padding: 8px 12px; font-weight: 600; color: #9b2c2c;">
                                                    <i class="fas fa-exclamation-circle mr-1" style="color: #e53e3e;"></i>
                                                    <?php echo htmlspecialchars($ev['event_type']); ?>
                                                </td>
                                                <td style="padding: 8px 12px; color: #c53030; font-weight: 600;">+<?php echo intval($ev['risk_weight']); ?> pts</td>
                                                <td style="padding: 8px 12px; color: #718096;"><?php echo htmlspecialchars($ev['created_at']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($app['quiz_status'] != 'not_taken' && !empty($app['total_questions'])): ?>
                <div class="ad-score">
                    <div class="ad-ring" style="background: conic-gradient(<?php echo $score_color; ?> <?php echo $score_pct; ?>%, var(--ad-border) 0);">
                        <div class="inner">
                            <b style="color: <?php echo $score_color; ?>;"><?php echo $score_pct; ?>%</b>
                            <small>Score</small>
                        </div>
                    </div>
                    <div class="ad-score-stats">
                        <div class="ad-score-stat">
                            <b><?php echo $app['correct_answers']; ?> / <?php echo $app['total_questions']; ?></b>
                            <span><i class="fas fa-check-circle mr-1" style="color:#059669;"></i>Correct</span>
                        </div>
                        <div class="ad-score-stat">
                            <b><?php echo $app['time_taken'] ? gmdate("i:s", $app['time_taken']) : 'N/A'; ?></b>
                            <span><i class="fas fa-stopwatch mr-1" style="color:var(--ad-primary);"></i>Time Taken</span>
                        </div>
                        <div class="ad-score-stat">
                            <b><?php echo date('M d, Y', strtotime($app['attempt_date'])); ?></b>
                            <span><i class="fas fa-calendar-check mr-1" style="color:#d97706;"></i>Attempted</span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="ad-not-taken">
                    <i class="fas fa-hourglass-half"></i>
                    <h4>Quiz Not Taken Yet</h4>
                    <p class="mb-0">The candidate has not attempted the quiz for this application.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Skills & Expertise -->
        <div class="ad-section">
            <div class="ad-section-head">
                <div class="ico"><i class="fas fa-code"></i></div>
                <div>
                    <h3>Skills & Expertise</h3>
                    <p>Technical and professional skills listed by the candidate.</p>
                </div>
            </div>
            <div class="ad-skills">
                <?php
                $skills = array_filter(array_map('trim', explode(',', $app['user_skills'])));
                if (count($skills) > 0):
                    foreach ($skills as $skill): ?>
                        <span class="ad-skill"><i class="fas fa-check"></i><?php echo htmlspecialchars($skill); ?></span>
                    <?php endforeach;
                else: ?>
                    <span class="ad-muted" style="color:var(--ad-muted);font-size:.9rem;">No skills listed.</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Applied Job -->
        <div class="ad-section">
            <div class="ad-section-head">
                <div class="ico"><i class="fas fa-briefcase"></i></div>
                <div>
                    <h3>Applied Job</h3>
                    <p>The position this candidate applied for.</p>
                </div>
            </div>
            <div class="ad-job">
                <span class="cat"><i class="fas fa-tag"></i><?php echo htmlspecialchars($app['job_category']); ?></span>
                <h4><?php echo htmlspecialchars($app['job_title']); ?></h4>
                <p><?php echo nl2br(htmlspecialchars($app['job_description'])); ?></p>
            </div>
        </div>

        <!-- Cover Letter -->
        <?php if (!empty($app['cover_letter'])): ?>
            <div class="ad-section">
                <div class="ad-section-head">
                    <div class="ico"><i class="fas fa-file-lines"></i></div>
                    <div>
                        <h3>Cover Letter</h3>
                        <p>Candidate's message accompanying the application.</p>
                    </div>
                </div>
                <div class="ad-cover"><?php echo htmlspecialchars($app['cover_letter']); ?></div>
            </div>
        <?php endif; ?>

        <!-- AI CV Screening & Candidate Screening Section -->
        <div class="ai-panel" id="aiScreeningPanel">
            <div class="ai-panel-header">
                <div class="ai-panel-title-wrap">
                    <div class="ai-panel-icon"><i class="fas fa-microchip"></i></div>
                    <div>
                        <h3>AI CV Match &amp; Candidate Screening
                            <button type="button" class="btn btn-sm" onclick="openScoreExplainerModal()" style="background: rgba(99, 102, 241, 0.1); color: #6366f1; border-radius: 20px; font-size: 0.75rem; font-weight: 700; padding: 2px 10px; border: 1px solid rgba(99, 102, 241, 0.25);">
                                <i class="fas fa-circle-info mr-1"></i> How is this calculated?
                            </button>
                        </h3>
                        <p>Objective screening decision-support aid comparing candidate's submitted CV against <strong><?php echo htmlspecialchars($app['job_title']); ?></strong> requirements.</p>
                    </div>
                </div>
                <div>
                    <?php if ($ai_status === 'COMPLETED'): ?>
                        <span class="ai-status-badge ai-badge-completed" id="aiHeaderBadge">
                            <i class="fas fa-check-circle"></i> AI CV Match: <?php echo intval($ai_analysis['overall_match_score']); ?>%
                        </span>
                    <?php elseif ($ai_status === 'OUTDATED'): ?>
                        <span class="ai-status-badge ai-badge-outdated" id="aiHeaderBadge">
                            <i class="fas fa-clock-rotate-left"></i> Analysis Outdated
                        </span>
                    <?php elseif ($ai_status === 'FAILED'): ?>
                        <span class="ai-status-badge ai-badge-failed" id="aiHeaderBadge">
                            <i class="fas fa-triangle-exclamation"></i> Analysis Failed
                        </span>
                    <?php else: ?>
                        <span class="ai-status-badge ai-badge-not-analyzed" id="aiHeaderBadge">
                            <i class="fas fa-hourglass-start"></i> Not Analyzed
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($ai_is_outdated): ?>
                <div id="aiOutdatedNotice" style="background: rgba(245, 158, 11, 0.1); border: 1px solid #f59e0b; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <strong style="color: #b45309;"><i class="fas fa-triangle-exclamation mr-1"></i> Analysis Outdated:</strong>
                        <span style="color: var(--ad-text); font-size: 0.88rem;">
                            <?php if ($ai_outdated_reason === 'cv_updated'): ?>
                                Candidate has updated their CV after the last analysis. Re-analysis recommended.
                            <?php else: ?>
                                Company job requirements were edited after the last analysis. Re-analysis recommended.
                            <?php endif; ?>
                        </span>
                    </div>
                    <button type="button" class="btn btn-sm btn-warning" onclick="triggerCVAnalysis(<?php echo $app_id; ?>, true)" style="font-weight: 700; border-radius: 8px;">
                        <i class="fas fa-arrows-rotate mr-1"></i> Re-analyze CV
                    </button>
                </div>
            <?php endif; ?>

            <!-- Loading Spinner Container (hidden by default) -->
            <div id="aiAnalyzingContainer" style="display: none; padding: 40px 20px; text-align: center;">
                <div style="font-size: 2.2rem; color: #6366f1; margin-bottom: 14px;">
                    <i class="fas fa-circle-notch fa-spin"></i>
                </div>
                <h4 style="font-size: 1.15rem; font-weight: 800; color: var(--ad-text); margin: 0 0 6px;">AI is Analyzing CV...</h4>
                <p style="font-size: 0.88rem; color: var(--ad-muted); margin: 0 0 16px;">Comparing submitted CV against required &amp; preferred job qualifications.</p>
                <div style="max-width: 380px; margin: 0 auto; background: var(--ad-input); border-radius: 10px; padding: 12px; text-align: left; font-size: 0.82rem; color: var(--ad-text);">
                    <div style="margin-bottom: 6px;"><i class="fas fa-check text-success mr-2"></i> Reading submitted CV</div>
                    <div style="margin-bottom: 6px;"><i class="fas fa-check text-success mr-2"></i> Extracting skills &amp; experience</div>
                    <div style="margin-bottom: 6px;"><i class="fas fa-spinner fa-spin text-primary mr-2"></i> Evaluating against job requirements</div>
                    <div style="color: var(--ad-muted);"><i class="far fa-circle mr-2"></i> Finalizing screening report</div>
                </div>
            </div>

            <!-- Content Area -->
            <div id="aiAnalysisContent">
                <?php if ($ai_status === 'COMPLETED' || ($ai_status === 'OUTDATED' && !empty($ai_analysis))): ?>
                    <!-- Overall Score & Subscores Grid -->
                    <div class="ai-score-overview">
                        <div class="ai-main-ring-box">
                            <div class="ai-match-ring">
                                <span class="num"><?php echo intval($ai_analysis['overall_match_score']); ?></span>
                                <span class="pct">% MATCH</span>
                            </div>
                            <label>AI CV Match</label>
                            <small>Job Requirement Fit</small>
                        </div>
                        <div class="ai-subscores-grid">
                            <div class="ai-subscore-item">
                                <div class="ai-subscore-head">
                                    <span><i class="fas fa-code text-primary mr-1"></i> Skills Match</span>
                                    <b><?php echo intval($ai_analysis['skills_match_score']); ?>%</b>
                                </div>
                                <div class="ai-bar-wrap">
                                    <div class="ai-bar-fill" style="width: <?php echo intval($ai_analysis['skills_match_score']); ?>%; background: #3b82f6;"></div>
                                </div>
                            </div>
                            <div class="ai-subscore-item">
                                <div class="ai-subscore-head">
                                    <span><i class="fas fa-briefcase text-success mr-1"></i> Experience</span>
                                    <b><?php echo intval($ai_analysis['experience_match_score']); ?>%</b>
                                </div>
                                <div class="ai-bar-wrap">
                                    <div class="ai-bar-fill" style="width: <?php echo intval($ai_analysis['experience_match_score']); ?>%; background: #10b981;"></div>
                                </div>
                            </div>
                            <div class="ai-subscore-item">
                                <div class="ai-subscore-head">
                                    <span><i class="fas fa-graduation-cap text-warning mr-1"></i> Education</span>
                                    <b><?php echo intval($ai_analysis['education_match_score']); ?>%</b>
                                </div>
                                <div class="ai-bar-wrap">
                                    <div class="ai-bar-fill" style="width: <?php echo intval($ai_analysis['education_match_score']); ?>%; background: #f59e0b;"></div>
                                </div>
                            </div>
                            <div class="ai-subscore-item">
                                <div class="ai-subscore-head">
                                    <span><i class="fas fa-diagram-project text-info mr-1"></i> Project Relevance</span>
                                    <b><?php echo intval($ai_analysis['project_relevance_score']); ?>%</b>
                                </div>
                                <div class="ai-bar-wrap">
                                    <div class="ai-bar-fill" style="width: <?php echo intval($ai_analysis['project_relevance_score']); ?>%; background: #06b6d4;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Why this score? -->
                    <?php if (!empty($ai_analysis['strengths']) || !empty($ai_analysis['gaps'])): ?>
                        <div class="ai-why-card">
                            <h4><i class="fas fa-wand-magic-sparkles text-primary"></i> Why this score?</h4>
                            <ul class="ai-why-list">
                                <?php foreach ($ai_analysis['strengths'] as $st): ?>
                                    <li class="strength"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($st); ?></li>
                                <?php endforeach; ?>
                                <?php foreach ($ai_analysis['gaps'] as $gp): ?>
                                    <li class="gap"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($gp); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- Requirements Breakdown: Required vs Preferred -->
                    <div class="ai-req-section-grid">
                        <!-- Required Requirements Column -->
                        <div class="ai-req-column">
                            <div class="ai-req-col-header">
                                <h4><i class="fas fa-star text-warning"></i> Required Requirements</h4>
                                <?php 
                                $r_m_count = count($ai_analysis['required_matches'] ?? []);
                                $r_tot = $r_m_count + count($ai_analysis['required_missing'] ?? []) + count($ai_analysis['required_unclear'] ?? []);
                                ?>
                                <span class="badge badge-light" style="font-size: 0.8rem; font-weight: 700; color: var(--ad-text);"><?php echo $r_m_count; ?> / <?php echo max(1, $r_tot); ?> Matched</span>
                            </div>
                            <div class="ai-req-list">
                                <?php foreach (($ai_analysis['required_matches'] ?? []) as $rm): ?>
                                    <div class="ai-req-item">
                                        <div class="ai-req-item-top">
                                            <span><i class="fas fa-check-circle text-success mr-1"></i> <?php echo htmlspecialchars($rm['name']); ?></span>
                                            <span class="ai-tag ai-tag-matched">MATCHED</span>
                                        </div>
                                        <div class="ai-req-evidence"><?php echo htmlspecialchars($rm['evidence']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach (($ai_analysis['required_unclear'] ?? []) as $ru): ?>
                                    <div class="ai-req-item">
                                        <div class="ai-req-item-top">
                                            <span><i class="fas fa-circle-question text-warning mr-1"></i> <?php echo htmlspecialchars($ru['name']); ?></span>
                                            <span class="ai-tag ai-tag-unclear">UNCLEAR</span>
                                        </div>
                                        <div class="ai-req-evidence"><?php echo htmlspecialchars($ru['evidence']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach (($ai_analysis['required_missing'] ?? []) as $mis): ?>
                                    <div class="ai-req-item">
                                        <div class="ai-req-item-top">
                                            <span><i class="fas fa-circle-xmark text-danger mr-1"></i> <?php echo htmlspecialchars($mis['name']); ?></span>
                                            <span class="ai-tag ai-tag-missing">NOT FOUND</span>
                                        </div>
                                        <div class="ai-req-evidence"><?php echo htmlspecialchars($mis['evidence']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Preferred Requirements Column -->
                        <div class="ai-req-column">
                            <div class="ai-req-col-header">
                                <h4><i class="fas fa-thumbs-up text-info"></i> Preferred Requirements</h4>
                                <?php 
                                $p_m_count = count($ai_analysis['preferred_matches'] ?? []);
                                $p_tot = $p_m_count + count($ai_analysis['preferred_missing'] ?? []) + count($ai_analysis['preferred_unclear'] ?? []);
                                ?>
                                <span class="badge badge-light" style="font-size: 0.8rem; font-weight: 700; color: var(--ad-text);"><?php echo $p_m_count; ?> / <?php echo max(1, $p_tot); ?> Matched</span>
                            </div>
                            <div class="ai-req-list">
                                <?php foreach (($ai_analysis['preferred_matches'] ?? []) as $pm): ?>
                                    <div class="ai-req-item">
                                        <div class="ai-req-item-top">
                                            <span><i class="fas fa-check-circle text-success mr-1"></i> <?php echo htmlspecialchars($pm['name']); ?></span>
                                            <span class="ai-tag ai-tag-matched">MATCHED</span>
                                        </div>
                                        <div class="ai-req-evidence"><?php echo htmlspecialchars($pm['evidence']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach (($ai_analysis['preferred_unclear'] ?? []) as $pu): ?>
                                    <div class="ai-req-item">
                                        <div class="ai-req-item-top">
                                            <span><i class="fas fa-circle-question text-warning mr-1"></i> <?php echo htmlspecialchars($pu['name']); ?></span>
                                            <span class="ai-tag ai-tag-unclear">UNCLEAR</span>
                                        </div>
                                        <div class="ai-req-evidence"><?php echo htmlspecialchars($pu['evidence']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach (($ai_analysis['preferred_missing'] ?? []) as $pmis): ?>
                                    <div class="ai-req-item">
                                        <div class="ai-req-item-top">
                                            <span><i class="fas fa-circle-xmark text-danger mr-1"></i> <?php echo htmlspecialchars($pmis['name']); ?></span>
                                            <span class="ai-tag ai-tag-missing">NOT FOUND</span>
                                        </div>
                                        <div class="ai-req-evidence"><?php echo htmlspecialchars($pmis['evidence']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($ai_analysis['preferred_matches']) && empty($ai_analysis['preferred_unclear']) && empty($ai_analysis['preferred_missing'])): ?>
                                    <div style="color: var(--ad-muted); font-size: 0.88rem; padding: 20px; text-align: center; font-style: italic;">
                                        No explicit secondary or preferred qualifications were specified in the job posting.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- AI Summary -->
                    <div class="ai-summary-block">
                        <strong style="color: #6366f1; display: block; margin-bottom: 4px;"><i class="fas fa-comment-dots mr-1"></i> AI Screening Summary:</strong>
                        <?php echo htmlspecialchars($ai_analysis['summary'] ?? ''); ?>
                    </div>

                    <!-- Detailed Evidence Details (Collapsible) -->
                    <?php if (!empty($ai_analysis['evidence'])): ?>
                        <details style="background: var(--ad-input); border: 1px solid var(--ad-border); border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; cursor: pointer;">
                            <summary style="font-weight: 700; color: var(--ad-text); font-size: 0.88rem;">
                                <i class="fas fa-list-check text-primary mr-1"></i> View Detailed CV Evidence Citations (<?php echo count($ai_analysis['evidence']); ?> items)
                            </summary>
                            <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--ad-border);">
                                <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px;">
                                    <?php foreach ($ai_analysis['evidence'] as $ev): ?>
                                        <li style="font-size: 0.84rem; color: var(--ad-text); line-height: 1.45;"><?php echo htmlspecialchars($ev); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </details>
                    <?php endif; ?>

                    <!-- Footer Bar -->
                    <div class="ai-footer-bar">
                        <div>
                            <span><i class="far fa-clock mr-1"></i> Analyzed: <?php echo !empty($ai_analysis['analyzed_at']) ? date('d F Y, h:i A', strtotime($ai_analysis['analyzed_at'])) : date('d F Y, h:i A'); ?></span>
                            <?php if (!empty($ai_analysis['model'])): ?>
                                <span class="ml-3"><i class="fas fa-microchip mr-1"></i> Engine: <?php echo htmlspecialchars($ai_analysis['model']); ?></span>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="triggerCVAnalysis(<?php echo $app_id; ?>, true)" style="border-radius: 8px; font-weight: 700;">
                            <i class="fas fa-rotate-right mr-1"></i> Re-analyze CV
                        </button>
                    </div>

                <?php elseif ($ai_status === 'FAILED'): ?>
                    <div style="background: rgba(239, 68, 68, 0.08); border: 1.5px solid #ef4444; border-radius: 14px; padding: 24px; text-align: center;">
                        <div style="font-size: 2rem; color: #ef4444; margin-bottom: 10px;"><i class="fas fa-triangle-exclamation"></i></div>
                        <h4 style="margin: 0 0 6px; font-weight: 800; color: #991b1b;">AI CV Analysis Unavailable</h4>
                        <p style="margin: 0 0 16px; font-size: 0.88rem; color: var(--ad-text);">
                            The automated CV analysis could not be completed at this time. You can still review the candidate manually and access the CV document directly.
                        </p>
                        <button type="button" class="btn btn-warning px-4 py-2" onclick="triggerCVAnalysis(<?php echo $app_id; ?>, false)" style="font-weight: 700; border-radius: 10px;">
                            <i class="fas fa-rotate-right mr-1"></i> Retry Analysis
                        </button>
                    </div>

                <?php else: ?>
                    <!-- NOT_ANALYZED state -->
                    <div style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(168, 85, 247, 0.03)); border: 1.5px dashed rgba(99, 102, 241, 0.4); border-radius: 16px; padding: 34px 20px; text-align: center;">
                        <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(99, 102, 241, 0.12); color: #6366f1; font-size: 1.5rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <h4 style="margin: 0 0 8px; font-weight: 800; color: var(--ad-text);">Candidate CV Not Yet Analyzed</h4>
                        <p style="max-width: 560px; margin: 0 auto 20px; font-size: 0.88rem; color: var(--ad-muted); line-height: 1.6;">
                            Run an AI-powered screening match between the candidate's submitted CV and the specific job requirements for <strong><?php echo htmlspecialchars($app['job_title']); ?></strong>. Generates match scores, extracts required and preferred skill evidence, and identifies potential gaps.
                        </p>
                        <button type="button" class="btn btn-primary px-4 py-2" onclick="triggerCVAnalysis(<?php echo $app_id; ?>, false)" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); border: none; font-weight: 800; border-radius: 12px; box-shadow: 0 8px 20px rgba(99, 102, 241, 0.35);">
                            <i class="fas fa-wand-magic-sparkles mr-2"></i> Analyze CV with AI
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Company Candidate Screening Decisions -->
        <div class="ai-company-actions-bar">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h4 style="margin: 0 0 4px; font-size: 1.05rem; font-weight: 800; color: var(--ad-text);">
                        <i class="fas fa-user-check text-primary mr-2"></i> Company Candidate Decision
                    </h4>
                    <p style="margin: 0; font-size: 0.82rem; color: var(--ad-muted);">
                        AI provides decision-support only. The company makes all screening and hiring decisions.
                    </p>
                </div>
                <div>
                    <span id="currentStatusBadge" class="badge" style="background: <?php echo $app_color; ?>22; color: <?php echo $app_color; ?>; border: 1px solid <?php echo $app_color; ?>55; font-size: 0.82rem; padding: 6px 14px; border-radius: 20px;">
                        Current: <?php echo htmlspecialchars($app_label); ?>
                    </span>
                </div>
            </div>

            <div class="ai-action-buttons">
                <button type="button" class="ai-act-btn ai-act-review" onclick="updateCompanyStatus(<?php echo $app_id; ?>, 'reviewed')">
                    <i class="fas fa-eye"></i> Mark as Reviewed
                </button>
                <button type="button" class="ai-act-btn ai-act-shortlist" onclick="updateCompanyStatus(<?php echo $app_id; ?>, 'shortlisted')">
                    <i class="fas fa-circle-check"></i> Shortlist Candidate
                </button>
                <button type="button" class="ai-act-btn ai-act-reject" onclick="confirmRejectCandidate(<?php echo $app_id; ?>)">
                    <i class="fas fa-circle-xmark"></i> Reject Candidate
                </button>
                <span id="interviewActionSlot" style="<?php echo ($app_status === 'shortlisted') ? '' : 'display: none;'; ?>">
                    <a href="schedule_interview.php?application_id=<?php echo $app_id; ?>" class="ai-act-btn ai-act-interview-btn">
                        <i class="fas fa-calendar-check"></i> Schedule Interview
                    </a>
                </span>
            </div>
        </div>

        <!-- Application Status Management -->
        <div class="ad-section">
            <div class="ad-section-head">
                <div class="ico"><i class="fas fa-sliders"></i></div>
                <div>
                    <h3>Application Status</h3>
                    <p>Update how this application is progressing.</p>
                </div>
            </div>

            <form method="POST" action="" id="statusForm">
                <div class="ad-status-pills mb-4">
                    <?php foreach ($status_colors as $key => $s): ?>
                        <label class="ad-pill <?php echo $app_status == $key ? 'sel' : ''; ?>" data-key="<?php echo $key; ?>">
                            <input type="radio" name="application_status" value="<?php echo $key; ?>" <?php echo $app_status == $key ? 'checked' : ''; ?>>
                            <b><i class="fas fa-circle mr-1" style="color: <?php echo $s[0]; ?>; font-size:.55rem;"></i><?php echo $s[1]; ?></b>
                            <span><?php echo $key == 'pending' ? 'Waiting for review' : ($key == 'reviewed' ? 'Application reviewed' : ($key == 'shortlisted' ? 'Moved to shortlist' : 'Not proceeding')); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="update_status" class="ad-save-btn">
                    <i class="fas fa-save"></i>Update Status
                </button>
            </form>
        </div>

        <?php if (!empty($app_interviews) || in_array($app['pipeline_stage'], ['interview', 'offered', 'hired', 'rejected'])): ?>
        <!-- Final Hiring Decision -->
        <div class="ad-section" id="hiring-decision-section" style="border: 2px solid #6366f1; box-shadow: 0 10px 30px rgba(99, 102, 241, 0.12);">
            <div class="ad-section-head" style="border-bottom-color: rgba(99, 102, 241, 0.2);">
                <div class="ico" style="background: linear-gradient(135deg, #6366f1, #8b5cf6);"><i class="fas fa-gavel"></i></div>
                <div>
                    <h3 style="color: #4f46e5;">Final Hiring Decision & Joining Workflow</h3>
                    <p>Review interviewer feedback, confirm employment details, set joining date, and request onboarding documents.</p>
                </div>
            </div>

            <?php if (!empty($hiring_error)): ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid #fecaca; font-weight: 600;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($hiring_error); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($hiring_success)): ?>
                <div style="background: #dcfce7; color: #166534; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid #bbf7d0; font-weight: 600;">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($hiring_success); ?>
                </div>
            <?php endif; ?>

            <?php 
            $curr_dec = $hiring_decision['decision'] ?? '';
            $is_hired = ($curr_dec === 'selected');
            $is_held = ($curr_dec === 'under_final_review');
            $is_rejected = ($curr_dec === 'rejected');
            $cea = get_candidate_selection_response($con, $app_id);
            $cand_ready = ($cea && $cea['ready_to_join'] === 'yes');
            $cand_declined = ($cea && $cea['ready_to_join'] === 'no');
            ?>

            <?php if ($is_hired && empty($hiring_error) && !isset($_GET['edit_decision'])): ?>
                <!-- Hired / Selected View -->
                <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(99, 102, 241, 0.04)); border: 1.5px solid #10b981; border-radius: 14px; padding: 22px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
                        <div>
                            <span style="background: #10b981; color: #fff; font-size: 0.75rem; font-weight: 800; padding: 4px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;">
                                <i class="fas fa-check-circle"></i> Candidate Selected
                            </span>
                            <h3 style="margin: 10px 0 4px; font-size: 1.25rem; color: var(--ad-text);">
                                <?php if ($cand_ready): ?>
                                    Candidate Ready to Join!
                                <?php elseif ($cand_declined): ?>
                                    Candidate Declined Selection
                                <?php else: ?>
                                    Candidate Selected — Awaiting Candidate Response
                                <?php endif; ?>
                            </h3>
                            <p style="margin: 0; font-size: 0.86rem; color: var(--ad-muted);">
                                Position: <strong><?php echo htmlspecialchars($app['job_title']); ?></strong>
                            </p>
                        </div>
                        <div style="text-align: right; background: var(--ad-card); padding: 12px 20px; border-radius: 10px; border: 1px solid var(--ad-border);">
                            <?php if ($cand_ready): ?>
                                <small style="display: block; color: var(--ad-muted); font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Approximate Joining Date</small>
                                <span style="font-size: 1.25rem; font-weight: 800; color: #10b981;">
                                    <i class="fas fa-calendar-check"></i> <?php echo date('M d, Y', strtotime($cea['approximate_joining_date'])); ?>
                                </span>
                            <?php elseif (!empty($hiring_decision['final_joining_date'])): ?>
                                <small style="display: block; color: var(--ad-muted); font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Approved Joining Date</small>
                                <span style="font-size: 1.25rem; font-weight: 800; color: #10b981;">
                                    <i class="fas fa-calendar-check"></i> <?php echo date('M d, Y', strtotime($hiring_decision['final_joining_date'])); ?>
                                </span>
                            <?php else: ?>
                                <small style="display: block; color: var(--ad-muted); font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Candidate Response</small>
                                <span style="font-size: 1.1rem; font-weight: 800; color: #f59e0b;">
                                    <i class="fas fa-clock"></i> Pending Submission
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($cand_declined): ?>
                        <div style="margin-top: 16px; padding: 14px; background: #fee2e2; border-radius: 10px; border: 1px solid #fecaca; color: #991b1b; font-size: 0.88rem;">
                            <strong>Candidate declined this offer.</strong> Reason: <?php echo htmlspecialchars($cea['decline_reason'] ?: 'None given'); ?>
                        </div>
                    <?php elseif ($cand_ready): ?>
                        <div style="display: flex; gap: 20px; margin-top: 16px; font-size: 0.85rem; color: var(--ad-text); flex-wrap: wrap;">
                            <div>
                                <strong>Employment Status (Confirmed by Candidate):</strong> 
                                <?php echo ($cea['currently_working'] === 'yes') ? '<span style="color:#059669; font-weight:700;">Currently Working at ' . htmlspecialchars($cea['current_company_name'] ?: 'another firm') . '</span>' : '<span>Not Currently Working (Immediate Availability)</span>'; ?>
                            </div>
                            <?php if ($cea['currently_working'] === 'yes' && !empty($cea['expected_leaving_date'])): ?>
                                <div>
                                    <strong>Expected Leaving Date:</strong> <?php echo date('M d, Y', strtotime($cea['expected_leaving_date'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 16px; padding: 12px 16px; background: rgba(99, 102, 241, 0.08); border-radius: 10px; border: 1px solid rgba(99, 102, 241, 0.2); font-size: 0.85rem; color: var(--ad-text);">
                            <i class="fas fa-info-circle" style="color: #6366f1;"></i> The candidate has received a selection notification and has been asked to confirm their readiness, provide their approximate joining date, and upload onboarding documents.
                        </div>
                    <?php endif; ?>

                    <!-- Workflow Cards -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-top: 22px;">
                        
                        <!-- Candidate Response Card -->
                        <div style="background: var(--ad-card); border: 1px solid var(--ad-border); border-radius: 12px; padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <b style="font-size: 0.95rem; color: var(--ad-text);"><i class="fas fa-user-check" style="color: #6366f1; margin-right: 8px;"></i>Candidate Response</b>
                                    <span style="font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 12px; background: <?php echo $cand_ready ? '#dcfce7' : ($cand_declined ? '#fee2e2' : '#fef3c7'); ?>; color: <?php echo $cand_ready ? '#166534' : ($cand_declined ? '#991b1b' : '#b45309'); ?>;">
                                        <?php echo $cand_ready ? 'READY TO JOIN' : ($cand_declined ? 'DECLINED' : 'PENDING'); ?>
                                    </span>
                                </div>
                                <p style="font-size: 0.82rem; color: var(--ad-muted); margin-bottom: 12px;">
                                    <?php echo $cand_ready ? 'Candidate confirmed readiness and availability date (' . date('M d, Y', strtotime($cea['approximate_joining_date'])) . ').' : ($cand_declined ? 'Candidate declined the offer.' : 'Waiting for candidate to submit readiness response.'); ?>
                                </p>
                            </div>
                            <a href="candidate_response_review.php?application_id=<?php echo $app_id; ?>" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 16px; border-radius: 8px; font-size: 0.86rem; font-weight: 700; background: #6366f1; color: #fff; text-decoration: none; transition: transform 0.2s;">
                                <i class="fas fa-clipboard-check"></i> View Candidate Response
                            </a>
                        </div>

                        <!-- Required Documents Card -->
                        <div style="background: var(--ad-card); border: 1px solid var(--ad-border); border-radius: 12px; padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <b style="font-size: 0.95rem; color: var(--ad-text);"><i class="fas fa-folder-open" style="color: #3b82f6; margin-right: 8px;"></i>Onboarding Documents</b>
                                    <span style="font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 12px; background: rgba(59, 130, 246, 0.12); color: #3b82f6;">
                                        <?php echo count($app_doc_requirements); ?> Required
                                    </span>
                                </div>
                                <p style="font-size: 0.82rem; color: var(--ad-muted); margin-bottom: 12px;">
                                    Uploaded: <strong><?php echo $app_uploaded_count; ?></strong> &middot; Verified: <strong style="color: #059669;"><?php echo $app_verified_count; ?></strong>
                                </p>
                            </div>
                            <a href="manage_hiring_documents.php?application_id=<?php echo $app_id; ?>" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 16px; border-radius: 8px; font-size: 0.86rem; font-weight: 700; background: #3b82f6; color: #fff; text-decoration: none; transition: transform 0.2s;">
                                <i class="fas fa-shield-halved"></i> Review & Verify Documents
                            </a>
                        </div>

                        <!-- Appointment Letter Card -->
                        <div style="background: var(--ad-card); border: 1px solid var(--ad-border); border-radius: 12px; padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <b style="font-size: 0.95rem; color: var(--ad-text);"><i class="fas fa-file-contract" style="color: #10b981; margin-right: 8px;"></i>Appointment Letter</b>
                                    <span style="font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 12px; background: <?php echo ($hiring_letter && $hiring_letter['status'] === 'SENT') ? '#dcfce7' : ($cand_ready ? '#fef3c7' : '#f1f5f9'); ?>; color: <?php echo ($hiring_letter && $hiring_letter['status'] === 'SENT') ? '#166534' : ($cand_ready ? '#b45309' : '#64748b'); ?>;">
                                        <?php echo ($hiring_letter && $hiring_letter['status'] === 'SENT') ? 'SENT TO CANDIDATE' : ($cand_ready ? 'READY TO ISSUE' : 'AWAITING RESPONSE'); ?>
                                    </span>
                                </div>
                                <p style="font-size: 0.82rem; color: var(--ad-muted); margin-bottom: 12px;">
                                    <?php if ($hiring_letter && $hiring_letter['status'] === 'SENT'): ?>
                                        Letter sent on <?php echo date('M d, Y', strtotime($hiring_letter['sent_at'] ?? $hiring_letter['updated_at'])); ?>.
                                    <?php elseif ($cand_ready): ?>
                                        Candidate response received. Generate live with AI, customize, and issue.
                                    <?php else: ?>
                                        Will become available once candidate confirms readiness to join.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php if ($cand_ready || ($hiring_letter && $hiring_letter['status'] === 'SENT')): ?>
                                <a href="hiring_letter.php?application_id=<?php echo $app_id; ?>" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 16px; border-radius: 8px; font-size: 0.86rem; font-weight: 700; background: #10b981; color: #fff; text-decoration: none; transition: transform 0.2s;">
                                    <i class="fas fa-wand-magic-sparkles"></i> <?php echo ($hiring_letter && $hiring_letter['status'] === 'SENT') ? 'View Appointment Letter' : 'Draft / Send Letter'; ?>
                                </a>
                            <?php else: ?>
                                <button type="button" disabled style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 16px; border-radius: 8px; font-size: 0.86rem; font-weight: 700; background: var(--ad-border); color: var(--ad-muted); border: none; cursor: not-allowed;">
                                    <i class="fas fa-lock"></i> Awaiting Candidate Response
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top: 16px; text-align: right;">
                        <a href="view_applicant_detail.php?id=<?php echo $app_id; ?>&edit_decision=1#hiring-decision-section" style="font-size: 0.8rem; color: #6366f1; text-decoration: underline; font-weight: 600;">
                            Modify Decision
                        </a>
                    </div>
                </div>

            <?php elseif (($is_held || $is_rejected) && empty($hiring_error) && !isset($_GET['edit_decision'])): ?>
                <!-- Hold or Reject Summary View -->
                <div style="background: var(--ad-card); border: 1.5px solid <?php echo $is_held ? '#8b5cf6' : '#ef4444'; ?>; border-radius: 14px; padding: 22px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                        <div>
                            <span style="background: <?php echo $is_held ? '#8b5cf6' : '#ef4444'; ?>; color: #fff; font-size: 0.75rem; font-weight: 800; padding: 4px 12px; border-radius: 20px; text-transform: uppercase;">
                                <i class="fas <?php echo $is_held ? 'fa-pause-circle' : 'fa-times-circle'; ?>"></i> <?php echo $is_held ? 'Application on Hold (Under Final Review)' : 'Candidate Rejected'; ?>
                            </span>
                            <h3 style="margin: 10px 0 4px; font-size: 1.2rem; color: var(--ad-text);">
                                <?php echo $is_held ? 'Application is Under Final Review' : 'Candidate Not Selected'; ?>
                            </h3>
                            <p style="margin: 0; font-size: 0.86rem; color: var(--ad-muted);">
                                <?php echo $is_held ? 'The candidate has been notified that their application is under final evaluation. No onboarding documents or appointment letters are issued.' : 'The hiring process for this application has ended. Candidate was notified.'; ?>
                            </p>
                        </div>
                        <div>
                            <a href="view_applicant_detail.php?id=<?php echo $app_id; ?>&edit_decision=1#hiring-decision-section" class="ad-save-btn" style="text-decoration: none; padding: 10px 18px; font-size: 0.86rem;">
                                <i class="fas fa-pen"></i> Change Decision
                            </a>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <!-- Decision Entry / Editing Form -->
                <form method="POST" action="">
                    <div class="ad-status-pills mb-3">
                        <?php 
                            $decisions = [
                                'selected'           => ['#10b981', 'Accept Candidate', 'Candidate is selected. Sends selection response invite.'],
                                'under_final_review' => ['#8b5cf6', 'Hold Candidate', 'Keep candidate under final review. No onboarding.'],
                                'rejected'           => ['#dc2626', 'Reject Candidate', 'Decline application. Hiring process ends.']
                            ];
                            foreach ($decisions as $key => $d): 
                        ?>
                            <label class="ad-pill <?php echo $curr_dec == $key ? 'sel' : ''; ?>" data-key="<?php echo $key; ?>" onclick="this.parentNode.querySelectorAll('.ad-pill').forEach(p=>p.classList.remove('sel')); this.classList.add('sel'); document.getElementById('hd_<?php echo $key; ?>').checked=true; toggleHiringDetails('<?php echo $key; ?>');">
                                <input type="radio" id="hd_<?php echo $key; ?>" name="final_decision" value="<?php echo $key; ?>" <?php echo $curr_dec == $key ? 'checked' : ''; ?> required>
                                <b><i class="fas fa-circle mr-1" style="color: <?php echo $d[0]; ?>; font-size:.55rem;"></i><?php echo $d[1]; ?></b>
                                <span><?php echo $d[2]; ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Dynamic HIRE Details Panel -->
                    <div id="hire_joining_details_panel" style="display: <?php echo ($curr_dec === 'selected') ? 'block' : 'none'; ?>; background: var(--ad-input); border: 1.5px solid rgba(16, 185, 129, 0.4); border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 12px 0; font-size: 0.96rem; color: #10b981; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-clipboard-list"></i> Onboarding Document Requirements & Joining Information
                        </h4>

                        <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.85rem; color: var(--ad-text);">
                            <i class="fas fa-info-circle" style="color: #10b981;"></i> 
                            <strong>How this works:</strong> Selecting <strong>Accept Candidate</strong> sends a congratulatory notification. The candidate will confirm their readiness to join, specify their approximate availability date, and provide their employment details along with the required onboarding documents configured below.
                        </div>

                        <!-- Employment Status Info from Interview -->
                        <div style="background: var(--ad-card); border: 1px solid var(--ad-border); border-radius: 10px; padding: 14px; margin-bottom: 16px;">
                            <span style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: #3b82f6; letter-spacing: 0.4px;">
                                <i class="fas fa-clipboard-user"></i> Interviewer-Reported Employment Status
                            </span>
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 6px;">
                                <div>
                                    <div style="font-size: 0.92rem; font-weight: 700; color: var(--ad-text);">
                                        Currently working elsewhere: 
                                        <?php if ($overall_emp_status === 'CURRENTLY_WORKING'): ?>
                                            <span style="color: #059669;">YES</span>
                                        <?php else: ?>
                                            <span style="color: #64748b;">NO</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($overall_emp_status === 'CURRENTLY_WORKING' && !empty($overall_leaving_date)): ?>
                                        <div style="font-size: 0.85rem; color: var(--ad-muted); margin-top: 3px;">
                                            Expected Leaving Date: <strong><?php echo date('M d, Y', strtotime($overall_leaving_date)); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($overall_emp_status === 'CURRENTLY_WORKING' && !empty($suggested_joining_date)): ?>
                                    <div style="text-align: right; background: rgba(16, 185, 129, 0.08); padding: 8px 14px; border-radius: 8px; border: 1px dashed rgba(16, 185, 129, 0.4);">
                                        <small style="display: block; font-size: 0.7rem; color: var(--ad-muted); text-transform: uppercase; font-weight: 700;">Interviewer Suggested Date</small>
                                        <span style="font-size: 1.05rem; font-weight: 800; color: #059669;">
                                            <i class="fas fa-lightbulb"></i> <?php echo date('M d, Y', strtotime($suggested_joining_date)); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <input type="hidden" name="employment_status_noted" value="<?php echo htmlspecialchars($overall_emp_status); ?>">
                            <input type="hidden" name="expected_leaving_date_noted" value="<?php echo htmlspecialchars($overall_leaving_date ?? ''); ?>">
                        </div>

                        <!-- Optional Joining Date Input -->
                        <div class="mb-3">
                            <label style="font-size: 0.88rem; font-weight: 700; color: var(--ad-text); margin-bottom: 6px; display: block;">
                                Target / Confirmed Joining Date <span style="font-size: 0.75rem; font-weight: normal; color: var(--ad-muted);">(Optional — Candidate will propose their approximate joining date)</span>
                            </label>
                            <input type="date" name="final_joining_date" id="final_joining_date_input" 
                                   value="<?php echo htmlspecialchars($hiring_decision['final_joining_date'] ?? ''); ?>"
                                   min="<?php echo $overall_leaving_date ? date('Y-m-d', strtotime($overall_leaving_date . ' +1 day')) : date('Y-m-d'); ?>"
                                   style="max-width: 280px; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--ad-border); background: var(--ad-card); color: var(--ad-text); font-size: 0.95rem; font-weight: 600;">
                            <small style="display: block; color: var(--ad-muted); margin-top: 4px; font-size: 0.8rem;">
                                You can set this now, or confirm it after reviewing the candidate's availability response when preparing the appointment letter.
                            </small>
                        </div>

                        <!-- Required Documents Checklist -->
                        <div class="mb-3">
                            <label style="font-size: 0.88rem; font-weight: 700; color: var(--ad-text); margin-bottom: 6px; display: block;">
                                Required Documents for Candidate to Upload
                            </label>
                            <p style="font-size: 0.8rem; color: var(--ad-muted); margin-top: -2px; margin-bottom: 10px;">
                                The candidate will see these requested documents in their selection response page:
                            </p>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px;">
                                <?php 
                                $default_docs = [
                                    'NID' => ['National ID Card (NID)', true],
                                    'Educational Certificate' => ['Educational Certificate', true],
                                    'Academic Transcript' => ['Academic Transcript', true],
                                    'Employment Certificate' => ['Current / Previous Employment Certificate', ($overall_emp_status === 'CURRENTLY_WORKING')],
                                    'Experience Certificate' => ['Experience Certificate', false],
                                    'Passport Photo' => ['Passport Size Photograph', false],
                                    'Other Document' => ['Other Verified Credentials', false],
                                ];
                                foreach ($default_docs as $d_key => $d_data): 
                                ?>
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: var(--ad-text); cursor: pointer; background: var(--ad-card); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--ad-border);">
                                        <input type="checkbox" name="required_documents[]" value="<?php echo $d_key; ?>" <?php echo $d_data[1] ? 'checked' : ''; ?> style="width: 16px; height: 16px; accent-color: #6366f1;">
                                        <span><?php echo htmlspecialchars($d_data[0]); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label style="font-size: 0.85rem; font-weight: 700; color: var(--ad-text); margin-bottom: 6px; display: block;">Internal Decision Notes (Optional)</label>
                        <textarea name="final_notes" rows="3" placeholder="Add internal company notes regarding this hiring decision..." style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid var(--ad-border); background: var(--ad-input); color: var(--ad-text); font-size: 0.9rem; resize: vertical;"><?php echo htmlspecialchars($hiring_decision['final_notes'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" name="submit_final_decision" class="ad-save-btn" style="background: linear-gradient(135deg, #6366f1, #8b5cf6);">
                        <i class="fas fa-check-double"></i> Save Decision & Notify Candidate
                    </button>
                </form>

                <script>
                function toggleHiringDetails(decision) {
                    const panel = document.getElementById('hire_joining_details_panel');
                    if (decision === 'selected') {
                        if (panel) panel.style.display = 'block';
                    } else {
                        if (panel) panel.style.display = 'none';
                    }
                }
                </script>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="ad-section">
            <div class="ad-section-head">
                <div class="ico"><i class="fas fa-bolt"></i></div>
                <div>
                    <h3>Quick Actions</h3>
                    <p>Reach out or take the next step with this candidate.</p>
                </div>
            </div>
            <div class="ad-actions">
                <?php if ($app['quiz_status'] == 'passed' || $app['application_status'] == 'shortlisted'): ?>
                    <a href="schedule_interview.php?application_id=<?php echo $app['id']; ?>" class="ad-act ad-act-interview">
                        <i class="fas fa-calendar-check"></i>Schedule Interview
                    </a>
                <?php endif; ?>
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
                    <a href="<?php echo $cv_link; ?>" target="_blank" class="ad-act ad-act-cv">
                        <i class="fas fa-file-pdf"></i>Download CV
                    </a>
                <?php endif; ?>
                <a href="message_center.php?with=user_<?php echo $app['user_id']; ?>" class="ad-act ad-act-msg">
                    <i class="fas fa-comments"></i>Send Message
                </a>
                <a href="mailto:<?php echo htmlspecialchars($app['email']); ?>?subject=Regarding Your Application for <?php echo urlencode($app['job_title']); ?>" class="ad-act ad-act-mail">
                    <i class="fas fa-envelope"></i>Send Email
                </a>
                <a href="tel:<?php echo htmlspecialchars($app['phone']); ?>" class="ad-act ad-act-call">
                    <i class="fas fa-phone"></i>Call Candidate
                </a>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="ad-toast" id="adToast"><i class="fas fa-circle-check"></i><b id="toastMsg">Application status updated! The candidate has been notified.</b></div>

    <!-- Score Explainer Modal -->
    <div class="ai-modal-backdrop" id="scoreExplainerModal" onclick="if(event.target === this) closeScoreExplainerModal();">
        <div class="ai-modal-box">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(99, 102, 241, 0.12); color: #6366f1; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                        <i class="fas fa-circle-info"></i>
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 1.1rem; font-weight: 800; color: var(--ad-text);">How is this score calculated?</h4>
                        <span style="font-size: 0.78rem; color: var(--ad-muted);">NovaHire AI Screening Methodology</span>
                    </div>
                </div>
                <button type="button" onclick="closeScoreExplainerModal()" style="background: none; border: none; font-size: 1.3rem; color: var(--ad-muted); cursor: pointer; padding: 4px 8px;">&times;</button>
            </div>

            <div style="font-size: 0.88rem; color: var(--ad-text); line-height: 1.6; margin-bottom: 16px;">
                <p style="margin-bottom: 12px;">
                    The score compares the submitted CV with the requirements of this specific job, including <strong>required skills</strong>, <strong>preferred skills</strong>, <strong>experience</strong>, <strong>education</strong>, and <strong>relevant project evidence</strong>.
                </p>
                <div style="background: rgba(99, 102, 241, 0.06); border-left: 3px solid #6366f1; padding: 10px 14px; border-radius: 0 8px 8px 0; margin-bottom: 14px; font-weight: 600;">
                    The score is an AI-generated screening aid and does not represent a final hiring decision.
                </div>
                
                <h5 style="font-size: 0.92rem; font-weight: 700; margin: 12px 0 6px; color: var(--ad-text);">Evaluation Criteria:</h5>
                <ul style="padding-left: 20px; margin: 0 0 14px; color: var(--ad-text); font-size: 0.85rem;">
                    <li><strong>Required Requirements (Heavy Weight):</strong> Core technical and domain proficiencies strictly necessary for the position.</li>
                    <li><strong>Preferred Requirements (Secondary Weight):</strong> Beneficial skills that enhance candidate performance without disqualifying missing entries.</li>
                    <li><strong>Experience &amp; Education:</strong> Relevant industry seniority, degree alignment, and practical tenure.</li>
                    <li><strong>Evidence-Based Attribution:</strong> Every requirement is classified strictly as <span class="ai-tag ai-tag-matched">MATCHED</span>, <span class="ai-tag ai-tag-missing">NOT FOUND</span>, or <span class="ai-tag ai-tag-unclear">UNCLEAR</span> with quoted CV citations to eliminate hallucination.</li>
                </ul>
            </div>

            <div style="text-align: right; border-top: 1px solid var(--ad-border); padding-top: 14px;">
                <button type="button" class="btn btn-primary" onclick="closeScoreExplainerModal()" style="border-radius: 8px; font-weight: 700; padding: 7px 20px;">
                    Got it, Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Status pill toggle
        document.querySelectorAll('.ad-pill').forEach(pill => {
            pill.addEventListener('click', () => {
                document.querySelectorAll('.ad-pill').forEach(p => p.classList.remove('sel'));
                pill.classList.add('sel');
            });
        });

        // Score explainer modal controls
        function openScoreExplainerModal() {
            const m = document.getElementById('scoreExplainerModal');
            if (m) m.style.display = 'flex';
        }
        function closeScoreExplainerModal() {
            const m = document.getElementById('scoreExplainerModal');
            if (m) m.style.display = 'none';
        }

        // Trigger AI CV analysis / re-analysis
        function triggerCVAnalysis(appId, isReanalyze) {
            const loadBox = document.getElementById('aiAnalyzingContainer');
            const contBox = document.getElementById('aiAnalysisContent');
            const outNotice = document.getElementById('aiOutdatedNotice');

            if (loadBox) loadBox.style.display = 'block';
            if (contBox) contBox.style.display = 'none';
            if (outNotice) outNotice.style.display = 'none';

            const action = isReanalyze ? 'reanalyze' : 'analyze';
            fetch('api_ai_cv_screening.php?action=' + action + '&application_id=' + appId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ application_id: appId })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('AI CV Analysis completed successfully!');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    if (loadBox) loadBox.style.display = 'none';
                    if (contBox) contBox.style.display = 'block';
                    alert(data.message || 'AI CV analysis could not be completed at this time.');
                }
            })
            .catch(err => {
                if (loadBox) loadBox.style.display = 'none';
                if (contBox) contBox.style.display = 'block';
                alert('An unexpected error occurred while communicating with the AI screening service.');
            });
        }

        // Update company candidate decision status
        function updateCompanyStatus(appId, newStatus) {
            fetch('api_ai_cv_screening.php?action=update_status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    application_id: appId,
                    status: newStatus
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const badge = document.getElementById('currentStatusBadge');
                    const interviewSlot = document.getElementById('interviewActionSlot');
                    
                    const labels = {
                        'pending': 'NEW / PENDING',
                        'reviewed': 'REVIEWED',
                        'shortlisted': 'SHORTLISTED',
                        'rejected': 'REJECTED'
                    };
                    const colors = {
                        'pending': '#f59e0b',
                        'reviewed': '#3b82f6',
                        'shortlisted': '#10b981',
                        'rejected': '#ef4444'
                    };

                    const c = colors[newStatus] || '#64748b';
                    const l = labels[newStatus] || newStatus.toUpperCase();

                    if (badge) {
                        badge.innerText = 'Current: ' + l;
                        badge.style.background = c + '22';
                        badge.style.color = c;
                        badge.style.borderColor = c + '55';
                    }

                    if (interviewSlot) {
                        if (newStatus === 'shortlisted') {
                            interviewSlot.style.display = 'inline-block';
                        } else {
                            interviewSlot.style.display = 'none';
                        }
                    }

                    // Also select radio in the manual status form
                    const r = document.querySelector('input[name="application_status"][value="' + newStatus + '"]');
                    if (r) {
                        r.checked = true;
                        document.querySelectorAll('.ad-pill').forEach(p => p.classList.remove('sel'));
                        const p = r.closest('.ad-pill');
                        if (p) p.classList.add('sel');
                    }

                    showToast('Candidate status marked as ' + l + '!');
                } else {
                    alert(data.message || 'Unable to update status.');
                }
            })
            .catch(err => {
                alert('Connection error occurred while updating status.');
            });
        }

        // Confirm rejection
        function confirmRejectCandidate(appId) {
            if (confirm('Are you sure you want to mark this candidate as Rejected? You can still change the status later if needed.')) {
                updateCompanyStatus(appId, 'rejected');
            }
        }

        function showToast(msg) {
            const t = document.getElementById('adToast');
            const msgEl = document.getElementById('toastMsg');
            if (msgEl) msgEl.innerText = msg;
            if (t) {
                t.classList.add('show');
                setTimeout(() => t.classList.remove('show'), 4200);
            }
        }

        <?php if (isset($status_updated)): ?>
            (function() {
                showToast('Application status updated! The candidate has been notified.');
            })();
        <?php endif; ?>
    </script>
</body>
</html>
